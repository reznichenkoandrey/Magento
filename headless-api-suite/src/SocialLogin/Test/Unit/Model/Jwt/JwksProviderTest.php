<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Test\Unit\Model\Jwt;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\SocialLogin\Model\Cache\JwksCache;
use Scr1be\SocialLogin\Model\Jwt\Base64Url;
use Scr1be\SocialLogin\Model\Jwt\JwksProvider;
use Scr1be\SocialLogin\Model\SocialLoginException;

/**
 * The rotation behaviour is the whole point of this class, and it is the part that only shows up in
 * production at 3am when a provider rotates a key.
 */
class JwksProviderTest extends TestCase
{
    private const PROVIDER = 'testable';
    private const URI = 'https://issuer.example.com/keys';

    private CurlFactory&MockObject $curlFactory;
    private Curl&MockObject $curl;
    private JwksCache&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private JwksProvider $provider;

    /**
     * @var array<string, string> Cache entries, keyed by identifier.
     */
    private array $stored = [];

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curlFactory->method('create')->willReturn($this->curl);

        $this->cache = $this->createMock(JwksCache::class);
        $this->cache->method('load')->willReturnCallback(fn ($id) => $this->stored[$id] ?? false);
        $this->cache->method('save')->willReturnCallback(
            function ($data, $id) {
                $this->stored[$id] = $data;

                return true;
            }
        );

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->provider = new JwksProvider($this->curlFactory, $this->cache, new Json(), $this->logger);
    }

    public function testFetchesAndCachesOnTheFirstCall(): void
    {
        $this->respondWith(200, $this->jwks(['kid-1']));

        $pem = $this->provider->getPem(self::PROVIDER, self::URI, 'kid-1');

        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        $this->assertArrayHasKey('scr1be_social_jwks_' . self::PROVIDER, $this->stored);
    }

    /**
     * A one-hour TTL is not a security boundary — an early rotation must be picked up immediately,
     * which means an unknown kid has to trigger a re-fetch even though the cache is warm.
     */
    public function testRefetchesWhenTheCachedSetDoesNotHaveTheKid(): void
    {
        $this->stored['scr1be_social_jwks_' . self::PROVIDER] = (new Json())->serialize(['kid-old' => 'stale-pem']);
        $this->respondWith(200, $this->jwks(['kid-new']));

        $this->assertStringStartsWith(
            '-----BEGIN PUBLIC KEY-----',
            $this->provider->getPem(self::PROVIDER, self::URI, 'kid-new')
        );
    }

    /**
     * Without the cooldown, a stream of forged tokens carrying random kids turns every request into
     * an outbound HTTPS call to the provider — a free amplifier pointed at Google.
     */
    public function testTheCooldownStopsRepeatedRefetches(): void
    {
        $this->stored['scr1be_social_jwks_' . self::PROVIDER] = (new Json())->serialize(['kid-1' => 'pem']);
        $this->stored['scr1be_social_jwks_' . self::PROVIDER . '_cooldown'] = '1';
        $this->curl->expects($this->never())->method('get');

        $this->expectException(SocialLoginException::class);
        $this->provider->getPem(self::PROVIDER, self::URI, 'kid-unknown');
    }

    /**
     * An unknown kid after a successful refresh is a forged token, not an outage — so the code is
     * INVALID_TOKEN, which a client must not retry.
     */
    public function testAnUnknownKidAfterARefreshIsAnInvalidToken(): void
    {
        $this->respondWith(200, $this->jwks(['kid-1']));

        try {
            $this->provider->getPem(self::PROVIDER, self::URI, 'kid-missing');
            $this->fail('Expected rejection');
        } catch (SocialLoginException $e) {
            $this->assertSame(SocialLoginException::INVALID_TOKEN, $e->getErrorCode());
        }
    }

    /**
     * Nothing cached and the endpoint is down: this is an outage, the code is retryable, and it must
     * not be confused with a bad token.
     */
    public function testAFailedFetchWithNoCacheIsAnOutage(): void
    {
        $this->respondWith(503, '');
        $this->logger->expects($this->once())->method('error');

        try {
            $this->provider->getPem(self::PROVIDER, self::URI, 'kid-1');
            $this->fail('Expected rejection');
        } catch (SocialLoginException $e) {
            $this->assertSame(SocialLoginException::KEYS_UNAVAILABLE, $e->getErrorCode());
        }
    }

    /**
     * Keys we cannot use must be dropped at cache time rather than half-converted at read time.
     */
    public function testSkipsNonRsaAndNonRs256Keys(): void
    {
        $body = (new Json())->serialize([
            'keys' => [
                ['kty' => 'EC', 'kid' => 'ec-key', 'crv' => 'P-256'],
                ['kty' => 'RSA', 'alg' => 'RS512', 'kid' => 'wrong-alg', 'n' => 'AQAB', 'e' => 'AQAB'],
                ['kty' => 'RSA', 'kid' => '', 'n' => 'AQAB', 'e' => 'AQAB'],
                ['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'good', 'n' => Base64Url::encode(
                    str_repeat("\x11", 256)
                ), 'e' => 'AQAB'],
            ],
        ]);
        $this->respondWith(200, $body);

        $this->assertStringStartsWith(
            '-----BEGIN PUBLIC KEY-----',
            $this->provider->getPem(self::PROVIDER, self::URI, 'good')
        );

        $cached = (new Json())->unserialize($this->stored['scr1be_social_jwks_' . self::PROVIDER]);
        $this->assertSame(['good'], array_keys($cached));
    }

    /**
     * A corrupt cache entry has to look like a miss, not like an outage.
     */
    public function testACorruptCacheEntryFallsBackToAFetch(): void
    {
        $this->stored['scr1be_social_jwks_' . self::PROVIDER] = 'not json at all';
        $this->respondWith(200, $this->jwks(['kid-1']));

        $this->assertStringStartsWith(
            '-----BEGIN PUBLIC KEY-----',
            $this->provider->getPem(self::PROVIDER, self::URI, 'kid-1')
        );
    }

    /**
     * @param int $status
     * @param string $body
     * @return void
     */
    private function respondWith(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }

    /**
     * @param string[] $keyIds
     * @return string
     */
    private function jwks(array $keyIds): string
    {
        $keys = [];
        foreach ($keyIds as $index => $keyId) {
            $keys[] = [
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $keyId,
                'n' => Base64Url::encode(str_repeat(chr(0x11 + $index), 256)),
                'e' => 'AQAB',
            ];
        }

        return (new Json())->serialize(['keys' => $keys]);
    }
}
