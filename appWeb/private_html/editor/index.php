<?php

declare(strict_types=1);

/**
 * iHymns — RETIRED legacy JSON-corpus Song Editor (redirect stub).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHY THIS IS A STUB:
 * This was the PRE-MySQL editor. It loaded/saved the ENTIRE ~3,600-song corpus
 * as a frozen `data_share/song_data/songs.json` FILE (`api.php?action=save`
 * → file_put_contents of the whole corpus). After the DB-direct rewrite
 * (#1010 / WS-J #1020) the live app reads songs straight from MySQL and NEVER
 * reads that file — so any edit saved here was SILENTLY LOST, and the file was
 * a stale snapshot that could overwrite live data if ever re-imported. That is
 * exactly the snapshot / pre-MySQL hazard class the #1380 audit hunted down.
 *
 * The backend (`api.php`) and the old editor JS (`editor.js`) have been REMOVED
 * so there is no longer any code path that can write the corpus file. The LIVE
 * editor is `/manage/editor/` (api2.php → editorSaveSongCore(), DB-direct).
 *
 * This file remains only as a graceful redirect for any old bookmark.
 * (#1380 snapshot/pre-MySQL audit follow-up.)
 */

/* Host-relative redirect so it resolves to the current env's live editor
   (dev / beta / production) without hard-coding a host. */
if (!headers_sent()) {
    header('Location: /manage/editor/', true, 301);
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="0;url=/manage/editor/">
    <title>Song Editor has moved</title>
</head>
<body>
    <p>The legacy song editor has been retired. The current editor is at
       <a href="/manage/editor/">/manage/editor/</a>.</p>
</body>
</html>
