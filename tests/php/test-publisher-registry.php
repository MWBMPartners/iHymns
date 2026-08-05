<?php

declare(strict_types=1);

/**
 * iHymns — Publisher registry surface + cross-file agreement guard (#93)
 * =============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The publisher feature spans six files that must agree with each other with
 * nothing at runtime forcing them to. This guard locks those agreements so a
 * future edit to ONE of them can't silently half-break the feature (rule #35 —
 * a mechanism, not a "keep these in sync" comment):
 *   - the shared cores (publisher_helpers.php / publisher_admin.php) define the
 *     functions + vocab the callers depend on;
 *   - /manage/publishers delegates to the shared core (rule #22) — it does not
 *     re-implement create/merge/delete;
 *   - the songbook editor's multi-publisher write handler reads the SAME POST
 *     keys the modal emits, and validates the Role against the SHARED vocab
 *     (IHYMNS_PUBLISHER_ROLES), not a hand-typed list;
 *   - the public /publisher/<slug> route is wired in router.js AND api.php AND
 *     the page file exists (rule #33 — the admin list + editor emit the link).
 *
 * DB-free, comment-stripped source scan. Mutation-proven both directions:
 *   php tests/php/test-publisher-registry.php
 *
 * @see #93
 */

$repoRoot = dirname(__DIR__, 2);
$web      = $repoRoot . '/appWeb/public_html';
$sql      = $repoRoot . '/appWeb/.sql';

/* --- comment-stripper: drop T_COMMENT/T_DOC_COMMENT/T_INLINE_HTML so a token
   named only in a comment (or in HTML prose) can't false-satisfy a check. --- */
function pubStrip(string $src): string
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
/* For the JS/HTML-heavy songbooks.php modal + router.js we DON'T tokenize
   (they're not pure PHP token streams we care about); a raw read is fine for
   the literal-presence assertions there. */
function pubRead(string $f): string
{
    return is_readable($f) ? (string)file_get_contents($f) : '';
}
/* Word-boundary identifier presence. A bare strpos() is the rule #34 trap:
   strpos('publisherAdminMerge') still matches a renamed 'publisherAdminMergeX'
   and strpos('tblPublisherAliases') still matches 'tblPublisherAliasesXX', so a
   rename slips through green. \b at both ends means the identifier must stand
   alone (bounded by a non-word char / start / end). */
function pubHas(string $haystack, string $ident): bool
{
    return (bool)preg_match('/\b' . preg_quote($ident, '/') . '\b/', $haystack);
}

$failures = [];
$mut      = [];

/* ---- mutation self-test of the stripper ---- */
$probe = pubStrip("<?php\n// IHYMNS_PUBLISHER_ROLES NeedleComment\n\$x='NeedleCode';\n");
if (strpos($probe, 'NeedleCode') === false)  { $mut[] = 'pubStrip FAILS-HIGH (dropped real code)'; }
if (strpos($probe, 'NeedleComment') !== false) { $mut[] = 'pubStrip FAILS-LOW (kept a comment)'; }

/* ---- 1. shared cores exist + define what callers depend on ---- */
$helpers = pubStrip(pubRead("$web/includes/publisher_helpers.php"));
$admin   = pubStrip(pubRead("$web/includes/publisher_admin.php"));

foreach (['IHYMNS_PUBLISHER_KINDS', 'IHYMNS_PUBLISHER_ROLES', 'ihymns_publisher_slugify', 'publisherSlugEnsureUnique', 'publisherFindOrCreateByName'] as $sym) {
    if (!pubHas($helpers, $sym)) {
        $failures[] = "publisher_helpers.php no longer defines/uses '{$sym}' — the shared vocab/slug/funnel every caller depends on";
    }
}
foreach (['publisherAdminValidateFields', 'publisherAdminCreate', 'publisherAdminPersistFields', 'publisherAdminReplaceAliases', 'publisherAdminUsageCounts', 'publisherAdminDelete', 'publisherAdminMerge', 'publisherAdminWouldCycle'] as $fn) {
    if (!preg_match('/function\s+' . preg_quote($fn, '/') . '\b/', $admin)) {
        $failures[] = "publisher_admin.php no longer defines {$fn}() — a shared core the page + future API both call (rule #22/#35)";
    }
}

/* ---- 2. /manage/publishers delegates to the shared core (rule #22) ---- */
$page = pubStrip(pubRead("$web/manage/publishers.php"));
foreach (['publisherAdminCreate', 'publisherAdminPersistFields', 'publisherAdminMerge', 'publisherAdminDelete', 'publisherAdminProbeGates'] as $fn) {
    if (!pubHas($page, $fn)) {
        $failures[] = "manage/publishers.php no longer calls {$fn}() — it must delegate to the shared core, not re-implement it (rule #22)";
    }
}
/* the page must gate on the SAME entitlement admin-links advertises (rule #1587) */
if (strpos($page, "userHasEntitlement('manage_publishers'") === false) {
    $failures[] = "manage/publishers.php no longer gates on manage_publishers — its gate must equal its nav entry's entitlement (rule #1587)";
}

/* ---- 3. songbook editor write handler: SAME POST keys the modal emits, and
        the Role validated against the SHARED vocab, not a hand-typed list ---- */
$songbooks   = pubStrip(pubRead("$web/manage/songbooks.php"));
$songbooksRaw = pubRead("$web/manage/songbooks.php");
foreach (["\$_POST['publisher_ids']", "\$_POST['publisher_roles']", "\$_POST['publisher_notes']"] as $key) {
    if (strpos($songbooks, $key) === false) {
        $failures[] = "songbooks.php write handler no longer reads {$key} — the POST keys it reads must match those the modal JS emits (rule #35)";
    }
}
/* the modal JS (raw, HTML/JS region) must EMIT those same three keys */
foreach (['name="publisher_ids[]"', 'name="publisher_roles[]"', 'name="publisher_notes[]"'] as $emit) {
    if (strpos($songbooksRaw, $emit) === false) {
        $failures[] = "songbooks.php modal no longer emits {$emit} — the picker inputs must match the write handler's POST keys (rule #35)";
    }
}
if (strpos($songbooks, 'IHYMNS_PUBLISHER_ROLES') === false) {
    $failures[] = "songbooks.php no longer validates the publisher Role against IHYMNS_PUBLISHER_ROLES — it must use the shared vocab, never a hardcoded role list (rule #20/#35)";
}
if (strpos($songbooks, 'tblSongbookPublishers') === false) {
    $failures[] = "songbooks.php no longer writes tblSongbookPublishers — the multi-publisher M:N reconcile is gone";
}

/* ---- 4. the public /publisher route is wired end-to-end (rule #33) ---- */
$router = pubRead("$web/js/modules/router.js");
$api    = pubStrip(pubRead("$web/api.php"));
if (strpos($router, "case 'publisher'") === false || strpos($router, "page: 'publisher'") === false) {
    $failures[] = "router.js no longer routes /publisher/<slug> — the admin list + editor picker emit that link (rule #33)";
}
if (strpos($api, "case 'publisher'") === false || strpos($api, 'publisher.php') === false) {
    $failures[] = "api.php no longer serves page=publisher — the /publisher/ route would 404 (rule #33)";
}
if (!is_readable("$web/includes/pages/publisher.php")) {
    $failures[] = "includes/pages/publisher.php is missing — the /publisher/ route has no page to render (rule #33)";
}

/* ---- 5. the migration + its four tables exist in schema.sql (the byte-identity
        is test-schema-coverage.php's job; here we just assert the family is
        present so a delete is caught by SOMETHING publisher-named) ---- */
$schema = pubRead("$sql/schema.sql");
foreach (['tblPublishers', 'tblSongbookPublishers', 'tblPublisherAliases', 'tblPublisherExternalLinks'] as $tbl) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($tbl, '/') . '\b/', $schema)) {
        $failures[] = "schema.sql no longer declares {$tbl} — the publisher family's fresh-install DDL is gone (rule #19)";
    }
}
if (!is_readable("$sql/migrate-publishers-entity.php")) {
    $failures[] = "migrate-publishers-entity.php is missing — long-running installs can't gain the publisher family";
}

/* ---- report ---- */
if ($failures || $mut) {
    if ($mut) {
        fwrite(STDERR, "FAIL: mutation self-test(s):\n");
        foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($failures) {
        fwrite(STDERR, "FAIL: publisher registry surface guard (#93):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}
echo "PASS: publisher shared cores defined, /manage/publishers delegates to them, "
   . "the songbook write handler reads the modal's POST keys + the shared Role vocab, "
   . "the /publisher/ route is wired end-to-end, and the schema family + migration exist. "
   . "Mutation self-tests behaved as expected.\n";
exit(0);
