/**
 * The Alpine component behind the popup.
 *
 * It holds no product data of its own: everything it renders arrives in the
 * `private-content-loaded` event, which Hyvä's `Hyva_Theme::page/js/private-content.phtml`
 * dispatches with the whole customer-data payload in `detail.data`. That is what makes the popup
 * safe on a full-page-cached document — the HTML is the same for everybody, and the cards are
 * per-customer JSON that never touches the cache.
 *
 * Nothing in here reaches for `window`. The three things it needs from the page — refreshing
 * customer data, trapping focus, releasing it — arrive as a small bridge object, which is what lets
 * the whole component be driven from `node --test` with no DOM at all.
 */

/** The customer-data section this component reads. Must match `etc/frontend/di.xml`. */
export const SECTION = 'scr1be-back-in-stock';

/** Hyvä's own event name, read from its private-content bootstrap rather than invented here. */
export const EVENT_PRIVATE_CONTENT_RELOAD = 'reload-customer-section-data';

/**
 * @param {{endpoints: {dismiss: string, addToCart: string}}} config
 * @param {function(string, Object=): Promise<Object>} post
 * @param {{reload: function(string): void, trapFocus: function(*): void, releaseFocus: function(*): void}} bridge
 * @returns {function(): Object} An Alpine component factory.
 */
export const popupComponent = (config, post, bridge) => function () {
    return {
        items: [],
        qty: {},
        open: false,
        busy: false,
        /**
         * Set the first time the customer closes the popup or empties it. Section data is
         * re-dispatched on every `reload-customer-section-data` and on every page load out of local
         * storage, so without this the popup would reappear the moment anything else on the page
         * refreshed the minicart.
         */
        acknowledged: false,

        receive(data) {
            const section = data && data[SECTION];
            const items = section && Array.isArray(section.items) ? section.items : [];

            this.items = items;
            this.qty = items.reduce((carry, item) => {
                carry[item.alert_id] = this.startQty(item);

                return carry;
            }, {});
            this.open = items.length > 0 && !this.acknowledged;
        },

        startQty(item) {
            const rules = item.qty || {};

            return rules.start > 0 ? rules.start : 1;
        },

        /**
         * The quantity stepper, held to the same rules the cart will enforce: never below the
         * product's minimum, never off its increment, never above its ceiling. Getting this wrong
         * client-side corrupts nothing — `AddToCart::resolveQty()` floors it again server-side — but
         * it does leave a customer looking at a number they cannot buy.
         */
        step(item, direction) {
            this.setQty(item, (this.qty[item.alert_id] || this.startQty(item)) + (direction * this.incrementOf(item)));
        },

        /**
         * Also the handler for the number field. Alpine's `x-model` is unavailable under a strict
         * CSP — Hyvä says so in its own helper file, next to the `safeParseNumber()` it ships as the
         * replacement — so the field is `:value` plus a change handler, and this is the handler.
         */
        setQty(item, rawValue) {
            const rules = item.qty || {};
            const floor = this.startQty(item);
            const parsed = parseFloat(rawValue);
            let next = Number.isFinite(parsed) ? parsed : floor;

            if (next < floor) {
                next = floor;
            }

            if (rules.max > 0 && next > rules.max) {
                next = rules.max;
            }

            const increment = this.incrementOf(item);

            if (rules.increment > 0) {
                next = Math.ceil(next / increment) * increment;
            }

            this.qty[item.alert_id] = rules.decimal ? next : Math.round(next);
        },

        incrementOf(item) {
            const rules = item.qty || {};

            return rules.increment > 0 ? rules.increment : 1;
        },

        trap(element) {
            bridge.trapFocus(element);
        },

        /**
         * Closing is a dismissal of everything in the popup, because the customer has now seen it.
         * The request is fired but not waited on: the popup should close at the speed of the click,
         * and the worst case if it fails is the same cards on the next page.
         */
        close() {
            const hadItems = this.items.length > 0;

            this.acknowledged = true;
            this.open = false;
            bridge.releaseFocus();

            if (hadItems) {
                post(config.endpoints.dismiss);
            }
        },

        async dismiss(item) {
            this.items = this.items.filter((candidate) => candidate.alert_id !== item.alert_id);

            if (this.items.length === 0) {
                this.acknowledged = true;
                this.open = false;
                bridge.releaseFocus();
            }

            await post(config.endpoints.dismiss, { alert_ids: [item.alert_id] });
        },

        async addToCart(item) {
            if (this.busy || !item.can_add_to_cart) {
                return;
            }

            this.busy = true;

            const result = await post(config.endpoints.addToCart, {
                alert_ids: [item.alert_id],
                qty: { [item.alert_id]: this.qty[item.alert_id] || this.startQty(item) },
            });

            this.busy = false;

            if (!result.success || result.added === 0) {
                // The card stays where it is. Something the popup's data was too old to know about —
                // the product sold out again between the mail run and this click — and leaving the
                // card up is what lets the customer follow its link to the product page.
                return;
            }

            this.items = this.items.filter((candidate) => candidate.alert_id !== item.alert_id);
            this.settle();
        },

        async addAll() {
            if (this.busy || this.items.length === 0) {
                return;
            }

            this.busy = true;

            const result = await post(config.endpoints.addToCart, {
                alert_ids: this.items.map((item) => item.alert_id),
                qty: this.items.reduce((carry, item) => {
                    carry[item.alert_id] = this.qty[item.alert_id] || this.startQty(item);

                    return carry;
                }, {}),
            });

            this.busy = false;

            if (!result.success) {
                return;
            }

            // Composites and anything that sold out again come back as `skipped`, and those cards
            // stay on screen with their product-page links. Everything else is in the cart.
            const skipped = Array.isArray(result.skipped) ? result.skipped.map((entry) => entry.alert_id) : [];

            this.items = this.items.filter((item) => skipped.includes(item.alert_id));
            this.settle();
        },

        /**
         * After a successful add: close if nothing is left, and refresh customer data either way so
         * the minicart counter and this section agree with the quote that was just saved.
         */
        settle() {
            this.open = this.items.length > 0;
            this.acknowledged = this.items.length === 0;

            if (!this.open) {
                bridge.releaseFocus();
            }

            bridge.reload(EVENT_PRIVATE_CONTENT_RELOAD);
        },
    };
};
