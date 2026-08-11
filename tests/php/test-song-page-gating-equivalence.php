<?php

declare(strict_types=1);

/**
 * iHymns — song-page gating pipeline equivalence guard (#1769 P3 Commit C)
 * ======================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Commit C rewrote the song page's gating onto the #1769 viewer struct
 * (songPageGatingDecide). This proves the swap is a BYTE-IDENTICAL no-op: it
 * replays the frozen goldens (captured from the pre-P3 inline maths in Commit B)
 * through the NEW viewer-driven function and asserts every decision tuple matches
 * to the comma. If the collapse changed one branch, this goes red.
 *
 * DB-FREE (same reasoning as test-gating-equivalence.php): the goldens were
 * captured with checkTierAccess() in its hardcoded-matrix fallback, which is how
 * accessViewerAssemble() resolves caps when no DB is reachable — CI runs no MySQL
 * for getDbMysqli(). SKIPS if a DB IS reachable (a dev box with credentials),
 * since the live tiers could diverge from the matrix; move the credentials aside
 * to run the replay locally.
 *
 * @link .claude/gating-p3-design.md  §4 the verification strategy
 * @see  tools/capture-song-page-gating-goldens.php  the capture side (Commit B)
 * @see  appWeb/public_html/includes/song_page_gating.php  songPageGatingDecide() under proof
 */

$root     = dirname(__DIR__, 2) . '/appWeb/public_html';
$failures = 0;
$passed   = 0;
function _spgeAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

$_SERVER['SCRIPT_FILENAME'] = 'test-runner';
require_once $root . '/includes/access_context.php';   // accessViewerAssemble + checkTierAccess
require_once $root . '/includes/song_page_gating.php'; // songPageGatingDecide

$SHA_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

/* ---- DB-free precondition ---------------------------------------------- */
$dbReachable = false;
try { getDbMysqli(); $dbReachable = true; } catch (\Throwable $_e) { $dbReachable = false; }
if ($dbReachable) {
    echo "SKIP: a DB is reachable via getDbMysqli() — the goldens are matrix-captured (DB-free).\n";
    echo "      Move appWeb/.auth/db_credentials.php aside to run the equivalence replay.\n";
    exit(0);
}

/* ---- Matrix sentinels — prove checkTierAccess is in the fallback-matrix mode. */
_spgeAssert(empty(checkTierAccess('public', 'view_copyrighted', false)['allowed']),
    'sentinel: public tier DENIED view_copyrighted (matrix mode)');
_spgeAssert(!empty(checkTierAccess('free', 'view_copyrighted', false)['allowed']),
    'sentinel: free tier ALLOWED view_copyrighted (matrix mode)');

/* ---- Load the fixture -------------------------------------------------- */
$fixturePath = __DIR__ . '/fixtures/song-page-gating-goldens.json';
$fixture = json_decode((string)@file_get_contents($fixturePath), true);
if (!is_array($fixture) || !isset($fixture['goldens'], $fixture['matrixSha'])) {
    fwrite(STDERR, "FATAL: could not load/parse $fixturePath\n");
    exit(1);
}
$goldens = $fixture['goldens'];

/* ---- Replay: build the viewer, call songPageGatingDecide, assert ≡ golden.
 * The viewer's presenceCcli === ($presenceNumber !== null) by construction — the
 * exact invariant song.php upholds (same serviceMode lookup feeds both). --- */
$mismatch = 0;
foreach ($goldens as $g) {
    $presenceCcli = ($g['presenceNumber'] !== null);
    $viewer = accessViewerAssemble(
        true, null, 'PWA',
        (string)$g['tier'], (bool)$g['hasCcli'], [], null, $presenceCcli, false
    );
    $out = songPageGatingDecide(
        $viewer,
        (bool)$g['entityAllowed'], 'Blocked by rule', $g['presenceNumber'],
        (bool)$g['lyricsPD'], (bool)($g['lyricsPD'] && $g['musicPD']),
        (bool)$g['hasAudio'], (bool)$g['hasSheet'], $g['media']
    );
    $okEqual = ($out === $g['output']);
    $okSha   = (hash('sha256', (string)json_encode($out, $SHA_FLAGS)) === $g['sha']);
    if (!$okEqual || !$okSha) {
        $mismatch++;
        if ($mismatch <= 5) {
            fwrite(STDERR, "  mismatch tier={$g['tier']} hasCcli=" . var_export($g['hasCcli'], true)
                . " entity=" . var_export($g['entityAllowed'], true) . " presence=" . var_export($g['presenceNumber'], true)
                . " lpd={$g['lyricsPD']} mpd={$g['musicPD']} shape={$g['mediaShapeIndex']}\n"
                . "    got =" . json_encode($out, $SHA_FLAGS) . "\n"
                . "    want=" . json_encode($g['output'], $SHA_FLAGS) . "\n");
        }
    }
}
_spgeAssert($mismatch === 0,
    '(1) songPageGatingDecide() reproduces all ' . count($goldens) . ' song-page goldens byte-identically (+sha)');

/* ---- matrixSha integrity: reconstruct the input grid from the goldens. --- */
$tiers = [];
foreach ($goldens as $g) { if (!in_array($g['tier'], $tiers, true)) { $tiers[] = $g['tier']; } }
$presences = [];
foreach ($goldens as $g) { if (!in_array($g['presenceNumber'], $presences, true)) { $presences[] = $g['presenceNumber']; } }
$shapesByIndex = [];
foreach ($goldens as $g) {
    $i = (int)$g['mediaShapeIndex'];
    if (!array_key_exists($i, $shapesByIndex)) { $shapesByIndex[$i] = $g['media']; }
}
ksort($shapesByIndex);
$recomputed = hash('sha256', (string)json_encode([
    'tiers' => $tiers, 'bools' => [false, true], 'presences' => $presences,
    'mediaShapes' => array_values($shapesByIndex),
], $SHA_FLAGS));
_spgeAssert($recomputed === $fixture['matrixSha'], '(2) fixture matrixSha integrity (reconstructed input grid)');

/* ---- (3) presence quirk pinned: sheet/midi keep the presence-OR (web/API
 * divergence). A present congregant on the `public` tier keeps sheet-music +
 * midi that the JSON API pipeline (accessMediaAllowed) would strip. --- */
$vPresent = accessViewerAssemble(true, null, 'PWA', 'public', false, [], null, true, false);
$qOut = songPageGatingDecide($vPresent, true, '', '741100', false, false, true, true,
    [['kind' => 'sheet-music'], ['kind' => 'midi'], ['kind' => 'audio']]);
_spgeAssert(count($qOut['media']) === 3 && $qOut['hasSheet'] === true,
    '(3) presence-OR quirk preserved: a present congregant keeps sheet+midi on public tier');

echo "\nsong-page-gating-equivalence: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
