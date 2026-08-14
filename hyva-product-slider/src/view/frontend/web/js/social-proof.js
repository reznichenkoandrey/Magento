/**
 * The volatile line under a slide: fetching it, and writing it into markup that was cached an hour
 * ago.
 *
 * Everything here is deliberately free of Alpine and of the component, because both halves are worth
 * testing on their own: the url has to survive an id list that a template produced, and the writing
 * has to be safe against text a shopper typed into a name field.
 */

const PROOF_SELECTOR = '[data-scr1be-proof]';

/**
 * Ids are sorted and de-duplicated before they reach the query string so that two sliders showing
 * the same products in a different order hit the same cache entry on the CDN rather than minting two.
 * The endpoint sorts them again on its own side; doing it here is what makes the *url* identical.
 */
export const buildProofUrl = (endpoint, productIds) => {
    const ids = Array.from(new Set((productIds || []).map(Number).filter((id) => Number.isInteger(id) && id > 0)))
        .sort((left, right) => left - right);

    if (endpoint === undefined || endpoint === null || endpoint === '' || ids.length === 0) {
        return '';
    }

    const separator = endpoint.includes('?') ? '&' : '?';

    return `${endpoint}${separator}ids=${ids.join(',')}`;
};

/**
 * `textContent`, never `innerHTML`. The sentence contains a shopper-supplied first name and a
 * shopper-supplied city; it is escaped on the way out of PHP for an HTML context, and assigning it as
 * text means it cannot become markup here either. Two layers, because this one is the one a future
 * refactor is most likely to reach for `innerHTML` in.
 *
 * @returns {number} How many lines were filled — the rest stay hidden, which is the correct rendering
 *                   for a product nobody bought inside the window.
 */
export const applyProofs = (root, items) => {
    if (!root || !items) {
        return 0;
    }

    let applied = 0;

    root.querySelectorAll(PROOF_SELECTOR).forEach((node) => {
        const proof = items[node.getAttribute('data-scr1be-proof')];

        if (!proof || !proof.text) {
            return;
        }

        node.textContent = proof.text;
        node.hidden = false;
        applied += 1;
    });

    return applied;
};

/**
 * A failed fetch resolves to an empty map rather than rejecting.
 *
 * The purchase line is decoration on a carousel that already rendered. A network error, a 404 from a
 * misconfigured route or a body that is not JSON must leave the page exactly as it was — never an
 * unhandled rejection in the console of every product page.
 */
export const fetchProofs = async (endpoint, productIds, fetchImpl) => {
    const url = buildProofUrl(endpoint, productIds);

    if (url === '') {
        return {};
    }

    const request = fetchImpl || (typeof fetch === 'function' ? fetch : null);

    if (request === null) {
        return {};
    }

    try {
        const response = await request(url, { headers: { Accept: 'application/json' } });

        if (!response || !response.ok) {
            return {};
        }

        const payload = await response.json();

        return payload && typeof payload.items === 'object' && payload.items !== null ? payload.items : {};
    } catch (error) {
        return {};
    }
};
