<?php

declare(strict_types=1);

/**
 * iHymns — Importer rights-field passthrough guard (#1673 / #1896)
 *
 * ELI5: when you bulk-import songs, the copyright, CCLI number, ISWC and
 * public-domain flags the file provides must actually be SAVED — the shared
 * saver used to throw them all away and store blanks. This proves they now flow
 * through, and that a file omitting a field still imports (doesn't crash).
 *
 * THE BUG (#1673 / #1896): `_bulkImport_saveSong()` — the ONE saver every
 * importer funnels through — hardcoded `$copyright=''`, `$ccli=''`, `$iswc=null`,
 * `$verified=0`, `$lyricsPD=0`, `$musicPD=0`, discarding what the parsers had
 * already collected. Copyright + CCLI are the LICENSING metadata: the #317 CCLI
 * report joins on `tblSongs.Ccli` and undercounted imports; #1590 gating reads
 * the PD flags; and post-#1860 the bulk path's `workAutolinkSafe()` had no CCLI/
 * ISWC to link on (#1896). Fixed by reading them from the dict via the pure
 * `_bulkImportRightsFromSong()`, with the old hardcoded values as fallbacks.
 *
 * WHY A PURE-FUNCTION TEST: `_bulkImport_saveSong()` does multi-statement DB work
 * (existence check → dedupe → INSERT → lyric-line write → work auto-link), so it
 * is not cheaply callable without a database. The rights mapping was extracted
 * into a pure helper precisely so it can be tested behaviourally here — this
 * repo's established idiom (see test-account-lifecycle.php's header). PART B then
 * asserts, from source, that the saver actually CALLS that helper (a pure
 * function proven correct but never wired would pass Part A alone).
 *
 *   php tests/php/test-importer-rights-fields.php
 *
 * Exit 0 = passthrough correct + wired, 1 = a field is dropped again.
 *
 * @see https://www.php.net/manual/en/function.empty.php
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/song_importers.php';
require_once __DIR__ . '/lib/php_source_units.php';

$failures = [];
$passed   = 0;
function riOk(string $label, bool $cond): void
{
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

/* =========================================================================
 * PART A — the pure mapping preserves what a parser supplies…
 * ========================================================================= */
$full = _bulkImportRightsFromSong([
    'copyright'          => '  © 2020 Acme Music  ',
    'ccli'               => ' 1234567 ',
    'iswc'               => 'T-034.524.680-1',
    'verified'           => 1,
    'lyricsPublicDomain' => 1,
    'musicPublicDomain'  => 1,
]);
riOk('copyright preserved + trimmed', $full['copyright'] === '© 2020 Acme Music');
riOk('ccli preserved + trimmed',      $full['ccli'] === '1234567');
riOk('iswc preserved',                $full['iswc'] === 'T-034.524.680-1');
riOk('verified 1 preserved',          $full['verified'] === 1);
riOk('lyricsPublicDomain 1 preserved',$full['lyricsPublicDomain'] === 1);
riOk('musicPublicDomain 1 preserved', $full['musicPublicDomain'] === 1);

/* =========================================================================
 * PART A2 — …and falls back safely for a parser that omits keys.
 * The #1673 ⚠️: a parser that doesn't supply a field must still SAVE, never
 * throw on a missing key. An empty dict is the strongest form of that.
 * ========================================================================= */
$empty = _bulkImportRightsFromSong([]);
riOk('missing copyright → ""',           $empty['copyright'] === '');
riOk('missing ccli → ""',                $empty['ccli'] === '');
riOk('missing iswc → NULL (not "")',     $empty['iswc'] === null);
riOk('missing verified → 0 (safe)',      $empty['verified'] === 0);
riOk('missing lyricsPublicDomain → 0',   $empty['lyricsPublicDomain'] === 0);
riOk('missing musicPublicDomain → 0',    $empty['musicPublicDomain'] === 0);

/* ISWC: '' means "no identifier" — must become NULL, because the nullable
   column + the #1860 auto-link read path distinguish NULL from a real value.
   A '' stored here would give workAutolinkSafe() a non-identifier to chew on. */
riOk("empty-string iswc → NULL", _bulkImportRightsFromSong(['iswc' => ''])['iswc'] === null);
riOk("whitespace-only iswc → NULL", _bulkImportRightsFromSong(['iswc' => '   '])['iswc'] === null);
riOk("real iswc kept", _bulkImportRightsFromSong(['iswc' => 'T-123'])['iswc'] === 'T-123');

/* verified / PD: truthy source → 1, everything else → 0 (mutation: an `(int)`
   cast instead of !empty would turn the string '1' into 1 but 'yes' into 0 —
   these pin the boolean contract). */
riOk('verified truthy string "1" → 1', _bulkImportRightsFromSong(['verified' => '1'])['verified'] === 1);
riOk('verified true → 1',              _bulkImportRightsFromSong(['verified' => true])['verified'] === 1);
riOk('verified 0 → 0',                 _bulkImportRightsFromSong(['verified' => 0])['verified'] === 0);
riOk('verified "" → 0',                _bulkImportRightsFromSong(['verified' => ''])['verified'] === 0);
riOk('lyricsPD true → 1',   _bulkImportRightsFromSong(['lyricsPublicDomain' => true])['lyricsPublicDomain'] === 1);
riOk('musicPD false → 0',   _bulkImportRightsFromSong(['musicPublicDomain' => false])['musicPublicDomain'] === 0);

/* The return shape is exactly the six rights keys — a stray extra/typo'd key
   would mis-bind the INSERT. */
riOk('return shape is the six rights keys',
    array_keys(_bulkImportRightsFromSong([])) === [
        'copyright', 'ccli', 'iswc', 'verified', 'lyricsPublicDomain', 'musicPublicDomain',
    ]);

/* =========================================================================
 * PART B — the saver actually WIRES the helper (a proven-but-unwired pure
 * function would pass Part A while the row still stored blanks).
 * ========================================================================= */
$src   = (string)file_get_contents($repoRoot . '/appWeb/public_html/includes/song_importers.php');
$units = phpSourceUnits($src);
$saver = $units['_bulkImport_saveSong']['code'] ?? '';

riOk('_bulkImport_saveSong() is a locatable unit', $saver !== '');
/* Mutation: revert the saver to hardcoded blanks → this call disappears → RED. */
riOk('_bulkImport_saveSong() calls _bulkImportRightsFromSong()',
    strpos($saver, '_bulkImportRightsFromSong(') !== false);
/* The identifiers reach the auto-link hook (#1896): a no-op link on import was
   the symptom. Mutation: drop $ccli/$iswc from the workAutolinkSafe call → RED. */
riOk('the auto-link hook receives $ccli and $iswc',
    (bool)preg_match('/workAutolinkSafe\s*\([^;]*\$ccli[^;]*\$iswc/', $saver));

/* =========================================================================
 * Report
 * ========================================================================= */
if ($failures) {
    fwrite(STDERR, "FAIL: importer rights-field passthrough (#1673/#1896):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  ✗ $f\n"); }
    fwrite(STDERR, "\n{$passed} passed, " . count($failures) . " failed.\n");
    exit(1);
}
echo "PASS: importer rights-field passthrough ({$passed} assertions).\n";
exit(0);
