<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\StoreSwitcher\Model\FlagSprite;

class FlagSpriteTest extends TestCase
{
    private FlagSprite $sprite;

    protected function setUp(): void
    {
        $this->sprite = new FlagSprite();
    }

    public function testResolvesAShippedFlag(): void
    {
        self::assertSame('de', $this->sprite->resolve('DE'));
    }

    public function testAnythingElseResolvesToTheFallbackSymbol(): void
    {
        // The important half: `resolve()` may only ever return an id the sprite actually defines,
        // or the page renders a <use> pointing at nothing and the row silently loses its icon.
        self::assertSame(FlagSprite::FALLBACK_CODE, $this->sprite->resolve('us'));
        self::assertSame(FlagSprite::FALLBACK_CODE, $this->sprite->resolve(''));
    }

    public function testEveryResolvedCodeIsEitherDefinedOrTheFallback(): void
    {
        foreach (array_keys($this->sprite->getFlags()) as $code) {
            self::assertArrayHasKey($code, $this->sprite->getFlags());
            self::assertSame($code, $this->sprite->resolve($code));
        }
    }

    public function testOnlyReferencedFlagsAreEmitted(): void
    {
        $used = $this->sprite->getUsedFlags(['de', 'us', 'fr', 'de']);

        self::assertSame(['de', 'fr'], array_keys($used));
    }

    public function testUnshippedFlagsContributeNoSymbol(): void
    {
        self::assertSame([], $this->sprite->getUsedFlags(['us', 'gb', 'jp']));
    }

    public function testEveryFlagDefinitionIsDrawable(): void
    {
        foreach ($this->sprite->getFlags() as $code => $flag) {
            self::assertContains(
                $flag['orientation'],
                [FlagSprite::ORIENTATION_HORIZONTAL, FlagSprite::ORIENTATION_VERTICAL],
                sprintf('Flag "%s" has an orientation the template cannot render.', $code)
            );
            self::assertNotEmpty($flag['colors'], sprintf('Flag "%s" has no bands.', $code));

            foreach ($flag['colors'] as $color) {
                self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $color);
            }
        }
    }
}
