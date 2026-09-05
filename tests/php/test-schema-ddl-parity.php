<?php
/**
 * iHymns — Migration -> schema.sql DDL Parity Test (#2077)
 *
 * ELI5: test-schema-coverage.php only checks that a table/column a migration
 * creates is MENTIONED somewhere in schema.sql. This test goes one level
 * deeper and checks that it is mentioned the SAME WAY — same column types,
 * same nullability, same defaults, same COMMENT text, same index columns,
 * same foreign-key targets. A migration can pass the coverage test while
 * still minting a differently-shaped table (wrong FK target, a stale
 * column name, a drifted COMMENT) — that is exactly the #2077 bug this
 * test exists to catch: migrate-vocal-parts.php kept declaring
 * tblVocalParts.CreditPersonId (FK to tblCreditPeople) for months after
 * schema.sql and every other consumer had moved to MusicianId (FK to
 * tblMusicians), because "does the column CreditPersonId/MusicianId exist
 * SOMEWHERE" was satisfied either way once the rename made them synonyms
 * in schema_audit.php's own coverage remap.
 *
 * WHY A SIBLING FILE, NOT AN EXTENSION OF test-schema-coverage.php:
 * That file's whole design is a single directional set-membership check
 * (migrations subseteq schema.sql) driven by schemaAuditParseSchema(),
 * which deliberately THROWS AWAY type/comment/index/FK detail because its
 * callers (the live /manage/schema-audit page, the coverage test) only
 * ever needed column names. This test needs the opposite: it keeps every
 * structural detail and is comparison-direction-aware (a migration that
 * predates a later rename/alter is EXPECTED to differ — see "EXEMPTIONS"
 * below). Bolting that onto schemaAuditParseSchema() would either slow down
 * the hot page-load path that reuses it, or fork the parser inside the same
 * file under two names — worse than one clearly-scoped sibling file whose
 * failure mode ("this table's SHAPE drifted") reads differently from the
 * existing one's ("this table isn't MENTIONED anywhere").
 *
 * SCOPE: this test only examines migration files that contain a literal
 * `CREATE TABLE [IF NOT EXISTS] tblX ( ... ) ENGINE=` block — i.e. a
 * migration that ships a table's FULL shape in one statement, per rule #20's
 * "one-pass forward-looking schema" convention (#1066/#1088/#1137-style
 * batches). A migration that only ever ADD COLUMNs is out of this test's
 * scope entirely (there is no "full CREATE" to diff) — that class of drift,
 * if any, is what test-schema-coverage.php's presence check is for.
 *
 * COMPARISON DIRECTION: for every column/index/FK the MIGRATION declares,
 * this test asks "does schema.sql's current CREATE TABLE for this same
 * table have an entry of the SAME NAME with the SAME type / nullability /
 * default / COMMENT (columns) or SAME columns+target (index/FK)?" It does
 * NOT require the reverse (a column schema.sql has that the migration
 * doesn't) — a table legitimately grows columns via later, separate
 * ADD COLUMN migrations (rule #20), and requiring the ORIGINAL one-pass
 * CREATE to already contain every column ever added since would make this
 * test permanently red on realistic, correct history.
 *
 * EXEMPTIONS (narrow, reasoned, tree-derived — NOT a hand-typed table list):
 * A table whose migration-time shape is meant to be TRANSFORMED by a LATER
 * migration is expected to differ from schema.sql's current (post-transform)
 * shape, and that is not a bug. This test derives "later" mechanically from
 * `manage/includes/migration-registry.php`'s own `$MIGRATIONS` array order
 * (which IS execution order: setup-database.php's
 * `$migrationOrder = array_keys($MIGRATIONS)`), not from a maintained list:
 *
 *   1. VIEW-EXEMPT — schema.sql declares the table's name as a
 *      `CREATE OR REPLACE VIEW`, not a `CREATE TABLE` (the #1741 P2-A
 *      musicians-rename compat-view pattern: tblCreditPeople and its six
 *      siblings are now views over tblMusician*). A VIEW has no columns/
 *      types/indexes/FKs of its own to structurally compare against a
 *      CREATE TABLE, so these are skipped entirely — not a judgement call,
 *      a type mismatch (TABLE vs VIEW) this test cannot meaningfully cross.
 *
 *   2. LATER-TRANSFORM-EXEMPT — some OTHER migration file, registered at a
 *      LATER position than the one doing the CREATE TABLE, contains a
 *      literal `RENAME COLUMN` / `RENAME INDEX` / `RENAME TABLE` / the
 *      `DROP FOREIGN KEY x, ADD CONSTRAINT y` FK-rename shape targeting
 *      that same table. Example: migrate-tune-enrichment.php (registry
 *      position 85) creates tblTuneCredits.CreditPersonId; that is CORRECT
 *      at the time it runs — migrate-musicians-rename.php (position 87,
 *      LATER) is what turns it into MusicianId via ALTER, and a fresh
 *      install never runs either script (it reads schema.sql directly, in
 *      the already-renamed shape). Comparing tune-enrichment's CREATE
 *      against schema.sql's current tblTuneCredits would be a permanent,
 *      correctly-explained false positive — exactly rule #34's "a guard
 *      that fails on correct code gets weakened or deleted" trap.
 *      The DISTINGUISHING test against #2077's actual bug:
 *      migrate-vocal-parts.php (position 96) is registered AFTER
 *      migrate-musicians-rename.php (position 87) — the transform is
 *      EARLIER, not later — so vocal-parts is NOT exempted here and its
 *      CREATE is compared strictly, which is exactly what caught the bug.
 *      "Later" is a per-TABLE exemption (not per-column): replaying every
 *      subsequent ALTER's cumulative column-level effect (e.g. a MODIFY
 *      COLUMN that only touches a comment) is out of scope — once a table
 *      is known to be re-touched afterward, its migration-time CREATE is
 *      not held to schema.sql's current shape at all.
 *      A transform-file with NO registry entry (should not happen — rule
 *      #19 requires one) is NOT treated as "later": unknown position never
 *      grants an exemption, so an unregistered transform fails safe toward
 *      still comparing strictly rather than silently going blind.
 *
 *   3. NO-SCHEMA-ENTRY — schema.sql has neither a TABLE nor a VIEW for that
 *      name at all. That is test-schema-coverage.php's job to catch
 *      (missing table), not this test's; skipped here without complaint.
 *
 * Every exemption actually engaged on a given run is a place this test is
 * blind, so `verboseSummary()` below prints the exemption list even on a
 * clean PASS — read it before trusting a green run on a new migration.
 *
 * GRANDFATHERED PRE-EXISTING DRIFT (count-exact allowlist, $allow below):
 * ELI5: an EXEMPTION above means "this table can't fairly be compared at
 * all" (it's a VIEW, or something later legitimately reshapes it) — this is
 * a DIFFERENT thing. A grandfathered table CAN be compared, and the
 * comparison genuinely finds a real difference; we are choosing, on purpose,
 * to let that ALREADY-EXISTING difference through for now rather than block
 * unrelated work on fixing it, while still making sure the SAME table can
 * never quietly grow a SECOND, NEW difference on top of the one we allowed.
 * Detail: this test was introduced already finding 58 pre-existing drifts
 * across the tree (#2077 follow-up) — a guard that is red the day it lands
 * gets ignored or deleted (CLAUDE.md rule #34's "a guard that fails on
 * correct code gets weakened"; the inverse also holds for one that fails on
 * KNOWN, already-tracked debt with no way to land it green). The house
 * pattern for this is CLAUDE.md rule #30's count-exact allowlist, the same
 * shape tests/php/test-fragment-inline-scripts.php uses: `$allow` below is
 * keyed by TABLE NAME (not by file, since one migration can create several
 * tables and a table's drift is what we're choosing to accept) to a
 * `{count, why}` pair. `$failuresByTable` collects every real diff for a
 * table before the allowlist is applied, then BOTH directions are checked,
 * exactly like the fragment-script guard: (1) a table with MORE diffs than
 * its allowance — a NEW drift, even one arriving alongside already-allowed
 * ones on the SAME table — fails the build; (2) an allowlisted table with
 * FEWER diffs than it claims means the debt was already paid elsewhere
 * (someone fixed it without shrinking the list) and the stale entry itself
 * must fail the build until deleted or its count is lowered. A table with NO
 * entry at all is simply `count => 0`, so any drift on it is entirely new
 * and fails immediately — the allowlist can only ever get smaller over
 * time, never grow silently. Grandfathered tables are printed on every run,
 * pass or fail, the same way exemptions are — read that list before trusting
 * a green run just as you would the exemption list.
 *
 * PARSE FAILURES: a column/index/FK clause this test's parser cannot make
 * sense of is reported under "UNPARSEABLE" (non-fatal) rather than silently
 * treated as a match — a parser gap must never read as a clean bill of
 * health (mirrors rule #34's "under-reporting is worse than not scanning").
 *
 *   php tests/php/test-schema-ddl-parity.php
 *
 * Exit status 0 = clean (mismatches only), 1 = at least one structural drift.
 */

declare(strict_types=1);

$repoRoot      = dirname(__DIR__, 2);
$schemaFile    = $repoRoot . '/appWeb/.sql/schema.sql';
$sqlDir        = $repoRoot . '/appWeb/.sql';
$registryFile  = $repoRoot . '/appWeb/public_html/manage/includes/migration-registry.php';

if (!is_readable($schemaFile)) {
    fwrite(STDERR, "FATAL: $schemaFile not readable\n");
    exit(1);
}
if (!is_readable($registryFile)) {
    fwrite(STDERR, "FATAL: $registryFile not readable\n");
    exit(1);
}

/* ------------------------------------------------------------------ *
 * Low-level, quote-aware SQL text helpers. Every one of these treats a
 * single-quoted SQL string literal as opaque content — `(`, `)`, `,` and
 * `--` inside a COMMENT string must never be mistaken for structural
 * syntax (schema.sql has real COMMENT text containing literal "--" as
 * prose; schemaAuditParseSchema()'s own doc-block documents getting this
 * order wrong once already, #722).
 * ------------------------------------------------------------------ */

/**
 * Find the matching `)` for the `(` at $s[$openPos], skipping over the
 * content of any single-quoted string (handling both '' and \' escaping)
 * so a comment containing literal parentheses can never desync the count.
 *
 * @return array{0:string,1:int}|null  [innerText, indexOfClosingParen]
 */
function ddlExtractParenGroup(string $s, int $openPos): ?array
{
    $n = strlen($s);
    if ($openPos >= $n || $s[$openPos] !== '(') {
        return null;
    }
    $depth = 0;
    $i = $openPos;
    while ($i < $n) {
        $ch = $s[$i];
        if ($ch === "'") {
            $i++;
            while ($i < $n) {
                if ($s[$i] === "'") {
                    if ($i + 1 < $n && $s[$i + 1] === "'") { $i += 2; continue; }
                    $i++;
                    break;
                }
                if ($s[$i] === '\\' && $i + 1 < $n) { $i += 2; continue; }
                $i++;
            }
            continue;
        }
        if ($ch === '(') { $depth++; $i++; continue; }
        if ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                return [substr($s, $openPos + 1, $i - $openPos - 1), $i];
            }
            $i++;
            continue;
        }
        $i++;
    }
    return null;
}

/**
 * Split a CREATE TABLE body into its top-level comma-separated clauses
 * (one column def or one table-level constraint per segment), the same
 * quote-and-paren-depth-aware way schemaAuditParseSchema() does, but
 * WITHOUT that function's COMMENT-stripping pass — this test needs the
 * comment text intact to compare it.
 *
 * @return string[]
 */
function ddlSplitTopLevel(string $body): array
{
    $segments = [];
    $depth = 0;
    $buf = '';
    $n = strlen($body);
    $i = 0;
    while ($i < $n) {
        $ch = $body[$i];
        if ($ch === "'") {
            $buf .= $ch;
            $i++;
            while ($i < $n) {
                $c = $body[$i];
                if ($c === "'") {
                    if ($i + 1 < $n && $body[$i + 1] === "'") {
                        $buf .= "''";
                        $i += 2;
                        continue;
                    }
                    $buf .= $c;
                    $i++;
                    break;
                }
                if ($c === '\\' && $i + 1 < $n) {
                    $buf .= $c . $body[$i + 1];
                    $i += 2;
                    continue;
                }
                $buf .= $c;
                $i++;
            }
            continue;
        }
        if ($ch === '-' && $i + 1 < $n && $body[$i + 1] === '-') {
            while ($i < $n && $body[$i] !== "\n") { $i++; }
            continue;
        }
        if ($ch === '(') { $depth++; $buf .= $ch; $i++; continue; }
        if ($ch === ')') { $depth--; $buf .= $ch; $i++; continue; }
        if ($ch === ',' && $depth === 0) {
            $segments[] = $buf;
            $buf = '';
            $i++;
            continue;
        }
        $buf .= $ch;
        $i++;
    }
    if (trim($buf) !== '') { $segments[] = $buf; }
    return $segments;
}

function ddlNormalizeWs(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

/**
 * Every migration in this codebase embeds its DDL in a PHP DOUBLE-quoted
 * string (`$db->query("CREATE TABLE ...")`), which means the RAW SOURCE
 * TEXT this test reads is not the SQL text MySQL actually receives: PHP's
 * own double-quote parser resolves `\\` -> `\`, `\"` -> `"` and `\$` -> `$`
 * (the `\$` escape shows up wherever a COMMENT needs a literal `$`, e.g. a
 * regex anchor like `{1,29}\$`, to stop PHP reading it as the start of a
 * variable; `\\` shows up wherever a COMMENT displays a literal backslash,
 * e.g. `NormTitle + \\n + NormFirstLine` meaning the two PRINTABLE
 * characters "\" and "n", not a real newline) before the string ever
 * reaches mysqli. Every OTHER backslash sequence (there is no PHP
 * double-quote escape for `'`) is left untouched by PHP, verbatim, two
 * characters. This is a single left-to-right pass mirroring PHP's own
 * scan order — NOT sequential str_replace() calls — because a naive
 * "replace \\\" then replace \\$" ordering can't tell a genuine `\\"`
 * (escaped backslash then a real, separately-escaped quote) from what
 * this codebase never actually writes; scanning once, left to right,
 * exactly like PHP's own lexer does, has no such ambiguity.
 * schema.sql has no such layer (it is read as plain SQL) — this
 * normalisation exists so a migration and its schema.sql mirror are
 * compared as the two RUNTIME STRINGS they actually are, not as raw
 * source bytes with a PHP printing convention still attached. */
function ddlPhpDoubleQuoteUnescape(?string $s): ?string
{
    if ($s === null) { return null; }
    $out = '';
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        if ($s[$i] === '\\' && $i + 1 < $n) {
            $next = $s[$i + 1];
            if ($next === '\\') { $out .= '\\'; $i += 2; continue; }
            if ($next === '"')  { $out .= '"';  $i += 2; continue; }
            if ($next === '$')  { $out .= '$';  $i += 2; continue; }
            /* Unrecognized PHP double-quote escape (e.g. `\'`, which PHP
               does not treat specially inside a double-quoted string) —
               emit just the backslash and let the next iteration handle
               the following character normally, so both survive intact
               for ddlSqlStringUnescape() to interpret at the SQL layer. */
            $out .= '\\';
            $i++;
            continue;
        }
        $out .= $s[$i];
        $i++;
    }
    return $out;
}

/**
 * The SECOND, independent escape layer: once PHP has handed the string to
 * mysqli, MySQL's own parser resolves the SQL single-quoted literal's
 * escapes — `''` (doubled quote) and, under this codebase's default (non
 * ANSI_QUOTES/NO_BACKSLASH_ESCAPES) sql_mode, `\'` (backslash-escaped
 * quote) both mean one literal `'`. Both spellings are used interchange-
 * ably across these migrations and schema.sql (e.g. migrate-publishers-
 * entity.php's `person\'s name` vs schema.sql's `person''s name` for the
 * exact same COMMENT) — genuinely equivalent SQL, not a real difference,
 * so both are folded to a plain `'` here before comparison. Must run
 * AFTER ddlPhpDoubleQuoteUnescape() — see that function's doc-block for
 * why order matters between the two layers. */
function ddlSqlStringUnescape(string $s): string
{
    $out = '';
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        if ($s[$i] === "'" && $i + 1 < $n && $s[$i + 1] === "'") { $out .= "'"; $i += 2; continue; }
        if ($s[$i] === '\\' && $i + 1 < $n && $s[$i + 1] === "'") { $out .= "'"; $i += 2; continue; }
        $out .= $s[$i];
        $i++;
    }
    return $out;
}

/**
 * Parse one column-definition clause into its comparable parts. Returns
 * null when the clause doesn't look like `Name TYPE ...` at all (reported
 * by the caller as UNPARSEABLE, never silently treated as a match).
 */
function ddlParseColumn(string $seg): ?array
{
    $seg = trim($seg);
    if (!preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s+(.+)$/s', $seg, $m)) {
        return null;
    }
    $name = $m[1];
    $rest = $m[2];

    $comment = null;
    if (preg_match("/COMMENT\\s+'((?:[^'\\\\]|\\\\.|'')*)'/is", $rest, $cm)) {
        $comment = ddlSqlStringUnescape(ddlPhpDoubleQuoteUnescape($cm[1]) ?? $cm[1]);
        $rest = preg_replace("/COMMENT\\s+'(?:[^'\\\\]|\\\\.|'')*'/is", ' ', $rest, 1) ?? $rest;
    }
    $rest = ddlNormalizeWs($rest);

    $autoIncrement    = (bool) preg_match('/\bAUTO_INCREMENT\b/i', $rest);
    $primaryKeyInline = (bool) preg_match('/\bPRIMARY\s+KEY\b/i', $rest);

    $default = null;
    if (preg_match(
        "/\\bDEFAULT\\s+('(?:[^'\\\\]|\\\\.|'')*'|CURRENT_TIMESTAMP(?:\\(\\))?|NULL|-?[0-9]+(?:\\.[0-9]+)?|[A-Za-z_][A-Za-z0-9_]*)/i",
        $rest,
        $dm
    )) {
        $raw = $dm[1];
        if (preg_match("/^'(.*)'\$/s", $raw, $dq)) {
            $default = "'" . ddlSqlStringUnescape(ddlPhpDoubleQuoteUnescape($dq[1]) ?? $dq[1]) . "'";
        } else {
            $default = strtoupper($raw);
        }
    }

    $onUpdate = null;
    if (preg_match('/\bON\s+UPDATE\s+(CURRENT_TIMESTAMP(?:\(\))?)/i', $rest, $ou)) {
        $onUpdate = strtoupper(preg_replace('/\s+/', '', $ou[1]) ?? $ou[1]);
    }

    $notNull = (bool) preg_match('/\bNOT\s+NULL\b/i', $rest);
    if ($primaryKeyInline) { $notNull = true; }

    $type = $rest;
    if (preg_match(
        '/\b(NOT\s+NULL|NULL|DEFAULT|AUTO_INCREMENT|PRIMARY\s+KEY|ON\s+UPDATE)\b/i',
        $rest,
        $km,
        PREG_OFFSET_CAPTURE
    )) {
        $type = substr($rest, 0, $km[0][1]);
    }
    $type = strtoupper(ddlNormalizeWs($type));
    $type = preg_replace('/\s*\(\s*/', '(', $type) ?? $type;
    $type = preg_replace('/\s*\)\s*/', ')', $type) ?? $type;
    $type = preg_replace('/\s*,\s*/', ',', $type) ?? $type;

    return [
        'name'     => $name,
        'type'     => $type,
        'notNull'  => $notNull,
        'default'  => $default,
        'onUpdate' => $onUpdate,
        'comment'  => $comment,
    ];
}

/** Parse one INDEX/UNIQUE/PRIMARY/FULLTEXT or CONSTRAINT...FOREIGN KEY clause. */
function ddlParseIndexOrConstraint(string $seg): ?array
{
    $seg = trim($seg);

    if (preg_match(
        '/^CONSTRAINT\s+([A-Za-z_][A-Za-z0-9_]*)\s+FOREIGN\s+KEY\s*\(/i',
        $seg,
        $m,
        PREG_OFFSET_CAPTURE
    )) {
        $name = $m[1][0];
        $openPos = $m[0][1] + strlen($m[0][0]) - 1;
        $group = ddlExtractParenGroup($seg, $openPos);
        if ($group === null) { return null; }
        [$colsRaw, $closePos] = $group;
        $cols = array_map('trim', explode(',', $colsRaw));

        $tail = substr($seg, $closePos + 1);
        if (!preg_match(
            '/REFERENCES\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/i',
            $tail,
            $rm,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }
        $refTable = $rm[1][0];
        $refOpen  = $rm[0][1] + strlen($rm[0][0]) - 1;
        $refGroup = ddlExtractParenGroup($tail, $refOpen);
        if ($refGroup === null) { return null; }
        [$refColsRaw, $refClose] = $refGroup;
        $refCols = array_map('trim', explode(',', $refColsRaw));

        $afterRef = substr($tail, $refClose + 1);
        $onDelete = null;
        $onUpdate = null;
        if (preg_match('/ON\s+DELETE\s+(CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION)/i', $afterRef, $od)) {
            $onDelete = strtoupper(ddlNormalizeWs($od[1]));
        }
        if (preg_match('/ON\s+UPDATE\s+(CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION)/i', $afterRef, $ou)) {
            $onUpdate = strtoupper(ddlNormalizeWs($ou[1]));
        }

        return [
            'kind'       => 'FOREIGN KEY',
            'name'       => $name,
            'columns'    => $cols,
            'refTable'   => $refTable,
            'refColumns' => $refCols,
            'onDelete'   => $onDelete,
            'onUpdate'   => $onUpdate,
        ];
    }

    if (preg_match(
        '/^(UNIQUE\s+KEY|UNIQUE\s+INDEX|INDEX|KEY|PRIMARY\s+KEY|FULLTEXT(?:\s+(?:INDEX|KEY))?)\s+([A-Za-z_][A-Za-z0-9_]*)?\s*\(/i',
        $seg,
        $m,
        PREG_OFFSET_CAPTURE
    )) {
        $kindRaw = strtoupper(ddlNormalizeWs($m[1][0]));
        $name    = (isset($m[2]) && $m[2][0] !== '') ? $m[2][0] : null;
        $openPos = $m[0][1] + strlen($m[0][0]) - 1;
        $group   = ddlExtractParenGroup($seg, $openPos);
        if ($group === null) { return null; }
        [$colsRaw,] = $group;
        $cols = array_map('trim', explode(',', $colsRaw));

        if (strpos($kindRaw, 'UNIQUE') === 0) { $kind = 'UNIQUE'; }
        elseif (strpos($kindRaw, 'PRIMARY') === 0) { $kind = 'PRIMARY'; }
        elseif (strpos($kindRaw, 'FULLTEXT') === 0) { $kind = 'FULLTEXT'; }
        else { $kind = 'INDEX'; }

        return ['kind' => $kind, 'name' => $name, 'columns' => $cols];
    }

    return null;
}

/**
 * Parse a full CREATE TABLE body into columns/indexes/constraints.
 * Returns the parse plus a list of segments that didn't fit any known
 * shape (UNPARSEABLE — reported, never silently skipped as a pass).
 */
function ddlParseTableBody(string $body): array
{
    $columns = [];
    $indexes = [];
    $constraints = [];
    $unparseable = [];

    foreach (ddlSplitTopLevel($body) as $seg) {
        $trimmed = ddlNormalizeWs($seg);
        if ($trimmed === '') { continue; }

        if (preg_match('/^(PRIMARY\s+KEY|UNIQUE|INDEX|KEY|CONSTRAINT|FULLTEXT|SPATIAL)\b/i', $trimmed)) {
            $parsed = ddlParseIndexOrConstraint($trimmed);
            if ($parsed === null) { $unparseable[] = $trimmed; continue; }
            if ($parsed['kind'] === 'FOREIGN KEY') {
                $constraints[$parsed['name']] = $parsed;
            } else {
                $key = $parsed['name'] ?? ('__unnamed_' . $parsed['kind'] . '_' . count($indexes));
                $indexes[$key] = $parsed;
            }
            continue;
        }

        $col = ddlParseColumn($trimmed);
        if ($col === null) { $unparseable[] = $trimmed; continue; }
        $columns[$col['name']] = $col;
    }

    return [
        'columns'     => $columns,
        'indexes'     => $indexes,
        'constraints' => $constraints,
        'unparseable' => $unparseable,
    ];
}

/**
 * schema.sql declares a handful of FKs as a STANDALONE trailing
 * `ALTER TABLE tblX ADD CONSTRAINT fkName FOREIGN KEY (...) REFERENCES
 * tblY(...) [ON DELETE ...] [ON UPDATE ...];` AFTER every CREATE TABLE,
 * rather than inline inside the CREATE TABLE body — e.g. fk_Lyrics_
 * SubmittedBy/fk_Lyrics_ApprovedBy/fk_ApiKeys_CreatedBy all reference
 * tblUsers, which is declared later in the file, so the FK is deferred to
 * avoid a forward reference. A migration that creates the SAME FK inline
 * (perfectly legal — its own file controls statement order) would
 * otherwise be flagged as "constraint missing from schema.sql" purely
 * because this test only looked inside CREATE TABLE parens. This is not
 * an exemption (nothing here is UNCOMPARABLE) — it is the other HALF of
 * schema.sql's constraint list for the same table, folded in before the
 * comparison runs. Excludes the `DROP FOREIGN KEY x, ADD CONSTRAINT y`
 * rename shape by construction: that shape is built from PHP-interpolated
 * `{$table}`/`{$new}` placeholders in migrate-musicians-rename.php's
 * helper, never a literal `tbl\w+` name immediately after ALTER TABLE.
 *
 * @return array<string, array<string, array>> tableName => [fkName => parsed]
 */
function ddlExtractTrailingAlterConstraints(string $contents): array
{
    $stripped = preg_replace('/\/\*.*?\*\//s', '', $contents) ?? $contents;
    $out = [];
    if (!preg_match_all(
        '/ALTER\s+TABLE\s+(tbl\w+)\s+ADD\s+(CONSTRAINT\s+\w+\s+FOREIGN\s+KEY\s*\([^;]*?);/is',
        $stripped,
        $m,
        PREG_SET_ORDER
    )) {
        return $out;
    }
    foreach ($m as $mm) {
        $tbl = $mm[1];
        $parsed = ddlParseIndexOrConstraint(trim($mm[2]));
        if ($parsed !== null && $parsed['kind'] === 'FOREIGN KEY') {
            $out[$tbl][$parsed['name']] = $parsed;
        }
    }
    return $out;
}

/**
 * Find every `CREATE TABLE [IF NOT EXISTS] tblX ( ... )` block in a source
 * text (schema.sql or a migration file) and return [tableName => parsed
 * body], last-one-wins if a name somehow appears twice in one file (it
 * never legitimately should). Merges in any standalone trailing
 * `ALTER TABLE tblX ADD CONSTRAINT ...` FKs for the same table (see
 * ddlExtractTrailingAlterConstraints()) so a deferred-FK table is compared
 * whole, not just its inline portion.
 */
function ddlExtractCreateTables(string $contents): array
{
    $stripped = preg_replace('/\/\*.*?\*\//s', '', $contents) ?? $contents;
    $out = [];
    if (!preg_match_all(
        '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(tbl\w+)\s*\(/is',
        $stripped,
        $starts,
        PREG_OFFSET_CAPTURE
    )) {
        return $out;
    }
    foreach ($starts[1] as $idx => $nameMatch) {
        $tableName   = $nameMatch[0];
        $fullOffset  = $starts[0][$idx][1];
        $fullText    = $starts[0][$idx][0];
        $openParenAt = $fullOffset + strlen($fullText) - 1;
        $group = ddlExtractParenGroup($stripped, $openParenAt);
        if ($group === null) { continue; }
        [$body,] = $group;
        $out[$tableName] = ddlParseTableBody($body);
    }

    $trailingFks = ddlExtractTrailingAlterConstraints($contents);
    foreach ($trailingFks as $tbl => $fks) {
        if (!isset($out[$tbl])) { continue; }
        foreach ($fks as $fkName => $fkDef) {
            $out[$tbl]['constraints'][$fkName] = $fkDef;
        }
    }

    return $out;
}

/** Names declared as `CREATE [OR REPLACE] VIEW tblX` in a source text. */
function ddlExtractViewNames(string $contents): array
{
    $stripped = preg_replace('/\/\*.*?\*\//s', '', $contents) ?? $contents;
    $names = [];
    if (preg_match_all('/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+(tbl\w+)/i', $stripped, $m)) {
        $names = array_fill_keys($m[1], true);
    }
    return $names;
}

/* ------------------------------------------------------------------ *
 * Registry order — the execution order setup-database.php actually uses
 * (`$migrationOrder = array_keys($MIGRATIONS)`), read straight from the
 * PHP array literal's own top-to-bottom 'script' => '...' declarations.
 * ------------------------------------------------------------------ */
function ddlReadRegistryOrder(string $registryFile): array
{
    $contents = (string) file_get_contents($registryFile);
    $order = [];
    if (preg_match_all("/'script'\\s*=>\\s*'([^']+)'/", $contents, $m)) {
        foreach ($m[1] as $i => $script) {
            $order[$script] = $i;
        }
    }
    return $order;
}

/* ------------------------------------------------------------------ *
 * Scan every migration file for a literal table-restructuring statement
 * (RENAME COLUMN / RENAME INDEX / RENAME TABLE / the DROP FOREIGN KEY,
 * ADD CONSTRAINT rename shape) and record which FILE touches which TABLE.
 * This is what makes exemption (2) tree-derived rather than a hand-typed
 * table list — see the file doc-block.
 *
 * @return array<string,string[]> tableName => [migrationFileBasename, …]
 */
function ddlScanTransformFiles(array $files): array
{
    $byTable = [];
    foreach ($files as $file) {
        $contents = @file_get_contents($file);
        if ($contents === false) { continue; }
        $stripped = preg_replace('/\/\*.*?\*\//s', '', $contents) ?? $contents;
        $base = basename($file);
        $tablesTouched = [];

        if (preg_match_all('/ALTER\s+TABLE\s+(tbl\w+)\s+RENAME\s+COLUMN\s+\w+\s+TO\s+\w+/i', $stripped, $m)) {
            foreach ($m[1] as $t) { $tablesTouched[$t] = true; }
        }
        if (preg_match_all('/ALTER\s+TABLE\s+(tbl\w+)\s+RENAME\s+INDEX\s+\w+\s+TO\s+\w+/i', $stripped, $m)) {
            foreach ($m[1] as $t) { $tablesTouched[$t] = true; }
        }
        if (preg_match_all(
            '/ALTER\s+TABLE\s+(tbl\w+)\s+DROP\s+FOREIGN\s+KEY\s+\w+\s*,\s*ADD\s+CONSTRAINT\s+\w+/i',
            $stripped,
            $m
        )) {
            foreach ($m[1] as $t) { $tablesTouched[$t] = true; }
        }
        /* MODIFY [COLUMN] col ... / CHANGE [COLUMN] old new ... — a type,
           nullability, default or COMMENT change on an EXISTING column
           (rule #20's "widen ENUM to VARCHAR with no ALTER to the app"
           examples, e.g. migrate-identifier-media-hardening.php's
           tblSongMedia.Kind, migrate-tune-enrichment.php's
           tblExternalLinkTypes.Category/AppliesTo, migrate-setlist-share-
           scope.php's tblSharedSetlists.ShareId width). Table-level, not
           column-level, for the same reason RENAME is table-level (see the
           file doc-block) — this codebase's ALTER statements routinely
           split "ALTER TABLE tblX" and "MODIFY colY ..." across lines, so
           \s+ (which matches newlines) bridges them rather than requiring
           the column name on the same regex line. */
        if (preg_match_all('/ALTER\s+TABLE\s+(tbl\w+)\s+(?:MODIFY|CHANGE)\b/i', $stripped, $m)) {
            foreach ($m[1] as $t) { $tablesTouched[$t] = true; }
        }
        if (preg_match_all('/RENAME\s+TABLE\s+((?:tbl\w+\s+TO\s+tbl\w+\s*,?\s*)+)/is', $stripped, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/(tbl\w+)\s+TO\s+(tbl\w+)/i', $block, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $tablesTouched[$p[1]] = true;
                        $tablesTouched[$p[2]] = true;
                    }
                }
            }
        }

        foreach (array_keys($tablesTouched) as $t) {
            $byTable[$t][] = $base;
        }
    }
    return $byTable;
}

/* ------------------------------------------------------------------ *
 * Structural comparers — one migration column/index/FK vs schema.sql's.
 * ------------------------------------------------------------------ */
function ddlCompareColumn(array $mig, array $schema): array
{
    $diffs = [];
    if ($mig['type'] !== $schema['type']) {
        $diffs[] = "type: migration='{$mig['type']}' schema.sql='{$schema['type']}'";
    }
    if ($mig['notNull'] !== $schema['notNull']) {
        $diffs[] = 'nullability: migration=' . ($mig['notNull'] ? 'NOT NULL' : 'NULL')
                  . ' schema.sql=' . ($schema['notNull'] ? 'NOT NULL' : 'NULL');
    }
    $migDefault = $mig['default'];
    $schemaDefault = $schema['default'];
    if ($migDefault !== $schemaDefault) {
        $diffs[] = "default: migration=" . ($migDefault ?? '(none)') . " schema.sql=" . ($schemaDefault ?? '(none)');
    }
    if ($mig['onUpdate'] !== $schema['onUpdate']) {
        $diffs[] = 'ON UPDATE: migration=' . ($mig['onUpdate'] ?? '(none)') . ' schema.sql=' . ($schema['onUpdate'] ?? '(none)');
    }
    $migComment = $mig['comment'];
    $schemaComment = $schema['comment'];
    if ($migComment !== $schemaComment) {
        $diffs[] = "COMMENT: migration=" . var_export($migComment, true) . ' schema.sql=' . var_export($schemaComment, true);
    }
    return $diffs;
}

function ddlCompareIndex(array $mig, array $schema): array
{
    $diffs = [];
    if ($mig['kind'] !== $schema['kind']) {
        $diffs[] = "kind: migration={$mig['kind']} schema.sql={$schema['kind']}";
    }
    if ($mig['columns'] !== $schema['columns']) {
        $diffs[] = 'columns: migration=(' . implode(',', $mig['columns']) . ') schema.sql=(' . implode(',', $schema['columns']) . ')';
    }
    return $diffs;
}

function ddlCompareFk(array $mig, array $schema): array
{
    $diffs = [];
    if ($mig['columns'] !== $schema['columns']) {
        $diffs[] = 'columns: migration=(' . implode(',', $mig['columns']) . ') schema.sql=(' . implode(',', $schema['columns']) . ')';
    }
    if ($mig['refTable'] !== $schema['refTable']) {
        $diffs[] = "REFERENCES table: migration={$mig['refTable']} schema.sql={$schema['refTable']}";
    }
    if ($mig['refColumns'] !== $schema['refColumns']) {
        $diffs[] = 'REFERENCES columns: migration=(' . implode(',', $mig['refColumns']) . ') schema.sql=(' . implode(',', $schema['refColumns']) . ')';
    }
    if (($mig['onDelete'] ?? null) !== ($schema['onDelete'] ?? null)) {
        $diffs[] = 'ON DELETE: migration=' . ($mig['onDelete'] ?? '(default RESTRICT)') . ' schema.sql=' . ($schema['onDelete'] ?? '(default RESTRICT)');
    }
    if (($mig['onUpdate'] ?? null) !== ($schema['onUpdate'] ?? null)) {
        $diffs[] = 'ON UPDATE: migration=' . ($mig['onUpdate'] ?? '(default RESTRICT)') . ' schema.sql=' . ($schema['onUpdate'] ?? '(default RESTRICT)');
    }
    return $diffs;
}

/* ==================================================================== *
 * Main
 * ==================================================================== */

$schemaContents = (string) file_get_contents($schemaFile);
$schemaTables   = ddlExtractCreateTables($schemaContents);
$schemaViews    = ddlExtractViewNames($schemaContents);

if (!$schemaTables) {
    fwrite(STDERR, "FATAL: parsed zero CREATE TABLE blocks out of schema.sql — parser broken or file shape changed.\n");
    exit(1);
}

$registryOrder = ddlReadRegistryOrder($registryFile);
if (!$registryOrder) {
    fwrite(STDERR, "FATAL: parsed zero 'script' => '...' entries out of migration-registry.php — parser broken or file shape changed.\n");
    exit(1);
}

$migrationFiles = glob($sqlDir . DIRECTORY_SEPARATOR . 'migrate-*.php') ?: [];
if (!$migrationFiles) {
    fwrite(STDERR, "FATAL: found zero migrate-*.php files under $sqlDir — scanner broken or path changed.\n");
    exit(1);
}

$transformFilesByTable = ddlScanTransformFiles($migrationFiles);

/**
 * Count-exact allowlist of pre-existing structural drift this test found on
 * introduction (#2077 follow-up) and is choosing to grandfather rather than
 * block on — see the file doc-block's "GRANDFATHERED PRE-EXISTING DRIFT"
 * section for the full reasoning and the two-directional check this drives.
 *
 * ELI5: this is a short, named list of "yes, we already know about this one,
 * it's on the To-Fix pile, don't fail the whole build over it" — but the
 * list can only ever get SHORTER. If the real number of drifts on a table
 * ever goes UP (even by one, even alongside the ones already here), the
 * build goes red. If it goes DOWN without this list being edited to match,
 * the build ALSO goes red — because that means the fix landed somewhere and
 * this stale entry is now lying about what's still broken.
 *
 * Detail: keyed by TABLE NAME (a migration file can create more than one
 * table, and the drift being tolerated is a property of the table's shape,
 * not of the file). Every entry was verified by hand against schema.sql
 * before being added here — none of these are "probably fine", each is a
 * specific, named difference (see 'why'). Three of the six are a single
 * missing `ON UPDATE CASCADE` on one FK each (harmless on a running install
 * — MySQL just won't cascade a SongId rename, which this app never does —
 * but still worth a follow-up ALTER so a fresh install and a migrated one
 * agree). tblLyricLineVocalParts/tblLyricWordVocalParts are in a migration
 * this task was explicitly told not to touch (a sibling piece of work
 * already has it open) — grandfathering, not fixing, is deliberate there.
 * (tblOrganisationLicences — the entry that WAS here and was genuinely
 * risky, a DATE-vs-DATETIME expiry that gates CCLI access — is resolved:
 * #2078's migrate-reconcile-organisation-licences-schema.php is a LATER,
 * data-aware ALTER that brings an already-migrated install's shape into
 * line with schema.sql's, so this test's own LATER-TRANSFORM-EXEMPT rule
 * now exempts the table entirely rather than comparing it — see that
 * script's doc-block for the full data-safety reasoning.)
 *
 * @var array<string, array{count:int, why:string}>
 */
$allow = [
    'tblSongAlternativeTitles' => [
        'count' => 1,
        'why'   => "migrate-alternative-titles.php's fk_alt_song FK omits ON UPDATE CASCADE; schema.sql's copy has it. Cosmetic on this app (SongId is never renamed in place) but a real DDL difference — needs its own follow-up ALTER, not a comment fix.",
    ],
    'tblSongExternalLinks' => [
        'count' => 1,
        'why'   => "migrate-external-links.php's fk_link_song FK omits ON UPDATE CASCADE; schema.sql's copy has it. Same shape and same reasoning as tblSongAlternativeTitles's fk_alt_song above.",
    ],
    'tblWorkSongs' => [
        'count' => 1,
        'why'   => "migrate-works.php's fk_work_song_song FK omits ON UPDATE CASCADE; schema.sql's copy has it. Same shape and same reasoning as tblSongAlternativeTitles's fk_alt_song above.",
    ],
    'tblSongArtists' => [
        'count' => 2,
        'why'   => "migrate-song-artists.php's SortOrder is SMALLINT (schema.sql: INT) and CreatedAt is DATETIME (schema.sql: TIMESTAMP). Both are real type drifts on a live install; narrowing/widening a column safely needs its own reviewed ALTER, not a mechanical comment fix.",
    ],
    'tblLyricLineVocalParts' => [
        'count' => 2,
        'why'   => 'migrate-vocal-parts.php is mid-flight work on this same branch (#2073) and was explicitly out of scope to edit here — its LyricsId and IsBackground COMMENT text is a few words shorter than schema.sql\'s. Leave it for that work to reconcile.',
    ],
    'tblLyricWordVocalParts' => [
        'count' => 1,
        'why'   => 'migrate-vocal-parts.php is mid-flight work on this same branch (#2073) and was explicitly out of scope to edit here — its LyricsId COMMENT text is a few words shorter than schema.sql\'s. Leave it for that work to reconcile.',
    ],
];

$failuresByTable = [];  // tableName => [diffLine, …] — every real diff, BEFORE the allowlist is applied
$exemptionsUsed  = [];  // table => [reason, …]  (informational, always printed)
$unparseableLog  = [];  // "file: table: segment"
$comparedCount   = 0;

foreach ($migrationFiles as $file) {
    $base = basename($file);
    $contents = @file_get_contents($file);
    if ($contents === false) { continue; }

    $migTables = ddlExtractCreateTables($contents);
    if (!$migTables) { continue; }

    $myPos = $registryOrder[$base] ?? null;

    foreach ($migTables as $tableName => $migParsed) {
        foreach ($migParsed['unparseable'] as $seg) {
            $unparseableLog[] = "$base: $tableName: " . substr($seg, 0, 80);
        }

        /* Exemption 1 — schema.sql only has this name as a compat VIEW. */
        if (isset($schemaViews[$tableName]) && !isset($schemaTables[$tableName])) {
            $exemptionsUsed[$tableName][] = "$base: schema.sql declares $tableName as a VIEW (compat back-name) — not structurally comparable to a CREATE TABLE";
            continue;
        }

        /* Exemption 3 — schema.sql has no entry at all (coverage test's job). */
        if (!isset($schemaTables[$tableName])) {
            continue;
        }

        /* Exemption 2 — some OTHER migration, registered LATER, restructures
           this table (rename column/index/table, or FK-rename shape). */
        $laterTransform = null;
        if (!empty($transformFilesByTable[$tableName])) {
            foreach ($transformFilesByTable[$tableName] as $transformFile) {
                if ($transformFile === $base) { continue; }
                $tPos = $registryOrder[$transformFile] ?? null;
                if ($tPos === null) {
                    /* Unknown position never grants an exemption — fail safe
                       toward still comparing strictly (see file doc-block). */
                    continue;
                }
                if ($myPos === null || $tPos > $myPos) {
                    $laterTransform = $transformFile;
                    break;
                }
            }
        }
        if ($laterTransform !== null) {
            $exemptionsUsed[$tableName][] = "$base: $laterTransform restructures $tableName afterward (registry order) — migration-time shape not held to schema.sql's current shape";
            continue;
        }

        $schemaParsed = $schemaTables[$tableName];
        $comparedCount++;

        foreach ($migParsed['columns'] as $colName => $migCol) {
            if (!isset($schemaParsed['columns'][$colName])) {
                $failuresByTable[$tableName][] = "$base: $tableName.$colName — column present in migration but no column of that name in schema.sql (renamed/removed without updating this migration?)";
                continue;
            }
            $diffs = ddlCompareColumn($migCol, $schemaParsed['columns'][$colName]);
            foreach ($diffs as $d) {
                $failuresByTable[$tableName][] = "$base: $tableName.$colName — $d";
            }
        }

        foreach ($migParsed['indexes'] as $idxName => $migIdx) {
            if ($idxName === null || str_starts_with((string) $idxName, '__unnamed_')) {
                continue; // PRIMARY KEY (Id) etc. — nothing named to key a comparison on
            }
            if (!isset($schemaParsed['indexes'][$idxName])) {
                $failuresByTable[$tableName][] = "$base: $tableName index $idxName — present in migration but no index of that name in schema.sql";
                continue;
            }
            $diffs = ddlCompareIndex($migIdx, $schemaParsed['indexes'][$idxName]);
            foreach ($diffs as $d) {
                $failuresByTable[$tableName][] = "$base: $tableName index $idxName — $d";
            }
        }

        foreach ($migParsed['constraints'] as $fkName => $migFk) {
            if (!isset($schemaParsed['constraints'][$fkName])) {
                $failuresByTable[$tableName][] = "$base: $tableName constraint $fkName — present in migration but no FK of that name in schema.sql";
                continue;
            }
            $diffs = ddlCompareFk($migFk, $schemaParsed['constraints'][$fkName]);
            foreach ($diffs as $d) {
                $failuresByTable[$tableName][] = "$base: $tableName constraint $fkName — $d";
            }
        }
    }
}

if ($comparedCount === 0) {
    fwrite(STDERR, "FATAL: compared zero tables end-to-end — scanner/exemption logic broken (found no non-exempt CREATE TABLE at all).\n");
    exit(1);
}

/* ------------------------------------------------------------------ *
 * Apply the count-exact allowlist ($allow, defined above) to turn the raw
 * per-table diff counts into the final pass/fail list. Two directions,
 * mirroring tests/php/test-fragment-inline-scripts.php exactly (see this
 * file's "GRANDFATHERED PRE-EXISTING DRIFT" doc-block section):
 *
 *   Direction 1 (regrown/new debt) — a table has MORE real diffs than its
 *   allowance (0 for a table with no entry at all). Only the diffs BEYOND
 *   the allowed count are reported as failures — the allowed ones are the
 *   ones we already know about and chose to grandfather.
 *
 *   Direction 2 (stale entry) — an allowlisted table has FEWER real diffs
 *   than it claims. That means the drift was already fixed somewhere and
 *   nobody shrank the list to match — which would otherwise let a brand
 *   new, unrelated diff on that same table hide inside the old entry's
 *   now-unused headroom. Reported as its own failure so the list can only
 *   ever get smaller, never silently grow slack.
 * ------------------------------------------------------------------ */
$failures          = [];
$grandfatheredUsed = [];   // table => count actually covered by the allowlist (for the summary line)

foreach ($failuresByTable as $tableName => $diffs) {
    $allowedCount = $allow[$tableName]['count'] ?? 0;
    if ($allowedCount > 0) {
        $grandfatheredUsed[$tableName] = min($allowedCount, count($diffs));
    }
    if (count($diffs) > $allowedCount) {
        $extra = array_slice($diffs, $allowedCount);
        foreach ($extra as $d) { $failures[] = $d; }
    }
}

foreach ($allow as $tableName => $meta) {
    $actual = count($failuresByTable[$tableName] ?? []);
    if ($actual < $meta['count']) {
        $failures[] = sprintf(
            '%s: allowlist claims %d grandfathered drift(s), found %d — the debt was paid, shrink/delete this stale $allow entry (%s)',
            $tableName,
            $meta['count'],
            $actual,
            $meta['why']
        );
    }
}

$exit = 0;

if ($unparseableLog) {
    fwrite(STDERR, "WARN: " . count($unparseableLog) . " clause(s) this test's parser could not classify (not counted as pass or fail):\n");
    foreach ($unparseableLog as $u) {
        fwrite(STDERR, "  - $u\n");
    }
    fwrite(STDERR, "\n");
}

if ($failures) {
    $exit = 1;
    fwrite(STDERR, "FAIL: " . count($failures) . " structural drift(s) between a migration's CREATE TABLE and schema.sql:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    fwrite(STDERR, "\n");
    fwrite(STDERR, "A migration's own CREATE TABLE must match schema.sql exactly (rule #19) UNLESS\n");
    fwrite(STDERR, "a later-registered migration is known to restructure that table afterward (see\n");
    fwrite(STDERR, "this file's EXEMPTIONS doc-block), or the table is a count-exact \$allow entry\n");
    fwrite(STDERR, "(see the GRANDFATHERED PRE-EXISTING DRIFT doc-block). If this failure is a\n");
    fwrite(STDERR, "genuine NEW drift, copy the column/index/FK definition from schema.sql into the\n");
    fwrite(STDERR, "migration verbatim; if it is already-known debt someone just paid off, adjust\n");
    fwrite(STDERR, "or remove the matching \$allow entry.\n");
}

echo ($exit === 0 ? "PASS" : "DONE") . ": compared $comparedCount table(s) end-to-end.\n";
if ($exemptionsUsed) {
    echo count($exemptionsUsed) . " table(s) exempted (later-transform or compat-view) — read before trusting a green run:\n";
    foreach ($exemptionsUsed as $tbl => $reasons) {
        foreach (array_unique($reasons) as $r) {
            echo "  - $r\n";
        }
    }
}
if ($grandfatheredUsed) {
    echo count($grandfatheredUsed) . " table(s) carry grandfathered pre-existing drift via the count-exact \$allow list — read before trusting a green run:\n";
    foreach ($grandfatheredUsed as $tbl => $n) {
        echo "  - $tbl: $n allowed drift(s) — {$allow[$tbl]['why']}\n";
    }
}

exit($exit);
