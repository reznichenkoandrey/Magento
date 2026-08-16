/**
 * The adapter: everything that touches a real element, a real `window`, or Alpine.
 *
 * The component above it is written against two seams and knows nothing else, so this file is
 * where the third-party contracts live — the DOM API surface, `matchMedia`, Alpine's registration
 * timing, and the shape of the JSON island the block printed. That makes it the half most likely
 * to drift when a browser, a theme or the template changes, which is why the seams are exported
 * and specced rather than left as an implementation detail of a side-effecting module.
 *
 * Siblings are imported by relative path, so the browser resolves them against this file's own url
 * and the module needs no import map at all. That is not a style preference: a document may install
 * only one import map before its first module script, Firefox rejects every map after that one, and
 * a storefront running three Hyvä modules that each print their own gets two of them silently
 * dropped. The `exports` map in package.json still names the same files for `node --test`, so the
 * specs import exactly what the storefront imports.
 */
import { ACTION_BRANCH, ACTION_DRAWER_OPEN, ACTION_TOP, megaMenu } from './mega-menu.js';
import { PLACEMENT_DESKTOP, PLACEMENT_MOBILE, createMenuState } from './menu-state.js';

/** Must match the x-data expression in mega-menu.phtml. */
export const COMPONENT_NAME = 'scr1beMegaMenu';

/** Must match the data attributes in mega-menu.phtml. */
export const SELECTOR = {
    tree: '[data-menu-tree]',
    island: '[data-menu-island]',
    dockDesktop: '[data-menu-dock="desktop"]',
    dockMobile: '[data-menu-dock="mobile"]',
    control: '[data-menu-control]',
    topItem: '[data-menu-item]',
    topPanel: '[data-menu-panel]',
    branchItem: '[data-menu-branch-item]',
    branchPanel: '[data-menu-branch-panel]',
};

export const CLASS_OPEN = 'is-open';
export const CLASS_DRAWER_OPEN = 'is-drawer-open';
export const CLASS_ENTRY = 'scr1be-menu__entry';
export const CLASS_LINK = 'scr1be-menu__link';
export const CLASS_LABEL = 'scr1be-menu__label';
export const CLASS_ICON = 'scr1be-menu__icon';
export const CLASS_ICON_SWATCH = 'scr1be-menu__icon--swatch';

/** Must match the custom properties declared in view/frontend/tailwind/module.css. */
export const COLUMNS_PROPERTY = '--scr1be-mega-menu-columns';
export const ICON_COLOR_PROPERTY = '--scr1be-mega-menu-icon-color';
export const DESKTOP_MEDIA_PROPERTY = '--scr1be-mega-menu-desktop-media';

/**
 * Used only when the stylesheet has not loaded — a hard-refresh race, or a storefront that failed
 * to deploy the theme's CSS. It mirrors the `lg` breakpoint the markup's utilities use, and it is
 * the one place in the module where that number is written twice; the custom property exists so
 * that the second copy is never the one in force.
 */
export const DESKTOP_MEDIA_FALLBACK = '(min-width: 64rem)';

export const HOVER_MEDIA = '(hover: hover) and (pointer: fine)';

/** Must match the symbol ids the sprite in mega-menu.phtml renders. */
export const SPRITE_ID_PREFIX = 'scr1be-menu-icon-';

const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
const ICON_SIZE = '20';

const ICON_TYPE_SPRITE = 'sprite';
const ICON_TYPE_IMAGE = 'image';
const ICON_TYPE_CLASS = 'class';
const ICON_TYPE_COLOR = 'color';

/** Marks a third-level list as built, so opening a branch twice does not append it twice. */
const FILLED_FLAG = '1';

/**
 * The island is a `<script type="application/json">` data block, which the browser does not
 * execute and therefore hands over as text. Anything unexpected in it reads as "no third level":
 * a menu whose third level silently does not open is a degraded menu, and a menu that threw
 * during `init()` is no menu at all.
 */
export const parseIsland = (text) => {
    if (typeof text !== 'string' || text.trim() === '') {
        return {};
    }

    try {
        const parsed = JSON.parse(text);

        return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (error) {
        return {};
    }
};

const indexByKey = (root, selector) => {
    const index = new Map();

    Array.from(root.querySelectorAll(selector)).forEach((element) => {
        const key = element.dataset.menuKey;

        if (typeof key === 'string' && key !== '') {
            index.set(key, element);
        }
    });

    return index;
};

const buildIcon = (icon, doc) => {
    if (icon === null || typeof icon !== 'object') {
        return null;
    }

    if (icon.t === ICON_TYPE_SPRITE) {
        // createElementNS, not createElement: an <svg> built in the HTML namespace parses without
        // complaint and renders nothing at all.
        const svg = doc.createElementNS(SVG_NAMESPACE, 'svg');
        const use = doc.createElementNS(SVG_NAMESPACE, 'use');

        svg.setAttribute('class', CLASS_ICON);
        svg.setAttribute('width', ICON_SIZE);
        svg.setAttribute('height', ICON_SIZE);
        svg.setAttribute('aria-hidden', 'true');
        use.setAttribute('href', '#' + SPRITE_ID_PREFIX + icon.v);
        svg.appendChild(use);

        return svg;
    }

    if (icon.t === ICON_TYPE_IMAGE) {
        const image = doc.createElement('img');

        image.setAttribute('class', CLASS_ICON);
        image.setAttribute('src', icon.v);
        // Empty alt, not a repeat of the label: the icon sits inside the link whose text already
        // says where it goes, so announcing it twice is noise.
        image.setAttribute('alt', '');
        image.setAttribute('loading', 'lazy');
        image.setAttribute('width', ICON_SIZE);
        image.setAttribute('height', ICON_SIZE);

        return image;
    }

    if (icon.t === ICON_TYPE_CLASS) {
        const span = doc.createElement('span');

        span.setAttribute('class', CLASS_ICON + ' ' + icon.v);
        span.setAttribute('aria-hidden', 'true');

        return span;
    }

    if (icon.t === ICON_TYPE_COLOR) {
        const span = doc.createElement('span');

        span.setAttribute('class', CLASS_ICON + ' ' + CLASS_ICON_SWATCH);
        span.setAttribute('aria-hidden', 'true');
        // Through the CSSOM rather than a style attribute in a string, so the value never passes
        // through markup. The server has already validated it as a hex colour.
        span.style.setProperty(ICON_COLOR_PROPERTY, icon.v);

        return span;
    }

    return null;
};

/**
 * One third-level entry, built from data rather than parsed from a string.
 *
 * `textContent` and `setAttribute` are the whole reason this is not an `innerHTML` template: the
 * category name is merchant input and it lands in the page as text, with no second escaping layer
 * to get wrong on top of the one the block already applied to the JSON.
 */
export const buildEntry = (item, doc) => {
    const entry = doc.createElement('li');
    const link = doc.createElement('a');
    const label = doc.createElement('span');

    entry.setAttribute('class', CLASS_ENTRY);
    link.setAttribute('class', CLASS_LINK);
    link.setAttribute('href', typeof item.u === 'string' ? item.u : '#');
    label.setAttribute('class', CLASS_LABEL);
    label.textContent = typeof item.n === 'string' ? item.n : '';

    const icon = buildIcon(item.i ?? null, doc);

    if (icon !== null) {
        link.appendChild(icon);
    }

    link.appendChild(label);
    entry.appendChild(link);

    return entry;
};

export const createView = (root, doc) => {
    const tree = root.querySelector(SELECTOR.tree);
    const islandNode = root.querySelector(SELECTOR.island);
    const island = parseIsland(islandNode === null ? '' : islandNode.textContent);

    const docks = {
        [PLACEMENT_DESKTOP]: root.querySelector(SELECTOR.dockDesktop),
        [PLACEMENT_MOBILE]: root.querySelector(SELECTOR.dockMobile),
    };

    const topItems = indexByKey(root, SELECTOR.topItem);
    const topPanels = indexByKey(root, SELECTOR.topPanel);
    const branchItems = indexByKey(root, SELECTOR.branchItem);
    const branchPanels = indexByKey(root, SELECTOR.branchPanel);
    const controls = Array.from(root.querySelectorAll(SELECTOR.control));

    /**
     * Moving the tree, rather than rendering a second one. The check makes it idempotent, so
     * `apply()` can call it on every render without touching the DOM on the ones that changed
     * nothing — and a move that did happen keeps every element identity, so the Maps above stay
     * valid and nothing has to be re-indexed.
     */
    const dockTree = (placement) => {
        const dock = docks[placement];

        if (tree !== null && dock !== null && dock !== undefined && tree.parentNode !== dock) {
            dock.appendChild(tree);
        }
    };

    const fillBranch = (key) => {
        const panel = branchPanels.get(key);

        if (panel === undefined || panel.dataset.menuFilled === FILLED_FLAG) {
            return;
        }

        // Flagged before the entries are appended, and flagged even when there are none: a branch
        // the island has nothing for must not be retried on every hover.
        panel.dataset.menuFilled = FILLED_FLAG;

        const items = island[key];

        if (!Array.isArray(items)) {
            return;
        }

        items.forEach((item) => {
            if (item !== null && typeof item === 'object') {
                panel.appendChild(buildEntry(item, doc));
            }
        });
    };

    const setOpen = (index, openKey) => {
        index.forEach((element, key) => {
            element.classList.toggle(CLASS_OPEN, key === openKey);
        });
    };

    const setExpanded = (state) => {
        controls.forEach((control) => {
            const action = control.dataset.menuControl;
            const key = control.dataset.menuKey;

            if (action === ACTION_DRAWER_OPEN) {
                control.setAttribute('aria-expanded', String(state.drawerOpen));
            } else if (action === ACTION_TOP) {
                control.setAttribute('aria-expanded', String(key === state.topKey));
            } else if (action === ACTION_BRANCH) {
                control.setAttribute('aria-expanded', String(key === state.branchKey));
            }
        });
    };

    /**
     * The panel's width is a column count, not a pixel value: the stylesheet multiplies it by the
     * column width token. Closed panels are reset to a single column so that reopening one never
     * animates from the width the previous branch left behind.
     */
    const setColumns = (state) => {
        topPanels.forEach((panel, key) => {
            panel.style.setProperty(COLUMNS_PROPERTY, String(key === state.topKey ? state.columns : 1));
        });
    };

    return {
        readControl(target) {
            const control = target !== null && typeof target.closest === 'function'
                ? target.closest(SELECTOR.control)
                : null;

            if (control === null) {
                return null;
            }

            return {
                action: control.dataset.menuControl,
                key: control.dataset.menuKey ?? null,
            };
        },

        apply(state) {
            dockTree(state.placement);
            root.classList.toggle(CLASS_DRAWER_OPEN, state.drawerOpen);

            if (state.branchKey !== null) {
                fillBranch(state.branchKey);
            }

            setOpen(topItems, state.topKey);
            setOpen(branchItems, state.branchKey);
            setColumns(state);
            setExpanded(state);
        },
    };
};

/**
 * The breakpoint is declared once, in CSS, next to the utilities that share it. Reading it back
 * out is what stops `matchMedia` and the stylesheet from being able to disagree — the failure
 * mode where the dock that is visible and the dock the tree was moved into are different ones.
 */
export const readDesktopMediaQuery = (win) => {
    try {
        const value = win
            .getComputedStyle(win.document.documentElement)
            .getPropertyValue(DESKTOP_MEDIA_PROPERTY)
            .trim();

        return value === '' ? DESKTOP_MEDIA_FALLBACK : value;
    } catch (error) {
        return DESKTOP_MEDIA_FALLBACK;
    }
};

export const createBrowser = (win) => {
    const desktop = win.matchMedia(readDesktopMediaQuery(win));
    const hover = win.matchMedia(HOVER_MEDIA);

    return {
        isDesktop: () => desktop.matches,
        canHover: () => hover.matches,
        onDesktopChange: (handler) => {
            desktop.addEventListener('change', (event) => handler(event.matches));
        },
    };
};

export const createComponent = (win) => megaMenu({
    // The state starts in the placement the server rendered the tree into, so the very first
    // apply() on a desktop viewport moves nothing.
    state: createMenuState(PLACEMENT_DESKTOP),
    createView: (root) => createView(root, win.document),
    browser: createBrowser(win),
});

/**
 * Registration, and the two orders it has to survive.
 *
 * This module is deferred, and so is Alpine's. Alpine loads from `before.body.end` while this
 * menu lives in the header, so in a stock theme this file runs first and the `alpine:init`
 * listener is the path taken. A theme that moves Alpine earlier would make that listener a
 * subscription to an event that has already fired — hence the branch: if Alpine is already on
 * `window`, register immediately instead.
 *
 * `Alpine.data()` rather than a global function, because the CSP build resolves `x-data` only
 * against registered names.
 */
export const register = (alpine, win) => alpine.data(COMPONENT_NAME, () => createComponent(win));

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    if (window.Alpine) {
        register(window.Alpine, window);
    } else {
        document.addEventListener('alpine:init', () => register(window.Alpine, window), { once: true });
    }
}
