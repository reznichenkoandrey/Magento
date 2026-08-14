/**
 * The client is where the contract with PHP lives: `alert_ids[]` as a repeated field, `qty[<id>]` as
 * a map, `form_key` always, and a failed request as a value rather than a rejected promise.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { CONTENT_TYPE, createClient, encodeParams } from 'scr1be-back-in-stock/client.js';

const params = (body) => Object.fromEntries(new URLSearchParams(body).entries());

describe('encoding the body PHP expects', () => {
    it('sends a list as a repeated bracketed field', () => {
        // `JsonPostAction::readAlertIds()` reads `$this->request->getParam('alert_ids')` and expects
        // an array. `alert_ids=4,9` would arrive as one string.
        assert.equal(encodeParams({ alert_ids: [4, 9] }), 'alert_ids%5B%5D=4&alert_ids%5B%5D=9');
    });

    it('sends a map keyed the way AddToCart::resolveQty() reads it', () => {
        assert.equal(encodeParams({ qty: { 7: 2 } }), 'qty%5B7%5D=2');
    });

    it('sends scalars flat', () => {
        assert.equal(encodeParams({ form_key: 'abc' }), 'form_key=abc');
    });

    it('drops undefined and null instead of sending the words', () => {
        // `String(undefined)` is "undefined", and PHP would happily cast that to the integer 0.
        assert.equal(encodeParams({ a: undefined, b: null, c: 1 }), 'c=1');
    });

    it('encodes a value that would otherwise change the shape of the body', () => {
        assert.deepEqual(params(encodeParams({ a: 'x&b=y' })), { a: 'x&b=y' });
    });
});

describe('the request', () => {
    const windowWith = (fetchImpl, formKey = 'the-form-key') => ({
        hyva: { getFormKey: () => formKey },
        fetch: fetchImpl,
    });

    const okResponse = (body) => ({ ok: true, status: 200, json: async () => body });

    it('always carries the form key, because these endpoints check it themselves', async () => {
        let sent = null;
        const post = createClient(windowWith(async (url, options) => {
            sent = options;

            return okResponse({ success: true });
        }));

        await post('/dismiss', { alert_ids: [4] });

        assert.equal(sent.method, 'POST');
        assert.equal(sent.headers['Content-Type'], CONTENT_TYPE);
        assert.equal(sent.credentials, 'include');
        assert.equal(params(sent.body).form_key, 'the-form-key');
    });

    it('survives a page that loaded without Hyvä helpers', async () => {
        // A blank form key is refused by the controller with a 403, which is a message the customer
        // can act on. A TypeError on `hyva.getFormKey` is not.
        let sent = null;
        const post = createClient({
            fetch: async (url, options) => {
                sent = options;

                return okResponse({ success: true });
            },
        });

        await post('/dismiss');

        assert.equal(params(sent.body).form_key, '');
    });

    it('turns a network failure into a value', async () => {
        const post = createClient(windowWith(async () => {
            throw new TypeError('Failed to fetch');
        }));

        assert.deepEqual(await post('/dismiss'), { success: false, status: 0 });
    });

    it('reports an expired session as a failure rather than throwing', async () => {
        // The page came out of the full page cache; the session behind it did not survive.
        const post = createClient(windowWith(async () => ({
            ok: false,
            status: 401,
            json: async () => ({ success: false }),
        })));

        const result = await post('/dismiss');

        assert.equal(result.success, false);
        assert.equal(result.status, 401);
    });

    it('treats a body that is not JSON as a failure', async () => {
        const post = createClient(windowWith(async () => ({
            ok: true,
            status: 200,
            json: async () => {
                throw new SyntaxError('Unexpected token <');
            },
        })));

        assert.deepEqual(await post('/dismiss'), { success: false, status: 200 });
    });

    it('passes the controller payload through so the caller can read skipped items', async () => {
        const post = createClient(windowWith(async () => okResponse({
            success: true,
            added: 1,
            skipped: [{ alert_id: 9, reason: 'requires_options' }],
        })));

        const result = await post('/addtocart');

        assert.equal(result.added, 1);
        assert.deepEqual(result.skipped, [{ alert_id: 9, reason: 'requires_options' }]);
        assert.equal(result.success, true);
    });

    it('does not call a 200 with success:false a success', async () => {
        // The controllers answer 200 with `success: false` for "nothing changed", and a caller that
        // read only the HTTP status would remove the card anyway.
        const post = createClient(windowWith(async () => okResponse({ success: false })));

        assert.equal((await post('/dismiss')).success, false);
    });
});
