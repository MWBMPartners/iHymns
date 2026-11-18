<?php

declare(strict_types=1);

/**
 * iHymns — subprocess helper: songTranslationsSaveLinks() against a
 * PRE-#2131 schema (#2137 review round 3)
 *
 * ELI5
 * ----
 * `songTranslationsLanguageFkPresent()` remembers its answer for the whole
 * lifetime of the PHP process it runs in (see its own doc comment) — a fair
 * assumption in production, where one request only ever has one database.
 * But it means `tests/php/test-song-translations-sync.php`'s own Part B
 * already asks that question, once, against a database that HAS had the
 * #2131 migration. Asking it again in the same process, against a second,
 * un-migrated database, would just hand back Part B's cached answer, not a
 * fresh one — the test would look like it proved something about a
 * pre-migration server when it was really still testing the post-migration
 * one.
 *
 * So this one small scenario runs in its OWN process instead, spawned by the
 * main test file's Part C via `proc_open()` — the exact reason
 * `tests/php/test-song-language-backfill.php` spawns its own migration
 * script via `proc_open()` rather than calling it in-process.
 *
 * WHAT IT PROVES
 * ---------------
 * On a database that still has `fk_Trans_Lang` (i.e. the "Translations:
 * allow regional and script languages" card has NOT been run): a stored
 * translation link in `pt`, with a translator credit and a verified flag,
 * is left exactly as it is — same row id, same translator, same verified
 * flag, same creation date — when the curator tries to change its language
 * to `pt-BR`, because this database cannot store `pt-BR` yet. The warning
 * names the language and points at the card that would let it through.
 *
 * #2137 review round 4 added two more, each of which DELETED the stored row
 * before that round (reproduced on MariaDB 11 and MySQL 8.4):
 *   - `tblLanguages` holds `iw` but not `he` (as an old registry can): a
 *     link stored as `iw → T1` re-pointed to T2 cannot be written (`iw`
 *     tidies to `he`, which this server cannot link), and no stored row
 *     points at T2 — the stored row survives by its LANGUAGE;
 *   - `mo → T3` (Ion) and `ro → T4` (Maria) are stored, and the curator
 *     sends `ro-MD → T3` and `ro → T4`: `ro-MD` cannot be linked yet, so
 *     Ion's row survives by its SONG — inside the branch for two stored
 *     links of one language, which never looked at the protection before.
 * All three share this one process, which is safe: every database here has
 * `fk_Trans_Lang`, so the remembered answer is the true one for each, and
 * `tblLanguages` is read afresh on every save.
 *
 * Prints PASS/FAIL lines in the same shape every other suite here uses, and
 * exits 0 only when everything passed, so the parent script can just watch
 * the exit code and echo this script's own output as its own.
 *
 * Args: <host> <port> <user> <pass>
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * @see appWeb/public_html/includes/song_translations_sync.php
 * @see tests/php/test-song-translations-sync.php  (Part C spawns this)
 */

[, $host, $portArg, $user, $pass] = $argv + [null, null, null, null, null];
$port = (int)$portArg;

$repoRoot = dirname(__DIR__, 3);
require_once $repoRoot . '/appWeb/public_html/includes/media_language.php';
require_once $repoRoot . '/appWeb/public_html/includes/song_translations_sync.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string)$host, (string)$user, (string)$pass, '', $port);
if ($db->connect_errno) {
    fwrite(STDERR, "song-translations-pre2131-scenario: could not connect — {$db->connect_error}\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$name = 'ihymns_t2137_fk_pre2131';
$db->query("DROP DATABASE IF EXISTS `{$name}`");
$db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($name);

try {
    /* The schema exactly as it stood BEFORE the #2131 card ran: TargetLanguage
       still carries fk_Trans_Lang to tblLanguages, which holds only bare
       codes — this is the one table this migration touches. */
    $db->query('CREATE TABLE tblSongs (
        SongId VARCHAR(20) NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->query('CREATE TABLE tblLanguages (
        Code VARCHAR(35) NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->query('CREATE TABLE tblSongTranslations (
        Id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SourceSongId VARCHAR(20) NOT NULL, TranslatedSongId VARCHAR(20) NOT NULL,
        TargetLanguage VARCHAR(35) NOT NULL, Translator VARCHAR(255) NOT NULL DEFAULT \'\',
        Verified TINYINT(1) NOT NULL DEFAULT 0, CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_Translation (SourceSongId, TargetLanguage),
        CONSTRAINT fk_t_src FOREIGN KEY (SourceSongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_t_tgt FOREIGN KEY (TranslatedSongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_Trans_Lang FOREIGN KEY (TargetLanguage) REFERENCES tblLanguages(Code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach (['S1', 'T1', 'T2', 'T3', 'T4'] as $id) { $db->query("INSERT INTO tblSongs VALUES ('{$id}')"); }
    $cols = 'Id, TranslatedSongId, TargetLanguage, Translator, Verified, CreatedAt';

    /** Reset tblLanguages and the stored links, run the real save with $sent. */
    $scenario = static function (array $codes, array $storedRows, array $sent) use ($db, $cols): array {
        $db->query('DELETE FROM tblSongTranslations');
        $db->query('DELETE FROM tblLanguages');
        $li = $db->prepare('INSERT INTO tblLanguages VALUES (?)');
        foreach ($codes as $code) { $li->bind_param('s', $code); $li->execute(); }
        $li->close();
        $ins = $db->prepare("INSERT INTO tblSongTranslations
                                 (SourceSongId, TranslatedSongId, TargetLanguage, Translator, Verified, CreatedAt)
                             VALUES ('S1', ?, ?, ?, 1, '2020-01-01 00:00:00')");
        foreach ($storedRows as [$tid, $lang, $translator]) { $ins->bind_param('sss', $tid, $lang, $translator); $ins->execute(); }
        $ins->close();
        $before = $db->query("SELECT {$cols} FROM tblSongTranslations ORDER BY Id")->fetch_all(MYSQLI_ASSOC);
        $db->begin_transaction();
        /* The call the song save makes: all or nothing (#2137 review round 5). */
        $warnings = songTranslationsSaveLinksAllOrNothing($db, 'S1', array_map(
            static fn(array $l): array => ['songId' => $l[0], 'language' => $l[1]], $sent
        ));
        $db->commit();
        $after = $db->query("SELECT {$cols} FROM tblSongTranslations ORDER BY Id")->fetch_all(MYSQLI_ASSOC);
        return [$before, $after, $warnings];
    };

    [$before, $after, $warnings] = $scenario(['pt', 'en'], [['T1', 'pt', 'Ana']], [['T1', 'pt-BR']]);
    $check(
        'pre-#2131: pt kept byte-for-byte (same row, translator, verified flag, date) when pt-BR cannot be stored',
        $after === $before,
        json_encode(['before' => $before, 'after' => $after])
    );
    $check(
        'pre-#2131: the warning names pt-BR and points at the "Translations: allow regional and script languages" card',
        count($warnings) === 1
            && str_contains($warnings[0], 'pt-BR')
            && str_contains($warnings[0], 'Translations: allow regional and script languages')
            && str_contains($warnings[0], '/manage/setup-database'),
        json_encode($warnings)
    );

    [$before, $after, $warnings] = $scenario(['iw', 'pt', 'en'], [['T1', 'iw', 'Ana']], [['T2', 'iw']]);
    $check(
        '(b) pre-#2131, tblLanguages has iw not he: stored iw → T1 re-pointed to T2 cannot be linked — the stored row survives unchanged',
        $after === $before && count($warnings) === 1 && str_contains($warnings[0], '"he" cannot be linked'),
        json_encode(['before' => $before, 'after' => $after, 'warnings' => $warnings])
    );

    [$before, $after, $warnings] = $scenario(['mo', 'ro', 'en'], [['T3', 'mo', 'Ion'], ['T4', 'ro', 'Maria']], [['T3', 'ro-MD'], ['T4', 'ro']]);
    $check(
        '(c) pre-#2131: stored mo → T3 (Ion) and ro → T4 (Maria), sent ro-MD → T3 and ro → T4 — Ion\'s row survives (the two-links-one-language branch)',
        $after === $before && str_contains(implode(' ', $warnings), '"ro-MD" cannot be linked'),
        json_encode(['before' => $before, 'after' => $after, 'warnings' => $warnings])
    );
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
