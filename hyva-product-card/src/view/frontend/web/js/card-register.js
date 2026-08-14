import { cardGridComponent } from './card-grid.js';
import { productCardComponent } from './card.js';

/**
 * The seam between this module and the page: the only file that touches `window`.
 *
 * It exists as its own module — rather than as three lines at the bottom of `card.js` — because it
 * is where the third-party contract lives. `alpine:init` firing exactly once, the `{once: true}`
 * listener, the component names the templates reference, and the config element the PHP side
 * writes: every one of those is a promise to something outside this repository, and every one of
 * them is the kind of promise that quietly breaks. Keeping them in one exported function is what
 * makes them testable without a browser.
 */

/** The element the scripts template writes the endpoint config into. */
export const CONFIG_SELECTOR = '[data-scr1be-card-config]';

export const COMPONENT_CARD = 'scr1beProductCard';
export const COMPONENT_GRID = 'scr1beCardGrid';

/**
 * @param {Document} doc
 * @returns {{endpoints: object, ga4: boolean}}
 */
export const readConfig = (doc) => {
    const element = doc.querySelector(CONFIG_SELECTOR);
    if (!element) {
        return { endpoints: {}, ga4: false };
    }

    try {
        const parsed = JSON.parse(element.textContent);

        return { endpoints: parsed.endpoints || {}, ga4: Boolean(parsed.ga4) };
    } catch (error) {
        // A malformed config means no endpoints, which means a card that renders its cached stock
        // label and never fetches. That is a degraded card, not a broken page.
        return { endpoints: {}, ga4: false };
    }
};

/**
 * Registers both components with Alpine.
 *
 * @param {Window} win
 * @param {Document} doc
 * @returns {void}
 */
export const register = (win, doc) => {
    const config = readConfig(doc);

    win.addEventListener('alpine:init', () => {
        win.Alpine.data(COMPONENT_CARD, productCardComponent(config));
        win.Alpine.data(COMPONENT_GRID, cardGridComponent(config));
    }, { once: true });
};

/**
 * The entry module registers itself the moment it is imported — that is what makes the
 * `<script type="module">` tag in `scripts.phtml` enough. The guard is what lets the same file be
 * imported by `node --test`, where there is no window to hand it and self-registration would throw
 * before a single assertion ran.
 */
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    register(window, document);
}
