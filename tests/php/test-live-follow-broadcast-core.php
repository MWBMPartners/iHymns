<?php

declare(strict_types=1);

/**
 * iHymns — one-broadcaster-core guard (#1770 G4, req #4/#7)
 * ============================================================================
 * ELI5: whatever tells iHymns "show THIS song, at THIS point" has to write
 * the SAME two columns (`CurrentSongId`, `CurrentComponentIndex`) on
 * `tblLiveFollowSessions` the SAME way every time, or two writers could
 * quietly disagree about what "broadcasting" means. This checks that only
 * TWO places in the whole tree ever write those columns: the shared core
 * `serviceMode_applyBroadcast()` (which `service_broadcast` AND
 * `service_drive` both call — and any future writer must too) and
 * `live_follow_update`'s own deliberately-separate, code-scoped writer
 * (different WHERE shape, also rolls `ExpiresAt` — see
 * `serviceMode_applyBroadcast()`'s own doc-block for why it is NOT folded
 * in). A THIRD location — a planted inline `UPDATE` in some new endpoint —
 * must fail this guard the moment it is written, not be discovered later by
 * two features silently disagreeing about section state.
 *
 * DERIVATION (tree-derived, not a typed-in file list — rule #34): every
 * `.php` file under the scanned root is inspected for a
 * `->prepare(...)`/`->query(...)` call whose SQL text is an
 * `UPDATE tblLiveFollowSessions SET …` statement mentioning `CurrentSongId`
 * or `CurrentComponentIndex` in its SET list. Each hit is attributed to its
 * ENCLOSING function (`function NAME(...) { … }`) or, in api.php, its
 * enclosing `case 'action': { … }` dispatch block — whichever is smaller/
 * more specific. The set of DISTINCT enclosing-writer names found is then
 * compared against the allowed set.
 *
 * MUTATION PROOF (rule #34): see the commit body — a planted third inline
 * `UPDATE tblLiveFollowSessions SET CurrentSongId = ?, …` inside an
 * unrelated case block turns this guard red; removing it goes green again.
 *
 * @see appWeb/public_html/includes/service_mode.php  serviceMode_applyBroadcast()
 * @see .claude/live-follow-1770-plan.md §4.4, §9 G4
 */

$scanRoot = getenv('IHYMNS_SCAN_ROOT') ?: (dirname(__DIR__, 2) . '/appWeb/public_html');
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: could not read $scanRoot\n");
    exit(1);
}

$failures = 0;
$passed   = 0;
function lbc(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

function lbcStripComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/** Mirrors test-rate-limit-pairing.php's rlpCallArgs(). @return list<string>|null */
function lbcCallArgs(string $src, int $open): ?array
{
    $len = strlen($src);
    $depth = 0;
    $quote = '';
    $args  = [];
    $buf   = '';
    for ($i = $open; $i < $len; $i++) {
        $c = $src[$i];
        if ($quote !== '') {
            $buf .= $c;
            if ($c === '\\') { $i++; if ($i < $len) { $buf .= $src[$i]; } continue; }
            if ($c === $quote) { $quote = ''; }
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; $buf .= $c; continue; }
        if ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
            if ($depth === 1) { continue; }
            $buf .= $c;
            continue;
        }
        if ($c === ')' || $c === ']' || $c === '}') {
            $depth--;
            if ($depth === 0) { $args[] = trim($buf); return $args; }
            $buf .= $c;
            continue;
        }
        if ($c === ',' && $depth === 1) { $args[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    return null;
}

/** Concatenated content of $raw's quoted string literals; PHP glue collapsed to spaces. */
function lbcLiteralText(string $raw): string
{
    $len = strlen($raw);
    $out = '';
    $quote = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];
        if ($quote !== '') {
            if ($c === '\\') { $i++; if ($i < $len) { $out .= $raw[$i]; } continue; }
            if ($c === $quote) { $quote = ''; continue; }
            $out .= $c;
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; continue; }
        $out .= ' ';
    }
    return $out;
}

/** @return list<array{0:string,1:int,2:int}> [name, start, end) for every top-level function in $src. */
function lbcAllFunctionRanges(string $src): array
{
    $out = [];
    $offset = 0;
    while (preg_match('/function\s+([A-Za-z_]\w*)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $name = $m[1][0];
        $sigPos = $m[0][1];
        $offset = $sigPos + 1;
        $bracePos = strpos($src, '{', $sigPos);
        if ($bracePos === false) { continue; }
        $depth = 0;
        $len = strlen($src);
        for ($i = $bracePos; $i < $len; $i++) {
            if ($src[$i] === '{') { $depth++; }
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) { $out[] = [$name, $sigPos, $i + 1]; break; }
            }
        }
    }
    return $out;
}

/** @return list<array{0:string,1:int,2:int}> [action, start, end) for every `case 'x': {` block in $src. */
function lbcAllCaseRanges(string $src): array
{
    $out = [];
    $offset = 0;
    while (preg_match('/case\s+\'([a-z0-9_]+)\'\s*:/', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $name = $m[1][0];
        $pos = $m[0][1];
        $offset = $pos + 1;
        $nextPos = null;
        if (preg_match('/case\s+\'[a-z0-9_]+\'\s*:/', $src, $mNext, PREG_OFFSET_CAPTURE, $pos + 10)) {
            $nextPos = $mNext[0][1];
        }
        $out[] = [$name, $pos, $nextPos ?? strlen($src)];
    }
    return $out;
}

/** Smallest (most specific) enclosing name for $pos among a list of ranges, or null. */
function lbcEnclosing(int $pos, array $ranges): ?string
{
    $best = null;
    $bestSize = PHP_INT_MAX;
    foreach ($ranges as [$name, $start, $end]) {
        if ($pos >= $start && $pos < $end) {
            $size = $end - $start;
            if ($size < $bestSize) { $best = $name; $bestSize = $size; }
        }
    }
    return $best;
}

/* =========================================================================
 * SCAN — every .php file under $scanRoot
 * ========================================================================= */

$writers = [];   // "rel:line (enclosing)" per hit
$hitEnclosers = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
$scannedFiles = 0;
foreach ($it as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
    $path = $file->getPathname();
    if (strpos(str_replace('\\', '/', $path), '/vendor/') !== false) { continue; }
    $raw = (string)file_get_contents($path);
    if (strpos($raw, 'tblLiveFollowSessions') === false) { continue; }   // cheap pre-filter
    $scannedFiles++;
    $src = lbcStripComments($raw);
    $rel = ltrim(str_replace($scanRoot, '', $path), '/\\');

    $fnRanges   = lbcAllFunctionRanges($src);
    $caseRanges = (basename($path) === 'api.php') ? lbcAllCaseRanges($src) : [];

    foreach (['prepare', 'query'] as $fn) {
        $offset = 0;
        while (($pos = strpos($src, '->' . $fn . '(', $offset)) !== false) {
            $callOpen = $pos + strlen('->' . $fn);
            $offset = $pos + 1;
            $args = lbcCallArgs($src, $callOpen);
            if ($args === null || $args === []) { continue; }
            $sqlArg = $args[0];

            $texts = [];
            if ($sqlArg !== '' && ($sqlArg[0] === "'" || $sqlArg[0] === '"')) {
                $texts[] = lbcLiteralText($sqlArg);
            } elseif (preg_match('/^\$[A-Za-z_]\w*$/', $sqlArg)) {
                $re = '/' . preg_quote($sqlArg, '/') . '\s*=\s*(.+?);/s';
                if (preg_match_all($re, substr($src, 0, $pos), $mAssign)) {
                    foreach ($mAssign[1] as $rhs) { $texts[] = lbcLiteralText($rhs); }
                }
            }

            foreach ($texts as $sqlText) {
                if (!preg_match('/\bUPDATE\s+tblLiveFollowSessions\s+SET\b(.*)$/is', $sqlText, $mSet)) { continue; }
                $setClause = preg_split('/\bWHERE\b/i', $mSet[1], 2)[0];
                if (strpos($setClause, 'CurrentSongId') === false && strpos($setClause, 'CurrentComponentIndex') === false) {
                    continue;
                }
                $line = substr_count(substr($src, 0, $pos), "\n") + 1;
                $enclosing = lbcEnclosing($pos, array_merge($fnRanges, $caseRanges)) ?? '(top level)';
                $writers[] = "$rel:$line ($enclosing)";
                $hitEnclosers[$enclosing] = true;
            }
        }
    }
}

lbc($scannedFiles > 0, "0.1 scanned {$scannedFiles} PHP file(s) mentioning tblLiveFollowSessions under {$scanRoot}");
lbc($writers !== [], '0.2 found at least one UPDATE tblLiveFollowSessions SET (CurrentSongId|CurrentComponentIndex) writer (the scanner works)');

$allowed = ['serviceMode_applyBroadcast', 'live_follow_update'];
$found   = array_keys($hitEnclosers);
sort($found);
$expected = $allowed;
sort($expected);

lbc(
    $found === $expected,
    'G4 the ONLY writers of CurrentSongId/CurrentComponentIndex on tblLiveFollowSessions are '
        . implode(' + ', $allowed) . " — found: " . implode(', ', $found)
        . "\n  Sites:\n  - " . implode("\n  - ", $writers)
);

if ($failures) {
    fwrite(STDERR, "\n{$passed} passed, {$failures} failed\n");
    exit(1);
}
echo "\n{$passed} passed, 0 failed\n";
exit(0);
