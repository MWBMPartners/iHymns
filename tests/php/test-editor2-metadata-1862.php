<?php

declare(strict_types=1);

/**
 * iHymns — Editor2 Metadata UX overhaul guard (#1862, epic #1863)
 * =============================================================
 *
 * ELI5
 * ----
 * #1862 replaced three hand-maintained editor fields (Copyright, HasAudio,
 * HasSheetMusic) with values the app WORKS OUT instead, and added a new
 * "this song might be public domain now" hint that must never tick a
 * checkbox on its own. This file is the mutation-proven proof that:
 *   (1) the copyright display fold behaves identically in PHP and (later,
 *       once the JS twin lands) in the browser;
 *   (2) the public-domain year math matches the ratified decisions exactly
 *       (right contributor tables, right life-plus term, right fallback);
 *   (3) every place that changes what those denorms depend on (a media
 *       upload/delete, a credit edit, a musician's death date) actually
 *       tells the denorm to recompute — derived from the TREE, not typed
 *       by hand, so a future writer that forgets the hook fails CI instead
 *       of shipping a silently-stale flag;
 *   (4) the retired manual controls are actually gone from the client, and
 *       the alias/override machinery that protects a stale cached client is
 *       actually wired ahead of the generic write path;
 *   (5) the dormant rights-panel server plumbing was NOT accidentally
 *       stripped when its client picker was removed.
 *
 * This file grows across the #1862 build sequence (B1 cores+schema -> B2
 * server endpoints+hooks -> B3 Editor2 client) — sections are numbered to
 * match the build spec's §8 test plan, and each section's assertions only
 * exist once the code they check has landed, so `php tools/run-php-tests.php`
 * stays green after every commit in the sequence, not just the last one.
 *
 *   php tests/php/test-editor2-metadata-1862.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/copyright_display.php
 * @see appWeb/public_html/includes/pd_suggest.php
 * @see appWeb/public_html/includes/song_media_flags.php
 * @see appWeb/public_html/manage/includes/migration-registry.php
 * @see #1862, epic #1863
 */

$repo   = dirname(__DIR__, 2);
$pub    = $repo . '/appWeb/public_html';
$sqlDir = $repo . '/appWeb/.sql';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  \xE2\x9C\x85 {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  \xE2\x9D\x8C {$label}\n";
    }
}

/** PHP-code-only projection (drop comments/doc-blocks) — the
 *  test-derive-rights-facts.php drfPhpCode() idiom, so a source pattern
 *  MENTIONED in a doc-comment (this file included) can never satisfy an
 *  assertion looking for real code. */
function ed1862PhpCode(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT || $tok[0] === T_INLINE_HTML) {
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

/* Mutation self-test for the comment-stripper — proves the harness itself
   can fail before trusting anything built on it (rule #34). */
$mut = [];
$mf = "<?php\n// NeedleComment ihymns_copyright_statement(\n\$x='NeedleCode';\n";
$ms = ed1862PhpCode($mf);
if (strpos($ms, 'NeedleCode') === false) { $mut[] = 'ed1862PhpCode FAILS-HIGH: dropped real code'; }
if (strpos($ms, 'NeedleComment') !== false) { $mut[] = 'ed1862PhpCode FAILS-LOW: kept a comment'; }
if ($mut) {
    fwrite(STDERR, "FAIL: mutation self-test(s) for the comment-stripper:\n");
    foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); }
    exit(1);
}

echo "\n#1862 — Editor2 Metadata UX overhaul guard\n\n";

/* =============================================================================
 * SECTION 1 (B1) — the copyright-statement fold: PHP truth table + source scan.
 * ============================================================================= */
echo "-- Section 1: copyright-statement fold --\n";

$copyrightDisplayFile = $pub . '/includes/copyright_display.php';
check('includes/copyright_display.php exists', is_file($copyrightDisplayFile));
if (is_file($copyrightDisplayFile)) {
    /* Bypass the direct-access guard (same technique test-migration-registry.php
       uses) so this file's real function can be exercised directly. */
    $_SERVER['SCRIPT_FILENAME'] = '/test-runner.php';
    require_once $copyrightDisplayFile;
    check('ihymns_copyright_statement() is defined', function_exists('ihymns_copyright_statement'));

    $fixturesFile = $repo . '/tests/fixtures/copyright-statement-cases.json';
    check('shared fixtures file exists', is_file($fixturesFile));
    if (is_file($fixturesFile) && function_exists('ihymns_copyright_statement')) {
        $fixtures = json_decode((string)file_get_contents($fixturesFile), true);
        $cases = is_array($fixtures) ? ($fixtures['cases'] ?? []) : [];
        check('parsed at least 8 fixture cases (per the #1862 test plan)', count($cases) >= 8);
        foreach ($cases as $c) {
            $label = (string)($c['label'] ?? '(unlabeled case)');
            $got = ihymns_copyright_statement((string)$c['years'], (string)$c['holder'], (string)$c['legacy']);
            check("copyright fold: {$label}", $got === (string)$c['expected']);
        }
    }
}

$songPageFile = $pub . '/includes/pages/song.php';
check('includes/pages/song.php exists', is_file($songPageFile));
if (is_file($songPageFile)) {
    $songPageCode = ed1862PhpCode((string)file_get_contents($songPageFile));
    check(
        'song.php calls the shared ihymns_copyright_statement() fold',
        strpos($songPageCode, 'ihymns_copyright_statement(') !== false
    );
    check(
        'song.php no longer contains the old inline copyright-split fold (regression check)',
        strpos($songPageCode, "trim(\$copyrightYears . ' '") === false
    );
}

/* =============================================================================
 * SECTION 2 (B1) — the PD-suggestion fold: truth table + contributor-table
 * source-of-truth agreement with api2.php's ED2_CREDIT_TABLES (rule #34 —
 * the checked SET is derived from the tree; the role->part MAPPING is the
 * one human policy decision, D1, restated here exactly like
 * test-editor-api2-contract.php's $RENAMED/$RETIRED maps).
 * ============================================================================= */
echo "-- Section 2: public-domain suggestion fold --\n";

$pdSuggestFile = $pub . '/includes/pd_suggest.php';
check('includes/pd_suggest.php exists', is_file($pdSuggestFile));
if (is_file($pdSuggestFile)) {
    $_SERVER['SCRIPT_FILENAME'] = '/test-runner.php';
    require_once $pdSuggestFile;
    check('pdSuggestFold() is defined', function_exists('pdSuggestFold'));
    check('IHYMNS_PD_LIFE_PLUS_YEARS is defined and equals 70 (decision D4)', defined('IHYMNS_PD_LIFE_PLUS_YEARS') && IHYMNS_PD_LIFE_PLUS_YEARS === 70);

    if (function_exists('pdSuggestFold')) {
        $currentYear = (int)date('Y');
        $threshold   = 1900;

        $t1 = pdSuggestFold($currentYear, null, $threshold, $currentYear);
        check('death-basis exactly-boundary (fromYear == currentYear) -> suggested', $t1['suggested'] === true && $t1['basis'] === 'death');

        $t2 = pdSuggestFold($currentYear + 1, 1850, $threshold, $currentYear);
        check('death-basis one-year-early -> NOT suggested (and no publication fallback since fromYear is known)', $t2['suggested'] === false && $t2['basis'] === null);

        $t3 = pdSuggestFold(null, $threshold - 1, $threshold, $currentYear);
        check('NULL + old publication -> suggested on publication basis', $t3['suggested'] === true && $t3['basis'] === 'publication' && $t3['fromYear'] === $threshold - 1);

        $t4 = pdSuggestFold(null, $threshold + 1, $threshold, $currentYear);
        check('NULL + young publication -> NOT suggested', $t4['suggested'] === false && $t4['basis'] === null);

        $t5 = pdSuggestFold(null, null, $threshold, $currentYear);
        check('both NULL -> NOT suggested', $t5['suggested'] === false && $t5['basis'] === null);
    }

    /* ---- contributor-table agreement with api2.php's ED2_CREDIT_TABLES ---- */
    $api2File = $pub . '/manage/editor/api2.php';
    check('manage/editor/api2.php exists', is_file($api2File));
    if (is_file($api2File)) {
        $api2Code = ed1862PhpCode((string)file_get_contents($api2File));
        $creditTablesPos = strpos($api2Code, 'ED2_CREDIT_TABLES = [');
        check("api2.php defines ED2_CREDIT_TABLES", $creditTablesPos !== false);
        $derivedRoleToTable = [];
        if ($creditTablesPos !== false) {
            $win = substr($api2Code, $creditTablesPos, 400);
            preg_match_all("/'([a-z]+)'\s*=>\s*'(tbl[A-Za-z]+)'/", $win, $m, PREG_SET_ORDER);
            foreach ($m as $row) { $derivedRoleToTable[$row[1]] = $row[2]; }
        }
        check('derived at least 5 roles from ED2_CREDIT_TABLES (vacuity check)', count($derivedRoleToTable) >= 5);

        /* Decision D1's role -> part policy (a human decision — same status as
           test-editor-api2-contract.php's $RENAMED map, never mechanically
           derivable). 'artists' is DELIBERATELY absent — performers never
           count toward either part. */
        $rolePartPolicy = [
            'writers'     => 'lyrics',
            'adaptors'    => 'lyrics',
            'translators' => 'lyrics',
            'composers'   => 'music',
            'arrangers'   => 'music',
        ];
        $expectedLyrics = [];
        $expectedMusic  = [];
        foreach ($rolePartPolicy as $role => $part) {
            check("ED2_CREDIT_TABLES defines role '{$role}' (D1 policy depends on it)", isset($derivedRoleToTable[$role]));
            if (!isset($derivedRoleToTable[$role])) { continue; }
            if ($part === 'lyrics') { $expectedLyrics[] = $derivedRoleToTable[$role]; }
            else { $expectedMusic[] = $derivedRoleToTable[$role]; }
        }
        sort($expectedLyrics);
        sort($expectedMusic);
        $actualLyrics = defined('IHYMNS_PD_LYRICS_CREDIT_TABLES') ? IHYMNS_PD_LYRICS_CREDIT_TABLES : [];
        $actualMusic  = defined('IHYMNS_PD_MUSIC_CREDIT_TABLES') ? IHYMNS_PD_MUSIC_CREDIT_TABLES : [];
        sort($actualLyrics);
        sort($actualMusic);
        check(
            'IHYMNS_PD_LYRICS_CREDIT_TABLES matches D1 (writers+adaptors+translators), derived from api2.php',
            defined('IHYMNS_PD_LYRICS_CREDIT_TABLES') && $actualLyrics === $expectedLyrics
        );
        check(
            'IHYMNS_PD_MUSIC_CREDIT_TABLES matches D1 (composers+arrangers), derived from api2.php',
            defined('IHYMNS_PD_MUSIC_CREDIT_TABLES') && $actualMusic === $expectedMusic
        );
        check(
            "'artists' is excluded from both PD contributor-table constants (performers never count)",
            defined('IHYMNS_PD_LYRICS_CREDIT_TABLES') && defined('IHYMNS_PD_MUSIC_CREDIT_TABLES')
                && !in_array('tblSongArtists', IHYMNS_PD_LYRICS_CREDIT_TABLES, true)
                && !in_array('tblSongArtists', IHYMNS_PD_MUSIC_CREDIT_TABLES, true)
        );
    }

    $pdCode = ed1862PhpCode((string)file_get_contents($pdSuggestFile));
    check('pd_suggest.php never writes LyricsPublicDomain/MusicPublicDomain (suggestion-only contract)', strpos($pdCode, 'LyricsPublicDomain') === false && strpos($pdCode, 'MusicPublicDomain') === false);
}

/* =============================================================================
 * SECTION 6 (B1) — migration registry + schema entries.
 * (Sections 3-5 and 7 are appended by later commits in this build sequence —
 * see the file header.)
 * ============================================================================= */
echo "-- Section 6: migration registry + schema --\n";

$registryFile = $pub . '/manage/includes/migration-registry.php';
check('manage/includes/migration-registry.php exists', is_file($registryFile));
if (is_file($registryFile)) {
    $regCode = ed1862PhpCode((string)file_get_contents($registryFile));

    $pdEntryPos = strpos($regCode, "'song-pd-from-year' =>");
    check("registry has a 'song-pd-from-year' entry", $pdEntryPos !== false);
    if ($pdEntryPos !== false) {
        $win = substr($regCode, $pdEntryPos, 1200);
        check("'song-pd-from-year' points at migrate-song-pd-from-year.php", strpos($win, "'script' => 'migrate-song-pd-from-year.php'") !== false);
        check("'song-pd-from-year' probe is not a static `=> true`", !preg_match('/\'probe\'\s*=>\s*static\s+fn[^\n]*=>\s*true/', $win));
        check("'song-pd-from-year' probe checks both new columns", strpos($win, 'LyricsPdFromYear') !== false && strpos($win, 'MusicPdFromYear') !== false);
    }

    $reconcileEntryPos = strpos($regCode, "'reconcile-media-flags' =>");
    check("registry has a 'reconcile-media-flags' entry", $reconcileEntryPos !== false);
    if ($reconcileEntryPos !== false) {
        $win = substr($regCode, $reconcileEntryPos, 1600);
        check("'reconcile-media-flags' points at migrate-reconcile-media-flags.php", strpos($win, "'script' => 'migrate-reconcile-media-flags.php'") !== false);
        check("'reconcile-media-flags' is 'manual' => true (excluded from Apply-all, #1862 spec §4.5)", (bool)preg_match('/\'manual\'\s*=>\s*true/', $win));
        check("'reconcile-media-flags' is 'dryRunnable' => true (real dry-run, not just the drop-only shape)", (bool)preg_match('/\'dryRunnable\'\s*=>\s*true/', $win));
    }
}

check('migrate-song-pd-from-year.php exists on disk', is_file($sqlDir . '/migrate-song-pd-from-year.php'));
check('migrate-reconcile-media-flags.php exists on disk', is_file($sqlDir . '/migrate-reconcile-media-flags.php'));

if (is_file($sqlDir . '/migrate-song-pd-from-year.php')) {
    $migCode = ed1862PhpCode((string)file_get_contents($sqlDir . '/migrate-song-pd-from-year.php'));
    check('migrate-song-pd-from-year.php carries BOTH @migration-adds doctags (multi-column ALTER rule, CLAUDE.md #19)',
        strpos((string)file_get_contents($sqlDir . '/migrate-song-pd-from-year.php'), '@migration-adds tblSongs.LyricsPdFromYear') !== false
        && strpos((string)file_get_contents($sqlDir . '/migrate-song-pd-from-year.php'), '@migration-adds tblSongs.MusicPdFromYear') !== false);
    check('migrate-song-pd-from-year.php never hardcodes an unconditional /public_html/ require (rule #41)',
        !preg_match('/^(require|include)(_once)?\b.*dirname\s*\(\s*__DIR__\s*\).*[\'"][^\'"]*\/public_html\//m', $migCode));
}
if (is_file($sqlDir . '/migrate-reconcile-media-flags.php')) {
    $migCode = ed1862PhpCode((string)file_get_contents($sqlDir . '/migrate-reconcile-media-flags.php'));
    check('migrate-reconcile-media-flags.php never hardcodes an unconditional /public_html/ require (rule #41)',
        !preg_match('/^(require|include)(_once)?\b.*dirname\s*\(\s*__DIR__\s*\).*[\'"][^\'"]*\/public_html\//m', $migCode));
}

$schemaFile = $repo . '/appWeb/.sql/schema.sql';
if (is_file($schemaFile)) {
    $schemaSrc = (string)file_get_contents($schemaFile);
    check('schema.sql mirrors tblSongs.LyricsPdFromYear', (bool)preg_match('/LyricsPdFromYear\s+SMALLINT\s+UNSIGNED/', $schemaSrc));
    check('schema.sql mirrors tblSongs.MusicPdFromYear', (bool)preg_match('/MusicPdFromYear\s+SMALLINT\s+UNSIGNED/', $schemaSrc));
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    fwrite(STDERR, "\nFailures:\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "\nAll #1862 assertions (sections implemented so far) passed.\n";
exit(0);
