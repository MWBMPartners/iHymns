<?php

declare(strict_types=1);

/**
 * iHymns — Organisation shared helpers (#719 PR 2c)
 *
 * Single source of truth for the bits both /manage/organisations.php
 * (system admin) and /manage/my-organisations.php (org admin) share
 * with the new admin_organisation_* / org_admin_* API endpoints in
 * /api.php:
 *
 *   - ORG_MEMBER_ROLES — the allowlist of `tblOrganisationMembers.Role`
 *     values. Both surfaces accept the same three (member / admin /
 *     owner).
 *   - slugifyOrganisationName() — same lowercase + non-alphanum-→hyphen
 *     transform organisations.php has used since #459 / #260, lifted
 *     out so the API auto-slug path matches exactly.
 *   - userCanActOnOrg() — row-level gate from PR #726. Returns true
 *     when the caller is system admin OR holds an admin/owner row
 *     on tblOrganisationMembers for the target org. Used by every
 *     org_admin_* endpoint to refuse cross-org POSTs.
 *
 * The licence-type allowlists are deliberately NOT shared — the two
 * surfaces accept slightly different sets (system admin uses `none`
 * as a "no primary" sentinel; org admin uses individual rows where
 * absence-is-no-licence). Each call site keeps its own const.
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* tblOrganisationMembers.Role allowlist — shared between both surfaces. */
if (!defined('IHYMNS_ORG_MEMBER_ROLES_DEFINED')) {
    define('IHYMNS_ORG_MEMBER_ROLES_DEFINED', true);
    define('ORG_MEMBER_ROLES', ['member', 'admin', 'owner']);
}

/**
 * Lowercase + non-alphanum-→hyphen slug transform. Matches the
 * closure in organisations.php (and the auto-slug path in
 * /api?action=organisation_create) so a curator who types the same
 * name on either surface gets the same slug.
 *
 * @param string $s Raw text — typically the org name or a curator-
 *                  provided slug override.
 * @return string A trimmed, hyphen-joined, lowercase slug. May be
 *                empty if the input had no [a-z0-9] characters at all
 *                (caller decides whether to refuse or fall back).
 */
function slugifyOrganisationName(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/**
 * Row-level org-admin gate (PR #726 / #707). Returns true when
 * the caller is system admin OR holds an admin/owner row on
 * tblOrganisationMembers for the target org.
 *
 * Caller is the bearer-token user (from getAuthenticatedUser()).
 * The role lookup is keyed by the PascalCase 'Id' / 'Role' shape
 * that endpoint produces.
 *
 * @param array $authUser Authenticated user array with 'Id' + 'Role'.
 * @param int   $orgId    Target organisation id (must be > 0).
 * @return bool True if allowed; false otherwise.
 */
function userCanActOnOrg(array $authUser, int $orgId): bool
{
    if ($orgId <= 0) return false;
    $role = (string)($authUser['Role'] ?? '');
    if (in_array($role, ['admin', 'global_admin'], true)) return true;

    $userId = (int)($authUser['Id'] ?? 0);
    if ($userId <= 0) return false;
    /* userIsOrgAdminOf is best-effort against schema drift —
       returns [] on a pre-migration deployment, which means the
       gate refuses (correct fail-closed behaviour). */
    if (!function_exists('userIsOrgAdminOf')) {
        return false;
    }
    return in_array($orgId, userIsOrgAdminOf($userId), true);
}

/* =============================================================================
 * #1840 — ORG BRAND COLOUR: the ONE normalise + parse + persist core
 * =============================================================================
 *
 * ELI5
 * ----
 * A church can optionally pick ONE colour for its brand — the colour band
 * behind its logo on a shared set-list preview card. This is the ONE place
 * that checks a typed colour actually IS a colour (rejecting anything else
 * outright, never guessing or repairing it), turns a checked colour into
 * the three numbers a picture-drawing library wants, and saves it.
 *
 * DETAILED
 * --------
 * `ihymnsOrgBrandColourNormalise()` is a STRICT allowlist at the door: every
 * write site (the admin `brand_save` handlers, commit 5) calls this BEFORE
 * touching the database, and its return value is the ONLY thing that may
 * ever reach `tblOrganisations.BrandColor` — a malformed value is rejected,
 * never stored, never echoed back unescaped. The stored canonical form is
 * always `#rrggbb` or `#rrggbbaa`, lowercase; a 3-digit shorthand (`#rgb`)
 * is WIDENED to 6 digits so every reader only ever has to handle one shape.
 *
 * `ihymnsOrgBrandColourRgb()` is the ONE hex-to-GD-ints parser — og-image.php
 * (a later commit) uses this exclusively rather than inlining its own
 * `sscanf`/substr fork; alpha (the optional 7th/8th hex digit pair) is
 * IGNORED — a share-card band must be solid for legibility, and the app
 * never stores a use case that needs it read back.
 *
 * `orgSetBrandColour()` is the ONE write path — column-existence-gated
 * (`orgBrandColumnsExist()`) so it degrades to a clean `false` on an
 * un-migrated install rather than throwing under mysqli STRICT (rule #9).
 *
 * The colour value is NEVER interpolated into CSS or HTML anywhere in this
 * feature: og-image.php consumes it only as three GD integers via
 * `ihymnsOrgBrandColourRgb()`; the admin form echoes the stored value
 * `htmlspecialchars()`'d into a `value=` attribute (belt-and-braces over the
 * allowlist that already ran at write time).
 *
 * @link appWeb/public_html/og-image.php  the ONE consumer of ihymnsOrgBrandColourRgb()
 * @see .claude/org-logo-surfaces-1840-plan.md §4.2
 * @see #1840
 */

/**
 * Strict hex-colour allowlist normaliser — the ONE gate every BrandColor
 * write goes through.
 *
 *   - `null` or an empty/whitespace-only string -> `null` (means "clear the
 *     org's brand colour" — a legitimate, common write, not a rejection).
 *   - `#rgb` (3 hex digits) -> widened to lowercase `#rrggbb`.
 *   - `#rrggbb` / `#rrggbbaa` (6 or 8 hex digits) -> lowercased as-is.
 *   - anything else (missing `#`, wrong digit count, non-hex characters, a
 *     CSS colour keyword, an attempted injection payload, …) -> `false`
 *     (REJECT — the caller must refuse the save with a plain-English error,
 *     never store `false`/coerce it to a string).
 *
 * @param  ?string $in  Raw, untrusted input (typically `$_POST['brand_colour']`).
 * @return string|false|null
 */
function ihymnsOrgBrandColourNormalise(?string $in): string|false|null
{
    if ($in === null) {
        return null;
    }
    $trimmed = trim($in);
    if ($trimmed === '') {
        return null;
    }
    if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $trimmed, $m)) {
        return false;
    }
    $hex = strtolower($m[1]);
    if (strlen($hex) === 3) {
        /* '#abc' -> '#aabbcc' — the single-digit-per-channel CSS shorthand,
           widened so every reader only ever handles the 6/8-digit shape. */
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return '#' . $hex;
}

/**
 * Parse an ALREADY-NORMALISED hex colour (see above — `#rrggbb` or
 * `#rrggbbaa`, lowercase) into `[r, g, b]` integers for GD. Alpha (an
 * optional 7th/8th hex digit pair) is deliberately IGNORED — a share-card
 * background band must be a SOLID fill for legibility.
 *
 * Defensively re-validates its input rather than trusting the caller has
 * already run it through `ihymnsOrgBrandColourNormalise()` — a malformed or
 * unexpected `$hex` (e.g. a stale row from before a stricter allowlist)
 * degrades to black rather than emitting a PHP warning or a wrong colour
 * silently drawn from garbage bytes.
 *
 * @param  string $hex  `#rrggbb` or `#rrggbbaa` (case-insensitive; a bare
 *                        `#rgb` shorthand is also accepted defensively).
 * @return array{0:int,1:int,2:int}  [r, g, b], each 0-255.
 */
function ihymnsOrgBrandColourRgb(string $hex): array
{
    $h = ltrim(trim($hex), '#');
    if (strlen($h) === 3 && preg_match('/^[0-9a-fA-F]{3}$/', $h)) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    $h = substr($h, 0, 6); // RGB only — any alpha byte beyond this is ignored
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) {
        return [0, 0, 0]; // malformed input — never a wrong colour from garbage bytes
    }
    return [
        (int)hexdec(substr($h, 0, 2)),
        (int)hexdec(substr($h, 2, 2)),
        (int)hexdec(substr($h, 4, 2)),
    ];
}

/**
 * #1840 §7.3 — YIQ perceived-brightness check for a band colour, so text
 * drawn ON that band stays readable regardless of how pale or dark the
 * org's chosen brand colour is. `(299R+587G+114B)/1000` is the standard
 * YIQ luma weighting (the same formula behind the long-standing
 * "should this background get light or dark foreground text" heuristic).
 * One small pure helper beside `ihymnsOrgBrandColourRgb()` — its ONE
 * consumer is og-image.php's branded share-card band.
 *
 * @param  array{0:int,1:int,2:int} $rgb  [r, g, b], each 0-255 — typically
 *                                          `ihymnsOrgBrandColourRgb()`'s output.
 * @return bool  true when the colour reads as LIGHT (YIQ >= 150) — the
 *               caller should use a near-black foreground; false (a dark
 *               band) means use white.
 */
function ihymnsOrgBrandColourIsLight(array $rgb): bool
{
    $r = (int)($rgb[0] ?? 0);
    $g = (int)($rgb[1] ?? 0);
    $b = (int)($rgb[2] ?? 0);
    $yiq = ((299 * $r) + (587 * $g) + (114 * $b)) / 1000;
    return $yiq >= 150;
}

/**
 * True when `tblOrganisations.BrandColor` + `BrandJson` both exist on this
 * install — the dormancy gate the admin `brand_save` handlers AND
 * `orgSetBrandColour()` itself check first, mirroring
 * `serviceMode_orgIdleColumnsExist()` / `setlistOrgAudienceColumnsExist()`'s
 * established shape (rule #19 — a pre-migration deploy degrades silently,
 * mysqli STRICT means a raw write would otherwise throw). Memoised per
 * request.
 */
function orgBrandColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblOrganisations'
                AND COLUMN_NAME IN ('BrandColor', 'BrandJson')"
        );
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        $cached = ($count === 2);
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * The ONE write path for `tblOrganisations.BrandColor` — column-existence-
 * gated (dormant-safe: returns `false` on an un-migrated install rather
 * than throwing under mysqli STRICT). `$normalised` MUST already be the
 * output of `ihymnsOrgBrandColourNormalise()` (a canonical `#rrggbb`(`aa`)
 * string, or `null` to clear) — this function does not re-validate the
 * hex shape itself, only the column-existence dormancy gate.
 *
 * @return bool  `true` on a successful write (including "no value actually
 *               changed"); `false` only when the columns don't exist yet or
 *               `$orgId` is invalid.
 */
function orgSetBrandColour(\mysqli $db, int $orgId, ?string $normalised): bool
{
    if ($orgId <= 0 || !orgBrandColumnsExist($db)) {
        return false;
    }
    $stmt = $db->prepare('UPDATE tblOrganisations SET BrandColor = ? WHERE Id = ?');
    $stmt->bind_param('si', $normalised, $orgId);
    $stmt->execute();
    $stmt->close();
    return true;
}
