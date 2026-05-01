<?php

declare(strict_types=1);

/**
 * iHymns — IANA Language Subtag Registry import (#738)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Brings the IETF BCP 47 picker (#681) up to full coverage by
 * importing the canonical IANA Language Subtag Registry — every
 * language (~8,000), script (~225), region (~305), and variant
 * (~140) subtag — plus polished display names from the CLDR
 * English locale.
 *
 * Data sources (BUNDLED — tracked in git):
 *   appWeb/.sql/data/iana-language-subtag-registry.txt
 *   appWeb/.sql/data/cldr-en-languages.json
 *   appWeb/.sql/data/cldr-en-scripts.json
 *   appWeb/.sql/data/cldr-en-territories.json
 *   appWeb/.sql/data/cldr-en-variants.json
 *
 * The bundled snapshots are the deployment baseline — first-install
 * deployments don't need internet. The /manage/setup-database page
 * (#738 follow-up) provides a "Refresh from IANA + CLDR" button
 * that fetches fresh files and runs this migration again; the
 * bundled snapshots get overwritten at refresh time.
 *
 * Schema work (idempotent):
 *   1. Rename tblScripts → tblLanguageScripts (clarity — "scripts"
 *      is ambiguous with mini-programs).
 *   2. Widen tblLanguages.Code from VARCHAR(10) → VARCHAR(35) so
 *      the rare 8-char IANA subtag (e.g. some private-use tags)
 *      fits. tblSongTranslations.TargetLanguage was already
 *      widened to VARCHAR(35) by #681 — the FK alignment now
 *      matches.
 *   3. Add tblLanguages.Scope column ('individual' | 'macrolanguage'
 *      | 'special') so the picker can rank macrolanguages first.
 *   4. Create tblLanguageVariants (new — variant subtags).
 *
 * Data work (idempotent — INSERT IGNORE + selective UPDATE):
 *   5. Parse the IANA registry's `%%`-separated record blocks and
 *      INSERT IGNORE rows into the four tables.
 *   6. Parse CLDR JSONs and UPDATE Name / NativeName columns where
 *      CLDR has a more polished value than IANA's raw Description.
 *
 * @migration-adds tblLanguages.Scope
 * @migration-adds tblLanguageVariants.Code
 * @migration-adds tblLanguageVariants.Name
 * @migration-adds tblLanguageVariants.IsActive
 * @migration-adds tblLanguageVariants.CreatedAt
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-iana-language-subtag-registry.php
 *   Web: /manage/setup-database → "Refresh BCP 47 reference data"
 *        (entry point requires global_admin)
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migIana_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migIana_tableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migIana_columnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migIana_columnWidth(mysqli $db, string $table, string $column): ?int
{
    $stmt = $db->prepare(
        'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (int)$row[0] : null;
}

/**
 * Parse the IANA Language Subtag Registry text format.
 *
 * The file is a sequence of `%%`-separated record blocks. Each block
 * is a list of folded-line key:value pairs. We care about:
 *
 *   Type:        language | script | region | variant | extlang |
 *                grandfathered | redundant
 *   Subtag:      the canonical subtag itself (or "Tag:" for grandfathered)
 *   Description: human-readable name (one or more lines; first wins)
 *   Scope:       individual | macrolanguage | collection | private-use |
 *                special  (only on language records)
 *
 * Returns: ['languages' => [[subtag, description, scope], …],
 *           'scripts'   => [[subtag, description], …],
 *           'regions'   => [[subtag, description], …],
 *           'variants'  => [[subtag, description], …]]
 */
function _migIana_parseRegistry(string $text): array
{
    $out = [
        'languages' => [],
        'scripts'   => [],
        'regions'   => [],
        'variants'  => [],
    ];
    /* Records separate on a line containing only "%%". The first chunk
       is the file header (File-Date: …), discard it. */
    $blocks = preg_split('/\n%%\n/', $text);
    array_shift($blocks);

    foreach ($blocks as $block) {
        $rec = [];
        /* Folded-line continuation: a line starting with whitespace
           continues the previous field. Unfold first. */
        $unfolded = preg_replace('/\n[ \t]+/', ' ', $block);
        foreach (explode("\n", $unfolded) as $line) {
            if ($line === '' || strpos($line, ':') === false) continue;
            [$k, $v] = explode(':', $line, 2);
            $k = trim($k);
            $v = trim($v);
            /* First Description: line wins; subsequent ones are
               aliases that we ignore for v1. */
            if (!isset($rec[$k])) {
                $rec[$k] = $v;
            }
        }
        $type = $rec['Type'] ?? '';
        /* "Subtag" is the canonical key; "Tag" is used by
           grandfathered/redundant records (which we skip). */
        $sub  = $rec['Subtag'] ?? '';
        $desc = $rec['Description'] ?? '';
        if ($sub === '' || $desc === '') continue;

        if ($type === 'language') {
            $out['languages'][] = [
                'subtag'      => strtolower($sub),
                'description' => $desc,
                'scope'       => $rec['Scope'] ?? 'individual',
            ];
        } elseif ($type === 'script') {
            $out['scripts'][] = [
                'subtag'      => ucfirst(strtolower($sub)),
                'description' => $desc,
            ];
        } elseif ($type === 'region') {
            $out['regions'][] = [
                'subtag'      => strtoupper($sub),
                'description' => $desc,
            ];
        } elseif ($type === 'variant') {
            $out['variants'][] = [
                'subtag'      => strtolower($sub),
                'description' => $desc,
            ];
        }
        /* extlang / grandfathered / redundant — out of scope for v1. */
    }
    return $out;
}

/**
 * Pull the inner names map from a CLDR localenames JSON file.
 *
 *   $cldrJson['main']['en']['localeDisplayNames']['languages']    → {'aa': 'Afar', …}
 *   $cldrJson['main']['en']['localeDisplayNames']['scripts']      → {'Latn': 'Latin', …}
 *   $cldrJson['main']['en']['localeDisplayNames']['territories']  → {'GB': 'United Kingdom', …}
 *   $cldrJson['main']['en']['localeDisplayNames']['variants']     → {'1996': 'German orthography of 1996', …}
 *
 * Returns the inner map, or [] if the structure isn't as expected
 * (e.g. CLDR changes its JSON layout in a future revision).
 */
function _migIana_cldrInner(string $path, string $kind): array
{
    if (!is_readable($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $j = @json_decode($raw, true);
    if (!is_array($j)) return [];
    $node = $j['main']['en']['localeDisplayNames'][$kind] ?? null;
    return is_array($node) ? $node : [];
}

_migIana_out('IANA Language Subtag Registry import — starting…');

$db = getDbMysqli();
if (!$db) {
    _migIana_out('ERROR: could not connect to database.');
    exit(1);
}

/* =========================================================================
 * Step 1 — Rename tblScripts → tblLanguageScripts
 * ========================================================================= */
$oldScriptsExists = _migIana_tableExists($db, 'tblScripts');
$newScriptsExists = _migIana_tableExists($db, 'tblLanguageScripts');

if ($oldScriptsExists && !$newScriptsExists) {
    if (!$db->query('RENAME TABLE tblScripts TO tblLanguageScripts')) {
        _migIana_out('ERROR: rename tblScripts → tblLanguageScripts failed: ' . $db->error);
        exit(1);
    }
    _migIana_out('[rename] tblScripts → tblLanguageScripts.');
} elseif ($newScriptsExists) {
    _migIana_out('[skip ] tblLanguageScripts already in place.');
    /* If both exist (an aborted previous run), the OLD one's rows are
       a subset of the NEW one's content after a re-run, so we drop
       it. Single-step idempotency. */
    if ($oldScriptsExists) {
        $db->query('DROP TABLE tblScripts');
        _migIana_out('[clean] dropped stale tblScripts (old name from aborted run).');
    }
} else {
    /* Neither exists — fresh deployment. Create the new-named table. */
    $sql = "CREATE TABLE tblLanguageScripts (
        Code        VARCHAR(4)   NOT NULL PRIMARY KEY COMMENT 'ISO 15924 four-letter code (Title Case)',
        Name        VARCHAR(150) NOT NULL,
        NativeName  VARCHAR(150) NOT NULL DEFAULT '',
        IsActive    TINYINT(1)   NOT NULL DEFAULT 1,
        CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        _migIana_out('ERROR: create tblLanguageScripts failed: ' . $db->error);
        exit(1);
    }
    _migIana_out('[add  ] tblLanguageScripts (fresh — no legacy tblScripts to rename).');
}

/* =========================================================================
 * Step 2 — Widen tblLanguages.Code from VARCHAR(10) → VARCHAR(35)
 * ========================================================================= */
$langCodeWidth = _migIana_columnWidth($db, 'tblLanguages', 'Code');
if ($langCodeWidth === null) {
    _migIana_out('ERROR: tblLanguages.Code missing — run install.php first.');
    exit(1);
}
if ($langCodeWidth >= 35) {
    _migIana_out("[skip ] tblLanguages.Code already VARCHAR({$langCodeWidth}).");
} else {
    /* The FK from tblSongTranslations.TargetLanguage uses ON UPDATE
       CASCADE so the column-width change propagates. */
    if (!$db->query("ALTER TABLE tblLanguages MODIFY COLUMN Code VARCHAR(35) NOT NULL")) {
        _migIana_out('ERROR: widening tblLanguages.Code failed: ' . $db->error);
        exit(1);
    }
    _migIana_out('[wide ] tblLanguages.Code → VARCHAR(35).');
}

/* Same widening for Name (some IANA descriptions are long, e.g.
   "South African Sign Language") and NativeName. */
$nameWidth = _migIana_columnWidth($db, 'tblLanguages', 'Name');
if ($nameWidth !== null && $nameWidth < 250) {
    if (!$db->query("ALTER TABLE tblLanguages MODIFY COLUMN Name VARCHAR(250) NOT NULL")) {
        _migIana_out('ERROR: widening tblLanguages.Name failed: ' . $db->error);
        exit(1);
    }
    _migIana_out('[wide ] tblLanguages.Name → VARCHAR(250).');
}
$nativeWidth = _migIana_columnWidth($db, 'tblLanguages', 'NativeName');
if ($nativeWidth !== null && $nativeWidth < 250) {
    if (!$db->query("ALTER TABLE tblLanguages MODIFY COLUMN NativeName VARCHAR(250) NOT NULL DEFAULT ''")) {
        _migIana_out('ERROR: widening tblLanguages.NativeName failed: ' . $db->error);
        exit(1);
    }
    _migIana_out('[wide ] tblLanguages.NativeName → VARCHAR(250).');
}

/* =========================================================================
 * Step 3 — Add tblLanguages.Scope column
 * ========================================================================= */
if (_migIana_columnExists($db, 'tblLanguages', 'Scope')) {
    _migIana_out('[skip ] tblLanguages.Scope already present.');
} else {
    $sql = "ALTER TABLE tblLanguages
            ADD COLUMN Scope ENUM('individual','macrolanguage','collection','private-use','special') NOT NULL DEFAULT 'individual'
            COMMENT 'IANA registry Scope: macrolanguage codes (zh, ar, fa) outrank their narrower variants in the picker'
            AFTER TextDirection";
    if (!$db->query($sql)) {
        _migIana_out('ERROR: adding tblLanguages.Scope failed: ' . $db->error);
        exit(1);
    }
    _migIana_out('[add  ] tblLanguages.Scope.');
}

/* =========================================================================
 * Step 4 — Create tblLanguageVariants
 * ========================================================================= */
if (_migIana_tableExists($db, 'tblLanguageVariants')) {
    _migIana_out('[skip ] tblLanguageVariants already present.');
} else {
    $sql = "CREATE TABLE tblLanguageVariants (
        Code        VARCHAR(8)   NOT NULL PRIMARY KEY COMMENT 'IANA variant subtag (5-8 chars, e.g. 1996, fonipa, valencia)',
        Name        VARCHAR(250) NOT NULL,
        IsActive    TINYINT(1)   NOT NULL DEFAULT 1,
        CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        _migIana_out('ERROR: create tblLanguageVariants failed: ' . $db->error);
        exit(1);
    }
    _migIana_out('[add  ] tblLanguageVariants.');
}

/* =========================================================================
 * Step 5 — Parse IANA registry, INSERT IGNORE rows
 * =========================================================================
 * Source path resolution: caller can override via the IHYMNS_IANA_REGISTRY_PATH
 * environment variable (used by the live-refresh endpoint to point at a
 * temp file). Default is the bundled snapshot. */
$ianaPath = getenv('IHYMNS_IANA_REGISTRY_PATH') ?: __DIR__ . '/data/iana-language-subtag-registry.txt';
if (!is_readable($ianaPath)) {
    _migIana_out("ERROR: IANA registry snapshot not found at {$ianaPath}.");
    exit(1);
}
$registryText = (string)file_get_contents($ianaPath);
$parsed = _migIana_parseRegistry($registryText);

_migIana_out(sprintf(
    'Parsed IANA registry: %d languages, %d scripts, %d regions, %d variants.',
    count($parsed['languages']),
    count($parsed['scripts']),
    count($parsed['regions']),
    count($parsed['variants'])
));

/* INSERT IGNORE rows. The Description from IANA is the fallback Name —
   CLDR overlays a polished version in Step 6. */

/* tblLanguages */
$stmt = $db->prepare(
    "INSERT IGNORE INTO tblLanguages (Code, Name, NativeName, TextDirection, Scope) VALUES (?, ?, '', 'ltr', ?)"
);
$langInserted = 0;
foreach ($parsed['languages'] as $row) {
    $stmt->bind_param('sss', $row['subtag'], $row['description'], $row['scope']);
    $stmt->execute();
    if ($stmt->affected_rows > 0) $langInserted++;
}
$stmt->close();
_migIana_out("[seed ] tblLanguages: {$langInserted} new row(s).");

/* Update Scope on existing rows (the original 14 seeded rows
   pre-date the Scope column; without this they'd all be the default
   'individual' and macrolanguages like 'zh' wouldn't sort first). */
$stmt = $db->prepare("UPDATE tblLanguages SET Scope = ? WHERE Code = ? AND Scope = 'individual'");
$scopeBumped = 0;
foreach ($parsed['languages'] as $row) {
    if ($row['scope'] === 'individual') continue;
    $stmt->bind_param('ss', $row['scope'], $row['subtag']);
    $stmt->execute();
    if ($stmt->affected_rows > 0) $scopeBumped++;
}
$stmt->close();
_migIana_out("[scope] tblLanguages: {$scopeBumped} row(s) flagged macrolanguage / collection / special.");

/* tblLanguageScripts */
$stmt = $db->prepare(
    "INSERT IGNORE INTO tblLanguageScripts (Code, Name, NativeName) VALUES (?, ?, '')"
);
$scriptInserted = 0;
foreach ($parsed['scripts'] as $row) {
    $stmt->bind_param('ss', $row['subtag'], $row['description']);
    $stmt->execute();
    if ($stmt->affected_rows > 0) $scriptInserted++;
}
$stmt->close();
_migIana_out("[seed ] tblLanguageScripts: {$scriptInserted} new row(s).");

/* tblRegions */
$stmt = $db->prepare("INSERT IGNORE INTO tblRegions (Code, Name) VALUES (?, ?)");
$regionInserted = 0;
foreach ($parsed['regions'] as $row) {
    $stmt->bind_param('ss', $row['subtag'], $row['description']);
    $stmt->execute();
    if ($stmt->affected_rows > 0) $regionInserted++;
}
$stmt->close();
_migIana_out("[seed ] tblRegions: {$regionInserted} new row(s).");

/* tblLanguageVariants */
$stmt = $db->prepare("INSERT IGNORE INTO tblLanguageVariants (Code, Name) VALUES (?, ?)");
$variantInserted = 0;
foreach ($parsed['variants'] as $row) {
    $stmt->bind_param('ss', $row['subtag'], $row['description']);
    $stmt->execute();
    if ($stmt->affected_rows > 0) $variantInserted++;
}
$stmt->close();
_migIana_out("[seed ] tblLanguageVariants: {$variantInserted} new row(s).");

/* =========================================================================
 * Step 6 — Overlay CLDR English display names
 *
 * IANA's `Description:` is the canonical name but often has multiple
 * forms separated by semicolons (e.g. "Spanish; Castilian"). CLDR's
 * English locale picks the polished single form ("Spanish"), which
 * is what curators expect to see in the dropdown. We OVERLAY (UPDATE)
 * rather than INSERT so the IANA seed remains the floor.
 * ========================================================================= */
$cldrDir = getenv('IHYMNS_CLDR_DIR') ?: __DIR__ . '/data';
$cldrLanguages   = _migIana_cldrInner("{$cldrDir}/cldr-en-languages.json",   'languages');
$cldrScripts     = _migIana_cldrInner("{$cldrDir}/cldr-en-scripts.json",     'scripts');
$cldrTerritories = _migIana_cldrInner("{$cldrDir}/cldr-en-territories.json", 'territories');
$cldrVariants    = _migIana_cldrInner("{$cldrDir}/cldr-en-variants.json",    'variants');

_migIana_out(sprintf(
    'Loaded CLDR English: %d languages, %d scripts, %d territories, %d variants.',
    count($cldrLanguages), count($cldrScripts), count($cldrTerritories), count($cldrVariants)
));

/* CLDR overlay helper. The four overlay loops (#746) used to share
   the same shape with subtle differences (codes lowercased for
   languages + variants, kept-as-is for scripts + regions). Sharing
   the logic via a helper means:

     - per-row try/catch logs the offending Code if a single row
       fails, instead of aborting the whole import on the first
       mysqli_sql_exception (the bug behind #746);
     - each loop emits a "starting" + "done" summary so the operator
       sees partial progress in the output panel even on failure;
     - the helper falls back to skipping (rather than throwing) when
       the prepared statement itself can't be built — cheap defence
       against a future CLDR refresh shipping a row whose payload the
       schema can't represent. */
$_cldrOverlay = static function (
    mysqli $db,
    string $table,
    array  $entries,
    bool   $lowercaseCode
): array {
    $updated = 0;
    $skippedAlt = 0;
    $errors = [];

    /* The prepare itself can throw under MYSQLI_REPORT_STRICT — wrap
       it so a failure on one table doesn't prevent the others from
       running. */
    try {
        $stmt = $db->prepare("UPDATE {$table} SET Name = ? WHERE Code = ? AND Name <> ?");
    } catch (\Throwable $e) {
        return [
            'updated' => 0, 'skipped_alt' => 0,
            'errors' => [['*', 'prepare failed: ' . $e->getMessage()]],
        ];
    }

    foreach ($entries as $code => $name) {
        /* Skip CLDR's "alt" forms (keys with -alt-* suffix). They're
           legitimate alternative display strings that we don't want
           to overlay onto the canonical Code row. */
        if (strpos((string)$code, '-alt-') !== false) {
            $skippedAlt++;
            continue;
        }
        $codeKey = $lowercaseCode ? strtolower((string)$code) : (string)$code;
        try {
            $stmt->bind_param('sss', $name, $codeKey, $name);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $updated++;
            }
        } catch (\Throwable $e) {
            /* Single-row failure — log the code and continue. The
               error list is bounded to 10 entries so a wholesale
               failure mode doesn't fill the output panel; the count
               in the summary line catches the rest. */
            if (count($errors) < 10) {
                $errors[] = [$codeKey, $e->getMessage()];
            }
        }
    }
    $stmt->close();

    return [
        'updated'     => $updated,
        'skipped_alt' => $skippedAlt,
        'errors'      => $errors,
    ];
};

$_logCldrOverlay = static function (string $table, int $totalEntries) {
    _migIana_out("[cldr ] {$table}.Name overlay — starting on {$totalEntries} CLDR row(s)…");
};
$_logCldrSummary = static function (string $table, array $stats) {
    $u = (int)$stats['updated'];
    $s = (int)$stats['skipped_alt'];
    $e = count($stats['errors']);
    $line = "[cldr ] {$table}.Name overlaid on {$u} row(s)";
    if ($s > 0) $line .= "; {$s} alt-form key(s) skipped";
    if ($e > 0) $line .= "; {$e} per-row error(s) (sample below)";
    $line .= '.';
    _migIana_out($line);
    if ($e > 0) {
        foreach ($stats['errors'] as [$code, $msg]) {
            _migIana_out("         [skip] code='{$code}' — {$msg}");
        }
    }
};

/* tblLanguages — UPDATE Name to the CLDR English form. Languages
   keys are lowercased to match the IANA-seeded primary key. */
$_logCldrOverlay('tblLanguages', count($cldrLanguages));
$_logCldrSummary('tblLanguages',
    $_cldrOverlay($db, 'tblLanguages', $cldrLanguages, true));

/* tblLanguageScripts — UPDATE Name. ISO 15924 script codes are
   Title-case (Latn, Cyrl) and we keep them as-is. */
$_logCldrOverlay('tblLanguageScripts', count($cldrScripts));
$_logCldrSummary('tblLanguageScripts',
    $_cldrOverlay($db, 'tblLanguageScripts', $cldrScripts, false));

/* tblRegions — UPDATE Name. ISO 3166-1 region codes are UPPERCASE
   (GB, US) and we keep them as-is. */
$_logCldrOverlay('tblRegions', count($cldrTerritories));
$_logCldrSummary('tblRegions',
    $_cldrOverlay($db, 'tblRegions', $cldrTerritories, false));

/* tblLanguageVariants — UPDATE Name. Variant subtags (1996,
   fonipa, valencia) are lowercase per IANA grammar. */
$_logCldrOverlay('tblLanguageVariants', count($cldrVariants));
$_logCldrSummary('tblLanguageVariants',
    $_cldrOverlay($db, 'tblLanguageVariants', $cldrVariants, true));

_migIana_out('IANA Language Subtag Registry import — finished.');
