<?php

declare(strict_types=1);

/**
 * iHymns — themed org-logo surface resolver truth table (#1840)
 *
 * ELI5: a big table of "given these uploaded logos, this screen, and this
 * theme, which ONE picture (if any) should show?" checks for
 * `ihymnsOrgLogoResolveThemedAsset()` — the one function every themed
 * surface (header, projector, share card) asks instead of guessing its own
 * fallback order.
 *
 * DERIVATION: every case below is transcribed directly from
 * `.claude/org-logo-surfaces-1840-plan.md` §3.2's worked consequences
 * (header/dark-fallback, projector's dark-ground ladder including the
 * monochrome-never-on-dark exclusion, og-card's darkCapableOnly skip) —
 * not a guess at what might matter. The header and og-card worked examples
 * in the plan trace byte-for-byte against the per-kind algorithm
 * implemented here; the plan's projector PROSE line orders kinds
 * differently from its own formal "per kind K, step 1 then step 2" algorithm
 * text (a narrative slip, not a second definition) — this suite follows the
 * explicit numbered algorithm, which is what the header and og-card
 * examples both independently confirm.
 *
 * Exit status 0 = clean, 1 = at least one failure.
 *
 *   php tests/php/test-org-logo-themed-resolver.php
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/org_logo_helpers.php';

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

function row(string $kind, string $variant): array
{
    return ['kind' => $kind, 'variant' => $variant];
}

/* =============================================================================
 * 0. Sanity — the registry's own shape.
 * ============================================================================= */

check('IHYMNS_ORG_LOGO_SURFACE_PREFS defines exactly the 3 documented surfaces',
    array_keys(IHYMNS_ORG_LOGO_SURFACE_PREFS) === ['header', 'projector', 'og-card']);

check("header surface's kinds are ['emblem','favicon'] in that order",
    IHYMNS_ORG_LOGO_SURFACE_PREFS['header']['kinds'] === ['emblem', 'favicon']);
check('header surface is NOT darkCapableOnly',
    IHYMNS_ORG_LOGO_SURFACE_PREFS['header']['darkCapableOnly'] === false);

check("projector surface's kinds are ['emblem','reversed','favicon'] in that order",
    IHYMNS_ORG_LOGO_SURFACE_PREFS['projector']['kinds'] === ['emblem', 'reversed', 'favicon']);
check('projector surface is NOT darkCapableOnly',
    IHYMNS_ORG_LOGO_SURFACE_PREFS['projector']['darkCapableOnly'] === false);

check("og-card surface's kinds are ['reversed','emblem'] in that order",
    IHYMNS_ORG_LOGO_SURFACE_PREFS['og-card']['kinds'] === ['reversed', 'emblem']);
check('og-card surface IS darkCapableOnly',
    IHYMNS_ORG_LOGO_SURFACE_PREFS['og-card']['darkCapableOnly'] === true);

/* 'monochrome' never appears on a dark-ground surface (projector, og-card) —
   its own registry description is "usually black", invisible on a dark
   ground, and stored bytes are never tinted. */
check("'monochrome' is absent from the projector surface's kind list",
    !in_array('monochrome', IHYMNS_ORG_LOGO_SURFACE_PREFS['projector']['kinds'], true));
check("'monochrome' is absent from the og-card surface's kind list",
    !in_array('monochrome', IHYMNS_ORG_LOGO_SURFACE_PREFS['og-card']['kinds'], true));

/* =============================================================================
 * 1. EMPTY INPUT / UNKNOWN SURFACE — always null, never throws.
 * ============================================================================= */

check('empty $available resolves to null on every surface',
    ihymnsOrgLogoResolveThemedAsset([], 'header', 'dark') === null
    && ihymnsOrgLogoResolveThemedAsset([], 'projector', 'dark') === null
    && ihymnsOrgLogoResolveThemedAsset([], 'og-card', 'dark') === null);

check("an unknown surface name resolves to null, never throws",
    ihymnsOrgLogoResolveThemedAsset([row('emblem', 'default')], 'nonexistent-surface', 'dark') === null);

check("a garbage theme string is treated as 'light', never throws",
    ihymnsOrgLogoResolveThemedAsset([row('emblem', 'default')], 'header', 'purple')
        === ['kind' => 'emblem', 'variant' => 'default']);

/* =============================================================================
 * 2. HEADER — dark theme, org has only a default emblem => default emblem
 *    shows (a reversed lockup shape is wrong for a 28px co-brand slot, so
 *    header never substitutes the 'reversed' KIND — it is absent from its
 *    kind list entirely, proven by the sanity check above).
 * ============================================================================= */

check('header/dark, only emblem@default uploaded => emblem@default (fallback, never absence)',
    ihymnsOrgLogoResolveThemedAsset([row('emblem', 'default')], 'header', 'dark')
        === ['kind' => 'emblem', 'variant' => 'default']);

check('header/dark, emblem@dark uploaded => the exact theme-paired rendition wins',
    ihymnsOrgLogoResolveThemedAsset([row('emblem', 'default'), row('emblem', 'dark')], 'header', 'dark')
        === ['kind' => 'emblem', 'variant' => 'dark']);

check('header/light, only favicon uploaded (no emblem at all) => favicon@default (ladder order)',
    ihymnsOrgLogoResolveThemedAsset([row('favicon', 'default')], 'header', 'light')
        === ['kind' => 'favicon', 'variant' => 'default']);

check('header, org has ONLY a reversed logo (no emblem/favicon at all) => null, never substituted',
    ihymnsOrgLogoResolveThemedAsset([row('reversed', 'default')], 'header', 'dark') === null);

check('header, nothing uploaded for either kind => null',
    ihymnsOrgLogoResolveThemedAsset([row('primary', 'default')], 'header', 'dark') === null);

/* =============================================================================
 * 3. PROJECTOR — theme is always 'dark' in practice (the ground is fixed
 *    #0b1020); darkCapableOnly is false here so EVERY kind's 'default' step
 *    still applies (verified against the plan's own header-shaped worked
 *    example logic, since the projector prose line's literal kind ORDER
 *    disagrees with the formal per-kind algorithm — see this file's header
 *    doc-block).
 * ============================================================================= */

check('projector/dark, only emblem@default => emblem@default',
    ihymnsOrgLogoResolveThemedAsset([row('emblem', 'default')], 'projector', 'dark')
        === ['kind' => 'emblem', 'variant' => 'default']);

check('projector/dark, emblem@dark present => the paired rendition wins over emblem@default',
    ihymnsOrgLogoResolveThemedAsset([row('emblem', 'default'), row('emblem', 'dark')], 'projector', 'dark')
        === ['kind' => 'emblem', 'variant' => 'dark']);

check('projector/dark, no emblem at all but a reversed@default logo => reversed@default (ladder falls through)',
    ihymnsOrgLogoResolveThemedAsset([row('reversed', 'default')], 'projector', 'dark')
        === ['kind' => 'reversed', 'variant' => 'default']);

check('projector/dark, no emblem/reversed but a favicon@default => favicon@default (last rung)',
    ihymnsOrgLogoResolveThemedAsset([row('favicon', 'default')], 'projector', 'dark')
        === ['kind' => 'favicon', 'variant' => 'default']);

check('projector/dark, ONLY a monochrome logo uploaded => null (monochrome never appears on a dark ground)',
    ihymnsOrgLogoResolveThemedAsset([row('monochrome', 'default')], 'projector', 'dark') === null);

check('projector/dark, nothing resolvable at all => null',
    ihymnsOrgLogoResolveThemedAsset([row('primary', 'default'), row('secondary', 'default')], 'projector', 'dark') === null);

/* =============================================================================
 * 4. OG-CARD — darkCapableOnly is TRUE: the 'default'-variant fallback step
 *    is skipped for every kind EXCEPT 'reversed'. This is the plan's own
 *    worked example, matched exactly:
 *      reversed@dark -> reversed@default -> emblem@dark -- and NOT
 *      emblem@default.
 * ============================================================================= */

check('og-card/dark, reversed@dark present => wins outright',
    ihymnsOrgLogoResolveThemedAsset(
        [row('reversed', 'dark'), row('reversed', 'default'), row('emblem', 'dark'), row('emblem', 'default')],
        'og-card', 'dark'
    ) === ['kind' => 'reversed', 'variant' => 'dark']);

check("og-card/dark, no reversed@dark but reversed@default present => reversed@default ('reversed' is the ONE kind whose default step is never skipped)",
    ihymnsOrgLogoResolveThemedAsset(
        [row('reversed', 'default'), row('emblem', 'dark'), row('emblem', 'default')],
        'og-card', 'dark'
    ) === ['kind' => 'reversed', 'variant' => 'default']);

check('og-card/dark, no reversed at all, only emblem@dark => emblem@dark (the theme-paired step still applies to every kind)',
    ihymnsOrgLogoResolveThemedAsset([row('emblem', 'dark'), row('emblem', 'default')], 'og-card', 'dark')
        === ['kind' => 'emblem', 'variant' => 'dark']);

check("og-card/dark, no reversed at all, ONLY emblem@default => null (darkCapableOnly SKIPS emblem's default step -- never emblem@default)",
    ihymnsOrgLogoResolveThemedAsset([row('emblem', 'default')], 'og-card', 'dark') === null);

check('og-card/dark, nothing at all uploaded => null (caller falls back to org-name text, per the plan)',
    ihymnsOrgLogoResolveThemedAsset([], 'og-card', 'dark') === null);

/* =============================================================================
 * Summary
 * ============================================================================= */

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAIL: {$failures} assertion(s) failed.\n");
    exit(1);
}
echo "OK: all themed org-logo surface resolver assertions passed.\n";
exit(0);
