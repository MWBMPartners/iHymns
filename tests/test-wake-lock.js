/**
 * tests/test-wake-lock.js — the screen stays awake while it should, and only while it should (#2079)
 *
 * ELI5
 * ----
 * Checks that the code asking the browser to keep the screen on behaves: it asks
 * when something needs it, it lets go when nothing does, it copes with the
 * browser saying no, and it asks again after the page has been away.
 *
 * WHY EACH CHECK IS HERE
 * ----------------------
 * The re-request after the page becomes visible again is the one that matters
 * most. A wake lock is dropped automatically whenever a page is hidden — which
 * includes an operator glancing at a message mid-service — and it is NOT given
 * back on return. Without that listener the screen would quietly start sleeping
 * again for the rest of the service, with nothing on screen to say why. A test
 * that only covered acquire and release would pass while that hole was open.
 *
 * The counting also matters: presentation mode can be opened from inside a set
 * list, so two parts of the app can want the screen awake at once. If the first
 * one to finish released the lock outright, the screen would sleep while a
 * projector was still showing a song.
 */
import assert from 'node:assert';

let passed = 0, failed = 0;
function check(name, fn) {
    try { fn(); console.log('  PASS  ' + name); passed++; }
    catch (e) { console.log('  FAIL  ' + name + '\n        ' + e.message); failed++; }
}

/* A stand-in for the browser's wake-lock support, so the real behaviour can be
   exercised without a browser. `grant` decides whether the request succeeds. */
function installFakeBrowser({ grant = true } = {}) {
    const state = { requests: 0, releases: 0, listeners: {} };
    globalThis.document = {
        visibilityState: 'visible',
        addEventListener(type, fn) { state.listeners[type] = fn; },
    };
    /* Node 26 exposes `navigator` as a getter-only global, so a plain
       assignment throws. defineProperty replaces it outright, which is what a
       test double needs. */
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true, writable: true,
        value: { wakeLock: {
            async request() {
                state.requests++;
                if (!grant) throw new Error('refused');
                const lock = {
                    released: false,
                    addEventListener(type, fn) { if (type === 'release') lock._onRelease = fn; },
                    async release() { state.releases++; lock.released = true; },
                };
                state.lock = lock;
                return lock;
            },
        } },
    });
    return state;
}

async function main() {
    console.log('\nScreen wake lock (#2079)\n');

    /* Fresh import per scenario: the module holds state at module scope, so the
       cache must be bypassed or the second test inherits the first's counters. */
    const load = (n) => import('../appWeb/public_html/js/utils/wake-lock.js?case=' + n);

    let st = installFakeBrowser();
    let m = await load(1);
    await m.acquireWakeLock();
    check('asks the browser for a lock when something needs the screen awake',
        () => assert.strictEqual(st.requests, 1));
    check('reports that a lock is held', () => assert.strictEqual(m._wakeLockHeldForTests(), true));
    await m.releaseWakeLock();
    check('lets go when nothing needs it any more', () => assert.strictEqual(st.releases, 1));
    check('reports that no lock is held', () => assert.strictEqual(m._wakeLockHeldForTests(), false));

    st = installFakeBrowser();
    m = await load(2);
    await m.acquireWakeLock();
    await m.acquireWakeLock();
    await m.releaseWakeLock();
    check('two things wanting the screen awake: the first to finish does NOT release it',
        () => assert.strictEqual(st.releases, 0));
    await m.releaseWakeLock();
    check('...and the last one to finish does', () => assert.strictEqual(st.releases, 1));

    st = installFakeBrowser({ grant: false });
    m = await load(3);
    let threw = false;
    try { await m.acquireWakeLock(); } catch { threw = true; }
    check('a browser refusing the request does not throw at the caller',
        () => assert.strictEqual(threw, false));
    check('...and nothing is recorded as held', () => assert.strictEqual(m._wakeLockHeldForTests(), false));

    st = installFakeBrowser();
    m = await load(4);
    await m.acquireWakeLock();
    const before = st.requests;
    /* What a real browser does: hiding the page releases the lock. The page
       coming back does NOT restore it — that is the whole reason the module
       listens for this. */
    st.lock.released = true;
    globalThis.document.visibilityState = 'visible';
    await st.listeners.visibilitychange();
    check('THE IMPORTANT ONE: asks again when the page comes back into view',
        () => assert.ok(st.requests > before,
            'no second request — the screen would silently start sleeping again after the '
            + 'operator glanced at another app, for the rest of the service'));

    st = installFakeBrowser();
    m = await load(5);
    globalThis.document.visibilityState = 'visible';
    await st.listeners.visibilitychange();
    check('does NOT ask when nothing wanted the screen awake in the first place',
        () => assert.strictEqual(st.requests, 0));

    Object.defineProperty(globalThis, 'navigator', { configurable: true, writable: true, value: {} });
    m = await load(6);
    threw = false;
    try { await m.acquireWakeLock(); await m.releaseWakeLock(); } catch { threw = true; }
    check('a browser with no support at all is simply a no-op',
        () => assert.strictEqual(threw, false));

    console.log('\n=== ' + (passed + failed) + ' checks, ' + failed + ' failed ===');
    process.exit(failed ? 1 : 0);
}
main();
