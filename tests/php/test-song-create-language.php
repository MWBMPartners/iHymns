<?php

declare(strict_types=1);

/**
 * iHymns — a new song is created with its language "not known" (`und`), even
 * on a server whose column default is still English (#2137 review, #2132)
 *
 * ELI5: the v2 editor's "New song" left the language column out of its
 * INSERT, so the column's default applied — and until an administrator runs
 * the "Song language: default to not known (und)" card, that default is `en`.
 * This test creates a song, with the real insert, in a table built the OLD
 * way (Language DEFAULT 'en'), and checks it comes out `und`.
 *
 * CHECKS
 *  - (no database) every INSERT INTO tblSongs with a written-out column list,
 *    anywhere under appWeb/public_html, names the Language column — found by
 *    scanning the tree, not from a list, so a new insert is covered too;
 *  - (no database) create_song and duplicate_song in the v2 editor both go
 *    through songInsertNewRow();
 *  - (database) songInsertNewRow() on the pre-migration column stores `und`,
 *    with a PublicId minted, and never `en`.
 *
 * Mutation-proven: taking Language out of songInsertNewRow()'s INSERT turned
 * the tree check and the database check red (the song came out `en`).
 *
 * Database: IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=" (the
 * same variable as test-schema-installs.php). A throwaway database named
 * ihymns_t2137_create is created and dropped. Without it the database check
 * is skipped, and says so.
 *
 *   php tests/php/test-song-create-language.php
 */

$repoRoot = dirname(__DIR__, 2);
$web = $repoRoot . '/appWeb/public_html';
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};
echo "tests/php/test-song-create-language.php — a new song's language is und, whatever the column default\n";

/* ---- tree: every written-out INSERT INTO tblSongs names Language ---- */
$found = 0;
$missing = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', $f->getPathname());
    if ($f->getExtension() !== 'php' || str_contains($path, '/vendor/')) {
        continue;
    }
    $src = (string)preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($f->getPathname()));
    if (preg_match_all('/INSERT\s+INTO\s+tblSongs\s*\(([^)\'".$]*)\)/i', $src, $m)) {
        foreach ($m[1] as $cols) {
            $found++;
            if (!preg_match('/\bLanguage\b/', $cols)) {
                $missing[] = substr($path, strlen($repoRoot) + 1) . ': (' . preg_replace('/\s+/', ' ', trim($cols)) . ')';
            }
        }
    }
}
$check("found the written-out INSERT INTO tblSongs statements ({$found})", $found >= 3);
$check('every one of them names the Language column (none leaves it to the column default)', $missing === [],
    implode('; ', $missing));
$api2 = (string)file_get_contents($web . '/manage/editor/api2.php');
$check('the v2 editor creates and duplicates songs through songInsertNewRow()', substr_count($api2, 'songInsertNewRow($db,') === 2);

/* ---- database ---- */
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
    echo "  SKIP  no database — the pre-migration check did NOT run. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $name = 'ihymns_t2137_create';
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    try {
        /* tblSongs as it stands BEFORE the und card: Language DEFAULT 'en'. */
        $db->query("CREATE TABLE tblSongs (
            SongId VARCHAR(20) NOT NULL PRIMARY KEY,
            PublicId VARCHAR(16) NULL DEFAULT NULL,
            Title VARCHAR(500) NOT NULL,
            NormalizedTitle VARCHAR(500) NOT NULL DEFAULT '',
            SongbookAbbr VARCHAR(10) NOT NULL,
            Language VARCHAR(35) NOT NULL DEFAULT 'en',
            UNIQUE KEY uniq_PublicId (PublicId)
        )");
        require_once $web . '/includes/song_create.php';
        songInsertNewRow($db, 'MISC-0001', 'New Song', 'new song', 'MISC');
        $row = $db->query("SELECT Language, PublicId FROM tblSongs WHERE SongId = 'MISC-0001'")->fetch_assoc();
        $check("a song created on the old column (DEFAULT 'en') is stored as und, not en", ($row['Language'] ?? null) === 'und',
            'stored ' . var_export($row['Language'] ?? null, true));
        $check('…and still gets its PublicId permalink', is_string($row['PublicId'] ?? null) && $row['PublicId'] !== '');
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
