# Magento — Portfolio

A collection of Magento 2 + Hyvä modules and themes I've built to show different architectural angles on the same stack. Each subfolder is a standalone, installable project with its own README, license and full source.

The goal of this repo is not to bundle a "kitchen sink" — it's to show **how I make trade-offs** between backend, frontend, performance and UX work on Hyvä.

## Projects

Twenty-three of them, in two waves. The first five take one slice of the stack each and go deep;
the eighteen after them re-implement, from scratch, the patterns that hold up on a large
multi-store build.

### Wave 1 — one slice of the stack each

| Folder | Approach | What it demonstrates |
|---|---|---|
| [`hyva-quick-view/`](hyva-quick-view/) | Full Magento 2 module — PHP controller + ViewModel + Hyvä `.phtml` + Alpine modal | End-to-end backend → frontend slice: routed JSON action, ViewModel, layout XML, Alpine focus-trapped modal |
| [`hyva-mega-menu/`](hyva-mega-menu/) | One server-rendered tree, relocated between two docks by `matchMedia` | Desktop Miller columns and a mobile accordion from a single DOM tree — L1/L2 as real anchors, L3 from a JSON island, strictly CSP-safe Alpine |
| [`hyva-compare-drawer/`](hyva-compare-drawer/) | Client-side UX widget — Alpine store + `localStorage` | When server round-trips aren't worth it: persisted state, cross-tab sync via `window.storage`, drag-to-reorder |
| [`hyva-graphql-search/`](hyva-graphql-search/) | Headless instant search against stock Magento GraphQL | API-first frontend: debounce, `AbortController` for race-free queries, in-memory cache, keyboard nav |
| [`hyva-lazy-images/`](hyva-lazy-images/) | Performance — `<picture>` with AVIF/WebP/LQIP + IntersectionObserver | Core Web Vitals work: CLS=0 via explicit dimensions, AVIF-first srcsets, LQIP base64 placeholders |

### Wave 2 — the patterns, re-implemented clean-room

Each written from scratch against Magento and Hyvä source rather than
adapted from anything, each standalone and dependent on **zero paid extensions**. Full briefs and
the reasoning behind each are in [`ROADMAP.md`](ROADMAP.md).

**Compact and core-only** — high value per line, no admin UI to speak of:

| Folder | Approach | What it demonstrates |
|---|---|---|
| [`tier-price-label/`](tier-price-label/) | `around` plugin on the final-price render + bulk tier loader on collection load | Replacing core copy without a single extra query — 24–48 per-card lookups collapse to one, and an odd product degrades to the stock wording rather than a broken line |
| [`fraud-guard/`](fraud-guard/) | Two `before` plugins on place-order, boolean customer EAV attribute | Anti-carding where the decline is indistinguishable from a real gateway decline: no gateway call, no order, no signal back to the attacker |
| [`category-cascade/`](category-cascade/) | Save-commit observer + attribute-only child saves inside one transaction | Recursion that cannot re-enter itself — no save events on children, one UPDATE sweep for conflicting per-store overrides, and re-enabling deliberately does *not* cascade |
| [`customer-group-guard/`](customer-group-guard/) | Customer-data section comparing a group cookie against the DB, plus a hard plugin on place-order | Layered soft/hard enforcement: a stale session cannot keep buying at the old group's prices, and the soft path carries a translatable notice across the logout redirect |
| [`admin-grid-toolkit/`](admin-grid-toolkit/) | Three plugins against three measured admin defects | Reading core's own SQL before fixing it — the order-grid `COUNT(*)` de-join strips only allowlisted joins and falls back verbatim when their columns appear in `WHERE`/`HAVING` |
| [`pos-bridge/`](pos-bridge/) | Service contracts + `webapi.xml`, ACL-gated, core JWT user-token framework | Back-office REST for a POS terminal: token-scoped impersonation that is never anonymous, and a customer search that ANDs across whitespace tokens with a digits-only phone match |
| [`fpc-inspector/`](fpc-inspector/) | Logging plugins on the vary-string builder and the no-cache header setter | A debugging tool for the two questions every Magento dev eventually asks — what put a value in the FPC vary string, and who forced `pragma: no-cache` |

**Full features** — admin UI, storefront, and the seams between them:

| Folder | Approach | What it demonstrates |
|---|---|---|
| [`hyva-product-card/`](hyva-product-card/) | One ViewModel layer, four renderers reading it | Server `.phtml`, Alpine client grid, widget and GraphQL that cannot disagree, because none of them decides anything — badges, image ladder, stock label and qty rules all resolve in one place |
| [`hyva-product-slider/`](hyva-product-slider/) | Full CRUD module + nine product sources + a swappable carousel engine | Where an import map is actually justified: the engine specifier is a `di.xml` seam a project can rebind, and everything else imports relatively. Recently-bought is backed by a 15-minute cron index |
| [`curated-categories/`](curated-categories/) | Diff computed in SQL → one batch upsert + one batch delete on the category pivot | Batch membership that leans on the platform: mview triggers feed the changelog, partial reindex handles invalidation, and an SEO floor guard never empties a category |
| [`product-families/`](product-families/) | Three custom link types + a diff-based reconcile pipeline | Auto-derived "other colours / other sizes" without manual linking — emitting only INSERT/UPDATE/DELETE deltas in 500-row batches, never wipe-and-rebuild |
| [`back-in-stock/`](back-in-stock/) | One declarative column on the core alert table + customer-data section and Alpine popup | Turning Magento's passive "notify me" into a re-engagement surface: every armed alert's product loaded in one collection, inline and bulk add-to-cart, composites degrading to a PDP link |
| [`hyva-media/`](hyva-media/) | On-demand resizer with width-keyed derivatives and a GD WebP encoder | The Lighthouse budget killer nobody covers — `pub/media` art that never touches the catalog image pipeline. Never upscales, never serves a derivative heavier than its source |
| [`store-toolkit/`](store-toolkit/) | Canonical/hreflang ViewModels, per-website robots.txt, scheduled store closure | Everything multi-store: entity-aware hreflang that drops stores where the entity does not exist, emits `x-default` through a documented fallback chain, and a closure that answers correctly to crawlers |

**Suites** — several modules under one metapackage:

| Folder | Approach | What it demonstrates |
|---|---|---|
| [`headless-api-suite/`](headless-api-suite/) | Six GraphQL modules: guest auto-registration, wishlist sharing, autocomplete, social login, order attribution, push tokens | The surface a native app actually needs. Guest→customer registration runs an explicit decision ladder including the uniqueness race, and social login mints tokens through the current SPI rather than the deprecated one |
| [`content-as-code/`](content-as-code/) | Porters capturing CMS entities to version-controlled JSON, replayed on deploy | Content made reproducible — including the widget-placement asymmetry, where the shape core reads is not the shape core writes, and layout-update columns that are carried as warnings because core refuses to save them |
| [`signed-document-delivery/`](signed-document-delivery/) | HKDF-derived signing key, MAC-before-parse verification, per-type renderers | Headless invoice delivery done safely: unsigned bytes never reach a JSON parser, the key is derived one-way from the crypt key, and ownership is checked against both customer *and* store |

Every Wave 2 module has had a second pass in which each claim it makes about Magento or Hyvä was
checked against the source in `vendor/` rather than against recollection — see the status table in
[`ROADMAP.md`](ROADMAP.md).

## Live demo (Magento 2.4.8-p4 + Hyvä 1.4)

The portfolio runs on a real Magento storefront with sample data (Luma catalog, 2,046 products),
all thirty modules enabled. Screenshots are from that install, not mockups:

| | Module | What's shown |
|---|---|---|
| ![Mega menu](demo-screenshots/w2-01-mega-menu-home.png) | hyva-mega-menu | The header nav rendered from one tree — real anchors, docked to the desktop bar. The same tree is the mobile drawer's accordion; nothing is duplicated in markup or in JS |
| ![Category cards](demo-screenshots/w2-02-category-cards.png) | hyva-product-card + quick-view + compare-drawer | Every card carries the same ViewModel-fed data the GraphQL layer serves, plus the two injected buttons in Hyvä's `catalog.list.item.addto` slot |
| ![Quick view](demo-screenshots/w2-03-quick-view.png) | hyva-quick-view | Modal over a real product, body rendered by Magento and fetched as JSON — focus-trapped, `aria-modal`, form key and `uenc` handled the way Hyvä's own add-to-cart forms do |
| ![Compare drawer](demo-screenshots/w2-04-compare-drawer.png) | hyva-compare-drawer | Floating drawer with `localStorage` persistence, per-item removal and an LRU cap; the card button flips to "In compare" from the same Alpine store |
| ![Instant search](demo-screenshots/w2-05-instant-search.png) | hyva-graphql-search | Debounced autocomplete over stock Magento GraphQL — eight matches for "bag", thumbnails and prices from the same query, match highlighting done by escaping text first and injecting `<mark>` after |

Earlier screenshots of the same three storefront modules, taken on the previous install, are kept in
[`demo-screenshots/`](demo-screenshots/) as `01`–`05`.

`hyva-lazy-images` is installed and visible in **Stores → Configuration → scr1be → Lazy Images** but isn't actively rendering picture elements in the demo (the CDN passthrough is left unconfigured — there's no point spinning up imgproxy for a screenshots-only demo).

## Why this layout

Each project sits in its own folder with a complete Magento module structure (`registration.php`, `composer.json`, `etc/`, `view/`, etc.). You can copy `src/` into `app/code/Scr1be/<Module>/` or `composer require` it from a local path — no project depends on another.

Three of them ship more than one module, because the parts have different blast radii: `headless-api-suite/` (six), `store-toolkit/` (three) and `content-as-code/` (two). There each module is its own directory under `src/` with its own `composer.json`, and `src/composer.json` is a metapackage that pulls in the whole set. Every one of them installs on its own; the single intra-suite dependency is `coupon-ticket`, which registers a porter into `content-transfer`'s engine.

## Install any one of them

```bash
# from your Magento 2 root
composer config repositories.scr1be path /path/to/Magento/hyva-quick-view/src
composer require scr1be/hyva-quick-view:@dev
bin/magento module:enable Scr1be_HyvaQuickView
bin/magento setup:upgrade
```

(Replace `hyva-quick-view` with whichever folder you want. For the three multi-module projects, point the path repository at `src/*` and require either the metapackage — `scr1be/headless-api-suite`, `scr1be/store-toolkit`, `scr1be/content-as-code` — or a single module out of the set.)

## Stack across all projects

- **Backend:** PHP 8.2+, Magento 2.4.6+
- **Frontend:** [Hyvä Theme](https://hyva.io) 1.3+, Alpine.js 3, Tailwind CSS 4 — except the three that ship a Tailwind stylesheet (`hyva-mega-menu`, `hyva-product-card`, `hyva-product-slider`), which need **Hyvä 1.4+**: Tailwind 4 landed in theme 1.4.0, and the still-maintained 1.3 line is still on Tailwind 3
- **No legacy:** no jQuery, no RequireJS, no KnockoutJS, no UI components

## About

Senior Magento 2 / Hyvä engineer, 12+ years of building e-commerce. These repos are a snapshot of the kind of work I do — happy to walk through any of them.

— Andrii Reznichenko · [github.com/reznichenkoandrey](https://github.com/reznichenkoandrey)

## License

Every subproject is MIT-licensed. See the `LICENSE` file inside each folder.
