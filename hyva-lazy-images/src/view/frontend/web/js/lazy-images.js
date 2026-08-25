/**
 * The `data-src` fallback loader, with the browser handed to it.
 *
 * `loading="lazy"` covers modern browsers; this exists for the ones that lack it. It used to
 * be an IIFE in an inline `<script>`, closing over `window` and `document`, which made all of
 * it — the no-IntersectionObserver path, the promotion order inside a `<picture>`, the
 * re-scan after Hyvä swaps a product grid — unreachable from a spec.
 */

/** Start loading before the image is on screen, rather than as it arrives. */
export const DEFAULT_ROOT_MARGIN = '200px 0px';

export const DEFAULT_THRESHOLD = 0.01;

/** Marks an image as already handed to the observer, so a re-scan does not re-observe it. */
export const BOUND_ATTRIBUTE = 'data-lazy-bound';

/**
 * Moves the deferred urls into the attributes the browser acts on.
 *
 * The `<source>` elements go first, deliberately: setting the `<img>` src while its sibling
 * sources still carry no srcset lets the browser commit to the JPEG before the AVIF and WebP
 * candidates exist, which is the whole saving thrown away.
 *
 * @param {Element} img
 */
export const promoteImage = (img) => {
    const picture = img.closest('picture');
    if (picture) {
        picture.querySelectorAll('source[data-srcset]').forEach((source) => {
            source.srcset = source.dataset.srcset;
            source.removeAttribute('data-srcset');
        });
    }

    if (img.dataset.srcsetJpg) {
        img.srcset = img.dataset.srcsetJpg;
        delete img.dataset.srcsetJpg;
    }

    if (img.dataset.src) {
        img.src = img.dataset.src;
        delete img.dataset.src;
    }
};

/**
 * @param {object} options
 * @param {Document} options.doc
 * @param {Window} options.win
 * @param {string} [options.rootMargin]
 * @param {number} [options.threshold]
 * @returns {{start: function(): object}}
 */
export const createLazyLoader = ({ doc, win, rootMargin = DEFAULT_ROOT_MARGIN, threshold = DEFAULT_THRESHOLD }) => ({
    /**
     * @returns {{mode: string, observer: ?object, mutationObserver: ?object}} What it set up,
     *          so a caller — or a spec — can see which path was taken.
     */
    start() {
        // No IntersectionObserver at all: promote everything now. A browser that old is
        // better served by images that load than by images that never do.
        if (!('IntersectionObserver' in win)) {
            doc.querySelectorAll('img[data-src]').forEach((img) => promoteImage(img));
            return { mode: 'eager', observer: null, mutationObserver: null };
        }

        const observer = new win.IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                promoteImage(entry.target);
                observer.unobserve(entry.target);
            }
        }, { rootMargin, threshold });

        const observe = () => {
            doc.querySelectorAll(`img[data-src]:not([${BOUND_ATTRIBUTE}])`).forEach((img) => {
                img.setAttribute(BOUND_ATTRIBUTE, '1');
                observer.observe(img);
            });
        };

        observe();

        // Hyvä swaps product-list HTML in place, so images can arrive after this ran.
        const mutationObserver = new win.MutationObserver(observe);
        mutationObserver.observe(doc.body, { childList: true, subtree: true });

        return { mode: 'observed', observer, mutationObserver };
    },
});
