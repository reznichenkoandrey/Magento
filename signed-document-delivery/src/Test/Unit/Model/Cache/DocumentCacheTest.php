<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Cache;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\SignedDocumentDelivery\Model\Cache\DocumentCache;

class DocumentCacheTest extends TestCase
{
    private const KEY = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';
    private const EXPECTED_PATH = 'scr1be/signed-documents/ab/cd/'
        . 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789.pdf';

    private WriteInterface&MockObject $directory;
    private Random&MockObject $random;
    private DocumentCache $cache;

    protected function setUp(): void
    {
        $this->directory = $this->createMock(WriteInterface::class);
        $this->random = $this->createMock(Random::class);
        $this->random->method('getRandomString')->willReturn('inflight0001');

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::TMP)->willReturn($this->directory);

        $this->cache = new DocumentCache($filesystem, $this->random);
    }

    public function testTheCacheLivesUnderVarTmp(): void
    {
        // The controller hands this to FileFactory, so it has to be the same directory code the
        // relative paths are resolved against — and var/tmp is outside the document root, which is
        // why a cached PDF is unreachable except through the controller.
        $this->assertSame(DirectoryList::TMP, $this->cache->directoryCode());
    }

    public function testThePathIsShardedTwoLevelsDeepFromTheFrontOfTheKey(): void
    {
        $this->assertSame(self::EXPECTED_PATH, $this->cache->relativePath(self::KEY));
    }

    public function testTwoKeysSharingAPrefixShareADirectoryAndNotAFile(): void
    {
        $sibling = 'abcd' . str_repeat('9', 60);

        $this->assertSame(
            dirname($this->cache->relativePath(self::KEY)),
            dirname($this->cache->relativePath($sibling))
        );
        $this->assertNotSame($this->cache->relativePath(self::KEY), $this->cache->relativePath($sibling));
    }

    public function testAHitIsAFileAtTheKeyPath(): void
    {
        $this->directory->expects($this->once())
            ->method('isFile')
            ->with(self::EXPECTED_PATH)
            ->willReturn(true);

        $this->assertTrue($this->cache->has(self::KEY));
    }

    public function testAMissIsAbsence(): void
    {
        $this->directory->method('isFile')->willReturn(false);

        $this->assertFalse($this->cache->has(self::KEY));
    }

    /**
     * The whole reason this class exists rather than a `writeFile()` call at the call site: a
     * reader must never see a half-written PDF, so the bytes land under a temporary name and are
     * moved into place with a single rename.
     */
    public function testTheWriteIsAtomic(): void
    {
        $expectedInFlight = self::EXPECTED_PATH . '.inflight0001.part';

        $this->directory->expects($this->once())
            ->method('create')
            ->with('scr1be/signed-documents/ab/cd');

        $this->directory->expects($this->once())
            ->method('writeFile')
            ->with($expectedInFlight, '%PDF-1.4 bytes');

        $this->directory->expects($this->once())
            ->method('renameFile')
            ->with($expectedInFlight, self::EXPECTED_PATH);

        $this->cache->write(self::KEY, '%PDF-1.4 bytes');
    }

    public function testTheInFlightNameIsPerWriterSoTwoRacingRendersDoNotShareIt(): void
    {
        // Two processes rendering the same document at the same time is the ordinary case on a
        // cache miss. A fixed temporary name would have them writing into each other.
        $random = $this->createMock(Random::class);
        $random->method('getRandomString')->willReturnOnConsecutiveCalls('writerAAAAAA', 'writerBBBBBB');

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->directory);
        $cache = new DocumentCache($filesystem, $random);

        $names = [];
        $this->directory->method('writeFile')
            ->willReturnCallback(function (string $path) use (&$names): int {
                $names[] = $path;

                return 1;
            });

        $cache->write(self::KEY, 'first');
        $cache->write(self::KEY, 'second');

        $this->assertCount(2, array_unique($names));
        foreach ($names as $name) {
            $this->assertStringEndsWith(DocumentCache::IN_FLIGHT_SUFFIX, $name);
            $this->assertNotSame(self::EXPECTED_PATH, $name);
        }
    }

    public function testTheInFlightSuffixIsRecognisableToTheSweeper(): void
    {
        // CacheSweeper::isSweepable() matches on these two, so a rename of either constant without
        // the other would leave abandoned `.part` files behind forever.
        $this->assertSame('pdf', DocumentCache::EXTENSION);
        $this->assertSame('.part', DocumentCache::IN_FLIGHT_SUFFIX);
    }
}
