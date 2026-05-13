<?php

declare(strict_types=1);

/**
 * iHymns — Credit-people shared helpers (#719 PR 2d)
 *
 * Single source of truth for the bits both /manage/credit-people.php
 * and the admin_credit_person_* API endpoints share:
 *
 *   - CREDIT_PERSON_LEGACY_SLUG_MAP — back-compat slug → registry-slug
 *     coercion table for /api.php callers that still send the legacy
 *     #586 vocabulary. The /manage/credit-people form moved to numeric
 *     LinkTypeId values directly and no longer reads this map.
 *   - resolveCreditPersonLinkTypeId() — single source of truth for
 *     "given a numeric type_id OR a slug string, find the matching
 *     tblExternalLinkTypes.Id row" used by every write path.
 *   - normaliseCreditPersonLinks() / normaliseCreditPersonIpi() —
 *     drop empty rows, resolve link-type slugs / numeric ids to
 *     registry FKs, normalise the row shape into INSERT-ready arrays.
 *   - creditPeopleFlagsColumnsExist() — cached check for the
 *     IsSpecialCase / IsGroup columns from #584/#585. Lets the add /
 *     update paths gracefully no-op the flag writes on a partly-
 *     migrated install (#630).
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Legacy slug → tblExternalLinkTypes.Slug map for credit-people.
 *
 * Preserved purely for backwards compatibility with /api.php
 * admin_credit_person_add / _update callers that historically sent
 * `links[].type` as a free-text slug string (the #586 catalogue
 * vocabulary: 'apple_music', 'youtube_music', 'twitter', etc.).
 * Mirrors the mapping table from migrate-backfill-credit-person-links.php
 * so any external consumer can keep sending the old vocabulary
 * while the storage layer moves to numeric LinkTypeId FKs.
 *
 * Anything not in the map resolves to 'other' — same fall-through
 * the backfill migration applies.
 */
if (!defined('IHYMNS_CREDIT_LINK_LEGACY_SLUG_MAP_DEFINED')) {
    define('IHYMNS_CREDIT_LINK_LEGACY_SLUG_MAP_DEFINED', true);
    define('CREDIT_PERSON_LEGACY_SLUG_MAP', [
        /* Direct passes (the registry slug IS the form slug) */
        'wikipedia'             => 'wikipedia',
        'wikidata'              => 'wikidata',
        'discogs'               => 'discogs',
        'imslp'                 => 'imslp',
        'viaf'                  => 'viaf',
        'linkedin'              => 'linkedin',
        'instagram'             => 'instagram',
        'facebook'              => 'facebook',
        'mastodon'              => 'mastodon',
        'youtube'               => 'youtube',
        'spotify'               => 'spotify',
        'bandcamp'              => 'bandcamp',
        'soundcloud'            => 'soundcloud',
        /* Aliases the #586 catalogue used (and other common variants) */
        'wiki'                  => 'wikipedia',
        'petrucci'              => 'imslp',
        'hymnary'               => 'hymnary-org',
        'hymnary.org'           => 'hymnary-org',
        'hymnary-org'           => 'hymnary-org',
        'website'               => 'official-website',
        'official'              => 'official-website',
        'official-website'      => 'official-website',
        'home'                  => 'official-website',
        'homepage'              => 'official-website',
        'musicbrainz'           => 'musicbrainz-artist',
        'mb'                    => 'musicbrainz-artist',
        'musicbrainz-artist'    => 'musicbrainz-artist',
        'loc'                   => 'loc-name-authority',
        'library-of-congress'   => 'loc-name-authority',
        'loc-name-authority'    => 'loc-name-authority',
        'findagrave'            => 'find-a-grave',
        'find-a-grave'          => 'find-a-grave',
        'goodreads'             => 'goodreads-author',
        'goodreads-author'      => 'goodreads-author',
        'twitter'               => 'twitter-x',
        'x'                     => 'twitter-x',
        'twitter-x'             => 'twitter-x',
        'apple_music'           => 'apple-music',
        'apple-music'           => 'apple-music',
        'itunes'                => 'apple-music',
        'youtube_music'         => 'youtube-music',
        'youtube-music'         => 'youtube-music',
        'archive.org'           => 'internet-archive',
        'archive'               => 'internet-archive',
        'internet-archive'      => 'internet-archive',
        'cyber-hymnal'          => 'cyber-hymnal',
        'cyberhymnal'           => 'cyber-hymnal',
    ]);
}

/**
 * Resolve the `tblExternalLinkTypes.Id` for a credit-person link row.
 *
 * Accepts either a numeric `type_id` (preferred — the /manage/credit-people
 * form uses the shared editor module so values are already registry
 * IDs) or a legacy slug string (`type`) which gets coerced via
 * CREDIT_PERSON_LEGACY_SLUG_MAP and looked up in the registry.
 * Returns null when neither path resolves — the caller drops the
 * row from the normaliser output, matching the pre-#833 behaviour
 * where unknown types collapsed to 'other'.
 */
function resolveCreditPersonLinkTypeId(\mysqli $db, mixed $rawTypeId, mixed $rawSlug): ?int
{
    static $registryIds = null;
    static $slugToId    = null;
    if ($registryIds === null) {
        $registryIds = [];
        $slugToId    = [];
        try {
            $res = $db->query(
                'SELECT Id, Slug FROM tblExternalLinkTypes WHERE COALESCE(IsActive, 1) = 1'
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $registryIds[(int)$r['Id']]     = true;
                    $slugToId[(string)$r['Slug']]   = (int)$r['Id'];
                }
                $res->close();
            }
        } catch (\Throwable $_e) { /* leave maps empty — caller falls through */ }
    }

    /* New shape — caller already has a numeric id. Trust it provided
       it exists in the active registry. */
    $typeId = (int)($rawTypeId ?? 0);
    if ($typeId > 0 && isset($registryIds[$typeId])) return $typeId;

    /* Legacy shape — coerce the slug via the alias map, then look up. */
    $slugIn = trim((string)($rawSlug ?? ''));
    if ($slugIn === '') return null;
    $resolved = CREDIT_PERSON_LEGACY_SLUG_MAP[mb_strtolower($slugIn)] ?? 'other';
    return $slugToId[$resolved] ?? null;
}

/**
 * Normalise the per-person external-link sub-form. Drops empty rows
 * (no URL), resolves either numeric `type_id` (new editor) or legacy
 * `type` slug strings to a `tblExternalLinkTypes.Id`, and returns
 * INSERT-ready row arrays shaped for `tblCreditPersonExternalLinks`.
 *
 * Accepts either the form-array shape from /manage/credit-people
 * (`links[i][type_id|type|url|label]`) or the JSON shape from
 * /api.php (`links[]: {type_id|type, url, label}`).
 *
 * Rows whose type can't be resolved to a registry id are dropped
 * silently — same outcome the pre-#833 normaliser produced for
 * unknown slug strings (it folded them into 'other'; here we
 * require the 'other' row itself to exist in the registry).
 *
 * @param \mysqli $db   Needed to resolve slug → registry id.
 * @param mixed   $raw  Form / JSON-decoded array; non-array → []
 * @return list<array{type_id:int,url:string,label:?string,sort_order:int}>
 */
function normaliseCreditPersonLinks(\mysqli $db, mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $i => $row) {
        if (!is_array($row)) continue;
        $url = trim((string)($row['url'] ?? ''));
        if ($url === '') continue;
        $typeId = resolveCreditPersonLinkTypeId($db, $row['type_id'] ?? null, $row['type'] ?? null);
        if ($typeId === null) continue;
        $out[] = [
            'type_id'    => $typeId,
            'url'        => $url,
            'label'      => trim((string)($row['label'] ?? '')) ?: null,
            'sort_order' => (int)($row['sort_order'] ?? $i),
        ];
    }
    return $out;
}

/**
 * Normalise the per-person IPI Name Number sub-form. Drops empty
 * rows (no number) and returns INSERT-ready row arrays.
 *
 * @param mixed $raw Form / JSON-decoded array; non-array → []
 * @return list<array{number:string,name_used:?string,notes:?string}>
 */
function normaliseCreditPersonIpi(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) continue;
        $num = trim((string)($row['number'] ?? ''));
        if ($num === '') continue;
        $out[] = [
            'number'    => $num,
            'name_used' => trim((string)($row['name_used'] ?? '')) ?: null,
            'notes'     => trim((string)($row['notes']     ?? '')) ?: null,
        ];
    }
    return $out;
}

/**
 * Normalise the per-person ISNI sub-form. Same row shape as IPI so the
 * unified tblCreditPersonIdentifiers INSERT path can treat both flows
 * identically — the only thing that differs is the IdentifierType value
 * the caller binds when persisting.
 *
 * Light validation: trims hyphens / spaces and uppercases the trailing X
 * (ISNI checksum digit) since ISNI is a 16-character ID conventionally
 * displayed in groups of four separated by spaces or hyphens. Stored
 * representation is the bare digits/X so two equivalent renderings
 * collide on the UNIQUE constraint.
 *
 * @param mixed $raw Form / JSON-decoded array; non-array → []
 * @return list<array{number:string,name_used:?string,notes:?string}>
 */
function normaliseCreditPersonIsni(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) continue;
        $raw_id = trim((string)($row['number'] ?? ''));
        if ($raw_id === '') continue;
        /* Collapse separators + uppercase the X check character so
           "0000 0001 2103 2683" and "0000-0001-2103-2683" both store
           as 0000000121032683. */
        $bare = strtoupper(preg_replace('/[\s\-]+/', '', $raw_id) ?? $raw_id);
        $out[] = [
            'number'    => $bare,
            'name_used' => trim((string)($row['name_used'] ?? '')) ?: null,
            'notes'     => trim((string)($row['notes']     ?? '')) ?: null,
        ];
    }
    return $out;
}

/**
 * Compose a person's display name from the structured columns added in
 * #934. The result is what gets written back into tblCreditPeople.Name
 * so the ~30 read sites that already query Name continue to see the
 * canonical display string.
 *
 * Empty/null parts collapse cleanly: composePersonName('John', 'Newton', null)
 * returns "John Newton"; ('', 'Anonymous', '') returns "Anonymous"; all-empty
 * returns ''.
 */
function composePersonName(?string $first, ?string $surname, ?string $suffix): string
{
    $parts = array_filter(
        [trim((string)$first), trim((string)$surname), trim((string)$suffix)],
        static fn(string $p): bool => $p !== ''
    );
    return preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '';
}

/**
 * Best-effort split of a free-text Name into [firstNames, surname, suffix].
 * Used by the migration backfill (#934) and any other code that needs to
 * decompose a legacy single-string name on the fly.
 *
 * Heuristic:
 *   - Comma-inverted form ("Wesley, Charles") flips to "Charles Wesley".
 *   - Trailing tokens matching the suffix pattern peel off into Suffix
 *     (one or more — handles "John Smith III PhD").
 *   - The new last token becomes Surname; everything before is FirstNames.
 *   - A single-token name (e.g. "Madonna") goes entirely into Surname so
 *     "ORDER BY Surname" sorts naturally.
 *
 * Returns ['', '', ''] on empty input. Callers that need to handle the
 * "single-name surname might really be a first name" case can inspect
 * the returned firstNames === '' and decide policy.
 *
 * @return array{0:string,1:string,2:string} [firstNames, surname, suffix]
 */
function decomposePersonName(string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return ['', '', ''];
    }
    if (str_contains($name, ',')) {
        $parts = array_map('trim', explode(',', $name, 2));
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $name = $parts[1] . ' ' . $parts[0];
        }
    }
    $tokens = preg_split('/\s+/', $name) ?: [];
    $tokens = array_values(array_filter($tokens, static fn(string $t): bool => $t !== ''));
    if (empty($tokens)) {
        return ['', '', ''];
    }
    $suffixPattern = '/^(?:Jr|Sr|II|III|IV|V|VI|VII|VIII|PhD|Ph\.D\.|MD|M\.D\.|Esq|Esq\.|D\.D\.|D\.Min\.|MA|M\.A\.|BA|B\.A\.)$/i';
    $suffixParts = [];
    while (count($tokens) > 1) {
        $tail     = $tokens[count($tokens) - 1];
        $tailNorm = rtrim($tail, '.,');
        if (preg_match($suffixPattern, $tail) || preg_match($suffixPattern, $tailNorm)) {
            array_unshift($suffixParts, array_pop($tokens));
            continue;
        }
        break;
    }
    $suffix = implode(' ', $suffixParts);
    if (count($tokens) === 1) {
        return ['', $tokens[0], $suffix];
    }
    $surname    = (string)array_pop($tokens);
    $firstNames = implode(' ', $tokens);
    return [$firstNames, $surname, $suffix];
}

/**
 * Cached check for the FirstNames / Surname / Suffix columns from #934.
 * Mirrors creditPeopleFlagsColumnsExist()'s pattern — admin INSERT /
 * UPDATE paths gate the new columns on this so a partly-migrated
 * install still saves rather than throwing "Unknown column".
 */
function creditPeopleNamePartsColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblCreditPeople'
                AND COLUMN_NAME  = 'Surname' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Slugify a person's name for the URL component. Lower-cases, strips
 * combining marks, collapses non-letter/digit runs to single hyphens.
 * Mirrors the migration-time slugify in migrate-credit-people-slug.php
 * — when both implementations drift, the backfill stops being idempotent
 * with new inserts.
 *
 * Returns '' for an empty / punctuation-only name; callers should fall
 * back to a stable default (e.g. 'person') in that case.
 */
function slugifyCreditPersonName(string $name): string
{
    $s = mb_strtolower(trim($name));
    if (class_exists('Normalizer')) {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        $s = preg_replace('/\p{M}+/u', '', $s) ?? '';
    }
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
    return trim($slug, '-');
}

/**
 * Generate a unique slug for a tblCreditPeople row.
 *
 * Computes the base slug from $name, then appends -2 / -3 / … until the
 * candidate isn't already taken. When $excludeId is provided, the row
 * with that Id is excluded from the collision check — needed by UPDATE
 * paths so a curator renaming a row doesn't collide with the row's own
 * existing slug.
 *
 * Returns 'person' (or 'person-2', etc.) when the input slugifies to
 * empty — keeps the UNIQUE constraint satisfied without a NOT NULL
 * violation, and stays consistent with the migration's fallback.
 *
 * Schema-tolerant: if the Slug column doesn't exist yet (pre-migration
 * install), returns '' so callers can omit the column from the INSERT.
 */
function generateUniqueCreditPersonSlug(\mysqli $db, string $name, ?int $excludeId = null): string
{
    /* If the column hasn't been added yet, no slug is needed. */
    static $hasSlugCol = null;
    if ($hasSlugCol === null) {
        try {
            $probe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblCreditPeople'
                    AND COLUMN_NAME  = 'Slug' LIMIT 1"
            );
            $probe->execute();
            $hasSlugCol = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) {
            $hasSlugCol = false;
        }
    }
    if (!$hasSlugCol) return '';

    $base = slugifyCreditPersonName($name);
    if ($base === '') $base = 'person';

    /* Pull all taken slugs that share this base prefix in one query.
       VARCHAR(255) with utf8mb4_unicode_ci, indexed via idx_Slug + the
       uk_Slug unique key — the LIKE scan stays fast even on a large
       registry. */
    $like = $base . '%';
    if ($excludeId !== null) {
        $stmt = $db->prepare('SELECT Slug FROM tblCreditPeople WHERE Slug LIKE ? AND Id <> ?');
        $stmt->bind_param('si', $like, $excludeId);
    } else {
        $stmt = $db->prepare('SELECT Slug FROM tblCreditPeople WHERE Slug LIKE ?');
        $stmt->bind_param('s', $like);
    }
    $stmt->execute();
    $taken = [];
    $res   = $stmt->get_result();
    while ($r = $res->fetch_row()) { $taken[(string)$r[0]] = true; }
    $stmt->close();

    if (!isset($taken[$base])) return $base;
    $suffix = 2;
    while (isset($taken[$base . '-' . $suffix])) { $suffix++; }
    return $base . '-' . $suffix;
}

/**
 * Idempotent "register this name in the registry" helper.
 *
 * Looks up tblCreditPeople by Name and returns the existing Id if a row
 * already matches. Otherwise INSERTs a new row carrying:
 *   - Name        (the trimmed input)
 *   - Slug        (computed via generateUniqueCreditPersonSlug if the
 *                  column exists)
 *   - FirstNames  | OPTIONAL — only set when the name-parts columns
 *   - Surname     | from PR #935 are present AND $parts is supplied;
 *   - Suffix      | otherwise these are simply omitted from the INSERT
 *
 * Returns the row's Id (existing or new). Returns 0 only when $name is
 * empty — callers should guard.
 *
 * This is the canonical insertion point for the registry — every other
 * path that previously called `INSERT INTO tblCreditPeople (Name)` and
 * silently relied on `NOT NULL DEFAULT ''` Slug semantics MUST route
 * through here, or it will trip the `Duplicate entry '' for uk_Slug`
 * UNIQUE collision the moment an orphan empty-Slug row exists.
 */
function registerCreditPersonByName(
    \mysqli $db,
    string  $name,
    ?array  $parts = null
): int {
    $name = trim($name);
    if ($name === '') return 0;

    /* Fast path: row already exists. */
    $stmt = $db->prepare('SELECT Id FROM tblCreditPeople WHERE Name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($row) return (int)$row[0];

    /* Slug is computed even if the column is technically nullable —
       generateUniqueCreditPersonSlug() returns '' when the column
       isn't present yet (pre-migration), in which case we just omit
       it from the INSERT. */
    $slug          = generateUniqueCreditPersonSlug($db, $name);
    $hasSlugCol    = $slug !== '';
    $hasNamePartCols = creditPeopleNamePartsColumnsExist($db);
    $first  = $parts['first']   ?? null;
    $surname= $parts['surname'] ?? null;
    $suffix = $parts['suffix']  ?? null;
    if ($first  !== null && trim((string)$first)  === '') $first  = null;
    if ($surname!== null && trim((string)$surname)=== '') $surname= null;
    if ($suffix !== null && trim((string)$suffix) === '') $suffix = null;

    if ($hasSlugCol && $hasNamePartCols) {
        $sql   = 'INSERT INTO tblCreditPeople (Name, Slug, FirstNames, Surname, Suffix) VALUES (?, ?, ?, ?, ?)';
        $stmt  = $db->prepare($sql);
        $stmt->bind_param('sssss', $name, $slug, $first, $surname, $suffix);
    } elseif ($hasSlugCol) {
        $sql   = 'INSERT INTO tblCreditPeople (Name, Slug) VALUES (?, ?)';
        $stmt  = $db->prepare($sql);
        $stmt->bind_param('ss', $name, $slug);
    } elseif ($hasNamePartCols) {
        $sql   = 'INSERT INTO tblCreditPeople (Name, FirstNames, Surname, Suffix) VALUES (?, ?, ?, ?)';
        $stmt  = $db->prepare($sql);
        $stmt->bind_param('ssss', $name, $first, $surname, $suffix);
    } else {
        $sql   = 'INSERT INTO tblCreditPeople (Name) VALUES (?)';
        $stmt  = $db->prepare($sql);
        $stmt->bind_param('s', $name);
    }
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    return $newId;
}

/**
 * Cached check for the IsSpecialCase / IsGroup columns from
 * #584/#585 (#630). Both ship together via
 * migrate-credit-people-flags.php; detecting one is sufficient to
 * assume both. Caches the result for the request lifetime via a
 * static so the add / update paths don't pay the
 * INFORMATION_SCHEMA round-trip twice.
 */
function creditPeopleFlagsColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblCreditPeople'
                AND COLUMN_NAME  = 'IsSpecialCase' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}


/* =========================================================================
 * CREDIT PERSON ALIASES (AKA names)
 *
 * Shared helpers for the MusicBrainz-style alias model
 * (tblCreditPersonAliases — see migrate-credit-people-aliases.php).
 * Used by:
 *   - /api.php's admin_credit_person_add / _update handlers
 *   - /manage/credit-people.php form handler
 *   - /people/<slug>'s public render (alias list + JSON-LD)
 *   - bulk-import's "(a.k.a. …)" pattern detector
 * ========================================================================= */

const CREDIT_PERSON_ALIAS_TYPES = [
    'legal'         => 'Legal name',
    'artist'        => 'Artist / performing name',
    'pseudonym'     => 'Pseudonym / pen name',
    'nickname'      => 'Nickname',
    'maiden'        => 'Maiden name',
    'search-hint'   => 'Search hint',
    'misspelling'   => 'Common misspelling',
    'other'         => 'Other',
];

const CREDIT_PERSON_ALIAS_TYPE_KEYS = [
    'legal','artist','pseudonym','nickname','maiden','search-hint','misspelling','other',
];

/**
 * Schema-tolerant probe for the aliases table — returns false on installs
 * that haven't run migrate-credit-people-aliases.php yet so consuming
 * code can branch cheaply rather than throw on every load.
 */
function creditPeopleAliasesTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblCreditPersonAliases' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Normalise inbound alias payload (form-encoded or JSON-decoded) into
 * a list of INSERT-ready row arrays. Drops rows with empty Name, clamps
 * Type to the allow-list, defaults SortOrder to the input index.
 *
 * @param mixed $raw Form / JSON-decoded array; non-array → []
 * @return list<array{name:string,sort_name:?string,type:string,locale:?string,is_primary:int,sort_order:int,note:?string}>
 */
function normaliseCreditPersonAliases(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out  = [];
    $seen = []; /* dedupe by (lower-cased) name within the request */
    foreach ($raw as $i => $row) {
        if (!is_array($row)) continue;
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') continue;
        if (mb_strlen($name) > 255) $name = mb_substr($name, 0, 255);
        $key = mb_strtolower($name);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $type = trim((string)($row['type'] ?? 'other'));
        if (!in_array($type, CREDIT_PERSON_ALIAS_TYPE_KEYS, true)) $type = 'other';

        $sortName = trim((string)($row['sort_name'] ?? ''));
        $locale   = trim((string)($row['locale']    ?? ''));
        $note     = trim((string)($row['note']      ?? ''));

        $out[] = [
            'name'        => $name,
            'sort_name'   => $sortName !== '' ? mb_substr($sortName, 0, 255) : null,
            'type'        => $type,
            'locale'      => $locale   !== '' ? mb_substr($locale, 0, 35)    : null,
            'is_primary'  => !empty($row['is_primary']) ? 1 : 0,
            'sort_order'  => (int)($row['sort_order'] ?? $i),
            'note'        => $note     !== '' ? mb_substr($note, 0, 255)     : null,
        ];
    }
    return $out;
}

/**
 * Replace all rows in tblCreditPersonAliases for a credit person with
 * the supplied set. Run inside the same transaction as the parent
 * INSERT/UPDATE so a downstream failure rolls back cleanly. Schema-
 * tolerant: silently no-ops on installs where the table is absent.
 *
 * @param list<array> $aliases  Output of normaliseCreditPersonAliases()
 */
function replaceCreditPersonAliases(\mysqli $db, int $creditPersonId, array $aliases): void
{
    if (!creditPeopleAliasesTableExists($db)) return;

    $del = $db->prepare('DELETE FROM tblCreditPersonAliases WHERE CreditPersonId = ?');
    $del->bind_param('i', $creditPersonId);
    $del->execute();
    $del->close();

    if (!$aliases) return;

    $ins = $db->prepare(
        'INSERT INTO tblCreditPersonAliases
             (CreditPersonId, Name, SortName, Type, Locale, IsPrimary, SortOrder, Note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($aliases as $a) {
        $cpId      = $creditPersonId;
        $name      = $a['name'];
        $sortName  = $a['sort_name'];
        $type      = $a['type'];
        $locale    = $a['locale'];
        $isPrimary = (int)$a['is_primary'];
        $sortOrder = (int)$a['sort_order'];
        $note      = $a['note'];
        $ins->bind_param(
            'issssiis',
            $cpId, $name, $sortName, $type, $locale, $isPrimary, $sortOrder, $note
        );
        $ins->execute();
    }
    $ins->close();
}

/**
 * Fetch every alias for one credit-person, ordered by IsPrimary DESC
 * then SortOrder ASC then Id ASC so the curator's preferred display
 * form is first.
 *
 * @return list<array{Id:int,Name:string,SortName:?string,Type:string,Locale:?string,IsPrimary:int,SortOrder:int,Note:?string}>
 */
function loadCreditPersonAliases(\mysqli $db, int $creditPersonId): array
{
    if (!creditPeopleAliasesTableExists($db)) return [];
    $stmt = $db->prepare(
        'SELECT Id, Name, SortName, Type, Locale, IsPrimary, SortOrder, Note
           FROM tblCreditPersonAliases
          WHERE CreditPersonId = ?
       ORDER BY IsPrimary DESC, SortOrder ASC, Id ASC'
    );
    $stmt->bind_param('i', $creditPersonId);
    $stmt->execute();
    $out = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($out as &$r) {
        $r['Id']        = (int)$r['Id'];
        $r['IsPrimary'] = (int)$r['IsPrimary'];
        $r['SortOrder'] = (int)$r['SortOrder'];
    }
    unset($r);
    return $out;
}

/**
 * Bulk-load aliases for many credit-people in one round-trip, grouped
 * by CreditPersonId. Used by /manage/credit-people's list-view render
 * so the page doesn't issue N queries when surfacing aliases inline.
 *
 * @param list<int> $personIds
 * @return array<int, list<array>>  CreditPersonId → list of alias rows
 */
function loadCreditPersonAliasesBulk(\mysqli $db, array $personIds): array
{
    $personIds = array_values(array_filter(array_map('intval', $personIds), fn($v) => $v > 0));
    if (!$personIds || !creditPeopleAliasesTableExists($db)) return [];
    $placeholders = implode(',', array_fill(0, count($personIds), '?'));
    $types        = str_repeat('i', count($personIds));
    $stmt = $db->prepare(
        "SELECT CreditPersonId, Id, Name, SortName, Type, Locale, IsPrimary, SortOrder, Note
           FROM tblCreditPersonAliases
          WHERE CreditPersonId IN ($placeholders)
       ORDER BY CreditPersonId ASC, IsPrimary DESC, SortOrder ASC, Id ASC"
    );
    $stmt->bind_param($types, ...$personIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $grouped = [];
    foreach ($rows as $r) {
        $pid = (int)$r['CreditPersonId'];
        unset($r['CreditPersonId']);
        $r['Id']        = (int)$r['Id'];
        $r['IsPrimary'] = (int)$r['IsPrimary'];
        $r['SortOrder'] = (int)$r['SortOrder'];
        $grouped[$pid][] = $r;
    }
    return $grouped;
}

/**
 * Detect alias patterns inside a free-text credit string, used by the
 * bulk-import flow to auto-promote "a.k.a." annotations into proper
 * alias rows. Recognises:
 *
 *   "Smith (a.k.a. Jones)"        → primary=Smith, alias=Jones
 *   "Smith (aka Jones)"           → ditto
 *   "Smith / Jones"               → primary=Smith, alias=Jones
 *   "Smith (Jones)"               → primary=Smith, alias=Jones (parenthetical)
 *   "Smith, also known as Jones"  → ditto
 *
 * Returns ['name' => <canonical>, 'aliases' => list<string>]. Pass-through
 * for strings with no recognisable pattern: ['name' => $raw, 'aliases' => []].
 *
 * Conservative — only fires on patterns that are unambiguously
 * alias markers. Bare parenthetical "(b. 1850)" is left alone (the
 * Smith-and-Jones case is distinguished by the absence of digits /
 * non-name characters inside the parens).
 */
function parseCreditPersonAliasHints(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return ['name' => '', 'aliases' => []];

    $aliases = [];
    $primary = $raw;

    /* Pattern 1 — explicit a.k.a. / aka / also known as. The optional
       trailing comma/period before the marker gets stripped from the
       primary so "Smith, also known as Jones" lands as primary=Smith,
       not "Smith," with a trailing comma. */
    if (preg_match('/^(.+?)\s*[,.]?\s*(?:\(\s*)?(?:a\.?k\.?a\.?|also\s+known\s+as)\s+([^)]+?)\s*\)?\s*$/i', $raw, $m)) {
        $primary = rtrim(trim($m[1]), " ,.");
        $aliasText = trim($m[2]);
        if ($aliasText !== '') {
            /* Split on slash / semicolon / " or " so multiple aliases work. */
            foreach (preg_split('/\s*(?:\/|;| or )\s*/i', $aliasText) as $a) {
                $a = trim($a);
                if ($a !== '' && $a !== $primary) $aliases[] = $a;
            }
        }
        return ['name' => $primary, 'aliases' => $aliases];
    }

    /* Pattern 2 — parenthetical name without explicit marker.
       Requires the parens to contain letter-only content (no digits,
       no commas, no dates), and to look like a name (≥2 letters). */
    if (preg_match('/^(.+?)\s*\(\s*([\p{L}][\p{L}\s\.\-\']+)\s*\)\s*$/u', $raw, $m)) {
        $inner = trim($m[2]);
        /* Reject biography-ish content. */
        if (!preg_match('/\d|,|^b\.|^d\.|^born\b|^died\b/iu', $inner)) {
            $primary   = trim($m[1]);
            $aliases[] = $inner;
            return ['name' => $primary, 'aliases' => $aliases];
        }
    }

    /* Pattern 3 — "A / B" without slash being a date or path. Only
       split when both sides look like person names (≥2 letters, no
       digits, no slashes themselves). */
    if (preg_match('/^([\p{L}][\p{L}\s\.\-\']+?)\s+\/\s+([\p{L}][\p{L}\s\.\-\']+)$/u', $raw, $m)) {
        $left  = trim($m[1]);
        $right = trim($m[2]);
        if ($left !== '' && $right !== '' && $left !== $right) {
            return ['name' => $left, 'aliases' => [$right]];
        }
    }

    return ['name' => $raw, 'aliases' => []];
}
