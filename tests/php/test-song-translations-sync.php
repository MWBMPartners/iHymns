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
 *     same change on a server that HAS run the card simply succeeds.
 *
 * Part A runs the pure comparison, songTranslationsPlanSync(). Part B runs
 * the real save steps, songTranslationsSaveLinks(), against a real database
 * built the way schema.sql looks AFTER the #2131 card (no fk_Trans_Lang).
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

$plan = songTranslationsPlanSync(['pt' => $want('SDAH-PT', 'pt'), 'he' => $want('SDAH-HE', 'iw')], $stored, ['ro' => true], $tidy);
$check('`iw` is updated IN PLACE to `he` (row 2 — its translator, verified flag and date survive)',
    $plan['update'] === [['id' => 2, 'songId' => 'SDAH-HE', 'language' => 'he']], json_encode($plan['update']));
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
$check('…and a re-point the curator made to the kept one in the same save is applied in place',
    $plan['delete'] === [4] && $plan['update'] === [['id' => 3, 'songId' => 'SDAH-X', 'language' => 'mo']], json_encode($plan));
$plan = songTranslationsPlanSync([], array_slice($stored, 2), [], $tidy);
$check('neither sent back: both kept, with the warning', $plan['delete'] === [] && count($plan['warnings']) === 1);

$plan = songTranslationsPlanSync(['pt' => $want('SDAH-PT', 'pt')], [$stored[0], $stored[1]], [], $tidy);
$check('a link the curator removed (`iw`/`he`) is deleted', $plan['delete'] === [2], json_encode($plan['delete']));
$plan = songTranslationsPlanSync([], [$stored[1]], ['he' => true], $tidy);
$check('a link in the keep list (skipped for a reason that is not the curator\'s) is NOT deleted', $plan['delete'] === []);
$plan = songTranslationsPlanSync(['de' => $want('SDAH-DE', 'de'), 'pt' => $want('SDAH-PT2', 'pt')], [$stored[0]], [], $tidy);
$check('a new language is inserted and a re-pointed link is updated in place',
    array_column($plan['insert'], 'songId') === ['SDAH-DE'] && $plan['update'] === [['id' => 1, 'songId' => 'SDAH-PT2', 'language' => 'pt']]);

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

        /** Store links for S1 exactly as given, then run the real save with $sent. */
        $scenario = static function (array $storedRows, array $sent) use ($db): array {
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
            $warnings = songTranslationsSaveLinks($db, 'S1', array_map(
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
           succeeds: the pt row is gone, a fresh pt-BR row is there instead.
           A server that HAS NOT run the card cannot reach this database
           shape at all, which is exactly why the "pt survives untouched"
           half needs its own database and its own PHP process — see Part C,
           below, and its long comment on why. */
        [$b, $a, $w] = $scenario([['T1', 'pt', 'Ana', 1]], [['T1', 'pt-BR']]);
        $check('post-#2131: pt -> pt-BR succeeds outright — the pt row is gone, a fresh pt-BR row is there',
            count($a) === 1 && $a[0]['TargetLanguage'] === 'pt-BR' && $a[0]['TranslatedSongId'] === 'T1' && $w === [],
            json_encode([$a, $w]));

        /* the ordinary cases still work */
        [$b, $a, $w] = $scenario([['T1', 'pt', '', 0], ['T2', 'es', '', 0]], [['T2', 'es'], ['T3', 'de']]);
        $check('a removed link is deleted, a new one inserted, an unchanged one left',
            array_column($a, 'TargetLanguage') === ['es', 'de'] && $w === [], json_encode($a));
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

/* ---------------------------------------------------------------- Part C */
echo "\nPart C — the PRE-#2131 schema (fk_Trans_Lang still present): pt survives a failed change to pt-BR\n";
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
