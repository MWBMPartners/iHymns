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
