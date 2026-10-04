<?php

declare(strict_types=1);

/**
 * iHymns — a song save that fails part-way saves NOTHING (#2137 review round 7)
 * =============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Saving a song writes many things in one go: the song itself, its sections,
 * its tune, its translation links and more. Either all of it is saved or none
 * of it is. This file runs the REAL save — `editorSaveSongCore()`, the code
 * both song editors call — against a real database built from schema.sql,
 * makes it fail on purpose in different places, and checks two things each
 * time: the editor is told the save failed, and nothing at all was saved.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The sixth independent review of #2137 (a fresh Opus agent standing in for
 * Codex) found that the promise could still be broken in ways no existing
 * check could see:
 *
 *   1. `tuneFindOrCreateByName()` caught EVERY error and turned it into "no
 *      tune" — including MariaDB's 1020 ("Record has changed since last
 *      read"), which has already ended the whole transaction. The save carried
 *      on with no transaction at all: every later write saved itself on its
 *      own, the final `commit()` had nothing left to commit, and the editor was
 *      told "saved" over a half-saved song. `publisherFindOrCreateByName()`
 *      had the same catch. (Part A and case B1 below.)
 *   2. The existing check that the translation-link call is not wrapped in a
 *      `try` of its own reads save_song_core.php with PHP's tokenizer. A
 *      small helper function that does the swallowing somewhere else — the
 *      review planted `rv6BestEffort()` — is invisible to it. The only check
 *      that cannot be fooled by WHERE an error is swallowed is one that runs
 *      the save and looks at what happened, which is what this file does.
 *      (Cases B2 and B3.)
 *
 * WHAT IS CHECKED (on MariaDB and on MySQL; neither is skipped)
 * -------------------------------------------------------------
 *   Part A — the two find-or-create helpers, called inside a transaction,
 *            let a 1020 at their IL-id step through to the caller.
 *   B0     — a save with nothing wrong is saved (so the checks below are not
 *            passing just because nothing ever gets saved).
 *   B1     — a 1020 while the save creates a new tune: the save answers 500
 *            and nothing is saved.
 *   B4     — a deadlock (1213) while the save writes its own activity-log
 *            row (logActivity(), one of the catches decision 1's audit found
 *            swallowing such errors): the same.
 *   B2     — a 1020 during the translation-link writes: the same.
 *   B3     — a translation-link write fails AND undoing the link writes fails:
 *            the same.
 *   After every failing case, the save's own connection has no transaction
 *   left open — the save's error handler rolled back on that path, rather than
 *   merely containing a rollback somewhere.
 *
 * HOW THE FAILURES ARE MADE
 * -------------------------
 * MariaDB 11.8 raises 1020 for real when `innodb_snapshot_isolation` is ON
 * (its default): the save's transaction has already read the database, a
 * second connection (another curator) commits a change, and the save then
 * tries to change that same data. The test's connection fires that second
 * connection's change at the right moment, just before the save prepares the
 * statement concerned. MySQL never raises 1020 (its writes always read the
 * newest row), so there the same statement throws a stand-in 1020 instead. A
 * stand-in does not end the transaction the way a real one does — so on MySQL
 * it is the save's own error handler that must roll everything back, which is
 * the other half worth checking. The undo failing (B3) is made the way
 * test-song-translations-sync.php makes it: a trigger refuses one link write,
 * and the connection's `ROLLBACK TO SAVEPOINT` throws.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * It runs the save in the command line, not through the web server: the
 * request body is handed to the save through a stand-in for `php://input`,
 * and nobody is signed in (the save does not need a signed-in user to save).
 * It proves these failure paths, not every possible one.
 *
 * Database: IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=".
 * Without one, this file reports a SKIP — a gap, not a pass. It builds its own
 * database from schema.sql (named after this process) and drops it at the end.
 *
 *   php tests/php/test-song-save-whole-rollback.php
 *
 * @see appWeb/public_html/manage/editor/save_song_core.php
 * @see appWeb/public_html/includes/transaction_fatal.php  songRelocateIsTransactionFatal()
 * @see https://mariadb.com/kb/en/innodb-system-variables/#innodb_snapshot_isolation
 */

/* A session that stores nothing, started before anything is printed. The save
   asks who is signed in (manage/includes/auth.php starts a session to find
   out); in the command line, starting one after output has begun only prints
   warnings, and the default session handler would leave files behind. */
session_set_save_handler(new class implements \SessionHandlerInterface {
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
}, true);
session_start();

$repoRoot = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};
echo "tests/php/test-song-save-whole-rollback.php — a song save that fails part-way saves nothing\n";

$dsn = getenv('IHYMNS_TEST_DSN') ?: '';
$host = '127.0.0.1'; $port = 3306; $user = 'root'; $pass = '';
foreach (explode(';', $dsn) as $kv) {
    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
    if ($k === 'host') { $host = $v; }
    if ($k === 'port') { $port = (int)$v; }
    if ($k === 'user') { $user = $v; }
    if ($k === 'pass') { $pass = $v; }
}
$admin = null;
if ($dsn !== '') {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $admin = @new mysqli($host, $user, $pass, '', $port);
        if ($admin->connect_errno) { $admin = null; }
    } catch (\Throwable $e) {
        $admin = null;
    }
}
if ($admin === null) {
    echo "  SKIP  no database — nothing here ran. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
    echo "\n  {$passed} passed, {$failed} failed\n";
    exit($failed > 0 ? 1 : 0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$isMaria = str_contains((string)$admin->server_info, 'MariaDB');
$name = 'ihymns_t2137_save_' . getmypid();
$admin->query("DROP DATABASE IF EXISTS `{$name}`");
$admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->select_db($name);

/* The error log goes to a file of its own (this run's only), so the save's
   expected complaints do not clutter the output, and so the checks can read
   what the save logged. */
$logFile = (string)tempnam(sys_get_temp_dir(), 'ihymns-t2137-save-');
ini_set('error_log', $logFile);

try {
    /* ------------------------------------------------------------------ the database
       Built from schema.sql, one statement at a time, exactly as
       test-schema-installs.php does it (that file explains why splitting on
       `;` + newline is safe for this file). */
    $schemaErrors = [];
    foreach (preg_split('/;\s*\n/', (string)file_get_contents($repoRoot . '/appWeb/.sql/schema.sql')) as $chunk) {
        $keep = [];
        foreach (explode("\n", $chunk) as $l) {
            if ($keep === [] && (trim($l) === '' || str_starts_with(trim($l), '--'))) { continue; }
            $keep[] = $l;
        }
        $stmt = trim(implode("\n", $keep));
        if ($stmt === '') { continue; }
        try {
            $admin->query($stmt);
        } catch (\Throwable $e) {
            $schemaErrors[] = $e->getMessage();
        }
    }
    $check('the test database was built from schema.sql without an error', $schemaErrors === [], implode(' | ', array_slice($schemaErrors, 0, 3)));
    /* The IL-id counters (normally seeded by the ilyrics-internal-ids
       migration card), so the save mints IL ids for new songs and tunes —
       the step where the review's 1020 happens. */
    $admin->query("INSERT INTO tblIlyricsIdSequence (EntityType, Prefix, NextValue) VALUES ('song', 'ILS', 1), ('tune', 'ILT', 1), ('publisher', 'ILP', 1)");

    /* ------------------------------------------------------------------ the save's connection
       A real connection that can run a little code just before it prepares or
       runs a statement matching a pattern — once per pattern. That is how a
       failure is placed at an exact step of the real save without changing
       the save. `getDbMysqli()` hands the save this connection (it returns the
       one already made, see includes/db_mysql.php). */
    final class SaveTestConnection extends \mysqli
    {
        /** @var list<array{0: string, 1: \Closure}> pattern => what to do */
        public array $hooks = [];

        public function prepare(string $query): \mysqli_stmt|false
        {
            $this->fire($query);
            return parent::prepare($query);
        }

        public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
        {
            $this->fire($query);
            return parent::query($query, $result_mode);
        }

        private function fire(string $sql): void
        {
            foreach ($this->hooks as $i => [$pattern, $do]) {
                if (preg_match($pattern, $sql) === 1) {
                    unset($this->hooks[$i]);
                    $do($sql);
                }
            }
        }
    }

    /* The request body, handed to the save through a stand-in for
       `php://input` — the save reads the song from there, as it does on the
       web. The stand-in answers only that one read and then gives PHP's own
       `php://` streams back. */
    final class SaveTestRequestBody
    {
        public static string $body = '';
        private int $pos = 0;
        /** @var resource|null */
        public $context;

        public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
        {
            $this->pos = 0;
            return strtolower($path) === 'php://input';
        }

        public function stream_read(int $count): string|false
        {
            $out = substr(self::$body, $this->pos, $count);
            $this->pos += strlen($out);
            return $out;
        }

        public function stream_eof(): bool { return $this->pos >= strlen(self::$body); }

        public function stream_stat(): array|false { return []; }

        public function stream_close(): void { stream_wrapper_restore('php'); }
    }

    define('DB_HOST', $host);
    define('DB_USER', $user);
    define('DB_PASS', $pass);
    define('DB_NAME', $name);
    define('DB_PORT', $port);
    $_SERVER['REQUEST_METHOD'] = 'POST';

    /* What the v2 editor (manage/editor/api2.php) loads before it calls the
       save — the save relies on its caller for some of these. */
    $inc = $repoRoot . '/appWeb/public_html/includes/';
    require_once $repoRoot . '/appWeb/public_html/manage/includes/auth.php';
    foreach (['db_mysql', 'api_tokens', 'activity_log', 'webhooks', 'notifications', 'csv_safe', 'external_link_helpers',
              'SongMediaStorage', 'song_media_visibility', 'song_importers', 'lyric_lines_sync', 'line_enrichment',
              'media_language', 'vocal_parts', 'lyric_rounds', 'arrangement', 'song_relocate', 'songbook_count',
              'musician_helpers', 'identifier_normalize', 'media_identifiers', 'song_external_ids', 'song_alt_titles',
              'tune_helpers', 'publisher_helpers', 'song_copyright_holders', 'song_media_flags', 'pd_suggest',
              'work_admin', 'ilyrics_id'] as $f) {
        require_once $inc . $f . '.php';
    }
    require_once $repoRoot . '/appWeb/public_html/manage/editor/save_song_core.php';

    $conn = new SaveTestConnection($host, $user, $pass, $name, $port);
    $conn->set_charset('utf8mb4');
    $GLOBALS['_mysqliConnection'] = $conn;
    /* Another curator, on a connection of their own. */
    $other = new mysqli($host, $user, $pass, $name, $port);
    $other->set_charset('utf8mb4');
    /* An onlooker who sees only what has been COMMITTED — the truth after
       each save, whatever the save's own connection may still be holding. */
    $look = new mysqli($host, $user, $pass, $name, $port);
    $look->set_charset('utf8mb4');
    if ($isMaria) {
        $check('MariaDB: snapshot isolation is ON (the setting that makes 1020 happen for real)',
            (string)$conn->query('SELECT @@innodb_snapshot_isolation')->fetch_row()[0] === '1');
    }

    /** A 1020 for MySQL, which never raises one itself. */
    $standIn1020 = static function (): never {
        throw new \mysqli_sql_exception('Record has changed since last read (a stand-in: MySQL never raises 1020)', 1020);
    };

    /** Every table's checksum, as the onlooker sees it (committed data only). */
    $fingerprint = static function () use ($look, $name): array {
        $out = [];
        $res = $look->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$name}' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        foreach ($res->fetch_all() as [$table]) {
            $out[$table] = (string)$look->query('CHECKSUM TABLE `' . $table . '`')->fetch_row()[1];
        }
        return $out;
    };
    /** The tables whose committed content changed between two fingerprints. */
    $changedTables = static function (array $before, array $after): array {
        $out = [];
        foreach ($after as $t => $sum) {
            if (($before[$t] ?? null) !== $sum) { $out[] = $t; }
        }
        return $out;
    };
    /** Does the save's own connection still hold an open transaction? */
    $openTransactions = static function () use ($conn): int {
        return (int)$conn->query('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()')->fetch_row()[0];
    };
    /** Run the real save with this song as the request body. */
    $runSave = static function (array $song): array {
        SaveTestRequestBody::$body = (string)json_encode($song);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', SaveTestRequestBody::class);
        try {
            return editorSaveSongCore();
        } finally {
            if (!in_array('php', stream_get_wrappers(), true)) { @stream_wrapper_restore('php'); }
        }
    };
    /** A song row, as the onlooker sees it. */
    $songRow = static function (string $id) use ($look): ?array {
        $st = $look->prepare('SELECT SongId, Title, TuneName, TuneId FROM tblSongs WHERE SongId = ?');
        $st->bind_param('s', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row;
    };
    /** A song's stored translation links, as the onlooker sees them (every value as text, so the two servers compare alike). */
    $links = static function (string $id) use ($look): array {
        $st = $look->prepare('SELECT TranslatedSongId, TargetLanguage, Translator, Verified FROM tblSongTranslations WHERE SourceSongId = ? ORDER BY TargetLanguage');
        $st->bind_param('s', $id);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return array_map(static fn(array $r): array => array_map('strval', $r), $rows);
    };
    /** The most recent activity-log row for this song and action, if any. */
    $activity = static function (string $id, string $action) use ($look): ?array {
        $st = $look->prepare('SELECT Result, Details FROM tblActivityLog WHERE EntityId = ? AND Action = ? ORDER BY Id DESC LIMIT 1');
        $st->bind_param('ss', $id, $action);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row;
    };

    /* The songs every case uses: three targets for translation links, and one
       source song per case (so a case that wrongly saves something cannot
       change what a later case starts from). Each has its IL id already, so
       the save's own IL-id step for the SONG does nothing and the one 1020
       planted in B1 lands on the TUNE. */
    $admin->query("INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr, Language, IlId) VALUES
        ('MISC-0901', NULL, 'Target one',   'Misc', 'pt', 'ILS0000000901'),
        ('MISC-0902', NULL, 'Target two',   'Misc', 'es', 'ILS0000000902'),
        ('MISC-0903', NULL, 'Target three', 'Misc', 'de', 'ILS0000000903')");
    $makeSource = static function (string $id, int $n) use ($admin): void {
        $il = sprintf('ILS%010d', $n);
        $admin->query("INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr, Language, IlId) VALUES ('{$id}', NULL, 'Old title', 'Misc', 'en', '{$il}')");
        $admin->query("INSERT INTO tblSongTranslations (SourceSongId, TranslatedSongId, TargetLanguage, Translator, Verified) VALUES
            ('{$id}', 'MISC-0901', 'pt', 'Ana', 1), ('{$id}', 'MISC-0902', 'es', 'Luis', 1)");
    };
    $payload = static fn(string $id, string $tune, array $translations): array => [
        'id'           => $id,
        'title'        => 'New title',
        'songbook'     => 'Misc',
        'language'     => 'en',
        'tuneName'     => $tune,
        'components'   => [['type' => 'verse', 'number' => 1, 'lines' => ['First line', 'Second line']]],
        'translations' => $translations,
    ];

    /* ------------------------------------------------------------------ Part A */
    echo "\nPart A — the two find-or-create helpers let a 1020 at their IL-id step through (#2137 review round 7, decision 1)\n";
    foreach ([
        ['tuneFindOrCreateByName',      'tune',      'tblTunes',      'A tune created inside the transaction',      'MISC-0010', 10],
        ['publisherFindOrCreateByName', 'publisher', 'tblPublishers', 'A publisher created inside the transaction', 'MISC-0011', 11],
    ] as [$fn, $type, $table, $newName, $srcId, $ilNumber]) {
        $makeSource($srcId, $ilNumber);
        $conn->begin_transaction();
        $conn->query("SELECT * FROM tblSongs WHERE SongId = '{$srcId}'")->fetch_all();            /* the transaction's view of the data is fixed here */
        $conn->query("UPDATE tblSongs SET Title = 'written inside the transaction' WHERE SongId = '{$srcId}'");
        if ($isMaria) {
            $other->query("UPDATE tblIlyricsIdSequence SET NextValue = NextValue + 1 WHERE EntityType = '{$type}'");   /* another curator created one too, and committed */
        } else {
            $conn->hooks[] = ['/FOR UPDATE/', $standIn1020];
        }
        $thrown = null;
        $returned = 'nothing';
        try {
            $returned = var_export($fn($conn, $newName), true);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $conn->hooks = [];
        try { $conn->rollback(); } catch (\Throwable $_) {}
        $check("{$fn}(): the 1020 at the IL-id step reaches the caller as itself (it used to be logged and turned into \"no {$type}\")",
            $thrown instanceof \mysqli_sql_exception && (int)$thrown->getCode() === 1020 && songRelocateIsTransactionFatal($thrown),
            $thrown === null ? "returned {$returned}" : get_class($thrown) . ' ' . $thrown->getCode() . ': ' . $thrown->getMessage());
        $nameCount = $look->prepare("SELECT COUNT(*) FROM {$table} WHERE Name = ?");
        $nameCount->bind_param('s', $newName);
        $nameCount->execute();
        $made = (int)$nameCount->get_result()->fetch_row()[0];
        $nameCount->close();
        $check("…and nothing from that transaction was saved (the title and the new {$type} are not there)",
            ($songRow($srcId)['Title'] ?? null) === 'Old title' && $made === 0,
            json_encode([$songRow($srcId), $made]));
    }

    /* ------------------------------------------------------------------ Part B */
    echo "\nPart B — the whole save (editorSaveSongCore) saves everything or nothing\n";

    /* B0 — nothing goes wrong. */
    $makeSource('MISC-0100', 100);
    $r = $runSave($payload('MISC-0100', 'The control save tune', [['songId' => 'MISC-0901', 'language' => 'pt']]));
    $row = $songRow('MISC-0100');
    $check('B0: a save with nothing wrong answers 200 and is saved — the new title, the new tune linked, `es` removed, `pt` kept (Ana, verified)',
        $r['status'] === 200 && ($row['Title'] ?? null) === 'New title' && ($row['TuneName'] ?? null) === 'The control save tune'
        && ($row['TuneId'] ?? null) !== null
        && $links('MISC-0100') === [['TranslatedSongId' => 'MISC-0901', 'TargetLanguage' => 'pt', 'Translator' => 'Ana', 'Verified' => '1']],
        json_encode([$r, $row, $links('MISC-0100')]));
    $check('…and leaves no transaction open on its connection', $openTransactions() === 0);

    /**
     * Run one failing case: the save must answer 500, the only committed
     * changes must be the failure's own activity-log row (written after the
     * rollback) and whatever "another curator" committed, the save's
     * connection must hold no transaction, and the error that reached the
     * save's handler must be the one planted ($errorOk is given the
     * song.save_failed row's details).
     */
    $failingCase = static function (string $label, string $id, array $song, array $othersTables, string $errorWhat, callable $errorOk, ?callable $othersCheck = null)
        use ($check, $runSave, $fingerprint, $changedTables, $openTransactions, $songRow, $activity, $conn): void {
        $before = $fingerprint();
        $r = $runSave($song);
        $after = $fingerprint();
        $changed = array_values(array_diff($changedTables($before, $after), ['tblActivityLog'], $othersTables));
        $open = $openTransactions();
        $conn->hooks = [];
        try { $conn->rollback(); } catch (\Throwable $_) {}   /* tidy up for the next case, whatever happened */
        $check("{$label}: the save answers 500 \"Failed to save song\"",
            $r['status'] === 500 && str_starts_with((string)($r['body']['error'] ?? ''), 'Failed to save song'),
            json_encode($r));
        $check('…and NOTHING it wrote was saved — no table changed except the activity log' . ($othersTables === [] ? '' : ' and what the other curator committed'),
            $changed === [] && ($songRow($id)['Title'] ?? null) === 'Old title',
            'changed: ' . json_encode($changed) . ' row: ' . json_encode($songRow($id)));
        if ($othersCheck !== null) { $othersCheck(); }
        $check('…the save\'s own connection has no transaction left open (the error handler rolled back on this path)',
            $open === 0, "{$open} open");
        $logged = $activity($id, 'song.save_failed');
        $details = json_decode((string)($logged['Details'] ?? ''), true);
        $check("…and the error that reached the save's handler is {$errorWhat}, recorded as song.save_failed",
            is_array($details) && $errorOk($details), json_encode($logged));
    };
    $is1020 = static fn(array $d): bool => (int)($d['mysqli_code'] ?? 0) === 1020;

    /* B1 — a 1020 while the save creates a new tune (the review's tune1020.php
       case, through the real save). */
    $makeSource('MISC-0101', 101);
    $conn->hooks[] = ['/^\s*INSERT INTO tblTunes\b/', $isMaria
        ? static function () use ($other): void { $other->query("UPDATE tblIlyricsIdSequence SET NextValue = NextValue + 1 WHERE EntityType = 'tune'"); }
        : $standIn1020];
    $seqBefore = (int)$look->query("SELECT NextValue FROM tblIlyricsIdSequence WHERE EntityType = 'tune'")->fetch_row()[0];
    $failingCase('B1 (decision 1): a 1020 while the save creates a new tune', 'MISC-0101',
        $payload('MISC-0101', 'A tune that must not be saved', [['songId' => 'MISC-0901', 'language' => 'pt']]),
        $isMaria ? ['tblIlyricsIdSequence'] : [], 'the planted 1020', $is1020,
        static function () use ($check, $look, $seqBefore, $isMaria): void {
            $made = (int)$look->query("SELECT COUNT(*) FROM tblTunes WHERE Name = 'A tune that must not be saved'")->fetch_row()[0];
            $seq = (int)$look->query("SELECT NextValue FROM tblIlyricsIdSequence WHERE EntityType = 'tune'")->fetch_row()[0];
            $check('…the new tune does not exist, and the tune counter moved only by the other curator\'s one',
                $made === 0 && $seq === $seqBefore + ($isMaria ? 1 : 0), json_encode([$made, $seqBefore, $seq]));
        });

    /* B4 — a deadlock while the save writes its activity-log row (#2137 review
       round 7, decision 1's audit). logActivity() runs INSIDE the save's
       transaction (the song.edit row) and caught every error so that logging
       could never break a request — which, inside a transaction, let a
       deadlock (the database has already rolled everything back) be logged
       and walked past, so the save carried on with no transaction. Neither
       server will deadlock an insert on demand, so both get a stand-in 1213
       just before that row is written. */
    $makeSource('MISC-0104', 104);
    $conn->hooks[] = ['/^\s*INSERT\s+INTO\s+tblActivityLog\b/i', static function (): never {
        throw new \mysqli_sql_exception('Deadlock found when trying to get lock (a stand-in)', 1213);
    }];
    $failingCase('B4 (decision 1): a deadlock while the save writes its activity-log row', 'MISC-0104',
        $payload('MISC-0104', '', [['songId' => 'MISC-0901', 'language' => 'pt']]),
        [], 'the planted deadlock (1213)', static fn(array $d): bool => (int)($d['mysqli_code'] ?? 0) === 1213);
} finally {
    try { $admin->query("DROP DATABASE IF EXISTS `{$name}`"); } catch (\Throwable $_) {}
    @unlink($logFile);
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
