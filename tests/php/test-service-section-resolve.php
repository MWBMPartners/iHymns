<?php

declare(strict_types=1);

/**
 * iHymns — service_drive section-label resolution (#1798)
 * ============================================================================
 * ELI5: when an external driver (ProPresenter/Companion) says "jump to Tag" or
 * "Pre Chorus 2", the server has to turn that free-text label into the right
 * slide. This proves two fixes: (a) the label parser understands the SPACE form
 * ("Pre Chorus 2") the old letters-only regex rejected; and (b) the exporter and
 * the resolver AGREE on names — every section name iHymns' own ProPresenter
 * exporter emits (`Tag`, `Coda`, `Interlude`, `Vamp`, …) parses back to that
 * section's own stored type, so `serviceMode_resolveSectionIndex()`'s exact-type
 * pass can address it. Before #1798 the label map folded `tag`→`outro` etc., so
 * a section stored as `tag` could never be reached by the name "Tag".
 *
 * PART A (pure) — `serviceMode_sectionLabelParse()`:
 *   space form, hyphen form, bare number → verse default, trailing number,
 *   empty → null, garbage → still parses but resolves to a non-type key.
 * PART B (tree-derived, pure) — the exporter↔resolver naming CONTRACT
 *   (rule #35, the born-RED leg): parse every `type => {name}` entry out of
 *   `manage/editor/propresenter-export.js` and assert
 *   `serviceMode_sectionLabelParse(name).wordKey === normType(type)` — i.e. the
 *   name the exporter prints for a stored type parses straight back to that
 *   type's normalised key, which is exactly what the resolver's PASS-1 exact
 *   match keys on. A drift on either side (a renamed group, a broken parse)
 *   goes red.
 * PART C (source scan) — resolveSectionIndex tries the EXACT stored-type match
 *   BEFORE the coarse SERVICE_MODE_SECTION_LABEL_MAP fold; swapping that order
 *   re-breaks own-name addressing.
 *
 * MUTATION PROOF (rule #34): see the commit body — reverting the parser to the
 * letters-only regex reddens A (space form); dropping the hyphen strip reddens
 * B (`pre-chorus`); removing the exact-first pass reddens C.
 *
 * No DB needed (the resolver's full DB round-trip over a song seeded with
 * fine-grained components is deferred to the #1797 build's C4, where the
 * component-seed infrastructure fits).
 *
 * @see appWeb/public_html/includes/service_mode.php  serviceMode_sectionLabelParse / serviceMode_resolveSectionIndex
 * @see https://github.com/MWBMPartners/iHymns/issues/1798
 */

$scanRoot = getenv('IHYMNS_SCAN_ROOT') ?: (dirname(__DIR__, 2) . '/appWeb/public_html');
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: could not read $scanRoot\n");
    exit(1);
}

$failures = 0;
$passed   = 0;
function ssr(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

require_once $scanRoot . '/includes/service_mode.php';

/** Same fold the resolver uses to compare a stored type to a label word-key. */
$normType = static fn(string $s): string => (string)preg_replace('/[\s\-]+/', '', strtolower($s));

/* ---- PART A — pure parse ------------------------------------------------- */
$pp = static fn(string $s) => serviceMode_sectionLabelParse($s);

$a = $pp('Pre Chorus 2');
ssr($a !== null && $a['wordKey'] === 'prechorus' && $a['num'] === 2,
    'A space form "Pre Chorus 2" → wordKey=prechorus, num=2 (the old letters-only regex rejected this)');

$a = $pp('pre-chorus 2');
ssr($a !== null && $a['wordKey'] === 'prechorus' && $a['num'] === 2,
    'A hyphen form "pre-chorus 2" folds to the same wordKey');

$a = $pp('Verse 2');
ssr($a !== null && $a['wordKey'] === 'verse' && $a['num'] === 2, 'A "Verse 2" → verse / 2');

$a = $pp('Tag');
ssr($a !== null && $a['wordKey'] === 'tag' && $a['num'] === null, 'A "Tag" → tag / null');

$a = $pp('2');
ssr($a !== null && $a['wordKey'] === 'verse' && $a['num'] === 2, 'A bare "2" → verse default / 2');

ssr($pp('') === null, 'A empty string → null');
ssr($pp('   ') === null, 'A whitespace-only → null');

$a = $pp('Chorus');
ssr($a !== null && $a['wordKey'] === 'chorus' && $a['num'] === null, 'A "Chorus" → chorus / null');

/* ---- PART B — exporter ↔ resolver naming contract (tree-derived) --------- */
$expSrc = (string)file_get_contents($scanRoot . '/manage/editor/propresenter-export.js');
/* Match `'<type>': { letter: '..', name: '<Name>' }` — the exporter's ONE map. */
preg_match_all("/'([a-z][a-z\\-]*)'\\s*:\\s*\\{[^}]*name:\\s*'([^']+)'/", $expSrc, $mm, PREG_SET_ORDER);
ssr(count($mm) >= 8, 'B found the exporter group-name map (>=8 entries) — got ' . count($mm));

foreach ($mm as $pair) {
    [$_, $type, $name] = $pair;
    $parsed = serviceMode_sectionLabelParse($name);
    ssr(
        $parsed !== null && $parsed['wordKey'] === $normType($type),
        "B exporter name \"$name\" parses back to its own stored type \"$type\" "
            . '(wordKey=' . ($parsed['wordKey'] ?? 'NULL') . ', expected ' . $normType($type) . ')'
    );
}

/* ---- PART C — exact-before-fold ordering in the resolver ----------------- */
$smSrc = (string)file_get_contents($scanRoot . '/includes/service_mode.php');
/* Locate the resolver body. */
$fnPos = strpos($smSrc, 'function serviceMode_resolveSectionIndex');
ssr($fnPos !== false, 'C found serviceMode_resolveSectionIndex()');
if ($fnPos !== false) {
    $body = substr($smSrc, $fnPos, 3000);
    /* The exact stored-type comparison ($normType(...) === $wordKey) must appear
       BEFORE the coarse-fold fallback (SERVICE_MODE_SECTION_LABEL_MAP[$wordKey]). */
    $exactPos = strpos($body, '=== $wordKey');
    $foldPos  = strpos($body, 'SERVICE_MODE_SECTION_LABEL_MAP[$wordKey]');
    ssr(
        $exactPos !== false && $foldPos !== false && $exactPos < $foldPos,
        'C the exact stored-type match runs BEFORE the coarse-fold fallback (own-name addressing, #1798)'
    );
}

if ($failures) {
    fwrite(STDERR, "\n{$passed} passed, {$failures} failed\n");
    exit(1);
}
echo "\n{$passed} passed, 0 failed\n";
exit(0);
