<?php

declare(strict_types=1);

/**
 * iHymns — Organisation logo shared helpers: kind registry + reads (#1830)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A church's logo comes in several SHAPES — a main version, a wide one for
 * headers, a symbol on its own, a single-colour print version, and so on.
 * This file holds the ONE master list of those shapes (`IHYMNS_ORG_LOGO_
 * KINDS`), plus the small set of read-only helpers ("does an org have a
 * logo of this kind?", "what does this org have altogether?") that every
 * other piece of the feature — the admin upload page, the serving
 * endpoint, the print block — reads from, so none of them can drift onto
 * their own private copy of the list (rule #22 / #35, mirrors the
 * `includes/publisher_helpers.php` split).
 *
 * DETAILED / THE ONE KIND REGISTRY (owner requirement 2026-08-12)
 * ----------------------------------------------------------------------------
 * `IHYMNS_ORG_LOGO_KINDS`' key order IS the `'auto'` fallback ladder (§4.1/
 * §6.3 of the plan) — there is deliberately no SECOND ordered list anywhere
 * that could drift from this one: `primary -> full -> horizontal -> stacked
 * -> emblem -> logotype -> secondary -> monochrome -> reversed -> favicon`
 * means an `'auto'` print block always prefers the most complete asset an
 * org has actually uploaded. `Kind` is `VARCHAR(20)` app-validated against
 * this map, never an ENUM (rule #20) — a new kind is ONE line here plus a
 * migration is never needed.
 *
 * `monochrome`/`reversed` are modelled as KINDS (distinct brand ASSETS, the
 * way real brand guides publish them), not values of the dormant `Variant`
 * column — `Variant` stays reserved for a true theme-paired rendition of
 * the SAME kind (e.g. a light and a dark `primary` a future surface would
 * auto-switch between). The two axes can coexist without conflict; see the
 * plan's §12(e) for the (deliberately reversible) default this locks in.
 *
 * @link appWeb/public_html/includes/org_logo_admin.php  the write core (validate/stage/upsert/delete)
 * @link appWeb/public_html/includes/svg_sanitizer.php    the SVG hardening this file's SVG branch relies on
 * @link appWeb/public_html/includes/publisher_helpers.php  the split-file precedent this mirrors (rule #37)
 * @see .claude/org-logos-1830-plan.md §4  the full design this file implements
 * @see #1830
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * THE ONE kind registry. Shape: key => [label, one-line description] — both
 * strings are the exact plain-English copy (`.claude/admin-plain-english.md`)
 * the admin card and any future picker render VERBATIM (§4.2/§7.2 of the
 * plan). Key order = ladder order = `'auto'` resolution order = admin-card
 * display order — ONE source of truth, no second ordered list to drift
 * (rule #35). Every key fits the schema's `Kind VARCHAR(20)`.
 */
const IHYMNS_ORG_LOGO_KINDS = [
    'primary' => [
        'Primary logo',
        'Your main logo — used by default wherever one logo is needed.',
    ],
    'full' => [
        'Combined logo',
        'Symbol and name together in your standard arrangement.',
    ],
    'horizontal' => [
        'Wide layout',
        'Symbol beside the name — for wide, short spaces like page headers.',
    ],
    'stacked' => [
        'Stacked layout',
        'Symbol above the name — for square or tall spaces.',
    ],
    'emblem' => [
        'Symbol only',
        'Just the emblem or icon, no words — for tight corners and small spaces.',
    ],
    'logotype' => [
        'Name only',
        "Your organisation's name in its typeface, without the symbol.",
    ],
    'secondary' => [
        'Alternative logo',
        "A different logo for settings where the primary doesn't fit or suit.",
    ],
    'monochrome' => [
        'Single-colour',
        'A one-colour (usually black) version for plain printing.',
    ],
    'reversed' => [
        'Light-on-dark',
        'A white or light version for dark backgrounds.',
    ],
    'favicon' => [
        'App icon',
        'A small square icon for browser tabs and app tiles.',
    ],
];

/**
 * The dormant Variant axis (§2.1/§4.2) — v1's admin upload UI only ever
 * writes `'default'`; `'light'`/`'dark'` are schema-ready vocabulary
 * additions for a later theme-paired-rendition surface (rule #20).
 */
const IHYMNS_ORG_LOGO_VARIANTS = ['default', 'light', 'dark'];

/** Upload caps (§4.1) — enforced by `orgLogoValidateAndStage()` (the
 *  courtesy pre-sniff gate); `includes/svg_sanitizer.php` ALSO enforces its
 *  own SVG byte cap independently (defence in depth — never trust a caller
 *  to have already capped). */
const IHYMNS_ORG_LOGO_MAX_SVG_BYTES = 524288;    // 512 KiB
const IHYMNS_ORG_LOGO_MAX_PNG_BYTES = 2097152;   // 2 MiB
const IHYMNS_ORG_LOGO_MAX_DIMENSION = 4096;      // px, either axis (render-bomb cap on PNG/APNG)

/**
 * @return list<string>  IHYMNS_ORG_LOGO_KINDS's keys, IN LADDER ORDER.
 */
function ihymnsOrgLogoKindKeys(): array
{
    return array_keys(IHYMNS_ORG_LOGO_KINDS);
}

/**
 * Resolve WHICH kind a print block (or any future consumer) should render,
 * given what the org actually has uploaded (§4.1/§6.3):
 *
 *   - `$requested === 'auto'` -> walk `IHYMNS_ORG_LOGO_KINDS`' OWN key
 *     order and return the FIRST kind present in `$availableKinds` — the
 *     "prefer the most complete asset actually uploaded" ladder.
 *   - `$requested` an explicit kind -> that kind if the org has it, else
 *     `null`. NEVER substitutes a different kind — the author asked for
 *     something specific; silently swapping in a different asset behind
 *     their back would be worse than rendering nothing.
 *   - Nothing resolved -> `null`. The caller renders NOTHING — never a
 *     broken-image glyph on a printed handout (§6.3).
 *
 * @param  string        $requested       'auto' or an explicit kind key.
 * @param  array<string> $availableKinds  Kind keys the org has an ACTIVE logo for.
 * @return string|null
 */
function ihymnsOrgLogoResolveKind(string $requested, array $availableKinds): ?string
{
    if ($requested === 'auto') {
        foreach (ihymnsOrgLogoKindKeys() as $kind) {
            if (in_array($kind, $availableKinds, true)) {
                return $kind;
            }
        }
        return null;
    }
    return in_array($requested, $availableKinds, true) ? $requested : null;
}

/**
 * True when `tblOrganisationLogos` exists in the current database —
 * the dormancy gate EVERY consumer (admin card, serving endpoint, print
 * stash, PDF resolver) checks first, so a pre-migration deploy degrades
 * silently everywhere at once (rule #9 — mysqli STRICT + web-run
 * migrations means "it's in schema.sql" != "it exists here yet").
 * Memoised per request (a `static` local, the `orgLogoTableExists()`
 * sibling probes' shared idiom).
 */
function orgLogoTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblOrganisationLogos' LIMIT 1"
        );
        $cached = ($r && $r->fetch_row() !== null);
        if ($r) {
            $r->close();
        }
    } catch (\Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Fetch the ONE row a serving path needs for `(org, kind, variant)` —
 * an ACTIVE row, falling back from a requested non-default `variant` to
 * `'default'` when the specific variant hasn't been uploaded (§4.1/§5).
 *
 * SELECTs `ContentSanitised` — NEVER `ContentOriginal`. This is the ONLY
 * function any serving path (`org-logo.php`, `pdf_renderer.php`'s
 * `_pdfInlineOrgLogo()`) may use to read logo bytes; `tests/php/
 * test-org-logo-surfaces.php` bans a raw SELECT of this table anywhere else.
 *
 * @return array{Mime:string,ContentSanitised:?string,Sha256:string,AltText:?string,ByteSize:int,Width:?int,Height:?int}|null
 */
function orgLogoFetchServeRow(\mysqli $db, int $orgId, string $kind, string $variant): ?array
{
    if (!orgLogoTableExists($db) || $orgId <= 0) {
        return null;
    }
    $cols = 'Mime, ContentSanitised, Sha256, AltText, ByteSize, Width, Height';

    if ($variant !== 'default') {
        $stmt = $db->prepare(
            "SELECT {$cols} FROM tblOrganisationLogos
              WHERE OrgId = ? AND Kind = ? AND Variant = ? AND IsActive = 1 LIMIT 1"
        );
        $stmt->bind_param('iss', $orgId, $kind, $variant);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
        /* fall through to the 'default' variant below */
    }

    $stmt = $db->prepare(
        "SELECT {$cols} FROM tblOrganisationLogos
          WHERE OrgId = ? AND Kind = ? AND Variant = 'default' AND IsActive = 1 LIMIT 1"
    );
    $stmt->bind_param('is', $orgId, $kind);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Per-(kind,variant) META ONLY (no blobs) for one org — the admin card's
 * "what's already uploaded" read AND the `my_organisations` API's `logos`
 * field emit (§6.3) share this ONE query shape.
 *
 * @return list<array{Id:int,Kind:string,Variant:string,Mime:string,Width:?int,Height:?int,ByteSize:int,Sha256:string,AltText:?string,IsActive:int,UpdatedAt:string}>
 */
function orgLogoListForOrg(\mysqli $db, int $orgId): array
{
    if (!orgLogoTableExists($db) || $orgId <= 0) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT Id, Kind, Variant, Mime, Width, Height, ByteSize, Sha256, AltText, IsActive, UpdatedAt
           FROM tblOrganisationLogos
          WHERE OrgId = ?
          ORDER BY FIELD(Kind, ' . implode(',', array_fill(0, count(ihymnsOrgLogoKindKeys()), '?')) . '), Variant'
    );
    /* FIELD()'s ordering args are the fixed ladder keys — safe to bind as
       plain strings (never interpolated), and this is exactly the "hardcoded
       constants from a fixed $mappings array" carve-out rule #5 allows for
       building a `?,?,?` placeholder run. */
    $types  = 'i' . str_repeat('s', count(ihymnsOrgLogoKindKeys()));
    $params = array_merge([$orgId], ihymnsOrgLogoKindKeys());
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
