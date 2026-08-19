<?php

declare(strict_types=1);

/**
 * iHymns — Application Version & Information
 *
 * Copyright © 2026 iHymns. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Centralised application metadata and version information for the
 * iHymns web application. This file serves as the single point of
 * reference for all application identity, version, vendor, copyright,
 * and licensing information.
 *
 * This file is auto-updated by the CI/CD pipeline:
 * - Version.Number: the MAJOR is committed here (hand-edited, rare); the
 *   RELEASE (minor) + BUILD (patch) are injected at deploy time from the
 *   latest production `v*` tag + the commit count (deploy.yml, #1899).
 * - Build metadata (commit SHA, date, URL) injected by deploy.yml
 *
 * STRUCTURE:
 * Follows the same $app["Application"][...] array convention used
 * across all iHymns applications.
 *
 * USAGE:
 *   require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'infoAppVer.php';
 *   echo $app["Application"]["Name"];
 *   echo $app["Application"]["Version"]["Number"];
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * This file should only be included via require/include from other PHP files.
 * Deny direct HTTP access by checking that it was not called directly.
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    /* #1906 — redirect to a FIXED site-root path, never build the Location
       header from the raw request URI. header() already rejects CR/LF, but a
       tainted REQUEST_URI in a redirect is an open-redirect / cache-key smell;
       for every legitimate direct hit dirname('/includes/infoAppVer.php', 2)
       was already '/', so this is behaviour-identical for real traffic. */
    header('Location: /', true, 302);
    exit('<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=/"></head><body>Redirecting to <a href="/">iHymns</a>...</body></html>');
}

/* =========================================================================
 * INITIALISE THE APPLICATION METADATA ARRAY
 * ========================================================================= */

/* Initialise the top-level $app array */
$app = [];

/* =========================================================================
 * APPLICATION IDENTITY
 * ========================================================================= */

/* Shared base application identifier (common across all platforms) */
$app["Application"]["ID_Base"] = "Ltd.MWBMPartners.iHymns";

/* Platform-specific suffix */
$app["Application"]["ID_Platform"] = "PWA";

/* Full unique reverse-domain application identifier */
$app["Application"]["ID"] = $app["Application"]["ID_Base"] . "." . $app["Application"]["ID_Platform"];

/* Short application name (used in titles, manifests, UI) */
$app["Application"]["Name"] = "iHymns";

/* Application website URL (NULL if not yet live) */
$app["Application"]["Website"]["URL"] = "https://ihymns.app";

/* Synopsis: a brief description of the application's purpose */
$app["Application"]["Description"]["Synopsis"] = "A multiplatform Christian lyrics application providing searchable hymn and worship song lyrics from multiple songbooks, designed to enhance worship. Features 5 songbooks with over 3,600 songs, full-text search, favourites, dark mode, colourblind-friendly mode, and offline support via PWA.";

/* Keywords: comma-separated keywords for discoverability and SEO */
$app["Application"]["Description"]["Keywords"] = "hymns, worship, lyrics, songbook, Christian, church, praise, songs, PWA, offline, search, favourites";

/* =========================================================================
 * VERSION INFORMATION
 * ========================================================================= */

/* Semantic version number (MAJOR.MINOR.PATCH) */
/* TAG-DERIVED SCHEME (#1899). This committed value is:
     - the LOCAL-DEV / pre-first-tag display, and
     - the Apple MAJOR-parity anchor: appApple/Scripts/sync-version.sh reads
       THIS file (never a deployed artifact) and enforces that its MAJOR equals
       Versioning.xcconfig's MARKETING_VERSION major, so it MUST stay three
       plain integers "X.Y.Z" (no suffix — the regex `"[0-9]+\.[0-9]+\.[0-9]+"`
       would otherwise fail).
   MAJOR is hand-edited here (rare — a product-identity decision). The DEPLOYED
   value is `MAJOR.RELEASE.BUILD`, rewritten by deploy.yml from the latest
   production `v*` tag (RELEASE = the tag's minor) + the commit count (BUILD);
   an untagged checkout deploys this committed value unchanged. The `v*` tags
   are minted by promotion-deploy-bridge.yml at each beta→main promotion. The
   old auto-bumper (version-bump.yml) that ballooned the minor to 5250 is
   RETIRED — do NOT rely on a "+1 on merge" happening here, and keep
   api-docs.yaml's info.version in lockstep on any manual edit
   (tests/php/test-openapi-actions-exist.php guards it).

   History:
   - 0.4100.0 -> 0.5050.0 for the #89/#91 consolidated batch (the 214-commit
     `claude/issue-sweep-fixes-89` branch: the #1765 songbook/catalogue epic +
     #93 Publishers, the #1769/#1778 gating program, the #1767 print/PDF
     remainder, #94 IA-reconcile, #1770/#1792/#1798 Live-Follow, #1791 set-list
     sharing, #1786 public list-sort, #1785 musicians dedup, et al.) — an
     owner-directed significant minor bump reflecting the scope of the batch.
   - 0.5050.0 -> 0.5100.0 for the follow-up round on the same branch (the
     renamed-docroot migration hotfix #1816, three post-merge CI fixes, the
     plain-language What's New rework #1818, and the admin sidebar reorg +
     plain-English relabel of the Manage area #1822) — a modest minor bump for
     a smaller but real amount of further work.
   - 0.5100.0 -> 0.5150.0 for the org-branding round on the same branch (#1830
     per-organisation logos for Print Templates — a brand-asset store, a
     dedicated hardened SVG sanitiser, a public image endpoint, and a print
     `logo` block that also renders in the server PDF — plus the #1829 Missing
     Numbers hidden-held highlight and an owner-directed plain-English +
     minimal-disclosure rewrite of the in-app Help/Guides) — a minor bump sized
     to a real feature plus the docs pass.
   - 0.5150.0 -> 0.5160.0 for the Song-of-the-Day lyric-preview fix (#1841): the
     snippet now joins the opening lines into one "complete thought" phrase
     instead of showing just the first line (which is very often identical to
     the title), displayed single-line and truncated to the viewport with an
     ellipsis. A small, single-feature minor bump.
   - 0.5160.0 -> 0.5200.0 for the #1853 batch (merged to alpha 2026-08-14): the
     musician-profile migration fix (#1824), the v2 Song Editor cluster (#1845
     mobile shell / #1846 manual Save / #1849 IETF language picker / #1850
     single-line sidebar rows / #1847 clearer metadata-save error), the
     editor/shell hardening pass (#1851, ten fold-in fixes), the CSP-safe
     CDN->/vendor fallback (#1832) and the Microsoft Clarity Do-Not-Track
     privacy fix (#1852). A minor bump sized to a large feature + hardening
     batch.
   - 0.5200.0 -> 0.5250.0 for the #1855 transport-routing fix + the Editor2
     confidence pass (2026-08-14): manage/.htaccess 301-redirected `.php` to
     the extensionless URL on EVERY method, so a browser replayed each write
     POST as a body-less GET — v2 editor saves and the whole arrangement
     editor, plus server-PDF / bulk import / activity geo / place upsert, all
     silently lost their request body (#1855, closes #1847). Fixed at both
     layers (a GET/HEAD-only redirect condition + extensionless client URLs)
     with a mutation-proven CI guard and a browser-router mirror. Editor2 also
     regained its admin chrome — navbar/exit, footer, and the shared
     bottom-right toast (#1856) — and a compacted, still-accessible (buttons,
     never drag) arrangement editor (#1857). */
/* Note: the old "v1.x = local-JSON phase, v2.x = iLyrics dB phase" scheme is
   dead — reads went DB-direct with epic #1010 (there is no local-JSON phase to
   be in), so the major digit no longer encodes a data-source phase. */
$app["Application"]["Version"]["Number"] = "1.0.0";

/* Build number — the git commit count (`git rev-list --count HEAD`): a
 * monotonic, per-commit build identifier that advances on every landed commit,
 * independent of the semantic MAJOR.MINOR.PATCH above. NULL in source; the
 * deploy pipeline injects the real value via sed at deploy time — the same
 * no-commit-back mechanism as the commit SHA/date below — so it is never
 * bumped by hand and never churns git history. An un-injected checkout (local
 * dev) reads NULL. See deploy.yml, step "Inject build info into infoAppVer.php". */
$app["Application"]["Version"]["Build"]["Number"] = NULL;

/* Version name: human-readable release name (e.g., "Hymnal", NULL if unused) */
$app["Application"]["Version"]["Name"] = NULL;

/**
 * Development status: determined from the server's deployment directory.
 *
 * Since all branches deploy from the same source (appWeb/public_html/),
 * the environment is detected from the server-side DOCUMENT_ROOT or
 * SCRIPT_FILENAME path, which reflects the SFTP destination directory:
 *   - Remote path contains "public_html_dev"  → "Alpha"
 *   - Remote path contains "public_html_beta" → "Beta"
 *   - Otherwise (production public_html/)     → NULL (no label)
 *
 * Fallback: checks for a .env-channel file injected by CI/CD.
 */
$serverPath = $_SERVER['DOCUMENT_ROOT'] ?? $_SERVER['SCRIPT_FILENAME'] ?? __DIR__;
$envChannelFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env-channel';
$app["Application"]["Version"]["Development"]["Status"] = match (true) {
    /* Alpha/dev deployment — server path contains "public_html_dev" */
    str_contains($serverPath, 'public_html_dev') => "Alpha",
    /* Beta deployment — server path contains "public_html_beta" */
    str_contains($serverPath, 'public_html_beta') => "Beta",
    /* CI/CD injected channel file fallback */
    file_exists($envChannelFile) => match (trim(file_get_contents($envChannelFile))) {
        'alpha' => "Alpha",
        'beta'  => "Beta",
        default => null,
    },
    /* Production deployment — no development status label */
    default => null,
};

/* --- Repository / Commit Metadata --- */
/* These fields are populated at deploy time by the GitHub Actions pipeline */
/* They default to NULL in source and are replaced via sed during deployment */

/* Full git commit SHA (40 characters) */
$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Full"] = NULL;

/* Short git commit SHA (7 characters, for display) */
$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"] = NULL;

/* Commit date/time (ISO 8601 format) */
$app["Application"]["Version"]["Repo"]["Commit"]["Date"] = NULL;

/* GitHub URL to the specific commit */
$app["Application"]["Version"]["Repo"]["Commit"]["URL"] = NULL;

/* =========================================================================
 * VENDOR INFORMATION
 * ========================================================================= */

/* Primary vendor/developer name */
$app["Application"]["Vendor"]["Name"] = "iHymns";

/* Primary vendor website URL */
$app["Application"]["Vendor"]["Website"]["URL"] = "https://ihymns.app";

/* Parent company name */
$app["Application"]["Vendor"]["Parent"]["Name"] = "MWBM Partners Ltd";

/* Parent company website URL */
$app["Application"]["Vendor"]["Parent"]["Website"]["URL"] = NULL;

/* =========================================================================
 * COPYRIGHT
 * ========================================================================= */

/* Year copyright protection began */
$app["Application"]["Copyright"]["Year"]["Start"] = "2026";

/**
 * Dynamically compute the copyright year range for display.
 *
 * If the current year is the same as the start year, show just "2026".
 * Otherwise, show "2026–<current year>" (e.g., "2026–2028").
 * This ensures the copyright notice is always current without manual updates.
 */
$currentYear = date('Y');
$app["Application"]["Copyright"]["UseVendor"] = FALSE;
if ($currentYear > $app["Application"]["Copyright"]["Year"]["Start"]) {
    /* Multi-year range: "2026–2028" */
    $app["Application"]["Copyright"]["Year"]["Display"] = $app["Application"]["Copyright"]["Year"]["Start"] . "–" . $currentYear;
} else {
    /* Single year: "2026" */
    $app["Application"]["Copyright"]["Year"]["Display"] = $app["Application"]["Copyright"]["Year"]["Start"];
}

/* Rights statement */
$app["Application"]["Copyright"]["RightsStatement"] = "All Rights Reserved";

/* Full copyright string for display: "Application © 2026 iHymns. All Rights Reserved" (#230)
 * The "Application" prefix distinguishes the software copyright from song copyrights. */
if (isset($app["Application"]["Copyright"]["UseVendor"]) && $app["Application"]["Copyright"]["UseVendor"]) {
    $app["Application"]["Copyright"]["Full"] = "Application &copy; " . $app["Application"]["Copyright"]["Year"]["Display"] . " " . $app["Application"]["Vendor"]["Name"] . ". " . $app["Application"]["Copyright"]["RightsStatement"];
}
else {
    $app["Application"]["Copyright"]["Full"] = "Application &copy; " . $app["Application"]["Copyright"]["Year"]["Display"] . " " . $app["Application"]["Name"] . ". " . $app["Application"]["Copyright"]["RightsStatement"];
}

/* =========================================================================
 * LICENSING — DEVELOPER
 * ========================================================================= */

/* Developer licence type (e.g., "MIT", "Proprietary", NULL) */
$app["Application"]["License"]["Developer"]["Type"] = "Proprietary";

/* Developer licence cost */
$app["Application"]["License"]["Developer"]["Cost"] = NULL;

/* Developer licence agreement URL */
$app["Application"]["License"]["Developer"]["Agreement"]["URL"] = NULL;

/* Developer terms of service URL */
$app["Application"]["License"]["Developer"]["ToSURL"] = NULL;

/* =========================================================================
 * LICENSING — USER / END-USER
 * ========================================================================= */

/* User licence type */
$app["Application"]["License"]["User"]["Type"] = "Freeware";

/* User licence cost */
$app["Application"]["License"]["User"]["Cost"] = "Free";

/* User licence agreement URL */
$app["Application"]["License"]["User"]["Agreement"]["URL"] = NULL;

/* User terms of service URL */
$app["Application"]["License"]["User"]["ToSURL"] = NULL;

/* =========================================================================
 * REPOSITORY INFORMATION
 * ========================================================================= */

/* GitHub repository URL */
$app["Application"]["Repo"]["URL"] = "https://github.com/MWBMPartners/iHymns";

/* GitHub issues URL */
$app["Application"]["Repo"]["Issues"]["URL"] = "https://github.com/MWBMPartners/iHymns/issues";
