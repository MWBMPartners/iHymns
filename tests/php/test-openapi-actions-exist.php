<?php

declare(strict_types=1);

/**
 * iHymns — every documented API action must actually exist (orphan inventory §6.1)
 *
 * ELI5
 * ----
 * Our public API documentation lists the things the API can do. This checks that
 * each one is a thing the code actually answers to — so nobody writes an
 * integration against a page in the docs that was never real.
 *
 * WHY THIS EXISTS
 * ---------------
 * The mechanically-derived orphan audit (`.claude/orphan-inventory-2026-07-30.md`
 * §6.1) diffed the 216 `action=` paths in `api-docs.yaml` against the 275 `case`
 * labels in the dispatch files and found **four documented endpoints that do not
 * exist**: `action=song`, `action=writer`, `action=songbook`, `action=setlist`.
 * All four fell through to the default branch and returned **400 Unknown action**.
 *
 * The trap is worth understanding, because it is why four people missed it.
 * `api.php` has TWO switches:
 *
 *     line 563   switch ($page)      <- `?page=song` renders an HTML fragment
 *     line 763   switch ($action)    <- `?action=song_detail` returns JSON
 *
 * `case 'song':` exists — in the PAGE switch. So a `grep "case 'song'"` finds it
 * and appears to confirm the endpoint is real. It confirms nothing of the kind.
 * Only the position of the case relative to its switch tells you which dispatcher
 * owns it, which is why this test parses rather than greps.
 *
 * WHO THIS HURTS
 * --------------
 * Worse than an internal orphan. `/manage/api-docs` ships Swagger UI with
 * try-it-out enabled, so every click on one of these fails in an admin's face.
 * And an external integrator coding against the published spec ships something
 * broken, with our documentation as their evidence that it should have worked.
 *
 *   php tests/php/test-openapi-actions-exist.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$root = dirname(__DIR__, 2);
$pub  = $root . '/appWeb/public_html';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

echo "\nOpenAPI: every documented action must exist\n\n";

/**
 * The tokenising switch-walker that used to live here now lives in
 * `tests/php/lib/dispatch_parser.php`, because `test-orphan-inventory.php`
 * needs the identical answer to "which actions does the server dispatch?".
 * Two copies of that parser would be two answers (rule #35 — cross-file
 * agreement needs a mechanism, not a comment); the brace-depth trap and the
 * $page-vs-$action incident that motivated it are documented in full there.
 *
 * The positive controls below are unchanged and still assert, from this file,
 * that the shared parser distinguishes the two switches correctly.
 */
$casesForSwitch = static fn (string $f, string $v): array
    => dispatchParserCasesForSwitch($f, $v);

/* ---- 1. build the set of REAL actions, from every dispatch file ---------- */

$dispatchers = [
    $pub . '/api.php'                     => '$action',
    $pub . '/manage/editor/api.php'       => '$action',
    $pub . '/manage/editor/api2.php'      => '$action',
    $pub . '/manage/places-api.php'       => '$action',
];

$realActions = [];
foreach ($dispatchers as $file => $var) {
    if (!is_file($file)) { continue; }
    foreach ($casesForSwitch($file, $var) as $c) { $realActions[$c] = true; }
}

ok('parsed a plausible number of real actions from the dispatchers (>= 200)',
    count($realActions) >= 200);

/* Positive control: the tokenizer must NOT attribute the $page switch's labels
   to $action. If this fails, the parser is not doing what the doc-block claims
   and every result below is meaningless. */
$pageCases = $casesForSwitch($pub . '/api.php', '$page');
ok("control: 'song' is a \$page case (proving the two switches are distinguished)",
    in_array('song', $pageCases, true));
ok("control: 'song' is NOT a \$action case (this is exactly what §6.1 found)",
    !isset($realActions['song']));
ok("control: 'song_detail' IS a \$action case (the real JSON endpoint)",
    isset($realActions['song_detail']));

/* ---- 2. every action the OpenAPI spec documents must be real ------------- */

$yaml = (string)file_get_contents($pub . '/api-docs.yaml');

/* Only top-level path keys — two spaces, then the path. Matching `action=`
   anywhere would also hit prose in descriptions and the enum values inside
   parameter schemas. */
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_values(array_unique($m[1] ?? []));

ok('parsed a plausible number of documented actions from api-docs.yaml (>= 150)',
    count($documented) >= 150);

/* Deliberate exemptions — documented, but not dispatched by a switch. Each MUST
   carry a reason; an unexplained entry here is how a real phantom hides. */
$EXEMPT = [
    'health' => 'real, but handled before the switch (early-return liveness probe)',
];

$phantom = [];
foreach ($documented as $a) {
    if (isset($realActions[$a]) || isset($EXEMPT[$a])) { continue; }
    $phantom[] = $a;
}

ok('every action documented in api-docs.yaml exists in a dispatcher ('
    . count($documented) . ' documented, ' . count($realActions) . ' real)',
    $phantom === []);
foreach ($phantom as $a) {
    echo "       api-docs.yaml documents ?action={$a} — no dispatcher case; it would 400\n";
}

/* Keep the exemption list honest: an entry that is no longer needed must be
   removed, or the list slowly becomes a place to hide things. */
foreach ($EXEMPT as $a => $why) {
    ok("exemption '{$a}' is still needed ({$why})",
        in_array($a, $documented, true) && !isset($realActions[$a]));
}

/* Deliberately NOT asserted in reverse. Plenty of real actions are internal and
   need no public documentation, and failing on those would push people to
   document internals just to get the build green. The orphan guard covers
   actions-without-callers; this file only guarantees the published contract is
   honest. */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nA documented endpoint that does not exist is worse than an undocumented one:\n";
    echo "Swagger UI's try-it-out fails in an admin's face, and an external integrator\n";
    echo "ships broken code with our own spec as evidence it should have worked.\n";
    exit(1);
}
echo "\nAll documented API actions exist.\n";
