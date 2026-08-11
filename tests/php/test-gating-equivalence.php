<?php

declare(strict_types=1);

/**
 * iHymns — content-gating pipeline equivalence guard (#1769 P2 Commit E)
 * =====================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Commit E replaced the old inline content-gating enforcement with the new
 * Model-2 pipeline (accessViewerContext → accessApplySong / accessMediaAllowed).
 * This proves the swap is a BYTE-IDENTICAL no-op: it replays the frozen goldens
 * (tests/php/fixtures/gating-goldens.json, captured from the pre-refactor code in
 * Commit B) through the NEW pipeline and asserts every output matches to the
 * comma. If the pipeline changes one field of one output, this goes red.
 *
 * DETAILED / WHY DB-FREE
 * ----------------------
 * The goldens were captured with checkTierAccess() in its hardcoded-matrix
 * fallback (no DB) — CI runs no MySQL for getDbMysqli() (no
 * appWeb/.auth/db_credentials.php), so this test resolves caps through that same
 * matrix and the replay is exact. If a DB IS reachable (a dev box with
 * credentials), checkTierAccess() would read the LIVE tblAccessTiers rows, which
 * may diverge from the matrix the goldens were captured under — so this SKIPS
 * with a message (mirroring tools/capture-gating-goldens.php's refusal and
 * test-gating-noop.php's skip). Move the credentials aside to run the replay
 * locally.
 *
 * It also exercises the DELIBERATELY-DEAD rights-fact branch positively (proving
 * the P6 machinery is live though inert on every real payload), the unwired
 * accessResolve() primitive, and the bypass/off identities.
 *
 * @link .claude/gating-p2-design.md  §"Tests/guards (Commit E)"
 * @see  tools/capture-gating-goldens.php  the capture side (Commit B)
 * @see  appWeb/public_html/includes/access_resolver.php  the pipeline under proof
 */

$root     = dirname(__DIR__, 2) . '/appWeb/public_html';
$failures = 0;
$passed   = 0;
function _eqAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

$_SERVER['SCRIPT_FILENAME'] = 'test-runner'; // dodge the includes' direct-access 403 guards
require_once $root . '/includes/access_context.php';
require_once $root . '/includes/access_resolver.php';

$SHA_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES; // matches the capture tool

/* ---- DB-free precondition (see the doc-block) --------------------------- */
$dbReachable = false;
try { getDbMysqli(); $dbReachable = true; } catch (\Throwable $_e) { $dbReachable = false; }
if ($dbReachable) {
    echo "SKIP: a DB is reachable via getDbMysqli() — the goldens are matrix-captured (DB-free).\n";
    echo "      Move appWeb/.auth/db_credentials.php aside to run the equivalence replay.\n";
    exit(0);
}

/* ---- Matrix sentinels — prove checkTierAccess is in the fallback-matrix mode
 * the goldens were captured under, BEFORE trusting them (mirrors
 * test-gating-noop.php §6-9). ------------------------------------------------ */
_eqAssert(empty(checkTierAccess('public', 'view_copyrighted', false)['allowed']),
    'sentinel: public tier DENIED view_copyrighted (matrix mode)');
_eqAssert(!empty(checkTierAccess('free', 'view_copyrighted', false)['allowed']),
    'sentinel: free tier ALLOWED view_copyrighted (matrix mode)');

/* ---- Load the fixture --------------------------------------------------- */
$fixturePath = __DIR__ . '/fixtures/gating-goldens.json';
$fixture = json_decode((string)@file_get_contents($fixturePath), true);
if (!is_array($fixture) || !isset($fixture['apply'], $fixture['media'], $fixture['matrixSha'])) {
    fwrite(STDERR, "FATAL: could not load/parse $fixturePath\n");
    exit(1);
}
$applyRows = $fixture['apply'];
$mediaRows = $fixture['media'];

/* ---- (1) APPLY replay: accessApplySong() over the built viewer must equal
 * the captured legacy-core output, byte for byte, AND reproduce its sha. --- */
$applyMismatch = 0;
foreach ($applyRows as $row) {
    $viewer = accessViewerAssemble(
        true, null, 'PWA',
        (string)$row['tier'], (bool)$row['hasCcli'], [], null, (bool)$row['presenceCcli'], false
    );
    $out = accessApplySong($row['input'], $viewer);
    $okEqual = ($out === $row['output']);
    $okSha   = (hash('sha256', (string)json_encode($out, $SHA_FLAGS)) === $row['sha']);
    if (!$okEqual || !$okSha) {
        $applyMismatch++;
        if ($applyMismatch <= 5) {
            fwrite(STDERR, "  apply mismatch tier={$row['tier']} hasCcli="
                . var_export($row['hasCcli'], true) . " presenceCcli=" . var_export($row['presenceCcli'], true)
                . " shape={$row['shapeIndex']} (equal=" . var_export($okEqual, true)
                . ", sha=" . var_export($okSha, true) . ")\n");
        }
    }
}
_eqAssert($applyMismatch === 0,
    '(1) accessApplySong() reproduces all ' . count($applyRows) . ' apply goldens byte-identically (+sha)');

/* ---- (2) MEDIA replay: accessMediaAllowed() must equal the captured verdict. */
$mediaMismatch = 0;
foreach ($mediaRows as $row) {
    $viewer = accessViewerAssemble(
        true, null, 'PWA',
        (string)$row['tier'], (bool)$row['hasCcli'], [], null, (bool)$row['presenceCcli'], false
    );
    $got = accessMediaAllowed((string)$row['kind'], [], $viewer);
    if ($got !== $row['output']) {
        $mediaMismatch++;
        if ($mediaMismatch <= 5) {
            fwrite(STDERR, "  media mismatch kind='{$row['kind']}' tier={$row['tier']} got="
                . var_export($got, true) . " want=" . var_export($row['output'], true) . "\n");
        }
    }
}
_eqAssert($mediaMismatch === 0,
    '(2) accessMediaAllowed() reproduces all ' . count($mediaRows) . ' media goldens');

/* ---- (3) matrixSha integrity: reconstruct the INPUT grid from the fixture
 * rows and recompute the sha the capture tool wrote. Catches a hand-edited
 * input shape (a coordinated input+output+sha edit is a legitimate regenerate). */
$tiers = [];
foreach ($applyRows as $r) { if (!in_array($r['tier'], $tiers, true)) { $tiers[] = $r['tier']; } }
$mediaKinds = [];
foreach ($mediaRows as $r) { if (!in_array($r['kind'], $mediaKinds, true)) { $mediaKinds[] = $r['kind']; } }
$shapesByIndex = [];
foreach ($applyRows as $r) {
    $i = (int)$r['shapeIndex'];
    if (!array_key_exists($i, $shapesByIndex)) { $shapesByIndex[$i] = $r['input']; }
}
ksort($shapesByIndex);
$recomputed = hash('sha256', (string)json_encode([
    'tiers' => $tiers, 'bools' => [false, true], 'mediaKinds' => $mediaKinds,
    'songShapes' => array_values($shapesByIndex),
], $SHA_FLAGS));
_eqAssert($recomputed === $fixture['matrixSha'], '(3) fixture matrixSha integrity (reconstructed input grid)');

/* ---- (4) The DEAD rights-fact branch, tested POSITIVELY so the machinery is
 * proven live though it fires on NO golden (SongData never selects the column). */
$factSong = ['title' => 'F', 'lyricsPublicDomain' => true, 'components' => [['t' => 'v']], 'lyricsRightsLicenceKey' => 'ccli'];
/* free tier: canViewLyrics + canViewCopyrighted true, NOT copyrighted — so the
   ONLY possible deny is the fact. Viewer LACKS 'ccli' ⇒ stripped w/ the 3rd reason. */
$vNoLic = accessViewerAssemble(true, 7, 'PWA', 'free', false, [], null, false, false);
$outNoLic = accessApplySong($factSong, $vNoLic);
_eqAssert(!array_key_exists('components', $outNoLic)
    && ($outNoLic['contentRestricted'] ?? null) === true
    && ($outNoLic['restrictionReason'] ?? '') === 'licence_required_for_lyrics',
    '(4) rights fact + viewer lacks licence ⇒ body stripped w/ licence_required_for_lyrics');
/* Viewer HOLDS 'ccli' ⇒ fact satisfied ⇒ inert (body kept, no restriction). */
$vHasLic = accessViewerAssemble(true, 7, 'PWA', 'free', false, ['ccli'], null, false, false);
$outHasLic = accessApplySong($factSong, $vHasLic);
_eqAssert(array_key_exists('components', $outHasLic) && !array_key_exists('contentRestricted', $outHasLic),
    '(4) rights fact + viewer holds licence ⇒ inert (body kept)');
/* null / '' fact key ⇒ inert (the triple-lock guard skips it). */
foreach ([null, ''] as $emptyFact) {
    $s = ['title' => 'F', 'lyricsPublicDomain' => true, 'components' => [['t' => 'v']], 'lyricsRightsLicenceKey' => $emptyFact];
    $o = accessApplySong($s, $vNoLic);
    _eqAssert(array_key_exists('components', $o) && !array_key_exists('contentRestricted', $o),
        '(4) empty rights fact (' . var_export($emptyFact, true) . ') is inert');
}

/* ---- (5) bypass + off identities. -------------------------------------- */
$richSong = [
    'title' => 'Rich', 'lyricsPublicDomain' => false,
    'components' => [['t' => 'v1']], 'translations' => [['x' => 1]],
    'hasAudio' => true, 'hasSheetMusic' => true,
    'media' => [['kind' => 'audio', 'streamUrl' => '/song-media/1'], ['kind' => 'sheet-music', 'streamUrl' => '/song-media/2']],
];
$vBypass = accessViewerAssemble(true, 5, 'PWA', 'public', false, [], null, false, true); // bypass=true
_eqAssert(accessApplySong($richSong, $vBypass) === $richSong, '(5) bypass: accessApplySong returns the input unchanged');
/* Media IGNORES bypass — a bypass viewer has caps FAIL-CLOSED, so audio is denied.
   The MUTATION SENTINEL for "make accessMediaAllowed honour bypass". */
_eqAssert(accessMediaAllowed('audio', [], $vBypass) === false, '(5) bypass: accessMediaAllowed IGNORES bypass (audio denied on public caps)');

$vOff = accessViewerAssemble(false, 5, 'PWA', 'public', false, [], null, false, false); // gatingEnabled=false
_eqAssert(accessApplySong($richSong, $vOff) === $richSong, '(5) off: accessApplySong returns the input unchanged');
_eqAssert(accessMediaAllowed('audio', [], $vOff) === true, '(5) off: accessMediaAllowed returns true');

/* ---- (6) accessResolve() unit matrix (the unwired P3/P6 primitive). ----- */
$vResOff  = accessViewerAssemble(false, 1, 'PWA', 'free', false, [], null, false, false);
$vResByp  = accessViewerAssemble(true, 1, 'PWA', 'free', false, [], null, false, true);
$vFree    = accessViewerAssemble(true, 1, 'PWA', 'free', false, [], null, false, false);
$vFreeC   = accessViewerAssemble(true, 1, 'PWA', 'free', false, ['ccli'], null, false, false);
$vPublic  = accessViewerAssemble(true, null, 'PWA', 'public', false, [], null, false, false);
$vPresent = accessViewerAssemble(true, null, 'PWA', 'public', false, [], 'tok', true, false);
$vPrem    = accessViewerAssemble(true, 2, 'PWA', 'premium', false, [], null, false, false);

$r = accessResolve($vResOff, [], 'view_lyrics');
_eqAssert($r['allowed'] === true && $r['source'] === 'gating-off', '(6) accessResolve: gating off ⇒ open');
$r = accessResolve($vResByp, [], 'view_copyrighted');
_eqAssert($r['allowed'] === true && $r['source'] === 'bypass', '(6) accessResolve: bypass ⇒ allow');
$r = accessResolve($vFree, [], 'view_copyrighted');
_eqAssert($r['allowed'] === true && $r['source'] === 'tier', '(6) accessResolve: free tier allows view_copyrighted (tier)');
$r = accessResolve($vPublic, [], 'view_copyrighted');
_eqAssert($r['allowed'] === false, '(6) accessResolve: public tier denies view_copyrighted');
$r = accessResolve($vPresent, [], 'play_audio');
_eqAssert($r['allowed'] === true && $r['source'] === 'presence', '(6) accessResolve: presence OR-grants play_audio');
$r = accessResolve($vFree, ['lyricsRightsLicenceKey' => 'ccli'], 'view_copyrighted');
_eqAssert($r['allowed'] === false && $r['source'] === 'fact', '(6) accessResolve: lyric fact denies a tier-allowed action');
$r = accessResolve($vFreeC, ['lyricsRightsLicenceKey' => 'ccli'], 'view_copyrighted');
_eqAssert($r['allowed'] === true && $r['source'] === 'tier', '(6) accessResolve: lyric fact satisfied by held licence');
$r = accessResolve($vPrem, ['musicRightsLicenceKey' => 'mrl'], 'download_pdf');
_eqAssert($r['allowed'] === false && $r['source'] === 'fact', '(6) accessResolve: music fact denies a tier-allowed download');
$r = accessResolve($vFree, [], 'totally_unknown_action');
_eqAssert($r['allowed'] === false, '(6) accessResolve: unknown action ⇒ checkTierAccess deny (no fact gate)');

/* ---- (7) viewer struct key/order guard — assemble emits exactly ACCESS_VIEWER_KEYS. */
_eqAssert(array_keys($vFree) === ACCESS_VIEWER_KEYS,
    '(7) accessViewerAssemble() emits exactly ACCESS_VIEWER_KEYS in order');

/* ----------------------------------------------------------------------- */
echo "\ngating-equivalence: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
