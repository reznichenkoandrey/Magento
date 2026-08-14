<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\ReadInterface as FileReadInterface;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\HyvaMedia\Model\MediaStorage;

/**
 * The seam where this module meets Magento's filesystem contract, and therefore the place where a
 * change in that contract would surface. Everything above it is written against null and false, so
 * an exception escaping this class would reach a template.
 */
class MediaStorageTest extends TestCase
{
    private Filesystem&MockObject $filesystem;
    private ReadInterface&MockObject $readDirectory;
    private WriteInterface&MockObject $writeDirectory;
    private MediaStorage $storage;

    /** @var string[] */
    private array $readCodes = [];

    /** @var string[] */
    private array $writeCodes = [];

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->readDirectory = $this->createMock(ReadInterface::class);
        $this->writeDirectory = $this->createMock(WriteInterface::class);

        // Recorded through a callback rather than asserted with expects(), because a second
        // ->method() on the same mock would register a matcher the first one never lets run.
        $this->filesystem->method('getDirectoryRead')->willReturnCallback(
            function (string $code) {
                $this->readCodes[] = $code;

                return $this->readDirectory;
            }
        );
        $this->filesystem->method('getDirectoryWrite')->willReturnCallback(
            function (string $code) {
                $this->writeCodes[] = $code;

                return $this->writeDirectory;
            }
        );

        $this->storage = new MediaStorage($this->filesystem, $this->createMock(LoggerInterface::class));
    }

    public function testStatIsNarrowedToMtimeAndSize(): void
    {
        $this->readDirectory->method('stat')->willReturn([
            'mtime' => 1700000000,
            'size' => 245123,
            'ino' => 42,
            'mode' => 33188,
        ]);

        $this->assertSame(['mtime' => 1700000000, 'size' => 245123], $this->storage->stat('wysiwyg/a.jpg'));
    }

    public function testAMissingFileIsNullRatherThanAnException(): void
    {
        // Directory\Read::stat() throws for a file that is simply not there yet, which for a cache
        // is the single most common question asked of it.
        $this->readDirectory->method('stat')->willThrowException(new FileSystemException(new Phrase('nope')));

        $this->assertNull($this->storage->stat('wysiwyg/a.jpg'));
        $this->assertNull($this->storage->mtime('wysiwyg/a.jpg'));
    }

    public function testReadHeadTakesOnlyTheRequestedBytesAndClosesTheHandle(): void
    {
        $file = $this->createMock(FileReadInterface::class);
        $file->expects($this->once())->method('read')->with(1024)->willReturn('bytes');
        $file->expects($this->once())->method('close');

        $this->readDirectory->method('openFile')->willReturn($file);

        $this->assertSame('bytes', $this->storage->readHead('wysiwyg/a.jpg', 1024));
    }

    public function testReadHeadClosesTheHandleEvenWhenTheReadFails(): void
    {
        $file = $this->createMock(FileReadInterface::class);
        $file->method('read')->willThrowException(new FileSystemException(new Phrase('io')));
        $file->expects($this->once())->method('close');

        $this->readDirectory->method('openFile')->willReturn($file);

        $this->assertNull($this->storage->readHead('wysiwyg/a.jpg', 1024));
    }

    public function testAFailingCloseDoesNotDiscardBytesAlreadyRead(): void
    {
        // The handle has nothing left to tell us at that point, and the header is already in hand.
        $file = $this->createMock(FileReadInterface::class);
        $file->method('read')->willReturn('header');
        $file->method('close')->willThrowException(new FileSystemException(new Phrase('io')));

        $this->readDirectory->method('openFile')->willReturn($file);

        $this->assertSame('header', $this->storage->readHead('wysiwyg/a.jpg', 64));
    }

    public function testAnUnopenableFileIsNull(): void
    {
        $this->readDirectory->method('openFile')
            ->willThrowException(new FileSystemException(new Phrase('missing')));

        $this->assertNull($this->storage->readHead('wysiwyg/a.jpg', 64));
    }

    public function testAnEmptyReadIsNull(): void
    {
        // An empty string parses as "no recognised format" downstream anyway; collapsing it here
        // keeps the probe from having to distinguish empty from absent.
        $file = $this->createMock(FileReadInterface::class);
        $file->method('read')->willReturn('');

        $this->readDirectory->method('openFile')->willReturn($file);

        $this->assertNull($this->storage->readHead('wysiwyg/a.jpg', 64));
    }

    public function testWritingUsesTheMediaWriteDirectory(): void
    {
        $this->writeDirectory->expects($this->once())
            ->method('writeFile')
            ->with('scr1be/media/768/a.jpg', 'bytes')
            ->willReturn(5);

        $this->assertTrue($this->storage->write('scr1be/media/768/a.jpg', 'bytes'));
        $this->assertSame([DirectoryList::MEDIA], $this->writeCodes);
    }

    public function testAnUnwritableMediaVolumeDegradesToFalse(): void
    {
        // A full or read-only media volume must cost the page its derivatives, never a 500.
        $this->writeDirectory->method('writeFile')
            ->willThrowException(new FileSystemException(new Phrase('read-only')));

        $this->assertFalse($this->storage->write('scr1be/media/768/a.jpg', 'bytes'));
    }

    public function testTouchIsUsedForZeroByteMarkers(): void
    {
        $this->writeDirectory->expects($this->once())
            ->method('touch')
            ->with('scr1be/media/.webp-skip/a.jpg.webp.skip')
            ->willReturn(true);

        $this->assertTrue($this->storage->touch('scr1be/media/.webp-skip/a.jpg.webp.skip'));
    }

    public function testTheDirectoryIsResolvedOncePerKind(): void
    {
        // The media directory is where the remote-storage preference bites; resolving it per call
        // would put a driver lookup on the hot path of every rung of every image on the page.
        $this->readDirectory->method('stat')->willReturn(['mtime' => 1, 'size' => 2]);

        $this->storage->stat('a.jpg');
        $this->storage->stat('b.jpg');
        $this->storage->mtime('c.jpg');

        $this->assertSame([DirectoryList::MEDIA], $this->readCodes);
    }
}
