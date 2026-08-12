<?php

declare(strict_types=1);

/**
 * iHymns — Organisation logo shared core: kind registry + validate/stage
 * unit-style tests (#1830)
 *
 * ELI5: checks the two pure/file-only halves of the #1830 write core don't
 * need a database at all to prove correct — the kind-registry ladder
 * (`ihymnsOrgLogoResolveKind()`) and the upload-validation pipeline
 * (`orgLogoValidateAndStage()`), fed real fixture files under
 * `tests/php/fixtures/org-logos/`.
 *
 *   php tests/php/test-org-logo-admin.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

$repoRoot   = dirname(__DIR__, 2);
$fixtureDir = $repoRoot . '/tests/php/fixtures/org-logos';
require_once $repoRoot . '/appWeb/public_html/includes/org_logo_helpers.php';
require_once $repoRoot . '/appWeb/public_html/includes/org_logo_admin.php';

/* =============================================================================
 * 1. THE ONE KIND REGISTRY — shape + ladder order.
 * ============================================================================= */

check('IHYMNS_ORG_LOGO_KINDS has exactly 10 kinds (the §4.2 taxonomy)', count(IHYMNS_ORG_LOGO_KINDS) === 10);
foreach (IHYMNS_ORG_LOGO_KINDS as $key => $def) {
    check("kind '$key' fits VARCHAR(20)", strlen($key) <= 20);
    check("kind '$key' has a [label, description] pair", is_array($def) && count($def) === 2 && is_string($def[0]) && is_string($def[1]));
}
$expectedLadder = ['primary', 'full', 'horizontal', 'stacked', 'emblem', 'logotype', 'secondary', 'monochrome', 'reversed', 'favicon'];
check('ihymnsOrgLogoKindKeys() returns the EXACT ladder order from the plan §4.2',
    ihymnsOrgLogoKindKeys() === $expectedLadder);

/* =============================================================================
 * 2. ihymnsOrgLogoResolveKind() — the 'auto' ladder + explicit-kind rules.
 * ============================================================================= */

check("'auto' with nothing available resolves to null (never a broken image)",
    ihymnsOrgLogoResolveKind('auto', []) === null);

check("'auto' picks the FIRST ladder kind present, skipping ones the org lacks",
    ihymnsOrgLogoResolveKind('auto', ['favicon', 'stacked', 'monochrome']) === 'stacked');

check("'auto' prefers 'primary' over every other kind when the org has it",
    ihymnsOrgLogoResolveKind('auto', ['favicon', 'primary', 'full']) === 'primary');

check("'auto' falls to 'full' when 'primary' is absent (the owner's stated ladder)",
    ihymnsOrgLogoResolveKind('auto', ['full', 'favicon']) === 'full');

check('an explicit kind the org HAS resolves to that exact kind',
    ihymnsOrgLogoResolveKind('monochrome', ['primary', 'monochrome']) === 'monochrome');

check('an explicit kind the org LACKS resolves to null — NEVER substitutes a different kind',
    ihymnsOrgLogoResolveKind('monochrome', ['primary', 'full']) === null);

check('an unknown/garbage requested kind resolves to null, not a crash',
    ihymnsOrgLogoResolveKind('not-a-real-kind', ['primary']) === null);

/* =============================================================================
 * 3. orgLogoValidateAndStage() — the §4.4 pipeline, real fixture bytes.
 * ============================================================================= */

$pngPath      = $fixtureDir . '/sample-logo.png';
$svgPath      = $fixtureDir . '/sample-logo.svg';
$badSvgPath   = $fixtureDir . '/invalid-doctype.svg';
$notImagePath = $fixtureDir . '/not-an-image.txt';

/* -- PNG survives, unchanged bytes, dimensions read via getimagesize() -- */
$pngBytes  = (string)file_get_contents($pngPath);
$pngStaged = orgLogoValidateAndStage($pngPath, strlen($pngBytes));
check('a valid PNG stages successfully', is_array($pngStaged));
check('  — mime is image/png', $pngStaged['mime'] === 'image/png');
check('  — content is stored BYTE-IDENTICAL to the upload (no re-encode, APNG-safe)', $pngStaged['content'] === $pngBytes);
check('  — originalContent is null for a raster row (no dormant copy needed)', $pngStaged['originalContent'] === null);
check('  — sanitiserVersion is 0 (not applicable to raster)', $pngStaged['sanitiserVersion'] === 0);
check('  — dimensions read from the real 16x16 fixture', $pngStaged['width'] === 16 && $pngStaged['height'] === 16);
check('  — sha256/byteSize computed over the stored bytes', $pngStaged['sha256'] === hash('sha256', $pngBytes) && $pngStaged['byteSize'] === strlen($pngBytes));

/* -- SVG survives, SANITISED bytes stored, original kept for re-sanitise -- */
$svgBytes  = (string)file_get_contents($svgPath);
$svgStaged = orgLogoValidateAndStage($svgPath, strlen($svgBytes));
check('a valid SVG stages successfully', is_array($svgStaged));
check('  — mime is image/svg+xml', $svgStaged['mime'] === 'image/svg+xml');
check('  — content is the SANITISER OUTPUT, not necessarily byte-identical to the upload', is_string($svgStaged['content']) && $svgStaged['content'] !== '');
check('  — originalContent preserves the untouched upload (dormant re-sanitise source)', $svgStaged['originalContent'] === $svgBytes);
check('  — sanitiserVersion matches the live IHYMNS_SVG_SANITISER_VERSION', $svgStaged['sanitiserVersion'] === IHYMNS_SVG_SANITISER_VERSION);
check('  — dimensions extracted from the fixture\'s viewBox (64x64)', $svgStaged['width'] === 64 && $svgStaged['height'] === 64);
check('  — the logo\'s visible text label survived sanitisation', str_contains($svgStaged['content'], 'Test'));

/* -- A DOCTYPE/XXE-shaped "SVG" is REJECTED with the plain-English message -- */
$badSvgBytes = (string)file_get_contents($badSvgPath);
$badSvgThrew = false;
$badSvgMsg   = '';
try {
    orgLogoValidateAndStage($badSvgPath, strlen($badSvgBytes));
} catch (\RuntimeException $e) {
    $badSvgThrew = true;
    $badSvgMsg   = $e->getMessage();
}
check('a DOCTYPE-bearing "SVG" throws RuntimeException (rejected, not silently stripped)', $badSvgThrew);
check('  — the message is the plain-English §7.2 reject wording (no jargon, no stack trace)',
    str_contains($badSvgMsg, "couldn't be read as a safe logo") && !str_contains($badSvgMsg, 'DOMDocument') && !str_contains($badSvgMsg, 'XXE'));

/* -- A non-image file is REJECTED with the generic plain-English message -- */
$notImageBytes = (string)file_get_contents($notImagePath);
$notImageThrew = false;
$notImageMsg   = '';
try {
    orgLogoValidateAndStage($notImagePath, strlen($notImageBytes));
} catch (\RuntimeException $e) {
    $notImageThrew = true;
    $notImageMsg   = $e->getMessage();
}
check('a plain-text (non-image) upload throws RuntimeException', $notImageThrew);
check('  — the message says logos must be SVG or PNG', str_contains($notImageMsg, 'Logos must be SVG or PNG'));

/* -- Empty / zero-byte upload rejected -- */
$emptyThrew = false;
try {
    orgLogoValidateAndStage($pngPath, 0);
} catch (\RuntimeException $e) {
    $emptyThrew = true;
}
check('a zero-byte size is rejected as an empty upload', $emptyThrew);

/* -- Oversize (beyond the LARGER of the two caps) rejected before any finfo sniff -- */
$oversizeThrew = false;
try {
    orgLogoValidateAndStage($pngPath, max(IHYMNS_ORG_LOGO_MAX_SVG_BYTES, IHYMNS_ORG_LOGO_MAX_PNG_BYTES) + 1);
} catch (\RuntimeException $e) {
    $oversizeThrew = true;
}
check('a reported size over the larger cap is rejected', $oversizeThrew);

/* -- An SVG reported larger than the SVG-specific cap is rejected even though
      the real file is tiny (the size gate trusts the CALLER's reported size,
      exactly like SongMediaStorage::validateUpload() trusts $sizeBytes). -- */
$svgOversizeThrew = false;
try {
    orgLogoValidateAndStage($svgPath, IHYMNS_ORG_LOGO_MAX_SVG_BYTES + 1);
} catch (\RuntimeException $e) {
    $svgOversizeThrew = true;
}
check('an SVG upload reported over IHYMNS_ORG_LOGO_MAX_SVG_BYTES is rejected', $svgOversizeThrew);

if ($failures) {
    echo "\nFAIL: {$failures} organisation-logo admin-core check(s) failed.\n";
    exit(1);
}
echo "\nOK: kind registry ladder + orgLogoValidateAndStage() pipeline behave exactly as specified (§4 of the plan).\n";
exit(0);
