# Hyvä GraphQL Instant Search

Headless instant-search bar for Magento 2 + Hyvä. Queries the stock Magento GraphQL `products` endpoint directly from Alpine.js, with debounce, request cancellation, keyboard navigation and result caching.

No custom REST controller, no Elasticsearch-specific code, no third-party search service. Just the API Magento already ships.

## Stack

- **Backend:** stock Magento 2 GraphQL `products` query (no custom resolver)
- **Frontend:** Alpine.js 3, native `fetch`, `AbortController`
- **Theme:** Hyvä child component (`.phtml` only, no PHP class)
- **Cache:** in-memory `Map` keyed by query string, TTL 5 minutes

## Features

- 300 ms debounce — typing fast doesn't fire a request per keystroke
- `AbortController` cancels in-flight requests when a newer query starts (no race conditions)
- Per-session result cache — toggling between queries you've already typed is instant
- Keyboard nav: `↑`/`↓` move highlight, `Enter` follows, `Esc` closes
- Term highlighting — `<mark>` around matching substring (escaped, XSS-safe)
- Empty / loading / error states all rendered from the same Alpine component
- Click-outside to close, keeps query string so user can resume

## Install

```bash
composer require scr1be/hyva-graphql-search
bin/magento module:enable Scr1be_HyvaGraphqlSearch
bin/magento setup:upgrade
```

The module injects its search bar into `header.container` via layout XML. To override placement, copy `src/view/frontend/layout/default.xml` into your own theme.

## The query

```graphql
query InstantSearch($search: String!, $pageSize: Int = 8) {
  products(search: $search, pageSize: $pageSize) {
    total_count
    items {
      uid
      name
      sku
      url_key
      small_image { url label }
      price_range { minimum_price { final_price { value currency } } }
    }
  }
}
```

Why this exact shape:
- `uid` is the global identifier — works for both simple and configurable parents
- `url_key` lets us build the product URL client-side without an extra query
- `price_range.minimum_price.final_price` covers configurable products correctly
- `pageSize: 8` keeps payload small for a dropdown (full search page handles "see all")

## Why GraphQL, not the REST `/V1/search` endpoint

- GraphQL lets the client choose the fields — REST forces a fixed product schema (5–10× the payload)
- `AbortController` works the same against REST, but GraphQL gives one endpoint to whitelist in WAF rules
- Magento's GraphQL goes through the same QuickSearchInterface — same Elasticsearch backend, same relevance, just a different surface

## Race conditions

Naive instant-search fires a request per keystroke. The user types "shoes" → 5 requests fire, network reorders them, the dropdown shows results for "s" or "sh" instead of "shoes". This is the classic instant-search bug.

The fix here is in `web/js/search.js`:

```js
async search(query) {
    this.currentController?.abort();
    this.currentController = new AbortController();
    // ...
    const response = await fetch(GRAPHQL_URL, {
        signal: this.currentController.signal,
        // ...
    });
}
```

Every new request aborts the previous one. Only the latest response can ever render.

## File layout

```
src/
├── registration.php
├── composer.json
├── etc/module.xml
└── view/frontend/
    ├── layout/default.xml
    └── templates/
        ├── search-bar.phtml      # input + dropdown shell
        └── results.phtml         # one result item template
```

## License

MIT — see [LICENSE](LICENSE).
