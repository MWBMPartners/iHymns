<?php

declare(strict_types=1);

/**
 * iHymns — admin gate / nav-entitlement parity guard (#1648 item 1, rule #1587)
 *
 * ELI5: if the menu says a page needs a particular permission, the page itself
 * has to check that same permission. This makes sure the two never drift apart.
 *
 * Detail: `manage/includes/admin-links.php` is the ONE registry of admin nav
 * entries, and each row carries the entitlement that entry requires. Those
 * entitlements are admin-editable at runtime via `/manage/entitlements`
 * (`entitlements_overrides` in `tblAppSettings`). But several pages gated
 * themselves on a hardcoded ROLE instead, and a role is not editable.
 *
 * Both drift directions are bugs, and they are not symmetrical:
 *
 *   WIDENING an entitlement shows a nav link that then 403s. Confusing;
 *   harmless.
 *
 *   NARROWING one is the dangerous direction, because it fails SILENTLY and in
 *   the unsafe direction. Revoke `admin` from `view_activity_log` — a page that
 *   exposes usernames, emails and IP addresses — and the link disappears from
 *   the menu while the page still admits every admin-role user who types the
 *   URL. The override UI presents a revocation that does not happen. An
 *   administrator would reasonably believe they had removed access.
 *
 * This is the literal red flag named in rule #1587: "an admin page whose own
 * gate differs from the entitlement its nav entry advertises".
 *
 * WHY IT IS DERIVED, NOT A HARDCODED LIST
 * ---------------------------------------
 * The pairing is read out of `admin-links.php` at run time. A hardcoded list
 * here would be a THIRD copy of the same mapping, and would have exactly the
 * drift problem this guard exists to prevent — the failure mode this codebase
 * keeps rediscovering ("two lists that must agree with nothing enforcing it":
 * event names, rate-limit pairs, PHP/JS entitlement maps, npm-vs-CI test
 * lists). Deriving it means a NEW admin page is covered the day it is added,
 * with no one having to remember this file exists.
 *
 * SCOPE / LIMITS (stated honestly)
 *   - Static analysis. It proves the page NAMES the right entitlement, not
 *     that the check is reachable or correctly ordered. A page that calls
 *     userHasEntitlement() after already emitting output would still pass.
 *   - Pages whose nav entry advertises no entitlement are skipped — nothing to
 *     compare against.
 *   - `setup-database.php` legitimately bypasses its gate on a virgin install
 *     ($isInitialSetup): there is no user table to hold a role yet. The guard
 *     only asserts the entitlement is named, so that branch is unaffected.
 *
 * Usage: php tests/php/test-admin-gate-parity.php
 * Exit 0 = pass, 1 = fail.
 */

$root   = dirname(__DIR__, 2);
$manage = $root . '/appWeb/public_html/manage';
$links  = $manage . '/includes/admin-links.php';

if (!is_file($links)) {
    fwrite(STDERR, "FAIL: admin-links.php not found — cannot derive the nav↔gate pairing.\n");
    exit(1);
}

$src = (string) file_get_contents($links);

/* Strip PHP block comments first: the file documents the row shape in prose
   that contains example entitlement names, and matching those would invent
   pages that do not exist. */
$src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;

/* Each row is:
     ['slug', '/manage/route', 'bi-icon', 'Label', 'entitlement_key', 'Group']
   Capture slug (1st) and entitlement (5th). A row may legitimately carry null
   or '' for the entitlement — those are skipped as "no requirement declared". */
$rowRe = "~\[\s*'([a-z0-9\-]+)'\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'([a-z0-9_]+)'\s*,~i";

if (preg_match_all($rowRe, $src, $m, PREG_SET_ORDER) === 0) {
    fwrite(STDERR, "FAIL: parsed 0 nav rows from admin-links.php — the row shape has changed and\n");
    fwrite(STDERR, "      this guard has silently stopped checking anything. Fix the regex.\n");
    exit(1);
}

$failures = [];
$checked  = 0;
$skipped  = [];

foreach ($m as $row) {
    [$all, $slug, $entitlement] = $row;

    $page = $manage . '/' . $slug . '.php';
    if (!is_file($page)) {
        /* Route may be served by a directory index or a rewrite — not a
           failure, but record it so a silently-vanished page is visible. */
        $skipped[] = "{$slug} (no {$slug}.php on disk)";
        continue;
    }

    $pageSrc = (string) file_get_contents($page);
    $pageSrc = preg_replace('~/\*.*?\*/~s', '', $pageSrc) ?? $pageSrc;

    $checked++;

    /* The page must NAME the entitlement its nav row advertises. Quote style is
       not constrained — only that the key appears inside an entitlement check. */
    $needle = "userHasEntitlement('" . $entitlement . "'";
    $needleAlt = 'userHasEntitlement("' . $entitlement . '"';

    if (!str_contains($pageSrc, $needle) && !str_contains($pageSrc, $needleAlt)) {
        $failures[] = sprintf(
            "%s.php — nav advertises '%s' but the page never checks it. "
            . "A narrowed override would hide the link while the page still admits the old role.",
            $slug,
            $entitlement
        );
    }
}

if ($checked === 0) {
    fwrite(STDERR, "FAIL: 0 pages checked — every nav row resolved to a missing file, so this\n");
    fwrite(STDERR, "      guard is vacuous. That is itself the finding.\n");
    exit(1);
}

if ($failures) {
    fwrite(STDERR, "FAIL: admin gate / nav-entitlement parity (#1648 item 1, rule #1587):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\nEach admin page must gate on the SAME entitlement its admin-links.php row\n");
    fwrite(STDERR, "advertises, via userHasEntitlement(). Gating on a hardcoded role instead means\n");
    fwrite(STDERR, "the /manage/entitlements override UI silently fails to revoke access.\n");
    exit(1);
}

printf(
    "PASS: %d admin page(s) gate on the entitlement their nav entry advertises.%s\n",
    $checked,
    $skipped ? ' Skipped: ' . implode(', ', $skipped) . '.' : ''
);
exit(0);
