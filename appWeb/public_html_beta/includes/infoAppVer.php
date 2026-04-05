<?php
/**
 * iHymns — Application Version Information
 *
 * Copyright © 2026–<?php echo date('Y'); ?> MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Centralised version metadata for the iHymns web application.
 * This file is auto-updated by the CI/CD pipeline (version-bump workflow).
 * Build metadata (commit SHA, date, URL) is injected at deploy time.
 *
 * USAGE:
 *   <?php require_once 'includes/infoAppVer.php'; ?>
 *   Then access $app['Version']['Number'], $app['Name'], etc.
 */

/* =========================================================================
 * APPLICATION METADATA
 * ========================================================================= */

/* Initialise the $app array to hold all application metadata */
$app = [];

/* --- Application Identity --- */

/* The short name of the application (used in manifests, titles, etc.) */
$app['Name'] = 'iHymns';

/* The full descriptive name of the application */
$app['FullName'] = 'iHymns — Christian Lyrics Application';

/* A brief description of what the application does */
$app['Description'] = 'A multiplatform Christian lyrics application for worship enhancement';

/* The application's website domain */
$app['Domain'] = 'ihymns.app';

/* The application's full URL */
$app['URL'] = 'https://ihymns.app';

/* --- Version Information --- */

/* Initialise the Version sub-array */
$app['Version'] = [];

/* The semantic version number (MAJOR.MINOR.PATCH) */
/* This is auto-bumped by the version-bump GitHub Action on push to beta */
$app['Version']['Number'] = '1.0.0-alpha.1';

/* The version phase label (e.g., 'alpha', 'beta', 'stable', NULL for production) */
$app['Version']['Phase'] = 'alpha';

/* --- Build / Deployment Metadata --- */
/* These fields are populated at deploy time by the GitHub Actions pipeline */
/* They default to NULL in the source code and are replaced during deployment */

/* Initialise the Development sub-array */
$app['Development'] = [];

/* The development/deployment status (e.g., 'Beta', 'Development', NULL for production) */
$app['Development']['Status'] = 'Development';

/* The full git commit SHA that this build was created from */
$app['Development']['CommitSHA'] = NULL;

/* The short (7-char) git commit SHA for display purposes */
$app['Development']['CommitShort'] = NULL;

/* The date/time of the commit this build was created from (ISO 8601) */
$app['Development']['CommitDate'] = NULL;

/* The GitHub URL to the specific commit */
$app['Development']['CommitURL'] = NULL;

/* --- Copyright & Legal --- */

/* Initialise the Legal sub-array */
$app['Legal'] = [];

/* The starting year of the copyright (first year of development) */
$app['Legal']['CopyrightStartYear'] = 2026;

/**
 * Dynamically compute the copyright year range.
 * If the current year is the same as the start year, show just "2026".
 * Otherwise, show "2026–<current year>" (e.g., "2026–2028").
 */
$currentYear = (int) date('Y');
if ($currentYear > $app['Legal']['CopyrightStartYear']) {
    $app['Legal']['CopyrightYears'] = $app['Legal']['CopyrightStartYear'] . '–' . $currentYear;
} else {
    $app['Legal']['CopyrightYears'] = (string) $app['Legal']['CopyrightStartYear'];
}

/* The copyright holder's name */
$app['Legal']['CopyrightHolder'] = 'MWBM Partners Ltd';

/* The full copyright string for display (e.g., "© 2026 MWBM Partners Ltd") */
$app['Legal']['CopyrightFull'] = '© ' . $app['Legal']['CopyrightYears'] . ' ' . $app['Legal']['CopyrightHolder'];

/* The licence type */
$app['Legal']['Licence'] = 'Proprietary';

/* --- Repository Information --- */

/* Initialise the Repo sub-array */
$app['Repo'] = [];

/* The GitHub repository URL */
$app['Repo']['URL'] = 'https://github.com/MWBMPartners/iHymns';

/* The GitHub issues URL */
$app['Repo']['Issues'] = 'https://github.com/MWBMPartners/iHymns/issues';
