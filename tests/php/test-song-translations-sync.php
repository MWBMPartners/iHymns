<?php

declare(strict_types=1);

/**
 * iHymns — a song save never loses a translation link stored under a
 * retired language code (#2137 review, #2131)
 *
 * ELI5: a song's translation links come back from the editor on every save.
 * One stored as `iw` (the old code for Hebrew) used to be deleted and added
 * again as `he`, losing who translated it and whether it was checked; and of
 * two links stored as `mo` and `ro` (both Romanian now), one was quietly
 * deleted. This test runs the real comparison, songTranslationsPlanSync(),
 * with the real shared language rule, on exactly that set.
 *
 * WHAT IT CHECKS
 * --------------
 *  - the reviewer's set — stored `pt`, `iw`, `mo`, `ro`, sent back unchanged
 *    by the editor: `pt` is untouched; `iw` is UPDATED in place to `he` (same
 *    row, so translator / verified / creation date survive); `mo` and `ro` are
 *    both left exactly as they are, with a warning naming both and pointing
 *    at the remap on /manage/languages; nothing is deleted or inserted;
 *  - a link the curator really removed is still deleted;
 *  - a link skipped for the server's reason (the #2131 migration not run yet)
 *    or because its value cannot be read is NOT deleted;
 *  - a new link is inserted, and a re-pointed link is updated in place.
 *
 * Mutation-proven: keying stored rows by their raw language again turned the
 * `iw` check red (delete + insert); dropping the two-rows-one-language branch
 * turned the `mo`/`ro` checks red; ignoring $keep turned the skipped-link
 * checks red.
 *
 *   php tests/php/test-song-translations-sync.php
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/media_language.php';
require_once $repoRoot . '/appWeb/public_html/includes/song_translations_sync.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};
echo "tests/php/test-song-translations-sync.php — translation links stored under retired codes survive a save\n";
$check('the shared language rules loaded', mediaLanguageReady());

/** What save_song_core.php does to the editor's list before the comparison. */
$desiredFrom = static function (array $sent): array {
    $d = [];
    foreach ($sent as [$songId, $lang]) {
        $tidy = mediaLanguageTagForStorage($lang);
        if (is_string($tidy)) {
            $d[mb_strtolower($tidy)] = ['songId' => $songId, 'language' => $tidy];
        }
    }
    return $d;
};
$tidy = 'mediaLanguageTagForStorage';

/* ---- the reviewer's set ---- */
$stored = [
    ['id' => 1, 'songId' => 'SDAH-PT', 'language' => 'pt'],
    ['id' => 2, 'songId' => 'SDAH-HE', 'language' => 'iw'],
    ['id' => 3, 'songId' => 'SDAH-MO', 'language' => 'mo'],
    ['id' => 4, 'songId' => 'SDAH-RO', 'language' => 'ro'],
];
/* The editor loaded these exactly as stored and sends them back unchanged. */
$sent = array_map(static fn(array $r): array => [$r['songId'], $r['language']], $stored);
$plan = songTranslationsPlanSync($desiredFrom($sent), $stored, [], $tidy);
$check('nothing is deleted', $plan['delete'] === [], json_encode($plan['delete']));
$check('nothing is inserted', $plan['insert'] === [], json_encode($plan['insert']));
$check('`iw` is updated IN PLACE to `he` (row 2 — its translator, verified flag and date survive)',
    $plan['update'] === [['id' => 2, 'songId' => 'SDAH-HE', 'language' => 'he']], json_encode($plan['update']));
$check('`pt` is untouched (no write at all)',
    !in_array(1, $plan['delete'], true) && array_filter($plan['update'], static fn($u) => $u['id'] === 1) === []);
$check('`mo` and `ro` (both Romanian now) are both left exactly as they are',
    array_filter($plan['update'], static fn($u) => in_array($u['id'], [3, 4], true)) === []
    && !in_array(3, $plan['delete'], true) && !in_array(4, $plan['delete'], true));
$warn = implode(' ', $plan['warnings']);
$check('…with a warning that names both and points at the remap on /manage/languages',
    count($plan['warnings']) === 1 && str_contains($warn, '"mo"') && str_contains($warn, '"ro"')
    && str_contains($warn, '/manage/languages'), $warn);

/* ---- a real removal is still a removal ---- */
$sentMinusPt = array_values(array_filter($sent, static fn(array $s): bool => $s[1] !== 'pt'));
$plan = songTranslationsPlanSync($desiredFrom($sentMinusPt), $stored, [], $tidy);
$check('a link the curator removed (`pt`) is deleted', $plan['delete'] === [1], json_encode($plan['delete']));

/* ---- skipped for the server's reason → kept ---- */
$desired = $desiredFrom($sent);
unset($desired['he']);                                   // save_song_core skipped it: the #2131 migration has not run
$plan = songTranslationsPlanSync($desired, $stored, ['he' => true], $tidy);
$check('a link skipped because the server has not run the migration yet is NOT deleted',
    !in_array(2, $plan['delete'], true) && array_filter($plan['update'], static fn($u) => $u['id'] === 2) === []);

$storedOdd = [['id' => 9, 'songId' => 'SDAH-X', 'language' => 'Portuguese']];
$plan = songTranslationsPlanSync([], $storedOdd, ['portuguese' => true], $tidy);
$check('a stored link whose value cannot be read, sent back unchanged, is NOT deleted', $plan['delete'] === []);

/* ---- new and re-pointed links ---- */
$plan = songTranslationsPlanSync($desiredFrom([['SDAH-PT2', 'pt'], ['SDAH-DE', 'de']]),
    [['id' => 1, 'songId' => 'SDAH-PT', 'language' => 'pt']], [], $tidy);
$check('a new language is inserted', $plan['insert'] === [['songId' => 'SDAH-DE', 'language' => 'de']], json_encode($plan['insert']));
$check('a re-pointed link is updated in place', $plan['update'] === [['id' => 1, 'songId' => 'SDAH-PT2', 'language' => 'pt']]);

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
