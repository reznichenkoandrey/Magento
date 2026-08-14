<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

/**
 * The three link types this module owns, and the ids reserved for them.
 *
 * `catalog_product_link_type.link_type_id` is an auto-increment column, so a module that seeds a new
 * type has two options: let MySQL pick the id and resolve it by code at runtime, or reserve one.
 * Core reserves — `Magento\Catalog\Setup\Patch\Data\InstallDefaultCategories` writes its three rows
 * with `insertForce()` against `Magento\Catalog\Model\Product\Link::LINK_TYPE_RELATED` (1),
 * `LINK_TYPE_UPSELL` (4) and `LINK_TYPE_CROSSSELL` (5), and
 * `Magento\GroupedProduct\Setup\Patch\Data\InitializeGroupedProductLinks` reserves 3 for `super` the
 * same way (with `insertOnDuplicate` rather than `insertForce`). The reason is visible in
 * `Magento\RelatedProductGraphQl\Model\Resolver\Batch\AbstractLikedProducts`: its `getLinkType()` is
 * declared `: int`, so a consumer of a link type wants a compile-time constant, not a lookup.
 *
 * So this module reserves too, in a range core has never used. The trade is that a reserved id can
 * collide with another extension's, which is why the install patch refuses rather than guesses —
 * see `Setup\Patch\Data\InstallFamilyLinkTypes`.
 */
final class FamilyLinkType
{
    public const LINK_TYPE_OTHER_COLORS = 21;

    public const LINK_TYPE_OTHER_SIZES = 22;

    public const LINK_TYPE_SIMILAR = 23;

    /**
     * The link-type codes as they are stored in `catalog_product_link_type.code`, which is a
     * `varchar(32)` — every code below has to stay inside that.
     */
    public const CODE_OTHER_COLORS = 'scr1be_other_colors';

    public const CODE_OTHER_SIZES = 'scr1be_other_sizes';

    public const CODE_SIMILAR = 'scr1be_similar';

    /**
     * Every link type carries a `position` attribute in `catalog_product_link_attribute`, with its
     * values in `catalog_product_link_attribute_int`. Core seeds exactly one such row per type and
     * so do we — the sort order of a family row is the whole point of the module.
     */
    public const POSITION_ATTRIBUTE_CODE = 'position';

    public const POSITION_ATTRIBUTE_DATA_TYPE = 'int';

    /**
     * Family code (the configuration group, the CLI argument, the GraphQL field suffix) mapped to
     * the reserved link type id and the link type code.
     *
     * @var array<string, array{id: int, code: string}>
     */
    private const TYPES = [
        'other_colors' => ['id' => self::LINK_TYPE_OTHER_COLORS, 'code' => self::CODE_OTHER_COLORS],
        'other_sizes' => ['id' => self::LINK_TYPE_OTHER_SIZES, 'code' => self::CODE_OTHER_SIZES],
        'similar' => ['id' => self::LINK_TYPE_SIMILAR, 'code' => self::CODE_SIMILAR],
    ];

    /**
     * @return string[] family codes in the order they are rendered on the product page
     */
    public function getFamilyCodes(): array
    {
        return array_keys(self::TYPES);
    }

    public function isFamilyCode(string $familyCode): bool
    {
        return isset(self::TYPES[$familyCode]);
    }

    public function getLinkTypeId(string $familyCode): int
    {
        if (!isset(self::TYPES[$familyCode])) {
            throw new \InvalidArgumentException(sprintf('Unknown product family code "%s".', $familyCode));
        }

        return self::TYPES[$familyCode]['id'];
    }

    public function getLinkTypeCode(string $familyCode): string
    {
        if (!isset(self::TYPES[$familyCode])) {
            throw new \InvalidArgumentException(sprintf('Unknown product family code "%s".', $familyCode));
        }

        return self::TYPES[$familyCode]['code'];
    }

    /**
     * @return array<int, string> reserved link type id => link type code
     */
    public function getReservedTypes(): array
    {
        $reserved = [];
        foreach (self::TYPES as $type) {
            $reserved[$type['id']] = $type['code'];
        }

        return $reserved;
    }
}
