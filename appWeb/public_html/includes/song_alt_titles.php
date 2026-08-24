<?php

declare(strict_types=1);

/**
 * iHymns — tblSongAlternativeTitles write core (#1669, epic #832)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A song can be known by more than one name — "Amazing Grace" is also
 * catalogued as "Faith's Review and Expectation". This file is the ONE place
 * that lists, adds, and removes those "also known as" titles for a song.
 * Until now nothing anywhere could create the FIRST one.
 *
 * DETAILED / WHY THIS FILE EXISTS
 * ----------------------------------------------------------------------------
 * `tblSongAlternativeTitles` (#832, `migrate-alternative-titles.php`) has had
 * a complete READ half since it landed — `SongData::_songAltTitlesMap()`
 * feeds the public song page's "Also known as" line, the OG image, and the
 * #832 search boost (a query matching an alt title ranks the song top) — but
 * the ONLY `INSERT` anywhere in the tree was `manage/editor/api2.php`'s
 * `duplicate_song` action's `INSERT … SELECT` COPY of an EXISTING song's
 * rows, which can never create a row where none exists yet. This file is
 * that missing write path, mirroring `includes/song_external_ids.php`'s
 * shape byte-for-byte (CLAUDE.md rule #22 — the "no second write site" rule:
 * api2.php's three `song_alt_title*` actions, and any future bulk-importer
 * path, both delegate here — never re-fork the INSERT).
 *
 * WHY NOT A FIND-OR-CREATE PICKER (rule #43)
 * ----------------------------------------------------------------------------
 * Rule #43 mandates the shared live-search + find-or-create picker for any
 * field that references or CREATES A REGISTRY row (tblPublishers, tblTunes,
 * tblMusicians, tblWorks, …). An alt title is not that: it is per-song FREE
 * TEXT — a title STRING, not a reference to some OTHER entity that could
 * itself be looked up, shared, or reused across songs. There is nothing to
 * search or dedupe against beyond THIS song's own existing alt titles, which
 * the `uq_song_title` UNIQUE key below already enforces server-side. Rule
 * #43 therefore does not apply to this field.
 *
 * REVISION-SNAPSHOT POSTURE (mirrors song_external_ids.php's own note)
 * ----------------------------------------------------------------------------
 * Alt titles stay OUT of `ed2_buildSongSnapshot()` / `ed2_applySongSnapshot()`
 * — the SAME posture `tblSongExternalIds` and `tblSongLinks` already take
 * (see api2.php's own comment on those cases): an add/delete here never
 * calls `ed2_touchRevision()`. The `duplicate_song` copy loop
 * (api2.php ~:2095-2096) already covers the clone path independently of
 * this write core.
 *
 * Direct access is blocked (the SAME guard `media_identifiers.php` /
 * `tune_helpers.php` / `song_external_ids.php` all use) so this file can't
 * be requested as an endpoint via an open Apache config.
 * https://www.php.net/manual/en/reserved.variables.server.php ($_SERVER['SCRIPT_FILENAME'])
 *
 * @link .claude/wave2-importer-editor-fidelity-plan.md §2      the #1669 build spec this file implements
 * @link appWeb/public_html/includes/song_external_ids.php      the shape this file mirrors byte-for-byte
 * @link appWeb/public_html/includes/SongData.php                _songAltTitlesMap() — the read half this write path feeds
 * @link appWeb/public_html/manage/editor/api2.php               song_alt_titles / song_alt_title_add / song_alt_title_delete
 * @link appWeb/.sql/schema.sql                                   tblSongAlternativeTitles CREATE TABLE block (uq_song_title)
 * @see #832
 * @see #1669
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Cached, per-request probe: does `tblSongAlternativeTitles` exist on this
 * install? The exact idiom `song_external_ids.php`'s
 * `songExternalIdsTableExists()` / `tune_helpers.php`'s
 * `tuneTunesTableExists()` use (a `static` local — this file has no class).
 *
 * ELI5: "does the alt-titles table even exist yet on this database?" —
 * asked once per page load, remembered for every subsequent call so an
 * un-migrated install pays for the INFORMATION_SCHEMA lookup only once.
 *
 * @param \mysqli $db
 * @return bool
 */
function songAltTitlesTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongAlternativeTitles' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * List a song's alt titles, ordered `SortOrder ASC, Title ASC` — the SAME
 * ordering `SongData::_songAltTitlesMap()` uses (SongData.php ~:1401-1403),
 * so the editor panel and the public song page always agree on order.
 * Table-existence-gated (rule #25's "gate every table access" discipline
 * applied to this table): an un-migrated install gets an empty list, never
 * a mysqli-STRICT throw.
 *
 * ELI5: "give me this song's list of also-known-as titles, in the exact
 * order the public page would show them."
 *
 * @param \mysqli $db
 * @param string  $songId
 * @return array<int, array{id:int,title:string,language:string,note:string,sortOrder:int}>
 */
function songAltTitlesList(\mysqli $db, string $songId): array
{
    if (!songAltTitlesTableExists($db)) return [];

    $stmt = $db->prepare(
        'SELECT Id, Title, Language, Note, SortOrder
           FROM tblSongAlternativeTitles
          WHERE SongId = ?
          ORDER BY SortOrder ASC, Title ASC'
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static function (array $r): array {
        return [
            'id'        => (int)$r['Id'],
            'title'     => (string)$r['Title'],
            'language'  => (string)($r['Language'] ?? ''),
            'note'      => (string)($r['Note'] ?? ''),
            'sortOrder' => (int)$r['SortOrder'],
        ];
    }, $rows);
}

/**
 * Add one alt title — the ONE write path (rule #22). Validates the three
 * free-text inputs, then `INSERT IGNORE` (the `uq_song_title (SongId,Title)`
 * UNIQUE, `utf8mb4_unicode_ci`, absorbs a case-insensitive dupe) followed by
 * a CANONICAL re-select by `(SongId, Title)` — the exact
 * `song_external_id_add` idiom (api2.php ~:4500-4508) — so the echo is
 * always the STORED row, never the caller's own typed input.
 *
 * ELI5: "record this alternate title for this song — or, if it's already
 * there, just hand back the row that's already there instead of making a
 * second one."
 *
 * DETAILED / VALIDATION CONTRACT
 * ----------------------------------------------------------------------------
 * Throws `\InvalidArgumentException` for a caller-input mistake (empty or
 * over-length title, an unrecognised language tag, an over-length note) —
 * the CALLER maps that to an HTTP 422 (rule #35: the STATUS CODE is the
 * contract, never the exception message's prose). Throws
 * `\RuntimeException` if called directly on an un-migrated install (the
 * live api2.php callers already probe `songAltTitlesTableExists()`
 * themselves first and answer 409 before ever reaching this function, so
 * this is a defence-in-depth guard for any OTHER caller, e.g. a future
 * bulk-importer path per the Wave-2 plan's optional C9). A genuine SQL
 * failure still throws mysqli's own `mysqli_sql_exception` (STRICT mode,
 * project-wide, CLAUDE.md rule #5) and is NOT caught here — the caller's own
 * transaction/`try` handles that, exactly like every other write core in
 * this codebase.
 *
 * @param \mysqli     $db
 * @param string      $songId
 * @param string      $title    Trimmed, non-empty, <=255 CODE POINTS
 *                               (`mb_strlen`, not `strlen` — a multi-byte
 *                               title must not be cut mid-character).
 * @param string|null $language Trimmed; `''` or `null` -> `NULL`. Otherwise
 *                               must match the SAME soft BCP-47 grammar
 *                               `song_importers.php` already uses for a
 *                               songbook-language folder suffix
 *                               (`/^[a-z]{2,3}(-[A-Za-z0-9]+)*$/i`), then
 *                               capped to 35 code points (the column
 *                               width) via `mb_substr`.
 * @param string|null $note     Trimmed, <=255 code points; `''` or `null`
 *                               -> `NULL`.
 * @return array{id:int, created:bool, row:array{id:int,title:string,language:string,note:string,sortOrder:int}}
 * @throws \InvalidArgumentException on a validation failure (caller -> 422)
 * @throws \RuntimeException         when the table doesn't exist (caller -> 409)
 */
function songAltTitleAdd(\mysqli $db, string $songId, string $title, ?string $language, ?string $note): array
{
    $title = trim($title);
    if ($title === '') {
        throw new \InvalidArgumentException('title is required.');
    }
    if (mb_strlen($title) > 255) {
        throw new \InvalidArgumentException('title must be 255 characters or fewer.');
    }

    /* Same soft BCP-47 grammar + code-point cap `song_importers.php` already
       validates a songbook-language folder suffix against
       (`_bulkImport_upsertSongbook()`) — reused rather than re-typed, so an
       alt title's language and a songbook's language agree on what counts
       as a plausible tag. */
    $language = $language === null ? '' : trim($language);
    if ($language === '') {
        $language = null;
    } elseif (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)*$/i', $language)) {
        throw new \InvalidArgumentException('language is not a recognised BCP-47 tag.');
    } else {
        $language = mb_substr($language, 0, 35);
    }

    $note = $note === null ? '' : trim($note);
    if ($note === '') {
        $note = null;
    } elseif (mb_strlen($note) > 255) {
        throw new \InvalidArgumentException('note must be 255 characters or fewer.');
    }

    if (!songAltTitlesTableExists($db)) {
        throw new \RuntimeException('tblSongAlternativeTitles does not exist on this install.');
    }

    /* SortOrder = per-song MAX(SortOrder)+1 — preserves add order; the read
       path (songAltTitlesList() above) breaks ties by Title, so this only
       matters when two alts are added within the same read window. */
    $so = $db->prepare('SELECT COALESCE(MAX(SortOrder), -1) + 1 FROM tblSongAlternativeTitles WHERE SongId = ?');
    $so->bind_param('s', $songId);
    $so->execute();
    $sortOrder = (int)$so->get_result()->fetch_row()[0];
    $so->close();

    $ins = $db->prepare(
        'INSERT IGNORE INTO tblSongAlternativeTitles (SongId, Title, Language, SortOrder, Note)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins->bind_param('sssis', $songId, $title, $language, $sortOrder, $note);
    $ins->execute();
    $created = $ins->affected_rows > 0;
    $ins->close();

    /* Re-select on EITHER outcome, so the echo is always the CANONICAL
       stored row (never the caller's own typed input) — matters most on a
       collision, where the existing row may carry a different
       Language/Note/SortOrder than what was just typed. */
    $sel = $db->prepare(
        'SELECT Id, Title, Language, Note, SortOrder
           FROM tblSongAlternativeTitles
          WHERE SongId = ? AND Title = ?'
    );
    $sel->bind_param('ss', $songId, $title);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();

    $shaped = [
        'id'        => (int)$row['Id'],
        'title'     => (string)$row['Title'],
        'language'  => (string)($row['Language'] ?? ''),
        'note'      => (string)($row['Note'] ?? ''),
        'sortOrder' => (int)$row['SortOrder'],
    ];

    return ['id' => $shaped['id'], 'created' => $created, 'row' => $shaped];
}

/**
 * Delete one alt title. `SongId` is part of the WHERE (cross-song
 * defence-in-depth, mirroring `song_external_id_delete`,
 * api2.php ~:4581-4582) — a stale/tampered id from a different song's panel
 * can never delete someone else's row. Already-gone -> `0` (idempotent
 * double-click, not an error — the SAME posture `song_link_remove` /
 * `song_external_id_delete` already take).
 *
 * ELI5: "remove this one alternate title — and if it's already gone,
 * that's fine, not an error."
 *
 * @param \mysqli $db
 * @param string  $songId
 * @param int     $id
 * @return int Affected rows (0 or 1).
 */
function songAltTitleDelete(\mysqli $db, string $songId, int $id): int
{
    if (!songAltTitlesTableExists($db)) return 0;   // un-migrated — nothing to delete

    $del = $db->prepare('DELETE FROM tblSongAlternativeTitles WHERE Id = ? AND SongId = ?');
    $del->bind_param('is', $id, $songId);
    $del->execute();
    $deleted = $del->affected_rows;
    $del->close();
    return (int)max(0, $deleted);
}

/**
 * Is `$alt` just the song's main title again? A plain `mb_strtolower`
 * case-insensitive equality check — deliberately NOT the aggressive
 * `ihymns_normalize_title()` fold (`title_normalize.php`), which strips
 * punctuation/leading-articles for EXACT-dedup grouping across the whole
 * catalogue. A punctuation-VARIANT alt ("Amazing Grace!" vs "Amazing
 * Grace") is legitimate data here, not a redundant duplicate of the main
 * title — only an exact (case-insensitive) restatement is refused.
 *
 * ELI5: "is this 'alternate' title actually just the song's real title
 * again, spelled exactly the same way (ignoring upper/lower case)?"
 *
 * @param string $alt
 * @param string $mainTitle
 * @return bool
 */
function songAltTitleIsRedundant(string $alt, string $mainTitle): bool
{
    return mb_strtolower(trim($alt)) === mb_strtolower(trim($mainTitle));
}
