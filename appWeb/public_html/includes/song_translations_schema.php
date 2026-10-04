<?php

declare(strict_types=1);

/**
 * iHymns — one question about the whole-song translations table (#2131)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Until the migration `migrate-drop-song-translations-language-fk.php` has
 * been run on a server, the database there still insists that a whole-song
 * translation's language be one of the bare codes in `tblLanguages` (`pt`,
 * never `pt-BR`). Code that saves translation links has to know which world it
 * is in: before the migration, a `pt-BR` link would be refused by the database
 * (and inside the song-save transaction), so it must be skipped with a clear
 * warning; after it, the link is simply stored. This file answers that one
 * question, in one place, so the song save and the curator remap tool can
 * never disagree about it (rule #35).
 *
 * The migrations are run by hand on each server (rule #19), and the three
 * site folders share one database, so this is asked of the LIVE schema, not
 * assumed from the code's version.
 *
 * @see appWeb/.sql/migrate-drop-song-translations-language-fk.php
 * @see appWeb/public_html/manage/editor/save_song_core.php  (translation links)
 * @see appWeb/public_html/includes/language_tag_audit.php   (languageTagRemap)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* #2137 review round 7 (the sixth independent review) — songRelocateIsTransactionFatal(),
   the ONE list of database errors that have already ended the caller's whole
   transaction (a deadlock, a lock wait timeout, MariaDB's 1020). Every catch in
   this file that a song save, the v2 editor, the works or songbooks admin page
   or an importer can reach from inside its transaction starts by passing those
   back to its caller (the full list is in DEV_NOTES.md). Loaded here, at the
   top, so that check can always be a catch's first line. It lives in
   transaction_fatal.php, which loads nothing else (song_relocate.php, its old
   home, also loads the database layer). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'transaction_fatal.php';

/**
 * Does `tblSongTranslations` still carry the `fk_Trans_Lang` link to
 * `tblLanguages` on this database?
 *
 * ELI5: "has this server had the #2131 migration yet?" — true means "not yet:
 * only bare language codes can be saved as a translation's language".
 *
 * Remembered for the rest of the request (the answer cannot change inside
 * one request). If the question itself fails, the answer is TRUE — the
 * cautious one: the caller then keeps checking against the registry, so a
 * save can never be sent into a database rule that would refuse it.
 */
function songTranslationsLanguageFkPresent(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongTranslations'
                AND CONSTRAINT_NAME = 'fk_Trans_Lang' AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $e) {
        if (songRelocateIsTransactionFatal($e)) { throw $e; }   /* #2137 review round 7: never swallow an error that has ended the transaction */
        error_log('[songTranslationsLanguageFkPresent] ' . $e->getMessage());
        $cached = true;
    }
    return $cached;
}
