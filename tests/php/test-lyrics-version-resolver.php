<?php

declare(strict_types=1);

/**
 * iHymns — every "which lyrics version?" read agrees with the ONE resolver (#2076)
 * ==================================================================================
 *
 * ELI5
 * ----
 * A song can have more than one saved copy of its words — the one typed straight
 * into iHymns (`Source = 'ihymns'`), and sometimes an imported one too (TTML,
 * OpenLyrics, …). Two different pieces of code used to answer "which copy is
 * THE copy for this song?" in two different ways, and disagreed on a song that
 * had both kinds. Nothing crashed — the two answers were just quietly about
 * different rows, so line numbers from one never matched enrichment keyed to
 * the other. This test walks the whole app, finds every place that decides
 * which `tblLyrics` row is "the" one for a song, and fails the build if a new
 * one picks differently without saying so on purpose.
 *
 * THE BUG THIS GUARDS (#2076)
 * ---------------------------
 * Every line reader (`includes/lyric_lines_read.php`) keys its `tblLyrics` JOIN
 * on `Source = 'ihymns'` — never `IsPrimary` (#1235 R6/PF3). Before this fix,
 * `SongData::_primaryLyricsId()` used a DIFFERENT rule
 * (`Status = 'approved' ORDER BY IsPrimary DESC`) to decide which version's
 * enrichment to serve for `?include=vocalParts|translations|annotations`. On a
 * song with BOTH an `ihymns` row and an approved TTML row flagged
 * `IsPrimary = 1`, the enrichment blocks were read against the TTML version
 * while every `lineId` in the component payload came from the `ihymns`
 * version — two arrays that never shared an id, so a client could never
 * anchor a translation or annotation to a line. Silent, no error anywhere.
 *
 * THE FIX
 * -------
 * `lyricLinesPrimaryLyricsId(\mysqli $db, string $songId): int` in
 * `lyric_lines_read.php` is now the ONE place that decision gets made.
 * `SongData::_primaryLyricsId()` calls it first and only falls back to its
 * old rule for a song that has no `ihymns` row at all (a TTML-only import).
 *
 * WHAT THIS GUARD DERIVES FROM THE TREE (rule #34 — never a typed file list)
 * ---------------------------------------------------------------------
 * A "site" is any FUNCTION-SCOPED unit (see `lib/php_source_units.php`) whose
 * body contains a literal SQL `SELECT` statement that:
 *   (a) references `tblLyrics` (FROM or JOIN — word-bounded, so it does not
 *       false-match `tblLyricLines` / `tblLyricLineTranslations` / …), AND
 *   (b) filters on `SongId` (`SongId = ?`, `SongId IN (…)`, or a correlated
 *       `x.SongId = y.SongId`).
 * Every site found this way must EITHER:
 *   - call `lyricLinesPrimaryLyricsId(` somewhere in its own body (the CODE
 *     view, so a mention inside a string/comment does not count), OR
 *   - carry a comment, INSIDE ITS OWN BODY, containing the marker
 *     `@lyrics-version-exempt:` followed by a reason.
 * Scoped to `SELECT` only (not INSERT/UPDATE/DELETE): this guard is about
 * READING "which version is current", not every write that happens to touch
 * `tblLyrics` by SongId (an upsert-by-arbitrary-Source, a demote-old-primary
 * UPDATE, … answer a different question and are not version RESOLVERS).
 *
 * THE TWO GOTCHAS THIS GUARD IS BUILT AROUND (verified against
 * `lib/php_source_units.php`'s own header comment before writing a line here)
 * ------------------------------------------------------------------------
 *  1. A function's SIGNATURE and any doc-comment immediately above it belong
 *     to the ENCLOSING scope ("(file scope)" for a top-level function), not
 *     to the function's own unit — only the BODY (`{` … `}`) is the unit.
 *     So an `@lyrics-version-exempt:` note written as a doc-block ABOVE a
 *     function does NOT exempt that function; it must sit INSIDE the body.
 *     Self-test D below proves the guard actually enforces this the hard way
 *     (by trying the wrong placement and confirming it still fails).
 *  2. `code` erases comments and turns every non-identifier string literal
 *     into an opaque atom, so `error_log('lyricLinesPrimaryLyricsId() not
 *     needed here')` does NOT count as a call — self-test H proves this.
 *
 * THE FLOOR (#1701 — a scanner that finds nothing must not print green)
 * -----------------------------------------------------------------
 * If the derivation logic breaks (a regex typo, a wrong view), the honest
 * failure mode is "found zero sites, therefore vacuously all pass". Check 0
 * below asserts a minimum site count, so a broken scanner is loud, not quiet.
 *
 * WHAT THIS CANNOT CATCH (so its tick is not over-read)
 * ------------------------------------------------------
 *  - A resolver written in a different language (native apps do not query
 *    tblLyrics directly).
 *  - A helper THREE calls away that itself disagrees (this is not a full
 *    data-flow analysis — see the library's own stated limits).
 *  - Whether an `@lyrics-version-exempt:` reason is actually TRUE — only a
 *    human review at merge time can judge that; the guard only proves one
 *    was written down, so a future reviewer has something to check.
 *
 * CHECKS 3-5 — THE READ/WRITE ERROR-POLICY SPLIT (a regression, caught by an
 * independent review before this resolver's write-side use ever shipped)
 * ---------------------------------------------------------------------
 * `lyricLinesPrimaryLyricsId()` briefly wrapped its own SELECT in a
 * try/catch that degraded EVERY failure — including a genuine deadlock —
 * into a plain 0, indistinguishable from "this song has no ihymns row yet".
 * That is a fine degrade for a READ, but `lyricLinesEnsurePrimaryVersion()`
 * (a find-OR-CREATE running inside the caller's own transaction) used the
 * swallowed 0 to justify an INSERT that should never have happened —
 * risking a duplicate lyrics version and a save that reports success over a
 * partially-rolled-back transaction. The fix moved the try/catch OUT of the
 * resolver and onto each READ call site, and gave the resolver a
 * `$useCache` flag so a find-or-create can bypass its per-connection memo
 * (a cached "found" answer inside an open transaction can be undone by a
 * later ROLLBACK, going stale for the rest of the request). CHECKS 3-5
 * assert this split mechanically, tree-derived (never a typed caller list):
 *   - CHECK 3: the resolver's OWN unit contains no `catch` at all — a
 *     `\Throwable` there can ONLY mean "swallow this back into 0" again.
 *   - CHECK 4: every real CODE call site of the resolver, across the whole
 *     corpus, declares its cache policy — either an explicit literal
 *     `false` argument (a write/find-or-create that must always see live
 *     state), or an in-body `@lyrics-version-cache-ok:` marker explaining
 *     why a cached answer is safe there (mirrors the `@lyrics-version-exempt:`
 *     convention above — the SAME "sits inside the body, not the doc-block
 *     above it" gotcha applies, and self-tests below prove it the same way
 *     self-test D does for CHECK 1).
 *   - CHECK 5: the two known real write-path callers
 *     (`lyricLinesEnsurePrimaryVersion()`, the `duplicate_song` case) are
 *     specifically confirmed present and correctly wired — mirrors CHECK 2's
 *     "proves the derivation reaches production code" role.
 *
 *   php tests/php/test-lyrics-version-resolver.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/lyric_lines_read.php   lyricLinesPrimaryLyricsId()
 * @see appWeb/public_html/includes/SongData.php            _primaryLyricsId()
 * @see CLAUDE.md rule #25                                  the ONE lyric-line read/write path discipline this extends
 */

require_once __DIR__ . '/lib/php_source_units.php';

$ROOT = dirname(__DIR__, 2);
$SCAN_ROOT = $ROOT . '/appWeb/public_html';

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

/** Plain-PHP recursive walk of appWeb/public_html. No shell-out. */
function lvrPhpFiles(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $files[] = $f->getPathname(); }
    }
    sort($files);
    return $files;
}

/**
 * Is this literal SQL statement a version-resolving SELECT — i.e. does it
 * read `tblLyrics` (word-bounded — never `tblLyricLines`/`tblLyricLine…`)
 * AND filter on `SongId` (a placeholder, an IN() list, or a correlated
 * column-to-column comparison)?
 */
function lvrIsQualifyingSelect(string $rawSql): bool
{
    if (!preg_match('/^\s*SELECT\b/i', $rawSql)) {
        return false;   // scoped to reads — see file header for why
    }
    $sql = phpUnitsNormaliseSql($rawSql);
    if (!preg_match('/\btblLyrics\b/i', $sql)) {
        return false;
    }
    if (!preg_match('/\bSongId\s*(=|IN\s*\()/i', $sql)) {
        return false;
    }
    return true;
}

/**
 * Does this unit satisfy the guard: a real CODE call to the shared resolver,
 * or an in-body `@lyrics-version-exempt:` marker?
 *
 * @param array{code:string, comments:list<string>} $unit
 */
function lvrUnitSatisfies(array $unit): bool
{
    if (preg_match('/\blyricLinesPrimaryLyricsId\s*\(/', $unit['code'])) {
        return true;
    }
    foreach ($unit['comments'] as $c) {
        if (stripos($c, '@lyrics-version-exempt:') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Scan one already-parsed unit map (file => name => unit) for qualifying
 * sites, returning `"rel/path.php :: unitName" => [matched SQL, ...]`.
 *
 * @param array<string,array<string,array{code:string,strings:list<string>,sqlOnly:list<string>,comments:list<string>}>> $unitsByFile
 * @return array<string,list<string>>
 */
function lvrFindSites(array $unitsByFile): array
{
    $sites = [];
    foreach ($unitsByFile as $file => $units) {
        foreach ($units as $name => $unit) {
            $matched = [];
            foreach ($unit['sqlOnly'] as $sql) {
                if (lvrIsQualifyingSelect($sql)) {
                    $matched[] = $sql;
                }
            }
            if ($matched !== []) {
                $sites["$file :: $name"] = $matched;
            }
        }
    }
    return $sites;
}

/* ----------------------------------- CHECKS 3-5 helpers (error-policy split) -- */

/**
 * Every raw argument-list substring for calls to `lyricLinesPrimaryLyricsId(`
 * inside a unit's stripped `code` view — a small bracket-matching walk, not a
 * single regex, because an argument COULD itself contain parens (a nested
 * call). A fragile fixed-width regex is the exact class of under-reporting
 * rule #34 warns against: it would silently mis-scope a real future call
 * whose arguments happen to be more complex than today's three plain ones.
 *
 * EXCLUDES the function's own DECLARATION. `php_source_units.php`'s own
 * header states a function's SIGNATURE belongs to the ENCLOSING scope, not
 * the function's own body-unit — so `function lyricLinesPrimaryLyricsId(...)`
 * literally appears as source text inside `lyric_lines_read.php`'s
 * `(file scope)` unit, and a naive scan there would misreport the
 * DEFINITION as a caller of itself (found live: the first run of this
 * function did exactly that, failing CHECK 4 on a phantom "(file scope)"
 * caller). A real call is never immediately preceded by the `function`
 * keyword; a declaration always is.
 *
 * @return list<string>  one entry per call occurrence found in the unit
 */
function lvrCallArgLists(string $code, string $fnName = 'lyricLinesPrimaryLyricsId'): array
{
    $out    = [];
    $needle = $fnName . '(';
    $pos    = 0;
    $len    = strlen($code);
    while (($start = strpos($code, $needle, $pos)) !== false) {
        $i        = $start + strlen($needle);
        $depth    = 1;
        $argStart = $i;
        while ($i < $len && $depth > 0) {
            if ($code[$i] === '(') { $depth++; }
            elseif ($code[$i] === ')') { $depth--; }
            $i++;
        }
        $lookback = max(0, $start - 12);
        $before   = rtrim(substr($code, $lookback, $start - $lookback));
        if (!preg_match('/\bfunction$/', $before)) {
            $out[] = substr($code, $argStart, max(0, $i - 1 - $argStart));
        }
        $pos = $i;
    }
    return $out;
}

/** Does this call's own argument-list text pass a literal `false` for
 *  $useCache (the resolver's 3rd parameter — positional `false`, or a named
 *  `useCache: false`)? Scoped to just the extracted argument substring (never
 *  the whole unit) so an unrelated "false" elsewhere in the same function
 *  can't produce a false pass. */
function lvrCallPassesUseCacheFalse(string $argList): bool
{
    return (bool)preg_match('/\bfalse\b/i', $argList);
}

/**
 * Does this call site satisfy the CHECK 4 policy: an explicit `false`
 * argument, OR an in-THIS-UNIT'S-BODY `@lyrics-version-cache-ok:` marker?
 * Mirrors `lvrUnitSatisfies()`'s two-mechanism shape exactly (call vs.
 * in-body marker) — including the SAME gotcha: a marker in a doc-block
 * ABOVE the function is attributed to the enclosing unit, not this one (see
 * the file header's "THE TWO GOTCHAS" — self-tests below prove this the
 * same way self-test D proves it for `@lyrics-version-exempt:`).
 */
function lvrCallSiteDeclaresPolicy(array $unit, string $argList): bool
{
    if (lvrCallPassesUseCacheFalse($argList)) {
        return true;
    }
    foreach ($unit['comments'] as $c) {
        if (stripos($c, '@lyrics-version-cache-ok:') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Find every real CODE call site of `lyricLinesPrimaryLyricsId(...)` across
 * an already-parsed unit map — tree-derived (rule #34), not a typed caller
 * list — returning `"rel/path.php :: unitName" => [raw arg-list, ...]` (one
 * entry per call occurrence in that unit). The resolver's OWN definition
 * unit is naturally excluded: its body never calls itself.
 *
 * @param array<string,array<string,array{code:string,strings:list<string>,sqlOnly:list<string>,comments:list<string>}>> $unitsByFile
 * @return array<string,list<string>>
 */
function lvrFindCallerSites(array $unitsByFile): array
{
    $out = [];
    foreach ($unitsByFile as $file => $units) {
        foreach ($units as $name => $unit) {
            $args = lvrCallArgLists($unit['code']);
            if ($args !== []) {
                $out["$file :: $name"] = $args;
            }
        }
    }
    return $out;
}

/* ------------------------------------------------------- corpus + derive -- */

if (!is_dir($SCAN_ROOT)) {
    fwrite(STDERR, "FATAL: $SCAN_ROOT not found\n");
    exit(1);
}

$files = lvrPhpFiles($SCAN_ROOT);
ok('corpus walk found PHP files under appWeb/public_html', count($files) > 100,
   'found ' . count($files) . ' — a collapse here would make every check below vacuously green');

$unitsByFile = [];
foreach ($files as $f) {
    $unitsByFile[$f] = phpSourceUnits((string)file_get_contents($f));
}

$sites = lvrFindSites($unitsByFile);

echo "\nCHECK 0 — the scanner is actually finding sites (#1701: zero-findings must not print green)\n";
ok('at least 10 version-resolving SELECT sites were found', count($sites) >= 10,
   'found ' . count($sites) . ' — if this drops to 0 the scanner itself is broken, not the codebase');

echo "\nCHECK 1 — every version-resolving SELECT site calls lyricLinesPrimaryLyricsId(" .
     ") or records an exemption, IN ITS OWN FUNCTION BODY\n";

$missing = [];
foreach ($sites as $where => $matchedSql) {
    [$file] = explode(' :: ', $where, 2);
    $name = substr($where, strlen($file) + 4);
    $unit = $unitsByFile[$file][$name];
    $rel  = str_replace($ROOT . '/', '', $file);
    echo "  · site: {$rel} :: {$name}\n";
    if (!lvrUnitSatisfies($unit)) {
        $missing[] = "{$rel} :: {$name}";
    }
}
ok('every site calls the shared resolver or carries @lyrics-version-exempt: (' . count($sites) . ' site(s) found)',
   $missing === [],
   $missing === [] ? '' : "sites that neither call lyricLinesPrimaryLyricsId() nor explain why not:\n  - "
       . implode("\n  - ", $missing)
       . "\nFix: call lyricLinesPrimaryLyricsId(\$db, \$songId) from includes/lyric_lines_read.php,"
       . " or add an inline comment '@lyrics-version-exempt: <reason>' inside the function body.");

/* --------------------------------------------- CHECK 2 — known real sites -- */

echo "\nCHECK 2 — specific real sites are wired correctly (proves the derivation reaches production code)\n";

$songDataFile   = $ROOT . '/appWeb/public_html/includes/SongData.php';
$readerFile     = $ROOT . '/appWeb/public_html/includes/lyric_lines_read.php';
$songDataSite   = "$songDataFile :: _primaryLyricsId";
$resolverSite   = "$readerFile :: lyricLinesPrimaryLyricsId";

ok("SongData::_primaryLyricsId() is detected as a version-resolving site",
   isset($sites[$songDataSite]));
ok("SongData::_primaryLyricsId() satisfies the guard by CALLING the shared resolver",
   isset($unitsByFile[$songDataFile]['_primaryLyricsId'])
   && (bool)preg_match('/\blyricLinesPrimaryLyricsId\s*\(/', $unitsByFile[$songDataFile]['_primaryLyricsId']['code']));

ok("lyricLinesPrimaryLyricsId() itself is detected as a version-resolving site (it IS the definition)",
   isset($sites[$resolverSite]));
ok("lyricLinesPrimaryLyricsId() satisfies the guard via its own @lyrics-version-exempt: note",
   isset($unitsByFile[$readerFile]['lyricLinesPrimaryLyricsId'])
   && lvrUnitSatisfies($unitsByFile[$readerFile]['lyricLinesPrimaryLyricsId']));

/* --------------------------- CHECK 3 — the resolver never swallows a DB failure -- */

echo "\nCHECK 3 — lyricLinesPrimaryLyricsId() itself contains no catch that could swallow a DB failure into 0\n";

$resolverUnit = $unitsByFile[$readerFile]['lyricLinesPrimaryLyricsId'] ?? null;
ok('lyricLinesPrimaryLyricsId() is present in the corpus to check', $resolverUnit !== null);
if ($resolverUnit !== null) {
    $catchCount = phpUnitsCountToken($resolverUnit['code'], T_CATCH);
    ok('lyricLinesPrimaryLyricsId() contains ZERO catch clauses (a genuine DB failure must PROPAGATE — degrading it to 0 is each caller\'s decision, never the resolver\'s; see the resolver\'s own ERROR POLICY doc-block)',
       $catchCount === 0,
       "found {$catchCount} catch clause(s) inside the resolver's own body — this is exactly the #2076-regression"
       . " this check exists to catch (a resolver that swallows a deadlock into 0 lets a find-or-create caller"
       . " believe 'not found' and INSERT a duplicate row over a dead transaction):\n" . $resolverUnit['code']);
}

/* ------------- CHECK 4 — every caller declares its cache policy -- */

echo "\nCHECK 4 — every real caller of lyricLinesPrimaryLyricsId() declares its cache policy: an explicit `false`"
   . " argument (write/find-or-create — must always see live state) or a @lyrics-version-cache-ok: marker (read"
   . " — safe to cache, and why), IN ITS OWN UNIT'S BODY\n";

$callerSites = lvrFindCallerSites($unitsByFile);
unset($callerSites[$resolverSite]);   // the definition never calls itself

$undeclared = [];
foreach ($callerSites as $where => $argLists) {
    [$file] = explode(' :: ', $where, 2);
    $name   = substr($where, strlen($file) + 4);
    $unit   = $unitsByFile[$file][$name];
    $rel    = str_replace($ROOT . '/', '', $file);
    foreach ($argLists as $argList) {
        echo "  · caller: {$rel} :: {$name}({$argList})\n";
        if (!lvrCallSiteDeclaresPolicy($unit, $argList)) {
            $undeclared[] = "{$rel} :: {$name}({$argList})";
        }
    }
}
ok('every caller declares an explicit false or a @lyrics-version-cache-ok: reason (' . count($callerSites) . ' caller site(s) found)',
   $undeclared === [],
   $undeclared === [] ? '' : "callers with no declared cache policy:\n  - " . implode("\n  - ", $undeclared)
       . "\nFix: pass `false` as the 3rd argument for a write/find-or-create running inside a transaction, or add"
       . " an inline comment '@lyrics-version-cache-ok: <reason>' (INSIDE the function body, not its doc-block)"
       . " for a read that is safe to cache.");

/* ------------- CHECK 5 — the two known write-path callers, by name -- */

echo "\nCHECK 5 — the two known write-path callers are wired correctly (proves the derivation reaches production code)\n";

$syncFile     = $ROOT . '/appWeb/public_html/includes/lyric_lines_sync.php';
$api2File     = $ROOT . '/appWeb/public_html/manage/editor/api2.php';
$ensureSite   = "$syncFile :: lyricLinesEnsurePrimaryVersion";
$dupSongSite  = "$api2File :: case 'duplicate_song'";

ok('lyricLinesEnsurePrimaryVersion() (lyric_lines_sync.php) is detected as a caller',
   isset($callerSites[$ensureSite]));
ok('lyricLinesEnsurePrimaryVersion() passes an explicit false — a find-or-create inside the caller\'s transaction must always see live state (never a memo a rollback could invalidate)',
   isset($callerSites[$ensureSite])
   && array_reduce($callerSites[$ensureSite], static fn($carry, $a) => $carry || lvrCallPassesUseCacheFalse($a), false));

ok("the duplicate_song case (manage/editor/api2.php) is detected as a caller",
   isset($callerSites[$dupSongSite]));
ok('the duplicate_song case declares its cache policy via an in-body @lyrics-version-cache-ok: marker (it keeps the default cache, but $newId is a freshly-minted, never-reused SongId — see the marker for the full reasoning)',
   isset($callerSites[$dupSongSite])
   && isset($unitsByFile[$api2File]["case 'duplicate_song'"])
   && lvrCallSiteDeclaresPolicy($unitsByFile[$api2File]["case 'duplicate_song'"], $callerSites[$dupSongSite][0] ?? ''));

/* ------------------------------------------------- MUTATION SELF-TESTS ---- */

echo "\nMutation self-tests — each check must be ABLE to fail\n";

/** Classify a synthetic source exactly like the real corpus scan does. */
function lvrClassifySource(string $php): array
{
    $units = phpSourceUnits($php);
    $sites = lvrFindSites(['<synthetic>' => $units]);
    $out   = [];
    foreach ($sites as $where => $sql) {
        $name = substr($where, strlen('<synthetic> :: '));
        $out[$name] = lvrUnitSatisfies($units[$name]);
    }
    return $out;   // unitName => satisfies?
}

/* A — a brand-new inlined query with NO call and NO exemption must be
   flagged as a failing site. This is the canary: if this ever stops being
   flagged, the whole guard is vacuous. */
$a = lvrClassifySource('<?php
function newSiteNoExemption(\mysqli $db, string $songId): int {
    $stmt = $db->prepare("SELECT Id FROM tblLyrics WHERE SongId = ? AND IsPrimary = 1 LIMIT 1");
    $stmt->execute();
    return 0;
}');
ok('A: an inlined query with no call and no exemption is found AND fails',
   isset($a['newSiteNoExemption']) && $a['newSiteNoExemption'] === false);

/* B — the same shape, but calling the shared resolver, must pass. */
$b = lvrClassifySource('<?php
function newSiteCalls(\mysqli $db, string $songId): int {
    $x = lyricLinesPrimaryLyricsId($db, $songId);
    $stmt = $db->prepare("SELECT Id FROM tblLyrics WHERE SongId = ? AND IsPrimary = 1 LIMIT 1");
    $stmt->execute();
    return $x;
}');
ok('B: the same site calling lyricLinesPrimaryLyricsId() in its own body passes',
   isset($b['newSiteCalls']) && $b['newSiteCalls'] === true);

/* C — the same shape, exempted with an in-body comment, must pass. */
$c = lvrClassifySource('<?php
function newSiteExempt(\mysqli $db, string $songId): int {
    /* @lyrics-version-exempt: synthetic test reason */
    $stmt = $db->prepare("SELECT Id FROM tblLyrics WHERE SongId = ? AND IsPrimary = 1 LIMIT 1");
    $stmt->execute();
    return 0;
}');
ok('C: the same site with an in-body @lyrics-version-exempt: comment passes',
   isset($c['newSiteExempt']) && $c['newSiteExempt'] === true);

/* D — GOTCHA 1: an exemption written as a doc-block ABOVE the function
   (i.e. attributed to file scope, not the function's own body) must NOT
   exempt the function. If this ever starts passing, the guard has stopped
   checking per-function and gone back to per-file — the #1688-class bug
   this whole design avoids. */
$d = lvrClassifySource('<?php
/**
 * @lyrics-version-exempt: this comment sits ABOVE the function, not inside it
 */
function newSiteWronglyPlacedExemption(\mysqli $db, string $songId): int {
    $stmt = $db->prepare("SELECT Id FROM tblLyrics WHERE SongId = ? AND IsPrimary = 1 LIMIT 1");
    $stmt->execute();
    return 0;
}');
ok('D: an exemption comment ABOVE the function (file scope, not body) does NOT satisfy it',
   isset($d['newSiteWronglyPlacedExemption']) && $d['newSiteWronglyPlacedExemption'] === false);

/* E — FALSE-POSITIVE CONTROL: tblLyricLines must never be mistaken for
   tblLyrics (word-boundary check). A guard that fires on correct code gets
   weakened or deleted rather than fixed (rule #34). */
$e = lvrClassifySource('<?php
function readsLinesOnly(\mysqli $db, string $songId): array {
    $stmt = $db->prepare("SELECT Id FROM tblLyricLines WHERE SongId = ? AND LanguageCode = ?");
    $stmt->execute();
    return [];
}');
ok('E: a query against tblLyricLines (not tblLyrics) is NOT flagged at all',
   !isset($e['readsLinesOnly']));

/* F — FALSE-POSITIVE CONTROL: a tblLyrics query that does not filter by
   SongId at all (e.g. by primary key) is not a version-resolution site. */
$f = lvrClassifySource('<?php
function byOwnId(\mysqli $db, int $id): array {
    $stmt = $db->prepare("SELECT Id, Status FROM tblLyrics WHERE Id = ?");
    $stmt->execute();
    return [];
}');
ok('F: a tblLyrics query with no SongId filter is NOT flagged',
   !isset($f['byOwnId']));

/* G — SCOPE CONTROL: an UPDATE against tblLyrics filtered by SongId (e.g. a
   demote-old-primary write) is a MUTATION, not a version READ, and must not
   be flagged — see the file header for why this guard is SELECT-only. */
$g = lvrClassifySource('<?php
function demoteOthers(\mysqli $db, string $songId, int $keepId): void {
    $stmt = $db->prepare("UPDATE tblLyrics SET IsPrimary = 0 WHERE SongId = ? AND Id <> ?");
    $stmt->execute();
}');
ok('G: an UPDATE (not a SELECT) against tblLyrics by SongId is NOT flagged',
   !isset($g['demoteOthers']));

/* H — GOTCHA 2: the call check must read CODE, not prose. A string that
   merely NAMES the function must not satisfy it — mirrors the #1688/A5
   lesson (a bypass that "fixed" a guard by relocating the trick into a
   string literal, not by doing the actual work). */
$h = lvrClassifySource('<?php
function pretendsToCall(\mysqli $db, string $songId): int {
    $stmt = $db->prepare("SELECT Id FROM tblLyrics WHERE SongId = ? AND IsPrimary = 1 LIMIT 1");
    $stmt->execute();
    error_log("lyricLinesPrimaryLyricsId() intentionally not used here");
    return 0;
}');
ok('H: naming the resolver inside a STRING (not real code) does not satisfy the call check',
   isset($h['pretendsToCall']) && $h['pretendsToCall'] === false);

/* I — a correlated column-to-column SongId filter (no `?`) is still caught —
   this is the migration-registry.php probe shape (`ly2.SongId = sc.SongId`). */
$i = lvrClassifySource('<?php
function correlated(\mysqli $db): bool {
    $r = $db->query("SELECT 1 FROM tblSongs s WHERE EXISTS (SELECT 1 FROM tblLyrics l WHERE l.SongId = s.SongId)");
    return (bool)$r;
}');
ok('I: a correlated SongId = SongId filter (no placeholder) is still flagged',
   isset($i['correlated']) && $i['correlated'] === false);

/* --------------------------------------- CHECK 3/4 MUTATION SELF-TESTS ---- */

/** Classify a synthetic RESOLVER-shaped function for CHECK 3 (no swallowing
 *  catch). Returns null if the named unit was not even found (a broken test). */
function lvrClassifyResolverNoCatch(string $php, string $fnName): ?bool
{
    $units = phpSourceUnits($php);
    if (!isset($units[$fnName])) {
        return null;
    }
    return phpUnitsCountToken($units[$fnName]['code'], T_CATCH) === 0;
}

/** Classify every real call site inside a synthetic source for CHECK 4
 *  (declares an explicit false, or an in-body @lyrics-version-cache-ok:
 *  marker). unitName => declaresPolicy?, for every unit that calls the
 *  resolver at all. */
function lvrClassifyCallerPolicy(string $php): array
{
    $units = phpSourceUnits($php);
    $out   = [];
    foreach ($units as $name => $unit) {
        $args = lvrCallArgLists($unit['code']);
        if ($args === []) {
            continue;
        }
        $declared = true;
        foreach ($args as $a) {
            if (!lvrCallSiteDeclaresPolicy($unit, $a)) {
                $declared = false;
                break;
            }
        }
        $out[$name] = $declared;
    }
    return $out;
}

/* J — CANARY for CHECK 3: a synthetic resolver whose body swallows a
   \Throwable back into 0 (the literal shape of the regression) must be
   flagged as UNSAFE. If this ever stops being flagged, CHECK 3 is vacuous. */
$j = lvrClassifyResolverNoCatch('<?php
function fakeResolverSwallows(\mysqli $db, string $songId): int {
    try {
        $stmt = $db->prepare("SELECT Id FROM tblLyrics WHERE SongId = ? LIMIT 1");
        $stmt->execute();
        $id = 0;
    } catch (\Throwable $e) {
        $id = 0;
    }
    return $id;
}', 'fakeResolverSwallows');
ok('J: a synthetic resolver with a swallowing try/catch is flagged UNSAFE (canary for CHECK 3)',
   $j === false);

/* K — the same shape with NO catch at all must pass. */
$k = lvrClassifyResolverNoCatch('<?php
function fakeResolverClean(\mysqli $db, string $songId): int {
    $stmt = $db->prepare("SELECT Id FROM tblLyrics WHERE SongId = ? LIMIT 1");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return $row !== null ? (int)$row[0] : 0;
}', 'fakeResolverClean');
ok('K: a synthetic resolver with no catch at all passes CHECK 3',
   $k === true);

/* L — CANARY for CHECK 4: a caller with neither an explicit false nor a
   cache-ok marker must be flagged UNDECLARED. If this ever stops being
   flagged, CHECK 4 is vacuous. */
$l = lvrClassifyCallerPolicy('<?php
function undeclaredCaller(\mysqli $db, string $songId): int {
    return lyricLinesPrimaryLyricsId($db, $songId);
}');
ok('L: a caller with no explicit false and no cache-ok marker is flagged UNDECLARED (canary for CHECK 4)',
   isset($l['undeclaredCaller']) && $l['undeclaredCaller'] === false);

/* M — a caller passing an explicit false (the find-or-create shape) passes. */
$m = lvrClassifyCallerPolicy('<?php
function findOrCreateCaller(\mysqli $db, string $songId): int {
    return lyricLinesPrimaryLyricsId($db, $songId, false);
}');
ok('M: a caller passing an explicit false declares its policy and passes CHECK 4',
   isset($m['findOrCreateCaller']) && $m['findOrCreateCaller'] === true);

/* N — GOTCHA (mirrors self-test D): a caller with an in-BODY
   @lyrics-version-cache-ok: marker passes; the SAME marker written as a
   doc-block ABOVE the function (enclosing scope, not this unit's body) must
   NOT satisfy it. */
$n = lvrClassifyCallerPolicy('<?php
function readCallerWithMarker(\mysqli $db, string $songId): int {
    /* @lyrics-version-cache-ok: a plain read outside any transaction */
    return lyricLinesPrimaryLyricsId($db, $songId);
}');
ok('N: a caller with an in-body @lyrics-version-cache-ok: marker declares its policy and passes CHECK 4',
   isset($n['readCallerWithMarker']) && $n['readCallerWithMarker'] === true);

$nWrong = lvrClassifyCallerPolicy('<?php
/**
 * @lyrics-version-cache-ok: this comment sits ABOVE the function, not inside it
 */
function readCallerWronglyPlacedMarker(\mysqli $db, string $songId): int {
    return lyricLinesPrimaryLyricsId($db, $songId);
}');
ok('N2: a @lyrics-version-cache-ok: marker ABOVE the function (file scope, not body) does NOT satisfy it',
   isset($nWrong['readCallerWronglyPlacedMarker']) && $nWrong['readCallerWronglyPlacedMarker'] === false);

/* O — REGRESSION CONTROL: found LIVE while writing this guard (its first run
   against the real corpus failed CHECK 4 on this exact shape). A synthetic
   source containing the resolver's OWN declaration must NOT be misread as a
   caller of itself — the signature text `function lyricLinesPrimaryLyricsId(`
   lives in the ENCLOSING scope's code, not the function's own body-unit (see
   lvrCallArgLists()'s doc-block). If this ever starts failing again, CHECK 4
   will falsely flag lyric_lines_read.php's own "(file scope)" unit as an
   undeclared caller of the function it defines. */
$o = lvrClassifyCallerPolicy('<?php
function lyricLinesPrimaryLyricsId(\mysqli $db, string $songId, bool $useCache = true): int {
    return 0;
}');
ok("O: a synthetic source containing the resolver's OWN declaration is NOT misread as a caller of itself",
   !isset($o['(file scope)']));

if ($fail === 0) {
    echo "\nAll lyrics-version-resolver assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
