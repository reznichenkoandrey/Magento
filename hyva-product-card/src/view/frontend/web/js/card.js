import {
    applyStockUpdate,
    clampQty,
    nextQty,
    previousQty,
    toGa4Item,
    toStockRequestIds,
} from './card-data.js';

/**
 * The Alpine component behind one server-rendered card.
 *
 * Everything it decides, it delegates to `card-data.js`. What is left here is the part that can
 * only be done in a browser: reading the card's own JSON island, swapping an image source, posting
 * a form without leaving the page, and draining the flash messages that post left behind.
 *
 * Strictly CSP-safe by construction — every binding in the template is a dot path
 * (`x-text="stockLabel"`, `:disabled="isBusy"`) or a bare method reference (`@click="increment"`).
 * No template attribute contains an expression this file could not have named.
 */

/** Cards on the same page batch their stock lookups into one request within this window. */
const STOCK_BATCH_DELAY_MS = 50;

/** Names of the browser events this module speaks, in one place so nothing invents a variant. */
export const EVENT_GA4 = 'scr1be-card:ga4';
export const EVENT_MESSAGES_LOADED = 'messages-loaded';
export const EVENT_PRIVATE_CONTENT_RELOAD = 'reload-customer-section-data';

const stockBatch = {
    ids: new Set(),
    subscribers: [],
    timer: null,
};

/**
 * Collects the ids every card on the page asked about and resolves them in one request.
 *
 * A card that fetched its own status would turn a 24-card grid into 24 requests to an endpoint
 * whose entire reason for existing is that it is cheap. The batch window is short enough to close
 * inside the same paint.
 *
 * @param {string} endpoint
 * @param {number} productId
 * @returns {Promise<object>} The `items` map for the whole batch.
 */
const requestStock = (endpoint, productId) => new Promise((resolve, reject) => {
    stockBatch.ids.add(productId);
    stockBatch.subscribers.push({ resolve, reject });

    if (stockBatch.timer !== null) {
        return;
    }

    stockBatch.timer = setTimeout(() => {
        const ids = toStockRequestIds([...stockBatch.ids].map((id) => ({ id })));
        const subscribers = stockBatch.subscribers;

        stockBatch.ids = new Set();
        stockBatch.subscribers = [];
        stockBatch.timer = null;

        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set('ids', ids.join(','));

        fetch(url.toString(), { method: 'GET', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((payload) => subscribers.forEach(({ resolve: done }) => done(payload.items || {})))
            .catch((error) => subscribers.forEach(({ reject: fail }) => fail(error)));
    }, STOCK_BATCH_DELAY_MS);
});

/**
 * @param {object} config `{endpoints: {stock, drain}, ga4: boolean}` as the scripts template writes it.
 * @returns {object} An Alpine component definition.
 */
export const productCardComponent = (config) => function () {
    return {
        card: null,
        qty: 1,
        stockLabel: '',
        isBusy: false,

        init() {
            this.card = this.readCard();
            if (!this.card) {
                return;
            }

            this.qty = this.card.qty_rules.min;
            this.stockLabel = this.card.stock.label;
            this.refreshStock();
        },

        /**
         * The card's state travels in an `application/json` element inside the card, not in an
         * `x-data` object literal. Two reasons, and both matter: an object literal in an attribute
         * is a computed expression under a strict CSP, and the payload is the same array the
         * GraphQL resolvers return — one shape, two transports.
         */
        readCard() {
            const island = this.$el.querySelector('[data-scr1be-card]');
            if (!island) {
                return null;
            }
            try {
                return JSON.parse(island.textContent);
            } catch (error) {
                return null;
            }
        },

        increment() {
            this.qty = nextQty(this.qty, this.card.qty_rules);
        },

        decrement() {
            this.qty = previousQty(this.qty, this.card.qty_rules);
        },

        clampQty() {
            this.qty = clampQty(this.qty, this.card.qty_rules);
        },

        swapToHoverImage() {
            const image = this.$refs.primaryImage;
            if (!image || !image.dataset.hoverSrc) {
                return;
            }
            if (!image.dataset.primarySrc) {
                image.dataset.primarySrc = image.getAttribute('src');
                image.dataset.primarySrcset = image.getAttribute('srcset') || '';
            }
            // The hover file has no ladder of its own, so the srcset has to go with it — leaving a
            // stale srcset in place would let the browser keep choosing a primary-image rung.
            image.removeAttribute('srcset');
            image.setAttribute('src', image.dataset.hoverSrc);
        },

        swapToPrimaryImage() {
            const image = this.$refs.primaryImage;
            if (!image || !image.dataset.primarySrc) {
                return;
            }
            image.setAttribute('src', image.dataset.primarySrc);
            if (image.dataset.primarySrcset) {
                image.setAttribute('srcset', image.dataset.primarySrcset);
            }
        },

        refreshStock() {
            if (!config.endpoints || !config.endpoints.stock) {
                return;
            }

            requestStock(config.endpoints.stock, this.card.id)
                .then((items) => {
                    const { changed, stock } = applyStockUpdate(this.card, items);
                    if (changed) {
                        this.card.stock = stock;
                        this.stockLabel = stock.label;
                    }
                })
                // A stale label is a much smaller problem than a card that erases its own
                // availability because a CDN hiccupped.
                .catch(() => undefined);
        },

        addToCart(event) {
            const form = event.target;
            if (this.isBusy) {
                return;
            }
            this.isBusy = true;

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                // Not decoration. `Checkout\Controller\Cart\Add::goBack()` branches on
                // `getRequest()->isAjax()`, which is true for this header, and answers with a small
                // JSON body instead of the redirect a form post would get. Without the header the
                // browser would follow a 302 and download a whole page to throw it away.
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(() => this.drainMessages())
                .then(() => {
                    window.dispatchEvent(new CustomEvent(EVENT_PRIVATE_CONTENT_RELOAD));
                    this.trackAddToCart();
                })
                .finally(() => {
                    this.isBusy = false;
                });
        },

        /**
         * Takes the flash messages the add-to-cart controller left in the session and shows them,
         * instead of letting them ambush the shopper on whatever page they open next.
         *
         * `messages-loaded` is Hyvä's own event: its `Magento_Theme::messages.phtml` binds
         * `@messages-loaded.window` and reads `event.detail.messages`.
         */
        drainMessages() {
            if (!config.endpoints || !config.endpoints.drain) {
                return Promise.resolve();
            }

            const body = new FormData();
            body.append('form_key', window.hyva ? window.hyva.getFormKey() : '');

            return fetch(config.endpoints.drain, {
                method: 'POST',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((response) => (response.ok ? response.json() : { messages: [] }))
                .then((payload) => {
                    if (payload.messages && payload.messages.length) {
                        window.dispatchEvent(new CustomEvent(EVENT_MESSAGES_LOADED, {
                            detail: { messages: payload.messages },
                        }));
                    }
                })
                .catch(() => undefined);
        },

        trackSelectItem() {
            this.dispatchGa4('select_item', { items: [toGa4Item(this.card, this.domIndex())] });
        },

        trackAddToCart() {
            this.dispatchGa4('add_to_cart', {
                items: [{ ...toGa4Item(this.card, this.domIndex()), quantity: Number(this.qty) }],
            });
        },

        /**
         * A Hyvä listing renders one card at a time and never tells the card where it sits, so the
         * server payload carries index 0 for every card. The DOM knows the answer.
         */
        domIndex() {
            const cards = [...document.querySelectorAll('[data-product-id]')];

            return Math.max(0, cards.indexOf(this.$el));
        },

        dispatchGa4(name, payload) {
            if (!config.ga4) {
                return;
            }

            const detail = { event: name, ...payload };
            window.dispatchEvent(new CustomEvent(EVENT_GA4, { detail }));

            if (Array.isArray(window.dataLayer)) {
                window.dataLayer.push(detail);
            }
        },
    };
};
