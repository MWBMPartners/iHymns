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
 * cross-DB objects" note; #1749 full unification freezes it as legacy for
 * the SAME reason, §2.3 of that build spec) — per the brief.
 *
 * #1749 FULL UNIFICATION — THE STORE IS NOW THE AUTHORITY, THE COLUMN IS THE
 * DEGRADATION PATH (re-anchoring this file's own doc-block; the SQL below was
 * already right, built the Phase-1 union — only the NARRATIVE direction
 * changes here). Before #1749, `tblSongExternalIds` was a SUPPLEMENT this
 * file's ISRC lookup unioned in on top of the "real" `tblSongs.Isrc` column.
 * After #1749, the relationship inverts: the store is the recording-ID
 * authority, and `tblSongs.Isrc` is kept in lockstep by
 * `includes/song_external_ids.php`'s `songExternalIdSyncIsrcDenorm()` (the
 * store->column projection with "the last word" in every write). The column
 * arm of the union below therefore now survives ONLY as the un-migrated-
 * install degradation path — on a migrated + reconciled install the column
 * is always a SUBSET of what the store already contains (column ⊆ store), so
 * the column arm adds nothing there; it only matters when
 * `tblSongExternalIds` doesn't exist yet.
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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_external_ids.php';   /* #1749 full unification — songExternalIdUnionArmSql(), the ONE store-union predicate builder */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_visibility.php';   /* #1765 — songServableSql() */

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
 * ELI5: build the songs-by-identifier SELECT text — optionally widened so a
 * code held only in the tblSongExternalIds store still finds its song.
 * DETAILED: pure so tests/php/test-identifier-resolve-union.php can call it
 * directly (rule #34). $column is allow-listed by the caller; $visibleSql is
 * songVisibleSql()'s output (or '1=1' in tests); $withStore=true emits the
 * #1749 union arm. The OR pair is PARENTHESISED so the visibility predicate
 * applies to BOTH arms (the SongData.php:2312 #1694 lesson). 1 placeholder
 * without the store, 3 with (value, IdType, value) — the caller binds.
 *
 * DETAILED / WHY (CCLI guard): CCLI is NOT NULL DEFAULT '' (schema.sql) — an
 * empty $canonical would otherwise mass-match every un-tagged song. ISWC/ISRC
 * are nullable so a bound '' simply matches nothing (no row has an empty
 * string, only NULL), but the guard costs nothing to apply uniformly and
 * protects against a future column-nullability change silently reopening
 * this hole.
 *
 * KNOWN, ACCEPTED LIMITATION: the store lookup is an exact match on the
 * CANONICAL code, same as the `tblSongs.Isrc` arm already is — a legacy
 * raw-separator value copied verbatim by the #1747 backfill won't match a
 * canonical query, exactly as it doesn't today via the column arm. Not a
 * regression; not this issue's job to fix.
 *
 * @param string $column     ONE of 'Iswc'|'Ccli'|'Isrc' — fixed, hardcoded
 *                              caller-chosen constant (same posture as
 *                              _ihymns_resolve_work()'s $column).
 * @param string $visibleSql songVisibleSql()'s output (or a test's '1=1').
 * @param bool   $withStore  true = emit the #1749 tblSongExternalIds union arm.
 * @return string
 * @see #1749
 */
function _ihymns_resolve_songs_sql(string $column, string $visibleSql, bool $withStore): string
{
    /* @deleted-visible: pure query-builder unit (#1694) — this text-only
       function never itself calls songVisibleSql(); its SOLE caller
       (_ihymns_resolve_songs() immediately below) does, and passes the
       ALREADY-COMPUTED predicate in via $visibleSql. Splitting the SQL
       text from the \mysqli call is what makes this builder directly
       testable (rule #34 — tests/php/test-identifier-resolve-union.php
       calls it with '1=1'/a real predicate and asserts on the returned
       text), so it cannot itself hold a literal songVisibleSql(...) call
       without losing that property. No code path invokes this builder
       with anything other than a real songVisibleSql() result at runtime.
       @disabled-visible: same reasoning, one predicate over (#1765) — the
       caller's $visibleSql ALREADY carries songServableSql() too when
       $publicOnly (its default), so this builder text-splices whatever it's
       handed either way. */
    $extraGuard = ($column === 'Ccli') ? " AND s.{$column} <> ''" : '';
    /* #1749 full unification — the union arm is now BUILT by the shared
       songExternalIdUnionArmSql() (includes/song_external_ids.php), never
       re-inlined here (rule #22 — ONE predicate, three consumers: this file,
       api.php's song_by_identifier, lyricsIngest_resolveSong()). Whitespace
       differs from the pre-#1749 inline literal this replaces (a single-line
       fragment vs. the old multi-line one) — the properties
       tests/php/test-identifier-resolve-union.php asserts on (placeholder
       count, "OR ... IN" ordering, the enclosing parens) are unchanged, and
       the test does not byte-compare the SQL text itself. */
    $match = $withStore
        ? "(s.{$column} = ?{$extraGuard} OR " . songExternalIdUnionArmSql('s.SongId') . ')'
        : "s.{$column} = ?{$extraGuard}";
    return "SELECT s.SongId, s.Number, s.Title, s.SongbookAbbr, sb.Name AS SongbookName, s.Language
              FROM tblSongs s
              LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
             WHERE {$match} AND {$visibleSql}
             ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC";
}

/**
 * PURE decision: should the #1749 tblSongExternalIds union arm be emitted?
 *
 * ELI5: "widen the search into the external-IDs store" is true ONLY when the
 * caller asked for a store IdType AND the store table actually exists on this
 * install. Both must hold.
 *
 * DETAILED / WHY A NAMED PURE FUNCTION (rule #34): the store-union WIRING —
 * that the gate's polarity is right (union when the table is PRESENT, not
 * absent) and that its result actually drives `$useStore` rather than being
 * discarded — has a runtime handle only through this function. Extracting it
 * lets `tests/php/test-identifier-resolve-union.php` call it in BOTH
 * directions directly, so a polarity inversion (`!$storeTableExists`) or a
 * dead-code disable is caught by a behavioural assertion, not merely a
 * source co-occurrence scan (the exact wrong-but-green gap an adversarial
 * audit found in the first-draft guard). `_ihymns_resolve_songs()` below MUST
 * assign `$useStore` from this — the guard asserts that too.
 *
 * @param bool        $storeTableExists  _ihymns_table_exists($db,'tblSongExternalIds').
 * @param string|null $storeIdType       the companion store IdType, or null (iswc/ccli).
 * @return bool
 * @see #1749
 */
function _ihymns_resolve_use_store(bool $storeTableExists, ?string $storeIdType): bool
{
    return $storeIdType !== null && $storeTableExists;
}

/**
 * List every visible `tblSongs` row whose $column matches $canonical —
 * OR (when $storeIdType is given and the store table exists) whose SongId
 * appears in `tblSongExternalIds` under that IdType/value — grouped/ordered
 * by songbook the same way the caller (identifier.php) expects to render.
 *
 * ELI5: "which songs carry this exact ISWC / CCLI / ISRC?" — as of #1749,
 * for ISRC this also asks "…or does the external-IDs store know a song has
 * it, even though the tblSongs.Isrc column doesn't?"
 *
 * DETAILED / WHY: the SELECT shape (columns, LEFT JOIN to tblSongbooks,
 * ORDER BY) is lifted verbatim from `includes/pages/iswc.php`'s prior query
 * — the only change across the three callers is which WHERE clause fires.
 * The #1749 widening lives in the pure `_ihymns_resolve_songs_sql()`
 * builder above; this function's job is just the existence-gate + bind +
 * execute (rules #5/#9).
 *
 * @param \mysqli     $db
 * @param string      $column       ONE of 'Iswc'|'Ccli'|'Isrc'.
 * @param string      $canonical
 * @param string|null $storeIdType  #1749 — when non-null, ALSO union against
 *                       `tblSongExternalIds.IdType = $storeIdType` (e.g.
 *                       'isrc'). null = pre-#1749 behaviour (column only) —
 *                       the iswc/ccli call sites pass null since there is no
 *                       companion store column for those schemes yet.
 * @param bool        $publicOnly   #1765 Feature 1 — true (default) ALSO
 *                       requires `songServableSql()` (the song's songbook
 *                       isn't disabled), matching every other public read in
 *                       this codebase's fail-safe default. Every PUBLIC
 *                       caller of this driver (currently `identifier.php`
 *                       via `ihymns_resolve_identifier()`) keeps the
 *                       default. A future internal/system caller that must
 *                       resolve identity across a disabled book too (the
 *                       `lyrics_ingest.php` resolver shape — see
 *                       `lyricsIngest_resolveSong()`'s `@deleted-visible:`
 *                       markers for the identical reasoning one predicate
 *                       over) passes `false` and marks its call site
 *                       `@disabled-visible: <reason>`, exactly like the
 *                       soft-delete half already does.
 * @return list<array<string,mixed>>
 */
function _ihymns_resolve_songs(\mysqli $db, string $column, string $canonical, ?string $storeIdType = null, bool $publicOnly = true): array
{
    if (!in_array($column, ['Iswc', 'Ccli', 'Isrc'], true)) {
        return [];
    }
    try {
        /* #1749 — existence-gated (rules #5/#9: mysqli STRICT throws on a
           missing table; the probe reuses this file's own helper, never a
           second INFORMATION_SCHEMA idiom). Un-migrated install ⇒ exactly
           the pre-#1749 single-column query. */
        $useStore = _ihymns_resolve_use_store(_ihymns_table_exists($db, 'tblSongExternalIds'), $storeIdType);
        /* #1694 — visible (not soft-deleted) songs only.
           #1765 Feature 1 — PUBLIC callers (the default) also exclude a song
           whose songbook has been disabled; see the parameter doc above. */
        $visibleSql = songVisibleSql($db, 's');
        if ($publicOnly) {
            $visibleSql .= ' AND ' . songServableSql($db, 's');
        }
        $stmt = $db->prepare(_ihymns_resolve_songs_sql($column, $visibleSql, $useStore));
        if ($useStore) {
            $stmt->bind_param('sss', $canonical, $storeIdType, $canonical);
        } else {
            $stmt->bind_param('s', $canonical);
        }
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
 * @param bool    $publicOnly #1765 Feature 1 — forwarded to `_ihymns_resolve_songs()`
 *                    (see its doc-block); true (default, fail-safe) for every
 *                    public caller — `includes/pages/identifier.php` is the
 *                    only one today and never overrides it.
 * @return array{
 *     scheme:string, label:string, canonical:string,
 *     entity:'work'|'song'|'musician',
 *     work:?array{slug:string,title:string},
 *     songs:list<array<string,mixed>>,
 *     musicians:list<array{id:int,name:string,slug:string}>
 * }
 */
function ihymns_resolve_identifier(\mysqli $db, string $scheme, string $rawCode, bool $publicOnly = true): array
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
                $result['songs'] = _ihymns_resolve_songs($db, 'Iswc', $canonical, null, $publicOnly);
                break;
            case 'ccli':
                $result['work']  = _ihymns_resolve_work($db, 'Ccli', $canonical);
                $result['songs'] = _ihymns_resolve_songs($db, 'Ccli', $canonical, null, $publicOnly);
                break;
            case 'bowi':
                $result['work'] = _ihymns_resolve_work($db, 'Bowi', $canonical);
                break;
            case 'isrc':
                /* #1749 full unification — the store IS the authority; this
                   union is what lets an ISRC held only as a store row (a
                   manual second recording) resolve here — the column arm
                   below it is now the un-migrated-install degradation path,
                   not the primary lookup (see this file's own doc-block). */
                $result['songs'] = _ihymns_resolve_songs($db, 'Isrc', $canonical, 'isrc', $publicOnly);
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
