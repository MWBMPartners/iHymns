<?php

declare(strict_types=1);

/**
 * iHymns — Credit-people shared helpers (#719 PR 2d)
 *
 * Single source of truth for the bits both /manage/credit-people.php
 * and the admin_credit_person_* API endpoints share:
 *
 *   - CREDIT_PERSON_LINK_TYPE_CATALOGUE — grouped catalogue of
 *     link-type keys for the per-person external-link sub-form (#586).
 *   - CREDIT_PERSON_LINK_TYPE_KEYS — flat lookup used by the validator.
 *   - normaliseCreditPersonLinks() / normaliseCreditPersonIpi() —
 *     drop empty rows, coerce unknown link types to 'other', normalise
 *     the row shape into INSERT-ready arrays.
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
 * Curated link-type registry (#586).
 *
 * Adding a new provider: append it under its category. The picker
 * UI (built from this catalogue via <optgroup>) and the
 * normaliser's allowlist both update automatically. Legacy
 * LinkType values stored in the DB before #586 (e.g. 'official',
 * 'wikipedia') stay valid because they still appear under General.
 */
if (!defined('IHYMNS_CREDIT_LINK_CATALOGUE_DEFINED')) {
    define('IHYMNS_CREDIT_LINK_CATALOGUE_DEFINED', true);
    define('CREDIT_PERSON_LINK_TYPE_CATALOGUE', [
        'General' => [
            'official'      => 'Official website',
            'wikipedia'     => 'Wikipedia',
            'wikidata'      => 'Wikidata',
            'musicbrainz'   => 'MusicBrainz',
            'discogs'       => 'Discogs',
            'imslp'         => 'IMSLP',
            'hymnary'       => 'Hymnary',
        ],
        'Music streaming / stores' => [
            'spotify'       => 'Spotify',
            'apple_music'   => 'Apple Music',
            'youtube_music' => 'YouTube Music',
            'amazon_music'  => 'Amazon Music',
            'tidal'         => 'Tidal',
            'qobuz'         => 'Qobuz',
            'pandora'       => 'Pandora',
            'bandcamp'      => 'Bandcamp',
            'soundcloud'    => 'SoundCloud',
        ],
        'Social media' => [
            'facebook'      => 'Facebook',
            'instagram'     => 'Instagram',
            'twitter'       => 'Twitter / X',
            'tiktok'        => 'TikTok',
            'youtube'       => 'YouTube',
            'snapchat'      => 'Snapchat',
            'threads'       => 'Threads',
            'mastodon'      => 'Mastodon',
        ],
        'Other' => [
            'other'         => 'Other (free text)',
        ],
    ]);
    /* Flat lookup used by the normaliser's allowlist + by the JS-side
       serialiser when it needs to validate keys client-side. */
    define('CREDIT_PERSON_LINK_TYPE_KEYS',
        array_keys(array_merge(...array_values(CREDIT_PERSON_LINK_TYPE_CATALOGUE))));
}

/**
 * Normalise the per-person external-link sub-form. Drops empty rows
 * (no URL), coerces unknown types to 'other', and returns
 * INSERT-ready row arrays.
 *
 * Accepts either the form-array shape from /manage/credit-people
 * (`links[i][type|url|label]`) or the JSON shape from /api.php
 * (`links[]: {type, url, label}`). The shape is identical once
 * decoded, so one normaliser covers both surfaces.
 *
 * @param mixed $raw Form / JSON-decoded array; non-array → []
 * @return list<array{type:string,url:string,label:?string,sort_order:int}>
 */
function normaliseCreditPersonLinks(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $i => $row) {
        if (!is_array($row)) continue;
        $url = trim((string)($row['url'] ?? ''));
        if ($url === '') continue;
        $type = trim((string)($row['type'] ?? 'other'));
        /* Unknown types collapse to 'other' rather than 500ing —
           keeps a forward-compatible UI where a future picker
           category gets dropped to a sane bucket on older servers. */
        if (!in_array($type, CREDIT_PERSON_LINK_TYPE_KEYS, true)) {
            $type = 'other';
        }
        $out[] = [
            'type'       => $type,
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
