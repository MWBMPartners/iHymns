<?php

declare(strict_types=1);

/**
 * tests/php/test-pp7-proplaylist-import.php — ProPresenter 7+ `.proplaylist`
 * IMPORT -> ONE iHymns set list (epic #1968 PR-3,
 * plan .claude/propresenter-interop-1968-plan.md §5.1)
 *
 * PURPOSE
 * ELI5: makes sure the app can open a real ProPresenter playlist file (a
 * whole service order), correctly work out which lines are songs, which are
 * section dividers, and which are spacers, in the RIGHT ORDER — and, when a
 * database is reachable, that it actually builds one real set list from all
 * of that.
 *
 * DETAIL
 * `_bulkImport_processProplaylist()` (`includes/song_importers.php`) is a
 * thin DB-touching shell over a 100% PURE mapping core —
 * `_bulkImport_proplaylistBuildPlan()` (+ the helpers it calls:
 * `_bulkImport_proplaylistFlattenItems()`, `_bulkImport_proplaylistMatchEntry()`,
 * `_bulkImport_proplaylistName()`) — exactly the "pure core, thin DB-touching
 * shell" split `_bulkImport_processProbundle()` already established one file
 * section above this one (`tests/php/test-pp7-probundle-import.php`'s own
 * doc-block). This suite exercises that pure core directly against the
 * THREE real committed `.proplaylist` fixtures (`bussnet-testplaylist`,
 * `bussnet-sample-service`, `bussnet-empty-playlist` — the same fixtures
 * `tests/php/test-pp7-playlist-decode.php` already proves the DECODER
 * against, cross-validated there against `protobufjs`), so no database is
 * needed to prove the item -> song/header/slot MAPPING decision is correct.
 *
 * TWO PARTS, split by what needs a live database (same idiom as
 * `test-pp7-probundle-import.php`'s own PART A / PART B):
 *
 *   PART A (always runs, no DB) — the pure mapping core, run against the
 *   three real fixtures' ACTUAL decoded content (via `pp7ReadPlaylistBundle()`,
 *   itself pure/DB-free): item -> {header|placeholder|song-embedded|
 *   song-unresolved|skipped} classification, in order; the URL-decoded-
 *   basename entry matcher (`_bulkImport_proplaylistMatchEntry()`), including
 *   a case built to NOT match (referenced-not-found); the nested-playlist
 *   flattening rule (`_bulkImport_proplaylistFlattenItems()` — a synthetic
 *   header ONLY for a nested node, never the top-level one); the playlist
 *   `Name` fallback ladder (`_bulkImport_proplaylistName()`); and the shared
 *   media-deferred-warning line (`_bulkImport_pp7MediaDeferredWarning()`,
 *   the SAME function `.probundle`'s own finisher now calls — extracted in
 *   this task so the two ZIP-container importers say the identical sentence,
 *   rule #22/#35).
 *
 *   PART B (DB-reachable only, else SKIP — same idiom as
 *   `test-pp7-probundle-import.php`) — calls the REAL, full
 *   `_bulkImport_processProplaylist()` END-TO-END (real ZIP bytes, a real
 *   `_bulkImport_processPro7()` call, a real would-be `tblUserSetlists`
 *   INSERT) under the app's own DRY-RUN flag (`_bulkImport_dryRun(true)`,
 *   #1674) — for the SAME reason `test-pp7-probundle-import.php`'s own
 *   doc-block gives (MySQL has no nested transactions, so an outer rollback
 *   cannot safely wrap `_bulkImport_saveSong()`'s own per-song transaction).
 *   This function's OWN setlist INSERT is explicitly dry-run-gated too (see
 *   its doc-block), so Part B proves the FULL pipeline's decisions —
 *   including `setlists_created`/`setlist` — without leaving a stray row in
 *   whatever database happens to be reachable. As the task brief directs,
 *   "the DB-writing part will run in CI's MySQL" for real (that CI run is
 *   NOT dry-run — this file's own Part B is the safe-anywhere proof that the
 *   DECISIONS are right; a real, non-dry-run write is exercised by CI's
 *   database, never by this file against an arbitrary reachable DB).
 *
 * MUTATION PROOF (performed 2026-08-28 against the real working tree, each
 * mutation applied via the Edit tool, this test re-run and confirmed RED,
 * then reverted back to the exact original text before moving on — rule
 * #34; all four are Part-A/DB-free-provable, so all four were actually
 * executed in this session's own DB-less sandbox):
 *   m1 — `_bulkImport_proplaylistMatchEntry()`: changed
 *        `strcasecmp(basename($entryName), $base) === 0` to
 *        `strcasecmp(basename($entryName), $base) === 1` (impossible) ->
 *        the (a) testplaylist/sample-service "song-embedded" assertions
 *        went RED (every presentation item fell through to
 *        'song-unresolved' instead, since NOTHING could ever match).
 *   m2 — `_bulkImport_proplaylistBuildPlan()`: deleted the `case
 *        'placeholder':` arm entirely (falls through to the default
 *        skip branch) -> the (a) testplaylist "plan[2] is a placeholder"
 *        assertion went RED (it became a 'skipped' entry instead).
 *   m3 — `_bulkImport_proplaylistFlattenItems()`: changed `if ($depth > 0)`
 *        to `if ($depth >= 0)` (synthesise a header for the TOP-LEVEL node
 *        too) -> the (a) testplaylist "plan has exactly 4 entries" AND
 *        "plan[0] is the real header, not a synthetic one" assertions went
 *        RED (a spurious 5th entry, `{kind:'header',label:'TestPlaylist'}`,
 *        appeared first — every real fixture's top playlist DOES carry a
 *        name, so this mutation is caught by the real fixtures themselves,
 *        no synthetic tree needed).
 *   m4 — `_bulkImport_pp7MediaDeferredWarning()`: changed
 *        `if ($mediaCount <= 0)` to `if ($mediaCount < 0)` (0 no longer
 *        short-circuits) -> the (b) "sample-service/empty: NO media
 *        warning" assertions went RED (both zero-media fixtures produced a
 *        "0 media file(s)…" warning instead of null) AND, since this
 *        function is now the SAME shared helper `.probundle`'s own
 *        `_bulkImport_probundleFinishSummary()` calls (extracted in this
 *        task — see that function's own updated doc-block),
 *        `test-pp7-probundle-import.php`'s "NO media-deferred warning is
 *        appended when media_present is 0" assertion went RED too — real,
 *        cross-suite evidence the extraction actually removed a duplicate
 *        rather than merely relocating one copy of it.
 * Every mutation was reverted immediately after confirming red; the tree
 * this test ships against is unmodified.
 *
 *   php tests/php/test-pp7-proplaylist-import.php
 *
 * Exit 0 = all pass (Part B may SKIP without DB), 1 = at least one failure.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/propresenter7_decode.php';
require_once $repoRoot . '/appWeb/public_html/includes/propresenter7_zip.php';
require_once $repoRoot . '/appWeb/public_html/includes/propresenter7_playlist.php';
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

echo "\n#1968 PR-3 — ProPresenter 7+ .proplaylist IMPORT -> set list\n\n";

/* ============================================================================================
 * PART A — DB-FREE: the pure item -> {header|placeholder|song-embedded|song-unresolved|skipped}
 * mapping core, run against the THREE real committed .proplaylist fixtures.
 * ============================================================================================ */

echo "-- (a) real .proplaylist fixtures: item -> song/header/slot mapping --\n";

/**
 * Ground truth established DURING THIS TASK by actually decoding the committed bytes with
 * pp7ReadPlaylistBundle() and printing the result (see the file-level doc-block — never typed
 * from memory), cross-checked against the ALREADY-COMMITTED expected/*.playlist.json fixtures
 * test-pp7-playlist-decode.php validates the decoder itself against.
 */
$fixtures = [
    'bussnet-testplaylist.proplaylist' => [
        'name'     => 'TestPlaylist',
        'plan'     => [
            ['kind' => 'header',        'label' => 'Songs'],
            ['kind' => 'song-embedded', 'entryName' => 'Embedded Song One.pro', 'itemName' => 'Embedded Song One', 'arrangementName' => 'normal'],
            ['kind' => 'placeholder',   'label' => 'Spacer'],
            ['kind' => 'song-embedded', 'entryName' => 'Embedded Song Two.pro', 'itemName' => 'Embedded Song Two', 'arrangementName' => 'short'],
        ],
        'mediaCount' => 1,
        'mediaNames' => ['media/sample-background.jpg'],
    ],
    'bussnet-sample-service.proplaylist' => [
        'name'     => 'Sample Service',
        'plan'     => [
            ['kind' => 'header',        'label' => 'Opening'],
            ['kind' => 'song-embedded', 'entryName' => 'Sample Song.pro', 'itemName' => 'Sample Song', 'arrangementName' => 'normal'],
        ],
        'mediaCount' => 0,
        'mediaNames' => [],
    ],
    'bussnet-empty-playlist.proplaylist' => [
        'name'       => 'EmptyPlaylist',
        'plan'       => [],
        'mediaCount' => 0,
        'mediaNames' => [],
    ],
];

// Coverage floor (rule #34's under-report clause): every committed .proplaylist fixture must
// have a matching entry above, so a new fixture dropped in without updating this test fails
// loudly rather than being silently skipped.
$playlistFixturePaths = glob($fixturesDir . '/*.proplaylist') ?: [];
sort($playlistFixturePaths);
ok('at least 3 committed .proplaylist fixtures exist (found ' . count($playlistFixturePaths) . ')',
    count($playlistFixturePaths) >= 3);
foreach ($playlistFixturePaths as $path) {
    $base = basename($path);
    ok("{$base} has a matching expected-content entry in this test", isset($fixtures[$base]));
}
foreach (array_keys($fixtures) as $base) {
    ok("expected-content entry '{$base}' matches a fixture actually on disk",
        file_exists($fixturesDir . '/' . $base));
}

foreach ($fixtures as $base => $expect) {
    $path  = $fixturesDir . '/' . $base;
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        ok("{$base} is readable", false);
        continue;
    }

    try {
        $bundle = pp7ReadPlaylistBundle($bytes);
    } catch (\Throwable $e) {
        ok("{$base}: pp7ReadPlaylistBundle() succeeds (" . $e->getMessage() . ')', false);
        continue;
    }

    // (a1) Name derivation.
    $name = _bulkImport_proplaylistName($bundle['document'], $base);
    ok("{$base}: derived Name is '{$expect['name']}' (got '{$name}')", $name === $expect['name']);

    // (a2) Media counts match the decoder's own output (sanity — proves the fixture map itself
    // wasn't mistyped before trusting the media-warning assertions in section (b) below).
    ok("{$base}: mediaEntries count matches (" . count($bundle['mediaEntries']) . ' vs ' . $expect['mediaCount'] . ')',
        count($bundle['mediaEntries']) === $expect['mediaCount']);
    ok("{$base}: mediaEntries names match", $bundle['mediaEntries'] === $expect['mediaNames']);

    // (a3) THE mapping — _bulkImport_proplaylistBuildPlan(), the function under test.
    $plan = _bulkImport_proplaylistBuildPlan($bundle['document'], $bundle['proEntries']);
    ok("{$base}: plan has exactly " . count($expect['plan']) . ' entries (got ' . count($plan) . ')',
        count($plan) === count($expect['plan']));

    foreach ($expect['plan'] as $i => $expectedEntry) {
        $actual = $plan[$i] ?? null;
        if ($actual === null) {
            ok("{$base}: plan[{$i}] exists", false);
            continue;
        }
        ok("{$base}: plan[{$i}].kind is '{$expectedEntry['kind']}' (got '" . ($actual['kind'] ?? '?') . "')",
            ($actual['kind'] ?? null) === $expectedEntry['kind']);
        switch ($expectedEntry['kind']) {
            case 'header':
            case 'placeholder':
                ok("{$base}: plan[{$i}].label is '{$expectedEntry['label']}'",
                    ($actual['label'] ?? null) === $expectedEntry['label']);
                break;
            case 'song-embedded':
                ok("{$base}: plan[{$i}].entryName is '{$expectedEntry['entryName']}'",
                    ($actual['entryName'] ?? null) === $expectedEntry['entryName']);
                ok("{$base}: plan[{$i}].itemName is '{$expectedEntry['itemName']}'",
                    ($actual['itemName'] ?? null) === $expectedEntry['itemName']);
                ok("{$base}: plan[{$i}].arrangementName is '{$expectedEntry['arrangementName']}'",
                    ($actual['arrangementName'] ?? null) === $expectedEntry['arrangementName']);
                break;
        }
    }

    // (a4) Convenience "how many of each kind" summary — the task brief's own framing
    // ("2 songs + 1 header + 1 placeholder-handling" / "1 song + 1 header" / "empty").
    $kindCounts = [];
    foreach ($plan as $p) {
        $kindCounts[$p['kind']] = ($kindCounts[$p['kind']] ?? 0) + 1;
    }
    $expectedKindCounts = [];
    foreach ($expect['plan'] as $p) {
        $expectedKindCounts[$p['kind']] = ($expectedKindCounts[$p['kind']] ?? 0) + 1;
    }
    ok("{$base}: kind-count summary matches (" . json_encode($kindCounts) . ' vs ' . json_encode($expectedKindCounts) . ')',
        $kindCounts === $expectedKindCounts);
}

/* ============================================================================================
 * (b) MEDIA-AS-WARNING — the shared _bulkImport_pp7MediaDeferredWarning() helper, on the real
 *     fixtures' own media counts (1 media entry on testplaylist, 0 on the other two).
 * ============================================================================================ */

echo "\n-- (b) media-as-warning (shared with .probundle) --\n";

foreach ($fixtures as $base => $expect) {
    $warning = _bulkImport_pp7MediaDeferredWarning($expect['mediaCount'], $expect['mediaNames']);
    if ($expect['mediaCount'] > 0) {
        ok("{$base}: a media-deferred warning IS produced (mediaCount={$expect['mediaCount']})",
            $warning !== null);
        if ($warning !== null) {
            ok("{$base}: the warning names the count ('{$expect['mediaCount']} media file(s)')",
                str_contains($warning, "{$expect['mediaCount']} media file(s)"));
            foreach ($expect['mediaNames'] as $mn) {
                ok("{$base}: the warning names '{$mn}'", str_contains($warning, $mn));
            }
        }
    } else {
        ok("{$base}: NO media-deferred warning is produced (mediaCount=0)", $warning === null);
    }
}

/* ============================================================================================
 * (c) REFERENCED-NOT-FOUND handling — a presentation item whose documentPath does NOT resolve
 *     to any entry actually in the bundle must become 'song-unresolved', never a crash, never
 *     silently matched to the wrong entry.
 * ============================================================================================ */

echo "\n-- (c) referenced-not-found (song-unresolved) handling --\n";

// (c1) The matcher itself, in isolation — a documentPath naming a file that is genuinely absent
// from the bundle's own .pro entry list.
$missingDocPath = [
    'absoluteString' => 'file:///Library/Application%20Support/RenewedVision/ProPresenter/Songs/Nowhere%20To%20Be%20Found.pro',
    'localRoot'       => null,
    'localPath'       => null,
];
ok('_bulkImport_proplaylistMatchEntry(): a documentPath naming an entry NOT in the bundle returns null',
    _bulkImport_proplaylistMatchEntry($missingDocPath, ['Embedded Song One.pro', 'Embedded Song Two.pro']) === null);

// (c2) The SAME matcher correctly resolves a genuine match with percent-encoded spaces + a
// directory prefix — proves (c1) isn't null merely because the matcher is broken outright.
ok('_bulkImport_proplaylistMatchEntry(): a genuine match with percent-encoded spaces + a directory prefix resolves',
    _bulkImport_proplaylistMatchEntry($missingDocPath, ['Nowhere To Be Found.pro']) === 'Nowhere To Be Found.pro');

// (c3) Case-insensitivity (no real fixture exercises mixed case, but the matcher documents this).
ok('_bulkImport_proplaylistMatchEntry(): matches case-insensitively',
    _bulkImport_proplaylistMatchEntry(['absoluteString' => 'file:///X/SONG.pro', 'localRoot' => null, 'localPath' => null], ['song.pro']) === 'song.pro');

// (c4) The `localPath` fallback fires when `absoluteString` is absent.
ok('_bulkImport_proplaylistMatchEntry(): falls back to localPath when absoluteString is empty',
    _bulkImport_proplaylistMatchEntry(['absoluteString' => '', 'localRoot' => null, 'localPath' => 'Songs/Local Only.pro'], ['Local Only.pro']) === 'Local Only.pro');

// (c5) End-to-end through _bulkImport_proplaylistBuildPlan(): a hand-built document tree (no
// real bundle needed — pp7DecodePlaylist()'s own shape is a plain array, so this is a legitimate
// input, not a re-implementation of the decoder) with ONE presentation item whose documentPath
// does not resolve against the (empty) proEntries list.
$syntheticDocument = [
    'playlists' => [
        [
            'uuid'      => 'test-uuid',
            'name'      => 'Synthetic Service',
            'type'      => 1,
            'playlists' => [],
            'items'     => [
                [
                    'uuid'         => 'item-1',
                    'name'         => 'A Song Not In The Bundle',
                    'isHidden'     => false,
                    'itemType'     => 'presentation',
                    'header'       => null,
                    'presentation' => [
                        'documentPath'    => ['absoluteString' => 'file:///Songs/Missing.pro', 'localRoot' => null, 'localPath' => null],
                        'arrangement'     => null,
                        'arrangementName' => null,
                    ],
                ],
            ],
        ],
    ],
];
$syntheticPlan = _bulkImport_proplaylistBuildPlan($syntheticDocument, []); // empty proEntries -> nothing can match
ok('synthetic tree: a presentation item with no matching .pro entry produces exactly 1 plan entry',
    count($syntheticPlan) === 1);
if (count($syntheticPlan) === 1) {
    ok("synthetic tree: the plan entry's kind is 'song-unresolved' (got '" . ($syntheticPlan[0]['kind'] ?? '?') . "')",
        ($syntheticPlan[0]['kind'] ?? null) === 'song-unresolved');
    ok('synthetic tree: the plan entry preserves the itemName for a later catalogue-title resolve',
        ($syntheticPlan[0]['itemName'] ?? null) === 'A Song Not In The Bundle');
    ok('synthetic tree: the plan entry carries the original documentPath (nothing lost)',
        ($syntheticPlan[0]['documentPath']['absoluteString'] ?? null) === 'file:///Songs/Missing.pro');
}

/* ============================================================================================
 * (d) NESTED PLAYLIST FLATTENING — a synthetic node reached by recursing into a PARENT's own
 *     playlists[] (depth >= 1) gets a synthetic header for its own name; a depth-0 (top-level)
 *     node never does (its name becomes the set list's OWN Name instead — see (a1) above).
 * ============================================================================================ */

echo "\n-- (d) nested playlist flattening --\n";

$nestedDocument = [
    'playlists' => [
        [
            'uuid'      => 'top',
            'name'      => 'Whole Service',   // depth 0 — must NOT synthesize a header for this
            'type'      => 1,
            'items'     => [
                ['uuid' => 'i1', 'name' => 'Welcome', 'isHidden' => false, 'itemType' => 'header', 'header' => ['hasColor' => false, 'actionCount' => 0], 'presentation' => null],
            ],
            'playlists' => [
                [
                    'uuid'      => 'nested',
                    'name'      => 'Worship Set',   // depth 1 — MUST synthesize a header
                    'type'      => 2,
                    'items'     => [
                        ['uuid' => 'i2', 'name' => 'A Song', 'isHidden' => false, 'itemType' => 'placeholder', 'header' => null, 'presentation' => null],
                    ],
                    'playlists' => [],
                ],
            ],
        ],
    ],
];
$nestedFlat = _bulkImport_proplaylistFlattenItems($nestedDocument['playlists'], 0);
// Expected flattened order: [item Welcome] (top's own items first), [synthetic header "Worship
// Set"], [item A Song] (the nested node's items) — see the function's own doc-block for why
// items are emitted before recursing into child playlists.
ok('nested flattening: exactly 3 flat entries (1 top item + 1 synthetic header + 1 nested item)',
    count($nestedFlat) === 3);
if (count($nestedFlat) === 3) {
    ok('nested flattening: entry 0 is the top-level real item, not a synthetic header for "Whole Service"',
        $nestedFlat[0]['kind'] === 'item' && ($nestedFlat[0]['item']['name'] ?? null) === 'Welcome');
    ok('nested flattening: entry 1 is a synthetic header for the NESTED node\'s own name "Worship Set"',
        $nestedFlat[1]['kind'] === 'header' && ($nestedFlat[1]['name'] ?? null) === 'Worship Set');
    ok('nested flattening: entry 2 is the nested node\'s own item',
        $nestedFlat[2]['kind'] === 'item' && ($nestedFlat[2]['item']['name'] ?? null) === 'A Song');
}

/* ============================================================================================
 * (e) NAME FALLBACK LADDER — _bulkImport_proplaylistName().
 * ============================================================================================ */

echo "\n-- (e) Name fallback ladder --\n";

ok('Name ladder: top playlist name wins over the filename',
    _bulkImport_proplaylistName(['playlists' => [['name' => 'Real Name']]], 'upload.proplaylist') === 'Real Name');
ok('Name ladder: an empty/whitespace top playlist name falls through to the filename stem',
    _bulkImport_proplaylistName(['playlists' => [['name' => '   ']]], 'Sunday Service.proplaylist') === 'Sunday Service');
ok('Name ladder: no top playlist at all falls through to the filename stem',
    _bulkImport_proplaylistName(['playlists' => []], 'My Service.proplaylist') === 'My Service');
ok('Name ladder: neither a name nor a filename falls back to the fixed default',
    _bulkImport_proplaylistName(['playlists' => []], null) === 'Imported Playlist');

/* ============================================================================================
 * PART B — DB-reachable only (else SKIP). Full _bulkImport_processProplaylist() under dry-run —
 * see the file-level doc-block for exactly why dry-run (not a rolled-back transaction) is the
 * safe way to exercise the DB-touching orchestration end-to-end anywhere this file might run.
 * ============================================================================================ */

echo "\n-- (f) Part B: full orchestration end-to-end (DB-reachable only, dry-run) --\n";

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
        $hasSongs    = (bool)$db->query("SHOW TABLES LIKE 'tblSongs'")->num_rows;
        $hasSetlists = (bool)$db->query("SHOW TABLES LIKE 'tblUserSetlists'")->num_rows;
    } catch (\Throwable $e) {
        $db = null; $hasSongs = false; $hasSetlists = false;
    }

    if ($db !== null && $hasSongs && $hasSetlists) {
        try {
            /* #1674 dry-run — see file doc-block for why. A fake-but-well-formed
               positive user id is fine: dry-run never reaches the INSERT (no FK
               to tblUsers is ever hit), and _bulkImport_proplaylistMintSetlistId()'s
               own SELECT is read-only regardless of whether that id owns any real
               rows. Reset to false in `finally` so a stale flag can never leak
               into a later test file sharing this PHP process. */
            _bulkImport_dryRun(true);

            $sampleServiceBytes = file_get_contents($fixturesDir . '/bussnet-sample-service.proplaylist');
            $result = _bulkImport_processProplaylist(999999, $sampleServiceBytes, 'bussnet-sample-service.proplaylist');

            ok('Part B: _bulkImport_processProplaylist() returns ok:true', ($result['ok'] ?? false) === true);
            ok('Part B: songs_created + songs_skipped_existing sums to 1 (the one embedded song)',
                (($result['songs_created'] ?? 0) + ($result['songs_skipped_existing'] ?? 0)) === 1);
            ok('Part B: songs_failed is 0', ($result['songs_failed'] ?? -1) === 0);
            ok('Part B: setlists_created is 1 (even under dry-run, per this function\'s own dry-run contract)',
                ($result['setlists_created'] ?? 0) === 1);
            ok('Part B: setlist.name is "Sample Service"',
                ($result['setlist']['name'] ?? null) === 'Sample Service');
            ok('Part B: setlist.songCount is 1', ($result['setlist']['songCount'] ?? -1) === 1);
            ok('Part B: setlist.slotCount is 1 (the "Opening" header slot)',
                ($result['setlist']['slotCount'] ?? -1) === 1);
            ok('Part B: media_present is 0 (sample-service carries no media)',
                ($result['media_present'] ?? -1) === 0);

            // A REAL zero-.pro-entries but non-empty check: the empty-playlist fixture must
            // still produce a clean ok:true result with an EMPTY (but real) set list — Task 1's
            // "empty playlist -> a clean result, never a crash" contract.
            $emptyBytes = file_get_contents($fixturesDir . '/bussnet-empty-playlist.proplaylist');
            $emptyResult = _bulkImport_processProplaylist(999999, $emptyBytes, 'bussnet-empty-playlist.proplaylist');
            ok('Part B (empty playlist): ok:true, never a crash', ($emptyResult['ok'] ?? false) === true);
            ok('Part B (empty playlist): songs_created is 0', ($emptyResult['songs_created'] ?? -1) === 0);
            ok('Part B (empty playlist): setlists_created is 1 (an empty-but-real set list, per Task 1)',
                ($emptyResult['setlists_created'] ?? 0) === 1);
            ok('Part B (empty playlist): setlist.songCount is 0', ($emptyResult['setlist']['songCount'] ?? -1) === 0);

            $behaviouralRan = true;
        } finally {
            _bulkImport_dryRun(false);
        }
    }
}

echo "\n{$passed} passed, {$failed} failed";
echo $behaviouralRan
    ? " — Part B (DB-reachable, dry-run) ran.\n"
    : " — Part B SKIPPED (no reachable database with tblSongs + tblUserSetlists); Part A (DB-free) still ran in full.\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA failure here means the .proplaylist item -> song/header/slot MAPPING core disagrees\n";
    echo "with a real committed .proplaylist fixture's actual content, mishandles a referenced-but-\n";
    echo "not-embedded presentation, mishandles nested playlist flattening, or drops a media entry\n";
    echo "silently instead of reporting it.\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ .proplaylist import assertions passed.\n";
