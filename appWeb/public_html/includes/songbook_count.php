<?php

declare(strict_types=1);

/**
 * iHymns — Songbook SongCount recompute (#1742)
 * ===============================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `tblSongbooks.SongCount` is a saved number, not a live count — the home and
 * `/songbooks` tiles only render a book once its saved number is above zero.
 * Every place that adds, removes, hides, restores or moves a song has to tell
 * the songbook "your count changed" by re-counting and writing the number
 * back. This file is the ONE place that re-count lives, so every caller asks
 * it the same way instead of typing the same UPDATE out again.
 *
 * WHY THIS EXISTS (#1742, confirmed finding A3)
 * ----------------------------------------------
 * Two mechanisms are supposed to keep `SongCount` correct: the #791/#793
 * `trg_songs_songcount_*` DB triggers, and this app-level recompute. iHymns
 * targets shared hosting where `CREATE TRIGGER` is frequently denied by the
 * hosting account, so on that deployment target the triggers never exist and
 * the app-level recompute is not a belt-and-braces backup — it is the ONLY
 * thing that keeps the cached count honest. #1742 found the v2 editor's
 * `create_song` handler (`manage/editor/api2.php`) did no recompute at all
 * (`grep -c SongCount manage/editor/api2.php` was 0): on a trigger-capable
 * dev/CI box the DB trigger silently masked the gap, but on the real
 * shared-host target a brand-new songbook's tile simply never appeared,
 * because its `SongCount` stayed at 0 forever.
 *
 * Before this file, the SAME recompute UPDATE existed inline in THREE places
 * (`manage/editor/save_song_core.php` twice — current book, previous book on
 * a move — and `includes/song_relocate.php` once, looped over two books) —
 * a direct violation of this repo's modularity rule (CLAUDE.md: "When a piece
 * of ... logic ... exists in more than one place, extract it into a shared
 * module"). This file is that shared module: ONE function, called by every
 * funnel that changes a songbook's visible-song population, including the
 * `create_song` handler that was missing the call entirely.
 *
 * THE PREDICATE — songVisibleSql() (#1694 D1)
 * ---------------------------------------------
 * `SongCount` counts VISIBLE songs, not every row in `tblSongs` — a
 * soft-deleted song (`includes/song_soft_delete.php`) must not inflate the
 * count. `songVisibleSql($db, '')` returns the exact fragment
 * `'IsDeleted = 0'` on an install where the #1694 soft-delete migration has
 * landed, and the harmless `'1=1'` degrade on one where it has not — so this
 * recompute agrees with the predicate-aware `trg_songs_songcount_*` triggers
 * on a trigger-capable host, and behaves byte-identically to the pre-#1694
 * unfiltered count on one that has not migrated yet. Never hardcode
 * `IsDeleted = 0` here — that would throw under STRICT mode
 * (`includes/db_mysql.php`) the moment this runs against an un-migrated
 * install's `tblSongs`, which has no such column.
 *
 * @see appWeb/public_html/manage/editor/api2.php        create_song — the missing call this file fixes
 * @see appWeb/public_html/manage/editor/save_song_core.php  the whole-song save funnel
 * @see appWeb/public_html/includes/song_relocate.php        the songbook-move funnel
 * @see appWeb/public_html/includes/song_soft_delete.php     songVisibleSql() + songRelocateIsTransactionFatal()'s sibling recompute
 * @see tests/php/test-songbook-count-recompute.php          the behavioural + wiring guard
 * @link https://www.php.net/manual/en/mysqli.prepare.php
 * @link https://www.php.net/manual/en/mysqli-stmt.bind-param.php
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* songVisibleSql() */

/**
 * Recompute `tblSongbooks.SongCount` for one or more songbooks.
 *
 * ELI5: tell this function which songbook abbreviation(s) just changed how
 * many songs they visibly hold, and it writes the fresh count back onto
 * each one. Pass nothing meaningful (all blank, or nothing at all) and it
 * does not touch the database.
 *
 * DEDUPE / TRIM / EMPTY-SKIP, AND WHY IT MATTERS
 * ------------------------------------------------
 * ELI5: callers often hand this the current book AND the previous book (a
 * save that didn't move songbooks, or a relocate with no source-quite-book
 * change) — when those two are the same abbreviation, or one of them is '',
 * re-running the identical UPDATE twice (or running it for '') wastes a
 * round trip and — worse — a caller inside an open transaction pays for a
 * redundant lock on the same row for nothing.
 *
 * Detail: every abbr is trimmed, blank abbrs are dropped, and the survivors
 * are deduped (`array_unique`) before anything is prepared. This mirrors the
 * exact shape `song_relocate.php`'s original loop used
 * (`foreach ([$targetAbbr, $currentAbbr] as $abbr) { if ($abbr === '') { continue; } ... }`)
 * plus the save_song_core.php pair, where the "previous" abbr is legitimately
 * '' (a brand-new song has no previous songbook) or equal to the current one
 * (a save that didn't move books) — both must collapse to ONE database write.
 *
 * NO WORK AT ALL WHEN NOTHING SURVIVES
 * --------------------------------------
 * ELI5: if every abbreviation passed in turns out to be blank, this function
 * returns immediately without asking the database anything — not even the
 * "has the soft-delete migration run?" question.
 *
 * Detail: the early `return` happens BEFORE `songVisibleSql()` is called, so
 * a no-op invocation (e.g. a save whose current and previous songbook are
 * both '', which cannot happen today but is the honest contract) touches the
 * connection zero times — no `prepare()`, no INFORMATION_SCHEMA probe. This
 * keeps a degenerate call cheap and keeps `tests/php/lib/mysqli_doubles.php`'s
 * `GateRecorderMysqli` double's `->prepared === []` assertion meaningful as a
 * proof, not just an accident of what happened to run.
 *
 * ONE PREPARE, MANY EXECUTES
 * -----------------------------
 * The statement is prepared ONCE — `songVisibleSql($db, '')` is resolved
 * once into the prepared SQL — then `bind_param()` + `execute()` run once per
 * surviving abbreviation, exactly mirroring the loop shape
 * `song_relocate.php` used before extraction
 * (@link https://www.php.net/manual/en/mysqli-stmt.bind-param.php — binding
 * a statement once and re-executing it with different bound values is the
 * documented, cheaper pattern versus re-preparing per iteration).
 *
 * NO try/catch HERE — THE CALLER OWNS THE POLICY
 * -------------------------------------------------
 * ELI5: if the database complains while recounting, this function does not
 * decide whether that is a shrug-and-continue problem or a stop-everything
 * problem — the code that called it is in a better position to know, because
 * only it knows whether it is still inside a transaction that a MySQL error
 * may have already rolled back out from under it.
 *
 * Detail: `songRelocateIsTransactionFatal()` (`includes/song_relocate.php`,
 * #1679 A1) classifies MySQL errors into two kinds — ones that already rolled
 * back the WHOLE InnoDB transaction (which MUST be re-thrown, or the caller's
 * eventual `$db->commit()` commits nothing while the code path still answers
 * `ok=true`) and cosmetic ones (log and let the surrounding write stand). That
 * classification is meaningless without knowing whether the caller has an
 * open transaction at all: inside one (`save_song_core.php`,
 * `song_relocate.php`), a fatal error must propagate; called AFTER a commit
 * (`api2.php`'s `create_song`), the row this recompute is about already
 * exists and ANY failure here is purely best-effort — the count self-heals
 * next time something touches the book. Baking either policy into this
 * shared helper would be wrong for the other caller, so every call site wraps
 * its own `try { songbookRecomputeSongCount(...); } catch (\Throwable $e) { ... }`
 * using `songRelocateIsTransactionFatal($e)` to decide.
 *
 * @param \mysqli $db    Live connection from getDbMysqli().
 * @param string  ...$abbrs One or more `tblSongbooks.Abbreviation` values;
 *                          blanks and duplicates are dropped (see above).
 */
function songbookRecomputeSongCount(\mysqli $db, string ...$abbrs): void
{
    /* Trim first (a caller may pass whitespace-only noise), then drop blanks,
       then dedupe — order matters: dedupe on UN-trimmed values would treat
       ' MP' and 'MP' as different books. array_values() re-keys after the
       filter/unique pair so the loop below doesn't inherit gapped keys. */
    $abbrs = array_values(array_unique(array_filter(
        array_map('trim', $abbrs),
        static fn(string $a): bool => $a !== ''
    )));

    if ($abbrs === []) {
        return;   /* nothing to do — zero DB round trips, not even the songVisibleSql() probe */
    }

    $stmt = $db->prepare(
        /* #1694 D1 — SongCount counts VISIBLE songs; songVisibleSql() is
           resolved ONCE for this prepared statement and reused across every
           bound execute() below. */
        'UPDATE tblSongbooks
            SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ')
          WHERE Abbreviation = ?'
    );
    foreach ($abbrs as $abbr) {
        $stmt->bind_param('ss', $abbr, $abbr);
        $stmt->execute();
    }
    $stmt->close();
}
