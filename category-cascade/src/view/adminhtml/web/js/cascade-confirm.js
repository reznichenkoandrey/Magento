/**
 * Confirms a cascading category disable before the admin form is submitted.
 *
 * Loaded as an ES module from a src attribute: no inline script, no inline handler, nothing for a
 * strict CSP to reject. It reads its data from the block's hidden element rather than from
 * generated JavaScript, which keeps the markup and the script independently cacheable.
 *
 * The prompt is a courtesy. Every condition below is re-evaluated server side by CascadeGuard, so
 * a browser that never runs this file gets the same cascade — it just gets it without warning.
 */
const ROOT_ID = 'scr1be-cascade-confirm';

/**
 * The Save button as it is rendered across admin page layouts. Matching a union rather than one
 * selector because the toolbar markup is core's, not this module's, and a missed button has to
 * degrade to "no prompt" rather than to a broken save.
 */
const SAVE_BUTTON_SELECTOR = '#save, button.save.primary, [data-form-role="save"]';

/** The "Enable Category" toggle, rendered by the category form UI component. */
const ENABLE_TOGGLE_SELECTOR = '[data-index="is_active"] input[type="checkbox"]';

const root = document.getElementById(ROOT_ID);

if (root && root.dataset.wasActive === '1') {
    /**
     * Capture phase, bound to the document: the Save button belongs to a UI component that binds
     * its own click handler, and this listener has to win the race to run before that handler
     * starts the AJAX save. Binding to the document also means the button may be rendered at any
     * point in the form's asynchronous render without the listener having to wait for it.
     */
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target || !target.closest(SAVE_BUTTON_SELECTOR)) {
            return;
        }

        const toggle = document.querySelector(ENABLE_TOGGLE_SELECTOR);
        // No toggle means the form has not rendered yet; a checked toggle means the category is
        // still enabled. Neither save can cascade, so neither is worth interrupting.
        if (!toggle || toggle.checked) {
            return;
        }

        // A synchronous dialog on purpose. An asynchronous modal would have to cancel this click
        // and re-dispatch it after the answer, which is how double submits get written.
        if (window.confirm(root.dataset.message)) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);
}
