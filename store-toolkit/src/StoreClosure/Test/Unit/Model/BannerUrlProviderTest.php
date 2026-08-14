<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreClosure\Model\Banner\BannerUrlProvider;

class BannerUrlProviderTest extends TestCase
{
    /**
     * @var WriteInterface&MockObject
     */
    private $mediaDirectory;

    /**
     * @var CacheInterface&MockObject
     */
    private $cache;

    private BannerUrlProvider $provider;

    protected function setUp(): void
    {
        $this->mediaDirectory = $this->createMock(WriteInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->mediaDirectory);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_MEDIA)->willReturn('https://cdn.example.com/media/');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->provider = new BannerUrlProvider($filesystem, $storeManager, $this->cache);
    }

    public function testTheFileNameIsAHashOfTheImageBytes(): void
    {
        $this->givenSourceFile('closed.png', 'PNG-BYTES', 111, 9);
        $this->mediaDirectory->method('isExist')->willReturnCallback(
            static fn (string $path): bool => $path === 'scr1be/closure/closed.png'
        );

        $expectedHash = substr(hash('sha256', 'PNG-BYTES'), 0, 32);

        $this->mediaDirectory->expects(self::once())
            ->method('writeFile')
            ->with('scr1be/closure/content/' . $expectedHash . '.png', 'PNG-BYTES');

        self::assertSame(
            'https://cdn.example.com/media/scr1be/closure/content/' . $expectedHash . '.png',
            $this->provider->getUrl('closed.png')
        );
    }

    public function testAnAlreadyPublishedCopyIsNotRewritten(): void
    {
        $this->givenSourceFile('closed.png', 'PNG-BYTES', 111, 9);
        $this->mediaDirectory->method('isExist')->willReturn(true);

        $this->mediaDirectory->expects(self::never())->method('writeFile');

        self::assertNotNull($this->provider->getUrl('closed.png'));
    }

    public function testAWarmCacheSkipsTheHashEntirely(): void
    {
        $this->mediaDirectory->method('isExist')->willReturn(true);
        $this->mediaDirectory->method('isFile')->willReturn(true);
        $this->mediaDirectory->method('stat')->willReturn(['mtime' => 111, 'size' => 9]);
        $this->mediaDirectory->expects(self::never())->method('readFile');

        $this->cache->method('load')->willReturn('111:9|scr1be/closure/content/cached.png');

        self::assertSame(
            'https://cdn.example.com/media/scr1be/closure/content/cached.png',
            $this->provider->getUrl('closed.png')
        );
    }

    public function testAChangedSourceInvalidatesTheCachedAnswer(): void
    {
        $this->givenSourceFile('closed.png', 'NEW-BYTES', 222, 9);
        $this->mediaDirectory->method('isExist')->willReturnCallback(
            static fn (string $path): bool => $path === 'scr1be/closure/closed.png'
        );

        // Cached under the previous mtime, so the signature no longer matches and the file is
        // re-hashed rather than served from a stale entry.
        $this->cache->method('load')->willReturn('111:9|scr1be/closure/content/old.png');
        $this->mediaDirectory->expects(self::once())->method('writeFile');

        $expectedHash = substr(hash('sha256', 'NEW-BYTES'), 0, 32);

        self::assertStringEndsWith($expectedHash . '.png', (string) $this->provider->getUrl('closed.png'));
    }

    public function testAMissingSourceYieldsNoUrl(): void
    {
        $this->mediaDirectory->method('isExist')->willReturn(false);

        self::assertNull($this->provider->getUrl('closed.png'));
    }

    /**
     * @dataProvider hostileValueProvider
     */
    public function testHostileConfigValuesNeverReachTheFilesystem(string $storedValue): void
    {
        $this->mediaDirectory->expects(self::never())->method('isExist');

        self::assertNull($this->provider->getUrl($storedValue));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileValueProvider(): array
    {
        return [
            'traversal' => ['../../app/etc/env.php'],
            'nested traversal' => ['stores/2/../../../env.php'],
            'unsupported extension' => ['stores/2/env.php'],
            'no extension' => ['stores/2/banner'],
            'empty' => [''],
            'null byte' => ["closed.png\0.txt"],
        ];
    }

    public function testTheScopedPathTheUploaderStoresIsAccepted(): void
    {
        // With `scope_info` on the field, Magento\Config\Model\Config\Backend\File::beforeSave()
        // prepends the scope, so the stored value looks like `stores/2/closed.png`.
        $this->givenSourceFile('stores/2/closed.png', 'BYTES', 111, 5);
        $this->mediaDirectory->method('isExist')->willReturnCallback(
            static fn (string $path): bool => $path === 'scr1be/closure/stores/2/closed.png'
        );

        self::assertNotNull($this->provider->getUrl('stores/2/closed.png'));
    }

    private function givenSourceFile(string $relative, string $contents, int $mtime, int $size): void
    {
        $this->mediaDirectory->method('isFile')->willReturn(true);
        $this->mediaDirectory->method('stat')->willReturn(['mtime' => $mtime, 'size' => $size]);
        $this->mediaDirectory->method('readFile')
            ->with('scr1be/closure/' . $relative)
            ->willReturn($contents);
    }
}
