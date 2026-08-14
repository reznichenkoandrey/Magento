<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Model\Export;

/**
 * Turns one rendered legacy-grid cell back into the value it was rendered from.
 *
 * Legacy grid renderers produce HTML, because that is what a grid cell is. The export path reuses
 * them verbatim — renderExport() calls render() — so the CSV and the Excel XML receive markup and
 * HTML entities where a spreadsheet needs the raw value. The transform is the inverse of that
 * rendering, and it happens in this order for a reason:
 *
 *   1. Line breaks become spaces. A <br/> is a renderer's way of stacking two values in one cell;
 *      dropping it silently would glue them together ("BagsGear"). A space is chosen over a newline
 *      because a newline inside a quoted CSV field is legal but reads differently in every
 *      spreadsheet that opens it, and the cell is a concatenation, not prose.
 *   2. Tags are stripped, before anything is decoded. At this point every angle bracket that came
 *      from the row's data is still an entity — the renderer escaped it — so the only real tags in
 *      the string are the renderer's own. Decoding first would turn a customer's literal "<b>" into
 *      a tag and strip_tags() would then eat it, which is data loss disguised as cleanup.
 *   3. Entities are decoded last, which is what makes "Hoodies &amp; Sweatshirts" arrive as
 *      "Hoodies & Sweatshirts" and "O&#039;Brien" as "O'Brien".
 *
 * One asymmetry is worth stating plainly: Magento's escaper calls htmlspecialchars() with
 * double_encode disabled, so a value whose text is literally the five characters "&amp;" is left
 * exactly as it is — which is also what a single "&" escapes to. The two are the same string by the
 * time this class is called, and nothing downstream can tell them apart again. Decoding is the
 * inverse of an escape that is not injective.
 */
class ValueDecoder
{
    /**
     * Matches the three spellings of a line break that core renderers emit.
     */
    private const LINE_BREAK_PATTERN = '#<br\s*/?>#i';

    private const LINE_BREAK_REPLACEMENT = ' ';

    private const DECODE_FLAGS = ENT_QUOTES | ENT_HTML5;

    private const CHARSET = 'UTF-8';

    public function decode(string $value): string
    {
        $hasMarkup = str_contains($value, '<');

        // Most cells are a number or a plain word. Neither branch below can change them, and the
        // export loop runs this once per cell per row.
        if (!$hasMarkup && !str_contains($value, '&')) {
            return $value;
        }

        if ($hasMarkup) {
            $withoutBreaks = preg_replace(self::LINE_BREAK_PATTERN, self::LINE_BREAK_REPLACEMENT, $value);
            // A failed match returns null; keeping the original is the safe reading of "unchanged".
            $value = trim(strip_tags($withoutBreaks ?? $value));
        }

        return html_entity_decode($value, self::DECODE_FLAGS, self::CHARSET);
    }
}
