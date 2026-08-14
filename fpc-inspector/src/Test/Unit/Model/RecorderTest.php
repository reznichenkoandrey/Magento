<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\FpcInspector\Model\RecordBuilder;
use Scr1be\FpcInspector\Model\Recorder;

class RecorderTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->recorder = new Recorder($this->logger);
    }

    public function testAVaryLineNamesTheKeysThatFragmentTheCache(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'vary 1a2b3c4d on /gear/bags.html from customer_group, product_list_limit',
                $this->isType('array')
            );

        $this->recorder->record([
            'channel' => RecordBuilder::CHANNEL_VARY,
            'uri' => '/gear/bags.html',
            'vary' => '1a2b3c4d5e6f7890',
            'contributors' => [['key' => 'customer_group'], ['key' => 'product_list_limit']],
        ]);
    }

    public function testAnUnvariedPageSaysSoInWords(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('context is empty'), $this->isType('array'));

        $this->recorder->record([
            'channel' => RecordBuilder::CHANNEL_VARY,
            'uri' => '/',
            'vary' => null,
            'contributors' => [],
        ]);
    }

    public function testANoCacheLineLeadsWithTheHeaderThatWasOverwritten(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'no-cache stamped on /gear/bags.html, replacing Cache-Control: public, max-age=86400, s-maxage=86400',
                $this->isType('array')
            );

        $this->recorder->record([
            'channel' => RecordBuilder::CHANNEL_NO_CACHE,
            'uri' => '/gear/bags.html',
            'will_cache' => ['cache_control' => 'public, max-age=86400, s-maxage=86400'],
        ]);
    }

    public function testANoCacheLineWithNothingToOverwriteSaysThatToo(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('(none set)'), $this->isType('array'));

        $this->recorder->record([
            'channel' => RecordBuilder::CHANNEL_NO_CACHE,
            'uri' => '/',
            'will_cache' => ['cache_control' => null],
        ]);
    }

    public function testTheWholeRecordTravelsAsStructuredContext(): void
    {
        $record = [
            'channel' => RecordBuilder::CHANNEL_VARY,
            'uri' => '/',
            'vary' => 'abc',
            'contributors' => [],
            'stack' => ['Foo::bar (file.php:1)'],
        ];

        $this->logger->expects($this->once())->method('info')->with($this->isType('string'), $record);

        $this->recorder->record($record);
    }

    public function testAFailureToRecordIsReportedRatherThanThrown(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('could not write'), $this->isType('array'));

        $this->recorder->failed(new \RuntimeException('disk full'));
    }

    public function testAFailureToReportAFailureIsSwallowed(): void
    {
        // The prime directive: an observer must never be the reason a storefront page breaks.
        $this->logger->method('error')->willThrowException(new \RuntimeException('log dir is read-only'));

        $this->recorder->failed(new \RuntimeException('disk full'));

        $this->addToAssertionCount(1);
    }
}
