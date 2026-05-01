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
 * always pass the filter regardless of the requested set.
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
 * Parse a comma-separated list of language hints into a
 * canonical set of lowercase primary subtags. Invalid tokens
 * are silently dropped — a curator typing `en, es, garbage` gets
 * `["en", "es"]` rather than a 400.
 *
 * @param string|null $rawCsv Comma-list (e.g. "en,es,pt").
 * @return list<string> Sorted, deduplicated, lowercase primary subtags.
 */
function parsePreferredLanguageSubtags(?string $rawCsv): array
{
    if ($rawCsv === null || trim($rawCsv) === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $rawCsv) as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        /* Take only the first component before a hyphen — `en-GB`
           collapses to `en`, `zh-Hans-CN` collapses to `zh`. */
        $primary = strtolower(explode('-', $tok, 2)[0]);
        if (preg_match('/^[a-z]{2,3}$/', $primary)) {
            $out[$primary] = true;
        }
    }
    $list = array_keys($out);
    sort($list);
    return $list;
}

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
    $placeholders = implode(',', array_fill(0, count($subtags), '?'));
    $where = " AND ("
           .   "$colExpr IS NULL OR $colExpr = '' "
           .   "OR LOWER(SUBSTRING_INDEX($colExpr, '-', 1)) IN ($placeholders)"
           . ")";
    $types = str_repeat('s', count($subtags));
    return [$where, $types, array_values($subtags)];
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
    $set = array_flip($subtags);
    return static function (array $row) use ($set): bool {
        $tag = (string)($row['language'] ?? $row['Language'] ?? '');
        if ($tag === '') return true;                   // untagged → always show
        $primary = strtolower(explode('-', $tag, 2)[0]);
        return isset($set[$primary]);
    };
}
