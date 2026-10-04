<?php

declare(strict_types=1);

/**
 * iHymns — a song save never loses a translation link it was not asked to
 * remove (#2137 reviews, #2131)
 *
 * ELI5: a song's translation links ("this hymn is SDAH-123 in Romanian")
 * come back from the editor on every save, and the save changes only what
 * differs. These checks prove that, on the cases the reviews found:
 *   - a link stored as `iw` (the old code for Hebrew) is updated in place to
 *     `he`, keeping who translated it, whether it was checked, and when;
 *   - two stored links that are now one language (`mo` and `ro`): if the
 *     editor sends back exactly one of them, that one is kept as it is and
 *     the other deleted; if it sends back both, or neither, both are kept and
 *     the curator is told to remove all but one in the editor and save;
 *   - two links SENT for one language (the curator added `mo` beside a
 *     stored `ro`): nothing changes for that language, and the curator is
 *     told to keep one — the stored link, translator and all, stays;
 *   - a link whose target song no longer exists is skipped WITHOUT deleting
 *     the link stored for that language;
 *   - a server that has NOT run the #2131 migration ("Translations: allow
 *     regional and script languages") cannot store `pt-BR`: a curator
 *     changing a link's language from `pt` to `pt-BR` there does not lose the
 *     `pt` link — it is left exactly as it was, with a warning naming the
 *     card that would let the change through (round 3, below in Part C). The
 *     same change on a server that HAS run the card simply succeeds;
 *   - round 4: a link that cannot be written, for ANY reason, protects BY ROW
 *     ID both the stored rows pointing at its song and the stored row filed
 *     under its language — so `English → T1` re-pointed to T2, `iw → T1`
 *     re-pointed to T2 before the card, and Ion's `mo → T3` when `ro-MD → T3`
 *     cannot be linked (inside the branch for two stored links of one
 *     language) all survive unchanged. A protected row is never changed
 *     either; a change that would touch one is itself not made (Part A2, and
 *     the (a)–(d) cases in Parts B and C);
 *   - round 5 (the fourth review): a stored language with spaces, a tab, a
 *     line break, a vertical tab or a NUL around it is protected like the
 *     same value without them (Part A3, and six Part B cases); the link
 *     writes are all or nothing — a write failing part-way leaves every link
 *     exactly as it was, says so, and keeps the rest of the song save
 *     (Part B, L2); a payload entry that is not a link changes nothing
 *     (Parts A5 and B, I6); a language change of the same song keeps the
 *     row (Parts A4 and B, L3); and a
 *     failed link spelled with a retired code (`iw`) protects the row stored
 *     under the code it tidies to (`he`), for every link of a sent clash
 *     (Part B, T16 / T17);
 *   - round 6 (the fifth review): a link's details stay with its song. A
 *     language change of the same song keeps the translator always, and the
 *     verified flag and its date only for the same primary language
 *     (`pt` → `pt-BR`: kept; `fr` → `de`: Ana kept, not verified); a link
 *     re-pointed to a song that has its own stored row nobody sent back
 *     relabels THAT row, keeping its own details, and the old song's row goes
 *     (the review's `pt → T1` Ana + `pt-BR → T2` Zed, sent `pt-BR → T1` →
 *     `pt-BR → T1`, Ana, verified); otherwise it keeps no translator and is not
 *     verified (`de → T2` re-pointed to T3); a protected row is never
 *     relabelled (Parts A6 and B).
 *
 * Part A runs the pure comparison, songTranslationsPlanSync(). Part B runs
 * the real save steps against a real database built the way schema.sql looks
 * AFTER the #2131 card (no fk_Trans_Lang) — through
 * songTranslationsSaveLinksAllOrNothing(), the call the song save makes
 * since round 5, inside a transaction the test then commits as the caller
 * does.
 * Part C runs one more scenario against a database built the way it looked
 * BEFORE that card — in its OWN php process, for a reason its own comment,
 * right before it runs, explains. Both B and C are skipped, loudly, without
 * a database.
 *
 * Mutation-proven (see the commit bodies): keying stored rows by their raw
 * language, dropping the stored-clash choice, dropping the sent-clash check,
 * dropping the "no longer exists" keep, and dropping the round-3 fix (keying
 * the "keep this stored row" list by the language a failed change was TRYING
 * to become, instead of the target song it was trying to change) each turn
 * checks red.
 * What the round-4 note here claimed, corrected (#2137 review round 5): it
 * said the protection recorded by "any one of the seven reasons a link can
 * fail" was proven. The fourth review's planted faults showed two gaps it
 * did not cover — keying a failed link by its raw spelling instead of
 * songTranslationsGroupKey() (T16), and a sent clash recording only its
 * first link (T17) both left every check green. Re-run against round 5's
 * code on MariaDB 11.8 and MySQL 8.4, EVERY one of that review's seventeen
 * translation faults now turns this file red on both servers: either half
 * of the union (T01, T02), the clash branch deleting or re-pointing a
 * protected row (T03, T04), the single-row branch deleting or changing one
 * (T05 — re-targeted after round 5 moved that delete — and T06), a blocked
 * change not protecting in turn (T07), a single planning pass (T08), each of
 * the six failure reasons recording nothing (T09–T14), the key not tidying
 * (T15), and T16 and T17. Round 5's own faults — the key or the save not
 * trimming (or trimming only four characters), the clash branch comparing
 * untrimmed, no ROLLBACK TO SAVEPOINT, rolling back the whole transaction,
 * the savepoint set after the writes, the three payload-shape rules, no
 * pairing, pairing a protected row, pairing when two stored rows or two new
 * links share the song, and comparing song ids case-sensitively — each turn
 * it red on both servers too.
 *
 * Database: IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=".
 * Run against BOTH MariaDB and MySQL — the two servers this project supports
 * — since nothing here is server-specific, only the connection is.
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
echo "tests/php/test-song-translations-sync.php — a song save keeps the translation links it was not asked to remove\n";
$check('the shared language rules loaded', mediaLanguageReady());

/* ---------------------------------------------------------------- Part A */
echo "\nPart A — the comparison (songTranslationsPlanSync)\n";
$tidy = 'mediaLanguageTagForStorage';
/** One desired link, as songTranslationsSaveLinks() builds it. */
$want = static fn(string $songId, string $raw): array => [
    'songId' => $songId, 'language' => (string)mediaLanguageTagForStorage($raw), 'raw' => mb_strtolower($raw),
];
$stored = [
    ['id' => 1, 'songId' => 'SDAH-PT', 'language' => 'pt'],
    ['id' => 2, 'songId' => 'SDAH-HE', 'language' => 'iw'],
    ['id' => 3, 'songId' => 'SDAH-MO', 'language' => 'mo'],
    ['id' => 4, 'songId' => 'SDAH-RO', 'language' => 'ro'],
];

/* Row ids 3 and 4 (`mo`, `ro`) protected — as a sent clash protects them. */
$plan = songTranslationsPlanSync(['pt' => $want('SDAH-PT', 'pt'), 'he' => $want('SDAH-HE', 'iw')], $stored, [3 => true, 4 => true], $tidy);
$check('`iw` is updated IN PLACE to `he` (row 2 — its translator, verified flag and date survive)',
    $plan['update'] === [['id' => 2, 'songId' => 'SDAH-HE', 'language' => 'he', 'details' => 'keep']], json_encode($plan['update']));
$check('`pt` needs no write; nothing is inserted', $plan['insert'] === [] && !in_array(1, $plan['delete'], true));
$check('`mo` and `ro` with neither chosen (a clash was sent): both kept, and the warning says what works',
    !in_array(3, $plan['delete'], true) && !in_array(4, $plan['delete'], true)
    && str_contains(implode(' ', $plan['warnings']), 'remove all but one of these links in the editor and save')
    && !str_contains(implode(' ', $plan['warnings']), 'remap'), json_encode($plan['warnings']));

$plan = songTranslationsPlanSync(['ro' => $want('SDAH-RO', 'ro')], array_slice($stored, 2), [], $tidy);
$check('exactly one of the clashing spellings sent back (`ro`): that row is kept as it is, the other (`mo`) deleted',
    $plan['delete'] === [3] && $plan['update'] === [] && $plan['insert'] === [] && $plan['warnings'] === [],
    json_encode($plan));
$plan = songTranslationsPlanSync(['ro' => $want('SDAH-MO', 'mo')], array_slice($stored, 2), [], $tidy);
$check('…or `mo` kept and `ro` deleted, the kept row keeping its stored spelling',
    $plan['delete'] === [4] && $plan['update'] === [], json_encode($plan));
$plan = songTranslationsPlanSync(['ro' => $want('SDAH-X', 'mo')], array_slice($stored, 2), [], $tidy);
$check('…and a re-point the curator made to the kept one in the same save is applied in place — without the old song\'s details (round 6)',
    $plan['delete'] === [4] && $plan['update'] === [['id' => 3, 'songId' => 'SDAH-X', 'language' => 'mo', 'details' => 'clear']], json_encode($plan));
$plan = songTranslationsPlanSync([], array_slice($stored, 2), [], $tidy);
$check('neither sent back: both kept, with the warning', $plan['delete'] === [] && count($plan['warnings']) === 1);

$plan = songTranslationsPlanSync(['pt' => $want('SDAH-PT', 'pt')], [$stored[0], $stored[1]], [], $tidy);
$check('a link the curator removed (`iw`/`he`) is deleted', $plan['delete'] === [2], json_encode($plan['delete']));
$plan = songTranslationsPlanSync([], [$stored[1]], [2 => true], $tidy);
$check('a protected row (a link skipped for a reason that is not the curator\'s may belong with it) is NOT deleted', $plan['delete'] === []);
$plan = songTranslationsPlanSync(['de' => $want('SDAH-DE', 'de'), 'pt' => $want('SDAH-PT2', 'pt')], [$stored[0]], [], $tidy);
$check('a new language is inserted and a re-pointed link is updated in place (with no translator and not verified: round 6)',
    array_column($plan['insert'], 'songId') === ['SDAH-DE'] && $plan['update'] === [['id' => 1, 'songId' => 'SDAH-PT2', 'language' => 'pt', 'details' => 'clear']]);

/* #2137 review round 4 — protection is by ROW ID, the union of "same song"
   and "same language key", honoured in every branch. */
echo "\nPart A2 — protected rows (#2137 review round 4)\n";
$failedAt = static fn(string $lang, string $target): array => [
    'key' => songTranslationsGroupKey($lang, 'mediaLanguageTagForStorage'), 'target' => mb_strtolower($target),
];
$english = [['id' => 7, 'songId' => 'T1', 'language' => 'English']];
$check('union, language half: a failed `English → T2` protects the row stored as `English → T1` (no stored row points at T2)',
    songTranslationsProtectedIds([$failedAt('English', 'T2')], $english, $tidy) === [7 => true]);
$check('union, song half: a failed `pt-BR → T1` protects the row stored as `pt → T1` (a different language key)',
    songTranslationsProtectedIds([$failedAt('pt-BR', 'T1')], [['id' => 8, 'songId' => 'T1', 'language' => 'pt']], $tidy) === [8 => true]);
$check('union: a failed `iw → T2` (tidies to `he`) protects `iw → T1` by its language, `de → T2` by its song, and nothing else',
    songTranslationsProtectedIds([$failedAt('iw', 'T2')], [
        ['id' => 1, 'songId' => 'T1', 'language' => 'iw'], ['id' => 2, 'songId' => 'T2', 'language' => 'de'],
        ['id' => 3, 'songId' => 'T3', 'language' => 'es'],
    ], $tidy) === [1 => true, 2 => true]);
$check('a failed link with no song protects by its language only (an empty target matches nothing)',
    songTranslationsProtectedIds([$failedAt('de', '')], [['id' => 4, 'songId' => 'T4', 'language' => 'de'], ['id' => 5, 'songId' => '', 'language' => 'es']], $tidy) === [4 => true]);

$moro = [['id' => 3, 'songId' => 'T3', 'language' => 'mo'], ['id' => 4, 'songId' => 'T4', 'language' => 'ro']];
$plan = songTranslationsPlanSync(['ro' => $want('T4', 'ro')], $moro, [3 => true], $tidy);
$check('clash branch: the curator keeps `ro`, but `mo → T3` is protected (a failed `ro-MD → T3`): NOT deleted',
    $plan['delete'] === [] && $plan['update'] === [] && $plan['blocked'] === [], json_encode($plan));
$plan = songTranslationsPlanSync(['ro' => $want('T5', 'ro')], $moro, [4 => true], $tidy);
$check('clash branch: re-pointing the chosen row when it is protected is not done — it comes back blocked, and nothing is deleted',
    $plan['delete'] === [] && $plan['update'] === [] && $plan['blocked'] === ['ro'], json_encode($plan));
$plan = songTranslationsPlanSync(['pt' => $want('T2', 'pt')], [['id' => 1, 'songId' => 'T1', 'language' => 'pt']], [1 => true], $tidy);
$check('a protected row is never changed either: its re-point comes back blocked, with no write',
    $plan['update'] === [] && $plan['delete'] === [] && $plan['blocked'] === ['pt'], json_encode($plan));
$plan = songTranslationsPlanSync([], $english, [7 => true], $tidy);
$check('a protected row that the editor did not send back is kept', $plan['delete'] === [], json_encode($plan));

/* #2137 review round 5 (L1) — the save trims what the editor sends with
   PHP's trim(); a STORED value is keyed after the very same trim, so a
   stored "English " (tab, CR, LF, vertical tab, NUL) groups with the
   "English" the editor sends back. */
echo "\nPart A3 — surrounding whitespace (#2137 review round 5)\n";
$sameAsDefaultTrim = true;
for ($b = 0; $b < 256; $b++) {
    if (trim('x' . chr($b)) !== trim('x' . chr($b), IHYMNS_TRANSLATION_LINK_TRIM)) { $sameAsDefaultTrim = false; }
}
$check('the save and the key trim exactly the characters PHP\'s own trim() does (checked for every byte)', $sameAsDefaultTrim);
$padded = ['a trailing space' => 'English ', 'a tab' => "English\t", 'a carriage return' => "English\r",
           'a leading line feed' => "\nEnglish", 'a trailing vertical tab' => "English\x0B", 'a trailing NUL' => "English\0",
           'spaces around a retired code' => " iw\t"];
foreach ($padded as $what => $value) {
    $check("a stored value with {$what} keys like the value itself",
        songTranslationsGroupKey($value, $tidy) === songTranslationsGroupKey(trim($value), $tidy),
        json_encode([songTranslationsGroupKey($value, $tidy), songTranslationsGroupKey(trim($value), $tidy)]));
}
$check('…so a failed `English → T2` protects a row stored as "English\t" → T1',
    songTranslationsProtectedIds([$failedAt('English', 'T2')], [['id' => 9, 'songId' => 'T1', 'language' => "English\t"]], $tidy) === [9 => true]);
$plan = songTranslationsPlanSync(['ro' => $want('T3', 'mo')],
    [['id' => 3, 'songId' => 'T3', 'language' => "mo "], ['id' => 4, 'songId' => 'T4', 'language' => 'ro']], [], $tidy);
$check('two stored links of one language, one stored as "mo ": the editor sends back "mo" (trimmed) — that row is recognised as the kept one, and `ro` is deleted',
    $plan['delete'] === [4] && $plan['update'] === [] && $plan['warnings'] === [], json_encode($plan));

/* #2137 review round 5 (L3) — a stored row whose language nobody sent back
   and a sent link whose language is new, pointing at the SAME song, are one
   link whose language became more precise: updated in place. */
echo "\nPart A4 — a language change of the same song keeps the row (#2137 review round 5, L3)\n";
$pt1 = ['id' => 1, 'songId' => 'T1', 'language' => 'pt'];
$plan = songTranslationsPlanSync(['pt-br' => $want('T1', 'pt-BR')], [$pt1], [], $tidy);
$check('pt → T1 sent back as pt-BR → T1: row 1 updated in place to pt-BR, all its details kept (no delete, no insert)',
    $plan['update'] === [['id' => 1, 'songId' => 'T1', 'language' => 'pt-BR', 'details' => 'keep']] && $plan['delete'] === [] && $plan['insert'] === [],
    json_encode($plan));
$plan = songTranslationsPlanSync(['pt-br' => $want('t1', 'pt-BR')], [$pt1], [], $tidy);
$check('…the song id is compared ignoring letter case, as the database does',
    $plan['update'] === [['id' => 1, 'songId' => 't1', 'language' => 'pt-BR', 'details' => 'keep']] && $plan['delete'] === [], json_encode($plan));
$plan = songTranslationsPlanSync(['pt-br' => $want('T1', 'pt-BR')], [$pt1], [1 => true], $tidy);
$check('a PROTECTED row is never changed: not paired — it is kept, and the new link added (as round 4 left it)',
    $plan['update'] === [] && $plan['delete'] === [] && array_column($plan['insert'], 'language') === ['pt-BR'], json_encode($plan));
$plan = songTranslationsPlanSync(['pt-br' => $want('T1', 'pt-BR')], [$pt1, ['id' => 2, 'songId' => 'T1', 'language' => 'es']], [], $tidy);
$check('two stored rows for that song (pt and es → T1): ambiguous, so not paired — removed and added, as before',
    $plan['update'] === [] && $plan['delete'] === [1, 2] && array_column($plan['insert'], 'language') === ['pt-BR'], json_encode($plan));
$plan = songTranslationsPlanSync(['pt-br' => $want('T1', 'pt-BR'), 'es-mx' => $want('T1', 'es-MX')], [$pt1], [], $tidy);
$check('two new links for that song (pt-BR and es-MX → T1): ambiguous, so not paired',
    $plan['update'] === [] && $plan['delete'] === [1] && count($plan['insert']) === 2, json_encode($plan));
$plan = songTranslationsPlanSync(['pt-br' => $want('T2', 'pt-BR')], [$pt1], [], $tidy);
$check('a different song: not paired (a change of language AND song is a removal plus a new link)',
    $plan['update'] === [] && $plan['delete'] === [1] && count($plan['insert']) === 1, json_encode($plan));

/* #2137 review round 6 — what a changed row keeps (the lead's decisions on
   the fifth review's findings 1 and 3). The Translator, Verified flag and
   date belong to the song a row links to. */
echo "\nPart A6 — a link's details stay with its song (#2137 review round 6)\n";
$check('details after a language change: the same primary language under the shared rule keeps them (pt → pt-BR, iw → he, zh → zh-Hant)',
    songTranslationsDetailsAfterChange('pt', 'pt-BR') === 'keep' && songTranslationsDetailsAfterChange('iw', 'he') === 'keep'
    && songTranslationsDetailsAfterChange('zh', 'zh-Hant') === 'keep' && songTranslationsDetailsAfterChange(" pt\t", 'pt-BR') === 'keep');
$check('…a different language, or a value the rule cannot read, is no longer verified (fr → de, English → en)',
    songTranslationsDetailsAfterChange('fr', 'de') === 'unverify' && songTranslationsDetailsAfterChange('English', 'en') === 'unverify'
    && songTranslationsDetailsAfterChange("pt\u{00A0}", 'pt-BR') === 'unverify'
    /* two values the rule cannot read are not "the same language" either, even spelled alike */
    && songTranslationsDetailsAfterChange('English', 'English') === 'unverify');
$plan = songTranslationsPlanSync(['de' => $want('T1', 'de')], [['id' => 1, 'songId' => 'T1', 'language' => 'fr']], [], $tidy);
$check('(finding 3) fr → T1 sent back as de → T1: the same row, Translator kept, no longer verified',
    $plan['update'] === [['id' => 1, 'songId' => 'T1', 'language' => 'de', 'details' => 'unverify']] && $plan['delete'] === [] && $plan['insert'] === [],
    json_encode($plan));
$ptT1 = ['id' => 1, 'songId' => 'T1', 'language' => 'pt'];
$ptbrT2 = ['id' => 2, 'songId' => 'T2', 'language' => 'pt-BR'];
$plan = songTranslationsPlanSync(['pt-br' => $want('T1', 'pt-BR')], [$ptT1, $ptbrT2], [], $tidy);
$check('(finding 1, the review\'s case) stored pt → T1 and pt-BR → T2, sent pt-BR → T1: T1\'s OWN row is relabelled pt-BR (keeping its details), and the T2 row goes',
    $plan['delete'] === [2] && $plan['update'] === [['id' => 1, 'songId' => 'T1', 'language' => 'pt-BR', 'details' => 'keep']] && $plan['insert'] === [],
    json_encode($plan));
$plan = songTranslationsPlanSync(['de' => $want('T3', 'de')], [['id' => 5, 'songId' => 'T2', 'language' => 'de']], [], $tidy);
$check('(finding 1) de → T2 re-pointed to T3, which has no row: the row is re-pointed in place with no translator, not verified',
    $plan['update'] === [['id' => 5, 'songId' => 'T3', 'language' => 'de', 'details' => 'clear']] && $plan['delete'] === [] && $plan['insert'] === [],
    json_encode($plan));
$plan = songTranslationsPlanSync(['pt-br' => $want('T1', 'pt-BR')], [$ptT1, $ptbrT2], [1 => true], $tidy);
$check('(finding 1) a PROTECTED row on the target song is never relabelled: it stays, and the re-pointed row keeps no details',
    $plan['delete'] === [] && $plan['update'] === [['id' => 2, 'songId' => 'T1', 'language' => 'pt-BR', 'details' => 'clear']], json_encode($plan));
$plan = songTranslationsPlanSync(['pt-br' => $want('T1', 'pt-BR'), 'es' => $want('T1', 'es')], [$ptT1, $ptbrT2], [], $tidy);
$check('(finding 1) a re-point AND a new link both claim T1\'s row: ambiguous, so neither takes it — the re-point keeps no details, the new link is added, T1\'s row goes',
    $plan['delete'] === [1] && $plan['update'] === [['id' => 2, 'songId' => 'T1', 'language' => 'pt-BR', 'details' => 'clear']]
    && array_column($plan['insert'], 'language') === ['es'], json_encode($plan));
$plan = songTranslationsPlanSync(['pt' => $want('T2', 'pt'), 'es' => $want('T1', 'es')],
    [['id' => 1, 'songId' => 'T1', 'language' => 'pt'], ['id' => 2, 'songId' => 'T2', 'language' => 'es']], [], $tidy);
$check('(finding 1) two links swap songs (both languages sent back): each row is re-pointed with no details — neither takes the other\'s',
    $plan['delete'] === [] && $plan['update'] === [
        ['id' => 1, 'songId' => 'T2', 'language' => 'pt', 'details' => 'clear'],
        ['id' => 2, 'songId' => 'T1', 'language' => 'es', 'details' => 'clear'],
    ], json_encode($plan));
$plan = songTranslationsPlanSync(['ro' => $want('T5', 'mo')],
    [['id' => 3, 'songId' => 'T3', 'language' => 'mo'], ['id' => 4, 'songId' => 'T4', 'language' => 'ro'], ['id' => 6, 'songId' => 'T5', 'language' => 'de']], [], $tidy);
$check('(finding 1) the same in the branch for two stored links of one language: the kept `mo` re-pointed to T5 → T5\'s own row takes `mo` (not the same primary as `de`, so no longer verified); `mo → T3` and `ro → T4` go',
    $plan['delete'] === [4, 3] && $plan['update'] === [['id' => 6, 'songId' => 'T5', 'language' => 'mo', 'details' => 'unverify']], json_encode($plan));

echo "\nPart A5 — what counts as a link in the payload (#2137 review round 5, I6)\n";
foreach ([
    'an object with a song and a language' => [['songId' => 'T1', 'language' => 'pt'], true],
    'an empty object (names nothing)'      => [[], true],
    'a JSON null language'                 => [['songId' => 'T1', 'language' => null], true],
    'a numeric song id'                    => [['songId' => 123, 'language' => 'pt'], true],
    'a string'                             => ['junk', false],
    'a number'                             => [42, false],
    'null'                                 => [null, false],
    'a list ["T1", "pt"]'                  => [['T1', 'pt'], false],
    'a song that is a list'                => [['songId' => ['T1'], 'language' => 'pt'], false],
    'a language that is an object'         => [['songId' => 'T1', 'language' => ['code' => 'pt']], false],
    'a language that is true'              => [['songId' => 'T1', 'language' => true], false],
    /* #2137 review round 6 (finding 2): the right meaning under other key names */
    'an object with other key names ({"song":"T2","lang":"de"})'          => [['song' => 'T2', 'lang' => 'de'], false],
    'an object with differently-cased keys ({"SongId":"T2","Language":"de"})' => [['SongId' => 'T2', 'Language' => 'de'], false],
    'an object with one right key and one other ({"songId":"T2","lang":"de"})' => [['songId' => 'T2', 'lang' => 'de'], true],
] as $what => [$entry, $want]) {
    $check("{$what} " . ($want ? 'is' : 'is NOT') . ' a link', songTranslationsIsLinkShaped($entry) === $want);
}

/* ---------------------------------------------------------------- Part B */
echo "\nPart B — the real save steps (songTranslationsSaveLinks) against a real database\n";
$dsn = getenv('IHYMNS_TEST_DSN') ?: '';
$host = '127.0.0.1'; $port = 3306; $user = 'root'; $pass = '';
foreach (explode(';', $dsn) as $kv) {
    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
    if ($k === 'host') { $host = $v; }
    if ($k === 'port') { $port = (int)$v; }
    if ($k === 'user') { $user = $v; }
    if ($k === 'pass') { $pass = $v; }
}
$db = null;
if ($dsn !== '') {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli($host, $user, $pass, '', $port);
        if ($db->connect_errno) { $db = null; }
    } catch (\Throwable $e) {
        $db = null;
    }
}
if ($db === null) {
    echo "  SKIP  no database — Part B did NOT run. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $name = 'ihymns_t2137_translations';
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    try {
        /* The tables as schema.sql builds them after the #2131 card (no
           fk_Trans_Lang); the song links cascade like the real ones. */
        $db->query("CREATE TABLE tblSongs (SongId VARCHAR(20) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE tblLanguages (Code VARCHAR(35) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE tblSongTranslations (
            Id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            SourceSongId VARCHAR(20) NOT NULL, TranslatedSongId VARCHAR(20) NOT NULL,
            TargetLanguage VARCHAR(35) NOT NULL, Translator VARCHAR(255) NOT NULL DEFAULT '',
            Verified TINYINT(1) NOT NULL DEFAULT 0, CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_Translation (SourceSongId, TargetLanguage),
            CONSTRAINT fk_t_src FOREIGN KEY (SourceSongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_t_tgt FOREIGN KEY (TranslatedSongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (['S1', 'T1', 'T2', 'T3', 'T4', 'T5'] as $id) { $db->query("INSERT INTO tblSongs VALUES ('{$id}')"); }

        /** Store links for S1 exactly as given, then run the real save with $sent
            — the call the song save makes (save_song_core.php): the
            all-or-nothing wrapper inside the caller's transaction, which the
            caller then COMMITS. $raw sends $sent as the payload itself (for
            entries that are not links); $alsoInTransaction runs first inside
            the same transaction, standing in for the rest of the song save. */
        $scenario = static function (array $storedRows, array $sent, bool $raw = false, ?callable $alsoInTransaction = null) use ($db): array {
            $db->query('DELETE FROM tblSongTranslations');
            $ins = $db->prepare("INSERT INTO tblSongTranslations (SourceSongId, TranslatedSongId, TargetLanguage, Translator, Verified, CreatedAt)
                                 VALUES ('S1', ?, ?, ?, ?, '2020-01-01 00:00:00')");
            foreach ($storedRows as [$tid, $lang, $translator, $verified]) {
                $ins->bind_param('sssi', $tid, $lang, $translator, $verified);
                $ins->execute();
            }
            $ins->close();
            $before = $db->query('SELECT Id, TranslatedSongId, TargetLanguage, Translator, Verified, CreatedAt FROM tblSongTranslations ORDER BY Id')->fetch_all(MYSQLI_ASSOC);
            $db->begin_transaction();
            if ($alsoInTransaction !== null) { $alsoInTransaction(); }
            $warnings = songTranslationsSaveLinksAllOrNothing($db, 'S1', $raw ? $sent : array_map(
                static fn(array $s): array => ['songId' => $s[0], 'language' => $s[1]], $sent
            ));
            $db->commit();
            $after = $db->query('SELECT Id, TranslatedSongId, TargetLanguage, Translator, Verified, CreatedAt FROM tblSongTranslations ORDER BY Id')->fetch_all(MYSQLI_ASSOC);
            return [$before, $after, $warnings];
        };
        $byLang = static function (array $rows): array {
            $out = [];
            foreach ($rows as $r) { $out[$r['TargetLanguage']] = $r; }
            return $out;
        };

        /* retired code */
        [$b, $a, $w] = $scenario([['T1', 'pt', '', 0], ['T2', 'iw', 'Ana', 1]], [['T1', 'pt'], ['T2', 'iw']]);
        $bl = $byLang($b); $al = $byLang($a);
        $check('iw sent back unchanged: the same row now says `he`, with its translator, verified flag and date',
            isset($al['he']) && $al['he']['Id'] === $bl['iw']['Id'] && $al['he']['Translator'] === 'Ana'
            && (int)$al['he']['Verified'] === 1 && $al['he']['CreatedAt'] === $bl['iw']['CreatedAt'] && !isset($al['iw'])
            && $al['pt'] === $bl['pt'] && $w === [], json_encode([$a, $w]));

        /* item 3 — the reviewer's mo / ro set */
        $moro = [['T3', 'mo', 'Ion', 0], ['T4', 'ro', 'Maria', 1]];
        [$b, $a, $w] = $scenario($moro, [['T4', 'ro']]);
        $bl = $byLang($b);
        $check('mo + ro stored, the curator removed mo and kept ro: mo is deleted, ro kept as it is (Maria, verified, date)',
            count($a) === 1 && $a[0] === $bl['ro'] && $w === [], json_encode([$a, $w]));
        [$b, $a, $w] = $scenario($moro, [['T3', 'mo']]);
        $bl = $byLang($b);
        $check('…or removed ro and kept mo: ro is deleted, mo kept exactly as stored (Ion, `mo`)',
            count($a) === 1 && $a[0] === $bl['mo'] && $w === [], json_encode([$a, $w]));
        [$b, $a, $w] = $scenario($moro, [['T3', 'mo'], ['T4', 'ro']]);
        $check('…both sent back: both kept, and the warnings say to keep one / remove all but one in the editor',
            $a === $b && str_contains(implode(' ', $w), 'remove all but one of these links in the editor and save')
            && str_contains(implode(' ', $w), 'keep one'), json_encode($w));
        [$b, $a, $w] = $scenario($moro, []);
        $check('…neither sent back: both kept, with the warning', $a === $b && count($w) === 1, json_encode($w));

        /* item 4 — a vanished target song */
        [$b, $a, $w] = $scenario([['T5', 'he', 'Dan', 1]], [['OLD-9', 'he']]);
        $check('stored he → T5 (translator Dan), sent he → OLD-9 (no such song): the stored link survives unchanged',
            $a === $b && str_contains(implode(' ', $w), 'OLD-9') && str_contains(implode(' ', $w), 'no longer exists'), json_encode([$a, $w]));

        /* item 7 — two SENT links for one language */
        [$b, $a, $w] = $scenario([['T4', 'ro', 'Maria', 1]], [['T4', 'ro'], ['T3', 'mo']]);
        $check('stored ro → T4 (Maria, verified); the curator adds mo → T3: nothing changes, and the curator is told to keep one',
            $a === $b && str_contains(implode(' ', $w), 'Two links for the same language') && str_contains(implode(' ', $w), 'keep one'),
            json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([], [['T1', 'pt-br'], ['T2', 'pt-BR']]);
        $check('two spellings of one new language to different songs: nothing is stored, with the warning',
            $a === [] && str_contains(implode(' ', $w), 'keep one'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([], [['T1', 'pt-BR'], ['T1', 'pt-BR']]);
        $check('the same link sent twice is one link', count($a) === 1 && $a[0]['TargetLanguage'] === 'pt-BR' && $w === []);

        /* item 8 (#2137 review round 3) — the "still works after the
           migration" half of the fault this file's own Part C proves the
           OTHER half of. This database has no fk_Trans_Lang (it is built the
           way schema.sql looks after the #2131 card), so pt -> pt-BR simply
           succeeds. A server that HAS NOT run the card cannot reach this
           database shape at all, which is exactly why the "pt survives
           untouched" half needs its own database and its own PHP process —
           see Part C, below, and its long comment on why.
           #2137 review round 5 (L3) — and it now succeeds IN PLACE: the same
           row, keeping Ana, the verified flag and the date (the pairing is
           unchanged; only its language label became more precise). Until
           round 5 the pt row was deleted and a bare pt-BR row inserted
           (reproduced on MariaDB 11.8 and MySQL 8.4). */
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1]], [['T1', 'pt-BR']]);
        $check('(L3) post-#2131: pt → T1 (Ana, verified) changed to pt-BR → T1 succeeds IN PLACE — same row, Ana, verified, same date; only the language changed',
            count($a) === 1 && $a[0]['TargetLanguage'] === 'pt-BR' && $a[0]['TranslatedSongId'] === 'T1'
            && $a[0]['Id'] === $b[0]['Id'] && $a[0]['Translator'] === 'Ana' && (int)$a[0]['Verified'] === 1
            && $a[0]['CreatedAt'] === $b[0]['CreatedAt'] && $w === [],
            json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T2', 'es', 'Luis', 1]], [['T1', 'pt-BR'], ['T2', 'es-MX']]);
        $check('(L3) two language changes in one save (pt → pt-BR on T1, es → es-MX on T2): both rows kept in place with their translators and verified flags',
            array_column($a, 'TargetLanguage') === ['pt-BR', 'es-MX'] && array_column($a, 'Id') === array_column($b, 'Id')
            && array_column($a, 'Translator') === ['Ana', 'Luis'] && array_column($a, 'Verified') === ['1', '1'] && $w === [], json_encode([$a, $w]));
        /* #2137 review round 6 — a link's details stay with its song
           (findings 1 and 3, the lead's decisions). Reproduced before this
           round on MariaDB 11.8 and MySQL 8.4: the review's case gave Zed's
           T2 details (translator, verified) to T1, and fr → de on T1 stayed
           verified. */
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T2', 'pt-BR', 'Zed', 1]], [['T1', 'pt-BR']]);
        $check('(finding 1, the review\'s case) stored pt → T1 (Ana, verified) and pt-BR → T2 (Zed, verified), sent pt-BR → T1: T1\'s own row becomes pt-BR — Ana, verified, its date — and the T2 row is gone',
            count($a) === 1 && $a[0]['Id'] === $b[0]['Id'] && $a[0]['TargetLanguage'] === 'pt-BR' && $a[0]['TranslatedSongId'] === 'T1'
            && $a[0]['Translator'] === 'Ana' && (int)$a[0]['Verified'] === 1 && $a[0]['CreatedAt'] === $b[0]['CreatedAt'] && $w === [],
            json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T2', 'de', 'Zed', 1]], [['T3', 'de']]);
        $check('(finding 1) stored de → T2 (Zed, verified) re-pointed to de → T3 (T3 has no row): de → T3 with no translator, not verified, dated now — the same row',
            count($a) === 1 && $a[0]['Id'] === $b[0]['Id'] && $a[0]['TargetLanguage'] === 'de' && $a[0]['TranslatedSongId'] === 'T3'
            && $a[0]['Translator'] === '' && (int)$a[0]['Verified'] === 0 && $a[0]['CreatedAt'] !== $b[0]['CreatedAt'] && $w === [],
            json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T2', 'pt-BR', 'Zed', 1]], [['T1', 'pt-BR'], ['T1', 'English']]);
        $bl = $byLang($b); $al = $byLang($a);
        $check('(finding 1) a PROTECTED row is never relabelled: with a failed `English → T1` beside it, Ana\'s pt → T1 is untouched, and the re-pointed pt-BR row comes to T1 with no translator and not verified',
            count($a) === 2 && isset($al['pt'], $al['pt-BR']) && $al['pt'] === $bl['pt']
            && $al['pt-BR']['Id'] === $bl['pt-BR']['Id'] && $al['pt-BR']['TranslatedSongId'] === 'T1'
            && $al['pt-BR']['Translator'] === '' && (int)$al['pt-BR']['Verified'] === 0
            && str_contains(implode(' ', $w), '"English" is not a language code'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', 'fr', 'Ana', 1]], [['T1', 'de']]);
        $check('(finding 3) fr → T1 (Ana, verified) changed to de → T1: the same row, Ana kept, NOT verified (a different language), dated now',
            count($a) === 1 && $a[0]['Id'] === $b[0]['Id'] && $a[0]['TargetLanguage'] === 'de' && $a[0]['TranslatedSongId'] === 'T1'
            && $a[0]['Translator'] === 'Ana' && (int)$a[0]['Verified'] === 0 && $a[0]['CreatedAt'] !== $b[0]['CreatedAt'] && $w === [],
            json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T2', 'es', 'Luis', 1]], [['T2', 'pt'], ['T1', 'es']]);
        $check('(finding 1) two links swap songs: each row is re-pointed, and neither takes the other song\'s translator or verified flag',
            array_column($a, 'TranslatedSongId') === ['T2', 'T1'] && array_column($a, 'Translator') === ['', '']
            && array_column($a, 'Verified') === ['0', '0'] && $w === [], json_encode([$a, $w]));

        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1]], [['T2', 'pt-BR']]);
        $check('(L3) a change of language AND song is still a removal plus a new link (nothing ties them together)',
            count($a) === 1 && $a[0]['TargetLanguage'] === 'pt-BR' && $a[0]['TranslatedSongId'] === 'T2' && $a[0]['Translator'] === ''
            && $a[0]['Id'] !== $b[0]['Id'], json_encode([$a, $w]));

        /* #2137 review round 4 — a link that cannot be written protects,
           by row id, every stored row pointing at its song AND the row
           filed under its language. Each case below lost the stored row
           before this round (reproduced on MariaDB 11 and MySQL 8.4). */
        [$b, $a, $w] = $scenario([['T1', 'English', 'Ana', 1]], [['T2', 'English']]);
        $check('(a) stored English → T1 (Ana, verified), sent English → T2: the stored row survives unchanged (language half)',
            $a === $b && str_contains(implode(' ', $w), '"English" is not a language code'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', 'English', 'Ana', 1]], [['T1', 'English']]);
        $check('(d) an unchanged save of a link stored with an unreadable language: it survives unchanged',
            $a === $b && count($w) === 1, json_encode([$a, $w]));
        [$b, $a, $w] = $scenario($moro, [['T3', 'Moldavian'], ['T4', 'ro']]);
        $check('clash branch: stored mo → T3 (Ion) and ro → T4 (Maria); `Moldavian → T3` cannot be written, ro kept: Ion\'s row is NOT deleted',
            $a === $b, json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T2', 'de', 'Eva', 1]], [['S1', 'de']]);
        $check('a link to the song itself is skipped, and the stored de link is left as it is (it used to be deleted)',
            $a === $b && str_contains(implode(' ', $w), 'translation of itself'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', '', 'Ana', 1]], [['T1', '']]);
        $check('a link with no language (a stored link with an empty language sent back) is skipped with a warning, and survives',
            $a === $b && str_contains(implode(' ', $w), 'has no language'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T2', 'es', 'Luis', 1]], [['T2', 'pt'], ['T1', 'English']]);
        $check('a change that would touch a protected row is not made, and protects in turn: pt → T1 and es → T2 both survive',
            $a === $b && str_contains(implode(' ', $w), 'was not saved'), json_encode([$a, $w]));

        /* #2137 review round 5 (L4) — the failed link is keyed by the SAME
           function as the stored rows (songTranslationsGroupKey()), so a
           failed link spelled with a retired code protects the row stored
           under the code it tidies to. The fourth review's planted fault
           (keying the failed link by its raw spelling) went unnoticed by
           every earlier test; these two catch it. */
        [$b, $a, $w] = $scenario([['T2', 'he', 'Eva', 1]], [['S1', 'iw']]);
        $check('(T16) a link to the song itself, spelled `iw`, beside a stored he → T2 (Eva): the he row is left as it is',
            $a === $b && str_contains(implode(' ', $w), 'translation of itself'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T2', 'he', 'Eva', 1]], [['', 'iw']]);
        $check('(T16) half a link — `iw` with no song — beside a stored he → T2 (Eva): the he row is left as it is',
            $a === $b && str_contains(implode(' ', $w), 'names no song'), json_encode([$a, $w]));
        /* …and EVERY link of a sent clash protects, not only the first. */
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T2', 'es', 'Luis', 1]], [['T1', 'pt'], ['T2', 'pt']]);
        $check('(T17) stored pt → T1 and es → T2; sent pt → T1 AND pt → T2 (two links for one language): es → T2 survives, and so does pt → T1',
            $a === $b && str_contains(implode(' ', $w), 'Two links for the same language'), json_encode([$a, $w]));

        /* #2137 review round 5 (L1) — a stored unreadable language with
           characters the save trims around it: the re-point to T2 cannot be
           written, and the stored row must survive. Before this round each
           of these was DELETED (reproduced on MariaDB 11 and MySQL 8.4),
           because the stored row was keyed untrimmed and the failed link
           trimmed. */
        foreach (['a trailing space' => 'English ', 'a tab' => "English\t", 'a carriage return' => "English\r",
                  'a leading line feed' => "\nEnglish", 'a trailing vertical tab' => "English\x0B", 'a trailing NUL' => "English\0"]
                 as $what => $value) {
            [$b, $a, $w] = $scenario([['T1', $value, 'Ana', 1]], [['T2', $value]]);
            $check("stored English with {$what} → T1 (Ana, verified), re-pointed to T2 (cannot be written): the stored row survives unchanged",
                $a === $b && $b[0]['TargetLanguage'] === $value && str_contains(implode(' ', $w), '"English" is not a language code'),
                json_encode([$a, $w]));
        }

        /* #2137 review round 5 (L2) — the link writes are ALL OR NOTHING.
           The reviewer's case: a junk row "pt-BR" + no-break space → T5 that
           the database counts as the same language as "pt-BR". Changing
           `pt → T1` to `pt-BR → T1` fails on the unique key part-way; the
           caller used to catch that and COMMIT, and the `pt` row (already
           deleted) was lost. Reproduced on MariaDB 11.8 and MySQL 8.4. */
        $junk = "pt-BR\u{00A0}";
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T5', $junk, 'Zed', 1]], [['T1', 'pt-BR'], ['T5', $junk]]);
        $check('(L2) stored pt → T1 (Ana) and a junk "pt-BR"+no-break-space → T5; the curator changes the first to pt-BR → T1, which fails part-way: the links are exactly as before',
            $a === $b, json_encode([$a, $w], JSON_UNESCAPED_UNICODE));
        $check('…and the one warning says they were left unchanged, and why (two would share a language)',
            count($w) === 1 && str_starts_with($w[0], 'The translation links were left unchanged because two of them would have been stored under the same language')
            && str_contains($w[0], 'The rest of the song was saved'), json_encode($w, JSON_UNESCAPED_UNICODE));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T2', 'es', 'Luis', 1], ['T5', $junk, 'Zed', 1]], [['T1', 'pt-BR'], ['T5', $junk]]);
        $check('…with a link removed in the same save (es → T2 is deleted BEFORE the failing write): that delete is undone too — all or nothing',
            $a === $b && count($w) === 1 && str_contains($w[0], 'left unchanged'), json_encode([$a, $w], JSON_UNESCAPED_UNICODE));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T5', 'ｐｔ-ＢＲ', 'Zed', 1]], [['T1', 'pt-BR'], ['T5', 'ｐｔ-ＢＲ']]);
        $check('…the same with a junk full-width "ｐｔ-ＢＲ" → T5: unchanged, with the warning',
            $a === $b && count($w) === 1 && str_contains($w[0], 'left unchanged'), json_encode([$a, $w], JSON_UNESCAPED_UNICODE));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T5', $junk, 'Zed', 1]], [['T1', 'pt-BR'], ['T5', $junk]], false,
            static function () use ($db): void { $db->query("INSERT INTO tblSongs VALUES ('MARK')"); });
        $markKept = $db->query("SELECT COUNT(*) FROM tblSongs WHERE SongId = 'MARK'")->fetch_row()[0];
        $db->query("DELETE FROM tblSongs WHERE SongId = 'MARK'");
        $check('…and ONLY the links are undone: a write the song save made earlier in the same transaction is kept (a savepoint is rolled back, not the save)',
            $a === $b && (int)$markKept === 1, 'MARK rows: ' . $markKept);

        /* #2137 review round 5 (I6) — an entry that is not a link makes the
           WHOLE translation save refuse and change nothing. It used to be
           skipped, and `de → T2` was then deleted as if removed. */
        [$b, $a, $w] = $scenario([['T2', 'de', 'Eva', 1]], ['junk'], true);
        $check('(I6) a payload of ["junk"] over a stored de → T2: nothing changes, and the warning says so (entry 1)',
            $a === $b && count($w) === 1 && str_contains($w[0], 'left unchanged') && str_contains($w[0], 'entry 1'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T2', 'de', 'Eva', 1], ['T3', 'es', 'Luis', 1]], [['songId' => 'T2', 'language' => 'de'], 42], true);
        $check('…one good link then a number: the whole save refuses — es → T3 (which the payload did not name) is NOT deleted',
            $a === $b && count($w) === 1 && str_contains($w[0], 'entry 2'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1]], [['T1', 'pt-BR']], true);
        $check('…a link sent as a list (["T1", "pt-BR"]) is not a link: refused, pt → T1 unchanged',
            $a === $b && count($w) === 1 && str_contains($w[0], 'left unchanged'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1]], [['songId' => ['T1'], 'language' => 'pt']], true);
        $check('…a link whose song is itself a list: refused, unchanged',
            $a === $b && count($w) === 1 && str_contains($w[0], 'left unchanged'), json_encode([$a, $w]));
        /* #2137 review round 6 (finding 2) — an object with neither a songId
           nor a language key. Before this round both were skipped as empty
           rows, and the stored de → T2 was DELETED (reproduced on MariaDB
           11.8 and MySQL 8.4). */
        [$b, $a, $w] = $scenario([['T2', 'de', 'Eva', 1]], [['song' => 'T2', 'lang' => 'de']], true);
        $check('(finding 2) {"song":"T2","lang":"de"} over a stored de → T2: refused, nothing changes, and the plain warning says so (entry 1)',
            $a === $b && count($w) === 1 && str_contains($w[0], 'left unchanged') && str_contains($w[0], 'entry 1'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T2', 'de', 'Eva', 1]], [['SongId' => 'T2', 'Language' => 'de']], true);
        $check('(finding 2) {"SongId":"T2","Language":"de"} (the right names, the wrong letter case) over a stored de → T2: refused, nothing changes',
            $a === $b && count($w) === 1 && str_contains($w[0], 'left unchanged') && str_contains($w[0], 'entry 1'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T2', 'de', 'Eva', 1], ['T3', 'es', 'Luis', 1]], [['songId' => 'T2', 'language' => 'de'], ['song' => 'T3', 'lang' => 'es']], true);
        $check('(finding 2) …after a good link: the whole save refuses (entry 2), and es → T3 is NOT deleted',
            $a === $b && count($w) === 1 && str_contains($w[0], 'entry 2'), json_encode([$a, $w]));
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1], ['T2', 'es', 'Luis', 1]], [['songId' => 'T1', 'language' => 'pt'], []], true);
        $check('…but an EMPTY object still names nothing and is skipped as before (es → T2, not sent, is removed)',
            array_column($a, 'TargetLanguage') === ['pt'] && $w === [], json_encode([$a, $w]));

        /* the ordinary cases still work */
        [$b, $a, $w] = $scenario([['T1', 'pt', '', 0], ['T2', 'es', '', 0]], [['T2', 'es'], ['T3', 'de']]);
        $check('a removed link is deleted, a new one inserted, an unchanged one left',
            array_column($a, 'TargetLanguage') === ['es', 'de'] && $w === [], json_encode($a));
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

/* ---------------------------------------------------------------- Part C */
echo "\nPart C — the PRE-#2131 schema (fk_Trans_Lang still present): stored links survive changes the server cannot store yet\n";
if ($db === null) {
    echo "  SKIP  no database — Part C did NOT run. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
} else {
    /* songTranslationsLanguageFkPresent() remembers its answer for the whole
       PHP process (see its own doc comment — one database per request is a
       safe assumption in production). Part B, just above, already asked it
       once, against a database that HAS had the #2131 migration, in THIS
       process. Asking it again here, against a second, un-migrated database,
       would just return Part B's cached answer — this test would look like
       it proved something about a server that has not run the migration
       when it was really still testing the one that had. So this one
       scenario runs in its own fresh PHP process instead (the same reason
       tests/php/test-song-language-backfill.php spawns its migration script
       via proc_open() rather than calling it in-process): a fresh process
       has never asked the question before, so its answer is the truthful
       one for the database it is actually given. */
    $helper = __DIR__ . '/fixtures/song-translations-pre2131-scenario.php';
    $proc = proc_open(
        [PHP_BINARY, $helper, $host, (string)$port, $user, $pass],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        $failed++;
        echo "  FAIL  could not start the Part C subprocess\n";
    } else {
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        echo $out;
        if ($code !== 0) {
            $failed++;
            echo "  FAIL  Part C subprocess exited {$code}\n" . ($err !== '' ? $err . "\n" : '');
        } elseif (preg_match('/(\d+) passed, (\d+) failed/', $out, $m)) {
            /* Fold the subprocess's own tally into this script's, so the
               final line at the bottom is complete rather than silently
               missing what Part C checked. */
            $passed += (int)$m[1];
            $failed += (int)$m[2];
        } else {
            $failed++;
            echo "  FAIL  could not read Part C's own pass/fail tally from its output\n";
        }
    }
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
