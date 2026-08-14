<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Test\Unit\Model\Icon;

use Magento\Catalog\Model\Category;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\HyvaMegaMenu\Model\Icon\Icon;
use Scr1be\HyvaMegaMenu\Model\Icon\IconResolver;
use Scr1be\HyvaMegaMenu\Model\Icon\SpriteRegistry;

class IconResolverTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private IconResolver $resolver;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resolver = new IconResolver(new SpriteRegistry(), $this->logger);
    }

    /**
     * @param array<string, string> $data
     */
    private function category(array $data, string|false|null $imageUrl = false): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(7);
        $category->method('getData')->willReturnCallback(
            static fn (string $key = '') => $data[$key] ?? null
        );
        $category->method('getImageUrl')->willReturn($imageUrl);

        return $category;
    }

    public function testTheSpriteKeyWinsOverEverything(): void
    {
        $icon = $this->resolver->resolve($this->category([
            IconResolver::ATTRIBUTE_SPRITE => 'tag',
            IconResolver::ATTRIBUTE_IMAGE => 'icon.png',
            IconResolver::ATTRIBUTE_CLASS => 'fa fa-tag',
            IconResolver::ATTRIBUTE_COLOR => '#abc',
        ], 'https://shop.test/media/icon.png'));

        $this->assertSame(Icon::TYPE_SPRITE, $icon->type);
        $this->assertSame('tag', $icon->value);
    }

    /**
     * The ladder is a fallback chain, not a switch: a key the sprite does not carry has to hand
     * over to the next source rather than render a `<use>` pointing at nothing.
     */
    public function testAnUnknownSpriteKeyStepsAside(): void
    {
        $icon = $this->resolver->resolve($this->category([
            IconResolver::ATTRIBUTE_SPRITE => 'unicorn',
            IconResolver::ATTRIBUTE_COLOR => '#abcdef',
        ]));

        $this->assertSame(Icon::TYPE_COLOR, $icon->type);
        $this->assertSame('#abcdef', $icon->value);
    }

    public function testTheImageUrlComesFromTheCategory(): void
    {
        $icon = $this->resolver->resolve($this->category([
            IconResolver::ATTRIBUTE_IMAGE => 'icon.png',
            IconResolver::ATTRIBUTE_CLASS => 'fa fa-tag',
        ], 'https://shop.test/media/catalog/category/icon.png'));

        $this->assertSame(Icon::TYPE_IMAGE, $icon->type);
        $this->assertSame('https://shop.test/media/catalog/category/icon.png', $icon->value);
    }

    /**
     * `Category::getImageUrl()` throws when the stored value is not a string, which means the row
     * was written by something other than the image backend. One broken category must not be able
     * to take the header menu off every page.
     */
    public function testAnImageThatCannotBeResolvedIsLoggedAndSkipped(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(7);
        $category->method('getData')->willReturnCallback(
            static fn (string $key = '') => [
                IconResolver::ATTRIBUTE_IMAGE => 'icon.png',
                IconResolver::ATTRIBUTE_CLASS => 'icon-bag',
            ][$key] ?? null
        );
        $category->method('getImageUrl')->willThrowException(new LocalizedException(__('broken')));

        $this->logger->expects($this->once())->method('warning');

        $icon = $this->resolver->resolve($category);

        $this->assertSame(Icon::TYPE_CLASS, $icon->type);
        $this->assertSame('icon-bag', $icon->value);
    }

    public function testAnEmptyImageUrlStepsAside(): void
    {
        $icon = $this->resolver->resolve(
            $this->category([IconResolver::ATTRIBUTE_IMAGE => 'icon.png'], false)
        );

        $this->assertSame(Icon::TYPE_NONE, $icon->type);
    }

    /**
     * @dataProvider unusableClassProvider
     */
    public function testAClassAttributeThatCouldCarryMarkupIsIgnored(string $class): void
    {
        $icon = $this->resolver->resolve($this->category([IconResolver::ATTRIBUTE_CLASS => $class]));

        $this->assertSame(Icon::TYPE_NONE, $icon->type);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableClassProvider(): array
    {
        return [
            'double quote' => ['icon" onload="alert(1)'],
            'single quote' => ["icon' onload='alert(1)"],
            'angle bracket' => ['icon<svg'],
            'newline' => ["icon\nother"],
            'too long' => [str_repeat('a', 121)],
        ];
    }

    public function testAUtilityClassWithVariantsAndFractionsIsAccepted(): void
    {
        $icon = $this->resolver->resolve(
            $this->category([IconResolver::ATTRIBUTE_CLASS => 'icon-bag hover:text-primary w-1/2'])
        );

        $this->assertSame(Icon::TYPE_CLASS, $icon->type);
        $this->assertSame('icon-bag hover:text-primary w-1/2', $icon->value);
    }

    /**
     * The colour ends up inside a CSS custom property, where a declaration can be closed and
     * another opened. An allowlist that admits exactly what a colour looks like leaves nothing
     * to escape.
     *
     * @dataProvider unusableColorProvider
     */
    public function testAnythingThatIsNotAHexColourIsIgnored(string $color): void
    {
        $icon = $this->resolver->resolve($this->category([IconResolver::ATTRIBUTE_COLOR => $color]));

        $this->assertSame(Icon::TYPE_NONE, $icon->type);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableColorProvider(): array
    {
        return [
            'named colour' => ['red'],
            'no hash' => ['aabbcc'],
            'four digits' => ['#aabb'],
            'eight digits' => ['#aabbccdd'],
            'css function' => ['rgb(1,2,3)'],
            'declaration break out' => ['#abc; background-image: url(https://evil.test/x)'],
        ];
    }

    public function testHexColoursAreNormalisedToLowercase(): void
    {
        $icon = $this->resolver->resolve($this->category([IconResolver::ATTRIBUTE_COLOR => '#AABBCC']));

        $this->assertSame(Icon::TYPE_COLOR, $icon->type);
        $this->assertSame('#aabbcc', $icon->value);
    }

    public function testWhitespaceOnlyValuesReadAsAbsent(): void
    {
        $icon = $this->resolver->resolve($this->category([
            IconResolver::ATTRIBUTE_SPRITE => '   ',
            IconResolver::ATTRIBUTE_CLASS => "\t",
            IconResolver::ATTRIBUTE_COLOR => ' ',
        ]));

        $this->assertSame(Icon::TYPE_NONE, $icon->type);
        $this->assertFalse($icon->isPresent());
    }

    public function testAnAbsentIconCarriesNothingIntoTheIsland(): void
    {
        $this->assertNull(Icon::none()->toIslandArray());
        $this->assertSame(['t' => 'sprite', 'v' => 'tag'], Icon::sprite('tag')->toIslandArray());
    }
}
