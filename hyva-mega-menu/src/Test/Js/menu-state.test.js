/**
 * The state machine, asserted directly.
 *
 * Every rule here is one somebody eventually breaks by hand while chasing a visual bug: pressing
 * an open entry closes it, opening a different one abandons the branch under the old one, and a
 * placement change resets rather than migrates. None of them is visible in the markup, so none of
 * them is caught by looking at the menu.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    PLACEMENT_DESKTOP,
    PLACEMENT_MOBILE,
    createMenuState,
} from 'scr1be-mega-menu/state.js';

describe('menu state', () => {
    it('starts closed, in the placement it was created for', () => {
        const state = createMenuState(PLACEMENT_DESKTOP);

        assert.deepEqual(state.snapshot(), {
            placement: PLACEMENT_DESKTOP,
            drawerOpen: false,
            topKey: null,
            branchKey: null,
            columns: 0,
        });
        assert.equal(state.isDesktop(), true);
    });

    it('defaults to the desktop placement, which is the one the server rendered into', () => {
        assert.equal(createMenuState().snapshot().placement, PLACEMENT_DESKTOP);
    });

    it('counts one column for an open entry and two once a branch is open under it', () => {
        const state = createMenuState();

        state.openTop('c3');
        assert.equal(state.snapshot().columns, 1);

        state.toggleBranch('c11');
        assert.equal(state.snapshot().columns, 2);
    });

    it('closes an entry when its own control is pressed a second time', () => {
        const state = createMenuState();

        state.toggleTop('c3');
        state.toggleTop('c3');

        assert.deepEqual(
            [state.snapshot().topKey, state.snapshot().branchKey, state.snapshot().columns],
            [null, null, 0]
        );
    });

    it('abandons the open branch when a different top-level entry is opened', () => {
        const state = createMenuState();

        state.openTop('c3');
        state.toggleBranch('c11');
        state.toggleTop('c4');

        assert.deepEqual([state.snapshot().topKey, state.snapshot().branchKey], ['c4', null]);
    });

    it('keeps the open branch when the entry it belongs to is reopened by hover', () => {
        const state = createMenuState();

        state.openTop('c3');
        state.toggleBranch('c11');
        state.openTop('c3');

        assert.equal(state.snapshot().branchKey, 'c11');
    });

    it('opens a top-level entry on hover without ever closing it again', () => {
        const state = createMenuState();

        state.openTop('c3');
        state.openTop('c3');

        assert.equal(state.snapshot().topKey, 'c3');
    });

    it('toggles a branch closed when its own control is pressed again', () => {
        const state = createMenuState();

        state.openTop('c3');
        state.toggleBranch('c11');
        state.toggleBranch('c11');

        assert.equal(state.snapshot().branchKey, null);
    });

    it('replaces the open branch when a sibling branch is opened', () => {
        const state = createMenuState();

        state.openTop('c3');
        state.toggleBranch('c11');
        state.toggleBranch('c12');

        assert.equal(state.snapshot().branchKey, 'c12');
    });

    it('treats a placement change as a reset, not a migration', () => {
        const state = createMenuState(PLACEMENT_MOBILE);

        state.openDrawer();
        state.toggleTop('c3');
        state.toggleBranch('c11');

        assert.equal(state.setPlacement(PLACEMENT_DESKTOP), true);
        assert.deepEqual(state.snapshot(), {
            placement: PLACEMENT_DESKTOP,
            drawerOpen: false,
            topKey: null,
            branchKey: null,
            columns: 0,
        });
    });

    it('reports that nothing changed when the placement is already the one asked for', () => {
        const state = createMenuState(PLACEMENT_DESKTOP);

        state.toggleTop('c3');

        assert.equal(state.setPlacement(PLACEMENT_DESKTOP), false);
        assert.equal(state.snapshot().topKey, 'c3', 'a no-op placement change must not reset');
    });

    it('closes everything at once, because a panel nobody can see reopens on the next resize', () => {
        const state = createMenuState(PLACEMENT_MOBILE);

        state.openDrawer();
        state.toggleTop('c3');
        state.toggleBranch('c11');
        state.closeAll();

        assert.deepEqual(state.snapshot(), {
            placement: PLACEMENT_MOBILE,
            drawerOpen: false,
            topKey: null,
            branchKey: null,
            columns: 0,
        });
    });

    it('hands out a snapshot rather than its own state', () => {
        const state = createMenuState();
        const snapshot = state.snapshot();

        state.toggleTop('c3');

        assert.equal(snapshot.topKey, null, 'an earlier snapshot must not change under the caller');
    });
});
