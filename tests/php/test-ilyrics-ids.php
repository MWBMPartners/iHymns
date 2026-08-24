<?php

declare(strict_types=1);

/**
 * iHymns — iLyrics Internal IDs (`IL*`) Phase 1 guard (#1860)
 * ============================================================================
 *
 * Guards the additive, idempotent, DORMANT Phase-1 infrastructure:
 *
 *   1. Format / parse / validity truth table for the allocator core
 *      (`includes/ilyrics_id.php`) — no separator, 10 zero-padded digits,
 *      map-derived character class.
 *   2. Registry map integrity — exactly 8 entities, `catalogue` never
 *      `collection` (rule #24), every table a real schema.sql table.
 *   3. schema.sql <-> migration DDL agreement (rule #19) — derived from the
 *      LIVE `IHYMNS_ILID_TYPES` map, not a third hand-typed copy: for each
 *      table, the IlId column's COMMENT text is extracted from schema.sql
 *      and checked for byte-identical presence in the migration (so editing
 *      either file alone breaks the match), plus width/key-presence checks.
 *   4. Migration hygiene — doctags present, CREATE+seed precede the first
 *      ALTER (partial-apply safety, §1.3), seeds from the map (not a
 *      hardcoded copy), and the rule-#41 include-resolution shape.
 *   5. Allocator structure — FOR UPDATE, the claim-check, the per-type
 *      UPDATE, the bounded attempt limit, no `=== false` result check.
 *   6. The rule-#27 `IL*` songbook-abbreviation reservation, exercised
 *      through the REAL `validateSongbookAbbr()`.
 *
 * Runs PURELY against the source tree — NO DATABASE CONNECTION anywhere in
 * this file (CI has none); `ilidAllocate()` itself is never called here,
 * only inspected as text (it needs a live \mysqli and a transaction, which
 * is out of scope for a schema-optional CI run — Phase 2 adds a two-connection
 * concurrency test where a DB is available, per the design doc §4).
 *
 * MUTATION PROOF (rule #34): every assertion below was written against the
 * mutation in the adjacent comment, run once during development to confirm
 * it goes RED, then restored to green. See the commit body for the list.
 *
 *   php tests/php/test-ilyrics-ids.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see .claude/ilyrics-internal-ids-work-model-plan.md  the design this Phase 1 slice implements
 * @see appWeb/public_html/includes/ilyrics_id.php        the allocator core under test
 * @see appWeb/.sql/migrate-ilyrics-internal-ids.php       the migration under test
 * @see #1860
 */

$repo = dirname(__DIR__, 2);

$ilidFile      = $repo . '/appWeb/public_html/includes/ilyrics_id.php';
$songbookFile  = $repo . '/appWeb/public_html/includes/songbook_validation.php';
$migrationFile = $repo . '/appWeb/.sql/migrate-ilyrics-internal-ids.php';
$schemaFile    = $repo . '/appWeb/.sql/schema.sql';

foreach ([$ilidFile, $songbookFile, $migrationFile, $schemaFile] as $f) {
    if (!is_readable($f)) {
        fwrite(STDERR, "FATAL: could not read $f\n");
        exit(1);
    }
}

/* $_SERVER['SCRIPT_FILENAME'] under CLI is THIS test file's own path, which
   never matches basename('ilyrics_id.php') or basename('songbook_validation.php')
   — so the two files' direct-access 403 guards are naturally inert here,
   exactly like every other test that requires a guarded includes/*.php file
   (e.g. test-schema-coverage.php requiring schema_audit.php). No stubbing
   needed. */
require_once $ilidFile;
require_once $songbookFile; // also requires ilyrics_id.php itself (require_once, idempotent)

$failures = 0;
$passed   = 0;

function ilidCheck(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/**
 * Strip PHP comments (// # /* * / doc-blocks) via the real tokenizer —
 * robust against a doc-block PROSE mention of a pattern this file's own
 * regexes look for (the rule #30/#38 lesson: a pattern mentioned only in a
 * comment must not satisfy an assertion). Whitespace-preserving (comment
 * bytes become spaces) so byte offsets used for ordering checks stay valid.
 */
function ilidStripPhpComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/** Whitespace-collapsed comparison helper for "same tokens, formatting may differ". */
function ilidCollapse(string $s): string
{
    return trim((string)preg_replace('/\s+/', ' ', $s));
}

/**
 * Extract the column/constraint body of `CREATE TABLE IF NOT EXISTS $table
 * ( … ) ENGINE=` from raw SQL/PHP-heredoc text — mirrors
 * includes/schema_audit.php's schemaAuditParseSchema() extraction shape.
 */
function ilidExtractTableBody(string $src, string $table): ?string
{
    if (preg_match(
        '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+' . preg_quote($table, '/') . '\s*\((.*?)\)\s*ENGINE\s*=/is',
        $src,
        $m
    )) {
        return $m[1];
    }
    return null;
}

$ilidRaw       = (string)file_get_contents($ilidFile);
$ilidStripped  = ilidStripPhpComments($ilidRaw);
$migRaw        = (string)file_get_contents($migrationFile);
$migStripped   = ilidStripPhpComments($migRaw);
$schemaSql     = (string)file_get_contents($schemaFile);

/* ==========================================================================
 * 1 — Format truth table.
 * Mutations: change str_pad length 10->9 in ilidFormat(); re-add a hyphen.
 * ========================================================================== */
ilidCheck(ilidFormat('song', 12345) === 'ILS0000012345', '1a ilidFormat(song,12345) === ILS0000012345');
ilidCheck(ilidFormat('document', 1) === 'ILD0000000001', '1b ilidFormat(document,1) === ILD0000000001');

$allValid = true;
foreach (array_keys(IHYMNS_ILID_TYPES) as $t) {
    if (!ilidIsValid(ilidFormat($t, 42))) {
        $allValid = false;
        fwrite(STDERR, "  (ilidFormat/$t produced an id ilidIsValid() rejects)\n");
    }
}
ilidCheck($allValid, '1c every map entry: ilidIsValid(ilidFormat($t, 42)) === true');

$threwOnZero = false;
try { ilidFormat('song', 0); } catch (\InvalidArgumentException $e) { $threwOnZero = true; }
ilidCheck($threwOnZero, '1d ilidFormat throws on n=0');

$threwOnOverflow = false;
try { ilidFormat('song', 10000000000); } catch (\InvalidArgumentException $e) { $threwOnOverflow = true; }
ilidCheck($threwOnOverflow, '1e ilidFormat throws on n=10000000000 (11 digits)');

/* ==========================================================================
 * 2 — Independent oracle (the ONE deliberately-typed-twice artefact, rule
 * #34: a map edit here is a conscious two-place change).
 * Mutations: loosen ilidPattern() to accept a hyphen; add a 9th map entry
 * without updating this oracle (must go RED).
 * ========================================================================== */
$oraclePattern = '/^IL[SWMTPCBD]\d{10}$/';
$oracleMap = [
    'song' => 'S', 'work' => 'W', 'musician' => 'M', 'tune' => 'T',
    'publisher' => 'P', 'catalogue' => 'C', 'songbook' => 'B', 'document' => 'D',
];

$oracleAgrees = true;
foreach (array_keys(IHYMNS_ILID_TYPES) as $t) {
    $id = ilidFormat($t, 42);
    if (!preg_match($oraclePattern, $id)) {
        $oracleAgrees = false;
        fwrite(STDERR, "  ($id from type '$t' does not match the independent oracle)\n");
    }
}
ilidCheck($oracleAgrees, '2a every formatted id matches the independent oracle /^IL[SWMTPCBD]\\d{10}$/');
ilidCheck(count(IHYMNS_ILID_TYPES) === count($oracleMap), '2b map size matches the oracle map size (conscious two-place change)');
ilidCheck(ilidIsValid('ILS-0000012345') === false, '2c ilidIsValid rejects a hyphenated id (collision-avoidance contract)');
ilidCheck(ilidIsValid('ILS000001234') === false, '2d ilidIsValid rejects 9 digits');
ilidCheck(ilidIsValid('ILS00000123456') === false, '2e ilidIsValid rejects 11 digits');
ilidCheck(ilidIsValid('ils0000012345') === false, '2f ilidIsValid rejects lowercase');
ilidCheck(ilidIsValid('ILX0000000001') === false, '2g ilidIsValid rejects an unknown prefix');
ilidCheck(ilidIsValid('MP-1008') === false, '2h ilidIsValid rejects a public SongId');

/* ==========================================================================
 * 3 — Parse.
 * Mutations: break the zero-pad in ilidParse(); drop the map-membership check.
 * ========================================================================== */
$p1 = ilidParse('ILS12345');
ilidCheck($p1 !== null && $p1['canonical'] === 'ILS0000012345', '3a ilidParse(ILS12345).canonical === ILS0000012345');
$p2 = ilidParse('ils0000012345');
ilidCheck($p2 !== null && $p2['entityType'] === 'song', '3b ilidParse(ils0000012345).entityType === song (case-insensitive)');
ilidCheck(ilidParse('MP-1008') === null, '3c ilidParse(MP-1008) === null (hyphen is the discriminator)');
ilidCheck(ilidParse('ILX123') === null, '3d ilidParse(ILX123) === null (unknown prefix)');

/* ==========================================================================
 * 4 — Map integrity.
 * Mutations: typo one table name in the map; rename catalogue->collection.
 * ========================================================================== */
ilidCheck(count(IHYMNS_ILID_TYPES) === 8, '4a exactly 8 entity types in IHYMNS_ILID_TYPES');
ilidCheck(array_key_exists('catalogue', IHYMNS_ILID_TYPES), '4b map key "catalogue" present (rule #24)');
ilidCheck(!array_key_exists('collection', IHYMNS_ILID_TYPES), '4c map key "collection" ABSENT (rule #24 — UI copy only)');

$prefixes = array_column(IHYMNS_ILID_TYPES, 'prefix');
$allPrefixesShaped = true;
foreach ($prefixes as $pfx) {
    if (!preg_match('/^IL[A-Z]$/', $pfx)) {
        $allPrefixesShaped = false;
    }
}
ilidCheck($allPrefixesShaped, '4d every prefix is 3 chars starting IL');
ilidCheck(count($prefixes) === count(array_unique($prefixes)), '4e every prefix is distinct');

$allTablesInSchema = true;
foreach (IHYMNS_ILID_TYPES as $entityType => $def) {
    $needle = 'CREATE TABLE IF NOT EXISTS ' . $def['table'] . ' (';
    if (!str_contains($schemaSql, $needle)) {
        $allTablesInSchema = false;
        fwrite(STDERR, "  (schema.sql has no '$needle' for entity type '$entityType')\n");
    }
}
ilidCheck($allTablesInSchema, '4f every map table is a real CREATE TABLE in schema.sql (tree-derived)');

/* ==========================================================================
 * 5 — DDL agreement (rule #19), derived from schema.sql itself — NOT a
 * third hand-typed copy of the comment template. For each table: extract
 * the IlId column's COMMENT text from schema.sql, then check that EXACT
 * byte string also appears in the migration (so editing either file alone
 * breaks agreement).
 * Mutations: edit one COMMENT in schema.sql only; delete one table's
 * uq_IlId from schema.sql; change the schema.sql width to VARCHAR(13) in
 * one table.
 * ========================================================================== */
ilidCheck(
    str_contains($migStripped, 'ADD COLUMN IlId VARCHAR(16) NULL DEFAULT NULL'),
    '5a migration contains the shared ADD COLUMN IlId VARCHAR(16) NULL DEFAULT NULL clause'
);
ilidCheck(
    str_contains($migStripped, 'ADD UNIQUE KEY uq_IlId (IlId)'),
    '5b migration contains ADD UNIQUE KEY uq_IlId (IlId)'
);

$perTableDdlOk = true;
foreach (IHYMNS_ILID_TYPES as $entityType => $def) {
    $table = $def['table'];

    $schemaBody = ilidExtractTableBody($schemaSql, $table);
    if ($schemaBody === null) {
        $perTableDdlOk = false;
        fwrite(STDERR, "  ($table: could not locate its CREATE TABLE block in schema.sql)\n");
        continue;
    }

    if (!preg_match(
        "/\\bIlId\\s+VARCHAR\\(16\\)\\s+NULL DEFAULT NULL COMMENT '([^']*)'/",
        $schemaBody,
        $cm
    )) {
        $perTableDdlOk = false;
        fwrite(STDERR, "  ($table: no 'IlId VARCHAR(16) NULL DEFAULT NULL COMMENT ...' line found in schema.sql — width/shape drifted?)\n");
        continue;
    }
    $schemaComment = $cm[1];

    if (!preg_match('/UNIQUE\s+KEY\s+uq_IlId\s*\(IlId\)/', $schemaBody)) {
        $perTableDdlOk = false;
        fwrite(STDERR, "  ($table: no 'UNIQUE KEY uq_IlId (IlId)' in schema.sql)\n");
        continue;
    }

    if (!str_contains($migStripped, $schemaComment)) {
        $perTableDdlOk = false;
        fwrite(STDERR, "  ($table: schema.sql's IlId COMMENT text is not byte-identical in the migration)\n");
        continue;
    }

    /* Sanity: the schema.sql comment should itself carry this table's
       specific prefix (e.g. ILS for tblSongs) so a copy-paste that reused
       the wrong table's comment is also caught. */
    if (!str_contains($schemaComment, $def['prefix'] . '0000012345')) {
        $perTableDdlOk = false;
        fwrite(STDERR, "  ($table: schema.sql COMMENT does not carry its own prefix '{$def['prefix']}')\n");
    }
}
ilidCheck($perTableDdlOk, '5c per-table: schema.sql IlId COMMENT is byte-identical in the migration, uq_IlId present, correct prefix');

/* tblIlyricsIdSequence column-definition body, whitespace-collapsed. */
$migSeqBody    = ilidExtractTableBody($migStripped, 'tblIlyricsIdSequence');
$schemaSeqBody = ilidExtractTableBody($schemaSql, 'tblIlyricsIdSequence');
ilidCheck(
    $migSeqBody !== null && $schemaSeqBody !== null && ilidCollapse($migSeqBody) === ilidCollapse($schemaSeqBody),
    '5d tblIlyricsIdSequence column definitions are whitespace-collapsed identical between migration and schema.sql'
);

/* ==========================================================================
 * 6 — Migration hygiene.
 * Mutations: delete a doctag; move the seed block below the ALTERs;
 * hardcode the seed pairs.
 * ========================================================================== */
$doctagsOk = true;
foreach (IHYMNS_ILID_TYPES as $def) {
    if (!str_contains($migRaw, '@migration-adds ' . $def['table'] . '.IlId')) {
        $doctagsOk = false;
        fwrite(STDERR, "  (missing '@migration-adds {$def['table']}.IlId' doctag)\n");
    }
}
ilidCheck($doctagsOk, '6a all 8 @migration-adds tblX.IlId doctags present');

$createPos = strpos($migRaw, 'CREATE TABLE IF NOT EXISTS tblIlyricsIdSequence');
$seedPos   = strpos($migRaw, 'INSERT INTO tblIlyricsIdSequence');
$alterPos  = strpos($migStripped, 'ALTER TABLE');
ilidCheck(
    $createPos !== false && $seedPos !== false && $alterPos !== false
        && $createPos < $alterPos && $seedPos < $alterPos,
    '6b CREATE + seed INSERT both precede the first ALTER TABLE (partial-apply safety)'
);

/* Scoped to the SEED loop's own structure (not a loose file-wide substring
   check, which would stay green even if the seed step were swapped for a
   hardcoded pairs array, since IHYMNS_ILID_TYPES is legitimately still
   referenced elsewhere — the ALTER loop below also iterates it): the
   foreach header must be IHYMNS_ILID_TYPES AND its very next statement
   must be the seed statement's bind_param call. */
ilidCheck(
    (bool)preg_match(
        '/foreach\s*\(\s*IHYMNS_ILID_TYPES\s+as\s+\$entityType\s*=>\s*\$def\s*\)\s*\{\s*\$seed\s*->\s*bind_param/',
        $migStripped
    ),
    '6c the seed loop iterates IHYMNS_ILID_TYPES directly, not a hardcoded copy'
);

/* Rule #41 shape: no unconditional (column-0) require/include of a
   hardcoded '/public_html/' literal — mirrors test-deploy-paths.php Pass B,
   scoped to just this one file as a belt-and-braces check. */
$badRequire = false;
foreach (explode("\n", $migStripped) as $line) {
    $t = ltrim($line);
    if ($t === '') { continue; }
    if (!preg_match('/^(require|include)(_once)?\b/', $t)) { continue; }
    if (preg_match('/dirname\s*\(\s*__DIR__/', $t) && preg_match('#[\'"][^\'"]*/public_html/#', $t)) {
        $badRequire = true;
    }
}
ilidCheck(!$badRequire, '6d no unconditional column-0 require of a hardcoded /public_html/ literal (rule #41)');

/* ==========================================================================
 * 7 — Allocator structure (comment-stripped source of ilyrics_id.php).
 * Mutations: drop FOR UPDATE; delete the claim-check; make the UPDATE
 * un-scoped (global counter).
 * ========================================================================== */
ilidCheck(
    (bool)preg_match(
        '/SELECT\s+NextValue\s+FROM\s+tblIlyricsIdSequence\s+WHERE\s+EntityType\s*=\s*\?\s+FOR\s+UPDATE/',
        $ilidStripped
    ),
    '7a the sequence SELECT carries FOR UPDATE'
);
ilidCheck(
    (bool)preg_match('/SELECT\s+1\s+FROM\s+\S+\s+WHERE\s+IlId\s*=\s*\?/', $ilidStripped),
    '7b a claim-check "SELECT 1 FROM … WHERE IlId = ?" statement is prepared'
);
/* Preparing the statement isn't enough on its own — it must actually be
   EXECUTED (catches a mutation that guts the bind/execute/fetch lines but
   leaves the now-unreachable prepare() text sitting above the loop, which
   the 7b text-presence check alone cannot see). */
ilidCheck(
    (bool)preg_match('/\$claimStmt\s*->\s*execute\s*\(\s*\)/', $ilidStripped),
    '7b2 the claim-check statement is actually executed ($claimStmt->execute())'
);
ilidCheck(
    (bool)preg_match(
        '/UPDATE\s+tblIlyricsIdSequence\s+SET\s+NextValue\s*=\s*\?\s+WHERE\s+EntityType\s*=\s*\?/',
        $ilidStripped
    ),
    '7c the UPDATE targets WHERE EntityType = ? (per-type counters)'
);
ilidCheck(
    (bool)preg_match('/for\s*\(\s*\$attempt\s*=\s*0\s*;\s*\$attempt\s*<\s*8\s*;/', $ilidStripped),
    '7d a bounded attempt limit of 8 exists'
);
ilidCheck(!str_contains($ilidStripped, '=== false'), '7e no "=== false" check on a query/prepare result');

/* Claim-check runs BEFORE the return: the "SELECT 1 FROM … WHERE IlId" probe
   must appear before the function's terminal "return $candidate;". */
$allocBody = null;
if (preg_match('/function\s+ilidAllocate\s*\([^)]*\)\s*:\s*string\s*\{(.*)\}\s*$/s', trim($ilidStripped), $am)) {
    $allocBody = $am[1];
}
$claimBeforeReturn = false;
if ($allocBody !== null) {
    /* Anchor on the EXECUTE, not the prepare — the prepare() text sits
       above the loop unconditionally, so it is always "before" the return
       even in a mutant that guts the execute/fetch and never really
       claim-checks anything (see 7b2's comment). */
    $execPos   = strpos($allocBody, '$claimStmt->execute()');
    $returnPos = strpos($allocBody, 'return $candidate;');
    $claimBeforeReturn = ($execPos !== false && $returnPos !== false && $execPos < $returnPos);
}
ilidCheck($claimBeforeReturn, '7f the claim-check EXECUTE runs before "return $candidate;"');

/* ==========================================================================
 * 8 — Reservation (functional, calls the REAL validator).
 * Mutations: remove the ilidAbbrIsReserved() call from the validator;
 * widen the pattern to ^IL (red via ILSONGS).
 * ========================================================================== */
ilidCheck(validateSongbookAbbr('ILS') !== null, '8a validateSongbookAbbr(ILS) is rejected');
ilidCheck(validateSongbookAbbr('ilw') !== null, '8b validateSongbookAbbr(ilw) is rejected (case-insensitive)');
ilidCheck(validateSongbookAbbr('IL') !== null, '8c validateSongbookAbbr(IL) is rejected');
ilidCheck(validateSongbookAbbr('ILB') !== null, '8d validateSongbookAbbr(ILB) is rejected');
ilidCheck(validateSongbookAbbr('ILSONGS') === null, '8e validateSongbookAbbr(ILSONGS) is ALLOWED (narrowness guard)');
ilidCheck(validateSongbookAbbr('MP') === null, '8f validateSongbookAbbr(MP) is ALLOWED (unrelated abbreviation)');
ilidCheck(validateSongbookAbbr('CH1') === null, '8g validateSongbookAbbr(CH1) is ALLOWED (unrelated abbreviation)');

/* ========================================================================== */

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures assertion(s) failed, $passed passed.\n");
    exit(1);
}
echo "All $passed iLyrics-internal-id assertions passed.\n";
exit(0);
