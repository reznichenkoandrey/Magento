/**
 * A DOM small enough to read, faithful enough to pin a contract.
 *
 * The adapter under test is the file that touches real elements, and testing it needs something
 * for it to touch. A browser-grade DOM implementation would be an npm dependency, and this module
 * deliberately has none: it ships inside a Magento module, is installed by Composer, and a package
 * a merchant has to `npm install` before the specs run is a package nobody runs the specs on.
 *
 * So this implements exactly the surface `mega-menu-register.js` uses and nothing else —
 * attribute selectors, `dataset`, `classList.toggle`, `closest`, `appendChild`, `style.setProperty`
 * and `textContent`. Anything the adapter starts using that is missing here fails loudly as an
 * undefined method rather than passing quietly, which is the property that matters: the double is
 * allowed to be incomplete, it is not allowed to be lenient.
 */

const ATTRIBUTE_SELECTOR = /^\[([a-z-]+)(?:="([^"]*)")?\]$/;

const toAttributeName = (datasetKey) =>
    'data-' + datasetKey.replace(/[A-Z]/g, (character) => '-' + character.toLowerCase());

const createDataset = (element) =>
    new Proxy(
        {},
        {
            get: (_target, key) => {
                if (typeof key !== 'string') {
                    return undefined;
                }

                const value = element.getAttribute(toAttributeName(key));

                return value === null ? undefined : value;
            },
            set: (_target, key, value) => {
                element.setAttribute(toAttributeName(key), String(value));

                return true;
            },
            has: (_target, key) =>
                typeof key === 'string' && element.getAttribute(toAttributeName(key)) !== null,
        }
    );

class ClassList {
    constructor(element) {
        this.element = element;
    }

    values() {
        return new Set((this.element.getAttribute('class') ?? '').split(/\s+/).filter(Boolean));
    }

    contains(name) {
        return this.values().has(name);
    }

    toggle(name, force) {
        const classes = this.values();
        const shouldBeOn = force === undefined ? !classes.has(name) : Boolean(force);

        if (shouldBeOn) {
            classes.add(name);
        } else {
            classes.delete(name);
        }

        this.element.setAttribute('class', Array.from(classes).join(' '));

        return shouldBeOn;
    }
}

class FakeStyle {
    constructor() {
        this.properties = new Map();
    }

    setProperty(name, value) {
        this.properties.set(name, String(value));
    }

    getPropertyValue(name) {
        return this.properties.get(name) ?? '';
    }
}

class FakeElement {
    constructor(document, tagName, namespaceURI = null) {
        this.ownerDocument = document;
        this.tagName = tagName;
        this.namespaceURI = namespaceURI;
        this.attributes = new Map();
        this.childNodes = [];
        this.parentNode = null;
        this.style = new FakeStyle();
        this.classList = new ClassList(this);
        this.dataset = createDataset(this);
        this.ownText = '';
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    appendChild(child) {
        if (child.parentNode !== null) {
            child.parentNode.removeChild(child);
        }

        child.parentNode = this;
        this.childNodes.push(child);

        return child;
    }

    removeChild(child) {
        this.childNodes = this.childNodes.filter((node) => node !== child);
        child.parentNode = null;

        return child;
    }

    get children() {
        return this.childNodes;
    }

    set textContent(value) {
        this.childNodes.forEach((child) => {
            child.parentNode = null;
        });
        this.childNodes = [];
        this.ownText = String(value);
    }

    get textContent() {
        return this.ownText + this.childNodes.map((child) => child.textContent).join('');
    }

    matches(selector) {
        const parsed = ATTRIBUTE_SELECTOR.exec(selector);

        if (parsed === null) {
            throw new Error('The DOM double only understands attribute selectors, got: ' + selector);
        }

        const [, name, value] = parsed;
        const actual = this.getAttribute(name);

        return actual !== null && (value === undefined || actual === value);
    }

    closest(selector) {
        let node = this;

        while (node !== null) {
            if (node.matches(selector)) {
                return node;
            }

            node = node.parentNode;
        }

        return null;
    }

    descendants() {
        return this.childNodes.flatMap((child) => [child, ...child.descendants()]);
    }

    querySelectorAll(selector) {
        return this.descendants().filter((node) => node.matches(selector));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }
}

class FakeDocument {
    constructor() {
        this.documentElement = new FakeElement(this, 'html');
    }

    createElement(tagName) {
        return new FakeElement(this, tagName);
    }

    createElementNS(namespaceURI, tagName) {
        return new FakeElement(this, tagName, namespaceURI);
    }
}

export const createDocument = () => new FakeDocument();

/**
 * Builds an element tree from a compact description, so a spec can say what the markup means
 * instead of assembling it node by node.
 */
export const element = (document, tagName, attributes = {}, children = []) => {
    const node = document.createElement(tagName);

    Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
    children.forEach((child) => node.appendChild(child));

    return node;
};

class FakeMediaQueryList {
    constructor(media, matches) {
        this.media = media;
        this.matches = matches;
        this.listeners = [];
    }

    addEventListener(type, listener) {
        if (type === 'change') {
            this.listeners.push(listener);
        }
    }

    /** Test-side only: what a viewport change looks like from the component's point of view. */
    emit(matches) {
        this.matches = matches;
        this.listeners.forEach((listener) => listener({ matches }));
    }
}

/**
 * @param {object} options
 * @param {Record<string, boolean>} options.media  media query string => whether it matches
 * @param {Record<string, string>} [options.customProperties]  what getComputedStyle reports on <html>
 */
export const createWindow = ({ media = {}, customProperties = {} } = {}) => {
    const document = createDocument();
    const mediaQueryLists = new Map();

    return {
        document,
        matchMedia(query) {
            if (!mediaQueryLists.has(query)) {
                mediaQueryLists.set(query, new FakeMediaQueryList(query, media[query] ?? false));
            }

            return mediaQueryLists.get(query);
        },
        getComputedStyle(node) {
            if (node !== document.documentElement) {
                throw new Error('The menu only ever reads custom properties off <html>');
            }

            return { getPropertyValue: (name) => customProperties[name] ?? '' };
        },
        /** Test-side only. */
        mediaQueryList: (query) => mediaQueryLists.get(query),
    };
};
