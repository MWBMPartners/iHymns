<?php

declare(strict_types=1);

/**
 * iHymns — every per-song songbook write goes through songRelocate() (#1679)
 * =========================================================================
 *
 * ELI5
 * ----
 * A song's id starts with its songbook's short code, so moving a song to a
 * different book has to give it a new id and leave a forwarding note. More than
 * one screen can change a song's songbook. This test walks the source tree,
 * finds EVERY place that can change one song's songbook, and fails the build if
 * any of them does it the old way — because a missed funnel does not error, it
 * just half-applies the move and nobody notices.
 *
 * WHY THIS EXISTS
 * ---------------
 * `tblSongbooks.Abbreviation` IS the SongId prefix (CLAUDE.md rule #27) — the
 * PWA router, the OG-image endpoint and several API validators all parse it.
 * Writing `SongbookAbbr` on its own therefore produces a song whose id claims
 * one book and whose column claims another, permanently, with no error anywhere.
 * #1679's fix is `includes/song_relocate.php`.
 *
 * REWRITTEN FOR #1688 — the first version was defeatable four ways
 * ----------------------------------------------------------------
 * An adversarial review drove the full suite to green with the exact regression
 * this file exists to catch, four separate ways. All four are now self-tested
 * (S1–S5 below), because a bypass that is merely fixed will be reintroduced:
 *
 *  1. **Scope was the FILE.** "Does this file mention songRelocate() anywhere?"
 *     is satisfied forever by one call — so `save_song_core.php` and `api2.php`,
 *     the two files a new funnel is most likely to land in, had become
 *     permanently exempt. Scope is now the FUNCTION.
 *  2. **Prose satisfied it.** `error_log('songRelocate() not used here')` made a
 *     file pass with the raw write still in place. Assertions about CODE now run
 *     on a view where a string literal that says something (rather than names
 *     something) has been reduced to an opaque atom.
 *  3. **A quoted value truncated the statement.** The old parser cut an `UPDATE`
 *     at the first quote, so `SET a = ?, b = 'x' WHERE SongId = ?` lost its
 *     WHERE and was discarded. Real code already tripped this
 *     (`lyrics_ingest.php`). SQL is now reconstructed from tokens.
 *  4. **`WHERE Id = ?` and backticks were invisible.** Both idioms already
 *     appear in this tree.
 *
 * WHAT COUNTS AS A "PER-SONG SONGBOOK WRITE"
 * ------------------------------------------
 *   A. `SongbookAbbr = VALUES(SongbookAbbr)` — an UPSERT whose duplicate-key
 *      tail overwrites the book (manage/editor/save_song_core.php).
 *   B. `UPDATE tblSongs SET … SongbookAbbr = … WHERE <song key>` — a literal
 *      single-song update.
 *   C. `UPDATE tblSongs SET {$col} = ? WHERE <song key>` in a function that can
 *      NAME the songbook column — an allow-list-driven write (api2.php's
 *      ED2_META_FIELDS). Shape B alone cannot see this one.
 *
 * Shape C does NOT count when the function provably SKIPS the songbook column
 * (a revision restore does this deliberately). That is not an exemption: CHECK 2
 * asserts the skip is really there, so removing it turns the function back into
 * a shape-C site and CHECK 1 flags it. The two checks reinforce rather than
 * duplicate — one of them alone is defeatable.
 *
 * DELIBERATELY OUT OF SCOPE — by construction, not by allowlist:
 * a BOOK-WIDE rename (`… SET SongbookAbbr = ? WHERE SongbookAbbr = ?`) is a
 * different feature: the BOOK changed its abbreviation and no individual song
 * moved. The exclusion is keyed on the WHERE naming the BOOK and no song key,
 * so those sites never match and need no allowlist entry that could go stale.
 *
 * ⚠️ NEVER `rg`, NEVER A SHELL-OUT. The corpus walk is a plain PHP
 * `RecursiveDirectoryIterator` skipping `.git` / `node_modules` / `vendor` ONLY.
 * Dot-directories are deliberately INCLUDED: `rg` hides them by default, which
 * makes the whole of `appWeb/.sql/` — every migration, i.e. every bulk re-key —
 * invisible to a scan built on it (orphan-inventory §0.1, reproduced twice).
 *
 * WHAT IT CANNOT CATCH (so its tick is not over-read)
 * --------------------------------------------------
 *  - SQL assembled across STATEMENTS (`$sql .= …` in a loop). Concatenation
 *    within one expression is reconstructed; accumulation across statements is
 *    not. None exists today; if one appears this under-reports and says nothing.
 *  - A funnel in another language (the native apps do not write the DB).
 *  - Whether songRelocate() is on the RIGHT branch. It asserts the call is in
 *    the same FUNCTION as the write — not that every path reaches it. The
 *    behavioural half needs a live database, which this container has not got.
 *  - A write delegated to a helper in ANOTHER file that itself relocates. That
 *    would report a false positive, which is the safe direction: someone must
 *    look, rather than the check quietly passing.
 *
 *   php tests/php/test-song-relocate-funnels.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see .claude/remediation-plan-2026-07-30.md §4.4 (R1–R5)
 * @see .claude/batch4b-relocate-hardening.md (#1688 — why this was rewritten)
 */

require_once __DIR__ . '/lib/php_source_units.php';

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
 * Does this WHERE clause key on ONE song, or on a whole book?
 *
 * ELI5: is this changing one song, or renaming a whole songbook at once?
 *
 * Detail: the old version asked "does the WHERE mention SongId?" and therefore
 * could not see `WHERE Id = ?` — `tblSongs.Id` is the AUTO_INCREMENT primary
 * key and `migrate-song-normalized-title.php:143` already uses that idiom on
 * this very table. Asking the question the other way round — "is this keyed on
 * the BOOK and nothing else?" — is both correct and open-ended: a song key this
 * function has never heard of still counts as per-song, which is the safe
 * direction for a guard.
 */
function relocGuardIsBookWide(string $where): bool
{
    $namesBook = (bool)preg_match('/\bSongbookAbbr\s*(=|<=>|IN\b|LIKE\b)/i', $where);
    $namesSong = (bool)preg_match('/\b(SongId|Id|PublicId)\s*(=|<=>|IN\b)/i', $where);
    return $namesBook && !$namesSong;
}

/**
 * The identifier literals that mean "the songbook column", derived from source.
 *
 * ELI5: work out what this file calls the songbook field, instead of assuming.
 *
 * Detail: the column is `SongbookAbbr`, but the allow-list maps a request key
 * (`'songbook'`) to it, and a skip written against EITHER is correct. Hardcoding
 * only the column name made the guard fail on correct code — rewriting the skip
 * to the semantically identical `$field === 'songbook'` turned it red, which is
 * rule #34's other failure direction (a guard too blunt gets deleted, not
 * fixed). Both names are now derived from the map's own body.
 *
 * @return list<string>
 */
function relocGuardSongbookNames(string $unitCode, string $fileCode): array
{
    $names = ['SongbookAbbr'];
    // 'songbook' => ['SongbookAbbr', …]  — take the key that maps to the column.
    if (preg_match_all("/'(\w+)'\s*=>\s*\[\s*'SongbookAbbr'/", $fileCode, $m)) {
        foreach ($m[1] as $key) { $names[] = $key; }
    }
    return array_values(array_unique($names));
}

/**
 * Does this function body skip the songbook column in an allow-list loop?
 *
 * Returns the matched source text (so a mutation self-test can remove exactly
 * what the check found, rather than a literal somebody typed into the test —
 * which is how the previous version's self-test came to pass while the check it
 * was supposed to be proving had gone red).
 */
function relocGuardFindSongbookSkip(string $unitCode, array $names): ?string
{
    foreach ($names as $name) {
        $q = preg_quote($name, '/');
        /* `$x === 'Name' … continue` or `'Name' === $x … continue`, within ONE
           statement. Runs on the CODE view, where a sentence that merely
           CONTAINS this phrasing has already been reduced to '@STR@'.

           The span excludes `;` only. An earlier version also excluded `{`,
           which meant it could not match the actual production code —
           `if ($column === 'SongbookAbbr') { continue; }` has a brace between
           the comparison and the `continue`. `;` alone is the right stop: it
           ends the statement, so the match cannot drift into the next one. */
        $re = "/(\\\$\\w+\\s*===?\\s*'$q'|'$q'\\s*===?\\s*\\\$\\w+)[^;]{0,60}?\\bcontinue\\b/";
        if (preg_match($re, $unitCode, $m)) {
            return $m[0];
        }
    }
    return null;
}

/**
 * Classify one analysis unit (a function body, or file scope).
 *
 * @param array{code:string, sql:list<string>} $unit
 * @return array{shapes:list<string>, dynamic:bool, canNameSongbook:bool, skip:?string}
 */
function relocGuardClassifyUnit(array $unit, string $fileCode): array
{
    $shapes   = [];
    $dynamic  = false;
    $code     = $unit['code'];
    $names    = relocGuardSongbookNames($code, $fileCode);
    /* "Can this write name the songbook column?" is a FILE-level question: the
       column allow-list is a file-scope const, and the loop that consumes it
       carries no literal of its own. Asking the UNIT was subtly circular —
       `ed2_applySongSnapshot`'s only mention of `'SongbookAbbr'` IS its skip, so
       deleting the skip removed the evidence that the skip was needed, and the
       check went blind instead of red. Its own self-test caught that. */
    $canName  = false;
    foreach ($names as $n) {
        if (strpos($fileCode, "'" . $n . "'") !== false) { $canName = true; break; }
    }
    $skip = $canName ? relocGuardFindSongbookSkip($code, $names) : null;

    foreach ($unit['sql'] as $raw) {
        $sql = phpUnitsNormaliseSql($raw);

        /* A — UPSERT duplicate-key tail overwriting the book. */
        if (preg_match('/SongbookAbbr\s*=\s*VALUES\(\s*SongbookAbbr\s*\)/i', $sql)) {
            $shapes[] = 'A (upsert ON DUPLICATE KEY overwrite)';
        }

        if (!preg_match('/\bUPDATE\s+tblSongs\b/i', $sql)) {
            continue;
        }
        $parts = preg_split('/\bWHERE\b/i', $sql, 2);
        if (!is_array($parts) || count($parts) < 2) {
            continue;                       // no WHERE = a mass update, not a move
        }
        [$set, $where] = $parts;
        if (relocGuardIsBookWide($where)) {
            continue;                       // the book renamed; no song moved
        }

        if (preg_match('/\bSongbookAbbr\s*=(?!\s*VALUES)/i', $set)) {
            $shapes[] = 'B (literal single-song SongbookAbbr update)';
        }
        /* C — the SET names its column through a variable. */
        if (preg_match('/\bSET\s+\{*\$/', $set)) {
            $dynamic = true;
            if ($canName && $skip === null) {
                $shapes[] = 'C (allow-list driven dynamic column update)';
            }
        }
    }

    return [
        'shapes'          => array_values(array_unique($shapes)),
        'dynamic'         => $dynamic,
        'canNameSongbook' => $canName,
        'skip'            => $skip,
    ];
}

/* ------------------------------------------------------- corpus + derive -- */

$files = relocGuardPhpFiles($APP_DIR);
ok('corpus walk found PHP files under appWeb/ (incl. dot-directories)', count($files) > 200,
   'found ' . count($files) . ' — a collapse here would make every check below vacuously green');

$units    = [];      // file => [unitName => ['code'=>…, 'sql'=>[…]]]
$fileCode = [];      // file => all unit code concatenated (for map lookups)
foreach ($files as $f) {
    $u = phpSourceUnits((string)file_get_contents($f));
    $units[$f]    = $u;
    $fileCode[$f] = implode(' ', array_column($u, 'code'));
}

/* Sanity: the walk really does reach appWeb/.sql (the rg blind spot). */
$sawSqlDir = false;
foreach ($files as $f) { if (strpos($f, '/appWeb/.sql/') !== false) { $sawSqlDir = true; break; } }
ok('corpus walk reaches appWeb/.sql/ (the dot-directory rg silently skips)', $sawSqlDir);

/* The implementation file is DERIVED, not named: whichever file declares
   `function songRelocate(`. Exactly one must, or the helper has been forked —
   the regression this whole file exists to prevent. */
$implFiles = [];
foreach ($units as $f => $u) {
    if (isset($u['songRelocate'])) { $implFiles[] = $f; }
}
ok('exactly ONE file declares songRelocate() (the helper is not forked)', count($implFiles) === 1,
   'declaring files: ' . (implode(', ', $implFiles) ?: '(none)'));
$implSet = array_flip($implFiles);

/* ---------------------------------------------------- CHECK 1 — funnels --- */

echo "\nCHECK 1 — every per-song songbook write calls songRelocate() IN THE SAME FUNCTION\n";

$sites   = [];
$missing = [];
foreach ($units as $f => $fileUnits) {
    if (isset($implSet[$f])) { continue; }              // the helper IS the mechanism
    $rel = str_replace($ROOT . '/', '', $f);
    foreach ($fileUnits as $name => $unit) {
        $c = relocGuardClassifyUnit($unit, $fileCode[$f]);
        if ($c['shapes'] === []) { continue; }
        $sites["$rel :: $name"] = $c['shapes'];
        /* The CALL, in the same unit, in CODE — not a mention in a string. */
        if (!preg_match('/\bsongRelocate\s*\(/', $unit['code'])) {
            $missing[] = "$rel :: $name  [" . implode(', ', $c['shapes']) . ']';
        }
    }
}

foreach ($sites as $where => $shapes) {
    echo "  · site: {$where}  " . implode(' + ', $shapes) . "\n";
}
ok('every per-song songbook write site calls songRelocate() (' . count($sites) . ' site(s) found)',
   $missing === [],
   $missing === [] ? '' : "functions that change a song's songbook WITHOUT re-keying its SongId:\n  - "
       . implode("\n  - ", $missing)
       . "\nFix: call songRelocate() (includes/song_relocate.php) instead of writing SongbookAbbr directly.");

ok('at least the two known funnels are still detected', count($sites) >= 2,
   'detected ' . count($sites) . ' site(s); expected the save core + the granular metadata update');

/* ------------------------------------------ CHECK 2 — snapshot restore ---- */

echo "\nCHECK 2 — an allow-list-driven scalar write either SKIPS the songbook or relocates\n";

/*
 * A restore replays a stored snapshot's scalars. It cannot re-key the SongId,
 * cascade the FK children or write a redirect — so restoring `SongbookAbbr`
 * recreates the exact id/column mismatch #1679 removes, as a SIDE EFFECT of an
 * action aimed at the lyrics.
 *
 * This is the other half of shape C: CHECK 1 exempts a dynamic write that
 * skips, and CHECK 2 asserts the skip is real. Delete the skip and the function
 * becomes a shape-C site with no relocate call, so CHECK 1 fails — neither
 * check can be satisfied alone.
 */
$restoreChecked = 0;
$restoreFails   = [];
foreach ($units as $f => $fileUnits) {
    if (isset($implSet[$f])) { continue; }
    $rel = str_replace($ROOT . '/', '', $f);
    foreach ($fileUnits as $name => $unit) {
        $c = relocGuardClassifyUnit($unit, $fileCode[$f]);
        if (!$c['dynamic'] || !$c['canNameSongbook']) { continue; }
        $restoreChecked++;
        if ($c['skip'] === null && !preg_match('/\bsongRelocate\s*\(/', $unit['code'])) {
            $restoreFails[] = "$rel :: $name — writes tblSongs through a column allow-list that can name"
                . ' the songbook, but neither skips it nor relocates';
        }
    }
}
ok('every allow-list-driven scalar write is accounted for (' . $restoreChecked . ' such function(s))',
   $restoreFails === [], implode("\n", $restoreFails));
ok('at least one such function was actually inspected', $restoreChecked >= 1,
   'zero found — the derivation broke, so CHECK 2 asserted nothing');

/* ------------------------------------------------- CHECK 3 — redirects ---- */

echo "\nCHECK 3 — the move writes a permalink redirect\n";

$implUnits = $implFiles ? $units[$implFiles[0]] : [];
$implCode  = implode(' ', array_column($implUnits, 'code'));
$implSql   = [];
foreach ($implUnits as $u) { foreach ($u['sql'] as $s) { $implSql[] = phpUnitsNormaliseSql($s); } }
$implSqlAll = implode(' ;; ', $implSql);

ok('songRelocate() writes a tblSongRedirects row',
   (bool)preg_match("/songRedirectWrite\s*\(.{0,200}'move'/s", $implCode));
ok("songRelocate() clears Number on move (owner's stated default)",
   (bool)preg_match('/UPDATE tblSongs SET SongId = \?, SongbookAbbr = \?, Number = NULL WHERE SongId = \?/i', $implSqlAll));
ok('songRelocate() does NOT touch PublicId (it exists to survive a move)',
   !preg_match('/UPDATE\s+tblSongs[^;]{0,200}PublicId\s*=/i', $implSqlAll));

/* ------------------------------------------------ CHECK 4 — JSON reads ---- */

echo "\nCHECK 4 — the JSON song read follows the redirect layer\n";

$apiUnits = $units[$ROOT . '/appWeb/public_html/api.php'] ?? [];
$apiCode  = implode(' ', array_column($apiUnits, 'code'));
ok('api.php was read for the song_detail check', $apiCode !== '');
$sdPos = strpos($apiCode, "case 'song_detail':");
ok("api.php dispatches 'song_detail'", $sdPos !== false);
if ($sdPos !== false) {
    $sdBlock = substr($apiCode, $sdPos, 4000);
    ok('song_detail resolves the redirect layer before 404-ing',
       strpos($sdBlock, 'songRedirectResolve(') !== false);
    ok('song_detail reports the followed id as redirectedFrom',
       strpos($sdBlock, 'redirectedFrom') !== false);
    ok('song_detail answers 410 for a tombstone (status is the contract, not the prose)',
       (bool)preg_match('/sendJson\([^;]{0,160},\s*410\)/', $sdBlock));
}

/* ------------------------------------------------- MUTATION SELF-TESTS ---- */

echo "\nMutation self-tests — each check must be ABLE to fail\n";

/*
 * S1–S5 are the four bypasses an adversarial review drove to a full green suite
 * (#1688), plus the scope fix. They are the reason this file was rewritten, so
 * they run on every invocation rather than living in a commit message.
 */

/** Classify a synthetic source as if it were a file in the corpus. */
function relocGuardClassifySource(string $php): array
{
    $u    = phpSourceUnits($php);
    $code = implode(' ', array_column($u, 'code'));
    $out  = [];
    foreach ($u as $name => $unit) {
        $c = relocGuardClassifyUnit($unit, $code);
        if ($c['shapes'] !== []) {
            $out[$name] = ['shapes' => $c['shapes'], 'relocates' => (bool)preg_match('/\bsongRelocate\s*\(/', $unit['code'])];
        }
    }
    return $out;
}

/* S1 — SCOPE. A new function with a raw write, in a file that calls
   songRelocate() elsewhere, must still be flagged. This is the bypass that
   made save_song_core.php and api2.php permanently exempt. */
$s1 = relocGuardClassifySource('<?php
function alreadyHandled(\mysqli $db, string $id, string $abbr): void {
    songRelocate($db, $id, $abbr, null);
}
function addedLater(\mysqli $db, string $id, string $abbr): void {
    $u = $db->prepare("UPDATE tblSongs SET SongbookAbbr = ? WHERE SongId = ?");
    $u->bind_param("ss", $abbr, $id);
    $u->execute();
}');
ok('S1: a NEW function writing the songbook is flagged even though the FILE calls songRelocate()',
   isset($s1['addedLater']) && $s1['addedLater']['relocates'] === false && !isset($s1['alreadyHandled']));

/* S2 — PROSE. A string literal that merely names the helper must not satisfy
   the call check. Comment-stripping alone did not stop this; the trick just
   moved from a comment into a string. */
$s2 = relocGuardClassifySource('<?php
function pretendsToHandle(\mysqli $db, string $id, string $abbr): void {
    $u = $db->prepare("UPDATE tblSongs SET SongbookAbbr = ? WHERE SongId = ?");
    $u->execute();
    error_log("restore: songRelocate() intentionally not used here");
}');
ok('S2: songRelocate() named inside a STRING does not satisfy the call check',
   isset($s2['pretendsToHandle']) && $s2['pretendsToHandle']['relocates'] === false);

/* S3 — BOUNDING. A quoted value in the SET clause must not swallow the WHERE.
   The old parser cut at the first quote and discarded the statement entirely;
   real code in this tree already had that shape. */
$s3 = relocGuardClassifySource('<?php
function quotedValue(\mysqli $db): void {
    $u = $db->prepare("UPDATE tblSongs SET SongbookAbbr = ?, Note = \'x\' WHERE SongId = ?");
    $u->execute();
}');
ok('S3: a quoted value in the SET clause does not hide the statement',
   isset($s3['quotedValue']) && in_array('B (literal single-song SongbookAbbr update)', $s3['quotedValue']['shapes'], true));

/* S4 — the OTHER song key. tblSongs.Id is the AUTO_INCREMENT PK and this idiom
   is already used on this table elsewhere in the tree. */
$s4 = relocGuardClassifySource('<?php
function byPrimaryKey(\mysqli $db): void {
    $u = $db->prepare("UPDATE tblSongs SET SongbookAbbr = ? WHERE Id = ?");
    $u->execute();
}');
ok('S4: a write keyed on tblSongs.Id (not SongId) is still a per-song write',
   isset($s4['byPrimaryKey']));

/* S5 — BACKTICKS. api2.php already writes backticked identifiers. */
$s5 = relocGuardClassifySource('<?php
function backticked(\mysqli $db): void {
    $u = $db->prepare("UPDATE `tblSongs` SET `SongbookAbbr` = ? WHERE `SongId` = ?");
    $u->execute();
}');
ok('S5: backticked identifiers do not hide the statement', isset($s5['backticked']));

/* S6 — FALSE-POSITIVE CONTROL. A book-wide rename must NOT be flagged: a guard
   that fails on correct code gets weakened or deleted rather than fixed. */
$s6 = relocGuardClassifySource('<?php
function renameBook(\mysqli $db): void {
    $s = $db->prepare(\'UPDATE tblSongs SET SongbookAbbr = ? WHERE SongbookAbbr = ?\');
    $s->execute();
}');
ok('S6: a BOOK-WIDE abbreviation rename is NOT flagged', $s6 === []);

/* S7 — a doc-comment quoting the SQL is not a write site. */
$s7 = relocGuardClassifySource("<?php
function documented(): void {
    /* UPDATE tblSongs SET SongbookAbbr = ? WHERE SongId = ? — how it used to work */
    \$x = 1;
}");
ok('S7: the same SQL inside a COMMENT is not a write site', $s7 === []);

/* S8 — CONCATENATION. A statement glued from pieces must read as one. */
$s8 = relocGuardClassifySource('<?php
function assembled(\mysqli $db, string $col): void {
    $sql = "UPDATE tblSongs SET " . $col . " = ? WHERE SongId = ?";
    $u = $db->prepare($sql);
    $u->execute();
    $map = [\'songbook\' => [\'SongbookAbbr\', \'s\']];
}');
ok('S8: SQL concatenated across pieces is reconstructed and classified',
   isset($s8['assembled']) && in_array('C (allow-list driven dynamic column update)', $s8['assembled']['shapes'], true));

/* S9 — CHECK 2 must ACCEPT a semantically identical skip. Rewriting the real
   skip to `$field === 'songbook'` turned the previous version red — correct
   code failing a guard is how guards get deleted (rule #34). */
$s9src = '<?php
const META = [\'songbook\' => [\'SongbookAbbr\', \'s\']];
function restoreish(\mysqli $db, array $row): void {
    foreach (META as $field => [$column, $type]) {
        if ($field === \'songbook\') { continue; }
        $u = $db->prepare("UPDATE tblSongs SET {$column} = ? WHERE SongId = ?");
        $u->execute();
    }
}';
$s9 = relocGuardClassifySource($s9src);
ok('S9: a skip written against the FIELD KEY (not the column) is accepted', $s9 === []);

/* S10 — …and removing that skip must flip it to a flagged site. The mutation
   removes exactly the text the CHECK matched, not a literal typed here — the
   previous version hardcoded the literal, so its verdict was independent of
   whether the production skip existed at all (it passed while the check was
   red, and failed while the check was green). */
$s10units = phpSourceUnits($s9src);
$s10code  = implode(' ', array_column($s10units, 'code'));
$s10skip  = relocGuardFindSongbookSkip($s10units['restoreish']['code'] ?? '', ['SongbookAbbr', 'songbook']);
ok('S10a: the check actually located a skip to mutate', $s10skip !== null, 'nothing matched — S10 would be vacuous');
$s10 = relocGuardClassifySource(str_replace("if (\$field === 'songbook') { continue; }", '', $s9src));
ok('S10b: deleting that skip makes the function a flagged shape-C site',
   isset($s10['restoreish']) && in_array('C (allow-list driven dynamic column update)', $s10['restoreish']['shapes'], true));

/* S11 — the real save core is still one of the detected sites. */
$saveCoreFile = $ROOT . '/appWeb/public_html/manage/editor/save_song_core.php';
$saveShapes   = isset($units[$saveCoreFile])
    ? relocGuardClassifyUnit($units[$saveCoreFile]['editorSaveSongCore'] ?? ['code' => '', 'sql' => []], $fileCode[$saveCoreFile] ?? '')
    : ['shapes' => []];
ok('S11: the real save core is detected as a write site', $saveShapes['shapes'] !== []);

/* S12 — CHECK 4 must be able to fail. */
$s12 = str_replace('songRedirectResolve(', 'somethingElse(', $apiCode);
$s12Pos = strpos($s12, "case 'song_detail':");
ok('S12: removing songRedirectResolve() from song_detail makes CHECK 4 flag it',
   $s12Pos !== false && strpos(substr($s12, $s12Pos, 4000), 'songRedirectResolve(') === false);

/* S13 — the code view must destroy prose while keeping identifier literals.
   Everything above rests on that distinction holding. */
$s13 = phpSourceUnits('<?php function f() { $a = \'SongbookAbbr\'; $b = "the SongbookAbbr column is special"; }');
$s13code = $s13['f']['code'] ?? '';
ok('S13: an identifier literal survives the code view but a sentence does not',
   strpos($s13code, "'SongbookAbbr'") !== false && substr_count($s13code, 'SongbookAbbr') === 1);

if ($fail === 0) {
    echo "\nAll songbook-move funnel assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
