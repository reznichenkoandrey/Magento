<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\ViewModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;
use Scr1be\ProductFamilies\Model\FamilyDefinition;
use Scr1be\ProductFamilies\Model\FamilyDefinitionPool;
use Scr1be\ProductFamilies\Model\ResourceModel\FamilyLinkReader;

/**
 * Everything the product page needs about a product's families, in two queries plus one collection.
 *
 * The read is deliberately live — straight off `catalog_product_link`, with no index of the module's
 * own in between. Adding one would buy a marginally cheaper query and pay for it with a second thing
 * that can be stale, on data a nightly reconcile already changes at most once a day.
 *
 * The chips are the module's whole storefront surface, and they are text and colour, not cards. A
 * card row costs an image resize and a price render per member; that shape is what a product slider
 * is for. A family row's job is to answer "does this come in blue" in the width of a thumb.
 */
class ProductFamilies implements ArgumentInterface
{
    /**
     * A visual-colour swatch's value ends up inside a `style` attribute, so it is validated here
     * rather than escaped at the template. `#rrggbb` is the only thing the admin's colour picker
     * produces; anything else in that column arrived some other way and is rendered as a text chip
     * instead of being trusted into CSS.
     */
    private const HEX_COLOR = '/^#[0-9a-fA-F]{6}$/';

    /**
     * @var array<int, array<int, array{code: string, label: string, chips: array}>>
     */
    private array $rowCache = [];

    public function __construct(
        private readonly FamilyDefinitionPool $definitionPool,
        private readonly FamilyLinkReader $linkReader,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly EavConfig $eavConfig,
        private readonly SwatchHelper $swatchHelper,
        private readonly SwatchMedia $swatchMedia
    ) {
    }

    /**
     * @return array<int, array{code: string, label: string, chips: array<int, array{
     *     product_id: int, url: string, name: string, label: string,
     *     swatch_type: string, swatch_value: string
     * }>}>
     */
    public function getRows(ProductInterface $product): array
    {
        $productId = (int)$product->getId();
        if ($productId <= 0) {
            return [];
        }

        if (isset($this->rowCache[$productId])) {
            return $this->rowCache[$productId];
        }

        return $this->rowCache[$productId] = $this->buildRows($productId);
    }

    public function hasRows(ProductInterface $product): bool
    {
        return $this->getRows($product) !== [];
    }

    /**
     * @return array<int, array{code: string, label: string, chips: array}>
     */
    private function buildRows(int $productId): array
    {
        $definitions = $this->definitionPool->getRunnable();
        if ($definitions === []) {
            return [];
        }

        $idsByFamily = [];
        $allIds = [];
        foreach ($definitions as $familyCode => $definition) {
            $linkedIds = $this->linkReader->getLinkedProductIds($productId, $definition->getLinkTypeId());
            if ($linkedIds === []) {
                continue;
            }
            $idsByFamily[$familyCode] = $linkedIds;
            $allIds[] = $linkedIds;
        }

        if ($idsByFamily === []) {
            return [];
        }

        // One collection for every family on the page. Three separate loads would repeat the same
        // EAV joins for products that are frequently in more than one of the rows.
        $members = $this->loadMembers(array_unique(array_merge(...$allIds)), $definitions);
        $swatches = $this->loadSwatches($members, $definitions);

        $rows = [];
        foreach ($idsByFamily as $familyCode => $linkedIds) {
            $chips = [];
            foreach ($linkedIds as $linkedId) {
                if (!isset($members[$linkedId])) {
                    // Disabled, hidden or deleted between the reconcile and this render. The link
                    // table is allowed to be a step behind the catalogue; the page is not.
                    continue;
                }
                $chips[] = $this->buildChip($members[$linkedId], $definitions[$familyCode], $swatches);
            }

            if ($chips === []) {
                continue;
            }

            $rows[] = [
                'code' => $familyCode,
                'label' => $definitions[$familyCode]->getLabel(),
                'chips' => $chips,
            ];
        }

        return $rows;
    }

    /**
     * @param int[] $productIds
     * @param FamilyDefinition[] $definitions
     * @return array<int, Product>
     */
    private function loadMembers(array $productIds, array $definitions): array
    {
        $attributes = ['name', 'url_key'];
        foreach ($definitions as $definition) {
            if ($definition->hasVariantAttribute()) {
                $attributes[] = $definition->getVariantAttribute();
            }
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(array_values(array_unique($attributes)))
            ->addIdFilter($productIds)
            ->addStoreFilter()
            ->addAttributeToFilter(ProductInterface::STATUS, Status::STATUS_ENABLED)
            ->setVisibility([Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH])
            ->addUrlRewrite();

        $members = [];
        foreach ($collection as $member) {
            $members[(int)$member->getId()] = $member;
        }

        return $members;
    }

    /**
     * One swatch lookup for every option on the page.
     *
     * `Magento\Swatches\Helper\Data::getSwatchesByOptionsId()` takes the whole set at once and
     * memoises what it read, so asking for all of them here means the per-chip work below is array
     * access. It returns rows keyed by option id with a `type` and a `value`; options that carry no
     * swatch simply do not come back, which is what makes the text fallback in `buildChip()` the
     * natural default rather than a special case.
     *
     * @param array<int, Product> $members
     * @param FamilyDefinition[] $definitions
     * @return array<int|string, array{type: int|string, value: string}>
     */
    private function loadSwatches(array $members, array $definitions): array
    {
        $optionIds = [];
        foreach ($definitions as $definition) {
            if (!$definition->hasVariantAttribute()) {
                continue;
            }
            foreach ($members as $member) {
                $value = $member->getData($definition->getVariantAttribute());
                if (is_scalar($value) && ctype_digit((string)$value)) {
                    $optionIds[(int)$value] = (int)$value;
                }
            }
        }

        if ($optionIds === []) {
            return [];
        }

        return $this->swatchHelper->getSwatchesByOptionsId(array_values($optionIds));
    }

    /**
     * @param array<int|string, array{type: int|string, value: string}> $swatches
     * @return array{product_id: int, url: string, name: string, label: string,
     *               swatch_type: string, swatch_value: string}
     */
    private function buildChip(Product $member, FamilyDefinition $definition, array $swatches): array
    {
        $name = (string)$member->getName();
        $label = $name;
        $swatchType = 'text';
        $swatchValue = '';

        if ($definition->hasVariantAttribute()) {
            $optionValue = $member->getData($definition->getVariantAttribute());
            $optionLabel = $this->getOptionLabel($definition->getVariantAttribute(), $optionValue);
            if ($optionLabel !== '') {
                $label = $optionLabel;
            }

            $swatch = is_scalar($optionValue) ? ($swatches[(int)$optionValue] ?? null) : null;
            if ($swatch !== null) {
                [$swatchType, $swatchValue] = $this->readSwatch($swatch);
            }
        }

        return [
            'product_id' => (int)$member->getId(),
            'url' => (string)$member->getProductUrl(),
            'name' => $name,
            'label' => $label,
            'swatch_type' => $swatchType,
            'swatch_value' => $swatchValue,
        ];
    }

    /**
     * @param array{type: int|string, value: string} $swatch
     * @return array{0: string, 1: string}
     */
    private function readSwatch(array $swatch): array
    {
        $type = (int)$swatch['type'];
        $value = (string)($swatch['value'] ?? '');

        if ($value === '') {
            return ['text', ''];
        }

        if ($type === Swatch::SWATCH_TYPE_VISUAL_COLOR) {
            return preg_match(self::HEX_COLOR, $value) === 1 ? ['color', $value] : ['text', ''];
        }

        if ($type === Swatch::SWATCH_TYPE_VISUAL_IMAGE) {
            return ['image', $this->swatchMedia->getSwatchAttributeImage(Swatch::SWATCH_IMAGE_NAME, $value)];
        }

        // A textual swatch carries its own display text, which is not always the option label —
        // "XS" against an option called "Extra Small" is the whole point of the type.
        return ['text', $value];
    }

    private function getOptionLabel(string $attributeCode, mixed $value): string
    {
        if (!is_scalar($value) || (string)$value === '') {
            return '';
        }

        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
        if (!$attribute || !$attribute->getAttributeId() || !$attribute->usesSource()) {
            return (string)$value;
        }

        $text = $attribute->getSource()->getOptionText((string)$value);

        return is_scalar($text) ? (string)$text : '';
    }
}
