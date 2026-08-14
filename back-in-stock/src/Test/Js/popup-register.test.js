/**
 * The adapter — the only file in the module that touches `window` and `document`.
 *
 * Everything specced here is a promise to something outside this repository: the element the PHP
 * block writes its endpoints into, the component name the template puts in `x-data`, Alpine's
 * `alpine:init` timing, and the fact that the listener must fire exactly once.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { COMPONENT_POPUP, CONFIG_SELECTOR, readConfig, register } from 'scr1be-back-in-stock/register.js';
import { EVENT_PRIVATE_CONTENT_RELOAD, SECTION } from 'scr1be-back-in-stock/popup.js';

const CONFIG = {
    endpoints: {
        dismiss: '/scr1be_backinstock/alert/dismiss/',
        addToCart: '/scr1be_backinstock/alert/addtocart/',
    },
};

/** A document with one queryable element, which is all the adapter ever asks for. */
const createDocument = (textContent) => ({
    querySelector: (selector) => (selector === CONFIG_SELECTOR && textContent !== null
        ? { textContent }
        : null),
});

const createWindow = () => {
    const listeners = [];
    const dispatched = [];

    return {
        listeners,
        dispatched,
        Alpine: {
            registeredOn: [],
            data(name, factory) {
                this.registeredOn.push([name, factory]);
            },
        },
        CustomEvent: class {
            constructor(name) {
                this.type = name;
            }
        },
        addEventListener(name, handler, options) {
            listeners.push({ name, handler, options });
        },
        dispatchEvent(event) {
            dispatched.push(event.type);
        },
        fire(name) {
            listeners.filter((listener) => listener.name === name).forEach(({ handler }) => handler());
        },
    };
};

describe('reading the config the block printed', () => {
    it('reads the endpoints', () => {
        assert.deepEqual(readConfig(createDocument(JSON.stringify(CONFIG))), CONFIG);
    });

    it('degrades to a popup that posts nowhere rather than throwing', () => {
        // An exception here runs at import time, before Alpine has started, and takes every other
        // component on the page down with it.
        ['', 'not json', '[1,2]', 'null'].forEach((body) => {
            assert.deepEqual(readConfig(createDocument(body)), { endpoints: {} }, body);
        });
    });

    it('survives a page that has no config element at all', () => {
        assert.deepEqual(readConfig(createDocument(null)), { endpoints: {} });
    });
});

describe('registration', () => {
    it('waits for alpine:init instead of assuming Alpine is already there', () => {
        // Hyvä loads Alpine as a deferred module. Calling Alpine.data() at import time works exactly
        // until the theme changes how it bootstraps.
        const win = createWindow();

        register(win, createDocument(JSON.stringify(CONFIG)));

        assert.deepEqual(win.Alpine.registeredOn, []);
        assert.equal(win.listeners.length, 1);
        assert.equal(win.listeners[0].name, 'alpine:init');
    });

    it('listens once, so a second alpine:init cannot register the component twice', () => {
        const win = createWindow();

        register(win, createDocument(JSON.stringify(CONFIG)));

        assert.deepEqual(win.listeners[0].options, { once: true });
    });

    it('registers under exactly the name the template puts in x-data', () => {
        const win = createWindow();

        register(win, createDocument(JSON.stringify(CONFIG)));
        win.fire('alpine:init');

        assert.deepEqual(win.Alpine.registeredOn.map(([name]) => name), [COMPONENT_POPUP]);
        assert.equal(COMPONENT_POPUP, 'scr1beBackInStockPopup');
    });

    it('hands Alpine a factory, not an instance', () => {
        // Alpine.data() calls the factory once per element; passing an object would share one piece
        // of state across every copy of the component on the page.
        const win = createWindow();

        register(win, createDocument(JSON.stringify(CONFIG)));
        win.fire('alpine:init');

        const factory = win.Alpine.registeredOn[0][1];

        assert.equal(typeof factory, 'function');
        assert.equal(typeof factory.call({}), 'object');
    });
});

describe('the bridge to the page', () => {
    const componentFrom = (win) => {
        register(win, createDocument(JSON.stringify(CONFIG)));
        win.fire('alpine:init');

        return win.Alpine.registeredOn[0][1].call({});
    };

    it('dispatches Hyvä\'s own refresh event on the window', () => {
        // `$dispatch` would only bubble up the DOM; Hyvä's private-content bootstrap listens on
        // `window`.
        const win = createWindow();
        const component = componentFrom(win);

        component.items = [];
        component.settle();

        assert.deepEqual(win.dispatched, [EVENT_PRIVATE_CONTENT_RELOAD]);
        assert.equal(EVENT_PRIVATE_CONTENT_RELOAD, 'reload-customer-section-data');
    });

    it('does not fall over on a page without the Hyvä focus helpers', () => {
        const win = createWindow();
        const component = componentFrom(win);

        assert.doesNotThrow(() => component.trap({}));
    });

    it('uses hyva.trapFocus when it is there', () => {
        const win = createWindow();
        const trapped = [];
        win.hyva = { trapFocus: (element) => trapped.push(element) };

        const component = componentFrom(win);
        const panel = { id: 'panel' };
        component.trap(panel);

        assert.deepEqual(trapped, [panel]);
    });
});

describe('the section name', () => {
    it('matches the key registered in etc/frontend/di.xml', () => {
        // `Magento\Customer\CustomerData\SectionPool` returns the section under the key from the
        // `sectionSourceMap`, and a near-miss here is a popup that never has anything to show.
        assert.equal(SECTION, 'scr1be-back-in-stock');
    });
});
