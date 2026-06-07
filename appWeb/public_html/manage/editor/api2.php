<?php

declare(strict_types=1);

/**
 * iHymns — Song Editor v2 API (#1200)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * A clean, purpose-built, GRANULAR editor backend — every edit is its own
 * atomic, CSRF-guarded, audited MySQL write. This replaces the legacy
 * whole-song `save_song` model (and the auto/manual save RACE that corrupted
 * songs, #1178). It is deliberately NOT bolted onto the 7,800-line legacy
 * `api.php`; the owner approved a clean editor API, with the broader public/PWA
 * API + OpenAPI docs to be redone next (tracked follow-up).
 *
 * Wire format: `action` via query string (GET reads) or JSON body (POST writes).
 * Every POST requires the `X-CSRF-Token` header (the legacy editor API had NO
 * CSRF — this closes that gap). All values are bound (`bind_param`), every
 * mutation writes a `logActivity` row + a coalesced `tblSongRevisions` snapshot,
 * and every response is `{ ok, ... }` with the TRUE result (never a false
 * success — the lesson from the client-only `deleteSong()` that lied).
 *
 * Actions:
 *   GET  load_song?id=<SongId>                              -> { ok, song }
 *   POST create_song            { songbook, title? }        -> { ok, songId }
 *   POST delete_song            { songId }                  -> { ok, deleted }
 *   POST metadata_field_update  { songId, field, value }    -> { ok }
 *   POST component_upsert       { songId, component:{id?,type,number,sortOrder,lines[],chords?,language?} } -> { ok, componentId }
 *   POST component_delete       { songId, componentId }     -> { ok }
 *   POST component_reorder      { songId, order:[id,...] }  -> { ok }
 *   POST credit_upsert          { songId, role, credit:{id?,name} } -> { ok, creditId }
 *   POST credit_delete          { songId, role, creditId }  -> { ok }
 *
 * @requires PHP 8.1+, mysqli. Auth: editor+.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Emit a JSON response + exit. */
function ed2_respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------- Guard ---- */
if (!isAuthenticated()) {
    ed2_respond(['ok' => false, 'error' => 'Authentication required.'], 401);
}
$currentUser = getCurrentUser();
if (!$currentUser || !hasRole((string)($currentUser['role'] ?? ''), 'editor')) {
    ed2_respond(['ok' => false, 'error' => 'Editor access required.'], 403);
}
$ed2UserId  = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
$ed2IsAdmin = hasRole((string)($currentUser['role'] ?? ''), 'admin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_REQUEST['action'] ?? '');
$body   = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) { $body = []; }
    /* CSRF on every state-changing request. */
    if (!validateCsrf((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        ed2_respond(['ok' => false, 'error' => 'Invalid or missing CSRF token.'], 403);
    }
}

/* ------------------------------------------------------------- Constants --- */

/** Credit role -> child table. The only valid roles; anything else 400s. */
const ED2_CREDIT_TABLES = [
    'writers'     => 'tblSongWriters',
    'composers'   => 'tblSongComposers',
    'arrangers'   => 'tblSongArrangers',
    'adaptors'    => 'tblSongAdaptors',
    'translators' => 'tblSongTranslators',
    'artists'     => 'tblSongArtists',
];

/** Editable scalar field -> [column, bind-type]. Allow-list (CLAUDE.md #5): the
 *  column name is the only non-bound SQL fragment and comes from this constant,
 *  never from input. */
const ED2_META_FIELDS = [
    'title'              => ['Title', 's'],
    'number'             => ['Number', 'i'],
    'songbook'           => ['SongbookAbbr', 's'],
    'language'           => ['Language', 's'],
    'copyright'          => ['Copyright', 's'],
    'ccli'               => ['Ccli', 's'],
    'iswc'               => ['Iswc', 's'],
    'tuneName'           => ['TuneName', 's'],
    'originCity'         => ['OriginCity', 's'],
    'verified'           => ['Verified', 'i'],
    'lyricsPublicDomain' => ['LyricsPublicDomain', 'i'],
    'musicPublicDomain'  => ['MusicPublicDomain', 'i'],
    'hasAudio'           => ['HasAudio', 'i'],
    'hasSheetMusic'      => ['HasSheetMusic', 'i'],
];

/* --------------------------------------------------------------- Helpers --- */

/** App-maintained NormalizedTitle fold (best-effort; '' if the normalizer
 *  isn't loadable, matching the column's NOT NULL DEFAULT ''). */
function ed2_normalizeTitle(string $t): string {
    static $loaded = null;
    if ($loaded === null) {
        $p = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'title_normalize.php';
        $loaded = is_file($p);
        if ($loaded) { require_once $p; }
    }
    return ($loaded && function_exists('ihymns_normalize_title')) ? ihymns_normalize_title($t) : '';
}

/** True if the SongId exists. */
function ed2_songExists(\mysqli $db, string $songId): bool {
    $s = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
    $s->bind_param('s', $songId);
    $s->execute();
    $exists = (bool)$s->get_result()->fetch_row();
    $s->close();
    return $exists;
}

/**
 * Allocate a server-owned canonical SongId for a NEW numberless song:
 * `<ABBR>-<NNNNNN>` (6-digit per-songbook sequence; fits VARCHAR(20); same
 * grammar as official `<ABBR>-<NNNN>`). Call INSIDE a transaction — the source
 * of truth is the live data (no counter table), locked FOR UPDATE.
 */
function ed2_allocateSongId(\mysqli $db, string $abbr): string {
    $abbr = strtoupper(trim($abbr));
    /* Allow-list: abbreviation is [A-Z0-9]{1,10}; this validates the only value
       that ends up in the REGEXP fragment below. */
    if (!preg_match('/^[A-Z0-9]{1,10}$/', $abbr)) {
        throw new \RuntimeException('Invalid songbook abbreviation for id allocation.');
    }
    $prefix    = $abbr . '-';
    $regex     = '^' . $abbr . '-[0-9]+$';
    $tailStart = strlen($prefix) + 1;   // 1-based SUBSTRING position

    $stmt = $db->prepare(
        'SELECT SongId FROM tblSongs
          WHERE SongbookAbbr = ? AND SongId REGEXP ?
          ORDER BY CAST(SUBSTRING(SongId, ?) AS UNSIGNED) DESC
          LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('ssi', $abbr, $regex, $tailStart);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = ($row && isset($row['SongId'])) ? ((int)substr((string)$row['SongId'], strlen($prefix)) + 1) : 1;

    /* Skip any already-taken value (a numbered song could occupy the range). */
    for ($i = 0; $i < 8; $i++) {
        $candidate = sprintf('%s-%06d', $abbr, $next);
        $chk = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
        $chk->bind_param('s', $candidate);
        $chk->execute();
        $taken = (bool)$chk->get_result()->fetch_row();
        $chk->close();
        if (!$taken) { return $candidate; }
        $next++;
    }
    throw new \RuntimeException('Could not allocate a unique SongId.');
}

/** Recompute tblSongs.LyricsText (the FULLTEXT mirror) from the components,
 *  in display order. Called after any component mutation. */
function ed2_rebuildLyricsText(\mysqli $db, string $songId): void {
    $s = $db->prepare('SELECT LinesJson FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder ASC, Id ASC');
    $s->bind_param('s', $songId);
    $s->execute();
    $res = $s->get_result();
    $lines = [];
    while ($r = $res->fetch_assoc()) {
        $arr = json_decode((string)$r['LinesJson'], true);
        if (is_array($arr)) { foreach ($arr as $ln) { $lines[] = (string)$ln; } }
    }
    $s->close();
    $text = implode("\n", $lines);
    $u = $db->prepare('UPDATE tblSongs SET LyricsText = ? WHERE SongId = ?');
    $u->bind_param('ss', $text, $songId);
    $u->execute();
    $u->close();
}

/**
 * Write a COALESCED revision snapshot (#400): one row per song per ~15s burst,
 * not one per keystroke. If the most recent revision for this song is younger
 * than the window, skip (the burst is already represented); otherwise snapshot
 * the current tblSongs row. Best-effort — a revision failure never breaks the
 * edit. The precise per-edit trail lives in tblActivityLog via logActivity().
 */
function ed2_touchRevision(\mysqli $db, string $songId, ?int $userId, string $actionTag): void {
    try {
        $chk = $db->prepare(
            'SELECT 1 FROM tblSongRevisions
              WHERE SongId = ? AND CreatedAt > (NOW() - INTERVAL 15 SECOND)
              LIMIT 1'
        );
        $chk->bind_param('s', $songId);
        $chk->execute();
        $recent = (bool)$chk->get_result()->fetch_row();
        $chk->close();
        if ($recent) { return; }

        $snap = $db->prepare('SELECT * FROM tblSongs WHERE SongId = ? LIMIT 1');
        $snap->bind_param('s', $songId);
        $snap->execute();
        $rowData = $snap->get_result()->fetch_assoc();
        $snap->close();
        $newData = $rowData ? json_encode($rowData, JSON_UNESCAPED_UNICODE) : null;

        $rev = $db->prepare(
            'INSERT INTO tblSongRevisions (SongId, UserId, Action, PreviousData, NewData, Status)
             VALUES (?, ?, ?, NULL, ?, "approved")'
        );
        $rev->bind_param('siss', $songId, $userId, $actionTag, $newData);
        $rev->execute();
        $rev->close();
    } catch (\Throwable $_e) {
        /* swallow — auditing must not break the edit */
    }
}

/* -------------------------------------------------------------- Dispatch --- */

$db = getDbMysqli();

try {
    switch ($action) {

    /* ---- load_song (GET) — full editable record, DB-direct ---- */
    case 'load_song': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
        $sd   = new SongData();
        $song = $sd->getSongById($songId);
        if (!$song) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        ed2_respond(['ok' => true, 'song' => $song]);
        break;
    }

    /* ---- create_song (POST) — server-owned canonical id ---- */
    case 'create_song': {
        $abbr  = strtoupper(trim((string)($body['songbook'] ?? '')));
        if ($abbr === '') { $abbr = 'MISC'; }
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') { $title = 'New Song'; }
        $title = mb_substr($title, 0, 500);

        $db->begin_transaction();
        try {
            $sb = $db->prepare('SELECT Abbreviation FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1');
            $sb->bind_param('s', $abbr);
            $sb->execute();
            $sbOk = (bool)$sb->get_result()->fetch_row();
            $sb->close();
            if (!$sbOk) { $db->rollback(); ed2_respond(['ok' => false, 'error' => "Songbook '{$abbr}' not found."], 400); }

            $songId = ed2_allocateSongId($db, $abbr);
            $norm   = ed2_normalizeTitle($title);
            $ins = $db->prepare('INSERT INTO tblSongs (SongId, Title, NormalizedTitle, SongbookAbbr) VALUES (?, ?, ?, ?)');
            $ins->bind_param('ssss', $songId, $title, $norm, $abbr);
            $ins->execute();
            $ins->close();
            ed2_touchRevision($db, $songId, $ed2UserId, 'create');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.create', 'song', $songId, ['title' => $title, 'songbook' => $abbr]);
        ed2_respond(['ok' => true, 'songId' => $songId, 'title' => $title, 'songbook' => $abbr]);
        break;
    }

    /* ---- delete_song (POST) — single cascade delete (verified: all 40 inbound
           FKs CASCADE/SET NULL) ---- */
    case 'delete_song': {
        $songId = trim((string)($body['songId'] ?? $body['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        $db->begin_transaction();
        try {
            $prev = $db->prepare('SELECT Title, SongbookAbbr FROM tblSongs WHERE SongId = ? LIMIT 1');
            $prev->bind_param('s', $songId);
            $prev->execute();
            $prevRow = $prev->get_result()->fetch_assoc();
            $prev->close();
            if ($prevRow === null) { $db->rollback(); ed2_respond(['ok' => false, 'error' => 'Song not found.', 'deleted' => 0], 404); }

            $del = $db->prepare('DELETE FROM tblSongs WHERE SongId = ?');
            $del->bind_param('s', $songId);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();
            $db->commit();
            logActivity('song.delete', 'song', $songId, [
                'title'    => (string)($prevRow['Title'] ?? ''),
                'songbook' => (string)($prevRow['SongbookAbbr'] ?? ''),
            ]);
            ed2_respond(['ok' => true, 'deleted' => (int)$deleted, 'songId' => $songId]);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        break;
    }

    /* ---- metadata_field_update (POST) — one scalar tblSongs field ---- */
    case 'metadata_field_update': {
        $songId = trim((string)($body['songId'] ?? ''));
        $field  = (string)($body['field'] ?? '');
        if ($songId === '' || !isset(ED2_META_FIELDS[$field])) {
            ed2_respond(['ok' => false, 'error' => 'songId + a known field are required.'], 400);
        }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        [$column, $type] = ED2_META_FIELDS[$field];
        $raw = $body['value'] ?? null;

        /* Coerce per the allow-listed type; numberless/empty → NULL where the
           column allows it (Number/TuneName/Iswc/OriginCity are nullable). */
        if ($type === 'i') {
            if ($field === 'number') {
                $value = ($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw;
            } else {
                $value = (int)((bool)$raw);   // flags
            }
        } else {
            $value = $raw === null ? '' : trim((string)$raw);
            if (in_array($column, ['TuneName', 'Iswc', 'OriginCity'], true) && $value === '') { $value = null; }
        }

        $db->begin_transaction();
        try {
            /* Column name comes from the ED2_META_FIELDS constant only. */
            $u = $db->prepare("UPDATE tblSongs SET `{$column}` = ? WHERE SongId = ?");
            if ($value === null) {
                $nullParam = null;
                $u->bind_param('ss', $nullParam, $songId);   // mysqli sends NULL for a null var
            } else {
                $u->bind_param($type . 's', $value, $songId);
            }
            $u->execute();
            $u->close();
            /* Title drives NormalizedTitle too. */
            if ($field === 'title') {
                $norm = ed2_normalizeTitle((string)$value);
                $un = $db->prepare('UPDATE tblSongs SET NormalizedTitle = ? WHERE SongId = ?');
                $un->bind_param('ss', $norm, $songId);
                $un->execute();
                $un->close();
            }
            ed2_touchRevision($db, $songId, $ed2UserId, 'metadata');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.metadata', 'song', $songId, ['field' => $field]);
        ed2_respond(['ok' => true, 'field' => $field]);
        break;
    }

    /* ---- component_upsert (POST) ---- */
    case 'component_upsert': {
        $songId = trim((string)($body['songId'] ?? ''));
        $comp   = is_array($body['component'] ?? null) ? $body['component'] : [];
        if ($songId === '' || !$comp) { ed2_respond(['ok' => false, 'error' => 'songId + component are required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        $compId    = isset($comp['id']) ? (int)$comp['id'] : 0;
        $type      = mb_substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse';
        $number    = max(0, (int)($comp['number'] ?? 0));
        $sortOrder = isset($comp['sortOrder']) ? (int)$comp['sortOrder'] : $number;
        $lines     = is_array($comp['lines'] ?? null) ? array_values(array_map('strval', $comp['lines'])) : [];
        $linesJson = json_encode($lines, JSON_UNESCAPED_UNICODE);
        $chordsJson = (isset($comp['chords']) && is_array($comp['chords'])) ? json_encode($comp['chords'], JSON_UNESCAPED_UNICODE) : null;
        $language  = isset($comp['language']) && trim((string)$comp['language']) !== '' ? trim((string)$comp['language']) : null;

        $db->begin_transaction();
        try {
            if ($compId > 0) {
                $u = $db->prepare(
                    'UPDATE tblSongComponents
                        SET Type = ?, Number = ?, SortOrder = ?, LinesJson = ?, ChordsJson = ?, Language = ?
                      WHERE Id = ? AND SongId = ?'
                );
                $u->bind_param('siisssis', $type, $number, $sortOrder, $linesJson, $chordsJson, $language, $compId, $songId);
                $u->execute();
                $u->close();
            } else {
                $i = $db->prepare(
                    'INSERT INTO tblSongComponents (SongId, Type, Number, SortOrder, LinesJson, ChordsJson, Language)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $i->bind_param('ssiisss', $songId, $type, $number, $sortOrder, $linesJson, $chordsJson, $language);
                $i->execute();
                $compId = (int)$db->insert_id;
                $i->close();
            }
            ed2_rebuildLyricsText($db, $songId);
            ed2_touchRevision($db, $songId, $ed2UserId, 'component');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.component', 'song', $songId, ['componentId' => $compId, 'type' => $type]);
        ed2_respond(['ok' => true, 'componentId' => $compId]);
        break;
    }

    /* ---- component_delete (POST) ---- */
    case 'component_delete': {
        $songId = trim((string)($body['songId'] ?? ''));
        $compId = (int)($body['componentId'] ?? 0);
        if ($songId === '' || $compId <= 0) { ed2_respond(['ok' => false, 'error' => 'songId + componentId are required.'], 400); }
        $db->begin_transaction();
        try {
            $d = $db->prepare('DELETE FROM tblSongComponents WHERE Id = ? AND SongId = ?');
            $d->bind_param('is', $compId, $songId);
            $d->execute();
            $deleted = $d->affected_rows;
            $d->close();
            ed2_rebuildLyricsText($db, $songId);
            ed2_touchRevision($db, $songId, $ed2UserId, 'component');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.component.delete', 'song', $songId, ['componentId' => $compId]);
        ed2_respond(['ok' => true, 'deleted' => (int)$deleted]);
        break;
    }

    /* ---- component_reorder (POST) — { order: [componentId, ...] } ---- */
    case 'component_reorder': {
        $songId = trim((string)($body['songId'] ?? ''));
        $order  = is_array($body['order'] ?? null) ? array_values(array_map('intval', $body['order'])) : [];
        if ($songId === '' || !$order) { ed2_respond(['ok' => false, 'error' => 'songId + order[] are required.'], 400); }
        $db->begin_transaction();
        try {
            $u = $db->prepare('UPDATE tblSongComponents SET SortOrder = ? WHERE Id = ? AND SongId = ?');
            foreach ($order as $pos => $cid) {
                $cid = (int)$cid;
                if ($cid <= 0) { continue; }
                $u->bind_param('iis', $pos, $cid, $songId);
                $u->execute();
            }
            $u->close();
            ed2_rebuildLyricsText($db, $songId);
            ed2_touchRevision($db, $songId, $ed2UserId, 'reorder');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.component.reorder', 'song', $songId, ['count' => count($order)]);
        ed2_respond(['ok' => true, 'count' => count($order)]);
        break;
    }

    /* ---- credit_upsert (POST) — { role, credit:{id?, name} } ---- */
    case 'credit_upsert': {
        $songId = trim((string)($body['songId'] ?? ''));
        $role   = (string)($body['role'] ?? '');
        $credit = is_array($body['credit'] ?? null) ? $body['credit'] : [];
        if ($songId === '' || !isset(ED2_CREDIT_TABLES[$role]) || !$credit) {
            ed2_respond(['ok' => false, 'error' => 'songId + a known role + credit are required.'], 400);
        }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        $table   = ED2_CREDIT_TABLES[$role];     // from the allow-list constant only
        $creditId = isset($credit['id']) ? (int)$credit['id'] : 0;
        $name    = mb_substr(trim((string)($credit['name'] ?? '')), 0, 255);
        if ($name === '') { ed2_respond(['ok' => false, 'error' => 'credit name is required.'], 400); }

        $db->begin_transaction();
        try {
            if ($creditId > 0) {
                $u = $db->prepare("UPDATE `{$table}` SET Name = ? WHERE Id = ? AND SongId = ?");
                $u->bind_param('sis', $name, $creditId, $songId);
                $u->execute();
                $u->close();
            } else {
                $i = $db->prepare("INSERT INTO `{$table}` (SongId, Name) VALUES (?, ?)");
                $i->bind_param('ss', $songId, $name);
                $i->execute();
                $creditId = (int)$db->insert_id;
                $i->close();
            }
            ed2_touchRevision($db, $songId, $ed2UserId, 'credit');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.credit', 'song', $songId, ['role' => $role, 'creditId' => $creditId]);
        ed2_respond(['ok' => true, 'creditId' => $creditId]);
        break;
    }

    /* ---- credit_delete (POST) — { role, creditId } ---- */
    case 'credit_delete': {
        $songId  = trim((string)($body['songId'] ?? ''));
        $role    = (string)($body['role'] ?? '');
        $creditId = (int)($body['creditId'] ?? 0);
        if ($songId === '' || !isset(ED2_CREDIT_TABLES[$role]) || $creditId <= 0) {
            ed2_respond(['ok' => false, 'error' => 'songId + a known role + creditId are required.'], 400);
        }
        $table = ED2_CREDIT_TABLES[$role];
        $db->begin_transaction();
        try {
            $d = $db->prepare("DELETE FROM `{$table}` WHERE Id = ? AND SongId = ?");
            $d->bind_param('is', $creditId, $songId);
            $d->execute();
            $deleted = $d->affected_rows;
            $d->close();
            ed2_touchRevision($db, $songId, $ed2UserId, 'credit');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.credit.delete', 'song', $songId, ['role' => $role, 'creditId' => $creditId]);
        ed2_respond(['ok' => true, 'deleted' => (int)$deleted]);
        break;
    }

    default:
        ed2_respond(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (\Throwable $e) {
    error_log('[editor-v2-api ' . $action . '] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    ed2_respond([
        'ok'           => false,
        'error'        => 'Server error.',
        'error_detail' => $ed2IsAdmin ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : null,
    ], 500);
}
