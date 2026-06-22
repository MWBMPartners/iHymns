<?php

declare(strict_types=1);

/**
 * iHymns — Song PublicId helper test (#1343-B)
 *
 * Pure-function coverage (no DB): the generator's format + the
 * SongId-vs-PublicId disambiguator. mintUnique/resolveToSongId/columnReady need
 * a live DB and are exercised in the manual alpha verify.
 *
 *   php tests/php/test-song-public-id.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_public_id.php';

$fail = 0;
function ok(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $fail++; }
}

/* Generator: length 10, only the ambiguity-free uppercase alphabet. */
$alphabet = SONG_PUBLIC_ID_ALPHABET;
$allValid = true; $sawLen = true;
for ($i = 0; $i < 500; $i++) {
    $code = songPublicId_generate();
    if (strlen($code) !== 10) { $sawLen = false; break; }
    if (strspn($code, $alphabet) !== strlen($code)) { $allValid = false; break; }
}
ok('generate() is always length 10', $sawLen);
ok('generate() uses only the base32 alphabet', $allValid);
ok('alphabet excludes ambiguous glyphs I L O U 0 1',
    strpbrk($alphabet, 'ILOU01') === false);
ok('generate() honours a custom length', strlen(songPublicId_generate(6)) === 6);

/* Disambiguator: PublicId shape vs SongId / aliases. */
ok('a 10-char base32 looks like a PublicId', songPublicId_looksLikePublicId('B7K2QF9MNP'));
ok('a SongId (hyphen) is NOT a PublicId', !songPublicId_looksLikePublicId('MP-0001'));
ok('a padding alias (hyphen) is NOT a PublicId', !songPublicId_looksLikePublicId('MP-1'));
ok('a too-short code is NOT a PublicId', !songPublicId_looksLikePublicId('AB'));
ok('lowercase is NOT a PublicId (PublicIds are uppercase)', !songPublicId_looksLikePublicId('b7k2qf9mnp'));
ok('empty is NOT a PublicId', !songPublicId_looksLikePublicId(''));
ok('a synthetic song-<ts> id (hyphen) is NOT a PublicId', !songPublicId_looksLikePublicId('SONG-1778700096424'));

if ($fail === 0) { echo "\nAll song-public-id assertions passed.\n"; exit(0); }
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
