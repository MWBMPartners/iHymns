<?php

/**
 * bindings/php/media-language/MediaLanguagePolicy.php
 *
 * The PHP implementation of the Media Language & BCP 47 Policy
 * (policy ID MWBM-MEDIA-LANG, policy version 1.0.0). See
 * docs/standards/media-language-bcp47-policy.md for the full, human-readable
 * rules this file implements; the rule numbers used in the comments below
 * (LANG-001, UI-045, AUTO-020, and so on) point back to that document.
 *
 * THIS FILE IS COPIED VERBATIM into every repository that needs it (today:
 * iHymns, iLyricsDB, NetPLAYERapp — see the policy document's section 9,
 * "Implementations"). Those repositories keep `scripts/media-lang/check_copies.py`
 * (also copied verbatim) running in their own CI, which fails the build if a
 * copy of this file no longer matches the master here byte-for-byte. That
 * means: CHANGE THIS FILE ONLY IN MeedyaSuite-core. A fix made in a copy will
 * be silently reported as "the file has been changed" the next time that
 * repository's CI runs, and will be overwritten the next time it takes a new
 * version of the policy.
 *
 * Why one file: the three PHP consumers are plain PHP applications with no
 * framework, no Composer package of their own for this, and no dependency
 * manager step for a shared package. Copying one self-contained file is the
 * simplest thing that lets all three stay in step. There is deliberately no
 * Composer `require` here and no use of the `intl` extension — the policy's
 * Part B (presentation ordering, localised names) is written so that this
 * file never needs to know a language's name in any human language; the
 * caller supplies that (see Policy::sortPresentation() below).
 *
 * Runs on PHP 8.1 and later. Nothing here uses an 8.2+-only feature (no
 * readonly classes, no DNF types) because the three consumer applications
 * run PHP 8.3-8.5 today but the floor is set at 8.1 deliberately, so an
 * older install is not silently locked out.
 *
 * A note on sorting: every ordering function in this file that calls PHP's
 * usort()/uasort()/uksort() relies on PHP 8's SORT BEING STABLE (guaranteed
 * since PHP 8.0.0: "elements that compare as equal will retain their
 * original order"). The policy's rule LANG-027 ("ties keep their order") and
 * the presentation rule UI-045 point 5 ("stability") both depend on exactly
 * this behaviour, so the comparators below are written to return 0 for a
 * genuine tie and lean on PHP's guarantee rather than re-deriving the
 * original position by hand.
 *
 * Copyright (c) 2026 MeedyaSuite
 * Licensed under the MIT License. See LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Mwbm\MediaLanguage;

/**
 * The four shapes a canonicalised value can take (LANG-001, LANG-026).
 *
 * - Ordinary: a normal BCP 47 language tag, or one of the special-purpose
 *   codes (`und`, `mul`, `zxx`, `mis`, the `qaa`-`qtz` local-use range) —
 *   those are grammatically ordinary tags, just with a reserved meaning.
 * - Grandfathered: a registry "grandfathered" tag that the registry gives
 *   NO replacement for (LANG-001 step 2), kept exactly as the registry
 *   spells it. A grandfathered tag that DOES have a replacement is not this
 *   case — canonicalising it returns whatever kind the replacement is
 *   (almost always Ordinary), because the replacement is what gets used.
 * - PrivateUse: a tag that is nothing but a private-use subtag (`x-...`),
 *   with no language at all.
 * - Malformed: not a well-formed BCP 47 tag. The value is kept as text (see
 *   LanguageTag::$tag), never guessed at or silently dropped.
 */
enum TagKind: string
{
    case Ordinary = 'ordinary';
    case Grandfathered = 'grandfathered';
    case PrivateUse = 'privateuse';
    case Malformed = 'malformed';
}

/**
 * How strongly a candidate tag matches a preference (MATCH-010 to MATCH-040).
 * Best first: Exact, General, Specific, Related, None.
 */
enum MatchLevel: string
{
    case Exact = 'exact';
    case General = 'general';
    case Specific = 'specific';
    case Related = 'related';
    case None = 'none';

    /** Lower is better; used to compare two match levels and as a cut-off
     * ("related or better" appears throughout Part B as `<= Related`). */
    public function rank(): int
    {
        return match ($this) {
            self::Exact => 0,
            self::General => 1,
            self::Specific => 2,
            self::Related => 3,
            self::None => 4,
        };
    }
}

/**
 * The user's subtitle preference (AUTO-030). Not the same thing as a track
 * role: this describes what the PLAYER should do, not what any one track is.
 */
enum SubtitleMode: string
{
    case Automatic = 'automatic';
    case Always = 'always';
    case ForcedOnly = 'forced_only';
    case Off = 'off';
}

/**
 * The roles a track can carry (TRACK-010), spelled out here only as a
 * documented, typo-proof set of constants. Every function in Policy that
 * takes a track's roles accepts plain strings (an array decoded straight
 * from JSON, a database row, and so on) — using Role::Sdh->value instead of
 * the literal string 'sdh' is a convenience, never a requirement.
 */
enum Role: string
{
    case Alternate = 'alternate';
    case AudioDescription = 'audio_description';
    case Commentary = 'commentary';
    case Sdh = 'sdh';
    case Forced = 'forced';
    case Other = 'other';
}

/**
 * A single BCP 47 language tag, already run through canonical form
 * (LANG-001). Immutable: once built, a LanguageTag never changes, so it is
 * safe to share, cache and compare freely.
 *
 * $tag is the canonical string form (`en-GB`, `zh-Hant-TW`, ...) for every
 * kind except Malformed, where it is the original text instead — LANG-026
 * says a malformed value "keeps its text", because it must never be
 * silently dropped or guessed at.
 *
 * $language/$extlang/$script/$region/$variants/$extensions/$privateUse are
 * only meaningful when $kind is Ordinary (a PrivateUse tag has no language
 * at all, by definition; a Grandfathered or Malformed tag is not decomposed
 * into subtags). For any other kind they are null/empty.
 *
 * $extlang is almost always null: a registered extlang is folded into
 * $language during canonicalisation (LANG-001 step 5, "zh-yue-HK" becomes
 * language "yue" with no extlang left over) and only survives here when the
 * registry does not list it at all — a three-letter subtag that is
 * syntactically an extlang position but not a real one ("zh-abc" keeps
 * extlang "abc"). LANG-021 counts a surviving extlang as an "additional"
 * subtag, the same way it counts a variant or an extension.
 *
 * $extensions is a list of lists: each inner list is
 * [singletonLetter, subtag, subtag, ...], for example a tag ending in
 * `-u-ca-gregory` produces one entry `['u', 'ca', 'gregory']`.
 */
final class LanguageTag
{
    /**
     * @param list<string> $variants
     * @param list<list<string>> $extensions
     * @param list<string> $privateUse
     */
    public function __construct(
        public readonly string $tag,
        public readonly TagKind $kind,
        public readonly ?string $language,
        public readonly ?string $extlang,
        public readonly ?string $script,
        public readonly ?string $region,
        public readonly array $variants,
        public readonly array $extensions,
        public readonly array $privateUse,
    ) {
    }

    public function isMalformed(): bool
    {
        return $this->kind === TagKind::Malformed;
    }
}

/**
 * The result of comparing one preference tag against one candidate tag
 * (MATCH-010 to MATCH-040). $distance is only meaningful for General and
 * Specific (how many subtags were removed or added); it is 0 otherwise.
 */
final class TagMatch
{
    public function __construct(
        public readonly MatchLevel $level,
        public readonly int $distance,
    ) {
    }
}

/**
 * Static entry point for every rule in the policy. There is deliberately no
 * instance state beyond the cached reference data (Policy::loadData()) —
 * everything else is a pure function of its arguments, so two calls with
 * the same input always give the same answer.
 *
 * The jobs below are kept apart on purpose (policy section 9), because a
 * function that tried to do two of them at once is exactly how a rule from
 * one part of the policy quietly leaks into the wrong context:
 *
 *  - canonicalise() / fromLegacyThreeLetter() / fromPosixLocale() /
 *    iso6392CodesForWriting() — turning something else into (or out of) a
 *    canonical tag (LANG-001 to LANG-004, TRACK-070).
 *  - sortCanonicalOrder() / sortTrackOrder() — Part A stored order
 *    (LANG-010 to LANG-027, TRACK-050, TRACK-060). Same answer everywhere,
 *    for everyone, forever - this is what gets written to disk.
 *  - sortPresentation() / sortSubtitleMenu() — Part B menu order (UI-020 to
 *    UI-060). Different for different users; MUST NOT be written back into
 *    stored content (see the policy's "Two orders, on purpose").
 *  - buildLabel() — UI-070 label text.
 *  - matchTags() — MATCH-010 to MATCH-040, used by everything below it.
 *  - selectAudioTrack() / selectSubtitleTrack() — AUTO-010 to AUTO-040,
 *    automatic selection. A different decision from menu order (AUTO-010):
 *    selecting a track never depends on where it sits in a menu.
 *  - buildSidecarName() / parseSidecarName() — TEXT-030 sidecar file names.
 */
final class Policy
{
    /** The policy this file implements. Checked against the loaded data
     * file in loadData() so a file for the wrong policy is refused loudly
     * rather than producing quietly wrong answers. */
    private const EXPECTED_POLICY = 'MWBM-MEDIA-LANG';

    /** The reference data version this file was written against
     * (docs/standards/data/bcp47-language-data-v1.json, data_version).
     * Bumping this is a deliberate step alongside taking a new data file —
     * see loadData()'s doc comment for why a mismatch is fatal rather than
     * a warning. */
    private const EXPECTED_DATA_VERSION = '1.0.0';

    /**
     * TRACK-050's audio role order, main programme first: main (no role
     * present) -> alternate -> audio description -> commentary -> anything
     * else. A role not in this table (an application-specific extra role a
     * future version of the policy has not named yet) is treated the same
     * as "anything else", never as an error.
     */
    private const AUDIO_ROLE_RANK = [
        'alternate' => 1,
        'audio_description' => 2,
        'commentary' => 3,
        'other' => 4,
    ];

    /**
     * TRACK-050's subtitle role order: full subtitles (no role present) ->
     * SDH/captions -> forced -> commentary -> anything else.
     */
    private const SUBTITLE_ROLE_RANK = [
        'sdh' => 1,
        'forced' => 2,
        'commentary' => 3,
        'other' => 4,
    ];

    /** mul, mis and zxx each sit in their own single-language "group" in
     * LANG-025's fixed order; `und` too. qaa-qtz (bucket 3, checked
     * separately) sits between mis and und. Grandfathered-with-no-
     * replacement is 6, private-use-only is 7, malformed is 8 (see
     * bucket()). Ordinary languages are always 0. */
    private const SPECIAL_LANGUAGE_BUCKET = [
        'mul' => 1,
        'mis' => 2,
        'und' => 4,
        'zxx' => 5,
    ];

    private static ?array $data = null;

    private function __construct()
    {
        // Not instantiable: every job this class does is a static function
        // of its arguments (see the class doc comment above).
    }

    // ------------------------------------------------------------------
    // Reference data (policy section 8.2)
    // ------------------------------------------------------------------

    /**
     * Reads docs/standards/data/bcp47-language-data-v1.json (or an exact
     * copy of it) once, and keeps it in memory for the rest of the
     * request. Call this before anything else in this class.
     *
     * Throws rather than falling back to nothing, on every failure mode:
     * a missing/unreadable file, a file that is not valid JSON, a file
     * that is not this policy's data at all, and a file whose data_version
     * does not match what this copy of the code was written against. A
     * silent fallback here would mean every rule below could quietly give
     * a wrong answer with no sign anything was wrong - the policy's
     * "Never silently fall back" (LANG-003's spirit, applied to the whole
     * file) is treated as absolute for this one entry point.
     *
     * A `data_version` mismatch is deliberately fatal, not a warning: if
     * the registry refresh that produced the new file changed even one
     * answer, this code (compiled against the old data's assumptions in
     * spirit, even though nothing is truly "compiled" in PHP) could give a
     * different result than the fixtures it was tested against. Taking a
     * new data version is a deliberate step (policy section 8.3) that goes
     * together with re-running the conformance tests, not something that
     * should happen invisibly because a file on disk changed underneath
     * a running application.
     *
     * @throws \RuntimeException on any of the failures described above.
     */
    public static function loadData(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                "MWBM-MEDIA-LANG: cannot read the reference data file at '{$path}'."
            );
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(
                "MWBM-MEDIA-LANG: failed to read the reference data file at '{$path}'."
            );
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "MWBM-MEDIA-LANG: the reference data file at '{$path}' is not valid JSON."
            );
        }
        $policy = $decoded['policy'] ?? null;
        if ($policy !== self::EXPECTED_POLICY) {
            $found = is_string($policy) ? $policy : 'none';
            throw new \RuntimeException(
                "MWBM-MEDIA-LANG: the reference data file at '{$path}' declares policy "
                . "'{$found}', not '" . self::EXPECTED_POLICY . "'."
            );
        }
        $dataVersion = $decoded['data_version'] ?? null;
        if ($dataVersion !== self::EXPECTED_DATA_VERSION) {
            $found = is_string($dataVersion) ? $dataVersion : 'none';
            throw new \RuntimeException(
                "MWBM-MEDIA-LANG: the reference data file at '{$path}' has data_version "
                . "'{$found}'; this file was written against '"
                . self::EXPECTED_DATA_VERSION . "'. Take the new data version deliberately "
                . '(see the policy document, "Changing this policy") rather than loading '
                . 'a data file this code has not been checked against.'
            );
        }
        self::$data = $decoded;
    }

    /** @throws \RuntimeException if loadData() has not been called yet. */
    private static function data(): array
    {
        if (self::$data === null) {
            throw new \RuntimeException(
                'MWBM-MEDIA-LANG: reference data has not been loaded. '
                . 'Call Policy::loadData($path) once before using this class.'
            );
        }
        return self::$data;
    }

    // ------------------------------------------------------------------
    // Canonicalisation and reading old formats (LANG-001 to LANG-004)
    // ------------------------------------------------------------------

    /**
     * Turns any raw value into canonical BCP 47 form (LANG-001).
     *
     * Never throws and never returns null: a value that cannot be made
     * into a well-formed tag comes back as TagKind::Malformed, carrying
     * its own original text in ->tag, so it can be reported and sorted
     * last (LANG-025, LANG-026) rather than silently vanishing.
     *
     * What this cannot do: it does not check that a subtag which is
     * syntactically fine is actually REGISTERED (a language like `en-JJ`,
     * where `JJ` is not an assigned region, comes back well-formed and
     * unchanged - LANG-001 says such a value "is kept, and SHOULD be
     * reported as unregistered", which is the caller's job, not this
     * function's). It also never invents a script or region the input did
     * not have (LANG-024): `en` stays `en`.
     */
    public static function canonicalise(string $raw): LanguageTag
    {
        // LANG-001 step 1: remove leading/trailing U+0020, U+0009, U+000A
        // and U+000D ONLY - those four characters, and no others. A
        // no-break space (U+00A0) or any other character is part of the
        // value, not whitespace to discard, and makes the value malformed
        // if it is not otherwise a well-formed tag. PHP's bare trim() also
        // strips a NUL byte and a vertical tab, which this policy does
        // not ask for here (LANG-002's legacy reader is the one place a
        // NUL byte is stripped, and only there) - naming the four
        // characters explicitly, rather than calling trim($raw) alone,
        // keeps this function from silently drifting to match trim()'s
        // wider default set if that default ever changes.
        $stripped = trim($raw, " \t\r\n");
        if ($stripped === '') {
            // An empty string (after trimming) is not a tag at all. Keep
            // the ORIGINAL, untrimmed text, for the same reason LANG-026
            // keeps a malformed value's text generally.
            return new LanguageTag($raw, TagKind::Malformed, null, null, null, null, [], [], []);
        }

        $data = self::data();
        $lower = strtolower($stripped);

        // Step 2: a whole-tag grandfathered match, checked before anything
        // else is parsed, because a grandfathered tag is not reordered or
        // split - it is either replaced whole or kept exactly as spelled.
        if (isset($data['grandfathered'][$lower])) {
            $entry = $data['grandfathered'][$lower];
            if ($entry['preferred'] !== null) {
                // The replacement is itself run back through canonicalise()
                // so it gets the ordinary treatment (case, etc.) - this is
                // how "i-klingon" ends up Ordinary("tlh") rather than some
                // half-grandfathered hybrid.
                return self::canonicalise($entry['preferred']);
            }
            return new LanguageTag($entry['tag'], TagKind::Grandfathered, null, null, null, null, [], [], []);
        }

        $parsed = self::parseWellFormed($stripped);
        if ($parsed === null) {
            // Step 3: not well-formed grammar at all.
            return new LanguageTag($stripped, TagKind::Malformed, null, null, null, null, [], [], []);
        }

        if ($parsed['language'] === null) {
            // A tag that is nothing but `x-...` (private use only).
            return new LanguageTag(
                self::joinTag($parsed),
                TagKind::PrivateUse,
                null,
                null,
                null,
                null,
                [],
                [],
                $parsed['privateUse']
            );
        }

        // Step 4: a redundant tag with a registered replacement. Checked
        // on the tag as parsed so far (case-normalised per the grammar,
        // but before Preferred-Value substitution), matching the policy's
        // own ordering of steps 4 then 5.
        $joinedForRedundantCheck = strtolower(self::joinTag($parsed));
        if (isset($data['redundant_preferred'][$joinedForRedundantCheck])) {
            return self::canonicalise($data['redundant_preferred'][$joinedForRedundantCheck]);
        }

        // Step 5: Preferred-Value substitution, one subtag kind at a time.
        // A REGISTERED extlang replaces the language before it (LANG-001:
        // "A registered extlang replaces the language before it:
        // zh-yue-HK -> yue-HK" - and the LANGUAGE IT LEAVES BEHIND is then
        // itself checked for its own Preferred-Value, which is why
        // "ar-ajp" becomes language "ajp" on this line and then "apc" on
        // the very next one). An extlang the registry does not list at
        // all is a different case entirely: "zh-abc" is syntactically an
        // extlang position, but nothing registers "abc" as one, so it is
        // left exactly where it is, on the tag, rather than folded into
        // the language or dropped - LANG-021 then counts it as an
        // "additional" subtag (see specificityKey() below), the same way
        // it counts a variant or an extension.
        if ($parsed['extlang'] !== null && isset($data['preferred']['extlang'][$parsed['extlang']])) {
            $parsed['language'] = $data['preferred']['extlang'][$parsed['extlang']];
            $parsed['extlang'] = null;
        }
        $parsed['language'] = $data['preferred']['language'][$parsed['language']] ?? $parsed['language'];
        if ($parsed['script'] !== null) {
            $parsed['script'] = $data['preferred']['script'][$parsed['script']] ?? $parsed['script'];
        }
        if ($parsed['region'] !== null) {
            $parsed['region'] = $data['preferred']['region'][$parsed['region']] ?? $parsed['region'];
        }
        // A variant's own replacement can coincide with a variant already
        // sitting later in the tag ("ja-Latn-hepburn-heploc-alalc97": the
        // second variant "heploc" replaces to "alalc97", which is already
        // the third variant) - keep only the FIRST occurrence of each
        // result, dropping the later duplicate, so the tag stays
        // well-formed (LANG-001 forbids the same variant appearing twice)
        // and stable under re-canonicalisation.
        $substitutedVariants = array_map(
            static fn (string $v): string => $data['preferred']['variant'][$v] ?? $v,
            $parsed['variants']
        );
        $dedupedVariants = [];
        $seenVariants = [];
        foreach ($substitutedVariants as $variant) {
            if (!isset($seenVariants[$variant])) {
                $seenVariants[$variant] = true;
                $dedupedVariants[] = $variant;
            }
        }
        $parsed['variants'] = $dedupedVariants;

        // Step 6: extensions in order of their singleton letter. PHP 8's
        // usort() is stable, but there is nothing to break a tie on here
        // anyway - LANG-001 forbids the same extension letter appearing
        // twice (checked in parseWellFormed()), so every singleton in this
        // list is distinct and the sort is a total order already.
        usort($parsed['extensions'], static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $result = self::joinTag($parsed);

        // Canonical form must be STABLE: canonicalising an already-
        // canonical tag must return it unchanged. The substitutions above
        // can themselves produce a NEW redundant or grandfathered tag that
        // was not visible before substitution ("sgn-DD" only becomes the
        // redundant tag "sgn-DE" after its region is replaced; "sgn-DE" in
        // turn replaces whole to "gsg"), so after building the result,
        // check it again the same way steps 4 and would-be-step-2 do, and
        // keep going until a pass leaves the tag unchanged. The
        // "$result !== $stripped" guard (case-insensitively) is what stops
        // this looping forever: a result identical to what was already
        // checked at this level cannot loop back into the same steps
        // again with a different answer.
        if (strcasecmp($result, $stripped) !== 0) {
            $resultLower = strtolower($result);
            if (isset($data['redundant_preferred'][$resultLower]) || isset($data['grandfathered'][$resultLower])) {
                return self::canonicalise($result);
            }
        }

        return new LanguageTag(
            $result,
            TagKind::Ordinary,
            $parsed['language'],
            $parsed['extlang'],
            $parsed['script'],
            $parsed['region'],
            $parsed['variants'],
            $parsed['extensions'],
            $parsed['privateUse']
        );
    }

    /**
     * Reads a language value out of an old three-letter field, or any other
     * field or free-text value that might hold one (LANG-002): MP4's media
     * header, Matroska's pre-v4 `Language` element, ID3's
     * `TLAN`/`COMM`/`USLT` language, most `ffprobe` output, and free-text
     * fields other tools write a three-letter code into (Vorbis/MP4
     * `LANGUAGE`, which MusicBrainz Picard among others writes `eng` into).
     * **Every language value read from a file, a tag or another system
     * MUST go through this reader**, not straight into canonicalise() -
     * canonicalise() alone is for a value already known to be a BCP 47 tag
     * (one a person typed into a tag field, say).
     *
     * Returns the canonical BCP 47 tag, or null when the value cannot be
     * turned into one at all (LANG-002's "unrecognised... MUST NOT be
     * turned into a guessed language"). A null here means: store `und`,
     * and keep the original text somewhere so a person can fix it
     * (COMPAT-040) - this function only reports "I don't know", it never
     * decides what to store for "I don't know".
     */
    public static function fromLegacyThreeLetter(string $raw): ?string
    {
        // Before the numbered steps: fixed-width fields are padded with
        // trailing NUL bytes (U+0000), which are never part of the value,
        // so they come off first and only from the end - a NUL is never
        // meaningful in the MIDDLE of a genuine single value. Then the
        // same four whitespace characters as LANG-001 step 1. If a NUL
        // still remains after that (ID3v2.4 separates several values in
        // one field with a NUL, and the first is the primary language),
        // split on it and read only the first value, trimmed the same way.
        $value = trim(rtrim($raw, "\0"), " \t\r\n");
        if (str_contains($value, "\0")) {
            $value = trim(explode("\0", $value, 2)[0], " \t\r\n");
        }

        if (strtoupper($value) === 'XXX' && strlen($value) === 3) {
            // ID3's own "language not known" marker (LANG-002 step 3).
            return 'und';
        }

        // Matroska (pre-v4) three-letter-code + hyphen + two-letter-country
        // shape, e.g. "fre-ca" (LANG-002 step 4) - but ONLY when the three
        // letters are not themselves a registered BCP 47 language subtag.
        // "und-GB" and "yue-HK" already have a language on the left of the
        // hyphen ("und", "yue" are both registered directly), so they are
        // ordinary two-part tags, not a Matroska three-plus-two shape, and
        // fall through to the closing "canonicalise as a tag" step below.
        // "fre-ca" is different: "fre" is not itself registered as a BCP 47
        // language (only as the old ISO 639-2 code for French), so this
        // shape is the right reading and "fre-ca" becomes "fr-CA".
        if (preg_match('/\A([A-Za-z]{3})-([A-Za-z]{2})\z/', $value, $m) === 1
            && !in_array(strtolower($m[1]), self::data()['languages'], true)
        ) {
            $base = self::fromLegacyThreeLetter($m[1]);
            if ($base === null || $base === 'und') {
                return null;
            }
            $withRegion = self::canonicalise($base . '-' . $m[2]);
            return $withRegion->isMalformed() ? null : $withRegion->tag;
        }

        if (strlen($value) === 3 && self::isAsciiAlpha($value)) {
            $code = strtolower($value);
            $data = self::data();
            if (isset($data['iso639_2'][$code])) {
                // LANG-002 step 1: a known ISO 639-2 code (either form, or
                // a withdrawn one such as `scc`).
                return $data['iso639_2'][$code];
            }
            $isRegisteredLanguage = in_array($code, $data['languages'], true)
                || self::inAnyRange($code, $data['language_ranges']);
            if ($isRegisteredLanguage) {
                // LANG-002 step 2: a genuine ISO 639-3 code (e.g. `yue`),
                // or a local-use code in the qaa-qtz range.
                return self::canonicalise($code)->tag;
            }
            // LANG-002 step 5: three letters, but not recognised as
            // anything. Never guessed at.
            return null;
        }

        // The policy's closing paragraph: "A two-letter or longer value in
        // such a field is canonicalised as a tag" - covers values already
        // shaped like a real tag (`en`, `en-GB`), and rejects anything
        // that is not well-formed at all (`English`, a bare `e`).
        $tag = self::canonicalise($value);
        return $tag->isMalformed() ? null : $tag->tag;
    }

    /**
     * Converts an operating-system locale name (LANG-004), such as
     * `en_US.UTF-8` or `sr_RS@latin`, into a canonical BCP 47 tag.
     *
     * Returns null when the locale names no language at all (`C`,
     * `POSIX`) or when what remains cannot be turned into a tag.
     */
    public static function fromPosixLocale(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '' || $value === 'C' || $value === 'POSIX') {
            return null;
        }

        $modifier = null;
        if (str_contains($value, '@')) {
            [$value, $modifier] = explode('@', $value, 2);
        }
        if (str_contains($value, '.')) {
            $value = explode('.', $value, 2)[0];
        }
        $value = str_replace('_', '-', $value);

        if ($modifier === 'latin' || $modifier === 'cyrillic') {
            // LANG-004 step 4: @latin -> script Latn, @cyrillic -> script
            // Cyrl, placed right after the language. Any other modifier is
            // dropped (SHOULD be reported - the caller's job, not this
            // function's, matching fromLegacyThreeLetter()'s stance above).
            $parts = explode('-', $value);
            array_splice($parts, 1, 0, [$modifier === 'latin' ? 'Latn' : 'Cyrl']);
            $value = implode('-', $parts);
        }

        $tag = self::canonicalise($value);
        return $tag->isMalformed() ? null : $tag->tag;
    }

    /**
     * The ISO 639-2 code to write into an old three-letter field
     * (TRACK-070), in both forms: 'b' (bibliographic - Matroska's old
     * `Language` element) and 't' (terminology - MP4/MOV's media header,
     * and ID3). Unlike canonicalise() and the other reading/converting
     * functions above, this one is never asked to report "I don't know" -
     * a three-letter field always needs SOME three-letter value written
     * into it, so this function always returns a concrete pair, never
     * null: `und`/`und` is that value whenever nothing better applies.
     *
     * Accepts either a full tag or a bare language subtag; the input is
     * canonicalised first (LANG-001), and only the CANONICAL primary
     * language is used - script and region play no part in which
     * three-letter code to write, and a deprecated language subtag with a
     * replacement (`bh`, which canonicalises to `bih`) is looked up under
     * its replacement, not its original spelling.
     *
     * In order: a grandfathered, private-use or malformed value writes
     * `und`/`und` (there is no "language" to look anything up under); the
     * canonical primary language is looked up in the data file's
     * `iso639_2_for_language` table, and that entry is used if there is
     * one; a primary language in the local-use range `qaa`-`qtz` writes
     * itself for both forms (ISO 639-2 reserves that exact range the same
     * way BCP 47 does); anything else writes `und`/`und` - a language with
     * no ISO 639-2 code at all (`yue`, Cantonese, is a real example: it
     * has no two-or-three-letter code of its own in ISO 639-2, only in the
     * newer ISO 639-3).
     *
     * @return array{b: string, t: string}
     */
    public static function iso6392CodesForWriting(string $tagOrLanguage): array
    {
        $undetermined = ['b' => 'und', 't' => 'und'];

        $tag = self::canonicalise($tagOrLanguage);
        if ($tag->kind !== TagKind::Ordinary) {
            return $undetermined;
        }

        $data = self::data();
        $entry = $data['iso639_2_for_language'][$tag->language] ?? null;
        if ($entry !== null) {
            return ['b' => $entry['b'], 't' => $entry['t']];
        }

        [$localUseFirst, $localUseLast] = $data['iso639_2_local_use'];
        if (strlen((string) $tag->language) === 3 && $tag->language >= $localUseFirst && $tag->language <= $localUseLast) {
            return ['b' => $tag->language, 't' => $tag->language];
        }

        return $undetermined;
    }

    /**
     * Builds a sidecar file name (TEXT-030):
     * `{stem}.{tag}[.{role}…][.{number}].{extension}` - for example
     * `Film.en-GB.sdh.srt` or, with a clash-avoiding number,
     * `Mr. Robot.fr-CA.commentary.2.srt`.
     *
     * The tag comes first, always, straight after the stem: it is the
     * canonical tag (LANG-001), or `und` when $tag is malformed - a
     * malformed value never goes into a file name, because it could hold
     * characters that are unsafe in a path (LANG-026 keeps its text for
     * reporting, not for naming files with). A grandfathered or
     * private-use tag keeps its own canonical text (`i-default`, `x-foo`),
     * since neither of those is malformed.
     *
     * $roles may hold any of this file's Role values, but only Sdh,
     * Forced and Commentary ever produce a word in the name (TEXT-030
     * lists exactly these three) - anything else (Alternate,
     * AudioDescription, Other) is silently dropped, duplicates are
     * removed, and what remains is written in TRACK-050's subtitle order
     * (full < SDH < forced < commentary), regardless of the order $roles
     * was given in.
     *
     * $number is written only when given - "a number (.2, .3 …) is added
     * only when two sidecars would otherwise get the same name, numbering
     * from the second". A non-null $number MUST be at least 2 (numbering
     * starts at the SECOND file; there is no such thing as a clash-
     * avoiding number of 0, 1 or a negative amount) and at most
     * 999,999,999 - nine digits, because parseSidecarName() never reads a
     * run of more than nine digits back as a number (TEXT-030), so a
     * number this function wrote could otherwise never be read back at
     * all. Either limit being broken is a caller error, not a value this
     * function can make sense of, so it throws rather than silently
     * writing a name nothing can parse correctly - see the class doc
     * comment's stance on refusing rather than guessing.
     *
     * @param list<string> $roles Role values as plain strings (see the
     *   Role enum); only 'sdh', 'forced' and 'commentary' matter here.
     * @throws \InvalidArgumentException if $number is given and is less
     *   than 2 or more than 999999999.
     */
    public static function buildSidecarName(
        string $stem,
        string $tag,
        array $roles,
        string $extension,
        ?int $number = null
    ): string {
        if ($number !== null && ($number < 2 || $number > 999999999)) {
            throw new \InvalidArgumentException(
                "MWBM-MEDIA-LANG: a sidecar clash-avoiding number must be null or between 2 "
                . "and 999999999 (nine digits - anything longer could never be read back by "
                . "parseSidecarName()), got {$number}."
            );
        }

        $canonical = self::canonicalise($tag);
        $language = $canonical->isMalformed() ? 'und' : $canonical->tag;

        $allowedRoleWords = ['sdh', 'forced', 'commentary'];
        $seen = [];
        foreach ($roles as $role) {
            if (in_array($role, $allowedRoleWords, true)) {
                $seen[$role] = true;
            }
        }
        $words = array_keys($seen);
        usort($words, static fn (string $a, string $b): int => self::SUBTITLE_ROLE_RANK[$a] <=> self::SUBTITLE_ROLE_RANK[$b]);

        $parts = array_merge([$stem, $language], $words);
        if ($number !== null) {
            $parts[] = (string) $number;
        }
        $parts[] = $extension;

        return implode('.', $parts);
    }

    /**
     * Reads a sidecar file name back into its parts (TEXT-030), given the
     * stem of the media file it is meant to belong to. Returns null when
     * $filename does not start with $stem followed by a literal dot - the
     * stem is taken from the media file itself, never guessed at from the
     * sidecar's own name, so a file such as `Mr. Robot.en.sdh.srt` (whose
     * own name happens to contain a dot before its language part) is
     * still read correctly once the caller supplies `Mr. Robot` as the
     * stem.
     *
     * The language part (the first piece after the stem, if there is one
     * at all) is read with fromLegacyThreeLetter() (LANG-002) - the same
     * reader used for any language value from outside a proper tag field
     * - so `Film.eng.forced.srt` reads its language as `en`, not `eng`.
     * When there is nothing between the stem and the extension at all
     * (`Film.srt`), the tag is null, meaning the name carries no language
     * part - that is different from an unrecognised one, which gets `und`
     * plus the original text in 'unrecognised' (COMPAT-040: reported, not
     * guessed at). Because the tag always comes first, a role word is
     * never mistaken for a language - `Film.sdh.srt` reads as language
     * `sdh` (a real code, Southern Kurdish), not as a bare SDH marker with
     * no language, because there is only one part between the stem and
     * the extension and it fills the language slot.
     *
     * Every part after the language is checked, in order found: a part
     * made of ONE TO NINE ASCII digits and nothing else is the clash-
     * avoiding number (the LAST one found wins, if there is somehow more
     * than one) - a run of ten or more digits is not a number this format
     * uses (TEXT-030; buildSidecarName() never writes more than nine, so
     * ten or more could only be something else that happens to look like
     * one) and is ignored, exactly like any other unrecognised part; a
     * part that reads (case-insensitively) as `sdh`, `cc`, `hi`, `forced`
     * or `commentary` adds a role - `cc` and `hi` both mean `sdh`, because
     * other tools write them, and repeats are silently folded into one;
     * anything else is ignored, rather than treated as an error.
     *
     * @return array{
     *     tag: string|null,
     *     unrecognised: string|null,
     *     roles: list<string>,
     *     number: int|null,
     *     extension: string
     * }|null
     */
    public static function parseSidecarName(string $stem, string $filename): ?array
    {
        $prefix = $stem . '.';
        if (!str_starts_with($filename, $prefix)) {
            return null;
        }

        $parts = explode('.', substr($filename, strlen($prefix)));
        $extension = array_pop($parts);
        $middle = $parts;

        $result = [
            'tag' => null,
            'unrecognised' => null,
            'roles' => [],
            'number' => null,
            'extension' => $extension,
        ];
        if ($middle === []) {
            return $result;
        }

        $language = self::fromLegacyThreeLetter($middle[0]);
        if ($language === null) {
            $result['tag'] = 'und';
            $result['unrecognised'] = $middle[0];
        } else {
            $result['tag'] = $language;
        }

        $roleWords = [
            'sdh' => 'sdh',
            'cc' => 'sdh',
            'hi' => 'sdh',
            'forced' => 'forced',
            'commentary' => 'commentary',
        ];
        $roles = [];
        for ($i = 1; $i < count($middle); $i++) {
            $piece = $middle[$i];
            if (preg_match('/\A[0-9]{1,9}\z/', $piece) === 1) {
                $result['number'] = (int) $piece;
                continue;
            }
            $lowered = strtolower($piece);
            if (isset($roleWords[$lowered])) {
                $roles[$roleWords[$lowered]] = true;
            }
        }
        $roleList = array_keys($roles);
        usort($roleList, static fn (string $a, string $b): int => self::SUBTITLE_ROLE_RANK[$a] <=> self::SUBTITLE_ROLE_RANK[$b]);
        $result['roles'] = $roleList;

        return $result;
    }

    // ------------------------------------------------------------------
    // Part A: canonical stored order (LANG-010 to LANG-027, TRACK-050/060)
    // ------------------------------------------------------------------

    /**
     * Orders a list of language-tagged items the way they belong in stored
     * content (LANG-010 to LANG-027): the original language's whole group
     * first, then every other group by primary language code, general
     * forms before specific ones, special codes (`mul`/`mis`/`und`/`zxx`/
     * `qaa`-`qtz`) after every ordinary language, malformed values last of
     * all, and ties kept in their original order.
     *
     * This is the SAME order regardless of who is looking or what
     * interface language they use - see the policy's "Two orders, on
     * purpose". Never use this function's result to build a menu; use
     * sortPresentation() for that instead.
     *
     * @param list<array{tag: string, original?: bool}> $items Each item's
     *   other keys, if any, are preserved untouched and returned as-is;
     *   this function only reads 'tag' and 'original' (absent means
     *   false) and reorders the array.
     * @return list<array<string, mixed>> the same items, reordered.
     */
    public static function sortCanonicalOrder(array $items): array
    {
        return self::canonicalCompareSort($items, static fn (array $item): int => 0);
    }

    /**
     * Orders a container's tracks the way they belong on disk (TRACK-050,
     * TRACK-060): video, then audio, then subtitles, then anything else
     * (types are never interleaved); within each type, the same LANG-010
     * to LANG-027 rules as sortCanonicalOrder(), with roles as an
     * additional tie-break placed BEFORE specificity (main programme
     * before an alternate mix before audio description before commentary
     * before anything else, for audio; full subtitles before SDH/captions
     * before forced before commentary before anything else, for
     * subtitles). A track with more than one role is placed by whichever
     * one of its roles sorts latest.
     *
     * @param list<array{
     *     type: string,
     *     tag: string,
     *     roles?: list<string>,
     *     original?: bool
     * }> $tracks
     * @return list<array<string, mixed>> the same tracks, reordered.
     */
    public static function sortTrackOrder(array $tracks): array
    {
        $typeRank = ['video' => 0, 'audio' => 1, 'subtitle' => 2];
        $byType = [];
        foreach ($tracks as $track) {
            $byType[$track['type']][] = $track;
        }
        uksort($byType, static fn (string $a, string $b): int => ($typeRank[$a] ?? 3) <=> ($typeRank[$b] ?? 3));

        $result = [];
        foreach ($byType as $type => $group) {
            $roleRank = static fn (array $item): int => self::trackRoleRank($item, $type);
            $result = array_merge($result, self::canonicalCompareSort($group, $roleRank));
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Part B: presentation order (UI-010 to UI-070)
    // ------------------------------------------------------------------

    /**
     * Orders a list of language items the way they belong in a menu
     * (UI-020 to UI-050): the user's preferences first (in their own
     * priority order), then the original language, then every remaining
     * ordinary language group alphabetically by LOCALISED NAME, then the
     * special codes, then malformed values last.
     *
     * This file holds no language names and does not use PHP's `intl`
     * extension (see the class doc comment) - $groupCompare is how the
     * caller supplies that. It is called ONLY to order the "everything
     * else" groups (UI-040), and only with two PRIMARY LANGUAGE subtags
     * (e.g. "de", "ja"), never a whole tag: it should return the same
     * thing `Intl\Collator::compare()` or `strcoll()` would return when
     * comparing those two languages' localised names in the interface
     * language - negative if $primaryA sorts first, positive if $primaryB
     * does, 0 if they are equal (in which case this function breaks the
     * tie by comparing the primary language subtags themselves, ASCII,
     * per UI-040's "Groups with the same name are ordered by primary
     * language code" - the caller does not need to do that part).
     *
     * Selecting a track never reorders this list (UI-050): call
     * selectAudioTrack()/selectSubtitleTrack() separately and show the
     * result as a tick or a selected row, not by moving anything.
     *
     * @param list<array{
     *     tag: string,
     *     type?: string,
     *     roles?: list<string>,
     *     original?: bool
     * }> $items Any other keys (an 'id', for instance) are preserved.
     * @param list<string> $preferences The user's preferred tags, highest
     *   priority first. A malformed preference is silently ignored, per
     *   the fixture data's own convention - it can never match anything
     *   anyway.
     * @param callable(string, string): int $groupCompare
     * @param array{audio_description?: bool, captions?: bool} $accessibility
     * @return list<array<string, mixed>> the same items, reordered.
     */
    public static function sortPresentation(
        array $items,
        array $preferences,
        callable $groupCompare,
        array $accessibility = []
    ): array {
        $decorated = [];
        foreach ($items as $index => $item) {
            $tag = self::canonicalise($item['tag']);
            $decorated[] = [
                'item' => $item,
                'tag' => $tag,
                'bucket' => self::bucket($tag),
                'groupKey' => self::groupKey($tag),
            ];
        }

        $groups = [];
        $groupKeyByString = [];
        foreach ($decorated as $entry) {
            $keyString = self::groupKeyString($entry['groupKey']);
            $groups[$keyString][] = $entry;
            $groupKeyByString[$keyString] = $entry['groupKey'];
        }

        $order = [];
        $seen = [];

        // UI-020: the user's preferences, in their own priority order. A
        // preference's group is its primary language, so a preference for
        // `en-GB` brings the WHOLE English group forward, not just en-GB.
        foreach ($preferences as $preference) {
            $preferenceTag = self::canonicalise($preference);
            if ($preferenceTag->isMalformed()) {
                continue;
            }
            $groupKey = self::groupKey($preferenceTag);
            if ($groupKey[0] === 8) {
                continue;
            }
            $keyString = self::groupKeyString($groupKey);
            if (isset($groups[$keyString]) && !isset($seen[$keyString])) {
                $order[] = $keyString;
                $seen[$keyString] = true;
            }
        }

        // UI-030: the original language's group next (if not already
        // placed by a preference). This applies to special codes too - a
        // track marked original whose language is `und` or `mul` still
        // comes first, because it IS the original (LANG-010); only a
        // malformed value is never promoted, which is why $entry['bucket']
        // !== 8 is the only exclusion here, not "ordinary only".
        //
        // Several original languages (a bilingual work) all come next.
        // UI-030 (revised) orders them among THEMSELVES the same way
        // UI-040 orders everything else: ordinary language groups first,
        // alphabetically by localised name (the caller's $groupCompare,
        // same as the "rest" step below), so a menu with two originals
        // still reads alphabetically rather than by code; any ORIGINAL
        // special-code group (rare, but possible) follows after, in
        // LANG-025's fixed order - special codes never have a localised
        // name to alphabetise by.
        $originalGroupKeys = [];
        foreach ($decorated as $entry) {
            if (!empty($entry['item']['original']) && $entry['bucket'] !== 8) {
                $originalGroupKeys[self::groupKeyString($entry['groupKey'])] = $entry['groupKey'];
            }
        }
        $originalOrdinary = [];
        $originalSpecial = [];
        foreach ($originalGroupKeys as $groupKey) {
            if ($groupKey[0] === 0) {
                $originalOrdinary[] = $groupKey;
            } else {
                $originalSpecial[] = $groupKey;
            }
        }
        usort(
            $originalOrdinary,
            static fn (array $a, array $b): int => $groupCompare($a[1], $b[1]) ?: strcmp($a[1], $b[1])
        );
        usort($originalSpecial, [self::class, 'compareGroupKey']);
        foreach (array_merge($originalOrdinary, $originalSpecial) as $groupKey) {
            $keyString = self::groupKeyString($groupKey);
            if (!isset($seen[$keyString])) {
                $order[] = $keyString;
                $seen[$keyString] = true;
            }
        }

        // UI-040: every remaining ORDINARY group, alphabetically by
        // localised name (the caller's $groupCompare), then by primary
        // language code for a tie.
        $restKeys = [];
        foreach ($groups as $keyString => $entries) {
            if (isset($seen[$keyString])) {
                continue;
            }
            $groupKey = $groupKeyByString[$keyString];
            if ($groupKey[0] === 0) {
                $restKeys[] = $groupKey;
            }
        }
        usort(
            $restKeys,
            static fn (array $a, array $b): int => $groupCompare($a[1], $b[1]) ?: strcmp($a[1], $b[1])
        );
        foreach ($restKeys as $groupKey) {
            $keyString = self::groupKeyString($groupKey);
            $order[] = $keyString;
            $seen[$keyString] = true;
        }

        // UI-040's last sentence: the special codes, in LANG-025's fixed
        // order, after every ordinary group.
        $specialKeys = [];
        foreach ($groups as $keyString => $entries) {
            if (isset($seen[$keyString])) {
                continue;
            }
            $groupKey = $groupKeyByString[$keyString];
            if ($groupKey[0] !== 8) {
                $specialKeys[] = $groupKey;
            }
        }
        usort($specialKeys, [self::class, 'compareGroupKey']);
        foreach ($specialKeys as $groupKey) {
            $keyString = self::groupKeyString($groupKey);
            $order[] = $keyString;
            $seen[$keyString] = true;
        }

        // Malformed values last of all.
        $malformedKey = self::groupKeyString([8, '']);
        if (isset($groups[$malformedKey])) {
            $order[] = $malformedKey;
        }

        $preferenceTags = [];
        foreach ($preferences as $preference) {
            $preferenceTag = self::canonicalise($preference);
            if (!$preferenceTag->isMalformed()) {
                $preferenceTags[] = $preferenceTag->tag;
            }
        }

        $result = [];
        foreach ($order as $keyString) {
            $entries = $groups[$keyString];
            usort(
                $entries,
                static fn (array $a, array $b): int => self::compareWithinPresentationGroup(
                    $a,
                    $b,
                    $preferenceTags,
                    $accessibility
                )
            );
            foreach ($entries as $entry) {
                $result[] = $entry['item'];
            }
        }
        return $result;
    }

    /**
     * A subtitle menu (UI-060): "Off" first, as its own entry - not a
     * language, and never sorted in among them - followed by every
     * subtitle track in presentation order (see sortPresentation() for
     * every parameter here).
     *
     * "Off" is represented by `null` in the returned list, since a real
     * subtitle track is always the caller's own array. A caller building
     * an actual menu should treat a `null` entry as its own fixed "Off"
     * row and everything after it as sortPresentation()'s normal output.
     *
     * @param list<array{
     *     tag: string,
     *     type?: string,
     *     roles?: list<string>,
     *     original?: bool
     * }> $items
     * @param list<string> $preferences
     * @param callable(string, string): int $groupCompare
     * @param array{audio_description?: bool, captions?: bool} $accessibility
     * @return list<array<string, mixed>|null>
     */
    public static function sortSubtitleMenu(
        array $items,
        array $preferences,
        callable $groupCompare,
        array $accessibility = []
    ): array {
        $ordered = self::sortPresentation($items, $preferences, $groupCompare, $accessibility);
        array_unshift($ordered, null);
        return $ordered;
    }

    /**
     * Builds a menu label from structured data (UI-070):
     * "English (United Kingdom) — Audio Description — 5.1" - language
     * name, then roles in TRACK-050's order, then (for audio) the channel
     * layout, joined with " — " (space, em dash, space).
     *
     * $roles is a list of raw role identifiers (see the Role enum); only
     * roles present in $roleNames are actually shown (a role with no
     * localised word supplied is silently skipped, rather than showing a
     * raw internal identifier to a user). $languageName and every value in
     * $roleNames are expected to already be localised - this function only
     * orders and joins them, per its one job (see the class doc comment:
     * this is not where names come from).
     *
     * @param 'audio'|'subtitle' $trackType Decides which TRACK-050 role
     *   order applies.
     * @param list<string> $roles
     * @param array<string, string> $roleNames
     */
    public static function buildLabel(
        string $trackType,
        string $languageName,
        array $roles,
        array $roleNames,
        ?string $channels = null
    ): string {
        $rankTable = $trackType === 'audio' ? self::AUDIO_ROLE_RANK : self::SUBTITLE_ROLE_RANK;
        $sortedRoles = $roles;
        usort($sortedRoles, static fn (string $a, string $b): int => ($rankTable[$a] ?? 4) <=> ($rankTable[$b] ?? 4));

        $parts = [$languageName];
        foreach ($sortedRoles as $role) {
            if (isset($roleNames[$role]) && $roleNames[$role] !== '') {
                $parts[] = $roleNames[$role];
            }
        }
        if ($channels !== null && $channels !== '') {
            $parts[] = $channels;
        }

        return implode(" \u{2014} ", array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    // ------------------------------------------------------------------
    // Matching (MATCH-010 to MATCH-040)
    // ------------------------------------------------------------------

    /**
     * How strongly one preference tag matches one candidate tag
     * (MATCH-010 to MATCH-040). Best first: Exact, General (the candidate
     * is a shorter form of the preference), Specific (the candidate is a
     * longer form), Related (same primary language, no script conflict),
     * None.
     *
     * `und`, `mul`, `mis` and `zxx` only match themselves exactly - two
     * "unknown" or "uncoded" tracks need not be the same language
     * (MATCH-040's closing paragraph) - and so do private-use tags and a
     * grandfathered tag with no replacement, though those are excluded a
     * different way below (by never being TagKind::Ordinary), not by name.
     * A tag that merely ENDS in a private-use part (`en-x-foo`) is an
     * ordinary tag and is not affected by this. Two tags that both state a
     * script, and disagree, never match at all (a reader of one script
     * may not be able to read the other) - even though nothing here
     * infers a missing script (LANG-024), so `zh-TW` and `zh-Hans` come
     * back Related, not a script conflict, because `zh-TW` never actually
     * said what script it is in.
     *
     * Never changes either tag - this is read-only comparison.
     */
    public static function matchTags(string $preference, string $candidate): TagMatch
    {
        $preferenceTag = self::canonicalise($preference);
        $candidateTag = self::canonicalise($candidate);

        if ($preferenceTag->isMalformed() || $candidateTag->isMalformed()) {
            return new TagMatch(MatchLevel::None, 0);
        }
        if (strtolower($preferenceTag->tag) === strtolower($candidateTag->tag)) {
            return new TagMatch(MatchLevel::Exact, 0);
        }
        if ($preferenceTag->kind !== TagKind::Ordinary || $candidateTag->kind !== TagKind::Ordinary) {
            return new TagMatch(MatchLevel::None, 0);
        }
        $specialLanguages = ['und', 'mul', 'mis', 'zxx'];
        if (in_array($preferenceTag->language, $specialLanguages, true)
            || in_array($candidateTag->language, $specialLanguages, true)
        ) {
            // Already known not to be an exact (identical-string) match at
            // this point, so any of these four only ever gives None.
            return new TagMatch(MatchLevel::None, 0);
        }
        if ($preferenceTag->language !== $candidateTag->language) {
            return new TagMatch(MatchLevel::None, 0);
        }
        if ($preferenceTag->script !== null
            && $candidateTag->script !== null
            && $preferenceTag->script !== $candidateTag->script
        ) {
            return new TagMatch(MatchLevel::None, 0);
        }

        // MATCH-020/030's distance counts every hyphen-separated part that
        // was added or removed, single-letter extension singletons
        // included: splitting the whole canonical tag text on '-' already
        // gives every part, in the same way `en-u-ca-gregory` split on
        // hyphen gives four parts against bare `en`'s one, for a distance
        // of three - there is no separate, coarser "subtag count" to keep
        // in step with this.
        $preferenceSubtags = explode('-', strtolower($preferenceTag->tag));
        $candidateSubtags = explode('-', strtolower($candidateTag->tag));
        $preferenceCount = count($preferenceSubtags);
        $candidateCount = count($candidateSubtags);

        if ($candidateCount < $preferenceCount
            && array_slice($preferenceSubtags, 0, $candidateCount) === $candidateSubtags
        ) {
            return new TagMatch(MatchLevel::General, $preferenceCount - $candidateCount);
        }
        if ($preferenceCount < $candidateCount
            && array_slice($candidateSubtags, 0, $preferenceCount) === $preferenceSubtags
        ) {
            return new TagMatch(MatchLevel::Specific, $candidateCount - $preferenceCount);
        }
        return new TagMatch(MatchLevel::Related, 0);
    }

    // ------------------------------------------------------------------
    // Automatic selection (AUTO-010 to AUTO-040)
    // ------------------------------------------------------------------

    /**
     * Chooses which audio track should play (AUTO-020): tracks PLACED
     * (TRACK-050 - the latest of a track's roles decides, and a role this
     * file does not recognise counts as "other") as commentary or other
     * are skipped unless every track is one (an alternate mix or audio
     * description CAN be chosen, just after the main programme - audio
     * description first if the user asked for it, and a track placed as
     * audio description because that is the LATEST of its roles, such as
     * one carrying both "alternate" and "audio_description", is treated as
     * audio description throughout, not as an alternate); then each
     * preference is tried in turn for the best match; if none match, the
     * original track(s); otherwise the default track(s); otherwise the
     * best by role again (so with nothing else to go on, a main-programme
     * track still wins over an audio-description track nobody asked for),
     * then canonical order, then the track's own identifier - NEVER its
     * position in the given list (AUTO-010), which is why this function
     * gives the same answer whichever order $tracks arrives in.
     *
     * Returns the chosen track's 'id', or null if $tracks is empty.
     *
     * @param list<array{
     *     id: string,
     *     tag: string,
     *     roles?: list<string>,
     *     default?: bool,
     *     original?: bool
     * }> $tracks
     * @param list<string> $preferences
     * @param array{audio_description?: bool} $accessibility
     * @throws \InvalidArgumentException if two tracks share the same 'id'
     *   (AUTO-010: the identifier must be unique, or there is nothing safe
     *   to break a tie on - never guessed at by falling back to list
     *   position).
     */
    public static function selectAudioTrack(array $tracks, array $preferences, array $accessibility = []): ?string
    {
        if ($tracks === []) {
            return null;
        }
        self::requireUniqueIdentifiers($tracks);

        $isSpecial = static fn (array $t): bool => self::trackRoleRank($t, 'audio') >= self::AUDIO_ROLE_RANK['commentary'];
        $eligible = array_values(array_filter($tracks, static fn (array $t): bool => !$isSpecial($t)));
        if ($eligible === []) {
            // AUTO-020 step 1's exception: "unless every audio track is
            // one" - fall back to every track rather than choosing nothing.
            $eligible = $tracks;
        }

        $positions = self::canonicalPositions($eligible, 'audio');
        // The PLACING role decides rank (0 = main, 1 = alternate,
        // 2 = audio description - commentary/other never reach here,
        // having been filtered into $isSpecial above), remapped when the
        // user asked for audio description so THAT rank comes first.
        $rolePriority = static function (array $track) use ($accessibility): int {
            $placement = self::trackRoleRank($track, 'audio');
            if (!empty($accessibility['audio_description'])) {
                return match ($placement) {
                    2 => 0,
                    0 => 1,
                    1 => 2,
                    default => 3,
                };
            }
            return $placement;
        };

        foreach ($preferences as $preference) {
            $candidates = [];
            foreach ($eligible as $track) {
                $match = self::matchTags($preference, $track['tag']);
                if ($match->level->rank() <= MatchLevel::Related->rank()) {
                    $candidates[] = ['track' => $track, 'match' => $match];
                }
            }
            if ($candidates === []) {
                continue;
            }
            usort($candidates, static function (array $x, array $y) use ($rolePriority, $positions): int {
                $t1 = $x['track'];
                $t2 = $y['track'];
                return ($rolePriority($t1) <=> $rolePriority($t2))
                    ?: ($x['match']->level->rank() <=> $y['match']->level->rank())
                    ?: ($x['match']->distance <=> $y['match']->distance)
                    ?: ((empty($t1['default']) ? 1 : 0) <=> (empty($t2['default']) ? 1 : 0))
                    ?: ((empty($t1['original']) ? 1 : 0) <=> (empty($t2['original']) ? 1 : 0))
                    ?: ($positions[$t1['id']] <=> $positions[$t2['id']])
                    ?: self::compareIdentifiers($t1['id'], $t2['id']);
            });
            return $candidates[0]['track']['id'];
        }

        foreach (['original', 'default'] as $flag) {
            $flagged = array_values(array_filter($eligible, static fn (array $t): bool => !empty($t[$flag])));
            if ($flagged === []) {
                continue;
            }
            usort($flagged, static function (array $t1, array $t2) use ($rolePriority, $positions): int {
                return ($rolePriority($t1) <=> $rolePriority($t2))
                    ?: ((empty($t1['default']) ? 1 : 0) <=> (empty($t2['default']) ? 1 : 0))
                    ?: ($positions[$t1['id']] <=> $positions[$t2['id']])
                    ?: self::compareIdentifiers($t1['id'], $t2['id']);
            });
            return $flagged[0]['id'];
        }

        // AUTO-020 step 5: nothing else to go on (no preference matched, no
        // original, no default) - role still comes first, so a main-
        // programme track is chosen over an audio-description or alternate
        // track nobody asked for, even though nothing else distinguishes
        // them; only then canonical order, then identifier.
        $best = null;
        foreach ($eligible as $track) {
            if ($best === null) {
                $best = $track;
                continue;
            }
            $comparison = ($rolePriority($track) <=> $rolePriority($best))
                ?: ($positions[$track['id']] <=> $positions[$best['id']])
                ?: self::compareIdentifiers($track['id'], $best['id']);
            if ($comparison < 0) {
                $best = $track;
            }
        }
        return $best['id'];
    }

    /**
     * Chooses which subtitle track (if any) should show, given the audio
     * already chosen (AUTO-030). $mode is the user's subtitle preference:
     *
     * - Off: nothing, always.
     * - ForcedOnly: the forced track that best matches the AUDIO's
     *   language; nothing if there is none, or if the audio's language is
     *   `und`/`mul`/`zxx` (nothing to match a forced track against).
     * - Always: the best non-forced, non-commentary, non-other subtitle
     *   matching a preference (SDH first only if the user asked for
     *   captions); the default full track if no preference matches;
     *   otherwise nothing.
     * - Automatic (the usual default): acts as ForcedOnly when the
     *   audio's language matches one of the user's preferences (or the
     *   user has none at all); otherwise acts as Always.
     *
     * A forced track is never chosen by Always, and a full track is never
     * chosen by ForcedOnly (TRACK-030: a forced track is not an ordinary
     * subtitle track in a different list position). Both use each track's
     * PLACING role (TRACK-050 - the latest of its roles; an unrecognised
     * role counts as "other"), not just whether a role is somewhere in its
     * list: a track carrying both "forced" and "sdh" is PLACED as forced
     * (forced sorts later than sdh), so ForcedOnly considers it and Always
     * does not, even though "sdh" is also one of its roles.
     *
     * @param list<array{
     *     id: string,
     *     tag: string,
     *     roles?: list<string>,
     *     default?: bool
     * }> $tracks
     * @param string|null $audioTag The already-chosen audio track's tag,
     *   or null if there is none.
     * @param list<string> $preferences
     * @param array{captions?: bool} $accessibility
     * @throws \InvalidArgumentException if two tracks share the same 'id'
     *   (AUTO-010: see selectAudioTrack()'s matching note).
     */
    public static function selectSubtitleTrack(
        array $tracks,
        ?string $audioTag,
        array $preferences,
        SubtitleMode $mode,
        array $accessibility = []
    ): ?string {
        self::requireUniqueIdentifiers($tracks);

        if ($mode === SubtitleMode::Off) {
            return null;
        }

        $positions = self::canonicalPositions($tracks, 'subtitle');

        $forcedOnly = static function () use ($tracks, $audioTag, $positions): ?string {
            if ($audioTag === null) {
                return null;
            }
            $audio = self::canonicalise($audioTag);
            if ($audio->kind !== TagKind::Ordinary || in_array($audio->language, ['und', 'mul', 'zxx'], true)) {
                return null;
            }
            $candidates = [];
            foreach ($tracks as $track) {
                if (self::trackRoleRank($track, 'subtitle') !== self::SUBTITLE_ROLE_RANK['forced']) {
                    continue;
                }
                $match = self::matchTags($audioTag, $track['tag']);
                if ($match->level->rank() <= MatchLevel::Related->rank()) {
                    $candidates[] = ['track' => $track, 'match' => $match];
                }
            }
            if ($candidates === []) {
                return null;
            }
            usort($candidates, static function (array $x, array $y) use ($positions): int {
                $t1 = $x['track'];
                $t2 = $y['track'];
                return ($x['match']->level->rank() <=> $y['match']->level->rank())
                    ?: ($x['match']->distance <=> $y['match']->distance)
                    ?: ((empty($t1['default']) ? 1 : 0) <=> (empty($t2['default']) ? 1 : 0))
                    ?: ($positions[$t1['id']] <=> $positions[$t2['id']])
                    ?: self::compareIdentifiers($t1['id'], $t2['id']);
            });
            return $candidates[0]['track']['id'];
        };

        $always = static function () use ($tracks, $preferences, $accessibility, $positions): ?string {
            // Only tracks PLACED as full (no role) or SDH are ever chosen
            // by "always" - a track placed as forced, commentary or other
            // (including any role this file does not recognise, which
            // counts as "other") is excluded, whatever else is in its
            // role list.
            $ok = array_values(array_filter(
                $tracks,
                // 0 = full subtitles (no recognised role at all) and
                // self::SUBTITLE_ROLE_RANK['sdh'] (1) are the only two
                // placements "always" ever offers.
                static fn (array $t): bool => in_array(self::trackRoleRank($t, 'subtitle'), [0, self::SUBTITLE_ROLE_RANK['sdh']], true)
            ));
            $rolePriority = static function (array $track) use ($accessibility): int {
                $isSdh = self::trackRoleRank($track, 'subtitle') === self::SUBTITLE_ROLE_RANK['sdh'];
                if (!empty($accessibility['captions'])) {
                    return $isSdh ? 0 : 1;
                }
                return $isSdh ? 1 : 0;
            };
            foreach ($preferences as $preference) {
                $candidates = [];
                foreach ($ok as $track) {
                    $match = self::matchTags($preference, $track['tag']);
                    if ($match->level->rank() <= MatchLevel::Related->rank()) {
                        $candidates[] = ['track' => $track, 'match' => $match];
                    }
                }
                if ($candidates === []) {
                    continue;
                }
                usort($candidates, static function (array $x, array $y) use ($rolePriority, $positions): int {
                    $t1 = $x['track'];
                    $t2 = $y['track'];
                    return ($rolePriority($t1) <=> $rolePriority($t2))
                        ?: ($x['match']->level->rank() <=> $y['match']->level->rank())
                        ?: ($x['match']->distance <=> $y['match']->distance)
                        ?: ((empty($t1['default']) ? 1 : 0) <=> (empty($t2['default']) ? 1 : 0))
                        ?: ($positions[$t1['id']] <=> $positions[$t2['id']])
                        ?: self::compareIdentifiers($t1['id'], $t2['id']);
                });
                return $candidates[0]['track']['id'];
            }
            $defaults = array_values(array_filter($ok, static fn (array $t): bool => !empty($t['default'])));
            if ($defaults === []) {
                return null;
            }
            usort(
                $defaults,
                static fn (array $t1, array $t2): int => ($positions[$t1['id']] <=> $positions[$t2['id']])
                    ?: self::compareIdentifiers($t1['id'], $t2['id'])
            );
            return $defaults[0]['id'];
        };

        if ($mode === SubtitleMode::ForcedOnly) {
            return $forcedOnly();
        }
        if ($mode === SubtitleMode::Always) {
            return $always();
        }

        // Automatic.
        if ($preferences === []) {
            return $forcedOnly();
        }
        if ($audioTag !== null) {
            foreach ($preferences as $preference) {
                if (self::matchTags($preference, $audioTag)->level->rank() <= MatchLevel::Related->rank()) {
                    return $forcedOnly();
                }
            }
        }
        return $always();
    }

    // ------------------------------------------------------------------
    // Internal: tag grammar (LANG-001's well-formedness check)
    // ------------------------------------------------------------------

    /**
     * Parses a trimmed string against RFC 5646's grammar (LANG-001 step 3):
     * language, optional extlang (at most one), optional script, optional
     * region, variants, extensions, private use - or a tag that is nothing
     * but `x-...`. Returns null for anything that does not fit, including
     * a repeated variant or a repeated extension letter (both explicitly
     * malformed per the policy text) and a four-to-eight-letter primary
     * language that is not actually a registered subtag (the grammar
     * allows the shape; the registry decides whether it exists today).
     *
     * This function only checks GRAMMAR and registration of the primary
     * language subtag at length >= 4 (per LANG-001 step 3's own carve-out).
     * It does not check that every subtag is registered - see
     * canonicalise()'s doc comment for why that is a separate, non-fatal
     * concern.
     *
     * @return array{
     *     language: string|null,
     *     extlang: string|null,
     *     script: string|null,
     *     region: string|null,
     *     variants: list<string>,
     *     extensions: list<list<string>>,
     *     privateUse: list<string>
     * }|null
     */
    private static function parseWellFormed(string $s): ?array
    {
        $parts = explode('-', $s);
        foreach ($parts as $part) {
            // Anchored with \z, the ABSOLUTE end of the string, not with a
            // bare $ - PCRE's $ also matches just before a single trailing
            // newline, so "foo\n" would wrongly pass a check written as
            // /^[A-Za-z0-9]{1,8}$/, and every part of "en-x-foo\n-bar"
            // would then look individually well-formed even though the
            // line break is really part of the value (LANG-001 step 1
            // only trims specific characters from the very ends of the
            // WHOLE string, and a line break in the middle is trimmed
            // nowhere at all).
            if (!preg_match('/\A[A-Za-z0-9]{1,8}\z/', $part)) {
                return null;
            }
        }
        $lowered = array_map('strtolower', $parts);
        $count = count($lowered);
        $result = [
            'language' => null,
            'extlang' => null,
            'script' => null,
            'region' => null,
            'variants' => [],
            'extensions' => [],
            'privateUse' => [],
        ];

        if ($lowered[0] === 'x') {
            if ($count < 2) {
                return null;
            }
            $result['privateUse'] = array_slice($lowered, 1);
            return $result;
        }

        if (!self::isAsciiAlpha($lowered[0]) || strlen($lowered[0]) < 2 || strlen($lowered[0]) > 8) {
            return null;
        }
        if (strlen($lowered[0]) >= 4 && !in_array($lowered[0], self::data()['languages'], true)) {
            // LANG-001 step 3: a 4-8 letter primary language is only
            // accepted if the registry actually lists it.
            return null;
        }
        $result['language'] = $lowered[0];
        $i = 1;

        if (strlen($lowered[0]) <= 3) {
            $extlangCount = 0;
            while ($i < $count && strlen($lowered[$i]) === 3 && self::isAsciiAlpha($lowered[$i])) {
                $extlangCount++;
                if ($extlangCount > 1) {
                    // "At most one" extlang - a second one is malformed.
                    return null;
                }
                $result['extlang'] = $lowered[$i];
                $i++;
            }
        }

        if ($i < $count && strlen($lowered[$i]) === 4 && self::isAsciiAlpha($lowered[$i])) {
            $result['script'] = ucfirst($lowered[$i]);
            $i++;
        }

        if ($i < $count
            && ((strlen($lowered[$i]) === 2 && self::isAsciiAlpha($lowered[$i]))
                || (strlen($lowered[$i]) === 3 && self::isAsciiDigit($lowered[$i])))
        ) {
            $result['region'] = strtoupper($lowered[$i]);
            $i++;
        }

        // Duplicate check uses a KEYED array (a set: $seenVariants[$v] =
        // true) rather than in_array() against the growing $variants list.
        // in_array() on a plain list is a linear scan, so checking EVERY
        // new variant against every variant already collected is quadratic
        // in the number of variants - a single crafted tag with 20,000
        // variant subtags took several seconds to canonicalise before this
        // fix (a real cost on a server handling untrusted input), and well
        // under a tenth of a second after it, because isset() on a keyed
        // array is O(1) regardless of how many keys are already in it.
        $seenVariants = [];
        while ($i < $count
            && ((strlen($lowered[$i]) >= 5 && strlen($lowered[$i]) <= 8)
                || (strlen($lowered[$i]) === 4 && self::isAsciiDigit($lowered[$i][0])))
        ) {
            $variant = $lowered[$i];
            if (isset($seenVariants[$variant])) {
                // The same variant twice is malformed (LANG-001 step 3).
                return null;
            }
            $seenVariants[$variant] = true;
            $result['variants'][] = $variant;
            $i++;
        }

        $seenSingletons = [];
        while ($i < $count && strlen($lowered[$i]) === 1 && $lowered[$i] !== 'x') {
            $singleton = $lowered[$i];
            if (isset($seenSingletons[$singleton])) {
                // The same extension letter twice is malformed.
                return null;
            }
            $seenSingletons[$singleton] = true;
            $i++;
            $subtags = [];
            while ($i < $count && strlen($lowered[$i]) >= 2 && strlen($lowered[$i]) <= 8) {
                $subtags[] = $lowered[$i];
                $i++;
            }
            if ($subtags === []) {
                // A singleton with nothing after it is malformed.
                return null;
            }
            $result['extensions'][] = array_merge([$singleton], $subtags);
        }

        if ($i < $count && $lowered[$i] === 'x') {
            $i++;
            $privateUse = [];
            while ($i < $count) {
                $privateUse[] = $lowered[$i];
                $i++;
            }
            if ($privateUse === []) {
                // A trailing `x` with nothing after it is malformed.
                return null;
            }
            $result['privateUse'] = $privateUse;
        }

        if ($i !== $count) {
            // Something was left over that did not fit any of the shapes
            // above, in this position.
            return null;
        }
        return $result;
    }

    /** Rebuilds the hyphen-joined tag text from parseWellFormed()'s parts,
     * in RFC 5646's fixed subtag order. */
    private static function joinTag(array $parsed): string
    {
        $out = [];
        if ($parsed['language'] !== null) {
            $out[] = $parsed['language'];
        }
        if (!empty($parsed['extlang'])) {
            $out[] = $parsed['extlang'];
        }
        if ($parsed['script'] !== null) {
            $out[] = $parsed['script'];
        }
        if ($parsed['region'] !== null) {
            $out[] = $parsed['region'];
        }
        foreach ($parsed['variants'] as $variant) {
            $out[] = $variant;
        }
        foreach ($parsed['extensions'] as $extension) {
            foreach ($extension as $piece) {
                $out[] = $piece;
            }
        }
        if (!empty($parsed['privateUse'])) {
            $out[] = 'x';
            foreach ($parsed['privateUse'] as $piece) {
                $out[] = $piece;
            }
        }
        return implode('-', $out);
    }

    /**
     * True when $s is one or more ASCII letters and nothing else.
     *
     * Deliberately not ctype_alpha(): the ctype functions live in an
     * extension that a PHP build can be compiled without, and this file's
     * whole point is to need no extension at all (see the file's top doc
     * comment). A plain regular expression, anchored at both true ends of
     * the string (\A and \z, not ^ and a bare $ - see parseWellFormed()'s
     * comment on why that distinction matters), needs nothing but the PCRE
     * engine PHP itself is built on.
     */
    private static function isAsciiAlpha(string $s): bool
    {
        return preg_match('/\A[A-Za-z]+\z/', $s) === 1;
    }

    /** True when $s is one or more ASCII digits and nothing else. See
     * isAsciiAlpha()'s comment for why this is a regular expression and
     * not ctype_digit(). */
    private static function isAsciiDigit(string $s): bool
    {
        return preg_match('/\A[0-9]+\z/', $s) === 1;
    }

    /** @param list<array{0: string, 1: string}> $ranges Inclusive [first, last] pairs, ASCII order. */
    private static function inAnyRange(string $value, array $ranges): bool
    {
        foreach ($ranges as [$first, $last]) {
            if ($value >= $first && $value <= $last) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Internal: the shared Part A ordering engine
    // ------------------------------------------------------------------

    /**
     * The engine behind both sortCanonicalOrder() and sortTrackOrder():
     * LANG-010's original-group promotion, LANG-020's primary-language
     * order, LANG-021 to LANG-023's specificity ladder, LANG-025's fixed
     * placement of the special codes, LANG-026's malformed-values-last,
     * and LANG-027's "ties keep their order" (via PHP's stable usort() -
     * see the file's top doc comment).
     *
     * $roleRank lets a caller add a role-based tie-break BEFORE
     * specificity (TRACK-050) without this function needing to know
     * anything about roles itself; sortCanonicalOrder() passes a function
     * that always returns 0, which drops the tie-break out entirely.
     *
     * @param list<array<string, mixed>> $items Each must have a 'tag'
     *   string key; 'original' (bool) is optional and defaults to false.
     * @param callable(array<string, mixed>): int $roleRank
     * @return list<array<string, mixed>>
     */
    private static function canonicalCompareSort(array $items, callable $roleRank): array
    {
        $decorated = [];
        foreach ($items as $item) {
            $tag = self::canonicalise($item['tag']);
            $decorated[] = [
                'item' => $item,
                'tag' => $tag,
                'bucket' => self::bucket($tag),
                'groupKey' => self::groupKey($tag),
                'original' => !empty($item['original']),
            ];
        }

        // LANG-010: every item whose group has at least one original-
        // flagged member gets its WHOLE group promoted, not just the
        // flagged item itself.
        $originalGroups = [];
        foreach ($decorated as $entry) {
            if ($entry['original'] && $entry['bucket'] !== 8) {
                $originalGroups[self::groupKeyString($entry['groupKey'])] = true;
            }
        }

        usort($decorated, static function (array $a, array $b) use ($originalGroups, $roleRank): int {
            if ($a['bucket'] === 8 && $b['bucket'] === 8) {
                // LANG-026: both malformed - keep their found order (a
                // genuine tie; PHP's stable sort handles the rest).
                return 0;
            }
            if ($a['bucket'] === 8) {
                return 1;
            }
            if ($b['bucket'] === 8) {
                return -1;
            }

            $aPromoted = isset($originalGroups[self::groupKeyString($a['groupKey'])]) ? 0 : 1;
            $bPromoted = isset($originalGroups[self::groupKeyString($b['groupKey'])]) ? 0 : 1;
            if ($aPromoted !== $bPromoted) {
                return $aPromoted <=> $bPromoted;
            }

            $groupComparison = self::compareGroupKey($a['groupKey'], $b['groupKey']);
            if ($groupComparison !== 0) {
                return $groupComparison;
            }

            // Within a promoted group, the item(s) actually marked
            // original lead; within a non-promoted group this is always a
            // tie (both sides are 1), which is correct - there is nothing
            // to promote.
            $aOriginalRank = ($aPromoted === 0 && $a['original']) ? 0 : 1;
            $bOriginalRank = ($bPromoted === 0 && $b['original']) ? 0 : 1;
            if ($aOriginalRank !== $bOriginalRank) {
                return $aOriginalRank <=> $bOriginalRank;
            }

            $aRole = $roleRank($a['item']);
            $bRole = $roleRank($b['item']);
            if ($aRole !== $bRole) {
                return $aRole <=> $bRole;
            }

            return self::compareSpecificityKey(self::specificityKey($a['tag']), self::specificityKey($b['tag']));
        });

        return array_map(static fn (array $entry): array => $entry['item'], $decorated);
    }

    /**
     * Computes each track's position in canonical order, keyed by 'id',
     * INDEPENDENTLY of the order $tracks arrives in - the tie-break
     * AUTO-010 requires for automatic selection.
     *
     * The trick: $tracks is sorted by 'id' BEFORE being run through the
     * canonical-order engine. Two tracks that are otherwise identical
     * (same tag, same role) are a genuine tie for canonicalCompareSort(),
     * which (per LANG-027) then falls back to keeping the order they
     * arrived in - so pre-sorting by id turns "whatever order the caller
     * happened to give them in" into "the lower id wins", which is
     * exactly the tie-break AUTO-010 asks for and makes the result of
     * this function the same no matter what order $tracks was built in.
     *
     * @param list<array{id: string, tag: string, roles?: list<string>}> $tracks
     * @param 'audio'|'subtitle' $roleTableType Which TRACK-050 role table
     *   to use for the tie-break ordering.
     * @return array<string, int>
     */
    private static function canonicalPositions(array $tracks, string $roleTableType): array
    {
        $sorted = $tracks;
        usort($sorted, static fn (array $a, array $b): int => self::compareIdentifiers($a['id'], $b['id']));
        $ordered = self::canonicalCompareSort(
            $sorted,
            static fn (array $item): int => self::trackRoleRank($item, $roleTableType)
        );
        $positions = [];
        foreach ($ordered as $index => $track) {
            $positions[$track['id']] = $index;
        }
        return $positions;
    }

    /**
     * AUTO-010's identifier order, used everywhere a tie is broken by a
     * track's own 'id' rather than its position in a list: identifiers
     * made of ASCII digits only sort FIRST, as numbers ("9" before "10" -
     * ten tracks numbered the ordinary way are not "1, 10, 2, 3, ..."),
     * and when two digits-only identifiers are equal as numbers, as plain
     * byte strings ("01" before "1" - same value, but not the same text,
     * and text order is the only thing left to decide with). Every other
     * identifier (anything with so much as one non-digit character in it)
     * sorts after all of those, in plain byte-string order.
     *
     * Deliberately NOT PHP's `<=>` or `<` on the raw strings: PHP compares
     * two strings as numbers whenever BOTH look like one, which sounds
     * like what is wanted here but is not quite it - `"01" <=> "1"` comes
     * out 0 (equal), which would let a stable sort decide their order by
     * accident, and PHP also treats forms like "1e3" as numeric, which
     * this policy's "ASCII digits only" does not mean to include.
     */
    private static function compareIdentifiers(string $a, string $b): int
    {
        $aIsNumeric = self::isAsciiDigit($a);
        $bIsNumeric = self::isAsciiDigit($b);
        if ($aIsNumeric !== $bIsNumeric) {
            return $aIsNumeric ? -1 : 1;
        }
        if ($aIsNumeric) {
            return self::compareDigitStringsNumerically($a, $b) ?: strcmp($a, $b);
        }
        return strcmp($a, $b);
    }

    /**
     * Compares two strings of ASCII digits AS NUMBERS, without parsing
     * them into a PHP int first (a track identifier is caller-supplied
     * text with no promised limit on how many digits it has, and this
     * comparison must stay correct for a number of any length rather than
     * silently misbehaving past PHP_INT_MAX). Leading zeros are stripped
     * from a working copy of each string first (an empty result, meaning
     * the whole string was zeros, is treated as "0"); a longer remaining
     * string is always the bigger number, since neither string has a
     * leading zero left to pad it out; equal length then compares as
     * plain text, which for two same-length, leading-zero-free digit
     * strings gives the same order as comparing them as numbers would.
     * Returns 0 when the two represent the same number, however
     * differently they are written ("01" and "1") - compareIdentifiers()
     * is what then breaks that tie by the original text.
     */
    private static function compareDigitStringsNumerically(string $a, string $b): int
    {
        $strippedA = ltrim($a, '0');
        $strippedB = ltrim($b, '0');
        if ($strippedA === '') {
            $strippedA = '0';
        }
        if ($strippedB === '') {
            $strippedB = '0';
        }
        return (strlen($strippedA) <=> strlen($strippedB)) ?: strcmp($strippedA, $strippedB);
    }

    /**
     * AUTO-010: "identifiers must be unique. Given two tracks with the
     * same identifier, the selection function refuses with an error
     * ... never guess." Called at the very start of selectAudioTrack() and
     * selectSubtitleTrack(), before anything else about the tracks is
     * looked at, so a caller finds out immediately rather than getting a
     * plausible-looking but silently arbitrary answer - with a duplicate
     * id, the position map built by canonicalPositions() could only ever
     * keep one of the two tracks' positions anyway, overwriting the
     * other's under the same key.
     *
     * @param list<array{id: string}> $tracks
     * @throws \InvalidArgumentException if any two tracks share an 'id'.
     */
    private static function requireUniqueIdentifiers(array $tracks): void
    {
        $seen = [];
        foreach ($tracks as $track) {
            if (isset($seen[$track['id']])) {
                throw new \InvalidArgumentException(
                    "MWBM-MEDIA-LANG: two tracks share the identifier '{$track['id']}'. "
                    . 'AUTO-010 requires every track identifier to be unique so a tie can be '
                    . 'broken safely - refusing rather than guessing which one was meant.'
                );
            }
            $seen[$track['id']] = true;
        }
    }

    /**
     * TRACK-050's role rank for one track: 0 if it has no role from the
     * relevant table (the main/full track), otherwise the LATEST-sorting
     * role it carries (a track with several roles is placed by the one
     * that sorts last - see TRACK-050's own closing sentence). A role
     * string this file does not recognise is treated as "anything else"
     * (rank 4), never as an error.
     *
     * @param array{roles?: list<string>} $item
     */
    private static function trackRoleRank(array $item, string $type): int
    {
        $table = match ($type) {
            'audio' => self::AUDIO_ROLE_RANK,
            'subtitle' => self::SUBTITLE_ROLE_RANK,
            default => null,
        };
        if ($table === null) {
            return 0;
        }
        $max = 0;
        foreach ($item['roles'] ?? [] as $role) {
            $rank = $table[$role] ?? 4;
            if ($rank > $max) {
                $max = $rank;
            }
        }
        return $max;
    }

    /**
     * The within-group ordering for sortPresentation() (UI-045): role
     * (an accessibility role the user asked for moves to the front),
     * then an exact preference match (only an EXACT tag match is
     * promoted - UI-045 point 2 is explicit that a general match, such as
     * `en` for a preference of `en-GB`, is NOT promoted), then the
     * original flag, then LANG-021 to LANG-023 specificity. A genuine tie
     * falls through to PHP's stable sort, preserving input order
     * (UI-045 point 5).
     *
     * Malformed entries are the one exception to all of that: LANG-026's
     * "kept, flagged and put last, in the order it was found" applies in a
     * menu too, not only in stored order, so a malformed item's role,
     * original flag and any preference are never even looked at - it
     * always compares as a tie against another malformed item, which (via
     * PHP's stable sort, same as everywhere else in this file) leaves the
     * whole malformed group in whatever order it was found. Every
     * malformed item lands in the SAME group (they all share group key
     * (8, '') - see groupKey()), so this function is never asked to
     * compare a malformed item against an ordinary one.
     *
     * @param array{item: array<string, mixed>, tag: LanguageTag} $a
     * @param array{item: array<string, mixed>, tag: LanguageTag} $b
     * @param list<string> $preferenceTags Canonical preference tags, in
     *   priority order, malformed ones already dropped.
     * @param array{audio_description?: bool, captions?: bool} $accessibility
     */
    private static function compareWithinPresentationGroup(
        array $a,
        array $b,
        array $preferenceTags,
        array $accessibility
    ): int {
        if ($a['tag']->kind === TagKind::Malformed && $b['tag']->kind === TagKind::Malformed) {
            return 0;
        }

        $aType = $a['item']['type'] ?? '';
        $bType = $b['item']['type'] ?? '';

        $aRank = self::trackRoleRank($a['item'], $aType);
        $bRank = self::trackRoleRank($b['item'], $bType);
        // AUTO-040 / UI-045 point 1: a track jumps to the front of its
        // group only when the accessibility role the user asked for is
        // the role it is actually PLACED by (TRACK-050: the latest of its
        // roles) - checked by comparing the already-computed rank to that
        // role's own rank, not by asking whether the role is anywhere in
        // the list. A subtitle carrying both "forced" and "sdh" is placed
        // as forced (forced sorts later than sdh in TRACK-050's subtitle
        // order) and is NOT moved by a captions preference, even though
        // "sdh" is one of its roles.
        if (!empty($accessibility['audio_description']) && $aType === 'audio' && $aRank === self::AUDIO_ROLE_RANK['audio_description']) {
            $aRank = -1;
        }
        if (!empty($accessibility['audio_description']) && $bType === 'audio' && $bRank === self::AUDIO_ROLE_RANK['audio_description']) {
            $bRank = -1;
        }
        if (!empty($accessibility['captions']) && $aType === 'subtitle' && $aRank === self::SUBTITLE_ROLE_RANK['sdh']) {
            $aRank = -1;
        }
        if (!empty($accessibility['captions']) && $bType === 'subtitle' && $bRank === self::SUBTITLE_ROLE_RANK['sdh']) {
            $bRank = -1;
        }
        if ($aRank !== $bRank) {
            return $aRank <=> $bRank;
        }

        $aPreferenceIndex = array_search($a['tag']->tag, $preferenceTags, true);
        $bPreferenceIndex = array_search($b['tag']->tag, $preferenceTags, true);
        $aPreferenceIndex = $aPreferenceIndex === false ? count($preferenceTags) : $aPreferenceIndex;
        $bPreferenceIndex = $bPreferenceIndex === false ? count($preferenceTags) : $bPreferenceIndex;
        if ($aPreferenceIndex !== $bPreferenceIndex) {
            return $aPreferenceIndex <=> $bPreferenceIndex;
        }

        $aOriginal = !empty($a['item']['original']) ? 0 : 1;
        $bOriginal = !empty($b['item']['original']) ? 0 : 1;
        if ($aOriginal !== $bOriginal) {
            return $aOriginal <=> $bOriginal;
        }

        return self::compareSpecificityKey(self::specificityKey($a['tag']), self::specificityKey($b['tag']));
    }

    // ------------------------------------------------------------------
    // Internal: grouping and specificity keys shared by the sorters above
    // ------------------------------------------------------------------

    /**
     * LANG-025's fixed bucket number for a tag: 0 for an ordinary
     * language, 1-5 for mul/mis/(qaa-qtz)/und/zxx in that order, 6 for a
     * grandfathered tag with no replacement, 7 for a private-use-only tag,
     * 8 for malformed. Lower sorts first.
     */
    private static function bucket(LanguageTag $tag): int
    {
        return match ($tag->kind) {
            TagKind::Malformed => 8,
            TagKind::PrivateUse => 7,
            TagKind::Grandfathered => 6,
            TagKind::Ordinary => self::SPECIAL_LANGUAGE_BUCKET[$tag->language]
                ?? (strlen((string) $tag->language) === 3 && $tag->language >= 'qaa' && $tag->language <= 'qtz'
                    ? 3
                    : 0),
        };
    }

    /**
     * The "which group does this tag belong to" key (LANG-020's primary-
     * language grouping, widened to cover the special buckets and the
     * grandfathered/private-use/malformed cases too): [bucket, secondary].
     * For an ordinary or special-code tag, secondary is the primary
     * language subtag itself. For a grandfathered or private-use tag,
     * secondary is the whole tag text, lower-cased - each distinct such
     * tag is its own "group", so LANG-025's "in ASCII order" for those two
     * buckets falls out of ordinary group-key comparison.
     *
     * @return array{0: int, 1: string}
     */
    private static function groupKey(LanguageTag $tag): array
    {
        $bucket = self::bucket($tag);
        if ($bucket === 6 || $bucket === 7) {
            return [$bucket, strtolower($tag->tag)];
        }
        if ($bucket === 8) {
            return [8, ''];
        }
        return [$bucket, $tag->language ?? ''];
    }

    /** @param array{0: int, 1: string} $groupKey */
    private static function groupKeyString(array $groupKey): string
    {
        return $groupKey[0] . "\x00" . $groupKey[1];
    }

    /** @param array{0: int, 1: string} $a @param array{0: int, 1: string} $b */
    private static function compareGroupKey(array $a, array $b): int
    {
        return ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]);
    }

    /**
     * The LANG-021 to LANG-023 specificity ladder as a comparable key:
     * [hasExtra, base, script, regionKind, region, rest].
     *
     * Every tag with no unregistered-extlang/variant/extension/private-use
     * subtag sorts before every tag that has one, REGARDLESS of base level
     * - `de-1996` sorts after `de-CH` even though a bare variant is "less
     * specific" than a region in isolation, because LANG-021 rule 5 groups
     * every tag carrying extra subtags together, ordered among themselves
     * by rules 1-4 and then by the extra text - and that is a strictly
     * LATER group than the plain rules-1-4 tags, not interleaved with
     * them. An unregistered extlang counts as one of these "extra"
     * subtags too (LANG-021 rule 5): `zh-abc` (extlang "abc", not
     * registered, so it stays on the tag - see canonicalise()) sorts after
     * both `zh` and `zh-Hant`, and its extlang text is the FIRST part of
     * "rest", ahead of any variant or extension, matching where it sits in
     * the tag itself (right after the language, before script or region).
     * $base is 1 (bare) / 2 (script only) / 3 (region only) /
     * 4 (script + region). $regionKind is 0 (no region) /
     * 1 (two-letter country/territory) / 2 (three-digit UN M.49 area) -
     * LANG-023's "countries before areas".
     *
     * Not meaningful for a grandfathered, private-use or malformed tag
     * (those never reach this function through anything but a tie on
     * group key, where being compared at all only happens for two
     * DIFFERENT grandfathered/private-use tags, which already differ by
     * their group key's secondary text and never reach here) - returns a
     * constant, always-equal key for those, purely so the type stays
     * uniform.
     *
     * @return array{0: int, 1: int, 2: string, 3: int, 4: string, 5: string}
     */
    private static function specificityKey(LanguageTag $tag): array
    {
        if ($tag->kind !== TagKind::Ordinary) {
            return [0, 0, '', 0, '', ''];
        }
        $hasExtra = ($tag->extlang !== null || $tag->variants !== [] || $tag->extensions !== [] || $tag->privateUse !== [])
            ? 1
            : 0;
        $base = 1 + ($tag->script !== null ? 1 : 0) + ($tag->region !== null ? 2 : 0);
        $regionKind = $tag->region === null ? 0 : (self::isAsciiAlpha($tag->region) ? 1 : 2);

        $restParts = [];
        if ($tag->extlang !== null) {
            $restParts[] = $tag->extlang;
        }
        foreach ($tag->variants as $variant) {
            $restParts[] = $variant;
        }
        foreach ($tag->extensions as $extension) {
            $restParts[] = implode('-', $extension);
        }
        if ($tag->privateUse !== []) {
            $restParts[] = implode('-', array_merge(['x'], $tag->privateUse));
        }

        return [$hasExtra, $base, $tag->script ?? '', $regionKind, $tag->region ?? '', implode('-', $restParts)];
    }

    /**
     * @param array{0: int, 1: int, 2: string, 3: int, 4: string, 5: string} $a
     * @param array{0: int, 1: int, 2: string, 3: int, 4: string, 5: string} $b
     */
    private static function compareSpecificityKey(array $a, array $b): int
    {
        return ($a[0] <=> $b[0])
            ?: ($a[1] <=> $b[1])
            ?: strcmp($a[2], $b[2])
            ?: ($a[3] <=> $b[3])
            ?: strcmp($a[4], $b[4])
            ?: strcmp($a[5], $b[5]);
    }
}
