<?php

declare(strict_types=1);

/**
 * iHymns — the ONE door into the shared language policy (MWBM-MEDIA-LANG) (#2137)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Every MWBM app follows one written rule book for language codes such as
 * `en`, `pt-BR` (Brazilian Portuguese) or `zh-Hant` (Chinese in Traditional
 * characters): how to tidy them, how to read old three-letter codes such as
 * `eng`, what order to store them in, and what order to show them in. The
 * rule book and its PHP code are shared, not written separately per app.
 * This file is the only place in iHymns that loads that shared code. Every
 * other iHymns file asks THIS file, so there is exactly one set of language
 * rules in the site.
 *
 * WHERE THE SHARED CODE LIVES, AND WHY THERE
 * ------------------------------------------
 * `includes/vendor/media-language/` holds exact copies of
 * `MediaLanguagePolicy.php` and its reference data file (drawn from the IANA
 * language registry and the ISO 639-2 list). It is found from this file's own
 * folder (`__DIR__`), so it keeps working when the docroot folder is renamed
 * per channel (`public_html_dev`, `public_html_beta` — rule #41). The folder
 * is called `vendor` because that is how this repository marks code it did
 * not write: the source checks that walk the tree (the orphan inventory, the
 * PHP source-units check and others) skip folders of that name, so the
 * shared code's words are never mistaken for iHymns' own callers or rules.
 *
 * Why here and not in `appWeb/private_html/lib/`, beside the PDF engine,
 * where it was first put: the deploy never uploads `private_html` (the step
 * needs the `SFTP_PRIVATE_PATH` secret, which is not set — the deploy logs
 * say "skipping private_html deployment", #2138). On the server these files
 * would have been missing, and every language save would have been refused.
 * `includes/` is uploaded with every deploy, as part of each channel's own
 * docroot (so alpha, beta and main each carry the version their code was
 * tested with), and the site's `.htaccess` forbids web access to all of it
 * (`RewriteRule ^includes/ - [F,L]`), so the files still cannot be fetched by
 * a browser. `tests/php/test-media-language-deploy-layout.php` copies the
 * folder the way the deploy does and loads the rules from the copy.
 *
 * The copies are checked byte for byte against the master in
 * MWBMPartners/MeedyaSuite-core by `tools/media-lang/check_copies.py` in CI.
 * NEVER edit them here — change the master and take the new version.
 *
 * WHAT HAPPENS IF THE SHARED CODE IS MISSING ON A SERVER
 * ------------------------------------------------------
 * The folder is deployed by its own step in `.github/workflows/deploy.yml`.
 * If that step ever did not run on a server, two different things happen,
 * on purpose:
 *
 *   - READING (showing names, ordering a list) quietly falls back to the
 *     order and text the database gave, and logs the problem once. A song
 *     page must never go blank because a list could not be sorted.
 *   - WRITING (saving or importing a language tag) REFUSES with a plain
 *     message, through `mediaLanguageRequire()`. Saving an unchecked tag
 *     would put exactly the kind of data this policy exists to prevent into
 *     the database, where nobody would notice it. A loud refusal is found
 *     and fixed the same day.
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT DO
 * ---------------------------------------
 * - It holds no language NAMES. Names ("Portuguese (Brazil)") come from the
 *   registry tables through `includes/language_names.php`.
 * - It never adds a script or region a tag did not declare (policy LANG-024):
 *   `zh-TW` stays `zh-TW`; it is not "improved" to `zh-Hant-TW`.
 *
 * @see docs/standards/media-language-bcp47-policy.md  the rules (normative)
 * @see appWeb/public_html/includes/vendor/media-language/README.md  the shared code's own notes
 * @see tests/php/test-media-language-conformance.php     the policy's 268 cases, run here
 * @see tests/php/test-media-language-ihymns.php          iHymns' own uses of this file
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

use Mwbm\MediaLanguage\LanguageTag;
use Mwbm\MediaLanguage\Policy;
use Mwbm\MediaLanguage\TagKind;

/**
 * The widest language tag iHymns can store. Every language column in
 * `appWeb/.sql/schema.sql` is `VARCHAR(35)` (#681). A canonical tag longer
 * than this is REFUSED rather than cut short: cutting a tag short turns it
 * into a different tag (`sr-Latn-ME-x-longname` → `sr-Latn-ME-x-long`),
 * which is a silent change of meaning.
 */
const IHYMNS_LANGUAGE_TAG_MAX_LENGTH = 35;

/**
 * The value stored when nobody knows the language (policy LANG-003).
 * `und` is BCP 47's own code for "undetermined". It is stored instead of a
 * guess such as `en`, because a guess looks exactly like a fact and nobody
 * can later tell the two apart (#2132).
 */
const IHYMNS_LANGUAGE_UNKNOWN = 'und';

/**
 * Folder holding the copied shared code and its reference data.
 *
 * ELI5: where the shared language rule book sits on the server.
 */
function mediaLanguageLibraryDir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'media-language';
}

/**
 * Load the shared code and its reference data, once per request.
 *
 * ELI5: "is the rule book here and readable?" — true once it has been
 * loaded; false (and one line in the error log) when it is missing or
 * damaged.
 *
 * DETAIL: `Policy::loadData()` refuses a data file for a different policy
 * or a different data version with an exception rather than giving quietly
 * wrong answers (see its own doc comment); that exception is caught here and
 * turned into `false` so READ paths can degrade. WRITE paths call
 * `mediaLanguageRequire()` instead, which turns the same `false` into a
 * refusal. The answer is remembered for the rest of the request, so a
 * missing file is looked for once, not once per song.
 */
function mediaLanguageReady(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $dir = mediaLanguageLibraryDir();
    try {
        if (!class_exists(Policy::class, false)) {
            $lib = $dir . DIRECTORY_SEPARATOR . 'MediaLanguagePolicy.php';
            if (!is_file($lib)) {
                throw new \RuntimeException("the shared code is missing ({$lib})");
            }
            require_once $lib;
        }
        Policy::loadData($dir . DIRECTORY_SEPARATOR . 'bcp47-language-data-v1.json');
        $ready = true;
    } catch (\Throwable $e) {
        error_log('[media_language] the shared language rules could not be loaded: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * Stop with a plain explanation when the shared rules are not available.
 * Every path that WRITES a language tag calls this first (see the file
 * header for why reading and writing are treated differently).
 *
 * @throws \RuntimeException when the shared code or its data is missing.
 */
function mediaLanguageRequire(): void
{
    if (!mediaLanguageReady()) {
        throw new \RuntimeException(
            'The shared language rules are not installed on this server, so a language code '
            . 'cannot be checked and nothing was saved. Upload the includes/vendor/media-language/ folder '
            . '(see includes/media_language.php).'
        );
    }
}

/**
 * Parse and tidy one value that is ALREADY meant to be a language tag — one
 * a person typed or picked, or one iHymns stored itself (policy LANG-001).
 *
 * ELI5: `EN-gb` becomes `en-GB`; `iw` (an old code for Hebrew) becomes `he`;
 * `English` or `pt_BR` is not a language code at all.
 *
 * Returns null for an empty value. Never guesses: a value that is not a
 * well-formed tag comes back with `->isMalformed()` true and its own text in
 * `->tag`, so the caller can refuse it or report it.
 *
 * Values read from FILES or OTHER SYSTEMS go through
 * `mediaLanguageReadExternal()` instead, which also understands old
 * three-letter codes such as `eng` (policy LANG-002).
 *
 * @throws \RuntimeException when the shared rules are not installed.
 */
function mediaLanguageParse(string $raw): ?LanguageTag
{
    if (trim($raw, " \t\r\n") === '') {
        return null;
    }
    mediaLanguageRequire();
    return Policy::canonicalise($raw);
}

/**
 * The ONE storage check every save path uses for a language tag it was GIVEN
 * as a tag (the song editor, the songbook form, per-line languages, the
 * curator remap tool, iHymns' own JSON export format).
 *
 * ELI5: tidy the tag's letter case and old codes; say "empty" or "not a
 * valid language code" when that is the answer.
 *
 * Returns:
 *   - null   when the value is empty (the caller decides between NULL and
 *            `und` for its column);
 *   - false  when the value is not a well-formed tag, or its tidied form is
 *            longer than the 35 characters every language column holds;
 *   - string the canonical tag to store (`en-GB`, not `EN-gb`).
 *
 * This replaced two older checkers that disagreed with each other — one
 * rejected `en-gb` instead of fixing its case, the other rejected variants
 * such as `de-1996` (#2137). Both now call this.
 *
 * @return string|null|false
 * @throws \RuntimeException when the shared rules are not installed.
 */
function mediaLanguageTagForStorage(string $raw)
{
    $tag = mediaLanguageParse($raw);
    if ($tag === null) {
        return null;
    }
    if ($tag->isMalformed() || strlen($tag->tag) > IHYMNS_LANGUAGE_TAG_MAX_LENGTH) {
        return false;
    }
    return $tag->tag;
}

/**
 * The plain sentence shown to a curator when a language code they typed is
 * refused (policy LANG-001; #2137 — "refused with a plain message in admin
 * forms"). One wording everywhere, so the song editor, the section editor and
 * the songbook form all say the same thing.
 *
 * @param string $raw   What was typed.
 * @param string $where Optional place, e.g. "Verse 2" or "line 3 of Chorus".
 */
function mediaLanguageRefusalMessage(string $raw, string $where = ''): string
{
    $shown = trim($raw);
    if (mb_strlen($shown) > 40) {
        $shown = mb_substr($shown, 0, 40) . '…';
    }
    $place = $where !== '' ? " on {$where}" : '';
    return "“{$shown}”{$place} is not a language code this site can store. "
        . 'Use a code such as en, pt-BR or zh-Hant (language, then optional script and region), '
        . 'at most ' . IHYMNS_LANGUAGE_TAG_MAX_LENGTH . ' characters.';
}

/**
 * Look through the sections of a song an EDITOR is saving for a section
 * language, or a per-line language, that cannot be stored; return the plain
 * refusal message for the first one found, or null when every value is empty
 * or a valid tag.
 *
 * ELI5: "before saving, check every language box the curator filled in, and
 * say which one is wrong."
 *
 * Why the editors check this BEFORE saving, instead of the shared write path
 * dropping a bad value: a curator who typed a language code must be told it
 * was not stored (#2137). The shared write path (`lyric_lines_sync.php`) still
 * tidies letter case on every write, so importers and other callers get the
 * canonical form too; it just is not the place a person can be answered.
 *
 * @param mixed $components The `components` array from the request: each item
 *                          may carry `language` (string) and `languages` (a
 *                          list parallel to its lines).
 * @throws \RuntimeException when the shared rules are not installed.
 */
function mediaLanguageFirstRefusalInComponents(mixed $components): ?string
{
    if (!is_array($components)) {
        return null;
    }
    foreach (array_values($components) as $i => $comp) {
        if (!is_array($comp)) {
            continue;
        }
        $type   = trim((string)($comp['type'] ?? 'section'));
        $number = $comp['number'] ?? null;
        $name   = ucfirst($type !== '' ? $type : 'section')
                . ((is_numeric($number) && (int)$number > 0) ? ' ' . (int)$number : '');
        if ($name === 'Section') {
            $name .= ' ' . ($i + 1);
        }
        $lang = $comp['language'] ?? null;
        if (is_string($lang) && mediaLanguageTagForStorage($lang) === false) {
            return mediaLanguageRefusalMessage($lang, $name);
        }
        if (is_array($comp['languages'] ?? null)) {
            foreach (array_values($comp['languages']) as $li => $lineLang) {
                if (is_string($lineLang) && mediaLanguageTagForStorage($lineLang) === false) {
                    return mediaLanguageRefusalMessage($lineLang, 'line ' . ($li + 1) . ' of ' . $name);
                }
            }
        }
    }
    return null;
}

/**
 * Read a language value that came from a FILE or ANOTHER SYSTEM: a TTML
 * `xml:lang`, an OpenLyrics `lang`, a field in an imported song, the
 * lyrics-ingest API's `language` (policy LANG-002 — "every language value
 * read from a file, a tag or another system MUST go through this reader").
 *
 * ELI5: understands `eng` and `ger` as well as `en` and `de`, and says
 * "I cannot read this" instead of guessing.
 *
 * Returns ['tag' => canonical tag or null, 'unrecognised' => original text or null]:
 *   - an empty value      → both null;
 *   - a readable value    → `tag` set, `unrecognised` null;
 *   - an unreadable value → `tag` null, `unrecognised` = the text as found,
 *     so the caller can store `und` (or NULL) and REPORT the original for a
 *     person to fix (policy COMPAT-040). It is never turned into a guess.
 *
 * A readable value whose tidied form is too long for the column (over 35
 * characters) is treated as unrecognised too: cutting it short would change
 * its meaning.
 *
 * @return array{tag: ?string, unrecognised: ?string}
 * @throws \RuntimeException when the shared rules are not installed.
 */
function mediaLanguageReadExternal(?string $raw): array
{
    $raw = (string)$raw;
    if (trim($raw, " \t\r\n\0") === '') {
        return ['tag' => null, 'unrecognised' => null];
    }
    mediaLanguageRequire();
    $tag = Policy::fromLegacyThreeLetter($raw);
    if ($tag === null || strlen($tag) > IHYMNS_LANGUAGE_TAG_MAX_LENGTH) {
        return ['tag' => null, 'unrecognised' => trim($raw, " \t\r\n\0")];
    }
    return ['tag' => $tag, 'unrecognised' => null];
}

/**
 * Tell a curator that an imported language value could not be read, without
 * stopping the import (policy LANG-002 and COMPAT-040: the value is not
 * guessed at, and the original text is kept somewhere a person will see it).
 *
 * ELI5: "we could not understand the language 'Englsh' on song X, so we did
 * not guess — here it is, for someone to fix."
 *
 * Where it goes: one line in the PHP error log always, and one row in the
 * admin Activity Log (`/manage/activity-log`, action
 * `import.language_unrecognised`) when the activity logger is loaded — the
 * page curators already use to review import problems. The Activity Log has
 * its own per-request cap (`IHYMNS_LOG_PER_REQUEST_CAP`), so a file full of
 * bad values cannot flood it. Never throws: a report that fails must not
 * cost anyone their import.
 *
 * @param string $entityType Activity Log entity type, e.g. 'song' or 'songbook'.
 * @param string $entityId   e.g. the SongId.
 * @param string $field      Where the value was found, e.g. 'language' or 'section 2 language'.
 * @param string $raw        The text exactly as found.
 */
function mediaLanguageReportUnrecognised(string $entityType, string $entityId, string $field, string $raw): void
{
    error_log("[media_language] unreadable language value on {$entityType} {$entityId} ({$field}): " . json_encode($raw, JSON_UNESCAPED_UNICODE));
    try {
        if (function_exists('logActivity')) {
            logActivity('import.language_unrecognised', $entityType, $entityId, [
                'field' => $field,
                'value' => mb_substr($raw, 0, 200),
                'note'  => 'Not guessed: saved as unknown language (und), or with no language where that is allowed. '
                         . 'Fix it in the editor if you know the language.',
            ], 'failure');
        }
    } catch (\Throwable $_e) {
        /* A report must never break the import that triggered it. */
    }
}

/**
 * The language GROUP a tag belongs to, for matching a person's language
 * filter: the primary language for an ordinary tag (`pt-BR` → `pt`), or the
 * whole tag, lower-cased, for a private-use or old "grandfathered" tag
 * (policy UI-020). Returns '' for an empty or malformed value.
 *
 * ELI5: "which language is this, ignoring the country and the alphabet?"
 *
 * Degrades to "everything before the first hyphen, lower-cased" when the
 * shared rules are missing — the filter's historical behaviour — so a
 * missing deploy never empties a filtered list.
 */
function mediaLanguageGroup(string $tag): string
{
    $tag = trim($tag, " \t\r\n");
    if ($tag === '') {
        return '';
    }
    if (!mediaLanguageReady()) {
        return strtolower(explode('-', $tag, 2)[0]);
    }
    $parsed = Policy::canonicalise($tag);
    return match ($parsed->kind) {
        TagKind::Ordinary      => (string)$parsed->language,
        TagKind::Malformed     => '',
        default                => strtolower($parsed->tag),
    };
}

/**
 * The language to store for a song whose language may be missing (#2132).
 *
 * ELI5: "no language given" is stored as `und` ("not known") — never as
 * English, never as a blank. The ONE place that decision is written; every
 * path that creates or saves a song calls it (the song save, the v2 editor's
 * field save and create, the bulk importers, the lyrics-ingest API), so the
 * fallback cannot drift back to a guessed `en` in one of them.
 *
 * It only fills a gap. A value that is present is returned unchanged
 * (trimmed): checking and tidying it is mediaLanguageTagForStorage()'s job,
 * done by the caller first.
 */
function mediaLanguageOrUnknown(?string $tag): string
{
    $tag = trim((string)$tag, " \t\r\n");
    return $tag === '' ? IHYMNS_LANGUAGE_UNKNOWN : $tag;
}

/**
 * Is this tag an ordinary, real language (#2137 review)?
 *
 * ELI5: "does this say which language the words are in?" Yes for `en`,
 * `pt-BR`, `zh-Hant`. No for the special codes — `und` (not known), `mul`
 * (several languages), `zxx` (no language), `mis` (a language with no code) —
 * for the local-use codes `qaa` to `qtz`, for private-use and old
 * "grandfathered" tags (`x-hymnal`, `i-default`), and for anything malformed.
 *
 * Why it exists: those values are all deliberate statements, not mistakes.
 * Code that repairs or guesses a language (the songbook-language backfill
 * card) must leave every one of them alone — `und` in particular records that
 * nobody knows, and replacing it with a guess is exactly what policy LANG-003
 * forbids.
 *
 * Returns false when the shared rules are not installed, so a caller that
 * would CHANGE data on a true answer does nothing rather than guess.
 */
function mediaLanguageIsOrdinaryLanguage(string $tag): bool
{
    $tag = trim($tag, " \t\r\n");
    if ($tag === '' || !mediaLanguageReady()) {
        return false;
    }
    $parsed = Policy::canonicalise($tag);
    if ($parsed->kind !== TagKind::Ordinary || $parsed->language === null) {
        return false;
    }
    $language = $parsed->language;
    if (in_array($language, ['und', 'mul', 'zxx', 'mis'], true)) {
        return false;
    }
    /* qaa–qtz: ISO 639-2's block reserved for local use (BCP 47 section
       2.2.1) — a private agreement, not a language anyone else can name. */
    if (strlen($language) === 3 && strcmp($language, 'qaa') >= 0 && strcmp($language, 'qtz') <= 0) {
        return false;
    }
    return true;
}

/**
 * Put rows into STORED order (policy Part A, LANG-010 to LANG-027): the
 * original language's whole group first, then every other language by its
 * code (`de`, `en`, `es` …), general before specific (`zh`, `zh-Hans`,
 * `zh-TW`, `zh-Hant-TW`), countries before multi-country areas (`es-MX`
 * before `es-419`), the special codes `mul`/`und`/`zxx` after every real
 * language, malformed values last, and ties in the order they came.
 *
 * ELI5: the same order for everybody, on every machine, forever — the order
 * translations are kept and returned in. It is NOT the order a person sees in
 * a menu; that is `mediaLanguageSortForReader()`.
 *
 * Why in PHP and not SQL: `ORDER BY TargetLanguage` sorts letters, which puts
 * `es-419` before `es-AR` and splits no groups at all; the policy's order
 * needs to understand the parts of each tag.
 *
 * @param list<array<string,mixed>> $rows        Any rows; they are returned whole.
 * @param string                    $tagKey      The key holding each row's language tag.
 * @param string|null               $originalKey A key whose truthy value marks the original, or null.
 * @return list<array<string,mixed>> The same rows, reordered. Unchanged when the
 *         shared rules are not installed (reading never fails — see the file header).
 */
function mediaLanguageSortStored(array $rows, string $tagKey, ?string $originalKey = null): array
{
    $rows = array_values($rows);
    if (count($rows) < 2 || !mediaLanguageReady()) {
        return $rows;
    }
    $items = [];
    foreach ($rows as $i => $row) {
        $items[] = [
            'i'        => $i,
            'tag'      => (string)($row[$tagKey] ?? ''),
            'original' => $originalKey !== null && !empty($row[$originalKey]),
        ];
    }
    $out = [];
    foreach (Policy::sortCanonicalOrder($items) as $item) {
        $out[] = $rows[$item['i']];
    }
    return $out;
}

/**
 * Stored order WITHIN groups of consecutive rows (#2137): rows arrive ordered
 * by some outer key (a lyric line's Id, say), and inside each run of rows that
 * share that key they are put into the policy's stored order by language
 * (mediaLanguageSortStored()). The outer order is never changed.
 *
 * ELI5: every line keeps its place; the translations UNDER each line are put
 * in the fixed language order (de, en, es … general before specific).
 *
 * @param list<array<string,mixed>> $rows     Already ordered by $groupKey.
 * @param string                    $groupKey e.g. 'lineId' or 'LineId'.
 * @param string                    $tagKey   e.g. 'targetLanguage'.
 * @return list<array<string,mixed>>
 */
function mediaLanguageSortStoredWithin(array $rows, string $groupKey, string $tagKey): array
{
    $out = [];
    $run = [];
    $runKey = null;
    foreach (array_values($rows) as $row) {
        $key = (string)($row[$groupKey] ?? '');
        if ($run !== [] && $key !== $runKey) {
            array_push($out, ...mediaLanguageSortStored($run, $tagKey));
            $run = [];
        }
        $runKey = $key;
        $run[] = $row;
    }
    if ($run !== []) {
        array_push($out, ...mediaLanguageSortStored($run, $tagKey));
    }
    return $out;
}

/**
 * Compare two language NAMES the way an English reader expects (UI-040 —
 * "compared with the interface language's sorting rules").
 *
 * ELI5: alphabetical, but "Éwé" sorts with the E's, not after Z.
 *
 * Uses the `intl` extension's collator when the server has it (the proper
 * tool: https://www.php.net/manual/en/class.collator.php); otherwise folds
 * accents away and compares without regard to case, which gives the same
 * order for every English name iHymns shows today.
 */
function mediaLanguageCompareNames(string $a, string $b): int
{
    static $collator = false;
    if ($collator === false) {
        $collator = class_exists('Collator') ? new \Collator('en') : null;
    }
    if ($collator instanceof \Collator) {
        $r = $collator->compare($a, $b);
        if (is_int($r)) {
            return $r;
        }
    }
    $fold = static function (string $s): string {
        $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;
        return strtolower(is_string($t) && $t !== '' ? $t : $s);
    };
    return strcmp($fold($a), $fold($b));
}

/**
 * Put rows into the order a PERSON should see them in a list or menu
 * (policy Part B, UI-020 to UI-045): the person's own languages first, in the
 * order they chose; then the original; then every other language
 * alphabetically by its name; the special codes after those; malformed
 * values last. Within one language, the person's exact choice (`pt-BR` over
 * `pt-PT`) comes first, then the original, then general before specific.
 *
 * ELI5: "your languages first, then the original, then A to Z".
 *
 * NEVER store the result: it differs from person to person (policy "Two
 * orders, on purpose"). And nothing here moves an item because it is
 * selected (UI-050).
 *
 * @param list<array<string,mixed>> $rows
 * @param string                    $tagKey       Key holding each row's language tag.
 * @param string|null               $originalKey  Key whose truthy value marks the original.
 * @param list<string>              $preferences  The person's languages, highest priority first.
 * @param callable(string):string   $nameOfLanguage The display name of a PRIMARY language
 *                                                subtag (`de` → "German"), in the interface
 *                                                language — iHymns passes `resolveLanguageName()`.
 * @return list<array<string,mixed>> The same rows, reordered; unchanged when the
 *         shared rules are not installed.
 */
function mediaLanguageSortForReader(
    array $rows,
    string $tagKey,
    ?string $originalKey,
    array $preferences,
    callable $nameOfLanguage
): array {
    $rows = array_values($rows);
    if (count($rows) < 2 || !mediaLanguageReady()) {
        return $rows;
    }
    $items = [];
    foreach ($rows as $i => $row) {
        $items[] = [
            'i'        => $i,
            'tag'      => (string)($row[$tagKey] ?? ''),
            'original' => $originalKey !== null && !empty($row[$originalKey]),
        ];
    }
    /* Names are looked up once per language, not once per comparison. */
    $nameCache = [];
    $nameOf = static function (string $primary) use (&$nameCache, $nameOfLanguage): string {
        return $nameCache[$primary] ??= (string)$nameOfLanguage($primary);
    };
    $groupCompare = static fn (string $a, string $b): int => mediaLanguageCompareNames($nameOf($a), $nameOf($b));
    $out = [];
    foreach (Policy::sortPresentation($items, array_values(array_map('strval', $preferences)), $groupCompare) as $item) {
        $out[] = $rows[$item['i']];
    }
    return $out;
}
