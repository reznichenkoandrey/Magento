<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Porter;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\Page\CustomLayout\Data\CustomLayoutSelected;
use Magento\Cms\Model\Page\CustomLayoutRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Page\Collection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Scr1be\ContentTransfer\Api\Data\EntryInterface;
use Scr1be\ContentTransfer\Api\PorterInterface;
use Scr1be\ContentTransfer\Model\Content\ContentTransformer;
use Scr1be\ContentTransfer\Model\Entry;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\Outcome;
use Scr1be\ContentTransfer\Model\Selection;
use Scr1be\ContentTransfer\Model\StoreScope;
use Scr1be\ContentTransfer\Model\Summary;
use Scr1be\ContentTransfer\Model\ThemeResolver;
use Throwable;

/**
 * CMS pages.
 *
 * Depends on `cms_block` so that a page whose content embeds blocks is written after them.
 *
 * ### The two columns this porter refuses to carry
 *
 * `layout_update_xml` and `custom_layout_update_xml` are captured as **warnings, not data**.
 * `Magento\Cms\Model\PageRepository::validateLayoutUpdate()` throws
 * `InvalidArgumentException('Custom layout updates must be selected from a file')` for any save
 * where either column is non-empty and differs from what is already persisted — which is every
 * possible save of a new page carrying one. That check is a deliberate hardening (arbitrary layout
 * XML is an arbitrary-block-instantiation primitive), and routing around it through the resource
 * model would turn this module into a way to reintroduce the hole a bundle at a time. So the value
 * stays behind and the operator is told which page had one.
 *
 * `layout_update_selected` — the file-based replacement, whose value is the tail of a
 * `cms_page_view_selectable_<identifier>_<name>` layout handle, i.e. a file a developer ships in
 * code — does travel, but not in the same save as the rest of the page. See `selectCustomLayout()`.
 *
 * `custom_theme` holds a theme **id**, so it travels as a full path (`frontend/Magento/luma`) and is
 * resolved back on import.
 */
class CmsPagePorter implements PorterInterface
{
    public const CODE = 'cms_page';

    public const KEY_IDENTIFIER = 'identifier';
    public const KEY_STORES = 'stores';
    public const KEY_CUSTOM_THEME = 'custom_theme';

    /**
     * Columns copied verbatim in both directions. Every one of them is either author-typed text or
     * a value that means the same thing on any install.
     */
    private const PLAIN_FIELDS = [
        PageInterface::TITLE,
        PageInterface::PAGE_LAYOUT,
        PageInterface::META_TITLE,
        PageInterface::META_KEYWORDS,
        PageInterface::META_DESCRIPTION,
        PageInterface::CONTENT_HEADING,
        PageInterface::SORT_ORDER,
        PageInterface::CUSTOM_ROOT_TEMPLATE,
        PageInterface::CUSTOM_THEME_FROM,
        PageInterface::CUSTOM_THEME_TO,
    ];

    /**
     * Spelled out rather than taken from `PageInterface`, which declares no constant for it:
     * `Magento_Cms/etc/db_schema.xml` has the column, the data interface does not have the key.
     */
    private const FIELD_LAYOUT_UPDATE_SELECTED = 'layout_update_selected';

    private const REFUSED_FIELDS = [
        PageInterface::LAYOUT_UPDATE_XML,
        PageInterface::CUSTOM_LAYOUT_UPDATE_XML,
    ];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly PageFactory $pageFactory,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ContentTransformer $contentTransformer,
        private readonly StoreScope $storeScope,
        private readonly ThemeResolver $themeResolver,
        private readonly CustomLayoutRepositoryInterface $customLayoutRepository,
        private readonly StoreScopedKey $key
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('CMS Pages');
    }

    public function getDependencies(): array
    {
        return [CmsBlockPorter::CODE];
    }

    public function summarize(Selection $selection): array
    {
        $summaries = [];

        foreach ($this->collection($selection) as $page) {
            $summaries[] = new Summary(
                $this->keyFor($page),
                (string)$page->getTitle(),
                $this->storeCodesOf($page)
            );
        }

        return $summaries;
    }

    /**
     * The bundle key for a loaded page. Public because the mass action on the native CMS page grid
     * has to turn selected rows into keys, and deriving that in a controller is how the two drift.
     */
    public function keyFor(Page $page): string
    {
        return $this->key->build((string)$page->getIdentifier(), $this->storeCodesOf($page));
    }

    public function capture(Selection $selection): array
    {
        $entries = [];

        foreach ($this->collection($selection) as $page) {
            $storeCodes = $this->storeCodesOf($page);
            $key = $this->keyFor($page);

            if (!$selection->includesIdentifier(self::CODE, $key)) {
                continue;
            }

            $label = self::CODE . '/' . $key;
            $rewrite = $this->contentTransformer->toPortable($page->getContent(), $label);
            $warnings = $rewrite->getWarnings();

            $payload = [
                self::KEY_IDENTIFIER => (string)$page->getIdentifier(),
                PageInterface::CONTENT => $rewrite->getContent(),
                PageInterface::IS_ACTIVE => (bool)$page->isActive(),
                self::KEY_STORES => $storeCodes,
            ];

            foreach (self::PLAIN_FIELDS as $field) {
                $payload[$field] = $page->getData($field);
            }

            $payload[self::FIELD_LAYOUT_UPDATE_SELECTED] = $page->getData(self::FIELD_LAYOUT_UPDATE_SELECTED);
            $payload[self::KEY_CUSTOM_THEME] = $this->captureTheme($page, $label, $warnings);

            foreach (self::REFUSED_FIELDS as $field) {
                if ((string)$page->getData($field) !== '') {
                    $warnings[] = sprintf(
                        '%s: "%s" is set on this page and was not captured. Magento refuses to save '
                        . 'layout XML through PageRepository; move it to a layout file and use the '
                        . '"Layout Update" file selector instead.',
                        $label,
                        $field
                    );
                }
            }

            ksort($payload);

            $entries[] = new Entry(self::CODE, $key, $payload, $rewrite->getTransforms(), $warnings);
        }

        return $entries;
    }

    public function exists(EntryInterface $entry): bool
    {
        return $this->find($entry) !== null;
    }

    public function apply(EntryInterface $entry, ImportMode $mode): Outcome
    {
        $payload = $entry->getPayload();
        $identifier = (string)($payload[self::KEY_IDENTIFIER] ?? '');

        if ($identifier === '') {
            throw new LocalizedException(new Phrase('The entry has no page identifier.'));
        }

        $existing = $this->find($entry);

        if ($existing !== null && !$mode->replacesExisting()) {
            return Outcome::skipped((string)__('Page "%1" is already here.', $identifier));
        }

        $page = $existing ?? $this->pageFactory->create();
        $storeIds = $this->storeScope->toIds((array)($payload[self::KEY_STORES] ?? []));

        $page->setIdentifier($identifier);
        $page->setContent((string)($payload[PageInterface::CONTENT] ?? ''));
        $page->setIsActive((bool)($payload[PageInterface::IS_ACTIVE] ?? true));

        foreach (self::PLAIN_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $page->setData($field, $payload[$field]);
            }
        }

        $themePath = (string)($payload[self::KEY_CUSTOM_THEME] ?? '');
        $page->setData(PageInterface::CUSTOM_THEME, $themePath === '' ? null : $this->themeResolver->toId($themePath));

        // Never in the same save as the rest — see selectCustomLayout().
        $page->setData(self::FIELD_LAYOUT_UPDATE_SELECTED, null);

        // The page's store relation save handler reads `getStores()` and falls back to
        // `getStoreId()`; setting both means the fallback is never the one that decides.
        $page->setData('stores', $storeIds);
        $page->setData('store_id', $storeIds);

        $this->pageRepository->save($page);

        $note = $this->selectCustomLayout(
            (int)$page->getId(),
            (string)($payload[self::FIELD_LAYOUT_UPDATE_SELECTED] ?? '')
        );

        return $existing !== null
            ? Outcome::replaced((string)__('Page "%1" was overwritten.', $identifier) . $note)
            : Outcome::created((string)__('Page "%1" was created.', $identifier) . $note);
    }

    /**
     * Apply the page's custom-layout selection, in its own save, after the page exists.
     *
     * `Magento\Cms\Model\Page::beforeSave()` ends with
     * `$this->customLayoutRepository->validateLayoutSelectedFor($this)`, and
     * `CustomLayoutRepository::validateLayoutSelectedFor()` throws
     * `LocalizedException('Invalid Custom Layout Update selected')` when the value is set and either
     * the page has no id yet **or** the file is not among the ones available for it. The first half
     * of that condition makes it impossible to create a page carrying a selection in one save — so
     * the field is nulled for the main save and applied here, through
     * `Magento\Cms\Model\Page\CustomLayoutRepositoryInterface`, which is core's own path for it.
     *
     * Availability comes from `CustomLayoutManager::fetchAvailableFiles()`, which matches layout
     * handles of the form `cms_page_view_selectable_<identifier>_<name>` — files a developer ships
     * in code. So a target install that has the page but not the layout file is a normal, expected
     * outcome, and it does not make the entry a failure: the page landed, and saying "failed" about
     * a page that is now on the storefront would send the operator looking for something that is not
     * wrong.
     *
     * @return string A clause appended to the outcome message, empty when there is nothing to say.
     */
    private function selectCustomLayout(int $pageId, string $layoutFile): string
    {
        if ($pageId === 0 || $layoutFile === '') {
            return '';
        }

        try {
            $this->customLayoutRepository->save(new CustomLayoutSelected($pageId, $layoutFile));
        } catch (Throwable $exception) {
            return ' ' . (string)__(
                'Its custom layout "%1" was not applied: %2',
                $layoutFile,
                $exception->getMessage()
            );
        }

        return '';
    }

    /**
     * @param string[] $warnings
     */
    private function captureTheme(Page $page, string $label, array &$warnings): ?string
    {
        $themeId = (int)$page->getData(PageInterface::CUSTOM_THEME);

        if ($themeId === 0) {
            return null;
        }

        try {
            return $this->themeResolver->toFullPath($themeId);
        } catch (LocalizedException $exception) {
            $warnings[] = sprintf('%s: custom theme could not be resolved (%s).', $label, $exception->getMessage());

            return null;
        }
    }

    private function collection(Selection $selection): Collection
    {
        $collection = $this->collectionFactory->create();

        if ($selection->hasStoreFilter()) {
            $collection->addStoreFilter($selection->getStoreIds());
        }

        return $collection;
    }

    private function find(EntryInterface $entry): ?Page
    {
        $payload = $entry->getPayload();
        $wantedStores = (array)($payload[self::KEY_STORES] ?? []);
        sort($wantedStores);

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(self::KEY_IDENTIFIER, (string)($payload[self::KEY_IDENTIFIER] ?? ''));

        foreach ($collection as $page) {
            if ($this->storeCodesOf($page) === $wantedStores) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function storeCodesOf(Page $page): array
    {
        return $this->storeScope->toCodes((array)$page->getData('store_id'));
    }
}
