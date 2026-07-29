/**
 * tests/test-offline-cache-policy.js — regression guard for #1597 (RC1/RC2/RC3/RC4)
 *
 * ELI5: the app lets you "download" a songbook so you can read it on a plane.
 * This test checks the four rules that made the download actually survive:
 * downloaded songs live in their own box that the tidy-up never empties, the
 * box of audio is not thrown away when the app updates, the pages you need to
 * browse are saved too, and "remove this songbook" can actually find the files.
 *
 * WHY THIS EXISTS
 * ---------------
 * All four defects failed WHILE REPORTING SUCCESS — the "looks alive, isn't"
 * class (#1565, #1581, #1593). The UI said "All 3,517 songs saved for offline
 * use" and the songs were gone before the user reached the airport:
 *
 *   RC1  `CACHE_SONG` is posted by router.js on EVERY song view, and its
 *        handler trimmed the ONE songs bucket to 2000 entries oldest-first.
 *        Download Mission Praise (3,517 songs), open one song → ~1,500
 *        deliberately-downloaded songs deleted. Bulk-saved and recently-viewed
 *        were sharing a budget; they are different intents and must not.
 *   RC2  the `activate` keep-list kept only CACHE_VERSION + RECENT_CACHE, but
 *        audio is written to `iHymns-media-v1` — so every service-worker
 *        activation (i.e. every deploy, and the version now bumps on every
 *        alpha push, #1596) deleted all downloaded audio.
 *   RC3  the SW offline-fell-back only `page=song`; home / songbooks /
 *        songbook / search 503'd into the red "Failed to load page" card, so
 *        even a perfect download was unbrowsable.
 *   RC4  eviction + size reporting matched `/data/audio/<book>/…`
 *        SUBDIRECTORIES, while the real URLs emitted by `api.php` (bulk_audio)
 *        are FLAT: `/data/audio/<SongId>.mp3`. "Remove from offline" silently
 *        removed nothing and the reported size was wrong.
 *
 * HOW IT TESTS THE REAL CODE
 * --------------------------
 * `service-worker.js.php` is JS behind a PHP wrapper, so it cannot be
 * `import`ed. This suite strips the PHP head, evaluates the REAL shipped JS in
 * a `new Function` sandbox with `self` / `caches` stubbed, and calls the actual
 * pure helpers the fetch / activate / message handlers use. It is not a
 * paraphrase of the policy — it is the policy.
 *
 * PROVEN ABLE TO FAIL: run against the pre-fix tree, section 6 reproduces each
 * old implementation and asserts it still gets the wrong answer. If any of
 * those "legacy" checks ever stops failing, the corresponding guard above has
 * stopped testing anything and this suite is lying.
 *
 * @see appWeb/public_html/service-worker.js.php
 * @see https://developer.mozilla.org/en-US/docs/Web/API/CacheStorage
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Cache/keys  (insertion order)
 * @see https://github.com/MWBMPartners/iHymns/issues/1597
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/* The env override is the REPRODUCTION HOOK for "was this guard ever seen to
   fail?". Point it at a pre-fix copy of the worker and every assertion below
   should go red:
     git show <pre-fix-sha>:appWeb/public_html/service-worker.js.php > /tmp/old.php
     IHYMNS_SW_FILE=/tmp/old.php node tests/test-offline-cache-policy.js
   CI never sets it, so the default is always the shipped file. */
const SW_FILE = process.env.IHYMNS_SW_FILE || path.join(
    __dirname, '..', 'appWeb', 'public_html', 'service-worker.js.php'
);

let passed = 0;
let failed = 0;

function check(label, ok, detail) {
    if (ok) {
        passed++;
    } else {
        failed++;
        console.error(`  FAIL ${label}${detail ? `\n       ${detail}` : ''}`);
    }
}

function eq(label, actual, expected) {
    check(
        label,
        Object.is(actual, expected),
        `expected: ${JSON.stringify(expected)}\n       actual:   ${JSON.stringify(actual)}`
    );
}

/* ======================================================================
 * Load the REAL service worker
 * ==================================================================== */

/* The file is `<?php … ?>` followed by the JS body. Everything up to and
   including the closing tag is server-side; the browser never sees it. */
const rawSource = fs.readFileSync(SW_FILE, 'utf8');
const phpEnd = rawSource.indexOf('?>');
check('service-worker.js.php has a PHP head to strip', phpEnd !== -1);

/* `<?= $swCacheKey ?>` is the only PHP interpolation inside the JS body;
   stand in a fixed value so the cache names are deterministic here. */
const STUB_VERSION = '9.9.9-20260729';
const jsSource = rawSource
    .slice(phpEnd + 2)
    .replace(/<\?=\s*\$swCacheKey\s*\?>/g, STUB_VERSION);

check(
    'no PHP tags survive into the JS body (a stray one would break SW registration)',
    !/<\?/.test(jsSource),
    'found a `<?` in the JS body after substitution'
);

/* Names this suite reaches for. `typeof x !== "undefined"` is safe for an
   identifier that was never declared, which is exactly the pre-fix case —
   the harness then reports "helper missing" rather than throwing. */
const HARVEST = [
    'SW_CACHE_REVISION',
    'CACHE_VERSION', 'PAGES_CACHE', 'RECENT_CACHE', 'SAVED_CACHE', 'MEDIA_CACHE',
    'RECENT_CACHE_LIMIT', 'PAGES_CACHE_LIMIT',
    'OFFLINE_PAGE_FRAGMENTS',
    'swKeptCacheNames', 'swIsCacheKept', 'swSongCacheName',
    'swKeysToTrim', 'swPagesKeysToTrim',
    'swSongbookFromMediaUrl', 'swSongbookFromSongCacheUrl', 'swOfflinePageFragment',
];

const harvestTail = '\n;return {'
    + HARVEST.map(n => `${n}: (typeof ${n} !== 'undefined' ? ${n} : undefined)`).join(', ')
    + '};';

/* Minimal ServiceWorkerGlobalScope stand-in. Only `addEventListener` is
   invoked at load time; `location.origin` is read by the URL helpers.
   https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerGlobalScope */
const selfStub = {
    addEventListener() { /* handlers are registered but never dispatched here */ },
    location: { origin: 'https://www.ihymns.app' },
    registration: { active: null },
    clients: { matchAll: async () => [], claim: async () => {} },
    skipWaiting() {},
};
const cachesStub = {
    open: async () => ({ keys: async () => [], match: async () => undefined }),
    keys: async () => [],
    match: async () => undefined,
    delete: async () => false,
};

let sw;
try {
    /* eslint-disable-next-line no-new-func */
    sw = new Function('self', 'caches', jsSource + harvestTail)(selfStub, cachesStub);
} catch (err) {
    console.error(`  FATAL could not evaluate the service worker body: ${err.message}`);
    console.log('\n0 passed, 1 failed');
    process.exit(1);
}

function need(name) {
    const v = sw[name];
    if (v === undefined) {
        failed++;
        console.error(`  FAIL helper/constant \`${name}\` is missing from service-worker.js.php`);
        return null;
    }
    return v;
}

/* ======================================================================
 * 1 — RC1: deliberately-downloaded songs get their OWN budget
 * ==================================================================== */
console.log('#1597 RC1 — a downloaded song survives a trim that evicts recents\n');

const RECENT = need('RECENT_CACHE');
const SAVED = need('SAVED_CACHE');
const songCacheName = need('swSongCacheName');
const keysToTrim = need('swKeysToTrim');
const LIMIT = need('RECENT_CACHE_LIMIT');

check(
    'the deliberate-download bucket is a DIFFERENT cache from the recency bucket',
    typeof SAVED === 'string' && typeof RECENT === 'string' && SAVED !== RECENT,
    `RECENT_CACHE=${JSON.stringify(RECENT)} SAVED_CACHE=${JSON.stringify(SAVED)}`
);

if (songCacheName) {
    eq('a deliberate save routes to the saved bucket', songCacheName(true), SAVED);
    eq('an incidental view routes to the recency bucket', songCacheName(false), RECENT);
    eq('an absent `saved` flag defaults to the recency bucket (router.js posts no flag)',
        songCacheName(undefined), RECENT);
}

if (keysToTrim && songCacheName && typeof LIMIT === 'number') {
    /* Replay the exact scenario from the issue against the real routing +
       trim helpers: download Mission Praise (3,517 songs — bigger than
       RECENT_CACHE_LIMIT all on its own), then open ONE song, which is what
       makes router.js post CACHE_SONG and fire the trim.

       `buckets` models CacheStorage: bucket name → array of keys in insertion
       order, exactly what Cache.keys() resolves to. */
    const buckets = { [RECENT]: [], [SAVED]: [] };
    const put = (url, saved) => {
        const bucket = buckets[songCacheName(saved)];
        if (!bucket.some(k => k.url === url)) bucket.push({ url });
    };
    /* Some ordinary browsing first, so the recency bucket is already at its
       cap when the download lands — the worst case for the old code. */
    for (let i = 0; i < LIMIT; i++) {
        put(`https://www.ihymns.app/api?page=song&id=CP-${String(i + 1).padStart(4, '0')}`, false);
    }
    /* The deliberate download (offline-ui.js / settings.js send saved:true). */
    const downloadUrls = Array.from({ length: 3517 }, (_v, i) =>
        `https://www.ihymns.app/api?page=song&id=MP-${String(i + 1).padStart(4, '0')}`);
    downloadUrls.forEach(u => put(u, true));

    /* …and now the single song view that used to destroy it. router.js posts
       no `saved` flag, so it routes to the recency bucket, and ONLY that
       bucket is trimmed. */
    put('https://www.ihymns.app/api?page=song&id=JP-0001', undefined);
    const trimTarget = buckets[songCacheName(undefined)];
    const doomed = keysToTrim(trimTarget, LIMIT);
    doomed.forEach(k => {
        const at = trimTarget.findIndex(e => e.url === k.url);
        if (at !== -1) trimTarget.splice(at, 1);
    });

    eq('the view trims exactly the one-entry overflow from the recency bucket',
        doomed.length, 1);
    eq('the recency bucket is back at its cap', buckets[RECENT].length, LIMIT);
    eq('ALL 3,517 downloaded songs are still cached after the view (THE RC1 REGRESSION)',
        buckets[SAVED].length, 3517);
    check('no downloaded song was among the evicted keys',
        doomed.every(k => !downloadUrls.includes(k.url)),
        `evicted: ${doomed.map(k => k.url).join(', ')}`);
    eq('an under-cap bucket is not trimmed at all',
        keysToTrim(buckets[RECENT].slice(0, 10), LIMIT).length, 0);
}

/* ======================================================================
 * 2 — RC2: the media cache survives an activate
 * ==================================================================== */
console.log('\n#1597 RC2 — downloaded audio survives a deploy (activate keep-list)\n');

const MEDIA = need('MEDIA_CACHE');
const keptNames = need('swKeptCacheNames');
const isKept = need('swIsCacheKept');

eq('MEDIA_CACHE keeps its historical name (renaming it would wipe audio once more)',
    MEDIA, 'iHymns-media-v1');

if (keptNames) {
    const kept = keptNames();
    check('the keep-list includes the audio/sheet-music bucket (THE RC2 REGRESSION)',
        kept.includes(MEDIA), `keep-list = ${JSON.stringify(kept)}`);
    check('the keep-list includes the deliberate-download bucket',
        kept.includes(SAVED), `keep-list = ${JSON.stringify(kept)}`);
    check('the keep-list includes the recency bucket',
        kept.includes(RECENT), `keep-list = ${JSON.stringify(kept)}`);
    check('the keep-list includes the current versioned app-shell bucket',
        kept.includes(sw.CACHE_VERSION), `keep-list = ${JSON.stringify(kept)}`);
}

if (isKept) {
    eq('a superseded versioned bucket is still purged on activate',
        isKept('ihymns-v0.0.1-20200101'), false);
    eq('the media bucket is not purged on activate', isKept(MEDIA), true);
    eq('the saved bucket is not purged on activate', isKept(SAVED), true);
}

/* Source-level guard: a half-fix that adds MEDIA_CACHE to the keep-list but
   leaves a bare `caches.open('iHymns-media-v1')` elsewhere would drift the
   moment the constant changes. */
check(
    'no bare `iHymns-media-v1` literal outside the MEDIA_CACHE declaration',
    (jsSource.match(/'iHymns-media-v1'/g) || []).length === 1,
    `${(jsSource.match(/'iHymns-media-v1'/g) || []).length} literal occurrence(s); expected exactly 1 (the const)`
);

/* ======================================================================
 * 3 — RC3: offline navigation beyond the song page
 * ==================================================================== */
console.log('\n#1597 RC3 — home / songbooks / songbook / search are offline-cacheable\n');

const pageFragment = need('swOfflinePageFragment');
const PAGES = need('PAGES_CACHE');

if (pageFragment) {
    for (const page of ['home', 'songbooks', 'songbook', 'search']) {
        eq(`page=${page} is an offline-cacheable fragment`,
            pageFragment(`https://www.ihymns.app/api?page=${page}`), page);
    }
    eq('page=songbook keeps its id parameter and still resolves',
        pageFragment('https://www.ihymns.app/api?page=songbook&id=MP'), 'songbook');
    eq('page=song is NOT in the fragment bucket (songs have their own buckets)',
        pageFragment('https://www.ihymns.app/api?page=song&id=MP-1008'), null);
    eq('a non-/api URL is not a fragment',
        pageFragment('https://www.ihymns.app/songbooks/'), null);
    eq('an /api action (not a page) is not a fragment',
        pageFragment('https://www.ihymns.app/api?action=songs_index'), null);
    eq('a malformed URL returns null rather than throwing', pageFragment('::::'), null);
}

check('the page-fragment bucket is versioned, so a deploy refreshes it',
    typeof PAGES === 'string' && PAGES.includes(STUB_VERSION),
    `PAGES_CACHE = ${JSON.stringify(PAGES)} (expected the deploy version in the name)`);

const pagesTrim = need('swPagesKeysToTrim');
const PAGES_LIMIT = need('PAGES_CACHE_LIMIT');
if (pagesTrim && typeof PAGES_LIMIT === 'number') {
    /* Search fragments are per-query and would otherwise grow without bound
       inside one deploy window. They are trimmable; the two entry points a
       user needs in order to browse offline at all are pinned. */
    const fragmentKeys = [
        { url: 'https://www.ihymns.app/api?page=home' },
        { url: 'https://www.ihymns.app/api?page=songbooks' },
        ...Array.from({ length: PAGES_LIMIT + 5 }, (_v, i) =>
            ({ url: `https://www.ihymns.app/api?page=search&q=term${i}` })),
    ];
    const doomedPages = pagesTrim(fragmentKeys, PAGES_LIMIT);
    eq('the fragment trim removes exactly the overflow', doomedPages.length, 7);
    check('the fragment trim never evicts page=home',
        !doomedPages.some(k => k.url.endsWith('page=home')));
    check('the fragment trim never evicts page=songbooks',
        !doomedPages.some(k => k.url.endsWith('page=songbooks')));
    eq('an under-limit fragment bucket is not trimmed',
        pagesTrim(fragmentKeys.slice(0, 3), PAGES_LIMIT).length, 0);
}

/* ======================================================================
 * 4 — RC4: the evictor matches the REAL, FLAT media URL shape
 * ==================================================================== */
console.log('\n#1597 RC4 — flat /data/audio/<SongId>.mp3 is matched by the evictor\n');

const bookFromMedia = need('swSongbookFromMediaUrl');
if (bookFromMedia) {
    /* api.php `bulk_audio` emits exactly this shape (api.php:1517). */
    eq('flat audio URL yields its songbook (THE RC4 REGRESSION)',
        bookFromMedia('https://www.ihymns.app/data/audio/MP-1008.mp3'), 'MP');
    eq('flat audio URL as a root-relative path',
        bookFromMedia('/data/audio/CP-0001.mp3'), 'CP');
    eq('flat sheet music (/data/music/<SongId>.pdf, sheet-music.js:20)',
        bookFromMedia('/data/music/CP-0001.pdf'), 'CP');
    eq('signed audio route keeps working (#1358: /audio/<id>.mp3?exp=&sig=)',
        bookFromMedia('/audio/SDAH-0123.mp3?exp=1799999999&sig=deadbeef'), 'SDAH');
    eq('songbook is upper-cased so it matches the SongId prefix registry',
        bookFromMedia('/data/audio/mp-1008.mp3'), 'MP');
    eq('legacy nested layout still resolves (belt and braces)',
        bookFromMedia('/data/audio/MP/MP-1008.mp3'), 'MP');
    eq('a media file with no <letters>-<digits> SongId yields null',
        bookFromMedia('/data/audio/README.mp3'), null);
    eq('a non-media path yields null',
        bookFromMedia('/api?page=song&id=MP-1008'), null);
    eq('a malformed URL yields null rather than throwing',
        bookFromMedia('::::'), null);
}

const bookFromSong = need('swSongbookFromSongCacheUrl');
if (bookFromSong) {
    eq('song cache key yields its songbook',
        bookFromSong('https://www.ihymns.app/api?page=song&id=MP-1008'), 'MP');
    eq('the migration sentinel (no id param) yields null, so it is never evicted or counted',
        bookFromSong('https://www.ihymns.app/__ihymns_sw__/saved-split-v1'), null);
    eq('an id with no dash yields null', bookFromSong('/api?page=song&id=1008'), null);
}

/* ======================================================================
 * 5 — the cache version is bumped for this layout change
 * ==================================================================== */
console.log('\n#1597 — the SW cache layout revision\n');

const REV = need('SW_CACHE_REVISION');
check('an explicit cache-layout revision exists (bumped when bucket names change)',
    typeof REV === 'string' && REV.length > 0, `SW_CACHE_REVISION = ${JSON.stringify(REV)}`);
check('CACHE_VERSION folds in both the deploy version and the layout revision',
    typeof sw.CACHE_VERSION === 'string'
    && sw.CACHE_VERSION.includes(STUB_VERSION)
    && sw.CACHE_VERSION.includes(String(REV)),
    `CACHE_VERSION = ${JSON.stringify(sw.CACHE_VERSION)}`);

/* ======================================================================
 * 6 — the guard's own guard: every pre-fix implementation must still be wrong
 *
 * Reproduced verbatim from the pre-fix tree. If one of these ever agrees with
 * the fixed behaviour, the matching assertion above has gone vacuous.
 * ==================================================================== */
console.log('\n#1597 — the old implementations must still get it wrong\n');

/* RC1 (pre-fix): ONE bucket, so a bulk download and a song view share a
   budget and the trim eats the download. service-worker.js.php:1122-1127. */
function legacyTrim(keys, limit) {
    return keys.length > limit ? keys.slice(0, keys.length - limit) : [];
}
const legacyMixed = [
    ...Array.from({ length: 3517 }, (_v, i) => ({ url: `/api?page=song&id=MP-${i}`, bulk: true })),
    { url: '/api?page=song&id=CP-0001', bulk: false },
];
const legacyDoomed = legacyTrim(legacyMixed, 2000);
check('legacy single-bucket trim DOES delete deliberately-downloaded songs',
    legacyDoomed.length === 1518 && legacyDoomed.every(k => k.bulk === true),
    `deleted ${legacyDoomed.length}, bulk-only=${legacyDoomed.every(k => k.bulk === true)}`);

/* RC2 (pre-fix): service-worker.js.php:375 — `name !== CACHE_VERSION &&
   name !== RECENT_CACHE`, i.e. the media bucket was never kept. */
function legacyIsKept(name) {
    return name === sw.CACHE_VERSION || name === RECENT;
}
check('legacy keep-list DOES purge the audio bucket on every activate',
    legacyIsKept('iHymns-media-v1') === false);

/* RC3 (pre-fix): service-worker.js.php:445 — only page=song was ever
   offline-served; every other fragment fell through to a 503. */
function legacyOfflineServable(rawUrl) {
    try { return new URL(rawUrl, 'https://www.ihymns.app').searchParams.get('page') === 'song'; }
    catch { return false; }
}
check('legacy offline fallback DOES refuse page=home',
    legacyOfflineServable('/api?page=home') === false);
check('legacy offline fallback DOES refuse page=songbook',
    legacyOfflineServable('/api?page=songbook&id=MP') === false);

/* RC4 (pre-fix): service-worker.js.php:1097 — a SUBDIRECTORY regex against
   URLs that have never had a subdirectory. */
const LEGACY_MEDIA_RE = /^\/data\/(?:audio|music)\/([^/]+)\//i;
check('legacy media regex DOES miss the flat /data/audio/<SongId>.mp3 shape',
    LEGACY_MEDIA_RE.exec('/data/audio/MP-1008.mp3') === null);
check('legacy `path.includes("/data/audio/mp/")` DOES miss the flat shape',
    '/data/audio/mp-1008.mp3'.includes('/data/audio/mp/') === false);

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
