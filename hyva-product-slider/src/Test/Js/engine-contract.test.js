import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { createEngine } from 'scr1be-product-slider/engine.js';

/**
 * The engine is written against six members of `track` and nothing else — `clientWidth`,
 * `scrollLeft`, `scrollTo`, `querySelectorAll`, `addEventListener`, `removeEventListener` — which is
 * what lets it be tested against this object instead of a browser. If a change to the engine breaks
 * these specs by needing a seventh member, that is the contract changing, and the README's
 * swap-in-your-own-engine section is what has to change with it.
 */
const createTrack = ({ slides = 9, slideWidth = 100, clientWidth = 300 } = {}) => {
    const listeners = new Map();

    const track = {
        clientWidth,
        scrollLeft: 0,
        scrollCalls: [],

        querySelectorAll: () =>
            Array.from({ length: slides }, () => ({ getBoundingClientRect: () => ({ width: slideWidth }) })),

        scrollTo(options) {
            track.scrollCalls.push(options);
            track.scrollLeft = options.left;
        },

        addEventListener(name, handler) {
            listeners.set(name, handler);
        },

        removeEventListener(name) {
            listeners.delete(name);
        },

        emit(name) {
            const handler = listeners.get(name);

            if (handler) {
                handler();
            }
        },

        listenerCount: () => listeners.size
    };

    return track;
};

describe('engine: measuring', () => {
    it('derives how many slides fit from what is rendered, not from configuration', () => {
        const engine = createEngine(createTrack({ slideWidth: 100, clientWidth: 300 }));

        assert.equal(engine.getState().perView, 3);
    });

    it('counts pages, not slides', () => {
        const engine = createEngine(createTrack({ slides: 9, slideWidth: 100, clientWidth: 300 }));

        assert.equal(engine.getState().pages, 3);
    });

    it('rounds a partial last page up, because those slides still have to be reachable', () => {
        const engine = createEngine(createTrack({ slides: 7, slideWidth: 100, clientWidth: 300 }));

        assert.equal(engine.getState().pages, 3);
    });

    it('survives a track measured before layout, when every width is zero', () => {
        const engine = createEngine(createTrack({ slideWidth: 0, clientWidth: 0 }));
        const state = engine.getState();

        assert.equal(state.perView, 1);
        assert.equal(state.page, 0);
        assert.ok(state.pages >= 1);
    });

    it('reports a page count of at least one for an empty track', () => {
        const engine = createEngine(createTrack({ slides: 0 }));

        assert.equal(engine.getState().pages, 1);
    });

    it('reads the current page from the scroll offset', () => {
        const track = createTrack();
        const engine = createEngine(track);

        track.scrollLeft = 300;

        assert.equal(engine.getState().page, 1);
    });

    it('rounds the offset, so a smooth scroll landing a pixel short still reports the right page', () => {
        const track = createTrack();
        const engine = createEngine(track);

        track.scrollLeft = 299;

        assert.equal(engine.getState().page, 1);
    });

    it('reads the magnitude of the offset, so a right-to-left track is not always on page zero', () => {
        const track = createTrack();
        const engine = createEngine(track);

        track.scrollLeft = -600;

        assert.equal(engine.getState().page, 2);
    });
});

describe('engine: stepping', () => {
    it('advances one viewport at a time', () => {
        const track = createTrack();
        const engine = createEngine(track);

        engine.next();

        assert.deepEqual(track.scrollCalls.at(-1), { left: 300, behavior: 'smooth' });
    });

    it('stops at the last page without looping', () => {
        const track = createTrack();
        const engine = createEngine(track);

        track.scrollLeft = 600;
        engine.next();

        assert.equal(track.scrollCalls.at(-1).left, 600);
    });

    it('wraps to the first page with looping on', () => {
        const track = createTrack();
        const engine = createEngine(track, { loop: true });

        track.scrollLeft = 600;
        engine.next();

        assert.equal(track.scrollCalls.at(-1).left, 0);
    });

    it('wraps backwards to the last page with looping on', () => {
        const track = createTrack();
        const engine = createEngine(track, { loop: true });

        engine.prev();

        assert.equal(track.scrollCalls.at(-1).left, 600);
    });

    it('clamps a page index nobody could have reached', () => {
        const track = createTrack();
        const engine = createEngine(track);

        engine.goTo(99);

        assert.equal(track.scrollCalls.at(-1).left, 600);
    });

    it('reports both ends only when looping is off', () => {
        const looping = createEngine(createTrack(), { loop: true });

        assert.equal(looping.getState().atStart, false);
        assert.equal(looping.getState().atEnd, false);

        const bounded = createEngine(createTrack());

        assert.equal(bounded.getState().atStart, true);
        assert.equal(bounded.getState().atEnd, false);
    });
});

describe('engine: lifecycle', () => {
    it('returns the initial state from mount, so the controls are correct before the first scroll', () => {
        const engine = createEngine(createTrack());
        const state = engine.mount(() => {});

        assert.equal(state.pages, 3);
        assert.equal(state.page, 0);
    });

    it('notifies only when the visible page actually changed', () => {
        const track = createTrack();
        const engine = createEngine(track);
        const seen = [];

        engine.mount((state) => seen.push(state.page));

        // A scroll inside the same page fires the event but changes nothing worth re-rendering.
        track.scrollLeft = 20;
        track.emit('scroll');
        assert.deepEqual(seen, []);

        track.scrollLeft = 300;
        track.emit('scroll');
        assert.deepEqual(seen, [1]);
    });

    it('removes every listener it added', () => {
        const track = createTrack();
        const engine = createEngine(track);

        engine.mount(() => {});
        assert.equal(track.listenerCount(), 1);

        engine.destroy();
        assert.equal(track.listenerCount(), 0);
    });

    it('stops notifying after destroy', () => {
        const track = createTrack();
        const engine = createEngine(track);
        const seen = [];

        engine.mount((state) => seen.push(state.page));
        engine.destroy();

        track.scrollLeft = 300;
        track.emit('scroll');

        assert.deepEqual(seen, []);
    });
});
