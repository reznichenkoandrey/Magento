import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    ALPINE_INIT_EVENT,
    CONFIG_SELECTOR,
    FALLBACK_CONFIG,
    STORE_NAME,
    readConfig,
    register,
} from 'scr1be-compare-drawer/register.js';

const docWith = (textContent) => ({
    querySelector: (sel) => (sel === CONFIG_SELECTOR && textContent !== null ? { textContent } : null),
});

const fakeWindow = () => {
    const listeners = [];
    const stored = {};
    return {
        listeners,
        stored,
        localStorage: { getItem: () => null, setItem: () => {} },
        Alpine: { store: (name, value) => { stored[name] = value; } },
        addEventListener(event, handler, options) { listeners.push({ event, handler, options }); },
    };
};

describe('readConfig', () => {
    it('takes the island and falls back key by key', () => {
        const cfg = readConfig(docWith(JSON.stringify({ maxItems: 6 })));
        assert.equal(cfg.maxItems, 6);
        assert.equal(cfg.storageKey, FALLBACK_CONFIG.storageKey);
    });

    it('falls back entirely when the island is missing or malformed', () => {
        assert.deepEqual(readConfig(docWith(null)), FALLBACK_CONFIG);
        assert.deepEqual(readConfig(docWith('nope')), FALLBACK_CONFIG);
    });
});

describe('register', () => {
    it('subscribes to alpine:init once and registers nothing before it fires', () => {
        const win = fakeWindow();
        register(win, docWith(null));

        assert.equal(win.listeners.length, 1);
        assert.equal(win.listeners[0].event, ALPINE_INIT_EVENT);
        assert.deepEqual(win.listeners[0].options, { once: true });
        assert.equal(win.stored[STORE_NAME], undefined, 'the store must not exist until Alpine asks for it');
    });

    it('registers under the name templates address as $store.compare, carrying the island config', () => {
        const win = fakeWindow();
        register(win, docWith(JSON.stringify({ maxItems: 2 })));
        win.listeners[0].handler();

        assert.equal(STORE_NAME, 'compare');
        const store = win.stored[STORE_NAME];
        assert.ok(store, 'nothing was registered');
        assert.equal(store.max, 2, 'the cap from the island reached the store');

        store.add({ id: 1 });
        store.add({ id: 2 });
        store.add({ id: 3 });
        assert.deepEqual(store.items.map((i) => i.id), [2, 3], 'and the store actually enforces it');
    });

    it('hands the store the real browser storage and window as its event target', () => {
        const win = fakeWindow();
        let askedFor = null;
        win.localStorage.getItem = (k) => { askedFor = k; return null; };
        register(win, docWith(null));
        win.listeners[0].handler();

        assert.equal(askedFor, FALLBACK_CONFIG.storageKey, 'the store read through the window it was given');

        win.stored[STORE_NAME].init();
        assert.ok(win.listeners.some((l) => l.event === 'storage'),
            'cross-tab sync is wired to the same window');
    });
});
