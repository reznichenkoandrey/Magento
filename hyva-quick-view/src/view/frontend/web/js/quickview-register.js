import { createQuickViewStore } from './quick-view-store.js';

/** The name every template addresses the store by: `$store.quickView`. */
export const STORE_NAME = 'quickView';

export const CONFIG_SELECTOR = '[data-scr1be-quickview-config]';

export const ALPINE_INIT_EVENT = 'alpine:init';

/** `errorTitle` is intentionally English here: it is only reached when the island is missing. */
export const FALLBACK_CONFIG = {
    infoUrl: '/scr1be_quickview/product/info/',
    errorTitle: 'Could not load product',
};

export const readConfig = (doc) => {
    const element = doc.querySelector(CONFIG_SELECTOR);
    if (!element) return { ...FALLBACK_CONFIG };

    try {
        return { ...FALLBACK_CONFIG, ...JSON.parse(element.textContent) };
    } catch {
        return { ...FALLBACK_CONFIG };
    }
};

export const register = (win, doc) => {
    const config = readConfig(doc);

    win.addEventListener(ALPINE_INIT_EVENT, () => {
        win.Alpine.store(STORE_NAME, createQuickViewStore({ ...config, doc }));
    }, { once: true });
};

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    register(window, document);
}
