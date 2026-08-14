<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Test\Unit\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Phrase;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfigInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\FailureToSendException;
use Magento\Framework\Stdlib\Cookie\SensitiveCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\CustomerGroupGuard\Model\GroupCookie;

class GroupCookieTest extends TestCase
{
    private CookieManagerInterface&MockObject $cookieManager;
    private CookieMetadataFactory&MockObject $cookieMetadataFactory;
    private SessionConfigInterface&MockObject $sessionConfig;
    private LoggerInterface&MockObject $logger;
    private GroupCookie $groupCookie;

    protected function setUp(): void
    {
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->cookieMetadataFactory = $this->createMock(CookieMetadataFactory::class);
        $this->sessionConfig = $this->createMock(SessionConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sessionConfig->method('getCookiePath')->willReturn('/');
        $this->sessionConfig->method('getCookieDomain')->willReturn('shop.test');

        $this->groupCookie = new GroupCookie(
            $this->cookieManager,
            $this->cookieMetadataFactory,
            $this->sessionConfig,
            $this->logger
        );
    }

    public function testReadsAGroupId(): void
    {
        $this->cookieManager->method('getCookie')
            ->with(GroupCookie::COOKIE_NAME)
            ->willReturn('3');

        $this->assertSame(3, $this->groupCookie->read());
    }

    /**
     * The value is browser input. Group 0 is a real group in Magento (NOT LOGGED IN), so a
     * lenient cast on garbage produces a comparison that can accidentally succeed.
     *
     * @dataProvider unusableCookieProvider
     */
    public function testAnythingThatIsNotAGroupIdReadsAsAbsent(?string $stored): void
    {
        $this->cookieManager->method('getCookie')->willReturn($stored);

        $this->assertNull($this->groupCookie->read());
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function unusableCookieProvider(): array
    {
        return [
            'never set' => [null],
            'blanked' => [''],
            'not a number' => ['wholesale'],
            'negative' => ['-1'],
            'float' => ['1.5'],
            'padded' => [' 1'],
        ];
    }

    public function testWritesASensitiveCookieScopedLikeTheSession(): void
    {
        $metadata = $this->createMock(SensitiveCookieMetadata::class);
        $metadata->expects($this->once())->method('setPath')->with('/')->willReturnSelf();
        $metadata->expects($this->once())->method('setDomain')->with('shop.test')->willReturnSelf();

        $this->cookieMetadataFactory->method('createSensitiveCookieMetadata')->willReturn($metadata);

        $this->cookieManager->expects($this->once())
            ->method('setSensitiveCookie')
            ->with(GroupCookie::COOKIE_NAME, '4', $metadata);

        $this->groupCookie->write(4);
    }

    /**
     * A cookie that could not be written is a soft path that will not fire. A login this module
     * broke is an outage.
     */
    public function testAFailedWriteIsLoggedAndSwallowed(): void
    {
        $this->cookieMetadataFactory->method('createSensitiveCookieMetadata')
            ->willReturn($this->metadataStub(SensitiveCookieMetadata::class));
        $this->cookieManager->method('setSensitiveCookie')
            ->willThrowException(new FailureToSendException(new Phrase('headers already sent')));

        $this->logger->expects($this->once())->method('warning');

        $this->groupCookie->write(4);
    }

    /**
     * Path and domain matter more on the delete than on the write: a mismatched pair leaves the
     * original cookie in place while the browser cheerfully accepts a second, differently scoped
     * one.
     */
    public function testClearsWithTheSameScopeItWroteWith(): void
    {
        $metadata = $this->createMock(CookieMetadata::class);
        $metadata->expects($this->once())->method('setPath')->with('/')->willReturnSelf();
        $metadata->expects($this->once())->method('setDomain')->with('shop.test')->willReturnSelf();

        $this->cookieMetadataFactory->method('createCookieMetadata')->willReturn($metadata);

        $this->cookieManager->expects($this->once())
            ->method('deleteCookie')
            ->with(GroupCookie::COOKIE_NAME, $metadata);

        $this->groupCookie->clear();
    }

    public function testAFailedClearIsLoggedAndSwallowed(): void
    {
        $this->cookieMetadataFactory->method('createCookieMetadata')
            ->willReturn($this->metadataStub(CookieMetadata::class));
        $this->cookieManager->method('deleteCookie')
            ->willThrowException(new InputException(new Phrase('bad domain')));

        $this->logger->expects($this->once())->method('warning');

        $this->groupCookie->clear();
    }

    /**
     * @param class-string $type
     */
    private function metadataStub(string $type): CookieMetadata&MockObject
    {
        $metadata = $this->createMock($type);
        $metadata->method('setPath')->willReturnSelf();
        $metadata->method('setDomain')->willReturnSelf();

        return $metadata;
    }
}
