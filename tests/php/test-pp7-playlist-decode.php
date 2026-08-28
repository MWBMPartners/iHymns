<?php

declare(strict_types=1);

/**
 * iHymns — `.proplaylist` decoder cross-validation + field-table lockstep guard (#1968 P3 foundation)
 * =====================================================================================================
 *
 * ELI5
 * ----
 * Sibling to `tests/php/test-pp7-decode.php` (which does this for plain `.pro` files), but for
 * `.proplaylist` containers: two completely different pieces of code both read the same real
 * playlist files — the hand-written PHP wire-walker (`includes/propresenter7_playlist.php`) and a
 * Node script driven by Google's own protobuf reflection library
 * (`tools/pp7-gen-playlist-expected.js`, whose output is committed at
 * `tests/fixtures/propresenter/expected/*.playlist.json`). This file's first job: do they agree,
 * on every real fixture? Its second job, mirroring `test-pp7-decode.php`'s own: does every field
 * number cited in `propresenter7_playlist.php`'s constant tables still match the exact line of
 * the vendored `.proto` schema it claims to cite?
 *
 * NEITHER job needs a database — both the decoder and this test are pure functions of bytes on
 * disk (mirrors `song_similarity.php`'s "framework-free" posture, and `test-pp7-decode.php`'s own).
 *
 * MUTATION-PROVEN (rule #34) — performed, by hand, at authoring time, then reverted (a pristine
 * `git diff` confirmed byte-identical afterwards each time):
 *   - m1: In `includes/propresenter7_playlist.php`'s `PP7_FIELDS_PLAYLIST` table, changed
 *     `'items' => 13,` to `'items' => 14,` (the citation comment `// playlist.proto:41` left
 *     untouched — only the decode-affecting VALUE changed). RESULT: RED — section (a) failed on
 *     all three fixtures (every playlist's `items` array came back empty, since field 13 no
 *     longer matched anything on the wire) AND section (b) failed independently ("PP7_FIELDS_
 *     PLAYLIST.items => 14 but playlist.proto:41 declares 'items' = 13" — the lockstep guard
 *     catching the SAME mutation from the citation side). Reverted -> both green again.
 *   - m2: Separately (lockstep-only): edited ONE trailing citation comment
 *     (`PP7_FIELDS_PLAYLIST_ITEM`'s `'placeholder'` entry) from `playlist.proto:88` to
 *     `playlist.proto:87` (a real line in the file, `rv.data.PlaylistItem.PlanningCenter
 *     planning_center = 6;`) without touching the array's own value. RESULT: section (a) stayed
 *     GREEN (decode behaviour untouched — the constant's VALUE didn't change) while section (b)
 *     went RED ("playlist.proto:87 declares 'planning_center' = 6, but PP7_FIELDS_PLAYLIST_ITEM
 *     cites it for 'placeholder' => 8" — a citation drifting from its own value with nothing else
 *     catching it). Reverted -> green.
 *   - m3 (ZIP-listing cross-check): in `pp7ReadPlaylistBundle()`, changed the `.pro`-suffix test
 *     from `strtolower(substr($name, -4)) === '.pro'` to `... === '.xxx'` (no real entry ends
 *     `.xxx`), so `proEntries` came back empty for every fixture that has one. RESULT: RED — but
 *     NOT via the predicted path. The "every reported .pro entry exists in an independent ZIP
 *     listing" assertion itself stayed GREEN on all three fixtures (checking membership over an
 *     EMPTY list is vacuously true — there was nothing left to find missing, which is itself worth
 *     recording rather than silently re-guessing a cleaner story). What actually caught it: (1)
 *     the main document-shape diff, because `$actual`'s top-level `proEntries` key IS compared
 *     against `$expected` alongside `document` (not excluded, as an earlier draft of this note
 *     incorrectly assumed before the mutation was actually run — corrected here per the "record
 *     the ACTUAL observed message, not the guessed one" convention `test-pp7-zip.php` already
 *     established) — `[keys differ: [] vs [0]]` / `[0,1]` on the two fixtures with `.pro` entries;
 *     and (2) the hand-derived `.pro entry count` assertion, independently. `bussnet-empty-
 *     playlist` (which has zero `.pro` entries to begin with) correctly stayed green throughout —
 *     the mutation has nothing to break there. Net: 4 assertions across 2 fixtures went red, the
 *     vacuous-membership assertion did NOT catch it alone, and the mutation was still caught
 *     twice over by assertions that do NOT depend on membership-over-a-list. Reverted -> green,
 *     46/46 passed, `diff` against the pristine file confirmed byte-identical.
 *
 * Usage:
 *   php tests/php/test-pp7-playlist-decode.php
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see .claude/propresenter-interop-1968-plan.md   §5 (the `.proplaylist` design), §11.3 (the lockstep-guard mechanism)
 * @see includes/propresenter7_playlist.php          the decoder under test
 * @see tools/pp7-gen-playlist-expected.js            generates tests/fixtures/propresenter/expected/*.playlist.json
 * @see tests/php/test-pp7-decode.php                 the sibling test this one's shape mirrors (for `.pro`, not `.proplaylist`)
 * @see tests/fixtures/propresenter/README.md         fixture provenance + licensing
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_playlist.php';

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

/**
 * Find the first point at which two (already-decoded) values disagree, as a human-readable
 * dotted/bracketed path — mirrors `test-pp7-decode.php`'s `pp7FirstDiffPath()` (a small helper,
 * deliberately re-defined locally rather than shared, since every `tests/php/*.php` file runs in
 * its own isolated process — see `tools/run-php-tests.php`'s own doc-block on why).
 */
function pp7PlaylistFirstDiffPath($a, $b, string $path = '$'): ?string
{
    if (is_array($a) && is_array($b)) {
        $keysA = array_keys($a);
        $keysB = array_keys($b);
        if ($keysA !== $keysB) {
            return "{$path} (keys differ: [" . implode(',', $keysA) . '] vs [' . implode(',', $keysB) . '])';
        }
        foreach ($a as $k => $v) {
            $sub = is_int($k) ? "{$path}[{$k}]" : "{$path}.{$k}";
            $diff = pp7PlaylistFirstDiffPath($v, $b[$k], $sub);
            if ($diff !== null) {
                return $diff;
            }
        }
        return null;
    }
    if ($a === $b) {
        return null;
    }
    $av = is_string($a) && strlen($a) > 120 ? substr($a, 0, 120) . '…' : var_export($a, true);
    $bv = is_string($b) && strlen($b) > 120 ? substr($b, 0, 120) . '…' : var_export($b, true);
    return "{$path} (got {$av}, expected {$bv})";
}

echo "\n#1968 P3 — ProPresenter 7+ .proplaylist decoder cross-validation + field-table lockstep\n\n";

/* ============================================================================================
 * (a) DECODE CROSS-VALIDATION — the PHP walker vs. protobufjs, on real third-party files
 * ============================================================================================ */

echo "-- (a) decoder cross-validation against protobufjs-derived expected output --\n";

$fixturesDir = dirname(__DIR__, 2) . '/tests/fixtures/propresenter';
$expectedDir = $fixturesDir . '/expected';

$playlistFixtures = glob($fixturesDir . '/*.proplaylist') ?: [];
sort($playlistFixtures);

// Coverage floor (rule #34's under-report clause): guards against the glob silently matching
// fewer files than the corpus actually has. All three committed real fixtures
// (bussnet-{testplaylist,empty-playlist,sample-service}) must be present.
ok('at least 3 committed .proplaylist fixtures exist to cross-validate against (found ' . count($playlistFixtures) . ')',
    count($playlistFixtures) >= 3);

// Independent "known real inventory" table, derived by hand-inspecting each fixture during this
// task (via both the PHP decoder AND protobufjs directly — see the task report) — NOT copied
// from the decoder's own output, so a decoder bug that agrees with itself cannot pass this row.
// Keyed by fixture basename (without extension).
$knownInventory = [
    'bussnet-testplaylist'    => ['playlists' => 1, 'items' => 4, 'itemTypes' => ['header', 'presentation', 'placeholder', 'presentation'], 'proCount' => 2],
    'bussnet-empty-playlist'  => ['playlists' => 1, 'items' => 0, 'itemTypes' => [], 'proCount' => 0],
    'bussnet-sample-service'  => ['playlists' => 1, 'items' => 2, 'itemTypes' => ['header', 'presentation'], 'proCount' => 1],
];

foreach ($playlistFixtures as $plPath) {
    $base = basename($plPath, '.proplaylist');
    $expectedPath = $expectedDir . '/' . $base . '.playlist.json';

    // A fixture without a matching expected file is a coverage gap, not a skip — fail loudly
    // rather than silently decoding nothing for it (rule #34: "a fixture without one FAILS").
    if (!is_file($expectedPath)) {
        ok("{$base}.proplaylist has a matching expected/{$base}.playlist.json", false);
        continue;
    }

    $bytes = file_get_contents($plPath);
    if ($bytes === false) {
        ok("{$base}.proplaylist is readable", false);
        continue;
    }

    $expectedRaw = file_get_contents($expectedPath);
    $expected = $expectedRaw !== false ? json_decode($expectedRaw, true) : null;
    if (!is_array($expected)) {
        ok("{$base}.playlist.json parses as JSON", false);
        continue;
    }

    try {
        $actual = pp7ReadPlaylistBundle($bytes);
    } catch (\Throwable $e) {
        ok("{$base}.proplaylist decodes without throwing (" . $e->getMessage() . ')', false);
        continue;
    }

    $diff = pp7PlaylistFirstDiffPath($actual, $expected);
    ok("{$base}.proplaylist: PHP decoder matches protobufjs-derived expected output"
        . ($diff !== null ? " [first diff at {$diff}]" : ''),
        $diff === null);

    // Cross-check against the HAND-DERIVED inventory (independent of both decoders) — proves the
    // fixtures really do contain what this task's report claims, not just that the two decoders
    // agree with EACH OTHER (which two buggy-in-the-same-way decoders could still do).
    if (isset($knownInventory[$base])) {
        $inv = $knownInventory[$base];
        $flatItems = [];
        foreach ($actual['document']['playlists'] as $pl) {
            foreach ($pl['items'] as $it) {
                $flatItems[] = $it['itemType'];
            }
        }
        ok("{$base}: top-level playlist count matches hand-derived inventory ({$inv['playlists']})",
            count($actual['document']['playlists']) === $inv['playlists']);
        ok("{$base}: total item count matches hand-derived inventory ({$inv['items']})",
            count($flatItems) === $inv['items']);
        ok("{$base}: item TYPES in order match hand-derived inventory (" . implode(',', $inv['itemTypes']) . ')',
            $flatItems === $inv['itemTypes']);
        ok("{$base}: .pro entry count matches hand-derived inventory ({$inv['proCount']})",
            count($actual['proEntries']) === $inv['proCount']);
    }

    // Every `.pro` entry name pp7ReadPlaylistBundle() reports MUST actually exist as a real ZIP
    // entry — cross-checked against an INDEPENDENT pp7ZipListEntries() call (not the same call
    // pp7ReadPlaylistBundle() made internally), so a future refactor that lets proEntries drift
    // from the ZIP's real contents is caught here (mutation m3 in this file's doc-block proves
    // this assertion is load-bearing, not a tautology).
    try {
        $independentEntries = pp7ZipListEntries($bytes);
        $independentNames = array_column($independentEntries, 'name');
    } catch (\Throwable $e) {
        $independentNames = null;
    }
    if ($independentNames === null) {
        ok("{$base}: independent pp7ZipListEntries() call succeeds for the .pro-entry cross-check", false);
    } else {
        $allPresent = true;
        foreach ($actual['proEntries'] as $proName) {
            if (!in_array($proName, $independentNames, true)) {
                $allPresent = false;
                break;
            }
        }
        ok("{$base}: every reported .pro entry (" . implode(', ', $actual['proEntries']) . ') exists in an independent ZIP listing',
            $allPresent);
    }
}

/* ============================================================================================
 * (b) FIELD-TABLE LOCKSTEP — every cited field number still matches the vendored .proto source
 * ============================================================================================ */

echo "\n-- (b) decoder field-table <-> vendored .proto lockstep --\n";

$decoderPath = dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_playlist.php';
$decoderSrc = file_get_contents($decoderPath);
if ($decoderSrc === false) {
    ok('propresenter7_playlist.php is readable', false);
} else {
    // Tree-derived (rule #34): the SET of tables + entries to check comes from parsing the
    // decoder's own source, never from a hand-typed list in this test. Mirrors
    // test-pp7-decode.php's identical mechanism verbatim (same regex shape, same convention:
    // 'field_name' => NUMBER, // file.proto:LINE).
    preg_match_all('/define\(\'([A-Z0-9_]+)\',\s*\[(.*?)\]\);/s', $decoderSrc, $tables, PREG_SET_ORDER);

    ok('at least 6 field-number tables were found in propresenter7_playlist.php (found ' . count($tables) . ')',
        count($tables) >= 6);

    $protoDir = dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/protos/proto-7.16';
    $protoFileCache = [];
    $totalEntries = 0;
    $skippedNoCitation = 0;

    foreach ($tables as [, $tableName, $body]) {
        // Every entry line, cited or not — used to prove the deliberately-uncited
        // arrangement_name entry is still PRESENT in the table (just not lockstep-checked),
        // rather than silently absent for an unrelated reason.
        preg_match_all("/'([A-Za-z0-9_]+)'\\s*=>\\s*(\\d+),/", $body, $allEntries, PREG_SET_ORDER);

        preg_match_all(
            "/'([A-Za-z0-9_]+)'\\s*=>\\s*(\\d+),\\s*\\/\\/\\s*([A-Za-z0-9_]+\\.proto):(\\d+)/",
            $body,
            $entries,
            PREG_SET_ORDER
        );
        $skippedNoCitation += (count($allEntries) - count($entries));

        foreach ($entries as [, $name, $number, $protoFile, $lineNo]) {
            $totalEntries++;
            $number = (int)$number;
            $lineNo = (int)$lineNo;

            if (!isset($protoFileCache[$protoFile])) {
                $path = $protoDir . '/' . $protoFile;
                $protoFileCache[$protoFile] = is_file($path)
                    ? (file($path, FILE_IGNORE_NEW_LINES) ?: [])
                    : null;
            }
            $lines = $protoFileCache[$protoFile];

            if ($lines === null) {
                ok("{$tableName}.{$name}: cited proto file {$protoFile} exists under protos/proto-7.16/", false);
                continue;
            }
            if (!isset($lines[$lineNo - 1])) {
                ok("{$tableName}.{$name}: {$protoFile}:{$lineNo} exists (file has " . count($lines) . ' lines)', false);
                continue;
            }

            $protoLine = $lines[$lineNo - 1];
            if (!preg_match('/(\w+)\s*=\s*(\d+)\s*;/', $protoLine, $pm)) {
                ok("{$tableName}.{$name}: {$protoFile}:{$lineNo} contains a '<name> = <number>;' declaration (got: "
                    . trim($protoLine) . ')', false);
                continue;
            }
            $protoName = $pm[1];
            $protoNumber = (int)$pm[2];

            ok("{$tableName}.{$name} => {$number} matches {$protoFile}:{$lineNo} ({$protoName} = {$protoNumber})",
                $protoName === $name && $protoNumber === $number);
        }
    }

    ok('at least 20 field entries were lockstep-checked against the vendored .proto source (checked ' . $totalEntries . ')',
        $totalEntries >= 20);

    // Was exactly ONE deliberately-uncited entry (arrangement_name, field 5 — see this decoder's
    // file doc-block "UNCONFIRMED corner #4") from P3-IMPORT until #1968 P3-EXPORT added the
    // field to the vendored playlist.proto (line 116) so the EXPORT-side encoder could emit it —
    // at which point `arrangement_name`'s table entry picked up a real citation like every other
    // field, retiring the exception rather than merely moving it. The floor is therefore 0 now.
    // If this ever rises above 0, either a NEW deliberate exception was added without updating
    // this comment, or an existing citation was accidentally dropped (a genuine lockstep miss the
    // section (b) per-entry checks above did not already catch some other way).
    ok('every field-table entry now carries a real .proto citation (0 deliberately uncited; found ' . $skippedNoCitation . ')',
        $skippedNoCitation === 0);
}

/* ============================================================================================ */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA decoder cross-validation failure means the PHP walker and protobufjs (an\n";
    echo "independent implementation) disagree on a REAL third-party ProPresenter playlist file —\n";
    echo "the owner's #1 rule for this epic is that this must never ship. A lockstep failure means\n";
    echo "a field-number table has drifted from the vendored .proto schema it claims to mirror.\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ .proplaylist decoder assertions passed.\n";
