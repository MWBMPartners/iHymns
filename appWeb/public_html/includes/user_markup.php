<?php

declare(strict_types=1);

/**
 * iHymns — per-user song markup / notes: vocab + pure validators (#1266 Phase 1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PHASE 1 IS DORMANT BACKEND ONLY. This file is the ONE place that validates
 * and persists a signed-in user's PRIVATE per-song markup — a note anchored
 * to a lyric line (or the whole song) and/or a highlight span — mirroring the
 * shape of `includes/line_enrichment.php` (#1088/#1235 P3) but scoped to ONE
 * user rather than published to everyone. The web client (Phase 2, a
 * SEPARATE later commit) will call the three `api.php` actions
 * (`user_markup_list` / `user_markup_upsert` / `user_markup_delete`) that
 * delegate to the functions here; nothing calls them yet, so this whole
 * layer is a verified no-op until that client work lands.
 *
 * Design rules encoded here (CLAUDE.md):
 *  - Growable vocab is VARCHAR validated against a central map (rule #20),
 *    never ENUM — USER_MARKUP_KINDS / USER_MARKUP_COLOURS below are those
 *    maps. `drawing` is a foreseeable third Kind (freehand pen strokes); the
 *    schema's MetaJson column already gives it a home so adding it later is
 *    a vocab-array entry, never a second migration.
 *  - StartLineId / EndLineId anchor into the normalised `tblLyricLines.Id`
 *    (rule #21/#25) — NEVER `tblSongComponents.LinesJson` indices. Both FKs
 *    are ON DELETE SET NULL (not CASCADE): a user's own note degrades to
 *    song-level when the line it was pinned to is edited away, rather than
 *    being silently deleted out from under them.
 *  - StartOffset/EndOffset (0-based UTF-8 code-point, rule #21) and MetaJson
 *    are DORMANT in this schema (forward-looking, rule #20) — this Phase 1
 *    layer deliberately does not read or write them; a phrase-level
 *    highlight is a later phase's vocabulary entry, not a second migration.
 *  - Ownership is enforced everywhere: UserId always comes from the
 *    authenticated caller, never trusted from the request body; an update or
 *    delete by `id` only touches a row the caller already owns.
 *  - Every value is bound; the only interpolated SQL fragments are
 *    constants (column/table names from this file, never request input).
 *  - Status-code contract (rule #35): callers distinguish failure KINDS by
 *    HTTP status, never by matching this file's exception messages —
 *    \InvalidArgumentException maps to 422 (bad kind/colour/body/line-
 *    ownership/row-cap/unowned-id), \RuntimeException maps to 404
 *    (song not found).
 *
 * Requires getDbMysqli()-style \mysqli (caller supplies it) under
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (failing statements throw).
 *
 * @see https://github.com/MWBMPartners/iHymns/issues/1266
 * @see appWeb/public_html/includes/line_enrichment.php   the sibling idiom this mirrors
 * @see appWeb/.sql/migrate-user-song-markup.php          the schema this validates against
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* The canonical DB layer — getDbMysqli() + the bindParamSafe() count-guard
   (#928) every DB function below binds through. Lazy: requiring it opens no
   connection. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* songVisibleSql() (#1694) / songServableSql() (#1765) — the shared "is this
   song hidden (soft-deleted / its songbook disabled)?" predicates every raw
   tblSongs SELECT must embed (tests/php/test-song-visibility-guard.php +
   test-songbook-visibility-guard.php). Both degrade to '1=1' on an
   un-migrated install, so requiring them here is free until they're
   migrated. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_visibility.php';

/* ----------------------------------------------------------- Vocabularies --- */
/* Central allow-lists for the growable VARCHAR vocab (rule #20). Mirror the
   schema column COMMENTs in schema.sql (tblUserSongMarkup.Kind / .Colour).
   Add a value HERE, never an ENUM ALTER. */

/** Markup row Kind — a text note vs a plain highlight span. 'drawing' is a
 *  foreseeable future value (freehand pen strokes); MetaJson already gives
 *  it storage, so adding it is a one-line change here, never a migration. */
const USER_MARKUP_KINDS = ['note', 'highlight'];

/** Highlight colour token — a small fixed set (matches common highlighter
 *  colours); NULL/omitted means "no colour" (e.g. a pure text note). */
const USER_MARKUP_COLOURS = ['yellow', 'green', 'blue', 'pink', 'orange'];

/** Max Body length in UTF-8 code points (mb_strlen), mirrors the schema
 *  comment on tblUserSongMarkup.Body. */
const USER_MARKUP_BODY_MAX_LEN = 5000;

/** Max markup rows one user may hold against one song — a generous ceiling
 *  against runaway/automated writes, not a realistic usage limit. */
const USER_MARKUP_ROW_CAP = 200;

/* -------------------------------------------------- Pure validators (test) -- */
/* No DB / I/O — unit-tested directly in tests/php/test-user-markup.php. */

/** Normalise a value against an allow-list. Returns the value (lower-cased,
 *  trimmed) if allowed, else null. Mirrors lineEnrichmentNormalizeVocab() —
 *  kept as its own copy rather than a cross-require of line_enrichment.php,
 *  which is a distinct, unrelated feature family (the modularity rule is
 *  about a *shared* concern, and "fold a string against an allow-list" is
 *  the whole function; needlessly coupling two independent dormant features
 *  through a require is the greater risk). */
function userMarkupNormalizeVocab(string $value, array $allow): ?string
{
    $v = strtolower(trim($value));
    return in_array($v, $allow, true) ? $v : null;
}

/** Validate + normalise Kind. Throws \InvalidArgumentException (→422) when
 *  it is not one of USER_MARKUP_KINDS. */
function userMarkupValidateKind(string $kind): string
{
    $k = userMarkupNormalizeVocab($kind, USER_MARKUP_KINDS);
    if ($k === null) {
        throw new \InvalidArgumentException('kind must be one of: ' . implode(', ', USER_MARKUP_KINDS));
    }
    return $k;
}

/** Validate + normalise Colour. NULL / '' is always allowed (no colour, e.g.
 *  a plain text note); a non-empty value must be in USER_MARKUP_COLOURS or
 *  this throws \InvalidArgumentException (→422). */
function userMarkupValidateColour(?string $colour): ?string
{
    if ($colour === null || trim($colour) === '') {
        return null;
    }
    $c = userMarkupNormalizeVocab($colour, USER_MARKUP_COLOURS);
    if ($c === null) {
        throw new \InvalidArgumentException(
            'colour must be one of: ' . implode(', ', USER_MARKUP_COLOURS) . ', or omitted.'
        );
    }
    return $c;
}

/**
 * Validate Body against $kind's requirement, returning the trimmed value (or
 * null). A 'note' MUST carry non-empty Body; a 'highlight' MAY have a null
 * Body (a pure highlight with no accompanying text). Throws
 * \InvalidArgumentException (→422) on either violation, or when Body exceeds
 * USER_MARKUP_BODY_MAX_LEN UTF-8 code points (mb_strlen — never a byte
 * length, rule #21's code-point discipline applies to any stored text this
 * codebase later slices).
 */
function userMarkupValidateBody(string $kind, ?string $body): ?string
{
    $trimmed = $body !== null ? trim($body) : '';
    if ($trimmed === '') {
        if ($kind === 'note') {
            throw new \InvalidArgumentException('body is required for a note.');
        }
        return null; // highlight, no accompanying text — valid
    }
    if (mb_strlen($trimmed, 'UTF-8') > USER_MARKUP_BODY_MAX_LEN) {
        throw new \InvalidArgumentException('body must be at most ' . USER_MARKUP_BODY_MAX_LEN . ' characters.');
    }
    return $trimmed;
}

/**
 * Has this user already reached the per-(user, song) row cap? PURE — takes
 * the live count as a plain int so it is unit-testable with no DB handle;
 * the caller supplies COUNT(*) FROM tblUserSongMarkup WHERE UserId=? AND
 * SongId=? (see userMarkupCountForUserSong() below).
 */
function userMarkupRowCapExceeded(int $existingCount): bool
{
    return $existingCount >= USER_MARKUP_ROW_CAP;
}

/* ------------------------------------------------------- Schema readiness --- */

/** Is tblUserSongMarkup present? Memoised per request. Lets callers degrade
 *  gracefully on an un-migrated install (this migration may not have run
 *  yet) instead of a raw mysqli exception under STRICT reporting. */
function userMarkupTableReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUserSongMarkup' LIMIT 1"
        );
        $ready = ($r !== false && $r->fetch_row() !== null);
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $ready = false;
    }
    return $ready;
}

/* ------------------------------------------------------------- DB helpers --- */

/** Does $songId exist AND remain visible (not soft-deleted, not hidden by a
 *  disabled songbook) in tblSongs? Checked explicitly on CREATE so a bad or
 *  hidden SongId is a clean \RuntimeException (→404) rather than an
 *  uncaught FK violation surfacing as a 500 (the false-check-vs-throw
 *  lesson, project-rules.md §9 — mysqli under STRICT throws on a failed
 *  statement, but a passing INSERT that VIOLATES an FK also throws, and we
 *  want that case handled deliberately, not by accident).
 *
 *  @deleted-visible / #1694+#1765: a soft-deleted song, or one whose
 *  songbook was disabled, is treated as absent here — a user must not be
 *  able to attach a NEW private note to a song that no longer serves.
 *  songVisibleSql()/songServableSql() both degrade to '1=1' on an
 *  un-migrated install, so this costs nothing there and changes no
 *  behaviour until those columns exist. */
function userMarkupSongExists(\mysqli $db, string $songId): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM tblSongs WHERE SongId = ?'
        . ' AND ' . songVisibleSql($db, '')
        . ' AND ' . songServableSql($db, '')
        . ' LIMIT 1'
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/**
 * Does $lineId belong to a CURRENT ('ihymns') lyrics version of $songId?
 * Mirrors lineEnrichmentResolveLine()'s ownership JOIN. Returns a truthy
 * array on success, null when the line does not exist or belongs to a
 * different song — the caller maps null to a 422 (rule #35: a caller can't
 * anchor a note on another song's line by guessing an Id).
 */
function userMarkupResolveLine(\mysqli $db, int $lineId, string $songId): ?array
{
    $stmt = $db->prepare(
        "SELECT ll.Id AS id
           FROM tblLyricLines ll
           JOIN tblLyrics ly ON ly.Id = ll.LyricsId
          WHERE ll.Id = ? AND ly.SongId = ? AND ly.Source = 'ihymns'
          LIMIT 1"
    );
    $stmt->bind_param('is', $lineId, $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Live count of markup rows $userId already holds against $songId — feeds
 *  userMarkupRowCapExceeded() on CREATE. */
function userMarkupCountForUserSong(\mysqli $db, int $userId, string $songId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM tblUserSongMarkup WHERE UserId = ? AND SongId = ?');
    $stmt->bind_param('is', $userId, $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

/** Raw markup row by Id (assoc) or null. */
function userMarkupFetch(\mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM tblUserSongMarkup WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Shape a markup row for clients (camelCase). StartOffset/EndOffset/
 *  MetaJson are deliberately OMITTED — dormant in Phase 1 (see file
 *  doc-block); nothing reads or writes them yet, so they are not part of
 *  the wire shape until a later phase gives them a purpose. */
function userMarkupShapeRow(?array $r): array
{
    if ($r === null) { return []; }
    return [
        'id'          => (int)$r['Id'],
        'songId'      => (string)$r['SongId'],
        'kind'        => (string)$r['Kind'],
        'startLineId' => $r['StartLineId'] !== null ? (int)$r['StartLineId'] : null,
        'endLineId'   => $r['EndLineId']   !== null ? (int)$r['EndLineId']   : null,
        'colour'      => $r['Colour'] !== null ? (string)$r['Colour'] : null,
        'body'        => $r['Body']   !== null ? (string)$r['Body']   : null,
        'createdAt'   => (string)$r['CreatedAt'],
        'updatedAt'   => (string)$r['UpdatedAt'],
    ];
}

/* ================================================================== Read === */

/**
 * All of $userId's markup rows for $songId, shaped for the client. Empty
 * array both when the table is absent (fail-open read — an un-migrated
 * install behaves exactly as before this feature existed) and when the user
 * simply has none yet.
 *
 * @return list<array<string,mixed>>
 */
function userMarkupListForSong(\mysqli $db, int $userId, string $songId): array
{
    if (!userMarkupTableReady($db)) { return []; }
    $stmt = $db->prepare(
        'SELECT * FROM tblUserSongMarkup WHERE UserId = ? AND SongId = ? ORDER BY Id ASC'
    );
    $stmt->bind_param('is', $userId, $songId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map('userMarkupShapeRow', $rows);
}

/* ============================================================ Write === */

/**
 * Create or update one of $userId's markup rows for $songId. On CREATE
 * (no/zero `id`) a fresh row is inserted; on UPDATE the row must already
 * belong to ($userId, $songId) or this throws. UserId is ALWAYS $userId —
 * never read from $input (ownership cannot be spoofed via the body).
 *
 * Throws:
 *   \InvalidArgumentException  → caller maps to 422 (bad kind / colour /
 *     body / line-ownership / row-cap / an `id` that isn't $userId's own)
 *   \RuntimeException          → caller maps to 404 ($songId doesn't exist)
 *
 * Returns the persisted row, RE-READ from the DB and camelCase-shaped (rule
 * #35 — the response is the stored truth, never an echo of the request).
 *
 * @param array<string,mixed> $input  id?, kind?, colour?, body?,
 *                                    startLineId?, endLineId?
 */
function userMarkupUpsert(\mysqli $db, int $userId, string $songId, array $input): array
{
    $id = isset($input['id']) ? (int)$input['id'] : 0;

    $kind   = userMarkupValidateKind((string)($input['kind'] ?? 'note'));
    $colour = userMarkupValidateColour(
        isset($input['colour']) && $input['colour'] !== null ? (string)$input['colour'] : null
    );
    $body = userMarkupValidateBody(
        $kind,
        isset($input['body']) && $input['body'] !== null ? (string)$input['body'] : null
    );

    $existing = null;
    if ($id > 0) {
        $existing = userMarkupFetch($db, $id);
        if ($existing === null || (int)$existing['UserId'] !== $userId || (string)$existing['SongId'] !== $songId) {
            /* Same refusal for "doesn't exist" and "exists but isn't yours" —
               never leak which, that would let a caller enumerate other
               users' row ids by probing. */
            throw new \InvalidArgumentException('Markup row not found.');
        }
    }

    /* Line anchors — optional, song-level (NULL) when omitted entirely on
       CREATE. On UPDATE, an ABSENT key preserves the existing anchor and an
       explicit null/''/0 clears it — the array_key_exists carry that keeps a
       partial-payload update from silently wiping a field the caller never
       meant to touch (the same silent-wipe shape rule #45 documents for
       component Label). */
    $startLineId = $existing !== null && $existing['StartLineId'] !== null ? (int)$existing['StartLineId'] : null;
    if (array_key_exists('startLineId', $input)) {
        $raw = $input['startLineId'];
        $startLineId = ($raw === null || $raw === '') ? null : (int)$raw;
    }
    $endLineId = $existing !== null && $existing['EndLineId'] !== null ? (int)$existing['EndLineId'] : null;
    if (array_key_exists('endLineId', $input)) {
        $raw = $input['endLineId'];
        $endLineId = ($raw === null || $raw === '') ? null : (int)$raw;
    }

    if ($startLineId !== null && userMarkupResolveLine($db, $startLineId, $songId) === null) {
        throw new \InvalidArgumentException('startLineId does not belong to this song.');
    }
    if ($endLineId !== null && userMarkupResolveLine($db, $endLineId, $songId) === null) {
        throw new \InvalidArgumentException('endLineId does not belong to this song.');
    }
    if ($endLineId !== null && $startLineId === null) {
        throw new \InvalidArgumentException('endLineId requires startLineId.');
    }

    if ($id > 0) {
        $u = $db->prepare(
            'UPDATE tblUserSongMarkup
                SET Kind = ?, StartLineId = ?, EndLineId = ?, Colour = ?, Body = ?
              WHERE Id = ? AND UserId = ?'
        );
        bindParamSafe('userMarkup UPDATE', $u,
            's'  // Kind
          . 'i'  // StartLineId (nullable)
          . 'i'  // EndLineId (nullable)
          . 's'  // Colour (nullable)
          . 's'  // Body (nullable)
          . 'i'  // Id
          . 'i', // UserId
            $kind, $startLineId, $endLineId, $colour, $body, $id, $userId
        );
        $u->execute();
        $u->close();
        return userMarkupShapeRow(userMarkupFetch($db, $id));
    }

    /* CREATE — enforce the per-(user, song) row cap and that $songId is
       real BEFORE inserting (a bad SongId would otherwise surface as an
       uncaught FK-violation 500, not a clean 4xx). */
    if (userMarkupRowCapExceeded(userMarkupCountForUserSong($db, $userId, $songId))) {
        throw new \InvalidArgumentException(
            'You have reached the ' . USER_MARKUP_ROW_CAP . '-item markup limit for this song.'
        );
    }
    if (!userMarkupSongExists($db, $songId)) {
        throw new \RuntimeException('Song not found.');
    }

    $i = $db->prepare(
        'INSERT INTO tblUserSongMarkup (UserId, SongId, Kind, StartLineId, EndLineId, Colour, Body)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    bindParamSafe('userMarkup INSERT', $i,
        'i'  // UserId
      . 's'  // SongId
      . 's'  // Kind
      . 'i'  // StartLineId (nullable)
      . 'i'  // EndLineId (nullable)
      . 's'  // Colour (nullable)
      . 's', // Body (nullable)
        $userId, $songId, $kind, $startLineId, $endLineId, $colour, $body
    );
    $i->execute();
    $newId = (int)$db->insert_id;
    $i->close();
    return userMarkupShapeRow(userMarkupFetch($db, $newId));
}

/**
 * Delete one of $userId's markup rows. Ownership-scoped in the WHERE clause
 * itself (never a separate existence check) so this is a single atomic
 * statement. Returns true iff a row was actually removed; the caller does
 * NOT branch the HTTP response on this (mirrors `favorites_remove` — a
 * delete of an already-gone / never-owned id is not an error, it just has
 * no further effect, and answering identically either way avoids leaking
 * whether an id exists at all).
 */
function userMarkupDelete(\mysqli $db, int $userId, int $id): bool
{
    $stmt = $db->prepare('DELETE FROM tblUserSongMarkup WHERE Id = ? AND UserId = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $removed = $stmt->affected_rows > 0;
    $stmt->close();
    return $removed;
}
