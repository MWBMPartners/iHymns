<?php

declare(strict_types=1);

/**
 * iHymns — PHP source → analysis units (shared test library)
 * ==========================================================
 *
 * Turns a PHP file into the two views a source assertion actually needs, split
 * per FUNCTION rather than per file:
 *
 *   `code` — the token stream with comments removed and every string literal
 *            replaced by something that cannot be mistaken for code. An
 *            identifier-like literal (`'SongbookAbbr'`, `'songbook'`) survives
 *            verbatim because it IS load-bearing code — it names a column or a
 *            field key. Anything else (prose, SQL, messages) collapses to the
 *            atom `'@STR@'`, plus the marker `@SQLUPD@` when the literal was an
 *            `UPDATE <table>` statement, so positional checks can still see
 *            "a write happened here" without being able to read the sentence.
 *
 *   `sql`  — every SQL-looking string literal, RECONSTRUCTED across `.`
 *            concatenation and interpolation, so a statement split over five
 *            PHP string pieces reads as one statement.
 *
 * WHY BOTH, AND WHY PER FUNCTION (#1688)
 * --------------------------------------
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
 * Split a PHP source into per-function analysis units.
 *
 * @return array<string, array{code:string, sql:list<string>}>
 *         Keys are function names; everything outside any function body is
 *         collected under `(file scope)`.
 */
function phpSourceUnits(string $src): array
{
    $toks = @token_get_all($src);
    if (!is_array($toks)) {
        return [];
    }

    $units   = ['(file scope)' => ['code' => '', 'sql' => []]];
    $stack   = [];                 // [unitName, braceDepthAtOpen]
    $depth   = 0;
    $awaitFn = false;              // seen `function`, waiting for its body `{`
    $fnName  = null;
    $anon    = 0;
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
            $units[$unit] = ['code' => '', 'sql' => []];
        }

        /* ---- structural bookkeeping ------------------------------------- */
        if (is_array($t)) {
            if ($t[0] === T_FUNCTION) {
                $awaitFn = true;
                $fnName  = null;
            } elseif ($awaitFn && $t[0] === T_STRING && $fnName === null) {
                $fnName = $t[1];
            } elseif (!$stack && ($t[0] === T_CASE || $t[0] === T_DEFAULT)) {
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
                $units[$caseName] = ['code' => '', 'sql' => []];
                $unit = $caseName;
            } elseif (!$stack && $t[0] === T_SWITCH) {
                $switchDepth = $depth + 1;
            }
        } else {
            if ($t === '{') {
                $depth++;
                if ($awaitFn) {
                    $name = $fnName ?? ('{closure#' . (++$anon) . '}');
                    // Two same-named methods in one file (different classes) —
                    // keep both rather than silently merging their bodies.
                    if (isset($units[$name])) {
                        $name .= '#' . (++$anon);
                    }
                    $stack[]  = [$name, $depth];
                    $units[$name] = ['code' => '', 'sql' => []];
                    $awaitFn  = false;
                    $fnName   = null;
                    $unit     = $name;
                }
            } elseif ($t === '}') {
                if ($stack && $stack[count($stack) - 1][1] === $depth) {
                    array_pop($stack);
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

        /* ---- render into the unit's two views --------------------------- */
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                $units[$unit]['code'] .= ' ';
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                [$rendered, $joined, $consumed] = phpUnitsReadStringChain($toks, $i);
                $units[$unit]['code'] .= $rendered;
                if ($joined !== null) {
                    $units[$unit]['sql'][] = $joined;
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
                if (stripos($body, 'UPDATE') !== false) {
                    $units[$unit]['sql'][] = $body;
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
            if (stripos($body, 'UPDATE') !== false) {
                $units[$unit]['sql'][] = $body;
            }
            $i = $j;
            continue;
        }

        $units[$unit]['code'] .= $t;
    }

    foreach ($units as $k => $u) {
        $units[$k]['code'] = (string)preg_replace('/\s+/', ' ', $u['code']);
    }

    return $units;
}

/**
 * Render a non-identifier literal so it cannot be read as code, while leaving a
 * positional marker when it was an UPDATE statement.
 */
function phpUnitsRenderOpaque(string $value): string
{
    $marker = preg_match('/\bUPDATE\s+`?\w+`?\b/i', $value) ? " @SQLUPD@ " : '';
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
