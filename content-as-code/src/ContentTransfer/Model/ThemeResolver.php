<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;

/**
 * Theme id ↔ full path (`frontend/Magento/luma`).
 *
 * Same story as store ids: `theme.theme_id` is an autoincrement assigned in the order themes were
 * registered, and a `widget_instance` row or a page's `custom_theme` points at one. The full path is
 * the theme's name in code and is identical on every install that has the theme at all.
 *
 * `ThemeProviderInterface::getThemeByFullPath()` returns an object either way — an unsaved, empty
 * theme when nothing matches — so callers here check the id rather than the null the interface never
 * returns.
 */
class ThemeResolver
{
    public function __construct(
        private readonly ThemeProviderInterface $themeProvider
    ) {
    }

    /**
     * @throws LocalizedException when the theme id points at nothing; a widget instance without a
     *         theme cannot be captured meaningfully and guessing one would put the widget on the
     *         wrong storefront.
     */
    public function toFullPath(int $themeId): string
    {
        $theme = $this->themeProvider->getThemeById($themeId);

        if (!$theme->getId()) {
            throw new LocalizedException(new Phrase('Theme %1 does not exist on this install.', [$themeId]));
        }

        return (string)$theme->getFullPath();
    }

    /**
     * @throws LocalizedException when the bundle names a theme this install does not have. Falling
     *         back to the default theme would place content on a storefront nobody asked for, which
     *         is harder to notice than an import that says so.
     */
    public function toId(string $fullPath): int
    {
        $theme = $this->themeProvider->getThemeByFullPath($fullPath);

        if (!$theme->getId()) {
            throw new LocalizedException(
                new Phrase('Theme "%1" is not installed here; install it before applying this bundle.', [$fullPath])
            );
        }

        return (int)$theme->getId();
    }
}
