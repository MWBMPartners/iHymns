<?php

declare(strict_types=1);

/**
 * iHymns — Access-tier shared validators (#719 PR 2b)
 *
 * Single source of truth for the `tblAccessTiers` capability column
 * list and the tier-name grammar. Used by:
 *
 *   - /manage/tiers.php       (web admin: create + update handlers,
 *                              capability table headers)
 *   - /api.php                (admin_tier_* CRUD endpoints)
 *
 * Adding a new capability column on the schema is a one-line change
 * to TIER_CAPS — both surfaces pick it up automatically (the dynamic
 * SQL builders in tiers.php and the API endpoint walk
 * `array_keys(TIER_CAPS)` to derive the column list).
 *
 * The label tuple `[short, long]` is used by tiers.php for the table
 * column header + tooltip; the API surface ignores the labels and
 * just consumes the column-name keys.
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Capability columns on tblAccessTiers, mapped to their UI labels.
 *
 * Keyed by exact column name (used as both the SQL identifier and
 * the JSON-input form field via the `cap_<Column>` POST key on the
 * web admin / `caps.<column>` JSON path on the API).
 *
 * Tuple shape: [short_label, full_description].
 */
if (!defined('IHYMNS_TIER_CAPS_DEFINED')) {
    define('IHYMNS_TIER_CAPS_DEFINED', true);
    define('TIER_CAPS', [
        'CanViewLyrics'      => ['Lyrics',       'View song lyrics'],
        'CanViewCopyrighted' => ['Copyrighted',  'View copyrighted songs'],
        'CanPlayAudio'       => ['Audio',        'Play MIDI / audio in-app'],
        'CanDownloadMidi'    => ['MIDI',         'Download MIDI files'],
        'CanDownloadPdf'     => ['PDF',          'Download sheet-music PDFs'],
        'CanOfflineSave'     => ['Offline',      'Save songs for offline use'],
        'RequiresCcli'       => ['Needs CCLI',   'Tier requires a valid CCLI licence number'],
    ]);
}

/**
 * Validate a tier name. Permits the curator's chosen casing — the
 * Username column on tblAccessTiers is utf8mb4_unicode_ci so the
 * uniqueness check stays case-insensitive without us lowercasing.
 *
 * Letters / digits / hyphen / underscore (#639). 30-char cap.
 *
 * @param string $name Raw tier-name as typed.
 * @return string|null Error message or null if valid.
 */
function validateTierName(string $name): ?string
{
    $name = trim($name);
    if ($name === '') {
        return 'Name is required.';
    }
    if (strlen($name) > 30) {
        return 'Name must be 30 characters or fewer.';
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
        return 'Name must be letters, digits, hyphen or underscore.';
    }
    return null;
}

/**
 * Validate the tier `Level` integer. The level governs how the
 * tier_check / licence_check helpers compare two tiers — higher
 * level = more permissions. Bounded to [0, 1000] to prevent a typo
 * (e.g. an extra zero) from making one tier silently outrank
 * everything else in the catalogue.
 *
 * @param int $level Raw level value.
 * @return string|null Error message or null if valid.
 */
function validateTierLevel(int $level): ?string
{
    if ($level < 0 || $level > 1000) {
        return 'Level must be between 0 and 1000.';
    }
    return null;
}
