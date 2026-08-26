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
    'CACHE_VERSION', 'PAGES_CACHE', 'RECENT_CACHE', 'SAVED_CACHE', 'MEDIA_CACHE', 'INDEX_CACHE',
    'RECENT_CACHE_LIMIT', 'PAGES_CACHE_LIMIT', 'MEDIA_REVALIDATE_TTL_MS',
    'OFFLINE_PAGE_FRAGMENTS',
    'swKeptCacheNames', 'swIsCacheKept', 'swSongCacheName',
    'swKeysToTrim', 'swPagesKeysToTrim',
    'swSongbookFromMediaUrl', 'swSongbookFromSongCacheUrl', 'swOfflinePageFragment',
    /* #1962 — the media cache policy helpers (see service-worker.js.php's
       "MEDIA CACHE POLICY" section doc-block). All PURE except
       swRevalidateMediaEntry(), which needs its OWN fetch-controllable
       sandbox — see the dedicated section below, mirroring the
       networkFirstRevalidated() sandbox pattern already used for #1921. */
    'swIsMediaUrl', 'swMediaShouldRevalidate', 'swMediaSupersededKeys',
    'swMediaValidators', 'swMediaBulkPlan',
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
 * 2b — #1962 G5: the keep-list partitions cleanly into version-keyed vs
 * version-free buckets. A deploy must invalidate the APP-SHELL buckets
 * (markup/JS can go stale) but must NEVER touch the user's own content
 * (songs, audio, offline index) — this is invariant (A) applied to the
 * keep-list's own membership, not just its presence.
 * ==================================================================== */
console.log('\n#1962 G5 — the keep-list is exactly {version-keyed} ∪ {version-free}\n');

const PAGES_EARLY = need('PAGES_CACHE');
const INDEX = need('INDEX_CACHE');

if (keptNames && typeof PAGES_EARLY === 'string' && typeof INDEX === 'string') {
    const kept = keptNames();
    const versionKeyed = kept.filter(n => typeof n === 'string' && n.includes(STUB_VERSION));
    const versionFree  = kept.filter(n => typeof n === 'string' && !n.includes(STUB_VERSION));

    eq('the version-KEYED subset of the keep-list is EXACTLY {CACHE_VERSION, PAGES_CACHE}',
        JSON.stringify(versionKeyed.slice().sort()),
        JSON.stringify([sw.CACHE_VERSION, PAGES_EARLY].slice().sort()));

    check('RECENT_CACHE is version-free (survives a deploy)', versionFree.includes(RECENT));
    check('SAVED_CACHE is version-free (survives a deploy)', versionFree.includes(SAVED));
    check('MEDIA_CACHE is version-free (survives a deploy)', versionFree.includes(MEDIA));
    check('INDEX_CACHE is version-free (survives a deploy — THE #1962 G5 FIX)',
        versionFree.includes(INDEX));
    check('the two subsets are exhaustive and disjoint (partition the whole keep-list)',
        versionKeyed.length + versionFree.length === kept.length
        && versionKeyed.every(n => !versionFree.includes(n)));
}

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

    /* #1962 — the /song-media/<id> route (#853): the id is an opaque
       tblSongMedia.Id, not a SongId, so the songbook comes from the
       `?song=` query param SongData::_songMediaMap() now stamps. */
    eq('/song-media/<id>?song=<SongId>&v=… yields the SongId prefix (numeric id)',
        bookFromMedia('/song-media/123?song=MP-1008&v=abc123def456'), 'MP');
    eq('/song-media/<IL id>?song=<SongId> also resolves (id shape doesn\'t matter, only ?song= does)',
        bookFromMedia('/song-media/ILD0000012345?song=CP-0007&v=deadbeefcafe'), 'CP');
    eq('bare /song-media/<id> with NO ?song= yields null (nothing to attribute)',
        bookFromMedia('/song-media/123'), null);
    eq('/song-media/<id>?song=<junk> yields null rather than a wrong guess',
        bookFromMedia('/song-media/123?song=not-a-songid-shape'), null);
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

/* ======================================================================
 * 7 — #1921 SW half: conditional revalidation on songs_index
 * ==================================================================== */
console.log('\n#1921 — songs_index conditional revalidation (networkFirstRevalidated)\n');

/* The fetch-handler branch itself: MUST call networkFirstRevalidated(), not
   the plain networkFirstWithCache() (which has no notion of a validator
   header at all — see that function's own doc-block for why reusing it
   would be a silent no-op for this route, #1921 §1.2c). No nested braces
   inside this specific branch, so a non-greedy match to the first `}` after
   the opening one correctly bounds it. */
const songsIndexBranch = jsSource.match(
    /if\s*\(\s*url\.pathname\s*===\s*'\/api'\s*&&\s*url\.searchParams\.get\('action'\)\s*===\s*'songs_index'\s*\)\s*\{[\s\S]*?\}/
);
check('the songs_index fetch-handler branch was found', !!songsIndexBranch);
if (songsIndexBranch) {
    check('the songs_index branch dispatches to networkFirstRevalidated (NOT networkFirstWithCache)',
        /networkFirstRevalidated\s*\(/.test(songsIndexBranch[0]) && !/networkFirstWithCache\s*\(/.test(songsIndexBranch[0]),
        songsIndexBranch[0]);
}

/* Extract networkFirstRevalidated()'s own body by brace-depth counting (the
   function contains no braces inside any string/comment, so this is safe
   without a separate comment-stripping pass — unlike test-qr-cache.php's
   PHP-side extractor, which does strip comments first because THAT file's
   SQL-string literals are a real risk). */
function extractJsFunctionBody(src, fnName) {
    const idx = src.indexOf('function ' + fnName);
    if (idx === -1) { return null; }
    const openIdx = src.indexOf('{', idx);
    if (openIdx === -1) { return null; }
    let depth = 0;
    for (let i = openIdx; i < src.length; i++) {
        if (src[i] === '{') { depth++; }
        else if (src[i] === '}') {
            depth--;
            if (depth === 0) { return src.slice(openIdx, i + 1); }
        }
    }
    return null;
}
const nfrSrc = extractJsFunctionBody(jsSource, 'networkFirstRevalidated');
check('networkFirstRevalidated() source found in service-worker.js.php', !!nfrSrc);
if (nfrSrc) {
    check('sets If-None-Match from cached.headers.get(\'ETag\')',
        /cached\.headers\.get\(\s*['"]ETag['"]\s*\)/.test(nfrSrc));
    check('branches on response.status === 304', /response\.status\s*===\s*304/.test(nfrSrc));
    check('retains cache: \'no-store\' (the layered-cache-bypass fix)',
        /cache:\s*['"]no-store['"]/.test(nfrSrc));
}

/* Functional coverage — a SEPARATE tiny sandbox (not the `sw` object used by
   every assertion above) so `fetch` and `caches.open()` can be fully
   controlled per test case without disturbing sections 1-6. `fetch` is
   passed as an explicit Function parameter (shadowing Node's own global
   fetch, which would otherwise try a REAL network request against the fake
   ihymns.app origin) exactly the way `self`/`caches` already are above. */
let fetchImpl = async () => { throw new Error('fetchImpl not configured for this test case'); };
let cacheStore = { match: async () => undefined, put: async () => {} };
const fetchStub2 = (...args) => fetchImpl(...args);
const cachesStub2 = {
    open: async () => cacheStore,
    keys: async () => [],
    match: async () => undefined,
    delete: async () => false,
};
const selfStub2 = {
    addEventListener() { /* handlers registered but never dispatched here */ },
    location: { origin: 'https://www.ihymns.app' },
    registration: { active: null },
    clients: { matchAll: async () => [], claim: async () => {} },
    skipWaiting() {},
};

let nfr = null;
try {
    /* eslint-disable-next-line no-new-func */
    const sw2 = new Function(
        'self', 'caches', 'fetch',
        jsSource + "\n;return { networkFirstRevalidated: (typeof networkFirstRevalidated !== 'undefined' ? networkFirstRevalidated : undefined) };"
    )(selfStub2, cachesStub2, fetchStub2);
    nfr = sw2 && sw2.networkFirstRevalidated;
} catch (err) {
    console.error(`  FATAL could not evaluate the #1921 sandbox: ${err.message}`);
    failed++;
}
check('networkFirstRevalidated() harvested as a callable function', typeof nfr === 'function');

/* #1962 G5 — every call below now passes the `cacheName` param explicitly
   (INDEX_CACHE's real value), matching the fetch handler's own call site,
   even though `cachesStub2.open()` ignores its argument and would pass
   regardless — this keeps the test's call SHAPE honest as a form of
   documentation, not because the stub needs it. */
const NFR_CACHE_NAME = 'ihymns-index-v1';

if (typeof nfr === 'function') {
    /* Top-level `await` (this file is loaded as an ES module, package.json
       "type":"module") — each case MUST be awaited before the next runs, and
       ALL of them before the final summary/exit below, or their `check()`
       calls would still be pending microtasks when `process.exit()` fires
       and would simply never run (a silent 0-assertion pass, exactly the
       failure class this whole file exists to prevent elsewhere). */

    /* --- A: fresh install / purged cache — no cached copy, network 200 --- */
    cacheStore = { match: async () => undefined, putCalls: [], put: async function (req, res) { this.putCalls.push({ req, res }); } };
    let capturedOptsA = null;
    let capturedReqA = null;
    fetchImpl = async (req, opts) => {
        capturedReqA = req;
        capturedOptsA = opts;
        return new Response('{"songs":[]}', { status: 200, headers: { ETag: '"si1-fresh"' } });
    };
    const requestA = new Request('https://www.ihymns.app/api?action=songs_index');
    const resultA = await nfr(requestA, NFR_CACHE_NAME);
    check('A: fetch is called with cache: \'no-store\'', !!capturedOptsA && capturedOptsA.cache === 'no-store');
    check('A: no cached copy -> no If-None-Match is sent', !capturedReqA.headers.get('If-None-Match'));
    check('A: the 200 response is returned', resultA.status === 200);
    check('A: the 200 response is cached, keyed on the ORIGINAL request',
        cacheStore.putCalls.length === 1 && cacheStore.putCalls[0].req === requestA);

    /* --- B: cached copy exists, server says 304 (THE headline case) --- */
    const priorEtagB = '"si1-cached123"';
    const cachedResponseB = new Response('{"songs":["cached"]}', { status: 200, headers: { ETag: priorEtagB } });
    cacheStore = { match: async () => cachedResponseB, putCalls: [], put: async function (req, res) { this.putCalls.push({ req, res }); } };
    let capturedReqB = null;
    fetchImpl = async (req) => { capturedReqB = req; return new Response(null, { status: 304 }); };
    const requestB = new Request('https://www.ihymns.app/api?action=songs_index');
    const resultB = await nfr(requestB, NFR_CACHE_NAME);
    check('B: If-None-Match sent matches the cached response\'s own ETag', capturedReqB.headers.get('If-None-Match') === priorEtagB);
    check('B: the CACHED response is returned on a 304 (not a new/empty one)', resultB === cachedResponseB);
    check('B: cache.put is NEVER called on a 304 (a 304 has no body to store)', cacheStore.putCalls.length === 0);

    /* --- C: cached copy exists, catalogue actually changed (fresh 200) --- */
    const oldEtagC = '"si1-old"';
    const cachedResponseC = new Response('{"songs":["old"]}', { status: 200, headers: { ETag: oldEtagC } });
    cacheStore = { match: async () => cachedResponseC, putCalls: [], put: async function (req, res) { this.putCalls.push({ req, res }); } };
    const freshResponseC = new Response('{"songs":["new"]}', { status: 200, headers: { ETag: '"si1-new"' } });
    fetchImpl = async () => freshResponseC;
    const requestC = new Request('https://www.ihymns.app/api?action=songs_index');
    const resultC = await nfr(requestC, NFR_CACHE_NAME);
    check('C: the fresh 200 response is returned (not the stale cached one)', resultC === freshResponseC);
    check('C: the fresh response replaces the cache entry, keyed on the ORIGINAL request',
        cacheStore.putCalls.length === 1 && cacheStore.putCalls[0].req === requestC);

    /* --- D1/D2: network failure, WITH and WITHOUT a cached copy --- */
    const cachedResponseD = new Response('{"songs":["cached-d"]}', { status: 200, headers: { ETag: '"si1-d"' } });
    cacheStore = { match: async () => cachedResponseD, put: async () => {} };
    fetchImpl = async () => { throw new TypeError('simulated network failure'); };
    const requestD1 = new Request('https://www.ihymns.app/api?action=songs_index');
    const resultD1 = await nfr(requestD1, NFR_CACHE_NAME);
    check('D1: network failure WITH a cached copy returns the cached response', resultD1 === cachedResponseD);

    cacheStore = { match: async () => undefined, put: async () => {} };
    fetchImpl = async () => { throw new TypeError('simulated network failure'); };
    const requestD2 = new Request('https://www.ihymns.app/api?action=songs_index');
    const resultD2 = await nfr(requestD2, NFR_CACHE_NAME);
    check('D2: network failure with NO cached copy falls back to the offline response (503)', resultD2.status === 503);
}

/* ======================================================================
 * 8 — #1962: swIsMediaUrl() truth table — the 4 legacy shapes + the new
 * /song-media/<id> route, plus deliberate near-misses.
 * ==================================================================== */
console.log('\n#1962 — swIsMediaUrl(): every emitted media shape, and near-misses\n');

const isMediaUrl = need('swIsMediaUrl');
if (isMediaUrl) {
    eq('flat /data/audio/<SongId>.mp3', isMediaUrl('/data/audio/MP-1008.mp3'), true);
    eq('flat /data/music/<SongId>.pdf', isMediaUrl('/data/music/CP-0001.pdf'), true);
    eq('legacy nested /data/audio/<book>/<SongId>.mp3', isMediaUrl('/data/audio/MP/MP-1008.mp3'), true);
    eq('signed /audio/<SongId>.mp3?exp=…&sig=… (#1358)',
        isMediaUrl('/audio/SDAH-0123.mp3?exp=1799999999&sig=deadbeef'), true);
    eq('/song-media/<numeric id> (#853/#1962)', isMediaUrl('/song-media/123'), true);
    eq('/song-media/<IL id> (#1860 Phase 4)', isMediaUrl('/song-media/ILD0000012345'), true);
    eq('a root-relative absolute URL still matches',
        isMediaUrl('https://www.ihymns.app/song-media/123'), true);
    eq('an /api page fragment is NOT media', isMediaUrl('/api?page=song&id=MP-1008'), false);
    eq('a /song-media/<id>/<extra segment> is NOT media (the route is bare id only)',
        isMediaUrl('/song-media/1/evil'), false);
    eq('a malformed URL returns false rather than throwing', isMediaUrl('::::'), false);
}

/* ======================================================================
 * 9 — #1962: swMediaShouldRevalidate() staleness truth table
 * ==================================================================== */
console.log('\n#1962 — swMediaShouldRevalidate(): staleness truth table\n');

const shouldRevalidate = need('swMediaShouldRevalidate');
const TTL = need('MEDIA_REVALIDATE_TTL_MS');
if (shouldRevalidate && typeof TTL === 'number') {
    const now = Date.parse('2026-08-26T12:00:00Z');
    const fresh = new Date(now - 1000).toUTCString();          /* 1s old */
    const stale = new Date(now - TTL - 1000).toUTCString();     /* just over the TTL */
    const boundary = new Date(now - TTL).toUTCString();         /* exactly at the TTL */

    eq('a fresh Date header → do NOT revalidate', shouldRevalidate(fresh, now, TTL), false);
    eq('a Date header older than the TTL → revalidate', shouldRevalidate(stale, now, TTL), true);
    eq('exactly at the TTL boundary → revalidate (>=, not >)', shouldRevalidate(boundary, now, TTL), true);
    eq('a missing Date header → revalidate (the SAFER default, never "skip forever")',
        shouldRevalidate(null, now, TTL), true);
    eq('an unparseable Date header → revalidate (same safer default)',
        shouldRevalidate('not-a-date', now, TTL), true);
    eq('an empty-string Date header → revalidate', shouldRevalidate('', now, TTL), true);
}

/* ======================================================================
 * 10 — #1962: swMediaSupersededKeys() — same-pathname-only sweep
 * ==================================================================== */
console.log('\n#1962 — swMediaSupersededKeys(): same-pathname-only, never cross-song\n');

const supersededKeys = need('swMediaSupersededKeys');
if (supersededKeys) {
    const sameSongOld = 'https://www.ihymns.app/song-media/9?song=MP-1008&v=old111111111';
    const sameSongNew = 'https://www.ihymns.app/song-media/9?song=MP-1008&v=new222222222';
    const otherSong   = 'https://www.ihymns.app/song-media/12?song=MP-1009&v=old111111111';
    const keys = [{ url: sameSongOld }, { url: sameSongNew }, { url: otherSong }];

    const doomed = supersededKeys(keys, sameSongNew);
    eq('exactly ONE key is superseded (the OLD rendition of the SAME pathname)', doomed.length, 1);
    check('the superseded key is the old /song-media/9 entry',
        doomed.length === 1 && doomed[0].url === sameSongOld,
        `doomed: ${doomed.map(k => k.url).join(', ')}`);
    check('a DIFFERENT tblSongMedia row (/song-media/12) is NEVER superseded',
        !doomed.some(k => k.url === otherSong));
    eq('the just-cached URL itself is never returned as its own supersession',
        supersededKeys(keys, sameSongNew).some(k => k.url === sameSongNew), false);
    eq('no siblings under this pathname → nothing to delete',
        supersededKeys([{ url: otherSong }], sameSongNew).length, 0);
    eq('a malformed justCachedUrl returns [] rather than throwing',
        supersededKeys(keys, '::::').length, 0);
}

/* ======================================================================
 * 11 — #1962: swMediaValidators() extraction
 * ==================================================================== */
console.log('\n#1962 — swMediaValidators(): ETag / Last-Modified extraction\n');

const mediaValidators = need('swMediaValidators');
if (mediaValidators) {
    const lm = 'Wed, 21 Oct 2015 07:28:00 GMT';
    eq('ETag only', JSON.stringify(mediaValidators(new Headers({ ETag: '"x"' }))),
        JSON.stringify({ etag: '"x"', lastModified: null }));
    eq('Last-Modified only', JSON.stringify(mediaValidators(new Headers({ 'Last-Modified': lm }))),
        JSON.stringify({ etag: null, lastModified: lm }));
    eq('both present', JSON.stringify(mediaValidators(new Headers({ ETag: '"x"', 'Last-Modified': lm }))),
        JSON.stringify({ etag: '"x"', lastModified: lm }));
    eq('neither present → null (nothing safe to revalidate with)',
        mediaValidators(new Headers()), null);
}

/* ======================================================================
 * 12 — #1962: swMediaBulkPlan() skip/fetch/conditional truth table
 * ==================================================================== */
console.log('\n#1962 — swMediaBulkPlan(): CACHE_AUDIO_URLS per-url decision table\n');

const bulkPlan = need('swMediaBulkPlan');
if (bulkPlan) {
    eq('exact hit + no validators → skip (nothing safe to ask, nothing to fetch)',
        bulkPlan(true, null), 'skip');
    eq('exact hit + validators → conditional (worth a cheap 304 check)',
        bulkPlan(true, { etag: '"x"', lastModified: null }), 'conditional');
    eq('no exact hit but a SIBLING carries validators → conditional',
        bulkPlan(false, { etag: null, lastModified: 'Wed, 21 Oct 2015 07:28:00 GMT' }), 'conditional');
    eq('no exact hit, no sibling, no validators → fetch (nothing cached at all)',
        bulkPlan(false, null), 'fetch');
}

/* ======================================================================
 * 13 — #1962: bucket-wiring verified from SOURCE (never a re-implementation
 * of the policy — tree-derived, mirrors rule #34's "derive, don't type").
 * ==================================================================== */
console.log('\n#1962 — bucket wiring + branch ordering, verified from source\n');

const mediaBranchIdx    = jsSource.indexOf('if (swIsMediaUrl(event.request.url))');
const navigateBranchIdx = jsSource.indexOf("event.request.mode === 'navigate'");
check('the media branch (swIsMediaUrl) was found in source', mediaBranchIdx !== -1);
check('the navigate branch was found in source', navigateBranchIdx !== -1);
check('the media branch PRECEDES the navigate branch — an `<a download>` click '
    + 'dispatches as a `navigate` request, so ordering is load-bearing (#1962)',
    mediaBranchIdx !== -1 && navigateBranchIdx !== -1 && mediaBranchIdx < navigateBranchIdx,
    `mediaBranchIdx=${mediaBranchIdx} navigateBranchIdx=${navigateBranchIdx}`);

const mediaBranchSrc = (mediaBranchIdx !== -1 && navigateBranchIdx !== -1)
    ? jsSource.slice(mediaBranchIdx, navigateBranchIdx)
    : '';
check('the media branch opens MEDIA_CACHE', /caches\.open\(MEDIA_CACHE\)/.test(mediaBranchSrc));
check('the media branch calls swRevalidateMediaEntry (background revalidation is wired up)',
    /swRevalidateMediaEntry\(/.test(mediaBranchSrc));
check('the media branch calls swMediaSupersededKeys after a fresh cache.put (rendition sweep is wired up)',
    /swMediaSupersededKeys\(/.test(mediaBranchSrc));
{
    /* COMMENT-STRIPPED before this specific pair of checks. The branch's own
       doc-comment discusses BOTH `res.status === 200` and `res.ok` by name
       (explaining why one is used and not the other) — searching the RAW
       source for those exact substrings would find them in the COMMENT
       first, regardless of what the CODE below it actually does, which is
       precisely the "wrong-but-green" trap CLAUDE.md rule #34 warns about
       (confirmed empirically: mutation (6) in the #1962 plan — swapping the
       real `if` condition to bare `res.ok` — passed silently against the
       raw-source version of this exact check during red/green proving,
       because the comment's own mention of "res.status === 200" still
       preceded the (unrelated) cache.put() match). Only block comments are
       stripped here (not double-slash line comments) — good enough for this
       narrow, single-branch slice, and safer than a full stripper that
       could mis-handle a double-slash inside a string literal (e.g. a URL)
       if this helper were ever reused on a wider slice. */
    const mediaBranchCode = mediaBranchSrc.replace(/\/\*[\s\S]*?\*\//g, '');
    const statusIdx = mediaBranchCode.indexOf('res.status === 200');
    const putIdx = mediaBranchCode.indexOf('cache.put(event.request, res.clone())');
    check('the media branch checks `res.status === 200` BEFORE its cache.put '
        + '(never bare `.ok` — a 206 Partial Content would poison the cache)',
        statusIdx !== -1 && putIdx !== -1 && statusIdx < putIdx,
        `statusIdx=${statusIdx} putIdx=${putIdx}`);
    check('the media branch never gates its cache.put on bare `res.ok` '
        + '(matches `res.ok` as a whole word, so `res.ok && …` is ALSO caught, not just an exact `if (res.ok)`)',
        !/if\s*\(\s*res\.ok\b/.test(mediaBranchCode),
        mediaBranchCode.match(/if\s*\([^)]*res\.ok[^)]*\)/)?.[0]);
}

const cacheAllSongsIdx  = jsSource.indexOf("event.data.type === 'CACHE_ALL_SONGS'");
const cacheAudioUrlsIdx = jsSource.indexOf("event.data.type === 'CACHE_AUDIO_URLS'");
const evictSongbookIdx  = jsSource.indexOf("event.data.type === 'EVICT_SONGBOOK'");
check('the CACHE_ALL_SONGS handler was found', cacheAllSongsIdx !== -1);
check('the CACHE_AUDIO_URLS handler was found', cacheAudioUrlsIdx !== -1);
check('the EVICT_SONGBOOK handler was found', evictSongbookIdx !== -1);

const cacheAllSongsSrc = (cacheAllSongsIdx !== -1 && cacheAudioUrlsIdx !== -1)
    ? jsSource.slice(cacheAllSongsIdx, cacheAudioUrlsIdx)
    : '';
check('CACHE_ALL_SONGS opens SAVED_CACHE (the deliberate-download bucket, #1597 RC1)',
    /caches\.open\(SAVED_CACHE\)/.test(cacheAllSongsSrc));
check('CACHE_ALL_SONGS ALSO warms PAGES_CACHE per songbook (#1962 G4)',
    /caches\.open\(PAGES_CACHE\)/.test(cacheAllSongsSrc));

const cacheAudioUrlsSrc = (cacheAudioUrlsIdx !== -1 && evictSongbookIdx !== -1)
    ? jsSource.slice(cacheAudioUrlsIdx, evictSongbookIdx)
    : '';
check('CACHE_AUDIO_URLS opens MEDIA_CACHE', /caches\.open\(MEDIA_CACHE\)/.test(cacheAudioUrlsSrc));
check('CACHE_AUDIO_URLS decides its per-url plan via swMediaBulkPlan (no re-implemented skip logic)',
    /swMediaBulkPlan\(/.test(cacheAudioUrlsSrc));
check('CACHE_AUDIO_URLS also sweeps superseded renditions (swMediaSupersededKeys)',
    /swMediaSupersededKeys\(/.test(cacheAudioUrlsSrc));

const activateIdx = jsSource.indexOf("self.addEventListener('activate'");
const fetchListenerIdx = jsSource.indexOf("self.addEventListener('fetch'");
const activateSrc = (activateIdx !== -1 && fetchListenerIdx !== -1)
    ? jsSource.slice(activateIdx, fetchListenerIdx)
    : '';
check('activate() was found', activateIdx !== -1);
check('activate() re-warms saved songbook pages after the legacy migration (#1962 G4)',
    /swRewarmSavedSongbookPages\(\)/.test(activateSrc));

/* ======================================================================
 * 14 — #1962: swRevalidateMediaEntry() functional sandbox — a SEPARATE
 * fetch-controllable sandbox, mirroring the networkFirstRevalidated()
 * pattern in section 7 above (`sw`/`sw2` are not reused so this section
 * cannot disturb their state).
 * ==================================================================== */
console.log('\n#1962 — swRevalidateMediaEntry(): functional sandbox\n');

let mediaFetchImpl = async () => { throw new Error('mediaFetchImpl not configured for this test case'); };
const mediaFetchStub = (...args) => mediaFetchImpl(...args);
const mediaCachesStub = {
    open: async () => ({ keys: async () => [], match: async () => undefined }),
    keys: async () => [],
    match: async () => undefined,
    delete: async () => false,
};
const mediaSelfStub = {
    addEventListener() { /* handlers registered but never dispatched here */ },
    location: { origin: 'https://www.ihymns.app' },
    registration: { active: null },
    clients: { matchAll: async () => [], claim: async () => {} },
    skipWaiting() {},
};

let revalidateEntry = null;
try {
    /* eslint-disable-next-line no-new-func */
    const sw3 = new Function(
        'self', 'caches', 'fetch',
        jsSource + "\n;return { swRevalidateMediaEntry: (typeof swRevalidateMediaEntry !== 'undefined' ? swRevalidateMediaEntry : undefined) };"
    )(mediaSelfStub, mediaCachesStub, mediaFetchStub);
    revalidateEntry = sw3 && sw3.swRevalidateMediaEntry;
} catch (err) {
    console.error(`  FATAL could not evaluate the #1962 revalidation sandbox: ${err.message}`);
    failed++;
}
check('swRevalidateMediaEntry() harvested as a callable function', typeof revalidateEntry === 'function');

function fakeMediaCache() {
    const putCalls = [];
    return { putCalls, put: async (url, res) => { putCalls.push({ url, res }); } };
}

if (typeof revalidateEntry === 'function') {
    const MEDIA_URL = 'https://www.ihymns.app/song-media/9?song=MP-1008&v=abc123def456';

    /* --- A: no validators at all → NEVER blind-refetch (the core #1962 invariant-B guard) --- */
    {
        const cache = fakeMediaCache();
        let fetchCalled = false;
        mediaFetchImpl = async () => { fetchCalled = true; return new Response('', { status: 200 }); };
        const cachedNoValidators = new Response('audio-bytes', { status: 200, headers: {} });
        await revalidateEntry(cache, MEDIA_URL, cachedNoValidators);
        check('A: no ETag/Last-Modified on the cached entry → fetch is NEVER called',
            !fetchCalled);
        check('A: no ETag/Last-Modified → cache.put is NEVER called', cache.putCalls.length === 0);
    }

    /* --- B: ETag present, server says 304 (THE steady-state case) --- */
    {
        const cache = fakeMediaCache();
        const priorEtag = '"media-abc123"';
        let capturedReq = null;
        let capturedOpts = null;
        mediaFetchImpl = async (req, opts) => {
            capturedReq = req;
            capturedOpts = opts;
            return new Response(null, { status: 304 });
        };
        const cachedWithEtag = new Response('audio-bytes', { status: 200, headers: { ETag: priorEtag } });
        await revalidateEntry(cache, MEDIA_URL, cachedWithEtag);
        check('B: If-None-Match sent matches the cached entry\'s own ETag',
            capturedReq.headers.get('If-None-Match') === priorEtag);
        check('B: cache: \'no-store\' bypasses the browser HTTP cache (same fix as networkFirstRevalidated)',
            !!capturedOpts && capturedOpts.cache === 'no-store');
        check('B: credentials: \'same-origin\' is set on the Request (no cross-origin cookie leakage)',
            capturedReq.credentials === 'same-origin');
        check('B: NO Range header is ever attached to a revalidation request '
            + '(this asks about the WHOLE resource, never a byte window)',
            !capturedReq.headers.get('Range'));
        check('B: a 304 → cache.put is NEVER called (a 304 has no body to store)',
            cache.putCalls.length === 0);
    }

    /* --- C: ETag present, server says 200 (genuine content change) --- */
    {
        const cache = fakeMediaCache();
        mediaFetchImpl = async () => new Response('new-bytes', { status: 200, headers: {} });
        const cachedWithEtag = new Response('old-bytes', { status: 200, headers: { ETag: '"old"' } });
        await revalidateEntry(cache, MEDIA_URL, cachedWithEtag);
        check('C: a genuine 200 IS written to the cache', cache.putCalls.length === 1);
        check('C: written under the SAME key (url) that was passed in — replace IN PLACE',
            cache.putCalls.length === 1 && cache.putCalls[0].url === MEDIA_URL);
    }

    /* --- D: Last-Modified only (no ETag) → falls back to If-Modified-Since --- */
    {
        const cache = fakeMediaCache();
        let capturedReq = null;
        const lm = 'Wed, 21 Oct 2015 07:28:00 GMT';
        mediaFetchImpl = async (req) => { capturedReq = req; return new Response(null, { status: 304 }); };
        const cachedWithLm = new Response('audio-bytes', { status: 200, headers: { 'Last-Modified': lm } });
        await revalidateEntry(cache, MEDIA_URL, cachedWithLm);
        check('D: falls back to If-Modified-Since when there is no ETag',
            capturedReq.headers.get('If-Modified-Since') === lm);
        check('D: If-None-Match is NOT sent when there is no ETag to send',
            !capturedReq.headers.get('If-None-Match'));
    }

    /* --- E: a network throw (offline / hiccup) leaves the cache untouched --- */
    {
        const cache = fakeMediaCache();
        mediaFetchImpl = async () => { throw new TypeError('simulated offline'); };
        const cachedWithEtag = new Response('audio-bytes', { status: 200, headers: { ETag: '"e"' } });
        await revalidateEntry(cache, MEDIA_URL, cachedWithEtag);
        check('E: a network throw leaves the cache completely untouched (no put call — invariant A)',
            cache.putCalls.length === 0);
    }

    /* --- F: a non-304, non-200 response (403/404/5xx) also leaves the cache untouched --- */
    {
        const cache = fakeMediaCache();
        mediaFetchImpl = async () => new Response('', { status: 404 });
        const cachedWithEtag = new Response('audio-bytes', { status: 200, headers: { ETag: '"e"' } });
        await revalidateEntry(cache, MEDIA_URL, cachedWithEtag);
        check('F: a 403/404/5xx response is NEVER written to the cache (invariant A holds even on error)',
            cache.putCalls.length === 0);
    }
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
