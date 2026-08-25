import { createLazyLoader } from './lazy-images.js';

/**
 * The seam: the only file that touches `window`.
 *
 * There is no Alpine component here and nothing to register with it — this loader simply
 * starts. A module script is deferred, so the document is parsed by the time it runs and
 * `doc.body` is there for the MutationObserver.
 */
export const start = (win, doc) => createLazyLoader({ win, doc }).start();

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    start(window, document);
}
