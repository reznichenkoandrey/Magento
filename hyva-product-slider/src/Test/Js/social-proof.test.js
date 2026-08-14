import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { applyProofs, buildProofUrl, fetchProofs } from 'scr1be-product-slider/proof.js';

const createRoot = (productIds) => {
    const nodes = productIds.map((id) => ({
        attributes: { 'data-scr1be-proof': String(id) },
        textContent: '',
        hidden: true,
        getAttribute(name) {
            return this.attributes[name];
        }
    }));

    return { nodes, querySelectorAll: () => nodes };
};

describe('proof url', () => {
    it('sorts and de-duplicates, so two sliders over the same products share one cache entry', () => {
        assert.equal(buildProofUrl('/scr1be_slider/proof/', [9, 3, 3, 7]), '/scr1be_slider/proof/?ids=3,7,9');
    });

    it('drops ids that are not ids', () => {
        assert.equal(buildProofUrl('/proof', [1, 0, -4, 'x', null, 2]), '/proof?ids=1,2');
    });

    it('appends to an endpoint that already carries a query string', () => {
        assert.equal(buildProofUrl('/proof?store=2', [1]), '/proof?store=2&ids=1');
    });

    it('returns nothing to fetch when there is nothing to ask about', () => {
        assert.equal(buildProofUrl('/proof', []), '');
        assert.equal(buildProofUrl('', [1]), '');
        assert.equal(buildProofUrl(null, [1]), '');
    });
});

describe('applying proofs', () => {
    it('fills and reveals only the products the endpoint answered for', () => {
        const root = createRoot([1, 2]);

        const applied = applyProofs(root, { 1: { text: '17 minutes ago, Anna bought this' } });

        assert.equal(applied, 1);
        assert.equal(root.nodes[0].textContent, '17 minutes ago, Anna bought this');
        assert.equal(root.nodes[0].hidden, false);

        // A product nobody bought inside the window stays hidden. An empty visible line is worse
        // than no line.
        assert.equal(root.nodes[1].textContent, '');
        assert.equal(root.nodes[1].hidden, true);
    });

    it('leaves a line hidden when the payload has no text for it', () => {
        const root = createRoot([1]);

        assert.equal(applyProofs(root, { 1: { purchases: 3 } }), 0);
        assert.equal(root.nodes[0].hidden, true);
    });

    it('assigns text rather than markup, because the sentence contains shopper-supplied words', () => {
        const root = createRoot([1]);

        applyProofs(root, { 1: { text: '<img src=x onerror=alert(1)>' } });

        // Written to `textContent`: the browser renders it as characters, never as an element.
        assert.equal(root.nodes[0].textContent, '<img src=x onerror=alert(1)>');
    });

    it('does nothing at all without a root or a payload', () => {
        assert.equal(applyProofs(null, { 1: { text: 'x' } }), 0);
        assert.equal(applyProofs(createRoot([1]), null), 0);
    });
});

describe('fetching proofs', () => {
    it('asks for JSON and returns the items map', async () => {
        const calls = [];
        const fetchImpl = async (url, options) => {
            calls.push([url, options]);

            return { ok: true, json: async () => ({ items: { 1: { text: 'x' } } }) };
        };

        const items = await fetchProofs('/proof', [1], fetchImpl);

        assert.deepEqual(items, { 1: { text: 'x' } });
        assert.equal(calls[0][0], '/proof?ids=1');
        assert.equal(calls[0][1].headers.Accept, 'application/json');
    });

    it('never fetches when there is nothing to ask about', async () => {
        let called = false;

        await fetchProofs('/proof', [], async () => {
            called = true;
        });

        assert.equal(called, false);
    });

    it('swallows a failed response, because the carousel already rendered', async () => {
        const items = await fetchProofs('/proof', [1], async () => ({ ok: false }));

        assert.deepEqual(items, {});
    });

    it('swallows a rejected request rather than leaving an unhandled rejection on every page', async () => {
        const items = await fetchProofs('/proof', [1], async () => {
            throw new Error('offline');
        });

        assert.deepEqual(items, {});
    });

    it('swallows a body that is not the shape the endpoint promises', async () => {
        const items = await fetchProofs('/proof', [1], async () => ({ ok: true, json: async () => 'nope' }));

        assert.deepEqual(items, {});
    });
});
