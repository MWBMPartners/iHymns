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
    foreach (['pt', 'en'] as $code) { $db->query("INSERT INTO tblLanguages VALUES ('{$code}')"); }
    foreach (['S1', 'T1'] as $id) { $db->query("INSERT INTO tblSongs VALUES ('{$id}')"); }

    $db->query("INSERT INTO tblSongTranslations
                    (SourceSongId, TranslatedSongId, TargetLanguage, Translator, Verified, CreatedAt)
                VALUES ('S1', 'T1', 'pt', 'Ana', 1, '2020-01-01 00:00:00')");
    $cols = 'Id, TranslatedSongId, TargetLanguage, Translator, Verified, CreatedAt';
    $before = $db->query("SELECT {$cols} FROM tblSongTranslations ORDER BY Id")->fetch_all(MYSQLI_ASSOC);

    $db->begin_transaction();
    $warnings = songTranslationsSaveLinks($db, 'S1', [['songId' => 'T1', 'language' => 'pt-BR']]);
    $db->commit();

    $after = $db->query("SELECT {$cols} FROM tblSongTranslations ORDER BY Id")->fetch_all(MYSQLI_ASSOC);

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
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
