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
 * What the filter needs to know about a person's preferences, worked out once
 * (#2137 review — the shared policy's MATCH-040).
 *
 * ELI5: a preference such as `zh-Hans` (Chinese in Simplified characters)
 * should show Chinese songs — but not the ones written in Traditional
 * characters, because a reader of one script may not be able to read the
 * other. A preference with no script (`zh`, `zh-TW`) shows every form.
 *
 * Returns:
 *   - `any`     base languages where at least one preference names no script:
 *               every form of the language matches;
 *   - `byScript` base language → the scripts its preferences name (lower-case),
 *               for languages where EVERY preference names a script: a form
 *               with no script, or with one of these, matches; a form with
 *               another script does not;
 *   - `whole`   private-use and old "grandfathered" preferences (`x-hymnal`),
 *               which are matched as a whole tag.
 *
 * @param list<string> $preferences Canonical tags (parsePreferredLanguageSubtags()).
 * @return array{any: list<string>, byScript: array<string, list<string>>, whole: list<string>}
 */
function languageFilterPlan(array $preferences): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';
    $any = [];
    $scripts = [];
    $whole = [];
    foreach ($preferences as $pref) {
        $pref = trim((string)$pref);
        if ($pref === '') continue;
        if (mediaLanguageReady()) {
            $parsed = \Mwbm\MediaLanguage\Policy::canonicalise($pref);
            if ($parsed->kind === \Mwbm\MediaLanguage\TagKind::Malformed) continue;
            if ($parsed->kind !== \Mwbm\MediaLanguage\TagKind::Ordinary || $parsed->language === null) {
                $whole[strtolower($parsed->tag)] = true;
                continue;
            }
            $lang = strtolower($parsed->language);
            $script = $parsed->script !== null ? strtolower($parsed->script) : '';
        } else {
            /* Degraded path (shared rules missing): read the parts directly. */
            $parts = explode('-', strtolower($pref));
            $lang = $parts[0];
            if (!preg_match('/^[a-z]{2,3}$/', $lang)) continue;
            $script = (isset($parts[1]) && preg_match('/^[a-z]{4}$/', $parts[1])) ? $parts[1] : '';
        }
        if ($script === '') {
            $any[$lang] = true;
        } else {
            $scripts[$lang][$script] = true;
        }
    }
    $scriptsOut = [];
    foreach ($scripts as $lang => $set) {
        if (!isset($any[$lang])) {
            $scriptsOut[$lang] = array_keys($set);
        }
    }
    return ['any' => array_keys($any), 'byScript' => $scriptsOut, 'whole' => array_keys($whole)];
}

/**
 * The preferences the filter can use: every one that is not malformed.
 *
 * Policy MATCH-010 and AUTO-010 (settled in core revision 4, aaaa585): a
 * malformed preference matches nothing — not even an identical malformed
 * value — and a person whose preferences are ALL malformed counts as having
 * none, so no filter applies to them. The request path already drops
 * malformed values (parsePreferredLanguageSubtags() keeps only what the
 * shared storage rule accepts); this keeps the two filter builders honest for
 * any other caller, which before this change hid every song from a list
 * holding only malformed values.
 *
 * @param list<string> $preferences
 * @return list<string>
 */
function languageFilterUsablePreferences(array $preferences): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';
    $out = [];
    foreach ($preferences as $pref) {
        $pref = trim((string)$pref);
        if ($pref === '') continue;
        if (mediaLanguageReady()) {
            if (\Mwbm\MediaLanguage\Policy::canonicalise($pref)->isMalformed()) continue;
        } elseif (!preg_match('/^(?:[a-z]{2,3}|x|i)(?:-|$)/i', $pref)) {
            continue;   /* degraded path (shared rules missing): the plain shapes only */
        }
        $out[] = $pref;
    }
    return $out;
}

/**
 * The script subtag of a stored tag, lower-case, or '' when it names none.
 * Language, up to three extlangs, then a four-letter script (BCP 47 §2.1).
 */
function languageFilterScriptOf(string $tag): string
{
    return preg_match('/^[a-z]{2,8}(?:-[a-z]{3}){0,3}-([a-z]{4})(?:-|$)/i', trim($tag), $m) === 1 ? strtolower($m[1]) : '';
}

/**
 * Build a SQL WHERE-clause fragment + bind-param pair to apply
 * the language filter at SELECT time.
 *
 * The fragment looks like:
 *   AND (
 *       <colExpr> IS NULL OR <colExpr> = ''
 *    OR LOWER(SUBSTRING_INDEX(<colExpr>, '-', 1)) IN (?, ?, …)          -- any form of these
 *    OR LOWER(<colExpr>) IN (?, …)                                     -- whole-tag preferences
 *    OR (LOWER(SUBSTRING_INDEX(<colExpr>, '-', 1)) = ?                 -- one per script-limited language:
 *        AND (LOWER(<colExpr>) NOT REGEXP ? OR LOWER(<colExpr>) REGEXP ?))  -- no script, or an allowed one
 *   )
 *
 * Matching is by language (#2137 — a `pt-BR` preference matches `pt`,
 * `pt-BR` and `pt-PT` rows), except that a preference naming a SCRIPT drops
 * rows written in a different script (#2137 review, MATCH-040: `zh-Hans`
 * keeps `zh` and `zh-Hans-CN` but not `zh-Hant`; `sr-Latn` does not keep
 * `sr-Cyrl`). `und`, `mul` and `zxx` always pass (#2132), and so do
 * untagged rows. Every value is bound; the two REGEXP patterns are built only
 * from four-letter scripts the shared rule has already validated.
 *
 * What SQL cannot do: it compares the stored text, so a row stored under a
 * retired code (`iw`) does not match a `he` preference here, although the
 * in-memory filter below does. Empty preferences return `[" AND 1=1", '', []]`
 * so callers can concatenate without checking.
 *
 * @param string       $colExpr SQL column expression (e.g. `s.Language`,
 *                              `Language`, or a coalesce expression).
 * @param list<string> $subtags From resolvePreferredLanguagesForRequest().
 * @return array{0:string,1:string,2:list<string>} [whereSql, paramTypes, paramValues]
 */
function applyLanguageFilterSql(string $colExpr, array $subtags): array
{
    $subtags = languageFilterUsablePreferences($subtags);   /* all malformed = none (AUTO-010) */
    if (empty($subtags)) {
        return [' AND 1=1', '', []];
    }
    $plan   = languageFilterPlan($subtags);
    $any    = array_values(array_unique(array_merge($plan['any'], IHYMNS_LANGUAGE_FILTER_ALWAYS_SHOWN)));
    $values = $any;
    $where  = " AND ("
            .   "$colExpr IS NULL OR $colExpr = '' "
            .   "OR LOWER(SUBSTRING_INDEX($colExpr, '-', 1)) IN (" . implode(',', array_fill(0, count($any), '?')) . ")";
    if ($plan['whole'] !== []) {
        $where .= " OR LOWER($colExpr) IN (" . implode(',', array_fill(0, count($plan['whole']), '?')) . ")";
        $values = array_merge($values, $plan['whole']);
    }
    foreach ($plan['byScript'] as $lang => $scripts) {
        $where .= " OR (LOWER(SUBSTRING_INDEX($colExpr, '-', 1)) = ?"
               .  " AND (LOWER($colExpr) NOT REGEXP ? OR LOWER($colExpr) REGEXP ?))";
        $values[] = (string)$lang;
        $values[] = '^[a-z]{2,8}(-[a-z]{3}){0,3}-[a-z]{4}(-|$)';
        $values[] = '^[a-z]{2,8}(-[a-z]{3}){0,3}-(' . implode('|', $scripts) . ')(-|$)';
    }
    $where .= ")";
    return [$where, str_repeat('s', count($values)), $values];
}

/**
 * Convenience: return the filter for in-memory (PHP-array) row
 * filtering, used by code paths that don't want to push the
 * filter into SQL (e.g. the songbooks list which is small + cached).
 *
 * #2137 review — the same rule as applyLanguageFilterSql(), decided by the
 * shared Policy::matchTags() when the shared rules are installed: a row is
 * kept when it matches some preference at "related" or better (same
 * language, no conflicting script — MATCH-010 to MATCH-040). `und`, `mul`,
 * `zxx` and untagged rows always pass (#2132).
 *
 * @param list<string> $subtags From resolvePreferredLanguagesForRequest().
 * @return callable(array): bool Predicate; true → keep the row.
 */
function makeLanguageFilterPredicate(array $subtags): callable
{
    $subtags = languageFilterUsablePreferences($subtags);   /* all malformed = none (AUTO-010) */
    if (empty($subtags)) {
        return static fn(array $_row): bool => true;
    }
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';
    $always = array_flip(IHYMNS_LANGUAGE_FILTER_ALWAYS_SHOWN);
    $prefs  = array_values($subtags);
    $plan   = languageFilterPlan($prefs);
    return static function (array $row) use ($always, $prefs, $plan): bool {
        $tag = trim((string)($row['language'] ?? $row['Language'] ?? ''));
        if ($tag === '') return true;                   // untagged → always show
        if (isset($always[strtolower(explode('-', $tag, 2)[0])])) return true;
        if (mediaLanguageReady()) {
            $related = \Mwbm\MediaLanguage\MatchLevel::Related->rank();
            foreach ($prefs as $pref) {
                if (\Mwbm\MediaLanguage\Policy::matchTags((string)$pref, $tag)->level->rank() <= $related) {
                    return true;
                }
            }
            return false;
        }
        /* Degraded path: the plan, read directly (same rule as the SQL). */
        $lower   = strtolower($tag);
        $primary = explode('-', $lower, 2)[0];
        if (in_array($primary, $plan['any'], true) || in_array($lower, $plan['whole'], true)) return true;
        if (isset($plan['byScript'][$primary])) {
            $script = languageFilterScriptOf($lower);
            return $script === '' || in_array($script, $plan['byScript'][$primary], true);
        }
        return false;
    };
}
