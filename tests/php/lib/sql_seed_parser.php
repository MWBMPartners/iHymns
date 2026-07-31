<?php

declare(strict_types=1);

/**
 * iHymns — Minimal SQL seed parser (shared test library)
 * ======================================================
 *
 * Parses `appWeb/.sql/*.sql` well enough to answer two questions:
 *
 *   1. How wide is a given column declared?      → sqlSeedTableColumns()
 *   2. What literal values does a seed INSERT    → sqlSeedInsertRows()
 *      actually write into each of those columns?
 *
 * WHY THIS IS A REAL TOKENIZER AND NOT A REGEX (#1685 follow-up)
 * --------------------------------------------------------------
 * The first attempt at this check used
 *
 *     /INSERT[^;]*?tblAppSettings[^;]*?;/is
 *
 * to grab the seed block. That is wrong in a way this repo has been bitten by
 * repeatedly (rule #34): a `;` inside a quoted string ends the match early, so
 * the scanner silently reported EIGHT seed rows out of sixteen and pronounced
 * them all fine — while the very row that motivated the check (a description
 * containing "…exists in this codebase; changing this…") sat just past the
 * truncation point, unexamined.
 *
 * A scanner that under-reports is worse than no scanner, because its tick is
 * read as coverage. So this parser tracks quoting state properly:
 *
 *   - `'` opens/closes a string; `''` inside a string is a literal quote;
 *     `\` escapes the next character (MySQL's default NO_BACKSLASH_ESCAPES=off).
 *   - `--` to end-of-line and `/* … *\/` are comments ONLY outside a string.
 *   - `(`/`)`/`,`/`;` are structural ONLY outside a string.
 *
 * and every caller gets an explicit "did the statement terminate?" flag, so a
 * parse that runs off the end of the file fails loudly instead of returning a
 * confident short list.
 *
 * SCOPE: deliberately small. It understands `CREATE TABLE … ( … )` column
 * declarations and `INSERT … INTO t (cols) VALUES (…), (…);`. It does not
 * understand sub-selects, `INSERT … SELECT`, or MySQL-specific DDL beyond the
 * column type + length it needs. Anything it cannot parse it reports rather
 * than skipping.
 *
 * Consumed by tests/php/test-seed-column-widths.php.
 */

/**
 * Blank out SQL comments while preserving every byte offset and line number.
 *
 * ELI5: rub out the comments with a pencil eraser, leaving the same size hole,
 * so everything after them is still at the same place in the file.
 *
 * Detail: offsets are preserved so error messages can still report a usable
 * line number via substr_count($sql, "\n", 0, $offset). Newlines inside block
 * comments are kept for the same reason.
 */
function sqlSeedStripComments(string $sql): string
{
    $out    = $sql;
    $len    = strlen($sql);
    $i      = 0;
    $inStr  = false;

    while ($i < $len) {
        /* PERFORMANCE: jump straight to the next character that can possibly
           change state, instead of walking every byte in PHP. On the 7 MB
           .fulldata dump the naive per-byte loop cost ~13 s; strcspn does the
           skipping in C and brings the whole parse under a second.
           https://www.php.net/manual/en/function.strcspn.php */
        $i += strcspn($sql, $inStr ? "'\\" : "'-#/", $i);
        if ($i >= $len) {
            break;
        }
        $c = $sql[$i];

        if ($inStr) {
            if ($c === '\\') {            // backslash escape — skip the escapee
                $i += 2;
                continue;
            }
            // $c === "'":  '' is an escaped quote, not a terminator.
            if ($i + 1 < $len && $sql[$i + 1] === "'") {
                $i += 2;
                continue;
            }
            $inStr = false;
            $i++;
            continue;
        }

        if ($c === "'") {
            $inStr = true;
            $i++;
            continue;
        }

        // -- line comment (MySQL requires the dash-dash be followed by space/EOL)
        if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $out[$i] = ' ';
                $i++;
            }
            continue;
        }

        // # line comment (MySQL)
        if ($c === '#') {
            while ($i < $len && $sql[$i] !== "\n") {
                $out[$i] = ' ';
                $i++;
            }
            continue;
        }

        // /* block comment */
        if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            while ($i < $len) {
                if ($sql[$i] === '*' && $i + 1 < $len && $sql[$i + 1] === '/') {
                    $out[$i]     = ' ';
                    $out[$i + 1] = ' ';
                    $i += 2;
                    break;
                }
                if ($sql[$i] !== "\n") {
                    $out[$i] = ' ';
                }
                $i++;
            }
            continue;
        }

        $i++;
    }

    return $out;
}

/**
 * Blank out the CONTENTS of every string literal, preserving quotes, offsets
 * and newlines.
 *
 * ELI5: keep the speech marks but rub out the words inside them, so you can
 * count real SQL keywords without accidentally counting the ones somebody
 * wrote inside a piece of text.
 *
 * Detail: this is what makes the "did the parser see every statement?" check
 * exact. `schema.sql` contains nine occurrences of the word INSERT, but four
 * of them live inside `COMMENT '…INSERT-only contract…'` strings. Counting
 * them naively would make a correct parse look like it had missed four
 * statements; NOT counting statements at all would let a genuinely missed one
 * (an `INSERT … SELECT`, or an `INSERT INTO t VALUES` with no column list)
 * pass unnoticed — the under-reporting failure this whole file exists to
 * avoid. Masking gives an exact expected count with neither false alarm.
 */
function sqlSeedMaskStrings(string $sql): string
{
    $out   = $sql;
    $len   = strlen($sql);
    $i     = 0;
    $inStr = false;

    while ($i < $len) {
        $next = $i + strcspn($sql, $inStr ? "'\\" : "'", $i);
        if ($inStr) {
            // Everything skipped over was string body — blank it.
            for ($k = $i; $k < $next && $k < $len; $k++) {
                if ($out[$k] !== "\n") { $out[$k] = ' '; }
            }
        }
        $i = $next;
        if ($i >= $len) {
            break;
        }

        if ($inStr) {
            if ($sql[$i] === '\\') {
                $out[$i] = ' ';
                if ($i + 1 < $len && $sql[$i + 1] !== "\n") { $out[$i + 1] = ' '; }
                $i += 2;
                continue;
            }
            if ($i + 1 < $len && $sql[$i + 1] === "'") {   // '' escaped quote
                $out[$i] = ' ';
                $out[$i + 1] = ' ';
                $i += 2;
                continue;
            }
            $inStr = false;                                 // closing quote kept
            $i++;
            continue;
        }

        $inStr = true;                                      // opening quote kept
        $i++;
    }

    return $out;
}

/**
 * Read a balanced parenthesised run starting at $start (which must be the `(`).
 *
 * Returns [bodyStartOffset, bodyEndOffset(exclusive), closeOffset] or null if
 * the parens never balance (truncated file / parser confusion).
 */
function sqlSeedBalancedParen(string $sql, int $start): ?array
{
    $len   = strlen($sql);
    $depth = 0;
    $inStr = false;
    $i     = $start;

    while ($i < $len) {
        // See the strcspn note in sqlSeedStripComments().
        $i += strcspn($sql, $inStr ? "'\\" : "'()", $i);
        if ($i >= $len) {
            break;
        }
        $c = $sql[$i];

        if ($inStr) {
            if ($c === '\\') { $i += 2; continue; }
            if ($i + 1 < $len && $sql[$i + 1] === "'") { $i += 2; continue; }
            $inStr = false;
            $i++;
            continue;
        }

        if ($c === "'") { $inStr = true; $i++; continue; }
        if ($c === '(') { $depth++;      $i++; continue; }
        // $c === ')'
        $depth--;
        if ($depth === 0) {
            return [$start + 1, $i, $i];
        }
        $i++;
    }

    return null;
}

/**
 * Split a comma-separated list at paren-depth 0, respecting quoting.
 *
 * @return list<array{0:string,1:int}> [text, offsetWithinInput] pairs
 */
function sqlSeedSplitTopLevel(string $s): array
{
    $parts = [];
    $len   = strlen($s);
    $depth = 0;
    $inStr = false;
    $start = 0;
    $i     = 0;

    while ($i < $len) {
        // See the strcspn note in sqlSeedStripComments().
        $i += strcspn($s, $inStr ? "'\\" : "'(),", $i);
        if ($i >= $len) {
            break;
        }
        $c = $s[$i];

        if ($inStr) {
            if ($c === '\\') { $i += 2; continue; }
            if ($i + 1 < $len && $s[$i + 1] === "'") { $i += 2; continue; }
            $inStr = false;
            $i++;
            continue;
        }

        if ($c === "'") { $inStr = true; $i++; continue; }
        if ($c === '(') { $depth++;      $i++; continue; }
        if ($c === ')') { $depth--;      $i++; continue; }
        // $c === ','
        if ($depth === 0) {
            $parts[] = [substr($s, $start, $i - $start), $start];
            $start   = $i + 1;
        }
        $i++;
    }

    $tail = substr($s, $start);
    if (trim($tail) !== '') {
        $parts[] = [$tail, $start];
    }

    return $parts;
}

/**
 * Decode a single SQL scalar literal.
 *
 * @return array{kind:string,value:?string}
 *         kind = 'string' | 'other' (number, NULL, expression, function call)
 */
function sqlSeedDecodeValue(string $raw): array
{
    $t = trim($raw);

    if ($t === '' || $t[0] !== "'") {
        return ['kind' => 'other', 'value' => null];
    }

    $len = strlen($t);
    $buf = '';
    for ($i = 1; $i < $len; $i++) {
        $c = $t[$i];
        if ($c === '\\' && $i + 1 < $len) {
            // MySQL backslash escapes: \n \t \\ \' \" \0 …  For a length check
            // only the resulting character COUNT matters, so map the common
            // ones and pass anything else through as its literal second char.
            $n   = $t[$i + 1];
            $map = ['n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", 'b' => "\x08", 'Z' => "\x1a"];
            $buf .= $map[$n] ?? $n;
            $i++;
            continue;
        }
        if ($c === "'") {
            if ($i + 1 < $len && $t[$i + 1] === "'") { $buf .= "'"; $i++; continue; }
            // Closing quote. Anything after it means this value is an
            // expression ('a' || 'b'), not a plain literal.
            $rest = trim(substr($t, $i + 1));
            return $rest === ''
                ? ['kind' => 'string', 'value' => $buf]
                : ['kind' => 'other',  'value' => null];
        }
        $buf .= $c;
    }

    // Unterminated string — the caller's parse is broken; say so.
    return ['kind' => 'other', 'value' => null];
}

/**
 * Parse every `CREATE TABLE` in $sql into declared column metadata.
 *
 * @return array<string, array<string, array{type:string,len:?int}>>
 *         table => column => ['type' => 'VARCHAR', 'len' => 255]
 */
function sqlSeedTableColumns(string $sql): array
{
    $clean  = sqlSeedStripComments($sql);
    $tables = [];

    // Anchor directly on the table name — an un-anchored `CREATE TABLE.*?name`
    // with /s happily matches from an EARLIER CREATE TABLE across to a mere
    // MENTION of the name in a later comment, which is how the first draft of
    // this parser reported tblAppSettings as having columns
    // Token/DeviceName/Platform/AppVersion (they belong to another table).
    $re = '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(/i';
    if (!preg_match_all($re, $clean, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return $tables;
    }

    // Definition lines that declare an index/constraint rather than a column.
    $notAColumn = '/^\s*(PRIMARY\s+KEY|UNIQUE(\s+KEY)?|KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN\s+KEY|CHECK)\b/i';

    foreach ($m as $hit) {
        $table  = $hit[1][0];
        $parenAt = $hit[0][1] + strlen($hit[0][0]) - 1;   // offset of the '('
        $span    = sqlSeedBalancedParen($clean, $parenAt);
        if ($span === null) {
            continue;
        }
        $body = substr($clean, $span[0], $span[1] - $span[0]);

        $cols = [];
        foreach (sqlSeedSplitTopLevel($body) as [$def, $_off]) {
            if (preg_match($notAColumn, $def)) {
                continue;
            }
            if (!preg_match('/^\s*`?(\w+)`?\s+(\w+)\s*(?:\(\s*(\d+))?/', $def, $c)) {
                continue;
            }
            $cols[$c[1]] = [
                'type' => strtoupper($c[2]),
                'len'  => isset($c[3]) ? (int)$c[3] : null,
            ];
        }

        if ($cols) {
            $tables[$table] = $cols;
        }
    }

    return $tables;
}

/**
 * Build a sorted list of newline byte-offsets so line numbers can be resolved
 * in O(log n) instead of O(n).
 *
 * ELI5: write down where every line break is once, then to find which line a
 * character is on, look it up in that list instead of counting from the top
 * of the file again every time.
 *
 * Detail: this replaced a per-statement `substr_count($sql, "\n", 0, $offset)`.
 * With 18,687 INSERT statements in the 7 MB .fulldata dump that was ~130 GB of
 * repeated scanning and accounted for essentially all of the parser's 13 s
 * runtime. It is now ~0.6 s.
 *
 * @return list<int>
 */
function sqlSeedNewlineIndex(string $s): array
{
    $idx = [];
    $p   = -1;
    while (($p = strpos($s, "\n", $p + 1)) !== false) {
        $idx[] = $p;
    }
    return $idx;
}

/** 1-based line number for a byte offset, given sqlSeedNewlineIndex() output. */
function sqlSeedLineAt(array $newlines, int $offset): int
{
    $lo = 0;
    $hi = count($newlines);
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($newlines[$mid] < $offset) { $lo = $mid + 1; } else { $hi = $mid; }
    }
    return $lo + 1;
}

/**
 * Parse every `INSERT … INTO t (cols) VALUES (…), (…);` in $sql.
 *
 * @return list<array{
 *     table:string,
 *     columns:list<string>,
 *     rows:list<list<array{kind:string,value:?string}>>,
 *     line:int,
 *     terminated:bool
 * }>
 */
function sqlSeedInsertRows(string $sql): array
{
    $clean    = sqlSeedStripComments($sql);
    $len      = strlen($clean);
    $newlines = sqlSeedNewlineIndex($clean);
    $out      = [];

    $re = '/\bINSERT\b[^(;]*?\bINTO\s+`?(\w+)`?\s*\(/i';
    if (!preg_match_all($re, $clean, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return $out;
    }

    foreach ($m as $hit) {
        $table   = $hit[1][0];
        $parenAt = $hit[0][1] + strlen($hit[0][0]) - 1;
        $colSpan = sqlSeedBalancedParen($clean, $parenAt);
        if ($colSpan === null) {
            continue;
        }

        $columns = [];
        foreach (sqlSeedSplitTopLevel(substr($clean, $colSpan[0], $colSpan[1] - $colSpan[0])) as [$c, $_o]) {
            $columns[] = trim(trim(trim($c), '`'));
        }

        // Expect VALUES after the column list. `INSERT … SELECT` is out of
        // scope: record it with zero rows so the caller can see it was seen.
        $after = substr($clean, $colSpan[2] + 1, 40);
        if (!preg_match('/^\s*VALUES\s*/i', $after, $v)) {
            $out[] = [
                'table'      => $table,
                'columns'    => $columns,
                'rows'       => [],
                'line'       => sqlSeedLineAt($newlines, $hit[0][1]),
                'terminated' => true,
            ];
            continue;
        }

        $i          = $colSpan[2] + 1 + strlen($v[0]);
        $rows       = [];
        $terminated = false;

        while ($i < $len) {
            // Skip whitespace and row separators.
            while ($i < $len && (ctype_space($clean[$i]) || $clean[$i] === ',')) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($clean[$i] === ';') {
                $terminated = true;
                break;
            }
            if ($clean[$i] !== '(') {
                // ON DUPLICATE KEY UPDATE … or anything else we don't model:
                // run to the statement terminator, respecting quotes.
                $inStr = false;
                for (; $i < $len; $i++) {
                    $c = $clean[$i];
                    if ($inStr) {
                        if ($c === '\\') { $i++; continue; }
                        if ($c === "'") {
                            if ($i + 1 < $len && $clean[$i + 1] === "'") { $i++; continue; }
                            $inStr = false;
                        }
                        continue;
                    }
                    if ($c === "'") { $inStr = true; continue; }
                    if ($c === ';') { $terminated = true; break; }
                }
                break;
            }

            $rowSpan = sqlSeedBalancedParen($clean, $i);
            if ($rowSpan === null) {
                break;                                   // unbalanced → not terminated
            }
            $row = [];
            foreach (sqlSeedSplitTopLevel(substr($clean, $rowSpan[0], $rowSpan[1] - $rowSpan[0])) as [$val, $_o]) {
                $row[] = sqlSeedDecodeValue($val);
            }
            $rows[] = $row;
            $i      = $rowSpan[2] + 1;
        }

        $out[] = [
            'table'      => $table,
            'columns'    => $columns,
            'rows'       => $rows,
            'line'       => sqlSeedLineAt($newlines, $hit[0][1]),
            'terminated' => $terminated,
        ];
    }

    return $out;
}
