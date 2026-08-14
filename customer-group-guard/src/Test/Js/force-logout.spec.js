/**
 * Spec for the soft path's Alpine component.
 *
 * Runs on Node's built-in test runner with no dependencies and no DOM: the component is written
 * against a `browser` seam and against two Alpine magics, so a recorder object and a two-line
 * stub are the whole harness.
 *
 *   node --test Test/Js/force-logout.spec.js      (or: npm test, from src/)
 */
import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

import { SECTION_NAME, forceLogoutGuard } from '../../view/frontend/web/js/force-logout.js';

const LOGOUT_URL = 'https://example.test/customer/account/logout/';

const createBrowser = (pendingNotice = null) => ({
    handlers: [],
    notice: pendingNotice,
    written: [],
    shown: [],
    redirects: [],
    takeCalls: 0,

    onCustomerData(handler) {
        this.handlers.push(handler);
    },
    takeNotice() {
        this.takeCalls += 1;
        const notice = this.notice;
        this.notice = null;

        return notice;
    },
    writeNotice(text) {
        this.written.push(text);
    },
    showNotice(text) {
        this.shown.push(text);
    },
    redirect(url) {
        this.redirects.push(url);
    },

    /** Replays what the page's private-content channel would have delivered. */
    emit(sections) {
        this.handlers.forEach((handler) => handler(sections));
    }
});

const mount = (browser, dataset = { logoutUrl: LOGOUT_URL }) => {
    const component = forceLogoutGuard(browser);

    component.$el = { dataset };
    component.$nextTick = (callback) => callback();
    component.init();

    return component;
};

const forcedSection = (message = 'Your customer group was changed.') => ({
    [SECTION_NAME]: { force_logout: true, message }
});

describe('forceLogoutGuard — reacting to customer data', () => {
    let browser;

    beforeEach(() => {
        browser = createBrowser();
    });

    it('signs the customer out when the section says so', () => {
        mount(browser);

        browser.emit(forcedSection('Group changed'));

        assert.deepEqual(browser.written, ['Group changed']);
        assert.deepEqual(browser.redirects, [LOGOUT_URL]);
    });

    it('stays put when the section is absent from the payload', () => {
        mount(browser);

        browser.emit({ cart: { summary_count: 3 } });

        assert.deepEqual(browser.redirects, []);
    });

    it('stays put when the payload itself is missing', () => {
        mount(browser);

        browser.emit(null);
        browser.emit(undefined);

        assert.deepEqual(browser.redirects, []);
    });

    it('stays put while the section reports no change', () => {
        mount(browser);

        browser.emit({ [SECTION_NAME]: { force_logout: false } });

        assert.deepEqual(browser.redirects, []);
    });

    it('treats a stringified flag as no change rather than as truthy', () => {
        mount(browser);

        // What a mangled localStorage round trip can leave behind.
        browser.emit({ [SECTION_NAME]: { force_logout: 'false' } });

        assert.deepEqual(browser.redirects, []);
    });

    it('redirects once even when customer data arrives repeatedly', () => {
        mount(browser);

        browser.emit(forcedSection());
        browser.emit(forcedSection());
        browser.emit(forcedSection());

        assert.equal(browser.redirects.length, 1);
        assert.equal(browser.written.length, 1);
    });

    it('does nothing when the element carries no logout url', () => {
        mount(browser, {});

        browser.emit(forcedSection());

        assert.deepEqual(browser.redirects, []);
        assert.deepEqual(browser.written, []);
    });

    it('still redirects when the section carries no message', () => {
        mount(browser);

        browser.emit({ [SECTION_NAME]: { force_logout: true } });

        // writeNotice ignores an empty notice; the logout is not conditional on the copy.
        assert.deepEqual(browser.written, [undefined]);
        assert.deepEqual(browser.redirects, [LOGOUT_URL]);
    });
});

describe('forceLogoutGuard — the notice that survives the redirect', () => {
    it('shows a pending notice exactly once', () => {
        const browser = createBrowser('You were signed out.');

        mount(browser);

        assert.deepEqual(browser.shown, ['You were signed out.']);
        assert.equal(browser.takeCalls, 1);
        assert.equal(browser.notice, null, 'the flag is consumed, not just read');
    });

    it('shows nothing on an ordinary page load', () => {
        const browser = createBrowser();

        mount(browser);

        assert.deepEqual(browser.shown, []);
        assert.equal(browser.takeCalls, 1);
    });

    it('subscribes to customer data on mount', () => {
        const browser = createBrowser();

        mount(browser);

        assert.equal(browser.handlers.length, 1);
    });
});

/**
 * The adapter half. The suite above stubs `browser` wholesale, so the code that actually talks to
 * Hyvä and to localStorage was the one seam with no coverage — and it is the seam whose contracts
 * are owned by someone else and can drift under us. Importing the register module needs a `window`
 * first, because it attaches its customer-data listener at evaluation time.
 */
describe('browser adapter — the contracts owned by Hyvä', () => {
    let events;
    let browser;

    beforeEach(async () => {
        events = [];
        globalThis.window = {
            addEventListener() {},
            dispatchEvent: (event) => events.push(event),
            localStorage: {
                getItem: () => null,
                setItem() {},
                removeItem() {}
            }
        };
        globalThis.CustomEvent = class {
            constructor(type, init) {
                this.type = type;
                this.detail = init && init.detail;
            }
        };

        // Cache-busted so each case re-evaluates the module against its own window stub.
        ({ browser } = await import(
            `../../view/frontend/web/js/force-logout-register.js?case=${events.length}-${Math.random()}`
        ));
    });

    it('prefers the theme helper when it is present', () => {
        const calls = [];
        globalThis.window.dispatchMessages = (messages, hideAfter) => calls.push([messages, hideAfter]);

        browser.showNotice('Your group changed.');

        assert.equal(calls.length, 1);
        assert.deepEqual(calls[0][0], [{ type: 'warning', text: 'Your group changed.' }]);
        assert.equal(calls[0][1], 10000, 'the timeout travels as the second positional argument');
        assert.equal(events.length, 0, 'no raw event when the helper answered');
    });

    it('falls back to the messages-loaded event with the key Hyvä actually reads', () => {
        browser.showNotice('Your group changed.');

        assert.equal(events.length, 1);
        assert.equal(events[0].type, 'messages-loaded');
        assert.deepEqual(events[0].detail.messages, [{ type: 'warning', text: 'Your group changed.' }]);
        // hideAfter, not hideTimeout: the messages component reads event.detail.hideAfter, and its
        // auto-hide default only covers `success` — so a mis-keyed warning would never dismiss.
        assert.equal(events[0].detail.hideAfter, 10000);
        assert.ok(!('hideTimeout' in events[0].detail));
    });
});
