<?php

declare(strict_types=1);

/**
 * iHymns - what we do NOT keep about people (A4 + A5, epic #2180)
 * ==============================================================
 *
 * ELI5
 * ----
 * Two privacy promises, checked by reading the code that keeps them:
 *   1. When anyone searches, we record the words searched, but NOT who searched.
 *      (Nothing ever used the "who", and the privacy page says recent searches
 *      stay on your own device.)
 *   2. "Clear history" takes your name off your past views. It does not delete
 *      the rows, because deleting them would quietly lower how popular a song
 *      looks and the organisation licence report counts.
 *
 * Detailed
 * --------
 * Rule #34: instead of trusting one place, the guard WALKS every .php under
 * appWeb/public_html and looks at every SQL string (via PHP's tokenizer, so
 * comments cannot trip it), finding every writer of tblSearchQueries and every
 * statement on tblSongHistory that clears a person's history. Later steps of
 * the rewrite (F1 etc.) add more checks to this file; it is the home for
 * "what we deliberately do not store".
 *
 * Mutation proofs are in the A4/A5 commit: putting UserId back into the search
 * insert, or turning the history clear back into a DELETE, each turns this red.
 *
 *   php tests/php/test-privacy-minimisation.php
 */

$root = dirname(__DIR__, 2) . '/appWeb/public_html';
$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

/** Every string piece of a PHP file joined (comments excluded), plus the file it came from. */
function sqlStrings(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') { continue; }
        $src = (string)file_get_contents($f->getPathname());
        $buf = [];
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $buf[] = $tok[1];
            }
        }
        $out[substr($f->getPathname(), strlen($root) + 1)] = implode(' ', $buf);
    }
    return $out;
}

$all = sqlStrings($root);

/* ---- A4: the search log never records a person ---- */
$searchInserts = [];
foreach ($all as $file => $text) {
    if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+tblSearchQueries\s*\(([^)]*)\)/i', $text, $m)) {
        foreach ($m[1] as $cols) { $searchInserts[] = [$file, $cols]; }
    }
}
chk('the scan finds the search-log insert (so this check is not blind)', count($searchInserts) >= 1);
foreach ($searchInserts as [$file, $cols]) {
    chk("$file: the tblSearchQueries insert does not name UserId", stripos($cols, 'UserId') === false, $cols);
}

/* ---- A5: clearing history unlinks, never deletes ---- */
$api = $all['api.php'] ?? '';
chk('api.php was scanned', $api !== '');
$deletesHistory = [];
foreach ($all as $file => $text) {
    if (preg_match('/DELETE\s+FROM\s+tblSongHistory\s+WHERE\s+UserId\s*=/i', $text)) { $deletesHistory[] = $file; }
}
chk('nothing deletes a person\'s tblSongHistory rows by UserId (account erasure walks the table links instead)',
    $deletesHistory === [], implode(', ', $deletesHistory));
chk('song_history_clear unlinks the rows (UPDATE ... SET UserId = NULL WHERE UserId = ?)',
    (bool)preg_match('/UPDATE\s+tblSongHistory\s+SET\s+UserId\s*=\s*NULL\s+WHERE\s+UserId\s*=\s*\?/i', $api));

exit($fail === 0 ? 0 : 1);
