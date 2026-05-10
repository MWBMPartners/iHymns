<?php

declare(strict_types=1);

/**
 * iHymns — Works backfill from existing ISWCs (#942)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Songs already carry ISWC values in tblSongs.Iswc, but the Works
 * admin page reports zero rows because nothing has populated tblWorks
 * yet. ISWC is the authoritative composition identifier — songs that
 * share an ISWC are by definition the same composition. So we can
 * derive Works directly from the existing data without any external
 * API calls or manual curation.
 *
 * For each distinct ISWC in tblSongs:
 *   1. Find or create a tblWorks row keyed on Iswc.
 *   2. INSERT IGNORE every member song into tblWorkSongs.
 *   3. For Works newly created in this run, set Title to the most-
 *      common Title across member songs (alphabetic tiebreak), and
 *      derive Slug from that Title.
 *   4. Mark the song with the lowest-numbered Number (or first
 *      alphabetic SongId if numbers tie) as IsCanonical=1.
 *
 * Idempotent — re-runs don't overwrite existing tblWorks rows; only
 * the missing memberships are added. Curator manual edits are safe.
 *
 * USAGE:
 *   Web:  /manage/setup-database → "Backfill Works from existing ISWCs"
 *   CLI:  php appWeb/.sql/backfill-works-from-iswc.php
 *
 * NOT a schema migration — this is a one-shot data task. The schema
 * lives in migrate-works.php (#840).
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migIswcWorks_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) {
        flush();
    }
}

function _migIswcWorks_tableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/**
 * Slugify mirroring the Works admin's existing convention: lowercase,
 * non-alnum collapses to '-', trim leading/trailing hyphens. Length
 * capped at 80 to fit the UNIQUE Slug column.
 */
function _migIswcWorks_slug(string $name): string
{
    $name  = mb_strtolower(trim($name));
    if (class_exists('Normalizer')) {
        $name = Normalizer::normalize($name, Normalizer::FORM_KD);
        $name = preg_replace('/\p{M}+/u', '', $name);
    }
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $name);
    $slug = trim((string)$slug, '-');
    if (mb_strlen($slug) > 80) {
        $slug = mb_substr($slug, 0, 80);
    }
    return $slug;
}

_migIswcWorks_out('Works ISWC backfill starting…');

$db = getDbMysqli();
if (!$db) {
    _migIswcWorks_out('ERROR: could not connect to database.');
    exit(1);
}

if (!_migIswcWorks_tableExists($db, 'tblWorks')
    || !_migIswcWorks_tableExists($db, 'tblWorkSongs')
) {
    _migIswcWorks_out('ERROR: tblWorks / tblWorkSongs missing — run migrate-works.php first.');
    exit(1);
}

/* ----------------------------------------------------------------------
 * Step 1: find every ISWC in tblSongs and the songs grouped under it.
 * ---------------------------------------------------------------------- */
$res = $db->query(
    "SELECT Iswc, SongId, Title, Number
       FROM tblSongs
      WHERE Iswc IS NOT NULL AND TRIM(Iswc) <> ''
      ORDER BY Iswc, Number ASC, SongId ASC"
);
if (!$res) {
    _migIswcWorks_out('ERROR: SELECT from tblSongs failed: ' . $db->error);
    exit(1);
}

$byIswc = [];
while ($row = $res->fetch_assoc()) {
    $iswc = strtoupper(trim((string)$row['Iswc']));
    if ($iswc === '') continue;
    $byIswc[$iswc][] = [
        'song_id' => (string)$row['SongId'],
        'title'   => (string)$row['Title'],
        'number'  => $row['Number'] !== null ? (int)$row['Number'] : null,
    ];
}
$res->close();

if (empty($byIswc)) {
    _migIswcWorks_out('[seed] no ISWCs found on tblSongs — nothing to backfill.');
    _migIswcWorks_out('Works ISWC backfill finished.');
    return;
}

_migIswcWorks_out(sprintf(
    '[seed] found %d distinct ISWC%s spanning %d song%s.',
    count($byIswc), count($byIswc) === 1 ? '' : 's',
    array_sum(array_map('count', $byIswc)),
    array_sum(array_map('count', $byIswc)) === 1 ? '' : 's'
));

/* ----------------------------------------------------------------------
 * Step 2: pre-load existing slugs so the new-Work creation path can
 * collision-suffix without a per-row probe.
 * ---------------------------------------------------------------------- */
$takenSlugs = [];
$res = $db->query("SELECT Slug FROM tblWorks WHERE Slug <> ''");
if ($res) {
    while ($r = $res->fetch_assoc()) $takenSlugs[(string)$r['Slug']] = true;
    $res->close();
}

/* ----------------------------------------------------------------------
 * Step 3: for each ISWC, find-or-create the Work, then add memberships.
 * ---------------------------------------------------------------------- */
$worksFound      = 0;
$worksCreated    = 0;
$membersAdded    = 0;
$membersExisting = 0;

foreach ($byIswc as $iswc => $songs) {
    /* find existing Work row keyed on ISWC */
    $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Iswc = ? LIMIT 1');
    $stmt->bind_param('s', $iswc);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($r) {
        $workId = (int)$r['Id'];
        $worksFound++;
    } else {
        /* Create a new Work. Title = most common across member songs,
           with alphabetic tiebreak. Slug derived from that Title with
           collision suffix. */
        $titleCounts = [];
        foreach ($songs as $s) {
            $t = trim((string)$s['title']);
            if ($t === '') continue;
            $titleCounts[$t] = ($titleCounts[$t] ?? 0) + 1;
        }
        if (empty($titleCounts)) {
            /* Defensive — every member missing a title shouldn't happen
               in practice. Fall back to the ISWC itself. */
            $title = $iswc;
        } else {
            arsort($titleCounts, SORT_NUMERIC);
            $maxCount = reset($titleCounts);
            /* Among titles tied at the max count, pick alphabetically. */
            $tied = array_keys(array_filter(
                $titleCounts,
                static fn($c) => $c === $maxCount
            ));
            sort($tied, SORT_STRING);
            $title = $tied[0];
        }
        $slugBase = _migIswcWorks_slug($title);
        if ($slugBase === '') $slugBase = 'work';
        $slug = $slugBase;
        $suffix = 1;
        while (isset($takenSlugs[$slug])) {
            $suffix++;
            $slug = $slugBase . '-' . $suffix;
            if (mb_strlen($slug) > 80) {
                /* Slug overflow — truncate base + retry. */
                $slugBase = mb_substr($slugBase, 0, 78 - mb_strlen((string)$suffix));
                $slug = $slugBase . '-' . $suffix;
            }
        }
        $takenSlugs[$slug] = true;

        $stmt = $db->prepare(
            'INSERT INTO tblWorks (Iswc, Title, Slug, Notes)
             VALUES (?, ?, ?, ?)'
        );
        $notes = sprintf('Auto-created from ISWC %s on %s by backfill task #942.',
            $iswc, gmdate('Y-m-d'));
        $stmt->bind_param('ssss', $iswc, $title, $slug, $notes);
        $stmt->execute();
        $workId = (int)$db->insert_id;
        $stmt->close();

        $worksCreated++;
        _migIswcWorks_out(sprintf(
            '[add ] Work #%d "%s" (slug=%s, ISWC=%s, %d member%s).',
            $workId, $title, $slug, $iswc,
            count($songs), count($songs) === 1 ? '' : 's'
        ));
    }

    /* Pick the canonical member: lowest non-null Number, tiebreak by
       alphabetic SongId. Only set if there isn't already a canonical
       member on this Work (re-runs preserve the curator's choice). */
    $existingCanon = null;
    $stmt = $db->prepare(
        'SELECT SongId FROM tblWorkSongs WHERE WorkId = ? AND IsCanonical = 1 LIMIT 1'
    );
    $stmt->bind_param('i', $workId);
    $stmt->execute();
    $cr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $existingCanon = $cr['SongId'] ?? null;

    $canonSongId = null;
    if ($existingCanon === null) {
        usort($songs, static function ($a, $b) {
            $an = $a['number'] ?? PHP_INT_MAX;
            $bn = $b['number'] ?? PHP_INT_MAX;
            if ($an !== $bn) return $an <=> $bn;
            return strcmp($a['song_id'], $b['song_id']);
        });
        $canonSongId = $songs[0]['song_id'] ?? null;
    }

    /* Insert memberships. INSERT IGNORE so re-runs no-op on existing
       composite-key rows. */
    $insMember = $db->prepare(
        'INSERT IGNORE INTO tblWorkSongs (WorkId, SongId, IsCanonical)
         VALUES (?, ?, ?)'
    );
    foreach ($songs as $s) {
        $sid = $s['song_id'];
        $isCanon = ($canonSongId !== null && $sid === $canonSongId) ? 1 : 0;
        $insMember->bind_param('isi', $workId, $sid, $isCanon);
        $insMember->execute();
        if ($insMember->affected_rows > 0) {
            $membersAdded++;
        } else {
            $membersExisting++;
        }
    }
    $insMember->close();
}

_migIswcWorks_out(sprintf(
    'Backfill complete: %d existing Work%s found, %d new Work%s created, %d member%s added, %d already linked.',
    $worksFound,    $worksFound === 1    ? '' : 's',
    $worksCreated,  $worksCreated === 1  ? '' : 's',
    $membersAdded,  $membersAdded === 1  ? '' : 's',
    $membersExisting
));

if (function_exists('logActivity')) {
    try {
        logActivity(
            'admin.works.iswc_backfill',
            'works',
            '',
            [
                'works_found'      => $worksFound,
                'works_created'    => $worksCreated,
                'members_added'    => $membersAdded,
                'members_existing' => $membersExisting,
            ],
            'success'
        );
    } catch (\Throwable $_e) { /* best-effort */ }
}

_migIswcWorks_out('Works ISWC backfill finished.');
/* Don't close $db — see migrate-credit-people-slug.php for rationale. */
