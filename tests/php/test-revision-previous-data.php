<?php

declare(strict_types=1);

/**
 * iHymns — #1743 revision PreviousData chain guard
 * ==================================================
 *
 * ELI5
 * ----
 * Every time the v2 editor saves a song, it writes a "revision" row that is
 * supposed to remember two things: what the song looked like BEFORE this save
 * (PreviousData) and what it looks like AFTER (NewData). The v2 writer used to
 * always write PreviousData as a hard-coded empty answer (NULL), so the OLD
 * editor's "Restore this version" button always said "sorry, nothing to go
 * back to" — even for an ordinary edit that obviously had a "before". This
 * test makes sure that mistake stays fixed, in three places at once: the
 * writer that used to hard-code NULL, the reader that used to give up on
 * anything that wasn't the exact shape it expected, and the OTHER writer that
 * was already doing this correctly (so nobody "fixes" it back to NULL later).
 *
 * WHY THIS EXISTS (#1743)
 * ------------------------
 * `ed2_touchRevision()` in `manage/editor/api2.php` hard-coded the SQL literal
 * `NULL` as the `PreviousData` column value on every INSERT — every v2
 * granular edit (metadata_field_update, credit_upsert, ~15 call sites) wrote a
 * revision with no prior state. The legacy (v1) `restore_revision` case in
 * `manage/editor/api.php` reads that same column and, finding NULL, answers a
 * 409 reading "This revision has no prior state to restore (likely the
 * initial create)." — a wrong diagnosis for an ordinary v2 metadata edit.
 *
 * THE FIX HAS TWO HALVES THAT MUST LAND TOGETHER
 * ------------------------------------------------
 *  C2 (api2.php): `ed2_touchRevision()` now chains PreviousData from the
 *      immediately preceding revision row's NewData — verbatim, whatever
 *      shape it is stored in (the chain rule; see that function's doc-block).
 *  C3 (api.php): because PreviousData can now legitimately be the v2
 *      full-snapshot shape `{song:{...}}` (not just a bare tblSongs row),
 *      `restore_revision` unwraps that shape the same way
 *      `ed2_applySongSnapshot()` already does, AND gets an honest 409 for any
 *      shape it still can't handle — closing a pre-existing silent-success
 *      hole (an editor-payload-shaped PreviousData used to skip the whole
 *      UPDATE and still answer ok:true).
 *
 * If C2 landed without C3, a chained v2 snapshot would make the legacy
 * restore silently no-op with a SUCCESS response — worse than the 409 it
 * replaces. This test's assertions are written so that reverting EITHER half
 * turns it red (mutation-proven, rule #34), not just one.
 *
 * WHAT IT ASSERTS
 *   (1) api2.php's `ed2_touchRevision()` no longer hard-codes a literal NULL
 *       in its INSERT's VALUES list, and instead SELECTs the prior revision's
 *       NewData (`ORDER BY Id DESC LIMIT 1`) inside the same function body.
 *   (2) api.php's `restore_revision` case contains BOTH the `['song']` unwrap
 *       and the `!isset(...['Title'])` 409 guard, and the guard sits
 *       POSITIONALLY between the JSON-decode of PreviousData and the scalar
 *       `UPDATE tblSongs` — not after it (which would run a bad UPDATE first)
 *       and not missing (which reopens the silent-success hole).
 *   (3) `save_song_core.php` — which was ALREADY correct before #1743 — still
 *       binds a real `$previousData` variable (not a re-hardcoded NULL) in
 *       its own revision-row INSERT. This is a regression guard: nothing in
 *       #1743 touches this file, and this assertion is here so a FUTURE
 *       change can't quietly re-break the one writer that was already right.
 *
 * Extraction is by isolating the relevant function/case body first (balanced
 * braces for the PHP function, "next top-level case" for the switch case),
 * THEN asserting on that isolated text — not narrow proximity windows over
 * the raw 5000-line files. The #1671/rule-#34 lesson ("narrow windows
 * under-report") is why this test isolates the whole body rather than
 * hoping a short window happens to contain everything relevant.
 *
 *   php tests/php/test-revision-previous-data.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$root   = dirname(__DIR__, 2);
$editor = $root . '/appWeb/public_html/manage/editor';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  ❌ {$label}\n";
    }
}

/**
 * Extract a top-level PHP function's body (the text between its opening `{`
 * and the matching closing `}`), by counting brace depth char-by-char. Good
 * enough here because these are plain functions with no heredoc/nowdoc that
 * could contain unbalanced literal braces — verified against the real files.
 */
function extractFunctionBody(string $src, string $functionName): string
{
    $needle = 'function ' . $functionName . '(';
    $start = strpos($src, $needle);
    if ($start === false) { return ''; }
    $braceStart = strpos($src, '{', $start);
    if ($braceStart === false) { return ''; }
    $depth = 0;
    $len = strlen($src);
    for ($i = $braceStart; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; continue; }
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $braceStart, $i - $braceStart + 1);
            }
        }
    }
    return ''; /* unbalanced — treat as "not found" rather than guessing */
}

/**
 * Extract a `case '<name>':` block's body from a switch, bounded by the next
 * top-level `case '` at the SAME 4-space indent (mirrors the indent
 * convention this file already uses throughout its two switches — verified
 * against the real source, not assumed).
 */
function extractCaseBody(string $src, string $caseName): string
{
    $needle = "    case '{$caseName}':";
    $start = strpos($src, $needle);
    if ($start === false) { return ''; }
    $bodyStart = $start + strlen($needle);
    $next = strpos($src, "\n    case '", $bodyStart);
    return $next === false
        ? substr($src, $bodyStart)
        : substr($src, $bodyStart, $next - $bodyStart);
}

echo "\n#1743 — v2 revision PreviousData chain (api2.php write, api.php restore)\n\n";

$api2Src = (string)file_get_contents($editor . '/api2.php');
$api1Src = (string)file_get_contents($editor . '/api.php');
$coreSrc = (string)file_get_contents($editor . '/save_song_core.php');

/* Strip /* ... *\/ block comments before matching so a doc-block that
   DISCUSSES a literal NULL / a bind_param shape in prose (which this very
   file's own C2 doc-block now does, at length) cannot satisfy or defeat an
   assertion about the executable code. Line comments are left alone — PHP
   has none of the "//" -inside-string ambiguity JS string-parsing has to
   dodge here, and none of these three files' relevant lines carry one. */
$stripBlockComments = static function (string $s): string {
    return preg_replace('#/\*[\s\S]*?\*/#', '', $s) ?? $s;
};

/* =============================================================================
 * 1. api2.php — ed2_touchRevision() chains PreviousData
 * ============================================================================= */

$touchBodyRaw = extractFunctionBody($api2Src, 'ed2_touchRevision');
check('extracted ed2_touchRevision() function body from api2.php (vacuity check)', $touchBodyRaw !== '');
check('ed2_touchRevision() body is a plausible size (>= 300 chars — rule #34/#1671: narrow windows under-report)', strlen($touchBodyRaw) >= 300);

$touchBody = $stripBlockComments($touchBodyRaw);

/* (a) the prior-revision SELECT exists inside the function body. */
check(
    'ed2_touchRevision() SELECTs the prior revision\'s NewData (ORDER BY Id DESC LIMIT 1) before writing a new row',
    (bool)preg_match(
        '/SELECT\s+NewData\s+FROM\s+tblSongRevisions\s+WHERE\s+SongId\s*=\s*\?\s+ORDER\s+BY\s+Id\s+DESC\s+LIMIT\s+1/is',
        $touchBody
    )
);

/* (b) the INSERT's VALUES list no longer hard-codes a literal NULL for
   PreviousData — it must be a 5th bound placeholder instead. Isolate the
   INSERT statement itself (not the whole function body) so this assertion
   can't be satisfied by a stray NULL appearing elsewhere in the function
   (there is none today, but the check should mean what it says). */
$insertMatch = [];
$hasInsert = (bool)preg_match(
    '/INSERT\s+INTO\s+tblSongRevisions\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/is',
    $touchBody,
    $insertMatch
);
check('ed2_touchRevision() still contains the tblSongRevisions INSERT statement', $hasInsert);

$insertColumns = $hasInsert ? $insertMatch[1] : '';
$insertValues  = $hasInsert ? $insertMatch[2] : '';

check(
    'ed2_touchRevision()\'s INSERT column list includes PreviousData',
    (bool)preg_match('/\bPreviousData\b/i', $insertColumns)
);
check(
    'ed2_touchRevision()\'s INSERT VALUES list no longer contains a literal NULL (was hard-coded — the #1743 bug)',
    $hasInsert && !preg_match('/\bNULL\b/i', $insertValues)
);
check(
    'ed2_touchRevision()\'s INSERT VALUES list has 5 bound placeholders (SongId, UserId, Action, PreviousData, NewData)',
    $hasInsert && substr_count($insertValues, '?') === 5
);

/* (c) the bound variable feeding that 5th placeholder is a real
   $previousData, not e.g. a stray literal string — confirms the SELECT above
   and the INSERT below are actually wired together, not just both present. */
check(
    'ed2_touchRevision() binds a real $previousData variable into the INSERT (SELECT result actually feeds the write)',
    (bool)preg_match('/bind_param\s*\(\s*[\'"]sisss[\'"]\s*,\s*\$songId\s*,\s*\$userId\s*,\s*\$actionTag\s*,\s*\$previousData\s*,\s*\$newData\s*\)/', $touchBody)
);

/* Section-level mutation-proof note: reverting the INSERT to the literal
   `VALUES (?, ?, ?, NULL, ?, "approved")` — the exact pre-#1743 line — turns
   the "no literal NULL" assertion above red while everything else in this
   section stays green (the SELECT and the doc-block survive that one-line
   revert), which is what makes it a positional, not just an existential,
   guard on the INSERT specifically. */

/* =============================================================================
 * 2. api.php — restore_revision unwraps + honestly 409s on an unhandled shape
 * ============================================================================= */

$restoreBodyRaw = extractCaseBody($api1Src, 'restore_revision');
check('extracted restore_revision case body from api.php (vacuity check)', $restoreBodyRaw !== '');
check('restore_revision case body is a plausible size (>= 300 chars — rule #34/#1671)', strlen($restoreBodyRaw) >= 300);

$restoreBody = $stripBlockComments($restoreBodyRaw);

$posDecode = strpos($restoreBody, "\$restorePayload = \$row['PreviousData']");
$posUnwrap = strpos($restoreBody, "\$restorePayload['song']");
$posGuard  = strpos($restoreBody, "!isset(\$restorePayload['Title'])");
$posUpdate = strpos($restoreBody, 'UPDATE tblSongs SET Title=?');

check("restore_revision decodes \$row['PreviousData'] into \$restorePayload", $posDecode !== false);
check(
    "restore_revision unwraps the v2 full-snapshot {song:{...}} shape (mirrors ed2_applySongSnapshot()'s \$snap['song'] ?? \$snap)",
    $posUnwrap !== false
);
check(
    "restore_revision 409s on any remaining shape that still lacks 'Title' (closes the pre-#1743 silent-success hole)",
    $posGuard !== false
);
check('restore_revision still performs the scalar UPDATE tblSongs SET Title=... on the handled shape', $posUpdate !== false);

check(
    'the [\'song\'] unwrap runs AFTER the PreviousData JSON-decode and BEFORE the shape guard (positional)',
    $posDecode !== false && $posUnwrap !== false && $posGuard !== false
        && $posDecode < $posUnwrap && $posUnwrap < $posGuard
);
check(
    'the !isset(...[\'Title\']) 409 guard sits BETWEEN the JSON-decode and the scalar UPDATE (positional — the #1743-C3 requirement verbatim)',
    $posDecode !== false && $posGuard !== false && $posUpdate !== false
        && $posDecode < $posGuard && $posGuard < $posUpdate
);

/* Confirm the guard actually short-circuits (never falls through into the
   UPDATE for the un-handled shape) — it must answer via the same
   status-code mechanism the pre-existing NULL-409 uses (rule #35: branch on
   HTTP status, never prose), immediately followed by a `break;` out of the
   switch case so the scalar UPDATE below is never reached for that request. */
$guardWindowStart = $posGuard;
$guardWindow = $posGuard !== false ? substr($restoreBody, $guardWindowStart, 400) : '';
check(
    'the shape-guard 409 sets http_response_code(409) and breaks out of the case (never falls through to the UPDATE)',
    $guardWindow !== ''
        && (bool)preg_match('/http_response_code\s*\(\s*409\s*\)/', $guardWindow)
        && (bool)preg_match('/\bbreak\s*;/', $guardWindow)
);

/* =============================================================================
 * 3. save_song_core.php — regression guard: still a REAL $previousData, not a
 *    re-hardcoded NULL, in its OWN (pre-existing, already-correct) revision
 *    write. #1743 does not touch this file; this assertion exists purely so
 *    a future change cannot quietly re-break the one writer that was already
 *    right (the "Established facts" confirm-only finding in the #1743 plan).
 * ============================================================================= */

$coreNoComments = $stripBlockComments($coreSrc);

check(
    'save_song_core.php initialises $previousData = null only as a placeholder before the pre-edit row load',
    (bool)preg_match('/\$previousData\s*=\s*null\s*;/', $coreNoComments)
);
check(
    'save_song_core.php overwrites $previousData with json_encode($prevRow) whenever the song already exists',
    (bool)preg_match('/\$previousData\s*=\s*json_encode\s*\(\s*\$prevRow/', $coreNoComments)
);

$coreInsertMatch = [];
$coreHasInsert = (bool)preg_match(
    '/INSERT\s+INTO\s+tblSongRevisions\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/is',
    $coreNoComments,
    $coreInsertMatch
);
check('save_song_core.php still contains its own tblSongRevisions INSERT statement', $coreHasInsert);
check(
    'save_song_core.php\'s revision INSERT VALUES list contains no literal NULL for PreviousData (never re-hardcoded)',
    $coreHasInsert && !preg_match('/\bNULL\b/i', $coreInsertMatch[2])
);
check(
    'save_song_core.php binds the real $previousData variable (not a literal) into its revision INSERT',
    (bool)preg_match('/bind_param\s*\(\s*[\'"]sisss[\'"]\s*,\s*\$songId\s*,\s*\$userIdParam\s*,\s*\$action\s*,\s*\$previousData\s*,\s*\$newData\s*\)/', $coreNoComments)
);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\n#1743: ed2_touchRevision() must chain PreviousData from the prior revision's\n";
    echo "NewData (never a hard-coded NULL), and the legacy restore_revision case must\n";
    echo "unwrap the v2 snapshot shape AND honestly 409 on anything it still can't\n";
    echo "restore — never silently skip the UPDATE and still answer ok:true.\n";
    exit(1);
}
echo "\nAll #1743 revision-PreviousData assertions passed.\n";
