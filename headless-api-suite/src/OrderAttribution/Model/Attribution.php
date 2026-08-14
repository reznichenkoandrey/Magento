<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model;

/**
 * What the client said about where this order came from, after validation.
 *
 * Immutable, because it is passed between a plugin and an observer that run in different frames and
 * neither of them owns it.
 */
final class Attribution
{
    /**
     * The order column is varchar(255); anything longer is truncated at the boundary rather than by
     * MySQL, so the value that reaches the database is the value this object reports.
     */
    public const MAX_DETAIL_LENGTH = 255;

    /**
     * @param string $sourceCode
     * @param string|null $detail
     */
    private function __construct(
        public readonly string $sourceCode,
        public readonly ?string $detail
    ) {
    }

    /**
     * Build from already-validated input.
     *
     * @param string $sourceCode
     * @param string|null $detail
     * @return self
     */
    public static function of(string $sourceCode, ?string $detail): self
    {
        $detail = $detail === null ? null : trim($detail);
        if ($detail === '') {
            $detail = null;
        }
        if ($detail !== null && mb_strlen($detail) > self::MAX_DETAIL_LENGTH) {
            $detail = mb_substr($detail, 0, self::MAX_DETAIL_LENGTH);
        }

        return new self($sourceCode, $detail);
    }
}
