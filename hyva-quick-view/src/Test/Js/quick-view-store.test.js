import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { createQuickViewStore } from 'scr1be-quick-view/store.js';

const focusable = () => {
    const el = { focused: 0, focus() { this.focused++; } };
    return el;
};

const docWithActive = (active) => ({ activeElement: active });

const build = (over = {}) => createQuickViewStore({
    infoUrl: over.infoUrl ?? '/scr1be_quickview/product/info/',
    errorTitle: over.errorTitle ?? 'Could not load product',
    doc: over.doc ?? docWithActive(focusable()),
});

describe('createQuickViewStore', () => {
    it('opens, requests the product and fills the modal', async () => {
        let requested = null;
        globalThis.fetch = async (url, init) => {
            requested = { url, accept: init.headers.Accept };
            return { ok: true, json: async () => ({ title: 'Joust Duffle Bag', html: '<p>body</p>' }) };
        };

        const store = build();
        await store.show(42);

        assert.equal(requested.url, '/scr1be_quickview/product/info/?id=42');
        assert.equal(requested.accept, 'application/json');
        assert.equal(store.open, true);
        assert.equal(store.loading, false);
        assert.equal(store.title, 'Joust Duffle Bag');
        assert.equal(store.html, '<p>body</p>');
    });

    it('appends id with & when the endpoint already carries a query string', async () => {
        let url = null;
        globalThis.fetch = async (u) => { url = u; return { ok: true, json: async () => ({ title: '', html: '' }) }; };

        await build({ infoUrl: '/info?store=2' }).show(7);
        assert.equal(url, '/info?store=2&id=7');
    });

    it('encodes the product id rather than trusting it', async () => {
        let url = null;
        globalThis.fetch = async (u) => { url = u; return { ok: true, json: async () => ({ title: '', html: '' }) }; };

        await build().show('7&x=1');
        assert.ok(url.endsWith('id=7%26x%3D1'), `id was not encoded: ${url}`);
    });

    it('remembers what had focus before opening and restores it on close', async () => {
        const trigger = focusable();
        globalThis.fetch = async () => ({ ok: true, json: async () => ({ title: 't', html: 'h' }) });

        const store = build({ doc: docWithActive(trigger) });
        await store.show(1);
        assert.equal(store.lastFocused, trigger);

        store.close();
        assert.equal(trigger.focused, 1, 'focus must go back to the button that opened the modal');
        assert.equal(store.open, false);
        assert.equal(store.html, '', 'the body is dropped so the next open cannot flash the previous product');
    });

    it('closes without throwing when nothing had focus', () => {
        const store = build({ doc: docWithActive(null) });
        assert.doesNotThrow(() => store.close());
    });

    it('stays open and reports the failure, using the translated title it was given', async () => {
        globalThis.fetch = async () => ({ ok: false, status: 503, json: async () => ({}) });

        const store = build({ errorTitle: 'Не вдалося завантажити товар' });
        await store.show(1);

        assert.equal(store.open, true, 'closing on failure would look like a click that did nothing');
        assert.equal(store.title, 'Не вдалося завантажити товар');
        assert.ok(store.html.includes('HTTP 503'));
        assert.equal(store.loading, false, 'the spinner clears on the failure path too');
    });

    it('clears the previous body before the new request resolves', async () => {
        let resolveFetch;
        globalThis.fetch = () => new Promise((res) => { resolveFetch = () => res({ ok: true, json: async () => ({ title: 'b', html: 'B' }) }); });

        const store = build();
        store.title = 'previous';
        store.html = 'PREVIOUS';
        const pending = store.show(2);

        assert.equal(store.html, '', 'the old body must not be on screen while the new one loads');
        assert.equal(store.loading, true);

        resolveFetch();
        await pending;
        assert.equal(store.html, 'B');
    });
});
