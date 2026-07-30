<?php

declare(strict_types=1);

/**
 * iHymns — shared dispatch-surface parser (tests only)
 * ====================================================
 *
 * ELI5
 * ----
 * Our server answers requests like `?action=song_detail`. This file is the one
 * piece of code that reads our PHP source and works out, honestly, which
 * `action=` names the server actually answers to — so two different tests can
 * ask the same question and always get the same answer.
 *
 * WHY THIS EXISTS
 * ---------------
 * The tokenising switch-walker below was written for
 * `tests/php/test-openapi-actions-exist.php` (#06169c7c) and is now needed by
 * `tests/php/test-orphan-inventory.php` as well. Rather than let a second copy
 * grow — CLAUDE.md's modularity rule, and rule #35's "cross-file agreement needs
 * a mechanism, not a comment" — it lives here once and both tests consume it.
 *
 * A REGEX CANNOT DO THIS, and the reason is a real incident.
 * `api.php` has TWO switches:
 *
 *     line 563   switch ($page)      <- `?page=song` renders an HTML fragment
 *     line 763   switch ($action)    <- `?action=song_detail` returns JSON
 *
 * `case 'song':` exists — in the PAGE switch. A `grep "case 'song'"` therefore
 * "confirms" a `?action=song` endpoint that has never existed. Four documented
 * endpoints hid behind exactly that confusion (orphan inventory §6.1). Only the
 * position of a `case` relative to its switch tells you which dispatcher owns
 * it, which is why this parses rather than greps.
 *
 * ⚠️ THE BRACE-DEPTH TRAP (do not "simplify" this)
 * `T_CURLY_OPEN` and `T_DOLLAR_OPEN_CURLY_BRACES` must count as opening braces.
 * String interpolation — "{$var}" and "${var}" — OPENS with one of those tokens
 * but CLOSES with a plain '}' string. Counting only '{' under-counts opens while
 * still counting every close, so the depth unwinds early and the walk abandons
 * the switch part-way. That is not hypothetical: the first version of the
 * openapi test reported 29 actions instead of ~275. It failed loudly that time;
 * it could just as easily have over-reported and passed silently.
 * https://www.php.net/manual/en/tokens.php
 * https://www.php.net/manual/en/function.token-get-all.php
 *
 * WHAT IT MODELS
 * --------------
 *  1. `switch ($action) { case 'x': … }`               — the two API surfaces.
 *  2. `if ($action === 'x')` / `elseif` chains          — places-api.php, and a
 *     dozen self-posting `/manage/*.php` pages.
 *  3. `if (($_POST['action'] ?? '') === 'x')`           — the same, without a
 *     local variable (activity-log.php, data-health.php).
 *  4. `in_array($action, ['x', 'y'], true)`             — setup-database.php,
 *     gating-noop-verify.php.
 *
 * Every extracted label also carries the TOKEN INDEX it came from. That is what
 * lets a consumer say "this string is a dispatch label, not a caller" without
 * throwing away the rest of the file — self-posting admin pages emit their own
 * `<input name="action" value="update_person">`, so the dispatch file is both
 * the handler AND the caller, and a blanket "ignore the dispatch file" rule
 * would report all 24 of them as orphans.
 *
 * This file is a LIBRARY, not a test. `tools/run-php-tests.php` globs
 * `tests/php/*.php` non-recursively, so nothing under `tests/php/lib/` is ever
 * executed as a suite.
 */

/* Token constants that only exist on some versions. PHP 8.4 still defines
   T_DOLLAR_OPEN_CURLY_BRACES (the "${var}" syntax is deprecated, not removed),
   but a future PHP could drop it — and an undefined constant would be a fatal,
   turning a guard into a build break for the wrong reason. */
if (!defined('T_DOLLAR_OPEN_CURLY_BRACES')) {
    define('T_DOLLAR_OPEN_CURLY_BRACES', -10001);
}

/**
 * Tokenise a file once and keep it. The orphan guard asks several questions of
 * the same 33 dispatch files; re-tokenising api.php (≈20k lines) each time is
 * seconds of pure waste in a test that should run in under one.
 *
 * @return array<int,mixed> the token_get_all() array
 */
function dispatchParserTokens(string $file): array
{
    static $cache = [];
    if (!isset($cache[$file])) {
        $cache[$file] = token_get_all((string)file_get_contents($file));
    }
    return $cache[$file];
}

/**
 * Is this token an OPENING brace for depth-counting purposes?
 * See the trap note in the file header — the two interpolation tokens are the
 * whole reason this is a named helper rather than `$t === '{'` inline.
 */
function dispatchParserIsOpenBrace(mixed $t): bool
{
    if ($t === '{') { return true; }
    return is_array($t)
        && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES);
}

/**
 * Extract `case '<x>':` labels belonging to a SPECIFIC switch, by tracking brace
 * depth from that switch's opening brace.
 *
 * @return array<int,array{name:string,index:int}> label + the token index of the
 *         string literal it came from
 */
function dispatchParserCaseTokens(string $file, string $switchVar): array
{
    $toks = dispatchParserTokens($file);

    $out = [];
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($toks[$i]) || $toks[$i][0] !== T_SWITCH) { continue; }

        /* Confirm this switch is on the variable we care about, then find its
           opening brace. Anything between `switch` and `{` is the subject
           expression, so a T_VARIABLE match in that window is conclusive. */
        $isOurs = false;
        $j = $i + 1;
        for (; $j < $n; $j++) {
            $t = $toks[$j];
            if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === $switchVar) { $isOurs = true; }
            if ($t === '{') { break; }
        }
        if (!$isOurs) { continue; }

        /* Walk the switch body at depth 1; collect only its own case labels, so
           a nested switch's cases are not attributed to this one. */
        $depth = 0;
        for (; $j < $n; $j++) {
            $t = $toks[$j];
            if (dispatchParserIsOpenBrace($t)) { $depth++; continue; }
            if ($t === '}') { $depth--; if ($depth === 0) { break; } continue; }
            if ($depth === 1 && is_array($t) && $t[0] === T_CASE) {
                for ($k = $j + 1; $k < $n; $k++) {
                    if (is_array($toks[$k]) && $toks[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $out[] = ['name' => trim($toks[$k][1], "'\""), 'index' => $k];
                        break;
                    }
                    if ($toks[$k] === ':' || $toks[$k] === ';') { break; }
                }
            }
        }
    }
    return $out;
}

/**
 * Back-compat convenience: just the names.
 *
 * @return array<int,string> the case labels dispatched by $switchVar
 */
function dispatchParserCasesForSwitch(string $file, string $switchVar): array
{
    return array_column(dispatchParserCaseTokens($file, $switchVar), 'name');
}

/**
 * Does this token sequence, starting at $i, read `$_GET['action']` (or _POST /
 * _REQUEST)? Returns the index of the closing `]`, or null.
 *
 * Token-level rather than textual so a mention inside a comment or a doc-block
 * cannot register a file as a dispatch surface — `save_song_core.php` discusses
 * `$_GET['action']` in prose at line 116 and dispatches nothing.
 */
function dispatchParserActionSuperglobalAt(array $toks, int $i): ?int
{
    $t = $toks[$i];
    if (!is_array($t) || $t[0] !== T_VARIABLE) { return null; }
    if (!in_array($t[1], ['$_GET', '$_POST', '$_REQUEST'], true)) { return null; }
    if (($toks[$i + 1] ?? null) !== '[') { return null; }
    $s = $toks[$i + 2] ?? null;
    if (!is_array($s) || $s[0] !== T_CONSTANT_ENCAPSED_STRING) { return null; }
    if (trim($s[1], "'\"") !== 'action') { return null; }
    if (($toks[$i + 3] ?? null) !== ']') { return null; }
    return $i + 3;
}

/**
 * Is this file a dispatch surface — i.e. does it read `$_GET/$_POST/$_REQUEST
 * ['action']` in executable code?
 *
 * Auto-discovery is the point (remediation plan §4.1): a hand-listed set of
 * dispatch files silently stops covering the surface added next week, which is
 * rule #34's whole lesson.
 */
function dispatchParserIsSurface(string $file): bool
{
    if (!preg_match('/\$_(GET|POST|REQUEST)\s*\[\s*[\'"]action[\'"]/', (string)file_get_contents($file))) {
        return false; /* cheap textual pre-filter; the token pass below is authoritative */
    }
    $toks = dispatchParserTokens($file);
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        if (dispatchParserActionSuperglobalAt($toks, $i) !== null) { return true; }
    }
    return false;
}

/**
 * Local variables this file assigns from the action superglobal — e.g.
 * `$action = (string)($_POST['action'] ?? '');` yields ['$action'].
 *
 * Derived, never assumed: `manage/setup-database.php` uses `$secretPostAction`
 * alongside `$action`, and assuming the name would miss it.
 *
 * @return array<int,string>
 */
function dispatchParserActionVars(string $file): array
{
    $toks = dispatchParserTokens($file);
    $n = count($toks);
    $vars = [];

    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t) || $t[0] !== T_VARIABLE) { continue; }
        $name = $t[1];
        if (in_array($name, ['$_GET', '$_POST', '$_REQUEST'], true)) { continue; }

        /* Find the `=` that follows (skipping whitespace/comments), then look a
           short way ahead for the superglobal. The window is generous enough for
           `(string)($_POST['action'] ?? '')` and mean enough to not run into the
           next statement. */
        $j = $i + 1;
        while ($j < $n && is_array($toks[$j]) && in_array($toks[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; }
        if (($toks[$j] ?? null) !== '=') { continue; }

        for ($k = $j + 1; $k < min($n, $j + 20); $k++) {
            if ($toks[$k] === ';') { break; }
            if (dispatchParserActionSuperglobalAt($toks, $k) !== null) { $vars[$name] = true; break; }
        }
    }

    /* `$action` is the overwhelming convention; include it unconditionally on a
       surface so an assignment shape this function does not model (a helper
       call, a destructure) still gets its switch walked. Harmless when unused —
       a switch on a `$action` that does not exist yields no cases. */
    $vars['$action'] = true;

    return array_keys($vars);
}

/**
 * Every action name this file dispatches, by every shape listed in the header.
 *
 * @return array{names:array<int,string>,labelIndexes:array<int,bool>}
 *         `labelIndexes` maps token-index => true for each string literal that
 *         served as a dispatch label, so a caller-scan can subtract exactly
 *         those and keep the rest of the file's literals.
 */
function dispatchParserActionsForFile(string $file): array
{
    $toks   = dispatchParserTokens($file);
    $n      = count($toks);
    $vars   = dispatchParserActionVars($file);
    $names  = [];
    $labels = [];

    /* --- shape 1: switch ($action) { case 'x': } ------------------------- */
    foreach ($vars as $v) {
        foreach (dispatchParserCaseTokens($file, $v) as $c) {
            $names[$c['name']] = true;
            $labels[$c['index']] = true;
        }
    }

    /* --- shapes 2–4: comparisons and in_array() against the dispatch var or
           the raw superglobal -------------------------------------------- */
    $comparison = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL];

    /** Record every string literal in `[…]` starting at $k (an in_array subject). */
    $collectArray = function (int $k) use ($toks, $n, &$names, &$labels): void {
        $depth = 0;
        for (; $k < $n; $k++) {
            if ($toks[$k] === '[') { $depth++; continue; }
            if ($toks[$k] === ']') { $depth--; if ($depth === 0) { return; } continue; }
            if ($depth >= 1 && is_array($toks[$k]) && $toks[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                $v = trim($toks[$k][1], "'\"");
                if ($v !== '') { $names[$v] = true; $labels[$k] = true; }
            }
        }
    };

    for ($i = 0; $i < $n; $i++) {
        /* Where does the "subject" (the thing being compared) end? Either a
           dispatch variable, or a literal `$_POST['action']` index expression. */
        $end = null;
        $t = $toks[$i];
        if (is_array($t) && $t[0] === T_VARIABLE && in_array($t[1], $vars, true)) {
            $end = $i;
        } else {
            $sg = dispatchParserActionSuperglobalAt($toks, $i);
            if ($sg !== null) { $end = $sg; }
        }
        if ($end === null) { continue; }

        /* Forward window: `=== 'x'`, or `?? '' ) === 'x'`. 12 tokens covers the
           null-coalesce + cast + parenthesis noise without spilling into the
           next statement. */
        for ($k = $end + 1; $k < min($n, $end + 12); $k++) {
            if ($toks[$k] === ';' || $toks[$k] === '{') { break; }
            if (is_array($toks[$k]) && in_array($toks[$k][0], $comparison, true)) {
                for ($m = $k + 1; $m < min($n, $k + 4); $m++) {
                    if (is_array($toks[$m]) && $toks[$m][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $v = trim($toks[$m][1], "'\"");
                        if ($v !== '') { $names[$v] = true; $labels[$m] = true; }
                        break;
                    }
                }
                break;
            }
        }

        /* Backward: `in_array($action, ['a','b'], true)` — walk back over the
           subject to see whether we are the first argument of in_array(). */
        $b = $i - 1;
        while ($b > 0 && is_array($toks[$b]) && $toks[$b][0] === T_WHITESPACE) { $b--; }
        if (($toks[$b] ?? null) === '(') {
            $b--;
            while ($b > 0 && is_array($toks[$b]) && $toks[$b][0] === T_WHITESPACE) { $b--; }
            if (is_array($toks[$b] ?? null) && $toks[$b][0] === T_STRING
                && strtolower($toks[$b][1]) === 'in_array') {
                for ($k = $end + 1; $k < min($n, $end + 12); $k++) {
                    if ($toks[$k] === ')' || $toks[$k] === ';') { break; }
                    if ($toks[$k] === '[') { $collectArray($k); break; }
                }
            }
        }
    }

    return ['names' => array_keys($names), 'labelIndexes' => $labels];
}

/**
 * Walk a directory tree and return every dispatch surface under it.
 *
 * ⚠️ Plain PHP RecursiveDirectoryIterator, NEVER a shell-out to `rg`/`grep`.
 * The reason is documented at length in `test-orphan-inventory.php`'s header:
 * `rg` skips dot-directories by default and dropped hits on multi-root
 * invocations, and both cost the source audit real findings.
 *
 * @return array<int,string> absolute paths, sorted
 */
function dispatchParserDiscoverSurfaces(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $path = $f->getPathname();
        if (str_contains($path, '/vendor/')) { continue; }
        if (dispatchParserIsSurface($path)) { $out[] = $path; }
    }
    sort($out);
    return $out;
}
