<?php

declare(strict_types=1);

/**
 * iHymns — gating admin surfaces MUST audit-log their writes (#1769 P4 Commit F)
 * =============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The owner asked that every gating action be recorded in the activity log. This
 * guard makes that a MECHANISM, not a promise: it derives — from the tree — every
 * admin ENTRY POINT (a /manage page or api.php) that performs a gating write, and
 * fails the build if one of them never calls logActivity(). It is exactly what
 * would have caught the restrictions.php create/delete that shipped without an
 * audit call until #1769 P4 Commit E.
 *
 * HOW A SURFACE IS DETECTED (tree-derived, two signals — rule #34)
 * ----------------------------------------------------------------------------
 * A file is a gating-write entry point when its CODE (comments stripped) either:
 *   (1) issues a write verb (INSERT INTO / UPDATE / DELETE FROM) against a
 *       gating registry table (tblContentRestrictions / tblAccessTiers /
 *       tblLicenceTypes / tblGatingCapabilities), OR
 *   (2) calls a gating-write CORE function (licenceTypeAdmin{Create,Update,
 *       Toggle,Delete}) — the signal for a page whose SQL lives in a shared core
 *       (licence-types.php delegates to includes/licence_type_admin.php, so it
 *       has no write verb of its own but is still the audited entry point).
 * Only /manage/*.php + api.php are scanned — shared cores under includes/ are
 * deliberately NOT entry points (their callers do the logging), so a core that
 * correctly does not log is never wrongly flagged (rule #34: a guard must not
 * fail on correct code).
 *
 * A file may opt OUT with a `@no-activity-log: <reason>` doctag (none do today).
 *
 * MUTATION-PROVEN both ways: the detector is exercised on in-memory fixtures, and
 * the real check is proven to go red when a known surface's logActivity is removed
 * (see the self-tests). Vacuity-gated: the derived set must include the known
 * gating surfaces, so a broken scanner that finds nothing cannot pass.
 *
 * DB-free source scan.  php tests/php/test-gating-admin-activity-log.php
 *
 * @see appWeb/public_html/manage/restrictions.php  admin.restrictions.*
 * @see appWeb/public_html/manage/tiers.php          admin.tiers.*
 * @see appWeb/public_html/manage/licence-types.php  admin.licence_types.* (via the core)
 * @see appWeb/public_html/manage/feature-gating.php
 * @see appWeb/public_html/api.php                   admin_tier_* / admin_restrictions / admin_licence_type_*
 * @see .claude/gating-p4-design.md §"Commit F"
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

/** Strip PHP comments/doc-blocks (keep inline HTML) so neither the write-verb
 *  detection nor the logActivity check can be satisfied/tripped by a comment. */
function galStrip(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
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

/** Does this (comment-stripped) code make a gating write? */
function galIsGatingWrite(string $code): bool
{
    if (preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(tblContentRestrictions|tblAccessTiers|tblLicenceTypes|tblGatingCapabilities)\b/i', $code)) {
        return true;
    }
    if (preg_match('/\blicenceTypeAdmin(?:Create|Update|Toggle|Delete)\s*\(/', $code)) {
        return true;
    }
    return false;
}

$failures = [];
$mut = [];

/* ---- mutation self-tests for the detector ---- */
if (!galIsGatingWrite("\$db->query(\"INSERT INTO tblAccessTiers (Name) VALUES ('x')\");")) {
    $mut[] = 'galIsGatingWrite FAILS-HIGH: missed an INSERT INTO tblAccessTiers';
}
if (!galIsGatingWrite('licenceTypeAdminCreate($db, $norm, $uid);')) {
    $mut[] = 'galIsGatingWrite FAILS-HIGH: missed a licenceTypeAdminCreate() call';
}
if (galIsGatingWrite("\$db->query('SELECT * FROM tblSongs');")) {
    $mut[] = 'galIsGatingWrite FAILS-LOW: flagged a non-gating read';
}
/* the strip must blank a comment so a commented write verb does not count */
if (galIsGatingWrite(galStrip("<?php\n// INSERT INTO tblAccessTiers is mentioned here\n\$x=1;\n"))) {
    $mut[] = 'galStrip/detector FAILS-LOW: a write verb inside a comment was counted';
}

/* ---- derive the surface set from the tree ---- */
$scan = glob($pub . '/manage/*.php') ?: [];
$scan[] = $pub . '/api.php';

$surfaces = [];   // relPath => code
foreach ($scan as $path) {
    if (!is_file($path)) { continue; }
    $code = galStrip((string)file_get_contents($path));
    if (galIsGatingWrite($code)) {
        $surfaces[substr($path, strlen($pub) + 1)] = $code;
    }
}

/* ---- vacuity: the known gating surfaces must be in the derived set ---- */
$expected = ['manage/restrictions.php', 'manage/tiers.php', 'manage/licence-types.php', 'manage/feature-gating.php', 'api.php'];
foreach ($expected as $e) {
    if (!array_key_exists($e, $surfaces)) {
        $failures[] = "the detector did NOT flag {$e} as a gating-write surface — the scan is under-reporting (rule #34), fix it before trusting a green";
    }
}

/* ---- the real check: every gating-write surface calls logActivity ---- */
foreach ($surfaces as $rel => $code) {
    if (strpos($code, '@no-activity-log') !== false) { continue; }   // explicit opt-out (none today)
    if (strpos($code, 'logActivity(') === false) {
        $failures[] = "{$rel} performs a gating write but never calls logActivity() — every gating action must be audited (owner directive; #1769 P4). Add a logActivity(...) at the write, or a `@no-activity-log: <reason>` doctag if it is genuinely exempt.";
    }
}

/* ---- prove the check CAN fail: strip logActivity from a known surface's code
   in memory and confirm the same predicate would flag it. ---- */
$probe = $surfaces['manage/restrictions.php'] ?? '';
if ($probe !== '') {
    $broken = str_replace('logActivity(', 'noOp_(', $probe);
    if (strpos($broken, 'logActivity(') !== false || strpos($broken, 'noOp_(') === false) {
        $mut[] = 'the in-memory mutation of restrictions.php did not remove logActivity( as expected';
    } elseif (galIsGatingWrite($broken) && strpos($broken, 'logActivity(') === false) {
        /* good — a logActivity-less gating-write surface is detectable */
    } else {
        $mut[] = 'the real-check predicate would NOT flag a logActivity-stripped restrictions.php (the guard cannot fail)';
    }
}

if ($failures || $mut) {
    if ($mut) { fwrite(STDERR, "FAIL: mutation self-test(s):\n"); foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); } fwrite(STDERR, "\n"); }
    if ($failures) {
        fwrite(STDERR, "FAIL: gating admin activity-log guard (#1769 P4 Commit F):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}
echo "PASS: all " . count($surfaces) . " gating-write admin surface(s) call logActivity() — "
   . implode(', ', array_keys($surfaces)) . ". Mutation self-tests behaved as expected.\n";
exit(0);
