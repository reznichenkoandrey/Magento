<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model\Fcm;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Mints and caches the OAuth2 access token FCM HTTP v1 requires.
 *
 * Google's own PHP client would do this. It is not used, and the reason is proportion: the auth
 * library pulls in guzzle, psr/http-message, firebase/php-jwt and a monolog dependency, all so this
 * module can perform one signed assertion exchange. The exchange is RFC 7523 §2.1 and it is forty
 * lines: build a JWT whose `aud` is the token endpoint and whose `scope` is the one scope FCM needs,
 * sign it RS256 with the service account's private key, POST it as a `jwt-bearer` grant, keep the
 * access token until shortly before it expires.
 *
 * The cache is the part that makes this viable. A token lasts an hour; without caching, every push
 * would cost an extra HTTPS round trip to `oauth2.googleapis.com` before the one to FCM, doubling
 * the latency of a notification that is already happening inside an order save.
 */
class AccessTokenProvider
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * Google issues one-hour tokens. The assertion asks for the same.
     */
    private const ASSERTION_LIFETIME = 3600;

    /**
     * Drop the cached token a minute before it actually dies, so a request that starts just inside
     * the window does not arrive just outside it.
     */
    private const EXPIRY_MARGIN = 60;

    private const CACHE_KEY_PREFIX = 'scr1be_headless_push_fcm_token_';
    private const HTTP_TIMEOUT = 10;
    private const HTTP_OK = 200;

    /**
     * @param CurlFactory $curlFactory
     * @param CacheInterface $cache
     * @param Json $json
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * A usable access token for this service account.
     *
     * @param ServiceAccount $account
     * @return string
     * @throws \RuntimeException
     */
    public function getToken(ServiceAccount $account): string
    {
        $cacheKey = self::CACHE_KEY_PREFIX . sha1($account->clientEmail);

        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $expiresIn] = $this->exchange($account);

        $lifetime = max(1, $expiresIn - self::EXPIRY_MARGIN);
        $this->cache->save($token, $cacheKey, [], $lifetime);

        return $token;
    }

    /**
     * Trade a signed assertion for an access token.
     *
     * @param ServiceAccount $account
     * @return array{0: string, 1: int}
     * @throws \RuntimeException
     */
    private function exchange(ServiceAccount $account): array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::HTTP_TIMEOUT);
        $curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $curl->post(
            self::TOKEN_ENDPOINT,
            http_build_query([
                'grant_type' => self::GRANT_TYPE,
                'assertion' => $this->assertion($account),
            ])
        );

        if ($curl->getStatus() !== self::HTTP_OK) {
            throw new \RuntimeException('the token endpoint answered HTTP ' . $curl->getStatus());
        }

        $body = $this->json->unserialize($curl->getBody());
        if (!is_array($body) || !isset($body['access_token']) || !is_string($body['access_token'])) {
            throw new \RuntimeException('the token endpoint returned no access_token');
        }

        return [$body['access_token'], (int)($body['expires_in'] ?? self::ASSERTION_LIFETIME)];
    }

    /**
     * The RS256 JWT that stands in for a password.
     *
     * `aud` is the *token endpoint*, not the FCM API. That is the one field people get wrong here:
     * an assertion whose audience is `https://fcm.googleapis.com/` is refused with
     * `invalid_grant`, which reads like a key problem and is not.
     *
     * @param ServiceAccount $account
     * @return string
     * @throws \RuntimeException
     */
    private function assertion(ServiceAccount $account): string
    {
        $issuedAt = $this->dateTime->gmtTimestamp();

        $segments = [
            $this->base64UrlEncode((string)$this->json->serialize(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode((string)$this->json->serialize([
                'iss' => $account->clientEmail,
                'sub' => $account->clientEmail,
                'aud' => self::TOKEN_ENDPOINT,
                'scope' => self::SCOPE,
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::ASSERTION_LIFETIME,
            ])),
        ];

        $signingInput = implode('.', $segments);
        $signature = '';

        if (!openssl_sign($signingInput, $signature, $account->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('the service account private key could not sign the assertion');
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * @param string $data
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
