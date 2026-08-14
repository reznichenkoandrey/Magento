<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Model\Card;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Card\MediaResolver;
use Scr1be\HyvaProductCard\Model\Config;

class MediaResolverTest extends TestCase
{
    private const IMAGE_ID = 'category_page_grid';

    private ImageHelper&MockObject $imageHelper;
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->imageHelper = $this->createMock(ImageHelper::class);
        $this->imageHelper->method('init')->willReturnSelf();
        $this->imageHelper->method('resize')->willReturnSelf();
        $this->imageHelper->method('setImageFile')->willReturnSelf();
        $this->imageHelper->method('getWidth')->willReturn(240);
        $this->imageHelper->method('getHeight')->willReturn(300);
        $this->imageHelper->method('getLabel')->willReturn('Joust Duffle Bag');

        $this->config = $this->createMock(Config::class);
        $this->config->method('getSizesAttribute')->willReturn('50vw');
    }

    public function testTheLadderBecomesASrcsetWithOneRungPerConfiguredWidth(): void
    {
        $this->config->method('getSrcsetWidths')->willReturn([240, 480]);
        $this->config->method('isHoverImageEnabled')->willReturn(false);
        $this->imageHelper->method('getUrl')->willReturnOnConsecutiveCalls(
            'https://example.test/240.jpg',
            'https://example.test/480.jpg',
            'https://example.test/240.jpg'
        );

        $image = $this->resolver()->resolve($this->product(), self::IMAGE_ID);

        $this->assertSame(
            'https://example.test/240.jpg 240w, https://example.test/480.jpg 480w',
            $image->getSrcset()
        );
        $this->assertSame('https://example.test/240.jpg', $image->getUrl());
        $this->assertSame(240, $image->getWidth());
        $this->assertSame('50vw', $image->getSizes());
    }

    public function testASingleRungProducesNoSrcsetAtAll(): void
    {
        // One rung tells the browser nothing that `src` did not already say.
        $this->config->method('getSrcsetWidths')->willReturn([320]);
        $this->config->method('isHoverImageEnabled')->willReturn(false);
        $this->imageHelper->method('getUrl')->willReturn('https://example.test/320.jpg');

        $this->assertSame('', $this->resolver()->resolve($this->product(), self::IMAGE_ID)->getSrcset());
    }

    public function testTheHoverCeilingIsSpentAcrossTheWholePageNotPerCard(): void
    {
        $this->config->method('getSrcsetWidths')->willReturn([240]);
        $this->config->method('isHoverImageEnabled')->willReturn(true);
        $this->config->method('getHoverImageCeiling')->willReturn(2);
        $this->imageHelper->method('getUrl')->willReturn('https://example.test/image.jpg');

        $resolver = $this->resolver();

        $this->assertNotNull($resolver->resolve($this->product(), self::IMAGE_ID)->getHoverUrl());
        $this->assertNotNull($resolver->resolve($this->product(), self::IMAGE_ID)->getHoverUrl());
        $this->assertNull($resolver->resolve($this->product(), self::IMAGE_ID)->getHoverUrl());
    }

    public function testAProductWhoseBaseImageIsItsListingImageHasNoHoverCandidate(): void
    {
        // `image` equal to `small_image` is the same picture; swapping to it on hover would look
        // like a broken interaction rather than a second angle.
        $this->config->method('getSrcsetWidths')->willReturn([240]);
        $this->config->method('isHoverImageEnabled')->willReturn(true);
        $this->config->method('getHoverImageCeiling')->willReturn(10);
        $this->imageHelper->method('getUrl')->willReturn('https://example.test/image.jpg');

        $product = $this->product('/j/o/joust.jpg', '/j/o/joust.jpg');

        $this->assertNull($this->resolver()->resolve($product, self::IMAGE_ID)->getHoverUrl());
    }

    public function testAPlaceholderBaseImageIsNotAHoverCandidate(): void
    {
        $this->config->method('getSrcsetWidths')->willReturn([240]);
        $this->config->method('isHoverImageEnabled')->willReturn(true);
        $this->config->method('getHoverImageCeiling')->willReturn(10);
        $this->imageHelper->method('getUrl')->willReturn('https://example.test/image.jpg');

        $product = $this->product('no_selection', '/j/o/joust.jpg');

        $this->assertNull($this->resolver()->resolve($product, self::IMAGE_ID)->getHoverUrl());
    }

    public function testTheGalleryWinsOverTheImageAttributeWhenItIsAlreadyLoaded(): void
    {
        $this->config->method('getSrcsetWidths')->willReturn([240]);
        $this->config->method('isHoverImageEnabled')->willReturn(true);
        $this->config->method('getHoverImageCeiling')->willReturn(10);
        $this->imageHelper->method('getUrl')->willReturn('https://example.test/image.jpg');

        $this->imageHelper->expects($this->once())
            ->method('setImageFile')
            ->with('/j/o/joust-back.jpg')
            ->willReturnSelf();

        $product = $this->product('/j/o/joust-alt.jpg', '/j/o/joust.jpg', [
            new \Magento\Framework\DataObject(['file' => '/j/o/joust.jpg']),
            new \Magento\Framework\DataObject(['file' => '/j/o/joust-back.jpg']),
        ]);

        $this->resolver()->resolve($product, self::IMAGE_ID);
    }

    private function resolver(): MediaResolver
    {
        return new MediaResolver($this->imageHelper, $this->config);
    }

    /**
     * @param \Magento\Framework\DataObject[]|null $gallery
     */
    private function product(
        string $image = '/j/o/joust-alt.jpg',
        string $smallImage = '/j/o/joust.jpg',
        ?array $gallery = null
    ): Product&MockObject {
        $product = $this->createMock(Product::class);
        $product->method('getStoreId')->willReturn(0);
        $product->method('getData')->willReturnCallback(
            static function (string $key) use ($image, $smallImage, $gallery) {
                return match ($key) {
                    'image' => $image,
                    'small_image' => $smallImage,
                    'media_gallery_images' => $gallery,
                    default => null,
                };
            }
        );

        return $product;
    }
}
