/**
 * The adapter — the half of the JavaScript that touches elements, `window` and Alpine.
 *
 * The component above it is a state machine with two seams and is easy to assert. This file is
 * where the third-party contracts actually live: the DOM API surface, `matchMedia`, Alpine's
 * registration timing, and the shape of the JSON island the block printed. Those are the things
 * that drift when a browser, a theme or the template changes, so they are the things specced.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    CLASS_DRAWER_OPEN,
    CLASS_ENTRY,
    CLASS_ICON,
    CLASS_ICON_SWATCH,
    CLASS_LABEL,
    CLASS_LINK,
    CLASS_OPEN,
    COLUMNS_PROPERTY,
    COMPONENT_NAME,
    DESKTOP_MEDIA_FALLBACK,
    DESKTOP_MEDIA_PROPERTY,
    HOVER_MEDIA,
    ICON_COLOR_PROPERTY,
    SELECTOR,
    SPRITE_ID_PREFIX,
    buildEntry,
    createBrowser,
    createView,
    parseIsland,
    readDesktopMediaQuery,
    register,
} from 'scr1be-mega-menu/register.js';
import { PLACEMENT_DESKTOP, PLACEMENT_MOBILE } from 'scr1be-mega-menu/state.js';

import { createDocument, createWindow, element } from './dom-double.js';

const ISLAND = {
    c11: [
        { n: 'Duffle', u: 'https://example.test/duffle.html', i: { t: 'sprite', v: 'bag' } },
        { n: 'Backpack', u: 'https://example.test/backpack.html', i: null },
    ],
};

/** The markup mega-menu.phtml renders, reduced to the attributes the adapter reads. */
const renderMenu = (document, { island = ISLAND } = {}) => {
    const control = (action, key) =>
        element(document, 'button', key === undefined
            ? { 'data-menu-control': action }
            : { 'data-menu-control': action, 'data-menu-key': key });

    const branch = (key) => [
        element(document, 'a', {}),
        control('branch', key),
        element(document, 'ul', { 'data-menu-branch-panel': '', 'data-menu-key': key }),
    ];

    const tree = element(document, 'nav', { 'data-menu-tree': '' }, [
        element(document, 'ul', {}, [
            element(document, 'li', { 'data-menu-item': '', 'data-menu-key': 'c3' }, [
                element(document, 'div', {}, [element(document, 'a', {}), control('top', 'c3')]),
                element(document, 'div', { 'data-menu-panel': '', 'data-menu-key': 'c3' }, [
                    element(document, 'ul', {}, [
                        element(
                            document,
                            'li',
                            { 'data-menu-branch-item': '', 'data-menu-key': 'c11' },
                            branch('c11')
                        ),
                        element(
                            document,
                            'li',
                            { 'data-menu-branch-item': '', 'data-menu-key': 'c12' },
                            branch('c12')
                        ),
                    ]),
                ]),
            ]),
            element(document, 'li', { 'data-menu-item': '', 'data-menu-key': 'c4' }, [
                element(document, 'div', {}, [element(document, 'a', {}), control('top', 'c4')]),
                element(document, 'div', { 'data-menu-panel': '', 'data-menu-key': 'c4' }),
            ]),
        ]),
    ]);

    const islandNode = element(document, 'script', { 'data-menu-island': '' });
    islandNode.textContent = island === null ? '' : JSON.stringify(island);

    return element(document, 'div', { 'data-mega-menu': '' }, [
        control('drawer-open'),
        element(document, 'div', { 'data-menu-dock': 'desktop' }, [tree]),
        element(document, 'div', {}, [
            control('drawer-close'),
            element(document, 'div', { 'data-menu-dock': 'mobile' }),
        ]),
        islandNode,
    ]);
};

const snapshot = (overrides = {}) => ({
    placement: PLACEMENT_DESKTOP,
    drawerOpen: false,
    topKey: null,
    branchKey: null,
    columns: 0,
    ...overrides,
});

const viewOf = (options) => {
    const document = createDocument();
    const root = renderMenu(document, options);

    return { document, root, view: createView(root, document) };
};

const openKeys = (root, selector) =>
    root
        .querySelectorAll(selector)
        .filter((node) => node.classList.contains(CLASS_OPEN))
        .map((node) => node.dataset.menuKey);

describe('island parsing', () => {
    it('reads the object the block printed', () => {
        assert.deepEqual(parseIsland(JSON.stringify(ISLAND)), ISLAND);
    });

    it('reads anything unexpected as "no third level" rather than throwing', () => {
        // A menu whose third level silently does not open is degraded; a menu that threw during
        // init() is no menu at all, on every page, for everyone.
        [undefined, null, 42, '', '   ', 'not json', '[1,2]', 'null', '"a string"'].forEach(
            (input) => assert.deepEqual(parseIsland(input), {}, String(input))
        );
    });
});

describe('third-level entries', () => {
    const entryOf = (item) => buildEntry(item, createDocument());

    it('puts the category name in as text, never as markup', () => {
        const entry = entryOf({ n: '<img src=x onerror=alert(1)>', u: '/x.html', i: null });
        const label = entry.children[0].children[0];

        assert.equal(label.getAttribute('class'), CLASS_LABEL);
        assert.equal(label.textContent, '<img src=x onerror=alert(1)>');
        assert.equal(label.children.length, 0, 'the name must not have been parsed as elements');
    });

    it('builds a link inside a list item, classed like its server-rendered siblings', () => {
        const entry = entryOf({ n: 'Duffle', u: 'https://example.test/duffle.html', i: null });

        assert.equal(entry.tagName, 'li');
        assert.equal(entry.getAttribute('class'), CLASS_ENTRY);
        assert.equal(entry.children[0].tagName, 'a');
        assert.equal(entry.children[0].getAttribute('class'), CLASS_LINK);
        assert.equal(entry.children[0].getAttribute('href'), 'https://example.test/duffle.html');
    });

    it('falls back to a harmless href and an empty label when the payload is malformed', () => {
        const entry = entryOf({ n: 42, u: null, i: null });

        assert.equal(entry.children[0].getAttribute('href'), '#');
        assert.equal(entry.textContent, '');
    });

    it('draws a sprite icon in the SVG namespace, pointing at the inlined symbol', () => {
        const entry = entryOf({ n: 'Duffle', u: '/x.html', i: { t: 'sprite', v: 'bag' } });
        const svg = entry.children[0].children[0];

        // An <svg> built in the HTML namespace parses without complaint and renders nothing.
        assert.equal(svg.namespaceURI, 'http://www.w3.org/2000/svg');
        assert.equal(svg.tagName, 'svg');
        assert.equal(svg.getAttribute('class'), CLASS_ICON);
        assert.equal(svg.getAttribute('aria-hidden'), 'true');
        assert.equal(svg.children[0].tagName, 'use');
        assert.equal(svg.children[0].namespaceURI, 'http://www.w3.org/2000/svg');
        assert.equal(svg.children[0].getAttribute('href'), '#' + SPRITE_ID_PREFIX + 'bag');
    });

    it('draws an image icon with an empty alt, because the link text already says where it goes', () => {
        const entry = entryOf({ n: 'Duffle', u: '/x.html', i: { t: 'image', v: '/media/i.png' } });
        const image = entry.children[0].children[0];

        assert.equal(image.tagName, 'img');
        assert.equal(image.getAttribute('src'), '/media/i.png');
        assert.equal(image.getAttribute('alt'), '');
        assert.equal(image.getAttribute('loading'), 'lazy');
    });

    it('draws an icon-font class alongside its own', () => {
        const entry = entryOf({ n: 'Duffle', u: '/x.html', i: { t: 'class', v: 'icon-bag' } });

        assert.equal(entry.children[0].children[0].getAttribute('class'), CLASS_ICON + ' icon-bag');
    });

    it('sets a colour swatch through the CSSOM, so the value never passes through markup', () => {
        const entry = entryOf({ n: 'Duffle', u: '/x.html', i: { t: 'color', v: '#abc' } });
        const swatch = entry.children[0].children[0];

        assert.equal(swatch.getAttribute('class'), CLASS_ICON + ' ' + CLASS_ICON_SWATCH);
        assert.equal(swatch.style.getPropertyValue(ICON_COLOR_PROPERTY), '#abc');
    });

    it('draws nothing at all for an absent or unrecognised icon', () => {
        [null, undefined, 'sprite', { t: 'unicorn', v: 'x' }].forEach((icon) => {
            const entry = entryOf({ n: 'Duffle', u: '/x.html', i: icon });

            assert.equal(entry.children[0].children.length, 1, String(icon));
        });
    });
});

describe('reading a control off an event target', () => {
    it('finds the control the shopper actually pressed, from whatever was inside it', () => {
        const { root, view } = viewOf();
        const control = root.querySelectorAll(SELECTOR.control)[1];
        const insideTheControl = element(root.ownerDocument, 'svg');

        control.appendChild(insideTheControl);

        assert.deepEqual(view.readControl(insideTheControl), { action: 'top', key: 'c3' });
    });

    it('reports no control for an anchor, so the link is left to navigate', () => {
        const { root, view } = viewOf();
        const [row] = root.querySelector(SELECTOR.topItem).children;
        const [anchor] = row.children;

        assert.equal(anchor.tagName, 'a');
        assert.equal(view.readControl(anchor), null);
    });

    it('reports a keyless control for the drawer, which belongs to no menu entry', () => {
        const { root, view } = viewOf();

        assert.deepEqual(view.readControl(root.querySelectorAll(SELECTOR.control)[0]), {
            action: 'drawer-open',
            key: null,
        });
    });

    it('survives an event target that cannot be walked up from', () => {
        const { view } = viewOf();

        assert.equal(view.readControl(null), null);
        assert.equal(view.readControl({}), null);
    });
});

describe('applying a state to the DOM', () => {
    it('moves the one tree into the dock the placement asks for', () => {
        const { root, view } = viewOf();
        const tree = root.querySelector(SELECTOR.tree);

        view.apply(snapshot({ placement: PLACEMENT_MOBILE }));
        assert.equal(tree.parentNode, root.querySelector(SELECTOR.dockMobile));

        view.apply(snapshot({ placement: PLACEMENT_DESKTOP }));
        assert.equal(tree.parentNode, root.querySelector(SELECTOR.dockDesktop));
    });

    it('leaves the tree alone when it is already docked where it belongs', () => {
        // apply() runs on every render, and re-appending a subtree on each of them would move the
        // focused element out of the document and back for nothing.
        const { root, view } = viewOf();
        const desktopDock = root.querySelector(SELECTOR.dockDesktop);
        const moves = [];

        desktopDock.appendChild = (child) => moves.push(child);

        view.apply(snapshot());
        view.apply(snapshot({ topKey: 'c3', columns: 1 }));

        assert.deepEqual(moves, []);
        assert.equal(root.querySelector(SELECTOR.tree).parentNode, desktopDock);
    });

    it('keeps every element identity across a move, so nothing has to be re-indexed', () => {
        const { root, view } = viewOf();

        view.apply(snapshot({ placement: PLACEMENT_MOBILE, topKey: 'c3', columns: 1 }));

        assert.deepEqual(openKeys(root, SELECTOR.topItem), ['c3']);
    });

    it('marks exactly the open entry and the open branch, and unmarks the rest', () => {
        const { root, view } = viewOf();

        view.apply(snapshot({ topKey: 'c3', branchKey: 'c11', columns: 2 }));
        assert.deepEqual(
            [openKeys(root, SELECTOR.topItem), openKeys(root, SELECTOR.branchItem)],
            [['c3'], ['c11']]
        );

        view.apply(snapshot({ topKey: 'c4', columns: 1 }));
        assert.deepEqual(
            [openKeys(root, SELECTOR.topItem), openKeys(root, SELECTOR.branchItem)],
            [['c4'], []]
        );
    });

    it('drives the drawer from a class on the root, which is the only element Alpine knows', () => {
        const { root, view } = viewOf();

        view.apply(snapshot({ placement: PLACEMENT_MOBILE, drawerOpen: true }));
        assert.equal(root.classList.contains(CLASS_DRAWER_OPEN), true);

        view.apply(snapshot({ placement: PLACEMENT_MOBILE }));
        assert.equal(root.classList.contains(CLASS_DRAWER_OPEN), false);
    });

    it('publishes the panel width as a column count, and resets closed panels to one', () => {
        const { root, view } = viewOf();
        const columnsOf = (key) =>
            root
                .querySelectorAll(SELECTOR.topPanel)
                .find((panel) => panel.dataset.menuKey === key)
                .style.getPropertyValue(COLUMNS_PROPERTY);

        view.apply(snapshot({ topKey: 'c3', branchKey: 'c11', columns: 2 }));
        assert.deepEqual([columnsOf('c3'), columnsOf('c4')], ['2', '1']);

        // Reopening must not animate from the width the previous branch left behind.
        view.apply(snapshot());
        assert.deepEqual([columnsOf('c3'), columnsOf('c4')], ['1', '1']);
    });

    it('keeps aria-expanded on every control in step with the state', () => {
        const { root, view } = viewOf();
        const expanded = () =>
            root
                .querySelectorAll(SELECTOR.control)
                .map((control) => control.getAttribute('aria-expanded'));

        view.apply(snapshot({ topKey: 'c3', branchKey: 'c11', columns: 2 }));

        // drawer-open, top c3, branch c11, branch c12, top c4, drawer-close — in document order.
        assert.deepEqual(expanded(), ['false', 'true', 'true', 'false', 'false', null]);
    });

    it('fills a branch from the island the first time it is opened, and never again', () => {
        const { root, view } = viewOf();
        const panel = root
            .querySelectorAll(SELECTOR.branchPanel)
            .find((node) => node.dataset.menuKey === 'c11');

        view.apply(snapshot({ topKey: 'c3', branchKey: 'c11', columns: 2 }));

        assert.deepEqual(
            panel.children.map((entry) => entry.textContent),
            ['Duffle', 'Backpack']
        );

        view.apply(snapshot({ topKey: 'c3', columns: 1 }));
        view.apply(snapshot({ topKey: 'c3', branchKey: 'c11', columns: 2 }));

        assert.equal(panel.children.length, 2, 'the branch must not have been filled twice');
    });

    it('gives up on a branch the island has nothing for, rather than retrying on every hover', () => {
        const { root, view } = viewOf();
        const panel = root
            .querySelectorAll(SELECTOR.branchPanel)
            .find((node) => node.dataset.menuKey === 'c12');

        view.apply(snapshot({ topKey: 'c3', branchKey: 'c12', columns: 2 }));

        assert.equal(panel.children.length, 0);
        assert.equal(panel.dataset.menuFilled, '1');
    });

    it('renders no third level at all when the block printed no island', () => {
        const { root, view } = viewOf({ island: null });
        const panel = root
            .querySelectorAll(SELECTOR.branchPanel)
            .find((node) => node.dataset.menuKey === 'c11');

        view.apply(snapshot({ topKey: 'c3', branchKey: 'c11', columns: 2 }));

        assert.equal(panel.children.length, 0);
    });

    it('skips island entries that are not objects instead of building junk elements', () => {
        const { root, view } = viewOf({ island: { c11: [null, 'Duffle', { n: 'Backpack', u: '/b' }] } });
        const panel = root
            .querySelectorAll(SELECTOR.branchPanel)
            .find((node) => node.dataset.menuKey === 'c11');

        view.apply(snapshot({ topKey: 'c3', branchKey: 'c11', columns: 2 }));

        assert.deepEqual(panel.children.map((entry) => entry.textContent), ['Backpack']);
    });

    it('only ever touches elements inside its own root', () => {
        // Everything is found by querying the component root, never the document — otherwise a
        // second menu on the same page (a footer one, a second header for a landing handle) would
        // open and close in step with the first.
        const document = createDocument();
        const header = renderMenu(document);
        const footer = renderMenu(document);

        element(document, 'div', {}, [header, footer]);
        createView(header, document).apply(snapshot({ topKey: 'c3', columns: 1 }));

        assert.deepEqual(openKeys(header, SELECTOR.topItem), ['c3']);
        assert.deepEqual(openKeys(footer, SELECTOR.topItem), []);
    });
});

describe('reading the breakpoint back out of the stylesheet', () => {
    it('uses the media query the stylesheet declares', () => {
        const win = createWindow({ customProperties: { [DESKTOP_MEDIA_PROPERTY]: ' (min-width: 48rem) ' } });

        assert.equal(readDesktopMediaQuery(win), '(min-width: 48rem)');
    });

    it('falls back when the stylesheet has not loaded', () => {
        assert.equal(readDesktopMediaQuery(createWindow()), DESKTOP_MEDIA_FALLBACK);
    });

    it('falls back rather than throwing when the property cannot be read at all', () => {
        const win = createWindow();

        win.getComputedStyle = () => {
            throw new Error('no layout yet');
        };

        assert.equal(readDesktopMediaQuery(win), DESKTOP_MEDIA_FALLBACK);
    });
});

describe('the browser seam', () => {
    const windowAt = (isDesktop, canHover) =>
        createWindow({
            customProperties: { [DESKTOP_MEDIA_PROPERTY]: '(min-width: 64rem)' },
            media: { '(min-width: 64rem)': isDesktop, [HOVER_MEDIA]: canHover },
        });

    it('answers "room for a dropdown" and "does this pointer hover" independently', () => {
        // The combination that matters is the second row: a tablet in landscape has the room and
        // not the hover, and opening on pointerover there puts the panel under the finger that was
        // on its way to the link.
        [[true, true], [true, false], [false, true], [false, false]].forEach(([isDesktop, canHover]) => {
            const browser = createBrowser(windowAt(isDesktop, canHover));

            assert.deepEqual([browser.isDesktop(), browser.canHover()], [isDesktop, canHover]);
        });
    });

    it('reports a crossed breakpoint to the handler', () => {
        const win = windowAt(false, false);
        const browser = createBrowser(win);
        const seen = [];

        browser.onDesktopChange((isDesktop) => seen.push(isDesktop));
        win.mediaQueryList('(min-width: 64rem)').emit(true);
        win.mediaQueryList('(min-width: 64rem)').emit(false);

        assert.deepEqual(seen, [true, false]);
    });
});

describe('registration', () => {
    it('registers under the name the template names in x-data', () => {
        const registered = [];

        register({ data: (name, factory) => registered.push([name, factory]) }, createWindow());

        assert.equal(registered.length, 1);
        assert.equal(registered[0][0], COMPONENT_NAME);
        assert.equal(typeof registered[0][1], 'function');
    });

    it('builds the component lazily, once Alpine asks for it', () => {
        // Alpine.data() takes a factory and calls it per component instance; building eagerly would
        // run matchMedia before the stylesheet that declares the breakpoint has been read.
        let factory = null;

        register({ data: (_name, given) => { factory = given; } }, createWindow());

        assert.equal(typeof factory(), 'object');
    });
});
