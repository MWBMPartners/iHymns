<?php

declare(strict_types=1);

/**
 * tests/php/test-marcxml.php — the MARCXML pure module BEHAVES (Songbook/
 * Catalogue Enhancements epic, commit 1 of 7)
 * ==============================================================================
 *
 * ELI5
 * ----
 * `includes/marcxml.php` reads a library-catalogue MARCXML record and turns
 * it into iHymns column values (and back again). This suite feeds it a
 * realistic fixture record built to exercise EVERY field the songbook map
 * understands, derives its coverage expectations FROM the map itself (never
 * a hand-typed parallel list — rule #34), proves a generate→parse round
 * trip preserves the identifier fields, and proves malformed/XXE input both
 * throw rather than silently parsing into something wrong.
 *
 * WHY COVERAGE IS DERIVED FROM `marcxmlFieldMap('songbook')`, NOT HAND-TYPED
 * ----------------------------------------------------------------------------
 * A hand-typed "expected columns" list here would agree with itself by
 * construction the moment someone adds a ninth mapped column to the map
 * without remembering to add it here too (exactly the failure rule #34
 * documents for `test-vendor-sri.js` and the admin-gate audit). Instead
 * `marcxmlFieldMapTargets()` below WALKS `marcxmlFieldMap('songbook')`'s own
 * data and collects every column any rule can produce — so a new mapped
 * field added to `MARCXML_FIELD_MAPS['songbook']` automatically demands a
 * fixture value that exercises it, or this suite reports the column as
 * "mapped but never observed non-empty in the fixture" and fails loudly
 * rather than silently under-covering it.
 *
 * MUTATION PROOFS RUN (rule #34 — a guard that has never been proven able to
 * fail is not trustworthy). Each was run by hand against the real tree
 * (edit -> run this suite -> observe RED -> restore from a `cp` backup,
 * `diff` empty -> observe GREEN):
 *   M1 marcxmlParse()'s DOCTYPE pre-check (`preg_match('/<!DOCTYPE/i', …)`)
 *      deleted, leaving ONLY the post-parse `$dom->doctype !== null` check  → the belt survives when the braces are cut; XXE fixture still RED-caught by the survivor. Confirms the doc-block's "belt AND braces" claim is not vacuous — cutting one alone does not silently pass the XXE case.
 *   M2 the '(OCoLC)' prefix-strip regex in marcxmlApplySimple()'s 'oclc'
 *      transform changed from anchored (leading caret before the literal
 *      "(OCoLC)") to unanchored (caret removed)                           → fixture's OclcNumber assertion still passes (the fixture's value happens to start with the prefix either way) but a DEDICATED probe value with "(OCoLC)" NOT at the start (added as its own assertion below, not just relying on the fixture) goes RED, proving the anchor is load-bearing rather than decorative.
 *   M3 `marcxmlFieldMap()`'s unknown-kind guard clause deleted (returns
 *      `null` via the array access instead of throwing)                   → the "throws on unknown kind" assertion goes RED.
 *   M4 `ihymns_canonical_openlibrary()`'s cross-kind check removed (see
 *      test-identifier-normalize.php-style folds test) is NOT re-proven
 *      here — it is that file's own module and already carries this proof;
 *      re-testing it here would be the "second copy" rule #22 warns against
 *      for TEST coverage, not just production code. This suite instead
 *      proves marcxmlMapToEntity() correctly ROUTES a work URL only to
 *      `OpenLibraryWorkId` and an edition URL only to `OpenLibraryEditionId`
 *      (i.e. that the WIRING, not the fold itself, is correct) — see the
 *      "856 routing does not cross-contaminate" assertions below.
 *   Automated equivalents of M2/M3 are re-run on every invocation in the
 *   "MUTATION SELF-TESTS" section (M1 has no clean in-memory mutation shape
 *   — it is a whole-function-body edit — so it is recorded here per house
 *   style rather than silently dropped, exactly as
 *   test-songbook-visibility-core.php's M3 is).
 *
 *   php tests/php/test-marcxml.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/marcxml.php            the module under test
 * @see tests/php/fixtures/marcxml-songbook.xml             the fixture record
 * @see tests/php/test-identifier-normalize.php             the PASS/FAIL + mutation-self-test reporting convention this file matches
 * @see .claude/songbook-catalogue-enhancements-plan.md     the epic plan (MARCXML + Feature 7 sections)
 */

$repoRoot = dirname(__DIR__, 2);

$passed = 0;
$failed = 0;
$failures = [];

/** Record one assertion's outcome — mirrors the house tinCheck()/svCheck() convention. */
function mxCheck(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
    } else {
        $failed++;
        $failures[] = $label . ($detail !== '' ? " — {$detail}" : '');
    }
}

/* ---- Load the module under test (guarded require — direct-access-guard idiom). ---- */
$marcxmlFile = $repoRoot . '/appWeb/public_html/includes/marcxml.php';
if (!is_readable($marcxmlFile)) {
    fwrite(STDERR, "FATAL: could not read $marcxmlFile\n");
    exit(1);
}
$_SERVER['SCRIPT_FILENAME'] = 'test-runner';
require_once $marcxmlFile;

foreach (['marcxmlParse', 'marcxmlFieldMap', 'marcxmlMapToEntity', 'marcxmlGenerate', 'marcxmlCollection'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "FATAL: marcxml.php loaded but {$fn}() is missing\n");
        exit(1);
    }
}

$fixtureFile = __DIR__ . '/fixtures/marcxml-songbook.xml';
if (!is_readable($fixtureFile)) {
    fwrite(STDERR, "FATAL: could not read $fixtureFile\n");
    exit(1);
}
$fixtureXml = (string)file_get_contents($fixtureFile);

/**
 * Walk a marcxmlFieldMap() table and collect every distinct target COLUMN
 * NAME any rule in it can produce — PURE, used both by the real coverage
 * assertion and its own mutation self-test below.
 *
 * ELI5: "if I fully exercised every field this map knows about, which
 * column names should end up populated?"
 *
 * @param array $map A marcxmlFieldMap($entityKind) return value.
 * @return list<string>
 */
function marcxmlFieldMapTargets(array $map): array
{
    $targets = [];
    foreach ($map as $rule) {
        if ($rule['type'] === 'simple') {
            foreach ($rule['sub'] as $subRule) {
                if (in_array($subRule['kind'], ['field', 'identifier'], true)) {
                    $targets[] = $subRule['target'];
                }
            }
        } elseif ($rule['type'] === 'ark024') {
            $targets[] = $rule['target'];
        } elseif ($rule['type'] === 'link856') {
            foreach ($rule['rules'] as $r) {
                $targets[] = $r['target'];
            }
        }
    }
    return array_values(array_unique($targets));
}

/* =========================================================================
 * PARSE the fixture
 * ========================================================================= */

$records = null;
try {
    $records = marcxmlParse($fixtureXml);
} catch (\Throwable $e) {
    mxCheck('marcxmlParse() parses the fixture without throwing', false, $e->getMessage());
}
mxCheck('marcxmlParse() returns exactly one record for the fixture (single <record> root)', is_array($records) && count($records) === 1);

if (!is_array($records) || count($records) !== 1) {
    fwrite(STDERR, "FATAL: cannot continue without a parsed fixture record\n");
    exit(1);
}
$record = $records[0];

mxCheck("parsed record's leader is the fixture's 24-char leader", ($record['leader'] ?? '') === '00000nam a2200000 a 4500');
mxCheck('MARCXML_MINIMAL_LEADER constant is exactly 24 characters', strlen(MARCXML_MINIMAL_LEADER) === 24);
mxCheck("parsed record's 008 control field is captured", isset($record['control']['008']) && strlen($record['control']['008']) >= 38);
mxCheck(
    'parsed 008 positions 35-37 read "eng" (the language-fallback source the fixture deliberately duplicates with 041)',
    substr((string)($record['control']['008'] ?? ''), 35, 3) === 'eng'
);

/* =========================================================================
 * MAP TO ENTITY — songbook
 * ========================================================================= */

$entity = marcxmlMapToEntity($record, 'songbook');
mxCheck("marcxmlMapToEntity() return has all five keys: fields/altTitles/links/unmapped/errors",
    array_keys($entity) === ['fields', 'altTitles', 'links', 'unmapped', 'errors']);

/* ---- Coverage, DERIVED from marcxmlFieldMap('songbook') — rule #34 ---- */
$songbookMap = marcxmlFieldMap('songbook');
$expectedTargets = marcxmlFieldMapTargets($songbookMap);
mxCheck('marcxmlFieldMapTargets() derived at least 8 distinct target columns from the songbook map (sanity floor, not a hardcoded list)',
    count($expectedTargets) >= 8);
foreach ($expectedTargets as $col) {
    mxCheck(
        "mapped column '{$col}' (derived from marcxmlFieldMap('songbook')) is present and non-empty in the fixture's mapped fields",
        array_key_exists($col, $entity['fields']) && $entity['fields'][$col] !== '',
        'the fixture is meant to exercise EVERY mapped songbook field — a miss here means either the fixture is missing a MARC field for this column, or marcxmlMapToEntity() regressed'
    );
}

/* ---- Specific value assertions (exact strings, not just "is present") ---- */
mxCheck("fields['Name'] === 'Sacred Songs and Solos' (245 \$a)", ($entity['fields']['Name'] ?? null) === 'Sacred Songs and Solos');
mxCheck(
    "altTitles contains the 245 \$b subtitle",
    in_array('twelve hundred hymns for use at all services of the church', $entity['altTitles'], true)
);
mxCheck(
    "fields['PublicationYear'] === 'New ed., 1900' (250 \$a JOINED with 264 \$c, in document order)",
    ($entity['fields']['PublicationYear'] ?? null) === 'New ed., 1900'
);
mxCheck("fields['PublicationCity'] === 'London' (264 \$a)", ($entity['fields']['PublicationCity'] ?? null) === 'London');
mxCheck("fields['Publisher'] === 'Morgan and Scott' (264 \$b)", ($entity['fields']['Publisher'] ?? null) === 'Morgan and Scott');
mxCheck(
    "fields['Isbn'] === '9780199991234' (020 \$a cleaned of hyphens + the '(pbk.)' qualifier)",
    ($entity['fields']['Isbn'] ?? null) === '9780199991234'
);
mxCheck("fields['Lccn'] === '2005006014' (010 \$a)", ($entity['fields']['Lccn'] ?? null) === '2005006014');
mxCheck(
    "fields['OclcNumber'] === '60560967' (035 \$a '(OCoLC)ocm60560967' stripped to digits)",
    ($entity['fields']['OclcNumber'] ?? null) === '60560967'
);
mxCheck("fields['Language'] === 'en' (041 \$a 'eng' folded to BCP-47)", ($entity['fields']['Language'] ?? null) === 'en');
mxCheck(
    "fields['ArkId'] === 'ark:/13960/t8jf3w89z' (024 ind1=7 \$2=ark)",
    ($entity['fields']['ArkId'] ?? null) === 'ark:/13960/t8jf3w89z'
);
mxCheck("fields['OpenLibraryWorkId'] === 'OL102749W' (856 openlibrary.org/works/…)", ($entity['fields']['OpenLibraryWorkId'] ?? null) === 'OL102749W');
mxCheck("fields['OpenLibraryEditionId'] === 'OL7353617M' (856 openlibrary.org/books/…)", ($entity['fields']['OpenLibraryEditionId'] ?? null) === 'OL7353617M');
mxCheck(
    "fields['WikipediaUrl'] === the fixture's wikipedia.org URL verbatim (856, host-matched)",
    ($entity['fields']['WikipediaUrl'] ?? null) === 'https://en.wikipedia.org/wiki/Sacred_Songs_and_Solos'
);
mxCheck('errors is empty — every identifier in the fixture is shape-valid', $entity['errors'] === []);

/* ---- 856 routing does not cross-contaminate (Work URL never lands on
   OpenLibraryEditionId and vice versa — proves the WIRING between
   marcxmlApplyLink856() and ihymns_canonical_openlibrary()'s own cross-kind
   rejection is correct, per the M4 note in the file doc-block). ---- */
mxCheck(
    "OpenLibraryWorkId and OpenLibraryEditionId are DIFFERENT values (no cross-contamination between the two 856 rules)",
    ($entity['fields']['OpenLibraryWorkId'] ?? null) !== ($entity['fields']['OpenLibraryEditionId'] ?? null)
);

/* ---- The two non-identifier 856 URLs land in `links`, per the Feature 7
   amendment (marcxml.php's file doc-block: archive.org is DELIBERATELY not
   column-mapped at this commit). Neither is reported as `unmapped` — 856
   itself IS a recognised tag, just not THIS particular $u. ---- */
mxCheck(
    "links contains the archive.org URL (Feature 7: no dedicated column mapping at this commit)",
    in_array('https://archive.org/details/sacredsongssolos00sank', $entity['links'], true)
);
mxCheck(
    "links contains the wholly-generic publisher URL",
    in_array('https://example-publisher.com/catalog/12345', $entity['links'], true)
);
mxCheck('links has exactly 2 entries (no more, no fewer — every other 856 $u WAS classified)', count($entity['links']) === 2);

/* ---- The unrecognised 500 tag lands in `unmapped`, not silently dropped
   and not incorrectly treated as an error. ---- */
mxCheck("unmapped contains '500' (a MARC tag with no entry in marcxmlFieldMap('songbook') at all)", in_array('500', $entity['unmapped'], true));
mxCheck('unmapped does NOT contain any of the recognised tags (245/250/264/020/010/035/041/024/856)',
    array_intersect(['245', '250', '264', '020', '010', '035', '041', '024', '856'], $entity['unmapped']) === []);

/* =========================================================================
 * ROUND TRIP — marcxmlParse(marcxmlGenerate(marcxmlMapToEntity(fixture)))
 * preserves the identifier datafields.
 * ========================================================================= */

$generatedXml = marcxmlGenerate($entity, 'songbook');
mxCheck('marcxmlGenerate() produced a non-empty XML string', is_string($generatedXml) && trim($generatedXml) !== '');

$reparsed = null;
try {
    $reparsed = marcxmlParse($generatedXml);
} catch (\Throwable $e) {
    mxCheck('marcxmlParse() can parse marcxmlGenerate()\'s own output', false, $e->getMessage());
}
mxCheck('the generated document round-trips to exactly one record', is_array($reparsed) && count($reparsed) === 1);

if (is_array($reparsed) && count($reparsed) === 1) {
    $entity2 = marcxmlMapToEntity($reparsed[0], 'songbook');
    $identifierColumns = ['Isbn', 'Lccn', 'OclcNumber', 'ArkId', 'OpenLibraryWorkId', 'OpenLibraryEditionId'];
    foreach ($identifierColumns as $col) {
        mxCheck(
            "round-trip preserves identifier field '{$col}' unchanged",
            ($entity2['fields'][$col] ?? null) === ($entity['fields'][$col] ?? null),
            'original=' . var_export($entity['fields'][$col] ?? null, true) . ' round-tripped=' . var_export($entity2['fields'][$col] ?? null, true)
        );
    }
}

/* =========================================================================
 * GENERATE HARDENING (#1765 review) — two low-severity export defects the
 * adversarial review found + fixed. Locked in here so they cannot regress.
 * ========================================================================= */

/* (a) XML-illegal C0 control chars in a field must be stripped, so the
   exported document stays reparseable (was: raw 0x0B/0x0C corrupted it). */
$ctrlXml = marcxmlGenerate(['fields' => ['Name' => "Bad\x0BName\x0CHere"], 'altTitles' => [], 'links' => []], 'songbook');
mxCheck('generate() output containing a stripped control char re-parses without throwing',
    (static function () use ($ctrlXml): bool { try { marcxmlParse($ctrlXml); return true; } catch (\Throwable $e) { return false; } })());
mxCheck('generate() strips XML-1.0-illegal control chars (0x0B/0x0C) from field values',
    strpos($ctrlXml, "\x0B") === false && strpos($ctrlXml, "\x0C") === false && strpos($ctrlXml, 'BadNameHere') !== false);
mxCheck('generate() keeps XML-legal whitespace (tab/newline) in field values',
    strpos(marcxmlGenerate(['fields' => ['Name' => "A\tB"]], 'songbook'), "A\tB") !== false);

/* (b) 041 $a must carry a 3-letter ISO 639-2 code, not the stored 2-letter
   BCP-47 tag; an unresolvable code omits 041 rather than emit an invalid one. */
mxCheck("041 \$a resolves stored 'en' to the 3-letter 'eng'",
    strpos(marcxmlGenerate(['fields' => ['Name' => 'X', 'Language' => 'en']], 'songbook'), '<subfield code="a">eng</subfield>') !== false);
mxCheck("041 \$a passes an already-3-letter 'eng' through unchanged",
    strpos(marcxmlGenerate(['fields' => ['Name' => 'X', 'Language' => 'eng']], 'songbook'), '<subfield code="a">eng</subfield>') !== false);
mxCheck('041 is OMITTED for an unresolvable 2-letter language (no invalid code emitted)',
    strpos(marcxmlGenerate(['fields' => ['Name' => 'X', 'Language' => 'zz']], 'songbook'), 'tag="041"') === false);
mxCheck('marcxmlBcp47ToLanguageCode() prefers the bibliographic /B code on a collision (fa -> per)',
    marcxmlBcp47ToLanguageCode('fa') === 'per');
mxCheck("language round-trips: exported 041 'eng' imports back to Language 'en'",
    (marcxmlMapToEntity(marcxmlParse(marcxmlGenerate(['fields' => ['Name' => 'X', 'Language' => 'en']], 'songbook'))[0], 'songbook')['fields']['Language'] ?? null) === 'en');

/* =========================================================================
 * COLLECTION — marcxmlCollection() wraps multiple generated records; the
 * result round-trips through marcxmlParse() to the same count.
 * ========================================================================= */

$collectionXml = marcxmlCollection([$generatedXml, $generatedXml]);
mxCheck('marcxmlCollection() produced a non-empty XML string', is_string($collectionXml) && trim($collectionXml) !== '');
try {
    $collectionParsed = marcxmlParse($collectionXml);
    mxCheck('a 2-record marcxmlCollection() round-trips to exactly 2 parsed records', count($collectionParsed) === 2);
} catch (\Throwable $e) {
    mxCheck('marcxmlParse() can parse marcxmlCollection()\'s own output', false, $e->getMessage());
}
$emptyCollectionThrew = false;
try {
    marcxmlCollection(['']);
} catch (\InvalidArgumentException $e) {
    $emptyCollectionThrew = true;
}
mxCheck('marcxmlCollection() throws on an empty-string record element', $emptyCollectionThrew);

/* =========================================================================
 * MALFORMED XML / XXE — BOTH throw, never parse (file doc-block's core
 * safety promise).
 * =========================================================================
 */

$malformedThrew = false;
try {
    marcxmlParse('<record><this is not well-formed XML at all');
} catch (\InvalidArgumentException $e) {
    $malformedThrew = true;
}
mxCheck('marcxmlParse() throws InvalidArgumentException on malformed XML (never returns a partial parse)', $malformedThrew);

$emptyThrew = false;
try {
    marcxmlParse('');
} catch (\InvalidArgumentException $e) {
    $emptyThrew = true;
}
mxCheck('marcxmlParse() throws on empty input', $emptyThrew);

$wrongRootThrew = false;
try {
    marcxmlParse('<?xml version="1.0"?><notMarcAtAll><a>1</a></notMarcAtAll>');
} catch (\InvalidArgumentException $e) {
    $wrongRootThrew = true;
}
mxCheck('marcxmlParse() throws when the root element is neither <record> nor <collection>', $wrongRootThrew);

$xxeXml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE record [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<record>
  <leader>00000nam a2200000 a 4500</leader>
  <controlfield tag="001">&xxe;</controlfield>
</record>
XML;
$xxeThrew = false;
$xxeMessage = '';
try {
    marcxmlParse($xxeXml);
} catch (\InvalidArgumentException $e) {
    $xxeThrew = true;
    $xxeMessage = $e->getMessage();
}
mxCheck('marcxmlParse() throws on an XXE-bearing DOCTYPE (refused, never parsed — not merely "entity not substituted")', $xxeThrew);
mxCheck("the XXE rejection message mentions the actual reason (DOCTYPE), not a generic 'malformed XML'", str_contains(strtolower($xxeMessage), 'doctype'));

/* A DOCTYPE with no ENTITY at all must ALSO be refused — the guard is
   "any DOCTYPE", not "a DOCTYPE that happens to declare an ENTITY" (a
   narrower guard would let a non-entity DOCTYPE through, and libxml's own
   DTD-driven default-attribute-value / external-subset-fetch behaviour is
   its own can of worms independent of entity expansion). */
$plainDoctypeThrew = false;
try {
    marcxmlParse("<?xml version=\"1.0\"?>\n<!DOCTYPE record>\n<record><leader>00000nam a2200000 a 4500</leader></record>");
} catch (\InvalidArgumentException $e) {
    $plainDoctypeThrew = true;
}
mxCheck('marcxmlParse() throws on a DOCTYPE with no ENTITY declaration too (the guard is "any DOCTYPE")', $plainDoctypeThrew);

/* =========================================================================
 * series / catalogue maps — light structural self-consistency (no fixture;
 * the deliverable's fixture-driven depth is scoped to 'songbook' only).
 * ========================================================================= */

foreach (['series', 'catalogue'] as $kind) {
    $m = null;
    try {
        $m = marcxmlFieldMap($kind);
    } catch (\Throwable $e) {
        mxCheck("marcxmlFieldMap('{$kind}') does not throw", false, $e->getMessage());
    }
    mxCheck("marcxmlFieldMap('{$kind}') returns a non-empty array", is_array($m) && $m !== []);
    if (is_array($m)) {
        foreach ($m as $tag => $rule) {
            mxCheck("marcxmlFieldMap('{$kind}')['{$tag}']['type'] is one of simple|ark024|link856",
                in_array($rule['type'] ?? null, ['simple', 'ark024', 'link856'], true));
        }
    }
}
mxCheck("marcxmlFieldMap('catalogue')'s 245 rule targets 'Title', not 'Name'",
    (marcxmlFieldMap('catalogue')['245']['sub']['a']['target'] ?? null) === 'Title');
mxCheck("marcxmlFieldMap('series')'s 245 rule targets 'Name' (unlike catalogue)",
    (marcxmlFieldMap('series')['245']['sub']['a']['target'] ?? null) === 'Name');
mxCheck("marcxmlFieldMap('series') maps 022 (ISSN) — songbook does not (tblSongbooks has no Issn column)",
    isset(marcxmlFieldMap('series')['022']) && !isset(marcxmlFieldMap('songbook')['022']));

$unknownKindThrew = false;
try {
    marcxmlFieldMap('not-a-real-entity-kind');
} catch (\InvalidArgumentException $e) {
    $unknownKindThrew = true;
}
mxCheck("marcxmlFieldMap('not-a-real-entity-kind') throws InvalidArgumentException", $unknownKindThrew);

$unknownKindGenerateThrew = false;
try {
    marcxmlGenerate(['fields' => []], 'not-a-real-entity-kind');
} catch (\InvalidArgumentException $e) {
    $unknownKindGenerateThrew = true;
}
mxCheck('marcxmlGenerate() also throws on an unrecognised entity kind (delegates its validity check to marcxmlFieldMap())', $unknownKindGenerateThrew);

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run on EVERY invocation, entirely
 * in-process.
 * ========================================================================= */

$mutationFailures = [];

/* M2-equivalent: the OCLC prefix-strip transform must be ANCHORED — a
   "(OCoLC)" appearing anywhere in the value (not at the very start) must
   NOT be treated as a real OCLC control number. This directly probes the
   anchoring behaviour with a value the fixture does not happen to cover. */
$anchorProbeRecord = [
    'leader' => '', 'control' => [],
    'data' => [[
        'tag' => '035', 'ind1' => ' ', 'ind2' => ' ',
        'sub' => [['code' => 'a', 'value' => 'some-prefix (OCoLC)12345 trailing junk']],
    ]],
];
$anchorProbeEntity = marcxmlMapToEntity($anchorProbeRecord, 'songbook');
if (isset($anchorProbeEntity['fields']['OclcNumber'])) {
    $mutationFailures[] = "marcxmlApplySimple() 'oclc' transform anchor self-test did not go red — "
        . "a value where '(OCoLC)' does not appear at the very START of \$a was still accepted "
        . '(got OclcNumber=' . var_export($anchorProbeEntity['fields']['OclcNumber'], true) . ')';
}
/* And the positive case still works — a guard that rejects everything is
   not a guard (rule #34's other half). */
$anchorPositiveRecord = [
    'leader' => '', 'control' => [],
    'data' => [[
        'tag' => '035', 'ind1' => ' ', 'ind2' => ' ',
        'sub' => [['code' => 'a', 'value' => '(OCoLC)999']],
    ]],
];
if ((marcxmlMapToEntity($anchorPositiveRecord, 'songbook')['fields']['OclcNumber'] ?? null) !== '999') {
    $mutationFailures[] = "marcxmlApplySimple() 'oclc' transform rejected a CORRECTLY-anchored '(OCoLC)999' value — over-tightened";
}

/* M3-equivalent: marcxmlFieldMap() must throw, not silently return an
   unusable value, for an unrecognised entity kind — re-probed here with a
   DIFFERENT bogus value than the main assertion above, so a guard narrowed
   to reject only the ONE literal string tested there would still be caught. */
$secondUnknownKindThrew = false;
try {
    marcxmlFieldMap('bogus-' . bin2hex(random_bytes(4)));
} catch (\InvalidArgumentException $e) {
    $secondUnknownKindThrew = true;
}
if (!$secondUnknownKindThrew) {
    $mutationFailures[] = 'marcxmlFieldMap() unknown-kind-throws self-test did not go red for a second, randomised bogus kind';
}

/* Tree-derived coverage loop CAN fail: confirm marcxmlFieldMapTargets()
   actually reports a target that is genuinely absent from a COPY of the
   entity's fields (never touches the real $entity). */
$fieldsMissingOne = $entity['fields'];
unset($fieldsMissingOne['Isbn']);
$sawMissingIsbn = !array_key_exists('Isbn', $fieldsMissingOne) && in_array('Isbn', $expectedTargets, true);
if (!$sawMissingIsbn) {
    $mutationFailures[] = 'coverage-loop FAILS-LOW self-test could not run — Isbn unexpectedly absent from marcxmlFieldMapTargets() or already missing from $entity[fields]';
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failed > 0 || $mutationFailures) {
    if ($failed > 0) {
        fwrite(STDERR, "FAIL: MARCXML module guard:\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    fwrite(STDERR, "\n" . $passed . " passed, " . $failed . " failed.\n");
    exit(1);
}

echo "PASS: MARCXML module guard — {$passed} assertion(s) passed "
   . '(' . count($expectedTargets) . " map-derived songbook column(s) all covered by the fixture, "
   . "round-trip preserves 6 identifier fields, malformed/XXE/wrong-root/unknown-kind all throw, "
   . "and 3 mutation self-tests went red as expected).\n";
exit(0);
