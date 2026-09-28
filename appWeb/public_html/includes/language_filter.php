<?php

declare(strict_types=1);

/**
 * iHymns — Server-side language-filter helper (#736)
 *
 * Single source of truth for the "show only songbooks/songs in
 * these primary subtags + always-show untagged" rule, applied
 * server-side at SELECT time so:
 *
 *   1. Native clients (Apple, Android, FireOS) get the same
 *      filter behaviour as the SPA without re-implementing it.
 *   2. Excluded rows aren't shipped over the wire just to be
 *      hidden client-side — bandwidth + page-render savings on
 *      large catalogues.
 *   3. Pagination counts (limit / offset / total) are correct
 *      relative to the visible set rather than the underlying
 *      one.
 *
 * The filter operates on the PRIMARY subtag (the first 2-3
 * letters of the BCP 47 tag) so picking "en" matches `en`,
 * `en-GB`, `en-US`. Untagged rows (Language IS NULL OR '')
 * always pass the filter regardless of the requested set, and so
 * (#2132) do rows whose language is `und` (not known), `mul` (several
 * languages) or `zxx` (no language) — see
 * IHYMNS_LANGUAGE_FILTER_ALWAYS_SHOWN.
 *
 * #2137 — the preference LIST keeps each person's whole tags, in the order
 * they chose them (the shared language policy, MWBM-MEDIA-LANG UI-020: "the
 * language groups of those preferences come first, in the user's priority
 * order"). It used to be cut to base codes and sorted A-Z, which lost both the
 * region/script and the priority. Only the MATCHING works by group: a `pt-BR`
 * preference still shows `pt` and `pt-PT` songs. Lists of bare base codes
 * saved before this change stay valid exactly as they are.
 *
 * Resolution order for an incoming request:
 *
 *   1. Explicit `?lang=en,es,pt` query param — highest priority,
 *      so the SPA can override the user's saved preference per-
 *      request (e.g. for the Search page's "show all languages"
 *      escape hatch).
 *   2. `X-Preferred-Languages: en,es,pt` request header — the
 *      SPA sends this from localStorage on every fetch so
 *      anonymous users get persistent filtering across page
 *      navigations.
 *   3. `tblUsers.PreferredLanguagesJson` — for an authenticated
 *      user, fall back to the saved-on-account list.
 *   4. Empty set — no filter applied; show every language.
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Parse a comma-separated list of preferred languages into canonical tags,
 * keeping the order given (#2137). Invalid tokens are silently dropped — a
 * curator typing `en, es, garbage` gets `["en", "es"]` rather than a 400.
 *
 * ELI5: `"PT-br, en, pt-BR"` → `["pt-BR", "en"]`: tidied, first-come order
 * kept, repeats removed. It used to return `["en", "pt"]` — cut to the base
 * language and sorted, losing both the region and the person's priority.
 *
 * The name keeps its historical "Subtags" wording because callers already use
 * it; the values are now whole tags. The OLD shape (sorted base codes) is still
 * available from preferredLanguageBaseSubtags(), for the API field that has
 * always returned it.
 *
 * @param string|null $rawCsv Comma-list (e.g. "pt-BR,en").
 * @return list<string> Canonical tags, highest priority first, no repeats.
 */
function parsePreferredLanguageSubtags(?string $rawCsv): array
{
    if ($rawCsv === null || trim($rawCsv) === '') {
        return [];
    }
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';
    $out = [];
    foreach (explode(',', $rawCsv) as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        if (!mediaLanguageReady()) {
            /* Degraded path (shared rules missing on this server): the old
               behaviour — base code only — so a filter still works. */
            $primary = strtolower(explode('-', $tok, 2)[0]);
            if (preg_match('/^[a-z]{2,3}$/', $primary)) {
                $out[$primary] = true;
            }
            continue;
        }
        $tag = mediaLanguageTagForStorage($tok);
        if (is_string($tag)) {
            $out[$tag] = true;   /* first occurrence wins, so the order is kept */
        }
    }
    return array_keys($out);
}

/**
 * The distinct language GROUPS a preference list covers, for MATCHING
 * (#2137): `["pt-BR", "en", "pt-PT"]` → `["pt", "en"]`, in the list's own
 * order. A private-use or old "grandfathered" preference is its own group, the
 * whole tag (see mediaLanguageGroup()).
 *
 * @param list<string> $preferences
 * @return list<string>
 */
function languageFilterGroups(array $preferences): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';
    $groups = [];
    foreach ($preferences as $tag) {
        $g = mediaLanguageGroup((string)$tag);
        if ($g !== '') {
            $groups[$g] = true;
        }
    }
    return array_keys($groups);
}

/**
 * The OLD shape of a preference list — sorted, distinct base codes
 * (`["pt-BR", "en"]` → `["en", "pt"]`) — for the `subtags` field of the
 * user_preferred_languages API actions, which has always returned exactly that
 * and must keep doing so for anything already reading it (#2137). The whole
 * tags in priority order are returned alongside, in the new `languages` field.
 *
 * @param list<string> $preferences
 * @return list<string>
 */
function preferredLanguageBaseSubtags(array $preferences): array
{
    $bases = array_values(array_filter(
        languageFilterGroups($preferences),
        static fn(string $g): bool => preg_match('/^[a-z]{2,3}$/', $g) === 1
    ));
    sort($bases);
    return $bases;
}

/**
 * Language groups that are never filtered out (#2132): a song whose language
 * is not known (`und`), is in several languages (`mul`) or has no language at
 * all (`zxx`, an instrumental) could be exactly what a reader of ANY language
 * wants, so hiding it behind a language filter would be a guess. They pass the
 * filter the same way an untagged song always has. Mirrored in the browser by
 * js/modules/songbook-language-filter.js (ALWAYS_SHOWN_GROUPS).
 */
const IHYMNS_LANGUAGE_FILTER_ALWAYS_SHOWN = ['und', 'mul', 'zxx'];

/**
 * Resolve the active set of preferred-language subtags for the
 * current request. Walks the four-level priority chain (see
 * file docblock). Returns `[]` when no filter should apply.
 *
 * @param array|null $authUser The authenticated user array (from
 *                             getAuthenticatedUser()), or null
 *                             for anonymous requests.
 * @return list<string> Subtag list to filter by, or `[]` for no filter.
 */
function resolvePreferredLanguagesForRequest(?array $authUser): array
{
    /* 1. Explicit ?lang= query param wins. Empty string means
       "show all" — the curator has actively cleared their saved
       preference for this request. We distinguish the two by
       isset() rather than a falsey check on the string value. */
    if (isset($_GET['lang'])) {
        return parsePreferredLanguageSubtags((string)$_GET['lang']);
    }

    /* 2. X-Preferred-Languages request header — the SPA sends this
       from localStorage on every fetch so anonymous users get
       per-device persistence without per-request URL parameters. */
    $hdr = $_SERVER['HTTP_X_PREFERRED_LANGUAGES'] ?? '';
    if ($hdr !== '') {
        return parsePreferredLanguageSubtags($hdr);
    }

    /* 3. Authenticated user's saved preference. Probe the column
       so a pre-#736 deployment doesn't 500 on the SELECT. */
    if ($authUser && !empty($authUser['Id'])) {
        try {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
            $db = getDbMysqli();
            $probe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblUsers'
                    AND COLUMN_NAME  = 'PreferredLanguagesJson' LIMIT 1"
            );
            $probe->execute();
            $hasCol = $probe->get_result()->fetch_row() !== null;
            $probe->close();
            if ($hasCol) {
                $stmt = $db->prepare(
                    'SELECT PreferredLanguagesJson FROM tblUsers WHERE Id = ?'
                );
                $userId = (int)$authUser['Id'];
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $raw = $row[0] ?? null;
                if ($raw) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        return parsePreferredLanguageSubtags(
                            implode(',', array_map('strval', $decoded))
                        );
                    }
                }
            }
        } catch (\Throwable $_e) {
            /* Best-effort — DB read failure falls through to
               "no filter" rather than 500ing the request. */
        }
    }

    return [];
}

/**
 * Build a SQL WHERE-clause fragment + bind-param pair to apply
 * the language filter at SELECT time.
 *
 * The fragment looks like:
 *   AND (
 *       <colExpr> IS NULL OR <colExpr> = ''
 *    OR LOWER(SUBSTRING_INDEX(<colExpr>, '-', 1)) IN (?, ?, …)
 *   )
 *
 * The IN list is the language GROUPS the preferences cover (#2137 — a
 * `pt-BR` preference matches `pt`, `pt-BR` and `pt-PT` rows) plus the
 * always-shown groups `und`, `mul`, `zxx` (#2132), all bound. A private-use or
 * old "grandfathered" preference (`x-hymnal`, `i-default`) is matched as a
 * whole tag in a second bound list, because its first part is not a language.
 * Untagged rows (NULL / empty) always pass — matches the spec.
 * Empty subtag list returns `[" AND 1=1", '', []]` so callers can
 * blindly concatenate without checking emptiness.
 *
 * @param string       $colExpr SQL column expression (e.g. `s.Language`,
 *                              `Language`, or a coalesce expression).
 * @param list<string> $subtags From resolvePreferredLanguagesForRequest().
 * @return array{0:string,1:string,2:list<string>} [whereSql, paramTypes, paramValues]
 */
function applyLanguageFilterSql(string $colExpr, array $subtags): array
{
    if (empty($subtags)) {
        return [' AND 1=1', '', []];
    }
    $firstParts = [];
    $wholeTags  = [];
    foreach (languageFilterGroups($subtags) as $g) {
        if (str_contains($g, '-')) {
            $wholeTags[] = $g;
        } else {
            $firstParts[] = $g;
        }
    }
    $firstParts = array_values(array_unique(array_merge($firstParts, IHYMNS_LANGUAGE_FILTER_ALWAYS_SHOWN)));
    $where = " AND ("
           .   "$colExpr IS NULL OR $colExpr = '' "
           .   "OR LOWER(SUBSTRING_INDEX($colExpr, '-', 1)) IN (" . implode(',', array_fill(0, count($firstParts), '?')) . ")";
    if ($wholeTags !== []) {
        $where .= " OR LOWER($colExpr) IN (" . implode(',', array_fill(0, count($wholeTags), '?')) . ")";
    }
    $where .= ")";
    $values = array_merge($firstParts, $wholeTags);
    return [$where, str_repeat('s', count($values)), $values];
}

/**
 * Convenience: return the filter for in-memory (PHP-array) row
 * filtering, used by code paths that don't want to push the
 * filter into SQL (e.g. the songbooks list which is small + cached).
 *
 * @param list<string> $subtags From resolvePreferredLanguagesForRequest().
 * @return callable(array): bool Predicate; true → keep the row.
 */
function makeLanguageFilterPredicate(array $subtags): callable
{
    if (empty($subtags)) {
        return static fn(array $_row): bool => true;
    }
    /* Same rule as applyLanguageFilterSql(): match by language group
       (#2137), and let und / mul / zxx through (#2132). */
    $set = array_flip(array_merge(languageFilterGroups($subtags), IHYMNS_LANGUAGE_FILTER_ALWAYS_SHOWN));
    return static function (array $row) use ($set): bool {
        $tag = trim((string)($row['language'] ?? $row['Language'] ?? ''));
        if ($tag === '') return true;                   // untagged → always show
        $primary = strtolower(explode('-', $tag, 2)[0]);
        return isset($set[$primary]) || isset($set[strtolower($tag)]);
    };
}
