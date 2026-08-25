import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    BOUND_ATTRIBUTE,
    DEFAULT_ROOT_MARGIN,
    createLazyLoader,
    promoteImage,
} from 'scr1be-lazy-images/lazy-images.js';

/** A DOM thin enough to assert against, thick enough to exercise the promotion order. */
const makeImg = ({ src, srcsetJpg, picture = null } = {}) => {
    const img = {
        dataset: {},
        attributes: {},
        srcset: null,
        src: null,
        setAttribute(name, value) { this.attributes[name] = value; },
        hasAttribute(name) { return name in this.attributes; },
        closest: () => picture,
    };
    if (src) img.dataset.src = src;
    if (srcsetJpg) img.dataset.srcsetJpg = srcsetJpg;
    return img;
};

const makeSource = (srcset) => ({
    dataset: { srcset },
    srcset: null,
    removeAttribute(name) { if (name === 'data-srcset') delete this.dataset.srcset; },
});

const makePicture = (sources) => ({ querySelectorAll: () => sources });

const makeDoc = (images) => ({
    body: {},
    querySelectorAll: (selector) => (selector.includes(BOUND_ATTRIBUTE)
        ? images.filter((i) => !i.hasAttribute(BOUND_ATTRIBUTE))
        : images),
});

const makeWin = ({ withObservers = true } = {}) => {
    const observed = [];
    const unobserved = [];
    const mutation = { target: null, options: null, callback: null };
    const win = {};
    if (withObservers) {
        win.IntersectionObserver = class {
            constructor(cb, opts) { this.cb = cb; this.opts = opts; win.lastIo = this; }
            observe(el) { observed.push(el); }
            unobserve(el) { unobserved.push(el); }
        };
        win.MutationObserver = class {
            constructor(cb) { mutation.callback = cb; }
            observe(target, options) { mutation.target = target; mutation.options = options; }
        };
    }
    win.observed = observed;
    win.unobserved = unobserved;
    win.mutation = mutation;
    return win;
};

describe('promoteImage', () => {
    it('promotes the picture sources before the img, so the browser can still pick AVIF', () => {
        const order = [];
        const avif = makeSource('a.avif 1x');
        const webp = makeSource('a.webp 1x');
        Object.defineProperty(avif, 'srcset', { set(v) { order.push('source'); }, get() { return null; } });
        const img = makeImg({ src: 'a.jpg', srcsetJpg: 'a.jpg 1x', picture: makePicture([avif, webp]) });
        Object.defineProperty(img, 'src', { set(v) { order.push('img'); }, get() { return null; } });

        promoteImage(img);

        assert.equal(order[0], 'source', 'promoting the img first would let the browser commit to the JPEG');
        assert.equal(webp.srcset, 'a.webp 1x');
        assert.equal(webp.dataset.srcset, undefined, 'the data attribute is cleared so a re-scan skips it');
    });

    it('moves data-src and data-srcset-jpg onto the real attributes and clears them', () => {
        const img = makeImg({ src: 'a.jpg', srcsetJpg: 'a.jpg 1x, a2.jpg 2x' });
        promoteImage(img);

        assert.equal(img.src, 'a.jpg');
        assert.equal(img.srcset, 'a.jpg 1x, a2.jpg 2x');
        assert.equal(img.dataset.src, undefined);
        assert.equal(img.dataset.srcsetJpg, undefined);
    });

    it('handles an image with no picture and no jpg srcset', () => {
        const img = makeImg({ src: 'only.jpg' });
        assert.doesNotThrow(() => promoteImage(img));
        assert.equal(img.src, 'only.jpg');
    });
});

describe('createLazyLoader', () => {
    it('promotes everything immediately when IntersectionObserver is absent', () => {
        const images = [makeImg({ src: '1.jpg' }), makeImg({ src: '2.jpg' })];
        const result = createLazyLoader({ doc: makeDoc(images), win: makeWin({ withObservers: false }) }).start();

        assert.equal(result.mode, 'eager');
        assert.deepEqual(images.map((i) => i.src), ['1.jpg', '2.jpg'],
            'a browser without the observer is better served by images that load than by images that never do');
    });

    it('observes unbound images and marks them so a re-scan skips them', () => {
        const images = [makeImg({ src: '1.jpg' }), makeImg({ src: '2.jpg' })];
        const win = makeWin();
        const result = createLazyLoader({ doc: makeDoc(images), win }).start();

        assert.equal(result.mode, 'observed');
        assert.equal(win.observed.length, 2);
        assert.ok(images.every((i) => i.hasAttribute(BOUND_ATTRIBUTE)));
        assert.equal(win.lastIo.opts.rootMargin, DEFAULT_ROOT_MARGIN,
            'the margin is what starts the load before the image is on screen');
    });

    it('promotes and unobserves only the entries that actually intersect', () => {
        const a = makeImg({ src: 'a.jpg' });
        const b = makeImg({ src: 'b.jpg' });
        const win = makeWin();
        createLazyLoader({ doc: makeDoc([a, b]), win }).start();

        win.lastIo.cb([{ isIntersecting: false, target: a }, { isIntersecting: true, target: b }]);

        assert.equal(a.src, null, 'an entry that is not intersecting must be left alone');
        assert.equal(b.src, 'b.jpg');
        assert.deepEqual(win.unobserved, [b], 'a promoted image is released, an untouched one is not');
    });

    it('watches the document for images that arrive later', () => {
        const images = [makeImg({ src: '1.jpg' })];
        const doc = makeDoc(images);
        const win = makeWin();
        createLazyLoader({ doc, win }).start();

        assert.equal(win.mutation.target, doc.body);
        assert.deepEqual(win.mutation.options, { childList: true, subtree: true },
            'Hyvä swaps whole product grids, so a shallow watch would miss them');

        const late = makeImg({ src: 'late.jpg' });
        images.push(late);
        win.mutation.callback();

        assert.equal(win.observed.length, 2, 'the image that arrived after the first scan is now observed');
        assert.ok(late.hasAttribute(BOUND_ATTRIBUTE));
    });

    it('does not re-observe an image the first scan already bound', () => {
        const img = makeImg({ src: '1.jpg' });
        const doc = makeDoc([img]);
        const win = makeWin();
        createLazyLoader({ doc, win }).start();
        win.mutation.callback();

        assert.equal(win.observed.length, 1, 'a re-scan must not hand the same image to the observer twice');
    });
});
