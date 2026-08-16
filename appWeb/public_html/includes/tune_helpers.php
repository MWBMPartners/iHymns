<?php

declare(strict_types=1);

/**
 * iHymns — Tune registry find-or-create helper (#1741 P4b §2.4.3)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A curator types a tune name ("HYFRYDOL") into a text box on the Works
 * admin form. This file answers "does a `tblTunes` row already called that
 * exist? If so, use it. If not, make one." — the ONE place that decision
 * gets made, so a Work and a Song editor (later) never invent two different
 * tune rows for the same name because each rolled its own lookup.
 *
 * DETAILED / WHY A SHARED FUNNEL, AND WHY BUILT NOW IN P4b
 * ----------------------------------------------------------------------------
 * `tblTunes` (#1090) already has a "promote free-text TuneName into a
 * first-class row" step, but it only ever ran ONCE as a backfill migration
 * (`migrate-tunes-entity.php`'s `_migTunes_slugify()` + collision-suffix
 * loop, :106-113 / :225-231) — nothing in the LIVE app writes a new tune row
 * today. `manage/works.php` (#1741 P4b) is the first live write path that
 * needs one: a curator typing a new work's tune name has to either match an
 * EXISTING tune (so `Tune: HYFRYDOL` links the same `/tune/hyfrydol` page a
 * song using that tune already links to) or create a new row.
 *
 * `ihymns_tune_slugify()` below is RE-HOMED byte-for-byte from
 * `migrate-tunes-entity.php::_migTunes_slugify()` (iconv ASCII//TRANSLIT →
 * lowercase → non-alnum runs collapse to '-' → trim → 'tune' fallback for an
 * all-non-Latin name → 130-char cap leaving headroom inside `Slug`'s
 * VARCHAR(140) for a '-N' collision suffix) so the ONE live slug fold and
 * the ONE backfill slug fold produce IDENTICAL slugs for the same input —
 * two independently-typed copies would drift the moment either was edited.
 * The migration script KEEPS its own private copy (it is a one-shot,
 * point-in-time script, not a live code path this file's callers share —
 * re-homing would mean the migration `require`s live app code, which is
 * backwards for a schema script that must still run standalone from the CLI
 * against a bare checkout).
 *
 * `tuneFindOrCreateByName()` is the ONE find-or-create funnel: `manage/
 * works.php` (this commit) is its first caller, and the P5 song-editor tune
 * typeahead / `song_tune_set` write path (parent plan §3B/§3C) is designed
 * to consume this SAME function rather than fork its own lookup — "build it
 * to last" per the P4 build spec (§2 item 2).
 *
 * Direct access is blocked (same guard as `media_identifiers.php` /
 * `musician_helpers.php` / `places.php`) so this file can't be requested as
 * an endpoint via an open Apache config.
 *
 * @link .claude/catalogue-1741-P4-plan.md §2.4.3                   the build spec this file implements
 * @link appWeb/.sql/migrate-tunes-entity.php                        the one-shot backfill this fold is re-homed from
 * @link appWeb/public_html/manage/works.php                         first live caller (#1741 P4b)
 * @link appWeb/.sql/schema.sql                                      tblTunes CREATE TABLE block (~3623-3641)
 * @see #1741
 *
 * #1741 P5c ADDITION — `ihymns_meter_normalize()`
 * ----------------------------------------------------------------------------
 * A hymn metre can be written half a dozen ways for the SAME thing — "CM",
 * "C.M.", "86.86" and "86 86" all mean Common Metre. `tune_search`'s `meter`
 * filter (api2.php, #1741 P5c) needs to compare a curator's typed metre
 * against `tblTunes.MeterCode` (a free-text display column, #1090) without
 * those spelling differences hiding a real match — this is that ONE fold,
 * pure and DB-free so it is trivially unit-testable (guarded by
 * `tests/php/test-tune-lockstep.php`'s behavioural assertions) and reusable
 * later by the tune page's planned "tunes with this meter" section (P4c
 * §3.3-6 named this exact upgrade path already).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Tune name → URL-safe handle ("HYFRYDOL" → "hyfrydol", "St. Anne" →
 * "st-anne"). Re-homed byte-for-byte from `migrate-tunes-entity.php`'s
 * `_migTunes_slugify()` — see the file doc-block for why a copy, not a
 * shared `require`, and why the two must stay byte-identical.
 *
 * ELI5: turns a tune's display name into something safe to put in a URL —
 * lowercase, spaces and punctuation become hyphens.
 *
 * DETAILED: deliberately NOT `ihymns_normalize_title()` (that fold is the
 * EXACT-dedup key for SONG titles and keeps spaces — wrong shape for a URL
 * segment). `iconv('UTF-8','ASCII//TRANSLIT', …)` transliterates accented
 * Latin characters (é → e); on a name it CANNOT transliterate at all (pure
 * CJK, for example) `iconv()` returns `false` rather than a partial string,
 * so the `$s === false ? $name : $s` line falls back to the raw name and the
 * `[^a-z0-9]+` strip then removes whatever's left — a name made entirely of
 * non-Latin characters collapses to `''` and is renamed 'tune'; the caller's
 * collision loop (in `tuneFindOrCreateByName()` below) turns a second such
 * name into 'tune-2', degrading to ugly-but-unique rather than a duplicate-
 * key failure. The 130-char cap leaves headroom inside `tblTunes.Slug`'s
 * VARCHAR(140) for that '-N' suffix.
 *
 * @param string $name Curator-typed or imported tune display name.
 * @return string Never empty — 'tune' is the degenerate-input fallback.
 * @link https://www.php.net/manual/en/function.iconv.php
 * @link appWeb/.sql/migrate-tunes-entity.php:106-113 the byte-identical original
 */
function ihymns_tune_slugify(string $name): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    $s = $s === false ? $name : $s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s === '' ? 'tune' : substr($s, 0, 130);
}

/**
 * Cached, per-request probe: does `tblTunes` exist on this install? A
 * `static` local (rather than a class property, this file has no class) —
 * the exact idiom `musician_helpers.php`'s `musicianMembersTableExists()` /
 * `musicianSlugColumnExists()` use, cited as idiom §0.3.4 in the build spec.
 *
 * ELI5: "does the tunes table even exist yet on this database?" — asked
 * once per page load, remembered for every subsequent call in the same
 * request rather than re-querying INFORMATION_SCHEMA every time.
 *
 * @param \mysqli $db
 * @return bool
 */
function tuneTunesTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblTunes' LIMIT 1"
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
 * Find an existing `tblTunes` row by (collation-folded) name, or create one.
 * The ONE find-or-create funnel every live write path should call — see the
 * file doc-block for why this matters (P5's song-editor typeahead is
 * designed to consume this exact function, not fork its own lookup).
 *
 * ELI5: "here's a tune name a curator typed — give me back its database id,
 * making a new row for it if nobody's used this name before."
 *
 * DETAILED:
 *   - Empty/whitespace-only `$name` → `null` (nothing to look up or create;
 *     callers use this to mean "no tune set" and skip writing `TuneId`).
 *   - `tblTunes` absent (pre-#1090-backfill install, or the works-identity
 *     migration ran before the tunes-entity migration — migrate-works-
 *     identity.php explicitly tolerates that ordering, see its doc-block)
 *     → `null`. Callers must NOT then write the bare `TuneName` text as if
 *     nothing happened and silently drop the id — see `manage/works.php`'s
 *     gated-UPDATE call site for how the two are kept in lockstep (TuneName
 *     + TuneId always land in the SAME UPDATE statement, never TuneName
 *     alone; §2.4.3 "TuneName→TuneId lockstep").
 *   - `Name = ?` lookup relies on `tblTunes`' table-level
 *     `utf8mb4_unicode_ci` COLLATION to do the case/accent folding — same
 *     as `migrate-tunes-entity.php`'s backfill JOIN (schema.sql:3623-3641
 *     doc-comment); "HYFRYDOL" and "Hyfrydol" resolve to the SAME row.
 *   - On a miss, INSERTs a new row using `ihymns_tune_slugify()` for the
 *     base slug, then re-runs the SAME collision-suffix loop
 *     `migrate-tunes-entity.php`'s backfill uses (:225-231): try the bare
 *     slug, then `-2`, `-3`, … until `uq_Slug` isn't violated. A genuine
 *     TOCTOU race (two concurrent curators creating the identically-named
 *     new tune at once) is caught by `uq_Name`/`uq_Slug` at the database
 *     layer — the `INSERT` would throw `mysqli_sql_exception` under this
 *     app's STRICT mysqli reporting, which the surrounding `try/catch`
 *     degrades to a logged `null` (the caller then simply doesn't link a
 *     TuneId this save; the curator's next edit re-resolves it, since by
 *     then the concurrent INSERT has landed and the `Name = ?` lookup
 *     above will hit).
 *
 * @param \mysqli $db
 * @param string  $name Curator-typed tune name, any whitespace already
 *                       trimmed by the caller or not — trimmed again here.
 * @return int|null tblTunes.Id, or null (empty name / tblTunes absent /
 *                   an unexpected DB error, logged and swallowed).
 * @link .claude/catalogue-1741-P4-plan.md §2.4.3
 */
function tuneFindOrCreateByName(\mysqli $db, string $name): ?int
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'ilyrics_id.php';   /* #1860 go-live — ilidStampNewRow() below */
    $name = trim($name);
    if ($name === '') return null;
    if (!tuneTunesTableExists($db)) return null;

    /* tblTunes.Name is VARCHAR(120) — same cap the migration's backfill
       effectively respects (TuneName source columns are all <=120 too). */
    $name = mb_substr($name, 0, 120);

    try {
        $stmt = $db->prepare('SELECT Id FROM tblTunes WHERE Name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['Id'];

        /* Miss — create it. #1748 — the base-slug + collision-suffix loop
           (originally inline here, itself byte-identical to
           migrate-tunes-entity.php:222-231) now lives in the shared
           `tuneSlugEnsureUnique()` above so `manage/tunes.php`'s update
           path can reuse it without a second copy (rule #22). Behaviour is
           unchanged — same base slug in, same collision suffix out. */
        $base = ihymns_tune_slugify($name);
        $slug = tuneSlugEnsureUnique($db, $base);

        $ins = $db->prepare('INSERT INTO tblTunes (Name, Slug) VALUES (?, ?)');
        $ins->bind_param('ss', $name, $slug);
        $ins->execute();
        $newId = (int)$db->insert_id;
        $ins->close();
        /* #1860 go-live — mint this tune's permanent IL-id (ILT…). Runs
           inside this function's OWN try/catch, which already degrades any
           throwable to a logged null — ilidStampNewRow() shares that same
           fail-safe posture internally, so nesting it here is harmless. */
        ilidStampNewRow($db, 'tune', $newId);
        return $newId;
    } catch (\Throwable $e) {
        error_log('[tuneFindOrCreateByName] ' . $e->getMessage());
        return null;
    }
}

/**
 * Named-metre → digit-form map (#1741 P5c). VARCHAR-shaped as a PHP const,
 * not a database ENUM/table (rule #20 — this is app-validated vocabulary
 * that could grow, e.g. "6.6.4.4.4.4" style long/short-metre variants a
 * curator names later; adding one is a one-line change here, never a
 * schema migration). Every mapped value is the CANONICAL digit form
 * `ihymns_meter_normalize()` also produces for that metre's numeric
 * spelling, so `CM` and `86.86` fold to the SAME string and therefore
 * compare equal in `tune_search`'s `meter` filter.
 *
 * @link https://en.wikipedia.org/wiki/Hymn_meter background on the CM/LM/SM naming convention
 */
const IHYMNS_METER_NAMED = [
    'CM'  => '86.86',   'CMD' => '86.86D',
    'LM'  => '88.88',   'LMD' => '88.88D',
    'SM'  => '66.86',   'SMD' => '66.86D',
];

/**
 * Fold a hymn-metre string into ONE canonical comparable form, e.g.
 * "CM" / "C.M." / "86.86" / "86 86" all -> "86.86"; "87 87 D" /
 * "87.87.D" / "8787D" all -> "87.87D".
 *
 * ELI5: a curator (or an old hymnal) can write the same metre a dozen
 * different ways — this turns any of those spellings into ONE string, so
 * "does this tune's metre match what I typed?" can be a plain `===`
 * instead of a fuzzy guess.
 *
 * DETAILED, in the order the fold actually runs:
 *   1. Uppercase + trim; periods/commas (metre notation's usual figure
 *      separators, "8.7.8.7" / "C.M.") fold to a SPACE (not removed) so
 *      grouping survives — losing the separator before splitting on digit
 *      runs would turn "8.7.8.7" into the ambiguous "8787" this function
 *      would then have to GUESS how to re-split.
 *   2. A trailing "DOUBLED" or a standalone "D" WORD (space-separated —
 *      "CM D", "87 87 D", "LM DOUBLED") is detected and stripped as the
 *      metre's "doubled" flag, re-added to whatever the rest of the string
 *      resolves to. A "D" GLUED directly onto the preceding token with no
 *      separator ("CMD", "8787D", "SMD") has no space to split on, so it is
 *      handled per-branch below instead (named-key lookup / a trailing-D
 *      regex on the last digit run) rather than at this step.
 *   3. NAMED branch — if what's left is letters only, look it up in
 *      `IHYMNS_METER_NAMED` (trying the bare key, then the key with a
 *      trailing "D" stripped for the glued-on case: "CMD" -> "CM" + D).
 *      No match -> `''` (an unrecognised name, not a numeric metre).
 *   4. NUMERIC branch — every space-separated digit run is collected and
 *      joined with '.'. A SINGLE glued-together run with no separators at
 *      all AND an even digit count ("8787", "6686") is a genuinely
 *      ambiguous input (no separator survives to say where one figure ends
 *      and the next begins) — the DEFENSIBLE DEFAULT taken here is to
 *      split it into 2-digit pairs (the overwhelmingly common case: every
 *      named metre in `IHYMNS_METER_NAMED` above is two-digit-per-line),
 *      which is what makes "8787D" fold to the SAME "87.87D" as the
 *      explicitly-separated "87 87 D" / "87.87.D" spellings. A metre with
 *      a genuine 1-digit or 3-digit line written with NO separator at all
 *      is a real but rare edge this default does not handle — trivially
 *      revisitable if a curator ever hits it (this is a pure function with
 *      one call site today, `tune_search`'s meter filter).
 *   5. No digits and no named match anywhere -> `''`, which every caller
 *      treats as "no metre filter" (never a query that matches nothing).
 *
 * @param string $code Curator-typed or stored (`tblTunes.MeterCode`) metre text.
 * @return string Canonical form, or '' when nothing recognisable was found.
 * @link .claude/catalogue-1741-P5-plan.md §3.5
 * @link appWeb/public_html/manage/editor/api2.php tune_search's `meter` filter (the one call site today)
 */
/**
 * #1748 — tune credit-role vocabulary, THE ONE canonical source.
 *
 * ELI5: a tune credit row can say who "wrote" it in one of exactly four
 * ways — composer, arranger, harmoniser or source. This list is the ONE
 * place that says which four words are allowed and what to call them on
 * screen, in display order.
 *
 * DETAILED / WHY A CENTRAL CONST (rule #20 — VARCHAR-not-ENUM vocabulary,
 * app-validated against ONE map; rule #35 — a mechanism, not a comment):
 * `tblTuneCredits.Role` is `VARCHAR(20)` on purpose (schema.sql ~3673) so
 * growing the vocabulary is a one-line change here, never an `ALTER`. Before
 * this constant existed, the same four tokens were typed out TWICE in
 * `includes/pages/tune.php` — once in a `FIELD(c.Role, …)` ORDER BY clause
 * (:377) and again in a local `$tuneCreditRoleLabels` display map (:454-459)
 * — with a comment CLAIMING a CI guard kept them in sync (it did not; see
 * that file's :368-369 fix). `tests/php/test-tune-admin-surface.php`'s D1
 * derives the token list straight from THIS constant's `array_keys()` and
 * asserts it equals the `tblTuneCredits.Role` COMMENT's own pipe-separated
 * vocabulary in `schema.sql` — two independently-authored lists (a SQL
 * comment and a PHP array) that must agree with nothing else enforcing it,
 * which is exactly the failure class rule #35 exists to name. D2 then
 * asserts BOTH consumers (`includes/pages/tune.php` and the new
 * `manage/tunes.php`) reference this constant and neither re-inlines the
 * raw `'composer', 'arranger'` sequence.
 *
 * @see #1748
 * @see appWeb/.sql/schema.sql            tblTuneCredits.Role COMMENT (~3673)
 * @see appWeb/public_html/includes/pages/tune.php   first consumer, refactored to use this const
 * @see appWeb/public_html/manage/tunes.php          second consumer (the admin CRUD)
 * @see tests/php/test-tune-admin-surface.php        D1/D2 guard
 */
const IHYMNS_TUNE_CREDIT_ROLES = [
    'composer'   => 'Composer',
    'arranger'   => 'Arranger',
    'harmoniser' => 'Harmoniser',
    'source'     => 'Source',
];

/**
 * #1748 — slug-collision loop, extracted from `tuneFindOrCreateByName()`'s
 * inline `while(true)` (this file, pre-#1748 :212-221) so `manage/tunes.php`'s
 * update path can re-derive a unique slug (e.g. when a curator blanks the
 * Slug field on rename, or explicitly retypes one) WITHOUT re-forking the
 * collision loop (rule #22 — one funnel, not two copies that drift the
 * moment either is edited).
 *
 * ELI5: "here's a candidate slug — if something's already using it, try
 * `-2`, `-3`, … until we find one that's free."
 *
 * DETAILED: byte-identical logic to the pre-extraction loop —
 * `tuneFindOrCreateByName()` below is refactored to CALL this function
 * rather than keep its own copy, so the two can never diverge (behaviour is
 * unchanged; `tests/php/test-tune-lockstep.php`'s reference-based sweep
 * only asserts `tuneFindOrCreateByName` is referenced by TuneName writers,
 * so this refactor doesn't touch what that guard checks).
 * `$excludeId` lets the UPDATE path skip the row's OWN current slug (a
 * curator re-saving without changing the name shouldn't collide with
 * itself) — `tuneFindOrCreateByName()`'s create-only call site passes
 * `null` (nothing to exclude on a brand-new row).
 *
 * @param \mysqli   $db
 * @param string    $base      Already-slugified candidate (see
 *                              `ihymns_tune_slugify()`), NOT re-slugified here.
 * @param int|null  $excludeId `tblTunes.Id` to exclude from the collision
 *                              check (the row's own id on update), or `null`.
 * @return string A slug guaranteed free of `uq_Slug` collisions at the
 *                 moment this function returns (a genuine concurrent-insert
 *                 race is still possible — same TOCTOU note as
 *                 `tuneFindOrCreateByName()`'s doc-block).
 * @see #1748
 */
function tuneSlugEnsureUnique(\mysqli $db, string $base, ?int $excludeId = null): string
{
    $slug = $base;
    $n    = 1;
    if ($excludeId !== null) {
        $slugTaken = $db->prepare('SELECT 1 FROM tblTunes WHERE Slug = ? AND Id <> ? LIMIT 1');
    } else {
        $slugTaken = $db->prepare('SELECT 1 FROM tblTunes WHERE Slug = ? LIMIT 1');
    }
    while (true) {
        if ($excludeId !== null) {
            $slugTaken->bind_param('si', $slug, $excludeId);
        } else {
            $slugTaken->bind_param('s', $slug);
        }
        $slugTaken->execute();
        $taken = $slugTaken->get_result()->fetch_row() !== null;
        if (!$taken) break;
        $n++;
        $slug = substr($base, 0, 134) . '-' . $n;
    }
    $slugTaken->close();
    return $slug;
}

function ihymns_meter_normalize(string $code): string
{
    $s = strtoupper(trim($code));
    if ($s === '') { return ''; }

    /* Fold period/comma figure-separators to a space (kept as a separator,
       not discarded) then collapse every whitespace run to one space. */
    $s = str_replace(['.', ','], ' ', $s);
    $s = trim((string)preg_replace('/\s+/', ' ', $s));
    if ($s === '') { return ''; }

    $doubled = false;
    $words   = explode(' ', $s);
    $lastWord = end($words);
    if ($lastWord === 'DOUBLED' || $lastWord === 'D') {
        $doubled = true;
        array_pop($words);
    }
    if (!$words) { return ''; }   // input WAS just "D" / "DOUBLED" alone

    $compactNoSpace = implode('', $words);

    /* NAMED branch — letters only once digits are ruled out. */
    if ($compactNoSpace !== '' && ctype_alpha($compactNoSpace)) {
        $mapped = IHYMNS_METER_NAMED[$compactNoSpace] ?? null;
        if ($mapped === null
            && substr($compactNoSpace, -1) === 'D'
            && isset(IHYMNS_METER_NAMED[substr($compactNoSpace, 0, -1)])
        ) {
            /* Glued-on "D" the word-split above couldn't see ("CMD"). */
            $mapped  = IHYMNS_METER_NAMED[substr($compactNoSpace, 0, -1)];
            $doubled = true;
        }
        if ($mapped === null) { return ''; }
        return ($doubled && substr($mapped, -1) !== 'D') ? $mapped . 'D' : $mapped;
    }

    /* NUMERIC branch. A "D" glued onto the LAST word with no separator
       ("8787D") is stripped from that word first. */
    $tailWord = end($words);
    if ($tailWord !== false && preg_match('/^(\d+)D$/', $tailWord, $wm)) {
        $doubled = true;
        $words[array_key_last($words)] = $wm[1];
    }
    $digitRuns = array_values(array_filter($words, static fn(string $w): bool => $w !== '' && ctype_digit($w)));
    if (!$digitRuns) { return ''; }

    /* A single glued-together run with an even digit count is genuinely
       ambiguous (no surviving separator) — split into 2-digit pairs, the
       defensible default per the doc-block above. */
    if (count($digitRuns) === 1 && strlen($digitRuns[0]) >= 4 && strlen($digitRuns[0]) % 2 === 0) {
        $digitRuns = str_split($digitRuns[0], 2);
    }

    $joined = implode('.', $digitRuns);
    return $doubled ? $joined . 'D' : $joined;
}
