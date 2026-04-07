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
 * - Version number bumped by version-bump.yml workflow
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
    header('Location: ' . dirname($_SERVER['REQUEST_URI'] ?? '', 2) . '/', true, 302);
    exit('<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=../"></head><body>Redirecting to <a href="../">iHymns</a>...</body></html>');
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
/* Auto-bumped by the version-bump GitHub Action on push to beta */
/* v1.x.x = Phase 1 (local JSON data), v2.x.x = Phase 2 (iLyrics dB) */
$app["Application"]["Version"]["Number"] = "0.1.9";

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
$envChannelFile = dirname(__DIR__) . '/.env-channel';
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
$app["Application"]["Vendor"]["Parent"]["Name"] = NULL;

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

/* Full copyright string for display: "© 2026 iHymns. All Rights Reserved" */
if (isset($app["Application"]["Copyright"]["UseVendor"]) && $app["Application"]["Copyright"]["UseVendor"]) {
    $app["Application"]["Copyright"]["Full"] = "&copy; " . $app["Application"]["Copyright"]["Year"]["Display"] . " " . $app["Application"]["Vendor"]["Name"] . ". " . $app["Application"]["Copyright"]["RightsStatement"];
}
else {
    $app["Application"]["Copyright"]["Full"] = "&copy; " . $app["Application"]["Copyright"]["Year"]["Display"] . " " . $app["Application"]["Name"] . ". " . $app["Application"]["Copyright"]["RightsStatement"];
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
