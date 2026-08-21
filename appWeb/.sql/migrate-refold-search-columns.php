<?php

declare(strict_types=1);

/**
 * iHymns — Refold search columns for the Unicode-preserving fold v2 (#1908 Commit 2)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE (ELI5): the OLD stored search columns were folded with a broken
 * recipe that turned every non-Latin title/lyrics ("耶稣爱我", "Иисус",
 * "Χριστός") into an empty string. This card just re-runs the NEW, fixed
 * recipe over every existing row so those songs become findable again
 * through the folded search arm — nothing else about the song changes.
 *
 * PURPOSE (DETAILED): Commit 1 of #1908 rewrote `ihymns_normalize_title()` /
 * `ihymns_search_fold()` (includes/title_normalize.php) from a locale-
 * dependent `iconv('UTF-8','ASCII//TRANSLIT//IGNORE', …)` fold — which
 * mangles any script iconv can't transliterate into a run of literal '?'
 * characters that the old punctuation-strip then erased to `''` — to a
 * `Normalizer::FORM_KD` fold that PRESERVES non-Latin scripts. That fixed
 * the FUNCTION, but two STORED columns still hold values computed by the old
 * function:
 *
 *   - tblSongs.NormalizedTitle  (dedup/match pre-filter, VARCHAR(500))
 *   - tblSongs.LyricsTextFolded (accent-insensitive FULLTEXT mirror, MEDIUMTEXT)
 *
 * This migration is a pure DATA RECOMPUTE — no DDL, nothing added to
 * schema.sql, no `@migration-adds` doctag (CLAUDE.md rule #19: only a column-
 * or table-creating migration needs a schema.sql mirror; this one creates
 * neither). It walks every tblSongs row and overwrites both columns with
 * `ihymns_search_fold()`'s CURRENT output, i.e. whatever `title_normalize.php`
 * on THIS docroot currently implements — which is why the cross-docroot
 * ordering note below is load-bearing, not decorative.
 *
 * WHY THE STORED VALUE MATTERS DESPITE THE FUNCTION ALREADY BEING FIXED
 * (D5 in the locked spec, .claude/unicode-nonlatin-1908-plan.md §0.1): the
 * interim state (function fixed, this card not yet run) is SAFE — every
 * consumer that compares NormalizedTitle for EQUALITY already guards
 * `!== ''` or recomputes live (manage/duplicate-songs.php, ia_reconcile.php,
 * lyrics_ingest.php, song_importers.php), so a stale `''` row can never
 * false-match. What stays broken until this card runs is only the FOLDED
 * search arms: `MATCH(NormalizedTitle) AGAINST(...)`,
 * `MATCH(NormalizedTitle, LyricsTextFolded) AGAINST(...)` and any
 * `LyricsTextFolded LIKE '%…%'` — those read the STORED column, so a
 * non-Latin song stays invisible to folded search until its row is refolded.
 * The raw `Title`/`LyricsText` FULLTEXT arms are untouched by any of this and
 * keep working regardless.
 *
 * ⚠ CROSS-DOCROOT OPS NOTE (READ BEFORE RUNNING ON THE SHARED DB) — mirrors
 * CLAUDE.md rule #25's C-phase discipline and is spelled out at
 * .claude/unicode-nonlatin-1908-plan.md §8 point 2: the three channels
 * (main / beta / alpha) are SEPARATE docroots that all point at ONE shared
 * MySQL database (rule #41). Run this card ONLY AFTER Commit 1 (the fold
 * fix) has been DEPLOYED TO ALL THREE DOCROOTS. If it were run while an
 * older docroot is still live on the iconv-lossy fold, that docroot's own
 * save path (create/edit) would keep WRITING stale iconv-folded values for
 * any non-Latin title/lyrics saved through it — quietly undoing this card's
 * work for those rows. Nothing here can detect that automatically (three
 * docroots, one DB — this script only sees whichever docroot ran it, never
 * the others), so it is an OPERATOR discipline, not a code gate. The card is
 * idempotent and re-runnable: if in doubt, redeploy Commit 1 everywhere and
 * run this card again — a second pass converges on the same values with no
 * harm from the first.
 *
 * OPS NOTE — RE-SCORE SUGGESTIONS AFTER THIS RUNS: `tblSongLinkSuggestions`
 * (the batch fuzzy-duplicate scorer, includes/tools/build-song-link-
 * suggestions.php) was scored under the OLD fold; those scores go stale the
 * moment titles/lyrics start refolding non-empty, and go MORE useful still
 * once #1908 Commit 3 fixes `ihymns_sim_fold()` (the separate FUZZY-compare
 * fold, rule #22 — this card never touches it). Re-run
 * `includes/tools/build-song-link-suggestions.php` once this card has landed
 * on the shared DB (ideally after Commit 3 too) so suggestion scores refresh.
 * This is harmless to defer: `tblSongLinkSuggestions` rows are human-reviewed
 * suggestions with a Dismiss path — no destructive action keys off a stale
 * score. Separately, `tblIaFetchCache`-family stored `NormTitle` rows are
 * DELIBERATELY not touched by this card: that scorer recomputes live from
 * `RawTitle` (includes/ia_reconcile.php:733/737), so those refresh on the
 * next reconcile run with no backfill needed.
 *
 * IDEMPOTENT + RE-RUNNABLE BY DESIGN, sentinel-short-circuited (same shape
 * as migrate-email-login-token-hashing.php, the registry probe's own cited
 * model): the FIRST thing this script does after connecting is check
 * whether `tblAppSettings.search_fold_version` is already `'2'` — if so it
 * prints `[skip]` and returns immediately, touching zero rows. This is
 * deliberately DIFFERENT from migrate-song-normalized-title.php's "always
 * recompute, converges deterministically" shape: that migration has no
 * cheap way to know it is already done short of re-walking the corpus,
 * whereas this one's whole JOB is "convert every fold-v1 value to fold-v2
 * once", so a completion sentinel is the correct, cheap, house-standard way
 * to make a second run a true no-op rather than a repeat O(all songs) pass.
 * If the fold function is ever revised again in the future (a hypothetical
 * fold v3), that would ship as its OWN new migration with its OWN sentinel
 * value — this script never needs to distinguish "v2" from "v3", only
 * "have I already run".
 *
 * GATE (rule #19 — a probe must detect REAL completion, never `=> true`):
 * this card recomputes `NormalizedTitle`, so it refuses to run before that
 * column exists (it would otherwise ALTER nothing and just throw on an
 * UPDATE against a missing column). On a column-less install it prints
 * `[SKIP]` and returns WITHOUT writing the `search_fold_version` sentinel —
 * the registry probe (below) therefore correctly reports this card as still
 * PENDING, exactly as rule #19 requires ("the probe must detect actual
 * completion… never a false positive"). `LyricsTextFolded` is a SEPARATE,
 * softer existence probe (rule #9): when present it is refolded too; when
 * absent it is silently skipped — `migrate-search-synonyms.php`'s own
 * backfill will fold it with the (by-then-current) fold on whatever install
 * adds that column later, so this card does not need to fail the whole run
 * over a column that is legitimately optional at this point in the codebase's
 * history.
 *
 * WRITE CAP (D6): NormalizedTitle writes are capped `mb_substr(…, 0, 500)` —
 * the column is VARCHAR(500) and `Normalizer::FORM_KD` can EXPAND a title
 * (a Hangul syllable decomposes into 2-3 jamo code points), so an uncapped
 * write could THROW under mysqli's MYSQLI_REPORT_STRICT on a long title.
 * LyricsTextFolded (MEDIUMTEXT) is intentionally left UNCAPPED — truncating
 * a whole lyrics body would corrupt the folded search snippet, not just
 * widen a candidate set the way a truncated title fold does.
 *
 * SCHEMA MIRROR: none — see PURPOSE above. This migration touches DATA only.
 *
 * OPERATOR VIEW: /manage/setup-database → card "Refold search columns
 * (fold v2, #1908)" → button "Run Refold Search Columns Migration".
 * Progress ticks every 500 rows; on the full corpus expect broadly the same
 * runtime as the original NormalizedTitle backfill (same O(all songs) shape),
 * which is why the execution-limit lift below matches that script's.
 *
 * @link https://www.php.net/manual/en/normalizer.normalize.php
 * @see includes/title_normalize.php        ihymns_search_fold() / ihymns_normalize_title() — the ONE fold this recomputes with
 * @see appWeb/.sql/migrate-song-normalized-title.php  the house pattern this migration is modelled on (Id-keyed loop, 500-row progress ticks, the _mig*_output helper)
 * @see appWeb/.sql/migrate-search-synonyms.php        the IHYMNS_INCLUDES_DIR runner-aware include idiom (rule #41) this reuses verbatim
 * @see .claude/unicode-nonlatin-1908-plan.md           §2 (this commit's spec) and §8 point 2 (the cross-docroot ordering hazard)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-refold-search-columns.php
 *   Web:  /manage/setup-database → "Refold search columns (fold v2, #1908)" button
 *
 * @requires PHP 8.1+ with mysqli; ext-intl recommended (see title_normalize.php
 *           for the guarded fallback when Normalizer is unavailable)
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* The refold walks every song (same shape as the NormalizedTitle backfill it
   is modelled on); lift PHP's execution cap and survive a curator navigating
   away mid-run. (The setup dashboard sets these too; belt-and-braces for a
   direct CLI run.) */
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ignore_user_abort(true);

/**
 * ELI5: prints one line of progress, whether we're on the command line or in
 * a browser tab.
 *
 * DETAILED: mirrors every sibling migration's `_mig*_output()` helper
 * (e.g. migrate-song-normalized-title.php's `_migNormTitle_output()`) — CLI
 * gets a bare newline, the web dashboard gets a `<br>` plus an explicit
 * `flush()` so the operator watching /manage/setup-database sees the ticker
 * move instead of one dump at the very end.
 */
function _migRefold_output(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) {
        flush();
    }
}

/**
 * ELI5: "does this table already have that column?" — asked without
 * guessing, straight from MySQL's own catalogue.
 *
 * DETAILED: INFORMATION_SCHEMA.COLUMNS is the portable existence probe used
 * throughout appWeb/.sql (e.g. migrate-add-creditpeople-date-precision.php);
 * both `$t`/`$c` here are always HARDCODED call-site literals ('tblSongs',
 * 'NormalizedTitle', 'LyricsTextFolded'), never request input, but they are
 * still bound via `bind_param` rather than interpolated — matching rule #5's
 * blanket "every value that enters a SQL string is bound" rather than
 * carving out an exception for values that merely happen to be safe today.
 *
 * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html
 */
function _migRefold_columnExists(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migRefold_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migRefold_output("");
_migRefold_output("=== iHymns — Refold search columns (fold v2, #1908) ===");
_migRefold_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migRefold_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migRefold_output("Connected to MySQL: " . DB_NAME);

try {
    /* Sentinel-driven short-circuit (mirrors migrate-email-login-token-
       hashing.php, the exact idiom manage/includes/migration-registry.php's
       probe for this card is modelled on). Checking this FIRST — before the
       gate, before resolving title_normalize.php, before touching a single
       song row — is what makes a second run a true O(1) no-op instead of a
       repeat O(all songs) walk that would just re-derive values already on
       disk. */
    $sentinelKey = 'search_fold_version';
    $check = $mysql->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ? LIMIT 1');
    $check->bind_param('s', $sentinelKey);
    $check->execute();
    $sentinelRow = $check->get_result()->fetch_row();
    $check->close();
    if ($sentinelRow && (string)$sentinelRow[0] === '2') {
        _migRefold_output("  [skip] sentinel search_fold_version=2 already set — nothing to do.");
        _migRefold_output("");
        _migRefold_output("Migration complete (no-op).");
        $mysql->close();
        return;
    }

    _migRefold_output("");
    _migRefold_output("--- Gate: tblSongs.NormalizedTitle must already exist ---");

    /* This card recomputes NormalizedTitle — it cannot run before the column
       does. Exiting HERE, before the sentinel write below, is what keeps the
       registry probe (manage/includes/migration-registry.php) correctly
       PENDING on a column-less install rather than silently reporting
       "applied" for work that never happened (rule #19). */
    if (!_migRefold_columnExists($mysql, 'tblSongs', 'NormalizedTitle')) {
        _migRefold_output("  [SKIP] run the NormalizedTitle card first.");
        _migRefold_output("");
        _migRefold_output("Gate not satisfied — no rows touched, sentinel NOT written.");
        _migRefold_output("Run the \"NormalizedTitle dedup column (#1066)\" card, then re-run this one.");
        $mysql->close();
        return;
    }
    _migRefold_output("  [OK] tblSongs.NormalizedTitle present.");

    /* LyricsTextFolded is a SOFTER, existence-probed extra (rule #9): refold
       it when present, skip it (not the whole card) when absent. It ships in
       the same schema.sql CREATE TABLE as NormalizedTitle today, so on a
       fresh/current install this is always true — the branch exists for an
       install that has run an OLDER search-synonyms card snapshot, or any
       future re-ordering, without throwing under MYSQLI_REPORT_STRICT on a
       column that legitimately might not be there yet. */
    $hasLyricsFolded = _migRefold_columnExists($mysql, 'tblSongs', 'LyricsTextFolded');
    _migRefold_output($hasLyricsFolded
        ? "  [OK] tblSongs.LyricsTextFolded present — will be refolded too."
        : "  [SKIP] tblSongs.LyricsTextFolded not present yet — refolding NormalizedTitle only this run.");

    /* Resolve the shared fold function deploy-agnostically (rule #41):
       IHYMNS_INCLUDES_DIR is defined by the setup-database runner (=
       <real-docroot>/includes on whichever channel this runs on — the
       docroot folder itself is renamed per channel, public_html_dev/_beta),
       with the literal public_html path as the standalone/CLI/test fallback
       ONLY. This is a plain variable ASSIGNMENT, not a column-0 require of a
       '/public_html/' literal, so it is exactly the shape
       tests/php/test-deploy-paths.php's Pass B allows (and
       migrate-search-synonyms.php already uses verbatim). */
    $_incDir = defined('IHYMNS_INCLUDES_DIR')
        ? IHYMNS_INCLUDES_DIR
        : dirname(__DIR__) . '/public_html/includes';
    require_once $_incDir . '/title_normalize.php';

    _migRefold_output("");
    _migRefold_output("--- Refold (re-runnable; recomputes every row with the CURRENT fold) ---");

    /* Row-by-row rather than set-based — ihymns_search_fold() is a PHP
       function MySQL cannot express, the same reason NormalizedTitle is a
       plain column rather than GENERATED (see migrate-song-normalized-
       title.php's doc-block). $sel is a BUFFERED result (mysqli's default),
       so the whole id+title(+lyrics) list is pulled client-side before the
       UPDATE loop starts and the two statements never contend on one
       connection (https://www.php.net/manual/en/mysqli.query.php). Only the
       columns this card actually folds are selected — never a whole-song
       SELECT * — so this stays a scoped per-row pass, not the whole-corpus
       materialisation CLAUDE.md rule #17 forbids. */
    $selectCols = $hasLyricsFolded ? 'Id, Title, LyricsText' : 'Id, Title';
    $sel = $mysql->query("SELECT {$selectCols} FROM tblSongs");
    $upd = $hasLyricsFolded
        ? $mysql->prepare('UPDATE tblSongs SET NormalizedTitle = ?, LyricsTextFolded = ? WHERE Id = ?')
        : $mysql->prepare('UPDATE tblSongs SET NormalizedTitle = ? WHERE Id = ?');

    $count = 0;
    $changed = 0;
    while ($row = $sel->fetch_assoc()) {
        /* D6 — truncate by CHARACTER, not byte: mysqli runs under
           MYSQLI_REPORT_STRICT, so an over-length VARCHAR(500) write would
           THROW rather than silently truncate, aborting the whole refold on
           one long title. Normalizer::FORM_KD can EXPAND a title (a Hangul
           syllable decomposes into 2-3 jamo code points), so this cap is not
           merely defensive — it is reachable on real non-Latin input.
           Cutting the fold short is harmless: this column is only an indexed
           search pre-filter, never the value compared for the final match. */
        $nt = mb_substr(ihymns_search_fold((string)$row['Title']), 0, 500);
        $id = (int)$row['Id'];

        if ($hasLyricsFolded) {
            /* LyricsTextFolded is MEDIUMTEXT — deliberately left UNCAPPED
               (D6): truncating a whole lyrics body would corrupt the folded
               search snippet, not just widen a candidate set. */
            $lf = ihymns_search_fold((string)$row['LyricsText']);
            $upd->bind_param('ssi', $nt, $lf, $id);
        } else {
            $upd->bind_param('si', $nt, $id);
        }
        $upd->execute();
        $count++;
        if ($upd->affected_rows > 0) {
            $changed++;
        }
        if ($count % 500 === 0) {
            _migRefold_output("  …{$count} rows processed");
        }
    }
    $upd->close();
    _migRefold_output("  [OK] Refolded {$count} song(s) ({$changed} row(s) actually changed value).");

    _migRefold_output("");
    _migRefold_output("--- Sentinel ---");

    /* ELI5: stamps a "fold v2 done" sticky note into the settings table so
       the dashboard card can tell it already ran without re-scanning every
       song.
       DETAILED: the email-login-token-hashing sentinel idiom (rule #19,
       manage/includes/migration-registry.php:1418-1426) — an
       INSERT … ON DUPLICATE KEY UPDATE against the PRIMARY KEY SettingKey
       makes this write idempotent regardless of whether a prior run already
       created the row. Bound via prepare/bind_param even though every value
       here is a hardcoded literal (rule #5's house convention — see
       migrate-add-audio-signing-setting.php for the identical shape). This
       write is reached ONLY after the refold loop above completes, so its
       presence really does mean "every row was walked with the fold that was
       live on THIS docroot at the time" — which is exactly what the
       registry probe (below) needs to be true. Reuses $sentinelKey from the
       short-circuit check above (same constant, same row). */
    $sentinelVal  = '2';
    /* Description is VARCHAR(255) — kept short deliberately; the full WHY
       lives in this file's doc-block, not duplicated here. */
    $sentinelDesc = 'Fold version stamped into NormalizedTitle/LyricsTextFolded (#1908 C2).'
                  . ' "2" = Unicode-preserving Normalizer::FORM_KD fold (was iconv-lossy).';
    $set = $mysql->prepare(
        'INSERT INTO tblAppSettings (SettingKey, SettingValue, Description)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue), Description = VALUES(Description)'
    );
    $set->bind_param('sss', $sentinelKey, $sentinelVal, $sentinelDesc);
    $set->execute();
    $set->close();
    _migRefold_output("  [OK] tblAppSettings.search_fold_version = 2.");

    _migRefold_output("");
    _migRefold_output("--- Ops reminders (see this file's doc-block for the full explanation) ---");
    _migRefold_output("  1. Only run this card on the shared DB after Commit 1 (the fold fix) has");
    _migRefold_output("     been deployed to ALL THREE docroots (main / beta / alpha) — an older");
    _migRefold_output("     docroot still on the iconv-lossy fold would keep re-staling rows it saves.");
    _migRefold_output("  2. Re-run includes/tools/build-song-link-suggestions.php afterwards so");
    _migRefold_output("     tblSongLinkSuggestions scores refresh under the new fold (harmless to defer —");
    _migRefold_output("     those are human-reviewed suggestions with a Dismiss path).");
    _migRefold_output("");
    _migRefold_output("Migration complete.");
} catch (\Throwable $e) {
    _migRefold_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
