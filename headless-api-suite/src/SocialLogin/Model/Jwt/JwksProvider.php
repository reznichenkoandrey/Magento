<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Jwt;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Scr1be\SocialLogin\Model\Cache\JwksCache;
use Scr1be\SocialLogin\Model\SocialLoginException;

/**
 * Fetches and caches a provider's public signing keys.
 *
 * Providers rotate signing keys on their own schedule and without notice, so this has to be a cache
 * with a miss path rather than a fetch with a cache in front of it. The rule is: look the `kid` up in
 * whatever is cached; if it is not there, the provider has rotated, so re-fetch once and look again.
 * Re-fetching on *every* unknown `kid` would also be a free denial-of-service — an attacker sends
 * garbage kids and we hammer Google — so the refresh is rate-limited by a short marker entry.
 */
class JwksProvider
{
    /**
     * One hour. Both Google and Apple publish `Cache-Control: max-age` on their JWKS endpoints, but
     * reading it would mean trusting a remote header to decide how long we keep a security-relevant
     * artefact. An hour is short enough that a scheduled rotation is picked up on its own and long
     * enough that sign-ins do not each cost an outbound round trip; an *early* rotation is handled by
     * the miss path, not by the TTL.
     */
    public const CACHE_LIFETIME = 3600;

    /**
     * How long a failed or just-completed refresh suppresses the next one, per provider.
     */
    private const REFRESH_COOLDOWN = 60;

    private const HTTP_TIMEOUT = 5;
    private const HTTP_OK = 200;

    /**
     * @param CurlFactory $curlFactory
     * @param JwksCache $cache
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly JwksCache $cache,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The PEM for one key id, fetching fresh keys once if the id is unknown.
     *
     * @param string $provider
     * @param string $jwksUri
     * @param string $keyId
     * @return string
     * @throws SocialLoginException
     */
    public function getPem(string $provider, string $jwksUri, string $keyId): string
    {
        $keys = $this->readCache($provider);

        if ($keys === null || !isset($keys[$keyId])) {
            $refreshed = $this->refresh($provider, $jwksUri, $keys === null);
            if ($refreshed !== null) {
                $keys = $refreshed;
            }
        }

        if (!isset($keys[$keyId])) {
            throw new SocialLoginException(
                SocialLoginException::INVALID_TOKEN,
                new Phrase('The sign-in token could not be verified.')
            );
        }

        return $keys[$keyId];
    }

    /**
     * @param string $provider
     * @return array<string, string>|null
     */
    private function readCache(string $provider): ?array
    {
        $cached = $this->cache->load($this->cacheKey($provider));
        if (!is_string($cached) || $cached === '') {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($cached);
        } catch (\InvalidArgumentException) {
            // A corrupt entry must look like a miss, not like an outage.
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Fetch the document and rebuild the key map.
     *
     * @param string $provider
     * @param string $jwksUri
     * @param bool $cacheWasEmpty
     * @return array<string, string>|null
     * @throws SocialLoginException
     */
    private function refresh(string $provider, string $jwksUri, bool $cacheWasEmpty): ?array
    {
        $cooldownKey = $this->cacheKey($provider) . '_cooldown';
        if ($this->cache->load($cooldownKey)) {
            // Somebody refreshed within the last minute. An unknown kid this soon after is far more
            // likely to be a forged token than a second rotation, so do not go back out.
            return null;
        }
        // No explicit tags: `TagScope::save()` appends the cache type's own tag, which is what makes
        // `cache:clean scr1be_social_jwks` reach both this marker and the key map.
        $this->cache->save('1', $cooldownKey, [], self::REFRESH_COOLDOWN);

        $keys = $this->fetch($provider, $jwksUri, $cacheWasEmpty);
        if ($keys === null) {
            return null;
        }

        $this->cache->save(
            $this->json->serialize($keys),
            $this->cacheKey($provider),
            [],
            self::CACHE_LIFETIME
        );

        return $keys;
    }

    /**
     * @param string $provider
     * @param string $jwksUri
     * @param bool $failLoudly Throw rather than return null — used when there is no cache to fall back on.
     * @return array<string, string>|null
     * @throws SocialLoginException
     */
    private function fetch(string $provider, string $jwksUri, bool $failLoudly): ?array
    {
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::HTTP_TIMEOUT);
            $curl->addHeader('Accept', 'application/json');
            $curl->get($jwksUri);

            if ($curl->getStatus() !== self::HTTP_OK) {
                throw new \RuntimeException('HTTP ' . $curl->getStatus());
            }

            $document = $this->json->unserialize($curl->getBody());
            if (!is_array($document) || !is_array($document['keys'] ?? null)) {
                throw new \RuntimeException('the response is not a JWK Set');
            }

            $keys = $this->toPemMap($document['keys']);
            if ($keys === []) {
                throw new \RuntimeException('the JWK Set contains no usable RSA keys');
            }

            return $keys;
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Scr1be_SocialLogin: could not load %s signing keys: %s', $provider, $e->getMessage()),
                ['exception' => $e, 'jwks_uri' => $jwksUri]
            );

            if ($failLoudly) {
                throw new SocialLoginException(
                    SocialLoginException::KEYS_UNAVAILABLE,
                    new Phrase('Sign-in is temporarily unavailable. Please try again.')
                );
            }

            return null;
        }
    }

    /**
     * Keep only the RSA signing keys we can actually use, converted to PEM once at cache time.
     *
     * Converting here rather than on read means the DER work happens once per rotation instead of
     * once per sign-in, and it means a malformed key is discovered while there is still a log line
     * to put it in.
     *
     * @param array<int, mixed> $jwks
     * @return array<string, string>
     */
    private function toPemMap(array $jwks): array
    {
        $keys = [];

        foreach ($jwks as $key) {
            if (!is_array($key)) {
                continue;
            }
            // `alg` is optional in a JWK Set, so its absence is not disqualifying — but if it is
            // present and is not RS256, this key is not for verifying these tokens.
            if (($key['kty'] ?? null) !== 'RSA' || (isset($key['alg']) && $key['alg'] !== 'RS256')) {
                continue;
            }
            $keyId = (string)($key['kid'] ?? '');
            if ($keyId === '') {
                continue;
            }

            $pem = RsaPublicKey::toPem((string)($key['n'] ?? ''), (string)($key['e'] ?? ''));
            if ($pem !== null) {
                $keys[$keyId] = $pem;
            }
        }

        return $keys;
    }

    /**
     * @param string $provider
     * @return string
     */
    private function cacheKey(string $provider): string
    {
        return 'scr1be_social_jwks_' . $provider;
    }
}
