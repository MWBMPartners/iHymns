<?php

declare(strict_types=1);

/**
 * iHymns — org brand-colour normaliser + RGB parser truth table (#1840)
 *
 * ELI5: a big table of "give it this text, expect either a clean colour or
 * an outright rejection" checks for `ihymnsOrgBrandColourNormalise()` and
 * `ihymnsOrgBrandColourRgb()` (includes/organisation_validation.php) — the
 * ONE gate every org brand-colour write goes through, and the ONE parser
 * every reader (og-image.php) uses instead of forking its own hex-parse.
 *
 * DERIVATION: every case is transcribed from
 * `.claude/org-logo-surfaces-1840-plan.md` §4.2/§8.3 — 3/6/8-digit hex,
 * case-folding, empty/null clear semantics, and "anything else -> false,
 * never stored, never echoed" (the security-load-bearing half: this is the
 * ONE place a malformed value must be caught before it could ever reach a
 * SQL bind or an HTML attribute).
 *
 * Exit status 0 = clean, 1 = at least one failure.
 *
 *   php tests/php/test-org-brand-colour.php
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/organisation_validation.php';

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

/* =============================================================================
 * ihymnsOrgBrandColourNormalise() — clear semantics.
 * ============================================================================= */

check('null input -> null (clear)', ihymnsOrgBrandColourNormalise(null) === null);
check("'' input -> null (clear)", ihymnsOrgBrandColourNormalise('') === null);
check("'   ' (whitespace only) -> null (clear, trimmed)", ihymnsOrgBrandColourNormalise('   ') === null);

/* =============================================================================
 * ihymnsOrgBrandColourNormalise() — valid shapes, 3/6/8-digit, case-folded.
 * ============================================================================= */

check("'#fff' (3-digit) widens to '#ffffff'", ihymnsOrgBrandColourNormalise('#fff') === '#ffffff');
check("'#FFF' (3-digit, uppercase) widens + lowercases to '#ffffff'", ihymnsOrgBrandColourNormalise('#FFF') === '#ffffff');
check("'#abc' widens to '#aabbcc'", ihymnsOrgBrandColourNormalise('#abc') === '#aabbcc');

check("'#1a73e8' (6-digit, already lowercase) passes through unchanged", ihymnsOrgBrandColourNormalise('#1a73e8') === '#1a73e8');
check("'#1A73E8' (6-digit, uppercase) lowercases to '#1a73e8'", ihymnsOrgBrandColourNormalise('#1A73E8') === '#1a73e8');
check("'#6A1B9A' lowercases to '#6a1b9a' (the plan's own example colour)", ihymnsOrgBrandColourNormalise('#6A1B9A') === '#6a1b9a');

check("'#1a73e8ff' (8-digit incl. alpha) passes through unchanged", ihymnsOrgBrandColourNormalise('#1a73e8ff') === '#1a73e8ff');
check("'#1A73E8FF' (8-digit, uppercase) lowercases to '#1a73e8ff'", ihymnsOrgBrandColourNormalise('#1A73E8FF') === '#1a73e8ff');

check("leading/trailing whitespace around a valid hex is trimmed first", ihymnsOrgBrandColourNormalise('  #1a73e8  ') === '#1a73e8');

/* =============================================================================
 * ihymnsOrgBrandColourNormalise() — REJECTIONS (the security-load-bearing
 * half). Every one of these must return exactly `false`, never coerce,
 * never partially accept.
 * ============================================================================= */

check("'1a73e8' (missing '#') -> false", ihymnsOrgBrandColourNormalise('1a73e8') === false);
check("'#gggggg' (non-hex characters) -> false", ihymnsOrgBrandColourNormalise('#gggggg') === false);
check("'#12345' (5 digits, invalid length) -> false", ihymnsOrgBrandColourNormalise('#12345') === false);
check("'#1234567' (7 digits, invalid length) -> false", ihymnsOrgBrandColourNormalise('#1234567') === false);
check("'#123456789' (9 digits, too long) -> false", ihymnsOrgBrandColourNormalise('#123456789') === false);
check("'red' (a CSS colour keyword, not hex) -> false", ihymnsOrgBrandColourNormalise('red') === false);
check("'rgb(26,115,232)' (a CSS function, not hex) -> false", ihymnsOrgBrandColourNormalise('rgb(26,115,232)') === false);
check("an attempted CSS/HTML injection payload -> false, never stored/echoed", ihymnsOrgBrandColourNormalise('#fff;background:url(javascript:alert(1))') === false);
check("a bare '#' with nothing after it -> false", ihymnsOrgBrandColourNormalise('#') === false);

/* =============================================================================
 * ihymnsOrgBrandColourRgb() — the ONE hex -> GD-ints parser.
 * ============================================================================= */

check("rgb('#1a73e8') == [26, 115, 232]", ihymnsOrgBrandColourRgb('#1a73e8') === [26, 115, 232]);
check("rgb('#1a73e8ff') ignores the alpha byte == [26, 115, 232]", ihymnsOrgBrandColourRgb('#1a73e8ff') === [26, 115, 232]);
check("rgb('#ffffff') == [255, 255, 255]", ihymnsOrgBrandColourRgb('#ffffff') === [255, 255, 255]);
check("rgb('#000000') == [0, 0, 0]", ihymnsOrgBrandColourRgb('#000000') === [0, 0, 0]);
check("rgb('#6a1b9a') == [106, 27, 154]", ihymnsOrgBrandColourRgb('#6a1b9a') === [106, 27, 154]);
check("rgb() is case-insensitive: '#1A73E8' == [26, 115, 232]", ihymnsOrgBrandColourRgb('#1A73E8') === [26, 115, 232]);
check("rgb() defensively widens a bare 3-digit shorthand: '#fff' == [255, 255, 255]", ihymnsOrgBrandColourRgb('#fff') === [255, 255, 255]);
check("rgb() on malformed input degrades to black [0, 0, 0], never a warning/garbage colour", ihymnsOrgBrandColourRgb('not-a-colour') === [0, 0, 0]);

/* =============================================================================
 * Summary
 * ============================================================================= */

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAIL: {$failures} assertion(s) failed.\n");
    exit(1);
}
echo "OK: all org brand-colour normaliser/RGB-parser assertions passed.\n";
exit(0);
