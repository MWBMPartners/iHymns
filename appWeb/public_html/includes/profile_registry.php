<?php

declare(strict_types=1);

/**
 * profile_registry.php - the ONE list of every personal setting and its rules
 * ============================================================================
 * Account/profile rewrite step C4 (epic #2180). Pure data and pure functions:
 * no database, no superglobals, no I/O, so a test can call it and a native app
 * can download it (`GET /api?action=profile_schema`, step C5).
 *
 * ELI5
 * ----
 * iHymns remembers things about a person: their theme, their text size, their
 * favourites, their set lists. Until now each web module, the server and each
 * phone app kept its own idea of which of those follow the person, what values
 * are allowed, and what to do at sign-out. They disagreed. This file is the
 * single written-down answer. Each entry says: what it is called, what values
 * are allowed, whether it stays on the device or follows the account, which
 * kinds of app use it, how it is stored and synced, and what happens when the
 * person signs out, exports their data or deletes their account.
 *
 * Detailed
 * --------
 * Each entry has a platform-neutral id, `section.key` (`appearance.theme`), never
 * a web storage key or an Apple preference name. The web storage key it used to
 * live under is `legacyWebKey` (used for the one-time import and for the lockstep
 * guard against js/modules/settings-sync-registry.js).
 *
 * Fields (every entry has all of them):
 *   id, section, key     identity. `id` is always `section.key`.
 *   type                 bool | int | float | enum | string | json | list
 *   (constraints)        enum: allowed[]. int/float: min, max (int also step, from).
 *                        string: pattern (no delimiters, so any language can use it), maxLength. json: shape{key=>spec}.
 *                        list: item{spec}, maxItems.
 *   default              the value when nothing was chosen (must itself be valid)
 *   scope                device = never leaves the device;
 *                        account = follows the signed-in person;
 *                        shared = visible to others (e.g. through shares)
 *   platforms            ['web','apple','android','tv'] subset, or ['all']
 *   storage              where the SERVER keeps it: profile_items (the
 *                        tblUserProfileItems table), table:<name> (a content
 *                        table of its own), or none (device scope)
 *   sync                 profile_sync (small items) | content_sync (favourites
 *                        and set lists, which keep their own protocol) | none
 *   cap                  largest number of items/entries, or null
 *   export               included in "download my data" (server only)
 *   erase                what account deletion does: delete | unlink | none (server only)
 *   claim                an anonymous visitor's local value is claimed by the
 *                        account at first sign-in
 *   clearAtSignOut       the browser clears it when the person signs out
 *   cookie               travels in the shared cross-site settings cookie (rule
 *                        #52); must equal membership of settings-sync-registry.js
 *   legacyWebKey         the old web storage key, or null
 *   since                schema version the entry first appeared in
 *
 * ADDITIVE ONLY: entries are added, never removed or renamed. A rename becomes a
 * new entry plus an alias in `profileRegistryAliases()`. Native apps bundle a
 * snapshot (appApple/.../profile-schema.json) and refresh it later; a test keeps
 * the snapshot equal to this file (step C6).
 *
 * The three server-only fields (storage, export, erase) are removed from the public
 * projection by profileRegistryPublic().
 *
 * @see tests/php/test-profile-registry.php
 * @see tests/test-profile-registry-lockstep.js
 * @see .claude/account-profile-rewrite-plan.md  Phase C, steps C4 to C6
 */

/* ===== The vocabularies. The test checks every entry uses only these. ===== */

/** Bump when an entry is ADDED (never for a change to an existing one). */
const PROFILE_REGISTRY_SCHEMA_VERSION = 1;

const PROFILE_TYPES     = ['bool', 'int', 'float', 'enum', 'string', 'json', 'list'];
const PROFILE_SCOPES    = ['device', 'account', 'shared'];
const PROFILE_PLATFORMS = ['web', 'apple', 'android', 'tv'];
const PROFILE_STORAGES  = ['profile_items', 'table:tblUserFavorites', 'table:tblUserSetlists', 'table:tblUserCustomTags', 'table:tblSongHistory', 'none'];
const PROFILE_SYNCS     = ['profile_sync', 'content_sync', 'none'];
const PROFILE_ERASES    = ['delete', 'unlink', 'none'];

/** Fields removed from the public projection (the server's own business). */
const PROFILE_SERVER_ONLY_FIELDS = ['storage', 'export', 'erase'];

/**
 * Build one entry with every field present, from a short spec. Anything not
 * given gets the plain default (a device-only, non-syncing entry), so an entry
 * only states what makes it special.
 *
 * @param array<string,mixed> $o
 * @return array<string,mixed>
 */
function _profileEntry(string $section, string $key, string $type, array $o): array
{
    $e = [
        'id'             => $section . '.' . $key,
        'section'        => $section,
        'key'            => $key,
        'type'           => $type,
        'default'        => null,
        'scope'          => 'device',
        'platforms'      => ['all'],
        'storage'        => 'none',
        'sync'           => 'none',
        'cap'            => null,
        'export'         => false,
        'erase'          => 'none',
        'claim'          => false,
        'clearAtSignOut' => false,
        'cookie'         => false,
        'legacyWebKey'   => null,
        'since'          => 1,
    ];
    return array_merge($e, $o);
}

/** An account-scope, small-item setting that syncs through profile_sync. */
function _profileAccount(string $section, string $key, string $type, array $o): array
{
    return _profileEntry($section, $key, $type, array_merge([
        'scope'   => 'account',
        'storage' => 'profile_items',
        'sync'    => 'profile_sync',
        'export'  => true,
        'erase'   => 'delete',
        'claim'   => false,
    ], $o));
}

/**
 * Every entry, in a stable order, keyed by id.
 *
 * @return array<string,array<string,mixed>>
 */
function profileRegistry(): array
{
    static $reg = null;
    if ($reg !== null) {
        return $reg;
    }
    $display = [
        'fontSize'         => ['type' => 'float', 'min' => 0.5, 'max' => 5.0],
        'lineSpacing'      => ['type' => 'enum', 'allowed' => ['compact', 'normal', 'spacious']],
        'showVerseNumbers' => ['type' => 'bool'],
        'highlightChorus'  => ['type' => 'bool'],
        'autoScrollSpeed'  => ['type' => 'float', 'min' => 0.0, 'max' => 1000.0],
    ];
    $entries = [
        /* ---- the fourteen web display settings (same set as settings-sync-registry.js) ---- */
        _profileAccount('appearance', 'theme', 'enum', [
            'allowed' => ['system', 'light', 'dark', 'high-contrast'], 'default' => 'system',
            'platforms' => ['web', 'apple'], 'legacyWebKey' => 'ihymns_theme', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('appearance', 'fontSize', 'int', [
            'min' => 14, 'max' => 28, 'step' => 2, 'from' => 14, 'default' => 18,
            'platforms' => ['web'], 'legacyWebKey' => 'ihymns_fontSize', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('appearance', 'reduceMotion', 'bool', [
            'default' => false, 'legacyWebKey' => 'ihymns_reduceMotion', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('appearance', 'reduceTransparency', 'bool', [
            'default' => false, 'platforms' => ['web'], 'legacyWebKey' => 'ihymns_reduceTransparency', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('appearance', 'transition', 'enum', [
            'allowed' => ['none', 'fade', 'crossfade', 'slide', 'scale', 'lift', 'depth', 'blur'], 'default' => 'none',
            'platforms' => ['web'], 'legacyWebKey' => 'ihymns_transition', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('library', 'defaultSongbook', 'string', [
            'pattern' => '^[A-Za-z0-9]{1,10}$', 'maxLength' => 10, 'default' => null,
            'legacyWebKey' => 'ihymns_default_songbook', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('library', 'autoUpdateSongs', 'bool', [
            'default' => true, 'platforms' => ['web'], 'legacyWebKey' => 'ihymns_auto_update_songs', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('search', 'numpadLiveSearch', 'bool', [
            'default' => false, 'platforms' => ['web'], 'legacyWebKey' => 'ihymns_numpad_live_search', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('search', 'includeLyrics', 'bool', [
            'default' => false, 'legacyWebKey' => 'ihymns_search_lyrics', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('reading', 'display', 'json', [
            'shape' => $display, 'default' => null,
            'platforms' => ['web'], 'legacyWebKey' => 'ihymns_display', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('appearance', 'cvd', 'enum', [
            'allowed' => ['protanopia', 'deuteranopia', 'tritanopia', 'achromatopsia'], 'default' => null,
            'platforms' => ['web', 'apple'], 'legacyWebKey' => 'ihymns_cvd_mode', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('appearance', 'linkEmphasis', 'enum', [
            'allowed' => ['on'], 'default' => null,
            'platforms' => ['web'], 'legacyWebKey' => 'ihymns_link_emphasis', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('input', 'keyboardShortcuts', 'bool', [
            'default' => true, 'platforms' => ['web'], 'legacyWebKey' => 'ihymns_keyboardShortcuts', 'cookie' => true, 'claim' => true,
        ]),
        _profileAccount('live', 'idleTimeoutMins', 'int', [
            'min' => 5, 'max' => 240, 'step' => 1, 'from' => 5, 'default' => null,
            'platforms' => ['web'], 'legacyWebKey' => 'liveIdleTimeoutMins', 'cookie' => true, 'claim' => true,
        ]),

        /* ---- chord display (#2176): follow the account, but never the shared cookie ---- */
        _profileAccount('chords', 'notation', 'enum', [
            'allowed' => ['letters', 'nashville', 'solfege', 'solfege-fixed'], 'default' => 'letters',
            'legacyWebKey' => 'ihymns_chord_notation', 'claim' => true,
        ]),
        _profileAccount('chords', 'columns', 'bool', [
            'default' => false, 'platforms' => ['web'], 'legacyWebKey' => 'ihymns_chord_columns', 'claim' => true,
        ]),

        /* ---- the Apple app's reading preferences (the range is confirmed when the Apple adapter, step I4, is built) ---- */
        _profileAccount('reading', 'textScale', 'float', [
            'min' => 0.5, 'max' => 5.0, 'default' => 1.0, 'platforms' => ['apple'],
        ]),
        _profileAccount('reading', 'showChords', 'bool', [
            'default' => true, 'platforms' => ['apple'],
        ]),
        _profileAccount('reading', 'dyslexia', 'bool', [
            'default' => false, 'platforms' => ['apple'],
        ]),

        /* ---- the person's choice of period for "Your most viewed" (#2175, decision O5) ---- */
        _profileAccount('home', 'mostViewedPeriod', 'enum', [
            'allowed' => ['30d', '90d', '12m', 'all'], 'default' => '12m',
        ]),

        /* ---- content: favourites, set lists, custom tags and history keep their own tables and their
               own sync protocol. They are listed so sign-out clearing reads ONE list (decision O2). ---- */
        _profileEntry('content', 'favorites', 'list', [
            'scope' => 'account', 'storage' => 'table:tblUserFavorites', 'sync' => 'content_sync', 'cap' => 500,
            'export' => true, 'erase' => 'delete', 'claim' => true, 'clearAtSignOut' => true,
            'legacyWebKey' => 'ihymns_favorites', 'item' => ['type' => 'string'], 'maxItems' => 500,
        ]),
        _profileEntry('content', 'setlists', 'list', [
            'scope' => 'account', 'storage' => 'table:tblUserSetlists', 'sync' => 'content_sync', 'cap' => null,
            'export' => true, 'erase' => 'delete', 'claim' => true, 'clearAtSignOut' => true,
            'legacyWebKey' => 'ihymns_setlists', 'item' => ['type' => 'json'], 'maxItems' => null,
        ]),
        _profileEntry('content', 'customTags', 'list', [
            'scope' => 'account', 'storage' => 'table:tblUserCustomTags', 'sync' => 'content_sync', 'cap' => 200,
            'export' => true, 'erase' => 'delete', 'claim' => true, 'clearAtSignOut' => true,
            'legacyWebKey' => 'ihymns_custom_tags', 'item' => ['type' => 'string'], 'maxItems' => 200,
        ]),
        _profileEntry('content', 'history', 'list', [
            'scope' => 'account', 'storage' => 'table:tblSongHistory', 'sync' => 'content_sync', 'cap' => 50,
            'export' => true, 'erase' => 'unlink', 'claim' => true, 'clearAtSignOut' => true,
            'legacyWebKey' => 'ihymns_history', 'item' => ['type' => 'string'], 'maxItems' => 50,
        ]),

        /* ---- device scope: documentation only. These NEVER leave the device. ---- */
        _profileEntry('device', 'midiPedal', 'bool', ['default' => false, 'platforms' => ['web'], 'legacyWebKey' => 'ihymns_midiPedal']),
        _profileEntry('device', 'offlineIncludeAudio', 'bool', ['default' => false, 'platforms' => ['web'], 'legacyWebKey' => 'ihymns_offline_include_audio']),
        _profileEntry('device', 'analyticsConsent', 'string', ['platforms' => ['web', 'apple'], 'legacyWebKey' => 'ihymns_analytics_consent', 'pattern' => '^[a-z]{1,16}$', 'maxLength' => 16]),
        _profileEntry('device', 'disclaimerAccepted', 'bool', ['default' => false, 'platforms' => ['web'], 'legacyWebKey' => 'ihymns_disclaimer_accepted']),
        _profileEntry('device', 'pwaBannerDismissed', 'bool', ['default' => false, 'platforms' => ['web'], 'legacyWebKey' => 'ihymns_pwa_banner_dismissed']),
        _profileEntry('device', 'searchHistory', 'list', ['platforms' => ['web', 'apple'], 'legacyWebKey' => 'ihymns_search_history', 'item' => ['type' => 'string'], 'maxItems' => 20]),
        _profileEntry('device', 'syncBookkeeping', 'json', ['platforms' => ['web'], 'legacyWebKey' => 'ihymns_xsite_stamps', 'shape' => []]),
        _profileEntry('device', 'ownerId', 'string', ['platforms' => ['web'], 'legacyWebKey' => 'ihymns_owner_id', 'pattern' => '^[A-Za-z0-9-]{8,64}$', 'maxLength' => 64]),
    ];
    $reg = [];
    foreach ($entries as $e) {
        $reg[$e['id']] = $e;
    }
    return $reg;
}

/** The schema version: how many ADDITIONS the list has had. A client compares it to what it bundled. */
function profileRegistryVersion(): int
{
    return PROFILE_REGISTRY_SCHEMA_VERSION;
}

/**
 * Renamed entries: old id => new id. Empty today (version 1). A rename is a NEW
 * entry plus a row here, never an edit of an existing id.
 *
 * @return array<string,string>
 */
function profileRegistryAliases(): array
{
    return [];
}

/** One entry by section and key, or null when unknown (an old alias is followed). @return ?array<string,mixed> */
function profileRegistryEntry(string $section, string $key): ?array
{
    $id = $section . '.' . $key;
    $reg = profileRegistry();
    $id = profileRegistryAliases()[$id] ?? $id;
    return $reg[$id] ?? null;
}

/**
 * PURE: is `$value` acceptable for this spec? `$spec` is an entry, or a nested
 * spec inside a json shape or list item.
 *
 * Canonical values are real JSON types: a bool is `true`/`false`, a number is an
 * int or float, an enum or string is a string. A client that stores text (the web's
 * localStorage) converts before sending. `null` is never a valid VALUE: "no value"
 * is a delete, not a set.
 *
 * @param array<string,mixed> $spec
 */
function profileValidateSpec(array $spec, mixed $value): bool
{
    switch ($spec['type'] ?? '') {
        case 'bool':
            return is_bool($value);
        case 'int':
            if (!is_int($value)) { return false; }
            $step = (int)($spec['step'] ?? 1);
            $from = (int)($spec['from'] ?? ($spec['min'] ?? 0));
            return $value >= (int)$spec['min'] && $value <= (int)$spec['max'] && $step >= 1 && (($value - $from) % $step) === 0;
        case 'float':
            if (!is_int($value) && !is_float($value)) { return false; }
            return is_finite((float)$value) && (float)$value >= (float)$spec['min'] && (float)$value <= (float)$spec['max'];
        case 'enum':
            return is_string($value) && in_array($value, (array)$spec['allowed'], true);
        case 'string':
            if (!is_string($value) || $value === '') { return false; }
            if (isset($spec['maxLength']) && mb_strlen($value) > (int)$spec['maxLength']) { return false; }
            /* The pattern is stored WITHOUT delimiters so a JavaScript or Swift client can
               use the same text. `D` makes `$` mean the true end (no trailing newline). */
            return !isset($spec['pattern']) || preg_match('/' . str_replace('/', '\/', (string)$spec['pattern']) . '/D', $value) === 1;
        case 'json':
            if (!is_array($value) || ($value !== [] && array_is_list($value))) { return false; }
            $shape = (array)($spec['shape'] ?? []);
            if ($shape === []) { return true; }   /* an open object: any shape (device bookkeeping only) */
            foreach ($value as $k => $v) {
                if (!isset($shape[$k]) || !profileValidateSpec($shape[$k], $v)) { return false; }
            }
            return true;
        case 'list':
            if (!is_array($value) || !array_is_list($value)) { return false; }
            if (isset($spec['maxItems']) && $spec['maxItems'] !== null && count($value) > (int)$spec['maxItems']) { return false; }
            foreach ($value as $v) {
                if (!profileValidateSpec((array)$spec['item'], $v)) { return false; }
            }
            return true;
    }
    return false;
}

/** Is `$value` valid for the entry `section.key`? An unknown entry is never valid. */
function profileRegistryValidate(string $section, string $key, mixed $value): bool
{
    $e = profileRegistryEntry($section, $key);
    return $e !== null && profileValidateSpec($e, $value);
}

/**
 * The public projection: every entry with the server-only fields removed, plus
 * the schema version. This is what `profile_schema` serves and what the Apple
 * snapshot holds. Constraint fields and the order are exactly as in the registry.
 *
 * @return array{schemaVersion:int, entries:list<array<string,mixed>>, aliases:array<string,string>}
 */
function profileRegistryPublic(): array
{
    $entries = [];
    foreach (profileRegistry() as $e) {
        foreach (PROFILE_SERVER_ONLY_FIELDS as $f) { unset($e[$f]); }
        $entries[] = $e;
    }
    return ['schemaVersion' => profileRegistryVersion(), 'entries' => $entries, 'aliases' => profileRegistryAliases()];
}
