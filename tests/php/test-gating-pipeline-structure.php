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

/* ---- (b) viewer-struct mechanism: every $viewer['…'] literal ANYWHERE under
 * includes/ is a real ACCESS_VIEWER_KEYS member. TREE-DERIVED (rule #34) — scans
 * every .php under includes/ recursively, so a new consumer (e.g. the P3
 * song_page_gating.php) is covered automatically, not by a typed file list. --- */
$badViewerKeys = [];
$sawViewerKey  = false;
$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
    $root . '/includes', \FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $src = (string)file_get_contents($f->getPathname());
    if (preg_match_all('/\$viewer\[\'([a-zA-Z_]+)\'\]/', $src, $m)) {
        $rel = str_replace($root, '', $f->getPathname());
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
    '(b) every $viewer[…] key read anywhere under includes/ is in ACCESS_VIEWER_KEYS'
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

/* ---- (e) song.php fork collapse (#1769 P3): the page + its gating seam resolve
 * nothing themselves — they route through the ONE viewer struct. ------------- */
$songSrc = (string)file_get_contents($root . '/includes/pages/song.php');
$spgSrc  = (string)file_get_contents($root . '/includes/song_page_gating.php');

/* song.php no longer resolves the viewer inline (that moved into accessViewerContext). */
_psAssert(strpos($songSrc, 'resolveEffectiveTier(') === false,
    '(e) song.php no longer calls resolveEffectiveTier() directly (routes through accessViewerContext)');
_psAssert(strpos($songSrc, 'checkTierAccess(') === false,
    '(e) song.php no longer calls checkTierAccess() directly (reads caps off the viewer)');
_psAssert(strpos($songSrc, 'contentGating_userHasCcli(') === false,
    '(e) song.php no longer calls contentGating_userHasCcli() directly (viewer resolves ccli)');

/* The seam reads caps off the viewer — it does NOT resolve tiers itself. */
_psAssert(strpos($spgSrc, 'checkTierAccess(') === false && strpos($spgSrc, 'resolveEffectiveTier(') === false,
    '(e) song_page_gating.php reads caps off the viewer, never resolves a tier itself');

/* The nastiest trap: the page's viewer MUST be built with apiKeyScopes=[] (no
   bypass on a caps-reading page — null would GATE a bypass-key render). */
_psAssert(strpos($songSrc, "accessViewerContext(\$tierViewerId, 'PWA', \$presenceTok !== '' ? \$presenceTok : null, [])") !== false,
    '(e) song.php builds its viewer with apiKeyScopes=[] (the bypass trap)');
_psAssert(strpos($songSrc, 'songPageGatingDecide(') !== false,
    '(e) song.php delegates its gating decision to songPageGatingDecide()');

/* Presence is resolved ONCE on the page (the design collapsed the old double
   lookup); the viewer resolves its own presenceCcli separately, so song.php
   should carry exactly one serviceMode_presenceCcliNumber( call. */
_psAssert(substr_count($songSrc, 'serviceMode_presenceCcliNumber(') === 1,
    '(e) song.php resolves the presence number exactly once (the design de-duplicated it)');

/* ---- (f) cache exclusion (#1769 P3 Commit E): api.php excludes the gated song
 * fragment from the shared cache + sends no-store, and the service worker honours
 * no-store at its INCIDENTAL fragment/song put sites (not the deliberate download,
 * which must still work for entitled users). ------------------------------- */
$apiSrc = (string)file_get_contents($root . '/api.php');
_psAssert(strpos($apiSrc, '$_gatedSongFragment') !== false
       && strpos($apiSrc, "header('Cache-Control: private, no-store')") !== false,
    '(f) api.php excludes the gated page=song fragment + sends Cache-Control: no-store');
_psAssert((bool)preg_match('/\$_gatedSongFragment\s*\)\s*\{\s*\$_shouldCachePage\s*=\s*false;/s', $apiSrc),
    '(f) the gated song fragment is removed from $_shouldCachePage (not shared-cached)');

$swSrc = (string)file_get_contents($root . '/service-worker.js.php');
_psAssert(strpos($swSrc, 'function swResponseCacheable(') !== false,
    '(f) service-worker.js.php defines swResponseCacheable()');
/* The two INCIDENTAL fetch-handler puts consult it (a gated no-store fragment
   never lands in the URL-keyed PAGES_CACHE / RECENT_CACHE that could leak across
   viewers of one device offline). */
_psAssert(strpos($swSrc, 'offlinePage && response.ok && swResponseCacheable(response)') !== false,
    '(f) the SW page-fragment put honours swResponseCacheable()');
_psAssert(strpos($swSrc, 'isSongPage && response.ok && swResponseCacheable(response)') !== false,
    '(f) the SW incidental song put honours swResponseCacheable()');

/* ----------------------------------------------------------------------- */
echo "\ngating-pipeline-structure: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
