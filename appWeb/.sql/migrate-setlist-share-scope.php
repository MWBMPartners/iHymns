<?php

declare(strict_types=1);

/**
 * iHymns — Set-list share capability columns (#1791)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * One-pass forward-looking schema (rule #20) for collab-by-link set-list
 * sharing — an EDIT-scope capability URL a set-list owner can hand out with
 * no email invite and no account for the recipient (the owner's #1791 ask,
 * "like cloud storage"). Additive + dormant: after this card runs, NOTHING
 * reads or writes any of the new columns yet (the server token model lands in
 * C2, the client in C4/C5), so applying it changes ZERO observable behaviour —
 * every existing view-share link keeps resolving byte-identically.
 *
 * `tblSharedSetlists` already models "a capability pointing at a live set
 * list" (#1380 live links). This EXTENDS that one table rather than forking a
 * parallel edit-share table (rule #20 reuse-first, the brief's instruction):
 *
 *   - ShareId widened VARCHAR(16) -> VARCHAR(64): legacy 8-hex view ids
 *     (32-bit) stay valid forever (rule #33); new links are base64url
 *     capability tokens — 22 chars/128-bit for view (#1648's "stronger fix"),
 *     43 chars/256-bit for edit (the tblServicePresence.PresenceToken shape,
 *     rule #26). The MODIFY is gated on the current width so a second run is a
 *     no-op.
 *   - Scope VARCHAR(10) DEFAULT 'view' — 'view' | 'edit', app-validated
 *     against a central map, never ENUM (rule #20: an ENUM value-add — say a
 *     future 'comment'/'suggest' — is an ALTER, the second migration we
 *     forbid). 'edit' implies 'view'. DEFAULT 'view' is why every legacy row
 *     is read exactly as before: the write path requires Scope='edit', which
 *     only the authenticated-owner mint path can set.
 *   - Label VARCHAR(100) NULL — owner-facing link name ("worship team"),
 *     display only.
 *   - RevokedAt DATETIME NULL — hard revocation instant (UTC); non-NULL = the
 *     link refuses at every scope, before any live resolution.
 *   - ExpiresAt DATETIME NULL — optional expiry (UTC), NULL = never (gate G1
 *     default). DATETIME not TIMESTAMP (rule #20 TTL convention: no
 *     auto-update, no 2038, explicit UTC).
 *   - LastUsedAt DATETIME NULL — last successful token read/write, the
 *     owner-facing "is this link in use?" signal.
 *   - EditCount INT UNSIGNED DEFAULT 0 — successful token WRITES (mirrors the
 *     existing ViewCount).
 *   - idx_Expiry (ExpiresAt) — a future expiry-sweep prune reads it.
 *
 * No Channel column, deliberately: shares are cross-docroot like
 * tblUserSetlists (the collab endpoints state that rationale); the rule-#26
 * channel wall applies to LIVE sessions, not durable capability rows.
 *
 * ----------------------------------------------------------------------
 * OWNER DECISIONS G3 / G4 / G4-org — RESOLVED 2026-08-11 (folded into this
 * SAME migration, rule #19/#20: this card has never been deployed — no PR
 * merged — so extending it in place is still genuinely one-pass; a second
 * migration file would be the dribbled-ALTER regression these rules forbid).
 * See .claude/setlist-sharing-1790-1791-plan.md §0.5 for the full spec.
 *
 * TWO more `tblSharedSetlists` per-link columns:
 *   - ShowSharerName TINYINT(1) DEFAULT 0 — G3 opt-in "Shared by <owner
 *     display name>" byline on the shared page. Default 0 = anonymous, the
 *     status quo; setlist_get only echoes the owner's name when this is 1
 *     (the one conditional allow-list key — the privacy invariant holds).
 *   - EditAudience VARCHAR(20) DEFAULT 'anyone' — G4 per-link edit
 *     audience: anyone | authenticated. DEFAULT 'anyone' because the
 *     owner's full ask is "no account needed"; a link may be tightened to
 *     'authenticated' at mint or clamped there by an org mandate (below).
 *     App-validated VARCHAR vocab (SETLIST_EDIT_AUDIENCES,
 *     includes/setlist_collab.php), never ENUM (rule #20).
 *
 * TWO new `tblOrganisations` columns — the G4-org policy layer, added by a
 * SECOND ALTER in this SAME migration (multi-object migration, rule #19),
 * mirroring `LiveIdleTimeoutMins` / `EnforceIdleTimeout` (#1770) verbatim so
 * `setlistResolveEditAudienceDefault()` is a structural copy of
 * `serviceMode_resolveIdleTimeoutMins()`, not a new pattern:
 *   - SetlistEditAudience VARCHAR(20) NULL — org preference for members'
 *     edit links (anyone | authenticated | NULL = no org opinion).
 *   - EnforceSetlistEditAudience TINYINT(1) DEFAULT 0 — 0 = advisory
 *     default a member may loosen; 1 = mandatory cap (a member's edit
 *     links can never be more open than SetlistEditAudience).
 *
 * @migration-adds tblSharedSetlists.Scope
 * @migration-adds tblSharedSetlists.Label
 * @migration-adds tblSharedSetlists.RevokedAt
 * @migration-adds tblSharedSetlists.ExpiresAt
 * @migration-adds tblSharedSetlists.LastUsedAt
 * @migration-adds tblSharedSetlists.EditCount
 * @migration-adds tblSharedSetlists.ShowSharerName
 * @migration-adds tblSharedSetlists.EditAudience
 * @migration-adds tblOrganisations.SetlistEditAudience
 * @migration-adds tblOrganisations.EnforceSetlistEditAudience
 *
 * SCHEMA MIRROR: the widened ShareId, all 8 tblSharedSetlists columns,
 * idx_Expiry, and both tblOrganisations columns are mirrored byte-identical
 * in appWeb/.sql/schema.sql's tblSharedSetlists / tblOrganisations CREATE
 * TABLE blocks — rule #19.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Every ADD COLUMN / ADD KEY is
 * existence-guarded and the ShareId MODIFY is width-guarded, so a second run
 * touches nothing.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-setlist-share-scope.php
 *   Web:  /manage/setup-database → "Set-list share links (#1791)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/setlist-sharing-1790-1791-plan.md §0.5, §3b, §4-C1
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migShareScope_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migShareScope_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migShareScope_idxExists(\mysqli $db, string $t, string $idx): bool { $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'"); return (bool)($r && $r->num_rows > 0); }
/** Current CHARACTER_MAXIMUM_LENGTH of a char column, or 0 if absent/non-char. */
function _migShareScope_colLen(\mysqli $db, string $t, string $c): int {
    $stmt = $db->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && $row['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int)$row['CHARACTER_MAXIMUM_LENGTH'] : 0;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migShareScope_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migShareScope_output("");
_migShareScope_output("=== iHymns — Set-list share capability columns (#1791) ===");
_migShareScope_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migShareScope_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migShareScope_output("Connected to MySQL: " . DB_NAME);

try {
    /* ---- 1. Widen ShareId 16 -> 64 (idempotent, width-guarded) ---- */
    _migShareScope_output("--- tblSharedSetlists.ShareId width ---");
    if (_migShareScope_colLen($mysql, 'tblSharedSetlists', 'ShareId') >= 64) {
        _migShareScope_output("  [SKIP] ShareId already >= 64 chars.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSharedSetlists
                MODIFY ShareId VARCHAR(64) NOT NULL COMMENT '8 hex chars (legacy view links, 32-bit) or 22/43-char base64url capability token (128/256-bit). Widened #1791.'"
        );
        _migShareScope_output("  [OK] Widened ShareId to VARCHAR(64).");
    }

    /* ---- 2. Capability columns (each existence-guarded) ---- */
    _migShareScope_output("--- tblSharedSetlists capability columns ---");
    $cols = [
        'Scope' => "ADD COLUMN Scope VARCHAR(10) NOT NULL DEFAULT 'view'
            COMMENT 'view | edit — app-validated VARCHAR vocab, never ENUM (rule #20). edit implies view.'
            AFTER Data",
        'Label' => "ADD COLUMN Label VARCHAR(100) NULL DEFAULT NULL
            COMMENT 'Owner-facing name for this link (\"worship team\"). Display only.'
            AFTER Scope",
        'RevokedAt' => "ADD COLUMN RevokedAt DATETIME NULL DEFAULT NULL
            COMMENT 'Hard revocation instant (UTC). Non-NULL = link refuses at every scope.'
            AFTER Label",
        'ExpiresAt' => "ADD COLUMN ExpiresAt DATETIME NULL DEFAULT NULL
            COMMENT 'Optional expiry (UTC), NULL = never. DATETIME not TIMESTAMP (rule #20 TTL convention).'
            AFTER RevokedAt",
        'LastUsedAt' => "ADD COLUMN LastUsedAt DATETIME NULL DEFAULT NULL
            COMMENT 'Last successful token READ or WRITE (edit links) — owner-facing \"in use?\" signal.'
            AFTER ExpiresAt",
        'EditCount' => "ADD COLUMN EditCount INT UNSIGNED NOT NULL DEFAULT 0
            COMMENT 'Successful token WRITES through this link (mirrors ViewCount).'
            AFTER LastUsedAt",
        /* --- G3 / G4 (owner decisions, resolved 2026-08-11) --- */
        'ShowSharerName' => "ADD COLUMN ShowSharerName TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'G3 #1791: 1 = shared page shows \"Shared by <owner display name>\"; 0 (default) = anonymous, current posture. Owner-set per link at mint.'
            AFTER EditCount",
        'EditAudience' => "ADD COLUMN EditAudience VARCHAR(20) NOT NULL DEFAULT 'anyone'
            COMMENT 'G4 #1791: who may edit via an edit-scope link — anyone | authenticated. Owner-set per link; DEFAULT anyone (no account needed). App-validated VARCHAR vocab, never ENUM (rule #20). Ignored for view-scope links.'
            AFTER ShowSharerName",
    ];
    foreach ($cols as $col => $clause) {
        if (_migShareScope_colExists($mysql, 'tblSharedSetlists', $col)) {
            _migShareScope_output("  [SKIP] tblSharedSetlists.{$col} already present.");
        } else {
            $mysql->query("ALTER TABLE tblSharedSetlists {$clause}");
            _migShareScope_output("  [OK] Added tblSharedSetlists.{$col}.");
        }
    }

    /* ---- 3. Expiry index (existence-guarded) ---- */
    _migShareScope_output("--- tblSharedSetlists.idx_Expiry ---");
    if (_migShareScope_idxExists($mysql, 'tblSharedSetlists', 'idx_Expiry')) {
        _migShareScope_output("  [SKIP] idx_Expiry already present.");
    } else {
        $mysql->query("ALTER TABLE tblSharedSetlists ADD KEY idx_Expiry (ExpiresAt)");
        _migShareScope_output("  [OK] Added idx_Expiry (ExpiresAt).");
    }

    /* ---- 4. G4-org: the org edit-audience policy layer (tblOrganisations) ----
       A SECOND object in this ONE migration (rule #19 multi-object). Mirrors
       LiveIdleTimeoutMins/EnforceIdleTimeout (#1770) verbatim so the resolver
       (setlistResolveEditAudienceDefault()) is a structural copy of
       serviceMode_resolveIdleTimeoutMins(). tblOrganisations existing on every
       install this migration would plausibly run against (it long predates
       #1791), but it's guarded anyway — a bare/partial install must degrade
       to "column absent, skip", never throw.

       The AFTER anchor is chosen dynamically: EnforceIdleTimeout (#1770) is
       itself a migrated-in column, so an install that hasn't run THAT
       migration yet must not have this ALTER throw on a missing anchor —
       it degrades to appending at the end of the table instead. */
    _migShareScope_output("--- tblOrganisations G4-org policy columns ---");
    if (!$mysql->query("SHOW TABLES LIKE 'tblOrganisations'")->num_rows) {
        _migShareScope_output("  [SKIP] tblOrganisations does not exist on this install.");
    } else {
        $orgAnchor = _migShareScope_colExists($mysql, 'tblOrganisations', 'EnforceIdleTimeout')
            ? 'AFTER EnforceIdleTimeout'
            : '';
        $orgCols = [
            'SetlistEditAudience' => "ADD COLUMN SetlistEditAudience VARCHAR(20) NULL DEFAULT NULL
                COMMENT 'G4-org #1791: this org''s preference for members'' set-list EDIT links — anyone | authenticated | NULL (no org opinion). App-validated VARCHAR vocab.'
                {$orgAnchor}",
            'EnforceSetlistEditAudience' => "ADD COLUMN EnforceSetlistEditAudience TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'G4-org #1791: 0 = SetlistEditAudience is an advisory default a member may loosen; 1 = mandatory cap (a member''s edit links can never be more open than SetlistEditAudience). Mirrors EnforceIdleTimeout (#1770).'
                AFTER SetlistEditAudience",
        ];
        foreach ($orgCols as $col => $clause) {
            if (_migShareScope_colExists($mysql, 'tblOrganisations', $col)) {
                _migShareScope_output("  [SKIP] tblOrganisations.{$col} already present.");
            } else {
                $mysql->query("ALTER TABLE tblOrganisations {$clause}");
                _migShareScope_output("  [OK] Added tblOrganisations.{$col}.");
            }
        }
    }

    _migShareScope_output("");
    _migShareScope_output("=== Done. Set-list share capability columns are in place (dormant until C2/C3). ===");
} catch (\mysqli_sql_exception $e) {
    _migShareScope_output("ERROR: migration failed: " . $e->getMessage());
    return;
}
