<?php

declare(strict_types=1);

/**
 * tests/php/test-org-licence-admin.php — organisation multi-licence CRUD core
 * guard (#1969)
 * ============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A church can hold several licences at once (CCLI + MRL + …). This guard makes
 * sure every place that adds/edits/removes those licences goes through the ONE
 * shared core (includes/org_licence_admin.php), that NONE of them hard-codes a
 * licence-key list (which is how `mrl`/`custom` got silently dropped), that
 * NONE of them wipes the whole set on every save (the data-loss bug), and that
 * the tier resolver honours a licence's expiry.
 *
 * WHAT THIS ASSERTS
 * -----------------
 *  (A) The core exists and defines every function the four surfaces call.
 *  (B) orgLicenceNormaliseFields() truth table (pure: trim / 100-cap / blank→null
 *      expiry+notes / active parsing from a checkbox string OR a JSON bool).
 *  (C) NO hard-coded licence-key list literal survives in the API write sites
 *      (the `['none','ihymns_basic','ihymns_pro','ccli']` that was MISSING mrl +
 *      custom, and the `['ccli','mrl',…]` per-row list) — rule #9.
 *  (D) NO destructive whole-org wipe (`DELETE FROM tblOrganisationLicences
 *      WHERE OrganisationId = ?` with NO LicenceType predicate) survives in
 *      api.php or organisations.php — the data-loss regression.
 *  (E) Every write surface routes through the core (api.php ×2, organisations.php,
 *      my-organisations.php).
 *  (F) The tier resolver (ccli_validator.php) filters `ExpiresAt` on the
 *      join-table arm, matching includes/licences.php.
 *
 * (C)/(D)/(F) carry self-tests proving the scan can go red; (B) is a
 * behavioural truth table over the real function.
 *
 * USAGE:  php tests/php/test-org-licence-admin.php
 *
 * @see appWeb/public_html/includes/org_licence_admin.php
 */

$ROOT = dirname(__DIR__, 2);
$DOCROOT = $ROOT . '/appWeb/public_html';
require_once $DOCROOT . '/includes/org_licence_admin.php';

$passed = 0; $failed = 0; $failures = [];
function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  \xE2\x9C\x85 {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/** PHP-code-only projection (drop comments) so a banned pattern MENTIONED in a
 *  doc-comment never registers as real code. */
function olaCode(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT || $tok[0] === T_INLINE_HTML) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

$apiCode   = olaCode((string)file_get_contents($DOCROOT . '/api.php'));
$orgCode   = olaCode((string)file_get_contents($DOCROOT . '/manage/organisations.php'));
$myOrgCode = olaCode((string)file_get_contents($DOCROOT . '/manage/my-organisations.php'));
$ccliCode  = olaCode((string)file_get_contents($DOCROOT . '/includes/ccli_validator.php'));

/* ========================================================================
 * (A) the core defines every shared function
 * ===================================================================== */
echo "\n(A) shared core surface:\n";
foreach ([
    'orgLicenceTableExists', 'orgLicenceValidateType', 'orgLicenceNormaliseFields',
    'orgLicenceList', 'orgLicenceUpsert', 'orgLicenceUpdateById', 'orgLicenceDeleteById',
    'orgLicenceSyncSet',
] as $fn) {
    ok("defines {$fn}()", function_exists($fn));
}

/* ========================================================================
 * (B) orgLicenceNormaliseFields — pure truth table
 * ===================================================================== */
echo "\n(B) orgLicenceNormaliseFields():\n";
$n1 = orgLicenceNormaliseFields(['licence_number' => '  CCLI 123  ', 'is_active' => '1', 'expires_at' => '2027-01-01', 'notes' => '  hi ']);
ok('trims number + notes, keeps active/expiry',
    $n1['licenceNumber'] === 'CCLI 123' && $n1['isActive'] === 1 && $n1['expiresAt'] === '2027-01-01' && $n1['notes'] === 'hi');
$n2 = orgLicenceNormaliseFields(['expires_at' => '', 'notes' => '']);
ok('blank expiry + notes → null', $n2['expiresAt'] === null && $n2['notes'] === null);
ok('active defaults to 1 when absent', orgLicenceNormaliseFields([])['isActive'] === 1);
ok('active "0" string → 0', orgLicenceNormaliseFields(['is_active' => '0'])['isActive'] === 0);
ok('active JSON false → 0', orgLicenceNormaliseFields(['isActive' => false])['isActive'] === 0);
ok('active absent+empty string → 0', orgLicenceNormaliseFields(['is_active' => ''])['isActive'] === 0);
ok('number capped at 100', mb_strlen(orgLicenceNormaliseFields(['licence_number' => str_repeat('x', 200)])['licenceNumber']) === 100);
/* camelCase (JSON body) keys work too */
ok('accepts camelCase licenceNumber/expiresAt',
    orgLicenceNormaliseFields(['licenceNumber' => 'X', 'expiresAt' => '2030-01-01'])['expiresAt'] === '2030-01-01');

/* ========================================================================
 * (C) no hard-coded licence-key list in the API write sites (rule #9)
 * ===================================================================== */
echo "\n(C) no hard-coded licence-key list:\n";
/** Detects a licence-key ARRAY LITERAL: an array containing at least two of the
 *  known licence keys as string literals close together. */
function hasLicenceKeyListLiteral(string $code): bool
{
    return (bool)preg_match(
        "/\\[[^\\]]*'(?:none|ccli|mrl|ihymns_basic|ihymns_pro|custom)'[^\\]]*,[^\\]]*'(?:none|ccli|mrl|ihymns_basic|ihymns_pro|custom)'[^\\]]*\\]/s",
        $code
    );
}
ok('scan self-test: a key-list literal IS detected',
    hasLicenceKeyListLiteral("\$k = ['none', 'ihymns_basic', 'ihymns_pro', 'ccli'];") === true);
ok('scan self-test: an unrelated array is NOT detected',
    hasLicenceKeyListLiteral("\$k = ['id', 'name', 'slug'];") === false);
ok('api.php admin_organisation_update / org_admin_licence_* carry NO licence-key list literal',
    hasLicenceKeyListLiteral($apiCode) === false);

/* ========================================================================
 * (D) no destructive whole-org wipe
 * ===================================================================== */
echo "\n(D) no destructive whole-org DELETE:\n";
/** The data-loss pattern: DELETE FROM tblOrganisationLicences scoped ONLY by
 *  OrganisationId (no LicenceType) — wipes every row. The core's per-type
 *  DELETE (…AND LicenceType = ?) is fine and must NOT trip this. */
function hasWholeOrgWipe(string $code): bool
{
    return (bool)preg_match(
        '/DELETE\s+FROM\s+tblOrganisationLicences\s+WHERE\s+OrganisationId\s*=\s*\?\s*(?![^\'"]*LicenceType)/is',
        $code
    );
}
ok('scan self-test: a whole-org wipe IS detected',
    hasWholeOrgWipe('DELETE FROM tblOrganisationLicences WHERE OrganisationId = ?') === true);
ok('scan self-test: a per-type delete is NOT flagged',
    hasWholeOrgWipe('DELETE FROM tblOrganisationLicences WHERE OrganisationId = ? AND LicenceType = ?') === false);
ok('api.php has no whole-org licence wipe',            hasWholeOrgWipe($apiCode) === false);
ok('organisations.php has no whole-org licence wipe',  hasWholeOrgWipe($orgCode) === false);

/* ========================================================================
 * (E) every write surface routes through the core
 * ===================================================================== */
echo "\n(E) write surfaces route through the core:\n";
ok('api.php admin_organisation_update uses orgLicenceSyncSet()',
    strpos($apiCode, 'orgLicenceSyncSet(') !== false);
ok('api.php org_admin_licence_* use the core upsert/update/delete',
    strpos($apiCode, 'orgLicenceUpsert(') !== false
    && strpos($apiCode, 'orgLicenceUpdateById(') !== false
    && strpos($apiCode, 'orgLicenceDeleteById(') !== false);
ok('organisations.php uses orgLicenceSyncSet() + orgLicenceUpdateById()',
    strpos($orgCode, 'orgLicenceSyncSet(') !== false && strpos($orgCode, 'orgLicenceUpdateById(') !== false);
ok('my-organisations.php uses the core upsert/update/delete',
    strpos($myOrgCode, 'orgLicenceUpsert(') !== false
    && strpos($myOrgCode, 'orgLicenceUpdateById(') !== false
    && strpos($myOrgCode, 'orgLicenceDeleteById(') !== false);
ok('the core validates types via the registry (licenceTypeKeys), not a literal (rule #9)',
    strpos(olaCode((string)file_get_contents($DOCROOT . '/includes/org_licence_admin.php')), 'licenceTypeKeys(') !== false);

/* ========================================================================
 * (F) the tier resolver honours ExpiresAt on the join-table arm
 * ===================================================================== */
echo "\n(F) resolver honours licence expiry:\n";
/** The join arm must filter ExpiresAt. Look for the tblOrganisationLicences
 *  arm carrying an ExpiresAt predicate. */
function joinArmHonoursExpiry(string $code): bool
{
    /* find the join-table SELECT arm, then require an ExpiresAt predicate
       within a reasonable window after it. */
    if (!preg_match('/JOIN\s+tblOrganisationLicences\s+ol\b/i', $code, $m, PREG_OFFSET_CAPTURE)) {
        return false;
    }
    $window = substr($code, $m[0][1], 400);
    return (bool)preg_match('/ol\.ExpiresAt\s+IS\s+NULL\s+OR\s+ol\.ExpiresAt\s*>\s*NOW\(\)/i', $window);
}
ok('scan self-test: an arm WITHOUT an expiry predicate is flagged',
    joinArmHonoursExpiry('JOIN tblOrganisationLicences ol ON x WHERE ol.IsActive = 1') === false);
ok('scan self-test: an arm WITH the predicate passes',
    joinArmHonoursExpiry('JOIN tblOrganisationLicences ol ON x WHERE ol.IsActive = 1 AND (ol.ExpiresAt IS NULL OR ol.ExpiresAt > NOW())') === true);
ok('ccli_validator.php resolveEffectiveTier filters ExpiresAt on the join arm',
    joinArmHonoursExpiry($ccliCode) === true);

/* ===================================================================== */
echo "\n" . ($failed === 0
    ? "PASS: {$passed} assertion(s) — org-licence CRUD is consolidated, non-destructive, registry-driven, expiry-honouring.\n"
    : "FAIL: {$failed} of " . ($passed + $failed) . " assertion(s) failed:\n  - " . implode("\n  - ", $failures) . "\n");
exit($failed === 0 ? 0 : 1);
