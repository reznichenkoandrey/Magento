/**
 * The soft path's Alpine component.
 *
 * It is a file rather than an inline block in the template for one reason: it has a spec. A
 * component that decides whether to sign a customer out is worth being able to run assertions
 * against, and an inline script has no module boundary to import.
 *
 * Everything that touches the browser is behind the `browser` seam, which the register module
 * fills in with the real window and the spec fills in with a recorder. What is left here is the
 * decision ladder and nothing else, which is exactly the part worth testing.
 */

/** Must match the section name registered in etc/frontend/di.xml. */
export const SECTION_NAME = 'scr1be_force_logout';

/**
 * The notice has to survive a full-page redirect to the logout route, so it cannot live in the
 * component's state, and it must not survive a second one, so it cannot live in a cookie the
 * server would have to be taught to clear. A one-shot localStorage key is the smallest thing
 * that spans exactly one navigation.
 */
export const NOTICE_STORAGE_KEY = 'scr1be-force-logout-notice';

export const forceLogoutGuard = (browser) => ({
    /** Latches on the first accepted payload: customer data can arrive more than once per page. */
    redirecting: false,

    init() {
        // $nextTick rather than a direct call: the notice is delivered as an event, and the
        // component that renders it is initialised in the same Alpine pass as this one. Waiting
        // one tick means the listener exists whatever order the two elements appear in.
        this.$nextTick(() => this.showPendingNotice());

        browser.onCustomerData((sections) => this.onCustomerData(sections));
    },

    onCustomerData(sections) {
        if (this.redirecting) {
            return;
        }

        const section = sections && sections[SECTION_NAME];
        // Strict comparison on purpose. Section data is JSON that has been through localStorage,
        // and a truthiness test would accept the string "false" that a mangled round trip can
        // leave behind.
        if (!section || section.force_logout !== true) {
            return;
        }

        // The URL is markup, not script: the template renders it into a data attribute, so the
        // component never builds a route and the page never carries a generated script block.
        const logoutUrl = this.$el.dataset.logoutUrl;
        if (!logoutUrl) {
            return;
        }

        this.redirecting = true;
        browser.writeNotice(section.message);
        browser.redirect(logoutUrl);
    },

    /**
     * Runs on every page, not just the one after a logout. The flag is its own trigger — reading
     * it is what makes the notice appear on whichever page the logout route ended up on, without
     * this component having to know which one that is.
     */
    showPendingNotice() {
        const notice = browser.takeNotice();

        if (notice) {
            browser.showNotice(notice);
        }
    },
});
