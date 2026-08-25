import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { EMPTY_CONFIG } from '../../view/frontend/web/js/store-switcher.js';
import {
    ALPINE_INIT_EVENT,
    COMPONENT_DRAWER,
    COMPONENT_LINKS,
    CONFIG_SELECTOR,
    readConfig,
    registerStoreSwitcher
} from '../../view/frontend/web/js/store-switcher-register.js';

/**
 * A document that answers only the selector the module is allowed to ask for. Asking for anything
 * else fails the spec rather than returning null, so a renamed selector cannot pass quietly.
 */
const documentWith = (textContent) => ({
    querySelector(selector) {
        assert.equal(selector, CONFIG_SELECTOR);

        return textContent === null ? null : { textContent };
    }
});

/** Node's EventTarget gives real `{ once: true }` semantics instead of a hand-rolled imitation. */
const windowSpy = () => {
    const target = new EventTarget();
    const registered = [];

    return {
        registered,
        addEventListener: target.addEventListener.bind(target),
        dispatchEvent: target.dispatchEvent.bind(target),
        Alpine: {
            data: (name, factory) => registered.push({ name, factory })
        }
    };
};

const init = (win) => win.dispatchEvent(new Event(ALPINE_INIT_EVENT));

describe('readConfig', () => {
    it('falls back to the defaults when the island is not on the page', () => {
        assert.deepEqual(readConfig(documentWith(null)), EMPTY_CONFIG);
    });

    it('survives a malformed payload instead of taking the page down with it', () => {
        // Throwing here would abort the alpine:init listener and every component registered after
        // this one would silently never exist.
        assert.doesNotThrow(() => readConfig(documentWith('{ not json')));
        assert.deepEqual(readConfig(documentWith('{ not json')), EMPTY_CONFIG);
    });

    it('merges over the defaults rather than replacing them', () => {
        const parsed = readConfig(documentWith(JSON.stringify({ currentCode: 'de' })));

        assert.equal(parsed.currentCode, 'de');
        // Not supplied by the island, so it must still carry core's parameter name.
        assert.equal(parsed.storeParam, '___store');
        assert.deepEqual(parsed.stores, []);
    });

    it('does not let a fallback object be shared between calls', () => {
        const first = readConfig(documentWith(null));

        first.stores.push({ code: 'leaked' });

        assert.deepEqual(readConfig(documentWith(null)).stores, []);
    });
});

describe('registerStoreSwitcher', () => {
    it('registers nothing until Alpine announces itself', () => {
        const win = windowSpy();

        registerStoreSwitcher(win, documentWith(null));

        assert.deepEqual(win.registered, []);
    });

    it('registers both component names the templates address', () => {
        const win = windowSpy();

        registerStoreSwitcher(win, documentWith(null));
        init(win);

        assert.deepEqual(
            win.registered.map((entry) => entry.name),
            [COMPONENT_LINKS, COMPONENT_DRAWER]
        );
    });

    it('reads the config at init time, not at registration time', () => {
        // The block renders the island into the body; the module is a deferred head script, so
        // reading on import would be reading an element that does not exist yet.
        let reads = 0;
        const doc = {
            querySelector: () => {
                reads += 1;

                return { textContent: JSON.stringify({ currentCode: 'de' }) };
            }
        };
        const win = windowSpy();

        registerStoreSwitcher(win, doc);
        assert.equal(reads, 0);

        init(win);
        assert.equal(reads, 1);
    });

    it('hands the drawer the parsed config', () => {
        const win = windowSpy();
        const stores = [{ code: 'de', baseUrl: 'https://shop.test/de/' }];

        registerStoreSwitcher(win, documentWith(JSON.stringify({
            currentCode: 'en',
            currentBaseUrl: 'https://shop.test/en/',
            redirectUrl: 'https://shop.test/stores/store/redirect/',
            stores
        })));
        init(win);

        const drawer = win.registered.find((entry) => entry.name === COMPONENT_DRAWER).factory();

        drawer.$refs = { select: { value: 'de' } };
        win.location = { href: 'https://shop.test/en/a.html', assign: (url) => { win.went = url; } };
        drawer.switchStore();

        assert.equal(new URL(win.went).searchParams.get('___store'), 'de');
    });

    it('registers once even though Alpine re-dispatches the event when it restarts', () => {
        // Re-registering would swap the component definition out from under already-mounted nodes.
        const win = windowSpy();

        registerStoreSwitcher(win, documentWith(null));
        init(win);
        init(win);
        init(win);

        assert.equal(win.registered.length, 2);
    });
});
