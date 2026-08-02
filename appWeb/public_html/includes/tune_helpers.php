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

        /* Miss — create it. Same base-slug + collision-suffix loop as
           migrate-tunes-entity.php:222-231. */
        $base = ihymns_tune_slugify($name);
        $slug = $base;
        $n    = 1;
        $slugTaken = $db->prepare('SELECT 1 FROM tblTunes WHERE Slug = ? LIMIT 1');
        while (true) {
            $slugTaken->bind_param('s', $slug);
            $slugTaken->execute();
            $taken = $slugTaken->get_result()->fetch_row() !== null;
            if (!$taken) break;
            $n++;
            $slug = substr($base, 0, 134) . '-' . $n;
        }
        $slugTaken->close();

        $ins = $db->prepare('INSERT INTO tblTunes (Name, Slug) VALUES (?, ?)');
        $ins->bind_param('ss', $name, $slug);
        $ins->execute();
        $newId = (int)$db->insert_id;
        $ins->close();
        return $newId;
    } catch (\Throwable $e) {
        error_log('[tuneFindOrCreateByName] ' . $e->getMessage());
        return null;
    }
}
