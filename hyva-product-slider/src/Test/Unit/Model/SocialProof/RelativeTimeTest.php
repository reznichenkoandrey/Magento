<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model\SocialProof;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Model\SocialProof\RelativeTime;

class RelativeTimeTest extends TestCase
{
    private RelativeTime $relativeTime;

    protected function setUp(): void
    {
        $this->relativeTime = new RelativeTime();
    }

    public function testAPurchaseThatJustHappenedIsNotZeroMinutes(): void
    {
        $this->assertSame('moments', $this->relativeTime->format(0));
        $this->assertSame('moments', $this->relativeTime->format(59));
    }

    public function testAClockSkewIntoTheFutureReadsAsMomentsRatherThanAsSomethingAbsurd(): void
    {
        $this->assertSame('moments', $this->relativeTime->format(-500));
    }

    public function testOneMinuteIsSingular(): void
    {
        $this->assertSame('a minute', $this->relativeTime->format(60));
        $this->assertSame('a minute', $this->relativeTime->format(119));
    }

    public function testMinutesAreTruncatedRatherThanRounded(): void
    {
        // 3599 seconds is 59 minutes and 59 seconds. Rounding would print "60 minutes", which is the
        // one number this branch must never produce.
        $this->assertSame('59 minutes', $this->relativeTime->format(3599));
    }

    public function testOneHourIsSingular(): void
    {
        $this->assertSame('an hour', $this->relativeTime->format(3600));
    }

    public function testHoursCoverTheRestOfTheDay(): void
    {
        $this->assertSame('23 hours', $this->relativeTime->format(86399));
    }

    public function testOneDayIsSingular(): void
    {
        $this->assertSame('a day', $this->relativeTime->format(86400));
    }

    public function testDaysAreTheLastUnit(): void
    {
        $this->assertSame('30 days', $this->relativeTime->format(86400 * 30));
    }
}
