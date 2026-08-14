/**
 * The adapter: the only file that knows about `window`, about Alpine, and about which concrete engine
 * is in use.
 *
 * Everything it touches is a contract owned by somebody else — the component name the templates put
 * in `x-data`, the event Hyvä dispatches before Alpine starts, the specifiers the import map binds —
 * which is exactly why it is a separate module with its own spec. A rename on either side of one of
 * those seams breaks the slider silently, and silently is the expensive way to find out.
 */

// The engine alone is imported by bare specifier: that is the seam the import map binds, and
// therefore the one a project can rebind to a different carousel implementation without touching a
// line of this module. Everything else is ours and is imported relatively, which needs no map at all.
import { createEngine } from 'scr1be-product-slider/engine.js';
import { createSlider } from './slider.js';
import { applyProofs, fetchProofs } from './social-proof.js';

/** The name `slider.phtml` writes into `x-data="scr1beSlider()"`. */
export const COMPONENT_NAME = 'scr1beSlider';

/** Alpine's own event, dispatched on `window` before it walks the document. */
export const ALPINE_INIT_EVENT = 'alpine:init';

export const register = (alpine) => {
    if (!alpine || typeof alpine.data !== 'function') {
        return false;
    }

    alpine.data(COMPONENT_NAME, createSlider({ createEngine, fetchProofs, applyProofs }));

    return true;
};

/**
 * `{ once: true }` because this module is loaded once per document but `alpine:init` is not
 * guaranteed to be: Alpine re-dispatches it if something restarts it, and registering the same
 * component twice replaces the definition of every slider already on the page.
 */
export const listen = (target) => {
    target.addEventListener(ALPINE_INIT_EVENT, () => register(target.Alpine), { once: true });
};

if (typeof window !== 'undefined') {
    listen(window);
}
