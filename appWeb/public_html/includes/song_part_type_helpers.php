<?php

declare(strict_types=1);

/**
 * iHymns — Song-part type registry reader (#1869, epic #1863, CLAUDE.md rule #43/#20)
 * =====================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The v2 Song Editor's "Structure" tab shows a dropdown of section types
 * (Verse, Chorus, Bridge, …) when you edit one part of a song. Before this
 * file existed, that dropdown's 10 choices were typed directly into the
 * editor's JavaScript. This file reads the REAL, growable list from the
 * database instead (`tblSongPartTypes`, #1138 — already seeded with 16
 * types) so a curator can add a new section type in the database without
 * anyone having to edit and redeploy the editor's code.
 *
 * DETAILED — WHY A SEPARATE READER FROM lyric_lines_sync.php's helper (rule #22)
 * -------------------------------------------------------------------------------
 * `includes/lyric_lines_sync.php::lyricLinesPartTypeSlug()` already queries
 * `tblSongPartTypes`, but it answers a DIFFERENT question — "is this free-text
 * component Type a known slug?" (Slug-keyed set, used to stamp
 * `tblLyricLines.PartTypeSlug` at write time). This file answers "what is the
 * FULL, ORDERED vocabulary, for a picker to render?" (Slug + display Name, in
 * curator-defined SortOrder). Re-deriving one from the other would either leak
 * the write-path's internal shape into a UI concern or force the write path to
 * carry display strings it never needs — so this is a second, small, honest
 * reader over the same table (rule #22 bans re-forking WRITE logic /
 * find-or-create funnels; a second READ shaped for a different caller is not
 * that).
 *
 * DORMANT-SAFE / NEVER THROWS (rule #19/#20/#28C pattern, mirrors
 * includes/licence_registry.php)
 * -------------------------------------------------------------------------------
 * Migrations are NOT auto-applied on deploy (rule #19) and mysqli runs under
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (rule #9), so a query against an
 * absent `tblSongPartTypes` THROWS rather than returning false. Every path
 * here is existence-gated (INFORMATION_SCHEMA, never a bare SELECT) and
 * wrapped in try/catch, so this file degrades to an EMPTY array — never a
 * white screen — on an un-migrated install or a broken connection. Unlike
 * licence_registry.php's LICENCE_TYPES_FALLBACK, there is deliberately NO
 * hardcoded PHP-side fallback vocabulary here: the one place a fallback lives
 * is the CLIENT (structure-tab.js's pre-existing COMPONENT_TYPES const),
 * because that is the only caller and duplicating the fallback list
 * server-side too would just be a second copy to drift (rule #35).
 *
 * @link appWeb/.sql/schema.sql                                  tblSongPartTypes DDL (#1138)
 * @link appWeb/.sql/migrate-song-part-types.php                  the 16-row seed this mirrors
 * @link appWeb/public_html/includes/lyric_lines_sync.php         lyricLinesPartTypeSlug() — the sibling write-path reader
 * @link appWeb/public_html/manage/editor/editor2.php             the ONE caller (bootstrap payload emitter)
 * @link appWeb/public_html/manage/editor/v2/structure-tab.js     the ONE consumer (Structure tab type <select>)
 * @see  tests/php/test-song-part-types-registry.php
 * @see  #1869, epic #1863, CLAUDE.md rule #43 ("the LAST picker item" — this one is
 *       registry SOURCING, not a typeahead, per the rule's own text)
 */

/* Block direct HTTP access — this is a library, never a page (mirrors
   licence_registry.php / tune_helpers.php / publisher_helpers.php). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * True when `tblSongPartTypes` exists in the current database. Existence-gated
 * via INFORMATION_SCHEMA (never a bare `SELECT … FROM tblSongPartTypes`, which
 * would throw under STRICT mysqli on an un-migrated install) and never throws
 * itself — any failure degrades to false.
 */
function songPartTypesTableExists(\mysqli $db): bool
{
    try {
        $stmt = $db->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $table = 'tblSongPartTypes';
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $exists;
    } catch (\Throwable $_e) {
        return false;
    }
}

/**
 * The song-part type vocabulary for a picker: the FULL `tblSongPartTypes` set,
 * in curator-defined `SortOrder`. Returns `[]` — never throws — when the table
 * is absent, empty, or the connection fails, so an un-migrated install (or a
 * momentary DB hiccup) degrades cleanly; the caller (editor2.php's bootstrap
 * payload) ships whatever this returns, and the ONE consumer
 * (structure-tab.js) falls back to its own minimal built-in list when it
 * receives nothing (rule #19/#20 — the fallback lives client-side, see this
 * file's header).
 *
 * @param ?\mysqli $db  null = getDbMysqli() + per-request cache; an explicit
 *                      connection (e.g. a test double) always runs fresh and
 *                      is never cached, so passing one is safe to repeat with
 *                      a different double across a single test process.
 * @return list<array{slug:string, name:string}>  ordered by SortOrder, Slug
 */
function songPartTypesForPicker(?\mysqli $db = null): array
{
    static $cache = null;
    if ($db === null && $cache !== null) {
        return $cache;
    }

    $out = [];
    try {
        $conn = $db ?? getDbMysqli();
        if (songPartTypesTableExists($conn)) {
            $res = $conn->query(
                'SELECT Slug, Name FROM tblSongPartTypes ORDER BY SortOrder ASC, Slug ASC'
            );
            if ($res !== false) {
                foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
                    $slug = (string)$r['Slug'];
                    if ($slug === '') {
                        continue; // never emit an unusable <option value="">
                    }
                    $out[] = [
                        'slug' => $slug,
                        'name' => (string)$r['Name'],
                    ];
                }
            }
        }
    } catch (\Throwable $_e) {
        $out = []; // degrade to empty — never propagate; the client owns the fallback
    }

    if ($db === null) {
        $cache = $out;
    }
    return $out;
}
