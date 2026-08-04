<?php

declare(strict_types=1);

/**
 * iHymns — Shared EasyWorship "Songs.db" export helpers (#1678)
 *
 * ELI5
 * ----
 * EasyWorship is another program churches use to show song words on a
 * screen. It reads its songs from one file called `Songs.db`. This file
 * knows how to BUILD that file from an iHymns song, so the export button
 * in the song editor can hand someone a file EasyWorship understands.
 *
 * WHY THIS EXISTS
 * ----------------
 * `_ewExport_rtfEscape()`, `_ewExport_buildRtf()` and `_ewExport_writeDb()`
 * were EXTRACTED VERBATIM from `manage/editor/api.php` (where they backed the
 * `?action=easyworship_export` handler, #1059) so the clean v2 editor API
 * (`manage/editor/api2.php`) can reuse the SAME code instead of forking it
 * (CLAUDE.md modularity rule). This is the same treatment
 * `includes/song_importers.php` already documents for the bulk-import
 * parsers — see that file's header for the established precedent this
 * follows.
 *
 * Epic #1601 is retiring the legacy v1 `manage/editor/api.php` in favour of
 * `api2.php`. The #1629 audit that inventoried v1-only endpoints still in use
 * by v2 found four consumers of v1 endpoints and MISSED this one: the v2
 * export menu's "EasyWorship (beta)" button (`manage/editor/v2/export.js`)
 * was still hitting `?action=easyworship_export` on v1, which has no v2
 * equivalent. Retiring v1 as planned would have silently broken that button.
 * #1678 is the fix: move the implementation here so BOTH api.php (while it
 * still exists) and api2.php can `require_once` the one copy, then give
 * api2.php its own `easyworship_export` case (rule #1601 follow-through).
 *
 * Behaviour-preserving: the three functions below are byte-identical to the
 * v1 originals (proven code — not "improved" in the move) and are wrapped in
 * `function_exists()` guards so a double-include (api.php AND api2.php both
 * requiring this file within the same request, or a stray double require)
 * is a safe no-op rather than a fatal redeclaration error.
 *
 * BETA / unverified (carried over from #1059): produces the core two-table
 * schema the iHymns EasyWorship importer (#1058) round-trips; whether a live
 * EasyWorship install reads it has not been confirmed.
 *
 * @requires PHP 8.4+ — project targets PHP 8.5; 8.4 supported for backward-compat.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!function_exists('_ewExport_rtfEscape')) {
/**
 * Escape one string for RTF: \ { } literals, non-ASCII → \uN (signed 16-bit,
 * with a '?' fallback char). Mirrors the JS exporter's rtfEscape.
 */
function _ewExport_rtfEscape(string $text): string
{
    $out = '';
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch   = mb_substr($text, $i, 1, 'UTF-8');
        $code = mb_ord($ch, 'UTF-8');
        if ($ch === '\\')                  { $out .= '\\\\'; }
        elseif ($ch === '{')               { $out .= '\\{'; }
        elseif ($ch === '}')               { $out .= '\\}'; }
        elseif ($code !== false && $code < 128) { $out .= $ch; }
        else {
            $rtfCode = ($code !== false && $code > 32767) ? $code - 65536 : (int)$code;
            $out    .= '\\u' . $rtfCode . '?';
        }
    }
    return $out;
}
}

if (!function_exists('_ewExport_buildRtf')) {
/**
 * Build the EasyWorship `words` RTF for one song. Slides (chunks of <=
 * $maxLines lines, or whole components when $maxLines <= 0) are separated by
 * \par\par; lines within a slide by \par.
 */
function _ewExport_buildRtf(array $components, int $maxLines): string
{
    $slides = [];
    foreach ($components as $comp) {
        $lines  = array_map('strval', (array)($comp['lines'] ?? []));
        $chunks = ($maxLines > 0) ? array_chunk($lines, $maxLines) : [$lines];
        foreach ($chunks as $chunk) {
            $esc = array_map('_ewExport_rtfEscape', $chunk);
            $slides[] = implode('\\par ', $esc);
        }
    }
    if (empty($slides)) { $slides[] = ''; }
    $body = implode('\\par\\par ', $slides);
    return '{\\rtf1\\ansi\\ansicpg1252{\\fonttbl\\f0\\fswiss Arial;}\\pard\\f0\\fs40 ' . $body . '}';
}
}

if (!function_exists('_ewExport_writeDb')) {
/**
 * Write an EasyWorship Songs.db (song + word tables) at $path for the given
 * iHymns song records (the SongData::getSongs() shape). Returns the song
 * count written.
 */
function _ewExport_writeDb(string $path, array $songs, int $maxLines): int
{
    if (!class_exists('SQLite3')) {
        throw new \RuntimeException('the SQLite3 PHP extension is not available');
    }
    @unlink($path);
    $db = new \SQLite3($path);
    $db->exec('CREATE TABLE song (rowid INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'title TEXT, author TEXT, copyright TEXT, reference_number TEXT)');
    $db->exec('CREATE TABLE word (rowid INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'song_id INTEGER, words TEXT)');

    $songStmt = $db->prepare('INSERT INTO song (title, author, copyright, reference_number) VALUES (?,?,?,?)');
    $wordStmt = $db->prepare('INSERT INTO word (song_id, words) VALUES (?,?)');

    $count = 0;
    $db->exec('BEGIN');
    foreach ($songs as $song) {
        $title = trim((string)($song['title'] ?? ''));
        if ($title === '') { continue; }
        $author = trim(implode(', ', array_filter(array_merge(
            (array)($song['writers'] ?? []),
            (array)($song['composers'] ?? [])
        ))));
        $copyright = (string)($song['copyright'] ?? '');
        $refnum    = (string)($song['number'] ?? '');
        $rtf       = _ewExport_buildRtf((array)($song['components'] ?? []), $maxLines);

        $songStmt->bindValue(1, $title, SQLITE3_TEXT);
        $songStmt->bindValue(2, $author, SQLITE3_TEXT);
        $songStmt->bindValue(3, $copyright, SQLITE3_TEXT);
        $songStmt->bindValue(4, $refnum, SQLITE3_TEXT);
        $songStmt->execute();
        $songStmt->reset();
        $songId = $db->lastInsertRowID();

        $wordStmt->bindValue(1, $songId, SQLITE3_INTEGER);
        $wordStmt->bindValue(2, $rtf, SQLITE3_TEXT);
        $wordStmt->execute();
        $wordStmt->reset();
        $count++;
    }
    $db->exec('COMMIT');
    $db->close();
    return $count;
}
}
