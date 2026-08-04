<?php

declare(strict_types=1);

/**
 * iHymns — Recording / Release / Product / Work identifier vocabulary (#1741 D5)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * This file is the ONE place iHymns writes down "what kinds of external ID can a
 * song or a work carry, and what does each one look like?" — a Luminate track can
 * have a Spotify id, an Apple Music id, an ISRC, a Gracenote id, and a dozen more,
 * all at once. Instead of adding a new database COLUMN every time we learn about
 * one more provider (the old `tblSongIdentityMap` shape — rule #28), the storage
 * side (`tblSongExternalIds`, migrate-song-external-ids.php) is a generic
 * key/value table, and THIS file is the allow-list that keeps "IdType" from
 * silently accepting typos or made-up provider names.
 *
 * DETAILED / WHY A SEPARATE FILE FROM musician_helpers.php
 * ----------------------------------------------------------------------------
 * `musician_helpers.php::CREDIT_IDENTIFIER_TYPES` already does the identical job
 * for PARTY (musician/composer) identifiers — one entry per authority-control
 * provider (VIAF, ORCID, GND, …), consumed by the "Other Identifiers" picker on
 * `/manage/musicians`. This file is its sibling for the other two grains the
 * catalogue-expansion epic (#1741) needs vocabularies for:
 *
 *   - RECORDING_EXTERNAL_ID_TYPES — the `tblSongExternalIds.IdType` allow-list.
 *     Recording/song-grain DSP + database + legacy provider IDs, PLUS a
 *     release/product "reserve" (decision A=c, media-identifiers-spec.md §4c):
 *     ICPN/GRid/UPC/EAN/catalog-number/MC_RELEASE/MC_RELEASE_GROUP/MC_PRODUCT ride the SAME
 *     recording-grain row as ingest provenance rather than a new `tblReleases`
 *     entity, because iHymns is a hymn-lyrics catalogue, not a commercial
 *     release database (owner-accepted scoping).
 *   - SONG_EXTERNAL_ID_SCOPES — the `tblSongExternalIds.IdScope` allow-list
 *     (recording | song | work | release | product | party). `work` and
 *     `party` are RESERVED slugs (decision Q6, spec §4/§4c: "reserve slugs …
 *     implement storage only where a scope already has a home") — this phase
 *     never writes a work- or party-scoped row into `tblSongExternalIds`,
 *     because those already have zero-schema-cost homes below.
 *   - WORK_IDENTIFIER_TYPES — a work identifier lives on ONE of two existing
 *     homes, never a new table: ISWC/BOWI/CCLI/MusicBrainz-Work are already
 *     `tblWorks` COLUMNS (uq_iswc / uq_bowi / uq_ccli / uq_mbwork); every PRO/
 *     royalty authority (ASCAP, BMI, SESAC, PRS, GEMA, SOCAN, APRA, …) plus HFA
 *     ride the pre-existing `tblSongRoyaltyIds.Authority` free-text column
 *     (#1140) at ZERO schema cost — Authority was already VARCHAR + growable,
 *     it simply never had a documented allow-list until now.
 *
 * Both maps are VARCHAR-not-ENUM app-validated vocabularies (CLAUDE.md rule
 * #20): growing them (a new DSP, a new PRO) is a one-line addition here, never
 * a migration. Neither map is wired into a live write path yet — that is the
 * P3 alias-URL resolver's job (explicitly OUT of scope for this storage-layer
 * commit); this file exists so P3 has one place to import from, and so
 * `tests/php/test-media-identifiers.php` has one place to guard.
 *
 * GROUNDING — every key below traces to an authoritative source, not a guess:
 *   - Recording/release/product entries: the Luminate Data "External IDs"
 *     entity model, transcribed into `.claude/media-identifiers-spec.md` §4b
 *     (owner-supplied 2026-08-02, since the Luminate KB article itself
 *     WebFetch-403s — this repo's grounding is the spec doc, not a re-guess).
 *   - Work entries: the same spec §4b (ISWC/BOWI/IPI) plus this repo's
 *     pre-existing `tblSongRoyaltyIds` PRO/HFA precedent (#1140).
 * Do NOT add a slug here that isn't traceable to one of those two sources.
 *
 * @link .claude/media-identifiers-spec.md              the spec (§4b taxonomy, §4c decisions)
 * @link .claude/catalogue-expansion-1741-plan.md        the #1741 P1 schema-convention precedent (§2)
 * @link appWeb/public_html/includes/musician_helpers.php the sibling PARTY registry (CREDIT_IDENTIFIER_TYPES)
 * @see appWeb/.sql/migrate-song-external-ids.php         the tblSongExternalIds migration (Deliverable 1)
 * @see appWeb/.sql/migrate-work-bowi.php                 the tblWorks.Bowi migration (Deliverable 2)
 *
 * #1749 FULL UNIFICATION — `tblSongExternalIds` (this file's vocabulary) is
 * now the recording-ID AUTHORITY; `tblSongs.Isrc` is a kept-in-sync
 * projection of it (includes/song_external_ids.php's
 * songExternalIdSyncIsrcDenorm()). `tblSongIdentityMap`'s four provider
 * columns are, by contrast, FROZEN LEGACY — superseded by this table,
 * absorbed one-way by the #1747 backfill, never written again; do not build
 * a live sync for them (D-3, non-blocking default —
 * .claude/catalogue-1741-1749-unification-plan.md §2.3). Their removal stays
 * gated on the #1010 DB-merge decision, same as always.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* =========================================================================
 * IdScope — tblSongExternalIds.IdScope allow-list (VARCHAR(20), rule #20)
 *
 * ELI5: "what KIND of thing does this id identify?" — a recording, the whole
 * song, the underlying work, a release, a commercial product (barcode-level),
 * or a party (person/group). Six buckets, matching the Luminate entity model
 * (spec §4b: Artist / Song / Recording / Musical Work / Release Group /
 * Release / Product).
 *
 * `work` and `party` are reserved (see file doc-block) — `tblSongExternalIds`
 * rows written by this phase (none — the table is dormant) or by the future
 * P3 resolver are expected to use `recording` most often, `song` for a
 * few legacy MC_SONG-shaped rows, and `release`/`product` for the barcode/
 * catalogue-number reserve. Keeping `work`/`party` in the SAME allow-list
 * (rather than omitting them) costs nothing and avoids a second migration if
 * a future feature ever wants a work- or party-scoped row on this same table.
 * ========================================================================= */
const SONG_EXTERNAL_ID_SCOPES = ['recording', 'song', 'work', 'release', 'product', 'party'];

/**
 * True when $scope is one of the six allow-listed IdScope values.
 *
 * ELI5: "is this a real scope name, or a typo?"
 */
function mediaIdentifierScopeValid(string $scope): bool
{
    return in_array($scope, SONG_EXTERNAL_ID_SCOPES, true);
}

/**
 * The whole IdScope allow-list, in declaration order.
 *
 * @return list<string>
 */
function mediaIdentifierScopes(): array
{
    return SONG_EXTERNAL_ID_SCOPES;
}

/* =========================================================================
 * RECORDING_EXTERNAL_ID_TYPES — tblSongExternalIds.IdType allow-list
 * (VARCHAR(40), rule #20). One entry per provider/database/legacy code.
 *
 * Per-field contract:
 *   - label           friendly display name.
 *   - scope            the CANONICAL SONG_EXTERNAL_ID_SCOPES value this type is
 *                      expected to carry (informational — the DB does not
 *                      cross-enforce IdScope against IdType; IdScope is its
 *                      own independently-validated column, mirroring how
 *                      tblSongComponents.Type and .Number are validated
 *                      independently of each other).
 *   - authority        the body that issues/operates this identifier
 *                      (free text, for curator-facing display only).
 *   - validate         a full PCRE pattern (delimiters included), or NULL
 *                      when no standard shape is documented (spec §4b lists
 *                      the identifier but not a format) — Deliverable 3's
 *                      "validation regex (where standard)" clause. A NULL
 *                      validator means "accept any non-empty value", never
 *                      "reject everything".
 *   - url              a "%s"-templated printf URL (bare id, to be
 *                      rawurlencode()'d by the caller, mirroring
 *                      creditIdentifierDisplayUrl()'s contract in
 *                      musician_helpers.php), or NULL when no confirmed
 *                      public per-id lookup page is known. Deliberately left
 *                      NULL for most DSP/database providers whose exact
 *                      deep-link shape (region prefixes, slug segments) this
 *                      repo cannot independently verify — filling these in
 *                      accurately is the P3 resolver's job, not this
 *                      storage-layer commit's (see file doc-block: "do NOT
 *                      invent"). Only filled where the shape is genuinely
 *                      well-known and stable (MusicBrainz, ISRC-via-
 *                      MusicBrainz, AcoustID, Spotify, Wikidata, IMDb,
 *                      Discogs, Deezer, YouTube Music).
 *   - urlTemplatable   bool — "in principle, could a bare id alone resolve to
 *                      a working public URL for this provider?" TRUE even
 *                      when `url` above is still NULL (not yet confirmed).
 *                      FALSE for providers whose URLs need MORE than the bare
 *                      id (a slug, a region, a username) or that have no
 *                      consumer-facing lookup at all (internal Luminate
 *                      legacy codes, Nielsen panel data, etc.) — a bare-id
 *                      template could never be correct for these regardless
 *                      of research effort.
 *
 * @link .claude/media-identifiers-spec.md §4b  the authoritative taxonomy every key below traces to
 * ========================================================================= */
const RECORDING_EXTERNAL_ID_TYPES = [
    /* ---- Industry IDs (spec §4b "Industry IDs") ---- */
    'isrc' => [
        'label' => 'ISRC', 'scope' => 'recording', 'authority' => 'IFPI / national ISRC agencies',
        'validate' => '/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', 'url' => 'https://musicbrainz.org/isrc/%s', 'urlTemplatable' => true,
    ],

    /* ---- Provider (DSP) IDs (spec §4b "Provider (DSP) IDs", 13 providers) ---- */
    'apple-music'   => ['label' => 'Apple Music',   'scope' => 'recording', 'authority' => 'Apple',                 'validate' => null, 'url' => null, 'urlTemplatable' => true],
    'spotify'       => ['label' => 'Spotify',       'scope' => 'recording', 'authority' => 'Spotify',               'validate' => '/^[A-Za-z0-9]{22}$/', 'url' => 'https://open.spotify.com/track/%s', 'urlTemplatable' => true],
    'sirius-xm'     => ['label' => 'SiriusXM',      'scope' => 'recording', 'authority' => 'SiriusXM',              'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'youtube-music' => ['label' => 'YouTube Music', 'scope' => 'recording', 'authority' => 'YouTube (Google)',      'validate' => null, 'url' => 'https://music.youtube.com/watch?v=%s', 'urlTemplatable' => true],
    'amazon-music'  => ['label' => 'Amazon Music',  'scope' => 'recording', 'authority' => 'Amazon',                'validate' => null, 'url' => null, 'urlTemplatable' => true],
    'deezer'        => ['label' => 'Deezer',        'scope' => 'recording', 'authority' => 'Deezer',                'validate' => '/^\d+$/', 'url' => 'https://www.deezer.com/track/%s', 'urlTemplatable' => true],
    'soundcloud'    => ['label' => 'SoundCloud',    'scope' => 'recording', 'authority' => 'SoundCloud',            'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'anghami'       => ['label' => 'Anghami',       'scope' => 'recording', 'authority' => 'Anghami',               'validate' => null, 'url' => null, 'urlTemplatable' => true],
    'melon'         => ['label' => 'Melon',         'scope' => 'recording', 'authority' => 'Melon (Kakao)',         'validate' => null, 'url' => null, 'urlTemplatable' => true],
    'flo'           => ['label' => 'FLO',           'scope' => 'recording', 'authority' => 'FLO (Dreamus)',         'validate' => null, 'url' => null, 'urlTemplatable' => true],
    'beatport'      => ['label' => 'Beatport',      'scope' => 'recording', 'authority' => 'Beatport',              'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'tencent'       => ['label' => 'Tencent Music', 'scope' => 'recording', 'authority' => 'Tencent Music (TME)',   'validate' => null, 'url' => null, 'urlTemplatable' => true],
    'epic'          => ['label' => 'Epic',          'scope' => 'recording', 'authority' => 'Epic',                  'validate' => null, 'url' => null, 'urlTemplatable' => true],

    /* ---- Database IDs (spec §4b "Database IDs", 10 providers incl. MusicBrainz/AcoustID,
       + 'genius' below as an 11th — a #1747 D5 addendum, NOT part of the original Luminate
       §4b list; see the entry's own comment for why it belongs here anyway) ---- */
    'musicbrainz-recording' => [
        'label' => 'MusicBrainz Recording', 'scope' => 'recording', 'authority' => 'MetaBrainz Foundation',
        'validate' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        'url' => 'https://musicbrainz.org/recording/%s', 'urlTemplatable' => true,
    ],
    'acoustid' => [
        'label' => 'AcoustID', 'scope' => 'recording', 'authority' => 'AcoustID / MetaBrainz',
        'validate' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        'url' => 'https://acoustid.org/track/%s', 'urlTemplatable' => true,
    ],
    'gracenote'     => ['label' => 'Gracenote',      'scope' => 'recording', 'authority' => 'Gracenote (Nielsen)',       'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'tms'           => ['label' => 'TMS',            'scope' => 'recording', 'authority' => 'TMS (Gracenote)',          'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'discogs'       => ['label' => 'Discogs',        'scope' => 'recording', 'authority' => 'Discogs',                  'validate' => '/^\d+$/', 'url' => 'https://www.discogs.com/release/%s', 'urlTemplatable' => true],
    /* ---- genius (#1747 D5 backfill) ----
       ELI5: Genius.com's own numeric song id — the number Genius's API and
       internal links use to identify a song/lyrics page. iHymns already had
       *storage* for this (tblSongIdentityMap.GeniusTrackId, #1066), it just
       was never registered as a validated IdType here — this entry is what
       lets that grandfathered column's data be mirrored into
       tblSongExternalIds (the D5 backfill, migrate-backfill-song-external-ids.php)
       under a recognised, allow-listed slug instead of a bare string nobody
       validates.
       DETAILED: `url` stays NULL — Genius song URLs are slug-based
       (https://genius.com/<artist>-<title>-lyrics), so a bare numeric id
       cannot resolve a working page on its own (same "do NOT invent" posture
       as sirius-xm/soundcloud/beatport above: url=null, urlTemplatable=false
       — a slug/username is ALWAYS required, not merely "not yet confirmed").
       `validate` is numeric because both Genius's own API and the legacy
       tblSongIdentityMap.GeniusTrackId column store a plain integer id.
       @link .claude/media-identifiers-spec.md §4c (D5 decisions this fulfils)
       @link appWeb/.sql/migrate-backfill-song-external-ids.php (#1747 D5 backfill consumer) */
    'genius'        => ['label' => 'Genius',         'scope' => 'recording', 'authority' => 'Genius Media',             'validate' => '/^\d+$/', 'url' => null, 'urlTemplatable' => false],
    'wikidata'      => ['label' => 'Wikidata',       'scope' => 'recording', 'authority' => 'Wikimedia Foundation',     'validate' => '/^Q\d+$/', 'url' => 'https://www.wikidata.org/wiki/%s', 'urlTemplatable' => true],
    'imdb'          => ['label' => 'IMDb',           'scope' => 'recording', 'authority' => 'IMDb (Amazon)',            'validate' => '/^tt\d{7,8}$/', 'url' => 'https://www.imdb.com/title/%s/', 'urlTemplatable' => true],
    'mediabase'     => ['label' => 'Mediabase',      'scope' => 'recording', 'authority' => 'Mediabase',                'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'soundexchange' => ['label' => 'SoundExchange',  'scope' => 'recording', 'authority' => 'SoundExchange',            'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'allmusic'      => ['label' => 'AllMusic',       'scope' => 'recording', 'authority' => 'AllMusic',                 'validate' => null, 'url' => null, 'urlTemplatable' => true],
    'rateyourmusic' => ['label' => 'RateYourMusic',  'scope' => 'recording', 'authority' => 'RateYourMusic (Sonemic)',  'validate' => null, 'url' => null, 'urlTemplatable' => false],

    /* ---- Luminate's own per-entity id (spec §4b "Plus Luminate's own per-entity ID") ---- */
    'luminate' => ['label' => 'Luminate Data', 'scope' => 'recording', 'authority' => 'Luminate Data', 'validate' => null, 'url' => null, 'urlTemplatable' => false],

    /* ---- Legacy IDs (spec §4b "Legacy IDs": MC_* codes, Nielsen, BDS, SoundScan) ----
       MC_RELEASE / MC_RELEASE_GROUP / MC_PRODUCT are release/product-scoped and
       repeated in the reserve block below (decision A=c) — they are the SAME
       slugs, not duplicates; a slug appears exactly once in this array. */
    'mc-recording'       => ['label' => 'MC_RECORDING',       'scope' => 'recording', 'authority' => 'Luminate Data (legacy Music Connect code)', 'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'mc-recording-group' => ['label' => 'MC_RECORDING_GROUP', 'scope' => 'recording', 'authority' => 'Luminate Data (legacy Music Connect code)', 'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'mc-song'            => ['label' => 'MC_SONG',            'scope' => 'song',      'authority' => 'Luminate Data (legacy Music Connect code)', 'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'nielsen'            => ['label' => 'Nielsen',             'scope' => 'recording', 'authority' => 'Nielsen',                                   'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'bds'                => ['label' => 'BDS',                 'scope' => 'recording', 'authority' => 'Nielsen BDS (Broadcast Data Systems)',      'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'soundscan'          => ['label' => 'SoundScan',            'scope' => 'recording', 'authority' => 'Nielsen SoundScan',                         'validate' => null, 'url' => null, 'urlTemplatable' => false],

    /* ---- Release/product reserve (decision A=c, spec §4c) ----
       ICPN/GRid/UPC/EAN/catalog-number/MC_RELEASE/MC_RELEASE_GROUP/MC_PRODUCT: stored on the
       SAME recording-grain tblSongExternalIds row (IdScope='release'|'product')
       as ingest provenance, never a new tblReleases entity, per Q1=c. */
    'icpn'             => ['label' => 'ICPN',             'scope' => 'product', 'authority' => 'GS1 (barcode standard, umbrella for UPC/EAN)', 'validate' => '/^\d{12,13}$/', 'url' => null, 'urlTemplatable' => false],
    'grid'             => ['label' => 'GRid',              'scope' => 'release', 'authority' => 'IFPI (Global Release Identifier)',              'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'upc'              => ['label' => 'UPC',               'scope' => 'product', 'authority' => 'GS1',                                            'validate' => '/^\d{12}$/', 'url' => null, 'urlTemplatable' => false],
    'ean'              => ['label' => 'EAN',               'scope' => 'product', 'authority' => 'GS1',                                            'validate' => '/^\d{13}$/', 'url' => null, 'urlTemplatable' => false],
    'catalog-number'   => ['label' => 'Catalog Number',    'scope' => 'release', 'authority' => 'Record label (free text)',                       'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'mc-release'       => ['label' => 'MC_RELEASE',        'scope' => 'release', 'authority' => 'Luminate Data (legacy Music Connect code)',       'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'mc-release-group' => ['label' => 'MC_RELEASE_GROUP',  'scope' => 'release', 'authority' => 'Luminate Data (legacy Music Connect code)',       'validate' => null, 'url' => null, 'urlTemplatable' => false],
    'mc-product'       => ['label' => 'MC_PRODUCT',        'scope' => 'product', 'authority' => 'Luminate Data (legacy Music Connect code)',       'validate' => null, 'url' => null, 'urlTemplatable' => false],
];

/**
 * The whole recording/release/product IdType registry.
 *
 * @return array<string,array{label:string,scope:string,authority:string,validate:?string,url:?string,urlTemplatable:bool}>
 */
function mediaIdentifierRecordingTypes(): array
{
    return RECORDING_EXTERNAL_ID_TYPES;
}

/**
 * True when $idType is a recognised `tblSongExternalIds.IdType` slug.
 *
 * ELI5: "is this a real provider key, or a typo/made-up name?" Unknown type
 * ⇒ false, so a hand-crafted write can't smuggle an unrecognised IdType past
 * the future P3 write path.
 */
function mediaIdentifierIdTypeValid(string $idType): bool
{
    return array_key_exists($idType, RECORDING_EXTERNAL_ID_TYPES);
}

/**
 * The declared canonical IdScope for a given IdType, or NULL when the type is
 * unrecognised. Informational — see the per-field contract note above on why
 * IdScope and IdType are independently validated rather than cross-enforced.
 */
function mediaIdentifierScopeForType(string $idType): ?string
{
    return RECORDING_EXTERNAL_ID_TYPES[$idType]['scope'] ?? null;
}

/**
 * True when $value passes $idType's documented shape. An unknown IdType is
 * always invalid (false); a known IdType with no documented shape (validate
 * === null) accepts any non-empty value — "where standard" (Deliverable 3),
 * never a silent accept-everything for a MADE-UP type.
 */
function mediaIdentifierValidateValue(string $idType, string $value): bool
{
    $reg = RECORDING_EXTERNAL_ID_TYPES[$idType] ?? null;
    if ($reg === null) return false;
    if (trim($value) === '') return false;
    if ($reg['validate'] === null) return true;
    return (bool)preg_match($reg['validate'], $value);
}

/* =========================================================================
 * WORK_IDENTIFIER_TYPES — work-grain identifier vocabulary. Deliberately NOT
 * a new table: every slug below already has a zero-schema-cost home (see the
 * file doc-block). `storage` discriminates the two homes:
 *
 *   'column'  — a real tblWorks column already exists (added in #1741 P1 or
 *               this D5 batch); `column` names it.
 *   'royalty' — rides tblSongRoyaltyIds.Authority (#1140, per-SONG not
 *               per-work today — tblSongRoyaltyIds has no WorkId column;
 *               that is an existing, pre-#1741 modelling choice, out of
 *               scope to change here); `authorityCode` is the literal string
 *               stored in the Authority column for this provider.
 * ========================================================================= */
const WORK_IDENTIFIER_TYPES = [
    'iswc' => [
        'label' => 'ISWC', 'storage' => 'column', 'column' => 'Iswc', 'authorityCode' => null,
        'authority' => 'CISAC', 'validate' => '/^T-?\d{3}\.?\d{3}\.?\d{3}-?\d$/',
    ],
    'bowi' => [
        'label' => 'BOWI', 'storage' => 'column', 'column' => 'Bowi', 'authorityCode' => null,
        'authority' => 'Luminate Data', 'validate' => null,
    ],
    'ccli' => [
        'label' => 'CCLI Work Number', 'storage' => 'column', 'column' => 'Ccli', 'authorityCode' => null,
        'authority' => 'CCLI', 'validate' => '/^\d+$/',
    ],
    'musicbrainz-work' => [
        'label' => 'MusicBrainz Work', 'storage' => 'column', 'column' => 'MusicBrainzWorkMBID', 'authorityCode' => null,
        'authority' => 'MetaBrainz Foundation', 'validate' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
    ],
    /* PRO / royalty-collection societies — spec §4b "PRO/society IDs"; ride
       tblSongRoyaltyIds.Authority (#1140) at zero schema cost. */
    'ascap' => ['label' => 'ASCAP', 'storage' => 'royalty', 'column' => null, 'authorityCode' => 'ASCAP', 'authority' => 'ASCAP', 'validate' => null],
    'bmi'   => ['label' => 'BMI',   'storage' => 'royalty', 'column' => null, 'authorityCode' => 'BMI',   'authority' => 'BMI',   'validate' => null],
    'sesac' => ['label' => 'SESAC', 'storage' => 'royalty', 'column' => null, 'authorityCode' => 'SESAC', 'authority' => 'SESAC', 'validate' => null],
    'prs'   => ['label' => 'PRS',   'storage' => 'royalty', 'column' => null, 'authorityCode' => 'PRS',   'authority' => 'PRS for Music', 'validate' => null],
    'gema'  => ['label' => 'GEMA',  'storage' => 'royalty', 'column' => null, 'authorityCode' => 'GEMA',  'authority' => 'GEMA', 'validate' => null],
    'socan' => ['label' => 'SOCAN', 'storage' => 'royalty', 'column' => null, 'authorityCode' => 'SOCAN', 'authority' => 'SOCAN', 'validate' => null],
    'apra'  => ['label' => 'APRA',  'storage' => 'royalty', 'column' => null, 'authorityCode' => 'APRA',  'authority' => 'APRA AMCOS', 'validate' => null],
    /* HFA — spec §4b "HFA Song Code (STD -> fits tblSongRoyaltyIds)". */
    'hfa'   => ['label' => 'HFA Song Code', 'storage' => 'royalty', 'column' => null, 'authorityCode' => 'HFA', 'authority' => 'Harry Fox Agency', 'validate' => null],
];

/**
 * The whole work-identifier registry.
 *
 * @return array<string,array{label:string,storage:string,column:?string,authorityCode:?string,authority:string,validate:?string}>
 */
function mediaIdentifierWorkTypes(): array
{
    return WORK_IDENTIFIER_TYPES;
}

/**
 * True when $slug is a recognised work-identifier slug.
 */
function mediaIdentifierWorkTypeValid(string $slug): bool
{
    return array_key_exists($slug, WORK_IDENTIFIER_TYPES);
}

/**
 * True when $value passes $slug's documented shape (#1741 P4b) — the
 * WORK_IDENTIFIER_TYPES mirror of `mediaIdentifierValidateValue()` above.
 *
 * ELI5: "does this CCLI Work Number / BOWI / MusicBrainz-Work MBID a
 * curator typed into `/manage/works` actually look like a real one?"
 *
 * DETAILED / WHY A SEPARATE FUNCTION RATHER THAN REUSING
 * `mediaIdentifierValidateValue()`: that function reads from
 * `RECORDING_EXTERNAL_ID_TYPES` (the recording/release/product-grain
 * registry) — a DIFFERENT map, keyed by a DIFFERENT vocabulary (`isrc`,
 * `spotify`, …). `WORK_IDENTIFIER_TYPES` is work-grain (`iswc`, `bowi`,
 * `ccli`, `musicbrainz-work`, plus the PRO/royalty slugs) and the two
 * registries deliberately do NOT share slugs 1:1 (`ccli` here means the
 * work's CCLI *Work* Number column; there is no `ccli` entry in
 * `RECORDING_EXTERNAL_ID_TYPES` at all). Reading the wrong map for a given
 * slug would silently validate against the wrong shape (or reject a valid
 * value outright), so this function reads ONLY `WORK_IDENTIFIER_TYPES` —
 * mirrors `mediaIdentifierValidateValue()`'s exact logic (empty → invalid,
 * unrecognised slug → invalid, `validate === null` → any non-empty value
 * accepted "where standard", per Deliverable 3), just against the sibling
 * map. `manage/works.php` calls THIS function for every work-identifier
 * field it validates (currently `ccli`; `bowi` has no documented shape —
 * see `WORK_IDENTIFIER_TYPES['bowi']['validate'] === null` and the file
 * doc-block — so it is validated by length only, not through this
 * function) rather than re-typing a `preg_match()` inline, which is
 * exactly the drift rule #35 exists to prevent: the vocabulary already
 * lives here, a second hand-typed regex on the admin page would be a
 * second copy that could silently diverge from it.
 *
 * @param string $slug  A WORK_IDENTIFIER_TYPES key, e.g. 'ccli', 'musicbrainz-work'.
 * @param string $value The curator-typed value to check.
 * @return bool false for an unrecognised slug or an empty value; true when
 *              the slug has no documented shape (`validate === null`); the
 *              PCRE match result otherwise.
 * @link .claude/catalogue-1741-P4-plan.md §2.4 item 1
 * @link appWeb/public_html/manage/works.php  the CCLI/MusicBrainz-Work call sites
 */
function mediaIdentifierWorkValidate(string $slug, string $value): bool
{
    $reg = WORK_IDENTIFIER_TYPES[$slug] ?? null;
    if ($reg === null) return false;
    if (trim($value) === '') return false;
    if ($reg['validate'] === null) return true;
    return (bool)preg_match($reg['validate'], $value);
}

/**
 * The `tblSongRoyaltyIds.Authority` allow-list, derived from
 * WORK_IDENTIFIER_TYPES (storage==='royalty' entries only) — never a second,
 * hand-typed copy. Ready for a future `/manage` royalty-ids editor or the P3
 * resolver to validate curator input against; nothing writes through this
 * yet (tblSongRoyaltyIds predates #1741 and had no documented allow-list
 * until this commit — see the file doc-block).
 *
 * @return list<string> e.g. ['ASCAP','BMI','SESAC','PRS','GEMA','SOCAN','APRA','HFA']
 */
function mediaIdentifierRoyaltyAuthorities(): array
{
    $out = [];
    foreach (WORK_IDENTIFIER_TYPES as $def) {
        if ($def['storage'] === 'royalty' && $def['authorityCode'] !== null) {
            $out[] = $def['authorityCode'];
        }
    }
    return $out;
}

/* =========================================================================
 * PUBLICATION_IDENTIFIER_TYPES — publication-entity (songbook / series /
 * catalogue) identifier vocabulary (Songbook/Catalogue Enhancements epic,
 * commit 1 of 7 — pure foundations, dormant until the migration in commit 2
 * lands the columns this map describes).
 *
 * ELI5
 * ----
 * The WORK_IDENTIFIER_TYPES map above says "what identifiers can a work have,
 * and where do they live?". This is the SAME question one level up, for the
 * publication itself (the physical/curated hymnal, series, or collection) —
 * ARK, OpenLibrary Work id, OpenLibrary Edition id, ISBN, ISSN.
 *
 * DETAILED / WHY "SAME ENTRY SHAPE AS WORK_IDENTIFIER_TYPES" BUT A SEPARATE
 * CONST RATHER THAN MORE ENTRIES IN THAT ONE
 * ----------------------------------------------------------------------------
 * `WORK_IDENTIFIER_TYPES` and this map are keyed by DIFFERENT, non-
 * overlapping vocabularies for DIFFERENT entities (a work's ISWC/BOWI/CCLI
 * vs. a publication's ISBN/ISSN/ARK/OpenLibrary) — mixing them into one
 * array would mean a lookup by slug could resolve against the wrong grain,
 * exactly the mistake `mediaIdentifierWorkValidate()`'s own doc-block warns
 * against for `RECORDING_EXTERNAL_ID_TYPES` vs `WORK_IDENTIFIER_TYPES`. This
 * map reuses that SAME field shape (`label`/`storage`/`column`/
 * `authorityCode`/`authority`/`validate`) — every entry here is
 * `storage => 'column'` because, per the epic's Feature 3 decision, every
 * publication identifier is a plain nullable `VARCHAR` column (the #672
 * pattern `tblSongbooks.Isbn`/`ArkId` already established), never a
 * generic key/value side-table — plus one addition this map's callers need
 * that WORK_IDENTIFIER_TYPES's callers do not yet: a nullable `url`
 * "%s"-templated printf lookup URL (mirrors `RECORDING_EXTERNAL_ID_TYPES`'s
 * `url` field), for a future admin "view on OpenLibrary/ISSN portal" link —
 * filled in only where the public lookup shape is genuinely well-known and
 * stable (all five below are).
 *
 * `column` NAMES THE CONVENTIONAL COLUMN, NOT A SINGLE TABLE: unlike
 * `WORK_IDENTIFIER_TYPES['iswc']['column']` (which names exactly one
 * `tblWorks` column), a publication identifier's column of the SAME NAME may
 * exist on ZERO, ONE, TWO or ALL THREE of `tblSongbooks` /
 * `tblSongbookSeries` / `tblCatalogues` — the epic's migration deliberately
 * does NOT give every entity every column (`tblSongbooks` has no `Issn`,
 * `tblCatalogues` has no `Isbn`/`Issn` at all — see the plan's "Adversarial
 * notes": "series 260$b / catalogue ISBN deliberately absent"). `column`
 * here documents the NAME a caller should look for; it is each entity's own
 * concern (and `includes/marcxml.php`'s `marcxmlFieldMap()`, its first
 * consumer) to know which of the three entities actually carries it.
 *
 * NOT YET WIRED TO ANY LIVE WRITE PATH: `mediaIdentifierPublicationValidate()`
 * is exactly the guard `includes/marcxml.php`'s importer will call once
 * MARCXML handlers land (commit 6) — nothing calls it from this commit.
 * The `ark` / `openlibrary-work` / `openlibrary-edition` slugs canonicalise
 * via `identifier_normalize.php`'s folds FIRST (never re-derive their shape
 * here — this map's `validate` regex for those three is for a caller that
 * only wants a truth check, e.g. a future admin form's client-side hint,
 * and is intentionally the SAME shape those folds enforce, matching the
 * pre-existing `WORK_IDENTIFIER_TYPES['iswc']['validate']` /
 * `ihymns_canonical_iswc()` precedent of two independently-declared but
 * shape-identical checks for two different purposes).
 *
 * @link .claude/songbook-catalogue-enhancements-plan.md  the epic plan (Feature 3 + Feature 6 sections)
 * @see appWeb/public_html/includes/identifier_normalize.php  ihymns_canonical_ark() / ihymns_canonical_openlibrary() — the authoritative folds for ark/openlibrary-*
 * @see appWeb/public_html/includes/marcxml.php               the first consumer (marcxmlMapToEntity())
 * @see tests/php/test-media-identifiers.php                  the vocabulary guard this map is checked against
 * ========================================================================= */
const PUBLICATION_IDENTIFIER_TYPES = [
    'ark' => [
        'label' => 'ARK', 'storage' => 'column', 'column' => 'ArkId', 'authorityCode' => null,
        'authority' => 'ARK Alliance / California Digital Library',
        'validate' => '#^ark:/\d{5,9}/[!-~]+$#',
        'url' => 'https://n2t.net/%s',
    ],
    'openlibrary-work' => [
        'label' => 'OpenLibrary Work', 'storage' => 'column', 'column' => 'OpenLibraryWorkId', 'authorityCode' => null,
        'authority' => 'Internet Archive / Open Library',
        'validate' => '/^OL\d+W$/',
        'url' => 'https://openlibrary.org/works/%s',
    ],
    'openlibrary-edition' => [
        'label' => 'OpenLibrary Edition', 'storage' => 'column', 'column' => 'OpenLibraryEditionId', 'authorityCode' => null,
        'authority' => 'Internet Archive / Open Library',
        'validate' => '/^OL\d+M$/',
        'url' => 'https://openlibrary.org/books/%s',
    ],
    /* ISBN/ISSN validate against the CLEANED shape (digits + an optional
       trailing check-"digit" X, hyphens already stripped) — this file ships
       no ihymns_canonical_isbn()/_issn() fold (unlike ark/openlibrary-*, the
       epic's Feature 6 scope does not call for one), so a caller with a
       curator-typed value carrying hyphens/spaces must strip them itself
       before calling mediaIdentifierPublicationValidate('isbn', …), the same
       pre-cleaned-input expectation mediaIdentifierValidateValue() already
       has for 'isrc'/'ccli'. includes/marcxml.php does this cleaning inline
       for the one caller this commit ships. */
    'isbn' => [
        'label' => 'ISBN', 'storage' => 'column', 'column' => 'Isbn', 'authorityCode' => null,
        'authority' => 'International ISBN Agency',
        'validate' => '/^(?:\d{9}[\dX]|\d{13})$/',
        'url' => null,
    ],
    'issn' => [
        'label' => 'ISSN', 'storage' => 'column', 'column' => 'Issn', 'authorityCode' => null,
        'authority' => 'ISSN International Centre',
        'validate' => '/^\d{7}[\dX]$/',
        'url' => 'https://portal.issn.org/resource/ISSN/%s',
    ],
];

/**
 * The whole publication-identifier registry.
 *
 * @return array<string,array{label:string,storage:string,column:?string,authorityCode:?string,authority:string,validate:?string,url:?string}>
 */
function mediaIdentifierPublicationTypes(): array
{
    return PUBLICATION_IDENTIFIER_TYPES;
}

/**
 * True when $value passes $slug's documented shape — the
 * PUBLICATION_IDENTIFIER_TYPES mirror of `mediaIdentifierWorkValidate()`.
 *
 * ELI5: "does this ARK / OpenLibrary id / ISBN / ISSN a curator typed (or
 * MARCXML import extracted) actually look like a real one?"
 *
 * Mirrors `mediaIdentifierWorkValidate()`'s exact contract against the
 * sibling map: unrecognised slug → false, empty value → false (trimmed),
 * a recognised slug with `validate === null` → any non-empty value accepted
 * (none of the five entries above currently has a null validate, but the
 * contract is kept identical to its siblings for a future entry that might),
 * else the PCRE match result.
 *
 * @param string $slug  A PUBLICATION_IDENTIFIER_TYPES key, e.g. 'ark', 'isbn'.
 * @param string $value The value to check — for isbn/issn this must already
 *                       be cleaned (digits + optional trailing X, no
 *                       hyphens/spaces); for ark/openlibrary-* callers
 *                       should prefer the canonicalising fold in
 *                       identifier_normalize.php, which validates AND
 *                       cleans in one step.
 * @return bool
 * @see appWeb/public_html/includes/marcxml.php  the first call site
 */
function mediaIdentifierPublicationValidate(string $slug, string $value): bool
{
    $reg = PUBLICATION_IDENTIFIER_TYPES[$slug] ?? null;
    if ($reg === null) return false;
    if (trim($value) === '') return false;
    if ($reg['validate'] === null) return true;
    return (bool)preg_match($reg['validate'], $value);
}
