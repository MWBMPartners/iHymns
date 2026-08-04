<?php

declare(strict_types=1);

/**
 * iHymns — Server-side content-gating enforcement for the public / native
 * API (#1353).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT THIS IS:
 * The gating TIERS (public/free/ccli/premium/pro) have been ADVISORY until
 * now — the API emitted full song data regardless of tier and the native
 * apps were expected to self-enforce. This module makes the server the
 * enforcement point: it strips the fields a tier may not see/use from a
 * built song_detail payload BEFORE it is emitted.
 *
 * THREE LOCKED RULES (do not relax):
 *
 *   (A) MASTER SWITCH. Every public function here is a NO-OP — it returns
 *       byte-identical data to today — unless
 *       getAppSetting('content_gating_enabled','0') === '1'. The flag is
 *       '0' by default (the deliberately-dormant design), so shipping this
 *       changes NOTHING on any live env until an operator flips the flag.
 *
 *   (B) CAPS COME FROM THE REGISTRY. We never hardcode a tier→cap matrix
 *       here. Per-cap decisions go through checkTierAccess() in
 *       ccli_validator.php, which (since #1353) resolves caps from the LIVE
 *       tblAccessTiers row — so a new one-line json cap (#1352) is enforced
 *       automatically.
 *
 *   (C) STRICT-SAFE. Migrations are NOT auto-applied and the 3 docroots
 *       share one MySQL, so a request can hit an un-migrated / edge env.
 *       mysqli runs under STRICT (db_mysql.php) → a bad read THROWS. Every
 *       optional read is wrapped; the helper defaults to FAIL-OPEN (return
 *       the song unchanged) on any uncertainty so a half-migrated env never
 *       throws and never blanks legitimate content. (Fail-open is correct
 *       here because the master switch is the real gate; this module only
 *       trims within an already-opted-in deployment.)
 *
 * COPYRIGHTED-SONG DETECTION:
 * A song is treated as copyrighted when its lyrics are NOT public domain —
 * i.e. the song_detail payload's `lyricsPublicDomain` boolean is false
 * (mirrors SongData::_fetchSongRow, which casts tblSongs.LyricsPublicDomain
 * to bool). PD gating is per-axis (see MEMORY: lyrics vs music flags are
 * independent), so the lyric-body copyright gate keys on the LYRICS axis
 * only — never AND-ed with musicPublicDomain.
 *
 * Direct access blocked so this can't be loaded as an endpoint.
 *
 * @see includes/ccli_validator.php  resolveEffectiveTier / checkTierAccess
 * @see includes/access_tier_validation.php  TIER_CAPS registry (#1352)
 * @link https://www.php.net/manual/en/language.types.array.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php';      /* getAppSetting() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ccli_validator.php';   /* resolveEffectiveTier / checkTierAccess */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'gating_rules.php';     /* gatingRulesApply() — #1481 P2 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licences.php';         /* userHasValidCcli() — the ONE CCLI resolver, #1668 */

/**
 * Media-kind → tier-capability-action map — the ONE definition (#1769 P0, OV-11).
 *
 * ELI5: "which permission does this kind of file need?" An audio file needs
 * `play_audio`, a MIDI needs `download_midi`, and sheet music + MusicXML both
 * need `download_pdf`. An unrecognised kind needs nothing (it stays visible).
 *
 * DETAILED / WHY THIS EXISTS: this mapping was copy-pasted in THREE places —
 * the payload strip in contentGatingApply(), the byte gate in
 * contentGatingMediaAllowed(), and the HTML song page
 * (includes/pages/song.php) — each "kept in sync" by a comment citing the
 * others' line numbers, which had already drifted (rule #35: a keep-in-sync
 * comment is the failure, not the fix). All three now call THIS. It returns the
 * cap-action string checkTierAccess() understands, or null for an unrecognised
 * kind meaning "no capability gates it — leave the row". Presence-token
 * OR-grants (a congregant may hear audio without the tier cap) stay at each
 * call site, since they differ per surface — this map is only the
 * kind→capability half they all share.
 *
 * @param string $kind A tblSongMedia.Kind value (audio|midi|sheet-music|musicxml|…).
 * @return 'play_audio'|'download_midi'|'download_pdf'|null
 */
function contentGatingMediaKindCap(string $kind): ?string
{
    switch ($kind) {
        case 'audio':       return 'play_audio';
        case 'midi':        return 'download_midi';
        case 'sheet-music': return 'download_pdf';
        case 'musicxml':    return 'download_pdf'; /* notation download = PDF family */
        default:            return null;           /* unknown kind — no capability gates it */
    }
}

/**
 * Is server-side content gating switched ON for this environment?
 *
 * The master gate for everything in this module. When this is false EVERY
 * public function returns its input unchanged. getAppSetting is itself
 * try/catch'd (returns the default on a DB blip), so this never throws.
 *
 * @return bool true only when content_gating_enabled === '1'.
 */
function contentGatingEnabled(): bool
{
    /* ELI5: the on/off switch for the whole feature.
       WHY: rule (A) — dormant by default; flag '0' = byte-identical to today. */
    return function_exists('getAppSetting')
        && getAppSetting('content_gating_enabled', '0') === '1';
}

/**
 * Resolve `$hasCcli` for a user — a thin, deny-on-error wrapper around the
 * ONE resolver, `userHasValidCcli()` in includes/licences.php (#1668).
 *
 * ELI5: this used to ask the database its own question and got a different
 * answer from the rest of the site. Now it asks the one place that knows.
 *
 * WHY the delegation (#1668): this function used to run its own
 * `SELECT CcliNumber, CcliVerified FROM tblUsers` — a personal-only check.
 * That made it structurally incapable of honouring the owner's requirement
 * that a member of an ORGANISATION holding a valid CCLI licence also passes,
 * and it disagreed with licences.php about the personal half too (it demanded
 * `CcliVerified`; licences.php accepted anything non-empty).
 * `userHasValidCcli()` answers both halves: a WELL-FORMED personal number
 * (format-checked by `validateCcliNumber()` — deliberately NOT gated on
 * `CcliVerified`, which nothing can set truthfully; see licenceCcliQualifies()
 * for the full reasoning), or a live `ccli` licence on any org in the user's
 * inheritance chain, read from both org stores.
 *
 * Anonymous (null id) is always false.
 *
 * DENY-ON-ERROR IS PRESERVED — deliberately. The rest of this module is
 * fail-OPEN (rule C: an un-migrated read degrades to "the song unchanged"),
 * but this one predicate goes the other way: on any throw we return false,
 * i.e. we do NOT hand out the CCLI unlock. A licensing gate must fail CLOSED,
 * and a false here can only ever REMOVE an unlock, never grant one.
 *
 * @param int|null $userId Authenticated user id, or null for anonymous.
 * @return bool
 * @see includes/licences.php  userHasValidCcli() — the resolver
 */
function contentGating_userHasCcli(?int $userId): bool
{
    if ($userId === null) {
        return false;
    }
    try {
        return userHasValidCcli($userId);
    } catch (\Throwable $_e) {
        /* Un-migrated schema / DB blip — deny the ccli unlock (safe). */
        return false;
    }
}

/**
 * Apply tier-based content gating to ONE built song_detail/song_data payload
 * immediately before it is emitted.
 *
 * NO-OP (returns $song unchanged) when the master switch is off (rule A) or
 * when anything needed to make a decision can't be resolved (rule C —
 * fail-open). Otherwise it resolves the requester's effective tier (anonymous
 * → 'public') and trims:
 *
 *   - !view_lyrics      → strip the lyric BODY (components) + per-line
 *                         translations/annotations + whole-song translations;
 *                         add contentRestricted=true + restrictionReason.
 *                         Metadata (id/number/title/songbook/credits) is kept.
 *   - copyrighted song  (lyricsPublicDomain === false) AND !view_copyrighted
 *                       → same lyric-body strip + restriction flag.
 *   - !play_audio       → drop audio-kind media (its streamUrl) + hasAudio=false.
 *   - !download_midi    → drop midi-kind media.
 *   - !download_pdf     → drop sheet-music + musicxml media + hasSheetMusic=false.
 *   - !offline_save     → offlineAllowed=false.
 *
 * Only fields that ACTUALLY exist in the payload are touched (the song_detail
 * shape from SongData::_fetchSongRow), so the trim degrades gracefully if a
 * future shape change renames a key.
 *
 * @param array       $song          The built song array (song_detail/song_data shape).
 * @param int|null    $userId        Authenticated user id, or null for anonymous.
 * @param string      $platform      Requesting platform tag (informational; default 'PWA').
 * @param string|null $presenceToken Service-Mode congregant presence token (#1335 / rule #26).
 *                                   When it resolves to a live session whose org holds a
 *                                   verified CCLI licence, it OR-grants view_copyrighted +
 *                                   play_audio for the duration of the service — the in-service
 *                                   exception. NULL/empty (the default) is a no-op, so the
 *                                   existing 3-arg callers are completely unaffected.
 * @return array The same array, with disallowed fields stripped (or unchanged).
 */
function contentGatingApply(array $song, ?int $userId, string $platform = 'PWA', ?string $presenceToken = null): array
{
    /* (A) Master switch — the deliberate dormant default. Unchanged: a 3-arg OR a
       4-arg call returns byte-identical data when the flag is off, because we bail
       here BEFORE touching the new presence-token path. */
    if (!contentGatingEnabled()) {
        return $song;
    }

    /* (C) Resolve the tier + caps inside try/catch — never throw out of here. */
    try {
        /* content-via-key — a vetted API key explicitly granted the separate
           `content:gated` scope reads gated content UNSTRIPPED. DORMANT twice over:
           it needs content_gating_enabled='1' AND a key actually carrying that scope.
           The per-partner decision to ISSUE such a key is the operator's licensing
           call — this is redistribution of gated/copyrighted content to an EXTERNAL
           system, broader than the #1324 in-service presence-gated basis, so the scope
           is kept SEPARATE from catalogue:read (a plain read key never unlocks it). */
        if (function_exists('apiKeyFromRequest') && function_exists('apiKeyVerify')) {
            $gatedKeyRaw = apiKeyFromRequest();
            if ($gatedKeyRaw !== null && strncmp($gatedKeyRaw, 'ihk_', 4) === 0
                && apiKeyVerify(getDbMysqli(), $gatedKeyRaw, 'content:gated') !== null) {
                return $song;   /* authorised for gated content → strip nothing */
            }
        }

        /* Effective tier: anonymous → 'public'; else highest of personal/org. */
        $tier = ($userId === null) ? 'public' : resolveEffectiveTier($userId);
        if ($tier === '') { $tier = 'public'; }
        $hasCcli = contentGating_userHasCcli($userId);

        /* #1335 / rule #26 — Service-Mode presence unlock. A congregant following a
           live service carries an opaque presence token (a same-origin cookie set by
           service-follow.js). When that token resolves to an ACTIVE session whose org
           holds a LIVE 'ccli' licence, they ride that licence for copyrighted lyrics +
           audio while present. We OR this grant into the per-cap decisions below (entity
           grant OR tier grant OR presence grant). DORMANT: only attempted when a
           non-empty token is supplied AND the service_mode helper + table are present —
           a missing helper / un-migrated env / DB blip resolves to false (deny the
           unlock, which leaves the plain tier verdict authoritative — the safe
           direction). serviceMode_presenceCcliNumber returns the org's CCLI number on a
           valid present token, or null. */
        $presenceCcli = false;
        if ($presenceToken !== null && $presenceToken !== '') {
            try {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'service_mode.php';
                if (function_exists('serviceMode_presenceCcliNumber') && function_exists('serviceMode_channel')) {
                    $presenceCcli = (serviceMode_presenceCcliNumber(
                        getDbMysqli(),
                        $presenceToken,
                        serviceMode_channel()
                    ) !== null);
                }
            } catch (\Throwable $_e) {
                /* Missing helper / un-migrated table / DB blip — deny the unlock. */
                $presenceCcli = false;
            }
        }

        /* The per-cap decisions + all payload trimming live in the legacy core
           (#1769 P2 Commit A — a pure code-motion seam so tools/capture-gating-
           goldens.php can exercise the exact pre-refactor logic DB-free over a
           synthetic tier matrix, freezing the goldens the P2 pipeline is proven
           against). The core is REPLACED by accessApplySong() in Commit E; it is
           deleted then. It reads only $tier/$hasCcli/$presenceCcli/$song. */
        return _contentGatingApplyLegacyCore($song, $tier, $hasCcli, $presenceCcli);
    } catch (\Throwable $_e) {
        /* (C) Anything unexpected — return the song UNCHANGED. The master
           switch already opted this env in; failing open here is preferable to
           white-screening a public read, and is logged for an operator. */
        error_log('[content_gating] apply failed: ' . $_e->getMessage());
        return $song;
    }
}

/**
 * The payload-transform CORE of contentGatingApply — extracted verbatim as a
 * pure code-motion seam (#1769 P2 Commit A). It takes the already-resolved
 * $tier / $hasCcli / $presenceCcli (rebuilding the $can closure from them) so
 * that tools/capture-gating-goldens.php can drive it DB-free over a synthetic
 * tier matrix, freezing the golden outputs the P2 pipeline (accessApplySong)
 * is proven byte-identical against. TEMPORARY: Commit E replaces the two CG
 * delegates with the access_resolver.php pipeline and DELETES this core (the
 * goldens are frozen by then). Reads only its four parameters + $song.
 *
 * @internal #1769 P2 scaffolding — do not call from new code.
 */
function _contentGatingApplyLegacyCore(array $song, string $tier, bool $hasCcli, bool $presenceCcli): array
{
    /* Per-cap decisions via the SHARED registry-backed resolver (rule B). */
    $can = static function (string $action) use ($tier, $hasCcli): bool {
        $r = checkTierAccess($tier, $action, $hasCcli);
        return !empty($r['allowed']);
    };

    $canViewLyrics      = $can('view_lyrics');
    /* The two CCLI-gated caps get the presence OR-grant (rule #26). The others
       (lyrics/midi/pdf/offline) are NOT covered by the in-service exception —
       presence rides the org's CCLI licence, which licenses copyrighted-lyric
       DISPLAY + accompaniment audio, not MIDI/PDF/offline redistribution. */
    $canViewCopyrighted = $can('view_copyrighted') || $presenceCcli;
    $canPlayAudio       = $can('play_audio')       || $presenceCcli;
    $canDownloadMidi    = $can('download_midi');
    $canDownloadPdf     = $can('download_pdf');
    $canOfflineSave     = $can('offline_save');

    /* Copyrighted = lyrics NOT public domain (LYRICS axis only — PD gating
       is per-axis, never AND music). The payload exposes the bool already. */
    $isCopyrighted = array_key_exists('lyricsPublicDomain', $song)
        && $song['lyricsPublicDomain'] === false;

    /* --- Lyric BODY gate ------------------------------------------------
       Strip the lyric body when the tier can't view lyrics at all, OR the
       song is copyrighted and the tier can't view copyrighted material.
       Metadata stays so the client can still show a "locked" card with
       title/number/credits and an upgrade prompt. */
    $denyLyricBody = (!$canViewLyrics) || ($isCopyrighted && !$canViewCopyrighted);
    if ($denyLyricBody) {
        /* The lyric body lives in `components`; per-line + whole-song
           translations/annotations would leak the text, so drop them too.
           Only unset keys that exist (graceful if the shape changes). */
        foreach (['components', 'translations', 'annotations', 'vocalParts'] as $bodyKey) {
            if (array_key_exists($bodyKey, $song)) {
                unset($song[$bodyKey]);
            }
        }
        $song['contentRestricted'] = true;
        $song['restrictionReason'] = (!$canViewLyrics)
            ? 'lyrics_not_available_on_tier'
            : 'copyrighted_requires_higher_tier';
    }

    /* --- Media gates ----------------------------------------------------
       The song_detail payload's `media` is an array of tblSongMedia rows,
       each `{kind, streamUrl(/song-media/<id>), ...}`. We DROP a media row
       the tier can't use (rather than null its URL) so the affordance
       disappears entirely. Kinds: audio | sheet-music | midi | musicxml. */
    if (!empty($song['media']) && is_array($song['media'])) {
        /* kind→cap via the ONE shared map (contentGatingMediaKindCap, OV-11);
           each cap resolves to the tier boolean already computed above. */
        $capBool = [
            'play_audio'    => $canPlayAudio,
            'download_midi' => $canDownloadMidi,
            'download_pdf'  => $canDownloadPdf,
        ];
        $song['media'] = array_values(array_filter(
            $song['media'],
            static function ($m) use ($capBool): bool {
                $kind = is_array($m) ? (string)($m['kind'] ?? '') : '';
                $cap  = contentGatingMediaKindCap($kind);
                return $cap === null ? true : ($capBool[$cap] ?? true);
            }
        ));
    }

    /* Keep the boolean indicator flags consistent with what's now emittable
       so a client badge ("has audio") doesn't promise media we stripped. */
    if (!$canPlayAudio && array_key_exists('hasAudio', $song)) {
        $song['hasAudio'] = false;
    }
    if (!$canDownloadPdf && array_key_exists('hasSheetMusic', $song)) {
        $song['hasSheetMusic'] = false;
    }

    /* --- Offline affordance --------------------------------------------
       Tell the client whether this song may be saved offline on this tier.
       Additive key — clients that don't read it are unaffected; clients
       that do can hide the "save offline" button. */
    $song['offlineAllowed'] = $canOfflineSave;

    /* --- Admin-defined enforcement rules (#1481 P2) ---------------------
       Runs LAST, after every built-in trim above, and is itself a no-op
       unless the SECOND nested flag (feature_gating_rules_enabled) is on
       — gatingRulesApply() checks that flag internally, so a 3-arg or
       4-arg call with content_gating_enabled='1' but the rules flag
       still '0' returns $song completely unchanged from this point.
       Rules resolve their capability via capValueForTierAction()
       (fail-open on null — never the fail-closed matrix), are restricted
       to DB-defined caps only (never one of the 7 built-ins gated above),
       and are re-verified against the no-escalation invariant before
       their output is accepted. See includes/gating_rules.php. */
    $song = gatingRulesApply($song, $tier);

    return $song;
}

/**
 * The kind→cap decision CORE of contentGatingMediaAllowed — extracted verbatim
 * as a pure code-motion seam (#1769 P2 Commit A). See
 * _contentGatingApplyLegacyCore for the full rationale. Replaced by
 * accessMediaAllowed() and DELETED in Commit E.
 *
 * @internal #1769 P2 scaffolding — do not call from new code.
 */
function _contentGatingMediaAllowedLegacyCore(string $kind, string $tier, bool $hasCcli, bool $presenceCcli): bool
{
    $can = static function (string $action) use ($tier, $hasCcli): bool {
        $r = checkTierAccess($tier, $action, $hasCcli);
        return !empty($r['allowed']);
    };

    /* kind→cap via the ONE shared map (OV-11). Audio keeps its Service-Mode
       presence OR-grant: a present congregant may hear audio even without
       the tier cap (this OR is byte-gate-specific, so it stays here). */
    $cap = contentGatingMediaKindCap($kind);
    if ($cap === null) { return true; }  /* unknown kind — leave it */
    return $can($cap) || ($cap === 'play_audio' && $presenceCcli);
}

/**
 * Is this ONE media row's kind playable/downloadable on the caller's tier? (#1388)
 *
 * ELI5: `contentGatingApply()` decides which media links a song page is allowed
 * to SHOW. This answers the same question for someone who already has the link
 * and is asking for the bytes.
 *
 * WHY IT EXISTS. Stripping a media row from the `song_detail` payload hides the
 * affordance; it does not protect the file. `/song-media/<id>` is a plain URL —
 * bookmarkable, shareable, guessable by id, and still served long after the row
 * vanished from someone's payload. Before this, that endpoint applied only the
 * ENTITY gate (`checkContentAccess`) and no tier cap at all, so the instant
 * gating went live a `public`-tier visitor could still stream premium audio by
 * hitting the URL directly. The affordance gate and the bytes gate have to agree.
 *
 * DELIBERATELY THE SAME DECISION, NOT A COPY OF IT (modularity rule / rule #28B).
 * The kind→cap mapping and the presence OR-grant below mirror the media-filter
 * block inside contentGatingApply() because they must never diverge — a cap that
 * hides the button but not the file, or vice versa, is worse than neither. Caps
 * are resolved through the shared registry-backed `checkTierAccess()`; there is
 * no local tier matrix here (rule #28B).
 *
 * FAIL-OPEN, like every other path in this file (rule #28C): gating off, an
 * unknown kind, a missing helper, or any throw ⇒ true. The 3 docroots share one
 * MySQL and migrations are web-run, so an un-migrated read must degrade to the
 * pre-gating behaviour, never to a broken media endpoint.
 *
 * @param string  $kind          tblSongMedia.Kind — audio | midi | sheet-music | musicxml
 * @param ?int    $userId        Resolved user, or null for anonymous
 * @param ?string $presenceToken Service-Mode presence token (#1335), or null
 * @return bool   true ⇒ serve the bytes
 */
function contentGatingMediaAllowed(string $kind, ?int $userId, ?string $presenceToken = null): bool
{
    /* (A) Master switch off ⇒ byte-identical to pre-#1388 behaviour. */
    if (!contentGatingEnabled()) {
        return true;
    }

    try {
        /* Same effective-tier resolution as contentGatingApply(). */
        $tier = ($userId === null) ? 'public' : resolveEffectiveTier($userId);
        if ($tier === '') { $tier = 'public'; }
        $hasCcli = contentGating_userHasCcli($userId);

        /* Presence unlock (rule #26) — rides the org's LIVE ccli licence for the
           duration of the service. Covers AUDIO only, exactly as in the payload
           path: a CCLI licence covers accompaniment playback, not MIDI/PDF
           redistribution. Any failure ⇒ no unlock (the safe direction). */
        $presenceCcli = false;
        if ($presenceToken !== null && $presenceToken !== '') {
            try {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'service_mode.php';
                if (function_exists('serviceMode_presenceCcliNumber') && function_exists('serviceMode_channel')) {
                    $presenceCcli = (serviceMode_presenceCcliNumber(
                        getDbMysqli(),
                        $presenceToken,
                        serviceMode_channel()
                    ) !== null);
                }
            } catch (\Throwable $_e) {
                $presenceCcli = false;
            }
        }

        /* The kind→cap decision lives in the legacy core (#1769 P2 Commit A —
           see contentGatingApply). Replaced by accessMediaAllowed() in Commit E. */
        return _contentGatingMediaAllowedLegacyCore($kind, $tier, $hasCcli, $presenceCcli);
    } catch (\Throwable $_e) {
        /* Fail open + log, matching contentGatingApply()'s (C) branch. */
        error_log('[content_gating] media gate failed: ' . $_e->getMessage());
        return true;
    }
}
