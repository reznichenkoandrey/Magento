import test from 'node:test';
import assert from 'node:assert/strict';

import {
    COMPONENT_NAME,
    couponTicketComponent,
    createClipboard,
    register,
} from '../../view/frontend/web/js/coupon-ticket.js';

/** A stand-in for the browser's timers that lets a test decide when time passes. */
const fakeTimers = () => {
    const scheduled = [];

    return {
        setTimeout: (callback, delay) => {
            scheduled.push({ callback, delay });

            return scheduled.length;
        },
        clearTimeout: (handle) => {
            scheduled[handle - 1] = null;
        },
        run: () => scheduled.filter(Boolean).forEach(({ callback }) => callback()),
        pending: () => scheduled.filter(Boolean).length,
        delays: () => scheduled.filter(Boolean).map(({ delay }) => delay),
    };
};

const workingClipboard = () => {
    const written = [];

    return { write: (text) => { written.push(text); return Promise.resolve(); }, written };
};

const brokenClipboard = () => ({ write: () => Promise.reject(new Error('denied')) });

test('a successful copy writes the code the template was given', async () => {
    const clipboard = workingClipboard();
    const ticket = couponTicketComponent(clipboard, fakeTimers())('SPRING20', 2000);

    await ticket.copy();

    assert.deepEqual(clipboard.written, ['SPRING20']);
    assert.equal(ticket.copied, true);
    assert.equal(ticket.failed, false);
});

test('a rejected copy is reported instead of thrown', async () => {
    // navigator.clipboard rejects when the document is not focused or permission is denied. Both
    // are normal, and the template answers them by pointing at the selectable code.
    const ticket = couponTicketComponent(brokenClipboard(), fakeTimers())('SPRING20', 2000);

    await ticket.copy();

    assert.equal(ticket.copied, false);
    assert.equal(ticket.failed, true);
});

test('the confirmation clears itself after the delay the block configured', async () => {
    const timers = fakeTimers();
    const ticket = couponTicketComponent(workingClipboard(), timers)('SPRING20', 2000);

    await ticket.copy();
    assert.deepEqual(timers.delays(), [2000]);

    timers.run();

    assert.equal(ticket.copied, false);
    assert.equal(ticket.failed, false);
});

test('a failed copy clears its message too', async () => {
    const timers = fakeTimers();
    const ticket = couponTicketComponent(brokenClipboard(), timers)('SPRING20', 2000);

    await ticket.copy();
    timers.run();

    assert.equal(ticket.failed, false);
});

test('a second click does not inherit the first click timer', async () => {
    // Otherwise the confirmation from the second copy is blanked by the first copy's timer, a
    // fraction of a second after it appears.
    const timers = fakeTimers();
    const ticket = couponTicketComponent(workingClipboard(), timers)('SPRING20', 2000);

    await ticket.copy();
    await ticket.copy();

    assert.equal(timers.pending(), 1);
    assert.equal(ticket.copied, true);
});

test('teardown cancels a pending reset', async () => {
    const timers = fakeTimers();
    const ticket = couponTicketComponent(workingClipboard(), timers)('SPRING20', 2000);

    await ticket.copy();
    ticket.destroy();

    assert.equal(timers.pending(), 0);
});

test('the clipboard adapter rejects rather than throwing when the API is absent', async () => {
    // http:// pages and old browsers have no navigator.clipboard at all. A synchronous TypeError
    // here would escape the component's try/catch and leave the button dead.
    const clipboard = createClipboard({ navigator: {} });

    await assert.rejects(() => clipboard.write('SPRING20'));
});

test('the clipboard adapter forwards to navigator.clipboard.writeText', async () => {
    const written = [];
    const clipboard = createClipboard({
        navigator: { clipboard: { writeText: (text) => { written.push(text); return Promise.resolve(); } } },
    });

    await clipboard.write('SPRING20');

    assert.deepEqual(written, ['SPRING20']);
});

test('register binds the component name the templates use, once', () => {
    // The seam. The component name is a promise to two .phtml files, and `alpine:init` fires once
    // per page — a listener without `once` would re-register on a page that dispatches it again.
    const registered = {};
    const listeners = [];
    const win = {
        addEventListener: (name, callback, options) => listeners.push({ name, callback, options }),
        Alpine: { data: (name, factory) => { registered[name] = factory; } },
        navigator: {},
    };

    register(win);

    assert.equal(listeners.length, 1);
    assert.equal(listeners[0].name, 'alpine:init');
    assert.deepEqual(listeners[0].options, { once: true });

    listeners[0].callback();

    assert.equal(COMPONENT_NAME, 'scr1beCouponTicket');
    assert.equal(typeof registered[COMPONENT_NAME], 'function');
});

test('the registered factory produces a component with the members the templates bind to', () => {
    const registered = {};
    const win = {
        addEventListener: (name, callback) => callback(),
        Alpine: { data: (name, factory) => { registered[name] = factory; } },
        navigator: {},
        setTimeout: () => 1,
        clearTimeout: () => {},
    };

    register(win);

    const component = registered[COMPONENT_NAME]('SPRING20', 2000);

    // ticket.phtml and ticket-compact.phtml bind exactly these.
    assert.equal(typeof component.copy, 'function');
    assert.equal(component.copied, false);
    assert.equal(component.failed, false);
});
