<?php

declare(strict_types=1);

/**
 * iHymns — PHP source → analysis units (shared test library)
 * ==========================================================
 *
 * Turns a PHP file into the three views a source assertion actually needs, split
 * per FUNCTION rather than per file:
 *
 *   `code`    — the token stream with comments removed and every string literal
 *            replaced by something that cannot be mistaken for code. An
 *            identifier-like literal (`'SongbookAbbr'`, `'songbook'`) survives
 *            verbatim because it IS load-bearing code — it names a column or a
 *            field key. Anything else (prose, SQL, messages) collapses to the
 *            atom `'@STR@'`, plus the marker `@SQL:<VERB>:<table>@` when the
 *            literal BEGAN a SQL statement, so positional checks can still see
 *            "a read/write against tblX happened here" without being able to
 *            read the sentence.
 *
 *   `strings` — EVERY string chain in the unit, RECONSTRUCTED across `.`
 *            concatenation and interpolation, so a statement split over five
 *            PHP string pieces reads as one statement. It is NOT a SQL view:
 *            it holds refusal sentences, `error_log` messages, array subscripts
 *            (`'UPDATE_RULE'`), everything. Use it when the PROSE is the point.
 *
 *   `sqlOnly` — the subset of `strings` that actually BEGINS a SQL statement
 *            (`SELECT` / `INSERT` / `UPDATE` / `DELETE` / `REPLACE`). Use it for
 *            every assertion about the SHAPE of a query.
 *
 *   `comments` — every comment in the unit, verbatim (#1694). The `code` view
 *            DELIBERATELY erases comments so prose can never satisfy a code
 *            assertion (failure 1 below) — but a MARKER convention
 *            (`@deleted-visible:`, the component-json guard's
 *            `lines-json-fallback`) is the opposite direction: the assertion is
 *            ABOUT the comment. Collected as its own view rather than leaked
 *            back into `code`, so the two uses can never contaminate each
 *            other: a marker can vouch for a unit, and a comment still cannot
 *            impersonate a call.
 *
 * WHY `strings` AND `sqlOnly` ARE SEPARATE (A5, 2026-07-31)
 * --------------------------------------------------------
 * `strings` was called `sql`, and the name was a lie that a review turned into a
 * bypass. The hardening guard asserted "the pre-check's query filters to FKs
 * referencing tblSongs(SongId) and reads UPDATE_RULE" by `stripos()`-ing that
 * field for three substrings — and the function's REFUSAL SENTENCE contains
 * "tblSongs(SongId)" while its result loop subscripts `$r['UPDATE_RULE']`. So the
 * whole prepare/execute/fetch could be replaced with `$rows = [];` and every H3
 * assertion stayed green. A view named for a language must contain only that
 * language, or every assertion pointed at it is really an assertion about the
 * file's vocabulary.
 *
 * WHY PER FUNCTION (#1688)
 * ------------------------
 * Three separate failures in the guard this replaces, all found by adversarial
 * review mutating the real tree:
 *
 *  1. **Prose satisfied the assertion.** The old view stripped comments but kept
 *     string CONTENT (it needed the SQL). So `error_log('songRelocate() not
 *     used here')` made a file pass the "does it call songRelocate()?" check
 *     with the raw write still in place — verified, full suite green. Comment
 *     stripping had been added precisely to stop prose satisfying a guard; the
 *     same trick simply moved into a string literal. The distinction the `code`
 *     view draws is the real one: in code, `$column === 'SongbookAbbr'` is three
 *     tokens; in prose it is one.
 *
 *  2. **A quoted value truncated the statement.** The old parser bounded an
 *     `UPDATE` at the first quote character, so
 *     `UPDATE t SET a = ?, b = 'x' WHERE SongId = ?` lost its `WHERE` and was
 *     discarded as unparseable. Real code already tripped this
 *     (`includes/lyrics_ingest.php:659`). Reconstructing the literal from tokens
 *     removes the whole class — the string's boundaries come from the
 *     tokenizer, which cannot be confused by the string's contents.
 *
 *  3. **Scope was the FILE.** "Does this file mention the helper anywhere?" is
 *     satisfied forever by one call, so the two files most likely to grow a new
 *     write site were the two that had become permanently exempt. A function is
 *     the smallest unit where "this write is handled" is a meaningful claim.
 *
 * NOT a PHP parser. It does not resolve constants, follow variables between
 * functions, or understand that a helper three calls away does the work. Those
 * are stated limits, not oversights — see the callers' "what this cannot catch".
 *
 * @see https://www.php.net/manual/en/function.token-get-all.php
 */

/**
 * Decode a PHP string literal token's text to its value.
 *
 * ELI5: take what was typed between the quotes and work out the actual text.
 */
function phpUnitsDecodeLiteral(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    $q = $raw[0];
    if ($q === "'" || $q === '"') {
        $body = substr($raw, 1, -1);
        if ($q === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }
        // Double quotes: only the escapes that change LENGTH matter here.
        return str_replace(
            ['\\\\', '\\"', '\\n', '\\t', '\\r', '\\$'],
            ['\\', '"', "\n", "\t", "\r", '$'],
            $body
        );
    }
    return $raw;
}

/** Is this literal an identifier — i.e. is it naming something, not saying something? */
function phpUnitsIsIdentifierLiteral(string $value): bool
{
    return $value !== '' && (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $value);
}

/**
 * Does this string literal BEGIN a SQL statement?
 *
 * ELI5: does this text start with "SELECT" / "UPDATE" / …? If it only mentions
 * one of those words half-way through a sentence, it is prose, not a query.
 *
 * Detail: anchored deliberately. An un-anchored `stripos($s, 'UPDATE')` is what
 * let a refusal message ("…would fail or orphan its child rows…UPDATE_RULE…")
 * and a `' ON DUPLICATE KEY UPDATE …'` fragment both read as statements. A real
 * statement literal always starts at the verb — every one in this tree does,
 * including the multi-line ones, because the leading newline is inside the
 * indentation of the NEXT line, not before the verb.
 */
function phpUnitsIsSqlStatement(string $value): bool
{
    return (bool)preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $value);
}

/**
 * Is this `default` a `match` ARM rather than a `switch` LABEL?
 *
 * ELI5: `default` means two different things in PHP. Inside a `switch` it opens
 * a branch of the dispatch; inside a `match` it is just the last option in an
 * expression. Only the first one deserves its own analysis unit.
 *
 * WHY BOTH SIGNALS (#1707)
 * ------------------------
 * They are INDEPENDENT, and either alone has a blind spot:
 *
 *  - STRUCTURAL (`$matchDepths` non-empty) is the one #1707 asks to prefer,
 *    because the enclosing construct is a fact rather than an inference. Its
 *    blind spot is a `match` the brace tracker never saw open.
 *  - LOOKAHEAD (`default` followed by `=>` rather than `:`) reads the arm's own
 *    syntax and needs no state at all. Its blind spot is that it is local — it
 *    cannot tell you what you are nested inside.
 *
 * ORed, because both answer the same question and a false "this is a switch
 * label" is the expensive direction: it mints a phantom unit that STEALS the
 * real default's name (`case default` for the arm, `case default#1` for the
 * genuine one), which is how a guard ends up asserting against the wrong half
 * of a split case. T_CASE is unaffected — `match` has no `case` keyword.
 *
 * @param list<array{0:int,1:string}|string> $toks the full token stream
 * @param int                                $i    index of the T_DEFAULT/T_CASE
 * @param int                                $id   the token id at $i
 * @param list<int>                          $matchDepths brace depths of open matches
 *
 * @see https://www.php.net/manual/en/control-structures.match.php
 */
function phpUnitsIsMatchArmDefault(array $toks, int $i, int $id, array $matchDepths): bool
{
    if ($id !== T_DEFAULT) {
        return false;                      // `case` never appears inside a match
    }
    if ($matchDepths !== []) {
        return true;                       // structural: we are inside a match
    }
    /* Lookahead: an arm is `default =>`, a label is `default:`. */
    $n = count($toks);
    for ($k = $i + 1; $k < $n; $k++) {
        if (is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) { continue; }
        if (is_array($toks[$k]) && $toks[$k][0] === T_COMMENT)    { continue; }
        return is_array($toks[$k]) && $toks[$k][0] === T_DOUBLE_ARROW;
    }
    return false;
}

/**
 * Split a PHP source into per-function analysis units.
 *
 * @return array<string, array{code:string, strings:list<string>, sqlOnly:list<string>, comments:list<string>}>
 *         Keys are function names; everything outside any function body is
 *         collected under `(file scope)`.
 */
function phpSourceUnits(string $src): array
{
    $toks = @token_get_all($src);
    if (!is_array($toks)) {
        return [];
    }

    $units   = ['(file scope)' => ['code' => '', 'strings' => [], 'comments' => []]];
    $stack   = [];                 // [unitName, braceDepthAtOpen]
    $depth   = 0;
    $awaitFn = false;              // seen `function`, waiting for its body `{`
    $fnName  = null;
    $anon    = 0;
    /* Has the parameter list opened since `function`? A function's NAME can only
       sit between `function` and its `(`; anything after that paren is a
       parameter type, and anything after the closing paren is a return type.
       Without this flag the naming rule took the next T_STRING wherever it
       landed, so `function ($a, int $b)` became the unit `int` (#1703) and
       `function (): bool` became `bool` (#1707).

       That is not cosmetic. DECLARATION ORDER decided who won: a closure whose
       parameter type shares a name with a real function, declared FIRST, took
       that function's unit name outright — and an assertion reading
       `$units['validate']` then read the closure's body while believing it read
       validate(). No error, no warning, confident assertion about the wrong
       code. Found live in 10 files under appWeb/, not the single latent case
       the issues predicted. */
    $sawFnParen = false;
    /* Brace depths at which a `match` body opened, innermost last.

       A `match` arm's `default =>` tokenizes as T_DEFAULT — the SAME token a
       `switch`'s `default:` produces — so the case-splitter minted a phantom
       `case default` unit part-way through a real case and split its
       attribution in two (#1707). Worse, the phantom CLAIMED the name, pushing
       the switch's genuine default to `case default#1`.

       Tracked structurally rather than by lookahead because #1707 asks for the
       structural signal where both are available: the enclosing construct is a
       fact, whereas `=>`-vs-`:` is an inference. The lookahead is kept as a
       second, independent signal below. */
    $matchDepths = [];
    $awaitMatch  = false;          // seen `match`, waiting for its body `{`
    /* Procedural dispatch files (api.php, api2.php) put every handler in one
       giant top-level `switch`, so "file scope" would be a single unit holding
       fifty unrelated handlers — and one handler's correctness would vouch for
       all of them, which is the per-file scoping bug this split exists to fix
       (#1688). Each top-level `case '…':` therefore gets its own unit. */
    $caseName    = null;
    $switchDepth = null;

    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t       = $toks[$i];
        $unit    = $stack ? $stack[count($stack) - 1][0] : ($caseName ?? '(file scope)');
        if (!isset($units[$unit])) {
            $units[$unit] = ['code' => '', 'strings' => [], 'comments' => []];
        }

        /* ---- structural bookkeeping ------------------------------------- */
        if (is_array($t)) {
            if ($t[0] === T_FUNCTION) {
                $awaitFn    = true;
                $fnName     = null;
                $sawFnParen = false;
            } elseif ($t[0] === T_MATCH) {
                $awaitMatch = true;
            } elseif ($awaitFn && !$sawFnParen && $t[0] === T_STRING && $fnName === null) {
                /* Between `function` and `(` — this really is the name. */
                $fnName = $t[1];
            } elseif (!$stack && ($t[0] === T_CASE || $t[0] === T_DEFAULT)
                      && !phpUnitsIsMatchArmDefault($toks, $i, $t[0], $matchDepths)) {
                /* Name the unit after the case's own literal, so a failure
                   report says `case 'metadata_field_update'` rather than a line
                   number that will be wrong by next week. Consecutive
                   fall-through labels (`case 'a': case 'b':`) share a body, and
                   naming it after the LAST label read is fine — the report only
                   needs to be locatable. */
                $label = null;
                for ($k = $i + 1; $k < $n && $k < $i + 6; $k++) {
                    if (is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) { continue; }
                    if (is_array($toks[$k]) && $toks[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $label = phpUnitsDecodeLiteral($toks[$k][1]);
                    }
                    break;
                }
                $caseName = 'case ' . ($label !== null ? "'" . $label . "'" : 'default');
                /* Two switches in one file can carry the same label. Merging
                   them would re-create exactly the vouching bug this split
                   fixes, so disambiguate rather than reuse. */
                if (isset($units[$caseName])) { $caseName .= '#' . (++$anon); }
                $units[$caseName] = ['code' => '', 'strings' => [], 'comments' => []];
                $unit = $caseName;
            } elseif (!$stack && $t[0] === T_SWITCH) {
                $switchDepth = $depth + 1;
            }
        } else {
            if ($t === '(' && $awaitFn) {
                /* The parameter list has opened: no further T_STRING can be
                   this function's name (#1703 / #1707). */
                $sawFnParen = true;
            }
            if ($t === '{') {
                $depth++;
                if ($awaitMatch) {
                    $matchDepths[] = $depth;
                    $awaitMatch    = false;
                }
                if ($awaitFn) {
                    $name = $fnName ?? ('{closure#' . (++$anon) . '}');
                    // Two same-named methods in one file (different classes) —
                    // keep both rather than silently merging their bodies.
                    if (isset($units[$name])) {
                        $name .= '#' . (++$anon);
                    }
                    $stack[]  = [$name, $depth];
                    $units[$name] = ['code' => '', 'strings' => [], 'comments' => []];
                    $awaitFn  = false;
                    $fnName   = null;
                    $unit     = $name;
                }
            } elseif ($t === '}') {
                if ($stack && $stack[count($stack) - 1][1] === $depth) {
                    array_pop($stack);
                }
                /* Leaving a match body. Checked BEFORE the decrement, to mirror
                   how the function stack above pops at its opening depth. */
                if ($matchDepths && $matchDepths[count($matchDepths) - 1] === $depth) {
                    array_pop($matchDepths);
                }
                $depth--;
                /* Left the dispatch switch — stop attributing top-level code to
                   the last case, or everything after the switch would inherit
                   that handler's songRelocate() call and be vouched for by it. */
                if ($switchDepth !== null && $depth < $switchDepth) {
                    $switchDepth = null;
                    $caseName    = null;
                }
            } elseif ($t === ';' && $awaitFn) {
                // `abstract function f();` / an interface method — no body.
                $awaitFn = false;
                $fnName  = null;
            }
        }

        /* ---- render into the unit's views ------------------------------- */
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                /* Erased from `code` (prose must not satisfy a code assertion)
                   but kept VERBATIM in `comments`, where marker conventions
                   like `@deleted-visible:` live (#1694 — see the file header). */
                $units[$unit]['code'] .= ' ';
                $units[$unit]['comments'][] = $t[1];
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                [$rendered, $joined, $consumed] = phpUnitsReadStringChain($toks, $i);
                $units[$unit]['code'] .= $rendered;
                if ($joined !== null) {
                    $units[$unit]['strings'][] = $joined;
                }
                $i = $consumed;
                continue;
            }
            if ($t[0] === T_START_HEREDOC) {
                // Heredoc/nowdoc: swallow to the terminator, keep it opaque.
                $body = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($toks[$j]) && $toks[$j][0] === T_END_HEREDOC) { break; }
                    $body .= is_array($toks[$j]) ? $toks[$j][1] : $toks[$j];
                }
                $units[$unit]['code'] .= phpUnitsRenderOpaque($body);
                if (phpUnitsIsSqlStatement($body) || stripos($body, 'UPDATE') !== false) {
                    $units[$unit]['strings'][] = $body;
                }
                $i = $j;
                continue;
            }
            $units[$unit]['code'] .= $t[1];
            continue;
        }

        if ($t === '"') {
            // A double-quoted string WITH interpolation: `"` … `"`.
            $body = '';
            for ($j = $i + 1; $j < $n; $j++) {
                if (!is_array($toks[$j]) && $toks[$j] === '"') { break; }
                if (is_array($toks[$j])) {
                    $body .= $toks[$j][0] === T_VARIABLE ? '{' . $toks[$j][1] . '}' : $toks[$j][1];
                } else {
                    $body .= $toks[$j];
                }
            }
            $units[$unit]['code'] .= phpUnitsRenderOpaque($body);
            if (phpUnitsIsSqlStatement($body) || stripos($body, 'UPDATE') !== false) {
                $units[$unit]['strings'][] = $body;
            }
            $i = $j;
            continue;
        }

        $units[$unit]['code'] .= $t;
    }

    foreach ($units as $k => $u) {
        $units[$k]['code'] = (string)preg_replace('/\s+/', ' ', $u['code']);
        /* `sqlOnly` is DERIVED from `strings`, never collected in parallel: two
           lists filled at two sites drift, and a shape assertion pointed at the
           wrong one is exactly the A5 bypass this view exists to close. */
        $units[$k]['sqlOnly'] = array_values(array_filter(
            $u['strings'],
            static fn(string $s): bool => phpUnitsIsSqlStatement($s)
        ));
    }

    return $units;
}

/**
 * Render a non-identifier literal so it cannot be read as code, while leaving a
 * positional marker naming the statement it stood for.
 *
 * ELI5: replace the text with a blank tile, but stamp the tile with "this was a
 * SELECT against tblSongRedirects" so an ordering check can still find it.
 *
 * Detail: the marker used to be a bare `@SQLUPD@`, which made every write in a
 * function look identical — so an assertion like "the tblSongbookEntries block is
 * inside its existence gate" had no way to point at the statement it named and
 * fell back to comparing indexes in two different lists (A12). Tagging the verb
 * and the table makes the `code` view POSITIONALLY precise without making it
 * readable: `@SQL:UPDATE:tblContentRestrictions@` says where a write is, and
 * still says nothing about what the sentence around it claimed.
 *
 * Anchored via phpUnitsIsSqlStatement(), so a message that merely contains the
 * word "update" no longer mints a phantom write marker.
 */
function phpUnitsRenderOpaque(string $value): string
{
    $marker = '';
    if (phpUnitsIsSqlStatement($value)) {
        preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $value, $v);
        $verb = strtoupper($v[1]);
        /* Where the table name sits depends on the verb: UPDATE names it first,
           INSERT/REPLACE after INTO, SELECT/DELETE after FROM. Getting this
           wrong is not a crash, it is a marker that says `@SQL:SELECT:1@` —
           which reads like a table and matches nothing, i.e. a positional check
           that silently stops locating anything. */
        $tablePattern = match ($verb) {
            'UPDATE'           => '/^\s*UPDATE\s+`?([\w.]+)`?/i',
            'INSERT', 'REPLACE' => '/\bINTO\s+`?([\w.]+)`?/i',
            default            => '/\bFROM\s+`?([\w.]+)`?/i',
        };
        $table  = preg_match($tablePattern, $value, $m) ? $m[1] : '?';
        $marker = ' @SQL:' . $verb . ':' . $table . '@ ';
    }
    return "'@STR@'" . $marker;
}

/**
 * Read a `'a' . $b . 'c'` concatenation chain starting at a string token.
 *
 * ELI5: SQL is often glued together from several pieces; read all the pieces at
 * once so the statement is whole before anyone tries to understand it.
 *
 * @return array{0:string,1:?string,2:int} [code rendering, joined SQL or null, last token index]
 */
function phpUnitsReadStringChain(array $toks, int $i): array
{
    $n        = count($toks);
    $parts    = [];
    $rendered = '';
    $last     = $i;

    while ($i < $n) {
        $t = $toks[$i];
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $val     = phpUnitsDecodeLiteral($t[1]);
            $parts[] = $val;
            $rendered .= phpUnitsIsIdentifierLiteral($val)
                ? "'" . $val . "'"
                : phpUnitsRenderOpaque($val);
            $last = $i;
        } elseif (is_array($t) && $t[0] === T_VARIABLE) {
            // An interpolated column/table name — preserve the SHAPE so a
            // dynamic-column write is still recognisable as one.
            $parts[]   = '{' . $t[1] . '}';
            $rendered .= $t[1];
            $last      = $i;
        } else {
            break;
        }

        // Look ahead for `.` joining another piece.
        $j = $i + 1;
        while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; }
        if ($j >= $n || is_array($toks[$j]) || $toks[$j] !== '.') { break; }
        $rendered .= ' . ';
        $k = $j + 1;
        while ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) { $k++; }
        if ($k >= $n) { break; }
        $next = $toks[$k];
        $isPiece = is_array($next) && ($next[0] === T_CONSTANT_ENCAPSED_STRING || $next[0] === T_VARIABLE);
        if (!$isPiece) { break; }
        $i = $k;
    }

    $joined = implode('', $parts);
    return [$rendered, ($joined !== '' ? $joined : null), $last];
}

/**
 * Normalise a SQL statement for matching: drop backticks, collapse whitespace.
 *
 * ELI5: `` `tblSongs` `` and `tblSongs` are the same table; make them look the
 * same before comparing.
 *
 * Detail: an un-normalised `UPDATE\s+tblSongs\b` pattern simply does not see
 * ``UPDATE `tblSongs` ``, and `api2.php` already writes backticked identifiers —
 * so this was a live bypass, not a theoretical one.
 */
function phpUnitsNormaliseSql(string $sql): string
{
    return (string)preg_replace('/\s+/', ' ', str_replace('`', '', $sql));
}

/* ==========================================================================
 *  STRUCTURAL WALKERS — "which block am I in?", not "how many characters away?"
 *
 *  Every one of these exists because a character window or a `strrpos()` over
 *  all preceding source was defeated on the real tree (A3, A4, A11, A12,
 *  2026-07-31). They run on the `code` view, where every string literal has
 *  already been reduced to `'@STR@'` — so no brace, paren or semicolon inside a
 *  string can unbalance a walk. That property is what makes a text-level walk
 *  sound enough to assert structure with; it does not survive being pointed at
 *  raw source, so do not do that.
 * ========================================================================== */

/**
 * The blocks that ENCLOSE $pos, innermost first, out to the unit's own body.
 *
 * ELI5: "which ifs / trys / loops am I inside?" — the whole chain, not just the
 * nearest one, and never a sibling that merely happens to sit above me.
 *
 * WHY THE WHOLE CHAIN (A3)
 * ------------------------
 * The version this replaces read ONE enclosing level and then fell back to
 * `strrpos($head, 'if (')` over ALL preceding source. Both halves were wrong in
 * opposite directions, and a review demonstrated each on the real file:
 *
 *  - TOO WEAK: wrapping the relocate branch in `try { … } catch` (an entirely
 *    natural change now the pre-check throws) made the single level land on
 *    `try {`, whereupon `strrpos` found an unrelated EARLIER
 *    `if (!$songbookSent && $prevRow !== null)` — a SIBLING, hundreds of
 *    characters above — and both M2 assertions passed with the M2 fix deleted.
 *  - TOO STRONG: one extra neutral nesting level inside the correct guard
 *    reported that correct guard as missing. A guard that fails on correct code
 *    gets weakened or deleted rather than fixed (rule #34).
 *
 * Collecting the chain fixes both: a sibling `if` is never in it, and neutral
 * nesting simply adds an entry the caller ignores.
 *
 * The `head` of a block is the source between the previous statement boundary
 * and the block's `{` — `if (…)`, `foreach (…)`, `try`, `catch (…)`, `else`,
 * `function f(…)`. The backward scan for that boundary is PAREN-AWARE, because
 * `for ($i = 0; $i < $n; $i++)` puts semicolons inside the head itself.
 *
 * @return list<array{head:string, open:int}> innermost first; empty at file scope.
 */
function phpUnitsEnclosingBlocks(string $code, int $pos): array
{
    $out   = [];
    $i     = min($pos, strlen($code)) - 1;
    $depth = 0;

    while ($i >= 0) {
        $c = $code[$i];
        if ($c === '}') { $depth++; $i--; continue; }
        if ($c !== '{')  { $i--; continue; }
        if ($depth > 0)  { $depth--; $i--; continue; }

        /* Unmatched `{` — this block encloses $pos. Read its head backwards to
           the previous statement boundary at paren depth 0. */
        $parens = 0;
        $j      = $i - 1;
        for (; $j >= 0; $j--) {
            $d = $code[$j];
            if ($d === ')') { $parens++; continue; }
            if ($d === '(') { if ($parens > 0) { $parens--; } continue; }
            if ($parens === 0 && ($d === ';' || $d === '{' || $d === '}')) { break; }
        }
        $out[] = ['head' => trim(substr($code, $j + 1, $i - $j - 1)), 'open' => $i];

        /* Step outside this block and look for ITS parent. */
        $i     = $i - 1;
        $depth = 0;
    }

    return $out;
}

/**
 * The body of the block whose `{` is at $open: [bodyStart, bodyEnd) offsets.
 *
 * ELI5: find the matching closing brace, so "inside this block" is a real
 * boundary rather than "the next 3000 characters".
 *
 * @return array{0:int,1:int} start (just after `{`) and end (at the matching `}`);
 *         end is strlen($code) when the source is truncated / unbalanced.
 */
function phpUnitsBlockBody(string $code, int $open): array
{
    $n     = strlen($code);
    $depth = 0;
    for ($i = $open; $i < $n; $i++) {
        if ($code[$i] === '{') { $depth++; continue; }
        if ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) { return [$open + 1, $i]; }
        }
    }
    return [$open + 1, $n];
}

/**
 * Every `catch (…) { … }` block in a unit, as [head, body] pairs.
 *
 * ELI5: list the "if it goes wrong, do this" blocks, with their real contents.
 *
 * Detail: used instead of `substr($code, $hit + $len, 120)`. A fixed window is
 * both under- and over-inclusive — it stops mid-statement on a long first line
 * and runs into the NEXT catch on a short one — and "the guard is the FIRST
 * statement of this catch" is only a meaningful claim against the block's own
 * boundaries.
 *
 * @return list<array{head:string, body:string, open:int}>
 */
function phpUnitsCatchBlocks(string $code): array
{
    $out = [];
    if (!preg_match_all('/\bcatch\s*\([^)]*\)\s*\{/', $code, $m, PREG_OFFSET_CAPTURE)) {
        return $out;
    }
    foreach ($m[0] as $hit) {
        $open        = $hit[1] + strlen($hit[0]) - 1;   // index of the `{`
        [$s, $e]     = phpUnitsBlockBody($code, $open);
        $out[]       = ['head' => $hit[0], 'body' => substr($code, $s, $e - $s), 'open' => $open];
    }
    return $out;
}

/**
 * How many times does a KEYWORD TOKEN appear in a unit's code view?
 *
 * ELI5: count real `try` keywords, not the letters t-r-y wherever they land.
 *
 * Detail: the assertion this replaces was `substr_count($code, 'try {') === 1`.
 * Source written `try{` renders as `try{` and was invisible to it, so the M3
 * swallow could be restored verbatim (minus one space) with the suite green.
 * Re-tokenising the code view answers the question that was actually being
 * asked. The view is a re-joined token stream, so it re-tokenises cleanly.
 *
 * @param int $tokenId e.g. `T_TRY`, `T_CATCH`, `T_THROW`.
 */
function phpUnitsCountToken(string $code, int $tokenId): int
{
    $toks = @token_get_all('<?php ' . $code);
    if (!is_array($toks)) { return 0; }
    $n = 0;
    foreach ($toks as $t) {
        if (is_array($t) && $t[0] === $tokenId) { $n++; }
    }
    return $n;
}
