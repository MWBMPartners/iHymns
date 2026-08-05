<?php

declare(strict_types=1);

/**
 * iHymns — Gating facts + licence-type registry (#1769 P1, Model 2)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The one-pass, additive, DORMANT schema batch for the Model-2 access-model
 * consolidation (#1769). Designed final-shape up front (rule #20 — ship a
 * feature family as ONE batch, never dribbled ALTERs), stress-tested against
 * "what would force a second migration?". Creates:
 *
 *   - tblLicenceTypes (#459, built at last) — THE licence-type vocabulary +
 *     per-type metadata, collapsing the six hardcoded licence-vocab sites. A
 *     licence type declares WHAT IT LEGALLY COVERS (CoversJson) and WHICH TIER
 *     IT CONFERS (ConfersTier — the previously hidden ccli_validator map, now a
 *     visible attribute). Seeded with ccli / mrl / ihymns_basic / ihymns_pro /
 *     custom. Absence of a licence is the ABSENCE of a row (no "none" row).
 *
 *   - Per-song rights-requirement FACTS: tblSongs.LyricsRightsLicenceKey /
 *     MusicRightsLicenceKey (+ indexes) — the licence a viewer must hold for
 *     gated lyric / music actions on a song. NULL = plan caps + PD flags govern.
 *
 *   - tblSongbooks.DefaultLyricsRightsLicenceKey / DefaultMusicRightsLicenceKey
 *     — EDITOR-DEFAULT prefill for the P4 Rights panel; NEVER resolver-read, so
 *     the per-song fact keeps its single meaning (no inheritance chase).
 *
 *   - tblSongArrangements.MusicRightsStatus / MusicRightsLicenceKey — RESERVED
 *     DORMANT arrangement-grain override (#1768 Q2; flips on later with zero
 *     migration).
 *
 *   - tblGatingCapabilities.EnforceJson — lets an admin-defined cap carry its
 *     own enforcement mapping so tblGatingRules can retire (#1769 P5).
 *
 *   - feature_gating_rules_enabled='0' in tblAppSettings (make-the-switch-visible
 *     seed, deferred here from #1769 P0; reads already default to 0).
 *
 * VARCHAR-not-ENUM (rule #20): every vocabulary (LicenceKey, coverage kinds in
 * CoversJson, MusicRightsStatus, Authority, Source, EnforceJson behaviour kinds)
 * is a growable app-validated allow-list, never a MySQL ENUM.
 *
 * DORMANT + ADDITIVE: nothing in the live tree reads any object here until
 * #1769 P2 wires the licence registry + resolver, and the whole family only
 * acts when tblAppSettings.content_gating_enabled='1' (rule #28 A). Applying
 * this batch on any docroot is a verified byte-identical no-op
 * (tests/php/test-gating-noop.php + /manage/gating-noop-verify).
 *
 * @migration-adds tblLicenceTypes
 * @migration-adds tblSongs.LyricsRightsLicenceKey
 * @migration-adds tblSongs.MusicRightsLicenceKey
 * @migration-adds tblSongbooks.DefaultLyricsRightsLicenceKey
 * @migration-adds tblSongbooks.DefaultMusicRightsLicenceKey
 * @migration-adds tblSongArrangements.MusicRightsStatus
 * @migration-adds tblSongArrangements.MusicRightsLicenceKey
 * @migration-adds tblGatingCapabilities.EnforceJson
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-gating-facts-and-licence-types.php
 *   Web:  /manage/setup-database → "Gating facts + licence-type registry (#1769 P1)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* Single output helper — legible in the terminal and the dashboard <pre> capture. */
function _migGFL_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

/* Idempotency probes — INFORMATION_SCHEMA with bound params (rule #5). */
function _migGFL_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}
function _migGFL_columnExists(\mysqli $db, string $t, string $c): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migGFL_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migGFL_output("");
_migGFL_output("=== iHymns — Gating facts + licence-type registry (#1769 P1) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migGFL_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migGFL_output("Connected to MySQL: " . DB_NAME);

try {
    /* ---- 1. tblLicenceTypes (#459) — DDL byte-identical to schema.sql (rule #19) ---- */
    if (_migGFL_tableExists($mysql, 'tblLicenceTypes')) {
        _migGFL_output("  [SKIP] tblLicenceTypes already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLicenceTypes (
                Id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                LicenceKey     VARCHAR(30)  NOT NULL COMMENT 'THE licence vocabulary token (#459/#1769) -- the value every licence store writes (tblOrganisations.LicenceType, tblOrganisationLicences.LicenceType, tblContentLicences.LicenceType) and every requirement fact names (tblSongs LyricsRightsLicenceKey/MusicRightsLicenceKey, require_licence restriction targets). Lowercase snake, grammar ^[a-z][a-z0-9_]{1,29}\$ app-validated in includes/licence_registry.php (P2) -- VARCHAR never ENUM (rule #20). Absence of a licence is the ABSENCE of a row, not a none row',
                Label          VARCHAR(100) NOT NULL COMMENT 'Curator-facing display name, e.g. CCLI, iHymns Pro',
                Description    VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Curator-facing hint text shown in pickers and on the admin Licences tab',
                ConfersTier    VARCHAR(30)  NULL DEFAULT NULL COMMENT 'tblAccessTiers.Name this licence ALSO grants its holders -- the now-visible licence-to-tier bridge that was the hidden hardcoded map in ccli_validator.php (#1769 OV-6). NULL = confers no tier. NO FK, degrade-safe like tblUsers.AccessTier: an unknown or renamed tier degrades to no conferral, never RESTRICT-fails',
                CoversJson     JSON         NULL DEFAULT NULL COMMENT 'Coverage SET this licence legally unlocks: object keyed by coverage kind -- lyrics_display | lyrics_print | music_reproduction | audio_playback (growable app-validated vocabulary, LICENCE_COVERAGE_KINDS in includes/licence_registry.php, P2; VARCHAR-in-JSON never ENUM, rule #20) -- value = per-kind qualifier object, {} = unqualified, RESERVED for future regional/time-box qualifiers so richer coverage never needs an ALTER. NULL = no legal coverage (a plan-conferral licence acts through ConfersTier only)',
                RequiresNumber TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = recording this licence needs a licence number entered (CCLI/MRL); drives P4 form validation only, never enforcement',
                Authority      VARCHAR(30)  NULL DEFAULT NULL COMMENT 'RESERVED (#1769 P1): issuing-body vocabulary -- ccli | onelicense | ihymns | ... (app-validated, VARCHAR never ENUM). NULL = unspecified. Nothing reads it yet; exists so a per-issuer picker grouping or validation route never needs an ALTER',
                AuthorityRef   VARCHAR(50)  NULL DEFAULT NULL COMMENT 'RESERVED (#1769 P1): the issuing-authority reference for this licence product (e.g. a CCLI product/programme code) -- free text, NULL = none. Dormant sibling of tblSongTags.CcliThemeId',
                Enabled        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = soft-disabled -- excluded from pickers and the P2 registry union but kept so historical org-licence rows keep resolving their label; hard-delete is refused while any store references the key (app-enforced -- no FK exists to enforce it)',
                SortOrder      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display order in pickers and on the admin Licences tab',
                Source         VARCHAR(20)  NOT NULL DEFAULT 'curator' COMMENT 'Provenance vocabulary -- system (shipped seed) | curator (admin-created); VARCHAR never ENUM (rule #20)',
                CreatedBy      INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who created this licence type (NULL for seeds)',
                UpdatedBy      INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who last edited it',
                CreatedAt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_LicenceTypes_Key      (LicenceKey),
                INDEX      idx_LicenceTypes_Enabled (Enabled, SortOrder),

                CONSTRAINT fk_LicenceTypes_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_LicenceTypes_UpdatedBy FOREIGN KEY (UpdatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='THE licence-type vocabulary + per-type metadata (#459, built by #1769 P1) -- collapses the six hardcoded licence-vocab sites. DORMANT until includes/licence_registry.php (P2) reads it; the legacy hardcoded maps survive as the un-migrated fallback.'"
        );
        _migGFL_output("  [OK] Created tblLicenceTypes.");
    }

    /* ---- 2. Seed the licence vocabulary (INSERT IGNORE — never clobbers curator edits) ---- */
    $seeded = $mysql->query(
        "INSERT IGNORE INTO tblLicenceTypes
            (LicenceKey, Label, Description, ConfersTier, CoversJson, RequiresNumber, Authority, AuthorityRef, Enabled, SortOrder, Source) VALUES
            ('ccli',         'CCLI',         'Christian Copyright Licensing International -- Church Copyright Licence. Covers displaying and printing copyrighted LYRICS for congregational use. Licence number required.', 'ccli',    '{\"lyrics_display\": {}, \"lyrics_print\": {}}', 1, 'ccli',   NULL, 1, 10, 'system'),
            ('mrl',          'MRL',          'Music Reproduction Licence (CCLI) -- covers reproducing the MUSICAL WORK: sheet music, chord charts, MusicXML, MIDI (#1768). Licence number required.',                       NULL,      '{\"music_reproduction\": {}}',                 1, 'ccli',   NULL, 1, 20, 'system'),
            ('ihymns_basic', 'iHymns Basic', 'Free iHymns plan licence -- public-domain songs only. Confers the free tier; carries no legal coverage of its own.',                                                          'free',    NULL,                                           0, 'ihymns', NULL, 1, 30, 'system'),
            ('ihymns_pro',   'iHymns Pro',   'Paid iHymns plan licence -- full catalogue access. Confers the premium tier; carries no legal coverage of its own.',                                                          'premium', NULL,                                           0, 'ihymns', NULL, 1, 40, 'system'),
            ('custom',       'Custom',       'Free-form licence recorded by an organisation. Coverage and tier conferral are set per install by a curator.',                                                                NULL,      NULL,                                           0, NULL,     NULL, 1, 50, 'system')"
    );
    $added = $mysql->affected_rows;
    _migGFL_output($added > 0 ? "  [OK] Seeded {$added} licence type(s)." : "  [SKIP] All licence-type seeds already present.");

    /* ---- 3-4. tblSongs per-song rights-requirement FACTS (+ indexes) ---- */
    if (_migGFL_columnExists($mysql, 'tblSongs', 'LyricsRightsLicenceKey')) {
        _migGFL_output("  [SKIP] tblSongs.LyricsRightsLicenceKey already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongs
                ADD COLUMN LyricsRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'Per-song LYRICS-rights requirement FACT (#1769 P1, Model 2): tblLicenceTypes.LicenceKey a viewer must hold (via their org licence set or Service-Mode presence) for gated lyric actions on this song. NULL = no per-song requirement — plan caps + LyricsPublicDomain alone govern. NO FK, degrade-safe like tblUsers.AccessTier: an unknown or retired key degrades to no-requirement, never blocks a save or read. DORMANT until the P2 resolver reads it' AFTER MusicPublicDomain,
                ADD INDEX idx_LyricsRightsLicence (LyricsRightsLicenceKey)"
        );
        _migGFL_output("  [OK] Added tblSongs.LyricsRightsLicenceKey (+ index).");
    }
    if (_migGFL_columnExists($mysql, 'tblSongs', 'MusicRightsLicenceKey')) {
        _migGFL_output("  [SKIP] tblSongs.MusicRightsLicenceKey already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongs
                ADD COLUMN MusicRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'Per-song MUSIC-rights requirement FACT (#1769 P1 / #1768): tblLicenceTypes.LicenceKey required for music-reproduction actions (chord display, sheet music, MusicXML, MIDI — plan Q3: MIDI counts). NULL = no per-song requirement — plan caps + MusicPublicDomain alone govern. NO FK (degrade-safe, see LyricsRightsLicenceKey). Song-grain first (#1768 Q2); the arrangement-grain override is the reserved pair on tblSongArrangements. DORMANT until the P2 resolver reads it' AFTER LyricsRightsLicenceKey,
                ADD INDEX idx_MusicRightsLicence (MusicRightsLicenceKey)"
        );
        _migGFL_output("  [OK] Added tblSongs.MusicRightsLicenceKey (+ index).");
    }

    /* ---- 5-6. tblSongbooks editor-default pair (EDITOR-DEFAULT only, never resolver-read) ---- */
    if (_migGFL_columnExists($mysql, 'tblSongbooks', 'DefaultLyricsRightsLicenceKey')) {
        _migGFL_output("  [SKIP] tblSongbooks.DefaultLyricsRightsLicenceKey already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongbooks
                ADD COLUMN DefaultLyricsRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'EDITOR-DEFAULT only, NEVER resolver-read (#1769 P1): pre-fills the Rights panel LyricsRightsLicenceKey for new songs in this book and powers the P4 apply-to-all-songs bulk action. The resolver reads ONLY the per-song fact — no inheritance chase, no JOIN, and tblSongs NULL keeps its single meaning (no requirement). NO FK (degrade-safe). DORMANT until the P4 editor reads it' AFTER Language"
        );
        _migGFL_output("  [OK] Added tblSongbooks.DefaultLyricsRightsLicenceKey.");
    }
    if (_migGFL_columnExists($mysql, 'tblSongbooks', 'DefaultMusicRightsLicenceKey')) {
        _migGFL_output("  [SKIP] tblSongbooks.DefaultMusicRightsLicenceKey already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongbooks
                ADD COLUMN DefaultMusicRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'EDITOR-DEFAULT only, NEVER resolver-read (#1769 P1): pre-fills the Rights panel MusicRightsLicenceKey for new songs in this book; see DefaultLyricsRightsLicenceKey. NO FK (degrade-safe). DORMANT until the P4 editor reads it' AFTER DefaultLyricsRightsLicenceKey"
        );
        _migGFL_output("  [OK] Added tblSongbooks.DefaultMusicRightsLicenceKey.");
    }

    /* ---- 7. tblSongArrangements RESERVED DORMANT arrangement-grain rights (#1768 Q2) ---- */
    if (!_migGFL_tableExists($mysql, 'tblSongArrangements')) {
        _migGFL_output("  [WARN] tblSongArrangements not present — run the #1066 iLyricsDB-alignment card first, then re-run this card.");
    } else {
        if (_migGFL_columnExists($mysql, 'tblSongArrangements', 'MusicRightsStatus')) {
            _migGFL_output("  [SKIP] tblSongArrangements.MusicRightsStatus already present.");
        } else {
            $mysql->query(
                "ALTER TABLE tblSongArrangements
                    ADD COLUMN MusicRightsStatus VARCHAR(20) NULL DEFAULT NULL COMMENT 'RESERVED DORMANT (#1769 P1 / #1768 Q2): arrangement-grain music-rights status — pd | licensed | restricted (app-validated growable vocabulary, VARCHAR never ENUM, rule #20). NULL = inherit the song-grain facts (tblSongs.MusicPublicDomain + MusicRightsLicenceKey). Nothing reads this yet; the arrangement-grain gate flips on later with zero migration' AFTER CapoFret"
            );
            _migGFL_output("  [OK] Added tblSongArrangements.MusicRightsStatus.");
        }
        if (_migGFL_columnExists($mysql, 'tblSongArrangements', 'MusicRightsLicenceKey')) {
            _migGFL_output("  [SKIP] tblSongArrangements.MusicRightsLicenceKey already present.");
        } else {
            $mysql->query(
                "ALTER TABLE tblSongArrangements
                    ADD COLUMN MusicRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'RESERVED DORMANT (#1769 P1 / #1768 Q2): arrangement-grain requirement override — tblLicenceTypes.LicenceKey needed for music-reproduction actions on THIS arrangement. NULL = inherit song-grain. NO FK (degrade-safe, matches tblSongs.MusicRightsLicenceKey)' AFTER MusicRightsStatus"
            );
            _migGFL_output("  [OK] Added tblSongArrangements.MusicRightsLicenceKey.");
        }
    }

    /* ---- 8. tblGatingCapabilities.EnforceJson ---- */
    if (!_migGFL_tableExists($mysql, 'tblGatingCapabilities')) {
        _migGFL_output("  [WARN] tblGatingCapabilities not present — run the #1481 gating-registry card first, then re-run this card.");
    } elseif (_migGFL_columnExists($mysql, 'tblGatingCapabilities', 'EnforceJson')) {
        _migGFL_output("  [SKIP] tblGatingCapabilities.EnforceJson already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblGatingCapabilities
                ADD COLUMN EnforceJson JSON NULL DEFAULT NULL COMMENT 'Enforcement mapping this admin-defined cap CARRIES (#1769 P1): object keyed by behaviour kind from the includes/gating_rules.php catalog (strip_payload_keys | drop_media_kinds | requiresCoverage | ...), value = kind-specific params. Mirrors the additive 5th enforce element TIER_CAPS gains in P2 so a cap definition and its enforcement live in ONE place and tblGatingRules can retire (P5). NULL = definition-only cap (identical to pre-#1769 behaviour -- what keeps this dormant). App-validated growable JSON, never ENUM (rule #20)' AFTER EmitInApi"
        );
        _migGFL_output("  [OK] Added tblGatingCapabilities.EnforceJson.");
    }

    /* ---- 9. Make the feature-gating-rules switch visible (deferred from #1769 P0) ---- */
    $flagKey  = 'feature_gating_rules_enabled';
    $flagDesc = 'Enable the admin-defined payload-rules engine (/manage/feature-gating). Nested under content_gating_enabled -- rules apply only when BOTH are 1. Seeded 0 by #1769 P1 (deferred from P0).';
    $stmt = $mysql->prepare("INSERT IGNORE INTO tblAppSettings (SettingKey, SettingValue, Description) VALUES (?, '0', ?)");
    $stmt->bind_param('ss', $flagKey, $flagDesc);
    $stmt->execute();
    _migGFL_output($mysql->affected_rows > 0 ? "  [OK] Seeded feature_gating_rules_enabled=0." : "  [SKIP] feature_gating_rules_enabled already present.");
    $stmt->close();

    _migGFL_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migGFL_output("ERROR: " . $e->getMessage());
}
