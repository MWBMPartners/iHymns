<?php

declare(strict_types=1);

/**
 * iHymns — Songbook visibility (disable-a-songbook foundation)
 * ==============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A curator can "disable" a songbook — the whole book (and every song that
 * lives in it) disappears from the public site everywhere, but stays fully
 * visible and editable in `/manage/*`, and nothing is deleted. This file is
 * the ONE place that knows how to write the tiny bit of SQL every public
 * reader needs to respect that: "…AND the songbook isn't disabled".
 *
 * DETAILED / WHY THIS IS A CLONE OF song_soft_delete.php's SHAPE, NOT A
 * DIFFERENT DESIGN
 * ----------------------------------------------------------------------------
 * `includes/song_soft_delete.php` already solved the identical problem one
 * grain up (a SONG can be hidden without deleting it) and CLAUDE.md's
 * modularity rule is explicit: reuse the shape, don't invent a second one.
 * Both files share:
 *   - a memoised, SUCCESS-only `INFORMATION_SCHEMA` column probe so an
 *     un-migrated install degrades to today's behaviour instead of throwing
 *     (the #1228 STRICT-mode lesson — the three docroots share ONE MySQL and
 *     migrations are web-run, never auto-applied, so "it's in schema.sql" is
 *     never proof it exists on THIS install);
 *   - the `'1=1'` degrade trick — callers embed the fragment this file mints
 *     unconditionally after `WHERE …AND `, so the SQL stays valid with ZERO
 *     per-site branching, and no leak is possible on an un-migrated install
 *     because a disabled songbook cannot exist before the column does;
 *   - alias validation via the ONE shared identifier gate
 *     (`ihymnsSqlIdentifierSafe()`, `sql_identifier.php`) BEFORE the `$ready`
 *     branch, so a hostile alias throws in EITHER migration state — a caller
 *     bug must never be masked by "well, the columns aren't there yet".
 *
 * THE ONE DELIBERATE DIVERGENCE FROM songSoftDeleteReady()'s POSTURE:
 * `songSoftDeleteReady()` does NOT catch a probe failure — its doc-block
 * argues a broken `INFORMATION_SCHEMA` read means the very next query (the
 * real `tblSongs` read) will fail identically anyway, so the page's existing
 * error handling is the right responder. `songbookDisableReady()` below is
 * asked to behave differently — the task brief for this module is explicit:
 * "any probe error → false (treat as un-migrated → predicates become 1=1)".
 * That is a deliberately MORE forgiving posture for this specific gate: this
 * predicate is stitched into a much wider blast radius than one table's own
 * read (every `tblSongs`-adjacent public query gets an `songServableSql()`
 * `NOT EXISTS` subquery bolted on, per the epic's read-path census), so a
 * transient `INFORMATION_SCHEMA` hiccup here should degrade the FEATURE
 * ("nothing is disabled today") rather than take down every public songbook
 * and song page across the site. Recorded here explicitly so a future reader
 * comparing the two files side by side does not "fix" this into matching
 * song_soft_delete.php's stricter posture — the difference is intentional.
 *
 * SEPARATE SUB-CONCERNS, TWO PREDICATES, NOT ONE
 * ----------------------------------------------------------------------------
 * `songbookVisibleSql()` answers "is THIS songbook row itself visible?" —
 * for songbook-grain reads (`getSongbooks()`, `getSongbook()`, the
 * `/songbooks` list, …). `songServableSql()` answers a DIFFERENT question at
 * SONG grain: "does the song ON THIS ROW belong to a disabled songbook?" —
 * for the ~30 `tblSongs` reads that never join `tblSongbooks` at all and
 * would otherwise have no way to know. The two are NOT folded into one
 * function (the adversarial notes in the epic plan are explicit about why):
 * folding songbook-disabled awareness into `songVisibleSql()` (the SONG
 * soft-delete predicate) would corrupt `tblSongbooks.SongCount` stability —
 * that cached count deliberately does NOT change when a book is disabled
 * (disabling hides the book from listings; it does not shrink the number on
 * the tile), so a merged predicate would make the two disagree.
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT DO (commit 1 of 7 — pure foundations)
 * ----------------------------------------------------------------------------
 * No writes. No admin toggle. No `SongData` wiring, no call sites updated —
 * every one of those lands in later commits of this epic (the plan's build
 * sequence). Until then this file is dead code: nothing requires it, so it
 * is a byte-identical no-op on the running site. `IsDisabled` does not exist
 * yet either (that is the migration, commit 2) — `songbookDisableReady()`
 * will read false everywhere until that lands, which is exactly the
 * "degrades to today" contract this design promises.
 *
 * @see appWeb/public_html/includes/song_soft_delete.php  the shape this clones (songSoftDeleteReady/songVisibleSqlFor/songVisibleSql)
 * @see appWeb/public_html/includes/sql_identifier.php     ihymnsSqlIdentifierSafe() — the shared "safe to paste" gate
 * @see .claude/songbook-catalogue-enhancements-plan.md    the epic plan (Architecture + Feature 1 sections) this implements
 * @see tests/php/test-songbook-visibility-core.php        the behavioural lock for this file's pure cores
 * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html
 */

/* Direct access blocked so this can't be loaded as an endpoint — mirrors
   song_soft_delete.php's identical top-of-file guard. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sql_identifier.php';

/**
 * Does `tblSongbooks.IsDisabled` exist on THIS install? — gated, FAIL-SAFE.
 *
 * ELI5: "has the 'disable a songbook' migration been applied yet?" — and if we
 * can't even ask the question safely, assume no.
 *
 * DETAILED: memoised per request (`static`), SUCCESS ONLY — a failed probe
 * does not populate the memo, so a transient hiccup on request N does not
 * pin "un-migrated" for the rest of that same request if a later call
 * happens to succeed (the `songSoftDeleteReady()` memo shape, reused
 * verbatim). Unlike that precedent, THIS probe is wrapped in try/catch and
 * answers `false` on any failure — see the file doc-block's "ONE DELIBERATE
 * DIVERGENCE" section for why that is the correct posture here specifically
 * (this gate's blast radius is much wider than one table's own read).
 *
 * Checks exactly ONE column (`IsDisabled`) — unlike `songSoftDeleteReady()`'s
 * five-column lockstep COUNT, there is only one column for this feature to
 * add, so there is no partial-apply state to protect against.
 *
 * @param \mysqli $db Live connection from getDbMysqli().
 * @return bool True only when `tblSongbooks.IsDisabled` exists AND the probe
 *              itself succeeded.
 */
function songbookDisableReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongbooks'
                AND COLUMN_NAME = 'IsDisabled'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
    } catch (\Throwable $e) {
        /* FAIL-SAFE: deliberately NOT memoised (`$ready` stays null), so a
           transient failure never pins this request's answer — see the file
           doc-block. */
        return false;
    }
    $ready = ($row !== null && (int)$row[0] === 1);
    return $ready;
}

/**
 * The songbook-grain visibility predicate a hand-built SELECT embeds — PURE.
 *
 * ELI5: the little "and this songbook isn't disabled" clause, or a harmless
 * "1=1" when the column doesn't exist yet.
 *
 * DETAILED: call sites write `WHERE … AND ` . songbookVisibleSql($db) (or,
 * in a test, this pure core directly) unconditionally — on a migrated
 * install with the feature acted on it is `b.IsDisabled = 0`; on an
 * un-migrated one, or before any book has ever been disabled, it is `1=1`,
 * so the SQL stays valid with zero per-site branching. See the file
 * doc-block for why this answers a DIFFERENT question than
 * `songServableSqlFor()` below (songbook-grain vs song-grain).
 *
 * ALIAS VALIDATION mirrors `songVisibleSqlFor()` byte-for-byte: the shared
 * `ihymnsSqlIdentifierSafe()` gate supplies "safe to paste", narrowed here to
 * `[A-Za-z_][A-Za-z0-9_]*` (no leading digit, no `$`) because every alias in
 * this codebase is a plain word — and validated BEFORE the `$ready` branch,
 * so a hostile alias throws in BOTH migration states (a caller bug must
 * never be masked by "well, the feature isn't live yet").
 *
 * @param bool   $ready songbookDisableReady()'s answer (injected so tests can call both branches).
 * @param string $alias Table alias to prefix, or '' for the bare column.
 * @return string 'b.IsDisabled = 0'-shaped predicate, or '1=1'.
 * @throws \InvalidArgumentException on an alias that fails validation.
 */
function songbookVisibleSqlFor(bool $ready, string $alias = 'b'): string
{
    if ($alias !== ''
        && !(ihymnsSqlIdentifierSafe($alias) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $alias) === 1)) {
        throw new \InvalidArgumentException(
            'songbookVisibleSqlFor(): refusing unsafe SQL alias ' . var_export($alias, true)
        );
    }
    if (!$ready) {
        return '1=1';
    }
    return $alias === '' ? 'IsDisabled = 0' : $alias . '.IsDisabled = 0';
}

/**
 * DB-bound driver for songbookVisibleSqlFor() — what songbook-grain call
 * sites actually embed.
 *
 * ELI5: same as above, but it asks the database whether the column exists.
 *
 * @param \mysqli $db    Live connection from getDbMysqli().
 * @param string  $alias Table alias to prefix (default 'b' — the house
 *                       convention for `tblSongbooks` in this codebase's
 *                       joined queries), or '' for the bare column.
 * @return string See songbookVisibleSqlFor().
 */
function songbookVisibleSql(\mysqli $db, string $alias = 'b'): string
{
    return songbookVisibleSqlFor(songbookDisableReady($db), $alias);
}

/**
 * The song-grain "does this song's songbook keep it servable?" predicate a
 * hand-built SELECT embeds — PURE.
 *
 * ELI5: for a query that only ever looks at `tblSongs` and has no join to
 * `tblSongbooks` in sight, this is the clause that still lets it say "…but
 * not if the song's book got disabled".
 *
 * DETAILED / WHY A CORRELATED NOT EXISTS RATHER THAN A JOIN: the ~30 raw
 * public sites this predicate is meant for (the epic plan's Feature 1
 * read-path census) each already have their own hand-built `tblSongs` query
 * shape — adding a `NOT EXISTS` clause to a `WHERE` is a pure textual
 * append, identical in spirit to how `songVisibleSql()` is appended, with NO
 * requirement to restructure the query's FROM/JOIN list (which would be a
 * much larger, much riskier diff across every call site for this same
 * feature). The subquery correlates on `Abbreviation = <song>.SongbookAbbr`
 * — `tblSongbooks.Abbreviation` is `tblSongs.SongbookAbbr`'s FK target in
 * spirit (never a formal FK — see `manage`'s existing joins), so this is the
 * same correlation every other songbook-join in the codebase already uses.
 *
 * `$songAlias`'s EMPTY-STRING CASE IS EXPLICIT, NOT A BARE COLUMN: passing
 * `''` means "the bare `tblSongs` table, not aliased" — so the subquery
 * reads `_dsb.Abbreviation = tblSongs.SongbookAbbr`, the literal table name,
 * NEVER a dangling unqualified `SongbookAbbr` (which would be ambiguous, or
 * worse, silently resolve against the WRONG table in a query that also
 * selects from `tblSongbooks` unaliased). This is the one place this
 * function's contract differs in SHAPE from `songVisibleSqlFor()`'s
 * empty-alias case (a bare unqualified column is fine there because that
 * predicate only ever touches ONE table).
 *
 * ALIAS VALIDATION: only when `$songAlias` is non-empty — an empty string
 * is replaced by the hardcoded literal `'tblSongs'` below, never
 * interpolated from the argument, so there is nothing user-controlled to
 * validate in that branch. Still validated (and still throws) in the
 * `$ready === false` branch when non-empty, matching
 * `songbookVisibleSqlFor()`'s "throws in both migration states" contract.
 *
 * @param bool   $ready     songbookDisableReady()'s answer (injected so tests can call both branches).
 * @param string $songAlias Alias of the `tblSongs` row being filtered, or ''
 *                          for the bare `tblSongs` table name.
 * @return string 'NOT EXISTS (SELECT 1 FROM tblSongbooks _dsb WHERE
 *                _dsb.Abbreviation = <ref>.SongbookAbbr AND
 *                _dsb.IsDisabled = 1)'-shaped predicate, or '1=1'.
 * @throws \InvalidArgumentException on a non-empty alias that fails validation.
 */
function songServableSqlFor(bool $ready, string $songAlias = 's'): string
{
    if ($songAlias !== ''
        && !(ihymnsSqlIdentifierSafe($songAlias) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $songAlias) === 1)) {
        throw new \InvalidArgumentException(
            'songServableSqlFor(): refusing unsafe SQL alias ' . var_export($songAlias, true)
        );
    }
    if (!$ready) {
        return '1=1';
    }
    $songRef = $songAlias === '' ? 'tblSongs' : $songAlias;
    return 'NOT EXISTS (SELECT 1 FROM tblSongbooks _dsb WHERE _dsb.Abbreviation = '
         . $songRef . '.SongbookAbbr AND _dsb.IsDisabled = 1)';
}

/**
 * DB-bound driver for songServableSqlFor() — what song-grain raw public
 * sites actually embed.
 *
 * ELI5: same as above, but it asks the database whether the column exists.
 *
 * @param \mysqli $db        Live connection from getDbMysqli().
 * @param string  $songAlias Alias of the `tblSongs` row (default 's' — the
 *                           house convention), or '' for the bare table name.
 * @return string See songServableSqlFor().
 */
function songServableSql(\mysqli $db, string $songAlias = 's'): string
{
    return songServableSqlFor(songbookDisableReady($db), $songAlias);
}
