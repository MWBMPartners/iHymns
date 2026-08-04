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
 * HOW IT LOOKS AT THE SOURCE — STRUCTURE, NEVER A CHARACTER WINDOW
 * ---------------------------------------------------------------
 * Through `tests/php/lib/php_source_units.php` (#1688) — the shared per-function
 * split into three views. Which view an assertion uses is load-bearing:
 *
 *   `code`    for anything about CONTROL FLOW. Prose cannot satisfy it (every
 *             non-identifier literal is `'@STR@'`), and each statement leaves a
 *             `@SQL:<VERB>:<table>@` marker so an ordering or containment check
 *             can point at ONE statement without being able to read it.
 *   `sqlOnly` for anything about the SHAPE of a query.
 *   `strings` ONLY where the PROSE is the deliverable (the refusal message).
 *
 * EVERY ASSERTION HERE WAS DEFEATED ONCE (2026-07-31)
 * --------------------------------------------------
 * An adversarial pass mutated the real tree and drove this file green five ways.
 * The root cause was the same every time — CHARACTER WINDOWS and PROSE MATCHING
 * where STRUCTURAL BOUNDARIES and TOKEN MATCHING were needed:
 *
 *  A5   the view then named `sql` held every string chain, so "the pre-check
 *       filters to FKs on tblSongs(SongId) and reads UPDATE_RULE" was satisfied
 *       by the REFUSAL SENTENCE plus the `$r['UPDATE_RULE']` subscript, with the
 *       query itself irrelevant. Measured rather than assumed, because the
 *       finding as handed over said "the entire H3 section stayed green" and that
 *       is not what happens: replacing the whole prepare/execute/fetch with
 *       `$rows = [];` and running the PRE-FIX guard turns two of the three shape
 *       assertions red (`INFORMATION_SCHEMA.…` and `DATABASE()` appear only in
 *       the query) and leaves exactly the named one PASSING. One green assertion
 *       inside a red section is still the bug — it claims a property nothing
 *       holds — but the scope was smaller than reported and is written down here
 *       accordingly. Shape assertions now read `sqlOnly`.
 *  A3   `enclosingGuard()` read ONE level then `strrpos($head, 'if (')` over all
 *       preceding source, so the "guard" it reported could be a SIBLING `if`
 *       hundreds of characters above the block the call is really in. Reported
 *       as: wrap the branch in `try { … } catch`, delete the M2 fix, and both M2
 *       assertions stay green. HONESTY NOTE — that exact instance did not
 *       reproduce here: with the M2 fix deleted, `strrpos` lands on the (now
 *       flag-less) guard itself and the pre-fix guard goes RED, and it still did
 *       when the guard was additionally written `if(` so the search would skip
 *       it. What DID reproduce, against the real file, is the other direction:
 *       leave the guard entirely CORRECT and add one neutral nesting level after
 *       the (balanced) `if (function_exists('getCurrentUser'))` block, and the
 *       pre-fix guard reports the correct guard as MISSING. Both directions are
 *       the same defect — "nearest `if` above me" is not "the block I am in" —
 *       and the chain walk fixes both; only one of them was demonstrable.
 *  A4   `substr_count($code, 'try {') === 1` cannot see `try{`, and
 *       "the sentence 'content-restriction rewrite failed' is absent" is one
 *       exact sentence. The M3 swallow was restored verbatim, green.
 *  A11  `/1213[^;]{0,80}1205[^;]{0,80}throw \$e;/` and an anchor on the exact
 *       text `'data.assignedId && data.previousId'` both go RED on obvious
 *       refactors (two `if`s; swapping two `&&` operands). A guard that fails on
 *       correct code gets deleted, not fixed (rule #34).
 *  A12  "the block is existence-gated" only checked that a literal appeared
 *       BEFORE the SQL, never that the writes were INSIDE the gate — so hoisting
 *       the probe to a variable and deleting the condition kept it green, and on
 *       an install where #1044 never ran the read then throws under mysqli STRICT
 *       inside the caller's transaction and rolls back the entire song save.
 *       "The gate is evaluated before the statement is prepared" was
 *       `strpos($code, '@STR@', $p) !== false` — "is there any string later?" —
 *       unconditionally true, so it could not fail for the ordering it named.
 *       And the editor.js check sliced a raw 3000-character window from the
 *       rename anchor: MEASURED, that overruns the real block by 1466 characters.
 *       No second `'edit-number'` happens to fall in the overrun today (the next
 *       one is 5840 characters out), so that assertion was correct BY LUCK rather
 *       than by construction — one edit away from the reported failure, and the
 *       distinction is recorded because a guard that passes for the wrong reason
 *       is read as coverage either way.
 *
 * WHAT IT CANNOT CATCH (so its tick is not over-read)
 * --------------------------------------------------
 *  - Whether the SQL is CORRECT. There is no MySQL in CI; the statements are
 *    checked for shape and order, never executed.
 *  - Whether the pre-check's verdict is right on a real drifted install.
 *  - A funnel that never calls the helper at all — that is the other file's job.
 *  - Whether a path REACHES the guarded branch. Containment is structural;
 *    reachability needs a running program.
 *
 * WHAT WAS MUTATION-TESTED, AND WHAT WAS NOT
 * ------------------------------------------
 * Every assertion CHANGED in this pass was driven against the real files in BOTH
 * directions — break the property and watch it go red, then write a
 * correct-but-different version and watch it stay green (rule #34). The
 * correct-but-different half matters as much as the other: hoisting a probe to a
 * local, splitting a predicate into two `if`s, dropping the braces round a
 * `throw`, swapping two `&&` operands and re-indenting a `try` all keep this
 * green, and each of them turned some earlier version of some assertion red.
 *
 * NOT individually mutated, because this pass did not change them and their
 * shape is a plain identifier presence test: "the mint asks the shared claim
 * check", "the refusal is a RuntimeException"/"extends \RuntimeException", "gated
 * on the EXISTING songRedirectsTableReady()", "…in STRICT mode". They read the
 * `code` view, so prose cannot satisfy them; they are named here so their tick is
 * not read as more than it is.
 *
 * WHAT #1691 MOVED OUT OF THIS FILE (D2–D5, 2026-07-31)
 * -----------------------------------------------------
 * A second adversarial pass defeated four MORE of this file's guards against
 * the real tree, all the same disease: source inspection standing in for a
 * property that could have been CALLED.
 *
 *  D2   the save-core catch derivation filtered on the SPELLING `$_e`, so
 *       renaming one catch to the conventional `$e` dropped it from the
 *       derived set and its re-throw could be deleted, green. Demonstrated:
 *       the old assertion PASSED with a swallowing `catch (\Throwable $e)` in
 *       the transaction. The filter is now POSITIONAL (every catch between
 *       begin_transaction() and the commit) and the guard must name the
 *       variable read out of each head; a non-capturing `catch (\Throwable)`
 *       counts as unguarded because it cannot re-throw what it caught.
 *  D3   "every gated statement is INSIDE the existence gate" never tested the
 *       gate's POLARITY — inverting the `if` (block runs only when the table
 *       is ABSENT) kept containment true and the suite green.
 *  D4   "the gate SHORT-CIRCUITS" accepted any `return` token between gate
 *       and statement. HONESTY NOTE — the defeat is narrower than the finding
 *       stated: with the unrelated `if ($songId === '') { return false; }`
 *       placed BEFORE the (answer-discarded) gate call, the old window check
 *       actually went red, because the window started at the gate. Placed
 *       BETWEEN the gate and the SELECT it passed exactly as reported, with
 *       the SELECT running ungated. One demonstrable direction is enough —
 *       and the behavioural replacement catches both placements identically,
 *       because the double throws 1146 the moment the ungated statement is
 *       prepared.
 *  D5   nothing pinned WHICH probe a gate asks — the strict
 *       songRelocateTableExists() could be swapped for the swallowing
 *       songRedirectsTableReady() with the suite green, silently skipping the
 *       restriction rewrite when a probe breaks.
 *
 * The conversion (the test-transaction-fatal.php rule — when a property lives
 * in a function, TEST THE FUNCTION): steps 5/5b were extracted into
 * songRelocateRewriteRestrictions() / songRelocateRewriteEntries(), whose gate
 * is an early `return false`, and tests/php/test-song-relocate-gates.php now
 * drives them — plus songRelocateIdTaken() — with a RECORDING mysqli double
 * across three settled-memo child processes (absent / present / probe-fails).
 * Polarity is a return value there, the short-circuit is the recorded
 * statement stream, and probe identity is the failure contract. What stays
 * HERE is only what has no runtime handle: the delegation, its ordering, the
 * call-site non-swallow, and the SQL shapes.
 *
 * WHAT #1690 MOVED, AND WHY ONE ASSERTION IS NOW BEHAVIOURAL
 * ---------------------------------------------------------
 * `songRelocateAssertCascades()` was split into three layers — a memoised
 * `songRelocateFkCatalogue()`, and the PURE `songRelocateCascadeGaps()` /
 * `songRelocateCascadeVerdict()` — so the deciding logic could be CALLED by a
 * test rather than read out of the source. Four position-sensitive assertions
 * here had to follow it, exactly as #1679 A1/A9's extraction required:
 *   - the three query-SHAPE assertions now read the CATALOGUE's `sqlOnly` view
 *     (plus two new ones: the child COLUMN, and the second COLUMNS read);
 *   - "the refusal names the migration" is no longer a string scan at all. The
 *     message is assembled from `SONG_RELOCATE_FIX_MIGRATIONS`, a top-level const
 *     belonging to no function unit, so the scan would have reported correct code
 *     as missing — rule #34's second failure mode, which ends in the guard being
 *     deleted rather than fixed. It CALLS the pure builder instead. That is the
 *     preference `test-transaction-fatal.php` exists to establish: source
 *     inspection is for properties with no runtime handle.
 * The per-song verdict itself is guarded exhaustively, by execution, in
 * `tests/php/test-song-relocate-cascade-verdict.php`.
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
/* #1690 — ONE assertion here is behavioural rather than structural (the refusal
   message), because the function that builds it is pure and calling it is
   strictly better evidence than reading it. Loading the file is side-effect-free:
   it declares functions and constants and opens no connection. */
require_once $ROOT . '/appWeb/public_html/includes/song_relocate.php';

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

/* ------------------------------------------------------- local helpers ----- */

/** An empty unit, so a renamed/removed function fails ONE assertion loudly. */
const EMPTY_UNIT = ['code' => '', 'strings' => [], 'sqlOnly' => []];

/** Does any statement in the unit's SQL-only view match $re? */
function sqlHas(array $unit, string $re): bool
{
    foreach ($unit['sqlOnly'] as $s) {
        if (preg_match($re, phpUnitsNormaliseSql((string)$s))) { return true; }
    }
    return false;
}

/** Byte offsets of every `@SQL:<VERB>:<table>@` marker matching $re, in order. */
function markerPositions(string $code, string $re): array
{
    if (!preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE)) { return []; }
    return array_map(static fn(array $h): int => $h[1], $m[0]);
}

/**
 * Is this block head a `try` / `catch` / `finally`?
 *
 * ELI5: "is the thing I am inside an error-swallower?"
 *
 * Detail: anchored at the END of the head, because a head can carry a leading
 * `case '@STR@':` label (the backward scan stops at statement boundaries, and a
 * case label is not one). `strpos($head, 'try')` would also match the word
 * inside an identifier such as `$retryCount`.
 */
function isSwallowHead(string $head): bool
{
    return (bool)preg_match('/\b(try|finally)\s*$/', $head)
        || (bool)preg_match('/\bcatch\s*\([^)]*\)\s*$/', $head);
}

/**
 * Is $pos structurally INSIDE a block whose head matches $headRe?
 *
 * ELI5: not "does that word appear somewhere above me" — "am I actually inside
 * that if?".
 *
 * This is the A12 fix. "The block is existence-gated" is a CONTAINMENT claim,
 * and the version it replaces tested two list indexes for ordering, which stays
 * true when the probe is hoisted to a variable and the `if` deleted — the exact
 * mutation that, on an install without the table, makes the read throw under
 * mysqli STRICT inside the caller's transaction and roll back the whole save.
 */
function enclosedByHead(string $code, int $pos, string $headRe): bool
{
    foreach (phpUnitsEnclosingBlocks($code, $pos) as $b) {
        if (preg_match($headRe, $b['head'])) { return true; }
    }
    return false;
}

/* NB: a `probeAcceptors()` helper used to live here — it derived "the answer to
   this probe, or any variable it was hoisted into" for the gate-containment
   checks. Those checks were the #1691 D3/D4 finding (they ignored the gate's
   POLARITY), and their properties moved into behavioural assertions in
   tests/php/test-song-relocate-gates.php, so the helper went with them rather
   than sitting here unused and reading like coverage. */

/**
 * Is $pos inside a `try`/`catch` that is itself INSIDE the block matching
 * $outerRe (or, when $outerRe is null, anywhere out to the unit body)?
 *
 * ELI5: "did somebody wrap this in an error-swallower?" — while ignoring the
 * legitimate outer one the whole function already runs inside.
 */
function swallowedWithin(string $code, int $pos, ?string $outerRe): bool
{
    foreach (phpUnitsEnclosingBlocks($code, $pos) as $b) {
        if ($outerRe !== null && preg_match($outerRe, $b['head'])) { return false; }
        if (isSwallowHead($b['head'])) { return true; }
    }
    return false;
}

/* ---- JavaScript: the same structural discipline, without a JS tokenizer ---- */

/**
 * Can a `/` at $i begin a REGEX literal rather than a division?
 *
 * ELI5: `a / b` divides; `x.replace(/…/, …)` is a pattern. Tell them apart by
 * what came just before.
 *
 * Detail: the standard lexical heuristic — a regex can only start where a VALUE
 * is expected, i.e. after an operator, an opening bracket, a comma/semicolon, or
 * one of the keywords that are followed by an expression. After an identifier,
 * a number, `)` or `]` a `/` is division. This is not a JS parser and does not
 * need to be; being wrong costs an under-redacted regex (the walk may end a
 * block early = a red guard someone will look at) not a silent pass.
 */
function jsRegexCanStartHere(string $js, int $i, string $prev): bool
{
    if ($prev === '') { return true; }
    if (strpos('(,=:[!&|?{};+-*%~^<>', $prev) !== false) { return true; }
    /* `return /re/`, `typeof /re/`, `case /re/:` … — a WORD before the slash is
       division unless the word is one of these. */
    if (preg_match('/\b(return|typeof|case|in|of|do|else|void|delete|instanceof|new|throw|yield|await)\s*$/',
                   substr($js, max(0, $i - 24), min($i, 24)))) {
        return true;
    }
    return false;
}

/**
 * Blank the CONTENTS of every JS string / template / regex literal, preserving
 * LENGTH.
 *
 * ELI5: keep the quotes, replace what is between them with filler, so the text
 * is the same size but a brace hiding inside a string can no longer confuse a
 * brace-counting walk.
 *
 * Detail: same-length redaction is what lets offsets found on the redacted copy
 * be used to slice the ORIGINAL — which is how `'edit-number'` can still be
 * asserted inside a block whose boundaries were computed without it.
 *
 * REGEX LITERALS ARE NOT OPTIONAL HERE. A first draft skipped them, on the
 * reasoning that the block being bounded contains none. That was true and
 * irrelevant: `editor.js:` `String(s).replace(/[&<>"']/g, …)` puts a `"` and a
 * `'` inside a character class, and one un-redacted quote does not damage its own
 * line — it swallows the next TEN THOUSAND characters as a string, including the
 * whole rename block, so `strpos()` reported the block as absent. A same-length
 * redactor is only safe if it understands every literal form the file uses; half
 * of one is worse than none, because it fails far from its cause.
 */
function jsRedactLiterals(string $js): string
{
    $out  = $js;
    $n    = strlen($js);
    $prev = '';                       // last significant char OUTSIDE any literal
    for ($i = 0; $i < $n; $i++) {
        $c = $js[$i];

        if ($c === "'" || $c === '"' || $c === '`') {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($js[$j] === '\\') { $out[$j] = 'x'; $j++; if ($j < $n) { $out[$j] = 'x'; } continue; }
                if ($js[$j] === $c)   { break; }
                if ($js[$j] !== "\n") { $out[$j] = 'x'; }
            }
            $i    = $j;
            $prev = $c;
            continue;
        }

        if ($c === '/' && jsRegexCanStartHere($js, $i, $prev)) {
            $inClass = false;
            for ($j = $i + 1; $j < $n; $j++) {
                $d = $js[$j];
                /* A newline inside an unterminated "regex" means it was not one
                   — bail without having redacted past the line. */
                if ($d === "\n") { break; }
                if ($d === '\\') { $out[$j] = 'x'; $j++; if ($j < $n && $js[$j] !== "\n") { $out[$j] = 'x'; } continue; }
                if ($d === '[')  { $inClass = true; }
                if ($d === ']')  { $inClass = false; }
                if ($d === '/' && !$inClass) { break; }
                $out[$j] = 'x';
            }
            $i    = $j;
            $prev = '/';
            continue;
        }

        if (!ctype_space($c)) { $prev = $c; }
    }
    return $out;
}

/**
 * The first `if (…)` whose CONDITION mentions $token: its condition text and the
 * offset of the `{` that opens its body.
 *
 * ELI5: find the `if` that tests this thing, and hand back what it tests plus
 * where its block starts.
 *
 * Detail: paren-BALANCED rather than `[^)]*`. Two reasons, one of them found the
 * hard way here: a negated-class scan across a redacted 180 KB file (where string
 * contents no longer supply any `)`) blows PCRE's backtrack limit and preg_match
 * returns FALSE — which reads as "the block is missing", i.e. a confident red on
 * correct code. Balancing also survives a call in the condition
 * (`if (Object.prototype.hasOwnProperty.call(data, 'number'))`), which `[^)]*`
 * truncates half-way through.
 *
 * @return array{cond:string, open:int}|null  $open indexes the body's `{`.
 */
function jsIfWithToken(string $redacted, string $token): ?array
{
    $n = strlen($redacted);
    if (!preg_match_all('/\bif\s*\(/', $redacted, $m, PREG_OFFSET_CAPTURE)) { return null; }
    foreach ($m[0] as $hit) {
        $p      = $hit[1] + strlen($hit[0]) - 1;   // index of the `(`
        $depth  = 0;
        $end    = null;
        for ($i = $p; $i < $n; $i++) {
            if ($redacted[$i] === '(') { $depth++; continue; }
            if ($redacted[$i] === ')') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }
        if ($end === null) { continue; }
        $cond = substr($redacted, $p + 1, $end - $p - 1);
        if (strpos($cond, $token) === false) { continue; }
        /* Only a BLOCK body can be walked; `if (…) doThing();` has none. */
        if (!preg_match('/\G\s*\{/', $redacted, $b, PREG_OFFSET_CAPTURE, $end + 1)) { continue; }
        return ['cond' => $cond, 'open' => $b[0][1] + strlen($b[0][0]) - 1];
    }
    return null;
}

/**
 * The `{ … }` block that opens at or after $from: [start, end) in the ORIGINAL.
 *
 * Brace-balanced on the redacted copy, so the boundary is the block's real one
 * rather than "$from + 3000 characters" (A12).
 *
 * @return array{0:int,1:int}|null
 */
function jsBlockAfter(string $redacted, int $from): ?array
{
    $n    = strlen($redacted);
    $open = strpos($redacted, '{', $from);
    if ($open === false) { return null; }
    $depth = 0;
    for ($i = $open; $i < $n; $i++) {
        if ($redacted[$i] === '{') { $depth++; continue; }
        if ($redacted[$i] === '}') {
            $depth--;
            if ($depth === 0) { return [$open + 1, $i]; }
        }
    }
    return null;
}

/* ------------------------------------------------------------- sources ----- */

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
$mint   = $relocate['songRelocateMintId'] ?? EMPTY_UNIT;
$assert = $relocate['songRelocateAssertCascades'] ?? EMPTY_UNIT;
$save   = $saveCore['editorSaveSongCore'];
/* #1690 split songRelocateAssertCascades() into three layers so the deciding
   logic could be CALLED by a test instead of read (the regress
   test-transaction-fatal.php documents). The properties below did not change —
   WHERE they are implemented did, so the unit each assertion reads has to follow
   them or it silently stops checking anything. This is the same correction
   #1679 A1/A9 made when the two predicates were extracted. */
$catalog = $relocate['songRelocateFkCatalogue'] ?? EMPTY_UNIT;
/* The two predicates the A1/A9 pass extracted OUT of the functions above,
   precisely so several files could share one copy (rule #35). The properties
   asserted below did not change — where they are implemented did, so the unit
   the assertion reads has to follow them or it silently stops checking
   anything. */
$taken  = $relocate['songRelocateIdTaken'] ?? EMPTY_UNIT;
$fatal  = $relocate['songRelocateIsTransactionFatal'] ?? EMPTY_UNIT;

/* ------------------------------------------------- H3 — cascade pre-check -- */

echo "\nH3 — the move verifies ON UPDATE CASCADE before it writes anything\n";

ok('songRelocateAssertCascades() is declared in song_relocate.php',
   $assert['code'] !== '');

/* ORDER is the whole point: a pre-check that runs after the re-key is not a
   pre-check. The `@SQL:<VERB>:<table>@` markers show where each statement stood,
   so "before the first WRITE" is answerable without reading any of them. */
$callPos    = strpos($move['code'], 'songRelocateAssertCascades(');
$writePos   = markerPositions($move['code'], '/@SQL:(?:UPDATE|INSERT|DELETE|REPLACE):[\w.]+@/');
$firstWrite = $writePos === [] ? null : $writePos[0];
ok('songRelocate() calls the pre-check', $callPos !== false,
   'nothing in songRelocate() invokes songRelocateAssertCascades() — a drifted install '
   . 'would fail mid-transaction with ER_ROW_IS_REFERENCED_2 and lose the whole save');
ok('and calls it BEFORE its first WRITE',
   $callPos !== false && $firstWrite !== null && $callPos < $firstWrite,
   'the pre-check must precede every write; found call at ' . var_export($callPos, true)
   . ', first write at ' . var_export($firstWrite, true)
   . ' (' . count($writePos) . ' write statement(s) seen)');

/* A5 — sqlOnly, NOT the full string view. Read on `strings`, all three of these
   were satisfied by the refusal SENTENCE (which contains "tblSongs(SongId)") plus
   the `$r['UPDATE_RULE']` array subscript, so the query could be deleted outright
   and H3 stayed green.
   #1690 — the query itself now lives in the memoised catalogue rather than in the
   assert function. */
$fkSql = implode("\n", array_map('phpUnitsNormaliseSql', $catalog['sqlOnly']));
ok('the pre-check reads REFERENTIAL_CONSTRAINTS joined to KEY_COLUMN_USAGE',
   stripos($fkSql, 'INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS') !== false
   && stripos($fkSql, 'INFORMATION_SCHEMA.KEY_COLUMN_USAGE') !== false,
   'SQL statements in this function: ' . (count($catalog['sqlOnly']) ?: 'NONE'));
ok('scoped to the CURRENT schema (DATABASE()), not every schema on the server',
   stripos($fkSql, 'DATABASE()') !== false);
ok('filtered to FKs that reference tblSongs(SongId), and reads UPDATE_RULE',
   stripos($fkSql, 'tblSongs') !== false && stripos($fkSql, 'SongId') !== false
   && stripos($fkSql, 'UPDATE_RULE') !== false,
   'the statement(s) read: ' . substr($fkSql, 0, 400));
/* #1690 — the CHILD COLUMN, without which there is no way to ask "does THIS song
   have rows behind that constraint" and the check silently reverts to the
   schema-wide refusal. REFERENTIAL_CONSTRAINTS does not carry it; only the
   KEY_COLUMN_USAGE side can supply it, which is why the join exists at all. */
ok('and selects the child COLUMN, not only the child TABLE (#1690)',
   preg_match('/\bk\.COLUMN_NAME\b/i', $fkSql) === 1,
   'a per-song verdict needs the column to probe. Statement(s) read: ' . substr($fkSql, 0, 400));
/* The second read: which expected (table, column) pairs exist here at all. It is
   what stops "this install never created the table" being reported as drift. */
ok('and separately resolves whether the EXPECTED columns exist on this install',
   stripos($fkSql, 'INFORMATION_SCHEMA.COLUMNS') !== false,
   'without it a missing FK on a table this install has never created reads as a '
   . 'gap, and a minimal install can never move a song');

/* Memoised, or a bulk move re-reads INFORMATION_SCHEMA once per song INSIDE the
   caller's transaction. This one belongs in a source scan and not in the
   behavioural file next door: "it only queried once" needs a live connection to
   count queries against, and a naive "call it twice and compare" assertion is
   satisfied by two equal failures — measured, when a mutant deleted the `static`
   and that assertion stayed green. The `code` view, so a comment cannot satisfy
   it. */
ok('the catalogue is memoised for the request (a bulk move must not re-read it per song)',
   preg_match('/\bstatic\s+\$\w+\s*=/', $catalog['code']) === 1,
   'the INFORMATION_SCHEMA reads would otherwise run once per song moved, inside '
   . "the caller's transaction");

/* The layering itself: the assert function must ASK the three layers rather than
   re-inline any of them. There is no runtime handle for "this function delegates"
   without a database, so this is the source-inspection class the
   test-transaction-fatal.php header explicitly allows — "every call site does X".
   Read on `code`, so a comment naming them cannot satisfy it. */
foreach ([
    'songRelocateFkCatalogue('     => 'the memoised INFORMATION_SCHEMA catalogue',
    'songRelocateCascadeGaps('     => 'the pure gap computation',
    'songRelocateCascadeVerdict('  => 'the pure per-song verdict',
    'songRelocateChildRowProbe('   => 'the real child-row probe it injects',
] as $needle => $what) {
    ok("the pre-check delegates to $what",
       strpos($assert['code'], $needle) !== false,
       "songRelocateAssertCascades() no longer calls $needle — re-inlining a layer "
       . 'puts the deciding logic back somewhere no test can call');
}

/* A RuntimeException, NOT InvalidArgumentException — api2's move handler catches
   InvalidArgumentException to answer 422 "you named a book that doesn't exist",
   so throwing that here would report an un-migrated ENVIRONMENT as bad typing.
   #1679 A8 made it a dedicated SUBCLASS: both funnels now have to RECOGNISE this
   refusal (to copy its message into the ungated `error_hint`), and recognising it
   by matching the sentence is exactly the prose-coupling rule #35 forbids. The
   subclass keeps the RuntimeException side of the split intact — hence both
   halves are asserted, since a subclass of the WRONG parent would sail past a
   name-only check. */
ok('the refusal is a RuntimeException (api2 must not report it as a 422 typo)',
   strpos($assert['code'], 'throw new SongRelocateEnvironmentException') !== false
   && strpos($assert['code'], 'InvalidArgumentException') === false);
ok('and SongRelocateEnvironmentException extends \\RuntimeException',
   preg_match('/class\s+SongRelocateEnvironmentException\s+extends\s+\\\\RuntimeException\b/', $relocateRaw) === 1,
   'a refusal class extending anything else (or \\InvalidArgumentException) would '
   . 'have api2 answer 422 "you named a book that does not exist" for an install '
   . 'whose migrations were never run');

/* Four FKs really do lack the cascade on real installs, so this refusal WILL
   fire, and one that does not name the fix is no more actionable than the raw
   MySQL error it replaces. Both precedents
   (migrate-backfill-canonical-songids.php, songbook_maintenance.php) point at
   the same migration.
   #1690 — this WAS a scan of the function's non-SQL string literals, and that no
   longer works: the message is assembled from SONG_RELOCATE_FIX_MIGRATIONS, a
   top-level const that belongs to no function unit, so the scan would report a
   perfectly correct implementation as missing. A guard that fails on correct
   code gets weakened or deleted rather than fixed (rule #34), and there is a far
   better option here anyway — the message builder is PURE, so CALL it. That is
   the whole lesson of test-transaction-fatal.php: source inspection is for
   properties with no runtime handle, and this one has one.
   Exhaustively exercised in tests/php/test-song-relocate-cascade-verdict.php;
   the single case kept here is the tripwire for anyone editing THIS file. */
$prefixGap = [[
    'table' => 'tblSongMedia', 'column' => 'SongId', 'constraint' => 'fk_media_song',
    'kind'  => 'no-cascade',   'rule'   => 'RESTRICT',
    'fix'   => 'songid-prefix-fixup', 'known' => true,
]];
$refusal = songRelocateCascadeVerdict($prefixGap, static fn(string $t, string $c): bool => true);
ok('the refusal MESSAGE names migrate-songid-prefix-fixup.php (the message IS the fix)',
   is_string($refusal) && strpos($refusal, 'migrate-songid-prefix-fixup.php') !== false,
   'verdict returned: ' . var_export($refusal, true));

/* -------------------------------------------------------------- M1 — mint -- */

echo "\nM1 — the mint will not re-issue an id a redirect still claims\n";

/* #1679 A9 — the claim CHECK moved out of the mint into songRelocateIdTaken(),
   because the mint was never the only one: ed2_allocateSongId(), lyrics_ingest's
   create path and the canonical-id backfill migration issue ids too, each with a
   differently-shaped loop, and every one of them probed tblSongs only. The
   properties below are unchanged; they are now asserted where they live. */
ok('the mint asks the shared claim check',
   strpos($mint['code'], 'songRelocateIdTaken(') !== false,
   'a mint that inlines its own "is this free?" probe is how the redirect half '
   . 'went missing from four of the five minting sites');
ok('the claim check probes tblSongRedirects.OldSongId',
   sqlHas($taken, '/FROM\s+tblSongRedirects\b.*OldSongId/is'),
   'the seed is MAX(Number)+1 and a move clears Number, so a freed slot can '
   . 're-mint the exact id a live redirect forwards away from — getSongById() '
   . 'matches it exactly and never consults the redirect, so an old bookmark '
   . 'silently serves a DIFFERENT song');
ok('gated on the EXISTING songRedirectsTableReady() helper, not a second probe',
   strpos($taken['code'], 'songRedirectsTableReady(') !== false
   && !sqlHas($taken, '/INFORMATION_SCHEMA/i'),
   'tblSongRedirects is optional (#1343 may not be migrated here) and an ungated '
   . 'read throws under mysqli STRICT — reuse the one helper, do not fork a probe');
ok('and it asks that helper in STRICT mode (a broken probe must not read as "free")',
   preg_match('/songRedirectsTableReady\(\s*\$db\s*,\s*true\s*\)/', $taken['code']) === 1,
   'the default helper swallows a probe failure and answers false, which here '
   . 'means "no redirect claims this id" — silently switching the whole check '
   . 'off. That is #1679 A13b; the same reasoning already produced the separate '
   . 'non-swallowing songRelocateTableExists()');

/* A12 — the assertion this replaces read
   `strpos($taken['code'], '@STR@', $gatePos) !== false`, i.e. "is there any
   opaque string literal later in this function?". That is unconditionally true
   and could not fail for the ordering it named. The ordering half is kept
   below; the SHORT-CIRCUIT half is not asserted here any more (#1691 D4) — its
   structural form ("a `return` token between the gate and the statement") was
   itself defeatable, because ANY return satisfied it: an unrelated
   `if ($songId === '') { return false; }` kept it green with the gate's early
   return deleted and the SELECT running ungated. The short-circuit is a
   BEHAVIOURAL property with a runtime handle, so it now lives in
   tests/php/test-song-relocate-gates.php, which CALLS songRelocateIdTaken()
   against a recording mysqli double and asserts: table absent → false with NO
   tblSongRedirects statement prepared; probe failure → the strict throw
   propagates (a swap to the swallowing probe answers false instead and goes
   red); table present → the claim check really runs. */
$gateAt      = strpos($taken['code'], 'songRedirectsTableReady(');
$redirectSql = markerPositions($taken['code'], '/@SQL:SELECT:tblSongRedirects@/');
ok('the redirect probe is only PREPARED after the table gate has been asked',
   $gateAt !== false && $redirectSql !== [] && $gateAt < $redirectSql[0],
   'gate at ' . var_export($gateAt, true) . ', redirect SELECT at '
   . var_export($redirectSql[0] ?? null, true));

/* ------------------------------------------------ F3 — tblSongbookEntries -- */

echo "\nF3 — the move carries the tblSongbookEntries home row with it\n";

/* #1691 D3/D5 — steps 5 and 5b were EXTRACTED into boolean-returning helpers
   (songRelocateRewriteRestrictions / songRelocateRewriteEntries) so the gate
   decisions could be CALLED instead of read. The containment check this section
   used to make was defeated two ways an adversarial pass demonstrated: it never
   looked at the gate's POLARITY (inverting the `if` so the writes ran only when
   the table was ABSENT stayed green), and nothing pinned WHICH probe the gate
   asks (swapping the strict songRelocateTableExists() for the swallowing
   songRedirectsTableReady() was invisible). Both properties now have a runtime
   handle and are asserted BEHAVIOURALLY, with a recording mysqli double, in
   tests/php/test-song-relocate-gates.php: table absent → FALSE and no
   statement issued; present → TRUE and exactly the expected statements; probe
   failure → the throw PROPAGATES, which the swallowing probe cannot satisfy.
   What remains here is the source-shaped residue with no runtime handle
   (the class test-transaction-fatal.php's header allows): that songRelocate()
   DELEGATES to the helpers, in the right order, unswallowed — and the SHAPE of
   the SQL inside them. */
$restrict = $relocate['songRelocateRewriteRestrictions'] ?? EMPTY_UNIT;
$entries  = $relocate['songRelocateRewriteEntries'] ?? EMPTY_UNIT;

ok('songRelocateRewriteEntries() writes tblSongbookEntries',
   sqlHas($entries, '/\b(UPDATE|DELETE\s+FROM)\s+tblSongbookEntries\b/i'),
   'without it the junction row reads "(old book, NEW id, old number, IsHome=1)" — '
   . 'it claims the song\'s home is the book it just left, and uq_book_number keeps '
   . 'the vacated slot occupied in a book the song is no longer in');
ok('it moves the home row into the new book and clears SongNumber',
   sqlHas($entries, '/UPDATE\s+tblSongbookEntries\s+SET\s+SongbookAbbr\s*=\s*\?,\s*SongNumber\s*=\s*NULL/i'));
ok('it handles a song that is ALREADY a member of the target book (uq_book_song)',
   sqlHas($entries, '/DELETE\s+FROM\s+tblSongbookEntries/i')
   && sqlHas($entries, '/UPDATE\s+tblSongbookEntries\s+SET\s+IsHome\s*=\s*1/i'),
   'multi-book membership is this table\'s whole point, so the target may already '
   . 'hold a row for this song; moving the old home row onto it would abort the '
   . 'entire save on a duplicate key');

/* The delegation itself — a helper nobody calls protects nothing, and a funnel
   check cannot see it (it asks who calls songRelocate, not what songRelocate
   does). Read on `code`, so a comment naming the helper cannot satisfy it. */
$entriesCallAt = strpos($move['code'], 'songRelocateRewriteEntries(');
ok('songRelocate() delegates step 5b to songRelocateRewriteEntries()',
   $entriesCallAt !== false,
   'deleting or re-inlining the call is how the junction rewrite stops running '
   . 'on a move — and re-inlining also puts the gate back where no test can '
   . 'call it (#1691 D3)');

/* Ordering: the re-key marker must precede the CALL — the helper reads and
   writes the junction by the NEW id, which only exists once step 4 cascaded. */
$rekeyAt = markerPositions($move['code'], '/@SQL:UPDATE:tblSongs@/');
ok('and calls it AFTER the re-key, so SongId has already cascaded',
   $rekeyAt !== [] && $entriesCallAt !== false && $rekeyAt[0] < $entriesCallAt,
   're-keying second would leave the entries rows pointing at the dead id');

/* ------------------------------------ M3 — the restriction rewrite is fatal -- */

echo "\nM3 — a restriction that cannot follow the song blocks the move\n";

ok('the content-restriction rewrite is still performed',
   sqlHas($restrict, '/UPDATE\s+tblContentRestrictions\s+SET\s+EntityId/i'));
$restrictCallAt = strpos($move['code'], 'songRelocateRewriteRestrictions(');
ok('and songRelocate() delegates step 5 to songRelocateRewriteRestrictions()',
   $restrictCallAt !== false,
   'the rewrite lives in the helper now (#1691 D3/D5) — a move that never calls '
   . 'it leaves every restriction on the dead id');

/* A4 — STRUCTURE, not one sentence. The check this replaces was
   `strpos($sql, 'content-restriction rewrite failed') === false`: exactly one
   wording, so the swallow could be restored with the message reworded and the
   suite stayed green. Since the #1691 extraction the fatality claim has two
   halves: the HELPER must not swallow (behaviourally proven — the gates test
   makes the UPDATE throw and asserts propagation — and pinned structurally here
   so a red names the exact edit), and the CALL SITE must not wrap it either,
   which only this file can see. swallowedWithin() walks the enclosing block
   chain out to the unit body; for the call-site half the (legitimate) SongCount
   try later in songRelocate() is a SIBLING, so it is never in that chain.
   NB "no `catch` between this statement and the end of the unit" would be the
   wrong test: step 7's cache recompute has one, correctly. */
$restrictAt = markerPositions($restrict['code'], '/@SQL:UPDATE:tblContentRestrictions@/');
ok('the restriction rewrite is not wrapped in a try/catch inside its helper',
   $restrictAt !== [] && !swallowedWithin($restrict['code'], $restrictAt[0], null),
   'a restriction left on the dead id stops applying — withheld content becomes '
   . 'readable, the move commits anyway, and error_log is the only trace');
ok('…and neither rewrite helper contains ANY try block',
   $restrict['code'] !== '' && $entries['code'] !== ''
   && phpUnitsCountToken($restrict['code'], T_TRY) === 0
   && phpUnitsCountToken($entries['code'], T_TRY) === 0,
   'every statement in both helpers is load-bearing; a catch makes its failure '
   . 'invisible while the move commits regardless (the original M3 defect)');
ok('…and songRelocate() does not wrap either CALL in a try/catch',
   $restrictCallAt !== false && !swallowedWithin($move['code'], $restrictCallAt, null)
   && $entriesCallAt !== false && !swallowedWithin($move['code'], $entriesCallAt, null),
   'wrapping the call re-creates M3 one frame up — the helper throws, the '
   . 'caller logs, the move commits without its restrictions');

/* One try/catch remains in songRelocate: the SongCount recompute (F8 below).
   Counting is blunt but it is the property that matters — re-wrapping any other
   step "just to be safe" is how M3 happened in the first place.
   TOKEN-counted (A4): `substr_count($code, 'try {')` cannot see `try{`, so the
   swallow was restored verbatim minus one space and this stayed green. */
$tryCount = phpUnitsCountToken($move['code'], T_TRY);
ok('songRelocate() has exactly ONE try block (the SongCount recompute)',
   $tryCount === 1,
   'found ' . $tryCount . '. Every step in this function except the cache recompute '
   . 'is load-bearing; wrapping one in a catch makes its failure invisible while '
   . 'the move commits regardless');

/* ------------------------------------------------------ F8 — transaction -- */

echo "\nF8 — a transaction-fatal error is re-thrown, never logged and ignored\n";

/* #1679 A1 — the test moved from "does this catch name the two codes?" to "does
   it ask the ONE predicate?", because the codes now live in exactly one place.
   That was not a refactor for tidiness: the re-throw here only holds if nothing
   between the relocate and the caller's commit() swallows the same error again,
   and both funnels have nine more best-effort catches in that span.

   A11 — asserted against the catch BLOCK's own boundaries rather than a regex
   spanning `[^;]{0,80}`, and accepting a braced or unbraced `throw`, so the
   codes-and-throw claim survives every refactor that keeps them together. */
$moveCatches = phpUnitsCatchBlocks($move['code']);
$guardedMove = 0;
foreach ($moveCatches as $c) {
    if (preg_match('/^\s*if\s*\(\s*songRelocateIsTransactionFatal\s*\([^)]*\)\s*\)\s*\{?\s*throw\b/', $c['body'])) {
        $guardedMove++;
    }
}
ok('the SongCount recompute re-throws via the shared predicate, first thing in its catch',
   $moveCatches !== [] && $guardedMove === count($moveCatches),
   count($moveCatches) . ' catch block(s) in songRelocate(), ' . $guardedMove . ' opening with '
   . 'the predicate + throw. A bare catch (\\Throwable) cannot tell "this column is '
   . 'missing on an old install" from "InnoDB just rolled back your entire transaction"');

/* The two codes, as NUMERIC TOKENS in the predicate. Token-matched so a code
   mentioned in a message cannot satisfy it, and deliberately NOT position-coupled:
   hoisting them to `$fatal = [1213, 1205];` or splitting the test into two `if`s
   are both obvious refactors that must stay green (A11). */
$fatalNums = [];
foreach (@token_get_all('<?php ' . $fatal['code']) as $t) {
    if (is_array($t) && $t[0] === T_LNUMBER) { $fatalNums[] = (int)$t[1]; }
}
ok('deadlock (1213) and lock-wait timeout (1205) are what that predicate re-throws',
   in_array(1213, $fatalNums, true) && in_array(1205, $fatalNums, true)
   && strpos($fatal['code'], 'mysqli_sql_exception') !== false,
   'numbers seen: ' . implode(', ', $fatalNums) . '. Both codes can roll back the WHOLE '
   . 'InnoDB transaction, not just the statement — swallowing them lets execution reach '
   . 'the caller\'s commit(), which commits nothing and answers {ok:true, songId:<new>} '
   . 'for a song that no longer exists under that id');

/* The re-throw is only worth anything end-to-end. DERIVED, not listed — and
   derived from STRUCTURE, not from a SPELLING (#1691 D2): the version this
   replaces filtered the catch list on `strpos($c['head'], '$_e')`, which is a
   hardcoded list wearing a derivation's clothes. Rename ONE catch to the
   conventional `catch (\Throwable $e)` — a spelling this very file uses — and
   that catch left the derived set entirely, so deleting its re-throw stayed
   green. The set is now positional: EVERY catch that opens between
   begin_transaction() and the commit — the span where a swallow turns a
   rolled-back transaction into a reported success — whatever its variable is
   called. The caught variable is read out of each head and the guard must name
   THAT variable; a non-capturing `catch (\Throwable)` (legal since PHP 8.0)
   binds nothing, cannot re-throw what it caught, and is therefore correctly
   counted as unguarded rather than skipped.
   The boundaries fail LOUDLY: if begin/commit cannot be located the scope
   assertion goes red instead of the filter silently matching nothing.
   Bounded by the catch block's own braces (A12): the 120-character window an
   earlier version used both truncated a long first statement and, on a short
   catch, ran into whatever followed the block.
   https://www.php.net/manual/en/language.exceptions.php (non-capturing catch) */
$txnBegin  = strpos($save['code'], 'begin_transaction(');
$txnCommit = strrpos($save['code'], '->commit(');
ok('the transaction span (begin_transaction … commit) was located in the save core',
   $txnBegin !== false && $txnCommit !== false && $txnBegin < $txnCommit,
   'begin at ' . var_export($txnBegin, true) . ', commit at ' . var_export($txnCommit, true)
   . ' — without both, "every catch inside the transaction" cannot be derived and '
   . 'this section would be vouching for an empty set');
$saveCatches = array_values(array_filter(
    phpUnitsCatchBlocks($save['code']),
    static fn(array $c): bool =>
        $txnBegin !== false && $txnCommit !== false
        && $c['open'] > $txnBegin && $c['open'] < $txnCommit
));
$unguarded = 0;
foreach ($saveCatches as $c) {
    /* ELI5: find out what this catch called the exception, then demand the
       guard re-throws exactly that.
       Detail: `$vm[1]` is the LAST `$var` before the head's closing paren, so a
       typed multi-catch (`catch (A | B $e)`) still yields its variable. */
    $var = preg_match('/(\$\w+)\s*\)\s*\{$/', trim($c['head']), $vm) ? preg_quote($vm[1], '/') : null;
    if ($var === null
        || !preg_match('/^\s*if\s*\(\s*songRelocateIsTransactionFatal\s*\(\s*' . $var . '\s*\)\s*\)\s*\{?\s*throw\b/', $c['body'])) {
        $unguarded++;
    }
}
ok('every best-effort catch inside the transaction re-throws a transaction-fatal error FIRST',
   $saveCatches !== [] && $unguarded === 0,
   count($saveCatches) . ' catch block(s) found between begin_transaction() and commit(), '
   . $unguarded . ' without the guard as their first statement. songRelocate() re-throwing '
   . '1213/1205 is undone by ANY later swallow before commit(): the commit then succeeds '
   . 'trivially and the endpoint answers ok:true naming a songId that does not exist');

/* ------------------------------------------- M2 — an omitted key is not a move -- */

echo "\nM2 — a save that never mentions the songbook does not move the song\n";

ok('the save core tests the RAW payload for a songbook key',
   preg_match("/array_key_exists\(\s*'songbook'\s*,\s*\\\$song\s*\)/", $save['code']) === 1,
   '$songbookAbbr is defaulted to Misc hundreds of lines earlier, so a test against '
   . 'it can never be false — a partial save re-keyed the song into Misc, cleared '
   . 'its Number and wrote a permanent redirect');

/* A3 — the WHOLE enclosing chain, not one level plus `strrpos($head, 'if (')`.
   That fallback searched all preceding source, so it happily returned a SIBLING
   `if (!$songbookSent && $prevRow !== null)` sitting hundreds of characters above
   — and both assertions below passed with the M2 fix deleted. It also failed on
   correct code: one extra neutral nesting level read as "guard missing". */
$relCall = strpos($save['code'], 'songRelocate(');
$chain   = $relCall === false ? [] : phpUnitsEnclosingBlocks($save['code'], $relCall);
$guardIx = null;
foreach ($chain as $i => $b) {
    if (preg_match('/^if\s*\(/', $b['head']) && strpos($b['head'], '$songbookSent') !== false) {
        $guardIx = $i;
        break;
    }
}
$guard = $guardIx === null ? '' : $chain[$guardIx]['head'];
ok('and the branch that calls songRelocate() is ENCLOSED by a guard on that flag',
   $guardIx !== null,
   'enclosing blocks were: ' . ($chain === []
       ? '(none — songRelocate() not found in editorSaveSongCore)'
       : implode(' | ', array_map(static fn(array $b): string => substr($b['head'], 0, 120), $chain))));
ok("the defeated `\$songbookAbbr !== ''` form is gone from that guard",
   $guardIx !== null && strpos($guard, "\$songbookAbbr !== '@STR@'") === false);
/* A3's other half: the guard is worthless if the call inside it is wrapped in a
   try/catch, because songRelocateAssertCascades() and the M3 rewrite both refuse
   by THROWING. Bounded at the guard itself, so the outer transaction try — which
   every statement in this function is legitimately inside — is not counted. */
ok('and nothing swallows the call between that guard and songRelocate()',
   $guardIx !== null && !swallowedWithin($save['code'], $relCall, '/\$songbookSent/'),
   'a try/catch here turns the cascade refusal and the fatal restriction rewrite '
   . 'back into silent no-ops, which is exactly what H3 and M3 removed');

/* --------------------------------------- F5 — the rename carries its Number -- */

echo "\nF5 — a rename tells the client the song's new Number, and the client applies it\n";

/* This is a two-file agreement, so both halves are asserted in one place. A
   comment in either file saying "keep these in sync" would be the failure, not
   the fix (rule #35). */
$respAssigned = strpos($save['code'], "\$respBody['assignedId']");
$respNumber   = strpos($save['code'], "\$respBody['number']");
/* SAME BLOCK, not merely "later in the file": `number` must be emitted by the
   very branch that emits the rename pair, or a save with no rename starts
   shipping a number the client will apply to a song that did not move. */
$assignedBlk = $respAssigned === false ? [] : phpUnitsEnclosingBlocks($save['code'], $respAssigned);
$numberBlk   = $respNumber === false ? [] : phpUnitsEnclosingBlocks($save['code'], $respNumber);
ok('save_song_core sends `number` from the SAME branch that sends the rename pair',
   $assignedBlk !== [] && $numberBlk !== [] && $assignedBlk[0]['open'] === $numberBlk[0]['open'],
   'both rename paths change the Number — a MOVE clears it, a #1380 draft promotion '
   . 'into an official book adopts the minted slot — and the client cannot tell which');

$editorJs = (string)file_get_contents($EDITORJS_PATH);
/* Comment-strip so this file's own explanatory prose (and editor.js's, which
   discusses `data.number` at length) cannot satisfy a code assertion. */
$editorCode = preg_replace('#/\*[\s\S]*?\*/#', ' ', $editorJs);
$editorCode = (string)preg_replace('#(^|[^:])//.*$#m', '$1', (string)$editorCode);
$editorRedacted = jsRedactLiterals($editorCode);

/* A11 — anchored on ONE identifier inside the `if` CONDITION, with the other
   asserted separately. The version this replaces matched the exact text
   `'data.assignedId && data.previousId'`, so swapping two `&&` operands — a
   no-op — turned three assertions red.
   A12 — and the block is brace-bounded, not `substr(..., 3000)`; that window ran
   past the rename block into unrelated functions, where any nearby mention of
   'edit-number' satisfied the DOM assertion. */
$renameIf    = jsIfWithToken($editorRedacted, 'data.assignedId');
$renameCond  = $renameIf === null ? '' : $renameIf['cond'];
$renameBody  = $renameIf === null ? null : jsBlockAfter($editorRedacted, $renameIf['open']);
$renameBlock = $renameBody === null ? '' : substr($editorCode, $renameBody[0], $renameBody[1] - $renameBody[0]);

ok('editor.js has the rename-relabel block', $renameIf !== null && $renameBlock !== '');
ok('and it is entered only when BOTH the new and the previous id arrived',
   strpos($renameCond, 'data.previousId') !== false,
   'condition read: ' . $renameCond);
ok('and it applies data.number to the in-memory song',
   preg_match('/\.number\s*=\s*data\.number/', $renameBlock) === 1,
   'without it the next save posts the stale number straight back, undoing the clear');
ok("and repaints #edit-number, or the DOM keeps feeding the old value back",
   strpos($renameBlock, "'edit-number'") !== false,
   'bindMetadataListeners() writes #edit-number straight into song.number on the next '
   . 'keystroke or save, so an un-refreshed field silently restores the old number');

/* -------------------------------------------------- MUTATION SELF-TESTS ---- */

echo "\nMutation self-tests — the structural helpers must be able to fail\n";

/*
 * The five defeats above were all found by mutating the REAL tree, which cannot
 * be done from inside a test run. What CAN live here is proof that the helpers
 * those fixes rest on answer the question they claim to — in both directions.
 * Each pair is a mutant that must be caught and a correct-but-different variant
 * that must not be (rule #34's two failure modes, one test each).
 */

/** Convenience: units of a synthetic source. */
function hardSelfUnits(string $php): array { return phpSourceUnits($php); }

/* T1 — enclosing chain vs. a SIBLING `if` above (the A3 defeat, in miniature). */
$t1 = hardSelfUnits('<?php
function f($a, $b) {
    if ($flagSent && $a) { doSomethingElse(); }
    if ($a !== $b) { try { target(); } catch (\Throwable $e) {} }
}');
$t1code = $t1['f']['code'];
$t1pos  = strpos($t1code, 'target(');
$t1heads = array_map(static fn(array $x): string => $x['head'], phpUnitsEnclosingBlocks($t1code, $t1pos));
ok('T1a: a sibling `if` above the call is NOT reported as enclosing it',
   !preg_match('/\$flagSent/', implode(' | ', $t1heads)),
   'chain: ' . implode(' | ', $t1heads));
ok('T1b: …while the real enclosing chain (try, then the if) IS reported',
   count($t1heads) >= 2 && preg_match('/\btry$/', $t1heads[0]) === 1
   && strpos($t1heads[1], '$a !== $b') !== false,
   'chain: ' . implode(' | ', $t1heads));
ok('T1c: and the try/catch wrapper is detected as a swallow',
   swallowedWithin($t1code, $t1pos, '/\$a !== \$b/'));

/* T2 — neutral nesting must NOT hide a correct guard (the other A3 direction). */
$t2 = hardSelfUnits('<?php
function g($sent) {
    if ($sent) { foreach ($rows as $r) { target(); } }
}');
$t2code = $t2['g']['code'];
ok('T2: one extra neutral nesting level inside the guard still finds the guard',
   enclosedByHead($t2code, strpos($t2code, 'target('), '/\$sent/'));

/* T3 — `try{` with no space must count as a try (the A4 defeat). */
ok('T3a: T_TRY counts `try{` written without a space',
   phpUnitsCountToken('function h() { try{ x(); } catch (\Throwable $e) {} }', T_TRY) === 1);
ok('T3b: …and the word "try" inside an identifier is not counted',
   phpUnitsCountToken('$retry = 1; $trying = 2;', T_TRY) === 0);

/* T4 — sqlOnly must exclude prose that merely contains SQL vocabulary (A5). */
$t4 = hardSelfUnits('<?php
function q(\mysqli $db) {
    $msg  = "the UPDATE_RULE on tblSongs(SongId) was not CASCADE";
    $key  = \'UPDATE_RULE\';
    $stmt = $db->prepare("SELECT rc.UPDATE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc");
}');
$t4unit = $t4['q'];
ok('T4a: the refusal sentence and the array key are in `strings`',
   count($t4unit['strings']) >= 3);
ok('T4b: …but `sqlOnly` holds ONLY the statement',
   count($t4unit['sqlOnly']) === 1
   && stripos($t4unit['sqlOnly'][0], 'INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS') !== false,
   'sqlOnly: ' . json_encode($t4unit['sqlOnly']));
ok('T4c: deleting the statement empties sqlOnly while `strings` still reads well',
   hardSelfUnits('<?php function q() { $msg = "UPDATE_RULE on tblSongs(SongId)"; }')['q']['sqlOnly'] === []);

/* T5 — the statement marker must NAME the table it stood for (A12). */
$t5 = hardSelfUnits('<?php
function w(\mysqli $db) {
    $db->prepare("SELECT 1 FROM tblSongRedirects WHERE OldSongId = ?");
    $db->prepare("UPDATE tblContentRestrictions SET EntityId = ?");
    $db->prepare("DELETE FROM tblSongbookEntries WHERE SongId = ?");
}')['w']['code'];
ok('T5: each statement leaves a marker naming its verb and table',
   strpos($t5, '@SQL:SELECT:tblSongRedirects@') !== false
   && strpos($t5, '@SQL:UPDATE:tblContentRestrictions@') !== false
   && strpos($t5, '@SQL:DELETE:tblSongbookEntries@') !== false,
   $t5);

/* T6 — the JS block walk must stop at the block's own brace (A12). */
$t6src = "if (data.previousId && data.assignedId) {\n"
       . "    renamed.number = data.number;\n"
       . "}\n"
       . "function unrelated() { setVal('edit-number', ''); }\n";
$t6red = jsRedactLiterals($t6src);
$t6if  = jsIfWithToken($t6red, 'data.assignedId');
$t6b   = $t6if === null ? null : jsBlockAfter($t6red, $t6if['open']);
$t6blk = $t6b === null ? '' : substr($t6src, $t6b[0], $t6b[1] - $t6b[0]);
ok('T6a: the block is found with the && operands in the OTHER order',
   $t6if !== null && strpos($t6blk, 'data.number') !== false);
ok('T6b: …and it stops at the closing brace, not N characters later',
   strpos($t6blk, 'edit-number') === false,
   'block read: ' . $t6blk);
ok('T6c: a brace inside a JS string does not unbalance the walk',
   (static function (): bool {
       $s = "if (data.assignedId) { var t = '} not a brace'; ok(); }\n";
       $r = jsRedactLiterals($s);
       $f = jsIfWithToken($r, 'data.assignedId');
       $b = $f === null ? null : jsBlockAfter($r, $f['open']);
       return $b !== null && strpos(substr($s, $b[0], $b[1] - $b[0]), 'ok()') !== false;
   })());
/* T6d — the one that actually bit. editor.js's HTML-escaper is
   `String(s).replace(/[&<>"']/g, …)`: an un-redacted quote inside a regex
   character class swallows everything that follows as a string, and the rename
   block (10 000 characters later) simply stopped existing. Real source, real
   failure, so it gets a real self-test rather than a comment. */
ok('T6d: a regex literal containing quotes does not swallow the rest of the file',
   (static function (): bool {
       $s = "var e = String(s).replace(/[&<>\"']/g, f);\n"
          . "if (data.assignedId) { renamed.number = data.number; }\n";
       $f = jsIfWithToken(jsRedactLiterals($s), 'data.assignedId');
       return $f !== null;
   })());
ok('T6e: …and ordinary division is still not mistaken for a regex',
   (static function (): bool {
       /* `(a) / b; …` — were the `/` read as a regex opener it would redact to
          the next `/` or newline and hide the `if` on the same line. */
       $s = "var r = (a) / b; if (data.assignedId) { hit(); }\n";
       $red = jsRedactLiterals($s);
       return strpos($red, 'data.assignedId') !== false;
   })());

/* T7 — the catch-block walk must bound on braces, not a fixed window. */
$t7 = phpUnitsCatchBlocks(
    'try { a(); } catch (\Throwable $_e) { if (guard($_e)) { throw $_e; } log(); } after();'
);
ok('T7a: exactly one catch block is found, bounded by its own braces',
   count($t7) === 1 && strpos($t7[0]['body'], 'after()') === false,
   json_encode($t7));
ok('T7b: …and a guard moved off the FIRST statement is detected',
   count($t7) === 1
   && preg_match('/^\s*if\s*\(\s*guard\s*\(/', $t7[0]['body']) === 1
   && preg_match(
       '/^\s*if\s*\(\s*guard\s*\(/',
       phpUnitsCatchBlocks('try { a(); } catch (\Throwable $_e) { log(); if (guard($_e)) { throw $_e; } }')[0]['body']
   ) === 0);

if ($fail === 0) {
    echo "\nAll songbook-move hardening assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
