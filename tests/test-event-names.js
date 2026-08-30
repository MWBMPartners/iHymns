/**
 * iHymns — Event-Name Unification Guard (#1581)
 *
 * ELI5: this test walks every JS file the browser loads and makes sure
 * nobody spells a custom event's name out by hand any more — everybody
 * has to `import` it from js/constants.js, the one place the name is
 * written down. It also checks that every name IN constants.js is
 * actually used by at least one dispatcher and one listener, so a typo'd
 * or orphaned constant gets caught immediately instead of silently
 * doing nothing (exactly how `iHymns:language-filter-changed` vs
 * `ihymns:language-filter-changed` broke Song of the Day for however
 * long nobody noticed — DOM event types are case-sensitive strings, so
 * two differently-cased spellings are two entirely different events
 * with no error, no warning, nothing).
 * https://developer.mozilla.org/docs/Web/API/EventTarget/addEventListener
 *
 * Detail: two independent assertions, both of which must be provably
 * capable of failing (a test that can't fail is worthless — this is a
 * hard project rule; see the #1581 fail-proof run recorded in the PR).
 *
 *   1. LITERAL BAN — scans every appWeb/public_html/js/**\/*.js file
 *      (except constants.js itself, which is where the literals are
 *      SUPPOSED to live) for anything shaped like a quoted
 *      'ihymns:something' / 'iHymns:something' string. Two narrow,
 *      explicitly documented exceptions are allow-listed below — see
 *      LITERAL_ALLOWLIST — everything else is a regression.
 *
 *   2. CROSS-REFERENCE — for every EVT_* export in constants.js, walks
 *      the same tree looking for it used as the literal first argument
 *      to addEventListener(...) (a listener) and to
 *      dispatchEvent(new CustomEvent(...)) / dispatchEvent(new Event(...))
 *      (a dispatcher). Every name needs >=1 of each, UNLESS it's in
 *      ONE_SIDED_ALLOWLIST — and that allowlist is itself asserted
 *      to still match reality (see the self-cleaning check below),
 *      mirroring the precedent in tests/php/test-fragment-inline-scripts.php.
 *
 *   3. FULL-CORPUS PAIRING + VOCABULARY (silent-wiring sweep, epic #2008) —
 *      assertions 1-2 above are scoped to `ihymns:*` names inside
 *      `appWeb/public_html/js/**` only. This assertion widens to the SAME
 *      corpus the sibling test-dom-target-integrity.js /
 *      test-wiring-attr-integrity.js scan (every *.js under public_html
 *      plus every inline <script> body in every .php page) and checks TWO
 *      more things that had zero guard before: any non-native custom event
 *      dispatched with no listener anywhere, and — the part that catches a
 *      typo like `shown.bs.tabs` — that every LISTENED name is a
 *      recognised native DOM event, a documented Bootstrap 5.3 event, an
 *      already-validated EVT_* constant, or has a dispatcher somewhere in
 *      the corpus. An unrecognised name FAILS outright rather than being
 *      skipped, so the vocabulary tables cannot silently shrink this
 *      assertion's coverage.
 *
 * USAGE:
 *   node tests/test-event-names.js
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const JS_ROOT    = path.resolve(__dirname, '..', 'appWeb', 'public_html', 'js');

/* The one file that's ALLOWED to spell the raw 'ihymns:x' strings — it's
   where every other file gets its EVT_* constant from. */
const CONSTANTS_FILE = path.join(JS_ROOT, 'constants.js');

/**
 * Recursively collect every *.js file under `dir`. Plain manual walk
 * (rather than fs.readdirSync's `recursive` option) so this keeps
 * working regardless of exact Node minor version — matches the style
 * of the existing tests/test-*.js scripts, which avoid relying on
 * very-new API surface.
 */
function collectJsFiles(dir) {
    const out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            out.push(...collectJsFiles(full));
        } else if (entry.isFile() && entry.name.endsWith('.js')) {
            out.push(full);
        }
    }
    return out;
}

const allFiles = collectJsFiles(JS_ROOT);

let passed = 0;
let failed = 0;
const failures = [];

function check(name, ok, detail) {
    if (ok) {
        passed++;
        console.log(`  PASS  ${name}`);
    } else {
        failed++;
        failures.push({ name, detail });
        console.log(`  FAIL  ${name}`);
        if (detail) console.log(`        ${detail}`);
    }
}

/* ======================================================================
 * Assertion 1 — literal ban
 * ==================================================================== */
console.log('Assertion 1 — no raw ihymns:*/iHymns:* string literals outside constants.js:');

/* The regex from the #1581 spec: a quote char, 'i', h-or-H, 'ymns:',
   then an event-name tail, then a matching quote char. Deliberately
   case-flexible on the 'h' so it ALSO catches the historical capital-H
   typo this whole test exists to prevent from ever coming back.
   L7 (adversarial-review finding, OBS-VERIFY.md): the tail class was
   originally `[a-z][a-z-]*` — lowercase letters and hyphens only — which
   misses a camelCase or digit tail (e.g. 'ihymns:languageFilterChanged',
   'ihymns:refresh2'); every existing EVT_* name in constants.js happens
   to be all-lowercase-hyphenated today, so this was a live blind spot,
   not a hypothetical one — a future name in either shape would sail
   straight through this ban undetected. Widened to
   `[A-Za-z0-9][A-Za-z0-9-]*` (a leading alphanumeric, then any run of
   alphanumerics/hyphens) so a mixed-case or digit-bearing tail is caught
   the same way. https://developer.mozilla.org/docs/Web/JavaScript/Guide/Regular_expressions/Character_classes */
const LITERAL_RE = /(['"`])i[hH]ymns:([A-Za-z0-9][A-Za-z0-9-]*)\1/g;

/* Narrow, documented exceptions — NOT event names that got missed,
   but two genuinely different things a plain regex can't tell apart
   from an event-type string:
   - bulk-import-progress.js's STORAGE_KEY uses the same `ihymns:`
     colon-namespacing convention for a sessionStorage key, not a
     CustomEvent type. It never touches addEventListener/dispatchEvent.
   - song-media-editor.js's 'iHymns:song-loaded' pair is explicitly
     OUT OF SCOPE for #1581 (see the file's own doc-block): its
     dispatch side, manage/editor/editor.js, is a classic script with
     zero import/export and cannot import constants.js. Migrating only
     the listener would still leave two files free to disagree — worse
     than today, where at least the (undocumented) pair matches. */
const LITERAL_ALLOWLIST = [
    { file: 'modules/bulk-import-progress.js', literal: 'ihymns:bulk-import-active-job' },
    { file: 'modules/song-media-editor.js',     literal: 'iHymns:song-loaded' },
];
/* Hit counter per allowlist entry, used below for the self-cleaning check. */
const allowlistHitCount = LITERAL_ALLOWLIST.map(() => 0);

const literalViolations = [];
for (const file of allFiles) {
    if (file === CONSTANTS_FILE) continue; /* the one legitimate home for these literals */
    const relPath = path.relative(JS_ROOT, file).split(path.sep).join('/');
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    lines.forEach((line, idx) => {
        LITERAL_RE.lastIndex = 0;
        let m;
        while ((m = LITERAL_RE.exec(line)) !== null) {
            /* Strip the captured surrounding quote char so the matched
               text (with its ORIGINAL casing) compares byte-for-byte
               against the allowlist below. */
            const matchedText = m[0].slice(1, -1);
            const allowIdx = LITERAL_ALLOWLIST.findIndex(
                a => relPath.endsWith(a.file) && matchedText === a.literal
            );
            if (allowIdx !== -1) {
                allowlistHitCount[allowIdx]++;
                continue;
            }
            literalViolations.push(`${relPath}:${idx + 1}: ${JSON.stringify(matchedText)}`);
        }
    });
}

check(
    'no un-allow-listed raw ihymns:*/iHymns:* literal outside constants.js',
    literalViolations.length === 0,
    literalViolations.length ? `${literalViolations.length} hit(s):\n        ` + literalViolations.join('\n        ') : ''
);

/* Self-cleaning: an allowlist entry that no longer matches ANYTHING is
   stale (the literal it was excusing has been migrated/removed) and
   must be deleted from LITERAL_ALLOWLIST above — otherwise the
   allowlist would quietly grow to cover regressions that happen to
   reuse the same string in the same file. Same discipline as the
   count-exact allowlist in tests/php/test-fragment-inline-scripts.php. */
LITERAL_ALLOWLIST.forEach((entry, i) => {
    check(
        `literal allowlist entry stays live: ${entry.file} :: ${entry.literal}`,
        allowlistHitCount[i] > 0,
        allowlistHitCount[i] === 0 ? 'zero matches found — remove this stale allowlist entry' : ''
    );
});

/* ======================================================================
 * Assertion 2 — cross-reference every EVT_* export
 * ==================================================================== */
console.log('');
console.log('Assertion 2 — every EVT_* constant has >=1 dispatcher and >=1 listener:');

const constantsSrc = fs.readFileSync(CONSTANTS_FILE, 'utf8');
const evtNames = [...constantsSrc.matchAll(/export const (EVT_[A-Z_]+)\s*=/g)].map(m => m[1]);
check('constants.js exports at least one EVT_* name (sanity check on the scanner itself)', evtNames.length > 0);

/* One-sided-by-design names. Per #1581: a listener with no dispatcher
   yet is legitimate future work; a dispatcher with no listener never
   is (nobody would ever observe it, which is itself a bug smell), so
   this allowlist only ever means "listener exists, dispatcher pending".
   The set is COUNT-EXACT and self-cleaning: once a dispatcher appears,
   the entry must be removed or this suite fails. That is deliberate —
   an allowlist nobody is forced to prune silently becomes a list of
   permanently-broken things.

   NOW EMPTY (#1597). `EVT_OFFLINE_SETTINGS_CHANGED` sat here through
   the whole #1581 sweep because it was correctly classified — the
   Settings "include audio offline" toggle had a listener
   (`offline-ui.js`) and no dispatcher, so the preference was inert for
   tile downloads. The guard had not MISSED that dead wire; it had
   recorded it and was waiting. #1597 wired the dispatcher, so the entry
   self-cleaned exactly as designed. Keep this set empty if you can. */
const ONE_SIDED_ALLOWLIST = new Set([]);

/* Concatenate every non-constants.js file into one haystack per name
   check — the dispatch/listen call and the EVT_* identifier always sit
   on the same source line in this codebase (see the #1581 migration),
   so a whitespace-only gap between the call and the identifier is
   sufficient; \s matches newlines too, so this remains correct even if
   a future edit wraps the call across lines. */
const haystack = allFiles
    .filter(f => f !== CONSTANTS_FILE)
    .map(f => fs.readFileSync(f, 'utf8'))
    .join('\n');

for (const name of evtNames) {
    const dispatchRe = new RegExp(`dispatchEvent\\(\\s*new\\s+(?:CustomEvent|Event)\\(\\s*${name}\\b`);
    const listenRe   = new RegExp(`addEventListener\\(\\s*${name}\\b`);
    const dispatchCount = (haystack.match(new RegExp(dispatchRe.source, 'g')) || []).length;
    const listenCount   = (haystack.match(new RegExp(listenRe.source, 'g')) || []).length;

    if (ONE_SIDED_ALLOWLIST.has(name)) {
        /* Allow-listed as listener-only. This must FAIL the moment
           reality changes shape, so the allowlist can never silently
           cover for a different, unrelated problem:
             - zero uses on BOTH sides -> the constant is dead, not one-sided
             - a dispatcher shows up -> no longer one-sided; remove from
               ONE_SIDED_ALLOWLIST and let the normal >=1/>=1 rule apply */
        check(
            `${name} — one-sided allowlist entry still shaped listener-only`,
            listenCount > 0 && dispatchCount === 0,
            `dispatchers=${dispatchCount} listeners=${listenCount} (expected 0 dispatchers, >=1 listener — ` +
            (dispatchCount > 0
                ? 'a dispatcher now exists; remove this name from ONE_SIDED_ALLOWLIST'
                : 'listener is missing; the constant looks dead')
            + ')'
        );
    } else {
        check(
            `${name} — has both a dispatcher and a listener`,
            dispatchCount >= 1 && listenCount >= 1,
            `dispatchers=${dispatchCount} listeners=${listenCount}`
        );
    }
}

/* ======================================================================
 * Assertion 3 — full-corpus custom-event pairing + native/Bootstrap vocabulary
 * (silent-wiring sweep, epic #2008)
 * ==================================================================== */
console.log('');
console.log('Assertion 3 — full-corpus custom-event pairing + native/Bootstrap vocabulary:');

/* Assertions 1-2 own the `ihymns:*`/`iHymns:*` namespace, scoped to
   `appWeb/public_html/js/**`. This assertion is everything else the #1581
   program never looked at: (a) a NON-namespaced CustomEvent dispatched with
   no listener anywhere — nobody would ever observe it, the same "dispatcher
   with no listener never is legitimate" doctrine #1581 already applies to
   EVT_* names; (b) the Bootstrap 5.3 `.bs.` event vocabulary, which today has
   ZERO guard at all — a typo like `shown.bs.tabs` (missing the final `s`)
   fails with no error, no warning, the listener simply never fires; and (c)
   the wider corpus — `manage/**` and every inline `<script>` body — that
   assertions 1-2 do not scan.
   First-pass measurement found zero violations (the #1581 sweep already
   drained this pond); this assertion's value is entirely PREVENTIVE —
   stopping the next `shown.bs.tabs` before it ships silently broken. */

const SCRIPT_TAG_RE = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;

/**
 * String/template-literal/regex-AWARE JS comment stripper — identical to the
 * one in tests/test-dom-target-integrity.js / tests/test-wiring-attr-integrity.js
 * (see that file's header for the full rationale, including the
 * `.replace(/"/g, …)` regex-literal trap a naive quote-tracking walker falls
 * into on a large inline script). Copied rather than imported — each
 * tests/test-*.js file is self-contained per house style.
 */
function stripJsComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    let mode = null; // null | 'sq' | 'dq' | 'tpl' | 'line' | 'block' | 'regex'
    let inCharClass = false;
    let lastSig = '';
    const REGEX_PRECEDERS = new Set(['(', ',', '=', ':', ';', '!', '&', '|', '?', '{', '[', '+', '-', '*', '%', '<', '>', '\n', '']);

    while (i < n) {
        const c  = src[i];
        const c2 = i + 1 < n ? src[i + 1] : '';

        if (mode === 'line') {
            if (c === '\n') { out += '\n'; mode = null; } else { out += ' '; }
            i++; continue;
        }
        if (mode === 'block') {
            if (c === '*' && c2 === '/') { out += '  '; i += 2; mode = null; continue; }
            out += (c === '\n' ? '\n' : ' ');
            i++; continue;
        }
        if (mode === 'sq' || mode === 'dq' || mode === 'tpl') {
            if (c === '\\') { out += c + c2; i += 2; continue; }
            const closer = mode === 'sq' ? '\'' : mode === 'dq' ? '"' : '`';
            out += c;
            if (c === closer) { mode = null; lastSig = closer; }
            i++; continue;
        }
        if (mode === 'regex') {
            if (c === '\\') { out += c + c2; i += 2; continue; }
            if (c === '[') { inCharClass = true; out += c; i++; continue; }
            if (c === ']') { inCharClass = false; out += c; i++; continue; }
            if (c === '/' && !inCharClass) {
                out += c; i++;
                while (i < n && /[a-z]/i.test(src[i])) { out += src[i]; i++; }
                mode = null; lastSig = '/';
                continue;
            }
            if (c === '\n') { mode = null; out += c; i++; continue; }
            out += c; i++; continue;
        }

        if (c === '/' && c2 === '/') { mode = 'line';  out += '  '; i += 2; continue; }
        if (c === '/' && c2 === '*') { mode = 'block'; out += '  '; i += 2; continue; }
        if (c === '/' && REGEX_PRECEDERS.has(lastSig)) { mode = 'regex'; inCharClass = false; out += c; i++; continue; }
        if (c === '\'') { mode = 'sq';  out += c; i++; continue; }
        if (c === '"')  { mode = 'dq';  out += c; i++; continue; }
        if (c === '`')  { mode = 'tpl'; out += c; i++; continue; }
        out += c;
        if (!/\s/.test(c)) lastSig = c;
        i++;
    }
    return out;
}

function walk3(dir, re, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (entry.name === 'vendor' || entry.name === 'node_modules' || entry.name.startsWith('.')) continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) { walk3(full, re, out); }
        else if (re.test(entry.name)) { out.push(full); }
    }
    return out;
}

const PUB_ROOT = path.resolve(JS_ROOT, '..'); // appWeb/public_html
const widenedRel = (p) => path.relative(PUB_ROOT, p).split(path.sep).join('/');

/* WIDENED corpus: every *.js under public_html (not just js/**) plus every
   inline <script> body in every *.php page (same allow-shape as the sibling
   guards — external `src=` scripts and inert JSON islands are skipped). */
const widenedSources = [];
for (const f of walk3(PUB_ROOT, /\.js$/)) {
    widenedSources.push({ file: widenedRel(f), text: fs.readFileSync(f, 'utf8'), lineOffset: 0 });
}
let inlineBlocksScanned = 0;
for (const f of walk3(PUB_ROOT, /\.php$/)) {
    const raw = fs.readFileSync(f, 'utf8');
    const cleanedForTags = raw
        .replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '))
        .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    SCRIPT_TAG_RE.lastIndex = 0;
    let sm;
    while ((sm = SCRIPT_TAG_RE.exec(cleanedForTags)) !== null) {
        const attrs = sm[1];
        if (/\bsrc\s*=/i.test(attrs)) continue;
        if (/type\s*=\s*["']application\/(ld\+)?json["']/i.test(attrs)) continue;
        inlineBlocksScanned++;
        const bodyStart = sm.index + 7 + attrs.length + 1;
        const rawBody = raw.slice(bodyStart, bodyStart + sm[2].length);
        const lineOffset = cleanedForTags.slice(0, bodyStart).split('\n').length - 1;
        widenedSources.push({ file: `${widenedRel(f)} [inline]`, text: rawBody, lineOffset });
    }
}
check(`Assertion 3 scanner extracted a plausible number of inline <script> blocks (${inlineBlocksScanned})`,
    inlineBlocksScanned >= 30, `only found ${inlineBlocksScanned}`);

const dispatched3 = new Map(); /* name -> ["file:line", ...] */
const listened3   = new Map();
for (const s of widenedSources) {
    const stripped = stripJsComments(s.text);
    stripped.split('\n').forEach((line, i) => {
        const loc = `${s.file}:${s.lineOffset + i + 1}`;
        for (const m of line.matchAll(/dispatchEvent\(\s*new\s+(?:CustomEvent|Event)\(\s*['"`]([\w:.-]+)['"`]/g)) {
            if (!dispatched3.has(m[1])) dispatched3.set(m[1], []);
            dispatched3.get(m[1]).push(loc);
        }
        for (const m of line.matchAll(/(?:add|remove)EventListener\(\s*['"`]([\w:.-]+)['"`]/g)) {
            if (!listened3.has(m[1])) listened3.set(m[1], []);
            listened3.get(m[1]).push(loc);
        }
    });
}
check(`Assertion 3 scanner found dispatched event names to check (${dispatched3.size} distinct)`,
    dispatched3.size > 0, `only found ${dispatched3.size}`);
check(`Assertion 3 scanner found listened event names to check (${listened3.size} distinct)`,
    listened3.size > 20, `only found ${listened3.size}`);

/* NATIVE DOM event names — a NOTATION table, not a checklist: an
   unrecognised name in the vocabulary check below FAILS rather than being
   silently skipped (mirrors the KEY_NOTATION discipline in
   tests/test-dom-target-integrity.js), so this table cannot silently shrink
   this assertion's coverage. Sourced from the MDN event reference plus this
   tree's own measured usage (`search` — HTMLInputElement's clear/Enter
   event — was the one name missing from a first draft of this list; add
   here, not as an exception elsewhere, if a genuinely new native event
   shows up).
   https://developer.mozilla.org/docs/Web/Events */
const NATIVE_DOM_EVENTS = new Set([
    'click', 'dblclick', 'change', 'input', 'submit', 'reset', 'invalid',
    'keydown', 'keyup', 'keypress',
    'focus', 'blur', 'focusin', 'focusout',
    'load', 'DOMContentLoaded', 'beforeunload', 'unload', 'pagehide', 'pageshow',
    'scroll', 'resize', 'popstate', 'hashchange', 'visibilitychange', 'languagechange',
    'storage', 'online', 'offline',
    'error', 'message', 'messageerror',
    'open', 'close', 'abort', 'progress', 'loadend', 'loadstart', 'timeout', 'readystatechange',
    'ended', 'play', 'playing', 'pause', 'timeupdate', 'durationchange', 'volumechange',
    'seeking', 'seeked', 'canplay', 'canplaythrough', 'waiting', 'stalled', 'suspend',
    'emptied', 'loadedmetadata', 'loadeddata', 'ratechange', 'cuechange',
    'pointerdown', 'pointerup', 'pointermove', 'pointercancel', 'pointerenter', 'pointerleave', 'pointerover', 'pointerout',
    'mousedown', 'mouseup', 'mousemove', 'mouseenter', 'mouseleave', 'mouseover', 'mouseout',
    'contextmenu', 'wheel',
    'touchstart', 'touchend', 'touchmove', 'touchcancel',
    'dragstart', 'dragend', 'dragover', 'dragenter', 'dragleave', 'drop', 'drag',
    'animationend', 'animationstart', 'animationiteration',
    'transitionend', 'transitionstart', 'transitioncancel', 'transitionrun',
    'selectionchange', 'select', 'search',
    'copy', 'cut', 'paste',
    'compositionstart', 'compositionend', 'compositionupdate',
    'toggle', 'slotchange',
    'fullscreenchange', 'fullscreenerror',
    'rejectionhandled', 'unhandledrejection',
    /* PWA / service-worker */
    'appinstalled', 'beforeinstallprompt', 'controllerchange', 'updatefound', 'statechange',
    'install', 'activate', 'fetch', 'push', 'notificationclick', 'sync', 'periodicsync',
    /* device/gesture (documented in the original scan even though unused today) */
    'gesturestart', 'devicemotion', 'deviceorientation', 'orientationchange', 'freeze', 'resume',
    'securitypolicyviolation', 'connect',
]);

/* Bootstrap 5.3 documented custom-event vocabulary. Built as PHASE x
   COMPONENT combinations (rather than nine hand-typed literals) so a
   currently-unused-but-valid Bootstrap event (`hide.bs.tab`, say) is not a
   false alarm the day someone starts using it — only a name Bootstrap never
   actually documents fails.
   https://getbootstrap.com/docs/5.3/components/modal/#events (and the
   equivalent Events section of each component's own docs page). */
const BOOTSTRAP_COMPONENTS = ['modal', 'dropdown', 'collapse', 'toast', 'tab', 'offcanvas', 'tooltip', 'popover'];
const BOOTSTRAP_PHASES = ['show', 'shown', 'hide', 'hidden', 'hidePrevented'];
const BOOTSTRAP_EVENTS = new Set();
for (const comp of BOOTSTRAP_COMPONENTS) { for (const phase of BOOTSTRAP_PHASES) { BOOTSTRAP_EVENTS.add(`${phase}.bs.${comp}`); } }
/* tooltip/popover also fire `inserted.bs.<component>`. carousel uses its own
   slide/slid phases (not show/shown/hide/hidden); alert uses close/closed. */
BOOTSTRAP_EVENTS.add('inserted.bs.tooltip');
BOOTSTRAP_EVENTS.add('inserted.bs.popover');
BOOTSTRAP_EVENTS.add('slide.bs.carousel');
BOOTSTRAP_EVENTS.add('slid.bs.carousel');
BOOTSTRAP_EVENTS.add('close.bs.alert');
BOOTSTRAP_EVENTS.add('closed.bs.alert');
BOOTSTRAP_EVENTS.add('activate.bs.scrollspy');

const IHYMNS_NS_RE = /^i[hH]ymns:/;

/* --- strict direction: every non-native, non-ihymns:*-namespaced dispatch
   needs >=1 listener SOMEWHERE in this same widened corpus. The ihymns:*
   namespace is excluded here because assertions 1-2 already own it
   completely (every EVT_* name is checked there); re-checking it here would
   just duplicate that work, not add coverage. */
for (const [name, locs] of [...dispatched3].sort()) {
    if (NATIVE_DOM_EVENTS.has(name)) continue;
    if (IHYMNS_NS_RE.test(name)) continue;
    check(`Assertion 3 — dispatched "${name}" has >=1 listener somewhere in the corpus`,
        listened3.has(name),
        `dispatched at ${locs.slice(0, 3).join(', ')}${locs.length > 3 ? ` …+${locs.length - 3}` : ''} but never listened for — nobody would ever observe this event`);
}

/* --- vocabulary direction: every listened name must be recognised —
   native, Bootstrap, an EVT_* constant (already validated by assertion 2),
   or matched by a dispatcher in THIS corpus (covers the wider corpus's own
   custom events, including ihymns:*-namespaced ones dispatched from a
   classic script that cannot import constants.js, e.g. `iHymns:song-loaded`
   in manage/editor/editor.js — see that file's own doc-comment on why it is
   deliberately out of the #1581 migration). An unrecognised name FAILS
   rather than being skipped — that is what catches `shown.bs.tabs`. */
for (const [name, locs] of [...listened3].sort()) {
    const recognised = NATIVE_DOM_EVENTS.has(name)
        || BOOTSTRAP_EVENTS.has(name)
        || evtNames.includes(name)
        || dispatched3.has(name);
    check(`Assertion 3 — listened "${name}" is a recognised event name (native, Bootstrap, EVT_*, or has a dispatcher)`,
        recognised,
        recognised ? '' : `listened at ${locs.slice(0, 3).join(', ')}${locs.length > 3 ? ` …+${locs.length - 3}` : ''} — unrecognised name (typo? add to NATIVE_DOM_EVENTS/BOOTSTRAP_EVENTS if genuinely new, or fix the typo)`);
}

/* ======================================================================
 * Summary
 * ==================================================================== */
console.log('');
console.log(`${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.log('');
    console.log('Failures:');
    for (const f of failures) {
        console.log(`  - ${f.name}`);
        if (f.detail) console.log(`    ${f.detail}`);
    }
}
process.exit(failed === 0 ? 0 : 1);
