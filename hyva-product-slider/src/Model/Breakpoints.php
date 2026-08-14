<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model;

use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;

/**
 * The four breakpoints a slider can be configured for, and the arithmetic that keeps them sane.
 *
 * The names are deliberately the module's own rather than Tailwind's, because the *values* are
 * Tailwind's and would be a lie to hardcode twice: `tablet`, `desktop` and `wide` line up with the
 * `sm`, `lg` and `xl` widths that `tailwindcss/theme.css` defines as `--breakpoint-sm: 40rem`,
 * `--breakpoint-lg: 64rem` and `--breakpoint-xl: 80rem`. The stylesheet writes the media queries; PHP
 * only ever emits counts, keyed by these codes, so the two halves cannot disagree about how many
 * slides fit.
 */
class Breakpoints
{
    public const MOBILE = 'mobile';
    public const TABLET = 'tablet';
    public const DESKTOP = 'desktop';
    public const WIDE = 'wide';

    /**
     * A carousel showing zero slides is a bug, and one showing more than eight is a grid with extra
     * steps. Both ends are clamped rather than rejected: an admin who types 40 gets a wide slider,
     * not a validation error on an unrelated save.
     */
    public const MIN_SLIDES = 1;
    public const MAX_SLIDES = 8;

    /** Column on `scr1be_slider` that stores each breakpoint's count. */
    private const COLUMNS = [
        self::MOBILE => SliderInterface::SLIDES_MOBILE,
        self::TABLET => SliderInterface::SLIDES_TABLET,
        self::DESKTOP => SliderInterface::SLIDES_DESKTOP,
        self::WIDE => SliderInterface::SLIDES_WIDE,
    ];

    private const DEFAULTS = [
        self::MOBILE => 1,
        self::TABLET => 2,
        self::DESKTOP => 4,
        self::WIDE => 5,
    ];

    /**
     * @return string[]
     */
    public function getCodes(): array
    {
        return array_keys(self::COLUMNS);
    }

    /**
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return self::COLUMNS;
    }

    public function getColumn(string $code): ?string
    {
        return self::COLUMNS[$code] ?? null;
    }

    public function getDefault(string $code): int
    {
        return self::DEFAULTS[$code] ?? self::MIN_SLIDES;
    }

    /**
     * The custom property the stylesheet reads for one breakpoint.
     *
     * Emitted into a `style` attribute on the slider root, so a hardened `style-src` that drops it
     * leaves the stylesheet's own defaults in place — a slider with the wrong number of columns, not
     * a slider with none.
     */
    public function getCssVariable(string $code): string
    {
        return '--scr1be-slides-' . $code;
    }

    /**
     * @param array<string, int|string|null> $counts
     * @return array<string, int> Every code present, every value inside the clamp.
     */
    public function normalise(array $counts): array
    {
        $normalised = [];
        foreach ($this->getCodes() as $code) {
            $value = isset($counts[$code]) && is_numeric($counts[$code])
                ? (int) $counts[$code]
                : $this->getDefault($code);

            $normalised[$code] = max(self::MIN_SLIDES, min(self::MAX_SLIDES, $value));
        }

        return $normalised;
    }

    /**
     * The largest configured count, which is how many slides have to exist before the carousel can
     * scroll at all. A four-across slider holding four products is a row, and it gets no arrows.
     *
     * @param array<string, int> $counts
     */
    public function getWidest(array $counts): int
    {
        $normalised = $this->normalise($counts);

        return max($normalised);
    }
}
