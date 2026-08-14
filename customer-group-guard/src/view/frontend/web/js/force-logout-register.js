/**
 * Wires the component to the real browser and publishes it under the name the template uses.
 *
 * Two ordering problems are solved here, and both are the reason this file exists separately
 * from the component it registers.
 *
 * The first is Alpine. This module is deferred, so it may execute either side of Alpine's own
 * script depending on where a theme loads Alpine — which means `alpine:init` may already have
 * fired by the time this runs, and `Alpine.data()` would be registering into a boot that has
 * finished. A plain global function has no such window: Alpine evaluates `x-data` when it walks
 * the DOM, which happens once the document is ready and therefore after every deferred module
 * has run. The name is published, not registered, and it is available whatever the load order.
 *
 * The second is customer data. `private-content-loaded` is dispatched during page load and does
 * not replay for listeners that arrive late, so the listener is attached at module evaluation
 * rather than from the component's init(), and the last payload is kept for whoever subscribes
 * afterwards. A component that mounted after the event still sees it.
 */
import { NOTICE_STORAGE_KEY, forceLogoutGuard } from './force-logout.js';

/** Must match the x-data expression in force-logout.phtml. */
const COMPONENT_NAME = 'scr1beForceLogoutGuard';

/** Magento's private-content channel: dispatched with every loaded section in detail.data. */
const CUSTOMER_DATA_EVENT = 'private-content-loaded';

/**
 * Where customer-data keeps its sections between page loads. Reading it directly is a deliberate
 * coupling to a long-standing Magento contract, and it is what covers the page that serves its
 * sections from storage without dispatching anything. Any surprise in the shape reads as "no
 * sections", so the worst case is the component waiting for the next event.
 */
const SECTION_STORAGE_KEY = 'mage-cache-storage';

/** Hyva's message contract, used when the theme's own helper has been replaced. */
const MESSAGE_EVENT = 'messages-loaded';
const NOTICE_TYPE = 'warning';
const NOTICE_TIMEOUT_MS = 10000;

let lastSections = null;
const subscribers = [];

window.addEventListener(CUSTOMER_DATA_EVENT, (event) => {
    lastSections = (event.detail && event.detail.data) || null;
    subscribers.forEach((handler) => handler(lastSections));
});

const readStoredSections = () => {
    try {
        const raw = window.localStorage.getItem(SECTION_STORAGE_KEY);
        const parsed = raw === null ? null : JSON.parse(raw);

        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (error) {
        // Private browsing modes and a half-written storage entry both land here. Neither is
        // worth a console entry on every page view.
        return null;
    }
};

/**
 * Exported for the spec. Nothing imports it at runtime — the storefront consumes this file for
 * its side effect, the published component name at the bottom — but the adapter is where the
 * browser contracts live, so it is the half worth pinning with tests.
 */
export const browser = {
    onCustomerData(handler) {
        subscribers.push(handler);

        const known = lastSections || readStoredSections();
        if (known) {
            handler(known);
        }
    },

    /** Read and delete in one step: a notice that can be shown twice is not a one-shot. */
    takeNotice() {
        try {
            const notice = window.localStorage.getItem(NOTICE_STORAGE_KEY);
            if (notice !== null) {
                window.localStorage.removeItem(NOTICE_STORAGE_KEY);
            }

            return notice;
        } catch (error) {
            return null;
        }
    },

    writeNotice(text) {
        if (!text) {
            return;
        }

        try {
            window.localStorage.setItem(NOTICE_STORAGE_KEY, text);
        } catch (error) {
            // A storage quota that refuses one short string costs the shopper an explanation,
            // not the logout. The redirect happens either way.
        }
    },

    showNotice(text) {
        if (typeof window.dispatchMessages === 'function') {
            window.dispatchMessages([{ type: NOTICE_TYPE, text }], NOTICE_TIMEOUT_MS);

            return;
        }

        // The key is `hideAfter`, matching what Hyvä's own dispatchMessages() builds and what the
        // messages component reads off event.detail. Anything else leaves hideAfter undefined, and
        // since the auto-hide default only applies to `success`, a warning would never dismiss.
        window.dispatchEvent(new CustomEvent(MESSAGE_EVENT, {
            detail: { messages: [{ type: NOTICE_TYPE, text }], hideAfter: NOTICE_TIMEOUT_MS }
        }));
    },

    /** assign(), not replace(): the page the shopper came from stays in their history. */
    redirect(url) {
        window.location.assign(url);
    }
};

window[COMPONENT_NAME] = () => forceLogoutGuard(browser);
