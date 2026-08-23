<?php

declare(strict_types=1);

/**
 * iHymns — Musician Public Page (#588, #1741 P2-B, P4a, P4a-3)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Public landing page for a `tblMusicians` registry row. Surfaces
 * the bio, lifespan, special-case / group classification, external
 * links, and a discography grouped by role across the six song-credit
 * tables.
 *
 * #1741 P4a additions: the entity-type vocabulary (Type — person / group
 * / character / orchestra / other, replacing the old flag-only icon
 * ladder), a dedicated public Biography field (falling back to the
 * internal-only Notes when empty — see the `notes-as-bio-fallback`
 * marker below), Disambiguation ("(the elder)"-style short parentheticals),
 * and the generalised tblMusicianRelations 'portrays' relation
 * (Portrayed-by / Portrays cards, for character/other-type rows) —
 * @link .claude/catalogue-1741-P4-plan.md §1.3
 *
 * #1741 P4a-3 additions (writer/person page consolidation, owner decision
 * D4): `includes/pages/writer.php` was DELETED and this page now also
 * serves every legacy `/writer/<name-slug>` request (api.php's
 * `case 'writer'` requires this file directly). Before the registry
 * lookup, `musicianResolveLegacySlugDb()` (musician_helpers.php) resolves
 * a name-slug or punctuated-slug input to the real registry Slug when
 * one exists; when a registry row DOES render, the section tag carries
 * `data-musician-canonical` so router.js can soft-canonicalise the
 * address bar via history.replaceState (no reload). The no-registry-row
 * discography fallback below was widened to try the historic writer.php
 * name-variant set (not just the title-cased spaced name) so a
 * hyphenated credited name like "Smith-Jones" still lists.
 * @link .claude/catalogue-1741-P4a3-plan.md the full design + decisions
 *
 * Loaded via api.php?page=person&slug=cecil-frances-humphreys-alexander,
 * OR api.php?page=writer&id=<legacy-name-slug> (P4a-3).
 * Expects $personSlug to be set by api.php before inclusion.
 *
 * Falls back to /writer/<slug> behaviour for installs that haven't
 * applied the migrate-musicians-slug migration yet — the slug
 * lookup short-circuits to a name-based search across the credit
 * tables, so the page still works even before the registry row
 * exists.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* #1786 — ihymns_title_sort_key() for the discography's Title sort key. */
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'sort_helpers.php';

/**
 * Slug → display name when no registry row exists. Mirrors the
 * /writer/<slug> page's fallback behaviour.
 */
function _personSlugToName(string $slug): string
{
    $slug = urldecode($slug);
    return mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE, 'UTF-8');
}

$person = null;            /* tblMusicians row, or null on partial install / unknown slug */
$personName = '';          /* always set — falls back to slug-derived name */
$db = getDbMysqli();

/**
 * #1741 P4a-3 — legacy-slug resolution, BEFORE the registry SELECT below.
 *
 * ELI5: this page can be reached three ways that don't necessarily carry
 * the registry's own slug — a legacy /writer/<name-slug> URL
 * (api.php's `case 'writer'` sets $personSlug = the writer id and
 * requires this file), a name-slug /musician/ credit link built by
 * song.php:700/:1252 (which folds a credited NAME, not a registry
 * Slug), or the /person|/people 301 alias. This resolves whichever of
 * those the incoming slug is into the real registry slug FIRST, so the
 * `WHERE Slug = ?` lookup immediately below always sees a canonical
 * value when one exists.
 *
 * DETAILED / WHY: `musicianResolveLegacySlugDb()` is the ONE shared
 * ladder (musician_helpers.php, rule #22 — never re-fork the fold) and
 * is FAIL-OPEN: on an un-migrated install (no Slug column / table) it
 * returns null and $personSlug is left exactly as it arrived, so this
 * page behaves precisely as it did before this slice — the name-based
 * fallback in §5 below still fires. `require_once` is guarded by
 * `function_exists()` because musician.php can be `require`d more than
 * once per PHP process in the P4 verification probe (see the build
 * spec's §5.4 live-probe note) and a bare `require_once` of a file that
 * unconditionally redeclares functions would still be safe, but the
 * guard costs nothing and matches the file's own existing convention
 * (see `musicianProfileColumnsExist()`'s guard a few lines below).
 *
 * @see includes/musician_helpers.php musicianResolveLegacySlugDb()
 * @link .claude/catalogue-1741-P4a3-plan.md §1.2 the ladder's design
 */
/* #1860 Phase 4 — dual-addressing pre-step, ahead of the legacy-slug ladder
   below: an IL internal id ('ILM…') resolves to the registry's real Slug
   and $personSlug is replaced with it, so every rung after this one (incl.
   the legacy-slug ladder and the name-based fallback) sees a canonical
   value exactly as if the curator had typed the real slug. A miss (not an
   IL id, or the column doesn't exist yet) leaves $personSlug UNCHANGED —
   never rejects, never errors. try/catch-swallowed + column-probe-gated so
   this page can never white-screen on an un-migrated install (the #1228
   lesson). */
try {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ilyrics_id.php';
    $_ilParsed = ilidParse((string)($personSlug ?? ''));
    if ($_ilParsed !== null && $_ilParsed['entityType'] === 'musician') {
        $_ilColProbe = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblMusicians' AND COLUMN_NAME = 'IlId' LIMIT 1"
        );
        $_ilColExists = $_ilColProbe && $_ilColProbe->fetch_row() !== null;
        if ($_ilColProbe) { $_ilColProbe->free(); }
        if ($_ilColExists) {
            $_ilStmt = $db->prepare('SELECT Slug FROM tblMusicians WHERE IlId = ? LIMIT 1');
            $_ilStmt->bind_param('s', $_ilParsed['canonical']);
            $_ilStmt->execute();
            $_ilRow = $_ilStmt->get_result()->fetch_assoc();
            $_ilStmt->close();
            if ($_ilRow !== null && (string)($_ilRow['Slug'] ?? '') !== '') {
                $personSlug = (string)$_ilRow['Slug'];
            }
        }
    }
} catch (\Throwable $_ilE) {
    // dormant-by-design — fall through to the legacy-slug ladder unchanged
}

if (!function_exists('musicianResolveLegacySlugDb')) {
    require_once dirname(__DIR__) . '/musician_helpers.php';
}
$_resolvedSlug = musicianResolveLegacySlugDb($db, $personSlug);
if ($_resolvedSlug !== null) { $personSlug = $_resolvedSlug; }

/* ---------------------------------------------------------------------- */
/* 1. Look up the registry row (if the migration has been applied).       */
/* ---------------------------------------------------------------------- */
try {
    /* #1348 — include the typed MusicBrainzArtistMBID column only if it exists
       (#1090 may be un-migrated; an absent column would 1054 under STRICT and blank
       the page). The other authority identifiers live in tblMusicianIdentifiers
       (read separately below). $mbidCol is a hardcoded constant — the only
       legitimate string interpolation into SQL (rule #5). */
    $mbidCol = '';
    try {
        $pc = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblMusicians' AND COLUMN_NAME = 'MusicBrainzArtistMBID' LIMIT 1");
        if ($pc && $pc->fetch_row() !== null) { $mbidCol = ', MusicBrainzArtistMBID'; }
        if ($pc) { $pc->free(); }
    } catch (\Throwable $_e) { $mbidCol = ''; }

    /* #1741 P4a — Type / Biography / Disambiguation, per-column gated via
       the shared musicianProfileColumnsExist() probe (§0.3.1 conditional
       select fragment idiom — same shape as $mbidCol just above). Each
       column drops out to a hardcoded-constant default independently
       when absent, so this page renders shape-blind either way. */
    if (!function_exists('musicianProfileColumnsExist')) {
        require_once dirname(__DIR__) . '/musician_helpers.php';
    }
    $profileCols = musicianProfileColumnsExist($db);
    $extraCols  = $profileCols['Type']           ? ', Type'           : ", 'person' AS Type";
    $extraCols .= $profileCols['Biography']      ? ', Biography'      : ', NULL AS Biography';
    $extraCols .= $profileCols['Disambiguation'] ? ', Disambiguation' : ", '' AS Disambiguation";

    $stmt = $db->prepare(
        "SELECT Id, Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate,
                COALESCE(IsSpecialCase, 0) AS IsSpecialCase,
                COALESCE(IsGroup, 0)        AS IsGroup{$mbidCol}{$extraCols}
           FROM tblMusicians
          WHERE Slug = ?
          LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('s', $personSlug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $person = $row;
            $personName = (string)$row['Name'];
        }
    }
} catch (\Throwable $_e) {
    /* Slug column doesn't exist yet (pre-migration) — fall through to
       the name-based fallback below. */
}

/* No registry row matched → derive the display name from the slug. */
if ($personName === '') {
    $personName = _personSlugToName($personSlug);
}

/* AKA / aliases — pull every alternative name attached to this
   registry row so the header card can render "Also known as: …" and
   the JSON-LD block can emit alternateName. Schema-tolerant: returns
   an empty array on installs that haven't run the aliases migration. */
$personAliases = [];
if ($person && isset($person['Id'])) {
    if (!function_exists('loadMusicianAliases')) {
        require_once dirname(__DIR__) . '/musician_helpers.php';
    }
    try {
        $personAliases = loadMusicianAliases($db, (int)$person['Id']);
    } catch (\Throwable $_e) {
        $personAliases = [];
    }
}

/* Group members (#1502) — the individual people that make up this
   Group/band/collective musician row (IsGroup=1, #585). Public,
   read-only list; admin add/remove lives in the /manage/musicians
   Edit drawer. Schema-tolerant: returns an empty array on installs
   that haven't run migrate-add-creditperson-members.php yet. */
$personMembers = [];
if ($person && (int)($person['IsGroup'] ?? 0) === 1 && isset($person['Id'])) {
    if (!function_exists('loadMusicianGroupMembers')) {
        require_once dirname(__DIR__) . '/musician_helpers.php';
    }
    try {
        $personMembers = loadMusicianGroupMembers($db, (int)$person['Id']);
    } catch (\Throwable $_e) {
        $personMembers = [];
    }
}

/* #1741 P4a — "Portrayed by" (who is credited as portraying THIS
   figure) / "Portrays" (which figures THIS person is credited as
   portraying) — read-only public cards, admin add/remove lives in the
   /manage/musicians Edit drawer's Portrayed-by sub-panel. Both loaders
   are RelationType-gated and return [] on an install without it, so no
   type check is needed here — a non-character/non-portrayer row simply
   gets two empty arrays and neither card renders (§1.3.5). */
$personPortrayedBy = [];
$personPortrays     = [];
if ($person && isset($person['Id'])) {
    if (!function_exists('loadMusicianPortrayedBy')) {
        require_once dirname(__DIR__) . '/musician_helpers.php';
    }
    try {
        $personPortrayedBy = loadMusicianPortrayedBy($db, (int)$person['Id']);
    } catch (\Throwable $_e) {
        $personPortrayedBy = [];
    }
    try {
        $personPortrays = loadMusicianPortrays($db, (int)$person['Id']);
    } catch (\Throwable $_e) {
        $personPortrays = [];
    }
}

/* External authority identifiers (#1348) — the key-value rows from
   tblMusicianIdentifiers (ipi / isni / viaf / wikidata / orcid / …, the table
   widened from ENUM in #1090 P6 so new types need no ALTER). Rendered as bare-code
   chips in the header, mirroring a song's ISWC/CCLI. Schema-tolerant: empty on an
   install without the table. The typed MusicBrainzArtistMBID column (from the SELECT
   above) is folded in at render time. */
$personIdentifiers = [];
if ($person && isset($person['Id'])) {
    try {
        $pid = (int)$person['Id'];
        $idStmt = $db->prepare(
            'SELECT IdentifierType, IdentifierValue
               FROM tblMusicianIdentifiers
              WHERE MusicianId = ?
              ORDER BY IdentifierType ASC, Id ASC'
        );
        if ($idStmt) {
            $idStmt->bind_param('i', $pid);
            $idStmt->execute();
            $rs = $idStmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $t = strtolower(trim((string)$r['IdentifierType']));
                $v = trim((string)$r['IdentifierValue']);
                if ($t !== '' && $v !== '') { $personIdentifiers[] = ['type' => $t, 'value' => $v]; }
            }
            $idStmt->close();
        }
    } catch (\Throwable $_e) {
        $personIdentifiers = []; /* table absent (un-migrated) — no chips */
    }
}

/* ---------------------------------------------------------------------- */
/* 2. Discography by role — count + list across all six credit tables.    */
/* ---------------------------------------------------------------------- */
$roleTables = [
    'writer'     => ['table' => 'tblSongWriters',     'label' => 'As Writer',      'icon' => 'fa-pen-fancy'],
    'composer'   => ['table' => 'tblSongComposers',   'label' => 'As Composer',    'icon' => 'fa-music'],
    'arranger'   => ['table' => 'tblSongArrangers',   'label' => 'As Arranger',    'icon' => 'fa-sliders'],
    'adaptor'    => ['table' => 'tblSongAdaptors',    'label' => 'As Adaptor',     'icon' => 'fa-compact-disc'],
    'translator' => ['table' => 'tblSongTranslators', 'label' => 'As Translator',  'icon' => 'fa-language'],
    'artist'     => ['table' => 'tblSongArtists',     'label' => 'As Artist',      'icon' => 'fa-microphone'],
];

/* tblSongArtists ships in a separate migration (#587); skip it
   gracefully on installs that haven't applied that migration. */
function _personPageArtistsTableExists(\mysqli $db): bool
{
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongArtists' LIMIT 1"
    );
    $exists = $r && $r->fetch_row() !== null;
    if ($r) $r->close();
    return $exists;
}
if (!_personPageArtistsTableExists($db)) {
    unset($roleTables['artist']);
}

/**
 * #1741 P4a-3 — the discography's credited-name candidate list.
 *
 * ELI5: when there's a real profile (a registry row matched), we only
 * ever looked up songs by its exact Name — that stays true. When there
 * ISN'T one (an un-registered writer, or a legacy slug the ladder above
 * couldn't resolve), the old /writer/<slug> page tried a few spelling
 * variants of the slug before giving up; this keeps that same courtesy
 * so those pages still list songs instead of 404ing.
 *
 * DETAILED / WHY: this is the D4 "no-silent-404" guarantee from the
 * P4a-3 build spec §1.3 — the ONE gap between the old two-role
 * writer.php and this six-role fallback. writer.php additionally tried
 * the RAW slug as a credited name (`smith-jones`), which the
 * title-cased-spaced-only fallback here never did; a hyphenated
 * credited name like "Smith-Jones" spaces to "Smith Jones" and would
 * never match. `musicianLegacySlugPlan()` (the SAME plan-builder the
 * ladder above consumes — rule #22, one fold, not two) already produces
 * exactly writer.php's historic variant set (title-cased spaced,
 * spaced, raw slug), CI-deduped.
 *
 * One `Name IN (...)` query SHAPE serves both branches deliberately —
 * a registry hit binds an N-element list (canonical Name + every alias
 * name, #1754), so there is no second SQL string to keep in sync (rule
 * #35: cross-file/cross-branch agreement needs ONE mechanism, not two
 * copies that could drift). Case-fold is free: the credit tables' utf8mb4
 * collation is already case-insensitive, same property
 * `SongData::getSongsByCreditName()` makes explicit with `LOWER()` for its
 * own (unrelated) caller.
 *
 * @see musicianLegacySlugPlan() musician_helpers.php — the one fold
 * @link .claude/catalogue-1741-P4a3-plan.md §1.3 "The closure"
 */
/* #1754 — ELI5: when there IS a registry profile, list songs credited under
   its canonical name AND every alias it has on file (a pen name, a
   misspelling someone typed into a credit line, …) — not just the one
   canonical spelling.
   DETAILED / WHY: the registry branch widens to canonical Name + EVERY
   alias name (all types, including search-hint/misspelling: they are match
   keys against how credits were actually typed, not display strings —
   taken default). Pairs with the ladder's rung 4 (musician_helpers.php): an
   alias URL now resolves to THIS registry page, so this list must cover at
   least what the old name-fallback listed for that alias, or the redirect
   "loses songs". $personAliases is loaded above at :162-172, safely before
   this point. $totalSongs dedupes by SongId (:334 below) so overlap across
   names never double-counts. @see #1754 */
$creditNames = $person !== null
    ? array_values(array_unique(array_merge(
          [$personName],
          array_map(static fn(array $a): string => (string)$a['Name'], $personAliases)
      )))
    : musicianLegacySlugPlan($personSlug)['names'];

$discography = [];
$totalSongs = 0;
$matchedSongIds = [];
foreach ($roleTables as $roleKey => $cfg) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'songbook_visibility.php';   /* #1765 */
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'songbook_display.php';       /* #1531 — ihymns_songbook_name_label() */
    $creditPh = implode(',', array_fill(0, count($creditNames), '?'));
    /* #1531 — pull the songbook full NAME (tblSongbooks.Name) alongside the
       abbreviation so the discography sub-line can show "Seventh-day Adventist
       Hymnal" instead of the bare "SDAH" code, via the shared
       ihymns_songbook_name_label() helper (server twin of JS songbookLabel()).
       LEFT JOIN so a song whose songbook row is missing still lists (name
       degrades to the abbr in the helper). */
    $sql = "SELECT s.SongId, s.Title, s.SongbookAbbr, s.Number, sb.Name AS SongbookName
              FROM {$cfg['table']} c
              JOIN tblSongs s ON s.SongId = c.SongId
              LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
             WHERE c.Name IN ($creditPh) AND " . songVisibleSql($db, 's') . "
               AND " . songServableSql($db, 's') . "
             ORDER BY s.SongbookAbbr, s.Number";   /* #1694/#1765 — visible songs only, in a non-disabled songbook */
    try {
        $stmt = $db->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($creditNames)), ...$creditNames);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($rows) {
            $discography[$roleKey] = [
                'cfg'   => $cfg,
                'songs' => $rows,
            ];
            foreach ($rows as $r) { $matchedSongIds[$r['SongId']] = true; }
        }
    } catch (\Throwable $_e) {
        /* Table missing / query failed — skip this role. */
    }
}
$totalSongs = count($matchedSongIds);

/* ---------------------------------------------------------------------- */
/* 2b. Compiled songbooks (#831). Schema-probed — pre-migration deploys   */
/*     get an empty list so the section is hidden without a fatal.        */
/* ---------------------------------------------------------------------- */
$compiledBooks = [];
if ($person && (int)$person['Id'] > 0) {
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongbookCompilers' LIMIT 1"
        );
        $hasCompTable = $r && $r->fetch_row() !== null;
        if ($r) $r->close();
        if ($hasCompTable) {
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'songbook_visibility.php';   /* #1765 */
            $stmt = $db->prepare(
                'SELECT b.Abbreviation AS abbr, b.Name AS name, b.SongCount AS songCount,
                        c.Note         AS note,  c.SortOrder AS sortOrder
                   FROM tblSongbookCompilers c
                   JOIN tblSongbooks b ON b.Id = c.SongbookId
                  WHERE c.MusicianId = ? AND ' . songbookVisibleSql($db) . '
                  ORDER BY b.Name ASC'
            );   /* #1765 — a disabled songbook is not listed as compiled here */
            $pid = (int)$person['Id'];
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $compiledBooks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } catch (\Throwable $_e) { /* table missing — leave empty */ }
}

/* ---------------------------------------------------------------------- */
/* 3. External links (when the registry row exists).                      */
/*    Reads tblMusicianExternalLinks (the new unified system, #833)   */
/*    if it has rows for this person, otherwise falls back to the legacy  */
/*    tblMusicianLinks. The fallback keeps deployments that haven't   */
/*    run the backfill from losing data; the migration ensures both       */
/*    tables stay in sync once it's been applied.                         */
/* ---------------------------------------------------------------------- */
$links = [];        /* legacy shape — LinkType / Url / Label */
$linksUnified = []; /* unified shape — slug / name / category / url / note / verified / iconClass */
if ($person && (int)$person['Id'] > 0) {
    /* Try the new system first. */
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblMusicianExternalLinks' LIMIT 1"
        );
        $hasNew = $r && $r->fetch_row() !== null;
        if ($r) $r->close();
        if ($hasNew) {
            $stmt = $db->prepare(
                'SELECT t.Slug      AS slug,
                        t.Name      AS name,
                        t.Category  AS category,
                        t.IconClass AS iconClass,
                        el.Url      AS url,
                        el.Note     AS note,
                        el.Verified AS verified
                   FROM tblMusicianExternalLinks el
                   JOIN tblExternalLinkTypes t ON t.Id = el.LinkTypeId
                  WHERE el.MusicianId = ?
                    AND COALESCE(t.IsActive, 1) = 1
                  ORDER BY t.Category, el.SortOrder ASC, t.DisplayOrder ASC, t.Name ASC'
            );
            $pid = (int)$person['Id'];
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $linksUnified = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($linksUnified as &$_l) { $_l['verified'] = (bool)$_l['verified']; }
            unset($_l);
        }
    } catch (\Throwable $_e) { /* fall through to legacy */ }

    /* Legacy fallback when the new table has no rows for this person. */
    if (empty($linksUnified)) {
        try {
            $stmt = $db->prepare(
                "SELECT LinkType, Url, Label
                   FROM tblMusicianLinks
                  WHERE MusicianId = ?
                  ORDER BY SortOrder, Id"
            );
            $stmt->bind_param('i', $person['Id']);
            $stmt->execute();
            $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (\Throwable $_e) { /* both tables missing — links stay empty */ }
    }
}

/* ---------------------------------------------------------------------- */
/* 4. Lifespan formatting. Adapts for groups (Founded / Dissolved).        */
/* ---------------------------------------------------------------------- */
function _personFormatLifespan(?string $birth, ?string $death, bool $isGroup): string
{
    $bYear = $birth ? substr($birth, 0, 4) : '';
    $dYear = $death ? substr($death, 0, 4) : '';
    if ($bYear === '' && $dYear === '') return '';
    if ($isGroup) {
        if ($bYear !== '' && $dYear !== '')  return 'Active ' . $bYear . '–' . $dYear;
        if ($bYear !== '')                   return 'Active since ' . $bYear;
        return 'Dissolved ' . $dYear;
    }
    if ($bYear !== '' && $dYear !== '')  return $bYear . '–' . $dYear;
    if ($bYear !== '')                   return 'b. ' . $bYear;
    return 'd. ' . $dYear;
}

/**
 * #1741 P4a — format a tblMusicianRelations date range (member tenure /
 * portrayal dates) for display, honouring per-boundary precision (a
 * 'year' precision shows just "1998", not "1998-01-01"). Shared by the
 * Members card and the Portrayed-by/Portrays cards below so all three
 * date-range renders agree byte-for-byte.
 *
 * @param array{dateFrom?:?string,dateFromPrecision?:?string,dateTo?:?string,dateToPrecision?:?string} $m
 * @return string '' when neither date is set.
 */
function _personFormatRelationDateRange(array $m): string
{
    $fmt = static function (?string $d, ?string $prec): string {
        if (!$d) return '';
        return $prec === 'year' ? substr($d, 0, 4) : $d;
    };
    $fromStr = $fmt($m['dateFrom'] ?? null, $m['dateFromPrecision'] ?? null);
    $toStr   = $fmt($m['dateTo']   ?? null, $m['dateToPrecision']   ?? null);
    if ($fromStr !== '' && $toStr !== '') return $fromStr . '–' . $toStr;
    if ($fromStr !== '')                  return 'since ' . $fromStr;
    if ($toStr !== '')                    return '–' . $toStr;
    return '';
}

/* $lifespanText itself is computed further below (§8, #1741 P4a) once
   $isGroupish is known — Type widens the "Active/Founded" wording beyond
   the legacy IsGroup-only check to also cover 'orchestra'. */

/* ---------------------------------------------------------------------- */
/* 5. 404 if neither a registry row nor any credited songs match.         */
/* ---------------------------------------------------------------------- */
if (!$person && $totalSongs === 0) {
    http_response_code(404);
    if (function_exists('renderErrorFragment')) {
        echo renderErrorFragment(404, [
            'title'   => 'Person not found',
            'message' => 'We couldn\'t find anyone matching "' . $personName . '" — no registry entry and no credited songs.',
            'fa'      => 'fa-user',
            'actions' => [
                ['label' => 'Browse Songbooks', 'href' => '/songbooks', 'navigate' => 'songbooks', 'primary' => true, 'fa' => 'fa-book-open'],
                ['label' => 'Search',           'href' => '/search',     'navigate' => 'search',    'fa' => 'fa-magnifying-glass'],
            ],
        ]);
    } else {
        echo '<div class="alert alert-warning" role="alert">No person found for: <strong>'
           . htmlspecialchars($personName) . '</strong></div>';
    }
    return;
}

/* ---------------------------------------------------------------------- */
/* 6. Compute the headline classification line.                           */
/* ---------------------------------------------------------------------- */
$rolesForBadges = [];
foreach ($discography as $rk => $entry) {
    /* Just the role name in the subtitle — the per-role song count is already
       shown (in context) on each "As Writer (N)" / "As Composer (N)" section
       header below, and surfacing both counts here read as a confusing
       "24 + 25 ≠ 27" to users (the totals overlap when someone is both writer
       and composer of a song). The distinct total is shown after the roles. */
    $rolesForBadges[] = ucfirst($rk);
}

/* ---------------------------------------------------------------------- */
/* 7. #1741 P4a — Bio switch: Biography (new, public) wins; empty falls    */
/*    back to Notes (the pre-P4a public read path). Once a curator fills   */
/*    in Biography, Notes never renders publicly again — Notes becomes     */
/*    the "internal — not shown publicly" field (manage/musicians.php's    */
/*    drawer label). The literal marker comment below is grepped by        */
/*    tests/php/test-musician-profile-fields.php (mirrors the              */
/*    `lines-json-fallback` convention, rule #25).                         */
/* ---------------------------------------------------------------------- */
$bioText = trim((string)($person['Biography'] ?? ''));
if ($bioText === '') {
    /* notes-as-bio-fallback */
    $bioText = trim((string)($person['Notes'] ?? ''));
}

/* ---------------------------------------------------------------------- */
/* 8. #1741 P4a — Type presentation, replacing the flag-only icon ladder.  */
/*    Falls back to the legacy IsGroup/IsSpecialCase flags when Type is    */
/*    absent (pre-migration install) — $personType stays 'person'/'group'/ */
/*    'other' either way so every render site below can key on ONE value. */
/* ---------------------------------------------------------------------- */
$MUSICIAN_TYPE_PRESENTATION = [
    'person'    => ['icon' => 'fa-user-pen',           'badge' => null,          'italic' => false],
    'group'     => ['icon' => 'fa-users',               'badge' => 'Group',       'italic' => false],
    'orchestra' => ['icon' => 'fa-users-line',           'badge' => 'Orchestra',   'italic' => false],
    'character' => ['icon' => 'fa-masks-theater',        'badge' => 'Character',   'italic' => true],
    'other'     => ['icon' => 'fa-circle-question',      'badge' => null,          'italic' => true],
];
if ($person && !empty($person['Type']) && isset($MUSICIAN_TYPE_PRESENTATION[$person['Type']])) {
    $personType = (string)$person['Type'];
} else {
    /* Pre-Type-column fallback, derived from the legacy flags. */
    $personType = $person && (int)($person['IsGroup'] ?? 0) === 1
        ? 'group'
        : ($person && (int)($person['IsSpecialCase'] ?? 0) === 1 ? 'other' : 'person');
}
$personTypePresentation = $MUSICIAN_TYPE_PRESENTATION[$personType] ?? $MUSICIAN_TYPE_PRESENTATION['person'];
/* "group-ish" for the lifespan wording (Active/Founded vs b./d.) — group
   AND orchestra both use the "Active …" phrasing, matching the pre-Type
   IsGroup-only behaviour extended to the new orchestra type. */
$isGroupish = in_array($personType, ['group', 'orchestra'], true);
$lifespanText = $person
    ? _personFormatLifespan($person['BirthDate'], $person['DeathDate'], $isGroupish)
    : '';
$personDisambiguation = trim((string)($person['Disambiguation'] ?? ''));

?>

<!-- ================================================================
     MUSICIAN PAGE — Public landing for a tblMusicians registry row
     ================================================================ -->
<?php
/* #1741 P4a-3 — the canonicalisation marker's explanatory comment MUST be
   a PHP comment, never an HTML comment, on this line: an HTML `<!-- -->`
   is static markup PHP does not strip, so it renders into the fragment's
   OUTPUT unconditionally — including on the no-registry-row fallback
   branch, where the marker attribute itself is deliberately absent. An
   HTML comment merely MENTIONING the literal string
   "data-musician-canonical" would therefore make every fallback page's
   HTML contain that substring anyway, defeating any caller (including
   this feature's own live probe, build spec §5.4 P4) that checks
   `!str_contains($html, 'data-musician-canonical')` to confirm the
   fallback emits NO fake canonical. The marker is emitted ONLY when a
   registry row rendered ($person !== null) — a bare fallback page has no
   canonical /musician/ URL to point at. router.js's afterPageLoad() reads
   it and history.replaceState()s the address bar to it — the #1343-B
   [data-song-canonical] pattern applied to legacy /writer/<name-slug>
   hits, name-slug /musician/ credit links (song.php:700/:1252), and
   /person|/people alias navigations, all for the price of one marker.
   @link .claude/catalogue-1741-P4a3-plan.md §1.1 leg C */
?>
<section class="page-musician"<?= $person !== null
    ? ' data-musician-canonical="/musician/' . htmlspecialchars(rawurlencode($personSlug)) . '"'
    : '' ?> aria-label="<?= htmlspecialchars($personName) ?>">

    <!-- Breadcrumb -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="/songbooks" data-navigate="songbooks">Songbooks</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                <?= htmlspecialchars($personName) ?>
            </li>
        </ol>
    </nav>

    <!-- Header card -->
    <div class="card card-song-header mb-4">
        <div class="card-body">
            <!-- #1741 P4a — per-Type icon + badge, replacing the old
                 IsGroup/IsSpecialCase-only icon ladder. Falls back to the
                 legacy flags automatically via $personType (computed
                 above). The badge is folded INSIDE the <h1> — the #1223
                 badge-a11y pattern (the "Unofficial" songbook badge, and
                 the language badge before it) — so a screen reader's
                 accessible name for the heading includes e.g. "Hillsong
                 United Group", not just the bare name. -->
            <h1 class="h4 mb-1 d-flex flex-wrap align-items-center gap-2">
                <i class="fa-solid <?= htmlspecialchars($personTypePresentation['icon']) ?><?= $personTypePresentation['badge'] === null && $personType === 'person' ? '' : ' text-info' ?>" aria-hidden="true" title="<?= htmlspecialchars(MUSICIAN_TYPES[$personType] ?? 'Person') ?>"></i>
                <span class="<?= $personTypePresentation['italic'] ? 'fst-italic' : '' ?>"><?= htmlspecialchars($personName) ?></span>
                <?php if ($personDisambiguation !== ''): ?>
                    <small class="text-muted fw-normal">(<?= htmlspecialchars($personDisambiguation) ?>)</small>
                <?php endif; ?>
                <?php if ($personTypePresentation['badge'] !== null): ?>
                    <span class="badge bg-body-secondary"><?= htmlspecialchars($personTypePresentation['badge']) ?></span>
                <?php endif; ?>
                <?php if ($lifespanText !== ''): ?>
                    <small class="text-muted fw-normal ms-1"><?= htmlspecialchars($lifespanText) ?></small>
                <?php endif; ?>
            </h1>
            <?php if (!empty($personAliases)):
                /* AKA names render directly under the title so a visitor
                   arriving via the canonical-name URL sees every name
                   this person is also known as. Search-hint and
                   misspelling rows are hidden — they exist to make the
                   internal search match, not for public display. */
                $publicAliases = array_filter(
                    $personAliases,
                    static fn(array $a): bool => !in_array($a['Type'], ['search-hint', 'misspelling'], true)
                );
            ?>
                <?php if ($publicAliases): ?>
                    <p class="text-muted small mb-1">
                        <i class="fa-solid fa-arrows-left-right me-1" aria-hidden="true"></i>
                        Also known as:
                        <?php
                            $rendered = array_map(static function (array $a): string {
                                $name = htmlspecialchars((string)$a['Name']);
                                /* Locale tag in <small> trailing the alias
                                   so a transliteration like "ジョン・ドウ (ja)" reads
                                   naturally. */
                                if (!empty($a['Locale'])) {
                                    $name .= ' <small class="text-muted">(' . htmlspecialchars((string)$a['Locale']) . ')</small>';
                                }
                                return $name;
                            }, $publicAliases);
                            echo implode(', ', $rendered);
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($rolesForBadges)): ?>
                <p class="text-muted small mb-1">
                    <?= implode(' &middot; ', array_map(
                        static fn(string $r): string => htmlspecialchars($r, ENT_QUOTES, 'UTF-8'),
                        $rolesForBadges
                    )) ?>
                    — <?= (int)$totalSongs ?> song<?= $totalSongs === 1 ? '' : 's' ?> total
                </p>
            <?php endif; ?>
            <?php if ($person && !empty($person['BirthPlace']) && !$isGroupish): ?>
                <p class="text-muted small mb-0">
                    <i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i>
                    Born in <?= htmlspecialchars($person['BirthPlace']) ?><?php if (!empty($person['DeathPlace'])): ?>,
                    died in <?= htmlspecialchars($person['DeathPlace']) ?><?php endif; ?>
                </p>
            <?php elseif ($person && $isGroupish && !empty($person['BirthPlace'])): ?>
                <p class="text-muted small mb-0">
                    <i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i>
                    Founded in <?= htmlspecialchars($person['BirthPlace']) ?>
                </p>
            <?php endif; ?>
            <?php if ($person && (int)$person['Id'] > 0): ?>
                <!-- Edit (#1348). Hidden by default; revealed by JS for users with the
                     manage_musicians entitlement (admin / global_admin), mirroring
                     the song page's #btn-edit-song. Server-side admin re-checks on the
                     target page, so hiding the button is purely a UX affordance. -->
                <a class="btn btn-sm btn-outline-secondary d-none mt-2"
                   id="btn-edit-musician"
                   href="/manage/musicians?id=<?= (int)$person['Id'] ?>"
                   title="Edit this musician in the Musicians admin">
                    <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>Edit
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($personMembers)): ?>
        <!-- Members (#1502) — the individual people that make up this
             Group/band/collective. Read-only here; admin add/remove
             lives in the /manage/musicians Edit drawer. Each member
             links to their own /musician/<slug> page when one exists,
             mirroring the "Compiled by" byline's slug-or-plain-text
             fallback on /manage/includes/pages/songbook.php. -->
        <div class="card card-song-header mb-4">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-people-group me-1" aria-hidden="true"></i>Members</h2>
                <p class="mb-0">
                    <?php foreach ($personMembers as $i => $m): ?>
                        <?php if ($i > 0): ?> &middot; <?php endif; ?>
                        <?php if (!empty($m['slug'])): ?>
                            <a href="/musician/<?= rawurlencode($m['slug']) ?>"
                               data-navigate="musician"
                               class="text-reset text-decoration-underline"><?= htmlspecialchars($m['name']) ?></a>
                        <?php else: ?>
                            <span><?= htmlspecialchars($m['name']) ?></span>
                        <?php endif; ?>
                        <?php
                            /* #1741 P4a — append the member's date range when
                               present (empty on installs without RelationType,
                               or when no dates were ever recorded). */
                            $mRange = _personFormatRelationDateRange($m);
                        ?>
                        <?php if ($mRange !== ''): ?>
                            <small class="text-muted">(<?= htmlspecialchars($mRange) ?>)</small>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($personPortrayedBy)): ?>
        <!-- #1741 P4a — "Portrayed by": performers credited as portraying
             THIS figure (a character/other-type row) in a dramatisation.
             Read-only here; admin add/remove lives in the
             /manage/musicians Edit drawer's Portrayed-by sub-panel. -->
        <div class="card card-song-header mb-4">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-person-circle-question me-1" aria-hidden="true"></i>Portrayed by</h2>
                <p class="mb-0">
                    <?php foreach ($personPortrayedBy as $i => $m): ?>
                        <?php if ($i > 0): ?> &middot; <?php endif; ?>
                        <?php if (!empty($m['slug'])): ?>
                            <a href="/musician/<?= rawurlencode($m['slug']) ?>"
                               data-navigate="musician"
                               class="text-reset text-decoration-underline"><?= htmlspecialchars($m['name']) ?></a>
                        <?php else: ?>
                            <span><?= htmlspecialchars($m['name']) ?></span>
                        <?php endif; ?>
                        <?php $pbRange = _personFormatRelationDateRange($m); ?>
                        <?php if ($pbRange !== ''): ?>
                            <small class="text-muted">(<?= htmlspecialchars($pbRange) ?>)</small>
                        <?php endif; ?>
                        <?php if (!empty($m['note'])): ?>
                            <small class="text-muted d-block ms-0"><?= htmlspecialchars((string)$m['note']) ?></small>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($personPortrays)): ?>
        <!-- #1741 P4a — "Portrays": the figures THIS person is credited
             as portraying (an actor's own page). -->
        <div class="card card-song-header mb-4">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-masks-theater me-1" aria-hidden="true"></i>Portrays</h2>
                <p class="mb-0">
                    <?php foreach ($personPortrays as $i => $m): ?>
                        <?php if ($i > 0): ?> &middot; <?php endif; ?>
                        <?php if (!empty($m['slug'])): ?>
                            <a href="/musician/<?= rawurlencode($m['slug']) ?>"
                               data-navigate="musician"
                               class="text-reset text-decoration-underline"><?= htmlspecialchars($m['name']) ?></a>
                        <?php else: ?>
                            <span><?= htmlspecialchars($m['name']) ?></span>
                        <?php endif; ?>
                        <?php $prRange = _personFormatRelationDateRange($m); ?>
                        <?php if ($prRange !== ''): ?>
                            <small class="text-muted">(<?= htmlspecialchars($prRange) ?>)</small>
                        <?php endif; ?>
                        <?php if (!empty($m['note'])): ?>
                            <small class="text-muted d-block ms-0"><?= htmlspecialchars((string)$m['note']) ?></small>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- External authority identifiers (#1348) — bare-code chips, mirroring the
         song page's ISWC/CCLI row (.song-meta-link styling). Sourced from
         tblMusicianIdentifiers + the typed MusicBrainzArtistMBID column. Each
         links to the provider where one exists; IPI/CAE have no public URL.
         Outbound links pass the #1347 leaving-iHymns interstitial.
         #1741 P4a — ipi/isni additionally cross-link INTERNALLY to the P3
         /ipi/<value> and /isni/<value> routes (§1.3.6); see the internal-
         routability check below. -->
    <?php
    /* IdentifierType => [label, FontAwesome icon, printf URL template (rawurlencoded
       value) or null]. Unknown/new types still render as a bare uppercased chip.

       #1367 — the authority-control entries (ISNI / VIAF / Wikidata / GND /
       FAST / WorldCat / LoC / ORCID / IdRef / Trove / LibraryThing /
       Open Library / CiNii / IPN) are now DERIVED from the central registry, so
       every provider a curator can save renders a chip with NO per-page edit.
       IPN (#1741 D5) is IN the registry but, like IPI/CAE below, its 'url' is
       NULL (no public look-up exists), so it renders the same unlinked-chip
       fallback automatically. The IPI / IPI-base / CAE rows below live
       OUTSIDE the registry entirely (they're rights-society numbers stored in
       their own dedicated sub-form, not the "Other Identifiers" picker), so
       they're appended explicitly after the registry-built map. */
    if (!function_exists('creditIdentifierTypes')) {
        require_once dirname(__DIR__) . '/musician_helpers.php';
    }
    /* #1741 P4a — the tree-derived source of which chip types are
       internally routable (rule #35: no second hardcoded ['ipi','isni']
       list — today's two happen to be ipi+isni, but this reads the SAME
       registry the /ipi/ /isni/ public routes themselves are built from,
       so a future scheme added there picks up a routable chip here with
       zero changes to this file). */
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
    $personIdMeta = [];
    foreach (creditIdentifierTypes() as $musIdSlug => $musIdDef) {
        /* The registry's `url` is already a "%s"-templated printf string the
           chip render below feeds through sprintf(rawurlencode(...)). */
        $personIdMeta[$musIdSlug] = [$musIdDef['label'], $musIdDef['icon'], $musIdDef['url']];
    }
    /* Non-registry rights-society identifiers — no public look-up URL. */
    $personIdMeta['ipi']      = ['IPI',      'fa-barcode', null];
    $personIdMeta['ipi-base'] = ['IPI Base', 'fa-barcode', null];
    $personIdMeta['cae']      = ['CAE',      'fa-barcode', null];
    /* Each chip carries its raw type slug as a 5th element so the render
       loop below can derive internal-routability per chip (never a
       second hardcoded list — rule #35). */
    $personIdChips = [];
    /* MusicBrainz first — its own typed column (#1090). Not in
       IHYMNS_ID_SCHEMES ⇒ not internally routable, keeps its existing
       direct-link behaviour exactly. */
    if ($person && !empty($person['MusicBrainzArtistMBID'])) {
        $personIdChips[] = ['MusicBrainz', 'fa-compact-disc', 'https://musicbrainz.org/artist/%s', trim((string)$person['MusicBrainzArtistMBID']), 'musicbrainz'];
    }
    foreach ($personIdentifiers as $pIdRow) {
        $meta = $personIdMeta[$pIdRow['type']] ?? [strtoupper($pIdRow['type']), 'fa-barcode', null];
        $personIdChips[] = [$meta[0], $meta[1], $meta[2], $pIdRow['value'], $pIdRow['type']];
    }
    ?>
    <?php if (!empty($personIdChips)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6 text-muted mb-2">
                    <i class="fa-solid fa-fingerprint me-1" aria-hidden="true"></i>Identifiers
                </h2>
                <div class="d-flex flex-wrap column-gap-4 row-gap-1">
                    <?php foreach ($personIdChips as [$idLabel, $idIcon, $idUrlTpl, $idVal, $idType]): ?>
                        <?php
                            /* #1741 P4a — internal routability, derived from
                               the tree (identifier_normalize.php's
                               IHYMNS_ID_SCHEMES), not a second hardcoded
                               ['ipi','isni'] list (rule #35). */
                            $idInternal = array_key_exists($idType, IHYMNS_ID_SCHEMES)
                                && IHYMNS_ID_SCHEMES[$idType]['entity'] === 'musician';
                        ?>
                        <span class="small text-muted">
                            <i class="fa-solid <?= htmlspecialchars($idIcon) ?> me-2" aria-hidden="true"></i>
                            <strong><?= htmlspecialchars($idLabel) ?>:</strong>&nbsp;<?php
                            if ($idInternal): ?><a class="song-meta-link"
                                   href="/<?= htmlspecialchars($idType) ?>/<?= rawurlencode($idVal) ?>"
                                   data-navigate="<?= htmlspecialchars($idType) ?>"
                                   title="See everyone sharing this <?= htmlspecialchars($idLabel) ?>"><?= htmlspecialchars($idVal) ?></a><?php
                                if ($idUrlTpl !== null): /* authority URL moves to a trailing icon */ ?><a
                                       href="<?= htmlspecialchars(sprintf($idUrlTpl, rawurlencode($idVal))) ?>"
                                       target="_blank" rel="noopener nofollow external" class="ms-1"
                                       aria-label="<?= htmlspecialchars($idLabel) ?> on its authority site — opens in a new tab"
                                       title="<?= htmlspecialchars($idLabel) ?> on its authority site — opens in a new tab"><i
                                       class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a><?php
                                endif; ?><?php
                            elseif ($idUrlTpl !== null): ?><a class="song-meta-link"
                                   href="<?= htmlspecialchars(sprintf($idUrlTpl, rawurlencode($idVal))) ?>"
                                   target="_blank" rel="noopener nofollow external"
                                   title="<?= htmlspecialchars($idLabel) ?> — opens in a new tab"><?= htmlspecialchars($idVal) ?></a><?php
                            else: ?><span title="No public lookup URL"><?= htmlspecialchars($idVal) ?></span><?php
                            endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- About / bio (#1741 P4a — $bioText is Biography-with-Notes-fallback,
         computed above §7; the notes-as-bio-fallback marker lives there). -->
    <?php if ($person && $bioText !== ''): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6 text-muted mb-2">
                    <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>About
                </h2>
                <p class="mb-0" style="white-space: pre-line;"><?= htmlspecialchars($bioText) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- External links — unified system (#833) preferred, legacy fallback.
         #1741 P4a — the unified-rows branch now consumes the SHARED
         partial (§0.4) work.php/tune.php already use, instead of its own
         inlined $pCatLabels category-label map + render loop (byte-similar
         output, one source — the category order shifts very slightly to
         match the partial's canonical list, an accepted cosmetic change
         noted in the P4a commit). -->
    <?php if (!empty($linksUnified)):
        $panelLinks     = $linksUnified;
        $panelHeading   = 'Find this person elsewhere';
        $panelAriaLabel = 'Find this person elsewhere';
        require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
              . 'partials' . DIRECTORY_SEPARATOR . 'external-links-panel.php';
    elseif (!empty($links)): /* legacy fallback when no unified rows */ ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6 text-muted mb-2">
                    <i class="fa-solid fa-link me-1" aria-hidden="true"></i>Links
                </h2>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($links as $l): ?>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= htmlspecialchars((string)$l['Url']) ?>"
                           target="_blank" rel="noopener noreferrer"
                           title="<?= htmlspecialchars((string)$l['Url']) ?>">
                            <?= htmlspecialchars(($l['Label'] !== null && $l['Label'] !== '') ? (string)$l['Label'] : (string)$l['LinkType']) ?>
                            <i class="fa-solid fa-arrow-up-right-from-square ms-1 small" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Compiled songbooks (#831) — appears above the per-song
         discography because compiling a hymnal is editorial credit
         on the catalogue itself, not a per-song role. Hidden when
         this person has compiled none. -->
    <?php if (!empty($compiledBooks)): ?>
        <div class="mb-4">
            <h2 class="h6 mb-2 text-muted">
                <i class="fa-solid fa-pen-nib me-1" aria-hidden="true"></i>
                As Compiler / Editor
                <small class="text-muted">(<?= count($compiledBooks) ?>)</small>
            </h2>
            <div class="list-group">
                <?php foreach ($compiledBooks as $b): ?>
                    <a href="/songbook/<?= htmlspecialchars($b['abbr']) ?>"
                       class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                       data-navigate="songbook"
                       data-songbook="<?= htmlspecialchars($b['abbr']) ?>">
                        <span class="badge bg-body-secondary"><?= htmlspecialchars($b['abbr']) ?></span>
                        <div class="flex-grow-1">
                            <div><?= htmlspecialchars($b['name']) ?></div>
                            <?php if (!empty($b['note'])): ?>
                                <small class="text-muted"><?= htmlspecialchars($b['note']) ?></small>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted"><?= number_format((int)$b['songCount']) ?> songs</small>
                        <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Discography grouped by role -->
    <?php if (!empty($discography)): ?>
        <!-- Sort control (#1786) — Number / Title / Songbook. One control
             governs EVERY per-role group below (multi-container — each
             role's own .song-list sorts independently; grouping by role is
             the page's information architecture and is never flattened). -->
        <?php
            $listSortSurface = 'musician-songs';
            $listSortDefault = 'Songbook & number';
            $listSortOptions = [
                'number' => ['label' => 'Number',   'type' => 'number', 'dir' => 'asc'],
                'title'  => ['label' => 'Title',    'type' => 'text',   'dir' => 'asc'],
                'book'   => ['label' => 'Songbook', 'type' => 'text',   'dir' => 'asc'],
            ];
            require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
        ?>
    <?php endif; ?>
    <?php foreach ($discography as $roleKey => $entry):
        $cfg = $entry['cfg'];
        $songs = $entry['songs'];
    ?>
        <div class="mb-4">
            <h2 class="h6 mb-2 text-muted">
                <i class="fa-solid <?= htmlspecialchars($cfg['icon']) ?> me-1" aria-hidden="true"></i>
                <?= htmlspecialchars($cfg['label']) ?>
                <small class="text-muted">(<?= count($songs) ?>)</small>
            </h2>
            <div class="list-group song-list" role="list" data-list-sort-list="musician-songs">
                <?php foreach ($songs as $s): ?>
                    <a href="/song/<?= htmlspecialchars($s['SongId']) ?>"
                       class="list-group-item list-group-item-action song-list-item"
                       data-navigate="song"
                       data-song-id="<?= htmlspecialchars($s['SongId']) ?>"
                       role="listitem"
                       <?php if ((int)$s['Number'] > 0): ?>data-sort-number="<?= (int)$s['Number'] ?>"<?php endif; ?>
                       data-sort-title="<?= htmlspecialchars(ihymns_title_sort_key((string)$s['Title'])) ?>"
                       data-sort-book="<?= htmlspecialchars(mb_strtolower((string)$s['SongbookAbbr'], 'UTF-8')) ?>">
<?php /* Unnumbered (Misc / unofficial) → emit a TRULY EMPTY badge (no whitespace)
                           so the shared `.song-number-badge:empty::before` book glyph shows
                           instead of a literal "0" (matches history.js:376). */ ?>
                        <span class="song-number-badge" data-songbook="<?= htmlspecialchars($s['SongbookAbbr']) ?>" aria-hidden="true"><?= ((int)$s['Number'] > 0) ? (int)$s['Number'] : '' ?></span>
                        <div class="song-info flex-grow-1">
                            <span class="song-title"><?= htmlspecialchars(toTitleCase((string)$s['Title'])) ?></span>
                            <small class="text-muted d-block">
                                <?php /* #1531 — full songbook NAME (registry twin of JS songbookLabel);
                                         self-escaping helper, degrades to the abbr when no name. */ ?>
                                <?= ihymns_songbook_name_label((string)$s['SongbookAbbr'], (string)($s['SongbookName'] ?? '')) ?>
                            </small>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php
        /* JSON-LD Person schema (#claude/musicians-aliases-v2). Emit
           an alternateName entry for every alias so search engines and
           knowledge-graph consumers learn that "John Newton" and his
           common variants ("J. Newton", "Newton, John") are the same
           person. Search-hint / misspelling aliases are included here
           too — schema.org alternateName is a non-display catalogue of
           known synonyms, so search engines benefit from the broader
           set the public header skipped.
           #1741 P4a — the gate widens to ALSO fire when Disambiguation
           is set (so a disambiguated-but-alias-less row still gets a
           JSON-LD block), and adds disambiguatingDescription — the
           schema.org property built exactly for "(the elder)"-style
           short parentheticals. */
        if ($person && (!empty($publicAliases) || !empty($personAliases) || $personDisambiguation !== '')):
            $ldNames = array_values(array_unique(array_map(
                static fn(array $a): string => (string)$a['Name'],
                $personAliases
            )));
            $ldType = $isGroupish ? 'MusicGroup' : 'Person';
            $ld = [
                '@context' => 'https://schema.org',
                '@type'    => $ldType,
                'name'     => $personName,
            ];
            if (!empty($ldNames)) {
                $ld['alternateName'] = count($ldNames) === 1 ? $ldNames[0] : $ldNames;
            }
            if ($personDisambiguation !== '') {
                $ld['disambiguatingDescription'] = $personDisambiguation;
            }
    ?>
        <?php /* SECURITY: JSON_HEX_TAG|_AMP|_APOS|_QUOT so a DB musician name
                 containing </script> (or &, ", ') cannot break out of this public
                 <script> element and inject HTML (stored XSS). See security audit. */ ?>
        <script type="application/ld+json"><?= json_encode(
            $ld,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
    <?php endif; ?>

</section>
