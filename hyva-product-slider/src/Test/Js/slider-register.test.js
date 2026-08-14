import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { ALPINE_INIT_EVENT, COMPONENT_NAME, listen, register } from 'scr1be-product-slider/register.js';
import { createSlider, readConfig } from 'scr1be-product-slider/slider.js';

/**
 * The seam, not the behaviour.
 *
 * Everything asserted here is a promise to something outside this module — the name `slider.phtml`
 * writes into `x-data`, the event Alpine dispatches, the attribute the PHP block writes the config
 * island under. Each of them breaks silently when renamed on one side only, which is why they are
 * pinned here rather than left to a browser to discover.
 */

const createAlpine = () => {
    const registered = new Map();

    return {
        registered,
        data(name, factory) {
            registered.set(name, factory);
        }
    };
};

const createTarget = () => {
    const listeners = [];

    return {
        listeners,
        Alpine: createAlpine(),
        addEventListener(name, handler, options) {
            listeners.push({ name, handler, options });
        },
        emit(name) {
            listeners.filter((entry) => entry.name === name).forEach((entry) => entry.handler());
        }
    };
};

describe('registration', () => {
    it('registers under the name the template puts in x-data', () => {
        // `slider.phtml` renders `x-data="scr1beSlider()"`. A rename here and not there produces a
        // page of static cards with no error anywhere.
        assert.equal(COMPONENT_NAME, 'scr1beSlider');

        const alpine = createAlpine();
        register(alpine);

        assert.ok(alpine.registered.has('scr1beSlider'));
    });

    it('registers a factory Alpine can call, not an object', () => {
        const alpine = createAlpine();
        register(alpine);

        const component = alpine.registered.get(COMPONENT_NAME)();

        assert.equal(typeof component.init, 'function');
        assert.equal(typeof component.destroy, 'function');
    });

    it('waits for the event Alpine actually dispatches', () => {
        assert.equal(ALPINE_INIT_EVENT, 'alpine:init');

        const target = createTarget();
        listen(target);

        assert.equal(target.listeners[0].name, 'alpine:init');
    });

    it('listens once, because registering twice replaces every slider already on the page', () => {
        const target = createTarget();
        listen(target);

        assert.deepEqual(target.listeners[0].options, { once: true });
    });

    it('registers when the event fires, not before', () => {
        const target = createTarget();
        listen(target);

        assert.equal(target.Alpine.registered.size, 0);

        target.emit(ALPINE_INIT_EVENT);

        assert.equal(target.Alpine.registered.size, 1);
    });

    it('does nothing rather than throwing when Alpine is absent', () => {
        assert.equal(register(undefined), false);
        assert.equal(register({}), false);
    });
});

describe('the config island', () => {
    const island = (json) => ({
        querySelector: (selector) =>
            selector === '[data-scr1be-slider-config]' ? { textContent: json } : null
    });

    it('reads the attribute the PHP template writes', () => {
        // `Block\Slider` renders `<script type="application/json" data-scr1be-slider-config>`.
        const config = readConfig(island('{"identifier":"home-new","autoplay":true}'));

        assert.equal(config.identifier, 'home-new');
        assert.equal(config.autoplay, true);
    });

    it('fills in every key the component reads, so a partial payload cannot produce undefined', () => {
        const config = readConfig(island('{"identifier":"home-new"}'));

        assert.deepEqual(config.productIds, []);
        assert.equal(config.autoplayDelay, 5000);
        assert.equal(config.loop, false);
        assert.equal(config.socialProof, false);
        assert.equal(config.proofUrl, '');
    });

    it('falls back to defaults when the island is malformed', () => {
        // A broken slider, not a broken page: the cards are already rendered and stay usable.
        const config = readConfig(island('{ not json'));

        assert.equal(config.autoplay, false);
        assert.deepEqual(config.productIds, []);
    });

    it('falls back to defaults when there is no island at all', () => {
        assert.equal(readConfig({ querySelector: () => null }).identifier, '');
        assert.equal(readConfig(null).identifier, '');
    });
});

describe('component state', () => {
    const component = () =>
        createSlider({
            createEngine: () => ({ mount: () => null, destroy: () => {}, next: () => {}, prev: () => {}, goTo: () => {} }),
            fetchProofs: async () => ({}),
            applyProofs: () => 0
        })();

    it('derives dots, arrows and the active page from one engine state', () => {
        const slider = component();

        slider.applyState({ page: 1, pages: 3, perView: 3, atStart: false, atEnd: false });

        assert.deepEqual(slider.pages, [1, 2, 3]);
        assert.equal(slider.activePage, 2);
        assert.equal(slider.hasControls, true);
        assert.equal(slider.prevDisabled, false);
        assert.equal(slider.nextDisabled, false);
    });

    it('hides the controls for a slider that cannot scroll', () => {
        const slider = component();

        slider.applyState({ page: 0, pages: 1, perView: 5, atStart: true, atEnd: true });

        assert.equal(slider.hasControls, false);
    });

    it('marks exactly one dot active', () => {
        const slider = component();

        slider.applyState({ page: 2, pages: 3, perView: 3, atStart: false, atEnd: true });

        assert.deepEqual(slider.pages.map((page) => slider.isActive(page)), [false, false, true]);
    });

    it('labels a dot with its position, for the screen reader that cannot see the row', () => {
        const slider = component();

        slider.applyState({ page: 0, pages: 4, perView: 2, atStart: true, atEnd: false });

        assert.equal(slider.pageLabel(2), '2 / 4');
    });

    it('translates a one-based dot into the zero-based page the engine speaks', () => {
        const jumps = [];
        const slider = createSlider({
            createEngine: () => ({ mount: () => null, destroy: () => {}, goTo: (page) => jumps.push(page) }),
            fetchProofs: async () => ({}),
            applyProofs: () => 0
        })();

        slider.engine = { goTo: (page) => jumps.push(page) };
        slider.goTo(3);

        assert.deepEqual(jumps, [2]);
    });
});
