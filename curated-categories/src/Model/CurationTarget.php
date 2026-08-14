<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model;

use Scr1be\CuratedCategories\Api\Data\CurationTargetInterface;

/**
 * Immutable target. Built by the sources through the ObjectManager factory, never mutated after.
 */
class CurationTarget implements CurationTargetInterface
{
    public function __construct(
        private readonly int $categoryId,
        private readonly int $minimumFloor,
        private readonly string $sourceCode
    ) {
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getMinimumFloor(): int
    {
        return $this->minimumFloor;
    }

    public function getSourceCode(): string
    {
        return $this->sourceCode;
    }
}
