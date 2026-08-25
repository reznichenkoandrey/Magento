import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    ALPINE_INIT_EVENT,
    CONFIG_SELECTOR,
    FALLBACK_CONFIG,
    STORE_NAME,
    readConfig,
    register,
} from 'scr1be-quick-view/register.js';

const docWith = (textContent) => ({
    activeElement: null,
    querySelector: (sel) => (sel === CONFIG_SELECTOR && textContent !== null ? { textContent } : null),
});

const fakeWindow = () => {
    const listeners = [];
    const stored = {};
    return {
        listeners, stored,
        Alpine: { store: (name, value) => { stored[name] = value; } },
        addEventListener(e, h, o) { listeners.push({ event: e, handler: h, options: o }); },
    };
};

describe('readConfig', () => {
    it('takes the island, including the PHP-translated failure message', () => {
        const cfg = readConfig(docWith(JSON.stringify({ errorTitle: 'Не вдалося завантажити товар' })));
        assert.equal(cfg.errorTitle, 'Не вдалося завантажити товар');
        assert.equal(cfg.infoUrl, FALLBACK_CONFIG.infoUrl, 'an omitted key falls back rather than becoming undefined');
    });

    it('falls back when the island is missing or malformed', () => {
        assert.deepEqual(readConfig(docWith(null)), FALLBACK_CONFIG);
        assert.deepEqual(readConfig(docWith('{{')), FALLBACK_CONFIG);
    });
});

describe('register', () => {
    it('subscribes to alpine:init once, and registers nothing until it fires', () => {
        const win = fakeWindow();
        register(win, docWith(null));

        assert.equal(win.listeners.length, 1);
        assert.equal(win.listeners[0].event, ALPINE_INIT_EVENT);
        assert.deepEqual(win.listeners[0].options, { once: true });
        assert.equal(win.stored[STORE_NAME], undefined);
    });

    it('registers as $store.quickView with the island config and the live document', async () => {
        const doc = docWith(JSON.stringify({ infoUrl: '/info', errorTitle: 'nope' }));
        const trigger = { focused: 0, focus() { this.focused++; } };
        doc.activeElement = trigger;

        const win = fakeWindow();
        register(win, doc);
        win.listeners[0].handler();

        assert.equal(STORE_NAME, 'quickView');
        const store = win.stored[STORE_NAME];
        assert.ok(store);

        globalThis.fetch = async () => { throw new Error('offline'); };
        await store.show(1);
        assert.equal(store.title, 'nope', 'the store got the config, not the fallback');
        assert.equal(store.lastFocused, trigger, 'and the document it captures focus from');
    });
});
