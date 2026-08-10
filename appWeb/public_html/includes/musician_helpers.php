<?php

declare(strict_types=1);

/**
 * iHymns — Credit-people shared helpers (#719 PR 2d)
 *
 * Single source of truth for the bits both /manage/musicians.php
 * and the admin_musician_* API endpoints share:
 *
 *   - MUSICIAN_LEGACY_SLUG_MAP — back-compat slug → registry-slug
 *     coercion table for /api.php callers that still send the legacy
 *     #586 vocabulary. The /manage/musicians form moved to numeric
 *     LinkTypeId values directly and no longer reads this map.
 *   - resolveMusicianLinkTypeId() — single source of truth for
 *     "given a numeric type_id OR a slug string, find the matching
 *     tblExternalLinkTypes.Id row" used by every write path.
 *   - normaliseMusicianLinks() / normaliseMusicianIpi() —
 *     drop empty rows, resolve link-type slugs / numeric ids to
 *     registry FKs, normalise the row shape into INSERT-ready arrays.
 *   - musicianFlagsColumnsExist() — cached check for the
 *     IsSpecialCase / IsGroup columns from #584/#585. Lets the add /
 *     update paths gracefully no-op the flag writes on a partly-
 *     migrated install (#630).
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Partial-date parser/formatter (the ONE place that turns a curator's
   YYYY / MM/YYYY / DD/MM/YYYY input into a normalised (date, precision)
   pair and back). The musician save + load paths below delegate to
   it so the form, the API and the public page all agree on what a
   year-only birth date means. require_once is idempotent — both
   /manage/musicians.php and /api.php pull this helper file, and
   either may already have included partial_date.php. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'partial_date.php';

/* Shared duplicate/counterpart similarity scorer (#1216, rule #22) —
   pulled in for the musician-registry NAME-similarity additions (#1785):
   ihymns_sim_name_normalise() / ihymns_sim_name_score() (used by
   musicianNameVariantClass() below and by the registry-duplicate scan)
   and the fold primitives they're built on. require_once is idempotent —
   safe even though some callers (manage/duplicate-songs.php,
   manage/tags.php) may already have included this file in the same
   request. Framework-free (no $_SERVER / session reads), so pulling it
   in here doesn't change this file's own dormant-safety story. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_similarity.php';

/**
 * iHymns — shared "read a form/JSON field as a trimmed string" helper (#trim).
 *
 * ELI5: whenever we pull a value a curator typed or pasted (a name, a
 * URL, an ISNI/IPI/VIAF number, a note) out of `$_POST` or a JSON-decoded
 * sub-form row, run it through here FIRST. It strips leading/trailing
 * whitespace — spaces, tabs, newlines (PHP's trim() default charlist,
 * @link https://www.php.net/manual/en/function.trim.php) — so a value
 * pasted with an accidental trailing newline (a common paste artefact
 * from a browser address bar or a spreadsheet cell) never lands in a
 * UNIQUE-keyed identifier column or a stored URL with stray whitespace.
 *
 * DETAILED / WHY a helper instead of inline `trim((string)($x ?? ''))`
 * everywhere: every musician add/update/rename/merge handler in
 * musicians.php, plus every repeating sub-form normaliser below
 * (links / IPI / ISNI / other-identifiers / aliases), needs the exact
 * same idiom on the exact same shape of input. Before this helper the
 * idiom was duplicated by hand at each call site; one typo'd copy (e.g.
 * forgetting the `(string)` cast, or forgetting the call entirely on a
 * newly-added field) would silently reintroduce an un-trimmed gap in
 * exactly one field with no test to catch it. ONE function = one place
 * to audit/fix — never re-duplicate this per field (project modularity
 * rule, see `.claude/CLAUDE.md`).
 *
 * Accepts `mixed` because both `$_POST` values (string|array|null) and
 * JSON-decoded sub-form rows can hand back a non-string scalar (int,
 * bool) for a field a curator left as a bare numeric literal.
 */
function musTrimmed(mixed $v): string
{
    return trim((string)($v ?? ''));
}

/* =========================================================================
 * AUTHORITY-CONTROL IDENTIFIER REGISTRY (#1367)
 *
 * ELI5: a libraries-of-the-world "name tag" for a person. Each big library /
 * music database hands the same writer a unique number (VIAF, Wikidata, GND,
 * …). This table is the ONE place that knows, for each provider: the friendly
 * label, the icon, the public look-up URL, what a valid bare id looks like,
 * and how to pull the bare id back out of a pasted authority URL.
 *
 * WHY a registry (not three hard-coded types): the curator's "Other
 * identifiers" picker, the save-time allow-list + value validation, the
 * public chip link-out, and the paste-a-URL auto-extract all need the SAME
 * truth. Adding a provider is now ONE entry here — no schema change, because
 * tblMusicianIdentifiers.IdentifierType is already VARCHAR(20) and is
 * app-validated (rule #20: a growable vocabulary is VARCHAR + an app-level
 * allow-list, never an ENUM that would force an ALTER).
 *
 * Per-field contract (do NOT re-shape — the JS mirror + four consumers
 * depend on it):
 *   - label     friendly chip / dropdown text.
 *   - group     bucket for the dropdown <optgroup> (International / National /
 *               Academic / People / Industry — emitted in registry order).
 *   - icon      FontAwesome solid icon class (rendered "fa-solid <icon>").
 *   - url        printf template; "%s" is the BARE id, rawurlencode()'d when
 *               the link is built (creditIdentifierDisplayUrl()). NULL when no
 *               public per-id lookup exists (#1741 D5 — e.g. IPN, a rights-
 *               administration number with no consumer-facing database);
 *               mirrors the pre-existing IPI/CAE handling in
 *               includes/pages/musician.php, which already renders a NULL-url
 *               chip as plain (unlinked) text.
 *   - validate  a FULL PHP regex (delimiters included) — true ⇒ the bare id
 *               is well-formed for this provider.
 *   - extract   list of RAW regex bodies (NO delimiters): PHP wraps each as
 *               '#'.$body.'#i' and the JS mirror does new RegExp(body,'i').
 *               The bodies are deliberately PCRE/JS-compatible (capture
 *               group 1 = the bare id). Used to lift the id out of a pasted
 *               authority URL, both server-side (fallback) and client-side
 *               (the primary paste-a-URL UX). Empty array ⇒ no known
 *               pasteable authority URL to extract from (paste-a-URL then
 *               falls through to storing the pasted text verbatim).
 *   - pickable  true ⇒ appears in the Other-Identifiers dropdown AND the save
 *               allow-list. ISNI is false: it has its own dedicated section
 *               (canonicaliseIsni()), so it's display-only here.
 *
 * @link https://www.wikidata.org/wiki/Property:P214  (VIAF)
 * @link https://www.loc.gov/standards/sourcelist/name-title.html
 */
const CREDIT_IDENTIFIER_TYPES = [
  'isni'        => ['label'=>'ISNI',        'group'=>'International','icon'=>'fa-fingerprint',     'url'=>'https://isni.org/isni/%s',                    'validate'=>'/^\d{15}[\dX]$/',                       'extract'=>['isni\.org/isni/(\d{16})'],                                                              'pickable'=>false],
  'viaf'        => ['label'=>'VIAF',        'group'=>'International','icon'=>'fa-id-card',         'url'=>'https://viaf.org/viaf/%s/',                   'validate'=>'/^\d+$/',                               'extract'=>['viaf\.org/viaf/(\d+)'],                                                                 'pickable'=>true],
  'wikidata'    => ['label'=>'Wikidata',    'group'=>'International','icon'=>'fa-database',        'url'=>'https://www.wikidata.org/wiki/%s',            'validate'=>'/^Q\d+$/',                              'extract'=>['wikidata\.org/(?:wiki|entity)/(Q\d+)'],                                                 'pickable'=>true],
  'gnd'         => ['label'=>'GND',         'group'=>'International','icon'=>'fa-landmark',        'url'=>'https://d-nb.info/gnd/%s',                    'validate'=>'/^[0-9X]+(?:-[0-9X]+)?$/',              'extract'=>['d-nb\.info/gnd/([0-9X][0-9X\-]*)', 'portal\.dnb\.de/\S*?(?:gnd[=/]|nid=)([0-9X][0-9X\-]*)'], 'pickable'=>true],
  'fast'        => ['label'=>'FAST',        'group'=>'International','icon'=>'fa-tags',            'url'=>'https://id.worldcat.org/fast/%s',             'validate'=>'/^\d+$/',                               'extract'=>['id\.worldcat\.org/fast/(\d+)', 'fast\.oclc\.org/\S*?fast/(\d+)'],                       'pickable'=>true],
  'worldcat'    => ['label'=>'WorldCat',    'group'=>'International','icon'=>'fa-globe',           'url'=>'https://id.oclc.org/worldcat/entity/%s',      'validate'=>'/^[A-Za-z0-9]+$/',                      'extract'=>['(?:id|entities)\.oclc\.org/worldcat/entity/([A-Za-z0-9]+)'],                             'pickable'=>true],
  'loc'         => ['label'=>'LoC',         'group'=>'National',     'icon'=>'fa-building-columns','url'=>'https://id.loc.gov/authorities/names/%s',     'validate'=>'/^n[a-z]?\d+$/i',                       'extract'=>['id\.loc\.gov/authorities/names/(n[a-z]?\d+)', 'lccn\.loc\.gov/(n[a-z]?\d+)'],          'pickable'=>true],
  'orcid'       => ['label'=>'ORCID',       'group'=>'People',       'icon'=>'fa-circle-nodes',    'url'=>'https://orcid.org/%s',                        'validate'=>'/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/',      'extract'=>['orcid\.org/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])'],                                            'pickable'=>true],
  'idref'       => ['label'=>'IdRef',       'group'=>'People',       'icon'=>'fa-id-badge',        'url'=>'https://www.idref.fr/%s',                     'validate'=>'/^\d{8}[\dX]$/',                        'extract'=>['idref\.fr/(\d{8}[\dX])'],                                                               'pickable'=>true],
  'trove'       => ['label'=>'Trove',       'group'=>'People',       'icon'=>'fa-feather',         'url'=>'https://nla.gov.au/nla.party-%s',             'validate'=>'/^\d+$/',                               'extract'=>['nla\.gov\.au/nla\.party-(\d+)'],                                                        'pickable'=>true],
  'librarything'=> ['label'=>'LibraryThing','group'=>'People',       'icon'=>'fa-book',            'url'=>'https://www.librarything.com/author/%s',      'validate'=>'/^[A-Za-z0-9]+$/',                      'extract'=>['librarything\.com/author/([A-Za-z0-9]+)'],                                              'pickable'=>true],
  'openlibrary' => ['label'=>'Open Library','group'=>'People',       'icon'=>'fa-book-open',       'url'=>'https://openlibrary.org/authors/%s',          'validate'=>'/^OL\d+A$/',                            'extract'=>['openlibrary\.org/authors/(OL\d+A)'],                                                   'pickable'=>true],
  'cinii'       => ['label'=>'CiNii',       'group'=>'Academic',     'icon'=>'fa-graduation-cap',  'url'=>'https://cir.nii.ac.jp/crid/%s',               'validate'=>'/^\d+$/',                               'extract'=>['cir\.nii\.ac\.jp/crid/(\d+)'],                                                          'pickable'=>true],
  /* #1741 D5 — IPN (International Performer Number), the one Luminate party
     ID (media-identifiers-spec.md §4b "Provider (DSP) IDs" list's sibling
     Industry-IDs entry "IPI (interested party)" — IPN is IPI's PERFORMER-side
     counterpart, assigned for neighbouring-rights royalty administration)
     this registry lacked. Unlike ISNI/VIAF/ORCID/…, IPN has no confirmed
     consumer-facing public lookup database (it is a rights-administration
     number, the same reason IPI/CAE below are handled OUTSIDE this registry
     in includes/pages/musician.php) — 'url' is NULL so the chip renders as
     plain (unlinked) text, and 'extract' is empty (no authority URL exists
     to paste-and-detect from). 'validate' is intentionally loose (no
     officially-confirmed digit count, unlike e.g. ORCID's fixed 16-char
     shape) — matches this file's existing convention for "we know it's
     numeric, not the exact length" providers (compare 'trove', 'fast'). */
  'ipn'         => ['label'=>'IPN',         'group'=>'Industry',     'icon'=>'fa-microphone',      'url'=>null,                                           'validate'=>'/^\d+$/',                               'extract'=>[],                                                                                       'pickable'=>true],
];

/**
 * The whole registry. ELI5: hand back the lookup table so callers can read a
 * provider's label / icon / url without re-typing it.
 *
 * @return array<string,array{label:string,group:string,icon:string,url:?string,validate:string,extract:list<string>,pickable:bool}>
 */
function creditIdentifierTypes(): array
{
    return CREDIT_IDENTIFIER_TYPES;
}

/**
 * Only the providers the curator may actually pick + save (pickable===true),
 * in registry order. ELI5: the dropdown + the "is this type allowed?" list.
 * Drives BOTH the <optgroup> dropdown and the save-time allow-list, so the two
 * can never drift.
 *
 * @return array<string,array> slug → registry entry (order preserved)
 */
function creditIdentifierPickable(): array
{
    /* array_filter on an assoc array preserves keys + insertion order, so the
       dropdown groups render in the same order the registry declares them. */
    return array_filter(
        CREDIT_IDENTIFIER_TYPES,
        static fn(array $def): bool => ($def['pickable'] ?? false) === true
    );
}

/**
 * True when $value is a well-formed bare id for $type. ELI5: "does this look
 * like a real VIAF / ORCID / … number?" Unknown type ⇒ false (a hand-crafted
 * POST can't smuggle an unrecognised IdentifierType past this).
 *
 * WHY: the save path rejects a malformed value rather than storing garbage
 * that would later render a dead chip link.
 *
 * @link https://www.php.net/manual/en/function.preg-match.php
 */
function creditIdentifierValidate(string $type, string $value): bool
{
    $reg = CREDIT_IDENTIFIER_TYPES[$type] ?? null;
    if ($reg === null) return false;
    /* validate IS a full PHP regex (delimiters included) — no wrapping. */
    return (bool)preg_match($reg['validate'], $value);
}

/**
 * Normalise a curator's input for $type into the bare id we store. ELI5: the
 * curator may paste EITHER a bare id ("118578537") OR the whole authority URL
 * ("https://d-nb.info/gnd/118578537") into the value box — this turns the URL
 * form into the bare id; a bare id passes straight through (just trimmed).
 *
 * WHY: one value box, two acceptable paste shapes — fewer ways for a curator
 * to get it "wrong". Only attempts URL extraction when the input LOOKS like a
 * URL (contains "://" or a "dot+slash"), so a legitimately slashed bare id is
 * left alone unless the type's own extract pattern matches it.
 */
function creditIdentifierNormalise(string $type, string $value): string
{
    $value = musTrimmed($value); // #trim
    if ($value === '') return $value;
    $reg = CREDIT_IDENTIFIER_TYPES[$type] ?? null;
    if ($reg === null) return $value;

    /* Cheap "could this be a URL?" gate — avoids running the extract regexes on
       an obviously-bare id. preg_match returns 1 when a "<dot><slash>" pair
       appears (e.g. "d-nb.info/…") which is the authority-host shape. */
    $looksLikeUrl = str_contains($value, '://') || preg_match('/\.[^\s\/]*\//', $value) === 1;
    if (!$looksLikeUrl) return $value;

    foreach ($reg['extract'] as $body) {
        /* extract bodies are RAW (no delimiters): wrap '#…#i' here, mirror
           new RegExp(body,'i') client-side. Group 1 = the bare id. */
        if (preg_match('#' . $body . '#i', $value, $m) === 1) {
            return $m[1];
        }
    }
    return $value;
}

/**
 * Scan a pasted URL against EVERY provider's extract patterns; return the
 * first match. ELI5: "this looks like a GND link — pull the GND number out."
 * Used server-side as a FALLBACK; the JS mirror (creditIdentifierClientConfig)
 * is the primary, instant UX when a curator pastes a URL into External Links.
 *
 * @return array{type:string,value:string}|null  null when no provider matches.
 */
function creditIdentifierExtractFromUrl(string $url): ?array
{
    $url = musTrimmed($url); // #trim
    if ($url === '') return null;
    foreach (CREDIT_IDENTIFIER_TYPES as $slug => $def) {
        foreach ($def['extract'] as $body) {
            if (preg_match('#' . $body . '#i', $url, $m) === 1) {
                return ['type' => $slug, 'value' => $m[1]];
            }
        }
    }
    return null;
}

/**
 * Build the public look-up URL for a stored (type, bare id). ELI5: turn
 * ("gnd","118578537") into "https://d-nb.info/gnd/118578537". Unknown type,
 * OR a type with no public lookup at all (#1741 D5 — 'url' === null, e.g.
 * IPN) ⇒ null (the chip then renders as plain text, no link).
 *
 * The bare id is rawurlencode()'d before substitution so an id with reserved
 * characters can't break the URL or smuggle path segments.
 *
 * @link https://www.php.net/manual/en/function.rawurlencode.php
 */
function creditIdentifierDisplayUrl(string $type, string $value): ?string
{
    $reg = CREDIT_IDENTIFIER_TYPES[$type] ?? null;
    if ($reg === null || $reg['url'] === null) return null;
    return sprintf($reg['url'], rawurlencode($value));
}

/**
 * The slim, JSON-serialisable slice the client-side auto-extract needs:
 * every provider's label + its extract pattern BODIES (raw, no delimiters,
 * so the JS does `new RegExp(body,'i')`). Also carries `pickable` so the JS
 * only auto-adds providers the dropdown can actually represent (ISNI is
 * excluded from auto-add — it owns a dedicated section).
 *
 * ELI5: a compact copy of the registry for the browser, so pasting an
 * authority URL into a link field can detect the provider WITHOUT a server
 * round-trip.
 *
 * @return array<string,array{label:string,extract:list<string>,pickable:bool}>
 */
function creditIdentifierClientConfig(): array
{
    $out = [];
    foreach (CREDIT_IDENTIFIER_TYPES as $slug => $def) {
        $out[$slug] = [
            'label'    => $def['label'],
            'extract'  => $def['extract'],
            'pickable' => ($def['pickable'] ?? false) === true,
        ];
    }
    return $out;
}

/**
 * Legacy slug → tblExternalLinkTypes.Slug map for musicians.
 *
 * Preserved purely for backwards compatibility with /api.php
 * admin_musician_add / _update callers that historically sent
 * `links[].type` as a free-text slug string (the #586 catalogue
 * vocabulary: 'apple_music', 'youtube_music', 'twitter', etc.).
 * Mirrors the mapping table from migrate-backfill-musician-links.php
 * so any external consumer can keep sending the old vocabulary
 * while the storage layer moves to numeric LinkTypeId FKs.
 *
 * Anything not in the map resolves to 'other' — same fall-through
 * the backfill migration applies.
 */
if (!defined('IHYMNS_MUSICIAN_LINK_LEGACY_SLUG_MAP_DEFINED')) {
    define('IHYMNS_MUSICIAN_LINK_LEGACY_SLUG_MAP_DEFINED', true);
    define('MUSICIAN_LEGACY_SLUG_MAP', [
        /* Direct passes (the registry slug IS the form slug) */
        'wikipedia'             => 'wikipedia',
        'wikidata'              => 'wikidata',
        'discogs'               => 'discogs',
        'imslp'                 => 'imslp',
        'viaf'                  => 'viaf',
        'linkedin'              => 'linkedin',
        'instagram'             => 'instagram',
        'facebook'              => 'facebook',
        'mastodon'              => 'mastodon',
        'youtube'               => 'youtube',
        'spotify'               => 'spotify',
        'bandcamp'              => 'bandcamp',
        'soundcloud'            => 'soundcloud',
        /* Aliases the #586 catalogue used (and other common variants) */
        'wiki'                  => 'wikipedia',
        'petrucci'              => 'imslp',
        'hymnary'               => 'hymnary-org',
        'hymnary.org'           => 'hymnary-org',
        'hymnary-org'           => 'hymnary-org',
        'website'               => 'official-website',
        'official'              => 'official-website',
        'official-website'      => 'official-website',
        'home'                  => 'official-website',
        'homepage'              => 'official-website',
        'musicbrainz'           => 'musicbrainz-artist',
        'mb'                    => 'musicbrainz-artist',
        'musicbrainz-artist'    => 'musicbrainz-artist',
        'loc'                   => 'loc-name-authority',
        'library-of-congress'   => 'loc-name-authority',
        'loc-name-authority'    => 'loc-name-authority',
        'findagrave'            => 'find-a-grave',
        'find-a-grave'          => 'find-a-grave',
        'goodreads'             => 'goodreads-author',
        'goodreads-author'      => 'goodreads-author',
        'twitter'               => 'twitter-x',
        'x'                     => 'twitter-x',
        'twitter-x'             => 'twitter-x',
        'apple_music'           => 'apple-music',
        'apple-music'           => 'apple-music',
        'itunes'                => 'apple-music',
        'youtube_music'         => 'youtube-music',
        'youtube-music'         => 'youtube-music',
        'archive.org'           => 'internet-archive',
        'archive'               => 'internet-archive',
        'internet-archive'      => 'internet-archive',
        'cyber-hymnal'          => 'cyber-hymnal',
        'cyberhymnal'           => 'cyber-hymnal',
    ]);
}

/**
 * Resolve the `tblExternalLinkTypes.Id` for a musician link row.
 *
 * Accepts either a numeric `type_id` (preferred — the /manage/musicians
 * form uses the shared editor module so values are already registry
 * IDs) or a legacy slug string (`type`) which gets coerced via
 * MUSICIAN_LEGACY_SLUG_MAP and looked up in the registry.
 * Returns null when neither path resolves — the caller drops the
 * row from the normaliser output, matching the pre-#833 behaviour
 * where unknown types collapsed to 'other'.
 */
function resolveMusicianLinkTypeId(\mysqli $db, mixed $rawTypeId, mixed $rawSlug): ?int
{
    static $registryIds = null;
    static $slugToId    = null;
    if ($registryIds === null) {
        $registryIds = [];
        $slugToId    = [];
        try {
            $res = $db->query(
                'SELECT Id, Slug FROM tblExternalLinkTypes WHERE COALESCE(IsActive, 1) = 1'
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $registryIds[(int)$r['Id']]     = true;
                    $slugToId[(string)$r['Slug']]   = (int)$r['Id'];
                }
                $res->close();
            }
        } catch (\Throwable $_e) { /* leave maps empty — caller falls through */ }
    }

    /* New shape — caller already has a numeric id. Trust it provided
       it exists in the active registry. */
    $typeId = (int)($rawTypeId ?? 0);
    if ($typeId > 0 && isset($registryIds[$typeId])) return $typeId;

    /* Legacy shape — coerce the slug via the alias map, then look up. */
    $slugIn = musTrimmed($rawSlug ?? ''); // #trim
    if ($slugIn === '') return null;
    $resolved = MUSICIAN_LEGACY_SLUG_MAP[mb_strtolower($slugIn)] ?? 'other';
    return $slugToId[$resolved] ?? null;
}

/**
 * Normalise the per-person external-link sub-form. Drops empty rows
 * (no URL), resolves either numeric `type_id` (new editor) or legacy
 * `type` slug strings to a `tblExternalLinkTypes.Id`, and returns
 * INSERT-ready row arrays shaped for `tblMusicianExternalLinks`.
 *
 * Accepts either the form-array shape from /manage/musicians
 * (`links[i][type_id|type|url|label]`) or the JSON shape from
 * /api.php (`links[]: {type_id|type, url, label}`).
 *
 * Rows whose type can't be resolved to a registry id are dropped
 * silently — same outcome the pre-#833 normaliser produced for
 * unknown slug strings (it folded them into 'other'; here we
 * require the 'other' row itself to exist in the registry).
 *
 * @param \mysqli $db   Needed to resolve slug → registry id.
 * @param mixed   $raw  Form / JSON-decoded array; non-array → []
 * @return list<array{type_id:int,url:string,label:?string,sort_order:int}>
 */
function normaliseMusicianLinks(\mysqli $db, mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $i => $row) {
        if (!is_array($row)) continue;
        $url = musTrimmed($row['url'] ?? ''); // #trim
        if ($url === '') continue;
        $typeId = resolveMusicianLinkTypeId($db, $row['type_id'] ?? null, $row['type'] ?? null);
        if ($typeId === null) continue;
        $out[] = [
            'type_id'    => $typeId,
            'url'        => $url,
            'label'      => musTrimmed($row['label'] ?? '') ?: null, // #trim
            'sort_order' => (int)($row['sort_order'] ?? $i),
        ];
    }
    return $out;
}

/**
 * Normalise the per-person IPI Name Number sub-form. Drops empty
 * rows (no number) and returns INSERT-ready row arrays.
 *
 * @param mixed $raw Form / JSON-decoded array; non-array → []
 * @return list<array{number:string,name_used:?string,notes:?string}>
 */
function normaliseMusicianIpi(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) continue;
        $num = musTrimmed($row['number'] ?? ''); // #trim
        if ($num === '') continue;
        $out[] = [
            'number'    => $num,
            'name_used' => musTrimmed($row['name_used'] ?? '') ?: null, // #trim
            'notes'     => musTrimmed($row['notes']     ?? '') ?: null, // #trim
        ];
    }
    return $out;
}

/**
 * Normalise the per-person ISNI sub-form. Same row shape as IPI so the
 * unified tblMusicianIdentifiers INSERT path can treat both flows
 * identically — the only thing that differs is the IdentifierType value
 * the caller binds when persisting.
 *
 * ISNI canonical form is "NNNN NNNN NNNN NNNX" — 16 characters total
 * (15 digits + a checksum that may be 'X' to encode value 10), grouped
 * in four blocks separated by single spaces. We normalise to that form
 * on save so search, link-out (https://isni.org/isni/<bare>), and the
 * UNIQUE constraint all collide cleanly regardless of what the curator
 * pasted ("0000-0001-2103-2683", "0000:0001:2103:2683", or just the
 * 16 bare digits all land at "0000 0001 2103 2683").
 *
 * Inputs that don't normalise to the 16-char shape (typo, partial paste,
 * deliberately weird format) are stored as the cleaned uppercased version
 * with all separators stripped — the curator can spot the problem on
 * re-open and fix it. UNIQUE still works because the same bad input
 * normalises to the same cleaned string.
 *
 * @param mixed $raw Form / JSON-decoded array; non-array → []
 * @return list<array{number:string,name_used:?string,notes:?string}>
 */
function normaliseMusicianIsni(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) continue;
        $raw_id = musTrimmed($row['number'] ?? ''); // #trim
        if ($raw_id === '') continue;
        $out[] = [
            'number'    => canonicaliseIsni($raw_id),
            'name_used' => musTrimmed($row['name_used'] ?? '') ?: null, // #trim
            'notes'     => musTrimmed($row['notes']     ?? '') ?: null, // #trim
        ];
    }
    return $out;
}

/**
 * Format an ISNI input string in its canonical "NNNN NNNN NNNN NNNX"
 * shape. Strips every non-[0-9X] character (handles spaces, hyphens,
 * en/em-dashes, colons, dots, NBSP, …) and uppercases. A 16-character
 * cleaned result that matches the ISNI pattern is regrouped into four
 * space-separated blocks of four. Anything else is returned as the
 * cleaned uppercased string so the UNIQUE constraint still collapses
 * duplicate inputs that disagree only on separator style.
 */
function canonicaliseIsni(string $raw): string
{
    /* Uppercase first so the trailing X check-digit lands correctly,
       then drop every non-[0-9X] character. This handles every
       separator a curator might paste without enumerating them. */
    $clean = preg_replace('/[^0-9X]/', '', strtoupper($raw)) ?? '';
    if (preg_match('/^\d{15}[0-9X]$/', $clean) === 1) {
        return substr($clean, 0, 4) . ' '
             . substr($clean, 4, 4) . ' '
             . substr($clean, 8, 4) . ' '
             . substr($clean, 12, 4);
    }
    return $clean;
}

/**
 * Compose a person's display name from the structured columns added in
 * #934. The result is what gets written back into tblMusicians.Name
 * so the ~30 read sites that already query Name continue to see the
 * canonical display string.
 *
 * Empty/null parts collapse cleanly: composePersonName('John', 'Newton', null)
 * returns "John Newton"; ('', 'Anonymous', '') returns "Anonymous"; all-empty
 * returns ''.
 */
function composePersonName(?string $first, ?string $surname, ?string $suffix): string
{
    $parts = array_filter(
        [trim((string)$first), trim((string)$surname), trim((string)$suffix)],
        static fn(string $p): bool => $p !== ''
    );
    return preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '';
}

/**
 * Best-effort split of a free-text Name into [firstNames, surname, suffix].
 * Used by the migration backfill (#934) and any other code that needs to
 * decompose a legacy single-string name on the fly.
 *
 * Heuristic:
 *   - Comma-inverted form ("Wesley, Charles") flips to "Charles Wesley".
 *   - Trailing tokens matching the suffix pattern peel off into Suffix
 *     (one or more — handles "John Smith III PhD").
 *   - The new last token becomes Surname; everything before is FirstNames.
 *   - A single-token name (e.g. "Madonna") goes entirely into Surname so
 *     "ORDER BY Surname" sorts naturally.
 *
 * Returns ['', '', ''] on empty input. Callers that need to handle the
 * "single-name surname might really be a first name" case can inspect
 * the returned firstNames === '' and decide policy.
 *
 * @return array{0:string,1:string,2:string} [firstNames, surname, suffix]
 */
function decomposePersonName(string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return ['', '', ''];
    }
    if (str_contains($name, ',')) {
        $parts = array_map('trim', explode(',', $name, 2));
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $name = $parts[1] . ' ' . $parts[0];
        }
    }
    $tokens = preg_split('/\s+/', $name) ?: [];
    $tokens = array_values(array_filter($tokens, static fn(string $t): bool => $t !== ''));
    if (empty($tokens)) {
        return ['', '', ''];
    }
    $suffixPattern = '/^(?:Jr|Sr|II|III|IV|V|VI|VII|VIII|PhD|Ph\.D\.|MD|M\.D\.|Esq|Esq\.|D\.D\.|D\.Min\.|MA|M\.A\.|BA|B\.A\.)$/i';
    $suffixParts = [];
    while (count($tokens) > 1) {
        $tail     = $tokens[count($tokens) - 1];
        $tailNorm = rtrim($tail, '.,');
        if (preg_match($suffixPattern, $tail) || preg_match($suffixPattern, $tailNorm)) {
            array_unshift($suffixParts, array_pop($tokens));
            continue;
        }
        break;
    }
    $suffix = implode(' ', $suffixParts);
    if (count($tokens) === 1) {
        return ['', $tokens[0], $suffix];
    }
    $surname    = (string)array_pop($tokens);
    $firstNames = implode(' ', $tokens);
    return [$firstNames, $surname, $suffix];
}

/**
 * Cached check for the FirstNames / Surname / Suffix columns from #934.
 * Mirrors musicianFlagsColumnsExist()'s pattern — admin INSERT /
 * UPDATE paths gate the new columns on this so a partly-migrated
 * install still saves rather than throwing "Unknown column".
 */
function musicianNamePartsColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblMusicians'
                AND COLUMN_NAME  = 'Surname' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Cached check for the MaidenSurname column (#1501,
 * migrate-add-creditperson-maiden-surname.php). Mirrors
 * musicianNamePartsColumnsExist()'s pattern exactly — the add /
 * update_person save paths gate the write on this so a partly-migrated
 * install still saves the rest of the record rather than throwing
 * "Unknown column", and the list-load query gates the SELECT the same
 * way (falls back to `NULL AS MaidenSurname`).
 */
function musicianMaidenSurnameColumnExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblMusicians'
                AND COLUMN_NAME  = 'MaidenSurname' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Persist MaidenSurname for a musician row, in a SEPARATE statement
 * from the main multi-branch INSERT/UPDATE — mirrors the per-place
 * BirthPlaceId/DeathPlaceId UPDATE pattern and musicianSaveDatePrecision()
 * below: the giant column-existence-branched INSERT/UPDATE shapes in
 * musicians.php don't need to learn a new branch for one more
 * optional column. No-op (gated on musicianMaidenSurnameColumnExists())
 * on an un-migrated install — dormant-safe (#1501).
 *
 * @param int         $personId      tblMusicians.Id (insert_id on create).
 * @param string|null $maidenSurname Trimmed value, or null to clear.
 */
function musicianSaveMaidenSurname(\mysqli $db, int $personId, ?string $maidenSurname): void
{
    if ($personId <= 0) return;
    if (!musicianMaidenSurnameColumnExists($db)) return;
    $stmt = $db->prepare('UPDATE tblMusicians SET MaidenSurname = ? WHERE Id = ?');
    $stmt->bind_param('si', $maidenSurname, $personId);
    $stmt->execute();
    $stmt->close();
}

/**
 * #1500 — merge-target candidate search. Replaces the old client-side
 * "every registry + in-use-only name in one giant <select>" Merge-modal
 * Target picker (unusable once the registry passed a few hundred rows)
 * with a server-filtered, capped result set for a live-search typeahead.
 *
 * ELI5: the curator types a few letters of the name they want to merge
 * INTO; this returns a short, relevant list instead of every person in
 * the whole catalogue.
 *
 * DETAILED: returns the SAME two-source shape the legacy client-side
 * `registry` array offered — registry rows (tblMusicians) first
 * (alphabetical), then in-use-only names (cited on a song but with no
 * registry row yet — the same 5-table UNION the page's own list-load
 * query uses) filling any remaining slots up to $limit, ordered by
 * usage. Each candidate carries the SAME "id:N" / "name:X" `key` shape
 * the old <select> option values carried, so the client's submit-time
 * routing (which hidden field gets the pick) is UNCHANGED. $excludeId /
 * $excludeName keep the source out of its own target list (a source not
 * yet in the registry has $excludeId = 0, so the NOT EXISTS + `<> ?`
 * name filters below are what actually excludes it). Empty $q surfaces
 * the most-used names first — same "useful before typing" convention as
 * /manage/songbooks's compiler_search / parent_search.
 *
 * @return list<array{key:string,id:?int,name:string,total:int}>
 */
function searchMusicianMergeTargets(
    \mysqli $db,
    string  $q,
    int     $excludeId,
    string  $excludeName,
    int     $limit
): array {
    $out  = [];
    $like = '%' . $q . '%';

    /* Registry rows first — alphabetical, matching the legacy dropdown's
       ordering for the "definitely a real person" bucket. */
    if ($q === '') {
        $stmt = $db->prepare('SELECT Id, Name FROM tblMusicians WHERE Id <> ? ORDER BY Name ASC LIMIT ?');
        $stmt->bind_param('ii', $excludeId, $limit);
    } else {
        $stmt = $db->prepare('SELECT Id, Name FROM tblMusicians WHERE Id <> ? AND Name LIKE ? ORDER BY Name ASC LIMIT ?');
        $stmt->bind_param('isi', $excludeId, $like, $limit);
    }
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $name = (string)$r['Name'];
        if ($excludeName !== '' && $name === $excludeName) continue; // defensive; different id in practice
        $out[] = ['key' => 'id:' . (int)$r['Id'], 'id' => (int)$r['Id'], 'name' => $name, 'total' => 0];
    }

    $remaining = $limit - count($out);
    if ($remaining <= 0) return $out;

    /* In-use-only names — cited on a song, no registry row yet. Same
       5-table union the page's own list-load query (Q1) already runs on
       every page load; NOT EXISTS keeps registry rows (already returned
       above) out of this bucket so nothing is duplicated. */
    $usageSql = "
        SELECT u.Name, SUM(u.cnt) AS TotalUsage
          FROM (
              SELECT Name, COUNT(*) AS cnt FROM tblSongWriters     GROUP BY Name
              UNION ALL
              SELECT Name, COUNT(*) AS cnt FROM tblSongComposers   GROUP BY Name
              UNION ALL
              SELECT Name, COUNT(*) AS cnt FROM tblSongArrangers   GROUP BY Name
              UNION ALL
              SELECT Name, COUNT(*) AS cnt FROM tblSongAdaptors    GROUP BY Name
              UNION ALL
              SELECT Name, COUNT(*) AS cnt FROM tblSongTranslators GROUP BY Name
          ) u
         WHERE NOT EXISTS (SELECT 1 FROM tblMusicians p WHERE p.Name = u.Name)
           AND u.Name <> ?"
        . ($q !== '' ? ' AND u.Name LIKE ?' : '') . "
         GROUP BY u.Name
         ORDER BY TotalUsage DESC, u.Name ASC
         LIMIT ?
    ";
    $stmt = $db->prepare($usageSql);
    if ($q !== '') {
        $stmt->bind_param('ssi', $excludeName, $like, $remaining);
    } else {
        $stmt->bind_param('si', $excludeName, $remaining);
    }
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $name = (string)$r['Name'];
        $out[] = ['key' => 'name:' . $name, 'id' => null, 'name' => $name, 'total' => (int)$r['TotalUsage']];
    }
    return $out;
}

/* =========================================================================
 * PARTIAL BIRTH / DEATH DATES (precision flags)
 *
 * Historical writers often have only a known YEAR (or month + year) of
 * birth / death. The `BirthDate` / `DeathDate` columns stay `DATE` (so they
 * still sort + range-query), the partial is normalised to the FIRST of the
 * period, and a precision flag ('year' | 'month' | 'day' — VARCHAR, NULL
 * when no date) records how much of it is real. See
 * migrate-add-creditpeople-date-precision.php + includes/partial_date.php.
 *
 * Every helper below is EXISTENCE-GATED on the precision columns: migrations
 * are web-run (NOT auto-applied), so a partly-migrated install must not be
 * able to read / write a column that doesn't exist — mysqli runs STRICT
 * (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT) so an "Unknown column" would
 * THROW and white-screen the page. On an un-migrated install these helpers
 * no-op cleanly: the date still saves to the DATE column, the precision is
 * simply not recorded and the input round-trips as a full date.
 * ========================================================================= */

/**
 * Cached probe for the BirthDatePrecision / DeathDatePrecision columns
 * (migrate-add-creditpeople-date-precision.php). Both ship together, so
 * detecting BirthDatePrecision is sufficient to assume DeathDatePrecision.
 * Mirrors musicianPlaceIdColumnsExist() in includes/places.php — the
 * add / update / API save paths gate the precision write on this so a
 * partly-migrated install saves the date without throwing "Unknown column".
 * Static-cached for the request lifetime so the INFORMATION_SCHEMA round-trip
 * happens at most once.
 *
 * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html
 */
function musicianDatePrecisionColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblMusicians'
                AND COLUMN_NAME  = 'BirthDatePrecision' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Persist the birth / death date precision flags for a musician row,
 * in a SEPARATE statement from the main INSERT / UPDATE (mirroring the
 * per-place BirthPlaceId / DeathPlaceId UPDATE pattern). The multi-branch
 * INSERT shapes in musicians.php / api.php don't have to learn about
 * the precision columns — this runs straight after, gated on existence.
 *
 * Both values bind as 's' (string); a NULL precision (no date) binds fine
 * via mysqli's NULL handling and lands as SQL NULL, matching the column's
 * "NULL when no date" semantics. No-op on an un-migrated install.
 *
 * @param int          $personId  tblMusicians.Id (insert_id on create).
 * @param string|null  $birthPrec 'year' | 'month' | 'day' | null
 * @param string|null  $deathPrec 'year' | 'month' | 'day' | null
 */
function musicianSaveDatePrecision(
    \mysqli $db,
    int     $personId,
    ?string $birthPrec,
    ?string $deathPrec
): void {
    if ($personId <= 0) return;
    if (!musicianDatePrecisionColumnsExist($db)) return;
    $stmt = $db->prepare(
        'UPDATE tblMusicians
            SET BirthDatePrecision = ?, DeathDatePrecision = ?
          WHERE Id = ?'
    );
    $stmt->bind_param('ssi', $birthPrec, $deathPrec, $personId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Format a stored (date, precision) pair back into the editor-input form
 * (YYYY / MM/YYYY / DD/MM/YYYY) so a partial date round-trips through the
 * drawer's text field. When the precision column exists we use the stored
 * precision; on an un-migrated install there is no precision to read, so we
 * treat the row as a full date ('day') — a legacy full date renders as
 * DD/MM/YYYY, which is correct, and a (rare) year-only legacy date would
 * render as e.g. "01/01/1823", which is the honest representation of what
 * is actually stored before the migration runs.
 *
 * @return string Empty string when $date is NULL / unparseable.
 */
function musicianDateInput(\mysqli $db, ?string $date, ?string $precision): string
{
    if (musicianDatePrecisionColumnsExist($db)) {
        return partialDateFormatInput($date, $precision);
    }
    return partialDateFormatInput($date, 'day');
}

/**
 * Slugify a person's name for the URL component. Lower-cases, strips
 * combining marks, collapses non-letter/digit runs to single hyphens.
 * Mirrors the migration-time slugify in migrate-musicians-slug.php
 * — when both implementations drift, the backfill stops being idempotent
 * with new inserts.
 *
 * Returns '' for an empty / punctuation-only name; callers should fall
 * back to a stable default (e.g. 'person') in that case.
 */
function slugifyMusicianName(string $name): string
{
    $s = mb_strtolower(trim($name));
    if (class_exists('Normalizer')) {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        $s = preg_replace('/\p{M}+/u', '', $s) ?? '';
    }
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
    return trim($slug, '-');
}

/**
 * iHymns — legacy /writer/<name-slug> → tblMusicians registry-slug resolver (#1741 P4a-3).
 *
 * ELI5: an old-style writer link like /writer/charles-h.-gabriel doesn't
 * automatically match the registry's own slug for that person
 * (charles-h-gabriel). This is the "try a few reasonable guesses, in a
 * fixed order" plan-builder those guesses are made from.
 *
 * DETAILED / WHY: inverts the ONE fold every emitter of a name-slug has
 * ever used — `strtolower(str_replace(' ', '-', $name))` (the historic
 * sitemap.xml.php:153 writer-page fold, and the CURRENT credit-link fold
 * at song.php:700/:1252) — into an ORDERED list of candidate registry
 * slugs and candidate registry/credited Names, consumed by
 * `musicianResolveLegacySlug()` below. Reused by THREE call sites
 * (index.php's top-level 301, api.php's `case 'writer'` via
 * musician.php, and musician.php's own no-registry-row fallback) so the
 * name/slug variant fold exists exactly once in this codebase — the
 * modularity rule (CLAUDE.md, "reuse a shared module — do not
 * duplicate") and rule #22 ("never re-fork the normalise … fold").
 *
 * Deliberately PURE (no DB, no I/O) so `musicianResolveLegacySlug()` and
 * this plan builder are unit-testable with zero fixtures — the #1689
 * `songRedirectFollow()` pure/driver split this mirrors.
 *
 * @param string $slug ALREADY-URLDECODED slug segment (callers decode
 *                      exactly once — see the A11 double-decode note in
 *                      .claude/catalogue-1741-P4a3-plan.md §2).
 * @return array{slugs: list<string>, names: list<string>}
 *   `slugs` — ordered candidate registry `tblMusicians.Slug` values:
 *             the raw input first (the common case — most slugs already
 *             match), then the `slugifyMusicianName()` punctuation/
 *             diacritic fold (dedupe-guarded so a no-op fold doesn't
 *             double an entry).
 *   `names` — ordered candidate credited/registry `Name` values: the
 *             title-cased spaced form, the plain spaced form, then the
 *             raw slug verbatim (the "Smith-Jones" case — a hyphenated
 *             credited name whose slug IS its name, so spacing the
 *             hyphen away would never match). Deduped case-insensitively
 *             via `mb_strtolower()`, matching the tables' utf8mb4 CI
 *             collation so a caller never binds the same value twice.
 * @link https://www.php.net/manual/en/function.mb-convert-case.php MB_CASE_TITLE
 * @see slugifyMusicianName() the punctuation/diacritic fold this reuses (rung 2)
 */
function musicianLegacySlugPlan(string $slug): array
{
    $slug   = trim($slug);
    $spaced = str_replace('-', ' ', $slug);

    /* Rung order: exact slug first (cheapest, most common hit), the
       slugify() fold second — see musicianResolveLegacySlug()'s doc-block
       for why this order matters. */
    $slugs = [];
    foreach ([$slug, slugifyMusicianName($slug)] as $c) {
        if ($c !== '' && !in_array($c, $slugs, true)) { $slugs[] = $c; }
    }

    $names = [];
    $seen  = [];
    foreach ([mb_convert_case($spaced, MB_CASE_TITLE, 'UTF-8'), $spaced, $slug] as $c) {
        $c = trim($c);
        $k = mb_strtolower($c);
        if ($c === '' || isset($seen[$k])) { continue; }
        $seen[$k] = true;
        $names[]  = $c;
    }
    return ['slugs' => $slugs, 'names' => $names];
}

/**
 * iHymns — pure ladder walker for the legacy-slug resolution plan (#1741 P4a-3).
 *
 * ELI5: given the list of guesses from `musicianLegacySlugPlan()`, try
 * each one in order using whatever lookup function the caller hands in,
 * and stop at the first hit.
 *
 * DETAILED / WHY: the lookups are INJECTED CALLABLES rather than a
 * `\mysqli $db` parameter — the same seam `songRedirectFollow()`
 * (`song_redirects.php:94-117`) uses, and for the same reason: a
 * behaviour with a runtime handle is what
 * `tests/php/test-song-redirect-claim.php`'s doc-block calls "a property
 * a test could simply have CALLED", so `tests/php/test-musician-slug-
 * resolve.php` can assert the *order* rungs are tried in (spy closures
 * that record every candidate they were asked about) with zero database
 * and zero fixtures.
 *
 * Ladder order (load-bearing — do not reorder without updating both this
 * doc-block and the mutation-proof row in the P4a-3 build spec §4.3):
 *   1. Exact registry slug — resolves the overwhelming common case (a
 *      simple name) in one indexed hit, AND makes the whole ladder
 *      idempotent: feeding an ALREADY-canonical registry slug back
 *      through resolves to itself, so leg A (index.php) can never loop.
 *   2. Punctuation/diacritic-folded slug (`slugifyMusicianName()`) —
 *      the SAME fold `generateUniqueMusicianSlug()` and the migration
 *      backfill use (rule #22: never re-fork it). Closes the writer-fold
 *      ↔ registry-fold gap for names like "Charles H. Gabriel".
 *   3. Registry `Name` match (only tried once BOTH slug rungs miss) —
 *      covers collision-suffixed slugs (`john-smith-2`) and the raw-slug
 *      "Smith-Jones" case. `$byName` receives the FULL deduped name list
 *      in one call so the driver can express it as one `Name IN (…)`
 *      query (never N round-trips).
 *   4. `tblMusicianAliases` name match (#1754) — tried ONLY after every
 *      registry rung (1-3) misses, so an alias can never outrank the
 *      registry's own slug/name. Lands only together with the discography
 *      widening in `includes/pages/musician.php` — see that file's
 *      `$creditNames` (a redirected alias URL must list at least what the
 *      old name-fallback listed for that alias, or the redirect "loses
 *      songs").
 *
 * @param callable(string):?string       $bySlug Exact `tblMusicians.Slug`
 *        lookup for one candidate slug. Returns the canonical slug on a
 *        hit (normally the same string it was asked about), or null/''
 *        on a miss — both treated as "no match, try the next rung".
 * @param callable(list<string>):?string $byName `tblMusicians.Name IN (…)`
 *        lookup across the WHOLE candidate name list at once. Returns
 *        the matched row's canonical Slug, or null/'' on a miss.
 * @param string $slug ALREADY-URLDECODED slug segment (see
 *        `musicianLegacySlugPlan()`'s doc-block).
 * @param ?callable(list<string>):?string $byAliasName #1754 rung 4 —
 *        `tblMusicianAliases.Name IN (…)` lookup across the SAME deduped
 *        name candidate list `$byName` receives, in one call. Returns the
 *        owning musician's canonical Slug, or null/'' on a miss. Omit (or
 *        pass null) to keep the pre-#1754 3-rung ladder — an un-migrated
 *        install / opted-out caller behaves exactly as before.
 * @return ?string The canonical `tblMusicians.Slug`, or null when every
 *         rung misses (the caller then degrades to the pre-#1741-P4a-3
 *         name-based fallback — never a 404 for a legacy URL that used
 *         to answer, per rule #33).
 */
function musicianResolveLegacySlug(callable $bySlug, callable $byName, string $slug, ?callable $byAliasName = null): ?string
{
    $plan = musicianLegacySlugPlan($slug);
    foreach ($plan['slugs'] as $cand) {
        $hit = $bySlug($cand);
        if (is_string($hit) && $hit !== '') { return $hit; }
    }
    if ($plan['names'] !== []) {
        $hit = $byName($plan['names']);
        if (is_string($hit) && $hit !== '') { return $hit; }
        /* #1754 — rung 4: tblMusicianAliases name match, tried ONLY after
           every registry rung misses (an alias must never outrank the
           registry's own slug/name). Null = rung absent (un-migrated
           install / caller opt-out) — ladder behaves exactly as pre-#1754.
           Placed INSIDE the `if ($plan['names'] !== [])` block: aliases key
           off the same name-candidate list, and an empty plan has nothing
           to ask. */
        if ($byAliasName !== null) {
            $hit = $byAliasName($plan['names']);
            if (is_string($hit) && $hit !== '') { return $hit; }
        }
    }
    return null;
}

/**
 * iHymns — DB driver for the legacy-slug ladder (#1741 P4a-3).
 *
 * ELI5: the real, database-backed version of the guess-and-check above —
 * plugs actual `tblMusicians` lookups into `musicianResolveLegacySlug()`.
 * If anything goes wrong (old install, DB hiccup), it just says "I don't
 * know" instead of crashing the page.
 *
 * DETAILED / WHY: FAIL-OPEN by design, mirroring the
 * `songRedirectClaimsId()` reasoning at `song_redirects.php:186-208` —
 * a pre-`Slug`-migration install (no `Slug` column, or the column exists
 * but is empty on every row), an absent `tblMusicians` table, or a
 * transient query failure must never surface as a fatal on a public
 * page; they all answer `null`, and every caller (index.php's 301
 * branch, api.php's `case 'writer'` → musician.php, musician.php's own
 * lookup) then degrades to the SAME name-based fallback the un-migrated
 * install has always used (CLAUDE.md red-flag list: a
 * `mysqli_sql_exception` under STRICT mode must be caught at a page-load
 * boundary, never left to white-screen — the #1228 lesson).
 *
 * Both closures use bound parameters exclusively (`bind_param('s', …)`
 * / `str_repeat('s', count($names))` for the `Name IN (…)` placeholder
 * count) — the ONLY sanctioned string interpolation into SQL here is the
 * placeholder-count-derived `?,?,?` string itself (CLAUDE.md SQL rule).
 *
 * @param \mysqli $db  Live connection from `getDbMysqli()`.
 * @param string  $slug ALREADY-URLDECODED slug segment.
 * @return ?string The canonical `tblMusicians.Slug`, or null on a miss
 *         OR any failure (schema-tolerant, never throws).
 * @see musicianResolveLegacySlug() the pure ladder this wires real lookups into
 */
function musicianResolveLegacySlugDb(\mysqli $db, string $slug): ?string
{
    try {
        /* #1754 — ELI5: build the 4th rung's real lookup ONLY when the
           aliases table actually exists on this install; otherwise pass
           null so the ladder behaves exactly as it did before #1754.
           DETAILED / WHY: musicianAliasesTableExists($db) (:1461) is
           already cached + schema-tolerant — reused here rather than a
           second existence probe (rule #22's "one fold" posture applied to
           existence gates too). Column names verified against
           replaceMusicianAliases()/loadMusicianAliases() (:1534/
           :1541-1544/:1575): tblMusicianAliases(MusicianId, Name, …).
           Placeholder string from count() — rule #5's sanctioned shape.
           Deterministic tie-break ORDER BY m.Id, a.Id mirrors the name
           rung's ORDER BY Id ASC LIMIT 1 posture. @see #1754 */
        $byAlias = musicianAliasesTableExists($db)
            ? static function (array $names) use ($db): ?string {
                $ph = implode(',', array_fill(0, count($names), '?'));
                $st = $db->prepare(
                    "SELECT m.Slug FROM tblMusicianAliases a
                       JOIN tblMusicians m ON m.Id = a.MusicianId
                      WHERE a.Name IN ($ph) AND m.Slug IS NOT NULL AND m.Slug <> ''
                      ORDER BY m.Id ASC, a.Id ASC LIMIT 1"
                );
                $st->bind_param(str_repeat('s', count($names)), ...$names);
                $st->execute();
                $row = $st->get_result()->fetch_row();
                $st->close();
                return $row !== null ? (string)$row[0] : null;
            }
            : null;

        return musicianResolveLegacySlug(
            static function (string $cand) use ($db): ?string {
                $st = $db->prepare('SELECT Slug FROM tblMusicians WHERE Slug = ? LIMIT 1');
                $st->bind_param('s', $cand);
                $st->execute();
                $row = $st->get_result()->fetch_row();
                $st->close();
                return ($row !== null && (string)$row[0] !== '') ? (string)$row[0] : null;
            },
            static function (array $names) use ($db): ?string {
                /* Placeholder string built from count() — a hardcoded
                   constant shape, never user input (rule #5's
                   sanctioned interpolation: array_fill(0, count(...),
                   '?') joined). */
                $ph = implode(',', array_fill(0, count($names), '?'));
                $st = $db->prepare(
                    "SELECT Slug FROM tblMusicians
                      WHERE Name IN ($ph) AND Slug IS NOT NULL AND Slug <> ''
                      ORDER BY Id ASC LIMIT 1"
                );
                $st->bind_param(str_repeat('s', count($names)), ...$names);
                $st->execute();
                $row = $st->get_result()->fetch_row();
                $st->close();
                return $row !== null ? (string)$row[0] : null;
            },
            $slug,
            $byAlias
        );
    } catch (\Throwable $_e) {
        return null;
    }
}

/**
 * Generate a unique slug for a tblMusicians row.
 *
 * Computes the base slug from $name, then appends -2 / -3 / … until the
 * candidate isn't already taken. When $excludeId is provided, the row
 * with that Id is excluded from the collision check — needed by UPDATE
 * paths so a curator renaming a row doesn't collide with the row's own
 * existing slug.
 *
 * Returns 'person' (or 'person-2', etc.) when the input slugifies to
 * empty — keeps the UNIQUE constraint satisfied without a NOT NULL
 * violation, and stays consistent with the migration's fallback.
 *
 * Schema-tolerant: if the Slug column doesn't exist yet (pre-migration
 * install), returns '' so callers can omit the column from the INSERT.
 */
function generateUniqueMusicianSlug(\mysqli $db, string $name, ?int $excludeId = null): string
{
    /* If the column hasn't been added yet, no slug is needed. */
    static $hasSlugCol = null;
    if ($hasSlugCol === null) {
        try {
            $probe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblMusicians'
                    AND COLUMN_NAME  = 'Slug' LIMIT 1"
            );
            $probe->execute();
            $hasSlugCol = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) {
            $hasSlugCol = false;
        }
    }
    if (!$hasSlugCol) return '';

    $base = slugifyMusicianName($name);
    if ($base === '') $base = 'person';

    /* Pull all taken slugs that share this base prefix in one query.
       VARCHAR(255) with utf8mb4_unicode_ci, indexed via idx_Slug + the
       uk_Slug unique key — the LIKE scan stays fast even on a large
       registry. */
    $like = $base . '%';
    if ($excludeId !== null) {
        $stmt = $db->prepare('SELECT Slug FROM tblMusicians WHERE Slug LIKE ? AND Id <> ?');
        $stmt->bind_param('si', $like, $excludeId);
    } else {
        $stmt = $db->prepare('SELECT Slug FROM tblMusicians WHERE Slug LIKE ?');
        $stmt->bind_param('s', $like);
    }
    $stmt->execute();
    $taken = [];
    $res   = $stmt->get_result();
    while ($r = $res->fetch_row()) { $taken[(string)$r[0]] = true; }
    $stmt->close();

    if (!isset($taken[$base])) return $base;
    $suffix = 2;
    while (isset($taken[$base . '-' . $suffix])) { $suffix++; }
    return $base . '-' . $suffix;
}

/**
 * Idempotent "register this name in the registry" helper.
 *
 * Looks up tblMusicians by Name and returns the existing Id if a row
 * already matches. Otherwise INSERTs a new row carrying:
 *   - Name        (the trimmed input)
 *   - Slug        (computed via generateUniqueMusicianSlug if the
 *                  column exists)
 *   - FirstNames  | OPTIONAL — only set when the name-parts columns
 *   - Surname     | from PR #935 are present AND $parts is supplied;
 *   - Suffix      | otherwise these are simply omitted from the INSERT
 *
 * Returns the row's Id (existing or new). Returns 0 only when $name is
 * empty — callers should guard.
 *
 * This is the canonical insertion point for the registry — every other
 * path that previously called `INSERT INTO tblMusicians (Name)` and
 * silently relied on `NOT NULL DEFAULT ''` Slug semantics MUST route
 * through here, or it will trip the `Duplicate entry '' for uk_Slug`
 * UNIQUE collision the moment an orphan empty-Slug row exists.
 */
function registerMusicianByName(
    \mysqli $db,
    string  $name,
    ?array  $parts = null
): int {
    $name = musTrimmed($name); // #trim
    if ($name === '') return 0;

    /* Fast path: row already exists. */
    $stmt = $db->prepare('SELECT Id FROM tblMusicians WHERE Name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($row) return (int)$row[0];

    /* Slug is computed even if the column is technically nullable —
       generateUniqueMusicianSlug() returns '' when the column
       isn't present yet (pre-migration), in which case we just omit
       it from the INSERT. */
    $slug          = generateUniqueMusicianSlug($db, $name);
    $hasSlugCol    = $slug !== '';
    $hasNamePartCols = musicianNamePartsColumnsExist($db);
    $first  = $parts['first']   ?? null;
    $surname= $parts['surname'] ?? null;
    $suffix = $parts['suffix']  ?? null;
    if ($first  !== null && trim((string)$first)  === '') $first  = null;
    if ($surname!== null && trim((string)$surname)=== '') $surname= null;
    if ($suffix !== null && trim((string)$suffix) === '') $suffix = null;

    if ($hasSlugCol && $hasNamePartCols) {
        $sql   = 'INSERT INTO tblMusicians (Name, Slug, FirstNames, Surname, Suffix) VALUES (?, ?, ?, ?, ?)';
        $stmt  = $db->prepare($sql);
        $stmt->bind_param('sssss', $name, $slug, $first, $surname, $suffix);
    } elseif ($hasSlugCol) {
        $sql   = 'INSERT INTO tblMusicians (Name, Slug) VALUES (?, ?)';
        $stmt  = $db->prepare($sql);
        $stmt->bind_param('ss', $name, $slug);
    } elseif ($hasNamePartCols) {
        $sql   = 'INSERT INTO tblMusicians (Name, FirstNames, Surname, Suffix) VALUES (?, ?, ?, ?)';
        $stmt  = $db->prepare($sql);
        $stmt->bind_param('ssss', $name, $first, $surname, $suffix);
    } else {
        $sql   = 'INSERT INTO tblMusicians (Name) VALUES (?)';
        $stmt  = $db->prepare($sql);
        $stmt->bind_param('s', $name);
    }
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    return $newId;
}

/**
 * iHymns — normalise one credit-entry payload into a uniform shape (#960).
 *
 * ELI5: a credit (a writer/composer/arranger/… name) can arrive from the
 * client as either a plain string ("John Newton") or a structured object
 * with separate first-names/surname/suffix boxes. This is the ONE place
 * that turns either shape into the same four-field array, so every
 * caller downstream — the role-table INSERT, the registry promote —
 * only ever has to handle one shape.
 *
 * DETAILED / WHY: extracted verbatim from the `$normaliseCreditEntry`
 * closure that used to live inline inside `editorSaveSongCore()`
 * (`manage/editor/save_song_core.php`, the legacy whole-song save) so the
 * v2 editor's granular `credit_upsert` endpoint (`manage/editor/api2.php`)
 * and other per-credit write paths can share the EXACT same
 * decompose/compose behaviour instead of re-forking it. This is the
 * "(c)" half of the #960 fix: the v2 editor's flat text-only credit UI
 * saved via `credit_upsert`, which read only `$credit['name']` and wrote
 * only the role table — it never ran this normalisation, so a curator's
 * `{first, surname, suffix}` payload (once the v2 UI is ported) had
 * nowhere to be reassembled server-side. Centralising it here means PHP
 * stays the single source of truth for the name maths (the browser-side
 * mirror lives in `editor.js`'s `composePersonNameJs`/`decomposePersonNameJs`,
 * which is retired with the legacy editor per #1601 scope 3 — see
 * `.claude/CLAUDE.md`'s modularity rule: extract first, reuse everywhere).
 *
 * Body is a byte-for-byte move of the `$normaliseCreditEntry` closure —
 * no logic changes, only closure → named function.
 *
 * @param mixed $v Either a trimmed name string, or an array shaped
 *                  {name?, first?, surname?, suffix?} (a JSON-decoded
 *                  request body, or a PHP array built server-side).
 * @return array{name:string,first:string,surname:string,suffix:string}|null
 *               null when the entry normalises to an empty name.
 */
function creditEntryNormalise(mixed $v): ?array
{
    if (is_string($v)) {
        $name = trim($v);
        if ($name === '') return null;
        [$first, $surname, $suffix] = decomposePersonName($name);
        return ['name' => $name, 'first' => $first, 'surname' => $surname, 'suffix' => $suffix];
    }
    if (!is_array($v)) return null;
    $first   = trim((string)($v['first']   ?? ''));
    $surname = trim((string)($v['surname'] ?? ''));
    $suffix  = trim((string)($v['suffix']  ?? ''));
    /* Prefer a client-composed `name` for byte-equal
       round-tripping; otherwise compose from parts. If
       parts are empty and the only thing the client
       sent is a `name` string, decompose it. */
    $name = trim((string)($v['name'] ?? ''));
    if ($name === '') {
        $name = composePersonName($first, $surname, $suffix);
    } elseif ($first === '' && $surname === '' && $suffix === '') {
        [$first, $surname, $suffix] = decomposePersonName($name);
    }
    if ($name === '') return null;
    return ['name' => $name, 'first' => $first, 'surname' => $surname, 'suffix' => $suffix];
}

/**
 * iHymns — idempotent registry promote + never-overwrite parts backfill (#960).
 *
 * ELI5: whenever a credit name (writer, composer, arranger…) is saved
 * anywhere in the app, this is the ONE function that makes sure that name
 * also exists as a row in the `tblMusicians` registry — the table that
 * powers the public `/musician/<slug>` page, aliases, identifiers and
 * links. Call it with a name (and, if you have them, the first/surname/
 * suffix parts) and it either finds the existing row or creates one, then
 * quietly fills in any BLANK structured-name columns. It never
 * overwrites a value a curator already typed on `/manage/musicians`.
 *
 * DETAILED / WHY: before #960 this promote-and-backfill pairing lived
 * only inline inside `editorSaveSongCore()` (the whole-song save reached
 * by the legacy v1 editor, and by `api2.php`'s back-compat `save_song`
 * action). The v2 editor's granular per-credit endpoints (`credit_upsert`,
 * the `revision_restore` credits loop) and `includes/lyrics_ingest.php`'s
 * `tblSongArtists` insert never called into that code, so a credit saved
 * through any of THOSE paths wrote only the role-table (`tblSongWriters`
 * etc.) `Name` column and silently left the registry unpopulated — no
 * slug, no `/musician/<slug>` page, nowhere to attach identifiers/links.
 * `credit_search` autocomplete still worked (it unions the role tables
 * directly), so the gap was invisible until someone tried to open the
 * person's page — the rule #30 "silent no-op" failure class. Extracting
 * this into a single callable closes the gap at every call site at once
 * instead of re-forking the promote+backfill pairing per site (project
 * modularity rule, `.claude/CLAUDE.md`).
 *
 * The backfill UPDATE is intentionally `COALESCE(NULLIF(col,''),?)` — it
 * fills a column ONLY when the existing value is NULL or empty string,
 * so a curator's hand-edited FirstNames/Surname/Suffix on
 * `/manage/musicians` can never be silently clobbered by an
 * auto-promote from a song save (that guarantee is why #960's fix
 * requires callers to echo back the REGISTRY's parts, not the caller's
 * input, in any API response — see `manage/editor/api2.php`'s
 * `credit_upsert`). Gated on `musicianNamePartsColumnsExist()` so an
 * un-migrated install (PR #935's columns not yet added) degrades to the
 * plain Name-only `registerMusicianByName()` insert, exactly as the
 * legacy whole-song save did.
 *
 * Body is a byte-for-byte move of the promote-loop-body + the
 * `COALESCE(NULLIF(` backfill UPDATE from `save_song_core.php`, reshaped
 * from "loop over many names at once" (whole-song save has a batch of
 * credits) to "one name per call" (every per-credit endpoint only ever
 * has one name at a time) — the SQL text and the never-overwrite
 * semantics are unchanged.
 *
 * @param \mysqli $db    Live connection (see `includes/db_mysql.php::getDbMysqli()`).
 * @param string  $name  The composed display name — becomes
 *                        `tblMusicians.Name`.
 * @param array{first?:string,surname?:string,suffix?:string} $parts
 *                        Structured name parts, when known. Missing or
 *                        empty parts are treated as "nothing to backfill".
 * @return int The person's `tblMusicians.Id` (existing row, or the
 *             newly inserted row). 0 only when $name is empty (mirrors
 *             `registerMusicianByName()`'s own contract).
 */
function musicianPromote(\mysqli $db, string $name, array $parts = []): int
{
    $partsCols = musicianNamePartsColumnsExist($db);
    $personId  = registerMusicianByName($db, $name, $partsCols ? $parts : null);

    $first   = trim((string)($parts['first']   ?? ''));
    $surname = trim((string)($parts['surname'] ?? ''));
    $suffix  = trim((string)($parts['suffix']  ?? ''));

    if ($partsCols && ($first !== '' || $surname !== '' || $suffix !== '')) {
        /* Existing registry rows may already exist
           without FirstNames/Surname/Suffix populated;
           backfill those (only when currently empty)
           so a song-save also enriches pre-existing
           Name-only registry rows. The helper above
           only sets parts for BRAND NEW inserts; this
           handles the existing-row case. Never
           overwrites a curated value. */
        $stmtParts = $db->prepare(
            'UPDATE tblMusicians
                SET FirstNames = COALESCE(NULLIF(FirstNames, ""), ?),
                    Surname    = COALESCE(NULLIF(Surname,    ""), ?),
                    Suffix     = COALESCE(NULLIF(Suffix,     ""), ?)
              WHERE Name = ?'
        );
        $firstBind   = $first   !== '' ? $first   : null;
        $surnameBind = $surname !== '' ? $surname : null;
        $suffixBind  = $suffix  !== '' ? $suffix  : null;
        $stmtParts->bind_param('ssss', $firstBind, $surnameBind, $suffixBind, $name);
        $stmtParts->execute();
        $stmtParts->close();
    }

    return $personId;
}

/**
 * iHymns — canonical BYTES for a person name (invisible-junk fold).
 *
 * ELI5: two names that LOOK identical on screen can be stored as different
 * bytes — one has a stray trailing space, a non-breaking space instead of a
 * normal one, or an accented letter encoded two different ways. This tidies a
 * name down to one canonical byte-string WITHOUT ever changing which person it
 * is: it only removes/normalises the invisible stuff.
 *
 * DETAILED / WHY: this is identity-PRESERVING on purpose — it must never fold
 * two genuinely different people together (that is what the fuzzy merge UX is
 * for). It only: (a) NFC-composes Unicode so "é" as one code point equals "é"
 * as e + combining-accent (https://unicode.org/reports/tr15/ Form C — the
 * composed form, NOT the KD decomposition used by the search-fold at line ~903,
 * which would strip accents and IS lossy); (b) turns NBSP / zero-width space /
 * BOM into an ordinary space; (c) collapses any run of whitespace to one ASCII
 * space; (d) trims. Used only as the fallback canonical when NO registry row
 * exists to adopt a spelling from (see musicianReconcileCreditNameBytes()).
 *
 * @param string $name Raw stored name (may carry invisible byte variants).
 * @return string      Canonical bytes ('' if the name was blank/whitespace-only).
 */
function musicianCanonicalNameBytes(string $name): string
{
    if (class_exists('\Normalizer')) {
        $n = \Normalizer::normalize($name, \Normalizer::FORM_C);
        if (is_string($n)) { $name = $n; }
    }
    /* NBSP (U+00A0), zero-width space (U+200B), BOM / ZWNBSP (U+FEFF) → space,
       then any whitespace run → one ASCII space, then trim. */
    $name = preg_replace('/[\x{00A0}\x{200B}\x{FEFF}]/u', ' ', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    return trim($name);
}

/**
 * iHymns — the SIX song-credit role tables, as one map (#1785, rule #22).
 *
 * ELI5: a song can credit a person as a writer, composer, arranger,
 * adaptor, translator OR performing artist. This is the ONE list of
 * "which database tables hold those credits" — every place in the app
 * that needs to touch all of them (a rename, a merge, a usage count)
 * reads this constant instead of typing its own copy of the same six
 * table names.
 *
 * DETAILED / WHY this exists (discovered gap, #1785 / #1796): the editor's
 * ED2_CREDIT_TABLES (manage/editor/api2.php:369-376) already treats SIX
 * tables as "credit tables" — including tblSongArtists — and
 * `credit_upsert` promotes an artist's name INTO the tblMusicians registry
 * exactly the same way it does for a writer or composer. But every
 * REGISTRY-side surface (merge, rename, delete-usage-count, the
 * Musicians list's usage aggregate, the merge-target typeahead, the
 * bulk-promote candidate scan, the byte-reconcile sweep) had its own
 * hand-typed FIVE-table list that never learned about tblSongArtists —
 * so a merge/rename would silently strand artist credits on the deleted
 * spelling while use-counts undercounted by however many artist credits
 * existed. That five-table list was independently forked in six-plus
 * places (rule #35's cross-file-agreement problem) before this constant;
 * #1785 C5 re-points every one of them here. `tests/php/
 * test-musician-credit-tables-single-list.php` (G3) statically derives
 * every ≥3-of-6 role-table site in the tree and asserts each one is
 * either this constant, ED2_CREDIT_TABLES itself, or a consumer that
 * references one of the two — so the two registries can never silently
 * drift apart again (rule #35's "mechanism, not a comment").
 *
 * Keys use the SINGULAR role-name convention already established
 * elsewhere in this file's own SQL (the `kindLabel` values in the
 * Musicians list's usage query, musicians.php) — NOT ED2_CREDIT_TABLES's
 * plural JSON-payload-shaped keys ('writers', 'composers', …). The two
 * constants are asserted VALUE-set-equal (same six table names) by G3,
 * never key-set-equal — the differing pluralisation is each file's own
 * pre-existing, unrelated convention and forcing them to match key-for-
 * key would be change for its own sake.
 *
 * Table names are hardcoded PHP constants — safe to interpolate directly
 * into SQL (rule #5's carve-out); every VALUE that flows alongside them
 * (a Name, an Id) stays bound via bind_param().
 */
const MUSICIAN_CREDIT_ROLE_TABLES = [
    'writer'     => 'tblSongWriters',
    'composer'   => 'tblSongComposers',
    'arranger'   => 'tblSongArrangers',
    'adaptor'    => 'tblSongAdaptors',
    'translator' => 'tblSongTranslators',
    'artist'     => 'tblSongArtists',
];

/**
 * iHymns — human-readable label for each musicianNameVariantClass()
 * outcome (#1785). ONE copy consumed by every surface that renders the
 * variant badge (the future /manage/musician-duplicates review page, the
 * Merge modal's credit-preview column, the bulk-promote match column) —
 * never re-word this per call site.
 *
 * @var array<string,string>
 */
const MUSICIAN_NAME_VARIANT_LABELS = [
    'identical'              => 'identical bytes',
    'unicode-normalisation'  => 'differs only by Unicode form (combining vs. precomposed accent)',
    'whitespace'             => 'differs only by invisible spacing',
    'case'                   => 'differs only by letter case',
    'punctuation'            => 'differs only by punctuation/accents',
];

/**
 * iHymns — classify HOW two person names differ, on an identity-
 * preserving ladder from "definitely the same spelling" to "genuinely
 * different words" (#1785).
 *
 * ELI5: given two names that LOOK almost the same, say WHY — is it just
 * an invisible extra space, a smart-quote vs a straight one, upper vs
 * lower case, or are the actual words different? This is what turns a
 * confusing "Eddie James → Eddie James" merge prompt into "these two
 * differ only by invisible spacing" — problem 2 from #1785's issue body.
 *
 * DETAILED / the ladder (first match wins), and why each rung is placed
 * where it is:
 *   1. `$a === $b`                                        → 'identical'
 *      Defensive only — a real caller only ever calls this on a genuine
 *      candidate PAIR, which by definition differs in some byte.
 *   2. NFC($a) === NFC($b)                                → 'unicode-normalisation'
 *      Catches ONLY a combining-accent-vs-precomposed-accent difference
 *      (e.g. "é" as one code point vs "e" + U+0301 COMBINING ACUTE
 *      ACCENT) — checked before the whitespace rung because NFC alone
 *      does not touch whitespace, so a pair that's PURELY a combining-
 *      accent difference must not fall through to a later, broader rung
 *      and get mis-labelled.
 *   3. musicianCanonicalNameBytes($a) === …($b)           → 'whitespace'
 *      Reuses the #1784 canonical-bytes fold UNCHANGED (never re-forked)
 *      — NFC-compose + NBSP/ZWSP/BOM→space + whitespace-run-collapse +
 *      trim. Catches trailing/leading/interior spacing variants.
 *   4. mb_strtolower(canonical(a)) === mb_strtolower(canonical(b))
 *                                                          → 'case'
 *      Same canonical fold, lower-cased — catches a pure case difference
 *      once whitespace is already ruled out by rung 3.
 *   5. ihymns_sim_name_normalise($a) === …($b)            → 'punctuation'
 *      The shared NAME fold (song_similarity.php, #1785) — accent-fold +
 *      punctuation→space + collapse. Catches period/apostrophe-homoglyph/
 *      dash/accent differences that survive rungs 2-4 (e.g. "O'Brien" vs
 *      "O'Brien" with a curly apostrophe, or "J. Newton" vs "J Newton").
 *   6. else                                                → null
 *      The names are genuinely different WORDS — the fuzzy-score case,
 *      not a byte-variant of the same spelling.
 *
 * §3.1 of the #1785 plan verified experimentally (against the live
 * tblMusicians uk_Name UNIQUE KEY under utf8mb4_unicode_ci) that most of
 * what rungs 2-4 test (pure Unicode form, case, most whitespace variants)
 * collapse under that collation, so TWO coexisting registry rows can
 * essentially never differ in those ways — except an interior run of 2+
 * plain spaces, which the collation treats as distinct even though rung 3
 * (whitespace) correctly still names it 'whitespace'. Rung 5
 * (punctuation) is what the punctuation-homoglyph classes that DO
 * coexist as distinct rows (curly vs straight apostrophe, period
 * present/absent, hyphen vs en-dash) actually hit. This is not a
 * database-uniqueness gate — it's a DISPLAY classifier only, called
 * AFTER some other means (a fold-equal bucket, a fuzzy-score match, an
 * alias hit) has already produced a candidate pair — and it serves BOTH
 * registry-vs-registry pairs and registry-vs-cited-credit-name pairs
 * (credit tables carry no unique constraint, so every rung is reachable
 * there, which is why #1784's byte-reconcile sweep and this classifier
 * are close cousins).
 *
 * @return string|null One of MUSICIAN_NAME_VARIANT_LABELS's keys, or null
 *                      when the names are not a byte-variant of each other.
 */
function musicianNameVariantClass(string $a, string $b): ?string
{
    if ($a === $b) {
        return 'identical';
    }

    $nfcA = $a;
    $nfcB = $b;
    if (class_exists('\Normalizer')) {
        $normA = \Normalizer::normalize($a, \Normalizer::FORM_C);
        $normB = \Normalizer::normalize($b, \Normalizer::FORM_C);
        if (is_string($normA)) { $nfcA = $normA; }
        if (is_string($normB)) { $nfcB = $normB; }
    }
    if ($nfcA === $nfcB) {
        return 'unicode-normalisation';
    }

    $canonA = musicianCanonicalNameBytes($a);
    $canonB = musicianCanonicalNameBytes($b);
    if ($canonA === $canonB) {
        return 'whitespace';
    }

    if (mb_strtolower($canonA, 'UTF-8') === mb_strtolower($canonB, 'UTF-8')) {
        return 'case';
    }

    if (ihymns_sim_name_normalise($a) === ihymns_sim_name_normalise($b)) {
        return 'punctuation';
    }

    return null;
}

/**
 * iHymns — format one Unicode code point as "U+0027 (')" for a variant
 * badge tooltip (#1785). NULL/empty input (used when one string ran out
 * of characters before the other) renders as "∅ (none)".
 *
 * @see https://www.php.net/manual/en/function.mb-ord.php
 */
function musicianCodePointLabel(?string $char): string
{
    if ($char === null || $char === '') {
        return '∅ (none)';
    }
    $codePoint = mb_ord($char, 'UTF-8');
    if ($codePoint === false) {
        return $char;
    }
    $hex = str_pad(strtoupper(dechex($codePoint)), 4, '0', STR_PAD_LEFT);
    return "U+{$hex} ({$char})";
}

/**
 * iHymns — the first differing Unicode code point between two names,
 * formatted for a badge tooltip (#1785).
 *
 * ELI5: takes two names that look identical on screen and points at the
 * EXACT invisible character that's different — e.g. "U+0027 (') vs
 * U+2019 (’)" for a straight vs curly apostrophe. This is what makes
 * musicianNameVariantClass()'s 'punctuation'/'unicode-normalisation'
 * labels legible instead of just "these differ somehow".
 *
 * DETAILED: splits both strings into individual Unicode code points
 * (`preg_split('//u', …)`, NOT bytes and NOT graphemes — a combining
 * accent is its own code point here, which is exactly the granularity
 * the 'unicode-normalisation' class needs to explain itself) and walks
 * both arrays together, returning the FIRST index where they differ. A
 * length mismatch with no earlier difference (one name is a byte-for-
 * byte prefix of the other, e.g. a trailing-space-only difference caught
 * upstream by canonical-bytes) reports the shorter side as "∅ (none)"
 * at that position.
 *
 * @return array{a:string,b:string}|null null when $a === $b (nothing to report)
 */
function musicianNameVariantDetail(string $a, string $b): ?array
{
    if ($a === $b) {
        return null;
    }
    $charsA = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $charsB = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $len = max(count($charsA), count($charsB));
    for ($i = 0; $i < $len; $i++) {
        $ca = $charsA[$i] ?? null;
        $cb = $charsB[$i] ?? null;
        if ($ca === $cb) {
            continue;
        }
        return [
            'a' => musicianCodePointLabel($ca),
            'b' => musicianCodePointLabel($cb),
        ];
    }
    return null; // Defensive — reachable only if $a !== $b yet every code point matched, which can't happen.
}

/**
 * iHymns — reconcile legacy credit-name BYTES against the registry (#1784).
 *
 * ELI5: fixes the "1 person is credited but isn't saved to the registry yet"
 * counter that would never reach zero (the weeks-old "Eddie James" bug). The
 * cause: a song-credit row and its registry row hold the SAME name spelled with
 * slightly different invisible bytes (a stray space, an NBSP). The list page
 * matches the two by EXACT bytes, so it never links them and the counter sticks
 * on 1 forever — and the "Merge into Eddie James" button couldn't fix it
 * either (it trimmed the candidate, saw it now equalled the target, and skipped
 * as "nothing to do"). This walks every cited name that has no byte-exact
 * registry row and repairs it.
 *
 * DETAILED / WHY: for each distinct cited Name (across the five role tables)
 * that has NO byte-exact tblMusicians row:
 *   (a) exactly one registry row COLLATION-matches → adopt that row's exact
 *       spelling into the credit tables (the Eddie James case — the canonical
 *       lives in the registry; this is what "Merge into X" was meant to do);
 *   (b) NO registry row matches → normalise the bytes via
 *       musicianCanonicalNameBytes() and auto-register the result
 *       (registerMusicianByName(), the ONE registry insert path);
 *   (c) TWO+ registry rows collation-match → the registry itself has a
 *       duplicate, unsafe to auto-pick; left for the registry-dedup UX (#1785)
 *       and reported as 'ambiguous'.
 * Every operation is identity-preserving (collation-equal / whitespace / NFC
 * are all "the same person"), so this can never silently merge two DISTINCT
 * people. Idempotent: a second run finds nothing (every cited name now
 * byte-matches a registry row). Blank / whitespace-only cited names are junk,
 * not real people — they are skipped, matching the migration probe's
 * `TRIM(Name) <> ''` (rule #35: the probe and this helper share one definition).
 *
 * Transaction-agnostic: opens NO transaction of its own so callers that already
 * run inside one (bulk_register_unregistered, the migration) can wrap it. Every
 * value is bound; the only interpolated tokens are the hardcoded table names
 * (rule #5).
 *
 * @param \mysqli $db Live connection (includes/db_mysql.php::getDbMysqli()).
 * @return array{scanned:int,rewritten:int,registered:int,adopted:int,ambiguous:int,names:array<int,array<string,mixed>>}
 */
function musicianReconcileCreditNameBytes(\mysqli $db): array
{
    /* The five song-credit tables that carry a free-text person Name. Hardcoded
       constants — safe to interpolate into the SQL below (rule #5). */
    static $tables = [
        'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
        'tblSongAdaptors', 'tblSongTranslators',
    ];

    $report = ['scanned' => 0, 'rewritten' => 0, 'registered' => 0,
               'adopted' => 0, 'ambiguous' => 0, 'names' => []];

    /* (1) Every distinct cited name, deduped by EXACT bytes (PHP array keys are
           byte-exact — unlike a SQL UNION, which folds under the collation and
           would lose the very byte-variant we are hunting). */
    $citedSql = implode("\n            UNION ALL\n            ", array_map(
        static fn(string $t): string => "SELECT Name FROM {$t}",
        $tables
    ));
    $citedBytes = [];
    foreach ($db->query($citedSql)->fetch_all(MYSQLI_ASSOC) as $r) {
        $citedBytes[(string)$r['Name']] = true;
    }

    /* (2) Registry names, deduped by EXACT bytes. */
    $regBytes = [];
    foreach ($db->query('SELECT Name FROM tblMusicians')->fetch_all(MYSQLI_ASSOC) as $r) {
        $regBytes[(string)$r['Name']] = true;
    }

    /* (3) The stuck set: cited, real (not blank/whitespace-only), and with no
           byte-exact registry row. */
    foreach (array_keys($citedBytes) as $bad) {
        if (trim($bad) === '') { continue; }        // junk row, not a person
        if (isset($regBytes[$bad])) { continue; }   // already byte-matched
        $report['scanned']++;

        /* Collation-matching registry rows (case- + trailing-space-insensitive),
           deduped by exact bytes so a genuine registry duplicate is detectable. */
        $stmt = $db->prepare('SELECT Name FROM tblMusicians WHERE Name = ?');
        $stmt->bind_param('s', $bad);
        $stmt->execute();
        $collationMatches = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $m) {
            $collationMatches[(string)$m['Name']] = true;
        }
        $stmt->close();

        $registerAfter = false;
        if (count($collationMatches) === 1) {
            $canonical = (string) array_key_first($collationMatches);  // adopt registry spelling
        } elseif (count($collationMatches) > 1) {
            $report['ambiguous']++;
            $report['names'][] = ['name' => $bad, 'action' => 'ambiguous',
                                  'candidates' => array_keys($collationMatches)];
            continue;
        } else {
            $canonical = musicianCanonicalNameBytes($bad);
            if ($canonical === '') { continue; }     // normalised to nothing → junk
            $registerAfter = true;
        }

        /* (4) Rewrite the credit rows from the bad bytes to the canonical bytes.
               BINARY in the WHERE touches ONLY the exact bad-byte rows, never a
               collation-equal sibling that is already correct. */
        if ($canonical !== $bad) {
            foreach ($tables as $t) {
                $up = $db->prepare("UPDATE {$t} SET Name = ? WHERE BINARY Name = ?");
                $up->bind_param('ss', $canonical, $bad);
                $up->execute();
                $report['rewritten'] += $up->affected_rows;
                $up->close();
            }
        }

        /* (5) Ensure a registry row exists for the canonical bytes. */
        if ($registerAfter) {
            $newId = registerMusicianByName($db, $canonical);
            if ($newId > 0) { $report['registered']++; }
            $report['names'][] = ['name' => $bad, 'action' => 'registered', 'canonical' => $canonical];
        } else {
            $report['adopted']++;
            $report['names'][] = ['name' => $bad, 'action' => 'adopted', 'canonical' => $canonical];
        }
    }

    return $report;
}

/**
 * Cached check for the IsSpecialCase / IsGroup columns from
 * #584/#585 (#630). Both ship together via
 * migrate-musicians-flags.php; detecting one is sufficient to
 * assume both. Caches the result for the request lifetime via a
 * static so the add / update paths don't pay the
 * INFORMATION_SCHEMA round-trip twice.
 */
function musicianFlagsColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblMusicians'
                AND COLUMN_NAME  = 'IsSpecialCase' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}


/* =========================================================================
 * CREDIT PERSON ALIASES (AKA names)
 *
 * Shared helpers for the MusicBrainz-style alias model
 * (tblMusicianAliases — see migrate-musicians-aliases.php).
 * Used by:
 *   - /api.php's admin_musician_add / _update handlers
 *   - /manage/musicians.php form handler
 *   - /musician/<slug>'s public render (alias list + JSON-LD)
 *   - bulk-import's "(a.k.a. …)" pattern detector
 * ========================================================================= */

const MUSICIAN_ALIAS_TYPES = [
    'legal'         => 'Legal name',
    'artist'        => 'Artist / performing name',
    'pseudonym'     => 'Pseudonym / pen name',
    'nickname'      => 'Nickname',
    'maiden'        => 'Maiden name',
    'search-hint'   => 'Search hint',
    'misspelling'   => 'Common misspelling',
    'other'         => 'Other',
];

const MUSICIAN_ALIAS_TYPE_KEYS = [
    'legal','artist','pseudonym','nickname','maiden','search-hint','misspelling','other',
];

/**
 * Schema-tolerant probe for the aliases table — returns false on installs
 * that haven't run migrate-musicians-aliases.php yet so consuming
 * code can branch cheaply rather than throw on every load.
 */
function musicianAliasesTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblMusicianAliases' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Normalise inbound alias payload (form-encoded or JSON-decoded) into
 * a list of INSERT-ready row arrays. Drops rows with empty Name, clamps
 * Type to the allow-list, defaults SortOrder to the input index.
 *
 * @param mixed $raw Form / JSON-decoded array; non-array → []
 * @return list<array{name:string,sort_name:?string,type:string,locale:?string,is_primary:int,sort_order:int,note:?string}>
 */
function normaliseMusicianAliases(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out  = [];
    $seen = []; /* dedupe by (lower-cased) name within the request */
    foreach ($raw as $i => $row) {
        if (!is_array($row)) continue;
        $name = musTrimmed($row['name'] ?? ''); // #trim
        if ($name === '') continue;
        if (mb_strlen($name) > 255) $name = mb_substr($name, 0, 255);
        $key = mb_strtolower($name);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $type = musTrimmed($row['type'] ?? 'other'); // #trim
        if (!in_array($type, MUSICIAN_ALIAS_TYPE_KEYS, true)) $type = 'other';

        $sortName = musTrimmed($row['sort_name'] ?? ''); // #trim
        $locale   = musTrimmed($row['locale']    ?? ''); // #trim
        $note     = musTrimmed($row['note']      ?? ''); // #trim

        $out[] = [
            'name'        => $name,
            'sort_name'   => $sortName !== '' ? mb_substr($sortName, 0, 255) : null,
            'type'        => $type,
            'locale'      => $locale   !== '' ? mb_substr($locale, 0, 35)    : null,
            'is_primary'  => !empty($row['is_primary']) ? 1 : 0,
            'sort_order'  => (int)($row['sort_order'] ?? $i),
            'note'        => $note     !== '' ? mb_substr($note, 0, 255)     : null,
        ];
    }
    return $out;
}

/**
 * Replace all rows in tblMusicianAliases for a credit person with
 * the supplied set. Run inside the same transaction as the parent
 * INSERT/UPDATE so a downstream failure rolls back cleanly. Schema-
 * tolerant: silently no-ops on installs where the table is absent.
 *
 * @param list<array> $aliases  Output of normaliseMusicianAliases()
 */
function replaceMusicianAliases(\mysqli $db, int $musicianId, array $aliases): void
{
    if (!musicianAliasesTableExists($db)) return;

    $del = $db->prepare('DELETE FROM tblMusicianAliases WHERE MusicianId = ?');
    $del->bind_param('i', $musicianId);
    $del->execute();
    $del->close();

    if (!$aliases) return;

    $ins = $db->prepare(
        'INSERT INTO tblMusicianAliases
             (MusicianId, Name, SortName, Type, Locale, IsPrimary, SortOrder, Note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($aliases as $a) {
        $musId      = $musicianId;
        $name      = $a['name'];
        $sortName  = $a['sort_name'];
        $type      = $a['type'];
        $locale    = $a['locale'];
        $isPrimary = (int)$a['is_primary'];
        $sortOrder = (int)$a['sort_order'];
        $note      = $a['note'];
        $ins->bind_param(
            'issssiis',
            $musId, $name, $sortName, $type, $locale, $isPrimary, $sortOrder, $note
        );
        $ins->execute();
    }
    $ins->close();
}

/**
 * Fetch every alias for one musician, ordered by IsPrimary DESC
 * then SortOrder ASC then Id ASC so the curator's preferred display
 * form is first.
 *
 * @return list<array{Id:int,Name:string,SortName:?string,Type:string,Locale:?string,IsPrimary:int,SortOrder:int,Note:?string}>
 */
function loadMusicianAliases(\mysqli $db, int $musicianId): array
{
    if (!musicianAliasesTableExists($db)) return [];
    $stmt = $db->prepare(
        'SELECT Id, Name, SortName, Type, Locale, IsPrimary, SortOrder, Note
           FROM tblMusicianAliases
          WHERE MusicianId = ?
       ORDER BY IsPrimary DESC, SortOrder ASC, Id ASC'
    );
    $stmt->bind_param('i', $musicianId);
    $stmt->execute();
    $out = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($out as &$r) {
        $r['Id']        = (int)$r['Id'];
        $r['IsPrimary'] = (int)$r['IsPrimary'];
        $r['SortOrder'] = (int)$r['SortOrder'];
    }
    unset($r);
    return $out;
}

/**
 * Bulk-load aliases for many musicians in one round-trip, grouped
 * by MusicianId. Used by /manage/musicians's list-view render
 * so the page doesn't issue N queries when surfacing aliases inline.
 *
 * @param list<int> $personIds
 * @return array<int, list<array>>  MusicianId → list of alias rows
 */
function loadMusicianAliasesBulk(\mysqli $db, array $personIds): array
{
    $personIds = array_values(array_filter(array_map('intval', $personIds), fn($v) => $v > 0));
    if (!$personIds || !musicianAliasesTableExists($db)) return [];
    $placeholders = implode(',', array_fill(0, count($personIds), '?'));
    $types        = str_repeat('i', count($personIds));
    $stmt = $db->prepare(
        "SELECT MusicianId, Id, Name, SortName, Type, Locale, IsPrimary, SortOrder, Note
           FROM tblMusicianAliases
          WHERE MusicianId IN ($placeholders)
       ORDER BY MusicianId ASC, IsPrimary DESC, SortOrder ASC, Id ASC"
    );
    $stmt->bind_param($types, ...$personIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $grouped = [];
    foreach ($rows as $r) {
        $pid = (int)$r['MusicianId'];
        unset($r['MusicianId']);
        $r['Id']        = (int)$r['Id'];
        $r['IsPrimary'] = (int)$r['IsPrimary'];
        $r['SortOrder'] = (int)$r['SortOrder'];
        $grouped[$pid][] = $r;
    }
    return $grouped;
}

/**
 * Detect alias patterns inside a free-text credit string, used by the
 * bulk-import flow to auto-promote "a.k.a." annotations into proper
 * alias rows. Recognises:
 *
 *   "Smith (a.k.a. Jones)"        → primary=Smith, alias=Jones
 *   "Smith (aka Jones)"           → ditto
 *   "Smith / Jones"               → primary=Smith, alias=Jones
 *   "Smith (Jones)"               → primary=Smith, alias=Jones (parenthetical)
 *   "Smith, also known as Jones"  → ditto
 *
 * Returns ['name' => <canonical>, 'aliases' => list<string>]. Pass-through
 * for strings with no recognisable pattern: ['name' => $raw, 'aliases' => []].
 *
 * Conservative — only fires on patterns that are unambiguously
 * alias markers. Bare parenthetical "(b. 1850)" is left alone (the
 * Smith-and-Jones case is distinguished by the absence of digits /
 * non-name characters inside the parens).
 */
function parseMusicianAliasHints(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return ['name' => '', 'aliases' => []];

    $aliases = [];
    $primary = $raw;

    /* Pattern 1 — explicit a.k.a. / aka / also known as. The optional
       trailing comma/period before the marker gets stripped from the
       primary so "Smith, also known as Jones" lands as primary=Smith,
       not "Smith," with a trailing comma. */
    if (preg_match('/^(.+?)\s*[,.]?\s*(?:\(\s*)?(?:a\.?k\.?a\.?|also\s+known\s+as)\s+([^)]+?)\s*\)?\s*$/i', $raw, $m)) {
        $primary = rtrim(trim($m[1]), " ,.");
        $aliasText = trim($m[2]);
        if ($aliasText !== '') {
            /* Split on slash / semicolon / " or " so multiple aliases work. */
            foreach (preg_split('/\s*(?:\/|;| or )\s*/i', $aliasText) as $a) {
                $a = trim($a);
                if ($a !== '' && $a !== $primary) $aliases[] = $a;
            }
        }
        return ['name' => $primary, 'aliases' => $aliases];
    }

    /* Pattern 2 — parenthetical name without explicit marker.
       Requires the parens to contain letter-only content (no digits,
       no commas, no dates), and to look like a name (≥2 letters). */
    if (preg_match('/^(.+?)\s*\(\s*([\p{L}][\p{L}\s\.\-\']+)\s*\)\s*$/u', $raw, $m)) {
        $inner = trim($m[2]);
        /* Reject biography-ish content. */
        if (!preg_match('/\d|,|^b\.|^d\.|^born\b|^died\b/iu', $inner)) {
            $primary   = trim($m[1]);
            $aliases[] = $inner;
            return ['name' => $primary, 'aliases' => $aliases];
        }
    }

    /* Pattern 3 — "A / B" without slash being a date or path. Only
       split when both sides look like person names (≥2 letters, no
       digits, no slashes themselves). */
    if (preg_match('/^([\p{L}][\p{L}\s\.\-\']+?)\s+\/\s+([\p{L}][\p{L}\s\.\-\']+)$/u', $raw, $m)) {
        $left  = trim($m[1]);
        $right = trim($m[2]);
        if ($left !== '' && $right !== '' && $left !== $right) {
            return ['name' => $left, 'aliases' => [$right]];
        }
    }

    return ['name' => $raw, 'aliases' => []];
}

/* =========================================================================
 * GROUP MEMBERSHIP (#1502)
 *
 * Links individual MEMBER people (ordinary tblMusicians rows) to a
 * 'Group / band / collective' person (IsGroup=1, #585) via the thin
 * join table tblMusicianRelations — see
 * appWeb/.sql/migrate-add-creditperson-members.php for the schema
 * rationale. Every helper below is table-existence-gated (dormant-safe
 * on an install that hasn't run the migration yet, matching the
 * aliases / date-precision / places helpers above in this file).
 * ========================================================================= */

/**
 * Cached probe for tblMusicianRelations. Static-cached for the request
 * lifetime so the INFORMATION_SCHEMA round-trip happens at most once,
 * mirroring musicianAliasesTableExists() above.
 *
 * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema-tables-table.html
 */
function musicianMembersTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblMusicianRelations' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Cached probe for the Slug column on tblMusicians (#588). Extracted
 * here (rather than re-probed inline) because both the admin Members
 * card-list and the public /musician/<slug> Members panel need to know
 * whether a member row can be linked. Mirrors the inline probe already
 * embedded in generateUniqueMusicianSlug() below, just exposed as
 * its own reusable, cached check.
 */
function musicianSlugColumnExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblMusicians'
                AND COLUMN_NAME  = 'Slug' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/* =========================================================================
 * ENTITY TYPE VOCABULARY + GENERALISED RELATIONS (#1741 P4a)
 *
 * ELI5: two upgrades over the plain member-of-a-group link above.
 *   1. A musician row can now be classified more precisely than the old
 *      IsGroup/IsSpecialCase pair of checkboxes — MUSICIAN_TYPES is the ONE
 *      central vocabulary (person/group/character/orchestra/other).
 *   2. tblMusicianRelations (formerly "just group membership") can now
 *      express a SECOND relation shape — 'portrays' — for a person credited
 *      as portraying a historical/fictional figure in a dramatisation
 *      ("Anonymous 4 performs as St. Cecilia" style credits). Both relation
 *      types share the same table + the same SubjectMusicianId/
 *      ObjectMusicianId direction: "subject HAS-RELATION-TO object" — for
 *      'member' that reads "the Group subject HAS-MEMBER the person object"
 *      (unchanged from #1502); for 'portrays' it reads "the portrayer
 *      subject PORTRAYS the portrayed-figure object". A character page's
 *      "Portrayed by" section therefore queries
 *      `ObjectMusicianId = <this row> AND RelationType = 'portrays'`
 *      (loadMusicianPortrayedBy() below) — never the reverse.
 *
 * DETAILED / WHY
 * ----------------------------------------------------------------------------
 * `MUSICIAN_TYPES` mirrors the schema.sql `tblMusicians.Type` COMMENT
 * vocabulary exactly (VARCHAR-not-ENUM, rule #20 — a growable classification
 * list must never force an ALTER). `musicianTypeApply()` is THE ONE funnel
 * that writes Type — and, since IsGroup/IsSpecialCase are DERIVED MIRRORS of
 * it (schema.sql:539-544's COMMENT: "group|orchestra" ⇒ IsGroup=1,
 * "character|other" ⇒ IsSpecialCase=1), it writes all three columns
 * together in ONE statement so they can never disagree. From this file's
 * next edit onward NOTHING outside this function may `SET IsGroup = …` or
 * `SET IsSpecialCase = …` — enforced by
 * tests/php/test-musician-profile-fields.php's flag-funnel-ban regex, which
 * excludes only this file (and appWeb/.sql/, the one-shot backfill
 * migration that predates the funnel).
 *
 * Every helper below is existence-gated the SAME way the rest of this file
 * is (musicianProfileColumnsExist() / musicianRelationColumnsExist() mirror
 * musicianFlagsColumnsExist()'s cached-per-request INFORMATION_SCHEMA
 * probe idiom, generalised to return a per-column map — §0.3.4 in the build
 * spec — since Type/Biography/Disambiguation, like tblWorks' P4b columns,
 * could in principle be partially applied).
 *
 * @link .claude/catalogue-1741-P4-plan.md §1.2  the build spec this section implements
 * @see #1741
 * ========================================================================= */

/**
 * The entity-type vocabulary — schema.sql's `tblMusicians.Type` COMMENT,
 * verbatim. Central map (rule #20): never hard-code this list a second
 * time in manage/musicians.php or musician.php — both `require_once` this
 * file and read the map.
 */
const MUSICIAN_TYPES = [
    'person'    => 'Person',
    'group'     => 'Group / collective',
    'character' => 'Character / persona',
    'orchestra' => 'Orchestra / ensemble',
    'other'     => 'Other / special',
];

/**
 * The tblMusicianRelations RelationType vocabulary — schema.sql's COMMENT,
 * verbatim. 'member' is the pre-existing #1502 shape; 'portrays' is new in
 * #1741 P1/P4a.
 */
const MUSICIAN_RELATION_TYPES = [
    'member'   => 'Member of',
    'portrays' => 'Portrays',
];

/**
 * Cached, per-column probe for the three #1741 P1 tblMusicians profile
 * columns (Type / Biography / Disambiguation). Returns a map so a caller
 * can gate each column independently — a partially-applied
 * migrate-musician-profile.php run (unlikely, but every other probe in
 * this file assumes the same "any subset may be present" posture, e.g.
 * tblWorks' `_worksExtraCols()` sibling in SongData.php) never throws
 * "Unknown column" under mysqli STRICT.
 *
 * @return array{Type:bool,Biography:bool,Disambiguation:bool}
 */
function musicianProfileColumnsExist(\mysqli $db): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $cols = ['Type', 'Biography', 'Disambiguation'];
    $out  = array_fill_keys($cols, false);
    try {
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblMusicians'
                AND COLUMN_NAME IN ($ph)"
        );
        $types = str_repeat('s', count($cols));
        $stmt->bind_param($types, ...$cols);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $out[(string)$row['COLUMN_NAME']] = true;
        }
        $stmt->close();
    } catch (\Throwable $_e) { /* leave every column false */ }
    $cached = $out;
    return $cached;
}

/**
 * Cached, per-column probe for the six #1741 P1 tblMusicianRelations
 * "dated relation" columns. Mirrors musicianProfileColumnsExist() above.
 *
 * @return array{RelationType:bool,DateFrom:bool,DateFromPrecision:bool,DateTo:bool,DateToPrecision:bool,Note:bool}
 */
function musicianRelationColumnsExist(\mysqli $db): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $cols = ['RelationType', 'DateFrom', 'DateFromPrecision', 'DateTo', 'DateToPrecision', 'Note'];
    $out  = array_fill_keys($cols, false);
    try {
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblMusicianRelations'
                AND COLUMN_NAME IN ($ph)"
        );
        $types = str_repeat('s', count($cols));
        $stmt->bind_param($types, ...$cols);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $out[(string)$row['COLUMN_NAME']] = true;
        }
        $stmt->close();
    } catch (\Throwable $_e) { /* leave every column false */ }
    $cached = $out;
    return $cached;
}

/**
 * THE ONE write funnel for tblMusicians.Type + its two derived mirrors
 * (IsGroup / IsSpecialCase). ELI5: pick a type from the drop-down (Person /
 * Group / Character / Orchestra / Other) and this is the only place that
 * turns that choice into database columns.
 *
 * DETAILED / WHY: before this function, manage/musicians.php (and
 * api.php's admin_musician_add/_update, the native-app equivalent) each
 * wrote IsGroup/IsSpecialCase directly from two checkboxes, with no
 * concept of Type at all. Centralising the write means Type and its
 * mirrors can never disagree (a curator picking "Character" always yields
 * IsSpecialCase=1, never a stray IsSpecialCase=0/Type=character split
 * that would make the old flag-reading code and the new Type-reading code
 * disagree about the same row) — the exact mapping schema.sql:539-544's
 * COMMENT documents: group|orchestra ⇒ IsGroup=1; character|other ⇒
 * IsSpecialCase=1; person ⇒ neither.
 *
 * Column-gated (§0.3.4): when the Type column doesn't exist yet
 * (un-migrated install) this falls back to writing ONLY the two legacy
 * flag columns — the exact shape the pre-P4a direct writes produced —
 * and no-ops entirely (returns false, no throw) on an install with
 * neither Type nor the flag columns.
 *
 * @param string $type One of MUSICIAN_TYPES' keys. An unrecognised value
 *                      is rejected (returns false, no write at all) rather
 *                      than silently defaulting — callers are expected to
 *                      have already synthesised a valid key (see
 *                      manage/musicians.php's checkbox→type fallback).
 * @return bool True when a write happened (either shape); false when $id/
 *              $type were invalid, or no relevant column exists at all.
 */
function musicianTypeApply(\mysqli $db, int $id, string $type): bool
{
    if ($id <= 0) return false;
    if (!array_key_exists($type, MUSICIAN_TYPES)) return false;

    /* Derived mirrors — fixed mapping, schema.sql:539-544's COMMENT. */
    $isGroup       = (int)in_array($type, ['group', 'orchestra'], true);
    $isSpecialCase = (int)in_array($type, ['character', 'other'], true);

    $profileCols = musicianProfileColumnsExist($db);
    if ($profileCols['Type']) {
        $stmt = $db->prepare(
            'UPDATE tblMusicians SET Type = ?, IsGroup = ?, IsSpecialCase = ? WHERE Id = ?'
        );
        $stmt->bind_param('siii', $type, $isGroup, $isSpecialCase, $id);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    /* Type absent — an install that hasn't run migrate-musician-profile.php
       yet. Fall back to the legacy flags-only write (still column-gated:
       an ancient pre-#630 install has neither, and gets no write at all,
       matching every add/update path's pre-P4a behaviour on that install
       tier). */
    if (!musicianFlagsColumnsExist($db)) return false;
    $stmt = $db->prepare('UPDATE tblMusicians SET IsGroup = ?, IsSpecialCase = ? WHERE Id = ?');
    $stmt->bind_param('iii', $isGroup, $isSpecialCase, $id);
    $stmt->execute();
    $stmt->close();
    return true;
}

/**
 * Shape one tblMusicianRelations JOIN tblMusicians row into the array shape
 * both the admin drawer's Members pre-fill and the public Portrayed-by/
 * Portrays cards consume. Shared by loadMusicianGroupMembers(),
 * loadMusicianGroupMembersBulk(), loadMusicianPortrayedBy() and
 * loadMusicianPortrays() so the five optional date/note columns are named
 * identically everywhere a caller reads them (rule #35 — one shape, not a
 * per-caller re-typed array literal).
 *
 * @param array<string,mixed> $r One fetch_assoc() row, already carrying
 *                                Id/Name/Slug + whichever of DateFrom/
 *                                DateFromPrecision/DateTo/DateToPrecision/
 *                                Note the caller's own column-gated SELECT
 *                                fragment included (absent keys read as
 *                                null via the `?? null` fallbacks below).
 *                                An optional `RelationId` (the
 *                                tblMusicianRelations row's OWN Id, distinct
 *                                from `Id` = the joined PERSON's Id) is
 *                                carried through as `relationId` when the
 *                                caller's SELECT included it — the
 *                                Portrayed-by sub-panel's Remove button
 *                                needs it to call `action=remove_relation`
 *                                by relation row Id (§1.2.10); Members rows
 *                                don't select it and simply get `null` here,
 *                                since remove_member deletes by the
 *                                (group,member) pair instead.
 * @return array{id:int,relationId:?int,name:string,slug:?string,relationType:string,dateFrom:?string,dateFromPrecision:?string,dateTo:?string,dateToPrecision:?string,note:?string}
 */
function musicianRelationRowShape(array $r, string $relationType): array
{
    return [
        'id'                => (int)$r['Id'],
        'relationId'        => isset($r['RelationId']) ? (int)$r['RelationId'] : null,
        'name'              => (string)$r['Name'],
        'slug'              => $r['Slug'] ?? null,
        'relationType'      => $relationType,
        'dateFrom'          => $r['DateFrom'] ?? null,
        'dateFromPrecision' => $r['DateFromPrecision'] ?? null,
        'dateTo'            => $r['DateTo'] ?? null,
        'dateToPrecision'   => $r['DateToPrecision'] ?? null,
        'note'              => $r['Note'] ?? null,
    ];
}

/**
 * Build the optional-columns SELECT fragment (`, m.DateFrom, …`) shared by
 * every tblMusicianRelations reader below, from the same
 * musicianRelationColumnsExist() map every writer already gates on.
 */
function musicianRelationExtraColsSql(array $relCols): string
{
    $extra = '';
    if ($relCols['DateFrom'])          $extra .= ', m.DateFrom';
    if ($relCols['DateFromPrecision']) $extra .= ', m.DateFromPrecision';
    if ($relCols['DateTo'])            $extra .= ', m.DateTo';
    if ($relCols['DateToPrecision'])   $extra .= ', m.DateToPrecision';
    if ($relCols['Note'])              $extra .= ', m.Note';
    return $extra;
}

/**
 * Fetch every member of one Group person, in SortOrder/Id order
 * (append-order — v1 has no drag-reorder UI). Slug is included (NULL on
 * a pre-#588 install) so callers that need to link to a member's public
 * page can do so without a second query.
 *
 * #1741 P4a — extended (column-gated) to also select the dated-relation
 * columns and to filter `RelationType = 'member'` once that column
 * exists, so a 'portrays' row (added via addMusicianRelation() below)
 * never renders as a band member here. On an install without
 * RelationType every relation row IS implicitly 'member' (the only shape
 * the legacy schema could express), so the unfiltered SELECT is already
 * correct — no filter is added in that case.
 *
 * @return list<array{id:int,name:string,slug:?string,relationType:string,dateFrom:?string,dateFromPrecision:?string,dateTo:?string,dateToPrecision:?string,note:?string}>
 */
function loadMusicianGroupMembers(\mysqli $db, int $groupId): array
{
    if ($groupId <= 0 || !musicianMembersTableExists($db)) return [];
    $slugCol   = musicianSlugColumnExists($db) ? 'p.Slug' : 'NULL';
    $relCols   = musicianRelationColumnsExist($db);
    $extraCols = musicianRelationExtraColsSql($relCols);
    $typeFilter = $relCols['RelationType'] ? " AND m.RelationType = 'member'" : '';
    $stmt = $db->prepare(
        "SELECT p.Id AS Id, p.Name AS Name, {$slugCol} AS Slug{$extraCols}
           FROM tblMusicianRelations m
           JOIN tblMusicians p ON p.Id = m.ObjectMusicianId
          WHERE m.SubjectMusicianId = ?{$typeFilter}
       ORDER BY m.SortOrder ASC, m.Id ASC"
    );
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $out = [];
    foreach ($rows as $r) { $out[] = musicianRelationRowShape($r, 'member'); }
    return $out;
}

/**
 * Bulk-load group members for many group-person ids in one round-trip.
 * Used by /manage/musicians's list-view render so the Edit drawer's
 * Members pre-fill doesn't cost an extra query per row (mirrors
 * loadMusicianAliasesBulk() above). #1741 P4a — same RelationType='member'
 * filter + extra date/note columns as loadMusicianGroupMembers() above.
 *
 * @param list<int> $groupIds
 * @return array<int, list<array{id:int,name:string,slug:?string,relationType:string,dateFrom:?string,dateFromPrecision:?string,dateTo:?string,dateToPrecision:?string,note:?string}>> SubjectMusicianId → member rows
 */
function loadMusicianGroupMembersBulk(\mysqli $db, array $groupIds): array
{
    $groupIds = array_values(array_filter(array_map('intval', $groupIds), fn($v) => $v > 0));
    if (!$groupIds || !musicianMembersTableExists($db)) return [];
    $slugCol   = musicianSlugColumnExists($db) ? 'p.Slug' : 'NULL';
    $relCols   = musicianRelationColumnsExist($db);
    $extraCols = musicianRelationExtraColsSql($relCols);
    $typeFilter = $relCols['RelationType'] ? " AND m.RelationType = 'member'" : '';
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $types        = str_repeat('i', count($groupIds));
    $stmt = $db->prepare(
        "SELECT m.SubjectMusicianId AS GroupId, p.Id AS Id, p.Name AS Name, {$slugCol} AS Slug{$extraCols}
           FROM tblMusicianRelations m
           JOIN tblMusicians p ON p.Id = m.ObjectMusicianId
          WHERE m.SubjectMusicianId IN ($placeholders){$typeFilter}
       ORDER BY m.SubjectMusicianId ASC, m.SortOrder ASC, m.Id ASC"
    );
    $stmt->bind_param($types, ...$groupIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $grouped = [];
    foreach ($rows as $r) {
        $gid = (int)$r['GroupId'];
        $grouped[$gid][] = musicianRelationRowShape($r, 'member');
    }
    return $grouped;
}

/**
 * Bulk-load "Portrayed by" performers for many portrayed-figure ids in
 * one round-trip — the admin list-load's counterpart to
 * loadMusicianGroupMembersBulk() above, used so the "Portrayed by"
 * sub-panel on manage/musicians.php's Edit drawer (§1.2.10, rendered for
 * character/other-type rows) pre-fills without a per-row query. Returns
 * [] entirely when RelationType isn't a real column yet (mirrors
 * loadMusicianPortrayedBy()'s single-row sibling).
 *
 * @param list<int> $objectIds The portrayed-figure ids (ObjectMusicianId).
 * @return array<int, list<array{id:int,name:string,slug:?string,relationType:string,dateFrom:?string,dateFromPrecision:?string,dateTo:?string,dateToPrecision:?string,note:?string}>> ObjectMusicianId → portrayer rows
 */
function loadMusicianPortrayedByBulk(\mysqli $db, array $objectIds): array
{
    $objectIds = array_values(array_filter(array_map('intval', $objectIds), fn($v) => $v > 0));
    if (!$objectIds || !musicianMembersTableExists($db)) return [];
    $relCols = musicianRelationColumnsExist($db);
    if (!$relCols['RelationType']) return [];
    $slugCol   = musicianSlugColumnExists($db) ? 'p.Slug' : 'NULL';
    $extraCols = musicianRelationExtraColsSql($relCols);
    $placeholders = implode(',', array_fill(0, count($objectIds), '?'));
    $types        = str_repeat('i', count($objectIds));
    $stmt = $db->prepare(
        "SELECT m.ObjectMusicianId AS FigureId, p.Id AS Id, m.Id AS RelationId, p.Name AS Name, {$slugCol} AS Slug{$extraCols}
           FROM tblMusicianRelations m
           JOIN tblMusicians p ON p.Id = m.SubjectMusicianId
          WHERE m.ObjectMusicianId IN ($placeholders) AND m.RelationType = 'portrays'
       ORDER BY m.ObjectMusicianId ASC, m.SortOrder ASC, m.Id ASC"
    );
    $stmt->bind_param($types, ...$objectIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $grouped = [];
    foreach ($rows as $r) {
        $fid = (int)$r['FigureId'];
        $grouped[$fid][] = musicianRelationRowShape($r, 'portrays');
    }
    return $grouped;
}

/**
 * Every figure this person (an actor / performer, $id) is credited as
 * portraying — the "Portrays" card on their own public page. Inverse of
 * loadMusicianPortrayedBy() below: reads the SUBJECT side.
 *
 * Returns [] whenever RelationType isn't a real column yet — a
 * 'portrays' relation cannot exist to find on that install (the legacy
 * schema can only express 'member').
 *
 * @return list<array{id:int,name:string,slug:?string,relationType:string,dateFrom:?string,dateFromPrecision:?string,dateTo:?string,dateToPrecision:?string,note:?string}>
 */
function loadMusicianPortrays(\mysqli $db, int $id): array
{
    if ($id <= 0 || !musicianMembersTableExists($db)) return [];
    $relCols = musicianRelationColumnsExist($db);
    if (!$relCols['RelationType']) return [];
    $slugCol   = musicianSlugColumnExists($db) ? 'p.Slug' : 'NULL';
    $extraCols = musicianRelationExtraColsSql($relCols);
    $stmt = $db->prepare(
        "SELECT p.Id AS Id, p.Name AS Name, {$slugCol} AS Slug{$extraCols}
           FROM tblMusicianRelations m
           JOIN tblMusicians p ON p.Id = m.ObjectMusicianId
          WHERE m.SubjectMusicianId = ? AND m.RelationType = 'portrays'
       ORDER BY m.SortOrder ASC, m.Id ASC"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $out = [];
    foreach ($rows as $r) { $out[] = musicianRelationRowShape($r, 'portrays'); }
    return $out;
}

/**
 * Every performer credited as portraying THIS figure ($id) — the
 * "Portrayed by" card on a character/other-type person's own public page.
 * Direction, verbatim from the plan (record here, not just in the plan,
 * since this is the load-bearing query): the relation verb reads FROM
 * subject TO object ("subject portrays object"), so "who portrays ME" is
 * `ObjectMusicianId = <this row> AND RelationType = 'portrays'` — the
 * SUBJECT side of each matching row is the portrayer. Inverse of
 * loadMusicianPortrays() above.
 *
 * @return list<array{id:int,name:string,slug:?string,relationType:string,dateFrom:?string,dateFromPrecision:?string,dateTo:?string,dateToPrecision:?string,note:?string}>
 */
function loadMusicianPortrayedBy(\mysqli $db, int $id): array
{
    if ($id <= 0 || !musicianMembersTableExists($db)) return [];
    $relCols = musicianRelationColumnsExist($db);
    if (!$relCols['RelationType']) return [];
    $slugCol   = musicianSlugColumnExists($db) ? 'p.Slug' : 'NULL';
    $extraCols = musicianRelationExtraColsSql($relCols);
    $stmt = $db->prepare(
        "SELECT p.Id AS Id, m.Id AS RelationId, p.Name AS Name, {$slugCol} AS Slug{$extraCols}
           FROM tblMusicianRelations m
           JOIN tblMusicians p ON p.Id = m.SubjectMusicianId
          WHERE m.ObjectMusicianId = ? AND m.RelationType = 'portrays'
       ORDER BY m.SortOrder ASC, m.Id ASC"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $out = [];
    foreach ($rows as $r) { $out[] = musicianRelationRowShape($r, 'portrays'); }
    return $out;
}

/**
 * Add one relation row — generalisation of the #1502 "add group member"
 * write, now covering BOTH relation types (§1.2.5 of the build spec).
 * Idempotent — re-adding an existing (subject, object, relationType)
 * combination is a no-op success, matching addMusicianGroupMember()'s
 * original "membership either holds or it doesn't" contract (the dedupe
 * check below deliberately ignores dates: this phase's UI offers one
 * relation row per (subject,object,type), not multiple dated stints —
 * schema.sql's uq_subject_object_rel key allows more, a future UI could
 * use it, but that is not this phase's scope).
 *
 * Guards:
 *   - table must exist (dormant-safe pre-migration)
 *   - both ids must be > 0, and $subjectId !== $objectId (no self-relation)
 *   - an install without RelationType can only express 'member' (the
 *     legacy shape) — 'portrays' is rejected with a migration-needed error
 *   - for 'member': $subjectId must reference a row with IsGroup = 1 (the
 *     pre-existing addMusicianGroupMember() guard, preserved verbatim)
 *   - for 'portrays': no IsGroup guard (any musician can be a portrayer);
 *     $subjectId merely needs to exist
 *   - $objectId must reference an existing row
 *
 * Appends at the end of the subject's current relation list (SortOrder =
 * current max + 1) — v1 has no drag-reorder UI, so every add lands last.
 *
 * Direction (verbatim from the plan): the relation verb reads FROM
 * subject TO object — for 'member' that's "subject GROUP has-member
 * object PERSON" (unchanged since #1502); for 'portrays' that's "subject
 * PORTRAYER portrays object PORTRAYED-FIGURE".
 *
 * @return array{ok:bool, error?:string, member?:array{id:int,name:string}}
 */
function addMusicianRelation(
    \mysqli $db,
    int     $subjectId,
    int     $objectId,
    string  $relationType,
    ?string $dateFrom = null,
    ?string $dateFromPrec = null,
    ?string $dateTo = null,
    ?string $dateToPrec = null,
    ?string $note = null
): array {
    if (!musicianMembersTableExists($db)) {
        return ['ok' => false, 'error' => 'Relations need a pending migration — run it from /manage/setup-database first.'];
    }
    if ($subjectId <= 0 || $objectId <= 0) {
        return ['ok' => false, 'error' => 'Both a subject and an object are required.'];
    }
    if ($subjectId === $objectId) {
        return ['ok' => false, 'error' => 'A person cannot have a relation to themselves.'];
    }

    $relCols         = musicianRelationColumnsExist($db);
    $hasRelationType = $relCols['RelationType'];
    if ($hasRelationType) {
        if (!array_key_exists($relationType, MUSICIAN_RELATION_TYPES)) {
            return ['ok' => false, 'error' => 'Unrecognised relation type.'];
        }
    } elseif ($relationType !== 'member') {
        /* Legacy install — RelationType doesn't exist, so only the
           implicit 'member' shape can be represented at all. */
        return ['ok' => false, 'error' => 'This relation type needs a pending migration — run it from /manage/setup-database first.'];
    }

    if ($relationType === 'member') {
        /* Preserved verbatim from addMusicianGroupMember() (#1502): IsGroup
           itself is a gated column (#585, migrate-musicians-flags.php) — an
           install new enough to have RelationType will almost certainly
           have that one too, but check anyway rather than assume, since a
           raw `SELECT IsGroup` would throw under mysqli's STRICT reporting
           on a column that doesn't exist. */
        if (!musicianFlagsColumnsExist($db)) {
            return ['ok' => false, 'error' => 'Group membership needs the Credit People classification-flags migration to be run first.'];
        }
        $stmt = $db->prepare('SELECT IsGroup FROM tblMusicians WHERE Id = ? LIMIT 1');
        $stmt->bind_param('i', $subjectId);
        $stmt->execute();
        $subjectRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$subjectRow) return ['ok' => false, 'error' => 'Group person not found.'];
        if ((int)($subjectRow['IsGroup'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'That person is not flagged as a Group.'];
        }
    } else {
        /* 'portrays' — no IsGroup guard; the subject just needs to exist. */
        $stmt = $db->prepare('SELECT Id FROM tblMusicians WHERE Id = ? LIMIT 1');
        $stmt->bind_param('i', $subjectId);
        $stmt->execute();
        $subjectExists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if (!$subjectExists) return ['ok' => false, 'error' => 'Person not found.'];
    }

    $stmt = $db->prepare('SELECT Name FROM tblMusicians WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $objectId);
    $stmt->execute();
    $objectRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$objectRow) return ['ok' => false, 'error' => 'Person not found.'];

    /* Subject's Name — needed for the response shape below. For 'member'
       the caller already has the subject (it's the group being edited)
       so this is only actually consumed by 'portrays' callers, but it's
       cheap and keeps the response shape uniform across both types. */
    $stmt = $db->prepare('SELECT Name FROM tblMusicians WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $subjectId);
    $stmt->execute();
    $subjectNameRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $subjectName = $subjectNameRow ? (string)$subjectNameRow['Name'] : '';

    /* Idempotent dedupe — same (subject, object[, type]) already exists.
       Filtered by RelationType only when the column exists (mirrors every
       other gate in this function); on a legacy install every row IS a
       'member' row, so the unfiltered check is already correct. */
    if ($hasRelationType) {
        $stmt = $db->prepare('SELECT Id FROM tblMusicianRelations WHERE SubjectMusicianId = ? AND ObjectMusicianId = ? AND RelationType = ? LIMIT 1');
        $stmt->bind_param('iis', $subjectId, $objectId, $relationType);
    } else {
        $stmt = $db->prepare('SELECT Id FROM tblMusicianRelations WHERE SubjectMusicianId = ? AND ObjectMusicianId = ? LIMIT 1');
        $stmt->bind_param('ii', $subjectId, $objectId);
    }
    $stmt->execute();
    $existingRow = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($existingRow) {
        return _musicianRelationAddedResponse((int)$existingRow[0], $subjectId, $subjectName, $objectId, (string)$objectRow['Name']);
    }

    $stmt = $db->prepare('SELECT COALESCE(MAX(SortOrder), -1) + 1 FROM tblMusicianRelations WHERE SubjectMusicianId = ?');
    $stmt->bind_param('i', $subjectId);
    $stmt->execute();
    $nextRow  = $stmt->get_result()->fetch_row();
    $nextSort = (int)($nextRow[0] ?? 0);
    $stmt->close();

    /* Column-gated INSERT — SubjectMusicianId/ObjectMusicianId/SortOrder
       always write; RelationType + the four date/note columns each drop
       out independently when absent (§0.3.4), never an all-or-nothing
       branch. */
    $cols  = ['SubjectMusicianId', 'ObjectMusicianId', 'SortOrder'];
    $types = 'iii';
    $vals  = [$subjectId, $objectId, $nextSort];
    if ($hasRelationType)               { $cols[] = 'RelationType';      $types .= 's'; $vals[] = $relationType; }
    if ($relCols['DateFrom'])           { $cols[] = 'DateFrom';          $types .= 's'; $vals[] = $dateFrom; }
    if ($relCols['DateFromPrecision'])  { $cols[] = 'DateFromPrecision'; $types .= 's'; $vals[] = $dateFromPrec; }
    if ($relCols['DateTo'])             { $cols[] = 'DateTo';            $types .= 's'; $vals[] = $dateTo; }
    if ($relCols['DateToPrecision'])    { $cols[] = 'DateToPrecision';   $types .= 's'; $vals[] = $dateToPrec; }
    if ($relCols['Note'])               { $cols[] = 'Note';              $types .= 's'; $vals[] = $note; }

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $stmt = $db->prepare('INSERT INTO tblMusicianRelations (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')');
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $newRelationId = (int)$db->insert_id;
    $stmt->close();

    return _musicianRelationAddedResponse($newRelationId, $subjectId, $subjectName, $objectId, (string)$objectRow['Name']);
}

/**
 * Build addMusicianRelation()'s success response. ELI5: whoever called
 * add_member/add_relation needs to know what just got added — but "what
 * got added" means a DIFFERENT side of the relation depending on
 * direction: for 'member' the caller already knows the subject (the
 * group being edited) and wants the OBJECT (the new member) back; for
 * 'portrays' the caller already knows the object (the figure being
 * edited) and wants the SUBJECT (the new portrayer) back. Rather than
 * have the two call sites guess which field means what, this returns
 * BOTH sides explicitly (`subject`/`object`) plus the relation row's own
 * Id (`relationId`, needed by the Portrayed-by sub-panel's Remove button
 * — see removeMusicianRelation()'s doc-block) — AND keeps the original
 * `member` key (`{id, name}` = the OBJECT) for back-compat with
 * addMemberRow()'s existing JS shape, unchanged since #1502.
 *
 * @return array{ok:true, relationId:int, member:array{id:int,name:string},
 *               subject:array{id:int,name:string}, object:array{id:int,name:string}}
 */
function _musicianRelationAddedResponse(
    int    $relationId,
    int    $subjectId,
    string $subjectName,
    int    $objectId,
    string $objectName
): array {
    return [
        'ok'         => true,
        'relationId' => $relationId,
        'member'     => ['id' => $objectId, 'name' => $objectName], // back-compat shape (#1502)
        'subject'    => ['id' => $subjectId, 'name' => $subjectName],
        'object'     => ['id' => $objectId, 'name' => $objectName],
    ];
}

/**
 * Add one member to a group. #1741 P4a — now a thin `'member'`-typed
 * wrapper around addMusicianRelation() (keeps this function's own
 * pre-existing IsGroup-subject guard + idempotent-dedupe + self-relation
 * guard, all preserved inside addMusicianRelation() verbatim); kept as its
 * own name because manage/musicians.php's existing `add_member` endpoint
 * (and its client-side fetch()) still calls it by this name — rule #33,
 * an internal call site is still a contract worth an alias.
 *
 * @return array{ok:bool, error?:string, member?:array{id:int,name:string}}
 */
function addMusicianGroupMember(\mysqli $db, int $groupId, int $memberId): array
{
    return addMusicianRelation($db, $groupId, $memberId, 'member');
}

/**
 * Remove one 'member'-typed relation between a group and a member.
 * Idempotent — removing a non-member (already removed, or never a
 * member) is a 0-rows-affected success, not an error.
 *
 * #1741 P4a — the DELETE now filters `RelationType = 'member'` once that
 * column exists, so removing a group member can never accidentally take a
 * 'portrays' relation between the same two rows with it (a real, if
 * unusual, possibility now that a pair can hold both relation types). On
 * a legacy install every row IS a 'member' row, so the unfiltered DELETE
 * was — and remains — correct there.
 *
 * @return array{ok:bool, error?:string, removed?:int}
 */
function removeMusicianGroupMember(\mysqli $db, int $groupId, int $memberId): array
{
    if (!musicianMembersTableExists($db)) {
        return ['ok' => false, 'error' => 'Group membership needs a pending migration — run it from /manage/setup-database first.'];
    }
    if ($groupId <= 0 || $memberId <= 0) {
        return ['ok' => false, 'error' => 'Both a group and a member are required.'];
    }
    $relCols = musicianRelationColumnsExist($db);
    if ($relCols['RelationType']) {
        $stmt = $db->prepare("DELETE FROM tblMusicianRelations WHERE SubjectMusicianId = ? AND ObjectMusicianId = ? AND RelationType = 'member'");
    } else {
        $stmt = $db->prepare('DELETE FROM tblMusicianRelations WHERE SubjectMusicianId = ? AND ObjectMusicianId = ?');
    }
    $stmt->bind_param('ii', $groupId, $memberId);
    $stmt->execute();
    $removed = $stmt->affected_rows;
    $stmt->close();
    return ['ok' => true, 'removed' => $removed];
}

/**
 * Remove one relation row by its own Id. This is what the Portrayed-by
 * sub-panel's Remove button calls (`action=remove_relation`,
 * manage/musicians.php) — unlike Members, whose rendered rows carry only
 * the OTHER person's id (so removeMusicianGroupMember() deletes by the
 * (group,member) pair instead), the Portrayed-by rows carry the relation
 * row's own Id via musicianRelationRowShape()'s `relationId` key
 * (populated by loadMusicianPortrayedBy()/…Bulk()'s `m.Id AS RelationId`
 * SELECT), so a delete-by-primary-key is both possible and simplest here.
 * Idempotent — deleting an already-gone Id is a 0-rows-affected success.
 *
 * @return array{ok:bool, error?:string, removed?:int}
 */
function removeMusicianRelation(\mysqli $db, int $relationId): array
{
    if (!musicianMembersTableExists($db)) {
        return ['ok' => false, 'error' => 'Relations need a pending migration — run it from /manage/setup-database first.'];
    }
    if ($relationId <= 0) {
        return ['ok' => false, 'error' => 'A relation id is required.'];
    }
    $stmt = $db->prepare('DELETE FROM tblMusicianRelations WHERE Id = ?');
    $stmt->bind_param('i', $relationId);
    $stmt->execute();
    $removed = $stmt->affected_rows;
    $stmt->close();
    return ['ok' => true, 'removed' => $removed];
}

/* =========================================================================
 * BULK-PROMOTE — "remaining" one-click path (#1503)
 *
 * Companion to the existing fuzzy-match review flow on
 * /manage/musicians-bulk-promote (#846): that page's job is to
 * catch NEAR-DUPLICATES ("J. Newton" vs "John Newton") before creating
 * a new registry row. This helper answers a narrower question —
 * "which cited names have NO registry row at all yet" — for the
 * one-click "Promote all remaining (N)" action on the parent
 * /manage/musicians page, which skips the fuzzy-match review
 * entirely (the curator who wants that review still has the dedicated
 * bulk-promote page). Same 5-table UNION + NOT EXISTS shape as
 * searchMusicianMergeTargets()'s in-use-only bucket and
 * musicians-bulk-promote.php's own candidate query — kept as its
 * own narrow (name-only, no per-role breakdown) helper rather than
 * forking either of those, since a plain name list is all the
 * "register everything remaining" action needs.
 * ========================================================================= */

/**
 * Every name cited on >= 1 song-credit row that has no matching
 * tblMusicians registry row yet, highest-usage first.
 *
 * @return list<string>
 */
function musicianCitedUnregisteredNames(\mysqli $db): array
{
    $sql = "
        SELECT u.Name, SUM(u.cnt) AS TotalUsage
          FROM (
              SELECT Name, COUNT(*) AS cnt FROM tblSongWriters     GROUP BY Name
              UNION ALL
              SELECT Name, COUNT(*) AS cnt FROM tblSongComposers   GROUP BY Name
              UNION ALL
              SELECT Name, COUNT(*) AS cnt FROM tblSongArrangers   GROUP BY Name
              UNION ALL
              SELECT Name, COUNT(*) AS cnt FROM tblSongAdaptors    GROUP BY Name
              UNION ALL
              SELECT Name, COUNT(*) AS cnt FROM tblSongTranslators GROUP BY Name
          ) u
         WHERE NOT EXISTS (SELECT 1 FROM tblMusicians p WHERE BINARY p.Name = BINARY u.Name)
         GROUP BY BINARY u.Name
         ORDER BY TotalUsage DESC, u.Name ASC
    ";
    /* mysqli runs under MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
       (includes/db_mysql.php) — a failing query throws rather than
       returning false, so this is a direct chain, not a false-check
       guard (project convention; see musicians-bulk-promote.php's
       identical $usageSql chain). */
    $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    return array_map(static fn(array $r): string => (string)$r['Name'], $rows);
}
