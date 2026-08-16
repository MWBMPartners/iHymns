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
 * exemption mechanism). As of commit A this was used by exactly one site —
 * the legacy Works auto-link fork in save_song_core.php; commit B deletes
 * that fork outright (replaced by workAutolinkSafe()), so the live count is
 * ZERO from commit B onward — see A3 below, whose own expected count moved
 * in lockstep in the same commit. 50, not the tighter 40
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

$coverageFailures      = [];
$exemptMarkers          = [];  // EVERY occurrence in the tree, whether or not a
                                // given INSERT actually needed it as a fallback —
                                // see A3's comment for why this is counted
                                // independently of the coverage loop below.
$columnListViolations   = [];  // §5.1 P0 item 3 — populated below, asserted in Section B
$totalHits              = 0;

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

        /* §5.1 P0 item 3 — ilidStampNewRow() (the fail-safe wrapper) must
           NEVER be called INSIDE an INSERT's own column-list/VALUES clause
           (the forbidden pre-INSERT-mint pattern §1.1 point 5 names — a
           raced pre-INSERT mint would 1062 the INSERT itself and kill the
           create). Scoped to the SQL statement text: from this "INSERT
           INTO tbl…" match up to that same prepare() call's closing —
           approximated by the next `VALUES` keyword (present in every
           mapped-table INSERT in this tree) or, failing that, a generous
           800-char bound so a malformed/unusual statement still gets
           SOME check rather than silently skipping. */
        $stmtWindow = substr($stripped, $offset, 800);
        $valuesPos  = stripos($stmtWindow, 'VALUES');
        $stmtSpan   = $valuesPos !== false ? substr($stmtWindow, 0, $valuesPos) : $stmtWindow;
        if (str_contains($stmtSpan, 'ilidStampNewRow(')) {
            $columnListViolations[] = "{$relFile}:{$line} — ilidStampNewRow( appears inside the INSERT's own column-list/VALUES clause";
        }

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

/* Count-exact (rule #34's "exemptions can't accrete silently"): ZERO
   `/* ilid-exempt:` markers exist anywhere in the tree as of commit B — the
   ONE that existed after commit A (the legacy Works auto-link fork in
   save_song_core.php) was deleted OUTRIGHT by commit B, not merely
   unmarked, when the fork was replaced by workAutolinkSafe(). This
   assertion's own expected count moved 1 -> 0 in the SAME commit that
   deleted the marker's code, which is the intended lifecycle — a marker is
   allowed to disappear when the code it excused disappears with it; it is
   NOT allowed to accrete un-reviewed. Counted independently of the coverage
   loop above (every occurrence, not just the ones a failing INSERT happened
   to fall back on) — a marker pasted next to an INSERT that ALREADY has
   real `ilidStampNewRow()` coverage would otherwise be invisible to a count
   taken only from the fallback branch, which defeats the whole "can't
   accrete silently" point.
   (Mutation: add an unjustified ilid-exempt marker anywhere, even beside an
   already-covered INSERT -> RED.) */
golive_check(
    count($exemptMarkers) === 0,
    'A3 ZERO ilid-exempt markers exist anywhere in the tree (count-exact — the legacy save_song_core.php Works fork this excused was deleted outright by commit B)'
);
foreach ($exemptMarkers as $s) {
    echo "  (exempt marker in: $s)\n";
}

/* §5.1 P0 item 3 (populated during the Section A scan above — see its
   comment): ilidStampNewRow() must NEVER appear inside an INSERT's own
   column-list/VALUES clause. This is the tree-wide sibling of B3c below
   (which pins the SAME rule specifically for save_song_core.php's save
   path) — checked here across every mapped-table INSERT in the tree, not
   just the one save path.
   (Mutation: rewrite a stamp call as a pre-INSERT column value, e.g. paste
   `ilidStampNewRow(...)` between a column list's parens -> RED.) */
golive_check(
    count($columnListViolations) === 0,
    'A4 ilidStampNewRow( never appears inside an INSERT column-list/VALUES clause anywhere in the tree'
);
foreach ($columnListViolations as $v) {
    fwrite(STDERR, "  ($v)\n");
}

/* ==============================================================================
 * SECTION B — §5.1 the P0 fail-safe assertions (commit B: auto-link-on-save)
 * ==============================================================================
 *
 * The single most important property in this whole increment: an auto-link
 * failure can NEVER break a song save. B1 proves the underlying predicate
 * behaves correctly (executed, not just inspected); B2 proves the wrapper
 * built on it actually uses that predicate correctly; B3 proves the save
 * path actually calls the wrapper (and only the wrapper) inside its
 * transaction, and that the pre-#1860 fork it replaces is truly gone.
 * ============================================================================== */

require_once $repo . '/appWeb/public_html/includes/song_relocate.php';
require_once $repo . '/appWeb/public_html/includes/work_admin.php';

/* B1 — executed (not merely inspected) spot-check on
   songRelocateIsTransactionFatal(), the predicate workAutolinkSafe() (and
   ilidStampNewRow()) both depend on for their rethrow-vs-swallow decision.
   The EXHAUSTIVE truth table (20+ cases incl. wrapped/double-wrapped
   chains, the depth bound, and every non-fatal MySQL error code this
   codebase cares about) already lives in tests/php/test-transaction-
   fatal.php (#1688) and runs in the SAME suite — rule #22 forbids
   re-forking that table here. This is a small, independently-EXECUTED
   confirmation of the exact two codes/two non-fatal shapes this
   increment's fail-safe wrappers are built on, so THIS file's own
   pass/fail does not silently depend on a sibling file staying correct.
   (Mutation: narrow songRelocateIsTransactionFatal()'s code list to just
   [1213] -> B1b goes RED; delete the cause-chain walk -> B1e goes RED.) */
golive_check(
    songRelocateIsTransactionFatal(new \mysqli_sql_exception('deadlock', 1213)) === true,
    'B1a songRelocateIsTransactionFatal(1213 deadlock) === true — must rethrow'
);
golive_check(
    songRelocateIsTransactionFatal(new \mysqli_sql_exception('lock wait timeout', 1205)) === true,
    'B1b songRelocateIsTransactionFatal(1205 lock-wait-timeout) === true — must rethrow'
);
golive_check(
    songRelocateIsTransactionFatal(new \RuntimeException('something else')) === false,
    'B1c songRelocateIsTransactionFatal(plain RuntimeException) === false — must swallow'
);
golive_check(
    songRelocateIsTransactionFatal(new \mysqli_sql_exception('dup', 1062)) === false,
    'B1d songRelocateIsTransactionFatal(1062 dup-key) === false — must swallow (not a transaction-fatal shape)'
);
golive_check(
    songRelocateIsTransactionFatal(new \RuntimeException('probe wrap', 0, new \mysqli_sql_exception('deadlock', 1213))) === true,
    'B1e songRelocateIsTransactionFatal(RuntimeException WRAPPING a 1213) === true — cause-chain walk must catch it'
);

/**
 * Brace-balanced function-body extractor — isolates JUST one function's
 * `{ … }` text so B2/B3's structural checks below can't be satisfied by a
 * mention in a DIFFERENT function or a doc-block above it. Operates on
 * already comment-stripped source (comment bytes are spaces, so brace
 * counting is unaffected by a `{` or `}` that only ever appeared in a
 * comment).
 */
function golive_extractFunctionBody(string $stripped, string $funcName): ?string
{
    if (!preg_match('/function\s+' . preg_quote($funcName, '/') . '\s*\(/', $stripped, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $searchFrom = $m[0][1];
    $bracePos = strpos($stripped, '{', $searchFrom);
    if ($bracePos === false) {
        return null;
    }
    $depth = 0;
    $len = strlen($stripped);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($stripped[$i] === '{') {
            $depth++;
        } elseif ($stripped[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($stripped, $bracePos, $i - $bracePos + 1);
            }
        }
    }
    return null;
}

/* B2 — wrapper policy (source-structural, comment-stripped): workAutolinkSafe()
   in work_admin.php. (Mutation: delete the songRelocateIsTransactionFatal()
   check from the catch body -> B2a RED; make the caller-txn-mode catch
   rethrow unconditionally -> B2c RED.) */
$workAdminFile     = $repo . '/appWeb/public_html/includes/work_admin.php';
$workAdminStripped = golive_stripPhpComments((string)file_get_contents($workAdminFile));
$autolinkBody       = golive_extractFunctionBody($workAdminStripped, 'workAutolinkSafe');

golive_check($autolinkBody !== null, 'B2-pre workAutolinkSafe() function body located in work_admin.php');

if ($autolinkBody !== null) {
    golive_check(
        (bool)preg_match('/catch\s*\(\s*\\\\Throwable[^)]*\)\s*\{[^}]*songRelocateIsTransactionFatal/s', $autolinkBody),
        'B2a workAutolinkSafe() has a catch(\Throwable) block that references songRelocateIsTransactionFatal('
    );
    golive_check(
        str_contains($autolinkBody, 'error_log('),
        'B2b workAutolinkSafe() logs a swallowed failure via error_log('
    );
    /* No BARE rethrow: every "throw $e;" in this function's body must be
       immediately gated by the fatal check — i.e. the count of "throw $e;"
       occurrences must equal the count of
       "songRelocateIsTransactionFatal($e)) { throw $e;" occurrences. A
       caller-txn-mode catch that rethrows unconditionally (or an own-txn
       catch that starts rethrowing at all — contract point 2/3, "never
       rethrows" in own-txn mode) would make these counts diverge. */
    $throwCount    = substr_count($autolinkBody, 'throw $e;');
    $guardedThrows = preg_match_all(
        '/songRelocateIsTransactionFatal\s*\(\s*\$e\s*\)\s*\)\s*\{\s*throw\s+\$e;/',
        $autolinkBody
    );
    golive_check(
        $throwCount > 0 && $throwCount === $guardedThrows,
        'B2c every "throw $e;" in workAutolinkSafe() is gated by songRelocateIsTransactionFatal($e) — no bare unconditional rethrow'
    );
}

/* B3 — save-path wiring in save_song_core.php: workAutolinkSafe() is used,
   the legacy inline fork is truly gone, and no bare core call was
   substituted in. (Mutations: re-add `INSERT INTO tblWorks` -> B3b RED;
   swap `workAutolinkSafe(` for a bare `workFindOrLinkByIdentifier(` call ->
   B3c RED; move the call outside begin_transaction()/commit() -> B3a RED.) */
$saveCoreFile     = $repo . '/appWeb/public_html/manage/editor/save_song_core.php';
$saveCoreStripped = golive_stripPhpComments((string)file_get_contents($saveCoreFile));

$beginPos    = strpos($saveCoreStripped, '$db->begin_transaction();');
$commitPos   = strpos($saveCoreStripped, '$db->commit();');
$autolinkPos = strpos($saveCoreStripped, 'workAutolinkSafe(');

golive_check(
    $beginPos !== false && $commitPos !== false && $autolinkPos !== false
        && $beginPos < $autolinkPos && $autolinkPos < $commitPos,
    'B3a save_song_core.php calls workAutolinkSafe( between its begin_transaction() and commit()'
);
golive_check(
    !str_contains($saveCoreStripped, 'INSERT INTO tblWorks'),
    'B3b save_song_core.php contains NO "INSERT INTO tblWorks" — the legacy ISWC-only fork is gone'
);
golive_check(
    !str_contains($saveCoreStripped, 'workFindOrLinkByIdentifier('),
    'B3c save_song_core.php contains NO bare workFindOrLinkByIdentifier( call — only the safe wrapper'
);

/* B4 — funnel-presence sanity (tree-derived counts, not text inspection of
   behaviour — the behaviour itself is B1-B3 above). Confirms the B3/B4
   funnels from the build spec actually wired the shared wrapper: the
   importer (exactly once — B3, the one save site in
   _bulkImport_saveSong()) and api2.php (at least 3 — the metadata_field_
   update hook, duplicate_song, revision_restore).
   (Mutation: delete the song_importers.php call -> B4a RED; delete the
   revision_restore call -> B4b RED via the count dropping to 2.) */
$importerRaw = (string)file_get_contents($repo . '/appWeb/public_html/includes/song_importers.php');
$api2Raw     = (string)file_get_contents($repo . '/appWeb/public_html/manage/editor/api2.php');
golive_check(
    substr_count($importerRaw, 'workAutolinkSafe(') === 1,
    'B4a song_importers.php calls workAutolinkSafe( exactly once'
);
golive_check(
    substr_count($api2Raw, 'workAutolinkSafe(') >= 3,
    'B4b api2.php calls workAutolinkSafe( at least 3 times (metadata hook + duplicate_song + revision_restore)'
);

/* ==============================================================================
 * (Section C is appended by commit C.)
 * ============================================================================== */

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures assertion(s) failed, $passed passed.\n");
    exit(1);
}
echo "All $passed #1860 go-live assertions passed.\n";
exit(0);
