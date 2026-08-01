<?php

declare(strict_types=1);

/**
 * iHymns — MusicBrainz-Parity External Links Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds six more external-link providers commonly surfaced on a MusicBrainz
 * artist page that iHymns didn't yet detect:
 *
 *   - Myspace        (legacy social, still hosts older artist pages)
 *   - AllMusic       (sister site to AllMovie / Discogs-style metadata)
 *   - Last.fm        (scrobble-based listen + discovery)
 *   - Bandsintown    (touring / live performance dates)
 *   - Genius         (lyrics + annotations)
 *   - Muzikum.eu     (lyrics + translations)
 *
 * The seed lists in `migrate-external-links.php` +
 * `migrate-external-link-patterns.php` already cover these on a fresh
 * install; this supplementary migration brings already-deployed installs
 * in line via the dashboard.
 *
 * THIS IS A DATA MIGRATION, NOT DDL. It creates no table and adds no
 * column — it seeds rows into two tables that
 * migrate-external-links.php (#833) and migrate-external-link-patterns.php
 * (#845) already created. That is why there is no `@migration-adds`
 * doctag and nothing to mirror into appWeb/.sql/schema.sql: rule #19's
 * byte-identity requirement applies to schema declarations, and seed
 * DATA has no schema.sql counterpart. Fresh installs get these six
 * providers from the seed lists in those two migrations instead; this
 * file exists purely to bring already-deployed installs level.
 *
 * NOT DESTRUCTIVE — no DELETE, no DROP, no ALTER. But see the
 * idempotency note below: a re-run is not quite a no-op for link TYPES.
 *
 * IDEMPOTENCY — two different mechanisms, and the asymmetry matters:
 *   - Link TYPES use INSERT … ON DUPLICATE KEY UPDATE keyed on the Slug
 *     unique index. A re-run therefore REWRITES Name / Category /
 *     AppliesTo / AllowMultiple / IconClass / DisplayOrder back to the
 *     values in $seedTypes below. If a curator has renamed "Muzikum.eu"
 *     or re-ordered it on /manage/external-link-types, re-running this
 *     card silently reverts that edit. Safe, but not inert.
 *   - URL PATTERNS use INSERT … WHERE NOT EXISTS. A re-run leaves every
 *     existing pattern row exactly as it is, including curator edits to
 *     Priority or MatchSubdomains.
 *
 * OPERATOR VIEW. Card "MusicBrainz-Parity External Links" on
 * /manage/setup-database. Its registry probe is a single sentinel — it
 * looks for the 'allmusic' slug only — so the card flips to applied as
 * soon as the type seed has run, whether or not the pattern seed did.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-musicbrainz-style-links.php
 *   Web: /manage/setup-database → "MusicBrainz-Parity External Links"
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

function _migMbLinks_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migMbLinks_tableExists(\mysqli $db, string $table): bool
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

_migMbLinks_out('MusicBrainz-Parity External Links migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

foreach (['tblExternalLinkTypes', 'tblExternalLinkPatterns'] as $required) {
    if (!_migMbLinks_tableExists($mysqli, $required)) {
        _migMbLinks_out("ERROR: {$required} not found. Run migrate-external-links.php"
            . ' (#833) and migrate-external-link-patterns.php (#845) first.');
        return;
    }
}

/* ----------------------------------------------------------------------
 * Step 1 — Seed link types
 * ---------------------------------------------------------------------- */
$seedTypes = [
    /* slug, name, category, applies_to, allow_multiple, icon, order */
    ['myspace',     'Myspace',     'social',      'person',        0, 'bi-people',            105],
    ['allmusic',    'AllMusic',    'information', 'song,person',   1, 'bi-music-note',        87],
    ['lastfm',      'Last.fm',     'listen',      'song,person',   1, 'bi-soundwave',         88],
    ['bandsintown', 'Bandsintown', 'information', 'person',        0, 'bi-ticket-perforated', 26],
    ['genius',      'Genius',      'information', 'song,person',   1, 'bi-file-text',         27],
    ['muzikum',     'Muzikum.eu',  'information', 'song,person',   1, 'bi-file-text',         28],
];

$upsert = $mysqli->prepare(
    'INSERT INTO tblExternalLinkTypes
         (Slug, Name, Category, AppliesTo, AllowMultiple, IconClass, DisplayOrder)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         Name          = VALUES(Name),
         Category      = VALUES(Category),
         AppliesTo     = VALUES(AppliesTo),
         AllowMultiple = VALUES(AllowMultiple),
         IconClass     = VALUES(IconClass),
         DisplayOrder  = VALUES(DisplayOrder)'
);
$typesAdded   = 0;
$typesUpdated = 0;
foreach ($seedTypes as $t) {
    [$slug, $name, $cat, $appliesTo, $multi, $icon, $order] = $t;
    $upsert->bind_param('ssssisi', $slug, $name, $cat, $appliesTo, $multi, $icon, $order);
    /* The `@` is vestigial. getDbMysqli() puts the connection into
       MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, under which a failed
       execute() raises mysqli_sql_exception rather than a PHP warning —
       and the error-suppression operator does not suppress exceptions.
       So a genuine failure here propagates out of the file regardless. */
    @$upsert->execute();
    /* affected_rows has THREE meaningful values after ON DUPLICATE KEY
       UPDATE, and the third is the one that surprises people:
         1 → the row was inserted,
         2 → the row existed and at least one column CHANGED,
         0 → the row existed and every column already matched.
       A second run of an unmodified install therefore reports "0
       inserted, 0 updated" — which is correct and means "all six are
       already exactly right", not "nothing happened because something
       went wrong". Do not read these counters as a completeness check.
       https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html */
    $ar = $mysqli->affected_rows;
    if ($ar === 1)      $typesAdded++;
    elseif ($ar === 2)  $typesUpdated++;
}
$upsert->close();
_migMbLinks_out("[seed] {$typesAdded} link type" . ($typesAdded === 1 ? '' : 's')
    . " inserted, {$typesUpdated} updated.");

/* ----------------------------------------------------------------------
 * Step 2 — Seed URL patterns
 * ---------------------------------------------------------------------- */
/* Priority is ASCENDING-wins, which is worth stating because the column
   name suggests the opposite: external_link_helpers.php reads the pattern
   table `ORDER BY Priority ASC, Host ASC` and takes the first host match,
   so a LOWER number is tried EARLIER. The numbers below are not a
   sequence of their own — they are positions interleaved into the single
   global ladder seeded by migrate-external-link-patterns.php (#845), so
   changing one here changes where that provider sits relative to every
   pattern in that file, not just relative to these six. */
$seedRows = [
    /* [slug, host, path-prefix-or-null, match-subdomains, priority, note?] */
    ['myspace',     'myspace.com',      null, 1, 224, 'Legacy social network'],
    ['allmusic',    'allmusic.com',     null, 1, 165, 'Sister site to AllMovie'],
    ['lastfm',      'last.fm',          null, 1, 145, 'Suffix match covers www.last.fm'],
    ['bandsintown', 'bandsintown.com',  null, 1, 75,  'Suffix match covers www.bandsintown.com'],
    ['genius',      'genius.com',       null, 1, 36,  'Lyrics + annotations'],
    ['muzikum',     'muzikum.eu',       null, 1, 37,  'Lyrics + translations'],
];

/* Re-read the slug → Id map from the database rather than trusting
   insert_id from step 1: on a re-run most of the six were UPDATEs, which
   yield no insert_id at all, and the pattern rows need the Id that
   already exists. Loading the WHOLE table (not just the six) also lets
   the $pMissing counter below distinguish "the type seed didn't take"
   from "this pattern's provider was never seeded" — in normal operation
   step 1 has just guaranteed all six are present, so a non-zero
   $pMissing is a signal that something upstream failed silently. */
$slugToId = [];
$res = $mysqli->query('SELECT Id, Slug FROM tblExternalLinkTypes');
while ($row = $res->fetch_assoc()) {
    $slugToId[(string)$row['Slug']] = (int)$row['Id'];
}
$res->close();

/* The pattern seed's idempotency guard, and the one subtlety in this file
   that is easy to get wrong.

   ELI5: "insert this row, but only if an identical one isn't there
   already" — written as a SELECT with a WHERE, because plain INSERT has
   no such conditional form.

   Detail on the two pieces:
   - `FROM DUAL` is MySQL's one-row dummy table. It exists so a SELECT
     that produces constants can still carry a WHERE clause, which is
     what turns this into a conditional INSERT.
     https://dev.mysql.com/doc/refman/8.0/en/select.html
   - `COALESCE(PathPrefix, "") = COALESCE(?, "")` rather than
     `PathPrefix = ?` is load-bearing. Every one of the six rows below
     has a NULL path prefix, and in SQL `NULL = NULL` evaluates to NULL,
     not TRUE — so a direct comparison would never find the existing row,
     NOT EXISTS would always be true, and every re-run would insert six
     more duplicates. Folding NULL to '' on both sides makes the
     comparison work for the NULL-vs-NULL case that is the norm here.
     https://dev.mysql.com/doc/refman/8.0/en/working-with-null.html

   The nine bound parameters are the six INSERT values followed by the
   three the NOT EXISTS re-checks; typeId, host and path are each bound
   twice because a placeholder cannot be referenced more than once. */
$insert = $mysqli->prepare(
    'INSERT INTO tblExternalLinkPatterns
         (LinkTypeId, Host, PathPrefix, MatchSubdomains, Priority, Note)
     SELECT ?, ?, ?, ?, ?, ?
       FROM DUAL
      WHERE NOT EXISTS (
            SELECT 1 FROM tblExternalLinkPatterns
             WHERE LinkTypeId = ?
               AND Host       = ?
               AND COALESCE(PathPrefix, "") = COALESCE(?, "")
      )'
);

$pInserted = 0;
$pSkipped  = 0;
$pMissing  = 0;
foreach ($seedRows as $r) {
    $slug    = (string)$r[0];
    $host    = (string)$r[1];
    $path    = $r[2] !== null ? (string)$r[2] : null;
    $matchSd = (int)$r[3];
    $prio    = (int)$r[4];
    $note    = isset($r[5]) ? (string)$r[5] : null;

    if (!isset($slugToId[$slug])) {
        $pMissing++;
        continue;
    }
    $typeId = $slugToId[$slug];

    $insert->bind_param(
        'issiisiss',
        $typeId, $host, $path, $matchSd, $prio, $note,
        $typeId, $host, $path
    );
    $insert->execute();
    if ($mysqli->affected_rows > 0) $pInserted++;
    else                            $pSkipped++;
}
$insert->close();

_migMbLinks_out("[seed] {$pInserted} pattern" . ($pInserted === 1 ? '' : 's')
    . " inserted, {$pSkipped} already present"
    . ($pMissing > 0 ? ", {$pMissing} skipped (link type missing)" : '')
    . '.');

_migMbLinks_out('MusicBrainz-Parity External Links migration finished.');
