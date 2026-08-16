<?php

declare(strict_types=1);

/**
 * iHymns — #1860 go-live guard: mint-on-create + auto-link-on-save + dual-addressing
 * ====================================================================================
 *
 * ELI5
 * ----
 * Phase 1 (`test-ilyrics-ids.php`) and Phase 3 (`test-work-link-plan.php`)
 * built and proved the dormant machinery. This file guards the GO-LIVE
 * increment (#1860) that actually wires it in: every entity-create funnel
 * stamps a permanent `IL*` id, a song save auto-links its Work by CCLI/ISWC
 * WITHOUT EVER breaking the save itself, and a handful of readers accept an
 * `IL*` id as an alternate address for the same record.
 *
 * This file grows across the feature's three commits (A/B/C below) rather
 * than being written once at the end — each commit's own section is added
 * and green before that commit lands, per CLAUDE.md rule #34 (a guard is
 * only trustworthy once it has actually gone RED against a real mutation).
 *
 *   SECTION A — §5.2 funnel coverage (commit A: mint-on-create)
 *   SECTION B — §5.1 the P0 fail-safe assertions (commit B: auto-link-on-save)
 *   SECTION C — §5.3 dual-addressing resolver wiring (commit C)
 *
 * Runs PURELY against the source tree — NO DATABASE CONNECTION anywhere in
 * this file (CI has none). `ilidStampNewRow()` / `workAutolinkSafe()` /
 * `workFindOrLinkByIdentifier()` are inspected as TEXT and, where pure,
 * CALLED directly (`songRelocateIsTransactionFatal()` needs no DB) — never
 * executed against a live table.
 *
 * MUTATION PROOF (rule #34): every assertion below was written against the
 * mutation named in its adjacent comment, run once during development to
 * confirm it goes RED, then reverted to green. See the commit body for the
 * transcript.
 *
 *   php tests/php/test-ilid-golive.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see .claude/ilyrics-internal-ids-work-model-plan.md  the design this increment implements
 * @see appWeb/public_html/includes/ilyrics_id.php         ilidStampNewRow() / ilidColumnReady() under test
 * @see appWeb/public_html/includes/work_admin.php          workAutolinkSafe() under test
 * @see appWeb/public_html/includes/song_relocate.php       songRelocateIsTransactionFatal() under test
 * @see tests/php/test-ilyrics-ids.php                      the Phase-1 sibling guard
 * @see tests/php/test-work-link-plan.php                   the Phase-3 sibling guard
 * @see #1860
 */

$repo = dirname(__DIR__, 2);

$ilidFile        = $repo . '/appWeb/public_html/includes/ilyrics_id.php';
$publicHtmlDir   = $repo . '/appWeb/public_html';

foreach ([$ilidFile, $publicHtmlDir] as $f) {
    if (!file_exists($f)) {
        fwrite(STDERR, "FATAL: could not find $f\n");
        exit(1);
    }
}

/* $_SERVER['SCRIPT_FILENAME'] under CLI is THIS test file's own path, which
   never matches basename('ilyrics_id.php') — so that file's direct-access
   403 guard is naturally inert here, the same precedent test-ilyrics-ids.php
   and test-work-link-plan.php both rely on. No stubbing needed. */
require_once $ilidFile;

$failures = 0;
$passed   = 0;

function golive_check(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/**
 * Strip PHP comments via the real tokenizer (byte-count-preserving —
 * comment bytes become spaces, no newline removed) so a prose MENTION of a
 * function name inside a doc-block can never satisfy a "is this function
 * actually called here" check. Identical shape to
 * `test-ilyrics-ids.php`'s `ilidStripPhpComments()`; duplicated rather than
 * shared because tests intentionally don't import each other (each is a
 * standalone, independently-runnable oracle).
 */
function golive_stripPhpComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/** Recursively collect every `*.php` file under $dir, sorted (deterministic). */
function golive_collectPhpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

/* ==============================================================================
 * SECTION A — §5.2 funnel coverage (commit A: mint-on-create)
 * ==============================================================================
 *
 * Tree-derived (rule #34): the table list comes from the LIVE
 * `IHYMNS_ILID_TYPES` map, not a second hand-typed copy, and the file list
 * comes from actually walking `appWeb/public_html/`, not a typed list of
 * "the 21 funnels" — a 22nd site added later without wiring is caught
 * automatically, and the wiring in `work_admin.php`'s own pre-existing
 * inline `tblWorks` mints (deliberately NOT touched by this build) is
 * proven correct by the SAME scan rather than asserted by hand.
 *
 * For every `INSERT INTO tbl<mapped>` hit (comment-stripped, so a mention
 * inside a doc-block never counts): require `ilidStampNewRow(` or
 * `ilidAllocate(` within ±50 source lines in the SAME file, OR the literal
 * marker comment `/* ilid-exempt: <reason> * /` within ±3 lines (the ONLY
 * exemption mechanism — used today by exactly one site, the legacy Works
 * fork in save_song_core.php that commit B deletes). 50, not the tighter 40
 * a first draft used: several real multi-branch INSERTs (musicians.php's
 * 4-shape create, songbooks.php's 23-column create) put the FIRST branch's
 * "INSERT INTO tbl…" 42-45 lines from the ONE stamp call that correctly
 * covers every branch after them — genuinely correct code a too-tight
 * window would flag (rule #34's "a guard that fails on correct code gets
 * weakened or deleted" trap). 50 keeps meaningful margin below the
 * mutation-proof case immediately below (moving a call 60 lines away must
 * still go RED).
 * ============================================================================== */

$tableToEntity = [];
foreach (IHYMNS_ILID_TYPES as $entityType => $def) {
    $tableToEntity[$def['table']] = $entityType;
}
$insertPattern = '/INSERT\s+(?:IGNORE\s+)?INTO\s+(' .
    implode('|', array_map(static fn($t) => preg_quote($t, '/'), array_keys($tableToEntity))) .
    ')\b/';

$phpFiles = golive_collectPhpFiles($publicHtmlDir);

$coverageFailures = [];
$exemptMarkers     = [];  // EVERY occurrence in the tree, whether or not a
                           // given INSERT actually needed it as a fallback —
                           // see A3's comment for why this is counted
                           // independently of the coverage loop below.
$totalHits         = 0;

foreach ($phpFiles as $file) {
    $raw = (string)file_get_contents($file);

    if (preg_match_all('/\/\*\s*ilid-exempt:/', $raw, $mk)) {
        $relFileMk = 'appWeb/public_html' . substr($file, strlen($publicHtmlDir));
        for ($i = 0; $i < count($mk[0]); $i++) {
            $exemptMarkers[] = $relFileMk;
        }
    }

    if (!str_contains($raw, 'INSERT')) {
        continue; // cheap pre-filter — most files never mention INSERT at all
    }
    $stripped = golive_stripPhpComments($raw);

    if (!preg_match_all($insertPattern, $stripped, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    $strippedLines = explode("\n", $stripped);
    $rawLines      = explode("\n", $raw);
    $lineCount     = count($strippedLines);
    $relFile       = 'appWeb/public_html' . substr($file, strlen($publicHtmlDir));

    foreach ($matches[0] as $m) {
        $offset = $m[1];
        $table  = null;
        foreach ($tableToEntity as $t => $et) {
            if (str_contains($m[0], $t)) { $table = $t; break; }
        }
        $totalHits++;
        $line = substr_count(substr($stripped, 0, $offset), "\n") + 1; // 1-based

        $winLo = max(1, $line - 50);
        $winHi = min($lineCount, $line + 50);
        $windowText = implode("\n", array_slice($strippedLines, $winLo - 1, $winHi - $winLo + 1));

        $hasStamp = str_contains($windowText, 'ilidStampNewRow(') || str_contains($windowText, 'ilidAllocate(');

        if ($hasStamp) {
            continue;
        }

        /* Fall back to the exempt marker — searched in the RAW (not
           comment-stripped) text, since the marker itself IS a comment,
           within a tighter ±3 line window. */
        $exWinLo = max(1, $line - 3);
        $exWinHi = min(count($rawLines), $line + 3);
        $exWindowText = implode("\n", array_slice($rawLines, $exWinLo - 1, $exWinHi - $exWinLo + 1));

        if (str_contains($exWindowText, '/* ilid-exempt:')) {
            continue; // used as the fallback here — already counted in $exemptMarkers above
        }

        $coverageFailures[] = "{$relFile}:{$line} INSERT INTO {$table} — no ilidStampNewRow()/ilidAllocate() within ±50 lines and no ilid-exempt marker within ±3 lines";
    }
}

/* Sanity floor — proves the scan actually walked the tree and matched
   something, so a regex/pathing bug can't silently report zero hits as a
   trivial pass. (Mutation: break $insertPattern's alternation -> RED.) */
golive_check($totalHits >= 21, "A1 scan found at least 21 INSERT-INTO-mapped-table hits across the tree (found {$totalHits})");

golive_check(
    count($coverageFailures) === 0,
    'A2 every INSERT INTO <mapped table> hit has ilidStampNewRow()/ilidAllocate() within ±50 lines, or an ilid-exempt marker within ±3 lines'
);
if ($coverageFailures) {
    foreach ($coverageFailures as $f) {
        fwrite(STDERR, "  (uncovered: $f)\n");
    }
}

/* Count-exact (rule #34's "exemptions can't accrete silently"): exactly ONE
   `/* ilid-exempt:` marker exists ANYWHERE in the tree today — the legacy
   Works auto-link fork in save_song_core.php, which commit B deletes
   outright (at which point this expected count drops to 0 and the test is
   updated in that same commit). Counted independently of the coverage loop
   above (every occurrence, not just the ones a failing INSERT happened to
   fall back on) — a marker pasted next to an INSERT that ALREADY has real
   `ilidStampNewRow()` coverage would otherwise be invisible to a count taken
   only from the fallback branch, which defeats the whole "can't accrete
   silently" point.
   (Mutation: add a second, unjustified ilid-exempt marker anywhere, even
   beside an already-covered INSERT -> RED; delete the one legitimate marker
   without wiring a real stamp -> RED via A2.) */
golive_check(
    count($exemptMarkers) === 1,
    'A3 exactly ONE ilid-exempt marker exists anywhere in the tree (count-exact — the legacy save_song_core.php Works fork, deleted in commit B)'
);
foreach ($exemptMarkers as $s) {
    echo "  (exempt marker in: $s)\n";
}

/* ==============================================================================
 * (Sections B and C are appended by commits B and C.)
 * ============================================================================== */

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures assertion(s) failed, $passed passed.\n");
    exit(1);
}
echo "All $passed #1860 go-live assertions passed.\n";
exit(0);
