<?php

declare(strict_types=1);

/**
 * iHymns — content-gating pipeline structure guard (#1769 P2 Commit E)
 * ===================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The byte-identical equivalence guard (test-gating-equivalence.php) proves the
 * pipeline BEHAVES right today. This proves the STRUCTURE that keeps it right
 * cannot silently drift:
 *   (a) the rights-fact branch stays DEAD — SongData (the public read path)
 *       emits NEITHER rights-fact key, so the fact the resolver reads is absent
 *       on every real payload (lock #1 of the triple lock). This is the mutation
 *       sentinel for "emit lyricsRightsLicenceKey from SongData".
 *   (b) every $viewer['…'] key the pipeline reads is a real ACCESS_VIEWER_KEYS
 *       member — a typo'd/renamed key (a silent-null class) is caught. Derived
 *       from the tree (grep), then asserted against the live constant.
 *   (c) the pre-refactor legacy cores are GONE (the goldens are frozen; nothing
 *       may quietly re-introduce a second enforcement path).
 *   (d) both content_gating.php delegates route through the ONE pipeline, and the
 *       media delegate passes apiKeyScopes=[] (no bypass on the byte gate).
 *
 * DB-free: pure source scan + the ACCESS_VIEWER_KEYS constant (no getDbMysqli).
 *
 * @link .claude/gating-p2-design.md  §"Tests/guards (Commit E)"
 * @see  appWeb/public_html/includes/access_context.php   ACCESS_VIEWER_KEYS
 * @see  appWeb/public_html/includes/access_resolver.php  the pipeline under guard
 */

$root     = dirname(__DIR__, 2) . '/appWeb/public_html';
$failures = 0;
$passed   = 0;
function _psAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

$_SERVER['SCRIPT_FILENAME'] = 'test-runner';
require_once $root . '/includes/access_context.php'; // for ACCESS_VIEWER_KEYS

/* ---- (a) facts-inertness lock: SongData emits neither rights-fact key ----
 * The resolver reads $song['lyricsRightsLicenceKey'] from the PAYLOAD; if
 * SongData (getSongById/_fetchSongRow — the public read path) ever SELECTed the
 * LyricsRightsLicenceKey/MusicRightsLicenceKey column and mapped it, the dead
 * branch would go LIVE for public payloads. P2 keeps it dormant, so SongData
 * must reference neither the columns nor the camelCase payload keys. (Flipping
 * this is a deliberate P6 act — the guard failing then is the intended signal.)
 */
$songData = (string)file_get_contents($root . '/includes/SongData.php');
foreach (['LyricsRightsLicenceKey', 'MusicRightsLicenceKey',
          'lyricsRightsLicenceKey', 'musicRightsLicenceKey'] as $needle) {
    _psAssert(strpos($songData, $needle) === false,
        "(a) SongData.php does not reference '{$needle}' (rights fact stays dead on the public read path)");
}

/* ---- (b) viewer-struct mechanism: every $viewer['…'] literal the pipeline
 * reads is a real ACCESS_VIEWER_KEYS member. Derived from the tree. --------- */
$viewerKeyFiles = [
    '/includes/access_resolver.php',
    '/includes/access_context.php',
    '/includes/content_gating.php',
];
$badViewerKeys = [];
$sawViewerKey  = false;
foreach ($viewerKeyFiles as $rel) {
    $src = (string)file_get_contents($root . $rel);
    if (preg_match_all('/\$viewer\[\'([a-zA-Z_]+)\'\]/', $src, $m)) {
        foreach ($m[1] as $k) {
            $sawViewerKey = true;
            if (!in_array($k, ACCESS_VIEWER_KEYS, true)) {
                $badViewerKeys[] = "{$rel}: \$viewer['{$k}']";
            }
        }
    }
}
_psAssert($sawViewerKey, '(b) the guard actually found $viewer[…] key literals to check');
_psAssert($badViewerKeys === [],
    '(b) every $viewer[…] key read by the pipeline is in ACCESS_VIEWER_KEYS'
    . ($badViewerKeys ? ' — offenders: ' . implode(', ', $badViewerKeys) : ''));

/* ---- (c) the pre-refactor legacy cores are gone from includes/. --------- */
$includesGlob = glob($root . '/includes/*.php') ?: [];
$legacyCoreHits = [];
foreach ($includesGlob as $f) {
    $src = (string)file_get_contents($f);
    foreach (['_contentGatingApplyLegacyCore', '_contentGatingMediaAllowedLegacyCore'] as $core) {
        if (strpos($src, $core) !== false) { $legacyCoreHits[] = basename($f) . ':' . $core; }
    }
}
_psAssert($legacyCoreHits === [],
    '(c) the Commit-A legacy cores are deleted (no second enforcement path)'
    . ($legacyCoreHits ? ' — found: ' . implode(', ', $legacyCoreHits) : ''));

/* ---- (d) both delegates route through the ONE pipeline. ----------------- */
$cg = (string)file_get_contents($root . '/includes/content_gating.php');

$applyStart = strpos($cg, 'function contentGatingApply(');
$applyBody  = $applyStart !== false ? substr($cg, $applyStart, 1600) : '';
_psAssert($applyStart !== false, '(d) contentGatingApply() is defined');
_psAssert(strpos($applyBody, "require_once __DIR__ . DIRECTORY_SEPARATOR . 'access_context.php'") !== false
       && strpos($applyBody, "require_once __DIR__ . DIRECTORY_SEPARATOR . 'access_resolver.php'") !== false,
    '(d) contentGatingApply() lazy-requires the pipeline files');
_psAssert(strpos($applyBody, 'accessViewerContext(') !== false
       && strpos($applyBody, 'accessApplySong(') !== false,
    '(d) contentGatingApply() delegates to accessViewerContext() + accessApplySong()');

$mediaStart = strpos($cg, 'function contentGatingMediaAllowed(');
$mediaBody  = $mediaStart !== false ? substr($cg, $mediaStart, 1600) : '';
_psAssert($mediaStart !== false, '(d) contentGatingMediaAllowed() is defined');
_psAssert(strpos($mediaBody, 'accessViewerContext($userId, \'PWA\', $presenceToken, [])') !== false,
    '(d) the media delegate builds its viewer with apiKeyScopes=[] (no bypass on the byte gate)');
_psAssert(strpos($mediaBody, 'accessMediaAllowed(') !== false,
    '(d) contentGatingMediaAllowed() delegates to accessMediaAllowed()');

/* ----------------------------------------------------------------------- */
echo "\ngating-pipeline-structure: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
