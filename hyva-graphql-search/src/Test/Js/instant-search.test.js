import assert from 'node:assert/strict';
import { beforeEach, describe, it } from 'node:test';

import { SEARCH_QUERY, instantSearchComponent } from 'scr1be-graphql-search/instant-search.js';

const CONFIG = {
    graphqlUrl: '/graphql',
    searchResultUrl: '/catalogsearch/result/',
    productUrlSuffix: '.html',
    pageSize: 8,
    cacheTtlMs: 300000,
    minQuery: 3,
};

/** Minimal stand-in for the one DOM API the component uses, so escaping can be asserted. */
const installDocumentStub = () => {
    globalThis.document = {
        createElement: () => ({
            textContent: '',
            get innerHTML() {
                return String(this.textContent)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
            },
        }),
    };
};

const productsResponse = (items, totalCount = items.length) => ({
    ok: true,
    json: async () => ({ data: { products: { items, total_count: totalCount } } }),
});

const ITEM = {
    uid: 'a',
    name: 'Driven Backpack',
    url_key: 'driven-backpack',
    small_image: { url: '/x.jpg', label: 'x' },
    price_range: { minimum_price: { final_price: { value: 36, currency: 'USD' } } },
};

describe('instantSearchComponent', () => {
    beforeEach(() => installDocumentStub());

    it('refuses to search below the configured minimum and closes the panel', async () => {
        let called = false;
        globalThis.fetch = async () => { called = true; return productsResponse([]); };

        const c = instantSearchComponent(CONFIG)();
        c.isOpen = true;
        c.query = 'ba';
        await c.search();

        assert.equal(called, false, 'a two-character query must not reach the network');
        assert.deepEqual(c.results, []);
        assert.equal(c.totalCount, 0);
        assert.equal(c.isOpen, false);
    });

    it('sends the query and variables the schema expects, and applies the results', async () => {
        let body = null;
        globalThis.fetch = async (url, init) => {
            assert.equal(url, CONFIG.graphqlUrl);
            body = JSON.parse(init.body);
            return productsResponse([ITEM], 42);
        };

        const c = instantSearchComponent(CONFIG)();
        c.query = ' bag ';
        await c.search();

        assert.equal(body.query, SEARCH_QUERY);
        assert.deepEqual(body.variables, { search: 'bag', pageSize: 8 }, 'the query is trimmed before it is sent');
        assert.equal(c.results.length, 1);
        assert.equal(c.totalCount, 42);
        assert.equal(c.highlightIndex, 0, 'the first result is pre-highlighted so Enter has a target');
        assert.equal(c.loading, false);
    });

    it('serves a repeat query from cache without a second request', async () => {
        let requests = 0;
        globalThis.fetch = async () => { requests++; return productsResponse([ITEM], 1); };

        const c = instantSearchComponent(CONFIG)();
        c.query = 'bag';
        await c.search();
        await c.search();

        assert.equal(requests, 1, 'the second identical query must come from the cache');
        assert.equal(c.results.length, 1);
    });

    it('re-requests once the cached entry is older than the TTL', async () => {
        let requests = 0;
        globalThis.fetch = async () => { requests++; return productsResponse([ITEM], 1); };

        const c = instantSearchComponent({ ...CONFIG, cacheTtlMs: 0 });
        const component = c();
        component.query = 'bag';
        await component.search();
        await component.search();

        assert.equal(requests, 2, 'a zero TTL means every search is a fresh request');
    });

    it('aborts the previous request when a new one starts', async () => {
        const c = instantSearchComponent(CONFIG)();
        let aborted = false;
        c.controller = { abort: () => { aborted = true; } };
        globalThis.fetch = async () => productsResponse([ITEM], 1);

        c.query = 'bag';
        await c.search();

        assert.equal(aborted, true);
    });

    it('reports a GraphQL error and clears the results, but says nothing about an abort', async () => {
        globalThis.fetch = async () => ({ ok: true, json: async () => ({ errors: [{ message: 'boom' }] }) });
        const c = instantSearchComponent(CONFIG)();
        c.results = [ITEM];
        c.query = 'bag';
        await c.search();
        assert.equal(c.error, 'boom');
        assert.deepEqual(c.results, []);
        assert.equal(c.loading, false, 'the spinner is cleared even on the failure path');

        const abortErr = new Error('aborted');
        abortErr.name = 'AbortError';
        globalThis.fetch = async () => { throw abortErr; };
        const d = instantSearchComponent(CONFIG)();
        d.query = 'bag';
        await d.search();
        assert.equal(d.error, '', 'cancelling ourselves is not an error to show the shopper');
    });

    it('wraps the highlight around both ends of the list', () => {
        const c = instantSearchComponent(CONFIG)();
        c.results = [ITEM, { ...ITEM, uid: 'b' }, { ...ITEM, uid: 'c' }];
        c.highlightIndex = 2;
        c.moveHighlight(1);
        assert.equal(c.highlightIndex, 0);
        c.moveHighlight(-1);
        assert.equal(c.highlightIndex, 2);
    });

    it('builds urls from the configured suffix and appends q without losing an existing query string', () => {
        const c = instantSearchComponent({ ...CONFIG, searchResultUrl: '/search?store=2' })();
        c.query = 'red bag';
        assert.equal(c.productUrl(ITEM), '/driven-backpack.html');
        assert.equal(c.seeAllUrl(), '/search?store=2&q=red%20bag');

        const plain = instantSearchComponent(CONFIG)();
        plain.query = 'bag';
        assert.equal(plain.seeAllUrl(), '/catalogsearch/result/?q=bag');
    });

    it('escapes the product name before injecting the mark', () => {
        const c = instantSearchComponent(CONFIG)();
        c.query = 'bag';
        const out = c.highlightMatch('<img src=x> bag');
        assert.ok(!out.includes('<img'), 'markup in a product name must not survive into x-html');
        assert.ok(out.includes('<mark class="bg-yellow-200">bag</mark>'));
    });

    it('treats a regex metacharacter in the query as a literal', () => {
        const c = instantSearchComponent(CONFIG)();
        c.query = 'c++';
        assert.doesNotThrow(() => c.highlightMatch('c++ primer'));
        assert.ok(c.highlightMatch('c++ primer').includes('<mark'));
    });

    it('formats a price and stays quiet when there is none', () => {
        const c = instantSearchComponent(CONFIG)();
        assert.equal(c.formatPrice(ITEM), '36.00 USD');
        assert.equal(c.formatPrice({ ...ITEM, price_range: undefined }), '');
    });

    it('reset clears everything the panel shows', () => {
        const c = instantSearchComponent(CONFIG)();
        Object.assign(c, { query: 'bag', results: [ITEM], totalCount: 5, error: 'x', isOpen: true, highlightIndex: 1 });
        c.reset();
        assert.deepEqual({ q: c.query, r: c.results, t: c.totalCount, e: c.error, o: c.isOpen, h: c.highlightIndex },
            { q: '', r: [], t: 0, e: '', o: false, h: -1 });
    });
});
