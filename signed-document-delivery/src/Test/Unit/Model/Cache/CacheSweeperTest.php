<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Cache;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Model\Cache\CacheSweeper;
use Scr1be\SignedDocumentDelivery\Model\Cache\DocumentCache;

class CacheSweeperTest extends TestCase
{
    private const NOW = 1_775_000_000;
    private const MAX_AGE = 3600;
    private const ROOT = '/var/www/var/tmp/scr1be/signed-documents';

    private WriteInterface&MockObject $directory;
    private DriverInterface&MockObject $driver;
    private LoggerInterface&MockObject $logger;
    private CacheSweeper $sweeper;

    protected function setUp(): void
    {
        $this->driver = $this->createMock(DriverInterface::class);

        $this->directory = $this->createMock(WriteInterface::class);
        $this->directory->method('getDriver')->willReturn($this->driver);
        $this->directory->method('getAbsolutePath')->willReturn(self::ROOT);

        $this->logger = $this->createMock(LoggerInterface::class);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->directory);

        $this->sweeper = new CacheSweeper($filesystem, $this->logger);
    }

    public function testANodeThatHasNeverRenderedAnythingIsNotAnError(): void
    {
        $this->directory->method('isExist')->willReturn(false);
        $this->driver->expects($this->never())->method('readDirectoryRecursively');

        $this->assertSame(0, $this->sweeper->sweep(self::MAX_AGE, self::NOW));
    }

    public function testExpiredFilesAreDeletedAndFreshOnesAreLeftAlone(): void
    {
        $stale = self::ROOT . '/ab/cd/stale.pdf';
        $fresh = self::ROOT . '/ab/cd/fresh.pdf';

        $this->listing([$stale, $fresh]);
        $this->mtimes([$stale => self::NOW - self::MAX_AGE - 1, $fresh => self::NOW - self::MAX_AGE + 1]);

        $this->driver->expects($this->once())->method('deleteFile')->with($stale);

        $this->assertSame(1, $this->sweeper->sweep(self::MAX_AGE, self::NOW));
    }

    public function testAFileExactlyAtTheCutoffIsSwept(): void
    {
        // The comparison is `mtime > cutoff` to keep, so the boundary second goes. Pinned because
        // flipping it is a one-character change nobody would notice.
        $borderline = self::ROOT . '/ab/cd/borderline.pdf';

        $this->listing([$borderline]);
        $this->mtimes([$borderline => self::NOW - self::MAX_AGE]);

        $this->assertSame(1, $this->sweeper->sweep(self::MAX_AGE, self::NOW));
    }

    public function testAbandonedInFlightWritesAreSweptToo(): void
    {
        // A `.part` file is the one thing in this directory that is not reproducible: its renderer
        // died before the rename, so nothing will ever move it into place.
        $abandoned = self::ROOT . '/ab/cd/something.pdf.writerAAAAAA' . DocumentCache::IN_FLIGHT_SUFFIX;

        $this->listing([$abandoned]);
        $this->mtimes([$abandoned => 0]);

        $this->driver->expects($this->once())->method('deleteFile')->with($abandoned);

        $this->assertSame(1, $this->sweeper->sweep(self::MAX_AGE, self::NOW));
    }

    /**
     * @dataProvider foreignEntries
     */
    public function testAnythingThatIsNotOursIsLeftWhereItIs(string $path): void
    {
        // readDirectoryRecursively() returns directories as well as files, and var/tmp is shared
        // with whatever else an operator has put there. A cron job that deletes by age alone would
        // eventually delete somebody's backup.
        $this->listing([$path]);
        $this->driver->expects($this->never())->method('stat');
        $this->driver->expects($this->never())->method('deleteFile');

        $this->assertSame(0, $this->sweeper->sweep(self::MAX_AGE, self::NOW));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function foreignEntries(): array
    {
        return [
            'a shard directory' => [self::ROOT . '/ab'],
            'somebody elses file' => [self::ROOT . '/notes.txt'],
            'a pdf-ish name that is not a pdf' => [self::ROOT . '/ab/cd/report.pdf.bak'],
        ];
    }

    public function testAFileThatDisappearsMidSweepDoesNotStopTheRest(): void
    {
        // A second sweep, a deploy or an operator between the listing and the unlink. Not worth
        // failing a cron run over.
        $vanished = self::ROOT . '/ab/cd/vanished.pdf';
        $survivor = self::ROOT . '/ab/cd/stale.pdf';

        $this->listing([$vanished, $survivor]);
        $this->driver->method('stat')->willReturnCallback(
            function (string $path) use ($vanished): array {
                if ($path === $vanished) {
                    throw new FileSystemException(new Phrase('gone'));
                }

                return ['mtime' => 0];
            }
        );

        $this->assertSame(1, $this->sweeper->sweep(self::MAX_AGE, self::NOW));
    }

    public function testAnUnreadableCacheDirectoryIsLoggedAndSweepsNothing(): void
    {
        $this->directory->method('isExist')->willReturn(true);
        $this->driver->method('readDirectoryRecursively')
            ->willThrowException(new FileSystemException(new Phrase('permission denied')));

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(0, $this->sweeper->sweep(self::MAX_AGE, self::NOW));
    }

    /**
     * @param string[] $paths
     */
    private function listing(array $paths): void
    {
        $this->directory->method('isExist')->willReturn(true);
        $this->driver->method('readDirectoryRecursively')->with(self::ROOT)->willReturn($paths);
    }

    /**
     * @param array<string, int> $mtimes
     */
    private function mtimes(array $mtimes): void
    {
        $this->driver->method('stat')->willReturnCallback(
            static fn (string $path): array => ['mtime' => $mtimes[$path] ?? PHP_INT_MAX]
        );
    }
}
