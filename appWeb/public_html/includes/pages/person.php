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
    $stmt = $db->prepare(
        "SELECT Id, Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate,
                COALESCE(IsSpecialCase, 0) AS IsSpecialCase,
                COALESCE(IsGroup, 0)        AS IsGroup
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
/* ---------------------------------------------------------------------- */
$links = [];
if ($person && (int)$person['Id'] > 0) {
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
    } catch (\Throwable $_e) { /* table missing — links stay empty */ }
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
    echo '<div class="alert alert-warning" role="alert">';
    echo '<i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>';
    echo 'No person found for: <strong>' . htmlspecialchars($personName) . '</strong>';
    echo '</div>';
    echo '<a href="/songbooks" class="btn btn-primary" data-navigate="songbooks">';
    echo '<i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Songbooks</a>';
    return;
}

/* ---------------------------------------------------------------------- */
/* 6. Compute the headline classification line.                           */
/* ---------------------------------------------------------------------- */
$rolesForBadges = [];
foreach ($discography as $rk => $entry) {
    $rolesForBadges[] = ucfirst($rk) . ' (' . count($entry['songs']) . ')';
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
            <?php if (!empty($rolesForBadges)): ?>
                <p class="text-muted small mb-1">
                    <?= htmlspecialchars(implode(' &middot; ', $rolesForBadges)) ?>
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
        </div>
    </div>

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

    <!-- External links -->
    <?php if (!empty($links)): ?>
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
                        <span class="song-number-badge" data-songbook="<?= htmlspecialchars($s['SongbookAbbr']) ?>" aria-hidden="true">
                            <?= (int)$s['Number'] ?>
                        </span>
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

</section>
