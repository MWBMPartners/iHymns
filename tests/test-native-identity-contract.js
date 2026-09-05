/**
 * iHymns — native (Apple + Android) song/work identity contract guard
 * (#1752, rule #34/#35)
 *
 * ELI5
 * ----
 * #1741 P1 added five new fields to songs (subtitle, disambiguation, first
 * published year, copyright years, copyright holder) and #1741 P4b added
 * nine similar fields to Works. #1752 taught the Apple app (and, forward-
 * compat only, the Android app) to decode and show them. This file makes
 * sure that promise keeps holding: it reads the REAL database schema and
 * REAL PHP source to work out what the field list actually is — never a
 * hand-typed list that could quietly drift — and then checks the Swift
 * (and, conditionally, Kotlin) source actually mentions every one of them.
 *
 * #2073 UPDATE (commit 16, `.claude/vocal-parts-2073-plan.md` design pass 7
 * "Native" note): the web side is gaining two brand-new SPARSE keys on
 * every song component — `voices` (who sings each run of lines) and
 * `voiceSpans` (who sings PART of one line) — plus a top-level, include-only
 * `rounds`/`vocalWords` pair on the whole-song shape. A native decoder that
 * FAILS on an unrecognised key would break the app the moment a single
 * curator assigns a voice part to a single song — worse than a missing
 * feature, an outright crash/error on content that used to load fine. This
 * file's Assertions 6-8 (below Assertion 5) check three things, tree-derived
 * from the actual source rather than assumed: (a) `SongComponent.swift` and
 * Android's `SongComponent` data class both declare `voices`/`voiceSpans`
 * as genuinely OPTIONAL (Swift `?`, Kotlin default value) so an absent key
 * decodes to "nothing assigned" rather than throwing; (b) NEITHER platform's
 * decoder has been made artificially strict — Swift has no hand-written
 * `init(from decoder:)` overriding the default tolerant behaviour, and
 * Kotlin's shared parser still sets `ignoreUnknownKeys = true`; (c) the
 * supporting `VoiceRun`/`VoiceRunPart` (Apple) or `VoiceRun`/`VoicePart`
 * (Android) /`VoiceSpan` shapes actually exist as real decodable types, not
 * a `[String: Any]`/`JsonElement` escape hatch. Rendering these values is
 * explicitly DEFERRED to a later commit (see the two model files' own doc
 * comments) — this guard is decode-only, matching that scope.
 *
 * DETAILED
 * --------
 * Modelled on `tests/test-identifier-routes.js` (read first, per the build
 * spec) — same "read the shipped source, no PHP/Swift/Kotlin toolchain
 * required" structural style, same PASS/FAIL `check()` harness shape,
 * auto-discovered by `tools/run-node-tests.js`'s `tests/*.js` glob.
 *
 * TREE-DERIVED, NOT HARDCODED (rule #34):
 *   - The five song keys are extracted from `appWeb/.sql/schema.sql`'s
 *     `tblSongs` CREATE TABLE block, by finding every column whose
 *     `COMMENT` text carries the literal `#1741 P1` doctag — the SAME
 *     derivation `tests/php/test-song-identity-render.php` (#1750 §6, the
 *     PHP twin of this guard) already uses, reimplemented here in JS per
 *     this issue's explicit "do NOT shell to PHP" instruction (a Node test
 *     runner has no guaranteed `php` binary in its execution environment).
 *   - The nine Work keys are extracted from `SongData::getWork()`'s own
 *     `$extraColNames = [...]` array literal (`appWeb/public_html/includes/
 *     SongData.php`) — not retyped, so a tenth P4b column added there is
 *     covered automatically and a column removed stops being asserted
 *     automatically, with no edit needed here either way.
 *   - The camelCase wire-key mapping (`Subtitle` -> `subtitle`) is a pure,
 *     directly-tested function, not eyeballed per column.
 *
 * COMMENT STRIPPING (a comment must never satisfy an assertion): every
 * Swift/Kotlin source file is run through `stripSlashComments()` — handling
 * `//` line comments AND `/* … *\/` block comments WITH NESTING (Swift block
 * comments nest, unlike C/PHP; a naive non-nesting stripper closes a nested
 * comment at its FIRST inner `*\/` and leaks the remainder as "code" — see
 * the mutation self-test below for a fixture that catches exactly that
 * bug) — before any substring assertion runs against it.
 *
 * MUTATION SELF-TESTS (rule #34 — run on EVERY invocation, entirely in
 * memory, never touching the tree, mirroring `test-song-identity-render.
 * php`'s protocol): the CREATE TABLE slicer (finds the right block, stops
 * at the right ENGINE=), the tag extractor (fails-high AND fails-low), the
 * nested-comment stripper (a needle buried in a nested comment must be
 * reported ABSENT; real code alongside it must survive), and the
 * conditional-Android check (partial Android landing -> red; nothing
 * landed -> skipped/green; everything landed -> green) are each proven able
 * to fail before the real assertions below trust them.
 *
 *   node tests/test-native-identity-contract.js
 *
 * Exit status 0 = all pass, 1 = at least one failure (including a mutation
 * self-test that didn't go red as expected).
 *
 * @see .claude/catalogue-1741-1752-plan.md §7
 * @see .claude/vocal-parts-2073-plan.md    design pass 7 "Native" note (#2073, commit 16)
 * @see tests/php/test-song-identity-render.php   the PHP twin this mirrors (#1750 §6)
 * @see tests/php/test-work-identity-fields.php    the P4b guard this also mirrors
 * @see appApple/Packages/iHymnsKit/Sources/IHModels/SongDetail.swift
 * @see appApple/Packages/iHymnsKit/Sources/IHModels/SongComponent.swift
 * @see appApple/Packages/iHymnsKit/Sources/IHModels/Work.swift
 * @see appApple/Packages/iHymnsKit/Sources/IHFeatures/SongMetadataView.swift
 * @see appAndroid/app/src/main/java/ltd/mwbmpartners/ihymns/models/Song.kt
 * @see appAndroid/app/src/main/java/ltd/mwbmpartners/ihymns/viewmodel/SongViewModel.kt
 */

import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(__dirname, '..');

const SCHEMA_SQL       = join(REPO_ROOT, 'appWeb', '.sql', 'schema.sql');
const SONGDATA_PHP     = join(REPO_ROOT, 'appWeb', 'public_html', 'includes', 'SongData.php');

const IH_MODELS   = join(REPO_ROOT, 'appApple', 'Packages', 'iHymnsKit', 'Sources', 'IHModels');
const IH_FEATURES = join(REPO_ROOT, 'appApple', 'Packages', 'iHymnsKit', 'Sources', 'IHFeatures');
const IH_API       = join(REPO_ROOT, 'appApple', 'Packages', 'iHymnsKit', 'Sources', 'IHAPI');

const SONG_DETAIL_SWIFT       = join(IH_MODELS, 'SongDetail.swift');
const SONG_COMPONENT_SWIFT    = join(IH_MODELS, 'SongComponent.swift');
const WORK_SWIFT              = join(IH_MODELS, 'Work.swift');
const SONG_METADATA_VIEW_SWIFT = join(IH_FEATURES, 'SongMetadataView.swift');
const SONG_DETAIL_VIEW_SWIFT   = join(IH_FEATURES, 'SongDetailView.swift');
const API_CLIENT_SWIFT         = join(IH_API, 'APIClient.swift');

const ANDROID_APP_ROOT = join(REPO_ROOT, 'appAndroid', 'app', 'src', 'main', 'java', 'ltd', 'mwbmpartners', 'ihymns');
const SONG_KT               = join(ANDROID_APP_ROOT, 'models', 'Song.kt');
const SONG_DETAIL_SCREEN_KT = join(ANDROID_APP_ROOT, 'ui', 'screens', 'SongDetailScreen.kt');
const SONG_VIEW_MODEL_KT    = join(ANDROID_APP_ROOT, 'viewmodel', 'SongViewModel.kt');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* =========================================================================
 * PURE CORE FUNCTIONS — no file I/O, no globals. Both the real assertions
 * below AND the mutation self-tests call these SAME functions, so a passing
 * mutation test is evidence about the checking logic itself, not a parallel
 * checker that only ever agrees with itself (rule #34).
 * ========================================================================= */

/**
 * Slice a `CREATE TABLE [IF NOT EXISTS] <table> (...) ENGINE=` block out of
 * a schema.sql-shaped string. Returns the column-declaration body (between
 * the outer parens), or null when the table isn't found at all. Mirrors
 * `tests/php/test-song-identity-render.php`'s `tsirSliceCreateTable()`.
 */
function sliceCreateTable(schemaSql, table) {
    const re = new RegExp(
        `CREATE\\s+TABLE(?:\\s+IF\\s+NOT\\s+EXISTS)?\\s+${table}\\s*\\((.*?)\\)\\s*ENGINE\\s*=`,
        'is'
    );
    const m = re.exec(schemaSql);
    return m ? m[1] : null;
}

/**
 * Within a CREATE TABLE column-declaration body, find every column whose
 * `COMMENT '...'` text contains at least one of the given tag strings. Each
 * column declaration is on its own physical line in this schema (verified
 * by direct read), so this is a per-line regex, not a full SQL parser —
 * same shape as the PHP twin.
 *
 * @param {string} block column-declaration body (sliceCreateTable()'s output)
 * @param {string[]} tags e.g. ['#1741 P1']
 * @returns {string[]} column names, in declaration order
 */
function taggedColumnsInBlock(block, tags) {
    const cols = [];
    const re = /^[ \t]*([A-Za-z_][A-Za-z0-9_]*)[ \t]+[A-Za-z].*?COMMENT\s+'((?:[^'\\]|\\.|'')*)'/gm;
    let m;
    while ((m = re.exec(block)) !== null) {
        const col = m[1];
        const comment = m[2];
        if (tags.some((tag) => comment.includes(tag))) {
            cols.push(col);
        }
    }
    return cols;
}

/**
 * `Subtitle` -> `subtitle` — the wire-key camelCase fold every #1741 P1/P4b
 * column name goes through. Pure, and directly self-tested below so a typo
 * in the mapping logic itself can't silently pass every other assertion.
 */
function camelFromColumn(colName) {
    if (colName.length === 0) return colName;
    return colName[0].toLowerCase() + colName.slice(1);
}

/**
 * Strips `//` line comments and `/* … *\/` block comments — WITH NESTING —
 * from a Swift or Kotlin source string. Both languages nest block comments
 * (unlike C/PHP), so a naive "find first `/*`, find next `*\/`, delete
 * between" stripper closes a nested comment at its FIRST inner close marker
 * and leaks the remainder of the outer comment as "code" — exactly the bug
 * this function exists to avoid (see the mutation self-test below for a
 * fixture proving it). Does not attempt to understand string literals — a
 * `"//"` or `"/*"` inside a Swift/Kotlin string literal would be
 * (mis-)treated as a comment start, same simplifying trade-off
 * `test-identifier-routes.js`'s sibling guards already make; acceptable
 * here because no real source line in the files this guard reads embeds a
 * comment-marker-shaped string literal adjacent to one of the identity keys.
 */
function stripSlashComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    while (i < n) {
        if (src[i] === '/' && src[i + 1] === '/') {
            while (i < n && src[i] !== '\n') i++;
            continue;
        }
        if (src[i] === '/' && src[i + 1] === '*') {
            let depth = 1;
            i += 2;
            while (i < n && depth > 0) {
                if (src[i] === '/' && src[i + 1] === '*') { depth++; i += 2; continue; }
                if (src[i] === '*' && src[i + 1] === '/') { depth--; i += 2; continue; }
                i++;
            }
            continue;
        }
        out += src[i];
        i++;
    }
    return out;
}

/**
 * Slice a named array literal out of PHP source: from `<marker>` (e.g.
 * `"$extraColNames = ["`) to the first `];` after it. Mirrors
 * `test-identifier-routes.js`'s `IHYMNS_ID_SCHEMES` registry-slicing shape
 * (indexOf-based, not a full PHP parser).
 *
 * @returns {string} the slice (possibly empty when the marker isn't found)
 */
function sliceArrayLiteral(src, marker) {
    const start = src.indexOf(marker);
    if (start < 0) return '';
    const end = src.indexOf('];', start);
    if (end < 0) return '';
    return src.slice(start, end);
}

/**
 * Extract every single-quoted string from an array-literal slice, in
 * declaration order — used against `sliceArrayLiteral()`'s output.
 */
function quotedStringsIn(block) {
    const out = [];
    const re = /'([A-Za-z0-9_]+)'/g;
    let m;
    while ((m = re.exec(block)) !== null) out.push(m[1]);
    return out;
}

/**
 * The conditional-Android check (build spec §7 assertion 4): if `songKt`
 * contains ANY of `derivedKeys`, then it must contain ALL of them, AND
 * `screenKt` must contain the literal `copyrightDisplay` — otherwise the
 * check is a no-op (`'skipped'`), keeping the guard green when the owner
 * declined Slice E entirely (per the build spec §6 honesty clause), while
 * still catching a HALF-landed Android slice.
 *
 * @returns {{status: 'skipped'|'red'|'green', missing: string[]}}
 */
function androidIdentityCheck(songKt, screenKt, derivedKeys) {
    const present = derivedKeys.filter((k) => songKt.includes(k));
    if (present.length === 0) {
        return { status: 'skipped', missing: [] };
    }
    const missing = derivedKeys.filter((k) => !songKt.includes(k));
    const hasCopyrightDisplay = screenKt.includes('copyrightDisplay');
    if (missing.length > 0 || !hasCopyrightDisplay) {
        return { status: 'red', missing: hasCopyrightDisplay ? missing : [...missing, 'copyrightDisplay (in SongDetailScreen.kt)'] };
    }
    return { status: 'green', missing: [] };
}

/**
 * #2073 (commit 16) — true when `src` (already comment-stripped) declares a
 * Swift stored property named `propName` typed as a genuinely OPTIONAL array
 * of `elementType`, e.g. `let voices: [VoiceRun]?`. This is the exact shape
 * that makes Swift's SYNTHESIZED `Decodable` use `decodeIfPresent` for that
 * key (rule #21/#25 precedent: every sparse key in this codebase's native
 * models — `chords`, `lineLanguages` — follows this same `[T]?` pattern) —
 * an array WITHOUT the trailing `?` would make a missing key throw
 * `keyNotFound` and crash the decode of every song until this one is set.
 * Allows either `let` or `var` and an optional `public`/access modifier, and
 * tolerates a trailing default-value expression (`= nil`) after the `?`.
 */
function swiftHasOptionalArrayProperty(src, propName, elementType) {
    const re = new RegExp(`\\b(?:let|var)\\s+${propName}\\s*:\\s*\\[${elementType}\\]\\?`);
    return re.test(src);
}

/**
 * #2073 (commit 16) — true when `src` (already comment-stripped) contains a
 * hand-written `init(from decoder:` / `init(from decoder: Decoder)` —
 * i.e. a CUSTOM `Decodable` implementation that could, deliberately or by
 * accident, reject an unrecognised key (e.g. by asserting
 * `container.allKeys.count == CodingKeys.allCases.count`). Swift's
 * COMPILER-SYNTHESIZED `Decodable` conformance (no custom init at all) is
 * what actually makes an extra/future JSON key silently ignored by default
 * — there is no "strict mode" flag to set, unlike Kotlin's
 * `kotlinx.serialization` (see `kotlinIgnoresUnknownKeys()` below), so the
 * ABSENCE of a custom decoder here is itself the thing worth guarding.
 */
function swiftHasCustomDecoderInit(src) {
    return /\binit\s*\(\s*from\s+\w+\s*:\s*Decoder\b/.test(src);
}

/**
 * #2073 (commit 16) — true when `src` (already comment-stripped) declares a
 * Kotlin `@Serializable` data-class property named `propName` typed as
 * `List<elementType>` WITH a default value (`= emptyList()` or any other
 * `= ...` expression). `kotlinx.serialization` is strict about a MISSING
 * required property regardless of `ignoreUnknownKeys` (that flag only
 * covers keys the JSON has that the class does NOT declare — the opposite
 * direction) — a property needs its OWN default to degrade gracefully when
 * the wire key is absent, so the default is what actually matters here, not
 * just the type.
 */
function kotlinHasDefaultedListProperty(src, propName, elementType) {
    const re = new RegExp(`\\bval\\s+${propName}\\s*:\\s*List<${elementType}>\\s*=\\s*\\S`);
    return re.test(src);
}

/**
 * #2073 (commit 16) — true when `src` (the Android `Json { ... }` parser
 * configuration block) sets `ignoreUnknownKeys = true`. Unlike Swift's
 * `JSONDecoder`, `kotlinx.serialization` REJECTS an unrecognised key by
 * default — this flag is what makes a wire key the app doesn't know about
 * yet (a future `rounds`/`vocalWords` companion, or any other server
 * addition) survive decoding instead of throwing
 * `SerializationException`. Whitespace-tolerant; comment-stripped input
 * expected (a commented-out `// ignoreUnknownKeys = true` must not count).
 */
function kotlinIgnoresUnknownKeys(src) {
    return /\bignoreUnknownKeys\s*=\s*true\b/.test(src);
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory, against
 * small fixtures, never the real tree. A guard that has never been proven
 * able to fail is not trustworthy.
 * ========================================================================= */

console.log('Mutation self-tests (run first, in memory):\n');

// --- sliceCreateTable(): finds the right block, stops at the right ENGINE= ---
{
    const fixtureSchema =
        "CREATE TABLE IF NOT EXISTS tblFixtureProbe (\n" +
        "    Id INT UNSIGNED PRIMARY KEY,\n" +
        "    Foo VARCHAR(10) NOT NULL COMMENT 'plain field',\n" +
        "    Bar VARCHAR(10) NOT NULL COMMENT 'tagged field (#1741 P1)'\n" +
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n" +
        "CREATE TABLE IF NOT EXISTS tblFixtureAfter (\n" +
        "    Zzz INT\n" +
        ") ENGINE=InnoDB;\n";

    const block = sliceCreateTable(fixtureSchema, 'tblFixtureProbe');
    check('mutation — sliceCreateTable() finds the target table and includes its columns',
        block !== null && block.includes('Bar'));
    check('mutation — sliceCreateTable() stops at the RIGHT ENGINE= (does not bleed into the next table)',
        block !== null && !block.includes('Zzz'));
    check('mutation — sliceCreateTable() returns null for a table that does not exist in the fixture',
        sliceCreateTable(fixtureSchema, 'tblDoesNotExist') === null);

    const tagged = taggedColumnsInBlock(block, ['#1741 P1']);
    check('mutation — taggedColumnsInBlock() extracts exactly the tagged column, not the untagged one',
        tagged.length === 1 && tagged[0] === 'Bar',
        `got [${tagged.join(', ')}]`);
}

// --- taggedColumnsInBlock(): fails-high (new tagged column detected) ---
{
    const syntheticBlock = "    Zebra VARCHAR(10) NOT NULL COMMENT 'a mutation probe (#1741 P1)'\n";
    const found = taggedColumnsInBlock(syntheticBlock, ['#1741 P1']);
    check('mutation FAILS-HIGH — a brand-new tagged column is detected',
        found.includes('Zebra'),
        'the tag extractor did not pick up a synthetic new tagged column');
}

// --- taggedColumnsInBlock(): fails-low (removing the tag stops detection) ---
{
    const schemaSrc = existsSync(SCHEMA_SQL) ? readFileSync(SCHEMA_SQL, 'utf8') : '';
    const realBlock = schemaSrc ? sliceCreateTable(schemaSrc, 'tblSongs') : null;
    if (realBlock) {
        const mutatedBlock = realBlock.replace('#1741 P1', 'REMOVED-TAG');
        const stillTagged = taggedColumnsInBlock(mutatedBlock, ['#1741 P1']);
        check('mutation FAILS-LOW — removing one real #1741 P1 tag drops that column from the derived list',
            stillTagged.length < taggedColumnsInBlock(realBlock, ['#1741 P1']).length,
            'removing a real tag occurrence was not detected — a deleted doctag would pass this guard silently');
    } else {
        check('mutation FAILS-LOW — could run against the real tblSongs block', false, 'schema.sql or the tblSongs table was not found — cannot run this self-test');
    }
}

// --- camelFromColumn(): pure mapping self-test ---
{
    check('mutation — camelFromColumn() mapping is correct',
        camelFromColumn('Subtitle') === 'subtitle'
        && camelFromColumn('Disambiguation') === 'disambiguation'
        && camelFromColumn('FirstPublishedYear') === 'firstPublishedYear'
        && camelFromColumn('CopyrightYears') === 'copyrightYears'
        && camelFromColumn('CopyrightHolder') === 'copyrightHolder');
}

// --- stripSlashComments(): line comments ---
{
    const src = "// needleInLineComment\nrealCodeSurvives";
    const stripped = stripSlashComments(src);
    check('mutation — line-comment needle is stripped, real code survives',
        !stripped.includes('needleInLineComment') && stripped.includes('realCodeSurvives'));
}

// --- stripSlashComments(): NESTED block comments (Swift/Kotlin-specific) ---
{
    // A naive non-nesting stripper (find first `/*`, delete to the FIRST
    // `*/`) would close this comment at the INNER `*/` and leak
    // "NEEDLE_STILL_COMMENT */" as "code" — wrongly satisfying an
    // assertion that looked for NEEDLE_STILL_COMMENT. Correct nesting-aware
    // stripping treats the whole thing as ONE comment (depth 2 -> 0).
    const src = '/* outer starts /* inner */ NEEDLE_STILL_COMMENT */ realCodeAfter';
    const stripped = stripSlashComments(src);
    check('mutation — a needle buried inside a NESTED block comment is reported ABSENT (proves nesting, not just any stripping)',
        !stripped.includes('NEEDLE_STILL_COMMENT'),
        'a non-nesting stripper would have leaked this as "code" — exactly the Swift/Kotlin-specific bug this function exists to avoid');
    check('mutation — real code AFTER the nested comment survives stripping',
        stripped.includes('realCodeAfter'));
}

// --- androidIdentityCheck(): conditional logic, all three states ---
{
    const derived = ['subtitle', 'disambiguation', 'firstPublishedYear', 'copyrightYears', 'copyrightHolder'];

    const none = androidIdentityCheck('data class Song(val id: String)', 'fun SongDetailScreen() {}', derived);
    check('mutation — androidIdentityCheck() is SKIPPED (not red) when Song.kt has none of the derived keys',
        none.status === 'skipped');

    const partial = androidIdentityCheck('data class Song(val subtitle: String = "")', 'fun SongDetailScreen() {}', derived);
    check('mutation — androidIdentityCheck() goes RED when Song.kt has only SOME of the derived keys (a half-landed slice)',
        partial.status === 'red' && partial.missing.length > 0);

    const allKeysNoFold = androidIdentityCheck(derived.join(' '), 'fun SongDetailScreen() {}', derived);
    check('mutation — androidIdentityCheck() goes RED when all keys are on Song.kt but copyrightDisplay is missing from SongDetailScreen.kt',
        allKeysNoFold.status === 'red');

    const allGreen = androidIdentityCheck(derived.join(' '), 'fun copyrightDisplay(song: Song): String { ... }', derived);
    check('mutation — androidIdentityCheck() is GREEN when all keys AND copyrightDisplay are present',
        allGreen.status === 'green');
}

// --- #2073 (commit 16) — swiftHasOptionalArrayProperty(): fails-high AND fails-low ---
{
    const optionalFixture = 'public struct Foo { public let voices: [VoiceRun]? = nil }';
    check('mutation FAILS-HIGH — swiftHasOptionalArrayProperty() detects a genuinely optional `[T]?` array property',
        swiftHasOptionalArrayProperty(optionalFixture, 'voices', 'VoiceRun'));

    const requiredFixture = 'public struct Foo { public let voices: [VoiceRun] }';
    check('mutation FAILS-LOW — swiftHasOptionalArrayProperty() rejects the SAME property spelled WITHOUT the `?` (a required array, not sparse)',
        !swiftHasOptionalArrayProperty(requiredFixture, 'voices', 'VoiceRun'),
        'a required (non-Optional) array must not be reported as the safe sparse shape — that would let a breaking change through silently');

    const wrongNameFixture = 'public struct Foo { public let somethingElse: [VoiceRun]? = nil }';
    check('mutation FAILS-LOW — swiftHasOptionalArrayProperty() does not match a differently-named property',
        !swiftHasOptionalArrayProperty(wrongNameFixture, 'voices', 'VoiceRun'));
}

// --- #2073 (commit 16) — swiftHasCustomDecoderInit(): fails-high AND fails-low ---
{
    const customDecoderFixture = 'struct Foo: Decodable { init(from decoder: Decoder) throws { ... } }';
    check('mutation FAILS-HIGH — swiftHasCustomDecoderInit() detects a real custom `init(from decoder: Decoder)`',
        swiftHasCustomDecoderInit(customDecoderFixture));

    const synthesizedFixture = 'struct Foo: Codable { let voices: [VoiceRun]? = nil }';
    check('mutation FAILS-LOW — swiftHasCustomDecoderInit() reports false for a struct with NO custom decoder (relies on synthesis)',
        !swiftHasCustomDecoderInit(synthesizedFixture));

    const unrelatedInitFixture = 'struct Foo { init(from song: SongDetail) { ... } }';
    check('mutation — swiftHasCustomDecoderInit() does not false-positive on an unrelated `init(from:)` whose parameter is not typed `Decoder`',
        !swiftHasCustomDecoderInit(unrelatedInitFixture),
        'a labelled-"from" convenience initializer that has nothing to do with Decodable must not be mistaken for a strict custom decoder');
}

// --- #2073 (commit 16) — kotlinHasDefaultedListProperty(): fails-high AND fails-low ---
{
    const defaultedFixture = 'data class Foo(val voices: List<VoiceRun> = emptyList())';
    check('mutation FAILS-HIGH — kotlinHasDefaultedListProperty() detects a defaulted `List<T>` property',
        kotlinHasDefaultedListProperty(defaultedFixture, 'voices', 'VoiceRun'));

    const noDefaultFixture = 'data class Foo(val voices: List<VoiceRun>)';
    check('mutation FAILS-LOW — kotlinHasDefaultedListProperty() rejects the SAME property with NO default value (required, not tolerant of an absent key)',
        !kotlinHasDefaultedListProperty(noDefaultFixture, 'voices', 'VoiceRun'),
        'kotlinx.serialization throws on a missing required property regardless of ignoreUnknownKeys — a property with no default is not actually safe');
}

// --- #2073 (commit 16) — kotlinIgnoresUnknownKeys(): fails-high AND fails-low ---
{
    check('mutation FAILS-HIGH — kotlinIgnoresUnknownKeys() detects `ignoreUnknownKeys = true`',
        kotlinIgnoresUnknownKeys('val json = Json { ignoreUnknownKeys = true }'));
    check('mutation FAILS-LOW — kotlinIgnoresUnknownKeys() reports false once the flag is flipped to `false`',
        !kotlinIgnoresUnknownKeys('val json = Json { ignoreUnknownKeys = false }'),
        'a strict parser (flag false or absent) must not be reported as tolerant');
    check('mutation FAILS-LOW — kotlinIgnoresUnknownKeys() reports false when the flag is absent entirely',
        !kotlinIgnoresUnknownKeys('val json = Json { isLenient = true }'));
}

console.log('');

/* =========================================================================
 * REAL DERIVATION — song keys (tblSongs, #1741 P1)
 * ========================================================================= */

console.log('Derivation — song identity keys from schema.sql:\n');

check('appWeb/.sql/schema.sql exists', existsSync(SCHEMA_SQL));

let SONG_KEYS = [];
if (existsSync(SCHEMA_SQL)) {
    const schemaSrc = readFileSync(SCHEMA_SQL, 'utf8');
    const tblSongsBlock = sliceCreateTable(schemaSrc, 'tblSongs');
    check('tblSongs CREATE TABLE block found in schema.sql', tblSongsBlock !== null);

    if (tblSongsBlock !== null) {
        const taggedColumns = taggedColumnsInBlock(tblSongsBlock, ['#1741 P1']);
        SONG_KEYS = taggedColumns.map(camelFromColumn);
        console.log(`  Derived ${SONG_KEYS.length} song key(s): ${SONG_KEYS.join(', ')}`);
        check('at least one #1741 P1-tagged song column was found (an empty list means the derivation broke, not that the schema has none)',
            SONG_KEYS.length > 0);
        // Sanity floor per the build spec's own expectation — a genuine
        // regression (derivation broke) looks identical to "the feature was
        // reverted" without SOME lower bound; this is a floor, not a
        // hardcoded exact-list (a sixth P1 column stays covered).
        check('at least the five originally-known #1741 P1 song keys are present',
            SONG_KEYS.length >= 5,
            `expected >= 5, got ${SONG_KEYS.length}`);
    }
} else {
    check('cannot continue without schema.sql', false);
}

/* =========================================================================
 * REAL DERIVATION — Work keys (getWork()'s own $extraColNames literal)
 * ========================================================================= */

console.log('\nDerivation — Work identity keys from SongData.php::getWork():\n');

check('appWeb/public_html/includes/SongData.php exists', existsSync(SONGDATA_PHP));

let WORK_KEYS = [];
if (existsSync(SONGDATA_PHP)) {
    const songDataSrc = readFileSync(SONGDATA_PHP, 'utf8');
    const extraColBlock = sliceArrayLiteral(songDataSrc, '$extraColNames = [');
    check('$extraColNames array literal found in SongData.php', extraColBlock !== '');

    if (extraColBlock !== '') {
        const columnNames = quotedStringsIn(extraColBlock);
        WORK_KEYS = columnNames.map(camelFromColumn);
        console.log(`  Derived ${WORK_KEYS.length} Work key(s): ${WORK_KEYS.join(', ')}`);
        check('at least one Work identity column was extracted from $extraColNames',
            WORK_KEYS.length > 0);
        /* Floor, not hard-equality (rule #34): WORK_KEYS is DERIVED from
           getWork()'s own $extraColNames literal, and every derived key is
           independently asserted present in Work.swift below — so a legitimate
           TENTH P4b column (extended in SongData.php + surfaced in Work.swift)
           is covered automatically and must NOT fail this guard. A hard
           `=== 9` turned the derivation's own floor into a ceiling that would
           nag on correct future code (and contradicted this file's header),
           mirroring the song-key `>= 5` floor above. */
        check('at least the nine originally-known #1741 P4b Work keys are present',
            WORK_KEYS.length >= 9,
            `expected >= 9, got ${WORK_KEYS.length}: ${WORK_KEYS.join(', ')}`);
    }
} else {
    check('cannot continue without SongData.php', false);
}

/* =========================================================================
 * ASSERTIONS — song keys against the Apple source
 * ========================================================================= */

console.log('\nAssertion 1 — every song key appears in SongDetail.swift (property + CodingKeys):\n');

for (const path of [SONG_DETAIL_SWIFT, WORK_SWIFT, SONG_METADATA_VIEW_SWIFT, SONG_DETAIL_VIEW_SWIFT, API_CLIENT_SWIFT]) {
    check(`${path.split('/Sources/')[1] ?? path} exists`, existsSync(path));
}

const songDetailSwift = existsSync(SONG_DETAIL_SWIFT) ? stripSlashComments(readFileSync(SONG_DETAIL_SWIFT, 'utf8')) : '';
const workSwift = existsSync(WORK_SWIFT) ? stripSlashComments(readFileSync(WORK_SWIFT, 'utf8')) : '';
const songMetadataViewSwift = existsSync(SONG_METADATA_VIEW_SWIFT) ? stripSlashComments(readFileSync(SONG_METADATA_VIEW_SWIFT, 'utf8')) : '';
const apiClientSwift = existsSync(API_CLIENT_SWIFT) ? stripSlashComments(readFileSync(API_CLIENT_SWIFT, 'utf8')) : '';

for (const key of SONG_KEYS) {
    check(`'${key}' appears as a property in SongDetail.swift ("let ${key}:")`,
        songDetailSwift.includes(`let ${key}:`));
    check(`'${key}' appears in SongDetail.swift's CodingKeys ("case ${key}")`,
        new RegExp(`case\\s+${key}\\b`).test(songDetailSwift));
}

console.log('\nAssertion 2 — every song key reaches a render site (any IHFeatures view file):\n');

// The render-site set is TREE-DERIVED (rule #34): a key "reaches a render
// site" if SOME IHFeatures view file accesses it off the loaded model as
// `detail.<key>`, not a hardcoded two-file list. The codebase's own "every
// section is its own file" convention (this module's SongDetailView header
// documents it) means a section can be split into a fresh file at any time —
// e.g. SongDetailHeaderView.swift, carved out of SongDetailView for the
// LOC-budget tripwire, now owns the subtitle + disambiguation render. A
// hardcoded {SongMetadataView, SongDetailView} list went spuriously RED the
// moment that happened; scanning the directory keeps the guard honest across
// extraction.
//
// We match the PROPERTY ACCESS `detail.<key>`, NOT the bare token `<key>`
// (rule #34's "not too blunt" edge): `subtitle`/`disambiguation` are common
// SwiftUI words (a `subtitle:` label, `.navigationSubtitle`, an unrelated
// card's own `subtitle`) that appear across eight IHFeatures files, so a bare
// substring check could NEVER fail — a guard that cannot fail reads as
// coverage while verifying nothing. `detail.subtitle` is the actual render
// signal and appears ONLY where the key is genuinely rendered; deleting that
// render turns this RED (mutation-proven). Comments are stripped first.
const renderSiteSwift = existsSync(IH_FEATURES)
    ? readdirSync(IH_FEATURES)
        .filter((f) => f.endsWith('.swift'))
        .map((f) => stripSlashComments(readFileSync(join(IH_FEATURES, f), 'utf8')))
        .join('\n')
    : '';

// firstPublishedYear/subtitle/disambiguation are rendered directly (accessed
// as `detail.<key>`); copyrightYears/copyrightHolder are reached ONLY via the
// copyrightDisplay fold (never rendered verbatim) — asserted separately below
// per the build spec's explicit "don't pretend every key renders verbatim"
// instruction (rule #34's both-edges lesson).
const DIRECTLY_RENDERED_SONG_KEYS = SONG_KEYS.filter((k) => k !== 'copyrightYears' && k !== 'copyrightHolder');
for (const key of DIRECTLY_RENDERED_SONG_KEYS) {
    check(`'${key}' reaches a render site (some IHFeatures view accesses detail.${key})`,
        renderSiteSwift.includes(`detail.${key}`));
}

check("the literal 'copyrightDisplay' appears in SongMetadataView.swift (the render site for the copyright line)",
    songMetadataViewSwift.includes('copyrightDisplay'));

if (SONG_KEYS.includes('copyrightYears') && SONG_KEYS.includes('copyrightHolder')) {
    check("SongDetail.swift's copyrightDisplay body/definition mentions BOTH split keys (copyrightYears AND copyrightHolder)",
        /copyrightDisplay[\s\S]{0,400}copyrightYears/.test(songDetailSwift)
        && /copyrightDisplay[\s\S]{0,400}copyrightHolder/.test(songDetailSwift),
        'copyrightYears/copyrightHolder must be reachable from the copyrightDisplay definition in SongDetail.swift, even though they never render verbatim elsewhere');
}

console.log("\nAssertion 3 — 'externalIds' is present in APIClient.swift's songDetailIncludeBlocks literal:\n");

check("'songDetailIncludeBlocks' literal found in APIClient.swift", apiClientSwift.includes('songDetailIncludeBlocks'));
check("'externalIds' appears in the songDetailIncludeBlocks array literal",
    /songDetailIncludeBlocks\s*=\s*\[[^\]]*"externalIds"[^\]]*\]/.test(apiClientSwift));

console.log('\nAssertion 4 — Android (CONDITIONAL — only asserted if Slice E landed):\n');

const songKtSrc = existsSync(SONG_KT) ? readFileSync(SONG_KT, 'utf8') : '';
const songDetailScreenKtSrc = existsSync(SONG_DETAIL_SCREEN_KT) ? readFileSync(SONG_DETAIL_SCREEN_KT, 'utf8') : '';

if (!existsSync(SONG_KT) || !existsSync(SONG_DETAIL_SCREEN_KT)) {
    console.log('  SKIPPED — Song.kt or SongDetailScreen.kt not found at the expected path (Android Slice E not present at all).');
} else {
    const androidResult = androidIdentityCheck(stripSlashComments(songKtSrc), stripSlashComments(songDetailScreenKtSrc), SONG_KEYS);
    if (androidResult.status === 'skipped') {
        console.log('  SKIPPED — Song.kt carries none of the derived song keys (Slice E was not taken, per the build spec §6 owner-flag default) — not a failure.');
    } else {
        check('Android Song.kt + SongDetailScreen.kt carry ALL derived keys + copyrightDisplay (never a HALF-landed slice)',
            androidResult.status === 'green',
            androidResult.missing.length ? `missing: ${androidResult.missing.join(', ')}` : '');
    }
}

/* =========================================================================
 * ASSERTIONS — Work keys against Work.swift
 * ========================================================================= */

console.log('\nAssertion 5 — every Work key appears in Work.swift:\n');

for (const key of WORK_KEYS) {
    check(`'${key}' appears in Work.swift`, workSwift.includes(key));
}

/* =========================================================================
 * ASSERTIONS — #2073 (commit 16): native decoders tolerate the new
 * voices/voiceSpans/rounds keys
 *
 * Decode-only scope (rendering is a later, separate commit — see the two
 * model files' own doc comments): every check below is about whether a
 * payload carrying these keys decodes WITHOUT throwing, never about whether
 * anything on screen changes.
 * ========================================================================= */

console.log('\nAssertion 6 — SongComponent.swift declares voices/voiceSpans as OPTIONAL, with no custom decoder overriding tolerance:\n');

check('appApple/.../IHModels/SongComponent.swift exists', existsSync(SONG_COMPONENT_SWIFT));

const songComponentSwiftRaw = existsSync(SONG_COMPONENT_SWIFT) ? readFileSync(SONG_COMPONENT_SWIFT, 'utf8') : '';
const songComponentSwift = stripSlashComments(songComponentSwiftRaw);

check("'voices' is declared as a genuinely Optional array (`[VoiceRun]?`) on SongComponent",
    swiftHasOptionalArrayProperty(songComponentSwift, 'voices', 'VoiceRun'));
check("'voiceSpans' is declared as a genuinely Optional array (`[VoiceSpan]?`) on SongComponent",
    swiftHasOptionalArrayProperty(songComponentSwift, 'voiceSpans', 'VoiceSpan'));
check('the supporting VoiceRun/VoiceRunPart/VoiceSpan types are real decodable structs (not a `[String: Any]` escape hatch)',
    /struct\s+VoiceRun\s*:[^{]*Codable/.test(songComponentSwift)
    && /struct\s+VoiceRunPart\s*:[^{]*Codable/.test(songComponentSwift)
    && /struct\s+VoiceSpan\s*:[^{]*Codable/.test(songComponentSwift));
check('SongComponent.swift has NO custom `init(from decoder:)` overriding the default unknown-key-tolerant synthesis',
    !swiftHasCustomDecoderInit(songComponentSwift));

console.log('\nAssertion 7 — SongDetail.swift stays unknown-key tolerant (proves `rounds`/`vocalWords` decode safely even though this commit adds no property for them yet):\n');

// SongDetail.swift is read by earlier assertions in this file already
// (`songDetailSwift`, line ~444) — reused here rather than re-read, per this
// file's own "don't duplicate a read" convention.
check('SongDetail.swift has NO custom `init(from decoder:)` — an unrecognised top-level key (e.g. a future `rounds`/`vocalWords`) is silently ignored by Swift\'s default JSONDecoder behaviour, with no extra property or config needed on THIS commit',
    !swiftHasCustomDecoderInit(songDetailSwift));

console.log("\nAssertion 8 — Android's SongComponent data class tolerates voices/voiceSpans, and the shared parser stays configured to ignore unknown keys:\n");

check('appAndroid/.../models/Song.kt exists', existsSync(SONG_KT));
check('appAndroid/.../viewmodel/SongViewModel.kt exists', existsSync(SONG_VIEW_MODEL_KT));

const songKtStripped = stripSlashComments(songKtSrc);
const songViewModelKtSrc = existsSync(SONG_VIEW_MODEL_KT) ? readFileSync(SONG_VIEW_MODEL_KT, 'utf8') : '';
const songViewModelKtStripped = stripSlashComments(songViewModelKtSrc);

check("'voices' is declared on Android's SongComponent as `List<VoiceRun>` WITH a default value (absent-key safe)",
    kotlinHasDefaultedListProperty(songKtStripped, 'voices', 'VoiceRun'));
check("'voiceSpans' is declared on Android's SongComponent as `List<VoiceSpan>` WITH a default value (absent-key safe)",
    kotlinHasDefaultedListProperty(songKtStripped, 'voiceSpans', 'VoiceSpan'));
check('the supporting VoiceRun/VoicePart/VoiceSpan classes are real @Serializable data classes (not a raw JsonElement escape hatch)',
    /@Serializable\s+data class VoiceRun\b/.test(songKtStripped)
    && /@Serializable\s+data class VoicePart\b/.test(songKtStripped)
    && /@Serializable\s+data class VoiceSpan\b/.test(songKtStripped));
check("SongViewModel.kt's shared Json{} parser still sets `ignoreUnknownKeys = true` (required for kotlinx.serialization to tolerate a key it doesn't know about at all, e.g. a future `rounds`/`vocalWords` addition)",
    kotlinIgnoresUnknownKeys(songViewModelKtStripped));

/* ------------------------------------------------------------------ */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll native song/work identity contract assertions passed.');
