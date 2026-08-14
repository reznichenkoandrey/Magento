<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model;

use ArrayObject;
use Magento\Framework\App\Cache\TypeListInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Scr1be\ContentTransfer\Api\Data\EntryInterface;
use Scr1be\ContentTransfer\Api\PorterInterface;
use Scr1be\ContentTransfer\Model\Bundle;
use Scr1be\ContentTransfer\Model\Bundle\Manifest;
use Scr1be\ContentTransfer\Model\Entry;
use Scr1be\ContentTransfer\Model\ImportEngine;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\Outcome;
use Scr1be\ContentTransfer\Model\PorterPool;
use Scr1be\ContentTransfer\Model\Selection;
use Throwable;

class ImportEngineTest extends TestCase
{
    public function testOneFailingEntryDoesNotStopTheRest(): void
    {
        // The property the whole format is built around: fix one entry, re-run, and the ones that
        // landed are skipped. A single transaction would have thrown all of them away.
        $porter = $this->porter('cms_block', failures: ['boom' => new RuntimeException('identifier is empty')]);

        $report = $this->engine([$porter])->apply(
            $this->bundleOf([
                new Entry('cms_block', 'good-one', []),
                new Entry('cms_block', 'boom', []),
                new Entry('cms_block', 'good-two', []),
            ]),
            ImportMode::Skip
        );

        $this->assertSame(['good-one', 'boom', 'good-two'], array_column($report->getRows(), 'identifier'));
        $this->assertCount(1, $report->getFailures());
        $this->assertSame(2, $report->getTotals()[Outcome::STATUS_CREATED]);
    }

    public function testTheExceptionMessageReachesTheReport(): void
    {
        $porter = $this->porter('cms_block', failures: ['boom' => new RuntimeException('identifier is empty')]);

        $report = $this->engine([$porter])->apply(
            $this->bundleOf([new Entry('cms_block', 'boom', [])]),
            ImportMode::Skip
        );

        $this->assertSame('identifier is empty', $report->getFailures()[0]['outcome']->getMessage());
    }

    public function testEntriesAreAppliedInDependencyOrderRegardlessOfFileOrder(): void
    {
        // A hand-edited bundle, or one assembled from a zip's alphabetical file list, can present
        // pages before the blocks they embed.
        $log = new ArrayObject();

        $this->engine([
            $this->porter('cms_page', dependencies: ['cms_block'], log: $log),
            $this->porter('cms_block', log: $log),
        ])->apply(
            $this->bundleOf([
                new Entry('cms_page', 'home', []),
                new Entry('cms_block', 'footer-links', []),
            ]),
            ImportMode::Skip
        );

        $this->assertSame(['cms_block/footer-links', 'cms_page/home'], $log->getArrayCopy());
    }

    public function testAnEntryWhosePorterIsNotInstalledIsAFailureNotASilentSkip(): void
    {
        $report = $this->engine([$this->porter('cms_block')])->apply(
            $this->bundleOf([new Entry('coupon_ticket', 'ticket--spring', [])]),
            ImportMode::Skip
        );

        $this->assertCount(1, $report->getFailures());
        $this->assertStringContainsString('coupon_ticket', $report->getFailures()[0]['outcome']->getMessage());
    }

    public function testCachesAreInvalidatedOnceWhenSomethingWasWritten(): void
    {
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $cacheTypeList->expects($this->once())
            ->method('invalidate')
            ->with(['block_html', 'layout', 'full_page']);

        $this->engine([$this->porter('cms_block')], $cacheTypeList)->apply(
            $this->bundleOf([new Entry('cms_block', 'footer-links', [])]),
            ImportMode::Skip
        );
    }

    public function testCachesAreLeftAloneWhenEveryEntryWasSkipped(): void
    {
        // The second and every later deploy. Flushing the storefront's cache because a bundle
        // confirmed that nothing changed is a self-inflicted outage.
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $cacheTypeList->expects($this->never())->method('invalidate');

        $this->engine([$this->porter('cms_block', existing: true)], $cacheTypeList)->apply(
            $this->bundleOf([new Entry('cms_block', 'footer-links', [])]),
            ImportMode::Skip
        );
    }

    public function testADryRunPredictsACreateForSomethingThatIsNotHere(): void
    {
        $report = $this->engine([$this->porter('cms_block')])->preview(
            $this->bundleOf([new Entry('cms_block', 'footer-links', [])]),
            ImportMode::Skip
        );

        $this->assertSame(Outcome::STATUS_CREATED, $report->getRows()[0]['outcome']->getStatus());
    }

    public function testADryRunPredictsASkipForSomethingAlreadyHere(): void
    {
        $report = $this->engine([$this->porter('cms_block', existing: true)])->preview(
            $this->bundleOf([new Entry('cms_block', 'footer-links', [])]),
            ImportMode::Skip
        );

        $this->assertSame(Outcome::STATUS_SKIPPED, $report->getRows()[0]['outcome']->getStatus());
    }

    public function testADryRunInReplaceModePredictsAnOverwrite(): void
    {
        $report = $this->engine([$this->porter('cms_block', existing: true)])->preview(
            $this->bundleOf([new Entry('cms_block', 'footer-links', [])]),
            ImportMode::Replace
        );

        $this->assertSame(Outcome::STATUS_REPLACED, $report->getRows()[0]['outcome']->getStatus());
    }

    public function testADryRunNeverCallsApply(): void
    {
        $log = new ArrayObject();

        $this->engine([$this->porter('cms_block', log: $log)])->preview(
            $this->bundleOf([new Entry('cms_block', 'footer-links', [])]),
            ImportMode::Replace
        );

        $this->assertSame([], $log->getArrayCopy());
    }

    public function testADryRunDoesNotTouchTheCache(): void
    {
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $cacheTypeList->expects($this->never())->method('invalidate');

        $this->engine([$this->porter('cms_block')], $cacheTypeList)->preview(
            $this->bundleOf([new Entry('cms_block', 'footer-links', [])]),
            ImportMode::Skip
        );
    }

    /**
     * @param PorterInterface[] $porters
     */
    private function engine(array $porters, ?TypeListInterface $cacheTypeList = null): ImportEngine
    {
        return new ImportEngine(
            new PorterPool($porters),
            $cacheTypeList ?? $this->createMock(TypeListInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param EntryInterface[] $entries
     */
    private function bundleOf(array $entries): Bundle
    {
        return new Bundle(Manifest::forCapture([], []), $entries);
    }

    /**
     * @param array<string, Throwable> $failures identifier => what `apply()` throws for it
     * @param string[] $dependencies
     * @param ArrayObject|null $log Collects "<porter>/<identifier>" in the order apply() ran.
     */
    private function porter(
        string $code,
        array $failures = [],
        array $dependencies = [],
        bool $existing = false,
        ?ArrayObject $log = null
    ): PorterInterface {
        return new class ($code, $failures, $dependencies, $existing, $log ?? new ArrayObject()) implements
            PorterInterface {
            /**
             * @param array<string, Throwable> $failures
             * @param string[] $dependencies
             */
            public function __construct(
                private string $code,
                private array $failures,
                private array $dependencies,
                private bool $existing,
                private ArrayObject $log
            ) {
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
                return $this->existing;
            }

            public function apply(EntryInterface $entry, ImportMode $mode): Outcome
            {
                if (isset($this->failures[$entry->getIdentifier()])) {
                    throw $this->failures[$entry->getIdentifier()];
                }

                $this->log->append($this->code . '/' . $entry->getIdentifier());

                return $this->existing ? Outcome::skipped() : Outcome::created();
            }
        };
    }
}
