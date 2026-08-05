<?php

declare(strict_types=1);

/**
 * iHymns — Media identifier vocabulary guard (#1741 D5)
 *
 * ELI5
 * ----
 * `includes/media_identifiers.php` is the ONE place that says "these are the
 * only IdType/IdScope values `tblSongExternalIds` may hold, and these are the
 * only work-identifier slugs". This test makes sure that promise stays true
 * in TWO directions at once:
 *
 *   1. Nothing in the code map claims a slug this test doesn't independently
 *      recognise (a stray/typo'd/invented entry slipped into the registry).
 *   2. Nothing this test independently recognises is MISSING from the code
 *      map (an entry was silently deleted, or never added in the first
 *      place, even though the spec calls for it).
 *
 * WHY TWO INDEPENDENT LISTS (rule #35 — "a comment saying keep in sync is
 * the failure, not the fix"): if this test just re-imported
 * RECORDING_EXTERNAL_ID_TYPES and asserted things about itself, it would
 * agree with itself by construction and could never go red. Instead this
 * file HAND-TRANSCRIBES the vocabulary from the authoritative source
 * (.claude/media-identifiers-spec.md §4b + the D5 task brief's Deliverable 1
 * list) as its OWN const, independently of includes/media_identifiers.php,
 * and diffs the two. A drift in EITHER file — the registry gains an
 * unlisted slug, or loses a spec-required one — shows up as a mismatch.
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely in
 * memory, never touching the tree, mirroring test-musician-rename-guard.php):
 *   FAILS-HIGH — inject a bogus slug into a COPY of the real map (in memory)
 *     and assert the diff function reports it as an unexpected extra.
 *   FAILS-LOW  — remove a real slug from a COPY of the real map (in memory)
 *     and assert the diff function reports it as a missing expectation.
 *   Both directions run through the SAME core diff function the real
 *   assertions use, so a passing mutation test is evidence about that
 *   function's logic, not a parallel checker that only agrees with itself.
 *   The same two-direction mutation is additionally run against the
 *   schema.sql VARCHAR-not-ENUM check (§ "SCHEMA CHECKS" below), by
 *   mutating an in-memory COPY of the relevant schema.sql text, never the
 *   file on disk.
 *
 * Pure source-tree scan — no DB required — so it slots into the CI lint
 * step alongside `php -l` and the other guards.
 *
 *   php tests/php/test-media-identifiers.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see appWeb/public_html/includes/media_identifiers.php
 * @see appWeb/.sql/migrate-song-external-ids.php
 * @see appWeb/.sql/migrate-work-bowi.php
 * @see .claude/media-identifiers-spec.md §4b (taxonomy), §4c (decisions)
 */

$repoRoot = dirname(__DIR__, 2);

/* =========================================================================
 * INDEPENDENTLY-TRANSCRIBED EXPECTED VOCABULARY (source: spec §4b + the D5
 * task brief's Deliverable 1 list — NOT read from includes/media_identifiers
 * .php). This is deliberately a SEPARATE hand-typed list, not a derivation
 * of the code map — see the file doc-block for why that's the point.
 * ========================================================================= */

/** tblSongExternalIds.IdScope allow-list (spec §4b entity model, 6 buckets). */
const MI_EXPECTED_SCOPES = ['recording', 'song', 'work', 'release', 'product', 'party'];

/** tblSongExternalIds.IdType allow-list — Deliverable 1's explicit list
 *  (Provider/DSP + Database + core Industry IDs + Luminate's own id) plus
 *  spec §4b's full "Legacy IDs" enumeration and "release/product reserve"
 *  parenthetical (decision A=c), PLUS 'genius' (#1747 D5 backfill addendum —
 *  not part of the original Luminate §4b list; promoted from the
 *  pre-existing tblSongIdentityMap.GeniusTrackId column, see
 *  media_identifiers.php's own entry comment). 41 slugs total. */
const MI_EXPECTED_RECORDING_TYPES = [
    // Industry
    'isrc',
    // Provider (DSP) IDs — spec §4b, 13 providers
    'apple-music', 'spotify', 'sirius-xm', 'youtube-music', 'amazon-music',
    'deezer', 'soundcloud', 'anghami', 'melon', 'flo', 'beatport', 'tencent', 'epic',
    // Database IDs — spec §4b, 10 providers (incl. MusicBrainz/AcoustID),
    // + 'genius' as an 11th, #1747 D5 addendum (see const doc-comment above)
    'musicbrainz-recording', 'acoustid', 'gracenote', 'tms', 'discogs', 'wikidata',
    'imdb', 'mediabase', 'soundexchange', 'allmusic', 'rateyourmusic', 'genius',
    // Luminate's own per-entity id
    'luminate',
    // Legacy IDs — spec §4b full enumeration (recording/song-scope half)
    'mc-recording', 'mc-recording-group', 'mc-song', 'nielsen', 'bds', 'soundscan',
    // Release/product reserve — decision A=c (spec §4c) incl. legacy MC_* release/product codes
    'icpn', 'grid', 'upc', 'ean', 'catalog-number', 'mc-release', 'mc-release-group', 'mc-product',
];

/** WORK_IDENTIFIER_TYPES allow-list — spec §4b work/composition IDs
 *  (ISWC/BOWI/IPI-family/MusicBrainz-Work) plus the PRO/HFA list Deliverable
 *  3 names explicitly. */
const MI_EXPECTED_WORK_TYPES = [
    'iswc', 'bowi', 'ccli', 'musicbrainz-work',
    'ascap', 'bmi', 'sesac', 'prs', 'gema', 'socan', 'apra', 'hfa',
];

/** PUBLICATION_IDENTIFIER_TYPES allow-list — Songbook/Catalogue Enhancements
 *  epic, Feature 6 (`.claude/songbook-catalogue-enhancements-plan.md`):
 *  ARK / OpenLibrary Work / OpenLibrary Edition / ISBN / ISSN, the five
 *  identifiers a publication entity (songbook/series/catalogue) can carry.
 *  Independently transcribed here (NOT read from media_identifiers.php),
 *  same rationale as MI_EXPECTED_WORK_TYPES above — this list agreeing with
 *  itself would prove nothing. */
const MI_EXPECTED_PUBLICATION_TYPES = [
    'ark', 'openlibrary-work', 'openlibrary-edition', 'isbn', 'issn',
];

/** Storage-column WIDTH each publication identifier binds into, INDEPENDENTLY
 *  transcribed from schema.sql (NOT read from the code map) — the same
 *  three-way-agreement discipline the MI_EXPECTED_* key lists use. The code
 *  map's `maxlen` MUST equal this (rule #19 byte-mirror) so a format-valid
 *  but over-length value is rejected by mediaIdentifierPublicationClean()
 *  BEFORE it overflows the column under STRICT mysqli (#1765 review). Each
 *  entry names the representative table the width is read from (ArkId/Isbn/
 *  OpenLibrary* live on tblSongbooks; Issn is the one identifier that is
 *  absent from tblSongbooks and lives on tblSongbookSeries — see the schema
 *  comments and the epic plan's "Adversarial notes"). */
const MI_EXPECTED_PUBLICATION_MAXLEN = [
    'ark'                 => ['ArkId',                'tblSongbooks',      80],
    'openlibrary-work'    => ['OpenLibraryWorkId',    'tblSongbooks',      20],
    'openlibrary-edition' => ['OpenLibraryEditionId', 'tblSongbooks',      20],
    'isbn'                => ['Isbn',                 'tblSongbooks',      20],
    'issn'                => ['Issn',                 'tblSongbookSeries', 20],
];

/**
 * PURE core: diff an actual key list against an expected key list, returning
 * human-readable mismatch strings (empty = clean). Both mutation self-tests
 * and the real assertions call this SAME function.
 *
 * @param list<string> $actual
 * @param list<string> $expected
 * @return list<string>
 */
function miDiffKeys(string $label, array $actual, array $expected): array
{
    $out = [];
    $extra   = array_values(array_diff($actual, $expected));
    $missing = array_values(array_diff($expected, $actual));
    foreach ($extra as $k) {
        $out[] = "{$label}: '{$k}' is in the code map but NOT in this test's independently-transcribed "
               . 'expected list — either a new slug needs adding to MI_EXPECTED_* above (if it traces to '
               . 'the spec), or it is an invented/typo\'d entry that should be removed from the code map.';
    }
    foreach ($missing as $k) {
        $out[] = "{$label}: '{$k}' is expected (spec-traceable) but MISSING from the code map — "
               . 'it was likely deleted, or never added.';
    }
    return $out;
}

$failures = [];

/* ---- Load the real registry (guarded require — the file 403s direct HTTP
   access via a SCRIPT_FILENAME check, so set it to something else first,
   matching musician_helpers.php's identical guard). ---- */
$mediaIdFile = $repoRoot . '/appWeb/public_html/includes/media_identifiers.php';
if (!is_readable($mediaIdFile)) {
    fwrite(STDERR, "FATAL: could not read $mediaIdFile\n");
    exit(1);
}
$_SERVER['SCRIPT_FILENAME'] = 'test-runner';
require_once $mediaIdFile;

if (!function_exists('mediaIdentifierRecordingTypes') || !function_exists('mediaIdentifierScopes')
    || !function_exists('mediaIdentifierWorkTypes') || !function_exists('mediaIdentifierPublicationTypes')
    || !function_exists('mediaIdentifierPublicationValidate')) {
    fwrite(STDERR, "FATAL: media_identifiers.php loaded but expected functions are missing\n");
    exit(1);
}

$actualRecordingTypes   = array_keys(mediaIdentifierRecordingTypes());
$actualScopes            = mediaIdentifierScopes();
$actualWorkTypes         = array_keys(mediaIdentifierWorkTypes());
$actualPublicationTypes  = array_keys(mediaIdentifierPublicationTypes());

foreach (miDiffKeys('RECORDING_EXTERNAL_ID_TYPES', $actualRecordingTypes, MI_EXPECTED_RECORDING_TYPES) as $m) { $failures[] = $m; }
foreach (miDiffKeys('SONG_EXTERNAL_ID_SCOPES',      $actualScopes,          MI_EXPECTED_SCOPES)          as $m) { $failures[] = $m; }
foreach (miDiffKeys('WORK_IDENTIFIER_TYPES',        $actualWorkTypes,       MI_EXPECTED_WORK_TYPES)      as $m) { $failures[] = $m; }
foreach (miDiffKeys('PUBLICATION_IDENTIFIER_TYPES', $actualPublicationTypes, MI_EXPECTED_PUBLICATION_TYPES) as $m) { $failures[] = $m; }

/* ---------------------------------------------------------------------- *
 * CROSS-MAP referential checks — internal self-consistency, derived from
 * the maps themselves (not hand-typed), so a new entry is automatically
 * covered.
 * ---------------------------------------------------------------------- */

/* Every RECORDING_EXTERNAL_ID_TYPES entry's declared `scope` must itself be
   a member of SONG_EXTERNAL_ID_SCOPES — the two maps must agree on what a
   "scope" even is. */
foreach (mediaIdentifierRecordingTypes() as $idType => $def) {
    if (!in_array($def['scope'] ?? null, $actualScopes, true)) {
        $failures[] = "RECORDING_EXTERNAL_ID_TYPES['{$idType}']['scope'] = "
                    . var_export($def['scope'] ?? null, true) . ' is not a member of SONG_EXTERNAL_ID_SCOPES';
    }
}

/* Every WORK_IDENTIFIER_TYPES entry must declare storage as exactly
   'column' or 'royalty' (the only two zero-schema-cost homes documented in
   the file doc-block), 'column' entries must name a real tblWorks column
   (checked against schema.sql below), and 'royalty' entries must carry a
   non-null authorityCode (the literal string tblSongRoyaltyIds.Authority
   would store). */
foreach (mediaIdentifierWorkTypes() as $slug => $def) {
    $storage = $def['storage'] ?? null;
    if (!in_array($storage, ['column', 'royalty'], true)) {
        $failures[] = "WORK_IDENTIFIER_TYPES['{$slug}']['storage'] = " . var_export($storage, true)
                    . " is neither 'column' nor 'royalty'";
        continue;
    }
    if ($storage === 'column' && !is_string($def['column'] ?? null)) {
        $failures[] = "WORK_IDENTIFIER_TYPES['{$slug}'] declares storage='column' but 'column' is not a string";
    }
    if ($storage === 'royalty' && !is_string($def['authorityCode'] ?? null)) {
        $failures[] = "WORK_IDENTIFIER_TYPES['{$slug}'] declares storage='royalty' but 'authorityCode' is not a string";
    }
}

/* Every PUBLICATION_IDENTIFIER_TYPES entry declares storage='column' (the
   ONLY zero-schema-cost home Feature 3 uses for a publication identifier —
   unlike WORK_IDENTIFIER_TYPES there is no 'royalty' alternative here), a
   non-empty string 'column' name, and — per the "same entry shape … plus a
   url template" contract (media_identifiers.php's own doc-comment) — a
   'url' that is either null or a string containing a '%s' placeholder for
   the bare id. */
foreach (mediaIdentifierPublicationTypes() as $slug => $def) {
    if (($def['storage'] ?? null) !== 'column') {
        $failures[] = "PUBLICATION_IDENTIFIER_TYPES['{$slug}']['storage'] = "
                    . var_export($def['storage'] ?? null, true) . " is not 'column'";
    }
    if (!is_string($def['column'] ?? null) || $def['column'] === '') {
        $failures[] = "PUBLICATION_IDENTIFIER_TYPES['{$slug}']['column'] is not a non-empty string";
    }
    if (!array_key_exists('url', $def)) {
        $failures[] = "PUBLICATION_IDENTIFIER_TYPES['{$slug}'] is missing the 'url' key entirely";
        continue;
    }
    $url = $def['url'];
    if ($url !== null && (!is_string($url) || !str_contains($url, '%s'))) {
        $failures[] = "PUBLICATION_IDENTIFIER_TYPES['{$slug}']['url'] must be null or a '%s'-templated string, got "
                    . var_export($url, true);
    }
}

/* Every declared `validate` regex (where present) must actually compile —
   a malformed PCRE would make mediaIdentifierValidateValue() throw/warn at
   the worst possible time (mid-import), not at CI time. */
foreach (mediaIdentifierRecordingTypes() as $idType => $def) {
    $re = $def['validate'] ?? null;
    if ($re === null) { continue; }
    $prev = set_error_handler(static function () { return true; }); // silence the E_WARNING preg_match emits on a bad pattern
    $ok = @preg_match($re, 'probe') !== false;
    set_error_handler($prev);
    if (!$ok) {
        $failures[] = "RECORDING_EXTERNAL_ID_TYPES['{$idType}']['validate'] is not a compilable PCRE: {$re}";
    }
}
foreach (mediaIdentifierWorkTypes() as $slug => $def) {
    $re = $def['validate'] ?? null;
    if ($re === null) { continue; }
    $prev = set_error_handler(static function () { return true; });
    $ok = @preg_match($re, 'probe') !== false;
    set_error_handler($prev);
    if (!$ok) {
        $failures[] = "WORK_IDENTIFIER_TYPES['{$slug}']['validate'] is not a compilable PCRE: {$re}";
    }
}
foreach (mediaIdentifierPublicationTypes() as $slug => $def) {
    $re = $def['validate'] ?? null;
    if ($re === null) { continue; }
    $prev = set_error_handler(static function () { return true; });
    $ok = @preg_match($re, 'probe') !== false;
    set_error_handler($prev);
    if (!$ok) {
        $failures[] = "PUBLICATION_IDENTIFIER_TYPES['{$slug}']['validate'] is not a compilable PCRE: {$re}";
    }
}

/* Every IdType key must physically fit tblSongExternalIds.IdType VARCHAR(40),
   every IdScope value must fit VARCHAR(20) — derived from the map, not a
   hand-typed length list, so a future long slug is caught immediately
   rather than truncating silently at INSERT time on a live DB. */
foreach ($actualRecordingTypes as $idType) {
    if (strlen($idType) > 40) {
        $failures[] = "IdType '{$idType}' is " . strlen($idType) . ' bytes — exceeds tblSongExternalIds.IdType VARCHAR(40)';
    }
}
foreach ($actualScopes as $scope) {
    if (strlen($scope) > 20) {
        $failures[] = "IdScope '{$scope}' is " . strlen($scope) . ' bytes — exceeds tblSongExternalIds.IdScope VARCHAR(20)';
    }
}

/* =========================================================================
 * SCHEMA CHECKS — schema.sql must actually carry the mirror (Deliverable 4's
 * "assert the migration's schema.sql mirror is present") and IdScope/IdType
 * must be VARCHAR, never ENUM (Deliverable 4's rule-#20 check).
 * ========================================================================= */

require_once $repoRoot . '/appWeb/public_html/includes/schema_audit.php';

$schemaFile = $repoRoot . '/appWeb/.sql/schema.sql';
$schemaSql  = is_readable($schemaFile) ? (string)file_get_contents($schemaFile) : '';
if ($schemaSql === '') {
    fwrite(STDERR, "FATAL: could not read $schemaFile\n");
    exit(1);
}

$schemaTables = schemaAuditParseSchema($schemaSql);

if (!isset($schemaTables['tblSongExternalIds'])) {
    $failures[] = 'schema.sql has no tblSongExternalIds CREATE TABLE block — the migrate-song-external-ids.php mirror is missing (rule #19)';
} else {
    $expectedCols = ['Id', 'SongId', 'IdScope', 'IdType', 'IdValue', 'Source', 'SourceRef', 'CreatedAt', 'UpdatedAt'];
    $missingCols  = array_values(array_diff($expectedCols, $schemaTables['tblSongExternalIds']));
    foreach ($missingCols as $c) {
        $failures[] = "schema.sql tblSongExternalIds is missing column '{$c}'";
    }
}

if (!isset($schemaTables['tblWorks']) || !in_array('Bowi', $schemaTables['tblWorks'], true)) {
    $failures[] = 'schema.sql tblWorks has no Bowi column — the migrate-work-bowi.php mirror is missing (rule #19)';
}

if (!preg_match('/UNIQUE\s+KEY\s+uq_bowi\s*\(Bowi\)/i', $schemaSql)) {
    $failures[] = 'schema.sql tblWorks has no uq_bowi UNIQUE key';
}

/**
 * PURE core: does $schemaSql declare $table.$column as VARCHAR (rule #20)?
 * Returns null when the column declaration can't be found at all (a
 * separate, already-reported failure), true/false otherwise. Shared by the
 * real check and both mutation directions below.
 */
function miColumnIsVarchar(string $schemaSql, string $table, string $column): ?bool
{
    if (!preg_match(
        '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+' . preg_quote($table, '/') . '\s*\((.*?)\)\s*ENGINE\s*=/is',
        $schemaSql,
        $tableMatch
    )) {
        return null;
    }
    if (!preg_match('/\b' . preg_quote($column, '/') . '\s+([A-Za-z]+)\s*\(/', $tableMatch[1], $colMatch)) {
        return null;
    }
    return strtoupper($colMatch[1]) === 'VARCHAR';
}

/**
 * PURE core: the declared VARCHAR width of $table.$column in $schemaSql, or
 * null when the column (or its VARCHAR width) can't be found. Sibling of
 * miColumnIsVarchar() — shares the same "first `) ENGINE=` bounds the table
 * block" bound, proven against the same tables. Used by the maxlen byte-mirror
 * assertion + its FAILS-HIGH mutation self-test below (#1765 review).
 */
function miColumnVarcharWidth(string $schemaSql, string $table, string $column): ?int
{
    if (!preg_match(
        '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+' . preg_quote($table, '/') . '\s*\((.*?)\)\s*ENGINE\s*=/is',
        $schemaSql,
        $tableMatch
    )) {
        return null;
    }
    if (!preg_match('/\b' . preg_quote($column, '/') . '\s+VARCHAR\s*\(\s*(\d+)\s*\)/i', $tableMatch[1], $colMatch)) {
        return null;
    }
    return (int)$colMatch[1];
}

$idScopeIsVarchar = miColumnIsVarchar($schemaSql, 'tblSongExternalIds', 'IdScope');
$idTypeIsVarchar  = miColumnIsVarchar($schemaSql, 'tblSongExternalIds', 'IdType');

if ($idScopeIsVarchar !== true) {
    $failures[] = 'tblSongExternalIds.IdScope is not declared VARCHAR in schema.sql (rule #20 — a growable vocabulary must never be ENUM)'
                . ($idScopeIsVarchar === null ? ' (column declaration not found at all)' : '');
}
if ($idTypeIsVarchar !== true) {
    $failures[] = 'tblSongExternalIds.IdType is not declared VARCHAR in schema.sql (rule #20 — a growable vocabulary must never be ENUM)'
                . ($idTypeIsVarchar === null ? ' (column declaration not found at all)' : '');
}

/* =========================================================================
 * VALIDATOR TRUTH TABLE — mediaIdentifierPublicationValidate() (Songbook/
 * Catalogue Enhancements epic, Feature 6). Each row is [slug, value,
 * expectedValid] — a value/expectation PAIR, not just "does it return
 * bool", so a validator loosened to accept-everything or tightened to
 * reject-everything is caught either way (rule #34's both-directions
 * discipline).
 * ========================================================================= */

$publicationValidateCases = [
    /* ARK — valid shape (matches ihymns_canonical_ark()'s own contract:
       ark:/NAAN(5-9 digits)/name). mediaIdentifierPublicationValidate()
       validates the CANONICAL form only (no URL/percent-decoding — that is
       ihymns_canonical_ark()'s job, exercised in test-identifier-normalize.php). */
    ['ark', 'ark:/13960/t8jf3w89z', true],
    ['ark', 'ark:/1234/tooshortnaan', false],   // NAAN only 4 digits (needs 5-9)
    ['ark', 'not-an-ark-at-all', false],
    ['ark', '', false],

    /* OpenLibrary Work vs Edition — CROSS-KIND must be invalid: an edition-
       shaped id ('…M') is not a valid value for the 'openlibrary-work' slug,
       and vice versa. This is the mediaIdentifierPublicationValidate() analogue
       of ihymns_canonical_openlibrary()'s cross-kind null (same property,
       checked at the OTHER layer — the plain truth-check registry, not the
       canonicalising fold). */
    ['openlibrary-work', 'OL102749W', true],
    ['openlibrary-work', 'OL7353617M', false],  // an EDITION id, wrong slug
    ['openlibrary-edition', 'OL7353617M', true],
    ['openlibrary-edition', 'OL102749W', false], // a WORK id, wrong slug
    ['openlibrary-work', 'not-an-ol-id', false],

    /* ISBN — ISBN-10 with a trailing X check "digit" (the shape 020$a
       genuinely produces for some pre-1970s/pre-ISBN-13 hymnals) and a
       13-digit ISBN-13, both cleaned (no hyphens — the caller's job, see
       marcxmlCleanIdentifierRaw() in includes/marcxml.php). */
    ['isbn', '019852663X', true],
    ['isbn', '9780199991234', true],
    ['isbn', '978-0-19-999123-4', false],  // hyphens NOT stripped — caller must pre-clean
    ['isbn', '12345', false],              // wrong length entirely

    /* ISSN — 7 digits + a check character (digit or X), cleaned (no hyphen). */
    ['issn', '0317847X', true],
    ['issn', '03178471', true],
    ['issn', '0317-8471', false],  // hyphen NOT stripped — caller must pre-clean
    ['issn', '123', false],
];
foreach ($publicationValidateCases as [$slug, $value, $expectedValid]) {
    $got = mediaIdentifierPublicationValidate($slug, $value);
    if ($got !== $expectedValid) {
        $failures[] = "mediaIdentifierPublicationValidate('{$slug}', " . var_export($value, true) . ') returned '
                    . var_export($got, true) . ', expected ' . var_export($expectedValid, true);
    }
}
/* Unknown slug and empty/blank value are always invalid, mirroring
   mediaIdentifierWorkValidate()'s contract. */
if (mediaIdentifierPublicationValidate('not-a-real-slug', 'anything') !== false) {
    $failures[] = "mediaIdentifierPublicationValidate('not-a-real-slug', …) must be false for an unrecognised slug";
}
if (mediaIdentifierPublicationValidate('isbn', '') !== false) {
    $failures[] = "mediaIdentifierPublicationValidate('isbn', '') must be false — empty value is never valid";
}
if (mediaIdentifierPublicationValidate('isbn', '   ') !== false) {
    $failures[] = "mediaIdentifierPublicationValidate('isbn', '   ') must be false — whitespace-only is trimmed to empty";
}

/* =========================================================================
 * CLEAN + COLUMN-WIDTH GUARD — mediaIdentifierPublicationClean() (#1765
 * review). This is the storage-ready wrapper the three admin pages' create/
 * update + MARCXML-import handlers call. Beyond the validate truth table
 * above it must ALSO reject a value that is format-VALID but longer than its
 * storage column (an unbounded ARK name part / OpenLibrary digit run), so a
 * STRICT-mysqli overflow can never white-screen the handler. Each row is
 * [slug, raw, expectValue, expectError] — both the accept AND the reject
 * direction for the SAME slug at the length boundary (rule #34).
 * ========================================================================= */
$overArk  = 'ark:/13960/' . str_repeat('t', 100);              // valid shape, 111 chars > 80
$overOlw  = 'OL' . str_repeat('9', 30) . 'W';                  // valid shape, 33 chars  > 20
$publicationCleanCases = [
    /* blank / whitespace → null value, NO error (means "not recorded") */
    ['ark',              '',                    null,                   false],
    ['ark',              '   ',                 null,                   false],
    /* valid + within width → the trimmed value, no error */
    ['ark',              ' ark:/13960/t8jf3w89z ', 'ark:/13960/t8jf3w89z', false],
    ['isbn',             '019852663X',          '019852663X',           false],
    ['openlibrary-work', 'OL102749W',           'OL102749W',            false],
    /* format-INVALID → null value + error */
    ['ark',              'not-an-ark',          null,                   true],
    ['isbn',             '12345',               null,                   true],
    /* format-VALID but OVER the column width → null value + error (the
       #1765 fix — without the maxlen guard these return the value + no error
       and overflow the column). */
    ['ark',              $overArk,              null,                   true],
    ['openlibrary-work', $overOlw,              null,                   true],
];
foreach ($publicationCleanCases as [$slug, $raw, $expectValue, $expectError]) {
    $res = mediaIdentifierPublicationClean($slug, $raw);
    /* Compare via array_key_exists, NOT `?? sentinel` — `value` is legitimately
       null on the blank / invalid / over-length cases, and null-coalescing
       would mask a present-null as "missing". */
    $gotValue = array_key_exists('value', $res) ? $res['value'] : '__missing__';
    if ($gotValue !== $expectValue) {
        $failures[] = "mediaIdentifierPublicationClean('{$slug}', " . var_export($raw, true) . ")['value'] = "
                    . var_export($gotValue, true) . ', expected ' . var_export($expectValue, true);
    }
    $gotError = ($res['error'] ?? null) !== null;
    if ($gotError !== $expectError) {
        $failures[] = "mediaIdentifierPublicationClean('{$slug}', " . var_export($raw, true) . ") error-present = "
                    . var_export($gotError, true) . ', expected ' . var_export($expectError, true)
                    . ($gotError ? " (error: {$res['error']})" : '');
    }
}

/* maxlen BYTE-MIRROR — the code map's `maxlen` for each publication
   identifier must equal the storage column's VARCHAR width in schema.sql,
   three-way (code map == this test's independently-transcribed expectation ==
   schema.sql), so the guard above can never be silently mis-tuned to a width
   that does not match the real column (rule #19). */
foreach (MI_EXPECTED_PUBLICATION_MAXLEN as $slug => [$col, $table, $width]) {
    $def     = mediaIdentifierPublicationTypes()[$slug] ?? null;
    $codeMax = $def['maxlen'] ?? null;
    if ($codeMax !== $width) {
        $failures[] = "PUBLICATION_IDENTIFIER_TYPES['{$slug}']['maxlen'] = " . var_export($codeMax, true)
                    . " but this test expects {$width} (the {$table}.{$col} column width) — must be byte-identical (rule #19)";
    }
    $schemaWidth = miColumnVarcharWidth($schemaSql, $table, $col);
    if ($schemaWidth !== $width) {
        $failures[] = "schema.sql {$table}.{$col} is VARCHAR(" . var_export($schemaWidth, true)
                    . ") but this test expects VARCHAR({$width}) — the code map's maxlen mirror would be validating against the wrong width";
    }
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run on EVERY invocation, entirely in
 * memory. A guard that has never been proven able to fail is not trustworthy.
 * ========================================================================= */

$mutationFailures = [];

/* --- Vocabulary diff: FAILS-HIGH + FAILS-LOW, exercised on all FOUR maps --- */
$vocabCases = [
    'RECORDING_EXTERNAL_ID_TYPES' => [$actualRecordingTypes, MI_EXPECTED_RECORDING_TYPES],
    'SONG_EXTERNAL_ID_SCOPES'      => [$actualScopes,          MI_EXPECTED_SCOPES],
    'WORK_IDENTIFIER_TYPES'        => [$actualWorkTypes,       MI_EXPECTED_WORK_TYPES],
    'PUBLICATION_IDENTIFIER_TYPES' => [$actualPublicationTypes, MI_EXPECTED_PUBLICATION_TYPES],
];
foreach ($vocabCases as $label => [$actual, $expected]) {
    // FAILS-HIGH: inject a bogus key into a COPY of $actual.
    $withExtra = $actual;
    $withExtra[] = 'zz-mutation-probe-bogus-slug';
    $diff = miDiffKeys($label, $withExtra, $expected);
    if ($diff === []) {
        $mutationFailures[] = "{$label} FAILS-HIGH self-test did not go red — an injected bogus slug was not detected";
    }

    // FAILS-LOW: remove a real key from a COPY of $actual (if there is one).
    if ($actual !== []) {
        $withoutOne = $actual;
        array_shift($withoutOne);
        $diff = miDiffKeys($label, $withoutOne, $expected);
        if ($diff === []) {
            $mutationFailures[] = "{$label} FAILS-LOW self-test did not go red — removing a real slug was not detected";
        }
    } else {
        $mutationFailures[] = "{$label} FAILS-LOW self-test could not run — actual list is empty";
    }
}

/* --- Schema VARCHAR-not-ENUM check: FAILS-HIGH direction (mutate a COPY of
   the schema.sql text in memory, turning IdScope into an ENUM, and confirm
   the checker now reports false rather than true). The FAILS-LOW direction
   (the real file today) is already exercised by the real assertion above —
   it currently reads true, i.e. "not an ENUM"; this mutation proves the
   function can ALSO read false when it should. --- */
$mutatedSchema = preg_replace(
    '/IdScope\s+VARCHAR\(20\)/',
    "IdScope     ENUM('recording','song')",
    $schemaSql,
    1
);
if ($mutatedSchema === null || $mutatedSchema === $schemaSql) {
    $mutationFailures[] = 'schema VARCHAR-not-ENUM mutation self-test could not run — the IdScope VARCHAR(20) text pattern was not found to mutate';
} else {
    $mutatedResult = miColumnIsVarchar($mutatedSchema, 'tblSongExternalIds', 'IdScope');
    if ($mutatedResult !== false) {
        $mutationFailures[] = 'schema VARCHAR-not-ENUM FAILS-HIGH self-test did not go red — mutating IdScope to ENUM in memory was not detected';
    }
}

/* --- maxlen width extractor: FAILS-HIGH. Mutate a COPY of schema.sql,
   shrinking tblSongbooks.ArkId from VARCHAR(80) to VARCHAR(40), and confirm
   miColumnVarcharWidth() now reads 40 (not the real 80) — proving the byte-
   mirror assertion above can actually go red on a real width drift. --- */
$mutatedWidth = preg_replace('/(ArkId\s+)VARCHAR\(80\)/', '$1VARCHAR(40)', $schemaSql, 1);
if ($mutatedWidth === null || $mutatedWidth === $schemaSql) {
    $mutationFailures[] = 'maxlen width mutation self-test could not run — the "ArkId VARCHAR(80)" text pattern was not found to mutate';
} else {
    $mutatedW = miColumnVarcharWidth($mutatedWidth, 'tblSongbooks', 'ArkId');
    if ($mutatedW !== 40) {
        $mutationFailures[] = 'maxlen width FAILS-HIGH self-test did not go red — shrinking ArkId to VARCHAR(40) in memory read '
                            . var_export($mutatedW, true) . ', expected 40';
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: Media identifier vocabulary guard (#1741 D5):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    exit(1);
}

echo "PASS: Media identifier vocabulary guard — "
   . count($actualRecordingTypes) . ' recording IdType(s), '
   . count($actualScopes) . ' IdScope(s), '
   . count($actualWorkTypes) . ' work-identifier slug(s), '
   . count($actualPublicationTypes) . " publication-identifier slug(s) all agree with this test's "
   . "independently-transcribed spec list (bidirectional), " . count($publicationValidateCases)
   . " publication validator truth-table case(s) passed, schema.sql carries the "
   . "tblSongExternalIds + tblWorks.Bowi mirrors with IdScope/IdType declared VARCHAR "
   . "(never ENUM), and all mutation self-tests (" . (count($vocabCases) * 2 + 1) . " total) went red as expected.\n";
exit(0);
