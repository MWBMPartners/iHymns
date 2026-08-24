<?php
/**
 * iHymns — ZIP bulk-import dry-run wiring guard (#1911, Wave 4 Commit C6)
 *
 * ELI5
 * ----
 * "Dry run" on a ZIP import must mean "preview only, nothing written" all
 * the way through — the moment it started, when the file finished parsing,
 * and everywhere the code decides whether to do a follow-up write. This
 * test reads the actual source files and checks that chain is really wired,
 * rather than trusting a comment that says so.
 *
 * DETAILED / WHY
 * --------------
 * #1674 gave single-file import a working dry-run preview, entirely inside
 * one synchronous request. ZIP import is asynchronous — the upload is
 * persisted, a `tblBulkImportJobs` row is queued, the HTTP connection is
 * released (`fastcgi_finish_request`), and only THEN does the real work
 * happen, in a worker step that can no longer see the original request's
 * in-process state. #1911 (this commit) persists the flag onto that job
 * row (`DryRun` column, migrate-bulk-import-dryrun.php) so the worker and
 * every later status poll can read it back.
 *
 * Four things this guard checks — comment-stripped source assertions, pure
 * source-tree scan, no DB required (slots into the CI lint step):
 *
 *   1. _bulkImport_processZip() (includes/song_importers.php) reads the
 *      DryRun column off its OWN job row (_bulkImport_jobDryRunFlag()) and
 *      feeds it into _bulkImport_dryRun() BEFORE the per-file work starts
 *      (positionally ahead of the tally-counters section the entry loop
 *      builds on).
 *   2. Every ed2_runSongbookMaintenance(...) call inside api2.php's
 *      import_zip case sits under a not-dry-run guard (`!$dryRun` /
 *      `!$jobWasDryRun`) on the SAME line — never unconditional.
 *   3. import_zip itself still contains the column-existence gate that
 *      keeps the pre-#1911 422 refusal alive on an un-migrated install
 *      (ed2_bulkJobsDryRunColumnExists()).
 *   4. import2.php no longer contains the old client-side ZIP-refusal
 *      string-branch (`isZip && dryRunEl.checked`) — the flag now travels
 *      to the server exactly like import_file's already does.
 *   5. TempPath cleanup (`@unlink($persistPath)`) in the async worker is
 *      NOT gated by dry-run — it must appear (textually, hence
 *      positionally) BEFORE the maintenance gate it sits beside, proving
 *      it isn't nested inside that gate's `if`.
 *
 * Mutation-proof (recorded in the commit body, not re-run by this file):
 * moving the `import_zip.async` maintenance call OUT of its `!$jobWasDryRun`
 * guard turns assertion 2 red; restoring the guard turns it green again.
 *
 *   php tests/php/test-zip-dryrun.php
 *
 * Exit status 0 = clean, 1 = at least one wiring gap.
 *
 * @see appWeb/.sql/migrate-bulk-import-dryrun.php
 * @see appWeb/public_html/includes/song_importers.php  _bulkImport_processZip() / _bulkImport_jobDryRunFlag()
 * @see appWeb/public_html/manage/editor/api2.php  import_zip / import_zip_status
 * @see appWeb/public_html/manage/editor/import2.php
 * @see .claude/wave4-actionable-remainder-plan.md §C6
 */

declare(strict_types=1);

$repo = dirname(__DIR__, 2);

/**
 * Blank out /* … *​/ block comments (same length in newlines preserved) so a
 * doc-block that MENTIONS a banned/required pattern in prose can't skew a
 * presence/absence check — mirrors tests/php/test-deploy-paths.php and
 * tests/php/test-component-json-guard.php's established convention.
 */
$stripBlockComments = static function (string $src): string {
    return (string)preg_replace_callback(
        '#/\*.*?\*/#s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    );
};

$failures = [];

/* ---------------------------------------------------------------------- *
 * 1. includes/song_importers.php — the worker reads DryRun off the job
 *    row and primes the static flag before any per-file work.
 * ---------------------------------------------------------------------- */
$importersFile = $repo . '/appWeb/public_html/includes/song_importers.php';
if (!is_readable($importersFile)) {
    $failures[] = "FATAL: cannot read $importersFile";
} else {
    $raw    = (string)file_get_contents($importersFile);
    $code   = $stripBlockComments($raw);

    $fnPos = strpos($code, 'function _bulkImport_processZip(');
    if ($fnPos === false) {
        $failures[] = 'song_importers.php: _bulkImport_processZip() not found.';
    } else {
        /* Bound the function body loosely: from its signature to the start
           of the NEXT top-level "function " declaration (the OpenSong
           parser section that follows it in the file). */
        $nextFnPos = strpos($code, "\nfunction ", $fnPos + 40);
        $body      = $nextFnPos !== false
            ? substr($code, $fnPos, $nextFnPos - $fnPos)
            : substr($code, $fnPos);

        $readPos  = strpos($body, '_bulkImport_jobDryRunFlag(');
        $setPos   = strpos($body, '_bulkImport_dryRun(_bulkImport_jobDryRunFlag(');
        /* Marks the start of the per-file work section — a CODE token
           (not a comment) so it survives $stripBlockComments(); this is
           the first tally-counter variable the entry loop below builds on. */
        $loopPos  = strpos($body, '$songbooksCreated');

        if ($readPos === false) {
            $failures[] = 'song_importers.php: _bulkImport_processZip() never calls _bulkImport_jobDryRunFlag() — the worker cannot resolve DryRun from the job row.';
        }
        if ($setPos === false) {
            $failures[] = 'song_importers.php: _bulkImport_processZip() does not feed _bulkImport_jobDryRunFlag()\'s result into _bulkImport_dryRun() — the resolved flag is never armed.';
        }
        if ($loopPos === false) {
            $failures[] = 'song_importers.php: could not locate the per-file work section ($songbooksCreated) inside _bulkImport_processZip() to order-check against.';
        } elseif ($setPos !== false && $setPos > $loopPos) {
            $failures[] = 'song_importers.php: _bulkImport_dryRun() is armed AFTER per-file work starts, not before — a dry-run job could write real rows before the gate takes effect.';
        }

        /* The DryRun read/set must be conditioned on $jobDb/$jobId both
           being non-null (the async-worker call shape) — the synchronous
           fallbacks never get a job row to read from. */
        if ($setPos !== false && strpos($body, '$jobDb !== null && $jobId !== null') === false) {
            $failures[] = 'song_importers.php: the job-row DryRun read is not guarded on $jobDb/$jobId being present — it would run for synchronous callers that have no job row.';
        }
    }

    /* The helper itself must be column-existence-safe (try/catch), never a
       raw SELECT that throws under STRICT on an un-migrated install. */
    if (strpos($code, 'function _bulkImport_jobDryRunFlag(') === false) {
        $failures[] = 'song_importers.php: _bulkImport_jobDryRunFlag() helper is missing entirely.';
    } elseif (!preg_match('/function _bulkImport_jobDryRunFlag\([^)]*\)\s*:\s*bool\s*\{.*?catch\s*\(\\\\Throwable/s', $code)) {
        $failures[] = 'song_importers.php: _bulkImport_jobDryRunFlag() does not wrap its SELECT in try/catch — it would throw under STRICT on an un-migrated install instead of degrading to false.';
    }
}

/* ---------------------------------------------------------------------- *
 * 2 + 3. manage/editor/api2.php — import_zip's column-existence gate is
 *    still in place, and EVERY songbook-maintenance call it makes is
 *    dry-run-gated on the SAME line as the call.
 * ---------------------------------------------------------------------- */
$api2File = $repo . '/appWeb/public_html/manage/editor/api2.php';
if (!is_readable($api2File)) {
    $failures[] = "FATAL: cannot read $api2File";
} else {
    $raw  = (string)file_get_contents($api2File);
    $code = $stripBlockComments($raw);

    $caseStart = strpos($code, "case 'import_zip':");
    $caseEnd   = strpos($code, "case 'import_zip_status':");
    if ($caseStart === false || $caseEnd === false || $caseEnd <= $caseStart) {
        $failures[] = "api2.php: could not bound the import_zip case block for scanning.";
    } else {
        $zipCase = substr($code, $caseStart, $caseEnd - $caseStart);

        /* 3. Column-existence gate keeps the 422 alive pre-migration. */
        if (!preg_match('/if\s*\(\s*\$dryRun\s*&&\s*!\s*ed2_bulkJobsDryRunColumnExists\(\$db\)\s*\)\s*\{/', $zipCase)) {
            $failures[] = 'api2.php: import_zip no longer column-gates dryRun=1 — an un-migrated install would attempt a real dry-run write path instead of the honest 422 refusal (rule #33).';
        }
        if (strpos($zipCase, '], 422)') === false) {
            $failures[] = 'api2.php: import_zip no longer answers 422 for dryRun=1 on an un-migrated install.';
        }

        /* 2. Every ed2_runSongbookMaintenance(...) call in this case must be
           preceded, on the SAME line, by a not-dry-run guard. */
        if (!preg_match_all('/^.*ed2_runSongbookMaintenance\([^)]*\);.*$/m', $zipCase, $mCalls)) {
            $failures[] = 'api2.php: import_zip makes no ed2_runSongbookMaintenance() call at all — expected at least the EasyWorship / sync / async branches.';
        } else {
            $expectedContexts = ['import_zip_easyworship', 'import_zip.sync_fallback', 'import_zip.sync', 'import_zip.async'];
            $seenContexts     = [];
            foreach ($mCalls[0] as $line) {
                foreach (['!$dryRun', '!$jobWasDryRun'] as $guard) {
                    if (strpos($line, $guard) !== false) { continue 2; }
                }
                $failures[] = "api2.php: an ed2_runSongbookMaintenance() call is not guarded by !\$dryRun / !\$jobWasDryRun on its own line: " . trim($line);
            }
            foreach ($mCalls[0] as $line) {
                foreach ($expectedContexts as $ctx) {
                    if (strpos($line, "'{$ctx}'") !== false) { $seenContexts[$ctx] = true; }
                }
            }
            foreach ($expectedContexts as $ctx) {
                if (empty($seenContexts[$ctx])) {
                    $failures[] = "api2.php: expected an ed2_runSongbookMaintenance() call tagged '{$ctx}' inside import_zip — not found (a branch may have lost its maintenance call entirely).";
                }
            }
        }

        /* 5. TempPath cleanup in the async worker must NOT be nested inside
           the dry-run maintenance gate — assert it textually precedes the
           'import_zip.async' guard line (i.e. it isn't inside that if's
           braces, which — since the guard is a single-line `if (...) { … }`
           — would have to appear textually AFTER the guard's own line to
           be nested in it). */
        $unlinkPos = strpos($zipCase, '@unlink($persistPath);', (int)strpos($zipCase, "'CompletedAt'"));
        $asyncGuardPos = strpos($zipCase, "'import_zip.async'");
        if ($unlinkPos === false || $asyncGuardPos === false) {
            $failures[] = 'api2.php: could not locate the async worker\'s TempPath cleanup or its maintenance guard to order-check.';
        } elseif ($unlinkPos > $asyncGuardPos) {
            $failures[] = 'api2.php: the async worker\'s @unlink($persistPath) TempPath cleanup appears AFTER the import_zip.async maintenance guard — it may have been moved inside the dry-run gate, which would leak temp files under dry-run.';
        }
    }
}

/* ---------------------------------------------------------------------- *
 * 4. manage/editor/import2.php — the old client-side ZIP-refusal
 *    string-branch is gone.
 * ---------------------------------------------------------------------- */
$import2File = $repo . '/appWeb/public_html/manage/editor/import2.php';
if (!is_readable($import2File)) {
    $failures[] = "FATAL: cannot read $import2File";
} else {
    $raw  = (string)file_get_contents($import2File);
    $code = $stripBlockComments($raw);

    if (strpos($code, 'isZip && dryRunEl.checked') !== false) {
        $failures[] = 'import2.php: the pre-#1911 client-side ZIP dry-run refusal (isZip && dryRunEl.checked) is still present — dryRun would never reach the server for a ZIP upload.';
    }
    if (strpos($code, 'Dry run is not yet supported for ZIP imports') !== false) {
        $failures[] = 'import2.php: the pre-#1911 refusal copy is still present in source.';
    }
    /* importZip() must actually SEND the flag now (mirrors importSingle()). */
    $zipFnPos = strpos($code, 'function importZip(file)');
    $singleFnPos = strpos($code, 'function importSingle(file)');
    if ($zipFnPos === false || $singleFnPos === false || $singleFnPos <= $zipFnPos) {
        $failures[] = 'import2.php: could not bound importZip()/importSingle() to check dryRun is appended.';
    } else {
        $zipFnBody = substr($code, $zipFnPos, $singleFnPos - $zipFnPos);
        if (strpos($zipFnBody, "fd.append('dryRun'") === false) {
            $failures[] = 'import2.php: importZip() no longer appends dryRun to the upload FormData — the checkbox state would never reach the server.';
        }
    }
}

/* ---------------------------------------------------------------------- *
 * Report.
 * ---------------------------------------------------------------------- */
if ($failures) {
    fwrite(STDERR, "FAIL — " . count($failures) . " zip-dryrun wiring gap(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "OK — ZIP bulk-import dry-run wiring (#1911) is consistent: " .
     "worker reads DryRun from its job row before per-file work, every " .
     "songbook-maintenance call is dry-run-gated, the pre-migration 422 " .
     "refusal survives, TempPath cleanup is never gated, and import2.php's " .
     "client-side refusal is gone.\n";
exit(0);
