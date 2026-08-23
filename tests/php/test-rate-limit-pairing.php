<?php

declare(strict_types=1);

/**
 * iHymns — rate-limit two-call contract guard (#1636)
 * ============================================================================
 *
 * checkRateLimit() only ever READS the counter in tblLoginAttempts;
 * recordRateLimitHit() is the only thing that WRITES it. That makes a rate
 * limit a TWO-CALL CONTRACT with nothing in the language enforcing the second
 * call — and it rotted exactly as you would expect: #1636 found THIRTEEN
 * actions that were checked and never recorded, so their counters sat at zero
 * forever and the caps were mathematically unreachable. Among them was
 * `service_join`, the anti-probe control on Service Mode join-code guessing
 * that #1621's code-uniqueness hardening had assumed was working.
 *
 * A missing record is invisible at runtime: nothing errors, nothing logs, the
 * endpoint behaves perfectly — it simply never refuses anyone. That is the
 * same silent-no-op failure mode tests/test-event-names.js exists to prevent
 * for DOM event names (a dispatcher with no listener), and this guard is
 * modelled on its paired-contract shape.
 *
 * WHAT IS ASSERTED
 *   1. Every checkRateLimit('X', …) action has at least one matching
 *      recordRateLimitHit('X', …) somewhere in the scanned tree.
 *   2. The reverse: every recordRateLimitHit('X', …) has a matching
 *      checkRateLimit('X', …). A write nobody reads is dead weight in a table
 *      that is pruned on a 30-day timer.
 *   3. No call DISCARDS its verdict: a checkRateLimit() in statement position
 *      (its return value unused) must have autoRespond = true, or nothing can
 *      ever answer 429. `service_join` and `service_poll` were dead BOTH ways —
 *      no counter AND no verdict — so pairing alone would not have caught them.
 *   4. Rule #26: Service Mode presence limiting is keyed PER PRESENCE TOKEN,
 *      never per IP. A NAT-shared congregation is a single IP, so an IP-keyed
 *      presence limiter would lock a whole church out mid-service.
 *
 * ALLOWLISTS are COUNT-EXACT: a stale entry (one that no longer describes a
 * real violation) FAILS the test, so the allowlist cannot quietly become a
 * dumping ground. Both are currently EMPTY.
 *
 *   php tests/php/test-rate-limit-pairing.php
 *
 * To prove the guard is fail-capable, point it at a tree with a violation:
 *   IHYMNS_SCAN_ROOT=/path/to/broken/docroot php tests/php/test-rate-limit-pairing.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/rate_limit.php
 * @see tests/test-event-names.js  (the paired-contract precedent)
 * @see https://www.php.net/manual/en/function.token-get-all.php
 */

$scanRoot = getenv('IHYMNS_SCAN_ROOT') ?: (dirname(__DIR__, 2) . '/appWeb/public_html');
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: could not read $scanRoot\n");
    exit(1);
}

$failures = 0;
$passed   = 0;

/** Record one assertion. */
function rlp(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/**
 * Blank out every comment, preserving byte offsets and line structure.
 *
 * ELI5: keeps the code where it is but wipes the notes around it, so "is this
 * action recorded?" measures the CODE and not the paragraph explaining it.
 *
 * Load-bearing, not cosmetic: the rate-limit call sites are heavily annotated
 * in house style, and those annotations NAME the very symbols this scanner
 * looks for ("the check READS the counter", "paired writer",
 * "recordRateLimitHit('service_join', …)"). Scanning raw source would match the
 * prose — passing on a comment while the real call is missing, which is worse
 * than a false positive. Same technique as tests/php/test-auth-rate-limit.php
 * and tests/php/test-fragment-inline-scripts.php.
 */
function rlpStripComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);   // same length, newlines kept
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/**
 * Split the argument list of a call whose '(' sits at $open into top-level
 * argument source strings.
 *
 * Tracks nesting of ()[]{} and both quote styles (with backslash escapes) so a
 * comma inside a string literal, an array literal or a nested call — e.g.
 * `'tok:' . substr(hash('sha256', $token), 0, 24)` — is not mistaken for an
 * argument separator. Returns null if the call is unbalanced, which the caller
 * treats as a FAILURE rather than a pass: a parse we cannot trust must not be
 * silently reported as compliant.
 *
 * @return list<string>|null
 */
function rlpCallArgs(string $src, int $open): ?array
{
    $len = strlen($src);
    $depth = 0;
    $quote = '';
    $args  = [];
    $buf   = '';
    for ($i = $open; $i < $len; $i++) {
        $c = $src[$i];

        if ($quote !== '') {
            $buf .= $c;
            if ($c === '\\') { $i++; if ($i < $len) { $buf .= $src[$i]; } continue; }
            if ($c === $quote) { $quote = ''; }
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; $buf .= $c; continue; }

        if ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
            if ($depth === 1) { continue; }     // the call's own opening paren
            $buf .= $c;
            continue;
        }
        if ($c === ')' || $c === ']' || $c === '}') {
            $depth--;
            if ($depth === 0) { $args[] = trim($buf); return $args; }
            $buf .= $c;
            continue;
        }
        if ($c === ',' && $depth === 1) { $args[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    return null;   // unbalanced
}

/** 1-based line number of a byte offset. */
function rlpLine(string $src, int $offset): int
{
    return substr_count($src, "\n", 0, $offset) + 1;
}

/**
 * Resolve a bucket-key argument that is a bare local variable back to the
 * expression most recently assigned to it.
 *
 * ELI5: if the call says `$svcPollKey`, go and find the line that says
 * `$svcPollKey = …` and report THAT instead.
 *
 * Why this matters: the rule #26 assertion below asks "is this key derived
 * from an IP address?". Call sites legitimately hoist the key into a local so
 * the check and the record provably share one expression — but that hoisting
 * would make a naive textual test pass on the variable NAME alone, i.e. pass
 * vacuously no matter what the variable holds. Resolving one level of
 * assignment keeps the assertion about the real expression. An unresolvable
 * variable is returned unchanged and the caller treats that as a failure,
 * never as a pass.
 */
function rlpResolveKeyExpr(string $src, int $pos, string $expr): string
{
    if (!preg_match('/^\$[A-Za-z_]\w*$/', $expr)) { return $expr; }
    $before = substr($src, 0, $pos);
    /* Last assignment to this variable before the call site. */
    if (preg_match_all('/' . preg_quote($expr, '/') . '\s*=\s*([^;]+);/', $before, $m)) {
        return trim((string)end($m[1]));
    }
    return $expr;
}

/* ===========================================================================
 * SCAN — collect every call site across the docroot
 *
 * The whole tree, not just api.php: the contract belongs to the helper, so a
 * future /manage/ page or include that adopts checkRateLimit() is covered the
 * day it is written rather than the day someone remembers to widen this test.
 * rate_limit.php itself is skipped — it DEFINES both functions, and its own
 * doc-block examples would otherwise register as call sites.
 * ======================================================================== */

$checks  = [];   // action => list of "file:line"
$records = [];   // action => list of "file:line"
$discarded = []; // "file:line action" for verdict-discarding calls
$unparsed  = []; // "file:line" for calls whose args could not be split
$keyExpr   = []; // action => list of the 2nd-argument source text

/* -------------------------------------------------------------------------
 * COMPILE-TIME ACTION CONSTANTS (#1027)
 *
 * An action name passed as a bare `const` identifier (e.g.
 * IHYMNS_AUTH_ACCT_ACTION) is NOT a "computed action name" the way a variable
 * or a concatenation is: a `const NAME = 'literal';` is fixed at parse time,
 * so a check and a record that both name it still pair statically — the
 * property this scanner exists to protect is intact. Resolving these keeps the
 * guard capable of understanding named-constant call sites (the shared
 * per-account login bucket uses one) instead of rejecting them as unpairable.
 *
 * Only `const NAME = '<action-charset>';` definitions are collected (the action
 * charset is [a-z0-9_]); anything whose value is not a bare lowercase string
 * literal is deliberately NOT resolved, so a genuinely computed name still
 * falls through to the unparsed/FAIL path. rate_limit.php IS included here
 * (unlike the call-site scan, which skips it) because that is where the shared
 * constants are defined.
 * ---------------------------------------------------------------------- */
$actionConsts = [];   // CONST_NAME => 'resolved literal action'
$constIt = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($constIt as $cf) {
    if (!$cf->isFile() || strtolower($cf->getExtension()) !== 'php') { continue; }
    $cpath = str_replace('\\', '/', $cf->getPathname());
    if (strpos($cpath, '/vendor/') !== false) { continue; }
    $craw = (string)file_get_contents($cf->getPathname());
    if (strpos($craw, 'const ') === false) { continue; }
    $csrc = rlpStripComments($craw);
    if (preg_match_all('/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*\'([a-z0-9_]+)\'\s*;/', $csrc, $cm, PREG_SET_ORDER)) {
        foreach ($cm as $one) { $actionConsts[$one[1]] = $one[2]; }
    }
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
);
$scannedFiles = 0;

foreach ($it as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
    $path = $file->getPathname();
    if (str_ends_with(str_replace('\\', '/', $path), 'includes/rate_limit.php')) { continue; }
    if (strpos(str_replace('\\', '/', $path), '/vendor/') !== false) { continue; }

    $raw = (string)file_get_contents($path);
    if (strpos($raw, 'RateLimit') === false) { continue; }   // cheap pre-filter
    $src = rlpStripComments($raw);
    $scannedFiles++;

    $rel = ltrim(str_replace($scanRoot, '', $path), '/\\');

    foreach (['checkRateLimit', 'recordRateLimitHit'] as $fn) {
        $offset = 0;
        while (($pos = strpos($src, $fn . '(', $offset)) !== false) {
            $offset = $pos + 1;

            /* Skip a definition (`function checkRateLimit(`) and any longer
               identifier that merely ENDS with this name. */
            $before = substr($src, max(0, $pos - 40), min(40, $pos));
            if (preg_match('/function\s+$/', $before)) { continue; }
            if ($pos > 0 && preg_match('/[A-Za-z0-9_$]/', $src[$pos - 1])) { continue; }

            $line = rlpLine($src, $pos);
            $args = rlpCallArgs($src, $pos + strlen($fn));
            if ($args === null || $args === []) { $unparsed[] = "$rel:$line ($fn)"; continue; }

            /* First argument must resolve to a fixed action name — either a
               single-quoted literal, or a compile-time `const` that holds one
               (see $actionConsts above). A RUNTIME-computed name (a variable, a
               concatenation, a function call) would defeat static pairing and
               is deliberately rejected as unparsed. */
            if (preg_match('/^\'([a-z0-9_]+)\'$/', $args[0], $m)) {
                $action = $m[1];
            } elseif (preg_match('/^([A-Z_][A-Z0-9_]*)$/', $args[0], $m) && isset($actionConsts[$m[1]])) {
                $action = $actionConsts[$m[1]];
            } else {
                $unparsed[] = "$rel:$line ($fn — non-literal action " . $args[0] . ')';
                continue;
            }

            if ($fn === 'recordRateLimitHit') {
                $records[$action][] = "$rel:$line";
                continue;
            }

            $checks[$action][] = "$rel:$line";
            $keyExpr[$action][] = rlpResolveKeyExpr($src, $pos, $args[1] ?? '');

            /* Verdict discarded? The call is in STATEMENT position when the
               previous non-whitespace character ends a statement/block — i.e.
               nothing is consuming what it returns. `$x = check(...)`,
               `if (!check(...))`, `return check(...)` all consume it. */
            $prev = rtrim(substr($src, 0, $pos));
            $prevChar = $prev === '' ? ';' : substr($prev, -1);
            $isStatement = in_array($prevChar, [';', '{', '}', ':'], true);
            $autoRespond = $args[4] ?? 'false';   // default parameter is false
            if ($isStatement && strtolower(trim($autoRespond)) !== 'true') {
                $discarded[] = "$rel:$line ($action, autoRespond=" . trim($autoRespond) . ')';
            }
        }
    }
}

rlp($scannedFiles > 0, "0.1 scanned $scannedFiles PHP file(s) under $scanRoot");
rlp($checks !== [],     '0.2 at least one checkRateLimit() call site was found (the scanner works)');
rlp(
    $unparsed === [],
    '0.3 every call site parsed with a literal action name'
        . ($unparsed === [] ? '' : ' — unparsed: ' . implode(', ', $unparsed))
);

/* ===========================================================================
 * SECTION 1 — every CHECK has a RECORD (#1636's headline)
 * ======================================================================== */

/* Deliberate exceptions: action => reason. COUNT-EXACT — an entry that is no
   longer a real violation fails the test below, so this cannot rot into a
   blanket suppression. Currently EMPTY: every checked action is recorded. */
$checkWithoutRecordAllowed = [];

$unpaired = [];
foreach ($checks as $action => $sites) {
    if (!isset($records[$action])) { $unpaired[$action] = $sites; }
}

foreach ($unpaired as $action => $sites) {
    rlp(
        isset($checkWithoutRecordAllowed[$action]),
        "1.1 checkRateLimit('$action') has a matching recordRateLimitHit() "
            . '[checked at ' . implode(', ', $sites) . ' — this cap can never trip]'
    );
}
if ($unpaired === []) {
    rlp(true, '1.1 every checkRateLimit() action has a paired recordRateLimitHit() ('
        . count($checks) . ' actions)');
}

/* Count-exact: nothing may sit in the allowlist that is no longer violating. */
foreach ($checkWithoutRecordAllowed as $action => $reason) {
    rlp(
        isset($unpaired[$action]),
        "1.2 allowlist entry '$action' still describes a real exception "
            . '(remove it now that the action is paired)'
    );
}

/* ===========================================================================
 * SECTION 2 — every RECORD has a CHECK (no write nobody reads)
 * ======================================================================== */

$recordWithoutCheckAllowed = [];   // COUNT-EXACT, currently empty

$orphanWrites = [];
foreach ($records as $action => $sites) {
    if (!isset($checks[$action])) { $orphanWrites[$action] = $sites; }
}
foreach ($orphanWrites as $action => $sites) {
    rlp(
        isset($recordWithoutCheckAllowed[$action]),
        "2.1 recordRateLimitHit('$action') has a matching checkRateLimit() "
            . '[recorded at ' . implode(', ', $sites) . ' — rows nobody counts]'
    );
}
if ($orphanWrites === []) {
    rlp(true, '2.1 every recordRateLimitHit() action is read back by a checkRateLimit()');
}
foreach ($recordWithoutCheckAllowed as $action => $reason) {
    rlp(isset($orphanWrites[$action]), "2.2 allowlist entry '$action' still describes a real exception");
}

/* ===========================================================================
 * SECTION 3 — no DISCARDED verdict
 *
 * A working counter is still useless if nothing acts on the answer. Both
 * service_join and service_poll were dead this way as well as unrecorded, so
 * pairing alone would have "fixed" them into a limiter that still never
 * refused a request.
 * ======================================================================== */

rlp(
    $discarded === [],
    '3.1 no checkRateLimit() discards its verdict (statement position requires '
        . 'autoRespond=true)' . ($discarded === [] ? '' : ' — ' . implode(', ', $discarded))
);

/* ===========================================================================
 * SECTION 4 — rule #26: presence limiting is per TOKEN, never per IP
 *
 * The congregation-facing Service Mode buckets must not be keyed on an IP: a
 * church behind one NAT egresses as a single address, so an IP-keyed presence
 * limiter throttles the whole room as if it were one abuser — locking people
 * out mid-service. service_join is the documented exception (the presence
 * token is MINTED by that endpoint, so no token exists yet to key on); it is
 * made NAT-safe instead by recording only FAILED joins, asserted below.
 * ======================================================================== */

if (isset($keyExpr['service_poll'])) {
    $pollKeys = $keyExpr['service_poll'];
    $tokenKeyed = true;
    foreach ($pollKeys as $k) {
        /* Must be POSITIVELY derived from the presence token (the 'tok:' bucket
           prefix over a hash of it) and must not mention an address. Requiring
           the positive marker is what stops an unresolved variable — or a future
           rewrite to something neither token- nor IP-shaped — from passing by
           merely failing to look like an IP. */
        if (strpos($k, "'tok:'") === false || strpos($k, 'token') === false) { $tokenKeyed = false; }
        if (strpos($k, 'REMOTE_ADDR') !== false || preg_match('/\$\w*[Ii]p\b/', $k)) { $tokenKeyed = false; }
    }
    rlp($tokenKeyed, '4.1 service_poll is keyed per presence token, not per IP (rule #26) — key: '
        . implode(' | ', $pollKeys));
} else {
    rlp(false, '4.1 a checkRateLimit(\'service_poll\', …) call site exists to inspect');
}

/* service_join must spend budget ONLY on failures, or a NAT-shared
   congregation joining correctly could exhaust its own per-IP bucket. The
   marker is recordRateLimitHit()'s third argument being an explicit false
   (Success = 0) — the same shape auth_login_acct uses. */
$joinFile = $scanRoot . '/api.php';
if (is_readable($joinFile)) {
    $joinSrc = rlpStripComments((string)file_get_contents($joinFile));
    $joinRecords = [];
    $off = 0;
    while (($p = strpos($joinSrc, "recordRateLimitHit('service_join'", $off)) !== false) {
        $off = $p + 1;
        $a = rlpCallArgs($joinSrc, $p + strlen('recordRateLimitHit'));
        $joinRecords[] = $a === null ? '?' : strtolower(trim($a[2] ?? 'true'));
    }
    rlp(
        $joinRecords !== [] && !in_array('true', $joinRecords, true) && !in_array('?', $joinRecords, true)
            && count(array_unique($joinRecords)) === 1 && $joinRecords[0] === 'false',
        '4.2 service_join records ONLY failed joins (Success=false), so a NAT-shared '
            . 'congregation joining with a CORRECT code never consumes its per-IP budget '
            . '(found: ' . (implode(', ', $joinRecords) ?: 'no record call') . ')'
    );
} else {
    rlp(false, '4.2 api.php is readable for the service_join key inspection');
}

/* #1770 G5 — the SAME per-token/per-key discipline extended to the two new
   machine/congregant-scale endpoints §9 names explicitly: `service_drive`
   (an external driver — key-authed, so its bucket must derive from the KEY,
   never the caller's IP: several presentation-app clients on one church LAN
   would otherwise throttle each other) and `live_follow_poll` (the #1268
   sibling of `service_poll`, extended #1770 C2). Per plan §9, extending
   THIS existing paired-writer scanner rather than writing a new one. */

/* service_drive: MACHINE-authenticated (a driver key, never a person), so
   its checkRateLimit key must be POSITIVELY derived from a HASH of the raw
   key ('drvkey:' prefix over hash(...)) — never per-IP (rule #26 I2) and
   never the raw key itself (which would persist the SECRET into
   tblLoginAttempts.IpAddress — the #1492 lesson service_poll's 'tok:' bucket
   already follows for a presence token). */
if (isset($keyExpr['service_drive'])) {
    $driveKeys = $keyExpr['service_drive'];
    $driveKeyed = true;
    foreach ($driveKeys as $k) {
        if (strpos($k, "'drvkey:'") === false || strpos($k, 'hash(') === false) { $driveKeyed = false; }
        if (strpos($k, 'REMOTE_ADDR') !== false || preg_match('/\$\w*[Ii]p\b/', $k)) { $driveKeyed = false; }
    }
    rlp($driveKeyed, '4.3 service_drive is keyed per driver-key hash, not per IP (rule #26 I2) — key: '
        . implode(' | ', $driveKeys));
} else {
    rlp(false, '4.3 a checkRateLimit(\'service_drive\', …) call site exists to inspect');
}

/* live_follow_poll: unlike service_poll this ALSO keeps a documented per-IP
   FALLBACK branch for a follower whose client predates the #1377 poll
   token (back-compat, not a regression — api.php's own comment on this
   branch says so) — so "never mentions an IP" would be the WRONG
   assertion here. What must hold is that the PREFERRED path (a follower
   who has a token) is genuinely token-keyed. */
if (isset($keyExpr['live_follow_poll'])) {
    $lfPollKeys = $keyExpr['live_follow_poll'];
    /* Positive 'tok:' marker only — unlike service_poll's own variable
       (named $token), live-follow.js's sibling names its variable $liveTok,
       so requiring the literal substring "token" too (service_poll's belt-
       and-braces) would reject THIS correct code over a naming difference,
       not a real absence of token-keying. */
    $hasTokenKeyed = false;
    foreach ($lfPollKeys as $k) {
        if (strpos($k, "'tok:'") !== false) { $hasTokenKeyed = true; }
    }
    rlp($hasTokenKeyed, '4.4 live_follow_poll has a per-token ("tok:") bucket among its '
        . 'checkRateLimit() key(s) (rule #26) — keys: ' . implode(' | ', $lfPollKeys));
} else {
    rlp(false, '4.4 a checkRateLimit(\'live_follow_poll\', …) call site exists to inspect');
}

/* ======================================================================== */

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
