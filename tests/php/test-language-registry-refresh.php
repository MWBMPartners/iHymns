<?php

declare(strict_types=1);

/**
 * iHymns — language-registry-refresh endpoint safety guard (BCP 47 registry
 * plan §6.2.3, M1)
 *
 * ELI5: makes sure the new "let a robot refresh the language list" door (a)
 * only accepts the ONE secret key as input — never a URL, filename, or any
 * other payload a leaked key could abuse — (b) has exactly ONE copy of the
 * list of official IANA/CLDR web addresses it's allowed to fetch, (c) checks
 * its key the safe (constant-time) way, and (d) never touches the database
 * schema before a human has pressed the button once.
 *
 * WHY THIS GUARD EXISTS
 * ----------------------
 * `language-registry-refresh.php` is a NEW, keyed, UNATTENDED endpoint — a
 * GitHub Action pokes it once a month with no human watching. Every property
 * this file checks is a "if this regresses, an unattended cron either leaks
 * capability, drifts from the admin button's behaviour, or alters shared-DB
 * schema with no human in the loop" failure — none of which would show up
 * as a visible bug in normal use (rule #34's "silent until it isn't" class,
 * mirrored from `test-endpoint-routing.php` / `test-org-logo-surfaces.php`'s
 * own house style: tree-derived checks, mutation-proven, comment-stripped
 * source so this doc-block itself can freely quote the patterns being
 * checked without false-positiving its own file).
 *
 * WHAT IT CHECKS (four checks, per the plan's §6.2.3 spec)
 * ----------------------------------------------------------------------
 * (A) NO CALLER-SUPPLIED URL/FILENAME/PAYLOAD — comment-stripped source of
 *     `language-registry-refresh.php` is scanned for ANY `$_GET[...]` /
 *     `$_POST[...]` / `$_REQUEST[...]` / `php://input` / a
 *     `$_SERVER['HTTP_*']` header OTHER than the one refresh-key header —
 *     the ONLY input this endpoint may read is the key itself.
 * (B) ONE `$sources` MAP — the IANA/CLDR upstream URL map (recognised by
 *     its fingerprint: the literal `www.iana.org/assignments/language-
 *     subtag-registry` URL string) must appear in EXACTLY the files the
 *     plan names as its one legitimate home
 *     (`includes/language_registry_refresh.php`) and must NOT appear a
 *     second time in `api.php` — the pre-hoist inline copy this refactor
 *     removed.
 * (C) KEY REGISTERED + HASH_EQUALS — `language_registry_refresh_key` is
 *     listed in `secretSettingKeys()` (so it's encrypted at rest), and
 *     `language-registry-refresh.php`'s own source calls `hash_equals(`
 *     to compare it (never `===`/`==`, which leak timing).
 * (D) DORMANCY GATE BEFORE ANY FETCH — `language-registry-refresh.php`
 *     calls `languageRegistrySchemaReady(` textually BEFORE the first
 *     `languageRegistryRefreshCore(` call in the file (byte-offset
 *     comparison on the comment-stripped source) — the schema-readiness
 *     check must gate the refresh, not run alongside/after it.
 *
 * MUTATION-PROVEN (rule #34) — every check below was actually broken, run,
 * confirmed RED, and restored; see the commit body that shipped this file
 * for the full red/green transcript of each.
 *
 *   php tests/php/test-language-registry-refresh.php
 *
 * Exit status 0 = every property holds, 1 = drift.
 *
 * @see appWeb/public_html/language-registry-refresh.php        checked file (A, C partial, D)
 * @see appWeb/public_html/includes/language_registry_refresh.php  checked file (B, D)
 * @see appWeb/public_html/api.php                                checked file (B — must NOT carry a second $sources copy)
 * @see appWeb/public_html/includes/secret_crypto.php             checked file (C — secretSettingKeys())
 * @see .claude/bcp47-language-registry-plan.md §6.2.3            the spec this guard implements
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

$endpointFile = $pub . '/language-registry-refresh.php';
$coreFile     = $pub . '/includes/language_registry_refresh.php';
$apiFile      = $pub . '/api.php';
$secretFile   = $pub . '/includes/secret_crypto.php';

$failures = [];
function langRefreshFail(array &$failures, string $msg): void
{
    $failures[] = $msg;
}

/** Blank `/* … *\/` and `// …` comment BODIES (keep newlines, so a reported
 *  line number stays correct) — the same technique test-endpoint-routing.php
 *  / test-org-logo-surfaces.php use, so a doc-block EXPLAINING one of these
 *  patterns (several exist deliberately in this very file's own doc-block
 *  above, and in the checked files' doc-blocks) never false-positives a scan
 *  for real, executable code. */
function langRefreshStripComments(string $src): string
{
    $src = (string)preg_replace_callback('#/\*.*?\*/#s', static fn(array $m) => str_repeat("\n", substr_count($m[0], "\n")), $src);
    $src = (string)preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
    return $src;
}

foreach ([$endpointFile, $coreFile, $apiFile, $secretFile] as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "FATAL: expected file missing: $f\n");
        exit(1);
    }
}

$endpointSrc = langRefreshStripComments((string)file_get_contents($endpointFile));
$coreSrc     = langRefreshStripComments((string)file_get_contents($coreFile));
$apiSrc      = langRefreshStripComments((string)file_get_contents($apiFile));
$secretSrc   = langRefreshStripComments((string)file_get_contents($secretFile));

/* =============================================================================
 * CHECK A — no caller-supplied URL/filename/payload. The ONLY superglobal
 * read this endpoint may perform is the key itself (`$_GET['key']` /
 * `$_SERVER['HTTP_X_REFRESH_KEY']`). Any OTHER `$_GET`/`$_POST`/`$_REQUEST`
 * access, or `php://input`, is a caller-controlled input this endpoint's
 * whole security posture depends on NOT reading (the plan's §3.3 "no
 * caller-supplied URL, filename, or payload" security invariant).
 * ============================================================================= */

preg_match_all('/\$_(GET|POST|REQUEST)\s*\[\s*([\'"])([^\'"]+)\2\s*\]/', $endpointSrc, $superMatches, PREG_SET_ORDER);
foreach ($superMatches as $m) {
    $var = $m[1] . "['" . $m[3] . "']";
    if ($m[1] === 'GET' && $m[3] === 'key') {
        continue; // the one sanctioned input
    }
    langRefreshFail($failures, "language-registry-refresh.php reads \$_{$var} — the endpoint may read ONLY \$_GET['key'] (rule: no caller-supplied input beyond the key).");
}
if (str_contains($endpointSrc, 'php://input')) {
    langRefreshFail($failures, "language-registry-refresh.php reads php://input (a request body) — the endpoint must accept no payload at all.");
}
preg_match_all('/\$_SERVER\s*\[\s*([\'"])(HTTP_[A-Z0-9_]+)\1\s*\]/', $endpointSrc, $headerMatches, PREG_SET_ORDER);
foreach ($headerMatches as $m) {
    if ($m[2] === 'HTTP_X_REFRESH_KEY') {
        continue; // the one sanctioned header
    }
    langRefreshFail($failures, "language-registry-refresh.php reads \$_SERVER['{$m[2]}'] — the endpoint may read ONLY the X-Refresh-Key header.");
}
echo "  check A: no caller-supplied URL/filename/payload beyond the key — " . (count($superMatches) + count($headerMatches)) . " superglobal read(s) inspected\n";

/* =============================================================================
 * CHECK B — exactly ONE $sources map (rule #35 — one mechanism). Fingerprint
 * the map by a literal upstream URL fragment that only a REAL copy of the
 * map would contain (a doc-block PROSE mention of "IANA" doesn't match this
 * fingerprint, so this can't false-positive on commentary — and comments are
 * already stripped besides).
 * ============================================================================= */

$fingerprint = 'www.iana.org/assignments/language-subtag-registry';
$coreCount = substr_count($coreSrc, $fingerprint);
$apiCount  = substr_count($apiSrc, $fingerprint);

if ($coreCount < 1) {
    langRefreshFail($failures, "includes/language_registry_refresh.php no longer contains the \$sources map (fingerprint '{$fingerprint}' not found) — has languageRegistryRefreshCore() been removed or renamed?");
}
if ($apiCount > 0) {
    langRefreshFail($failures, "api.php contains the IANA URL fingerprint '{$fingerprint}' — a second, re-inlined \$sources copy (the pre-hoist regression). admin_refresh_iana_cldr must delegate to languageRegistryRefreshCore(), never carry its own copy.");
}
echo "  check B: one \$sources map — includes/language_registry_refresh.php: {$coreCount} occurrence(s), api.php: {$apiCount} occurrence(s)\n";

/* =============================================================================
 * CHECK C — key registered in secretSettingKeys() (encrypted at rest) AND
 * compared via hash_equals() (constant-time — never ===/== which leak
 * timing information about how many leading bytes matched).
 * ============================================================================= */

if (!preg_match('/[\'"]language_registry_refresh_key[\'"]/', $secretSrc)) {
    langRefreshFail($failures, "includes/secret_crypto.php's secretSettingKeys() does not list 'language_registry_refresh_key' — the refresh key would be stored in PLAINTEXT.");
}
if (!preg_match('/hash_equals\s*\(\s*\$expected\s*,\s*\$provided\s*\)/', $endpointSrc)) {
    langRefreshFail($failures, "language-registry-refresh.php does not call hash_equals(\$expected, \$provided) — the key comparison must be constant-time.");
}
echo "  check C: key registered + hash_equals() comparison — verified\n";

/* =============================================================================
 * CHECK D — the dormancy gate (languageRegistrySchemaReady()) is called
 * BEFORE the refresh actually runs (languageRegistryRefreshCore()) in the
 * endpoint's own source — a schema-DDL-altering refresh must never be
 * unattended-triggerable before a human has pressed the setup-database card
 * once (the plan's §3.4 load-bearing rule).
 * ============================================================================= */

$gatePos = strpos($endpointSrc, 'languageRegistrySchemaReady(');
$corePos = strpos($endpointSrc, 'languageRegistryRefreshCore(');
if ($gatePos === false) {
    langRefreshFail($failures, "language-registry-refresh.php never calls languageRegistrySchemaReady() — the dormancy gate is missing entirely.");
} elseif ($corePos === false) {
    langRefreshFail($failures, "language-registry-refresh.php never calls languageRegistryRefreshCore() — has the refresh call been removed?");
} elseif ($gatePos > $corePos) {
    langRefreshFail($failures, "language-registry-refresh.php calls languageRegistryRefreshCore() (offset {$corePos}) BEFORE languageRegistrySchemaReady() (offset {$gatePos}) — the dormancy gate must run first.");
}
echo "  check D: schema-ready dormancy gate precedes the refresh call — verified\n";

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " language-registry-refresh problem(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
echo "OK: language-registry-refresh endpoint safety verified (no caller-supplied input beyond the key, one \$sources map, hash_equals key compare, dormancy gate ordered correctly).\n";
exit(0);
