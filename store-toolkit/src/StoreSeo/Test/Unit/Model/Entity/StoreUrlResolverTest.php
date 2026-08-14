<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model\Entity;

use Magento\Store\Model\Store;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Entity\EntityContext;
use Scr1be\StoreSeo\Model\Entity\StoreUrlResolver;

class StoreUrlResolverTest extends TestCase
{
    /**
     * @var UrlFinderInterface&MockObject
     */
    private $urlFinder;

    /**
     * @var Store&MockObject
     */
    private $store;

    private StoreUrlResolver $resolver;

    protected function setUp(): void
    {
        $this->urlFinder = $this->createMock(UrlFinderInterface::class);
        $this->store = $this->createMock(Store::class);
        $this->store->method('getId')->willReturn(2);
        $this->store->method('getBaseUrl')->willReturn('https://example.com/de/');

        $this->resolver = new StoreUrlResolver($this->urlFinder);
    }

    public function testJoinsTheRequestPathToTheStoreBaseUrl(): void
    {
        $this->urlFinder->method('findOneByData')->willReturn($this->rewrite('damen/tops.html'));

        $url = $this->resolver->resolve(new EntityContext('category', 7), $this->store);

        self::assertSame('https://example.com/de/damen/tops.html', $url);
    }

    public function testAsksOnlyForTheLiveRewriteInThatStore(): void
    {
        $this->urlFinder->expects(self::once())
            ->method('findOneByData')
            ->with([
                UrlRewrite::ENTITY_TYPE => 'product',
                UrlRewrite::ENTITY_ID => 42,
                UrlRewrite::STORE_ID => 2,
                // Redirect rows are the leftovers of a url_key change; advertising one as an
                // alternate points a crawler at a 301 instead of at the page.
                UrlRewrite::REDIRECT_TYPE => 0,
            ])
            ->willReturn($this->rewrite('joust-duffle-bag.html'));

        $this->resolver->resolve(new EntityContext('product', 42), $this->store);
    }

    public function testNoRewriteMeansNoUrl(): void
    {
        $this->urlFinder->method('findOneByData')->willReturn(null);

        self::assertNull($this->resolver->resolve(new EntityContext('product', 42), $this->store));
    }

    public function testTheHomePageIsTheBaseUrlAndCostsNoQuery(): void
    {
        $this->urlFinder->expects(self::never())->method('findOneByData');

        $url = $this->resolver->resolve(new EntityContext(EntityContext::TYPE_HOME), $this->store);

        self::assertSame('https://example.com/de/', $url);
    }

    /**
     * @return UrlRewrite&MockObject
     */
    private function rewrite(string $requestPath)
    {
        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn($requestPath);

        return $rewrite;
    }
}
