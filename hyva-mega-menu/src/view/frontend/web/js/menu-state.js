/**
 * Everything the menu knows about itself, and nothing about the page it is drawn on.
 *
 * There is one state machine for both placements. The desktop dropdown and the mobile drawer are
 * not two components that happen to share markup — they are the same three questions (which
 * top-level entry is open, which of its branches is open, is the drawer showing) rendered by two
 * different stylesheets. Keeping the answers here, with no element reference in sight, is what
 * lets the tree be physically moved between the two docks without any state travelling with it.
 *
 * The file is pure so that the transitions can be asserted directly. Every rule below is a rule
 * somebody eventually breaks by hand: reopening the same entry closes it, opening a different one
 * abandons the branch that was open under the old one, and changing placement resets everything.
 */

export const PLACEMENT_DESKTOP = 'desktop';
export const PLACEMENT_MOBILE = 'mobile';

/** No panel open, one column of second-level entries, plus the third-level column. */
const COLUMNS_CLOSED = 0;
const COLUMNS_SECOND_LEVEL = 1;
const COLUMNS_THIRD_LEVEL = 2;

export const createMenuState = (initialPlacement = PLACEMENT_DESKTOP) => {
    let placement = initialPlacement;
    let drawerOpen = false;
    let topKey = null;
    let branchKey = null;

    const columns = () => {
        if (topKey === null) {
            return COLUMNS_CLOSED;
        }

        return branchKey === null ? COLUMNS_SECOND_LEVEL : COLUMNS_THIRD_LEVEL;
    };

    const closePanels = () => {
        topKey = null;
        branchKey = null;
    };

    return {
        snapshot: () => ({ placement, drawerOpen, topKey, branchKey, columns: columns() }),

        isDesktop: () => placement === PLACEMENT_DESKTOP,

        /**
         * A placement change is a reset, not a migration. The drawer has no meaning on a desktop
         * dock and an open dropdown has none inside a drawer, so carrying either across is how a
         * resize leaves a panel open that nothing on screen can close.
         */
        setPlacement: (next) => {
            if (next === placement) {
                return false;
            }

            placement = next;
            drawerOpen = false;
            closePanels();

            return true;
        },

        openTop: (key) => {
            if (topKey !== key) {
                branchKey = null;
            }

            topKey = key;
        },

        /**
         * Pressing the control of the entry that is already open closes it. Without this the only
         * way out of an opened panel is a click elsewhere, which on a touch screen means the
         * control the shopper just pressed does nothing the second time.
         */
        toggleTop: (key) => {
            if (topKey === key) {
                closePanels();

                return;
            }

            topKey = key;
            branchKey = null;
        },

        toggleBranch: (key) => {
            branchKey = branchKey === key ? null : key;
        },

        openDrawer: () => {
            drawerOpen = true;
        },

        /**
         * What the close button, escape and an outside click all mean: nothing of this menu is open
         * any more. There is deliberately no separate "close the drawer but keep the panels" — a
         * panel nobody can see is a panel that reopens on the next placement change.
         */
        closeAll: () => {
            drawerOpen = false;
            closePanels();
        },
    };
};
