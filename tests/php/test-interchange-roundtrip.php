<?php

declare(strict_types=1);

/**
 * iHymns — unified interchange-format export→import CLOSURE + fidelity matrix (#1129)
 * =======================================================================================
 *
 * ELI5
 * ----
 * iHymns can export a song as OpenSong, OpenLP/OpenLyrics, ProPresenter 6, Proclaim,
 * VideoPsalm, FreeShow or ChordPro — and import every one of those formats back in. This
 * file builds ONE song, runs it through all seven of OUR OWN exporters
 * (`format-export.js`), feeds each result into the matching REAL PHP importer
 * (`song_importers.php`), and checks that what comes back out is EXACTLY what a careful
 * human, reading both halves' source, would predict — including every place the two
 * halves DON'T agree perfectly, because a real, already-shipped exporter/importer pair
 * sometimes drops or reshapes a field on purpose (or by accident — see "the 5 defects"
 * below). It is a CLOSURE test: it proves OUR two halves agree with EACH OTHER, not that
 * either half is correct against some other app's files.
 *
 * WHAT THIS IS (read carefully — this is not a round-trip-equality test)
 * -----------------------------------------------------------------------
 * This is a unified per-format CLOSURE harness: it proves our exporter and our importer
 * agree in the ways per-field EXPECTED DICTS (losses INCLUDED) say, and derives a
 * fidelity matrix from those same dicts (`--matrix` mode, added in this feature's second
 * commit). It is NOT external-correctness proof for any format — that would require real
 * third-party files from the format's OWN application, which is exactly what
 * `tests/php/test-pp7-*.php` do for ProPresenter 7+ against `tests/fixtures/propresenter/`.
 * The #1968 lesson this harness is built to never repeat: a circular same-schema round
 * trip can ship green TWICE while silently wrong against a real third-party file, because
 * a same-process (or same-author-implicit-assumption) round trip only ever proves the two
 * sides share the same idea of what a field means — never that the idea is RIGHT.
 * `test-pp7-roundtrip.php` already solved this for PP7 the correct way (two INDEPENDENT
 * implementations — browser JS + hand-rolled PHP — that have never read each other's
 * source, agreeing on real bytes); this harness reuses that exact doctrine for the other
 * seven formats, and reuses `test-pp7-roundtrip.php` ITSELF (by annotation, not
 * duplication) for the one it deliberately excludes. See "READ FIRST" below.
 *
 * This file therefore asserts a HAND-TRACED EXPECTED DICT per format, never
 * `exported == reimported`. A blanket-equality harness would FAIL TODAY for entirely
 * legitimate, already-documented reasons — the sharpest example lives right in this
 * codebase: `format-export.js:419`'s `buildOpenLyrics()` always emits a natural-order
 * `<verseOrder>` from component ITERATION order, regardless of what `song.arrangement`
 * says (in fact regardless of whether `song.arrangement` is set at all — no
 * `format-export.js` builder ever reads that field; see `FIXTURE_SONG`'s own
 * `arrangement: [2, 0, 1]` in `tools/interchange-gen-samples.js`, deliberately
 * NON-natural, deliberately never read). Re-importing our own OpenLyrics export therefore
 * always resolves the IDENTITY permutation, which `_bulkImport_parseOpenLyrics()`'s
 * identity-suppression rule (#2062) correctly, deliberately collapses to "no arrangement
 * key in the result at all". An equality harness has no vocabulary for "the input was
 * non-trivial and its absence on the way out is CORRECT" — a hand-traced expected dict
 * does, trivially: the OpenLyrics expected dict below simply has no `'arrangement'` key,
 * with a comment explaining why.
 *
 * READ FIRST (the precedents this is modelled on — study them before touching this file)
 * -----------------------------------------------------------------------------------------
 * - `tests/php/test-pp7-roundtrip.php` — the SANCTIONED closure test this harness's whole
 *   doctrine is copied from: its header (:21-99) states the "our two halves agree,
 *   expected-dict-not-equality" doctrine in full; its expected dict (:256-290) encodes
 *   every deliberate loss with `file:line` evidence; its orchestration (PHP spawns
 *   `node <generator> <tmp>`, feeds the bytes to the PHP parser, diffs via a first-diff
 *   helper) is the exact shape this file's own orchestration below copies (now via the
 *   shared `tests/php/lib/field_diff.php`, extracted from that file's local helper).
 *   PP7 STAYS THAT FILE'S JOB — this harness does NOT duplicate it. PP7's row in this
 *   harness's fidelity matrix (Slice 2, `--matrix` mode) is populated by ANNOTATION
 *   ("closure-tested separately — see test-pp7-roundtrip.php"), never by a second
 *   binary-format closure test living here.
 * - `tools/pp7-gen-roundtrip-sample.js` — the Node-side sample-generator shape this
 *   harness's own `tools/interchange-gen-samples.js` is modelled on, MINUS the
 *   vm-sandbox + protobufjs machinery PP7's binary encode needs (the seven
 *   `format-export.js` formats are plain string/JSON builders with no DOM dependency in
 *   their `build()` functions — see that generator's own header for the confirmation).
 * - `tests/test-chordpro-export.js:33-42` — proves `format-export.js` is `require()`-able
 *   under plain Node; `tools/interchange-gen-samples.js`'s header explains the ONE
 *   surprising wrinkle this repo's `"type":"module"` package.json adds (read for a
 *   NEW format before assuming `require()`'s OWN return value is trustworthy — it isn't;
 *   `globalThis.iHymnsFormatExport`, set as a side effect, is).
 * - `appWeb/public_html/manage/editor/format-export.js` — the `iHymnsFormatExport` api
 *   registry (~:846-883): `openSong / openLyrics / proPresenter6 / proclaim / videoPsalm
 *   / freeShow / chordPro` — the SEVEN formats in scope here, enumerated at generation
 *   time via `Object.keys()` (rule #34), never typed out by hand.
 * - `appWeb/public_html/includes/song_importers.php` — the paired PURE parsers under
 *   test: `_bulkImport_parseOpenSong()` / `_bulkImport_parseOpenLyrics()` /
 *   `_bulkImport_parsePro6()` / `_bulkImport_parseProclaimText()` /
 *   `_bulkImport_parseVideoPsalmSongbook()` / `_bulkImport_parseFreeShow()` /
 *   `_bulkImport_parseChordPro()` — string-in / dict-out, no DB, exactly like every
 *   other importer this codebase already unit-tests this way.
 * - `tests/php/test-import-format-coverage.php` + `tests/php/lib/dispatch_parser.php` —
 *   the importer-dispatch enumeration this file's own completeness cross-check (below)
 *   reuses: `import2.php`'s `$formats` dropdown is the canonical list of every format
 *   iHymns can IMPORT (a strict superset of the seven this harness closure-tests, since
 *   several — `ihymns`, `pro7`, `probundle`, `proplaylist`, `pptx`, `easyworship` — have
 *   no `format-export.js` EXPORTER at all, or (pro7) are deliberately tested elsewhere).
 *
 * THE FIXTURE — `FIXTURE_SONG` in `tools/interchange-gen-samples.js` is the single
 * source of truth for every expected dict below; that file's own doc-block traces WHY
 * each field was chosen. Every expected value below was hand-derived from reading BOTH
 * halves' source, THEN CONFIRMED by actually running the generator + each parser once at
 * authoring time (`tools/interchange-gen-samples.js` + a one-off dump script — not typed
 * from what "should" happen in the abstract; this repo's own rule #34/#35 discipline, and
 * the same "confirmed, not derived" standard `test-pp7-roundtrip.php`'s header sets).
 *
 * THE 7 FORMATS' OUTPUT SHAPES DIFFER (by DESIGN, pre-existing, not introduced here):
 *   - OpenSong / ChordPro / (each song inside) VideoPsalm return the FULL song-object
 *     shape `_bulkImport_saveSong()` consumes (id/title/number/songbook/songbookName/
 *     language/ccli/iswc/tuneName/copyright/verified/…/writers/composers/…/components).
 *   - OpenLyrics / ProPresenter 6 / Proclaim / FreeShow return the smaller "neutral
 *     parsed structure" `_bulkImport_assembleSong()` later wraps (title/songbookName/
 *     entry/language/ccli/copyright/writers/components, OpenLyrics additionally
 *     altTitles/warnings/arrangement?).
 * Each format's `expected` array below is written in THAT format's OWN parser's actual
 * return shape — never coerced to a common shape — matching `test-pp7-parse.php` /
 * `test-pp7-roundtrip.php`'s own precedent of trusting each parser's real contract.
 *
 * THE 5 DEFECTS this harness's expected dicts freeze (confirm + report, per the task
 * brief — NOT fixed here; the owner files the tracking issues from this evidence):
 *   (1) VIDEOPSALM — `buildVideoPsalm()` (format-export.js:224-225) writes copyright to
 *       `Memo1` and ccli to `Memo2` (prefixed `'CCLI '+ccli`), and NEVER emits an
 *       `Author` key at all — but `_bulkImport_parseVideoPsalmSongbook()`
 *       (song_importers.php:2593/2596/2577) reads `Copyright`/`CCLI`/`Author`. Our own
 *       copyright + ccli + writers are silently dropped on a same-app round trip. See
 *       the `videoPsalm` expected dict's `'ccli'=>''`, `'copyright'=>''`, `'writers'=>[]`.
 *   (2) CHORDPRO — `buildChordPro()` (format-export.js:792,796) folds writers+composers
 *       into ONE `{artist: "…"}` directive (never emits `{author:}`); the importer's
 *       `case 'artist':` (song_importers.php:2866-2867) pushes that WHOLE string as a
 *       SINGLE composer entry (no comma-splitting, unlike every other format's author
 *       parsing) — our writers come back relabelled as one composer string. See the
 *       `chordPro` expected dict's `'writers'=>[]`,
 *       `'composers'=>['Ada Writer, Bea Writer, Cy Composer']`.
 *   (3) DEAD EXPORTER INPUTS — `format-export.js` reads `song.alternateTitle` (:795),
 *       `song.key` (:797) and `song.capo` (:798) into ChordPro's `{subtitle:}`/`{key:}`/
 *       `{capo:}` directives, and `propresenter-export.js` reads `song.notes` (:1244) —
 *       but `SongData::getSongById()`'s real row shape (SongData.php ~4474-4600) never
 *       populates ANY of these: it has `alternativeTitles` (PLURAL array, #832), not
 *       `alternateTitle` (singular string); it has no `key`, `capo`, or `notes` field at
 *       all. These four exporter inputs are dead code against the app's own live data —
 *       confirmed by reading `_fetchSongRow()`'s full SELECT + post-processing, not
 *       inferred. (`song.notes`/PP7 itself is out of THIS harness's scope — see the PP7
 *       exclusion above — so this defect is reported from source inspection, not a
 *       harness cell; the ChordPro-side three fields DO appear in the `chordPro`
 *       expected dict below, each commented at its cell.)
 *   (4) OPENSONG TUNE — `buildOpenSong()` emits `<tune>` (format-export.js:147) from
 *       `song.tuneName`, but `_bulkImport_parseOpenSong()` NEVER reads `<tune>` anywhere
 *       — its returned `'tuneName'` is a hardcoded `''` literal
 *       (song_importers.php:2269). See the `openSong` expected dict's `'tuneName'=>''`.
 *   (5) V2 EDITOR EXPORT MENU — `manage/editor/v2/export.js`'s `ITEMS` array (:70-79)
 *       lists ProPresenter 7/6, OpenSong, OpenLP, VideoPsalm, FreeShow, Proclaim and
 *       EasyWorship — but NO ChordPro entry, even though the SAME file's own comment
 *       (:16-24) references "the v2 editor's own 'Export ▸ ChordPro'" as if it exists,
 *       and the shared PUBLIC export menu (`includes/partials/export-menu.php:105`) DOES
 *       list `'chordPro' => 'ChordPro'`. Checked against git history
 *       (`git log -p --follow -- appWeb/public_html/manage/editor/v2/export.js`): the
 *       `ITEMS` array has been touched in exactly ONE commit ever — ChordPro was never
 *       added, not removed; this is an omission, not a regression from a prior working
 *       state. Not a harness cell (a JS menu array, not an export/import byte format) —
 *       reported from source + git-history inspection.
 * None of these are fixed by this commit — CONFIRM + FREEZE + REPORT is this harness's
 * job; the owner files the tracking issues from the evidence above.
 *
 * MUTATION-PROVEN (rule #34), each performed once by hand against the real working tree,
 * this file re-run and confirmed RED, then reverted (`git diff --stat` empty before
 * moving on) — see the commit body for the full transcript:
 *   m1 — format-export.js's `OPENSONG_LETTER['chorus']` 'C' → 'X' (an export-side
 *        lockstep drift, rule #35) → RED on `openSong`: component[1].type came back
 *        'refrain' (the importer's unmapped-letter fallback), not 'chorus'.
 *   m2 — format-export.js's `buildVideoPsalm()` `s.Number = …` assignment commented out
 *        → RED on `videoPsalm`: with no `Number` in the JSON, the importer falls back to
 *        the 1-based array index (`$number = (int)$idx + 1` = 1), so `number`/`id` came
 *        back `1` / `'VIDEOPSALM-0001'` instead of `7` / `'VIDEOPSALM-0007'`.
 *   m3 — song_importers.php's ChordPro `case 'artist': case 'composer': case 'music':`
 *        arm changed to push into `$writers[]` instead of `$composers[]` → RED on
 *        `chordPro`: `writers` came back with the merged string, `composers` came back
 *        empty — the exact inverse of defect (2)'s expected dict, proving that dict is
 *        pinned to the REAL mechanism, not a coincidence.
 *   m4 — song_importers.php's shared `_bulkImport_pro6GroupType()` alias map gained a
 *        `'chorus' => 'refrain'` entry → RED on BOTH `proPresenter6` AND `freeShow`
 *        simultaneously: component[1].type came back 'refrain' on each — proving the
 *        harness catches a shared-helper regression across every format that reuses it,
 *        not just one.
 *   m5 — song_importers.php's OpenLyrics identity-suppression check
 *        `if ($resolved !== $identity)` flipped to `if ($resolved === $identity)`
 *        (inverting the collapse condition, mirroring `test-pp7-roundtrip.php`'s own m3)
 *        → RED on `openLyrics`: the result gained a spurious `'arrangement' => [0,1,2]`
 *        key where the expected dict has none — this is the harness's OWN central
 *        "sparse absent, not equality" doctrine catching itself if it were ever silently
 *        broken.
 * Every mutation was reverted immediately after confirming red.
 *
 * Usage:
 *   php tests/php/test-interchange-roundtrip.php            # closure assertions (this commit)
 *   php tests/php/test-interchange-roundtrip.php --matrix    # + regenerate/verify the fidelity
 *                                                             # matrix artifacts (added in this
 *                                                             # feature's second commit)
 *
 * Requires a `node` binary on PATH (only to regenerate the fixture files — the assertions
 * themselves are pure PHP), exactly like `test-pp7-roundtrip.php`.
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see tools/interchange-gen-samples.js                    the REAL exporters run against FIXTURE_SONG
 * @see appWeb/public_html/manage/editor/format-export.js   the seven build() functions under test
 * @see appWeb/public_html/includes/song_importers.php       the seven importers under test
 * @see tests/php/test-pp7-roundtrip.php                     the sanctioned closure-test precedent (PP7, excluded here)
 * @see tests/php/lib/field_diff.php                         the shared first-diff helper (extracted from test-pp7-roundtrip.php)
 * @see tests/php/lib/dispatch_parser.php                     reused for the importer-format completeness cross-check
 * @see tests/php/test-import-format-coverage.php             the sibling guard this harness's completeness check cross-references
 * @see .claude/CLAUDE.md rules #34, #35, #45, #46             the guard/mechanism/label/versioning discipline this file follows
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';
require_once __DIR__ . '/lib/field_diff.php';
require_once __DIR__ . '/lib/dispatch_parser.php';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  ❌ {$label}\n";
    }
}

echo "\n#1129 — unified interchange export -> import CLOSURE (our exporters -> our importers)\n\n";

/* ================================================================================================
 * (1) Generate the fixture files via the REAL exporters (a separate node process — see the file
 *     header for why this must not be a same-process self-decode, and
 *     tools/interchange-gen-samples.js's header for exactly what it builds).
 * ================================================================================================ */

$repoRoot        = dirname(__DIR__, 2);
$generatorScript = $repoRoot . '/tools/interchange-gen-samples.js';

ok('generator script exists (tools/interchange-gen-samples.js)', is_file($generatorScript));

$outDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ihymns-interchange-' . bin2hex(random_bytes(6));

$nodeVersionProbe = @shell_exec('node --version 2>&1');
ok('a `node` binary is on PATH (required to regenerate the closure fixtures — see file header)',
    is_string($nodeVersionProbe) && (bool)preg_match('/^v\d+\.\d+\.\d+/', trim($nodeVersionProbe)));

$manifest = null;

if ($failed === 0) {
    $cmd = escapeshellarg('node') . ' ' . escapeshellarg($generatorScript) . ' ' . escapeshellarg($outDir) . ' 2>&1';
    $cmdOutputLines = [];
    $exitCode = 1;
    exec($cmd, $cmdOutputLines, $exitCode);

    ok('generator exits 0', $exitCode === 0);
    if ($exitCode !== 0) {
        echo '    output: ' . implode(' | ', $cmdOutputLines) . "\n";
    }

    if ($exitCode === 0) {
        /* The generator's LAST line of stdout is the JSON manifest (earlier lines, if any,
           would be diagnostics — there are none in the happy path, but this is robust to a
           future one being added). */
        $lastLine = '';
        foreach ($cmdOutputLines as $line) {
            if (trim($line) !== '') { $lastLine = $line; }
        }
        $decoded = json_decode($lastLine, true);
        ok('generator printed a valid JSON manifest', is_array($decoded) && !empty($decoded));
        if (is_array($decoded) && !empty($decoded)) {
            $manifest = $decoded;
        }
    }
}

/* ================================================================================================
 * (2) COMPLETENESS CROSS-CHECK, exporter side — every key iHymnsFormatExport actually exposes
 *     (i.e. every key the generator's manifest names, which IS Object.keys(iHymnsFormatExport)
 *     minus '_internal' — see tools/interchange-gen-samples.js) must have an expected dict below,
 *     OR be named in $EXPORT_EXEMPTIONS. Bidirectional: a stale expected-dict entry for a format
 *     that no longer exists is caught too. Rule #34 — "either list growing without a row → red".
 * ================================================================================================ */

/**
 * Reserved for a future export format that is structurally exempt from THIS harness's
 * closure-testing shape (e.g. a binary/path-based format with its own dedicated closure
 * test, mirroring PP7's exemption on the IMPORT side below) — empty today because all
 * seven current `iHymnsFormatExport` keys have a real expected dict. A new exporter key
 * with neither an expected dict NOR an entry here fails loudly (see the loop below),
 * which is the whole point: this map existing-but-empty is not a workaround, it is the
 * documented ESCAPE HATCH so adding one never requires silently loosening the check.
 *
 * @var array<string,string> formatKey => reason
 */
$EXPORT_EXEMPTIONS = [];

/* ================================================================================================
 * (3) Per-format: real bytes -> real parser -> hand-traced expected dict, in THAT PARSER'S OWN
 *     return shape (see the file header's "output shapes differ" note). `expectedImportFormat`
 *     is this format's identifier in import2.php's $formats dropdown / api2.php's import_file
 *     dispatch (test-import-format-coverage.php) — consumed by the importer-side completeness
 *     cross-check in section (4) below.
 * ================================================================================================ */

$FORMATS = [

    /* -------------------------------------------------------------------------------------
     * OpenSong (.xml) — full song-object shape (mirrors _bulkImport_parseTxt()'s contract).
     * ------------------------------------------------------------------------------------- */
    'openSong' => [
        'importFormat' => 'opensong',
        'parse' => static function (string $body): array {
            return _bulkImport_parseOpenSong($body, 'IX', 'Interchange Fixtures', 0, static fn (): int => 1);
        },
        'expected' => [
            'id'                 => 'IX-0007',
            'title'              => 'Interchange Fixture Song',
            'number'             => 7,          // from <hymn_number> (song.number), not the $numberHint arg
            'songbook'           => 'IX',
            'songbookName'       => 'Interchange Fixtures',
            'language'           => 'en',        // hardcoded by this parser — OpenSong carries no language field
            'ccli'               => '1234567',
            'iswc'               => '',          // hardcoded — OpenSong has no <iswc>
            /* DEFECT (4) — buildOpenSong() emits <tune>FIXTURE TUNE</tune> (format-export.js:147)
               from song.tuneName, but _bulkImport_parseOpenSong() never reads <tune> anywhere —
               its 'tuneName' key is a hardcoded '' literal (song_importers.php:2269). Confirmed by
               running the real pair: the exported .xml DOES carry <tune>FIXTURE TUNE</tune>, yet
               this comes back empty. */
            'tuneName'           => '',
            'copyright'          => '© 1987 Fixture Music',   // lossless — one opaque string in, same string out
            'verified'           => 0,
            'lyricsPublicDomain' => 0,
            'musicPublicDomain'  => 0,
            'hasAudio'           => 0,
            'hasSheetMusic'      => 0,
            /* buildOpenSong() joins writers+composers into ONE <author> element
               ('Ada Writer, Bea Writer, Cy Composer', format-export.js:121-123,141); the importer
               splits on /,&;\// (song_importers.php:2252) — the comma delimiter re-splits it into
               THREE names, so 'Cy Composer' (a real composer, not a writer) comes back
               indistinguishable from a writer. A real, documented role-collapse — not one of the
               "5 defects" list, but visible here and worth the same file:line evidence. */
            'writers'            => ['Ada Writer', 'Bea Writer', 'Cy Composer'],
            'composers'          => [],   // OpenSong's importer never populates this — always empty
            'arrangers'          => [],
            'adaptors'           => [],
            'translators'        => [],
            'components'         => [
                ['type' => 'verse',  'number' => 1, 'lines' => ['Line one of verse one', 'Line two of verse one']],
                ['type' => 'chorus', 'number' => 0, 'lines' => ['Chorus line one', 'Chorus line two']],
                ['type' => 'verse',  'number' => 2, 'lines' => ['Line one of verse two', 'Line two of verse two']],
            ],
            /* No 'chords'/'notes'/'language'/'label' anywhere: buildOpenSong() never reads
               comp.chords / comp.notes / comp.language / comp.label (confirmed — grep every
               `comp.` identifier format-export.js dereferences), and OpenSong's own <lyrics> text
               format has no channel for any of them regardless. Universal, structural drop. */
        ],
    ],

    /* -------------------------------------------------------------------------------------
     * OpenLP / OpenLyrics (.xml) — the smaller "neutral parsed structure" shape.
     * ------------------------------------------------------------------------------------- */
    'openLyrics' => [
        'importFormat' => 'openlp',
        'parse' => static function (string $body): array {
            return _bulkImport_parseOpenLyrics($body);
        },
        'expected' => [
            'title'        => 'Interchange Fixture Song',
            'songbookName' => 'Interchange Fixtures',
            'entry'        => 7,
            'language'     => '',   // buildOpenLyrics() never emits a lang attribute anywhere — see below
            'ccli'         => '1234567',
            'copyright'    => '© 1987 Fixture Music',   // lossless
            /* Same role-collapse as OpenSong, by a DIFFERENT mechanism: buildOpenLyrics() emits
               writers+composers as separate <author> ELEMENTS (format-export.js:403,408-411, no
               comma-join at all) — the importer just reads every <author> as a writer
               (song_importers.php:3251-3257). Structurally different code path, same observable
               loss (composer role indistinguishable from writer). */
            'writers'      => ['Ada Writer', 'Bea Writer', 'Cy Composer'],
            'altTitles'    => [],   // buildOpenLyrics() never emits a second <title> — alternateTitle is dropped, not even attempted
            'warnings'     => [],
            'components'   => [
                ['type' => 'verse',  'number' => 1, 'lines' => ['Line one of verse one', 'Line two of verse one']],
                ['type' => 'chorus', 'number' => 0, 'lines' => ['Chorus line one', 'Chorus line two']],
                ['type' => 'verse',  'number' => 2, 'lines' => ['Line one of verse two', 'Line two of verse two']],
            ],
            /* NO 'arrangement' key — THE central example this file's header explains at length.
               FIXTURE_SONG.arrangement is [2,0,1] (non-natural) but buildOpenLyrics() never reads
               song.arrangement at all; it always emits <verseOrder> in component ITERATION order
               ('v1 c v2' — natural). _bulkImport_parseOpenLyrics()'s identity-suppression rule
               (#2062) resolves that back to [0,1,2] and correctly OMITS the key rather than
               storing a no-op arrangement. Confirmed via mutation m5 below: flipping the
               suppression condition makes this key wrongly appear.
               Also NO 'chords'/'notes'/'language' on any component: buildOpenLyrics() never emits
               <chord>/<comment>/a verse lang attribute (confirmed — it only ever writes plain
               <lines>…<br/>…</lines> text), even though _bulkImport_openLyricsParseLines() CAN
               read all three from a real third-party OpenLyrics file — our own exporter simply
               never produces them, so nothing is there to read back. */
        ],
    ],

    /* -------------------------------------------------------------------------------------
     * ProPresenter 6 (.pro6) — neutral parsed structure (no composers field at all).
     * ------------------------------------------------------------------------------------- */
    'proPresenter6' => [
        'importFormat' => 'pro6',
        'parse' => static function (string $body): array {
            return _bulkImport_parsePro6($body);
        },
        'expected' => [
            'title'        => 'Interchange Fixture Song',
            'songbookName' => '',   // .pro6 carries no songbook concept — always empty, by format design
            'entry'        => 0,    // ditto — no per-song entry-number concept
            'language'     => '',   // .pro6 carries no language attribute
            'ccli'         => '1234567',
            'copyright'    => '© 1987 Fixture Music',   // lossless — CCLIPublisher carries the whole string verbatim, no year split
            /* buildPro6() joins writers+composers with ' / ' into CCLIAuthor (format-export.js:575,579);
               the importer splits on /\/|&|,|;/ (song_importers.php:2283) — the slash delimiter
               re-splits it into THREE names. Same role-collapse family as OpenSong/OpenLyrics
               above, third distinct mechanism (string-join + slash-split this time). Pro6's
               return shape has no 'composers' key at all — every credit that survives comes back
               as a 'writer', full stop. */
            'writers'      => ['Ada Writer', 'Bea Writer', 'Cy Composer'],
            'components'   => [
                ['type' => 'verse',  'number' => 1, 'lines' => ['Line one of verse one', 'Line two of verse one']],
                ['type' => 'chorus', 'number' => 0, 'lines' => ['Chorus line one', 'Chorus line two']],
                ['type' => 'verse',  'number' => 2, 'lines' => ['Line one of verse two', 'Line two of verse two']],
            ],
            /* No chords/notes/language anywhere: buildPro6() has no chord/note/language channel
               at all (RTF slide text only) — a structural format limitation, not a bug. */
        ],
    ],

    /* -------------------------------------------------------------------------------------
     * Proclaim (.txt) — neutral parsed structure. Proclaim carries NO metadata channel at
     * all (lyrics text only), so copyright/ccli/writers/songbook/language are ALWAYS empty
     * regardless of input — a structural format limitation, not a loss specific to this
     * fixture.
     * ------------------------------------------------------------------------------------- */
    'proclaim' => [
        'importFormat' => 'proclaim',
        'parse' => static function (string $body): array {
            return _bulkImport_parseProclaimText($body, 'proclaim.txt');
        },
        'expected' => [
            'title'        => 'Interchange Fixture Song',
            'songbookName' => '',
            'entry'        => 0,
            'language'     => '',
            'ccli'         => '',
            'copyright'    => '',
            'writers'      => [],
            'components'   => [
                ['type' => 'verse',  'number' => 1, 'lines' => ['Line one of verse one', 'Line two of verse one']],
                ['type' => 'chorus', 'number' => 0, 'lines' => ['Chorus line one', 'Chorus line two']],
                ['type' => 'verse',  'number' => 2, 'lines' => ['Line one of verse two', 'Line two of verse two']],
            ],
        ],
    ],

    /* -------------------------------------------------------------------------------------
     * VideoPsalm (.json) — full song-object shape, nested inside the [meta,songs[],err]
     * songbook-parser contract. A single song exports as a 1-song "book" whose Text is the
     * SONG'S OWN TITLE (exportSongVideoPsalm's own shape — reproduced by the generator's
     * SERIALIZERS.videoPsalm), so songbookMeta.name and the song's songbookName both come
     * back as the song title, not FIXTURE_SONG.songbookName — a real quirk of VideoPsalm's
     * "native unit is a whole songbook" design, distinct from defect (1) below.
     * ------------------------------------------------------------------------------------- */
    'videoPsalm' => [
        'importFormat' => 'videopsalm',
        'parse' => static function (string $body): array {
            [$meta, $songs, $err] = _bulkImport_parseVideoPsalmSongbook($body, 'videoPsalm.json');
            if ($err !== null) {
                return [null, $err];
            }
            return [['meta' => $meta, 'song' => $songs[0] ?? null], null];
        },
        'expected' => [
            'meta' => [
                /* Derived from the generator's chosen filename hint 'videoPsalm.json' — see
                   _bulkImport_videopsalmAbbrevFromHint()'s bare-alphanumeric-stem rule
                   (song_importers.php:2438-2462): the '.json' extension is stripped, the
                   remaining 'videoPsalm' matches ^[A-Za-z0-9_-]+$, uppercased. */
                'abbrev'      => 'VIDEOPSALM',
                'name'        => 'Interchange Fixture Song',   // = the song's own title, see class doc-block above
                'language'    => null,   // VideoPsalm does not encode IETF language tags — always null
                'parseErrors' => [],
            ],
            'song' => [
                'id'                 => 'VIDEOPSALM-0007',
                'title'              => 'Interchange Fixture Song',
                'number'             => 7,
                'songbook'           => 'VIDEOPSALM',
                'songbookName'       => 'Interchange Fixture Song',
                'language'           => 'en',   // hardcoded by this parser
                /* DEFECT (1) — buildVideoPsalm() (format-export.js:224-225) writes copyright to
                   'Memo1' and ccli to 'Memo2' (prefixed 'CCLI 1234567') and NEVER emits an
                   'Author' key at all — but _bulkImport_parseVideoPsalmSongbook() reads
                   sRaw['Copyright']/sRaw['CCLI']/sRaw['Author'] (song_importers.php:2593,2596,2577).
                   All three vanish on our own round trip; confirmed via the real generated JSON,
                   which carries "Memo1"/"Memo2" and no "Author"/"CCLI"/"Copyright" key at all. */
                'ccli'               => '',
                'iswc'               => '',
                'tuneName'           => '',
                'copyright'          => '',
                'verified'           => 0,
                'lyricsPublicDomain' => 0,
                'musicPublicDomain'  => 0,
                'hasAudio'           => 0,
                'hasSheetMusic'      => 0,
                'writers'            => [],   // DEFECT (1), continued — see above
                'composers'          => [],
                'arrangers'          => [],
                'adaptors'           => [],
                'translators'        => [],
                'components'         => [
                    ['type' => 'verse',  'number' => 1, 'lines' => ['Line one of verse one', 'Line two of verse one']],
                    ['type' => 'chorus', 'number' => 0, 'lines' => ['Chorus line one', 'Chorus line two']],
                    ['type' => 'verse',  'number' => 2, 'lines' => ['Line one of verse two', 'Line two of verse two']],
                ],
            ],
        ],
    ],

    /* -------------------------------------------------------------------------------------
     * FreeShow (.show) — neutral parsed structure.
     * ------------------------------------------------------------------------------------- */
    'freeShow' => [
        'importFormat' => 'freeshow',
        'parse' => static function (string $body): array {
            return _bulkImport_parseFreeShow($body);
        },
        'expected' => [
            'title'        => 'Interchange Fixture Song',
            'songbookName' => '',   // .show carries no songbook concept
            'entry'        => 0,
            'language'     => '',   // .show carries no language field
            'ccli'         => '1234567',        // lossless — meta.CCLI carries it unprefixed, unlike VideoPsalm's Memo2
            'copyright'    => '© 1987 Fixture Music',   // lossless
            /* buildFreeShow() joins writers+composers with ', ' into meta.author
               (format-export.js:308,316); the importer splits on /,&;\// (song_importers.php:7925)
               — the comma delimiter re-splits it correctly into THREE names. Same role-collapse
               family as OpenSong/OpenLyrics/Pro6 above, fourth mechanism (comma-join this time,
               but WITH correct re-splitting — the names are individually right, only the
               writer/composer ROLE distinction is lost). */
            'writers'      => ['Ada Writer', 'Bea Writer', 'Cy Composer'],
            'components'   => [
                ['type' => 'verse',  'number' => 1, 'lines' => ['Line one of verse one', 'Line two of verse one']],
                ['type' => 'chorus', 'number' => 0, 'lines' => ['Chorus line one', 'Chorus line two']],
                ['type' => 'verse',  'number' => 2, 'lines' => ['Line one of verse two', 'Line two of verse two']],
            ],
            /* No chords/notes/language: buildFreeShow() has no such channel (plain slide text runs only). */
        ],
    ],

    /* -------------------------------------------------------------------------------------
     * ChordPro (.cho) — full song-object shape; the ONLY format-export.js builder that
     * reads comp.chords at all (confirmed — grep every `comp.` identifier the file
     * dereferences), so this is the one closure cell that actually exercises the STRING-
     * cell vs ARRAY-cell chord shapes FIXTURE_SONG carries.
     * ------------------------------------------------------------------------------------- */
    'chordPro' => [
        'importFormat' => 'chordpro',
        'parse' => static function (string $body): array {
            return _bulkImport_parseChordPro($body, 'IX', 'Interchange Fixtures', 7);
        },
        'expected' => [
            'id'                 => 'IX-0007',
            'title'              => 'Interchange Fixture Song',
            'number'             => 7,          // from the $number ARGUMENT — ChordPro carries no number in-body at all
            'songbook'           => 'IX',
            'songbookName'       => 'Interchange Fixtures',
            'language'           => 'en',        // hardcoded by this parser
            'ccli'               => '1234567',   // lossless — {ccli:} round-trips exactly
            'iswc'               => '',
            'tuneName'           => '',           // ChordPro's exporter never reads song.tuneName at all
            'copyright'          => '© 1987 Fixture Music',   // lossless — {copyright:} carries the whole opaque string
            'verified'           => 0,
            'lyricsPublicDomain' => 0,
            'musicPublicDomain'  => 0,
            'hasAudio'           => 0,
            'hasSheetMusic'      => 0,
            /* DEFECT (2) — buildChordPro() (format-export.js:792,796) folds writers+composers
               into ONE {artist:} directive and NEVER emits {author:}/{lyricist:}/{words:}/
               {writer:} at all. The importer's `case 'artist': case 'composer': case 'music':`
               arm (song_importers.php:2866-2867) pushes the WHOLE joined string as a SINGLE
               composer entry — no splitting, unlike every other format above. Our two writers
               and one composer all come back as one indivisible "composer" string, and 'writers'
               is empty. Confirmed via the real generated .cho, which carries
               "{artist: Ada Writer, Bea Writer, Cy Composer}" and no {author:} line; confirmed
               AGAIN via mutation m3 below (flipping this arm to push into $writers[] instead
               flips this exact pair of assertions red). */
            'writers'            => [],
            'composers'          => ['Ada Writer, Bea Writer, Cy Composer'],
            'arrangers'          => [],
            'adaptors'           => [],
            'translators'        => [],
            'components'         => [
                [
                    'type' => 'verse', 'number' => 1,
                    'lines' => ['Line one of verse one', 'Line two of verse one'],
                    /* FIXTURE_SONG's verse-1 line-0 chord cell is the POSITIONED STRING
                       'G       D' (run-length whitespace, NOT word-aligned). buildChordPro()'s
                       chordProChordTokens() (format-export.js:705-714) treats a string cell as
                       whitespace-SPLIT tokens (['G','D']), discarding the actual column
                       positions entirely — unlike PP7's column-anchored chord model
                       (test-pp7-roundtrip.php's own header explains that contrast). Both tokens
                       still land correctly, word-index-aligned, because ChordPro's chord model
                       captures ORDER only, never position (format-export.js's own "DATA SHAPE"
                       doc-block says this outright). Round-trips to the space-joined string
                       'G D' — the app's own per-line chord-cell convention
                       (_bulkImport_chordProSplitLine(), song_importers.php:2759-2773). */
                    'chords' => ['G D', ''],
                ],
                [
                    'type' => 'chorus', 'number' => 0,
                    'lines' => ['Chorus line one', 'Chorus line two'],
                    /* The chorus's line-0 cell is the ARRAY ['C','G'] (word-start aligned by
                       construction) — chordProChordTokens() reads an array cell as its tokens
                       verbatim. Same round-tripped shape as the string cell above ('C G'),
                       proving format-export.js's own claim that the two INPUT shapes are
                       functionally equivalent once through buildChordProLine(). */
                    'chords' => ['C G', ''],
                ],
                [
                    'type' => 'verse', 'number' => 2,
                    'lines' => ['Line one of verse two', 'Line two of verse two'],
                    /* No 'chords' key — FIXTURE_SONG's verse 2 carries none at all; the flush()
                       closure (song_importers.php:2815-2825) strips a component's 'chords' array
                       when every cell is empty, keeping a chordless component byte-identical to
                       the pre-#1126 shape. */
                ],
            ],
            /* DEFECT (3), the ChordPro-side three cells — buildChordPro() DOES read
               song.alternateTitle/.key/.capo (format-export.js:795,797,798) into
               {subtitle:}/{key:}/{capo:}, and the generated .cho DOES carry all three
               ("{subtitle: The Other Title}", "{key: D}", "{capo: 3}") — but
               _bulkImport_parseChordPro()'s directive switch has no case for any of them; its
               documented `default:` arm (song_importers.php:2889-2893) says outright
               "subtitle/key/capo/… — no target field in the song model; ignored". These three
               fields are DOUBLY dead: even when present in the export (which real SongData rows
               never populate in the first place — see defect (3)'s class doc-block above), the
               importer discards them regardless. Nothing in this expected dict reflects
               alternateTitle/key/capo because the song-object shape this parser returns has no
               field for them at all — their absence here IS the evidence. */
        ],
    ],

];

$manifestKeys = is_array($manifest) ? array_keys($manifest) : [];
if ($manifest !== null) {
    foreach ($manifestKeys as $key) {
        $coveredExport = array_key_exists($key, $FORMATS) || array_key_exists($key, $EXPORT_EXEMPTIONS);
        ok("completeness: exporter key '{$key}' has an expected dict or a named exemption", $coveredExport);
    }
    foreach (array_keys($FORMATS) as $key) {
        ok("reverse: expected-dict key '{$key}' still exists on iHymnsFormatExport", in_array($key, $manifestKeys, true));
    }
}

/* ================================================================================================
 * (4) Per-format assertion: real bytes -> real parser -> diff against the expected dict.
 * ================================================================================================ */

if ($manifest !== null) {
    foreach ($FORMATS as $formatKey => $spec) {
        $path = $manifest[$formatKey] ?? null;
        if ($path === null || !is_file($path)) {
            ok("{$formatKey}: generated fixture file exists", false);
            continue;
        }
        $body = file_get_contents($path);
        ok("{$formatKey}: fixture file is non-empty", $body !== false && strlen($body) > 0);
        if ($body === false || $body === '') {
            continue;
        }

        [$actual, $err] = ($spec['parse'])($body);
        ok("{$formatKey}: real importer parses the generated fixture successfully"
            . ($actual === null ? ' (got failure: ' . ($err ?? 'null') . ')' : ''),
            $actual !== null);

        if ($actual !== null) {
            $diff = ihymnsFieldDiffFirstPath($actual, $spec['expected']);
            ok("{$formatKey}: round-tripped result matches the hand-traced expected dict"
                . ($diff !== null ? " [first diff at {$diff}]" : ''),
                $diff === null);
        }
    }
}

/* ================================================================================================
 * (5) COMPLETENESS CROSS-CHECK, importer side — every format import2.php's dropdown actually
 *     offers (excluding the synthetic 'auto') must be either one of the 7 formats closure-tested
 *     above, or named in $IMPORT_ONLY_EXEMPTIONS with a reason. Bidirectional, same rule-#34
 *     "either list growing without a row → red" discipline as section (2).
 * ================================================================================================ */

/**
 * A small, narrow, LOCALLY-scoped extractor for import2.php's `$formats` dropdown array
 * KEYS, reusing `dispatch_parser.php`'s shared `dispatchParserTokens()` tokeniser (the
 * "reuse dispatch_parser.php" this task's brief calls for). Deliberately NOT a copy of
 * `test-import-format-coverage.php`'s own `ifcArrayKeys()` pulled in some other way —
 * that helper is intentionally local to that file (its own header explains why: "the
 * array/match-arm shape below is specific to how import2.php and api2.php happen to
 * declare their format lists, so it stays here rather than growing the shared library
 * for a one-consumer shape"). This harness is now a SECOND consumer of that exact same
 * narrow shape (import2.php's `$formats` array keys only — nothing about api2.php's
 * dispatch, which this harness has no need to cross-check), so it gets its own equally
 * narrow, equally local copy rather than reaching into another test file's private
 * function or refactoring a passing, unrelated guard out from under itself.
 *
 * @return array<int,string> every $formats key except 'auto'
 */
function ihymnsInterchangeUiImportFormats(): array
{
    $file = dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/import2.php';
    $toks = dispatchParserTokens($file);
    $n = count($toks);
    $isWs = static fn ($t): bool => is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);

    for ($i = 0; $i < $n; $i++) {
        if (!(is_array($toks[$i]) && $toks[$i][0] === T_VARIABLE && $toks[$i][1] === '$formats')) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && $isWs($toks[$j])) { $j++; }
        if (($toks[$j] ?? null) !== '=') { continue; }
        $j++;
        while ($j < $n && $isWs($toks[$j])) { $j++; }
        if (($toks[$j] ?? null) !== '[') { continue; }

        $depth = 0;
        $keys = [];
        for (; $j < $n; $j++) {
            if ($toks[$j] === '[') { $depth++; continue; }
            if ($toks[$j] === ']') { $depth--; if ($depth === 0) { break; } continue; }
            if ($depth === 1 && is_array($toks[$j]) && $toks[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $k = $j + 1;
                while ($k < $n && $isWs($toks[$k])) { $k++; }
                if (is_array($toks[$k] ?? null) && $toks[$k][0] === T_DOUBLE_ARROW) {
                    $keys[] = trim($toks[$j][1], "'\"");
                }
            }
        }
        return array_values(array_diff($keys, ['auto']));
    }
    return [];
}

/**
 * The mapping from a `format-export.js` key (closure-tested above) to its
 * import2.php/api2.php format IDENTIFIER — the two naming conventions differ
 * (camelCase JS registry key vs lowercase dispatch string) so this is the explicit,
 * asserted bridge between them, consumed by the completeness cross-check below AND
 * (Slice 2, `--matrix` mode) the fidelity-matrix generator.
 *
 * @var array<string,string> formatExportKey => importFormatId
 */
$EXPORT_KEY_TO_IMPORT_FORMAT = array_combine(
    array_keys($FORMATS),
    array_map(static fn (array $spec): string => $spec['importFormat'], $FORMATS)
);

/**
 * Every import2.php format identifier with NO format-export.js exporter at all (so it
 * cannot be closure-tested the way the seven above are) — each named with the reason,
 * per rule #34's "either list growing without a row → red": a future format added to
 * import2.php's dropdown with neither an $EXPORT_KEY_TO_IMPORT_FORMAT entry nor a row
 * here fails the completeness check loudly.
 *
 * @var array<string,string> importFormatId => reason
 */
$IMPORT_ONLY_EXEMPTIONS = [
    'ihymns'      => 'native iHymns interchange JSON (#1633) — import-only, no format-export.js exporter exists for it',
    'pro7'        => 'the ONE binary format deliberately excluded here — closure-tested separately by the sanctioned tests/php/test-pp7-roundtrip.php (see this file\'s header)',
    'probundle'   => 'a ZIP container of .pro entries, pro7-adjacent — import-only, no format-export.js exporter',
    'proplaylist' => 'a ProPresenter service-order container, pro7-adjacent — import-only, no format-export.js exporter',
    'pptx'        => 'path/archive-based (a zip of slide XML) — no format-export.js exporter; test-import-format-coverage.php names the same exemption for its own fixture-parses-clean check',
    'easyworship' => 'path/archive-based (a SQLite Songs.db) — no format-export.js exporter; test-import-format-coverage.php names the same exemption for its own fixture-parses-clean check',
];

$uiImportFormats = ihymnsInterchangeUiImportFormats();
ok('vacuity check: import2.php $formats extraction found a non-trivial list (' . count($uiImportFormats) . ' entries)',
    count($uiImportFormats) >= 10);

$closureImportFormats = array_values($EXPORT_KEY_TO_IMPORT_FORMAT);

foreach ($uiImportFormats as $fmt) {
    $covered = in_array($fmt, $closureImportFormats, true) || array_key_exists($fmt, $IMPORT_ONLY_EXEMPTIONS);
    ok("completeness: importer format '{$fmt}' is either closure-tested above or a named import-only exemption", $covered);
}
foreach ($closureImportFormats as $fmt) {
    ok("reverse: closure-tested importer format '{$fmt}' is still offered in import2.php", in_array($fmt, $uiImportFormats, true));
}
foreach (array_keys($IMPORT_ONLY_EXEMPTIONS) as $fmt) {
    ok("reverse: exempted importer format '{$fmt}' is still offered in import2.php (exemption not stale)", in_array($fmt, $uiImportFormats, true));
}

/* ================================================================================================ */

if ($outDir !== '' && is_dir($outDir)) {
    /* Best-effort cleanup of the temp fixture dir — mirrors test-pp7-roundtrip.php's @unlink()
       of its own single temp file; failure to remove a stray temp dir is not a test failure. */
    foreach (glob($outDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA failure here means one of: (a) our OWN exporter and our OWN importer have drifted\n";
    echo "apart for some format — a song this app exports no longer comes back the way this\n";
    echo "harness's hand-traced expected dict says it should; or (b) the completeness cross-check\n";
    echo "found a format on one side (the export registry, or the import dropdown) with no\n";
    echo "corresponding row or exemption on the other. Neither proves anything about correctness\n";
    echo "against a REAL third-party file from another app — see this file's header.\n";
    exit(1);
}
echo "\nAll interchange closure + completeness assertions passed.\n";
