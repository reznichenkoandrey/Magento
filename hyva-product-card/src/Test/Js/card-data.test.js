/**
 * The pure half: quantity rules, stock merging and GA4 payloads.
 *
 * The quantity rules matter most. They are the browser half of a contract whose server half is
 * `Model\Card\QtyRuleResolver`, and a stepper that lets a shopper past the ladder does not fail
 * visibly — it fails at checkout, in a validator, with a message about quantity increments.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    applyStockUpdate,
    clampQty,
    nextQty,
    previousQty,
    roundQty,
    toGa4Item,
    toGa4ViewItemList,
    toStockRequestIds,
} from 'scr1be-product-card/data.js';

const rules = (overrides = {}) => ({ min: 1, step: 1, max: null, is_decimal: false, ...overrides });

const card = (overrides = {}) => ({
    id: 12,
    stock: { in_stock: true, label: 'In stock', is_low: false, salable_qty: null },
    analytics: { item_id: '24-MB01', item_list_id: 'category_4', item_list_name: 'Bags', index: 1 },
    ...overrides,
});

describe('clamping a quantity onto the product ladder', () => {
    it('never goes below the minimum', () => {
        assert.equal(clampQty(0, rules({ min: 5, step: 5 })), 5);
        assert.equal(clampQty(-40, rules({ min: 5, step: 5 })), 5);
    });

    it('only ever offers whole multiples of the increment', () => {
        // Core rejects anything that does not divide exactly by qty_increments
        // (StockStateProvider::checkQtyIncrements), so "packs of 6" means 6, 12, 18 — measured from
        // zero, not from wherever the minimum happens to sit.
        const packs = rules({ min: 12, step: 6 });

        assert.deepEqual([13, 15, 17, 18].map((qty) => clampQty(qty, packs)), [12, 18, 18, 18]);
    });

    it('lifts a minimum that is not itself on the ladder', () => {
        // "Minimum 10, increments of 6" has no legal quantity at 10. The smallest is 12 — the same
        // conclusion core reaches in suggestQty() and Hyvä reaches in its PDP quantity template.
        const awkward = rules({ min: 10, step: 6 });

        assert.equal(clampQty(10, awkward), 12);
        assert.equal(clampQty(1, awkward), 12);
    });

    it('stays on the ladder when the ceiling is not itself a step', () => {
        // Snapping to the nearest step can land above the maximum; the only correction that is
        // still a legal quantity is the step below it.
        assert.equal(clampQty(60, rules({ min: 12, step: 6, max: 50 })), 48);
    });

    it('reports the minimum rather than an unbuyable number when the bounds cross', () => {
        // Minimum 12, increments of 10, ceiling 15: the aligned ceiling is 10 and the aligned
        // minimum is 20. There is no purchasable quantity, and the stepper says so by refusing to
        // go below the minimum instead of offering a number the cart will reject.
        assert.equal(clampQty(15, rules({ min: 12, step: 10, max: 15 })), 20);
    });

    it('treats a zero or missing maximum as no ceiling at all', () => {
        assert.equal(clampQty(900, rules({ min: 1, step: 1, max: 0 })), 900);
        assert.equal(clampQty(900, rules({ min: 1, step: 1, max: null })), 900);
    });

    it('falls back to whole units when the rules are nonsense', () => {
        assert.equal(clampQty(3, { min: 0, step: 0, max: null }), 3);
        assert.equal(clampQty(Number.NaN, rules()), 1);
        assert.equal(clampQty('nonsense', rules()), 1);
    });

    it('rounds to the precision the column has, so a decimal stepper stays readable', () => {
        assert.equal(roundQty(0.1 + 0.2), 0.3);
        assert.equal(clampQty(0.3, rules({ min: 0.1, step: 0.1, is_decimal: true })), 0.3);
    });
});

describe('stepping', () => {
    it('moves by exactly one step in each direction', () => {
        const packs = rules({ min: 12, step: 6, max: 24 });

        assert.equal(nextQty(12, packs), 18);
        assert.equal(previousQty(18, packs), 12);
    });

    it('refuses to step past either end', () => {
        const packs = rules({ min: 12, step: 6, max: 24 });

        assert.equal(previousQty(12, packs), 12);
        assert.equal(nextQty(24, packs), 24);
    });
});

describe('merging a fresh stock answer into a card', () => {
    it('reports no change when nothing moved, so nothing re-renders', () => {
        const subject = card();
        const result = applyStockUpdate(subject, { 12: { ...subject.stock } });

        assert.equal(result.changed, false);
        assert.deepEqual(result.stock, subject.stock);
    });

    it('takes the fresh answer when availability changed', () => {
        const result = applyStockUpdate(card(), {
            12: { in_stock: false, label: 'Out of stock', is_low: false, salable_qty: 0 },
        });

        assert.equal(result.changed, true);
        assert.equal(result.stock.label, 'Out of stock');
    });

    it('reads a missing entry as "no news", never as "out of stock"', () => {
        // The endpoint truncates long id lists and skips products that vanished between the render
        // and the fetch. A stale label is a much smaller problem than a card that erases its own
        // availability because a key was absent.
        const subject = card();

        assert.deepEqual(applyStockUpdate(subject, {}), { changed: false, stock: subject.stock });
        assert.deepEqual(applyStockUpdate(subject, undefined), { changed: false, stock: subject.stock });
    });
});

describe('the stock request', () => {
    it('asks in a stable order, so one answer is one cache entry', () => {
        assert.deepEqual(toStockRequestIds([{ id: 9 }, { id: 3 }, { id: 9 }, { id: 5 }]), [3, 5, 9]);
    });

    it('drops anything that is not a product id', () => {
        assert.deepEqual(toStockRequestIds([{ id: 0 }, { id: -2 }, { id: 'x' }, { id: 7 }]), [7]);
    });
});

describe('GA4 payloads', () => {
    it('corrects the position the server could not know', () => {
        // A Hyvä listing renders one card at a time and never tells it where in the grid it sits,
        // so every server payload carries the same index. The DOM knows.
        assert.equal(toGa4Item(card(), 4).index, 5);
    });

    it('keeps the list identity the server resolved', () => {
        const item = toGa4Item(card(), 0);

        assert.equal(item.item_list_id, 'category_4');
        assert.equal(item.item_list_name, 'Bags');
    });

    it('survives a card with no analytics payload at all', () => {
        assert.deepEqual(toGa4Item(card({ analytics: undefined }), 0), { index: 1 });
    });

    it('numbers a whole list from one and lifts the list identity to the top', () => {
        const payload = toGa4ViewItemList([card({ id: 1 }), card({ id: 2 })]);

        assert.equal(payload.item_list_id, 'category_4');
        assert.deepEqual(payload.items.map((item) => item.index), [1, 2]);
    });

    it('reports an empty list without inventing an identity for it', () => {
        assert.deepEqual(toGa4ViewItemList([]), { item_list_id: '', item_list_name: '', items: [] });
    });
});
