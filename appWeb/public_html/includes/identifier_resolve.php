<?php

declare(strict_types=1);

/**
 * iHymns — Shared external-identifier resolver (#1741 P3)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Given a scheme name ("iswc") and whatever a visitor typed into the URL
 * ("t345246800.1"), this file answers "what does that point at in the
 * catalogue?" — a Work, a list of Songs, a list of Musicians, or nothing.
 * It is the ONE place `includes/pages/identifier.php` (the public
 * `/iswc/ /ccli/ /bowi/ /isrc/ /ipi/ /isni/` fragment) asks that question,
 * so the six alias routes share one lookup implementation instead of six.
 *
 * DETAILED
 * --------
 * `ihymns_resolve_identifier($db, $scheme, $rawCode)` is the single entry
 * point. It:
 *   1. Canonicalises `$rawCode` via `includes/identifier_normalize.php`
 *      (`ihymns_normalize_identifier()`) — an empty or malformed code short-
 *      circuits to an EMPTY result shape WITHOUT touching the database at
 *      all (mirrors `includes/pages/iswc.php`'s prior `if ($iswcCode !== '')`
 *      guard, generalised across all six schemes).
 *   2. Runs the scheme-appropriate lookup(s): a Work-row lookup for
 *      iswc/ccli/bowi (the "Linked Work" panel `iswc.php` already had), a
 *      multi-song list for iswc/ccli/isrc (the song-list body `iswc.php`
 *      already had — reused VERBATIM for iswc, extended to ccli/isrc), or a
 *      `tblMusicianIdentifiers` lookup for ipi/isni (the exact query shape
 *      `api.php`'s dormant `person_by_identifier`/`musician_by_identifier`
 *      action already uses, per the P3 brief — this is that query's first
 *      real consumer as a page rather than a JSON action).
 *
 * STRICT-SAFETY (CLAUDE.md red flag: "a page or handler that treats
 * `$db->query()`/`$db->prepare()` as returning `false` on error"): mysqli
 * runs under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`
 * (`includes/db_mysql.php`), so ANY failing statement — including "table
 * doesn't exist" on a pre-migration install — THROWS `mysqli_sql_exception`,
 * never returns false. Every lookup below is wrapped in `try/catch` and/or
 * gated behind an `INFORMATION_SCHEMA.TABLES` existence probe (mirroring
 * `iswc.php`'s existing `hasWorks` probe) so a missing `tblWorks` or
 * `tblMusicianIdentifiers` degrades the result to an empty list/`null`
 * rather than fataling the whole page. This file NEVER lets an exception
 * escape `ihymns_resolve_identifier()` — the caller (`identifier.php`) only
 * needs its OWN try/catch for the vanishingly rare case of a total DB outage
 * hit before any lookup even runs.
 *
 * SQL SAFETY (rule #5): every value that entered a query is `bind_param`-ed —
 * `$canonical` (user-derived, however cleaned) is ALWAYS a bound `?`
 * placeholder, never interpolated. The one column name that varies per call
 * (`Iswc`/`Ccli`/`Bowi` on `tblWorks`) is chosen from a fixed, hardcoded
 * PHP-source allow-list (`_ihymns_resolve_work()`'s `in_array(...)` guard) —
 * the same "hardcoded constants from PHP source" exception rule #5 already
 * carves out for column names, never a user-controlled string.
 *
 * `tblSongs.Isrc` is explicitly queried here, NEVER `tblSongIdentityMap
 * .uk_Isrc` (that table is a DIFFERENT, cross-DB-keyed identity map gated on
 * the separate #1010 DB-merge decision — out of scope, rule #20's "gated
 * cross-DB objects" note) — per the brief.
 *
 * @link .claude/catalogue-expansion-1741-plan.md §4      the P3 build-scoping ground truth this file implements
 * @link appWeb/public_html/includes/identifier_normalize.php the canonicalisation this file's lookups key off
 * @link appWeb/public_html/includes/pages/identifier.php     the one caller (the public fragment)
 * @link appWeb/public_html/includes/song_soft_delete.php     songVisibleSql() — soft-deleted songs never resolve (#1694)
 * @see #1741
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';

/**
 * True when $table exists in the current database schema.
 *
 * ELI5: "does this table exist yet?" — used so a pre-migration install (or
 * one where a table was dropped) degrades gracefully instead of the resolver
 * throwing mid-lookup.
 *
 * DETAILED / WHY: mirrors the `hasWorks` probe `includes/pages/iswc.php`
 * already used (`SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE
 * TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?`), generalised into a
 * reusable helper since this file needs the identical probe for TWO tables
 * (`tblWorks`, `tblMusicianIdentifiers`) rather than one. Bound via
 * `bind_param` even though $table is always an internal, hardcoded caller
 * value — cheap insurance, never a reason to skip the parameter API.
 *
 * @param \mysqli $db
 * @param string  $table
 * @return bool
 */
function _ihymns_table_exists(\mysqli $db, string $table): bool
{
    try {
        $stmt = $db->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $exists;
    } catch (\Throwable $e) {
        error_log('[identifier_resolve] table-existence probe failed for ' . $table . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Look up a single `tblWorks` row by one of its unique identifier columns.
 *
 * ELI5: "does a Work in the catalogue carry this exact ISWC / CCLI / BOWI?"
 *
 * @param \mysqli $db
 * @param string  $column       ONE of 'Iswc'|'Ccli'|'Bowi' — a fixed,
 *                               hardcoded caller-chosen constant, NEVER
 *                               user input (the in_array() guard below is
 *                               belt-and-braces against a future caller
 *                               mistake, not a real attacker surface).
 * @param string  $canonical    Already-normalised value (bound, not
 *                               interpolated).
 * @return array{slug:string,title:string}|null
 */
function _ihymns_resolve_work(\mysqli $db, string $column, string $canonical): ?array
{
    if (!in_array($column, ['Iswc', 'Ccli', 'Bowi'], true)) {
        return null;
    }
    if (!_ihymns_table_exists($db, 'tblWorks')) {
        return null;
    }
    try {
        /* $column is validated against the fixed allow-list above — never a
           user-controlled string — so this interpolation is the "hardcoded
           constant from PHP source" exception rule #5 documents. $canonical
           is the only variable input and IS bound. */
        $stmt = $db->prepare("SELECT Slug, Title FROM tblWorks WHERE {$column} = ? LIMIT 1");
        $stmt->bind_param('s', $canonical);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        return ['slug' => (string)$row['Slug'], 'title' => (string)$row['Title']];
    } catch (\Throwable $e) {
        error_log('[identifier_resolve] tblWorks lookup failed (' . $column . '): ' . $e->getMessage());
        return null;
    }
}

/**
 * List every visible `tblSongs` row whose $column matches $canonical,
 * grouped/ordered by songbook the same way the caller (identifier.php)
 * expects to render.
 *
 * ELI5: "which songs carry this exact ISWC / CCLI / ISRC?"
 *
 * DETAILED / WHY: the SELECT shape (columns, LEFT JOIN to tblSongbooks,
 * ORDER BY) is lifted verbatim from `includes/pages/iswc.php`'s prior query
 * — the only change across the three callers is which WHERE clause fires,
 * and (for CCLI only) an extra `<> ''` guard because `tblSongs.Ccli` is
 * `NOT NULL DEFAULT ''` (unlike the nullable `Iswc`/`Isrc` columns), so an
 * un-tagged song's empty string must never mass-match every other
 * un-tagged song.
 *
 * @param \mysqli $db
 * @param string  $column     ONE of 'Iswc'|'Ccli'|'Isrc' — fixed, hardcoded
 *                              caller-chosen constant (same posture as
 *                              _ihymns_resolve_work()'s $column).
 * @param string  $canonical
 * @return list<array<string,mixed>>
 */
function _ihymns_resolve_songs(\mysqli $db, string $column, string $canonical): array
{
    if (!in_array($column, ['Iswc', 'Ccli', 'Isrc'], true)) {
        return [];
    }
    try {
        /* CCLI is NOT NULL DEFAULT '' (schema.sql) — an empty $canonical
           would otherwise mass-match every un-tagged song. ISWC/ISRC are
           nullable so a bound '' simply matches nothing (no row has an
           empty string, only NULL), but the guard costs nothing to apply
           uniformly and protects against a future column-nullability
           change silently reopening this hole. */
        $extraGuard = ($column === 'Ccli') ? " AND s.{$column} <> ''" : '';
        $stmt = $db->prepare(
            "SELECT s.SongId, s.Number, s.Title, s.SongbookAbbr, sb.Name AS SongbookName, s.Language
               FROM tblSongs s
               LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
              WHERE s.{$column} = ?{$extraGuard} AND " . songVisibleSql($db, 's') . "
              ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC"
        );   /* #1694 — visible songs only */
        $stmt->bind_param('s', $canonical);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (\Throwable $e) {
        error_log('[identifier_resolve] tblSongs lookup failed (' . $column . '): ' . $e->getMessage());
        return [];
    }
}

/**
 * List every `tblMusicians` row linked to $canonical under $scheme via
 * `tblMusicianIdentifiers`.
 *
 * ELI5: "which musicians/composers have this exact IPI / ISNI on file?"
 *
 * DETAILED / WHY: this is the EXACT query shape `api.php`'s dormant
 * `person_by_identifier`/`musician_by_identifier` JSON action already runs
 * (`SELECT cp.Id,cp.Name,cp.Slug FROM tblMusicianIdentifiers i JOIN
 * tblMusicians cp ON cp.Id=i.MusicianId WHERE i.IdentifierType=? AND
 * i.IdentifierValue=?`) — reused here rather than re-derived, per the brief.
 * `$scheme` ('ipi'|'isni') doubles as the `IdentifierType` value being
 * matched; both it and `$canonical` are bound, never interpolated.
 *
 * @param \mysqli $db
 * @param string  $scheme     'ipi' or 'isni' — also the IdentifierType value.
 * @param string  $canonical
 * @return list<array{id:int,name:string,slug:string}>
 */
function _ihymns_resolve_musicians(\mysqli $db, string $scheme, string $canonical): array
{
    if (!_ihymns_table_exists($db, 'tblMusicianIdentifiers')) {
        return [];
    }
    try {
        $stmt = $db->prepare(
            'SELECT cp.Id AS id, cp.Name AS name, cp.Slug AS slug
               FROM tblMusicianIdentifiers i
               JOIN tblMusicians cp ON cp.Id = i.MusicianId
              WHERE i.IdentifierType = ? AND i.IdentifierValue = ?
              ORDER BY cp.Name
              LIMIT 50'
        );
        $stmt->bind_param('ss', $scheme, $canonical);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
        }
        unset($row);
        return $rows;
    } catch (\Throwable $e) {
        error_log('[identifier_resolve] tblMusicianIdentifiers lookup failed (' . $scheme . '): ' . $e->getMessage());
        return [];
    }
}

/**
 * Resolve a raw, curator/URL-typed identifier code for $scheme into
 * whatever the catalogue has on file — a Work, a list of Songs, and/or a
 * list of Musicians, per the scheme's declared `entity` in
 * `IHYMNS_ID_SCHEMES`.
 *
 * ELI5: the ONE function `includes/pages/identifier.php` calls to answer
 * "what does /iswc/T-345.246.800-1 (or /ipi/00028... , or /isrc/US...)
 * actually point at?"
 *
 * DETAILED / WHY: always returns a well-formed array (never throws — every
 * internal failure mode degrades to an empty result), so the caller's own
 * try/catch only needs to guard the vanishingly rare case where even the
 * canonicalisation step itself blows up (it doesn't touch the DB, so in
 * practice that catch is defensive-only, matching the "belt and braces"
 * posture the rest of this codebase uses around page bootstrapping).
 *
 * @param \mysqli $db
 * @param string  $scheme  One of IHYMNS_ID_SCHEMES's keys (iswc/ccli/bowi/isrc/ipi/isni).
 * @param string  $rawCode Curator/importer/URL-decoded raw value, any separator style.
 * @return array{
 *     scheme:string, label:string, canonical:string,
 *     entity:'work'|'song'|'musician',
 *     work:?array{slug:string,title:string},
 *     songs:list<array<string,mixed>>,
 *     musicians:list<array{id:int,name:string,slug:string}>
 * }
 */
function ihymns_resolve_identifier(\mysqli $db, string $scheme, string $rawCode): array
{
    $registry = IHYMNS_ID_SCHEMES[$scheme] ?? null;
    $label    = $registry['label']  ?? strtoupper($scheme);
    /* Unknown scheme defaults to 'work' purely so the return shape stays
       well-formed for a caller that (mistakenly) invokes this with a scheme
       outside the registry — api.php's page switch never actually allows
       that (only the six registered schemes reach this function), so this
       is defensive-only, matching ihymns_normalize_identifier()'s own
       "unreachable but not fatal" default branch. */
    $entity   = $registry['entity'] ?? 'work';

    $emptyResult = static function (string $canonical) use ($scheme, $label, $entity): array {
        return [
            'scheme'    => $scheme,
            'label'     => $label,
            'canonical' => $canonical,
            'entity'    => $entity,
            'work'      => null,
            'songs'     => [],
            'musicians' => [],
        ];
    };

    if ($registry === null) {
        return $emptyResult('');
    }

    $canonical = ihymns_normalize_identifier($scheme, $rawCode);
    if ($canonical === null || $canonical === '') {
        /* null = unknown scheme or malformed ISWC; '' = genuinely empty
           input. Either way there is nothing to query — never run a lookup
           against an empty/invalid value (rule #17 posture: a bounded
           lookup, never an accidental whole-table scan via a blank WHERE). */
        return $emptyResult('');
    }

    $result = $emptyResult($canonical);

    try {
        switch ($scheme) {
            case 'iswc':
                $result['work']  = _ihymns_resolve_work($db, 'Iswc', $canonical);
                $result['songs'] = _ihymns_resolve_songs($db, 'Iswc', $canonical);
                break;
            case 'ccli':
                $result['work']  = _ihymns_resolve_work($db, 'Ccli', $canonical);
                $result['songs'] = _ihymns_resolve_songs($db, 'Ccli', $canonical);
                break;
            case 'bowi':
                $result['work'] = _ihymns_resolve_work($db, 'Bowi', $canonical);
                break;
            case 'isrc':
                $result['songs'] = _ihymns_resolve_songs($db, 'Isrc', $canonical);
                break;
            case 'ipi':
            case 'isni':
                $result['musicians'] = _ihymns_resolve_musicians($db, $scheme, $canonical);
                break;
        }
    } catch (\Throwable $e) {
        /* Belt-and-braces — every per-lookup helper above already has its
           own try/catch and returns a safe default, so this outer catch
           should be unreachable in practice. Kept anyway: a resolver that
           can throw defeats the entire point of this function existing
           (the caller relies on it NEVER throwing under a partial-schema
           install). */
        error_log('[identifier_resolve] resolve failed for scheme=' . $scheme . ': ' . $e->getMessage());
    }

    return $result;
}
