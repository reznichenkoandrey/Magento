<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Config;
use Scr1be\HyvaProductCard\Model\ListContext;

class ListContextTest extends TestCase
{
    private HttpRequest&MockObject $request;
    private Registry&MockObject $registry;
    private StoreManagerInterface&MockObject $storeManager;
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->registry = $this->createMock(Registry::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->config = $this->createMock(Config::class);

        // Store rather than StoreInterface: getCurrentCurrencyCode() is declared on the model, not
        // on the Api\Data interface the manager's return type names.
        $store = $this->createMock(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $this->storeManager->method('getStore')->willReturn($store);
    }

    public function testSearchPagesReportOneStableListIdentity(): void
    {
        $this->request->method('getFullActionName')->willReturn('catalogsearch_result_index');

        $this->assertSame(
            ['item_list_id' => 'search_results', 'item_list_name' => 'Search results'],
            $this->context()->get()
        );
    }

    public function testACategoryPageIsIdentifiedByItsCategoryId(): void
    {
        $this->request->method('getFullActionName')->willReturn('catalog_category_view');
        $this->registry->method('registry')->with('current_category')->willReturn($this->category(12, 'Bags'));

        $this->assertSame(
            ['item_list_id' => 'category_12', 'item_list_name' => 'Bags'],
            $this->context()->get()
        );
    }

    public function testPagesWithNoCategoryAndNoSearchStillGetAnIdentity(): void
    {
        // An empty item_list_id splits the funnel just as effectively as a wrong one.
        $this->request->method('getFullActionName')->willReturn('cms_index_index');
        $this->registry->method('registry')->willReturn(null);

        $this->assertSame(
            ['item_list_id' => 'product_list', 'item_list_name' => 'Product list'],
            $this->context()->get()
        );
    }

    public function testAWidgetOverrideSlugsItsOwnTitle(): void
    {
        $this->assertSame(
            ['item_list_id' => 'widget_summer_picks', 'item_list_name' => 'Summer Picks!'],
            $this->context()->get('Summer Picks!')
        );
    }

    public function testTheIdentityIsResolvedOnceForTheWholePage(): void
    {
        $this->request->expects($this->once())->method('getFullActionName')->willReturn('cms_index_index');
        $this->registry->method('registry')->willReturn(null);

        $context = $this->context();
        $context->get();
        $context->get();
    }

    public function testTheItemPayloadCountsPositionsFromOneAsGa4Does(): void
    {
        $this->request->method('getFullActionName')->willReturn('cms_index_index');
        $this->registry->method('registry')->willReturn(null);

        $payload = $this->context()->toItemPayload($this->product('24-MB01', 'Joust Duffle Bag', 34.0), 0);

        $this->assertSame(1, $payload['index']);
        $this->assertSame('24-MB01', $payload['item_id']);
        $this->assertSame('Joust Duffle Bag', $payload['item_name']);
        $this->assertSame(34.0, $payload['price']);
        $this->assertSame('USD', $payload['currency']);
        $this->assertSame('product_list', $payload['item_list_id']);
    }

    private function context(): ListContext
    {
        return new ListContext($this->request, $this->registry, $this->storeManager, $this->config);
    }

    private function category(int $id, string $name): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn($name);

        return $category;
    }

    private function product(string $sku, string $name, float $price): Product&MockObject
    {
        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn($price);
        $priceModel = $this->createMock(PriceInterface::class);
        $priceModel->method('getAmount')->willReturn($amount);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($priceModel);

        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn($name);
        $product->method('getPriceInfo')->willReturn($priceInfo);

        return $product;
    }
}
