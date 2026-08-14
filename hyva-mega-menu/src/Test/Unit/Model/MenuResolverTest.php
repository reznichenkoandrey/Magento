<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Test\Unit\Model;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMegaMenu\Model\Config;
use Scr1be\HyvaMegaMenu\Model\GroupMenuMap;
use Scr1be\HyvaMegaMenu\Model\MenuResolver;
use Scr1be\HyvaMegaMenu\Model\RootCategories;

class MenuResolverTest extends TestCase
{
    private const STORE_ID = 1;
    private const STORE_ROOT = 2;

    private Config&MockObject $config;
    private RootCategories&MockObject $rootCategories;
    private HttpContext&MockObject $httpContext;
    private MenuResolver $resolver;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->rootCategories = $this->createMock(RootCategories::class);
        $this->httpContext = $this->createMock(HttpContext::class);

        $this->resolver = new MenuResolver(
            $this->config,
            new GroupMenuMap(),
            $this->rootCategories,
            $this->httpContext
        );
    }

    /**
     * @param int[] $activeRoots
     */
    private function activeRoots(array $activeRoots, ?int $first = null): void
    {
        $this->rootCategories->method('isActiveRoot')
            ->willReturnCallback(
                static fn (int $categoryId, int $storeId): bool => in_array($categoryId, $activeRoots, true)
            );
        $this->rootCategories->method('getFirstActiveRootId')->willReturn($first);
    }

    private function group(int $groupId): void
    {
        $this->httpContext->method('getValue')
            ->with(CustomerContext::CONTEXT_GROUP)
            ->willReturn((string) $groupId);
    }

    public function testTheLayoutArgumentWins(): void
    {
        $this->config->method('getGroupMapRaw')->willReturn('2:8');
        $this->config->method('getDefaultRootCategoryId')->willReturn(9);
        $this->group(2);
        $this->activeRoots([2, 5, 8, 9]);

        $this->assertSame(5, $this->resolver->resolve(self::STORE_ID, self::STORE_ROOT, 5));
    }

    /**
     * A layout handle that pins a root category which was later switched off is a configuration
     * mistake, and the cost of it is a menu that looks wrong — not a header with no navigation.
     */
    public function testAnInactiveLayoutArgumentFallsThrough(): void
    {
        $this->config->method('getGroupMapRaw')->willReturn('');
        $this->config->method('getDefaultRootCategoryId')->willReturn(null);
        $this->activeRoots([self::STORE_ROOT]);

        $this->assertSame(self::STORE_ROOT, $this->resolver->resolve(self::STORE_ID, self::STORE_ROOT, 5));
    }

    public function testTheGroupMapBeatsTheStoreDefault(): void
    {
        $this->config->method('getGroupMapRaw')->willReturn("1:4\n2:8");
        $this->config->method('getDefaultRootCategoryId')->willReturn(9);
        $this->group(2);
        $this->activeRoots([8, 9]);

        $this->assertSame(8, $this->resolver->resolve(self::STORE_ID, self::STORE_ROOT));
    }

    public function testAGroupWithNoEntryUsesTheStoreDefault(): void
    {
        $this->config->method('getGroupMapRaw')->willReturn('2:8');
        $this->config->method('getDefaultRootCategoryId')->willReturn(9);
        $this->group(3);
        $this->activeRoots([8, 9]);

        $this->assertSame(9, $this->resolver->resolve(self::STORE_ID, self::STORE_ROOT));
    }

    public function testTheConfiguredRootBeatsTheStoresOwn(): void
    {
        $this->config->method('getGroupMapRaw')->willReturn('');
        $this->config->method('getDefaultRootCategoryId')->willReturn(9);
        $this->activeRoots([self::STORE_ROOT, 9]);

        $this->assertSame(9, $this->resolver->resolve(self::STORE_ID, self::STORE_ROOT));
    }

    public function testAnEmptyConfiguredRootMeansTheStoresOwn(): void
    {
        $this->config->method('getGroupMapRaw')->willReturn('');
        $this->config->method('getDefaultRootCategoryId')->willReturn(null);
        $this->activeRoots([self::STORE_ROOT]);

        $this->assertSame(self::STORE_ROOT, $this->resolver->resolve(self::STORE_ID, self::STORE_ROOT));
    }

    /**
     * `Store::getRootCategoryId()` answers `Category::ROOT_CATEGORY_ID`, which is 0, for a store
     * with no group. Zero is not a category, so it must not be offered to the active-root check
     * as though it were one.
     */
    public function testAStoreWithNoRootCategoryFallsThroughToTheFirstActiveOne(): void
    {
        $this->config->method('getGroupMapRaw')->willReturn('');
        $this->config->method('getDefaultRootCategoryId')->willReturn(null);
        // 0 is deliberately reported as an active root: a resolver that offered the store's zero
        // as a candidate would answer 0 here instead of reaching step four.
        $this->activeRoots([0], 4);

        $this->assertSame(4, $this->resolver->resolve(self::STORE_ID, 0));
    }

    public function testEveryCandidateFailingLandsOnTheFirstActiveRoot(): void
    {
        $this->config->method('getGroupMapRaw')->willReturn('2:8');
        $this->config->method('getDefaultRootCategoryId')->willReturn(9);
        $this->group(2);
        $this->activeRoots([], 4);

        $this->assertSame(4, $this->resolver->resolve(self::STORE_ID, self::STORE_ROOT, 5));
    }

    public function testAnInstallationWithNoActiveRootResolvesToNothing(): void
    {
        $this->config->method('getGroupMapRaw')->willReturn('');
        $this->config->method('getDefaultRootCategoryId')->willReturn(null);
        $this->activeRoots([], null);

        $this->assertNull($this->resolver->resolve(self::STORE_ID, self::STORE_ROOT));
    }

    public function testVariesByCustomerGroupOnlyWhenTheMapHoldsSomethingUsable(): void
    {
        $this->config->method('getGroupMapRaw')->willReturnOnConsecutiveCalls('', 'nonsense', '2:8');

        $this->assertFalse($this->resolver->variesByCustomerGroup(self::STORE_ID));
        $this->assertFalse($this->resolver->variesByCustomerGroup(self::STORE_ID));
        $this->assertTrue($this->resolver->variesByCustomerGroup(self::STORE_ID));
    }

    /**
     * The group comes from the HTTP context, which is what the full-page cache varies on — never
     * from the customer session, which is depersonalised on a cacheable request.
     */
    public function testTheGroupIsReadFromTheHttpContext(): void
    {
        $this->httpContext->expects($this->once())
            ->method('getValue')
            ->with(CustomerContext::CONTEXT_GROUP)
            ->willReturn('3');

        $this->assertSame(3, $this->resolver->getCustomerGroupId());
    }
}
