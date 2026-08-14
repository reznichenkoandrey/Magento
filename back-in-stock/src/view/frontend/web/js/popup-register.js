import { createClient } from './alert-client.js';
import { popupComponent } from './popup.js';

/**
 * The seam: the only file in the module that touches `window` and `document`.
 *
 * Everything in here is a promise to something outside this repository — the element the PHP block
 * writes its endpoints into, the component name the template puts in `x-data`, Alpine's `alpine:init`
 * timing, and the event name Hyvä listens on to refresh customer data. Those are the four things
 * that break silently when a template is renamed or a theme changes its bootstrap, which is why they
 * live in one exported function with a spec against it rather than as three lines at the bottom of
 * the component.
 */

/** The element `popup-scripts.phtml` writes the endpoint config into. */
export const CONFIG_SELECTOR = '[data-scr1be-back-in-stock-config]';

/** The name `popup.phtml` puts in `x-data`. */
export const COMPONENT_POPUP = 'scr1beBackInStockPopup';

const EMPTY_CONFIG = { endpoints: {} };

/**
 * @param {Document} doc
 * @returns {{endpoints: Object}}
 */
export const readConfig = (doc) => {
    const element = doc.querySelector(CONFIG_SELECTOR);

    if (!element) {
        return EMPTY_CONFIG;
    }

    try {
        const parsed = JSON.parse(element.textContent);

        // A config with no endpoints produces a popup that renders and whose buttons post nowhere,
        // which is a degraded popup. Throwing here would take Alpine down on every page instead.
        return { endpoints: (parsed && parsed.endpoints) || {} };
    } catch (error) {
        return EMPTY_CONFIG;
    }
};

/**
 * @param {Window} win
 * @param {Document} doc
 * @returns {void}
 */
export const register = (win, doc) => {
    const config = readConfig(doc);
    const post = createClient(win);

    // Hyvä's focus helpers live on the `hyva` global and are absent on a page that loaded this
    // module without the theme's helper bundle. Guarding here rather than in the component keeps the
    // component free of any knowledge of the page it is on.
    const bridge = {
        reload: (name) => win.dispatchEvent(new win.CustomEvent(name)),
        trapFocus: (element) => win.hyva && win.hyva.trapFocus && win.hyva.trapFocus(element),
        releaseFocus: (element) => win.hyva && win.hyva.releaseFocus && win.hyva.releaseFocus(element),
    };

    win.addEventListener('alpine:init', () => {
        win.Alpine.data(COMPONENT_POPUP, popupComponent(config, post, bridge));
    }, { once: true });
};

/**
 * Self-registration on import is what makes the `<script type="module">` tag in the template enough.
 * The guard is what lets the same file be imported by `node --test`, where there is no window and
 * self-registration would throw before a single assertion ran.
 */
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    register(window, document);
}
