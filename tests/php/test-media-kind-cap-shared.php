<?php

declare(strict_types=1);

/**
 * iHymns — one shared media-kind → capability map, no re-inlined copies (#1769 P0, OV-11)
 * =======================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * "Which permission does an audio / MIDI / sheet-music / MusicXML file need?"
 * used to be answered by the SAME little switch statement copy-pasted in three
 * files (the API payload strip, the file-byte gate, and the HTML song page),
 * each "kept in sync" by a comment pointing at the others' line numbers — which
 * had already drifted. #1769 P0 extracted it to ONE function,
 * contentGatingMediaKindCap(). This guard proves (a) the map is correct and
 * (b) no file has quietly re-inlined its own copy.
 *
 *   Part A (behavioural, no DB) — the function returns the right cap for each
 *     kind and null for an unknown one.
 *   Part B (de-dup source scan) — the tell-tale `case 'sheet-music':` +
 *     `case 'musicxml':` switch appears ONLY inside the shared function (once
 *     in content_gating.php) and NOT in song.php, access_resolver.php, or
 *     anywhere else; the enforcement pipeline (both accessApplySong() and
 *     accessMediaAllowed() in access_resolver.php since #1769 P2 Commit E) and
 *     the HTML page all CALL the shared function instead of re-inlining it.
 *
 * NOTE (#1769 P2 Commit E): the two enforcement CONSUMERS moved out of
 * content_gating.php into the Model-2 pipeline (includes/access_resolver.php) —
 * contentGatingMediaKindCap() is still DEFINED in content_gating.php, but its
 * uses are now accessApplySong()'s media filter + accessMediaAllowed().
 *
 * MUTATION PROOF (rule #34): re-inline the switch into song.php or
 * access_resolver.php (or change a mapping in the function) and this goes red;
 * restore → green. (Proven by the author via cp-backup.)
 *
 * @link appWeb/public_html/includes/content_gating.php   contentGatingMediaKindCap() (#1769 P0)
 * @link appWeb/public_html/includes/access_resolver.php  the two enforcement consumers (#1769 P2)
 * @link appWeb/public_html/includes/pages/song.php       the HTML page consumer
 */

$repoRoot = dirname(__DIR__, 2);
$failures = [];

/* =========================================================================
 * PART A — behavioural (no DB). Load the module and check the map.
 * ========================================================================= */
$_SERVER['SCRIPT_FILENAME'] = 'test-runner'; // dodge the direct-access 403 guard
require_once $repoRoot . '/appWeb/public_html/includes/content_gating.php';

if (!function_exists('contentGatingMediaKindCap')) {
    $failures[] = "content_gating.php does not define contentGatingMediaKindCap() — the ONE shared kind→cap map.";
} else {
    $expect = [
        'audio'       => 'play_audio',
        'midi'        => 'download_midi',
        'sheet-music' => 'download_pdf',
        'musicxml'    => 'download_pdf',
        'unknown-xyz' => null,
        ''            => null,
    ];
    foreach ($expect as $kind => $cap) {
        $got = contentGatingMediaKindCap($kind);
        if ($got !== $cap) {
            $failures[] = "contentGatingMediaKindCap('{$kind}') = " . var_export($got, true)
                        . ", expected " . var_export($cap, true) . ".";
        }
    }
}

/* =========================================================================
 * PART B — de-dup source scan. The switch must live ONLY in the shared
 * function; every consumer must call it, not re-inline it.
 * ========================================================================= */
$cgFile   = $repoRoot . '/appWeb/public_html/includes/content_gating.php';
$arFile   = $repoRoot . '/appWeb/public_html/includes/access_resolver.php';
$songFile = $repoRoot . '/appWeb/public_html/includes/pages/song.php';
$cgSrc    = (string)file_get_contents($cgFile);
$arSrc    = (string)file_get_contents($arFile);
$songSrc  = (string)file_get_contents($songFile);

/* The media-kind switch's signature line. It should appear exactly once, inside
   contentGatingMediaKindCap() in content_gating.php, and NOWHERE else — not in
   song.php and not in the enforcement pipeline (access_resolver.php). */
$cgSwitch   = substr_count($cgSrc, "case 'sheet-music':");
$arSwitch   = substr_count($arSrc, "case 'sheet-music':");
$songSwitch = substr_count($songSrc, "case 'sheet-music':");
if ($cgSwitch !== 1) {
    $failures[] = "content_gating.php contains {$cgSwitch} `case 'sheet-music':` blocks — expected exactly 1 "
                . "(inside contentGatingMediaKindCap). More than one means the kind→cap switch was re-inlined "
                . "somewhere instead of calling the shared map (#1769 P0, OV-11).";
}
if ($arSwitch !== 0) {
    $failures[] = "access_resolver.php contains {$arSwitch} `case 'sheet-music':` block(s) — expected 0; its "
                . "media filter + byte gate must call contentGatingMediaKindCap() rather than re-inline the switch "
                . "(#1769 P2 regression).";
}
if ($songSwitch !== 0) {
    $failures[] = "song.php contains {$songSwitch} `case 'sheet-music':` block(s) — expected 0; it must call "
                . "contentGatingMediaKindCap() rather than re-inline its own copy (the #1769 P0 regression).";
}
if (!str_contains($songSrc, 'contentGatingMediaKindCap(')) {
    $failures[] = "song.php does not call contentGatingMediaKindCap() — its media-affordance gate must use the "
                . "shared map so the page and the API agree.";
}
/* content_gating.php DEFINES the function (≥1 reference — the definition). */
if (substr_count($cgSrc, 'contentGatingMediaKindCap') < 1) {
    $failures[] = "content_gating.php no longer references contentGatingMediaKindCap — the shared map definition "
                . "went missing (#1769 P0, OV-11).";
}
/* access_resolver.php's TWO enforcement consumers (accessApplySong()'s media
   filter + accessMediaAllowed()) each CALL the shared map — the two uses that
   moved out of content_gating.php in #1769 P2 Commit E. At least 2 call sites. */
if (substr_count($arSrc, 'contentGatingMediaKindCap(') < 2) {
    $failures[] = "access_resolver.php calls contentGatingMediaKindCap() fewer than 2 times — expected its use in "
                . "BOTH accessApplySong()'s media filter and accessMediaAllowed() (#1769 P2 Commit E). A missing "
                . "call means one path re-decided the kind→cap mapping for itself.";
}

if ($failures) {
    fwrite(STDERR, "FAIL: shared media-kind→cap guard (#1769 P0, OV-11):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\n");
    exit(1);
}
echo "PASS: media kind→cap lives in ONE function (contentGatingMediaKindCap); the payload strip, the byte gate "
   . "and the HTML song page all call it, with no re-inlined switch.\n";
exit(0);
