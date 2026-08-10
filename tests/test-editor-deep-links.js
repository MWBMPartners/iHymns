/**
 * iHymns — editor deep-link entry-point coverage (#1680)
 *
 * ELI5
 * ----
 * Other admin pages link INTO the song editor with instructions in the URL —
 * "open this song", "show its history", "start song 412 of this book". This
 * checks the editor actually understands every instruction anybody sends it.
 *
 * WHY THIS EXISTS — the same bug, three times
 * -------------------------------------------
 * A deep-link parameter the editor does not read is the quietest possible
 * failure. The page loads, the list populates, Bootstrap works, and the ONE
 * thing the link asked for simply does not happen. No console error, no toast,
 * nothing to grep for.
 *
 *   #1623  /manage/revisions linked `?open=` for its whole life while the
 *          editor read only `?song=`. "Open in editor" did nothing, for years.
 *   #1628  the v2 shell read `song` and ignored `tab` entirely, so the flip
 *          would have re-broken the very button #1623 had just fixed.
 *   #1680  the v2 shell had no `?songbook=`/`#number=` prefill, so the flip
 *          would have silently killed Missing Numbers' "Open in editor" too.
 *
 * Three instances of one shape, each found by hand, each after the last was
 * "fixed". The audits that missed them were not lazy — an API diff finds absent
 * endpoints and a UI diff finds absent screens, and **neither one enumerates
 * the URLs other pages already point at you.**
 *
 * So this derives the LINKS from the tree: every `/manage/editor/...?...` href
 * emitted anywhere under manage/, every param name in it, checked against what
 * the v2 shell actually reads. Add a new deep link on any page and it is
 * covered automatically; that is the entire point.
 *
 *   node tests/test-editor-deep-links.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PUB = join(__dirname, '..', 'appWeb', 'public_html');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond) {
    if (cond) { passed++; console.log(`  ✅ ${label}`); }
    else { failed++; failures.push(label); console.log(`  ❌ ${label}`); }
}

const stripPhp = (s) => s
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');

console.log('\n#1680 — editor deep-link entry-point coverage\n');

/* ---- 1. derive every deep link aimed at the editor ---------------------- */

function walk(dir, out = []) {
    for (const e of readdirSync(dir, { withFileTypes: true })) {
        if (e.name === 'vendor' || e.name === 'node_modules' || e.name.startsWith('.')) continue;
        const p = join(dir, e.name);
        if (e.isDirectory()) walk(p, out);
        else if (/\.php$/.test(e.name)) out.push(p);
    }
    return out;
}

/* Scan the whole public tree, not just manage/ — includes/pages/song.php links
   into the editor too, and "only admin pages link to admin pages" is exactly
   the kind of assumption that produced #1680. */
const emitted = new Map();   // param -> Set(files)
for (const file of walk(PUB)) {
    const src = stripPhp(readFileSync(file, 'utf8'));
    /* Editor hrefs. The PHP interpolations inside are irrelevant — only the
       PARAM NAMES matter, and those are literal. */
    /* Bound the match on the HTML attribute's own quotes, NOT on "no quotes or
       angle brackets". These hrefs interpolate PHP —
       `?song=<?= urlencode($r['SongId']) ?>&tab=history` — so a character class
       excluding `>` truncates at the `?>` and one excluding `'` truncates
       inside `$r['SongId']`. Either way `&tab=history` is never seen.
       This guard did exactly that on its first run: it reported 2 params and
       silently missed `tab`, the very param #1628 had just added. A scanner
       that under-reports is worse than no scanner, because its green tick is
       read as coverage. */
    for (const m of src.matchAll(/href\s*=\s*"([^"]*\/manage\/editor\/\?[^"]*)"/g)) {
        /* Drop the path so `^` aligns with the FIRST param. Matching against the
           whole href instead loses `song=` (it is not at position 0 and not
           preceded by `&`) while still finding `&tab=` — which is how the second
           iteration of this scanner reported `tab` and `number` but silently
           dropped `song` and `songbook`. Two wrong answers in a row from the
           same guard; each looked green. */
        const query = m[1].slice(m[1].indexOf('/manage/editor/?') + '/manage/editor/?'.length);
        for (const pm of query.matchAll(/(?:^|&(?:amp;)?)([a-z_]+)=/gi)) {
            const key = pm[1].toLowerCase();
            if (!emitted.has(key)) emitted.set(key, new Set());
            emitted.get(key).add(file.slice(PUB.length + 1));
        }
        /* The fragment form too — missing-numbers.php emits `#number=`, which
           the browser never sends to the server, so it is easy to miss when
           reasoning about query strings alone. */
        const frag = /#([a-z_]+)=/i.exec(query);
        if (frag) {
            const key = frag[1].toLowerCase();
            if (!emitted.has(key)) emitted.set(key, new Set());
            emitted.get(key).add(file.slice(PUB.length + 1) + ' (fragment)');
        }
    }
}

check(`found deep links into /manage/editor/ (${emitted.size} distinct params)`,
    emitted.size > 0);

/* ---- 2. the v2 shell handles every one of them -------------------------- */

const shellSrc = readFileSync(join(PUB, 'manage/editor/editor2.php'), 'utf8');
const shell = stripPhp(shellSrc);

/* `/manage/editor/` is now a ROUTER: index.php either redirects to the v2 shell
   or (with ?legacy=1) serves v1 itself. So a param is handled when EITHER end of
   that route reads it — checking only the shell wrongly flags `?legacy=`, which
   is addressed to the router and deliberately never reaches v2.
   Comments are stripped from both first, so the long notes explaining each of
   these params cannot satisfy an assertion about them. */
const router = stripPhp(readFileSync(join(PUB, 'manage/editor/index.php'), 'utf8'));

function handled(param) {
    const reads = new RegExp(`\\$_GET\\[['"]${param}['"]\\]`);
    if (reads.test(shell) || reads.test(router)) return true;
    /* `number` arrives as a FRAGMENT from missing-numbers.php, which the browser
       never sends to the server — so the shell parses it in JS, and no
       $_GET check can ever see it. */
    if (param === 'number' && /location\.hash/.test(shell) && /number=/.test(shell)) return true;
    return false;
}

for (const [param, files] of [...emitted].sort()) {
    check(`v2 shell handles ?${param}= (emitted by ${[...files].join(', ')})`,
        handled(param));
}

/* ---- 3. the aliases that exist because links outlive code ---------------- */

check("v2 shell accepts ?open= as an alias for ?song= (#1623 — the alias exists "
    + 'so bookmarked links from that era do not fail the same invisible way)',
    /\$_GET\[['"]open['"]\]/.test(shell));

check('v2 shell accepts the #number= FRAGMENT form, not only ?number= '
    + '(missing-numbers.php emits the fragment, which never reaches PHP)',
    /location\.hash/.test(shell) && /number=/.test(shell));

check('v2 shell reads ?duplicate= (#1783 — the cold-load duplicate entry, '
    + 'asserted now so the handler is guarded before any page emits the link, '
    + 'exactly like ?open=)',
    /\$_GET\[['"]duplicate['"]\]/.test(shell));

/* ---- 4. the prefill cannot mint a duplicate ----------------------------- */

const sidebar = readFileSync(join(PUB, 'manage/editor/v2/sidebar.js'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

check('sidebar exposes findByBookAndNumber so the prefill can open an existing '
    + 'song instead of creating a second one on the same number',
    /findByBookAndNumber\s*\(/.test(sidebar));

/* Numbers arrive from the slim index as STRINGS. `'412' === 412` is false, so a
   strict compare would report every existing song as missing and mint a
   duplicate on its number — the one outcome this flow must never produce. */
check('findByBookAndNumber compares numbers NUMERICALLY (slim-index numbers are strings)',
    /Number\(s\.number\)\s*===\s*n|n\s*===\s*Number\(s\.number\)/.test(sidebar));

check('the prefill awaits the index rather than racing it (whenLoaded)',
    /whenLoaded\s*\(\s*\)/.test(sidebar) && /whenLoaded\(\)/.test(shell));

/* ---- 5. the route flip itself (#1601 scope item 2) ---------------------- */

const v1 = router;   /* same source, already read + comment-stripped above */

check('/manage/editor/ redirects to the v2 editor (the route flip, not just the nav)',
    /header\(\s*['"]Location:\s*\/manage\/editor\/editor2\.php/.test(v1));

/* The query string must survive, or every deep link above arrives at v2 stripped
   of the very instruction it was carrying — the failure this whole file is about,
   reintroduced by the fix for it. */
check('the redirect forwards the query string verbatim',
    /QUERY_STRING/.test(v1));

/* 301 is cached by the browser, sometimes indefinitely: if this has to be
   reverted, anyone who visited once keeps being sent to v2 from their own cache
   with no server-side way to stop it. */
check('the redirect is 302, not a browser-cached 301',
    /,\s*true\s*,\s*302\s*\)/.test(v1) && !/,\s*true\s*,\s*301\s*\)/.test(v1));

check('?legacy=1 still reaches v1 (the per-visit escape hatch)',
    /\$_GET\[['"]legacy['"]\]/.test(v1));

check("tblAppSettings.editor_v2_default='0' can revert it fleet-wide without a deploy",
    /getAppSetting\(\s*['"]editor_v2_default['"]/.test(v1));

/* An absent setting must mean v2, and getAppSetting() returns its default on ANY
   DB error — so a database wobble sends people to the new editor rather than
   throwing on the way in. */
check('the flip defaults ON when the setting is absent or the DB is unreachable',
    /getAppSetting\(\s*['"]editor_v2_default['"]\s*,\s*['"]1['"]\s*\)/.test(v1));

/* editor2.php's own "Legacy" button must carry ?legacy=1. A bare
   /manage/editor/ link would bounce straight back to v2 and read as broken. */
check("editor2.php's Legacy button carries ?legacy=1 (a bare link would bounce back)",
    /\/manage\/editor\/\?legacy=1/.test(shellSrc));

/* The fleet-wide switch needs a UI control, or it is not a usable revert. This
   is the lesson content_gating_enabled already taught: it shipped with no admin
   control and could only be flipped by a raw SQL statement, which #1481 had to
   go back and fix. A revert nobody can perform under pressure is not a revert. */
const config = stripPhp(readFileSync(join(PUB, 'manage/configuration.php'), 'utf8'));
check('editor_v2_default is flippable from /manage/configuration, not only via raw SQL',
    /save_editor_default/.test(config)
    && /getAppSetting\(\s*['"]editor_v2_default['"]/.test(config)
    && /name="editor_v2_default"/.test(config));

/* Polarity is the easy thing to get backwards, and getting it backwards means
   the cutover silently does not happen. ABSENT must mean v2 on BOTH sides. */
check("the admin control reads editor_v2_default with default '1' (absent = v2, matching the router)",
    /getAppSetting\(\s*['"]editor_v2_default['"]\s*,\s*['"]1['"]\s*\)/.test(config));

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nA deep-link param the editor does not read fails SILENTLY: the page loads,');
    console.error('the list populates, and the one thing the link asked for never happens.');
    console.error('That is #1623, #1628 and #1680 — the same bug three times. Handle the param');
    console.error('in editor2.php, or stop emitting the link.');
    process.exit(1);
}
console.log('\nAll editor deep-link assertions passed.');
