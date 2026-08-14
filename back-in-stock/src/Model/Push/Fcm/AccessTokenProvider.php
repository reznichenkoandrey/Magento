<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\Push\Fcm;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * OAuth 2.0 for a service account: sign an assertion with the account's private key, exchange it for
 * an access token, and keep the token until shortly before it expires.
 *
 * FCM's HTTP v1 API takes a bearer token rather than the old legacy server key, which means the send
 * path needs a token exchange in front of it. Doing that per notification would double the outbound
 * calls and burn a signature every time; caching it is not an optimisation, it is what the flow is
 * designed for — Google issues these with an hour of life.
 *
 * The whole exchange is `openssl_sign()` and one form POST, which is why this module needs no
 * `google/auth` dependency. That library is worth its weight in an application that has to support
 * every Google credential type; here there is exactly one, it is a service-account key, and the
 * RFC 7523 assertion flow it uses is forty lines.
 */
class AccessTokenProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    private const ASSERTION_LIFETIME = 3600;

    /**
     * Tokens are dropped from the cache a minute before Google would stop accepting them, so a send
     * that starts just under the wire does not finish just over it.
     */
    private const EXPIRY_MARGIN = 60;

    private const CACHE_PREFIX = 'scr1be_back_in_stock_fcm_token_';
    private const CACHE_TAG = 'SCR1BE_BACK_IN_STOCK_FCM';

    private const TIMEOUT = 10;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly CacheInterface $cache,
        private readonly Json $serializer,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @throws LocalizedException When Google refuses the assertion, or the response is not a token.
     */
    public function getToken(ServiceAccount $account): string
    {
        $cacheKey = $this->cacheKey($account);
        $cached = $this->cache->load($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $lifetime] = $this->exchange($account);

        if ($lifetime > self::EXPIRY_MARGIN) {
            $this->cache->save($token, $cacheKey, [self::CACHE_TAG], $lifetime - self::EXPIRY_MARGIN);
        }

        return $token;
    }

    /**
     * @return array{0: string, 1: int} The token and the seconds it is good for.
     * @throws LocalizedException
     */
    private function exchange(ServiceAccount $account): array
    {
        $client = $this->curlFactory->create();
        $client->setTimeout(self::TIMEOUT);
        $client->addHeader('Content-Type', 'application/x-www-form-urlencoded');

        try {
            $client->post($account->tokenUri, http_build_query([
                'grant_type' => self::GRANT_TYPE,
                'assertion' => $this->buildAssertion($account),
            ]));
        } catch (\Exception $exception) {
            throw new LocalizedException(
                __('Could not reach the Google token endpoint: %1', $exception->getMessage()),
                $exception
            );
        }

        if ($client->getStatus() !== 200) {
            // The body is Google's own error JSON and names the problem — an expired key, a clock
            // that is off, the wrong audience — so it is worth carrying rather than flattening to
            // "authentication failed".
            throw new LocalizedException(
                __('Google refused the service account assertion (HTTP %1): %2', $client->getStatus(), $client->getBody())
            );
        }

        $decoded = $this->decode($client->getBody());

        if (!isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new LocalizedException(__('The Google token response carried no access token.'));
        }

        return [$decoded['access_token'], (int)($decoded['expires_in'] ?? 0)];
    }

    /**
     * The signed JWT, per RFC 7523: a header naming RS256, a claim set naming the account, the
     * scope, the audience and a one-hour window, and an RSA signature over both.
     */
    private function buildAssertion(ServiceAccount $account): string
    {
        $issuedAt = $this->dateTime->gmtTimestamp();

        $segments = [
            $this->base64Url($this->serializer->serialize(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url($this->serializer->serialize([
                'iss' => $account->clientEmail,
                'scope' => self::SCOPE,
                'aud' => $account->tokenUri,
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::ASSERTION_LIFETIME,
            ])),
        ];

        $signingInput = implode('.', $segments);
        $signature = '';

        if (!openssl_sign($signingInput, $signature, $account->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new LocalizedException(
                __('The Firebase service account private key could not be used to sign a request.')
            );
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        try {
            $decoded = $this->serializer->unserialize($body);
        } catch (\InvalidArgumentException $exception) {
            throw new LocalizedException(__('The Google token response was not JSON.'), $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The cache key carries a fingerprint of the credentials, so rotating the service account
     * invalidates the token that was minted with the old one instead of serving it until it expires.
     */
    private function cacheKey(ServiceAccount $account): string
    {
        return self::CACHE_PREFIX . hash('sha256', $account->clientEmail . '|' . $account->privateKey);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
