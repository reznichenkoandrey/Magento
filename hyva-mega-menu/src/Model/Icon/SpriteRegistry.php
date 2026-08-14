<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Model\Icon;

/**
 * The symbol set that gets inlined into the page as one `<svg>` sprite.
 *
 * The markup is constant data owned by this module — it is never assembled from anything a
 * merchant or a shopper can type — which is why the template prints it unescaped. Escaping it
 * would print the path data as text; the safety property comes from where the strings originate,
 * not from an escaper, and that is worth stating out loud next to the one `<?= ?>` in this module
 * that has no escaper on it.
 *
 * Every symbol is drawn on a 24×24 grid with `fill="none"` and no colour of its own, so the
 * `stroke="currentColor"` on the wrapping `<svg>` is what tints it. That is the whole reason the
 * sprite exists rather than a set of image files: an icon that inherits the text colour follows
 * hover, focus and the active-branch state for free, with no second asset and no JavaScript.
 *
 * The chrome keys (chevrons, hamburger, close) are in the same sprite as the category keys on
 * purpose. They are injected by the same mechanism, so there is one place where SVG enters the
 * page and one thing to audit.
 */
class SpriteRegistry
{
    public const CHEVRON_RIGHT = 'chevron-right';
    public const CHEVRON_DOWN = 'chevron-down';
    public const MENU = 'menu';
    public const CLOSE = 'close';

    /**
     * Symbols that exist for the chrome and are always injected, whatever the catalogue uses.
     */
    private const CHROME_KEYS = [self::CHEVRON_RIGHT, self::CHEVRON_DOWN, self::MENU, self::CLOSE];

    /**
     * @var array<string, string> sprite key => inner SVG markup of its `<symbol>`
     */
    private const SYMBOLS = [
        self::CHEVRON_RIGHT => '<path d="M9 5l7 7-7 7"/>',
        self::CHEVRON_DOWN => '<path d="M5 9l7 7 7-7"/>',
        self::MENU => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        self::CLOSE => '<path d="M6 6l12 12M18 6L6 18"/>',
        'home' => '<path d="M4 11l8-7 8 7v8a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z"/>',
        'tag' => '<path d="M4 4h7l9 9-7 7-9-9z"/><circle cx="8.5" cy="8.5" r="1.5"/>',
        'bag' => '<path d="M6 8h12l-1 12H7z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'shirt' => '<path d="M8 4l4 2 4-2 4 3-2 3-1-1v11H7V9L6 10 4 7z"/>',
        'gift' => '<path d="M4 11h16v9H4z"/><path d="M3 8h18v3H3z"/><path d="M12 8v12"/>'
            . '<path d="M12 8c-3 0-5-1-5-2.5S8 3 9.5 3 12 5.5 12 8z"/>'
            . '<path d="M12 8c3 0 5-1 5-2.5S16 3 14.5 3 12 5.5 12 8z"/>',
        'sparkle' => '<path d="M12 4l1.8 4.2L18 10l-4.2 1.8L12 16l-1.8-4.2L6 10l4.2-1.8z"/>'
            . '<path d="M18 16l.9 2.1L21 19l-2.1.9L18 22l-.9-2.1L15 19l2.1-.9z"/>',
        'truck' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/>'
            . '<circle cx="7.5" cy="17.5" r="1.5"/><circle cx="17.5" cy="17.5" r="1.5"/>',
        'percent' => '<path d="M6 18L18 6"/><circle cx="8" cy="8" r="2"/><circle cx="16" cy="16" r="2"/>',
    ];

    public function has(string $key): bool
    {
        return isset(self::SYMBOLS[$key]);
    }

    /**
     * @return string[] every key a merchant may type into the category attribute
     */
    public function getCategoryKeys(): array
    {
        return array_values(array_diff(array_keys(self::SYMBOLS), self::CHROME_KEYS));
    }

    /**
     * The symbols to inline for this page: the chrome, plus whatever the menu actually used.
     *
     * Unused symbols are left out rather than shipped and hidden. The sprite lands in the HTML of
     * every cached page, so the bytes are paid for on every request, forever — and a catalogue
     * that tags three categories has no reason to carry twelve icons.
     *
     * @param string[] $usedKeys
     * @return array<string, string> sprite key => inner SVG markup, in a stable order
     */
    public function getSymbolsFor(array $usedKeys): array
    {
        $keys = array_unique(array_merge(self::CHROME_KEYS, $usedKeys));
        $symbols = [];

        foreach (array_keys(self::SYMBOLS) as $key) {
            if (in_array($key, $keys, true)) {
                $symbols[$key] = self::SYMBOLS[$key];
            }
        }

        return $symbols;
    }
}
