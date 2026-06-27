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
 * Column-existence probe for the #1380 live-link columns
 * (tblSharedSetlists.OwnerUserId + SourceSetlistId).
 *
 * The two columns arrive via migrate-shared-setlist-live-link.php. Until that
 * has run, every helper here MUST keep using the legacy 2-column shape — mysqli
 * runs under MYSQLI_REPORT_ERROR|STRICT, so SELECTing / INSERTing a column that
 * does not exist would THROW and break sharing entirely. The probe is
 * static-cached for the request so the INFORMATION_SCHEMA round-trip happens at
 * most once per request even when the share helpers run in a loop. Fail-open: on
 * any probe error we report "absent" and the legacy path runs. (#1380)
 */
function _sharedSetlistLiveLinkColumns(): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $db   = getDbMysqli();
        /* Both columns must exist before we use either — a multi-object check,
           never a partial one (matches the migration's OR-probe). Bound params
           (rule #5), never string-interpolation. */
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSharedSetlists'
                AND COLUMN_NAME IN ('OwnerUserId', 'SourceSetlistId')"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $cached = ((int)($row[0] ?? 0) === 2);
    } catch (\Throwable $_e) {
        $cached = false; /* fail-open → legacy 2-column path */
    }
    return $cached;
}

/**
 * Load a shared setlist by ID. Returns the decoded payload array or
 * null if not found in either store.
 *
 * When the #1380 live-link columns are present, OwnerUserId / SourceSetlistId
 * are returned under the PRIVATE keys `_ownerUserId` / `_sourceSetlistId`
 * (leading underscore = "server-internal, never echoed to clients" — callers
 * MUST strip these before building any response). These resolve the owner's
 * CURRENT live setlist at read time.
 */
function sharedSetlistGet(string $shareId): ?array
{
    try {
        $db = getDbMysqli();

        /* Live-link columns selected only when present, so a pre-migration
           install keeps working with the legacy 2-column read. */
        if (_sharedSetlistLiveLinkColumns()) {
            $stmt = $db->prepare(
                'SELECT Data, OwnerUserId, SourceSetlistId FROM tblSharedSetlists WHERE ShareId = ?'
            );
            $stmt->bind_param('s', $shareId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $raw = (string)($row['Data'] ?? '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    /* PRIVATE keys (leading underscore) — owner identity is
                       NEVER part of the public wire shape; setlist_get strips
                       them when building its allow-listed response. */
                    $decoded['_ownerUserId']     = isset($row['OwnerUserId']) && $row['OwnerUserId'] !== null
                        ? (int)$row['OwnerUserId'] : null;
                    $decoded['_sourceSetlistId'] = isset($row['SourceSetlistId']) && $row['SourceSetlistId'] !== null
                        ? (string)$row['SourceSetlistId'] : null;
                    return $decoded;
                }
            }
            return null;
        }

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
 *
 * The optional $ownerUserId / $sourceSetlistId persist the #1380 live-link
 * (the share resolves the owner's CURRENT setlist at read time); $createdBy
 * fills the long-unused CreatedBy audit column. All three are written only
 * when the live-link columns exist — a pre-migration install falls open to the
 * legacy 2-column INSERT so sharing never breaks on a partly-migrated deploy.
 */
function sharedSetlistInsert(
    string $shareId,
    array $data,
    ?int $ownerUserId = null,
    ?string $sourceSetlistId = null,
    ?int $createdBy = null
): ?bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    /* MySQL atomic insert — duplicate-key collision is the signal so
       the caller can pick another ID. PDO surfaced this as SQLSTATE
       23000 (string); mysqli_sql_exception::getCode() returns the
       MySQL error number 1062 (ER_DUP_ENTRY) for the same condition. */
    try {
        $db = getDbMysqli();
        if (_sharedSetlistLiveLinkColumns()) {
            $stmt = $db->prepare(
                'INSERT INTO tblSharedSetlists (ShareId, Data, OwnerUserId, SourceSetlistId, CreatedBy)
                 VALUES (?, ?, ?, ?, ?)'
            );
            /* Types: ShareId=s, Data=s, OwnerUserId=i, SourceSetlistId=s, CreatedBy=i.
               mysqli sends SQL NULL when a bound PHP null is paired with any type, so
               an anonymous/legacy share (all three null) writes clean NULLs. */
            $stmt->bind_param('ssisi', $shareId, $json, $ownerUserId, $sourceSetlistId, $createdBy);
        } else {
            $stmt = $db->prepare('INSERT INTO tblSharedSetlists (ShareId, Data) VALUES (?, ?)');
            $stmt->bind_param('ss', $shareId, $json);
        }
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
 *
 * The optional #1380 live-link args mirror sharedSetlistInsert(): they are
 * written only when the live-link columns exist (else the legacy 2-column path
 * runs). On the UPDATE branch the live-link columns are refreshed too, so
 * re-sharing a setlist that gained an owner since its first share upgrades it
 * from snapshot-only to live without a separate code path.
 */
function sharedSetlistUpdate(
    string $shareId,
    array $data,
    ?int $ownerUserId = null,
    ?string $sourceSetlistId = null,
    ?int $createdBy = null
): bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $db = getDbMysqli();

        if (_sharedSetlistLiveLinkColumns()) {
            $stmt = $db->prepare(
                'UPDATE tblSharedSetlists
                    SET Data = ?, OwnerUserId = ?, SourceSetlistId = ?, CreatedBy = ?, UpdatedAt = NOW()
                  WHERE ShareId = ?'
            );
            /* Types: Data=s, OwnerUserId=i, SourceSetlistId=s, CreatedBy=i, ShareId=s. */
            $stmt->bind_param('sisis', $json, $ownerUserId, $sourceSetlistId, $createdBy, $shareId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected > 0) return true;
            /* No row matched — promote into MySQL on first edit (with live link). */
            $stmt = $db->prepare(
                'INSERT INTO tblSharedSetlists (ShareId, Data, OwnerUserId, SourceSetlistId, CreatedBy)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('ssisi', $shareId, $json, $ownerUserId, $sourceSetlistId, $createdBy);
            $stmt->execute();
            $stmt->close();
            return true;
        }

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
 * XSS-safe song-id allow-list (#1380).
 *
 * The shared-setlist song ids are stored with NO charset validation at the
 * write boundary for setlists synced via user_setlists_sync (the editor /
 * other clients write tblUserSetlists.SongsJson directly), and the public
 * shared-page client renders each id verbatim into `data-song-id="…"` and
 * `href="/song/…"`. Its escapeHtml() (the textContent→innerHTML idiom) does
 * NOT escape a double-quote, so an id carrying a quote could break out of the
 * attribute (stored XSS). This helper is the single, shared id allow-list:
 *   - caps length (a long id is never real, and is a DoS / payload vector);
 *   - admits draft (`song-1a2b3c`) AND canonical (`<Abbr>-NNNN`) ids;
 *   - rejects every HTML metacharacter (quotes, brackets, spaces).
 * Returns the trimmed id when safe, or '' when it must be dropped. A
 * metachar-bearing token is never a real SongId, so dropping it is correct —
 * this is NOT the over-strict `\d+$` form #1380 removed.
 */
function sharedSetlistSafeSongId(string $id): string
{
    /* Code-point-safe length cap, then the SAFE charset match. */
    $id = function_exists('mb_substr') ? mb_substr(trim($id), 0, 100) : substr(trim($id), 0, 100);
    return preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1 ? $id : '';
}

/**
 * Resolve a shared setlist to its PUBLIC WIRE shape — the single source of the
 * live-vs-snapshot projection (#1380).
 *
 * Before this helper, three call sites each computed name/songs independently:
 *   - api.php setlist_get   (live-aware: read the owner's CURRENT setlist);
 *   - og-image.php          (snapshot-only: stale name/count on a live share);
 *   - index.php OG/meta      (snapshot-only: same staleness).
 * Extracting the projection here (modularity rule) makes the social-card image
 * and the page meta tags LIVE too, and de-dupes the id allow-list (FIX 1).
 *
 * Returns:
 *   ['name' => string, 'songs' => string[] (id strings), 'arrangements' => array,
 *    'live' => bool, 'unavailable' => bool]
 * or null when the share id is not found at all (caller → 404).
 *
 * PUBLIC-SAFE: the return NEVER carries _ownerUserId / _sourceSetlistId / owner /
 * CreatedBy / any email — those private keys are read internally here and dropped.
 *
 * `unavailable` is true ONLY when the share is a LIVE link whose underlying
 * tblUserSetlists row the owner has since deleted (the honest "no longer shared"
 * state → 410 for the JSON API; an empty card for OG/meta). A live-read FAILURE
 * (DB blip) is NOT unavailable — it degrades to the frozen snapshot (fail-safe).
 */
function sharedSetlistResolveWire(\mysqli $db, string $shareId): ?array
{
    $data = sharedSetlistGet($shareId);
    if ($data === null) {
        return null;
    }

    /* Private keys returned by sharedSetlistGet() when the live-link columns
       exist — read internally, never echoed. */
    $ownerUserId     = $data['_ownerUserId'] ?? null;
    $sourceSetlistId = $data['_sourceSetlistId'] ?? null;

    /* Default to the frozen SNAPSHOT, with the FIX-1 id guard applied so even a
       legacy snapshot (written before the guard existed) can't carry an
       attribute-breaking id into a renderer. */
    $snapshotSongs = [];
    foreach (($data['songs'] ?? []) as $sid) {
        $safe = sharedSetlistSafeSongId((string)$sid);
        if ($safe !== '') { $snapshotSongs[] = $safe; }
    }
    $snapshotArrangements = [];
    foreach (($data['arrangements'] ?? []) as $sid => $arr) {
        $safe = sharedSetlistSafeSongId((string)$sid);
        if ($safe !== '' && is_array($arr)) { $snapshotArrangements[$safe] = $arr; }
    }

    $result = [
        'name'         => (string)($data['name'] ?? 'Untitled'),
        'songs'        => $snapshotSongs,
        'arrangements' => $snapshotArrangements,
        'live'         => false,
        'unavailable'  => false,
    ];

    /* No live link → snapshot is the answer. */
    if ($ownerUserId === null || $sourceSetlistId === null || $sourceSetlistId === '') {
        return $result;
    }

    /* LIVE resolution — serve the owner's CURRENT setlist from tblUserSetlists so
       their later edits reach link-holders. A missing row = revoked (unavailable);
       any other failure degrades to the snapshot above (fail-safe). */
    try {
        $liveStmt = $db->prepare(
            'SELECT Name, SongsJson FROM tblUserSetlists WHERE UserId = ? AND SetlistId = ? LIMIT 1'
        );
        $ownerUserIdInt    = (int)$ownerUserId;
        $sourceSetlistIdSt = (string)$sourceSetlistId;
        $liveStmt->bind_param('is', $ownerUserIdInt, $sourceSetlistIdSt);
        $liveStmt->execute();
        $liveRow = $liveStmt->get_result()->fetch_assoc();
        $liveStmt->close();

        if ($liveRow === null) {
            /* Owner deleted the underlying setlist — the live link points at a
               row that no longer exists. Honest "no longer shared". */
            $result['unavailable'] = true;
            return $result;
        }

        /* Project the SongsJson OBJECT array
           ([{id,title,songbook,number,arrangement?}, …]) to the wire shape:
           an array of SongId STRINGS plus an arrangements map. Apply the FIX-1
           id guard to each id (user_setlists_sync stores ids with no charset
           validation, so this is the defense-in-depth boundary). */
        $liveDecoded = json_decode((string)$liveRow['SongsJson'], true);
        $liveSongs        = [];
        $liveArrangements = [];
        if (is_array($liveDecoded)) {
            foreach ($liveDecoded as $s) {
                if (!is_array($s) || !isset($s['id'])) { continue; }
                $sid = sharedSetlistSafeSongId((string)$s['id']);
                if ($sid === '') { continue; }
                $liveSongs[] = $sid;
                if (isset($s['arrangement']) && is_array($s['arrangement'])) {
                    $arr = array_values(array_filter(
                        $s['arrangement'],
                        static fn($v) => is_int($v) && $v >= 0
                    ));
                    if (count($arr) > 0) { $liveArrangements[$sid] = $arr; }
                }
            }
        }

        $result['name']         = (string)$liveRow['Name'];
        $result['songs']        = $liveSongs;
        $result['arrangements'] = $liveArrangements;
        $result['live']         = true;
        return $result;
    } catch (\Throwable $_e) {
        /* Live read failed — degrade to the frozen snapshot rather than erroring
           (fail-safe). Logged so a systemic outage leaves a trail. */
        error_log('[sharedSetlistResolveWire] live resolution failed: ' . $_e->getMessage());
        return $result;
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
