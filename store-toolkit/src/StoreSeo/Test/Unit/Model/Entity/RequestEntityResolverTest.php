<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model\Entity;

use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Entity\EntityContext;
use Scr1be\StoreSeo\Model\Entity\EntityTypes;
use Scr1be\StoreSeo\Model\Entity\RequestEntityResolver;

class RequestEntityResolverTest extends TestCase
{
    /**
     * @var HttpRequest&MockObject
     */
    private $request;

    private RequestEntityResolver $resolver;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->resolver = new RequestEntityResolver($this->request, new EntityTypes());
    }

    public function testResolvesAProductPage(): void
    {
        $this->givenRequest('catalog_product_view', ['id' => '42']);

        $entity = $this->resolver->resolve();

        self::assertNotNull($entity);
        self::assertSame('product', $entity->getType());
        self::assertSame(42, $entity->getId());
    }

    public function testResolvesACategoryPage(): void
    {
        $this->givenRequest('catalog_category_view', ['id' => '7']);

        $entity = $this->resolver->resolve();

        self::assertNotNull($entity);
        self::assertSame('category', $entity->getType());
    }

    public function testResolvesACmsPageFromEitherParameterName(): void
    {
        $this->givenRequest('cms_page_view', ['page_id' => '3']);
        $fromPageId = $this->resolver->resolve();

        self::assertNotNull($fromPageId);
        self::assertSame('cms-page', $fromPageId->getType());
        self::assertSame(3, $fromPageId->getId());

        $request = $this->createMock(HttpRequest::class);
        $request->method('getFullActionName')->willReturn('cms_page_view');
        $request->method('getParam')->willReturnCallback(
            static fn (string $key) => $key === 'id' ? '9' : null
        );

        $fromId = (new RequestEntityResolver($request, new EntityTypes()))->resolve();

        self::assertNotNull($fromId);
        self::assertSame(9, $fromId->getId());
    }

    public function testResolvesTheHomePage(): void
    {
        $this->givenRequest('cms_index_index', []);

        $entity = $this->resolver->resolve();

        self::assertNotNull($entity);
        self::assertSame(EntityContext::TYPE_HOME, $entity->getType());
    }

    public function testActionNameCasingDoesNotMatter(): void
    {
        // getFullActionName() concatenates the router's own strings without lowercasing them, so a
        // controller reached as `catalog_product_View` has to match too.
        $this->givenRequest('catalog_product_View', ['id' => '42']);

        self::assertNotNull($this->resolver->resolve());
    }

    public function testUnknownPagesResolveToNothing(): void
    {
        $this->givenRequest('catalogsearch_result_index', []);

        self::assertNull($this->resolver->resolve());
    }

    public function testAnEntityPageWithoutAnIdResolvesToNothing(): void
    {
        $this->givenRequest('catalog_product_view', []);

        self::assertNull($this->resolver->resolve());
    }

    /**
     * @param array<string, string> $params
     */
    private function givenRequest(string $fullActionName, array $params): void
    {
        $this->request->method('getFullActionName')->willReturn($fullActionName);
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key) => $params[$key] ?? null
        );
    }
}
