/**
 * tests/test-offline-media-coverage.js — tree-derived coverage guard for
 * #1962 (offline-download persistence fix).
 *
 * ELI5: this file goes and reads every PHP/JS file that actually PRODUCES a
 * media URL (a song's audio, sheet-music PDF, MIDI file), builds one
 * realistic sample URL per producer, and checks the Service Worker's own
 * `swIsMediaUrl()` / `swSongbookFromMediaUrl()` helpers recognise every
 * single one. If a future change adds a new place that emits a media URL in
 * a shape the Service Worker doesn't know about, that file would silently
 * fall through to the generic app-shell caching branch — CLAUDE.md rule #6
 * invariant (A) says a deploy must never evict user-downloaded content, and
 * a media file wrongly cached into the versioned app-shell bucket
 * (CACHE_VERSION) gets evicted on the very next deploy. This suite exists
 * so that silent failure mode goes RED instead.
 *
 * WHY "TREE-DERIVED" MATTERS (CLAUDE.md rule #34): a hand-typed list of
 * "the URL shapes I remember" is exactly the kind of guard that has, in
 * this repo's history, stayed green while the thing it named was broken
 * elsewhere (#1676, #1648). So every sample URL below is built from a
 * REGEX MATCH against the actual, current source of the file that emits
 * it — never from a shape typed here from memory. If a producer's emit
 * line changes shape (a literal moves, a helper is renamed, a query param
 * is dropped), the regex match fails FIRST — before the URL-matching
 * assertion even runs — so a shape drift is caught even if the new shape
 * would, by coincidence, still match swIsMediaUrl().
 *
 * COVERAGE — every media-URL PRODUCER in the tree, as of #1962:
 *   1. SongData::_songMediaMap()   → /song-media/<id>?song=<SongId>&v=<sha>
 *      (includes/SongData.php)       (public song page, api2.php-adjacent
 *                                     public reads, and now bulk_audio too)
 *   2. index.php config JSON       → audioBasePath ('/data/audio/') consumed
 *      + js/modules/audio.js          by audio.js as <base><SongId>.mid
 *   3. index.php config JSON       → musicBasePath ('/data/music/') consumed
 *      + js/modules/sheet-music.js    by sheet-music.js as <base><SongId>.pdf
 *   4. api.php bulk_audio          → the legacy static '/data/audio/<SongId>.mp3'
 *      (literal, offline manifest)    literal, PLUS (#1962) the registry
 *                                     entries from producer #1 appended to
 *                                     the SAME manifest, PLUS the #1358
 *                                     signing rewrite guarded to ONLY
 *                                     /data/audio/ entries (never re-signing
 *                                     a /song-media/ streamUrl).
 *   5. includes/audio_signing.php  → the signed '/audio/<SongId>.mp3?exp=&sig='
 *      audioSignedUrlFor()            shape (#1358), when signing is enabled.
 *   6. audio-media.php             → (not a URL producer) — asserted to
 *                                     carry the #1962 conditional-GET
 *                                     validator block (ETag/Last-Modified/
 *                                     If-None-Match/If-Modified-Since),
 *                                     mirroring song-media.php's #1452 one.
 *
 * @see appWeb/public_html/service-worker.js.php   swIsMediaUrl(), swSongbookFromMediaUrl()
 * @see tests/test-offline-cache-policy.js         the sibling policy-logic suite
 * @see https://github.com/MWBMPartners/iHymns/issues/1962
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.join(__dirname, '..');

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

function readSrc(relPath) {
    return fs.readFileSync(path.join(ROOT, relPath), 'utf8');
}

/* ======================================================================
 * Load the REAL service worker's media helpers (same harness pattern as
 * tests/test-offline-cache-policy.js — strip the PHP head, evaluate the
 * REAL shipped JS body, harvest the pure functions).
 * ==================================================================== */

const SW_FILE = process.env.IHYMNS_SW_FILE || path.join(
    ROOT, 'appWeb', 'public_html', 'service-worker.js.php'
);
const rawSwSource = fs.readFileSync(SW_FILE, 'utf8');
const phpEnd = rawSwSource.indexOf('?>');
check('service-worker.js.php has a PHP head to strip', phpEnd !== -1);

const STUB_VERSION = '9.9.9-20260826';
const swJsSource = rawSwSource
    .slice(phpEnd + 2)
    .replace(/<\?=\s*\$swCacheKey\s*\?>/g, STUB_VERSION);

const selfStub = {
    addEventListener() { /* handlers registered but never dispatched here */ },
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
    sw = new Function(
        'self', 'caches',
        swJsSource + "\n;return { swIsMediaUrl: (typeof swIsMediaUrl !== 'undefined' ? swIsMediaUrl : undefined), "
            + "swSongbookFromMediaUrl: (typeof swSongbookFromMediaUrl !== 'undefined' ? swSongbookFromMediaUrl : undefined) };"
    )(selfStub, cachesStub);
} catch (err) {
    console.error(`  FATAL could not evaluate the service worker body: ${err.message}`);
    console.log('\n0 passed, 1 failed');
    process.exit(1);
}

const isMediaUrl = sw.swIsMediaUrl;
const bookFromMediaUrl = sw.swSongbookFromMediaUrl;
check('swIsMediaUrl() harvested from service-worker.js.php', typeof isMediaUrl === 'function');
check('swSongbookFromMediaUrl() harvested from service-worker.js.php', typeof bookFromMediaUrl === 'function');

/**
 * Assert one derived sample URL against BOTH SW helpers in one place, so
 * every producer below reads as a single declarative line.
 *
 * @param {string} label What's being tested (shows in FAIL output)
 * @param {string} url The derived sample URL
 * @param {string} expectedBook Upper-cased expected songbook abbreviation
 */
function assertCovered(label, url, expectedBook) {
    if (typeof isMediaUrl === 'function') {
        check(`${label}: swIsMediaUrl() recognises it`, isMediaUrl(url) === true, `url=${url}`);
    }
    if (typeof bookFromMediaUrl === 'function') {
        eq(`${label}: swSongbookFromMediaUrl() attributes it to '${expectedBook}'`,
            bookFromMediaUrl(url), expectedBook);
    }
}

/* ======================================================================
 * Producer 1 — SongData::_songMediaMap() streamUrl (#853/#1962)
 * ==================================================================== */
console.log('#1962 — producer 1: SongData::_songMediaMap() streamUrl\n');

const songDataSrc = readSrc('appWeb/public_html/includes/SongData.php');

/* The exact concatenation, captured from the REAL source — a change to
   ANY of these three pieces (the id, `?song=`, `&v=`) fails HERE, before
   the URL-recognition assertion below even runs. */
const streamUrlEmit = songDataSrc.match(
    /'streamUrl'\s*=>\s*'\/song-media\/'\s*\.\s*\(int\)\$row\['Id'\]\s*\n\s*\.\s*'\?song='\s*\.\s*rawurlencode\(\$sid\)\s*\n\s*\.\s*'&v='\s*\.\s*substr\(\(string\)\$row\['Sha256'\],\s*0,\s*12\)/
);
check('SongData::_songMediaMap() emits the /song-media/<id>?song=&v= shape (rules #21/#27)', !!streamUrlEmit);

/* Never keyed on UpdatedAt (CLAUDE.md rule #6 invariant B — an
   annotation-only edit must not force a re-download). This is the guard
   mutation (8) in the #1962 plan targets. */
check('the &v= cache-buster is keyed on Sha256, NEVER UpdatedAt',
    !/'&v='\s*\.\s*[^;]*UpdatedAt/.test(songDataSrc));

/* Public accessor exists and is reachable from api.php's bulk_audio — see
   Producer 4 below for the api.php-side half of this wiring. */
check('SongData exposes a PUBLIC accessor over the private media map (getAudioMediaStreamUrls)',
    /public function getAudioMediaStreamUrls\(array \$songIds\)/.test(songDataSrc));

if (streamUrlEmit) {
    /* id=42, SongId='MP-1008', Sha256 prefix='abc123def456' — a realistic
       sample built from the CONFIRMED shape above, not typed from memory. */
    assertCovered('SongData streamUrl (numeric tblSongMedia.Id)',
        '/song-media/42?song=MP-1008&v=abc123def456', 'MP');
    /* #1860 Phase 4 — an IL-prefixed id resolves the SAME way server-side
       (song-media.php's dual-addressing pre-step); the SW-side shape is
       identical since `<id>` is opaque to swIsMediaUrl() either way. */
    assertCovered('SongData streamUrl (IL-prefixed tblSongMedia id)',
        '/song-media/ILD0000012345?song=CP-0007&v=deadbeefcafe', 'CP');
}

/* ======================================================================
 * Producer 2 — index.php audioBasePath, consumed by audio.js (.mid)
 * ==================================================================== */
console.log('\n#1962 — producer 2: index.php audioBasePath → audio.js MIDI URLs\n');

const indexSrc = readSrc('appWeb/public_html/index.php');
const audioBaseMatch = indexSrc.match(/'audioBasePath'\s*=>\s*'([^']+)'/);
check('index.php emits config.audioBasePath', !!audioBaseMatch);

const audioJsSrc = readSrc('appWeb/public_html/js/modules/audio.js');
const audioMidConcat = /this\.app\.config\.audioBasePath\s*\+\s*songId\s*\+\s*'\.mid'/.test(audioJsSrc);
check('audio.js concatenates audioBasePath + songId + \'.mid\' (fetchMidi)', audioMidConcat);

if (audioBaseMatch && audioMidConcat) {
    assertCovered('audioBasePath + SongId + .mid (MIDI)',
        audioBaseMatch[1] + 'MP-1008' + '.mid', 'MP');
}

/* ======================================================================
 * Producer 3 — index.php musicBasePath, consumed by sheet-music.js (.pdf)
 * ==================================================================== */
console.log('\n#1962 — producer 3: index.php musicBasePath → sheet-music.js PDF URLs\n');

const musicBaseMatch = indexSrc.match(/'musicBasePath'\s*=>\s*'([^']+)'/);
check('index.php emits config.musicBasePath', !!musicBaseMatch);

const sheetMusicSrc = readSrc('appWeb/public_html/js/modules/sheet-music.js');
const musicPdfConcat = /this\.app\.config\.musicBasePath\s*\+\s*songId\s*\+\s*'\.pdf'/.test(sheetMusicSrc);
check('sheet-music.js concatenates musicBasePath + songId + \'.pdf\'', musicPdfConcat);

if (musicBaseMatch && musicPdfConcat) {
    assertCovered('musicBasePath + SongId + .pdf (sheet music)',
        musicBaseMatch[1] + 'CP-0001' + '.pdf', 'CP');
}

/* ======================================================================
 * Producer 4 — api.php bulk_audio: legacy static literal + the #1962
 * registry-append + the #1358 signing-rewrite guard
 * ==================================================================== */
console.log('\n#1962 — producer 4: api.php bulk_audio (static literal + registry append + signing guard)\n');

const apiSrc = readSrc('appWeb/public_html/api.php');

const staticLiteralEmit = /'url'\s*=>\s*'\/data\/audio\/'\s*\.\s*rawurlencode\(\$sid\)\s*\.\s*'\.mp3'/.test(apiSrc);
check('bulk_audio emits the legacy /data/audio/<SongId>.mp3 static literal', staticLiteralEmit);
if (staticLiteralEmit) {
    assertCovered('bulk_audio static literal /data/audio/<SongId>.mp3',
        '/data/audio/MP-1008.mp3', 'MP');
}

/* #1962 — the NEW registry-append: bulk_audio must actually call the
   Producer-1 accessor, or the manifest silently keeps missing every
   curator-uploaded (tblSongMedia-only) audio file. */
check('bulk_audio appends the tblSongMedia registry via getAudioMediaStreamUrls() (#1962)',
    /\$songData->getAudioMediaStreamUrls\(\$audioAllSongIds\)/.test(apiSrc));
check('bulk_audio scopes the registry append to THIS songbook\'s SongIds (rule #17 — no whole-corpus scan)',
    /\$audioAllSongIds\s*=\s*array_column\(\$audioSongs,\s*'id'\)/.test(apiSrc));

/* #1962 mutation (9) — the signing rewrite MUST be guarded to /data/audio/
   entries only, or a /song-media/ streamUrl gets its own ?song=&v= query
   silently discarded by audioSignedUrlFor()'s replacement URL. */
const signGuardSrc = apiSrc.match(
    /foreach \(\$manifest as &\$audioEntry\) \{[\s\S]*?unset\(\$audioEntry\);/
);
check('the #1358 signing-rewrite loop over $manifest was found', !!signGuardSrc);
if (signGuardSrc) {
    const guardIdx = signGuardSrc[0].indexOf("str_starts_with((string)\$audioEntry['url'], '/data/audio/')");
    const signIdx = signGuardSrc[0].indexOf('$signed = audioSignedUrlFor(');
    check('the signing rewrite checks the /data/audio/ prefix BEFORE calling audioSignedUrlFor()',
        guardIdx !== -1 && signIdx !== -1 && guardIdx < signIdx,
        `guardIdx=${guardIdx} signIdx=${signIdx}`);
}

/* ======================================================================
 * Producer 5 — includes/audio_signing.php audioSignedUrlFor() (#1358)
 * ==================================================================== */
console.log('\n#1962 — producer 5: audio_signing.php signed /audio/ URLs (#1358)\n');

const signingSrc = readSrc('appWeb/public_html/includes/audio_signing.php');
const signedUrlEmit = /'\/audio\/'\s*\.\s*rawurlencode\(\$songId\)\s*\.\s*'\.mp3\?exp='\s*\.\s*\$exp\s*\.\s*'&sig='\s*\.\s*\$sig/.test(signingSrc);
check('audioSignedUrlFor() emits the /audio/<SongId>.mp3?exp=&sig= shape', signedUrlEmit);
if (signedUrlEmit) {
    assertCovered('audioSignedUrlFor() signed URL',
        '/audio/SDAH-0123.mp3?exp=1799999999&sig=deadbeef', 'SDAH');
}

/* ======================================================================
 * Producer 6 (non-URL) — audio-media.php carries the #1962 conditional-GET
 * validator block, mirroring song-media.php's own #1452 one.
 * ==================================================================== */
console.log('\n#1962 — audio-media.php carries #1452-style conditional-GET validators\n');

const audioMediaSrc = readSrc('appWeb/public_html/audio-media.php');
check('audio-media.php emits an ETag header', /header\(\s*'ETag:/.test(audioMediaSrc));
check('audio-media.php emits a Last-Modified header', /header\(\s*'Last-Modified:/.test(audioMediaSrc));
check('audio-media.php reads If-None-Match', /HTTP_IF_NONE_MATCH/.test(audioMediaSrc));
check('audio-media.php reads If-Modified-Since', /HTTP_IF_MODIFIED_SINCE/.test(audioMediaSrc));
check('audio-media.php can short-circuit to a 304', /http_response_code\(304\)/.test(audioMediaSrc));

/* The validator block must sit AFTER every access gate (never leak
   "unchanged" to a denied caller) and BEFORE the byte-volume rate limiter
   / streaming body (a 304 has no body to send). */
const signingGateEnd = audioMediaSrc.indexOf("if (audioSigningEnabled()) {");
const rateLimitCall = audioMediaSrc.indexOf("enforceReadRateLimitKeyed('media', 240);");
const etagHeaderIdx = audioMediaSrc.indexOf("header('ETag: ' . \$condEtag);");
check('the conditional-GET block sits AFTER the signing/access-gate block', signingGateEnd !== -1 && etagHeaderIdx !== -1 && signingGateEnd < etagHeaderIdx);
check('the conditional-GET block sits BEFORE the byte-volume rate limiter / streaming body',
    etagHeaderIdx !== -1 && rateLimitCall !== -1 && etagHeaderIdx < rateLimitCall);

/* ======================================================================
 * Negative controls — things that must NEVER be mistaken for media
 * ==================================================================== */
console.log('\n#1962 — negative controls: non-media URLs must NOT match\n');

if (typeof isMediaUrl === 'function') {
    eq('a page fragment (?page=song) is not media', isMediaUrl('/api?page=song&id=MP-1008'), false);
    eq('a /song-media/ URL with a trailing path segment is not media', isMediaUrl('/song-media/1/evil'), false);
    eq('the app shell root is not media', isMediaUrl('/'), false);
    eq('a JS module is not media', isMediaUrl('/js/app.js'), false);
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
