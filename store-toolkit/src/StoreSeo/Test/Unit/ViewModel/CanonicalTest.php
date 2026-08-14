<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\ViewModel;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Canonical\UrlBuilder;
use Scr1be\StoreSeo\Model\Config;
use Scr1be\StoreSeo\ViewModel\Canonical;

/**
 * The seam, not the string builder: which request accessor is read, which base URL type is asked
 * for, and whether the answer is computed once. UrlBuilderTest covers the assembly itself.
 */
class CanonicalTest extends TestCase
{
    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    /**
     * @var HttpRequest&MockObject
     */
    private $request;

    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var Store&MockObject
     */
    private $store;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->config = $this->createMock(Config::class);
        $this->store = $this->createMock(Store::class);

        $this->store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($this->store);
    }

    public function testAsksForTheLinkBaseUrlSoTheStoreCodeIsIncluded(): void
    {
        // URL_TYPE_WEB would give the bare domain and lose the /de/ prefix that
        // Store::_updatePathUseStoreView() adds to the link URL.
        $this->store->expects(self::once())
            ->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_LINK)
            ->willReturn('https://example.com/de/');

        $this->givenEnabledWithWhitelist(['p']);
        $this->request->method('getPathInfo')->willReturn('/damen/tops.html');
        $this->request->method('getQueryValue')->willReturn([]);

        self::assertSame('https://example.com/de/damen/tops.html', $this->viewModel()->getCanonicalUrl());
    }

    public function testReadsTheQueryStringRatherThanTheMergedParameterBag(): void
    {
        // getParams() would also contain route parameters (`id`, `_secure`) and, on a POST, the
        // request body — none of which belongs in a canonical.
        $this->request->expects(self::once())->method('getQueryValue')->willReturn(['p' => '2']);
        $this->request->expects(self::never())->method('getParams');

        $this->givenEnabledWithWhitelist(['p']);
        $this->store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->request->method('getPathInfo')->willReturn('/c.html');

        self::assertSame('https://example.com/c.html?p=2', $this->viewModel()->getCanonicalUrl());
    }

    public function testResolvesOncePerRequest(): void
    {
        $this->givenEnabledWithWhitelist([]);
        $this->store->expects(self::once())->method('getBaseUrl')->willReturn('https://example.com/');
        $this->request->expects(self::once())->method('getPathInfo')->willReturn('/c.html');
        $this->request->method('getQueryValue')->willReturn([]);

        $viewModel = $this->viewModel();

        self::assertSame($viewModel->getCanonicalUrl(), $viewModel->getCanonicalUrl());
    }

    public function testDisabledStoreGetsNoCanonicalAndCostsNoWork(): void
    {
        $this->config->method('isCanonicalEnabled')->willReturn(false);
        $this->store->expects(self::never())->method('getBaseUrl');

        self::assertNull($this->viewModel()->getCanonicalUrl());
    }

    public function testAnUnresolvableStoreIsNotAFatalError(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException(__('No store.')));

        $viewModel = new Canonical($storeManager, $this->request, $this->config, new UrlBuilder());

        self::assertNull($viewModel->getCanonicalUrl());
    }

    /**
     * @param string[] $whitelist
     */
    private function givenEnabledWithWhitelist(array $whitelist): void
    {
        $this->config->method('isCanonicalEnabled')->willReturn(true);
        $this->config->method('getCanonicalQueryWhitelist')->willReturn($whitelist);
    }

    private function viewModel(): Canonical
    {
        return new Canonical($this->storeManager, $this->request, $this->config, new UrlBuilder());
    }
}
