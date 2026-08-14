<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Test\Unit\Model\Resolver;

use Magento\Customer\Model\Group;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\SearchAutocomplete\Model\Config;
use Scr1be\SearchAutocomplete\Model\ProviderPool;
use Scr1be\SearchAutocomplete\Model\Resolver\SearchAutocomplete;
use Scr1be\SearchAutocomplete\Model\SuggestionRequest;

/**
 * The resolver is the seam between GraphQL's context and this module's own request object, and every
 * value it reads comes from somewhere a unit test would otherwise never exercise.
 *
 * `ContextExtensionInterface` needs care to mock, because there are two versions of it depending on
 * where the suite is run:
 *
 *  - Under the plain unit-test autoloader, it does not exist on disk and
 *    `Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator` stubs it
 *    as an *empty* interface — its own docblock says so. `getStore()` must then be `addMethods()`.
 *    Core's own GraphQL unit tests assume exactly this; see
 *    `Magento\CatalogUrlRewriteGraphQl\Test\Unit\Model\Resolver\CategoryUrlSuffixTest`.
 *  - On an installed store, `generated/code` holds the real interface built from every module's
 *    `extension_attributes.xml`, so `getStore()` is declared and `addMethods()` on it is a fatal
 *    `CannotUseAddMethodsException`.
 *
 * `extensionAttributesMock()` picks per method rather than guessing, so the suite is green whether it
 * is run from a bare checkout or from inside a working installation.
 */
class SearchAutocompleteTest extends TestCase
{
    private const STORE_ID = 3;
    private const WEBSITE_ID = 2;

    private ProviderPool&MockObject $pool;
    private StoreManagerInterface&MockObject $storeManager;

    protected function setUp(): void
    {
        $this->pool = $this->createMock(ProviderPool::class);
        $this->pool->method('getKeys')->willReturn(['products', 'categories', 'terms']);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $store->method('getWebsiteId')->willReturn(self::WEBSITE_ID);

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    public function testPassesTheResolvedRequestToThePool(): void
    {
        $captured = null;
        $this->pool->method('collect')->willReturnCallback(
            static function (SuggestionRequest $request) use (&$captured) {
                $captured = $request;

                return ['products' => [], 'categories' => [], 'terms' => []];
            }
        );

        $this->resolver()->resolve(
            $this->createMock(Field::class),
            $this->context(4),
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => '  shirt  ']
        );

        $this->assertSame('shirt', $captured?->term, 'The term is trimmed before it reaches a provider');
        $this->assertSame(self::STORE_ID, $captured?->storeId);
        $this->assertSame(self::WEBSITE_ID, $captured?->websiteId);
        $this->assertSame(4, $captured?->customerGroupId);
        $this->assertSame(8, $captured?->limit);
    }

    /**
     * A shopper who has typed two of the three characters the store requires is mid-word, not in
     * error. Every section has to be present because the schema declares them non-null.
     */
    public function testATooShortTermReturnsEmptySectionsWithoutRunningAnyProvider(): void
    {
        $this->pool->expects($this->never())->method('collect');

        $result = $this->resolver()->resolve(
            $this->createMock(Field::class),
            $this->context(0),
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => 'sh']
        );

        $this->assertSame(
            ['query' => 'sh', 'products' => [], 'categories' => [], 'terms' => []],
            $result
        );
    }

    /**
     * An unauthenticated caller has no group on the context and must be priced as NOT LOGGED IN
     * rather than as group 0-by-accident or as whatever a session would have said.
     */
    public function testAnAbsentCustomerGroupBecomesNotLoggedIn(): void
    {
        $captured = null;
        $this->pool->method('collect')->willReturnCallback(
            static function (SuggestionRequest $request) use (&$captured) {
                $captured = $request;

                return [];
            }
        );

        $this->resolver()->resolve(
            $this->createMock(Field::class),
            $this->context(null),
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => 'shirt']
        );

        $this->assertSame(Group::NOT_LOGGED_IN_ID, $captured?->customerGroupId);
    }

    /**
     * The maximum length is a store setting and the term is cut to it, so an eight-kilobyte "query"
     * never reaches a LIKE.
     */
    public function testTheTermIsTruncatedToTheStoresMaximum(): void
    {
        $captured = null;
        $this->pool->method('collect')->willReturnCallback(
            static function (SuggestionRequest $request) use (&$captured) {
                $captured = $request;

                return [];
            }
        );

        $this->resolver()->resolve(
            $this->createMock(Field::class),
            $this->context(0),
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => str_repeat('a', 5000)]
        );

        $this->assertSame(128, mb_strlen((string)$captured?->term));
    }

    public function testTheResultAlwaysCarriesTheTermItAnswered(): void
    {
        $this->pool->method('collect')->willReturn(['products' => [], 'categories' => [], 'terms' => []]);

        $result = $this->resolver()->resolve(
            $this->createMock(Field::class),
            $this->context(0),
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => 'shirt']
        );

        $this->assertSame('shirt', $result['query']);
    }

    private function resolver(): SearchAutocomplete
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        return new SearchAutocomplete($this->pool, new Config($scopeConfig), $this->storeManager);
    }

    /**
     * @param int|null $customerGroupId
     * @return ContextInterface&MockObject
     */
    private function context(?int $customerGroupId): ContextInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);

        $extension = $this->extensionAttributesMock(['getStore', 'getCustomerGroupId']);
        $extension->method('getStore')->willReturn($store);
        $extension->method('getCustomerGroupId')->willReturn($customerGroupId);

        $context = $this->getMockBuilder(ContextInterface::class)
            ->onlyMethods(['getExtensionAttributes', 'getUserId', 'getUserType'])
            ->getMockForAbstractClass();
        $context->method('getExtensionAttributes')->willReturn($extension);

        return $context;
    }

    /**
     * Mock the context's extension attributes, whichever version of the interface is loaded.
     *
     * See the class docblock: the same method name has to go through `onlyMethods()` on an installed
     * store and through `addMethods()` on a bare checkout, and PHPUnit throws rather than tolerating
     * the wrong one. Reflection answers which, so neither environment needs a special case.
     *
     * @param string[] $methods
     * @return ContextExtensionInterface&MockObject
     */
    private function extensionAttributesMock(array $methods): ContextExtensionInterface
    {
        $declared = get_class_methods(ContextExtensionInterface::class);
        $builder = $this->getMockBuilder(ContextExtensionInterface::class);

        $existing = array_values(array_intersect($methods, $declared));
        $missing = array_values(array_diff($methods, $declared));

        if ($existing !== []) {
            $builder->onlyMethods($existing);
        }
        if ($missing !== []) {
            $builder->addMethods($missing);
        }

        return $builder->getMockForAbstractClass();
    }
}
