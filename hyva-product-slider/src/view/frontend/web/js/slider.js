/**
 * The Alpine component.
 *
 * It owns three things and delegates everything else: the state the template binds to, the autoplay
 * timer, and the one-shot proof fetch. Scrolling belongs to the engine, wording belongs to PHP, and
 * card markup belongs to Hyvä.
 *
 * Every value the template binds is a plain property or a method reference — `prevDisabled`,
 * `@click="next"`, `:data-active="isActive(page)"` — rather than an expression evaluated in the
 * attribute. Alpine evaluates those expressions with the CSP-relevant machinery, and a component that
 * keeps its logic in JavaScript is a component that keeps working when a storefront tightens
 * `script-src`.
 */

const CONFIG_SELECTOR = '[data-scr1be-slider-config]';

const DEFAULT_CONFIG = {
    identifier: '',
    autoplay: false,
    autoplayDelay: 5000,
    loop: false,
    socialProof: false,
    proofUrl: '',
    productIds: []
};

/**
 * The config travels in an `application/json` island inside the slider rather than in a data
 * attribute, because product ids are an array and an attribute would be a second, hand-rolled
 * encoding of one.
 */
export const readConfig = (root) => {
    const island = root ? root.querySelector(CONFIG_SELECTOR) : null;

    if (!island) {
        return { ...DEFAULT_CONFIG };
    }

    try {
        return { ...DEFAULT_CONFIG, ...JSON.parse(island.textContent) };
    } catch (error) {
        // A malformed island means a broken slider, not a broken page: the cards are already
        // rendered and stay usable without controls.
        return { ...DEFAULT_CONFIG };
    }
};

export const createSlider = ({ createEngine, fetchProofs, applyProofs }) => () => ({
    pages: [],
    activePage: 1,
    hasControls: false,
    prevDisabled: true,
    nextDisabled: true,

    config: { ...DEFAULT_CONFIG },
    engine: null,
    timer: null,
    paused: false,

    init() {
        this.config = readConfig(this.$root);
        this.engine = createEngine(this.$refs.track, { loop: this.config.loop });

        this.applyState(this.engine.mount((state) => this.applyState(state)));

        if (this.config.autoplay) {
            this.startAutoplay();
        }

        if (this.config.socialProof) {
            this.loadProofs();
        }
    },

    /**
     * Alpine calls this when the component's element leaves the DOM. Without it, a slider inside a
     * region that gets re-rendered leaves an interval and a ResizeObserver behind, and the page gets
     * slower every time it is redrawn.
     */
    destroy() {
        this.stopAutoplay();

        if (this.engine !== null) {
            this.engine.destroy();
            this.engine = null;
        }
    },

    /**
     * The single place component state is derived from engine state, so the dots, the arrows and the
     * aria attributes cannot describe three different moments.
     */
    applyState(state) {
        if (!state) {
            return;
        }

        this.pages = Array.from({ length: state.pages }, (unused, index) => index + 1);
        this.activePage = state.page + 1;
        this.hasControls = state.pages > 1;
        this.prevDisabled = state.atStart;
        this.nextDisabled = state.atEnd;
    },

    next() {
        this.engine.next();
    },

    prev() {
        this.engine.prev();
    },

    goTo(page) {
        this.engine.goTo(page - 1);
    },

    isActive(page) {
        return page === this.activePage;
    },

    pageLabel(page) {
        return `${page} / ${this.pages.length}`;
    },

    pause() {
        this.paused = true;
    },

    resume() {
        this.paused = false;
    },

    /**
     * Autoplay never starts for a visitor who asked for reduced motion — not "starts and animates
     * less". Automatic movement is the thing the setting is about.
     */
    startAutoplay() {
        if (typeof window === 'undefined' || typeof window.setInterval !== 'function') {
            return;
        }

        if (typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        this.timer = window.setInterval(() => {
            // A background tab still fires intervals, and advancing a carousel nobody is looking at
            // spends layout work and, with looping off, leaves it parked at the end.
            if (this.paused || (typeof document !== 'undefined' && document.hidden)) {
                return;
            }

            this.engine.next();
        }, this.config.autoplayDelay);
    },

    stopAutoplay() {
        if (this.timer !== null && typeof window !== 'undefined') {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    },

    async loadProofs() {
        const items = await fetchProofs(this.config.proofUrl, this.config.productIds);

        applyProofs(this.$root, items);
    }
});
