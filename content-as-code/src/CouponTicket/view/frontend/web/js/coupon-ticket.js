/**
 * Copy-to-clipboard for the coupon ticket widget.
 *
 * One file, on purpose. The component is small enough that splitting it would buy nothing but an
 * import map, and an import map has to be installed in the document head before any module loads —
 * which a widget rendered mid-page cannot promise. A single module script referenced from the
 * template needs no map at all.
 *
 * The bottom of the file registers with Alpine when a window exists, so `<script type="module">` in
 * the template is the whole wiring. The guard is what lets `node --test` import the same file.
 */

/** The name both templates put in `x-data`. Renaming one without the other is the silent break. */
export const COMPONENT_NAME = 'scr1beCouponTicket';

/**
 * The clipboard seam.
 *
 * `navigator.clipboard` is undefined outside a secure context, and `writeText` rejects when the
 * document is not focused or permission is denied. Both are normal conditions rather than bugs, so
 * they are reported as a failed copy — the template shows the code as `select-all` text either way,
 * which is what the failure message points at.
 *
 * @param {Window} win
 * @returns {{write: function(string): Promise<void>}}
 */
export const createClipboard = (win) => ({
    write: (text) => {
        const clipboard = win.navigator && win.navigator.clipboard;

        if (!clipboard || typeof clipboard.writeText !== 'function') {
            return Promise.reject(new Error('Clipboard API unavailable'));
        }

        return clipboard.writeText(text);
    },
});

/**
 * @param {{write: function(string): Promise<void>}} clipboard
 * @param {{setTimeout: function(Function, number): *, clearTimeout: function(*): void}} timers
 * @returns {function(string, number): Object} Alpine component factory.
 */
export const couponTicketComponent = (clipboard, timers) => (code, feedbackMs) => ({
    copied: false,
    failed: false,
    resetTimer: null,

    async copy() {
        // Clearing first: a second click while the confirmation is up would otherwise inherit the
        // first click's timer and blank the confirmation early.
        this.cancelReset();

        try {
            await clipboard.write(code);
            this.copied = true;
            this.failed = false;
        } catch (error) {
            this.copied = false;
            this.failed = true;
        }

        this.resetTimer = timers.setTimeout(() => {
            this.copied = false;
            this.failed = false;
            this.resetTimer = null;
        }, feedbackMs);
    },

    cancelReset() {
        if (this.resetTimer !== null) {
            timers.clearTimeout(this.resetTimer);
            this.resetTimer = null;
        }
    },

    /**
     * Alpine calls this when the element leaves the DOM. Without it, a timer fired after teardown
     * writes to a component nothing is watching — harmless today, and exactly the kind of thing
     * that stops being harmless when the widget ends up inside a modal.
     */
    destroy() {
        this.cancelReset();
    },
});

/**
 * @param {Window} win
 * @returns {void}
 */
export const register = (win) => {
    win.addEventListener(
        'alpine:init',
        () => {
            win.Alpine.data(COMPONENT_NAME, couponTicketComponent(createClipboard(win), win));
        },
        { once: true }
    );
};

if (typeof window !== 'undefined') {
    register(window);
}
