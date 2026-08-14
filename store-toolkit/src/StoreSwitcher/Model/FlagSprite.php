<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Model;

/**
 * The flags the shipped sprite can draw, as data rather than as markup.
 *
 * Two things to be honest about.
 *
 * Only flags that are *pure geometry* are here — horizontal or vertical bands of flat colour. A
 * coat of arms, a canton of stars or a saltire is artwork, and shipping a hand-approximated
 * version of a national flag is worse than shipping none, so everything else resolves to a neutral
 * globe. Dropping a full sprite into the theme is one template override; see the README.
 *
 * And flags are countries, not languages: `en` is spoken in a dozen of them and a flag beside a
 * language name tells a Swiss visitor which market they are in, not which language they will get.
 * The switcher therefore always renders the store *name* as the label and treats the flag as
 * decoration — `aria-hidden`, never the only signal.
 */
class FlagSprite
{
    public const ORIENTATION_HORIZONTAL = 'horizontal';
    public const ORIENTATION_VERTICAL = 'vertical';

    /**
     * Code used when a store's region has no shipped flag.
     */
    public const FALLBACK_CODE = 'globe';

    /**
     * Region subtag to band definition. Colours are the flag's own, so they are data, not design
     * tokens: a themed `--color-primary` here would draw the wrong flag.
     *
     * @var array<string, array{orientation: string, colors: string[]}>
     */
    private const FLAGS = [
        'at' => ['orientation' => self::ORIENTATION_HORIZONTAL, 'colors' => ['#ED2939', '#FFFFFF', '#ED2939']],
        'be' => ['orientation' => self::ORIENTATION_VERTICAL, 'colors' => ['#000000', '#FAE042', '#ED2939']],
        'de' => ['orientation' => self::ORIENTATION_HORIZONTAL, 'colors' => ['#000000', '#DD0000', '#FFCE00']],
        'fr' => ['orientation' => self::ORIENTATION_VERTICAL, 'colors' => ['#002395', '#FFFFFF', '#ED2939']],
        'ie' => ['orientation' => self::ORIENTATION_VERTICAL, 'colors' => ['#169B62', '#FFFFFF', '#FF883E']],
        'it' => ['orientation' => self::ORIENTATION_VERTICAL, 'colors' => ['#008C45', '#F4F5F0', '#CD212A']],
        'nl' => ['orientation' => self::ORIENTATION_HORIZONTAL, 'colors' => ['#AE1C28', '#FFFFFF', '#21468B']],
        'pl' => ['orientation' => self::ORIENTATION_HORIZONTAL, 'colors' => ['#FFFFFF', '#DC143C']],
        'ua' => ['orientation' => self::ORIENTATION_HORIZONTAL, 'colors' => ['#0057B7', '#FFD700']],
    ];

    /**
     * The symbol id a store's flag code maps to, always one this sprite actually defines.
     */
    public function resolve(string $flagCode): string
    {
        return isset(self::FLAGS[strtolower($flagCode)]) ? strtolower($flagCode) : self::FALLBACK_CODE;
    }

    /**
     * @return array<string, array{orientation: string, colors: string[]}>
     */
    public function getFlags(): array
    {
        return self::FLAGS;
    }

    /**
     * Only the symbols a page actually references, so a two-store site does not carry nine flags.
     *
     * @param string[] $flagCodes
     * @return array<string, array{orientation: string, colors: string[]}>
     */
    public function getUsedFlags(array $flagCodes): array
    {
        $used = [];

        foreach ($flagCodes as $flagCode) {
            $resolved = $this->resolve($flagCode);

            if ($resolved !== self::FALLBACK_CODE) {
                $used[$resolved] = self::FLAGS[$resolved];
            }
        }

        ksort($used);

        return $used;
    }
}
