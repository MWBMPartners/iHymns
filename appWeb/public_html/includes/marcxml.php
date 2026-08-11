<?php

declare(strict_types=1);

/**
 * iHymns — MARCXML import/export (pure, DB-free) for publication entities
 * ==============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A library catalogue (WorldCat, the Library of Congress, a denomination's
 * own archive) describes a hymnal using MARCXML — a fixed, decades-old XML
 * dialect where every fact about the book (its title, publisher, ISBN, year,
 * an ARK or OpenLibrary id, …) lives in a numbered "field" (245 = title,
 * 020 = ISBN, and so on). This file knows how to READ that XML into a plain
 * PHP array, MAP those numbered fields onto the columns iHymns actually
 * stores, and do the reverse — turn an iHymns songbook/series/catalogue back
 * into MARCXML a librarian's software can import. It never touches the
 * database itself; it only ever sees the string it was handed and the array
 * it was given.
 *
 * DETAILED / WHY A SEPARATE PURE MODULE, AND WHY COMMIT 1 OF 7
 * ----------------------------------------------------------------------------
 * The Songbook/Catalogue Enhancements epic ships MARCXML support across two
 * commits deliberately kept apart: this one (commit 1, "pure foundations")
 * has NO database calls anywhere in it — parsing, field-mapping and
 * generating are all pure functions over strings and arrays, so they are
 * fully unit-testable with zero fixture setup beyond an XML string. The
 * DB-touching half — matching a generic (non-identifier) `856 $u` against
 * the `tblExternalLinkPatterns` provider registry via a NEW
 * `externalLinkDetectProviderId(db,url)`, and the admin page handlers that
 * call `marcxmlParse()`/`marcxmlMapToEntity()` against a real upload and then
 * WRITE the result — is explicitly deferred to a LATER commit (the epic
 * plan's build sequence, commit 6). Shipping the pure half first, fully
 * tested, means the DB-touching commit is "wire up an existing, already-
 * proven parser" rather than "write and test a parser under time pressure
 * while also touching the database" — the same reasoning
 * `includes/song_similarity.php` (#1216) and `includes/lyric_lines_sync.php`
 * (#1235) both follow: pure core, thin DB shell.
 *
 * XXE-SAFETY (rule: never trust an uploaded XML document)
 * ----------------------------------------------------------------------------
 * `marcxmlParse()` is a NEW attack surface for whatever eventually calls it
 * with an untrusted upload (a `/manage/*` import), so it is built XXE-safe
 * from day one even though nothing feeds it untrusted input yet:
 *   - `LIBXML_NONET` blocks libxml from following ANY external reference
 *     (a network fetch triggered by a malicious `<!ENTITY … SYSTEM "http://
 *     …">`) even in the unlikely event something below mishandles a doctype.
 *   - `LIBXML_NOENT` (entity SUBSTITUTION) is deliberately NEVER passed —
 *     PHP 8's `DOMDocument` does not expand external entities by default,
 *     and asking it to would be the exact XXE hole this parser must never
 *     open (this mirrors `MusicXmlImporter::parseString()`'s identical
 *     posture for uploaded MusicXML, #1096).
 *   - Belt-and-braces on top of both: ANY `<!DOCTYPE` in the raw string is
 *     refused BEFORE it ever reaches libxml (a fast literal scan), and
 *     `$dom->doctype !== null` is checked again AFTER a successful parse —
 *     so a doctype can never survive to influence anything downstream
 *     regardless of what a future libxml/PHP version's defaults do. This is
 *     stricter than "safe" (no substitution happens either way) because a
 *     MARC record legitimately has NO reason to ever carry a DTD, so simply
 *     refusing the shape outright costs nothing and removes an entire class
 *     of "was this libxml build configured correctly?" doubt.
 *
 * NAMESPACE TOLERANCE: real-world MARCXML is exported both with the formal
 * `http://www.loc.gov/MARC21/slim` namespace declared and (rarely, but seen
 * in the wild from older export tools) without any namespace at all. DOM's
 * `->localName` reads the tag name minus any prefix in BOTH cases (when no
 * namespace is declared, `localName` simply equals `nodeName`), so every
 * element lookup in this file keys off `->localName` rather than a
 * namespace-qualified `getElementsByTagNameNS()` call — one code path for
 * either input shape rather than two.
 *
 * SCOPE (Feature 5 + the Feature 7 amendment, epic plan)
 * ----------------------------------------------------------------------------
 * Three publication entities share this ONE mapping engine:
 * `'songbook'` (`tblSongbooks`), `'series'` (`tblSongbookSeries`),
 * `'catalogue'` (`tblCatalogues` — the internal name for the user-facing
 * "Collection", rule #24; never rename the entity kind string). Their
 * column sets deliberately differ (the plan's "Adversarial notes": "series
 * 260$b / catalogue ISBN deliberately absent — model as songbook, rule
 * #24") — `MARCXML_FIELD_MAPS` below encodes exactly which MARC fields have
 * ANYWHERE to land for each kind, so a series import never invents a
 * `Publisher` value the schema has no column for.
 *
 * The 856 (Electronic location) field for `archive.org` URLs deliberately
 * does NOT map to a dedicated column, even on `'songbook'` (which DOES still
 * have a legacy `InternetArchiveUrl` column at the point this commit
 * lands) — that is the Feature 7 amendment to this epic's own plan,
 * decided the SAME DAY as the rest of this plan: "MARCXML (commit 6): `856`
 * archive.org → the internet-archive external-link type (multiple), NOT the
 * column." The DB-touching provider-match half of that decision is commit
 * 6's job; here, an `archive.org` (or any other unrecognised) `856 $u`
 * simply falls into the generic `links` list, indistinguishable at THIS
 * layer from any other not-yet-classified URL — see `marcxmlFieldMap()`'s
 * own doc-comment on the `'856'` entry for the full reasoning, so a reader
 * who only opens this file (not the epic plan) still finds the "why" here.
 *
 * @see .claude/songbook-catalogue-enhancements-plan.md  the epic plan (MARCXML + Feature 7 sections)
 * @see appWeb/public_html/includes/MusicXmlImporter.php  the XXE-safety posture this mirrors (LIBXML_NONET, no LIBXML_NOENT)
 * @see appWeb/public_html/includes/identifier_normalize.php  ihymns_canonical_ark()/ihymns_canonical_openlibrary() — the folds 024/856 identifier subfields run through
 * @see appWeb/public_html/includes/media_identifiers.php      PUBLICATION_IDENTIFIER_TYPES / mediaIdentifierPublicationValidate() — the isbn/issn guard 020/022 run through
 * @see tests/php/test-marcxml.php                             the fixture-driven behavioural lock
 * @link https://www.loc.gov/standards/marcxml/                MARCXML schema (Library of Congress)
 * @link https://www.loc.gov/marc/bibliographic/                MARC 21 Bibliographic field reference (245/250/260/264/020/022/010/035/041/024/856 used below)
 * @link https://www.php.net/manual/en/domdocument.loadxml.php  DOMDocument::loadXML() + LIBXML_* flag reference
 */

/* Direct access blocked so this can't be loaded as an endpoint — the same
   guard every includes/*.php module in this codebase carries. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_identifiers.php';

/**
 * The minimal, structurally-valid 24-character MARC leader this module
 * emits on generate.
 *
 * ELI5: every MARC record starts with a fixed 24-character "leader" that
 * says (among other things) what kind of record this is and how it's
 * encoded. Real cataloguing software computes several of these positions
 * from the record's actual content (record length, base address of data);
 * this module does not — it emits one FIXED, generically-valid leader,
 * because iHymns is never the SOURCE of truth for MARC leader semantics, it
 * is a participant that needs its exports to simply PARSE correctly in any
 * MARC-reading tool, which a placeholder leader satisfies.
 *
 * DETAILED: position-by-position (0-indexed, per the MARC 21 leader spec) —
 * `00000` record length (unset placeholder, correct value unknown until
 * serialisation, which downstream MARC tools recompute anyway), `n` record
 * status (new), `a` type of record (language material — every iHymns
 * songbook/series/catalogue is textual, never notated music or a mixed
 * material record), `m` bibliographic level (monograph — a standalone
 * publication, matching how these three entities are modelled), ` ` type of
 * control (none of the special values apply), `a` character coding scheme
 * (UCS/Unicode — this module always emits UTF-8), `2` `2` indicator count /
 * subfield code count (both fixed at 2 per the MARC21 spec, never variable),
 * `00000` base address of data (placeholder, same reasoning as record
 * length), ` ` encoding level (full level, unknown/not specified), `a`
 * descriptive cataloging form (AACR2/RDA), ` ` multipart resource record
 * level (not specified), `4500` entry map (fixed constant every MARC21
 * record carries). 24 characters exactly (verified in
 * tests/php/test-marcxml.php).
 *
 * @link https://www.loc.gov/marc/bibliographic/bdleader.html  MARC 21 leader position reference
 */
const MARCXML_MINIMAL_LEADER = '00000nam a2200000 a 4500';

/**
 * MARCXML_LANGUAGE_CODE_MAP — common MARC (ISO 639-2/B, "bibliographic")
 * 3-letter language codes mapped to their ISO 639-1 2-letter BCP-47 primary
 * subtag, for folding MARC 041/008 language data into
 * `tblSongbooks.Language` (an IETF BCP 47 free-text column, rule #21's
 * "language tags are free-text VARCHAR" convention applied one grain up).
 *
 * ELI5: MARC records say "eng" for English, "fre" for French, "ger" for
 * German — iHymns stores languages the web-standard way, "en"/"fr"/"de".
 * This is the lookup table between the two.
 *
 * DETAILED / WHY NOT EXHAUSTIVE, AND WHY THAT'S SAFE: the full ISO 639-2
 * registry has ~450 entries (most for languages with no ISO 639-1
 * 2-letter code at all, e.g. many indigenous/liturgical/historical
 * languages). This table covers the languages a hymnal/songbook catalogue
 * realistically encounters — major world languages plus the languages of
 * historically hymn-publishing regions (European, several African, several
 * Asian). `marcxmlLanguageCodeToBcp47()`'s FALLBACK for an unmapped code is
 * the lowercase 3-letter MARC code itself, NEVER a rejection — `Language`
 * is a free-text `VARCHAR(35)` with no FK and only soft dropdown validation
 * (see its schema.sql comment), so an unmapped code degrading to "less
 * pretty than a proper 2-letter tag" is safe; growing this table later is a
 * one-line addition, never a schema change (the rule #20 VARCHAR-not-ENUM
 * posture applied to a PHP lookup table, not a DB column, but the same
 * spirit — a fixed table you can only grow, never one you must migrate).
 *
 * @link https://www.loc.gov/marc/languages/  MARC List for Languages (the ISO 639-2/B codes this table's KEYS are drawn from)
 * @link https://www.iso.org/iso-639-language-code  ISO 639-1 (the VALUES)
 */
const MARCXML_LANGUAGE_CODE_MAP = [
    'eng' => 'en', 'fre' => 'fr', 'fra' => 'fr', 'ger' => 'de', 'deu' => 'de',
    'ita' => 'it', 'spa' => 'es', 'por' => 'pt', 'lat' => 'la', 'dut' => 'nl',
    'nld' => 'nl', 'gre' => 'el', 'ell' => 'el', 'heb' => 'he', 'rus' => 'ru',
    'pol' => 'pl', 'cze' => 'cs', 'ces' => 'cs', 'slo' => 'sk', 'slk' => 'sk',
    'hun' => 'hu', 'rum' => 'ro', 'ron' => 'ro', 'bul' => 'bg', 'srp' => 'sr',
    'hrv' => 'hr', 'slv' => 'sl', 'mac' => 'mk', 'mkd' => 'mk', 'alb' => 'sq',
    'sqi' => 'sq', 'lit' => 'lt', 'lav' => 'lv', 'est' => 'et', 'ice' => 'is',
    'isl' => 'is', 'wel' => 'cy', 'cym' => 'cy', 'gle' => 'ga', 'gla' => 'gd',
    'bre' => 'br', 'cor' => 'kw', 'baq' => 'eu', 'eus' => 'eu', 'cat' => 'ca',
    'glg' => 'gl', 'swe' => 'sv', 'nor' => 'no', 'nob' => 'nb', 'nno' => 'nn',
    'dan' => 'da', 'fin' => 'fi', 'tur' => 'tr', 'ukr' => 'uk', 'bel' => 'be',
    'mlt' => 'mt', 'epo' => 'eo', 'afr' => 'af', 'ara' => 'ar', 'per' => 'fa',
    'fas' => 'fa', 'amh' => 'am', 'swa' => 'sw', 'yor' => 'yo', 'ibo' => 'ig',
    'hau' => 'ha', 'zul' => 'zu', 'xho' => 'xh', 'som' => 'so', 'chi' => 'zh',
    'zho' => 'zh', 'jpn' => 'ja', 'kor' => 'ko', 'vie' => 'vi', 'tha' => 'th',
    'ind' => 'id', 'may' => 'ms', 'msa' => 'ms', 'tgl' => 'tl', 'hin' => 'hi',
    'ben' => 'bn', 'pan' => 'pa', 'guj' => 'gu', 'mar' => 'mr', 'tam' => 'ta',
    'tel' => 'te', 'kan' => 'kn', 'mal' => 'ml', 'sin' => 'si', 'urd' => 'ur',
    'san' => 'sa', 'khm' => 'km', 'lao' => 'lo', 'bur' => 'my', 'mya' => 'my',
    'geo' => 'ka', 'kat' => 'ka', 'arm' => 'hy', 'hye' => 'hy', 'aze' => 'az',
    'kaz' => 'kk', 'uzb' => 'uz', 'mon' => 'mn', 'nep' => 'ne', 'sna' => 'sn',
];

/**
 * Resolve a MARC 041/008 3-letter language code to a BCP-47 primary subtag —
 * PURE.
 *
 * ELI5: "eng" becomes "en"; anything this file's table doesn't know about is
 * handed back lowercased, unchanged, rather than dropped.
 *
 * @param string $code3 A MARC ISO 639-2/B code, any case (e.g. "eng", "ENG").
 * @return string '' for an empty/blank input (nothing to map); the BCP-47
 *                subtag when known; else the lowercased 3-letter code
 *                unchanged (a safe, honest fallback — see the const's
 *                doc-comment on why this never rejects).
 */
function marcxmlLanguageCodeToBcp47(string $code3): string
{
    $code3 = strtolower(trim($code3));
    if ($code3 === '') return '';
    return MARCXML_LANGUAGE_CODE_MAP[$code3] ?? $code3;
}

/**
 * Reverse of marcxmlLanguageCodeToBcp47() — resolve a stored BCP-47 primary
 * subtag to the MARC 041/008 3-letter ISO 639-2 code for EXPORT. PURE.
 * (#1765 review.)
 *
 * ELI5: MARC 041 $a must be a 3-letter code ("eng"), but iHymns stores the
 * 2-letter BCP-47 tag ("en"). This turns "en" back into "eng" for export;
 * an already-3-letter code is handed back unchanged, and anything we can't
 * resolve to a valid 3-letter code returns '' so the caller can OMIT 041
 * rather than emit an invalid 2-letter value.
 *
 * The inverse table is built from MARCXML_LANGUAGE_CODE_MAP with "first
 * 3-letter wins per 2-letter"; that table lists the bibliographic (639-2/B)
 * code first for every 2-way collision (per/fas, chi/zho, geo/kat, arm/hye,
 * bur/mya, may/msa), so the preferred /B code is what comes back.
 *
 * @param string $code A stored language value (BCP-47 tag, or already a
 *                     3-letter code), any case.
 * @return string The 3-letter ISO 639-2 code, or '' when it cannot be
 *                resolved to a valid one.
 */
function marcxmlBcp47ToLanguageCode(string $code): string
{
    $code = strtolower(trim($code));
    if ($code === '') return '';
    /* Already a 3-letter alpha code → honest passthrough (mirrors
       marcxmlLanguageCodeToBcp47()'s unknown-code fallback). */
    if (strlen($code) === 3 && ctype_alpha($code)) return $code;

    static $inverse = null;
    if ($inverse === null) {
        $inverse = [];
        foreach (MARCXML_LANGUAGE_CODE_MAP as $three => $two) {
            if (!isset($inverse[$two])) { $inverse[$two] = $three; }
        }
    }
    return $inverse[$code] ?? '';
}

/* =========================================================================
 * MARCXML_FIELD_MAPS — the field mapping table AS DATA (marcxmlFieldMap()'s
 * backing store). One entry per publication entity kind
 * ('songbook' | 'series' | 'catalogue'), keyed by MARC tag.
 *
 * ELI5: this is the one place that says "MARC field 245 subfield $a becomes
 * this entity's title column" and so on for every field this module
 * understands — written as plain data (not scattered `if`/`switch` logic)
 * so a test can walk it and a docs page could render it without duplicating
 * the list by hand (rule #34 — tree-derived coverage).
 *
 * THREE RULE SHAPES, keyed by each tag entry's 'type':
 *   - 'simple'   — a straightforward per-subfield mapping. Each subfield
 *                  code maps to either `['kind'=>'field', 'target'=>COLUMN,
 *                  'mode'=>'first'|'join', 'transform'=>?NAME]` (goes into
 *                  the entity's `fields[COLUMN]`; 'join' concatenates
 *                  multiple contributing values with ', ' in document
 *                  order — used for PublicationYear, which can be fed by
 *                  BOTH a 250 edition statement and a 264 $c date, e.g.
 *                  "2nd ed., 2011", matching `tblSongbooks.PublicationYear`'s
 *                  own schema.sql comment: "Year / edition range (free-form:
 *                  1986, 1986-2003, 2nd edition 2011)"), `['kind'=>
 *                  'altTitle']` (goes into the entity's `altTitles` list,
 *                  e.g. a 245 $b subtitle), or `['kind'=>'identifier',
 *                  'target'=>COLUMN, 'slug'=>SLUG]` (cleaned + validated via
 *                  `mediaIdentifierPublicationValidate()` before landing in
 *                  `fields[COLUMN]` — used for 020/022 isbn/issn).
 *   - 'ark024'   — MARC 024 (Other Standard Identifier) needs BOTH
 *                  ind1='7' AND a $2 (source code) of 'ark' before its $a is
 *                  an ARK at all (ind1=7 alone is used for dozens of OTHER
 *                  standard identifier schemes) — a per-subfield 'simple'
 *                  rule cannot express that cross-subfield condition, so
 *                  024 gets its own tiny rule shape with just a `target`
 *                  column (always 'ArkId' — every entity kind that maps 024
 *                  at all uses the same target).
 *   - 'link856'  — MARC 856 (Electronic Location) is a LIST of test/target
 *                  pairs tried IN ORDER against each `$u`: the first test
 *                  that recognises the URL wins (ark → ArkId, openlibrary-
 *                  work/-edition → the matching column, wikipedia →
 *                  WikipediaUrl on 'songbook' only). A `$u` that matches
 *                  NONE of an entity kind's rules — including, DELIBERATELY,
 *                  every `archive.org` URL on every entity kind (see the
 *                  '856' entries' own inline comment) — falls into the
 *                  generic `links` list untouched, per the file doc-block's
 *                  Feature 7 note.
 *
 * WHY THE THREE ENTITY KINDS ARE SEPARATE LITERAL ARRAYS RATHER THAN ONE
 * ARRAY WITH A "PRUNE UNSUPPORTED COLUMNS" STEP: `tblSongbooks` /
 * `tblSongbookSeries` / `tblCatalogues` genuinely have different column sets
 * (the epic plan's "Adversarial notes" — series has no Publisher/
 * PublicationYear/PublicationCity/Lccn/OclcNumber/Language at all;
 * catalogue has none of those AND no Isbn/Issn; only 'catalogue' names its
 * title column 'Title' rather than 'Name'). A single map plus a runtime
 * "does this entity have that column" filter would be MORE code (the filter
 * logic itself) to express something three short literal tables already
 * say plainly — and a filter step is exactly the kind of implicit rule this
 * repo's rule #34 warns can be silently wrong in a way a reader does not
 * notice. Three tables that can be read top-to-bottom and diffed against
 * each other by eye is the simpler, more honest shape for genuinely
 * different schemas.
 * ========================================================================= */
const MARCXML_FIELD_MAPS = [
    'songbook' => [
        '245' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'field', 'target' => 'Name', 'mode' => 'first'],
            'b' => ['kind' => 'altTitle'],
        ]],
        '250' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'field', 'target' => 'PublicationYear', 'mode' => 'join'],
        ]],
        '260' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'field', 'target' => 'PublicationCity', 'mode' => 'first'],
            'b' => ['kind' => 'field', 'target' => 'Publisher', 'mode' => 'first'],
            'c' => ['kind' => 'field', 'target' => 'PublicationYear', 'mode' => 'join'],
        ]],
        '264' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'field', 'target' => 'PublicationCity', 'mode' => 'first'],
            'b' => ['kind' => 'field', 'target' => 'Publisher', 'mode' => 'first'],
            'c' => ['kind' => 'field', 'target' => 'PublicationYear', 'mode' => 'join'],
        ]],
        '020' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'identifier', 'target' => 'Isbn', 'slug' => 'isbn'],
        ]],
        '010' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'field', 'target' => 'Lccn', 'mode' => 'first'],
        ]],
        '035' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'field', 'target' => 'OclcNumber', 'mode' => 'first', 'transform' => 'oclc'],
        ]],
        '041' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'field', 'target' => 'Language', 'mode' => 'first', 'transform' => 'lang'],
        ]],
        '024' => ['type' => 'ark024', 'target' => 'ArkId'],
        /* 856: archive.org is DELIBERATELY absent from this rule list even
           though tblSongbooks still has a legacy InternetArchiveUrl column
           at this commit — the Feature 7 amendment (file doc-block) retires
           that column in favour of the (DB-touching, commit-6) external-
           link provider match, so here an archive.org $u falls through to
           the generic `links` list exactly like any other unrecognised URL.
           Wikipedia is UNAFFECTED by that amendment (the plan's Feature 7
           section explicitly calls it "a follow-on, out of scope here") and
           keeps its column mapping, songbook-only (series/catalogue never
           had a WikipediaUrl column). */
        '856' => ['type' => 'link856', 'rules' => [
            ['test' => 'ark', 'target' => 'ArkId'],
            ['test' => 'openlibrary-work', 'target' => 'OpenLibraryWorkId'],
            ['test' => 'openlibrary-edition', 'target' => 'OpenLibraryEditionId'],
            ['test' => 'wikipedia', 'target' => 'WikipediaUrl'],
        ]],
    ],
    'series' => [
        '245' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'field', 'target' => 'Name', 'mode' => 'first'],
            'b' => ['kind' => 'altTitle'],
        ]],
        '020' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'identifier', 'target' => 'Isbn', 'slug' => 'isbn'],
        ]],
        '022' => ['type' => 'simple', 'sub' => [
            'a' => ['kind' => 'identifier', 'target' => 'Issn', 'slug' => 'issn'],
        ]],
        '024' => ['type' => 'ark024', 'target' => 'ArkId'],
        '856' => ['type' => 'link856', 'rules' => [
            ['test' => 'ark', 'target' => 'ArkId'],
            ['test' => 'openlibrary-work', 'target' => 'OpenLibraryWorkId'],
            ['test' => 'openlibrary-edition', 'target' => 'OpenLibraryEditionId'],
        ]],
    ],
    'catalogue' => [
        '245' => ['type' => 'simple', 'sub' => [
            /* tblCatalogues names its title column 'Title', not 'Name' —
               the one place the three entity kinds' column names actually
               diverge for the SAME semantic field. */
            'a' => ['kind' => 'field', 'target' => 'Title', 'mode' => 'first'],
            'b' => ['kind' => 'altTitle'],
        ]],
        '024' => ['type' => 'ark024', 'target' => 'ArkId'],
        '856' => ['type' => 'link856', 'rules' => [
            ['test' => 'ark', 'target' => 'ArkId'],
            ['test' => 'openlibrary-work', 'target' => 'OpenLibraryWorkId'],
            ['test' => 'openlibrary-edition', 'target' => 'OpenLibraryEditionId'],
        ]],
    ],
];

/**
 * The MARC field mapping table for one publication entity kind — PURE, DATA
 * ONLY.
 *
 * ELI5: "if I'm importing/exporting a songbook (or series, or collection),
 * which MARC fields matter, and where does each one go?"
 *
 * @param string $entityKind 'songbook' | 'series' | 'catalogue'.
 * @return array The MARCXML_FIELD_MAPS[$entityKind] table — see that
 *               const's doc-block for the full shape contract.
 * @throws \InvalidArgumentException on an unrecognised $entityKind.
 */
function marcxmlFieldMap(string $entityKind): array
{
    if (!array_key_exists($entityKind, MARCXML_FIELD_MAPS)) {
        throw new \InvalidArgumentException(
            "marcxmlFieldMap(): unknown entity kind " . var_export($entityKind, true)
            . " — expected 'songbook', 'series' or 'catalogue'"
        );
    }
    return MARCXML_FIELD_MAPS[$entityKind];
}

/* =========================================================================
 * PARSE — MARCXML string -> plain PHP array(s). Pure, no I/O beyond the
 * string handed in.
 * ========================================================================= */

/**
 * Direct element children of $node, optionally filtered to one local name —
 * PURE, namespace-tolerant (see file doc-block).
 *
 * @return \DOMElement[]
 */
function marcxmlChildElements(\DOMNode $node, ?string $localName = null): array
{
    $out = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE
            && ($localName === null || $child->localName === $localName)) {
            $out[] = $child;
        }
    }
    return $out;
}

/**
 * Parse one `<record>` element into this module's plain-array shape.
 *
 * @return array{leader:string, control:array<string,string>, data:list<array{tag:string,ind1:string,ind2:string,sub:list<array{code:string,value:string}>}>}
 */
function marcxmlParseRecordElement(\DOMElement $recordEl): array
{
    $leader  = '';
    $control = [];
    $data    = [];

    foreach (marcxmlChildElements($recordEl) as $child) {
        $name = $child->localName;
        if ($name === 'leader') {
            $leader = $child->textContent;
        } elseif ($name === 'controlfield') {
            $tag = $child->getAttribute('tag');
            if ($tag !== '') {
                $control[$tag] = $child->textContent;
            }
        } elseif ($name === 'datafield') {
            $tag  = $child->getAttribute('tag');
            $ind1 = $child->hasAttribute('ind1') ? $child->getAttribute('ind1') : ' ';
            $ind2 = $child->hasAttribute('ind2') ? $child->getAttribute('ind2') : ' ';
            $sub  = [];
            foreach (marcxmlChildElements($child, 'subfield') as $sf) {
                $sub[] = ['code' => $sf->getAttribute('code'), 'value' => $sf->textContent];
            }
            $data[] = ['tag' => $tag, 'ind1' => $ind1, 'ind2' => $ind2, 'sub' => $sub];
        }
        /* Any other child element (MARCXML defines only leader/controlfield/
           datafield as <record> children) is silently ignored rather than
           treated as an error — a stray processing-instruction-shaped
           element from a slightly non-conformant export tool should not
           block an otherwise-good record. */
    }

    return ['leader' => $leader, 'control' => $control, 'data' => $data];
}

/**
 * Parse a MARCXML document (one `<record>` or a `<collection>` of them) into
 * a list of plain PHP arrays — PURE (beyond the DOM parser's own internal
 * state), XXE-safe (see file doc-block).
 *
 * ELI5: hand this the raw XML text of a MARC record (or a batch of them);
 * get back a plain array (or list of arrays) with the leader, control
 * fields and data fields pulled out — nothing fancier than that.
 *
 * @param string $xml Raw MARCXML text.
 * @return list<array{leader:string, control:array<string,string>, data:list<array{tag:string,ind1:string,ind2:string,sub:list<array{code:string,value:string}>}>}>
 *         Always a LIST, even for a single `<record>` root (one element).
 * @throws \InvalidArgumentException with a curator-readable message on
 *         malformed XML, a DOCTYPE declaration (refused, not parsed — XXE
 *         safety), a root element that is neither `<record>` nor
 *         `<collection>`, or a `<collection>` with no `<record>` children.
 */
function marcxmlParse(string $xml): array
{
    $xml = trim($xml);
    if ($xml === '') {
        throw new \InvalidArgumentException('marcxmlParse(): empty input — nothing to parse.');
    }

    /* Hard DOCTYPE refusal BEFORE the string ever reaches libxml — belt and
       braces alongside the ->doctype check below (file doc-block's
       XXE-safety section explains why this module refuses a DTD outright
       rather than relying solely on libxml's entity-substitution default). */
    if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
        throw new \InvalidArgumentException(
            'marcxmlParse(): a DOCTYPE declaration was found in the input — refused for XXE-safety, not parsed.'
        );
    }

    $dom = new \DOMDocument();
    $prevErr = libxml_use_internal_errors(true);
    /* LIBXML_NONET blocks any network fetch libxml might otherwise attempt
       for an external reference. LIBXML_NOENT (entity substitution) is
       deliberately NEVER passed — see the file doc-block. */
    $loaded    = $dom->loadXML($xml, LIBXML_NONET);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prevErr);

    if (!$loaded) {
        $first = $xmlErrors[0]->message ?? 'unknown parse error';
        throw new \InvalidArgumentException('marcxmlParse(): malformed XML — ' . trim($first));
    }
    if ($dom->doctype !== null) {
        throw new \InvalidArgumentException(
            'marcxmlParse(): a DOCTYPE declaration was found in the input — refused for XXE-safety, not parsed.'
        );
    }

    $root = $dom->documentElement;
    if ($root === null) {
        throw new \InvalidArgumentException('marcxmlParse(): no root element — not a MARC document.');
    }

    $localName = $root->localName ?? $root->nodeName;
    if ($localName === 'collection') {
        $recordEls = marcxmlChildElements($root, 'record');
        if ($recordEls === []) {
            throw new \InvalidArgumentException(
                'marcxmlParse(): <collection> root has no <record> children — not a MARC document.'
            );
        }
        $out = [];
        foreach ($recordEls as $el) {
            $out[] = marcxmlParseRecordElement($el);
        }
        return $out;
    }
    if ($localName === 'record') {
        $parsed = marcxmlParseRecordElement($root);
        if ($parsed['leader'] === '' && $parsed['control'] === [] && $parsed['data'] === []) {
            throw new \InvalidArgumentException(
                'marcxmlParse(): <record> has no leader, control fields or data fields — not a MARC document.'
            );
        }
        return [$parsed];
    }

    throw new \InvalidArgumentException(
        "marcxmlParse(): root element is <{$localName}>, expected <record> or <collection> — not a MARC document."
    );
}

/* =========================================================================
 * MAP TO ENTITY — one parsed MARC record -> iHymns field values. Pure.
 * ========================================================================= */

/**
 * Strip a MARC 020/022 subfield value down to its bare identifier characters
 * — PURE.
 *
 * ELI5: real MARC records often write an ISBN like "0-19-852663-6 (pbk.)" —
 * this keeps just the "0198526636" part (digits + a possible trailing check
 * "digit" X), ready for `mediaIdentifierPublicationValidate()`.
 *
 * Shared by both isbn and issn (same shape: digits + optional trailing
 * X/x check character) rather than one cleaner per slug — a second nearly-
 * identical function would be exactly the kind of "second copy" rule #22
 * warns against, just for a cleaning step rather than a scoring one.
 */
function marcxmlCleanIdentifierRaw(string $raw): string
{
    $raw = trim($raw);
    /* Drop a trailing parenthetical qualifier, e.g. "0-19-852663-6 (pbk.)". */
    $paren = strpos($raw, '(');
    if ($paren !== false) {
        $raw = trim(substr($raw, 0, $paren));
    }
    /* Take the first whitespace-delimited token only (covers the rare
       MARC record that lists more than one qualifier-separated value in a
       single $a; this module stores one value per column). */
    $parts = preg_split('/\s+/', $raw) ?: [];
    $raw   = $parts[0] ?? '';
    return strtoupper((string)preg_replace('/[^0-9Xx]/', '', $raw));
}

/**
 * Does $url's host match $suffix (itself or a subdomain of it)? — PURE,
 * used for the 856 wikipedia test.
 *
 * ELI5: "is this a wikipedia.org link?" — checked against the URL's actual
 * HOST, never a raw substring match against the whole URL string (a raw
 * `str_contains($url, 'wikipedia.org')` would be fooled by
 * `https://evil.example/?redirect=wikipedia.org`).
 */
function marcxmlUrlHostMatches(string $url, string $suffix): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }
    $host = strtolower($host);
    $suffix = strtolower($suffix);
    return $host === $suffix || str_ends_with($host, '.' . $suffix);
}

/**
 * Run one 856 `$u` value against a single named test — PURE. Returns the
 * value to STORE (already canonicalised where the test canonicalises) or
 * `null` when the URL does not match this test at all.
 *
 * @param string $test One of 'ark' | 'openlibrary-work' | 'openlibrary-edition' | 'wikipedia'.
 * @param string $url   The raw 856 $u value.
 */
function marcxmlTestLink(string $test, string $url): ?string
{
    switch ($test) {
        case 'ark':
            return ihymns_canonical_ark($url) ?: null;
        case 'openlibrary-work':
            return ihymns_canonical_openlibrary($url, 'work') ?: null;
        case 'openlibrary-edition':
            return ihymns_canonical_openlibrary($url, 'edition') ?: null;
        case 'wikipedia':
            return marcxmlUrlHostMatches($url, 'wikipedia.org') ? $url : null;
        default:
            /* Unreachable given MARCXML_FIELD_MAPS's own 'test' values — a
               defensive fallback rather than an assert, so a future rule
               with a typo'd 'test' degrades to "never matches" (falls into
               `links`) instead of a fatal. */
            return null;
    }
}

/**
 * Apply a 'simple' MARCXML_FIELD_MAPS rule (245/250/260/264/020/022/010/035/
 * 041) to one parsed datafield — mutates $collected/$altTitles/$errors by
 * reference (the accumulator shape every apply* helper shares).
 *
 * @param array<string,array{kind:string,target?:string,mode?:string,transform?:?string,slug?:string}> $subRules
 * @param array<string,list<array{value:string,mode:string}>> $collected
 * @param list<string> $altTitles
 * @param list<string> $errors
 */
function marcxmlApplySimple(array $df, array $subRules, string $tag, array &$collected, array &$altTitles, array &$errors): void
{
    foreach (($df['sub'] ?? []) as $sf) {
        $code = (string)($sf['code'] ?? '');
        if (!isset($subRules[$code])) {
            continue;
        }
        $rule  = $subRules[$code];
        $value = trim((string)($sf['value'] ?? ''));
        if ($value === '') {
            continue;
        }

        if ($rule['kind'] === 'altTitle') {
            $altTitles[] = $value;
            continue;
        }

        if ($rule['kind'] === 'identifier') {
            $slug  = (string)$rule['slug'];
            $clean = marcxmlCleanIdentifierRaw($value);
            if ($clean !== '' && mediaIdentifierPublicationValidate($slug, $clean)) {
                $collected[$rule['target']][] = ['value' => $clean, 'mode' => 'first'];
            } else {
                $errors[] = "{$tag}\${$code}: " . var_export($value, true) . " does not look like a valid " . strtoupper($slug);
            }
            continue;
        }

        /* 'field' */
        $val = $value;
        $transform = $rule['transform'] ?? null;
        if ($transform === 'oclc') {
            /* Only a genuine "(OCoLC)…" control number maps to OclcNumber —
               035 $a is also used by OTHER systems' control numbers, which
               this module does not attempt to classify (they simply do not
               contribute a value here; the datafield is still considered
               "matched" since 'a' has a rule, so it is not reported in
               `unmapped`). */
            if (preg_match('/^\(OCoLC\)\s*/i', $val) !== 1) {
                continue;
            }
            $val = (string)preg_replace('/^\(OCoLC\)\s*/i', '', $val);
            $val = (string)preg_replace('/[^0-9]/', '', $val);
            if ($val === '') {
                continue;
            }
        } elseif ($transform === 'lang') {
            $val = marcxmlLanguageCodeToBcp47($val);
            if ($val === '') {
                continue;
            }
        }
        $collected[$rule['target']][] = ['value' => $val, 'mode' => (string)($rule['mode'] ?? 'first')];
    }
}

/**
 * Apply the 'ark024' rule (MARC 024, Other Standard Identifier) to one
 * parsed datafield.
 *
 * ELI5: 024 carries all sorts of "other" standard identifiers — an ARK is
 * only ONE of them, signalled by ind1='7' (meaning "the source is named in
 * $2") AND $2 literally being 'ark'. Anything else in a 024 (a different
 * ind1, or ind1=7 with a different $2) is simply not an ARK and is ignored
 * here — NOT reported as unmapped, because the datafield tag itself IS
 * recognised by this module, just not this particular occurrence's content.
 */
function marcxmlApplyArk024(array $df, string $target, array &$collected, array &$errors): void
{
    if ((string)($df['ind1'] ?? '') !== '7') {
        return;
    }
    $source = null;
    $value  = null;
    foreach (($df['sub'] ?? []) as $sf) {
        $code = (string)($sf['code'] ?? '');
        if ($code === '2') {
            $source = strtolower(trim((string)($sf['value'] ?? '')));
        } elseif ($code === 'a') {
            $value = trim((string)($sf['value'] ?? ''));
        }
    }
    if ($source !== 'ark' || $value === null || $value === '') {
        return;
    }
    $canon = ihymns_canonical_ark($value);
    if ($canon === null || $canon === '') {
        $errors[] = "024\$a: " . var_export($value, true) . ' does not look like a valid ARK';
        return;
    }
    $collected[$target][] = ['value' => $canon, 'mode' => 'first'];
}

/**
 * Apply a 'link856' rule (MARC 856, Electronic Location and Access) to one
 * parsed datafield.
 *
 * ELI5: try each configured test (ark, openlibrary-work, …) against this
 * 856's $u in order; the first one that recognises the URL wins and its
 * canonical value lands on the matching column. A $u nothing recognises is
 * collected into the generic `links` list, untouched, so it is never
 * silently dropped (the file doc-block's "unmapped/links, never dropped"
 * contract).
 *
 * @param list<array{test:string,target:string}> $rules
 * @param list<string> $links
 */
function marcxmlApplyLink856(array $df, array $rules, array &$collected, array &$links): void
{
    foreach (($df['sub'] ?? []) as $sf) {
        if ((string)($sf['code'] ?? '') !== 'u') {
            continue;
        }
        $u = trim((string)($sf['value'] ?? ''));
        if ($u === '') {
            continue;
        }
        $matched = false;
        foreach ($rules as $rule) {
            $canon = marcxmlTestLink((string)$rule['test'], $u);
            if ($canon !== null) {
                $collected[$rule['target']][] = ['value' => $canon, 'mode' => 'first'];
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $links[] = $u;
        }
    }
}

/**
 * Map one parsed MARC record onto an iHymns publication entity's fields —
 * PURE (no DB, no I/O).
 *
 * ELI5: take the array `marcxmlParse()` gave you, and turn it into "here are
 * the column values to save", "here are the alternate titles", "here are
 * the links that don't have a dedicated column", "here are the MARC tags
 * this module doesn't understand at all", and "here's what looked wrong".
 *
 * DETAILED / THE FIVE OUTPUT BUCKETS, AND WHY NOTHING IS EVER SILENTLY
 * DROPPED (the file doc-block's promise, made concrete here):
 *   - `fields`    column => value, ready to bind into an UPDATE/INSERT once
 *                 a later commit's DB layer validates the entity exists.
 *   - `altTitles` alternate titles (245 $b subtitles) — this module never
 *                 decides where these are stored (that is a later commit's
 *                 concern); it only collects them.
 *   - `links`     856 $u values that matched NONE of this entity kind's
 *                 `link856` rules — the generic-provider-match half (rule
 *                 #34-honouring: `externalLinkDetectProviderId()`) is a
 *                 later, DB-touching commit's job (file doc-block).
 *   - `unmapped`  MARC TAGS this module's field map has NO entry for at all
 *                 (e.g. a 500 general note) — the tag itself, not its
 *                 content, so a curator reviewing an import can see what
 *                 was silently skipped and judge whether it mattered. A tag
 *                 that IS recognised but whose PARTICULAR occurrence didn't
 *                 match anything (e.g. a 024 that isn't an ARK, or a 035
 *                 that isn't an OCLC number) is NOT added here — it was
 *                 correctly recognised and correctly found to carry nothing
 *                 this module stores, which is different from "never heard
 *                 of this tag".
 *   - `errors`    identifiers that LOOKED like they should map (the tag/
 *                 subfield matched a rule) but failed shape validation —
 *                 an invalid ARK/OpenLibrary/ISBN/ISSN value. Reported, not
 *                 silently dropped and not silently accepted either.
 *
 * LANGUAGE FALLBACK: if no 041 $a supplied a Language value, the 008
 * control field's positions 35–37 (0-indexed) are tried — the MARC 21
 * fixed-length "Language" position every bibliographic 008 carries when
 * present at all. Only consulted when 041 is entirely absent, matching the
 * file doc-block's "245→field map, 041/008→Language" contract (041 always
 * wins when both are present).
 *
 * @param array{leader:string, control:array<string,string>, data:list<array{tag:string,ind1:string,ind2:string,sub:list<array{code:string,value:string}>}>} $record
 *        One element of marcxmlParse()'s return value.
 * @param string $entityKind 'songbook' | 'series' | 'catalogue'.
 * @return array{fields:array<string,string>, altTitles:list<string>, links:list<string>, unmapped:list<string>, errors:list<string>}
 * @throws \InvalidArgumentException on an unrecognised $entityKind (via marcxmlFieldMap()).
 */
function marcxmlMapToEntity(array $record, string $entityKind): array
{
    $map = marcxmlFieldMap($entityKind);

    /** @var array<string,list<array{value:string,mode:string}>> $collected */
    $collected = [];
    $altTitles = [];
    $links     = [];
    $unmapped  = [];
    $errors    = [];

    foreach (($record['data'] ?? []) as $df) {
        $tag = (string)($df['tag'] ?? '');
        if ($tag === '') {
            continue;
        }
        $rule = $map[$tag] ?? null;
        if ($rule === null) {
            $unmapped[] = $tag;
            continue;
        }
        switch ($rule['type']) {
            case 'simple':
                marcxmlApplySimple($df, $rule['sub'], $tag, $collected, $altTitles, $errors);
                break;
            case 'ark024':
                marcxmlApplyArk024($df, $rule['target'], $collected, $errors);
                break;
            case 'link856':
                marcxmlApplyLink856($df, $rule['rules'], $collected, $links);
                break;
        }
    }

    /* Language fallback: 008 positions 35-37, ONLY when 041 supplied nothing. */
    if (!isset($collected['Language'])) {
        $ctrl008 = (string)($record['control']['008'] ?? '');
        if (strlen($ctrl008) >= 38) {
            $bcp = marcxmlLanguageCodeToBcp47(substr($ctrl008, 35, 3));
            if ($bcp !== '') {
                $collected['Language'][] = ['value' => $bcp, 'mode' => 'first'];
            }
        }
    }

    $fields = [];
    foreach ($collected as $col => $entries) {
        if ($entries === []) {
            continue;
        }
        $mode = $entries[0]['mode'];
        if ($mode === 'join') {
            $vals = [];
            foreach ($entries as $e) {
                if (!in_array($e['value'], $vals, true)) {
                    $vals[] = $e['value'];
                }
            }
            $fields[$col] = implode(', ', $vals);
        } else {
            $fields[$col] = $entries[0]['value'];
        }
    }

    return [
        'fields'    => $fields,
        'altTitles' => array_values(array_unique($altTitles)),
        'links'     => array_values(array_unique($links)),
        'unmapped'  => array_values(array_unique($unmapped)),
        'errors'    => $errors,
    ];
}

/* =========================================================================
 * GENERATE — iHymns entity fields -> MARCXML string. Pure, structural
 * (DOMDocument) escaping throughout — never string concatenation.
 * ========================================================================= */

/**
 * Build one MARCXML `<record>` document from a `marcxmlMapToEntity()`-shaped
 * entity array.
 *
 * ELI5: the reverse of `marcxmlMapToEntity()` — hand this a songbook's
 * (or series's, or catalogue's) field values and get back MARCXML a
 * librarian's cataloguing software can import.
 *
 * DETAILED: reverse-maps over the SAME `marcxmlFieldMap($entityKind)` table
 * `marcxmlMapToEntity()` reads (via the shared `marcxmlFieldMap()` call,
 * which also supplies the "is this a real entity kind" check — no second,
 * hand-typed validity check here). Every subfield value is appended via
 * `DOMDocument::createTextNode()` / `->appendChild()` — DOM handles XML
 * entity-escaping structurally, so a title containing `&`, `<` or `"`
 * (all legal in MARC free text) can never corrupt the document, unlike a
 * hand-built `"<subfield code=\"a\">{$title}</subfield>"` string would.
 *
 * NOT A PERFECT ROUND-TRIP FOR EVERY FIELD, BY DESIGN: `PublicationYear`
 * may hold a JOINED "2nd ed., 2011" value folded from BOTH a 250 edition
 * statement and a 264 $c date (`marcxmlFieldMap()`'s 'join' mode) — this
 * function does not attempt to re-split that back into two MARC fields; it
 * emits the whole value as 264 $c. `tests/php/test-marcxml.php`'s round-trip
 * assertion is scoped to what this genuinely guarantees: the IDENTIFIER
 * datafields (020/022/010/035/024/856-ark/856-openlibrary) survive a
 * generate→parse cycle unchanged, not free-text fields that were folded
 * from more than one MARC source field on the way in.
 *
 * @param array{fields:array<string,string>, altTitles?:list<string>, links?:list<string>} $entity
 *        Typically `marcxmlMapToEntity()`'s own return value (its `unmapped`/
 *        `errors` keys, if present, are ignored — they carry no export data).
 * @param string $entityKind 'songbook' | 'series' | 'catalogue'.
 * @return string A complete MARCXML `<record>` document (UTF-8, XML declaration included).
 * @throws \InvalidArgumentException on an unrecognised $entityKind (via marcxmlFieldMap()).
 */
function marcxmlGenerate(array $entity, string $entityKind): string
{
    marcxmlFieldMap($entityKind); // validity check only — throws on an unknown kind

    $fields    = $entity['fields'] ?? [];
    $altTitles = $entity['altTitles'] ?? [];
    $links     = $entity['links'] ?? [];

    $doc = new \DOMDocument('1.0', 'UTF-8');
    $ns  = 'http://www.loc.gov/MARC21/slim';
    $record = $doc->createElementNS($ns, 'record');
    $doc->appendChild($record);
    $record->appendChild($doc->createElement('leader', MARCXML_MINIMAL_LEADER));

    /**
     * Append one `<datafield>` with the given tag/indicators/subfields —
     * every text value passes through `createTextNode()` (structural
     * escaping, per this function's doc-block).
     *
     * @param array<string,string> $subfields code => value, in emit order.
     */
    $addDatafield = function (string $tag, string $ind1, string $ind2, array $subfields) use ($doc, $record): void {
        $df = $doc->createElement('datafield');
        $df->setAttribute('tag', $tag);
        $df->setAttribute('ind1', $ind1);
        $df->setAttribute('ind2', $ind2);
        foreach ($subfields as $code => $value) {
            $sf = $doc->createElement('subfield');
            $sf->setAttribute('code', (string)$code);
            /* Strip XML-1.0-illegal C0 control characters before the text
               node (#1765 review). createTextNode() escapes markup
               metacharacters (&, <, ") but passes a raw 0x0B/0x0C/etc.
               through, which then makes saveXML() emit a document that
               marcxmlParse() (and any conformant MARC importer) rejects with
               "PCDATA invalid Char value". Tab (0x09), LF (0x0A) and CR
               (0x0D) are the only C0 chars XML 1.0 permits, so they are kept;
               everything else in 0x00-0x1F is removed. */
            $sf->appendChild($doc->createTextNode(
                preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value
            ));
            $df->appendChild($sf);
        }
        $record->appendChild($df);
    };

    /* 245 — title ($a) + first alt title as subtitle ($b), if any. */
    $titleCol = $entityKind === 'catalogue' ? 'Title' : 'Name';
    if (!empty($fields[$titleCol])) {
        $subs = ['a' => (string)$fields[$titleCol]];
        if ($altTitles !== []) {
            $subs['b'] = (string)$altTitles[0];
        }
        $addDatafield('245', ' ', '0', $subs);
    }

    /* 020 ISBN / 022 ISSN. */
    if (!empty($fields['Isbn'])) {
        $addDatafield('020', ' ', ' ', ['a' => (string)$fields['Isbn']]);
    }
    if (!empty($fields['Issn'])) {
        $addDatafield('022', ' ', ' ', ['a' => (string)$fields['Issn']]);
    }

    /* 010 LCCN. */
    if (!empty($fields['Lccn'])) {
        $addDatafield('010', ' ', ' ', ['a' => (string)$fields['Lccn']]);
    }

    /* 035 OCLC control number, re-wrapped in the "(OCoLC)" prefix parsing expects. */
    if (!empty($fields['OclcNumber'])) {
        $addDatafield('035', ' ', ' ', ['a' => '(OCoLC)' . $fields['OclcNumber']]);
    }

    /* 041 language — MARC 041 $a is a 3-letter ISO 639-2 code, but iHymns
       stores the 2-letter BCP-47 tag, so resolve it on the way out (#1765
       review). If it cannot be resolved to a valid 3-letter code, omit 041
       rather than emit an invalid 2-letter value. */
    if (!empty($fields['Language'])) {
        $lang041 = marcxmlBcp47ToLanguageCode((string)$fields['Language']);
        if ($lang041 !== '') {
            $addDatafield('041', '0', ' ', ['a' => $lang041]);
        }
    }

    /* 264 publication (city/publisher/year) — MARC21 prefers 264 over the
       legacy 260, so generation always emits 264 regardless of which of the
       two the import came from. */
    $pub = [];
    if (!empty($fields['PublicationCity'])) { $pub['a'] = (string)$fields['PublicationCity']; }
    if (!empty($fields['Publisher']))       { $pub['b'] = (string)$fields['Publisher']; }
    if (!empty($fields['PublicationYear'])) { $pub['c'] = (string)$fields['PublicationYear']; }
    if ($pub !== []) {
        $addDatafield('264', ' ', '1', $pub);
    }

    /* 024 ind1=7 $2=ark — Archival Resource Key. */
    if (!empty($fields['ArkId'])) {
        $addDatafield('024', '7', ' ', ['a' => (string)$fields['ArkId'], '2' => 'ark']);
    }

    /* 856 — OpenLibrary work/edition, the legacy WikipediaUrl column (still
       live on 'songbook' at this commit), and every still-unclassified link
       collected at parse time (which is also where a re-exported
       archive.org URL lands — see the file doc-block's Feature 7 note). */
    if (!empty($fields['OpenLibraryWorkId'])) {
        $addDatafield('856', '4', '1', ['u' => 'https://openlibrary.org/works/' . $fields['OpenLibraryWorkId']]);
    }
    if (!empty($fields['OpenLibraryEditionId'])) {
        $addDatafield('856', '4', '1', ['u' => 'https://openlibrary.org/books/' . $fields['OpenLibraryEditionId']]);
    }
    if (!empty($fields['WikipediaUrl'])) {
        $addDatafield('856', '4', '2', ['u' => (string)$fields['WikipediaUrl']]);
    }
    foreach ($links as $u) {
        if (is_string($u) && $u !== '') {
            $addDatafield('856', '4', '2', ['u' => $u]);
        }
    }

    return (string)$doc->saveXML();
}

/**
 * Wrap several already-generated MARCXML `<record>` documents into one
 * `<collection>`.
 *
 * ELI5: `marcxmlGenerate()` makes ONE record; this staples several of them
 * together into the batch shape `marcxmlParse()` also understands
 * (round-trip: `marcxmlParse(marcxmlCollection([...]))` yields one array
 * entry per input record).
 *
 * XXE-safety: each supplied string is parsed with the SAME posture as
 * `marcxmlParse()` (LIBXML_NONET, no LIBXML_NOENT, DOCTYPE refused) before
 * its `<record>` element is imported into the collection — a caller handing
 * this function something other than trusted output from `marcxmlGenerate()`
 * gets the identical protection `marcxmlParse()` gives any other input.
 *
 * @param list<string> $recordsXml Each a complete MARCXML `<record>` document string.
 * @return string A complete MARCXML `<collection>` document (UTF-8, XML declaration included).
 * @throws \InvalidArgumentException on an empty/non-string element, malformed XML, a DOCTYPE, or a non-`<record>` root.
 */
function marcxmlCollection(array $recordsXml): string
{
    $doc = new \DOMDocument('1.0', 'UTF-8');
    $ns  = 'http://www.loc.gov/MARC21/slim';
    $root = $doc->createElementNS($ns, 'collection');
    $doc->appendChild($root);

    foreach ($recordsXml as $xml) {
        if (!is_string($xml) || trim($xml) === '') {
            throw new \InvalidArgumentException(
                'marcxmlCollection(): every element must be a non-empty MARCXML <record> document string'
            );
        }
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new \InvalidArgumentException(
                'marcxmlCollection(): a supplied record declares a DOCTYPE — refused for XXE-safety, not parsed.'
            );
        }
        $sub = new \DOMDocument();
        $prevErr = libxml_use_internal_errors(true);
        $ok = $sub->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErr);
        if (!$ok || $sub->documentElement === null) {
            throw new \InvalidArgumentException(
                'marcxmlCollection(): one of the supplied record strings is not well-formed XML'
            );
        }
        if ($sub->doctype !== null) {
            throw new \InvalidArgumentException(
                'marcxmlCollection(): a supplied record declares a DOCTYPE — refused for XXE-safety, not parsed.'
            );
        }
        $subLocalName = $sub->documentElement->localName ?? $sub->documentElement->nodeName;
        if ($subLocalName !== 'record') {
            throw new \InvalidArgumentException(
                "marcxmlCollection(): a supplied document's root is <{$subLocalName}>, expected <record>"
            );
        }
        $imported = $doc->importNode($sub->documentElement, true);
        $root->appendChild($imported);
    }

    return (string)$doc->saveXML();
}
