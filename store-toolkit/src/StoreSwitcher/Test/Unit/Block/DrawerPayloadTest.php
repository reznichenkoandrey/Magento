<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Test\Unit\Block;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSwitcher\Block\DrawerPayload;
use Scr1be\StoreSwitcher\Model\FlagSprite;
use Scr1be\StoreSwitcher\Model\StoreListProvider;
use Scr1be\StoreSwitcher\Model\StoreOption;

/**
 * The cache contract, which is the whole reason this block exists separately from the desktop one.
 */
class DrawerPayloadTest extends TestCase
{
    /**
     * @var StoreListProvider&MockObject
     */
    private $storeListProvider;

    /**
     * @var HttpRequest&MockObject
     */
    private $request;

    /**
     * @var UrlInterface&MockObject
     */
    private $urlBuilder;

    protected function setUp(): void
    {
        $this->storeListProvider = $this->createMock(StoreListProvider::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
    }

    public function testTheCacheKeyCarriesTheStoreAndTheSchemeAndNothingElse(): void
    {
        $this->request->method('isSecure')->willReturn(true);

        $block = $this->block(2);
        $block->setNameInLayout('scr1be.store.switcher.drawer');

        self::assertSame(
            ['SCR1BE_STORE_SWITCHER_DRAWER', 'scr1be.store.switcher.drawer', '2', '1'],
            $block->getCacheKeyInfo()
        );
        // The namespace constant is deliberately not called CACHE_KEY_PREFIX: AbstractBlock
        // already declares a public constant under that name, and shadowing it privately is a
        // fatal error at class-load time rather than a failing assertion.
        self::assertSame('BLOCK_', $block::CACHE_KEY_PREFIX);
    }

    public function testTheCacheKeyIsNotAffectedByTheRequestUrl(): void
    {
        // The claim this block makes: one cache entry per store serves the whole catalogue. If any
        // request-derived value leaked into the key, that claim would quietly become false and the
        // block would simply be slower rather than broken, so nothing would ever notice.
        $this->request->method('isSecure')->willReturn(false);
        $this->request->expects(self::never())->method('getPathInfo');
        $this->request->expects(self::never())->method('getRequestUri');

        $block = $this->block(1);
        $block->setNameInLayout('drawer');

        $block->getCacheKeyInfo();
    }

    public function testTwoStoresMakeTheSwitcherWorthRendering(): void
    {
        $this->storeListProvider->method('getOptions')->willReturn([
            $this->option(1, 'default', 'en_US'),
            $this->option(2, 'de', 'de_DE'),
        ]);

        self::assertTrue($this->block(1)->isSwitchable());
    }

    public function testASingleStoreRendersNothing(): void
    {
        $this->storeListProvider->method('getOptions')->willReturn([$this->option(1, 'default', 'en_US')]);

        self::assertFalse($this->block(1)->isSwitchable());
    }

    public function testThePayloadCarriesNoUrlOfTheCurrentPage(): void
    {
        $this->storeListProvider->method('getOptions')->willReturn([
            $this->option(1, 'default', 'en_US'),
            $this->option(2, 'de', 'de_DE', 'https://example.com/de/'),
        ]);
        $this->storeListProvider->method('getCurrentStoreOption')->willReturn($this->option(1, 'default', 'en_US'));
        $this->urlBuilder->method('getUrl')->willReturn('https://example.com/stores/store/redirect/');

        $payload = json_decode($this->block(1)->getPayloadJson(), true);

        self::assertSame('default', $payload['currentCode']);
        self::assertSame('https://example.com/stores/store/redirect/', $payload['redirectUrl']);
        self::assertSame('___store', $payload['storeParam']);
        self::assertSame('___from_store', $payload['fromStoreParam']);
        self::assertSame('uenc', $payload['targetUrlParam']);
        self::assertCount(2, $payload['stores']);
        self::assertSame('https://example.com/de/', $payload['stores'][1]['baseUrl']);

        // Nothing in the payload is allowed to be a page address; only store roots.
        self::assertSame(
            ['code', 'name', 'locale', 'flag', 'baseUrl'],
            array_keys($payload['stores'][0])
        );
    }

    public function testTheRedirectEndpointIsBuiltForTheCurrentStore(): void
    {
        $this->storeListProvider->method('getOptions')->willReturn([]);
        $this->storeListProvider->method('getCurrentStoreOption')->willReturn(null);

        $this->urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('stores/store/redirect', [])
            ->willReturn('https://example.com/stores/store/redirect/');

        $this->block(1)->getPayloadJson();
    }

    private function option(int $id, string $code, string $locale, string $baseUrl = 'https://example.com/'): StoreOption
    {
        return new StoreOption($id, $code, ucfirst($code), $locale, $baseUrl);
    }

    private function block(int $currentStoreId): DrawerPayload
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($currentStoreId);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $context = $this->createMock(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder);

        return new DrawerPayload($context, $this->storeListProvider, new FlagSprite(), new JsonHexTag());
    }
}
