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

/* ---- 3. the reverse, but ONLY for the PUBLIC dispatcher ------------------- */

/* This file used to end here, with a note explaining that the reverse is
   deliberately not asserted: plenty of real actions are internal, and failing on
   those would push people to document internals just to get the build green.
   That reasoning is right, and it is preserved — but it was about ALL FOUR
   dispatchers (260 actions, most of them editor/admin internals).

   Scoped to the PUBLIC api.php alone the picture is different, and was measured
   before this assertion was written (2026-08-01): every one of its actions is
   already documented, so this locks in a property the code has today rather than
   demanding new work. It matters because api.php IS the native-app contract —
   iOS/iPadOS/tvOS and Android read this spec to discover what the server offers.
   An undocumented public action is invisible to them: not broken, just never
   built against. That is the silent-no-op shape this codebase keeps paying for,
   one step earlier in the chain.

   The editor + places dispatchers are still deliberately exempt from this
   direction — they are internal surfaces, and the original reasoning stands. */

$publicActions = $casesForSwitch($pub . '/api.php', '$action');

/* Same self-cleaning contract as $EXEMPT above: an entry must say WHY, and is
   itself asserted to still be necessary, so the list cannot quietly become a
   dumping ground for things somebody could not be bothered to document. */
$UNDOCUMENTED_OK = [
    /* (empty — every public action is documented, and the assertion below is
       what keeps it that way. Add an entry ONLY for an action that genuinely
       must not appear in the published contract, with the reason.) */
];

$undocumented = [];
foreach ($publicActions as $a) {
    if (in_array($a, $documented, true) || isset($UNDOCUMENTED_OK[$a])) { continue; }
    $undocumented[] = $a;
}

ok('every PUBLIC api.php action is documented in api-docs.yaml ('
    . count($publicActions) . ' public actions)',
    $undocumented === []);
foreach ($undocumented as $a) {
    echo "       api.php dispatches ?action={$a} — absent from api-docs.yaml;\n";
    echo "       a native-app developer reading the spec cannot discover it\n";
}

foreach ($UNDOCUMENTED_OK as $a => $why) {
    ok("undocumented-exemption '{$a}' is still needed ({$why})",
        in_array($a, $publicActions, true) && !in_array($a, $documented, true));
}

/* ---- 3. api2 (v2 editor) per-action coverage — #1201 breakout landed --------

   HISTORY (why this used to be narrow): before #1201 this guarded ONLY the five
   #1608 song-link actions and explicitly deferred the rest, because api2.php's
   ~50 actions were documented as a single PROSE block and a blanket reverse
   guard ("every api2 action must be documented") would have failed on ~45
   still-undocumented actions — rule #34's other failure mode, the guard so blunt
   it flags correct code, which gets weakened or deleted. The old check therefore
   asserted only that those 5 were MENTIONED in the spec, and said "the full
   per-action OpenAPI breakout is tracked as #1201".

   #1201 landed that breakout: every api2 action is now its own
   `/manage/editor/api2.php?action=…` PATH ITEM (not prose). So the blanket
   reverse guard is now CORRECT rather than too-blunt — it locks the breakout in
   and goes red the moment a NEW api2 action ships without a per-action doc entry
   (the silent-native-contract-gap shape this file exists to catch, one step
   earlier). This strictly SUPERSEDES the old "mentioned in prose" check with the
   stronger "is a real path item" check.

   Tree-derived (rule #34): the required set is the dispatcher's OWN cases via the
   shared parser, never a hand list. The phantom direction (a documented api2
   action that does not exist) is already covered by section 2 above, whose regex
   now sees the api2 path items too. */

$api2Actions = $casesForSwitch($pub . '/manage/editor/api2.php', '$action');

/* Per-action path items for api2 — top-level path keys only (two-space indent),
   NOT prose/description mentions. */
preg_match_all('#^  /manage/editor/api2\.php\?action=([a-z0-9_]+):#mi', $yaml, $m2);
$api2Documented = array_values(array_unique($m2[1] ?? []));

ok('api2 per-action breakout present as path items (>= 45 — #1201)',
    count($api2Documented) >= 45);

/* Self-cleaning exemption, same contract as the lists above: an api2 action
   deliberately kept OUT of the published spec must say why, and is asserted to
   still exist AND still be undocumented, so the list cannot quietly rot into a
   dumping ground. Empty today — #1201 documented every api2 action per-action. */
$API2_UNDOCUMENTED_OK = [
    /* (empty — every api2 action has a per-action path item. Add an entry ONLY
       for an action that genuinely must not appear in the spec, with a reason.) */
];

$api2Undoc = [];
foreach ($api2Actions as $a) {
    if (in_array($a, $api2Documented, true) || isset($API2_UNDOCUMENTED_OK[$a])) { continue; }
    $api2Undoc[] = $a;
}
ok('every api2 (v2 editor) action has a per-action OpenAPI path item ('
    . count($api2Actions) . ' actions, ' . count($api2Documented) . ' documented) — #1201',
    $api2Undoc === []);
foreach ($api2Undoc as $a) {
    echo "       manage/editor/api2.php dispatches ?action={$a} — no per-action path item in api-docs.yaml (#1201 breakout gap)\n";
}
foreach ($API2_UNDOCUMENTED_OK as $a => $why) {
    ok("api2 undocumented-exemption '{$a}' is still needed ({$why})",
        in_array($a, $api2Actions, true) && !in_array($a, $api2Documented, true));
}

/* ---- 4. api-docs.yaml <-> infoAppVer.php version lockstep (docs-swagger
       plan §6.1, CLAUDE.md rule #35 — "cross-file agreement needs a
       mechanism, not a comment") ------------------------------------------

   Two files independently state the app version: `info.version` in the
   spec, and `Version.Number` in infoAppVer.php. Nothing enforced they
   agree until now — and `version-bump.yml` (the ONLY thing that writes
   the version on every alpha/beta push) used to touch infoAppVer.php
   alone. This assertion is what earns the right to exist: the SAME
   commit that adds it also adds a "Update version in api-docs.yaml" step
   to version-bump.yml, so a routine bump keeps both files in lockstep
   instead of turning this check red on the very next push (rule #34 —
   a guard that fails on correct process gets deleted, not fixed). */

$infoAppVerSrc = (string)file_get_contents($pub . '/includes/infoAppVer.php');
preg_match('/\["Version"\]\["Number"\]\s*=\s*"([^"]+)"/', $infoAppVerSrc, $vm);
$appVersion = $vm[1] ?? null;

preg_match('/^  version:\s*"([^"]+)"/m', $yaml, $vm2);
$specVersion = $vm2[1] ?? null;

ok('infoAppVer.php Version.Number is parseable', $appVersion !== null);
ok('api-docs.yaml info.version is parseable', $specVersion !== null);
ok("api-docs.yaml info.version ({$specVersion}) matches infoAppVer.php Version.Number ({$appVersion})",
    $appVersion !== null && $specVersion !== null && $appVersion === $specVersion);

/* ---- 5. every $page case must be documented, or carry a reasoned
       exemption (docs-swagger plan §6.2) -----------------------------------

   Mirrors section 2/3 above but for the PAGE router, which had NO presence
   check at all before this — an entire dispatch surface (SPA HTML-fragment
   hydration) could drift silently in either direction. Both directions are
   asserted, with the SAME self-cleaning exemption contract used throughout
   this file: an entry that stops being true (documented, or no longer a
   real case) fails its own assertion, so the list cannot rot into a place
   to hide things. */

$pageCasesReal = $casesForSwitch($pub . '/api.php', '$page');

preg_match_all('#^  /api\.php\?page=([a-z0-9_-]+):#m', $yaml, $pm);
$pageDocumented = array_values(array_unique($pm[1] ?? []));

ok('parsed a plausible number of documented ?page= routes from api-docs.yaml (>= 15)',
    count($pageDocumented) >= 15);

/* Phantom direction (documented but not a real case): `person`/`people` are
   PRE-SWITCH aliases — api.php normalises $page to 'musician' before the
   switch even runs (line ~440), so they can never be real case labels, yet
   are deliberately documented (deprecated, pointing at the canonical
   `musician` entry). That is a real, reasoned exception — not a phantom —
   so it gets the same named-exemption treatment as `health` in section 2. */
$PAGE_PHANTOM_OK = [
    'person' => 'pre-switch alias normalised to musician before the $page switch runs (api.php ~line 440) — never a real case label, kept for back-compat links',
];
$pagePhantom = [];
foreach ($pageDocumented as $p) {
    if (in_array($p, $pageCasesReal, true) || isset($PAGE_PHANTOM_OK[$p])) { continue; }
    $pagePhantom[] = $p;
}
ok('every documented ?page= route is a real $page case or a reasoned alias ('
    . count($pageDocumented) . ' documented)', $pagePhantom === []);
foreach ($pagePhantom as $p) {
    echo "       api-docs.yaml documents ?page={$p} — no such \$page case; it would 404\n";
}
foreach ($PAGE_PHANTOM_OK as $p => $why) {
    ok("page-phantom-exemption '{$p}' is still needed ({$why})",
        !in_array($p, $pageCasesReal, true) && in_array($p, $pageDocumented, true));
}

/* Reverse direction (real but undocumented): every $page case is either a
   documented path item, or named in $PAGE_INTERNAL with a reason. These are
   all SPA-internal HTML fragments consumed only by router.js's own fetch —
   never a JSON contract, never linked to from outside the SPA shell — so
   documenting them as individual OpenAPI paths would be presence-only noise
   with no parameter contract worth publishing (docs-swagger plan §3.3-1). */
$PAGE_INTERNAL = [
    'songbooks'      => 'SPA-internal HTML fragment (songbook list) — no external consumer',
    'songbook'       => 'SPA-internal HTML fragment — no external consumer',
    'song'           => 'SPA-internal HTML fragment — the JSON twin (song_detail/song_data) is the published contract for this data',
    'search'         => 'SPA-internal HTML fragment — no external consumer',
    'favorites'      => 'SPA-internal HTML fragment, auth-required user-specific view',
    'setlist'        => 'SPA-internal HTML fragment, auth-required user-specific view',
    'setlist-shared' => 'SPA-internal HTML fragment for a shared set-list link',
    'link'           => 'SPA-internal HTML fragment — RFC 8628 device-pairing approval screen, auth-required user-specific view',
    'stats'          => 'SPA-internal HTML fragment, auth-required user-specific view',
    'writer'         => 'forever-kept alias (rule #33) rendering the SAME fragment as the documented ?page=musician — no independent contract of its own',
    'tag'            => 'SPA-internal HTML fragment (theme page) — no external consumer',
    'request-a-song' => 'alias of ?page=request, documented inside that path item\'s `page` parameter enum rather than as a second path item',
];

$pageUndocumented = [];
foreach ($pageCasesReal as $p) {
    if (in_array($p, $pageDocumented, true) || isset($PAGE_INTERNAL[$p])) { continue; }
    $pageUndocumented[] = $p;
}
ok('every $page case is documented or has a reasoned exemption in $PAGE_INTERNAL ('
    . count($pageCasesReal) . ' real page cases, ' . count($pageDocumented) . ' documented)',
    $pageUndocumented === []);
foreach ($pageUndocumented as $p) {
    echo "       api.php dispatches ?page={$p} — absent from api-docs.yaml and from \$PAGE_INTERNAL\n";
}
foreach ($PAGE_INTERNAL as $p => $why) {
    ok("page-internal-exemption '{$p}' is still needed ({$why})",
        in_array($p, $pageCasesReal, true) && !in_array($p, $pageDocumented, true));
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nBoth directions of this file matter, and they fail differently.\n\n";
    echo "DOCUMENTED BUT ABSENT is the loud one: Swagger UI's try-it-out fails in an\n";
    echo "admin's face, and an external integrator ships broken code with our own spec\n";
    echo "as evidence it should have worked.\n\n";
    echo "PRESENT BUT UNDOCUMENTED is the quiet one, and it is the reason the second\n";
    echo "check exists: api.php IS the native-app contract. iOS/iPadOS/tvOS and Android\n";
    echo "read api-docs.yaml to discover what the server offers, so an undocumented\n";
    echo "public action is not broken — it is invisible, and simply never gets built\n";
    echo "against. Either document it, or add it to \$UNDOCUMENTED_OK with a reason.\n";
    exit(1);
}
echo "\nAll documented API actions exist.\n";
