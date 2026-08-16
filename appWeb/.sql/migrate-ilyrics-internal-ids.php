<?php

declare(strict_types=1);

/**
 * iHymns — iLyrics Internal IDs infrastructure (#1860 Phase 1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE
 * -------
 * The first, additive+idempotent+DORMANT slice of the future `IL*`
 * internal-id scheme (design doc §2.2/§2.3): iHymns' backend is the
 * future iLyrics DB, and this is the ONE central allocator every entity
 * family will eventually share. This card creates and seeds the
 * allocator table `tblIlyricsIdSequence` (one row per entity type) and
 * adds a NULLable `IlId VARCHAR(16)` column + `uq_IlId` UNIQUE key to the
 * 8 entity tables (`tblSongs`, `tblWorks`, `tblMusicians`, `tblTunes`,
 * `tblPublishers`, `tblCatalogues`, `tblSongbooks`, `tblSongMedia`).
 *
 * After this card runs, NOTHING in the live tree reads or mints an `IlId`
 * — the dormant allocator core (`includes/ilyrics_id.php`) has zero
 * production callers, no resolver branches on `IlId`, and no create path
 * writes it. Applying this migration changes ZERO observable behaviour;
 * the backfill + mint-on-create wiring is Phase 2.
 *
 * NO-SEPARATOR RATIONALE (owner sign-off 2026-08-16, design doc §2.2):
 * the public SongId grammar is always `<letters>-<digits>` (`MP-1008`),
 * so the hyphen-less `ILS0000012345` form is provably disjoint from it —
 * a public id always contains `-`, an IL id never does.
 *
 * @migration-adds tblSongs.IlId
 * @migration-adds tblWorks.IlId
 * @migration-adds tblMusicians.IlId
 * @migration-adds tblTunes.IlId
 * @migration-adds tblPublishers.IlId
 * @migration-adds tblCatalogues.IlId
 * @migration-adds tblSongbooks.IlId
 * @migration-adds tblSongMedia.IlId
 *
 * SCHEMA MIRROR: the `tblIlyricsIdSequence` CREATE TABLE and all 8 IlId
 * columns + their `uq_IlId` UNIQUE keys are mirrored byte-identical
 * (including every COMMENT) in `appWeb/.sql/schema.sql` — rule #19.
 *
 * ORDERING IS LOAD-BEARING: (1) CREATE tblIlyricsIdSequence, (2) seed its
 * 8 rows, (3) the 8 ALTERs — in that order. The registry probe (below)
 * checks the table AND all 8 columns, so a run that fails partway
 * through the seed step aborts before any IlId column exists and the
 * dashboard card correctly stays "pending" rather than showing green on
 * a half-applied state (rule #19).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Every CREATE/ADD is existence-guarded;
 * a second run prints [SKIP] everywhere and touches nothing.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-ilyrics-internal-ids.php
 *   Web:  /manage/setup-database → "iLyrics Internal IDs (#1860)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/ilyrics-internal-ids-work-model-plan.md §2.2, §2.3  the full design this migration implements
 * @see appWeb/public_html/includes/ilyrics_id.php  the dormant allocator core reading IHYMNS_ILID_TYPES this seeds from
 * @see appWeb/public_html/manage/includes/migration-registry.php  'ilyrics-internal-ids' entry
 * @see #1860
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migIlids_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migIlids_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'"); return (bool)($r && $r->num_rows > 0); }
function _migIlids_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migIlids_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migIlids_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

/* Rule #41: the deployed docroot is renamed per channel (public_html_dev /
   public_html_beta); IHYMNS_INCLUDES_DIR is defined by the setup-database
   runner and points at the REAL docroot's includes/. The literal is the
   repo/CLI fallback only — this line is an ASSIGNMENT, never an
   unconditional require of the literal itself, so
   tests/php/test-deploy-paths.php's column-0-require scan does not flag
   it (the require below reads $_incDir, not a hardcoded path). */
$_incDir = defined('IHYMNS_INCLUDES_DIR') ? IHYMNS_INCLUDES_DIR : dirname(__DIR__) . '/public_html/includes';
require_once $_incDir . '/ilyrics_id.php';

_migIlids_output("");
_migIlids_output("=== iHymns — iLyrics Internal IDs (#1860) ===");
_migIlids_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migIlids_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migIlids_output("Connected to MySQL: " . DB_NAME);

try {
    /* ---- 1. The allocator table ----
       SCHEMA MIRROR: byte-identical (incl. every COMMENT) to the
       tblIlyricsIdSequence block in appWeb/.sql/schema.sql — rule #19. */
    _migIlids_output("--- tblIlyricsIdSequence ---");
    if (_migIlids_tableExists($mysql, 'tblIlyricsIdSequence')) {
        _migIlids_output("  [SKIP] tblIlyricsIdSequence already exists.");
    } else {
        $mysql->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS tblIlyricsIdSequence (
    EntityType VARCHAR(20)     NOT NULL COMMENT 'song | work | musician | tune | publisher | catalogue | songbook | document — app-validated against IHYMNS_ILID_TYPES in includes/ilyrics_id.php (VARCHAR not ENUM, rule #20; the internal type stays catalogue, never collection — rule #24)',
    Prefix     VARCHAR(4)      NOT NULL COMMENT 'ILS | ILW | ILM | ILT | ILP | ILC | ILB | ILD — informational denorm of IHYMNS_ILID_TYPES; the map is the source of truth',
    NextValue  BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Next candidate number for this type. The counter is a SEED, not the claim set — ilidAllocate() claim-checks the entity table''s uq_IlId before returning (restore-safety, #1860 §6)',
    UpdatedAt  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (EntityType)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-entity-type allocator for the sequential IL* internal ids (#1860 §2.3). One row per entity family; row-level FOR UPDATE serialises same-type mints only. Seeded by migrate-ilyrics-internal-ids.php; read/written ONLY by ilidAllocate().'
SQL
        );
        _migIlids_output("  [OK] Created tblIlyricsIdSequence.");
    }

    /* ---- 2. Seed rows — idempotent by PK; the no-op ON DUPLICATE KEY
       UPDATE guarantees a re-run NEVER resets a live counter. Sourced
       from IHYMNS_ILID_TYPES (rule #22/#35) — NOT a second hardcoded
       copy of the 8 prefix/table pairs. ---- */
    _migIlids_output("--- Seeding tblIlyricsIdSequence ---");
    $seed = $mysql->prepare(
        'INSERT INTO tblIlyricsIdSequence (EntityType, Prefix, NextValue)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE EntityType = EntityType'
    );
    foreach (IHYMNS_ILID_TYPES as $entityType => $def) {
        $seed->bind_param('ss', $entityType, $def['prefix']);
        $seed->execute();
    }
    $seed->close();
    _migIlids_output('  [OK] Seeded/verified ' . count(IHYMNS_ILID_TYPES) . ' entity-type row(s).');

    /* ---- 3. The 8 IlId columns + uq_IlId keys ----
       Per-table anchor column + BYTE-IDENTICAL (rule #19) COMMENT text —
       the exact wording mirrored in appWeb/.sql/schema.sql's IlId column
       lines. Table/prefix themselves come from the central
       IHYMNS_ILID_TYPES map (loaded above, used for the seed step); these
       two extra facts (where the column sits, exactly what its COMMENT
       says) are per-table SQL CONTENT the map doesn't carry, so they are
       spelled out literally here — that is what keeps this migration
       diffable byte-for-byte against schema.sql
       (tests/php/test-schema-coverage.php and
       tests/php/test-ilyrics-ids.php both check this). This is NOT a
       second copy of the prefix->table pairs. */
    $ilIdAnchor = [
        'tblSongs'      => 'PublicId',
        'tblWorks'      => 'Slug',
        'tblMusicians'  => 'Slug',
        'tblTunes'      => 'Slug',
        'tblPublishers' => 'Slug',
        'tblCatalogues' => 'Slug',
        'tblSongbooks'  => 'Abbreviation',
        'tblSongMedia'  => 'Id',
    ];
    $ilIdComment = [
        'tblSongs'      => 'iLyrics internal id (#1860 §2.2): ILS + 10 zero-padded digits, e.g. ILS0000012345 — sequential catalogue-master identity minted by ilidAllocate() in includes/ilyrics_id.php. Move/rename-stable (never re-keyed). NULL until the Phase-2 backfill/mint-on-create; DORMANT in Phase 1 (no readers).',
        'tblWorks'      => 'iLyrics internal id (#1860 §2.2): ILW + 10 zero-padded digits, e.g. ILW0000012345 — sequential catalogue-master identity minted by ilidAllocate() in includes/ilyrics_id.php. Move/rename-stable (never re-keyed). NULL until the Phase-2 backfill/mint-on-create; DORMANT in Phase 1 (no readers).',
        'tblMusicians'  => 'iLyrics internal id (#1860 §2.2): ILM + 10 zero-padded digits, e.g. ILM0000012345 — sequential catalogue-master identity minted by ilidAllocate() in includes/ilyrics_id.php. Move/rename-stable (never re-keyed). NULL until the Phase-2 backfill/mint-on-create; DORMANT in Phase 1 (no readers).',
        'tblTunes'      => 'iLyrics internal id (#1860 §2.2): ILT + 10 zero-padded digits, e.g. ILT0000012345 — sequential catalogue-master identity minted by ilidAllocate() in includes/ilyrics_id.php. Move/rename-stable (never re-keyed). NULL until the Phase-2 backfill/mint-on-create; DORMANT in Phase 1 (no readers).',
        'tblPublishers' => 'iLyrics internal id (#1860 §2.2): ILP + 10 zero-padded digits, e.g. ILP0000012345 — sequential catalogue-master identity minted by ilidAllocate() in includes/ilyrics_id.php. Move/rename-stable (never re-keyed). NULL until the Phase-2 backfill/mint-on-create; DORMANT in Phase 1 (no readers).',
        'tblCatalogues' => 'iLyrics internal id (#1860 §2.2): ILC + 10 zero-padded digits, e.g. ILC0000012345 — sequential catalogue-master identity minted by ilidAllocate() in includes/ilyrics_id.php. Move/rename-stable (never re-keyed). NULL until the Phase-2 backfill/mint-on-create; DORMANT in Phase 1 (no readers).',
        'tblSongbooks'  => 'iLyrics internal id (#1860 §2.2): ILB + 10 zero-padded digits, e.g. ILB0000012345 — sequential catalogue-master identity minted by ilidAllocate() in includes/ilyrics_id.php. Move/rename-stable (never re-keyed). NULL until the Phase-2 backfill/mint-on-create; DORMANT in Phase 1 (no readers).',
        'tblSongMedia'  => 'iLyrics internal id (#1860 §2.2): ILD + 10 zero-padded digits, e.g. ILD0000012345 — sequential catalogue-master identity minted by ilidAllocate() in includes/ilyrics_id.php. Move/rename-stable (never re-keyed). NULL until the Phase-2 backfill/mint-on-create; DORMANT in Phase 1 (no readers).',
    ];

    foreach (IHYMNS_ILID_TYPES as $entityType => $def) {
        $table = $def['table'];
        _migIlids_output("--- {$table}.IlId ---");

        if (_migIlids_colExists($mysql, $table, 'IlId')) {
            if (_migIlids_idxExists($mysql, $table, 'uq_IlId')) {
                _migIlids_output("  [SKIP] {$table}.IlId + uq_IlId already present.");
            } else {
                /* Repairs the theoretical column-without-key state. */
                $mysql->query("ALTER TABLE {$table} ADD UNIQUE KEY uq_IlId (IlId)");
                _migIlids_output("  [OK] Repaired {$table}: added uq_IlId (column already present).");
            }
            continue;
        }

        /* AFTER-anchor is dynamic-guarded (the setlist-share-scope
           $orgAnchor pattern) — an install missing the anchor column
           degrades to appending IlId at table end instead of throwing. */
        $anchorCol = $ilIdAnchor[$table];
        $anchor    = _migIlids_colExists($mysql, $table, $anchorCol) ? "AFTER {$anchorCol}" : '';
        $comment   = $ilIdComment[$table];

        /* MySQL 8 DDL is single-statement atomic, so the column and its
           UNIQUE key land together or not at all. */
        $mysql->query(
            "ALTER TABLE {$table}
                ADD COLUMN IlId VARCHAR(16) NULL DEFAULT NULL
                    COMMENT '{$comment}'
                    {$anchor},
                ADD UNIQUE KEY uq_IlId (IlId)"
        );
        _migIlids_output("  [OK] Added {$table}.IlId + uq_IlId.");
    }

    _migIlids_output("");
    _migIlids_output("=== Done. iLyrics Internal IDs infrastructure is in place (dormant until Phase 2). ===");
} catch (\mysqli_sql_exception $e) {
    _migIlids_output("ERROR: migration failed: " . $e->getMessage());
    return;
}
