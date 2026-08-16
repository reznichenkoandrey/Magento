# CLAUDE.md — working rules for this repository

Conventions for anyone — human or coding agent — writing code in this repo. What each project
*is* lives in [`README.md`](README.md); what each one had to do lives in [`ROADMAP.md`](ROADMAP.md).
This file is the how.

## Layout and naming

One folder per project, each standalone and installable on its own:

```
<project-in-kebab-case>/
├── LICENSE          MIT
├── README.md        what it does, what it costs, what to re-check on upgrade
└── src/             the module itself — registration.php, composer.json, etc/, view/, …
```

The folder is kebab-case (`hyva-product-card`), the module is PascalCase under the `Scr1be`
vendor namespace (`Scr1be_HyvaProductCard`). Three projects ship several modules — each gets its
own directory under `src/` with its own `composer.json`, and `src/composer.json` becomes a
metapackage.

**No project depends on another** (the one exception is documented in the README). A shared
helper that would couple two of them is a sign the boundary is in the wrong place.

Every module is written from its brief in `ROADMAP.md` against Magento and Hyvä source, not
adapted from another codebase. Where a decision was hard, the reasoning goes in a comment next to
the code rather than into a commit message nobody will find again.

## Magento + Hyvä: four things that fail silently

These share a shape worth naming: nothing errors, no test goes red, and the feature is simply
absent. Each one cost a full investigation before it was understood.

**1 — A theme's layout beats every module's.**
`Magento\Framework\View\Layout\File\Collector\Aggregated::getFiles()` adds base (module) files
first, then loops over the inherited themes. Hyvä declares `product_list_item` in the **theme**, so
a module's `<referenceBlock name="product_list_item" template="…"/>` is merged first and the
theme's own declaration puts its template back. A module `<sequence>` orders modules against
modules and cannot reach this. Re-apply from PHP on `layout_generate_blocks_after` — the first
point where the block object exists and nothing has rendered yet — and gate it on the module's own
enabled flag so switching the module off still returns the stock output.

**2 — An account controller cannot declare a return type.**
`Magento\Customer\Controller\Plugin\Account::aroundExecute()` returns an implicit `null` for a
guest: `Session::authenticate()` writes the login redirect onto the response and answers `false`,
so the plugin falls out of its `if` returning nothing. A narrowed `: Page` is copied onto the
generated interceptor, and the guest who should have been redirected gets a `TypeError` and an
HTTP 500. Core's own account controllers are untyped for exactly this reason. A unit test cannot
catch it — calling `execute()` directly never routes through an interceptor — so the contract is
pinned by asserting the signature itself.

**3 — One import map per document.**
Chromium 133+ and WebKit merge multiple import maps. **Firefox does not**: it installs the first
and rejects the rest, and every bare specifier the later maps declared fails to resolve — with no
console error in Chrome, where it works. Several Hyvä modules each printing their own map is
therefore broken in Firefox by construction. Import siblings by relative path and print no map at
all; keep one only for a specifier that is a genuine swap seam, and render it first in
`head.additional`.

**4 — A module script belongs in the head, not `before.body.end`.**
`<script type="module">` is deferred, deferred scripts run in document order, and Hyvä's Alpine tag
is the *first* block in `before.body.end`. Hyvä's `BlockJsDependencies` renders a dependency from
there — which works for its own inline classic scripts, because those execute at parse time, and
fails for a module: Alpine has already walked the DOM and found no component registered. Scope such
a block by **layout handle** instead. `catalog_list_item` is not a scoping handle — Hyvä's Page
Builder layout pulls it into `default`.

## PHP and templates

- **Never state a core contract from memory.** `vendor/magento` and `vendor/hyva-themes` are right
  there; open the class and read it. Every one of the four traps above was settled by reading the
  framework, and each had a plausible wrong answer available.
- **ViewModel over Block** for new frontend logic. A block is for when you genuinely need a layout
  node.
- **Templates instantiate nothing** — no `new`, no `*Factory->create()`. Reach for `$block->…` or
  `$viewModels->require(Foo::class)`.
- **Escape through `$escaper`** — `escapeHtml`, `escapeHtmlAttr`, `escapeUrl`, `escapeJs`, each for
  its own context. Not `htmlspecialchars()`, not a raw echo.
- **Build URLs with `getViewFileUrl()` / `getUrl()`.** The asset repository is what stamps the
  deployment's static version into the URL, honours a separate static domain, and appends `.min`
  via `Asset\Minification::addMinifiedSign()` when `dev/js/minify_files` is on outside developer
  mode. A hand-written path works in developer mode and 404s in production.
- **`final` on value objects is deliberate.** `phpcs --standard=Magento2` objects to it on principle;
  these classes are immutable data, never injected and never extended. Do not "fix" them.

## Frontend JavaScript

There is no build step — no bundler, no child theme, no `npm run build`. A module ships plain ES
modules under `<Module>/view/frontend/web/js/`, and a PHP block in `head.additional` emits one
`<script type="module">`. What is in the repository is what the browser runs.

- **Import siblings relatively** (`./card.js`). No import map needed, and none printed. See trap 3.
- **An import map is allowed only for a real swap seam** — a specifier a project is meant to rebind,
  bound through a `di.xml` argument. Then it is the only map on the page and renders first.
- **`package.json` `exports` is the Node half**, so specs import exactly the files the storefront
  does under `node --test`. It is not a browser map and does not imply one.
- **Register with Alpine on `alpine:init`, with `{ once: true }`.** Alpine re-dispatches the event if
  something restarts it, and registering twice replaces the definition on every element already
  mounted.
- **Pass configuration as a `<script type="application/json">` island**, not as attributes stuffed
  with serialised data. `application/json` is a media type no engine executes, so it needs no CSP
  treatment and any component can read it.
- **Register an inline script with CSP immediately after emitting it.** `HyvaCsp::registerInlineScript()`
  acts on the *last* script in the output buffer. The `$hyvaCsp` variable exists only under a Hyvä
  theme — check `isset()`, don't assume.
- **A script block never travels inside a cached block.** A block carrying `cache_lifetime` skips its
  template on a hit, and the CSP hash is registered while the template runs.

## Tailwind

Hyvä 1.4 ships **Tailwind 4**; module stylesheets use v4 syntax (`@source`, `@theme`). Each module
puts its CSS in `<Module>/view/frontend/tailwind/module.css`, which `npx hyva-sources` picks up
automatically. Point `@source` at the JavaScript as well as the templates — components that build
markup in the browser carry classes that appear in no `.phtml`, and Tailwind will otherwise compile
a stylesheet that is correct only until a shopper interacts.

Design values become tokens rather than arbitrary literals, and a token is named for its purpose
(`--font-size-topbar`), not its value.

## Quality gates

There is no CI and no git hook in this repo — the gates are run by hand, and running them is part
of finishing a change.

```bash
php -l <file>                                  # every touched PHP/PHTML file
xmllint --noout <file>.xml                     # every touched XML file
vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml <path>
```

Prefer fixing code over silencing a rule. No file-level ignores, and no inline suppression without a
comment saying why the rule is wrong here rather than the code.

## Tests

**PHP** — PHPUnit, using Magento's own unit configuration, which already covers
`app/code/*/*/Test/Unit`:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist --no-coverage app/code/Scr1be
```

Mock properties use native intersection types: `private FooInterface&MockObject $foo;`.

**JavaScript** — `node --test`, one package per project, specs in `src/Test/Js/`:

```bash
cd <project>/src && npm test
```

### What makes a test worth having

- **A unit test cannot see** a generated interceptor, layout merge order, or a second browser engine.
  All four traps above passed a green suite. Decide deliberately which half of a contract a test can
  actually observe, and pin the other half some other way — asserting a method signature is a fair
  answer when the behaviour only appears through an interceptor.
- **Re-read the test after writing it.** If it would still pass against an empty implementation, it
  is checking shape, not behaviour. For each assertion: does it observe a real consequence — state
  changed, event dispatched, DOM touched, argument received by a mock — or only that a callback
  exists?
- If the test name promises several effects, assert each one.
- For every listener, timer or subscription registered, test that teardown removes it.
- Import fixtures from the source rather than hand-rolling them; hand-rolled ones drift.
- A weak assertion sometimes means the scenario is fake, not that the author was lazy. Before
  strengthening it, ask whether that path runs at all — if a framework guarantees the state the test
  guards against, delete the test, and look hard at the guard too.
- Don't reach into private methods with Reflection or widen visibility for a test. If something
  isn't reachable through the public surface, that is usually a collaborator asking to be extracted.

## Commits and branches

Work on `feature/<name>` or `fix/<name>`; `main` takes no direct commits. History is linear and pull
requests land squashed, with `(#N)` in the subject.

Commit subjects say what changed and why it mattered, not which files moved. `Fixes #9, #10` closes
**only #9** — GitHub needs the keyword next to each number.
