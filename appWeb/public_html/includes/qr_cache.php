<?php

declare(strict_types=1);

/**
 * iHymns — QR image cache module (#1920)
 * ============================================================================
 *
 * ELI5: a QR code for a fixed link/text never changes — so the first time
 * someone asks for one, we keep a copy of the picture bytes CueRCode drew us,
 * in our OWN database, and hand that copy straight back on every later
 * request for the SAME picture. This file is the "look it up" / "save it" /
 * "throw out old ones" half; the actual "ask CueRCode to draw it" half stays
 * in includes/cuercode_client.php, which composes the two
 * (cuercodeGenerateCached()) so nobody else needs to know this cache exists.
 *
 * DETAIL:
 * Pure DB module, side-effect-free to require (no connection opened, no
 * query run just by loading this file — mirrors includes/read_rate_limit.php's
 * safety contract verbatim, rule #22 "one core per concern"):
 *
 *   - `_qrCacheTableExists()`  memoised INFORMATION_SCHEMA probe. FALSE on
 *     any error — a probe failure degrades to "cache absent", never a throw.
 *   - `qrCacheFetch()`         bound SELECT by primary key. Null on a miss OR
 *     on ANY error — a broken cache read must never take the QR endpoint
 *     down (the #1228 white-screen lesson every read-side cache in this repo
 *     follows).
 *   - `qrCacheStore()`         bound INSERT ... ON DUPLICATE KEY UPDATE
 *     CacheKey = CacheKey (a no-op "keep existing" clause — two requests
 *     racing to fill the SAME miss both computed the identical bytes from
 *     the SAME CueRCode call shape, so the first write wins and the second
 *     is silently a no-op, never a second row, never an error). Refuses to
 *     store an oversized body (belt on top of the client's own aborting
 *     write-callback) and enforces a row-count belt (oldest-first batch
 *     delete) before inserting, so the table cannot grow without bound
 *     between TTL prune runs.
 *   - `qrCachePrune()`         bound DELETE of rows older than the TTL.
 *     Exists here as the ONE prune implementation for any FUTURE in-process
 *     caller (e.g. a future admin "clear QR cache" action) that can safely
 *     require this file. `appWeb/.sql/cleanup.php` (the house cron janitor)
 *     deliberately does NOT require this file — it is a script that runs
 *     from the un-renamed `appWeb/.sql/` sibling directory and, uniquely
 *     among files in this repo, requires NOTHING from `public_html/includes/`
 *     today; reaching into `includes/qr_cache.php` from there would be the
 *     exact cross-channel docroot-naming trap rule #41 exists to prevent
 *     (alpha/beta deploy `public_html_dev`/`public_html_beta`, not
 *     `public_html`, and a CLI cron has no `IHYMNS_INCLUDES_DIR` to resolve
 *     the renamed sibling correctly). cleanup.php therefore carries its OWN
 *     tiny inline prune statement instead of requiring this file — see the
 *     comment there, which cites `QR_CACHE_TTL_DAYS` below as the source of
 *     truth for the retention number it duplicates.
 *
 * DORMANCY (rule #28's discipline applied to perf): every public function
 * here degrades to a clean no-op on an un-migrated install (`tblQrCache`
 * absent) — `cuercodeGenerateCached()` in cuercode_client.php is what makes
 * this ADDITIONALLY dormant on an UNKEYED install, by checking
 * `cuercodeConfigured()` BEFORE ever calling into this file at all (rule #38
 * — "dormant until keyed" is a property of the INSTALL, not of what this
 * cache happens to hold).
 *
 * All SQL values are bound via `bind_param()` (rule #5) — the only
 * interpolations below are the fixed table/column-name SQL text and the
 * PHP-source integer constants `QR_CACHE_MAX_ROWS` / prune-batch size, which
 * rule #5(a) treats as legitimate (hardcoded constants from PHP source, never
 * user input).
 *
 * @see includes/cuercode_client.php   cuercodeGenerateCached() — the ONE composition point
 * @see appWeb/.sql/migrate-add-qr-cache.php   the tblQrCache DDL
 * @see appWeb/.sql/cleanup.php        the house janitor's OWN inline TTL prune
 * @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
 * @see https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
 */

/**
 * Retention window for a cached QR image (days). A QR for a fixed
 * (payload, options) pair is immutable, so correctness never expires —
 * eviction here is purely a disk-space bound, not a freshness concern.
 * Code constant, not a settings row (fewer knobs, no way to misconfigure
 * the fail-soft bound — mirrors cuercode_client.php's own house style).
 *
 * ⚠️ Duplicated as a plain integer inside appWeb/.sql/cleanup.php's own
 * inline prune block (see the file-header note above for why that block
 * cannot simply require this file) — if this number ever changes, update
 * that comment/literal too.
 */
const QR_CACHE_TTL_DAYS = 90;

/** Row-count belt: at/over this many rows, `qrCacheStore()` deletes the
 *  oldest batch BEFORE inserting, so the table cannot grow unbounded
 *  between TTL prune runs even under sustained cache-fill abuse (#1920 §A.2). */
const QR_CACHE_MAX_ROWS = 20000;

/** How many oldest rows `qrCacheStore()`'s row-count belt deletes in one
 *  pass when the cap is hit — small and bounded so a single INSERT never
 *  triggers an unbounded DELETE. */
const QR_CACHE_PRUNE_BATCH = 500;

/**
 * ELI5: "does the QR cache table actually exist on this install yet?"
 * WHY: memoised per request (like read_rate_limit.php's identical-shaped
 * probe) so an un-migrated install pays for exactly one cheap
 * INFORMATION_SCHEMA lookup, not one per QR request. FALSE on ANY error —
 * a probe failure degrades to "absent" -> every caller below no-ops.
 */
function _qrCacheTableExists(\mysqli $db): bool
{
    static $exists = null; // request-scoped memo
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblQrCache' LIMIT 1"
        );
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $exists = false; // any error -> treat as "absent" -> fail open
    }
    return $exists;
}

/**
 * ELI5: "have we already got this exact QR picture saved? if so, hand it
 * back — the encoded text and every drawing option (size/format/colour/…)
 * all matched EXACTLY, because $key is derived from the same normalised
 * fold the request itself is built from."
 * WHY null-on-anything-else: a cache MISS and a cache FAILURE look
 * identical to the caller (both mean "go ask CueRCode instead") — that is
 * the whole fail-soft contract; the caller never needs to distinguish them.
 *
 * @param  string $key sha256 hex from cuercodeCacheKey().
 * @return array{bytes:string,mime:string,format:string}|null
 */
function qrCacheFetch(string $key): ?array
{
    if (!function_exists('getDbMysqli')) {
        return null;
    }
    try {
        $db = getDbMysqli();
        if (!_qrCacheTableExists($db)) {
            return null; // un-migrated install -> clean no-op
        }
        $stmt = $db->prepare('SELECT Bytes, Mime, Format FROM tblQrCache WHERE CacheKey = ? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null; // miss
        }
        return [
            'bytes'  => (string)$row['Bytes'],
            'mime'   => (string)$row['Mime'],
            'format' => (string)$row['Format'],
        ];
    } catch (\Throwable $_e) {
        return null; // any DB error -> behave exactly like a miss
    }
}

/**
 * ELI5: "remember this picture so we never have to ask CueRCode for the
 * SAME thing again." Never called with a failed/empty result (the caller,
 * cuercodeGenerateCached(), only stores a REAL success) — this function is
 * simply never the place a bad answer could get cached.
 * WHY the whole body is one try/catch: a store failure must be INVISIBLE to
 * the caller — the QR the visitor asked for has already been generated and
 * is about to be served either way; failing to cache it just means the NEXT
 * request repeats the CueRCode round trip, not that this one fails.
 *
 * @param string $key      sha256 hex from cuercodeCacheKey().
 * @param string $payload   The encoded text/URL (informational; CacheKey is authoritative).
 * @param array  $normOpts  The normalised option map cuercodeNormaliseOptions() returned.
 * @param array  $qr        {bytes, mime, format} — cuercodeGenerate()'s successful result.
 */
function qrCacheStore(string $key, string $payload, array $normOpts, array $qr): void
{
    if (!function_exists('getDbMysqli')) {
        return;
    }
    try {
        $db = getDbMysqli();
        if (!_qrCacheTableExists($db)) {
            return; // un-migrated install -> clean no-op
        }

        $bytes  = (string)($qr['bytes'] ?? '');
        $mime   = (string)($qr['mime'] ?? '');
        $format = (string)($qr['format'] ?? '');
        if ($bytes === '' || $mime === '' || $format === '') {
            return; // malformed result — never store a partial row
        }
        /* Belt on top of the client's own aborting write-callback
           (CUERCODE_MAX_RESPONSE_BYTES) — never persist an oversized body
           even if that guard were ever loosened independently. */
        if (defined('CUERCODE_MAX_RESPONSE_BYTES') && strlen($bytes) > CUERCODE_MAX_RESPONSE_BYTES) {
            return;
        }

        /* Row-count belt (#1920 §A.2 — bounds unbounded cache-fill growth
           between TTL prune runs). No parameters to bind — a plain query()
           skips a needless prepare/execute round-trip (the getSongsSlimIndex()
           precedent for a parameter-free statement). */
        $countRow = $db->query('SELECT COUNT(*) AS c FROM tblQrCache')->fetch_assoc();
        if ((int)($countRow['c'] ?? 0) >= QR_CACHE_MAX_ROWS) {
            $pruneBatch = QR_CACHE_PRUNE_BATCH; // PHP-source constant, bound (never interpolated)
            $del = $db->prepare('DELETE FROM tblQrCache ORDER BY CreatedAt ASC LIMIT ?');
            $del->bind_param('i', $pruneBatch);
            $del->execute();
            $del->close();
        }

        $paramsJson = json_encode($normOpts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($paramsJson === false) {
            return; // should be unreachable (normOpts is always a flat scalar map)
        }
        $byteLength = strlen($bytes);

        /* ON DUPLICATE KEY UPDATE CacheKey = CacheKey is a deliberate no-op
           "keep existing" clause (#1920 §3.1c): two requests racing to fill
           the SAME miss both computed IDENTICAL bytes (same key = same
           normalised request = same CueRCode answer), so the first write
           wins and the second is silently absorbed — never a duplicate-key
           error, never a second row. */
        $stmt = $db->prepare(
            'INSERT INTO tblQrCache (CacheKey, Payload, ParamsJson, Mime, Format, Bytes, ByteLength, CreatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE CacheKey = CacheKey'
        );
        $stmt->bind_param('ssssssi', $key, $payload, $paramsJson, $mime, $format, $bytes, $byteLength);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $_e) {
        /* FAIL-SOFT: a cache-write failure is invisible to the caller — the
           bytes were already generated and are about to be served either
           way; only the NEXT request's cache hit is lost. */
    }
}

/**
 * ELI5: "throw out QR pictures nobody's asked for in ages." Called from a
 * periodic janitor, never from a live request — pruning is housekeeping,
 * not something a visitor should ever wait on.
 * WHY DATE_SUB(UTC_TIMESTAMP(), …): time is SQL-side (mirrors
 * read_rate_limit.php's doctrine) so there is no PHP-vs-SQL clock skew, and
 * UTC_TIMESTAMP() matches the UTC frame `CreatedAt` was written in
 * (`CreatedAt = UTC_TIMESTAMP()` in qrCacheStore()) — never a session
 * time-zone-dependent NOW().
 *
 * @param  \mysqli $db
 * @return int Rows deleted (0 on an un-migrated install or any error).
 */
function qrCachePrune(\mysqli $db): int
{
    try {
        if (!_qrCacheTableExists($db)) {
            return 0;
        }
        $ttlDays = QR_CACHE_TTL_DAYS;
        $stmt = $db->prepare('DELETE FROM tblQrCache WHERE CreatedAt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
        $stmt->bind_param('i', $ttlDays);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    } catch (\Throwable $_e) {
        return 0; // fail-soft — a prune failure must never break the janitor run
    }
}
