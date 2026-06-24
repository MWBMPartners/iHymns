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
 * Resolve `$hasCcli` for a user the SAME way the tier_check endpoint does:
 * a non-empty CcliNumber AND CcliVerified truthy. Anonymous (null id) is
 * always false. Wrapped so a missing column / DB blip degrades to false
 * (deny the ccli-only unlock — the safe direction for a copyright gate).
 *
 * @param int|null $userId Authenticated user id, or null for anonymous.
 * @return bool
 */
function contentGating_userHasCcli(?int $userId): bool
{
    if ($userId === null) {
        return false;
    }
    try {
        $db   = getDbMysqli();
        $stmt = $db->prepare('SELECT CcliNumber, CcliVerified FROM tblUsers WHERE Id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        /* ELI5: a CCLI unlock needs BOTH a number AND that it's verified.
           WHY: mirrors api.php:6837 ($hasCcli) so gating + tier_check agree. */
        return !empty($row['CcliNumber']) && !empty($row['CcliVerified']);
    } catch (\Throwable $_e) {
        /* Un-migrated tblUsers / DB blip — deny the ccli unlock (safe). */
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
 * @param array    $song     The built song array (song_detail/song_data shape).
 * @param int|null $userId   Authenticated user id, or null for anonymous.
 * @param string   $platform Requesting platform tag (informational; default 'PWA').
 * @return array The same array, with disallowed fields stripped (or unchanged).
 */
function contentGatingApply(array $song, ?int $userId, string $platform = 'PWA'): array
{
    /* (A) Master switch — the deliberate dormant default. */
    if (!contentGatingEnabled()) {
        return $song;
    }

    /* (C) Resolve the tier + caps inside try/catch — never throw out of here. */
    try {
        /* Effective tier: anonymous → 'public'; else highest of personal/org. */
        $tier = ($userId === null) ? 'public' : resolveEffectiveTier($userId);
        if ($tier === '') { $tier = 'public'; }
        $hasCcli = contentGating_userHasCcli($userId);

        /* Per-cap decisions via the SHARED registry-backed resolver (rule B). */
        $can = static function (string $action) use ($tier, $hasCcli): bool {
            $r = checkTierAccess($tier, $action, $hasCcli);
            return !empty($r['allowed']);
        };

        $canViewLyrics      = $can('view_lyrics');
        $canViewCopyrighted = $can('view_copyrighted');
        $canPlayAudio       = $can('play_audio');
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
            $song['media'] = array_values(array_filter(
                $song['media'],
                static function ($m) use ($canPlayAudio, $canDownloadMidi, $canDownloadPdf): bool {
                    $kind = is_array($m) ? (string)($m['kind'] ?? '') : '';
                    switch ($kind) {
                        case 'audio':       return $canPlayAudio;
                        case 'midi':        return $canDownloadMidi;
                        case 'sheet-music': return $canDownloadPdf;
                        case 'musicxml':    return $canDownloadPdf;  /* notation download = PDF family */
                        default:            return true;             /* unknown kind — leave it */
                    }
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

        return $song;
    } catch (\Throwable $_e) {
        /* (C) Anything unexpected — return the song UNCHANGED. The master
           switch already opted this env in; failing open here is preferable to
           white-screening a public read, and is logged for an operator. */
        error_log('[content_gating] apply failed: ' . $_e->getMessage());
        return $song;
    }
}
