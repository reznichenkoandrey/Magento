/**
 * The compare list itself: an Alpine store factory with the browser handed to it.
 *
 * It used to be an object literal inside `alpine:init` inside a `<script>` tag, closing over
 * `localStorage` and `window` directly. That made every one of its rules — the cap, the
 * eviction order, the cross-tab sync, the malformed-storage recovery — reachable only by
 * driving a real browser. Storage and the event target are parameters now, so the same code
 * runs under `node --test`.
 */

/**
 * @param {object} options
 * @param {string} options.storageKey
 * @param {number} options.maxItems
 * @param {Storage} options.storage
 * @param {EventTarget} options.eventTarget Where the cross-tab `storage` event arrives.
 * @returns {object} The object handed to `Alpine.store()`.
 */
export const createCompareStore = ({ storageKey, maxItems, storage, eventTarget }) => {
    /**
     * Never throws. Private browsing can make `getItem` throw outright, and a half-written
     * value parses to nothing useful — in both cases an empty list is the honest answer and a
     * thrown error would take Alpine's whole init with it.
     */
    const read = () => {
        try {
            const raw = storage.getItem(storageKey);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    };

    const write = (items) => {
        try {
            storage.setItem(storageKey, JSON.stringify(items));
        } catch {
            // A full or blocked quota must not stop the drawer from updating on screen.
        }
    };

    return {
        items: read(),
        minimized: false,
        max: maxItems,

        init() {
            eventTarget.addEventListener('storage', (event) => {
                if (event.key !== storageKey) return;
                try {
                    const parsed = event.newValue ? JSON.parse(event.newValue) : [];
                    this.items = Array.isArray(parsed) ? parsed : [];
                } catch {
                    this.items = [];
                }
            });
        },

        has(productId) {
            return this.items.some((item) => item.id === productId);
        },

        /** At the cap the oldest entry is evicted, so the button always succeeds. */
        add(product) {
            if (this.has(product.id)) return;
            const next = this.items.length >= this.max ? this.items.slice(1) : [...this.items];
            this.items = [...next, product];
            write(this.items);
        },

        remove(productId) {
            this.items = this.items.filter((item) => item.id !== productId);
            write(this.items);
        },

        clear() {
            this.items = [];
            write(this.items);
        },

        reorder(fromIndex, toIndex) {
            const next = [...this.items];
            const [moved] = next.splice(fromIndex, 1);
            next.splice(toIndex, 0, moved);
            this.items = next;
            write(this.items);
        },

        get count() {
            return this.items.length;
        },

        get isVisible() {
            return this.items.length > 0;
        },
    };
};
