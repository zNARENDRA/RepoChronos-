// Optional developer checks: node --test tests/section-navigation.test.cjs
// Node is only a test runner; the application remains Laravel and Blade.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const script = readFileSync(require('node:path').join(__dirname, '../public/section-navigation.js'), 'utf8');
const base = 'http://localhost/r/1/';

function element() {
    const attributes = new Map();
    const classes = new Set();
    return {
        attributes, classes, children: [], textContent: '',
        classList: {
            toggle(name, on) { on ? classes.add(name) : classes.delete(name); },
            remove(name) { classes.delete(name); }
        },
        setAttribute(name, value) { attributes.set(name, value); },
        getAttribute(name) { return attributes.get(name) ?? null; },
        removeAttribute(name) { attributes.delete(name); },
        hasAttribute(name) { return attributes.has(name); },
        toggleAttribute(name, on) { on ? attributes.set(name, '') : attributes.delete(name); },
        replaceChildren(...children) { this.children = children; },
        append(child) { this.children.push(child); },
        focus() { this.focused = true; }
    };
}

function browser() {
    const links = ['overview', 'history', 'analysis', 'compare', 'bugs', 'sql'].map(tab =>
        Object.assign(element(), { href: base + tab, textContent: tab, target: '' }));
    const navigation = Object.assign(element(), {
        dataset: { repository: '1' }, querySelectorAll: () => links,
        addEventListener(name, handler) { this[name] = handler; }
    });
    const content = element();
    const status = element();
    const events = {};
    const requests = [];
    const history = [];
    const timers = new Map();
    const location = {
        href: base + 'overview', origin: 'http://localhost',
        assign(url) { this.assigned = url; }, reload() { this.reloaded = true; }
    };
    const document = {
        querySelector: selector => selector.startsWith('.section-nav') ? navigation : status,
        getElementById: () => content, createElement: () => element()
    };
    class DOMParser {
        parseFromString(text) {
            const tab = JSON.parse(text);
            const next = Object.assign(element(), { childNodes: [tab] });
            next.setAttribute('aria-label', tab);
            return {
                title: tab,
                getElementById: () => next,
                querySelector: () => ({ dataset: { repository: '1' },
                    querySelector: () => ({ getAttribute: () => '/r/1/' + tab }) })
            };
        }
    }
    runInNewContext(script, {
        document, location, DOMParser, URL, AbortController,
        window: { addEventListener(name, handler) { events[name] = handler; } },
        history: { pushState(_state, _title, url) { history.push(url.href); location.href = url.href; } },
        setTimeout(handler) { const id = Symbol(); timers.set(id, handler); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, { signal }) {
            return new Promise((resolve, reject) => {
                requests.push({ url: url.href, resolve, reject });
                signal.addEventListener('abort', () => reject(new Error('Timed out')));
            });
        }
    });
    return {
        links, content, status, requests, history, location, timers,
        click(tab, extra = {}) {
            let prevented = false;
            navigation.click({ target: { closest: () => links.find(link => link.href === base + tab) },
                button: 0, preventDefault() { prevented = true; }, ...extra });
            return prevented;
        },
        pop(tab) { location.href = base + tab; events.popstate(); },
        reply(index, tab, extra = {}) {
            requests[index].resolve({ ok: true, redirected: false, text: async () => JSON.stringify(tab), ...extra });
        }
    };
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('switches content, active tab and URL without document navigation', async () => {
    const b = browser();
    b.click('history');
    assert.match(b.status.textContent, /Opening history/);
    assert.equal(b.content.getAttribute('aria-busy'), 'true');
    b.reply(0, 'history'); await settle();
    assert.deepEqual(b.content.children, ['history']);
    assert.equal(b.links[1].getAttribute('aria-current'), 'page');
    assert.equal(b.history[0], base + 'history');
    assert.equal(b.location.assigned, undefined);
    assert.equal(b.content.hasAttribute('aria-busy'), false);
});

test('rapid clicks keep one request in flight and only display the latest section', async () => {
    const b = browser();
    b.click('history'); b.click('analysis'); b.click('bugs');
    assert.equal(b.requests.length, 1);
    b.reply(0, 'history'); await settle();
    assert.equal(b.requests.length, 2);
    assert.equal(b.requests[1].url, base + 'bugs');
    assert.equal(b.history.length, 0);
    b.reply(1, 'bugs'); await settle();
    assert.deepEqual(b.content.children, ['bugs']);
    assert.deepEqual(b.history, [base + 'bugs']);
});

test('returning to the current section cancels queued navigation and clears loading', async () => {
    const b = browser();
    b.click('history'); b.click('overview');
    b.reply(0, 'history'); await settle();
    assert.equal(b.requests.length, 1);
    assert.equal(b.history.length, 0);
    assert.equal(b.status.classes.has('visually-hidden'), true);
    assert.equal(b.content.hasAttribute('aria-busy'), false);
});

test('Back and Forward restore content without adding history entries', async () => {
    const b = browser();
    b.click('bugs'); b.reply(0, 'bugs'); await settle();
    b.pop('overview'); b.reply(1, 'overview'); await settle();
    assert.deepEqual(b.content.children, ['overview']);
    b.pop('bugs'); b.reply(2, 'bugs'); await settle();
    assert.deepEqual(b.content.children, ['bugs']);
    assert.equal(b.history.length, 1);
});

test('failed requests show a direct link, release the content and allow retry', async () => {
    const b = browser();
    b.click('sql'); b.reply(0, 'sql', { ok: false }); await settle();
    assert.match(b.status.textContent, /could not load/);
    assert.equal(b.status.children[0].href, base + 'sql');
    assert.equal(b.content.hasAttribute('aria-busy'), false);
    b.click('sql'); b.reply(1, 'sql'); await settle();
    assert.deepEqual(b.content.children, ['sql']);
});

test('stalled requests time out instead of leaving the interface blocked', async () => {
    const b = browser(); b.click('compare');
    for (const timeout of b.timers.values()) timeout();
    await settle();
    assert.match(b.status.textContent, /could not load/);
    assert.equal(b.content.hasAttribute('aria-busy'), false);
    assert.equal(b.timers.size, 0);
});

test('expired sessions follow the login redirect and modified clicks stay native', async () => {
    const b = browser();
    assert.equal(b.click('history', { ctrlKey: true }), false);
    assert.equal(b.requests.length, 0);
    b.click('history'); b.reply(0, 'history', { redirected: true, url: 'http://localhost/login' });
    await settle();
    assert.equal(b.location.assigned, 'http://localhost/login');
});
