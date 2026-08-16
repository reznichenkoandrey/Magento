/**
 * The seam between the PHP that renders the markup and the JavaScript that drives it.
 *
 * Nothing in either half fails loudly when they disagree. Rename a data attribute in the template
 * and the menu still renders, still validates, still passes every other spec in this directory —
 * it just stops opening, on every page, for everyone. The same is true of the sprite id prefix, the
 * component name in `x-data`, and the two custom properties the stylesheet and the adapter share.
 *
 * So this file reads the real template, the real stylesheet and the real block, and asserts that
 * every constant the adapter declares as "must match" actually appears in the file it must match.
 * It is a string check by nature: the contract is a string, and the two sides are written in
 * different languages.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

import {
    COLUMNS_PROPERTY,
    COMPONENT_NAME,
    DESKTOP_MEDIA_FALLBACK,
    DESKTOP_MEDIA_PROPERTY,
    ICON_COLOR_PROPERTY,
    SELECTOR,
    SPRITE_ID_PREFIX,
} from 'scr1be-mega-menu/register.js';

const MODULE_ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');

const read = (relativePath) => readFileSync(join(MODULE_ROOT, relativePath), 'utf8');

const TEMPLATE = read('view/frontend/templates/html/header/mega-menu.phtml');
const STYLESHEET = read('view/frontend/tailwind/module.css');
const SCRIPTS_BLOCK = read('Block/MenuScripts.php');
const SCRIPTS_TEMPLATE = read('view/frontend/templates/html/header/menu-scripts.phtml');
const PACKAGE = JSON.parse(read('package.json'));

describe('the template renders what the adapter looks for', () => {
    it('carries every selector the view indexes on', () => {
        Object.entries(SELECTOR).forEach(([name, selector]) => {
            assert.ok(
                TEMPLATE.includes(selector.replace(/^\[|\]$/g, '')),
                `the template renders no ${name} (${selector})`
            );
        });
    });

    it('names the component Alpine is asked to register', () => {
        assert.ok(
            TEMPLATE.includes(`x-data="${COMPONENT_NAME}"`),
            'x-data must name the component register.js registers'
        );
    });

    it('binds handlers as bare method references, which is all the CSP build resolves', () => {
        // Alpine's CSP build allows a directive to name a property or a method and nothing else.
        // An expression or an argument here works in development and silently dies in production.
        const directives = TEMPLATE.match(/(?:x-data|x-on:[\w.]+|@[\w.]+)="([^"]*)"/g) ?? [];

        assert.ok(directives.length > 0, 'the template should carry Alpine directives');

        directives.forEach((directive) => {
            const [, expression] = /="([^"]*)"/.exec(directive);

            assert.match(expression, /^[A-Za-z_$][\w$]*$/, `${directive} is not a bare reference`);
        });
    });

    it('gives its sprite symbols the ids the adapter builds for the third level', () => {
        assert.ok(
            TEMPLATE.includes(`id="${SPRITE_ID_PREFIX}`),
            'the inline sprite must use the id prefix the adapter points <use> at'
        );
    });

    it('sets the swatch colour through the same custom property the adapter does', () => {
        assert.ok(TEMPLATE.includes(ICON_COLOR_PROPERTY));
        assert.ok(STYLESHEET.includes(ICON_COLOR_PROPERTY));
    });
});

describe('the stylesheet and the adapter agree on geometry', () => {
    it('declares the column count the adapter writes', () => {
        assert.ok(STYLESHEET.includes(COLUMNS_PROPERTY));
    });

    it('declares the breakpoint the adapter reads back out', () => {
        assert.ok(STYLESHEET.includes(DESKTOP_MEDIA_PROPERTY + ':'));
    });

    it('keeps the JavaScript fallback breakpoint equal to the declared one', () => {
        // The property exists so the number is written once; the fallback is the copy that only
        // applies when the stylesheet has not loaded, and a copy that drifted is worse than none.
        const [, declared] = new RegExp(DESKTOP_MEDIA_PROPERTY + ':([^;]+);').exec(STYLESHEET);

        assert.equal(declared.trim(), DESKTOP_MEDIA_FALLBACK);
    });

    it('uses that same breakpoint in the media query, which cannot read a custom property', () => {
        assert.ok(STYLESHEET.includes('@media ' + DESKTOP_MEDIA_FALLBACK));
    });
});

describe('the browser resolves the module graph without an import map', () => {
    /**
     * A document installs the first import map it sees and Firefox rejects the rest, so a page
     * carrying this module beside any other one that prints its own loses whichever came second —
     * silently, with no server-side error and none in Chrome, which merges them. The rule is
     * therefore absolute rather than a preference: this module prints no map, and nothing it ships
     * imports by a specifier that would need one.
     */
    it('prints no import map', () => {
        assert.ok(!SCRIPTS_TEMPLATE.includes('type="importmap"'));
        // The block would have to build the map for the template to print one, so the absence of a
        // serialised `imports` key is the half that survives someone rewriting the template.
        assert.ok(!/['"]imports['"]\s*=>/.test(SCRIPTS_BLOCK));
    });

    it('imports every sibling by relative path', () => {
        Object.values(PACKAGE.exports).forEach((file) => {
            const source = read(file.replace(/^\.\//, ''));
            const bare = source.match(/from\s+'(?!\.)([^']+)'/g) ?? [];

            assert.deepEqual(bare, [], `${file} still imports by bare specifier: ${bare.join(', ')}`);
        });
    });

    it('serves the entry module from the file the exports map calls register.js', () => {
        const [, viewFile] = /ENTRY_FILE = '([^']+)'/.exec(SCRIPTS_BLOCK);

        assert.equal(
            viewFile.replace('Scr1be_HyvaMegaMenu::', ''),
            PACKAGE.exports['./register.js'].replace('./view/frontend/web/', '')
        );
    });
});
