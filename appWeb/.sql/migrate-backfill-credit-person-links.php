<?php

declare(strict_types=1);

/**
 * iHymns — Backfill tblCreditPersonLinks → tblCreditPersonExternalLinks (#833)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Migrates rows from the existing tblCreditPersonLinks (free-text
 * LinkType, from #545) into the new external-links system. Maps the
 * free-text type strings to controlled-vocabulary slugs. Most map
 * directly; unrecognised values fall through to 'other' with the
 * original string preserved in Note (so the curator hasn't lost the
 * context, and a later /manage/link-types editor can disambiguate).
 *
 * Mapping table (case-insensitive, edge-trimmed):
 *
 *   wikipedia / wiki              → wikipedia
 *   wikidata                      → wikidata
 *   imslp / petrucci              → imslp
 *   hymnary / hymnary.org         → hymnary-org
 *   website / official            → official-website
 *   musicbrainz / mb              → musicbrainz-artist
 *   discogs                       → discogs
 *   viaf                          → viaf
 *   loc / library-of-congress     → loc-name-authority
 *   findagrave / find-a-grave     → find-a-grave
 *   goodreads                     → goodreads-author
 *   linkedin                      → linkedin
 *   twitter / x                   → twitter-x
 *   instagram                     → instagram
 *   facebook                      → facebook
 *   mastodon                      → mastodon
 *   youtube                       → youtube
 *   spotify                       → spotify
 *   apple-music / itunes          → apple-music
 *   bandcamp                      → bandcamp
 *   soundcloud                    → soundcloud
 *   archive.org / internet-archive→ internet-archive
 *   anything else                 → other (original LinkType saved
 *                                          to Note for context)
 *
 * Idempotent — re-runs use INSERT…WHERE-NOT-EXISTS so duplicate
 * (CreditPersonId, LinkTypeId, Url) tuples are no-ops.
 *
 * Legacy tblCreditPersonLinks is NOT dropped — it stays as a
 * read-fallback for one release cycle. Separate later migration
 * retires it.
 *
 * @migration-modifies tblCreditPersonExternalLinks
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

function _migBfPersonLinks_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migBfPersonLinks_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migBfPersonLinks_out('Backfill credit-person external links migration starting (#833)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migBfPersonLinks_tableExists($mysqli, 'tblCreditPersonExternalLinks')
    || !_migBfPersonLinks_tableExists($mysqli, 'tblExternalLinkTypes')) {
    _migBfPersonLinks_out('[skip] external-links schema not yet present — run migrate-external-links.php first.');
    return;
}

if (!_migBfPersonLinks_tableExists($mysqli, 'tblCreditPersonLinks')) {
    _migBfPersonLinks_out('[skip] tblCreditPersonLinks not present — nothing to backfill.');
    return;
}

/* Build slug → id map for the registry. */
$slugToId = [];
$res = $mysqli->query('SELECT Id, Slug FROM tblExternalLinkTypes');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $slugToId[(string)$row['Slug']] = (int)$row['Id'];
    }
    $res->close();
}
if (!$slugToId) {
    _migBfPersonLinks_out('[skip] tblExternalLinkTypes is empty — nothing to map to.');
    return;
}

/* Free-text → slug map (case-insensitive, accept several spellings). */
$linkTypeMap = [
    'wikipedia'             => 'wikipedia',
    'wiki'                  => 'wikipedia',
    'wikidata'              => 'wikidata',
    'imslp'                 => 'imslp',
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
    'discogs'               => 'discogs',
    'viaf'                  => 'viaf',
    'loc'                   => 'loc-name-authority',
    'library-of-congress'   => 'loc-name-authority',
    'loc-name-authority'    => 'loc-name-authority',
    'findagrave'            => 'find-a-grave',
    'find-a-grave'          => 'find-a-grave',
    'goodreads'             => 'goodreads-author',
    'goodreads-author'      => 'goodreads-author',
    'linkedin'              => 'linkedin',
    'twitter'               => 'twitter-x',
    'x'                     => 'twitter-x',
    'twitter-x'             => 'twitter-x',
    'instagram'             => 'instagram',
    'facebook'              => 'facebook',
    'mastodon'              => 'mastodon',
    'youtube'               => 'youtube',
    'spotify'               => 'spotify',
    'apple-music'           => 'apple-music',
    'itunes'                => 'apple-music',
    'bandcamp'              => 'bandcamp',
    'soundcloud'            => 'soundcloud',
    'archive.org'           => 'internet-archive',
    'archive'               => 'internet-archive',
    'internet-archive'      => 'internet-archive',
    'cyber-hymnal'          => 'cyber-hymnal',
    'cyberhymnal'           => 'cyber-hymnal',
];

$resolveSlug = static function (string $rawType) use ($linkTypeMap): string {
    $key = strtolower(trim($rawType));
    return $linkTypeMap[$key] ?? 'other';
};

/* Walk every legacy row, resolve slug, INSERT into the new table. */
$res = $mysqli->query(
    'SELECT CreditPersonId, LinkType, Url, Label, SortOrder
       FROM tblCreditPersonLinks
      ORDER BY CreditPersonId ASC, SortOrder ASC, Id ASC'
);
if (!$res) {
    _migBfPersonLinks_out('WARN: SELECT from tblCreditPersonLinks failed: ' . $mysqli->error);
    return;
}

$ins = $mysqli->prepare(
    'INSERT INTO tblCreditPersonExternalLinks
         (CreditPersonId, LinkTypeId, Url, Note, SortOrder)
     SELECT ?, ?, ?, ?, ?
       FROM DUAL
      WHERE NOT EXISTS (
            SELECT 1 FROM tblCreditPersonExternalLinks x
             WHERE x.CreditPersonId = ?
               AND x.LinkTypeId     = ?
               AND x.Url            = ?
        )'
);

$inserted = 0;
$mapped   = 0;  /* mapped to a non-other slug */
$other    = 0;  /* fell through to 'other' */
$skipped  = 0;  /* already on disk */

while ($row = $res->fetch_assoc()) {
    $personId  = (int)$row['CreditPersonId'];
    $url       = trim((string)($row['Url'] ?? ''));
    $rawType   = (string)($row['LinkType'] ?? '');
    $label     = trim((string)($row['Label'] ?? ''));
    $sortOrder = (int)($row['SortOrder'] ?? 0);
    if ($url === '' || $personId <= 0) continue;

    $slug = $resolveSlug($rawType);
    if (!isset($slugToId[$slug])) {
        /* 'other' should always be present per the seed list — this
           is a belt-and-braces check for a curator who deactivated
           it. Skip the row rather than 500 the migration. */
        _migBfPersonLinks_out("WARN: slug '{$slug}' not in registry — skipping link {$url}");
        continue;
    }
    $linkTypeId = $slugToId[$slug];

    /* When the resolution falls to 'other', stash the original
       free-text LinkType in Note so the context isn't lost (a later
       UI / migration can promote those to dedicated slugs). When
       a clean slug match was found, prefer the legacy Label as the
       Note (often more meaningful than the slug name itself). */
    if ($slug === 'other') {
        $note = $rawType !== '' ? mb_substr($rawType, 0, 255) : null;
        $other++;
    } else {
        $note = $label !== '' ? mb_substr($label, 0, 255) : null;
        $mapped++;
    }

    $ins->bind_param('iissiiis', $personId, $linkTypeId, $url, $note, $sortOrder, $personId, $linkTypeId, $url);
    if (@$ins->execute()) {
        if ($ins->affected_rows > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    } else {
        _migBfPersonLinks_out('WARN: insert failed for person ' . $personId . ': ' . $mysqli->error);
    }
}
$res->close();
$ins->close();

_migBfPersonLinks_out("[seed] backfilled {$inserted} link(s) into tblCreditPersonExternalLinks.");
_migBfPersonLinks_out("       mapped to a known slug: {$mapped}");
_migBfPersonLinks_out("       fell to 'other':         {$other}  (original LinkType preserved in Note)");
_migBfPersonLinks_out("       already on disk:         {$skipped}");
_migBfPersonLinks_out('Backfill complete.');
_migBfPersonLinks_out('The legacy tblCreditPersonLinks table is NOT dropped by this migration —');
_migBfPersonLinks_out('it stays as a read-fallback for one release cycle. A later migration');
_migBfPersonLinks_out('retires it once the public site has been on the new system.');
