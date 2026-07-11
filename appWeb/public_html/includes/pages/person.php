<?php

declare(strict_types=1);

/**
 * iHymns — Credit Person Public Page (#588)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Public landing page for a `tblCreditPeople` registry row. Surfaces
 * the bio, lifespan, special-case / group classification, external
 * links, and a discography grouped by role across the six song-credit
 * tables.
 *
 * Loaded via api.php?page=person&slug=cecil-frances-humphreys-alexander.
 * Expects $personSlug to be set by api.php before inclusion.
 *
 * Falls back to /writer/<slug> behaviour for installs that haven't
 * applied the migrate-credit-people-slug migration yet — the slug
 * lookup short-circuits to a name-based search across the credit
 * tables, so the page still works even before the registry row
 * exists.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'db_mysql.php';

/**
 * Slug → display name when no registry row exists. Mirrors the
 * /writer/<slug> page's fallback behaviour.
 */
function _personSlugToName(string $slug): string
{
    $slug = urldecode($slug);
    return mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE, 'UTF-8');
}

$person = null;            /* tblCreditPeople row, or null on partial install / unknown slug */
$personName = '';          /* always set — falls back to slug-derived name */
$db = getDbMysqli();

/* ---------------------------------------------------------------------- */
/* 1. Look up the registry row (if the migration has been applied).       */
/* ---------------------------------------------------------------------- */
try {
    /* #1348 — include the typed MusicBrainzArtistMBID column only if it exists
       (#1090 may be un-migrated; an absent column would 1054 under STRICT and blank
       the page). The other authority identifiers live in tblCreditPersonIdentifiers
       (read separately below). $mbidCol is a hardcoded constant — the only
       legitimate string interpolation into SQL (rule #5). */
    $mbidCol = '';
    try {
        $pc = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblCreditPeople' AND COLUMN_NAME = 'MusicBrainzArtistMBID' LIMIT 1");
        if ($pc && $pc->fetch_row() !== null) { $mbidCol = ', MusicBrainzArtistMBID'; }
        if ($pc) { $pc->free(); }
    } catch (\Throwable $_e) { $mbidCol = ''; }

    $stmt = $db->prepare(
        "SELECT Id, Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate,
                COALESCE(IsSpecialCase, 0) AS IsSpecialCase,
                COALESCE(IsGroup, 0)        AS IsGroup{$mbidCol}
           FROM tblCreditPeople
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
    if (!function_exists('loadCreditPersonAliases')) {
        require_once dirname(__DIR__) . '/credit_people_helpers.php';
    }
    try {
        $personAliases = loadCreditPersonAliases($db, (int)$person['Id']);
    } catch (\Throwable $_e) {
        $personAliases = [];
    }
}

/* Group members (#1502) — the individual people that make up this
   Group/band/collective credit-person row (IsGroup=1, #585). Public,
   read-only list; admin add/remove lives in the /manage/credit-people
   Edit drawer. Schema-tolerant: returns an empty array on installs
   that haven't run migrate-add-creditperson-members.php yet. */
$personMembers = [];
if ($person && (int)($person['IsGroup'] ?? 0) === 1 && isset($person['Id'])) {
    if (!function_exists('loadCreditPersonGroupMembers')) {
        require_once dirname(__DIR__) . '/credit_people_helpers.php';
    }
    try {
        $personMembers = loadCreditPersonGroupMembers($db, (int)$person['Id']);
    } catch (\Throwable $_e) {
        $personMembers = [];
    }
}

/* External authority identifiers (#1348) — the key-value rows from
   tblCreditPersonIdentifiers (ipi / isni / viaf / wikidata / orcid / …, the table
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
               FROM tblCreditPersonIdentifiers
              WHERE CreditPersonId = ?
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

$discography = [];
$totalSongs = 0;
$matchedSongIds = [];
foreach ($roleTables as $roleKey => $cfg) {
    $sql = "SELECT s.SongId, s.Title, s.SongbookAbbr, s.Number
              FROM {$cfg['table']} c
              JOIN tblSongs s ON s.SongId = c.SongId
             WHERE c.Name = ?
             ORDER BY s.SongbookAbbr, s.Number";
    try {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $personName);
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
            $stmt = $db->prepare(
                'SELECT b.Abbreviation AS abbr, b.Name AS name, b.SongCount AS songCount,
                        c.Note         AS note,  c.SortOrder AS sortOrder
                   FROM tblSongbookCompilers c
                   JOIN tblSongbooks b ON b.Id = c.SongbookId
                  WHERE c.CreditPersonId = ?
                  ORDER BY b.Name ASC'
            );
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
/*    Reads tblCreditPersonExternalLinks (the new unified system, #833)   */
/*    if it has rows for this person, otherwise falls back to the legacy  */
/*    tblCreditPersonLinks. The fallback keeps deployments that haven't   */
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
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblCreditPersonExternalLinks' LIMIT 1"
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
                   FROM tblCreditPersonExternalLinks el
                   JOIN tblExternalLinkTypes t ON t.Id = el.LinkTypeId
                  WHERE el.CreditPersonId = ?
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
                   FROM tblCreditPersonLinks
                  WHERE CreditPersonId = ?
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

$lifespanText = $person
    ? _personFormatLifespan($person['BirthDate'], $person['DeathDate'], (bool)$person['IsGroup'])
    : '';

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

?>

<!-- ================================================================
     PERSON PAGE — Public landing for a Credit People registry row
     ================================================================ -->
<section class="page-person" aria-label="<?= htmlspecialchars($personName) ?>">

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
            <h1 class="h4 mb-1 d-flex flex-wrap align-items-center gap-2">
                <?php if ($person && (int)$person['IsGroup'] === 1): ?>
                    <i class="fa-solid fa-users text-info" aria-hidden="true" title="Group / band / collective"></i>
                <?php elseif ($person && (int)$person['IsSpecialCase'] === 1): ?>
                    <i class="fa-solid fa-circle-question text-warning" aria-hidden="true" title="Special-case attribution"></i>
                <?php else: ?>
                    <i class="fa-solid fa-user-pen" aria-hidden="true"></i>
                <?php endif; ?>
                <span class="<?= ($person && (int)$person['IsSpecialCase'] === 1) ? 'fst-italic' : '' ?>"><?= htmlspecialchars($personName) ?></span>
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
            <?php if ($person && !empty($person['BirthPlace']) && !$person['IsGroup']): ?>
                <p class="text-muted small mb-0">
                    <i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i>
                    Born in <?= htmlspecialchars($person['BirthPlace']) ?><?php if (!empty($person['DeathPlace'])): ?>,
                    died in <?= htmlspecialchars($person['DeathPlace']) ?><?php endif; ?>
                </p>
            <?php elseif ($person && $person['IsGroup'] && !empty($person['BirthPlace'])): ?>
                <p class="text-muted small mb-0">
                    <i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i>
                    Founded in <?= htmlspecialchars($person['BirthPlace']) ?>
                </p>
            <?php endif; ?>
            <?php if ($person && (int)$person['Id'] > 0): ?>
                <!-- Edit (#1348). Hidden by default; revealed by JS for users with the
                     manage_credit_people entitlement (admin / global_admin), mirroring
                     the song page's #btn-edit-song. Server-side admin re-checks on the
                     target page, so hiding the button is purely a UX affordance. -->
                <a class="btn btn-sm btn-outline-secondary d-none mt-2"
                   id="btn-edit-person"
                   href="/manage/credit-people?id=<?= (int)$person['Id'] ?>"
                   title="Edit this person in Credit People admin">
                    <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>Edit
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($personMembers)): ?>
        <!-- Members (#1502) — the individual people that make up this
             Group/band/collective. Read-only here; admin add/remove
             lives in the /manage/credit-people Edit drawer. Each member
             links to their own /people/<slug> page when one exists,
             mirroring the "Compiled by" byline's slug-or-plain-text
             fallback on /manage/includes/pages/songbook.php. -->
        <div class="card card-song-header mb-4">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-people-group me-1" aria-hidden="true"></i>Members</h2>
                <p class="mb-0">
                    <?php foreach ($personMembers as $i => $m): ?>
                        <?php if ($i > 0): ?> &middot; <?php endif; ?>
                        <?php if (!empty($m['slug'])): ?>
                            <a href="/people/<?= rawurlencode($m['slug']) ?>"
                               data-navigate="person"
                               class="text-reset text-decoration-underline"><?= htmlspecialchars($m['name']) ?></a>
                        <?php else: ?>
                            <span><?= htmlspecialchars($m['name']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- External authority identifiers (#1348) — bare-code chips, mirroring the
         song page's ISWC/CCLI row (.song-meta-link styling). Sourced from
         tblCreditPersonIdentifiers + the typed MusicBrainzArtistMBID column. Each
         links to the provider where one exists; IPI/CAE have no public URL.
         Outbound links pass the #1347 leaving-iHymns interstitial. -->
    <?php
    /* IdentifierType => [label, FontAwesome icon, printf URL template (rawurlencoded
       value) or null]. Unknown/new types still render as a bare uppercased chip.

       #1367 — the authority-control entries (ISNI / VIAF / Wikidata / GND /
       FAST / WorldCat / LoC / ORCID / IdRef / Trove / LibraryThing /
       Open Library / CiNii) are now DERIVED from the central registry, so every
       provider a curator can save renders a chip with NO per-page edit. The
       IPI / IPI-base / CAE rows below live OUTSIDE the registry (they're
       rights-society numbers with no public look-up URL), so they're appended
       explicitly after the registry-built map. */
    if (!function_exists('creditIdentifierTypes')) {
        require_once dirname(__DIR__) . '/credit_people_helpers.php';
    }
    $personIdMeta = [];
    foreach (creditIdentifierTypes() as $cpIdSlug => $cpIdDef) {
        /* The registry's `url` is already a "%s"-templated printf string the
           chip render below feeds through sprintf(rawurlencode(...)). */
        $personIdMeta[$cpIdSlug] = [$cpIdDef['label'], $cpIdDef['icon'], $cpIdDef['url']];
    }
    /* Non-registry rights-society identifiers — no public look-up URL. */
    $personIdMeta['ipi']      = ['IPI',      'fa-barcode', null];
    $personIdMeta['ipi-base'] = ['IPI Base', 'fa-barcode', null];
    $personIdMeta['cae']      = ['CAE',      'fa-barcode', null];
    $personIdChips = [];
    /* MusicBrainz first — its own typed column (#1090). */
    if ($person && !empty($person['MusicBrainzArtistMBID'])) {
        $personIdChips[] = ['MusicBrainz', 'fa-compact-disc', 'https://musicbrainz.org/artist/%s', trim((string)$person['MusicBrainzArtistMBID'])];
    }
    foreach ($personIdentifiers as $pIdRow) {
        $meta = $personIdMeta[$pIdRow['type']] ?? [strtoupper($pIdRow['type']), 'fa-barcode', null];
        $personIdChips[] = [$meta[0], $meta[1], $meta[2], $pIdRow['value']];
    }
    ?>
    <?php if (!empty($personIdChips)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6 text-muted mb-2">
                    <i class="fa-solid fa-fingerprint me-1" aria-hidden="true"></i>Identifiers
                </h2>
                <div class="d-flex flex-wrap column-gap-4 row-gap-1">
                    <?php foreach ($personIdChips as [$idLabel, $idIcon, $idUrlTpl, $idVal]): ?>
                        <span class="small text-muted">
                            <i class="fa-solid <?= htmlspecialchars($idIcon) ?> me-2" aria-hidden="true"></i>
                            <strong><?= htmlspecialchars($idLabel) ?>:</strong>&nbsp;<?php
                            if ($idUrlTpl !== null): ?><a class="song-meta-link"
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

    <!-- Notes / bio -->
    <?php if ($person && !empty(trim((string)$person['Notes']))): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6 text-muted mb-2">
                    <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>About
                </h2>
                <p class="mb-0" style="white-space: pre-line;"><?= htmlspecialchars((string)$person['Notes']) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- External links — unified system (#833) preferred, legacy fallback -->
    <?php if (!empty($linksUnified)): ?>
        <?php
            $pLinksByCat = [];
            foreach ($linksUnified as $l) {
                $cat = (string)($l['category'] ?? 'other');
                if (!isset($pLinksByCat[$cat])) $pLinksByCat[$cat] = [];
                $pLinksByCat[$cat][] = $l;
            }
            $pCatLabels = [
                'official'    => 'Official',
                'information' => 'Information',
                'authority'   => 'Authority',
                'sheet-music' => 'Sheet music',
                'listen'      => 'Listen',
                'watch'       => 'Watch',
                'social'      => 'Social',
                'read'        => 'Read',
                'purchase'    => 'Purchase',
                'other'       => 'Other',
            ];
        ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6 text-muted mb-3">
                    <i class="fa-solid fa-link me-1" aria-hidden="true"></i>Find this person elsewhere
                </h2>
                <?php foreach ($pCatLabels as $cat => $catLabel): ?>
                    <?php if (empty($pLinksByCat[$cat])) continue; ?>
                    <div class="mb-2">
                        <div class="text-uppercase small text-muted mb-1"><?= htmlspecialchars($catLabel) ?></div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($pLinksByCat[$cat] as $l): ?>
                                <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2"
                                   href="<?= htmlspecialchars((string)$l['url']) ?>"
                                   target="_blank" rel="noopener nofollow"
                                   title="<?= htmlspecialchars((string)$l['url']) ?>">
                                    <?php if (!empty($l['iconClass'])): ?>
                                        <i class="<?= htmlspecialchars((string)$l['iconClass']) ?>" aria-hidden="true"></i>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars((string)$l['name']) ?></span>
                                    <?php if (!empty($l['note'])): ?>
                                        <span class="text-muted small">— <?= htmlspecialchars((string)$l['note']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($l['verified'])): ?>
                                        <i class="fa-solid fa-circle-check text-success small" aria-label="Verified" title="Verified"></i>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php elseif (!empty($links)): /* legacy fallback when no unified rows */ ?>
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
            <div class="list-group song-list" role="list">
                <?php foreach ($songs as $s): ?>
                    <a href="/song/<?= htmlspecialchars($s['SongId']) ?>"
                       class="list-group-item list-group-item-action song-list-item"
                       data-navigate="song"
                       data-song-id="<?= htmlspecialchars($s['SongId']) ?>"
                       role="listitem">
<?php /* Unnumbered (Misc / unofficial) → emit a TRULY EMPTY badge (no whitespace)
                           so the shared `.song-number-badge:empty::before` book glyph shows
                           instead of a literal "0" (matches history.js:376). */ ?>
                        <span class="song-number-badge" data-songbook="<?= htmlspecialchars($s['SongbookAbbr']) ?>" aria-hidden="true"><?= ((int)$s['Number'] > 0) ? (int)$s['Number'] : '' ?></span>
                        <div class="song-info flex-grow-1">
                            <span class="song-title"><?= htmlspecialchars(toTitleCase((string)$s['Title'])) ?></span>
                            <small class="text-muted d-block">
                                <?= htmlspecialchars($s['SongbookAbbr']) ?>
                            </small>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php
        /* JSON-LD Person schema (#claude/credit-people-aliases-v2). Emit
           an alternateName entry for every alias so search engines and
           knowledge-graph consumers learn that "John Newton" and his
           common variants ("J. Newton", "Newton, John") are the same
           person. Search-hint / misspelling aliases are included here
           too — schema.org alternateName is a non-display catalogue of
           known synonyms, so search engines benefit from the broader
           set the public header skipped. */
        if ($person && (!empty($publicAliases) || !empty($personAliases))):
            $ldNames = array_values(array_unique(array_map(
                static fn(array $a): string => (string)$a['Name'],
                $personAliases
            )));
            $ldType = ((int)($person['IsGroup'] ?? 0) === 1) ? 'MusicGroup' : 'Person';
            $ld = [
                '@context'      => 'https://schema.org',
                '@type'         => $ldType,
                'name'          => $personName,
                'alternateName' => count($ldNames) === 1 ? $ldNames[0] : $ldNames,
            ];
    ?>
        <?php /* SECURITY: JSON_HEX_TAG|_AMP|_APOS|_QUOT so a DB credit-person name
                 containing </script> (or &, ", ') cannot break out of this public
                 <script> element and inject HTML (stored XSS). See security audit. */ ?>
        <script type="application/ld+json"><?= json_encode(
            $ld,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
    <?php endif; ?>

</section>
