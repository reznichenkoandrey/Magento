/**
 * Copyright (c) 2026 scr1be. MIT licensed.
 *
 * The seam. Everything in `store-switcher.js` is a function over its arguments; this is the part
 * that is a promise to Alpine, to the templates, and to the element the block renders.
 *
 * `alpine:init` is dispatched by Alpine on `document` with `bubbles: true`, so a listener on the
 * window sees it. `{ once: true }` matters because Alpine re-dispatches the event if it is
 * restarted, and registering a second time would swap the component out from under nodes that
 * are already mounted.
 */

import { drawerComponent, emptyConfig, linksComponent } from './store-switcher.js';

/** The element the drawer template writes its JSON into. */
export const CONFIG_SELECTOR = '[data-scr1be-store-switcher-config]';

/** The names the templates put in `x-data`. */
export const COMPONENT_LINKS = 'scr1beStoreSwitcherLinks';
export const COMPONENT_DRAWER = 'scr1beStoreSwitcherDrawer';

export const ALPINE_INIT_EVENT = 'alpine:init';

/**
 * @param {Document} doc
 * @returns {Object}
 */
export const readConfig = (doc) => {
    const element = doc.querySelector(CONFIG_SELECTOR);

    if (!element) {
        return emptyConfig();
    }

    try {
        return Object.assign(emptyConfig(), JSON.parse(element.textContent));
    } catch (error) {
        // A malformed payload leaves a switcher that does nothing. Throwing here would take every
        // other Alpine component on the page down with it.
        return emptyConfig();
    }
};

/**
 * @param {Window} win
 * @param {Document} doc
 */
export const registerStoreSwitcher = (win, doc) => {
    win.addEventListener(
        ALPINE_INIT_EVENT,
        () => {
            const config = readConfig(doc);

            win.Alpine.data(COMPONENT_LINKS, () => linksComponent(win));
            win.Alpine.data(COMPONENT_DRAWER, () => drawerComponent(config, win));
        },
        { once: true }
    );
};

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    registerStoreSwitcher(window, document);
}
