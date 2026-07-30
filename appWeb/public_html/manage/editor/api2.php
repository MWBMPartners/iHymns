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
 * Every POST requires the `X-Requested-With: XMLHttpRequest` header — the same
 * same-origin AJAX CSRF defence as the public api.php (#293), chosen over a
 * per-form session token because the embedded token went stale / absent under
 * the cross-subdomain token-adopted admin session and 403'd every POST (#1307).
 * All values are bound (`bind_param`), every
 * mutation writes a `logActivity` row + a coalesced `tblSongRevisions` snapshot,
 * and every response is `{ ok, ... }` with the TRUE result (never a false
 * success — the lesson from the client-only `deleteSong()` that lied).
 *
 * Actions:
 *   GET  load_index                                         -> { ok, songs }  (slim sidebar index)
 *   POST bulk_verify            { songIds:[...], verified? }  -> { ok, count, verified }
 *   POST bulk_tag_attach        { songIds:[...], name }       -> { ok, tag, attached, count }
 *   GET  load_song?id=<SongId>                              -> { ok, song }
 *   POST create_song            { songbook, title? }        -> { ok, songId }
 *   POST delete_song            { songId }                  -> { ok, deleted }
 *   POST metadata_field_update  { songId, field, value }    -> { ok }
 *   POST component_upsert       { songId, component:{id?,type,number,sortOrder,lines[],chords?,language?} } -> { ok, componentId }
 *   POST component_delete       { songId, componentId }     -> { ok }
 *   POST component_reorder      { songId, order:[id,...] }  -> { ok }
 *   POST components_replace     { songId, components:[...], mode? } -> { ok, count, components }
 *   POST credit_upsert          { songId, role, credit:{id?,name} } -> { ok, creditId }
 *   POST credit_delete          { songId, role, creditId }  -> { ok }
 *   GET  credit_search?q=&kind=&limit=                       -> { ok, suggestions }
 *   GET  user_search?q=&limit=                                -> { ok, suggestions }  (#1629 — Content Restrictions picker, NOT an editor feature; ported off v1)
 *   GET  org_search?q=&limit=                                 -> { ok, suggestions }  (#1629 — same picker, "organisation" source)
 *   GET  tag_list?id=<SongId>                                -> { ok, tags }
 *   GET  tag_search?q=&limit=                                -> { ok, suggestions }
 *   POST tag_attach             { songId, name }             -> { ok, tag, attached }
 *   POST tag_detach             { songId, tagId }            -> { ok, removed }
 *   POST link_save_all          { songId, links:[{typeId,url,note?,verified?}] } -> { ok, count, links }
 *   GET  media_list?id=<SongId>                              -> { ok, media }
 *   POST media_upload  (MULTIPART: songId, kind, annotation?, file) -> { ok, media }
 *   POST media_update           { mediaId, annotation }      -> { ok, mediaId }
 *   POST media_delete           { mediaId }                  -> { ok, deleted, songId }
 *   POST media_reorder          { songId, kind, ids:[...] }  -> { ok, reordered }
 *   POST import_file   (MULTIPART: file, format=auto|videopsalm|openlp|pro6|proclaim|freeshow|pptx|easyworship, dedupeMode?) -> { ok, songs_created, ... }
 *   POST import_zip    (MULTIPART: file=.zip, dedupeMode?)    -> { ok, async, job_id, poll_url } (async) | { ok, songs_created, ... } (sync fallback / EasyWorship)
 *   GET  import_zip_status?job_id=<n>                         -> { ok, job }
 *   GET  import_zip_skipped_csv?job_id=<n>                    -> text/csv
 *   GET  revision_list?songId=<id>&limit=                     -> { ok, revisions }
 *   POST revision_restore       { revisionId }                -> { ok, songId }
 *
 * tblSongRevisions NewData is the FULL hydrated record (ed2_buildSongSnapshot) —
 * the same shape load_song returns (minus media) — so a revision restores in full.
 *
 * load_song additionally returns `tags` (registry-backed), `links` (the
 * {typeId,url,note,verified,sortOrder} shape the shared external-links-editor
 * consumes), and `media` (file metadata, never bytes), so the Tags / Links /
 * Media tabs hydrate from the one load.
 *
 * @requires PHP 8.4+ — project targets PHP 8.5; 8.4 supported for backward-compat
 *           (no implicit-nullable params, no 8.x-removed funcs). mysqli. Auth: editor+.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
/* The ONE in-app notification writer (#1638) — notifyUser(). Replaces this
   file's hand-rolled INSERT INTO tblNotifications, which was one of three
   drifting copies. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'notifications.php';
require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'csv_safe.php'; // ihymns_fputcsv() — CSV formula-injection neutraliser
/* Shared external-link helpers (#833/#845) — the SAME loader + save +
   reconcile the songbook / work surfaces use, so the song editor never
   forks the external-links code. Provides loadExternalLinkTypesFor(),
   loadExternalLinksForRow(), saveExternalLinksForRow(). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
/* Song media storage layer (#853) — kind→backend routing, MIME-sniff
   validation, FS/DB staging, the same class the streaming route uses. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongMediaStorage.php';
/* Shared song importers (#1200 Phase 4b) — the SAME bulk-import parsers +
   universal saver the legacy api.php uses (extracted to a shared include so v2
   reuses, never forks, them). Provides _bulkImport_process*() + _bulkImport_dedupeMode(). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_importers.php';
/* #1235 P1b — the shared tblLyricLines projector (transitional dual-write). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';
/* #1235 P3 / #1088 — shared per-line enrichment (translations / annotations)
   write+read layer; the SAME contract the future native API (#1201) reuses. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'line_enrichment.php';

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
    /* CSRF defence — aligned with the public api.php policy (#293), NOT a
       per-form session token (#1307).
       WHY THE CHANGE: the previous gate validated $_SESSION['csrf_token']
       against the X-CSRF-Token header. That session token is baked into the
       editor page HTML (window.IHYMNS_EDITOR_CSRF) at load time, so it goes
       stale the moment the PHP session's token rotates (re-login / session
       regeneration / GC) under a long-lived editor tab — or is simply absent
       when the admin is authed via the adopted cross-subdomain `ihymns_auth`
       token rather than a persistent /manage/ PHP session. Either case made
       hash_equals() fail and 403'd EVERY api2 POST (delete_song, the line
       enrichment upserts, …), e.g. "Here to Stay" #1289.
       THE DEFENCE STACK (identical to api.php, which is why save_song over
       there always worked): (1) this endpoint already requires isAuthenticated()
       + the editor role above; the auth cookies are SameSite (manage session =
       Strict, ihymns_auth = Lax) so a cross-site POST carries no identity → 401.
       (2) X-Requested-With: XMLHttpRequest is a forbidden header name a cross-
       origin <form>/navigation cannot set without a CORS preflight we never
       honour — so only same-origin `fetch()` (editor.js ed2EnrichApi, which
       already sends it) reaches here. That eliminates the classic CSRF surface
       without depending on a fragile embedded token. */
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        ed2_respond(['ok' => false, 'error' => 'Cross-site POST blocked: missing or invalid X-Requested-With header.'], 403);
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
    'originCityId'       => ['OriginCityId', 'i'],   // FK to tblPlaces (nullable int, NOT a flag)
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

/** Canonicalise a tag name — uses the SAME normalisation rules as the legacy
 *  bulk_tag normaliser (#762): trim, collapse internal whitespace, Title-Case,
 *  cap at 50 (the tblSongTags.Name VARCHAR(50) length). Identical rules mean
 *  'worship' / 'WORSHIP' / 'Worship' all resolve to the SAME canonical row, so a
 *  tag added through the v2 API never double-stores against one added through the
 *  legacy path. Returns '' for empty/whitespace input (the legacy closure
 *  returns null + filters it; tag_attach rejects '' explicitly — same effect). */
function ed2_normalizeTag(string $name): string {
    $trimmed = preg_replace('/\s+/u', ' ', trim($name));
    if ($trimmed === null || $trimmed === '') { return ''; }
    return mb_substr(mb_convert_case($trimmed, MB_CASE_TITLE_SIMPLE, 'UTF-8'), 0, 50);
}

/** URL-safe slug for a tag name — byte-identical to the legacy generator (#762):
 *  lowercase, every non-alphanumeric run → '-', trimmed of leading/trailing '-'. */
function ed2_tagSlug(string $name): string {
    return trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
}

/** True if the tblSongMedia table is present (gracefully degrades pre-migration,
 *  like the legacy _songMedia_tableExists). Memoised — cheap to call per request. */
function ed2_songMediaTableExists(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongMedia' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/** Shape a tblSongMedia row for the v2 client (camelCase, like the other slices).
 *  NEVER returns the file bytes — playback is via the gated /song-media/<id>
 *  stream route (#853), the only way to the content. */
function ed2_mediaRowShape(array $r): array {
    return [
        'id'             => (int)$r['Id'],
        'kind'           => (string)$r['Kind'],
        'fileName'       => (string)$r['FileName'],
        'mimeType'       => (string)$r['MimeType'],
        'sizeBytes'      => (int)$r['SizeBytes'],
        'annotation'     => (string)($r['Annotation'] ?? ''),
        'sortOrder'      => (int)$r['SortOrder'],
        'storageBackend' => (string)($r['StorageBackend'] ?? ''),
        'uploadedAt'     => (string)($r['UploadedAt'] ?? ''),
        'streamUrl'      => '/song-media/' . (int)$r['Id'],
    ];
}

/** Memoised probe: is tblBulkImportJobs present (async import job tracking, #676)? */
function ed2_bulkJobsTableExists(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblBulkImportJobs' LIMIT 1");
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/** Best-effort songbook maintenance after an import created songs (cache regen +
 *  stale-prefix fixup, #932). Guarded + lazy-required; never throws to the caller. */
function ed2_runSongbookMaintenance(\mysqli $db, string $context): void {
    $sm = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
    if (!is_file($sm)) { return; }
    try {
        require_once $sm;
        if (function_exists('songbookMaintenanceRun')) { songbookMaintenanceRun($db, $context); }
    } catch (\Throwable $_e) {
        /* best-effort — maintenance must not fail the import */
    }
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

/** Recompute tblSongs.LyricsText (the FULLTEXT mirror) from the song's lines, in
 *  display order. Called after any component mutation.
 *
 *  #1235 P4/C5 — sourced from the AUTHORITATIVE tblLyricLines (drop-safe), NOT
 *  tblSongComponents.LinesJson. The v2 mutators now write the lines themselves via
 *  the shared inverted write path (lyricLinesWriteComponents), so this is purely the
 *  text-mirror rebuild — there is NO lyricLinesProjectSong() reproject here anymore
 *  (it re-read LinesJson, which is not drop-safe). LinesJson survives only as the
 *  un-migrated-install fallback. */
function ed2_rebuildLyricsText(\mysqli $db, string $songId): void {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
    $lines = [];
    if (lyricLinesMirrorPresent($db)) {
        foreach (lyricLinesFetchPrimary($db, $songId) as $r) { $lines[] = (string)$r['text']; }
    } else {
        /* lines-json-fallback (#1235 P4): un-migrated install (no mirror) — concat the
           component LinesJson, which provably still exists pre-mirror. */
        $s = $db->prepare('SELECT LinesJson FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder ASC, Id ASC');
        $s->bind_param('s', $songId);
        $s->execute();
        $res = $s->get_result();
        while ($r = $res->fetch_assoc()) {
            $arr = json_decode((string)$r['LinesJson'], true);
            if (is_array($arr)) { foreach ($arr as $ln) { $lines[] = (string)$ln; } }
        }
        $s->close();
    }
    $text = implode("\n", $lines);
    $u = $db->prepare('UPDATE tblSongs SET LyricsText = ? WHERE SongId = ?');
    $u->bind_param('ss', $text, $songId);
    $u->execute();
    $u->close();
}

/**
 * Build a validated, null-padded LanguagesJson string (#1235 P3 / #1253) from a
 * per-line `languages` array (parallel to a component's lines), or null when no
 * line carries a real override. Each entry is validated as BCP 47 via the shared
 * validator (canonical _ietfBcp47Validate when loaded); empty/invalid → null
 * (that line inherits the component language). Returning null when there are no
 * overrides keeps the column NULL rather than storing an all-null array.
 *
 * @param mixed $languages  raw per-line languages (expected list aligned to lines)
 * @param int   $lineCount  the component's line count (the array is padded to it)
 */
function ed2_buildLanguagesJson(mixed $languages, int $lineCount): ?string {
    /* Thin wrapper over the shared builder (line_enrichment.php) so api.php +
       api2.php store per-line language identically. */
    return lineEnrichmentBuildLanguagesJson($languages, $lineCount);
}

/** Write a component's LanguagesJson (#1235 P3) — a no-op when the column is not
 *  migrated yet, so per-line language degrades gracefully to component language. */
function ed2_writeComponentLanguages(\mysqli $db, int $compId, string $songId, ?string $languagesJson): void {
    if ($compId <= 0 || !lyricLinesComponentsLangReady($db)) { return; }
    $u = $db->prepare('UPDATE tblSongComponents SET LanguagesJson = ? WHERE Id = ? AND SongId = ?');
    $u->bind_param('sis', $languagesJson, $compId, $songId);
    $u->execute();
    $u->close();
}

/**
 * #1235 P4/C5 — a song's components in the editor shape
 * ({id,type,number,sortOrder,lines,chords,language,languages}), drop-safely. The ONE
 * read for the v2 mutators' read-modify-write + ed2_buildSongSnapshot. Sourced from the
 * authoritative tblLyricLines (assembler) when the mirror exists; the legacy LinesJson
 * read is the un-migrated-install fallback only.
 *
 * @return list<array<string,mixed>>
 */
function ed2_currentComponents(\mysqli $db, string $songId): array {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
    if (lyricLinesMirrorPresent($db)) {
        return lyricLinesEditableComponents($db, $songId);
    }
    /* lines-json-fallback (#1235 P4): un-migrated install — LanguagesJson optional
       (hardcoded column name, never input — rule #5). */
    $out = [];
    $langCol = lyricLinesComponentsLangReady($db) ? 'LanguagesJson' : 'NULL AS LanguagesJson';
    $cs = $db->prepare("SELECT Id, Type, Number, SortOrder, LinesJson, ChordsJson, Language, {$langCol}
                          FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder ASC, Id ASC");
    $cs->bind_param('s', $songId);
    $cs->execute();
    $cr = $cs->get_result();
    while ($row = $cr->fetch_assoc()) {
        $out[] = [
            'id'        => (int)$row['Id'],
            'type'      => (string)$row['Type'],
            'number'    => (int)$row['Number'],
            'sortOrder' => (int)$row['SortOrder'],
            'lines'     => is_array($d = json_decode((string)$row['LinesJson'], true)) ? $d : [],
            'chords'    => $row['ChordsJson'] !== null ? json_decode((string)$row['ChordsJson'], true) : null,
            'language'  => $row['Language'],
            'languages' => $row['LanguagesJson'] !== null ? json_decode((string)$row['LanguagesJson'], true) : null,
        ];
    }
    $cs->close();
    return $out;
}

/**
 * #1235 P4/C5 — persist a FULL component set (editor shape, in display order) for a song
 * and rebuild the LyricsText mirror. The ONE write for every v2 component mutation. When
 * the tblLyricLines mirror exists, delegates to the shared inverted write path
 * (lyricLinesWriteComponents): lines become authoritative, the JSON columns are
 * shadow-written from the same payload while they exist, and component rows are upserted
 * Id-stably (drop-safe + revertable). The legacy DELETE+reinsert is the un-migrated-only
 * fallback. SortOrder is the array position; pass components already in display order.
 *
 * @param list<array<string,mixed>> $components
 */
function ed2_persistComponents(\mysqli $db, string $songId, array $components): void {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';
    $components = array_values(array_filter($components, 'is_array'));
    if (lyricLinesSyncReady($db)) {
        lyricLinesWriteComponents($db, $songId, $components);
    } else {
        /* lines-json-fallback (#1235 P4): un-migrated install (no mirror) — legacy
           DELETE + reinsert of the JSON columns, which provably still exist here. */
        $del = $db->prepare('DELETE FROM tblSongComponents WHERE SongId = ?');
        $del->bind_param('s', $songId);
        $del->execute();
        $del->close();
        $ins = $db->prepare('INSERT INTO tblSongComponents (SongId, Type, Number, SortOrder, LinesJson, ChordsJson, Language) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($components as $i => $comp) {
            $type      = mb_substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse';
            $number    = max(0, (int)($comp['number'] ?? 0));
            $sortOrder = (int)$i;
            $lines     = is_array($comp['lines'] ?? null) ? array_values(array_map('strval', $comp['lines'])) : [];
            $linesJson = json_encode($lines, JSON_UNESCAPED_UNICODE);
            $chordsJson = (isset($comp['chords']) && is_array($comp['chords'])) ? json_encode($comp['chords'], JSON_UNESCAPED_UNICODE) : null;
            $language  = (isset($comp['language']) && trim((string)$comp['language']) !== '') ? trim((string)$comp['language']) : null;
            $ins->bind_param('ssiisss', $songId, $type, $number, $sortOrder, $linesJson, $chordsJson, $language);
            $ins->execute();
            ed2_writeComponentLanguages($db, (int)$db->insert_id, $songId, ed2_buildLanguagesJson($comp['languages'] ?? null, count($lines)));
        }
        $ins->close();
    }
    ed2_rebuildLyricsText($db, $songId);
}

/**
 * Build the full editable song record — { song, components, credits, tags, links }
 * — in the SAME shapes load_song returns (minus media, which is a separate file
 * lifecycle). The single source for BOTH the load_song hydration and the
 * tblSongRevisions snapshot, so a restored snapshot re-hydrates the editor
 * identically. Returns null if the song is gone.
 */
function ed2_buildSongSnapshot(\mysqli $db, string $songId): ?array {
    $s = $db->prepare('SELECT * FROM tblSongs WHERE SongId = ? LIMIT 1');
    $s->bind_param('s', $songId);
    $s->execute();
    $song = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$song) { return null; }

    /* #1235 P4/C5 — components in the editor/snapshot shape from the AUTHORITATIVE
       tblLyricLines (drop-safe), so the revision NewData + v2 load are line-sourced. */
    $components = ed2_currentComponents($db, $songId);

    $credits = [];
    foreach (ED2_CREDIT_TABLES as $role => $table) {
        $credits[$role] = [];
        $q = $db->prepare("SELECT Id, Name FROM `{$table}` WHERE SongId = ? ORDER BY Id ASC");
        $q->bind_param('s', $songId);
        $q->execute();
        $qr = $q->get_result();
        while ($row = $qr->fetch_assoc()) { $credits[$role][] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name']]; }
        $q->close();
    }

    $tags = [];
    $tg = $db->prepare('SELECT t.Id, t.Name, t.Slug, t.Description
                          FROM tblSongTagMap m JOIN tblSongTags t ON t.Id = m.TagId
                         WHERE m.SongId = ? ORDER BY t.Name ASC');
    $tg->bind_param('s', $songId);
    $tg->execute();
    $tgr = $tg->get_result();
    while ($row = $tgr->fetch_assoc()) {
        $tags[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name'], 'slug' => (string)$row['Slug'], 'description' => (string)($row['Description'] ?? '')];
    }
    $tg->close();

    $links = loadExternalLinksForRow($db, 'tblSongExternalLinks', 'SongId', $songId);

    return ['song' => $song, 'components' => $components, 'credits' => $credits, 'tags' => $tags, 'links' => $links];
}

/**
 * Apply a full song snapshot (a revision's NewData) onto the live song —
 * scalars + components + credits + tags + links — replacing each. The caller
 * owns the transaction. Tolerates an old scalar-only snapshot (the bare tblSongs
 * row with no 'song' key) by restoring scalars only. Mirrors the relational
 * write paths so a restore lands identical to having re-typed everything.
 */
function ed2_applySongSnapshot(\mysqli $db, string $songId, array $snap): void {
    /* A v2 full snapshot has a 'song' key; an old scalar-only snapshot IS the row. */
    $songRow = is_array($snap['song'] ?? null) ? $snap['song'] : $snap;

    /* Scalars — only the allow-listed editable columns (same coercion as
       metadata_field_update). */
    foreach (ED2_META_FIELDS as $field => [$column, $type]) {
        if (!array_key_exists($column, $songRow)) { continue; }
        $raw = $songRow[$column];
        if ($type === 'i') {
            $value = ($field === 'number' || $field === 'originCityId')
                ? (($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw)
                : (int)((bool)$raw);
        } else {
            $value = $raw === null ? '' : trim((string)$raw);
            if (in_array($column, ['TuneName', 'Iswc', 'OriginCity'], true) && $value === '') { $value = null; }
        }
        $u = $db->prepare("UPDATE tblSongs SET `{$column}` = ? WHERE SongId = ?");
        if ($value === null) { $np = null; $u->bind_param('ss', $np, $songId); }
        else { $u->bind_param($type . 's', $value, $songId); }
        $u->execute();
        $u->close();
    }
    if (array_key_exists('Title', $songRow)) {
        $norm = ed2_normalizeTitle((string)$songRow['Title']);
        $un = $db->prepare('UPDATE tblSongs SET NormalizedTitle = ? WHERE SongId = ?');
        $un->bind_param('ss', $norm, $songId);
        $un->execute();
        $un->close();
    }

    /* Components — replace the whole set (only if the snapshot carries them). #1235
       P4/C5 — restore through the shared write path (drop-safe; lines authoritative +
       JSON shadow). Works for OLD revisions too — their NewData carries
       components[].lines/chords/languages. Order by the snapshot's sortOrder so the
       position-keyed write lands them in their saved display order. */
    if (isset($snap['components']) && is_array($snap['components'])) {
        $snapComps = array_values(array_filter($snap['components'], 'is_array'));
        usort($snapComps, static fn($a, $b) => ((int)($a['sortOrder'] ?? 0)) <=> ((int)($b['sortOrder'] ?? 0)));
        ed2_persistComponents($db, $songId, $snapComps);
    }

    /* Credits — replace each role table from the snapshot. */
    if (isset($snap['credits']) && is_array($snap['credits'])) {
        foreach (ED2_CREDIT_TABLES as $role => $table) {
            $d = $db->prepare("DELETE FROM `{$table}` WHERE SongId = ?");
            $d->bind_param('s', $songId);
            $d->execute();
            $d->close();
            $roleList = is_array($snap['credits'][$role] ?? null) ? $snap['credits'][$role] : [];
            if ($roleList) {
                $ci = $db->prepare("INSERT INTO `{$table}` (SongId, Name) VALUES (?, ?)");
                foreach ($roleList as $credit) {
                    $name = mb_substr(trim((string)($credit['name'] ?? '')), 0, 255);
                    if ($name === '') { continue; }
                    $ci->bind_param('ss', $songId, $name);
                    $ci->execute();
                }
                $ci->close();
            }
        }
    }

    /* Tags — replace the map from the snapshot's tag ids (the tags themselves
       are global registry rows; INSERT IGNORE skips any since-deleted tag). */
    if (isset($snap['tags']) && is_array($snap['tags'])) {
        $dt = $db->prepare('DELETE FROM tblSongTagMap WHERE SongId = ?');
        $dt->bind_param('s', $songId);
        $dt->execute();
        $dt->close();
        $it = $db->prepare('INSERT IGNORE INTO tblSongTagMap (SongId, TagId) VALUES (?, ?)');
        foreach ($snap['tags'] as $tag) {
            $tid = (int)($tag['id'] ?? 0);
            if ($tid <= 0) { continue; }
            $it->bind_param('si', $songId, $tid);
            $it->execute();
        }
        $it->close();
    }

    /* Links — reconcile via the shared helper (DELETE-then-INSERT). */
    if (isset($snap['links']) && is_array($snap['links'])) {
        $typeIds = []; $urls = []; $notes = []; $verified = [];
        foreach ($snap['links'] as $ln) {
            if (!is_array($ln)) { continue; }
            $typeIds[]  = (int)($ln['typeId'] ?? 0);
            $urls[]     = (string)($ln['url'] ?? '');
            $notes[]    = (string)($ln['note'] ?? '');
            $verified[] = !empty($ln['verified']) ? 1 : 0;
        }
        saveExternalLinksForRow($db, 'tblSongExternalLinks', 'SongId', $songId, $typeIds, $urls, $notes, $verified);
    }
}

/**
 * Write a COALESCED revision snapshot (#400): one row per song per ~15s burst,
 * not one per keystroke. NewData is the FULL hydrated record (ed2_buildSongSnapshot)
 * so a revision can be restored in full. $force=true bypasses the coalesce window
 * (used for restores, which must always land in the audit trail). Best-effort —
 * a revision failure never breaks the edit; the precise per-edit trail lives in
 * tblActivityLog via logActivity().
 */
function ed2_touchRevision(\mysqli $db, string $songId, ?int $userId, string $actionTag, bool $force = false): void {
    try {
        if (!$force) {
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
        }

        $snapshot = ed2_buildSongSnapshot($db, $songId);
        $newData  = $snapshot !== null ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;

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

    /* ---- load_song (GET) — purpose-built v2 payload, DB-direct. Returns the
           song scalars + components + credits each WITH their row Id, because
           the granular API keys every update/delete on the Id (the legacy
           getSongById shape is index-based and not guaranteed to carry ids). ---- */
    case 'load_song': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }

        /* song + components + credits + tags + links — the same builder the
           revision snapshot uses, so a restore re-hydrates the editor identically. */
        $snapshot = ed2_buildSongSnapshot($db, $songId);
        if ($snapshot === null) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        /* Media — file metadata only (never bytes); a separate file lifecycle,
           so it is NOT part of the content snapshot. [] pre-migration. */
        $media = [];
        if (ed2_songMediaTableExists($db)) {
            $ms = $db->prepare(
                'SELECT Id, Kind, StorageBackend, FileName, MimeType, SizeBytes,
                        Annotation, SortOrder, UploadedBy, UploadedAt
                   FROM tblSongMedia WHERE SongId = ?
                  ORDER BY Kind ASC, SortOrder ASC, Id ASC'
            );
            $ms->bind_param('s', $songId);
            $ms->execute();
            $mr = $ms->get_result();
            while ($row = $mr->fetch_assoc()) { $media[] = ed2_mediaRowShape($row); }
            $ms->close();
        }

        /* #1235 P3 — per-line enrichment (translations + annotations), keyed to
           tblLyricLines.Id (which load_song's components now expose as lineIds).
           Empty arrays on an un-migrated install. A separate concern from the
           component-content snapshot, like media. */
        $enrichment = lineEnrichmentForSong($db, $songId);

        ed2_respond(array_merge(['ok' => true], $snapshot, [
            'media'            => $media,
            'lineTranslations' => $enrichment['translations'],
            'lineAnnotations'  => $enrichment['annotations'],
        ]));
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
            /* #1343-B — mint the opaque PublicId permalink at create (gated; an
               un-migrated env omits the column and the backfill fills it later). */
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_public_id.php';
            if (songPublicId_columnReady($db)) {
                $pubId = songPublicId_mintUnique($db);
                $ins = $db->prepare('INSERT INTO tblSongs (SongId, PublicId, Title, NormalizedTitle, SongbookAbbr) VALUES (?, ?, ?, ?, ?)');
                $ins->bind_param('sssss', $songId, $pubId, $title, $norm, $abbr);
            } else {
                $ins = $db->prepare('INSERT INTO tblSongs (SongId, Title, NormalizedTitle, SongbookAbbr) VALUES (?, ?, ?, ?)');
                $ins->bind_param('ssss', $songId, $title, $norm, $abbr);
            }
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
        /* #1343 — optional relink: where should the deleted song's permalink point?
           A valid other SongId → 301 redirect; blank/invalid → a "removed" tombstone.
           Either way the old /song/<id> resolves instead of 404ing. */
        $redirectTo = trim((string)($body['redirectTo'] ?? ''));
        $db->begin_transaction();
        try {
            $prev = $db->prepare('SELECT Title, SongbookAbbr FROM tblSongs WHERE SongId = ? LIMIT 1');
            $prev->bind_param('s', $songId);
            $prev->execute();
            $prevRow = $prev->get_result()->fetch_assoc();
            $prev->close();
            if ($prevRow === null) { $db->rollback(); ed2_respond(['ok' => false, 'error' => 'Song not found.', 'deleted' => 0], 404); }

            /* Resolve the relink target (must be a different, existing song). */
            $rTarget = null;
            if ($redirectTo !== '' && $redirectTo !== $songId) {
                $chk = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
                $chk->bind_param('s', $redirectTo);
                $chk->execute();
                if ($chk->get_result()->fetch_row() !== null) { $rTarget = $redirectTo; }
                $chk->close();
            }

            /* #1343 — keep permalinks resolving (gated). When relinking, FORWARD any
               redirects that already point AT this song to the new target BEFORE the
               delete, so the FK ON DELETE SET NULL cascade can't strand a chain
               (mirrors the merge path). A tombstone (null target) intentionally lets
               inbound chains fall to "removed". */
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_redirects.php';
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_public_id.php';
            /* Capture the PublicId BEFORE the delete so a shared /song/<PublicId> also
               resolves afterward (#1343-B). Gated. */
            $delPubId = '';
            if (songPublicId_columnReady($db)) {
                $pp = $db->prepare('SELECT PublicId FROM tblSongs WHERE SongId = ? LIMIT 1');
                $pp->bind_param('s', $songId);
                $pp->execute();
                $delPubId = (string)($pp->get_result()->fetch_assoc()['PublicId'] ?? '');
                $pp->close();
            }
            if ($rTarget !== null) { songRedirectRepoint($db, $songId, $rTarget); }

            $del = $db->prepare('DELETE FROM tblSongs WHERE SongId = ?');
            $del->bind_param('s', $songId);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();

            songRedirectWrite($db, $songId, $rTarget, 'delete', null);
            if ($delPubId !== '') { songRedirectWrite($db, $delPubId, $rTarget, 'delete', null); }

            $db->commit();
            logActivity('song.delete', 'song', $songId, [
                'title'       => (string)($prevRow['Title'] ?? ''),
                'songbook'    => (string)($prevRow['SongbookAbbr'] ?? ''),
                'redirect_to' => $rTarget ?? '(tombstone)',
            ]);
            ed2_respond(['ok' => true, 'deleted' => (int)$deleted, 'songId' => $songId, 'redirectTo' => $rTarget]);
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
            if ($field === 'number' || $field === 'originCityId') {
                /* nullable ints (song number / place FK): empty or <=0 → NULL */
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
        $language  = isset($comp['language']) && trim((string)$comp['language']) !== '' ? trim((string)$comp['language']) : null;

        $db->begin_transaction();
        try {
            /* #1235 P4/C5 — read-modify-write the WHOLE component set through the shared
               drop-safe path (lines authoritative + JSON shadow), instead of a single-row
               LinesJson upsert. The new/updated component lands at its sortOrder; PF1/R1 —
               an UPDATE that OMITS `chords`/`languages` preserves the stored arrays. */
            $comps = ed2_currentComponents($db, $songId);
            $entry = [
                'type'      => $type,
                'number'    => $number,
                'sortOrder' => $sortOrder,
                'lines'     => $lines,
                'chords'    => (isset($comp['chords'])    && is_array($comp['chords']))    ? array_values($comp['chords'])    : null,
                'language'  => $language,
                'languages' => (isset($comp['languages']) && is_array($comp['languages'])) ? array_values($comp['languages']) : null,
                '_target'   => true,   // marker to resolve the resulting Id (stripped by the writer)
            ];
            $found = false;
            foreach ($comps as $idx => $c) {
                if ($compId > 0 && (int)($c['id'] ?? 0) === $compId) {
                    if (!isset($comp['chords']))    { $entry['chords']    = $c['chords'] ?? null; }
                    if (!isset($comp['languages'])) { $entry['languages'] = $c['languages'] ?? null; }
                    $comps[$idx] = $entry;
                    $found = true;
                    break;
                }
            }
            /* New component → APPEND at the end (never mid-list insert): the shared
               writer upserts existing thin rows BY POSITION, so a mid-list insert would
               shift every downstream component onto the wrong row (churning ComponentId +
               misrouting the returned id — the C5 review finding). Keeping $comps in the
               existing display order (target replaced in place, new appended) keeps the
               position-match Id-stable. A new component lands last; reorder via
               component_reorder. */
            if (!$found) { $comps[] = $entry; }
            ed2_persistComponents($db, $songId, $comps);
            /* Resolve the resulting componentId from the target's position. */
            $targetPos = 0;
            foreach ($comps as $idx => $c) { if (!empty($c['_target'])) { $targetPos = $idx; break; } }
            $after  = ed2_currentComponents($db, $songId);
            $compId = isset($after[$targetPos]['id']) ? (int)$after[$targetPos]['id'] : $compId;
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
            /* #1235 P4/C5 — read-modify-write the whole set (drop-safe): drop the target
               component, persist the remainder through the shared write path (which also
               cascade-removes its lines from tblLyricLines + the JSON shadow). */
            $comps   = ed2_currentComponents($db, $songId);
            $before  = count($comps);
            $comps   = array_values(array_filter($comps, static fn($c) => (int)($c['id'] ?? 0) !== $compId));
            $deleted = $before - count($comps);
            ed2_persistComponents($db, $songId, $comps);
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
            /* #1235 P4/C5 — read-modify-write the whole set in the requested order
               (drop-safe). Components named in order[] come first (in that order); any
               not named are appended in their current order. The shared write path then
               reassigns contiguous SortOrder + re-stamps each line's component. */
            $comps = ed2_currentComponents($db, $songId);
            $byId  = [];
            foreach ($comps as $c) { $byId[(int)($c['id'] ?? 0)] = $c; }
            $reordered = [];
            foreach ($order as $cid) {
                $cid = (int)$cid;
                if ($cid > 0 && isset($byId[$cid])) { $reordered[] = $byId[$cid]; unset($byId[$cid]); }
            }
            foreach ($byId as $c) { $reordered[] = $c; }   // any not listed → appended (stable)
            ed2_persistComponents($db, $songId, $reordered);
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

    /* ---- line_translation_upsert (POST) — per-line translation / transliteration
           (#1235 P3 / #1088). Body: { songId, translation:{ id?, lineId, kind,
           targetLanguage, text, translationType?, isPrimary?, sortOrder?, status? } }.
           The shared layer derives LyricsId from the line + enforces the line
           belongs to this song; enrichment survives later edits via the Id-
           preserving diff. NOT a component-content change → no revision touch. ---- */
    case 'line_translation_upsert': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        if (!lineEnrichmentTablesReady($db)) { ed2_respond(['ok' => false, 'error' => 'Line-enrichment tables are not migrated.'], 409); }
        $input = is_array($body['translation'] ?? null) ? $body['translation'] : [];
        $db->begin_transaction();
        try {
            $row = lineEnrichmentUpsertTranslation($db, $songId, $input, $ed2UserId);
            $db->commit();
        } catch (\InvalidArgumentException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.lineTranslation', 'song', $songId, ['id' => (int)($row['id'] ?? 0), 'lineId' => (int)($row['lineId'] ?? 0)]);
        ed2_respond(['ok' => true, 'translation' => $row]);
        break;
    }

    /* ---- line_translation_delete (POST) — { songId, id } ---- */
    case 'line_translation_delete': {
        $songId = trim((string)($body['songId'] ?? ''));
        $id     = (int)($body['id'] ?? 0);
        if ($songId === '' || $id <= 0) { ed2_respond(['ok' => false, 'error' => 'songId + id are required.'], 400); }
        if (!lineEnrichmentTablesReady($db)) { ed2_respond(['ok' => false, 'error' => 'Line-enrichment tables are not migrated.'], 409); }
        $db->begin_transaction();
        try {
            $removed = lineEnrichmentDeleteTranslation($db, $songId, $id);
            $db->commit();
        } catch (\RuntimeException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.lineTranslation.delete', 'song', $songId, ['id' => $id]);
        ed2_respond(['ok' => true, 'deleted' => $removed ? 1 : 0]);
        break;
    }

    /* ---- line_annotation_upsert (POST) — per-line / per-span annotation
           (#1235 P3 / #1088). Body: { songId, annotation:{ id?, startLineId,
           endLineId?, startOffset?, endOffset?, annotationType, body, bodyFormat?,
           languageCode?, sortOrder?, status? } }. Offsets are code-point indices. ---- */
    case 'line_annotation_upsert': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        if (!lineEnrichmentTablesReady($db)) { ed2_respond(['ok' => false, 'error' => 'Line-enrichment tables are not migrated.'], 409); }
        $input = is_array($body['annotation'] ?? null) ? $body['annotation'] : [];
        $db->begin_transaction();
        try {
            $row = lineEnrichmentUpsertAnnotation($db, $songId, $input, $ed2UserId);
            $db->commit();
        } catch (\InvalidArgumentException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.lineAnnotation', 'song', $songId, ['id' => (int)($row['id'] ?? 0), 'startLineId' => (int)($row['startLineId'] ?? 0)]);
        ed2_respond(['ok' => true, 'annotation' => $row]);
        break;
    }

    /* ---- line_annotation_delete (POST) — { songId, id } ---- */
    case 'line_annotation_delete': {
        $songId = trim((string)($body['songId'] ?? ''));
        $id     = (int)($body['id'] ?? 0);
        if ($songId === '' || $id <= 0) { ed2_respond(['ok' => false, 'error' => 'songId + id are required.'], 400); }
        if (!lineEnrichmentTablesReady($db)) { ed2_respond(['ok' => false, 'error' => 'Line-enrichment tables are not migrated.'], 409); }
        $db->begin_transaction();
        try {
            $removed = lineEnrichmentDeleteAnnotation($db, $songId, $id);
            $db->commit();
        } catch (\RuntimeException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.lineAnnotation.delete', 'song', $songId, ['id' => $id]);
        ed2_respond(['ok' => true, 'deleted' => $removed ? 1 : 0]);
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

    /* ---- tag_list (GET) — tags currently attached to one song ---- */
    case 'tag_list': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }
        $tags = [];
        $q = $db->prepare(
            'SELECT t.Id, t.Name, t.Slug, t.Description
               FROM tblSongTagMap m JOIN tblSongTags t ON t.Id = m.TagId
              WHERE m.SongId = ? ORDER BY t.Name ASC'
        );
        $q->bind_param('s', $songId);
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) {
            $tags[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name'], 'slug' => (string)$row['Slug'], 'description' => (string)($row['Description'] ?? '')];
        }
        $q->close();
        ed2_respond(['ok' => true, 'tags' => $tags]);
        break;
    }

    /* ---- tag_search (GET) — typeahead over the registry, with usage counts.
           Empty q => top-N by usage; otherwise substring match (case-insensitive
           via the column collation). ---- */
    case 'tag_search': {
        $term  = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 10);
        if ($limit < 1)  { $limit = 1; }
        if ($limit > 20) { $limit = 20; }
        $suggestions = [];
        if ($term === '') {
            $q = $db->prepare(
                'SELECT t.Id, t.Name, t.Slug, COUNT(m.TagId) AS UsageCount
                   FROM tblSongTags t LEFT JOIN tblSongTagMap m ON m.TagId = t.Id
                  GROUP BY t.Id, t.Name, t.Slug
                  ORDER BY UsageCount DESC, t.Name ASC LIMIT ?'
            );
            $q->bind_param('i', $limit);
        } else {
            $like = '%' . $term . '%';
            $q = $db->prepare(
                'SELECT t.Id, t.Name, t.Slug, COUNT(m.TagId) AS UsageCount
                   FROM tblSongTags t LEFT JOIN tblSongTagMap m ON m.TagId = t.Id
                  WHERE t.Name LIKE ?
                  GROUP BY t.Id, t.Name, t.Slug
                  ORDER BY UsageCount DESC, t.Name ASC LIMIT ?'
            );
            $q->bind_param('si', $like, $limit);
        }
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) {
            $suggestions[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name'], 'slug' => (string)$row['Slug'], 'usage' => (int)$row['UsageCount']];
        }
        $q->close();
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- tag_attach (POST) — attach a tag to a song, auto-creating the registry
           row if needed. Returns the CANONICAL {id,name,slug} so the client adopts
           the server's stored form. attached=false means it was already on the
           song (the (SongId,TagId) PK no-op'd). ---- */
    case 'tag_attach': {
        $songId = trim((string)($body['songId'] ?? ''));
        $name   = ed2_normalizeTag((string)($body['name'] ?? ''));
        if ($songId === '' || $name === '') { ed2_respond(['ok' => false, 'error' => 'songId + a tag name are required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        $slug = ed2_tagSlug($name);
        if ($slug === '') { ed2_respond(['ok' => false, 'error' => 'Tag name has no usable characters.'], 400); }

        $db->begin_transaction();
        try {
            /* ON DUPLICATE KEY pulls the existing row's Name up to the new
               Title-Cased form (re-canonicalises legacy lower-case rows) while
               LAST_INSERT_ID(Id) makes insert_id the existing Id on a dupe. */
            /* Name = ? (bound twice) instead of the deprecated VALUES(Name)
               (removed in MySQL 8.0.20+) — pulls an existing row's Name up to
               the new Title-Cased form (re-canonicalises legacy lower-case rows)
               while LAST_INSERT_ID(Id) makes insert_id the existing Id on a dupe. */
            $ins = $db->prepare(
                'INSERT INTO tblSongTags (Name, Slug) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE Id = LAST_INSERT_ID(Id), Name = ?'
            );
            $ins->bind_param('sss', $name, $slug, $name);
            $ins->execute();
            $tagId = (int)$db->insert_id;
            $ins->close();

            /* TaggedBy bound as nullable INT (not 0) so a session with no resolved
               user id doesn't trip fk_TagMap_User. INSERT IGNORE = PK dedupe. */
            $map = $db->prepare('INSERT IGNORE INTO tblSongTagMap (SongId, TagId, TaggedBy) VALUES (?, ?, ?)');
            $map->bind_param('sii', $songId, $tagId, $ed2UserId);
            $map->execute();
            $attached = $map->affected_rows > 0;
            $map->close();

            ed2_touchRevision($db, $songId, $ed2UserId, 'tag');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.tag.attach', 'song', $songId, ['tag' => $name, 'tagId' => $tagId]);
        ed2_respond(['ok' => true, 'tag' => ['id' => $tagId, 'name' => $name, 'slug' => $slug], 'attached' => $attached]);
        break;
    }

    /* ---- tag_detach (POST) — remove a tag from a song by TagId ---- */
    case 'tag_detach': {
        $songId = trim((string)($body['songId'] ?? ''));
        $tagId  = (int)($body['tagId'] ?? 0);
        if ($songId === '' || $tagId <= 0) { ed2_respond(['ok' => false, 'error' => 'songId + tagId are required.'], 400); }
        $db->begin_transaction();
        try {
            $d = $db->prepare('DELETE FROM tblSongTagMap WHERE SongId = ? AND TagId = ?');
            $d->bind_param('si', $songId, $tagId);
            $d->execute();
            $removed = $d->affected_rows;
            $d->close();
            ed2_touchRevision($db, $songId, $ed2UserId, 'tag');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.tag.detach', 'song', $songId, ['tagId' => $tagId]);
        ed2_respond(['ok' => true, 'removed' => (int)$removed]);
        break;
    }

    /* ---- link_save_all (POST) — reconcile the whole external-links sub-form
           (DELETE-then-INSERT), the SAME contract every other surface uses via
           saveExternalLinksForRow(). Links are a bounded sub-form with no
           dual-path race, so a reconcile (rather than per-row granular) is safe
           here and lets the editor reuse the shared card-list module + its DOM
           field naming verbatim. Returns the canonical persisted rows. ---- */
    case 'link_save_all': {
        $songId = trim((string)($body['songId'] ?? ''));
        $links  = is_array($body['links'] ?? null) ? $body['links'] : [];
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        /* Unzip rows into the parallel arrays the shared helper expects. The
           helper itself validates each row (typeId>0, http(s) URL, ≤2048) and
           skips invalid ones, so a half-typed row never persists. */
        $typeIds = []; $urls = []; $notes = []; $verified = [];
        foreach ($links as $ln) {
            if (!is_array($ln)) { continue; }
            $typeIds[]  = (int)($ln['typeId'] ?? 0);
            $urls[]     = (string)($ln['url'] ?? '');
            $notes[]    = (string)($ln['note'] ?? '');
            $verified[] = !empty($ln['verified']) ? 1 : 0;
        }

        $db->begin_transaction();
        try {
            $count = saveExternalLinksForRow($db, 'tblSongExternalLinks', 'SongId', $songId, $typeIds, $urls, $notes, $verified);
            ed2_touchRevision($db, $songId, $ed2UserId, 'links');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.external_links', 'song', $songId, ['count' => $count]);
        $saved = loadExternalLinksForRow($db, 'tblSongExternalLinks', 'SongId', $songId);
        ed2_respond(['ok' => true, 'count' => $count, 'links' => $saved]);
        break;
    }

    /* ---- media_list (GET) — file metadata for a song (never bytes) ---- */
    case 'media_list': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }
        $media = [];
        if (ed2_songMediaTableExists($db)) {
            $ms = $db->prepare(
                'SELECT Id, Kind, StorageBackend, FileName, MimeType, SizeBytes,
                        Annotation, SortOrder, UploadedBy, UploadedAt
                   FROM tblSongMedia WHERE SongId = ?
                  ORDER BY Kind ASC, SortOrder ASC, Id ASC'
            );
            $ms->bind_param('s', $songId);
            $ms->execute();
            $mr = $ms->get_result();
            while ($row = $mr->fetch_assoc()) { $media[] = ed2_mediaRowShape($row); }
            $ms->close();
        }
        ed2_respond(['ok' => true, 'media' => $media]);
        break;
    }

    /* ---- media_upload (POST, MULTIPART) — the one multipart endpoint: reads
           $_POST + $_FILES, not the JSON $body. The top-of-file CSRF guard still
           applies (token in the X-CSRF-Token header). MIME is SNIFFED on the
           bytes (never the declared content-type); size-capped per kind; staged
           FS-or-DB by SongMediaStorage. An FS file staged before an INSERT that
           then fails is unlinked so nothing orphans. ---- */
    case 'media_upload': {
        if ($method !== 'POST') { ed2_respond(['ok' => false, 'error' => 'POST required.'], 405); }
        if (!ed2_songMediaTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Song Media migration has not been run.'], 503); }

        $songId     = trim((string)($_POST['songId'] ?? ''));
        $kind       = trim((string)($_POST['kind'] ?? ''));
        $annotation = trim((string)($_POST['annotation'] ?? ''));
        if ($songId === '' || !in_array($kind, SongMediaStorage::allKinds(), true)) {
            ed2_respond(['ok' => false, 'error' => 'Missing or invalid songId / kind.'], 400);
        }
        if ($annotation !== '') { $annotation = mb_substr($annotation, 0, 255); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server upload size limit.',
                UPLOAD_ERR_NO_FILE                        => 'No file received — expected multipart with a "file" field.',
                UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted.',
                default                                   => 'Upload failed.',
            };
            ed2_respond(['ok' => false, 'error' => $msg, 'phpError' => $err], 400);
        }

        $tmpPath  = (string)$_FILES['file']['tmp_name'];
        $origName = (string)($_FILES['file']['name'] ?? 'upload');
        $size     = (int)($_FILES['file']['size'] ?? 0);
        $staged   = null;   // tracked so the outer catch can unlink an FS orphan

        try {
            $meta  = SongMediaStorage::validateUpload($tmpPath, $kind, $size);
            $bytes = file_get_contents($tmpPath);
            if ($bytes === false) { throw new \RuntimeException('Could not read upload tempfile.'); }
            $staged = SongMediaStorage::stage($bytes, $kind, $meta['extension']);

            $cleanName = basename($origName);
            $cleanName = preg_replace('/[\x00-\x1f\x7f]/', '', $cleanName) ?? 'upload';
            $cleanName = mb_substr($cleanName, 0, 255);

            $db->begin_transaction();
            try {
                /* SortOrder = (max+1) for this (song, kind) so new uploads append. */
                $mx = $db->prepare('SELECT COALESCE(MAX(SortOrder), -1) AS m FROM tblSongMedia WHERE SongId = ? AND Kind = ?');
                $mx->bind_param('ss', $songId, $kind);
                $mx->execute();
                $nextOrder = (int)($mx->get_result()->fetch_assoc()['m'] ?? -1) + 1;
                $mx->close();

                $annotationOrNull = ($annotation !== '') ? $annotation : null;
                $ins = $db->prepare(
                    'INSERT INTO tblSongMedia
                        (SongId, Kind, StorageBackend, FileName, MimeType, SizeBytes,
                         Sha256, Content, StoragePath, Annotation, SortOrder, UploadedBy)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                /* 's' for the BLOB content is fine under the sub-16MB cap (mysqli is binary-safe). */
                $ins->bind_param(
                    'sssssissssii',
                    $songId, $kind, $staged['backend'], $cleanName, $meta['mime'], $size,
                    $staged['sha256'], $staged['content'], $staged['path'], $annotationOrNull, $nextOrder, $ed2UserId
                );
                $ins->execute();
                $newId = (int)$db->insert_id;
                $ins->close();

                ed2_touchRevision($db, $songId, $ed2UserId, 'media');
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }

            logActivity('song-media.upload', 'song', $songId, [
                'media_id' => $newId, 'kind' => $kind, 'backend' => $staged['backend'],
                'file_name' => $cleanName, 'mime' => $meta['mime'], 'size_bytes' => $size, 'sha256' => $staged['sha256'],
            ]);
            ed2_respond(['ok' => true, 'media' => ed2_mediaRowShape([
                'Id' => $newId, 'Kind' => $kind, 'FileName' => $cleanName, 'MimeType' => $meta['mime'],
                'SizeBytes' => $size, 'Annotation' => $annotation, 'SortOrder' => $nextOrder,
                'StorageBackend' => $staged['backend'], 'UploadedAt' => date('Y-m-d H:i:s'),
            ])]);
        } catch (\Throwable $e) {
            /* Unlink a staged FS file if the DB write never landed (no orphans). */
            if (is_array($staged) && ($staged['backend'] ?? '') === 'filesystem' && !empty($staged['path'])) {
                SongMediaStorage::deleteStorage(['StorageBackend' => 'filesystem', 'StoragePath' => $staged['path']]);
            }
            $userFacing = $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException;
            error_log('[editor-v2-api media_upload] ' . $e->getMessage());
            ed2_respond([
                'ok'           => false,
                'error'        => $userFacing ? $e->getMessage() : 'Upload failed.',
                'error_detail' => $ed2IsAdmin ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : null,
            ], $userFacing ? 400 : 500);
        }
        break;
    }

    /* ---- media_update (POST JSON) — only Annotation is mutable post-upload ---- */
    case 'media_update': {
        $mediaId    = (int)($body['mediaId'] ?? 0);
        $annotation = trim((string)($body['annotation'] ?? ''));
        if ($mediaId <= 0) { ed2_respond(['ok' => false, 'error' => 'mediaId is required.'], 400); }
        if (!ed2_songMediaTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Song Media migration has not been run.'], 503); }
        if ($annotation !== '') { $annotation = mb_substr($annotation, 0, 255); }
        $annotationOrNull = ($annotation !== '') ? $annotation : null;

        $sel = $db->prepare('SELECT SongId FROM tblSongMedia WHERE Id = ? LIMIT 1');
        $sel->bind_param('i', $mediaId);
        $sel->execute();
        $mrow = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$mrow) { ed2_respond(['ok' => false, 'error' => 'Media not found.'], 404); }
        $mSongId = (string)$mrow['SongId'];

        $db->begin_transaction();
        try {
            $u = $db->prepare('UPDATE tblSongMedia SET Annotation = ? WHERE Id = ?');
            $u->bind_param('si', $annotationOrNull, $mediaId);
            $u->execute();
            $u->close();
            ed2_touchRevision($db, $mSongId, $ed2UserId, 'media');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song-media.update', 'song', $mSongId, ['mediaId' => $mediaId]);
        ed2_respond(['ok' => true, 'mediaId' => $mediaId]);
        break;
    }

    /* ---- media_delete (POST JSON) — removes the row + its underlying bytes ---- */
    case 'media_delete': {
        $mediaId = (int)($body['mediaId'] ?? 0);
        if ($mediaId <= 0) { ed2_respond(['ok' => false, 'error' => 'mediaId is required.'], 400); }
        if (!ed2_songMediaTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Song Media migration has not been run.'], 503); }

        $sel = $db->prepare('SELECT Id, SongId, Kind, StorageBackend, StoragePath, FileName FROM tblSongMedia WHERE Id = ? LIMIT 1');
        $sel->bind_param('i', $mediaId);
        $sel->execute();
        $mrow = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$mrow) { ed2_respond(['ok' => false, 'error' => 'Media not found.'], 404); }
        $mSongId = (string)$mrow['SongId'];

        $db->begin_transaction();
        try {
            $d = $db->prepare('DELETE FROM tblSongMedia WHERE Id = ?');
            $d->bind_param('i', $mediaId);
            $d->execute();
            $deleted = $d->affected_rows;
            $d->close();
            ed2_touchRevision($db, $mSongId, $ed2UserId, 'media');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        /* Remove bytes only after the row is gone (orphan file recoverable;
           an orphan row after a "delete me" is worse). */
        SongMediaStorage::deleteStorage([
            'StorageBackend' => (string)$mrow['StorageBackend'],
            'StoragePath'    => (string)($mrow['StoragePath'] ?? ''),
        ]);
        logActivity('song-media.delete', 'song', $mSongId, [
            'mediaId' => $mediaId, 'kind' => (string)$mrow['Kind'], 'fileName' => (string)$mrow['FileName'],
        ]);
        ed2_respond(['ok' => true, 'deleted' => (int)$deleted, 'songId' => $mSongId]);
        break;
    }

    /* ---- media_reorder (POST JSON) — rewrite SortOrder for one (song, kind)
           group from the posted id order. The scoped WHERE (Id+SongId+Kind)
           prevents cross-song/cross-kind tampering. ---- */
    case 'media_reorder': {
        $songId = trim((string)($body['songId'] ?? ''));
        $kind   = trim((string)($body['kind'] ?? ''));
        $rawIds = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        if ($songId === '' || !in_array($kind, SongMediaStorage::allKinds(), true)) {
            ed2_respond(['ok' => false, 'error' => 'Missing or invalid songId / kind.'], 400);
        }
        $orderedIds = [];
        foreach ($rawIds as $raw) {
            $id = (int)$raw;
            if ($id > 0 && !in_array($id, $orderedIds, true)) { $orderedIds[] = $id; }
        }
        if (empty($orderedIds)) { ed2_respond(['ok' => true, 'reordered' => 0]); }
        if (!ed2_songMediaTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Song Media migration has not been run.'], 503); }

        $db->begin_transaction();
        try {
            $u = $db->prepare('UPDATE tblSongMedia SET SortOrder = ? WHERE Id = ? AND SongId = ? AND Kind = ?');
            $written = 0;
            foreach ($orderedIds as $i => $id) {
                $u->bind_param('iiss', $i, $id, $songId, $kind);
                $u->execute();
                $written += $u->affected_rows;
            }
            $u->close();
            ed2_touchRevision($db, $songId, $ed2UserId, 'media');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song-media.reorder', 'song', $songId, ['kind' => $kind, 'count' => count($orderedIds)]);
        ed2_respond(['ok' => true, 'reordered' => $written]);
        break;
    }

    /* ---- components_replace (POST) — atomic bulk set of a song's components,
           for Paste & Reflow + single-song import (both produce a whole section
           set at once). mode 'replace' (default) wipes the existing components
           first; 'append' adds after the current max SortOrder. ONE transaction,
           ONE LyricsText rebuild, ONE revision + activity row — no N-request
           granular loop. Returns the persisted rows (with real ids) so the
           client re-hydrates the Structure tab. ---- */
    case 'components_replace': {
        $songId = trim((string)($body['songId'] ?? ''));
        $rows   = is_array($body['components'] ?? null) ? $body['components'] : [];
        $mode   = (($body['mode'] ?? 'replace') === 'append') ? 'append' : 'replace';
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        $db->begin_transaction();
        try {
            /* #1235 P4/C5 — bulk replace/append through the shared drop-safe write path
               (lines authoritative + JSON shadow). PF1 / R1: on 'replace', a pasted
               component that OMITS chords/languages reclaims the position-matched
               original's (Type | Number | line-count, FIFO) — so Paste & Reflow / import
               (which rebuilds STRUCTURE, not enrichment) never silently drops it. Client-
               sent values always win. */
            $existing = ed2_currentComponents($db, $songId);
            $carry = [];   // "type\x1fnumber\x1flineCount" => FIFO of ['c'=>chords array|null,'l'=>languages array|null]
            if ($mode === 'replace') {
                foreach ($existing as $pc) {
                    $ck = (string)$pc['type'] . "\x1f" . (string)(int)$pc['number'] . "\x1f" . count($pc['lines']);
                    $carry[$ck][] = ['c' => $pc['chords'], 'l' => $pc['languages']];
                }
            }

            /* Normalise the incoming components (editor shape), applying carry-forward. */
            $incoming = [];
            foreach ($rows as $comp) {
                if (!is_array($comp)) { continue; }
                $type   = mb_substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse';
                $number = max(0, (int)($comp['number'] ?? 0));
                $lines  = is_array($comp['lines'] ?? null) ? array_values(array_map('strval', $comp['lines'])) : [];
                $carried = null;
                if ($mode === 'replace') {
                    $ck = $type . "\x1f" . (string)$number . "\x1f" . count($lines);
                    if (!empty($carry[$ck])) { $carried = array_shift($carry[$ck]); }
                }
                $chords = (isset($comp['chords']) && is_array($comp['chords']))
                    ? array_values($comp['chords'])
                    : ($carried !== null ? $carried['c'] : null);
                $langs  = (isset($comp['languages']) && is_array($comp['languages']))
                    ? array_values($comp['languages'])
                    : ($carried !== null ? $carried['l'] : null);
                $incoming[] = [
                    'type'      => $type,
                    'number'    => $number,
                    'language'  => (isset($comp['language']) && trim((string)$comp['language']) !== '') ? trim((string)$comp['language']) : null,
                    'lines'     => $lines,
                    'chords'    => is_array($chords) ? $chords : null,
                    'languages' => is_array($langs) ? $langs : null,
                ];
            }
            $count = count($incoming);

            /* replace = the incoming set; append = existing rows then incoming. The
               shared writer upserts the existing rows Id-stably + inserts the rest. */
            $finalComps = ($mode === 'append') ? array_merge($existing, $incoming) : $incoming;
            ed2_persistComponents($db, $songId, $finalComps);
            ed2_touchRevision($db, $songId, $ed2UserId, 'components_replace');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.components.replace', 'song', $songId, ['mode' => $mode, 'count' => $count]);

        /* Re-read the persisted set (real ids) — same editor shape load_song emits. */
        $out = ed2_currentComponents($db, $songId);
        ed2_respond(['ok' => true, 'count' => $count, 'components' => $out]);
        break;
    }

    /* ---- import_file (POST, MULTIPART) — single-file bulk import for the 7
           legacy single-file formats, routing the upload to the SHARED parser +
           universal saver (INSERT-only, skips existing). Auto-detects the format
           from the extension when format=auto. ZIP (multi-file/async) is a
           separate endpoint (4b.3). Returns the same summary shape the legacy
           bulk_import_* endpoints do. ---- */
    case 'import_file': {
        if ($method !== 'POST') { ed2_respond(['ok' => false, 'error' => 'POST required.'], 405); }
        $format = strtolower(trim((string)($_POST['format'] ?? 'auto')));
        $dedupe = ((string)($_POST['dedupeMode'] ?? 'off') === 'skip-title') ? 'skip-title' : 'off';

        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server upload size limit.',
                UPLOAD_ERR_NO_FILE                        => 'No file received — expected multipart with a "file" field.',
                UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted.',
                default                                   => 'Upload failed.',
            };
            ed2_respond(['ok' => false, 'error' => $msg], 400);
        }

        /* Explicit size cap (defense-in-depth beyond php.ini upload_max_filesize),
           mirroring the legacy per-format caps — 25 MiB covers the largest (PPTX). */
        if ((int)($_FILES['file']['size'] ?? 0) > 25 * 1024 * 1024) {
            ed2_respond(['ok' => false, 'error' => 'File too large (max 25 MB for single-file import).'], 400);
        }
        $tmpPath  = (string)$_FILES['file']['tmp_name'];
        $origName = (string)($_FILES['file']['name'] ?? 'upload');
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        /* Body-format uploads are read ONCE into $content. Pre-seeded to null so the
           `.json` content sniff below can fill it and the import re-use it rather
           than reading a 25 MB upload off disk twice (#1633). */
        $content = null;

        if ($format === 'auto' || $format === '') {
            $format = match ($ext) {
                'json'  => 'videopsalm',
                'xml'   => 'openlp',       // processOpenLp content-sniffs OpenLyrics vs OpenSong
                'pro6'  => 'pro6',
                'show'  => 'freeshow',
                'pptx'  => 'pptx',
                'db'    => 'easyworship',
                'txt'   => 'proclaim',
                'cho', 'chopro', 'crd', 'chord', 'pro' => 'chordpro',   // #1264 ChordPro
                default => '',
            };

            /* ELI5: two different formats both use ".json", so peek inside to tell
               which one this is.
               #1633 — the extension map above cannot separate them: VideoPsalm
               claimed `.json` first, and the iHymns interchange format
               (tests/fixtures/songs.schema.json) uses the same extension. Sniffing
               the CONTENT is the established answer here — `'xml' => 'openlp'` on
               the line above relies on _bulkImport_looksLikeOpenLyrics() downstream
               for exactly the same reason.
               The sniff is CONSERVATIVE BY DESIGN and only ever REDIRECTS AWAY from
               videopsalm when the body is unambiguously ours (all three iHymns
               top-level keys present AND VideoPsalm's "Songs" key absent), so no
               existing VideoPsalm import can be re-routed by this. Anything
               ambiguous stays on the old path and the operator can still pick
               "iHymns interchange (.json)" explicitly from the format dropdown. */
            if ($format === 'videopsalm') {
                $probe = file_get_contents($tmpPath);
                if ($probe === false) { ed2_respond(['ok' => false, 'error' => 'Could not read the uploaded file.'], 500); }
                if (_bulkImport_looksLikeIHymnsJson($probe)) { $format = 'ihymns'; }
                $content = $probe;      // reused below — do not re-read the upload
            }
        }

        /* Configure the dedup mode for every _bulkImport_saveSong() this request makes. */
        _bulkImport_dedupeMode($dedupe);

        $bodyFormats = ['videopsalm', 'ihymns', 'openlp', 'pro6', 'proclaim', 'freeshow', 'chordpro'];
        $summary = null;
        try {
            if (in_array($format, $bodyFormats, true)) {
                if ($content === null) { $content = file_get_contents($tmpPath); }
                if ($content === false) { throw new \RuntimeException('Could not read the uploaded file.'); }
                $summary = match ($format) {
                    'videopsalm' => _bulkImport_processVideoPsalm($content, $origName),
                    'ihymns'     => _bulkImport_processIHymnsJson($content, $origName),  // #1633
                    'openlp'     => _bulkImport_processOpenLp($content, $origName),
                    'pro6'       => _bulkImport_processPro6($content, $origName),
                    'proclaim'   => _bulkImport_processProclaim($content, $origName),
                    'freeshow'   => _bulkImport_processFreeShow($content, $origName),
                    'chordpro'   => _bulkImport_processChordPro($content, $origName),   // #1264
                };
            } elseif ($format === 'pptx') {
                $summary = _bulkImport_processPptx($tmpPath, $origName);
            } elseif ($format === 'easyworship') {
                $summary = _bulkImport_processEasyWorship($tmpPath, $origName);
            } else {
                ed2_respond(['ok' => false, 'error' => 'Unknown or undetected format — choose one explicitly.'], 400);
            }
        } catch (\Throwable $e) {
            $userFacing = $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException;
            error_log('[editor-v2-api import_file] ' . $e->getMessage());
            ed2_respond([
                'ok'           => false,
                'error'        => $userFacing ? $e->getMessage() : 'Import failed.',
                'error_detail' => $ed2IsAdmin ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : null,
            ], $userFacing ? 400 : 500);
        }

        if (!is_array($summary)) { ed2_respond(['ok' => false, 'error' => 'Importer returned no result.'], 500); }

        /* Regenerate songbook-derived state after a successful import (mirrors the
           legacy bulk_import_* handlers). Best-effort + guarded. */
        if ((int)($summary['songs_created'] ?? 0) > 0) {
            ed2_runSongbookMaintenance($db, 'import_file');
        }
        logActivity('song.import_file', 'import', $origName, [
            'format'  => $format,
            'created' => (int)($summary['songs_created'] ?? 0),
            'skipped' => (int)($summary['songs_skipped_existing'] ?? 0),
            'failed'  => (int)($summary['songs_failed'] ?? 0),
        ]);
        ed2_respond(array_merge(['ok' => true, 'format' => $format], $summary));
        break;
    }

    /* ---- import_zip (POST, MULTIPART) — async multi-song ZIP import. Mirrors
           the legacy bulk_import_zip orchestration but on the clean v2 surface:
           persist the upload, create a tblBulkImportJobs row, return {job_id,
           poll_url}, release the connection (fastcgi_finish_request), then run
           the SHARED _bulkImport_processZip worker. The persist dir + job table
           are SHARED with legacy. NB: the async success path does NOT use
           ed2_respond (which exit()s) — it echoes, flushes, then keeps working. ---- */
    case 'import_zip': {
        if ($method !== 'POST') { ed2_respond(['ok' => false, 'error' => 'POST required.'], 405); }
        _bulkImport_dedupeMode(((string)($_POST['dedupeMode'] ?? 'off') === 'skip-title') ? 'skip-title' : 'off');
        if (!class_exists('ZipArchive')) { ed2_respond(['ok' => false, 'error' => 'Server is missing the PHP zip extension.'], 500); }
        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file is larger than the server limit.',
                UPLOAD_ERR_NO_FILE                        => 'No file received — expected a multipart upload with a "file" field.',
                default                                   => 'Upload failed.',
            };
            ed2_respond(['ok' => false, 'error' => $msg, 'phpError' => $err], 400);
        }
        $sizeBytes = (int)($_FILES['file']['size'] ?? 0);
        if ($sizeBytes > 100 * 1024 * 1024) { ed2_respond(['ok' => false, 'error' => 'Uploaded zip exceeds the 100 MB import limit.'], 413); }
        $tmpPath  = (string)$_FILES['file']['tmp_name'];
        $origName = (string)($_FILES['file']['name'] ?? 'upload.zip');

        /* EasyWorship export zips carry a Songs.db (not the song-file layout) —
           detect + hand to the EW reader synchronously (mirror legacy). */
        try {
            $ewProbe = new \ZipArchive();
            if ($ewProbe->open($tmpPath) === true) {
                $hasSongsDb = false;
                for ($zi = 0; $zi < $ewProbe->numFiles; $zi++) {
                    if (strtolower(basename((string)$ewProbe->getNameIndex($zi))) === 'songs.db') { $hasSongsDb = true; break; }
                }
                $ewProbe->close();
                if ($hasSongsDb) {
                    $summary = _bulkImport_processEasyWorship($tmpPath, $origName);
                    if ((int)($summary['songs_created'] ?? 0) > 0) { ed2_runSongbookMaintenance($db, 'import_zip_easyworship'); }
                    logActivity('song.import_zip', 'import', $origName, ['mode' => 'easyworship', 'created' => (int)($summary['songs_created'] ?? 0)]);
                    ed2_respond(array_merge(['ok' => (bool)($summary['ok'] ?? false)], $summary), ($summary['ok'] ?? false) ? 200 : 400);
                }
            }
        } catch (\Throwable $ewE) {
            error_log('[editor-v2-api import_zip] EasyWorship probe failed: ' . $ewE->getMessage());
        }

        /* Synchronous fallback when async job tracking isn't available. */
        if (!ed2_bulkJobsTableExists($db)) {
            try {
                $summary = _bulkImport_processZip($tmpPath);
                if ((int)($summary['songs_created'] ?? 0) > 0) { ed2_runSongbookMaintenance($db, 'import_zip.sync'); }
                logActivity('song.import_zip', 'import', $origName, ['mode' => 'sync', 'created' => (int)($summary['songs_created'] ?? 0)]);
                ed2_respond(array_merge(['ok' => true], $summary));
            } catch (\Throwable $e) {
                error_log('[editor-v2-api import_zip sync] ' . $e->getMessage());
                ed2_respond(['ok' => false, 'error' => 'Import failed.', 'error_detail' => $ed2IsAdmin ? $e->getMessage() : null], 500);
            }
        }

        /* Persist the upload outside the docroot so it survives the request close
           (same dir + job table the legacy importer uses). */
        $persistDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.bulk_import_uploads';
        if (!is_dir($persistDir)) { @mkdir($persistDir, 0700, true); }
        $persistPath = $persistDir . DIRECTORY_SEPARATOR . 'job-' . bin2hex(random_bytes(8)) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
        if (!@move_uploaded_file($tmpPath, $persistPath)) {
            /* move failed → sync fallback so the import still succeeds. */
            try {
                $summary = _bulkImport_processZip($tmpPath);
                if ((int)($summary['songs_created'] ?? 0) > 0) { ed2_runSongbookMaintenance($db, 'import_zip.sync_fallback'); }
                ed2_respond(array_merge(['ok' => true], $summary));
            } catch (\Throwable $e) {
                error_log('[editor-v2-api import_zip move-fallback] ' . $e->getMessage());
                ed2_respond(['ok' => false, 'error' => 'Import failed.'], 500);
            }
        }
        @chmod($persistPath, 0600);

        /* Create the queued job row. Bind a guaranteed-int UserId (the auth gate
           guarantees a logged-in editor; the ?? 0 keeps the stored UserId
           consistent with the `?? 0` ownership filter in import_zip_status /
           import_zip_skipped_csv, so a row is never un-pollable on a NULL≠0 mismatch). */
        $insUid = (int)($ed2UserId ?? 0);
        try {
            $j = $db->prepare('INSERT INTO tblBulkImportJobs (UserId, Filename, TempPath, SizeBytes, Status) VALUES (?, ?, ?, ?, "queued")');
            $j->bind_param('issi', $insUid, $origName, $persistPath, $sizeBytes);
            $j->execute();
            $jobId = (int)$db->insert_id;
            $j->close();
        } catch (\Throwable $e) {
            @unlink($persistPath);
            error_log('[editor-v2-api import_zip] could not create job row: ' . $e->getMessage());
            ed2_respond(['ok' => false, 'error' => 'Could not start import job.'], 500);
        }

        /* Hand the browser its tracking handle, then release the connection.
           NOT ed2_respond — the worker must run AFTER the response is sent. */
        http_response_code(200);
        echo json_encode([
            'ok'       => true,
            'async'    => true,
            'job_id'   => $jobId,
            'status'   => 'queued',
            'poll_url' => '/manage/editor/api2.php?action=import_zip_status&job_id=' . $jobId,
        ], JSON_UNESCAPED_UNICODE);
        @session_write_close();
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) { @ob_end_flush(); }
            @flush();
        }

        /* Worker — runs after the HTTP connection is freed. Crash → job 'failed'. */
        try {
            $db = getDbMysqli();
            _bulkImport_jobMark($db, $jobId, 'running', ['StartedAt' => 'NOW()']);
            $summary = _bulkImport_processZip($persistPath, $db, $jobId);
            _bulkImport_jobMark($db, $jobId, 'completed', [
                'CompletedAt'           => 'NOW()',
                'SongbooksCreatedJson'  => json_encode($summary['songbooks_created']  ?? [], JSON_UNESCAPED_UNICODE),
                'SongbooksExistingJson' => json_encode($summary['songbooks_existing'] ?? [], JSON_UNESCAPED_UNICODE),
                'SongsCreated'          => (int)($summary['songs_created'] ?? 0),
                'SongsSkippedExisting'  => (int)($summary['songs_skipped_existing'] ?? 0),
                'SongsFailed'           => (int)($summary['songs_failed'] ?? 0),
                'ErrorsJson'            => json_encode($summary['errors'] ?? [], JSON_UNESCAPED_UNICODE),
                'SkippedSongIdsJson'    => json_encode($summary['skipped_song_ids'] ?? [], JSON_UNESCAPED_UNICODE),
                'PerSongbookJson'       => json_encode($summary['per_songbook'] ?? [], JSON_UNESCAPED_UNICODE),
                'PhaseLabel'            => 'completed',
                'TempPath'              => '',
            ]);
            @unlink($persistPath);
            if ((int)($summary['songs_created'] ?? 0) > 0) { ed2_runSongbookMaintenance($db, 'import_zip.async'); }

            /* Best-effort completion notification so the curator finds the result later.
               #1638 — was a hand-rolled INSERT INTO tblNotifications, one of
               three near-identical copies. Now the shared notifyUser() helper,
               which owns the best-effort try/catch, the column-width clipping
               and the #1238 Environment/ExpiresAt migration gate that this copy
               never had. */
            if ($ed2UserId !== null) {
                $c = (int)($summary['songs_created'] ?? 0); $s = (int)($summary['songs_skipped_existing'] ?? 0); $fl = (int)($summary['songs_failed'] ?? 0);
                notifyUser(
                    $db,
                    $ed2UserId,
                    'bulk_import_complete',
                    "Import finished: {$c} new, {$s} skipped" . ($fl > 0 ? ", {$fl} failed" : ''),
                    'Bulk import of "' . $origName . '" completed.',
                    '/manage/editor/'
                );
            }
            logActivity('song.import_zip', 'import', $origName, [
                'job_id'  => $jobId, 'mode' => 'async',
                'created' => (int)($summary['songs_created'] ?? 0),
                'skipped' => (int)($summary['songs_skipped_existing'] ?? 0),
                'failed'  => (int)($summary['songs_failed'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            error_log('[editor-v2-api import_zip worker] ' . $e->getMessage());
            try {
                $db = getDbMysqli();
                _bulkImport_jobMark($db, $jobId, 'failed', [
                    'ErrorsJson'  => json_encode([['entry' => '(worker)', 'error' => $e->getMessage()]], JSON_UNESCAPED_UNICODE),
                    'CompletedAt' => 'NOW()',
                    'PhaseLabel'  => 'failed',
                    'TempPath'    => '',
                ]);
                @unlink($persistPath);
            } catch (\Throwable $_e) { /* give up */ }
        }
        exit;   // worker finished; connection already released
    }

    /* ---- import_zip_status (GET) — poll an async import job (own jobs only) ---- */
    case 'import_zip_status': {
        $jobId = (int)($_GET['job_id'] ?? 0);
        if ($jobId <= 0) { ed2_respond(['ok' => false, 'error' => 'job_id required.'], 400); }
        if (!ed2_bulkJobsTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Bulk-import job tracking is not enabled on this deployment.', 'migration_needed' => true], 404); }

        $uid = $ed2UserId ?? 0;
        $s = $db->prepare(
            'SELECT Id, UserId, Filename, SizeBytes, Status, TotalEntries, ProcessedEntries,
                    SongbooksCreatedJson, SongbooksExistingJson, SongsCreated, SongsSkippedExisting,
                    SongsFailed, ErrorsJson, PerSongbookJson, PhaseLabel, StartedAt, CompletedAt, CreatedAt, UpdatedAt
               FROM tblBulkImportJobs WHERE Id = ? AND UserId = ? LIMIT 1'
        );
        $s->bind_param('ii', $jobId, $uid);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) { ed2_respond(['ok' => false, 'error' => 'Job not found.'], 404); }

        $decode = static fn($x) => $x === null ? null : json_decode($x, true);
        $total = (int)$row['TotalEntries']; $processed = (int)$row['ProcessedEntries'];
        ed2_respond(['ok' => true, 'job' => [
            'id'                     => (int)$row['Id'],
            'status'                 => (string)$row['Status'],
            'filename'               => (string)$row['Filename'],
            'size_bytes'             => (int)$row['SizeBytes'],
            'total_entries'          => $total,
            'processed_entries'      => $processed,
            'percent'                => $total > 0 ? round(($processed / $total) * 100, 1) : 0,
            'songs_created'          => (int)$row['SongsCreated'],
            'songs_skipped_existing' => (int)$row['SongsSkippedExisting'],
            'songs_failed'           => (int)$row['SongsFailed'],
            'songbooks_created'      => $decode($row['SongbooksCreatedJson'])  ?? [],
            'songbooks_existing'     => $decode($row['SongbooksExistingJson']) ?? [],
            'errors'                 => $decode($row['ErrorsJson'])            ?? [],
            'per_songbook'           => $decode($row['PerSongbookJson'])       ?? null,
            'skip_reason'            => 'existing-in-db',
            'skipped_csv_url'        => (int)$row['SongsSkippedExisting'] > 0
                ? '/manage/editor/api2.php?action=import_zip_skipped_csv&job_id=' . (int)$row['Id'] : '',
            'phase_label'            => $row['PhaseLabel'] ?? null,
            'started_at'             => $row['StartedAt'],
            'completed_at'           => $row['CompletedAt'],
            'created_at'             => $row['CreatedAt'],
            'updated_at'             => $row['UpdatedAt'],
        ]]);
        break;
    }

    /* ---- import_zip_skipped_csv (GET) — CSV of the SongIds an async job skipped
           (already existed). Own jobs only. Streams CSV, not JSON. ---- */
    case 'import_zip_skipped_csv': {
        $jobId = (int)($_GET['job_id'] ?? 0);
        if ($jobId <= 0) { ed2_respond(['ok' => false, 'error' => 'job_id required.'], 400); }
        if (!ed2_bulkJobsTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Job tracking not enabled.', 'migration_needed' => true], 404); }

        $uid = $ed2UserId ?? 0;
        $s = $db->prepare('SELECT Filename, Status, SkippedSongIdsJson FROM tblBulkImportJobs WHERE Id = ? AND UserId = ? LIMIT 1');
        $s->bind_param('ii', $jobId, $uid);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) { ed2_respond(['ok' => false, 'error' => 'Job not found.'], 404); }
        if ((string)$row['Status'] !== 'completed') { ed2_respond(['ok' => false, 'error' => 'Job is not yet completed.'], 409); }
        $skipped = json_decode((string)($row['SkippedSongIdsJson'] ?? '[]'), true);
        if (!is_array($skipped) || !$skipped) { ed2_respond(['ok' => false, 'error' => 'No skipped SongIds recorded for this job.'], 404); }

        $ph    = implode(',', array_fill(0, count($skipped), '?'));
        $types = str_repeat('s', count($skipped));
        $look = $db->prepare(
            "SELECT s.SongId, s.Title, s.SongbookAbbr, sb.Name AS SongbookName
               FROM tblSongs s LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
              WHERE s.SongId IN ({$ph})"
        );
        $look->bind_param($types, ...$skipped);
        $look->execute();
        $byId = [];
        $lr = $look->get_result();
        while ($r = $lr->fetch_assoc()) { $byId[(string)$r['SongId']] = $r; }
        $look->close();

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', 'skipped-songids-job-' . $jobId . '-' . pathinfo((string)$row['Filename'], PATHINFO_FILENAME) . '.csv') ?? 'skipped-songids.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo "\xEF\xBB\xBF";   // UTF-8 BOM for Excel
        $out = fopen('php://output', 'w');
        ihymns_fputcsv($out, ['SongId', 'Title', 'SongbookAbbr', 'SongbookName', 'Reason']);
        foreach ($skipped as $sid) {
            $sid = (string)$sid; $r = $byId[$sid] ?? null;
            ihymns_fputcsv($out, [$sid, $r ? (string)$r['Title'] : '', $r ? (string)$r['SongbookAbbr'] : '', $r ? (string)$r['SongbookName'] : '', 'existing-in-db']);
        }
        fclose($out);
        exit;   // CSV already streamed — don't fall through to JSON
    }

    /* ---- bulk_verify (POST) — set/clear the Verified flag on many songs in one
           transaction. Activity-logged once (no per-song revision: a bulk flag
           flip is audited via the log, not the per-song content history). ---- */
    case 'bulk_verify': {
        $rawIds = is_array($body['songIds'] ?? null) ? $body['songIds'] : [];
        $ids = [];
        foreach ($rawIds as $x) { $s = trim((string)$x); if ($s !== '') { $ids[] = $s; } }
        if (!$ids) { ed2_respond(['ok' => false, 'error' => 'songIds are required.'], 400); }
        $val = isset($body['verified']) ? (int)((bool)$body['verified']) : 1;
        $db->begin_transaction();
        try {
            $u = $db->prepare('UPDATE tblSongs SET Verified = ? WHERE SongId = ?');
            foreach ($ids as $sid) { $u->bind_param('is', $val, $sid); $u->execute(); }
            $u->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.bulk_verify', 'song', '', ['count' => count($ids), 'verified' => $val]);
        ed2_respond(['ok' => true, 'count' => count($ids), 'verified' => $val]);
        break;
    }

    /* ---- bulk_tag_attach (POST) — attach one tag (auto-created) to many songs in
           one transaction. INSERT IGNORE skips already-tagged or missing songs. ---- */
    case 'bulk_tag_attach': {
        $rawIds = is_array($body['songIds'] ?? null) ? $body['songIds'] : [];
        $ids = [];
        foreach ($rawIds as $x) { $s = trim((string)$x); if ($s !== '') { $ids[] = $s; } }
        $name = ed2_normalizeTag((string)($body['name'] ?? ''));
        if (!$ids || $name === '') { ed2_respond(['ok' => false, 'error' => 'songIds + a tag name are required.'], 400); }
        $slug = ed2_tagSlug($name);
        if ($slug === '') { ed2_respond(['ok' => false, 'error' => 'Tag name has no usable characters.'], 400); }

        $db->begin_transaction();
        try {
            $ins = $db->prepare('INSERT INTO tblSongTags (Name, Slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE Id = LAST_INSERT_ID(Id), Name = ?');
            $ins->bind_param('sss', $name, $slug, $name);
            $ins->execute();
            $tagId = (int)$db->insert_id;
            $ins->close();

            $map = $db->prepare('INSERT IGNORE INTO tblSongTagMap (SongId, TagId, TaggedBy) VALUES (?, ?, ?)');
            $attached = 0;
            foreach ($ids as $sid) {
                $map->bind_param('sii', $sid, $tagId, $ed2UserId);
                $map->execute();
                if ($db->affected_rows > 0) { $attached++; }
            }
            $map->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.bulk_tag', 'song', '', ['tag' => $name, 'count' => count($ids), 'attached' => $attached]);
        ed2_respond(['ok' => true, 'tag' => ['id' => $tagId, 'name' => $name, 'slug' => $slug], 'attached' => $attached, 'count' => count($ids)]);
        break;
    }

    /* ---- credit_search (GET) — autocomplete for credit names. UNIONs the five
           song-credit tables (grouped by name → combined usage count + the roles
           it appears in) + the tblCreditPeople registry for kind=any. Table +
           label fragments come ONLY from the hardcoded allow-list (CLAUDE.md #5);
           the query term is bound. ---- */
    case 'credit_search': {
        $q     = trim((string)($_GET['q'] ?? ''));
        $kind  = strtolower(trim((string)($_GET['kind'] ?? 'any')));
        $limit = max(1, min(20, (int)($_GET['limit'] ?? 12)));
        if ($q === '') { ed2_respond(['ok' => true, 'suggestions' => []]); }

        /* Allow-list: only these (label => table) pairs ever reach the SQL. */
        $kindToTable = [
            'writer'     => 'tblSongWriters',
            'composer'   => 'tblSongComposers',
            'arranger'   => 'tblSongArrangers',
            'adaptor'    => 'tblSongAdaptors',
            'translator' => 'tblSongTranslators',
            'artist'     => 'tblSongArtists',
        ];
        $tables = ($kind === 'any') ? $kindToTable : (isset($kindToTable[$kind]) ? [$kind => $kindToTable[$kind]] : []);
        if (!$tables) { ed2_respond(['ok' => false, 'error' => 'Unknown kind.'], 400); }

        $like = '%' . $q . '%';
        $parts = []; $params = []; $types = '';
        foreach ($tables as $label => $table) {
            $parts[]  = "SELECT Name, '{$label}' AS kindLabel, COUNT(*) AS cnt FROM `{$table}` WHERE Name LIKE ? GROUP BY Name";
            $params[] = $like; $types .= 's';
        }
        if ($kind === 'any') {
            $parts[]  = "SELECT Name, 'registry' AS kindLabel, 0 AS cnt FROM tblCreditPeople WHERE Name LIKE ?";
            $params[] = $like; $types .= 's';
        }
        $sql = 'SELECT u.Name, GROUP_CONCAT(DISTINCT u.kindLabel) AS kinds, SUM(u.cnt) AS usageCount
                  FROM (' . implode(' UNION ALL ', $parts) . ') u
                 GROUP BY u.Name ORDER BY usageCount DESC, u.Name ASC LIMIT ?';
        $types .= 'i'; $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'name'  => (string)$row['Name'],
                'usage' => (int)$row['usageCount'],
                'kinds' => $row['kinds'] !== null ? explode(',', (string)$row['kinds']) : [],
            ];
        }
        $stmt->close();
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- user_search (GET) — live-search users by display name / username
           (#498; ported into api2 by #1629 ahead of v1 api.php's retirement).
           Powers the /manage/restrictions name-first picker
           (manage/includes/renderEntityPicker.js) resolving a human-friendly
           label ("Lance Manasse · @admin") to the canonical tblUsers.Id.

           ELI5: the admin types a few letters of someone's name and this
           hands back a short list of matching accounts to choose from.

           WHY IT LIVES HERE, NOT ON THE PUBLIC api.php: tblUsers isn't
           exposed there, and this endpoint needs the SAME admin/editor+
           gate api2.php already enforces (line ~113) — matching api.php's
           `hasRole($currentUser['role'], 'editor')` check exactly, so
           moving files doesn't change who can call it.

           NOT an editor feature: grep finds zero call sites in editor.js /
           index.php / editor2.php / v2/* — its only consumer is Content
           Restrictions (manage/restrictions.php). It rode along on the
           editor's api.php only because that was the nearest admin-gated,
           tblUsers-reading endpoint (#1629 corrects sibling issue #1609,
           which had assumed this WAS editor-only).

           Query + bind_param sequence are unchanged from v1 (verbatim
           port); the only deliberate change is dropping v1's local
           try/catch-swallow-to-empty-list in favour of api2's shared
           ed2_respond()/outer-catch convention (every other case in this
           file follows that pattern — a DB error surfaces as a real 500
           with error_detail for admins, rather than a silent empty list
           that looks like "no matches" to the curator). ---- */
    case 'user_search': {
        $q     = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));
        if ($q === '') { ed2_respond(['ok' => true, 'suggestions' => []]); }
        $like = '%' . $q . '%';
        $stmt = $db->prepare(
            'SELECT Id, DisplayName, Username, Role
               FROM tblUsers
              WHERE DisplayName LIKE ? OR Username LIKE ?
              ORDER BY DisplayName ASC
              LIMIT ?'
        );
        $stmt->bind_param('ssi', $like, $like, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'id'    => (int)$row['Id'],
                'label' => $row['DisplayName'] ?: $row['Username'],
                'hint'  => '@' . $row['Username'] . ' · ' . $row['Role'],
            ];
        }
        $stmt->close();
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- org_search (GET) — live-search organisations by name/slug (#498;
           ported into api2 by #1629 alongside user_search — same picker,
           same reasoning, same gate parity). The "organisation" source in
           renderEntityPicker.js's Content Restrictions picker.

           ELI5: same idea as user_search above, but for choosing an
           organisation instead of a user.

           DETAIL: an empty q still returns a page of active orgs (browse
           mode) instead of nothing — mirrors v1's behaviour so opening the
           picker with no query yet shows choices rather than an empty
           panel. Query + bind_param sequence unchanged from v1; same
           ed2_respond()/outer-catch adaptation noted above user_search. ---- */
    case 'org_search': {
        $q     = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        if ($q === '') {
            $stmt = $db->prepare(
                'SELECT Id, Name, Slug, LicenceType FROM tblOrganisations
                  WHERE IsActive = 1
                  ORDER BY Name ASC
                  LIMIT ?'
            );
            $stmt->bind_param('i', $limit);
        } else {
            $like = '%' . $q . '%';
            $stmt = $db->prepare(
                'SELECT Id, Name, Slug, LicenceType FROM tblOrganisations
                  WHERE IsActive = 1 AND (Name LIKE ? OR Slug LIKE ?)
                  ORDER BY Name ASC
                  LIMIT ?'
            );
            $stmt->bind_param('ssi', $like, $like, $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'id'    => (int)$row['Id'],
                'label' => $row['Name'],
                'hint'  => 'licence: ' . ($row['LicenceType'] ?: 'none') . ' · slug: ' . $row['Slug'],
            ];
        }
        $stmt->close();
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- load_index (GET) — the lightweight song list for the editor sidebar:
           id / number / title / songbook / songbookName (+ audio/sheet flags).
           Reuses SongData::getSongsSlimIndex() (the canonical slim index the PWA
           uses) — NEVER materialises the whole corpus (CLAUDE.md #17). ---- */
    case 'load_index': {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
        $songData = new SongData();
        ed2_respond(['ok' => true, 'songs' => $songData->getSongsSlimIndex()]);
        break;
    }

    /* ---- revision_list (GET) — revision history for a song, newest first
           (metadata only; the full NewData snapshot is fetched on restore). ---- */
    case 'revision_list': {
        $songId = trim((string)($_GET['songId'] ?? $_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        $limit = (int)($_GET['limit'] ?? 50);
        if ($limit < 1 || $limit > 200) { $limit = 50; }
        $rows = [];
        $q = $db->prepare(
            'SELECT r.Id, r.Action, r.CreatedAt, r.UserId, u.Username
               FROM tblSongRevisions r LEFT JOIN tblUsers u ON u.Id = r.UserId
              WHERE r.SongId = ? ORDER BY r.CreatedAt DESC, r.Id DESC LIMIT ?'
        );
        $q->bind_param('si', $songId, $limit);
        $q->execute();
        $res = $q->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'        => (int)$r['Id'],
                'action'    => (string)$r['Action'],
                'createdAt' => (string)$r['CreatedAt'],
                'userId'    => $r['UserId'] !== null ? (int)$r['UserId'] : null,
                'username'  => $r['Username'] !== null ? (string)$r['Username'] : null,
            ];
        }
        $q->close();
        ed2_respond(['ok' => true, 'revisions' => $rows]);
        break;
    }

    /* ---- revision_restore (POST) — restore the song to a revision's full
           snapshot (scalars + components + credits + tags + links), atomically,
           then record a forced 'restore' revision so the trail stays linear. ---- */
    case 'revision_restore': {
        $revisionId   = (int)($body['revisionId'] ?? 0);
        $expectSongId = trim((string)($body['songId'] ?? ''));   // optional defense-in-depth
        if ($revisionId <= 0) { ed2_respond(['ok' => false, 'error' => 'revisionId is required.'], 400); }

        $sel = $db->prepare('SELECT SongId, NewData FROM tblSongRevisions WHERE Id = ? LIMIT 1');
        $sel->bind_param('i', $revisionId);
        $sel->execute();
        $rev = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$rev) { ed2_respond(['ok' => false, 'error' => 'Revision not found.'], 404); }
        $songId = (string)$rev['SongId'];
        /* Guard against a client passing a revisionId from a different song. */
        if ($expectSongId !== '' && $expectSongId !== $songId) {
            ed2_respond(['ok' => false, 'error' => 'Revision does not belong to the expected song.'], 409);
        }
        $snap = $rev['NewData'] !== null ? json_decode((string)$rev['NewData'], true) : null;
        if (!is_array($snap)) { ed2_respond(['ok' => false, 'error' => 'This revision has no snapshot to restore.'], 409); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        $db->begin_transaction();
        try {
            ed2_applySongSnapshot($db, $songId, $snap);
            ed2_touchRevision($db, $songId, $ed2UserId, 'restore', true);   // force — always audit a restore
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.revision.restore', 'song', $songId, ['fromRevisionId' => $revisionId]);
        ed2_respond(['ok' => true, 'songId' => $songId]);
        break;
    }

    /* ---- save_song (POST) — the legacy whole-song save, now SHARED ---- */
    /* The Song Editor's primary save path. Extracted VERBATIM into the shared
       editorSaveSongCore() (#1200) so this v2 API and the legacy api.php run the
       SAME save logic — the migration off the legacy api.php that does NOT risk
       the primary save path by re-implementing it. The CSRF gate + auth/role
       checks above already protect this POST; the core returns the HTTP status +
       body, which ed2_respond() emits in the v2 house style. */
    case 'save_song': {
        require_once __DIR__ . '/save_song_core.php';
        $r = editorSaveSongCore();
        ed2_respond($r['body'], $r['status']);
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
