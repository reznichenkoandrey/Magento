<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Token;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\SignedDocumentDelivery\Model\Token\SigningKey;

class SigningKeyTest extends TestCase
{
    private const A_KEY = 'e3f1a0c0d9b84a1e9f2c7d6b5a493827';
    private const ANOTHER_KEY = '11223344556677889900aabbccddeeff';

    private DeploymentConfig&MockObject $deploymentConfig;

    protected function setUp(): void
    {
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
    }

    public function testTheDerivedKeyIsNotTheCryptKey(): void
    {
        $this->deploymentConfig->method('get')->with(Encryptor::PARAM_CRYPT_KEY)->willReturn(self::A_KEY);

        $derived = (new SigningKey($this->deploymentConfig))->get();

        $this->assertNotSame(self::A_KEY, $derived);
        $this->assertStringNotContainsString(self::A_KEY, $derived);
        $this->assertSame(32, strlen($derived), 'HKDF was asked for 32 bytes');
    }

    public function testDerivationIsDeterministic(): void
    {
        $this->deploymentConfig->method('get')->willReturn(self::A_KEY);

        $first = (new SigningKey($this->deploymentConfig))->get();
        $second = (new SigningKey($this->deploymentConfig))->get();

        $this->assertSame($first, $second, 'a token issued by one node must verify on another');
    }

    public function testADifferentCryptKeyDerivesADifferentSigningKey(): void
    {
        $one = $this->createMock(DeploymentConfig::class);
        $one->method('get')->willReturn(self::A_KEY);
        $other = $this->createMock(DeploymentConfig::class);
        $other->method('get')->willReturn(self::ANOTHER_KEY);

        $this->assertNotSame(
            (new SigningKey($one))->get(),
            (new SigningKey($other))->get()
        );
    }

    /**
     * Magento\Framework\Encryption\Encryptor::__construct() splits crypt/key on whitespace and
     * treats the last entry as current. Signing with anything else would mean signing with a key
     * the installation has retired.
     *
     * @dataProvider rotatedKeyFiles
     */
    public function testTheNewestKeyOfARotatedSetIsUsed(string $stored): void
    {
        $rotated = $this->createMock(DeploymentConfig::class);
        $rotated->method('get')->willReturn($stored);

        $justTheNewest = $this->createMock(DeploymentConfig::class);
        $justTheNewest->method('get')->willReturn(self::ANOTHER_KEY);

        $this->assertSame(
            (new SigningKey($justTheNewest))->get(),
            (new SigningKey($rotated))->get()
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rotatedKeyFiles(): array
    {
        return [
            'newline separated' => [self::A_KEY . "\n" . self::ANOTHER_KEY],
            'space separated' => [self::A_KEY . ' ' . self::ANOTHER_KEY],
            'trailing newline' => [self::A_KEY . "\n" . self::ANOTHER_KEY . "\n"],
            'three keys' => ['0000' . "\n" . self::A_KEY . "\n" . self::ANOTHER_KEY],
        ];
    }

    public function testTheKeyIsDerivedOnceAndMemoised(): void
    {
        $this->deploymentConfig->expects($this->once())->method('get')->willReturn(self::A_KEY);

        $signingKey = new SigningKey($this->deploymentConfig);
        $signingKey->get();
        $signingKey->get();
    }

    /**
     * @dataProvider unusableCryptKeys
     */
    public function testAnInstallationWithoutACryptKeyIsAnError(?string $stored): void
    {
        $this->deploymentConfig->method('get')->willReturn($stored);

        $this->expectException(LocalizedException::class);

        (new SigningKey($this->deploymentConfig))->get();
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unusableCryptKeys(): array
    {
        return [
            'never set' => [null],
            'empty' => [''],
            'whitespace only' => ["  \n\t "],
        ];
    }
}
