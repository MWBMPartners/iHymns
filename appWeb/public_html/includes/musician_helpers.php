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
 *               Academic / People — emitted in registry order).
 *   - icon      FontAwesome solid icon class (rendered "fa-solid <icon>").
 *   - url        printf template; "%s" is the BARE id, rawurlencode()'d when
 *               the link is built (creditIdentifierDisplayUrl()).
 *   - validate  a FULL PHP regex (delimiters included) — true ⇒ the bare id
 *               is well-formed for this provider.
 *   - extract   list of RAW regex bodies (NO delimiters): PHP wraps each as
 *               '#'.$body.'#i' and the JS mirror does new RegExp(body,'i').
 *               The bodies are deliberately PCRE/JS-compatible (capture
 *               group 1 = the bare id). Used to lift the id out of a pasted
 *               authority URL, both server-side (fallback) and client-side
 *               (the primary paste-a-URL UX).
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
];

/**
 * The whole registry. ELI5: hand back the lookup table so callers can read a
 * provider's label / icon / url without re-typing it.
 *
 * @return array<string,array{label:string,group:string,icon:string,url:string,validate:string,extract:list<string>,pickable:bool}>
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
 * ("gnd","118578537") into "https://d-nb.info/gnd/118578537". Unknown type
 * ⇒ null (the chip then renders as plain text, no link).
 *
 * The bare id is rawurlencode()'d before substitution so an id with reserved
 * characters can't break the URL or smuggle path segments.
 *
 * @link https://www.php.net/manual/en/function.rawurlencode.php
 */
function creditIdentifierDisplayUrl(string $type, string $value): ?string
{
    $reg = CREDIT_IDENTIFIER_TYPES[$type] ?? null;
    if ($reg === null) return null;
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

/**
 * Fetch every member of one Group person, in SortOrder/Id order
 * (append-order — v1 has no drag-reorder UI). Slug is included (NULL on
 * a pre-#588 install) so callers that need to link to a member's public
 * page can do so without a second query.
 *
 * @return list<array{id:int,name:string,slug:?string}>
 */
function loadMusicianGroupMembers(\mysqli $db, int $groupId): array
{
    if ($groupId <= 0 || !musicianMembersTableExists($db)) return [];
    $slugCol = musicianSlugColumnExists($db) ? 'p.Slug' : 'NULL';
    $stmt = $db->prepare(
        "SELECT p.Id AS Id, p.Name AS Name, {$slugCol} AS Slug
           FROM tblMusicianRelations m
           JOIN tblMusicians p ON p.Id = m.ObjectMusicianId
          WHERE m.SubjectMusicianId = ?
       ORDER BY m.SortOrder ASC, m.Id ASC"
    );
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['Id'], 'name' => (string)$r['Name'], 'slug' => $r['Slug']];
    }
    return $out;
}

/**
 * Bulk-load group members for many group-person ids in one round-trip.
 * Used by /manage/musicians's list-view render so the Edit drawer's
 * Members pre-fill doesn't cost an extra query per row (mirrors
 * loadMusicianAliasesBulk() above).
 *
 * @param list<int> $groupIds
 * @return array<int, list<array{id:int,name:string,slug:?string}>> SubjectMusicianId → member rows
 */
function loadMusicianGroupMembersBulk(\mysqli $db, array $groupIds): array
{
    $groupIds = array_values(array_filter(array_map('intval', $groupIds), fn($v) => $v > 0));
    if (!$groupIds || !musicianMembersTableExists($db)) return [];
    $slugCol = musicianSlugColumnExists($db) ? 'p.Slug' : 'NULL';
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $types        = str_repeat('i', count($groupIds));
    $stmt = $db->prepare(
        "SELECT m.SubjectMusicianId AS GroupId, p.Id AS Id, p.Name AS Name, {$slugCol} AS Slug
           FROM tblMusicianRelations m
           JOIN tblMusicians p ON p.Id = m.ObjectMusicianId
          WHERE m.SubjectMusicianId IN ($placeholders)
       ORDER BY m.SubjectMusicianId ASC, m.SortOrder ASC, m.Id ASC"
    );
    $stmt->bind_param($types, ...$groupIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $grouped = [];
    foreach ($rows as $r) {
        $gid = (int)$r['GroupId'];
        $grouped[$gid][] = ['id' => (int)$r['Id'], 'name' => (string)$r['Name'], 'slug' => $r['Slug']];
    }
    return $grouped;
}

/**
 * Add one member to a group. Idempotent — re-adding an existing member
 * is a no-op success (matches the UNIQUE (SubjectMusicianId, ObjectMusicianId)
 * key's intent: "membership either holds or it doesn't", not an error a
 * curator has to dismiss on a double-click). Guards:
 *   - table must exist (dormant-safe pre-migration)
 *   - both ids must be > 0
 *   - $groupId must reference a row with IsGroup = 1 (a plain individual
 *     is not a valid membership parent)
 *   - $memberId must reference an existing row
 *   - $groupId !== $memberId (no self-membership)
 *
 * Appends at the end of the group's current member list (SortOrder =
 * current max + 1) — v1 has no drag-reorder UI, so every add lands last.
 *
 * @return array{ok:bool, error?:string, member?:array{id:int,name:string}}
 */
function addMusicianGroupMember(\mysqli $db, int $groupId, int $memberId): array
{
    if (!musicianMembersTableExists($db)) {
        return ['ok' => false, 'error' => 'Group membership needs a pending migration — run it from /manage/setup-database first.'];
    }
    if ($groupId <= 0 || $memberId <= 0) {
        return ['ok' => false, 'error' => 'Both a group and a member are required.'];
    }
    if ($groupId === $memberId) {
        return ['ok' => false, 'error' => 'A group cannot list itself as a member.'];
    }
    /* IsGroup itself is a gated column (#585, migrate-musicians-flags.php)
       — an install new enough to have run THIS migration will almost
       certainly have that one too, but check anyway rather than assume,
       since a raw `SELECT IsGroup` would throw under mysqli's STRICT
       reporting on a column that doesn't exist (matches the same gate
       musicianFlagsColumnsExist() enforces elsewhere in this file). */
    if (!musicianFlagsColumnsExist($db)) {
        return ['ok' => false, 'error' => 'Group membership needs the Credit People classification-flags migration to be run first.'];
    }

    $stmt = $db->prepare('SELECT IsGroup FROM tblMusicians WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $groupRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$groupRow) return ['ok' => false, 'error' => 'Group person not found.'];
    if ((int)($groupRow['IsGroup'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'That person is not flagged as a Group.'];
    }

    $stmt = $db->prepare('SELECT Name FROM tblMusicians WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $memberRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$memberRow) return ['ok' => false, 'error' => 'Member person not found.'];

    $stmt = $db->prepare('SELECT Id FROM tblMusicianRelations WHERE SubjectMusicianId = ? AND ObjectMusicianId = ? LIMIT 1');
    $stmt->bind_param('ii', $groupId, $memberId);
    $stmt->execute();
    $already = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($already) {
        return ['ok' => true, 'member' => ['id' => $memberId, 'name' => (string)$memberRow['Name']]];
    }

    $stmt = $db->prepare('SELECT COALESCE(MAX(SortOrder), -1) + 1 FROM tblMusicianRelations WHERE SubjectMusicianId = ?');
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $nextRow  = $stmt->get_result()->fetch_row();
    $nextSort = (int)($nextRow[0] ?? 0);
    $stmt->close();

    $stmt = $db->prepare(
        'INSERT INTO tblMusicianRelations (SubjectMusicianId, ObjectMusicianId, SortOrder) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('iii', $groupId, $memberId, $nextSort);
    $stmt->execute();
    $stmt->close();

    return ['ok' => true, 'member' => ['id' => $memberId, 'name' => (string)$memberRow['Name']]];
}

/**
 * Remove one member from a group. Idempotent — removing a non-member
 * (already removed, or never a member) is a 0-rows-affected success,
 * not an error.
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
    $stmt = $db->prepare('DELETE FROM tblMusicianRelations WHERE SubjectMusicianId = ? AND ObjectMusicianId = ?');
    $stmt->bind_param('ii', $groupId, $memberId);
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
         WHERE NOT EXISTS (SELECT 1 FROM tblMusicians p WHERE p.Name = u.Name)
         GROUP BY u.Name
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
