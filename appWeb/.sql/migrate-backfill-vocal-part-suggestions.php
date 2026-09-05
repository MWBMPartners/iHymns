<?php

declare(strict_types=1);

/**
 * iHymns — Backfill: detect voice markers across the catalogue into a review queue (#2073 commit 14, D4)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5: a lot of the songs already in iHymns were typed up years before
 * this feature existed, so instead of a real "who sings this" marking, a
 * curator just typed a line like "WOMEN" or "MEN: You are holy," straight
 * into the lyrics. This script walks every song in the catalogue, ONE
 * TIME, looking for those old marker lines, and writes each one down as a
 * SUGGESTION for a curator to look at on `/manage/vocal-parts-review`
 * (a later commit — #2073 commit 15 — builds that page). It never changes
 * a single song's actual lyrics by itself; it only proposes.
 *
 * WHY THIS IS ITS OWN SCRIPT, AND WHY IT IS MANUAL:
 * every other #2073 migration (`migrate-vocal-parts-rounds.php`) creates
 * EMPTY, dormant tables — completely safe to run automatically as part of
 * "Apply all". This one is different: it reads the WHOLE lyric catalogue
 * (tens of thousands of lines, #929's own OOM lesson about corpus-wide
 * scans) and WRITES data — thousands of review-queue rows a curator will
 * then see and act on. That is squarely the "manual, dry-run-by-default"
 * class of migration `migrate-cleanup-zero-line-components.php` (#2063) and
 * `migrate-reconcile-media-flags.php` (#1862) already established the house
 * pattern for, and this file follows that pattern deliberately rather than
 * inventing a new one:
 *   - Registry `'manual' => true` + `'dryRunnable' => true`: EXCLUDED from
 *     "Apply all" (both the JS bulk runner and the no-JS apply-all loop)
 *     and the pending counter — a routine bulk migration run must never
 *     silently populate a curator-visible review queue.
 *   - DRY-RUN BY DEFAULT. A web run without `?confirm=1` (CLI: without
 *     `--confirm`) only REPORTS what it WOULD do — per-form and
 *     per-songbook counts — and writes nothing.
 *   - IDEMPOTENT. Re-running finds the SAME markers and either leaves them
 *     alone (a suggestion a curator already accepted/dismissed/undone is
 *     NEVER touched again) or refreshes a still-open one in place — never
 *     a second, duplicate row for the same real occurrence. This is the
 *     `uq_Detection (DetectionLineId, Form, MarkerOffset)` unique key
 *     doing its job (see `migrate-vocal-parts-rounds.php`'s own "F2"
 *     cross-review note for why that key exists in the first place), and
 *     it is proven by `tests/php/test-vocal-part-review.php`'s own
 *     "re-run is idempotent" case.
 *   - On a CONFIRMED apply, writes a `vocal_parts_backfill_ran` sentinel
 *     row into `tblAppSettings` — this data-only pass's completion signal
 *     for the registry probe (the `'publishers-entity'` backfill idiom,
 *     same shape `migrate-reconcile-media-flags.php` uses for its own
 *     `media_flags_reconciled_at` sentinel).
 *
 * REUSE, NOT REIMPLEMENTATION (rule #22 of .claude/CLAUDE.md): every actual
 * DECISION this script makes — what counts as a marker, how far a
 * standalone marker's run extends, whether a re-scan should insert /
 * update / leave a row alone, whether a still-open row has gone stale —
 * lives in `includes/vocal_part_review.php`'s `vocalPartReviewScanSong()`
 * and the pure helpers it calls. This script is deliberately thin: it only
 * walks the corpus in safe chunks and calls that ONE function once per
 * song. See that file's own doc-block for why the marker DETECTION itself
 * (`vocal_part_detect.php`) is a third, separate, PURE file this script
 * never touches either.
 *
 * Rule #41 — the shared `includes/` directory is resolved via
 * `IHYMNS_INCLUDES_DIR` (set by the `/manage/setup-database` runner, which
 * physically lives inside the real, per-channel docroot) when running
 * there; the literal `dirname(__DIR__) . '/public_html/includes'` below is
 * the standalone/CLI repo fallback ONLY — this file itself opens its OWN
 * mysqli connection straight from the credentials file (mirrors `migrate-
 * vocal-parts-rounds.php` / `migrate-reconcile-media-flags.php`), so there
 * is no hardcoded `/public_html/` literal anywhere else for the deployed-
 * docroot-rename trap (#1695) to catch.
 *
 * USAGE:
 *   Web (dry-run): /manage/setup-database -> "Detect voice markers into a review queue (#2073)"
 *   Web (apply):   add &confirm=1 to the run URL
 *   CLI (dry-run): php appWeb/.sql/migrate-backfill-vocal-part-suggestions.php
 *   CLI (apply):   php appWeb/.sql/migrate-backfill-vocal-part-suggestions.php --confirm
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/includes/vocal_part_review.php         vocalPartReviewScanSong() — the ONE scan/insert/stale decision core this script calls
 * @see appWeb/public_html/includes/vocal_part_detect.php         the PURE detector vocalPartReviewScanSong() calls
 * @see appWeb/.sql/migrate-vocal-parts-rounds.php                 tblVocalPartSuggestions (commit 2) — the table this script populates
 * @see appWeb/.sql/migrate-cleanup-zero-line-components.php       the dry-run-by-default / manual-card shape this mirrors
 * @see appWeb/.sql/migrate-reconcile-media-flags.php               the sentinel-probe shape this mirrors
 * @see appWeb/public_html/manage/includes/migration-registry.php  'vocal-parts-backfill' entry + probe
 * @see tests/php/test-vocal-part-review.php                       the truth table over vocalPartReviewScanSong()'s pure decision helpers
 * @see .claude/vocal-parts-2073-plan.md                           "Design pass 7" §11 (D4) — see vocal_part_review.php's own note on where this diverges from the earlier `canon-note` design sketch
 * @see #2073, #2075, #1260
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migVPSuggest_out(string $m): void
{
    global $isCli;
    echo $m . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) {
        @flush();
    }
}

function _migVPSuggest_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/* CONFIRM resolution: CLI uses --confirm; web uses ?confirm=1. Anything
   else is a DRY RUN (report only) — the house-standard safe default. */
$confirm = false;
if ($isCli) {
    $confirm = in_array('--confirm', $argv ?? [], true);
} else {
    $confirm = (($_GET['confirm'] ?? '') === '1');
}
$dryRun = !$confirm;

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migVPSuggest_out('ERROR: MySQL credentials not found. Run install.php first.');
    return;
}
require_once $credFile;

/* Rule #41 — see this file's header. `dirname(__DIR__)` (this script's own
   `.sql/` sibling) is correct on every channel already; it is a literal
   `/public_html/` appended after it that would be wrong off `main`. */
$_incDir = defined('IHYMNS_INCLUDES_DIR')
    ? IHYMNS_INCLUDES_DIR
    : dirname(__DIR__) . '/public_html/includes';
require_once $_incDir . '/vocal_part_review.php';   // vocalPartReviewScanSong() + IHYMNS_VOCAL_DETECT_FORMS (via its own require chain)
require_once $_incDir . '/maintenance.php';         // setAppSetting() — the completion sentinel

_migVPSuggest_out('');
_migVPSuggest_out('=== iHymns — Backfill: detect voice markers into a review queue (#2073) ===');
_migVPSuggest_out($dryRun ? 'Mode: DRY RUN (report only — pass ?confirm=1 / --confirm to apply)' : 'Mode: APPLY');
_migVPSuggest_out('');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migVPSuggest_out('ERROR: MySQL connection failed: ' . $e->getMessage());
    return;
}
_migVPSuggest_out('Connected to MySQL: ' . DB_NAME);

try {
    /* An un-migrated install (neither the #1137 trio, the #2073 commit-2
       tables, nor the base lyrics-normalisation tables) has nothing this
       script can do — a clean, quiet skip rather than a STRICT throw. */
    $required = ['tblSongs', 'tblLyrics', 'tblLyricLines', 'tblVocalParts', 'tblLyricLineVocalParts', 'tblVocalPartSuggestions'];
    $missing  = [];
    foreach ($required as $t) {
        if (!_migVPSuggest_tableExists($mysql, $t)) {
            $missing[] = $t;
        }
    }
    if ($missing) {
        _migVPSuggest_out('  [SKIP] Missing required table(s): ' . implode(', ', $missing) . '.');
        _migVPSuggest_out('         Run the "Vocal / singing parts (#1137)" and "Vocal parts: echo spans,');
        _migVPSuggest_out('         rounds/canon, review queue (#2073)" migration cards first.');
        _migVPSuggest_out('Done (nothing to do).');
        $mysql->close();
        return;
    }

    $chunkSize = 500;   // rule #17 — never load the whole catalogue at once
    $lastId    = 0;
    $songsScanned = 0;
    $songsErrored = 0;

    $totals = ['found' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'staled' => 0];
    $byForm = [];
    $bySongbook = [];   // abbr => ['found'=>n,'inserted'=>n,'updated'=>n,'staled'=>n]

    while (true) {
        $stmt = $mysql->prepare('SELECT Id, SongId FROM tblSongs WHERE Id > ? ORDER BY Id ASC LIMIT ?');
        $stmt->bind_param('ii', $lastId, $chunkSize);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$rows) {
            break;
        }

        foreach ($rows as $row) {
            $songId = (string)$row['SongId'];
            $lastId = (int)$row['Id'];

            try {
                $result = vocalPartReviewScanSong($mysql, $songId, $dryRun);
            } catch (\Throwable $e) {
                if (function_exists('songRelocateIsTransactionFatal') && songRelocateIsTransactionFatal($e)) {
                    throw $e;   // a lost connection / deadlock is not "one bad song" — stop the whole batch
                }
                $songsErrored++;
                _migVPSuggest_out("  [WARN] {$songId}: " . $e->getMessage() . ' — skipped, continuing.');
                continue;
            }

            $songsScanned++;
            foreach (['found', 'inserted', 'updated', 'skipped', 'staled'] as $k) {
                $totals[$k] += $result[$k];
            }
            foreach (($result['byForm'] ?? []) as $form => $n) {
                $byForm[$form] = ($byForm[$form] ?? 0) + $n;
            }

            if ($result['found'] > 0 || $result['staled'] > 0) {
                $abbr = strtok($songId, '-') ?: $songId;   // tblSongbooks.Abbreviation prefix (rule #27)
                $bySongbook[$abbr]['found']    = ($bySongbook[$abbr]['found']    ?? 0) + $result['found'];
                $bySongbook[$abbr]['inserted'] = ($bySongbook[$abbr]['inserted'] ?? 0) + $result['inserted'];
                $bySongbook[$abbr]['updated']  = ($bySongbook[$abbr]['updated']  ?? 0) + $result['updated'];
                $bySongbook[$abbr]['staled']   = ($bySongbook[$abbr]['staled']   ?? 0) + $result['staled'];
            }
        }
        _migVPSuggest_out("  ... scanned {$songsScanned} song(s) so far (through Id {$lastId})");
    }

    _migVPSuggest_out('');
    _migVPSuggest_out('--- Summary ---');
    _migVPSuggest_out("  Songs scanned:               {$songsScanned}" . ($songsErrored > 0 ? " ({$songsErrored} skipped on error — see [WARN] lines above)" : ''));
    _migVPSuggest_out('  Markers found this pass:     ' . $totals['found']);
    _migVPSuggest_out('    ' . ($dryRun ? 'Would insert (new):        ' : 'Inserted (new):            ') . $totals['inserted']);
    _migVPSuggest_out('    ' . ($dryRun ? 'Would update (still open): ' : 'Updated (still open):      ') . $totals['updated']);
    _migVPSuggest_out('    Left alone (already reviewed): ' . $totals['skipped']);
    _migVPSuggest_out('    ' . ($dryRun ? 'Would mark stale:          ' : 'Marked stale:              ') . $totals['staled']);

    if ($byForm) {
        _migVPSuggest_out('');
        _migVPSuggest_out('  By form:');
        ksort($byForm);
        foreach ($byForm as $form => $n) {
            _migVPSuggest_out("    - {$form}: {$n}");
        }
    }

    if ($bySongbook) {
        _migVPSuggest_out('');
        _migVPSuggest_out('  By songbook (found / inserted / updated / staled):');
        ksort($bySongbook);
        foreach ($bySongbook as $abbr => $c) {
            _migVPSuggest_out("    - {$abbr}: {$c['found']} / {$c['inserted']} / {$c['updated']} / {$c['staled']}");
        }
    }

    if ($dryRun) {
        _migVPSuggest_out('');
        _migVPSuggest_out('Dry run complete — no changes written. Pass ?confirm=1 (web) or --confirm (CLI) to apply.');
    } else {
        setAppSetting($mysql, 'vocal_parts_backfill_ran', gmdate('Y-m-d H:i:s') . ' UTC');
        _migVPSuggest_out('');
        _migVPSuggest_out('[OK] Backfill applied; sentinel written to tblAppSettings.');
        _migVPSuggest_out('     Review the queue at /manage/vocal-parts-review.');
    }
} catch (\Throwable $e) {
    _migVPSuggest_out('  [ERROR] ' . $e->getMessage());
}
$mysql->close();
return;
