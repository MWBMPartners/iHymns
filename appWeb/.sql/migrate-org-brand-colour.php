<?php

declare(strict_types=1);

/**
 * iHymns — Organisation brand colour (#1840)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A church picking a colour that shows up on its shared set-list preview
 * card (the picture that appears when a set-list link is pasted into a chat
 * app). This migration adds ONE column to store that colour, plus a second,
 * empty column reserved for future colour-related settings nobody has
 * asked for yet.
 *
 * DETAILED / PURPOSE
 * ----------------------------------------------------------------------------
 * One-pass forward-looking schema (rule #20) for Share Card Option B — the
 * branded set-list social-preview card (`.claude/org-logo-surfaces-1840-
 * plan.md` §4/§7): a church's own brand colour, so the OG-image band for a
 * shared set-list can be painted in the org's own colour instead of the
 * generic app purple. Additive + dormant: after this card runs, NOTHING
 * reads or writes `BrandColor`/`BrandJson` yet (the admin field and the
 * og-image consumer both land in LATER commits of the same PR), so applying
 * it changes ZERO observable behaviour on any existing install.
 *
 * Adversarial "what would force a second migration?" pass (rule #20): the
 * brand family foreseeably grows — a secondary colour, a dark-theme brand
 * colour, a brand font token. So this ships ONE scalar column for the token
 * the app ACTS ON now (`BrandColor`), plus the house-style dormant JSON bag
 * for the growable vocabulary (`BrandJson` — mirrors
 * `tblOrganisationLogos.MetaJson`, the #1830 precedent already in
 * `schema.sql`). Rule #44 governs FORM fields, not forward-looking schema —
 * no form control is ever rendered for the JSON bag; it stays reserved.
 *
 *   - `BrandColor` VARCHAR(9) NULL — a strict hex token, `#rrggbb` or
 *     `#rrggbbaa`, always lowercase, normalised by
 *     `ihymnsOrgBrandColourNormalise()` (includes/organisation_validation.php)
 *     BEFORE it ever reaches this column — never interpolated into
 *     CSS/HTML anywhere; a malformed value is rejected at the door, never
 *     stored, never echoed unescaped.
 *   - `BrandJson` JSON NULL — dormant grab-bag for future brand tokens
 *     (`secondaryColor`, `darkColor`, a brand font, …). Nothing reads it yet.
 *
 * @migration-adds tblOrganisations.BrandColor
 * @migration-adds tblOrganisations.BrandJson
 *
 * SCHEMA MIRROR: both columns are mirrored byte-identical in
 * `appWeb/.sql/schema.sql`'s `tblOrganisations` CREATE TABLE block,
 * directly after `EnforceSetlistEditAudience` — rule #19.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Each `ADD COLUMN` is existence-guarded, so
 * a second run touches nothing.
 *
 * Rule #41 note: this script needs NO shared `includes/` file (a plain
 * two-column `ALTER TABLE` against DB credentials only) — there is nothing
 * under `/public_html/` this file reaches into, so the `IHYMNS_INCLUDES_DIR`
 * idiom does not arise here.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-org-brand-colour.php
 *   Web:  /manage/setup-database -> "Organisation brand colour (#1840)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/org-logo-surfaces-1840-plan.md §4.1
 * @see #1840
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migOrgBrand_output(string $m): void
{
    global $isCli;
    echo $m . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) {
        flush();
    }
}

function _migOrgBrand_colExists(\mysqli $db, string $t, string $c): bool
{
    $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migOrgBrand_output('ERROR: MySQL credentials not found. Run install.php first.');
    return;
}
require_once $credFile;

_migOrgBrand_output('');
_migOrgBrand_output('=== iHymns — Organisation brand colour (#1840) ===');
_migOrgBrand_output('');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migOrgBrand_output('ERROR: MySQL connection failed: ' . $e->getMessage());
    return;
}
_migOrgBrand_output('Connected to MySQL: ' . DB_NAME);

try {
    /* tblOrganisations long predates #1840, but guarded anyway (the
       migrate-setlist-share-scope.php precedent, #1791) — a bare/partial
       install must degrade to "table absent, skip", never throw. */
    if (!$mysql->query("SHOW TABLES LIKE 'tblOrganisations'")->num_rows) {
        _migOrgBrand_output('  [SKIP] tblOrganisations does not exist on this install.');
        _migOrgBrand_output('');
        _migOrgBrand_output('=== Done. ===');
        return;
    }

    _migOrgBrand_output('--- tblOrganisations brand columns ---');
    /* AFTER anchor chosen dynamically: EnforceSetlistEditAudience (#1791) is
       itself a migrated-in column, so an install that hasn't run THAT
       migration yet must not have this ALTER throw on a missing anchor — it
       degrades to appending at the end of the table instead (the same
       dynamic-anchor idiom migrate-setlist-share-scope.php already uses). */
    $anchor = _migOrgBrand_colExists($mysql, 'tblOrganisations', 'EnforceSetlistEditAudience')
        ? 'AFTER EnforceSetlistEditAudience'
        : '';
    $cols = [
        'BrandColor' => "ADD COLUMN BrandColor VARCHAR(9) NULL DEFAULT NULL
            COMMENT 'Org brand colour as a strict hex token — #rrggbb or #rrggbbaa, lowercase, app-validated by ihymnsOrgBrandColourNormalise() (includes/organisation_validation.php, #1840). NULL = no brand colour; every branded surface (OG share card band) stays dormant'
            {$anchor}",
        'BrandJson' => "ADD COLUMN BrandJson JSON NULL DEFAULT NULL
            COMMENT 'Dormant grab-bag for future brand tokens (secondaryColor, darkColor, font…) — growable vocabulary is JSON, never new columns (rule #20/#28, the tblOrganisationLogos.MetaJson precedent). Nothing reads it yet (#1840)'
            AFTER BrandColor",
    ];
    foreach ($cols as $col => $clause) {
        if (_migOrgBrand_colExists($mysql, 'tblOrganisations', $col)) {
            _migOrgBrand_output("  [SKIP] tblOrganisations.{$col} already present.");
        } else {
            $mysql->query("ALTER TABLE tblOrganisations {$clause}");
            _migOrgBrand_output("  [OK] Added tblOrganisations.{$col}.");
        }
    }

    _migOrgBrand_output('');
    _migOrgBrand_output('=== Done. Organisation brand colour columns are in place (dormant until an org sets one). ===');
} catch (\mysqli_sql_exception $e) {
    _migOrgBrand_output('ERROR: migration failed: ' . $e->getMessage());
    return;
}
