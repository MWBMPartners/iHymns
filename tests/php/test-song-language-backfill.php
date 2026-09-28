<?php

declare(strict_types=1);

/**
 * iHymns — the songbook-language backfill never overwrites a deliberate
 * language code, and never runs from "Apply all" (#2137 review, #2132)
 *
 * ELI5: a migration card gives songs their songbook's language. It used to run
 * automatically with every other upgrade, and it replaced "not known" (`und`),
 * "no language" (`zxx`) and "several languages" (`mul`) with a guess that
 * could not be undone. This test proves, against a real MariaDB, that those
 * codes survive, that the card only reports unless a curator confirms, and
 * that the card is kept out of "Apply all pending migrations".
 *
 * CHECKS
 * ------
 * Part A (no database) — read from the real files, never typed in:
 *   - the registry entry is `'manual' => true` and `'dryRunnable' => true`;
 *   - setup-database.php derives its manual list from that flag and both
 *     "Apply all" paths (the server-side loop and the list the page's own
 *     bulk runner walks) skip manual cards.
 * Part B (a real database; skipped, loudly, when none is reachable):
 *   - a dry run changes nothing and reports what it would change;
 *   - a confirmed run fills an empty song and rewrites the HAC case (`en`
 *     inside a Croatian book), and leaves `und`, `zxx`, `mul`, `mis`, `qaa`,
 *     `x-hymnal`, `i-default` and a malformed value byte-for-byte alone;
 *   - songbooks whose own language is `mul`, missing or malformed are skipped;
 *   - the card's probe says "pending" before and "done" after, even though
 *     the `und` / `zxx` / `mul` songs still differ from their songbook;
 *   - a second confirmed run changes nothing.
 *
 * Mutation-proven: dropping the special-code check from the script turned the
 * "survive" checks red; dropping it from the probe turned the "done after"
 * check red; removing `'manual' => true` turned Part A red.
 *
 * Database: IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=" (the
 * same variable test-schema-installs.php reads). A throwaway database named
 * ihymns_t2137_backfill is created and dropped.
 *
 *   php tests/php/test-song-language-backfill.php
 */

$repoRoot = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};

echo "tests/php/test-song-language-backfill.php — the songbook-language backfill leaves deliberate codes alone\n";

/* ---------------------------------------------------------------- Part A */
echo "\nPart A — the card is manual and kept out of \"Apply all\"\n";

/* Real probe helper, used by the live probe call in Part B (the registry's
   probes call these; setup-database.php defines them for real). */
function _migProbe_columnExists(\mysqli $db, string $table, string $column): bool
{
    $s = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $s->bind_param('ss', $table, $column);
    $s->execute();
    $ok = $s->get_result()->fetch_row() !== null;
    $s->close();
    return $ok;
}
function _migProbe_tableExists(\mysqli $db, string $t): bool { return false; }
function _migProbe_columnIsNullable(\mysqli $db, string $t, string $c): bool { return false; }
function _migProbe_triggerExists(\mysqli $db, string $t): bool { return false; }
$hasCredentials = true;
$_SERVER['SCRIPT_FILENAME'] = '/different.php';   // the registry refuses direct access; this is a require
$MIGRATIONS = require $repoRoot . '/appWeb/public_html/manage/includes/migration-registry.php';
$entry = $MIGRATIONS['backfill-song-language-from-songbook'] ?? null;
$check('the backfill card is in the migration registry', is_array($entry));
$check("the card is 'manual' => true (never run by \"Apply all\" or the setup wizard)", ($entry['manual'] ?? null) === true);
$check("the card is 'dryRunnable' => true (a web run without confirm only reports)", ($entry['dryRunnable'] ?? null) === true);
$check('the card text says plainly that it assigns the songbook\'s language to songs that have none, as a curator\'s decision',
    str_contains((string)($entry['card']['body'] ?? ''), 'no language at all')
    && str_contains((string)($entry['card']['body'] ?? '') . (string)($entry['card']['title'] ?? ''), 'curator'));

$setupSrc = (string)file_get_contents($repoRoot . '/appWeb/public_html/manage/setup-database.php');
$check('setup-database.php builds its manual list from the registry\'s flag',
    preg_match('/if \(!empty\(\$_entry\[\'manual\'\]\)\)\s*\{\s*\$migrationManual\[\$_slug\] = true;/', $setupSrc) === 1);
$check('the server-side "Apply all" loop skips every manual card',
    preg_match('/foreach \(\(\$proceedBulk \? \$migrationOrder : \[\]\) as \$migAction\) \{.*?if \(!empty\(\$migrationManual\[\$migAction\]\)\) \{.*?continue;/s', $setupSrc) === 1);
$check('the list the page\'s bulk runner walks (and the pending counter) leaves manual cards out',
    substr_count($setupSrc, 'isset($migrationCards[$slug]) && empty($migrationManual[$slug])') >= 2);

/* ---------------------------------------------------------------- Part B */
echo "\nPart B — against a real database\n";

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
    echo "  SKIP  no database — Part B did NOT run. Set IHYMNS_TEST_DSN to run it; this is a gap, not a pass.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $name = 'ihymns_t2137_backfill';
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    try {
        $db->query('CREATE TABLE tblSongbooks (Abbreviation VARCHAR(10) NOT NULL PRIMARY KEY, Language VARCHAR(35) NULL)');
        $db->query("CREATE TABLE tblSongs (SongId VARCHAR(20) NOT NULL PRIMARY KEY, SongbookAbbr VARCHAR(10) NOT NULL, Language VARCHAR(35) NOT NULL DEFAULT 'und')");
        $db->query("INSERT INTO tblSongbooks VALUES ('HAC','hr'), ('MULTI','mul'), ('NONE',NULL), ('BAD','Croatian')");
        $rows = [
            ['HAC-1', 'HAC', ''],          // no language → filled (the curator's decision)
            ['HAC-2', 'HAC', 'en'],        // the HAC case → rewritten
            ['HAC-3', 'HAC', 'hr-HR'],     // same group → left alone
            ['HAC-4', 'HAC', 'und'], ['HAC-5', 'HAC', 'zxx'], ['HAC-6', 'HAC', 'mul'],
            ['HAC-7', 'HAC', 'mis'], ['HAC-8', 'HAC', 'qaa'], ['HAC-9', 'HAC', 'x-hymnal'],
            ['HAC-10', 'HAC', 'i-default'], ['HAC-11', 'HAC', 'English'],
            ['MULTI-1', 'MULTI', 'en'], ['MULTI-2', 'MULTI', ''],
            ['NONE-1', 'NONE', 'en'], ['BAD-1', 'BAD', ''],
        ];
        $ins = $db->prepare('INSERT INTO tblSongs (SongId, SongbookAbbr, Language) VALUES (?, ?, ?)');
        foreach ($rows as [$id, $book, $lang]) { $ins->bind_param('sss', $id, $book, $lang); $ins->execute(); }
        $ins->close();
        $snapshot = static function () use ($db): array {
            $out = [];
            $r = $db->query('SELECT SongId, Language FROM tblSongs ORDER BY SongId');
            while ($row = $r->fetch_row()) { $out[$row[0]] = $row[1]; }
            return $out;
        };
        $before = $snapshot();

        define('IHYMNS_MIGRATION_NO_AUTORUN', true);
        require_once $repoRoot . '/appWeb/.sql/migrate-backfill-song-language-from-songbook.php';
        $lines = [];
        $collect = static function (string $l) use (&$lines): void { $lines[] = $l; };

        $probe = $entry['probe'];
        $check('probe: pending before the run (one empty song, one HAC-case song)', $probe($db) === true);

        $dry = migrateBackfillSongLanguageFromSongbook($db, false, $collect);
        $check('a dry run changes nothing', $snapshot() === $before);
        $check('a dry run reports what it would change (1 filled, 1 rewritten)', $dry['filled'] === 1 && $dry['rewritten'] === 1, json_encode($dry));

        $res = migrateBackfillSongLanguageFromSongbook($db, true, $collect);
        $after = $snapshot();
        $check('a confirmed run fills the song that had no language', $after['HAC-1'] === 'hr');
        $check('a confirmed run rewrites the HAC case (en inside a Croatian book)', $after['HAC-2'] === 'hr');
        foreach (['HAC-3', 'HAC-4', 'HAC-5', 'HAC-6', 'HAC-7', 'HAC-8', 'HAC-9', 'HAC-10', 'HAC-11'] as $id) {
            $check("{$id} ({$before[$id]}) survives byte-for-byte", $after[$id] === $before[$id], "now {$after[$id]}");
        }
        foreach (['MULTI-1', 'MULTI-2', 'NONE-1', 'BAD-1'] as $id) {
            $check("{$id}: a songbook without one ordinary language changes none of its songs", $after[$id] === $before[$id], "now {$after[$id]}");
        }
        $check('the counts add up (7 special codes, 1 malformed, 1 matched, 2 books skipped)',
            $res['skippedSpecial'] === 7 && $res['skippedMalformed'] === 1 && $res['matched'] === 1 && $res['booksSkipped'] === 2,
            json_encode($res));
        $check('probe: done after the run, although und / zxx / mul songs still differ from their songbook', $probe($db) === false);

        $again = migrateBackfillSongLanguageFromSongbook($db, true, $collect);
        $check('a second confirmed run changes nothing', $snapshot() === $after && $again['filled'] === 0 && $again['rewritten'] === 0);
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
