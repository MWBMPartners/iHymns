<?php

declare(strict_types=1);

/**
 * iHymns — the songbook move's SAFETY properties, not just its funnels (#1679)
 * ===========================================================================
 *
 * ELI5
 * ----
 * `test-song-relocate-funnels.php` asks "does every place that changes a song's
 * songbook go through the one helper?". This file asks a different question:
 * "and does that helper still do the six careful things it has to do?". Those
 * six things have no other enforcement — each one is a single statement that
 * could be deleted, re-ordered or re-wrapped in a `try` and nothing else in the
 * tree would notice.
 *
 * WHY THIS EXISTS
 * ---------------
 * Two adversarial reviews of `d8ecfa35` found six real gaps in a mechanism whose
 * funnel coverage was already guarded. Every one of them was invisible to a
 * funnel check, because a funnel check asks WHO calls the helper, never WHAT the
 * helper does once called. The six, and the property each now has here:
 *
 *  H3  the re-key assumes `ON UPDATE CASCADE` on ~41 FKs, four of which are
 *      created WITHOUT it by migrations → a pre-check must run BEFORE the first
 *      write and name the migration that fixes it.
 *  M1  the mint probes only `tblSongs`, so a freed slot could re-issue an id a
 *      live redirect still forwards away from (200 OK, wrong song) → it must
 *      also consult `tblSongRedirects.OldSongId`, through the EXISTING gate.
 *  F3  `tblSongbookEntries`' home row is not reachable from a `SongId` change →
 *      it must be rewritten, existence-gated, AFTER the re-key has cascaded.
 *  M3  the content-restriction rewrite was the one security-relevant step and
 *      the one made non-fatal → it must not be swallowed.
 *  F8  a caught deadlock/lock-wait rolls back the WHOLE transaction, then the
 *      caller commits nothing and reports success → 1213/1205 must be re-thrown.
 *  M2  `$songbookAbbr` is defaulted before the move test, so an OMITTED
 *      `songbook` key relocated the song into Misc → the branch must key off the
 *      raw payload.
 *  F5  a rename changes the song's Number, and the v1 editor kept the old one →
 *      the server must SEND the authoritative number and the client must APPLY
 *      it (rule #35: two files that must agree need a mechanism, not a comment).
 *
 * HOW IT LOOKS AT THE SOURCE
 * --------------------------
 * Through `tests/php/lib/php_source_units.php` (#1688) — the shared per-function
 * `code` / `sql` split, not a private tokenizer. `code` cannot be satisfied by
 * prose (every non-identifier literal collapses to `'@STR@'`, with `@SQLUPD@`
 * marking where an UPDATE statement stood), and `sql` reconstructs statements
 * across concatenation. Ordering assertions use the `sql` list's own order,
 * which is source order, rather than a character window — the window bug rule
 * #34 records was found twice in this codebase already.
 *
 * TWO assertions deliberately read RAW source, both flagged in place: the
 * refusal MESSAGE (where the prose IS the deliverable — a refusal that does not
 * name the migration is no better than the raw FK error it replaces) and the
 * ABSENCE of the removed swallow-and-log line.
 *
 * WHAT IT CANNOT CATCH (so its tick is not over-read)
 * --------------------------------------------------
 *  - Whether the SQL is CORRECT. There is no MySQL in CI; the statements are
 *    checked for shape and order, never executed.
 *  - Whether the pre-check's verdict is right on a real drifted install.
 *  - A funnel that never calls the helper at all — that is the other file's job.
 *
 * Every assertion here was mutation-tested against the real files (see the
 * session report): each was confirmed to go RED for the specific regression it
 * names, then the file was restored and re-confirmed green.
 *
 *   php tests/php/test-song-relocate-hardening.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/song_relocate.php
 * @see .claude/batch4b-relocate-hardening.md
 */

$ROOT = dirname(__DIR__, 2);
require_once __DIR__ . '/lib/php_source_units.php';

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        " . str_replace("\n", "\n        ", $detail) . "\n"; }
    }
}

/**
 * The condition of the block that ENCLOSES $pos — `if (…)` text, or ''.
 *
 * ELI5: "which `if` am I inside?" — not "which `if` is nearest above me".
 *
 * Detail: the obvious `strrpos($code, 'if (')` finds the LAST `if` before the
 * position, which for the relocate call is the inner
 * `if (function_exists('getCurrentUser'))` — a confidently wrong answer that
 * reported a real, correct guard as missing on this file's first run (rule #34,
 * again). So walk backwards balancing braces to the enclosing `{` and read the
 * condition that opened it. String literals are already `'@STR@'` in the `code`
 * view, so no brace inside a literal can unbalance the walk.
 */
function enclosingGuard(string $code, int $pos): string
{
    $depth = 0;
    for ($i = $pos - 1; $i >= 0; $i--) {
        $c = $code[$i];
        if ($c === '}') { $depth++; continue; }
        if ($c !== '{') { continue; }
        if ($depth > 0) { $depth--; continue; }
        $head = substr($code, 0, $i);
        $ifAt = strrpos($head, 'if (');
        return $ifAt === false ? '' : substr($head, $ifAt);
    }
    return '';
}

/** Index of the first SQL statement in a unit matching $re, or null. */
function sqlIndex(array $unit, string $re): ?int
{
    foreach ($unit['sql'] as $i => $s) {
        if (preg_match($re, (string)$s)) { return $i; }
    }
    return null;
}

$RELOCATE_PATH = $ROOT . '/appWeb/public_html/includes/song_relocate.php';
$SAVECORE_PATH = $ROOT . '/appWeb/public_html/manage/editor/save_song_core.php';
$EDITORJS_PATH = $ROOT . '/appWeb/public_html/manage/editor/editor.js';

$relocateRaw = (string)file_get_contents($RELOCATE_PATH);
$relocate    = phpSourceUnits($relocateRaw);
$saveCore    = phpSourceUnits((string)file_get_contents($SAVECORE_PATH));

ok('song_relocate.php parsed into function units', isset($relocate['songRelocate']),
   'units found: ' . implode(', ', array_keys($relocate)));
ok('save_song_core.php parsed into function units', isset($saveCore['editorSaveSongCore']),
   'units found: ' . implode(', ', array_keys($saveCore)));
if (!isset($relocate['songRelocate'], $saveCore['editorSaveSongCore'])) {
    fwrite(STDERR, "\nCannot continue without both units.\n");
    exit(1);
}

$move   = $relocate['songRelocate'];
$mint   = $relocate['songRelocateMintId'] ?? ['code' => '', 'sql' => []];
$assert = $relocate['songRelocateAssertCascades'] ?? ['code' => '', 'sql' => []];
$save   = $saveCore['editorSaveSongCore'];

/* ------------------------------------------------- H3 — cascade pre-check -- */

echo "\nH3 — the move verifies ON UPDATE CASCADE before it writes anything\n";

ok('songRelocateAssertCascades() is declared in song_relocate.php',
   $assert['code'] !== '');

/* ORDER is the whole point: a pre-check that runs after the re-key is not a
   pre-check. `@SQLUPD@` marks where an UPDATE statement literal stood, so the
   first one in this unit IS the re-key. */
$callPos = strpos($move['code'], 'songRelocateAssertCascades(');
$firstUpdate = strpos($move['code'], '@SQLUPD@');
ok('songRelocate() calls the pre-check', $callPos !== false,
   'nothing in songRelocate() invokes songRelocateAssertCascades() — a drifted install '
   . 'would fail mid-transaction with ER_ROW_IS_REFERENCED_2 and lose the whole save');
ok('and calls it BEFORE its first UPDATE',
   $callPos !== false && $firstUpdate !== false && $callPos < $firstUpdate,
   'the pre-check must precede every write; found call at ' . var_export($callPos, true)
   . ', first UPDATE at ' . var_export($firstUpdate, true));

$fkSql = implode("\n", $assert['sql']);
ok('the pre-check reads REFERENTIAL_CONSTRAINTS joined to KEY_COLUMN_USAGE',
   stripos($fkSql, 'INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS') !== false
   && stripos($fkSql, 'INFORMATION_SCHEMA.KEY_COLUMN_USAGE') !== false);
ok('scoped to the CURRENT schema (DATABASE()), not every schema on the server',
   stripos($fkSql, 'DATABASE()') !== false);
ok('filtered to FKs that reference tblSongs(SongId), and reads UPDATE_RULE',
   stripos($fkSql, 'tblSongs') !== false && stripos($fkSql, 'SongId') !== false
   && stripos($fkSql, 'UPDATE_RULE') !== false);

/* RuntimeException, NOT InvalidArgumentException — api2's move handler catches
   InvalidArgumentException to answer 422 "you named a book that doesn't exist",
   so throwing that here would report an un-migrated ENVIRONMENT as bad typing. */
ok('the refusal is a RuntimeException (api2 must not report it as a 422 typo)',
   strpos($assert['code'], 'throw new \\RuntimeException') !== false
   && strpos($assert['code'], 'InvalidArgumentException') === false);

/* The one place a PROSE assertion is right: four FKs really do lack the cascade
   on real installs, so this refusal WILL fire, and a refusal that does not name
   the fix is no more actionable than the raw MySQL error it replaces. Both
   precedents (migrate-backfill-canonical-songids.php, songbook_maintenance.php)
   point at the same migration.
   Scoped to THIS FUNCTION'S string literals, not the raw file: on its first run
   this read the whole source and passed happily while the message itself no
   longer named the migration — the doc-block above mentions it too. That is the
   "prose satisfied the guard" trap this repo keeps re-shipping, caught here only
   because the assertion was mutation-tested. */
$refusalText = implode(' ', $assert['sql']);
ok('the refusal MESSAGE names migrate-songid-prefix-fixup.php (the message IS the fix)',
   strpos($refusalText, 'migrate-songid-prefix-fixup.php') !== false,
   'message literals read: ' . substr($refusalText, 0, 400));

/* -------------------------------------------------------------- M1 — mint -- */

echo "\nM1 — the mint will not re-issue an id a redirect still claims\n";

ok('the mint probes tblSongRedirects.OldSongId',
   sqlIndex($mint, '/FROM\s+tblSongRedirects\b.*OldSongId/is') !== null,
   'the seed is MAX(Number)+1 and a move clears Number, so a freed slot can '
   . 're-mint the exact id a live redirect forwards away from — getSongById() '
   . 'matches it exactly and never consults the redirect, so an old bookmark '
   . 'silently serves a DIFFERENT song');
ok('gated on the EXISTING songRedirectsTableReady() helper, not a second probe',
   strpos($mint['code'], 'songRedirectsTableReady(') !== false
   && stripos(implode(' ', $mint['sql']), 'INFORMATION_SCHEMA') === false,
   'tblSongRedirects is optional (#1343 may not be migrated here) and an ungated '
   . 'read throws under mysqli STRICT — reuse the one helper, do not fork a probe');
ok('the gate is evaluated before the redirect statement is prepared',
   ($p = strpos($mint['code'], 'songRedirectsTableReady(')) !== false
   && ($q = strpos($mint['code'], '@STR@', $p)) !== false);

/* ------------------------------------------------ F3 — tblSongbookEntries -- */

echo "\nF3 — the move carries the tblSongbookEntries home row with it\n";

$iRekey   = sqlIndex($move, '/UPDATE\s+tblSongs\s+SET\s+SongId/i');
$iGate    = sqlIndex($move, '/^tblSongbookEntries$/');
$iEntries = sqlIndex($move, '/\btblSongbookEntries\b.*\b(SET|WHERE)\b/is');

ok('songRelocate() writes tblSongbookEntries', $iEntries !== null,
   'without it the junction row reads "(old book, NEW id, old number, IsHome=1)" — '
   . 'it claims the song\'s home is the book it just left, and uq_book_number keeps '
   . 'the vacated slot occupied in a book the song is no longer in');
ok('it moves the home row into the new book and clears SongNumber',
   sqlIndex($move, '/UPDATE\s+tblSongbookEntries\s+SET\s+SongbookAbbr\s*=\s*\?,\s*SongNumber\s*=\s*NULL/i') !== null);
ok('it handles a song that is ALREADY a member of the target book (uq_book_song)',
   sqlIndex($move, '/DELETE\s+FROM\s+tblSongbookEntries/i') !== null
   && sqlIndex($move, '/UPDATE\s+tblSongbookEntries\s+SET\s+IsHome\s*=\s*1/i') !== null,
   'multi-book membership is this table\'s whole point, so the target may already '
   . 'hold a row for this song; moving the old home row onto it would abort the '
   . 'entire save on a duplicate key');
ok('the whole block is existence-gated (migrations are web-run; #1044 may be absent)',
   strpos($move['code'], "songRelocateTableExists(\$db, 'tblSongbookEntries')") !== false
   && $iGate !== null && $iEntries !== null && $iGate < $iEntries);
ok('and it runs AFTER the re-key, so SongId has already cascaded',
   $iRekey !== null && $iEntries !== null && $iRekey < $iEntries,
   're-keying second would leave the entries rows pointing at the dead id');

/* ------------------------------------ M3 — the restriction rewrite is fatal -- */

echo "\nM3 — a restriction that cannot follow the song blocks the move\n";

ok('the content-restriction rewrite is still performed',
   sqlIndex($move, '/UPDATE\s+tblContentRestrictions\s+SET\s+EntityId/i') !== null);
/* The ABSENCE of a specific removed line — again scoped to this function's own
   literals, so a doc-comment that explains the removal cannot fail the check
   (the mirror image of the trap the refusal assertion above fell into). */
ok('the swallow-and-log around it is gone',
   strpos(implode(' ', $move['sql']), 'content-restriction rewrite failed') === false,
   'a restriction left on the dead id stops applying — withheld content becomes '
   . 'readable, the move commits anyway, and error_log is the only trace');
/* One try/catch remains in songRelocate: the SongCount recompute (F8 below).
   Counting is blunt but it is the property that matters — re-wrapping any other
   step "just to be safe" is how M3 happened in the first place. */
ok('songRelocate() has exactly ONE try/ block (the SongCount recompute)',
   substr_count($move['code'], 'try {') === 1,
   'found ' . substr_count($move['code'], 'try {') . '. Every step in this function '
   . 'except the cache recompute is load-bearing; wrapping one in a catch makes its '
   . 'failure invisible while the move commits regardless');

/* ------------------------------------------------------ F8 — transaction -- */

echo "\nF8 — a transaction-fatal error is re-thrown, never logged and ignored\n";

ok('the SongCount recompute catches mysqli_sql_exception specifically',
   strpos($move['code'], 'catch (\\mysqli_sql_exception') !== false,
   'a bare catch (\\Throwable) cannot tell "this column is missing on an old '
   . 'install" from "InnoDB just rolled back your entire transaction"');
ok('deadlock (1213) and lock-wait timeout (1205) are re-thrown',
   preg_match('/1213/', $move['code']) === 1
   && preg_match('/1205/', $move['code']) === 1
   && preg_match('/1213[^;]{0,80}1205[^;]{0,80}throw \$e;|1205[^;]{0,80}1213[^;]{0,80}throw \$e;/', $move['code']) === 1,
   'both roll back the WHOLE InnoDB transaction, not just the statement — swallowing '
   . 'them lets execution reach the caller\'s commit(), which commits nothing and '
   . 'answers {ok:true, songId:<new>} for a song that no longer exists under that id');

/* ------------------------------------------- M2 — an omitted key is not a move -- */

echo "\nM2 — a save that never mentions the songbook does not move the song\n";

ok('the save core tests the RAW payload for a songbook key',
   preg_match("/array_key_exists\(\s*'songbook'\s*,\s*\\\$song\s*\)/", $save['code']) === 1,
   '$songbookAbbr is defaulted to Misc hundreds of lines earlier, so a test against '
   . 'it can never be false — a partial save re-keyed the song into Misc, cleared '
   . 'its Number and wrote a permanent redirect');

/* Derived, not a fixed window: read the condition of the block that ENCLOSES
   the songRelocate() call and require the raw-key flag inside it. */
$relCall = strpos($save['code'], 'songRelocate(');
$guard   = $relCall === false ? '' : enclosingGuard($save['code'], $relCall);
ok('and the branch that calls songRelocate() is gated on that flag',
   $guard !== '' && strpos($guard, '$songbookSent') !== false,
   'guard read: ' . ($guard === '' ? '(not located)' : trim($guard)));
ok("the defeated `\$songbookAbbr !== ''` form is gone from that guard",
   $guard !== '' && strpos($guard, "\$songbookAbbr !== '@STR@'") === false);

/* --------------------------------------- F5 — the rename carries its Number -- */

echo "\nF5 — a rename tells the client the song's new Number, and the client applies it\n";

/* This is a two-file agreement, so both halves are asserted in one place. A
   comment in either file saying "keep these in sync" would be the failure, not
   the fix (rule #35). */
$respAssigned = strpos($save['code'], "\$respBody['assignedId']");
$respNumber   = strpos($save['code'], "\$respBody['number']");
ok('save_song_core sends `number` alongside a rename',
   $respAssigned !== false && $respNumber !== false && $respNumber > $respAssigned,
   'both rename paths change the Number — a MOVE clears it, a #1380 draft promotion '
   . 'into an official book adopts the minted slot — and the client cannot tell which');

$editorJs = (string)file_get_contents($EDITORJS_PATH);
/* Comment-strip so this file's own explanatory prose (and editor.js's, which
   discusses `data.number` at length) cannot satisfy a code assertion. */
$editorCode = preg_replace('#/\*[\s\S]*?\*/#', ' ', $editorJs);
$editorCode = (string)preg_replace('#(^|[^:])//.*$#m', '$1', (string)$editorCode);
$renameAt = strpos($editorCode, 'data.assignedId && data.previousId');
$renameBlock = $renameAt === false ? '' : substr($editorCode, $renameAt, 3000);
ok('editor.js has the rename-relabel block', $renameAt !== false);
ok('and it applies data.number to the in-memory song',
   preg_match('/\.number\s*=\s*data\.number/', $renameBlock) === 1,
   'without it the next save posts the stale number straight back, undoing the clear');
ok("and repaints #edit-number, or the DOM keeps feeding the old value back",
   strpos($renameBlock, "'edit-number'") !== false,
   'bindMetadataListeners() writes #edit-number straight into song.number on the next '
   . 'keystroke or save, so an un-refreshed field silently restores the old number');

if ($fail === 0) {
    echo "\nAll songbook-move hardening assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
