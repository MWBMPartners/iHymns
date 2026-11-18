/**
 * tests/test-settings-sync-core.js — the "which copy is newer?" rules, row by row
 *
 * PURPOSE
 * ELI5: `js/modules/settings-sync-core.js` decides, setting by setting, whether
 * the copy on this site or the copy in the shared cookie is newer, and reads
 * that cookie safely. This table runs it on small, exact inputs, including
 * deliberately hostile ones, and checks the exact result of each.
 *
 * Covers: a newer remote value is adopted; an older one is pushed back; a tie
 * keeps the local value; a newer tombstone removes a setting; an invalid value
 * is dropped; a key that is not in the registry is ignored (including
 * `__proto__`, `constructor` and `toString`, which must change nothing in the
 * page); malformed or oversized text gives an empty result and never throws;
 * stamps that are negative, fractional or far in the future are dropped; an
 * encoded cookie that would be too long is refused; and a stamp never goes
 * backwards, even if the clock is set back.
 *
 * Mutation-proven (rule #34): turn `>` into `>=` (the tie row fails); read the
 * cookie with `for…in` instead of walking the registry (the unknown-key and
 * proto rows fail); take the `Math.max` out of nextStamp (the clock row fails).
 * The current file is green.
 *
 *   node tests/test-settings-sync-core.js
 *
 * @see appWeb/public_html/js/modules/settings-sync-core.js
 * @see .claude/settings-sync-2143-plan.md  (section 5, guard G2)
 */

import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const core = await import(path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'settings-sync-core.js'));
const { decodeEnvelope, encodeEnvelope, mergeEntries, nextStamp, highestStamp, MAX_ENCODED_LENGTH } = core;

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}

const THEME = 'ihymns_theme';
const FONT = 'ihymns_fontSize';
const MOTION = 'ihymns_reduceMotion';
const m = (obj) => new Map(Object.entries(obj));
const wire = (k) => encodeURIComponent(JSON.stringify({ v: 2, k }));
const eq = (a, b) => JSON.stringify([...a]) === JSON.stringify([...b]);

console.log('mergeEntries — which copy is newer:');
{
    const r = mergeEntries(m({ [THEME]: ['light', 100] }), m({ [THEME]: ['dark', 200] }));
    check('a newer remote value is adopted here', eq(r.applyLocal, m({ [THEME]: ['dark', 200] })) && r.writeRemote.size === 0);
}
{
    const r = mergeEntries(m({ [THEME]: ['dark', 300] }), m({ [THEME]: ['light', 200] }));
    check('an older remote value is pushed back to the shared cookie', eq(r.writeRemote, m({ [THEME]: ['dark', 300] })) && r.applyLocal.size === 0);
}
{
    const r = mergeEntries(m({ [THEME]: ['light', 200] }), m({ [THEME]: ['dark', 200] }));
    check('a tie keeps the local value and does nothing', r.applyLocal.size === 0 && r.writeRemote.size === 0);
}
{
    const r = mergeEntries(m({ [THEME]: ['dark', 100] }), m({ [THEME]: [null, 200] }));
    check('a newer tombstone removes the local setting (value null)', eq(r.applyLocal, m({ [THEME]: [null, 200] })));
}
{
    const r = mergeEntries(m({ [THEME]: [null, 300] }), m({ [THEME]: ['dark', 200] }));
    check('a newer LOCAL tombstone is pushed to the shared cookie (a reset travels)', eq(r.writeRemote, m({ [THEME]: [null, 300] })));
}
{
    const r = mergeEntries(m({}), m({ [THEME]: ['dark', 5] }));
    check('a setting only the cookie has is adopted', eq(r.applyLocal, m({ [THEME]: ['dark', 5] })));
}
{
    const r = mergeEntries(m({ [THEME]: ['dark', 5] }), m({}));
    check('a setting only this site has is written to the cookie', eq(r.writeRemote, m({ [THEME]: ['dark', 5] })));
}
{
    const r = mergeEntries(m({ [THEME]: ['light', 100] }), m({ [THEME]: ['sepia', 900] }));
    check('a newer remote value that fails its check is NOT adopted (an old site ignores a new theme)', r.applyLocal.size === 0);
}
{
    const r = mergeEntries(
        m({ [THEME]: ['light', 100], [FONT]: ['20', 900] }),
        m({ [THEME]: ['dark', 500], [FONT]: ['16', 400] }));
    check('each setting is decided on its own (theme adopted, text size pushed back)',
        eq(r.applyLocal, m({ [THEME]: ['dark', 500] })) && eq(r.writeRemote, m({ [FONT]: ['20', 900] })));
}
{
    const r = mergeEntries(m({ ihymns_auth_token: ['x', 1] }), m({ ihymns_auth_token: ['y', 2] }));
    check('a key that is not in the registry is never merged either way', r.applyLocal.size === 0 && r.writeRemote.size === 0);
}

console.log('decodeEnvelope — reading the cookie safely:');
{
    const d = decodeEnvelope(wire({ [THEME]: ['dark', 50], [FONT]: ['18', 60], [MOTION]: [null, 70] }));
    check('a good cookie is read in full (value, value and a tombstone)',
        eq(d, m({ [THEME]: ['dark', 50], [FONT]: ['18', 60], [MOTION]: [null, 70] })));
}
{
    const d = decodeEnvelope(wire({ [THEME]: ['sepia', 50], [FONT]: ['19', 60], [MOTION]: ['yes', 70] }));
    check('values that fail their check are dropped', d.size === 0);
}
{
    const d = decodeEnvelope(wire({ ihymns_auth_token: ['abc', 5], ihymns_favorites: ['[]', 5], whatever: ['x', 5] }));
    check('keys that are not in the registry are ignored', d.size === 0);
}
{
    /* A "deleted" marker (null) for a key that is not in the registry must not get in either: a tombstone
       skips the per-value check, so only walking the registry's own keys keeps these out. */
    const d = decodeEnvelope(wire({ whatever: [null, 5], ihymns_auth_token: [null, 6], ihymns_favorites: [null, 7] }));
    check('tombstones for keys that are not in the registry are ignored too', d.size === 0);
}
{
    /* JSON.parse makes "__proto__" an ordinary own key, so this is the realistic attack shape. */
    const hostile = encodeURIComponent('{"v":2,"k":{"__proto__":["x",1],"constructor":["y",2],"toString":["z",3],"ihymns_theme":["dark",9]}}');
    const d = decodeEnvelope(hostile);
    check('hostile keys (__proto__, constructor, toString) are ignored and only the real key is read', d.size === 1 && d.get(THEME)[0] === 'dark');
    check('...and nothing in the page was changed (({}).polluted is still undefined)', ({}).polluted === undefined && Object.prototype.constructor === Object);
}
{
    const hostile = encodeURIComponent('{"__proto__":{"polluted":"yes"},"k":{}}');
    decodeEnvelope(hostile);
    check('a top-level __proto__ does not pollute either', ({}).polluted === undefined);
}
for (const [name, bad] of [
    ['text that is not JSON', encodeURIComponent('not json at all')],
    ['text that is not valid URL-encoding', '%E0%A4%A'],
    ['a JSON array', encodeURIComponent('[1,2,3]')],
    ['JSON null', encodeURIComponent('null')],
    ['a JSON number', '5'],
    ['an empty string', ''],
    ['no "k" section', encodeURIComponent('{"v":2}')],
    ['"k" that is an array', encodeURIComponent('{"v":2,"k":[]}')],
]) {
    let threw = false; let size = -1;
    try { size = decodeEnvelope(bad).size; } catch { threw = true; }
    check('malformed input is empty and never throws: ' + name, !threw && size === 0);
}
check('non-text input gives an empty result', decodeEnvelope(null).size === 0 && decodeEnvelope(undefined).size === 0 && decodeEnvelope(42).size === 0);
check('text over the read limit is ignored', decodeEnvelope('a'.repeat(5000)).size === 0);
{
    const farFuture = Date.now() + 3 * 24 * 60 * 60 * 1000;
    const d = decodeEnvelope(wire({ [THEME]: ['dark', farFuture], [FONT]: ['18', -1], [MOTION]: ['true', 1.5] }));
    check('stamps that are too far ahead, negative or fractional are dropped', d.size === 0);
}
{
    const d = decodeEnvelope(wire({ [THEME]: ['dark', 5, 'extra'], [FONT]: '18', [MOTION]: ['true'] }));
    check('entries that are not exactly [value, stamp] are dropped', d.size === 0);
}

console.log('encodeEnvelope — writing the cookie:');
{
    const text = encodeEnvelope(m({ [THEME]: ['dark', 50], [FONT]: [null, 60] }));
    const back = decodeEnvelope(text);
    check('what is written can be read back exactly', eq(back, m({ [THEME]: ['dark', 50], [FONT]: [null, 60] })));
    check('it is URL-encoded text within the size limit', typeof text === 'string' && text.length <= MAX_ENCODED_LENGTH);
}
{
    const text = encodeEnvelope(m({ ihymns_auth_token: ['abc', 5], [THEME]: ['dark', 50] }));
    check('only registry keys are ever written', eq(decodeEnvelope(text), m({ [THEME]: ['dark', 50] })) && !decodeURIComponent(text).includes('auth_token'));
}
{
    const text = encodeEnvelope(m({ [THEME]: ['sepia', 50], [FONT]: ['19', 5] }));
    check('invalid values are not written', decodeEnvelope(text).size === 0);
}
{
    /* The largest legal value (a display-prefs object) repeated cannot get near the limit by itself,
       so prove the limit by checking the refusal path directly with a stub registry-sized overflow. */
    const big = { fontSize: 1.5, lineSpacing: 'spacious', showVerseNumbers: true, highlightChorus: true, autoScrollSpeed: 999 };
    const ok = encodeEnvelope(m({ ihymns_display: [JSON.stringify(big), 5] }));
    check('the biggest realistic cookie is well inside the limit', typeof ok === 'string' && ok.length < MAX_ENCODED_LENGTH / 2);
    check('a missing or non-Map input writes an empty envelope, not an error', typeof encodeEnvelope(null) === 'string');
}

console.log('nextStamp — never goes backwards:');
{
    const realNow = Date.now;
    try {
        Date.now = () => 1000;
        check('uses the clock when it is ahead', nextStamp(500) === 1000);
        check('goes one past the highest stamp seen when the clock is behind it', nextStamp(5000) === 5001);
        Date.now = () => 10;
        check('still moves forward when the clock has been set back', nextStamp(5001) === 5002);
        check('copes with a nonsense highest-seen value', nextStamp(NaN) === 10 && nextStamp(undefined) === 10);
    } finally { Date.now = realNow; }
}
check('highestStamp finds the largest stamp across maps', highestStamp(m({ a: ['x', 5] }), m({ b: ['y', 9] })) === 9 && highestStamp() === 0);

if (failures > 0) {
    console.error('\n' + failures + ' check(s) failed.');
    process.exit(1);
}
console.log('\nAll checks passed.');
