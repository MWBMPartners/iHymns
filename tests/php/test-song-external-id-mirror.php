<?php

declare(strict_types=1);

/**
 * iHymns — ISRC dual-write mirror guard (#1749, epic #1741 P5d)
 *
 * ELI5
 * ----
 * `includes/song_external_ids.php`'s `songExternalIdMirrorIsrc()` is the ONE
 * function that keeps `tblSongs.Isrc` and `tblSongExternalIds` in sync after
 * a curator edits an ISRC. This test proves the plumbing around that promise
 * holds: the helper's SQL literals agree with the central vocabulary AND with
 * the #1747 D5 backfill's own `SourceRef` literal (byte-for-byte, not "looks
 * similar"), the helper's DELETE actually carries the ownership predicate
 * that stops it from eating a curator's manual second-recording ISRC row, and
 * — DERIVED from the tree, not hand-typed — every file that writes
 * `tblSongs.Isrc` either calls the mirror or is named in an explicit,
 * issue-numbered exemption list.
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * ----------------------------------------------------------------------------
 * The write-site sweep scans `appWeb/public_html/**\/*.php` for an actual SQL
 * `INSERT INTO tblSongs (...)`/`UPDATE tblSongs SET Isrc = ...` write — never
 * a hand-typed "these are the writers" list — so a FUTURE file that starts
 * writing `tblSongs.Isrc` without going through the mirror (or without being
 * deliberately, visibly exempted with an issue number) fails THIS test
 * automatically, the same shape `tests/php/test-tune-lockstep.php` (P5c,
 * sibling family) uses for the TuneName↔TuneId lockstep sweep.
 *
 * WHY BYTE-EQUALITY, NOT "LOOKS THE SAME" (rule #35)
 * ----------------------------------------------------------------------------
 * The mirror's ownership model rests entirely on `SourceRef = 'tblSongs.Isrc'`
 * being the EXACT string the #1747 D5 backfill already writes
 * (`migrate-backfill-song-external-ids.php`) — a backfilled row and a
 * live-mirrored row must be the SAME ownership class, or the mirror would
 * either eat backfilled rows it doesn't own or leave orphaned duplicates
 * behind on the very first live edit. "Keep these two strings in sync" is
 * the failure mode rule #35 names, not a fix — this file parses BOTH
 * literals out of their real source files and compares them, so a typo in
 * either one goes red here instead of drifting silently.
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely in
 * memory, never touching the tree): each core checking function below is
 * exercised in BOTH directions against small in-memory fixtures. A guard
 * that has never been proven able to fail is not trustworthy.
 *
 * Pure source-tree scan — no DB required — so it slots into the CI lint step
 * alongside `php -l` and the other guards.
 *
 *   php tests/php/test-song-external-id-mirror.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see appWeb/public_html/includes/song_external_ids.php songExternalIdMirrorIsrc(), SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF
 * @see appWeb/public_html/includes/media_identifiers.php SONG_EXTERNAL_ID_SCOPES, RECORDING_EXTERNAL_ID_TYPES
 * @see appWeb/.sql/migrate-backfill-song-external-ids.php the SourceRef='tblSongs.Isrc' literal this mirror reuses
 * @see appWeb/public_html/manage/editor/api2.php metadata_field_update, ed2_applySongSnapshot()
 * @see .claude/catalogue-1741-P5-plan.md §4.5
 */

$repoRoot = dirname(__DIR__, 2);

/* =========================================================================
 * PURE CORE FUNCTIONS — no file I/O, no globals.
 * ========================================================================= */

/**
 * Slice a named top-level PHP `function NAME(...) { ... }` region — from
 * `function NAME(` to the next top-level (column-0) `function `
 * declaration, or end of file.
 */
function seimSliceFunction(string $src, string $name): string
{
    if (!preg_match(
        '/^function\s+' . preg_quote($name, '/') . '\s*\(.*?(?=\n^function\s|\z)/ms',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Slice a `case 'NAME':` region out of a PHP switch statement — from the
 * `case` line to the next top-level `case '` occurrence (or end of file).
 */
function seimSliceCase(string $src, string $caseName): string
{
    if (!preg_match(
        '/case\s+\'' . preg_quote($caseName, '/') . '\'\s*:.*?(?=\n\s*case\s+\')/s',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Strip PHP comments (`//`, `#`, `/* *​/`, doc-block) from source via the
 * TOKENIZER, so a CODE assertion below can never be satisfied by a mention of
 * the symbol/literal inside a comment — the wrong-but-green trap (rule #34).
 *
 * ELI5: turn "the code, comments and all" into "just the code", so a stale
 * comment that still mentions e.g. `'tblSongs.Isrc'` after the real constant
 * was renamed can't keep this guard green.
 *
 * DETAILED / WHY THIS WAS ADDED (#1751): before this, EVERY non-self-test
 * assertion in this file scanned raw `file_get_contents()` output — an
 * adversarial audit proved assertions 2/3/4/5/5b + the write-site "calls the
 * mirror" leg all stayed GREEN when the real code was reverted but a
 * plausible stale comment survived (e.g. a renamed const with a commented-out
 * old declaration). `T_COMMENT`/`T_DOC_COMMENT` tokens are replaced by their
 * own newline count so relative offsets (bounded-window scans) stay faithful.
 * Same tokenizer approach as `tests/php/test-tune-lockstep.php`'s
 * `ttlStripComments()`.
 *
 * @link https://www.php.net/manual/en/function.token-get-all.php
 */
function seimStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/**
 * Extract the single-quoted string literal assigned to a top-level PHP
 * `const NAME = '...';` declaration. Returns null when not found.
 */
function seimExtractConstString(string $src, string $constName): ?string
{
    if (!preg_match(
        '/const\s+' . preg_quote($constName, '/') . '\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*;/s',
        $src,
        $m
    )) {
        return null;
    }
    return stripcslashes($m[1]);
}

/**
 * #1751 — true when $fnSlice's `songExternalIdMirrorIsrc(` signature defaults
 * its `$source` parameter to `'ihymns-mirror'`.
 *
 * ELI5: "does the mirror function still default to the SAME provenance label
 * the two existing api2.php call sites (which pass no 4th argument) have
 * always gotten?" — the #1751 change adds a $source parameter; this proves
 * its default keeps those two call sites' behaviour byte-identical.
 *
 * DETAILED / WHY A NAMED FUNCTION (rule #34 — every regex must be provably
 * able to fail): wrapping the pattern in a function with its own fixture
 * pair below is what makes THIS test's own guard mutation-testable in the
 * same way `seimExtractConstString()` etc. already are, rather than an
 * inline `preg_match()` nobody has ever watched go red.
 */
function seimMirrorDefaultsToIhymnsMirror(string $fnSlice): bool
{
    return preg_match('/function\s+songExternalIdMirrorIsrc\s*\([^)]{0,220}\$source\s*=\s*\'ihymns-mirror\'/', $fnSlice) === 1;
}

/**
 * #1751 — count how many times $src calls
 * `songExternalIdMirrorIsrc(...'ihymns-ingest')` — i.e. passes the ingest
 * provenance literal as (part of) the call's argument list. Bounded window
 * (rule #34 / #1676's "generous bounded window, never to end of line"
 * lesson) rather than a greedy match across the whole file.
 */
function seimCountIngestMirrorCalls(string $src): int
{
    return preg_match_all('/songExternalIdMirrorIsrc\s*\([^;]{0,220}\'ihymns-ingest\'/', $src);
}

/**
 * Extract every SourceRef literal from `migrate-backfill-song-external-ids.php`-
 * shaped INSERT statements of the form `'ihymns-backfill', 'SOME.LITERAL'`
 * that ALSO carry `'isrc'` as the IdType literal in the same SELECT list —
 * i.e. specifically the tblSongs.Isrc-sourced backfill INSERT, not the
 * sibling tblSongIdentityMap-sourced ones (GeniusTrackId, SpotifyTrackId, …)
 * which carry a DIFFERENT SourceRef. Returns the first match or null.
 */
function seimExtractIsrcBackfillSourceRef(string $src): ?string
{
    if (!preg_match(
        "/SELECT\\s+SongId,\\s*'recording',\\s*'isrc',\\s*Isrc,\\s*'ihymns-backfill',\\s*'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'\\s*\n\\s*FROM\\s+tblSongs\\b/s",
        $src,
        $m
    )) {
        return null;
    }
    return stripcslashes($m[1]);
}

/**
 * DERIVED write-site sweep: scan every `.php` file under $dir (recursive,
 * skipping the `.sql` migration tree — one-shot scripts are exempt by
 * construction, mirroring test-tune-lockstep.php's identical carve-out) for
 * an SQL statement that WRITES `tblSongs.Isrc` — an
 * `INSERT INTO tblSongs (...)` whose column list contains a bare `Isrc`, or
 * an `UPDATE tblSongs SET ... Isrc = ...`. Bounded windows (rule #34's
 * #1676 lesson: never "no `>`", always a generous bounded window) rather
 * than a single greedy match across the whole file.
 *
 * @return list<string> paths (relative to $dir) containing at least one
 *                        matching write statement
 */
function seimFindIsrcWriteSites(string $dir): array
{
    $hits = [];
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        $path = $file->getPathname();
        /* Comment-stripped so a commented-out SQL write is not counted as a
           write site (rule #34). */
        $src  = seimStripComments((string)file_get_contents($path));
        $isWrite =
            preg_match('/INSERT\s+INTO\s+tblSongs\b[^;]{0,600}\bIsrc\b/is', $src) === 1
            || preg_match('/UPDATE\s+tblSongs\b[^;]{0,700}\bIsrc\s*=/is', $src) === 1;
        if ($isWrite) {
            $hits[] = str_replace($dir . DIRECTORY_SEPARATOR, '', $path);
        }
    }
    sort($hits);
    return $hits;
}

/**
 * #1749 full unification — DERIVED scan: find every `.php` file under $dir
 * (recursive, skipping non-.php the same way seimFindIsrcWriteSites() does)
 * containing a comment-stripped, hand-typed `ORDER BY (e.SourceRef` literal
 * — the exact fragment `songExternalIdIsrcProjectionSql()`'s
 * `SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER` constant would produce if RE-FORKED
 * (typed a second time) instead of built by calling that ONE shared builder
 * (rule #22). $exemptRelPath (a path relative to $dir) is skipped entirely —
 * `includes/song_external_ids.php` builds the ORDER BY via string
 * CONCATENATION (`. ' ORDER BY ' . SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER`), so
 * the literal legitimately never appears as one contiguous string even in
 * that file's own source; excluding it just documents that rather than
 * relying on it being accidentally true.
 *
 * @return list<string> paths (relative to $dir) containing a re-forked literal
 */
function seimFindReforkedIsrcOrderBy(string $dir, string $exemptRelPath): array
{
    $hits = [];
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        $path = $file->getPathname();
        $relNorm = str_replace(DIRECTORY_SEPARATOR, '/', str_replace($dir . DIRECTORY_SEPARATOR, '', $path));
        if ($relNorm === $exemptRelPath) { continue; }
        $src = seimStripComments((string)file_get_contents($path));
        if (strpos($src, 'ORDER BY (e.SourceRef') !== false) {
            $hits[] = $relNorm;
        }
    }
    sort($hits);
    return $hits;
}

/**
 * #1749 full unification — the §7.2 SIBLING of seimFindIsrcWriteSites()
 * (immediately above): DERIVED scan for every `.php` file under $dir
 * (recursive, `.sql` migration tree skipped by construction — one-shot
 * scripts are exempt, same posture as the sibling scan) containing a
 * comment-stripped SQL statement that MUTATES `tblSongExternalIds` — an
 * `INSERT (IGNORE) INTO tblSongExternalIds`, `UPDATE (IGNORE)
 * tblSongExternalIds`, or `DELETE FROM tblSongExternalIds`. $selfRelPath is
 * excluded entirely: `includes/song_external_ids.php` IS the one write core
 * every hit below is expected to REFERENCE, so it cannot sensibly flag
 * itself (mirrors how the sibling sweep's own file never contains an
 * `UPDATE tblSongs SET ... Isrc` literal to flag).
 *
 * @return list<string> paths (relative to $dir) containing at least one
 *                        matching mutation
 */
/**
 * #1749 — the THREE SQL-statement shapes that MUTATE tblSongExternalIds,
 * defined ONCE (rule #22 / #35): both the mutation-site FILE finder
 * (seimFindExternalIdStoreMutationSites) and the per-statement POSITION finder
 * (seimFindExtIdMutationPositions) read this same list, so a widened/narrowed
 * detection can never drift between "which files are mutation sites" and
 * "where inside them the mutations are".
 */
const SEIM_EXTID_MUTATION_PATTERNS = [
    '/INSERT\s+(IGNORE\s+)?INTO\s+tblSongExternalIds\b/i',
    '/UPDATE\s+(IGNORE\s+)?tblSongExternalIds\b/i',
    '/DELETE\s+FROM\s+tblSongExternalIds\b/i',
];

function seimFindExternalIdStoreMutationSites(string $dir, string $selfRelPath): array
{
    $hits = [];
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        $path = $file->getPathname();
        $relNorm = str_replace(DIRECTORY_SEPARATOR, '/', str_replace($dir . DIRECTORY_SEPARATOR, '', $path));
        if ($relNorm === $selfRelPath) { continue; }
        $src = seimStripComments((string)file_get_contents($path));
        $isMutation = false;
        foreach (SEIM_EXTID_MUTATION_PATTERNS as $pat) {
            if (preg_match($pat, $src) === 1) { $isMutation = true; break; }
        }
        if ($isMutation) {
            $hits[] = $relNorm;
        }
    }
    sort($hits);
    return $hits;
}

/**
 * #1749 — every byte offset in $src at which a tblSongExternalIds-mutating
 * statement begins (INSERT/UPDATE/DELETE per SEIM_EXTID_MUTATION_PATTERNS).
 *
 * ELI5: "point at each spot in this file where a row in the store is
 * created/changed/removed", so the caller can check a sync runs right after
 * EACH of them — not just "somewhere in the whole file".
 *
 * DETAILED / WHY (rule #34): assertion 9 used to do a whole-file
 * `strpos($fileSrc, 'songExternalIdSyncIsrcDenorm')` co-occurrence check. That
 * stays GREEN when a mutation in ONE dispatch action loses its sync while an
 * UNRELATED action elsewhere in the same file still mentions it — api2.php
 * legitimately holds FOUR sync/mirror references across four cases, so a
 * whole-file check cannot tell "the add case still syncs" from "some OTHER
 * case does". Returning positions lets the assertion scope a bounded forward
 * window to each mutation individually.
 *
 * @return list<int> sorted ascending
 */
function seimFindExtIdMutationPositions(string $src): array
{
    $positions = [];
    foreach (SEIM_EXTID_MUTATION_PATTERNS as $pat) {
        if (preg_match_all($pat, $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) { $positions[] = $hit[1]; }
        }
    }
    sort($positions);
    return $positions;
}

/**
 * #1749 — true when a songExternalIdSyncIsrcDenorm( or songExternalIdMirrorIsrc(
 * reference appears within $window characters AFTER byte offset $pos (the start
 * of a store-mutating statement).
 *
 * ELI5: "does the store->column sync run just after this mutation?" — the sync
 * is the "last word" (D-1), so it must FOLLOW the write.
 *
 * DETAILED / WHY forward + bounded (rule #34, #1676's "generous bounded window"
 * lesson): the measured legitimate distance from every current mutation to its
 * own sync is at most 857 chars (api2 add-case INSERT), while the cross-case
 * refs that fooled the old whole-file check sit 40k+ chars away and BEFORE the
 * mutation. A forward window of 2000 clears every real site with >2x margin yet
 * a mutation whose sync was stripped falls to the next action's far sync (2833+
 * chars, or none) and goes red. Forward-only because a sync BEFORE the write
 * cannot be projecting the write's result.
 */
function seimMutationFollowedBySync(string $src, int $pos, int $window = 2000): bool
{
    $slice = substr($src, $pos, $window);
    return strpos($slice, 'songExternalIdSyncIsrcDenorm(') !== false
        || strpos($slice, 'songExternalIdMirrorIsrc(') !== false;
}

/**
 * #1749 — does songExternalIdMirrorIsrc()'s store->column sync
 * (songExternalIdSyncIsrcDenorm) run on BOTH migrated return paths? Returns
 * [clearedPathSyncs, insertedPathSyncs].
 *
 * The mirror has three returns: (1) the un-migrated table-absent early return
 * (echoes the caller's value, NO sync — correct); (2) the cleared-value path
 * inside `if ($value === '')`; (3) the inserted-value path, the function's
 * final return after the INSERT. Paths (2) and (3) must each project the store
 * back into the column.
 *
 * ELI5: split the function at the point where the "insert a value" path begins;
 * the half before it is the "cleared" path and the half after is the "inserted"
 * path — each must end in the sync.
 *
 * DETAILED / WHY per-path, not a whole-body count (rule #34): the old check was
 * `substr_count($mirrorFnSlice, 'songExternalIdSyncIsrcDenorm(') >= 2`. That
 * stays GREEN if the sync is REMOVED from the cleared path but DUPLICATED on
 * the inserted path — the count is still 2 while a cleared ISRC silently stops
 * healing tblSongs.Isrc. Splitting the body at the inserted-path anchor and
 * asserting each half independently makes exactly that mutation go red.
 *
 * @return array{0:bool,1:bool}
 */
function seimMirrorSyncsOnBothPaths(string $fnSlice): array
{
    $clearedAnchor  = strpos($fnSlice, "if (\$value === '')");
    $insertedAnchor = strpos($fnSlice, "\$idScope = 'recording'");
    if ($clearedAnchor === false || $insertedAnchor === false || $clearedAnchor >= $insertedAnchor) {
        return [false, false];
    }
    $clearedSegment  = substr($fnSlice, $clearedAnchor, $insertedAnchor - $clearedAnchor);
    $insertedSegment = substr($fnSlice, $insertedAnchor);
    return [
        strpos($clearedSegment, 'return songExternalIdSyncIsrcDenorm(') !== false,
        strpos($insertedSegment, 'return songExternalIdSyncIsrcDenorm(') !== false,
    ];
}

/**
 * #1749 full unification — slice an `if ($action === 'NAME') { ... }`
 * top-level POST-dispatcher block out of duplicate-songs.php — from that
 * `if` to the next top-level `if ($action === '` occurrence, or end of file.
 * The SAME shape as seimSliceCase()'s `switch`/`case` slicer, applied to
 * this file's `if`-chain dispatcher shape instead (duplicate-songs.php has
 * no `switch` — see its POST dispatcher).
 */
function seimSliceActionBlock(string $src, string $actionName): string
{
    if (!preg_match(
        '/if\s*\(\s*\$action\s*===\s*\'' . preg_quote($actionName, '/') . '\'\s*\)\s*\{.*?(?=\n\s*if\s*\(\s*\$action\s*===\s*\')/s',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory.
 * ========================================================================= */

$mutationFailures = [];

/* --- seimSliceFunction() --- */
$fixtureFnSrc = "function alpha(): array\n{\n    \$cols = ['NeedleInAlpha'];\n    return \$cols;\n}\n\nfunction beta(): array\n{\n    \$cols = ['NeedleInBeta'];\n    return \$cols;\n}\n";
$alphaFnSlice = seimSliceFunction($fixtureFnSrc, 'alpha');
if (strpos($alphaFnSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'seimSliceFunction() FAILS-HIGH self-test did not find the needle inside its own fixture function';
}
if (strpos($alphaFnSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "seimSliceFunction() FAILS-LOW self-test: alpha()'s slice bled into beta()";
}

/* --- seimSliceCase() --- */
$fixtureSwitchSrc = "switch (\$action) {\n    case 'alpha': {\n        \$x = 'NeedleInAlpha';\n        break;\n    }\n    case 'beta': {\n        \$x = 'NeedleInBeta';\n        break;\n    }\n}\n";
$alphaCaseSlice = seimSliceCase($fixtureSwitchSrc, 'alpha');
if (strpos($alphaCaseSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'seimSliceCase() FAILS-HIGH self-test did not find the needle inside its own fixture case';
}
if (strpos($alphaCaseSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "seimSliceCase() FAILS-LOW self-test: 'alpha' case slice bled into 'beta' case";
}

/* --- seimStripComments() --- */
$stripFixture = "<?php\n// NeedleInLineComment\n\$x = 'NeedleInCode';\n# NeedleInHashComment\n/* NeedleInBlockComment */\n/** NeedleInDocComment */\n\$y = \"AlsoCode\";\n";
$stripped = seimStripComments($stripFixture);
foreach (['NeedleInCode', 'AlsoCode'] as $codeNeedle) {
    if (strpos($stripped, $codeNeedle) === false) {
        $mutationFailures[] = "seimStripComments() FAILS-HIGH self-test removed the code string '{$codeNeedle}'";
    }
}
foreach (['NeedleInLineComment', 'NeedleInHashComment', 'NeedleInBlockComment', 'NeedleInDocComment'] as $commentNeedle) {
    if (strpos($stripped, $commentNeedle) !== false) {
        $mutationFailures[] = "seimStripComments() FAILS-LOW self-test kept the comment string '{$commentNeedle}'";
    }
}

/* --- seimExtractConstString() --- */
$fixtureConstSrc = "const NEEDLE_CONST = 'the-value';\nconst OTHER = 'unrelated';\n";
if (seimExtractConstString($fixtureConstSrc, 'NEEDLE_CONST') !== 'the-value') {
    $mutationFailures[] = 'seimExtractConstString() FAILS-HIGH self-test did not extract the fixture value';
}
if (seimExtractConstString($fixtureConstSrc, 'DOES_NOT_EXIST') !== null) {
    $mutationFailures[] = 'seimExtractConstString() FAILS-LOW self-test wrongly extracted a value for an absent const';
}

/* --- seimExtractIsrcBackfillSourceRef() --- */
$fixtureBackfillSrc = "\$mysql->query(\n    \"INSERT INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)\n     SELECT SongId, 'recording', 'isrc', Isrc, 'ihymns-backfill', 'tblSongs.Isrc'\n     FROM tblSongs\n     WHERE Isrc IS NOT NULL AND Isrc <> ''\"\n);\n";
if (seimExtractIsrcBackfillSourceRef($fixtureBackfillSrc) !== 'tblSongs.Isrc') {
    $mutationFailures[] = 'seimExtractIsrcBackfillSourceRef() FAILS-HIGH self-test did not extract the fixture SourceRef literal';
}
$fixtureBackfillSrcWrongType = "SELECT SongId, 'recording', 'genius', GeniusTrackId, 'ihymns-backfill', 'tblSongIdentityMap.GeniusTrackId'\n FROM tblSongs\n";
if (seimExtractIsrcBackfillSourceRef($fixtureBackfillSrcWrongType) !== null) {
    $mutationFailures[] = 'seimExtractIsrcBackfillSourceRef() FAILS-LOW self-test wrongly matched a non-isrc backfill INSERT';
}

/* --- #1751 seimMirrorDefaultsToIhymnsMirror() --- */
$fixtureMirrorFnDefault = "function songExternalIdMirrorIsrc(\\mysqli \$db, string \$songId, ?string \$canonicalIsrc, string \$source = 'ihymns-mirror'): void\n{\n    /* body */\n}\n";
if (!seimMirrorDefaultsToIhymnsMirror($fixtureMirrorFnDefault)) {
    $mutationFailures[] = "seimMirrorDefaultsToIhymnsMirror() FAILS-HIGH self-test did not detect the fixture's \$source = 'ihymns-mirror' default";
}
$fixtureMirrorFnWrongDefault = "function songExternalIdMirrorIsrc(\\mysqli \$db, string \$songId, ?string \$canonicalIsrc, string \$source = 'something-else'): void\n{\n    /* body */\n}\n";
if (seimMirrorDefaultsToIhymnsMirror($fixtureMirrorFnWrongDefault)) {
    $mutationFailures[] = "seimMirrorDefaultsToIhymnsMirror() FAILS-LOW self-test wrongly matched a fixture whose \$source default is NOT 'ihymns-mirror'";
}

/* --- #1751 seimCountIngestMirrorCalls() --- */
$fixtureIngestTwoCalls = "songExternalIdMirrorIsrc(\$db, \$songId, \$isrc, 'ihymns-ingest');\nsongExternalIdMirrorIsrc(\$db, \$songId, \$storedIsrc, 'ihymns-ingest');\n";
if (seimCountIngestMirrorCalls($fixtureIngestTwoCalls) !== 2) {
    $mutationFailures[] = 'seimCountIngestMirrorCalls() FAILS-HIGH self-test: expected 2 matches in the two-call fixture, got ' . seimCountIngestMirrorCalls($fixtureIngestTwoCalls);
}
$fixtureIngestOneCallOneMirror = "songExternalIdMirrorIsrc(\$db, \$songId, \$isrc, 'ihymns-ingest');\nsongExternalIdMirrorIsrc(\$db, \$songId, \$storedIsrc, 'ihymns-mirror');\n";
if (seimCountIngestMirrorCalls($fixtureIngestOneCallOneMirror) !== 1) {
    $mutationFailures[] = "seimCountIngestMirrorCalls() FAILS-LOW self-test: a site whose literal was changed to 'ihymns-mirror' must NOT count, got " . seimCountIngestMirrorCalls($fixtureIngestOneCallOneMirror);
}

/* --- seimFindIsrcWriteSites() --- */
$fixtureDir = sys_get_temp_dir() . '/seim_fixture_' . bin2hex(random_bytes(6));
mkdir($fixtureDir, 0777, true);
mkdir($fixtureDir . '/sub', 0777, true);
file_put_contents($fixtureDir . '/writer.php', "<?php\n\$db->query(\"UPDATE tblSongs SET Isrc = ? WHERE SongId = ?\");\n");
file_put_contents($fixtureDir . '/sub/inserter.php', "<?php\n\$db->prepare('INSERT INTO tblSongs (SongId, Isrc) VALUES (?, ?)');\n");
file_put_contents($fixtureDir . '/reader.php', "<?php\n\$db->prepare('SELECT Isrc FROM tblSongs WHERE SongId = ?');\n");
file_put_contents($fixtureDir . '/unrelated.php', "<?php\necho 'nothing to see here';\n");
$fixtureHits = seimFindIsrcWriteSites($fixtureDir);
if (!in_array('writer.php', $fixtureHits, true)) {
    $mutationFailures[] = 'seimFindIsrcWriteSites() FAILS-HIGH self-test did not find the UPDATE-writing fixture file';
}
if (!in_array('sub' . DIRECTORY_SEPARATOR . 'inserter.php', $fixtureHits, true)) {
    $mutationFailures[] = 'seimFindIsrcWriteSites() FAILS-HIGH self-test did not find the INSERT-writing fixture file (recursion into a subdirectory)';
}
if (in_array('reader.php', $fixtureHits, true)) {
    $mutationFailures[] = 'seimFindIsrcWriteSites() FAILS-LOW self-test wrongly flagged a SELECT-only fixture file as a write site';
}
if (in_array('unrelated.php', $fixtureHits, true)) {
    $mutationFailures[] = 'seimFindIsrcWriteSites() FAILS-LOW self-test wrongly flagged an unrelated fixture file';
}
/* Clean up the fixture tree. */
@unlink($fixtureDir . '/writer.php');
@unlink($fixtureDir . '/sub/inserter.php');
@unlink($fixtureDir . '/reader.php');
@unlink($fixtureDir . '/unrelated.php');
@rmdir($fixtureDir . '/sub');
@rmdir($fixtureDir);

/* --- #1749 seimFindReforkedIsrcOrderBy() --- */
$reforkFixtureDir = sys_get_temp_dir() . '/seim_refork_fixture_' . bin2hex(random_bytes(6));
mkdir($reforkFixtureDir, 0777, true);
file_put_contents($reforkFixtureDir . '/reforked.php', "<?php\n\$sql = \"... ORDER BY (e.SourceRef <=> 'tblSongs.Isrc') DESC, e.Id ASC ...\";\n");
file_put_contents($reforkFixtureDir . '/clean.php', "<?php\n\$sql = songExternalIdIsrcProjectionSql('?');\n");
file_put_contents($reforkFixtureDir . '/exempt.php', "<?php\n\$sql = \"... ORDER BY (e.SourceRef <=> 'tblSongs.Isrc') DESC, e.Id ASC ...\";\n");
$reforkHits = seimFindReforkedIsrcOrderBy($reforkFixtureDir, 'exempt.php');
if (!in_array('reforked.php', $reforkHits, true)) {
    $mutationFailures[] = 'seimFindReforkedIsrcOrderBy() FAILS-HIGH self-test did not find the fixture file carrying a re-forked ORDER BY literal';
}
if (in_array('clean.php', $reforkHits, true)) {
    $mutationFailures[] = 'seimFindReforkedIsrcOrderBy() FAILS-LOW self-test wrongly flagged a fixture that calls the shared builder instead of re-forking';
}
if (in_array('exempt.php', $reforkHits, true)) {
    $mutationFailures[] = 'seimFindReforkedIsrcOrderBy() FAILS-LOW self-test: the exempt path was not actually excluded';
}
@unlink($reforkFixtureDir . '/reforked.php');
@unlink($reforkFixtureDir . '/clean.php');
@unlink($reforkFixtureDir . '/exempt.php');
@rmdir($reforkFixtureDir);

/* --- #1749 seimFindExternalIdStoreMutationSites() --- */
$extIdFixtureDir = sys_get_temp_dir() . '/seim_extid_fixture_' . bin2hex(random_bytes(6));
mkdir($extIdFixtureDir, 0777, true);
file_put_contents($extIdFixtureDir . '/inserter.php', "<?php\n\$db->prepare('INSERT IGNORE INTO tblSongExternalIds (SongId) VALUES (?)');\n");
file_put_contents($extIdFixtureDir . '/updater.php', "<?php\n\$db->prepare('UPDATE IGNORE tblSongExternalIds SET SongId = ? WHERE SongId = ?');\n");
file_put_contents($extIdFixtureDir . '/deleter.php', "<?php\n\$db->prepare('DELETE FROM tblSongExternalIds WHERE Id = ?');\n");
file_put_contents($extIdFixtureDir . '/reader.php', "<?php\n\$db->prepare('SELECT Id FROM tblSongExternalIds WHERE SongId = ?');\n");
file_put_contents($extIdFixtureDir . '/self.php', "<?php\n\$db->prepare('INSERT INTO tblSongExternalIds (SongId) VALUES (?)');\n");
$extIdHits = seimFindExternalIdStoreMutationSites($extIdFixtureDir, 'self.php');
foreach (['inserter.php', 'updater.php', 'deleter.php'] as $needle) {
    if (!in_array($needle, $extIdHits, true)) {
        $mutationFailures[] = "seimFindExternalIdStoreMutationSites() FAILS-HIGH self-test did not find the {$needle} fixture file";
    }
}
if (in_array('reader.php', $extIdHits, true)) {
    $mutationFailures[] = 'seimFindExternalIdStoreMutationSites() FAILS-LOW self-test wrongly flagged a SELECT-only fixture file';
}
if (in_array('self.php', $extIdHits, true)) {
    $mutationFailures[] = 'seimFindExternalIdStoreMutationSites() FAILS-LOW self-test: the $selfRelPath exclusion did not actually exclude it';
}
@unlink($extIdFixtureDir . '/inserter.php');
@unlink($extIdFixtureDir . '/updater.php');
@unlink($extIdFixtureDir . '/deleter.php');
@unlink($extIdFixtureDir . '/reader.php');
@unlink($extIdFixtureDir . '/self.php');
@rmdir($extIdFixtureDir);

/* --- #1749 seimFindExtIdMutationPositions() --- */
$posFixture = "<?php\n\$a='before';\n\$db->query('INSERT INTO tblSongExternalIds (SongId) VALUES (?)');\n\$b='mid';\n\$db->query('DELETE FROM tblSongExternalIds WHERE Id=?');\n\$db->query('SELECT Id FROM tblSongExternalIds');\n";
$posHits = seimFindExtIdMutationPositions($posFixture);
if (count($posHits) !== 2) {
    $mutationFailures[] = 'seimFindExtIdMutationPositions() self-test found ' . count($posHits) . ' positions, expected 2 (one INSERT + one DELETE; the SELECT is not a mutation)';
} else {
    if ($posHits[0] >= $posHits[1]) {
        $mutationFailures[] = 'seimFindExtIdMutationPositions() self-test returned positions out of ascending order';
    }
    if (substr($posFixture, $posHits[0], 6) !== 'INSERT') {
        $mutationFailures[] = "seimFindExtIdMutationPositions() self-test: first position does not point at the INSERT (found '" . substr($posFixture, $posHits[0], 6) . "')";
    }
    if (substr($posFixture, $posHits[1], 6) !== 'DELETE') {
        $mutationFailures[] = "seimFindExtIdMutationPositions() self-test: second position does not point at the DELETE (found '" . substr($posFixture, $posHits[1], 6) . "')";
    }
}

/* --- #1749 seimMutationFollowedBySync() --- */
$mfsGood = "DELETE FROM tblSongExternalIds WHERE Id=?';\n\$db->execute();\nsongExternalIdSyncIsrcDenorm(\$db, \$songId);\n";
$mfsBad  = "DELETE FROM tblSongExternalIds WHERE Id=?';\n\$db->execute();\n" . str_repeat("// filler line\n", 300) . "songExternalIdSyncIsrcDenorm(\$db, \$songId);\n";
if (!seimMutationFollowedBySync($mfsGood, 0)) {
    $mutationFailures[] = 'seimMutationFollowedBySync() FAILS-HIGH self-test: a sync within the window was not detected';
}
if (seimMutationFollowedBySync($mfsBad, 0)) {
    $mutationFailures[] = 'seimMutationFollowedBySync() FAILS-LOW self-test: a sync FAR beyond the window was wrongly accepted';
}

/* --- #1749 seimMirrorSyncsOnBothPaths() --- */
$msbpGood = "\$value = trim(\$x);\nif (\$value === '') {\n    return songExternalIdSyncIsrcDenorm(\$db, \$songId);\n}\n\$idScope = 'recording';\n\$ins->execute();\nreturn songExternalIdSyncIsrcDenorm(\$db, \$songId);\n";
[$msbpClear, $msbpInsert] = seimMirrorSyncsOnBothPaths($msbpGood);
if (!$msbpClear || !$msbpInsert) {
    $mutationFailures[] = 'seimMirrorSyncsOnBothPaths() FAILS-HIGH self-test: a body with the sync on BOTH paths was not recognised as such';
}
/* The exact wrong-but-green mutation the old assertion 8 missed: sync removed
   from the cleared path, DUPLICATED on the inserted path (whole-body count
   stays 2). Per-path split must report the cleared path as FALSE. */
$msbpDupInsert = "\$value = trim(\$x);\nif (\$value === '') {\n    return null;\n}\n\$idScope = 'recording';\n\$ins->execute();\nreturn songExternalIdSyncIsrcDenorm(\$db, \$songId);\nreturn songExternalIdSyncIsrcDenorm(\$db, \$songId);\n";
[$msbpClear2, $msbpInsert2] = seimMirrorSyncsOnBothPaths($msbpDupInsert);
if ($msbpClear2) {
    $mutationFailures[] = 'seimMirrorSyncsOnBothPaths() FAILS-LOW self-test: the cleared path was reported as syncing even though its sync was removed (duplicated on the inserted path) — this is the exact wrong-but-green the per-path split exists to catch';
}
if (!$msbpInsert2) {
    $mutationFailures[] = 'seimMirrorSyncsOnBothPaths() self-test: the inserted path (which does sync) was reported as not syncing';
}

/* --- #1749 seimSliceActionBlock() --- */
$fixtureActionSrc = "if (\$action === 'alpha') {\n    \$x = 'NeedleInAlpha';\n}\nif (\$action === 'beta') {\n    \$x = 'NeedleInBeta';\n}\n";
$alphaActionSlice = seimSliceActionBlock($fixtureActionSrc, 'alpha');
if (strpos($alphaActionSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'seimSliceActionBlock() FAILS-HIGH self-test did not find the needle inside its own fixture block';
}
if (strpos($alphaActionSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "seimSliceActionBlock() FAILS-LOW self-test: 'alpha' block slice bled into 'beta' block";
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

$failures = [];

$helperFile    = $repoRoot . '/appWeb/public_html/includes/song_external_ids.php';
$mediaIdsFile  = $repoRoot . '/appWeb/public_html/includes/media_identifiers.php';
$backfillFile  = $repoRoot . '/appWeb/.sql/migrate-backfill-song-external-ids.php';
$api2File      = $repoRoot . '/appWeb/public_html/manage/editor/api2.php';
$publicHtmlDir = $repoRoot . '/appWeb/public_html';

foreach ([
    'includes/song_external_ids.php'                       => $helperFile,
    'includes/media_identifiers.php'                        => $mediaIdsFile,
    '.sql/migrate-backfill-song-external-ids.php'            => $backfillFile,
    'manage/editor/api2.php'                                 => $api2File,
] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read {$label} at {$path}\n");
        exit(1);
    }
}

/* Comment-stripped ONCE here (#1751): every code assertion below slices or
   regex-scans these strings, and a stale/misleading comment mentioning a
   symbol or literal must never satisfy a code assertion (rule #34 — proven by
   hand that a stale const comment kept assertion 3 green). */
$helperSrc   = seimStripComments((string)file_get_contents($helperFile));
$mediaSrc    = seimStripComments((string)file_get_contents($mediaIdsFile));
$backfillSrc = seimStripComments((string)file_get_contents($backfillFile));
$api2Src     = seimStripComments((string)file_get_contents($api2File));

/* ---- 1. includes/song_external_ids.php exists (already fatal-checked
   above) and declares songExternalIdMirrorIsrc(). ---- */
$mirrorFnSlice = seimSliceFunction($helperSrc, 'songExternalIdMirrorIsrc');
if ($mirrorFnSlice === '') {
    $failures[] = 'includes/song_external_ids.php does not declare function songExternalIdMirrorIsrc()';
}

/* ---- 2. The helper's INSERT carries 'recording'/'isrc', and BOTH values
   are real entries in media_identifiers.php's central vocabulary (rule #35
   — no second, silently-divergent list). ---- */
if ($mirrorFnSlice !== '') {
    if (strpos($mirrorFnSlice, "'recording'") === false) {
        $failures[] = "songExternalIdMirrorIsrc() does not use the 'recording' IdScope literal";
    }
    if (strpos($mirrorFnSlice, "'isrc'") === false) {
        $failures[] = "songExternalIdMirrorIsrc() does not use the 'isrc' IdType literal";
    }
}
if (strpos($mediaSrc, "'recording'") === false || strpos($mediaSrc, 'SONG_EXTERNAL_ID_SCOPES') === false) {
    $failures[] = "media_identifiers.php's SONG_EXTERNAL_ID_SCOPES does not appear to contain 'recording'";
}
if (!preg_match("/'isrc'\s*=>\s*\[/", $mediaSrc)) {
    $failures[] = "media_identifiers.php's RECORDING_EXTERNAL_ID_TYPES does not appear to have an 'isrc' entry";
}

/* ---- 3. SourceRef byte-equality: the helper's own constant vs the literal
   parsed out of the REAL backfill migration's tblSongs.Isrc INSERT. ---- */
$helperSourceRef   = seimExtractConstString($helperSrc, 'SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF');
$backfillSourceRef = seimExtractIsrcBackfillSourceRef($backfillSrc);
if ($helperSourceRef === null) {
    $failures[] = 'Could not find const SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF in includes/song_external_ids.php';
}
if ($backfillSourceRef === null) {
    $failures[] = 'Could not find the tblSongs.Isrc-sourced SourceRef literal in migrate-backfill-song-external-ids.php';
}
if ($helperSourceRef !== null && $backfillSourceRef !== null && $helperSourceRef !== $backfillSourceRef) {
    $failures[] = "SourceRef literal drift: includes/song_external_ids.php has '{$helperSourceRef}', "
                . "migrate-backfill-song-external-ids.php has '{$backfillSourceRef}' — these MUST be byte-equal "
                . "(the mirror's ownership model depends on it; see the file doc-blocks)";
}

/* ---- 4. The helper's DELETE carries the ownership predicate (a bare
   IdType='isrc' delete, with no SourceRef leg, would eat manual rows). ---- */
if ($mirrorFnSlice !== '' && !preg_match('/DELETE\s+FROM\s+tblSongExternalIds[^;]{0,300}SourceRef\s*=/is', $mirrorFnSlice)) {
    $failures[] = 'songExternalIdMirrorIsrc()\'s DELETE does not carry a "SourceRef =" predicate — this would delete a curator\'s manual ISRC row too';
}

/* ---- 5. api2.php's metadata_field_update AND ed2_applySongSnapshot both
   call the mirror. ---- */
$mfuSlice  = seimSliceCase($api2Src, 'metadata_field_update');
$snapSlice = seimSliceFunction($api2Src, 'ed2_applySongSnapshot');
if ($mfuSlice === '') {
    $failures[] = "Could not locate case 'metadata_field_update': in api2.php";
} elseif (strpos($mfuSlice, 'songExternalIdMirrorIsrc') === false) {
    $failures[] = "case 'metadata_field_update' in api2.php does not call songExternalIdMirrorIsrc() — the #1749 dual-write";
}
if ($snapSlice === '') {
    $failures[] = 'Could not locate function ed2_applySongSnapshot() in api2.php';
} elseif (strpos($snapSlice, 'songExternalIdMirrorIsrc') === false) {
    $failures[] = 'ed2_applySongSnapshot() does not call songExternalIdMirrorIsrc() — a revision restore is an Isrc write funnel too';
}

/* ---- 5b (#1751). Literal-agreement, mechanism not comment (rule #35):
   (a) songExternalIdMirrorIsrc()'s $source parameter still defaults to
   'ihymns-mirror', so the two existing api2.php call sites (which pass no
   4th argument) keep emitting the SAME provenance label they always have;
   (b) lyrics_ingest.php calls the mirror with 'ihymns-ingest' from AT LEAST
   2 sites — both #1751 ingest write sites, not just one. ---- */
if ($mirrorFnSlice !== '' && !seimMirrorDefaultsToIhymnsMirror($mirrorFnSlice)) {
    $failures[] = "songExternalIdMirrorIsrc()'s \$source parameter no longer defaults to 'ihymns-mirror' — this would silently change the provenance the two existing api2.php call sites (which pass no 4th argument) write";
}

$lyricsIngestFile = $repoRoot . '/appWeb/public_html/includes/lyrics_ingest.php';
if (!is_readable($lyricsIngestFile)) {
    fwrite(STDERR, "FATAL: could not read includes/lyrics_ingest.php at {$lyricsIngestFile}\n");
    exit(1);
}
$lyricsIngestSrc = seimStripComments((string)file_get_contents($lyricsIngestFile));   /* #1751 — comment-stripped (rule #34) */
$ingestMirrorCallCount = seimCountIngestMirrorCalls($lyricsIngestSrc);
if ($ingestMirrorCallCount < 2) {
    $failures[] = "includes/lyrics_ingest.php calls songExternalIdMirrorIsrc(...'ihymns-ingest') fewer than 2 times (found {$ingestMirrorCallCount}) — both #1751 ingest write sites (lyricsIngest_createSong() and lyricsIngest_storeExternalIds()) must pass the ingest provenance, or a partially-wired ingest silently under-mirrors";
}

/* ---- 6. Derived write-site sweep: every file that writes tblSongs.Isrc
   must reference the mirror OR be named, WITH an issue number, in the
   exempt list below. An exemption with no issue number is itself a
   failure — "we'll fix it later" is not a mechanism (rule #35). ---- */
$exempt = [];   /* #1751 — empty. Its last entry, lyrics_ingest.php, self-cleaned
                   when #1751 wired the ingest sites to the mirror (mirrors the
                   test-fragment-inline-scripts.php empty-allowlist convention). */
/* The guard FAILS if an exemption's value doesn't look like it carries an
   issue reference (a '#' followed by digits, or a github.com issue URL) —
   an empty/placeholder exemption is the same regression as no exemption at
   all, just quieter. */
foreach ($exempt as $exFile => $exReason) {
    if (!preg_match('/#\d+/', $exReason) && !preg_match('#github\.com/[^ ]+/issues/\d+#', $exReason)) {
        $failures[] = "The exemption for {$exFile} has no issue number in its reason — an unnumbered exemption is not a tracked follow-up";
    }
}

$writeSites = seimFindIsrcWriteSites($publicHtmlDir);
foreach ($writeSites as $rel) {
    $relNorm = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    if (array_key_exists($relNorm, $exempt)) {
        continue;
    }
    $fileSrc = seimStripComments((string)file_get_contents($publicHtmlDir . DIRECTORY_SEPARATOR . $rel));   /* #1751 — a commented-out mirror call must not satisfy this (rule #34) */
    if (strpos($fileSrc, 'songExternalIdMirrorIsrc') === false) {
        $failures[] = "appWeb/public_html/{$relNorm} writes tblSongs.Isrc but never references songExternalIdMirrorIsrc() and is not in this test's exempt list — either wire it to the #1749 mirror or add a REASONED, issue-numbered exemption";
    }
}

/* =========================================================================
 * #1749 FULL UNIFICATION — §7.2 of the build spec, same file/family (one
 * guard for the one write core, extended rather than forked — rule #22).
 * ========================================================================= */

$reconcileFile = $repoRoot . '/appWeb/.sql/migrate-reconcile-isrc-denorm.php';
foreach ([
    '.sql/migrate-reconcile-isrc-denorm.php' => $reconcileFile,
] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read {$label} at {$path}\n");
        exit(1);
    }
}
$reconcileSrc = seimStripComments((string)file_get_contents($reconcileFile));

/* ---- 7. Projection single-sourcing: the §2.1 primary-ISRC ORDER BY lives
   ONCE (SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER + songExternalIdIsrcProjectionSql()
   in includes/song_external_ids.php) — DERIVED scan bans a hand-typed
   re-fork of the literal anywhere else under appWeb/public_html (recursive)
   or in the reconcile migration, and requires BOTH the reconcile script and
   the registry probe to actually CALL the shared builder rather than
   re-deriving the rule. ---- */
$reforkedInPublicHtml = seimFindReforkedIsrcOrderBy($publicHtmlDir, 'includes/song_external_ids.php');
if ($reforkedInPublicHtml) {
    $failures[] = 'A hand-typed "ORDER BY (e.SourceRef" literal (a re-fork of the §2.1 primary-ISRC rule) was found outside includes/song_external_ids.php in: '
                . implode(', ', array_map(static fn($p) => "appWeb/public_html/{$p}", $reforkedInPublicHtml))
                . ' — call songExternalIdIsrcProjectionSql() instead (rule #22)';
}
if (strpos($reconcileSrc, 'ORDER BY (e.SourceRef') !== false) {
    $failures[] = 'appWeb/.sql/migrate-reconcile-isrc-denorm.php contains a hand-typed "ORDER BY (e.SourceRef" literal — call songExternalIdIsrcProjectionSql() instead (rule #22)';
}
if (strpos($reconcileSrc, 'songExternalIdIsrcProjectionSql(') === false) {
    $failures[] = 'appWeb/.sql/migrate-reconcile-isrc-denorm.php never calls songExternalIdIsrcProjectionSql() — Step B (store->column) must use the ONE shared projection builder, not a re-derived query';
}

$registryFile = $repoRoot . '/appWeb/public_html/manage/includes/migration-registry.php';
if (!is_readable($registryFile)) {
    fwrite(STDERR, "FATAL: could not read manage/includes/migration-registry.php at {$registryFile}\n");
    exit(1);
}
$registrySrc = seimStripComments((string)file_get_contents($registryFile));
$registryEntryPos = strpos($registrySrc, "'reconcile-isrc-denorm'");
if ($registryEntryPos === false) {
    $failures[] = "manage/includes/migration-registry.php has no 'reconcile-isrc-denorm' registry entry (rule #19 — the #1749 cutover card must be registered)";
} else {
    /* Generous bounded window (rule #34 / #1676's lesson) covering the
       entry's script/card/probe — the probe closure itself is the longest
       part of this entry. */
    $registryEntrySlice = substr($registrySrc, $registryEntryPos, 3500);
    if (strpos($registryEntrySlice, 'songExternalIdIsrcProjectionSql(') === false) {
        $failures[] = "the 'reconcile-isrc-denorm' registry entry's probe never calls songExternalIdIsrcProjectionSql() — a re-derived probe query would let the projection rule drift from the ONE builder (rule #22)";
    }
    if (strpos($registryEntrySlice, 'ORDER BY (e.SourceRef') !== false) {
        $failures[] = "the 'reconcile-isrc-denorm' registry entry contains a hand-typed \"ORDER BY (e.SourceRef\" literal — call songExternalIdIsrcProjectionSql() instead (rule #22)";
    }
}

/* ---- 8. Mirror ends with the sync: songExternalIdMirrorIsrc()'s embedded
   store->column projection (D-1's "the store has the last word") must run on
   BOTH return paths — the cleared-value early return AND the normal
   insert-then-return path — never just one, or a cleared ISRC could leave a
   stale/wrong column value uncorrected. ---- */
if ($mirrorFnSlice !== '') {
    [$clearedSyncs, $insertedSyncs] = seimMirrorSyncsOnBothPaths($mirrorFnSlice);
    if (!$clearedSyncs) {
        $failures[] = "songExternalIdMirrorIsrc()'s CLEARED-value path (inside `if (\$value === '')`) does not `return songExternalIdSyncIsrcDenorm(...)` — a cleared ISRC would leave tblSongs.Isrc at whatever the caller's own write left it (the #1749 D-1 promotion/heal case), uncorrected";
    }
    if (!$insertedSyncs) {
        $failures[] = "songExternalIdMirrorIsrc()'s INSERTED-value path (the final return after the INSERT) does not `return songExternalIdSyncIsrcDenorm(...)` — the store would not get the last word (#1749 D-1)";
    }
}

/* ---- 9. Derived store-mutation sweep: every file that mutates
   tblSongExternalIds (outside the funnel file itself) must reference
   songExternalIdSyncIsrcDenorm() or songExternalIdMirrorIsrc() (which embeds
   it), or be named, WITH an issue number, in the exempt list below — the
   SAME "no unnumbered exemption" posture as assertion 6's sibling sweep.
   Expected initial exempt: [] — api2.php and duplicate-songs.php both
   qualify by calling the sync/mirror directly. ---- */
$extIdExempt = [];   /* #1749 — empty. includes/song_external_ids.php is excluded
                        structurally (it IS the funnel, not a caller of it);
                        every other current mutation site already calls the sync. */
foreach ($extIdExempt as $exFile => $exReason) {
    if (!preg_match('/#\d+/', $exReason) && !preg_match('#github\.com/[^ ]+/issues/\d+#', $exReason)) {
        $failures[] = "The tblSongExternalIds-mutation exemption for {$exFile} has no issue number in its reason — an unnumbered exemption is not a tracked follow-up";
    }
}
$extIdMutationSites = seimFindExternalIdStoreMutationSites($publicHtmlDir, 'includes/song_external_ids.php');
foreach ($extIdMutationSites as $relNorm) {
    if (array_key_exists($relNorm, $extIdExempt)) {
        continue;
    }
    $fileSrc = seimStripComments((string)file_get_contents($publicHtmlDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relNorm)));
    /* Per-statement forward-window (rule #34): NOT a whole-file co-occurrence.
       Each individual INSERT/UPDATE/DELETE against tblSongExternalIds must be
       FOLLOWED (within seimMutationFollowedBySync()'s window) by the
       store->column sync — a distant sync in an unrelated function no longer
       satisfies a mutation that lost its own. */
    foreach (seimFindExtIdMutationPositions($fileSrc) as $mpos) {
        if (!seimMutationFollowedBySync($fileSrc, $mpos)) {
            $failures[] = "appWeb/public_html/{$relNorm} mutates tblSongExternalIds at offset {$mpos} without a songExternalIdSyncIsrcDenorm()/songExternalIdMirrorIsrc() call in the following code — the #1749 store->column sync must run after this specific mutation (or add a REASONED, issue-numbered exemption)";
        }
    }
}

/* ---- 10. Merge specifics (#1755): duplicate-songs.php's merge handler
   repoints the duplicate's tblSongExternalIds rows to the survivor (gated —
   an ungated touch would STRICT-throw on an un-migrated install, rule #9),
   DEMOTES the duplicate's own marker row so the survivor can never end up
   with two, and re-projects the survivor's column afterwards. Dynamic SQL
   the derived sweep above already caught structurally (duplicate-songs.php
   IS one of the mutation sites in assertion 9) — this asserts the SPECIFIC
   shape within the merge action's own slice, which a bare "references the
   sync somewhere in the file" check cannot. ---- */
$dupFile = $repoRoot . '/appWeb/public_html/manage/duplicate-songs.php';
if (!is_readable($dupFile)) {
    fwrite(STDERR, "FATAL: could not read manage/duplicate-songs.php at {$dupFile}\n");
    exit(1);
}
$dupSrc = seimStripComments((string)file_get_contents($dupFile));
$mergeSlice = seimSliceActionBlock($dupSrc, 'merge');
if ($mergeSlice === '') {
    $failures[] = "Could not locate the if (\$action === 'merge') block in manage/duplicate-songs.php";
} else {
    if (strpos($mergeSlice, 'songExternalIdsTableExists(') === false) {
        $failures[] = "manage/duplicate-songs.php's merge handler touches tblSongExternalIds without an existence gate (songExternalIdsTableExists()) — an un-migrated install would STRICT-throw (rule #9)";
    }
    if (!preg_match('/UPDATE\s+IGNORE\s+tblSongExternalIds\b/i', $mergeSlice)) {
        $failures[] = "manage/duplicate-songs.php's merge handler does not repoint tblSongExternalIds rows via an UPDATE IGNORE — the duplicate's store rows would be lost to the ON DELETE CASCADE (#1755)";
    }
    if (!preg_match('/SourceRef\s*=\s*IF\s*\(\s*IdType\s*=\s*\'isrc\'/', $mergeSlice)) {
        $failures[] = "manage/duplicate-songs.php's merge handler does not DEMOTE the duplicate's own isrc marker row (SourceRef = IF(IdType = 'isrc' ... NULL ...)) — the survivor could end up with two marker rows (§2.1 anomaly)";
    }
    if (strpos($mergeSlice, 'songExternalIdSyncIsrcDenorm(') === false) {
        $failures[] = "manage/duplicate-songs.php's merge handler never calls songExternalIdSyncIsrcDenorm() on the survivor — the store's post-merge primary ISRC would never reach tblSongs.Isrc";
    }
}

/* ---- 11. api2 endpoint wiring: song_external_id_add and
   song_external_id_delete each call the store->column sync, and _delete
   pre-reads the row's IdType BEFORE its DELETE (so it can decide whether a
   sync is needed at all, and so the SELECT can never observe its own
   DELETE's aftermath). ---- */
$addSlice = seimSliceCase($api2Src, 'song_external_id_add');
if ($addSlice === '') {
    $failures[] = "Could not locate case 'song_external_id_add': in manage/editor/api2.php";
} elseif (strpos($addSlice, 'songExternalIdSyncIsrcDenorm(') === false) {
    $failures[] = "case 'song_external_id_add' in api2.php does not call songExternalIdSyncIsrcDenorm() — the #1749 panel-add store->column sync";
}
$deleteSlice = seimSliceCase($api2Src, 'song_external_id_delete');
if ($deleteSlice === '') {
    $failures[] = "Could not locate case 'song_external_id_delete': in manage/editor/api2.php";
} else {
    if (strpos($deleteSlice, 'songExternalIdSyncIsrcDenorm(') === false) {
        $failures[] = "case 'song_external_id_delete' in api2.php does not call songExternalIdSyncIsrcDenorm() — the #1749 panel-delete store->column sync";
    }
    $preReadPos = strpos($deleteSlice, 'SELECT IdType FROM tblSongExternalIds');
    $deletePos  = strpos($deleteSlice, 'DELETE FROM tblSongExternalIds');
    if ($preReadPos === false) {
        $failures[] = "case 'song_external_id_delete' in api2.php does not pre-read the row's IdType (SELECT IdType FROM tblSongExternalIds) before deleting it";
    } elseif ($deletePos === false) {
        $failures[] = "case 'song_external_id_delete' in api2.php has no DELETE FROM tblSongExternalIds statement to compare against";
    } elseif ($preReadPos > $deletePos) {
        $failures[] = "case 'song_external_id_delete' in api2.php reads IdType AFTER its DELETE, not before — the row would already be gone";
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: ISRC dual-write mirror guard (#1749 / #1741 P5d):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    exit(1);
}

echo "PASS: ISRC dual-write mirror guard — songExternalIdMirrorIsrc() uses the central 'recording'/'isrc' "
   . "vocabulary; its SourceRef literal is byte-equal to the #1747 D5 backfill's; its DELETE carries the "
   . "SourceRef ownership predicate; api2.php's metadata_field_update and ed2_applySongSnapshot() both call "
   . "it; " . count($writeSites) . " derived tblSongs.Isrc write site(s) found (" . implode(', ', $writeSites) . "), "
   . "each either calling the mirror or explicitly, issue-numbered exempt; all mutation self-tests went red as expected.\n";
exit(0);
