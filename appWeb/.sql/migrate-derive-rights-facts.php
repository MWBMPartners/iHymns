<?php

declare(strict_types=1);

/**
 * iHymns — DM-2: derive per-song rights FACTS from existing require_licence restrictions (#1769 P4)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Before Model 2, "this song needs a CCLI licence to view" was recorded as a
 * ROW in tblContentRestrictions (RestrictionType='require_licence', the licence
 * key in TargetId). Model 2 reads a per-song FACT column instead
 * (tblSongs.LyricsRightsLicenceKey / MusicRightsLicenceKey). This one-shot,
 * fill-NULL-only pass copies each song's existing require_licence signal INTO
 * the matching fact column, so the new resolver sees exactly what the old
 * restriction rows implied. It never overwrites a fact a curator already set,
 * and it is safe to re-run (a second run finds nothing left to derive — the
 * registry probe re-checks the same thing, so it doubles as a drift detector).
 *
 * DETAILED / THE MAPPING IS THE SHARED FOLD
 * ----------------------------------------------------------------------------
 * Which column a licence maps to is decided by rightsFactColumnForLicence()
 * (includes/licence_registry.php) from the licence's COVERAGE set — never a
 * second copy here (rule #22/#35), so the migration and its completeness probe
 * can never disagree:
 *   lyrics_display / lyrics_print   -> LyricsRightsLicenceKey
 *   music_reproduction              -> MusicRightsLicenceKey
 *   neither (plan-conferral, audio) -> no fact column (the licence gates via a
 *                                      tier cap, not a per-song rights fact) — REPORTED, not derived.
 *
 * Licences are processed in registry (SortOrder) order, so when a song has two
 * require_licence restrictions that map to the SAME column, the winner is
 * deterministic (the first licence in registry order fills the NULL; a later
 * one sees the now-non-NULL column and is skipped by the IS NULL clause). Songs
 * whose fact column is ALREADY set to a different key are counted + reported as
 * "coexisting" and left untouched — the curator's explicit value always wins.
 *
 * WHY DATA-ONLY (no schema.sql edit — D6): no CREATE TABLE, no ALTER — only a
 * fill-NULL-only UPDATE JOIN per licence into columns the P1 batch already
 * created. Mirrors migrate-reconcile-isrc-denorm.php's declared posture; CI's
 * test-schema-coverage.php stays green (no schema object to attribute).
 *
 * GATED. No-ops with a clear message (never an error) when the fact columns
 * aren't migrated yet (P1 not applied) or tblContentRestrictions is absent.
 * DORMANT: the derived fact ENFORCES nothing until #1769 P6 flips the master
 * switch — this only makes the new read path agree with the old data.
 *
 * RECOVERY: appWeb/.sql/revert-derive-rights-facts.php (CLI) clears the two fact
 * columns back to NULL for rows this pass could have set.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-derive-rights-facts.php
 *   Web:  /manage/setup-database -> "Derive rights facts from restrictions (#1769 P4)"
 *   Prerequisite: the "Gating facts + licence-type registry (#1769 P1)" card.
 *
 * @requires PHP 8.1+ with mysqli
 * @link .claude/gating-p4-design.md §"Commit D"
 * @see appWeb/public_html/includes/licence_registry.php  rightsFactColumnForLicence() — the ONE fold
 * @see appWeb/public_html/manage/includes/migration-registry.php  'derive-rights-facts' entry + probe
 * @see appWeb/.sql/revert-derive-rights-facts.php  the recovery tool
 * @see #1769
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
if ($isCli) { @set_time_limit(0); }

function _migDeriveRights_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) { flush(); } }
function _migDeriveRights_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migDeriveRights_colExists(\mysqli $db, string $t, string $c): bool {
    $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ok;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migDeriveRights_out("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

/* The ONE mapping fold + registry reader — never re-derived here (rule #22).
   Resolve includes/ via the runner's real docroot: the deployed docroot is
   renamed per channel (public_html_dev/_beta), so a literal
   '/public_html/includes/…' is WRONG off main (the #1196 deploy-path trap).
   Repo fallback for a standalone/CLI or test run. */
$_incDir = defined('IHYMNS_INCLUDES_DIR')
    ? IHYMNS_INCLUDES_DIR
    : dirname(__DIR__) . '/public_html/includes';
require_once $_incDir . '/licence_registry.php';

_migDeriveRights_out("");
_migDeriveRights_out("=== iHymns — #1769 P4 DM-2: derive per-song rights facts from require_licence restrictions ===");
_migDeriveRights_out("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migDeriveRights_out("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migDeriveRights_out("Connected to MySQL: " . DB_NAME);

try {
    /* Prerequisite gates — no-op cleanly, never throw. */
    if (!_migDeriveRights_colExists($mysql, 'tblSongs', 'LyricsRightsLicenceKey')
        || !_migDeriveRights_colExists($mysql, 'tblSongs', 'MusicRightsLicenceKey')) {
        _migDeriveRights_out("  [SKIP] The per-song rights-fact columns do not exist yet.");
        _migDeriveRights_out("         Run the \"Gating facts + licence-type registry (#1769 P1)\" card first, then re-run this one.");
        $mysql->close();
        return;
    }
    if (!_migDeriveRights_tableExists($mysql, 'tblContentRestrictions')) {
        _migDeriveRights_out("  [SKIP] tblContentRestrictions does not exist — nothing to derive from.");
        $mysql->close();
        return;
    }

    /* Build the {licence key => fact column} map from the LIVE registry via the
       ONE shared fold, in registry (SortOrder) order for a deterministic winner
       when a song has multiple same-column restrictions. */
    $keyToCol = [];
    $skipped  = [];
    foreach (licenceTypesAll($mysql) as $key => $def) {
        $col = rightsFactColumnForLicence($def['covers'] ?? null);
        if ($col === null) { $skipped[] = $key; continue; }
        $keyToCol[$key] = $col;   // 'LyricsRightsLicenceKey' | 'MusicRightsLicenceKey'
    }

    if ($keyToCol === []) {
        _migDeriveRights_out("  [OK] No licence type maps to a rights fact (none cover lyrics/music). Nothing to derive.");
        if ($skipped) { _migDeriveRights_out("       Licences with no fact column (gated via tier only): " . implode(', ', $skipped)); }
        $mysql->close();
        return;
    }

    $appliedByCol   = ['LyricsRightsLicenceKey' => 0, 'MusicRightsLicenceKey' => 0];
    $coexistingByCol = ['LyricsRightsLicenceKey' => 0, 'MusicRightsLicenceKey' => 0];

    foreach ($keyToCol as $key => $col) {
        /* $col is ALWAYS one of the two hard-coded fact-column constants the
           shared fold returns — never user input — so interpolating it into the
           SET/WHERE is rule-#5-legitimate (a fixed identifier from PHP source);
           the licence KEY is always bound. */

        /* Coexisting: songs that carry this require_licence restriction but whose
           fact column is ALREADY a DIFFERENT key — left untouched, reported. */
        $c = $mysql->prepare(
            "SELECT COUNT(DISTINCT s.SongId)
               FROM tblSongs s
               JOIN tblContentRestrictions r
                 ON r.EntityType = 'song' AND r.EntityId = s.SongId
                AND r.RestrictionType = 'require_licence' AND r.TargetId = ?
              WHERE s.`{$col}` IS NOT NULL AND s.`{$col}` <> ?"
        );
        $c->bind_param('ss', $key, $key);
        $c->execute();
        $coexistingByCol[$col] += (int)($c->get_result()->fetch_row()[0] ?? 0);
        $c->close();

        /* Fill-NULL-only derive: set the fact from the restriction ONLY where the
           column is still NULL (never overwrite a curator's explicit value, and
           a later same-column licence sees the now-non-NULL column and skips). */
        $u = $mysql->prepare(
            "UPDATE tblSongs s
               JOIN tblContentRestrictions r
                 ON r.EntityType = 'song' AND r.EntityId = s.SongId
                AND r.RestrictionType = 'require_licence' AND r.TargetId = ?
                SET s.`{$col}` = ?
              WHERE s.`{$col}` IS NULL"
        );
        $u->bind_param('ss', $key, $key);
        $u->execute();
        $appliedByCol[$col] += $u->affected_rows;
        $u->close();
    }

    _migDeriveRights_out("  [OK] Lyrics rights derived onto " . $appliedByCol['LyricsRightsLicenceKey'] . " song(s); "
        . "music rights onto " . $appliedByCol['MusicRightsLicenceKey'] . " song(s).");
    if ($coexistingByCol['LyricsRightsLicenceKey'] > 0 || $coexistingByCol['MusicRightsLicenceKey'] > 0) {
        _migDeriveRights_out("  [INFO] Left untouched (a different rights key already set): "
            . $coexistingByCol['LyricsRightsLicenceKey'] . " lyrics + "
            . $coexistingByCol['MusicRightsLicenceKey'] . " music song-field(s).");
    }
    if ($skipped) {
        _migDeriveRights_out("  [INFO] Licences with no rights fact (gated via tier cap only, not derived): " . implode(', ', $skipped));
    }

    /* Guarded activity log (setup-database logs card runs as setup.run without
       row counts; this adds the DM-2-specific summary — owner directive: log
       every gating action). function_exists-guarded: the CLI path has no
       activity_log loaded, the dashboard path does. */
    if (function_exists('logActivity')) {
        logActivity('admin.migration.derive-rights-facts', 'migration', 'derive-rights-facts', [
            'lyrics_applied'    => $appliedByCol['LyricsRightsLicenceKey'],
            'music_applied'     => $appliedByCol['MusicRightsLicenceKey'],
            'lyrics_coexisting' => $coexistingByCol['LyricsRightsLicenceKey'],
            'music_coexisting'  => $coexistingByCol['MusicRightsLicenceKey'],
            'skipped_licences'  => $skipped,
        ]);
    }

    _migDeriveRights_out("");
    _migDeriveRights_out("Derivation complete. Idempotent — safe to re-run (a second run finds nothing left).");
    _migDeriveRights_out("DORMANT: the derived fact enforces nothing until #1769 P6. Recovery: revert-derive-rights-facts.php.");
} catch (\Throwable $e) {
    _migDeriveRights_out("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
