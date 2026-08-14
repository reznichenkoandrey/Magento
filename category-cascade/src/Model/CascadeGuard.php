<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Model;

use Magento\Catalog\Model\Category;

/**
 * The transition guard: the whole module hangs off the question this class answers.
 *
 * `catalog_category_save_commit_after` fires for every category save in the installation —
 * renames, position changes, image uploads, imports, a data patch touching one attribute. Exactly
 * one of those is a cascade: a category that *was* enabled and is *now* disabled. Everything else
 * has to leave the subtree alone, and the cheapest place to enforce that is before a single row is
 * read.
 */
class CascadeGuard
{
    /**
     * Level 0 is the tree root, level 1 is a store's root category ("Default Category" on Luma).
     * Neither is a merchandising decision — disabling one is how a store gets taken offline, and
     * cascading from there would rewrite every category row in the catalog on one click. Level 2
     * is the first level a merchant actually curates, so it is the first level worth cascading.
     */
    public const MIN_CASCADE_LEVEL = 2;

    private const ATTRIBUTE_IS_ACTIVE = 'is_active';
    private const PATH_SEPARATOR = '/';

    public function __construct(
        private readonly Config $config
    ) {
    }

    public function shouldCascade(Category $category): bool
    {
        if (!$this->config->isCascadeEnabled((int) $category->getStoreId())) {
            return false;
        }

        if (!$category->getId() || $this->isNewCategory($category)) {
            return false;
        }

        if ($this->resolveLevel($category) < self::MIN_CASCADE_LEVEL) {
            return false;
        }

        return $this->isDisableTransition($category);
    }

    /**
     * A category created in this request has an id by the time the commit callback runs, so
     * isObjectNew() alone is not enough to recognise it. The reliable marker is the absence of a
     * pre-save snapshot: _origData is only populated by a load, so a null snapshot means the model
     * was never loaded, which means nothing was disabled — it was created.
     */
    private function isNewCategory(Category $category): bool
    {
        return $category->isObjectNew() || $category->getOrigData() === null;
    }

    /**
     * Enabled → disabled, and nothing else.
     *
     * Re-enabling deliberately does not cascade back: a merchant who disabled "Women" last month
     * and disabled two of its subcategories the month before has expressed two decisions, and
     * turning the parent back on is not a request to undo the other one. The asymmetry is the
     * point — a cascade that reverses itself would silently republish categories nobody asked to
     * republish, which is a worse failure than an extra click.
     *
     * Both halves are read defensively. A save that does not carry `is_active` at all (a partial
     * save, an import touching one other attribute) is not a transition, and a snapshot without
     * the attribute means the module cannot know what the previous state was — in both cases the
     * safe answer is "no cascade".
     */
    private function isDisableTransition(Category $category): bool
    {
        if (!$category->hasData(self::ATTRIBUTE_IS_ACTIVE)) {
            return false;
        }

        $previous = $category->getOrigData(self::ATTRIBUTE_IS_ACTIVE);
        if ($previous === null) {
            return false;
        }

        return (bool) (int) $previous && !(bool) (int) $category->getData(self::ATTRIBUTE_IS_ACTIVE);
    }

    /**
     * `level` is a plain column and is set on every loaded category, but a save assembled by hand
     * (an integration building a category from an array) can omit it. The path always survives —
     * the tree cannot be written without it — so it is the safer source of the same number.
     */
    private function resolveLevel(Category $category): int
    {
        $level = $category->getLevel();
        if ($level !== null && $level !== '') {
            return (int) $level;
        }

        return substr_count((string) $category->getPath(), self::PATH_SEPARATOR);
    }
}
