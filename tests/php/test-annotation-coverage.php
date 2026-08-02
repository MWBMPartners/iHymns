<?php

declare(strict_types=1);

/**
 * test-annotation-coverage.php — the annotation baseline is never allowed to regress (#1158)
 * ==========================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Every source file in the core directories must open with a doc-block that
 * says what it is and why it exists. This check walks the tree and fails the
 * build if a file ever ships without one — so the "~100% of files carry a head
 * doc-block" state the 2026-06-21 audit measured can't quietly erode as new
 * files land. It ALSO prints a progress line for the ongoing #1158 backfill,
 * but that line never fails the build (see WHY DENSITY IS ADVISORY below).
 *
 * WHY THIS EXISTS (#1158 / absorbed #1340)
 * -----------------------------------------
 * #1158 is the governed, multi-session program to backfill the two-register
 * (ELI5 + detailed "why") annotation standard across legacy code. Its #1340
 * duplicate proposed, as item 3, "a non-blocking check flagging files that
 * fall below the includes/ baseline, so new code stays annotated without
 * blocking a build (rule #34: derive its file list from the tree, and prove it
 * can go red before trusting its green)." This is that mechanism — the point
 * of the program is that annotation stays healthy by a MECHANISM, not by a
 * comment in a doc asking people to remember (CLAUDE.md rule #35).
 *
 * WHAT IS HARD-ENFORCED, AND WHY IT'S SAFE (rule #34)
 * -----------------------------------------------------
 * The ONE hard assertion is: every watched file has a block doc-block (`/* … *​/`
 * or `/** … *​/`) within its opening lines. That baseline is measured at 100%
 * across the watched set TODAY (208 files: includes/ 118, js/ 77,
 * manage/includes/ 13), so this guard is green on correct code and fails ONLY
 * on a genuine regression — a new file that shipped with no head doc-block.
 * The watched file list is DERIVED from the tree by globbing those directories,
 * never a hand-typed list, so a file added anywhere under them is covered
 * automatically (rule #34's "derive the list from the tree"). A vacuity
 * assertion (>= 180 files scanned) makes a broken glob fail loudly rather than
 * pass by scanning nothing.
 *
 * WHY DENSITY IS ADVISORY, NOT A FAILURE
 * ----------------------------------------
 * The obvious next step — "fail files below an inline-comment-density
 * threshold" — was deliberately NOT taken, because raw comment density is a
 * proven-misleading proxy. `includes/pages/home.php` scores ~5% comment lines
 * yet is thoroughly annotated where it matters (its non-obvious PHP logic cites
 * rules #6/#30/#448/#680/#1223 inline); `includes/pages/search.php` scores ~4%
 * because it is a pure HTML template shell with a good head doc-block and a
 * single line of logic. A density gate would flag both as debt and, being
 * wrong on correct code, would get weakened or deleted — exactly rule #34's
 * other failure mode. So the density/labelled-register figure below is printed
 * as a PROGRESS SIGNAL for the #1158 backlog and never contributes to the exit
 * status. The backfill itself stays a human-reviewed, file-by-file program.
 *
 *   php tests/php/test-annotation-coverage.php
 *
 * Exit status 0 = baseline intact, 1 = a watched file lost its head doc-block
 * (or the glob broke).
 *
 * @see .github (the standing-tasks annotation convention this enforces)
 * @see appWeb/public_html/includes/importers/MusicXmlImporter.php  the worked exemplar (#1158)
 */

$root = dirname(__DIR__, 2);
$pub  = $root . '/appWeb/public_html';

$passed = 0;
$failed = 0;
function acOk(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  {$label}\n"; return; }
    $failed++;
    echo "  FAIL  {$label}\n";
    if ($detail !== '') { echo "        {$detail}\n"; }
}

echo "\nAnnotation baseline — every watched source file opens with a doc-block (#1158)\n\n";

/* The watched set, DERIVED from the tree (rule #34): every PHP file under
   includes/ (which already contains pages/ + partials/ recursively) and
   manage/includes/, and every JS file under js/ (modules/ + utils/). These are
   the hand-written core surfaces; vendored / generated trees are not walked. */
$watched = [
    ['dir' => $pub . '/includes',        'ext' => 'php'],
    ['dir' => $pub . '/manage/includes', 'ext' => 'php'],
    ['dir' => $pub . '/js',              'ext' => 'js'],
];

/** Recursively collect files with the given extension under $dir. */
function acCollect(string $dir, string $ext): array
{
    if (!is_dir($dir)) { return []; }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === $ext) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

$files = [];
foreach ($watched as $w) {
    foreach (acCollect($w['dir'], $w['ext']) as $p) { $files[$p] = true; }
}
$files = array_keys($files);

/* Vacuity — a broken glob (wrong root, typo) must fail loudly, never pass by
   scanning nothing. 180 is comfortably below today's 208 but well above any
   plausible partial-collection accident. */
acOk('scanned a plausible number of watched source files (>= 180)',
    count($files) >= 180,
    'only ' . count($files) . ' files collected — the glob is probably broken');

/* HARD: every watched file opens with a block doc-block. Read only the head of
   each file (doc-blocks live at the top; this keeps the scan cheap). Matching a
   block comment `/*` — not a line `//` — because the standard is a doc-BLOCK,
   and every watched file satisfies that today. */
$noDocBlock = [];
foreach ($files as $p) {
    $head = '';
    $fh = fopen($p, 'rb');
    if ($fh !== false) {
        for ($i = 0; $i < 30 && !feof($fh); $i++) { $head .= (string)fgets($fh); }
        fclose($fh);
    }
    if (strpos($head, '/*') === false) {
        $noDocBlock[] = substr($p, strlen($root) + 1);
    }
}
acOk('every watched source file opens with a block doc-block within its first 30 lines',
    $noDocBlock === [],
    $noDocBlock === [] ? '' :
        count($noDocBlock) . " file(s) missing a head doc-block:\n        - "
        . implode("\n        - ", $noDocBlock));

/* ADVISORY (never fails) — how far the #1158 two-register backfill has spread:
   the share of watched files that already carry at least one explicitly
   labelled ELI5 / "Why:" register. This is a PROGRESS signal for the backlog,
   not a gate — raw annotation density is a misleading proxy (see the file
   doc-block), so nothing here touches the exit status. */
$labelled = 0;
foreach ($files as $p) {
    $src = (string)file_get_contents($p);
    if (preg_match('/\bELI5\b|\bWhy:/', $src) === 1) { $labelled++; }
}
$pct = count($files) > 0 ? (int)round($labelled * 100 / count($files)) : 0;
echo "\n  ADVISORY (#1158 backlog progress — not a gate): {$labelled}/" . count($files)
    . " watched files ({$pct}%) carry a labelled ELI5/Why register.\n";
echo "  The backfill of the remainder is a governed, human-reviewed, file-by-file\n";
echo "  program; the largest logic files (SongData.php, api.php by case group) are\n";
echo "  its bulk and are intentionally multi-session. Raw comment density is NOT\n";
echo "  used as debt here — template shells score low while being fully annotated.\n";

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    fwrite(STDERR, "\nThe annotation baseline regressed: a core source file shipped without a head\n");
    fwrite(STDERR, "doc-block. Add one (see MusicXmlImporter.php for the standard) — this is the\n");
    fwrite(STDERR, "one thing #1158 keeps at 100%, precisely so it can never silently erode.\n");
    exit(1);
}
echo "\nAnnotation baseline intact.\n";
exit(0);
