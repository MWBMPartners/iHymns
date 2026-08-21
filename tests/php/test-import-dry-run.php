<?php

declare(strict_types=1);

/**
 * iHymns — bulk-import dry-run seam guard (#1674)
 *
 * ELI5
 * ----
 * Bulk import can now run in "preview" mode: it tells you what it WOULD do
 * (create this song, skip that one, create this songbook) without actually
 * writing anything. This file proves the one line that makes that safe is in
 * EXACTLY the right spot — not one line too early (which would make the
 * preview lie) and not one line too late (which would leak an open database
 * transaction for every song in the file).
 *
 * WHY THIS EXISTS (source-contract, mutation-proven, rule #34)
 * --------------------------------------------------------------
 * `_bulkImport_saveSong()` opens its OWN per-song transaction and MySQL has
 * no nested transactions, so "wrap the whole import and roll it back" cannot
 * work here. The only workable seam is: run every real DECISION (the
 * existence pre-flight, the optional title-dedupe pre-flight) and suppress
 * only the WRITE block, via one early-return positioned exactly at the
 * transaction boundary — after both pre-flights, before
 * `$db->begin_transaction()`. This is, verbatim, the single highest-risk
 * line of the #1674 feature (wave plan §5.1): one position too early and a
 * dry-run preview reports counts that diverge from what a real import would
 * do; one position too late and every dry-run song leaks an uncommitted
 * transaction. A plain "does the function call _bulkImport_dryRun()
 * somewhere?" existence check cannot see either failure — only a POSITIONAL
 * assertion can, checked in BOTH directions. This file uses
 * `tests/php/lib/php_source_units.php`'s per-function source view (`code`,
 * with every string literal reduced to `'@STR@'` / `@SQL:VERB:table@` so
 * prose can never satisfy a structural assertion — see that file's header)
 * plus its structural walker `phpUnitsEnclosingBlocks()`, so the check is
 * "which block(s) enclose this call" rather than "how many characters away
 * is it" (the #1671/rule #34 lesson: a character window silently
 * under-reports on real source).
 *
 * WHAT THIS FILE DOES **NOT** CHECK
 * ----------------------------------
 * It has no database and does not execute `_bulkImport_saveSong()` /
 * `_bulkImport_upsertSongbook()` / the api2.php handlers — those need a live
 * mysqli connection this harness does not have (the same constraint
 * `test-importer-rights-fields.php`'s header explains). Every assertion here
 * is a SOURCE-CONTRACT check: is the seam wired in the right shape, in the
 * right place, relative to the right landmarks? The manual alpha pass in the
 * wave plan (§3 C8, "Manual (alpha)") is what proves the RUNTIME behaviour —
 * a dry-run import writes zero rows and a subsequent real import matches its
 * counts.
 *
 * MUTATION-PROVEN DURING AUTHORING (rule #34) — each positional/structural
 * assertion below was checked to go RED when the thing it guards was
 * reverted, then restored byte-identical:
 *   (a) the saveSong early-return moved ABOVE the title-dedupe pre-flight →
 *       PART B's ordering assertion goes red;
 *   (b) the import_zip 422 refusal deleted → PART E's assertion goes red;
 *   (c) the `!$dryRun` conjunct dropped from the maintenance-call gate →
 *       PART D's gate assertion goes red.
 *
 *   php tests/php/test-import-dry-run.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see https://www.php.net/manual/en/function.token-get-all.php
 */

$repoRoot = dirname(__DIR__, 2);
require_once __DIR__ . '/lib/php_source_units.php';

$passed   = 0;
$failures = [];
function idr(string $label, bool $cond): void
{
    global $passed, $failures;
    if ($cond) { $passed++; echo "  \xE2\x9C\x85 {$label}\n"; return; }
    $failures[] = $label;
    echo "  \xE2\x9D\x8C {$label}\n";
}

echo "\n#1674 — bulk-import dry-run seam\n\n";

/* =========================================================================
 * PART A — _bulkImport_dryRun() exists, adjacent-in-shape (and adjacent IN
 * THE FILE) to _bulkImport_dedupeMode() — the established precedent for
 * exactly this kind of per-request static-flag mode (§3 C7.1).
 * ========================================================================= */
$impSrc   = (string)file_get_contents($repoRoot . '/appWeb/public_html/includes/song_importers.php');
$impUnits = phpSourceUnits($impSrc);

idr('_bulkImport_dedupeMode() unit is locatable', isset($impUnits['_bulkImport_dedupeMode']));
idr('_bulkImport_dryRun() unit is locatable', isset($impUnits['_bulkImport_dryRun']));
idr('_bulkImport_dryRunSeen() unit is locatable (the per-run seen-set mechanism)', isset($impUnits['_bulkImport_dryRunSeen']));

/* Adjacency IN THE FILE: the next top-level `function NAME(` declaration
   after _bulkImport_dedupeMode()'s must be _bulkImport_dryRun()'s — these are
   both column-0 top-level functions (never indented), so a plain per-line
   regex is sound here (unlike inside a function body, where phpSourceUnits'
   token-aware split is required). */
preg_match_all('/^function\s+(\w+)\s*\(/m', $impSrc, $fnMatches);
$fnOrder = $fnMatches[1] ?? [];
$dedupeIdx = array_search('_bulkImport_dedupeMode', $fnOrder, true);
$dryRunIdx = array_search('_bulkImport_dryRun', $fnOrder, true);
idr(
    '_bulkImport_dryRun() is declared IMMEDIATELY after _bulkImport_dedupeMode() in the file',
    $dedupeIdx !== false && $dryRunIdx !== false && $dryRunIdx === $dedupeIdx + 1
);

/* Same shape as _bulkImport_dedupeMode(): a `static` local, a nullable
   optional parameter that both sets AND reads. Mutation: replacing the
   static with a global, or dropping the nullable-param convention, breaks
   this without breaking the pure existence check above. */
$dryRunCode = $impUnits['_bulkImport_dryRun']['code'] ?? '';
idr('_bulkImport_dryRun() keeps its flag in a `static` local (request-scoped, not global)',
    str_contains($dryRunCode, 'static $dryRun'));
idr('_bulkImport_dryRun() setting the flag also resets the seen-set (ONE mechanism, rule #35)',
    str_contains($dryRunCode, '_bulkImport_dryRunSeen('));

/* =========================================================================
 * PART B — _bulkImport_saveSong(): the early-return sits EXACTLY between the
 * title-dedupe pre-flight and $db->begin_transaction(). THE HIGHEST-RISK
 * LINE OF THIS WAVE (§5.1) — checked positionally in BOTH directions, plus
 * structurally (not merely "after the dedupeMode() call" but "outside the
 * dedupeMode if-block entirely", so a mutation that nests the early-return
 * inside that if cannot slip past an order-only check).
 * ========================================================================= */
$saveCode = $impUnits['_bulkImport_saveSong']['code'] ?? '';
idr('_bulkImport_saveSong() unit is locatable', $saveCode !== '');

$posDedupeCall = strpos($saveCode, '_bulkImport_dedupeMode(');
$posDryRunCall = strpos($saveCode, '_bulkImport_dryRun(');
$posBeginTx    = strpos($saveCode, 'begin_transaction(');

idr('saveSong calls _bulkImport_dedupeMode() (the title-dedupe pre-flight)', $posDedupeCall !== false);
idr('saveSong calls _bulkImport_dryRun() (the dry-run early-return check)', $posDryRunCall !== false);
idr('saveSong calls $db->begin_transaction()', $posBeginTx !== false);

/* Direction 1: NOT one line too early — the dry-run check textually follows
   the title-dedupe pre-flight's own call to _bulkImport_dedupeMode(). A
   mutation that hoists the early-return above the pre-flight block (so a
   preview would lie about created/skipped) flips this to false. */
idr(
    'dry-run early-return sits AFTER the title-dedupe pre-flight call (not hoisted above it — a preview would then lie)',
    $posDedupeCall !== false && $posDryRunCall !== false && $posDryRunCall > $posDedupeCall
);
/* Direction 2: NOT one line too late — it precedes $db->begin_transaction().
   A mutation that drops it below that call (so every dry-run song opens and
   never closes a transaction) flips this to false. */
idr(
    'dry-run early-return sits BEFORE $db->begin_transaction() (not after it — every song would leak an open transaction)',
    $posDryRunCall !== false && $posBeginTx !== false && $posDryRunCall < $posBeginTx
);

/* Structural: the dry-run check is not NESTED inside the dedupeMode
   if-block itself (which would make it a positional pass while still being
   wrong — e.g. only firing for songs that opted into title-dedupe). None of
   its enclosing blocks' heads may reference _bulkImport_dedupeMode(). */
if ($posDryRunCall !== false) {
    $enclosing = phpUnitsEnclosingBlocks($saveCode, $posDryRunCall);
    $nestedInDedupeIf = false;
    foreach ($enclosing as $blk) {
        if (str_contains($blk['head'], '_bulkImport_dedupeMode')) { $nestedInDedupeIf = true; break; }
    }
    idr('dry-run early-return is NOT nested inside the dedupeMode if-block (it runs for every song, not only dedupe-opted-in ones)', !$nestedInDedupeIf);
} else {
    idr('dry-run early-return is NOT nested inside the dedupeMode if-block (it runs for every song, not only dedupe-opted-in ones)', false);
}

/* The early-return's own contract: on (), consult the seen-set; skip vs
   create. Mutation: swapping the ternary branches, or calling the seen-set
   with the wrong argument, breaks a real behavioural test but this source
   check pins the SHAPE so a reviewer sees it too. */
idr(
    'the early-return consults _bulkImport_dryRunSeen($songId) for in-file duplicate fidelity',
    (bool)preg_match('/_bulkImport_dryRun\s*\(\s*\)\s*\)\s*\{\s*return\s+_bulkImport_dryRunSeen\s*\(\s*\$songId\s*\)/', $saveCode)
);

/* =========================================================================
 * PART C — _bulkImport_upsertSongbook(): the dry-run early-return sits after
 * the existence SELECT (`!$exists`) and before the INSERT (§3 C7.3).
 * ========================================================================= */
$sbCode = $impUnits['_bulkImport_upsertSongbook']['code'] ?? '';
idr('_bulkImport_upsertSongbook() unit is locatable', $sbCode !== '');

$posSelect = strpos($sbCode, '@SQL:SELECT:tblSongbooks@');
$posSbDry  = strpos($sbCode, '_bulkImport_dryRun(');
$posInsert = strpos($sbCode, '@SQL:INSERT:tblSongbooks@');

idr('upsertSongbook runs its existence SELECT against tblSongbooks', $posSelect !== false);
idr('upsertSongbook calls _bulkImport_dryRun()', $posSbDry !== false);
idr('upsertSongbook INSERTs into tblSongbooks (the real-run create path)', $posInsert !== false);
idr(
    'dry-run check sits AFTER the existence SELECT (the created/existing decision already made)',
    $posSelect !== false && $posSbDry !== false && $posSbDry > $posSelect
);
idr(
    'dry-run check sits BEFORE the INSERT (no row is written under dry-run)',
    $posSbDry !== false && $posInsert !== false && $posSbDry < $posInsert
);

/* =========================================================================
 * PART D — import_file (api2.php): reads dryRun, sets the mode flag,
 * injects `dry_run` into the response, and gates the post-import
 * maintenance call on `!$dryRun` (§3 C7.4).
 * ========================================================================= */
$apiSrc   = (string)file_get_contents($repoRoot . '/appWeb/public_html/manage/editor/api2.php');
$apiUnits = phpSourceUnits($apiSrc);

$importFileCode = $apiUnits["case 'import_file'"]['code'] ?? '';
idr("api2.php case 'import_file' is locatable", $importFileCode !== '');

idr('import_file reads $_POST[\'dryRun\']', str_contains($importFileCode, "'dryRun'"));
idr('import_file calls _bulkImport_dryRun( — set explicitly, both directions', str_contains($importFileCode, '_bulkImport_dryRun('));
idr("import_file injects \$summary['dry_run'] into the response (a KEY, not prose — rule #35)",
    str_contains($importFileCode, "\$summary['dry_run']"));

/* The maintenance call is gated `!$dryRun && …songs_created… > 0`, checked
   both by regex (the literal shape) AND structurally (the call site's
   innermost enclosing `if` actually carries that guard — not merely present
   somewhere earlier in the case). Mutation: dropping the `!$dryRun &&`
   conjunct (leaving only the songs_created check) breaks BOTH. */
idr(
    'the maintenance-call guard reads `if (!$dryRun && …)`',
    (bool)preg_match('/if\s*\(\s*!\s*\$dryRun\s*&&/', $importFileCode)
);
$posMaintCall = strpos($importFileCode, 'ed2_runSongbookMaintenance(');
if ($posMaintCall !== false) {
    $encMaint = phpUnitsEnclosingBlocks($importFileCode, $posMaintCall);
    $gated = isset($encMaint[0]) && str_contains($encMaint[0]['head'], '!$dryRun');
    idr('ed2_runSongbookMaintenance() call is INSIDE an `if` whose own head carries the `!$dryRun` guard', $gated);
} else {
    idr('ed2_runSongbookMaintenance() call is INSIDE an `if` whose own head carries the `!$dryRun` guard', false);
}

/* logActivity detail carries 'dryRun' (audit trail distinguishes a preview
   from a real import even though the activity row itself always fires). */
idr("import_file's logActivity() detail includes 'dryRun'",
    (bool)preg_match('/logActivity\s*\([^;]*\'dryRun\'/s', $importFileCode));

/* =========================================================================
 * PART E — import_zip (api2.php): dryRun=1 is REFUSED with 422 before any
 * job/file work (§3 C7.5, rule #33 — a param the destination can't honour
 * is refused, not silently ignored).
 * ========================================================================= */
$importZipCode = $apiUnits["case 'import_zip'"]['code'] ?? '';
idr("api2.php case 'import_zip' is locatable", $importZipCode !== '');

$pos422        = strpos($importZipCode, '422');
$posZipDedupe  = strpos($importZipCode, '_bulkImport_dedupeMode(');
$posClassExists = strpos($importZipCode, 'class_exists(');

idr('import_zip contains a 422 response', $pos422 !== false);
idr('import_zip still calls _bulkImport_dedupeMode() (normal-path work continues to exist)', $posZipDedupe !== false);
idr('import_zip still probes ZipArchive via class_exists() (normal-path work continues to exist)', $posClassExists !== false);

/* Structural: the 422 must be inside an `if` block whose OWN head tests
   dryRun — not merely "a 422 exists somewhere in this case", which a
   coincidental unrelated 422 elsewhere could satisfy. */
if ($pos422 !== false) {
    $enc422 = phpUnitsEnclosingBlocks($importZipCode, $pos422);
    $dryRunGated422 = isset($enc422[0]) && str_contains($enc422[0]['head'], 'dryRun');
    idr('the 422 is inside an `if` block whose own head tests dryRun (not a coincidental unrelated 422)', $dryRunGated422);
} else {
    idr('the 422 is inside an `if` block whose own head tests dryRun (not a coincidental unrelated 422)', false);
}

/* Positional: refused BEFORE any job/file work — both the dedupe-mode set
   and the ZipArchive probe must come AFTER the 422 check textually. */
idr(
    'the 422 refusal sits BEFORE _bulkImport_dedupeMode() is set (refused before any normal-path work)',
    $pos422 !== false && $posZipDedupe !== false && $pos422 < $posZipDedupe
);
idr(
    'the 422 refusal sits BEFORE the ZipArchive class_exists() probe (refused before any job/file work)',
    $pos422 !== false && $posClassExists !== false && $pos422 < $posClassExists
);

/* =========================================================================
 * PART F — import2.php: PHP <-> JS lockstep (rule #35). The client appends
 * the field the server reads, and branches the summary render on the exact
 * key the server injects — checked on COMMENT-STRIPPED source so a doc-block
 * quoting these tokens while explaining them cannot itself satisfy the
 * assertion (the same trap test-editor-api2-contract.php's header names).
 * ========================================================================= */
$import2Src = (string)file_get_contents($repoRoot . '/appWeb/public_html/manage/editor/import2.php');
$stripJs = static function (string $s): string {
    $s = preg_replace('#/\*[\s\S]*?\*/#', '', $s) ?? $s;
    return preg_replace('#(^|[^:])//.*$#m', '$1', $s) ?? $s;
};
$import2 = $stripJs($import2Src);

idr("import2.php declares the 'imp-dryrun' checkbox", str_contains($import2Src, 'id="imp-dryrun"'));
idr('import2.php JS reads #imp-dryrun into dryRunEl', str_contains($import2, "getElementById('imp-dryrun')"));
idr(
    "importSingle() appends the 'dryRun' form field the server reads (PART D)",
    (bool)preg_match('/fd\.append\(\s*[\'"]dryRun[\'"]\s*,\s*dryRunEl\.checked/', $import2)
);
idr(
    'the click handler refuses a ZIP + dry-run combination client-side, without uploading',
    (bool)preg_match('/isZip\s*&&\s*dryRunEl\.checked/', $import2)
);
idr(
    "renderSummary() branches on data.dry_run (the EXACT key api2.php injects in PART D — not a re-derived guess)",
    str_contains($import2, 'data.dry_run')
);

/* =========================================================================
 * Report
 * ========================================================================= */
if ($failures) {
    fwrite(STDERR, "\nFAIL: bulk-import dry-run seam (#1674):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    fwrite(STDERR, "\n{$passed} passed, " . count($failures) . " failed.\n");
    exit(1);
}
echo "\nPASS: bulk-import dry-run seam ({$passed} assertions).\n";
exit(0);
