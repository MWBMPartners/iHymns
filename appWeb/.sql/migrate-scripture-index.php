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
 * NOT DESTRUCTIVE. Two new tables plus a reference-data seed; nothing existing
 * is altered or deleted. Both tables are dormant until the browse-by-passage /
 * lectionary UI lands.
 *
 * HOW IDEMPOTENCY IS ACHIEVED — note the two halves work differently, and the
 * difference is deliberate:
 *   · The two CREATEs sit behind _migSR_tableExists() guards, so a re-run skips
 *     them.
 *   · The 66-book SEED does NOT sit behind a guard. It runs on EVERY execution
 *     and relies on `INSERT IGNORE` + `UNIQUE KEY uq_Code` to discard books
 *     already present, reporting only the count it actually added. That is on
 *     purpose: it makes re-running the card the repair action for a
 *     part-seeded table, and it is the only thing that seeds a FRESH install
 *     (schema.sql creates tblBibleBooks but ships no rows).
 *
 * ⚠ CONSEQUENCE OF INSERT IGNORE: it only ever adds. An existing row's Name,
 * Testament or CanonicalOrder is never corrected by a re-run. So amending the
 * canon list below — e.g. adding the apocrypha that schema.sql's Testament
 * COMMENT already anticipates — would give the NEW books correct ordinals while
 * leaving every previously-seeded book on its old CanonicalOrder, silently
 * corrupting canonical sort order on long-running installs while looking
 * perfect on a fresh one. Any such change needs a deliberate re-order UPDATE,
 * not just extra array entries.
 *
 * OPERATOR VIEW: /manage/setup-database → card "Scripture cross-reference index
 * (#1112)" → button "Run Scripture Index Migration". A first run reports
 * "Seeded 66 new Bible books (66 total)"; a repeat reports "Seeded 0".
 *
 * SCHEMA MIRROR: both CREATE TABLEs are mirrored in appWeb/.sql/schema.sql
 * (what a FRESH install reads instead of running this) and must stay
 * byte-identical, COMMENT text included — CLAUDE.md rule #19. schema.sql does
 * NOT mirror the seed data, so a fresh install has the tables and an EMPTY
 * tblBibleBooks until this card is run; the registry probe is table-existence
 * only, so it will already read as applied. Run the card anyway on a fresh
 * install.
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
                Code           VARCHAR(12)  NOT NULL COMMENT 'OSIS book code (Gen, Ps, Matt, …)',
                Name           VARCHAR(60)  NOT NULL,
                Testament      VARCHAR(12)  NOT NULL COMMENT 'old | new | apocrypha',
                CanonicalOrder INT UNSIGNED NOT NULL,
                UNIQUE KEY uq_Code (Code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='OSIS Bible-book reference list (#1112).'"
        );
        _migSR_output("  [OK] Created tblBibleBooks.");
    }

    /* Seed the 66-book Protestant canon (OSIS codes), idempotent via INSERT IGNORE.

       ELI5: fill in the list of Bible books so a song can point at "Isa 53".

       The keys are OSIS book codes (Gen, Ps, 1Cor, …) rather than a local
       invention, so refs are interchangeable with every other hymnody/Bible
       dataset that speaks OSIS — that interchangeability is the reason Book is
       stored as the CODE and not as an FK to tblBibleBooks.Id.

       CanonicalOrder is derived from POSITION IN THESE TWO ARRAYS, not from
       anything intrinsic — which is why the arrays are in canonical order and
       must stay that way. $order is a single counter across both testaments
       (OT 1-39, NT 40-66), giving one whole-Bible sort key rather than two
       restarting ones. It is recomputed from zero on every run, so the ordinals
       are stable across runs — but see the INSERT IGNORE warning in the file
       doc-block: existing rows are never re-numbered.

       'old' / 'new' go into Testament, a VARCHAR whose schema.sql COMMENT
       already reserves 'apocrypha' — VARCHAR not ENUM, so widening the canon is
       a data change rather than an ALTER (CLAUDE.md rule #20). */
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
        /* Two shape decisions worth stating, since neither is visible in the DDL:

           1. Book is a FK-BY-CODE, not a real foreign key to tblBibleBooks.Id.
              Storing the OSIS code keeps the row self-describing and portable
              (an export carries "Isa", not an integer that means nothing
              elsewhere), and it lets a ref be recorded for a book outside the
              seeded canon without first extending the reference table. The
              price is that referential integrity here is the app's job, not
              InnoDB's. idx_Book (Book, Chapter, VerseStart) is the
              browse-by-passage access path, left-prefix usable for
              book-only and book+chapter queries too.

           2. StartLineId is OPTIONAL and SET NULL on delete: a ref may be about
              the whole song (NULL) or anchored to the exact line that quotes the
              passage. SET NULL rather than CASCADE because deleting a lyric line
              must degrade the ref to a whole-song reference, not silently
              destroy a curator's scripture attribution — contrast the
              annotations table, where CASCADE is right because a gloss with no
              line is meaningless. */
        $mysql->query(
            "CREATE TABLE tblSongScriptureRefs (
                Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongId      VARCHAR(20)  NOT NULL,
                StartLineId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional anchor to tblLyricLines.Id (NULL = whole-song ref)',
                Book        VARCHAR(12)  NOT NULL COMMENT 'OSIS book code (FK-by-code to tblBibleBooks.Code)',
                Chapter     INT UNSIGNED NULL DEFAULT NULL,
                VerseStart  INT UNSIGNED NULL DEFAULT NULL,
                VerseEnd    INT UNSIGNED NULL DEFAULT NULL,
                OsisRef     VARCHAR(60)  NULL DEFAULT NULL COMMENT 'Versification-neutral OSIS ref e.g. Ps.23.1-Ps.23.6',
                Source      VARCHAR(40)  NOT NULL DEFAULT 'manual' COMMENT 'manual | hymnary | parsed (VARCHAR not ENUM)',
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
