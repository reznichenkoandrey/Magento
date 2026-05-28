# Hyvä Quick View

AJAX quick-view modal for Magento 2 + Hyvä themes. One click on a product card opens a lightweight modal with image, price, swatches and add-to-cart — no full page reload.

Built as a full Magento 2 module: PHP controller returning JSON, dedicated ViewModel, Hyvä-style `.phtml` templates and an Alpine.js modal component. Demonstrates a complete backend-to-frontend slice on the Hyvä stack.

## Stack

- **Backend:** PHP 8.2+, Magento 2.4.6+
- **Frontend:** Hyvä Theme 1.3+, Alpine.js 3, Tailwind CSS 3
- **Pattern:** AJAX JSON endpoint → Alpine `x-data` modal → `x-html` swap

## Features

- Single AJAX endpoint that returns rendered product block as HTML (no client-side templating duplication)
- Modal driven by an Alpine store — any button on the page can trigger `$store.quickView.open(productId)`
- Keyboard-accessible (`Escape` closes, focus trap, `aria-modal`)
- No jQuery, no RequireJS — Hyvä-native
- Image gallery preserved (uses Hyvä's own `Magento_Catalog::product/view/gallery.phtml`)
- Graceful fallback: if JS is disabled the button degrades to a normal product page link

## Install

```bash
composer require scr1be/hyva-quick-view
bin/magento module:enable Scr1be_HyvaQuickView
bin/magento setup:upgrade
bin/magento setup:di:compile
cd app/design/frontend/<Vendor>/<theme>/web/tailwind && npm run build:prod
```

## Usage

The module auto-injects a "Quick view" button into product list items via `catalog_category_view.xml`. To trigger from custom code:

```html
<button @click="$store.quickView.open({{ $product->getId() }})">
    Quick view
</button>
```

## Architecture

```
Product card
   │ click
   ▼
Alpine $store.quickView.open(id)
   │ fetch('/hyva-quickview/product/info?id=' + id)
   ▼
Controller\Product\Info::execute()
   │ uses ViewModel\QuickView to render block HTML
   ▼
JSON { html: "...", title: "..." }
   │
   ▼
Modal swaps innerHTML with x-html, locks scroll, traps focus
```

## File layout

```
src/
├── registration.php
├── composer.json
├── etc/
│   ├── module.xml
│   └── frontend/routes.xml
├── Controller/Product/Info.php
├── ViewModel/QuickView.php
└── view/frontend/
    ├── layout/
    │   ├── default.xml
    │   └── catalog_category_view.xml
    └── templates/
        ├── modal/quick-view.phtml
        └── product/list/quick-view-button.phtml
```

## License

MIT — see [LICENSE](LICENSE).
