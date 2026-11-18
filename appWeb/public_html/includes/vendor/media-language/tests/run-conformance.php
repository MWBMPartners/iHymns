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
 * before a single case is executed (see checkTopLevel(), checkSections()
 * and checkCaseShapes() below), so a broken fixture file is caught
 * immediately rather than producing a confusing, partial run.
 * checkTopLevel() checks the file's own fields (`policy`, the three
 * versions, `$schema`) and that its data_version is the data file's.
 * checkCaseShapes() checks every case against the schema's own shape -
 * required fields, including inside nested objects and in every item and
 * track; no field the schema does not allow (so an `error` flag in a
 * section that has no refusal cases is refused, not ignored); every field's
 * TYPE (string, true/false, whole number, list, object - and null only
 * where the schema allows null), read from a copy of the file decoded with
 * objects kept as objects so `{}` and `[]` are never confused; every value
 * the schema limits to a list of words (a role, a track type, a subtitle
 * mode, a match level, a tag kind) one of those words; and `error`, where
 * allowed, only ever `true`, on a case that expects null. Any error the
 * runner does not expect stops it with a message naming the case and exit
 * code 1 (set_exception_handler() below), never PHP's exit code 255.
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
 * itself, not a section of cases - never counted as "unknown" when
 * checkSections() looks for stray keys. checkTopLevel() checks each one
 * against TOP_LEVEL_FIELDS below. */
const METADATA_KEYS = ['$schema', 'policy', 'policy_version', 'fixtures_version', 'data_version'];

/**
 * The fixture file's own top-level fields, as the schema gives them:
 * whether each is required, and the rule its value must meet ('const' - that
 * exact string; 'version' - a string of three dot-separated numbers;
 * 'string' - any string). Before Codex's review r7 these were not checked at
 * all: a file with every one of the four required fields removed ran, and
 * reported every case as passing.
 */
const TOP_LEVEL_FIELDS = [
    '$schema' => ['required' => false, 'rule' => 'string'],
    'policy' => ['required' => true, 'rule' => 'const', 'value' => 'MWBM-MEDIA-LANG'],
    'policy_version' => ['required' => true, 'rule' => 'version'],
    'fixtures_version' => ['required' => true, 'rule' => 'version'],
    'data_version' => ['required' => true, 'rule' => 'version'],
];

/**
 * The shape every case in each section MUST have, taken directly from the
 * fixture schema (bcp47-language-policy-v1.schema.json). 'required' and
 * 'optional' map each field the schema allows to the TYPE its value must
 * have; because the schema says `additionalProperties: false` everywhere,
 * no other field is allowed. Types:
 *
 *   'string', 'bool', 'int'   - a JSON string, true/false, or whole number
 *   'list<string>'            - a JSON array of strings
 *   'map<string>'             - a JSON object whose values are all strings
 *                               (localised names; its keys are not checked)
 *   'object'                  - a JSON object with its own shape, given
 *                               under 'objects'
 *   'list<object>'            - a JSON array of objects of one shape, given
 *                               under 'lists'
 *   'true'                    - only the value true (the schema's `const`)
 *
 * A type ending in '|null' may also be null; no other field may be null.
 * 'enums' gives, for a field the schema limits to a list of words, that
 * list: a string field's value, or each string in a list field, must be
 * one of them. 'refusal' marks the three case shapes that may carry
 * `error: true`, whose expected answer must then be null.
 *
 * History, so none of this is quietly loosened again: before policy
 * revision 4 this runner only checked each case's top-level required fields
 * (a nested `expected` object, the items and tracks inside a case, and
 * unknown fields went unchecked). Until Codex's review r7 it checked which
 * fields were present but not their TYPES, so `roles: null` on a selection
 * track silently counted as "no roles", `input: 5` or `channels: 5` reached
 * the library as a number, and a null where the schema allows none (an
 * optional `description`, `original` or `default`) passed as "absent".
 */
/** The schema's own lists of allowed words ($defs and the section enums). */
const ROLES = ['alternate', 'audio_description', 'commentary', 'sdh', 'forced', 'other'];
const TRACK_TYPES = ['video', 'audio', 'subtitle', 'other'];

const ACCESSIBILITY_SHAPE = [
    'required' => [],
    'optional' => ['audio_description' => 'bool', 'captions' => 'bool'],
];
const SELECT_TRACK_SHAPE = [
    'required' => ['id' => 'string', 'tag' => 'string', 'roles' => 'list<string>'],
    'optional' => ['default' => 'bool', 'original' => 'bool'],
    'enums' => ['roles' => ROLES],
];
const PRESENTATION_CASE_SHAPE = [
    'required' => [
        'id' => 'string',
        'rules' => 'list<string>',
        'description' => 'string',
        'preferences' => 'list<string>',
        'accessibility' => 'object',
        'display_names' => 'map<string>',
        'collation_keys' => 'map<string>',
        'items' => 'list<object>',
        'expected' => 'list<string>',
    ],
    'optional' => ['selected' => 'string'],
    'objects' => ['accessibility' => ACCESSIBILITY_SHAPE],
    'lists' => [
        'items' => [
            'required' => ['id' => 'string', 'tag' => 'string'],
            'optional' => ['type' => 'string', 'roles' => 'list<string>', 'original' => 'bool'],
            'enums' => ['type' => ['audio', 'subtitle', 'text'], 'roles' => ROLES],
        ],
    ],
];
const CASE_SHAPES = [
    'canonicalise' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'input' => 'string',
            'expected' => 'string|null',
            'kind' => 'string',
        ],
        'optional' => ['note' => 'string'],
        'enums' => ['kind' => ['ordinary', 'grandfathered', 'privateuse', 'malformed']],
    ],
    'legacy_three_letter' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'input' => 'string',
            'expected' => 'string|null',
        ],
        'optional' => [],
    ],
    'iso639_2_write' => [
        'required' => ['id' => 'string', 'rules' => 'list<string>', 'input' => 'string', 'expected' => 'object'],
        'optional' => ['description' => 'string'],
        'objects' => ['expected' => ['required' => ['b' => 'string', 't' => 'string'], 'optional' => []]],
    ],
    'posix_locale' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'input' => 'string',
            'expected' => 'string|null',
        ],
        'optional' => ['description' => 'string'],
    ],
    'sidecar_name (build)' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'mode' => 'string',
            'stem' => 'string',
            'tag' => 'string',
            'roles' => 'list<string>',
            'extension' => 'string',
            'number' => 'int|null',
            'expected' => 'string|null',
        ],
        'optional' => ['error' => 'true', 'description' => 'string'],
        'enums' => ['roles' => ROLES],
        'refusal' => true,
    ],
    'sidecar_name (parse)' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'mode' => 'string',
            'stem' => 'string',
            'filename' => 'string',
            'expected' => 'object|null',
        ],
        'optional' => [],
        'objects' => [
            'expected' => [
                'required' => [
                    'tag' => 'string|null',
                    'unrecognised' => 'string|null',
                    'roles' => 'list<string>',
                    'number' => 'int|null',
                    'extension' => 'string',
                ],
                'optional' => [],
                'enums' => ['roles' => ['sdh', 'forced', 'commentary']],
            ],
        ],
    ],
    'canonical_order' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'description' => 'string',
            'items' => 'list<object>',
            'expected' => 'list<string>',
        ],
        'optional' => [],
        'lists' => [
            'items' => ['required' => ['tag' => 'string'], 'optional' => ['id' => 'string', 'original' => 'bool']],
        ],
    ],
    'track_order' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'description' => 'string',
            'tracks' => 'list<object>',
            'expected' => 'list<string>',
        ],
        'optional' => [],
        'lists' => [
            'tracks' => [
                'required' => ['id' => 'string', 'type' => 'string', 'tag' => 'string', 'roles' => 'list<string>'],
                'optional' => ['original' => 'bool'],
                'enums' => ['type' => TRACK_TYPES, 'roles' => ROLES],
            ],
        ],
    ],
    'presentation_order' => PRESENTATION_CASE_SHAPE,
    'subtitle_menu' => PRESENTATION_CASE_SHAPE,
    'label' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'type' => 'string',
            'language_name' => 'string',
            'roles' => 'list<string>',
            'role_names' => 'map<string>',
            'channels' => 'string|null',
            'expected' => 'string',
        ],
        'optional' => ['description' => 'string'],
        'enums' => ['type' => ['audio', 'subtitle'], 'roles' => ROLES],
    ],
    'match' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'preference' => 'string',
            'candidate' => 'string',
            'expected' => 'object',
        ],
        'optional' => ['description' => 'string'],
        'objects' => [
            'expected' => [
                'required' => ['level' => 'string', 'distance' => 'int'],
                'optional' => [],
                'enums' => ['level' => ['exact', 'general', 'specific', 'related', 'none']],
            ],
        ],
    ],
    'auto_select_audio' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'description' => 'string',
            'preferences' => 'list<string>',
            'accessibility' => 'object',
            'tracks' => 'list<object>',
            'expected' => 'string|null',
        ],
        'optional' => ['error' => 'true'],
        'objects' => ['accessibility' => ACCESSIBILITY_SHAPE],
        'lists' => ['tracks' => SELECT_TRACK_SHAPE],
        'refusal' => true,
    ],
    'auto_select_subtitle' => [
        'required' => [
            'id' => 'string',
            'rules' => 'list<string>',
            'description' => 'string',
            'mode' => 'string',
            'preferences' => 'list<string>',
            'accessibility' => 'object',
            'audio' => 'string|null',
            'tracks' => 'list<object>',
            'expected' => 'string|null',
        ],
        'optional' => ['error' => 'true'],
        'objects' => ['accessibility' => ACCESSIBILITY_SHAPE],
        'lists' => ['tracks' => SELECT_TRACK_SHAPE],
        'enums' => ['mode' => ['automatic', 'always', 'forced_only', 'off']],
        'refusal' => true,
    ],
];

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
 * all refused here, before a single case is executed. Reads the file as
 * decoded with objects kept as objects (see isJsonObject()), so a section
 * written as an object - even an empty `{}` - is refused as not a list.
 */
function checkSections(\stdClass $fixtures): void
{
    $fields = get_object_vars($fixtures);
    $actual = array_values(array_diff(array_map('strval', array_keys($fields)), METADATA_KEYS));
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
        if (!is_array($fields[$section])) {
            fwrite(STDERR, "The fixture file's '{$section}' section is not a list.\n");
            exit(1);
        }
        if (count($fields[$section]) === 0) {
            fwrite(
                STDERR,
                "The fixture file's '{$section}' section is empty; this runner needs at least "
                . "one case in it.\n"
            );
            exit(1);
        }
    }
}

/** Stops the whole run with a fixture-shape error (policy section 8.1). */
function fixtureError(string $where, string $problem): never
{
    fwrite(STDERR, "Fixture error in {$where}: {$problem}\n");
    exit(1);
}

/**
 * True for a JSON object. The shape checks read the fixture file decoded a
 * SECOND time, with json_decode()'s objects left as \stdClass objects, so a
 * JSON object is a \stdClass and a JSON list is a PHP array, and the two can
 * never be mistaken for each other.
 *
 * History, so this is not quietly undone: until the stand-in review of
 * revision 5 the shape checks read the copy decoded into PHP arrays (the one
 * the cases are run from), where an empty object `{}` and an empty list `[]`
 * both become the same empty array. So `accessibility: []` and
 * `display_names: []` passed as objects, and `roles: {}` and `rules: {}`
 * passed as lists - `roles: {}` even changed audio-07's answer.
 */
function isJsonObject(mixed $value): bool
{
    return $value instanceof \stdClass;
}

/** A JSON value's kind in plain words, for error messages. */
function jsonKind(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        is_bool($value) => 'true/false',
        is_int($value) => 'a whole number',
        is_float($value) => 'a number with a fraction',
        is_string($value) => 'a string',
        is_array($value) => 'a list',
        default => 'an object',
    };
}

/** True for a JSON list whose every element is a string. (In the decode the
 * shape checks read, every PHP array is a JSON list - see isJsonObject().) */
function isListOfStrings(mixed $value): bool
{
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $element) {
        if (!is_string($element)) {
            return false;
        }
    }
    return true;
}

/**
 * Checks the fixture file's own top-level fields against TOP_LEVEL_FIELDS
 * (policy section 8.1: a harness must fail, not report success, on a file
 * that breaks the schema), and that the file's data_version is the version
 * of the reference data file actually given - the same check the Rust
 * runner makes against the data it embeds, so the two runners refuse the
 * same files. Stops the run on the first problem.
 */
function checkTopLevel(\stdClass $fixtures, string $dataPath): void
{
    $fields = get_object_vars($fixtures);
    foreach (TOP_LEVEL_FIELDS as $field => $rule) {
        if (!array_key_exists($field, $fields)) {
            if ($rule['required']) {
                fixtureError('the top level', "missing required field '{$field}'.");
            }
            continue;
        }
        $value = $fields[$field];
        if (!is_string($value)) {
            fixtureError("the top level -> {$field}", 'must be a string, not ' . jsonKind($value) . '.');
        }
        if ($rule['rule'] === 'const' && $value !== $rule['value']) {
            fixtureError("the top level -> {$field}", "must be '{$rule['value']}', not '{$value}'.");
        }
        if ($rule['rule'] === 'version' && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $value) !== 1) {
            fixtureError(
                "the top level -> {$field}",
                "must be a version of three dot-separated numbers such as '1.0.0', not '{$value}'."
            );
        }
    }
    // Read here directly (not through Policy) so this check does not depend
    // on the library it is testing. A data file that cannot be read or has
    // no data_version is left for Policy::loadData() to refuse, with its
    // own message, a few lines later.
    $dataRaw = @file_get_contents($dataPath);
    $data = is_string($dataRaw) ? json_decode($dataRaw, true) : null;
    if (is_array($data) && is_string($data['data_version'] ?? null)
        && $data['data_version'] !== $fields['data_version']) {
        fixtureError(
            'the top level -> data_version',
            "the cases were computed against reference data '{$fields['data_version']}', but the "
            . "data file given ({$dataPath}) is '{$data['data_version']}'."
        );
    }
}

/**
 * Checks one value against one type from a shape (see CASE_SHAPES' doc
 * comment for the type words), recursing into nested objects and lists.
 * Stops the run on the first problem.
 *
 * @param array<string, mixed> $shape the shape the value's field belongs
 *   to, for its 'objects' and 'lists'.
 */
function checkType(mixed $value, string $type, array $shape, string $field, string $where): void
{
    $nullable = str_ends_with($type, '|null');
    $base = $nullable ? substr($type, 0, -strlen('|null')) : $type;
    if ($value === null) {
        if (!$nullable) {
            fixtureError($where, 'is null, which the schema does not allow here.');
        }
        return;
    }
    $ok = match ($base) {
        'string' => is_string($value),
        'bool' => is_bool($value),
        'int' => is_int($value),
        'true' => $value === true,
        'list<string>' => isListOfStrings($value),
        'map<string>' => isJsonObject($value)
            && array_filter(get_object_vars($value), 'is_string') === get_object_vars($value),
        'object' => isJsonObject($value),
        'list<object>' => is_array($value),
        default => fixtureError($where, "this runner has no rule for the type '{$type}' (a bug in the runner)."),
    };
    if (!$ok) {
        if ($base === 'true') {
            fixtureError($where, "'error' may only be true (the schema's const); leave it out instead.");
        }
        $expected = match ($base) {
            'string' => 'a string',
            'bool' => 'true or false',
            'int' => 'a whole number',
            'list<string>' => 'a list of strings',
            'map<string>' => 'an object whose values are all strings',
            'object' => 'a JSON object',
            default => 'a list',
        };
        // Say what was found instead; where the value is the right kind of
        // container with a wrong thing inside it, name that thing.
        $found = 'it is ' . jsonKind($value);
        $elements = match (true) {
            $base === 'list<string>' && is_array($value) => $value,
            $base === 'map<string>' && isJsonObject($value) => get_object_vars($value),
            default => [],
        };
        foreach ($elements as $key => $element) {
            if (!is_string($element)) {
                $which = $base === 'list<string>' ? "item {$key}" : "'{$key}'";
                $found = "{$which} is " . jsonKind($element);
                break;
            }
        }
        fixtureError($where, "must be {$expected}" . ($nullable ? ' or null' : '') . ", but {$found}.");
    }
    if ($base === 'object') {
        checkShape($value, $shape['objects'][$field], $where);
    } elseif ($base === 'list<object>') {
        foreach ($value as $index => $item) {
            checkShape($item, $shape['lists'][$field], "{$where}[{$index}]");
        }
    }
}

/**
 * Checks one value against the schema's list of allowed words for its
 * field (a shape's 'enums'): a string must be one of them, and so must each
 * string in a list. Stops the run on the first problem.
 *
 * Before the stand-in review of revision 5 this was not checked at all, and
 * the worst case was not a quiet pass but a crash: a subtitle case whose
 * `mode` was not one of the four modes reached SubtitleMode::from(), which
 * threw an error nothing caught, and PHP stopped with exit code 255 and no
 * message naming the case.
 *
 * @param list<string> $allowed
 */
function checkEnum(mixed $value, array $allowed, string $where): void
{
    $values = is_array($value) ? $value : [$value];
    foreach ($values as $index => $word) {
        if (is_string($word) && !in_array($word, $allowed, true)) {
            $at = is_array($value) ? "{$where}[{$index}]" : $where;
            fixtureError(
                $at,
                "'{$word}' is not one of the values the schema allows here ("
                . implode(', ', $allowed) . ').'
            );
        }
    }
}

/**
 * Checks one object against one shape from CASE_SHAPES (see its doc
 * comment): every required field present, no field the schema does not
 * allow, every field's value of the type the schema gives it - recursing
 * into nested objects and lists - and, for a field with a list of allowed
 * words ('enums'), a value from that list. Stops the run on the first
 * problem. Uses array_key_exists(), not isset(): a required field whose
 * value is legitimately null (several are, such as 'expected' on a refusal
 * case) still counts as present; only an ABSENT key is an error.
 *
 * @param array<string, mixed> $shape
 */
function checkShape(mixed $value, array $shape, string $where): void
{
    if (!isJsonObject($value)) {
        fixtureError($where, 'is not a JSON object (it is ' . jsonKind($value) . ').');
    }
    $fields = get_object_vars($value);
    $missing = array_values(array_filter(
        array_keys($shape['required']),
        static fn (string $field): bool => !array_key_exists($field, $fields)
    ));
    if ($missing !== []) {
        fixtureError($where, 'missing required field(s): ' . implode(', ', $missing));
    }
    $types = $shape['required'] + $shape['optional'];
    $unknown = array_values(array_diff(array_map('strval', array_keys($fields)), array_keys($types)));
    if ($unknown !== []) {
        fixtureError(
            $where,
            'field(s) the schema does not allow here: ' . implode(', ', $unknown)
            . (in_array('error', $unknown, true) ? ' (this section has no refusal cases)' : '')
        );
    }
    foreach ($fields as $field => $fieldValue) {
        checkType($fieldValue, $types[$field], $shape, (string) $field, "{$where} -> {$field}");
        if (isset($shape['enums'][$field])) {
            checkEnum($fieldValue, $shape['enums'][$field], "{$where} -> {$field}");
        }
    }
    if (($shape['refusal'] ?? false) && array_key_exists('error', $fields) && $fields['expected'] !== null) {
        fixtureError($where, "carries error: true but its expected answer is not null.");
    }
}

/**
 * Checks, once, that every shape in CASE_SHAPES is complete: each field
 * typed 'object' or 'list<object>' has its nested shape, so checkType()
 * can never meet a nested value with nothing to check it against, and each
 * list of allowed words belongs to a field the shape has. A fault here is a
 * bug in this runner, not in the fixture file.
 *
 * @param array<string, mixed> $shape
 */
function checkShapeTable(array $shape, string $name): void
{
    $types = $shape['required'] + $shape['optional'];
    foreach ($types as $field => $type) {
        $base = str_ends_with($type, '|null') ? substr($type, 0, -strlen('|null')) : $type;
        if ($base === 'object') {
            if (!isset($shape['objects'][$field])) {
                fixtureError("this runner's shape '{$name}'", "field '{$field}' has no nested shape.");
            }
            checkShapeTable($shape['objects'][$field], "{$name} -> {$field}");
        } elseif ($base === 'list<object>') {
            if (!isset($shape['lists'][$field])) {
                fixtureError("this runner's shape '{$name}'", "field '{$field}' has no item shape.");
            }
            checkShapeTable($shape['lists'][$field], "{$name} -> {$field}[]");
        }
    }
    foreach (array_keys($shape['enums'] ?? []) as $field) {
        if (!isset($types[$field])) {
            fixtureError("this runner's shape '{$name}'", "allowed words given for '{$field}', which it has no type for.");
        }
    }
}

/**
 * Checks every case in every section against its shape before anything
 * runs (policy section 8.1). sidecar_name cases are checked against the
 * build or parse shape their own 'mode' names.
 */
function checkCaseShapes(\stdClass $fixtures): void
{
    $fields = get_object_vars($fixtures);
    foreach (EXPECTED_SECTIONS as $section) {
        foreach ($fields[$section] as $index => $case) {
            $caseFields = isJsonObject($case) ? get_object_vars($case) : [];
            $id = is_string($caseFields['id'] ?? null) ? $caseFields['id'] : "(case {$index}, no id)";
            $shapeName = $section;
            if ($section === 'sidecar_name') {
                $mode = $caseFields['mode'] ?? null;
                if ($mode !== 'build' && $mode !== 'parse') {
                    fixtureError("'sidecar_name' case '{$id}' -> mode", "must be 'build' or 'parse'.");
                }
                $shapeName = "sidecar_name ({$mode})";
            }
            checkShape($case, CASE_SHAPES[$shapeName], "'{$shapeName}' case '{$id}'");
        }
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

/** The case being run, for the message below; empty until cases run. */
$currentCase = '';

// A safety net: any error the runner does not catch itself stops the run
// with a plain message naming the case and exit code 1, never PHP's own
// uncaught-error report and exit code 255. The shape checks above refuse a
// damaged case file before anything runs, so reaching this means the
// library threw where the case expected an answer - for example a refusal
// case whose `error: true` was removed. (Found by the stand-in review of
// revision 5, which saw exit code 255 for both kinds of fault.)
set_exception_handler(static function (\Throwable $error): void {
    global $currentCase;
    $where = $currentCase === '' ? 'before any case ran' : "while running case '{$currentCase}'";
    fwrite(
        STDERR,
        "Error {$where}: " . get_class($error) . ': ' . $error->getMessage() . "\n"
        . "If the case expects a refusal, it must carry error: true; otherwise the library "
        . "threw where the case expects an answer.\n"
    );
    exit(1);
});

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
// Decoded twice. The shape checks read the second copy, in which JSON
// objects stay \stdClass objects, so `{}` and `[]` are told apart (see
// isJsonObject()); the cases are then run from the first, in which objects
// are PHP arrays - the form the library takes.
$fixtures = json_decode($fixtureRaw, true);
$fixtureObjects = json_decode($fixtureRaw, false);
if ($fixtures === null && json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "{$args['fixtures']} is not valid JSON: " . json_last_error_msg() . "\n");
    exit(1);
}
if (!($fixtureObjects instanceof \stdClass) || !is_array($fixtures)) {
    fwrite(STDERR, "{$args['fixtures']} is not a JSON object at the top level.\n");
    exit(1);
}

foreach (CASE_SHAPES as $shapeName => $shape) {
    checkShapeTable($shape, $shapeName);
}
checkTopLevel($fixtureObjects, $args['data']);
checkSections($fixtureObjects);
checkCaseShapes($fixtureObjects);

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
    $casesRun++;
    $currentCase = "{$case['id']} (canonicalise)";
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
    $casesRun++;
    $currentCase = "{$case['id']} (legacy_three_letter)";
    check($case['id'], Policy::fromLegacyThreeLetter($case['input']), $case['expected']);
}

// --- iso639_2_write (TRACK-070) ------------------------------------------
foreach ($fixtures['iso639_2_write'] as $case) {
    $casesRun++;
    $currentCase = "{$case['id']} (iso639_2_write)";
    check($case['id'], Policy::iso6392CodesForWriting($case['input']), $case['expected']);
}

// --- posix_locale (LANG-004) --------------------------------------------
foreach ($fixtures['posix_locale'] as $case) {
    $casesRun++;
    $currentCase = "{$case['id']} (posix_locale)";
    check($case['id'], Policy::fromPosixLocale($case['input']), $case['expected']);
}

// --- sidecar_name (TEXT-030) ---------------------------------------------
foreach ($fixtures['sidecar_name'] as $case) {
    $casesRun++;
    $currentCase = "{$case['id']} (sidecar_name)";
    if ($case['mode'] === 'build') {
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
        $parsed = Policy::parseSidecarName($case['stem'], $case['filename']);
        // The case file checks five parts. parseSidecarName() also returns
        // 'ignored' (the parts TEXT-030 says SHOULD be reported), which the
        // case file does not record, so only the five are compared - in the
        // case file's own key order, since check() compares arrays exactly.
        if ($parsed !== null) {
            $parsed = [
                'tag' => $parsed['tag'],
                'unrecognised' => $parsed['unrecognised'],
                'roles' => $parsed['roles'],
                'number' => $parsed['number'],
                'extension' => $parsed['extension'],
            ];
        }
        check($case['id'], $parsed, $case['expected']);
    }
}

// --- canonical_order (LANG-010 to LANG-027) -----------------------------
foreach ($fixtures['canonical_order'] as $case) {
    $casesRun++;
    $currentCase = "{$case['id']} (canonical_order)";
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
    $casesRun++;
    $currentCase = "{$case['id']} (track_order)";
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
    $casesRun++;
    $currentCase = "{$case['id']} (presentation_order)";
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
    $casesRun++;
    $currentCase = "{$case['id']} (subtitle_menu)";
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
    $casesRun++;
    $currentCase = "{$case['id']} (label)";
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
    $casesRun++;
    $currentCase = "{$case['id']} (match)";
    $result = Policy::matchTags($case['preference'], $case['candidate']);
    $actual = ['level' => $result->level->value, 'distance' => $result->distance];
    check($case['id'], $actual, $case['expected']);
}

// --- auto_select_audio (AUTO-010, AUTO-020, AUTO-040) --------------------
foreach ($fixtures['auto_select_audio'] as $case) {
    $casesRun++;
    $currentCase = "{$case['id']} (auto_select_audio)";
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
    $casesRun++;
    $currentCase = "{$case['id']} (auto_select_subtitle)";
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

$currentCase = 'the PHP-specific checks below';

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

// Two reporting additions from policy revision 4 that the shared case file
// does not record (their answers are SHOULD-level reporting, not part of
// any case's expected value), mirrored by unit tests in the Rust crate.
//
// fromLegacyThreeLetterAll(): one entry per NUL-separated value, in order,
// null for an unrecognised one (kept in place, not dropped), and entry 0
// always equal to what fromLegacyThreeLetter() returns.
check(
    'php-legacy-all-values',
    Policy::fromLegacyThreeLetterAll("eng\0fre\0\0zzz\0XXX\0\0\0"),
    ['en', 'fr', null, null, 'und']
);
$phpSpecificChecksRun++;
foreach (['', " ger \0 fre", "\0eng", "fre-ca\0", "English\0en"] as $legacyInput) {
    check(
        'php-legacy-all-first-is-primary',
        Policy::fromLegacyThreeLetterAll($legacyInput)[0],
        Policy::fromLegacyThreeLetter($legacyInput),
        json_encode($legacyInput)
    );
    $phpSpecificChecksRun++;
}

// The text a malformed value keeps (LANG-026): the value after LANG-001
// step 1's trim of space, tab, line feed and carriage return - so a value of
// nothing but those keeps the empty text. The case file cannot check this:
// a canonicalise case records only `expected: null` and `kind: malformed`
// for a malformed value, not the text it keeps.
foreach ([
    ' ' => '',
    "\t\n" => '',
    " \t\r\n " => '',
    "  English\t" => 'English',
    "\u{a0}" => "\u{a0}",              // a no-break space is not trimmed
    " en_US\u{a0} " => "en_US\u{a0}",
] as $malformedInput => $keptText) {
    $malformedTag = Policy::canonicalise($malformedInput);
    check(
        'php-malformed-keeps-trimmed-text',
        [$malformedTag->kind->value, $malformedTag->tag],
        ['malformed', $keptText],
        json_encode($malformedInput)
    );
    $phpSpecificChecksRun++;
}

// parseSidecarName()'s 'ignored': the parts that were neither a role word
// nor a number, as written, in order - a run of ten digits included, an
// overridden earlier number not.
check(
    'php-sidecar-ignored-parts',
    Policy::parseSidecarName('Film', 'Film.en.x.2.1234567890.CC.old.3.srt'),
    [
        'tag' => 'en',
        'unrecognised' => null,
        'roles' => ['sdh'],
        'number' => 3,
        'ignored' => ['x', '1234567890', 'old'],
        'extension' => 'srt',
    ]
);
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
