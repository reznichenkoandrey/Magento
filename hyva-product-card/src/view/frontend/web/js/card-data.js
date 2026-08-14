/**
 * The card's decisions, with no DOM and no Alpine anywhere near them.
 *
 * Everything here is a pure function of its arguments, which is the only reason the quantity rules
 * can be trusted: they are the browser half of a contract whose server half is
 * `Model\Card\QtyRuleResolver`, and a rule that disagrees with the server surfaces as a rejected
 * checkout rather than as a visible bug. Pure functions are also the half of this module that can
 * be tested without a browser.
 */

/** Quantities are DECIMAL(12,4) on the server; anything finer is noise from float arithmetic. */
const QTY_DECIMALS = 4;
const QTY_EPSILON = 1e-6;

/** GA4 counts list positions from 1; every renderer here counts from 0. */
const GA4_INDEX_OFFSET = 1;

/**
 * Rounds to the server's precision. Without this, `0.1 + 0.2` steps a decimal stepper to
 * 0.30000000000000004 and the input starts showing it.
 *
 * @param {number} value
 * @returns {number}
 */
export const roundQty = (value) => Number(value.toFixed(QTY_DECIMALS));

/**
 * Snaps a quantity onto the product's own ladder.
 *
 * A legal quantity is a whole multiple of the increment measured from **zero**, not from the
 * minimum: core rejects anything else in `StockStateProvider::checkQtyIncrements()`, which errors
 * unless the quantity divides exactly by `qty_increments`. So a product with "minimum 10,
 * increments of 6" is buyable at 12, 18, 24 — never at 10 or 16.
 *
 * The bounds arriving here have already been snapped by `Model\Card\QtyRuleResolver`; this function
 * re-derives them anyway, because it also runs on whatever the shopper typed.
 *
 * @param {number} value
 * @param {{min: number, step: number, max: (number|null), is_decimal: boolean}} rules
 * @returns {number}
 */
export const clampQty = (value, rules) => {
    const step = Number(rules?.step) > 0 ? Number(rules.step) : 1;
    const rawMin = Number(rules?.min) > 0 ? Number(rules.min) : step;
    const rawMax = Number.isFinite(Number(rules?.max)) && Number(rules?.max) > 0 ? Number(rules.max) : null;

    const min = roundQty(Math.ceil(roundQty(rawMin / step)) * step);
    const max = rawMax === null ? null : roundQty(Math.floor(roundQty(rawMax / step)) * step);

    let qty = Number(value);
    if (!Number.isFinite(qty)) {
        qty = min;
    }

    qty = roundQty(Math.round(roundQty(qty / step)) * step);

    if (qty < min) {
        qty = min;
    }

    // Snapping to the nearest step can land above the ceiling; the step below it is the only
    // correction that is still a legal quantity. A ceiling under the minimum means the product has
    // no purchasable quantity — the stepper reports the minimum rather than an unbuyable number.
    if (max !== null && qty > max + QTY_EPSILON) {
        qty = max >= min ? max : min;
    }

    return qty;
};

/**
 * @param {number} value
 * @param {{min: number, step: number, max: (number|null)}} rules
 * @returns {number}
 */
export const nextQty = (value, rules) => clampQty(Number(value) + Number(rules?.step || 1), rules);

/**
 * @param {number} value
 * @param {{min: number, step: number, max: (number|null)}} rules
 * @returns {number}
 */
export const previousQty = (value, rules) => clampQty(Number(value) - Number(rules?.step || 1), rules);

/**
 * Applies a fresh answer from the stock endpoint to a card, and reports whether anything changed.
 *
 * The endpoint answers for a page of cards at a time and a card may be missing from the answer —
 * a product disabled between the render and the fetch, or an id the endpoint truncated. A missing
 * entry means "no news", never "out of stock": the cached label is stale, not wrong, and turning a
 * saleable product off on the strength of an absent key would be the worse failure.
 *
 * @param {object} card
 * @param {object} items Keyed by product id, as the endpoint returns them.
 * @returns {{changed: boolean, stock: object}}
 */
export const applyStockUpdate = (card, items) => {
    const fresh = items ? items[String(card.id)] : undefined;
    if (!fresh) {
        return { changed: false, stock: card.stock };
    }

    const changed = fresh.in_stock !== card.stock.in_stock
        || fresh.label !== card.stock.label
        || fresh.is_low !== card.stock.is_low;

    return { changed, stock: fresh };
};

/**
 * The GA4 item payload for a card at a known position.
 *
 * The server fills in `index` from whatever the renderer knew, which for a Hyvä listing is nothing
 * — the item renderer is handed one product at a time and never learns where in the grid it sits.
 * The browser does know, so the position is corrected here rather than guessed there.
 *
 * @param {object} card
 * @param {number} domIndex 0-based position among the cards on the page.
 * @returns {object}
 */
export const toGa4Item = (card, domIndex) => ({
    ...(card.analytics || {}),
    index: domIndex + GA4_INDEX_OFFSET,
});

/**
 * @param {object[]} cards
 * @returns {object} A GA4 `view_item_list` payload.
 */
export const toGa4ViewItemList = (cards) => {
    const items = cards.map((card, index) => toGa4Item(card, index));
    const first = items[0] || {};

    return {
        item_list_id: first.item_list_id || '',
        item_list_name: first.item_list_name || '',
        items,
    };
};

/**
 * Ids the stock endpoint should be asked about, in a stable order.
 *
 * Sorting is not cosmetic: the endpoint is cacheable, and two grids showing the same products in a
 * different order would otherwise mint two cache entries for one answer.
 *
 * @param {object[]} cards
 * @returns {number[]}
 */
export const toStockRequestIds = (cards) => [...new Set(
    cards.map((card) => Number(card.id)).filter((id) => Number.isInteger(id) && id > 0)
)].sort((a, b) => a - b);
