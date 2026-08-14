<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\ProductLink;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductLink\CollectionProviderInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\ProductFamilies\Model\ResourceModel\FamilyLinkReader;

/**
 * Answers "which products are linked to this one" for the three family link types.
 *
 * Registering a link type with `Magento\Catalog\Model\Product\LinkTypeProvider` is only half the
 * contract, and the missing half is not optional. `Magento\Catalog\Model\ProductLink\CollectionProvider`
 * is asked for a collection *per registered type* on the product-save path, and its `getCollection()`
 * throws `NoSuchEntityException("The collection provider isn't registered.")` for any type absent
 * from its own `providers` map. A type registered on one side and not the other therefore does not
 * degrade to "no links" — it makes every save through `ProductRepository` fail, for every product in
 * the catalogue, whether or not that product has a family.
 *
 * This is not a theoretical failure: it took down the sample-data import on a clean install, where
 * the first product save after the module was enabled aborted the whole data-patch transaction.
 *
 * The links themselves are read straight from the pivot table rather than through the product model.
 * `Product::getRelatedProducts()` and its siblings exist because core keeps one accessor per built-in
 * type; there is no generic accessor to inherit, and adding three magic getters to the product model
 * to satisfy an interface that only needs an array would be a worse trade.
 */
class FamilyCollectionProvider implements CollectionProviderInterface
{
    /**
     * @param FamilyLinkReader $linkReader Reads the pivot table for one link type.
     * @param ProductRepositoryInterface $productRepository
     * @param int $linkTypeId One of Scr1be\ProductFamilies\Model\FamilyLinkType's LINK_TYPE_* ids.
     */
    public function __construct(
        private readonly FamilyLinkReader $linkReader,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly int $linkTypeId
    ) {
    }

    /**
     * @param Product $product
     * @return Product[] Position-ordered, and empty for a product with no family.
     */
    public function getLinkedProducts(Product $product): array
    {
        try {
            $linkedIds = $this->linkReader->getLinkedProductIds(
                (int) $product->getId(),
                $this->linkTypeId
            );
        } catch (LocalizedException) {
            // The reader raises when the link type has no position attribute yet — the state
            // between this module being enabled and its data patch running. That is worth shouting
            // about on the reconcile path, and worth ignoring here: this method is called for every
            // registered type on every product save, so a throw would mean no product in the
            // catalogue can be saved until setup:upgrade completes. A feature that is not installed
            // yet has no links, which is exactly what an empty array says.
            return [];
        }

        if ($linkedIds === []) {
            return [];
        }

        $linked = [];
        foreach ($linkedIds as $linkedId) {
            try {
                $linked[] = $this->productRepository->getById((int) $linkedId, false, (int) $product->getStoreId());
            } catch (NoSuchEntityException) {
                // A link surviving its product is possible between a delete and the next reconcile.
                // Skipping it keeps the family readable instead of failing the whole product load.
                continue;
            }
        }

        return $linked;
    }
}
