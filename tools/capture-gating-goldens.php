<?php

declare(strict_types=1);

/**
 * iHymns — content-gating golden capture (#1769 P2 Commit B)
 * =========================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Before we replace the content-gating enforcement engine with the new #1769
 * Model-2 pipeline, we take a photograph of EXACTLY what the current engine
 * outputs for a big grid of inputs (every tier × licence state × song shape).
 * This tool writes that photograph to tests/php/fixtures/gating-goldens.json.
 * The new pipeline is then proven byte-identical against it
 * (tests/php/test-gating-equivalence.php). If the new code changes one comma of
 * one output, the equivalence test goes red.
 *
 * DETAILED / WHY DB-FREE
 * ----------------------
 * It drives the pre-refactor cores `_contentGatingApplyLegacyCore()` /
 * `_contentGatingMediaAllowedLegacyCore()` (extracted in Commit A) directly,
 * passing a synthetic ($tier, $hasCcli, $presenceCcli) grid — so it needs NO
 * database. That matters because CI runs no MySQL, so the equivalence test
 * resolves caps through `checkTierAccess()`'s hardcoded matrix fallback; the
 * goldens MUST be captured in that same matrix condition or the two would
 * disagree. Run it with NO reachable DB (no appWeb/.auth/db_credentials.php and
 * no IHYMNS_TEST_DSN) so `checkTierAccess()` falls to the matrix and
 * `gatingRulesApply()` self-gates off (its table probe fails ⇒ no-op) — fully
 * deterministic.
 *
 * The output carries a `matrixSha` over the INPUT grid so a later hand-edit of
 * the fixture (goldens or inputs) is caught by test-gating-equivalence.php.
 *
 * Usage:  php tools/capture-gating-goldens.php        (CLI only)
 * Exit:   0 = wrote the fixture; 1 = refused (not CLI / a DB was reachable).
 *
 * @link appWeb/public_html/includes/content_gating.php  the legacy cores under photograph
 * @see  tests/php/test-gating-equivalence.php            the replay/assert side (Commit E)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Refused: capture-gating-goldens.php is CLI-only.\n");
    exit(1);
}

$repoRoot = dirname(__DIR__);

/* Guard: a reachable DB would let checkTierAccess() read the LIVE registry
   instead of the matrix, producing goldens that CI (no MySQL) can't reproduce.
   Refuse if credentials or a DSN are present. */
if (is_readable($repoRoot . '/appWeb/.auth/db_credentials.php') || getenv('IHYMNS_TEST_DSN')) {
    fwrite(STDERR, "Refused: a DB is configured (db_credentials.php or IHYMNS_TEST_DSN). Goldens must be\n");
    fwrite(STDERR, "captured DB-free so they match CI's matrix path. Move the credentials aside and retry.\n");
    exit(1);
}

require_once $repoRoot . '/appWeb/public_html/includes/content_gating.php';

if (!function_exists('_contentGatingApplyLegacyCore') || !function_exists('_contentGatingMediaAllowedLegacyCore')) {
    fwrite(STDERR, "FATAL: the Commit-A legacy cores are missing — capture cannot run.\n");
    exit(1);
}

/* ---- The input grid (documented in test-gating-equivalence.php §8.2) ---- */
$tiers        = ['public', 'free', 'ccli', 'premium', 'pro', 'made_up_tier'];
$bools        = [false, true];
$mediaKinds   = ['audio', 'midi', 'sheet-music', 'musicxml', 'video-unknown', ''];

/* 10 song shapes exercising every branch of the apply core. Kept verbatim in
   the fixture so the replay side rebuilds the exact input. */
$songShapes = [
    /* 1 — full copyrighted song, all body keys, 4 media kinds incl. an unknown
       kind + both indicator flags. */
    ['title' => 'Full', 'lyricsPublicDomain' => false,
     'components' => [['t' => 'v1']], 'translations' => [['x' => 1]], 'annotations' => [['a' => 1]],
     'vocalParts' => ['SATB'], 'hasAudio' => true, 'hasSheetMusic' => true,
     'media' => [
         ['kind' => 'audio', 'streamUrl' => '/song-media/1'],
         ['kind' => 'midi', 'streamUrl' => '/song-media/2'],
         ['kind' => 'sheet-music', 'streamUrl' => '/song-media/3'],
         ['kind' => 'musicxml', 'streamUrl' => '/song-media/4'],
         ['kind' => 'video-unknown', 'streamUrl' => '/song-media/5'],
     ]],
    /* 2 — public-domain lyrics (not copyrighted). */
    ['title' => 'PD', 'lyricsPublicDomain' => true, 'components' => [['t' => 'v1']],
     'hasAudio' => true, 'media' => [['kind' => 'audio', 'streamUrl' => '/song-media/6']]],
    /* 3 — lyricsPublicDomain absent entirely. */
    ['title' => 'NoPdFlag', 'components' => [['t' => 'v1']], 'media' => [['kind' => 'audio']]],
    /* 4 — lyricsPublicDomain: null (must NOT count as copyrighted — strict === false). */
    ['title' => 'NullPd', 'lyricsPublicDomain' => null, 'components' => [['t' => 'v1']]],
    /* 5 — media with a malformed (non-array) row. */
    ['title' => 'BadMedia', 'lyricsPublicDomain' => false,
     'media' => [['kind' => 'audio'], 'not-an-array', ['kind' => 'sheet-music']]],
    /* 6 — empty media array. */
    ['title' => 'EmptyMedia', 'lyricsPublicDomain' => false, 'components' => [['t' => 'v1']], 'media' => []],
    /* 7 — media key absent. */
    ['title' => 'NoMedia', 'lyricsPublicDomain' => false, 'components' => [['t' => 'v1']]],
    /* 8 — missing both indicator flags. */
    ['title' => 'NoFlags', 'lyricsPublicDomain' => false, 'components' => [['t' => 'v1']],
     'media' => [['kind' => 'audio'], ['kind' => 'sheet-music']]],
    /* 9 — missing all four body keys. */
    ['title' => 'NoBody', 'lyricsPublicDomain' => false, 'hasAudio' => true],
    /* 10 — already carries a restrictionReason (overwrite order pinned). */
    ['title' => 'PreReason', 'lyricsPublicDomain' => false, 'components' => [['t' => 'v1']],
     'contentRestricted' => false, 'restrictionReason' => 'pre-existing'],
];

$SHA_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES; // matches gating-noop-verify.php:133

$applyGoldens = [];
foreach ($tiers as $tier) {
    foreach ($bools as $hasCcli) {
        foreach ($bools as $presenceCcli) {
            foreach ($songShapes as $i => $shape) {
                $out = _contentGatingApplyLegacyCore($shape, $tier, $hasCcli, $presenceCcli);
                $applyGoldens[] = [
                    'tier' => $tier, 'hasCcli' => $hasCcli, 'presenceCcli' => $presenceCcli,
                    'shapeIndex' => $i, 'input' => $shape, 'output' => $out,
                    'sha' => hash('sha256', (string)json_encode($out, $SHA_FLAGS)),
                ];
            }
        }
    }
}

$mediaGoldens = [];
foreach ($tiers as $tier) {
    foreach ($bools as $hasCcli) {
        foreach ($bools as $presenceCcli) {
            foreach ($mediaKinds as $kind) {
                $mediaGoldens[] = [
                    'kind' => $kind, 'tier' => $tier, 'hasCcli' => $hasCcli, 'presenceCcli' => $presenceCcli,
                    'output' => _contentGatingMediaAllowedLegacyCore($kind, $tier, $hasCcli, $presenceCcli),
                ];
            }
        }
    }
}

/* matrixSha covers ONLY the inputs (grid + shapes) — a hand-edited golden
   output changes the per-row sha / output but not this, so the equivalence
   test's own recomputation catches output tampering while this catches an
   input-grid drift. */
$matrixSha = hash('sha256', (string)json_encode(
    ['tiers' => $tiers, 'bools' => $bools, 'mediaKinds' => $mediaKinds, 'songShapes' => $songShapes],
    $SHA_FLAGS
));

$fixture = [
    '_comment'  => 'GENERATED by tools/capture-gating-goldens.php (#1769 P2). Do not hand-edit; regenerate DB-free.',
    'matrixSha' => $matrixSha,
    'shaFlags'  => 'JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES',
    'apply'     => $applyGoldens,
    'media'     => $mediaGoldens,
];

$outPath = $repoRoot . '/tests/php/fixtures/gating-goldens.json';
@mkdir(dirname($outPath), 0775, true);
file_put_contents($outPath, json_encode($fixture, JSON_PRETTY_PRINT | $SHA_FLAGS) . "\n");
echo "Wrote " . count($applyGoldens) . " apply golden(s) + " . count($mediaGoldens)
   . " media golden(s) to tests/php/fixtures/gating-goldens.json (matrixSha " . substr($matrixSha, 0, 12) . "…).\n";
exit(0);
