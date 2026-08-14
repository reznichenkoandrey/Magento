<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

/**
 * One resolved family: everything the pipeline needs to know about "other colours", read once from
 * configuration and then passed down unchanged.
 *
 * A family is defined by two attributes, not one. The *group* attribute is the family key — products
 * sharing its value belong together. The *variant* attribute is what separates them inside the
 * family, and it is the one that decides the order of the rendered row: positions come from the sort
 * order the merchant gave the attribute's options in the admin, so a size row reads XS · S · M · L
 * rather than whatever order the ids happen to have.
 *
 * The variant attribute is optional. With none configured the family degenerates to "everything with
 * the same group value", ordered by product id — which is exactly what the "similar products" family
 * wants, and the reason the third family needs no code of its own.
 */
final class FamilyDefinition
{
    public function __construct(
        private readonly string $familyCode,
        private readonly int $linkTypeId,
        private readonly string $groupAttribute,
        private readonly string $variantAttribute,
        private readonly int $maxMembers,
        private readonly bool $distinctVariants,
        private readonly string $label
    ) {
    }

    public function getFamilyCode(): string
    {
        return $this->familyCode;
    }

    public function getLinkTypeId(): int
    {
        return $this->linkTypeId;
    }

    public function getGroupAttribute(): string
    {
        return $this->groupAttribute;
    }

    public function getVariantAttribute(): string
    {
        return $this->variantAttribute;
    }

    public function hasVariantAttribute(): bool
    {
        return $this->variantAttribute !== '';
    }

    public function getMaxMembers(): int
    {
        return $this->maxMembers;
    }

    /**
     * When true, a family keeps at most one member per variant value. A colour row listing "Black"
     * three times because three SKUs share it is noise; a size row usually is not, because the sizes
     * are already distinct. So this is per family rather than global.
     */
    public function isDistinctVariants(): bool
    {
        return $this->distinctVariants;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
