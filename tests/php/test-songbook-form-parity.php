<?php

declare(strict_types=1);

/**
 * iHymns — Songbook Add/Edit form-parity guard
 * (Songbook/Catalogue Enhancements epic, #1765, commit 4 — rules #22, #34)
 *
 * ELI5
 * ----
 * The owner's complaint that kicked off this commit: `/manage/songbooks`
 * had TWO hand-typed copies of the songbook field set — the "Add a
 * songbook" create form and the "Edit songbook" modal — and the Add form
 * was missing fields the Edit form had. So a curator had to create a book,
 * then edit it, just to set (say) its publisher. Commit 4 fixed that by
 * moving every editable field into ONE shared partial
 * (`manage/includes/songbook-form-fields.php`) that BOTH forms `require`.
 *
 * This guard makes that fix PERMANENT: it fails the build if a songbook
 * metadata field is ever hand-inlined back into `songbooks.php` (i.e. added
 * to one form and not the other), or if either form stops `require`ing the
 * shared partial. It is derived from the TREE (rule #34): the field set is
 * read from the partial itself, never a list typed here — so a new field
 * added to the partial is covered automatically.
 *
 * MUTATION-PROVEN (rule #34): the two self-tests at the bottom re-inline a
 * field and delete a require, and assert the guard notices each. Verified
 * to go RED on a real mutation (re-inlining `<input name="publisher">` into
 * songbooks.php) and GREEN again once removed.
 *
 * @link appWeb/public_html/manage/includes/songbook-form-fields.php  the ONE shared field partial
 * @link appWeb/public_html/manage/songbooks.php                      the two call sites (create form + edit modal)
 */

$root          = dirname(__DIR__, 2);
$songbooksPath = $root . '/appWeb/public_html/manage/songbooks.php';
$partialPath   = $root . '/appWeb/public_html/manage/includes/songbook-form-fields.php';

/** @var list<string> $failures */
$failures = [];
$check = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) { $failures[] = $msg; }
};

/* Strip HTML + PHP comments (best-effort) so a field name merely MENTIONED
   in a comment can never count as a real input (the #1565/#34 lesson: a
   scanner must not be satisfied by prose). */
$strip = static function (string $src): string {
    $src = preg_replace('/<!--.*?-->/s', ' ', $src) ?? $src;   // HTML comments
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src) ?? $src;    // PHP block comments
    $src = preg_replace('#(^|\s)//[^\n]*#', ' ', $src) ?? $src; // PHP line comments
    return $src;
};

/* Real form-field names: name="X" where X is a bare identifier. The reorder
   form's array inputs (`name="display_order[<id>]"`) deliberately do NOT
   match — the `[` breaks the closing quote — so they are not mistaken for a
   bare `display_order` field. */
$fieldNames = static function (string $src): array {
    preg_match_all('/name="([a-z0-9_]+)"/i', $src, $m);
    return array_values(array_unique($m[1]));
};

$check(is_readable($songbooksPath), 'songbooks.php not readable');
$check(is_readable($partialPath), 'shared songbook-form-fields.php partial not readable');

if ($failures) {
    fwrite(STDERR, "FAIL: songbook form-parity guard — missing files:\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

$songbooks = $strip((string)file_get_contents($songbooksPath));
$partial   = $strip((string)file_get_contents($partialPath));

/* 1. Both forms must pull the shared partial — verified by BOUNDING each
      <form>…</form> region (HTML forms don't nest) and requiring the partial
      to appear inside AT LEAST TWO DISTINCT forms (the create form AND the
      edit modal). Counting total require()s (the previous check) under-
      reported: both requires could sit in ONE form, leaving the OTHER form
      with no shared fields at all — the exact Add/Edit gap this guards
      against (#1765 review). */
$countPartialForms = static function (string $src): int {
    preg_match_all('#<form\b[^>]*>.*?</form>#is', $src, $m);
    $n = 0;
    foreach ($m[0] as $block) {
        if (strpos($block, 'songbook-form-fields.php') !== false) { $n++; }
    }
    return $n;
};
$formsWithPartial = $countPartialForms($songbooks);
$check(
    $formsWithPartial >= 2,
    'the shared field partial must be require()d inside at least TWO distinct <form> regions '
    . "(the create form AND the edit modal); found it in {$formsWithPartial} form(s)"
);

/* 2. Derive the field set from the partial (tree-derived, not a typed list). */
$partialFields = $fieldNames($partial);
$check(
    count($partialFields) >= 15,
    'expected the shared partial to emit a substantial songbook field set; found ' . count($partialFields)
);

/* 3. THE parity assertion — none of the partial's field names may also be
      hand-inlined in songbooks.php. If one is, it means a field was added to
      a form directly instead of to the shared partial (the exact drift this
      commit removed). Fields must live in the ONE partial both forms share. */
$inlineNames = $fieldNames($songbooks);
$leaked = array_values(array_intersect($partialFields, $inlineNames));
$check(
    $leaked === [],
    'songbook metadata field(s) inlined directly in songbooks.php instead of the shared '
    . 'songbook-form-fields.php partial (Add/Edit parity risk): ' . implode(', ', $leaked)
);

/* --- Self-mutation tests: prove the guard CAN fail (rule #34). --- */
$mutantInline = $songbooks . "\n<input name=\"publisher\">\n";
$check(
    in_array('publisher', array_intersect($partialFields, $fieldNames($mutantInline)), true),
    'SELF-TEST failed: guard did not detect a re-inlined field (assertion 3 is dead)'
);
/* Region self-test: two requires collapsed into ONE <form> must read 1 (the
   under-report the previous count-based check missed), and one require per
   form across two forms must read 2. */
$oneFormFixture = '<form a> require "songbook-form-fields.php"; require "songbook-form-fields.php"; </form><form b> no partial here </form>';
$twoFormFixture = '<form a> require "songbook-form-fields.php"; </form><form b> require "songbook-form-fields.php"; </form>';
$check(
    $countPartialForms($oneFormFixture) === 1,
    'SELF-TEST failed: two requires inside ONE <form> should count as 1 form (region check is dead)'
);
$check(
    $countPartialForms($twoFormFixture) === 2,
    'SELF-TEST failed: one require in each of two <form>s should count as 2 (region check is dead)'
);

if ($failures) {
    fwrite(STDERR, "FAIL: songbook Add/Edit form-parity guard\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

echo "PASS: songbook Add + Edit forms share the ONE songbook-form-fields.php partial "
    . "(" . count($partialFields) . " fields, in {$formsWithPartial} distinct <form> regions); no field inlined.\n";
exit(0);
