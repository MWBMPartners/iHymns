<?php

declare(strict_types=1);

/**
 * iHymns — DM-2 recovery: revert derived per-song rights facts (#1769 P4)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Undo migrate-derive-rights-facts.php. For each song whose rights-fact column
 * holds a licence key AND that song carries the matching require_licence
 * restriction that key came from, this clears the column back to NULL — exactly
 * the rows the derive pass could have set. A curator value that does NOT match a
 * restriction is left alone; a curator value that coincidentally equals the
 * restriction key is cleared too (a recovery tool errs toward clearing the
 * derived data — re-set it by hand if needed).
 *
 * CLI-ONLY (a deliberate recovery action, never a dashboard card).
 *
 * USAGE:  php appWeb/.sql/revert-derive-rights-facts.php
 *
 * @requires PHP 8.1+ with mysqli
 * @link .claude/gating-p4-design.md §"Commit D"
 * @see appWeb/.sql/migrate-derive-rights-facts.php  the forward pass this reverts
 * @see appWeb/public_html/includes/licence_registry.php  rightsFactColumnForLicence() — the ONE fold
 * @see #1769
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "This recovery tool runs from the CLI only.\n";
    return;
}
@set_time_limit(0);

function _revDeriveRights_out(string $m): void { echo $m . "\n"; }
function _revDeriveRights_colExists(\mysqli $db, string $t, string $c): bool {
    $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ok;
}
function _revDeriveRights_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _revDeriveRights_out("ERROR: MySQL credentials not found."); return; }
require_once $credFile;
require_once dirname(__DIR__) . '/public_html/includes/licence_registry.php';   // the ONE fold

_revDeriveRights_out("");
_revDeriveRights_out("=== iHymns — #1769 P4 DM-2 RECOVERY: revert derived rights facts ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _revDeriveRights_out("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_revDeriveRights_out("Connected to MySQL: " . DB_NAME);

try {
    if (!_revDeriveRights_colExists($mysql, 'tblSongs', 'LyricsRightsLicenceKey')
        || !_revDeriveRights_colExists($mysql, 'tblSongs', 'MusicRightsLicenceKey')
        || !_revDeriveRights_tableExists($mysql, 'tblContentRestrictions')) {
        _revDeriveRights_out("  [SKIP] Fact columns or tblContentRestrictions absent — nothing to revert.");
        $mysql->close();
        return;
    }

    $keyToCol = [];
    foreach (licenceTypesAll($mysql) as $key => $def) {
        $col = rightsFactColumnForLicence($def['covers'] ?? null);
        if ($col !== null) { $keyToCol[$key] = $col; }
    }

    $cleared = ['LyricsRightsLicenceKey' => 0, 'MusicRightsLicenceKey' => 0];
    foreach ($keyToCol as $key => $col) {
        /* $col is a hard-coded fact-column constant from the shared fold; $key is bound. */
        $u = $mysql->prepare(
            "UPDATE tblSongs s
               JOIN tblContentRestrictions r
                 ON r.EntityType = 'song' AND r.EntityId = s.SongId
                AND r.RestrictionType = 'require_licence' AND r.TargetId = ?
                SET s.`{$col}` = NULL
              WHERE s.`{$col}` = ?"
        );
        $u->bind_param('ss', $key, $key);
        $u->execute();
        $cleared[$col] += $u->affected_rows;
        $u->close();
    }

    _revDeriveRights_out("  [OK] Cleared " . $cleared['LyricsRightsLicenceKey'] . " lyrics + "
        . $cleared['MusicRightsLicenceKey'] . " music derived rights fact(s) back to NULL.");
} catch (\Throwable $e) {
    _revDeriveRights_out("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
