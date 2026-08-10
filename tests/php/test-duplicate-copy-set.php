<?php

/**
 * iHymns — duplicate_song copy-set guard (#1783)
 *
 * ELI5
 * ----
 * When a song is duplicated, the copy must (a) start life un-numbered and
 * unverified, (b) build its content through the ONE snapshot-apply path (never
 * a second hand-rolled copy loop), (c) carry the per-line enrichment + scripture
 * refs onto the NEW lines, and (d) NOT drag along things that are personal to
 * the original or that belong to a *move* rather than a *copy* (revision trail,
 * redirects, favourites). This asserts the case body still does exactly that.
 *
 * WHY THIS EXISTS
 * ---------------
 * The enrichment/scripture copy (commit 4) is the silent-failure class rule #34
 * is about: drop it in a refactor and the duplicate still opens, still shows its
 * lyrics — the translations/annotations/scripture just quietly vanish, with
 * nothing red. And a stray `INSERT INTO tblSongRevisions/tblSongRedirects/
 * tblUserFavorites` inside the case would leak move/personal state into a copy.
 * Both are invisible at runtime, so guard them at build time.
 *
 * DERIVED + MUTATION-PROVEN (rule #34): the checks read the LIVE case body
 * (brace-matched, comment-stripped) — not a typed list. Each was proven able to
 * fail (delete the Number reset / the enrichment table refs / add a banned
 * INSERT → red; restore → green). Kept narrow enough not to fail on correct
 * code (rule #34's second edge): the banned-table check greps the literal
 * `INSERT INTO tbl…`, which the case's dynamic-table enrichment INSERT
 * (`'INSERT INTO ' . $table`) cannot trip.
 *
 *   php tests/php/test-duplicate-copy-set.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

declare(strict_types=1);

$api2Path = dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/api2.php';
$src = file_get_contents($api2Path);
if ($src === false) {
    fwrite(STDERR, "cannot read api2.php\n");
    exit(1);
}

/* ---- extract the duplicate_song case body (brace-matched) --------------- */
/* Find `case 'duplicate_song':` then the `{` opening the block, then walk to
   its matching close. Naive brace counting is fine here: the case body has no
   unbalanced braces inside string literals (verified against the source). */
$casePos = strpos($src, "case 'duplicate_song':");
if ($casePos === false) {
    fwrite(STDERR, "FAIL: case 'duplicate_song' not found in api2.php\n");
    exit(1);
}
$open = strpos($src, '{', $casePos);
$depth = 0;
$end = $open;
for ($i = $open, $n = strlen($src); $i < $n; $i++) {
    $ch = $src[$i];
    if ($ch === '{') { $depth++; }
    elseif ($ch === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
}
$body = substr($src, $open, $end - $open + 1);

/* Strip PHP comments so a reference in a comment can't satisfy/trip a check. */
$stripped = preg_replace('!/\*.*?\*/!s', '', $body);
$stripped = preg_replace('!//[^\n]*!', '', (string)$stripped);
$stripped = (string)$stripped;

$passed = 0; $failed = 0; $failures = [];
function check(string $label, bool $cond): void {
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  \xE2\x9C\x85 $label\n"; }
    else { $failed++; $failures[] = $label; echo "  \xE2\x9D\x8C $label\n"; }
}

echo "\n#1783 — duplicate_song copy-set\n\n";

/* ---- 1. the copy starts un-numbered / unverified ------------------------ */
check("nulls the snapshot Number (a duplicate is un-numbered until assigned)",
    (bool)preg_match("/\\\$snap\\['song'\\]\\['Number'\\]\\s*=\\s*null/", $stripped));
check("resets Verified to 0 (a fresh copy is not carried over as verified)",
    (bool)preg_match("/\\\$snap\\['song'\\]\\['Verified'\\]\\s*=\\s*0/", $stripped));
check("clears the recording-scope Isrc (D2 default: work ids kept, recording ids dropped)",
    (bool)preg_match("/\\\$snap\\['song'\\]\\['Isrc'\\]\\s*=\\s*null/", $stripped));

/* ---- 2. content is built through the ONE snapshot-apply path ------------ */
$applyCount = preg_match_all('/ed2_applySongSnapshot\s*\(/', $stripped);
check("delegates to ed2_applySongSnapshot (the ONE apply path — no forked copy loop)",
    $applyCount >= 1);
check("calls ed2_applySongSnapshot exactly once (a second call would be a rebuild, not a copy)",
    $applyCount === 1);

/* ---- 3. per-line enrichment + scripture refs are re-anchored (commit 4) - */
/* The enrichment INSERTs use a dynamic table name, so assert the case
   REFERENCES each table (it appears in the SELECT SQL + the $copyRemapped
   call). Dropping any one silently loses that enrichment on every duplicate. */
check("copies per-line translations (tblLyricLineTranslations, #1088)",
    strpos($stripped, 'tblLyricLineTranslations') !== false);
check("copies per-line annotations (tblLyricLineAnnotations, #1088)",
    strpos($stripped, 'tblLyricLineAnnotations') !== false);
check("copies scripture refs (tblSongScriptureRefs, #1350)",
    strpos($stripped, 'tblSongScriptureRefs') !== false);
/* The re-anchor is the whole point: a positional src→new line map, not a naive
   INSERT…SELECT that would point copies at the SOURCE's lines (rule #21). */
check("builds a positional source→new line map (lyricLinesFetchPrimary) rather than INSERT…SELECT",
    strpos($stripped, 'lyricLinesFetchPrimary') !== false
    && strpos($stripped, 'lineMap') !== false);

/* ---- 4. no move/personal state leaks into a copy ------------------------ */
/* Raw literal INSERTs only — the case's own enrichment INSERT is dynamic
   (`'INSERT INTO ' . $table`) so it cannot match these. */
foreach (['tblSongRevisions', 'tblSongRedirects', 'tblUserFavorites'] as $banned) {
    check("does NOT raw-INSERT $banned (a copy is not a move/personal-state carry)",
        !preg_match('/INSERT\s+INTO\s+' . preg_quote($banned, '/') . '\b/i', $stripped));
}
/* The revision trail is started fresh via the shared helper, forced 'duplicate'
   — never by copying the source's tblSongRevisions rows. */
check("starts a fresh revision via ed2_touchRevision(..., 'duplicate', ...)",
    (bool)preg_match("/ed2_touchRevision\([^;]*['\"]duplicate['\"]/", $stripped));

echo "\n$passed passed, $failed failed\n";
if ($failed > 0) {
    fwrite(STDERR, "\nFailures:\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - $f\n"); }
    fwrite(STDERR, "\nA dropped enrichment copy or a leaked move/personal INSERT is invisible at\n");
    fwrite(STDERR, "runtime — the duplicate still opens. Guard the copy-set here (rule #34).\n");
    exit(1);
}
echo "\nAll duplicate_song copy-set assertions passed.\n";
