<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Widget;

use Magento\Widget\Model\Widget\Instance;
use Scr1be\ContentTransfer\Model\Slug;
use Scr1be\ContentTransfer\Model\StoreScope;
use Scr1be\ContentTransfer\Model\ThemeResolver;

/**
 * Widget instance ↔ payload, shared by every porter that ships widget instances.
 *
 * Extracted because a second porter exists: the coupon-ticket module claims its own widget type so
 * it can make a sales-rule id portable, and it has no business re-deriving how `page_groups` are
 * shaped. Composition rather than inheritance — the specific porter *has* a codec, so it can call
 * it and then rewrite one parameter, which subclassing would not make any cleaner.
 *
 * ### The page-groups shape, which is two different shapes
 *
 * Reading and writing a widget instance's placements are not symmetrical, and this is the trap the
 * class exists to contain.
 *
 * `Magento\Widget\Model\ResourceModel\Widget\Instance::_afterLoad()` sets `page_groups` to the raw
 * rows of `widget_instance_page`, whose columns are `page_id`, `page_group`, `layout_handle`,
 * `block_reference`, `page_for`, `entities`, `page_template`.
 *
 * `Magento\Widget\Model\Widget\Instance::beforeSave()` expects something else entirely: a list where
 * each element has a `page_group` key naming the group, plus a nested array **under that name**
 * holding `page_id`, `layout_handle`, `for`, `block`, `template` and `entities`. That is the shape
 * the admin form posts. Hand `beforeSave()` the rows you loaded and it finds no
 * `$pageGroup[$pageGroup['page_group']]`, writes an empty `page_groups`, and the widget saves with
 * every placement removed — a silent, total data loss that looks like a successful save.
 *
 * So `toPayload()` writes the neutral shape, and `applyPayload()` builds the form shape from it.
 *
 * ### `page_id` is deliberately dropped
 *
 * It is the primary key of `widget_instance_page`, not a CMS page. On import it is set empty, which
 * puts it outside `page_group_ids` in `beforeSave()`; the resource model's `_afterSave()` then
 * removes the instance's existing placement rows and inserts fresh ones. That is what makes
 * replacing an instance produce exactly the placements in the bundle rather than the union of old
 * and new.
 */
class InstanceCodec
{
    public const KEY_TITLE = 'title';
    public const KEY_TYPE = 'instance_type';
    public const KEY_THEME = 'theme';
    public const KEY_STORES = 'stores';
    public const KEY_SORT_ORDER = 'sort_order';
    public const KEY_PARAMETERS = 'parameters';
    public const KEY_PAGE_GROUPS = 'page_groups';

    public const GROUP_KEY_GROUP = 'group';
    public const GROUP_KEY_LAYOUT_HANDLE = 'layout_handle';
    public const GROUP_KEY_BLOCK = 'block_reference';
    public const GROUP_KEY_FOR = 'for';
    public const GROUP_KEY_ENTITIES = 'entities';
    public const GROUP_KEY_TEMPLATE = 'template';

    /**
     * `Magento\Widget\Model\Widget\Instance::SPECIFIC_ENTITIES`. Placements with this value carry
     * numeric category or product ids in `entities`, which no amount of rewriting makes portable.
     */
    private const FOR_SPECIFIC = Instance::SPECIFIC_ENTITIES;

    public function __construct(
        private readonly StoreScope $storeScope,
        private readonly ThemeResolver $themeResolver,
        private readonly Slug $slug
    ) {
    }

    /**
     * The bundle key for an instance: `<widget class basename>--<title slug>`.
     *
     * `widget_instance` has no identifier column — `Magento_Widget/etc/db_schema.xml` declares
     * `instance_id`, `instance_type`, `theme_id`, `title`, `store_ids`, `widget_parameters` and
     * `sort_order`, and nothing else — so a key has to be synthesised from what is there. Type and
     * title are the two fields an admin actually distinguishes instances by, and they are also what
     * `apply()` matches on, which keeps the key honest: two instances that collide on it are two
     * instances the importer could not tell apart either.
     */
    public function identifierFor(Instance $instance): string
    {
        return $this->typeSlug((string)$instance->getType()) . '--'
            . $this->slug->of((string)$instance->getTitle());
    }

    public function typeSlug(string $instanceType): string
    {
        $parts = explode('\\', trim($instanceType, '\\'));

        return $this->slug->of((string)end($parts));
    }

    /**
     * `Instance::getStoreIds()` explodes the comma-separated `store_ids` column, so this works on a
     * collection item as well as on a fully loaded instance.
     *
     * @return string[]
     */
    public function storeCodesOf(Instance $instance): array
    {
        return $this->storeScope->toCodes((array)$instance->getStoreIds());
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException when the theme is not installed here.
     */
    public function themeIdFor(string $fullPath): int
    {
        return $this->themeResolver->toId($fullPath);
    }

    /**
     * @return array<string, mixed>
     * @throws \Magento\Framework\Exception\LocalizedException when the instance's theme is gone.
     */
    public function toPayload(Instance $instance): array
    {
        return [
            self::KEY_TITLE => (string)$instance->getTitle(),
            self::KEY_TYPE => (string)$instance->getType(),
            self::KEY_THEME => $this->themeResolver->toFullPath((int)$instance->getThemeId()),
            self::KEY_STORES => $this->storeScope->toCodes((array)$instance->getStoreIds()),
            self::KEY_SORT_ORDER => (int)$instance->getSortOrder(),
            self::KEY_PARAMETERS => $instance->getWidgetParameters(),
            self::KEY_PAGE_GROUPS => $this->toPortablePageGroups((array)$instance->getData('page_groups')),
        ];
    }

    /**
     * Placements a bundle cannot carry: anything scoped to specific categories or products, whose
     * `entities` column holds autoincrement ids from the source install.
     *
     * @return string[]
     */
    public function unportablePlacements(Instance $instance, string $label): array
    {
        $warnings = [];

        foreach ($this->toPortablePageGroups((array)$instance->getData('page_groups')) as $group) {
            if ($group[self::GROUP_KEY_FOR] !== self::FOR_SPECIFIC) {
                continue;
            }

            $warnings[] = sprintf(
                '%s: placement "%s" is limited to specific entities (%s). Those are category or '
                . 'product ids from this install and were captured as-is; re-pick them after import.',
                $label,
                $group[self::GROUP_KEY_GROUP],
                $group[self::GROUP_KEY_ENTITIES]
            );
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws \Magento\Framework\Exception\LocalizedException when the theme is not installed here.
     */
    public function applyPayload(Instance $instance, array $payload): void
    {
        $instance->setType((string)($payload[self::KEY_TYPE] ?? ''));
        $instance->setThemeId($this->themeResolver->toId((string)($payload[self::KEY_THEME] ?? '')));
        $instance->setTitle((string)($payload[self::KEY_TITLE] ?? ''));
        $instance->setSortOrder((int)($payload[self::KEY_SORT_ORDER] ?? 0));

        // `beforeSave()` implodes an array of store ids into the comma-separated string the column
        // holds, so the array form is what to hand it.
        $instance->setStoreIds($this->storeScope->toIds((array)($payload[self::KEY_STORES] ?? [])));

        // Also serialised by `beforeSave()`, from an array. Passing the already-serialised string
        // would double-encode it.
        $instance->setData('widget_parameters', (array)($payload[self::KEY_PARAMETERS] ?? []));

        $instance->setData(
            'page_groups',
            $this->toAdminFormPageGroups((array)($payload[self::KEY_PAGE_GROUPS] ?? []))
        );
    }

    /**
     * Raw `widget_instance_page` rows → the neutral shape a bundle stores.
     *
     * Public, and taking rows rather than an instance, because this and its counterpart below are
     * the two halves of the asymmetry described in the class docblock — the part worth a test that
     * does not need fifteen constructor arguments to run.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    public function toPortablePageGroups(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[] = [
                self::GROUP_KEY_GROUP => (string)($row['page_group'] ?? ''),
                self::GROUP_KEY_LAYOUT_HANDLE => (string)($row['layout_handle'] ?? ''),
                self::GROUP_KEY_BLOCK => (string)($row['block_reference'] ?? ''),
                self::GROUP_KEY_FOR => (string)($row['page_for'] ?? Instance::ALL_ENTITIES),
                self::GROUP_KEY_ENTITIES => (string)($row['entities'] ?? ''),
                self::GROUP_KEY_TEMPLATE => (string)($row['page_template'] ?? ''),
            ];
        }

        // Placement order is not meaningful to Magento and comes back in whatever order the table
        // scan produced; sorting it keeps a re-capture byte-identical.
        usort(
            $groups,
            static fn (array $left, array $right): int => [
                $left[self::GROUP_KEY_GROUP],
                $left[self::GROUP_KEY_LAYOUT_HANDLE],
                $left[self::GROUP_KEY_BLOCK],
            ] <=> [
                $right[self::GROUP_KEY_GROUP],
                $right[self::GROUP_KEY_LAYOUT_HANDLE],
                $right[self::GROUP_KEY_BLOCK],
            ]
        );

        return $groups;
    }

    /**
     * The neutral shape → the shape `Instance::beforeSave()` expects, which is the shape the admin
     * form posts.
     *
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    public function toAdminFormPageGroups(array $groups): array
    {
        $shaped = [];

        foreach ($groups as $group) {
            $name = (string)($group[self::GROUP_KEY_GROUP] ?? '');

            if ($name === '') {
                continue;
            }

            $shaped[] = [
                'page_group' => $name,
                $name => [
                    // Empty, never the captured value: see the class docblock.
                    'page_id' => '',
                    'layout_handle' => (string)($group[self::GROUP_KEY_LAYOUT_HANDLE] ?? ''),
                    'for' => (string)($group[self::GROUP_KEY_FOR] ?? Instance::ALL_ENTITIES),
                    'block' => (string)($group[self::GROUP_KEY_BLOCK] ?? ''),
                    'entities' => (string)($group[self::GROUP_KEY_ENTITIES] ?? ''),
                    'template' => (string)($group[self::GROUP_KEY_TEMPLATE] ?? ''),
                ],
            ];
        }

        return $shaped;
    }
}
