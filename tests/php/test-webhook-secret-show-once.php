<?php

declare(strict_types=1);

/**
 * iHymns — webhook signing-secret show-once guard (#1987)
 * =========================================================================
 *
 * ELI5
 * ----
 * `/manage/webhooks` used to have a "Reveal secret" button that decrypted a
 * subscription's signing secret and printed it back on screen, any time an
 * admin clicked it. #1987 retired that button for good — the secret is now
 * shown in full exactly ONCE, at the moment it is minted (create) or
 * replaced (rotate), and never again after that. Losing it means rotating
 * it, not looking it up. This file is the standing proof that the leak
 * cannot quietly come back — under a different button, a different action
 * name, or a different response field — without the test suite noticing.
 *
 * WHAT THIS FILE DOES (tree-derived, never a typed belief — rule #34)
 * ---------------------------------------------------------------------
 *   1. Finds EVERY call site of `webhookSecretReveal(` — the one function
 *      that can turn a stored (encrypted-at-rest) secret back into
 *      plaintext — anywhere under `appWeb/`, and identifies which named
 *      function encloses each one. Confirms that set is EXACTLY the
 *      signing/verification allow-list below (currently two functions: the
 *      verification handshake and the outbound delivery signer) in BOTH
 *      directions — no new caller outside the list, and no allow-listed
 *      entry that has quietly stopped calling it. Then, independent of
 *      that list, proves NONE of those enclosing functions' bodies contain
 *      `sendJson(` / `echo json_encode(` / `$revealSecret` / `htmlspecialchars(`
 *      — i.e. the decrypted value never flows toward a response. This
 *      second check is the one that "goes red by discovery, not by name":
 *      a brand-new decrypt-to-response path anywhere in the tree fails it
 *      even if nobody remembered to add its name to an allow-list.
 *   2. `webhookSubscriptionRevealSecret` — the function #1987 deleted — no
 *      longer exists anywhere under `appWeb/`, as either a definition or a
 *      call.
 *   3. `manage/webhooks.php` no longer dispatches a `reveal_secret` action
 *      (checked against the REAL `$action` switch, not a text grep) and no
 *      longer renders a `value="reveal_secret"` form control. The one-shot
 *      `$revealSecret` DISPLAY variable is still assigned — but ONLY inside
 *      the `create` and `rotate_secret` case bodies, isolated per-case so a
 *      third case quietly gaining the same assignment cannot hide.
 *   4. Both places a fresh secret is minted (`webhookSubscriptionCreate()`
 *      and `webhookSubscriptionRotateSecret()` in `includes/webhook_admin.php`)
 *      still store it via `webhookSecretForStorage(` — i.e. #1987 removed the
 *      READ-BACK path, not the encrypted-at-rest write path a signing secret
 *      still needs (it cannot be hashed like an API key — the server must
 *      recover the plaintext to compute the outgoing HMAC).
 *
 * WHY A SIGNING SECRET IS DIFFERENT FROM AN API KEY (do not "fix" this by
 * hashing it)
 * ---------------------------------------------------------------------
 * `tests/php/test-api-coverage-batch6a.php` §F already proves API keys are
 * show-once by construction — only a SHA-256 hash is ever persisted, so the
 * plaintext is architecturally unrecoverable after the mint response. A
 * webhook signing secret cannot follow that model: the server has to sign
 * every OUTBOUND delivery with it, so it is necessarily stored recoverably
 * (encrypted-at-rest via `secretEncrypt()`/`secretDecrypt()`, see
 * `.claude/webhooks-1909-design.md` §7.2). `webhookSecretReveal()` in
 * `includes/webhooks.php` is that decrypt PRIMITIVE and stays — signing
 * needs it. What #1987 removed was the second, unnecessary hop: a page
 * action that decrypted the secret ONLY to hand it back to an admin
 * session. This guard's job is to keep that second hop gone while leaving
 * the first (signing) alone — hence checking WHERE the decrypted value is
 * allowed to flow, not whether decryption itself may ever happen.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * ---------------------------------------------------------------------
 * The REUSABLE parser helpers (`caseBodyFor()`/`caseBodyContains()`/
 * `functionBodyFor()`/`braceBlockAfter()`/`stripPhpComments()`) are the same
 * ones `test-api-coverage-batch6a.php` proved against in-memory fixtures —
 * duplicated locally here per that file's own precedent (and
 * `test-api-coverage-batch5.php` before it). The ONE genuinely new piece of
 * parsing machinery this file adds — `webhookRevealEnclosingFunctions()`,
 * which walks a token stream and attributes each `webhookSecretReveal(`
 * call site to its nearest enclosing NAMED function — gets its own
 * self-test below (finds a marker that is there, rejects a decoy neighbour,
 * and correctly tells a genuine call apart from the `function
 * webhookSecretReveal(...)` DEFINITION line, which token-matches
 * `webhookSecretReveal(` too but is not a call).
 *
 * The live assertions were additionally proven able to fail against a full
 * SCRATCH COPY of the real appWeb/ + tests/php/lib/ tree (never the working
 * tree — this is a read-only guard at rest), one mutation at a time,
 * restoring to a proven-clean baseline (33 passed, 0 failed) between each:
 *
 *   - re-added a `case 'reveal_secret': { ... }` block (byte-identical to
 *     the pre-#1987 shape) to the scratch manage/webhooks.php → 3 assertions
 *     went RED (the case-absence check, the tree-wide
 *     webhookSubscriptionRevealSecret-absence check — the reinstated case
 *     body calls it — and the per-case $revealSecret-assignment isolation
 *     check for the resurrected case) → removed it → back to 33/0 GREEN.
 *   - re-added `function webhookSubscriptionRevealSecret(...)` to the
 *     scratch includes/webhook_admin.php → 2 assertions went RED (the
 *     function-absence check, AND — a bonus independent catch — the new
 *     call site's enclosing function is not in the signing/verification
 *     allow-set) → removed it → back to 33/0 GREEN.
 *   - added a decoy `function webhookDebugDump(\mysqli $db, int $id): void
 *     { $s = webhookSecretReveal(...); sendJson(['secret' => $s]); }` to the
 *     scratch includes/webhook_admin.php → 2 assertions went RED
 *     INDEPENDENTLY (the new call site's enclosing function is not in the
 *     allow-list, AND separately — the "goes red by discovery, not by name"
 *     property this guard exists for — its body contains `sendJson(`) →
 *     removed it → back to 33/0 GREEN.
 *
 * Usage:
 *   php tests/php/test-webhook-secret-show-once.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/webhooks-1909-design.md "Addendum (2026-08-29): #1987"   the owner reversal this guard enforces
 * @see appWeb/public_html/includes/webhooks.php        webhookSecretReveal() — the decrypt primitive, untouched
 * @see appWeb/public_html/includes/webhook_admin.php   the deleted webhookSubscriptionRevealSecret()
 * @see appWeb/public_html/manage/webhooks.php           the deleted reveal_secret action + button
 * @see tests/php/test-api-coverage-batch6a.php           the sibling guard whose helpers this one duplicates
 * @see tests/php/lib/dispatch_parser.php                 the shared tokeniser this one builds on
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo          = dirname(__DIR__, 2);
$appRoot       = $repo . '/appWeb';
$webhookAdmin  = $appRoot . '/public_html/includes/webhook_admin.php';
$webhookEngine = $appRoot . '/public_html/includes/webhooks.php';
$webhooksPage  = $appRoot . '/public_html/manage/webhooks.php';

$webhookAdminSrc  = (string)file_get_contents($webhookAdmin);
$webhookEngineSrc = (string)file_get_contents($webhookEngine);
$webhooksPageSrc  = (string)file_get_contents($webhooksPage);

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
 * DUPLICATED PARSER HELPERS (rule #22 note: this is the SAME duplication
 * test-api-coverage-batch6a.php made from test-api-coverage-batch5.php — a
 * tests-only, single-process-per-file convention, not a violation of the
 * app's own "one core" modularity rule, which governs application code).
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
        $end   = isset($cases[$i + 1]) ? $cases[$i + 1]['index'] : count($toks);
        return tokSpanText($toks, $start, $end);
    }
    return null;
}

function stripPhpComments(string $src): string
{
    $wrapped = (strpos(ltrim($src), '<?php') === 0) ? $src : ("<?php\n" . $src);
    $toks = @token_get_all($wrapped);
    if (!is_array($toks)) { return $src; }
    $out = '';
    foreach ($toks as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

function caseBodyContains(?string $body, string $needle): bool
{
    return $body !== null && strpos(stripPhpComments($body), $needle) !== false;
}

function braceBlockAfter(string $src, string $conditionMarker): ?string
{
    $pos = strpos($src, $conditionMarker);
    if ($pos === false) { return null; }
    $braceStart = strpos($src, '{', $pos);
    if ($braceStart === false) { return null; }

    $depth = 0;
    $len = strlen($src);
    for ($i = $braceStart; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $pos, $i - $pos + 1);
            }
        }
    }
    return null;
}

function functionBodyFor(string $src, string $fnName): ?string
{
    if (!preg_match('/\bfunction\s+' . preg_quote($fnName, '/') . '\s*\(/', $src, $m)) {
        return null;
    }
    return braceBlockAfter($src, $m[0]);
}

/* =========================================================================
 * NEW HELPER — attribute every `webhookSecretReveal(` CALL SITE (never the
 * `function webhookSecretReveal(...)` DEFINITION line, which token-matches
 * the same way but is excluded by checking the token immediately before it)
 * to its nearest enclosing NAMED function, by a single forward token walk
 * tracking brace depth. Operates on an already comment-stripped source so a
 * doc-block merely naming the function can never register as a call.
 *
 * Reuses dispatchParserIsOpenBrace() for the OPEN side of brace-depth
 * counting (the T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES trap documented
 * at length in dispatch_parser.php's header — a plain '{' undercounts
 * string-interpolation opens) — the CLOSE side stays a plain '}' compare,
 * matching dispatch_parser.php's own dispatchParserCaseTokens().
 *
 * @return array<int,array{name:?string,index:int}> one entry per call site;
 *         name is null when the call sits outside any named function (a
 *         closure, or file scope) — such an entry can never satisfy an
 *         allow-list membership check, by construction.
 */
function webhookRevealEnclosingFunctions(string $strippedSrc): array
{
    $toks = token_get_all($strippedSrc);
    $n = count($toks);

    $stack = [];          // [{name:?string, openDepth:int}, ...]
    $depth = 0;
    $awaitingBody = false;
    $pendingName = null;
    $out = [];

    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];

        if (is_array($t) && $t[0] === T_FUNCTION) {
            $j = $i + 1;
            while ($j < $n && ((is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) || $toks[$j] === '&')) {
                $j++;
            }
            $pendingName = (is_array($toks[$j] ?? null) && $toks[$j][0] === T_STRING) ? $toks[$j][1] : null;
            $awaitingBody = true;
            continue;
        }

        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'webhookSecretReveal') {
            /* Exclude the DEFINITION line: `function webhookSecretReveal(`
               token-matches identically to a call, but the token just
               before it (skipping whitespace) is T_FUNCTION, never true for
               an actual call expression. */
            $p = $i - 1;
            while ($p >= 0 && is_array($toks[$p]) && $toks[$p][0] === T_WHITESPACE) { $p--; }
            $isDefinition = is_array($toks[$p] ?? null) && $toks[$p][0] === T_FUNCTION;

            $k = $i + 1;
            while ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) { $k++; }
            $isCall = ($toks[$k] ?? null) === '(';

            if ($isCall && !$isDefinition) {
                $enclosing = null;
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    if ($stack[$s]['name'] !== null) { $enclosing = $stack[$s]['name']; break; }
                }
                $out[] = ['name' => $enclosing, 'index' => $i];
            }
            continue;
        }

        if (dispatchParserIsOpenBrace($t)) {
            $depth++;
            if ($awaitingBody) {
                $stack[] = ['name' => $pendingName, 'openDepth' => $depth];
                $awaitingBody = false;
                $pendingName = null;
            }
            continue;
        }

        if ($t === '}') {
            if (!empty($stack) && end($stack)['openDepth'] === $depth) {
                array_pop($stack);
            }
            $depth--;
            continue;
        }

        if ($t === ';' && $awaitingBody) {
            /* Abstract/interface method declaration — no body ever opens. */
            $awaitingBody = false;
            $pendingName = null;
            continue;
        }
    }

    return $out;
}

/**
 * Every *.php file under $dir (RecursiveDirectoryIterator, never a shell-out
 * to rg/grep — the same reasoning dispatch_parser.php's own header gives:
 * rg skips dot-directories by default and has dropped hits on multi-root
 * invocations in this codebase's own history), vendor/ excluded.
 *
 * @return array<int,string> absolute paths, sorted
 */
function ihWhSosDiscoverPhpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $path = $f->getPathname();
        if (str_contains($path, '/vendor/')) { continue; }
        $out[] = $path;
    }
    sort($out);
    return $out;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — prove the helper functions above can
 * both find a marker that is there AND fail to find one that is not,
 * against small real-tokeniser/real-source fixtures, before the real
 * assertions below are trusted.
 * ========================================================================= */

$mutationFailures = [];

/* ---- reused batch6a fixtures (same precedent — duplicated per-file) ---- */

$caseFixtureSrc = <<<'PHP'
<?php
switch ($action) {
    case 'alpha':
        doAlphaThing();
        break;
    case 'beta':
        doBetaThing();
        alsoBetaHelper();
        break;
    case 'gamma':
        doGammaThing();
        break;
}
PHP;
$caseFixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_wh_sos_case_fixture_');
file_put_contents($caseFixtureFile, $caseFixtureSrc);

$betaBody = caseBodyFor($caseFixtureFile, '$action', 'beta');
if (!caseBodyContains($betaBody, 'doBetaThing(') || !caseBodyContains($betaBody, 'alsoBetaHelper(')) {
    $mutationFailures[] = 'caseBodyFor()/caseBodyContains() FAILS-HIGH self-test: markers genuinely present in the beta case body were not found';
}
if (caseBodyContains($betaBody, 'doAlphaThing(') || caseBodyContains($betaBody, 'doGammaThing(')) {
    $mutationFailures[] = 'caseBodyFor()/caseBodyContains() FAILS-LOW self-test: a NEIGHBOURING case\'s marker was wrongly found inside the beta case body';
}
if (caseBodyFor($caseFixtureFile, '$action', 'does-not-exist') !== null) {
    $mutationFailures[] = 'caseBodyFor() FAILS-LOW self-test: a non-existent case name returned a body instead of null';
}
unlink($caseFixtureFile);

$fnFixtureSrc = <<<'PHP'
<?php
function decoyFunction(string $x): string
{
    return trim($x);
}

function realWriter(string $raw): array
{
    $clean = pretendSanitize($raw, 'layout');
    return ['ok' => true, 'clean' => $clean];
}
PHP;
$decoyBody = functionBodyFor($fnFixtureSrc, 'decoyFunction');
$realBody  = functionBodyFor($fnFixtureSrc, 'realWriter');
if ($realBody === null || strpos($realBody, 'pretendSanitize(') === false) {
    $mutationFailures[] = 'functionBodyFor() FAILS-HIGH self-test: a marker genuinely present inside realWriter() was not found';
}
if ($decoyBody === null || strpos($decoyBody, 'pretendSanitize(') !== false) {
    $mutationFailures[] = 'functionBodyFor() FAILS-LOW self-test: decoyFunction()\'s isolated body wrongly contains realWriter()\'s marker';
}
if (functionBodyFor($fnFixtureSrc, 'doesNotExistFunction') !== null) {
    $mutationFailures[] = 'functionBodyFor() FAILS-LOW self-test: a non-existent function name returned a body instead of null';
}

$commentTrapSrc = <<<'PHP'
/* This block deliberately never calls dangerousFunction() from here. */
safeFunction();
PHP;
if (caseBodyContains($commentTrapSrc, 'dangerousFunction(')) {
    $mutationFailures[] = 'caseBodyContains()/stripPhpComments() FAILS-LOW self-test: a marker inside a /* comment */ was wrongly treated as present';
}
if (!caseBodyContains($commentTrapSrc, 'safeFunction(')) {
    $mutationFailures[] = 'caseBodyContains()/stripPhpComments() FAILS-HIGH self-test: a marker genuinely present in real CODE was not found';
}

/* ---- NEW: webhookRevealEnclosingFunctions() self-test ----
   Three functions: one genuinely calls the target (must be attributed to
   IT, by name), one is a same-file neighbour that must NOT be implicated,
   and — the trap this whole guard exists to dodge — a bare `function
   webhookSecretReveal(...)` DEFINITION line must never itself register as
   a call site. */
$enclosingFixtureSrc = <<<'PHP'
<?php
function webhookSecretReveal(?string $stored): ?string
{
    return secretDecrypt($stored);
}

function decoyNeighbourFunction(): void
{
    doSomethingUnrelated();
}

function realSigningFunction(\mysqli $db, int $id): array
{
    $secret = webhookSecretReveal($stored);
    return ['ok' => true];
}
PHP;
$hits = webhookRevealEnclosingFunctions($enclosingFixtureSrc);
$hitNames = array_column($hits, 'name');

if (count($hits) !== 1) {
    $mutationFailures[] = 'webhookRevealEnclosingFunctions() FAILS-HIGH/LOW self-test: expected exactly 1 real call site (the definition line must be excluded), found ' . count($hits);
}
if (!in_array('realSigningFunction', $hitNames, true)) {
    $mutationFailures[] = 'webhookRevealEnclosingFunctions() FAILS-HIGH self-test: the genuine call inside realSigningFunction() was not attributed to it';
}
if (in_array('decoyNeighbourFunction', $hitNames, true)) {
    $mutationFailures[] = 'webhookRevealEnclosingFunctions() FAILS-LOW self-test: a call was wrongly attributed to the NEIGHBOURING decoyNeighbourFunction(), which never calls it';
}
if (in_array('webhookSecretReveal', $hitNames, true)) {
    $mutationFailures[] = 'webhookRevealEnclosingFunctions() FAILS-LOW self-test (THE core trap): the `function webhookSecretReveal(...)` DEFINITION line was wrongly counted as a CALL site';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

echo "\nWebhook signing-secret show-once guard (#1987)\n\n";

/* -----------------------------------------------------------------------
 * 1. Tree-wide webhookSecretReveal( call-site enumeration.
 * ------------------------------------------------------------------- */

/* The MAINTAINED allow-list (rule #34/#35's "a human writes down a decision
   with a reason" shape — same pattern test-manage-action-api-coverage.php's
   $MAPPING uses). Keys are paths relative to $appRoot; values are the named
   functions genuinely allowed to decrypt a webhook signing secret, because
   they need the plaintext to SIGN an outgoing request — never to answer one. */
$allowSet = [
    'public_html/includes/webhook_admin.php' => ['webhookSendVerification'],
    'public_html/includes/webhooks.php'      => ['_webhookAttemptDelivery'],
];

$discovered = [];   // "relpath::fnName" => true, for every call site found tree-wide
/* NOTE on the 'json_encode(' needle: a bare substring match on json_encode(
   would false-positive on this codebase's own legitimate use — both allow-
   listed functions ALSO json_encode() the OUTBOUND webhook envelope (the
   request body sent TO the partner, then HMAC-signed with the very secret
   this guard is protecting; see webhook_admin.php's $rawBody / webhooks.php's
   $payload). That is the opposite direction from a leak. The real "hand-
   rolled JSON response to the browser" shape elsewhere in this codebase is
   `echo json_encode(...)` (api_keys.php, rate_limit.php, read_rate_limit.php
   all use exactly this outside the house sendJson() wrapper), so that is
   the needle here — narrow enough not to fail on correct code (rule #34)
   while still catching a hand-rolled response bypassing sendJson(). */
$leakNeedles = ["sendJson(", "echo json_encode(", '$revealSecret', 'htmlspecialchars('];

$phpFiles = ihWhSosDiscoverPhpFiles($appRoot);
foreach ($phpFiles as $file) {
    $raw = (string)file_get_contents($file);
    /* Cheap textual pre-filter (mirrors dispatchParserIsSurface's own
       pattern) — only pay for tokenising + comment-stripping files that
       could possibly matter. */
    if (strpos($raw, 'webhookSecretReveal') === false) { continue; }

    $stripped = stripPhpComments($raw);
    $hits = webhookRevealEnclosingFunctions($stripped);
    if ($hits === []) { continue; }

    $rel = ltrim(str_replace($appRoot, '', $file), '/');

    foreach ($hits as $hit) {
        $name = $hit['name'];
        $label = $name === null ? "{$rel} (call outside any named function)" : "{$rel}::{$name}";
        $inAllow = $name !== null && in_array($name, $allowSet[$rel] ?? [], true);
        ok("webhookSecretReveal( call site '{$label}' is in the signing/verification allow-set",
            $inAllow);

        if ($name !== null) {
            $discovered["{$rel}::{$name}"] = true;

            /* Independent of the allow-list above: whatever function this
               call sits in, its body must never carry a plaintext-secret
               response pattern — proves the property, not just the name. */
            $fnBody = functionBodyFor($raw, $name);
            foreach ($leakNeedles as $needle) {
                ok("'{$label}' (encloses a webhookSecretReveal( call) does NOT contain '{$needle}' anywhere in its own body",
                    !caseBodyContains($fnBody, $needle));
            }
        }
    }
}

/* No stale allow-list entries — every listed function must still genuinely
   call webhookSecretReveal(, proven from the SAME discovery pass above. */
foreach ($allowSet as $rel => $fnNames) {
    foreach ($fnNames as $fnName) {
        ok("allow-set entry '{$rel}::{$fnName}' still genuinely calls webhookSecretReveal( (not stale)",
            isset($discovered["{$rel}::{$fnName}"]));
    }
}

/* -----------------------------------------------------------------------
 * 2. webhookSubscriptionRevealSecret() — gone tree-wide (definition + call).
 * ------------------------------------------------------------------- */

$stillReferenced = [];
foreach ($phpFiles as $file) {
    $raw = (string)file_get_contents($file);
    if (strpos($raw, 'webhookSubscriptionRevealSecret') === false) { continue; }
    if (strpos(stripPhpComments($raw), 'webhookSubscriptionRevealSecret') !== false) {
        $stillReferenced[] = ltrim(str_replace($appRoot, '', $file), '/');
    }
}
ok('webhookSubscriptionRevealSecret does not exist anywhere under appWeb/ (no definition, no call — comment-stripped, so a doc-block explaining the removal, like this file\'s own header, can never trip this)',
    $stillReferenced === []);

/* -----------------------------------------------------------------------
 * 3. manage/webhooks.php — no reveal_secret action, no reveal_secret
 *    control, and $revealSecret is assigned ONLY inside create/rotate_secret.
 * ------------------------------------------------------------------- */

$pageActionCases = dispatchParserCasesForSwitch($webhooksPage, '$action');
ok("manage/webhooks.php's \$action switch no longer carries a 'reveal_secret' case",
    !in_array('reveal_secret', $pageActionCases, true));

ok('manage/webhooks.php no longer renders a value="reveal_secret" form control',
    strpos($webhooksPageSrc, 'value="reveal_secret"') === false);

foreach ($pageActionCases as $caseName) {
    $body = caseBodyFor($webhooksPage, '$action', $caseName);
    $assignsReveal = caseBodyContains($body, '$revealSecret =');
    $shouldAssign = in_array($caseName, ['create', 'rotate_secret'], true);
    ok("manage/webhooks.php's '{$caseName}' case " . ($shouldAssign ? 'DOES' : 'does NOT') . " assign \$revealSecret =",
        $assignsReveal === $shouldAssign);
}

/* -----------------------------------------------------------------------
 * 4. Both mint funnels store via webhookSecretForStorage( — encrypted-at-
 *    rest write path is untouched by the read-back removal above.
 * ------------------------------------------------------------------- */

foreach (['webhookSubscriptionCreate', 'webhookSubscriptionRotateSecret'] as $fn) {
    $body = functionBodyFor($webhookAdminSrc, $fn);
    ok("includes/webhook_admin.php's {$fn}() is isolatable by functionBodyFor()", $body !== null);
    ok("{$fn}() stores the freshly minted secret via webhookSecretForStorage(",
        caseBodyContains($body, 'webhookSecretForStorage('));
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failed > 0 || $mutationFailures) {
    if ($mutationFailures) {
        fwrite(STDERR, "\nFAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    echo "\n{$passed} passed, {$failed} failed";
    if ($mutationFailures) { echo ' (+ ' . count($mutationFailures) . ' mutation self-test failure(s))'; }
    echo "\n";
    exit(1);
}

echo "\n{$passed} passed, 0 failed. The webhook signing-secret show-once discipline holds: every webhookSecretReveal( call site tree-wide sits in the signing/verification allow-set and none of those functions' bodies ever route the decrypted value toward a response; webhookSubscriptionRevealSecret() is gone entirely; manage/webhooks.php carries no reveal_secret action or control and assigns \$revealSecret only from create/rotate_secret; and both mint funnels still store fresh secrets encrypted-at-rest.\n";
