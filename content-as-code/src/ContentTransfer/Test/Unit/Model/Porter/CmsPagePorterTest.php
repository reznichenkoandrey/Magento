<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model\Porter;

use ArrayIterator;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\Page\CustomLayout\Data\CustomLayoutSelectedInterface;
use Magento\Cms\Model\Page\CustomLayoutRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Page\Collection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Model\Content\ContentTransformer;
use Scr1be\ContentTransfer\Model\Entry;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\Outcome;
use Scr1be\ContentTransfer\Model\Porter\CmsPagePorter;
use Scr1be\ContentTransfer\Model\Porter\StoreScopedKey;
use Scr1be\ContentTransfer\Model\StoreScope;
use Scr1be\ContentTransfer\Model\ThemeResolver;

/**
 * The apply path, which is where the awkward core contracts live.
 *
 * `find()` is exercised by handing the collection factory an empty or populated iterator, which is
 * enough to steer every branch without a database.
 */
class CmsPagePorterTest extends TestCase
{
    private const PAGE_ID = 42;

    public function testANewPageIsCreated(): void
    {
        $page = $this->page();
        $porter = $this->porter($page, existing: null);

        $outcome = $porter->apply($this->entry(), ImportMode::Skip);

        $this->assertSame(Outcome::STATUS_CREATED, $outcome->getStatus());
    }

    public function testAnExistingPageIsLeftAloneInSkipMode(): void
    {
        $existing = $this->page();
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->expects($this->never())->method('save');

        $outcome = $this->porter($this->page(), existing: $existing, repository: $repository)
            ->apply($this->entry(), ImportMode::Skip);

        $this->assertSame(Outcome::STATUS_SKIPPED, $outcome->getStatus());
    }

    public function testAnExistingPageIsOverwrittenInReplaceMode(): void
    {
        $existing = $this->page();

        $outcome = $this->porter($this->page(), existing: $existing)
            ->apply($this->entry(), ImportMode::Replace);

        $this->assertSame(Outcome::STATUS_REPLACED, $outcome->getStatus());
    }

    public function testTheCustomLayoutSelectionIsClearedBeforeTheMainSave(): void
    {
        // Magento\Cms\Model\Page::beforeSave() runs validateLayoutSelectedFor(), which throws when
        // the value is set and the page has no id — so a page carrying one cannot be created in a
        // single save. This is the assertion that keeps the field out of that save.
        $page = $this->page();
        $written = [];

        $page->method('setData')->willReturnCallback(
            static function (string $key, $value = null) use ($page, &$written) {
                $written[$key][] = $value;

                return $page;
            }
        );

        $this->porter($page, existing: null, extraSetData: true)
            ->apply($this->entryWithLayout('cms_page_view_selectable_home_hero'), ImportMode::Skip);

        $this->assertSame([null], $written['layout_update_selected'] ?? null);
    }

    public function testTheCustomLayoutIsAppliedThroughCoresOwnRepositoryAfterwards(): void
    {
        $customLayout = $this->createMock(CustomLayoutRepositoryInterface::class);
        $customLayout->expects($this->once())
            ->method('save')
            ->with($this->callback(
                static fn (CustomLayoutSelectedInterface $selected): bool =>
                    $selected->getPageId() === self::PAGE_ID
                    && $selected->getLayoutFileId() === 'cms_page_view_selectable_home_hero'
            ));

        $outcome = $this->porter($this->page(), existing: null, customLayout: $customLayout)
            ->apply($this->entryWithLayout('cms_page_view_selectable_home_hero'), ImportMode::Skip);

        $this->assertSame(Outcome::STATUS_CREATED, $outcome->getStatus());
    }

    public function testAPageWithNoSelectionNeverTouchesTheCustomLayoutRepository(): void
    {
        $customLayout = $this->createMock(CustomLayoutRepositoryInterface::class);
        $customLayout->expects($this->never())->method('save');

        $this->porter($this->page(), existing: null, customLayout: $customLayout)
            ->apply($this->entry(), ImportMode::Skip);
    }

    public function testALayoutFileMissingOnThisInstallDoesNotFailTheEntry(): void
    {
        // The page landed. Reporting it as failed sends the operator looking for a page that is
        // already on the storefront.
        $customLayout = $this->createMock(CustomLayoutRepositoryInterface::class);
        $customLayout->method('save')
            ->willThrowException(new CouldNotSaveException(__('Invalid Custom Layout Update selected')));

        $outcome = $this->porter($this->page(), existing: null, customLayout: $customLayout)
            ->apply($this->entryWithLayout('cms_page_view_selectable_home_hero'), ImportMode::Skip);

        $this->assertSame(Outcome::STATUS_CREATED, $outcome->getStatus());
        $this->assertStringContainsString('was not applied', $outcome->getMessage());
    }

    public function testAnEntryWithNoIdentifierIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/identifier/');

        $this->porter($this->page(), existing: null)
            ->apply(new Entry(CmsPagePorter::CODE, 'broken', ['stores' => []]), ImportMode::Skip);
    }

    private function entry(): Entry
    {
        return new Entry(CmsPagePorter::CODE, 'home', [
            'identifier' => 'home',
            'title' => 'Home',
            PageInterface::CONTENT => '<p>Hello</p>',
            PageInterface::IS_ACTIVE => true,
            'stores' => [],
        ]);
    }

    private function entryWithLayout(string $layoutFile): Entry
    {
        $payload = $this->entry()->getPayload();
        $payload['layout_update_selected'] = $layoutFile;

        return new Entry(CmsPagePorter::CODE, 'home', $payload);
    }

    private function page(): Page&MockObject
    {
        $page = $this->createMock(Page::class);
        $page->method('getId')->willReturn(self::PAGE_ID);
        $page->method('getIdentifier')->willReturn('home');
        $page->method('getData')->willReturn(null);

        return $page;
    }

    private function porter(
        Page&MockObject $page,
        ?Page $existing,
        ?PageRepositoryInterface $repository = null,
        ?CustomLayoutRepositoryInterface $customLayout = null,
        bool $extraSetData = false
    ): CmsPagePorter {
        if (!$extraSetData) {
            $page->method('setData')->willReturn($page);
        }

        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new ArrayIterator($existing ? [$existing] : []));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        $storeScope = $this->createMock(StoreScope::class);
        $storeScope->method('toIds')->willReturn([0]);
        $storeScope->method('toCodes')->willReturn([]);

        return new CmsPagePorter(
            $collectionFactory,
            $pageFactory,
            $repository ?? $this->createMock(PageRepositoryInterface::class),
            $this->createMock(ContentTransformer::class),
            $storeScope,
            $this->createMock(ThemeResolver::class),
            $customLayout ?? $this->createMock(CustomLayoutRepositoryInterface::class),
            new StoreScopedKey()
        );
    }
}
