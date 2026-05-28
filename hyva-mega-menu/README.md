# Hyvä Mega Menu

Responsive mega menu for Magento 2 + Hyvä — built as a **theme override**, with **zero PHP logic**. Pure Alpine.js state + Tailwind layout, driven entirely by the existing `Magento\Theme\Block\Html\Topmenu` category tree.

The goal of this project: show how far you can go on Hyvä without writing a single backend class.

## Stack

- **Theme:** Hyvä child theme (extends `Hyva/default`)
- **JS:** Alpine.js 3 (`x-data`, transitions, click-outside)
- **CSS:** Tailwind 3 — grid for desktop columns, drawer for mobile
- **Backend:** none. Menu data comes from the stock Magento topmenu block

## Features

- Desktop: hover-or-click dropdown with 4-column grid of subcategories + featured image slot
- Mobile: full-screen slide-in drawer with nested accordion levels
- Single template, single Alpine component — same DOM, different layouts via Tailwind breakpoints
- Keyboard: `Tab` cycles, `Esc` closes, arrow keys move within open panel
- Closes on outside click and on scroll-past-threshold
- Featured slot is data-driven — set a category custom attribute and the menu picks it up via existing topmenu node data, still no PHP

## Install

```bash
composer require scr1be/hyva-mega-menu
bin/magento setup:upgrade
bin/magento cache:clean
# Re-build Tailwind in your parent Hyvä theme
cd app/design/frontend/<Vendor>/<theme>/web/tailwind && npm run build:prod
```

Then in admin: **Content → Design → Configuration**, pick `Scr1be/mega-menu` for the store view.

## Why no PHP

Two reasons:
1. **Forward-compatibility.** Every M2 release rewrites a class somewhere. A theme that only overrides `.phtml` and `layout XML` survives major upgrades unchanged.
2. **Hyvä philosophy.** The Hyvä team has spent serious effort moving logic out of templates and into block/ViewModels. The topmenu block already gives a category tree — we don't need to re-fetch it.

## How the menu reads category data

`Magento\Theme\Block\Html\Topmenu::getHtml()` returns a flat HTML string by default. Hyvä replaces this with a template that loops over `$block->getRootNode()->getChildren()` — a `Magento\Framework\Data\Tree\Node` recursive structure. We re-use that structure:

```php
foreach ($block->getRootNode()->getChildren() as $level1) {
    // $level1->getName(), $level1->getUrl(), $level1->getChildren()
}
```

Featured image: read from `$node->getExtensionAttributes()` if set by a separate module, otherwise fall back to first product thumbnail (also already available via topmenu prepare).

## File layout

```
src/
├── registration.php
├── theme.xml
├── composer.json
├── etc/view.xml
└── view/frontend/
    ├── layout/default.xml
    └── templates/html/header/
        ├── menu.phtml             # desktop + mobile shell
        └── menu-panel.phtml       # one open panel (4-col grid)
```

## License

MIT — see [LICENSE](LICENSE).
