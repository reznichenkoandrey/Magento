<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model\Hreflang;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Entity\EntityContext;
use Scr1be\StoreSeo\Model\Entity\StoreAvailability\AvailabilityCheckerInterface;
use Scr1be\StoreSeo\Model\Entity\StoreAvailability\CheckerPool;
use Scr1be\StoreSeo\Model\Entity\StoreUrlResolver;
use Scr1be\StoreSeo\Model\Hreflang\AlternateResolver;
use Scr1be\StoreSeo\Model\Hreflang\LocaleFormatter;

class AlternateResolverTest extends TestCase
{
    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @var CheckerPool&MockObject
     */
    private $checkerPool;

    /**
     * @var StoreUrlResolver&MockObject
     */
    private $urlResolver;

    /**
     * @var AvailabilityCheckerInterface&MockObject
     */
    private $checker;

    private AlternateResolver $resolver;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->checkerPool = $this->createMock(CheckerPool::class);
        $this->urlResolver = $this->createMock(StoreUrlResolver::class);
        $this->checker = $this->createMock(AvailabilityCheckerInterface::class);

        $this->checkerPool->method('get')->willReturn($this->checker);

        $this->resolver = new AlternateResolver(
            $this->storeManager,
            $this->scopeConfig,
            $this->checkerPool,
            $this->urlResolver,
            new LocaleFormatter()
        );
    }

    public function testBuildsOneLinkPerAvailableStore(): void
    {
        $this->givenStores([1 => 'en_US', 2 => 'de_DE']);
        $this->checker->method('isAvailable')->willReturn(true);
        $this->urlResolver->method('resolve')->willReturnOnConsecutiveCalls(
            'https://example.com/p.html',
            'https://example.com/de/p.html'
        );

        $links = $this->resolver->resolve(new EntityContext('product', 42));

        self::assertCount(2, $links);
        self::assertSame('en-US', $links[0]->getHreflang());
        self::assertSame('https://example.com/de/p.html', $links[1]->getHref());
    }

    public function testDropsStoresWhereTheEntityIsUnavailable(): void
    {
        $this->givenStores([1 => 'en_US', 2 => 'de_DE', 3 => 'fr_FR']);
        $this->checker->method('isAvailable')->willReturnMap([
            [42, 1, true],
            [42, 2, false],
            [42, 3, true],
        ]);
        $this->urlResolver->method('resolve')->willReturnOnConsecutiveCalls(
            'https://example.com/p.html',
            'https://example.fr/p.html'
        );

        $links = $this->resolver->resolve(new EntityContext('product', 42));

        self::assertCount(2, $links);
        self::assertSame(['en-US', 'fr-FR'], array_map(static fn ($l) => $l->getHreflang(), $links));
    }

    public function testDropsStoresWithNoLiveUrl(): void
    {
        // A store the product is assigned to but whose url_rewrite row was never generated: the
        // availability gate says yes and the URL gate has to say no, or the group advertises a 404.
        $this->givenStores([1 => 'en_US', 2 => 'de_DE', 3 => 'fr_FR']);
        $this->checker->method('isAvailable')->willReturn(true);
        $this->urlResolver->method('resolve')->willReturnOnConsecutiveCalls(
            'https://example.com/p.html',
            null,
            'https://example.fr/p.html'
        );

        $links = $this->resolver->resolve(new EntityContext('product', 42));

        self::assertCount(2, $links);
        self::assertSame(['en-US', 'fr-FR'], array_map(static fn ($l) => $l->getHreflang(), $links));
    }

    public function testSuppressesAGroupThatWouldCarryASingleLocale(): void
    {
        $this->givenStores([1 => 'en_US', 2 => 'de_DE']);
        $this->checker->method('isAvailable')->willReturnMap([[42, 1, true], [42, 2, false]]);
        $this->urlResolver->method('resolve')->willReturn('https://example.com/p.html');

        self::assertSame([], $this->resolver->resolve(new EntityContext('product', 42)));
    }

    public function testFirstStoreWinsWhenTwoShareALocale(): void
    {
        // Two store views on one locale is legitimate; two identical hreflang values in one group
        // is not, so the second store is dropped and the group falls under the minimum.
        $this->givenStores([1 => 'en_US', 2 => 'en_US']);
        $this->checker->method('isAvailable')->willReturn(true);
        $this->urlResolver->method('resolve')->willReturn('https://example.com/p.html');

        self::assertSame([], $this->resolver->resolve(new EntityContext('product', 42)));
    }

    public function testInactiveStoresAreNeverAdvertised(): void
    {
        $this->storeManager->method('getStores')->willReturn([
            $this->makeStore(1, false),
            $this->makeStore(2, true),
            $this->makeStore(3, true),
        ]);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['general/locale/code', 'store', 1, 'en_US'],
            ['general/locale/code', 'store', 2, 'de_DE'],
            ['general/locale/code', 'store', 3, 'fr_FR'],
        ]);
        $this->checker->method('isAvailable')->willReturn(true);
        $this->urlResolver->method('resolve')->willReturnOnConsecutiveCalls(
            'https://example.com/de/p.html',
            'https://example.fr/p.html'
        );

        $links = $this->resolver->resolve(new EntityContext('product', 42));

        self::assertSame([2, 3], array_map(static fn ($l) => $l->getStoreId(), $links));
    }

    public function testUnknownEntityTypeAdvertisesNothing(): void
    {
        $pool = $this->createMock(CheckerPool::class);
        $pool->method('get')->willReturn(null);

        $resolver = new AlternateResolver(
            $this->storeManager,
            $this->scopeConfig,
            $pool,
            $this->urlResolver,
            new LocaleFormatter()
        );

        self::assertSame([], $resolver->resolve(new EntityContext('widget', 7)));
    }

    /**
     * @param array<int, string> $localesByStoreId
     */
    private function givenStores(array $localesByStoreId): void
    {
        $stores = [];
        $localeMap = [];

        foreach ($localesByStoreId as $storeId => $locale) {
            $stores[] = $this->makeStore($storeId, true);
            $localeMap[] = ['general/locale/code', 'store', $storeId, $locale];
        }

        $this->storeManager->method('getStores')->willReturn($stores);
        $this->scopeConfig->method('getValue')->willReturnMap($localeMap);
    }

    /**
     * @return Store&MockObject
     */
    private function makeStore(int $storeId, bool $active)
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($storeId);
        $store->method('isActive')->willReturn($active);

        return $store;
    }
}
