<?php

declare(strict_types=1);

/**
 * iHymns — Work identity model: external-ID overflow + medley composition +
 * per-section source provenance (#1860 Phase 3)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Three small, empty, dormant additions to the Works family so a LATER
 * commit (the write core `includes/work_admin.php`, same PR) has somewhere
 * to put things:
 *   1. `tblWorkExternalIds` — a spare drawer for "other" work identifiers
 *      (ASCAP/BMI/PRS numbers, MusicBrainz, …) that don't already have their
 *      own `tblWorks` column.
 *   2. `tblWorkComponents` — lets one Work say "I am a medley made of THESE
 *      other Works" (many-to-many), distinct from the existing
 *      `ParentWorkId` self-FK (which means "I am a VARIANT of that Work").
 *   3. `tblSongComponents.SourceWorkId` — an optional per-section tag saying
 *      "this verse/chorus of THIS song's rendering came from THAT Work" —
 *      the block-by-block stitching a rendered medley needs.
 *
 * DETAILED / WHY (design doc §3.5 / §3.6 / §3.6b)
 * ------------------------------------------------------------------------
 * Object A (`tblWorkExternalIds`) mirrors `tblSongExternalIds`
 * (schema.sql, the recording-grain sibling) with two deliberate deltas:
 * the UNIQUE key is TABLE-WIDE `(IdType, IdValue)` — a work identifier maps
 * to at most ONE work globally, unlike a recording id which legitimately
 * repeats per song — and there is no `IdScope` column, because everything
 * here is work-grain by construction. The vocabulary is the EXISTING
 * `WORK_IDENTIFIER_TYPES` registry (`includes/media_identifiers.php`),
 * which already maps `iswc`/`bowi`/`ccli`/`musicbrainz-work` onto their own
 * `tblWorks` columns and parks the eight PRO/society ids on the per-song
 * `tblSongRoyaltyIds` table — this table gives those a work-grain home
 * LATER by flipping their registry `storage` entry; nothing here changes
 * the registry or reads/writes a row (DORMANT — rule #20).
 *
 * Object B (`tblWorkComponents`) is a medley: a Work with its OWN CCLI that
 * CONTAINS several independent constituent Works. This is M:N, and
 * deliberately NOT `ParentWorkId` (1:N, "is-a-variant-of") — the two
 * relationships answer different questions and neither substitutes for the
 * other (design doc §3.2 table). App-level guards (MedleyWorkId <>
 * ComponentWorkId, a bounded-depth cycle reject) live in the WRITE CORE,
 * not the DB — Phase 5, alongside the `/manage/works` constituent editor
 * that is this table's first consumer. DORMANT here: no reader, no writer.
 *
 * Object C (`tblSongComponents.SourceWorkId`) is per-SECTION provenance —
 * `tblWorkComponents` says WHICH works a medley contains, not WHICH section
 * of the rendered song came from which. Nullable, `ON DELETE SET NULL`
 * (never CASCADE: deleting a Work must never delete a song's section). It
 * links the WORK, not a songbook song, so it survives a songbook move/re-key
 * (#1679) — `tblWorks.Id` never changes; a `SongId` FK would. NULL = "no
 * per-section claim", the whole-song default (the song's ordinary
 * `tblWorkSongs` membership). DORMANT here too: `component_upsert`'s
 * acceptance of this field and the §3.6b.2 lockstep `tblWorkComponents`
 * upsert are Phase 5, with the Structure-tab picker — this migration only
 * adds the column.
 *
 * PRECONDITION: every object here FKs to `tblWorks`, created by the
 * separate `migrate-works.php` ('works' registry entry). This script
 * probes for `tblWorks` FIRST and applies NOTHING if it is missing — the
 * same "run X above first" posture `migrate-works-identity.php` uses for
 * `fk_Works_Tune` against `tblTunes`.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Every CREATE/ADD is existence-guarded
 * independently, so a partial apply (e.g. the two tables landed but the
 * `SourceWorkId` ALTER failed) resumes cleanly on re-run.
 *
 * No shared include is required — this migration mints nothing (no `IlId`,
 * no `ilidAllocate()` call), so rule #41's `IHYMNS_INCLUDES_DIR` resolver
 * is a non-issue by construction rather than something this file has to
 * get right.
 *
 * @migration-adds tblSongComponents.SourceWorkId
 *
 * SCHEMA MIRROR: `tblWorkExternalIds` and `tblWorkComponents` are mirrored
 * byte-identical (incl. every COMMENT) in `appWeb/.sql/schema.sql`,
 * inserted after the `tblWorkExternalLinks` block and before the "#1066
 * INTERCHANGE" banner. `SourceWorkId` + `idx_SourceWork` are mirrored
 * INLINE in the `tblSongComponents` CREATE TABLE block (that table is
 * declared before `tblWorks` in schema.sql); `fk_Component_SourceWork` is a
 * TRAILING ALTER placed next to `fk_Works_Tune` (both need a table that is
 * declared later in the file) — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-work-identity-model.php
 *   Web:  /manage/setup-database → "Work identity model (#1860 Phase 3)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/ilyrics-internal-ids-work-model-plan.md §3.5, §3.6, §3.6b  the full design this migration implements
 * @see appWeb/public_html/includes/work_admin.php  the dormant write core this schema batch supports (same PR)
 * @see appWeb/public_html/manage/includes/migration-registry.php  'work-identity-model' entry
 * @see #1860
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migWorkIdent_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migWorkIdent_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'"); return (bool)($r && $r->num_rows > 0); }
function _migWorkIdent_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migWorkIdent_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }
function _migWorkIdent_fkExists(\mysqli $db, string $name): bool {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '" . $db->real_escape_string($name) . "' LIMIT 1"
    );
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migWorkIdent_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migWorkIdent_output("");
_migWorkIdent_output("=== iHymns — Work identity model (#1860 Phase 3) ===");
_migWorkIdent_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migWorkIdent_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migWorkIdent_output("Connected to MySQL: " . DB_NAME);

/* Every object below FKs to tblWorks — probe it FIRST (the
   'works-identity' precedent, migrate-works-identity.php) and apply
   NOTHING if it's absent, rather than letting three separate CREATE/ALTER
   statements each throw their own unresolved-FK error. The registry probe
   keeps this card correctly "pending" until 'works' has run; registry
   ORDER resolves that for "Apply all pending". */
if (!_migWorkIdent_tableExists($mysql, 'tblWorks')) {
    _migWorkIdent_output("  [SKIP][WARN] tblWorks does not exist yet — run the \"Works\" migration card first, then re-run this migration.");
    _migWorkIdent_output("");
    _migWorkIdent_output("Nothing applied.");
    return;
}

try {
    /* ---- A. tblWorkExternalIds (design §3.5) ---- */
    _migWorkIdent_output("--- tblWorkExternalIds ---");
    if (_migWorkIdent_tableExists($mysql, 'tblWorkExternalIds')) {
        _migWorkIdent_output("  [SKIP] tblWorkExternalIds already exists.");
    } else {
        $mysql->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS tblWorkExternalIds (
    Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    WorkId    INT UNSIGNED NOT NULL COMMENT 'FK to tblWorks.Id — the composition this identifier belongs to (#1860 §3.5)',
    IdType    VARCHAR(40)  NOT NULL COMMENT 'Registry/society identifier key, e.g. ascap | bmi | prs | … — app-validated against includes/media_identifiers.php WORK_IDENTIFIER_TYPES (VARCHAR not ENUM, rule #20). The four column-backed keys (iswc/ccli/bowi/musicbrainz-work) stay on tblWorks'' own uq_* columns — this table is the extensible overflow, never their replacement',
    IdValue   VARCHAR(191) NOT NULL COMMENT 'The identifier value as issued by the authority. VARCHAR(191) keeps the utf8mb4 UNIQUE index under the legacy 767-byte-per-column InnoDB limit (mirrors tblSongExternalIds.IdValue)',
    Source    VARCHAR(40)  NULL DEFAULT NULL COMMENT 'Provenance system that supplied this row, e.g. manual | musicbrainz | luminate (free text, mirrors tblSongExternalIds.Source)',
    SourceRef VARCHAR(191) NULL DEFAULT NULL COMMENT 'External primary id from Source for idempotent re-import / dedup; NULL for manual entry — the (Source, SourceRef) pattern, rule #20',
    CreatedAt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Type_Value (IdType, IdValue),
    INDEX      idx_Work      (WorkId),

    CONSTRAINT fk_WorkExtIds_Work
        FOREIGN KEY (WorkId) REFERENCES tblWorks(Id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Key/value work-grain external-ID overflow (#1860 §3.5). Two deliberate deltas from tblSongExternalIds: the UNIQUE is TABLE-WIDE (IdType, IdValue) — a work identifier maps to at most ONE work globally, unlike recording ids which repeat per song — and there is no IdScope column (everything here is work-grain by construction). DORMANT until a WORK_IDENTIFIER_TYPES storage entry flips to this table.'
SQL
        );
        _migWorkIdent_output("  [OK] Created tblWorkExternalIds.");
    }

    /* ---- B. tblWorkComponents (design §3.6 — medley composition, M:N) ---- */
    _migWorkIdent_output("--- tblWorkComponents ---");
    if (_migWorkIdent_tableExists($mysql, 'tblWorkComponents')) {
        _migWorkIdent_output("  [SKIP] tblWorkComponents already exists.");
    } else {
        $mysql->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS tblWorkComponents (
    MedleyWorkId    INT UNSIGNED NOT NULL COMMENT 'FK to tblWorks.Id — the medley (a Work with its OWN CCLI) that CONTAINS the constituent works (#1860 §3.6). M:N contains — deliberately NOT ParentWorkId, which means is-a-variant-of (§3.2)',
    ComponentWorkId INT UNSIGNED NOT NULL COMMENT 'FK to tblWorks.Id — an independent constituent work; keeps its own tblWorkSongs memberships and identity untouched',
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Order of the constituent inside the medley (mirrors tblWorkSongs.SortOrder)',
    Note            VARCHAR(255) NULL COMMENT 'Optional curator note (mirrors tblWorkSongs.Note)',
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (MedleyWorkId, ComponentWorkId),
    INDEX idx_component (ComponentWorkId),

    CONSTRAINT fk_medley_work
        FOREIGN KEY (MedleyWorkId)    REFERENCES tblWorks(Id) ON DELETE CASCADE,
    CONSTRAINT fk_component_work
        FOREIGN KEY (ComponentWorkId) REFERENCES tblWorks(Id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Medley composition (#1860 §3.6): a medley Work CONTAINS independent constituent Works (M:N). App-level guards live in the write core, the DB cannot express them: MedleyWorkId <> ComponentWorkId, bounded-depth cycle reject. DORMANT until the /manage/works constituent editor lands (Phase 5).'
SQL
        );
        _migWorkIdent_output("  [OK] Created tblWorkComponents.");
    }

    /* ---- C. tblSongComponents.SourceWorkId (design §3.6b) ----
       Each guarded independently — column, index, constraint — so a run
       that fails partway (e.g. the FK, if tblWorks were somehow dropped
       between the column ADD and here) resumes cleanly. */
    _migWorkIdent_output("--- tblSongComponents.SourceWorkId ---");
    if (_migWorkIdent_colExists($mysql, 'tblSongComponents', 'SourceWorkId')) {
        _migWorkIdent_output("  [SKIP] tblSongComponents.SourceWorkId already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongComponents
                ADD COLUMN SourceWorkId INT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional provenance: the Work this section excerpts (medley stitching, #1860 §3.6b). Links the WORK, not a songbook song, so it survives songbook re-keys (#1679). NULL = whole-song default (the song''s own tblWorkSongs membership). DORMANT until Phase 5 wires component_upsert'"
        );
        _migWorkIdent_output("  [OK] Added tblSongComponents.SourceWorkId.");
    }

    if (_migWorkIdent_idxExists($mysql, 'tblSongComponents', 'idx_SourceWork')) {
        _migWorkIdent_output("  [SKIP] idx_SourceWork present.");
    } else {
        $mysql->query("ALTER TABLE tblSongComponents ADD INDEX idx_SourceWork (SourceWorkId)");
        _migWorkIdent_output("  [OK] Added index idx_SourceWork.");
    }

    if (_migWorkIdent_fkExists($mysql, 'fk_Component_SourceWork')) {
        _migWorkIdent_output("  [SKIP] fk_Component_SourceWork present.");
    } else {
        /* ON DELETE SET NULL, never CASCADE — deleting a work must never
           delete a song's section (design §3.6b). This exact statement
           text is mirrored byte-identical as the trailing ALTER in
           schema.sql, next to fk_Works_Tune. */
        $mysql->query(
            "ALTER TABLE tblSongComponents
                ADD CONSTRAINT fk_Component_SourceWork
                    FOREIGN KEY (SourceWorkId) REFERENCES tblWorks(Id) ON DELETE SET NULL"
        );
        _migWorkIdent_output("  [OK] Added FK fk_Component_SourceWork.");
    }

    _migWorkIdent_output("");
    _migWorkIdent_output("=== Done. Work identity model infrastructure is in place (dormant). ===");
} catch (\mysqli_sql_exception $e) {
    _migWorkIdent_output("ERROR: migration failed: " . $e->getMessage());
    return;
}
