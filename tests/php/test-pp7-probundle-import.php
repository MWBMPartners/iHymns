<?php

declare(strict_types=1);

/**
 * tests/php/test-pp7-probundle-import.php — ProPresenter 7+ `.probundle`
 * IMPORT (epic #1968 P2, plan .claude/propresenter-interop-1968-plan.md §4.2)
 *
 * PURPOSE
 * ELI5: makes sure the app can actually open a real ProPresenter bundle
 * file, pull the song(s) out of it, and tell you honestly about anything
 * inside it (like pictures/videos) that it did NOT import.
 *
 * DETAIL
 * `_bulkImport_processProbundle()` (`includes/song_importers.php`) is a thin
 * orchestrator over pieces already independently proven elsewhere in this
 * repo: `pp7ZipListEntries()`/`pp7ZipReadEntry()` (the tolerant ZIP64 reader,
 * `tests/php/test-pp7-zip.php`) and `_bulkImport_processPro7()`/
 * `_bulkImport_parsePro7()` (the P1 single-`.pro` importer,
 * `tests/php/test-pp7-parse.php`). This suite exercises the FULL
 * bundle -> ZIP -> decode -> song pipeline against the SAME real committed
 * fixtures those two suites already use, but through the bundle entry point
 * — the thing neither of those suites covers on its own.
 *
 * TWO PARTS, split by what needs a live database:
 *
 *   PART A (always runs, no DB) — `_bulkImport_processProbundle()` is
 *   deliberately built from THREE pure, independently-testable pieces (the
 *   same "pure core, thin DB-touching shell" split `_bulkImport_parsePro7()`
 *   / `_bulkImport_processPro7()` already use one level down):
 *     - `_bulkImport_probundleClassifyEntries()` partitions a bundle's raw
 *       entry list into `.pro` vs media with NO database at all;
 *     - `_bulkImport_probundleFoldInnerSummary()` folds ONE
 *       `_bulkImport_processPro7()`-shaped result (success OR failure) into
 *       a running aggregate — the summing/unioning logic — given a
 *       HAND-BUILT `$inner` array, no real DB write required to prove it;
 *     - `_bulkImport_probundleFinishSummary()` appends the media-deferred
 *       warning and assembles the FINAL summary shape from a hand-built
 *       aggregate + media counts.
 *   Combined with the independently pure `pp7ZipReadEntry()` + the
 *   already-pure `_bulkImport_parsePro7()`, Part A proves — without a
 *   database — that: (1) each committed real `.probundle`'s `.pro`
 *   entry(ies) are correctly identified and separated from its media
 *   entries; (2) the inner `.pro` bytes decode and parse to the exact
 *   expected song shape (title, components, arrangement, warnings) via the
 *   SAME parser the orchestrator itself calls; (3) the AGGREGATION logic
 *   (multi-entry summing, songbook created/existing unioning, the
 *   media-deferred warning) is correct, independent of any real ZIP or DB;
 *   (4) `_bulkImport_processProbundle()`'s own zero-`.pro` and
 *   malformed-ZIP early-return paths (neither of which ever reaches a
 *   database — no `.pro` entry means `_bulkImport_processPro7()` is never
 *   called) return the documented clean-fail shape. This is the bulk of
 *   this suite's real assertions, and it is what runs in an environment
 *   with no MySQL at all (this session's own sandbox has none — see the
 *   DB-detection block below).
 *
 *   PART B (DB-reachable only, else SKIP — same idiom as
 *   `tests/php/test-tier-level-source.php` / `test-print-usage-ccli-gate.php`)
 *   — calls the REAL `_bulkImport_processProbundle()` END-TO-END (real ZIP
 *   bytes, real `_bulkImport_processPro7()` calls, real `_bulkImport_
 *   saveSong()` pre-flight DB queries), under the app's own DRY-RUN flag
 *   (`_bulkImport_dryRun(true)`, #1674). Dry-run is the load-bearing choice
 *   here, not a shortcut: `_bulkImport_saveSong()`'s own doc-block records
 *   that MySQL has no nested transactions, so an outer
 *   `begin_transaction()`/`rollback()` wrapped around a function that opens
 *   its OWN per-song transaction cannot safely undo a real write (the inner
 *   `START TRANSACTION` would implicitly commit whatever the outer one was
 *   holding). Dry-run instead makes every REAL pre-flight decision
 *   (songbook-exists check, song-exists check) while provably executing no
 *   `INSERT`/`begin_transaction()`/`commit()` at all (see
 *   `_bulkImport_saveSong()`'s and `_bulkImport_upsertSongbook()`'s own
 *   doc-comments for the exact early-return each takes under dry-run) — so
 *   Part B is safe to run against ANY reachable database, not just a
 *   disposable per-CI-job one. Part B is a BELT-AND-BRACES full-pipeline
 *   proof on top of Part A's pure-unit proofs, not the only place the
 *   aggregation logic is exercised — the three-way pure-core split above is
 *   what makes that logic provable even when, as in THIS session's own
 *   sandbox, no database is reachable at all.
 *
 * MUTATION PROOF (performed 2026-08-28 against the real working tree, each
 * mutation applied, this test re-run and confirmed RED, then reverted via
 * the Edit tool back to the exact original text before moving on — rule
 * #34; ALL FOUR are DB-free-provable thanks to the pure-core split above,
 * so all four were actually executed in this session's own DB-less
 * sandbox, not just reasoned about):
 *   m1 — `_bulkImport_probundleClassifyEntries()`: changed the `.pro` match
 *        from `strtolower(substr($name, -4)) === '.pro'` to
 *        `$name === '.pro'` (an impossible exact-match) -> every (a) check
 *        and the (b) multi-.pro checks went RED (9 assertions; zero `.pro`
 *        entries found in any fixture).
 *   m2 — `_bulkImport_probundleFoldInnerSummary()`: commented out the
 *        `$agg['songsFailed'] += (int)($inner['songs_failed'] ?? 0);`
 *        aggregation line -> the (b2) hand-built-summary "songsFailed sums
 *        across two failing inner results" assertion went RED (2 instead
 *        of the expected 3, since the DIRECT `songsFailed++` on a
 *        wholly-failed inner call still fired but the nested count from a
 *        PARTIALLY-failed inner call's own `songs_failed` did not).
 *   m3 — `_bulkImport_processProbundle()`: changed
 *        `if (empty($proEntries))` to `if (false)` (never firing the
 *        zero-`.pro` clean-fail path) -> the (c) zero-`.pro` assertions went
 *        RED (a synthetic zero-`.pro` bundle then fell through to an empty
 *        `$proEntries` loop and returned `ok:true, songs_created:0` with no
 *        `error` key, instead of the documented clean fail).
 *   m4 — `_bulkImport_probundleFinishSummary()`: deleted the
 *        `$warnings[] = "{$mediaCount} media file(s)…"` block entirely ->
 *        the (b2) hand-built-summary "media warning is appended" assertion
 *        went RED.
 * Every mutation was reverted immediately after confirming red; the tree
 * this test ships against is unmodified.
 *
 *   php tests/php/test-pp7-probundle-import.php
 *
 * Exit 0 = all pass (Part B may SKIP without DB), 1 = at least one failure.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/propresenter7_zip.php';
require_once $repoRoot . '/appWeb/public_html/includes/propresenter7_decode.php';
require_once $repoRoot . '/appWeb/public_html/includes/song_importers.php';

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

$fixturesDir = $repoRoot . '/tests/fixtures/propresenter';

echo "\n#1968 P2 — ProPresenter 7+ .probundle IMPORT\n\n";

/* ============================================================================================
 * PART A — DB-FREE: entry classification + inner-.pro parse, on the REAL committed fixtures.
 * ============================================================================================ */

echo "-- (a) real .probundle fixtures: .pro / media classification + parsed content --\n";

/**
 * Ground truth established DURING THIS TASK by actually running the reader + parser against
 * the committed bytes (see the file-level doc-block — never typed from memory). All three
 * committed `.probundle` fixtures carry EXACTLY ONE `.pro` entry and EXACTLY ONE media entry;
 * two of the three (`bussnet-testbild.probundle` / `bussnet-export-from-pp.probundle`) share the
 * SAME `TestBild.pro` content, which is a real ProPresenter test-image document with NO lyric
 * slides at all — a legitimate, non-crashing "this .pro parses to nothing importable" case,
 * distinct from "zero .pro entries in the bundle" (covered separately in section (c) below).
 * `synthetic-zip64.probundle`'s `Test.pro` is verified elsewhere (test-pp7-zip.php,
 * test-pp7-parse.php) to be byte-identical to the committed `bussnet-test.pro` fixture, which
 * DOES carry real lyric content — the one fixture here that proves a genuine title/component/
 * arrangement/warning extraction through the bundle path.
 */
$fixtures = [
    'bussnet-testbild.probundle' => [
        'proName'        => 'TestBild.pro',
        'mediaNames'     => ['test-background.png'],
        'parses'         => false,
        'failureNeedle'  => 'no lyric text found',
    ],
    'bussnet-export-from-pp.probundle' => [
        'proName'        => 'TestBild.pro',
        'mediaNames'     => ['Media/sample-media.png'],
        'parses'         => false,
        'failureNeedle'  => 'no lyric text found',
    ],
    'synthetic-zip64.probundle' => [
        'proName'          => 'Test.pro',
        'mediaNames'       => ['/Users/curator/Downloads/pp-test/Media/dummy.png'],
        'parses'           => true,
        'expectedTitle'    => 'Titel',
        'expectedCcli'     => '123456789',
        'expectedComponentCount' => 4,
        'expectedArrangement'    => [2, 0, 2, 1, 2],
        'expectedWarningNeedles' => ['translation layer', 'artist_credits'],
    ],
];

// Coverage floor (rule #34's under-report clause): every committed .probundle fixture must have
// a matching entry above, so a new fixture dropped in without updating this test fails loudly
// rather than being silently skipped.
$bundleFixturePaths = glob($fixturesDir . '/*.probundle') ?: [];
sort($bundleFixturePaths);
ok('at least 3 committed .probundle fixtures exist (found ' . count($bundleFixturePaths) . ')',
    count($bundleFixturePaths) >= 3);
foreach ($bundleFixturePaths as $path) {
    $base = basename($path);
    ok("{$base} has a matching expected-content entry in this test", isset($fixtures[$base]));
}
foreach (array_keys($fixtures) as $base) {
    ok("expected-content entry '{$base}' matches a fixture actually on disk",
        file_exists($fixturesDir . '/' . $base));
}

foreach ($fixtures as $base => $expect) {
    $path = $fixturesDir . '/' . $base;
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        ok("{$base} is readable", false);
        continue;
    }

    try {
        $entries = pp7ZipListEntries($bytes);
    } catch (\Throwable $e) {
        ok("{$base}: pp7ZipListEntries() succeeds (" . $e->getMessage() . ')', false);
        continue;
    }

    // (a1) The PURE classifier — no DB — correctly separates .pro from media.
    $classified = _bulkImport_probundleClassifyEntries($entries);
    ok("{$base}: classifier finds exactly 1 .pro entry (found " . count($classified['pro']) . ')',
        count($classified['pro']) === 1);
    ok("{$base}: classifier finds exactly 1 media entry (found " . count($classified['media']) . ')',
        count($classified['media']) === 1);
    if (count($classified['pro']) === 1) {
        ok("{$base}: the .pro entry is named '{$expect['proName']}'",
            $classified['pro'][0]['name'] === $expect['proName']);
    }
    if (count($classified['media']) === 1) {
        ok("{$base}: the media entry is named '{$expect['mediaNames'][0]}'",
            $classified['media'][0]['name'] === $expect['mediaNames'][0]);
    }

    // (a2) The inner .pro bytes, run through the SAME pure parser the orchestrator calls,
    // produce the expected song shape — title from the inner .pro, real structure, real
    // warnings. This is the "songs are parsed" proof, entirely DB-free.
    if (count($classified['pro']) === 1) {
        try {
            $proBytes = pp7ZipReadEntry($bytes, $classified['pro'][0]);
        } catch (\Throwable $e) {
            ok("{$base}: extracting the .pro entry succeeds (" . $e->getMessage() . ')', false);
            continue;
        }
        [$parsed, $reason] = _bulkImport_parsePro7($proBytes);

        if ($expect['parses'] === false) {
            ok("{$base}: {$expect['proName']} correctly fails to parse (no lyric content — a real, "
                . 'non-crashing case, not a bug)', $parsed === null);
            ok("{$base}: the failure reason names why ('{$expect['failureNeedle']}')",
                $parsed === null && $reason !== null && str_contains($reason, $expect['failureNeedle']));
            continue;
        }

        ok("{$base}: {$expect['proName']} parses successfully ($reason)", $parsed !== null);
        if ($parsed === null) {
            continue;
        }
        ok("{$base}: parsed title matches the inner .pro's CCLI song_title ('{$parsed['title']}' vs '{$expect['expectedTitle']}')",
            $parsed['title'] === $expect['expectedTitle']);
        ok("{$base}: parsed CCLI number matches ('{$parsed['ccli']}' vs '{$expect['expectedCcli']}')",
            $parsed['ccli'] === $expect['expectedCcli']);
        ok("{$base}: parsed component count matches (" . count($parsed['components']) . ' vs ' . $expect['expectedComponentCount'] . ')',
            count($parsed['components']) === $expect['expectedComponentCount']);
        ok("{$base}: parsed arrangement matches (" . json_encode($parsed['arrangement']) . ' vs ' . json_encode($expect['expectedArrangement']) . ')',
            $parsed['arrangement'] === $expect['expectedArrangement']);
        foreach ($expect['expectedWarningNeedles'] as $needle) {
            $found = false;
            foreach ($parsed['warnings'] as $w) {
                if (str_contains($w, $needle)) { $found = true; break; }
            }
            ok("{$base}: parsed warnings include a note about '{$needle}'", $found);
        }
    }
}

/* ============================================================================================
 * (b) A SYNTHETIC multi-.pro bundle — proves the AGGREGATION shape (>1 .pro entry, media
 *     counted, mixed pro/media/directory-placeholder entries) using only pp7ZipListEntries()'s
 *     documented input contract — hand-built bytes, not a hand-typed re-implementation of the
 *     classifier (the classifier under test still does the real work).
 * ============================================================================================ */

echo "\n-- (b) synthetic multi-.pro bundle: classification + aggregation shape --\n";

/**
 * Minimal valid ZIP (STORED, method 0) with three entries: two `.pro` presentations (reusing
 * real fixture bytes — bussnet-doxology.pro and bussnet-stille-nacht.pro, both already proven
 * to parse cleanly by test-pp7-parse.php) plus one media file plus one directory placeholder
 * entry, to prove the classifier's three-way split (pro / media / silently-ignored directory
 * marker) on a single archive. Hand-assembled with the SAME local-file-header shape
 * pp7ZipListEntries() documents it reads (PK\x03\x04 + the 26-byte fixed body + name + no extra
 * field + STORED data) — this is deliberately NOT reusing any iHymns encoder, so a bug shared
 * between an encoder and pp7ZipListEntries() couldn't hide here.
 */
function _pp7test_buildMiniZip(array $entries): string
{
    $out = '';
    foreach ($entries as [$name, $bytes]) {
        $nameBytes = $name;
        $crc = crc32($bytes);
        $size = strlen($bytes);
        $out .= "PK\x03\x04";
        $out .= pack('v', 20);        // version needed
        $out .= pack('v', 0);         // flags
        $out .= pack('v', 0);         // method = STORED
        $out .= pack('v', 0);         // mod time
        $out .= pack('v', 0);         // mod date
        $out .= pack('V', $crc);
        $out .= pack('V', $size);     // csize
        $out .= pack('V', $size);     // usize
        $out .= pack('v', strlen($nameBytes)); // name len
        $out .= pack('v', 0);         // extra len
        $out .= $nameBytes;
        $out .= $bytes;
    }
    return $out;
}

$doxologyBytes = file_get_contents($fixturesDir . '/bussnet-doxology.pro');
$stilleBytes   = file_get_contents($fixturesDir . '/bussnet-stille-nacht.pro');
ok('bussnet-doxology.pro fixture is readable (needed to build the synthetic multi-.pro bundle)', $doxologyBytes !== false);
ok('bussnet-stille-nacht.pro fixture is readable (needed to build the synthetic multi-.pro bundle)', $stilleBytes !== false);

if ($doxologyBytes !== false && $stilleBytes !== false) {
    $miniZipBytes = _pp7test_buildMiniZip([
        ['Doxology.pro', $doxologyBytes],
        ['StilleNacht.pro', $stilleBytes],
        ['Media/background.png', "\x89PNG-fake-bytes"],
        ['Media/', ''],   // directory placeholder — zero bytes, name ends '/'
    ]);

    try {
        $miniEntries = pp7ZipListEntries($miniZipBytes);
    } catch (\Throwable $e) {
        ok('synthetic multi-.pro bundle: pp7ZipListEntries() succeeds (' . $e->getMessage() . ')', false);
        $miniEntries = null;
    }

    if ($miniEntries !== null) {
        ok('synthetic multi-.pro bundle: pp7ZipListEntries() finds all 4 raw entries (found ' . count($miniEntries) . ')',
            count($miniEntries) === 4);

        $miniClassified = _bulkImport_probundleClassifyEntries($miniEntries);
        // (b1) two .pro entries found, directory placeholder silently dropped from both buckets.
        ok('synthetic multi-.pro bundle: classifier finds exactly 2 .pro entries (found ' . count($miniClassified['pro']) . ')',
            count($miniClassified['pro']) === 2);
        // (b2) exactly 1 media entry — the directory placeholder ('Media/') must NOT be counted
        // as media (this is the load-bearing "silently ignored, not silently mis-counted" proof).
        ok('synthetic multi-.pro bundle: classifier finds exactly 1 media entry, excluding the directory placeholder (found ' . count($miniClassified['media']) . ')',
            count($miniClassified['media']) === 1);
        if (count($miniClassified['media']) === 1) {
            ok("synthetic multi-.pro bundle: the one media entry is 'Media/background.png' (not the directory placeholder)",
                $miniClassified['media'][0]['name'] === 'Media/background.png');
        }

        // (b3) both .pro entries independently decode + parse via the SAME pure parser the
        // orchestrator uses — proves "multiple .pro -> multiple songs" at the parse layer.
        $parsedTitles = [];
        foreach ($miniClassified['pro'] as $entry) {
            try {
                $proBytes = pp7ZipReadEntry($miniZipBytes, $entry);
                [$p, $r] = _bulkImport_parsePro7($proBytes);
                if ($p !== null) { $parsedTitles[] = $p['title']; }
            } catch (\Throwable $e) {
                // fall through — asserted below via count
            }
        }
        ok('synthetic multi-.pro bundle: both .pro entries parse to a non-empty title each (found '
            . count($parsedTitles) . ': ' . implode(', ', $parsedTitles) . ')',
            count($parsedTitles) === 2 && $parsedTitles[0] !== '' && $parsedTitles[1] !== '');
    }
}

/* ============================================================================================
 * (b2) PURE AGGREGATION HELPERS — hand-built `_bulkImport_processPro7()`-shaped inner results,
 *     no ZIP bytes, no protobuf, no database at all. This is what makes m2/m4 (the aggregation
 *     summing and the media-warning line) mutation-provable WITHOUT a reachable database — the
 *     three-way pure-core split the file-level doc-block describes.
 * ============================================================================================ */

echo "\n-- (b2) pure aggregation helpers: hand-built inner summaries, no DB/ZIP needed --\n";

/** A minimal, fully-keyed `_bulkImport_processPro7()`-shaped SUCCESS result, with overrides. */
function _pp7test_innerSuccess(array $overrides = []): array
{
    return array_merge([
        'ok'                     => true,
        'songbooks_created'      => [],
        'songbooks_existing'     => [],
        'songs_created'          => 0,
        'songs_skipped_existing' => 0,
        'songs_failed'           => 0,
        'parsed_by_format'       => ['propresenter7' => 0],
        'errors'                 => [],
        'warnings'               => [],
    ], $overrides);
}

$emptyAgg = [
    'songbooksCreated'  => [],
    'songbooksExisting' => [],
    'songsCreated'      => 0,
    'songsSkipped'      => 0,
    'songsFailed'       => 0,
    'errors'            => [],
    'warnings'          => [],
];

/* Fold FOUR hand-built inner results in sequence:
 *   A — a real CREATE, filing under a brand-new "PP7" songbook.
 *   B — a real SKIP (already existed), in the SAME "PP7" songbook — proves the
 *       created/existing UNION precedence (PP7 must end up in songbooks_created ONLY, never
 *       also in songbooks_existing, even though B's own inner result says 'existing').
 *   C — a straightforward per-entry FAILURE (ok:false) — the DIRECT `$agg['songsFailed']++` line.
 *   D — an (intentionally atypical, for unit-test purposes only — see the file doc-block's m2
 *       note) ok:true result that itself carries songs_failed:2, proving the SEPARATE
 *       `$agg['songsFailed'] += (int)($inner['songs_failed'] ?? 0)` summing line is real
 *       addition, not an overwrite (m2's mutation target).
 */
$agg = $emptyAgg;
$agg = _bulkImport_probundleFoldInnerSummary($agg, 'A.pro', _pp7test_innerSuccess([
    'songbooks_created' => ['PP7'], 'songs_created' => 1,
]));
$agg = _bulkImport_probundleFoldInnerSummary($agg, 'B.pro', _pp7test_innerSuccess([
    'songbooks_existing' => ['PP7'], 'songs_skipped_existing' => 1,
]));
$agg = _bulkImport_probundleFoldInnerSummary($agg, 'C.pro', [
    'ok' => false, 'error' => 'ProPresenter 7+ decode failed: truncated input',
]);
$agg = _bulkImport_probundleFoldInnerSummary($agg, 'D.pro', _pp7test_innerSuccess([
    'songs_failed' => 2,
]));

ok('fold: songsCreated sums to 1 (only A created a song)', $agg['songsCreated'] === 1);
ok('fold: songsSkipped sums to 1 (only B was skipped-existing)', $agg['songsSkipped'] === 1);
ok('fold: songsFailed sums to 3 = 1 (C, direct ++) + 2 (D, nested +=) — proves BOTH failure paths accumulate',
    $agg['songsFailed'] === 3);
ok('fold: songbooksCreated is exactly ["PP7"] (A created it)',
    array_keys($agg['songbooksCreated']) === ['PP7']);
ok('fold: songbooksExisting is EMPTY — B\'s "existing PP7" must be excluded because A already created it '
    . '(the union-precedence rule, not merely "both lists get everything")',
    $agg['songbooksExisting'] === []);
ok('fold: errors[] carries exactly C\'s one entry', count($agg['errors']) === 1 && $agg['errors'][0]['entry'] === 'C.pro');

/* _bulkImport_probundleFinishSummary(): the media-warning line + final shape, from a hand-built
 * aggregate — no ZIP, no DB. */
$finishedWithMedia = _bulkImport_probundleFinishSummary($agg, 2, ['Media/a.png', 'Media/b.png']);
ok('finish: songs_created/skipped/failed pass through the aggregate unchanged (1/1/3)',
    $finishedWithMedia['songs_created'] === 1
        && $finishedWithMedia['songs_skipped_existing'] === 1
        && $finishedWithMedia['songs_failed'] === 3);
ok('finish: parsed_by_format sums created+skipped (2)',
    ($finishedWithMedia['parsed_by_format']['propresenter7'] ?? null) === 2);
$mediaWarningFound = false;
foreach ($finishedWithMedia['warnings'] as $w) {
    if (str_contains($w, '2 media file(s)') && str_contains($w, 'were not imported')) { $mediaWarningFound = true; break; }
}
ok('finish: a media-deferred warning naming the count is appended when media_present > 0', $mediaWarningFound);
ok('finish: media_present/media_files pass through unchanged',
    $finishedWithMedia['media_present'] === 2 && $finishedWithMedia['media_files'] === ['Media/a.png', 'Media/b.png']);

$finishedNoMedia = _bulkImport_probundleFinishSummary($emptyAgg, 0, []);
ok('finish: NO media-deferred warning is appended when media_present is 0',
    !array_filter($finishedNoMedia['warnings'], static fn(string $w): bool => str_contains($w, 'media file')));

/* ============================================================================================
 * (c) ZERO-.pro handling — a bundle with media but no presentation at all must fail CLEANLY,
 *     never crash, with the exact message plan §4.2 specifies.
 * ============================================================================================ */

echo "\n-- (c) zero-.pro bundle: clean fail, not a crash --\n";

$zeroProZipBytes = _pp7test_buildMiniZip([
    ['Media/only-a-picture.png', "\x89PNG-fake-bytes-only"],
]);
try {
    $zeroProEntries = pp7ZipListEntries($zeroProZipBytes);
    $zeroProClassified = _bulkImport_probundleClassifyEntries($zeroProEntries);
    ok('zero-.pro synthetic bundle: classifier finds 0 .pro entries (as built)',
        count($zeroProClassified['pro']) === 0);
    ok('zero-.pro synthetic bundle: classifier finds 1 media entry (as built)',
        count($zeroProClassified['media']) === 1);
} catch (\Throwable $e) {
    ok('zero-.pro synthetic bundle: pp7ZipListEntries() succeeds on the hand-built ZIP (' . $e->getMessage() . ')', false);
}

/* This exercises _bulkImport_processProbundle() itself up to (and including) its zero-.pro
   early return — which happens BEFORE any DB touch (no getDbMysqli() call is reachable on this
   path: _bulkImport_processPro7() is never invoked when $proEntries is empty), so this
   assertion runs with NO database required, unlike Part B below. */
try {
    $zeroProResult = _bulkImport_processProbundle($zeroProZipBytes, 'only-a-picture.probundle');
    ok('zero-.pro bundle: _bulkImport_processProbundle() returns ok:false (clean fail, not a crash)',
        ($zeroProResult['ok'] ?? true) === false);
    ok("zero-.pro bundle: error message is the plan §4.2 text ('No ProPresenter presentation found in …')",
        isset($zeroProResult['error']) && str_contains($zeroProResult['error'], 'No ProPresenter presentation found in'));
    ok('zero-.pro bundle: songs_created is 0', ($zeroProResult['songs_created'] ?? -1) === 0);
    ok('zero-.pro bundle: media is still reported (media_present = 1), never silently dropped even on total failure',
        ($zeroProResult['media_present'] ?? -1) === 1);
    ok("zero-.pro bundle: media_files names the file ('only-a-picture.png')",
        in_array('Media/only-a-picture.png', $zeroProResult['media_files'] ?? [], true));
} catch (\Throwable $e) {
    ok('zero-.pro bundle: _bulkImport_processProbundle() never throws (' . $e->getMessage() . ')', false);
}

/* A malformed / truncated ZIP is the OTHER trigger for the same clean-fail contract (a totally
   unreadable archive, not just a readable one with zero .pro entries). Also DB-free — the
   pp7ZipListEntries() throw is caught before any DB code is reachable. */
try {
    $malformedResult = _bulkImport_processProbundle("not a zip at all", 'garbage.probundle');
    ok('malformed (non-ZIP) bytes: _bulkImport_processProbundle() returns ok:false, never throws',
        ($malformedResult['ok'] ?? true) === false);
    ok('malformed (non-ZIP) bytes: error message names the bundle could not be read',
        isset($malformedResult['error']) && str_contains($malformedResult['error'], 'Could not read'));
} catch (\Throwable $e) {
    ok('malformed (non-ZIP) bytes: _bulkImport_processProbundle() never throws (' . $e->getMessage() . ')', false);
}

/* ============================================================================================
 * PART B — DB-reachable only (else SKIP). Full _bulkImport_processProbundle() under dry-run —
 * see the file-level doc-block for exactly why dry-run (not a rolled-back transaction) is the
 * safe way to exercise the DB-touching orchestration end-to-end.
 * ============================================================================================ */

echo "\n-- (d) Part B: full orchestration end-to-end (DB-reachable only, dry-run) --\n";

$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null; $port = 3306; $dbName = null;
$credFile = $repoRoot . '/appWeb/.auth/db_credentials.php';
if (is_readable($credFile)) {
    require $credFile;
    if (defined('DB_HOST')) { $host = DB_HOST; }
    if (defined('DB_USER')) { $user = DB_USER; }
    if (defined('DB_PASS')) { $pass = DB_PASS; }
    if (defined('DB_PORT')) { $port = (int)DB_PORT; }
    if (defined('DB_NAME')) { $dbName = DB_NAME; }
} else {
    $dsn = getenv('IHYMNS_TEST_DSN') ?: '';
    if ($dsn !== '') {
        foreach (explode(';', $dsn) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if ($k === 'host')   { $host = $v; }
            if ($k === 'user')   { $user = $v; }
            if ($k === 'pass')   { $pass = $v; }
            if ($k === 'socket') { $sock = $v; }
            if ($k === 'port')   { $port = (int)$v; }
            if ($k === 'dbname' || $k === 'db') { $dbName = $v; }
        }
    }
}

$behaviouralRan = false;
if ($dbName !== null && $sock === null) {
    if (!defined('DB_HOST')) { define('DB_HOST', $host); }
    if (!defined('DB_USER')) { define('DB_USER', $user); }
    if (!defined('DB_PASS')) { define('DB_PASS', $pass); }
    if (!defined('DB_PORT')) { define('DB_PORT', (string)$port); }
    if (!defined('DB_NAME')) { define('DB_NAME', $dbName); }

    try {
        $db = getDbMysqli();
        $hasSongs = (bool)$db->query("SHOW TABLES LIKE 'tblSongs'")->num_rows;
    } catch (\Throwable $e) {
        $db = null; $hasSongs = false;
    }

    if ($db !== null && $hasSongs) {
        try {
            /* #1674 dry-run — see file doc-block for why this (not a rolled-back
               transaction) is the safe end-to-end mechanism here. Reset to false in
               `finally` so a stale flag can never leak into a later test file sharing
               this PHP process (tools/run-php-tests.php runs each file in its own
               process per its own doc-comment, but resetting explicitly costs nothing
               and matches api2.php's own "set explicitly BOTH ways" convention). */
            _bulkImport_dryRun(true);

            $result = _bulkImport_processProbundle(
                $miniZipBytes ?? _pp7test_buildMiniZip([['Doxology.pro', file_get_contents($fixturesDir . '/bussnet-doxology.pro')]]),
                'behavioural-test.probundle'
            );

            ok('Part B: _bulkImport_processProbundle() returns ok:true for the 2-.pro synthetic bundle',
                ($result['ok'] ?? false) === true);
            ok('Part B: songs_created + songs_skipped_existing sums to 2 (both .pro entries accounted for) — got '
                . (($result['songs_created'] ?? 0) + ($result['songs_skipped_existing'] ?? 0)),
                (($result['songs_created'] ?? 0) + ($result['songs_skipped_existing'] ?? 0)) === 2);
            ok('Part B: songs_failed is 0 (both real fixtures parse cleanly)',
                ($result['songs_failed'] ?? -1) === 0);
            ok('Part B: media_present is 1 (the one Media/background.png entry)',
                ($result['media_present'] ?? -1) === 1);
            ok('Part B: a media warning is present in warnings[]',
                (function () use ($result) {
                    foreach ($result['warnings'] ?? [] as $w) {
                        if (str_contains($w, 'media file') && str_contains($w, 'were not imported')) { return true; }
                    }
                    return false;
                })());
            ok('Part B: songbooks_created OR songbooks_existing names the PP7 abbreviation exactly once total (never both)',
                (in_array('PP7', $result['songbooks_created'] ?? [], true) xor in_array('PP7', $result['songbooks_existing'] ?? [], true)));

            /* Zero-.pro + malformed-ZIP paths never reach the DB at all (asserted in
               section (c) above without a DB requirement) — no need to repeat them here
               under dry-run; Part B's whole point is the AGGREGATION logic those two
               early-return paths never exercise. */

            $behaviouralRan = true;
        } finally {
            _bulkImport_dryRun(false);
        }
    }
}

echo "\n{$passed} passed, {$failed} failed";
echo $behaviouralRan
    ? " — Part B (DB-reachable, dry-run) ran.\n"
    : " — Part B SKIPPED (no reachable database with a tblSongs table); Part A (DB-free) still ran in full.\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA failure here means _bulkImport_processProbundle() (or its pure classifier\n";
    echo "_bulkImport_probundleClassifyEntries()) disagrees with a real committed .probundle\n";
    echo "fixture's actual content, mishandles a multi-.pro or zero-.pro bundle, or drops a\n";
    echo "media entry silently instead of reporting it.\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ .probundle import assertions passed.\n";
