/**
 * Copyright (c) 2026 scr1be. MIT licensed.
 *
 * The storefront half of the switcher.
 *
 * Two Alpine components, because the two renderers hand the browser different things: the desktop
 * list arrives with finished redirect URLs in its option values and only has to navigate, while
 * the drawer arrives with store codes and has to compose the redirect itself. Everything that is
 * not DOM work is exported as a plain function so the contract with Magento — the shape of the
 * redirect URL, the encoding of `uenc` — can be checked without a browser.
 */

/** The element the drawer template writes its JSON into. */
export const CONFIG_SELECTOR = '[data-scr1be-store-switcher-config]';

/** The names the templates put in `x-data`. */
export const COMPONENT_LINKS = 'scr1beStoreSwitcherLinks';
export const COMPONENT_DRAWER = 'scr1beStoreSwitcherDrawer';

const EMPTY_CONFIG = {
    currentCode: '',
    currentBaseUrl: '',
    redirectUrl: '',
    storeParam: '___store',
    fromStoreParam: '___from_store',
    targetUrlParam: 'uenc',
    stores: []
};

/**
 * Magento's base64 URL alphabet.
 *
 * `Magento\Framework\Url\Encoder::encode()` is `strtr(base64_encode($url), '+/=', '-_~')`, and
 * `Magento\Framework\Url\Decoder::decode()` reverses exactly that mapping. Getting the third pair
 * wrong (`,` instead of `~` is the usual slip) produces a value that decodes to nothing, and the
 * redirect then silently falls back to the store's home page.
 *
 * @param {string} url
 * @returns {string}
 */
export const encodeTargetUrl = (url) => {
    const bytes = new TextEncoder().encode(url);

    let binary = '';
    bytes.forEach((byte) => {
        binary += String.fromCharCode(byte);
    });

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '~');
};

/**
 * The same page, addressed in the target store.
 *
 * The current path is recovered by subtracting the current store's base URL from the current href
 * rather than by reading `location.pathname`, because the base URL may carry a store code segment
 * (`/de/`) that belongs to the store being left, not to the one being entered. When the href does
 * not start with the base URL — a host alias, a URL rewritten by an edge — the target degrades to
 * the store's home page instead of guessing.
 *
 * @param {Object} config
 * @param {string} storeCode
 * @param {string} currentHref
 * @returns {string|null}
 */
export const buildTargetUrl = (config, storeCode, currentHref) => {
    const store = (config.stores || []).find((candidate) => candidate.code === storeCode);

    if (!store) {
        return null;
    }

    const base = config.currentBaseUrl || '';
    const suffix = base && currentHref.startsWith(base) ? currentHref.slice(base.length) : '';

    return store.baseUrl + suffix;
};

/**
 * The URL of core's store redirect controller, carrying the three parameters it reads.
 *
 * @param {Object} config
 * @param {string} storeCode
 * @param {string} currentHref
 * @returns {string|null}
 */
export const buildRedirectUrl = (config, storeCode, currentHref) => {
    const target = buildTargetUrl(config, storeCode, currentHref);

    if (target === null || !config.redirectUrl) {
        return null;
    }

    const url = new URL(config.redirectUrl);
    url.searchParams.set(config.storeParam, storeCode);
    url.searchParams.set(config.fromStoreParam, config.currentCode);
    url.searchParams.set(config.targetUrlParam, encodeTargetUrl(target));

    return url.toString();
};

/**
 * @param {Document} doc
 * @returns {Object}
 */
export const readConfig = (doc) => {
    const element = doc.querySelector(CONFIG_SELECTOR);

    if (!element) {
        return EMPTY_CONFIG;
    }

    try {
        return Object.assign({}, EMPTY_CONFIG, JSON.parse(element.textContent));
    } catch (error) {
        // A malformed payload leaves a switcher that does nothing. Throwing here would take every
        // other Alpine component on the page down with it.
        return EMPTY_CONFIG;
    }
};

/**
 * Desktop: the option values are already redirect URLs.
 *
 * The selected value is read through `$refs` rather than off the change event, so the template
 * needs no expression in its `x-on` attribute — a bare method reference is all a strict CSP build
 * of Alpine will evaluate.
 */
export const linksComponent = () => ({
    switchStore() {
        const value = this.$refs.select ? this.$refs.select.value : '';

        if (value) {
            window.location.assign(value);
        }
    }
});

/**
 * Drawer: the option values are store codes, and the redirect is composed here.
 *
 * @param {Object} config
 */
export const drawerComponent = (config) => ({
    switchStore() {
        const code = this.$refs.select ? this.$refs.select.value : '';

        if (!code || code === config.currentCode) {
            return;
        }

        const url = buildRedirectUrl(config, code, window.location.href);

        if (url) {
            window.location.assign(url);
        }
    }
});

/**
 * The seam. Everything above is testable without a DOM; this is the part that is a promise to
 * Alpine, to the templates and to the element the block renders.
 *
 * @param {Window} win
 * @param {Document} doc
 */
export const registerStoreSwitcher = (win, doc) => {
    win.addEventListener(
        'alpine:init',
        () => {
            const config = readConfig(doc);

            win.Alpine.data(COMPONENT_LINKS, linksComponent);
            win.Alpine.data(COMPONENT_DRAWER, () => drawerComponent(config));
        },
        { once: true }
    );
};

registerStoreSwitcher(window, document);
