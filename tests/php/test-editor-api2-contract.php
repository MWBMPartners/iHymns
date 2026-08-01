<?php

declare(strict_types=1);

/**
 * iHymns — v2 editor client <-> api2.php contract guard (#1677)
 *
 * ELI5
 * ----
 * The v2 editor's JavaScript asks the server to do things by name. This test
 * checks that every name it asks for is a name the server actually answers to,
 * and that its write requests carry the header the server insists on. Both are
 * things a human reading either file alone cannot see.
 *
 * WHY THIS EXISTS
 * ---------------
 * #1677: api2.php rejects every POST without `X-Requested-With: XMLHttpRequest`
 * (the #1307 same-origin CSRF gate). The v2 client sent that header on its GET
 * helper and on NEITHER of its two write helpers — so every mutation the v2
 * editor could perform returned 403, while browsing worked perfectly.
 *
 * Two properties of that bug are what this file is shaped around:
 *
 *  1. It is invisible from either side. api2.php is correct. api-client.js is
 *     plausible — it sends a CSRF token, just not the one that is checked. The
 *     defect exists only in the RELATION between them, which is the classic
 *     "two lists that must agree with nothing enforcing it" shape this codebase
 *     keeps rediscovering (event names, rate-limit pairs, entitlement maps,
 *     npm-vs-CI test lists). A comment is not a mechanism.
 *
 *  2. The gate's own comment names "editor.js ed2EnrichApi, which already sends
 *     it" — the V1 editor calling into api2. #1307 was verified against that
 *     caller. The v2 shell, the other consumer of the same endpoint, was never
 *     checked. So the failure was not carelessness; it was a correct check
 *     performed against an incomplete list of callers. Deriving BOTH sides from
 *     the tree is the only version of this test that could have caught it.
 *
 * WHAT IT ASSERTS
 *   (1) Both v2 write helpers send `X-Requested-With` — the direct #1677 guard.
 *   (2) Every action literal the client sends has a matching `case` in api2.php.
 *       A typo'd action is also invisible: it 400s at runtime with no CI signal.
 *
 * Neither side's list is written down here. Both are parsed out of the source,
 * so adding an endpoint or a client method needs no edit to this file — and
 * removing one cannot silently pass.
 *
 *   php tests/php/test-editor-api2-contract.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$root   = dirname(__DIR__, 2);
$editor = $root . '/appWeb/public_html/manage/editor';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  ❌ {$label}\n";
    }
}

echo "\n#1677 — v2 editor client <-> api2.php contract\n\n";

$clientSrc = (string)file_get_contents($editor . '/v2/api-client.js');
$apiSrc    = (string)file_get_contents($editor . '/api2.php');

/* Strip comments from the CLIENT before matching. This file's own doc-blocks
   necessarily quote `X-Requested-With` at length while explaining #1677, and a
   header named in prose is not a header sent on the wire — matching raw source
   would let the explanation satisfy the assertion it is explaining. */
$stripJs = static function (string $s): string {
    $s = preg_replace('#/\*[\s\S]*?\*/#', '', $s) ?? $s;
    return preg_replace('#(^|[^:])//.*$#m', '$1', $s) ?? $s;
};
$client = $stripJs($clientSrc);

/* ---- 1. both write helpers carry the header api2 actually checks ---------- */

/* Isolate each helper by brace-free heuristic: from its `async function NAME(`
   to the next `async function` (or EOF). Good enough and dependency-free —
   these are three short sibling helpers in one small module. */
$helperBody = static function (string $src, string $name): string {
    $start = strpos($src, 'async function ' . $name . '(');
    if ($start === false) { return ''; }
    $next = strpos($src, 'async function ', $start + 10);
    return substr($src, $start, $next === false ? strlen($src) : $next - $start);
};

foreach (['postJson', 'postForm'] as $fn) {
    $bodyText = $helperBody($client, $fn);
    check("v2 api-client {$fn}() exists", $bodyText !== '');
    check(
        "v2 api-client {$fn}() sends X-Requested-With — api2.php POSTs 403 without it (#1677)",
        $bodyText !== '' && str_contains($bodyText, 'X-Requested-With')
    );
}

/* ---------------------------------------------------------------------------
   THE ASSERTION ABOVE WAS NOT ENOUGH, AND ITS FAILURE IS THE POINT.

   `['postJson', 'postForm']` is a hardcoded two-name list inside ONE file. It
   went green for weeks while manage/editor/import2.php — a SECOND api2 POST
   client, with its own hand-written XHR and fetch — sent X-CSRF-Token and no
   X-Requested-With, so every v2 bulk import 403'd before any import logic ran
   (including the #1633 iHymns interchange format). The guard did not miss a
   subtlety; it was never looking at that file.

   That is rule #34 verbatim: derive the list from the tree, or the tick is not
   coverage. api2.php's own gate comment made the same mistake in prose — it
   names "editor.js ed2EnrichApi, which already sends it" as though enumerating
   the clients, and import2.php is simply absent from the sentence.

   So: find every file that POSTs to api2.php by SCANNING, and require each to
   send the header. A new POST client anywhere under manage/editor/ is covered
   the moment it exists.
   --------------------------------------------------------------------------- */

$api2PostClients = [];
$scanDir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($editor));
foreach ($scanDir as $entry) {
    if (!$entry->isFile()) { continue; }
    $path = $entry->getPathname();
    if (!preg_match('/\.(js|php)$/', $path)) { continue; }
    /* api2.php IS the server; save_song_core.php is included BY it. Neither is
       a browser client, and both legitimately mention the filename. */
    $base = basename($path);
    if ($base === 'api2.php' || $base === 'save_song_core.php') { continue; }

    $src = (string)file_get_contents($path);
    $noComments = $stripJs($src);

    /* A POST client = names api2.php ANYWHERE, and POSTs somewhere.
       Deliberately NOT a proximity match. The first version of this scan
       required `api2.php` within 400 chars of `method: 'POST'`, and found only
       import2.php — because v2/api-client.js builds its URL from
       `const ENDPOINT = '/manage/editor/api2.php'` (:17) and the literal never
       appears near the verb. Proximity is a heuristic about formatting; "this
       file talks to api2 and this file POSTs" is the actual property.

       The cost of the looser test is a file that mentions api2.php and happens
       to POST elsewhere getting checked too. That is a harmless extra
       assertion — and cheap next to missing a real client, which is the exact
       way this guard failed before. */
    $isPostClient =
        str_contains($noComments, 'api2.php')
        && (preg_match('#method\s*:\s*[\'"]POST[\'"]#i', $noComments)
            || preg_match('#\.open\(\s*[\'"]POST[\'"]#i', $noComments));

    if ($isPostClient) { $api2PostClients[] = $path; }
}

/* If the scan finds nothing, the regexes have rotted and every assertion below
   would vacuously pass — the "scanner that under-reports is worse than no
   scanner" failure. Fail loudly instead. */
check(
    'derived at least 2 api2.php POST clients from the tree (a scan finding none would pass vacuously)',
    count($api2PostClients) >= 2
);

foreach ($api2PostClients as $path) {
    $rel = str_replace($root . '/', '', $path);
    check(
        "{$rel} sends X-Requested-With — api2.php 403s every POST without it (#1677)",
        str_contains($stripJs((string)file_get_contents($path)), 'X-Requested-With')
    );
}

/* The server side of the same pair: assert the gate is still THERE. If a future
   change removes it, assertion (1) above would still pass while silently
   guarding nothing — so pin both ends, not just the client. */
check(
    'api2.php still gates POSTs on X-Requested-With (if this is removed, the client assertions above guard nothing)',
    /* Window is generous (300) because the 403 responder line is long — it
       carries the whole operator-facing error string. Tuned to 120 first, which
       failed on the real source: a guard's own regex is as capable of being
       subtly wrong as the code it guards, which is why every guard here gets
       mutation-tested rather than trusted because it went green. */
    (bool)preg_match('/HTTP_X_REQUESTED_WITH.{0,300}403/s', preg_replace('#/\*[\s\S]*?\*/#', '', $apiSrc) ?? $apiSrc)
);

/* ---- 2. every client action name is a real server case -------------------- */

/* Client side: the action is always the first argument to one of the three
   helpers, as a string literal. */
preg_match_all(
    "/(?:getJson|postJson|postForm)\s*\(\s*'([a-z0-9_]+)'/i",
    $client,
    $m
);
$clientActions = array_values(array_unique($m[1] ?? []));

/* Server side: `case 'name':` inside api2.php's action switch. Comments stripped
   so a case name discussed in a doc-block does not count as implemented. */
preg_match_all("/case\s+'([a-z0-9_]+)'\s*:/i", preg_replace('#/\*[\s\S]*?\*/#', '', $apiSrc) ?? $apiSrc, $m2);
$serverActions = array_values(array_unique($m2[1] ?? []));

check('parsed a plausible number of client actions (>= 20)', count($clientActions) >= 20);
check('parsed a plausible number of server cases (>= 20)', count($serverActions) >= 20);

$orphans = array_values(array_diff($clientActions, $serverActions));
check(
    'every action the v2 client calls has a matching case in api2.php ('
        . count($clientActions) . ' client actions checked)',
    $orphans === []
);
if ($orphans !== []) {
    foreach ($orphans as $o) {
        echo "       client calls '{$o}' — no `case '{$o}':` in api2.php (would 400 at runtime)\n";
    }
}

/* Deliberately NOT asserted in reverse: api2.php legitimately serves actions no
   v2 client method calls yet — the line-enrichment endpoints are the live
   example (#1627: v1's panel is currently their only UI consumer). Failing on
   server-only actions would punish exactly the parity work epic #1601 exists to
   do. Orphaned endpoints are tracked as issues, not as build failures. */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nThe v2 editor and api2.php must agree on BOTH the same-origin header and\n";
    echo "the action vocabulary. Fix the CLIENT — never weaken api2's POST gate,\n";
    echo "which is rule #29's named regression class.\n";
    exit(1);
}
echo "\nAll v2 editor contract assertions passed.\n";
