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
