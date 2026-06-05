<?php

declare(strict_types=1);

/**
 * iHymns — Shared Setlist Storage Helper
 *
 * Encapsulates the storage of public link-shared setlists in MySQL
 * (`tblSharedSetlists`).
 *
 * WS-J #1020: the legacy file-based store under APP_SETLIST_SHARE_DIR was
 * removed — every read/write is DB-direct now (governing rule: no JSON file
 * stores, DB-down = graceful error never stale). Any legacy setlist_json
 * files still on disk are imported into tblSharedSetlists by
 * migrate-users.php; until that has run for a deployment, those file-only
 * shares are unavailable. Each helper returns null/false on a miss or a DB
 * error so callers surface a clean "not found" / 500.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/**
 * Load a shared setlist by ID. Returns the decoded payload array or
 * null if not found in either store.
 */
function sharedSetlistGet(string $shareId): ?array
{
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
        /* DB unreachable — clean miss (WS-J: no disk fallback). */
    }
    return null;
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
        if ($e->getCode() === 1062) return false; /* duplicate — caller retries */
        return null;                              /* hard DB error → 500 (WS-J: no disk fallback) */
    } catch (\Throwable $_e) {
        return null;
    }
}

/**
 * Update an existing shared setlist. If no row matches (e.g. a legacy
 * file-only share that migrate-users.php hasn't imported yet) we INSERT it,
 * promoting it into MySQL. Returns true on success, false on DB failure.
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
        /* WS-J: no disk fallback — a DB failure is a clean false. */
        return false;
    }
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
