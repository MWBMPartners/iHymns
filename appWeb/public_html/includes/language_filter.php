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
 * The most preferences the filter uses: the first 32, in the person's order
 * (#2137 review round 4). A list arrives from a request header, a query
 * string or a saved account setting, and every preference adds to the SQL
 * the filter runs; 2,000 entries built a query of about 270 KB with 6,000
 * bound values that took several seconds on a 20,000-row table. No real
 * reader ranks more than a handful of languages, so the rest are ignored —
 * the first 32 are kept exactly, in order. Applied where a list is parsed
 * (parsePreferredLanguageSubtags()) AND where the filter builders take it
 * (languageFilterUsablePreferences()), so a caller that skips the parser
 * is bounded too. It also bounds what the account setting stores: saving a
 * longer list saves its first 32 (the save's answer shows what was kept).
 */
const IHYMNS_LANGUAGE_FILTER_MAX_PREFERENCES = 32;

/**
 * Parse a comma-separated list of preferred languages into canonical tags,
 * keeping the order given (#2137). Invalid tokens are silently dropped — a
 * curator typing `en, es, garbage` gets `["en", "es"]` rather than a 400.
 * At most IHYMNS_LANGUAGE_FILTER_MAX_PREFERENCES tags are returned: the
 * first ones, in order (#2137 review round 4); parsing stops there, so a
 * 2,000-entry header costs no more than a 32-entry one.
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
        if (count($out) >= IHYMNS_LANGUAGE_FILTER_MAX_PREFERENCES) {
            break;   /* the first 32 are kept; the rest are ignored */
        }
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
            if ($parsed->kind !== \Mwbm\MediaLanguage\TagKind::Ordinary || $parsed->language === null
                || in_array($parsed->language, ['und', 'mul', 'mis', 'zxx'], true)) {
                /* Private-use and grandfathered tags, and (#2137 second review)
                   the special codes und / mul / mis / zxx: exact matches only —
                   two "not known" values need not be the same language
                   (MATCH-040). */
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
 * Also keeps only the first IHYMNS_LANGUAGE_FILTER_MAX_PREFERENCES usable
 * preferences, in order (#2137 review round 4), for a caller that did not
 * go through parsePreferredLanguageSubtags().
 *
 * @param list<string> $preferences
 * @return list<string>
 */
function languageFilterUsablePreferences(array $preferences): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';
    $out = [];
    foreach ($preferences as $pref) {
        if (count($out) >= IHYMNS_LANGUAGE_FILTER_MAX_PREFERENCES) {
            break;
        }
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
 * Every other way a stored value can name a language, from the shared data
 * file's replacement tables (#2137 second review).
 *
 * ELI5: `iw` is an old code for Hebrew, `zh-yue` an old way to write
 * Cantonese, `i-klingon` an old tag for Klingon. The shared rule
 * (canonicalise(), which the in-memory filter uses) reads each as the
 * language it means; SQL only sees the text. So that the two filters agree,
 * this lists, for each language, the stored spellings that mean it — and, for
 * each language, the spellings that START like it but mean something else
 * (`zh-yue` starts with `zh` but is Cantonese, `yue`, not Chinese `zh`).
 *
 * Built once per request from the data file itself, and each candidate is
 * judged by canonicalise(), so this can never disagree with the shared rule
 * about what a spelling means. Candidates:
 *   - `primary`: a retired language subtag (`iw` → `he`); a stored value whose
 *     FIRST part is it means that language;
 *   - `prefix`: an extlang form (`zh-yue` → `yue`); the value itself, or it
 *     followed by more parts;
 *   - `whole`: a grandfathered or redundant tag (`i-klingon`, `sgn-BR`,
 *     `zh-min-nan`); the value exactly.
 *
 * @return array{aliases: array<string, array{primary: list<string>, prefix: list<string>, whole: list<string>}>,
 *               startsLike: array<string, list<array{0:string, 1:string}>>}
 *         Both keyed by lower-case language; startsLike lists [candidate, kind]
 *         pairs that begin with "<language>-" but do NOT mean that language.
 *         Empty when the shared rules are not installed.
 */
function languageFilterAliasIndex(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    $index = ['aliases' => [], 'startsLike' => []];
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';
    if (!mediaLanguageReady()) {
        return $index;
    }
    $data = json_decode((string)@file_get_contents(mediaLanguageLibraryDir() . DIRECTORY_SEPARATOR . 'bcp47-language-data-v1.json'), true);
    if (!is_array($data)) {
        return $index;
    }
    $candidates = [];
    foreach (array_keys((array)($data['preferred']['language'] ?? [])) as $old) {
        $candidates[] = [strtolower((string)$old), 'primary'];
    }
    foreach ((array)($data['extlangs'] ?? []) as $extlang => $prefixes) {
        foreach ((array)$prefixes as $prefix) {
            $candidates[] = [strtolower($prefix . '-' . $extlang), 'prefix'];
        }
    }
    foreach (array_keys((array)($data['grandfathered'] ?? [])) as $tag) {
        $candidates[] = [strtolower((string)$tag), 'whole'];
    }
    foreach (array_keys((array)($data['redundant_preferred'] ?? [])) as $tag) {
        $candidates[] = [strtolower((string)$tag), 'whole'];
    }
    foreach ($candidates as [$cand, $kind]) {
        $parsed = \Mwbm\MediaLanguage\Policy::canonicalise($cand);
        $means = ($parsed->kind === \Mwbm\MediaLanguage\TagKind::Ordinary && $parsed->language !== null)
            ? strtolower($parsed->language) : null;
        if ($means !== null && $means !== explode('-', $cand, 2)[0]) {
            $index['aliases'][$means][$kind][] = $cand;
        }
        if ($kind !== 'primary' && str_contains($cand, '-')) {
            $first = explode('-', $cand, 2)[0];
            if ($means !== $first) {
                $index['startsLike'][$first][] = [$cand, $kind];
            }
        }
    }
    foreach ($index['aliases'] as &$kinds) {
        $kinds += ['primary' => [], 'prefix' => [], 'whole' => []];
    }
    unset($kinds);
    return $index;
}

/**
 * The four characters the shared rule trims from both ends of a stored value
 * before reading it — space, tab, carriage return, line feed (LANG-001 step 1;
 * `Policy::canonicalise()` calls `trim($raw, " \t\r\n")`). NOT PHP's bare
 * trim(), which also strips a NUL byte and a vertical tab: the shared rule
 * deliberately does not, and the filter must read a value the way the rule
 * does (#2137 review round 4).
 */
const IHYMNS_LANGUAGE_FILTER_TRIM = " \t\r\n";

/**
 * Build a SQL WHERE-clause fragment + bind-param pair to apply
 * the language filter at SELECT time.
 *
 * #2137 review round 4 — how the SQL reads a stored value, so it keeps
 * exactly the rows the in-memory filter keeps:
 *   R = the value with the four trim characters removed from both ends
 *       (MySQL's TRIM() removes spaces only, so a value with a surrounding
 *       tab or line break used to be compared with it; see $r below for how
 *       this is done on every supported server, MySQL 5.7 included);
 *   T = R with every non-ASCII character turned into `?` (CONVERT … USING
 *       ascii), lower-cased, then compared BYTE FOR BYTE (CAST … AS BINARY).
 * Why each step, since each one was a real disagreement:
 *   - the column's collation (utf8mb4_unicode_ci) ignores accents, letter
 *     width, and some characters entirely: `én`, full-width `ｅｎ`, `ünd`,
 *     `en` + a no-break or zero-width space all compared equal to `en` or
 *     `und`, and a value holding only a no-break or zero-width space counted
 *     as empty. The shared rule reads every one as malformed.
 *   - a binary COLLATION is not enough: utf8mb4_bin still ignores trailing
 *     spaces (`'und '` = `'und'` — checked on MariaDB 11.8 and MySQL 8.4), so
 *     the first part of `und -x` would count as `und`. CAST … AS BINARY
 *     compares every byte.
 *   - LOWER() maps some non-ASCII letters to ASCII ones (`İ` → `i`, the
 *     Kelvin sign → `k`, on both servers), so `İW` would become the Hebrew
 *     alias `iw`. The shared rule only ever reads ASCII, so non-ASCII is
 *     turned into `?` first, which no language code contains.
 *   - "untagged" is decided by CHAR_LENGTH(R) = 0, which no collation can
 *     bend.
 *
 * With T and its first part P, the fragment is:
 *   AND (
 *       col IS NULL OR CHAR_LENGTH(R) = 0                    -- untagged: always shown
 *    OR P IN ('und', 'mul', 'zxx')                           -- always shown (#2132)
 *    OR T IN (?, …)                                          -- exact-match preferences
 *    OR ( (P IN (L, retired codes for L) OR T = / LIKE an extlang form of L
 *          OR T = a grandfathered or redundant tag meaning L)
 *         AND T's second part is not an extlang that makes it another language (`zh-yue`)
 *         AND T is not a grandfathered/redundant tag that starts like L but means another
 *         [AND (T names no script OR T names one of the preferred scripts)] )   -- one per language L
 *   )
 *
 * The rules are the in-memory filter's (Policy::matchTags(), below), and
 * tests/php/test-language-filter-scripts.php checks the two keep the same
 * rows, on MariaDB and MySQL 8.4, including stored values that are not in
 * standard form:
 *   - matching is by language (#2137 — `pt-BR` matches `pt`, `pt-BR`,
 *     `pt-PT`), and a preference naming a SCRIPT drops rows in another script
 *     (#2137 review, MATCH-040);
 *   - a stored retired or old form matches the language it means (`iw` for
 *     `he`, `in` for `id`, `zh-yue` for `yue`, `i-klingon` for `tlh`), and a
 *     form that only STARTS like a language does not match it (`zh-yue` is
 *     not Chinese `zh`) — languageFilterAliasIndex() (#2137 second review);
 *   - a private-use or grandfathered preference, and `und` / `mul` / `mis` /
 *     `zxx` as a preference, match exactly only;
 *   - `und`, `mul` and `zxx` rows, and untagged rows, always pass (#2132);
 *   - ASCII letter case, and the four trim characters at either end, are
 *     ignored; nothing else is (see R and T above).
 * Every value is bound; the REGEXP patterns are built only from four-letter
 * scripts the shared rule has validated, and everything else in the SQL is
 * a constant.
 *
 * What SQL still cannot match the way the shared rule does, stated plainly:
 * a MALFORMED stored value that begins the way a matching value begins is
 * matched in SQL, where the shared rule matches nothing — SQL looks at the
 * start of the value, it does not check the whole value is a well-formed tag.
 * For `en`: `en-`, `en--GB`, `en-toolongsubtag`, `en-é` (its first part is
 * `en`). For `yue`: `zh-yue-` and `zh-yue-%` (they start like the old form
 * `zh-yue-…`, which the SQL matches as "that form followed by more parts").
 * Checking well-formedness in SQL would take a full tag grammar per row;
 * tests/php/test-language-filter-scripts.php pins exactly this list, so a
 * new difference turns it red. And without the shared rules installed there
 * are no alias lists at all.
 * Empty preferences return `[" AND 1=1", '', []]` so callers can concatenate
 * without checking.
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
    $plan  = languageFilterPlan($subtags);
    $index = languageFilterAliasIndex();
    $in = static fn(int $n): string => implode(',', array_fill(0, $n, '?'));

    /* The rule, written once over three expressions (#2137 review round 4):
       $t the value as bytes, lower-cased, for = / IN / LIKE; $l the same as
       ASCII text, for REGEXP; $empty "is it untagged". Called twice below. */
    $build = static function (string $t, string $l, string $empty) use ($plan, $index, $in): array {
        $p      = "SUBSTRING_INDEX($t, '-', 1)";
        $always = IHYMNS_LANGUAGE_FILTER_ALWAYS_SHOWN;
        $values = $always;
        $sql    = "($empty OR $p IN (" . $in(count($always)) . ")";
        if ($plan['whole'] !== []) {
            $sql   .= " OR $t IN (" . $in(count($plan['whole'])) . ")";
            $values = array_merge($values, $plan['whole']);
        }
        $languages = array_fill_keys($plan['any'], null) + $plan['byScript'];
        foreach ($languages as $lang => $scripts) {
            $lang    = (string)$lang;
            $aliases = $index['aliases'][$lang] ?? ['primary' => [], 'prefix' => [], 'whole' => []];
            $primary = array_values(array_unique(array_merge([$lang], $aliases['primary'])));
            $match   = ["$p IN (" . $in(count($primary)) . ")"];
            $vals    = $primary;
            foreach ($aliases['prefix'] as $form) {
                $match[] = "$t = ? OR $t LIKE ?";
                array_push($vals, $form, $form . '-%');
            }
            if ($aliases['whole'] !== []) {
                $match[] = "$t IN (" . $in(count($aliases['whole'])) . ")";
                $vals = array_merge($vals, $aliases['whole']);
            }
            $clause = '((' . implode(' OR ', $match) . ')';
            /* Forms that start like L but mean another language. An extlang form
               is always exactly "L-xxx", so its SECOND part is enough to spot it
               (one IN list, not one LIKE per form — the first version of this ran
               about 80 comparisons on every `zh` row and was 7 times slower). */
            $extlangSeconds = [];
            $wholeForms = [];
            foreach ($index['startsLike'][$lang] ?? [] as [$form, $kind]) {
                if ($kind === 'prefix') {
                    $extlangSeconds[] = explode('-', $form, 2)[1];
                } else {
                    $wholeForms[] = $form;
                }
            }
            if ($extlangSeconds !== []) {
                $clause .= " AND SUBSTRING_INDEX(SUBSTRING_INDEX($t, '-', 2), '-', -1) NOT IN (" . $in(count($extlangSeconds)) . ")";
                $vals = array_merge($vals, $extlangSeconds);
            }
            if ($wholeForms !== []) {
                $clause .= " AND $t NOT IN (" . $in(count($wholeForms)) . ")";
                $vals = array_merge($vals, $wholeForms);
            }
            if ($scripts !== null) {
                $clause .= " AND ($l NOT REGEXP ? OR $l REGEXP ?)";
                $vals[] = '^[a-z]{2,8}(-[a-z]{3}){0,3}-[a-z]{4}(-|$)';
                $vals[] = '^[a-z]{2,8}(-[a-z]{3}){0,3}-(' . implode('|', $scripts) . ')(-|$)';
            }
            $sql   .= ' OR ' . $clause . ')';
            $values = array_merge($values, $vals);
        }
        return [$sql . ')', $values];
    };

    /* EXACT path — R, L and T as the docblock above describes.
       R turns every tab, CR and LF into a space and then TRIM()s spaces.
       That is the rule's trim at both ends; INSIDE the value it also turns
       those three characters into spaces, which cannot change any result:
       every comparison below is against letters, digits and hyphens only
       (codes, tags, `form-%`, the script patterns), which neither a tab nor
       a space can ever equal or match, and the value's ends — the only place
       a regular expression's `$` could treat a line break specially — have
       already been trimmed. (REGEXP_REPLACE would trim the same way, but
       MySQL 5.7, which this project supports, does not have it: the whole
       statement would fail and every filtered list with it. It was also no
       faster.) The three characters are written into the SQL as themselves,
       not as backslash escapes, so this reads the same whether or not the
       server treats backslashes in a string as escapes
       (NO_BACKSLASH_ESCAPES). */
    $r = "TRIM(REPLACE(REPLACE(REPLACE($colExpr, '\t', ' '), '\r', ' '), '\n', ' '))";
    $l = "LOWER(CONVERT($r USING ascii))";
    [$exactSql, $exactValues] = $build("CAST($l AS BINARY)", $l, "CHAR_LENGTH($r) = 0");

    /* FAST path, for a CLEAN value — only ASCII letters, digits and hyphens,
       which is what almost every stored value is. For such a value there is
       nothing to trim and nothing non-ASCII, so R is the value itself and
       LOWER(CONVERT(R USING ascii)) is byte-for-byte LOWER(value): the fast
       path computes exactly the bytes the exact path would, and so cannot
       disagree with it. It exists because the exact path re-trims the value
       for every comparison (MySQL cannot reuse an expression within a row):
       measured, that made the filter 2–5 times slower on a 20,000-row table
       with 8–32 preferences, where this keeps it close to what it was.
       "Clean" is tested once per row, without anchors or escapes: after
       CONVERT … USING ascii every non-ASCII character is `?`, so "contains
       no character outside A–Z, a–z, 0–9 and -" is exact on MariaDB and
       MySQL 8 — but NOT on MySQL 5.7 (#2137 review round 5, correcting what
       this note said): 5.7's regular expressions stop reading at a NUL byte,
       so a value with a NUL in it is judged clean when everything BEFORE the
       NUL is. Such a value then takes the fast path, whose bytes can differ
       from the exact path's only AFTER the NUL (a trailing tab not trimmed,
       a non-ASCII letter not turned into `?`). The results still agree:
       everything before the NUL is identical on both paths, no constant the
       SQL compares with contains a NUL, and REGEXP stops at it on both paths
       — so every comparison gives the same answer. Checked on 5.7.44 by the
       fourth review and again in round 5: 99 values with a NUL were judged
       clean, and every one
       gave the same result on both paths for all 28 preference lists, with
       no difference from the in-memory filter outside the documented class
       below. (An anchored `^…$` test would not be exact either: `$` also
       matches just before a final line break, so `en` + a line feed would
       pass as clean.) An empty value counts as clean and is untagged on both
       paths. */
    [$fastSql, $fastValues] = $build("CAST(LOWER($colExpr) AS BINARY)", "LOWER($colExpr)", "CHAR_LENGTH($colExpr) = 0");
    $clean = "CONVERT($colExpr USING ascii) NOT REGEXP '[^A-Za-z0-9-]'";

    $where  = " AND ($colExpr IS NULL OR IF($clean, $fastSql, $exactSql))";
    $values = array_merge($fastValues, $exactValues);
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
        /* The shared rule's own four trim characters (round 4): PHP's bare
           trim() also strips NUL and a vertical tab, so `en` + a vertical tab
           used to match `en` here although the rule reads it as malformed. */
        $tag = trim((string)($row['language'] ?? $row['Language'] ?? ''), IHYMNS_LANGUAGE_FILTER_TRIM);
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
