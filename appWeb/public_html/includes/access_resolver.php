<?php

declare(strict_types=1);

/**
 * iHymns — Access resolver: the ONE content-gating pipeline (#1769 P2 Commit E)
 * ============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Given a viewer struct (from access_context.php) this decides, in ONE place,
 * what a request may see: which lyric body / media / offline affordance to keep
 * on a song payload (accessApplySong), whether a single media file may be served
 * (accessMediaAllowed), and — for the P3+ future — a general allow/deny verdict
 * for any action against a song's rights facts (accessResolve). Every emitter
 * adopts THIS instead of re-deciding for itself (rule #28: never a second matrix).
 *
 * DETAILED / BYTE-IDENTICAL NO-OP (#1769 Model 2)
 * -----------------------------------------------
 * accessApplySong() / accessMediaAllowed() are the pipeline the content_gating.php
 * delegates now call. Their transforms are lifted BRANCH-FOR-BRANCH from the
 * pre-P2 enforcement core (the `_contentGating*LegacyCore` functions this commit
 * deletes), so the output is byte-identical for every (tier × ccli × presence ×
 * song-shape) input — proven against the frozen goldens by
 * tests/php/test-gating-equivalence.php. The ONLY additions are DELIBERATELY DEAD
 * in P2:
 *   - a per-song RIGHTS-FACT gate on the lyric body (lyricsRightsLicenceKey read
 *     from the PAYLOAD, never the DB). Triple-locked inert: SongData does not
 *     select the column, no row carries a value, and the key-present guard skips
 *     null/''. It only fires when a future emitter (P6) emits a non-empty key
 *     naming a licence the viewer lacks — tested POSITIVELY so the machinery is
 *     proven live, but absent on every real payload today.
 *   - accessResolve(), the general primitive P3/P6 will wire; NOTHING calls it
 *     in P2.
 *
 * Fail-open posture (rule #28C) is preserved: gating off → the input unchanged;
 * any throw → the input unchanged (apply) / serve the bytes (media), logged.
 *
 * @link .claude/gating-p2-design.md  §"includes/access_resolver.php" — the design
 * @see  includes/access_context.php  the viewer struct this consumes
 * @see  includes/content_gating.php  the delegates + contentGatingMediaKindCap / gatingRulesApply
 * @see  tests/php/test-gating-equivalence.php  the golden-replay guard
 */

/* Library, never a page. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* content_gating.php provides contentGatingMediaKindCap() + gatingRulesApply()
   (via gating_rules.php) + contentGatingEnabled(); ccli_validator.php provides
   checkTierAccess() for the accessResolve() primitive. Re-entry safe: CG requires
   this file only lazily inside its delegate bodies, so there is no file-scope
   cycle (see access_context.php doc-block for the full load-order proof). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'content_gating.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ccli_validator.php';

/**
 * Apply tier-based gating to ONE built song payload from a viewer struct — the
 * replacement for the pre-P2 contentGatingApply enforcement core.
 *
 * NO-OP when gating is off or on bypass. Otherwise it trims the payload exactly
 * as before: strip the lyric body when the tier can't view lyrics, or the song
 * is copyrighted and the tier can't view copyrighted material (metadata stays);
 * drop media rows the tier can't use; flip the hasAudio/hasSheetMusic indicator
 * flags; set offlineAllowed; then run the admin-defined rules LAST.
 *
 * @param array $song   The built song array (song_detail/song_data shape).
 * @param array $viewer A struct from accessViewerContext()/accessViewerAssemble().
 * @return array The same array, disallowed fields stripped (or unchanged).
 */
function accessApplySong(array $song, array $viewer): array
{
    /* (A) master switch — the deliberate dormant default (rule A). */
    if (empty($viewer['gatingEnabled'])) {
        return $song;
    }

    try {
        /* content:gated bypass → strip nothing (CG:216–219). */
        if (!empty($viewer['bypass'])) {
            return $song;
        }

        $caps         = is_array($viewer['caps'] ?? null) ? $viewer['caps'] : [];
        $presenceCcli = !empty($viewer['presenceCcli']);

        $canViewLyrics      = !empty($caps['view_lyrics']);
        /* The two CCLI-gated caps get the presence OR-grant (rule #26); the
           others do not (a CCLI licence covers lyric DISPLAY + accompaniment
           audio, not MIDI/PDF/offline redistribution). */
        $canViewCopyrighted = !empty($caps['view_copyrighted']) || $presenceCcli;
        $canPlayAudio       = !empty($caps['play_audio'])       || $presenceCcli;
        $canPlayVideo       = !empty($caps['play_video']);      /* #1968 P4 — no presence OR-grant (a CCLI licence covers audio accompaniment, not video) */
        $canDownloadMidi    = !empty($caps['download_midi']);
        $canDownloadPdf     = !empty($caps['download_pdf']);
        $canOfflineSave     = !empty($caps['offline_save']);

        /* Copyrighted = lyrics NOT public domain (LYRICS axis only). */
        $isCopyrighted = array_key_exists('lyricsPublicDomain', $song)
            && $song['lyricsPublicDomain'] === false;

        /* --- Per-song RIGHTS-FACT gate (DELIBERATELY DEAD in P2) -----------
           Read the lyrics rights fact from the PAYLOAD (never the DB). Triple
           lock keeps it inert on every real install: (1) SongData does not
           select LyricsRightsLicenceKey → the key is absent; (2) no row carries
           a value (P1 NULL DEFAULT NULL, nothing writes it); (3) the guard below
           skips null/''. It flips true only when a future emitter (P6) emits a
           non-empty key naming a licence THIS viewer does not hold — proven live
           by test-gating-equivalence.php's positive fact case, absent on every
           golden. The MUSIC fact is intentionally NOT consulted here (only in
           accessResolve). */
        $factRequiresLyricLicence = false;
        $lyricsRightsKey = $song['lyricsRightsLicenceKey'] ?? null;
        if (is_string($lyricsRightsKey) && $lyricsRightsKey !== '') {
            $viewerLicences = is_array($viewer['licences'] ?? null) ? $viewer['licences'] : [];
            $factRequiresLyricLicence = !in_array($lyricsRightsKey, $viewerLicences, true);
        }

        /* --- Lyric BODY gate ---------------------------------------------- */
        $denyLyricBody = (!$canViewLyrics)
            || ($isCopyrighted && !$canViewCopyrighted)
            || $factRequiresLyricLicence;
        if ($denyLyricBody) {
            /* #2073 — 'rounds'/'vocalWords' joined this list alongside the
               pre-existing 'translations'/'annotations'/'vocalParts': every
               one of them shows "who sings which line" or "what a line
               says", so they are lyric BODY facts and must be stripped
               together. The per-line `voices`/`voiceSpans` keys a
               component can carry ride INSIDE 'components' and need no
               entry of their own — stripping 'components' already removes
               them. `tests/php/test-lyric-body-strip-lockstep.php` asserts
               this array is a superset of SongData::lyricBodyIncludeBlocks()
               (rule #35 — a new block added to one side and not the other
               is a silent content-gating leak, not a compile error). */
            foreach (['components', 'translations', 'annotations', 'vocalParts', 'rounds', 'vocalWords'] as $bodyKey) {
                if (array_key_exists($bodyKey, $song)) {
                    unset($song[$bodyKey]);
                }
            }
            $song['contentRestricted'] = true;
            /* Reason precedence matches the pre-P2 2-way for every golden input
               (the fact branch is never the reason there); the 3rd reason only
               surfaces once the dead fact branch is emitted (P6). */
            $song['restrictionReason'] = (!$canViewLyrics)
                ? 'lyrics_not_available_on_tier'
                : (($isCopyrighted && !$canViewCopyrighted)
                    ? 'copyrighted_requires_higher_tier'
                    : 'licence_required_for_lyrics');
        }

        /* --- Media gates -------------------------------------------------- */
        if (!empty($song['media']) && is_array($song['media'])) {
            $capBool = [
                'play_audio'    => $canPlayAudio,
                'play_video'    => $canPlayVideo,   /* #1968 P4 */
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

        /* Keep the boolean indicator flags consistent with what's emittable. */
        if (!$canPlayAudio && array_key_exists('hasAudio', $song)) {
            $song['hasAudio'] = false;
        }
        if (!$canDownloadPdf && array_key_exists('hasSheetMusic', $song)) {
            $song['hasSheetMusic'] = false;
        }

        /* --- Offline affordance — UNCONDITIONAL additive key. */
        $song['offlineAllowed'] = $canOfflineSave;

        /* --- Admin-defined enforcement rules (#1481 P2) — LAST, self-gated on
           feature_gating_rules_enabled internally. */
        $song = gatingRulesApply($song, (string)($viewer['tier'] ?? 'public'));

        return $song;
    } catch (\Throwable $_e) {
        /* (C) fail-open, logged — returns the part-mutated local, matching the
           pre-P2 monolithic contentGatingApply (which mutated $song in place). */
        error_log('[content_gating] apply failed: ' . $_e->getMessage());
        return $song;
    }
}

/**
 * Is this ONE media row's kind serveable to the viewer? — the replacement for
 * the pre-P2 contentGatingMediaAllowed core (#1388).
 *
 * FAIL-OPEN (rule #28C): gating off, an unknown kind, or any throw ⇒ true.
 * Does NOT consult bypass — the pre-P2 byte gate had no bypass branch, and the
 * media viewer is always built with apiKeyScopes=[] (bypass=false) anyway.
 *
 * @param string $kind      tblSongMedia.Kind — audio | midi | sheet-music | musicxml.
 * @param array  $songFacts Per-song rights facts (unused in P2 — the music-fact
 *                          byte gate is P6). Present so the signature is stable.
 * @param array  $viewer    A struct from accessViewerContext(..., []).
 * @return bool true ⇒ serve the bytes.
 */
function accessMediaAllowed(string $kind, array $songFacts, array $viewer): bool
{
    if (empty($viewer['gatingEnabled'])) {
        return true;
    }
    try {
        $cap = contentGatingMediaKindCap($kind);
        if ($cap === null) { return true; }   /* unknown kind — leave it */
        $caps         = is_array($viewer['caps'] ?? null) ? $viewer['caps'] : [];
        $presenceCcli = !empty($viewer['presenceCcli']);
        /* Audio keeps its Service-Mode presence OR-grant (byte-gate-specific). */
        return !empty($caps[$cap]) || ($cap === 'play_audio' && $presenceCcli);
    } catch (\Throwable $_e) {
        return true;
    }
}

/**
 * General allow/deny verdict for ONE action against a song's rights facts —
 * the P3/P6 primitive. NOT WIRED in P2 (no production caller); implemented and
 * unit-tested now so the emitter adoption in P3 has a proven decision function.
 *
 * Resolution order: gating off → open; bypass → allow; the shared checkTierAccess
 * verdict (reason/upgradeTo passthrough); presence OR for view_copyrighted /
 * play_audio; then a per-fact AND-gate — the lyric family keys on
 * lyricsRightsLicenceKey, the music family on musicRightsLicenceKey, and a viewer
 * who lacks that licence key is denied (coverage-kind refinement is P6). An
 * action in neither family gets the plain tier verdict.
 *
 * @param array  $viewer A struct from accessViewerContext()/accessViewerAssemble().
 * @param array  $facts  Per-song rights facts (lyricsRightsLicenceKey / musicRightsLicenceKey).
 * @param string $action A TIER_ACTION_CAP_MAP action (view_lyrics, play_audio, …).
 * @return array{allowed:bool,reason:string,upgradeTo:?string,source:string}
 */
function accessResolve(array $viewer, array $facts, string $action): array
{
    if (empty($viewer['gatingEnabled'])) {
        return ['allowed' => true, 'reason' => '', 'upgradeTo' => null, 'source' => 'gating-off'];
    }
    if (!empty($viewer['bypass'])) {
        return ['allowed' => true, 'reason' => '', 'upgradeTo' => null, 'source' => 'bypass'];
    }

    $tier         = (string)($viewer['tier'] ?? 'public');
    $hasCcli      = !empty($viewer['hasCcli']);
    $presenceCcli = !empty($viewer['presenceCcli']);

    /* Tier verdict via the shared registry-backed resolver (rule #28B). */
    $verdict   = checkTierAccess($tier, $action, $hasCcli);
    $allowed   = !empty($verdict['allowed']);
    $reason    = (string)($verdict['reason'] ?? '');
    $upgradeTo = $verdict['upgradeTo'] ?? null;
    $source    = 'tier';

    /* Presence OR-grant for the two CCLI-gated caps. */
    if (!$allowed && $presenceCcli && ($action === 'view_copyrighted' || $action === 'play_audio')) {
        $allowed = true;
        $reason = '';
        $upgradeTo = null;
        $source = 'presence';
    }

    /* Per-fact AND-gate: a lyric/music-family action is additionally denied when
       the song carries a rights-licence key the viewer does not hold. */
    if ($allowed) {
        $factKey = null;
        if ($action === 'view_lyrics' || $action === 'view_copyrighted') {
            $factKey = $facts['lyricsRightsLicenceKey'] ?? null;
        } elseif ($action === 'play_audio' || $action === 'download_midi'
               || $action === 'download_pdf' || $action === 'offline_save') {
            $factKey = $facts['musicRightsLicenceKey'] ?? null;
        }
        if (is_string($factKey) && $factKey !== '') {
            $viewerLicences = is_array($viewer['licences'] ?? null) ? $viewer['licences'] : [];
            if (!in_array($factKey, $viewerLicences, true)) {
                $allowed = false;
                $reason = 'licence_required';
                $upgradeTo = null;
                $source = 'fact';
            }
        }
    }

    return ['allowed' => $allowed, 'reason' => $reason, 'upgradeTo' => $upgradeTo, 'source' => $source];
}
