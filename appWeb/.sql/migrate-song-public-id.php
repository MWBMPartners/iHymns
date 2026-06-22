<?php

declare(strict_types=1);

/**
 * iHymns — Song PublicId / IHUID (#1343-B)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds tblSongs.PublicId — an opaque, location-independent canonical permalink id
 * (Crockford base32, 10 chars, uppercase) so a shared /song/<id> link survives the
 * song moving songbook or being renumbered. The SongId (<Abbreviation>-<number>)
 * stays the PRIMARY KEY (rule #27); PublicId is purely additive.
 *
 * Three IDEMPOTENT stages, all bound (rule #5):
 *   1. ADD COLUMN PublicId nullable (instant metadata op) — guarded.
 *   2. Backfill rows WHERE PublicId IS NULL with a unique generated code (re-runnable;
 *      rows minted at create already have one). In-run dedupe + dup-key reroll.
 *   3. ADD UNIQUE uniq_PublicId AFTER the backfill — guarded.
 * Re-running is a no-op once every row has a PublicId and the UNIQUE exists.
 *
 * @migration-adds tblSongs.PublicId
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-public-id.php
 *   Web:  /manage/setup-database → "Song PublicId (permalink id)" button (re-run until done)
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSP_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSP_columnExists(\mysqli $db, string $t, string $c): bool
{
    $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->bind_param('ss', $t, $c); $st->execute();
    $ok = $st->get_result()->fetch_row() !== null; $st->close(); return $ok;
}
function _migSP_indexExists(\mysqli $db, string $t, string $idx): bool
{
    $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
    $st->bind_param('ss', $t, $idx); $st->execute();
    $ok = $st->get_result()->fetch_row() !== null; $st->close(); return $ok;
}
/** Crockford-ish base32, len 10 — mirrors includes/song_public_id.php. */
function _migSP_generate(): string
{
    $a = 'ABCDEFGHJKMNPQRSTVWXYZ23456789'; $max = strlen($a) - 1; $out = '';
    for ($i = 0; $i < 10; $i++) { $out .= $a[random_int(0, $max)]; }
    return $out;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSP_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSP_output("");
_migSP_output("=== iHymns — Song PublicId / IHUID (#1343-B) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSP_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSP_output("Connected to MySQL: " . DB_NAME);

try {
    /* Stage 1 — add the column. */
    if (_migSP_columnExists($mysql, 'tblSongs', 'PublicId')) {
        _migSP_output("  [SKIP] tblSongs.PublicId already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongs
                ADD COLUMN PublicId VARCHAR(16) NULL DEFAULT NULL
                COMMENT 'Opaque stable permalink id (IHUID, #1343-B); Crockford base32, uppercase, no I/L/O/U/0/1. Location-independent — survives songbook move/renumber. SongId stays the PK; this is additive.'
                AFTER SongId"
        );
        _migSP_output("  [OK] Added tblSongs.PublicId.");
    }

    /* Stage 2 — backfill rows that have no PublicId yet (re-runnable). */
    $seen = [];
    $upd = $mysql->prepare('UPDATE tblSongs SET PublicId = ? WHERE SongId = ? AND PublicId IS NULL');
    $filled = 0; $batches = 0;
    while (true) {
        $res = $mysql->query("SELECT SongId FROM tblSongs WHERE PublicId IS NULL LIMIT 500");
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        if ($res) { $res->free(); }
        if (empty($rows)) { break; }
        $batches++;
        foreach ($rows as $r) {
            $sid = (string)$r['SongId'];
            /* Roll a code unused in this run; catch a UNIQUE clash (1062) if the
               index is already present from a prior partial run. */
            for ($try = 0; $try < 12; $try++) {
                $code = _migSP_generate();
                if (isset($seen[$code])) { continue; }
                try {
                    $upd->bind_param('ss', $code, $sid);
                    $upd->execute();
                    if ($upd->affected_rows > 0) { $seen[$code] = true; $filled++; }
                    break;
                } catch (\mysqli_sql_exception $e) {
                    if ($e->getCode() === 1062) { continue; } /* dup PublicId — reroll */
                    throw $e;
                }
            }
        }
        if ($batches > 1000) { _migSP_output("  [WARN] backfill exceeded 1000 batches — stopping; re-run to continue."); break; }
    }
    $upd->close();
    _migSP_output("  [OK] Backfilled $filled row(s) across $batches batch(es).");

    /* Stage 3 — add the UNIQUE index once everything is filled. */
    $remaining = 0;
    if ($rc = $mysql->query("SELECT COUNT(*) AS n FROM tblSongs WHERE PublicId IS NULL")) {
        $remaining = (int)($rc->fetch_assoc()['n'] ?? 0); $rc->free();
    }
    if ($remaining > 0) {
        _migSP_output("  [INFO] $remaining row(s) still without a PublicId — re-run this migration to finish before the UNIQUE index is added.");
    } elseif (_migSP_indexExists($mysql, 'tblSongs', 'uniq_PublicId')) {
        _migSP_output("  [SKIP] uniq_PublicId index already present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD UNIQUE KEY uniq_PublicId (PublicId)");
        _migSP_output("  [OK] Added UNIQUE index uniq_PublicId.");
    }

    _migSP_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migSP_output("ERROR: " . $e->getMessage());
}
