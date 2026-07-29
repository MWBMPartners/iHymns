<?php
/**
 * iHymns — FreeShow Importer Smoke Test (#1538)
 *
 * Exercises _bulkImport_parseFreeShow() and its slide-flattening helper
 * _bulkImport_freeShowSlideLines() against the four archived FreeShow
 * fixtures. Pure-parser surface only — no DB, no HTTP.
 *
 *   php tests/php/test-freeshow-parser.php
 *
 * Exit status 0 = all pass, 1 = at least one assertion failed.
 *
 * FIXTURE PROVENANCE — the four .show documents under fixtures/freeshow/ are
 * the originals written for the #884 importer. They were removed from the tree
 * and preserved on the archive tag `archive/freeshow-import-fixtures`
 * (dcca55b4), and are restored here verbatim (`git show dcca55b4:…`) rather
 * than hand-written, so they remain a faithful sample of what FreeShow
 * actually emits. That matters: a fixture invented to match the parser can
 * only ever confirm the parser agrees with itself.
 *
 * A FreeShow ".show" file is JSON — either a bare show object or the
 * [ "<id>", { show } ] pair FreeShow writes on export. Both forms are covered.
 * Format notes: https://freeshow.app/  (see the #884 parser doc-block in
 * includes/song_importers.php for the field-by-field breakdown).
 */

declare(strict_types=1);

/* ELI5: load the file the importer actually lives in, then call it.
 *
 * Detail — song_importers.php is a pure-function include. Its only top-level
 * side effect is a direct-access guard keyed on $_SERVER['SCRIPT_FILENAME']
 * (under the CLI that is THIS test file, not the include, so the guard passes),
 * and no parser touches the DB at include time. Requiring the real module is
 * deliberate: the two older sibling tests (#882 / #883) instead scraped
 * manage/editor/api.php for a comment banner, and went permanently red the
 * moment #1200 Phase 4b moved the parsers into this shared include (#1575).
 * Testing the public entry point survives that class of move.
 * @see https://www.php.net/manual/en/reserved.variables.server.php */
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';

/* -------------------------------------------------------------------- */
/* Test runner — one-line PASS/FAIL per assertion.                       */
/* -------------------------------------------------------------------- */
$passed = 0;
$failed = 0;
function assertEq($actual, $expected, string $label): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}
function assertTrue($actual, string $label): void { assertEq((bool)$actual, true, $label); }

$fixtureDir = __DIR__ . '/fixtures/freeshow';

/* -------------------------------------------------------------------- */
/* Fixture 1: simple-song.show — the happy path.                         */
/*   meta title/author/copyright/CCLI, an explicit "default" layout that */
/*   fixes slide order, and four slides.                                 */
/* -------------------------------------------------------------------- */
echo "fixture: simple-song.show\n";
$body = file_get_contents("$fixtureDir/simple-song.show");
[$song, $err] = _bulkImport_parseFreeShow($body);

assertEq($err, null,                                          'no parse error');
assertTrue(is_array($song),                                   'parsed song returned');
assertEq(($song['title'] ?? null),     'Be Thou My Vision',             'title from meta.title');
assertEq(($song['ccli'] ?? null),      '30639',                         'CCLI from meta.CCLI');
assertEq(($song['copyright'] ?? null), 'Public Domain',                 'copyright from meta.copyright');

/* meta.author is "Mary E. Byrne & Eleanor H. Hull" — the FreeShow parser
   splits credits on the same [/&,;] delimiter set as the OpenSong and
   VideoPsalm parsers, so one author string becomes two writer rows. */
assertEq(($song['writers'] ?? null),   ['Mary E. Byrne', 'Eleanor H. Hull'],
                                                              'meta.author split on &');

/* The importer is a lyrics catalogue: it deliberately does NOT carry a
   songbook across, because one .show is one song with no book context.
   The ZIP walker supplies the songbook from the parent folder name. */
assertEq(($song['songbookName'] ?? null), '',                           'no songbook name (supplied by the ZIP walker)');

assertEq(count($song['components'] ?? []), 4,                       'four slides → four components');

/* Component order must follow layouts.default.slides, not the slides-map
   key order — the layout is what the operator actually projects. */
assertEq(($song['components'][0]['type'] ?? null),   'verse',           'component 0 type (group "Verse 1")');
assertEq(($song['components'][0]['number'] ?? null), 1,                 'component 0 number parsed off the group label');
assertEq(($song['components'][1]['type'] ?? null),   'chorus',          'component 1 type (group "Chorus")');
assertEq(($song['components'][3]['type'] ?? null),   'verse',           'component 3 type (group "Verse 2")');
assertEq(($song['components'][3]['number'] ?? null), 2,                 'component 3 number');

/* Lyric flattening: items[].lines[].text[].value runs concatenate into one
   line per `lines[]` entry. */
assertEq(($song['components'][0]['lines'] ?? null),
         ['Be Thou my vision, O Lord of my heart',
          'Naught be all else to me, save that Thou art'],   'verse 1 lines');
assertEq(($song['components'][1]['lines'] ?? null),
         ['High King of heaven, my victory won',
          "May I reach heaven's joys, O bright heaven's Sun"], 'chorus lines');

/* CHARACTERISATION — not an endorsement. Slide "c2" carries the group label
   "Chorus (cont.)" and globalGroup "chorus". _bulkImport_pro6GroupType()
   inspects ONLY the free-text `group` label; "chorus (cont.)" matches no
   alias, so _bulkImport_componentTypeFor() falls through to its 'refrain'
   default and the continuation slide lands as a refrain rather than a second
   chorus. The lyrics are preserved either way, so this is a typing-fidelity
   gap, not data loss — FreeShow's own machine-readable `globalGroup` field
   would resolve it. Pinned here so the behaviour is visible and any change to
   it is a deliberate, reviewed one rather than a silent drift. See the notes
   filed against #1538. */
assertEq(($song['components'][2]['type'] ?? null),  'refrain',
                                 'component 2: "Chorus (cont.)" → refrain (globalGroup ignored — known gap)');
assertEq(($song['components'][2]['lines'] ?? null), ['Heart of my own heart, whatever befall'],
                                                              'component 2 lyrics survive regardless of typing');

/* -------------------------------------------------------------------- */
/* Fixture 2: multi-layout.show — three layouts, NO settings.activeLayout */
/* -------------------------------------------------------------------- */
echo "fixture: multi-layout.show (no settings.activeLayout)\n";
$body = file_get_contents("$fixtureDir/multi-layout.show");
[$song, $err] = _bulkImport_parseFreeShow($body);

assertEq($err, null,                                          'no parse error');
assertEq(($song['title'] ?? null), 'Amazing Grace',                     'title');
assertEq(($song['ccli'] ?? null),  '',                                  'absent meta.CCLI → empty string, not null');

/* The show declares "primary" (v1, v2), "alternate" (v2, v1) and "intro"
   (v1) but no settings.activeLayout pointer. The parser takes the FIRST
   layout via reset() — PHP preserves the insertion order json_decode() saw,
   so "primary" wins and the verses stay in book order. This assertion is the
   guard on that fallback: pick "alternate" instead and the order reverses. */
assertEq(count($song['components'] ?? []), 2,                       'two slides in the fallback layout');
assertEq(($song['components'][0]['number'] ?? null), 1,                 'first layout ("primary") order — verse 1 first');
assertEq(($song['components'][1]['number'] ?? null), 2,                 'first layout ("primary") order — verse 2 second');
assertEq(($song['components'][0]['lines'][0] ?? null), 'Amazing grace, how sweet the sound',
                                                              'verse 1 first line');

/* -------------------------------------------------------------------- */
/* settings.activeLayout — the operator's CHOSEN layout wins.            */
/* -------------------------------------------------------------------- */
/* ELI5: FreeShow lets you save several running orders; we must import the
 * one the operator actually selected.
 *
 * Detail — none of the four archived fixtures can prove this. simple-song.show
 * has one layout whose order happens to equal the slides-map key order, and
 * multi-layout.show has no `settings.activeLayout` pointer at all, so both pass
 * even if the activeLayout branch is deleted. This inline show is built
 * specifically to separate the three candidate orderings:
 *
 *   slides-map key order  : a, b, c
 *   FIRST layout          : a, b, c   ("first")
 *   activeLayout          : c, a      ("chosen")  ← the only correct answer
 *
 * It also pins that a layout SUBSETS the slides: "b" is not referenced by the
 * chosen layout and must not be imported, because a slide the operator left
 * out of the running order is not part of the song they projected. */
echo "settings.activeLayout precedence:\n";
$mkSlide = static fn(string $group, string $text): array => [
    'group' => $group,
    'items' => [['type' => 'text', 'lines' => [['text' => [['value' => $text]]]]]],
];
[$song, $err] = _bulkImport_parseFreeShow(json_encode([
    'name'     => 'Order Probe',
    'settings' => ['activeLayout' => 'chosen'],
    'slides'   => [
        'a' => $mkSlide('Verse 1', 'AAA'),
        'b' => $mkSlide('Verse 2', 'BBB'),
        'c' => $mkSlide('Verse 3', 'CCC'),
    ],
    'layouts'  => [
        'first'  => ['name' => 'First',  'slides' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]],
        'chosen' => ['name' => 'Chosen', 'slides' => [['id' => 'c'], ['id' => 'a']]],
    ],
]));
assertEq($err, null,                                          'activeLayout show parses');
assertEq(count($song['components'] ?? []), 2,                 'only the chosen layout\'s slides import (b excluded)');
assertEq(($song['components'][0]['lines'][0] ?? null), 'CCC', 'chosen layout order — slide c first');
assertEq(($song['components'][1]['lines'][0] ?? null), 'AAA', 'chosen layout order — slide a second');

/* -------------------------------------------------------------------- */
/* Fixture 3: with-media-slide.show — a non-text slide mid-layout.       */
/* -------------------------------------------------------------------- */
echo "fixture: with-media-slide.show\n";
$body = file_get_contents("$fixtureDir/with-media-slide.show");
[$song, $err] = _bulkImport_parseFreeShow($body);

assertEq($err, null,                                          'no parse error');
assertEq(($song['title'] ?? null), 'How Great Thou Art',                'title');
assertEq(($song['copyright'] ?? null), '',                              'absent meta.copyright → empty string');

/* The layout is v1 → media1 → c1. Slide "media1" holds only a media item and
   a timer item, so it flattens to zero lines and is SKIPPED — it must not
   become an empty component, and it must not stop the walk before the chorus
   that follows it. Both are real failure modes for a slide-order loop. */
assertEq(count($song['components'] ?? []), 2,                       'media-only slide skipped, not emitted as an empty component');
assertEq(($song['components'][0]['lines'][0] ?? null), 'O Lord my God, when I in awesome wonder',
                                                              'verse before the media slide');
assertEq(($song['components'][1]['type'] ?? null), 'chorus',            'chorus AFTER the media slide still parses');
assertEq(($song['components'][1]['lines'][0] ?? null), 'Then sings my soul, my Saviour God, to Thee',
                                                              'chorus lyrics after the media slide');

/* -------------------------------------------------------------------- */
/* Fixture 4: sermon-notes.show — category "notes", not "song".          */
/* -------------------------------------------------------------------- */
echo "fixture: sermon-notes.show (non-song category)\n";
$body = file_get_contents("$fixtureDir/sermon-notes.show");
[$song, $err] = _bulkImport_parseFreeShow($body);

/* CHARACTERISATION — not an endorsement. FreeShow tags every show with a
   `category` ("song", "notes", "countdown", …) and this fixture is "notes".
   The parser never reads that field, so a sermon-notes show parses as a
   perfectly ordinary song. Because a FreeShow ZIP export contains a
   collection's shows indiscriminately, and the ZIP walker routes every .show
   entry straight into this parser, non-song shows currently import as songs.
   Pinned so the behaviour is visible; filed against #1538 rather than fixed
   here, since changing it is an importer policy decision, not a test fix. */
assertEq($err, null,                                          'parses without error (category is not inspected)');
assertEq(($song['title'] ?? null), 'Sunday Sermon Notes',               'title from meta.title');
assertEq(count($song['components'] ?? []), 1,                       'the notes slide becomes a component — known gap');

/* -------------------------------------------------------------------- */
/* Wrapper form: FreeShow exports [ "<id>", { show } ], not a bare show. */
/* -------------------------------------------------------------------- */
echo "wrapper form: [\"<id>\", {show}]\n";
$bare    = file_get_contents("$fixtureDir/simple-song.show");
$wrapped = json_encode(['fs_abc123', json_decode($bare, true)]);
[$song, $err] = _bulkImport_parseFreeShow($wrapped);
assertEq($err, null,                                          'wrapped [id, show] pair parses');
assertEq(($song['title'] ?? null), 'Be Thou My Vision',                 'wrapped form yields the same title');
assertEq(count($song['components'] ?? []), 4,                       'wrapped form yields the same components');

/* -------------------------------------------------------------------- */
/* Error paths — both must be graceful strings, never an exception.      */
/* -------------------------------------------------------------------- */
echo "error paths:\n";
[$song, $err] = _bulkImport_parseFreeShow('{"name": "truncated"');
assertEq($song, null,                                         'malformed JSON → null song');
assertTrue(is_string($err) && str_starts_with($err, 'invalid JSON'),
                                                              'error message starts with "invalid JSON"');

/* Valid JSON, wrong document: no slides map. The ZIP walker routes purely on
   the .show extension, so a mis-named file reaches the parser and must be
   rejected with a readable per-entry reason rather than a fatal. */
[$song, $err] = _bulkImport_parseFreeShow('{"foo":1}');
assertEq($song, null,                                         'JSON without slides → null song');
assertEq($err,  'not a FreeShow .show document (no slides)',  'error names the missing slides map');

/* A show whose slides exist but carry no text at all cannot become a song —
   there would be nothing to catalogue and no title to fall back to. */
[$song, $err] = _bulkImport_parseFreeShow(
    '{"name":"Empty","slides":{"s1":{"group":"Verse 1","items":[{"type":"media","src":"a.jpg"}]}}}'
);
assertEq($song, null,                                         'slides with no text → null song');
assertEq($err,  'no slide text found',                        'error names the empty-text case');

/* -------------------------------------------------------------------- */
/* Title fallback — a show with no meta.title and no name uses the first */
/* lyric line, matching how FreeShow itself labels an untitled show.     */
/* -------------------------------------------------------------------- */
echo "title fallback:\n";
[$song, $err] = _bulkImport_parseFreeShow(json_encode([
    'slides' => ['s1' => ['group' => 'Verse 1', 'items' => [
        ['type' => 'text', 'lines' => [['text' => [['value' => 'When peace like a river']]]]],
    ]]],
]));
assertEq($err, null,                                          'untitled show still parses');
assertEq(($song['title'] ?? null), 'When peace like a river',           'title falls back to the first lyric line');

/* -------------------------------------------------------------------- */
/* Slide flattening helper — the run-concatenation rules in isolation.   */
/* -------------------------------------------------------------------- */
echo "slide flattening (_bulkImport_freeShowSlideLines):\n";

/* Several text runs inside ONE line[] entry are style spans (bold, colour,
   a highlighted word) and must concatenate into a single lyric line — not
   become three lines. This is the rule most likely to regress if someone
   "simplifies" the nested loops. */
assertEq(
    _bulkImport_freeShowSlideLines(['items' => [['type' => 'text', 'lines' => [
        ['text' => [['value' => 'Hark! '], ['value' => 'the herald '], ['value' => 'angels sing']]],
    ]]]]),
    ['Hark! the herald angels sing'],                         'multiple runs in one line concatenate');

/* Conversely a run VALUE may itself contain newlines (FreeShow allows a soft
   break inside a textbox), and those must split into separate lyric lines. */
assertEq(
    _bulkImport_freeShowSlideLines(['items' => [['type' => 'text', 'lines' => [
        ['text' => [['value' => "line A\nline B"]]],
    ]]]]),
    ['line A', 'line B'],                                     'embedded newline splits into two lines');

/* CRLF from a Windows-authored show normalises the same way. */
assertEq(
    _bulkImport_freeShowSlideLines(['items' => [['type' => 'text', 'lines' => [
        ['text' => [['value' => "line A\r\nline B"]]],
    ]]]]),
    ['line A', 'line B'],                                     'CRLF normalises before splitting');

/* Trailing blank/whitespace-only lines are trimmed so a slide padded out for
   projection spacing doesn't add empty lyric rows. Interior blanks stay. */
assertEq(
    _bulkImport_freeShowSlideLines(['items' => [['type' => 'text', 'lines' => [
        ['text' => [['value' => 'real line']]],
        ['text' => [['value' => '   ']]],
        ['text' => [['value' => '']]],
    ]]]]),
    ['real line'],                                            'trailing blank lines trimmed');

/* Non-text items (media, timer, shapes) contribute nothing. */
assertEq(
    _bulkImport_freeShowSlideLines(['items' => [
        ['type' => 'media', 'src' => 'bg.jpg'],
        ['type' => 'timer', 'duration' => 30],
    ]]),
    [],                                                       'non-text items contribute no lines');

/* A slide with no items at all is legal FreeShow and must not warn or throw. */
assertEq(_bulkImport_freeShowSlideLines([]), [],              'slide with no items[] → empty array');

/* -------------------------------------------------------------------- */
/* Group-label → component-type mapping (shared with the ProPresenter 6  */
/* importer via _bulkImport_pro6GroupType).                              */
/* -------------------------------------------------------------------- */
echo "group-label mapping:\n";
assertEq(_bulkImport_pro6GroupType('Verse 1'),    ['verse', 1],      'Verse 1 → verse #1');
assertEq(_bulkImport_pro6GroupType('Chorus'),     ['chorus', 0],     'Chorus → chorus, no number');
assertEq(_bulkImport_pro6GroupType('Bridge 2'),   ['bridge', 2],     'Bridge 2 → bridge #2');
assertEq(_bulkImport_pro6GroupType('Pre Chorus'), ['pre-chorus', 0], 'Pre Chorus → pre-chorus (alias)');
assertEq(_bulkImport_pro6GroupType('Ending'),     ['outro', 0],      'Ending → outro (alias)');
assertEq(_bulkImport_pro6GroupType('Interlude'),  ['refrain', 0],    'Interlude → refrain (alias)');
assertEq(_bulkImport_pro6GroupType('Squiggle'),   ['refrain', 0],    'unknown label → refrain fallback');

echo "\n";
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
