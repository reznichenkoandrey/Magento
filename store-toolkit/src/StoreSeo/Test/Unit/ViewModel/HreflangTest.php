<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Config;
use Scr1be\StoreSeo\Model\Entity\EntityContext;
use Scr1be\StoreSeo\Model\Entity\RequestEntityResolver;
use Scr1be\StoreSeo\Model\Hreflang\AlternateLink;
use Scr1be\StoreSeo\Model\Hreflang\AlternateResolver;
use Scr1be\StoreSeo\Model\Hreflang\LocaleFormatter;
use Scr1be\StoreSeo\Model\Hreflang\XDefaultSelector;
use Scr1be\StoreSeo\ViewModel\Hreflang;

class HreflangTest extends TestCase
{
    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    /**
     * @var StoreRepositoryInterface&MockObject
     */
    private $storeRepository;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var RequestEntityResolver&MockObject
     */
    private $entityResolver;

    /**
     * @var AlternateResolver&MockObject
     */
    private $alternateResolver;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->entityResolver = $this->createMock(RequestEntityResolver::class);
        $this->alternateResolver = $this->createMock(AlternateResolver::class);

        $currentStore = $this->createMock(StoreInterface::class);
        $currentStore->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($currentStore);
    }

    public function testResolvesTheGroupOnceForBothGetters(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $this->entityResolver->method('resolve')->willReturn(new EntityContext('product', 42));
        $this->alternateResolver->expects(self::once())
            ->method('resolve')
            ->willReturn([
                new AlternateLink(1, 'en-US', 'https://example.com/p.html'),
                new AlternateLink(2, 'de-DE', 'https://example.com/de/p.html'),
            ]);

        $viewModel = $this->viewModel();

        self::assertCount(2, $viewModel->getAlternates());
        self::assertNotNull($viewModel->getXDefault());
    }

    public function testAPageThatIsNotAnEntityGetsNoGroup(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $this->entityResolver->method('resolve')->willReturn(null);
        $this->alternateResolver->expects(self::never())->method('resolve');

        self::assertSame([], $this->viewModel()->getAlternates());
    }

    public function testDisabledStoreShortCircuitsBeforeTheEntityLookup(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(false);
        $this->entityResolver->expects(self::never())->method('resolve');

        self::assertSame([], $this->viewModel()->getAlternates());
        self::assertNull($this->viewModel()->getXDefault());
    }

    public function testAnEmptyGroupHasNoXDefault(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $this->entityResolver->method('resolve')->willReturn(new EntityContext('product', 42));
        $this->alternateResolver->method('resolve')->willReturn([]);

        self::assertNull($this->viewModel()->getXDefault());
    }

    public function testAStaleXDefaultStoreCodeFallsThroughInsteadOfSuppressingXDefault(): void
    {
        // The nominated store was deleted after the setting was saved. The group still needs a
        // default, so the selector's later rungs decide instead.
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $this->config->method('getXDefaultStoreCode')->willReturn('deleted');
        $this->storeRepository->method('get')->willThrowException(new NoSuchEntityException(__('Gone.')));
        $this->entityResolver->method('resolve')->willReturn(new EntityContext('product', 42));
        $this->alternateResolver->method('resolve')->willReturn([
            new AlternateLink(2, 'de-DE', 'https://example.com/de/p.html'),
            new AlternateLink(3, 'fr-FR', 'https://example.fr/p.html'),
        ]);

        $xDefault = $this->viewModel()->getXDefault();

        self::assertNotNull($xDefault);
        self::assertSame('de-DE', $xDefault->getHreflang());
    }

    public function testNominatedPrimaryWinsWhenItIsPresent(): void
    {
        $primary = $this->createMock(StoreInterface::class);
        $primary->method('getId')->willReturn(3);

        $this->config->method('isHreflangEnabled')->willReturn(true);
        $this->config->method('getXDefaultStoreCode')->willReturn('fr');
        $this->storeRepository->method('get')->willReturn($primary);
        $this->scopeConfig->method('getValue')->willReturn('fr_FR');
        $this->entityResolver->method('resolve')->willReturn(new EntityContext('product', 42));
        $this->alternateResolver->method('resolve')->willReturn([
            new AlternateLink(2, 'de-DE', 'https://example.com/de/p.html'),
            new AlternateLink(3, 'fr-FR', 'https://example.fr/p.html'),
        ]);

        $xDefault = $this->viewModel()->getXDefault();

        self::assertNotNull($xDefault);
        self::assertSame('fr-FR', $xDefault->getHreflang());
    }

    private function viewModel(): Hreflang
    {
        return new Hreflang(
            $this->storeManager,
            $this->storeRepository,
            $this->scopeConfig,
            $this->config,
            $this->entityResolver,
            $this->alternateResolver,
            new XDefaultSelector(),
            new LocaleFormatter()
        );
    }
}
