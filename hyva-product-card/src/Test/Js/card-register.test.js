/**
 * The adapter — the only file in the module that touches `window`, `document` and Alpine.
 *
 * Everything specced here is a promise to something outside this repository: the component names
 * the templates put in `x-data`, the element the PHP block writes the endpoint config into,
 * Alpine's `alpine:init` timing, and the fact that the listener must fire exactly once. Those are
 * the things that break silently when a template is renamed or a theme changes its bootstrap, so
 * those are the things asserted.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    COMPONENT_CARD,
    COMPONENT_GRID,
    CONFIG_SELECTOR,
    readConfig,
    register,
} from 'scr1be-product-card/register.js';
import { EVENT_GA4, EVENT_MESSAGES_LOADED, EVENT_PRIVATE_CONTENT_RELOAD } from 'scr1be-product-card/card.js';

const CONFIG = {
    endpoints: { stock: '/scr1be_card/stock/status/', drain: '/scr1be_card/message/drain/' },
    ga4: true,
};

/** A document with one queryable element, which is all the adapter ever asks for. */
const createDocument = (textContent) => ({
    querySelector: (selector) => (selector === CONFIG_SELECTOR && textContent !== null
        ? { textContent }
        : null),
});

const createWindow = () => {
    const listeners = [];

    return {
        listeners,
        Alpine: {
            data(name, factory) {
                this.registeredOn.push([name, factory]);
            },
            registeredOn: [],
        },
        addEventListener(name, handler, options) {
            listeners.push({ name, handler, options });
        },
        fire(name) {
            listeners.filter((listener) => listener.name === name).forEach(({ handler }) => handler());
        },
    };
};

describe('reading the config the block printed', () => {
    it('reads the endpoints and the analytics switch', () => {
        assert.deepEqual(readConfig(createDocument(JSON.stringify(CONFIG))), CONFIG);
    });

    it('degrades to a card that never fetches rather than throwing', () => {
        // A malformed config is a card that shows its cached stock label. An exception here would
        // take Alpine down with it, on every page, for everyone.
        ['', 'not json', '[1,2]', 'null'].forEach((body) => {
            assert.deepEqual(readConfig(createDocument(body)), { endpoints: {}, ga4: false }, body);
        });
    });

    it('survives a page that has no config element at all', () => {
        assert.deepEqual(readConfig(createDocument(null)), { endpoints: {}, ga4: false });
    });

    it('coerces the analytics switch, because JSON true and 1 both arrive from PHP', () => {
        assert.equal(readConfig(createDocument('{"ga4":1}')).ga4, true);
        assert.equal(readConfig(createDocument('{}')).ga4, false);
    });
});

describe('registration', () => {
    it('waits for alpine:init instead of assuming Alpine is already there', () => {
        // Hyvä loads Alpine as a deferred module. Calling Alpine.data() at import time works
        // exactly until the theme changes how it bootstraps.
        const win = createWindow();

        register(win, createDocument(JSON.stringify(CONFIG)));

        assert.deepEqual(win.Alpine.registeredOn, []);
        assert.equal(win.listeners.length, 1);
        assert.equal(win.listeners[0].name, 'alpine:init');
    });

    it('listens once, so a second alpine:init cannot register the components twice', () => {
        const win = createWindow();

        register(win, createDocument(JSON.stringify(CONFIG)));

        assert.deepEqual(win.listeners[0].options, { once: true });
    });

    it('registers under exactly the names the templates put in x-data', () => {
        const win = createWindow();

        register(win, createDocument(JSON.stringify(CONFIG)));
        win.fire('alpine:init');

        assert.deepEqual(win.Alpine.registeredOn.map(([name]) => name), [COMPONENT_CARD, COMPONENT_GRID]);
        assert.equal(COMPONENT_CARD, 'scr1beProductCard');
        assert.equal(COMPONENT_GRID, 'scr1beCardGrid');
    });

    it('hands Alpine factories, not instances', () => {
        // Alpine.data() calls the factory once per component on the page; passing an object would
        // make every card on a grid share one piece of state.
        const win = createWindow();

        register(win, createDocument(JSON.stringify(CONFIG)));
        win.fire('alpine:init');

        win.Alpine.registeredOn.forEach(([name, factory]) => {
            assert.equal(typeof factory, 'function', name);
            assert.equal(typeof factory.call({}), 'object', name);
        });
    });

    it('gives both components the same config object', () => {
        const win = createWindow();

        register(win, createDocument(JSON.stringify(CONFIG)));
        win.fire('alpine:init');

        const grid = win.Alpine.registeredOn[1][1].call({});

        assert.deepEqual(grid.endpoints, CONFIG.endpoints);
        assert.equal(grid.ga4, true);
    });
});

describe('the browser events this module speaks', () => {
    it('uses Hyvä\'s own names, because Hyvä is what listens', () => {
        // `messages-loaded` is bound in Hyvä's Magento_Theme::messages.phtml and
        // `reload-customer-section-data` in its private-content bootstrap. A near-miss here is a
        // message nobody shows and a minicart that never refreshes — both silent.
        assert.equal(EVENT_MESSAGES_LOADED, 'messages-loaded');
        assert.equal(EVENT_PRIVATE_CONTENT_RELOAD, 'reload-customer-section-data');
    });

    it('namespaces its own event so a tag manager can tell it apart', () => {
        assert.equal(EVENT_GA4, 'scr1be-card:ga4');
    });
});
