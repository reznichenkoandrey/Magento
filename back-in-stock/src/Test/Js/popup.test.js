/**
 * The component's behaviour, driven with no DOM at all — which is the reason it takes a bridge
 * object rather than reaching for `window`.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { EVENT_PRIVATE_CONTENT_RELOAD, SECTION, popupComponent } from 'scr1be-back-in-stock/popup.js';

const CONFIG = { endpoints: { dismiss: '/dismiss', addToCart: '/addtocart' } };

const item = (overrides = {}) => ({
    alert_id: 1,
    name: 'Chaz Kangeroo Hoodie',
    can_add_to_cart: true,
    qty: { min: 1, max: 0, increment: 0, decimal: false, start: 1 },
    ...overrides,
});

const sectionData = (items) => ({ [SECTION]: { count: items.length, items } });

const build = (responses = []) => {
    const calls = [];
    const reloaded = [];
    const focus = { released: 0, trapped: [] };
    const queue = [...responses];

    const post = async (url, params) => {
        calls.push({ url, params });

        return queue.length > 0 ? queue.shift() : { success: true, added: 1, skipped: [] };
    };

    const bridge = {
        reload: (name) => reloaded.push(name),
        trapFocus: (element) => focus.trapped.push(element),
        releaseFocus: () => { focus.released += 1; },
    };

    return { component: popupComponent(CONFIG, post, bridge).call({}), calls, reloaded, focus };
};

describe('receiving customer data', () => {
    it('opens when there is something to show', () => {
        const { component } = build();

        component.receive(sectionData([item()]));

        assert.equal(component.open, true);
        assert.equal(component.items.length, 1);
    });

    it('stays shut on a payload with no section of ours in it', () => {
        // Every section arrives in one event, and most page loads carry no alerts at all.
        const { component } = build();

        component.receive({ cart: { summary_count: 3 } });

        assert.equal(component.open, false);
        assert.deepEqual(component.items, []);
    });

    it('survives a malformed section instead of throwing inside an event handler', () => {
        const { component } = build();

        component.receive({ [SECTION]: { items: 'nope' } });

        assert.deepEqual(component.items, []);
    });

    it('does not reopen after the customer has closed it', () => {
        // Section data is re-dispatched on every `reload-customer-section-data`, which anything on
        // the page can fire — a minicart update should not bring the popup back.
        const { component } = build();

        component.receive(sectionData([item()]));
        component.close();
        component.receive(sectionData([item()]));

        assert.equal(component.open, false);
    });

    it('seeds each card with the quantity the product actually sells', () => {
        const { component } = build();

        component.receive(sectionData([
            item({ alert_id: 1, qty: { min: 6, max: 0, increment: 2, decimal: false, start: 6 } }),
            item({ alert_id: 2 }),
        ]));

        assert.deepEqual(component.qty, { 1: 6, 2: 1 });
    });
});

describe('the quantity stepper', () => {
    it('steps by the product increment rather than by one', () => {
        const { component } = build();
        const product = item({ qty: { min: 2, max: 0, increment: 2, decimal: false, start: 2 } });

        component.receive(sectionData([product]));
        component.step(product, 1);

        assert.equal(component.qty[1], 4);
    });

    it('never goes below the minimum sale quantity', () => {
        const { component } = build();
        const product = item({ qty: { min: 6, max: 0, increment: 0, decimal: false, start: 6 } });

        component.receive(sectionData([product]));
        component.step(product, -1);

        assert.equal(component.qty[1], 6);
    });

    it('never goes above the maximum', () => {
        const { component } = build();
        const product = item({ qty: { min: 1, max: 3, increment: 0, decimal: false, start: 1 } });

        component.receive(sectionData([product]));
        component.step(product, 1);
        component.step(product, 1);
        component.step(product, 1);

        assert.equal(component.qty[1], 3);
    });

    it('rounds a typed value up onto the increment', () => {
        // The field is `:value` plus a change handler because `x-model` is unusable under a strict
        // CSP, so this handler is the only thing between a typed 5 and a cart that refuses it.
        const { component } = build();
        const product = item({ qty: { min: 2, max: 0, increment: 2, decimal: false, start: 2 } });

        component.receive(sectionData([product]));
        component.setQty(product, '5');

        assert.equal(component.qty[1], 6);
    });

    it('reads a cleared field as the minimum rather than as NaN', () => {
        const { component } = build();
        const product = item();

        component.receive(sectionData([product]));
        component.setQty(product, '');

        assert.equal(component.qty[1], 1);
    });

    it('keeps fractions for products sold by weight', () => {
        const { component } = build();
        const product = item({ qty: { min: 0.5, max: 0, increment: 0, decimal: true, start: 0.5 } });

        component.receive(sectionData([product]));
        component.setQty(product, '1.5');

        assert.equal(component.qty[1], 1.5);
    });
});

describe('dismissing', () => {
    it('closing dismisses everything, because the customer has now seen it', () => {
        const { component, calls } = build();

        component.receive(sectionData([item({ alert_id: 1 }), item({ alert_id: 2 })]));
        component.close();

        assert.equal(component.open, false);
        assert.deepEqual(calls, [{ url: '/dismiss', params: undefined }]);
    });

    it('closing an empty popup posts nothing', () => {
        const { component, calls } = build();

        component.close();

        assert.deepEqual(calls, []);
    });

    it('dismissing one card names it', async () => {
        const { component, calls } = build();

        component.receive(sectionData([item({ alert_id: 1 }), item({ alert_id: 2 })]));
        await component.dismiss(component.items[0]);

        assert.deepEqual(calls[0].params, { alert_ids: [1] });
        assert.equal(component.items.length, 1);
        assert.equal(component.open, true);
    });

    it('dismissing the last card closes the popup and releases focus', async () => {
        const { component, focus } = build();

        component.receive(sectionData([item({ alert_id: 1 })]));
        await component.dismiss(component.items[0]);

        assert.equal(component.open, false);
        assert.equal(focus.released, 1);
    });
});

describe('adding to the cart', () => {
    it('sends the alert id and the chosen quantity', async () => {
        const { component, calls } = build();
        const product = item({ alert_id: 5 });

        component.receive(sectionData([product]));
        component.step(product, 1);
        await component.addToCart(component.items[0]);

        assert.deepEqual(calls[0], { url: '/addtocart', params: { alert_ids: [5], qty: { 5: 2 } } });
    });

    it('refuses to post for a product the card cannot configure', async () => {
        const { component, calls } = build();

        component.receive(sectionData([item({ can_add_to_cart: false })]));
        await component.addToCart(component.items[0]);

        assert.deepEqual(calls, []);
    });

    it('leaves the card up when the server added nothing', async () => {
        // The restock sold out between the mail run and the click. The card's product-page link is
        // still a working path to the purchase, so taking it away helps nobody.
        const { component, reloaded } = build([{ success: true, added: 0, skipped: [] }]);

        component.receive(sectionData([item()]));
        await component.addToCart(component.items[0]);

        assert.equal(component.items.length, 1);
        assert.deepEqual(reloaded, []);
    });

    it('refreshes customer data after a successful add so the minicart agrees', async () => {
        const { component, reloaded } = build([{ success: true, added: 1, skipped: [] }]);

        component.receive(sectionData([item()]));
        await component.addToCart(component.items[0]);

        assert.deepEqual(reloaded, [EVENT_PRIVATE_CONTENT_RELOAD]);
        assert.equal(component.open, false);
    });

    it('will not fire twice while a request is in flight', async () => {
        const { component, calls } = build();

        component.receive(sectionData([item()]));
        const first = component.addToCart(component.items[0]);
        await component.addToCart(component.items[0]);
        await first;

        assert.equal(calls.length, 1);
    });

    it('bulk add keeps exactly the cards the server skipped', async () => {
        const { component } = build([{
            success: true,
            added: 2,
            skipped: [{ alert_id: 3, reason: 'requires_options' }],
        }]);

        component.receive(sectionData([
            item({ alert_id: 1 }),
            item({ alert_id: 2 }),
            item({ alert_id: 3, can_add_to_cart: false }),
        ]));
        await component.addAll();

        assert.deepEqual(component.items.map((entry) => entry.alert_id), [3]);
        assert.equal(component.open, true);
    });

    it('bulk add sends every card and its quantity', async () => {
        const { component, calls } = build();

        component.receive(sectionData([item({ alert_id: 1 }), item({ alert_id: 2 })]));
        await component.addAll();

        assert.deepEqual(calls[0].params, { alert_ids: [1, 2], qty: { 1: 1, 2: 1 } });
    });

    it('a failed bulk add changes nothing on screen', async () => {
        const { component, reloaded } = build([{ success: false, status: 403 }]);

        component.receive(sectionData([item({ alert_id: 1 }), item({ alert_id: 2 })]));
        await component.addAll();

        assert.equal(component.items.length, 2);
        assert.equal(component.open, true);
        assert.deepEqual(reloaded, []);
    });
});
