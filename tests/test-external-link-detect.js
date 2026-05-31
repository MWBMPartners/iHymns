/**
 * iHymns — External-Link Provider Auto-Detect Unit Tests (#841)
 *
 * Loads the IIFE module via a minimal `window` shim and exercises
 * detectFromUrl() against every provider rule + a clutch of edge
 * cases. No DOM, no jsdom — the module itself only touches `URL`
 * and `window.iHymnsLinkDetect`.
 *
 * USAGE:
 *   node tests/test-external-link-detect.js
 */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const MODULE_PATH = path.resolve(
    __dirname,
    '..',
    'appWeb',
    'public_html',
    'js',
    'modules',
    'external-link-detect.js'
);

/* Sandbox minimal browser globals the IIFE expects. */
const sandbox = {
    window: {},
    URL,
    Event: class { constructor(t) { this.type = t; } },
    setTimeout,
};
sandbox.global = sandbox;

const code = fs.readFileSync(MODULE_PATH, 'utf8');
vm.createContext(sandbox);
vm.runInContext(code, sandbox);

const detect = sandbox.window.iHymnsLinkDetect;
assert.ok(detect, 'iHymnsLinkDetect should be exposed on window');
assert.ok(typeof detect.detectFromUrl === 'function');

let passed = 0;
let failed = 0;
function check(input, expected) {
    const got = detect.detectFromUrl(input);
    if (got === expected) {
        passed++;
    } else {
        failed++;
        console.error(`FAIL  detectFromUrl(${JSON.stringify(input)}) → ${JSON.stringify(got)}, expected ${JSON.stringify(expected)}`);
    }
}

/* Happy-path provider matches. */
check('https://en.wikipedia.org/wiki/Amazing_Grace',                  'wikipedia');
check('https://de.wikipedia.org/wiki/Amazing_Grace',                  'wikipedia');
check('https://www.wikidata.org/wiki/Q123',                           'wikidata');
check('https://hymnary.org/text/amazing_grace_how_sweet_the_sound',   'hymnary-org');
check('https://hymnalplus.com/song/123',                              'hymnal-plus');
check('https://www.hymntime.com/tch/htm/a/m/a/amazingg.htm',          'cyber-hymnal');
check('https://archive.org/details/hymns123',                         'internet-archive');
check('https://openlibrary.org/books/OL123M',                         'open-library');
check('https://www.worldcat.org/oclc/12345',                          'oclc-worldcat');
check('https://viaf.org/viaf/123/',                                   'viaf');
check('https://id.loc.gov/authorities/names/n12345',                  'loc-name-authority');
check('https://www.findagrave.com/memorial/123/john-newton',          'find-a-grave');
check('https://songselect.ccli.com/Songs/22025',                      'ccli-songselect');
check('https://imslp.org/wiki/Foo',                                   'imslp');
check('https://www.youtube.com/watch?v=abc',                          'youtube');
check('https://youtu.be/abc',                                         'youtube');
check('https://m.youtube.com/watch?v=abc',                            'youtube');
check('https://music.youtube.com/playlist?list=xyz',                  'youtube-music'); /* must beat youtube */
check('https://vimeo.com/123456',                                     'vimeo');
check('https://open.spotify.com/track/abc',                           'spotify');
check('https://music.apple.com/us/album/foo',                         'apple-music');
check('https://artist.bandcamp.com/album/foo',                        'bandcamp');
check('https://soundcloud.com/artist/track',                          'soundcloud');
check('https://librivox.org/foo/',                                    'librivox');
check('https://www.discogs.com/artist/123',                           'discogs');
check('https://musicbrainz.org/work/abc-123',                         'musicbrainz-work');
check('https://musicbrainz.org/recording/abc-123',                    'musicbrainz-recording');
check('https://musicbrainz.org/artist/abc-123',                       'musicbrainz-artist');
check('https://www.goodreads.com/author/show/123.john-newton',        'goodreads-author');
check('https://www.linkedin.com/in/foo',                              'linkedin');
check('https://twitter.com/foo',                                      'twitter-x');
check('https://x.com/foo',                                            'twitter-x');
check('https://www.instagram.com/foo',                                'instagram');
check('https://www.facebook.com/foo',                                 'facebook');
check('https://m.facebook.com/foo',                                   'facebook');

/* Edge cases. */
check('',                                                             null);
check(null,                                                           null);
check(undefined,                                                      null);
check(123,                                                            null);
check('not a url',                                                    null);
check('   https://en.wikipedia.org/wiki/Foo  ',                       'wikipedia'); /* trimmed */
check('https://example.com/random',                                   null);
check('https://notyoutube.com/watch?v=abc',                           null);         /* boundary check */

/* Path-discriminated rules: musicbrainz.org root URL has no slug. */
check('https://musicbrainz.org/',                                     null);

/* ----- DB-driven rules (#845) -----
 * When window._iHymnsLinkTypes is populated, detectFromUrl reads
 * patterns from there instead of the hard-coded RULES. Cover:
 *   - a custom rule wins over the bundled fallback
 *   - empty patterns array → fallback still works
 *   - new providers added DB-side need no code change
 */
sandbox.window._iHymnsLinkTypes = [
    { id: 99, slug: 'example-news', patterns: [
        { host: 'example.com', pathPrefix: null, matchSubdomains: true, priority: 50 },
    ]},
    { id: 100, slug: 'wikipedia', patterns: [
        { host: 'wikipedia.org', pathPrefix: null, matchSubdomains: true, priority: 40 },
    ]},
];
detect._resetDbRulesCache();
check('https://example.com/article/123',                              'example-news');
check('https://news.example.com/foo',                                 'example-news'); /* sub-domain match */
check('https://en.wikipedia.org/wiki/Foo',                            'wikipedia');    /* still works */
check('https://music.youtube.com/playlist?list=xyz',                  null);           /* not in DB rules */

/* Empty patterns → falls back to bundled RULES. */
sandbox.window._iHymnsLinkTypes = [
    { id: 1, slug: 'wikipedia', patterns: [] },
];
detect._resetDbRulesCache();
check('https://en.wikipedia.org/wiki/Foo',                            'wikipedia');    /* fallback */
check('https://www.youtube.com/watch?v=abc',                          'youtube');      /* fallback */

/* Output. */
console.log(`\n#841 + #845 external-link-detect: ${passed} passed, ${failed} failed.`);
if (failed > 0) process.exit(1);
