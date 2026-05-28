# Hyvä Compare Drawer

A floating "compare products" drawer for Magento 2 + Hyvä — **100% client-side**. State lives in `localStorage`, persists across sessions and tabs, never touches the database, never hits an API endpoint.

The point of this project: show how to build genuinely useful e-commerce UX without round-tripping to PHP. Compare is mostly a UI/UX concern — the server doesn't need to care which products a guest is comparing.

## Stack

- **Frontend only:** Alpine.js 3 (`$store` + `$persist` plugin), Tailwind CSS 3
- **Storage:** `localStorage` keyed `scr1be_compare_v1`
- **Cross-tab sync:** `window.storage` event listener — add a product in tab A, the drawer updates in tab B
- **Backend:** Hyvä module shell only (registration, layout XML to inject the drawer)

## Features

- Floating drawer pinned to bottom-right; collapses to a thumbnail strip + counter when minimized
- Drag-and-drop reordering within the drawer (`@dragstart` / `@dragover`)
- Maximum of 4 products (configurable via Alpine store `max` property)
- Removing the last product hides the drawer entirely
- Full compare table view at `/compare` route (also client-rendered — pulls items from store, fetches each product's display data via the existing Hyvä product card endpoint, no custom controller needed)
- Respects `prefers-reduced-motion` — disables slide animations

## Install

```bash
composer require scr1be/hyva-compare-drawer
bin/magento module:enable Scr1be_HyvaCompareDrawer
bin/magento setup:upgrade
```

No `setup:di:compile` needed — there is no PHP class to compile.

## Usage

The "Add to compare" button is auto-injected into product list items. To trigger manually:

```html
<button @click="$store.compare.add({
    id: {{ product.id }},
    name: '{{ product.name | escape('js') }}',
    image: '{{ product.image_url | escape('js') }}',
    url: '{{ product.url | escape('js') }}',
    price: '{{ product.formatted_price | escape('js') }}'
})">
    Compare
</button>
```

## Why client-only

| Server-side compare | This drawer |
|---|---|
| New DB table, repository, service contract, REST + GraphQL surfaces | One JS object |
| Guest carts need session-based persistence logic | `localStorage` handles it for free |
| Cross-tab sync needs WebSocket or polling | `window.storage` event, native |
| Logged-in user state has to sync from session → cart → quote… | Skip the round-trip entirely |
| Every interaction = full XHR | Every interaction = setter |

The trade-off: compare list doesn't follow the user across devices. For a guest-heavy storefront, this is a non-issue. For logged-in users who care about cross-device parity, you can layer a sync-to-server step on `$watch('items', …)` later — without rewriting any of this.

## File layout

```
src/
├── registration.php
├── composer.json
├── etc/module.xml
└── view/frontend/
    ├── layout/default.xml
    └── templates/
        ├── drawer.phtml          # the floating drawer
        ├── add-button.phtml      # "Add to compare" pill
        └── store.phtml           # Alpine store + $persist registration
```

## License

MIT — see [LICENSE](LICENSE).
