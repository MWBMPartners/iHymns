<?php

declare(strict_types=1);

/**
 * iHymns — Semantic-search embedding cache (#1090 P11)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblSongEmbeddings is the durable cache for semantic search. ContentHash
 * (sha256 of the embedded text) lets an edit invalidate exactly the affected
 * rows (re-embed on mismatch only — NEVER a whole-corpus re-embed; rule #17).
 * MySQL 8 has no native ANN here, so similarity is app-side cosine over a
 * FULLTEXT-prefiltered candidate set. Prepped now, dormant until semantic
 * search ships.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-embeddings.php
 *   Web:  /manage/setup-database → "Song embeddings (semantic search)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migEmb_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migEmb_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migEmb_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migEmb_output("");
_migEmb_output("=== iHymns — Song embeddings (#1090 P11) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migEmb_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migEmb_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migEmb_tableExists($mysql, 'tblSongEmbeddings')) {
        _migEmb_output("  [SKIP] tblSongEmbeddings already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongEmbeddings (
                Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongId      VARCHAR(20)     NOT NULL COMMENT 'FK to tblSongs.SongId',
                ModelKey    VARCHAR(80)     NOT NULL COMMENT 'Embedding model, e.g. openai-text-embedding-3-small | voyage-3 (multiple models coexist)',
                Dims        SMALLINT UNSIGNED NOT NULL COMMENT 'Vector dimensionality',
                `Vector`    MEDIUMBLOB      NOT NULL COMMENT 'Packed float32 vector',
                ContentHash CHAR(64)        NOT NULL COMMENT 'sha256 of the embedded text — drives re-index-on-edit (re-embed only on mismatch)',
                SourceField VARCHAR(20)     NOT NULL DEFAULT 'lyrics' COMMENT 'lyrics | title | combined',
                GeneratedAt DATETIME        NOT NULL,
                CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_SongModelField (SongId, ModelKey, SourceField),
                INDEX      idx_Model         (ModelKey),
                INDEX      idx_Hash          (ContentHash),
                CONSTRAINT fk_Embedding_Song FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Semantic-search embedding cache (#1090 P11, prep-now/dormant).'"
        );
        _migEmb_output("  [OK] Created tblSongEmbeddings.");
    }
    _migEmb_output("Migration complete.");
} catch (\Throwable $e) { _migEmb_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
