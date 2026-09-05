<?php

declare(strict_types=1);

/**
 * iHymns — #2086 follow-up: documented READ actions must ask the real schema
 * for real columns
 * =========================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `song_revisions` and `admin_pending_revisions` asked the database for four
 * columns that had never existed (`ChangesJson`, `Notes`, `ReviewedAt`) and
 * joined on a fifth that never had either (`CreatedBy`). Because the database
 * layer here treats a bad query as a loud crash rather than a quiet "no", both
 * endpoints failed for every single caller, every single time — but nothing
 * noticed for months, because nothing in this repository ever CALLED either
 * one. A test can only catch a mistake it actually looks at, and nobody had
 * written anything that looked at these two.
 *
 * This file is that look, made general rather than one-off: it reads
 * `api-docs.yaml` to find every documented "read a thing over GET" action,
 * finds that action's own code inside `api.php`, pulls out the plain SQL text
 * it prepares, and checks each `alias.Column` reference — plus every column an
 * INSERT or UPDATE names — against the REAL column list `appWeb/.sql/
 * schema.sql` declares for that table. No database connection is needed for
 * this: it is close reading of two text files, done mechanically instead of
 * by eye.
 *
 * WHY "QUALIFIED" REFERENCES ONLY (`r.SongId`, not a bare `SongId`)
 * -----------------------------------------------------------------------
 * A query can write a column name two ways: `r.SongId` (qualified — this SQL
 * fragment says which table it means) or bare `SongId` (unqualified — correct
 * only when there is exactly one table in scope, and genuinely ambiguous the
 * moment a JOIN adds a second one). This file checks ONLY the qualified shape,
 * on purpose: a bare-column checker has to guess which of several joined
 * tables owns a name, and a guess that is wrong even once produces a false
 * alarm on code that was never broken — which is exactly the "guard so blunt
 * it fails on correct code" trap CLAUDE.md rule #34 warns will get a guard
 * weakened or deleted rather than fixed. The actual #2086 bug is not a
 * hypothetical this narrower scope might miss: all four broken references —
 * `r.ChangesJson`, `r.Notes`, `r.ReviewedAt`, `r.CreatedBy` — were qualified.
 * INSERT/UPDATE column lists are also checked (see below) because their
 * target table is never ambiguous — it is named once, right after INSERT INTO
 * or UPDATE, so there is nothing to guess.
 *
 * WHY ONLY *FULLY LITERAL* SQL IS CHECKED
 * -----------------------------------------------------------------------
 * A lot of this codebase builds its WHERE clause out of PHP variables ($where
 * arrays, ternaries, helper calls) — see `manage/revisions.php` for the
 * canonical example. Guessing at a variable's contents would be exactly the
 * kind of guess the previous paragraph just ruled out, so a `prepare(...)`
 * call is only checked when its ENTIRE argument is one or more plain PHP
 * string literals concatenated with `.` — nothing else. Anything else (a real
 * variable, a ternary, a function or constant reference) is left alone rather
 * than guessed at, and is reported in the summary as "not checkable" rather
 * than silently dropped — see the floor assertion below for why that count is
 * watched, not just printed. (`song_revisions` and `admin_pending_revisions`
 * deliberately stay two independent literal strings rather than one shared
 * runtime constant, precisely so a pre-existing, unrelated guard —
 * tests/php/test-live-session-channel.php, which requires every `->prepare()`
 * call it scans in this same file to be plain inline SQL — keeps working too;
 * see the in-code comment on those two cases. What keeps THOSE two in sync
 * instead is the dedicated byte-identical-prefix assertion further down.)
 *
 * THE FLOOR ASSERTION (rule #34's "a guard that under-reports is worse than
 * no guard, because its tick is read as coverage")
 * -----------------------------------------------------------------------
 * A checker that quietly stops checking anything still prints "0 failed" —
 * that is a worse state than not existing, because a green run then reads as
 * a clean bill of health for code nobody looked at. So this file asserts a
 * MINIMUM number of statements were actually run through the schema check
 * (today's real count, from the real files, is comfortably over the floor —
 * see the assertion itself for the current number) and separately asserts
 * that `song_revisions` and `admin_pending_revisions` — the two actions this
 * guard exists because of — are themselves among the checked, not the
 * skipped. If a future refactor changes how either builds its SQL in a way
 * this file's extractor can no longer follow, THAT assertion is what goes red
 * — not a silent drop back to zero coverage on exactly the two actions that
 * matter most here.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * -----------------------------------------------------------------------
 * Every extraction/checking primitive is proven on small in-memory fixtures
 * BEFORE it is trusted against the real files: the schema-column parser, the
 * FROM/JOIN alias-map builder (including its keyword-denylist, so `WHERE`
 * is never mistaken for a table alias), the INFORMATION_SCHEMA skip, and —
 * the direct proof this file exists to give — running the EXACT pre-fix SQL
 * text of both #2086 endpoints (typed here as a fixture string, not sourced
 * from git history, so this test does not depend on repository history
 * staying intact) through the checker and confirming it reports precisely
 * the four bad references the issue named. That last one is the concrete
 * answer to "would this have caught it?".
 *
 *   php tests/php/test-read-action-schema-columns.php
 *
 * Exit status 0 = clean (mutation self-tests all proved they can fail, and
 * every checkable statement in the real files passed), 1 = otherwise.
 *
 * @see appWeb/public_html/api.php                 the read actions this checks
 * @see appWeb/public_html/api-docs.yaml            where the GET action list comes from
 * @see appWeb/.sql/schema.sql                      the real column lists
 * @see tests/php/test-api-gate-parity.php          sibling guard this borrows caseBodyFor() from (duplicated per-file, matching that file's own established precedent for these small tokeniser helpers)
 * @see tests/php/test-schema-installs.php          the "static now, live later" two-assertions-of-different-kinds shape this file follows
 * @link https://github.com/MWBMPartners/iHymns/issues/2086
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo       = dirname(__DIR__, 2);
$api        = $repo . '/appWeb/public_html/api.php';
$docsPath   = $repo . '/appWeb/public_html/api-docs.yaml';
$schemaPath = $repo . '/appWeb/.sql/schema.sql';

$apiSrc    = (string)file_get_contents($api);
$docsSrc   = (string)file_get_contents($docsPath);
$schemaSrc = (string)file_get_contents($schemaPath);

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

/* =========================================================================
 * SHARED TOKENISER HELPERS — duplicated from test-api-gate-parity.php,
 * matching that file's own documented precedent ("duplicated per-file
 * rather than shared... for these tiny tokeniser helpers") rather than
 * reaching into another test file or growing tests/php/lib/ for a two-file
 * consumer.
 * ========================================================================= */

function tokSpanText(array $toks, int $start, int $end): string
{
    $buf = '';
    $n = min($end, count($toks));
    for ($k = $start; $k < $n; $k++) {
        $t = $toks[$k];
        $buf .= is_array($t) ? $t[1] : $t;
    }
    return $buf;
}

function caseBodyFor(string $file, string $switchVar, string $name): ?string
{
    $toks  = dispatchParserTokens($file);
    $cases = dispatchParserCaseTokens($file, $switchVar);
    foreach ($cases as $i => $c) {
        if ($c['name'] !== $name) { continue; }
        $start = $c['index'];
        $j = $i;
        while (isset($cases[$j + 1])) {
            $gapStart = $cases[$j]['index'] + 1;
            $gapEnd   = $cases[$j + 1]['index'];
            $pureFallthrough = true;
            for ($k = $gapStart; $k < $gapEnd; $k++) {
                $t = $toks[$k];
                if ($t === ':') { continue; }
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CASE], true)) { continue; }
                $pureFallthrough = false;
                break;
            }
            if (!$pureFallthrough) { break; }
            $j++;
        }
        $end = isset($cases[$j + 1]) ? $cases[$j + 1]['index'] : count($toks);
        return tokSpanText($toks, $start, $end);
    }
    return null;
}

/* =========================================================================
 * PRIMITIVE 1 — decode a single PHP string-literal token's real value
 * (strip quotes, undo the one escape shape either quote style needs).
 * ========================================================================= */

function decodeStringLiteralToken(string $raw): string
{
    $quote = $raw[0];
    $inner = substr($raw, 1, -1);
    if ($quote === "'") {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
    }
    return str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
}

/* =========================================================================
 * PRIMITIVE 2 — extract every "checkable" SQL statement from a case body:
 * every `->prepare(EXPR)` call whose ENTIRE argument resolves, without
 * guessing, to one or more plain string literals concatenated with `.` —
 * nothing else (see file header for why this is the deliberate boundary,
 * not everything api.php's prepare() calls do).
 *
 * @return array<int,string> the resolved SQL text of each checkable call
 * ========================================================================= */

function extractCheckableSql(string $body): array
{
    $out = [];
    $bodyToks = @token_get_all('<?php ' . $body);
    $n = count($bodyToks);
    for ($i = 0; $i < $n; $i++) {
        $t = $bodyToks[$i];
        if (!(is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'prepare')) { continue; }
        $j = $i + 1;
        while ($j < $n && is_array($bodyToks[$j]) && $bodyToks[$j][0] === T_WHITESPACE) { $j++; }
        if (($bodyToks[$j] ?? null) !== '(') { continue; }

        $depth = 0;
        $resolvable = true;
        $sawAnything = false;
        $parts = [];
        for ($k = $j; $k < $n; $k++) {
            $tk = $bodyToks[$k];
            if ($tk === '(') { $depth++; continue; }
            if ($tk === ')') { $depth--; if ($depth === 0) { break; } continue; }
            if ($depth !== 1) { continue; }

            if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING) {
                $parts[] = decodeStringLiteralToken($tk[1]);
                $sawAnything = true;
                continue;
            }
            if (is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            if ($tk === '.') { continue; }
            /* Anything else (a real $variable, a bare constant/function
               reference, a ternary, an expression) — not guessed at. */
            $resolvable = false;
            break;
        }

        if ($resolvable && $sawAnything) {
            $out[] = implode('', $parts);
        }
    }
    return $out;
}

/* =========================================================================
 * PRIMITIVE 3 — parse schema.sql into table => {lowercased column => real
 * column name}. Recognises the same broad MySQL type-keyword set
 * test-schema-installs.php's own parser does, widened to cover the types
 * that parser does not need but this one does (TEXT/JSON/TIMESTAMP/DATETIME
 * etc — tblSongRevisions alone has all four), and excludes the same
 * structural keywords (KEY/INDEX/CONSTRAINT/...) that are not columns.
 * ========================================================================= */

function parseSchemaColumns(string $sql): array
{
    $types = [
        'BIGINT', 'INT', 'INTEGER', 'SMALLINT', 'TINYINT', 'MEDIUMINT',
        'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE', 'BIT',
        'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR',
        'CHAR', 'VARCHAR', 'BINARY', 'VARBINARY',
        'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB',
        'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT',
        'ENUM', 'SET', 'JSON', 'BOOLEAN', 'BOOL',
    ];
    $structural = ['FOREIGN', 'CONSTRAINT', 'PRIMARY', 'UNIQUE', 'INDEX', 'KEY', 'FULLTEXT', 'SPATIAL', 'CHECK'];

    $cols = [];
    $cur = null;
    foreach (explode("\n", $sql) as $line) {
        if (preg_match('/^\s*CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $line, $m)) {
            $cur = $m[1];
            $cols[$cur] = $cols[$cur] ?? [];
            continue;
        }
        if ($cur === null) { continue; }
        if (preg_match('/^\s*\)\s*ENGINE/i', $line)) { $cur = null; continue; }
        if (preg_match('/^\s*`?(\w+)`?\s+([A-Za-z]+)\s*[\(\s,]/', $line, $m)) {
            $name = $m[1];
            $type = strtoupper($m[2]);
            if (in_array(strtoupper($name), $structural, true)) { continue; }
            if (!in_array($type, $types, true)) { continue; }
            $cols[$cur][strtolower($name)] = $name;
        }
    }
    return $cols;
}

/* =========================================================================
 * PRIMITIVE 4 — build an alias => table map from every FROM/JOIN in a SQL
 * string (including inside a subquery — a nested FROM only ever ADDS an
 * entry, and a bare/no-alias subquery table can't collide with an outer
 * alias, so no ordering care is needed). A bare table name is ALSO
 * registered as its own alias, so `tblX.Col` (no AS, no shorthand alias)
 * resolves too.
 * ========================================================================= */

const READ_ACTION_ALIAS_KEYWORD_DENYLIST = [
    'on', 'where', 'order', 'group', 'limit', 'left', 'right', 'inner',
    'outer', 'join', 'using', 'having', 'set', 'values', 'duplicate',
    'union', 'as', 'into', 'select',
];

function buildAliasMap(string $sql): array
{
    $map = [];
    if (preg_match_all(
        '/\b(?:FROM|JOIN)\s+`?(tbl[A-Za-z0-9_]+)`?(?:\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_]*))?/i',
        $sql,
        $ms,
        PREG_SET_ORDER
    )) {
        foreach ($ms as $mm) {
            $table = $mm[1];
            $map[$table] = $table;
            $alias = $mm[2] ?? '';
            if ($alias !== '' && !in_array(strtolower($alias), READ_ACTION_ALIAS_KEYWORD_DENYLIST, true)) {
                $map[$alias] = $table;
            }
        }
    }
    return $map;
}

/* =========================================================================
 * PRIMITIVE 5 — the actual check. Returns a list of human-readable issue
 * strings (empty = clean or nothing checkable in this statement).
 * ========================================================================= */

function checkSqlAgainstSchema(string $sql, array $schemaCols): array
{
    $issues = [];
    if (stripos($sql, 'INFORMATION_SCHEMA') !== false) {
        /* A meta-query about the schema itself, not a query against one of
           our own tables — nothing here to check. */
        return $issues;
    }

    $aliasMap = buildAliasMap($sql);

    /* Qualified alias.Column references anywhere in the statement
       (including inside a subquery's own clauses). */
    if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)\b/', $sql, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $mm) {
            [, $alias, $col] = $mm;
            if (!isset($aliasMap[$alias])) { continue; } /* not one of OUR tables — e.g. a schema-qualified name */
            $table = $aliasMap[$alias];
            if (!isset($schemaCols[$table])) {
                $issues[] = "table `{$table}` (referenced via `{$alias}`) does not exist in schema.sql";
                continue;
            }
            if (!isset($schemaCols[$table][strtolower($col)])) {
                $issues[] = "`{$alias}.{$col}` — unknown column `{$col}` on table `{$table}`";
            }
        }
    }

    /* INSERT INTO tblX (a, b, c) — the target table is never ambiguous. */
    if (preg_match('/INSERT\s+INTO\s+`?(tbl[A-Za-z0-9_]+)`?\s*\(([^)]*)\)/is', $sql, $mm)) {
        $table = $mm[1];
        foreach (explode(',', $mm[2]) as $col) {
            $col = trim($col, " `\t\n\r\0\x0B");
            if ($col === '') { continue; }
            if (!isset($schemaCols[$table])) { $issues[] = "table `{$table}` does not exist in schema.sql"; continue; }
            if (!isset($schemaCols[$table][strtolower($col)])) {
                $issues[] = "INSERT INTO {$table}(...) — unknown column `{$col}`";
            }
        }
    }

    /* UPDATE tblX SET a = ?, b = ? — same reasoning; comma-split respects
       parenthesis depth so a function call inside a SET value (rare, but
       COALESCE/NOW() etc. do appear elsewhere in this codebase) doesn't
       get mistaken for a second assignment. */
    if (preg_match('/UPDATE\s+`?(tbl[A-Za-z0-9_]+)`?\s+SET\s+(.*?)(?:\bWHERE\b|\bORDER\s+BY\b|\bLIMIT\b|$)/is', $sql, $mm)) {
        $table = $mm[1];
        $depth = 0; $cur = ''; $segments = [];
        for ($i = 0, $L = strlen($mm[2]); $i < $L; $i++) {
            $ch = $mm[2][$i];
            if ($ch === '(') { $depth++; }
            if ($ch === ')') { $depth--; }
            if ($ch === ',' && $depth === 0) { $segments[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        if (trim($cur) !== '') { $segments[] = $cur; }
        foreach ($segments as $seg) {
            if (!preg_match('/^\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\s*=/', $seg, $sm)) { continue; }
            $col = $sm[1];
            if (!isset($schemaCols[$table])) { $issues[] = "table `{$table}` does not exist in schema.sql"; continue; }
            if (!isset($schemaCols[$table][strtolower($col)])) {
                $issues[] = "UPDATE {$table} SET ... — unknown column `{$col}`";
            }
        }
    }

    return $issues;
}

/** Did this SQL string have at least one thing the checker above actually
 *  evaluated (as opposed to a statement with no tbl* table reference at
 *  all — a COUNT(*)-only probe, say — which trivially returns no issues
 *  without having checked anything)? Used only for the "checkable count"
 *  bookkeeping, never for deciding pass/fail. */
function sqlHadCheckableReference(string $sql): bool
{
    if (stripos($sql, 'INFORMATION_SCHEMA') !== false) { return false; }
    $aliasMap = buildAliasMap($sql);
    if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)\b/', $sql, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $mm) {
            if (isset($aliasMap[$mm[1]])) { return true; }
        }
    }
    if (preg_match('/INSERT\s+INTO\s+`?tbl[A-Za-z0-9_]+`?\s*\(/i', $sql)) { return true; }
    if (preg_match('/UPDATE\s+`?tbl[A-Za-z0-9_]+`?\s+SET\s+/i', $sql)) { return true; }
    return false;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — every primitive above proven to catch a
 * planted defect AND to leave correct input alone, before the real files
 * are trusted to it.
 * ========================================================================= */

$mutationFailures = [];
function mustCatch(string $label, bool $wentRed): void
{
    global $mutationFailures;
    if (!$wentRed) { $mutationFailures[] = $label; }
}

/* --- schema-column parser: finds real columns, ignores structural lines,
   stops at the table's closing ) ENGINE line. --- */
$fixtureSchema = <<<'SQL'
CREATE TABLE IF NOT EXISTS tblWidgets (
    Id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Name    VARCHAR(50) NOT NULL,
    Notes   TEXT NULL,
    INDEX idx_Name (Name),
    CONSTRAINT fk_Widget_Thing FOREIGN KEY (ThingId) REFERENCES tblThings(Id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tblGadgets (
    Id INT UNSIGNED PRIMARY KEY
) ENGINE=InnoDB;
SQL;
$fixtureCols = parseSchemaColumns($fixtureSchema);
mustCatch(
    'parseSchemaColumns() FAILS-HIGH self-test: a real column (Id/Name/Notes) was not found',
    isset($fixtureCols['tblWidgets']['id'], $fixtureCols['tblWidgets']['name'], $fixtureCols['tblWidgets']['notes'])
);
mustCatch(
    'parseSchemaColumns() FAILS-LOW self-test: a structural keyword (INDEX/CONSTRAINT/FOREIGN) was wrongly kept as a column',
    !isset($fixtureCols['tblWidgets']['index'])
    && !isset($fixtureCols['tblWidgets']['constraint'])
    && !isset($fixtureCols['tblWidgets']['foreign'])
);
mustCatch(
    'parseSchemaColumns() FAILS-LOW self-test: a column from ANOTHER table bled across the ) ENGINE boundary',
    !isset($fixtureCols['tblGadgets']['name'])
);

/* --- alias map: real alias captured; a SQL keyword right after the table
   name is NEVER captured as a fake alias. --- */
$aliasedMap   = buildAliasMap('SELECT r.Id FROM tblSongRevisions r LEFT JOIN tblUsers u ON u.Id = r.UserId');
$noAliasMap   = buildAliasMap('SELECT Id FROM tblSongRevisions WHERE Status = ?');
mustCatch(
    'buildAliasMap() FAILS-HIGH self-test: a real alias (r -> tblSongRevisions, u -> tblUsers) was not captured',
    ($aliasedMap['r'] ?? null) === 'tblSongRevisions' && ($aliasedMap['u'] ?? null) === 'tblUsers'
);
mustCatch(
    'buildAliasMap() FAILS-LOW self-test: the keyword immediately after a bare table name (WHERE) was wrongly captured as an alias',
    !isset($noAliasMap['where']) && !isset($noAliasMap['WHERE'])
);

/* --- checkSqlAgainstSchema(): catches a planted bad qualified column,
   leaves a genuinely correct query alone, and skips an INFORMATION_SCHEMA
   probe rather than false-alarming on it. --- */
$goodSql = 'SELECT r.Id AS id, r.SongId AS songId FROM tblSongRevisions r WHERE r.SongId = ?';
$badSql  = 'SELECT r.Id AS id, r.NotAColumn AS bogus FROM tblSongRevisions r WHERE r.SongId = ?';
$infoSchemaSql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tblSongRevisions' AND COLUMN_NAME = 'NotAColumn'";
mustCatch(
    'checkSqlAgainstSchema() FAILS-LOW self-test: a genuinely correct qualified query was wrongly flagged',
    checkSqlAgainstSchema($goodSql, $fixtureCols + ['tblSongRevisions' => ['id' => 'Id', 'songid' => 'SongId']]) === []
);
$badIssues = checkSqlAgainstSchema($badSql, ['tblSongRevisions' => ['id' => 'Id', 'songid' => 'SongId']]);
mustCatch(
    'checkSqlAgainstSchema() FAILS-HIGH self-test: a planted unknown qualified column (r.NotAColumn) was not caught',
    $badIssues !== [] && stripos(implode(' ', $badIssues), 'NotAColumn') !== false
);
mustCatch(
    'checkSqlAgainstSchema() FAILS-LOW self-test: an INFORMATION_SCHEMA probe (a meta-query, not one of our tables) was wrongly flagged',
    checkSqlAgainstSchema($infoSchemaSql, []) === []
);

/* --- INSERT / UPDATE column-list checking. --- */
$badInsert = "INSERT INTO tblSongRevisions (SongId, Bogus) VALUES (?, ?)";
$badUpdate = "UPDATE tblSongRevisions SET Bogus = ? WHERE Id = ?";
$revCols = ['tblSongRevisions' => array_fill_keys(
    array_map('strtolower', ['Id', 'SongId', 'UserId', 'Action', 'PreviousData', 'NewData', 'Status', 'ReviewedBy', 'ReviewNote', 'CreatedAt']),
    true
)];
mustCatch(
    'checkSqlAgainstSchema() FAILS-HIGH self-test: an unknown column in an INSERT column list was not caught',
    stripos(implode(' ', checkSqlAgainstSchema($badInsert, $revCols)), 'Bogus') !== false
);
mustCatch(
    'checkSqlAgainstSchema() FAILS-HIGH self-test: an unknown column in an UPDATE SET clause was not caught',
    stripos(implode(' ', checkSqlAgainstSchema($badUpdate, $revCols)), 'Bogus') !== false
);
mustCatch(
    'checkSqlAgainstSchema() FAILS-LOW self-test: a genuinely correct INSERT was wrongly flagged',
    checkSqlAgainstSchema("INSERT INTO tblSongRevisions (SongId, Action) VALUES (?, ?)", $revCols) === []
);

/* --- extractCheckableSql(): a genuinely dynamic prepare() (a real $variable
   in the argument) is left alone — NOT guessed at, NOT reported as an issue,
   simply absent from the checkable list — mirroring manage/revisions.php's
   own real shape ($whereSql built from an array and concatenated in). --- */
$dynamicCaseBody = "case 'fixture_dynamic':\n    \$stmt = \$db->prepare('SELECT * FROM tblSongRevisions WHERE ' . \$whereSql);\n    break;";
mustCatch(
    'extractCheckableSql() FAILS-LOW self-test: a prepare() built from a real PHP variable was wrongly treated as checkable',
    extractCheckableSql($dynamicCaseBody) === []
);

/* --- extractCheckableSql(): a bare constant/function reference alongside a
   literal is ALSO left alone (not guessed at) — this file deliberately does
   not chase either shape; see the header for why song_revisions /
   admin_pending_revisions stay two plain literal strings rather than using
   one. --- */
$constRefCaseBody = "case 'fixture_const':\n    \$stmt = \$db->prepare(SOME_BASE_SQL . 'WHERE r.SongId = ?');\n    break;";
mustCatch(
    'extractCheckableSql() FAILS-LOW self-test: a prepare() built from a bare constant reference was wrongly treated as checkable',
    extractCheckableSql($constRefCaseBody) === []
);

/* --- THE DIRECT PROOF: the exact pre-#2086 SQL text (typed here, not
   sourced from git history, so this assertion does not depend on history
   staying intact) run through the REAL schema-derived column list for
   tblSongRevisions must be flagged with precisely the four bad references
   the issue named. This is the concrete "would this have caught it?"
   answer, not a claim. --- */
$realSchemaCols = parseSchemaColumns($schemaSrc);
mustCatch(
    'schema.sql actually has a tblSongRevisions table to check against (vacuity check on the real file)',
    isset($realSchemaCols['tblSongRevisions'])
);

$preFix2086SongRevisionsSql =
    'SELECT r.Id AS id, r.SongId AS songId, r.Action AS action,
            r.Status AS status, r.ChangesJson AS changesJson,
            r.Notes AS notes, r.CreatedAt AS createdAt,
            r.ReviewedAt AS reviewedAt,
            u.Username AS username,
            rv.Username AS reviewedBy
     FROM tblSongRevisions r
     JOIN tblUsers u ON u.Id = r.CreatedBy
     LEFT JOIN tblUsers rv ON rv.Id = r.ReviewedBy
     WHERE r.SongId = ?
     ORDER BY r.CreatedAt DESC
     LIMIT 100';
$preFix2086Issues = checkSqlAgainstSchema($preFix2086SongRevisionsSql, $realSchemaCols);
$preFix2086IssuesText = implode(' | ', $preFix2086Issues);
foreach (['ChangesJson', 'Notes', 'ReviewedAt', 'CreatedBy'] as $badCol) {
    mustCatch(
        "THE #2086 PROOF: the real, pre-fix song_revisions SQL text, checked against the REAL schema.sql column list, is flagged for its actual bad reference to `{$badCol}` — this is the concrete demonstration that this mechanism would have caught the April bug",
        stripos($preFix2086IssuesText, $badCol) !== false
    );
}

/* =========================================================================
 * REPORT THE MUTATION PHASE — every primitive must have been PROVEN able
 * to fail before its verdict on the real files is trusted (rule #34).
 * ========================================================================= */

if ($mutationFailures) {
    fwrite(STDERR, "\nFAIL: mutation self-test(s) did not go red as expected:\n\n");
    foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    echo "\n0 passed, " . count($mutationFailures) . " mutation self-test failure(s)\n";
    exit(1);
}

/* =========================================================================
 * REAL ASSERTIONS — every documented GET `/api.php?action=...` read action.
 * ========================================================================= */

echo "\n#2086 follow-up: documented read actions vs. the real schema\n\n";

/* ---- Derive the read-action list from api-docs.yaml's own tree — never a
   typed list (rule #34): every `/api.php?action=X:` path whose FIRST
   HTTP method block is `get:`. ---- */
$docsLines = explode("\n", $docsSrc);
$docsLineCount = count($docsLines);
$readActions = [];
for ($i = 0; $i < $docsLineCount; $i++) {
    if (!preg_match('#^  /api\.php\?action=([\w-]+):\s*$#', $docsLines[$i], $m)) { continue; }
    $name = $m[1];
    $method = null;
    for ($j = $i + 1; $j < $docsLineCount; $j++) {
        if (preg_match('#^  /#', $docsLines[$j])) { break; }
        if (preg_match('/^    (get|post|put|delete|patch):\s*$/', $docsLines[$j], $mm)) { $method = $mm[1]; break; }
    }
    if ($method === 'get') { $readActions[] = $name; }
}

ok('derived a plausible number of documented GET /api.php?action=... read actions from api-docs.yaml (not a typed list)',
    count($readActions) >= 50);

$actionCases = dispatchParserCasesForSwitch($api, '$action');

$notDispatched   = [];
$checkableCount  = 0;
$checkedActions  = [];
$sqlsByAction    = [];

foreach ($readActions as $name) {
    if (!in_array($name, $actionCases, true)) {
        /* Not every documented action is dispatched via `switch ($action)`
           — `health` deliberately short-circuits earlier as a top-level
           `if`, before the maintenance-mode gate (see api.php's own
           doc-comment on that). Recorded, not failed: this file only
           checks statements it can actually locate and read. */
        $notDispatched[] = $name;
        continue;
    }
    $body = caseBodyFor($api, '$action', $name);
    if ($body === null) { $notDispatched[] = $name; continue; }

    $sqls = extractCheckableSql($body);
    foreach ($sqls as $sql) {
        $sqlsByAction[$name][] = $sql;
        if (!sqlHadCheckableReference($sql)) { continue; }
        $checkableCount++;
        $checkedActions[$name] = true;
        $issues = checkSqlAgainstSchema($sql, $realSchemaCols);
        ok("'{$name}' — every alias.Column / INSERT / UPDATE reference in its checkable SQL exists on the real table"
            . ($issues ? ' — FOUND: ' . implode('; ', $issues) : ''),
            $issues === []);
    }
}

/* ---- The mechanism promised in api.php's own comment on these two cases:
   `song_revisions` and `admin_pending_revisions` stay two INDEPENDENT
   literal SQL strings (see file header for why — a pre-existing,
   unrelated guard requires it), so nothing at runtime stops them drifting
   into two DIFFERENT queries the way they did before #2086. What replaces
   that is this assertion: the shared SELECT/FROM/JOIN portion — everything
   up to the first WHERE — must be BYTE-IDENTICAL between the two. This is
   the rule #35 "mechanism, not a comment" for the one thing that actually
   needs to stay in sync; the schema-column check above already covers
   correctness, this covers the two staying the SAME as each other. ---- */
$songRevisionsSql       = $sqlsByAction['song_revisions'][0] ?? null;
$adminPendingRevSql     = $sqlsByAction['admin_pending_revisions'][0] ?? null;
$sharedPrefixOf = static function (?string $sql): ?string {
    if ($sql === null) { return null; }
    $pos = stripos($sql, 'WHERE');
    return $pos === false ? null : substr($sql, 0, $pos);
};
$songRevisionsPrefix   = $sharedPrefixOf($songRevisionsSql);
$adminPendingRevPrefix = $sharedPrefixOf($adminPendingRevSql);
ok('both song_revisions and admin_pending_revisions have a checkable SQL statement with a WHERE clause to split on (vacuity check)',
    $songRevisionsPrefix !== null && $adminPendingRevPrefix !== null);
ok("song_revisions' and admin_pending_revisions' shared SELECT/FROM/JOIN prefix (everything before WHERE) is BYTE-IDENTICAL — the mechanism that keeps the two from silently drifting into two different queries again",
    $songRevisionsPrefix !== null && $songRevisionsPrefix === $adminPendingRevPrefix);

echo "\n";
echo '  (' . count($notDispatched) . " of " . count($readActions) . " documented read actions were not dispatched via a plain switch case and were skipped — expected for at least `health`, which short-circuits before the switch: "
    . implode(', ', $notDispatched) . ")\n";
echo "  ({$checkableCount} statements across " . count($checkedActions) . " actions had at least one fully-resolvable, schema-checkable reference — everything else in these actions builds its SQL dynamically and is left unchecked rather than guessed at)\n";

/* The floor: today's real count is comfortably above this. If a future
   change to how MANY actions build fully-literal SQL shrinks this a lot,
   that is worth a human looking at — a checker that quietly stops checking
   anything still prints "0 failed", which is the exact false-coverage trap
   rule #34 warns about. */
ok('at least 15 real, checkable SQL statements were actually run through the schema check (not a guard that quietly checks nothing)',
    $checkableCount >= 15);

/* The two actions this whole file exists for must themselves be inside the
   checked set — not just "some read actions somewhere". If a later refactor
   of either changes its SQL-building shape in a way extractCheckableSql()
   can no longer follow, THIS is the assertion that catches the guard's own
   blind spot reopening on exactly the two actions that matter most here. */
ok("'song_revisions' (the #2086 action itself) is among the actions this file actually checked, not skipped as unresolvable",
    isset($checkedActions['song_revisions']));
ok("'admin_pending_revisions' (the #2086 action itself) is among the actions this file actually checked, not skipped as unresolvable",
    isset($checkedActions['admin_pending_revisions']));

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nA documented `action=X` read that selects a column the real schema.sql\n";
    echo "does not have will throw for every caller under this codebase's STRICT\n";
    echo "mysqli mode (#2086) — fix the query, or fix schema.sql if the column\n";
    echo "genuinely should exist and a migration is missing.\n";
    exit(1);
}
echo "\nAll real, checkable read-action SQL agrees with schema.sql, and every checking\n";
echo "primitive was proven able to catch a planted defect before being trusted.\n";
exit(0);
