<?php

declare(strict_types=1);

/**
 * iHymns — Seed standard CCLI / OpenLyrics theme vocabulary (#1152)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adopt the standard CCLI / SongSelect theme taxonomy that the OpenLyrics
 * project ships as themelist.txt (https://github.com/openlyrics/openlyrics),
 * so iHymns' Browse-by-Theme is interoperable with the wider worship-software
 * ecosystem and curators get a ready-made, professional vocabulary instead of
 * ad-hoc free-text tags.
 *
 * Schema (strictly additive + idempotent, column existence guarded):
 *   - tblSongTags.ParentId    self-FK → the 2-level "Parent / Child" hierarchy.
 *   - tblSongTags.CcliThemeId  CCLI SongSelect theme number (dormant — themelist
 *                              carries names only; populated later by import).
 *   - tblSongTags.Source       provenance: 'curator' | 'ccli-openlyrics'.
 *
 * Data (idempotent upsert, re-runnable):
 *   The themelist " / " hierarchy maps to parent → child. The LEAF is stored in
 *   Name (never the slash-joined path — Name is VARCHAR(50)); the path lives via
 *   ParentId. A leaf whose name already exists as a top-level theme
 *   (Forgiveness / Grace / Love / Mercy / Righteousness all appear both flat and
 *   under "God's Attributes") collapses to the single canonical node — it is NOT
 *   duplicated and NOT re-parented (the canonical-vocabulary goal, #1222).
 *   Any existing same-named curator tag is promoted to Source='ccli-openlyrics'.
 *
 * LICENCE: the theme *names* are short factual labels from the OpenLyrics
 * themelist (the same taxonomy as CCLI SongSelect); seeding them is low-risk.
 * Source recorded here per #1152's licensing note.
 *
 * NON-DESTRUCTIVE. Three ADD COLUMNs, two ADD INDEXes, one ADD FOREIGN KEY and
 * a vocabulary seed — nothing is dropped, renamed or rewritten. Existing
 * curator tags keep their rows and their song mappings; the only thing that
 * can change on an existing row is `Source` flipping from 'curator' to
 * 'ccli-openlyrics' (the "promotion" described above), which is metadata, not
 * content. There is accordingly no recovery script: an unwanted run is undone
 * by an UPDATE resetting Source, not by restoring anything.
 *
 * IDEMPOTENCY — the part to read before editing, because a re-run is routine:
 *   - DDL: each helper probes first (SHOW COLUMNS / SHOW INDEX /
 *     information_schema.TABLE_CONSTRAINTS) and prints "[SKIP]" if the object
 *     is already there. `ADD COLUMN` has no IF NOT EXISTS in MySQL, so the
 *     probe is the only thing making a second run survivable.
 *   - Data: `INSERT … ON DUPLICATE KEY UPDATE … Id = LAST_INSERT_ID(Id)` — see
 *     the detailed note at Phase 1. The upsert is keyed on tblSongTags' UNIQUE
 *     constraints, so an existing theme is adopted rather than duplicated.
 *   - Because the whole thing converges, the number of standard theme nodes
 *     reported in the summary is stable across runs — that count is what the
 *     setup-database probe reads to decide the card is applied.
 *
 * SCHEMA MIRROR (rule #19): the three column DDL strings below are
 * byte-identical to their declarations inside the tblSongTags CREATE TABLE in
 * appWeb/.sql/schema.sql, COMMENT text included. That is not stylistic — a
 * fresh install builds the table from schema.sql and a long-running one from
 * these ALTERs, so any difference (even a comma in a COMMENT) makes the two
 * populations structurally distinct and the Schema Audit page (#518) starts
 * flagging divergence. Note that `tests/php/test-schema-coverage.php` compares
 * PRESENCE only — it will not catch a COMMENT that drifts, so the two have to
 * be edited together by hand, in one commit.
 *
 * @migration-adds tblSongTags.ParentId
 * @migration-adds tblSongTags.CcliThemeId
 * @migration-adds tblSongTags.Source
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-seed-theme-vocabulary.php
 *   Web:  /manage/setup-database → "Standard Theme Vocabulary" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migThemes_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* The three _migThemes_add* helpers all follow the same shape: probe, skip if
   present, otherwise ALTER. `$table` / `$col` / `$idx` / `$fk` are interpolated
   rather than bound because they are identifiers, which prepared statements
   cannot parameterise — every call site below passes a hardcoded literal from
   this file, which is case (a) of the interpolation exemption in rule #5. Never
   route a request value through these. */
function _migThemes_addCol(\mysqli $db, string $table, string $col, string $ddl): void {
    /* SHOW COLUMNS … LIKE treats the pattern as a LIKE pattern, so `_` is a
       single-character wildcard. Harmless for the three names used here (none
       contains one), but a future column named e.g. `Ccli_Theme_Id` would
       match loosely and could report a different column as "already present".
       https://dev.mysql.com/doc/refman/8.0/en/show-columns.html */
    $r = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    if ($r && $r->num_rows > 0) { _migThemes_output("  [SKIP] {$table}.{$col} already present."); return; }
    $db->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    _migThemes_output("  [OK] Added {$table}.{$col}.");
}

function _migThemes_addIdx(\mysqli $db, string $table, string $idx, string $cols): void {
    $r = $db->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$idx}'");
    if ($r && $r->num_rows > 0) { _migThemes_output("  [SKIP] index {$idx} already present."); return; }
    $db->query("ALTER TABLE {$table} ADD INDEX {$idx} ({$cols})");
    _migThemes_output("  [OK] Added index {$idx}.");
}

function _migThemes_addFk(\mysqli $db, string $table, string $fk, string $ddl): void {
    $r = $db->query(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
            AND CONSTRAINT_NAME = '{$fk}' LIMIT 1"
    );
    if ($r && $r->num_rows > 0) { _migThemes_output("  [SKIP] FK {$fk} already present."); return; }
    $db->query("ALTER TABLE {$table} ADD {$ddl}");
    _migThemes_output("  [OK] Added FK {$fk}.");
}

/** URL-safe lowercase slug (apostrophes dropped, runs of non-alnum → single hyphen). */
function _migThemes_slug(string $s): string {
    $s = str_replace("'", '', strtolower($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    return trim($s, '-');
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migThemes_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migThemes_output("");
_migThemes_output("=== iHymns — Standard Theme Vocabulary (CCLI / OpenLyrics) (#1152) ===");
_migThemes_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migThemes_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migThemes_output("Connected to MySQL: " . DB_NAME);

/* The standard OpenLyrics / CCLI theme list (themelist.txt). One per line; a
   " / " expresses a 2-level Parent / Child relationship. Verbatim labels. */
$themelist = <<<'THEMES'
Admonition
Adoration
Anointing
Ascension
Aspiration
Assurance
Atonement
Benediction
Beauty
Blessing
Blood
Calvary
Ceremony / Baby
Ceremony / Baptism
Ceremony / Funeral
Ceremony / Wedding
Challenge
Character
Children
Christ
Christian Life
Church
Cleansing
Comfort
Commitment
Communion
Compassion
Confession
Conquering
Consecration
Contentment
Conversion
Courage
Creation
Creator
Cross
Crucifixion
Declaration
Dedication
Devotion
Devotional
Discipleship
Encouragement
Eternal Life
Evangelism
Faith
Family
Fellowship
Forgiveness
Freedom
Giving
God's Attributes / Faithfulness
God's Attributes / Father
God's Attributes / Forgiveness
God's Attributes / Goodness
God's Attributes / Grace
God's Attributes / Greatness
God's Attributes / His Name
God's Attributes / Love
God's Attributes / Majesty
God's Attributes / Mercy
God's Attributes / Power
God's Attributes / Righteousness
God's Attributes / Will
God's Attributes / Wisdom
God's Word
Grace
Guidance
Healing
Heaven
Heritage
Holiness
Holy Spirit
Hope
Humility
Jesus
Jesus / Bread of Life
Jesus / Friendship
Jesus / Lamb of God
Jesus / Life Of Jesus
Jesus / Living Water
Jesus / Lordship Of Jesus
Jesus / Messiah
Jesus / Name Of Jesus
Jesus / The Light
Joy
Judgement
Kingship
Liberty
Love
Mercy
Miracles
Missions
Nature
Obedience
Offering
Peace
Petition
Praise
Prayer
Presence
Processional
Promise
Protection
Providence
Provision
Redemption
Refuge
Rejoice
Renewal
Repentance
Resurrection
Revival
Righteousness
Sacrifice
Salvation
Sanctification
Savior
Seasonal / Advent
Seasonal / Christmas
Seasonal / Easter
Seasonal / Epiphany
Seasonal / Harvest
Seasonal / Lent
Seasonal / Palm Sunday
Seasonal / Patriotic
Seasonal / Pentecost
Second Coming
Servanthood
Service
Stewardship
Strength
Testimony
Thankfulness
Tithes
Trials
Trust
Truth
Unity
Victory
Warfare
Worship
Youth
Zion
THEMES;

try {
    _migThemes_output("");
    _migThemes_output("--- tblSongTags: hierarchy + provenance columns ---");
    _migThemes_addCol($mysql, 'tblSongTags', 'ParentId',
        "ParentId INT UNSIGNED NULL DEFAULT NULL COMMENT 'Self-FK to tblSongTags.Id for the 2-level CCLI/OpenLyrics theme hierarchy (#1152); NULL = top-level' AFTER Description");
    _migThemes_addCol($mysql, 'tblSongTags', 'CcliThemeId',
        "CcliThemeId INT UNSIGNED NULL DEFAULT NULL COMMENT 'CCLI SongSelect theme number for import id-match (#1152); NULL until known' AFTER ParentId");
    /* Source is VARCHAR + an app-level allow-list, never an ENUM (rule #20):
       provenance is a growable vocabulary — a future SongSelect import or a
       partner feed is a new value — and adding an ENUM member costs an ALTER,
       which is exactly the second migration the one-pass rule exists to avoid.
       The DEFAULT 'curator' is what makes the ADD COLUMN safe on a populated
       table: every pre-existing tag is correctly labelled curator-authored
       without a backfill pass. */
    _migThemes_addCol($mysql, 'tblSongTags', 'Source',
        "Source VARCHAR(50) NOT NULL DEFAULT 'curator' COMMENT 'Provenance: curator | ccli-openlyrics (seeded standard vocab) (#1152)' AFTER CcliThemeId");
    _migThemes_addIdx($mysql, 'tblSongTags', 'idx_ParentId', 'ParentId');
    _migThemes_addIdx($mysql, 'tblSongTags', 'idx_Source', 'Source');
    _migThemes_addFk($mysql, 'tblSongTags', 'fk_SongTags_Parent',
        "CONSTRAINT fk_SongTags_Parent FOREIGN KEY (ParentId) REFERENCES tblSongTags(Id) ON DELETE SET NULL ON UPDATE CASCADE");

    /* ---- Parse the themelist into top-level + child relations. ---- */
    $topLevel = [];   // name => true (flat themes + implied parent categories)
    $children = [];   // [parentName, leafName]
    foreach (preg_split('/\r?\n/', $themelist) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        if (str_contains($line, ' / ')) {
            /* Split on the FIRST ' / ' only (limit 2). The taxonomy is two
               levels by definition, so a hypothetical three-level line would
               fold its tail into the leaf name ("A / B / C" → parent "A", leaf
               "B / C") rather than silently losing it — and would then fail
               loudly on the VARCHAR(50) Name if it were long. Deliberate: the
               2-level shape is the contract, not an accident of parsing. */
            [$p, $leaf] = array_map('trim', explode(' / ', $line, 2));
            $topLevel[$p] = true;       // ensure the parent category node exists
            $children[]   = [$p, $leaf];
        } else {
            $topLevel[$line] = true;
        }
    }

    _migThemes_output("");
    _migThemes_output("--- Seeding " . count($topLevel) . " top-level + " . count($children) . " child theme(s) ---");

    /* Phase 1 — upsert every top-level node; capture id (LAST_INSERT_ID trick
       returns the existing row's id on a duplicate-key hit). Any existing
       same-named curator tag is promoted to the standard vocabulary.

       ELI5 — `Id = LAST_INSERT_ID(Id)`: an INSERT tells you the id of the row
       it just created, but an upsert that hit an EXISTING row normally tells
       you nothing. Assigning the row's own Id back to itself through
       LAST_INSERT_ID() is MySQL's documented way of saying "and report that
       id", so `$mysql->insert_id` below is the right id in BOTH cases — which
       is what lets Phase 2 look parents up in $idOf without a second SELECT
       per theme. https://dev.mysql.com/doc/refman/8.0/en/information-functions.html#function_last-insert-id

       DETAIL — what the upsert keys on: tblSongTags declares UNIQUE on BOTH
       Name and Slug. Either can trigger the duplicate branch, which is what
       makes "adopt the curator's existing 'Grace' tag" work. It also means two
       DIFFERENTLY-named themes whose slugs collide would be folded into one
       row; the current themelist has no such pair, but a future addition
       differing only in punctuation would need checking.

       DETAIL — what the UPDATE clause deliberately does NOT set: `ParentId`.
       An existing tag keeps whatever parent it had (normally none). That is
       the "promote, never re-parent" policy from the doc-block — a curator's
       flat "Christmas" tag becomes standard vocabulary without being silently
       moved underneath "Seasonal", where every existing song mapping would
       start displaying differently. */
    $idOf = [];
    $insTop = $mysql->prepare(
        "INSERT INTO tblSongTags (Name, Slug, Source) VALUES (?, ?, 'ccli-openlyrics')
         ON DUPLICATE KEY UPDATE Source = 'ccli-openlyrics', Id = LAST_INSERT_ID(Id)"
    );
    foreach (array_keys($topLevel) as $name) {
        $slug = _migThemes_slug($name);
        $insTop->bind_param('ss', $name, $slug);
        $insTop->execute();
        $idOf[$name] = (int)$mysql->insert_id;
    }
    $insTop->close();

    /* Phase 2 — children. A leaf that already exists as a top-level theme
       collapses to that single canonical node (promote, never duplicate /
       re-parent). Otherwise insert under its parent. */
    $insChild = $mysql->prepare(
        "INSERT INTO tblSongTags (Name, Slug, Source, ParentId) VALUES (?, ?, 'ccli-openlyrics', ?)
         ON DUPLICATE KEY UPDATE Source = 'ccli-openlyrics', Id = LAST_INSERT_ID(Id)"
    );
    $promote = $mysql->prepare(
        "UPDATE tblSongTags SET Source = 'ccli-openlyrics' WHERE Name = ? AND Source = 'curator'"
    );
    $childCount = 0;
    foreach ($children as [$p, $leaf]) {
        /* $idOf holds every top-level node from Phase 1 PLUS every leaf already
           created in this loop, so this one test covers two collisions at once:
           a leaf that duplicates a flat theme (Grace, Love, Mercy, …) and a
           leaf that appears under two different parents. Both resolve to the
           first/canonical node. The check is against the in-memory map rather
           than the database on purpose — it makes the outcome a function of
           the themelist alone, identical on every run and every install. */
        if (isset($idOf[$leaf])) {           /* collision with a top-level theme */
            $promote->bind_param('s', $leaf);
            $promote->execute();
            continue;
        }
        $parentId = $idOf[$p] ?? null;
        $slug = _migThemes_slug($leaf);
        $insChild->bind_param('ssi', $leaf, $slug, $parentId);
        $insChild->execute();
        $idOf[$leaf] = (int)$mysql->insert_id;
        $childCount++;
    }
    $insChild->close();
    $promote->close();

    $std = $mysql->query("SELECT COUNT(*) AS n FROM tblSongTags WHERE Source = 'ccli-openlyrics'");
    $stdN = $std ? (int)$std->fetch_assoc()['n'] : 0;
    if ($std) { $std->close(); }

    _migThemes_output("");
    _migThemes_output("--- Summary ---");
    _migThemes_output("  Standard theme nodes now present: {$stdN}.");
    _migThemes_output("  Curators can still add custom themes; these are marked Source='ccli-openlyrics'.");
    _migThemes_output("");
    _migThemes_output("Migration complete.");
} catch (\Throwable $e) {
    _migThemes_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
