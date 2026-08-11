<?php

declare(strict_types=1);

/**
 * iHymns — Licence-type admin CRUD core guard (#459 / #1769 P4)
 * =============================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * P4 gives curators a write path over the licence vocabulary via the shared core
 * `includes/licence_type_admin.php` (consumed by BOTH `/manage/licence-types` and
 * the `admin_licence_type_*` API — rule #22/#35, no re-fork). This guard pins the
 * core's promises that are cheap to break and expensive to notice:
 *   1. `licenceTypeAdminValidate()` — the validation truth table AND the
 *      LicenceKey-is-immutable-on-update rule (the tier-Name precedent).
 *   2. `licenceTypeAdminCoversMerge()` — a `{}` (unqualified) coverage kind stays
 *      `{}` across a round-trip and a real qualifier object is preserved verbatim.
 *   3. delete policy — refuses a `Source='system'` seed and refuses a
 *      still-referenced key (the Enabled-column app-enforced-retire promise), and
 *      the reference counts are INFORMATION_SCHEMA-gated (rule #9).
 *
 * WHY BEHAVIOURAL WHERE IT CAN BE (rule #34)
 * ------------------------------------------
 * The two pure-enough functions (`…CoversMerge` is pure; `…Validate` touches the
 * DB only in its non-empty `confers_tier` branch) are exercised as REAL calls
 * against small in-memory fixtures — proven in both directions — using an
 * unconnected `mysqli` for the paths that never reach it. CI has no MySQL, so the
 * create/update/toggle/delete round-trips (which all issue statements) are pinned
 * by a mutation-proven SOURCE-STRUCTURE scan of the one file where the logic
 * runs, not a live DB round-trip. `mysqli_report(MYSQLI_REPORT_OFF)` is set so an
 * unconnected handle degrades to false rather than throwing under PHP 8.1+'s
 * STRICT default, and no fixture case reaches a statement anyway.
 *
 * Pure require of `includes/licence_type_admin.php` (defines functions only; its
 * `require db_mysql.php` does NOT connect at include time) + a source scan. No DB.
 *
 *   php tests/php/test-licence-type-admin.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 *
 * @see appWeb/public_html/includes/licence_type_admin.php  the core under guard
 * @see appWeb/public_html/manage/licence-types.php         the page consumer
 * @see appWeb/public_html/api.php                          admin_licence_type_* API consumer
 * @see tests/php/test-gating-admin-gates.php               the API-gate half of P4
 * @see .claude/gating-p4-design.md §"Commit A"
 */

/* PHP 8.1+ defaults mysqli reporting to STRICT (throws). Turn it OFF here so an
   unconnected handle used only for the no-DB validate paths degrades to false
   instead of throwing — none of the fixture cases actually reach a statement. */
mysqli_report(MYSQLI_REPORT_OFF);

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';
$coreFile = $pub . '/includes/licence_type_admin.php';

if (!is_readable($coreFile)) {
    fwrite(STDERR, "FATAL: could not read {$coreFile}\n");
    exit(1);
}

require_once $coreFile;

$failures         = [];
$mutationFailures = [];

/* =========================================================================
 * SOURCE-SLICER (mutation-proven) — used by the delete-policy structure scan.
 * ========================================================================= */

/** Slice a top-level `function NAME(...) { ... }` region (to the next
 *  column-0 `function ` or EOF). */
function ltaSliceFunction(string $src, string $name): string
{
    if (!preg_match(
        '/^function\s+' . preg_quote($name, '/') . '\s*\(.*?(?=\n^function\s|\z)/ms',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/** Strip PHP comments/doc-blocks via the tokenizer so a CODE assertion can never
 *  be satisfied by a symbol NAMED in a comment (this file's own annotations name
 *  the very symbols it asserts on). */
function ltaStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/* --- mutation self-tests for the slicer/stripper (rule #34) --- */
$fixtureFnSrc = "function alpha(): void\n{\n    \$x = 'NeedleAlpha';\n}\n\nfunction beta(): void\n{\n    \$x = 'NeedleBeta';\n}\n";
$alphaSlice = ltaSliceFunction($fixtureFnSrc, 'alpha');
if (strpos($alphaSlice, 'NeedleAlpha') === false) {
    $mutationFailures[] = 'ltaSliceFunction() FAILS-HIGH: did not find the needle in its own fixture function';
}
if (strpos($alphaSlice, 'NeedleBeta') !== false) {
    $mutationFailures[] = "ltaSliceFunction() FAILS-LOW: alpha()'s slice bled into beta()";
}
$stripFixture = "<?php\n// NeedleInComment\n\$x = 'NeedleInCode';\n/* NeedleInBlock */\n";
$stripped = ltaStripComments($stripFixture);
if (strpos($stripped, 'NeedleInCode') === false) {
    $mutationFailures[] = "ltaStripComments() FAILS-HIGH: removed the code string 'NeedleInCode'";
}
if (strpos($stripped, 'NeedleInComment') !== false || strpos($stripped, 'NeedleInBlock') !== false) {
    $mutationFailures[] = 'ltaStripComments() FAILS-LOW: kept a comment string';
}

/* =========================================================================
 * BEHAVIOURAL — licenceTypeAdminCoversMerge() (pure).
 * ========================================================================= */

$assertJson = static function (string $label, ?string $got, ?string $want) use (&$failures): void {
    if ($got !== $want) {
        $failures[] = "coversMerge: {$label} — got " . var_export($got, true) . ", expected " . var_export($want, true);
    }
};

/* New kinds → {} each; order follows the ticked list. */
$assertJson(
    'new kinds get {} qualifiers',
    licenceTypeAdminCoversMerge(null, ['lyrics_display', 'lyrics_print']),
    '{"lyrics_display":{},"lyrics_print":{}}'
);
/* A stored `{}` decodes (assoc) to [] — must re-encode as {}, NOT [] (the flip
   this function's normalisation exists to prevent). */
$assertJson(
    'unqualified {} survives a round-trip as {} (not [])',
    licenceTypeAdminCoversMerge(['lyrics_display' => []], ['lyrics_display']),
    '{"lyrics_display":{}}'
);
/* A real qualifier object is preserved verbatim. */
$assertJson(
    'non-empty qualifier object is preserved',
    licenceTypeAdminCoversMerge(['lyrics_display' => ['region' => 'US']], ['lyrics_display']),
    '{"lyrics_display":{"region":"US"}}'
);
/* Dropping a kind removes it; the kept kind keeps its qualifier. */
$assertJson(
    'un-ticking a kind drops it, keeps the other qualifier',
    licenceTypeAdminCoversMerge(['lyrics_display' => ['region' => 'US'], 'lyrics_print' => []], ['lyrics_display']),
    '{"lyrics_display":{"region":"US"}}'
);
/* An unknown coverage kind is filtered out (never trusted from the editor). */
$assertJson(
    'unknown coverage kind is filtered out',
    licenceTypeAdminCoversMerge(null, ['lyrics_display', 'not_a_real_kind']),
    '{"lyrics_display":{}}'
);
/* No ticked kinds → NULL (no coverage). */
$assertJson('no ticked kinds → null', licenceTypeAdminCoversMerge(null, []), null);
$assertJson('only-invalid ticked kinds → null', licenceTypeAdminCoversMerge(null, ['bogus']), null);

/* =========================================================================
 * BEHAVIOURAL — licenceTypeAdminValidate() no-DB paths.
 * Every fixture leaves confers_tier '' so the DB branch is never reached; the
 * unconnected handle is only a type-satisfying placeholder.
 * ========================================================================= */

$db = mysqli_init();  /* unconnected — never used by any fixture below */

$assertValid = static function (string $label, array $f, bool $isCreate, callable $check) use ($db, &$failures): void {
    $r = licenceTypeAdminValidate($db, $f, $isCreate);
    $msg = $check($r);
    if ($msg !== '') {
        $failures[] = "validate: {$label} — {$msg}";
    }
};

/* A clean create with no tier conferral validates with zero errors and carries
   the normalised key. */
$assertValid('clean create is error-free', [
    'key' => 'my_licence', 'label' => 'My Licence', 'description' => 'A test.',
    'confers_tier' => '', 'covers' => ['lyrics_display'], 'requires_number' => '1',
    'authority' => 'ccli', 'authority_ref' => 'REF1', 'sort_order' => '5',
], true, static function (array $r): string {
    if ($r['errors'] !== []) { return 'expected no errors, got: ' . implode(' | ', $r['errors']); }
    if (($r['norm']['key'] ?? null) !== 'my_licence') { return "norm.key should be 'my_licence'"; }
    if (($r['norm']['requires_number'] ?? null) !== 1) { return 'requires_number should normalise to int 1'; }
    if (($r['norm']['enabled'] ?? null) !== 1) { return 'enabled should default to 1 when the key is absent'; }
    if (($r['norm']['sort_order'] ?? null) !== 5) { return 'sort_order should normalise to int 5'; }
    if (($r['norm']['covers'] ?? null) !== ['lyrics_display']) { return 'covers should keep the one valid kind'; }
    return '';
});

/* Key is validated + REQUIRED on create; a bad key is rejected. */
$assertValid('empty key on create is rejected', [
    'key' => '', 'label' => 'X', 'confers_tier' => '',
], true, static fn(array $r): string => $r['errors'] === [] ? 'expected an error for an empty key' : '');
$assertValid('malformed key on create is rejected', [
    'key' => '1bad-KEY!', 'label' => 'X', 'confers_tier' => '',
], true, static fn(array $r): string => $r['errors'] === [] ? 'expected an error for a malformed key' : '');

/* KEY IMMUTABILITY: on update the key is NEVER read/validated/normalised, no
   matter what is posted. norm must not carry a 'key'. */
$assertValid('update ignores the key entirely (immutable)', [
    'key' => 'attempted_rekey', 'label' => 'X', 'confers_tier' => '',
], false, static function (array $r): string {
    if (array_key_exists('key', $r['norm'])) { return 'norm should NOT contain key on update (LicenceKey is immutable)'; }
    if ($r['errors'] !== []) { return 'a valid update body should have no errors; got: ' . implode(' | ', $r['errors']); }
    return '';
});

/* Label required + length-bounded. */
$assertValid('empty label is rejected', [
    'key' => 'k', 'label' => '   ', 'confers_tier' => '',
], true, static fn(array $r): string => $r['errors'] === [] ? 'expected an error for an empty label' : '');
$assertValid('over-long label is rejected', [
    'key' => 'k2', 'label' => str_repeat('x', 101), 'confers_tier' => '',
], true, static fn(array $r): string => $r['errors'] === [] ? 'expected an error for a >100-char label' : '');

/* Description length-bounded (>255). */
$assertValid('over-long description is rejected', [
    'key' => 'k3', 'label' => 'OK', 'description' => str_repeat('d', 256), 'confers_tier' => '',
], true, static fn(array $r): string => $r['errors'] === [] ? 'expected an error for a >255-char description' : '');

/* Unknown coverage kind is an error (and filtered from norm.covers). */
$assertValid('unknown coverage kind is rejected', [
    'key' => 'k4', 'label' => 'OK', 'confers_tier' => '', 'covers' => ['lyrics_display', 'nope'],
], true, static function (array $r): string {
    if ($r['errors'] === []) { return 'expected an error for an unknown coverage kind'; }
    if (($r['norm']['covers'] ?? null) !== ['lyrics_display']) { return 'norm.covers should drop the unknown kind'; }
    return '';
});

/* Authority must be a lowercase token or blank. */
$assertValid('bad authority token is rejected', [
    'key' => 'k5', 'label' => 'OK', 'confers_tier' => '', 'authority' => 'Not A Token',
], true, static fn(array $r): string => $r['errors'] === [] ? 'expected an error for a non-token authority' : '');
$assertValid('blank authority normalises to null', [
    'key' => 'k6', 'label' => 'OK', 'confers_tier' => '', 'authority' => '',
], true, static function (array $r): string {
    if ($r['errors'] !== []) { return 'blank authority should be valid; got: ' . implode(' | ', $r['errors']); }
    if (!array_key_exists('authority', $r['norm']) || $r['norm']['authority'] !== null) { return 'blank authority should normalise to null'; }
    return '';
});

/* enabled: present-but-falsy → 0, present-and-truthy → 1. */
$assertValid('explicit enabled=0 normalises to 0', [
    'key' => 'k7', 'label' => 'OK', 'confers_tier' => '', 'enabled' => '0',
], true, static fn(array $r): string => ($r['norm']['enabled'] ?? null) === 0 ? '' : 'enabled=0 should normalise to int 0');

/* =========================================================================
 * SOURCE-STRUCTURE — the DB-touching functions (create/update/toggle/delete +
 * reference counts) that CI cannot run behaviourally. Scan the ONE file where
 * the logic lives (rule #34: derived slice + comment-stripped so a mention in
 * an annotation can't satisfy the check).
 * ========================================================================= */

$coreCode = ltaStripComments((string)file_get_contents($coreFile));

/* Delete policy: refuses a system seed, and refuses a still-referenced key. */
$deleteSlice = ltaSliceFunction($coreCode, 'licenceTypeAdminDelete');
if ($deleteSlice === '') {
    $failures[] = 'licence_type_admin.php has no licenceTypeAdminDelete() to slice';
} else {
    if (strpos($deleteSlice, "'system'") === false || strpos($deleteSlice, 'RuntimeException') === false) {
        $failures[] = "licenceTypeAdminDelete() no longer refuses a Source='system' seed with a RuntimeException — a system row must be disabled, never deleted (#1769 P4)";
    }
    if (strpos($deleteSlice, 'licenceTypeAdminReferenceCounts') === false) {
        $failures[] = 'licenceTypeAdminDelete() no longer consults licenceTypeAdminReferenceCounts() before deleting — a still-referenced key must be refused (the Enabled-column app-enforced-retire promise)';
    }
}

/* Reference counts are INFORMATION_SCHEMA-gated (rule #9): every referencing
   store is behind an existence probe, so a pre-migration install cannot throw. */
$refSlice = ltaSliceFunction($coreCode, 'licenceTypeAdminReferenceCounts');
if ($refSlice === '') {
    $failures[] = 'licence_type_admin.php has no licenceTypeAdminReferenceCounts() to slice';
} else {
    if (strpos($refSlice, '_ltaTableExists') === false || strpos($refSlice, '_ltaColumnExists') === false) {
        $failures[] = 'licenceTypeAdminReferenceCounts() does not existence-gate its reads with _ltaTableExists/_ltaColumnExists — an ungated read of an optional store throws under mysqli STRICT on a partly-migrated install (rule #9)';
    }
}

/* Update never touches LicenceKey or Source in its SET clause (immutability at
   the persistence layer, matching the validate-layer immutability above). */
$updateSlice = ltaSliceFunction($coreCode, 'licenceTypeAdminUpdate');
if ($updateSlice === '') {
    $failures[] = 'licence_type_admin.php has no licenceTypeAdminUpdate() to slice';
} else {
    if (preg_match('/UPDATE\s+tblLicenceTypes\s+SET\b/i', $updateSlice, $mSet)) {
        $setToWhere = substr($updateSlice, (int)strpos($updateSlice, $mSet[0]));
        $wherePos   = stripos($setToWhere, 'WHERE');
        $setClause  = $wherePos !== false ? substr($setToWhere, 0, $wherePos) : $setToWhere;
        if (stripos($setClause, 'LicenceKey') !== false) {
            $failures[] = 'licenceTypeAdminUpdate() writes LicenceKey in its SET clause — the key is immutable (too many stores reference it)';
        }
        if (stripos($setClause, 'Source') !== false) {
            $failures[] = 'licenceTypeAdminUpdate() writes Source in its SET clause — provenance is set at create only, never changed on edit';
        }
    } else {
        $failures[] = 'licenceTypeAdminUpdate() has no recognisable UPDATE tblLicenceTypes SET clause to inspect';
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not behave as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n\n");
    }
    if ($failures) {
        fwrite(STDERR, "FAIL: Licence-type admin CRUD core guard (#1769 P4):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}

echo "PASS: Licence-type admin CRUD core guard — coversMerge keeps {} stable and preserves real qualifiers; "
   . "validate enforces the field truth table and treats LicenceKey as immutable on update; delete refuses "
   . "system + still-referenced rows; reference counts are INFORMATION_SCHEMA-gated; update never rewrites "
   . "LicenceKey/Source; all mutation self-tests behaved as expected.\n";
exit(0);
