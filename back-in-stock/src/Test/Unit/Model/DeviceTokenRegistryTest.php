<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\DeviceTokenRegistry;
use Scr1be\BackInStock\Model\ResourceModel\DeviceTokenWriter;

/**
 * The validation in front of a public write endpoint.
 */
class DeviceTokenRegistryTest extends TestCase
{
    private const VALID_TOKEN = 'fXt1Zk9mQ0mS3pR7vLxN2bYc-8dEwHjK_4aTgU6iO5nP1qMzB0sVrD';

    private DeviceTokenWriter&MockObject $writer;
    private DeviceTokenRegistry $registry;

    protected function setUp(): void
    {
        $this->writer = $this->createMock(DeviceTokenWriter::class);
        $this->registry = new DeviceTokenRegistry($this->writer);
    }

    public function testTheHashIsWhatIdentifiesTheDevice(): void
    {
        $this->writer->expects($this->once())
            ->method('upsert')
            ->with(hash('sha256', self::VALID_TOKEN), self::VALID_TOKEN, 7, 1, 'web');

        $this->registry->register(self::VALID_TOKEN, 7, 1, 'web');
    }

    public function testAGuestRegistrationStoresNoCustomer(): void
    {
        // The sequence that actually happens: permission is granted on a product page, the account
        // is created afterwards. Refusing here means asking for permission twice.
        $this->writer->expects($this->once())
            ->method('upsert')
            ->with($this->anything(), $this->anything(), null, 1, 'web');

        $this->registry->register(self::VALID_TOKEN, 0, 1, 'web');
    }

    public function testSurroundingWhitespaceIsNotPartOfTheToken(): void
    {
        // A token that round-trips through a textarea or a copy-paste picks up a newline, and the
        // hash of "abc\n" is a different device from the hash of "abc".
        $this->writer->expects($this->once())
            ->method('upsert')
            ->with(hash('sha256', self::VALID_TOKEN), self::VALID_TOKEN);

        $this->registry->register("  " . self::VALID_TOKEN . "\n", null, 1, 'web');
    }

    public function testAnUnknownPlatformFallsBackToWebRatherThanBeingStored(): void
    {
        $this->writer->expects($this->once())
            ->method('upsert')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), 'web');

        $this->registry->register(self::VALID_TOKEN, null, 1, '"><script>');
    }

    /**
     * @dataProvider rejectedTokens
     */
    public function testAValueThatIsNotATokenIsRefused(string $token): void
    {
        $this->writer->expects($this->never())->method('upsert');
        $this->expectException(LocalizedException::class);

        $this->registry->register($token, null, 1, 'web');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedTokens(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['          '],
            'too short to be a registration token' => ['abc123'],
            'longer than the column' => [str_repeat('a', 513)],
            'carries markup' => [str_repeat('a', 40) . '<script>'],
            'carries a quote' => [str_repeat('a', 40) . '"'],
            'carries a newline' => [str_repeat('a', 20) . "\n" . str_repeat('b', 20)],
        ];
    }

    public function testRetiringHandsTheTokensStraightThrough(): void
    {
        $this->writer->expects($this->once())
            ->method('deactivate')
            ->with(['a', 'b'], 'gone')
            ->willReturn(2);

        $this->assertSame(2, $this->registry->retire(['a', 'b'], 'gone'));
    }
}
