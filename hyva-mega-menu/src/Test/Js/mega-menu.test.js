/**
 * The component, against doubles for both of its seams.
 *
 * It knows nothing about elements — it reads a control off an event target through the view and
 * asks the state machine to change. What is worth pinning here is therefore not markup but policy:
 * which events are intercepted, which are left alone, and when hover is allowed to open a panel.
 *
 * `this.$root` and `this.$event` are Alpine magics, assigned onto the component scope before a
 * handler runs. The specs assign them the same way rather than pretending Alpine is present.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    ACTION_BRANCH,
    ACTION_DRAWER_CLOSE,
    ACTION_DRAWER_OPEN,
    ACTION_TOP,
    megaMenu,
} from 'scr1be-mega-menu/component.js';
import { PLACEMENT_DESKTOP, PLACEMENT_MOBILE } from 'scr1be-mega-menu/state.js';

const ROOT = { name: 'the component root' };

/**
 * A view double that records what it was asked to render, plus a state double that records the
 * transitions it was asked for. Between them they say exactly what the component decided.
 */
const mount = ({ control = null, isDesktop = true, canHover = true } = {}) => {
    const calls = [];
    const rendered = [];
    // Desktop, because that is the placement the server rendered the tree into and the one
    // createComponent() seeds the real state machine with.
    let placement = PLACEMENT_DESKTOP;
    let desktopChangeHandler = null;
    let viewRoot = null;

    const state = {
        snapshot: () => ({ placement }),
        isDesktop: () => placement === PLACEMENT_DESKTOP,
        setPlacement: (next) => {
            calls.push(['setPlacement', next]);

            if (next === placement) {
                return false;
            }

            placement = next;

            return true;
        },
        openTop: (key) => calls.push(['openTop', key]),
        toggleTop: (key) => calls.push(['toggleTop', key]),
        toggleBranch: (key) => calls.push(['toggleBranch', key]),
        openDrawer: () => calls.push(['openDrawer']),
        closeAll: () => calls.push(['closeAll']),
    };

    const component = megaMenu({
        state,
        createView: (root) => {
            viewRoot = root;

            return {
                readControl: () => control,
                apply: (snapshot) => rendered.push(snapshot),
            };
        },
        browser: {
            isDesktop: () => isDesktop,
            canHover: () => canHover,
            onDesktopChange: (handler) => {
                desktopChangeHandler = handler;
            },
        },
    });

    component.$root = ROOT;
    component.init();

    return {
        component,
        calls,
        rendered,
        viewRoot: () => viewRoot,
        crossBreakpoint: (nowDesktop) => desktopChangeHandler(nowDesktop),
    };
};

const clickEvent = () => {
    let prevented = false;

    return {
        target: { name: 'whatever was clicked' },
        preventDefault: () => {
            prevented = true;
        },
        wasPrevented: () => prevented,
    };
};

describe('initialisation', () => {
    it('builds its view from the root Alpine gave it', () => {
        assert.equal(mount().viewRoot(), ROOT);
    });

    it('adopts the placement the viewport is already in, then renders once', () => {
        const menu = mount({ isDesktop: false });

        assert.deepEqual(menu.calls, [['setPlacement', PLACEMENT_MOBILE]]);
        assert.deepEqual(menu.rendered, [{ placement: PLACEMENT_MOBILE }]);
    });

    it('moves nothing on a desktop viewport, because that is where the tree was rendered', () => {
        const menu = mount({ isDesktop: true });

        assert.deepEqual(menu.calls, [['setPlacement', PLACEMENT_DESKTOP]]);
        assert.equal(menu.rendered.length, 1);
    });
});

describe('clicks', () => {
    const click = (menu) => {
        const event = clickEvent();

        menu.component.$event = event;
        menu.component.onClick();

        return event;
    };

    it('leaves an anchor alone, so the server-rendered links navigate', () => {
        const menu = mount({ control: null });
        const event = click(menu);

        assert.equal(event.wasPrevented(), false);
        assert.deepEqual(menu.calls.slice(1), []);
        assert.equal(menu.rendered.length, 1, 'nothing changed, so nothing was re-rendered');
    });

    it('intercepts a recognised control and renders the result', () => {
        const menu = mount({ control: { action: ACTION_TOP, key: 'c3' } });
        const event = click(menu);

        assert.equal(event.wasPrevented(), true);
        assert.deepEqual(menu.calls.slice(1), [['toggleTop', 'c3']]);
        assert.equal(menu.rendered.length, 2);
    });

    it('toggles rather than opens, so pressing an open entry closes it', () => {
        const menu = mount({ control: { action: ACTION_BRANCH, key: 'c11' } });

        click(menu);

        assert.deepEqual(menu.calls.slice(1), [['toggleBranch', 'c11']]);
    });

    it('opens and closes the drawer from its two controls', () => {
        const opened = mount({ control: { action: ACTION_DRAWER_OPEN, key: null } });
        const closed = mount({ control: { action: ACTION_DRAWER_CLOSE, key: null } });

        click(opened);
        click(closed);

        assert.deepEqual(opened.calls.slice(1), [['openDrawer']]);
        assert.deepEqual(closed.calls.slice(1), [['closeAll']]);
    });

    it('still swallows the event for a control it does not recognise, and changes nothing', () => {
        // The element carries data-menu-control, so it is one of ours and is not a link; letting
        // it through would submit or navigate on markup this module rendered.
        const menu = mount({ control: { action: 'invented-later', key: 'c3' } });
        const event = click(menu);

        assert.equal(event.wasPrevented(), true);
        assert.deepEqual(menu.calls.slice(1), []);
    });
});

describe('hover', () => {
    const hover = (menu) => {
        menu.component.$event = { target: { name: 'a control' } };
        menu.component.onPointerOver();
    };

    it('opens a top-level entry on a desktop pointer that hovers', () => {
        const menu = mount({ control: { action: ACTION_TOP, key: 'c3' }, isDesktop: true, canHover: true });

        hover(menu);

        assert.deepEqual(menu.calls.slice(1), [['openTop', 'c3']]);
        assert.equal(menu.rendered.length, 2);
    });

    it('opens rather than toggles, so a second pass over an open entry does not close it', () => {
        const menu = mount({ control: { action: ACTION_TOP, key: 'c3' } });

        hover(menu);
        hover(menu);

        assert.deepEqual(menu.calls.slice(1), [['openTop', 'c3'], ['openTop', 'c3']]);
    });

    it('ignores hover in the drawer, where there is no room for a dropdown', () => {
        const menu = mount({ control: { action: ACTION_TOP, key: 'c3' }, isDesktop: false });

        hover(menu);

        assert.deepEqual(menu.calls.slice(1), []);
    });

    it('ignores hover on a wide screen whose pointer does not hover', () => {
        // A tablet in landscape has room for the dropdown and no hover state; opening on
        // pointerover there puts the panel under the finger on its way to the link.
        const menu = mount({ control: { action: ACTION_TOP, key: 'c3' }, isDesktop: true, canHover: false });

        hover(menu);

        assert.deepEqual(menu.calls.slice(1), []);
    });

    it('ignores hover over anything that is not a top-level control', () => {
        [null, { action: ACTION_BRANCH, key: 'c11' }, { action: ACTION_DRAWER_OPEN, key: null }].forEach(
            (control) => {
                const menu = mount({ control });

                hover(menu);

                assert.deepEqual(menu.calls.slice(1), [], JSON.stringify(control));
            }
        );
    });
});

describe('dismissal', () => {
    it('closes everything on escape', () => {
        const menu = mount();

        menu.component.onEscape();

        assert.deepEqual(menu.calls.slice(1), [['closeAll']]);
        assert.equal(menu.rendered.length, 2);
    });

    it('closes everything on a click outside the menu', () => {
        const menu = mount();

        menu.component.onOutside();

        assert.deepEqual(menu.calls.slice(1), [['closeAll']]);
        assert.equal(menu.rendered.length, 2);
    });
});

describe('crossing the breakpoint', () => {
    it('re-docks the tree and renders once when the placement actually changes', () => {
        const menu = mount({ isDesktop: true });

        menu.crossBreakpoint(false);

        assert.deepEqual(menu.calls.slice(1), [['setPlacement', PLACEMENT_MOBILE]]);
        assert.deepEqual(menu.rendered[1], { placement: PLACEMENT_MOBILE });
    });

    it('renders nothing when the media query fires without the placement changing', () => {
        // An orientation change inside the same breakpoint fires the listener and means nothing.
        const menu = mount({ isDesktop: true });

        menu.crossBreakpoint(true);

        assert.equal(menu.rendered.length, 1);
    });
});
