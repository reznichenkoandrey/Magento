<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private EncryptorInterface&MockObject $encryptor;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->config = new Config($this->scopeConfig, $this->encryptor);
    }

    /**
     * @dataProvider outOfRangeItemCounts
     */
    public function testTheItemCountIsClampedToSomethingAPopupCanHold(string $stored): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        $this->assertSame(6, (new Config($scopeConfig, $this->encryptor))->getMaxItems());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function outOfRangeItemCounts(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-3'],
            'more than a modal can hold' => ['25'],
            'not a number' => ['nonsense'],
            'never configured' => [''],
        ];
    }

    public function testAValidItemCountIsUsedAsGiven(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('12');

        $this->assertSame(12, $this->config->getMaxItems());
    }

    public function testZeroIsAValidLowStockThresholdBecauseItMeansOff(): void
    {
        // Unlike the item count, zero here is a deliberate setting rather than an empty field: it is
        // how a merchant stops the storefront claiming anything about stock levels.
        $this->scopeConfig->method('getValue')->willReturn('0');

        $this->assertSame(0, $this->config->getLowStockThreshold());
    }

    public function testAnAbsurdLowStockThresholdFallsBackToTheDefault(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('5000');

        $this->assertSame(5, $this->config->getLowStockThreshold());
    }

    public function testThePopupIsStoreScopedAndThePushChannelIsWebsiteScoped(): void
    {
        // The scopes are not interchangeable: a popup is storefront copy, a Firebase project is an
        // installation credential. Reading either at the wrong scope silently returns the default.
        $this->scopeConfig->expects($this->exactly(2))
            ->method('isSetFlag')
            ->willReturnCallback(static function (string $path, string $scope): bool {
                if ($path === Config::XML_PATH_POPUP_ENABLED) {
                    return $scope === ScopeInterface::SCOPE_STORE;
                }

                return $scope === ScopeInterface::SCOPE_WEBSITE;
            });

        $this->assertTrue($this->config->isPopupEnabled(1));
        $this->assertTrue($this->config->isPushEnabled(1));
    }

    public function testTheServiceAccountIsDecryptedOnTheWayOut(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0:3:ciphertext');
        $this->encryptor->method('decrypt')->with('0:3:ciphertext')->willReturn('{"client_email":"a@b.c"}');

        $this->assertSame('{"client_email":"a@b.c"}', $this->config->getPushServiceAccountJson(1));
    }

    public function testAnEmptyServiceAccountNeverReachesTheEncryptor(): void
    {
        // `decrypt('')` is a wasted call on every send for an installation that has not configured
        // push, and the empty string is the answer either way.
        $this->scopeConfig->method('getValue')->willReturn('');
        $this->encryptor->expects($this->never())->method('decrypt');

        $this->assertSame('', $this->config->getPushServiceAccountJson(1));
    }

    public function testWhitespaceAroundTheProjectIdIsTrimmedAwayBeforeItReachesAUrl(): void
    {
        $this->scopeConfig->method('getValue')->willReturn("  my-project \n");

        $this->assertSame('my-project', $this->config->getPushProjectId(1));
    }
}
