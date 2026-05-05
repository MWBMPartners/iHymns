<?php

declare(strict_types=1);

/**
 * iHymns — Language-Name Resolver (#856)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Maps an IETF BCP 47 language tag (or its primary subtag) to a
 * human-readable display name from `tblLanguages`, so every PHP
 * template that renders a language pill / badge can attach a
 * tooltip showing the full name without each one re-querying the
 * database.
 *
 * Backed by tblLanguages (#738 — IANA registry import + #ietf
 * CLDR overlay). Pre-migration deployments fall back to the
 * uppercase code unchanged so old installs render with no errors.
 *
 * DESIGN NOTES:
 * - Static-cached per request: one SELECT, ~250 rows, indexed by
 *   lowercase Code in PHP memory. A page rendering 50 badges is
 *   one DB query.
 * - Look-ups try the full tag first (e.g. 'pt-BR' → "Portuguese
 *   (Brazil)"); if the full tag isn't seeded, fall back to the
 *   primary subtag ('pt' → "Portuguese"). Same fallback used by
 *   the IETF picker module for compose / decompose.
 * - Schema-probed: a deploy without `tblLanguages` returns the
 *   uppercase code identity (so a tooltip just says "EN" / "AF"
 *   rather than 500-ing the page).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/**
 * Resolve a language code or BCP 47 tag to a display name.
 * Returns the original code uppercased on lookup miss / pre-migration.
 *
 * @param string $code Language code or tag (e.g. 'en', 'pt-BR', 'AF').
 * @return string Display name (e.g. 'English', 'Afrikaans') or the
 *                normalized code on miss.
 */
function resolveLanguageName(string $code): string
{
    $code = trim($code);
    if ($code === '') return '';
    $key = strtolower($code);
    $map = getLanguageNamesMap();

    if (isset($map[$key])) return $map[$key];

    /* Try the primary subtag — pt-BR not seeded? Try pt. */
    if (str_contains($key, '-')) {
        $primary = explode('-', $key, 2)[0];
        if (isset($map[$primary])) return $map[$primary];
    }

    /* No match — return the code uppercased so the tooltip degrades
       gracefully ("EN" reads better than empty). */
    return strtoupper($code);
}

/**
 * Return the full code → name map, statically cached for the
 * request. Best-effort: probe tblLanguages first; if absent
 * (pre-#738 deploy), return an empty map and let resolveLanguageName
 * fall back to identity.
 *
 * @return array<string, string> Lowercase code → English Name.
 */
function getLanguageNamesMap(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $db = getDbMysqli();
        if (!$db) {
            $cached = [];
            return $cached;
        }

        /* Schema-probe so a pre-migration deploy doesn't spam the
           error log with "table not found" for every page render. */
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblLanguages' LIMIT 1"
        );
        $probe->execute();
        $hasSchema = $probe->get_result()->fetch_row() !== null;
        $probe->close();
        if (!$hasSchema) {
            $cached = [];
            return $cached;
        }

        $res = $db->query(
            'SELECT Code, Name FROM tblLanguages
              WHERE COALESCE(IsActive, 1) = 1'
        );
        $out = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $code = strtolower((string)$row['Code']);
                $name = (string)$row['Name'];
                if ($code !== '' && $name !== '') {
                    $out[$code] = $name;
                }
            }
            $res->close();
        }
        $cached = $out;
        return $cached;
    } catch (\Throwable $e) {
        error_log('[language_names] ' . $e->getMessage());
        $cached = [];
        return $cached;
    }
}
