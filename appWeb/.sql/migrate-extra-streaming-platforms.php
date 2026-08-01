<?php

declare(strict_types=1);

/**
 * iHymns — Extra Streaming Platforms Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds Tidal, Deezer, Amazon Music, Pandora, iHeartRadio, Qobuz, Napster,
 * Anghami, JioSaavn, Yandex Music, Mixcloud and Audiomack to the
 * `tblExternalLinkTypes` registry (#833) and seeds the matching host
 * patterns into `tblExternalLinkPatterns` (#845) so a pasted URL from
 * any of those services auto-detects to the correct provider in the
 * songs / songbooks / works / credit-people external-links editors.
 *
 * The seed lists in `migrate-external-links.php` + `migrate-external-link-patterns.php`
 * already cover these on a fresh install — this supplementary migration
 * exists to bring already-deployed installs in line without re-running
 * the bigger migrations (whose probes are satisfied as soon as the
 * tables exist, so the dashboard wouldn't prompt to re-run them).
 *
 * NON-DESTRUCTIVE, DATA-ONLY: no CREATE, no ALTER, no DROP — it seeds rows
 * into two tables other migrations own. That is why it carries no
 * `@migration-adds` doctag and needs no `schema.sql` mirror: rule #19's
 * byte-identical-DDL requirement binds migrations that create structure, and
 * there is none here. Recovery from an unwanted run is a manual DELETE of the
 * twelve slugs (and their patterns, which cascade via fk_linkpat_type).
 *
 * IDEMPOTENT — two different mechanisms, because the two tables are shaped
 * differently:
 *
 *  - Link TYPES have a UNIQUE key on Slug (uq_slug), so the seed is an
 *    `INSERT … ON DUPLICATE KEY UPDATE`. A re-run refreshes the label / icon /
 *    ordering but deliberately never lists `IsActive` in the UPDATE clause —
 *    that column is curator-controlled, and a provider somebody switched off
 *    must stay off across re-runs.
 *
 *  - Link PATTERNS have NO unique key at all (only plain indexes — see
 *    tblExternalLinkPatterns in schema.sql), so neither INSERT IGNORE nor an
 *    upsert is available. The `INSERT … SELECT … FROM DUAL WHERE NOT EXISTS`
 *    guard below IS the uniqueness rule. It is advisory rather than enforced:
 *    two concurrent runs could both pass the guard and both insert. Acceptable
 *    because migrations are single-operator, one-at-a-time from
 *    /manage/setup-database — but it is why a curator can still create a
 *    duplicate pattern by hand from /manage/external-link-types.
 *
 * The setup-database card's probe is anchored on a SINGLE sentinel slug
 * ('tidal'), not all twelve — see migration-registry.php. An OR-chain over
 * every slug would let one curator-deleted provider keep the card stuck on
 * "pending" for ever.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-extra-streaming-platforms.php
 *   Web: /manage/setup-database → "Extra Streaming Platforms"
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

function _migStreamPlat_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migStreamPlat_tableExists(\mysqli $db, string $table): bool
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

_migStreamPlat_out('Extra Streaming Platforms migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

foreach (['tblExternalLinkTypes', 'tblExternalLinkPatterns'] as $required) {
    if (!_migStreamPlat_tableExists($mysqli, $required)) {
        _migStreamPlat_out("ERROR: {$required} not found. Run migrate-external-links.php"
            . ' (#833) and migrate-external-link-patterns.php (#845) first.');
        return;
    }
}

/* ----------------------------------------------------------------------
 * Step 1 — Seed link types
 *
 * Slugs/labels/icons mirror the same rows added to
 * migrate-external-links.php $seedTypes so fresh-install seed data and
 * this supplementary migration produce identical registry state.
 * ---------------------------------------------------------------------- */
$seedTypes = [
    /* slug, name, category, applies_to, allow_multiple, icon, order */
    ['tidal',         'Tidal',         'listen', 'song,person', 1, 'bi-music-note',    86],
    ['deezer',        'Deezer',        'listen', 'song,person', 1, 'bi-music-note',    87],
    ['amazon-music',  'Amazon Music',  'listen', 'song,person', 1, 'bi-amazon',        88],
    ['pandora',       'Pandora',       'listen', 'song,person', 1, 'bi-broadcast',     89],
    ['iheartradio',   'iHeartRadio',   'listen', 'song,person', 1, 'bi-broadcast-pin', 95],
    ['qobuz',         'Qobuz',         'listen', 'song,person', 1, 'bi-music-note',    96],
    ['napster',       'Napster',       'listen', 'song,person', 1, 'bi-music-note',    97],
    ['anghami',       'Anghami',       'listen', 'song,person', 1, 'bi-music-note',    105],
    ['jiosaavn',      'JioSaavn',      'listen', 'song,person', 1, 'bi-music-note',    106],
    ['yandex-music',  'Yandex Music',  'listen', 'song,person', 1, 'bi-music-note',    107],
    ['mixcloud',      'Mixcloud',      'listen', 'song,person', 1, 'bi-cloud',         110],
    ['audiomack',     'Audiomack',     'listen', 'song,person', 1, 'bi-music-note',    111],
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
    [$slug, $name, $cat, $applies, $multi, $icon, $order] = $t;
    $upsert->bind_param('ssssisi', $slug, $name, $cat, $applies, $multi, $icon, $order);
    @$upsert->execute();
    /* MySQL's INSERT … ON DUPLICATE KEY UPDATE reports affected_rows as
       1 = inserted, 2 = an existing row was changed, 0 = the row already
       matched the seed exactly. The 0 case is counted as neither, so a fully
       up-to-date re-run correctly prints "0 inserted, 0 updated".
       https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html */
    $ar = $mysqli->affected_rows;
    if ($ar === 1)      $typesAdded++;
    elseif ($ar === 2)  $typesUpdated++;
}
$upsert->close();
_migStreamPlat_out("[seed] {$typesAdded} link type" . ($typesAdded === 1 ? '' : 's')
    . " inserted, {$typesUpdated} updated.");

/* ----------------------------------------------------------------------
 * Step 2 — Seed URL patterns
 *
 * Same rows that landed in migrate-external-link-patterns.php $seedRows
 * so re-running either migration converges on the same state. Patterns
 * use NOT EXISTS guards on (LinkTypeId, Host, PathPrefix) to stay
 * idempotent.
 * ---------------------------------------------------------------------- */
$seedRows = [
    /* [slug, host, path-prefix-or-null, match-subdomains, priority, note?] */
    ['tidal',         'tidal.com',           null, 1, 230, 'Suffix match covers listen.tidal.com / www.tidal.com / store.tidal.com'],
    ['deezer',        'deezer.com',          null, 1, 240, 'Suffix match covers www.deezer.com'],
    ['amazon-music',  'music.amazon.com',    null, 0, 250, 'Amazon Music — US (primary)'],
    ['amazon-music',  'music.amazon.co.uk',  null, 0, 251, 'Amazon Music — UK'],
    ['amazon-music',  'music.amazon.de',     null, 0, 252, 'Amazon Music — Germany'],
    ['amazon-music',  'music.amazon.co.jp',  null, 0, 253, 'Amazon Music — Japan'],
    ['amazon-music',  'music.amazon.fr',     null, 0, 254, 'Amazon Music — France'],
    ['amazon-music',  'music.amazon.it',     null, 0, 255, 'Amazon Music — Italy'],
    ['amazon-music',  'music.amazon.es',     null, 0, 256, 'Amazon Music — Spain'],
    ['amazon-music',  'music.amazon.com.au', null, 0, 257, 'Amazon Music — Australia'],
    ['amazon-music',  'music.amazon.ca',     null, 0, 258, 'Amazon Music — Canada'],
    ['amazon-music',  'music.amazon.com.br', null, 0, 259, 'Amazon Music — Brazil'],
    ['amazon-music',  'music.amazon.com.mx', null, 0, 260, 'Amazon Music — Mexico'],
    ['amazon-music',  'music.amazon.in',     null, 0, 261, 'Amazon Music — India'],
    ['pandora',       'pandora.com',         null, 1, 270],
    ['iheartradio',   'iheart.com',          null, 1, 280],
    ['iheartradio',   'iheartradio.com',     null, 1, 281, 'Alias domain'],
    ['iheartradio',   'iheartradio.ca',      null, 1, 282, 'Canada'],
    ['qobuz',         'qobuz.com',           null, 1, 290, 'Suffix match covers open.qobuz.com / www.qobuz.com'],
    ['napster',       'napster.com',         null, 1, 300, 'Suffix match covers us.napster.com etc.'],
    ['anghami',       'anghami.com',         null, 1, 310, 'Suffix match covers play.anghami.com / www.anghami.com'],
    ['jiosaavn',      'jiosaavn.com',        null, 1, 320, 'Primary domain'],
    ['jiosaavn',      'saavn.com',           null, 1, 321, 'Legacy domain (pre-Jio rebrand)'],
    ['yandex-music',  'music.yandex.ru',     null, 0, 330, 'Yandex Music — Russia (primary)'],
    ['yandex-music',  'music.yandex.com',    null, 0, 331, 'Yandex Music — international'],
    ['yandex-music',  'music.yandex.by',     null, 0, 332, 'Yandex Music — Belarus'],
    ['yandex-music',  'music.yandex.kz',     null, 0, 333, 'Yandex Music — Kazakhstan'],
    ['yandex-music',  'music.yandex.uz',     null, 0, 334, 'Yandex Music — Uzbekistan'],
    ['mixcloud',      'mixcloud.com',        null, 1, 340],
    ['audiomack',     'audiomack.com',       null, 1, 350],
];

/* Read the slug → Id map AFTER Step 1, never before: the twelve providers this
   migration exists to add usually don't have rows yet on the first run, and a
   map built earlier would miss every one of them — each pattern would then be
   counted as "link type missing" and skipped, leaving the auto-detect silently
   unwired while the card still reported success. */
$slugToId = [];
$res = $mysqli->query('SELECT Id, Slug FROM tblExternalLinkTypes');
while ($row = $res->fetch_assoc()) {
    $slugToId[(string)$row['Slug']] = (int)$row['Id'];
}
$res->close();

/* `INSERT … SELECT … FROM DUAL WHERE NOT EXISTS` is the standard way to say
   "insert this row unless one like it is already there" when the table has no
   unique key to hang INSERT IGNORE / ON DUPLICATE KEY off. FROM DUAL is
   MySQL's no-table dummy source, needed because a SELECT can't carry a WHERE
   without a FROM. https://dev.mysql.com/doc/refman/8.0/en/select.html

   COALESCE on BOTH sides of the PathPrefix comparison is load-bearing, not
   defensive noise: in SQL, `NULL = NULL` evaluates to NULL (not TRUE), so a
   host-only rule — every row in this file has a NULL PathPrefix — would never
   match its own existing copy, the guard would pass on every run, and each
   re-run would insert a fresh duplicate of all 29 patterns.
   https://dev.mysql.com/doc/refman/8.0/en/working-with-null.html */
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

    /* Nine placeholders for six logical values: the INSERT's SELECT list takes
       all six, then the NOT EXISTS guard re-takes type/host/path. Prepared
       statements have no named parameters here, so the repeat is unavoidable —
       keep the two groups visually separated on their own lines so a future
       edit doesn't drop one and silently shift the whole binding. */
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

_migStreamPlat_out("[seed] {$pInserted} pattern" . ($pInserted === 1 ? '' : 's')
    . " inserted, {$pSkipped} already present"
    . ($pMissing > 0 ? ", {$pMissing} skipped (link type missing)" : '')
    . '.');

_migStreamPlat_out('Extra Streaming Platforms migration finished.');
