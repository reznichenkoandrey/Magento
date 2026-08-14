<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model;

use Magento\Framework\Exception\ConfigurationMismatchException;
use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Api\Data\EntryInterface;
use Scr1be\ContentTransfer\Api\PorterInterface;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\Outcome;
use Scr1be\ContentTransfer\Model\PorterPool;
use Scr1be\ContentTransfer\Model\Selection;

class PorterPoolTest extends TestCase
{
    public function testPortersAreKeyedByTheirOwnCodeNotByTheDiArrayKey(): void
    {
        // A di.xml item name and a porter code are two different things maintained by two different
        // people. Looking up by the code is what the bundle format promises.
        $pool = new PorterPool(['whatever_the_xml_said' => $this->porter('cms_block')]);

        $this->assertTrue($pool->has('cms_block'));
        $this->assertFalse($pool->has('whatever_the_xml_said'));
    }

    public function testAnUnknownCodeThrowsRatherThanReturningNull(): void
    {
        $this->expectException(ConfigurationMismatchException::class);

        (new PorterPool([]))->get('cms_page');
    }

    public function testSomethingThatIsNotAPorterIsRejectedAtConstruction(): void
    {
        $this->expectException(ConfigurationMismatchException::class);

        new PorterPool(['broken' => new \stdClass()]);
    }

    public function testDependenciesComeFirst(): void
    {
        $pool = new PorterPool([
            $this->porter('widget_instance', ['cms_page']),
            $this->porter('cms_page', ['cms_block']),
            $this->porter('cms_block'),
        ]);

        $this->assertSame(['cms_block', 'cms_page', 'widget_instance'], $this->codesOf($pool));
    }

    public function testIndependentPortersAreOrderedAlphabetically(): void
    {
        // Two installs merging the same di.xml files in a different order must produce the same
        // bundle, so ties cannot be broken by registration order.
        $pool = new PorterPool([$this->porter('zebra'), $this->porter('aardvark')]);

        $this->assertSame(['aardvark', 'zebra'], $this->codesOf($pool));
    }

    public function testACycleThrowsInsteadOfRecursingForever(): void
    {
        $pool = new PorterPool([
            $this->porter('first', ['second']),
            $this->porter('second', ['first']),
        ]);

        $this->expectException(ConfigurationMismatchException::class);
        $this->expectExceptionMessageMatches('/cycle/');

        $pool->getSorted();
    }

    public function testADependencyOnSomethingUnregisteredThrows(): void
    {
        $pool = new PorterPool([$this->porter('cms_page', ['cms_block'])]);

        $this->expectException(ConfigurationMismatchException::class);
        $this->expectExceptionMessageMatches('/not registered/');

        $pool->getSorted();
    }

    public function testADiamondPutsEachPorterInExactlyOnce(): void
    {
        $pool = new PorterPool([
            $this->porter('top', ['left', 'right']),
            $this->porter('left', ['base']),
            $this->porter('right', ['base']),
            $this->porter('base'),
        ]);

        $this->assertSame(['base', 'left', 'right', 'top'], $this->codesOf($pool));
    }

    /**
     * @return string[]
     */
    private function codesOf(PorterPool $pool): array
    {
        return array_map(
            static fn (PorterInterface $porter): string => $porter->getCode(),
            $pool->getSorted()
        );
    }

    /**
     * @param string[] $dependencies
     */
    private function porter(string $code, array $dependencies = []): PorterInterface
    {
        return new class ($code, $dependencies) implements PorterInterface {
            /**
             * @param string[] $dependencies
             */
            public function __construct(private string $code, private array $dependencies)
            {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function getLabel(): string
            {
                return $this->code;
            }

            public function getDependencies(): array
            {
                return $this->dependencies;
            }

            public function summarize(Selection $selection): array
            {
                return [];
            }

            public function capture(Selection $selection): array
            {
                return [];
            }

            public function exists(EntryInterface $entry): bool
            {
                return false;
            }

            public function apply(EntryInterface $entry, ImportMode $mode): Outcome
            {
                return Outcome::skipped();
            }
        };
    }
}
