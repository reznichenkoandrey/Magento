<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Test\Unit\Block;

use Magento\Framework\App\State;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Element\Template\File\Resolver;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMegaMenu\Block\MegaMenu;
use Scr1be\HyvaMegaMenu\Model\Config;
use Scr1be\HyvaMegaMenu\Model\Icon\SpriteRegistry;
use Scr1be\HyvaMegaMenu\Model\MenuResolver;
use Scr1be\HyvaMegaMenu\Model\MenuTree;
use Scr1be\HyvaMegaMenu\Model\MenuTreeBuilder;

/**
 * The block decides two things the rest of the module cannot: what goes into the cache key, and how
 * many times the tree is built for one render. Both are silent when they are wrong — a key that
 * shards produces correct pages and a cache that no longer helps, and a build that runs twice
 * produces the same menu for double the queries.
 */
class MegaMenuTest extends TestCase
{
    private const STORE_ID = 1;
    private const STORE_ROOT_ID = 2;
    private const RESOLVED_ROOT_ID = 5;

    private MenuResolver&MockObject $menuResolver;
    private MenuTreeBuilder&MockObject $menuTreeBuilder;
    private Config&MockObject $config;
    private SpriteRegistry&MockObject $spriteRegistry;
    private Context&MockObject $context;

    protected function setUp(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $store->method('getCode')->willReturn('default');
        $store->method('getRootCategoryId')->willReturn(self::STORE_ROOT_ID);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn('frontend');

        // Template::getCacheKeyInfo() builds its half of the key from the store code, the resolved
        // template file and the base url, so both collaborators need a double behind them even
        // though nothing in these tests renders anything.
        $resolver = $this->createMock(Resolver::class);
        $resolver->method('getTemplateFileName')->willReturn('mega-menu.phtml');

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getBaseUrl')->willReturn('https://example.test/');

        $this->context = $this->createMock(Context::class);
        $this->context->method('getStoreManager')->willReturn($storeManager);
        $this->context->method('getAppState')->willReturn($appState);
        $this->context->method('getResolver')->willReturn($resolver);
        $this->context->method('getUrlBuilder')->willReturn($urlBuilder);

        $this->menuResolver = $this->createMock(MenuResolver::class);
        $this->menuTreeBuilder = $this->createMock(MenuTreeBuilder::class);
        $this->config = $this->createMock(Config::class);
        $this->spriteRegistry = $this->createMock(SpriteRegistry::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function block(array $data = []): MegaMenu
    {
        return new MegaMenu(
            $this->context,
            $this->menuResolver,
            $this->menuTreeBuilder,
            $this->config,
            $this->spriteRegistry,
            new JsonHexTag(),
            $data
        );
    }

    private function resolvesTo(?int $rootCategoryId): void
    {
        $this->menuResolver->method('resolve')->willReturn($rootCategoryId);
    }

    private function buildsTree(MenuTree $tree): void
    {
        $this->menuTreeBuilder->method('build')->willReturn($tree);
    }

    /**
     * Without a group map the menu cannot differ between groups, so putting the group in the key
     * would shard one entry per store view into one per group for identical HTML.
     */
    public function testTheKeyCarriesTheStoreAndTheResolvedRoot(): void
    {
        $this->resolvesTo(self::RESOLVED_ROOT_ID);
        $this->menuResolver->method('variesByCustomerGroup')->willReturn(false);

        $info = $this->block()->getCacheKeyInfo();

        $this->assertContains('store_1', $info);
        $this->assertContains('root_5', $info);
        $this->assertSame([], array_filter($info, static fn ($part): bool => str_starts_with((string) $part, 'group_')));
    }

    public function testTheCustomerGroupJoinsTheKeyOnlyWhenAMapExists(): void
    {
        $this->resolvesTo(self::RESOLVED_ROOT_ID);
        $this->menuResolver->method('variesByCustomerGroup')->willReturn(true);
        $this->menuResolver->method('getCustomerGroupId')->willReturn(3);

        $this->assertContains('group_3', $this->block()->getCacheKeyInfo());
    }

    /**
     * `null` is one of resolution's answers, so the memo cannot be a nullable field standing in for
     * a flag — and an installation with no active root must still produce a stable key rather than
     * a key that changes shape.
     */
    public function testAnInstallationWithNoRootStillProducesAKey(): void
    {
        $this->resolvesTo(null);
        $this->menuResolver->method('variesByCustomerGroup')->willReturn(false);

        $this->assertContains('root_none', $this->block()->getCacheKeyInfo());
    }

    /**
     * Resolution runs for the cache key and again for the tree. Both have to be answered from one
     * pass — the resolver reads the HTTP context and, on the null branch, a category collection.
     */
    public function testResolutionRunsOnceForBothTheKeyAndTheTree(): void
    {
        $this->menuResolver->expects($this->once())
            ->method('resolve')
            ->willReturn(self::RESOLVED_ROOT_ID);
        $this->menuResolver->method('variesByCustomerGroup')->willReturn(false);
        $this->buildsTree(new MenuTree([['key' => 'c3']], [], [3], []));

        $block = $this->block();
        $block->getCacheKeyInfo();
        $block->getMenuItems();
        $block->getIdentities();
    }

    public function testTheTreeIsBuiltOnceHoweverManyTimesTheTemplateAsks(): void
    {
        $this->resolvesTo(self::RESOLVED_ROOT_ID);
        $this->menuTreeBuilder->expects($this->once())
            ->method('build')
            ->with(self::RESOLVED_ROOT_ID, self::STORE_ID, true)
            ->willReturn(new MenuTree([['key' => 'c3']], [], [3], []));
        $this->config->method('isThirdLevelEnabled')->willReturn(true);

        $block = $this->block();
        $block->hasMenuItems();
        $block->getMenuItems();
        $block->getIslandJson();
        $block->getIdentities();
    }

    /**
     * Nothing to build a menu from is not an error: the header renders without navigation rather
     * than with a query that cannot answer.
     */
    public function testNoResolvedRootMeansNoBuildAtAll(): void
    {
        $this->resolvesTo(null);
        $this->menuTreeBuilder->expects($this->never())->method('build');

        $block = $this->block();

        $this->assertFalse($block->hasMenuItems());
        $this->assertSame([], $block->getMenuItems());
        $this->assertSame([], $block->getIdentities());
        $this->assertSame('', $block->getIslandJson());
    }

    /**
     * The layout argument is admin-authored, but a typo must fall through the resolution chain
     * rather than cast itself to category 0 and take the menu off every page.
     *
     * @dataProvider layoutArgumentProvider
     */
    public function testTheLayoutArgumentIsReadDefensively(mixed $argument, ?int $expected): void
    {
        $this->menuResolver->expects($this->once())
            ->method('resolve')
            ->with(self::STORE_ID, self::STORE_ROOT_ID, $expected)
            ->willReturn(self::RESOLVED_ROOT_ID);
        $this->menuResolver->method('variesByCustomerGroup')->willReturn(false);

        $this->block(['menu_root' => $argument])->getCacheKeyInfo();
    }

    /**
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function layoutArgumentProvider(): array
    {
        return [
            'number argument' => [7, 7],
            'string of digits' => ['7', 7],
            'category name instead of an id' => ['women', null],
            'zero' => [0, null],
            'negative' => [-1, null],
            'empty string' => ['', null],
            'boolean' => [true, null],
        ];
    }

    public function testTheBlockWithNoArgumentAsksForResolutionWithoutOne(): void
    {
        $this->menuResolver->expects($this->once())
            ->method('resolve')
            ->with(self::STORE_ID, self::STORE_ROOT_ID, null)
            ->willReturn(self::RESOLVED_ROOT_ID);
        $this->menuResolver->method('variesByCustomerGroup')->willReturn(false);

        $this->block()->getCacheKeyInfo();
    }

    /**
     * The island is a data block inside the page, so a category name that looks like markup has to
     * leave as an escape sequence rather than as an angle bracket.
     */
    public function testTheIslandIsSerialisedWithHexedAngleBrackets(): void
    {
        $this->resolvesTo(self::RESOLVED_ROOT_ID);
        $this->buildsTree(new MenuTree(
            [['key' => 'c3']],
            ['c3' => [['n' => '</script>', 'u' => 'https://example.test/x.html', 'i' => null]]],
            [3],
            []
        ));

        $json = $this->block()->getIslandJson();

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertSame('</script>', json_decode($json, true)['c3'][0]['n']);
    }

    public function testAMenuWithNoThirdLevelRendersNoIsland(): void
    {
        $this->resolvesTo(self::RESOLVED_ROOT_ID);
        $this->buildsTree(new MenuTree([['key' => 'c3']], [], [3], []));

        $this->assertSame('', $this->block()->getIslandJson());
    }

    /**
     * Only the symbols this menu referenced reach the page — the sprite is inlined into every
     * cached response, so an unused symbol is bytes paid for on every request.
     */
    public function testOnlyTheSpriteSymbolsTheTreeUsedAreAskedFor(): void
    {
        $this->resolvesTo(self::RESOLVED_ROOT_ID);
        $this->buildsTree(new MenuTree([['key' => 'c3']], [], [3], ['tag']));
        $this->spriteRegistry->expects($this->once())
            ->method('getSymbolsFor')
            ->with(['tag'])
            ->willReturn(['tag' => '<path d="M4 4h7l9 9-7 7-9-9z"/>']);

        $this->assertSame(['tag' => '<path d="M4 4h7l9 9-7 7-9-9z"/>'], $this->block()->getSpriteSymbols());
    }
}
