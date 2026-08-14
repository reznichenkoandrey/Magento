<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\MediaUrl;

class MediaUrlTest extends TestCase
{
    public function testThePathIsAppendedToTheStoreMediaBaseUrl(): void
    {
        $mediaUrl = new MediaUrl($this->storeManagerReturning('https://shop.test/media/'));

        $this->assertSame(
            'https://shop.test/media/scr1be/media/768/wysiwyg/banner.jpg',
            $mediaUrl->forPath('scr1be/media/768/wysiwyg/banner.jpg')
        );
    }

    public function testTheMediaUrlTypeIsRequestedRatherThanTheLinkType(): void
    {
        // The link base URL and the media base URL differ on any install with a CDN in front of
        // pub/media, which is most of the installs this module is worth having on.
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_MEDIA)
            ->willReturn('https://cdn.test/media/');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        (new MediaUrl($storeManager))->forPath('wysiwyg/a.jpg');
    }

    public function testSpacesAreEncodedBecauseASrcsetIsSpaceDelimited(): void
    {
        // A raw space does not merely look untidy in a srcset: the candidate ends at the space and
        // the remainder of the filename is parsed as the width descriptor.
        $mediaUrl = new MediaUrl($this->storeManagerReturning('https://shop.test/media/'));

        $this->assertSame(
            'https://shop.test/media/wysiwyg/summer%20sale%20hero.jpg',
            $mediaUrl->forPath('wysiwyg/summer sale hero.jpg')
        );
    }

    public function testSeparatorsSurviveEncoding(): void
    {
        // rawurlencode() over the whole path would turn every slash into %2F and produce a URL
        // pointing at one absurdly named file in the media root.
        $mediaUrl = new MediaUrl($this->storeManagerReturning('https://shop.test/media/'));

        $this->assertSame(
            'https://shop.test/media/a/b/c/d.png',
            $mediaUrl->forPath('a/b/c/d.png')
        );
    }

    public function testNonAsciiFilenamesAreEncoded(): void
    {
        $mediaUrl = new MediaUrl($this->storeManagerReturning('https://shop.test/media/'));

        $this->assertSame(
            'https://shop.test/media/wysiwyg/caf%C3%A9.jpg',
            $mediaUrl->forPath('wysiwyg/café.jpg')
        );
    }

    private function storeManagerReturning(string $baseUrl): StoreManagerInterface
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }
}
