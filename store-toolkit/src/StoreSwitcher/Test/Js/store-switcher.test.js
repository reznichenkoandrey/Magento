import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import {
    EMPTY_CONFIG,
    buildRedirectUrl,
    buildTargetUrl,
    drawerComponent,
    encodeTargetUrl,
    linksComponent
} from '../../view/frontend/web/js/store-switcher.js';

/** The inverse of Magento\Framework\Url\Decoder::decode(), written out so the spec is the proof. */
const decodeTargetUrl = (value) => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/').replace(/~/g, '=');
    const binary = atob(base64);
    const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));

    return new TextDecoder().decode(bytes);
};

/** A window whose only job is to record where the component tried to send the browser. */
const windowSpy = (href = 'https://shop.test/en/women/tops.html') => {
    const assigned = [];

    return { assigned, location: { href, assign: (url) => assigned.push(url) } };
};

/** The component objects are Alpine data objects; `$refs` is what Alpine would have supplied. */
const withSelectedValue = (component, value) => {
    component.$refs = value === null ? {} : { select: { value } };

    return component;
};

const config = {
    ...EMPTY_CONFIG,
    currentCode: 'en',
    currentBaseUrl: 'https://shop.test/en/',
    redirectUrl: 'https://shop.test/stores/store/redirect/',
    stores: [
        { code: 'en', baseUrl: 'https://shop.test/en/' },
        { code: 'de', baseUrl: 'https://shop.test/de/' },
        { code: 'fr', baseUrl: 'https://fr.shop.test/' }
    ]
};

describe('encodeTargetUrl', () => {
    it('emits none of the three characters Magento maps away', () => {
        // A multi-byte path is what produces "+" and "/" in raw base64, so this input actually
        // exercises the mapping rather than trusting an ASCII URL that never hits it.
        const encoded = encodeTargetUrl('https://shop.test/de/damen/oberteile-größe-38.html?a=1&b=2');

        assert.equal(encoded.includes('+'), false);
        assert.equal(encoded.includes('/'), false);
        assert.equal(encoded.includes('='), false);
    });

    it('round-trips through the mapping core decodes with', () => {
        const url = 'https://shop.test/de/damen/oberteile-größe-38.html?a=1&b=2';

        assert.equal(decodeTargetUrl(encodeTargetUrl(url)), url);
    });

    it('uses ~ for padding rather than dropping it', () => {
        // "a" is one byte, so base64 pads with two "=", which must arrive as two "~". Dropping
        // padding instead of mapping it is the slip that makes core decode to an empty string.
        assert.equal(encodeTargetUrl('a'), 'YQ~~');
        assert.equal(decodeTargetUrl('YQ~~'), 'a');
    });

    it('encodes non-ASCII as UTF-8 bytes, not as code units', () => {
        // The byte loop exists for exactly this: charCodeAt on a multi-byte character would
        // produce a value btoa refuses.
        assert.equal(decodeTargetUrl(encodeTargetUrl('größe')), 'größe');
    });
});

describe('buildTargetUrl', () => {
    it('returns null for a store code that is not in the payload', () => {
        assert.equal(buildTargetUrl(config, 'es', 'https://shop.test/en/women/tops.html'), null);
    });

    it('carries the current path across to the target store', () => {
        assert.equal(
            buildTargetUrl(config, 'de', 'https://shop.test/en/women/tops.html'),
            'https://shop.test/de/women/tops.html'
        );
    });

    it('subtracts the base URL rather than reading the path, so the leaving store code is dropped', () => {
        // The point of the whole function: /en/ belongs to the store being left. Using
        // location.pathname here would carry it into the target and 404.
        const target = buildTargetUrl(config, 'fr', 'https://shop.test/en/women/tops.html');

        assert.equal(target, 'https://fr.shop.test/women/tops.html');
        assert.equal(target.includes('/en/'), false);
    });

    it('degrades to the store home page when the href does not start with the base URL', () => {
        // A host alias or an edge-rewritten URL: guessing a path here would be worse than landing
        // the visitor on a page that certainly exists.
        assert.equal(
            buildTargetUrl(config, 'de', 'https://alias.example/en/women/tops.html'),
            'https://shop.test/de/'
        );
    });

    it('treats an absent stores list as no match rather than throwing', () => {
        assert.equal(buildTargetUrl({ ...EMPTY_CONFIG }, 'de', 'https://shop.test/'), null);
    });
});

describe('buildRedirectUrl', () => {
    it('sets the three parameters core reads, under the names the config supplies', () => {
        const url = new URL(buildRedirectUrl(config, 'de', 'https://shop.test/en/women/tops.html'));

        assert.equal(url.searchParams.get('___store'), 'de');
        assert.equal(url.searchParams.get('___from_store'), 'en');
        assert.equal(
            decodeTargetUrl(url.searchParams.get('uenc')),
            'https://shop.test/de/women/tops.html'
        );
    });

    it('honours renamed parameters instead of hard-coding core defaults', () => {
        const renamed = { ...config, storeParam: 's', fromStoreParam: 'f', targetUrlParam: 't' };
        const url = new URL(buildRedirectUrl(renamed, 'de', 'https://shop.test/en/'));

        assert.equal(url.searchParams.get('s'), 'de');
        assert.equal(url.searchParams.get('f'), 'en');
        assert.notEqual(url.searchParams.get('t'), null);
        assert.equal(url.searchParams.get('___store'), null);
    });

    it('returns null when there is no redirect controller URL to build on', () => {
        assert.equal(buildRedirectUrl({ ...config, redirectUrl: '' }, 'de', 'https://shop.test/en/'), null);
    });

    it('returns null when the target cannot be resolved', () => {
        assert.equal(buildRedirectUrl(config, 'es', 'https://shop.test/en/'), null);
    });
});

describe('linksComponent', () => {
    it('navigates to the finished URL the desktop option already carries', () => {
        const win = windowSpy();
        const component = withSelectedValue(linksComponent(win), 'https://shop.test/stores/store/redirect/?x=1');

        component.switchStore();

        assert.deepEqual(win.assigned, ['https://shop.test/stores/store/redirect/?x=1']);
    });

    it('does nothing when the placeholder option is selected', () => {
        const win = windowSpy();

        withSelectedValue(linksComponent(win), '').switchStore();

        assert.deepEqual(win.assigned, []);
    });

    it('does nothing when the ref is missing rather than throwing', () => {
        const win = windowSpy();

        withSelectedValue(linksComponent(win), null).switchStore();

        assert.deepEqual(win.assigned, []);
    });
});

describe('drawerComponent', () => {
    it('composes the redirect from the store code and navigates to it', () => {
        const win = windowSpy('https://shop.test/en/women/tops.html');

        withSelectedValue(drawerComponent(config, win), 'de').switchStore();

        assert.equal(win.assigned.length, 1);

        const url = new URL(win.assigned[0]);

        assert.equal(url.searchParams.get('___store'), 'de');
        assert.equal(
            decodeTargetUrl(url.searchParams.get('uenc')),
            'https://shop.test/de/women/tops.html'
        );
    });

    it('does not navigate when the visitor picks the store they are already in', () => {
        const win = windowSpy();

        withSelectedValue(drawerComponent(config, win), 'en').switchStore();

        assert.deepEqual(win.assigned, []);
    });

    it('does not navigate when no redirect can be built', () => {
        const win = windowSpy();

        withSelectedValue(drawerComponent({ ...config, redirectUrl: '' }, win), 'de').switchStore();

        assert.deepEqual(win.assigned, []);
    });

    it('does not navigate for a store code that is not in the payload', () => {
        const win = windowSpy();

        withSelectedValue(drawerComponent(config, win), 'es').switchStore();

        assert.deepEqual(win.assigned, []);
    });

    it('reads the href at click time, so a pushState navigation is respected', () => {
        const win = windowSpy('https://shop.test/en/women/tops.html');
        const component = withSelectedValue(drawerComponent(config, win), 'de');

        win.location.href = 'https://shop.test/en/men/shoes.html';
        component.switchStore();

        assert.equal(
            decodeTargetUrl(new URL(win.assigned[0]).searchParams.get('uenc')),
            'https://shop.test/de/men/shoes.html'
        );
    });
});
