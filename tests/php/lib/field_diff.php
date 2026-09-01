<?php

declare(strict_types=1);

/**
 * iHymns — shared "first structural diff" helper (tests only) (#1129)
 * =====================================================================
 *
 * ELI5
 * ----
 * When a test compares two big nested arrays and they don't match, printing both
 * whole arrays is useless — you have to hunt for the one value that's different.
 * This file walks two arrays together and tells you exactly WHERE they first
 * disagree ("$.components[1].chords (got '', expected 'C G')"), so a failure is
 * something you can act on immediately.
 *
 * DETAILED
 * --------
 * Extracted verbatim (rule #35 / modularity rule: don't duplicate a working
 * helper) from `tests/php/test-pp7-roundtrip.php`'s locally-named
 * `pp7RoundtripFirstDiffPath()` (:175-198), which is itself a sibling of
 * `test-pp7-parse.php`'s identically-shaped local helper. Both of those stay as
 * they are — this extraction is for NEW consumers (starting with
 * `tests/php/test-interchange-roundtrip.php`) so a third and fourth copy don't
 * grow independently; the two existing pp7 tests are deliberately left
 * untouched (no churn) per this task's brief.
 *
 * `tools/run-php-tests.php` runs each `tests/php/*.php` suite in its own PHP
 * subprocess (no shared global namespace across sibling test files — see that
 * runner's own doc-block), so this shared function name cannot collide with the
 * two pre-existing local copies even though nothing here enforces that
 * structurally; it's a fact about the test runner, not this file.
 *
 * @see tests/php/test-pp7-roundtrip.php:175-198   the original this was extracted from
 * @see tests/php/test-interchange-roundtrip.php   the first consumer
 * @see https://www.php.net/manual/en/function.var-export.php
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/**
 * Find the first point at which two already-decoded PHP values disagree, as a
 * human-readable dotted/bracketed path string, or null if they are identical
 * (recursively, via `===`, so type mismatches — e.g. int 0 vs string '0' — count
 * as a difference, matching PHP's own strict-comparison semantics and this
 * codebase's "never a loose ==" convention).
 *
 * @param mixed  $a    "got" (typically the freshly re-imported value)
 * @param mixed  $b    "expected" (typically the committed expected dict)
 * @param string $path the path accumulated so far — callers normally omit this
 * @return ?string a path like "$.components[1].chords (got X, expected Y)", or
 *                  null when $a and $b are structurally identical
 */
function ihymnsFieldDiffFirstPath($a, $b, string $path = '$'): ?string
{
    if (is_array($a) && is_array($b)) {
        $keysA = array_keys($a);
        $keysB = array_keys($b);
        if ($keysA !== $keysB) {
            return "{$path} (keys differ: [" . implode(',', $keysA) . '] vs [' . implode(',', $keysB) . '])';
        }
        foreach ($a as $k => $v) {
            $sub = is_int($k) ? "{$path}[{$k}]" : "{$path}.{$k}";
            $diff = ihymnsFieldDiffFirstPath($v, $b[$k], $sub);
            if ($diff !== null) {
                return $diff;
            }
        }
        return null;
    }
    if ($a === $b) {
        return null;
    }
    $av = is_string($a) && strlen($a) > 160 ? substr($a, 0, 160) . '…' : var_export($a, true);
    $bv = is_string($b) && strlen($b) > 160 ? substr($b, 0, 160) . '…' : var_export($b, true);
    return "{$path} (got {$av}, expected {$bv})";
}
