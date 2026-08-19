import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    ALPINE_INIT_EVENT,
    COMPONENT_NAME,
    CONFIG_SELECTOR,
    FALLBACK_CONFIG,
    readConfig,
    register,
} from 'scr1be-graphql-search/register.js';

const docWith = (textContent) => ({
    querySelector: (sel) => (sel === CONFIG_SELECTOR && textContent !== null ? { textContent } : null),
});

const fakeWindow = () => {
    const listeners = [];
    return {
        listeners,
        registered: {},
        Alpine: null,
        addEventListener(event, handler, options) {
            listeners.push({ event, handler, options });
        },
    };
};

describe('readConfig', () => {
    it('parses the island and keeps every key the component reads', () => {
        const cfg = readConfig(docWith(JSON.stringify({ graphqlUrl: '/gql', minQuery: 2 })));
        assert.equal(cfg.graphqlUrl, '/gql');
        assert.equal(cfg.minQuery, 2);
        assert.equal(cfg.pageSize, FALLBACK_CONFIG.pageSize, 'keys the island omits fall back rather than becoming undefined');
    });

    it('falls back when the island is absent or malformed', () => {
        assert.deepEqual(readConfig(docWith(null)), FALLBACK_CONFIG);
        assert.deepEqual(readConfig(docWith('{ not json')), FALLBACK_CONFIG);
    });

    it('returns a copy, so one page cannot mutate the fallback for the next read', () => {
        const first = readConfig(docWith(null));
        first.minQuery = 99;
        assert.equal(readConfig(docWith(null)).minQuery, FALLBACK_CONFIG.minQuery);
    });
});

describe('register', () => {
    it('subscribes to alpine:init exactly once', () => {
        const win = fakeWindow();
        register(win, docWith(null));

        assert.equal(win.listeners.length, 1);
        assert.equal(win.listeners[0].event, ALPINE_INIT_EVENT);
        assert.deepEqual(win.listeners[0].options, { once: true },
            'Alpine re-dispatches init if it restarts; registering twice would replace the definition on mounted elements');
    });

    it('registers the component under the name the template writes into x-data', () => {
        const win = fakeWindow();
        register(win, docWith(JSON.stringify({ minQuery: 4 })));

        win.Alpine = { data: (name, factory) => { win.registered[name] = factory; } };
        win.listeners[0].handler();

        assert.equal(COMPONENT_NAME, 'scr1beInstantSearch');
        assert.ok(win.registered[COMPONENT_NAME], 'nothing was registered under the expected name');

        // The factory has to carry the island's configuration, not the fallback.
        const component = win.registered[COMPONENT_NAME]();
        assert.equal(component.minQuery, 4);
    });

    it('does nothing at import time beyond adding the listener', () => {
        const win = fakeWindow();
        register(win, docWith(null));
        assert.equal(win.registered[COMPONENT_NAME], undefined,
            'registration must wait for alpine:init, not happen while the module is evaluated');
    });
});
