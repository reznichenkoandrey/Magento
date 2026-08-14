<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\ViewModel\SwitcherUrlProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSwitcher\Model\StoreListProvider;

class StoreListProviderTest extends TestCase
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
     * @var SwitcherUrlProvider&MockObject
     */
    private $switcherUrlProvider;

    private StoreListProvider $provider;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->switcherUrlProvider = $this->createMock(SwitcherUrlProvider::class);

        $this->provider = new StoreListProvider(
            $this->storeManager,
            $this->scopeConfig,
            $this->switcherUrlProvider
        );
    }

    public function testInactiveStoresAreNotOffered(): void
    {
        $this->storeManager->method('getStores')->willReturn([
            $this->makeStore(1, 'English', true),
            $this->makeStore(2, 'Deutsch', false),
        ]);
        $this->scopeConfig->method('getValue')->willReturn('en_US');

        $options = $this->provider->getOptions(false);

        self::assertCount(1, $options);
        self::assertSame(1, $options[0]->getStoreId());
    }

    public function testOptionsAreSortedByName(): void
    {
        $this->storeManager->method('getStores')->willReturn([
            $this->makeStore(1, 'Nederlands', true),
            $this->makeStore(2, 'Deutsch', true),
            $this->makeStore(3, 'English', true),
        ]);
        $this->scopeConfig->method('getValue')->willReturn('en_US');

        $names = array_map(static fn ($o) => $o->getName(), $this->provider->getOptions(false));

        self::assertSame(['Deutsch', 'English', 'Nederlands'], $names);
    }

    public function testTheDrawerVariantNeverAsksForARequestSpecificUrl(): void
    {
        // This is the guarantee that makes DrawerPayload cacheable. If the provider called the
        // core switcher URL provider here, the payload would embed the current request and one
        // cached copy would misdirect every other page.
        $this->storeManager->method('getStores')->willReturn([$this->makeStore(1, 'English', true)]);
        $this->scopeConfig->method('getValue')->willReturn('en_US');

        $this->switcherUrlProvider->expects(self::never())->method('getTargetStoreRedirectUrl');

        $options = $this->provider->getOptions(false);

        self::assertNull($options[0]->getRedirectUrl());
    }

    public function testTheListIsBuiltOncePerVariant(): void
    {
        // Four blocks ask for the same list in one render. Rebuilding it for each of them would
        // base64-encode the current URL twice per store on every page of the site.
        $store = $this->makeStore(1, 'English', true);
        $this->storeManager->method('getStores')->willReturn([$store]);
        $this->scopeConfig->method('getValue')->willReturn('en_US');

        $this->switcherUrlProvider->expects(self::once())
            ->method('getTargetStoreRedirectUrl')
            ->willReturn('https://example.com/r');

        $this->provider->getOptions(true);
        $this->provider->getOptions(true);
        $this->provider->getOptions(false);
        $this->provider->getOptions(false);
    }

    public function testTheDesktopVariantCarriesCoresRedirectUrl(): void
    {
        $store = $this->makeStore(1, 'English', true);
        $this->storeManager->method('getStores')->willReturn([$store]);
        $this->scopeConfig->method('getValue')->willReturn('en_US');

        $this->switcherUrlProvider->expects(self::once())
            ->method('getTargetStoreRedirectUrl')
            ->with($store)
            ->willReturn('https://example.com/stores/store/redirect/');

        self::assertSame(
            'https://example.com/stores/store/redirect/',
            $this->provider->getOptions(true)[0]->getRedirectUrl()
        );
    }

    public function testTheLinkBaseUrlIsUsedSoStoreCodesSurvive(): void
    {
        // URL_TYPE_WEB would drop the `/de/` segment Store::_updatePathUseStoreView() adds to the
        // link URL, and every switcher option would then point at the wrong store's root.
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('default');
        $store->method('getName')->willReturn('English');
        $store->method('isActive')->willReturn(true);
        $store->expects(self::once())
            ->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_LINK)
            ->willReturn('https://example.com/de/');

        $this->storeManager->method('getStores')->willReturn([$store]);
        $this->scopeConfig->method('getValue')->willReturn('de_DE');

        self::assertSame('https://example.com/de/', $this->provider->getOptions(false)[0]->getBaseUrl());
    }

    /**
     * @return Store&MockObject
     */
    private function makeStore(int $storeId, string $name, bool $active)
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($storeId);
        $store->method('getCode')->willReturn(strtolower($name));
        $store->method('getName')->willReturn($name);
        $store->method('isActive')->willReturn($active);
        $store->method('getBaseUrl')->willReturn('https://example.com/');

        return $store;
    }
}
