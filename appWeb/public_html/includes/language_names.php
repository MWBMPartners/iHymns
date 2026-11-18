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
 * migration yet just shows the language code itself instead of crashing.
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
 * - Static-cached per request: one SELECT per registry table, indexed
 *   by lowercase Code in PHP memory. A page rendering 50 badges is a
 *   handful of DB queries, not 50.
 * - #2137 — a name is COMPOSED from the tag's parts ('pt-BR' →
 *   "Portuguese (Brazil)", 'zh-Hant' → "Chinese (Traditional)"), using
 *   the base language only for its NAME. The old lookup fell back to the
 *   base language's name, so regional and script forms all collapsed to
 *   one name ("Portuguese"). Tags are read with the shared language
 *   policy's rules (includes/media_language.php).
 * - #2137 — text direction comes from the script when the tag names one
 *   (IHYMNS_RTL_SCRIPTS), else from the base language.
 * - Schema-probed: a deploy without the registry tables returns the tag
 *   itself as its name (so a tooltip says "pt-BR" rather than 500-ing the
 *   page).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';   /* #2137 — the shared language rules (reading a tag's parts) */

/**
 * Scripts written right to left (#2137).
 *
 * ELI5: Arabic, Hebrew, Thaana (Maldivian), Syriac, N'Ko, Adlam and a few
 * dozen historic scripts run from right to left. When a tag names one of these
 * scripts, the text is laid out right to left whatever the language — and when
 * it names a left-to-right script, left to right, even for a language usually
 * written right to left (`ar-Latn`, romanised Arabic, runs left to right).
 *
 * DETAIL: the list is the set of ISO 15924 script codes Unicode's character
 * data marks as right-to-left, as ICU reports them through
 * `uscript_isRightToLeft()` (https://unicode-org.github.io/icu-docs/apidoc/released/icu4c/uscript_8h.html),
 * plus the registry's variant codes for those scripts (`Aran` Arabic Nastaliq;
 * `Syre`, `Syrj`, `Syrn` Syriac). The shared policy data carries no text
 * direction, so it lives here. Before #2137 direction came only from the base
 * language's row in tblLanguages, so `pa-Arab` (Punjabi in Arabic script, as
 * written in Pakistan) and `az-Arab` rendered left to right — wrong — and
 * `ar-Latn` right to left — also wrong.
 */
const IHYMNS_RTL_SCRIPTS = [
    'Adlm', 'Arab', 'Aran', 'Armi', 'Avst', 'Chrs', 'Cprt', 'Elym', 'Gara', 'Hatr',
    'Hebr', 'Hung', 'Khar', 'Lydi', 'Mand', 'Mani', 'Mend', 'Merc', 'Mero', 'Narb',
    'Nbat', 'Nkoo', 'Orkh', 'Ougr', 'Palm', 'Phli', 'Phlp', 'Phnx', 'Prti', 'Rohg',
    'Samr', 'Sarb', 'Sogd', 'Sogo', 'Syrc', 'Syre', 'Syrj', 'Syrn', 'Thaa', 'Yezi',
];

/**
 * The display name of a language tag in English — PURE (no database), so it
 * can be tested with plain arrays.
 *
 * ELI5: `pt-BR` → "Portuguese (Brazil)", `zh-Hant` → "Chinese (Traditional)",
 * `zh-Hant-TW` → "Chinese (Traditional, Taiwan)", `es-419` → "Spanish (Latin
 * America)". The language's name, then its script, region and variants in
 * brackets — the pattern Unicode CLDR uses for a language shown in a list
 * (and what the shared policy asks for, UI-010: "a regional or script form
 * SHOULD be named with its qualifier").
 *
 * #2137 — this replaced a lookup that fell back to the BASE language, so
 * `pt-BR` and `pt-PT` both showed as plain "Portuguese" and `zh-Hans` and
 * `zh-Hant` both as "Chinese": two different translations with one name.
 *
 * Every map is keyed by LOWER-CASE code → English name; iHymns fills them from
 * the registry tables (tblLanguages, tblLanguageScripts, tblRegions,
 * tblLanguageVariants), which the IANA + CLDR migration loads with CLDR's
 * English names. A part with no known name is shown as its code, so nothing is
 * ever dropped. A value that is not a language tag at all is returned as typed.
 *
 * @param array<string,string> $languageNames
 * @param array<string,string> $scriptNames
 * @param array<string,string> $regionNames
 * @param array<string,string> $variantNames
 */
function languageComposeDisplayName(
    string $tag,
    array $languageNames,
    array $scriptNames,
    array $regionNames,
    array $variantNames
): string {
    $raw = trim($tag);
    if ($raw === '') {
        return '';
    }
    if (!mediaLanguageReady()) {
        return $languageNames[strtolower($raw)] ?? $raw;
    }
    $parsed = \Mwbm\MediaLanguage\Policy::canonicalise($raw);
    if ($parsed->kind === \Mwbm\MediaLanguage\TagKind::Malformed) {
        return $raw;
    }
    if ($parsed->kind !== \Mwbm\MediaLanguage\TagKind::Ordinary) {
        return $languageNames[strtolower($parsed->tag)] ?? $parsed->tag;
    }
    $base = $languageNames[strtolower((string)$parsed->language)] ?? null;
    if ($base === null || $base === '') {
        /* Unknown language: its code says more than any guess would. */
        return $parsed->tag;
    }
    $qualifiers = [];
    if ($parsed->extlang !== null) {
        $qualifiers[] = $parsed->extlang;
    }
    if ($parsed->script !== null) {
        $qualifiers[] = $scriptNames[strtolower($parsed->script)] ?? $parsed->script;
    }
    if ($parsed->region !== null) {
        $qualifiers[] = $regionNames[strtolower($parsed->region)] ?? $parsed->region;
    }
    foreach ($parsed->variants as $variant) {
        $qualifiers[] = $variantNames[strtolower($variant)] ?? $variant;
    }
    foreach ($parsed->extensions as $extension) {
        $qualifiers[] = implode('-', $extension);
    }
    if ($parsed->privateUse !== []) {
        $qualifiers[] = 'x-' . implode('-', $parsed->privateUse);
    }
    return $qualifiers === [] ? $base : $base . ' (' . implode(', ', $qualifiers) . ')';
}

/**
 * Which way a language tag's text runs — PURE (no database).
 *
 * ELI5: the script decides when the tag names one (`pa-Arab` → right to left,
 * `ar-Latn` → left to right); otherwise the base language's usual direction
 * from the registry (`he` → right to left).
 *
 * @param array<string,array{name:string,nativeName:string,dir:string}> $metaMap lower-case code → registry row
 * @return 'ltr'|'rtl'
 */
function languageTextDirection(string $tag, array $metaMap): string
{
    $raw = trim($tag);
    if ($raw === '') {
        return 'ltr';
    }
    if (mediaLanguageReady()) {
        $parsed = \Mwbm\MediaLanguage\Policy::canonicalise($raw);
        if ($parsed->kind !== \Mwbm\MediaLanguage\TagKind::Ordinary) {
            return 'ltr';
        }
        if ($parsed->script !== null) {
            return in_array($parsed->script, IHYMNS_RTL_SCRIPTS, true) ? 'rtl' : 'ltr';
        }
        return ($metaMap[(string)$parsed->language]['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
    }
    /* Degraded path (shared rules missing on the server): the old behaviour. */
    $key = strtolower($raw);
    $row = $metaMap[$key] ?? $metaMap[explode('-', $key, 2)[0]] ?? null;
    return ($row['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
}

/**
 * The language's own name for itself (its "autonym") to show beside the
 * English name for a tag, or '' when none can be shown truthfully (#2137).
 *
 * The registry holds ONE autonym per base language, written in that
 * language's usual script ("српски" for Serbian, "اردو" for Urdu). A tag that
 * names a script of its own — `sr-Latn`, `ur-Latn`, `zh-Hant` — may be written
 * in a different one, so showing the base autonym there would label Latin-script
 * Serbian in Cyrillic. Such tags get no autonym; the English name ("Serbian
 * (Latin)") still says everything. Regional forms keep the base autonym
 * (`pt-BR` → "português"), because a region does not change the script.
 *
 * @param string $tag     Language tag.
 * @param array  $metaMap getLanguageMetaMap() shape (base code → nativeName).
 * @return string
 */
function languageAutonymFor(string $tag, array $metaMap): string
{
    $raw = trim($tag);
    if ($raw === '') {
        return '';
    }
    $base = mediaLanguageGroup($raw);
    if (mediaLanguageReady()) {
        $parsed = \Mwbm\MediaLanguage\Policy::canonicalise($raw);
        if ($parsed->kind !== \Mwbm\MediaLanguage\TagKind::Ordinary || $parsed->script !== null) {
            return '';
        }
        $base = (string)$parsed->language;
    }
    return (string)($metaMap[$base]['nativeName'] ?? '');
}

/**
 * Resolve a language tag to the English name a reader sees.
 *
 * ELI5: "what is 'pt-BR' called, in English?" → "Portuguese (Brazil)". Don't
 * know the language? Show its code rather than nothing.
 *
 * #2137 — this used to fall back to the BASE language when the full tag had no
 * row of its own, so `pt-BR` came back as "Portuguese" (its own doc comment
 * claimed "Portuguese (Brazil)", which it never produced). It now composes the
 * full name from the registry tables — see languageComposeDisplayName(). On a
 * complete miss (or before the registry migration has run) the canonical tag
 * is returned rather than an upper-cased code, so `pt-BR` never becomes the
 * different-looking `PT-BR`.
 *
 * @param string $code Language code or tag (e.g. 'en', 'pt-BR', 'zh-Hant-TW').
 * @return string Display name, or the tag itself when no name is known.
 */
function resolveLanguageName(string $code): string
{
    $code = trim($code);
    if ($code === '') return '';
    return languageComposeDisplayName(
        $code,
        getLanguageNamesMap(),
        getLanguageSubtagNamesMap('script'),
        getLanguageSubtagNamesMap('region'),
        getLanguageSubtagNamesMap('variant')
    );
}

/**
 * Resolve a language tag to its full display metadata for the language-picker
 * UI (#1149): the English name (as resolveLanguageName()), the language's own
 * name for itself (its "autonym", e.g. "português" — shown as a second label,
 * never the only one, policy UI-011) and its text direction.
 *
 * #2137 — the direction now comes from the SCRIPT when the tag names one
 * (languageTextDirection()), so `pa-Arab` is right to left and `ar-Latn` left
 * to right; before, only the base language decided. The autonym is the base
 * language's (the registry holds autonyms for languages only, not for every
 * regional form), so `pt-BR` shows "português" — but a tag naming its own
 * script gets none (languageAutonymFor()), so `sr-Latn` is never labelled in
 * Cyrillic.
 *
 * @param string $code Language code or tag (e.g. 'en', 'pt-BR', 'AF').
 * @return array{name:string,nativeName:string,dir:string}
 */
function resolveLanguageMeta(string $code): array
{
    $code = trim($code);
    if ($code === '') {
        return ['name' => '', 'nativeName' => '', 'dir' => 'ltr'];
    }
    $map = getLanguageMetaMap();
    return [
        'name'       => resolveLanguageName($code),
        'nativeName' => languageAutonymFor($code, $map),
        'dir'        => languageTextDirection($code, $map),
    ];
}

/**
 * Everything a songbook tile needs to show its language (#2137) — ONE helper
 * for the home page and the /songbooks page (modularity rule).
 *
 * ELI5: the small corner badge, the words a screen reader reads, and the
 * tooltip, for one songbook.
 *
 * - `tag`       the songbook's own language tag, tidied ('' when none or not known);
 * - `badge`     the visual badge text: the whole tag upper-cased ("ZH-HANT",
 *               "PT-BR", "EN") — it used to be the base code only, which could
 *               not tell Simplified from Traditional Chinese;
 * - `name`      the book language's full name ("Chinese (Traditional)"), for
 *               the tile's accessible name — never a bare code;
 * - `title`     the tooltip: the full names of every language the book's songs
 *               are in (`languageTags`), falling back to the book's own name;
 * - `groupsCsv` the language GROUPS for the client-side filter
 *               (data-songbook-languages), unchanged in meaning;
 * - `tagsCsv`   the WHOLE tags — the book's own language and its songs' —
 *               for the client-side filter's script check
 *               (data-songbook-language-tags, #2137 review: a `zh-Hans`
 *               reader must not be shown a book whose songs are all
 *               `zh-Hant`).
 *
 * A songbook whose language is `und`/`mul`/`zxx` shows no badge, like one with
 * no language (the language filter always shows it).
 *
 * @param array<string,mixed> $book A row from SongData::getSongbooks().
 * @return array{tag:string,badge:string,name:string,title:string,groupsCsv:string,tagsCsv:string}
 */
function songbookTileLanguage(array $book): array
{
    $raw  = trim((string)($book['language'] ?? ''));
    $tidy = ($raw !== '' && mediaLanguageReady()) ? mediaLanguageTagForStorage($raw) : $raw;
    $tag  = is_string($tidy) ? $tidy : $raw;
    if (in_array(mediaLanguageGroup($tag), ['und', 'mul', 'zxx'], true)) {
        $tag = '';
    }
    $name  = $tag !== '' ? resolveLanguageName($tag) : '';
    $names = [];
    foreach ((array)($book['languageTags'] ?? []) as $t) {
        $n = resolveLanguageName((string)$t);
        if ($n !== '' && !in_array($n, $names, true)) {
            $names[] = $n;
        }
    }
    $tags = array_map('strval', (array)($book['languageTags'] ?? []));
    if ($tag !== '' && !in_array($tag, $tags, true)) {
        array_unshift($tags, $tag);
    }
    return [
        'tag'       => $tag,
        'badge'     => $tag !== '' ? mb_strtoupper($tag) : '',
        'name'      => $name,
        'title'     => $names !== [] ? implode(', ', $names) : $name,
        'groupsCsv' => implode(',', (array)($book['languages'] ?? [])),
        'tagsCsv'   => implode(',', $tags),
    ];
}

/**
 * English names for one kind of subtag — scripts, regions or variants —
 * statically cached for the request, keyed by lower-case code (#2137).
 *
 * Reads the same registry tables the language pickers search
 * (IHYMNS_BCP47_SUBTAG_KINDS, via bcp47ResolveTable()), loaded with CLDR's
 * English names by the IANA + CLDR migration. Best-effort like
 * getLanguageMetaMap(): an un-migrated install or a failed read gives an empty
 * map, and the composer then shows the subtag's code instead of a name.
 *
 * @param 'script'|'region'|'variant' $kind
 * @return array<string,string>
 */
function getLanguageSubtagNamesMap(string $kind): array
{
    static $cache = [];
    if (isset($cache[$kind])) {
        return $cache[$kind];
    }
    $cache[$kind] = [];
    if (!in_array($kind, ['script', 'region', 'variant'], true)) {
        return $cache[$kind];
    }
    try {
        $db = getDbMysqli();
        if (!$db) {
            return $cache[$kind];
        }
        $table = bcp47ResolveTable($db, $kind);
        if ($table === '') {
            return $cache[$kind];
        }
        /* $table comes from bcp47ResolveTable()'s allow-listed candidates,
           never user input (rule #5). */
        $res = $db->query("SELECT Code, Name FROM {$table} WHERE COALESCE(IsActive, 1) = 1");
        while ($row = $res->fetch_assoc()) {
            $code = strtolower((string)$row['Code']);
            $name = (string)$row['Name'];
            if ($code !== '' && $name !== '') {
                $cache[$kind][$code] = $name;
            }
        }
        $res->close();
    } catch (\Throwable $e) {
        error_log('[language_names:' . $kind . '] ' . $e->getMessage());
    }
    return $cache[$kind];
}

/**
 * Return the full code → name map, statically cached for the
 * request. A back-compat projection of getLanguageMetaMap() — every
 * existing caller that only wants the English name keeps working.
 *
 * #2137 review — this comment already said "statically cached", but only the
 * underlying meta map was: the name map was rebuilt, all several hundred
 * entries, on EVERY call — and resolveLanguageName() calls it once per name
 * shown (every tile tooltip, every translation, every filter chip). It is
 * now built once per request.
 *
 * @return array<string, string> Lowercase code → English Name.
 */
function getLanguageNamesMap(): array
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $names = [];
    foreach (getLanguageMetaMap() as $code => $meta) {
        $names[$code] = $meta['name'];
    }
    return $names;
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
