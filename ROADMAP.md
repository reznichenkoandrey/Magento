# Roadmap — Wave 2

Wave 1 (the five `hyva-*` projects in this repo) showed isolated slices of the stack.
Wave 2 re-implements, clean-room, the strongest patterns I've shipped in production on a
large multi-store B2C Magento 2 + Hyvä build: every project below is written from scratch
for this repo, runs on vanilla Magento 2.4.8 + Hyvä 1.4 + Luma sample data, and depends on
**zero paid extensions**.

Rules for every Wave 2 project:

- Own folder, standalone installable, `Scr1be` namespace, MIT licensed, own README.
- Pure core Magento + Hyvä. No paid/3rd-party module dependencies, no client-specific data.
- Production-grade: declarative schema, idempotent data patches, ACL, DI discipline,
  CSP-safe Alpine templates, unit tests where behaviour is non-trivial.
- Demoable on the live storefront in this repo (Luma catalog, 2,046 products).

## Status

| # | Project | Wave | Status |
|---|---|---|---|
| 1 | `tier-price-label` | 2a | built · reviewed |
| 2 | `fraud-guard` | 2a | built · reviewed |
| 3 | `category-cascade` | 2a | built · reviewed |
| 4 | `customer-group-guard` | 2a | built · reviewed |
| 5 | `admin-grid-toolkit` | 2a | built · reviewed |
| 6 | `pos-bridge` | 2a | built · reviewed |
| 7 | `fpc-inspector` | 2a | built · reviewed |
| 8 | `hyva-mega-menu` v2 | 2b | built · reviewed |
| 9 | `hyva-product-card` | 2b | built · reviewed |
| 10 | `hyva-product-slider` | 2b | built · reviewed |
| 11 | `curated-categories` | 2b | built · reviewed |
| 12 | `product-families` | 2b | built · reviewed |
| 13 | `back-in-stock` | 2b | built · reviewed |
| 14 | `hyva-media` | 2b | built · reviewed |
| 15 | `store-toolkit` | 2b | built · reviewed |
| 16 | `headless-api-suite` | 2c | built · reviewed |
| 17 | `content-as-code` | 2c | built · reviewed |
| 18 | `signed-document-delivery` | 2c | built · reviewed |
| — | `hyva-compare-drawer` | 1 | built · reviewed |
| — | `hyva-graphql-search` | 1 | built · reviewed |
| — | `hyva-lazy-images` | 1 | built · reviewed |
| — | `hyva-quick-view` | 1 | built · reviewed |

All eighteen Wave 2 projects are written and installed on the demo stand, where the thirty
modules they add up to are enabled together, and all eighteen have had the second pass
described below.

The four Wave 1 projects are listed here too, because until now nothing recorded whether they
had been through it — they had not. `hyva-mega-menu` was rewritten in Wave 2 and is row 8; the
other four were reviewed on their own terms, twelve claims in total. Eleven held word for word,
including the `Container::getChildHtml()` product push, the GraphQL `frontName`, and the pair of
checks `Catalog\Helper\Product::initProduct()` runs before a product page renders. One did not:
`hyva-quick-view` credited Hyvä's global `uenc` submit listener to
`Magento_Theme::page/js/set-uenc.phtml`. It is `Hyva_Theme::` — the file ships in the
`magento2-theme-module` package, and no `Magento_Theme` version exists anywhere in `vendor/`.
The behaviour was described correctly; the address was not.

Wave 2a = compact, high value-per-line. Wave 2b = full features with admin + frontend.
Wave 2c = large suites. Order within a wave is the build order.

"Reviewed" means a second pass verified every claim the module makes about Magento or Hyvä
against the actual core source in `vendor/`, not against recollection — the one discipline
that separates a portfolio module from a plausible-looking one. Two rounds of that caught a
fabricated `price_id` contract that broke Alpine id scoping on Hyvä, a wrong event payload key
that would have left a warning on screen forever, and a repeated bit of folklore about
interceptors.

Every project in this repository now carries at least one frame — 23 of 23, storefront and
admin, all from the demo stand. `demo-screenshots/w2-*` are the storefront ones and `cfg-*` the
configuration sections; the root `README.md` says what each shows. CLI commands and the GraphQL
surfaces are still text-only, and each module's README says what would be worth capturing.

---

## Wave 2a — compact modules

### 1. `tier-price-label`

Replace Magento's bare "As low as $X" with "From N pcs — $X" without adding a single query.

- `around` plugin on the final-price box minimal-amount render — rebuilds the core render
  call with only the label argument changed; falls through to core whenever qty/amount is
  missing so an odd product degrades to the stock wording, never a broken line.
- ViewModel exposing the raw tier ladder for Hyvä price templates doing client-side
  qty→price recalculation (deliberately bypassing core's tier filtering).
- Observer on the listing-collection pre-load event calling the bulk tier-price loader:
  24–48 per-card queries → 1. Threshold picked by *cheapest price*, not highest qty.
- Demo: Luma products with tier prices; before/after label + query count in README.

### 2. `fraud-guard`

Anti-carding: a flagged customer's place-order attempt returns a decline message
indistinguishable from a real gateway decline — no gateway call, no order, no signal to
the attacker.

- Boolean `is_carder` customer EAV attribute (data patch) + admin-only checkbox via
  `customer_form` UI-component override; grid column/filter via EAV grid flags.
- Two `before` plugins: `CartManagementInterface::placeOrder` (Hyvä/REST/GraphQL, all
  gateways) and `QuoteManagement::submit` (direct-submit callers). Admin order creation
  exempted by area check.
- Master kill-switch, configurable decline copy, attempt log in a dedicated file.
- README documents the threat model and the known guest gap (phase 2: velocity guard).

### 3. `category-cascade`

Disabling a parent category disables its whole subtree — transactionally, without event
recursion — plus a fast admin category-tree product count.

- Global-area observer on category save-commit-after with a strict transition guard
  (active→inactive, level ≥ 2, not new, kill-switch on).
- One disabler service: indexed subtree path walk, per-child *attribute-only* saves (no
  save events → cascade cannot re-enter), single UPDATE sweep for conflicting per-store
  overrides, all in one transaction. Re-enabling deliberately does not cascade.
- Post-commit cache-tag cleaning + indexer invalidation; failures logged, never breaking
  the admin save. Admin JS confirm dialog before a cascading save. Config kill-switch.
- Collection plugin replacing the slow admin-tree product count with a lookup against the
  category-product index store table.

### 4. `customer-group-guard`

When an admin changes a customer's group, the customer's active session must not keep
using stale group pricing — layered soft/hard enforcement.

- Soft path: `force-logout` customer-data section comparing a group-id cookie (written at
  login by observers, HttpOnly/Secure semantics documented as CDN-critical) against the
  DB value; Alpine component redirects to logout; one-shot `localStorage` flag carries a
  translatable notice across the logout redirect into Hyvä's messages event.
- Hard path: `before` plugin on cart management place-order — throws before any order is
  created if quote group ≠ DB group. Covers Hyvä, REST, GraphQL.
- Config gate on by default; JS spec for the Alpine component.

### 5. `admin-grid-toolkit`

Three measured admin-grid/order defects fixed in one module.

- `afterRenderExport` plugin fixing legacy grid CSV/Excel exports writing HTML entities
  instead of raw values.
- Order-grid `COUNT(*)` de-join plugin: strips allowlisted LEFT JOINs from the count
  select (core resets ORDER/LIMIT but not FROM), guarded to fall back verbatim when the
  join's columns appear in WHERE/HAVING. README with measured before/after.
- Admin "Reorder" plugin clearing the session reordered flag so a reorder mints a fresh
  increment ID instead of walking the order-*edit* suffix path.
- Unit tests for all three.

### 6. `pos-bridge`

Back-office REST endpoints letting a POS terminal find a customer and act as them.

- Service-contract layer (interfaces + DTOs + `webapi.xml`), custom base path, both
  endpoints ACL-gated on customer management — never anonymous.
- Customer search: AND-across-whitespace-tokens over name/billing name/email, plus a
  digits-only phone match for 3+ digit tokens; optional website scoping.
- Impersonation endpoint mints a customer JWT via the core JWT user-token framework.
- Demo: curl walkthrough in README against Luma sample customers.

### 7. `fpc-inspector`

A cacheability debugging tool answering two questions every Magento dev eventually asks:
what put a non-empty value into the FPC vary string, and who forced `pragma: no-cache`.

- Logging plugins on the HTTP-context vary-string builder and the response
  no-cache-header setter; structured entries (URI, store/currency, computed vary, core
  comparison vs cookie value, will-cache verdict, flattened call stack).
- Enable/reproduce/disable runbook in README; log-growth warning; off by default.

---

## Wave 2b — full features

### 8. `hyva-mega-menu` v2 (rewrite of the existing project)

Closes the Hyvä 1.4 gap with the architecture that actually holds up in production.

- **One DOM tree, two placements**: a single Alpine component root relocated by
  `matchMedia` between the desktop dock (Miller-column dropdown, width driven by a CSS
  custom property as columns activate) and the mobile drawer accordion. No duplicated
  markup, no second tree in JS.
- **SEO-correct rendering**: L1/L2 server-rendered as real `<a href>` exactly once;
  L3 lazy-loaded from an inline JSON island.
- **Strictly CSP-safe Alpine**: dot-path reads and no-arg method references only; all
  state changes are imperative class toggles; presentation entirely in Tailwind/CSS.
- Icons via an inline SVG sprite injected once and tinted with `currentColor`; four-way
  icon resolution priority (sprite key → media image → icon class → colour square).
- Menu resolution chain: layout block argument → customer-group→menu config map (HTTP
  context) → store default → first active; block cache key varies by customer group
  **only when a mapping exists**. `IdentityInterface` for FPC invalidation.
- Module JS as ES modules imported into the theme bundle via a path alias (participates
  in Hyvä's build pipeline, no legacy script includes) + JS specs.
- Keeps the existing companion-module premise (EAV attributes on categories feed the
  menu); the CRUD/tree-editor variant is documented as an extension path, not built.

### 9. `hyva-product-card`

One product card, four renderers — server phtml, Alpine client grid, widget, GraphQL —
zero drift.

- ViewModel layer as single source of truth: badges, srcset image ladder, stock label +
  low-stock threshold, qty rules (min/step/max for the stepper), layered-nav label map,
  GA4 list context, toolbar default sort.
- Mirrored GraphQL resolvers (`card_badges`, `card_media`, `qty_rules`) on
  `ProductInterface` + a collection processor so resolver-backed fields get their
  attributes loaded on both search and category paths.
- Bulk observer attaching qty rules to product collections (no N+1); configurable
  ceiling on the hover-image path.
- Cacheable, session-free stock-status AJAX endpoint (session suppression so guests get
  no `Set-Cookie` that would defeat the CDN); XHR flash-message drainer so add-to-cart
  messages don't resurface on the next full page load.
- Minicart parity: two plugins at the `ItemPoolInterface` choke point — struck-through
  regular price + `has_discount` (epsilon-guarded) and the same qty rules the PDP uses.

### 10. `hyva-product-slider`

Admin-managed product carousels replacing hardcoded layout-XML product lists.

- Full CRUD: declarative schema, repositories + service contracts, admin grid/form,
  mass actions, ACL, menu; per-breakpoint visible counts, autoplay/loop config,
  store scope.
- Product sources: new / bestsellers / deals / most-viewed / recently-bought / category /
  attribute-set / manual SKU / featured (EAV boolean via data patch). Recently-bought
  backed by a 15-minute cron index.
- FPC-safe social proof: "N min ago, X from Y bought this" built from real orders,
  hydrated client-side via a controller so the slider HTML stays block/FPC cached while
  the volatile line stays fresh.
- Page-Builder-friendly widget; frontend on Keen slider + Alpine/Tailwind reusing
  `hyva-product-card` ViewModels.

### 11. `curated-categories`

A reusable batch engine for automatic category membership + three rule adapters.

- Engine: `reconcileAll` / `add` / `remove` service contract returning
  added/removed/unchanged; diff computed in SQL → one batch upsert + one batch delete on
  the category-product pivot; designed for update-on-schedule so mview triggers feed the
  changelog and partial reindex handles cache invalidation — no manual cache BAN.
- SEO minimum-floor guard: never empties the category; retains lowest-position members,
  removes highest-position first on the incremental path.
- Adapters: **Bestsellers** (30-day paid-order qty ranking, daily cron + CLI),
  **New Arrivals** (stock-item save observer + hourly self-heal cron + admin-configured
  exclusion rules, attribute+operator+value with All/Any), **Coming Soon** (restock-date
  attribute source, PDP arrival message with date placeholders).
- Misconfiguration guard refusing to wipe all badges on an accidentally-empty source
  config. CLI with `--dry-run` for every adapter.

### 12. `product-families`

Auto-derived product relationships (other colours / other sizes / similar) rendered as
swatch rows on the PDP — no manual linking.

- Three custom `catalog_product_link` types (idempotent schema patch with aliases).
- Diff-based reconcile pipeline: full-scan driver → grouper by configured attribute →
  position resolver by option sort order → per-family cap → bulk writer emitting only
  INSERT/UPDATE/DELETE deltas → batched raw SQL (500-row cap), never wipe-and-rebuild,
  bypassing repository saves entirely.
- Affected products registered with the cache context for targeted invalidation; links
  read live, no reindex. Cron with enable/dry-run gates + CLI with progress bar.
- GraphQL: three batch resolvers on `ProductInterface` reusing core's related-product
  batch plumbing. Demo: Luma configurables grouped by colour/size.

### 13. `back-in-stock`

Magento's passive "notify me" turned into an active re-engagement surface.

- One declarative column (`popup_status`) on the core alert table; provider loads all
  armed alerts' products in **one** collection, enriched with price, reviews, qty rules,
  badges.
- Customer-data section + Alpine popup (`x-for` cards, `private-content-loaded`),
  inline + bulk add-to-cart, composites degrade to a PDP link; removed from strict-CSP
  checkout pages.
- Global `afterSave` plugin fixing the core state machine: re-subscribing resets
  `status` but leaves a stale `popup_status` that would suppress the popup forever.
- "My Product Alerts" account page, GraphQL query, CLI reset command, JS specs.
- Optional push channel: device-token registry (own table, sha256 upsert, guest-capable,
  soft deactivation) + FCM HTTP v1 sender with self-healing token cleanup — transport
  behind an interface with a log-sink default so the demo needs no Firebase project.

### 14. `hyva-media`

On-demand resize + WebP for `pub/media` uploads that never pass through the catalog
image pipeline (CMS/wysiwyg art) — the Lighthouse image-delivery budget killer.

- Resizer producing cached derivatives under width-keyed paths with srcset builders;
  header-only image-size probe; GD WebP encoder (core's adapter can't output WebP).
- Never upscales, never pads, never serves a derivative heavier than the source (rungs
  above ~90% of source width dropped; original bytes replace a fatter derivative while
  keeping the URL stable); all-or-nothing WebP srcsets; `.webp.skip` markers so a failed
  encode isn't retried; mtime invalidation.
- Natural companion to `hyva-lazy-images` (which owns the `<picture>` markup side).
- Demo: measured before/after bytes on a CMS-imagery page.

### 15. `store-toolkit`

Everything multi-store: SEO cluster, switcher, and temporary store closure.

- **SEO**: canonical ViewModel (query params stripped except whitelisted pager, store
  echo removed, memoised); entity-aware hreflang — resolve current product/category/CMS
  entity per store, drop stores where unavailable, suppress single-locale groups, emit
  `x-default` with a documented primary → same-language → first-available fallback
  chain; per-website robots.txt (Config/Validator/FileWriter/CacheInvalidator split,
  admin-edited, written on config-change observer). Canonical/hreflang deliberately
  removed from cart/checkout/account/404.
- **Switcher**: same-page store switching; server-rendered desktop list (safe: topbar
  renders per-URL under FPC) vs JSON payload for the mobile drawer (URL-agnostic block
  cache → path resolved client-side); Alpine over a native `<select>` so keyboard/a11y
  come free; inline SVG flag sprite.
- **Closure**: one store-scoped flag closes a store for sales — route redirect observer
  (checkout/login/register) + `placeOrder` plugin closing the REST/GraphQL hole; price
  hiding via a price-render plugin; header account links hidden while the switcher stays
  so visitors reach the open sibling store; closure banner published under a
  content-addressed URL (hash of file bytes) because CDN-cached media is never
  tag-invalidated.

---

## Wave 2c — suites

### 16. `headless-api-suite`

The GraphQL surface a native mobile app actually needs, as one coherent suite.

- **Guest→customer auto-registration**: graphql-area observer on quote submit success,
  request-scoped result holder (`ResetAfterRequestInterface`), `afterResolve` plugin
  stamping `customer_created` onto `PlaceOrderOutput`; explicit decision ladder
  (has customer / no email / email matches existing on website → link / new → create +
  events / uniqueness race → re-find and link).
- **Order attribution**: two soft-reference columns on order + grid, admin-managed
  source registry (CRUD, ACL), `around` plugin on the placeOrder resolver validating
  against the registry, request-scoped DTO (survives quote identity-map eviction),
  observer stamping columns at submit; grid columns + filters; failures swallowed so
  checkout never breaks; public `availableOrderSources` query.
- **Social login (OIDC)**: abstract verifier + JWKS provider (dedicated cache type,
  ~1h TTL, retry on `kid` rotation), per-provider subclasses (Google/Apple) validating
  signature/issuer/audience/expiry; provisioner resolving `(provider, subject)` → email
  → create-and-link; own social-link table; store scope strictly from the `Store`
  header; typed error codes in `extensions`.
- **Search autocomplete**: one query fanning out to providers (products, categories,
  popular terms from the core search-query table) honouring admin limits; product cards
  with badges and price; own fulltext collection virtual type pinned to the quick-search
  request container (the default container never binds the term).
- **Wishlist share mutation**: frozen contract, per-recipient try/catch (one bad address
  is non-fatal), raw SMTP errors logged never returned, ownership check collapsing
  not-found and wrong-owner into one message (no IDOR oracle).
- **Push infrastructure**: device registry + FCM HTTP v1 client (RS256 service-account
  JWT minted without an SDK, OAuth2 token caching, `UNREGISTERED` self-healing);
  `after` plugins on the eight core sales email senders so push inherits "Notify
  Customer" semantics and de-dupes for free; `setCartDeviceToken` mutation +
  quote→order carry observer; transport interface with log-sink default.

### 17. `content-as-code`

CMS content made reproducible: capture to version-controlled JSON, replay on deploy.

- Porter abstraction (one interface, a pool): CMS pages, blocks, widget instances +
  one demo custom entity. Export as single JSON or ZIP with manifest and deterministic
  naming; import topologically sorted by porter dependency, per-entry outcome isolation,
  identifier-based matching, skip-if-exists default + opt-in replace mode.
- `capture` / `apply` console commands sharing the engine with setup patches; capture
  prints every transform, warns on unresolvable block references (numeric block IDs
  rewritten to portable identifiers).
- Admin "Content Transfer" page: filter by store view, tick entities across types,
  export one bundle; mass-actions on the native grids.
- Includes the coupon-ticket CMS widget as the demo custom entity: four author-facing
  parameters, discount %/validity/eligibility read from the linked cart price rule
  itself (never retyped), customer-group check against HTTP context (FPC-safe),
  copy-to-clipboard ES module + spec.

### 18. `signed-document-delivery`

Headless order-document delivery done safely: a mutation returns a short-lived signed
URL for an order/invoice/shipment/creditmemo PDF.

- Renderer registry (one renderer per document type) over **core** sales PDF models,
  each doing `loadAndAuthorize` on customer_id + store_id.
- Canonical-key builder → sha256 → filesystem cache under `var/tmp` with atomic
  tmp→rename writes; hourly sweep cron.
- HMAC-SHA256 token (secret derived one-way from the crypt key), constant-time verify
  before payload decode, TTL-bounded; streaming controller enforcing a **second**
  independent lock (customer token cross-checked against the payload).
- Fixes the core GraphQL quirk where shipment UID encodes increment_id, not entity_id.

---

## Deliberately not ported

About 35 production modules were left out of this roadmap on purpose. They fall into
three buckets, and the reason is the same for all: nothing to show without the paid
vendor, or nothing generic to reuse.

1. **Paid-extension fixes and compatibility glue** — patches to payment gateways,
   search, loyalty, RMA, SMTP and B2B extensions. Real production work, but they
   can't run (or demo) without the licensed vendor code they fix.
2. **Client-content patches** — data patches seeding a specific store's CMS content,
   themes and media. The *engine* behind them is ported (`content-as-code`); the
   content itself is not mine to publish and wouldn't demonstrate anything.
3. **Legacy-app bridge schemas** — GraphQL facades kept byte-compatible with a
   retired mobile app's contract. Migration engineering worth a case study, not a
   reusable module.
