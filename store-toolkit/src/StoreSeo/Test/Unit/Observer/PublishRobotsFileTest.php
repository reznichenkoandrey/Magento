<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Robots\Publisher;
use Scr1be\StoreSeo\Observer\PublishRobotsFile;

class PublishRobotsFileTest extends TestCase
{
    /**
     * @var WebsiteRepositoryInterface&MockObject
     */
    private $websiteRepository;

    /**
     * @var Publisher&MockObject
     */
    private $publisher;

    private PublishRobotsFile $observer;

    protected function setUp(): void
    {
        $this->websiteRepository = $this->createMock(WebsiteRepositoryInterface::class);
        $this->publisher = $this->createMock(Publisher::class);
        $this->observer = new PublishRobotsFile($this->websiteRepository, $this->publisher);
    }

    public function testDefaultScopeSaveRepublishesEveryWebsite(): void
    {
        $this->publisher->expects(self::once())->method('publishAll');
        $this->publisher->expects(self::never())->method('publishWebsite');

        $this->observer->execute($this->observerWith(''));
    }

    public function testWebsiteScopeSaveRepublishesOnlyThatWebsite(): void
    {
        $website = $this->createMock(Website::class);
        $this->websiteRepository->expects(self::once())->method('getById')->with(2)->willReturn($website);

        $this->publisher->expects(self::once())->method('publishWebsite')->with($website);
        $this->publisher->expects(self::never())->method('publishAll');

        $this->observer->execute($this->observerWith('2'));
    }

    public function testANonNumericScopeValueIsResolvedAsAWebsiteCode(): void
    {
        $website = $this->createMock(Website::class);
        $this->websiteRepository->expects(self::once())->method('get')->with('second')->willReturn($website);

        $this->publisher->expects(self::once())->method('publishWebsite')->with($website);

        $this->observer->execute($this->observerWith('second'));
    }

    public function testAWebsiteThatCannotBeResolvedFallsBackToRepublishingAll(): void
    {
        $this->websiteRepository->method('getById')->willThrowException(new NoSuchEntityException(__('Gone.')));

        $this->publisher->expects(self::once())->method('publishAll');

        $this->observer->execute($this->observerWith('99'));
    }

    private function observerWith(string $website): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->with('website')->willReturn($website);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
