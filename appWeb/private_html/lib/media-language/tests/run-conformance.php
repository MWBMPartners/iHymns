<?php

/**
 * bindings/php/media-language/tests/run-conformance.php
 *
 * Runs every conformance case in tests/fixtures/bcp47-language-policy-v1.json
 * against the PHP implementation of MWBM-MEDIA-LANG
 * (../MediaLanguagePolicy.php). Plain PHP, no PHPUnit and no other
 * dependency, on purpose: the three consumer applications (iHymns,
 * iLyricsDB, NetPLAYERapp) have no test framework of their own, and this
 * file is meant to run the same way in all of them, with `php` alone - it
 * also needs no PHP extension, the same as the library file it tests (see
 * that file's top doc comment), so `php -n` (every extension disabled)
 * runs it exactly the same as an ordinary `php`.
 *
 * Usage:
 *   php run-conformance.php [--fixtures <path>] [--data <path>]
 *
 * With no arguments, both paths default to the copies inside THIS
 * repository (MeedyaSuite-core), found relative to this script's own
 * location. A repository that has taken a copy of the policy (section 8.3)
 * passes its own paths instead, for example:
 *
 *   php run-conformance.php \
 *     --fixtures docs/standards/tests/bcp47-language-policy-v1.json \
 *     --data docs/standards/data/bcp47-language-data-v1.json
 *
 * Exit code is 0 only when every case in every section passed AND the
 * number of cases actually run matches the number declared in the fixture
 * file - a section silently skipped (a typo'd array key, for instance)
 * would otherwise still print "0 failed" and look like a clean pass. Policy
 * section 8.1 also requires this runner to FAIL - never quietly report
 * success - on an unknown section, a missing or empty section it needs, or
 * a case that is missing a field the schema requires: those checks run
 * before a single case is executed (see checkSections() and
 * requireFields() below), so a broken fixture file is caught immediately
 * rather than producing a confusing, partial run.
 *
 * Copyright (c) 2026 MeedyaSuite
 * Licensed under the MIT License. See LICENSE file in the project root.
 */

declare(strict_types=1);

require __DIR__ . '/../MediaLanguagePolicy.php';

use Mwbm\MediaLanguage\Policy;
use Mwbm\MediaLanguage\SubtitleMode;

/**
 * The sections of the fixture file, in the order the policy document lists
 * them (section 8.1's table). Kept as a named list, rather than just
 * iterating whatever keys the JSON happens to have, so a section renamed,
 * removed, or added to the fixture file causes an immediate, loud failure
 * here rather than a quietly smaller (or differently shaped) test run.
 */
const EXPECTED_SECTIONS = [
    'canonicalise',
    'legacy_three_letter',
    'iso639_2_write',
    'posix_locale',
    'sidecar_name',
    'canonical_order',
    'track_order',
    'presentation_order',
    'subtitle_menu',
    'label',
    'match',
    'auto_select_audio',
    'auto_select_subtitle',
];

/** The top-level keys in the fixture file that are metadata about the file
 * itself, not a section of cases - never checked for required case fields,
 * and not counted as "unknown" when checkSections() looks for stray keys. */
const METADATA_KEYS = ['$schema', 'policy', 'policy_version', 'fixtures_version', 'data_version'];

/**
 * The fields every case in each section MUST have, taken directly from the
 * fixture schema's own `required` lists (bcp47-language-policy-v1.schema.json).
 * `sidecar_name` and `presentation_order`/`subtitle_menu` cases are checked
 * against the right list for their own shape (build vs parse; the shared
 * presentationCase shape) inside their own loops below, not from this table.
 *
 * @var array<string, list<string>>
 */
const REQUIRED_FIELDS = [
    'canonicalise' => ['id', 'rules', 'input', 'expected', 'kind'],
    'legacy_three_letter' => ['id', 'rules', 'input', 'expected'],
    'iso639_2_write' => ['id', 'rules', 'input', 'expected'],
    'posix_locale' => ['id', 'rules', 'input', 'expected'],
    'canonical_order' => ['id', 'rules', 'description', 'items', 'expected'],
    'track_order' => ['id', 'rules', 'description', 'tracks', 'expected'],
    'label' => ['id', 'rules', 'type', 'language_name', 'roles', 'role_names', 'channels', 'expected'],
    'match' => ['id', 'rules', 'preference', 'candidate', 'expected'],
    'auto_select_audio' => ['id', 'rules', 'description', 'preferences', 'accessibility', 'tracks', 'expected'],
    'auto_select_subtitle' => [
        'id', 'rules', 'description', 'mode', 'preferences', 'accessibility', 'audio', 'tracks', 'expected',
    ],
];

/** The fixture schema's presentationCase shape, shared by presentation_order
 * and subtitle_menu (both are literally `$ref`s to the same $defs entry). */
const PRESENTATION_CASE_FIELDS = [
    'id', 'rules', 'description', 'preferences', 'accessibility', 'display_names', 'collation_keys', 'items',
    'expected',
];

/** sidecar_name is the one section whose cases come in two different
 * required-field shapes (the schema's `oneOf`), keyed by 'mode'. */
const SIDECAR_BUILD_FIELDS = ['id', 'rules', 'mode', 'stem', 'tag', 'roles', 'extension', 'number', 'expected'];
const SIDECAR_PARSE_FIELDS = ['id', 'rules', 'mode', 'stem', 'filename', 'expected'];

/**
 * @param array<int, string> $argv
 * @return array{fixtures: string, data: string}
 */
function parseArguments(array $argv): array
{
    $repoRoot = realpath(__DIR__ . '/../../../../');
    if ($repoRoot === false) {
        fwrite(STDERR, "Could not resolve the repository root from " . __DIR__ . "\n");
        exit(1);
    }
    $fixtures = $repoRoot . '/tests/fixtures/bcp47-language-policy-v1.json';
    $data = $repoRoot . '/docs/standards/data/bcp47-language-data-v1.json';

    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        if ($argv[$i] === '--fixtures' && $i + 1 < $count) {
            $fixtures = $argv[++$i];
        } elseif ($argv[$i] === '--data' && $i + 1 < $count) {
            $data = $argv[++$i];
        } else {
            fwrite(STDERR, "Unrecognised argument: {$argv[$i]}\n");
            fwrite(STDERR, "Usage: php run-conformance.php [--fixtures <path>] [--data <path>]\n");
            exit(1);
        }
    }
    return ['fixtures' => $fixtures, 'data' => $data];
}

/**
 * Fails the whole run immediately (policy section 8.1) if the fixture
 * file's top-level sections do not exactly match EXPECTED_SECTIONS - an
 * unknown section (one this runner has never heard of, so it could never
 * have been checked), a missing one, or one that is present but empty (so
 * this runner's profile needs it, yet there is nothing in it to run) are
 * all refused here, before a single case is executed.
 *
 * @param array<string, mixed> $fixtures
 */
function checkSections(array $fixtures): void
{
    $actual = array_values(array_diff(array_keys($fixtures), METADATA_KEYS));
    $unknown = array_values(array_diff($actual, EXPECTED_SECTIONS));
    $missing = array_values(array_diff(EXPECTED_SECTIONS, $actual));
    if ($unknown !== [] || $missing !== []) {
        $message = "The fixture file's sections do not match what this runner expects.\n";
        if ($unknown !== []) {
            $message .= '  Unknown section(s), never checked by this runner: ' . implode(', ', $unknown) . "\n";
        }
        if ($missing !== []) {
            $message .= '  Missing section(s), needed by this runner: ' . implode(', ', $missing) . "\n";
        }
        fwrite(STDERR, $message);
        exit(1);
    }
    foreach (EXPECTED_SECTIONS as $section) {
        if (!is_array($fixtures[$section])) {
            fwrite(STDERR, "The fixture file's '{$section}' section is not an array.\n");
            exit(1);
        }
        if (count($fixtures[$section]) === 0) {
            fwrite(
                STDERR,
                "The fixture file's '{$section}' section is empty; this runner needs at least "
                . "one case in it.\n"
            );
            exit(1);
        }
    }
}

/**
 * Fails the whole run immediately if $case is missing any field in
 * $fields. Uses array_key_exists(), not isset() - a required field whose
 * value is legitimately null (several fields in this schema allow null,
 * such as 'expected' on an error case) still counts as present; only an
 * ABSENT key is a fixture error. Reading a case's fields with plain array
 * access (`$case['foo']`) before this check would, at best, produce a PHP
 * warning that a careless CI setup could miss, and at worst silently read
 * null and let a case pass or fail for the wrong reason.
 *
 * @param array<string, mixed> $case
 * @param list<string> $fields
 */
function requireFields(array $case, array $fields, string $section): void
{
    $missing = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $case)) {
            $missing[] = $field;
        }
    }
    if ($missing !== []) {
        $id = is_string($case['id'] ?? null) ? $case['id'] : '(no id)';
        fwrite(
            STDERR,
            "Fixture error in '{$section}' case '{$id}': missing required field(s): "
            . implode(', ', $missing) . "\n"
        );
        exit(1);
    }
}

/** @var list<string> $failures */
$failures = [];
$casesRun = 0;
$checksRun = 0;

/**
 * Records one pass/fail check. $extra names which run this is (for example
 * "reversed"), so a failure that only shows up with the tracks reversed is
 * distinguishable in the printed output from the normal-order failure.
 */
function check(string $caseId, mixed $actual, mixed $expected, string $extra = ''): void
{
    global $failures, $checksRun;
    $checksRun++;
    if ($actual !== $expected) {
        $label = $extra === '' ? $caseId : "{$caseId} ({$extra})";
        $failures[] = sprintf(
            "%s: expected %s, got %s",
            $label,
            json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}

/**
 * Records one pass/fail check for a case whose `error: true` marks it as
 * one the implementation MUST refuse (throw) rather than answer at all -
 * returning any value, even a plausible-looking one, fails the case just
 * as surely as answering wrongly would. Catches \Throwable, not a
 * narrower type: the policy only requires "an error (exception / Rust
 * Err)", not any specific PHP exception class.
 */
function checkRefused(string $caseId, callable $fn, string $extra = ''): void
{
    global $failures, $checksRun;
    $checksRun++;
    try {
        $fn();
    } catch (\Throwable) {
        return;
    }
    $label = $extra === '' ? $caseId : "{$caseId} ({$extra})";
    $failures[] = "{$label}: this case is marked error: true (must refuse), but the call returned normally.";
}

$args = parseArguments($argv);

if (!is_file($args['fixtures'])) {
    fwrite(STDERR, "No fixture file at {$args['fixtures']}\n");
    exit(1);
}
$fixtureRaw = file_get_contents($args['fixtures']);
if ($fixtureRaw === false) {
    fwrite(STDERR, "Could not read {$args['fixtures']}\n");
    exit(1);
}
$fixtures = json_decode($fixtureRaw, true);
if (!is_array($fixtures)) {
    fwrite(STDERR, "{$args['fixtures']} is not valid JSON\n");
    exit(1);
}

checkSections($fixtures);

$declaredTotal = 0;
foreach (EXPECTED_SECTIONS as $section) {
    $declaredTotal += count($fixtures[$section]);
}

try {
    Policy::loadData($args['data']);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Could not load reference data: " . $e->getMessage() . "\n");
    exit(1);
}

// --- canonicalise (LANG-001, LANG-026) --------------------------------
foreach ($fixtures['canonicalise'] as $case) {
    requireFields($case, REQUIRED_FIELDS['canonicalise'], 'canonicalise');
    $casesRun++;
    $tag = Policy::canonicalise($case['input']);
    $actual = [$tag->isMalformed() ? null : $tag->tag, $tag->kind->value];
    check($case['id'], $actual, [$case['expected'], $case['kind']]);

    // Stability check (LANG-001, revision 2): canonical form must be a
    // fixed point - canonicalising an already-canonical tag has to return
    // it unchanged. Run for every case whose expected value is a real tag
    // (skipped for the malformed cases, which have no tag to re-check).
    // This is an extra verification of the SAME case, not a new declared
    // case, so it adds to $checksRun but not to $casesRun - the same
    // convention as the reversed AUTO-010 re-checks below.
    if ($case['expected'] !== null) {
        $restabilised = Policy::canonicalise($case['expected']);
        check($case['id'], $restabilised->tag, $case['expected'], 'stability');
    }
}

// --- legacy_three_letter (LANG-002, LANG-003) --------------------------
foreach ($fixtures['legacy_three_letter'] as $case) {
    requireFields($case, REQUIRED_FIELDS['legacy_three_letter'], 'legacy_three_letter');
    $casesRun++;
    check($case['id'], Policy::fromLegacyThreeLetter($case['input']), $case['expected']);
}

// --- iso639_2_write (TRACK-070) ------------------------------------------
foreach ($fixtures['iso639_2_write'] as $case) {
    requireFields($case, REQUIRED_FIELDS['iso639_2_write'], 'iso639_2_write');
    $casesRun++;
    check($case['id'], Policy::iso6392CodesForWriting($case['input']), $case['expected']);
}

// --- posix_locale (LANG-004) --------------------------------------------
foreach ($fixtures['posix_locale'] as $case) {
    requireFields($case, REQUIRED_FIELDS['posix_locale'], 'posix_locale');
    $casesRun++;
    check($case['id'], Policy::fromPosixLocale($case['input']), $case['expected']);
}

// --- sidecar_name (TEXT-030) ---------------------------------------------
foreach ($fixtures['sidecar_name'] as $case) {
    $casesRun++;
    if ($case['mode'] === 'build') {
        requireFields($case, SIDECAR_BUILD_FIELDS, 'sidecar_name (build)');
        if ($case['error'] ?? false) {
            checkRefused(
                $case['id'],
                static fn () => Policy::buildSidecarName(
                    $case['stem'],
                    $case['tag'],
                    $case['roles'],
                    $case['extension'],
                    $case['number']
                )
            );
            continue;
        }
        $name = Policy::buildSidecarName(
            $case['stem'],
            $case['tag'],
            $case['roles'],
            $case['extension'],
            $case['number']
        );
        check($case['id'], $name, $case['expected']);
    } else {
        requireFields($case, SIDECAR_PARSE_FIELDS, 'sidecar_name (parse)');
        $parsed = Policy::parseSidecarName($case['stem'], $case['filename']);
        check($case['id'], $parsed, $case['expected']);
    }
}

// --- canonical_order (LANG-010 to LANG-027) -----------------------------
foreach ($fixtures['canonical_order'] as $case) {
    requireFields($case, REQUIRED_FIELDS['canonical_order'], 'canonical_order');
    $casesRun++;
    $items = array_map(
        static fn (array $item): array => [
            'id' => $item['id'] ?? $item['tag'],
            'tag' => $item['tag'],
            'original' => $item['original'] ?? false,
        ],
        $case['items']
    );
    $ordered = Policy::sortCanonicalOrder($items);
    $ids = array_map(static fn (array $i): string => $i['id'], $ordered);
    check($case['id'], $ids, $case['expected']);
}

// --- track_order (TRACK-050, TRACK-060) ---------------------------------
foreach ($fixtures['track_order'] as $case) {
    requireFields($case, REQUIRED_FIELDS['track_order'], 'track_order');
    $casesRun++;
    $ordered = Policy::sortTrackOrder($case['tracks']);
    $ids = array_map(static fn (array $t): string => $t['id'], $ordered);
    check($case['id'], $ids, $case['expected']);
}

/**
 * Builds the $groupCompare callable the brief specifies: compare
 * collation_keys as plain strings (the library itself then breaks a tie by
 * primary subtag, per UI-040's "Groups with the same name are ordered by
 * primary language code" - see Policy::sortPresentation()'s doc comment).
 *
 * @param array<string, string> $collationKeys
 * @return callable(string, string): int
 */
function presentationGroupCompare(array $collationKeys): callable
{
    return static function (string $a, string $b) use ($collationKeys): int {
        return strcmp($collationKeys[$a] ?? $a, $collationKeys[$b] ?? $b);
    };
}

// --- presentation_order (UI-020 to UI-050) ------------------------------
foreach ($fixtures['presentation_order'] as $case) {
    requireFields($case, PRESENTATION_CASE_FIELDS, 'presentation_order');
    $casesRun++;
    $ordered = Policy::sortPresentation(
        $case['items'],
        $case['preferences'],
        presentationGroupCompare($case['collation_keys']),
        $case['accessibility']
    );
    $ids = array_map(static fn (array $i): string => $i['id'], $ordered);
    check($case['id'], $ids, $case['expected']);
}

// --- subtitle_menu (UI-060) ---------------------------------------------
foreach ($fixtures['subtitle_menu'] as $case) {
    requireFields($case, PRESENTATION_CASE_FIELDS, 'subtitle_menu');
    $casesRun++;
    $ordered = Policy::sortSubtitleMenu(
        $case['items'],
        $case['preferences'],
        presentationGroupCompare($case['collation_keys']),
        $case['accessibility']
    );
    $ids = array_map(static fn (?array $i): string => $i === null ? 'off' : $i['id'], $ordered);
    check($case['id'], $ids, $case['expected']);
}

// --- label (UI-070) ------------------------------------------------------
foreach ($fixtures['label'] as $case) {
    requireFields($case, REQUIRED_FIELDS['label'], 'label');
    $casesRun++;
    $label = Policy::buildLabel(
        $case['type'],
        $case['language_name'],
        $case['roles'],
        $case['role_names'],
        $case['channels']
    );
    check($case['id'], $label, $case['expected']);
}

// --- match (MATCH-010 to MATCH-040) --------------------------------------
foreach ($fixtures['match'] as $case) {
    requireFields($case, REQUIRED_FIELDS['match'], 'match');
    $casesRun++;
    $result = Policy::matchTags($case['preference'], $case['candidate']);
    $actual = ['level' => $result->level->value, 'distance' => $result->distance];
    check($case['id'], $actual, $case['expected']);
}

// --- auto_select_audio (AUTO-010, AUTO-020, AUTO-040) --------------------
foreach ($fixtures['auto_select_audio'] as $case) {
    requireFields($case, REQUIRED_FIELDS['auto_select_audio'], 'auto_select_audio');
    $casesRun++;
    if ($case['error'] ?? false) {
        // AUTO-010: duplicate identifiers must be refused, never guessed
        // at - and refusing is a property of the INPUT, not of which
        // order it arrived in, so (unlike an ordinary case) there is
        // nothing useful a "reversed" re-check would add here.
        checkRefused(
            $case['id'],
            static fn () => Policy::selectAudioTrack($case['tracks'], $case['preferences'], $case['accessibility'])
        );
        continue;
    }
    $chosen = Policy::selectAudioTrack($case['tracks'], $case['preferences'], $case['accessibility']);
    check($case['id'], $chosen, $case['expected']);

    // AUTO-010: the same answer whatever order the tracks are listed in.
    $reversedChosen = Policy::selectAudioTrack(
        array_reverse($case['tracks']),
        $case['preferences'],
        $case['accessibility']
    );
    check($case['id'], $reversedChosen, $case['expected'], 'reversed');
}

// --- auto_select_subtitle (AUTO-010, AUTO-030, AUTO-040) -----------------
foreach ($fixtures['auto_select_subtitle'] as $case) {
    requireFields($case, REQUIRED_FIELDS['auto_select_subtitle'], 'auto_select_subtitle');
    $casesRun++;
    $mode = SubtitleMode::from($case['mode']);
    if ($case['error'] ?? false) {
        checkRefused(
            $case['id'],
            static fn () => Policy::selectSubtitleTrack(
                $case['tracks'],
                $case['audio'],
                $case['preferences'],
                $mode,
                $case['accessibility']
            )
        );
        continue;
    }
    $chosen = Policy::selectSubtitleTrack(
        $case['tracks'],
        $case['audio'],
        $case['preferences'],
        $mode,
        $case['accessibility']
    );
    check($case['id'], $chosen, $case['expected']);

    $reversedChosen = Policy::selectSubtitleTrack(
        array_reverse($case['tracks']),
        $case['audio'],
        $case['preferences'],
        $mode,
        $case['accessibility']
    );
    check($case['id'], $reversedChosen, $case['expected'], 'reversed');
}

// ---------------------------------------------------------------------
// PHP-specific implementation checks: behaviours the shared, language-
// neutral fixture cases cannot express (a required SPEED, in this one
// case), so they live here instead of in the fixture file.
// ---------------------------------------------------------------------

// [P1] Duplicate-variant detection must be LINEAR in the number of
// variants, not quadratic. Before this was fixed, canonicalising a tag
// with 20,000 distinct variant subtags took several seconds (a real cost
// on a server handling untrusted input, since nothing here limits how
// many variants a caller's string can claim to have) - it must now finish
// in a small fraction of a second. Threshold is deliberately generous
// (a correct, linear implementation finishes in well under a tenth of a
// second on ordinary hardware) so this never fails from ordinary CI
// slowness while still catching a return to quadratic behaviour outright.
$variantCount = 20000;
$variants = [];
for ($i = 0; $i < $variantCount; $i++) {
    // Five-character alnum variant subtags ('v' + four hex digits),
    // distinct by construction because $i is distinct - shape only
    // matters here, not which real variant (if any) each one names.
    $variants[] = 'v' . str_pad(dechex($i), 4, '0', STR_PAD_LEFT);
}
$largeTag = 'en-' . implode('-', $variants);
$start = microtime(true);
Policy::canonicalise($largeTag);
$elapsed = microtime(true) - $start;
$checksRun++;
$phpSpecificChecksRun = 1;
if ($elapsed >= 1.0) {
    $failures[] = sprintf(
        'performance: canonicalising a tag with %d variants took %.3fs; must finish well under 1s '
        . '(duplicate-variant detection must be linear, not quadratic, in the number of variants - '
        . 'see the P1 finding in MediaLanguagePolicy.php\'s parseWellFormed()).',
        $variantCount,
        $elapsed
    );
}

// AUTO-010's identifier order is exercised end to end by the shared
// fixture cases (audio-18 "9" before "10", audio-19 "01" before "1"), but
// neither of those happens to disagree with what plain byte-string order
// would already give for THOSE specific pairs by coincidence - "99" vs
// "1abc" is the case that actually tells the two rules apart: plain
// strcmp() would put "1abc" first ('1' < '9'), but AUTO-010 puts every
// digits-only identifier before every other kind, so "99" must win here.
$mixedIdentifierResult = Policy::selectAudioTrack(
    [
        ['id' => '1abc', 'tag' => 'en', 'roles' => []],
        ['id' => '99', 'tag' => 'en', 'roles' => []],
    ],
    ['en'],
    []
);
check('php-identifier-numeric-before-text', $mixedIdentifierResult, '99');
$phpSpecificChecksRun++;

// ---------------------------------------------------------------------

if ($failures !== []) {
    fwrite(STDERR, "Failures:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
}

if ($casesRun !== $declaredTotal) {
    fwrite(
        STDERR,
        "Case-count mismatch: ran {$casesRun} cases but the fixture file declares "
        . "{$declaredTotal} across its " . count(EXPECTED_SECTIONS) . " sections. "
        . "A section was probably skipped.\n"
    );
    exit(1);
}

printf(
    "MWBM-MEDIA-LANG PHP conformance: %d/%d cases run (%d checks including reversed "
    . "AUTO-010 re-checks, canonical-form stability re-checks, and %d PHP-specific "
    . "implementation checks), %d failure(s).\n",
    $casesRun,
    $declaredTotal,
    $checksRun,
    $phpSpecificChecksRun,
    count($failures)
);

exit($failures === [] ? 0 : 1);
