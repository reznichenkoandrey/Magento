<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\PathValidator;

class PathValidatorTest extends TestCase
{
    private PathValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PathValidator();
    }

    public function testAnOrdinaryWysiwygPathIsAccepted(): void
    {
        $this->assertSame('wysiwyg/home/banner.jpg', $this->validator->normalise('wysiwyg/home/banner.jpg'));
    }

    public function testALeadingSlashIsStrippedRatherThanRejected(): void
    {
        // Both spellings appear in wysiwyg content and both mean the same file; rejecting one of
        // them would make the module look broken for reasons no template author could see.
        $this->assertSame('wysiwyg/banner.jpg', $this->validator->normalise('/wysiwyg/banner.jpg'));
    }

    public function testTraversalSegmentsAreRejected(): void
    {
        $this->assertNull($this->validator->normalise('wysiwyg/../../app/etc/env.php.jpg'));
    }

    public function testASingleDotSegmentIsRejected(): void
    {
        $this->assertNull($this->validator->normalise('wysiwyg/./banner.jpg'));
    }

    public function testBackslashesAreRejectedRatherThanNormalised(): void
    {
        // Not a separator on the platforms Magento runs on, which is exactly why a traversal
        // segment can hide behind one and survive a check that only splits on forward slashes.
        $this->assertNull($this->validator->normalise('wysiwyg\\..\\..\\secret.jpg'));
    }

    public function testANullByteIsRejected(): void
    {
        // The OS truncates at the null while PHP still sees the whole string, so the extension
        // check would pass for a file opened under a completely different name.
        $this->assertNull($this->validator->normalise("wysiwyg/env.php\0.jpg"));
    }

    public function testDoubleSlashesAreRejected(): void
    {
        $this->assertNull($this->validator->normalise('wysiwyg//banner.jpg'));
    }

    public function testARemoteUrlIsRejected(): void
    {
        $this->assertNull($this->validator->normalise('https://example.com/banner.jpg'));
    }

    public function testUnsupportedExtensionsAreRejected(): void
    {
        $this->assertNull($this->validator->normalise('wysiwyg/logo.svg'));
        $this->assertNull($this->validator->normalise('wysiwyg/scan.tiff'));
        $this->assertNull($this->validator->normalise('wysiwyg/notes.txt'));
    }

    public function testExtensionMatchingIsCaseInsensitive(): void
    {
        $this->assertSame('wysiwyg/Banner.JPG', $this->validator->normalise('wysiwyg/Banner.JPG'));
    }

    public function testFilenameCaseIsPreserved(): void
    {
        // Media lives on case-sensitive filesystems in every environment that is not a developer
        // laptop; lower-casing the path here would produce derivatives of files that do not exist.
        $this->assertSame('wysiwyg/Hero-Banner.png', $this->validator->normalise('wysiwyg/Hero-Banner.png'));
    }

    public function testAPathInsideTheDerivativeCacheIsRejected(): void
    {
        // Deriving from a derivative compounds generation loss and grows a width segment per pass,
        // so the cache root is one-way by construction.
        $this->assertNull($this->validator->normalise('scr1be/media/768/wysiwyg/banner.jpg'));
    }

    public function testAnEmptyPathIsRejected(): void
    {
        $this->assertNull($this->validator->normalise(''));
    }

    public function testAPathWithNoExtensionIsRejected(): void
    {
        $this->assertNull($this->validator->normalise('wysiwyg/banner'));
    }

    public function testExtensionOfLowerCasesAndDropsTheDot(): void
    {
        $this->assertSame('jpeg', $this->validator->extensionOf('a/b/c.JPEG'));
        $this->assertSame('', $this->validator->extensionOf('a/b/c'));
    }
}
