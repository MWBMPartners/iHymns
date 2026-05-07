<?php

declare(strict_types=1);

/**
 * iHymns — Shared Setlist Storage Helper
 *
 * Encapsulates the storage of public link-shared setlists. Prefers
 * MySQL (`tblSharedSetlists`); transparently falls back to the legacy
 * file-based store under APP_SETLIST_SHARE_DIR when the table is
 * missing — so a deployment that hasn't run migrate-account-sync.php
 * yet keeps working unchanged until the admin opts in.
 *
 * Once migration has run, every read/write flows through MySQL and
 * the file path becomes dead code. The original JSON files are left
 * on disk by the migration so admins can verify the import before
 * deleting them.
 *
 * Each helper returns null/false on a clean miss so callers don't have
 * to differentiate "not found" from "DB unavailable" — that's
 * intentional, since the fallback handles the latter.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/**
 * Load a shared setlist by ID. Returns the decoded payload array or
 * null if not found in either store.
 */
function sharedSetlistGet(string $shareId): ?array
{
    /* MySQL first */
    try {
        $db   = getDbMysqli();
        $stmt = $db->prepare('SELECT Data FROM tblSharedSetlists WHERE ShareId = ?');
        $stmt->bind_param('s', $shareId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $raw = (string)($row[0] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
    } catch (\Throwable $_e) {
        /* DB unreachable or table missing — fall through to file lookup */
    }

    /* Legacy disk fallback */
    $path = APP_SETLIST_SHARE_DIR . DIRECTORY_SEPARATOR . $shareId . '.json';
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Atomically insert a new shared setlist. Returns true on success,
 * false on a duplicate-ID collision so the caller can retry with a
 * fresh ID, or null on a hard failure (caller should surface a 500).
 */
function sharedSetlistInsert(string $shareId, array $data): ?bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    /* MySQL atomic insert — duplicate-key collision is the signal so
       the caller can pick another ID. PDO surfaced this as SQLSTATE
       23000 (string); mysqli_sql_exception::getCode() returns the
       MySQL error number 1062 (ER_DUP_ENTRY) for the same condition. */
    try {
        $db   = getDbMysqli();
        $stmt = $db->prepare('INSERT INTO tblSharedSetlists (ShareId, Data) VALUES (?, ?)');
        $stmt->bind_param('ss', $shareId, $json);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) return false; /* duplicate */
        /* Other DB error — try disk path below */
    } catch (\Throwable $_e) {
        /* DB unavailable — try disk path below */
    }

    /* Disk fallback — `x` mode is atomic and fails if file exists. */
    $path = APP_SETLIST_SHARE_DIR . DIRECTORY_SEPARATOR . $shareId . '.json';
    $fp   = @fopen($path, 'x');
    if ($fp === false) {
        return is_file($path) ? false : null;
    }
    $written = fwrite($fp, $json);
    fclose($fp);
    return $written !== false ? true : null;
}

/**
 * Update an existing shared setlist. If the row doesn't exist in MySQL
 * (e.g. it was historically file-only) we INSERT it — this is the
 * gradual-migration path: the next save promotes the file's contents
 * into MySQL. Returns true on success, false otherwise.
 */
function sharedSetlistUpdate(string $shareId, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $db   = getDbMysqli();
        $stmt = $db->prepare('UPDATE tblSharedSetlists SET Data = ?, UpdatedAt = NOW() WHERE ShareId = ?');
        $stmt->bind_param('ss', $json, $shareId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) return true;
        /* No row matched — promote into MySQL on first edit */
        $stmt = $db->prepare('INSERT INTO tblSharedSetlists (ShareId, Data) VALUES (?, ?)');
        $stmt->bind_param('ss', $shareId, $json);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $_e) {
        /* Fall through to file write */
    }

    $path = APP_SETLIST_SHARE_DIR . DIRECTORY_SEPARATOR . $shareId . '.json';
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Increment the view counter on a shared setlist. Best-effort —
 * silent on failure since the read itself already succeeded.
 */
function sharedSetlistMarkViewed(string $shareId): void
{
    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('UPDATE tblSharedSetlists SET ViewCount = ViewCount + 1 WHERE ShareId = ?');
        $stmt->bind_param('s', $shareId);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $_e) {
        /* Non-critical */
    }
}
