<?php

declare(strict_types=1);

/**
 * iHymns — Language-Name Resolver (#856)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Songs and lyric lines are tagged with short language codes like `en` or
 * `pt-BR` (the IETF's own standard for naming languages, "BCP 47") — good
 * for a computer to compare, useless for a person to read on a badge. This
 * file is the ONE place that turns a code like `pt-BR` into the words
 * "Portuguese (Brazil)" a reader actually understands, and the same file
 * also powers the little "type to search for a language/script/region"
 * boxes in admin forms (BCP 47 covers more than just languages — scripts
 * like "Cyrillic", regions like "Brazil", and variants too). Everything
 * degrades gracefully: an install that hasn't run the language-table
 * migration yet just shows the raw code in capitals instead of crashing.
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
 * ELI5: "what is 'pt-BR' called, in English?" → "Portuguese (Brazil)".
 * Don't know it? Just show the code back in capitals rather than nothing.
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
 * Resolve a language code/tag to its full display metadata — English
 * name, native endonym and text direction — for the language-picker UI
 * (#1149). Same full-tag → primary-subtag fallback as
 * resolveLanguageName(); on a complete miss / pre-migration deploy the
 * name degrades to the uppercased code, native to '' and dir to 'ltr'.
 *
 * @param string $code Language code or tag (e.g. 'en', 'pt-BR', 'AF').
 * @return array{name:string,nativeName:string,dir:string}
 */
function resolveLanguageMeta(string $code): array
{
    $code = trim($code);
    $fallback = [
        'name'       => $code === '' ? '' : strtoupper($code),
        'nativeName' => '',
        'dir'        => 'ltr',
    ];
    if ($code === '') return $fallback;

    $key = strtolower($code);
    $map = getLanguageMetaMap();

    if (isset($map[$key])) return $map[$key];

    if (str_contains($key, '-')) {
        $primary = explode('-', $key, 2)[0];
        if (isset($map[$primary])) return $map[$primary];
    }

    return $fallback;
}

/**
 * Return the full code → name map, statically cached for the
 * request. A back-compat projection of getLanguageMetaMap() — every
 * existing caller that only wants the English name keeps working.
 *
 * @return array<string, string> Lowercase code → English Name.
 */
function getLanguageNamesMap(): array
{
    $out = [];
    foreach (getLanguageMetaMap() as $code => $meta) {
        $out[$code] = $meta['name'];
    }
    return $out;
}

/**
 * Return the full code → metadata map (name + native endonym + text
 * direction), statically cached for the request. Best-effort: probe
 * tblLanguages first; if absent (pre-#738 deploy) or the read fails,
 * return an empty map and let the resolvers fall back to identity.
 *
 * NativeName + TextDirection were added in the same #738 migration that
 * created tblLanguages, so a table-existence probe is sufficient — if
 * the table exists, the columns exist.
 *
 * @return array<string, array{name:string,nativeName:string,dir:string}>
 */
/**
 * BCP 47 registry plan §4.3 — the ONE search vocabulary registry the four
 * subtag search actions (`language_search` / `script_search` /
 * `region_search` / `variant_search`, all served from `api.php`, plus the
 * legacy `/manage/songbooks?action=script_search|region_search` aliases —
 * see `bcp47SubtagSearch()` below) read from. Identifiers only, from PHP
 * source — never user input (rule #5) — which is what lets
 * `bcp47SubtagSearch()` safely interpolate a TABLE NAME into a SQL string
 * (every VALUE is still bound).
 *
 * `tables` is an ORDERED list because `script` has two legitimate names on
 * a live DB: `tblLanguageScripts` (post-#738, the renamed table) or the
 * legacy `tblScripts` on a deployment still mid-migration (rename
 * pending) — the FIRST one that exists wins, mirroring
 * `action=scripts`'/`script_search`'s existing dual-probe exactly.
 */
const IHYMNS_BCP47_SUBTAG_KINDS = [
    'language' => ['tables' => ['tblLanguages'],        'hasNative' => true,  'hasScope' => true],
    'script'   => ['tables' => ['tblLanguageScripts', 'tblScripts'], 'hasNative' => true, 'hasScope' => false],
    'region'   => ['tables' => ['tblRegions'],          'hasNative' => false, 'hasScope' => false],
    'variant'  => ['tables' => ['tblLanguageVariants'], 'hasNative' => false, 'hasScope' => false],
];

/**
 * Resolve which of a subtag kind's candidate table names actually exists on
 * this DB, or '' if none do (pre-#738 / mid-migration). Shared (rule #22)
 * by `bcp47SubtagSearch()` below AND `includes/language_tag_audit.php`'s
 * unknown-tag classifier (BCP 47 registry plan §5.2) — both need "which
 * live table backs this kind" and neither should re-probe
 * INFORMATION_SCHEMA with its own copy of the dual-table-name fallback
 * (`tblLanguageScripts` vs the legacy `tblScripts` — see
 * IHYMNS_BCP47_SUBTAG_KINDS's own doc-comment for why `script` alone has
 * two candidates).
 *
 * @param \mysqli $db
 * @param string  $kind One of IHYMNS_BCP47_SUBTAG_KINDS's keys.
 * @return string The first candidate table that exists, or '' if none do.
 */
function bcp47ResolveTable(\mysqli $db, string $kind): string
{
    if (!isset(IHYMNS_BCP47_SUBTAG_KINDS[$kind])) {
        return '';
    }
    try {
        foreach (IHYMNS_BCP47_SUBTAG_KINDS[$kind]['tables'] as $candidate) {
            $probe = $db->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $probe->bind_param('s', $candidate);
            $probe->execute();
            $found = $probe->get_result()->fetch_row() !== null;
            $probe->close();
            if ($found) {
                return $candidate;
            }
        }
    } catch (\Throwable $e) {
        error_log("[bcp47ResolveTable:$kind] table probe failed: " . $e->getMessage());
    }
    return '';
}

/**
 * The ONE live-search core behind every BCP 47 subtag typeahead (#681 /
 * #738 / BCP 47 registry plan §4.3 — rule #22, never re-forked per
 * subtag). Mirrors `action=languages`'s existing macrolanguage-first /
 * short-name-first ranking for the `language` kind, and the pre-existing
 * `/manage/songbooks?action=script_search|region_search` substring-LIKE
 * shape for every kind — this function is what those two admin-only
 * endpoints now delegate to (see `manage/songbooks.php`), and what the
 * new public `?action=language_search|script_search|region_search|
 * variant_search` actions in `api.php` call directly.
 *
 * Query shape (identical across kinds, differing only in which columns
 * exist): substring LIKE on Name [+ NativeName when present] + Code, so a
 * curator can search either the friendly name ("Spanish") or the raw code
 * ("es"); ordered so an EXACT code match wins outright, then a NAME-PREFIX
 * match ("English" before "Middle English"), then (language only)
 * macrolanguages before individual/collection/private-use/special, then
 * shortest name, then alphabetic. An un-migrated table degrades to an
 * empty suggestion list + a `note`, matching every sibling schema-probed
 * endpoint in this codebase (never a 500).
 *
 * ELI5: the engine behind every "type a few letters, pick from a
 * dropdown" language/script/region/variant box in this app. Whatever the
 * curator types, it's matched against both the code ("es") and the
 * friendly name ("Spanish"), with the closest / most obvious match
 * listed first.
 *
 * @param \mysqli $db
 * @param string  $kind   One of IHYMNS_BCP47_SUBTAG_KINDS's keys.
 * @param string  $q      Raw typed text — trimmed here; empty => empty result.
 * @param int     $limit  Clamped to [1, 50].
 * @return array{suggestions:list<array<string,string>>,note?:string}
 */
function bcp47SubtagSearch(\mysqli $db, string $kind, string $q, int $limit): array
{
    if (!isset(IHYMNS_BCP47_SUBTAG_KINDS[$kind])) {
        return ['suggestions' => []];
    }
    $spec  = IHYMNS_BCP47_SUBTAG_KINDS[$kind];
    $q     = trim($q);
    $limit = max(1, min(50, $limit));
    if ($q === '') {
        return ['suggestions' => []];
    }

    $table = bcp47ResolveTable($db, $kind);
    if ($table === '') {
        $expected = $spec['tables'][0];
        return ['suggestions' => [], 'note' => "{$expected} not yet created — run /manage/setup-database"];
    }

    /* Scope column is optional even once tblLanguages exists (#738 adds
       it in the same migration, but an older row-state during a partial
       apply could theoretically lack it — the SAME belt-and-braces probe
       action=languages already performs). */
    $hasScope = false;
    if ($spec['hasScope']) {
        try {
            $probe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'Scope' LIMIT 1"
            );
            $probe->bind_param('s', $table);
            $probe->execute();
            $hasScope = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) { /* probe failed → no Scope */ }
    }

    /* Column list + LIKE clause — NativeName only when the kind has one. */
    $nativeCol   = $spec['hasNative'] ? ', NativeName AS nativeName' : '';
    $nativeLike  = $spec['hasNative'] ? ' OR NativeName LIKE ?' : '';
    $scopeCol    = $hasScope ? ', Scope AS scope' : '';
    $scopeOrder  = $hasScope ? " (Scope = 'macrolanguage') DESC," : '';

    /* Identifiers ({$table}, the column fragments above) are ALL sourced
       from the allow-listed IHYMNS_BCP47_SUBTAG_KINDS map / the
       INFORMATION_SCHEMA probe above — never user input (rule #5). Every
       VALUE ($like / $qLower / $limit) is bound. */
    $sql = "SELECT Code AS code, Name AS name{$nativeCol}{$scopeCol}
              FROM {$table}
             WHERE IsActive = 1 AND (Name LIKE ? OR Code LIKE ?{$nativeLike})
             ORDER BY (LOWER(Code) = ?) DESC,
                      (Name LIKE ?) DESC,
                      {$scopeOrder}
                      CHAR_LENGTH(Name) ASC, Name ASC
             LIMIT ?";
    $stmt = $db->prepare($sql);

    $like      = '%' . $q . '%';
    $qLower    = mb_strtolower($q);
    $namePrefix = $q . '%';
    if ($spec['hasNative']) {
        $stmt->bind_param('sssssi', $like, $like, $like, $qLower, $namePrefix, $limit);
    } else {
        $stmt->bind_param('ssssi', $like, $like, $qLower, $namePrefix, $limit);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $suggestions = [];
    while ($row = $res->fetch_assoc()) {
        $entry = [
            'code' => (string)$row['code'],
            'name' => (string)$row['name'],
        ];
        if ($spec['hasNative']) {
            $entry['nativeName'] = (string)($row['nativeName'] ?? '');
        }
        if ($hasScope) {
            $entry['scope'] = (string)($row['scope'] ?? '');
        }
        $suggestions[] = $entry;
    }
    $stmt->close();
    return ['suggestions' => $suggestions];
}

function getLanguageMetaMap(): array
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
            'SELECT Code, Name, NativeName, TextDirection FROM tblLanguages
              WHERE COALESCE(IsActive, 1) = 1'
        );
        $out = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $code = strtolower((string)$row['Code']);
                $name = (string)$row['Name'];
                if ($code === '' || $name === '') {
                    continue;
                }
                $dir = strtolower(trim((string)($row['TextDirection'] ?? 'ltr')));
                $out[$code] = [
                    'name'       => $name,
                    'nativeName' => (string)($row['NativeName'] ?? ''),
                    'dir'        => $dir === 'rtl' ? 'rtl' : 'ltr',
                ];
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
