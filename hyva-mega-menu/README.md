# Hyvä Mega Menu

A header navigation for Magento 2 + Hyvä 1.4 that renders **one** menu tree and shows it two ways: a
Miller-column dropdown on desktop, an accordion drawer on mobile. The tree is server-rendered once as
ordinary anchors, physically relocated between the two docks by `matchMedia`, and driven by a single
Alpine component that never learns which placement is in force.

Third-level categories travel separately, as a JSON island, and become links the first time a shopper
opens the branch they belong to.

This is a **v2 rewrite**. The first version of this project was a Hyvä child theme plus a companion
module and was written against Hyvä 1.3's topmenu API; nothing of its rendering survives. What
survives is its premise — that a menu which needs more than a category name needs EAV attributes, and
EAV attributes need a module.

## Why this exists

Hyvä 1.4 ships a perfectly good header menu, and it renders the navigation **twice**. Its
`Magento_Theme/layout/default.xml` declares `topmenu_generic` with two children, `topmenu_mobile` and
`topmenu_desktop`; each of their templates calls `$viewModelNavigation->getNavigation(4)` and walks
the whole result, one inside a `lg:hidden` wrapper and one inside a `hidden lg:flex` one. Both trees
are in the HTML of every page; a browser paints one and keeps the other.

That is a defensible theme decision — two independent templates are far easier to style — and it is
the wrong trade on a large catalogue, where the menu is the single biggest fixed cost in the document
and it is being paid twice. This module is what the other trade looks like when it is taken
seriously:

- **one tree in the DOM**, moved rather than duplicated;
- **two of its three levels in the HTML**, because levels one and two are what a crawler and a
  no-JavaScript visitor need, and the third level is a long tail nobody has asked for yet;
- **every state change an imperative class toggle**, so the markup carries no Alpine directives and
  can be relocated with no lifecycle at all.

The rest of the module is what a menu needs before it is usable on a real installation: icons that
inherit the text colour, a resolution chain that survives a merchant's typo, and cache identities
that make a category rename invalidate the pages it appears on.

## One tree, two placements

The template renders the tree inside the desktop dock. The component's `init()` asks the browser
which placement applies and moves the `<nav>` into the mobile dock if it is the narrow one — a single
`appendChild`, which is a move rather than a copy.

```
<div class="scr1be-menu" x-data="scr1beMegaMenu">     ← the only element Alpine knows about
  <svg class="scr1be-menu__sprite">…</svg>            ← symbols, injected once
  <button data-menu-control="drawer-open">            ← chrome
  <div data-menu-dock="desktop">
      <nav data-menu-tree>  ← L1 + L2, real anchors        ⇄ moved between the two docks
  </div>
  <div class="scr1be-menu__drawer">
      <button data-menu-control="drawer-close">
      <div data-menu-dock="mobile">                        ⇄ …and lands here below the breakpoint
  </div>
  <script type="application/json" data-menu-island>…</script>   ← L3
</div>
```

Three properties follow from that shape, and each of them is the reason for the next one:

**Nothing inside the tree is bound.** Every control is found by walking up from the event target
(`Element.closest()`), so the tree is plain `<a>`, `<button>` and `<li>` elements that can be appended
anywhere. Markup carrying Alpine directives is markup Alpine has to tear down and re-initialise when
it moves.

**The state machine has no element in it.** `menu-state.js` answers three questions — which top-level
entry is open, which of its branches is open, is the drawer showing — and knows nothing about the
page. That is what lets the tree move without any state travelling with it. A placement change is a
deliberate reset rather than a migration: an open dropdown has no meaning inside a drawer, and
carrying it across is how a resize leaves a panel open that nothing on screen can close.

**Presentation is entirely CSS.** The two docks are two stylesheets over the same markup. The same
`is-open` class that positions an absolute dropdown under the desktop dock expands an accordion
section under the mobile one; the rules that made it a dropdown are simply not the ones that apply
there. The one piece of geometry JavaScript sets is a column *count* —
`--scr1be-mega-menu-columns` — which the stylesheet multiplies by a column-width token. The panel
widens as the second and third columns activate without the component knowing what a pixel is.

The breakpoint is declared once, in CSS, and read back out by `getComputedStyle` before `matchMedia`
is called. That closes the failure mode where the dock that is *visible* and the dock the tree was
*moved into* are different ones. The JavaScript keeps a fallback copy of the value for the case where
the stylesheet has not loaded, and a spec asserts the two are equal.

## What is an anchor, and what is data

| Level | How it ships | Why |
|---|---|---|
| 1 | `<a href>` in the HTML | Top-level categories are the site's primary navigation |
| 2 | `<a href>` in the HTML | Where the internal linking value is, and what a drawer user reaches for |
| 3 | JSON island, built into anchors on first open | A long tail of links most sessions never open, on the level where a deep catalogue keeps most of its nodes |

The split happens in `MenuTreeBuilder`, not in the template, because it is a data decision rather
than a rendering one. Each second-level entry keeps a `has_children` flag so the template can render
the disclosure control, and its children move into the island keyed by the same node key the markup
carries.

The island is a `<script type="application/json">` data block: the browser hands its contents over as
text and never executes them, so there is no inline-script hash to register. It is serialised with
`Magento\Framework\Serialize\Serializer\JsonHexTag`, whose `serialize()` calls `json_encode` with
`JSON_HEX_TAG` — every angle bracket in the payload leaves as a JSON unicode escape, so a category
named after a closing script tag cannot close the element it travels in.

On the way back in, entries are built with `createElement`, `setAttribute` and `textContent` rather
than an `innerHTML` template. The category name is merchant input and lands in the page as text, with
no second escaping layer to get wrong on top of the one the block already applied.

A branch is filled once. The flag goes on **before** the entries are appended and goes on even when
the island has nothing for that branch, so a branch with no third level is not retried on every
hover.

## Strictly CSP-safe Alpine

Alpine's CSP build — `Hyva_Theme::js/alpine3-csp.min.js`, shipped with the theme module — does not
evaluate expressions at all. Its evaluator splits the directive value on `.` and walks the component
scope one segment at a time, warning and stopping the moment a segment is undefined. A dot path or a
method reference resolves; an expression, an argument or a comparison does not. Every directive in
the template obeys that:

```html
<div class="scr1be-menu"
     x-data="scr1beMegaMenu"
     @click="onClick"
     @pointerover="onPointerOver"
     @click.outside="onOutside"
     @keydown.escape.window="onEscape">
```

Delegation is what makes it possible. A per-item handler would need the item's key as an argument;
a delegated one reads the key off the element it matched. `x-data` names a component registered with
`Alpine.data()`, and that is not a stylistic preference either: the CSP build's `x-data` directive
evaluates its expression against a scope built from the `Alpine.data()` registrations, so a global
factory function on `window` is not in scope and does not resolve.

Two inline scripts exist on the page and both are handled explicitly. The JSON island is a data block
and needs nothing. The import map is a real inline script, so `menu-scripts.phtml` calls
`$hyvaCsp->registerInlineScript()` immediately after it — that method reads the output buffer and
acts on the **last** `<script>` element in it, hashing it when the full page cache is on and the
layout is cacheable and adding a nonce otherwise. Either way, position matters. `$hyvaCsp` is checked
with `isset` rather than assumed: Hyvä's `PhpPlugin::beforeRender()` assigns it only when the current
theme is a Hyvä theme, and this particular block hangs off `head.additional`, which every theme has.

A spec reads the real template and asserts that every Alpine directive in it is a bare reference. An
expression there works in development and dies silently under a strict policy in production, which is
not a thing to leave to review.

## Icons

Four category attributes, resolved as a **fallback ladder** rather than a switch:

```
sprite key  →  media image  →  CSS class  →  colour square  →  nothing
```

A value that cannot be used steps aside for the next one instead of producing a broken icon. A sprite
key that is not in the registry, an icon class carrying characters that have no business in a class
attribute, a hex colour typed as a word — each hands over, and a category with nothing usable draws
no icon, which is what every category looks like before anyone fills the fields in.

The order is deliberate. The sprite wins because it is the only source that inherits the text colour:
every symbol is drawn on a 24×24 grid with `fill="none"` and no colour of its own, and the
`stroke="currentColor"` on the wrapper is what tints it — so hover, focus and the active-branch state
come for free, with no second asset and no JavaScript. A media image is next because it is the only
source that carries real artwork. A CSS class is third because it depends on an icon font the theme
may or may not still ship. The colour square is last because it is what a merchant reaches for when
nothing else is ready.

Only the symbols the menu actually referenced are inlined, plus the four chrome symbols (two
chevrons, hamburger, close). The sprite lands in the HTML of every cached page, so an unused symbol
is bytes paid for on every request, forever.

Two of the four are allow-listed at the PHP end rather than merely escaped on the way out:

- **the colour** is written into a CSS custom property, and a custom property is one of the few places
  in a page where escaping is not enough on its own — the declaration is parsed as CSS, so an
  unvalidated value could close it and open another. Three- and six-digit hex only.
- **the CSS class** is letters, digits, `-`, `_`, `.`, `:`, `/` and spaces, capped at 120 characters.
  Anything else takes the value out of the ladder rather than being escaped into the attribute.

The image url is not built by hand. `Category::getImageUrl()` handles the two shapes core's own image
backend writes — a bare file name for something uploaded into the category media folder, and a
leading-slash path for a file picked from elsewhere in the gallery — and it throws when the stored
value is not a string. That throw is caught and logged: one category written by something other than
the image backend is not a reason to take the header menu off every page.

## Menu resolution

A "menu" here is a root category and the subtree beneath it. That is not a shortcut around building a
menu entity — it is the observation that Magento already ships one. Root categories are exactly the
level-1 nodes of the category tree (core defines them the same way from the other direction:
`Category\Collection::addRootLevelFilter()` is `path != '1'` plus `level <= 1`), a store group already
points at one, and the admin already has a tree editor for them.

Four candidates, first active root wins:

| # | Candidate | Where it comes from |
|---|---|---|
| 1 | Layout block argument | `<argument name="menu_root" xsi:type="number">5</argument>` on this block, in any layout handle |
| 2 | Customer-group override | The `group:root` map in config, with the group read from the **HTTP context** |
| 3 | Store default | The configured root category id, or the one the store view is already assigned to |
| 4 | First active root | In admin tree order — position, then id |

Every step **falls through** rather than failing. A layout argument pointing at a category that was
later switched off, a group mapped onto a root that was deleted, a store view whose
`getRootCategoryId()` answers `Category::ROOT_CATEGORY_ID` (which is `0`, and is what the concrete
`Store` returns when it has no group) — each of those is a configuration mistake that should cost the
merchant a wrong-looking menu, not a header with no navigation in it. Step 4 is why the chain
terminates in something; it is also the only step that can still answer nothing, and it does so only
when the installation genuinely has no active root category at all.

The customer group is read from `Magento\Framework\App\Http\Context`, never from the customer
session. The HTTP context is the value the full-page cache varies on; the session is depersonalised
on a cacheable request.

The layout argument is admin-authored rather than request input, and it is still read defensively: a
typo that produced `menu_root="women"` falls through to the next candidate instead of casting itself
to category `0`.

The group map itself is free text typed by a human, so its parser is unsurprising rather than strict —
commas, semicolons and newlines all separate, spaces around the colon are fine, and an unreadable
line is dropped instead of throwing. Group `0` is accepted as a key because **NOT LOGGED IN** is a
real customer group; root category `0` is rejected because it is Magento's "no root category"
sentinel. That asymmetry is the reason the parser insists on digits rather than casting: a lenient
`(int)` on a typo produces group `0` out of thin air and quietly re-points the menu for every guest.

## Caching

**The cache key varies by customer group only when a map exists.** The store view and the resolved
root category are in the key regardless — they are what the output depends on. The group joins them
only when `variesByCustomerGroup()` says a usable map is configured. Without one the menu cannot
differ between groups, and adding the group anyway would shard one entry per store view into one per
group for identical HTML: four renders on an installation with four groups, and a great many more on
one with a group per key account.

**`IdentityInterface`, and deliberately no `ttl`.** The block implements `IdentityInterface`, so
`PageCache\Model\Layout\LayoutPlugin::afterGetOutput()` folds the menu's category tags into the page's
`X-Magento-Tags` header, and `AbstractBlock::getCacheTags()` puts the same tags on the block's own
`block_html` entry. It has a `cache_lifetime` argument but no `ttl` attribute, which is where it
parts company with Hyvä's own `topmenu_generic` (that block declares both). A `ttl` does two things
under Varnish: `PageCache\Observer\ProcessLayoutRenderElement` replaces the block's output with an
`<esi:include>`, and the same `LayoutPlugin` then *skips* that block's identities. A menu inside an
ESI include is a second request per page; a menu whose identities were skipped is a page that never
notices a category was renamed. Neither is worth a fragment-level TTL on markup that changes when the
catalogue does.

**The identity list is capped, and the cost is stated rather than hidden.** Above 200 categories the
page carries a single bare `cat_c` tag instead of one per category, because `X-Magento-Tags` goes out
on every cacheable response and some reverse proxies bound its size. Above the cap a category
*rename* no longer invalidates the page on its own: core only emits the bare tag on create, delete,
or a change of `include_in_menu` (`Catalog\Model\Category::getIdentities()`). A menu with several
hundred entries is already a merchandising problem, and it should not also be a header-size one.

**The bare tag is in the list unconditionally**, and it is not redundant next to the per-category
ones. A category *added* to the menu has no tag in the list yet, because it did not exist when the
page was rendered — and create, delete and `include_in_menu` are exactly the set of changes that
alter who is in the menu without changing anyone who already was.

**The tree is built lazily, once, and identities are worth a query.** `getIdentities()` runs after
rendering, and on a `block_html` hit the template never runs at all (`AbstractBlock::_loadCache()`
returns the cached string without calling `_toHtml()`), so on that path the identities are what
triggers the build. That is a query the block cache does not save, and it is the right one to keep
paying: a menu that renders from cache but reports no cache tags leaves every page in the full-page
cache claiming it does not depend on the catalogue.

## One query

The whole menu — every level, every icon — comes from one category collection.

The icon attributes are selected in the tree query rather than fetched afterwards: they are ordinary
category attributes, so `addAttributeToSelect()` folds them into the joins the collection was going
to make for the name anyway. The alternative — walking the tree and then asking a repository for each
category's extra fields — is the shape v1 had, and it costs one round trip per menu entry on a page
that is otherwise a single query. `addUrlRewriteToResult()` puts `request_path` on every row, so
`Category::getUrl()` uses it instead of asking the url finder for one rewrite at a time.

Rows come back ordered by level first, and that is load-bearing rather than cosmetic: a parent has
always been accepted or rejected before its children are read, so a category whose parent failed the
`is_active` / `include_in_menu` filters can be dropped along with its whole branch in a single pass.
Promoting an orphan to the top level — which is what a builder keyed only on "is my parent the root"
would do — publishes a category the merchant switched off.

**The EAV collection is used, not the flat one.** Core's `StateDependentCollectionFactory` hands back
`Category\Flat\Collection` when the category flat index is on, and that class's
`addAttributeToSelect()` is not the EAV one: it adds *columns of the flat table* to the select. An
attribute with no column there does not degrade — it produces an SQL error. The EAV collection answers
correctly whether or not the flat index exists, which is worth more here than the flat table's read
speed on a query that runs once per cached page.

## Design decisions

**Hyvä 1.4's build pipeline is CSS-only, so the JavaScript uses an import map.** The theme's
`web/tailwind/package.json` runs `npx hyva-sources && npx hyva-tokens` and then Tailwind; there is no
JavaScript bundler in it, and nothing in `vendor/hyva-themes` or `vendor/magento` renders an import
map. So "path alias" is delivered in the two halves the platform actually offers:

- **CSS participates in the build pipeline properly.** `hyva-sources` emits an `@import` of a module's
  `view/frontend/tailwind/module.css` when that file exists, and an `@source` of the module directory
  when it does not. Shipping the file therefore puts the component CSS into the theme's stylesheet —
  and, because the `@source` is then *not* emitted for us, `module.css` declares its own `@source`
  lines. One of them covers `view/frontend/web/js`, because third-level entries are built in the
  browser and carry classes that appear in no `.phtml`.
- **JavaScript ships as ES modules behind bare specifiers**, bound to published static files by an
  import map the module renders itself. `getViewFileUrl()` builds every target, so the urls carry the
  deployment's static version, respect a separate static domain, and pick up `.min.js` names when
  `dev/js/minify_files` is on. There are no legacy script includes, no RequireJS, and nothing to
  rebuild when the module's JavaScript changes.

The same three specifiers are declared a second time in the module's `package.json` `exports` map, so
they resolve identically under `node --test`. The specs therefore import exactly what the storefront
imports, with no build step in between to disagree with. A spec asserts the two maps have not drifted
apart.

**The import map is a block of its own, in `head.additional`.** Two reasons it is not part of the
menu block. An import map has to be installed before the first module script starts loading, and
Hyvä loads Alpine as a deferred module from `before.body.end` — rendering the map from
`head.additional` puts it in front of every module script on the page, Alpine's included. And it must
not be cached with the menu: a block with a `cache_lifetime` skips its template on a hit, so a map
that travelled inside it would eventually be served with no CSP hash behind it and be blocked.

**Removed, not re-templated.** The layout removes `topmenu_generic` rather than overriding its
template. Two trees is the thing this module exists to stop doing, and removing the wrapper takes both
children with it — `GeneratorPool::removeElement()` recurses into the subtree. The removal is also
*scheduled* rather than immediate: `buildStructure()` applies the remove list after every layout file
has been read, so this instruction does not depend on being merged after the theme's. A merchant who
wants Hyvä's menu back undoes it from their own theme with
`<referenceBlock name="topmenu_generic" remove="false"/>`, which `Reader\Block::scheduleReference()`
reads as un-scheduling. The replacement is registered under the alias `topmenu`, which is the name
Hyvä's `Magento_Theme::html/header.phtml` asks for when it renders the navigation slot.

**Registration survives both load orders.** This module's entry script and Alpine's are both deferred,
and in a stock theme this file runs first — so the `alpine:init` listener is the path taken. A theme
that moves Alpine earlier would make that listener a subscription to an event that has already fired,
which is why registration branches: if `Alpine` is already on `window`, register immediately.

**Hover is gated on two questions, not one.** The media query answers "is there room for a dropdown",
which is not the same as "does this pointer hover". A tablet in landscape says yes to the first and no
to the second, and opening on `pointerover` there means the panel appears under the finger that was on
its way to the link. So `pointerover` opens a panel only when the placement is desktop **and**
`(hover: hover) and (pointer: fine)` matches.

**Only a recognised control is intercepted.** `onClick` calls `preventDefault()` after the delegated
lookup finds a `[data-menu-control]`, and not before. Anything else inside the component root — most
of all the anchors, which are the entire point of the server-rendered levels — keeps its default
behaviour and navigates.

**Top-level controls toggle; hover opens.** Pressing the control of an entry that is already open
closes it. Without that the only way out of an opened panel is a click elsewhere, which on a touch
screen means the control the shopper just pressed does nothing the second time.

**A tree editor is an extension path, not a feature.** Because a menu is a root category, everything a
merchant needs to author one already exists in **Catalog → Categories**: create a root, drag the
subtree, set `include_in_menu`, point the store view or a customer group at it. A CRUD module with its
own menu entity, its own tree UI and its own store/group scoping is a real product — and it is a
second source of truth for a structure the admin already edits. If you need one (menu entries that are
not categories, CMS pages or external links in the tree, per-menu banners), the seam is
`MenuTreeBuilder`: it is the only class that turns a root category id into a `MenuTree`, and
everything downstream of it — the block, the template, the island, the identities — consumes that
value object without caring where it came from.

## What gets shipped

```
src/
├── registration.php
├── composer.json                       # type: magento2-module
├── package.json                        # ES module scope + exports map + `npm test`
├── etc/
│   ├── module.xml                      # sequenced after Magento_Catalog, _Eav, _Store, _Theme, Hyva_Theme
│   ├── config.xml                      # defaults — no pinned root, no group map, third level on
│   ├── acl.xml                         # Scr1be_HyvaMegaMenu::config
│   └── adminhtml/system.xml            # the admin form, store-view scope throughout
├── Block/
│   ├── MegaMenu.php                    # IdentityInterface, cache key, lazy tree
│   └── MenuScripts.php                 # the import map + entry module tag, in head.additional
├── Model/
│   ├── Config.php                      # store-scoped reads of the three settings
│   ├── MenuResolver.php                # the four-candidate chain
│   ├── GroupMenuMap.php                # `group:root` parser
│   ├── RootCategories.php              # active roots, one query per store view
│   ├── MenuTreeBuilder.php             # one collection → MenuTree
│   ├── MenuTree.php                    # items + island + identities + sprite keys
│   └── Icon/
│       ├── Icon.php                    # value object: one type, one value
│       ├── IconResolver.php            # the four-way ladder, with the allow-lists
│       └── SpriteRegistry.php          # the symbol set, and which of it to inline
├── Setup/Patch/Data/
│   └── AddMenuIconAttributes.php       # the four category attributes
├── view/
│   ├── adminhtml/ui_component/
│   │   └── category_form.xml           # one collapsed fieldset, in ladder order
│   └── frontend/
│       ├── layout/default.xml          # remove topmenu_generic, add the replacement
│       ├── tailwind/module.css         # the whole presentation, picked up by hyva-sources
│       ├── templates/html/header/
│       │   ├── mega-menu.phtml         # sprite, chrome, tree, drawer, island
│       │   └── menu-scripts.phtml      # import map + CSP registration + entry module
│       └── web/js/
│           ├── menu-state.js           # the state machine — pure, no element in sight
│           ├── mega-menu.js            # the Alpine component — two seams, no DOM
│           └── mega-menu-register.js   # the adapter — DOM, matchMedia, Alpine, the island
├── i18n/en_US.csv
└── Test/
    ├── Unit/                           # 8 PHPUnit classes
    └── Js/                             # 4 node:test specs + a DOM double
```

No database schema. The four category attributes are the module's only stored state, and they are
created by an idempotent data patch — `EavSetup::addAttribute()` looks an attribute up first and
updates it when it is already there, so re-running the patch after a database restore re-states the
definitions instead of failing on a duplicate.

## Install

**Needs Hyvä 1.4 or newer** — not for its PHP, which calls into no Hyvä class, but for
`view/frontend/tailwind/module.css`: it uses `@source`, a Tailwind 4 directive. Tailwind 4 arrived
in `hyva-themes/magento2-default-theme` 1.4.0 (2025-11-10); the 1.3 line is still maintained and
still on Tailwind 3, where that stylesheet does not compile. The theme is a `suggest` rather than a
`require` because the menu itself renders on any theme — it just arrives unstyled.

```bash
# from your Magento 2 root
composer config repositories.scr1be-hyva-mega-menu path /path/to/Magento/hyva-mega-menu/src
composer require scr1be/hyva-mega-menu:@dev
bin/magento module:enable Scr1be_HyvaMegaMenu
bin/magento setup:upgrade
bin/magento setup:di:compile
```

Then add the module to your theme's Tailwind sources — `<theme>/web/tailwind/hyva.config.json`:

```json
{
    "tailwind": {
        "include": [
            { "src": "vendor/scr1be/hyva-mega-menu" }
        ]
    }
}
```

…and rebuild the stylesheet:

```bash
cd app/design/frontend/<Vendor>/<theme>/web/tailwind
npm run build      # or: npm run watch, while you are working on it
```

`npm run build` runs `hyva-sources` first, which is what turns that config entry into an `@import` of
this module's `module.css`. Skipping the rebuild leaves the menu rendered and unstyled.

If an admin other than a full-privilege one should reach the settings, grant **System → User Roles →
*role* → Role Resources → Stores → Settings → Configuration → Mega Menu**.

## Configuration

**Stores → Configuration → scr1be → Mega Menu → Menu source** — store-view scope throughout, because
the same website routinely runs one storefront off a different root category than the next.

| Setting | Default | Notes |
|---|---|---|
| Root category | *(empty)* | Id of the root category the menu is built from. Empty means "the root this store view is already assigned to", which is what almost every installation wants. An id that is not an active root category is ignored |
| Customer group overrides | *(empty)* | One `customerGroupId:rootCategoryId` pair per line — e.g. `2:5`. Leave empty unless you genuinely serve different catalogues to different groups: an empty map is what keeps the menu cached once per store view rather than once per group |
| Load the third level | Yes | Off, the third level is not queried at all and no island is rendered — worth doing on a catalogue whose third level is large and rarely used |

Per handle, in layout XML, a menu can be pinned to one root regardless of all three:

```xml
<referenceBlock name="scr1be.mega-menu">
    <arguments>
        <argument name="menu_root" xsi:type="number">5</argument>
    </arguments>
</referenceBlock>
```

The block also accepts `cache_lifetime`; set it to `null` from your own theme if you would rather the
menu rebuilt on every full-page-cache miss.

### Setting icons

**Catalog → Categories → *category* → Mega Menu** — one collapsed fieldset, in ladder order, so the
merchant filling the third field can see that the first two are empty. That relationship is exactly
what explains why a value is not being drawn, and splitting the fields across the form would hide it.

Sprite keys available out of the box: `home`, `tag`, `bag`, `shirt`, `gift`, `sparkle`, `truck`,
`percent`. Add your own to `SpriteRegistry::SYMBOLS` — 24×24, `fill="none"`, no colour of its own, so
`currentColor` keeps working.

The image field posts to core's own category image endpoint (`catalog/category_image/upload`), which
reads the field name from the `param_name` request parameter — so it goes through exactly the same
validation and the same storage as the stock Category Image field, and accepts the same four
extensions.

## Tests

```bash
# PHP — from your Magento 2 root, paths following the install method above
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/scr1be/hyva-mega-menu/Test/Unit

# JavaScript — from the module directory. No dependencies to install
cd vendor/scr1be/hyva-mega-menu && npm test
```

96 PHPUnit tests over 8 classes, aimed at the decisions that are silent when they are wrong:

- **`MegaMenuTest`** — that resolution runs once for both the cache key and the tree, that the tree is
  built once however many times the template asks, that the group is in the key only when a map
  exists, that a layout argument of `'women'` or `0` or `true` falls through, and that a category
  named `</script>` leaves the island hex-escaped.
- **`MenuResolverTest`** — the chain in order, each step falling through rather than failing,
  including a store whose root category id is `0`.
- **`MenuTreeBuilderTest`** — one query for the whole menu; the icon attributes selected alongside the
  name; the level filter changing with the third-level setting; an orphaned branch dropped wholesale
  rather than promoted; sprite keys collected once each.
- **`MenuTreeTest`** — the identity list, the cap, and the boundary either side of it.
- **`IconResolverTest`** — the ladder stepping aside at every rung, and the two allow-lists against
  values that would otherwise break out of a class attribute or a CSS declaration.
- **`SpriteRegistryTest`, `ConfigTest`, `GroupMenuMapTest`** — the chrome always injected, the
  store-view scope, and a parser that drops a malformed line instead of throwing.

The JavaScript specs run on `node:test` with no dependencies — a small DOM double stands in for the
browser, deliberately incomplete and deliberately not lenient, so anything the adapter starts using
that it does not implement fails loudly instead of passing quietly. They cover the state machine, the
component's policy (which events are intercepted, which are left alone, when hover may open a panel),
and — most of all — the adapter, because that is where the third-party contracts live.
`template-contract.test.js` is the one worth explaining: it reads the real `.phtml`, the real
`module.css` and the real `MenuScripts.php`, and asserts that every string the adapter declares as
"must match" actually appears in the file it must match. Rename a data attribute and nothing fails —
the menu still renders, still validates, still passes every other spec — it just stops opening.

## Demo notes

On a stock **Magento 2.4.8 + Hyvä 1.4 + Luma sample data** storefront. Luma's tree is a good demo
because it is genuinely three levels deep in one branch and two in others.

1. **See the duplication you are removing, first.** Before enabling the module, view-source on any
   page and search for a top-level category name — on stock Hyvä it appears twice, once in the
   `lg:hidden` mobile tree and once in the `hidden lg:flex` desktop one. Enable the module,
   `bin/magento cache:flush`, reload: once.

2. **One tree, two placements.** Open a desktop viewport and hover *Gear* — the panel opens as a
   single column of second-level entries. Hover *Bags* inside it and the panel widens to two columns
   as the third level arrives; the width is `--scr1be-mega-menu-columns` going from 1 to 2 in the
   element inspector, not a pixel value. Now drag the window narrower past `64rem`: the burger
   appears, and in the DOM inspector the same `<nav data-menu-tree>` node is now inside
   `[data-menu-dock="mobile"]`. Its element identity is unchanged — expand it before and after.

3. **The third level really is lazy.** With the panel closed, search the page source for a
   third-level category name (e.g. *Duffle Bags* under Gear → Bags): it is in the JSON island, not in
   any `<a>`. Open the branch once and it becomes anchors. Close and reopen — the `<ul>` keeps its
   `data-menu-filled="1"` and is not rebuilt.

4. **SEO shape.** `curl -s <storefront> | grep -c 'scr1be-menu__link--top'` counts the top-level
   anchors in the raw HTML, with no JavaScript involved. Disable JavaScript in the browser entirely:
   levels one and two are still navigable links, the drawer and the dropdowns simply do not open.

5. **Icons and the ladder.** On *Gear* set **Icon (sprite key)** to `bag` — it renders in the menu's
   text colour and follows hover. Now type `unicorn` instead and set **Icon (colour)** to `#e11d48`:
   the unknown key steps aside and a rose square is drawn. Type `red` into the colour field and both
   are ignored — no icon, no broken markup, no error.

6. **Customer-group menus.** Create a second root category with a couple of children, activate it, and
   set **Customer group overrides** to `1:<new root id>` (group 1 is *General* on a Luma install —
   check **Customers → Customer Groups**). Sign in as Veronica Costello (`roni_cost@example.com`,
   Luma's customer fixture) and the header shows the second tree; sign out and it is back. With the
   map set the menu now occupies one `block_html` entry per customer group; clear the map and it is
   back to one per store view.

7. **Cache invalidation.** With the full page cache warm, rename a category that is in the menu and
   reload the storefront — the page is invalidated because its `X-Magento-Tags` carried `cat_c_<id>`.
   Then set **Include in Menu** to *No* on another one: that is the case the bare `cat_c` tag covers,
   and it invalidates too.

8. **CSP.** The storefront policy ships report-only (`csp/mode/storefront/report_only` defaults to
   `1` in Magento_Csp's `config.xml`, and there is no admin field for it). Switch it to restrict with
   `bin/magento config:set csp/mode/storefront/report_only 0`, flush config, and reload. No
   violations from the menu: the only inline script it renders is the import map, and its hash — or
   its nonce, off the full page cache — was registered while its template ran.

Demo screenshots for the whole wave come after it is written; there are none in this folder yet.

## Compatibility

| | Version |
|---|---|
| PHP | 8.2, 8.3, 8.4 |
| Magento 2 | 2.4.6, 2.4.7, 2.4.8 |
| Hyvä Theme | 1.4.x |
| Alpine.js | 3.x, CSP build included |
| Tailwind CSS | 4.x, via `hyva-sources` |
| Node.js | 20+, for the specs only |

Not compatible with Hyvä 1.3: the layout removes `topmenu_generic`, which is the block name Hyvä 1.4
declares. On an earlier theme that removal is a no-op, so the theme's own menu renders alongside this
one.

Import maps are supported in all current evergreen browsers. There is no fallback for one that lacks
them — the menu would render, and the levels that are anchors would still navigate, but nothing would
open.

## Troubleshooting

**The menu renders unstyled.** The Tailwind build has not picked the module up. Check that the entry
is in your theme's `hyva.config.json` under `tailwind.include`, that `npm run build` was run from the
theme's `web/tailwind` directory afterwards, and that `generated/hyva-source.css` now contains an
`@import` ending in `hyva-mega-menu/view/frontend/tailwind/module.css`.

**Both menus are showing.** The removal of `topmenu_generic` has not been merged — the module is not
enabled, the layout cache is stale (`bin/magento cache:flush layout`), or the theme is not Hyvä 1.4.

**The menu does not open, and the console says nothing.** Almost always the import map. Check the page
source for `<script type="importmap">` in the `<head>`, and that it is *above* Alpine's module script;
then check that the urls in it resolve (a static content deployment that has not run leaves them
404ing).

**The menu is empty.** Resolution found no active root category. In descending order of likelihood:
the categories under the root have `include_in_menu` off, the root category itself is inactive, or
the store view has no store group. The chain never throws — an empty menu means every candidate was
rejected, not that something failed.

**An icon image is not showing.** The attribute stores a file name, and the storefront joins it with
the category media path. If the value in `catalog_category_entity_varchar` looks like an array or a
tmp path, the upload did not go through the image backend — clear the field and re-upload from the
category form.

**Third-level entries never appear.** Either *Load the third level* is off, or the branch genuinely
has no children in the store view being viewed — the island only carries what survived the
`is_active` and `include_in_menu` filters.

## License

MIT — see [LICENSE](LICENSE).
