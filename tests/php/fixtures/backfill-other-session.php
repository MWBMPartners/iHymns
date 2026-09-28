<?php

declare(strict_types=1);

/**
 * iHymns — subprocess helper: "another curator" changing a song's language
 * while the songbook-language backfill card runs (#2137 review round 4)
 *
 * ELI5
 * ----
 * The backfill card reads a song's language, then fills it. If somebody else
 * changes that song in between, their change must win. To prove it, this
 * script plays the other person, in its own database connection:
 *   1. starts a transaction and changes the song's language (which locks the
 *      row, so the card's own change has to wait for it),
 *   2. prints "locked", so the test knows the lock is held and runs the card
 *      (in the test's own process),
 *   3. waits a moment, then commits and prints "committed".
 * The card's change then either finds the language no longer what it read
 * (and skips that song), or — on MariaDB 11.8 with snapshot isolation on —
 * fails the whole run, which rolls back. Either way the other change stands.
 *
 * Why a separate process: the card runs to completion inside one PHP call,
 * so the other person has to act from outside it, at the same time.
 *
 * Args: <host> <port> <user> <pass> <database> <songId> <new language> <hold ms>
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * @see tests/php/test-song-language-backfill.php  (Part C spawns this)
 * @see appWeb/.sql/migrate-backfill-song-language-from-songbook.php
 */

[, $host, $port, $user, $pass, $name, $songId, $newLanguage, $holdMs] = $argv + array_fill(0, 9, '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli((string)$host, (string)$user, (string)$pass, (string)$name, (int)$port);
$db->begin_transaction();
$stmt = $db->prepare('UPDATE tblSongs SET Language = ? WHERE SongId = ?');
$stmt->bind_param('ss', $newLanguage, $songId);
$stmt->execute();
echo "locked\n";
flush();
usleep(max(0, (int)$holdMs) * 1000);
$db->commit();
echo "committed\n";
