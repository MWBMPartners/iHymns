<?php

declare(strict_types=1);

/**
 * iHymns — the unified CCLI licence resolver guard (#1668)
 * =======================================================
 *
 * ELI5
 * ----
 * "Does this person hold a valid CCLI licence?" must have exactly ONE answer,
 * produced by exactly ONE piece of code. This test proves that — first by
 * exercising the decision logic directly (no database needed), then by reading
 * the source tree and failing if a second copy of either the licence QUERY or
 * the CCLI FORMAT RULE has grown back somewhere else.
 *
 * WHAT WENT WRONG, AND WHY A GUARD (not a comment)
 * ------------------------------------------------
 * Before #1668 the question had THREE answers that did not agree:
 *   - includes/licences.php branch (b)      → any non-empty tblUsers.CcliNumber,
 *                                             org licences from a table with no
 *                                             writer, and NOT the org store the
 *                                             live admin UI actually writes;
 *   - contentGating_userHasCcli()           → its own SELECT, personal-only,
 *                                             and it DEMANDED CcliVerified;
 *   - api.php `tier_check`                  → a third, byte-identical copy of
 *                                             that same SELECT.
 * Two gates could therefore return OPPOSITE answers about the same user and the
 * same song, and organisation CCLI licences did not work AT ALL — the resolver
 * read `tblContentLicences` (no writer anywhere) and the legacy
 * `tblOrganisations` columns, while /manage/organisations,
 * /manage/my-organisations and the org_admin_licence_* API actions all write
 * `tblOrganisationLicences`. Severed at the storage layer, not merely ignored.
 *
 * Rule #35: cross-file agreement needs a MECHANISM. "Keep these in sync" in a
 * comment is the failure, not the fix. This file is the mechanism.
 *
 * THE FORMAT RULE IS FORMAT-ONLY, ON PURPOSE
 * ------------------------------------------
 * CCLI publishes no verification API and no check-digit algorithm, so nothing
 * in this project can set `tblUsers.CcliVerified` truthfully at scale. The
 * owner's criterion is therefore: a CCLI number must be all digits and of
 * normal length. That rule lives ONCE, in `validateCcliNumber()`
 * (includes/ccli_validator.php). Assertion group C below fails if any other
 * CCLI-handling code grows its own digits/length check.
 *
 * HOW THE STRUCTURAL CHECKS ARE DERIVED (rule #34)
 * ------------------------------------------------
 * Nothing here is a hand-typed list of files to look at. The tree is walked,
 * every .php file is tokenised, and the questions are asked of what is actually
 * there. Tokenising rather than grepping matters: this file's own doc-blocks —
 * and licences.php's, and content_gating.php's — quote the very SQL the guard
 * bans. A `grep` would flag the comments explaining the fix as the fix's
 * absence. Comments are separate tokens, so they are invisible here.
 *
 * ⚠️ THE BRACE-DEPTH TRAP (shared with tests/php/lib/dispatch_parser.php):
 * T_CURLY_OPEN and T_DOLLAR_OPEN_CURLY_BRACES open a brace but close with a
 * plain '}'. Count only '{' and every walk unwinds early and silently
 * under-reports. See that file's header for the incident.
 *
 *   php tests/php/test-ccli-resolver.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @link https://www.php.net/manual/en/function.token-get-all.php
 * @link https://www.php.net/manual/en/function.ctype-digit.php
 */

require_once __DIR__ . '/lib/dispatch_parser.php';   /* dispatchParserCaseTokens/IsOpenBrace */

$ROOT    = dirname(__DIR__, 2);
$APPWEB  = $ROOT . '/appWeb';
$PUBLIC  = $APPWEB . '/public_html';

$failures = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $failures++;
        if ($detail !== '') {
            foreach (explode("\n", rtrim($detail)) as $line) {
                echo "          " . $line . "\n";
            }
        }
    }
}

echo "\nCCLI resolver guard — one query, one format rule, one answer (#1668)\n\n";

/* =======================================================================
 * SHARED TOKEN MACHINERY
 * ======================================================================= */

/** Every .php file under a directory, recursively, sorted for stable output. */
function ccliPhpFiles(string $dir): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

/**
 * String LITERALS in a token stream, with `'a' . 'b'` concatenation runs joined
 * into one candidate. A split-string SQL query is the obvious way to evade a
 * naive per-literal scan, so runs are folded before matching.
 *
 * Heredoc bodies arrive as T_ENCAPSED_AND_WHITESPACE and are treated as their
 * own candidates. Comments are NOT string tokens, so prose that quotes the
 * banned SQL (this file, licences.php's header, …) never matches — which is the
 * whole reason this tokenises instead of grepping.
 *
 * @return array<int,array{text:string,idx:int}> text + first token index
 */
function ccliStringRuns(array $toks): array
{
    $runs = [];
    $n    = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t)) { continue; }
        if ($t[0] !== T_CONSTANT_ENCAPSED_STRING && $t[0] !== T_ENCAPSED_AND_WHITESPACE) {
            continue;
        }
        $start = $i;
        $text  = trim($t[1], "'\"");
        /* Walk forward over `. 'more'` continuations, skipping only whitespace
           and comments — anything else ends the run. */
        $j = $i + 1;
        while ($j < $n) {
            $u = $toks[$j];
            if (is_array($u) && in_array($u[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
                continue;
            }
            if ($u === '.') {
                $k = $j + 1;
                while ($k < $n && is_array($toks[$k])
                       && in_array($toks[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $k++;
                }
                if ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $text .= trim($toks[$k][1], "'\"");
                    $j = $k + 1;
                    continue;
                }
            }
            break;
        }
        $runs[] = ['text' => $text, 'idx' => $start];
        $i = $j - 1;
    }
    return $runs;
}

/**
 * Token-index ranges of every `try { … }` block. Used to prove that an optional
 * table read is existence-gated rather than free to throw: under mysqli STRICT
 * (db_mysql.php) a missing table THROWS, migrations here are web-run and never
 * auto-applied, and the three docroots share one MySQL — so an ungated read is
 * a 500 on a real install, not a theoretical one (#1228).
 *
 * @return array<int,array{0:int,1:int}>
 */
function ccliTryRanges(array $toks): array
{
    $ranges = [];
    $n      = count($toks);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($toks[$i]) || $toks[$i][0] !== T_TRY) { continue; }
        /* Find the opening brace of the try body. */
        $j = $i;
        while ($j < $n && $toks[$j] !== '{') { $j++; }
        if ($j >= $n) { continue; }
        $depth = 0;
        for ($k = $j; $k < $n; $k++) {
            if (dispatchParserIsOpenBrace($toks[$k])) { $depth++; }
            elseif ($toks[$k] === '}') {
                $depth--;
                if ($depth === 0) { $ranges[] = [$j, $k]; break; }
            }
        }
    }
    return $ranges;
}

/**
 * The units a "did somebody re-implement this?" question is asked of.
 *
 * A named FUNCTION is the natural unit — validateCcliNumber() itself cleans the
 * input on one line and calls ctype_digit() on another, so a statement-level
 * scope would miss exactly the shape it is looking for. But api.php's CCLI code
 * is not in functions at all: it lives in `case 'ccli_validate':` arms of a
 * top-level switch. Scoping those to the whole file would be useless (api.php
 * legitimately calls ctype_digit for person ids, songbook numbers, ports …), so
 * top-level code in a dispatch surface is split at its `case` boundaries using
 * the shared parser, and top-level code elsewhere becomes one unit per file.
 *
 * @return array<int,array{name:string,from:int,to:int}>
 */
function ccliScanUnits(string $file, array $toks): array
{
    $n     = count($toks);
    $units = [];
    $covered = [];   /* token indexes inside some function body */

    for ($i = 0; $i < $n; $i++) {
        if (!is_array($toks[$i]) || $toks[$i][0] !== T_FUNCTION) { continue; }
        /* Name: next T_STRING before the '('. Anonymous functions have none —
           they fold into whatever unit encloses them, which is correct. */
        $name = '';
        $j    = $i + 1;
        while ($j < $n && $toks[$j] !== '(') {
            if (is_array($toks[$j]) && $toks[$j][0] === T_STRING) { $name = $toks[$j][1]; break; }
            $j++;
        }
        if ($name === '') { continue; }
        /* Body: first '{' after the signature, matched to its '}'. An abstract
           or interface method ends at ';' and has no body. */
        $b = $i;
        while ($b < $n && $toks[$b] !== '{' && $toks[$b] !== ';') { $b++; }
        if ($b >= $n || $toks[$b] === ';') { continue; }
        $depth = 0;
        $end   = $b;
        for ($k = $b; $k < $n; $k++) {
            if (dispatchParserIsOpenBrace($toks[$k])) { $depth++; }
            elseif ($toks[$k] === '}') {
                $depth--;
                if ($depth === 0) { $end = $k; break; }
            }
        }
        $units[] = ['name' => $name, 'from' => $i, 'to' => $end];
        for ($k = $i; $k <= $end; $k++) { $covered[$k] = true; }
    }

    /* Top-level remainder. */
    $caseIdx = [];
    foreach (['$action', '$page'] as $switchVar) {
        foreach (dispatchParserCaseTokens($file, $switchVar) as $c) { $caseIdx[] = $c['index']; }
    }
    sort($caseIdx);
    if ($caseIdx !== []) {
        $count = count($caseIdx);
        for ($c = 0; $c < $count; $c++) {
            $units[] = [
                'name' => '<case@' . $caseIdx[$c] . '>',
                'from' => $caseIdx[$c],
                'to'   => ($c + 1 < $count) ? $caseIdx[$c + 1] - 1 : $n - 1,
            ];
        }
    } else {
        /* CONTIGUOUS runs of uncovered tokens — NOT one span from the first to
           the last, which would swallow every function body in between and turn
           the unit into "the whole file". That bug made the first version of
           this guard report nineteen re-implementations, all of them the file
           merely CONTAINING both a CCLI mention and an unrelated ctype_digit. */
        $runStart = null;
        for ($i = 0; $i <= $n; $i++) {
            $isTop = ($i < $n) && !isset($covered[$i]);
            if ($isTop && $runStart === null) { $runStart = $i; }
            if (!$isTop && $runStart !== null) {
                $units[]  = ['name' => '<toplevel>', 'from' => $runStart, 'to' => $i - 1];
                $runStart = null;
            }
        }
    }
    return $units;
}

/**
 * Arguments of a call to $fn inside a token range, as token sub-arrays.
 * Used to ask "what is this digits test being applied TO?" — the difference
 * between a CCLI format fork and a perfectly ordinary verse-number parse.
 *
 * @return array<int,array<int,mixed>> one entry per call, each the arg tokens
 */
function ccliCallArgs(array $toks, int $from, int $to, string $fn): array
{
    $calls = [];
    $n     = min($to, count($toks) - 1);
    for ($i = $from; $i <= $n; $i++) {
        if (!is_array($toks[$i]) || $toks[$i][0] !== T_STRING
            || strcasecmp($toks[$i][1], $fn) !== 0) {
            continue;
        }
        $j = $i + 1;
        while ($j <= $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; }
        if ($j > $n || $toks[$j] !== '(') { continue; }
        $depth = 0;
        $args  = [];
        for ($k = $j; $k <= $n; $k++) {
            if ($toks[$k] === '(') { $depth++; if ($depth === 1) { continue; } }
            elseif ($toks[$k] === ')') { $depth--; if ($depth === 0) { break; } }
            $args[] = $toks[$k];
        }
        $calls[] = $args;
    }
    return $calls;
}

/** Does this token slice mention a variable whose NAME contains "ccli"? */
function ccliArgsNameCcli(array $args): bool
{
    foreach ($args as $t) {
        if (is_array($t) && $t[0] === T_VARIABLE && stripos($t[1], 'ccli') !== false) {
            return true;
        }
    }
    return false;
}

/** Flatten a token range back to source text (for substring questions). */
function ccliUnitSrc(array $toks, int $from, int $to): string
{
    $s = '';
    for ($i = $from; $i <= $to && $i < count($toks); $i++) {
        $s .= is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
    }
    return $s;
}

/* Tokenise every file once. */
$FILES = ccliPhpFiles($APPWEB);
$TOKS  = [];
foreach ($FILES as $f) { $TOKS[$f] = token_get_all((string)file_get_contents($f)); }

ok('walked a plausible slice of the tree (>= 200 php files)', count($FILES) >= 200,
   'got ' . count($FILES));

/* =======================================================================
 * GROUP A — the decision logic, exercised directly. No database.
 *
 * licences.php requires db_mysql.php + ccli_validator.php, but neither opens a
 * connection at include time (getDbMysqli() is lazy), so the pure predicates
 * can be called for real rather than re-modelled here.
 * ======================================================================= */
$_SERVER['SCRIPT_FILENAME'] = __FILE__;          /* clears the direct-access guards */
require_once $PUBLIC . '/includes/licences.php';

/* Presence FIRST. Calling a renamed/removed function is a FATAL, which aborts
   the whole run — so a mutation that deletes the resolver would kill the guard
   at A12 and every structural assertion after it would simply never execute.
   A guard that dies is technically red, but it stops REPORTING, and this file's
   job is to say what broke. Found by mutation-testing this very file. */
$REQUIRED = [
    'validateCcliNumber', 'licenceCcliQualifies', 'licenceOrgRowQualifies',
    'licenceTypesFromSet', 'userHasValidCcli', 'getUserEffectiveLicences',
    'getUserEffectiveLicenceTypes', 'userHasEffectiveLicence',
];
$missingFns = array_values(array_filter(
    $REQUIRED, static fn(string $f): bool => !function_exists($f)));
ok('A0  every resolver entry point is defined', $missingFns === [],
   'missing: ' . implode(', ', $missingFns));

if ($missingFns !== []) {
    fwrite(STDERR, "\nSkipping the behavioural group — the resolver API is incomplete.\n");
} else {

ok('A1  a well-formed personal number qualifies',
   licenceCcliQualifies('1234567', true) === true);
ok('A2  a blank PERSONAL number does not (the number IS the licence)',
   licenceCcliQualifies('', true) === false);
ok('A3  whitespace-only is blank, not a number',
   licenceCcliQualifies("  \t ", true) === false);
ok('A4  a blank ORG number still qualifies (the row is the declaration)',
   licenceCcliQualifies('', false) === true);
ok('A5  letters are not a CCLI number',
   licenceCcliQualifies('abc', true) === false);
ok('A6  "n/a" in an ORG row is not a licence (present but malformed)',
   licenceCcliQualifies('n/a', false) === false);
ok('A7  too short (4 digits) is rejected',
   licenceCcliQualifies('1234', true) === false);
ok('A8  too long (9 digits) is rejected',
   licenceCcliQualifies('123456789', true) === false);
ok('A9  formatting is tolerated — "CCLI# 123-456" normalises to 123456',
   licenceCcliQualifies('CCLI# 123-456', true) === true);
ok('A10 non-ccli licence types pass through untouched',
   licenceOrgRowQualifies('ihymns_pro', '') === true
   && licenceOrgRowQualifies('mrl', 'anything') === true);
ok('A11 a ccli row with a malformed number is dropped',
   licenceOrgRowQualifies('ccli', 'pending') === false);
ok('A12 anonymous (null user) never holds a CCLI licence',
   userHasValidCcli(null) === false);
ok('A13 licenceTypesFromSet de-duplicates and preserves first-seen order',
   licenceTypesFromSet([
       ['type' => 'ccli'], ['type' => 'ihymns_pro'], ['type' => 'ccli'],
   ]) === ['ccli', 'ihymns_pro']);

/* THE CORRECTION THIS BATCH TOOK MID-FLIGHT (owner, 2026-07-30): validity is
   FORMAT + presence, never a verification flag. Asserted structurally so a
   future "hardening" pass cannot quietly reintroduce the precondition: the
   qualifier takes a number and a require-flag, and nothing else. */
$sig = new ReflectionFunction('licenceCcliQualifies');
ok('A14 the CCLI qualifier takes (number, requireNumber) only — CcliVerified is NOT an input',
   $sig->getNumberOfParameters() === 2
   && $sig->getParameters()[0]->getName() === 'number'
   && $sig->getParameters()[1]->getName() === 'requireNumber',
   'params: ' . implode(', ', array_map(
       static fn(ReflectionParameter $p): string => $p->getName(), $sig->getParameters())));

}   /* end of the behavioural group */

/* =======================================================================
 * GROUP B — ONE personal-CCLI QUERY.
 *
 * A SQL string that reads CcliNumber and CcliVerified together may exist in at
 * most one file, and that file must be the resolver. This is what stops C3's
 * de-duplication from being undone by the next person who needs the answer and
 * writes the SELECT again rather than calling userHasValidCcli().
 * ======================================================================= */
$personalSqlFiles = [];
foreach ($FILES as $f) {
    foreach (ccliStringRuns($TOKS[$f]) as $run) {
        $t = $run['text'];
        if (stripos($t, 'select') !== false
            && stripos($t, 'CcliNumber') !== false
            && stripos($t, 'CcliVerified') !== false) {
            $personalSqlFiles[$f] = true;
        }
    }
}
$personalSqlFiles = array_keys($personalSqlFiles);
$rel = static fn(string $p): string => str_replace($ROOT . '/', '', $p);

ok('B1  the personal-CCLI SELECT (CcliNumber + CcliVerified) exists in AT MOST one file',
   count($personalSqlFiles) <= 1,
   "found in:\n  - " . implode("\n  - ", array_map($rel, $personalSqlFiles)));
ok('B2  …and that file is includes/licences.php, the resolver',
   $personalSqlFiles === [] || $personalSqlFiles === [$PUBLIC . '/includes/licences.php'],
   implode(', ', array_map($rel, $personalSqlFiles)));

/* =======================================================================
 * GROUP C — ONE CCLI FORMAT RULE.
 *
 * validateCcliNumber() is the only code allowed to decide what a CCLI number
 * looks like. Any OTHER unit that mentions CCLI and also performs a digits /
 * numeric test is re-implementing it — the fork this batch exists to remove.
 *
 * ⚠️ NARROWNESS IS THE WHOLE DESIGN HERE (rule #34: a guard so blunt it fails
 * on correct code gets weakened or deleted rather than fixed). The first draft
 * flagged any unit that mentioned "ccli" and contained a digit test anywhere —
 * and reported NINETEEN violations, every one of them legitimate: importers
 * that read a `CCLI` tag out of a file AND separately parse verse numbers with
 * ctype_digit, a scripture-reference matcher, a media route's `\d` path regex.
 *
 * So the question asked is not "does this code do a digit test?" but "does this
 * code do a digit test ON A CCLI VALUE?" — the digits/numeric call must be
 * applied to a variable whose NAME says ccli, or the regex must be matched
 * against one. That is the shape a genuine re-implementation takes
 * (`ctype_digit($ccliNumber)`, `preg_match('/^\d{5,8}$/', $ccli)`), and it is
 * one no correct code in this tree does today.
 * ======================================================================= */
$formatForks = [];
$validateDefs = [];
foreach ($FILES as $f) {
    foreach (ccliScanUnits($f, $TOKS[$f]) as $u) {
        if ($u['name'] === 'validateCcliNumber') { $validateDefs[] = $f; continue; }

        /* (i) ctype_digit / is_numeric applied to a ccli-named variable. */
        foreach (['ctype_digit', 'is_numeric'] as $fn) {
            foreach (ccliCallArgs($TOKS[$f], $u['from'], $u['to'], $fn) as $args) {
                if (ccliArgsNameCcli($args)) {
                    $formatForks[] = $rel($f) . ' :: ' . $u['name'] . "  ({$fn} on a CCLI value)";
                }
            }
        }
        /* (ii) a digits-only regex matched against a ccli-named variable. */
        foreach (['preg_match', 'preg_match_all'] as $fn) {
            foreach (ccliCallArgs($TOKS[$f], $u['from'], $u['to'], $fn) as $args) {
                if (!ccliArgsNameCcli($args)) { continue; }
                foreach ($args as $t) {
                    if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING
                        && (str_contains($t[1], '\\d') || str_contains($t[1], '[0-9]'))) {
                        $formatForks[] = $rel($f) . ' :: ' . $u['name']
                                       . '  (digits regex on a CCLI value)';
                        break;
                    }
                }
            }
        }
    }
}
$formatForks = array_values(array_unique($formatForks));

ok('C1  validateCcliNumber() is defined exactly once in the tree',
   count($validateDefs) === 1,
   implode(', ', array_map($rel, $validateDefs)));
ok('C2  no other CCLI-handling unit re-implements the digits / length rule',
   $formatForks === [],
   "re-implementations found:\n  - " . implode("\n  - ", $formatForks));
/* Asked of TOKENS, not source text. The first version tested
   `str_contains($src, 'validateCcliNumber(')` and stayed GREEN when the real
   call was replaced with a hand-rolled `strlen($n) >= 5` — because the file's
   own doc-block still says "validateCcliNumber() in includes/ccli_validator.php"
   and that substring matched the comment. A guard satisfied by a comment
   describing the mechanism, rather than the mechanism, is exactly the failure
   mode rule #35 is about. Caught by mutation M5. */
$licToks    = $TOKS[$PUBLIC . '/includes/licences.php'];
$licCallsIt = false;
for ($i = 0, $n = count($licToks); $i < $n; $i++) {
    if (!is_array($licToks[$i]) || $licToks[$i][0] !== T_STRING) { continue; }
    if ($licToks[$i][1] !== 'validateCcliNumber') { continue; }
    $j = $i + 1;
    while ($j < $n && is_array($licToks[$j]) && $licToks[$j][0] === T_WHITESPACE) { $j++; }
    if ($j < $n && $licToks[$j] === '(') { $licCallsIt = true; break; }
}
ok('C3  the resolver DELEGATES the format rule (a real call to validateCcliNumber)',
   $licCallsIt);

/* =======================================================================
 * GROUP D — ONE RESOLVER, and every call site pointed at it.
 * ======================================================================= */
$resolverDefs = [];
$callers      = [];
foreach ($FILES as $f) {
    foreach (ccliScanUnits($f, $TOKS[$f]) as $u) {
        if ($u['name'] === 'userHasValidCcli') { $resolverDefs[] = $f; }
        $src = ccliUnitSrc($TOKS[$f], $u['from'], $u['to']);
        if ($u['name'] !== 'userHasValidCcli' && str_contains($src, 'userHasValidCcli(')) {
            $callers[$rel($f)][] = $u['name'];
        }
    }
}
ok('D1  userHasValidCcli() is defined exactly once',
   count($resolverDefs) === 1, implode(', ', array_map($rel, $resolverDefs)));
ok('D2  …in includes/licences.php',
   $resolverDefs === [$PUBLIC . '/includes/licences.php'],
   implode(', ', array_map($rel, $resolverDefs)));

/* contentGating_userHasCcli must DELEGATE — call the resolver, own no SQL. */
$cgFile = $PUBLIC . '/includes/content_gating.php';
$cgUnit = null;
foreach (ccliScanUnits($cgFile, $TOKS[$cgFile]) as $u) {
    if ($u['name'] === 'contentGating_userHasCcli') { $cgUnit = $u; }
}
ok('D3  contentGating_userHasCcli() still exists', $cgUnit !== null);
if ($cgUnit !== null) {
    $cgSrc = ccliUnitSrc($TOKS[$cgFile], $cgUnit['from'], $cgUnit['to']);
    ok('D4  …and delegates to userHasValidCcli()', str_contains($cgSrc, 'userHasValidCcli('));
    $ownSql = false;
    foreach (ccliStringRuns($TOKS[$cgFile]) as $run) {
        if ($run['idx'] < $cgUnit['from'] || $run['idx'] > $cgUnit['to']) { continue; }
        if (stripos($run['text'], 'select') !== false) { $ownSql = true; }
    }
    ok('D5  …and runs no SQL of its own', $ownSql === false);
    ok('D6  …and still fails CLOSED (catches Throwable, returns false)',
       str_contains($cgSrc, 'catch') && preg_match('/catch[^{]*\{\s*(?:\/\*.*?\*\/\s*)*return\s+false\s*;/s', $cgSrc) === 1);
}

/* api.php's tier_check case must ask the resolver. */
$apiFile   = $PUBLIC . '/api.php';
$tierCheck = null;
foreach (ccliScanUnits($apiFile, $TOKS[$apiFile]) as $u) {
    $src = ccliUnitSrc($TOKS[$apiFile], $u['from'], $u['to']);
    if (str_starts_with($u['name'], '<case@') && str_contains($src, "'tier_check'")
        && str_contains($src, 'checkTierAccess(')) {
        $tierCheck = $src;
    }
}
ok('D7  api.php still dispatches a tier_check case', $tierCheck !== null);
if ($tierCheck !== null) {
    ok('D8  …and resolves $hasCcli via userHasValidCcli()',
       str_contains($tierCheck, 'userHasValidCcli('));
}

/* =======================================================================
 * GROUP E — the organisation store is actually read (the #1668 fix itself),
 * and every read of it is existence-gated.
 * ======================================================================= */
$licFile = $PUBLIC . '/includes/licences.php';
$licReadsOrgStore = false;
foreach (ccliStringRuns($TOKS[$licFile]) as $run) {
    if (stripos($run['text'], 'tblOrganisationLicences') !== false
        && stripos($run['text'], 'select') !== false) {
        $licReadsOrgStore = true;
    }
}
ok('E1  the resolver READS tblOrganisationLicences — the store the live UI writes',
   $licReadsOrgStore);

/* Every includes/ read of the optional table must be STRICT-safe. Three shapes
   count as safe, because all three actually are:
     (a) the query literal sits inside a try block;
     (b) the ENCLOSING FUNCTION catches — ccli_validator.php builds its recursive
         CTE into $sqlRecursive on one line and prepares it inside a try several
         lines later, so a literal-position-only test called it ungated when it
         is not. That false positive is why (b) exists;
     (c) the file carries an INFORMATION_SCHEMA probe for the table.
   Derived from the tree, so a NEW ungated read anywhere under includes/ fails
   too — not just the two files this batch touched. */
$ungated = [];
foreach ($FILES as $f) {
    if (!str_starts_with($f, $PUBLIC . '/includes/')) { continue; }
    $runs = ccliStringRuns($TOKS[$f]);
    $hasProbe = false;
    foreach ($runs as $run) {
        if (stripos($run['text'], 'INFORMATION_SCHEMA') !== false
            && stripos($run['text'], 'tblOrganisationLicences') !== false) {
            $hasProbe = true;
        }
    }
    if ($hasProbe) { continue; }
    $tries = ccliTryRanges($TOKS[$f]);
    $units = ccliScanUnits($f, $TOKS[$f]);
    foreach ($runs as $run) {
        $t = $run['text'];
        if (stripos($t, 'tblOrganisationLicences') === false)  { continue; }
        if (stripos($t, 'INFORMATION_SCHEMA') !== false)       { continue; }
        if (!preg_match('/\b(from|join|into|update)\b/i', $t)) { continue; }
        $safe = false;
        foreach ($tries as [$a, $b]) {
            if ($run['idx'] >= $a && $run['idx'] <= $b) { $safe = true; break; }
        }
        if (!$safe) {
            foreach ($units as $u) {
                if ($run['idx'] < $u['from'] || $run['idx'] > $u['to']) { continue; }
                /* A real T_CATCH token, NOT the substring "catch". The first
                   version tested `str_contains($src, 'catch')` and stayed GREEN
                   after the try/catch was deleted — because a comment inside the
                   very function it was checking says "no try/catch of its own".
                   Second time in this file a text search was satisfied by prose
                   describing the mechanism instead of the mechanism (see C3).
                   Caught by mutation M11. */
                for ($k = $u['from']; $k <= $u['to']; $k++) {
                    if (is_array($TOKS[$f][$k]) && $TOKS[$f][$k][0] === T_CATCH) {
                        $safe = true;
                        break 2;
                    }
                }
            }
        }
        if (!$safe) { $ungated[] = $rel($f); }
    }
}
ok('E2  every includes/ read of tblOrganisationLicences is existence-gated (try / probe)',
   $ungated === [],
   "ungated (mysqli STRICT would THROW on an un-migrated install):\n  - "
   . implode("\n  - ", array_unique($ungated)));

/* =======================================================================
 * GROUP F — rule #26: the Service-Mode Phase-3 unlock stays Channel-scoped.
 *
 * C4 rewrote that query to accept both org stores. An un-Channel-filtered
 * presence query is the cross-env leak class: three docroots share one MySQL,
 * so a token minted on dev must never unlock content on www.
 * ======================================================================= */
$smFile = $PUBLIC . '/includes/service_mode.php';
$smUnit = null;
foreach (ccliScanUnits($smFile, $TOKS[$smFile]) as $u) {
    if ($u['name'] === 'serviceMode_presenceCcliNumber') { $smUnit = $u; }
}
ok('F1  serviceMode_presenceCcliNumber() still exists', $smUnit !== null);
if ($smUnit !== null) {
    $presenceQueries = 0;
    $unscoped        = [];
    foreach (ccliStringRuns($TOKS[$smFile]) as $run) {
        if ($run['idx'] < $smUnit['from'] || $run['idx'] > $smUnit['to']) { continue; }
        if (stripos($run['text'], 'tblServicePresence') === false)      { continue; }
        $presenceQueries++;
        if (!preg_match('/\bp\.Channel\s*=\s*\?/', $run['text'])) {
            $unscoped[] = substr(preg_replace('/\s+/', ' ', $run['text']), 0, 90) . '…';
        }
    }
    ok('F2  it queries tblServicePresence at least once', $presenceQueries >= 1,
       'found ' . $presenceQueries);
    ok('F3  EVERY such query is Channel-scoped (rule #26)', $unscoped === [],
       "unscoped:\n  - " . implode("\n  - ", $unscoped));
    ok('F4  it accepts the tblOrganisationLicences store too (#1668)',
       str_contains(ccliUnitSrc($TOKS[$smFile], $smUnit['from'], $smUnit['to']),
                    'tblOrganisationLicences'));
    ok('F5  …behind an existence gate, so an un-migrated install keeps the legacy unlock',
       str_contains(ccliUnitSrc($TOKS[$smFile], $smUnit['from'], $smUnit['to']),
                    'serviceMode_orgLicencesTableExists('));
}

/* =======================================================================
 * GROUP G — C5: the dead ccli_validation_enabled flag is gone, with a way for
 * existing installs to catch up. schema.sql edits never reach a live install
 * (the seed is INSERT IGNORE), so the migration is the only mechanism.
 * ======================================================================= */
$schemaSql = (string)file_get_contents($APPWEB . '/.sql/schema.sql');
ok('G1  schema.sql no longer SEEDS ccli_validation_enabled',
   !str_contains($schemaSql, "('ccli_validation_enabled'"));
ok('G2  the full-data dump no longer seeds it either',
   !str_contains((string)file_get_contents($APPWEB . '/.sql/.fulldata/ihymns-full.sql'),
                 "('ccli_validation_enabled'"));
ok('G3  a cleanup migration exists for installs that already have the row',
   is_file($APPWEB . '/.sql/migrate-remove-ccli-validation-setting.php'));
$registrySrc = (string)file_get_contents($PUBLIC . '/manage/includes/migration-registry.php');
ok('G4  …and it is registered, so /manage/setup-database can run it',
   str_contains($registrySrc, 'migrate-remove-ccli-validation-setting.php'));
/* Nobody may READ the key again. Asked of string LITERALS, not raw text: the
   removal migration, the registry card and this test all NAME the key in prose
   and must not count as readers. The two files that legitimately carry it as a
   literal are the migration that deletes the row and the registry probe that
   looks for it — anything else is somebody wiring the dead flag back up. */
$keyLiteralFiles = [];
foreach ($FILES as $f) {
    foreach (ccliStringRuns($TOKS[$f]) as $run) {
        if (str_contains($run['text'], 'ccli_validation_enabled')) { $keyLiteralFiles[$f] = true; }
    }
}
$keyLiteralFiles = array_map($rel, array_keys($keyLiteralFiles));
sort($keyLiteralFiles);
ok('G5  the removed key survives only in the migration that deletes it and its probe',
   $keyLiteralFiles === [
       'appWeb/.sql/migrate-remove-ccli-validation-setting.php',
       'appWeb/public_html/manage/includes/migration-registry.php',
   ],
   "string literals found in:\n  - " . implode("\n  - ", $keyLiteralFiles));

if ($failures === 0) {
    echo "\nAll CCLI resolver assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
exit(1);
