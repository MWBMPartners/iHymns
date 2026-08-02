<?php

declare(strict_types=1);

/**
 * iHymns — Musicians rename schema batch (epic #1741 P2-A)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * SCHEMA-ONLY half of the Musicians rename (plan .claude/catalogue-expansion-1741-plan.md
 * §3.A). Renames the 7 tblCreditPe(ople|rson...) tables to tblMusician*, renames
 * every CreditPersonId-family column to MusicianId (or Subject/ObjectMusicianId on the
 * relation table), renames the indexes/FK constraints that embedded the old vocabulary,
 * and — the load-bearing back-compat move — creates an UPDATABLE VIEW under every OLD
 * table name so app code that still says `tblCreditPeople` (every PHP file except this
 * one and includes/schema_audit.php) keeps working unchanged. The APP-CODE rename
 * (routes, actions, log keys, PHP/JS identifiers, entitlement name) is P2-B — a
 * DELIBERATELY separate, later step. Applying this card alone changes zero app-visible
 * behaviour: every existing read/write against tblCreditPeople etc. is now served by a
 * thin view over tblMusicians instead of the base table directly.
 *
 * WHY A VIEW AND NOT JUST A RENAME:
 * The three docroots (dev/alpha/www) share ONE MySQL but deploy CODE independently, and
 * migrations are web-run, not auto-applied on deploy (project rule #19/#28C). A plain
 * `RENAME TABLE` with no compat layer would mean the FIRST docroot to have this card
 * clicked breaks every OTHER docroot's still-running old-name code instantly. The view
 * is the same shield rule #28 uses for the gating registry and #1235 uses for the
 * lyric-lines cutover: old code, new schema, bridged by a thin read/write layer until
 * every consumer has moved (P2-B), at which point a later `'manual'`+`confirm=1` card
 * (P2b, tracked separately, NOT this script) drops the views.
 *
 * WHY THE VIEWS ARE UPDATABLE (not just SELECT-able):
 * Every column in each view is a direct 1:1 reference to an underlying column (renamed
 * via `AS <old-name>` where the column itself moved) — no expression, no aggregate, no
 * JOIN, no DISTINCT/GROUP BY. MySQL/MariaDB's updatability rules are satisfied by
 * construction, so `manage/credit-people.php`'s INSERT/UPDATE/DELETE against
 * `tblCreditPeople` (etc.) keep working through the view exactly as they did against the
 * base table — verified by hand in a scratch DB before this script was written (see the
 * PR description / handoff for the transcript).
 *
 * STEP ORDER (mirrors plan §3.A.3 exactly — each step is individually idempotency-
 * guarded, but the ORDER matters: a column must be renamed before the index/constraint
 * that names it can be re-created against the new name; the constraint DROP+ADD needs
 * the column already renamed; the views need the table+column renames already applied
 * so their `SELECT <NewCol> AS <OldCol>` clauses resolve):
 *   0. Idempotency short-circuit: if tblMusicians already exists, the whole rename has
 *      already landed (RENAME TABLE is atomic — see Step 1 — so this is a reliable
 *      signal) and the precondition gate below is SKIPPED, not re-evaluated, because
 *      once the compat views exist they show up in INFORMATION_SCHEMA.COLUMNS/STATISTICS
 *      too but a VIEW has no INDEX rows — re-running the P1 index probe against the
 *      view (tblCreditPersonMembers.uq_group_member_rel) would read as "missing" and
     *      wrongly ABORT an otherwise-complete re-run. Skipping the gate once already
 *      renamed sidesteps that false negative entirely.
 *   1. Precondition gate — abort (not throw) unless all 4 P1 cards (musician-profile,
 *      works-identity, tune-enrichment, song-identity-fields) are fully applied. Musts:
 *      tune-enrichment created tblTuneCredits with its OWN FK to tblCreditPeople; if
 *      tune-enrichment ran AFTER this script, its `FOREIGN KEY … REFERENCES
 *      tblCreditPeople(Id)` would target a VIEW — illegal DDL in MySQL/MariaDB (a
 *      foreign key may only reference a base table). The registry places this card
 *      after all 4 P1 entries so the dashboard's natural click order is safe; this gate
 *      guards a CLI run or an out-of-order manual click from the same mistake.
 *   2. Atomic `RENAME TABLE` (7 tables, one statement — either all 7 rename or the
 *      statement fails and NONE do; MySQL/MariaDB RENAME TABLE is all-or-nothing).
 *   3. `RENAME COLUMN` (metadata-only; FK definitions auto-follow — verified live).
 *   4. `RENAME INDEX` + FK `DROP …, ADD CONSTRAINT …` (same cols/refs/actions as the
 *      original — nothing about cascade behaviour changes, only names).
 *   5. `MODIFY COLUMN` for the 5 column COMMENTs that literally name tblCreditPe*.
 *   6. `CREATE OR REPLACE VIEW` — one per old table name, all 7, explicit column lists
 *      (never `SELECT *` — rule: a later column ADD on tblMusicians must not silently
 *      leak into the view without a deliberate edit here).
 *   7. `tblExternalLinkTypes.AppliesTo` — see the deviation note below.
 *
 * DEVIATION FROM THE PLAN'S LITERAL WORDING (flagged per the owner-decision protocol —
 * this is not a question that blocks anything, just a correction to record):
 * Plan §3.A describes the AppliesTo step as a "'person' token -> 'musician' rewrite".
 * A literal find-and-replace would BREAK `manage/credit-people.php`'s external-links
 * editor today: that page is explicit in-scope-to-keep-working app code, and it calls
 * `loadExternalLinkTypesFor($db, 'person')`, which reads `FIND_IN_SET('person',
 * AppliesTo)`. Rewriting every row's 'person' token away would zero out that editor's
 * provider list — the exact regression this whole P2-A phase exists to avoid. This
 * script instead APPENDS 'musician' alongside the retained 'person' token (CSV, e.g.
 * `song,songbook,person` -> `song,songbook,person,musician`) so old code
 * (`FIND_IN_SET('person', …)`) and future P2-B code (`FIND_IN_SET('musician', …)`) both
 * match during the transition. The column's own DEFAULT is widened the same way for
 * fresh installs. P2-B (or a dedicated follow-up once every docroot runs renamed code)
 * can retire the legacy 'person' token from both the DEFAULT and existing rows once
 * nothing reads it anymore — that cleanup is NOT this script's job.
 *
 * COMPAT VIEWS ARE PERMANENT UNTIL A DEDICATED DROP CARD (P2b, not written here) —
 * do not hand-drop them; do not "clean up" by inlining a real tblCreditPeople table
 * again. The read/write path for the OLD names is now exclusively these views.
 *
 * SCHEMA MIRROR: schema.sql now declares the 7 tables under their NEW names/columns/
 * index+constraint names/comments directly (byte-identical to this script's end state)
 * plus the 7 CREATE VIEW statements positioned after every table they reference — a
 * fresh install never runs this script at all, it is already in the post-rename shape.
 * rule #19.
 *
 * SCANNER: includes/schema_audit.php's schemaAuditScanMigrations() was taught to parse
 * `RENAME TABLE`  (multi-pair, one statement) and `RENAME COLUMN` out of migration files
 * and re-attribute prior migrations' `@migration-adds`/CREATE-TABLE coverage keys (e.g.
 * P1's `tblCreditPeople.Type`) to the new `tblMusicians.Type` key — see that file's
 * doc-block for detail. Nothing here needs its own `@migration-adds` doctag: every
 * object this script touches already exists somewhere in migration history under its
 * old name; nothing is genuinely NEW.
 *
 * STRICTLY IDEMPOTENT: every step re-checks the live schema before acting; a second run
 * on an already-migrated DB is a clean no-op end to end (verified by hand).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-musicians-rename.php
 *   Web:  /manage/setup-database -> "Musicians rename — schema + compat views (#1741 P2)"
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/catalogue-expansion-1741-plan.md §3.A
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* ELI5: print one progress line, in CLI or HTML form depending on where we run.
   WHY: mirrors every sibling migrate-*.php's output helper (e.g.
   migrate-musician-profile.php) so the dashboard's live <pre> capture and a terminal
   both render this legibly. */
function _migMusRename_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

function _migMusRename_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migMusRename_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migMusRename_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }

/** Does a BASE TABLE (never a VIEW) exist under this name? `SHOW TABLES LIKE` (and
 *  INFORMATION_SCHEMA.TABLES without a TABLE_TYPE filter) matches views too — which
 *  is exactly wrong for Step 2's re-run check, since the whole point of Step 6 is
 *  that the 7 OLD names become VIEWs. Without this distinction, a second run of this
 *  script sees the old names "exist" (as views) AND the new names "exist" (as base
 *  tables) and misreads that as the inconsistent-partial-state error case — caught by
 *  hand while verifying idempotency, not from reading the plan. Used ONLY for the
 *  Step 2 old-name re-run check; every other exists-check in this file targets a name
 *  that never becomes a view (the new tblMusician* names, or unrelated tables), so
 *  the plain SHOW-TABLES-based _migMusRename_tableExists() stays correct there. */
function _migMusRename_baseTableExists(\mysqli $db, string $t): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE' LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/** Is a named FK constraint present anywhere in this schema? Constraint names are
 *  unique per-schema in MySQL/MariaDB, so — mirroring migrate-works-identity.php's
 *  _migWorksId_fkExists() — no table qualifier is needed. */
function _migMusRename_fkExists(\mysqli $db, string $name): bool {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '" . $db->real_escape_string($name) . "' LIMIT 1"
    );
    return (bool)($r && $r->num_rows > 0);
}

function _migMusRename_dataType(\mysqli $db, string $t, string $c): string {
    $r = $db->query(
        "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($t) . "'
            AND COLUMN_NAME = '" . $db->real_escape_string($c) . "' LIMIT 1"
    );
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? strtolower((string)$row['DATA_TYPE']) : '';
}

/** Current COLUMN_COMMENT text, or '' if the column doesn't exist — used to guard the
 *  Step 5 MODIFY COLUMNs so a re-run doesn't rewrite an already-updated comment. */
function _migMusRename_colComment(\mysqli $db, string $t, string $c): string {
    $stmt = $db->prepare(
        "SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['COLUMN_COMMENT'] : '';
}

/** Current COLUMN_DEFAULT, or null when absent/NULL-default — used to guard the
 *  AppliesTo DEFAULT widening in Step 7. Strips a surrounding single-quote pair
 *  when present: MariaDB (10.2.7+, this environment) reports a string column's
 *  DEFAULT as the literal `'value'` (quotes included) via INFORMATION_SCHEMA,
 *  while MySQL reports the bare `value` — caught by hand when the exact-match
 *  guard below never matched on a re-run against MariaDB and kept re-issuing
 *  the same (harmless but not actually idempotent-detecting) MODIFY every time. */
function _migMusRename_colDefault(\mysqli $db, string $t, string $c): ?string {
    $stmt = $db->prepare(
        "SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['COLUMN_DEFAULT'] === null) { return null; }
    $v = (string)$row['COLUMN_DEFAULT'];
    if (strlen($v) >= 2 && $v[0] === "'" && $v[strlen($v) - 1] === "'") {
        $v = substr($v, 1, -1);
    }
    return $v;
}

/** Rename a column, guarded both ways: skip if the NEW name is already there (done),
 *  warn-skip if neither name is present (the owning table probably doesn't exist —
 *  shouldn't happen once the precondition gate has passed, but never fatal here).
 *  $sql is the FULL literal `ALTER TABLE tbl RENAME COLUMN old TO new` statement,
 *  passed in (not built here) so it appears verbatim in this file's SOURCE TEXT —
 *  includes/schema_audit.php's schemaAuditScanMigrations() regex-scans migration
 *  files for exactly that literal shape to stay rename-aware (#1741 P2 Deliverable
 *  4); a dynamically-interpolated statement built only inside this function would
 *  be invisible to that static scan. $table/$old/$new stay separate params purely
 *  for the idempotency guard checks above — deliberate small duplication with the
 *  literal $sql, not a mistake. */
function _migMusRename_renameColumn(\mysqli $db, string $table, string $old, string $new, string $sql): void {
    if (_migMusRename_colExists($db, $table, $new)) {
        _migMusRename_output("  [SKIP] {$table}.{$new} already present.");
        return;
    }
    if (!_migMusRename_colExists($db, $table, $old)) {
        _migMusRename_output("  [WARN] Neither {$table}.{$old} nor {$table}.{$new} found — skipping (table may be absent).");
        return;
    }
    $db->query($sql);
    _migMusRename_output("  [OK] {$table}.{$old} -> {$table}.{$new}.");
}

/** Rename an index, guarded both ways — same shape as _migMusRename_renameColumn(). */
function _migMusRename_renameIndex(\mysqli $db, string $table, string $old, string $new): void {
    if (_migMusRename_idxExists($db, $table, $new)) {
        _migMusRename_output("  [SKIP] index {$table}.{$new} already present.");
        return;
    }
    if (!_migMusRename_idxExists($db, $table, $old)) {
        _migMusRename_output("  [WARN] Neither index {$table}.{$old} nor {$table}.{$new} found — skipping.");
        return;
    }
    $db->query("ALTER TABLE {$table} RENAME INDEX {$old} TO {$new}");
    _migMusRename_output("  [OK] index {$table}.{$old} -> {$new}.");
}

/** Swap an FK constraint's NAME (MySQL/MariaDB has no RENAME for constraints — this is
 *  DROP the old, ADD the new with the SAME columns/reference/actions, per plan §3.A.3).
 *  $addClause is the full "FOREIGN KEY (...) REFERENCES ... ON DELETE ... [ON UPDATE ...]"
 *  tail, copied verbatim from the pre-rename schema.sql block for that constraint. */
function _migMusRename_renameFk(\mysqli $db, string $table, string $old, string $new, string $addClause): void {
    if (_migMusRename_fkExists($db, $new)) {
        _migMusRename_output("  [SKIP] constraint {$new} already present.");
        return;
    }
    if (!_migMusRename_fkExists($db, $old)) {
        _migMusRename_output("  [WARN] Neither constraint {$old} nor {$new} found on {$table} — skipping.");
        return;
    }
    $db->query("ALTER TABLE {$table} DROP FOREIGN KEY {$old}, ADD CONSTRAINT {$new} {$addClause}");
    _migMusRename_output("  [OK] constraint {$table}.{$old} -> {$new}.");
}

/**
 * Precondition gate: are all 4 #1741 P1 cards (musician-profile, works-identity,
 * tune-enrichment, song-identity-fields) fully applied? Mirrors each card's registry
 * probe in includes/migration-registry.php (that OR-probe reports PENDING on any one
 * condition; this returns APPLIED only when ALL conditions hold — the exact negation).
 * Only called when tblMusicians does NOT yet exist (see the Step 0 short-circuit in the
 * main body) so it always reads the OLD (pre-rename) table names, never the views.
 *
 * @return array{0: bool, 1: list<string>}  [$allApplied, $failureReasons]
 */
function _migMusRename_p1Applied(\mysqli $db): array
{
    $reasons = [];

    /* --- musician-profile --- */
    foreach ([
        ['tblCreditPeople', 'Type'], ['tblCreditPeople', 'Disambiguation'], ['tblCreditPeople', 'Biography'],
        ['tblCreditPersonMembers', 'RelationType'], ['tblCreditPersonMembers', 'DateFrom'],
        ['tblCreditPersonMembers', 'DateFromPrecision'], ['tblCreditPersonMembers', 'DateTo'],
        ['tblCreditPersonMembers', 'DateToPrecision'], ['tblCreditPersonMembers', 'Note'],
        ['tblCreditPersonMembers', 'UpdatedAt'],
    ] as [$t, $c]) {
        if (!_migMusRename_colExists($db, $t, $c)) { $reasons[] = "musician-profile: {$t}.{$c} missing"; }
    }
    if (!_migMusRename_idxExists($db, 'tblCreditPersonMembers', 'uq_group_member_rel')) {
        $reasons[] = 'musician-profile: tblCreditPersonMembers.uq_group_member_rel index missing';
    }
    if (_migMusRename_idxExists($db, 'tblCreditPersonMembers', 'uq_group_member')) {
        $reasons[] = 'musician-profile: legacy tblCreditPersonMembers.uq_group_member index still present';
    }
    if (_migMusRename_dataType($db, 'tblCreditPersonAliases', 'Type') !== 'varchar') {
        $reasons[] = 'musician-profile: tblCreditPersonAliases.Type not yet VARCHAR';
    }

    /* --- works-identity --- */
    foreach ([
        ['tblWorks', 'Ccli'], ['tblWorks', 'Subtitle'], ['tblWorks', 'Disambiguation'],
        ['tblWorks', 'TuneName'], ['tblWorks', 'TuneId'], ['tblWorks', 'FirstPublishedYear'],
        ['tblWorks', 'CopyrightYears'], ['tblWorks', 'CopyrightHolder'],
    ] as [$t, $c]) {
        if (!_migMusRename_colExists($db, $t, $c)) { $reasons[] = "works-identity: {$t}.{$c} missing"; }
    }
    if (!_migMusRename_idxExists($db, 'tblWorks', 'idx_TuneId')) { $reasons[] = 'works-identity: tblWorks.idx_TuneId missing'; }
    if (!_migMusRename_idxExists($db, 'tblWorks', 'uq_ccli')) { $reasons[] = 'works-identity: tblWorks.uq_ccli missing'; }
    if (!_migMusRename_fkExists($db, 'fk_Works_Tune')) { $reasons[] = 'works-identity: fk_Works_Tune missing'; }

    /* --- tune-enrichment --- (its tblTuneCredits FK to tblCreditPeople is WHY this
       gate exists at all — see the file doc-block) */
    if (!_migMusRename_colExists($db, 'tblTunes', 'Subtitle')) { $reasons[] = 'tune-enrichment: tblTunes.Subtitle missing'; }
    if (!_migMusRename_colExists($db, 'tblTunes', 'Disambiguation')) { $reasons[] = 'tune-enrichment: tblTunes.Disambiguation missing'; }
    if (!_migMusRename_tableExists($db, 'tblTuneCredits')) { $reasons[] = 'tune-enrichment: tblTuneCredits missing'; }
    if (!_migMusRename_tableExists($db, 'tblTuneExternalLinks')) { $reasons[] = 'tune-enrichment: tblTuneExternalLinks missing'; }
    if (_migMusRename_dataType($db, 'tblExternalLinkTypes', 'Category') !== 'varchar') {
        $reasons[] = 'tune-enrichment: tblExternalLinkTypes.Category not yet VARCHAR';
    }
    if (_migMusRename_dataType($db, 'tblExternalLinkTypes', 'AppliesTo') !== 'varchar') {
        $reasons[] = 'tune-enrichment: tblExternalLinkTypes.AppliesTo not yet VARCHAR';
    }

    /* --- song-identity-fields --- */
    foreach ([
        ['tblSongs', 'Subtitle'], ['tblSongs', 'Disambiguation'],
        ['tblSongs', 'CopyrightYears'], ['tblSongs', 'CopyrightHolder'], ['tblSongs', 'FirstPublishedYear'],
    ] as [$t, $c]) {
        if (!_migMusRename_colExists($db, $t, $c)) { $reasons[] = "song-identity-fields: {$t}.{$c} missing"; }
    }
    if (!_migMusRename_idxExists($db, 'tblSongs', 'idx_Iswc')) { $reasons[] = 'song-identity-fields: tblSongs.idx_Iswc missing'; }
    if (!_migMusRename_idxExists($db, 'tblSongs', 'idx_Ccli')) { $reasons[] = 'song-identity-fields: tblSongs.idx_Ccli missing'; }

    return [$reasons === [], $reasons];
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migMusRename_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migMusRename_output("");
_migMusRename_output("=== iHymns — Musicians rename schema batch (#1741 P2-A) ===");
_migMusRename_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migMusRename_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migMusRename_output("Connected to MySQL: " . DB_NAME);

try {
    /* ==== Step 0/1 — idempotency short-circuit + precondition gate ==== */
    $alreadyRenamed = _migMusRename_tableExists($mysql, 'tblMusicians');

    if (!$alreadyRenamed) {
        _migMusRename_output("--- Precondition gate: 4 x #1741 P1 cards must be fully applied ---");
        [$p1Ok, $p1Reasons] = _migMusRename_p1Applied($mysql);
        if (!$p1Ok) {
            _migMusRename_output("  [ABORT] One or more #1741 P1 cards are not yet fully applied:");
            foreach ($p1Reasons as $r) { _migMusRename_output("           - {$r}"); }
            _migMusRename_output("");
            _migMusRename_output("  Run 'Musician profile schema', 'Works identity schema', 'Tune enrichment");
            _migMusRename_output("  schema' and 'Song identity fields' (in that dashboard order) first, then");
            _migMusRename_output("  re-run this migration. Running the rename early is unsafe: tune-enrichment's");
            _migMusRename_output("  tblTuneCredits carries its OWN foreign key to tblCreditPeople, and a foreign");
            _migMusRename_output("  key may only reference a base table — never a view.");
            $mysql->close();
            return;
        }
        _migMusRename_output("  [OK] All 4 P1 cards are fully applied — safe to rename.");
    } else {
        _migMusRename_output("--- tblMusicians already exists — rename previously completed; re-running remaining idempotent steps only ---");
    }

    /* ==== Step 2 — atomic RENAME TABLE (7 tables) ====
       Branches on $alreadyRenamed (computed once above from tblMusicians alone —
       a name that is NEVER a view, so unambiguous) rather than re-deriving the
       old/new presence with a plain SHOW-TABLES-style check: `SHOW TABLES LIKE`
       (and unfiltered INFORMATION_SCHEMA.TABLES) match VIEWS as well as base
       tables, and by the time Step 6 has run once, all 7 OLD names legitimately
       "exist" again — as views. A naive re-check would see old-present=7 AND
       new-present=7 on a perfectly healthy second run and misreport the
       inconsistent-partial-state error below. Caught by hand while verifying
       idempotency (a live re-run), which is exactly why that verification step
       exists. */
    _migMusRename_output("");
    _migMusRename_output("--- Step 2: RENAME TABLE (7 tables, one atomic statement) ---");
    $renameMap = [
        'tblCreditPeople'              => 'tblMusicians',
        'tblCreditPersonIdentifiers'   => 'tblMusicianIdentifiers',
        'tblCreditPersonExternalLinks' => 'tblMusicianExternalLinks',
        'tblCreditPersonAliases'       => 'tblMusicianAliases',
        'tblCreditPersonMembers'       => 'tblMusicianRelations',
        'tblCreditPersonLinks'         => 'tblMusicianLinks',
        'tblCreditPersonIPI'           => 'tblMusicianIPI',
    ];
    if ($alreadyRenamed) {
        _migMusRename_output("  [SKIP] All 7 tables already renamed.");
    } else {
        /* The precondition gate already ran in this branch, but re-confirm — as a
           BASE TABLE, not merely "a name that resolves" — that every OLD name is
           still there right before the atomic rename, and report precisely which
           are missing if not (defence in depth; should be unreachable given the
           gate above, but a clear message beats a bare mysqli exception). */
        $missingOld = [];
        foreach (array_keys($renameMap) as $old) {
            if (!_migMusRename_baseTableExists($mysql, $old)) { $missingOld[] = $old; }
        }
        if ($missingOld) {
            _migMusRename_output("  [ERROR] Expected base table(s) not found — manual DBA review required:");
            _migMusRename_output("          missing: " . implode(', ', $missingOld));
            $mysql->close();
            return;
        }
        $mysql->query(
            "RENAME TABLE
                tblCreditPeople TO tblMusicians,
                tblCreditPersonIdentifiers TO tblMusicianIdentifiers,
                tblCreditPersonExternalLinks TO tblMusicianExternalLinks,
                tblCreditPersonAliases TO tblMusicianAliases,
                tblCreditPersonMembers TO tblMusicianRelations,
                tblCreditPersonLinks TO tblMusicianLinks,
                tblCreditPersonIPI TO tblMusicianIPI"
        );
        _migMusRename_output("  [OK] Renamed all 7 tables (tblCreditPe*/tblCreditPerson* -> tblMusician*).");
    }

    /* ==== Step 3 — RENAME COLUMN (10 renames across 9 tables; metadata-only, FK
       definitions auto-follow — verified live in a scratch DB before this script was
       written) ==== */
    _migMusRename_output("");
    _migMusRename_output("--- Step 3: RENAME COLUMN (CreditPersonId -> MusicianId; Group/MemberPersonId -> Subject/ObjectMusicianId) ---");
    _migMusRename_renameColumn($mysql, 'tblMusicianIdentifiers', 'CreditPersonId', 'MusicianId',
        'ALTER TABLE tblMusicianIdentifiers RENAME COLUMN CreditPersonId TO MusicianId');
    _migMusRename_renameColumn($mysql, 'tblMusicianExternalLinks', 'CreditPersonId', 'MusicianId',
        'ALTER TABLE tblMusicianExternalLinks RENAME COLUMN CreditPersonId TO MusicianId');
    _migMusRename_renameColumn($mysql, 'tblMusicianAliases', 'CreditPersonId', 'MusicianId',
        'ALTER TABLE tblMusicianAliases RENAME COLUMN CreditPersonId TO MusicianId');
    _migMusRename_renameColumn($mysql, 'tblMusicianLinks', 'CreditPersonId', 'MusicianId',
        'ALTER TABLE tblMusicianLinks RENAME COLUMN CreditPersonId TO MusicianId');
    _migMusRename_renameColumn($mysql, 'tblMusicianIPI', 'CreditPersonId', 'MusicianId',
        'ALTER TABLE tblMusicianIPI RENAME COLUMN CreditPersonId TO MusicianId');
    _migMusRename_renameColumn($mysql, 'tblMusicianRelations', 'GroupPersonId', 'SubjectMusicianId',
        'ALTER TABLE tblMusicianRelations RENAME COLUMN GroupPersonId TO SubjectMusicianId');
    _migMusRename_renameColumn($mysql, 'tblMusicianRelations', 'MemberPersonId', 'ObjectMusicianId',
        'ALTER TABLE tblMusicianRelations RENAME COLUMN MemberPersonId TO ObjectMusicianId');
    _migMusRename_renameColumn($mysql, 'tblSongbookCompilers', 'CreditPersonId', 'MusicianId',
        'ALTER TABLE tblSongbookCompilers RENAME COLUMN CreditPersonId TO MusicianId');
    _migMusRename_renameColumn($mysql, 'tblTuneCredits', 'CreditPersonId', 'MusicianId',
        'ALTER TABLE tblTuneCredits RENAME COLUMN CreditPersonId TO MusicianId');
    _migMusRename_renameColumn($mysql, 'tblVocalParts', 'CreditPersonId', 'MusicianId',
        'ALTER TABLE tblVocalParts RENAME COLUMN CreditPersonId TO MusicianId');

    /* ==== Step 4 — RENAME INDEX + FK constraint DROP/ADD (same cols/refs/actions) ==== */
    _migMusRename_output("");
    _migMusRename_output("--- Step 4: RENAME INDEX + swap FK constraint names ---");

    _migMusRename_output("  tblMusicians:");
    _migMusRename_renameFk($mysql, 'tblMusicians', 'fk_CreditPeople_BirthPlace', 'fk_Musicians_BirthPlace',
        'FOREIGN KEY (BirthPlaceId) REFERENCES tblPlaces(Id) ON DELETE SET NULL ON UPDATE CASCADE');
    _migMusRename_renameFk($mysql, 'tblMusicians', 'fk_CreditPeople_DeathPlace', 'fk_Musicians_DeathPlace',
        'FOREIGN KEY (DeathPlaceId) REFERENCES tblPlaces(Id) ON DELETE SET NULL ON UPDATE CASCADE');

    _migMusRename_output("  tblMusicianLinks:");
    _migMusRename_renameIndex($mysql, 'tblMusicianLinks', 'idx_CreditPersonId', 'idx_MusicianId');
    _migMusRename_renameFk($mysql, 'tblMusicianLinks', 'fk_CreditPersonLinks_Person', 'fk_MusicianLinks_Musician',
        'FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE ON UPDATE CASCADE');

    _migMusRename_output("  tblMusicianIPI:");
    _migMusRename_renameIndex($mysql, 'tblMusicianIPI', 'uk_PersonIPI', 'uk_MusicianIPI');
    _migMusRename_renameFk($mysql, 'tblMusicianIPI', 'fk_CreditPersonIPI_Person', 'fk_MusicianIPI_Musician',
        'FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE ON UPDATE CASCADE');

    _migMusRename_output("  tblMusicianIdentifiers:");
    _migMusRename_renameIndex($mysql, 'tblMusicianIdentifiers', 'uk_PersonIdValue', 'uk_MusicianIdValue');
    _migMusRename_renameFk($mysql, 'tblMusicianIdentifiers', 'fk_CreditPersonId_Person', 'fk_MusicianIdentifiers_Musician',
        'FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE ON UPDATE CASCADE');

    _migMusRename_output("  tblMusicianExternalLinks:");
    _migMusRename_renameIndex($mysql, 'tblMusicianExternalLinks', 'idx_person', 'idx_musician');
    _migMusRename_renameFk($mysql, 'tblMusicianExternalLinks', 'fk_link_person', 'fk_link_musician',
        'FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE');
    _migMusRename_renameFk($mysql, 'tblMusicianExternalLinks', 'fk_link_type_person', 'fk_link_type_musician',
        'FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT');

    _migMusRename_output("  tblMusicianAliases:");
    _migMusRename_renameIndex($mysql, 'tblMusicianAliases', 'uq_person_name', 'uq_musician_name');
    _migMusRename_renameIndex($mysql, 'tblMusicianAliases', 'idx_person', 'idx_musician');
    _migMusRename_renameFk($mysql, 'tblMusicianAliases', 'fk_alias_person', 'fk_alias_musician',
        'FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE');

    _migMusRename_output("  tblMusicianRelations:");
    _migMusRename_renameIndex($mysql, 'tblMusicianRelations', 'uq_group_member_rel', 'uq_subject_object_rel');
    _migMusRename_renameIndex($mysql, 'tblMusicianRelations', 'idx_group', 'idx_subject');
    _migMusRename_renameIndex($mysql, 'tblMusicianRelations', 'idx_member', 'idx_object');
    _migMusRename_renameFk($mysql, 'tblMusicianRelations', 'fk_creditpersonmembers_group', 'fk_musicianrelations_subject',
        'FOREIGN KEY (SubjectMusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE');
    _migMusRename_renameFk($mysql, 'tblMusicianRelations', 'fk_creditpersonmembers_member', 'fk_musicianrelations_object',
        'FOREIGN KEY (ObjectMusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE');

    _migMusRename_output("  tblSongbookCompilers:");
    _migMusRename_renameIndex($mysql, 'tblSongbookCompilers', 'uq_book_person', 'uq_book_musician');
    _migMusRename_renameIndex($mysql, 'tblSongbookCompilers', 'idx_person', 'idx_musician');
    _migMusRename_renameFk($mysql, 'tblSongbookCompilers', 'fk_compiler_person', 'fk_compiler_musician',
        'FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE');

    _migMusRename_output("  tblTuneCredits:");
    _migMusRename_renameIndex($mysql, 'tblTuneCredits', 'idx_CreditPersonId', 'idx_MusicianId');
    _migMusRename_renameFk($mysql, 'tblTuneCredits', 'fk_TuneCredits_Person', 'fk_TuneCredits_Musician',
        'FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE SET NULL ON UPDATE CASCADE');

    _migMusRename_output("  tblVocalParts:");
    _migMusRename_renameIndex($mysql, 'tblVocalParts', 'idx_Person', 'idx_Musician');
    _migMusRename_renameFk($mysql, 'tblVocalParts', 'fk_VocalParts_Person', 'fk_VocalParts_Musician',
        'FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE SET NULL ON UPDATE CASCADE');

    /* ==== Step 5 — MODIFY COLUMN for the 5 COMMENTs that literally name tblCreditPe* ==== */
    _migMusRename_output("");
    _migMusRename_output("--- Step 5: update COMMENT prose naming tblCreditPe*/tblCreditPerson* ---");

    if (str_contains(_migMusRename_colComment($mysql, 'tblMusicianRelations', 'SubjectMusicianId'), 'tblCreditPe')) {
        $mysql->query(
            "ALTER TABLE tblMusicianRelations
                MODIFY COLUMN SubjectMusicianId INT UNSIGNED NOT NULL
                    COMMENT 'FK to tblMusicians.Id — the Group/band/collective musician (subject side of the relation) (#1741 P2)'"
        );
        _migMusRename_output("  [OK] Updated tblMusicianRelations.SubjectMusicianId comment.");
    } else {
        _migMusRename_output("  [SKIP] tblMusicianRelations.SubjectMusicianId comment already updated.");
    }

    if (str_contains(_migMusRename_colComment($mysql, 'tblMusicianRelations', 'ObjectMusicianId'), 'tblCreditPe')) {
        $mysql->query(
            "ALTER TABLE tblMusicianRelations
                MODIFY COLUMN ObjectMusicianId INT UNSIGNED NOT NULL
                    COMMENT 'FK to tblMusicians.Id — an individual member musician (object side of the relation) (#1741 P2)'"
        );
        _migMusRename_output("  [OK] Updated tblMusicianRelations.ObjectMusicianId comment.");
    } else {
        _migMusRename_output("  [SKIP] tblMusicianRelations.ObjectMusicianId comment already updated.");
    }

    if (str_contains(_migMusRename_colComment($mysql, 'tblTuneCredits', 'Name'), 'tblCreditPe')) {
        $mysql->query(
            "ALTER TABLE tblTuneCredits
                MODIFY COLUMN Name VARCHAR(255) NOT NULL
                    COMMENT 'Credited name-string, matching the tblSong*/tblWork credit-table pattern (no FK to tblMusicians by default)'"
        );
        _migMusRename_output("  [OK] Updated tblTuneCredits.Name comment.");
    } else {
        _migMusRename_output("  [SKIP] tblTuneCredits.Name comment already updated.");
    }

    if (str_contains(_migMusRename_colComment($mysql, 'tblTuneCredits', 'MusicianId'), 'tblCreditPe')) {
        $mysql->query(
            "ALTER TABLE tblTuneCredits
                MODIFY COLUMN MusicianId INT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'Reserved FK to tblMusicians.Id (#1741 P1/P2) — nullable/dormant so the credits name-string-vs-FK decision costs zero ALTER either way; unused until that decision lands'"
        );
        _migMusRename_output("  [OK] Updated tblTuneCredits.MusicianId comment.");
    } else {
        _migMusRename_output("  [SKIP] tblTuneCredits.MusicianId comment already updated.");
    }

    if (str_contains(_migMusRename_colComment($mysql, 'tblVocalParts', 'MusicianId'), 'tblCreditPe')) {
        $mysql->query(
            "ALTER TABLE tblVocalParts
                MODIFY COLUMN MusicianId INT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'FK to tblMusicians.Id — typed named-singer link (reuses the musician registry, NOT a new tblArtists)'"
        );
        _migMusRename_output("  [OK] Updated tblVocalParts.MusicianId comment.");
    } else {
        _migMusRename_output("  [SKIP] tblVocalParts.MusicianId comment already updated.");
    }

    /* ==== Step 6 — back-compat VIEWs, one per old table name. Explicit column lists
       (never SELECT *); every renamed column is aliased back to its old name so old
       code's column references keep resolving unchanged. CREATE OR REPLACE is always
       safe here — by this point the old base-table name is guaranteed free (Step 2
       already renamed it away, in this run or a prior one). ==== */
    _migMusRename_output("");
    _migMusRename_output("--- Step 6: back-compat VIEWs (old table names -> new base tables) ---");

    $mysql->query(
        "CREATE OR REPLACE VIEW tblCreditPeople AS
            SELECT Id, Name, Slug, MusicBrainzArtistMBID, IsSpecialCase, IsGroup, Type, Disambiguation,
                   FirstNames, Surname, MaidenSurname, Suffix, Notes, Biography, BirthPlace, BirthPlaceId,
                   BirthDate, BirthDatePrecision, DeathPlace, DeathPlaceId, DeathDate, DeathDatePrecision,
                   CreatedAt, UpdatedAt
              FROM tblMusicians"
    );
    _migMusRename_output("  [OK] VIEW tblCreditPeople -> tblMusicians.");

    $mysql->query(
        "CREATE OR REPLACE VIEW tblCreditPersonLinks AS
            SELECT Id, MusicianId AS CreditPersonId, LinkType, Url, Label, SortOrder, CreatedAt, UpdatedAt
              FROM tblMusicianLinks"
    );
    _migMusRename_output("  [OK] VIEW tblCreditPersonLinks -> tblMusicianLinks.");

    $mysql->query(
        "CREATE OR REPLACE VIEW tblCreditPersonIPI AS
            SELECT Id, MusicianId AS CreditPersonId, IPINumber, NameUsed, Notes, CreatedAt, UpdatedAt
              FROM tblMusicianIPI"
    );
    _migMusRename_output("  [OK] VIEW tblCreditPersonIPI -> tblMusicianIPI.");

    $mysql->query(
        "CREATE OR REPLACE VIEW tblCreditPersonIdentifiers AS
            SELECT Id, MusicianId AS CreditPersonId, IdentifierType, IdentifierValue, NameUsed, Notes,
                   CreatedAt, UpdatedAt
              FROM tblMusicianIdentifiers"
    );
    _migMusRename_output("  [OK] VIEW tblCreditPersonIdentifiers -> tblMusicianIdentifiers.");

    $mysql->query(
        "CREATE OR REPLACE VIEW tblCreditPersonExternalLinks AS
            SELECT Id, MusicianId AS CreditPersonId, LinkTypeId, Url, Note, SortOrder, Verified,
                   CreatedAt, UpdatedAt
              FROM tblMusicianExternalLinks"
    );
    _migMusRename_output("  [OK] VIEW tblCreditPersonExternalLinks -> tblMusicianExternalLinks.");

    $mysql->query(
        "CREATE OR REPLACE VIEW tblCreditPersonAliases AS
            SELECT Id, MusicianId AS CreditPersonId, Name, SortName, Type, Locale, IsPrimary, SortOrder,
                   Note, CreatedAt, UpdatedAt
              FROM tblMusicianAliases"
    );
    _migMusRename_output("  [OK] VIEW tblCreditPersonAliases -> tblMusicianAliases.");

    $mysql->query(
        "CREATE OR REPLACE VIEW tblCreditPersonMembers AS
            SELECT Id, SubjectMusicianId AS GroupPersonId, ObjectMusicianId AS MemberPersonId, RelationType,
                   DateFrom, DateFromPrecision, DateTo, DateToPrecision, Note, SortOrder, CreatedAt, UpdatedAt
              FROM tblMusicianRelations"
    );
    _migMusRename_output("  [OK] VIEW tblCreditPersonMembers -> tblMusicianRelations.");

    /* ==== Step 7 — tblExternalLinkTypes.AppliesTo: ADD 'musician' alongside the
       retained 'person' token (see the deviation note in the file doc-block for why
       this is additive, not a destructive rewrite). ==== */
    _migMusRename_output("");
    _migMusRename_output("--- Step 7: tblExternalLinkTypes.AppliesTo — add 'musician' token (retain legacy 'person') ---");

    $curDefault = _migMusRename_colDefault($mysql, 'tblExternalLinkTypes', 'AppliesTo');
    if ($curDefault === 'song,songbook,person,musician') {
        _migMusRename_output("  [SKIP] DEFAULT already widened to include 'musician'.");
    } else {
        $mysql->query(
            "ALTER TABLE tblExternalLinkTypes
                MODIFY AppliesTo VARCHAR(255) NOT NULL DEFAULT 'song,songbook,person,musician'
                    COMMENT 'CSV of applicable entity types: song | songbook | musician | work | tune (legacy alias \"person\" also recognised for pre-#1741-P2-B app-code back-compat — see migrate-musicians-rename.php; retired once the app-code rename lands) (app-validated via FIND_IN_SET; widened from SET so a new entity type never needs an ALTER, rule #20, #1741 P1/P2)'"
        );
        _migMusRename_output("  [OK] Widened DEFAULT to 'song,songbook,person,musician'.");
    }

    $mysql->query(
        "UPDATE tblExternalLinkTypes
            SET AppliesTo = CONCAT(AppliesTo, ',musician')
          WHERE FIND_IN_SET('person', AppliesTo) > 0
            AND FIND_IN_SET('musician', AppliesTo) = 0"
    );
    $touched = $mysql->affected_rows;
    _migMusRename_output("  [OK] Added 'musician' token to {$touched} row(s) already carrying 'person' (both tokens retained).");

    _migMusRename_output("");
    _migMusRename_output("Migration complete.");
} catch (\Throwable $e) {
    _migMusRename_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
