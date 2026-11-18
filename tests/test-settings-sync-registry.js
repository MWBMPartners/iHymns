/**
 * tests/test-settings-sync-registry.js — the one list of settings that may travel between sites
 *
 * PURPOSE
 * ELI5: iHymns lets a few display settings (theme, text size ...) follow a
 * visitor between its sites. `js/modules/settings-sync-registry.js` is the ONE
 * list of which settings may do that. This check makes sure the list stays
 * safe and honest: it never contains a secret or someone's content, it matches
 * what the settings page really offers, and nobody quietly typed a second copy
 * of it somewhere else.
 *
 * DETAIL (all derived from the tree, never from a list typed here — rule #34)
 *   (a) `settings.js` imports the shared list; it does not type its own
 *       (rule #35: two files that must agree need a mechanism, not a comment).
 *   (b) NOTHING private can be in the registry. Every localStorage key the
 *       code uses is collected from the tree (the `STORAGE_*` exports in
 *       constants.js, plus every literal key passed to localStorage.getItem /
 *       setItem / removeItem under js/ and manage/editor/v2/). Any key whose
 *       name says it is a token, an owner or device id, a consent choice, a
 *       sync note, a favourite, a set list, history and so on must NOT be in
 *       the registry.
 *   (c) The allowed values match the real settings page: the theme buttons,
 *       the transition and colour-filter <option>s, and the text-size range
 *       are read from includes/pages/settings.php and compared.
 *   (d) Every registry key is a `STORAGE_*` export in constants.js (so a
 *       renamed key cannot leave the list pointing at nothing).
 *   (e) A small truth table for a few validators.
 *
 * Mutation-proven: add STORAGE_AUTH_TOKEN to the registry (b fails); add an
 * <option value="sepia"> to the transition select (c fails); type a list
 * back into settings.js (a fails). The current tree is green.
 *
 *   node tests/test-settings-sync-registry.js
 *
 * @see appWeb/public_html/js/modules/settings-sync-registry.js
 * @see .claude/settings-sync-2143-plan.md  (section 5, guard G1)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');

const registry = await import(path.join(PUB, 'js', 'modules', 'settings-sync-registry.js'));

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}
const read = (p) => fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '';
const setsEqual = (a, b) => a.length === b.length && a.every((x) => b.includes(x));

/* Comments are blanked (newlines kept) so a key MENTIONED in a comment is not mistaken for code. */
const stripComments = (s) => s
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '))
    .replace(/(^|[^:'"`])\/\/[^\n]*/g, '$1');

const registryKeys = [...registry.CROSS_SITE_KEYS];

/* ---- (a) one list ---- */
console.log('One list, not two:');
const settingsJs = stripComments(read(path.join(PUB, 'js', 'modules', 'settings.js')));
check('settings.js imports CROSS_SITE_KEYS from the registry',
    /import\s*\{[^}]*\bCROSS_SITE_KEYS\b[^}]*\}\s*from\s*'\.\/settings-sync-registry\.js'/.test(settingsJs));
check('settings.js sets SYNC_PREF_KEYS from it', /const\s+SYNC_PREF_KEYS\s*=\s*CROSS_SITE_KEYS\s*;/.test(settingsJs));
check('settings.js does not type its own list of ihymns_ keys for SYNC_PREF_KEYS',
    !/SYNC_PREF_KEYS\s*=\s*(Object\.freeze\()?\s*\[[^\]]*['"]ihymns_/.test(settingsJs));
check('the registry is not empty', registryKeys.length > 0);

/* ---- (b) nothing private ---- */
console.log('Nothing private can travel:');
const constantsSrc = stripComments(read(path.join(PUB, 'js', 'constants.js')));
const constantExports = new Map();   /* STORAGE_NAME -> key value */
for (const m of constantsSrc.matchAll(/export const (STORAGE_[A-Z0-9_]+)\s*=\s*'([^']+)'/g)) constantExports.set(m[1], m[2]);

/* Every literal key handed to localStorage anywhere in the app code. */
const tracked = execFileSync('git', ['ls-files', '-z', '--', 'appWeb/public_html/js', 'appWeb/public_html/manage/editor/v2'],
    { cwd: REPO, encoding: 'utf8' }).split('\0').filter((f) => f.endsWith('.js'));
const literalKeys = new Set();
for (const f of tracked) {
    const src = stripComments(read(path.join(REPO, f)));
    for (const m of src.matchAll(/localStorage\.(?:getItem|setItem|removeItem)\(\s*'([^']+)'/g)) literalKeys.add(m[1]);
}
const candidates = new Set([...constantExports.values(), ...literalKeys]);

/* Names that say "this is private, device-only, or the site's own bookkeeping". */
const PRIVATE = /auth|token|owner_id|device|presence|consent|synced_at|deleted|backfilled|migrated|stamps|legacy_imported|sync_app|favorites|setlists|history|custom_tags|recent_songbooks|playlist_context|analytics|disclaimer|pwa_banner|offline|midi|transpose|capo|octave|chord/i;
const privateCandidates = [...candidates].filter((k) => PRIVATE.test(k));
check('the scan found a real set of keys (' + candidates.size + ' keys, ' + privateCandidates.length + ' private-looking)',
    candidates.size > 25 && privateCandidates.length > 10,
    'Too few keys found: the scan pattern no longer matches the code, so this check would pass while checking nothing.');
for (const key of registryKeys) {
    check('"' + key + '" does not look private', !PRIVATE.test(key));
}
check('no private-looking key from the tree is in the registry',
    privateCandidates.every((k) => !registryKeys.includes(k)),
    privateCandidates.filter((k) => registryKeys.includes(k)).join(', '));

/* ---- (c) matches the real settings page ---- */
console.log('Matches the real settings page:');
const page = read(path.join(PUB, 'includes', 'pages', 'settings.php'));
const optionValues = (selectId) => {
    const m = new RegExp('<select[^>]*id="' + selectId + '"[\\s\\S]*?</select>').exec(page);
    return m ? [...m[0].matchAll(/<option[^>]*value="([^"]*)"/g)].map((x) => x[1]) : null;
};
const themes = [...page.matchAll(/data-setting-theme="([^"]+)"/g)].map((m) => m[1]);
check('theme buttons match THEME_VALUES', themes.length > 0 && setsEqual(themes, [...registry.THEME_VALUES]),
    'page: ' + themes.join(',') + ' | registry: ' + registry.THEME_VALUES.join(','));
const trans = optionValues('setting-transition');
check('transition options match TRANSITION_VALUES', trans !== null && setsEqual(trans, [...registry.TRANSITION_VALUES]),
    'page: ' + (trans || []).join(',') + ' | registry: ' + registry.TRANSITION_VALUES.join(','));
const cvd = optionValues('setting-cvd-mode');
check('colour-filter options (apart from the empty "None") match CVD_VALUES',
    cvd !== null && setsEqual(cvd.filter((v) => v !== ''), [...registry.CVD_VALUES]),
    'page: ' + (cvd || []).join(',') + ' | registry: ' + registry.CVD_VALUES.join(','));
const rangeMatch = /<input[^>]*id="setting-font-size"[^>]*>/.exec(page);
const attr = (name) => rangeMatch ? Number((new RegExp(name + '="(\\d+)"').exec(rangeMatch[0]) || [])[1]) : NaN;
check('text-size range matches FONT_SIZE_RANGE',
    attr('min') === registry.FONT_SIZE_RANGE.min && attr('max') === registry.FONT_SIZE_RANGE.max && attr('step') === registry.FONT_SIZE_RANGE.step,
    'page: ' + attr('min') + '-' + attr('max') + ' step ' + attr('step'));

/* ---- (d) every key is a constants.js export ---- */
console.log('Keys come from constants.js:');
const constantValues = new Set(constantExports.values());
for (const key of registryKeys) {
    check('"' + key + '" is a STORAGE_* export in constants.js', constantValues.has(key));
}

/* ---- (e) a few validators ---- */
console.log('Validators:');
const ok = (k, v) => registry.isShareableSetting(k, v);
check('accepts a real theme, rejects a made-up one', ok('ihymns_theme', 'dark') && !ok('ihymns_theme', 'sepia'));
check('accepts text size 18, rejects 19 (off the step), 30 (out of range) and a word',
    ok('ihymns_fontSize', '18') && !ok('ihymns_fontSize', '19') && !ok('ihymns_fontSize', '30') && !ok('ihymns_fontSize', 'big'));
check('accepts "true"/"false", rejects "yes"', ok('ihymns_reduceMotion', 'true') && !ok('ihymns_reduceMotion', 'yes'));
check('rejects an empty value and a non-string', !ok('ihymns_theme', '') && !ok('ihymns_theme', null) && !ok('ihymns_theme', 5));
check('rejects a key that is not in the registry', !ok('ihymns_auth_token', 'abc') && !ok('ihymns_favorites', '[]'));
check('display prefs: accepts the known keys, rejects an unknown one',
    ok('ihymns_display', '{"fontSize":1.2,"lineSpacing":"normal"}') && !ok('ihymns_display', '{"evil":1}') && !ok('ihymns_display', '[]'));
check('rejects a value over the length limit', !ok('ihymns_display', '{"fontSize":1}' + ' '.repeat(600)));

if (failures > 0) {
    console.error('\n' + failures + ' check(s) failed.');
    process.exit(1);
}
console.log('\nAll checks passed.');
