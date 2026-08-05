<?php

declare(strict_types=1);

/**
 * iHymns — Shared external-identifier normaliser (#1741 P3)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A curator (or an importer) can type the SAME identifier a dozen slightly
 * different ways — "T-345.246.800-1", "t345246800-1", "T345246800.1" are all
 * the same ISWC. This file is the ONE place that turns a messy, curator-typed
 * or URL-pasted identifier into ONE canonical string per scheme, so a lookup
 * for "T-345.246.800-1" and a lookup for "t345246800-1" hit the exact same
 * database row. Six schemes are covered: ISWC / CCLI / BOWI (work-grain),
 * ISRC (recording-grain), and IPI / ISNI (party/musician-grain).
 *
 * `IHYMNS_ID_SCHEMES` is the registry that says which schemes exist, what
 * entity type each one resolves to (work / song / musician), and whether more
 * than one song can legitimately share the same value (`multiSong`) — the
 * same VARCHAR-not-ENUM, app-validated-map shape rule #20 requires for any
 * growable vocabulary. `includes/identifier_resolve.php` (the sibling P3
 * module) reads this registry to decide which table(s) to query; the six
 * `/iswc/ /ccli/ /bowi/ /isrc/ /ipi/ /isni/` public routes in router.js +
 * api.php are driven by the SAME registry keys.
 *
 * DETAILED / WHY A SEPARATE FILE FROM works.php AND musician_helpers.php
 * ----------------------------------------------------------------------------
 * Before this file, the canonical ISWC fold lived ONLY inside
 * `manage/works.php`'s private `$validateIswc` closure — un-reusable from
 * anywhere else, so the (now-deleted) `includes/pages/iswc.php` public page
 * carried its OWN, weaker, duplicate strip (`preg_replace('/[^T0-9.\-]/i', ...)`
 * followed by `strtoupper()` — no shape validation, no canonical reformat).
 * Two folds for the same value is exactly the regression rule #22 names
 * ("A second copy of the duplicate/counterpart similarity maths … is a
 * regression" — the same principle applied to identifier canonicalisation,
 * not fuzzy-matching maths). `ihymns_canonical_iswc()` below is that ONE
 * fold, extracted byte-for-byte from `works.php`'s `$validateIswc` body;
 * `works.php` now delegates to it (see the edit in the same commit).
 *
 * ISNI/IPI canonicalisation is NOT re-implemented here either —
 * `ihymns_canonical_isni()` delegates to `musician_helpers.php`'s
 * `canonicaliseIsni()`, the existing single source of truth for that fold
 * (used by the `/manage/musicians` "Other Identifiers" save path). CCLI/BOWI/
 * ISRC had no prior fold anywhere in the codebase (their columns were written
 * verbatim by importers) — the three new folds here are conservative
 * "strip separators, don't reject" cleans. BOWI's exact shape is not yet
 * confirmed by an authoritative source (unlike ISWC/ISRC/ISNI, which ARE
 * standardised), so `ihymns_canonical_bowi()` never returns `null` — it only
 * ever cleans, matching `WORK_IDENTIFIER_TYPES['bowi']['validate']` being
 * `null` in `media_identifiers.php` (D5) for the identical reason.
 *
 * Framework-free (no `$_SERVER` / session / DB reads in the fold functions
 * themselves — `ihymns_canonical_isni()`/`ihymns_canonical_ipi()` only
 * `require_once` a sibling include, never open a connection), so this file is
 * safe to `require_once` from the public API, `/manage`, and test runners
 * alike, mirroring `title_normalize.php` and `song_similarity.php`.
 *
 * Direct access is blocked (same guard as `media_identifiers.php` /
 * `musician_helpers.php`) so this file can't be requested as an endpoint via
 * an open Apache config.
 *
 * @link .claude/catalogue-expansion-1741-plan.md §4   the P3 build-scoping ground truth this file implements
 * @link .claude/media-identifiers-spec.md              the #1741 identifier taxonomy this registry is a slice of
 * @link appWeb/public_html/manage/works.php            the extracted $validateIswc fold (now delegates here)
 * @link appWeb/public_html/includes/musician_helpers.php the delegated-to canonicaliseIsni()
 * @link appWeb/public_html/includes/identifier_resolve.php the sibling P3 module that queries by canonical value
 * @see #1741
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* =========================================================================
 * IHYMNS_ID_SCHEMES — the registry of every external-identifier scheme the
 * public alias routes (/iswc/ /ccli/ /bowi/ /isrc/ /ipi/ /isni/) understand.
 *
 * ELI5: "what kinds of code can you look up, and what do you get back?"
 *
 * Per-scheme fields:
 *   - label      friendly display name (breadcrumb / page heading / empty-
 *                state copy in includes/pages/identifier.php).
 *   - entity     which kind of thing this scheme ultimately identifies —
 *                'work' (a tblWorks row, possibly with member songs),
 *                'song' (individual tblSongs rows only, no work concept),
 *                or 'musician' (tblMusicians rows via tblMusicianIdentifiers).
 *                Drives which branch includes/pages/identifier.php renders.
 *   - multiSong  true when more than one tblSongs row can legitimately share
 *                the same value (ISWC/CCLI/ISRC — a hymn text can be set by
 *                many arrangements; an ISRC can even repeat across regional
 *                re-releases). false for BOWI/IPI/ISNI, which this phase
 *                treats as identifying exactly one thing (a single work, or
 *                a single party) — informational for now (P3 does not
 *                enforce uniqueness from this flag), reserved for a future
 *                UI hint ("did you mean the SAME work?" vs "these songs are
 *                related").
 *
 * Order is deliberate (work-grain schemes first, then the one recording-grain
 * scheme, then the two party-grain schemes) and is what
 * tests/test-identifier-routes.js walks to derive coverage — never hardcode
 * this key list a second time anywhere else (rule #34).
 *
 * @link .claude/catalogue-expansion-1741-plan.md §4  "IHYMNS_ID_SCHEMES registry (iswc/ccli/bowi/isrc/ipi/isni)"
 * ========================================================================= */
const IHYMNS_ID_SCHEMES = [
    'iswc' => ['label' => 'ISWC',        'entity' => 'work',     'multiSong' => true],
    'ccli' => ['label' => 'CCLI Number', 'entity' => 'work',     'multiSong' => true],
    'bowi' => ['label' => 'BOWI',        'entity' => 'work',     'multiSong' => false],
    'isrc' => ['label' => 'ISRC',        'entity' => 'song',     'multiSong' => true],
    'ipi'  => ['label' => 'IPI',         'entity' => 'musician', 'multiSong' => false],
    'isni' => ['label' => 'ISNI',        'entity' => 'musician', 'multiSong' => false],
];

/**
 * Canonicalise an ISWC (International Standard Musical Work Code).
 *
 * ELI5: turn any of "T-345.246.800-1", "t345246800-1", "T 345 246 800 1"
 * into the ONE canonical "T-345.246.800-1" shape, or hand back `null` when
 * the input doesn't look like an ISWC at all.
 *
 * DETAILED / WHY: this is the EXACT fold that used to live only inside
 * `manage/works.php`'s private `$validateIswc` closure — copied here
 * byte-for-byte (uppercase+trim, shape-check via regex, re-digit-count-check,
 * reformat) so `manage/works.php` can delegate to it instead of owning the
 * only copy (rule #22 — one fold, not two). The check DIGIT (the trailing
 * `-C`) is NOT recomputed here, matching the original: ISWC check-digit
 * schemes vary by issuing body, so this is shape validation only, trusting
 * the curator/importer got the check digit right.
 *
 * @param string $raw Curator/importer/URL-decoded input, any separator style.
 * @return string|null '' for an empty (still "valid", ISWC is optional in
 *                      most contexts) input, `null` when non-empty but
 *                      malformed, else the canonical "T-NNN.NNN.NNN-C" string.
 * @link https://www.iswc.org/en    ISWC format reference (CISAC)
 * @link appWeb/public_html/manage/works.php  the original $validateIswc this was extracted from
 */
function ihymns_canonical_iswc(string $raw): ?string
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') return '';
    if (preg_match('/^T-?\d{3}\.?\d{3}\.?\d{3}-?\d$/', $raw) !== 1) return null;
    /* Re-format to canonical T-NNN.NNN.NNN-C. */
    $digits = preg_replace('/\D/', '', $raw);
    if (strlen((string)$digits) !== 10) return null;
    return 'T-' . substr($digits, 0, 3) . '.' . substr($digits, 3, 3)
         . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 1);
}

/**
 * Canonicalise a CCLI (SongSelect) work/song number.
 *
 * ELI5: a CCLI number is "just digits" — this strips everything that isn't a
 * digit (spaces, hyphens, a pasted "CCLI#" prefix) and hands back the digits.
 *
 * DETAILED / WHY: `tblSongs.Ccli` / `tblWorks.Ccli` store bare digit strings
 * (see schema.sql `idx_Ccli`); CCLI itself has no published check-digit or
 * fixed length, so — unlike ISWC — there is nothing to shape-validate against.
 * A curator pasting "CCLI Song # 1234567" or "1234567" both fold to the same
 * "1234567", which is the point: the lookup must be forgiving about
 * formatting since CCLI never publishes a canonical display format.
 *
 * @param string $raw
 * @return string Digits only; '' when the input carried no digits at all.
 */
function ihymns_canonical_ccli(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    return (string)preg_replace('/\D+/', '', $raw);
}

/**
 * Canonicalise a BOWI (Luminate Data's Bank of Work Identifiers code).
 *
 * ELI5: uppercase it and strip spaces/hyphens/dots so two curators who typed
 * the same code with different separators land on the same string.
 *
 * DETAILED / WHY: unlike ISWC/ISRC/ISNI, BOWI has no publicly documented
 * fixed shape this repo can validate against (see
 * `includes/media_identifiers.php`'s `WORK_IDENTIFIER_TYPES['bowi']['validate']
 * === null` for the identical reasoning) — so this fold is DELIBERATELY deny-
 * list-free: it cleans separators but never returns `null` for "looks wrong",
 * because there is no confirmed "right" shape to compare against yet. Once
 * an authoritative BOWI format reference is available, tighten this the same
 * way `ihymns_canonical_iswc()` tightens ISWC — not before, since a
 * speculative regex here would silently reject genuine values.
 *
 * @param string $raw
 * @return string Cleaned, uppercased; '' for an empty/whitespace-only input.
 */
function ihymns_canonical_bowi(string $raw): string
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') return '';
    return (string)preg_replace('/[\s.\-]+/', '', $raw);
}

/**
 * Canonicalise an ISRC (International Standard Recording Code).
 *
 * ELI5: turn "US-ABC-12-34567" or "us abc1234567" into "USABC1234567" — the
 * bare 12-character code with no separators, uppercased.
 *
 * DETAILED / WHY: ISRC's canonical machine-readable form (per IFPI) is the
 * 12-character CC-XXX-YY-NNNNN code with the separators removed; this mirrors
 * `RECORDING_EXTERNAL_ID_TYPES['isrc']['validate']` in `media_identifiers.php`
 * (`/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/`), though this fold deliberately does NOT
 * enforce that shape — `tblSongs.Isrc` is a free VARCHAR(15) with no CHECK
 * constraint, and rejecting a malformed-but-stored value here would make an
 * existing catalogue row un-look-up-able. Cleaning (never rejecting) matches
 * `ihymns_canonical_bowi()`'s posture for the same reason.
 *
 * @param string $raw
 * @return string Uppercased, non-alphanumeric characters stripped; '' for an
 *                empty input.
 * @link https://isrc.ifpi.org/en/isrc-standard/structure  ISRC structure reference (IFPI)
 */
function ihymns_canonical_isrc(string $raw): string
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') return '';
    return (string)preg_replace('/[^A-Z0-9]/', '', $raw);
}

/**
 * Canonicalise an ISNI (International Standard Name Identifier).
 *
 * ELI5: just hands the job to the ONE place that already knows how — the
 * musician-editor save path's `canonicaliseIsni()`.
 *
 * DETAILED / WHY: `musician_helpers.php::canonicaliseIsni()` is already the
 * single source of truth for ISNI canonicalisation (regroups a clean 16-char
 * "NNNN NNNN NNNN NNNX" — used by `/manage/musicians`'s "Other Identifiers"
 * save path). Re-implementing the same regroup logic here would be exactly
 * the "second copy of the same fold" rule #22 forbids; this function exists
 * only so `ihymns_normalize_identifier()` has one dispatch shape across all
 * six schemes, not because the fold itself needed a second home.
 *
 * @param string $raw
 * @return string canonicaliseIsni()'s result, verbatim.
 * @link appWeb/public_html/includes/musician_helpers.php canonicaliseIsni() — the delegate target
 */
function ihymns_canonical_isni(string $raw): string
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'musician_helpers.php';
    return canonicaliseIsni($raw);
}

/**
 * Canonicalise an IPI (Interested Party Information) number.
 *
 * ELI5: strip everything that isn't a digit — an IPI is "just digits",
 * same posture as CCLI.
 *
 * DETAILED / WHY: IPI numbers (assigned by CISAC's IPI System) are decimal;
 * this repo has no confirmed fixed length to validate against (see
 * `musician_helpers.php`'s `'ipn'` entry doc-block, IPI's performer-side
 * sibling, for the identical "no officially-confirmed digit count" note), so
 * — matching BOWI/ISRC's posture — this cleans rather than rejects.
 *
 * @param string $raw
 * @return string Digits only; '' when the input carried no digits at all.
 */
function ihymns_canonical_ipi(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    return (string)preg_replace('/\D+/', '', $raw);
}

/**
 * Canonicalise an ARK (Archival Resource Key).
 *
 * ELI5: turn "ark:/13960/t8jf3w89z", "ARK:/13960/t8jf3w89z",
 * "https://n2t.net/ark:/13960/t8jf3w89z" or the percent-encoded
 * "ark%3A%2F13960%2Ft8jf3w89z" all into the SAME canonical
 * "ark:/13960/t8jf3w89z" — or `null` when the input doesn't contain a
 * shape-valid ARK at all.
 *
 * DETAILED / WHY
 * --------------
 * A curator pastes an ARK from wherever they found it — a library catalogue
 * record's bare identifier, a resolver URL a browser bar shows after
 * following a link (n2t.net, arks.princeton.edu, and dozens of other
 * institutional resolvers all forward `https://<host>/ark:/<NAAN>/<name>`),
 * or a URL-encoded copy pasted out of a query string. All four must land on
 * the SAME stored value for a later lookup to find the row, exactly the
 * problem `ihymns_canonical_iswc()` solves for ISWC — this is that same
 * trichotomy applied to the ARK Alliance's identifier scheme.
 *
 * ALGORITHM: percent-decode first (so an encoded resolver URL's `ark%3A%2F…`
 * is treated identically to `ark:/…`), then find the first case-insensitive
 * `ark:/` anywhere in the decoded string and take EVERYTHING from there to
 * the end — a resolver URL's scheme/host/path prefix carries no information
 * the canonical form needs, so it is simply discarded rather than parsed.
 * The `ark:/` scheme token itself is forced to lowercase (the ARK spec
 * treats the scheme as case-insensitive) but the NAAN and name are kept
 * BYTE-FOR-BYTE — an ARK name is explicitly case-SENSITIVE per the ARK
 * specification, so "canonicalising" it to a single case would silently
 * corrupt distinct identifiers into collisions.
 *
 * SHAPE VALIDATION happens LAST, against the exact contract this function
 * promises: `ark:/` + 5–9 digit NAAN (the ARK spec's Name Assigning
 * Authority Number range) + `/` + one-or-more printable-ASCII name chars
 * (`!` 0x21 through `~` 0x7E — i.e. any visible ASCII character, no space,
 * matching the ARK spec's tolerance for punctuation/qualifiers inside a
 * name). Anything that doesn't fit — no `ark:/` found at all, a NAAN outside
 * 5–9 digits, or no name segment — is `null`, never a best-effort guess.
 *
 * @param string $raw Curator/importer/URL-pasted input, any of the accepted shapes.
 * @return string|null '' for an empty (still "valid" — ARK is optional
 *                      everywhere it's stored) input, `null` when non-empty
 *                      but no shape-valid ARK could be found, else the
 *                      canonical "ark:/NAAN/name" string.
 * @link https://arks.org/about/what-is-an-ark/          ARK specification (California Digital Library / ARK Alliance)
 * @link https://n2t.net/e/ark_ids.html                   NAAN / ARK syntax reference
 * @see appWeb/public_html/includes/media_identifiers.php PUBLICATION_IDENTIFIER_TYPES['ark'] — the sibling registry entry
 */
function ihymns_canonical_ark(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    /* Percent-decode BEFORE searching — a resolver URL copied out of an
       already-encoded context (e.g. a redirect query string) carries
       "ark%3A%2F13960%2Ft8jf3w89z" rather than "ark:/13960/t8jf3w89z", and
       both must resolve to the identical candidate substring below. */
    $decoded = rawurldecode($raw);

    /* Find the FIRST "ark:/" (case-insensitive scheme token) anywhere in the
       string and take the rest of the line — this is what lets a bare id, a
       full resolver URL, or a decoded percent-encoded URL all converge on
       the same extraction step regardless of what came before it. */
    if (preg_match('/ark:\/.*/i', $decoded, $m) !== 1) {
        return null;
    }
    /* Force the scheme token to lowercase; everything after the 5th
       character (NAAN + '/' + name) is preserved byte-for-byte — an ARK
       name is case-SENSITIVE per spec, unlike ISWC/CCLI/BOWI above. */
    $candidate = 'ark:/' . substr(trim($m[0]), 5);

    if (preg_match('#^ark:/\d{5,9}/[!-~]+$#', $candidate) !== 1) {
        return null;
    }
    return $candidate;
}

/**
 * Canonicalise an OpenLibrary Work or Edition identifier.
 *
 * ELI5: turn a bare "OL102749W" / "ol102749w", or a full
 * "https://openlibrary.org/works/OL102749W/Some-Slug" URL, into the ONE
 * canonical uppercase "OL102749W" — but only if it's the RIGHT kind (a Work
 * id where a Work was asked for, an Edition id where an Edition was asked
 * for). Ask for the wrong kind and you get `null`, not a wrong-shaped
 * answer.
 *
 * DETAILED / WHY A $kind PARAMETER RATHER THAN ONE FUNCTION THAT ACCEPTS
 * EITHER
 * ----------------------------------------------------------------------------
 * OpenLibrary Work ids (`OL…W`) and Edition ids (`OL…M`) are DIFFERENT
 * entities — a Work is the abstract "the hymnal as a bibliographic work"
 * (openlibrary.org/works/…), an Edition is one specific printing
 * (openlibrary.org/books/…). `tblSongbooks`/`tblSongbookSeries`/
 * `tblCatalogues` each get TWO separate nullable columns for this reason
 * (`OpenLibraryWorkId` / `OpenLibraryEditionId` — see the epic plan's
 * migration section), so a curator pasting an Edition URL into the Work
 * field (or vice versa) is a real, easy-to-make mistake this function
 * exists to catch AT THE FOLD, not downstream: `ihymns_canonical_openlibrary
 * ($editionUrl, 'work')` returns `null` rather than silently accepting an
 * Edition id into a Work-typed column.
 *
 * ALGORITHM: if the input contains a `/works/<id>` or `/books/<id>` URL path
 * segment (case-insensitive, an optional trailing `/Some-Slug` ignored —
 * OpenLibrary URLs carry a human-readable slug after the id that is not
 * part of the identifier), extract just `<id>`; otherwise the whole trimmed
 * input is treated as a bare id already. Uppercase it, then validate against
 * EXACTLY the pattern for the requested `$kind` — `/^OL\d+W$/` for 'work',
 * `/^OL\d+M$/` for 'edition'. An id extracted from a `/books/…` URL will
 * never match the 'work' pattern (it ends `M`, not `W`) and vice versa,
 * which is what makes cross-kind input `null` without any extra bookkeeping.
 *
 * @param string $raw  Curator/importer/URL-pasted input, bare id or full URL.
 * @param string $kind 'work' or 'edition' — which OpenLibrary id family the
 *                     caller is asking to validate against.
 * @return string|null '' for an empty input, `null` when non-empty but not a
 *                      shape-valid id of the REQUESTED kind (incl. a
 *                      well-formed id of the OTHER kind), else the canonical
 *                      uppercase "OL<digits><W|M>" string.
 * @throws \InvalidArgumentException when $kind is neither 'work' nor 'edition' — a caller bug, not a data problem.
 * @link https://openlibrary.org/dev/docs/api/books  OpenLibrary identifier shapes (Work vs Edition)
 * @see appWeb/public_html/includes/media_identifiers.php PUBLICATION_IDENTIFIER_TYPES — the sibling registry entries
 */
function ihymns_canonical_openlibrary(string $raw, string $kind): ?string
{
    if ($kind !== 'work' && $kind !== 'edition') {
        throw new \InvalidArgumentException(
            "ihymns_canonical_openlibrary(): \$kind must be 'work' or 'edition', got " . var_export($kind, true)
        );
    }
    $raw = trim($raw);
    if ($raw === '') return '';

    /* A URL: pull just the id out of the /works/<id> or /books/<id> path
       segment (stopping at the next '/', '?' or '#' — a trailing "/Some-
       Slug", query string, or fragment is not part of the identifier). A
       bare id (no such path segment present) passes through untouched. */
    $candidate = $raw;
    if (preg_match('~/(works|books)/([^/?#]+)~i', $raw, $m) === 1) {
        $candidate = $m[2];
    }
    $candidate = strtoupper(trim($candidate));

    $pattern = $kind === 'work' ? '/^OL\d+W$/' : '/^OL\d+M$/';
    return preg_match($pattern, $candidate) === 1 ? $candidate : null;
}

/* NOTE — deliberately NOT registered in IHYMNS_ID_SCHEMES / NOT wired into
   ihymns_normalize_identifier() (Songbook/Catalogue Enhancements epic §Feature
   6, commit 1 of 7).
   ELI5: this file knows how to CLEAN an ARK or OpenLibrary id, but nothing
   yet asks it to.
   DETAILED / WHY: `IHYMNS_ID_SCHEMES` is the registry that drives the public
   `/iswc/ /ccli/ /bowi/ /isrc/ /ipi/ /isni/` alias routes (router.js +
   api.php) AND `tests/test-identifier-routes.js`'s tree-derived route
   coverage (rule #34 — that test walks this registry's keys, so adding one
   here would silently demand a NEW public route + JS wiring this commit
   does not ship). ARK and OpenLibrary ids are being added ONLY as songbook/
   series/catalogue metadata columns (Feature 3) — there is no `/ark/<id>` or
   `/openlibrary/<id>` public resolver page planned yet, and registering the
   scheme here without one would make the route-coverage test demand a route
   that does not exist. If/when a public resolver page for these is scoped,
   add the registry entries AND the routes in the SAME commit — never one
   without the other. See `.claude/songbook-catalogue-enhancements-plan.md`
   "Current-state facts" for this exact deferral decision.
   @see tests/test-identifier-routes.js */

/**
 * Dispatch: canonicalise $raw for the named $scheme.
 *
 * ELI5: "I have a scheme name (a string like 'iswc') and a value someone
 * typed — give me back the ONE canonical form to store/compare/query, or
 * tell me the scheme doesn't exist."
 *
 * DETAILED / WHY: this is the single entry point `includes/identifier_resolve
 * .php` (and any future write path) calls — callers never need to know which
 * per-scheme function to invoke, so adding a SEVENTH scheme later is a
 * two-step change (one `IHYMNS_ID_SCHEMES` entry + one `ihymns_canonical_*()`
 * function + one `case` here), never a change to every call site.
 *
 * @param string $scheme One of the IHYMNS_ID_SCHEMES keys (iswc/ccli/bowi/isrc/ipi/isni).
 * @param string $raw    The curator/importer/URL-decoded raw value.
 * @return string|null   `null` for an unrecognised $scheme OR a malformed
 *                       ISWC (the one scheme with a rejecting fold); '' for
 *                       an empty $raw on every other scheme; else the
 *                       canonical string.
 */
function ihymns_normalize_identifier(string $scheme, string $raw): ?string
{
    if (!array_key_exists($scheme, IHYMNS_ID_SCHEMES)) {
        return null;
    }

    switch ($scheme) {
        case 'iswc':
            return ihymns_canonical_iswc($raw);
        case 'ccli':
            return ihymns_canonical_ccli($raw);
        case 'bowi':
            return ihymns_canonical_bowi($raw);
        case 'isrc':
            return ihymns_canonical_isrc($raw);
        case 'ipi':
            return ihymns_canonical_ipi($raw);
        case 'isni':
            return ihymns_canonical_isni($raw);
        default:
            /* Unreachable: every IHYMNS_ID_SCHEMES key has a case above, and
               the array_key_exists() guard rejects everything else. Kept as
               a defensive fallback rather than an assert so a future scheme
               added to the registry but not yet wired here degrades to
               "unrecognised" instead of a fatal "unhandled match" error. */
            return null;
    }
}
