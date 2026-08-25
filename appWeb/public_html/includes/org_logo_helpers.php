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

/**
 * Render the "Organisation logos" admin card — ONE markup source shared by
 * `manage/organisations.php` (system admins) and `manage/my-organisations.php`
 * (org admins), §7.1 of the plan: "the way `organisation_validation.php`
 * already serves both" pages. Both callers already gated the CURRENT
 * viewer's right to act on `$orgId` before calling this (`manage_organisations`
 * / `manage_own_organisation` + the row-level `$canActOnOrg()` closure) — this
 * function renders unconditional upload/remove/toggle CONTROLS, it does not
 * re-check permission itself.
 *
 * Renders '' (nothing) when `orgLogoTableExists()` is false — the same
 * `placeColumnExists()`-style dormancy posture both pages already use for
 * other optional schema (rule #19): a pre-migration install shows no card
 * at all, never a broken one.
 *
 * A row WITHOUT an upload renders collapsed (label + description + Add);
 * a row WITH one expands to a preview (through `/org-logo…` — the
 * never-inline rule applies to the ADMIN PREVIEW too, no `<svg>` markup and
 * no data-URI from the DB bytes is ever printed into this admin page) plus
 * Replace / Show-Hide / Remove controls. Copy is the plan's §7.2 quotes
 * verbatim (`.claude/admin-plain-english.md` — plain English, minimal
 * disclosure, no table/endpoint/sanitiser talk beyond the one upload hint).
 *
 * All three POST actions this card's forms submit — `logo_upload` /
 * `logo_remove` / `logo_toggle` — share the SAME field names on both pages:
 * `csrf_token`, `action`, `org_id`, `kind` (+ `logo_file`/`alt_text` for
 * upload, `active` for toggle). Each page's own handler validates `$kind`
 * against `ihymnsOrgLogoKindKeys()` again server-side before touching the
 * database (rule #5 — never trust a hidden form field alone).
 *
 * @param  string $csrfToken  The page's own `csrfToken()` value.
 */
function orgLogoRenderAdminCard(\mysqli $db, int $orgId, string $csrfToken): string
{
    if (!orgLogoTableExists($db) || $orgId <= 0) {
        return '';
    }

    /* Bare, standalone string literals (never embedded inside a larger HTML
       string) — tests/php/test-orphan-inventory.php's dispatch-caller scan
       matches a PHP string literal's FULL content against the known action
       names it discovered from the two pages' `switch ($action)` blocks;
       an action name baked into a longer literal like
       `'<input … value="logo_upload">'` is invisible to that scan (its
       token content is the whole tag, not the bare name), which is exactly
       what an EARLIER version of this function did and which the guard
       correctly flagged as three caller-less actions. Keeping these as
       their own assignment is what makes the emitted forms discoverable. */
    $actUpload = 'logo_upload';
    $actRemove = 'logo_remove';
    $actToggle = 'logo_toggle';

    /* #1840 — per-(kind,variant) lookup so the "Theme versions" strip below
       can find each kind's light/dark rows alongside the existing
       default-only $existingByKind fold (kept for the "is this row's kind
       expanded at all" check, unchanged from #1830). */
    $existingByKindVariant = [];
    foreach (orgLogoListForOrg($db, $orgId) as $row) {
        $existingByKindVariant[$row['Kind']][$row['Variant']] = $row;
    }
    $existingByKind = [];
    foreach ($existingByKindVariant as $kind => $variants) {
        if (isset($variants['default'])) {
            $existingByKind[$kind] = $variants['default'];
        }
    }

    $csrfEsc = htmlspecialchars($csrfToken, ENT_QUOTES);
    $rowsHtml = '';

    foreach (IHYMNS_ORG_LOGO_KINDS as $kind => $def) {
        [$label, $description] = $def;
        $kindEsc  = htmlspecialchars($kind, ENT_QUOTES);
        $labelEsc = htmlspecialchars($label, ENT_QUOTES);
        $descEsc  = htmlspecialchars($description, ENT_QUOTES);
        $existing = $existingByKind[$kind] ?? null;

        $hiddenFields = '<input type="hidden" name="csrf_token" value="' . $csrfEsc . '">'
            . '<input type="hidden" name="org_id" value="' . $orgId . '">'
            . '<input type="hidden" name="kind" value="' . $kindEsc . '">';

        if ($existing === null) {
            /* Collapsed row — label + description + a single "Add" upload form. */
            $rowsHtml .= '<div class="org-logo-row border rounded p-2 mb-2">'
                . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">'
                . '<div><span class="fw-semibold small">' . $labelEsc . '</span>'
                . '<span class="text-muted small ms-1">— ' . $descEsc . '</span></div>'
                . '<form method="POST" enctype="multipart/form-data" class="d-flex gap-2 align-items-center flex-wrap">'
                . $hiddenFields
                . '<input type="hidden" name="action" value="' . $actUpload . '">'
                . '<input type="file" name="logo_file" accept=".svg,.png,image/svg+xml,image/png"'
                . ' class="form-control form-control-sm" style="max-width:220px;" required>'
                . '<button type="submit" class="btn btn-sm btn-amber-solid">Add</button>'
                . '</form></div></div>';
            continue;
        }

        /* Expanded row — preview (through the ONE serving endpoint — never
           inlined) + alt text + Replace / Show-Hide / Remove. */
        $sha       = (string)$existing['Sha256'];
        /* "/org-logo", never "/org-logo.php" — this admin preview <img> is
           requested by the BROWSER as a fresh top-level request, so it is
           just as subject to .htaccess's "block direct PHP access" rule as
           any public-facing src; living inside an authenticated /manage/
           page does not exempt an <img src> that points OUTSIDE /manage/
           (rules #33/#38/#42; see .htaccess for the extensionless alias). */
        $preview   = '/org-logo?org=' . $orgId . '&kind=' . rawurlencode($kind) . '&v=' . rawurlencode($sha);
        $previewEsc = htmlspecialchars($preview, ENT_QUOTES);
        $altEsc    = htmlspecialchars((string)($existing['AltText'] ?? ''), ENT_QUOTES);
        $isActive  = ((int)$existing['IsActive'] === 1);
        $toggleTo  = $isActive ? '0' : '1';
        $toggleLabel = $isActive ? 'Hide' : 'Show';
        /* #1840 — removing the DEFAULT row cascades to its light/dark theme
           versions too (orgLogoDeleteKindAll(), so the confirm copy says so
           up front rather than surprising a curator after the fact. */
        $confirmRemove = "Remove the {$labelEsc} logo and its theme versions?";

        /* #1840 §3.5 — "Theme versions (optional)" strip, ONE per expanded
           kind row: a Light-theme and a Dark-theme slot, each independently
           empty (Add form) or filled (preview + Replace/Remove), gated on
           the kind already having a 'default' row (variants require the
           default — an orphan dark-only row would show on dark screens and
           silently vanish on light ones, the silent-half-feature class this
           repo documents; enforced here by only ever rendering this strip
           inside the ALREADY-expanded branch). No per-variant alt-text
           input — AltText meaning rides the kind's default row (the variant
           is the same asset re-drawn, one accessible name). */
        $variantLabels = ['light' => 'Light theme', 'dark' => 'Dark theme'];
        $variantSlotsHtml = '';
        foreach ($variantLabels as $variant => $variantLabel) {
            $variantLabelEsc = htmlspecialchars($variantLabel, ENT_QUOTES);
            $variantRow = $existingByKindVariant[$kind][$variant] ?? null;

            if ($variantRow === null) {
                $variantSlotsHtml .= '<form method="POST" enctype="multipart/form-data"'
                    . ' class="d-flex align-items-center gap-2 org-logo-variant-slot">'
                    . $hiddenFields
                    . '<input type="hidden" name="action" value="' . $actUpload . '">'
                    . '<input type="hidden" name="variant" value="' . $variant . '">'
                    . '<span class="small text-muted" style="min-width:6.5rem;">' . $variantLabelEsc . '</span>'
                    . '<input type="file" name="logo_file" accept=".svg,.png,image/svg+xml,image/png"'
                    . ' class="form-control form-control-sm" style="max-width:170px;" required>'
                    . '<button type="submit" class="btn btn-sm btn-outline-secondary">Add</button>'
                    . '</form>';
                continue;
            }

            $vSha = (string)$variantRow['Sha256'];
            /* Same reasoning as the default-row $preview above — extensionless
               /org-logo, never /org-logo.php. */
            $vPreview = '/org-logo?org=' . $orgId . '&kind=' . rawurlencode($kind)
                . '&variant=' . rawurlencode($variant) . '&v=' . rawurlencode($vSha);
            $vPreviewEsc = htmlspecialchars($vPreview, ENT_QUOTES);
            $variantSlotsHtml .= '<div class="d-flex align-items-center gap-2 org-logo-variant-slot">'
                . '<img src="' . $vPreviewEsc . '" alt="' . $labelEsc . ' (' . $variantLabelEsc . ') preview"'
                . ' style="max-height:44px;max-width:80px;object-fit:contain;background:#fff;border-radius:4px;padding:3px;">'
                . '<span class="small text-muted" style="min-width:6.5rem;">' . $variantLabelEsc . '</span>'
                . '<form method="POST" enctype="multipart/form-data" class="d-flex gap-1 align-items-center">'
                . $hiddenFields
                . '<input type="hidden" name="action" value="' . $actUpload . '">'
                . '<input type="hidden" name="variant" value="' . $variant . '">'
                . '<input type="file" name="logo_file" accept=".svg,.png,image/svg+xml,image/png"'
                . ' class="form-control form-control-sm" style="max-width:130px;" required>'
                . '<button type="submit" class="btn btn-sm btn-outline-secondary">Replace</button>'
                . '</form>'
                . '<form method="POST" class="d-inline">' . $hiddenFields
                . '<input type="hidden" name="action" value="' . $actRemove . '">'
                . '<input type="hidden" name="variant" value="' . $variant . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-danger">Remove</button></form>'
                . '</div>';
        }
        $variantStripHtml = '<div class="org-logo-variant-strip mt-2 ps-2 border-start">'
            . '<div class="text-muted small mb-1">Theme versions <span class="fw-normal">(optional)</span> '
            . '— add a version drawn for light or dark screens and iHymns will pick the right one automatically.</div>'
            . '<div class="d-flex flex-wrap gap-3">' . $variantSlotsHtml . '</div>'
            . '</div>';

        $rowsHtml .= '<div class="org-logo-row border rounded p-2 mb-2">'
            . '<div class="d-flex align-items-start gap-3 flex-wrap">'
            . '<img src="' . $previewEsc . '" alt="' . $labelEsc . ' preview"'
            . ' style="max-height:60px;max-width:120px;object-fit:contain;background:#fff;border-radius:4px;padding:4px;">'
            . '<div class="flex-grow-1">'
            . '<div class="fw-semibold small">' . $labelEsc . ($isActive ? '' : ' <span class="badge bg-secondary">hidden</span>') . '</div>'
            . '<div class="text-muted small mb-1">' . $descEsc . '</div>'
            . '<form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center mb-1">'
            . $hiddenFields
            . '<input type="hidden" name="action" value="' . $actUpload . '">'
            . '<input type="file" name="logo_file" accept=".svg,.png,image/svg+xml,image/png"'
            . ' class="form-control form-control-sm" style="max-width:200px;" required>'
            . '<input type="text" name="alt_text" value="' . $altEsc . '" placeholder="Alt text (optional)"'
            . ' class="form-control form-control-sm" style="max-width:180px;">'
            . '<button type="submit" class="btn btn-sm btn-outline-secondary">Replace</button>'
            . '</form>'
            . '<div class="d-flex gap-2">'
            . '<form method="POST" class="d-inline">' . $hiddenFields
            . '<input type="hidden" name="action" value="' . $actToggle . '">'
            . '<input type="hidden" name="active" value="' . $toggleTo . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-secondary">' . $toggleLabel . '</button></form>'
            . '<form method="POST" class="d-inline" onsubmit="return confirm(' . json_encode($confirmRemove) . ');">' . $hiddenFields
            . '<input type="hidden" name="action" value="' . $actRemove . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-danger">Remove</button></form>'
            . '</div>'
            . $variantStripHtml
            . '</div></div></div>';
    }

    return '<div class="card-admin p-3 mb-3 org-logo-card">'
        . '<h3 class="h6 mb-2"><i class="bi bi-image me-2"></i>Organisation logos</h3>'
        . '<p class="text-muted small mb-2">Upload your organisation\'s logo so printed song sheets, '
        . "the app's header (for signed-in members), your projector screen and shared set-list "
        . 'pictures can carry your branding. You can add several shapes — a main logo, wide and '
        . 'stacked layouts, a symbol on its own, single-colour and light-on-dark versions — and each '
        . 'place will use the best one available.</p>'
        . '<p class="text-muted small mb-2">SVG files look sharpest in print; PNG also works. For your '
        . 'safety we tidy SVG files on upload, so decorative effects like animation or embedded pictures '
        . "won't be kept.</p>"
        /* #1840 §B.6 — the og-card share-preview picture is drawn by a
           library that cannot read SVG at all; PNG rows are the only ones
           it can composite. A one-sentence nudge here (never a hard
           requirement — SVG still works everywhere else) is the whole fix;
           trivially changeable if the wording ever needs to move. */
        . '<p class="text-muted small mb-3">For share-preview images (like a shared set-list picture), '
        . "add a PNG version of your light-on-dark logo too — that picture can't use an SVG file.</p>"
        . $rowsHtml
        . '</div>';
}

/* =============================================================================
 * #1840 — SCREEN-SURFACE BRANDING: the ONE themed-surface registry + resolver
 * =============================================================================
 *
 * ELI5
 * ----
 * Three new places on screen (the app's top header, the projector, and the
 * shared "share this on social media" picture) want to show a church's logo
 * too — but each of those places sits on a DIFFERENT background (a normal
 * page, an always-dark projector screen, a coloured banner), so each one has
 * its own short "wish list" of which logo SHAPE it would like, and whether
 * light or dark artwork fits its background best. This is that ONE wish-list
 * table, plus the ONE function that turns "here's what the org actually
 * uploaded" into "here's the one picture to show" for a given place and a
 * given light/dark theme.
 *
 * DETAILED
 * --------
 * `IHYMNS_ORG_LOGO_SURFACE_PREFS` names every consumer surface once — never a
 * second copy per file (rule #35). Key order within a surface's `kinds` list
 * IS that surface's resolution order (the SAME "key-order-is-the-ladder"
 * doctrine `IHYMNS_ORG_LOGO_KINDS` already uses). `darkCapableOnly` marks a
 * surface whose ground is permanently dark/coloured (never the plain white a
 * `'default'`-variant rendition assumes) — for those surfaces the
 * `'default'`-variant fallback step is skipped for every kind EXCEPT
 * `'reversed'` (whose `'default'` rendition IS, by the registry's own
 * definition, already light-on-dark — see `IHYMNS_ORG_LOGO_KINDS['reversed']`
 * above).
 *
 * `ihymnsOrgLogoResolveThemedAsset()` is pure and DB-free (list-in,
 * choice-out) — the same "unit-testable like `ihymnsOrgLogoResolveKind()`"
 * shape that function already has. Algorithm, per kind K in the surface's
 * `kinds` list, IN ORDER:
 *   1. `(K, $theme)` — the exact theme-paired rendition.
 *   2. `(K, 'default')` — skipped when the surface is `darkCapableOnly` AND
 *      `K !== 'reversed'`.
 * The first hit (in that per-kind, then-per-step order) wins; nothing found
 * across every kind returns `null` — the caller renders NOTHING, never a
 * broken image and never a kind the ladder doesn't document (rule #42's
 * "never substitute a different kind" carried over from the print `'auto'`
 * ladder to this new themed axis).
 *
 * @link .claude/org-logo-surfaces-1840-plan.md §3.2  the full design this implements
 * @link appWeb/public_html/js/modules/org-logo.js     the exact client-side mirror (rule #35 lockstep)
 * @see #1840
 */

/**
 * Per-surface kind preference lists (§3.2 of the plan). Key order within
 * each `kinds` array is that surface's resolution ladder.
 *
 * `'header'`    — the app's top nav-bar co-brand (small, ~28px slot).
 * `'projector'` — the Service-Projection corner bug (always on a dark ground).
 * `'og-card'`   — the social share-preview band (always on the org's own
 *                 brand-colour ground, i.e. "dark-ish" by construction).
 */
const IHYMNS_ORG_LOGO_SURFACE_PREFS = [
    'header'    => ['kinds' => ['emblem', 'favicon'],             'darkCapableOnly' => false],
    'projector' => ['kinds' => ['emblem', 'reversed', 'favicon'], 'darkCapableOnly' => false],
    'og-card'   => ['kinds' => ['reversed', 'emblem'],            'darkCapableOnly' => true],
];

/**
 * Resolve WHICH (kind, variant) a themed screen surface should render, given
 * the org's ACTIVE (kind, variant) rows and the viewer's current theme.
 * Pure + DB-free — see this section's doc-block for the full algorithm.
 *
 * @param  list<array{kind:string,variant:string}> $available  ACTIVE rows
 *         only (callers filter `IsActive`; the `og-card` surface additionally
 *         pre-filters to PNG rows before calling this — that FILTER is the
 *         caller's constraint, this ladder is shared regardless of it).
 * @param  string $surface  One of `IHYMNS_ORG_LOGO_SURFACE_PREFS`'s keys.
 *                           An unknown surface returns `null` (never throws —
 *                           a typo'd surface name degrades to "show nothing",
 *                           the same fail-safe posture as an unresolved kind).
 * @param  string $theme    `'light'` or `'dark'`; anything else is treated
 *                           as `'light'` (defensive default — a themed
 *                           surface always resolves ONE of the two).
 * @return array{kind:string,variant:string}|null  `null` => render NOTHING.
 */
function ihymnsOrgLogoResolveThemedAsset(array $available, string $surface, string $theme): ?array
{
    if (!isset(IHYMNS_ORG_LOGO_SURFACE_PREFS[$surface])) {
        return null;
    }
    $prefs = IHYMNS_ORG_LOGO_SURFACE_PREFS[$surface];
    $theme = ($theme === 'dark') ? 'dark' : 'light';

    /* A fast (kind|variant) => true lookup set, built once, so the per-kind
       ladder below is a cheap isset() rather than an O(n) scan per step. */
    $have = [];
    foreach ($available as $row) {
        $kind    = (string)($row['kind'] ?? '');
        $variant = (string)($row['variant'] ?? '');
        if ($kind === '' || $variant === '') {
            continue;
        }
        $have[$kind . '|' . $variant] = true;
    }

    foreach ($prefs['kinds'] as $kind) {
        /* Step 1 — the exact theme-paired rendition. */
        if (isset($have[$kind . '|' . $theme])) {
            return ['kind' => $kind, 'variant' => $theme];
        }
        /* Step 2 — the 'default' rendition, skipped on a darkCapableOnly
           surface for every kind except 'reversed' (whose 'default' IS
           already light-on-dark by the kind registry's own definition). */
        if ($prefs['darkCapableOnly'] && $kind !== 'reversed') {
            continue;
        }
        if (isset($have[$kind . '|default'])) {
            return ['kind' => $kind, 'variant' => 'default'];
        }
    }
    return null;
}
