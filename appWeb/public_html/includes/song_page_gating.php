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
 * DETAILED / TWO SEAMS, LIKE P2
 * -----------------------------
 * `songPageGatingDecideLegacy()` (this file, Commit A) is a VERBATIM code-motion
 * of song.php's pre-P3 maths (lines ~290-356): it takes the already-resolved
 * viewer scalars ($viewerTier / $viewerHasCcli / $presenceNumber) and reproduces
 * the exact decisions the page made inline. `tools/capture-song-page-gating-
 * goldens.php` freezes its outputs over a synthetic matrix; then Commit C adds
 * `songPageGatingDecide(array $viewer, …)` (reading tier/caps/presenceCcli off
 * the #1769 viewer struct), proves it byte-identical against those goldens, and
 * DELETES this legacy seam — exactly the P2 `_contentGating*LegacyCore` pattern.
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

/* checkTierAccess() (ccli_validator) + contentGatingMediaKindCap() (content_gating). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'content_gating.php';

/**
 * VERBATIM code-motion of song.php's pre-P3 gating maths (#1769 P3 Commit A).
 * Takes the already-resolved viewer scalars + the entity verdict + the payload
 * flags, and returns the six decision outputs the page consumes. Reproduces the
 * page's decisions branch-for-branch — INCLUDING the deliberate quirk that the
 * page ORs the Service-Mode presence unlock into ALL THREE media kinds (audio +
 * sheet + midi), whereas the JSON API ORs presence only into audio/copyrighted
 * (a CCLI licence covers display + accompaniment, not MIDI/PDF redistribution).
 * That divergence is pre-existing and dormant; P3 preserves it byte-identically
 * and files it as an owner decision — the golden replay is what pins it (rule
 * #35), never a "keep in sync" comment.
 *
 * @internal #1769 P3 scaffolding — REPLACED by songPageGatingDecide() + DELETED in Commit C.
 *
 * @param string  $viewerTier        resolveEffectiveTier() result (already ''→public normalised).
 * @param bool    $viewerHasCcli      contentGating_userHasCcli().
 * @param ?string $presenceNumber     serviceMode_presenceCcliNumber() (PD-independent), or null.
 * @param bool    $entityAllowed      checkContentAccess() verdict (the per-song legal gate).
 * @param string  $entityReason       its reason when denied.
 * @param bool    $lyricsPublicDomain $song['lyricsPublicDomain'].
 * @param bool    $fullyPublicDomain  lyrics AND music PD.
 * @param bool    $hasAudio           the page's pre-gate hasAudio flag.
 * @param bool    $hasSheet           the page's pre-gate hasSheet flag.
 * @param array   $media              $song['media'] (or [] when absent — see the caller re-apply guard).
 * @return array{lyricsGated:bool,gateReason:string,serviceCcliNumber:?string,hasAudio:bool,hasSheet:bool,media:array}
 */
function songPageGatingDecideLegacy(
    string $viewerTier,
    bool $viewerHasCcli,
    ?string $presenceNumber,
    bool $entityAllowed,
    string $entityReason,
    bool $lyricsPublicDomain,
    bool $fullyPublicDomain,
    bool $hasAudio,
    bool $hasSheet,
    array $media
): array {
    /* ENTITY gate (authoritative) — song.php lines 250-252. */
    $lyricsGated = !$entityAllowed;
    $gateReason  = $entityAllowed ? '' : $entityReason;

    /* CCL-notice number: only for a COPYRIGHTED song (not fully PD), entity-allowed,
       with a present token — song.php lines 253-260. Drives the per-device notice. */
    $serviceCcliNumber = ($entityAllowed && !$fullyPublicDomain && $presenceNumber !== null)
        ? $presenceNumber
        : null;

    /* TIER lyric gate — song.php lines 290-297. Copyrighted lyrics need the tier's
       view_copyrighted cap unless a presence unlock is active ($serviceCcliNumber). */
    if (!$lyricsGated && !$lyricsPublicDomain && $serviceCcliNumber === null) {
        $tierVerdict = checkTierAccess($viewerTier ?: 'public', 'view_copyrighted', $viewerHasCcli);
        if (empty($tierVerdict['allowed'])) {
            $lyricsGated = true;
            $gateReason  = 'A higher access tier is required to view these lyrics.';
        }
    }

    /* MEDIA-affordance gate — song.php lines 308-356.
       $mediaPresenceOk is PD-INDEPENDENT (unlike $serviceCcliNumber): a present
       congregant keeps media even on a fully-PD song. It equals ($presenceNumber
       !== null) — provably identical to the page's old two-step derivation
       ($serviceCcliNumber!==null) || re-resolve, because the re-resolve reads the
       same presence lookup. */
    $mediaPresenceOk = ($presenceNumber !== null);
    $audioOk = $mediaPresenceOk
        || !empty(checkTierAccess($viewerTier ?: 'public', 'play_audio', $viewerHasCcli)['allowed']);
    $sheetOk = $mediaPresenceOk
        || !empty(checkTierAccess($viewerTier ?: 'public', 'download_pdf', $viewerHasCcli)['allowed']);
    $midiOk  = $mediaPresenceOk
        || !empty(checkTierAccess($viewerTier ?: 'public', 'download_midi', $viewerHasCcli)['allowed']);

    /* Toolbar flags — only ever flipped true→false (song.php 330-331). */
    if (!$audioOk) { $hasAudio = false; }
    if (!$sheetOk) { $hasSheet = false; }

    /* Recordings list — drop the rows the tier can't use, keyed by the ONE shared
       kind→cap map (song.php 341-355). Caller only re-applies when media existed. */
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
