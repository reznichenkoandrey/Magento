/**
 * The Alpine component: one root, one delegated listener per event, no directive anywhere inside
 * the menu tree itself.
 *
 * That last part is the design, not a style preference. The tree is physically moved between the
 * desktop dock and the mobile drawer, and markup that carries Alpine directives is markup Alpine
 * has to tear down and re-initialise when it moves. A tree of plain `<a>`, `<button>` and `<li>`
 * elements can be appended anywhere with no lifecycle at all — so the only element Alpine knows
 * about is the root, which never moves, and every control is found by walking up from the event
 * target instead of by having been bound.
 *
 * Delegation is also what keeps this component CSP-safe. Alpine's CSP build allows a directive to
 * name a property or a method and nothing else — no expressions, no arguments. A per-item handler
 * would need the item's key as an argument; a delegated one reads it off the element it matched.
 *
 * Neither the state machine nor the view is a property of the returned object. Alpine makes the
 * component's own properties reactive, and there is nothing here worth observing: every visible
 * change is an explicit class toggle. Keeping both in the closure also keeps a Map of elements
 * out of a reactive proxy, which is the kind of thing that is invisible until a menu has three
 * hundred entries.
 */
import { PLACEMENT_DESKTOP, PLACEMENT_MOBILE } from 'scr1be-mega-menu/state.js';

export const ACTION_DRAWER_OPEN = 'drawer-open';
export const ACTION_DRAWER_CLOSE = 'drawer-close';
export const ACTION_TOP = 'top';
export const ACTION_BRANCH = 'branch';

export const megaMenu = ({ state, createView, browser }) => {
    let view = null;

    const render = () => view.apply(state.snapshot());

    const apply = (control) => {
        switch (control.action) {
            case ACTION_DRAWER_OPEN:
                state.openDrawer();
                break;
            case ACTION_DRAWER_CLOSE:
                state.closeAll();
                break;
            case ACTION_TOP:
                state.toggleTop(control.key);
                break;
            case ACTION_BRANCH:
                state.toggleBranch(control.key);
                break;
            default:
                break;
        }
    };

    return {
        init() {
            view = createView(this.$root);
            state.setPlacement(browser.isDesktop() ? PLACEMENT_DESKTOP : PLACEMENT_MOBILE);
            browser.onDesktopChange((isDesktop) => this.onDesktopChange(isDesktop));
            render();
        },

        onClick() {
            const event = this.$event;
            const control = view.readControl(event.target);

            if (control === null) {
                return;
            }

            // Only a recognised control is intercepted. Anything else inside the root — most of
            // all the anchors, which are the entire point of the server-rendered levels — keeps
            // its default behaviour and navigates.
            event.preventDefault();

            apply(control);
            render();
        },

        /**
         * Hover opens a top-level entry on desktop only, and only on a device that hovers.
         *
         * The media query answers "is there room for a dropdown", which is not the same question
         * as "does this pointer hover". A tablet in landscape says yes to the first and no to the
         * second, and opening on pointerover there means the panel appears under the finger that
         * was on its way to the link.
         */
        onPointerOver() {
            if (!state.isDesktop() || !browser.canHover()) {
                return;
            }

            const control = view.readControl(this.$event.target);

            if (control === null || control.action !== ACTION_TOP) {
                return;
            }

            state.openTop(control.key);
            render();
        },

        onEscape() {
            state.closeAll();
            render();
        },

        onOutside() {
            state.closeAll();
            render();
        },

        onDesktopChange(isDesktop) {
            // setPlacement reports whether anything changed, so a media query that fires on an
            // orientation change inside the same breakpoint costs nothing.
            if (state.setPlacement(isDesktop ? PLACEMENT_DESKTOP : PLACEMENT_MOBILE)) {
                render();
            }
        },
    };
};
