<?php

declare(strict_types=1);

/**
 * iHymns — TuneName<->TuneId lockstep guard (#1741 P5c)
 *
 * ELI5
 * ----
 * `tblSongs.TuneName` (a free-text display string) and `tblSongs.TuneId`
 * (a link to the `tblTunes` registry row) must ALWAYS be written together.
 * Before P5c, three different places wrote TuneName alone — a curator
 * editing a song's tune, saving the whole song, or bulk-importing one all
 * silently stranded TuneId, de-linking the tune page. This file proves the
 * fix holds: every real SQL statement that writes TuneName also calls the
 * ONE shared find-or-create funnel, the new `song_tune_set` / `tune_search`
 * endpoints actually exist, the `metadata_field_update` `TuneName` branch
 * runs BEFORE the generic column UPDATE (not after — order is the whole
 * point, a branch that runs too late has already let the drift happen),
 * the `tuneName` wire-contract key is still honoured (rule #33 — a stale
 * Service-Worker-cached client may still send it), and `ihymns_meter_normalize()`
 * folds the metre spellings the plan names.
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * ----------------------------------------------------------------------------
 * The write-site sweep scans `appWeb/public_html/**\/*.php` for an actual SQL
 * `INSERT INTO tblSongs (...TuneName...)` / `SET ... TuneName = ...` write —
 * never a hand-typed "these are the writers" list — so a FUTURE file that
 * starts writing TuneName without going through `tuneFindOrCreateByName()`
 * fails THIS test automatically. Same shape as the sibling
 * `tests/php/test-song-external-id-mirror.php` (#1749 P5d) — that file's own
 * doc-block names this one as the family it belongs to.
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely in
 * memory, never touching the tree): each core checking function below is
 * exercised in BOTH directions against small in-memory fixtures before the
 * real assertions run. A guard that has never been proven able to fail is
 * not trustworthy.
 *
 * Pure source-tree scan + a behavioural require of the one pure function
 * (`ihymns_meter_normalize()`, no DB) — no DB connection needed — so it
 * slots into the CI lint step alongside `php -l` and the other guards.
 *
 *   php tests/php/test-tune-lockstep.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see appWeb/public_html/includes/tune_helpers.php tuneFindOrCreateByName(), ihymns_meter_normalize()
 * @see appWeb/public_html/manage/editor/api2.php ed2_songTuneApply(), case 'song_tune_set', case 'tune_search', metadata_field_update's TuneName branch
 * @see appWeb/public_html/manage/editor/save_song_core.php the TuneId lockstep UPDATE beside the places-adoption gate
 * @see appWeb/public_html/includes/song_importers.php the bulk-import TuneId lockstep rider
 * @see appWeb/public_html/manage/works.php:307-313 the original Work-side lockstep this family mirrors
 * @see .claude/catalogue-1741-P5-plan.md §3.8
 */

$repoRoot = dirname(__DIR__, 2);

/* =========================================================================
 * PURE CORE FUNCTIONS — no file I/O, no globals.
 * ========================================================================= */

/**
 * Slice a named top-level PHP `function NAME(...) { ... }` region — from
 * `function NAME(` to the next top-level (column-0) `function ` declaration,
 * or end of file. Same shape as test-song-external-id-mirror.php's
 * seimSliceFunction() (this file's own prefix: `ttl`).
 */
function ttlSliceFunction(string $src, string $name): string
{
    if (!preg_match(
        '/^function\s+' . preg_quote($name, '/') . '\s*\(.*?(?=\n^function\s|\z)/ms',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Slice a `case 'NAME':` region out of a PHP switch statement — from the
 * `case` line to the next top-level `case '` occurrence (or end of file).
 */
function ttlSliceCase(string $src, string $caseName): string
{
    if (!preg_match(
        '/case\s+\'' . preg_quote($caseName, '/') . '\'\s*:.*?(?=\n\s*case\s+\')/s',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Strip PHP comments (`//`, `#`, and `/* *​/` / doc-block) from source via the
 * TOKENIZER, so a CODE assertion below can never be satisfied by a mention of
 * the symbol inside a comment — the wrong-but-green trap (rule #34). This is
 * load-bearing here: this file's own inline annotations DELIBERATELY name
 * `ed2_songTuneApply()` / `tuneFindOrCreateByName()` right beside the calls
 * they document, so a raw `strpos` on a function slice stays green even after
 * the real call is deleted (proven by hand before this helper was added).
 * Same tokenizer approach as `tests/php/test-song-external-id-mirror.php`.
 * `T_COMMENT` / `T_DOC_COMMENT` tokens are replaced by their own newline count
 * so relative offsets (§4's order check) stay faithful to the source.
 *
 * @link https://www.php.net/manual/en/function.token-get-all.php
 */
function ttlStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/**
 * DERIVED write-site sweep: scan every `.php` file under $dir (recursive,
 * skipping any `appWeb/.sql` subtree — one-shot migration scripts are exempt
 * by construction, same carve-out as test-song-external-id-mirror.php) for
 * an SQL statement that WRITES `tblSongs.TuneName` — an
 * `INSERT INTO tblSongs (...)` whose statement carries a bare `TuneName`, or
 * a `SET ... TuneName = ...`. Bounded windows (rule #34's #1676 lesson:
 * never "no `>`", always a generous bounded window) rather than a single
 * greedy match across the whole file.
 *
 * @return list<string> paths (relative to $dir, '/'-separated) containing
 *                        at least one matching write statement
 */
function ttlFindTuneNameWriteSites(string $dir): array
{
    $hits = [];
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        $path = $file->getPathname();
        /* One-shot migration scripts live under appWeb/.sql — exempt by
           construction (they run once, by hand, from the CLI; the live
           write paths this guard cares about are all under public_html). */
        if (strpos($path, DIRECTORY_SEPARATOR . '.sql' . DIRECTORY_SEPARATOR) !== false) { continue; }
        /* Comment-stripped so a commented-out SQL write (or a `TuneName`
           mentioned only in prose) can neither be counted as a write site nor
           slip past the funnel-reference check below (rule #34). */
        $src  = ttlStripComments((string)file_get_contents($path));
        $isWrite =
            preg_match('/INSERT\s+INTO\s+tblSongs\b[^;]{0,600}\bTuneName\b/is', $src) === 1
            || preg_match('/SET\b[^;]{0,300}\bTuneName\s*=/is', $src) === 1
            /* A DYNAMIC column-list INSERT (song_importers.php's own shape,
               rule #34's "under-reporting is worse than no scanner"
               lesson): the column list is a PHP array built SEPARATELY from
               the SQL text and imploded into it, so 'TuneName' and
               "INSERT INTO tblSongs" never sit inside one short window in
               SOURCE ORDER — the two windowed checks above miss it. Caught
               instead by CO-OCCURRENCE across the whole file: a quoted
               'TuneName' array element, an `implode(` call, and a literal
               "INSERT INTO tblSongs" string all present together. */
            || (
                preg_match('/INSERT\s+INTO\s+tblSongs\b/is', $src) === 1
                && preg_match('/\'TuneName\'/', $src) === 1
                && preg_match('/\bimplode\s*\(/', $src) === 1
            );
        if ($isWrite) {
            $rel = str_replace($dir . DIRECTORY_SEPARATOR, '', $path);
            $hits[] = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        }
    }
    sort($hits);
    return $hits;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory.
 * ========================================================================= */

$mutationFailures = [];

/* --- ttlSliceFunction() --- */
$fixtureFnSrc = "function alpha(): array\n{\n    \$cols = ['NeedleInAlpha'];\n    return \$cols;\n}\n\nfunction beta(): array\n{\n    \$cols = ['NeedleInBeta'];\n    return \$cols;\n}\n";
$alphaFnSlice = ttlSliceFunction($fixtureFnSrc, 'alpha');
if (strpos($alphaFnSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'ttlSliceFunction() FAILS-HIGH self-test did not find the needle inside its own fixture function';
}
if (strpos($alphaFnSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "ttlSliceFunction() FAILS-LOW self-test: alpha()'s slice bled into beta()";
}

/* --- ttlSliceCase() --- */
$fixtureSwitchSrc = "switch (\$action) {\n    case 'alpha': {\n        \$x = 'NeedleInAlpha';\n        break;\n    }\n    case 'beta': {\n        \$x = 'NeedleInBeta';\n        break;\n    }\n}\n";
$alphaCaseSlice = ttlSliceCase($fixtureSwitchSrc, 'alpha');
if (strpos($alphaCaseSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'ttlSliceCase() FAILS-HIGH self-test did not find the needle inside its own fixture case';
}
if (strpos($alphaCaseSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "ttlSliceCase() FAILS-LOW self-test: 'alpha' case slice bled into 'beta' case";
}

/* --- ttlStripComments() --- */
$stripFixture = "<?php\n// NeedleInLineComment\n\$x = 'NeedleInCode';\n# NeedleInHashComment\n/* NeedleInBlockComment */\n/** NeedleInDocComment */\n\$y = \"AlsoCode\";\n";
$stripped = ttlStripComments($stripFixture);
foreach (['NeedleInCode', 'AlsoCode'] as $codeNeedle) {
    if (strpos($stripped, $codeNeedle) === false) {
        $mutationFailures[] = "ttlStripComments() FAILS-HIGH self-test removed the code string '{$codeNeedle}'";
    }
}
foreach (['NeedleInLineComment', 'NeedleInHashComment', 'NeedleInBlockComment', 'NeedleInDocComment'] as $commentNeedle) {
    if (strpos($stripped, $commentNeedle) !== false) {
        $mutationFailures[] = "ttlStripComments() FAILS-LOW self-test kept the comment string '{$commentNeedle}'";
    }
}

/* --- ttlFindTuneNameWriteSites() --- */
$fixtureDir = sys_get_temp_dir() . '/ttl_fixture_' . bin2hex(random_bytes(6));
mkdir($fixtureDir, 0777, true);
mkdir($fixtureDir . '/sub', 0777, true);
mkdir($fixtureDir . '/.sql', 0777, true);
file_put_contents($fixtureDir . '/writer.php', "<?php\n\$db->query(\"UPDATE tblSongs SET TuneName = ? WHERE SongId = ?\");\n");
file_put_contents($fixtureDir . '/sub/inserter.php', "<?php\n\$db->prepare('INSERT INTO tblSongs (SongId, TuneName) VALUES (?, ?)');\n");
file_put_contents($fixtureDir . '/reader.php', "<?php\n\$db->prepare('SELECT TuneName FROM tblSongs WHERE SongId = ?');\n");
file_put_contents($fixtureDir . '/unrelated.php', "<?php\necho 'nothing to see here';\n");
file_put_contents($fixtureDir . '/.sql/migrate-fixture.php', "<?php\n\$db->query(\"UPDATE tblSongs SET TuneName = ? WHERE SongId = ?\");\n");
/* The song_importers.php SHAPE: a column-list array (holding 'TuneName')
   built separately from the SQL text, then imploded into it — 'TuneName'
   never sits inside a short window of "INSERT INTO tblSongs" in SOURCE
   ORDER, so this fixture only goes green if the CO-OCCURRENCE branch works. */
file_put_contents(
    $fixtureDir . '/dynamic-cols.php',
    "<?php\n\$cols = ['SongId', 'TuneName', 'Ccli'];\n\$ph = implode(', ', array_fill(0, count(\$cols), '?'));\n"
    . "\$db->prepare('INSERT INTO tblSongs (' . implode(', ', \$cols) . ') VALUES (' . \$ph . ')');\n"
);
/* A file with `implode(` and "INSERT INTO tblSongs" but NO 'TuneName' at
   all must NOT be flagged — proves the co-occurrence branch isn't just
   "any dynamic INSERT into tblSongs". */
file_put_contents(
    $fixtureDir . '/dynamic-cols-no-tune.php',
    "<?php\n\$cols = ['SongId', 'Ccli'];\n\$db->prepare('INSERT INTO tblSongs (' . implode(', ', \$cols) . ') VALUES (?, ?)');\n"
);
$fixtureHits = ttlFindTuneNameWriteSites($fixtureDir);
if (!in_array('writer.php', $fixtureHits, true)) {
    $mutationFailures[] = 'ttlFindTuneNameWriteSites() FAILS-HIGH self-test did not find the SET-writing fixture file';
}
if (!in_array('sub/inserter.php', $fixtureHits, true)) {
    $mutationFailures[] = 'ttlFindTuneNameWriteSites() FAILS-HIGH self-test did not find the INSERT-writing fixture file (recursion into a subdirectory)';
}
if (!in_array('dynamic-cols.php', $fixtureHits, true)) {
    $mutationFailures[] = 'ttlFindTuneNameWriteSites() FAILS-HIGH self-test did not find the DYNAMIC column-list fixture (the song_importers.php shape — TuneName and "INSERT INTO tblSongs" never share a short window)';
}
if (in_array('reader.php', $fixtureHits, true)) {
    $mutationFailures[] = 'ttlFindTuneNameWriteSites() FAILS-LOW self-test wrongly flagged a SELECT-only fixture file as a write site';
}
if (in_array('unrelated.php', $fixtureHits, true)) {
    $mutationFailures[] = 'ttlFindTuneNameWriteSites() FAILS-LOW self-test wrongly flagged an unrelated fixture file';
}
if (in_array('.sql/migrate-fixture.php', $fixtureHits, true)) {
    $mutationFailures[] = 'ttlFindTuneNameWriteSites() FAILS-LOW self-test wrongly flagged a fixture under a .sql/ migration tree (should be exempt by construction)';
}
if (in_array('dynamic-cols-no-tune.php', $fixtureHits, true)) {
    $mutationFailures[] = 'ttlFindTuneNameWriteSites() FAILS-LOW self-test wrongly flagged a dynamic tblSongs INSERT that never mentions TuneName at all';
}
/* Clean up the fixture tree. */
@unlink($fixtureDir . '/writer.php');
@unlink($fixtureDir . '/sub/inserter.php');
@unlink($fixtureDir . '/reader.php');
@unlink($fixtureDir . '/unrelated.php');
@unlink($fixtureDir . '/.sql/migrate-fixture.php');
@unlink($fixtureDir . '/dynamic-cols.php');
@unlink($fixtureDir . '/dynamic-cols-no-tune.php');
@rmdir($fixtureDir . '/sub');
@rmdir($fixtureDir . '/.sql');
@rmdir($fixtureDir);

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

$failures = [];

$tuneHelpersFile   = $repoRoot . '/appWeb/public_html/includes/tune_helpers.php';
$api2File          = $repoRoot . '/appWeb/public_html/manage/editor/api2.php';
$publicHtmlDir     = $repoRoot . '/appWeb/public_html';

foreach ([
    'includes/tune_helpers.php'      => $tuneHelpersFile,
    'manage/editor/api2.php'         => $api2File,
] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read {$label} at {$path}\n");
        exit(1);
    }
}

/* Comment-stripped ONCE here: EVERY code assertion below (§2-§6) slices or
   regex-scans this string, and this file's own annotations name the very
   symbols those assertions look for right beside the calls — so a raw scan
   is wrong-but-green the moment a call is deleted but its comment stays
   (proven by hand; see ttlStripComments()'s doc-block, rule #34). */
$api2Src = ttlStripComments((string)file_get_contents($api2File));

/* ---- 1. Behavioural: ihymns_meter_normalize() (§3.5's named cases). ---- */
require_once $tuneHelpersFile;
if (!function_exists('ihymns_meter_normalize')) {
    $failures[] = 'includes/tune_helpers.php does not declare function ihymns_meter_normalize()';
} else {
    $meterCases = [
        'CM'       => '86.86',
        '87 87 D'  => '87.87D',
        '8787D'    => '87.87D',
        ''         => '',
        'LMD'      => '88.88D',
    ];
    foreach ($meterCases as $in => $expected) {
        $got = ihymns_meter_normalize($in);
        if ($got !== $expected) {
            $failures[] = "ihymns_meter_normalize(" . var_export($in, true) . ") returned " . var_export($got, true) . ", expected " . var_export($expected, true);
        }
    }
}

/* ---- 2. api2.php declares the two new actions. ---- */
if (!preg_match('/case\s+\'song_tune_set\'\s*:/', $api2Src)) {
    $failures[] = "api2.php has no `case 'song_tune_set':`";
}
if (!preg_match('/case\s+\'tune_search\'\s*:/', $api2Src)) {
    $failures[] = "api2.php has no `case 'tune_search':`";
}

/* ---- 3. api2.php declares the shared write core + the two existence probes. ---- */
$applyFnSlice = ttlSliceFunction($api2Src, 'ed2_songTuneApply');
if ($applyFnSlice === '') {
    $failures[] = 'api2.php does not declare function ed2_songTuneApply() — the ONE tune write core the plan requires';
} else {
    if (strpos($applyFnSlice, 'tuneFindOrCreateByName') === false) {
        $failures[] = 'ed2_songTuneApply() does not call tuneFindOrCreateByName() — a second lookup fork, or no lookup at all (rule #22)';
    }
}

/* ---- 4. metadata_field_update's TuneName branch runs BEFORE the generic
   `UPDATE tblSongs SET` line, references ed2_songTuneApply(), and 'tuneName'
   is STILL a key of ED2_META_FIELDS (rule #33 — the wire contract must not
   be silently dropped for a stale client). Order matters: a branch added
   AFTER the generic UPDATE has already let the drift happen by the time it
   runs, so this is a POSITION assertion, not just a presence one. ---- */
$mfuSlice = ttlSliceCase($api2Src, 'metadata_field_update');
if ($mfuSlice === '') {
    $failures[] = "Could not locate case 'metadata_field_update': in api2.php";
} else {
    $tuneBranchPos   = strpos($mfuSlice, "column === 'TuneName'");
    $genericTuneWrite = null;
    if (preg_match('/UPDATE\s+tblSongs\s+SET\s+`\{\$column\}`/', $mfuSlice, $_m, PREG_OFFSET_CAPTURE)) {
        $genericTuneWrite = $_m[0][1];
    }
    if ($tuneBranchPos === false) {
        $failures[] = "case 'metadata_field_update' in api2.php has no `\$column === 'TuneName'` branch — TuneName can still fall through to the generic UPDATE";
    } elseif ($genericTuneWrite !== null && $tuneBranchPos > $genericTuneWrite) {
        $failures[] = "case 'metadata_field_update'\'s TuneName branch runs AFTER the generic \"UPDATE tblSongs SET\" line — too late to intercept the write";
    }
    if (strpos($mfuSlice, 'ed2_songTuneApply') === false) {
        $failures[] = "case 'metadata_field_update' in api2.php does not call ed2_songTuneApply() for its TuneName branch";
    }
}
if (!preg_match('/\'tuneName\'\s*=>\s*\[\s*\'TuneName\'/', $api2Src)) {
    $failures[] = "api2.php's ED2_META_FIELDS no longer has a 'tuneName' => ['TuneName', ...] entry — a stale Service-Worker-cached client's metadata_field_update writes would now 400 (rule #33)";
}

/* ---- 5. DERIVED write-site sweep: every file that writes tblSongs.TuneName
   must reference tuneFindOrCreateByName(). No exemption list — unlike the
   ISRC mirror (#1749), which has one deliberately-deferred funnel
   (lyrics_ingest.php), P5c's own build spec (§3.4 item 2) requires EVERY
   in-scope TuneName writer to be wired or have its rider explicitly
   dropped with a follow-up issue — and the P5c implementation wired all of
   them (works.php, save_song_core.php, api2.php, song_importers.php), so
   this sweep has NO exempt list at all: a future unwired writer is a
   straight failure, not a candidate for a quiet carve-out. ---- */
$writeSites = ttlFindTuneNameWriteSites($publicHtmlDir);
if (count($writeSites) < 3) {
    $failures[] = 'ttlFindTuneNameWriteSites() found suspiciously few TuneName write sites (' . count($writeSites) . ') — the sweep itself may be broken';
}
foreach ($writeSites as $rel) {
    $fileSrc = ttlStripComments((string)file_get_contents($publicHtmlDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel)));
    if (strpos($fileSrc, 'tuneFindOrCreateByName') === false) {
        $failures[] = "appWeb/public_html/{$rel} writes tblSongs.TuneName but never references tuneFindOrCreateByName() — TuneId can be stranded (#1741 P5c drift)";
    }
}

/* ---- 6. The revision-restore path (ed2_applySongSnapshot) funnels TuneName
   through the ONE write core, NOT the generic scalar loop. The §5 file-level
   sweep CANNOT catch this: api2.php already references tuneFindOrCreateByName()
   (in ed2_songTuneApply), so the file passes the sweep no matter what
   ed2_applySongSnapshot() does — the restore path writes TuneName via the
   dynamic `SET \`{$column}\`` UPDATE, which never carries the literal string
   'TuneName' for the sweep to see. This is exactly the intra-file blind spot
   rule #34 warns about ("a scanner that under-reports is worse than no
   scanner"). So assert the restore path SPECIFICALLY, from its own function
   slice: (a) it delegates to ed2_songTuneApply() (the lockstep core), and
   (b) it has an explicit `$column === 'TuneName'` capture/skip so the generic
   scalar loop can never write TuneName alone. Both are mutation-provable:
   delete the funnel call -> (a) red; delete the skip and let the loop write
   TuneName -> (b) red. ---- */
$applySnapSlice = ttlSliceFunction($api2Src, 'ed2_applySongSnapshot');
if ($applySnapSlice === '') {
    $failures[] = 'api2.php does not declare function ed2_applySongSnapshot() — cannot verify the revision-restore tune lockstep (#1741 P5c)';
} else {
    if (strpos($applySnapSlice, 'ed2_songTuneApply') === false) {
        $failures[] = "ed2_applySongSnapshot() does not call ed2_songTuneApply() — a revision restore writes TuneName via the scalar loop without a companion TuneId write, stranding the tune link (#1741 P5c restore-path drift)";
    }
    if (strpos($applySnapSlice, "column === 'TuneName'") === false) {
        $failures[] = "ed2_applySongSnapshot() has no `\$column === 'TuneName'` capture/skip — TuneName can still fall through the generic scalar UPDATE and strand TuneId on restore (#1741 P5c)";
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: TuneName<->TuneId lockstep guard (#1741 P5c):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    exit(1);
}

echo "PASS: TuneName<->TuneId lockstep guard — ihymns_meter_normalize() folds every named case; "
   . "api2.php declares song_tune_set + tune_search + ed2_songTuneApply() (which calls "
   . "tuneFindOrCreateByName()); metadata_field_update's TuneName branch runs before the generic "
   . "UPDATE and 'tuneName' is still a live ED2_META_FIELDS key; " . count($writeSites)
   . " derived tblSongs.TuneName write site(s) found (" . implode(', ', $writeSites) . "), every "
   . "one referencing tuneFindOrCreateByName(); ed2_applySongSnapshot() funnels the restore path "
   . "through ed2_songTuneApply() (no scalar-loop TuneName strand); all mutation self-tests went red "
   . "as expected.\n";
exit(0);
