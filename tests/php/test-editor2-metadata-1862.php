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
 * SECTION 3 (B2) — flag/PD-recompute wiring, TREE-DERIVED (rule #34): every
 * tblSongMedia writer and every credit-table writer under appWeb/public_html
 * must also reference the matching recompute hook. Nothing here is a typed
 * file list — a FUTURE writer that forgets the hook fails this section
 * without anyone editing this test.
 * ============================================================================= */
echo "-- Section 3: flag/PD-recompute wiring (tree-derived) --\n";

/* Loaded once here, reused by Section 3's per-site checks below AND Section 4a. */
$api2Src  = is_file($pub . '/manage/editor/api2.php') ? (string)file_get_contents($pub . '/manage/editor/api2.php') : '';
$api2Code = ed1862PhpCode($api2Src);
check('manage/editor/api2.php is readable', $api2Src !== '');

/** Every .php file under $root whose comment-stripped source matches $needleRegex. */
function ed1862ScanTree(string $root, string $needleRegex): array
{
    $hits = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        $code = ed1862PhpCode((string)file_get_contents($file->getPathname()));
        if (preg_match($needleRegex, $code)) { $hits[] = $file->getPathname(); }
    }
    sort($hits);
    return $hits;
}

/* ---- tblSongMedia writers -> songMediaRecomputeFlags() ---- */
$mediaWriterNeedle = '/\b(INSERT\s+INTO\s+tblSongMedia\b|DELETE\s+FROM\s+tblSongMedia\b)/i';
$mediaWriters = ed1862ScanTree($pub, $mediaWriterNeedle);
check('derived at least 2 tblSongMedia writer files (vacuity check — a scan finding none would pass vacuously)', count($mediaWriters) >= 2);
foreach ($mediaWriters as $path) {
    $rel = str_replace($repo . '/', '', $path);
    $code = ed1862PhpCode((string)file_get_contents($path));
    check("{$rel} references songMediaRecomputeFlags() SOMEWHERE (file-level net — a brand-new writer with zero references fails here)", strpos($code, 'songMediaRecomputeFlags(') !== false);
}

/* ---- credit-table writers -> pdRecomputeForSong() / pdRecomputeForMusicianName() ----
   Two signals, like schemaAuditScanMigrations()'s multi-signal approach:
   (A) the literal table name straight after INSERT INTO (save_song_core.php,
       includes/song_importers.php); (B) this codebase's own established
       DYNAMIC credit-insert fingerprint — `` INSERT INTO `{$table}` (SongId, Name) ``
       — which is how api2.php's credit_upsert / ed2_applySongSnapshot() write
       these same five tables via the ED2_CREDIT_TABLES map. Confirmed
       non-vacuous against the real tree, and mutation-tested (a decoy dynamic
       INSERT with different columns correctly does NOT match — see the
       session report). */
$creditWriterNeedle = '/\bINSERT\s+INTO\s+(?:(tblSongWriters|tblSongComposers|tblSongArrangers|tblSongAdaptors|tblSongTranslators)\b|`?\{\$table\}`?\s*\(SongId,\s*Name\))/i';
$creditWriters = ed1862ScanTree($pub, $creditWriterNeedle);
check('derived at least 3 credit-table writer files (vacuity check)', count($creditWriters) >= 3);
foreach ($creditWriters as $path) {
    $rel = str_replace($repo . '/', '', $path);
    $code = ed1862PhpCode((string)file_get_contents($path));
    check(
        "{$rel} references pdRecomputeForSong() or pdRecomputeForMusicianName() SOMEWHERE (file-level net)",
        strpos($code, 'pdRecomputeForSong(') !== false || strpos($code, 'pdRecomputeForMusicianName(') !== false
    );
}

/* ---- LAYER 2 — precisely-scoped per-site checks ----------------------------
   The file-level scan above proves a FUTURE writer file can't ship with zero
   references (the regression class rule #34 exists for) but, as originally
   written, could NOT prove that a SPECIFIC hook at a SPECIFIC call site
   wasn't deleted while an unrelated hook elsewhere in the same file (e.g.
   HasAudio/HasSheetMusic's own recompute-and-echo alias branch, or the
   revision-restore recompute) kept the file-level substring check green —
   confirmed by mutation: deleting media_delete's hook call left this
   file-level check passing because media_upload's + revision_restore's own
   calls were still present in the same file. Scoping to the SWITCH-CASE body
   (a flat run from `case 'x':` to the next `case`/`default`) or the enclosing
   FUNCTION body (brace-depth matched) closes that gap for every hook this
   build actually added — TODAY's known write sites, layered ON TOP of the
   tree-derived scan above, the same "derived set + a human disposition list"
   shape test-editor-api2-contract.php's $RENAMED/$RETIRED maps use (rule
   #34's own carve-out for policy data that can't be mechanically derived). */

/** The flat body of a `switch` case: from `case '$name':` to the next
 *  `case '...'`/`default:` (this codebase's case bodies never nest another
 *  switch, so no brace-depth tracking is needed). '' if not found. */
function ed1862CaseBody(string $code, string $caseName): string
{
    $start = strpos($code, "case '{$caseName}':");
    if ($start === false) { return ''; }
    $rest = substr($code, $start + 20);
    $end = preg_match('/case\s+\'[A-Za-z0-9_]+\'\s*:|default\s*:/', $rest, $m, PREG_OFFSET_CAPTURE)
        ? $start + 20 + $m[0][1]
        : strlen($code);
    return substr($code, $start, $end - $start);
}

/** A brace-depth-matched function body: from `function $name(` through its
 *  balanced closing `}`. '' if not found or unbalanced. */
function ed1862FunctionBody(string $code, string $funcName): string
{
    $start = strpos($code, "function {$funcName}(");
    if ($start === false) { return ''; }
    $braceStart = strpos($code, '{', $start);
    if ($braceStart === false) { return ''; }
    $depth = 0;
    for ($i = $braceStart, $len = strlen($code); $i < $len; $i++) {
        if ($code[$i] === '{') { $depth++; }
        elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) { return substr($code, $start, $i - $start + 1); }
        }
    }
    return '';   // unbalanced — degrade to "not found" rather than a bogus tail
}

/* Media: every write case, scoped, must contain its own hook call. */
foreach (['media_upload' => 'media_upload', 'media_delete' => 'media_delete'] as $label => $caseName) {
    $body = $api2Code !== '' ? ed1862CaseBody($api2Code, $caseName) : '';
    check("api2.php's case '{$caseName}' contains a songMediaRecomputeFlags() call (per-site, not just per-file)", $body !== '' && strpos($body, 'songMediaRecomputeFlags(') !== false);
}
$apiLegacySrc  = is_file($pub . '/manage/editor/api.php') ? (string)file_get_contents($pub . '/manage/editor/api.php') : '';
$apiLegacyCode = ed1862PhpCode($apiLegacySrc);
foreach (['song_media_upload', 'song_media_delete'] as $caseName) {
    $body = $apiLegacyCode !== '' ? ed1862CaseBody($apiLegacyCode, $caseName) : '';
    check("api.php's case '{$caseName}' contains a songMediaRecomputeFlags() call (per-site, not just per-file)", $body !== '' && strpos($body, 'songMediaRecomputeFlags(') !== false);
}

/* Credits: each case/function that writes a credit table, scoped, must
   contain its own PD-recompute call. */
foreach (['credit_upsert', 'credit_delete', 'duplicate_song'] as $caseName) {
    $body = $api2Code !== '' ? ed1862CaseBody($api2Code, $caseName) : '';
    check("api2.php's case '{$caseName}' contains a pdRecomputeForSong() call (per-site)", $body !== '' && strpos($body, 'pdRecomputeForSong(') !== false);
}
$saveCoreFileSrc = is_file($pub . '/manage/editor/save_song_core.php') ? (string)file_get_contents($pub . '/manage/editor/save_song_core.php') : '';
$saveCoreFileCode = ed1862PhpCode($saveCoreFileSrc);
$saveCoreBody = $saveCoreFileCode !== '' ? ed1862FunctionBody($saveCoreFileCode, 'editorSaveSongCore') : '';
check("save_song_core.php's editorSaveSongCore() contains a pdRecomputeForSong() call (per-function)", $saveCoreBody !== '' && strpos($saveCoreBody, 'pdRecomputeForSong(') !== false);

$importerFileSrc = is_file($pub . '/includes/song_importers.php') ? (string)file_get_contents($pub . '/includes/song_importers.php') : '';
$importerFileCode = ed1862PhpCode($importerFileSrc);
$importerBody = $importerFileCode !== '' ? ed1862FunctionBody($importerFileCode, '_bulkImport_saveSong') : '';
check("song_importers.php's _bulkImport_saveSong() contains a pdRecomputeForSong() call (per-function)", $importerBody !== '' && strpos($importerBody, 'pdRecomputeForSong(') !== false);

/* =============================================================================
 * SECTION 4a (B2) — the server half of the manual-field retirement: the
 * CopyrightHolder/HasAudio/HasSheetMusic alias branches in
 * metadata_field_update must run BEFORE the generic column write (the
 * test-editor-api2-contract.php windowing technique — a 300-char window,
 * widened from an initial 120 against real source per rule #34's own
 * retrospective), and save_song_core's ON-DUPLICATE tail must no longer
 * update either flag. (The CLIENT half — metadata-tab.js's FIELDS array —
 * is asserted in Section 4b, appended once the Editor2 client build lands.)
 * ============================================================================= */
echo "-- Section 4a: server-side alias branches + ON-DUPLICATE tail (B2) --\n";

/* $api2Src / $api2Code loaded once, in Section 3 above — reused here. */

/* Scope to the metadata_field_update CASE BODY only — the literal
   `UPDATE tblSongs SET \`{$column}\` = ?` string ALSO appears verbatim inside
   ed2_applySongSnapshot() (a different function entirely, the restore path)
   and inside this same case's own RIGHTS-FACTS branch, both of which precede
   the true generic write textually. Scoping to the case + taking the LAST
   occurrence inside it is what makes "before the generic write" mean
   anything (the first cut of this assertion matched the wrong occurrence
   and false-failed on real, correct source — exactly the rule #34 "a guard
   that fails on correct code" trap; fixed to find the case body first). */
$caseStart = strpos($api2Code, "case 'metadata_field_update':");
$caseEnd   = $caseStart !== false ? strpos($api2Code, "case 'song_tune_set':", $caseStart) : false;
check('located the metadata_field_update case body (case start + next-case end)', $caseStart !== false && $caseEnd !== false && $caseEnd > $caseStart);
$caseBody = ($caseStart !== false && $caseEnd !== false) ? substr($api2Code, $caseStart, $caseEnd - $caseStart) : '';

$genericWritePos = $caseBody !== '' ? strrpos($caseBody, 'UPDATE tblSongs SET `{$column}` = ?') : false;
check('api2.php still has the generic metadata_field_update column write (if this moved, the "before" assertions below prove nothing)', $genericWritePos !== false);
if ($genericWritePos !== false) {
    foreach (['CopyrightHolder', 'HasAudio', 'HasSheetMusic'] as $col) {
        $branchPos = strpos($caseBody, "column === '{$col}'");
        check("api2.php has a dedicated \$column === '{$col}' alias branch inside metadata_field_update", $branchPos !== false);
        if ($branchPos !== false) {
            check("the '{$col}' alias branch runs BEFORE the generic column write", $branchPos < $genericWritePos);
        }
    }
}

$saveSongCoreFile = $pub . '/manage/editor/save_song_core.php';
if (is_file($saveSongCoreFile)) {
    $saveCoreCode = ed1862PhpCode((string)file_get_contents($saveSongCoreFile));
    $onDupPos = strpos($saveCoreCode, 'ON DUPLICATE KEY UPDATE');
    check("save_song_core.php has an ON DUPLICATE KEY UPDATE tail", $onDupPos !== false);
    if ($onDupPos !== false) {
        /* Window from ON DUPLICATE to the closing quote — generous (600) since
           the tail lists ~10 columns. */
        $win = substr($saveCoreCode, $onDupPos, 600);
        check(
            "save_song_core's ON-DUPLICATE tail no longer writes HasAudio/HasSheetMusic (#1862 — they are derived, an UPDATE must never clobber live media truth)",
            strpos($win, 'HasAudio') === false && strpos($win, 'HasSheetMusic') === false
        );
    }
}

/* =============================================================================
 * SECTION 4b (B3) — the CLIENT half of the manual-field retirement:
 * metadata-tab.js's FIELDS array literal must contain no
 * 'hasAudio'/'hasSheetMusic'/'copyrightHolder'/'copyright' rows. Regexes the
 * FIELDS array literal ONLY (not the whole file) — a NARROW check, per rule
 * #34's own warning that an over-blunt guard (banning a phrase file-wide)
 * gets weakened or deleted rather than fixed; a bespoke control elsewhere in
 * the file legitimately references these words (e.g. `song.CopyrightHolder`,
 * the picker's own id) without being a FIELDS row.
 * ============================================================================= */
echo "-- Section 4b: client-side FIELDS retirement (B3) --\n";

$metaTabFile = $pub . '/manage/editor/v2/metadata-tab.js';
check('manage/editor/v2/metadata-tab.js exists', is_file($metaTabFile));
$metaTabSrc = is_file($metaTabFile) ? (string)file_get_contents($metaTabFile) : '';
/* JS comment-strip (block + line comments) — mirrors test-rights-panel-
   fields.php's own inline approach for the same reason: a doc-comment
   EXPLAINING the retirement (this file's own header prose) must not
   satisfy a check for the retirement itself. */
$metaTabStripped = $metaTabSrc !== '' ? (preg_replace('#/\*[\s\S]*?\*/#', '', $metaTabSrc) ?? $metaTabSrc) : '';
$metaTabStripped = $metaTabStripped !== '' ? (preg_replace('#(^|[^:])//.*$#m', '$1', $metaTabStripped) ?? $metaTabStripped) : '';

if ($metaTabStripped !== '' && preg_match('/const\s+FIELDS\s*=\s*\[.*?\n\];/s', $metaTabStripped, $fm)) {
    $fieldsSlice = $fm[0];
    check('FIELDS array literal slice is non-trivial (vacuity check)', strlen($fieldsSlice) > 200);
    foreach (['hasAudio', 'hasSheetMusic', 'copyrightHolder', 'copyright'] as $retired) {
        check(
            "metadata-tab.js's FIELDS array no longer has a '{$retired}' row (#1862 retirement)",
            strpos($fieldsSlice, "['{$retired}',") === false
        );
    }
    /* Positive control: fields that DID stay must still be there, so a
       mutation that deletes the WHOLE array (making every "no longer has"
       check vacuously true) is caught. */
    foreach (['title', 'copyrightYears', 'lyricsPublicDomain', 'musicPublicDomain'] as $kept) {
        check("metadata-tab.js's FIELDS array still has a '{$kept}' row (positive control)", strpos($fieldsSlice, "['{$kept}',") !== false);
    }
} else {
    check('located the FIELDS const array literal in metadata-tab.js', false);
}

/* =============================================================================
 * SECTION 5 (B3) — rights-panel removal, lightweight (the full mutation-
 * proven guard is tests/php/test-rights-panel-fields.php, updated in this
 * same build — never re-forked here, rule #22; these are the two headline
 * checks the #1862 spec's own §8 item 5 names, kept here too so this file
 * is self-contained for its own build-sequence story).
 * ============================================================================= */
echo "-- Section 5: rights panel removal (B3) --\n";

check('v2/rights-panel.js does not exist on disk (#1862 — replaced by a derived coverage line)', !is_file($pub . '/manage/editor/v2/rights-panel.js'));
if ($metaTabStripped !== '') {
    check('metadata-tab.js does not import rights-panel.js', strpos($metaTabStripped, 'rights-panel.js') === false);
}
check(
    'api2.php STILL contains the LyricsRightsLicenceKey branch (dormant server plumbing kept per #1862 — removal of the client picker must not strip the server side)',
    strpos($api2Code, 'LyricsRightsLicenceKey') !== false
);

/* =============================================================================
 * SECTION 7 note — the PHP<->JS copyright-fold lockstep (spec §8 item 7)
 * lives as its OWN node test, tests/test-copyright-preview-lockstep.js
 * (auto-discovered by tools/run-node-tests.js's tests/*.js glob — no
 * separate wiring needed, the exact mechanism #1581/rule #35 exists to
 * guarantee). Nothing to assert here in the PHP suite; noted so this file's
 * own section numbering stays legible against the spec.
 * ============================================================================= */

/* =============================================================================
 * SECTION 6 (B1) — migration registry + schema entries.
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
