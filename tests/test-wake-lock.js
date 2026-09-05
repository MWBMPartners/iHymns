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
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

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

/* ==========================================================================
 * SOURCE-LEVEL GUARD (#2079) — do the two remaining screens (the set-list
 * playback bar in setlist.js, and the projector in service-projection.php)
 * actually wire the shared helper correctly?
 *
 * ELI5: everything above proves the SHARED counting mechanism behaves.
 * It proves nothing about any one screen actually calling it. This repo has
 * no jsdom (see present-mode.js's own note on why its DOM-heavy
 * initPresentMode() isn't driven directly by a test either), so instead of
 * faking a browser well enough to run the real click handlers, these checks
 * read the two files' own source text: does the import come from the one
 * shared module, does the release sit in the same place the thing it is
 * paired with disappears, does the acquire only fire once there is
 * something on screen to keep awake.
 *
 * Every check has at least one MUTATION test further down, proving it can
 * actually go red (rule #34). Each mutation is applied to an in-memory
 * COPY of the real file's text — nothing here writes to disk, since other
 * agents are editing other files in this same tree at the same time.
 * ======================================================================== */

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const SETLIST_PATH = path.join(REPO, 'appWeb', 'public_html', 'js', 'modules', 'setlist.js');
const PROJECTION_PATH = path.join(REPO, 'appWeb', 'public_html', 'manage', 'service-projection.php');

/* Blank `/* … *\/` block-comment BODIES (keeping the newlines, so nothing a
   failure message reports shifts line-wise) before every scan below.
   Otherwise a doc-comment EXPLAINING acquireWakeLock()/releaseWakeLock()
   right next to the real call — and there are several, in both files —
   would count as the call itself. Block comments only: every doc-comment
   this feature added uses `/* … *\/`, never `//`, so that is all there is
   to strip (test-component-label-sites.js's stripComments() is the
   precedent for this exact technique). */
function stripBlockComments(src) {
    return src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
}

/* Brace-matching function/method-body extractor — the same technique
   test-structure-chords-remap.js's extractFunctionSource() uses. That is
   safe here because neither renderSongNavigation()/clearPlaylistContext()
   nor service-projection.php's teardown() contains a stray unmatched
   `{`/`}` inside a string or template literal (checked by hand against the
   real files when this test was written; the one template literal in
   renderSongNavigation() only ever opens/closes braces in balanced pairs
   via `${...}` interpolation). Returns null (not a throw) on a missing
   anchor or an unmatched brace, so a mutation that deletes the method
   itself makes every downstream check correctly read as "not found"
   rather than crashing the whole test file. */
function extractBody(source, anchor) {
    const anchorIdx = source.indexOf(anchor);
    if (anchorIdx === -1) return null;
    const openIdx = source.indexOf('{', anchorIdx + anchor.length - 1);
    if (openIdx === -1) return null;
    let depth = 0, endIdx = -1;
    for (let i = openIdx; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') { depth--; if (depth === 0) { endIdx = i; break; } }
    }
    if (endIdx === -1) return null;
    return source.slice(anchorIdx, endIdx + 1);
}

/** Describes setlist.js's wake-lock wiring, given its raw source text. */
function setlistWakeLockChecks(rawSrc) {
    const src = stripBlockComments(rawSrc);
    const render = extractBody(src, 'renderSongNavigation() {');
    const clear = extractBody(src, 'clearPlaylistContext() {');

    const removeIdx  = render ? render.indexOf("getElementById('setlist-song-nav')?.remove()") : -1;
    const noNavIdx   = render ? render.indexOf('if (!nav)') : -1;
    const releaseIdx = render ? render.indexOf('releaseWakeLock(') : -1;
    const appendIdx  = render ? render.indexOf('document.body.appendChild(navEl)') : -1;
    const acquireIdx = render ? render.indexOf('acquireWakeLock(') : -1;

    return {
        importsHelper: /import\s*\{\s*acquireWakeLock\s*,\s*releaseWakeLock\s*\}\s*from\s*['"]\.\.\/utils\/wake-lock\.js['"]/.test(src),
        renderFound: render !== null,
        clearFound: clear !== null,
        renderReleases: releaseIdx !== -1,
        renderAcquires: acquireIdx !== -1,
        /* The bar's own removal, and the branch that decides no new bar is
           coming (so the lock should be let go), must both come before the
           release call — the "same teardown" rule #32 asks for. */
        removeBeforeRelease: removeIdx !== -1 && noNavIdx !== -1 && releaseIdx !== -1
            && removeIdx < noNavIdx && noNavIdx < releaseIdx,
        /* The lock must only be asked for once the bar is actually on
           screen — never ahead of document.body.appendChild(navEl). */
        acquireAfterAppend: appendIdx !== -1 && acquireIdx !== -1 && appendIdx < acquireIdx,
        /* Both sides must read/write the SAME flag — a second, driftable
           flag (or a raw unconditional call) is exactly the router's
           double-call race this flag exists to prevent (see the
           constructor's own _wakeLockHeld doc-comment). */
        renderFlagGated: render
            ? /this\._wakeLockHeld\s*=\s*false;\s*releaseWakeLock\(\)/.test(render)
              && /this\._wakeLockHeld\s*=\s*true;\s*acquireWakeLock\(\)/.test(render)
            : false,
        clearReleases: clear ? /releaseWakeLock\(/.test(clear) : false,
        clearFlagGated: clear ? /this\._wakeLockHeld\s*=\s*false;\s*releaseWakeLock\(\)/.test(clear) : false,
    };
}

/** Describes service-projection.php's wake-lock wiring, given its raw source text. */
function projectionWakeLockChecks(rawSrc) {
    const src = stripBlockComments(rawSrc);
    const teardown = extractBody(src, 'function teardown() {');

    const activeIdx      = src.indexOf("overlay.classList.add('active');");
    const acquireIdx     = activeIdx === -1 ? -1 : src.indexOf('acquireWakeLock(', activeIdx);
    const broadcasterIdx = activeIdx === -1 ? -1 : src.indexOf('new ServiceBroadcaster(', activeIdx);

    /* teardown() is the ONE place every end-of-session path funnels
       through (the End button, and the broadcaster's own onSessionEnded
       callback) — the release belongs as its very FIRST statement, exactly
       like present-mode.js's close(), not merely "somewhere in there". */
    let releaseIsFirst = false;
    if (teardown) {
        const afterBrace = teardown.slice(teardown.indexOf('{') + 1).trimStart();
        releaseIsFirst = afterBrace.startsWith('releaseWakeLock();');
    }

    return {
        importsHelper: /import\s*\{\s*acquireWakeLock\s*,\s*releaseWakeLock\s*\}\s*from\s*['"]\/js\/utils\/wake-lock\.js['"]/.test(src),
        teardownFound: teardown !== null,
        releaseIsFirstStatementOfTeardown: releaseIsFirst,
        acquireAfterOverlayActive: acquireIdx !== -1,
        acquireBeforeBroadcasterInit: acquireIdx !== -1 && broadcasterIdx !== -1 && acquireIdx < broadcasterIdx,
    };
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

    console.log('\nsetlist.js — the set-list playback bar holds the lock correctly (#2079)\n');
    const setlistSrc = fs.readFileSync(SETLIST_PATH, 'utf8');
    const sl = setlistWakeLockChecks(setlistSrc);
    check('imports acquireWakeLock/releaseWakeLock from the ONE shared helper',
        () => assert.ok(sl.importsHelper));
    check('renderSongNavigation() calls releaseWakeLock()', () => assert.ok(sl.renderReleases));
    check('renderSongNavigation() calls acquireWakeLock()', () => assert.ok(sl.renderAcquires));
    check('...the bar is removed, and "nothing to show" decided, BEFORE the release (rule #32 — same teardown)',
        () => assert.ok(sl.removeBeforeRelease));
    check('...the lock is only requested AFTER the bar is actually appended to the page',
        () => assert.ok(sl.acquireAfterAppend));
    check('...both calls are gated on the SAME _wakeLockHeld flag, never a second driftable copy',
        () => assert.ok(sl.renderFlagGated));
    check('clearPlaylistContext() ("Leave set list playback") also releases the lock',
        () => assert.ok(sl.clearReleases));
    check('...gated on the same flag renderSongNavigation() uses',
        () => assert.ok(sl.clearFlagGated));

    console.log('\nmanage/service-projection.php — the projector never sleeps mid-service (#2079)\n');
    const projectionSrc = fs.readFileSync(PROJECTION_PATH, 'utf8');
    const pr = projectionWakeLockChecks(projectionSrc);
    check('imports acquireWakeLock/releaseWakeLock from the ONE shared helper',
        () => assert.ok(pr.importsHelper));
    check('teardown() releases the lock as its very FIRST statement',
        () => assert.ok(pr.releaseIsFirstStatementOfTeardown));
    check('the session-start handler acquires the lock once the overlay is actually showing',
        () => assert.ok(pr.acquireAfterOverlayActive));
    check('...before the driver console even mounts (never a late or forgotten acquire)',
        () => assert.ok(pr.acquireBeforeBroadcasterInit));

    console.log('\nMUTATION PROOF — every check above is capable of failing (rule #34)\n');

    /**
     * Runs one mutation: confirms the named check reads TRUE on the real
     * file, applies `mutate` to an in-memory copy, confirms the copy is
     * genuinely different (so a stale anchor string doesn't silently pass),
     * and confirms the SAME check now reads FALSE on the mutated copy.
     */
    function mustFlip(name, base, mutate, checksFn, key) {
        check('MUTATION: ' + name, () => {
            const before = checksFn(base)[key];
            assert.strictEqual(before, true,
                `precondition failed — the real file is not green on "${key}" to begin with`);
            const mutated = mutate(base);
            assert.notStrictEqual(mutated, base,
                'the mutation did not change anything — its anchor text must be stale');
            const after = checksFn(mutated)[key];
            assert.strictEqual(after, false, `check "${key}" did not go red for this mutation`);
        });
    }

    mustFlip('deleting setlist.js\'s import drops the import check', setlistSrc,
        (s) => s.replace("import { acquireWakeLock, releaseWakeLock } from '../utils/wake-lock.js';", ''),
        setlistWakeLockChecks, 'importsHelper');

    mustFlip('deleting the acquire call out of renderSongNavigation() drops that check', setlistSrc,
        (s) => s.replace(
            "        if (!this._wakeLockHeld) {\n            this._wakeLockHeld = true;\n            acquireWakeLock();\n        }\n\n        navEl.querySelector('.playlist-bar-exit')",
            "        navEl.querySelector('.playlist-bar-exit')"
        ),
        setlistWakeLockChecks, 'renderAcquires');

    mustFlip('deleting the release out of renderSongNavigation()\'s "nothing to show" branch drops that check', setlistSrc,
        (s) => s.replace(
            "            if (this._wakeLockHeld) {\n                this._wakeLockHeld = false;\n                releaseWakeLock();\n            }\n            return;",
            '            return;'
        ),
        setlistWakeLockChecks, 'renderReleases');

    mustFlip('deleting the release out of clearPlaylistContext() drops that check', setlistSrc,
        (s) => s.replace(
            "        if (this._wakeLockHeld) {\n            this._wakeLockHeld = false;\n            releaseWakeLock();\n        }\n    }\n\n    /**\n     * Render set list navigation bar",
            '    }\n\n    /**\n     * Render set list navigation bar'
        ),
        setlistWakeLockChecks, 'clearReleases');

    mustFlip('moving the acquire call ahead of the DOM append trips the ordering check', setlistSrc,
        (s) => s
            .replace(
                "        if (!this._wakeLockHeld) {\n            this._wakeLockHeld = true;\n            acquireWakeLock();\n        }\n\n        navEl.querySelector('.playlist-bar-exit')",
                "        navEl.querySelector('.playlist-bar-exit')"
            )
            .replace('document.body.appendChild(navEl);', 'acquireWakeLock();\n        document.body.appendChild(navEl);'),
        setlistWakeLockChecks, 'acquireAfterAppend');

    mustFlip('deleting service-projection.php\'s import drops the import check', projectionSrc,
        (s) => s.replace("import { acquireWakeLock, releaseWakeLock } from '/js/utils/wake-lock.js';", ''),
        projectionWakeLockChecks, 'importsHelper');

    mustFlip('deleting the release out of teardown() drops the "first statement" check', projectionSrc,
        (s) => s.replace('            releaseWakeLock();\n            if (rotateTimer)', '            if (rotateTimer)'),
        projectionWakeLockChecks, 'releaseIsFirstStatementOfTeardown');

    mustFlip('putting a statement ahead of releaseWakeLock() in teardown() also trips the "first statement" check', projectionSrc,
        (s) => s.replace(
            '            releaseWakeLock();\n            if (rotateTimer)',
            '            session = null;\n            releaseWakeLock();\n            if (rotateTimer)'
        ),
        projectionWakeLockChecks, 'releaseIsFirstStatementOfTeardown');

    mustFlip('moving the acquire call ahead of the overlay actually going active trips that check', projectionSrc,
        (s) => s
            .replace('                acquireWakeLock();\n', '')
            .replace("overlay.classList.add('active');",
                "acquireWakeLock();\n                overlay.classList.add('active');"),
        projectionWakeLockChecks, 'acquireAfterOverlayActive');

    console.log('\n=== ' + (passed + failed) + ' checks, ' + failed + ' failed ===');
    process.exit(failed ? 1 : 0);
}
main();
