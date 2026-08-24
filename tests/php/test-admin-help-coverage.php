<?php

declare(strict_types=1);

/**
 * iHymns — admin nav / Help & Guides coverage guard (docs-swagger plan §6.3)
 *
 * ELI5
 * ----
 * Every page in the admin sidebar should have a matching section on the
 * in-app Help page explaining what it's for. This checks that every nav
 * destination either HAS a help section, or is named on a short reasoned
 * list explaining why it doesn't need its own (e.g. it shares a section
 * with a sibling page).
 *
 * WHY THIS EXISTS
 * ----------------
 * This is exactly how `tunes`, `venues` and `my-ccli-report` (#1861) shipped
 * with NO admin help coverage at all — nothing enforced that a new nav
 * destination gets a matching Help section, so it silently didn't. A
 * curator opening Help/Guides looking for "how do Tunes work?" found
 * nothing, with no error anywhere to point at the gap.
 *
 * WHY IT IS DERIVED, NOT A HARDCODED LIST (rule #34)
 * ----------------------------------------------------
 * Both sides are read out of their own source at run time:
 *   - nav destinations come from `manage/includes/admin-links.php`'s
 *     `$_adminLinks` registry (the SAME file `test-admin-gate-parity.php`
 *     derives from) — never a typed list here.
 *   - help sections come from `manage/help.php`'s `$sections` array's `id`
 *     keys.
 * A hardcoded list in THIS file would be a third copy of one of those two
 * registries, with the exact drift problem rule #34 exists to prevent.
 *
 * SCOPE / LIMITS (stated honestly)
 *   - Presence only: this proves a help SECTION EXISTS for a nav id (or an
 *     exemption is named), not that its prose is accurate or current.
 *   - `$ALIAS_OK` covers ids that legitimately DON'T get their own section
 *     because an existing section already documents that functionality
 *     under a different id (e.g. `external-link-types` is covered by the
 *     `external-links` section). Each entry MUST carry a reason and is
 *     itself asserted to still be true (self-cleaning, same contract as
 *     `test-openapi-actions-exist.php`'s `$EXEMPT`/`$PAGE_INTERNAL`) — an
 *     alias that stops being accurate (the sibling section is renamed or
 *     removed) fails loudly instead of silently going stale.
 *
 * Usage: php tests/php/test-admin-help-coverage.php
 * Exit 0 = pass, 1 = fail.
 */

$root   = dirname(__DIR__, 2);
$manage = $root . '/appWeb/public_html/manage';
$links  = $manage . '/includes/admin-links.php';
$help   = $manage . '/help.php';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

echo "\nAdmin nav <-> Help & Guides coverage\n\n";

if (!is_file($links)) {
    fwrite(STDERR, "FAIL: admin-links.php not found — cannot derive the nav list.\n");
    exit(1);
}
if (!is_file($help)) {
    fwrite(STDERR, "FAIL: manage/help.php not found — cannot derive the section list.\n");
    exit(1);
}

/* ---- 1. every nav destination id, from admin-links.php -------------------
   Strip block comments first (the file's own doc-block prose is not a row);
   each real row starts a line (after whitespace) with `['<id>',`. Slug chars
   are lowercase-alnum-plus-hyphen throughout the registry. */
$linksSrc = (string)file_get_contents($links);
$linksSrc = preg_replace('~/\*.*?\*/~s', '', $linksSrc) ?? $linksSrc;
preg_match_all("/^\s*\['([a-z0-9-]+)',/m", $linksSrc, $lm);
$navIds = array_values(array_unique($lm[1] ?? []));

ok('parsed a plausible number of nav destinations from admin-links.php (>= 40)',
    count($navIds) >= 40);

/* ---- 2. every help-section id, from manage/help.php's $sections array ---- */
$helpSrc = (string)file_get_contents($help);
$helpSrc = preg_replace('~/\*.*?\*/~s', '', $helpSrc) ?? $helpSrc;
preg_match_all("/'id'\s*=>\s*'([a-z0-9-]+)'/", $helpSrc, $hm);
$helpIds = array_values(array_unique($hm[1] ?? []));

ok('parsed a plausible number of help sections from manage/help.php (>= 35)',
    count($helpIds) >= 35);

/* ---- 3. every nav id needs a help section, OR a reasoned alias ----------- */

/* Each entry: nav id => [covering help-section id, reason]. The covering id
   is itself asserted to be a REAL help section below, so an alias can never
   point at a section that has since been renamed/removed without this
   guard noticing. */
$ALIAS_OK = [
    'external-link-types' => ['external-links', 'the external-links help section covers both the registry CRUD page and its /manage/external-link-types route — same feature, one write-up'],
    'service-projection'  => ['service-mode',   'covered by the Service Mode help section, which documents both broadcaster front-ends (Projector Screen + Lead a Service) together'],
    'service-lead'        => ['service-mode',   'covered by the Service Mode help section, which documents both broadcaster front-ends (Projector Screen + Lead a Service) together'],
    'feature-gating'      => ['gating',         'folded into the Content Access hub section as its own subsection — Feature Access defines the gating capability vocabulary the hub links out to'],
    'gating-noop-verify'  => ['gating',         'folded into the Content Access hub section as its own subsection — the no-op verifier is the pre-flight check for the same master switch the hub explains'],
    'intapps-status'      => ['native-api',     'folded into the Native API surface section as its own subsection — the IntAppsAPI gateway is a native/API integration, same audience as that section'],
];

/* Ids that legitimately need NO help coverage at all — not covered by a
   sibling section, because there IS no sibling; each needs its own reason
   and is asserted to still be a real, uncovered nav id (same self-cleaning
   contract, minus the "points at a real section" half since there is no
   covering section to check). */
$SELF_OK = [
    'help' => 'this nav entry IS the Help & Guides page itself — a page has no need to document itself to itself',
];

$missing = [];
foreach ($navIds as $id) {
    if (in_array($id, $helpIds, true)) { continue; }
    if (isset($ALIAS_OK[$id]) || isset($SELF_OK[$id])) { continue; }
    $missing[] = $id;
}

ok('every admin nav destination has a Help & Guides section, or a reasoned alias ('
    . count($navIds) . ' nav destinations, ' . count($helpIds) . ' help sections)',
    $missing === []);
foreach ($missing as $id) {
    echo "       admin-links.php registers '{$id}' — no manage/help.php section id\n";
    echo "       '{$id}' and no entry in \$ALIAS_OK/\$SELF_OK explaining why not\n";
}

/* Keep the alias list honest: an entry stops being valid the moment either
   (a) the nav id it excuses actually gains its own section (no longer
   needed), or (b) the section it points at stops existing (now pointing at
   nothing). Both must hold for the alias to still make sense. */
foreach ($ALIAS_OK as $id => [$coveringId, $why]) {
    ok("help-alias '{$id}' -> '{$coveringId}' is still needed ({$why})",
        in_array($id, $navIds, true)
        && !in_array($id, $helpIds, true)
        && in_array($coveringId, $helpIds, true));
}
foreach ($SELF_OK as $id => $why) {
    ok("help-self-exemption '{$id}' is still needed ({$why})",
        in_array($id, $navIds, true) && !in_array($id, $helpIds, true));
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nA nav destination with no Help & Guides coverage is invisible to the very\n";
    echo "curator who needs it — they open Help looking for an explanation and find\n";
    echo "nothing, with no error anywhere pointing at the gap. Add a manage/help.php\n";
    echo "section with that id, or add a reasoned entry to \$ALIAS_OK if an existing\n";
    echo "section already covers it under a different id.\n";
    exit(1);
}
echo "\nEvery admin nav destination has Help & Guides coverage.\n";
