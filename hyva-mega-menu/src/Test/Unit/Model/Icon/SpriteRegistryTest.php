<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Test\Unit\Model\Icon;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMegaMenu\Model\Icon\SpriteRegistry;

class SpriteRegistryTest extends TestCase
{
    private SpriteRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new SpriteRegistry();
    }

    public function testTheChromeIsAlwaysInjected(): void
    {
        $symbols = $this->registry->getSymbolsFor([]);

        $this->assertSame(
            [
                SpriteRegistry::CHEVRON_RIGHT,
                SpriteRegistry::CHEVRON_DOWN,
                SpriteRegistry::MENU,
                SpriteRegistry::CLOSE,
            ],
            array_keys($symbols)
        );
    }

    /**
     * The sprite is inlined into every cached page, so a catalogue that tags three categories has
     * no business shipping twelve icons on every request.
     */
    public function testOnlyTheSymbolsTheMenuUsedAreAdded(): void
    {
        $symbols = $this->registry->getSymbolsFor(['tag', 'gift']);

        $this->assertArrayHasKey('tag', $symbols);
        $this->assertArrayHasKey('gift', $symbols);
        $this->assertArrayNotHasKey('truck', $symbols);
    }

    public function testAnUnknownKeyContributesNothing(): void
    {
        $this->assertSame(
            $this->registry->getSymbolsFor([]),
            $this->registry->getSymbolsFor(['unicorn'])
        );
    }

    public function testARepeatedKeyIsInjectedOnce(): void
    {
        $symbols = $this->registry->getSymbolsFor(['tag', 'tag', SpriteRegistry::MENU]);

        $this->assertCount(5, $symbols);
    }

    public function testTheChromeKeysAreNotOfferedAsCategoryIcons(): void
    {
        $categoryKeys = $this->registry->getCategoryKeys();

        $this->assertNotContains(SpriteRegistry::MENU, $categoryKeys);
        $this->assertNotContains(SpriteRegistry::CHEVRON_DOWN, $categoryKeys);
        $this->assertContains('tag', $categoryKeys);
    }

    public function testEveryOfferedKeyResolvesToASymbol(): void
    {
        foreach ($this->registry->getCategoryKeys() as $key) {
            $this->assertTrue($this->registry->has($key), $key . ' is offered but has no symbol');
        }
    }

    /**
     * Nothing in the sprite may carry a colour of its own: the whole reason these are inline
     * symbols rather than image files is that `stroke="currentColor"` on the wrapper tints them
     * from the text beside them, and a fill or stroke in the path data would opt out of that.
     */
    public function testNoSymbolCarriesAColourOfItsOwn(): void
    {
        $symbols = $this->registry->getSymbolsFor($this->registry->getCategoryKeys());

        foreach ($symbols as $key => $markup) {
            $this->assertStringNotContainsString('fill="', $markup, $key . ' sets its own fill');
            $this->assertStringNotContainsString('stroke="', $markup, $key . ' sets its own stroke');
        }
    }
}
