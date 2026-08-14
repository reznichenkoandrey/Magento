<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Exclusion;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Applies a rule set to a ranked list of product ids and hands back the survivors, in order.
 *
 * Two things about this class are decisions rather than plumbing.
 *
 * **The rules are evaluated in PHP, not pushed into the collection's WHERE clause.** Translating
 * seven operators and two match modes into `addAttributeToFilter()` is possible and would be
 * faster, and it is the wrong trade here: the candidate list is already capped by the source's
 * limit, so the collection is tens of rows, while a mistranslated filter on a left-joined EAV table
 * is a silent wrong answer. The comparison logic lives in `Rule`, where it is a pure function and
 * therefore testable without a database.
 *
 * **Values are read in the default scope.** `catalog_category_product` has no store column, so
 * membership is a global fact and the attribute values that decide it have to be the global ones.
 * Reading a store view's overrides would make the answer depend on which store view happened to run
 * the reconcile — and there is only one row to write either way.
 */
class ProductFilter
{
    public function __construct(
        private readonly CollectionFactory $productCollectionFactory,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int[] $productIds Ranked.
     * @return int[] The same ids, same order, minus the excluded ones.
     */
    public function apply(array $productIds, RuleSet $ruleSet): array
    {
        if ($productIds === [] || $ruleSet->isEmpty()) {
            return array_values($productIds);
        }

        $attributeCodes = $this->resolveAttributeCodes($ruleSet->getAttributeCodes());

        if ($attributeCodes === []) {
            // Every rule names an attribute that does not exist. Excluding nothing is the safe
            // reading: the alternative is a set of rules that all fail to match, which under All
            // would exclude nothing anyway and under Any would also exclude nothing — but arriving
            // there by loading a collection of unknown columns would have thrown instead.
            return array_values($productIds);
        }

        $values = $this->loadAttributeValues($productIds, $attributeCodes);

        return array_values(
            array_filter(
                $productIds,
                static fn (int $productId): bool => !$ruleSet->excludes($values[$productId] ?? [])
            )
        );
    }

    /**
     * Drop codes with no attribute behind them.
     *
     * `Collection::addAttributeToSelect()` on an unknown code raises a `LocalizedException` deep
     * inside the EAV resource, and a single mistyped code in the admin form would take the whole
     * hourly reconcile down with it. A rule naming an attribute that does not exist is a rule that
     * cannot match anything, so it is logged and skipped.
     *
     * @param string[] $codes
     * @return string[]
     */
    private function resolveAttributeCodes(array $codes): array
    {
        $resolved = [];

        foreach ($codes as $code) {
            try {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            } catch (\Throwable $exception) {
                $attribute = null;
            }

            if ($attribute === null || !$attribute->getAttributeId()) {
                $this->logger->warning(
                    sprintf('Curated categories: exclusion rule names unknown product attribute "%s".', $code)
                );

                continue;
            }

            $resolved[] = $code;
        }

        return $resolved;
    }

    /**
     * @param int[] $productIds
     * @param string[] $attributeCodes
     * @return array<int, array<string, mixed>> productId => [code => value]
     */
    private function loadAttributeValues(array $productIds, array $attributeCodes): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId(Store::DEFAULT_STORE_ID)
            ->addAttributeToSelect($attributeCodes)
            ->addFieldToFilter('entity_id', ['in' => $productIds]);

        $values = [];

        foreach ($collection as $product) {
            $row = [];

            foreach ($attributeCodes as $code) {
                $row[$code] = $product->getData($code);
            }

            $values[(int) $product->getId()] = $row;
        }

        return $values;
    }
}
