# Headless API Suite

Six modules covering the GraphQL surface a native mobile app actually needs from a Magento store and
does not get: turning a guest checkout into an account, knowing which channel an order came from,
signing in with Google or Apple, a search drop-down that costs one round trip, sharing a wish list
without a form post, and pushing order updates to a phone.

They ship as a suite because they are one product decision — "there is an app" — but as six separate
modules because they have six different blast radii. Social login mints authentication tokens. Push
notifications hang off every sales email on the installation. Search autocomplete is a read-only
query. A project that wants the search box should not have to install a credential exchange, and an
admin who may edit attribution sources should not thereby be able to paste a Firebase key.

| Module | What it owns | GraphQL surface |
|---|---|---|
| `Scr1be_GuestRegistration` | Guest checkout → customer account, with an explicit decision ladder | `PlaceOrderOutput.customer_created` |
| `Scr1be_OrderAttribution` | Two soft-reference columns on the order, an admin-managed source registry, grid columns | `PlaceOrderInput.order_source`, `availableOrderSources` |
| `Scr1be_SocialLogin` | OIDC ID-token verification (Google, Apple), JWKS cache, `(provider, subject)` link table | `socialLogin`, `availableSocialLoginProviders` |
| `Scr1be_SearchAutocomplete` | One query fanning out to products, categories and popular terms | `searchAutocomplete` |
| `Scr1be_WishlistShare` | Per-recipient wish list sharing with a frozen result contract | `shareWishlist` |
| `Scr1be_PushNotifications` | Device registry, FCM HTTP v1 client, eight plugins on the sales email senders | `setCartDeviceToken` |

Every claim below about Magento's own behaviour was checked against the source in `vendor/` rather
than recalled, and the file is named where it matters.

## Why this exists

Magento's GraphQL coverage is genuinely good for the storefront it was designed against: a browser,
a session, a customer who registered through a form. An app is none of those, and the gaps are not
missing resolvers so much as missing *decisions*.

**A guest order in the API has nowhere to become an account.** The storefront has a checkbox on the
checkout that core wires up for you. `placeOrder` has nothing, and the app cannot fix it from
outside: by the time the mutation returns, the order is placed and the only thing the client has is
an increment id. Worse, the obvious implementation —
`if (!$order->getCustomerId()) { createAccount(); }` — is wrong in a way that only shows up in
production, because the address may already have an account, and core's
`AccountManagement::createAccountWithPasswordHash()` answers that with an `InputMismatchException`
(`vendor/magento/module-customer/Model/AccountManagement.php`) rather than with the account you
wanted.

**Attribution has to survive the quote.** "Which channel placed this order" sounds like a field on a
mutation, and the naive version sets it on the quote and reads it at submit. That fails
intermittently, which is the worst way to fail: `Magento\Quote\Model\QuoteRepository::save()` ends
with `unset($this->quotesById[$quote->getId()])`, so every save evicts the quote from the identity
map and the next `get()` rebuilds it from the database. Anything set in memory and not persisted is
gone, and whether it survives depends on which other module saved the quote last.

**Social login is a signature-verification problem wearing an OAuth costume.** The dangerous
implementations are the ones that decode the ID token's payload and trust it — or check `iss` and
`aud` *before* checking the signature, which is the same thing with extra steps. Until the signature
verifies, every claim in the token is attacker-controlled text.

**Autocomplete has one trap and it is silent.**
`Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection` defaults its `searchRequestName`
argument to `catalog_view_container`, and that container in
`Magento_CatalogSearch/etc/search_request.xml` declares no `$search_term$` binding — only
`quick_search_container` does. A collection built with the default accepts `addSearchFilter('shirt')`
without complaint and returns the catalogue. Nothing logs, nothing throws; the search box just
works badly.

**Push has to inherit "Notify Customer" or it is spam.** An admin who unticks that box on a shipment
has made a decision, and a notification system that hangs off an observer on `sales_order_save_after`
never learns about it.

## What's interesting (and what's just baseline)

| Choice | Why | Honest classification |
|---|---|---|
| Guest registration is a seven-rung ladder, and every rung returns | The interesting part is not the happy path, it is the six ways it does not apply. A boolean cannot tell "normal traffic" from "an incident" in a log | The distinction that makes it operable |
| `OrderCustomerManagementInterface::create()` rather than a hand-rolled account | Core's service extracts the customer from the order, calls `createAccount($customer)` with no password, and re-stamps the order — and a null password is what selects the "set your password" mail rather than a welcome one | Baseline, frequently reimplemented worse |
| The uniqueness race is handled by re-finding, not by failing | Two concurrent checkouts from one address both find no account and both create one. The second loses; the honest response is to attach the order to the winner | The bug the naive version ships |
| A module-namespaced event, not `customer_register_success` | Core's payload is `['account_controller' => $this, 'customer' => …]` and there is no controller in a GraphQL mutation. Dispatching it would hand every existing listener a key it may dereference and cannot | Opinionated, and the only version that does not break other people's code |
| Attribution lives in a request-scoped stack, not on the quote | It survives identity-map eviction, costs no migration, and a stack rather than a map because GraphQL can place two orders in one document | Architectural |
| The attribution plugin is `around` — the one place in the suite where that is right | It must refuse before core runs, hold state *while* core runs (the observer fires inside `$proceed()`), and release it whether core returns or throws. No other plugin type does all three | The justification most `around` plugins never get |
| Attribution columns are soft references with no foreign key | An order is a historical record. A merchant retiring a channel must not either be blocked or have January rewritten | Opinionated, and correct |
| Grid columns need `db_schema.xml` **and** the `Grid` virtual type | The declarative schema puts the columns in `sales_order_grid`; the `columns` map on `Magento\Sales\Model\ResourceModel\Order\Grid` is what fills them. Miss the second and the columns render blank with nothing in the logs | The trap, made explicit |
| The ID token's `alg` is checked before anything touches the signature | Accepting the token's own word for its algorithm is the `alg: none` family. RS256 is not configurable, because a second accepted algorithm is a second attack surface | Baseline, and skipped often enough to have a CVE genre |
| Claims are checked *after* the signature, never before | Until the signature passes, `iss` and `aud` are strings an attacker chose | The ordering that is the whole point |
| JWK → PEM is thirty lines of DER rather than a JWT library | The alternative is a dependency tree for one signature check. `ext-openssl` still does the cryptography; this only reshapes the key | Opinionated |
| The DER INTEGER gets a leading zero when the high bit is set | Without it the modulus reads as negative, and OpenSSL parses the PEM happily and then fails every signature | The single trap in the conversion |
| An unknown `kid` triggers exactly one refetch, rate-limited | Providers rotate without notice, so a cache with a miss path is required; refetching on every unknown `kid` is a free denial-of-service against your own store | The half most implementations miss |
| The social link table keys on `(provider, subject, website_id)` | `sub` is what an OIDC identity is. Email is a mutable attribute both providers let a user change, and matching on it hands out the wrong account the day an address is recycled | Baseline, commonly got wrong |
| Social account creation uses `customerRepository->save()`, not `createAccount()` | `createAccount()` sends a "set your password" email, which after tapping *Sign in with Google* is noise at best and a phishing lookalike at worst | Opinionated |
| Both `AlreadyExistsException` **and** `InputMismatchException` are caught | `CustomerRepository::save()` documents the second, but calls `$customerModel->save()` without a try/catch and the resource model raises the first. The exception that actually escapes is the one the docblock does not name | The detail you only find by reading the source |
| Autocomplete pins its own collection to `quick_search_container` | See above — the default container silently returns everything. Core solves this for its own quick search with a virtual type of exactly this shape | The silent trap |
| `addPriceData($groupId, $websiteId)` with both arguments | The no-argument form falls back to `$this->_customerSession->getCustomerGroupId()`, and a bearer-token GraphQL request has no storefront session | Baseline, and invisible when wrong |
| Autocomplete reuses `catalog/search/autocomplete_limit` | A module that invents its own limit leaves a merchant with two fields that claim the same thing | Baseline, rarely done |
| A blank limit becomes 8 rather than "unlimited" | Core's `DataProvider` treats a falsy limit as no limit; an autocomplete endpoint that can be asked for every match is a button marked *denial of service* | Deliberate divergence from core |
| Wish list sharing is one transport per recipient | Core wraps the whole `foreach` in one try/catch, so the first bad address aborts the run and the shopper is told nothing about who got it. Also: several recipients on one message means each sees the others' addresses | The API-shaped version of a form-shaped decision |
| Not-found and wrong-owner collapse into one message | Two different errors turn an id lookup into an oracle: walk the space and the errors tell you which ids exist | Baseline, and an audit finding when missing |
| SMTP errors are logged, never returned | "550 5.1.1 User unknown" describes the recipient's mailbox; "Connection refused to smtp.internal" describes the merchant's infrastructure. Neither is the sender's business | Opinionated |
| Push hangs off the eight email senders, not off order events | It inherits "Notify Customer" and the per-store email switch for free, and de-dupes for free | The design decision the module is built on |
| The entity senders are read via `send_email`, the comment senders via `$notify` | `send()` returns `true` only for a synchronous send, so async sending would silently stop pushes; and `NotifySender::checkAndSend()` returns `true` even when `$notify` is false, because it sent a *copy to the store* | Why there are two plugin classes rather than one |
| The device registry stores a hash as its key, and the cart stores only the hash | An FCM token is a credential to push arbitrary notifications to that device. There is no reason for a second copy on a table that gets dumped into staging | Opinionated |
| The device is attached at cart time, not order time | By the time the order exists the app may be gone — a payment callback can place it — and a guest order has no customer to look devices up by | The reason guest push works at all |
| `UNREGISTERED` deactivates the row immediately | A registry that treats a dead token as transient retries it on every order forever | The self-healing half |
| The shipped transport is a log sink | The whole chain is observable before any credentials exist, and a portfolio reviewer can see it work without a Firebase project | Deliberate |

## Architecture

### `Scr1be_GuestRegistration`

Three parts and one seam.

The **observer** (`sales_model_service_quote_submit_success`, declared in `etc/graphql/events.xml`)
is area-scoped rather than global. `Magento\Quote\Model\QuoteManagement::submitQuote()` dispatches
that event for every checkout on the installation — storefront, REST, admin, GraphQL — and only the
last is this module's business. Scoping it in the `graphql` area means the other three never load
the class at all, rather than loading it to run an `if`.

The **ladder** (`Model/GuestRegistrar`) is the module. Disabled → logged in → no email → email
belongs to an existing account (link, or skip if the merchant switched that off) → create → race.
It never throws: the order is placed and paid for by the time this runs, so an account-creation
problem is a support ticket, not a rolled-back checkout. Creation delegates to
`OrderCustomerManagementInterface::create()`, whose implementation
(`vendor/magento/module-sales/Model/Order/CustomerManagement.php`) extracts the customer, calls
`accountManagement->createAccount($customer)` and then re-reads the order to stamp `customer_id` and
`customer_is_guest = 0`.

The **holder** (`Model/RegistrationResultHolder`) carries the verdict across to the resolver, keyed
by increment id because one document may place more than one order. It implements
`ResetAfterRequestInterface` for the same reason core's own `PlaceOrder` resolver does — under a
persistent application server the object manager is not rebuilt between requests, and inheriting the
previous request's verdict would tell one shopper about another's new account.

The **plugin** is `after` on both resolvers that return `PlaceOrderOutput`: `PlaceOrder` and the
deprecated `SetPaymentAndPlaceOrder`. They are independent classes — the second does not delegate to
the first — but both return `['order' => ['order_number' => …], 'orderV2' => …, 'errors' => []]` on
success and `['errors' => [...]]` on failure, and the observer sits on the shared quote event. The
field is nullable rather than defaulting to `false`, because "no order was placed" and "an order was
placed and no account came of it" are answers a client branches on differently.

### `Scr1be_OrderAttribution`

An `around` plugin validates `input.order_source` against the registry and pushes the result onto a
request-scoped stack; the submit observer pops it off onto the order. The plugin releases the stack
in a `finally`, which is what makes the stack unambiguous — the bracket is exactly the window in
which the observer fires.

The observer binds to `sales_model_service_quote_submit_**before**`, not `_success`. The before event
fires after the order object is assembled and validated and before `orderManagement->place($order)`,
so the two columns ride along with the insert that creates the order. Using `_success` would cost a
second `save()` on the checkout's critical path — and a second round of `sales_order_save_after`
observers on an order other modules have already been told is finished. Nothing thrown in the
observer escapes: attribution is analytics, and a checkout that fails over a reporting column is a
worse outcome than a report with a gap in it.

Registry rows are soft-referenced by code, with a unique index on the code and `is_active` for
retirement. `Model/Source` pairs a `CACHE_TAG` constant with `$_cacheTag`, exactly as
`Magento\Cms\Model\Block` does, so `AbstractModel::afterSave()` → `cleanModelCache()` purges the
cached `availableOrderSources` query without a line of invalidation code.

Grid support is two declarations: the columns in `db_schema.xml` (which merges into core's own
`sales_order_grid` definition — the `db_schema.xml` reader is a
`Magento\Framework\Config\Reader\Filesystem` with `/schema/table` keyed by `name`, see
`app/etc/di.xml`), and the same two columns on the `Magento\Sales\Model\ResourceModel\Order\Grid`
virtual type's `columns` map, which is what `Magento\Sales\Model\ResourceModel\Grid::refresh()`
actually selects. Both columns default to hidden: the order grid already ships more columns than fit
on a laptop.

### `Scr1be_SocialLogin`

`AbstractVerifier` is the OIDC ID-token check, cheapest-first: three segments → header parses →
`alg` is RS256 → payload parses → **signature verifies** → `iss` → `aud` → `exp`/`iat`/`nbf` with 60
seconds of skew. `openssl_verify()` is compared against `1` explicitly, because it returns `-1` on
error and a boolean cast turns that into a pass.

Rejections log the real reason and return one opaque message with a **typed code** in
`extensions.code` — `SOCIAL_INVALID_TOKEN`, `SOCIAL_PROVIDER_UNAVAILABLE`,
`SOCIAL_EMAIL_UNAVAILABLE`, `SOCIAL_KEYS_UNAVAILABLE`, `SOCIAL_ACCOUNT_CONFLICT` — so a client can
branch (retry vs. re-authenticate vs. show a message) without parsing English. That works by
overriding `getExtensions()` on a `GraphQlInputException` subclass: `GraphQlInputException`
implements `GraphQL\Error\ProvidesExtensions`, and `GraphQL\Error\Error::__construct()` copies
`$previous->getExtensions()` when the previous exception implements it. The messages themselves stay
uninformative about *which* check failed — a sign-in endpoint that distinguishes "unknown key id"
from "wrong audience" for an unauthenticated caller is describing its verification to whoever is
probing it.

`JwksProvider` caches the key map in a dedicated cache type (`scr1be_social_jwks`) for an hour, keyed
per provider, and converts JWK → PEM once at cache time rather than once per sign-in. An unknown
`kid` means the provider rotated, so it refetches — once, behind a 60-second cooldown marker, so a
stream of forged `kid`s cannot turn your store into a load generator against Google.

`RsaPublicKey` builds the SubjectPublicKeyInfo by hand. The suite's test for it does not assert on
byte strings: it generates real RSA keys, converts, and checks the result is byte-identical to
OpenSSL's own encoding of the same key and that a real RS256 signature verifies against it.

`Provisioner` is the second ladder: linked `(provider, subject)` wins outright (it still holds when
the provider has since changed the account's email) → no link and no verified email refuses → email
matches an account on this website links → otherwise create → uniqueness race re-finds and links.
`email_verified` is required for the third rung, because without it any provider that lets a user
assert an unverified address is an account-takeover route.

The store comes from the GraphQL context and never from an argument. Accepting a store argument would
let a caller choose which website's customer table their Google account resolves against.

### `Scr1be_SearchAutocomplete`

One resolver, a pool, three providers keyed by the GraphQL field each fills, so a fourth source is a
class and one line of `di.xml`. Products come from a collection pinned to `quick_search_container`
via a module-owned virtual type — module-owned rather than core's `SearchCollectionFactory`, so a
project re-pointing that factory does not silently re-point this. Categories are a `LIKE` on the name
with `is_active = 1` and `level >= 2` (level 1 is the store's root, which is not a page anybody can
visit and would otherwise be suggested for "def" on a store rooted at *Default Category*). Terms come
from core's `Query\Collection::setPopularQueryFilter()`, which resets onto `search_query`, filters to
the store, adds `num_results > 0` and orders by popularity — so the "did it find anything" filter
comes from core rather than from a select that would drift from it.

Wildcards a shopper can type (`%`, `_`, `\`) are escaped in both `LIKE` providers. Not an injection —
the value is still bound — but a search box that answers nonsense for characters people type.

A term below the store's minimum length returns empty sections rather than an error: the client is
mid-typing, and the schema's non-null lists make "empty" a perfectly good answer.

### `Scr1be_WishlistShare`

The contract is frozen: every address the caller named comes back in exactly one of `sent` or
`failed`, whatever happened. Each recipient gets its own transport and its own `try`/`catch`, which
is the deliberate departure from `Magento\Wishlist\Controller\Index\Send::execute()` — core wraps the
whole loop in one try/catch and counts `$sent` only on success. That is defensible for a resubmittable
form post and not for an API, where the client's only recovery would be to re-send to everybody.

Recipient count and message length come from `wishlist/email/number_limit` and
`wishlist/email/text_limit`, so the API and the storefront form cannot disagree. Recipients are
de-duplicated case-insensitively.

The email uses this module's own template rather than core's, and the README owes you the reason:
core renders the item table by adding the `wishlist_email_items` layout handle and calling `toHtml()`
on `wishlist.email.items`. That handle is declared in a **frontend-area** layout file, and the block
resolves *which* wish list through `Magento\Wishlist\Helper\Data::getWishlist()`, which reads a
`shared_wishlist` registry entry or a session-backed provider. Neither exists in an API request, so
reusing core's template would mean half-emulating a storefront.

The shared URL is built with `UrlInterface` and an explicit `_scope`, which `Magento\Framework\Url`
lists among its reserved route parameters and turns into a `setScope()` call — so the link points at
the store the `Store` header named, with the store code a multi-store install puts in the path.

### `Scr1be_PushNotifications`

Eight plugins, two classes, eight virtual types carrying one notification type each. The split is not
cosmetic: `OrderSender` and friends are `send($entity, $forceSyncMode = false)` and begin with
`$entity->setSendEmail($this->identityContainer->isEnabled())` *before* branching on
`sales_email/general/async_sending`, so `send_email` is the decision and the return value is only a
report on one delivery attempt. The comment senders are `send($entity, $notify = true, $comment = '')`
and go through `NotifySender::checkAndSend()`, which returns `true` even when `$notify` is false —
because in that case it sent a copy to the *store's* address. A plugin keyed on the return value
would push to a shopper about a message they were deliberately not sent.

`setCartDeviceToken` registers the device and pins its hash to the cart, after checking the cart's
own ownership rule: a signed-in caller may only address their own cart, an anonymous one only a guest
cart. The submit observer copies the hash onto the order and claims the device row for the customer
if it was still unclaimed — which is what lets a guest who registered during checkout still be
reached by their *next*, web-placed order.

`FcmTransport` mints an RS256 service-account assertion without an SDK (RFC 7523 §2.1 — `aud` is the
token endpoint, not the FCM API, which is the field people get wrong and which fails as
`invalid_grant`), caches the access token, and reads FCM's `error.details[]` for the specific
`errorCode`. `UNREGISTERED` and `INVALID_ARGUMENT` come back as a distinct `PushResult` so
`OrderNotifier` can deactivate the row instead of retrying it forever.

`PushTransportInterface` is bound to `LogSinkTransport` by default. Swapping it is one `di.xml`
preference.

## What gets shipped

```
src/
├── composer.json                    metapackage: scr1be/headless-api-suite
├── GuestRegistration/               Scr1be_GuestRegistration
│   ├── Model/{GuestRegistrar,RegistrationResultHolder,RegistrationOutcome,Config}.php
│   ├── Observer/RegisterGuestAfterSubmit.php
│   ├── Plugin/StampCustomerCreated.php
│   └── etc/{module,acl,config}.xml, etc/adminhtml/system.xml, etc/graphql/{di,events}.xml, etc/schema.graphqls
├── OrderAttribution/                Scr1be_OrderAttribution
│   ├── Api/{SourceRepositoryInterface,Data/SourceInterface}.php
│   ├── Model/{Source,SourceRepository,SourceValidator,Attribution,AttributionHolder,…}.php
│   ├── Controller/Adminhtml/Source/{Index,NewAction,Edit,Save,Delete}.php
│   ├── Plugin/CaptureAttribution.php, Observer/StampOrderAttribution.php
│   ├── Ui/…, view/adminhtml/{layout,ui_component}/…   (listing, form, sales_order_grid columns)
│   └── etc/{module,acl,di,db_schema}.xml, etc/adminhtml/{di,menu,routes}.xml, etc/graphql/{di,events}.xml
├── SocialLogin/                     Scr1be_SocialLogin
│   ├── Model/Verifier/{AbstractVerifier,GoogleVerifier,AppleVerifier,VerifierPool,IdentityClaims}.php
│   ├── Model/Jwt/{JwksProvider,RsaPublicKey,Base64Url}.php, Model/Cache/JwksCache.php
│   ├── Model/{Provisioner,SocialLoginException}.php, Model/ResourceModel/SocialLink.php
│   └── etc/{module,acl,di,cache,db_schema}.xml, etc/adminhtml/system.xml, etc/schema.graphqls
├── SearchAutocomplete/              Scr1be_SearchAutocomplete
│   ├── Model/Provider/{ProductProvider,CategoryProvider,TermProvider}.php
│   ├── Model/{ProviderPool,SuggestionRequest,Config}.php
│   └── etc/{module,di}.xml, etc/schema.graphqls
├── WishlistShare/                   Scr1be_WishlistShare
│   ├── Model/{WishlistSharer,ShareOutcome,Config}.php, Model/Resolver/ShareWishlist.php
│   ├── view/frontend/email/share_wishlist.html
│   └── etc/{module,acl,config,email_templates}.xml, etc/adminhtml/system.xml, etc/schema.graphqls
└── PushNotifications/               Scr1be_PushNotifications
    ├── Api/PushTransportInterface.php
    ├── Model/Fcm/{FcmTransport,AccessTokenProvider,ServiceAccount}.php
    ├── Model/{OrderNotifier,MessageComposer,LogSinkTransport,PushMessage,PushResult,Config}.php
    ├── Model/ResourceModel/DeviceRegistry.php, Model/Resolver/SetCartDeviceToken.php
    ├── Plugin/{NotifyOnEntityEmail,NotifyOnCommentEmail}.php, Observer/CarryDeviceToOrder.php
    └── etc/{module,acl,di,events,config,db_schema}.xml, etc/adminhtml/system.xml, etc/schema.graphqls
```

Three tables are created (`scr1be_order_source`, `scr1be_social_login_link`,
`scr1be_headless_push_device`) and five columns are added to core tables: two on `sales_order` and
`sales_order_grid` for attribution, one on `quote` and one on `sales_order` for the device hash. All
declarative, all with whitelist entries, all dropped cleanly with the module.

## Install

The six modules are independent; install the ones you want. Both methods below are complete — pick
one and use its paths throughout, including for the test commands further down.

### From this repository (what the demo storefront does)

```bash
mkdir -p app/code/Scr1be
cp -R /path/to/Magento/headless-api-suite/src/GuestRegistration  app/code/Scr1be/GuestRegistration
cp -R /path/to/Magento/headless-api-suite/src/OrderAttribution   app/code/Scr1be/OrderAttribution
cp -R /path/to/Magento/headless-api-suite/src/SocialLogin        app/code/Scr1be/SocialLogin
cp -R /path/to/Magento/headless-api-suite/src/SearchAutocomplete app/code/Scr1be/SearchAutocomplete
cp -R /path/to/Magento/headless-api-suite/src/WishlistShare      app/code/Scr1be/WishlistShare
cp -R /path/to/Magento/headless-api-suite/src/PushNotifications  app/code/Scr1be/PushNotifications

bin/magento module:enable \
  Scr1be_GuestRegistration Scr1be_OrderAttribution Scr1be_SocialLogin \
  Scr1be_SearchAutocomplete Scr1be_WishlistShare Scr1be_PushNotifications
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### With Composer

The metapackage pulls in all six. This repository sets no `installer-paths`, so the packages land
under `vendor/scr1be/`:

```bash
composer require scr1be/headless-api-suite
# -> vendor/scr1be/headless-guest-registration, -order-attribution, -social-login,
#    -search-autocomplete, -wishlist-share, -push-notifications

bin/magento module:enable \
  Scr1be_GuestRegistration Scr1be_OrderAttribution Scr1be_SocialLogin \
  Scr1be_SearchAutocomplete Scr1be_WishlistShare Scr1be_PushNotifications
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

There is no frontend asset in this suite, so no Tailwind rebuild is needed.

## Configuration

Everything lives under **Stores → Configuration → scr1be**, except the source registry, which is a
grid of its own.

| Setting | Path | Scope | Default |
|---|---|---|---|
| Create accounts from GraphQL guest orders | `scr1be_guest_registration/general/enabled` | Store view | **No** |
| Attach orders to accounts that already exist | `scr1be_guest_registration/general/link_existing` | Store view | Yes |
| Google OAuth client ID | `scr1be_social_login/google/client_id` | Store view | *(blank — provider off)* |
| Apple Services ID / bundle ID | `scr1be_social_login/apple/client_id` | Store view | *(blank — provider off)* |
| Wish list share email template | `scr1be_wishlist_share/email/template` | Store view | this module's template |
| Wish list share sender | `scr1be_wishlist_share/email/identity` | Store view | — |
| Send order notifications | `scr1be_push/general/enabled` | Store view | **No** |
| FCM service account key (JSON) | `scr1be_push/fcm/service_account` | Default only | *(blank)* |

Guest registration is **off by default**, on purpose: creating accounts for people who did not ask
for one has a legal dimension in several jurisdictions, and a module that starts doing it the moment
it is enabled has made that decision for the merchant.

Social login has no separate enable flag. A blank client id switches the provider off for that scope,
because a provider with no configured audience cannot verify anything anyway.

Wish list recipient and message limits are deliberately **not** here: they come from
**Customers → Wish List → Share Options**, so the API and the storefront form cannot disagree.

Pasting an FCM key does **not** switch the transport on — the shipped binding is a log sink. Point the
preference at `FcmTransport` when you are ready:

```xml
<preference for="Scr1be\PushNotifications\Api\PushTransportInterface"
            type="Scr1be\PushNotifications\Model\Fcm\FcmTransport"/>
```

**Order attribution sources** live at **Stores → Other Settings → Order Attribution Sources**. Codes
are restricted to 2–32 characters of lowercase letters, digits, `-` and `_`, which removes the whole
class of "why does the report have `iOS ` and `ios`" questions before it starts.

### ACL

| Resource | Guards |
|---|---|
| `Scr1be_GuestRegistration::config` | its configuration section |
| `Scr1be_SocialLogin::config` | its configuration section (client ids) |
| `Scr1be_WishlistShare::config` | its configuration section |
| `Scr1be_PushNotifications::config` | its configuration section (the Firebase key) |
| `Scr1be_OrderAttribution::source` | the source registry grid, form and delete |

The registry gets its own resource rather than riding on `Magento_Config::config`, because editing it
changes what an app is allowed to send and what every attribution report groups by. That is a
merchandising decision, not a configuration toggle.

## Design decisions

**Why `customer_created` is nullable.** `false` means "an order was placed and no account came of
it". `null` means "there is nothing to report" — which is the honest answer both when `placeOrder`
returned errors instead of an order and when the module is switched off for the store view. A mobile
client branches on those differently, and collapsing them into `false` would have it offer a
"set your password" prompt after a failed checkout.

**Why `LINKED_EXISTING` reports `customer_created: false`.** The flag exists so the app can decide
whether to show a password prompt. Showing it to somebody who already has a password is worse than
showing nothing.

**Why the deprecated `setPaymentMethodAndPlaceOrder` gets the registration plugin but not the
attribution one.** The registration plugin only reads a value the shared quote observer already
produced, so covering the second mutation costs one `di.xml` entry and prevents the same order
reporting differently depending on how it was placed. Attribution would require adding a second input
field to a mutation core has marked `@deprecated`. An order placed that way simply carries no
attribution — the same thing that happens to a storefront order.

**Why an unknown attribution source fails the mutation rather than being swallowed.** The brief's
"failures swallowed so checkout never breaks" is implemented where it belongs: in the observer, which
runs *inside* the submit and catches everything. Validation runs in the plugin, before core is
called, before any order exists — so refusing there costs the shopper a retry with a corrected
payload rather than a broken checkout. A silently-dropped attribution is a reporting gap nobody
notices for a quarter; a rejected mutation is a bug the app developer fixes the same day.

**Why `availableOrderSources` is public and unauthenticated.** An app has to know the vocabulary
before it has a customer token — its first order may well be a guest order. The list is a set of
channel names the merchant chose, not data about anybody. The alternative is an app hardcoding the
codes and drifting from the registry the first time a merchant adds one.

**Why the cache identity returns an empty array for an empty registry.** Core's own identities do
this (`Magento\CmsGraphQl\Model\Resolver\Block\Identity` returns `[]` when there are no items), and
in the full-page-cache path an empty tag set makes `CacheableQuery::shouldPopulateCacheHeadersWithTags()`
false, which makes `Magento\GraphQlCache\Controller\Plugin\GraphQl` call `setNoCacheHeaders()`. So a
merchant who has not populated the registry yet sees their first source appear immediately rather
than after a cache flush.

**Why Apple's `email_verified` is checked for both `true` and `"true"`.** Apple sends the string for
relay addresses and the boolean otherwise. Both mean verified, and a strict boolean check would lock
out every *Hide My Email* user.

**Why relay addresses are not treated as second-class.** `…@privaterelay.appleid.com` forwards to the
real address; it is a perfectly good account identifier and a perfectly good delivery address. It is
also a reminder that email is not identity here, which is why the link table keys on `sub`.

**Why autocomplete badges are only `discount` and `from_price`.** Both are decidable from the row
already loaded: a discount is two columns the price index returned, and the type id is a column on
the product. "New", "bestseller" and "low stock" each need another join or another index, and an
autocomplete costing four queries per keystroke is not autocomplete. `from_price` is set for bundles
only — `Magento\Catalog\Model\Product\Type` declares `simple`, `bundle` and `virtual` and nothing
else, since `configurable` lives in `Magento_ConfigurableProduct`. Taking a dependency on that module
for one badge was not worth it; it is one line to add in a project that already has it.

**Why the wish list failure reasons are coarse.** `INVALID_ADDRESS` is the shopper's typo and is
fixable in the form. `DELIVERY_FAILED` is the store's problem and the only useful advice is "try
again later". Any finer distinction would be describing the recipient's mailbox or the merchant's
infrastructure to whoever typed the address.

**Why the push registry deactivates rather than deletes.** The row records that the device existed
and why it stopped working, which is the difference between diagnosing "the app never registered" and
"the app registered and was uninstalled".

**Why the device foreign key is `ON DELETE SET NULL`.** Deleting an account should not silently
unsubscribe a phone that is still in somebody's pocket and may sign into another account five minutes
later. The row degrades to an unclaimed device.

**Why `claim()` only updates rows where `customer_id IS NULL`.** A device already claimed by one
customer must not be re-claimed by another; otherwise a shared or resold handset leaks one person's
order notifications to the next.

## Tests

160 unit tests, 297 assertions, all green.

| Module | Tests | Assertions |
|---|---|---|
| `Scr1be_GuestRegistration` | 25 | 52 |
| `Scr1be_OrderAttribution` | 27 | 40 |
| `Scr1be_SocialLogin` | 45 | 99 |
| `Scr1be_SearchAutocomplete` | 17 | 24 |
| `Scr1be_WishlistShare` | 13 | 23 |
| `Scr1be_PushNotifications` | 33 | 59 |

Every behaviour class has one: both decision ladders and each of their rungs, the request-scoped
holder and stack (including `_resetState`), both resolver plugins, the source validator, the
attribution observer's swallow-everything guarantee, the JWT verifier's ordering and each rejection,
the JWKS refresh and its cooldown, the DER encoder, the provider pool, the category and autocomplete
providers, the wish list resolver's authorisation and validation, the sharer's per-recipient
isolation, the FCM transport's error interpretation and the service-account parser, and both email
plugins' signal-reading.

Three are worth calling out.

`SocialLogin/Test/Unit/Model/Jwt/RsaPublicKeyTest.php` does not assert on remembered byte strings. It
generates real RSA keys, runs them through the converter, and checks the output is byte-identical to
OpenSSL's own encoding of the same key and that a genuine RS256 signature verifies against it. A DER
encoder tested against a fixture is tested against whatever the author believed when they wrote the
fixture.

`SocialLogin/Test/Unit/Model/ProvisionerTest.php` uses a *stateful* `CustomerInterface` double,
because the create path both writes and reads the customer. Its closures take `$state` by explicit
reference: an arrow function would bind the array by value at creation and every getter would answer
null forever, which would make these assertions pass or fail for reasons unrelated to the
`Provisioner`. That is documented in the file, because it is the kind of thing that silently rots a
test suite into decoration.

The GraphQL resolver tests build `ContextExtensionInterface` through a small helper that decides per
method between `onlyMethods()` and `addMethods()`. The interface has two shapes: on a bare checkout it
does not exist and the unit-test autoloader
(`Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator`) stubs it as
an *empty* interface, so `getStore()` must be added; inside a working installation, `generated/code`
holds the real one built from every module's `extension_attributes.xml`, and adding a declared method
is a fatal `CannotUseAddMethodsException`. Core's own GraphQL unit tests assume only the first case
(see `Magento\CatalogUrlRewriteGraphQl\Test\Unit\Model\Resolver\CategoryUrlSuffixTest`) and therefore
fail when run from inside an installed store. These do not.

Run them. The suite is not installed as a Magento module in this repository, so use a throwaway
bootstrap that registers the PSR-4 roots on top of Magento's unit-test bootstrap:

```php
<?php
// bootstrap.php, at the Magento root
require __DIR__ . '/dev/tests/unit/framework/bootstrap.php';

$src = __DIR__ . '/Magento/headless-api-suite/src';
$roots = [
    'Scr1be\\GuestRegistration\\'  => $src . '/GuestRegistration/',
    'Scr1be\\OrderAttribution\\'   => $src . '/OrderAttribution/',
    'Scr1be\\SocialLogin\\'        => $src . '/SocialLogin/',
    'Scr1be\\SearchAutocomplete\\' => $src . '/SearchAutocomplete/',
    'Scr1be\\WishlistShare\\'      => $src . '/WishlistShare/',
    'Scr1be\\PushNotifications\\'  => $src . '/PushNotifications/',
];

spl_autoload_register(static function (string $class) use ($roots): void {
    foreach ($roots as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $path = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
            return;
        }
    }
});
```

```bash
vendor/bin/phpunit --bootstrap bootstrap.php \
  Magento/headless-api-suite/src/GuestRegistration/Test/Unit \
  Magento/headless-api-suite/src/OrderAttribution/Test/Unit \
  Magento/headless-api-suite/src/SocialLogin/Test/Unit \
  Magento/headless-api-suite/src/SearchAutocomplete/Test/Unit \
  Magento/headless-api-suite/src/WishlistShare/Test/Unit \
  Magento/headless-api-suite/src/PushNotifications/Test/Unit
```

If you installed the modules into `app/code/Scr1be/` instead, drop the bootstrap entirely — the
Magento autoloader already knows the namespaces — and point PHPUnit at
`app/code/Scr1be/*/Test/Unit`.

## Demo notes

On a Magento 2.4.8 + Hyvä 1.4 install with Luma sample data. Everything below is GraphQL, so a
`.http` file or Altair is enough; no app is needed.

**Autocomplete** is the fastest thing to show and needs no configuration at all:

```graphql
{ searchAutocomplete(query: "shirt") {
    query
    products { name sku final_price currency badges image_url }
    categories { name url_path product_count }
    terms { query_text result_count is_exact_match }
} }
```

Luma has enough catalogue for all three sections to be non-empty. Type `sh` instead and everything
comes back empty rather than erroring — that is the minimum-query-length behaviour, and
`catalog/search/min_query_length` moves it. To see the trap this module avoids, temporarily point the
provider at core's `Fulltext\CollectionFactory` in `di.xml`: the `products` section starts returning
the catalogue in SKU order for every term, and nothing anywhere says why.

**Guest registration**: switch it on for the store view, then place a guest order through
`placeOrder` with an email that has no account. The mutation returns `customer_created: true` in the
same round trip, and *Customers → All Customers* has a new row whose *Confirmed email* is the address
you used, with the order attached rather than a guest order. Run it a second time with the *same*
address and you get `customer_created: false` with the order still attached — the linking rung. Turn
`link_existing` off and the second order stays a guest order.

**Attribution**: create a source at *Stores → Other Settings → Order Attribution Sources* (code
`ios-app`, label `iOS app`, active). Then:

```graphql
{ availableOrderSources { code label } }
```

and place an order with `order_source: { source_code: "ios-app", source_detail: "build-412" }` in the
`placeOrder` input. Turn the two columns on in the order grid's *Columns* dropdown to see them, and
filter on the source. Deactivate the source and place another order: the mutation is refused, while
the orders already attributed to it keep saying `ios-app` — the soft reference doing its job. Send an
unknown code and you get a clean GraphQL error rather than a stored typo.

**Wish list sharing**: sign a customer in (`generateCustomerToken`), add a couple of products to the
wish list, then share it to three addresses, one of which is deliberately malformed:

```graphql
mutation { shareWishlist(input: {
    emails: ["a@example.com", "not-an-address", "b@example.com"],
    message: "Look at these"
}) { wishlist_id shared_url sent failed { email reason } } }
```

With MailHog or a catch-all SMTP, two messages arrive as separate emails — each recipient sees only
their own address — and the response reports `sent: ["a@…", "b@…"]` with the third in `failed` as
`INVALID_ADDRESS`. The `shared_url` works in a browser. Point the store at a dead SMTP host and every
address comes back `DELIVERY_FAILED` with the real reason in `var/log/exception.log` and nowhere in
the response.

**Push** demos end-to-end without Firebase, which is the point of the log-sink default. Switch
notifications on for the store view, register a device against a cart:

```graphql
mutation { setCartDeviceToken(input: {
    cart_id: "<masked cart id>", device_token: "demo-token-1", platform: "ios"
}) { success } }
```

place the order, then ship it from the admin with *Notify Customer by Email* ticked. `var/log/`
carries the composed notification with the order's increment id. Untick the box and ship another —
no log line, because the plugin read the sender's decision rather than the fact that a shipment
happened. `scr1be_headless_push_device` shows the row, claimed to the customer id if the order had
one.

**Social login** is the one that needs external setup: a Google OAuth client id in configuration and
a real ID token from a client. `{ availableSocialLoginProviders }` is demoable on its own — it
returns `[]` with no client ids configured and `["google"]` once one is set, which is exactly what a
client uses to decide which buttons to draw. A malformed or expired token returns one opaque
"could not be verified" message while `var/log/system.log` records which of the eight checks
rejected it.

Screenshots of the admin source registry and the order grid columns live in
`../demo-screenshots/` once the wave is complete.

## Compatibility

- Magento Open Source / Adobe Commerce **2.4.6 – 2.4.8**
- PHP **8.2 – 8.4**
- No Hyvä dependency and no theme dependency. The suite ships no storefront template, no layout XML
  and no JavaScript; its only `view/frontend` file is `Scr1be_WishlistShare`'s transactional email
  body, which renders through `TransportBuilder` rather than through a theme. It coexists with Luma,
  Hyvä or a headless front end equally.
- No paid extensions, no third-party PHP packages. `ext-openssl` and `ext-curl` are declared by
  `Scr1be_SocialLogin` and `Scr1be_PushNotifications`; both are already required by
  `magento/framework`, so any working install has them.
- Elasticsearch or OpenSearch, as configured for the store — `Scr1be_SearchAutocomplete` goes through
  core's search request, so whatever the storefront search uses is what it uses.

## Troubleshooting

**`customer_created` is always null.** The observer is declared in `etc/graphql/events.xml`, so it
only runs in the GraphQL area — a storefront or admin order will never set it. Check the module is
enabled for the store view, then check `var/log/system.log` for a `Scr1be_GuestRegistration` line:
the ladder logs every failure and never throws.

**Attribution columns are empty in the grid but present on the order.** The `Grid` virtual type entry
in `etc/di.xml` did not compile. Run `bin/magento setup:di:compile`, then re-save an order (or wait
for the grid async index) to repopulate the row.

**Every social sign-in fails with "could not be verified".** `var/log/system.log` names the actual
reason for each rejection. The two common ones are a client id that does not match the token's `aud`,
and a system clock more than 60 seconds out — the verifier allows that much skew and no more.

**Autocomplete returns the whole catalogue.** Something has re-pointed the collection factory away
from the `quick_search_container`-pinned virtual type. Check `etc/di.xml` survived compilation, and
that no project `di.xml` overrides `Scr1be\SearchAutocomplete\Model\Provider\ProductProvider`'s
`collectionFactory` argument.

**Wish list emails do not arrive but the response says `sent`.** `sent` means the message reached the
transport, not the inbox. The transport's own errors are in `var/log/exception.log`; deliberately
nothing about them appears in the API response.

**No push log lines.** The default transport is the log sink, so notifications appear in `var/log/`,
not in Firebase. If nothing appears at all: the store view flag is off, or the sales email you
triggered was sent without *Notify Customer* — which is the module working as designed.

## License

MIT. See [LICENSE](LICENSE).
