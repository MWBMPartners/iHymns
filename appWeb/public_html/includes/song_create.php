<?php

declare(strict_types=1);

/**
 * iHymns — the one INSERT that creates a new, empty song row in the v2 editor
 * (#2137 review, #2132)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * "New song" and "Duplicate song" in the v2 editor both start by inserting a
 * bare row: an id, a title, a songbook. This file is that insert, in one
 * place, and it always says what the new song's language is: `und`, "not
 * known yet".
 *
 * WHY (#2137 review)
 * ------------------
 * The insert used to leave the Language column out, so the column's default
 * applied — and on a server that has not yet run the "Song language: default
 * to not known (und)" card, that default is still `en`. Every song created
 * there was quietly recorded as English: a guess that looks like a fact
 * (policy LANG-003). Writing the value explicitly makes the result the same
 * on every server, whatever its default. (Duplicate then copies the source
 * song's real language over this, through ed2_applySongSnapshot().)
 *
 * The PublicId permalink is minted when that column exists (#1343-B) and left
 * out when it does not, exactly as the two inserts this replaces did; and the
 * row's permanent IL-id is minted straight after the insert (#1860 go-live,
 * ilidStampNewRow() — a no-op on an install without that column), which the
 * two callers used to do themselves. One place creates the row, so one place
 * stamps it (tests/php/test-ilid-golive.php checks every such insert).
 *
 * @see appWeb/public_html/manage/editor/api2.php  create_song, duplicate_song
 * @see tests/php/test-song-create-language.php     (run against the old column default)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_public_id.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ilyrics_id.php';

/**
 * Insert a new song row whose language is not known yet.
 *
 * Runs inside the caller's transaction; throws what mysqli throws.
 *
 * @param \mysqli $db
 * @param string  $songId          Already allocated (ed2_allocateSongId()).
 * @param string  $title
 * @param string  $normalizedTitle ed2_normalizeTitle($title).
 * @param string  $songbookAbbr
 */
function songInsertNewRow(\mysqli $db, string $songId, string $title, string $normalizedTitle, string $songbookAbbr): void
{
    $language = mediaLanguageOrUnknown(null);   // a brand-new song: nobody has said yet → und
    if (songPublicId_columnReady($db)) {
        $publicId = songPublicId_mintUnique($db);
        $ins = $db->prepare(
            'INSERT INTO tblSongs (SongId, PublicId, Title, NormalizedTitle, SongbookAbbr, Language) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->bind_param('ssssss', $songId, $publicId, $title, $normalizedTitle, $songbookAbbr, $language);
    } else {
        $ins = $db->prepare(
            'INSERT INTO tblSongs (SongId, Title, NormalizedTitle, SongbookAbbr, Language) VALUES (?, ?, ?, ?, ?)'
        );
        $ins->bind_param('sssss', $songId, $title, $normalizedTitle, $songbookAbbr, $language);
    }
    $ins->execute();
    $ins->close();
    /* #1860 go-live — the song's permanent IL-id (ILS…), minted AFTER the row
       exists (see ilidStampNewRow()'s own doc comment for why never inside
       the INSERT). */
    ilidStampNewRow($db, 'song', $songId, 'SongId');
}
