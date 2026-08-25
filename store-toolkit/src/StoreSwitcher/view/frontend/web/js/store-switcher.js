/**
 * Copyright (c) 2026 scr1be. MIT licensed.
 *
 * The storefront half of the switcher, minus the seam.
 *
 * Two Alpine components, because the two renderers hand the browser different things: the desktop
 * list arrives with finished redirect URLs in its option values and only has to navigate, while
 * the drawer arrives with store codes and has to compose the redirect itself. Everything here is
 * a plain function over its arguments — the window is passed in rather than reached for — so the
 * contract with Magento (the shape of the redirect URL, the encoding of `uenc`) can be checked
 * without a browser. The part that touches `window` is `store-switcher-register.js`.
 */

/**
 * The defaults a missing or malformed config island falls back to.
 *
 * Frozen, and `stores` with it: a spread of this object is shallow, so every fallback would
 * otherwise hand out the *same* array. Nothing mutates it today, which is exactly why it would
 * be an unpleasant thing to discover later.
 */
export const EMPTY_CONFIG = Object.freeze({
    currentCode: '',
    currentBaseUrl: '',
    redirectUrl: '',
    storeParam: '___store',
    fromStoreParam: '___from_store',
    targetUrlParam: 'uenc',
    stores: Object.freeze([])
});

/** A mutable copy of the defaults, safe to hand to a caller. */
export const emptyConfig = () => ({ ...EMPTY_CONFIG, stores: [] });

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
 * Desktop: the option values are already redirect URLs.
 *
 * The selected value is read through `$refs` rather than off the change event, so the template
 * needs no expression in its `x-on` attribute — a bare method reference is all a strict CSP build
 * of Alpine will evaluate.
 *
 * @param {Window} win
 */
export const linksComponent = (win) => ({
    switchStore() {
        const value = this.$refs.select ? this.$refs.select.value : '';

        if (value) {
            win.location.assign(value);
        }
    }
});

/**
 * Drawer: the option values are store codes, and the redirect is composed here.
 *
 * @param {Object} config
 * @param {Window} win
 */
export const drawerComponent = (config, win) => ({
    switchStore() {
        const code = this.$refs.select ? this.$refs.select.value : '';

        if (!code || code === config.currentCode) {
            return;
        }

        const url = buildRedirectUrl(config, code, win.location.href);

        if (url) {
            win.location.assign(url);
        }
    }
});
