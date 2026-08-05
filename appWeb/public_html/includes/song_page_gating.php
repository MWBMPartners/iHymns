<?php

declare(strict_types=1);

/**
 * iHymns — Song-page content-gating decision seam (#1769 P3)
 * =========================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The public HTML song page (`includes/pages/song.php`) renders its OWN media
 * buttons and lyric body straight from `$song`, so it has always had its own
 * copy of the tier/presence gating logic alongside the JSON API's. #1769 P3
 * pulls that decision maths out of the page into ONE pure function here, so the
 * page and the API can't drift and the decision can be replayed DB-free against
 * a golden matrix.
 *
 * DETAILED / ONE FUNCTION, VIEWER-DRIVEN
 * --------------------------------------
 * `songPageGatingDecide(array $viewer, …)` reads the tier verdicts + presence off
 * the #1769 viewer struct (built once by accessViewerContext) and returns the six
 * decisions song.php consumes. It was proven a byte-identical no-op against the
 * pre-P3 inline maths via the P2-style A→B→C protocol: Commit A extracted the
 * legacy maths verbatim (`songPageGatingDecideLegacy`), Commit B froze its
 * outputs over a synthetic matrix (`tools/capture-song-page-gating-goldens.php`
 * → `tests/php/fixtures/song-page-gating-goldens.json`), and Commit C (this)
 * added the viewer-driven form, proved it reproduces every golden
 * (`test-song-page-gating-equivalence.php`), and DELETED the legacy seam — the
 * exact P2 `_contentGating*LegacyCore` pattern.
 *
 * Require-once safe: bulk_songs and gating-noop-verify.php include song.php
 * repeatedly, so the maths MUST live in a function, not inline in the fragment.
 *
 * @link .claude/gating-p3-design.md  §2 the collapse + §4 the verification
 * @see  includes/access_resolver.php  the JSON-API pipeline this mirrors
 * @see  includes/content_gating.php   contentGatingMediaKindCap() (the ONE kind→cap map)
 * @see  tests/php/test-song-page-gating-equivalence.php  the byte-identical replay (Commit C)
 */

/* Library, never a page. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* contentGatingMediaKindCap() (content_gating) — the ONE kind→cap map. The tier
   verdicts now arrive pre-resolved on the #1769 viewer struct, so this file no
   longer calls checkTierAccess itself. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'content_gating.php';

/**
 * The song-page gating decision, from the #1769 viewer struct (P3 Commit C —
 * replaces the Commit-A legacy seam, proven byte-identical against the frozen
 * goldens by tests/php/test-song-page-gating-equivalence.php).
 *
 * Reads the viewer's already-resolved per-action caps + presence flag (built once
 * by accessViewerContext) and returns the six decision outputs song.php consumes.
 * It preserves, byte-for-byte, the page's DELIBERATE pre-existing quirk that the
 * Service-Mode presence unlock ORs into ALL THREE media kinds (audio + sheet +
 * midi), whereas the JSON API pipeline (accessMediaAllowed) ORs presence only
 * into audio — a CCLI licence covers display + accompaniment, not MIDI/PDF
 * redistribution. That web/API divergence is dormant and is an owner decision
 * (#1769 P3 Commit G issue); the golden replay is what PINS it (rule #35), never
 * a "keep in sync" comment. That is why sheet/midi read the caps + presence
 * directly here instead of calling accessMediaAllowed() (which would silently
 * converge them onto the API's audio-only rule).
 *
 * $presenceNumber (the actual CCLI number, or '' / null) is passed alongside the
 * viewer because the viewer carries only the presenceCcli BOOL; the copyrighted-
 * only CCL notice needs the number. In every caller $viewer['presenceCcli'] ===
 * ($presenceNumber !== null) by construction (same serviceMode lookup).
 *
 * @param array   $viewer            An access_context.php viewer struct (ACCESS_VIEWER_KEYS).
 * @param bool    $entityAllowed     checkContentAccess() verdict (the per-song legal gate).
 * @param string  $entityReason      its reason when denied.
 * @param ?string $presenceNumber    serviceMode_presenceCcliNumber() (PD-independent), or null.
 * @param bool    $lyricsPublicDomain $song['lyricsPublicDomain'].
 * @param bool    $fullyPublicDomain  lyrics AND music PD.
 * @param bool    $hasAudio           the page's pre-gate hasAudio flag.
 * @param bool    $hasSheet           the page's pre-gate hasSheet flag.
 * @param array   $media              $song['media'] (or [] when absent — caller re-apply guard).
 * @return array{lyricsGated:bool,gateReason:string,serviceCcliNumber:?string,hasAudio:bool,hasSheet:bool,media:array}
 */
function songPageGatingDecide(
    array $viewer,
    bool $entityAllowed,
    string $entityReason,
    ?string $presenceNumber,
    bool $lyricsPublicDomain,
    bool $fullyPublicDomain,
    bool $hasAudio,
    bool $hasSheet,
    array $media
): array {
    $caps         = is_array($viewer['caps'] ?? null) ? $viewer['caps'] : [];
    $presenceCcli = !empty($viewer['presenceCcli']);

    /* ENTITY gate (authoritative). */
    $lyricsGated = !$entityAllowed;
    $gateReason  = $entityAllowed ? '' : $entityReason;

    /* CCL-notice number: only for a COPYRIGHTED song (not fully PD), entity-allowed,
       with a present token. Drives the per-device notice. */
    $serviceCcliNumber = ($entityAllowed && !$fullyPublicDomain && $presenceNumber !== null)
        ? $presenceNumber
        : null;

    /* TIER lyric gate — copyrighted lyrics need view_copyrighted unless a presence
       unlock is active ($serviceCcliNumber). Reads the viewer's cap (no presence
       OR baked in — presence is expressed by $serviceCcliNumber, kept verbatim). */
    if (!$lyricsGated && !$lyricsPublicDomain && $serviceCcliNumber === null) {
        if (empty($caps['view_copyrighted'])) {
            $lyricsGated = true;
            $gateReason  = 'A higher access tier is required to view these lyrics.';
        }
    }

    /* MEDIA-affordance gate. $mediaPresenceOk is PD-independent (== the viewer's
       presenceCcli). Sheet/midi keep the page's presence-OR quirk (see doc-block). */
    $mediaPresenceOk = $presenceCcli;
    $audioOk = $mediaPresenceOk || !empty($caps['play_audio']);
    $sheetOk = $mediaPresenceOk || !empty($caps['download_pdf']);
    $midiOk  = $mediaPresenceOk || !empty($caps['download_midi']);

    /* Toolbar flags — only ever flipped true→false. */
    if (!$audioOk) { $hasAudio = false; }
    if (!$sheetOk) { $hasSheet = false; }

    /* Recordings list — drop the rows the tier can't use, keyed by the ONE shared
       kind→cap map. Caller only re-applies when media existed. */
    if (!empty($media)) {
        $capBool = [
            'play_audio'    => $audioOk,
            'download_midi' => $midiOk,
            'download_pdf'  => $sheetOk,
        ];
        $media = array_values(array_filter(
            $media,
            static function ($m) use ($capBool): bool {
                $kind = is_array($m) ? (string)($m['kind'] ?? '') : '';
                $cap  = contentGatingMediaKindCap($kind);
                return $cap === null ? true : ($capBool[$cap] ?? true);
            }
        ));
    }

    return [
        'lyricsGated'       => $lyricsGated,
        'gateReason'        => $gateReason,
        'serviceCcliNumber' => $serviceCcliNumber,
        'hasAudio'          => $hasAudio,
        'hasSheet'          => $hasSheet,
        'media'             => $media,
    ];
}
