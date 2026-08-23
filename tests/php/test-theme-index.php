<?php

declare(strict_types=1);

/**
 * iHymns — Browse-by-Theme count-core guard (#1148)
 * ============================================================================
 *
 * The count-drift bug this feature closes (§1e): `popular_tags` counted EVERY
 * tblSongTagMap row while `tag.php` listed only visible-and-servable songs, so
 * a home chip said "42" and the page it opened showed fewer. The fix is ONE
 * count core (`includes/theme_index.php`) that every PUBLIC surface calls. This
 * guard keeps it the ONE core — a second public COUNT over tblSongTagMap would
 * re-open the drift.
 *
 * ASSERTS (tree-derived + mutation-proven, rule #34):
 *   1. Functional: the core defines themeIndexReady/…HierarchyReady/…Counts;
 *      themeIndexCounts applies BOTH visibility predicates (that is what aligns
 *      its count with tag.php) and binds its LIMIT (never interpolates it).
 *   2. Sole-core: NO public surface (api.php, includes/**, sitemap.xml.php —
 *      comment-stripped) contains a theme-count query (a COUNT of a tag-map
 *      TagId, or a COUNT … FROM tblSongTagMap) outside theme_index.php itself.
 *      `manage/` is exempt BY DIRECTORY — its LEFT JOIN admin counts are a
 *      deliberately different question (zero-use tags shown to curators), so
 *      they are simply not in the scanned set.
 *   3. popular_tags sources from themeIndexCounts() and carries no
 *      JOIN tblSongTagMap literal of its own.
 *
 *   php tests/php/test-theme-index.php
 *
 * Exit 0 = all pass, 1 = at least one failure.
 *
 * @see .claude/browse-by-theme-1148-plan.md §6.2
 */

$docroot = dirname(__DIR__, 2) . '/appWeb/public_html';

$failures = 0;
$passed   = 0;
function ti(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Blank out comments, preserving offsets (so a doc-block mentioning a banned
 *  pattern can't false-positive — the #1676-family lesson). */
function tiStrip(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/* The two theme-count fingerprints (alias-agnostic): a COUNT of a (possibly
   aliased) TagId column, or a COUNT aggregate reading FROM tblSongTagMap. */
const TI_PAT_TAGID = '/COUNT\(\s*(?:\w+\.)?TagId\s*\)/i';
const TI_PAT_FROM  = '/COUNT\([^)]*\)[^;]{0,160}\bFROM\s+tblSongTagMap\b/is';

$coreRel = 'includes/theme_index.php';
$corePath = $docroot . '/' . $coreRel;

/* ---- 1. Functional ----------------------------------------------------- */
$coreRaw = is_readable($corePath) ? (string)file_get_contents($corePath) : '';
ti($coreRaw !== '', '1.1 includes/theme_index.php exists');
$core = $coreRaw !== '' ? tiStrip($coreRaw) : '';

foreach (['themeIndexReady', 'themeIndexHierarchyReady', 'themeIndexCounts'] as $fn) {
    ti((bool)preg_match('/function\s+' . $fn . '\s*\(/', $core), "1.2 $fn() is defined");
}
/* The count query applies BOTH visibility predicates — the alignment fix. */
ti(strpos($core, 'songVisibleSql(') !== false && strpos($core, 'songServableSql(') !== false,
    '1.3 themeIndexCounts applies songVisibleSql AND songServableSql (aligns the count with tag.php)');
/* The LIMIT is bound, never interpolated. */
ti(strpos($core, "bind_param('i'") !== false && strpos($core, 'LIMIT ?') !== false,
    '1.4 the LIMIT is bound (bind_param + `LIMIT ?`)');
ti(!preg_match('/LIMIT\s+["\'\.]*\s*\$/', $core) && strpos($core, 'LIMIT {$') === false,
    '1.5 the LIMIT is never interpolated from a variable');

/* ---- 2. Sole-core (tree-derived) --------------------------------------- */
$scan = [$docroot . '/api.php', $docroot . '/sitemap.xml.php'];
if (is_dir($docroot . '/includes')) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docroot . '/includes', FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $scan[] = $f->getPathname(); }
    }
}
sort($scan);
$offenders = [];
foreach ($scan as $pf) {
    if (!is_file($pf)) { continue; }
    $rel = str_replace($docroot . '/', '', $pf);
    if ($rel === $coreRel) { continue; }   /* the ONE allowed home */
    $src = tiStrip((string)file_get_contents($pf));
    if (preg_match(TI_PAT_TAGID, $src) || preg_match(TI_PAT_FROM, $src)) {
        $offenders[] = $rel;
    }
}
ti($offenders === [],
    '2.1 no public surface holds a theme-count query outside theme_index.php (offenders: '
        . implode(', ', $offenders) . ')');

/* Sanity: the fingerprint DOES match inside the core (the pattern works). */
ti((bool)preg_match(TI_PAT_TAGID, $core),
    '2.2 the theme-count fingerprint matches inside the core (the detector works)');

/* ---- 3. popular_tags is refactored onto the core ----------------------- */
$apiSrc = tiStrip((string)file_get_contents($docroot . '/api.php'));
$needle = "\n        case 'popular_tags':";
$start  = strpos($apiSrc, $needle);
if ($start === false) {
    ti(false, "3.1 case 'popular_tags' found");
} else {
    $start += strlen($needle);
    $next = preg_match('/\n        case \'/', $apiSrc, $m, PREG_OFFSET_CAPTURE, $start) ? $m[0][1] : strlen($apiSrc);
    $case = substr($apiSrc, $start, $next - $start);
    ti(strpos($case, 'themeIndexCounts(') !== false,
        '3.1 popular_tags sources its rows from themeIndexCounts()');
    ti(strpos($case, 'tblSongTagMap') === false,
        '3.2 popular_tags no longer carries its own tblSongTagMap query');
}

/* ------------------------------------------------------------------------ */
echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
