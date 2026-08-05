<?php

declare(strict_types=1);

/**
 * iHymns — Licence-type registry (#459, built by #1769 P2)
 * =======================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * This is the ONE place the app asks "what licence types exist, what does each
 * legally COVER, and which membership TIER (if any) does holding it grant?".
 * Before this, that vocabulary was copy-pasted in six places (organisations.php,
 * restrictions.php, my-organisations.php, the ccli_validator licence→tier map,
 * and two divergent schema comments) — which had already drifted (my-orgs alone
 * knew about `mrl`). #1769 P1 created the `tblLicenceTypes` table; this reader
 * makes it THE source of truth. The old hardcoded maps survive only as the
 * fallback for an install whose migration hasn't run yet.
 *
 * DETAILED / DORMANT + FALLBACK-SAFE (rule #28C)
 * ---------------------------------------------
 * The three docroots share one MySQL and run web-applied migrations, so any
 * read here must degrade to the pre-migration behaviour, never throw under
 * STRICT mysqli. `licenceTypesAll()` is existence-gated and try/caught: if the
 * table is absent, unreadable, or empty it returns `LICENCE_TYPES_FALLBACK` —
 * the byte-exact P1 seeds — so an un-migrated docroot behaves identically to a
 * freshly-migrated one with untouched seeds. Every reader is wrapped so it can
 * never throw. Nothing in P2 ENFORCES on this data yet (P3+ wires the emitters);
 * this commit only makes the vocabulary single-sourced.
 *
 * @link .claude/gating-p2-design.md §"licence_registry.php"  the design
 * @see  appWeb/.sql/migrate-add-gating-facts-and-licence-types.php  the P1 table + seeds this mirrors
 * @see  appWeb/public_html/includes/ccli_validator.php  the licence→tier fallback map ($licenceToTier)
 * @see  tests/php/test-licence-registry.php  the seed-parity + fallback guard
 */

/* Block direct HTTP access — this is a library, never a page (mirrors
   media_identifiers.php's guard). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* =========================================================================
 * Coverage vocabulary — the growable allow-list CoversJson keys validate
 * against (rule #20: VARCHAR-in-JSON + central map, never ENUM). Keyed
 * coverageKind => human label (label is for P4's Licences tab; nothing renders
 * it in P2). A newer docroot may write a kind this list doesn't know yet —
 * licenceTypesAll() drops the unknown kind from that row's coverage set rather
 * than the whole row, so a forward-migrated env degrades gracefully.
 * ========================================================================= */
if (!defined('LICENCE_COVERAGE_KINDS')) {
    define('LICENCE_COVERAGE_KINDS', [
        'lyrics_display'     => 'Display lyrics on screen',
        'lyrics_print'       => 'Print / reproduce lyric text',
        'music_reproduction' => 'Reproduce the musical work (sheet music, chords, MusicXML, MIDI)',
        'audio_playback'     => 'Play recorded / rendered audio',
    ]);
}

/** True when $kind is an allow-listed coverage kind. */
function licenceCoverageKindValid(string $kind): bool
{
    return array_key_exists($kind, LICENCE_COVERAGE_KINDS);
}

/**
 * True when $key matches the tblLicenceTypes.LicenceKey grammar
 * (`^[a-z][a-z0-9_]{1,29}$`) the schema COMMENT promises. Never index the
 * registry by a malformed DB key (mirrors tierCapsMergeUnion's key hygiene).
 */
function licenceKeyValid(string $key): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9_]{1,29}$/', $key);
}

/* =========================================================================
 * LICENCE_TYPES_FALLBACK — byte-derived from the P1 seeds (schema.sql /
 * migrate-add-gating-facts-and-licence-types.php). Returned whenever the live
 * table can't be read, so an un-migrated install behaves like a migrated one.
 * tests/php/test-licence-registry.php pins this against the parsed schema seeds
 * (a seed edit without a fallback edit — or vice versa — goes red).
 * ========================================================================= */
if (!defined('LICENCE_TYPES_FALLBACK')) {
    define('LICENCE_TYPES_FALLBACK', [
        'ccli' => [
            'key' => 'ccli', 'label' => 'CCLI',
            'description' => 'Christian Copyright Licensing International -- Church Copyright Licence. Covers displaying and printing copyrighted LYRICS for congregational use. Licence number required.',
            'confersTier' => 'ccli',
            'covers' => ['lyrics_display' => [], 'lyrics_print' => []],
            'requiresNumber' => true, 'authority' => 'ccli', 'authorityRef' => null,
            'enabled' => true, 'sortOrder' => 10, 'source' => 'system',
        ],
        'mrl' => [
            'key' => 'mrl', 'label' => 'MRL',
            'description' => 'Music Reproduction Licence (CCLI) -- covers reproducing the MUSICAL WORK: sheet music, chord charts, MusicXML, MIDI (#1768). Licence number required.',
            'confersTier' => null,
            'covers' => ['music_reproduction' => []],
            'requiresNumber' => true, 'authority' => 'ccli', 'authorityRef' => null,
            'enabled' => true, 'sortOrder' => 20, 'source' => 'system',
        ],
        'ihymns_basic' => [
            'key' => 'ihymns_basic', 'label' => 'iHymns Basic',
            'description' => 'Free iHymns plan licence -- public-domain songs only. Confers the free tier; carries no legal coverage of its own.',
            'confersTier' => 'free', 'covers' => null,
            'requiresNumber' => false, 'authority' => 'ihymns', 'authorityRef' => null,
            'enabled' => true, 'sortOrder' => 30, 'source' => 'system',
        ],
        'ihymns_pro' => [
            'key' => 'ihymns_pro', 'label' => 'iHymns Pro',
            'description' => 'Paid iHymns plan licence -- full catalogue access. Confers the premium tier; carries no legal coverage of its own.',
            'confersTier' => 'premium', 'covers' => null,
            'requiresNumber' => false, 'authority' => 'ihymns', 'authorityRef' => null,
            'enabled' => true, 'sortOrder' => 40, 'source' => 'system',
        ],
        'custom' => [
            'key' => 'custom', 'label' => 'Custom',
            'description' => 'Free-form licence recorded by an organisation. Coverage and tier conferral are set per install by a curator.',
            'confersTier' => null, 'covers' => null,
            'requiresNumber' => false, 'authority' => null, 'authorityRef' => null,
            'enabled' => true, 'sortOrder' => 50, 'source' => 'system',
        ],
    ]);
}

/**
 * Does tblLicenceTypes exist on this database? Existence-gated + request-cached,
 * verbatim shape of tierCapsColumnExists() — any throw ⇒ false (degrade to the
 * fallback map, never a STRICT throw). Mirrors rule #28C.
 */
function licenceTypesTableExists(\mysqli $db): bool
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblLicenceTypes' LIMIT 1"
        );
        $stmt->execute();
        $cache = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cache = false;
    }
    return $cache;
}

/**
 * THE licence-type registry, keyed by LicenceKey. Reads the live Enabled=1 rows
 * (ordered SortOrder, LicenceKey); on ANY failure path — table absent, throw,
 * zero rows — returns LICENCE_TYPES_FALLBACK (rule #28C). Request-cached when
 * $db is null (production); an explicit $db bypasses the cache (test seam).
 *
 * @param ?\mysqli $db null = getDbMysqli() + request cache; explicit = uncached.
 * @return array<string,array{key:string,label:string,description:string,confersTier:?string,covers:?array,requiresNumber:bool,authority:?string,authorityRef:?string,enabled:bool,sortOrder:int,source:string}>
 */
function licenceTypesAll(?\mysqli $db = null): array
{
    static $cache = null;
    if ($db === null && $cache !== null) { return $cache; }

    $out = null;
    try {
        $conn = $db ?? getDbMysqli();
        if (licenceTypesTableExists($conn)) {
            $res = $conn->query(
                'SELECT LicenceKey, Label, Description, ConfersTier, CoversJson,
                        RequiresNumber, Authority, AuthorityRef, Enabled, SortOrder, Source
                   FROM tblLicenceTypes
                  WHERE Enabled = 1
                  ORDER BY SortOrder ASC, LicenceKey ASC'
            );
            if ($res !== false) {
                $rows = [];
                foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
                    $key = (string)$r['LicenceKey'];
                    if (!licenceKeyValid($key)) { continue; } // never index by a malformed key
                    $covers = null;
                    if ($r['CoversJson'] !== null && $r['CoversJson'] !== '') {
                        $decoded = json_decode((string)$r['CoversJson'], true);
                        if (is_array($decoded)) {
                            $covers = [];
                            foreach ($decoded as $ck => $qual) {
                                if (licenceCoverageKindValid((string)$ck)) { // drop unknown kinds, keep the row
                                    $covers[(string)$ck] = is_array($qual) ? $qual : [];
                                }
                            }
                        }
                    }
                    $rows[$key] = [
                        'key' => $key,
                        'label' => (string)$r['Label'],
                        'description' => (string)$r['Description'],
                        'confersTier' => ($r['ConfersTier'] !== null && $r['ConfersTier'] !== '') ? (string)$r['ConfersTier'] : null,
                        'covers' => $covers,
                        'requiresNumber' => (int)$r['RequiresNumber'] === 1,
                        'authority' => ($r['Authority'] !== null && $r['Authority'] !== '') ? (string)$r['Authority'] : null,
                        'authorityRef' => ($r['AuthorityRef'] !== null && $r['AuthorityRef'] !== '') ? (string)$r['AuthorityRef'] : null,
                        'enabled' => (int)$r['Enabled'] === 1,
                        'sortOrder' => (int)$r['SortOrder'],
                        'source' => (string)$r['Source'],
                    ];
                }
                if ($rows !== []) { $out = $rows; }
            }
        }
    } catch (\Throwable $_e) {
        $out = null; // fall through to fallback
    }

    $result = $out ?? LICENCE_TYPES_FALLBACK;
    if ($db === null) { $cache = $result; }
    return $result;
}

/** One licence type's row, or null. */
function licenceType(?\mysqli $db, string $key): ?array
{
    return licenceTypesAll($db)[$key] ?? null;
}

/**
 * The tblAccessTiers.Name a licence CONFERS, or null. NULL means BOTH "no such
 * licence row" AND "row confers nothing" — deliberately identical, because the
 * one consumer (ccli_validator's $licenceToTier fallback) treats both the same
 * way, which is what makes the #1769 P2 re-point a provable no-op.
 */
function licenceConfersTier(?\mysqli $db, string $key): ?string
{
    return licenceType($db, $key)['confersTier'] ?? null;
}

/** True when licence $key legally covers $coverageKind. */
function licenceCovers(?\mysqli $db, string $key, string $coverageKind): bool
{
    $t = licenceType($db, $key);
    return $t !== null && is_array($t['covers']) && array_key_exists($coverageKind, $t['covers']);
}

/**
 * Which per-song RIGHTS-FACT column a licence's coverage set maps to (#1769 P4
 * D5). The ONE fold the DM-2 derivation migration, its registry probe and its
 * test all call — never re-typed (rule #22/#35), so the migration and its
 * completeness probe can never disagree on the mapping.
 *
 * Mapping (LYRICS precedence — the seeds are disjoint, so precedence only
 * matters for a hypothetical future both-covering licence, which is rarer than
 * a lyrics-only one and is the safer default to record first):
 *   lyrics_display OR lyrics_print   -> 'LyricsRightsLicenceKey'
 *   music_reproduction (and no lyrics)-> 'MusicRightsLicenceKey'
 *   neither (plan-conferral or audio-only, e.g. ihymns_basic/pro/custom)
 *                                     -> null  (no fact column; skip + report)
 *
 * `audio_playback` deliberately maps to NO fact column — the two fact columns
 * are lyrics/music rights only; audio gating stays a tier cap (CanPlayAudio),
 * not a per-song rights fact.
 *
 * @param ?array $covers The licence's decoded coverage map (kind => qualifier),
 *                       i.e. a row's `covers` from licenceTypesAll(); null/[] ⇒ null.
 * @return ?string       'LyricsRightsLicenceKey' | 'MusicRightsLicenceKey' | null
 */
function rightsFactColumnForLicence(?array $covers): ?string
{
    if (!is_array($covers) || $covers === []) { return null; }
    if (array_key_exists('lyrics_display', $covers) || array_key_exists('lyrics_print', $covers)) {
        return 'LyricsRightsLicenceKey';
    }
    if (array_key_exists('music_reproduction', $covers)) {
        return 'MusicRightsLicenceKey';
    }
    return null;
}

/** All licence keys, in registry (SortOrder) order. */
function licenceTypeKeys(?\mysqli $db = null): array
{
    return array_keys(licenceTypesAll($db));
}

/**
 * The picker shape the admin pages consume: key => ['label'=>…, 'description'=>…].
 * When $includeNone, prepend a 'none' entry — the column empty-state
 * (tblOrganisations.LicenceType DEFAULT 'none'), NOT a registry row (the
 * absence of a licence is the absence of a row, schema.sql).
 *
 * @return array<string,array{label:string,description:string}>
 */
function licenceTypesForPicker(?\mysqli $db = null, bool $includeNone = false): array
{
    $out = [];
    if ($includeNone) {
        $out['none'] = ['label' => 'None', 'description' => 'No licence on file'];
    }
    foreach (licenceTypesAll($db) as $key => $def) {
        $out[$key] = ['label' => $def['label'], 'description' => $def['description']];
    }
    return $out;
}
