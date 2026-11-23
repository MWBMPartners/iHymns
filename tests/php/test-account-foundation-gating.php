<?php

declare(strict_types=1);

/**
 * iHymns - every use of a foundation (B1) table or column sits behind its "does it exist here?" check
 * ============================================================================
 * Account/profile rewrite, PR 2a step 2a-2 (epic #2180, plan section 1: "Never assume B1 has run").
 *
 * ELI5
 * ----
 * The foundation migration (B1) creates new tables and columns, but it is run by
 * hand, and the three copies of the site share one database, so new code can be
 * live before those tables exist. mysqli is in STRICT mode: asking for a table or
 * column that is not there THROWS and blanks the page. So every piece of code that
 * touches one of the new things must first ask a "ready?" question (for example
 * profileItemsReady()), and do the old thing when the answer is no.
 * This test reads the migration to find out WHICH things are new, then reads every
 * PHP file under public_html and fails if any SQL naming one of them is not
 * preceded by its ready-check in the same function (or, in api.php, the same
 * `case`).
 *
 * Detailed (rule #34: derived from the tree, and proven able to fail)
 * -------------------------------------------------------------------
 *  - The new tables come from the `CREATE TABLE` lines in
 *    appWeb/.sql/migrate-account-profile-foundation.php and the new columns from
 *    its `@migration-adds tblX.Col` tags. Nothing here lists them by hand.
 *  - The only typed list is "which ready-check covers which object". An object with
 *    NO ready-check yet fails the moment any SQL names it, which forces the check
 *    to be written first. Each ready-check is also verified to exist and to
 *    actually mention the objects it claims to cover.
 *  - A "statement" is the text between `;`, `{` and `}`; only statements that read
 *    like SQL (an uppercase SQL verb) and do not look in INFORMATION_SCHEMA count.
 *    For a column the SAME statement must name its table too.
 *  - The "unit" is the innermost function, narrowed to the current `case` inside a
 *    switch (api.php is one huge function, and a check in one case must not
 *    excuse another).
 *  - The finder is run on small inline fixtures first (a statement with no check
 *    is found; with the check it is not), so the guard itself is shown to fail.
 *    The mutation proofs on the real tree are in the commits that add each use.
 *
 *   php tests/php/test-account-foundation-gating.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

/** Source with comments blanked (line count kept), so prose never satisfies or trips a check. */
function stripComments(string $src): string
{
    $o = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            $o .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1];
        } else {
            $o .= $t;
        }
    }
    return $o;
}

/**
 * Find every SQL-looking statement that names a guarded object but has no ready-check before it in its unit.
 *
 * @param string $src       PHP source
 * @param array  $objects   list of ['table'=>string,'cols'=>string[] (empty = the table itself),'checks'=>string[]]
 * @return array<int,array{line:int,object:string}>
 */
function findUngated(string $src, array $objects): array
{
    $toks = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) { continue; }
            $toks[] = ['id' => $t[0], 'text' => $t[1], 'line' => $t[2]];
        } else {
            $toks[] = ['id' => $t, 'text' => $t, 'line' => end($toks)['line'] ?? 1];
        }
    }
    $n = count($toks);

    /* Pass 1: for every token, the start index of its "unit" (innermost function, narrowed by the current case). */
    $unitStart = array_fill(0, $n, 0);
    $unitName  = array_fill(0, $n, '');
    $stack = [];            /* frames: ['type'=>function|switch|other, 'start'=>int, 'caseStart'=>int, 'name'=>string] */
    $pendingFn = null;      /* ['start'=>int,'name'=>string,'paren'=>int] while looking for the function's opening brace */
    $pendingSwitch = null;  /* ['paren'=>int] */
    $paren = 0;
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        $id = $t['id'];
        if ($id === T_FUNCTION) {
            $name = '';
            for ($j = $i + 1; $j < $n && $j < $i + 4; $j++) {
                if ($toks[$j]['id'] === T_STRING) { $name = $toks[$j]['text']; break; }
                if ($toks[$j]['id'] === '(') { break; }
            }
            $pendingFn = ['start' => $i, 'name' => $name, 'paren' => $paren];
        } elseif ($id === T_SWITCH) {
            $pendingSwitch = ['paren' => $paren];
        } elseif ($id === '(') {
            $paren++;
        } elseif ($id === ')') {
            $paren--;
        } elseif ($id === ';' && $pendingFn !== null && $paren === $pendingFn['paren']) {
            $pendingFn = null; /* abstract / interface method: no body */
        } elseif ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            if ($id === '{' && $pendingFn !== null && $paren === $pendingFn['paren']) {
                $stack[] = ['type' => 'function', 'start' => $pendingFn['start'], 'caseStart' => -1, 'name' => $pendingFn['name']];
                $pendingFn = null;
            } elseif ($id === '{' && $pendingSwitch !== null && $paren === $pendingSwitch['paren']) {
                $stack[] = ['type' => 'switch', 'start' => $i, 'caseStart' => -1, 'name' => ''];
                $pendingSwitch = null;
            } else {
                $stack[] = ['type' => 'other', 'start' => $i, 'caseStart' => -1, 'name' => ''];
            }
        } elseif ($id === '}') {
            array_pop($stack);
        } elseif (($id === T_CASE || $id === T_DEFAULT) && $stack !== [] && end($stack)['type'] === 'switch') {
            $stack[count($stack) - 1]['caseStart'] = $i;
        }
        /* The unit for this token. */
        $start = 0; $name = '';
        for ($k = count($stack) - 1; $k >= 0; $k--) {
            if ($stack[$k]['type'] === 'function') { $start = max($start, $stack[$k]['start']); $name = $stack[$k]['name']; break; }
            if ($stack[$k]['type'] === 'switch' && $stack[$k]['caseStart'] >= 0) { $start = max($start, $stack[$k]['caseStart']); }
        }
        $unitStart[$i] = $start;
        $unitName[$i]  = $name;
    }

    /* Pass 2: statements. */
    $found = [];
    $stmtStart = 0;
    for ($i = 0; $i <= $n; $i++) {
        $isEnd = $i === $n || in_array($toks[$i]['id'], [';', '{', '}', T_CURLY_OPEN], true);
        if (!$isEnd) { continue; }
        $text = '';
        for ($j = $stmtStart; $j < $i; $j++) {
            if (in_array($toks[$j]['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text .= ' ' . $toks[$j]['text'];
            }
        }
        if ($text !== ''
            && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|FROM|JOIN)\b/', $text)
            && stripos($text, 'INFORMATION_SCHEMA') === false) {
            foreach ($objects as $o) {
                $names = $o['cols'] === [] ? [] : $o['cols'];
                $hit = false;
                if (preg_match('/\b' . preg_quote($o['table'], '/') . '\b/', $text)) {
                    if ($o['cols'] === []) {
                        $hit = true;
                    } else {
                        foreach ($o['cols'] as $c) {
                            if (preg_match('/\b' . preg_quote($c, '/') . '\b/', $text)) { $hit = true; break; }
                        }
                    }
                }
                if (!$hit) { continue; }
                /* A ready-check call earlier in the same unit, or being inside the ready-check itself. */
                $ok = in_array($unitName[$stmtStart] ?? '', $o['checks'], true);
                $us = $unitStart[min($stmtStart, $n - 1)];
                for ($j = $us; !$ok && $j < $stmtStart; $j++) {
                    if ($toks[$j]['id'] === T_STRING && in_array($toks[$j]['text'], $o['checks'], true)
                        && ($toks[$j + 1]['id'] ?? '') === '(') { $ok = true; }
                }
                if (!$ok) {
                    $found[] = ['line' => $toks[min($stmtStart, $n - 1)]['line'], 'object' => $o['table'] . ($o['cols'] === [] ? '' : '.' . implode('|', $o['cols']))];
                }
            }
        }
        $stmtStart = $i + 1;
    }
    return $found;
}

/* ---------------------------------------------------------------------------------------------
 * 1. What is new? Read the migration. */
echo "what the foundation migration adds\n";
$migSrc = (string)file_get_contents($repo . '/appWeb/.sql/migrate-account-profile-foundation.php');
preg_match_all('/^CREATE TABLE\s+(tbl\w+)/m', $migSrc, $m);
$newTables = array_values(array_unique($m[1]));
preg_match_all('/@migration-adds\s+(tbl\w+)\.(\w+)/', $migSrc, $m2, PREG_SET_ORDER);
$newCols = [];
foreach ($m2 as $row) {
    if (in_array($row[1], $newTables, true)) { continue; } /* a column of a brand-new table: covered by the table */
    $newCols[$row[1]][] = $row[2];
}
chk('the migration creates tables (found ' . implode(', ', $newTables) . ')', count($newTables) >= 3);
chk('the migration adds columns to existing tables (found ' . implode(', ', array_map(fn($t) => $t . '.' . implode('/', $newCols[$t]), array_keys($newCols))) . ')', count($newCols) >= 4);

/* The ONLY typed list: which ready-check covers which object. An object missing here has NO check yet. */
$checkFor = [
    'tblUserProfileItems'   => ['profileItemsReady'],
    'tblUserSyncTombstones' => ['userSyncItemTombstonesReady'],
    'tblSongHistory'        => ['songHistoryProvenanceReady'],
    'tblApnsTokens'         => ['apnsTokenHashReady'],
    'tblApiTokens'          => ['apiTokensFoundationColumnsExist'],
];
$objects = [];
foreach ($newTables as $t) {
    $objects[] = ['table' => $t, 'cols' => [], 'checks' => $checkFor[$t] ?? []];
}
foreach ($newCols as $t => $cols) {
    $objects[] = ['table' => $t, 'cols' => $cols, 'checks' => $checkFor[$t] ?? []];
}

/* ---------------------------------------------------------------------------------------------
 * 2. Every ready-check exists and really looks for what it claims to cover. */
echo "the ready-checks\n";
$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && substr($f->getFilename(), -4) === '.php') { $phpFiles[] = $f->getPathname(); }
}
sort($phpFiles);
$allCode = [];
foreach ($phpFiles as $pf) { $allCode[$pf] = stripComments((string)file_get_contents($pf)); }
foreach ($objects as $o) {
    foreach ($o['checks'] as $fn) {
        $body = null;
        foreach ($allCode as $src) {
            if (preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $src, $mm, PREG_OFFSET_CAPTURE)) {
                $body = substr($src, $mm[0][1], 1800);
                break;
            }
        }
        $label = $fn . '() exists and looks for ' . $o['table'] . ($o['cols'] === [] ? '' : ' (' . implode(', ', $o['cols']) . ')');
        $names = array_merge([$o['table']], $o['cols']);
        $ok = $body !== null && !array_filter($names, fn($x) => !str_contains($body, $x)) && str_contains($body, 'INFORMATION_SCHEMA');
        chk($label, $ok);
    }
}

/* ---------------------------------------------------------------------------------------------
 * 3. The finder can fail (inline fixtures). */
echo "the finder itself\n";
$fixBad = '<?php function a(\mysqli $db) { $s = $db->prepare("SELECT * FROM tblUserProfileItems WHERE UserId = ?"); }';
$fixOk  = '<?php function a(\mysqli $db) { if (!profileItemsReady($db)) { return; } $s = $db->prepare("SELECT * FROM tblUserProfileItems WHERE UserId = ?"); }';
$fixOtherFn = '<?php function a(\mysqli $db) { profileItemsReady($db); } function b(\mysqli $db) { $s = $db->prepare("SELECT 1 FROM tblUserProfileItems"); }';
$fixCaseBad = '<?php function api($x) { switch ($x) { case "one": if (profileItemsReady($db)) {} break; case "two": $db->query("DELETE FROM tblUserProfileItems"); break; } }';
$fixCaseOk  = '<?php function api($x) { switch ($x) { case "one": break; case "two": if (profileItemsReady($db)) {} $db->query("DELETE FROM tblUserProfileItems"); break; } }';
$fixColBad  = '<?php function a($db) { $db->query("SELECT Source FROM tblSongHistory"); }';
$fixColOk   = '<?php function a($db) { if (songHistoryProvenanceReady($db)) { $db->query("SELECT Source FROM tblSongHistory"); } }';
$fixColOther = '<?php function a($db) { $db->query("SELECT Source FROM tblSongs"); $db->query("SELECT Id FROM tblSongHistory"); }';
$fixProbe   = '<?php function a($db) { $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = \'tblUserProfileItems\'"); }';
$fixNotSql  = '<?php function a($db) { return _migProbe_tableExists($db, "tblUserProfileItems"); }';
chk('SQL on a new table with no check is FOUND', count(findUngated($fixBad, $objects)) === 1);
chk('...and is clean once the check comes first', findUngated($fixOk, $objects) === []);
chk('a check in a DIFFERENT function does not excuse it', count(findUngated($fixOtherFn, $objects)) === 1);
chk('in a switch, a check in another case does not excuse this case', count(findUngated($fixCaseBad, $objects)) === 1);
chk('in a switch, a check in the same case does', findUngated($fixCaseOk, $objects) === []);
chk('a new column on a guarded table with no check is FOUND', count(findUngated($fixColBad, $objects)) === 1);
chk('...and is clean once the check comes first', findUngated($fixColOk, $objects) === []);
chk('the same column name on another table, or another column of the guarded table, is not flagged', findUngated($fixColOther, $objects) === []);
chk('an INFORMATION_SCHEMA probe is not flagged', findUngated($fixProbe, $objects) === []);
chk('a string that is not SQL is not flagged', findUngated($fixNotSql, $objects) === []);

/* ---------------------------------------------------------------------------------------------
 * 4. The real tree. */
echo "every use in the tree\n";
$bad = [];
foreach ($allCode as $pf => $src) {
    foreach (findUngated($src, $objects) as $f) {
        $bad[] = substr($pf, strlen($repo) + 1) . ':' . $f['line'] . ' (' . $f['object'] . ')';
    }
}
chk('no SQL names a new table or column without its ready-check first (' . count($phpFiles) . ' files scanned)', $bad === [], implode('; ', $bad));

exit($fail === 0 ? 0 : 1);
