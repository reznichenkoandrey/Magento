import { applyStockUpdate, toGa4Item, toGa4ViewItemList, toStockRequestIds } from './card-data.js';
import { EVENT_GA4 } from './card.js';

/**
 * The Alpine component behind the client-rendered grid.
 *
 * It renders the same {@see CardData} the server template renders, from the same JSON. What it does
 * *not* do is re-derive any of it: there is no badge rule, no price comparison and no stock
 * threshold in this file, because every one of those already exists on the server and a second copy
 * would be a second answer.
 */

/**
 * @param {object} defaults Fallback endpoint config, used when the payload carries none.
 * @returns {object} An Alpine component definition.
 */
export const cardGridComponent = (defaults) => function () {
    return {
        cards: [],
        toolbar: { sort: '', direction: 'asc' },
        filterLabels: {},
        endpoints: defaults.endpoints || {},
        ga4: Boolean(defaults.ga4),

        hydrate() {
            const payload = this.readPayload();
            if (!payload) {
                return;
            }

            this.cards = payload.cards || [];
            this.toolbar = payload.toolbar || this.toolbar;
            this.filterLabels = payload.filter_labels || {};
            this.endpoints = payload.endpoints || this.endpoints;
            this.ga4 = Boolean(payload.ga4);

            this.trackViewItemList();
            this.refreshStock();
        },

        readPayload() {
            const island = this.$root.parentElement
                ? this.$root.parentElement.querySelector('[data-scr1be-card-grid]')
                : null;
            if (!island) {
                return null;
            }
            try {
                return JSON.parse(island.textContent);
            } catch (error) {
                return null;
            }
        },

        /**
         * One request for the whole grid — the endpoint takes a list precisely so a grid never has
         * to ask per card.
         */
        refreshStock() {
            if (!this.endpoints.stock || this.cards.length === 0) {
                return;
            }

            const url = new URL(this.endpoints.stock, window.location.origin);
            url.searchParams.set('ids', toStockRequestIds(this.cards).join(','));

            fetch(url.toString(), { method: 'GET', headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
                .then((payload) => {
                    this.cards = this.cards.map((card) => {
                        const { changed, stock } = applyStockUpdate(card, payload.items || {});

                        return changed ? { ...card, stock } : card;
                    });
                })
                .catch(() => undefined);
        },

        /**
         * The click target carries its own card id, so this stays a bare method reference in the
         * template — an argument would have made it a computed expression, which a strict CSP
         * refuses to evaluate.
         */
        trackSelectItem(event) {
            const id = Number(event.currentTarget.dataset.cardId);
            const index = this.cards.findIndex((card) => Number(card.id) === id);
            if (index < 0) {
                return;
            }

            this.dispatchGa4({ event: 'select_item', items: [toGa4Item(this.cards[index], index)] });
        },

        trackViewItemList() {
            if (this.cards.length === 0) {
                return;
            }

            this.dispatchGa4({ event: 'view_item_list', ...toGa4ViewItemList(this.cards) });
        },

        dispatchGa4(detail) {
            if (!this.ga4) {
                return;
            }

            window.dispatchEvent(new CustomEvent(EVENT_GA4, { detail }));

            if (Array.isArray(window.dataLayer)) {
                window.dataLayer.push(detail);
            }
        },
    };
};
