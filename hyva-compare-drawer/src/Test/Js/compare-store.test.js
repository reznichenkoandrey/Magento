import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { createCompareStore } from 'scr1be-compare-drawer/store.js';

const fakeStorage = (initial = {}) => {
    const data = { ...initial };
    return {
        data,
        getItem: (k) => (k in data ? data[k] : null),
        setItem: (k, v) => { data[k] = v; },
    };
};

const fakeTarget = () => {
    const handlers = [];
    return {
        handlers,
        addEventListener: (event, handler) => handlers.push({ event, handler }),
        emit: (event, payload) => handlers.filter((h) => h.event === event).forEach((h) => h.handler(payload)),
    };
};

const KEY = 'scr1be_compare_v1';
const build = (over = {}) => createCompareStore({
    storageKey: KEY,
    maxItems: 4,
    storage: fakeStorage(over.initial ?? {}),
    eventTarget: over.eventTarget ?? fakeTarget(),
});

const item = (id) => ({ id, name: `p${id}` });

describe('createCompareStore', () => {
    it('hydrates from storage on construction', () => {
        const store = createCompareStore({
            storageKey: KEY, maxItems: 4,
            storage: fakeStorage({ [KEY]: JSON.stringify([item(1)]) }),
            eventTarget: fakeTarget(),
        });
        assert.equal(store.count, 1);
        assert.equal(store.isVisible, true);
    });

    it('survives malformed or non-array storage rather than throwing during alpine:init', () => {
        for (const raw of ['{ not json', '"a string"', '42']) {
            const store = createCompareStore({
                storageKey: KEY, maxItems: 4,
                storage: fakeStorage({ [KEY]: raw }),
                eventTarget: fakeTarget(),
            });
            assert.deepEqual(store.items, [], `"${raw}" should degrade to an empty list`);
        }
    });

    it('keeps working when storage itself throws', () => {
        const hostile = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('quota'); } };
        const store = createCompareStore({ storageKey: KEY, maxItems: 4, storage: hostile, eventTarget: fakeTarget() });
        assert.deepEqual(store.items, []);
        assert.doesNotThrow(() => store.add(item(1)), 'a blocked quota must not stop the drawer updating on screen');
        assert.equal(store.count, 1);
    });

    it('refuses a duplicate instead of growing the list', () => {
        const store = build();
        store.add(item(1));
        store.add(item(1));
        assert.equal(store.count, 1);
    });

    it('evicts the oldest at the cap, so adding always succeeds', () => {
        const store = build();
        [1, 2, 3, 4].forEach((id) => store.add(item(id)));
        assert.equal(store.count, 4);
        store.add(item(5));
        assert.equal(store.count, 4, 'the cap holds');
        assert.deepEqual(store.items.map((i) => i.id), [2, 3, 4, 5], 'the first one added is the one that goes');
    });

    it('persists every mutation', () => {
        const storage = fakeStorage();
        const store = createCompareStore({ storageKey: KEY, maxItems: 4, storage, eventTarget: fakeTarget() });

        store.add(item(1));
        assert.deepEqual(JSON.parse(storage.data[KEY]).map((i) => i.id), [1]);

        store.add(item(2));
        store.reorder(0, 1);
        assert.deepEqual(JSON.parse(storage.data[KEY]).map((i) => i.id), [2, 1], 'a reorder is written, not just shown');

        store.remove(2);
        assert.deepEqual(JSON.parse(storage.data[KEY]).map((i) => i.id), [1]);

        store.clear();
        assert.deepEqual(JSON.parse(storage.data[KEY]), []);
    });

    it('has() answers for both membership and absence', () => {
        const store = build();
        store.add(item(7));
        assert.equal(store.has(7), true);
        assert.equal(store.has(8), false);
    });

    it('subscribes to storage and adopts the list another tab wrote', () => {
        const target = fakeTarget();
        const store = build({ eventTarget: target });
        store.init();

        assert.equal(target.handlers.length, 1);
        assert.equal(target.handlers[0].event, 'storage');

        target.emit('storage', { key: KEY, newValue: JSON.stringify([item(9)]) });
        assert.deepEqual(store.items.map((i) => i.id), [9]);

        target.emit('storage', { key: KEY, newValue: null });
        assert.deepEqual(store.items, [], 'another tab clearing the list clears this one');
    });

    it('ignores storage events for other keys, and malformed ones', () => {
        const target = fakeTarget();
        const store = build({ eventTarget: target });
        store.init();
        store.add(item(1));

        target.emit('storage', { key: 'something_else', newValue: JSON.stringify([item(9)]) });
        assert.deepEqual(store.items.map((i) => i.id), [1], 'an unrelated key must not touch this list');

        target.emit('storage', { key: KEY, newValue: '{ not json' });
        assert.deepEqual(store.items, [], 'a malformed cross-tab write degrades rather than throws');
    });

    it('isVisible tracks emptiness', () => {
        const store = build();
        assert.equal(store.isVisible, false);
        store.add(item(1));
        assert.equal(store.isVisible, true);
        store.clear();
        assert.equal(store.isVisible, false);
    });
});
