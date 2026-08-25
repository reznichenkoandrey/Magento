/**
 * The quick-view store: fetches a product's rendered body and holds the modal's state.
 *
 * It used to be an object literal inside an inline `alpine:init` block, closing over a URL
 * constant and a translated string the template had interpolated into JavaScript. Both are
 * arguments now — the translation especially, because only PHP can translate, so the string
 * has to cross the boundary as data rather than as generated source.
 *
 * `document` is a parameter too. The store remembers what had focus before it opened and
 * restores it on close, which is the accessible behaviour and also the one thing here that a
 * spec cannot check without being handed a stand-in.
 */

/**
 * @param {object} config
 * @param {string} config.infoUrl Endpoint returning `{ title, html }` for a product id.
 * @param {string} config.errorTitle Already translated by PHP.
 * @param {Document} config.doc
 * @returns {object} The object handed to `Alpine.store()`.
 */
export const createQuickViewStore = ({ infoUrl, errorTitle, doc }) => ({
    open: false,
    loading: false,
    title: '',
    html: '',
    lastFocused: null,

    async show(productId) {
        // Captured before the modal opens, so focus can go back where the shopper left it.
        this.lastFocused = doc.activeElement;
        this.open = true;
        this.loading = true;
        this.html = '';

        try {
            const separator = infoUrl.includes('?') ? '&' : '?';
            const response = await fetch(`${infoUrl}${separator}id=${encodeURIComponent(productId)}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const payload = await response.json();
            this.title = payload.title;
            this.html = payload.html;
        } catch (error) {
            // The modal stays open and says so: closing it on failure would look like a
            // click that did nothing.
            this.title = errorTitle;
            this.html = `<p class="text-red-600">${error.message}</p>`;
        } finally {
            this.loading = false;
        }
    },

    close() {
        this.open = false;
        this.html = '';
        if (this.lastFocused) this.lastFocused.focus();
    },
});
