/**
 * The instant-search component, with no knowledge of where its configuration came from.
 *
 * It used to be a function in a `<script>` tag reading six `SCR1BE_*` globals the template
 * had written above it. That arrangement has one property worth naming: the only way to
 * exercise it was to load a storefront page. Everything here takes its endpoints and limits
 * as an argument instead, so `node --test` runs the same code the browser does.
 */

/** The query is a constant, not built per call: nothing in it varies but the variables. */
export const SEARCH_QUERY = `
    query InstantSearch($search: String!, $pageSize: Int!) {
        products(search: $search, pageSize: $pageSize) {
            total_count
            items {
                uid
                name
                sku
                url_key
                small_image { url label }
                price_range { minimum_price { final_price { value currency } } }
            }
        }
    }
`;

/**
 * @param {object} config
 * @param {string} config.graphqlUrl
 * @param {string} config.searchResultUrl
 * @param {string} config.productUrlSuffix
 * @param {number} config.pageSize
 * @param {number} config.cacheTtlMs
 * @param {number} config.minQuery
 * @returns {function(): object} An Alpine component factory.
 */
export const instantSearchComponent = (config) => () => ({
    query: '',
    results: [],
    totalCount: 0,

    /** Exposed so the empty state gates on the same threshold `search()` enforces. */
    minQuery: config.minQuery,

    loading: false,
    error: '',
    isOpen: false,
    highlightIndex: -1,
    controller: null,
    cache: new Map(),

    onFocus() {
        if (this.results.length > 0) this.isOpen = true;
    },

    close() {
        this.isOpen = false;
        this.highlightIndex = -1;
    },

    reset() {
        this.query = '';
        this.results = [];
        this.totalCount = 0;
        this.error = '';
        this.close();
    },

    async search() {
        const trimmed = this.query.trim();
        if (trimmed.length < config.minQuery) {
            this.results = [];
            this.totalCount = 0;
            this.isOpen = false;
            return;
        }

        const cached = this.cache.get(trimmed);
        if (cached && Date.now() - cached.timestamp < config.cacheTtlMs) {
            this.applyResults(cached.items, cached.totalCount);
            return;
        }

        // Aborting the previous request is what makes a fast typist's earlier keystrokes
        // unable to overwrite the answer to their latest one.
        this.controller?.abort();
        this.controller = new AbortController();
        this.loading = true;
        this.error = '';
        this.isOpen = true;

        try {
            const response = await fetch(config.graphqlUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    query: SEARCH_QUERY,
                    variables: { search: trimmed, pageSize: config.pageSize },
                }),
                signal: this.controller.signal,
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (payload.errors?.length) throw new Error(payload.errors[0].message);

            const items = payload.data?.products?.items ?? [];
            const totalCount = payload.data?.products?.total_count ?? 0;

            this.cache.set(trimmed, { items, totalCount, timestamp: Date.now() });
            this.applyResults(items, totalCount);
        } catch (error) {
            // An abort is this component cancelling itself, not a failure to report.
            if (error.name === 'AbortError') return;
            this.error = error.message;
            this.results = [];
        } finally {
            this.loading = false;
        }
    },

    applyResults(items, totalCount) {
        this.results = items;
        this.totalCount = totalCount;
        this.highlightIndex = items.length > 0 ? 0 : -1;
    },

    moveHighlight(delta) {
        if (this.results.length === 0) return;
        this.highlightIndex = (this.highlightIndex + delta + this.results.length) % this.results.length;
    },

    followHighlight() {
        const item = this.results[this.highlightIndex];
        if (item) window.location.href = this.productUrl(item);
        else if (this.query.trim().length > 0) window.location.href = this.seeAllUrl();
    },

    /**
     * `url_key` plus the admin-configured suffix, both from the server:
     * `catalog/seo/product_url_suffix` can be ".html", empty, or anything else.
     */
    productUrl(item) {
        return `/${item.url_key}${config.productUrlSuffix}`;
    },

    seeAllUrl() {
        const sep = config.searchResultUrl.includes('?') ? '&' : '?';
        return config.searchResultUrl + sep + 'q=' + encodeURIComponent(this.query.trim());
    },

    formatPrice(item) {
        const price = item.price_range?.minimum_price?.final_price;
        if (!price) return '';
        return `${price.value.toFixed(2)} ${price.currency}`;
    },

    /**
     * Escape first, then inject the mark. Doing it the other way round would let a product
     * name containing markup reach `x-html` intact.
     */
    highlightMatch(text) {
        const trimmed = this.query.trim();
        if (!trimmed) return this.escape(text);
        const escaped = this.escape(text);
        const pattern = trimmed.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return escaped.replace(new RegExp(`(${pattern})`, 'ig'), '<mark class="bg-yellow-200">$1</mark>');
    },

    escape(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
});
