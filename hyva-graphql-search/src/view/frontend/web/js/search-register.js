import { instantSearchComponent } from './instant-search.js';

/**
 * The seam between this module and the page: the only file that touches `window`.
 *
 * Everything it names is a promise to something outside this file — the component name the
 * template writes into `x-data`, the element the PHP block prints the configuration into,
 * and Alpine's own init event. Those are exactly the things that break silently when one
 * side is renamed, which is why they are constants with specs on them.
 */

/** The element `search-scripts.phtml` writes the endpoint configuration into. */
export const CONFIG_SELECTOR = '[data-scr1be-search-config]';

/** The name `search-bar.phtml` writes into `x-data`. */
export const COMPONENT_NAME = 'scr1beInstantSearch';

export const ALPINE_INIT_EVENT = 'alpine:init';

/** Used when the island is missing or malformed, so a broken config degrades rather than throws. */
export const FALLBACK_CONFIG = {
    graphqlUrl: '/graphql',
    searchResultUrl: '/catalogsearch/result/',
    productUrlSuffix: '',
    pageSize: 8,
    cacheTtlMs: 300000,
    minQuery: 3,
};

/**
 * @param {Document} doc
 * @returns {object} The parsed configuration, or the fallback.
 */
export const readConfig = (doc) => {
    const element = doc.querySelector(CONFIG_SELECTOR);
    if (!element) return { ...FALLBACK_CONFIG };

    try {
        return { ...FALLBACK_CONFIG, ...JSON.parse(element.textContent) };
    } catch (error) {
        // A malformed island means a search box that still renders and still searches, just
        // against the defaults. That is a degraded feature, not a broken header.
        return { ...FALLBACK_CONFIG };
    }
};

/**
 * @param {Window} win
 * @param {Document} doc
 */
export const register = (win, doc) => {
    const config = readConfig(doc);

    // `{ once: true }` because this module loads once per document but `alpine:init` is not
    // guaranteed to: Alpine re-dispatches it if something restarts it, and registering the
    // same component twice replaces the definition on every element already mounted.
    win.addEventListener(ALPINE_INIT_EVENT, () => {
        win.Alpine.data(COMPONENT_NAME, instantSearchComponent(config));
    }, { once: true });
};

/**
 * Self-registration is what makes the single `<script type="module">` tag enough. The guard
 * is what lets `node --test` import this file, where there is no window to hand it.
 */
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    register(window, document);
}
