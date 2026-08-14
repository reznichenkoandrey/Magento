<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model\Robots;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\StoreSeo\Model\Robots\CacheInvalidator;
use Scr1be\StoreSeo\Model\Robots\Config;
use Scr1be\StoreSeo\Model\Robots\FileWriter;
use Scr1be\StoreSeo\Model\Robots\Publisher;
use Scr1be\StoreSeo\Model\Robots\Validator;

class PublisherTest extends TestCase
{
    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var Validator&MockObject
     */
    private $validator;

    /**
     * @var FileWriter&MockObject
     */
    private $fileWriter;

    /**
     * @var CacheInvalidator&MockObject
     */
    private $cacheInvalidator;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    private Publisher $publisher;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->validator = $this->createMock(Validator::class);
        $this->fileWriter = $this->createMock(FileWriter::class);
        $this->cacheInvalidator = $this->createMock(CacheInvalidator::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->publisher = new Publisher(
            $this->config,
            $this->validator,
            $this->fileWriter,
            $this->cacheInvalidator,
            $this->storeManager,
            $this->logger
        );
    }

    public function testWritesTheFileAndThenInvalidatesEveryStoreOfTheWebsite(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getContent')->willReturn("User-agent: *\nDisallow: /checkout/");
        $this->validator->method('validate')->willReturn([]);

        $this->fileWriter->expects(self::once())
            ->method('publish')
            ->with('base', "User-agent: *\nDisallow: /checkout/\n")
            ->willReturn('scr1be/robots/base.txt');

        // The tag is per store even though the content is per website, because that is the shape
        // Magento\Robots\Block\Data::getIdentities() emits.
        $this->cacheInvalidator->expects(self::once())->method('invalidate')->with([1, 2]);

        self::assertSame('scr1be/robots/base.txt', $this->publisher->publishWebsite($this->website()));
    }

    public function testNormalisesLineEndingsAndGuaranteesATrailingNewline(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getContent')->willReturn("User-agent: *\r\nDisallow: /a\r\n\r\n");
        $this->validator->method('validate')->willReturn([]);

        $this->fileWriter->expects(self::once())
            ->method('publish')
            ->with('base', "User-agent: *\nDisallow: /a\n");

        $this->publisher->publishWebsite($this->website());
    }

    public function testDisablingRemovesTheFileRatherThanEmptyingIt(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $this->fileWriter->expects(self::once())->method('remove')->with('base');
        $this->fileWriter->expects(self::never())->method('publish');
        $this->cacheInvalidator->expects(self::once())->method('invalidate');

        self::assertNull($this->publisher->publishWebsite($this->website()));
    }

    public function testInvalidStoredContentIsLoggedAndNotWritten(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getContent')->willReturn('nonsense');
        $this->validator->method('validate')->willReturn([__('Line 1 is broken.')]);

        $this->fileWriter->expects(self::never())->method('publish');
        // Nothing changed on disk, so nothing is purged: a needless full page cache flush across
        // every store of a website is not a free operation.
        $this->cacheInvalidator->expects(self::never())->method('invalidate');
        $this->logger->expects(self::once())->method('warning');

        self::assertNull($this->publisher->publishWebsite($this->website()));
    }

    public function testAFailedWriteDoesNotTakeTheConfigSaveDown(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getContent')->willReturn('User-agent: *');
        $this->validator->method('validate')->willReturn([]);
        $this->fileWriter->method('publish')->willThrowException(new LocalizedException(__('Read-only volume.')));

        $this->logger->expects(self::once())->method('error');

        self::assertNull($this->publisher->publishWebsite($this->website()));
    }

    public function testPublishAllSkipsWebsitesThatWroteNothing(): void
    {
        $enabled = $this->website('base', [1]);
        $disabled = $this->website('second', [3]);

        $this->storeManager->method('getWebsites')->willReturn([$enabled, $disabled]);
        $this->config->method('isEnabled')->willReturnMap([[1, true], [2, false]]);
        $this->config->method('getContent')->willReturn('User-agent: *');
        $this->validator->method('validate')->willReturn([]);
        $this->fileWriter->method('publish')->willReturn('scr1be/robots/base.txt');

        self::assertSame(['scr1be/robots/base.txt'], $this->publisher->publishAll());
    }

    /**
     * @param int[] $storeIds
     * @return Website&MockObject
     */
    private function website(string $code = 'base', array $storeIds = [1, 2])
    {
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn($code === 'base' ? 1 : 2);
        $website->method('getCode')->willReturn($code);
        $website->method('getStoreIds')->willReturn($storeIds);

        return $website;
    }
}
