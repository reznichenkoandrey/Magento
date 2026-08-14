/**
 * The slider engine: a scroll-snap track driven by `scrollTo`.
 *
 * This module is bound to the bare specifier `scr1be-product-slider/engine.js` by the import map the
 * `SliderScripts` block writes, and that indirection is the point — the component never imports a
 * concrete engine. Anything exporting `createEngine` with the contract below can be swapped in by
 * rebinding the specifier in di.xml; the README documents the contract and why the module ships this
 * implementation rather than a third-party carousel.
 *
 * Contract:
 *
 *   createEngine(track, { loop }) -> {
 *     mount(onChange)   // start listening; call onChange(state) whenever the visible page changes
 *     destroy()         // remove every listener mount() added
 *     next() / prev()   // advance one page, honouring `loop`
 *     goTo(pageIndex)   // 0-based
 *     getState()        // { page, pages, perView, atStart, atEnd }
 *   }
 *
 * `track` is only ever touched through `clientWidth`, `scrollLeft`, `scrollTo`, `querySelectorAll`,
 * `addEventListener` and `removeEventListener` — which is what lets the engine be unit-tested against
 * a plain object instead of a browser.
 */

const SLIDE_SELECTOR = '[data-scr1be-slide]';

/**
 * A page is one viewport of the track, so `scrollLeft / clientWidth` is the page index. Rounding
 * rather than flooring means a track resting a pixel short of a snap point — which is where a smooth
 * scroll lands often enough to matter — reports the page the reader is actually looking at.
 */
const readPage = (track, pages) => {
    const width = track.clientWidth || 1;

    // `scrollLeft` is negative in a right-to-left document in browsers that follow the older spec,
    // and the magnitude is what carries the position in both.
    const offset = Math.abs(track.scrollLeft || 0);

    return Math.max(0, Math.min(pages - 1, Math.round(offset / width)));
};

const countSlides = (track) => track.querySelectorAll(SLIDE_SELECTOR).length;

/**
 * How many slides fit. Derived from the rendered width of the first slide rather than from the
 * configured breakpoint counts, because the CSS is the authority on that — the same slider inside a
 * narrow container shows fewer columns than the breakpoint alone would suggest, and the arrows have
 * to agree with what is on screen.
 */
const readPerView = (track) => {
    const slides = track.querySelectorAll(SLIDE_SELECTOR);

    if (slides.length === 0) {
        return 1;
    }

    const slideWidth = slides[0].getBoundingClientRect().width;

    if (!slideWidth || slideWidth <= 0) {
        return 1;
    }

    return Math.max(1, Math.round((track.clientWidth || slideWidth) / slideWidth));
};

const prefersReducedMotion = () =>
    typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export const createEngine = (track, options = {}) => {
    const loop = Boolean(options.loop);
    let onChange = null;
    let lastPage = -1;
    let resizeObserver = null;

    const countPages = () => Math.max(1, Math.ceil(countSlides(track) / readPerView(track)));

    const getState = () => {
        const pages = countPages();
        const page = readPage(track, pages);

        return {
            page,
            pages,
            perView: readPerView(track),
            // With looping on, both ends stay live: the arrows wrap instead of dimming.
            atStart: !loop && page === 0,
            atEnd: !loop && page >= pages - 1
        };
    };

    const notify = () => {
        const state = getState();

        if (state.page === lastPage || typeof onChange !== 'function') {
            lastPage = state.page;

            return;
        }

        lastPage = state.page;
        onChange(state);
    };

    const goTo = (pageIndex) => {
        const pages = countPages();
        const target = Math.max(0, Math.min(pages - 1, pageIndex));

        track.scrollTo({
            left: target * (track.clientWidth || 0),
            // Motion is an enhancement. A visitor who asked for less of it still gets the movement,
            // just without the animation.
            behavior: prefersReducedMotion() ? 'auto' : 'smooth'
        });
    };

    const step = (delta) => {
        const { page, pages } = getState();
        const target = page + delta;

        if (target < 0) {
            goTo(loop ? pages - 1 : 0);

            return;
        }

        if (target > pages - 1) {
            goTo(loop ? 0 : pages - 1);

            return;
        }

        goTo(target);
    };

    return {
        mount(callback) {
            onChange = callback;
            lastPage = getState().page;

            track.addEventListener('scroll', notify, { passive: true });

            // A resize changes how many slides fit, which changes the page count, which changes how
            // many dots there are. Without this the controls silently describe the previous layout.
            if (typeof ResizeObserver === 'function') {
                resizeObserver = new ResizeObserver(() => {
                    lastPage = -1;
                    notify();
                });
                resizeObserver.observe(track);
            }

            return getState();
        },

        destroy() {
            track.removeEventListener('scroll', notify);

            if (resizeObserver !== null) {
                resizeObserver.disconnect();
                resizeObserver = null;
            }

            onChange = null;
        },

        next() {
            step(1);
        },

        prev() {
            step(-1);
        },

        goTo,
        getState
    };
};
