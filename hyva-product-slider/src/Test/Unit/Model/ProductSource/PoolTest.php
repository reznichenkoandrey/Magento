<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model\ProductSource;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Api\ProductSourceInterface;
use Scr1be\HyvaProductSlider\Model\ProductSource\Pool;

class PoolTest extends TestCase
{
    public function testAnUnavailableSourceIsNotOffered(): void
    {
        // Offering "Most Viewed" on a shop with Reports switched off is a dropdown entry whose only
        // possible outcome is an empty carousel.
        $pool = new Pool(['new' => $this->source(true), 'most_viewed' => $this->source(false)]);

        $this->assertSame(['new'], array_keys($pool->getAvailable()));
    }

    public function testFindReturnsNullForAnUnavailableSourceRatherThanThrowing(): void
    {
        // The storefront path: a slider whose source module was disabled after the slider was
        // created renders nothing, not a 500 on a category page.
        $pool = new Pool(['most_viewed' => $this->source(false)]);

        $this->assertNull($pool->find('most_viewed'));
    }

    public function testFindReturnsNullForAnUnknownCode(): void
    {
        $this->assertNull((new Pool([]))->find('telepathy'));
    }

    public function testGetThrowsForAnUnknownCodeBecauseTheAdminIsTheRightPersonToSeeIt(): void
    {
        $this->expectException(LocalizedException::class);

        (new Pool([]))->get('telepathy');
    }

    public function testGetReturnsAnUnavailableSourceSoThatAnExistingSliderStillValidates(): void
    {
        // The admin side must be able to load and re-save a slider whose source is temporarily
        // unavailable; hiding it from `get()` too would make the form unsavable.
        $source = $this->source(false);

        $this->assertSame($source, (new Pool(['most_viewed' => $source]))->get('most_viewed'));
    }

    public function testHasIgnoresAvailability(): void
    {
        $this->assertTrue((new Pool(['most_viewed' => $this->source(false)]))->has('most_viewed'));
    }

    public function testRegisteringSomethingThatIsNotASourceFailsLoudlyAtConstruction(): void
    {
        // A di.xml typo should surface as a compile-time error, not as a fatal on a storefront
        // request three weeks later.
        $this->expectException(\InvalidArgumentException::class);

        new Pool(['broken' => new \stdClass()]);
    }

    private function source(bool $available): ProductSourceInterface&MockObject
    {
        $source = $this->createMock(ProductSourceInterface::class);
        $source->method('isAvailable')->willReturn($available);

        return $source;
    }
}
