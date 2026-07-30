<?php

declare(strict_types=1);

/**
 * iHymns — the permanent orphan guard (remediation plan §4.1, item G1)
 * ====================================================================
 *
 * ELI5
 * ----
 * Every so often this codebase grows something nothing uses: an API endpoint no
 * screen ever calls, a database table nothing writes to, a permission checkbox
 * nothing checks. It all *looks* shipped — the code is there, the docs describe
 * it, the tests are green — and nobody notices for months. This test rebuilds
 * that list from the source tree on every run and fails the build when a new one
 * appears. The deliberate ones live in a written-down list with reasons.
 *
 * WHY THIS EXISTS
 * ---------------
 * A mechanically-derived audit (`.claude/orphan-inventory-2026-07-30.md`) found
 * 93 dispatched API actions with no first-party caller, 43 tables with a missing
 * read or write side, and 11 labelled entitlements nothing enforced. The
 * flagship case: `bulk_tag_detach` shipped, five documents announced it as a
 * restored control, and there was no button — the commit message itself said
 * "there is simply no Remove button".
 *
 * The owner's brief was "we don't want to be back here again", so the guard
 * lands BEFORE the fixes: every later batch is executed under the check it is
 * meant to satisfy, and the residue is enumerated in a count-exact allowlist
 * rather than rediscovered by the next audit.
 *
 * ⚠️ NEVER `rg`, NEVER A SHELL-OUT — TWO REPRODUCED INCIDENTS
 * ----------------------------------------------------------
 * The corpus walk below is a plain PHP `RecursiveDirectoryIterator` with an
 * explicit skip-list of `.git`, `node_modules` and `vendor` ONLY. Dot-directories
 * are deliberately INCLUDED. Both rules are paid for:
 *
 *   (a) `rg PAT appWeb` **skips `appWeb/.sql/` entirely**, because ripgrep hides
 *       dot-directories by default. `ccli_validation_enabled` sat in
 *       `schema.sql:1722` and was invisible to every scan until plain `grep` was
 *       tried. A guard built on `rg` defaults under-reports the entire
 *       migrations tree — which is also where every backfill writer lives, so
 *       the table check would be silently wrong in the "no writer" direction.
 *
 *   (b) Multi-root invocations (`rg PAT appWeb appApple …`) deterministically
 *       DROPPED the `appApple/Packages/**` matches for `apns_register` while
 *       single-root invocations found them — 16 matches with `--stats` or the
 *       roots reordered, 4 without, reproduced five times. Root cause never
 *       diagnosed. An action called only from Swift would have been reported as
 *       an orphan.
 *
 * Both are inventory §0.1. If a future editor "simplifies" this file back to a
 * shell-out, that is the regression: the scanner will still be green and will
 * still be wrong, which is the worst state a guard can be in.
 *
 * WHAT IT CANNOT CATCH (stated, so its tick is not over-read — inventory §8.4)
 * ---------------------------------------------------------------------------
 *  - **Out-of-repo consumers.** API-key partners, curl scripts, the not-yet-
 *    written native admin app. This guard reports "no in-repo caller", NEVER
 *    "unused". Runtime evidence (`tblApiKeyUsage`) is the only way to close that
 *    gap and it needs a live MySQL.
 *  - **Mounted-but-unreachable UI.** A caller inside a module whose button is
 *    `display:none`, or behind a flag that is off. Static xref sees a caller.
 *    (The Swagger try-it-out console is the worked example: reachable ≠ product
 *    surface.)
 *  - **Semantic emptiness.** `?include=arrangements` works; the data is just
 *    forever empty. The table check catches that only because both sides are
 *    static SQL.
 *  - **Dynamically assembled action names** (`'admin_' . $x`). None exist today;
 *    if one appears this guard under-reports it and nothing here will say so.
 *  - **Ubiquitous names.** An action called `get` or `search` cannot be
 *    distinguished from the thousands of unrelated `'get'` literals in the
 *    corpus, so `places-api.php?action=get` — a real orphan — does not appear.
 *    That is a deliberate bias towards NOT failing on correct code (rule #34:
 *    a guard that cries wolf gets weakened or deleted).
 *  - **Dormant tables** (no reader AND no writer). Check 3 asks "is one side
 *    missing?", and a table with neither side is the DESIGNED state of a rule
 *    #20 one-pass forward-looking batch. 34 tables are in that state today; the
 *    count is printed below for context but deliberately not asserted, because
 *    asserting it would make shipping a forward-looking schema batch a build
 *    failure.
 *
 *   php tests/php/test-orphan-inventory.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$root = dirname(__DIR__, 2);
$pub  = $root . '/appWeb/public_html';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; return; }
    $failed++;
    $failures[] = $label;
    echo "  ❌ {$label}\n";
    if ($detail !== '') {
        foreach (explode("\n", rtrim($detail)) as $line) { echo "       {$line}\n"; }
    }
}

echo "\nOrphan inventory guard — endpoints, tables and entitlements nothing uses\n\n";

/* =========================================================================
 * PART 0 — the corpus
 * ========================================================================= */

/**
 * Every text file in the repo, as repo-relative paths.
 *
 * Skip-list is exactly three directories. Everything else — including every
 * dot-directory — is walked, for the reasons in the header.
 *
 * @return array<int,string>
 */
function orphanCorpusFiles(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $root = dirname(__DIR__, 2);
    $skipDirs = ['.git', 'node_modules', 'vendor'];
    /* Binary extensions are skipped for speed only — none of them can contain a
       PHP case label or a SQL statement. Anything not listed is read as text. */
    $binExt = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'icns', 'pdf', 'zip',
               'gz', 'woff', 'woff2', 'ttf', 'otf', 'mp3', 'mp4', 'wav', 'm4a',
               'jar', 'so', 'dylib', 'class', 'bin', 'sqlite', 'db', 'pptx',
               'docx', 'xlsx', 'psd', 'ai', 'mov'];

    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $path => $info) {
        /** @var SplFileInfo $info */
        if (!$info->isFile()) { continue; }
        $rel = substr((string)$path, strlen($root) + 1);
        foreach (explode('/', $rel) as $seg) {
            if (in_array($seg, $skipDirs, true)) { continue 2; }
        }
        if (in_array(strtolower($info->getExtension()), $binExt, true)) { continue; }
        $out[] = $rel;
    }
    sort($out);
    return $cache = $out;
}

/**
 * Which consumer bucket does this file belong to, if any?
 *
 * A hit in ANY of these counts as a caller. Everything else — api-docs.yaml,
 * *.md, help/, wiki/, tests/, tools/, data/, .claude/ — does NOT: documentation
 * describing an endpoint is precisely the evidence that misled five documents
 * about `bulk_tag_detach`.
 *
 * Each bucket is asserted non-empty further down, so renaming a directory
 * cannot silently empty one and turn its whole client into "no callers found".
 */
function orphanBucketFor(string $rel): ?string
{
    if ($rel === 'appWeb/public_html/service-worker.js.php')      { return 'SW'; }
    if (str_starts_with($rel, 'appWeb/public_html/js/'))          { return 'WEB-JS'; }
    if (str_starts_with($rel, 'appWeb/public_html/manage/'))      { return 'ADMIN'; }
    if (str_starts_with($rel, 'appWeb/public_html/includes/'))    { return 'WEB-INC'; }
    if (preg_match('#^appWeb/public_html/[^/]+\.php$#', $rel))    { return 'WEB-PHP'; }
    if (str_starts_with($rel, 'appApple/'))                       { return 'APPLE'; }
    if (str_starts_with($rel, 'appAndroid/'))                     { return 'ANDROID'; }
    return null;
}

/* =========================================================================
 * PART 1 — dispatched actions and their callers
 * ========================================================================= */

/**
 * Auto-discovered dispatch surfaces → the action names each one answers to.
 *
 * @return array{surfaces:array<int,string>,actions:array<string,array<int,string>>,labels:array<string,array<int,bool>>}
 */
function orphanDispatchIndex(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $root = dirname(__DIR__, 2);
    $surfaces = dispatchParserDiscoverSurfaces($root . '/appWeb/public_html');

    $actions = [];
    $labels  = [];
    foreach ($surfaces as $abs) {
        $rel = substr($abs, strlen($root) + 1);
        $r = dispatchParserActionsForFile($abs);
        $labels[$rel] = $r['labelIndexes'];
        foreach ($r['names'] as $name) { $actions[$name][] = $rel; }
    }
    ksort($actions);

    return $cache = [
        'surfaces' => array_map(static fn ($a) => substr($a, strlen($root) + 1), $surfaces),
        'actions'  => $actions,
        'labels'   => $labels,
    ];
}

/**
 * Emitter shapes that count INSIDE a dispatch file.
 *
 * A dispatch file is not a blanket caller of itself — api.php reuses its own
 * action names as rate-limit keys (`checkRateLimit('device_signout', …)`) and
 * activity-log keys, and its doc-blocks contain `?action=admin_data_health`
 * example URLs. Counting either would let ten real orphans vouch for
 * themselves; the first draft of this guard did exactly that and reported 79
 * orphans instead of 94.
 *
 * But a dispatch file cannot be excluded outright either, because 24 self-posting
 * `/manage/*.php` pages ARE their own caller — the `<input name="action">` is in
 * the same file as the handler. So only these four shapes count, all of which
 * put the literal `action` next to the value:
 *
 *   1. `?action=NAME` / `&action=NAME` in a URL — this is ALSO the §2.6 chain
 *      rule: `bulk_import_skipped_csv` and `import_zip_skipped_csv` have zero
 *      static callers, yet are reachable because the server emits their URLs as
 *      response data (`skipped_csv_url`) which `bulk-import-progress.js` then
 *      fetches. A guard without this rule files them as bugs forever.
 *   2. `<input name="action" … value="NAME">` (either attribute order).
 *   3. `action: 'NAME'` / `'action' => 'NAME'` / `append('action', 'NAME')`.
 *   4. `actionInput.value = 'NAME'` — the assignment shape that false-flagged
 *      all of /manage/credit-people until it was added (inventory §0.2).
 *
 * @param array<string,mixed> $actionSet name => anything, used to filter noise
 * @return array<string,bool>
 */
function orphanEmittersIn(string $text, array $actionSet): array
{
    $out = [];
    $add = static function (array $vals) use (&$out): void {
        foreach ($vals as $v) { if ($v !== '') { $out[$v] = true; } }
    };

    if (preg_match_all('/[?&]action=([A-Za-z0-9_.\-]+)/', $text, $m)) { $add($m[1]); }
    if (preg_match_all('/name=(["\'])action\1[^>]{0,200}?value=(["\'])([A-Za-z0-9_.\-]+)\2/i', $text, $m)) { $add($m[3]); }
    if (preg_match_all('/value=(["\'])([A-Za-z0-9_.\-]+)\1[^>]{0,200}?name=(["\'])action\3/i', $text, $m)) { $add($m[2]); }
    if (preg_match_all('/\b\w*[Aa]ction\w*\??\.value\s*=(?!=)\s*(["\'])([A-Za-z0-9_.\-]+)\1/', $text, $m)) { $add($m[2]); }
    if (preg_match_all('/["\']?\baction\b["\']?\s*(?::|=>|,)\s*["\']([A-Za-z0-9_.\-]+)["\']/', $text, $m)) { $add($m[1]); }

    return array_intersect_key($out, $actionSet);
}

/**
 * Action names referenced by ONE corpus file, restricted to real action names.
 *
 * For a non-dispatch PHP file the scan is token-based, so comments and
 * doc-blocks are skipped: `includes/schema_audit.php` explains at length that
 * "the new admin_schema_audit … API endpoints can call them", and that sentence
 * is not a caller.
 *
 * For JS / Swift / Kotlin the whole text is scanned for quoted literals, which
 * subsumes every §0.2 caller shape at once — `{ action: 'link' }`,
 * `postJson('x')`, `Endpoint(action: "x")`, `input.value = 'x'`.
 *
 * @param array<string,mixed> $actionSet
 * @return array<string,bool>
 */
function orphanReferencesIn(string $rel, string $text, array $actionSet, bool $isDispatchFile): array
{
    $root = dirname(__DIR__, 2);

    if ($isDispatchFile) {
        /* Strip comments before looking for emitters, so a doc-block URL cannot
           vouch for its own endpoint. */
        $clean = '';
        foreach (dispatchParserTokens($root . '/' . $rel) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $clean .= ' '; continue; }
                $clean .= $t[1];
            } else {
                $clean .= $t;
            }
        }
        return orphanEmittersIn($clean, $actionSet);
    }

    $found = [];
    if (str_ends_with($rel, '.php')) {
        foreach (dispatchParserTokens($root . '/' . $rel) as $t) {
            if (!is_array($t)) { continue; }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $v = trim($t[1], "'\"");
                if ($v !== '') { $found[$v] = true; }
            } elseif ($t[0] === T_INLINE_HTML) {
                if (preg_match_all('/[\'"]([A-Za-z0-9_.\-]{2,60})[\'"]/', $t[1], $mm)) {
                    foreach ($mm[1] as $v) { $found[$v] = true; }
                }
                if (preg_match_all('/[?&]action=([A-Za-z0-9_.\-]+)/', $t[1], $mm)) {
                    foreach ($mm[1] as $v) { $found[$v] = true; }
                }
            }
        }
    } else {
        if (preg_match_all('/[\'"`]([A-Za-z0-9_.\-]{2,60})[\'"`]/', $text, $mm)) {
            foreach ($mm[1] as $v) { $found[$v] = true; }
        }
    }
    if (preg_match_all('/[?&]action=([A-Za-z0-9_.\-]+)/', $text, $mm)) {
        foreach ($mm[1] as $v) { $found[$v] = true; }
    }

    return array_intersect_key($found, $actionSet);
}

/**
 * file => [bucket, action names referenced]. Built once; mutations re-aggregate
 * over a filtered copy rather than re-reading the tree.
 *
 * @return array<string,array{bucket:string,names:array<string,bool>}>
 */
function orphanCallerIndex(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $root  = dirname(__DIR__, 2);
    $disp  = orphanDispatchIndex();
    $set   = $disp['actions'];
    $out   = [];

    foreach (orphanCorpusFiles() as $rel) {
        $bucket = orphanBucketFor($rel);
        if ($bucket === null) { continue; }
        $text = (string)file_get_contents($root . '/' . $rel);
        /* A NUL byte in the first block means a mislabelled binary. */
        if (str_contains(substr($text, 0, 8192), "\0")) { continue; }
        $names = orphanReferencesIn($rel, $text, $set, isset($disp['labels'][$rel]));
        $out[$rel] = ['bucket' => $bucket, 'names' => $names];
    }
    return $cache = $out;
}

/**
 * Aggregate the caller index into "which actions have no caller".
 *
 * @param array<int,string>  $excludeFiles rel paths to pretend do not exist
 * @param array<int,string>  $injectActions extra action names to pretend exist
 * @return array{orphans:array<int,string>,callers:array<string,array<string,string>>,buckets:array<string,int>}
 */
function orphanDeriveActions(array $excludeFiles = [], array $injectActions = []): array
{
    $actions = orphanDispatchIndex()['actions'];
    foreach ($injectActions as $a) { $actions[$a] = ['(injected by a mutation self-test)']; }

    $skip    = array_fill_keys($excludeFiles, true);
    $callers = [];
    $buckets = [];

    foreach (orphanCallerIndex() as $rel => $row) {
        if (isset($skip[$rel])) { continue; }
        $buckets[$row['bucket']] = ($buckets[$row['bucket']] ?? 0) + 1;
        foreach ($row['names'] as $name => $_) {
            if (isset($actions[$name])) { $callers[$name][$rel] = $row['bucket']; }
        }
    }

    $orphans = [];
    foreach ($actions as $name => $_) {
        if (empty($callers[$name])) { $orphans[] = $name; }
    }
    sort($orphans);

    return ['orphans' => $orphans, 'callers' => $callers, 'buckets' => $buckets];
}

/* =========================================================================
 * PART 2 — tables vs their read / write paths
 * ========================================================================= */

/**
 * file => [zone, tables read, tables written].
 *
 * One regex pass per file rather than one per (file, table) pair: 141 tables ×
 * 528 files is 74k matches and five seconds; this is one.
 *
 * @return array<string,array{zone:string,readers:array<string,bool>,writers:array<string,bool>}>
 */
function orphanTableIndex(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $root = dirname(__DIR__, 2);
    $out  = [];

    foreach (orphanCorpusFiles() as $rel) {
        if (!preg_match('/\.(php|js|sql|py|sh)$/', $rel)) { continue; }

        /* Zones. MIGRATION writers legitimise a read (a backfill is a writer);
           TEST files legitimise nothing. SCHEMA-ADMIN is the schema tooling
           itself — setup-database and the audit page mention every table in the
           database by construction, so their mentions must not be read as the
           app using it. */
        if (str_starts_with($rel, 'appWeb/.sql/')) {
            $zone = 'MIGRATION';
        } elseif (in_array($rel, [
            'appWeb/public_html/manage/setup-database.php',
            'appWeb/public_html/manage/includes/migration-registry.php',
            'appWeb/public_html/manage/schema-audit.php',
            'appWeb/public_html/includes/schema_audit.php',
        ], true)) {
            $zone = 'SCHEMA-ADMIN';
        } elseif (str_starts_with($rel, 'appWeb/public_html/')) {
            $zone = 'APP';
        } elseif (str_starts_with($rel, 'tests/') || str_starts_with($rel, 'tools/')) {
            $zone = 'TEST';
        } else {
            continue;
        }

        $text = (string)file_get_contents($root . '/' . $rel);
        if (!str_contains($text, 'tbl')) { continue; }
        /* Collapse whitespace so a statement broken across lines or string
           concatenations still matches — `"FROM\n            tblSongs"`. */
        $flat = (string)preg_replace('/\s+/', ' ', $text);

        $readers = [];
        $writers = [];
        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?(tbl[A-Za-z0-9_]+)`?\b/i', $flat, $m)) {
            foreach ($m[1] as $t) { $readers[$t] = true; }
        }
        if (preg_match_all(
            '/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+`?(tbl[A-Za-z0-9_]+)`?\b/i',
            $flat, $m
        )) {
            foreach ($m[1] as $t) { $writers[$t] = true; }
        }
        if ($readers === [] && $writers === []) { continue; }
        $out[$rel] = ['zone' => $zone, 'readers' => $readers, 'writers' => $writers];
    }
    return $cache = $out;
}

/**
 * @param array<int,string> $excludeFiles
 * @return array{tables:array<int,string>,readerNoWriter:array<int,string>,writerNoReader:array<int,string>,dormant:array<int,string>,index:array<string,array{r:array<string,bool>,w:array<string,bool>}>}
 */
function orphanDeriveTables(array $excludeFiles = []): array
{
    $root = dirname(__DIR__, 2);
    $schema = (string)file_get_contents($root . '/appWeb/.sql/schema.sql');
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(tbl[A-Za-z0-9_]+)`?/i', $schema, $m);
    $tables = array_values(array_unique($m[1]));
    sort($tables);

    $skip = array_fill_keys($excludeFiles, true);
    $byTable = [];
    foreach ($tables as $t) { $byTable[$t] = ['r' => [], 'w' => []]; }

    foreach (orphanTableIndex() as $rel => $row) {
        if (isset($skip[$rel])) { continue; }
        foreach ($row['readers'] as $t => $_) { if (isset($byTable[$t])) { $byTable[$t]['r'][$row['zone']] = true; } }
        foreach ($row['writers'] as $t => $_) { if (isset($byTable[$t])) { $byTable[$t]['w'][$row['zone']] = true; } }
    }

    /* Tables whose writer is an interpolated `{$table}` name — invisible to any
       literal SQL scan. The map lives in the fixture and is validated there. */
    $allow = require __DIR__ . '/fixtures/orphan-allowlist.php';
    foreach (array_keys($allow['tables_dynamic_writers'] ?? []) as $t) {
        if (isset($byTable[$t])) { $byTable[$t]['w']['APP'] = true; }
    }

    $readerNoWriter = [];
    $writerNoReader = [];
    $dormant        = [];
    foreach ($tables as $t) {
        $appRead  = isset($byTable[$t]['r']['APP']);
        $appWrite = isset($byTable[$t]['w']['APP']);
        $migWrite = isset($byTable[$t]['w']['MIGRATION']);
        $anyRead  = $byTable[$t]['r'] !== [];

        if (!$appRead && !$appWrite)            { $dormant[] = $t; }
        if ($appRead && !$appWrite && !$migWrite) { $readerNoWriter[] = $t; }
        if ($appWrite && !$anyRead)             { $writerNoReader[] = $t; }
    }

    return [
        'tables'         => $tables,
        'readerNoWriter' => $readerNoWriter,
        'writerNoReader' => $writerNoReader,
        'dormant'        => $dormant,
        'index'          => $byTable,
    ];
}

/* =========================================================================
 * PART 3 — labelled entitlements vs enforcement sites
 * ========================================================================= */

/**
 * The keys of `$ENTITLEMENT_LABELS`, and the PHP files that mention each one.
 *
 * A key counts as ENFORCED when it appears as a quoted string in any
 * `appWeb/public_html` PHP file outside the map files themselves. That is
 * deliberately looser than "is a literal argument to userHasEntitlement()",
 * because two real enforcement shapes are invisible to the tighter test:
 *
 *   - `manage/index.php:27-40` walks an ARRAY of entitlement names;
 *   - `channel_gate.php:92` picks the name with a ternary
 *     (`$entitlement = ($devStatus === 'Alpha') ? 'access_alpha' : 'access_beta';`)
 *     and passes the VARIABLE to `userHasEntitlement()` at :107.
 *
 * The tighter scan missed the second and reported `access_alpha`/`access_beta`
 * as decorative; they are not (remediation plan §0.2). The looser test is the
 * right trade: it can only UNDER-report (call something enforced that is not),
 * never fail the build on correct code.
 *
 * @return array{labels:array<int,string>,sites:array<string,array<int,string>>,mapFiles:array<int,string>}
 */
function orphanEntitlementIndex(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $root = dirname(__DIR__, 2);
    $pub  = $root . '/appWeb/public_html';

    /* Labels, bounded to the array literal so a later map in the same file
       cannot leak in. */
    $src   = (string)file_get_contents($pub . '/manage/entitlements.php');
    $start = strpos($src, '$ENTITLEMENT_LABELS = [');
    $body  = $start === false ? '' : substr($src, $start);
    $end   = $body === '' ? false : strpos($body, "\n];");
    if ($end !== false) { $body = substr($body, 0, $end); }
    preg_match_all("/^\s*'([a-z0-9_]+)'\s*=>\s*\[/m", $body, $m);
    $labels = array_values(array_unique($m[1] ?? []));

    /* Map files are DERIVED (a file declaring either map), not hand-listed, so
       moving a map does not silently turn it into an enforcement site. */
    $mapFiles = [];
    $sites    = [];
    foreach (orphanCorpusFiles() as $rel) {
        if (!str_starts_with($rel, 'appWeb/public_html/') || !str_ends_with($rel, '.php')) { continue; }
        $text = (string)file_get_contents($root . '/' . $rel);
        if (str_contains($text, '$ENTITLEMENT_LABELS = [') || preg_match('/\bconst\s+ENTITLEMENTS\s*=/', $text)) {
            $mapFiles[] = $rel;
            continue;
        }
        if (!str_contains($text, '_')) { continue; }
        foreach (dispatchParserTokens($root . '/' . $rel) as $t) {
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
            $v = trim($t[1], "'\"");
            if ($v !== '' && in_array($v, $labels, true)) { $sites[$v][$rel] = true; }
        }
    }
    foreach ($sites as $k => $v) { $sites[$k] = array_keys($v); }

    return $cache = ['labels' => $labels, 'sites' => $sites, 'mapFiles' => $mapFiles];
}

/**
 * @param array<int,string> $excludeFiles
 * @return array<int,string> labelled entitlements nothing enforces
 */
function orphanDeriveEntitlements(array $excludeFiles = []): array
{
    $idx  = orphanEntitlementIndex();
    $skip = array_fill_keys($excludeFiles, true);
    $dead = [];
    foreach ($idx['labels'] as $k) {
        $live = array_filter($idx['sites'][$k] ?? [], static fn ($f) => !isset($skip[$f]));
        if ($live === []) { $dead[] = $k; }
    }
    return $dead;
}

/* =========================================================================
 * PART 4 — sanity of the derive pass itself
 *
 * If any of these is wrong, every result below is meaningless. Rule #34: a
 * scanner that under-reports is worse than no scanner, because its tick is read
 * as coverage.
 * ========================================================================= */

$corpus = orphanCorpusFiles();
$disp   = orphanDispatchIndex();
$act    = orphanDeriveActions();
$tab    = orphanDeriveTables();
$entIdx = orphanEntitlementIndex();
$ents   = orphanDeriveEntitlements();
$allow  = require __DIR__ . '/fixtures/orphan-allowlist.php';

echo "  Derived: " . count($corpus) . " corpus files, " . count($disp['surfaces'])
   . " dispatch surfaces, " . count($disp['actions']) . " actions, "
   . count($tab['tables']) . " tables, " . count($entIdx['labels']) . " entitlement labels\n";
echo "  (informational, not asserted: " . count($tab['dormant'])
   . " tables have neither an app reader nor an app writer — the designed state of a\n"
   . "   rule #20 one-pass forward-looking batch. See the header.)\n\n";

ok('corpus is a plausible size (>= 800 text files)', count($corpus) >= 800,
    'got ' . count($corpus));

/* The two `rg` incidents, as assertions. If a future refactor reintroduces a
   hidden-dir-skipping walk, these go red instead of silently under-reporting. */
$sqlFiles   = array_filter($corpus, static fn ($f) => str_starts_with($f, 'appWeb/.sql/'));
$appleFiles = array_filter($corpus, static fn ($f) => str_starts_with($f, 'appApple/'));
ok('corpus includes the DOT-directory appWeb/.sql/ (rg skips it by default — inventory §0.1b)',
    count($sqlFiles) >= 10, 'found ' . count($sqlFiles) . ' files');
ok('corpus includes appApple/ (rg dropped these on multi-root runs — inventory §0.1a)',
    count($appleFiles) >= 50, 'found ' . count($appleFiles) . ' files');

ok('dispatch surfaces were auto-discovered, not hand-listed (>= 20 found)',
    count($disp['surfaces']) >= 20, 'found ' . count($disp['surfaces']));
foreach (['appWeb/public_html/api.php',
          'appWeb/public_html/manage/editor/api.php',
          'appWeb/public_html/manage/editor/api2.php',
          'appWeb/public_html/manage/places-api.php'] as $must) {
    ok("auto-discovery found the {$must} dispatch surface",
        in_array($must, $disp['surfaces'], true));
}
ok('parsed a plausible number of dispatched actions (>= 300)',
    count($disp['actions']) >= 300, 'got ' . count($disp['actions']));

/* Every caller bucket must be non-empty. A directory rename that empties a
   bucket would otherwise turn every one of that client's endpoints into an
   "orphan" — or, worse, look like a clean result. */
foreach (['WEB-JS', 'ADMIN', 'WEB-INC', 'WEB-PHP', 'APPLE', 'ANDROID', 'SW'] as $bucket) {
    ok("caller bucket {$bucket} is non-empty (a path rename cannot silently empty it)",
        ($act['buckets'][$bucket] ?? 0) > 0);
}

/* Positive controls — each one a case the scanner has actually got wrong at
   some point in its short life. */
ok("control: 'auth_verify_email' is credited to js/modules/user-auth.js",
    isset($act['callers']['auth_verify_email']['appWeb/public_html/js/modules/user-auth.js']));
ok("control: 'admin_refresh_iana_cldr' is NOT an orphan — proving the scanner can tell a called admin_* action from the 53 that are not",
    !in_array('admin_refresh_iana_cldr', $act['orphans'], true));
ok("control: 'bulk_import_skipped_csv' is caller-by-proxy — its only credit is the self-emitted URL inside its own dispatch file (§2.6 chain rule)",
    array_keys($act['callers']['bulk_import_skipped_csv'] ?? [])
        === ['appWeb/public_html/manage/editor/api.php']);
ok("control: 'update_person' is credited to manage/credit-people.php — the `actionIn.value = '…'` emitter shape (§0.2)",
    isset($act['callers']['update_person']['appWeb/public_html/manage/credit-people.php']));
ok("control: 'bulk_tag_detach' has a caller again (wired by 8c990266; the flagship orphan of the 2026-07-30 audit)",
    !in_array('bulk_tag_detach', $act['orphans'], true));
ok("control: 'tblSongLinks' is not flagged in either direction",
    !in_array('tblSongLinks', $tab['readerNoWriter'], true)
    && !in_array('tblSongLinks', $tab['writerNoReader'], true));

/* The §0.2 correction, pinned as a mechanism rather than a comment: access_alpha
   / access_beta are enforced through a ternary-assigned variable, so they are
   NOT decorative and must not be allowlisted. If channel_gate.php ever stops
   naming them, this goes red and the entitlement check will flag them — which is
   the correct outcome, because at that point they really would be dead. */
$gate = (string)file_get_contents($pub . '/includes/channel_gate.php');
ok("control: channel_gate.php still names 'access_alpha'/'access_beta' and calls userHasEntitlement() — the §0.2 correction to the inventory",
    str_contains($gate, "'access_alpha'") && str_contains($gate, "'access_beta'")
    && str_contains($gate, 'userHasEntitlement('));
ok("control: access_alpha / access_beta are therefore NOT reported as unenforced",
    !in_array('access_alpha', $ents, true) && !in_array('access_beta', $ents, true));

ok('parsed a plausible number of schema tables (>= 100)', count($tab['tables']) >= 100,
    'got ' . count($tab['tables']));
ok('parsed a plausible number of entitlement labels (>= 25)', count($entIdx['labels']) >= 25,
    'got ' . count($entIdx['labels']));
ok('entitlement map files were derived, and both were found',
    count($entIdx['mapFiles']) === 2, implode(', ', $entIdx['mapFiles']));

/* The dynamic-writer map cannot rot: each named file must still contain the
   table literal it claims to write.
   ⚠️ WORD-BOUNDARY, not str_contains(). The first version of this assertion used
   str_contains() and a hand mutation renaming the table to
   `tblWorkExternalLinksRENAMED` sailed straight past it — the old name is a
   PREFIX of the new one. A substring test would have kept vouching for a table
   that no longer exists, which is the precise failure mode this map is here to
   prevent. */
foreach (($allow['tables_dynamic_writers'] ?? []) as $table => $file) {
    $abs = $root . '/' . $file;
    ok("dynamic-writer map: {$file} still contains the literal {$table}",
        is_file($abs)
        && preg_match('/\b' . preg_quote($table, '/') . '\b/', (string)file_get_contents($abs)) === 1);
}

/* =========================================================================
 * PART 5 — allowlist hygiene
 * ========================================================================= */

echo "\n";
foreach (['actions', 'tables_reader_no_writer', 'tables_writer_no_reader', 'entitlements'] as $key) {
    ok("allowlist has an '{$key}' section", isset($allow[$key]) && is_array($allow[$key]));
}

/**
 * The reason rule, as ONE function — used by the real check AND by mutation M7.
 * Two copies of the predicate would be two rules, and the mutation test would
 * stop proving anything about the rule that actually runs (rule #35).
 *
 * An entry must say either which issue tracks it, or that it is deliberate.
 * "because" is not a reason.
 */
function orphanReasonIsExplained(mixed $reason): bool
{
    return is_string($reason)
        && (preg_match('/#\d+/', $reason) === 1 || str_contains($reason, 'deliberate'));
}

$badReasons = [];
foreach (['actions', 'tables_reader_no_writer', 'tables_writer_no_reader', 'entitlements'] as $key) {
    foreach (($allow[$key] ?? []) as $name => $reason) {
        if (!orphanReasonIsExplained($reason)) { $badReasons[] = "{$key}[{$name}]"; }
    }
}
ok('every allowlist entry carries a reason containing an issue number (#1234) or the word "deliberate"',
    $badReasons === [], $badReasons === [] ? '' : implode("\n", $badReasons));

ok('no allowlist entry carries an "until" date (a date that passes silently is not a mechanism — rule #35)',
    !preg_match("/['\"]until['\"]\s*=>/", (string)file_get_contents(__DIR__ . '/fixtures/orphan-allowlist.php')));

/* =========================================================================
 * CHECK 1 — every dispatched action has a first-party caller or an entry
 *
 * (Check 2 — "every documented action exists" — is NOT here. It already ships
 * as tests/php/test-openapi-actions-exist.php, which now shares this file's
 * parser. Duplicating it would create two answers to one question.)
 * ========================================================================= */

echo "\n";
$unexplained = array_values(array_diff($act['orphans'], array_keys($allow['actions'])));
ok('CHECK 1 — every dispatched action has a first-party caller or an allowlist entry ('
    . count($act['orphans']) . ' caller-less, ' . count($allow['actions']) . ' allowlisted)',
    $unexplained === [],
    $unexplained === [] ? '' :
    "These actions are dispatched but nothing in js/, manage/, includes/, the public PHP,\n"
    . "the service worker, appApple or appAndroid calls them:\n  - "
    . implode("\n  - ", $unexplained)
    . "\nWire a caller, delete the endpoint (with its api-docs.yaml path), or add an entry\n"
    . 'to tests/php/fixtures/orphan-allowlist.php with a reason.');

$staleActions = array_values(array_diff(array_keys($allow['actions']), $act['orphans']));
ok('no stale allowlist entries in [actions] — an allowlisted orphan that got wired must be removed',
    $staleActions === [],
    $staleActions === [] ? '' :
    "These are allowlisted but are NOT orphans any more (somebody wired or deleted them).\n"
    . "The allowlist is self-cleaning: delete the entry.\n  - " . implode("\n  - ", $staleActions));

/* =========================================================================
 * CHECK 3 — tables with only one side of the read/write pair
 * ========================================================================= */

$rnwUnexplained = array_values(array_diff($tab['readerNoWriter'], array_keys($allow['tables_reader_no_writer'])));
ok('CHECK 3a — every table an app path READS has an app or migration WRITER, or an allowlist entry ('
    . count($tab['readerNoWriter']) . ' flagged)',
    $rnwUnexplained === [],
    $rnwUnexplained === [] ? '' :
    "App code reads these tables and nothing ever writes them, so the feature can only\n"
    . "return empty:\n  - " . implode("\n  - ", $rnwUnexplained));

$rnwStale = array_values(array_diff(array_keys($allow['tables_reader_no_writer']), $tab['readerNoWriter']));
ok('no stale allowlist entries in [tables_reader_no_writer]', $rnwStale === [],
    $rnwStale === [] ? '' : 'delete: ' . implode(', ', $rnwStale));

$wnrUnexplained = array_values(array_diff($tab['writerNoReader'], array_keys($allow['tables_writer_no_reader'])));
ok('CHECK 3b — every table an app path WRITES has a reader, or an allowlist entry ('
    . count($tab['writerNoReader']) . ' flagged)',
    $wnrUnexplained === [],
    $wnrUnexplained === [] ? '' :
    "App code writes these tables and nothing ever reads them — write-only data:\n  - "
    . implode("\n  - ", $wnrUnexplained));

$wnrStale = array_values(array_diff(array_keys($allow['tables_writer_no_reader']), $tab['writerNoReader']));
ok('no stale allowlist entries in [tables_writer_no_reader]', $wnrStale === [],
    $wnrStale === [] ? '' : 'delete: ' . implode(', ', $wnrStale));

/* =========================================================================
 * CHECK 4 — every labelled entitlement is enforced somewhere
 * ========================================================================= */

$entUnexplained = array_values(array_diff($ents, array_keys($allow['entitlements'])));
ok('CHECK 4 — every labelled entitlement is enforced somewhere, or has an allowlist entry ('
    . count($ents) . ' unenforced)',
    $entUnexplained === [],
    $entUnexplained === [] ? '' :
    "These appear in \$ENTITLEMENT_LABELS — so an operator can edit them at\n"
    . "/manage/entitlements — but no code checks them, so the edit changes nothing,\n"
    . "silently:\n  - " . implode("\n  - ", $entUnexplained));

$entStale = array_values(array_diff(array_keys($allow['entitlements']), $ents));
ok('no stale allowlist entries in [entitlements]', $entStale === [],
    $entStale === [] ? '' : 'delete: ' . implode(', ', $entStale));

/* =========================================================================
 * PART 6 — MUTATION SELF-TESTS (rule #34)
 *
 * Run on EVERY invocation, entirely in memory, never touching the tree. The
 * point is not to test the mutations; it is that this guard must prove its own
 * detectors still fire. Every hardcoded-list check in this repo has, at some
 * point, passed while the thing it named was broken — including three written
 * on the same day as this one. A green run whose detectors are dead is exactly
 * the state we are trying to make impossible.
 * ========================================================================= */

echo "\n  Mutation self-tests (the guard proving it can still fail):\n";

/* M1 — a brand-new endpoint nobody calls must be caught. */
$m1 = orphanDeriveActions([], ['zz_orphan_probe']);
ok('M1: injecting an un-called action makes CHECK 1 flag it',
    in_array('zz_orphan_probe', $m1['orphans'], true)
    && !array_key_exists('zz_orphan_probe', $allow['actions']));

/* M2 — removing a real caller must orphan its action. `custom_tags_sync` is
   called from exactly one place, so the mutation is unambiguous. */
$m2Callers = array_keys($act['callers']['custom_tags_sync'] ?? []);
$m2 = orphanDeriveActions($m2Callers);
ok('M2: deleting every caller of custom_tags_sync from the corpus makes CHECK 1 flag it',
    $m2Callers !== [] && in_array('custom_tags_sync', $m2['orphans'], true)
    && !in_array('custom_tags_sync', $act['orphans'], true),
    'callers removed: ' . implode(', ', $m2Callers));

/* M3 — the per-file EXTRACTOR, not just the aggregation: strip the literal out
   of a copy of the file's text and confirm the reference disappears. This is
   what catches a regex that stops matching a caller shape. */
$m3File = $m2Callers[0] ?? '';
$m3Ok = false;
if ($m3File !== '') {
    $m3Text = (string)file_get_contents($root . '/' . $m3File);
    $before = orphanReferencesIn($m3File, $m3Text, $disp['actions'], false);
    $after  = orphanReferencesIn($m3File, str_replace('custom_tags_sync', 'zz_removed', $m3Text),
                                 $disp['actions'], false);
    /* The non-dispatch branch is token-based and re-reads from disk for PHP, so
       this mutation is only meaningful for a non-PHP caller — which user-auth.js
       is. */
    $m3Ok = isset($before['custom_tags_sync']) && !isset($after['custom_tags_sync']);
}
ok('M3: the per-file reference extractor stops seeing a caller when the literal is removed',
    $m3Ok, 'file: ' . $m3File);

/* M4 — the table check: remove every writer of a table that has a reader. */
$m4Table = 'tblLyricLines';
$m4Files = [];
foreach (orphanTableIndex() as $rel => $row) {
    if (isset($row['writers'][$m4Table])) { $m4Files[] = $rel; }
}
$m4 = orphanDeriveTables($m4Files);
ok("M4: deleting every writer of {$m4Table} from the corpus makes CHECK 3a flag it",
    $m4Files !== [] && in_array($m4Table, $m4['readerNoWriter'], true)
    && !in_array($m4Table, $tab['readerNoWriter'], true),
    'writers removed: ' . implode(', ', $m4Files));

/* M5 — the entitlement check: silence every enforcement site of one key. A key
   with exactly one site is chosen from the tree, not typed, so this keeps
   working when enforcement moves. */
$m5Key = null;
foreach ($entIdx['labels'] as $k) {
    if (count($entIdx['sites'][$k] ?? []) === 1) { $m5Key = $k; break; }
}
$m5 = $m5Key === null ? [] : orphanDeriveEntitlements($entIdx['sites'][$m5Key]);
ok('M5: deleting the only enforcement site of a labelled entitlement makes CHECK 4 flag it',
    $m5Key !== null && in_array($m5Key, $m5, true) && !in_array($m5Key, $ents, true),
    $m5Key === null ? 'no single-site entitlement found to mutate' :
        "key: {$m5Key} (site: " . implode(', ', $entIdx['sites'][$m5Key]) . ')');

/* M6 — the self-cleaning property: an allowlist entry for something that is NOT
   an orphan must be reported stale. */
$m6Stale = array_values(array_diff(
    array_merge(array_keys($allow['actions']), ['song_detail']),
    $act['orphans']
));
ok('M6: allowlisting a healthy action (song_detail) is detected as a stale entry',
    in_array('song_detail', $m6Stale, true)
    && !in_array('song_detail', $staleActions, true));

/* M7 — the reason-format rule really rejects a reason that says nothing, and
   really accepts both accepted forms. Exercises the SAME predicate the check
   above runs, not a re-typed copy of it. */
ok('M7: the reason-format check rejects "because" and accepts both "#1234" and "deliberate"',
    !orphanReasonIsExplained('because')
    && !orphanReasonIsExplained(null)
    && orphanReasonIsExplained('tracked by #1671')
    && orphanReasonIsExplained('deliberate, rule #28'));

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nAn orphan is not a cosmetic problem. It is code that looks shipped: the endpoint\n";
    echo "answers, the docs describe it, the tests are green, and the one thing it was for\n";
    echo "never happens. Wire it, delete it (endpoint + docs + schema together), or write\n";
    echo "down why it stays — in tests/php/fixtures/orphan-allowlist.php, with a reason.\n";
    exit(1);
}
echo "\nNo unexplained orphans. Allowlist is name-exact, count-exact and self-cleaning.\n";
