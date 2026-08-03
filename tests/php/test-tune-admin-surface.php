<?php

declare(strict_types=1);

/**
 * iHymns — Tune admin CRUD surface guard (#1748)
 *
 * ELI5
 * ----
 * #1748 built `/manage/tunes` + four `admin_tune_*` API actions on top of a
 * shared core (`includes/tune_admin.php`) and a centralised credit-role
 * vocabulary (`IHYMNS_TUNE_CREDIT_ROLES`, `includes/tune_helpers.php`).
 * This file makes sure those promises hold: the role vocabulary the schema
 * comment names is EXACTLY the const's keys (D1), both consumer files
 * reference the const rather than re-inlining the list (D2), the page
 * gates before any output and uses the same-origin CSRF check (not the
 * stale-token one), the external-links whitelist + entity-type const
 * were actually widened, the four documented API actions exist and are
 * gated, and the nav row advertises the right entitlement.
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * ----------------------------------------------------------------------------
 * D1's role list is sliced straight out of `schema.sql`'s
 * `tblTuneCredits.Role` COMMENT — never hand-typed here — so a future role
 * added to the schema without updating `IHYMNS_TUNE_CREDIT_ROLES` (or vice
 * versa) fails this test automatically. The four API-action names checked
 * in point assertion 3 are derived by scanning `api-docs.yaml` for
 * `admin_tune_*` action enums, so documenting a fifth action later forces
 * implementing its gate check here too, with zero maintenance on this file.
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely in
 * memory, never touching the tree, mirroring test-tune-lockstep.php /
 * test-work-identity-fields.php): every core checking function below is
 * exercised in BOTH directions — proven to detect a thing that's there, and
 * proven to detect a thing that's missing — against small in-memory
 * fixtures, not the real files.
 *
 * Pure source-tree scan + a behavioural require of `tune_helpers.php` (no
 * DB) — slots into the CI lint step alongside `php -l` and the other
 * guards.
 *
 *   php tests/php/test-tune-admin-surface.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see appWeb/public_html/manage/tunes.php
 * @see appWeb/public_html/includes/tune_admin.php
 * @see appWeb/public_html/includes/tune_helpers.php               IHYMNS_TUNE_CREDIT_ROLES
 * @see appWeb/public_html/includes/external_link_helpers.php      IHYMNS_LINK_ENTITY_TYPES, the whitelist widening
 * @see appWeb/public_html/manage/includes/admin-links.php         the 'tunes' nav row
 * @see appWeb/public_html/api.php                                  admin_tune_add/update/merge/delete
 * @see tests/php/test-tune-lockstep.php                            the sibling this file's slicer/stripper cores are copied from ('tas' prefix)
 * @see .claude/catalogue-1741-1748-plan.md §6
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

/* =========================================================================
 * PURE CORE FUNCTIONS — no file I/O, no globals. Copied from
 * test-tune-lockstep.php's ttl* cores (same shapes, 'tas' prefix — test
 * files are standalone scripts, prefixes must not collide).
 * ========================================================================= */

/**
 * Slice a named top-level PHP `function NAME(...) { ... }` region — from
 * `function NAME(` to the next top-level (column-0) `function ` declaration,
 * or end of file.
 */
function tasSliceFunction(string $src, string $name): string
{
    if (!preg_match(
        '/^function\s+' . preg_quote($name, '/') . '\s*\(.*?(?=\n^function\s|\z)/ms',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Slice a `case 'NAME':` region out of a PHP switch statement — from the
 * `case` line to the next top-level `case '` occurrence (or end of file).
 */
function tasSliceCase(string $src, string $caseName): string
{
    if (!preg_match(
        '/case\s+\'' . preg_quote($caseName, '/') . '\'\s*:.*?(?=\n\s*case\s+\')/s',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Strip PHP comments (`//`, `#`, and `/* *​/` / doc-block) from source via
 * the TOKENIZER, so a CODE assertion below can never be satisfied by a
 * mention of the symbol inside a comment — the wrong-but-green trap
 * (rule #34). Load-bearing here for the same reason as
 * test-tune-lockstep.php's identical function: this file's OWN inline
 * annotations deliberately name `userHasEntitlement`, `validateCsrfRequest`,
 * etc. right beside the assertions that check for them.
 */
function tasStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/**
 * Keep ONLY executable PHP — drop PHP comments AND all T_INLINE_HTML (the
 * template's raw HTML + any <script> JS body outside `<?php … ?>`).
 *
 * WHY (rule #34 — the wrong-but-green the #1748 adversarial audit found):
 * `manage/tunes.php` is a full HTML document whose trailing <script> block
 * carries a JS `/* … *​/` comment that also names `IHYMNS_TUNE_CREDIT_ROLES`
 * (and could name any function). JS/HTML comments are NOT PHP `T_COMMENT`s —
 * `tasStripComments()` passes T_INLINE_HTML through verbatim, so a single
 * decoy line in that <script> kept THREE separate "does the PHP reference X"
 * assertions green at once while the real PHP call sites were renamed away.
 * For every "the PHP code references / does not fork X" check, scan THIS
 * projection instead: a `<?= json_encode(IHYMNS_TUNE_CREDIT_ROLES) ?>` echo
 * survives (it tokenizes as PHP), but a JS comment or HTML text mentioning the
 * symbol cannot satisfy the check. (Ordering checks that need `<!DOCTYPE`
 * itself must still use tasStripComments(), which keeps the HTML.)
 *
 * @link https://www.php.net/manual/en/tokens.php  T_INLINE_HTML
 */
function tasPhpCodeOnly(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT || $tok[0] === T_INLINE_HTML) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/**
 * Slice a `CREATE TABLE ... tblName ( ... )` block out of raw schema.sql
 * text — from the `CREATE TABLE` line naming $table to the matching
 * `) ENGINE=` close. Same shape as test-tune-registry-page.php's
 * trpgSliceCreateTable().
 *
 * @return string|null null when the table isn't found.
 */
function tasSliceCreateTable(string $schemaSql, string $table): ?string
{
    if (!preg_match(
        '/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+' . preg_quote($table, '/') . '\s*\((.*?)\)\s*ENGINE\s*=/is',
        $schemaSql,
        $m
    )) {
        return null;
    }
    return $m[1];
}

/**
 * The pipe-separated vocabulary named in a column's own `COMMENT '...'`
 * text, e.g. `Role VARCHAR(20) NOT NULL COMMENT 'composer | arranger |
 * harmoniser | source (app-validated; VARCHAR not ENUM, rule #20)'` ->
 * `['composer', 'arranger', 'harmoniser', 'source']`. Text after the first
 * `(` is dropped (the parenthetical explanation, not part of the
 * vocabulary itself). Same shape as test-tune-registry-page.php's
 * trpgPipeVocabForColumn().
 *
 * @return list<string>
 */
function tasPipeVocabForColumn(string $block, string $column): array
{
    if (!preg_match(
        '/\b' . preg_quote($column, '/') . '\s+[A-Za-z].*?COMMENT\s+\'([^\']*)\'/is',
        $block,
        $m
    )) {
        return [];
    }
    $comment  = $m[1];
    $parenPos = strpos($comment, '(');
    $pipePart = $parenPos !== false ? substr($comment, 0, $parenPos) : $comment;
    $parts    = array_map('trim', explode('|', $pipePart));
    return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
}

/**
 * Scan `api-docs.yaml` text for `enum: [<prefix>...]` action-name
 * declarations and return the distinct list, sorted. Used to derive the
 * four `admin_tune_*` action names for point assertion 3, so documenting a
 * fifth one later is caught here automatically (rule #34).
 *
 * @return list<string>
 */
function tasApiDocsActionsWithPrefix(string $yamlSrc, string $prefix): array
{
    if (!preg_match_all(
        '/enum:\s*\[\s*(' . preg_quote($prefix, '/') . '[a-z0-9_]*)\s*\]/i',
        $yamlSrc,
        $m
    )) {
        return [];
    }
    $names = array_values(array_unique($m[1]));
    sort($names);
    return $names;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory, before
 * trusting the real assertions below.
 * ========================================================================= */

$mutationFailures = [];

/* --- tasSliceFunction() --- */
$fixtureFnSrc = "function alpha(): array\n{\n    \$cols = ['NeedleInAlpha'];\n    return \$cols;\n}\n\nfunction beta(): array\n{\n    \$cols = ['NeedleInBeta'];\n    return \$cols;\n}\n";
$alphaFnSlice = tasSliceFunction($fixtureFnSrc, 'alpha');
if (strpos($alphaFnSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'tasSliceFunction() FAILS-HIGH self-test did not find the needle inside its own fixture function';
}
if (strpos($alphaFnSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "tasSliceFunction() FAILS-LOW self-test: alpha()'s slice bled into beta()";
}

/* --- tasSliceCase() --- */
$fixtureSwitchSrc = "switch (\$action) {\n    case 'alpha': {\n        \$x = 'NeedleInAlpha';\n        break;\n    }\n    case 'beta': {\n        \$x = 'NeedleInBeta';\n        break;\n    }\n}\n";
$alphaCaseSlice = tasSliceCase($fixtureSwitchSrc, 'alpha');
if (strpos($alphaCaseSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'tasSliceCase() FAILS-HIGH self-test did not find the needle inside its own fixture case';
}
if (strpos($alphaCaseSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "tasSliceCase() FAILS-LOW self-test: 'alpha' case slice bled into 'beta' case";
}

/* --- tasStripComments(): a code literal survives, a comment-only mention
   of the SAME literal is blanked. --- */
$stripFixture = "<?php\n// NeedleInLineComment\n\$x = 'NeedleInCode';\n# NeedleInHashComment\n/* NeedleInBlockComment */\n/** NeedleInDocComment */\n\$y = \"AlsoCode\";\n";
$stripped = tasStripComments($stripFixture);
foreach (['NeedleInCode', 'AlsoCode'] as $codeNeedle) {
    if (strpos($stripped, $codeNeedle) === false) {
        $mutationFailures[] = "tasStripComments() FAILS-HIGH self-test removed the code string '{$codeNeedle}'";
    }
}
foreach (['NeedleInLineComment', 'NeedleInHashComment', 'NeedleInBlockComment', 'NeedleInDocComment'] as $commentNeedle) {
    if (strpos($stripped, $commentNeedle) !== false) {
        $mutationFailures[] = "tasStripComments() FAILS-LOW self-test kept the comment string '{$commentNeedle}'";
    }
}

/* --- tasPhpCodeOnly(): a PHP-code needle (incl. inside a `<?= … ?>` echo)
   survives; a needle living ONLY in inline HTML / a <script> JS comment is
   blanked — the T_INLINE_HTML decoy the #1748 audit found. --- */
$phpOnlyFixture = "<?php \$real = 'NeedleInPhp'; ?>\n"
                . "<div>NeedleInHtmlText</div>\n"
                . "<script>/* NeedleInJsComment mentions IHYMNS_TUNE_CREDIT_ROLES */\nvar x = <?= json_encode('NeedleInEchoPhp') ?>;</script>\n";
$phpOnly = tasPhpCodeOnly($phpOnlyFixture);
foreach (['NeedleInPhp', 'NeedleInEchoPhp'] as $keep) {
    if (strpos($phpOnly, $keep) === false) {
        $mutationFailures[] = "tasPhpCodeOnly() FAILS-HIGH self-test dropped PHP-code string '{$keep}'";
    }
}
foreach (['NeedleInHtmlText', 'NeedleInJsComment'] as $drop) {
    if (strpos($phpOnly, $drop) !== false) {
        $mutationFailures[] = "tasPhpCodeOnly() FAILS-LOW self-test kept inline-HTML/JS-comment string '{$drop}' (the wrong-but-green decoy this projection exists to close)";
    }
}

/* --- tasSliceCreateTable(): finds a fixture table, null for absent. --- */
$fixtureSchema = "CREATE TABLE IF NOT EXISTS tblFixtureProbe (\n    Id INT,\n    Role VARCHAR(20) COMMENT 'alpha | beta'\n) ENGINE=InnoDB;\n";
if (tasSliceCreateTable($fixtureSchema, 'tblFixtureProbe') === null) {
    $mutationFailures[] = 'tasSliceCreateTable() FAILS-HIGH self-test did not find the fixture table';
}
if (tasSliceCreateTable($fixtureSchema, 'tblDoesNotExist') !== null) {
    $mutationFailures[] = 'tasSliceCreateTable() FAILS-LOW self-test wrongly matched a non-existent table';
}

/* --- tasPipeVocabForColumn(): extracts the pipe list, drops the
   parenthetical, ignores an unrelated column. --- */
$fixtureRoleBlock = "    Role VARCHAR(20) NOT NULL COMMENT 'alpha | beta | gamma (app-validated; some note)',\n"
                  . "    Other VARCHAR(20) NOT NULL COMMENT 'delta | epsilon',\n";
$fixtureVocab = tasPipeVocabForColumn($fixtureRoleBlock, 'Role');
if ($fixtureVocab !== ['alpha', 'beta', 'gamma']) {
    $mutationFailures[] = 'tasPipeVocabForColumn() FAILS-HIGH self-test did not extract the exact fixture vocabulary (got: ' . implode(',', $fixtureVocab) . ')';
}
if (in_array('delta', $fixtureVocab, true) || in_array('epsilon', $fixtureVocab, true)) {
    $mutationFailures[] = "tasPipeVocabForColumn() FAILS-LOW self-test bled into the unrelated 'Other' column's vocabulary";
}

/* --- tasApiDocsActionsWithPrefix(): finds prefixed enums, ignores others. --- */
$fixtureYaml = "        - name: action\n          schema: { type: string, enum: [admin_tune_add] }\n"
             . "        - name: action\n          schema: { type: string, enum: [admin_tune_delete] }\n"
             . "        - name: action\n          schema: { type: string, enum: [admin_musician_add] }\n";
$fixtureActions = tasApiDocsActionsWithPrefix($fixtureYaml, 'admin_tune_');
if ($fixtureActions !== ['admin_tune_add', 'admin_tune_delete']) {
    $mutationFailures[] = 'tasApiDocsActionsWithPrefix() FAILS-HIGH/exact-set self-test did not return the exact expected set (got: ' . implode(',', $fixtureActions) . ')';
}
if (in_array('admin_musician_add', $fixtureActions, true)) {
    $mutationFailures[] = 'tasApiDocsActionsWithPrefix() FAILS-LOW self-test wrongly matched an unrelated-prefix action';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

$failures = [];

/* ---- Load real files. ---- */
$schemaFile   = $repoRoot . '/appWeb/.sql/schema.sql';
$helpersFile  = $pub . '/includes/tune_helpers.php';
$tunesPageFile = $pub . '/manage/tunes.php';
$tuneAdminFile = $pub . '/includes/tune_admin.php';
$tunePubFile   = $pub . '/includes/pages/tune.php';
$extLinkHelpersFile = $pub . '/includes/external_link_helpers.php';
$adminLinksFile     = $pub . '/manage/includes/admin-links.php';
$apiFile            = $pub . '/api.php';
$apiDocsFile        = $pub . '/api-docs.yaml';

foreach ([
    'schema.sql' => $schemaFile, 'tune_helpers.php' => $helpersFile,
    'manage/tunes.php' => $tunesPageFile, 'includes/tune_admin.php' => $tuneAdminFile,
    'includes/pages/tune.php' => $tunePubFile,
    'external_link_helpers.php' => $extLinkHelpersFile, 'admin-links.php' => $adminLinksFile,
    'api.php' => $apiFile, 'api-docs.yaml' => $apiDocsFile,
] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read {$label} at {$path}\n");
        exit(1);
    }
}

$schemaSql       = (string)file_get_contents($schemaFile);
$tunesPageSrc    = (string)file_get_contents($tunesPageFile);
$tunesPageCode   = tasStripComments($tunesPageSrc);   /* keeps HTML (needed for the <!DOCTYPE ordering check) */
$tunesPagePhp    = tasPhpCodeOnly($tunesPageSrc);      /* PHP only — for "does the PHP reference X" checks (kills the JS-comment decoy) */
$tuneAdminSrc    = (string)file_get_contents($tuneAdminFile);
$tuneAdminCode   = tasStripComments($tuneAdminSrc);    /* pure PHP file; comment-stripped is sufficient */
$tunePubSrc      = (string)file_get_contents($tunePubFile);
$tunePubCode     = tasStripComments($tunePubSrc);
$tunePubPhp      = tasPhpCodeOnly($tunePubSrc);         /* tune.php is also a template (JSON-LD etc.) */
$extLinkSrc      = (string)file_get_contents($extLinkHelpersFile);
$extLinkCode     = tasStripComments($extLinkSrc);
$adminLinksSrc   = (string)file_get_contents($adminLinksFile);
$adminLinksCode  = tasStripComments($adminLinksSrc);
$apiSrc          = (string)file_get_contents($apiFile);
$apiCode         = tasStripComments($apiSrc);
$apiDocsSrc      = (string)file_get_contents($apiDocsFile);

/* ---- D1 — role vocabulary from the schema, behaviourally against the
   const (require tune_helpers.php and ASK it, not grep it — the
   test-entitlement-parity.php posture). ---- */
$tuneCreditsBlock = tasSliceCreateTable($schemaSql, 'tblTuneCredits');
if ($tuneCreditsBlock === null) {
    fwrite(STDERR, "FATAL: schema.sql has no tblTuneCredits CREATE TABLE block\n");
    exit(1);
}
$schemaRoleVocab = tasPipeVocabForColumn($tuneCreditsBlock, 'Role');
if (!$schemaRoleVocab) {
    fwrite(STDERR, "FATAL: could not extract tblTuneCredits.Role's COMMENT vocabulary — schema.sql parsing is likely broken\n");
    exit(1);
}

require_once $helpersFile;
if (!defined('IHYMNS_TUNE_CREDIT_ROLES')) {
    $failures[] = 'includes/tune_helpers.php does not define IHYMNS_TUNE_CREDIT_ROLES';
} else {
    $constKeys = array_keys(IHYMNS_TUNE_CREDIT_ROLES);
    if ($constKeys !== $schemaRoleVocab) {
        $failures[] = 'IHYMNS_TUNE_CREDIT_ROLES keys (' . implode(', ', $constKeys) . ') do not exactly '
            . 'match tblTuneCredits.Role\'s schema.sql COMMENT vocabulary (' . implode(', ', $schemaRoleVocab) . ')';
    }
}

/* ---- D2 — both consumers reference the const; neither re-inlines the
   raw 'composer', 'arranger' sequence (the regression this const fixed
   in includes/pages/tune.php, and the shape a future manage/tunes.php
   edit could reintroduce). ---- */
/* PHP-code-only projections (tasPhpCodeOnly): a JS `/* … *​/` decoy in
   tunes.php's <script> block naming the const must NOT satisfy the reference
   check, and the real reference is a `<?= json_encode(IHYMNS_TUNE_CREDIT_ROLES) ?>`
   echo (PHP), which survives the projection. */
foreach (['includes/pages/tune.php' => $tunePubPhp, 'manage/tunes.php' => $tunesPagePhp] as $label => $code) {
    if (strpos($code, 'IHYMNS_TUNE_CREDIT_ROLES') === false) {
        $failures[] = "{$label} does not reference IHYMNS_TUNE_CREDIT_ROLES (in executable PHP — a comment/JS mention does not count)";
    }
    if (strpos($code, "'composer', 'arranger'") !== false || strpos($code, "'composer','arranger'") !== false) {
        $failures[] = "{$label} appears to re-inline the raw 'composer', 'arranger' role sequence instead of using IHYMNS_TUNE_CREDIT_ROLES";
    }
}

/* ---- Point assertion 1 — manage/tunes.php. ---- */

/* Gate-before-DOCTYPE ordering: userHasEntitlement('manage_tunes' must
   appear at a BYTE OFFSET before the first <!DOCTYPE in the STRIPPED
   source (comments can't fake either side of this comparison). */
$gateNeedle = "userHasEntitlement('manage_tunes'";
$gatePos    = strpos($tunesPageCode, $gateNeedle);
$doctypePos = stripos($tunesPageCode, '<!DOCTYPE');
if ($gatePos === false) {
    $failures[] = "manage/tunes.php does not call {$gateNeedle}";
} elseif ($doctypePos === false) {
    $failures[] = 'manage/tunes.php has no <!DOCTYPE — cannot verify gate-before-output ordering';
} elseif ($gatePos > $doctypePos) {
    $failures[] = 'manage/tunes.php calls userHasEntitlement(\'manage_tunes\' AFTER <!DOCTYPE — the gate must run before any output';
}

if (strpos($tunesPageCode, 'validateCsrfRequest(') === false) {
    $failures[] = 'manage/tunes.php does not call validateCsrfRequest( — state-changing POSTs must use the same-origin check, not the stale-token one (rule #29)';
}
/* Bare validateCsrf( (non-Request) must be ABSENT — every occurrence of
   the substring 'validateCsrf(' must be part of 'validateCsrfRequest('. */
$bareCsrfPos = 0;
$sawBareCsrf = false;
while (($bareCsrfPos = strpos($tunesPageCode, 'validateCsrf(', $bareCsrfPos)) !== false) {
    if (strpos($tunesPageCode, 'validateCsrfRequest(', $bareCsrfPos) !== $bareCsrfPos) {
        $sawBareCsrf = true;
        break;
    }
    $bareCsrfPos += strlen('validateCsrf(');
}
if ($sawBareCsrf) {
    $failures[] = 'manage/tunes.php calls the bare (non-Request) validateCsrf( — a long-lived admin page must use validateCsrfRequest() (rule #29)';
}

/* tuneFindOrCreateByName — kept explicit via tuneAdminCreate(), the
   shared includes/tune_admin.php wrapper that pairs the funnel with the
   scalar-fields persist (see that function's own doc-block for why this
   is what keeps test-tune-lockstep.php's derived sweep green for
   tune_admin.php). Accept EITHER a direct reference or the wrapper —
   either keeps the funnel contract explicit for a reader of this file. */
if (strpos($tunesPagePhp, 'tuneFindOrCreateByName') === false
    && strpos($tunesPagePhp, 'tuneAdminCreate(') === false
) {
    $failures[] = 'manage/tunes.php references neither tuneFindOrCreateByName nor tuneAdminCreate( — the create path must go through the one find-or-create funnel (rule #22)';
}

/* mediaIdentifierWorkValidate — #1748 moved the actual MBID validation
   into the shared tuneAdminValidateFields() (includes/tune_admin.php,
   rule #35 "ONE validation, create+update", consumed by BOTH this page
   and the admin_tune_* API) rather than calling
   mediaIdentifierWorkValidate() a second time directly in this file (the
   works.php precedent this spec line was modelled on calls it directly
   because works.php has no equivalent shared core). Accept EITHER the
   direct call or the shared-validator reference — both keep "no inline
   regex fork" true, which is the actual intent behind this check. */
if (strpos($tunesPagePhp, 'mediaIdentifierWorkValidate') === false
    && strpos($tunesPagePhp, 'tuneAdminValidateFields(') === false
) {
    $failures[] = 'manage/tunes.php references neither mediaIdentifierWorkValidate nor tuneAdminValidateFields( — MBID validation must go through the shared registry (rule #35)';
}

if (strpos($tunesPagePhp, '[0-9a-f]{8}-') !== false) {
    $failures[] = 'manage/tunes.php appears to carry an inline MBID regex fragment ([0-9a-f]{8}-) — a fork of media_identifiers.php\'s WORK_IDENTIFIER_TYPES registry shape (rule #35)';
}

/* ---- Point assertion 1b — includes/tune_admin.php: the shared core is where
   the validation/gating logic ACTUALLY executes (the page + the admin_tune_*
   API are thin wrappers over it). The #1748 audit found this file was never
   scanned, so the role-guard / MBID-validator / no-inline-regex checks above
   only protected the wrappers' MENTIONS, not the one place the logic runs.
   Assert them here against the real core. ---- */
if (strpos($tuneAdminCode, 'IHYMNS_TUNE_CREDIT_ROLES') === false
    || strpos($tuneAdminCode, 'array_key_exists') === false
) {
    $failures[] = 'includes/tune_admin.php does not guard credit roles via array_key_exists(..., IHYMNS_TUNE_CREDIT_ROLES) — the role allow-list must be enforced where credits are actually persisted, not only mentioned in the page wrapper (rule #20/#35)';
}
if (strpos($tuneAdminCode, 'mediaIdentifierWorkValidate') === false) {
    $failures[] = 'includes/tune_admin.php does not call mediaIdentifierWorkValidate — MBID validation must run in the shared core, not be forked (rule #35)';
}
if (strpos($tuneAdminCode, '[0-9a-f]{8}-') !== false) {
    $failures[] = 'includes/tune_admin.php carries an inline MBID regex fragment ([0-9a-f]{8}-) — a fork of the WORK_IDENTIFIER_TYPES registry shape (rule #35)';
}
if (strpos($tuneAdminCode, 'tuneFindOrCreateByName') === false) {
    $failures[] = 'includes/tune_admin.php never references tuneFindOrCreateByName — the create path must go through the one find-or-create funnel (rule #22; also what keeps test-tune-lockstep.php green for this file)';
}

/* Column-gating locks (#1748 correctness fix, rule #9): the enrichment columns
   tblTunes.Subtitle/.Disambiguation are added by a LATER migration card than the
   one that creates tblTunes, and tblTuneCredits.MusicianId is a post-rename name —
   so both must be column-probed, not assumed from table existence, or a
   partially-migrated install throws under mysqli STRICT (the exact defect the
   correctness lens caught). Lock the gates in so a revert to unconditional
   reads/writes goes red. */
$persistSlice = tasSliceFunction($tuneAdminCode, 'tuneAdminPersistFields');
if ($persistSlice === '') {
    $failures[] = 'includes/tune_admin.php has no tuneAdminPersistFields() to slice';
} elseif (strpos($persistSlice, 'tuneAdminColumnExists') === false) {
    $failures[] = 'tuneAdminPersistFields() writes tblTunes without a tuneAdminColumnExists() gate on Subtitle/Disambiguation — an unconditional write throws under STRICT on a pre-enrichment install (rule #9)';
}
$probeSlice = tasSliceFunction($tuneAdminCode, 'tuneAdminProbeGates');
if ($probeSlice === '') {
    $failures[] = 'includes/tune_admin.php has no tuneAdminProbeGates() to slice';
} else {
    if (strpos($probeSlice, "tuneAdminColumnExists(\$db, 'tblTuneCredits', 'MusicianId'") === false) {
        $failures[] = "tuneAdminProbeGates() does not column-probe tblTuneCredits.MusicianId — hasCredits must require the RENAMED FK column (pre-musicians-rename the column is CreditPersonId and tblMusicians is absent, so an ungated credits read/JOIN throws — rule #9)";
    }
    if (strpos($probeSlice, "tuneAdminColumnExists(\$db, 'tblTunes', 'Subtitle'") === false
        || strpos($probeSlice, "tuneAdminColumnExists(\$db, 'tblTunes', 'Disambiguation'") === false
    ) {
        $failures[] = "tuneAdminProbeGates() does not column-probe both tblTunes.Subtitle and .Disambiguation for hasTuneEnrichCols (rule #9)";
    }
}

/* ---- Point assertion 2 — external_link_helpers.php. ---- */
/* BOTH whitelists must be widened: saveExternalLinksForRow() AND its mirror
   loadExternalLinksForRow() carry the same $allowedTables map, and each throws
   InvalidArgumentException on an unlisted table — so a save-only widening still
   makes rendering a tune's EXISTING links blow up. The #1748 audit found the
   load-side copy was unchecked. */
foreach (['saveExternalLinksForRow', 'loadExternalLinksForRow'] as $fnName) {
    $slice = tasSliceFunction($extLinkCode, $fnName);
    if ($slice === '') {
        $failures[] = "external_link_helpers.php has no {$fnName}() function to slice";
        continue;
    }
    if (strpos($slice, "'tblTuneExternalLinks'") === false) {
        $failures[] = "{$fnName}()'s whitelist is missing 'tblTuneExternalLinks' (#1748 §5.1)";
    }
    if (strpos($slice, "'TuneId'") === false) {
        $failures[] = "{$fnName}()'s whitelist is missing 'TuneId' (#1748 §5.1)";
    }
}
if (strpos($extLinkCode, 'IHYMNS_LINK_ENTITY_TYPES') === false) {
    $failures[] = 'external_link_helpers.php does not define IHYMNS_LINK_ENTITY_TYPES (#1748 §5.2)';
} elseif (strpos($extLinkCode, "'tune'") === false) {
    $failures[] = "external_link_helpers.php's IHYMNS_LINK_ENTITY_TYPES does not appear to contain 'tune'";
}

/* ---- Point assertion 3 — api.php: the four admin_tune_* actions,
   DERIVED from api-docs.yaml (so a documented fifth action forces a case
   + gate check here too). ---- */
$docActions = tasApiDocsActionsWithPrefix($apiDocsSrc, 'admin_tune_');
if (count($docActions) < 4) {
    $failures[] = 'api-docs.yaml documents fewer than 4 admin_tune_* actions (' . count($docActions) . ' found) — expected add/update/merge/delete';
}
foreach ($docActions as $actionName) {
    $caseSlice = tasSliceCase($apiCode, $actionName);
    if ($caseSlice === '') {
        $failures[] = "api.php has no case '{$actionName}': matching the api-docs.yaml-documented action";
        continue;
    }
    if (strpos($caseSlice, "userHasEntitlement('manage_tunes'") === false) {
        $failures[] = "api.php's case '{$actionName}': does not gate on userHasEntitlement('manage_tunes'";
    }
}

/* ---- Point assertion 4 — admin-links.php: a 'tunes' row whose
   entitlement field is 'manage_tunes'. ---- */
if (!preg_match(
    "/\[\s*'tunes'\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'([a-z0-9_]+)'\s*,/i",
    $adminLinksCode,
    $navMatch
)) {
    $failures[] = "admin-links.php has no 'tunes' nav row in the expected shape";
} elseif ($navMatch[1] !== 'manage_tunes') {
    $failures[] = "admin-links.php's 'tunes' row entitlement is '{$navMatch[1]}', expected 'manage_tunes'";
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: Tune admin CRUD surface guard (#1748):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    exit(1);
}

echo "PASS: Tune admin CRUD surface guard — IHYMNS_TUNE_CREDIT_ROLES keys (" . implode(', ', array_keys(IHYMNS_TUNE_CREDIT_ROLES)) . ") "
   . "exactly match tblTuneCredits.Role's schema.sql COMMENT vocabulary; both includes/pages/tune.php and manage/tunes.php "
   . "reference the const with no re-inlined role list; manage/tunes.php gates before <!DOCTYPE, uses validateCsrfRequest( "
   . "with no bare validateCsrf(, keeps the tuneFindOrCreateByName/tuneAdminCreate funnel reference and the MBID validator "
   . "reference with no inline regex fork; external_link_helpers.php's whitelist + IHYMNS_LINK_ENTITY_TYPES were widened "
   . "for tunes; all " . count($docActions) . " documented admin_tune_* action(s) exist in api.php and gate on manage_tunes; "
   . "admin-links.php's 'tunes' row advertises manage_tunes; all mutation self-tests went red as expected.\n";
exit(0);
