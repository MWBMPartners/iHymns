<?php

declare(strict_types=1);

/**
 * iHymns — Access-tier shared validators (#719 PR 2b)
 *
 * Single source of truth for the `tblAccessTiers` capability set and
 * the tier-name grammar. Used by:
 *
 *   - /manage/tiers.php       (web admin: create + update handlers,
 *                              capability table headers)
 *   - /api.php                (admin_tier_* CRUD endpoints + the public
 *                              `access_tiers` emit — the native-app
 *                              contract)
 *
 * TO ADD A GATED FEATURE: add ONE line to TIER_CAPS with storage 'json'
 * — no schema change. The admin UI, the API CRUD, the API emit and
 * content gating all derive the cap list from TIER_CAPS, so a json-backed
 * cap is picked up everywhere with zero per-surface edits. The 7 original
 * caps keep storage 'column' because the native-app API contract (the
 * camelCase keys in /api.php's `access_tiers` emit) reads them straight
 * off their own TINYINT columns — never re-home an existing column cap
 * into JSON (it would break that contract). New caps go to 'json', which
 * lands inside the single tblAccessTiers.Capabilities JSON column added
 * by migrate-add-tier-capabilities-json.php (rule #20: a growable
 * vocabulary is JSON, never an ENUM or one-column-per-feature).
 *
 * Tuple shape (4 elements): [short_label, full_description, storage, default]
 *   - short_label  : table column header on /manage/tiers.php
 *   - full_description : tooltip / checkbox hint
 *   - storage      : 'column' (own TINYINT column) | 'json'
 *                    (key inside tblAccessTiers.Capabilities)
 *   - default      : 0 | 1 — value assumed when the json key is absent
 *                    or the Capabilities column hasn't been migrated yet
 *
 * BACK-COMPAT: tiers.php destructures `[$lbl, $hint]` (2 elements) from
 * each tuple. PHP list-destructuring of a 4-element array binds only the
 * first two and ignores the rest — so widening to a 4-tuple is a no-op
 * for those call sites (verified). The helpers below read storage /
 * default / per-tier values without touching that pattern.
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Tier capabilities on tblAccessTiers, mapped to their UI labels +
 * storage + default.
 *
 * Keyed by exact capability key. For storage 'column' the key is the
 * exact tblAccessTiers column name; for storage 'json' it's the key
 * inside the Capabilities JSON column. Both forms drive the same
 * `cap_<Key>` POST field on the web admin and `caps.<key>` JSON path
 * on the API.
 *
 * Tuple shape: [short_label, full_description, storage, default].
 *   storage: 'column' = own TINYINT column (the 7 originals — the
 *            native-app API contract reads these);
 *            'json'   = a named key in tblAccessTiers.Capabilities
 *            (every NEW gated cap — no schema change, rule #20).
 *   default: 0|1 assumed when a json key is absent or the Capabilities
 *            column hasn't been migrated yet.
 */
if (!defined('IHYMNS_TIER_CAPS_DEFINED')) {
    define('IHYMNS_TIER_CAPS_DEFINED', true);
    define('TIER_CAPS', [
        /* The 7 originals — storage 'column' (their own TINYINT). Never
           re-home one of these into JSON: the camelCase keys they emit
           are the native-app API contract. */
        'CanViewLyrics'      => ['Lyrics',       'View song lyrics',                         'column', 1],
        'CanViewCopyrighted' => ['Copyrighted',  'View copyrighted songs',                   'column', 0],
        'CanPlayAudio'       => ['Audio',        'Play MIDI / audio in-app',                 'column', 0],
        'CanDownloadMidi'    => ['MIDI',         'Download MIDI files',                       'column', 0],
        'CanDownloadPdf'     => ['PDF',          'Download sheet-music PDFs',                 'column', 0],
        'CanOfflineSave'     => ['Offline',      'Save songs for offline use',               'column', 0],
        'RequiresCcli'       => ['Needs CCLI',   'Tier requires a valid CCLI licence number', 'column', 0],
        /* NEW gated caps go BELOW, storage 'json' — one line, no ALTER.
           Example (commented until needed):
           'CanRequestSongs' => ['Requests', 'Submit song requests', 'json', 0], */
    ]);
}

/**
 * Storage backing for a capability key: 'column' or 'json'.
 * Unknown keys default to 'json' (the safe additive home) so a typo'd
 * lookup never tries to interpolate a non-existent column name into SQL.
 *
 * @param string $k Capability key (e.g. 'CanViewLyrics').
 * @return string 'column' | 'json'.
 */
if (!function_exists('tierCapStorage')) {
    function tierCapStorage(string $k): string
    {
        /* ELI5: ask TIER_CAPS where this cap lives.
           WHY: the 4-tuple's 3rd slot is the storage; default to 'json'
           for unknown keys so we never build SQL around a bad column. */
        return (string)(TIER_CAPS[$k][2] ?? 'json');
    }
}

/**
 * Default 0/1 for a capability key when its value is absent (json key
 * missing, or the Capabilities column not yet migrated).
 *
 * @param string $k Capability key.
 * @return int 0 | 1.
 */
if (!function_exists('tierCapDefault')) {
    function tierCapDefault(string $k): int
    {
        /* ELI5: what value should we assume if this cap was never set?
           WHY: the 4-tuple's 4th slot is the default; coerce to 0/1 so an
           un-migrated install reads every json cap at a sane baseline. */
        return ((int)(TIER_CAPS[$k][3] ?? 0)) === 1 ? 1 : 0;
    }
}

/**
 * The capability keys backed by their own tblAccessTiers TINYINT column
 * (the 7 originals). These drive the existing dynamic column SQL.
 *
 * @return string[] Exact column names, in TIER_CAPS order.
 */
if (!function_exists('tierCapColumnKeys')) {
    function tierCapColumnKeys(): array
    {
        /* ELI5: list the caps that have their own DB column.
           WHY: the create/update SQL binds these as real columns; the
           emit selects them as the contract's camelCase keys. */
        $out = [];
        foreach (TIER_CAPS as $k => $_t) {
            if (tierCapStorage($k) === 'column') { $out[] = $k; }
        }
        return $out;
    }
}

/**
 * The capability keys stored inside the Capabilities JSON column (every
 * cap added since the json migration). Empty until the first json cap
 * is registered — so the surfaces behave exactly as before until then.
 *
 * @return string[] JSON-backed capability keys, in TIER_CAPS order.
 */
if (!function_exists('tierCapJsonKeys')) {
    function tierCapJsonKeys(): array
    {
        /* ELI5: list the caps that live inside the JSON column.
           WHY: create/update assemble these into one json_encode() bind;
           the emit decodes them and adds each as a camelCase key. */
        $out = [];
        foreach (TIER_CAPS as $k => $_t) {
            if (tierCapStorage($k) === 'json') { $out[] = $k; }
        }
        return $out;
    }
}

/**
 * Read a capability's effective 0/1 value off a fetched tier row.
 *
 * Column-backed caps read straight off the row's matching key.
 * JSON-backed caps decode tblAccessTiers.Capabilities and read the key,
 * falling back to the TIER_CAPS default when absent / unmigrated / the
 * JSON is malformed. Used by /manage/tiers.php to render every checkbox's
 * current state regardless of where the cap is stored.
 *
 * @param array  $tierRow A row from tblAccessTiers (assoc).
 * @param string $k       Capability key.
 * @return int 0 | 1.
 */
if (!function_exists('tierCapRead')) {
    function tierCapRead(array $tierRow, string $k): int
    {
        if (tierCapStorage($k) === 'column') {
            /* ELI5: column-backed cap — read its own field if present.
               WHY: keeps the 7 originals identical to today; if a caller
               selected without the column we degrade to the default. */
            return array_key_exists($k, $tierRow)
                ? ((int)$tierRow[$k] === 1 ? 1 : 0)
                : tierCapDefault($k);
        }
        /* ELI5: json-backed cap — decode the Capabilities blob, read key.
           WHY: one JSON column holds every future cap; a NULL/absent
           column or malformed JSON gracefully yields the cap's default
           (https://www.php.net/manual/en/function.json-decode.php). */
        $raw = $tierRow['Capabilities'] ?? null;
        if ($raw === null || $raw === '') {
            return tierCapDefault($k);
        }
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded) || !array_key_exists($k, $decoded)) {
            return tierCapDefault($k);
        }
        return !empty($decoded[$k]) ? 1 : 0;
    }
}

/**
 * Has the Capabilities JSON column been migrated onto tblAccessTiers yet?
 *
 * The CRUD writers + the public emit MUST gate every read/write of the
 * Capabilities column on this — mysqli runs under STRICT mode app-wide
 * (includes/db_mysql.php), so SELECTing / INSERTing a non-existent column
 * THROWS rather than returning false. INFORMATION_SCHEMA is queried with
 * a bound param (rule #5). Result is request-cached so repeated calls in
 * one request (e.g. the emit looping tiers) cost a single round-trip.
 *
 * @param \mysqli $db Live connection from getDbMysqli().
 * @return bool true once migrate-add-tier-capabilities-json.php has run.
 */
if (!function_exists('tierCapsColumnExists')) {
    function tierCapsColumnExists(\mysqli $db): bool
    {
        /* ELI5: remember the answer for the rest of this request.
           WHY: the column either exists or it doesn't for a whole request;
           caching avoids re-hitting INFORMATION_SCHEMA per tier row. */
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'tblAccessTiers'
                    AND COLUMN_NAME = 'Capabilities' LIMIT 1"
            );
            $stmt->execute();
            $cached = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            /* ELI5: if the probe itself fails, assume not-migrated.
               WHY: false is the safe degrade — writers skip the column,
               readers fall back to defaults; never let the probe throw up. */
            $cached = false;
        }
        return $cached;
    }
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
