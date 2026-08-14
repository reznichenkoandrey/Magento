<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\DerivativePath;

class DerivativePathTest extends TestCase
{
    private DerivativePath $paths;

    protected function setUp(): void
    {
        $this->paths = new DerivativePath();
    }

    public function testDerivativesAreKeyedByWidthUnderOneRoot(): void
    {
        $this->assertSame(
            'scr1be/media/768/wysiwyg/home/banner.jpg',
            $this->paths->forWidth('wysiwyg/home/banner.jpg', 768)
        );
    }

    public function testWebpExtensionIsAppendedRatherThanSubstituted(): void
    {
        // Substitution collapses banner.jpg and banner.png onto one banner.webp, and whichever
        // renders second serves the other one's pixels under the first one's URL.
        $this->assertSame(
            'scr1be/media/768/wysiwyg/banner.jpg.webp',
            $this->paths->webpForWidth('wysiwyg/banner.jpg', 768)
        );
        $this->assertNotSame(
            $this->paths->webpForWidth('wysiwyg/banner.jpg', 768),
            $this->paths->webpForWidth('wysiwyg/banner.png', 768)
        );
    }

    public function testSkipMarkersLiveOutsideTheWidthTree(): void
    {
        // A skip is a verdict on the source, not on a rung, so its path carries no width. The
        // leading dot on the directory keeps it from ever colliding with one.
        $this->assertSame(
            'scr1be/media/.webp-skip/wysiwyg/banner.jpg.webp.skip',
            $this->paths->webpSkipMarker('wysiwyg/banner.jpg')
        );
    }

    public function testEverythingWrittenSharesTheCacheRoot(): void
    {
        // One root is what makes the cache disposable: purging is a single recursive delete, and
        // nothing the module wrote survives it.
        $written = [
            $this->paths->forWidth('a/b.jpg', 320),
            $this->paths->webpForWidth('a/b.jpg', 320),
            $this->paths->webpSkipMarker('a/b.jpg'),
        ];

        foreach ($written as $path) {
            $this->assertStringStartsWith(DerivativePath::CACHE_ROOT . '/', $path);
        }
    }
}
