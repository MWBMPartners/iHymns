<?php

declare(strict_types=1);

/**
 * iHymns — Scripture cross-reference index (#1112)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE (owner-confirmed):
 * A first-class, queryable scripture cross-reference index — "show every hymn
 * that sets Isaiah 53" / browse-by-passage — distinct from the free-text
 * scripture-type per-line annotation:
 *   - tblBibleBooks (seeded with the 66-book Protestant canon, OSIS codes).
 *   - tblSongScriptureRefs (SongId + optional StartLineId span + Book/Ch/Verse +
 *     OSIS key). Unblocks the lectionary feature.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-scripture-index.php
 *   Web:  /manage/setup-database → "Scripture index" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSR_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSR_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSR_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSR_output("");
_migSR_output("=== iHymns — Scripture index (#1112) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSR_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSR_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migSR_tableExists($mysql, 'tblBibleBooks')) {
        _migSR_output("  [SKIP] tblBibleBooks already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblBibleBooks (
                Id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                Code           VARCHAR(12)  NOT NULL,
                Name           VARCHAR(60)  NOT NULL,
                Testament      VARCHAR(12)  NOT NULL,
                CanonicalOrder INT UNSIGNED NOT NULL,
                UNIQUE KEY uq_Code (Code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='OSIS Bible-book reference list (#1112).'"
        );
        _migSR_output("  [OK] Created tblBibleBooks.");
    }

    /* Seed the 66-book Protestant canon (OSIS codes), idempotent via INSERT IGNORE. */
    $ot = ['Gen'=>'Genesis','Exod'=>'Exodus','Lev'=>'Leviticus','Num'=>'Numbers','Deut'=>'Deuteronomy',
        'Josh'=>'Joshua','Judg'=>'Judges','Ruth'=>'Ruth','1Sam'=>'1 Samuel','2Sam'=>'2 Samuel',
        '1Kgs'=>'1 Kings','2Kgs'=>'2 Kings','1Chr'=>'1 Chronicles','2Chr'=>'2 Chronicles','Ezra'=>'Ezra',
        'Neh'=>'Nehemiah','Esth'=>'Esther','Job'=>'Job','Ps'=>'Psalms','Prov'=>'Proverbs',
        'Eccl'=>'Ecclesiastes','Song'=>'Song of Solomon','Isa'=>'Isaiah','Jer'=>'Jeremiah','Lam'=>'Lamentations',
        'Ezek'=>'Ezekiel','Dan'=>'Daniel','Hos'=>'Hosea','Joel'=>'Joel','Amos'=>'Amos',
        'Obad'=>'Obadiah','Jonah'=>'Jonah','Mic'=>'Micah','Nah'=>'Nahum','Hab'=>'Habakkuk',
        'Zeph'=>'Zephaniah','Hag'=>'Haggai','Zech'=>'Zechariah','Mal'=>'Malachi'];
    $nt = ['Matt'=>'Matthew','Mark'=>'Mark','Luke'=>'Luke','John'=>'John','Acts'=>'Acts',
        'Rom'=>'Romans','1Cor'=>'1 Corinthians','2Cor'=>'2 Corinthians','Gal'=>'Galatians','Eph'=>'Ephesians',
        'Phil'=>'Philippians','Col'=>'Colossians','1Thess'=>'1 Thessalonians','2Thess'=>'2 Thessalonians',
        '1Tim'=>'1 Timothy','2Tim'=>'2 Timothy','Titus'=>'Titus','Phlm'=>'Philemon','Heb'=>'Hebrews',
        'Jas'=>'James','1Pet'=>'1 Peter','2Pet'=>'2 Peter','1John'=>'1 John','2John'=>'2 John',
        '3John'=>'3 John','Jude'=>'Jude','Rev'=>'Revelation'];
    $ins = $mysql->prepare("INSERT IGNORE INTO tblBibleBooks (Code, Name, Testament, CanonicalOrder) VALUES (?, ?, ?, ?)");
    $order = 0; $seeded = 0;
    foreach (['old' => $ot, 'new' => $nt] as $testament => $books) {
        foreach ($books as $code => $name) {
            $order++;
            $ins->bind_param('sssi', $code, $name, $testament, $order);
            $ins->execute();
            if ($ins->affected_rows > 0) $seeded++;
        }
    }
    $ins->close();
    _migSR_output("  [OK] Seeded {$seeded} new Bible books (66 total).");

    if (_migSR_tableExists($mysql, 'tblSongScriptureRefs')) {
        _migSR_output("  [SKIP] tblSongScriptureRefs already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongScriptureRefs (
                Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongId      VARCHAR(20)  NOT NULL,
                StartLineId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional anchor to tblLyricLines.Id (NULL = whole-song ref)',
                Book        VARCHAR(12)  NOT NULL COMMENT 'OSIS book code (FK-by-code to tblBibleBooks.Code)',
                Chapter     INT UNSIGNED NULL DEFAULT NULL,
                VerseStart  INT UNSIGNED NULL DEFAULT NULL,
                VerseEnd    INT UNSIGNED NULL DEFAULT NULL,
                OsisRef     VARCHAR(60)  NULL DEFAULT NULL COMMENT 'Versification-neutral OSIS ref',
                Source      VARCHAR(40)  NOT NULL DEFAULT 'manual' COMMENT 'manual | hymnary | parsed',
                SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
                CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_Song (SongId),
                INDEX idx_Book (Book, Chapter, VerseStart),
                INDEX idx_Line (StartLineId),
                CONSTRAINT fk_ScriptureRefs_Song FOREIGN KEY (SongId)      REFERENCES tblSongs(SongId)  ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_ScriptureRefs_Line FOREIGN KEY (StartLineId) REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Scripture cross-reference index — browse-by-passage (#1112).'"
        );
        _migSR_output("  [OK] Created tblSongScriptureRefs.");
    }

    _migSR_output("Migration complete.");
} catch (\Throwable $e) { _migSR_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
