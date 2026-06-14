<?php
/**
 * iHymns — tblSongComponents JSON-payload column guard (#1235 P4 / C5)
 *
 * The #1235 P4 cutover makes `tblLyricLines` the source of truth for lyric lines and
 * RETIRES the `tblSongComponents` JSON payload columns (`LinesJson`, `NotesJson`,
 * `LanguagesJson`, `ChordsJson`) in P4d/C6. Until the drop they are SHADOW-written from
 * the authoritative lines; after the drop they are gone. Because the DB layer runs
 * mysqli under MYSQLI_REPORT_STRICT, ANY surviving statement that names a dropped column
 * throws — bricking every save / read it sits on.
 *
 * This guard fails CI when a RAW reference to one of the doomed columns appears in
 * application code OUTSIDE a gated / fallback site. The intent is that the only paths
 * that may still touch the columns are (a) the column-existence-gated shadow write
 * (lyric_lines_sync.php), and (b) the explicitly-marked `lines-json-fallback`
 * un-migrated-install branches — both of which degrade to a no-op once the column is
 * gone. A new ungated write/read is the regression this catches.
 *
 * It guards the UNAMBIGUOUS TRIO — LinesJson / NotesJson / LanguagesJson — which exist
 * ONLY on tblSongComponents (tblLyricLines carries ChordsJson / Note / LanguageCode /
 * LineText, NOT these; tblUsers has the distinct PreferredLanguagesJson). Every
 * component WRITE site also names LinesJson, so guarding the trio covers the ChordsJson
 * sites too without the tblLyricLines.ChordsJson false-positive.
 *
 * A reference is ALLOWED when, within a small window around it, the code shows it is
 * gated or a marked fallback: `lines-json-fallback`, `@column-gated-fallback`, or one of
 * the gate probes (lyricLinesShadowColumnsPresent / lyricLinesMirrorPresent /
 * lyricLinesSyncReady / lyricLinesComponentsLangReady / INFORMATION_SCHEMA / columnExists).
 * Migrations (appWeb/.sql/) legitimately name the columns and are not scanned.
 *
 * Pure source-tree scan — no DB — so it slots into the CI lint step.
 *
 *   php tests/php/test-component-json-guard.php
 *
 * Exit status 0 = clean, 1 = at least one ungated reference.
 */

declare(strict_types=1);

$root    = dirname(__DIR__, 2) . '/appWeb/public_html';
$tokens  = ['LinesJson', 'NotesJson', 'LanguagesJson'];   // the unambiguous trio
/* Markers / gate probes that prove a reference is fallback or column-existence-gated. */
$allow   = [
    'lines-json-fallback', '@column-gated-fallback',
    'lyricLinesShadowColumnsPresent', 'lyricLinesMirrorPresent', 'lyricLinesSyncReady',
    'lyricLinesComponentsLangReady', 'INFORMATION_SCHEMA', 'columnExists',
    '_hasLyricLinesMirror', '_hasComponentLanguageColumn', '_hasComponentChordsColumn',
];
const GUARD_WINDOW = 16;   // lines either side to look for an allow marker / gate probe

/* The ONE file allowed to orchestrate the doomed columns wholesale: the cutover write
   ENGINE. lyric_lines_sync.php hosts both the column-existence-gated shadow write
   (lyricLinesShadowColumnsPresent) and the legacy/backfill projector source
   (lyricLinesBuildDesired, reached only by the one-time backfill migration pre-drop; its
   retirement-era no-op guard is a P4d/C6 deliverable). Every other file must gate or mark
   each reference — this allowlist is deliberately a single, high-scrutiny entry. */
$allowFiles = ['appWeb/public_html/includes/lyric_lines_sync.php'];

if (!is_dir($root)) {
    fwrite(STDERR, "FATAL: $root not found\n");
    exit(1);
}

/* Recursively collect *.php under public_html. */
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $files[] = $f->getPathname(); }
}
sort($files);

$violations = [];
$scanned    = 0;

$rootPrefix = dirname(__DIR__, 2) . '/';
foreach ($files as $file) {
    $relFile = substr($file, strlen($rootPrefix));
    if (in_array($relFile, $allowFiles, true)) { continue; }   // cutover write engine

    $src   = file_get_contents($file);
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($src === false || $lines === false) { continue; }
    $scanned++;
    $n = count($lines);

    /* Tokenise so the column names are seen ONLY in real string literals — doc-blocks
       and inline comments that name the columns are skipped (they are NOT executable
       references). PHP string SQL / array-keys are T_CONSTANT_ENCAPSED_STRING; the
       interpolated pieces of a double-quoted SQL string are T_ENCAPSED_AND_WHITESPACE. */
    $toks = @token_get_all($src);
    foreach ($toks as $tok) {
        if (!is_array($tok)) { continue; }
        [$id, $text, $lineNo] = [$tok[0], $tok[1], $tok[2]];
        if ($id !== T_CONSTANT_ENCAPSED_STRING && $id !== T_ENCAPSED_AND_WHITESPACE) { continue; }
        if (strpos($text, 'PreferredLanguagesJson') !== false) { continue; }   // tblUsers column

        $hit = null;
        foreach ($tokens as $t) {
            if (preg_match('/\b' . $t . '\b/', $text)) { $hit = $t; break; }
        }
        if ($hit === null) { continue; }

        /* Window scan (around the token's line) for an allow marker / gate probe. */
        $i  = $lineNo - 1;
        $lo = max(0, $i - GUARD_WINDOW);
        $hi = min($n - 1, $i + GUARD_WINDOW);
        $window = implode("\n", array_slice($lines, $lo, $hi - $lo + 1));
        $allowed = false;
        foreach ($allow as $a) {
            if (strpos($window, $a) !== false) { $allowed = true; break; }
        }
        if (!$allowed) {
            $rel = substr($file, strlen(dirname(__DIR__, 2)) + 1);
            $violations[] = sprintf('%s:%d  %s  %s', $rel, $lineNo, $hit, trim($lines[$i] ?? $text));
        }
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: ungated tblSongComponents JSON-payload column reference(s) (#1235 P4/C5):\n\n");
    foreach ($violations as $v) { fwrite(STDERR, "  $v\n"); }
    fwrite(STDERR, "\nEach raw LinesJson / NotesJson / LanguagesJson reference on tblSongComponents must be\n");
    fwrite(STDERR, "either column-existence-gated (lyricLinesShadowColumnsPresent / *Ready / INFORMATION_SCHEMA)\n");
    fwrite(STDERR, "or in a branch marked `lines-json-fallback` — these columns are dropped in P4d/C6 and a\n");
    fwrite(STDERR, "surviving raw reference throws under MYSQLI_REPORT_STRICT. See lyrics-normalisation-strategy.md §11.\n");
    exit(1);
}

echo "PASS: no ungated tblSongComponents JSON-payload column references ({$scanned} files scanned).\n";
exit(0);
