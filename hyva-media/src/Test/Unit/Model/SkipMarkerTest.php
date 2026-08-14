<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\DerivativePath;
use Scr1be\HyvaMedia\Model\MediaStorage;
use Scr1be\HyvaMedia\Model\SkipMarker;

class SkipMarkerTest extends TestCase
{
    private MediaStorage&MockObject $storage;
    private SkipMarker $marker;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(MediaStorage::class);
        $this->marker = new SkipMarker($this->storage, new DerivativePath());
    }

    public function testNoMarkerMeansNotSkipped(): void
    {
        $this->storage->method('mtime')->willReturn(null);

        $this->assertFalse($this->marker->isSet('wysiwyg/a.jpg', 1000));
    }

    public function testAMarkerNewerThanTheSourceSkips(): void
    {
        $this->storage->method('mtime')->willReturn(1500);

        $this->assertTrue($this->marker->isSet('wysiwyg/a.jpg', 1000));
    }

    public function testAMarkerOlderThanTheSourceIsIgnored(): void
    {
        // Re-uploading the image is the whole invalidation story: a verdict recorded against bytes
        // that no longer exist must not outlive them, or a fixed source stays permanently skipped.
        $this->storage->method('mtime')->willReturn(900);

        $this->assertFalse($this->marker->isSet('wysiwyg/a.jpg', 1000));
    }

    public function testAMarkerWrittenInTheSameSecondAsTheSourceStillSkips(): void
    {
        // mtime has one-second granularity, so "written just now, for the source as it is now" and
        // "written a moment before the source changed" are indistinguishable. Erring toward the
        // skip costs at most one stale verdict, correctable by touching the file; erring the other
        // way reinstates the retry loop the marker exists to end.
        $this->storage->method('mtime')->willReturn(1000);

        $this->assertTrue($this->marker->isSet('wysiwyg/a.jpg', 1000));
    }

    public function testSettingAMarkerTouchesTheSourceKeyedPath(): void
    {
        $this->storage->expects($this->once())
            ->method('touch')
            ->with('scr1be/media/.webp-skip/wysiwyg/a.jpg.webp.skip')
            ->willReturn(true);

        $this->marker->set('wysiwyg/a.jpg');
    }

    public function testTheMarkerIsReadFromTheSameSourceKeyedPathItIsWrittenTo(): void
    {
        // Trivially true in one class and trivially broken the moment either side grows a width
        // segment — which is exactly the change that would make the marker silently stop working.
        $this->storage->expects($this->once())
            ->method('mtime')
            ->with('scr1be/media/.webp-skip/wysiwyg/a.jpg.webp.skip')
            ->willReturn(null);

        $this->marker->isSet('wysiwyg/a.jpg', 1000);
    }
}
