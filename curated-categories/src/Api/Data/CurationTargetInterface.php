<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Api\Data;

/**
 * Where a source's product list is supposed to land, and the one promise the engine makes about it.
 *
 * A target is deliberately not a category: the engine never loads one. Everything it needs is an id
 * it can put in a WHERE clause, the floor it must not go below, and a label to log under.
 */
interface CurationTargetInterface
{
    /**
     * @return int The category the source's products belong to. Zero means "not configured": the
     *             engine refuses the run rather than writing rows nothing can reach.
     */
    public function getCategoryId(): int;

    /**
     * @return int The SEO floor: how many members the category keeps even when the source asks for
     *             fewer. Always at least 1 once it has come through configuration.
     */
    public function getMinimumFloor(): int;

    /**
     * @return string The adapter code, used for log lines and CLI output.
     */
    public function getSourceCode(): string;
}
