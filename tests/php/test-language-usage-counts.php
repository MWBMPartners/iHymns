<?php

declare(strict_types=1);

/**
 * iHymns — deleting a language counts the translation links that use it
 * (#2137 review)
 *
 * ELI5: before a curator deletes a language on /manage/languages, the site
 * counts what still uses it and asks for a second click. It counted songs
 * and songbooks but not whole-song translation links, so a language used
 * only by translations looked unused. This test builds the three tables in a
 * throwaway database and checks the count includes translations — and that a
 * server without the translations table still answers (0).
 *
 * Checks (database; skipped, loudly, without one):
 *  - `pt` used only by a `pt-BR` translation link counts 1 translation, 0
 *    songs, 0 songbooks — so the delete is refused until confirmed;
 *  - `en` used by a song, a songbook and a translation counts all three;
 *  - an unused code counts 0 everywhere;
 *  - with no tblSongTranslations table the count still works (0).
 *  - (no database) both delete paths — manage/languages.php and the API —
 *    add the translations count into the refuse-unless-forced sum.
 *
 * Mutation-proven: removing the translations count from the helper turned
 * the first two database checks red.
 *
 * Database: IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=".
 *
 *   php tests/php/test-language-usage-counts.php
 */

$repoRoot = dirname(__DIR__, 2);
$web = $repoRoot . '/appWeb/public_html';
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};
echo "tests/php/test-language-usage-counts.php — a language used by translation links is shown as in use\n";

$pageSrc = (string)file_get_contents($web . '/manage/languages.php');
$apiSrc  = (string)file_get_contents($web . '/api.php');
$check('manage/languages.php refuses unless forced when translation links use the language',
    str_contains($pageSrc, '($songCount + $songbookCount + $translationCount) > 0'));
$check('the API delete does the same',
    str_contains($apiSrc, "(\$usage['songs'] + \$usage['songbooks'] + \$usage['translations']) > 0"));

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
    echo "  SKIP  no database — the counting checks did NOT run. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $name = 'ihymns_t2137_usage';
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    try {
        require_once $web . '/includes/language_admin.php';
        $db->query('CREATE TABLE tblSongs (SongId VARCHAR(20) PRIMARY KEY, Language VARCHAR(35) NOT NULL)');
        $db->query('CREATE TABLE tblSongbooks (Abbreviation VARCHAR(10) PRIMARY KEY, Language VARCHAR(35) NULL)');
        $check('with no translations table, the count still works (0 translations)',
            languageAdminUsageCounts($db, 'pt') === ['songs' => 0, 'songbooks' => 0, 'translations' => 0]);
        $db->query('CREATE TABLE tblSongTranslations (Id INT AUTO_INCREMENT PRIMARY KEY, SourceSongId VARCHAR(20), TranslatedSongId VARCHAR(20), TargetLanguage VARCHAR(35) NOT NULL)');
        $db->query("INSERT INTO tblSongs VALUES ('A-1','en'), ('A-2','en-GB')");
        $db->query("INSERT INTO tblSongbooks VALUES ('A','en')");
        $db->query("INSERT INTO tblSongTranslations (SourceSongId, TranslatedSongId, TargetLanguage) VALUES ('A-1','A-2','pt-BR'), ('A-2','A-1','en')");
        $pt = languageAdminUsageCounts($db, 'pt');
        $check('pt, used only by a pt-BR translation link, counts 1 translation (so the delete asks first)',
            $pt === ['songs' => 0, 'songbooks' => 0, 'translations' => 1], json_encode($pt));
        $en = languageAdminUsageCounts($db, 'en');
        $check('en counts its 2 songs, 1 songbook and 1 translation link',
            $en === ['songs' => 2, 'songbooks' => 1, 'translations' => 1], json_encode($en));
        $check('an unused code counts 0 everywhere',
            languageAdminUsageCounts($db, 'de') === ['songs' => 0, 'songbooks' => 0, 'translations' => 0]);
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
