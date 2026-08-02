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
 *   GET  load_index                                         -> { ok, songs, songbooks }
 *                               songs = the slim sidebar index; songbooks =
 *                               [{abbr,name}] for EVERY book incl. empty ones
 *                               (#1679 A2 — the move target list cannot come
 *                               from the song index, or a new book is unreachable)
 *   GET  easyworship_export?id=<SongId>|abbr=<BOOK>[&maxLinesPerSlide=N] -> streams an EasyWorship Songs.db (#1059/#1678)
 *   POST bulk_verify            { songIds:[...], verified? }  -> { ok, count, verified }
 *   POST bulk_tag_attach        { songIds:[...], name }       -> { ok, tag, attached, count }
 *   GET  load_song?id=<SongId>                              -> { ok, song, components, credits, tags, links, media, … }
 *                               credits[role][] now carries first/surname/suffix
 *                               alongside {id,name} (#960 plan §4 item 3) — the
 *                               registry's curated parts when known, else a
 *                               server-side decomposePersonName() fallback; never
 *                               decomposed client-side (musicianNamePartsColumnsExist()-
 *                               gated, so an un-migrated install still gets {id,name} only).
 *   POST create_song            { songbook, title? }        -> { ok, songId }
 *   POST delete_song            { songId, reason?, note? }  -> { ok, deleted, softDeleted }
 *                               SOFT delete since #1694 — hides the song
 *                               (restorable at /manage/deleted-songs); the
 *                               destructive purge is songPurge(), admin-only,
 *                               reachable only from the deleted state.
 *                               redirectTo is accepted-and-ignored (relink
 *                               happens at purge). 409 = un-migrated /
 *                               already deleted; 422 = unknown reason.
 *   POST metadata_field_update  { songId, field, value }    -> { ok }
 *                               field=songbook RE-KEYS the SongId (#1679) and
 *                               answers { ok, field, songId, previousId }
 *   POST component_upsert       { songId, component:{id?,type,number,sortOrder,lines[],chords?,language?} } -> { ok, componentId }
 *   POST component_delete       { songId, componentId }     -> { ok }
 *   POST component_reorder      { songId, order:[id,...] }  -> { ok }
 *   POST components_replace     { songId, components:[...], mode? } -> { ok, count, components }
 *   POST credit_upsert          { songId, role, credit:{id?, name?, first?, surname?, suffix?} }
 *                               -> { ok, creditId, name, first, surname, suffix, registryPersonId }
 *                               Accepts EITHER the legacy flat {name} shape (what the current
 *                               UI still sends) OR the structured {first,surname,suffix} shape
 *                               (creditEntryNormalise() reassembles/decomposes either way).
 *                               Promotes the name into tblMusicians in the SAME transaction
 *                               as the role-table write (#960) — the response echoes back the
 *                               REGISTRY's parts, never the caller's input (D2: the backfill
 *                               never overwrites a curated value, so echoing input would show a
 *                               silent no-op as if it had saved).
 *   POST credit_delete          { songId, role, creditId }  -> { ok }
 *   GET  credit_search?q=&kind=&limit=                       -> { ok, suggestions }
 *   GET  user_search?q=&limit=                                -> { ok, suggestions }  (#1629 — Content Restrictions picker, NOT an editor feature; ported off v1)
 *   GET  org_search?q=&limit=                                 -> { ok, suggestions }  (#1629 — same picker, "organisation" source)
 *   GET  tag_list?id=<SongId>                                -> { ok, tags }
 *   GET  tag_search?q=&limit=                                -> { ok, suggestions }
 *   POST tag_attach             { songId, name }             -> { ok, tag, attached }
 *   POST tag_detach             { songId, tagId }            -> { ok, removed }
 *   POST link_save_all          { songId, links:[{typeId,url,note?,verified?}] } -> { ok, count, links }
 *   GET  song_links?id=<SongId>                              -> { ok, groupId, links }
 *                               Cross-book counterpart group ("same hymn, different
 *                               songbook, different number") — ported from the
 *                               legacy get_song_links (#1608). groupId=0 means the
 *                               song isn't grouped yet; `links` lists every OTHER
 *                               member of the group.
 *   POST song_link_add          { sourceSongId, targetSongId, note? }
 *                               -> { ok, groupId, created? | extended? | noop? }
 *                               Mints a new group / extends an existing one / no-ops
 *                               if already linked. 409 when the two songs are
 *                               already in DIFFERENT groups (unlink one first, or
 *                               use the Merge tool at /manage/duplicate-songs, which
 *                               stays the ONLY merge surface — this endpoint never
 *                               grows one). Entitlement: edit_songs.
 *   POST song_link_remove       { id?, songId? }              -> { ok, deleted }
 *                               Drops one song from its counterpart group; a
 *                               resulting singleton group is cleaned up too.
 *                               Already-gone -> {ok:true, deleted:0}, not an error
 *                               (idempotent double-click, matches v1). Entitlement:
 *                               edit_songs.
 *   GET  song_link_suggestions?id=<SongId>                   -> { ok, suggestions, tableMissing? }
 *                               Up to 5 highest-scoring PENDING pairs involving this
 *                               song, read from the pre-scored tblSongLinkSuggestions
 *                               table the offline batch build-song-link-suggestions.php
 *                               fills — that batch is the ONLY consumer of
 *                               includes/song_similarity.php's scorer; this endpoint
 *                               never scores live (CLAUDE.md rule #22).
 *                               tableMissing:true on an un-migrated install (empty
 *                               suggestions, not a 500).
 *   POST song_link_suggestion_dismiss { songIdA, songIdB, reason? } -> { ok }
 *                               Canonicalises (SongIdA < SongIdB) server-side, records
 *                               a permanent dismissal in tblSongLinkSuggestionsDismissed
 *                               — the SAME table /manage/duplicate-songs writes, so a
 *                               dismissal from either surface suppresses the pair in
 *                               both — and drops the matching pending suggestion row.
 *                               Entitlement: edit_songs.
 *   GET  media_list?id=<SongId>                              -> { ok, media }
 *   POST media_upload  (MULTIPART: songId, kind, annotation?, file) -> { ok, media }
 *   POST media_update           { mediaId, annotation }      -> { ok, mediaId }
 *   POST media_delete           { mediaId }                  -> { ok, deleted, songId }
 *   POST media_reorder          { songId, kind, ids:[...] }  -> { ok, reordered }
 *   POST import_file   (MULTIPART: file, format=auto|videopsalm|openlp|opensong|pro6|proclaim|freeshow|chordpro|pptx|easyworship, dedupeMode?) -> { ok, songs_created, ... }
 *     format=auto on a .xml/.opensong upload resolves via the shared XML
 *     auto-router (_bulkImport_processXmlAuto(), #882) — it sniffs
 *     OpenLyrics vs OpenSong and tries the other parser once on a primary
 *     parse failure; the response's top-level `format` echoes back the
 *     format that actually parsed.
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
/* #1627 — the ONE ArrangementJson rule, shared with gate G4 (#1618) and the
   write side. arrangement_update below must never persist ordinals the gate
   would reject; sharing the validator is what makes that structurally true
   rather than a thing two files happen to agree on today. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'arrangement.php';
/* #1679 — the songbook-move re-key helper, PLUS the two cross-cutting predicates
   this file needs OUTSIDE the move branch: songRelocateIdTaken() (the shared
   "is this id claimed by tblSongs OR a live redirect?" check ed2_allocateSongId
   uses, A9) and songRelocateIsTransactionFatal() (the ONE list of MySQL errors
   that have already rolled the caller's transaction back, A1). Loaded at module
   scope rather than lazily inside the move case, because both are wanted by code
   paths that have nothing to do with a move. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_relocate.php';
/* #1742 — songbookRecomputeSongCount(): the ONE shared tblSongbooks.SongCount
   recompute (includes/songbook_count.php). create_song below is the funnel
   that was missing it entirely — on iHymns' shared-hosting deployment target
   (no CREATE TRIGGER privilege) that left a brand-new songbook's home tile
   permanently hidden behind a stale SongCount = 0. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_count.php';
/* #960 — creditEntryNormalise() / musicianPromote(): the shared
   normalise-and-registry-promote pair every credit write path (this file's
   credit_upsert + revision_restore, the legacy whole-song save, and
   lyrics_ingest.php) now delegates to, so v2's granular credit saves stop
   silently skipping tblMusicians (the #960 regression). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_helpers.php';

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

/**
 * Refuse the request unless the signed-in role holds $key (#1590, E1).
 *
 * ELI5: /manage/entitlements has checkboxes for "Delete songs" and "Bulk-edit
 * songs". Nothing used to read them, so ticking or unticking one did nothing.
 * This is the function that makes those two checkboxes real on this API.
 *
 * WHY A HELPER RATHER THAN FOUR INLINE `if`s: four copies of a gate are four
 * chances for one of them to drift (CLAUDE.md's modularity rule; the same
 * reasoning that produced ed2_respond()). One place to read, one place to fix.
 *
 * EQUIVALENCE — this adds NO live restriction. The guard above already requires
 * `hasRole($role, 'editor')`, i.e. exactly {editor, admin, global_admin}, and
 * the default maps for both `delete_songs` and `bulk_edit_songs` are now that
 * same set (aligned to reality this pass — see includes/entitlements.php). The
 * only new behaviour is that an operator's REVOCATION is finally honoured.
 *
 * @param string $key Entitlement key, e.g. 'delete_songs'
 * @see appWeb/public_html/includes/entitlements.php
 */
function ed2_requireEntitlement(string $key): void
{
    global $currentUser;
    if (!userHasEntitlement($key, $currentUser['role'] ?? null)) {
        ed2_respond(['ok' => false, 'error' => 'The ' . $key . ' entitlement is required.'], 403);
    }
}

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
    /* @deleted-visible: write-path existence check (#1694) — "does the row
       exist?" is an FK/identity question; a soft-deleted row exists, and a
       write into it is harmless and restore-preserving. */
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

    /* @deleted-visible: id MINT SEED (#1694) — a soft-deleted song keeps its
       SongId reserved (the row still holds the UNIQUE key), so the sequence
       seed must see it or the mint would propose a taken id and 500 on the
       duplicate key. songRelocateIdTaken() below is unfiltered for the same
       reason. */
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

    /* Skip any already-taken value (a numbered song could occupy the range).
       #1679 A9 — "taken" is the SHARED songRelocateIdTaken(), which also asks
       tblSongRedirects. This loop used to probe tblSongs only, so a brand-new
       song could be handed an id that a live redirect still forwards away from;
       getSongById() matches exactly before it consults the redirect layer, so an
       old bookmark then resolves to a DIFFERENT song — 200 OK with the wrong
       content, which is worse than the 404 the redirect existed to prevent.
       The 6-digit tail here (vs the mint's 4) is why the CHECK is shared and the
       loop is not. */
    for ($i = 0; $i < 8; $i++) {
        $candidate = sprintf('%s-%06d', $abbr, $next);
        if (!songRelocateIdTaken($db, $candidate)) { return $candidate; }
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
    /* @deleted-visible: editor load + revision snapshot (#1694) — a curator
       repairing or reviewing a hidden song's record must still be able to
       load it by direct id; discovery goes dark instead (the sidebar's
       getSongsSlimIndex() is filtered — restore-first workflow). */
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
    $creditNames = [];   // distinct names credited on this song, any role
    foreach (ED2_CREDIT_TABLES as $role => $table) {
        $credits[$role] = [];
        $q = $db->prepare("SELECT Id, Name FROM `{$table}` WHERE SongId = ? ORDER BY Id ASC");
        $q->bind_param('s', $songId);
        $q->execute();
        $qr = $q->get_result();
        while ($row = $qr->fetch_assoc()) {
            $credits[$role][] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name']];
            $creditNames[(string)$row['Name']] = true;
        }
        $q->close();
    }

    /* #960 (plan §4 item 3, closing the gap left by e430dfbc) — attach
       first/surname/suffix to every credit BEFORE it reaches the client.
       ELI5: when the editor opens a song, each Writer/Composer/etc. name
       needs to arrive already split into its three boxes — the browser is
       never allowed to guess the split itself.
       DETAILED / WHY: credit_upsert (the SAVE path) has returned the
       registry's authoritative parts since e430dfbc, but this function
       (the LOAD path — load_song AND the revision-snapshot builder) still
       emitted {id, name} only. A v2 UI built to "never decompose a name in
       JS, only display what the server sends" (the explicit #960 design —
       see musician_helpers.php's doc-block) would therefore render
       every EXISTING credit's First/Surname/Suffix fields blank on open,
       which is indistinguishable from data loss to a curator. One batch
       SELECT covers every distinct name credited on this song (rule #5:
       placeholders built via array_fill, never string-interpolated
       values); a registry row with any non-empty curated part wins,
       otherwise decomposePersonName() — the SAME heuristic
       creditEntryNormalise() already applies to a flat-name credit_upsert
       — supplies a same-shaped fallback split, never a second drifting
       client-side copy of that maths. Gated on
       musicianNamePartsColumnsExist() so an un-migrated install keeps
       emitting {id, name} exactly as before this change. */
    if ($creditNames && musicianNamePartsColumnsExist($db)) {
        $names        = array_keys($creditNames);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $rp = $db->prepare("SELECT Name, FirstNames, Surname, Suffix FROM tblMusicians WHERE Name IN ({$placeholders})");
        $rp->bind_param(str_repeat('s', count($names)), ...$names);
        $rp->execute();
        $rpr = $rp->get_result();
        $registryParts = [];
        while ($row = $rpr->fetch_assoc()) {
            $registryParts[(string)$row['Name']] = [
                (string)($row['FirstNames'] ?? ''),
                (string)($row['Surname']    ?? ''),
                (string)($row['Suffix']     ?? ''),
            ];
        }
        $rp->close();
        foreach ($credits as $role => $list) {
            foreach ($list as $i => $c) {
                $reg = $registryParts[$c['name']] ?? null;
                [$first, $surname, $suffix] = ($reg !== null && ($reg[0] !== '' || $reg[1] !== '' || $reg[2] !== ''))
                    ? $reg
                    : decomposePersonName($c['name']);
                $credits[$role][$i]['first']   = $first;
                $credits[$role][$i]['surname'] = $surname;
                $credits[$role][$i]['suffix']  = $suffix;
            }
        }
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
 *
 * ONE DELIBERATE EXCLUSION — `SongbookAbbr` (#1679).
 *
 * ELI5: restoring an old version of the words must not silently pick the song up
 * and drop it back into a songbook it left.
 *
 * Detail: the abbreviation IS the SongId prefix (rule #27). A restore writes
 * SCALARS only — it cannot re-key the id, cascade the ~25 FK children or leave a
 * permalink redirect — so restoring an old `SongbookAbbr` would recreate exactly
 * the SongId↔SongbookAbbr mismatch #1679 exists to kill, as a SIDE EFFECT of an
 * action the curator asked for on a completely different field. A restore
 * therefore keeps the song's CURRENT home; moving books stays an explicit act
 * (`metadata_field_update` with field=songbook → songRelocate()).
 */
function ed2_applySongSnapshot(\mysqli $db, string $songId, array $snap): void {
    /* A v2 full snapshot has a 'song' key; an old scalar-only snapshot IS the row. */
    $songRow = is_array($snap['song'] ?? null) ? $snap['song'] : $snap;

    /* Scalars — only the allow-listed editable columns (same coercion as
       metadata_field_update). */
    foreach (ED2_META_FIELDS as $field => [$column, $type]) {
        /* #1679 — never restore the songbook (see the doc-block above). */
        if ($column === 'SongbookAbbr') { continue; }
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

        /* ArrangementJson — restore it TOO (#1627).
         *
         * ELI5: the running order is part of the song, so restoring an old
         * version has to bring the running order back with it.
         *
         * Detail: this column is not in ED2_META_FIELDS (nothing in v2 could
         * write it until arrangement_update, above), so the scalar loop skips
         * it — yet the snapshot row is a `SELECT *` and has carried the value
         * all along. Restore therefore rebuilt every section and silently left
         * the arrangement pointing at the CURRENT set. Harmless while no v2
         * editor could create one; a live data-loss bug the moment one can.
         *
         * Deliberately inside the components branch: ordinals index the section
         * list, so restoring an order without restoring the sections it indexes
         * is how you get ordinals pointing past the end. Re-validated against
         * the snapshot's own component count through the shared rule rather
         * than trusted — an old revision may predate a section being deleted,
         * and an out-of-range ordinal must clear to NULL, never be written back
         * for gate G4 to find later. */
        if (arrangementColumnExists($db)) {
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'arrangement.php';
            $arrRaw = $songRow['ArrangementJson'] ?? null;
            $arrOrd = is_string($arrRaw) ? json_decode($arrRaw, true) : $arrRaw;
            $arrJson = arrangementSanitise($arrOrd, count($snapComps));
            $ua = $db->prepare('UPDATE tblSongs SET ArrangementJson = ? WHERE SongId = ?');
            $ua->bind_param('ss', $arrJson, $songId);
            $ua->execute();
            $ua->close();
        }
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
                    /* #960 — normalise + promote, same as every other credit
                       write path (credit_upsert, save_song). A restore can
                       resurrect a name whose registry row was since merged
                       or deleted away; without this the role table comes
                       back but the person page/registry stays gone. */
                    $entry = creditEntryNormalise($credit);
                    if ($entry === null) { continue; }
                    $name = mb_substr($entry['name'], 0, 255);
                    $ci->bind_param('ss', $songId, $name);
                    $ci->execute();
                    musicianPromote($db, $name, [
                        'first'   => $entry['first'],
                        'surname' => $entry['surname'],
                        'suffix'  => $entry['suffix'],
                    ]);
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
        /* #1679 A1 — the ONE exception this must NOT swallow. Every caller runs
           this INSIDE its own transaction, immediately before commit(); a MySQL
           error that already rolled that transaction back (deadlock victim) turns
           the swallow into a FALSE SUCCESS — commit() then succeeds trivially and
           the endpoint answers ok:true naming a songId that does not exist. The
           songbook-move case is the sharpest instance: songRelocate() carefully
           re-throws those codes from its own best-effort step, and this catch,
           two lines later, ate them again. The test is the shared predicate, not
           a copy of the code list (rule #35). */
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
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

        /* #1742 — recompute the book's cached tblSongbooks.SongCount now the new
           song is committed. On a shared host with no CREATE TRIGGER privilege
           (iHymns' deployment target) this app-side recompute is the ONLY thing
           that keeps the songbook tile's count correct; on trigger-capable hosts
           it is a harmless idempotent re-count. Best-effort and post-commit: the
           song already exists, so a recount failure self-heals on the next pass
           and must never fail the create. */
        try {
            songbookRecomputeSongCount($db, $abbr);
        } catch (\Throwable $_e) {
            error_log('[editor create_song] SongCount recompute failed: ' . $_e->getMessage());
        }

        logActivity('song.create', 'song', $songId, ['title' => $title, 'songbook' => $abbr]);
        ed2_respond(['ok' => true, 'songId' => $songId, 'title' => $title, 'songbook' => $abbr]);
        break;
    }

    /* ---- delete_song (POST) — SOFT delete since #1694 commit 4. The old
           hard-delete cascade body moved VERBATIM into songPurge()
           (includes/song_soft_delete.php), reachable only from the deleted
           state on /manage/deleted-songs. This endpoint now hides the song:
           IsDeleted = 1 + who/when/why, restorable by an admin. ---- */
    case 'delete_song': {
        ed2_requireEntitlement('delete_songs');
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $songId = trim((string)($body['songId'] ?? $body['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        /* `redirectTo` is ACCEPTED AND IGNORED (#1694, deliberately — not
           silently dropped: the response says so below). A soft delete writes
           NOTHING to tblSongRedirects, because a songRedirectRepoint() cannot
           be un-written and restore must be a no-op on redirect state (the
           #1679 stranded-chain class). The relink happens at PURGE, where the
           old, well-tested redirect dance runs unchanged. */
        $redirectTo = trim((string)($body['redirectTo'] ?? ''));
        /* Optional vocabulary the UI may grow into: a songDeleteReasons() key
           + a free-text note. Unknown reasons refuse with 422 (rule #20's
           allow-list; status is the contract, rule #35). */
        $reason = trim((string)($body['reason'] ?? ''));
        $note   = trim((string)($body['note'] ?? ''));

        $verdict = songSoftDelete($db, $songId, $ed2UserId, $reason === '' ? null : $reason, $note);
        if (!$verdict['ok']) {
            /* 400 empty id / 404 absent / 409 un-migrated or already deleted /
               422 unknown reason — relayed as-is; clients branch on STATUS. */
            ed2_respond(['ok' => false, 'error' => $verdict['error'], 'deleted' => 0], $verdict['status']);
        }
        logActivity('song.soft_delete', 'song', $songId, [
            'title'    => (string)($verdict['title'] ?? ''),
            'songbook' => (string)($verdict['songbook'] ?? ''),
            'reason'   => $reason === '' ? null : $reason,
            'note'     => $note,
        ]);
        ed2_respond([
            'ok'          => true,
            'deleted'     => 1,               /* back-compat: the one song left the catalogue */
            'softDeleted' => true,            /* NEW — restorable from /manage/deleted-songs */
            'songId'      => $songId,
            'redirectTo'  => null,            /* no redirect is ever written at soft-delete time */
        ] + ($redirectTo !== '' ? [
            'notice' => 'redirectTo is ignored on a soft delete — choose the relink target when the song is purged from /manage/deleted-songs (#1694).',
        ] : []));
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

        /* ---- #1679 — SONGBOOK MOVE is not a scalar update ----------------
           `tblSongbooks.Abbreviation` IS the SongId prefix (rule #27), so
           writing SongbookAbbr through the generic UPDATE below would leave the
           id claiming the OLD book forever. Branch out to the shared re-key
           helper instead: it mints the new id, cascades every child row, clears
           Number, rewrites BOTH non-FK soft references (the content-restriction
           rows and the tblSongbookEntries home row — #1679 F3 found the second
           one; this comment named only the first until A13d) and leaves a
           tblSongRedirects row so old permalinks keep resolving.
           The generic path opens no transaction (a single UPDATE needs none) —
           a move is several statements, so it gets its own. */
        if ($column === 'SongbookAbbr') {
            $targetAbbr = trim((string)($raw ?? ''));
            if ($targetAbbr === '') {
                ed2_respond(['ok' => false, 'error' => 'A target songbook abbreviation is required.'], 400);
            }
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_relocate.php';
            $db->begin_transaction();
            try {
                $rel = songRelocate($db, $songId, $targetAbbr, $ed2UserId);
                ed2_touchRevision($db, $rel['songId'], $ed2UserId, 'metadata');
                $db->commit();
            } catch (\InvalidArgumentException $e) {
                /* The abbreviation is a free-text field, so a typo is ordinary
                   user input, not a server fault — 422, with the reason. Caught
                   SEPARATELY from \Throwable because mysqli_sql_exception is a
                   RuntimeException: a single broad catch would report a real
                   database failure as "you typed the wrong book". Clients branch
                   on the STATUS, never on this sentence (rule #35). */
                $db->rollback();
                ed2_respond(['ok' => false, 'error' => $e->getMessage()], 422);
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }
            logActivity('song.metadata', 'song', $rel['songId'], [
                'field'         => $field,
                'songbook'      => $targetAbbr,
                'previous_book' => $rel['previousSongbookAbbr'],
                'previous_id'   => $rel['previousId'],
                'renamed'       => $rel['renamed'],
            ]);
            /* `previousId` !== `songId` is the client's signal to re-open the song
               under its new id (the shell re-keys its in-memory id + the ?song=
               URL). Always present so the client has one thing to compare, and
               equal on a no-op move. */
            ed2_respond([
                'ok'         => true,
                'field'      => $field,
                'songId'     => $rel['songId'],
                'previousId' => $rel['previousId'],
            ]);
        }

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

    /* ---- arrangement_update (POST) — the song's running order (#161 / #1627).
     *
     * ELI5: saves "play section 0, then 1, then 1 again, then 2".
     *
     * Body: { songId, arrangement: [0,1,1,2] }   — null or [] CLEARS it.
     * ->    { ok, songId, arrangement }          — the stored value, echoed back.
     *
     * WHY THIS EXISTS
     * v1 persisted ArrangementJson through its whole-song save (save_song_core.php:303).
     * v2 has no such save — every edit is granular — and ED2_META_FIELDS cannot
     * write this column, so no v2 endpoint touched it at all. #1601 would have
     * made existing arrangements READ-ONLY FOSSIL DATA: the public render and
     * every export format keep consuming them, but nobody could ever create or
     * change one again. The admin dashboard advertises "arrangements" throughout.
     *
     * VALIDATION IS THE SHARED ONE, deliberately. #1618's gate G4 audits stored
     * ordinals; this endpoint uses the SAME includes/arrangement.php rule, so the
     * editor cannot manufacture data that later fails the cutover verification
     * and blocks the C6 drop.
     *
     * THE 422 IS THE SUBTLE PART. G4 — and the public render — index the
     * ASSEMBLED component list, while the editor indexes the EDITABLE one. Those
     * are equal except when a zero-line component exists (reachable via
     * components_replace with lines: []), where the editable list is longer. So
     * ordinals are validated against the ASSEMBLED count, and while the two
     * counts diverge we refuse to store a non-null arrangement at all rather
     * than write ordinals that mean one thing to the editor and another to the
     * renderer. Clearing is always allowed — you must be able to get out of that
     * state.
     *
     * CSRF: covered by the file-level same-origin POST gate above (#1307). Auth:
     * the file-level isAuthenticated() + editor role, exactly what protected
     * v1's save_song write of this same column — no new entitlement invented. ---- */
    case 'arrangement_update': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        /* Migrations are web-run, not applied on deploy, and mysqli is STRICT —
           touching a missing column throws. Probe, don't assume (rule #19). */
        if (!arrangementColumnExists($db)) {
            ed2_respond(['ok' => false, 'error' => 'The ArrangementJson migration has not been run on this install.'], 409);
        }

        $raw = $body['arrangement'] ?? null;
        if ($raw !== null && !is_array($raw)) {
            ed2_respond(['ok' => false, 'error' => 'arrangement must be an array of section indexes, or null to clear.'], 400);
        }

        $json = null;
        if ($raw !== null && $raw !== []) {
            /* lyric_lines_read.php is required LAZILY inside ed2_currentComponents()
               and its sibling, not at the top of this file. Calling
               ed2_currentComponents() first happens to load it before
               lyricLinesMirrorPresent() is reached below — but depending on that
               ordering is the kind of thing a later refactor breaks silently, with
               a fatal "undefined function" only on the branch that reorders. State
               the dependency; require_once is idempotent and cheap. */
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';

            $nEditable  = count(ed2_currentComponents($db, $songId));
            $nAssembled = lyricLinesMirrorPresent($db)
                ? count(lyricLinesAssembleComponents($db, $songId))
                : $nEditable;

            if ($nAssembled !== $nEditable) {
                ed2_respond([
                    'ok'    => false,
                    'error' => 'This song has empty sections, so section numbering differs between the '
                             . 'editor and the published song. Remove them before setting an arrangement.',
                ], 422);
            }

            $viol = arrangementViolations(array_values($raw), $nAssembled);
            if ($viol !== []) {
                $first = $viol[0];
                $msg = $first['reason'] === 'malformed'
                    ? 'arrangement must be an array of section indexes.'
                    : 'arrangement[' . ($first['position'] ?? 0) . '] = '
                      . json_encode($first['value'] ?? null)
                      . ' is not a valid section index (this song has ' . $nAssembled . ' sections).';
                ed2_respond(['ok' => false, 'error' => $msg], 400);
            }

            $json = arrangementSanitise(array_values($raw), $nAssembled);
        }

        $db->begin_transaction();
        try {
            $up = $db->prepare('UPDATE tblSongs SET ArrangementJson = ? WHERE SongId = ?');
            $up->bind_param('ss', $json, $songId);
            $up->execute();
            $up->close();
            ed2_touchRevision($db, $songId, $ed2UserId, 'arrangement');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.arrangement', 'song', $songId, ['length' => $json === null ? 0 : count(json_decode($json, true) ?: [])]);
        /* Echo the STORED value, not the request, so the client's local copy can
           only ever hold what the server actually kept. */
        ed2_respond(['ok' => true, 'songId' => $songId, 'arrangement' => $json === null ? null : json_decode($json, true)]);
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

    /* ---- credit_upsert (POST) — { role, credit:{id?, name?, first?, surname?, suffix?} } ---- */
    case 'credit_upsert': {
        $songId = trim((string)($body['songId'] ?? ''));
        $role   = (string)($body['role'] ?? '');
        $credit = is_array($body['credit'] ?? null) ? $body['credit'] : [];
        if ($songId === '' || !isset(ED2_CREDIT_TABLES[$role]) || !$credit) {
            ed2_respond(['ok' => false, 'error' => 'songId + a known role + credit are required.'], 400);
        }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        $table    = ED2_CREDIT_TABLES[$role];     // from the allow-list constant only
        $creditId = isset($credit['id']) ? (int)$credit['id'] : 0;

        /* #960 — accept EITHER shape: the legacy/flat {name} the current UI
           still sends, or the structured {name?,first?,surname?,suffix?}
           shape a future 3-field UI sends. creditEntryNormalise() is the
           SAME function the whole-song save uses (includes/musicians_
           helpers.php) — this endpoint never re-forks the decompose/compose
           logic. */
        $entry = creditEntryNormalise($credit);
        if ($entry === null) { ed2_respond(['ok' => false, 'error' => 'credit name is required.'], 400); }
        $name = mb_substr($entry['name'], 0, 255);

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
            /* #960 — the ACTUAL regression fix: promote the name into the
               tblMusicians registry in the SAME transaction as the
               role-table write, so a v2 credit save can never leave the two
               out of sync (the pre-#960 bug: this endpoint wrote Name-only
               to the role table and never touched the registry at all). */
            $registryPersonId = musicianPromote($db, $name, [
                'first'   => $entry['first'],
                'surname' => $entry['surname'],
                'suffix'  => $entry['suffix'],
            ]);
            ed2_touchRevision($db, $songId, $ed2UserId, 'credit');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        /* D2 — echo the REGISTRY's parts, never the caller's normalised
           input. musicianPromote()'s backfill is
           COALESCE(NULLIF(col,''),?) — it NEVER overwrites an existing
           curated value, so when the registry already held different parts
           than this request sent, the write above was a silent no-op for
           those columns. Echoing the input back would show the curator
           their own text while the DB kept the old value, and the very next
           load would silently revert it — a rule #30 "looks alive, isn't"
           failure. Gated on musicianNamePartsColumnsExist(): on an
           un-migrated install there are no registry parts to read back, so
           fall back to the entry's own (decompose-derived) parts — there is
           no curated value that fallback could ever shadow. */
        if (musicianNamePartsColumnsExist($db)) {
            $reg = $db->prepare('SELECT FirstNames, Surname, Suffix FROM tblMusicians WHERE Id = ?');
            $reg->bind_param('i', $registryPersonId);
            $reg->execute();
            $regRow = $reg->get_result()->fetch_assoc() ?: [];
            $reg->close();
            $first   = (string)($regRow['FirstNames'] ?? '');
            $surname = (string)($regRow['Surname']    ?? '');
            $suffix  = (string)($regRow['Suffix']     ?? '');
        } else {
            $first   = $entry['first'];
            $surname = $entry['surname'];
            $suffix  = $entry['suffix'];
        }

        logActivity('song.credit', 'song', $songId, ['role' => $role, 'creditId' => $creditId]);
        ed2_respond([
            'ok'               => true,
            'creditId'         => $creditId,
            'name'             => $name,
            'first'            => $first,
            'surname'          => $surname,
            'suffix'           => $suffix,
            'registryPersonId' => $registryPersonId,
        ]);
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

    /* =====================================================================
     * SONG-LINK / COUNTERPART actions (#1608) — five cases ported verbatim
     * (matching v1's behaviour + response shape) from the legacy
     * manage/editor/api.php: get_song_links (:381), add_song_link (:459),
     * remove_song_link (:599), suggest_song_links (:676),
     * dismiss_song_link_suggestion (:772).
     *
     * ELI5: this is the "these are the same hymn, just in a different
     * songbook" feature. A curator can link e.g. MP-0031 (Amazing Grace) to
     * CH-0376 (also Amazing Grace) so the app can point between them, and
     * can accept/dismiss the computer's own guesses about which songs might
     * be the same.
     *
     * WHY THIS EXISTS HERE, NOW (#1608): v1's inline "Suggested counterparts"
     * panel in the editor sidebar was the ONLY place these five actions were
     * reachable, but `manage/editor/index.php` has 302-redirected every editor
     * visit to this v2 API's UI since #1601 landed — so the panel, and these
     * actions, have been LIVE-BUT-UNREACHABLE (the #1565/#1580 "looks alive,
     * isn't" class) for as long as v2 has been the default. #1220 explicitly
     * decided to keep the panel when the standalone suggestions PAGE was
     * absorbed into /manage/duplicate-songs (#1215) — the panel is a
     * complementary PUSH surface (surfaced the moment a curator has a song
     * open, with the most context) to duplicate-songs' PULL review queue, not
     * a duplicate of it. This commit is the "port the panel" resolution
     * recorded in .claude/wave4-prelaunch-plan.md §3/§6 Block C.
     *
     * RULE #22 (song_similarity.php is the ONE scorer): NONE of these five
     * actions call includes/song_similarity.php. suggest_song_links only ever
     * READS the PRE-SCORED tblSongLinkSuggestions table that the offline batch
     * `build-song-link-suggestions.php` fills (that batch is the scorer's only
     * caller) — so this file adds zero scoring maths, by construction.
     *
     * ENTITLEMENTS (per the plan + duplicate-songs.php's existing precedent,
     * duplicate-songs.php:34-42): page view rides the file-level editor-role
     * gate (same as v1); Link/Remove/Dismiss = edit_songs. The destructive
     * Merge action stays ONLY on /manage/duplicate-songs under
     * manage_duplicate_songs — this file never grows a merge case.
     *
     * No ed2_touchRevision() call anywhere below: tblSongLinks isn't part of
     * the song record (scalars/components/credits/tags/links-the-external-
     * kind) that a revision snapshot restores, matching v1's add/remove_song_link
     * exactly — neither of those wrote a revision row either.
     * ===================================================================== */

    /* ---- song_links (GET) — this song's cross-book counterpart group.
           Port of get_song_links (api.php:381).
           D10 (wave4-prelaunch-plan.md §5 defect list): v1 reads tblSongLinks
           UNPROBED — no INFORMATION_SCHEMA existence check — because the table
           ships in the SAME migration as tblSongLinkSuggestions/…Dismissed
           (#1216/#1219) and every install that has run any of #1608's sibling
           features already has it. Matched here exactly rather than inventing
           a new degrade v1 never had; only suggest_song_links (below) probes,
           because ITS table is the newer, separately-added
           tblSongLinkSuggestions from the fuzzy-match batch (#1219), which an
           older-but-still-#1216-migrated install could plausibly lack. ---- */
    case 'song_links': {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }

        $stmt = $db->prepare('SELECT GroupId FROM tblSongLinks WHERE SongId = ? LIMIT 1');
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $groupId = $row ? (int)$row['GroupId'] : 0;

        $links = [];
        if ($groupId > 0) {
            /* #1694 — songVisibleSql() keeps a soft-deleted counterpart off
               this panel, the same guard v1 already had (api.php:420). */
            $stmt = $db->prepare(
                'SELECT l.Id           AS id,
                        l.SongId       AS songId,
                        l.Note         AS note,
                        l.Verified     AS verified,
                        s.Title        AS title,
                        s.Number       AS number,
                        s.SongbookAbbr AS songbook,
                        sb.Name        AS songbookName,
                        s.Language     AS language
                   FROM tblSongLinks l
                   JOIN tblSongs s      ON s.SongId = l.SongId
                   JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                  WHERE l.GroupId = ?
                    AND l.SongId  <> ?
                    AND ' . songVisibleSql($db, 's') . '
                  ORDER BY s.SongbookAbbr ASC, s.Number ASC'
            );
            $stmt->bind_param('is', $groupId, $songId);
            $stmt->execute();
            $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($links as &$ln) {
                $ln['id']       = (int)$ln['id'];
                $ln['verified'] = (bool)$ln['verified'];
                $ln['number']   = ($ln['number'] === null) ? null : (int)$ln['number'];
            }
            unset($ln);
        }
        ed2_respond(['ok' => true, 'groupId' => $groupId, 'links' => $links]);
        break;
    }

    /* ---- song_link_add (POST) — link two songs as cross-book counterparts.
           Port of add_song_link (api.php:459). Body: { sourceSongId,
           targetSongId, note? }.

           Group-merge semantics, UNCHANGED from v1:
             - neither song grouped   -> mint a new GroupId, add both
             - exactly one grouped    -> add the other to it
             - both in the SAME group -> no-op (note refreshed if supplied)
             - both in DIFFERENT groups -> refuse; the curator must unlink
               one side first (rule #35: this is a STATUS code, 409, not a
               string the client pattern-matches). ---- */
    case 'song_link_add': {
        ed2_requireEntitlement('edit_songs');
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $srcId = trim((string)($body['sourceSongId'] ?? ''));
        $tgtId = trim((string)($body['targetSongId'] ?? ''));
        $note  = trim((string)($body['note'] ?? ''));
        if ($srcId === '' || $tgtId === '') {
            ed2_respond(['ok' => false, 'error' => 'sourceSongId and targetSongId are required.'], 400);
        }
        if ($srcId === $tgtId) {
            ed2_respond(['ok' => false, 'error' => 'A song cannot be linked to itself.'], 400);
        }

        /* Validate both songs exist AND are visible before mutating anything
           — cheaper than an FK failure, and #1694-consistent: a hidden song
           cannot be linked to (it's offered nowhere, so a stale request
           naming one should read as not-found, not silently succeed). */
        $probe = $db->prepare(
            'SELECT SongId FROM tblSongs WHERE SongId IN (?, ?) AND ' . songVisibleSql($db, '')
        );
        $probe->bind_param('ss', $srcId, $tgtId);
        $probe->execute();
        $found = [];
        $res = $probe->get_result();
        while ($r = $res->fetch_assoc()) { $found[] = $r['SongId']; }
        $probe->close();
        if (count($found) < 2) {
            ed2_respond(['ok' => false, 'error' => 'One or both songs were not found.'], 404);
        }

        $lookup = $db->prepare('SELECT SongId, GroupId FROM tblSongLinks WHERE SongId IN (?, ?)');
        $lookup->bind_param('ss', $srcId, $tgtId);
        $lookup->execute();
        $existing = [];
        $res = $lookup->get_result();
        while ($r = $res->fetch_assoc()) { $existing[$r['SongId']] = (int)$r['GroupId']; }
        $lookup->close();

        $srcGroup  = $existing[$srcId] ?? 0;
        $tgtGroup  = $existing[$tgtId] ?? 0;
        $createdBy = $ed2UserId;

        if ($srcGroup > 0 && $tgtGroup > 0 && $srcGroup === $tgtGroup) {
            if ($note !== '') {
                $db->begin_transaction();
                try {
                    $upd = $db->prepare('UPDATE tblSongLinks SET Note = ? WHERE SongId = ?');
                    $upd->bind_param('ss', $note, $tgtId);
                    $upd->execute();
                    $upd->close();
                    $db->commit();
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
            }
            ed2_respond(['ok' => true, 'groupId' => $srcGroup, 'noop' => true]);
        }
        if ($srcGroup > 0 && $tgtGroup > 0) {
            /* Different groups. The 409 status IS the contract the client
               branches on (rule #35) — the sentence is free to reword. */
            ed2_respond([
                'ok'    => false,
                'error' => 'Both songs are already in different counterpart groups. Unlink one before linking, or use the merge tool.',
            ], 409);
        }

        $db->begin_transaction();
        try {
            if ($srcGroup === 0 && $tgtGroup === 0) {
                /* Neither grouped — mint a new GroupId. MAX(GroupId)+1 is fine:
                   tblSongLinks is small + curator-edited, and an AUTO_INCREMENT
                   GroupId would complicate a future merge-groups op (matches
                   v1's reasoning verbatim, api.php:546-553). Wrapped in the
                   transaction (v1 was not) so two concurrent first-links can't
                   race onto the same minted id — a strict safety IMPROVEMENT
                   over v1, not a behaviour change any caller can observe. */
                $r = $db->query('SELECT COALESCE(MAX(GroupId), 0) + 1 AS NextId FROM tblSongLinks');
                $newGroup = $r ? (int)$r->fetch_assoc()['NextId'] : 1;
                if ($r) { $r->close(); }

                $ins = $db->prepare(
                    'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                     VALUES (?, ?, ?, ?), (?, ?, ?, ?)'
                );
                $emptyNote = '';
                /* CORRECTED type string, NOT a verbatim port of this one line:
                   v1 (api.php:561) binds this same 8-value pair as
                   'issiisis' — positions 7-8 swap $note's 's' and
                   $createdBy's 'i', so mysqli coerces the non-numeric $note
                   string to (int) 0 on every brand-new counterpart group,
                   silently discarding whatever text the curator typed. Caught
                   by this commit's own behavioural verification (a POSTed
                   note came back as the literal string "0" on re-GET) — a
                   type-string transposition bind_param()'s signature can't
                   catch (rule #5 talks about placeholder/VALUE COUNT
                   mismatches via bindParamSafe(); this is a same-count
                   type mismatch, a different failure shape). Fixing a
                   verbatim port would ship a KNOWN, freshly-discovered data-
                   loss bug into new code, so this one line intentionally
                   diverges from v1: 'issiissi' below is i,s,s,i,i,s,s,i —
                   note stays 's', createdBy stays 'i'. Filed as its own v1
                   bug report (does not block this port; v1 is scheduled for
                   retirement under #1601). */
                $ins->bind_param(
                    'issiissi',
                    $newGroup, $srcId, $emptyNote, $createdBy,
                    $newGroup, $tgtId, $note,      $createdBy
                );
                $ins->execute();
                $ins->close();
                $groupId = $newGroup;
                $extra   = ['created' => true];
            } else {
                /* Exactly one side already grouped — extend it. */
                $joinGroup = $srcGroup > 0 ? $srcGroup : $tgtGroup;
                $newSongId = $srcGroup > 0 ? $tgtId    : $srcId;
                $ins = $db->prepare(
                    'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                     VALUES (?, ?, ?, ?)'
                );
                $ins->bind_param('issi', $joinGroup, $newSongId, $note, $createdBy);
                $ins->execute();
                $ins->close();
                $groupId = $joinGroup;
                $extra   = ['extended' => true];
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.link', 'song', $srcId, ['groupId' => $groupId, 'target' => $tgtId] + $extra);
        ed2_respond(['ok' => true, 'groupId' => $groupId] + $extra);
        break;
    }

    /* ---- song_link_remove (POST) — drop one song from its counterpart
           group. Port of remove_song_link (api.php:599). Body:
           { id?: int, songId?: string } — either identifier works.

           A resulting group of <2 members is meaningless (a "counterpart"
           of nobody), so it's cleaned up too — matches v1 verbatim.
           Already-gone -> {ok:true, deleted:0}, NOT a 404: a double-click on
           the Unlink button must not surface a spurious error. ---- */
    case 'song_link_remove': {
        ed2_requireEntitlement('edit_songs');
        $removeId = (int)($body['id'] ?? 0);
        $songId   = trim((string)($body['songId'] ?? ''));
        if ($removeId <= 0 && $songId === '') {
            ed2_respond(['ok' => false, 'error' => 'id or songId is required.'], 400);
        }

        if ($removeId > 0) {
            $stmt = $db->prepare('SELECT Id, GroupId FROM tblSongLinks WHERE Id = ?');
            $stmt->bind_param('i', $removeId);
        } else {
            $stmt = $db->prepare('SELECT Id, GroupId FROM tblSongLinks WHERE SongId = ?');
            $stmt->bind_param('s', $songId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            ed2_respond(['ok' => true, 'deleted' => 0]);
        }

        $groupId = (int)$row['GroupId'];
        $rowId   = (int)$row['Id'];

        $db->begin_transaction();
        try {
            $del = $db->prepare('DELETE FROM tblSongLinks WHERE Id = ?');
            $del->bind_param('i', $rowId);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();

            /* Fewer than two members left in the group? Drop the remainder
               — a singleton group is meaningless and would otherwise occupy
               a slot forever showing "no counterparts". */
            $r = $db->prepare('SELECT COUNT(*) AS n FROM tblSongLinks WHERE GroupId = ?');
            $r->bind_param('i', $groupId);
            $r->execute();
            $remaining = (int)$r->get_result()->fetch_assoc()['n'];
            $r->close();
            if ($remaining < 2) {
                $cleanup = $db->prepare('DELETE FROM tblSongLinks WHERE GroupId = ?');
                $cleanup->bind_param('i', $groupId);
                $cleanup->execute();
                $cleanup->close();
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.link.remove', 'song', $songId !== '' ? $songId : (string)$rowId, ['groupId' => $groupId, 'deleted' => (int)$deleted]);
        ed2_respond(['ok' => true, 'deleted' => (int)$deleted]);
        break;
    }

    /* ---- song_link_suggestions (GET) — up to 5 highest-scoring pending
           counterpart suggestions involving this song. Port of
           suggest_song_links (api.php:676).

           RULE #22: this reads the PRE-SCORED tblSongLinkSuggestions table —
           built offline by build-song-link-suggestions.php, the ONE consumer
           of includes/song_similarity.php's ihymns_sim_score(). No scoring
           maths lives here or anywhere in this file.

           Probed (unlike song_links above — see that case's D10 comment):
           an install can have run #1216's original tblSongLinks migration
           without yet having run #1219's later tblSongLinkSuggestions
           addition, so an un-migrated read here degrades to an empty list +
           tableMissing:true rather than a mysqli-STRICT throw (matches v1
           exactly, api.php:686-700). ---- */
    case 'song_link_suggestions': {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }

        $probe = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongLinkSuggestions' LIMIT 1"
        );
        $hasTable = $probe && $probe->fetch_row() !== null;
        if ($probe) { $probe->close(); }
        if (!$hasTable) {
            ed2_respond(['ok' => true, 'suggestions' => [], 'tableMissing' => true]);
        }

        $stmt = $db->prepare(
            'SELECT s.Id          AS id,
                    s.SongIdA     AS songIdA,
                    s.SongIdB     AS songIdB,
                    s.Score       AS score,
                    s.TitleScore  AS titleScore,
                    s.LyricsScore AS lyricsScore,
                    a.Title       AS titleA,
                    a.Number      AS numberA,
                    a.SongbookAbbr AS songbookA,
                    b.Title       AS titleB,
                    b.Number      AS numberB,
                    b.SongbookAbbr AS songbookB
               FROM tblSongLinkSuggestions s
               JOIN tblSongs a ON a.SongId = s.SongIdA AND ' . songVisibleSql($db, 'a') . '
               JOIN tblSongs b ON b.SongId = s.SongIdB AND ' . songVisibleSql($db, 'b') . '
              WHERE (s.SongIdA = ? OR s.SongIdB = ?)
                AND NOT EXISTS (
                    SELECT 1 FROM tblSongLinkSuggestionsDismissed d
                     WHERE d.SongIdA = s.SongIdA AND d.SongIdB = s.SongIdB
                )
                /* Skip pairs already in the same counterpart group — already linked. */
                AND NOT EXISTS (
                    SELECT 1
                      FROM tblSongLinks la
                      JOIN tblSongLinks lb ON la.GroupId = lb.GroupId
                     WHERE la.SongId = s.SongIdA AND lb.SongId = s.SongIdB
                )
              ORDER BY s.Score DESC
              LIMIT 5'
        );
        $stmt->bind_param('ss', $songId, $songId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        /* Normalise so the "other side" is always in a single `other` field,
           regardless of which slot the current song was found in. */
        $suggestions = [];
        foreach ($rows as $r) {
            $isA = ($r['songIdA'] === $songId);
            $suggestions[] = [
                'id'          => (int)$r['id'],
                'score'       => (float)$r['score'],
                'titleScore'  => (float)$r['titleScore'],
                'lyricsScore' => (float)$r['lyricsScore'],
                'other' => [
                    'songId'   => $isA ? $r['songIdB']   : $r['songIdA'],
                    'title'    => $isA ? $r['titleB']    : $r['titleA'],
                    'number'   => $isA ? $r['numberB']   : $r['numberA'],
                    'songbook' => $isA ? $r['songbookB'] : $r['songbookA'],
                ],
            ];
        }
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- song_link_suggestion_dismiss (POST) — curator says "no, different
           hymns". Port of dismiss_song_link_suggestion (api.php:772). Body:
           { songIdA, songIdB, reason? } — canonical order (SongIdA < SongIdB
           lexicographically) is enforced server-side so callers needn't
           pre-sort, matching the build-script invariant.

           Writes tblSongLinkSuggestionsDismissed — the SAME table
           /manage/duplicate-songs's `dismiss` action writes (CLAUDE.md rule
           #22: "a cluster is suppressed only when ALL its pairs are
           dismissed"), so a dismissal from this panel and a dismissal on
           duplicate-songs are two views of ONE workflow, never two
           divergent stores. ---- */
    case 'song_link_suggestion_dismiss': {
        ed2_requireEntitlement('edit_songs');
        $a      = trim((string)($body['songIdA'] ?? ''));
        $b      = trim((string)($body['songIdB'] ?? ''));
        $reason = trim((string)($body['reason'] ?? ''));
        if ($a === '' || $b === '' || $a === $b) {
            ed2_respond(['ok' => false, 'error' => 'songIdA and songIdB are required and must differ.'], 400);
        }
        if ($a > $b) { [$a, $b] = [$b, $a]; }

        $db->begin_transaction();
        try {
            $dismissedBy = $ed2UserId;
            $stmt = $db->prepare(
                'INSERT INTO tblSongLinkSuggestionsDismissed (SongIdA, SongIdB, DismissedBy, Reason)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE Reason = VALUES(Reason),
                                         DismissedBy = VALUES(DismissedBy),
                                         DismissedAt = CURRENT_TIMESTAMP'
            );
            $stmt->bind_param('ssis', $a, $b, $dismissedBy, $reason);
            $stmt->execute();
            $stmt->close();

            /* Drop the matching pending suggestion so it disappears from
               every consumer immediately (this panel AND duplicate-songs). */
            $del = $db->prepare(
                'DELETE FROM tblSongLinkSuggestions WHERE SongIdA = ? AND SongIdB = ?'
            );
            $del->bind_param('ss', $a, $b);
            $del->execute();
            $del->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.link.dismiss', 'song', $a, ['other' => $b, 'reason' => $reason]);
        ed2_respond(['ok' => true]);
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
                /* #882 — both .xml (OpenLyrics' native extension) and
                   .opensong resolve to the shared 'xmlauto' TARGET, which
                   routes through _bulkImport_processXmlAuto() below —
                   that function, not this map, is what actually tells
                   OpenLyrics and OpenSong apart (it content-sniffs via
                   _bulkImport_looksLikeOpenLyrics(), the same discriminator
                   the ZIP import loop uses for its own .xml entries). */
                'xml', 'opensong' => 'xmlauto',
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
               the CONTENT is the established answer here — `'xml', 'opensong' =>
               'xmlauto'` above uses exactly the same content-sniff strategy, just
               via _bulkImport_processXmlAuto() downstream instead of inline.
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

        /* #882 — 'xmlauto' is the internal auto-resolution target reached
           only via format=auto on a .xml/.opensong extension (never offered
           directly in the UI dropdown); 'opensong' is also explicitly
           pickable so an operator can override a sniff that guessed wrong
           (same #1633 precedent as the iHymns-vs-VideoPsalm JSON override). */
        $bodyFormats = ['videopsalm', 'ihymns', 'openlp', 'opensong', 'xmlauto', 'pro6', 'proclaim', 'freeshow', 'chordpro'];
        $summary = null;
        try {
            if (in_array($format, $bodyFormats, true)) {
                if ($content === null) { $content = file_get_contents($tmpPath); }
                if ($content === false) { throw new \RuntimeException('Could not read the uploaded file.'); }
                $summary = match ($format) {
                    'videopsalm' => _bulkImport_processVideoPsalm($content, $origName),
                    'ihymns'     => _bulkImport_processIHymnsJson($content, $origName),  // #1633
                    'openlp'     => _bulkImport_processOpenLp($content, $origName),
                    'opensong'   => _bulkImport_processOpenSong($content, $origName),    // #882
                    'xmlauto'    => _bulkImport_processXmlAuto($content, $origName),     // #882
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
        /* #882 — the auto-router (format 'xmlauto') stamps its own resolved
           'format' ('openlp' or 'opensong') into $summary; every other
           branch's $summary carries no 'format' key, so this falls back to
           the format that was actually used to parse. Same value feeds
           both the activity log and the response so they can't disagree. */
        $resolvedFormat = (string)($summary['format'] ?? $format);
        logActivity('song.import_file', 'import', $origName, [
            'format'  => $resolvedFormat,
            'created' => (int)($summary['songs_created'] ?? 0),
            'skipped' => (int)($summary['songs_skipped_existing'] ?? 0),
            'failed'  => (int)($summary['songs_failed'] ?? 0),
        ]);
        /* #882 — every single-file processor's contract is "ok=false ⇔ parse
           failed" (a save failure lands as ok=true + songs_failed>0
           instead); a parse failure is a genuine client error (bad/wrong
           file), so it belongs on 400, not 200 — CLAUDE.md rule #35: the
           HTTP status is the failure-kind contract, not the error prose.
           This endpoint previously always answered 200 regardless of
           $summary['ok'], which the #882 negative-path verification
           (auto-detect on a file that is neither dialect) surfaced. */
        $httpStatus = ($summary['ok'] ?? false) ? 200 : 400;
        ed2_respond(array_merge(['ok' => true, 'format' => $resolvedFormat], $summary), $httpStatus);
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
        /* @deleted-visible: audit CSV (#1694) — a skip happened because the
           row existed at import time; title resolution must not depend on
           later visibility. */
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
        ed2_requireEntitlement('bulk_edit_songs');
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
        ed2_requireEntitlement('bulk_edit_songs');
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

    /* ---- bulk_tag_detach (POST) — remove one tag from many songs (#1628 item 3).
     *
     * ELI5: the opposite of bulk_tag_attach — takes a tag OFF a set of songs.
     *
     * WHY: v1's single `bulk_tag` action (#399) took BOTH `add:[]` and
     * `remove:[]`. v2 shipped only `bulk_tag_attach`, so the capability v2
     * appeared to have was narrower than it looked — a curator who bulk-tagged
     * 200 songs with the wrong tag had no way to undo it except one song at a
     * time. That asymmetry is invisible from the v2 UI, which simply has no
     * Remove button; it only bites after the mistake.
     *
     * Resolves the tag by SLUG, not by Id, so the client passes the same
     * human-typed name it passes to attach, normalised the same way. An unknown
     * tag is NOT an error — detaching a tag nothing carries is a successful
     * no-op, and 404-ing there would make "remove this tag everywhere" fail
     * whenever it had already succeeded.
     *
     * Deliberately does NOT delete the tag row itself when the last song loses
     * it. Tags are a curated vocabulary (#1152 seeds the CCLI theme taxonomy
     * into the same table, with hierarchy via ParentId); garbage-collecting a
     * standard theme because it briefly had no songs would silently prune the
     * vocabulary and orphan its children. ---- */
    case 'bulk_tag_detach': {
        ed2_requireEntitlement('bulk_edit_songs');
        $rawIds = is_array($body['songIds'] ?? null) ? $body['songIds'] : [];
        $ids = [];
        foreach ($rawIds as $x) { $s = trim((string)$x); if ($s !== '') { $ids[] = $s; } }
        $name = ed2_normalizeTag((string)($body['name'] ?? ''));
        if (!$ids || $name === '') { ed2_respond(['ok' => false, 'error' => 'songIds + a tag name are required.'], 400); }
        $slug = ed2_tagSlug($name);
        if ($slug === '') { ed2_respond(['ok' => false, 'error' => 'Tag name has no usable characters.'], 400); }

        $sel = $db->prepare('SELECT Id FROM tblSongTags WHERE Slug = ? LIMIT 1');
        $sel->bind_param('s', $slug);
        $sel->execute();
        $row = $sel->get_result()->fetch_row();
        $sel->close();
        if ($row === null) {
            /* Nothing carries this tag — a successful no-op, not a failure. */
            ed2_respond(['ok' => true, 'tag' => ['id' => null, 'name' => $name, 'slug' => $slug], 'detached' => 0, 'count' => count($ids)]);
        }
        $tagId = (int)$row[0];

        $db->begin_transaction();
        try {
            $del = $db->prepare('DELETE FROM tblSongTagMap WHERE SongId = ? AND TagId = ?');
            $detached = 0;
            foreach ($ids as $sid) {
                $del->bind_param('si', $sid, $tagId);
                $del->execute();
                if ($db->affected_rows > 0) { $detached++; }
            }
            $del->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.bulk_tag_detach', 'song', '', ['tag' => $name, 'count' => count($ids), 'detached' => $detached]);
        ed2_respond(['ok' => true, 'tag' => ['id' => $tagId, 'name' => $name, 'slug' => $slug], 'detached' => $detached, 'count' => count($ids)]);
        break;
    }

    /* ---- credit_search (GET) — autocomplete for credit names. UNIONs the five
           song-credit tables (grouped by name → combined usage count + the roles
           it appears in) + the tblMusicians registry for kind=any. Table +
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
            $parts[]  = "SELECT Name, 'registry' AS kindLabel, 0 AS cnt FROM tblMusicians WHERE Name LIKE ?";
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
        /* #1679 A2 — `songbooks` is the REAL catalogue, not the set of books that
           happen to appear in the song index.
           WHY: v2's songbook <select> derived its options from the loaded index
           (sidebar.js songbookList()), so a songbook with ZERO songs contributed
           no row and could not be chosen — a curator who had just created a book
           could not move the first song into it. That is a regression against v1,
           whose own load_index has always returned this exact list
           (manage/editor/api.php `case 'load_index'`). Same source
           (SongData::getSongbooks()), no new query.
           SHAPE: mapped to the {abbr, name} pairs the client actually consumes,
           rather than shipping the full catalogue row (series, compilers, links,
           colours…) for a two-field control. `id` is the Abbreviation — which IS
           the SongId prefix (rule #27), so it is the value that must be POSTed
           back as `songbook`. DisplayAbbr is deliberately NOT used here: it is
           display-only and is never a prefix.
           KEY AGREEMENT: `songbooks[].abbr` / `.name` are read verbatim by
           sidebar.js's songbookList(); tests/test-v2-songbook-move-ui.js pins the
           same two names on the client side, so the pair cannot drift silently
           (rule #35). */
        $books = [];
        foreach ($songData->getSongbooks() as $b) {
            $abbr = (string)($b['id'] ?? '');
            if ($abbr === '') { continue; }
            $books[] = ['abbr' => $abbr, 'name' => (string)($b['name'] ?? $abbr)];
        }
        ed2_respond([
            'ok'        => true,
            'songs'     => $songData->getSongsSlimIndex(),
            'songbooks' => $books,
        ]);
        break;
    }

    /* ---- easyworship_export (GET) — build + stream an EasyWorship Songs.db
           (#1059). Behaviourally identical to the legacy v1 handler this
           replaces (api.php:2604-2642, now delegating to the SAME shared
           includes/easyworship_export.php helpers, #1678 — v1's endpoint had
           no v2 equivalent, which the #1629 v1-consumer audit missed and
           epic #1601's v1 retirement would otherwise have broken). GET, so
           the POST-only X-Requested-With gate above does not apply; the
           file-level isAuthenticated()/editor-role guard already covers it.
           This is the ONE binary-response case in this file: on success it
           streams the file and `return`s instead of falling through to the
           JSON default, matching v1's structure exactly. ---- */
    case 'easyworship_export': {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'easyworship_export.php';
        $abbr     = strtoupper(trim((string)($_GET['abbr'] ?? '')));
        $oneId    = trim((string)($_GET['id'] ?? ''));
        $maxLines = max(0, (int)($_GET['maxLinesPerSlide'] ?? 0));
        try {
            $songData = new SongData();
            $songs    = [];
            $stem     = 'EasyWorship';
            if ($oneId !== '') {
                $one = $songData->getSongById($oneId);
                if ($one !== null) { $songs = [$one]; $stem = (string)($one['title'] ?? $oneId); }
            } elseif ($abbr !== '') {
                $songs = $songData->getSongs($abbr);
                $stem  = $abbr;
            }
            if (empty($songs)) {
                ed2_respond(['ok' => false, 'error' => 'No songs found to export (pass ?abbr=<songbook> or ?id=<SongId>).'], 404);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'ewexp_');
            $n   = _ewExport_writeDb($tmp, $songs, $maxLines);
            $fname = trim((string)preg_replace('/[^A-Za-z0-9 _\-]/', '', $stem));
            if ($fname === '') { $fname = 'EasyWorship'; }
            $fname .= ' Songs.db';
            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Song-Count: ' . $n);
            readfile($tmp);
            @unlink($tmp);
            /* Streamed a binary file — don't fall through to the JSON default
               (copies v1's api.php:2604-2642 structure exactly, #1678). */
            return;
        } catch (\Throwable $e) {
            error_log('[easyworship_export] ' . $e->getMessage());
            ed2_respond(['ok' => false, 'error' => 'Export failed: ' . $e->getMessage()], 500);
        }
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
    $ed2Payload = [
        'ok'           => false,
        'error'        => 'Server error.',
        'error_detail' => $ed2IsAdmin ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : null,
    ];
    /* #1679 A8 — a refusal that names the migration to run, shown to EVERY role
       that can reach this endpoint.
       WHY A SEPARATE KEY: `error_detail` above is deliberately admin-only (it
       leaks file paths, line numbers and raw mysqli text). But this endpoint
       admits the `editor` role while $ed2IsAdmin is computed from `admin`, so a
       plain editor — the person most likely to trip an un-applied migration —
       got a bare "Server error." with nothing to act on. The relocate refusal is
       an environment fault with no sensitive content: it names four FK
       constraint names and a migration card. So it travels in its own field,
       ungated, and the admin-only channel is NOT widened.
       RECOGNISED BY TYPE, never by matching the sentence (rule #35) — the
       message is user-facing copy and will be reworded; the class will not. */
    if ($e instanceof SongRelocateEnvironmentException) {
        $ed2Payload['error_hint'] = $e->getMessage();
    }
    ed2_respond($ed2Payload, 500);
}
