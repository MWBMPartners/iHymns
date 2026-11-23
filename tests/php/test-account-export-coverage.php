<?php

declare(strict_types=1);

/**
 * iHymns - "download my data" covers every personal store and never a secret (PR 2c-1, epic #2180)
 * ============================================================================
 *
 * ELI5
 * ----
 * When a person asks for everything we hold about them, the file must (a) include every place
 * we keep something about them and (b) never include a password, a sign-in code or a key. This
 * guard works that out from schema.sql itself (the tree), not from a list typed here (rule #34),
 * and fails when either promise could be broken by a table or column someone adds.
 *
 * Detailed
 * --------
 *  1. LINKS. Every column in schema.sql that is a foreign key to tblUsers(Id) is passed through the
 *     real accountExportPlan(). It must come out exported, or excluded WITH a non-empty plain reason.
 *     No exclusion may name a table that no longer links to users (a stale exclusion would hide a
 *     future export).
 *  2. NO LINK. Every integer column that holds a person's id and has NO foreign key at all must be
 *     listed in accountExportNoLinkColumns(), and every entry there must be a real column. Which columns
 *     "hold a person's id" is LEARNED from schema.sql, not typed here (PR 2c review: the old typed name
 *     pattern missed DismissedBy and AddedBy): the spellings of the columns that DO link to tblUsers
 *     (a trailing By, UserId, OwnerId...) are collected, and an unlinked integer column spelled the same
 *     way, or whose own comment names tblUsers, must be decided. A column of the sign-in attempt log (a
 *     name as text) must be listed as excluded with its reason.
 *  2b. NOBODY ELSE'S INFORMATION. Reviewer-role links, address/browser/country columns (see the header
 *     of includes/account_export.php); each derived from schema.sql.
 *  3. SECRETS. Every column, in every table that is exported, whose name looks like a secret
 *     (the pattern the export itself uses) must be in the REVIEWED deny list. The run-time pattern
 *     would withhold it anyway; this check makes a person decide and write the reason down. Every
 *     deny-list entry must be a real column somewhere (no stale entries).
 *  4. REGISTRY. Every entry of the one profile registry marked `export` that stores in a table
 *     (`table:X` or `profile_items`) must have that table exported.
 *  5. ENDPOINT. api.php's account_export case: POST only, same-origin check before the sign-in lookup,
 *     the 5-an-hour limit before the data is read, no account-sync switch (owner decision), and
 *     nothing in it reads a secret.
 *
 * MUTATION PROOFS are recorded in the 2c-1 commit: a new linked table with a secret-looking column
 * that is not on the deny list, `PasswordHash` removed from the deny list, a no-link user column that
 * is not listed, an exclusion with no reason, and the rate limit removed from the case each turn it red.
 *
 *   php tests/php/test-account-export-coverage.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $web . '/includes/profile_registry.php';

/* The export file pulls in db_mysql.php; the guard needs only its pure functions, but loading is harmless. */
require_once $web . '/includes/account_export.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

/** Where the guard reads its schema from; the mutation runs point it at a changed copy. */
$schemaPath = getenv('IHYMNS_EXPORT_GUARD_SCHEMA') ?: $repo . '/appWeb/.sql/schema.sql';
$schema = (string)file_get_contents($schemaPath);

/* ---- read schema.sql: every table, its columns, its links ---- */
$tables = [];   /* name => ['cols' => [name => type], 'fkCols' => [colLower => refTable], 'userFk' => [col, ...]] */
preg_match_all('/CREATE TABLE IF NOT EXISTS (tbl\w+) \((.*?)\n\) ENGINE/s', $schema, $mm, PREG_SET_ORDER);
foreach ($mm as $m) {
    $body = (string)preg_replace('#/\*.*?\*/#s', '', $m[2]);
    $body = (string)preg_replace('/^\s*--.*$/m', '', $body);
    $t = ['cols' => [], 'fkCols' => [], 'userFk' => []];
    foreach (explode("\n", $body) as $line) {
        if (preg_match('/^\s+(\w+)\s+(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT|VARCHAR|CHAR|TEXT|MEDIUMTEXT|LONGTEXT|JSON|DATETIME|TIMESTAMP|DATE|ENUM|DECIMAL|DOUBLE|FLOAT|BLOB|MEDIUMBLOB|LONGBLOB|VARBINARY|BINARY)\b/i', $line, $c)) {
            $t['cols'][$c[1]] = strtoupper($c[2]);
        }
    }
    if (preg_match_all('/FOREIGN KEY \((\w+)\)\s+REFERENCES (\w+)\s*\(/', $body, $fk, PREG_SET_ORDER)) {
        foreach ($fk as $f) {
            $t['fkCols'][strtolower($f[1])] = $f[2];
            if (strcasecmp($f[2], 'tblUsers') === 0) { $t['userFk'][] = $f[1]; }
        }
    }
    $tables[$m[1]] = $t;
}
if (preg_match_all('/ALTER\s+TABLE\s+(tbl\w+)\s+ADD\s+CONSTRAINT\s+\w+\s+FOREIGN\s+KEY\s*\((\w+)\)\s+REFERENCES\s+(\w+)\s*\(/i', $schema, $al, PREG_SET_ORDER)) {
    foreach ($al as $a) {
        if (!isset($tables[$a[1]])) { continue; }
        $tables[$a[1]]['fkCols'][strtolower($a[2])] = $a[3];
        if (strcasecmp($a[3], 'tblUsers') === 0) { $tables[$a[1]]['userFk'][] = $a[2]; }
    }
}
$links = [];
foreach ($tables as $name => $t) {
    foreach (array_unique($t['userFk']) as $col) { $links[] = ['table' => $name, 'column' => $col]; }
}
chk('the guard found the linked columns in schema.sql (' . count($links) . ', including the one added by a later ALTER)', count($links) >= 55 && in_array('tblSongs.DeletedBy', array_map(fn($l) => $l['table'] . '.' . $l['column'], $links), true));

/* ---- 1. links ---- */
echo "1. every link is exported or excluded with a reason\n";
$plan = accountExportPlan($links);
$exportedKeys = array_map(fn($p) => strtolower($p['table'] . '.' . $p['column']), $plan['export']);
$excludedKeys = array_map(fn($p) => strtolower($p['table'] . '.' . $p['column']), $plan['excluded']);
$lost = [];
foreach ($links as $l) {
    $k = strtolower($l['table'] . '.' . $l['column']);
    if (!in_array($k, $exportedKeys, true) && !in_array($k, $excludedKeys, true)) { $lost[] = $k; }
}
chk('no link is neither exported nor excluded', $lost === [], implode(', ', $lost));
$noReason = array_values(array_filter($plan['excluded'], fn($e) => trim($e['reason']) === ''));
chk('every exclusion has a plain reason', $noReason === [], implode(', ', array_map(fn($e) => $e['table'], $noReason)));
$linkedTables = array_map(fn($l) => strtolower($l['table']), $links);
$stale = array_values(array_filter(array_keys(accountExportExcludedTables()), fn($t) => !in_array($t, $linkedTables, true)));
chk('no exclusion names a table that no longer links to a person', $stale === [], implode(', ', $stale));
chk('the export is not empty and the personal tables are in it', count($plan['export']) >= 25
    && in_array('tbluserfavorites.userid', $exportedKeys, true) && in_array('tbluserprofileitems.userid', $exportedKeys, true)
    && in_array('tblusersetlists.userid', $exportedKeys, true) && in_array('tblsonghistory.userid', $exportedKeys, true)
    && in_array('tblusercustomtags.userid', $exportedKeys, true));
chk('a table that links to a person is never excluded just because its name looks private', !in_array('tblusersongmarkup.userid', $excludedKeys, true));

/* ---- 2. no link ---- */
echo "2. columns that hold a person's id without a database link\n";
/* PR 2c review: this used to be a TYPED name pattern, which is how DismissedBy and AddedBy slipped past it. Now the vocabulary is
   LEARNED from the schema: the names of the columns that DO link to tblUsers say how this schema spells "a person" (a trailing
   `By`, `UserId`, `OwnerId`, `RequesterId` ...). An INT column with NO link that spells itself the same way, or whose own schema
   comment points at tblUsers, is a person column without a link and must be decided. */
$camelWords = static fn(string $n): array => preg_split('/(?<=[a-z0-9])(?=[A-Z])/', $n) ?: [$n];
$suffixOf = static function (string $n) use ($camelWords): string {
    $w = $camelWords($n);
    $last = array_pop($w);
    return ($last === 'Id' && $w !== []) ? array_pop($w) . 'Id' : $last;   /* CreatedBy -> By ; OwnerUserId -> UserId */
};
$learned = [];
foreach ($links as $l) { $learned[$suffixOf($l['column'])] = true; }
chk('the vocabulary learned from the real links includes the spellings that matter (By, UserId)', isset($learned['By']) && isset($learned['UserId']), implode(',', array_keys($learned)));
/* The comment of each column, so a comment that names tblUsers counts as evidence too. */
$commentPointsAtUsers = [];
preg_match_all('/CREATE TABLE IF NOT EXISTS (tbl\w+) \((.*?)\n\) ENGINE/s', $schema, $ctm, PREG_SET_ORDER);
foreach ($ctm as $cm) {
    foreach (explode("\n", $cm[2]) as $line) {
        if (preg_match('/^\s+(\w+)\s+(?:TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)\b.*COMMENT\s+\'[^\']*tblUsers/i', $line, $cc)) { $commentPointsAtUsers[strtolower($cm[1] . '.' . $cc[1])] = true; }
    }
}
$unlinked = [];
foreach ($tables as $name => $t) {
    foreach ($t['cols'] as $col => $type) {
        if (!in_array($type, ['INT', 'BIGINT', 'SMALLINT', 'MEDIUMINT'], true)) { continue; }
        $key = strtolower($name . '.' . $col);
        if (isset($t['fkCols'][strtolower($col)])) { continue; }          /* a link to something (users or another table): handled by 1 or not a person */
        if (strcasecmp($name, 'tblUsers') === 0) { continue; }
        if ($col === 'Id') { continue; }                                  /* a table's own key */
        if (!isset($learned[$suffixOf($col)]) && !isset($commentPointsAtUsers[$key])) { continue; }
        $unlinked[] = $key;
    }
}
/* Spellings that match a learned suffix but are plainly not a person (checked by hand, each with the reason). */
$notAPerson = [];
$unlinked = array_values(array_diff($unlinked, array_keys($notAPerson)));
foreach (['tblmusicianduplicatesdismissed.dismissedby', 'tblsonglinksuggestionsdismissed.dismissedby', 'tblcataloguesongs.addedby'] as $mustFind) {
    chk("the derivation finds $mustFind (the review's three misses)", in_array($mustFind, $unlinked, true));
}
$handListed = array_keys(accountExportNoLinkColumns());
$missing = array_values(array_diff($unlinked, $handListed));
chk('every person-id column without a link is in accountExportNoLinkColumns() (' . count($unlinked) . ' found)', $missing === [] && count($unlinked) >= 2, implode(', ', $missing));
$phantom = [];
foreach (accountExportNoLinkColumns() as $key => $n) {
    $real = false;
    foreach ($tables as $name => $t) {
        if (strcasecmp($name, $n['table']) === 0) { foreach (array_keys($t['cols']) as $c) { if (strcasecmp($c, $n['column']) === 0) { $real = true; } } }
    }
    if (!$real) { $phantom[] = $key; }
    if ($key !== strtolower($n['table'] . '.' . $n['column'])) { $phantom[] = $key . ' (key does not match its table and column)'; }
    if ($n['export'] === false && trim($n['reason']) === '') { $phantom[] = $key . ' (excluded without a reason)'; }
}
chk('every hand-listed no-link column is a real column and every exclusion has a reason', $phantom === [], implode(', ', $phantom));
chk('the sign-in attempt log (a name as text) is excluded, not exported', isset(accountExportNoLinkColumns()['tblloginattempts.username']) && accountExportNoLinkColumns()['tblloginattempts.username']['export'] === false);

/* ---- 2b. other people's information (PR 2c review) ---- */
echo "2b. nothing about anybody else rides along\n";
/* Broader than the file's own pattern on purpose: a column the pattern misses must still be noticed here. */
$roleLike = '/review|approv|verif|resolv|assign/i';
$missedRole = [];
foreach ($links as $l) {
    if (preg_match($roleLike, $l['column']) !== 1) { continue; }
    $k = strtolower($l['table'] . '.' . $l['column']);
    $tableExcluded = isset(accountExportExcludedTables()[strtolower($l['table'])]);
    if (in_array($k, $exportedKeys, true) || (!$tableExcluded && !isset(accountExportReviewerColumns()[$k]))) { $missedRole[] = $k; }
}
chk('every reviewer-role link in schema.sql (' . count(array_filter($links, fn($l) => preg_match($roleLike, $l['column']) === 1)) . ' found) is left out of the export and is in the reviewed list or sits in an excluded table', $missedRole === [], implode(', ', $missedRole));
$badRole = [];
foreach (accountExportReviewerColumns() as $k => $why) {
    [$tn, $cn] = explode('.', $k);
    $real = false;
    foreach ($tables as $name => $t) { if (strcasecmp($name, $tn) === 0) { foreach (array_keys($t['cols']) as $c) { if (strcasecmp($c, $cn) === 0) { $real = true; } } } }
    if (!$real) { $badRole[] = $k . ' (no such column)'; }
    if (in_array($k, $exportedKeys, true)) { $badRole[] = $k . ' (listed as reviewer-role but exported)'; }
    if (trim($why) === '') { $badRole[] = $k . ' (no role in plain words)'; }
}
chk('every entry of the reviewed reviewer-role list is a real column, is not exported, and says what the role is', $badRole === [], implode(', ', $badRole));
chk('the reviewer-role pattern catches the names the live schema uses', accountExportIsReviewerColumn('tblX', 'ReviewedBy') && accountExportIsReviewerColumn('tblX', 'ApprovedBy') && accountExportIsReviewerColumn('tblX', 'VerifiedBy') && !accountExportIsReviewerColumn('tblX', 'SubmittedBy') && !accountExportIsReviewerColumn('tblX', 'UserId'));
$netLike = '/(^|[a-z])Ip$|^Ip[A-Z]|IpAddress|UserAgent|^Country$/';
$unplaced = [];
foreach (array_unique(array_map(fn($p) => $p['table'], $plan['export'])) as $tn) {
    foreach (array_keys($tables[$tn]['cols'] ?? []) as $col) {
        if (preg_match($netLike, $col) !== 1) { continue; }
        $k = strtolower($tn . '.' . $col);
        $ok = isset(accountExportColumnMasks()[strtolower($tn)][$col]) || isset(accountExportOmitColumns()[strtolower($tn)][strtolower($col)]) || isset(accountExportOwnNetworkColumns()[$k]);
        if (!$ok) { $unplaced[] = $k; }
    }
}
chk('every address / browser / country column of an exported table is masked, omitted, or reviewed as the person\'s own', $unplaced === [], implode(', ', $unplaced));
$allColsByTable = [];
foreach ($tables as $name => $t) { foreach (array_keys($t['cols']) as $c) { $allColsByTable[strtolower($name)][strtolower($c)] = true; } }
$stale = [];
foreach (accountExportOmitColumns() as $tl => $cs) { foreach (array_keys($cs) as $cl) { if (!isset($allColsByTable[$tl][$cl])) { $stale[] = "omit $tl.$cl"; } } }
foreach (accountExportColumnMasks() as $tl => $cs) { foreach (array_keys($cs) as $cl) { if (!isset($allColsByTable[$tl][strtolower($cl)])) { $stale[] = "mask $tl.$cl"; } } }
foreach (accountExportOwnNetworkColumns() as $k => $why) { [$tl, $cl] = explode('.', $k); if (!isset($allColsByTable[$tl][$cl])) { $stale[] = "own $k"; } }
chk('every omitted, masked and own-network column named in the file is a real column', $stale === [], implode(', ', $stale));

/* ---- 3. secrets ---- */
echo "3. secret-looking columns are on the reviewed deny list\n";
$exportedTableNames = array_unique(array_map(fn($p) => $p['table'], $plan['export']));
$exportedTableNames[] = 'tblUsers';
$unreviewed = [];
foreach ($exportedTableNames as $tn) {
    foreach (array_keys($tables[$tn]['cols'] ?? []) as $col) {
        if (preg_match('/' . accountExportDenyPattern() . '/i', $col) === 1 && !isset(accountExportDenyColumns()[strtolower($col)])) {
            $unreviewed[] = $tn . '.' . $col;
        }
    }
}
chk('every secret-looking column of an exported table is in accountExportDenyColumns()', $unreviewed === [], implode(', ', $unreviewed));
$allCols = [];
foreach ($tables as $t) { foreach (array_keys($t['cols']) as $c) { $allCols[strtolower($c)] = true; } }
$staleDeny = array_values(array_filter(array_keys(accountExportDenyColumns()), fn($c) => !isset($allCols[$c])));
chk('every deny-list entry names a column that exists', $staleDeny === [], implode(', ', $staleDeny));
chk('the password hash of the account row is on the list and refused by the function', isset(accountExportDenyColumns()['passwordhash']) && !accountExportColumnAllowed('PasswordHash'));
chk('a column nobody has reviewed yet but that looks secret is still refused at run time', !accountExportColumnAllowed('NewThingToken') && !accountExportColumnAllowed('ApiSecretValue') && accountExportColumnAllowed('Title'));
$dangerous = ['PasswordHash', 'Token', 'TokenHash', 'RefreshToken', 'P256dhKey', 'AuthKey', 'PushToken', 'KeyHash', 'DeviceCodeHash', 'ApiTokenHash', 'Secret', 'VerifyToken', 'Endpoint'];
$leaks = array_values(array_filter($dangerous, fn($c) => accountExportColumnAllowed($c)));
chk('every known secret column name is refused', $leaks === [], implode(', ', $leaks));

/* ---- 4. registry ---- */
echo "4. the registry's export promises\n";
$wanted = [];
foreach (profileRegistry() as $e) {
    if (($e['export'] ?? false) !== true) { continue; }
    $st = (string)($e['storage'] ?? '');
    if ($st === 'profile_items') { $wanted['tbluserprofileitems'] = $e['id']; }
    elseif (strncmp($st, 'table:', 6) === 0) { $wanted[strtolower(substr($st, 6))] = $e['id']; }
}
$exportedTablesLower = array_map(fn($k) => explode('.', $k)[0], $exportedKeys);
$unmet = [];
foreach ($wanted as $tbl => $id) { if (!in_array($tbl, $exportedTablesLower, true)) { $unmet[] = "$id -> $tbl"; } }
chk('every registry entry marked export has its table exported (' . count($wanted) . ' tables)', $unmet === [] && count($wanted) >= 5, implode(', ', $unmet));

/* ---- 5. the endpoint ---- */
echo "5. the endpoint\n";
$api = (string)file_get_contents($web . '/api.php');
$a = strpos($api, "case 'account_export'");
$b = $a === false ? false : strpos($api, "\n        case '", $a + 10);
$case = ($a !== false && $b !== false) ? substr($api, $a, $b - $a) : '';
$code = '';
foreach (token_get_all('<?php ' . $case) as $tk) { $code .= is_array($tk) ? (in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $tk[1]) : $tk; }
$at = fn(string $needle) => strpos($code, $needle);
chk('the case exists', $case !== '' && $code !== '');
chk('POST only', $at("REQUEST_METHOD'] !== 'POST'") !== false && $at("REQUEST_METHOD'] !== 'POST'") < ($at('validateCsrfRequest') ?: PHP_INT_MAX));
chk('the same-origin check comes before the sign-in lookup', $at('validateCsrfRequest(null)') !== false && $at('validateCsrfRequest(null)') < ($at('getAuthenticatedUser()') ?: -1));
chk('the limit (per person, 5 an hour) is applied before the data is read', $at('enforceReadRateLimit(') !== false && $at('enforceReadRateLimit(') < ($at('accountExportStream(') ?: -1)
    && strpos($code, 'ACCOUNT_EXPORT_RATE_PER_HOUR') !== false && ACCOUNT_EXPORT_RATE_PER_HOUR === 5);
chk('it is not behind the account sync switch (owner decision) and not behind the saving brake (a read)', strpos($code, 'profileSyncEnabledHere') === false && strpos($code, 'accountWritesRefuseIfOff') === false);
chk('it answers a failure with a reason code, not a stack trace', strpos($code, "'account_export_unavailable'") !== false && strpos($code, '503') !== false);
chk('it sends the file as a download and never caches it', strpos($code, 'Content-Disposition: attachment') !== false && strpos($code, 'no-store') !== false);
chk('nothing in the case reads a password hash or a token itself', !preg_match('/PasswordHash|RefreshToken|ApiTokenHash/', $code));
$core = (string)file_get_contents($web . '/includes/account_export.php');
chk('the core takes its list of tables from the same map the erasure uses', strpos($core, 'accountEraseFkGraph(') !== false);
/* PR 2c review: memory. The rows must come off the database one at a time and go straight into the file. */
$fnBody = static function (string $src, string $name): string {
    $p = strpos($src, 'function ' . $name . '(');
    if ($p === false) { return ''; }
    $o = strpos($src, '{', strpos($src, ')', $p));
    $depth = 0; $n = strlen($src);
    for ($i = $o; $i < $n; $i++) {
        if ($src[$i] === '{') { $depth++; } elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { return substr($src, $o, $i - $o + 1); } }
    }
    return '';
};
$strip = static function (string $php): string { $o = ''; foreach (token_get_all('<?php ' . $php) as $tk) { $o .= is_array($tk) ? (in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $tk[1]) : $tk; } return $o; };
$rows = $strip($fnBody($core, 'accountExportStreamTable'));
chk('the row reader exists and reads UNBUFFERED (bind_result + fetch), never get_result()/fetch_all()/store_result() (they load every row first)', $rows !== '' && strpos($rows, 'bind_result(') !== false && strpos($rows, '->fetch()') !== false && !preg_match('/get_result\(|fetch_all\(|store_result\(|MYSQLI_STORE_RESULT/', $rows));
chk('the row reader checks the row and byte limits INSIDE the read loop and writes each row out as it goes', preg_match('/while\s*\(\$stmt->fetch\(\)\)\s*\{.*\$cap.*\$tableLimit.*accountExportWrite\(/s', $rows) === 1);
chk('the whole-file builder streams to a handle: no array of all tables and no pretty-printed copy', strpos($strip($fnBody($core, 'accountExportStream')), 'JSON_PRETTY_PRINT') === false && strpos($core, 'JSON_PRETTY_PRINT') === false);
chk('the endpoint streams to a temporary file and sends it with fpassthru, instead of echoing a built string', strpos($code, 'tmpfile()') !== false && strpos($code, 'fpassthru(') !== false && strpos($code, 'accountExportStream(') !== false && strpos($code, 'accountExportBuild(') === false && strpos($code, 'accountExportText(') === false);
chk('the large-text tables have lower row limits than the general one', accountExportRowCap('tblSongRevisions') < ACCOUNT_EXPORT_MAX_ROWS && accountExportRowCap('tblActivityLog') < ACCOUNT_EXPORT_MAX_ROWS && accountExportRowCap('tblUserFavorites') === ACCOUNT_EXPORT_MAX_ROWS);
chk('the person id is only ever bound, never joined into SQL', substr_count($core, "bind_param('i', \$userId)") >= 1 && strpos($core, 'array_fill(0, 1 + count($skip), $userId)') !== false && strpos($core, 'bind_param(str_repeat(\'i\', count($ids)), ...$ids)') !== false && !preg_match('/WHERE[^;]*\.\s*\$userId/', $core));

exit($fail === 0 ? 0 : 1);
