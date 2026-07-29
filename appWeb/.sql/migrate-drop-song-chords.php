<?php

declare(strict_types=1);

/**
 * iHymns — DROP the unused tblSongChords table (#1613)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblSongChords (#299) was a per-component `{position, chord}` JSON payload table. It has
 * ZERO PHP and ZERO JS runtime references — chord notation now lives per-line on
 * tblLyricLines.ChordsJson (rule #21/#25 of .claude/CLAUDE.md). The only two places that
 * ever modelled a positioned-chord pair were this table and js/utils/transpose.js; both
 * were dead code with no callers. The JS utility was deleted in #1612 — this migration is
 * the matching table drop, closing out #1613.
 *
 * ⚠ DESTRUCTIVE — but self-protecting. This script cannot see the live database the
 * #1613 zero-reference analysis was run against, so it REFUSES to drop unless the live
 * table is verifiably EMPTY (`SELECT COUNT(*) FROM tblSongChords` = 0). A non-zero count
 * means some write path the grep missed still exists on this install, so dropping would
 * silently discard data — the migration bails out with a loud warning instead. This is the
 * same abort-don't-drop shape as migrate-retire-component-lines-json.php's Stage 0, scaled
 * down to the one check that matters for a table with no known writers.
 *
 * Requires explicit confirmation — CLI `--confirm` / web `?confirm=1` — same convention as
 * this folder's other destructive scripts (restore.php, drop-legacy-tables.php). Without
 * it, the script only prints what it WOULD do and exits without touching the database.
 *
 * Idempotent: guarded on the table still existing, so a second run (e.g. a stray "Apply
 * all pending" — though this migration is marked `manual` and excluded from that path) is
 * a clean no-op.
 *
 * @migration-drops tblSongChords
 *
 * USAGE:
 *   CLI dry-run: php appWeb/.sql/migrate-drop-song-chords.php
 *   CLI execute: php appWeb/.sql/migrate-drop-song-chords.php --confirm
 *   Web:         /manage/setup-database.php?action=drop-song-chords&confirm=1
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (PHP_SAPI === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migDropChords_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

/** Table-existence probe (idempotency guard — mirrors the retire-lines-json script's
 *  local `_migRetire_colExists()` pattern, just against TABLES instead of COLUMNS). */
function _migDropChords_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migDropChords_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migDropChords_out('=== iHymns — DROP tblSongChords (#1613) ===');

try {
    /* --- Idempotency: already gone? --- */
    if (!_migDropChords_tableExists($db, 'tblSongChords')) {
        _migDropChords_out('  [SKIP] tblSongChords already gone — nothing to do.');
        _migDropChords_out('Done (already dropped).');
        return;
    }

    /* --- Abort-don't-drop: refuse unless the live table is EMPTY. Runs unconditionally,
       BEFORE the confirm gate below, so even a confirmed run can never discard rows. --- */
    $countRes = $db->query('SELECT COUNT(*) FROM tblSongChords');
    $rowCount = (int)$countRes->fetch_row()[0];
    $countRes->close();

    if ($rowCount > 0) {
        _migDropChords_out("  [REFUSE] tblSongChords has {$rowCount} row(s) on this install — NOT dropping.");
        _migDropChords_out('           The #1613 zero-reference analysis (no PHP/JS callers found by grep) does');
        _migDropChords_out('           NOT hold here if the table has data — some write path exists that the');
        _migDropChords_out('           audit missed. Investigate before re-running, e.g.:');
        _migDropChords_out('             SELECT * FROM tblSongChords LIMIT 20;');
        return;
    }
    _migDropChords_out('  [OK] tblSongChords is empty (0 rows) — safe to drop.');

    /* --- Confirm gate (web ?confirm=1 / CLI --confirm), matching restore.php /
       drop-legacy-tables.php's convention. --- */
    $confirmed = false;
    if ($isCli) {
        foreach ($argv ?? [] as $arg) {
            if ($arg === '--confirm') { $confirmed = true; }
        }
    } else {
        $confirmed = (($_GET['confirm'] ?? '') === '1');
    }

    if (!$confirmed) {
        _migDropChords_out('  Would drop: tblSongChords (0 rows; zero PHP/JS references — #299, superseded by');
        _migDropChords_out('  tblLyricLines.ChordsJson, rule #21/#25 of .claude/CLAUDE.md).');
        _migDropChords_out('');
        _migDropChords_out('DRY RUN — nothing has been changed.');
        _migDropChords_out('Re-run with --confirm (CLI) or ?confirm=1 (web) to proceed.');
        return;
    }

    /* --- Drop --- */
    $db->query('DROP TABLE tblSongChords');
    _migDropChords_out('  [OK] Dropped tblSongChords.');
    _migDropChords_out('');
    _migDropChords_out('Done.');
} catch (\Throwable $e) {
    _migDropChords_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
