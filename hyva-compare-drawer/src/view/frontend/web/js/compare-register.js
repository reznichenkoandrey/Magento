import { createCompareStore } from './compare-store.js';

/** The name every template addresses the store by: `$store.compare`. */
export const STORE_NAME = 'compare';

/** The element `compare-scripts.phtml` writes the configuration into. */
export const CONFIG_SELECTOR = '[data-scr1be-compare-config]';

export const ALPINE_INIT_EVENT = 'alpine:init';

export const FALLBACK_CONFIG = { storageKey: 'scr1be_compare_v1', maxItems: 4 };

export const readConfig = (doc) => {
    const element = doc.querySelector(CONFIG_SELECTOR);
    if (!element) return { ...FALLBACK_CONFIG };

    try {
        return { ...FALLBACK_CONFIG, ...JSON.parse(element.textContent) };
    } catch {
        return { ...FALLBACK_CONFIG };
    }
};

/**
 * @param {Window} win
 * @param {Document} doc
 */
export const register = (win, doc) => {
    const config = readConfig(doc);

    win.addEventListener(ALPINE_INIT_EVENT, () => {
        win.Alpine.store(STORE_NAME, createCompareStore({
            ...config,
            storage: win.localStorage,
            eventTarget: win,
        }));
    }, { once: true });
};

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    register(window, document);
}
