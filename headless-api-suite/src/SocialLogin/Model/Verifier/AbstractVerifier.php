<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Verifier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Scr1be\SocialLogin\Model\Jwt\Base64Url;
use Scr1be\SocialLogin\Model\Jwt\JwksProvider;
use Scr1be\SocialLogin\Model\SocialLoginException;

/**
 * Everything that is the same for every OIDC provider.
 *
 * The subclass supplies four facts — code, issuer(s), JWKS URI, and the configured audience — and
 * maps the provider's claim names onto `IdentityClaims`. Everything below is the OIDC ID Token
 * validation from RFC 7519 §7.2 and OpenID Connect Core §3.1.3.7, in the order that makes the
 * cheapest check fail first:
 *
 *  1. three segments, and a header that parses
 *  2. `alg` is RS256 — checked *before* anything else touches the signature, because accepting the
 *     token's own word for its algorithm is the `alg: none` family of attacks
 *  3. the payload parses
 *  4. the signature verifies against the key named by `kid`
 *  5. `iss` is one we expect
 *  6. `aud` contains our client id
 *  7. `exp` is in the future and `iat`/`nbf` are not
 *
 * The signature check sits before the claim checks on purpose: until it passes, every claim is
 * attacker-controlled text and comparing it to anything is theatre.
 */
abstract class AbstractVerifier implements VerifierInterface
{
    /**
     * The only signature algorithm accepted, for both providers.
     *
     * Not a configuration option. An installation that can be talked into accepting a second
     * algorithm has a second attack surface, and neither Google nor Apple signs ID tokens with
     * anything else.
     */
    private const REQUIRED_ALGORITHM = 'RS256';

    private const SEGMENT_COUNT = 3;

    /**
     * Tolerance for clock skew between this server and the provider, in seconds.
     */
    private const CLOCK_SKEW = 60;

    /**
     * @param JwksProvider $jwksProvider
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $json
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly JwksProvider $jwksProvider,
        protected readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The provider's JWKS endpoint.
     *
     * @return string
     */
    abstract protected function getJwksUri(): string;

    /**
     * Every `iss` value this provider is allowed to use.
     *
     * @return string[]
     */
    abstract protected function getAllowedIssuers(): array;

    /**
     * The configuration path holding the client id this store expects in `aud`.
     *
     * @return string
     */
    abstract protected function getClientIdConfigPath(): string;

    /**
     * Turn a verified payload into the four things this module is allowed to know.
     *
     * @param array<string, mixed> $payload
     * @return IdentityClaims
     */
    abstract protected function toClaims(array $payload): IdentityClaims;

    /**
     * @inheritDoc
     */
    public function isAvailable(int $storeId): bool
    {
        return $this->getClientId($storeId) !== '';
    }

    /**
     * @inheritDoc
     */
    public function verify(string $idToken, int $storeId): IdentityClaims
    {
        $clientId = $this->getClientId($storeId);
        if ($clientId === '') {
            throw new SocialLoginException(
                SocialLoginException::PROVIDER_UNAVAILABLE,
                new Phrase('This sign-in method is not available.')
            );
        }

        $segments = explode('.', $idToken);
        if (count($segments) !== self::SEGMENT_COUNT) {
            $this->reject('the token is not a three-segment JWS');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = $this->decodeSegment($encodedHeader);
        if ($header === null) {
            $this->reject('the header is not decodable JSON');
        }

        if (($header['alg'] ?? null) !== self::REQUIRED_ALGORITHM) {
            $this->reject(sprintf('unexpected alg "%s"', (string)($header['alg'] ?? '')));
        }

        $keyId = (string)($header['kid'] ?? '');
        if ($keyId === '') {
            $this->reject('the header carries no kid');
        }

        $payload = $this->decodeSegment($encodedPayload);
        if ($payload === null) {
            $this->reject('the payload is not decodable JSON');
        }

        $signature = Base64Url::decode($encodedSignature);
        if ($signature === null || $signature === '') {
            $this->reject('the signature is not decodable');
        }

        $pem = $this->jwksProvider->getPem($this->getProviderCode(), $this->getJwksUri(), $keyId);

        // openssl_verify returns 1 on success, 0 on a bad signature and -1 on an error. Only 1 is a
        // pass; treating the return as a boolean turns -1 into "verified".
        if (openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            $this->reject('the signature does not verify');
        }

        $this->assertClaims($payload, $clientId);

        return $this->toClaims($payload);
    }

    /**
     * The claim checks, run only after the signature has passed.
     *
     * @param array<string, mixed> $payload
     * @param string $clientId
     * @return void
     * @throws SocialLoginException
     */
    private function assertClaims(array $payload, string $clientId): void
    {
        if (!in_array((string)($payload['iss'] ?? ''), $this->getAllowedIssuers(), true)) {
            $this->reject(sprintf('unexpected iss "%s"', (string)($payload['iss'] ?? '')));
        }

        // `aud` is a string or an array of strings (RFC 7519 §4.1.3). Normalising both to an array
        // and comparing strictly avoids the loose comparison that makes `aud: 0` match anything.
        $audience = $payload['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if (!in_array($clientId, array_map(static fn ($a) => is_string($a) ? $a : '', $audiences), true)) {
            $this->reject('the token was issued for a different audience');
        }

        if ((string)($payload['sub'] ?? '') === '') {
            $this->reject('the token carries no sub');
        }

        $now = $this->dateTime->gmtTimestamp();

        $expiry = (int)($payload['exp'] ?? 0);
        if ($expiry === 0 || $now > $expiry + self::CLOCK_SKEW) {
            $this->reject('the token has expired');
        }

        foreach (['iat', 'nbf'] as $claim) {
            if (isset($payload[$claim]) && $now + self::CLOCK_SKEW < (int)$payload[$claim]) {
                $this->reject(sprintf('the token is not valid yet (%s is in the future)', $claim));
            }
        }
    }

    /**
     * @param string $segment
     * @return array<string, mixed>|null
     */
    private function decodeSegment(string $segment): ?array
    {
        $raw = Base64Url::decode($segment);
        if ($raw === null) {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Log the real reason, tell the client nothing.
     *
     * @param string $reason
     * @return never
     * @throws SocialLoginException
     */
    private function reject(string $reason): never
    {
        $this->logger->warning(
            sprintf('Scr1be_SocialLogin: rejected a %s ID token — %s', $this->getProviderCode(), $reason)
        );

        throw new SocialLoginException(
            SocialLoginException::INVALID_TOKEN,
            new Phrase('The sign-in token could not be verified.')
        );
    }

    /**
     * @param int $storeId
     * @return string
     */
    protected function getClientId(int $storeId): string
    {
        return trim((string)$this->scopeConfig->getValue(
            $this->getClientIdConfigPath(),
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }
}
