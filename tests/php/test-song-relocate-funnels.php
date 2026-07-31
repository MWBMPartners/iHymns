<?php

declare(strict_types=1);

/**
 * iHymns — every per-song songbook write goes through songRelocate() (#1679)
 * =========================================================================
 *
 * ELI5
 * ----
 * A song's id starts with its songbook's short code, so moving a song to a
 * different book has to give it a new id and leave a forwarding note. There is
 * more than one screen that can change a song's songbook. This test walks the
 * source tree, finds EVERY place that can change one song's songbook, and fails
 * the build if any of them does it the old way — because a funnel that was
 * missed does not error, it just half-applies the move and nobody notices.
 *
 * WHY THIS EXISTS
 * ---------------
 * `tblSongbooks.Abbreviation` IS the SongId prefix (CLAUDE.md rule #27) — the
 * PWA router, the OG-image endpoint and several API validators all parse it.
 * Writing `SongbookAbbr` on its own therefore produces a song whose id claims
 * one book and whose column claims another, permanently, with no error anywhere.
 * #1679's fix is `includes/song_relocate.php`: mint the new id, let the ~25
 * `ON UPDATE CASCADE` FKs re-key the children, rewrite the one non-FK soft
 * reference, clear `Number`, write the `tblSongRedirects` row.
 *
 * The remediation plan named THREE funnels. A grep of the tree found a FOURTH
 * (the v1 editor's revision restore, which wrote `SongbookAbbr=?` in its scalar
 * UPDATE). That is the whole argument for deriving this list from the tree
 * instead of typing it: the plan's list was assembled carefully by a human and
 * was still short by one (CLAUDE.md rule #34).
 *
 * WHAT COUNTS AS A "PER-SONG SONGBOOK WRITE"
 * ------------------------------------------
 * Three shapes, all detected on COMMENT-STRIPPED source (a doc-comment that
 * merely mentions `SongbookAbbr` must not satisfy — or trip — anything):
 *
 *   A. `SongbookAbbr = VALUES(SongbookAbbr)` — an UPSERT on the song PK whose
 *      duplicate-key tail overwrites the book (manage/editor/save_song_core.php).
 *   B. `UPDATE tblSongs SET … SongbookAbbr = … WHERE … SongId = …` — a literal
 *      single-song update.
 *   C. `UPDATE tblSongs SET \`{$col}\` = ? WHERE … SongId = …` where the file
 *      also carries a quoted `'SongbookAbbr'` literal — a column-allow-list
 *      driven write that CAN name the songbook column (manage/editor/api2.php's
 *      ED2_META_FIELDS). Shape B alone cannot see this one; missing it is how
 *      the second live write path would have been left behind.
 *
 * DELIBERATELY OUT OF SCOPE — by construction, not by allowlist:
 * a BOOK-WIDE rename (`UPDATE tblSongs SET SongbookAbbr = ? WHERE
 * SongbookAbbr = ?`, manage/songbooks.php + api.php's admin songbook update) is
 * a different feature: the BOOK changed its abbreviation, every song went with
 * it, and no individual song moved anywhere. Shapes B and C both require the
 * statement to be keyed on `SongId`, so those sites never match and need no
 * allowlist entry that could later go stale.
 *
 * ⚠️ NEVER `rg`, NEVER A SHELL-OUT. The corpus walk is a plain PHP
 * `RecursiveDirectoryIterator` skipping `.git` / `node_modules` / `vendor` ONLY.
 * Dot-directories are deliberately INCLUDED: `rg` hides them by default, which
 * makes the whole of `appWeb/.sql/` — every migration, i.e. every bulk re-key —
 * invisible to a scan built on it (orphan-inventory §0.1, reproduced twice).
 *
 * WHAT IT CANNOT CATCH (so its tick is not over-read)
 * --------------------------------------------------
 *  - SQL assembled from variables across statements (`$sql .= …`). None exists
 *    today; if one appears this test under-reports it and says nothing.
 *  - A funnel in another language (the native apps do not write the DB).
 *  - Whether songRelocate() is called on the RIGHT branch — it asserts the call
 *    exists in the file, not that every code path reaches it. The behavioural
 *    half of that lives in the runtime verification pass (no MySQL here).
 *
 *   php tests/php/test-song-relocate-funnels.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see .claude/remediation-plan-2026-07-30.md §4.4 (R1–R5)
 */

$ROOT    = dirname(__DIR__, 2);
$APP_DIR = $ROOT . '/appWeb';

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

/* ---------------------------------------------------------------- helpers -- */

/**
 * Comment-strip PHP source with the real tokenizer, then collapse whitespace.
 *
 * ELI5: throw away every comment, then squash runs of spaces/newlines into one
 * space so a statement written across five lines reads as one string.
 *
 * Detail: `token_get_all()` is exact where a regex is not — it knows a `/*`
 * inside a string literal is not a comment, and that a `#` inside a string is
 * not a line comment. String CONTENT survives (the SQL we are matching lives in
 * string literals). Collapsing whitespace is what lets the statement-shape
 * patterns below use simple ` ` separators instead of `\s+` everywhere.
 *
 * @see https://www.php.net/manual/en/function.token-get-all.php
 */
function relocGuardStrip(string $src): string
{
    $out  = '';
    $toks = @token_get_all($src);
    if (!is_array($toks)) { return ''; }
    foreach ($toks as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return (string)preg_replace('/\s+/', ' ', $out);
}

/** Plain-PHP recursive walk. No rg, no shell-out, dot-directories INCLUDED. */
function relocGuardPhpFiles(string $dir): array
{
    $skip  = ['.git' => true, 'node_modules' => true, 'vendor' => true];
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function ($current) use ($skip): bool {
                return !($current->isDir() && isset($skip[$current->getFilename()]));
            }
        )
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $files[] = $f->getPathname(); }
    }
    sort($files);
    return $files;
}

/**
 * Every `UPDATE tblSongs …` statement in a stripped source, as [set, where].
 *
 * The SQL always lives inside a quoted PHP string, so the statement is bounded
 * by the first quote character after `UPDATE` — that is what keeps a 600-char
 * window from bleeding into whatever code follows and mis-attributing its
 * `WHERE SongId = ?` to this statement (the api2-contract test's window bug,
 * rule #34).
 *
 * @return array<int,array{set:string,where:string}>
 */
function relocGuardUpdateStatements(string $stripped): array
{
    $out = [];
    if (!preg_match_all('/UPDATE\s+tblSongs\b/i', $stripped, $m, PREG_OFFSET_CAPTURE)) { return $out; }
    foreach ($m[0] as $hit) {
        $tail = substr($stripped, (int)$hit[1], 800);
        $stmt = substr($tail, 0, strcspn($tail, "'\""));   // cut at the string-literal terminator
        $parts = preg_split('/\bWHERE\b/i', $stmt, 2);
        if (!is_array($parts) || count($parts) < 2) { continue; }
        $out[] = ['set' => $parts[0], 'where' => $parts[1]];
    }
    return $out;
}

/** Does this stripped source write ONE song's songbook? Returns the shape letters. */
function relocGuardWriteShapes(string $stripped): array
{
    $shapes = [];

    /* A — UPSERT duplicate-key tail overwriting the book. */
    if (preg_match('/SongbookAbbr\s*=\s*VALUES\(\s*SongbookAbbr\s*\)/i', $stripped)) {
        $shapes[] = 'A (upsert ON DUPLICATE KEY overwrite)';
    }

    $stmts = relocGuardUpdateStatements($stripped);
    foreach ($stmts as $s) {
        /* Every shape below is keyed on the SONG — a book-wide rename
           (WHERE SongbookAbbr = ?) is a different feature and never matches. */
        if (!preg_match('/\bSongId\s*=/i', $s['where'])) { continue; }

        /* B — literal SongbookAbbr assignment. */
        if (preg_match('/\bSongbookAbbr\s*=/i', $s['set'])) {
            $shapes[] = 'B (literal single-song SongbookAbbr update)';
        }
        /* C — interpolated column name; the file must also be able to NAME the
           songbook column for this to be a songbook write. */
        if (preg_match('/SET\s+`?\{?\$/', $s['set']) && strpos($stripped, "'SongbookAbbr'") !== false) {
            $shapes[] = 'C (allow-list driven dynamic column update)';
        }
    }
    return array_values(array_unique($shapes));
}

/* ------------------------------------------------------- corpus + derive -- */

$files = relocGuardPhpFiles($APP_DIR);
ok('corpus walk found PHP files under appWeb/ (incl. dot-directories)', count($files) > 200,
   'found ' . count($files) . ' — a collapse here would make every check below vacuously green');

$stripped = [];
foreach ($files as $f) { $stripped[$f] = relocGuardStrip((string)file_get_contents($f)); }

/* Sanity: the walk really does reach appWeb/.sql (the rg blind spot). */
$sawSqlDir = false;
foreach ($files as $f) { if (strpos($f, '/appWeb/.sql/') !== false) { $sawSqlDir = true; break; } }
ok('corpus walk reaches appWeb/.sql/ (the dot-directory rg silently skips)', $sawSqlDir);

/* The implementation file is DERIVED, not named: it is whichever file declares
   `function songRelocate(`. Exactly one must, or the helper has been forked —
   which is the regression this whole file exists to prevent. */
$implFiles = [];
foreach ($stripped as $f => $src) {
    if (preg_match('/function\s+songRelocate\s*\(/', $src)) { $implFiles[] = $f; }
}
ok('exactly ONE file declares songRelocate() (the helper is not forked)', count($implFiles) === 1,
   'declaring files: ' . (implode(', ', $implFiles) ?: '(none)'));

$implSet = array_flip($implFiles);

/* ---------------------------------------------------- CHECK 1 — funnels --- */

echo "\nCHECK 1 — every per-song songbook write funnels through songRelocate()\n";

$sites   = [];
$missing = [];
foreach ($stripped as $f => $src) {
    if (isset($implSet[$f])) { continue; }          // the helper itself IS the mechanism
    $shapes = relocGuardWriteShapes($src);
    if ($shapes === []) { continue; }
    $rel = str_replace($ROOT . '/', '', $f);
    $sites[$rel] = $shapes;
    /* The call, not the declaration — declarations were excluded above. */
    if (!preg_match('/\bsongRelocate\s*\(/', $src)) { $missing[] = $rel . '  [' . implode(', ', $shapes) . ']'; }
}

foreach ($sites as $rel => $shapes) {
    echo "  · site: {$rel}  " . implode(' + ', $shapes) . "\n";
}
ok('every per-song songbook write site calls songRelocate() (' . count($sites) . ' site(s) found)',
   $missing === [],
   $missing === [] ? '' : "sites that change a song's songbook WITHOUT re-keying its SongId:\n  - "
       . implode("\n  - ", $missing)
       . "\nFix: call songRelocate() (includes/song_relocate.php) instead of writing SongbookAbbr directly.");

/* The list must not silently empty out — a refactor that renamed the columns
   would otherwise leave this file passing while checking nothing. */
ok('at least the two known funnels are still detected', count($sites) >= 2,
   'detected ' . count($sites) . ' site(s); expected the save core + the granular metadata update');

/* ------------------------------------------ CHECK 2 — snapshot restore ---- */

echo "\nCHECK 2 — a revision RESTORE never rewrites the songbook\n";

/*
 * A restore replays a stored snapshot's scalars. It cannot re-key the SongId,
 * cascade the FK children or write a redirect — so restoring `SongbookAbbr`
 * recreates the exact id/column mismatch #1679 removes, as a SIDE EFFECT of an
 * action aimed at the lyrics. Both restore paths must skip that column.
 *
 * Derived: for every file with the shape-C column map, find each `foreach` over
 * that map, take the region from the loop header to the FIRST `UPDATE tblSongs`
 * after it, and require the songbook skip inside that region. Using the write
 * as the region's end (rather than a fixed character window) means the check
 * also enforces ORDER — a skip placed after the write would not be found — and
 * cannot rot as the loop body grows.
 */
$restoreChecked = 0;
$restoreFails   = [];
foreach ($stripped as $f => $src) {
    $rel = str_replace($ROOT . '/', '', $f);
    if (!preg_match_all('/foreach\s*\(\s*([A-Z][A-Z0-9_]+)\s+as\s+\$\w+\s*=>\s*\[\s*\$(\w+)/', $src, $loops, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;
    }
    foreach ($loops as $loop) {
        $constName = $loop[1][0];
        /* Is this constant the songbook-capable column map? Derived from the
           constant's own body, so renaming the constant changes nothing. */
        if (!preg_match('/const\s+' . preg_quote($constName, '/') . '\s*=\s*\[(.*?)\];/s', $src, $cm)) { continue; }
        if (strpos($cm[1], "'SongbookAbbr'") === false) { continue; }

        $start = (int)$loop[0][1];
        $after = substr($src, $start, 6000);
        $wPos  = stripos($after, 'UPDATE tblSongs');
        if ($wPos === false) { continue; }            // a read-only loop — nothing to guard
        $region = substr($after, 0, $wPos);
        $restoreChecked++;
        if (!preg_match("/\\\$\\w+\\s*===\\s*'SongbookAbbr'[^;]{0,120}continue/", $region)) {
            $restoreFails[] = $rel . ' — foreach (' . $constName . ' …) writes tblSongs without skipping SongbookAbbr'
                . ' (region to the write is ' . strlen($region) . ' chars)';
        }
    }
}
ok('every allow-list-driven scalar restore skips SongbookAbbr (' . $restoreChecked . ' writing loop(s))',
   $restoreFails === [],
   implode("\n", $restoreFails));
ok('at least one such loop was actually inspected', $restoreChecked >= 1,
   'zero writing loops found — the derivation broke, so CHECK 2 asserted nothing');

/*
 * The v1 restore is a HAND-WRITTEN scalar UPDATE, not a map loop, so CHECK 2's
 * derivation cannot see it — but CHECK 1's shape B can, and does: if anyone
 * re-adds `SongbookAbbr=?` to it, that file becomes a per-song songbook write
 * site with no songRelocate() call and CHECK 1 goes red. That is the coverage
 * for the funnel the plan missed; asserted here explicitly so the reasoning is
 * not lost if CHECK 1's site list changes.
 */

/* ------------------------------------------------- CHECK 3 — redirects ---- */

echo "\nCHECK 3 — the move writes a permalink redirect\n";

/* A re-key without a redirect row is a silently broken bookmark for every
   existing link — the #1343 failure this reuses the machinery to avoid. */
$implSrc = $implFiles ? $stripped[$implFiles[0]] : '';
ok('songRelocate() writes a tblSongRedirects row',
   (bool)preg_match("/songRedirectWrite\s*\(.{0,160}'move'/s", $implSrc));
ok("songRelocate() clears Number on move (owner's stated default)",
   (bool)preg_match('/UPDATE tblSongs SET SongId = \?, SongbookAbbr = \?, Number = NULL WHERE SongId = \?/', $implSrc));
ok('songRelocate() does NOT touch PublicId (it exists to survive a move)',
   strpos($implSrc, 'PublicId') === false || !preg_match('/UPDATE\s+tblSongs[^\'"]{0,200}PublicId\s*=/i', $implSrc));

/* ------------------------------------------------ CHECK 4 — JSON reads ---- */

echo "\nCHECK 4 — the JSON song read follows the redirect layer\n";

/* The HTML fragment has followed redirects since #1343; the JSON path had not,
   so a native/PWA client holding a moved id got a bare 404 with no way to learn
   the new one. Derived by locating the song_detail dispatch case rather than by
   trusting a line number. */
$apiPhp = $stripped[$ROOT . '/appWeb/public_html/api.php'] ?? '';
ok('api.php was read for the song_detail check', $apiPhp !== '');
$sdPos = strpos($apiPhp, "case 'song_detail':");
ok("api.php dispatches 'song_detail'", $sdPos !== false);
if ($sdPos !== false) {
    $sdBlock = substr($apiPhp, $sdPos, 4000);
    ok('song_detail resolves the redirect layer before 404-ing',
       strpos($sdBlock, 'songRedirectResolve(') !== false);
    ok('song_detail reports the followed id as redirectedFrom',
       strpos($sdBlock, 'redirectedFrom') !== false);
    ok('song_detail answers 410 for a tombstone (status is the contract, not the prose)',
       (bool)preg_match('/sendJson\([^;]{0,120},\s*410\)/', $sdBlock));
}

/* ------------------------------------------------- MUTATION SELF-TESTS ---- */

echo "\nMutation self-tests — each check must be ABLE to fail\n";

/* These run on in-memory copies of the REAL sources on every invocation. They
   are the standing proof that the patterns above are not vacuous; they do NOT
   replace mutating the real files by hand once, which is how this file was
   validated before it was first believed (rule #34). */

$saveCore = $stripped[$ROOT . '/appWeb/public_html/manage/editor/save_song_core.php'] ?? '';
ok('M0: the save core is one of the detected sites', $saveCore !== '' && relocGuardWriteShapes($saveCore) !== []);

/* M1 — remove the songRelocate() CALL from the save core: CHECK 1 must flag it. */
$m1 = preg_replace('/\bsongRelocate\s*\(/', 'someOtherThing(', $saveCore);
ok('M1: deleting songRelocate() from the save core makes CHECK 1 flag it',
   relocGuardWriteShapes($m1) !== [] && !preg_match('/\bsongRelocate\s*\(/', $m1));

/* M2 — a NEW file that updates one song's songbook literally and never
   relocates is exactly the regression this guards; it must be detected. */
$m2 = relocGuardStrip('<?php $u = $db->prepare("UPDATE tblSongs SET SongbookAbbr = ? WHERE SongId = ?"); $u->execute();');
ok('M2: a new literal single-song SongbookAbbr update is detected',
   in_array('B (literal single-song SongbookAbbr update)', relocGuardWriteShapes($m2), true));

/* M3 — the book-wide rename must NOT be detected (a guard that fails on correct
   code gets weakened or deleted rather than fixed — rule #34). */
$m3 = relocGuardStrip('<?php $s = $db->prepare(\'UPDATE tblSongs SET SongbookAbbr = ? WHERE SongbookAbbr = ?\'); $s->execute();');
ok('M3: a BOOK-WIDE abbreviation rename is NOT flagged (no false positive)',
   relocGuardWriteShapes($m3) === []);

/* M4 — a doc-comment that merely quotes the SQL must not register as a write
   site. This is the "prose satisfied the guard" failure three consecutive
   batches shipped; the comment strip is what prevents it. */
$m4 = relocGuardStrip("<?php /* UPDATE tblSongs SET SongbookAbbr = ? WHERE SongId = ? — how it used to work */ \$x = 1;");
ok('M4: the same SQL inside a COMMENT is not a write site', relocGuardWriteShapes($m4) === []);

/* M5 — removing the skip from the restore loop must make CHECK 2 flag it. */
$api2 = $stripped[$ROOT . '/appWeb/public_html/manage/editor/api2.php'] ?? '';
$m5   = str_replace("if (\$column === 'SongbookAbbr') { continue; }", '', $api2);
$m5Flagged = false;
if (preg_match_all('/foreach\s*\(\s*([A-Z][A-Z0-9_]+)\s+as\s+\$\w+\s*=>\s*\[\s*\$(\w+)/', $m5, $l5, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
    foreach ($l5 as $loop) {
        if (!preg_match('/const\s+' . preg_quote($loop[1][0], '/') . '\s*=\s*\[(.*?)\];/s', $m5, $cm5)) { continue; }
        if (strpos($cm5[1], "'SongbookAbbr'") === false) { continue; }
        $after = substr($m5, (int)$loop[0][1], 6000);
        $wPos  = stripos($after, 'UPDATE tblSongs');
        if ($wPos === false) { continue; }
        if (!preg_match("/\\\$\\w+\\s*===\\s*'SongbookAbbr'[^;]{0,120}continue/", substr($after, 0, $wPos))) { $m5Flagged = true; }
    }
}
ok('M5: deleting the SongbookAbbr skip from the restore loop makes CHECK 2 flag it', $m5Flagged);

/* M6 — CHECK 4 must be able to fail: strip the resolver call from song_detail. */
$m6 = str_replace('songRedirectResolve(', 'somethingElse(', $apiPhp);
$m6Pos = strpos($m6, "case 'song_detail':");
ok('M6: removing songRedirectResolve() from song_detail makes CHECK 4 flag it',
   $m6Pos !== false && strpos(substr($m6, $m6Pos, 4000), 'songRedirectResolve(') === false);

/* M7 — the comment stripper itself must work on the shapes that matter. */
ok('M7: relocGuardStrip() removes a block comment but keeps string content',
   strpos(relocGuardStrip('<?php /* SongbookAbbr */ $q = "SongbookAbbr";'), '/*') === false
   && substr_count(relocGuardStrip('<?php /* SongbookAbbr */ $q = "SongbookAbbr";'), 'SongbookAbbr') === 1);

if ($fail === 0) {
    echo "\nAll songbook-move funnel assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
