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
    'tblAccountEnrolKeys'   => ['accountEnrolKeysReady'],   /* PR 3 H3 */
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
 * 3b. PR 2b: the profile sync core. "Somewhere earlier in the function" (the general rule) can be satisfied by a check that
 * guards nothing, so for the file that holds the core the rule is stricter and derived from the tree: every function in
 * includes/profile_sync.php whose body names tblUserProfileItems must OPEN with `if (!profileItemsReady($db))` (so the check
 * is the first thing that happens, and its failure returns). The finder is shown to catch a function that checks too late. */
echo "the profile sync core opens with its check\n";
require_once __DIR__ . '/lib/php_source_units.php';
/**
 * Every function (a closure too) whose CODE or string text names `$needle`, with its code view.
 * Built on the shared token-based library, so a brace, the word "function" or the needle inside a comment or a message
 * never moves a function boundary or counts as a use: only real code and real SQL text do.
 *
 * @return array<string,string> function name => code view (`{ ... }`, comments removed, strings collapsed)
 */
function functionsNaming(string $src, string $needle): array
{
    $out = [];
    foreach (phpSourceUnits($src) as $name => $u) {
        if ($name === '(file scope)') { continue; }
        /* SQL text is in `strings` (the code view keeps only a marker); the needle may be in either. A message or an array
           subscript in `strings` could also contain it, which only makes the finder stricter, never blind. */
        if (strpos($u['code'], $needle) !== false || strpos(implode("\n", $u['strings']), $needle) !== false) { $out[$name] = $u['code']; }
    }
    return $out;
}
function opensWithReadyCheck(string $codeView): bool
{
    return (bool)preg_match('/^\{\s*if\s*\(\s*!\s*profileItemsReady\(\s*\$db\s*\)\s*\)/', $codeView);
}
/**
 * The functions of the core file that reach the table, DIRECTLY OR THROUGH ANOTHER FUNCTION OF THE SAME FILE. Derived from the
 * file (nothing here lists a name): start from every function that names the table, then add every function that calls one already
 * in the set, until nothing changes. `profileItem*` (the readiness check and the single-item reader) are the file's two public
 * helpers: they open with their own check and are meant to be called from elsewhere, so they are not part of the door.
 *
 * @return list<string>
 */
function coreDoorFunctions(string $src): array
{
    $units = phpSourceUnits($src);
    $set = [];
    foreach (functionsNaming($src, 'tblUserProfileItems') as $n => $_c) {
        if (!str_starts_with($n, 'profileItem') && !str_starts_with($n, '{closure')) { $set[$n] = true; }
    }
    do {
        $grew = false;
        foreach ($units as $n => $u) {
            if ($n === '(file scope)' || str_starts_with($n, '{closure') || str_starts_with($n, 'profileItem') || isset($set[$n])) { continue; }
            if ($set !== [] && preg_match('/\b(?:' . implode('|', array_map('preg_quote', array_keys($set))) . ')\s*\(/', $u['code'])) { $set[$n] = true; $grew = true; }
        }
    } while ($grew);
    return array_keys($set);
}
$fixLate  = '<?php function a(\mysqli $db) { $x = 1; if (!profileItemsReady($db)) { return; } $db->query("SELECT 1 FROM tblUserProfileItems"); }';
$fixFirst = '<?php function a(\mysqli $db) { if (!profileItemsReady($db)) { return; } $db->query("SELECT 1 FROM tblUserProfileItems"); }';
$fixNone  = '<?php function a(\mysqli $db) { $x = 1; $db->query("SELECT 1 FROM tblUserProfileItems"); }';
/* the shapes the old text scanner got wrong: a brace, the word "function" and the needle inside strings and comments */
$fixBrace = '<?php function a(\mysqli $db) { if (!profileItemsReady($db)) { return; } $s = "{ not a block"; /* function b() { */ $db->query("SELECT 1 FROM tblUserProfileItems"); } function c(\mysqli $db) { if (!profileItemsReady($db)) { return; } $db->query("SELECT 1 FROM tblUserProfileItems"); }';
$fixProse = '<?php function d() { return "this message mentions tblUserProfileItems"; } /* function e() { tblUserProfileItems } */ function f() { return 2; }';
$bareOf = fn(string $s) => array_keys(array_filter(functionsNaming($s, 'tblUserProfileItems'), fn($b) => !opensWithReadyCheck($b)));
chk('the stricter finder: a check that comes late is caught, one that opens the function is not, no check is caught',
    $bareOf($fixLate) === ['a'] && $bareOf($fixFirst) === [] && $bareOf($fixNone) === ['a']);
chk('...and an unbalanced brace in a string, or "function" and the table name inside a comment, never move a boundary or make a function look gated',
    $bareOf($fixBrace) === [] && array_keys(functionsNaming($fixBrace, 'tblUserProfileItems')) === ['a', 'c'] && array_keys(functionsNaming($fixProse, 'tblUserProfileItems')) === ['d']);
$coreFile = file_get_contents($web . '/includes/profile_sync.php');
$naming = functionsNaming((string)$coreFile, 'tblUserProfileItems');
unset($naming['profileItemsReady']);   /* the ready-check itself asks INFORMATION_SCHEMA */
$late = array_keys(array_filter($naming, fn($b) => !opensWithReadyCheck($b)));
chk('every function in profile_sync.php that names the table opens with its ready-check (' . count($naming) . ' functions: ' . implode(', ', array_keys($naming)) . ')', count($naming) >= 5 && $late === [], implode(', ', $late));

/* The core is reachable only from the endpoint, which checks the switch first (a second caller would skip the switch). The
   names of "the core" come from the core file itself (coreDoorFunctions), never from a list typed here. */
$door = coreDoorFunctions((string)$coreFile);
chk('the door is found from the file (' . count($door) . ' functions: ' . implode(', ', $door) . ')', count($door) >= 8 && in_array('profileSyncApply', $door, true) && in_array('profileSyncPull', $door, true) && in_array('profileSyncReconcileIfChanged', $door, true) && !in_array('profileItemsReady', $door, true) && !in_array('profileSyncPlan', $door, true));
$fixDoor = '<?php function lowLevel(\mysqli $db) { if (!profileItemsReady($db)) { return; } $db->query("SELECT 1 FROM tblUserProfileItems"); } function wrapper(\mysqli $db) { lowLevel($db); } function pure() { return 1; } function profileItemRead() { $db->query("SELECT 1 FROM tblUserProfileItems"); }';
chk('the door finder follows calls through other functions, and leaves out pure helpers and the public readers', coreDoorFunctions($fixDoor) === ['lowLevel', 'wrapper']);
$doorRe = '/\b(?:' . implode('|', array_map('preg_quote', $door)) . ')\s*\(/';
$callers = [];
foreach ($phpFiles as $pf) {
    if (substr($pf, -strlen('/includes/profile_sync.php')) === '/includes/profile_sync.php') { continue; }
    foreach (phpSourceUnits((string)file_get_contents($pf)) as $u) {
        if (preg_match($doorRe, $u['code'])) { $callers[] = substr($pf, strlen($repo) + 1); break; }
    }
}
chk('only api.php calls the profile sync core from outside its own file (' . implode(', ', $callers) . ')', $callers === ['appWeb/public_html/api.php']);
$apiSrc = $allCode[$web . '/api.php'] ?? '';
$a = strpos($apiSrc, "case 'profile_sync'");
$b = strpos($apiSrc, "case 'songs_exist'", (int)$a);
$caseText = ($a !== false && $b !== false) ? substr($apiSrc, $a, $b - $a) : '';
preg_match_all($doorRe, $apiSrc, $allCalls, PREG_OFFSET_CAPTURE);
$outside = array_filter($allCalls[0], fn($c) => $a === false || $c[1] < $a || $c[1] > $b);
chk('every such call in api.php sits inside case \'profile_sync\', which asks profileSyncEnabledHere() before the first of them',
    $caseText !== '' && $allCalls[0] !== [] && $outside === [] && strpos($caseText, 'profileSyncEnabledHere()') !== false && strpos($caseText, 'profileSyncEnabledHere()') < $allCalls[0][0][1] - $a);

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
