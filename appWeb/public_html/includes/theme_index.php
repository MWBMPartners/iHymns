<?php

declare(strict_types=1);

/**
 * iHymns — Browse-by-Theme count core (#1148)
 *
 * ELI5: this is the ONE place that answers "for each theme, how many songs a
 * visitor can actually see carry it?" — the home "Popular themes" strip, the
 * `/themes` A–Z index and the sitemap all ask it, so the number on a chip can
 * never disagree with the page the chip links to.
 *
 * DETAIL:
 * -------
 * ONE query core (rule #22). Before this, `?action=popular_tags` counted EVERY
 * `tblSongTagMap` row while `includes/pages/tag.php` listed only
 * visible-and-servable songs (`songVisibleSql` AND `songServableSql`) — so on
 * any install with soft-deleted songs (#1694) or disabled songbooks (#1765) the
 * chip said "42" and the page it opened showed fewer: a quiet contract
 * violation between the two ends of one link (rule #33's spirit applied to a
 * number). This core joins `tblSongs` and applies the SAME two predicates, so
 * every public count is aligned by construction.
 *
 * SCOPE, NOT CACHE (rules #17 / #44): the tag registry is a few hundred rows and
 * the map is a few thousand, with the `tblSongTagMap.TagId` FK index behind the
 * GROUP BY — a single indexed aggregate is sub-millisecond. A denormalised
 * `UseCount` column would buy nothing and cost a migration + a write-path
 * maintenance obligation in every tagging funnel + a drift risk. Derive, don't
 * store.
 *
 * DORMANT-SAFE (rule #28-C — three docroots, one MySQL, mixed code versions):
 * `themeIndexReady()` probes the tables exist and `themeIndexHierarchyReady()`
 * probes the #1152 `ParentId` column, both fail-open; the two visibility
 * helpers are themselves readiness-gated. A fresh, un-migrated or fully-migrated
 * install all behave correctly — never a mysqli-STRICT white-screen (#1228).
 *
 * @see includes/pages/tag.php       (the per-theme destination whose count this aligns with)
 * @see includes/song_soft_delete.php (songVisibleSql)
 * @see includes/songbook_visibility.php (songServableSql)
 * @see .claude/browse-by-theme-1148-plan.md §3.1
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';    /* songVisibleSql() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_visibility.php'; /* songServableSql() (#1765) */

/**
 * ELI5: do the theme tables even exist on this install?
 * WHY: on a long-running install that hasn't applied the tag migrations, a
 * SELECT against them would throw under mysqli STRICT and white-screen the page
 * (#1228). Probed once per request; fail-open to false.
 */
function themeIndexReady(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $res = $db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('tblSongTags', 'tblSongTagMap')"
        );
        $row = $res ? $res->fetch_assoc() : null;
        return $cached = ((int)($row['c'] ?? 0)) === 2;
    } catch (\Throwable $e) {
        return $cached = false;
    }
}

/**
 * ELI5: does this install have the 2-level theme hierarchy column (#1152)?
 * WHY: the parent-name context on a child row is emitted only when
 * `tblSongTags.ParentId` exists; a pre-#1152 install renders a correct FLAT
 * list instead of throwing. Static-cached per request (the manage/tags.php
 * `$hasThemeCols` pattern).
 */
function themeIndexHierarchyReady(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $res = $db->query("SHOW COLUMNS FROM tblSongTags LIKE 'ParentId'");
        return $cached = ($res && $res->num_rows > 0);
    } catch (\Throwable $e) {
        return $cached = false;
    }
}

/**
 * The ONE count query — themes with their VISIBLE-song counts.
 *
 * ELI5: "list the themes and, for each, how many songs a visitor can actually
 * open that carry it", newest-curated-first or A–Z.
 *
 * @param \mysqli  $db
 * @param int|null $limit null = all themes; an int caps + orders by popularity's
 *                        natural companion (see $order). Bound, never interpolated.
 * @param string   $order 'popular' (useCount DESC, then name) or 'name' (default, A–Z).
 * @return list<array{id:int,name:string,slug:string,description:?string,parentId:?int,parentName:?string,useCount:int}>
 *         Empty when the tables are absent (callers render their empty state).
 *         parentId/parentName are null on a pre-#1152 install.
 */
function themeIndexCounts(\mysqli $db, ?int $limit = null, string $order = 'name'): array
{
    if (!themeIndexReady($db)) {
        return [];
    }

    /* The hierarchy select/join/group triplet is present only when the #1152
       column exists — a pre-migration install gets a correct flat list. */
    $hier       = themeIndexHierarchyReady($db);
    $selectHier = $hier ? ', t.ParentId AS parentId, p.Name AS parentName' : '';
    $joinHier   = $hier ? ' LEFT JOIN tblSongTags p ON p.Id = t.ParentId'   : '';
    /* p.Name goes in GROUP BY explicitly — ONLY_FULL_GROUP_BY's
       functional-dependency inference through a chained join is not something
       to bet a public page on. */
    $groupHier  = $hier ? ', t.ParentId, p.Name' : '';

    /* $order is an allow-list, never interpolated user input. */
    $orderSql = ($order === 'popular') ? 'useCount DESC, t.Name ASC' : 't.Name ASC';

    /* songVisibleSql()/songServableSql() return SQL fragments built from
       hardcoded constants in PHP source (rule #5's legitimate interpolation),
       and align this count with what tag.php lists. Both are internally
       readiness-gated (fail-open on a pre-#1694/#1765 install → today's
       unfiltered count, never a throw). */
    $visible  = songVisibleSql($db, 's');
    $servable = songServableSql($db, 's');

    /* lastTouched (sitemap hardening, 2026-08-30) — ADDITIVE: the most recent
       UpdatedAt among this theme's own VISIBLE songs (same join, same
       predicates already in this query — the aggregate is free). Nothing
       about the existing count changes; this is one more SELECT column and
       one more key on the returned rows. Its ONE consumer today is
       sitemap.xml.php's themes section (a theme page's content changes
       exactly when the songs carrying it do); the /themes index and the home
       "Popular themes" chips simply ignore the extra key. */
    $sql = "SELECT t.Id AS id, t.Name AS name, t.Slug AS slug, t.Description AS description{$selectHier},
                   COUNT(m.TagId) AS useCount, MAX(s.UpdatedAt) AS lastTouched
              FROM tblSongTags t
              JOIN tblSongTagMap m ON m.TagId = t.Id
              JOIN tblSongs s      ON s.SongId = m.SongId{$joinHier}
             WHERE {$visible} AND {$servable}
             GROUP BY t.Id, t.Name, t.Slug, t.Description{$groupHier}
             ORDER BY {$orderSql}";
    if ($limit !== null) {
        $sql .= ' LIMIT ?';
    }

    try {
        $stmt = $db->prepare($sql);
        if ($limit !== null) {
            $lim = max(1, (int)$limit);
            $stmt->bind_param('i', $lim);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[theme_index] ' . $e->getMessage());
        return [];
    }

    foreach ($rows as &$r) {
        $r['id']       = (int)$r['id'];
        $r['useCount'] = (int)$r['useCount'];
        /* lastTouched stays a raw string (or null) — callers that want a
           date-only <lastmod> shape run it through sitemapLastmod(); this
           core doesn't know it's feeding a sitemap. */
        $r['lastTouched'] = isset($r['lastTouched']) && $r['lastTouched'] !== null ? (string)$r['lastTouched'] : null;
        /* Normalise the hierarchy pair to explicit nulls whether the columns
           were selected (hier present, but a top-level tag has NULL ParentId)
           or not selected at all (pre-#1152) — callers always see both keys. */
        $r['parentId']   = (isset($r['parentId'])   && $r['parentId']   !== null) ? (int)$r['parentId'] : null;
        $r['parentName'] = (isset($r['parentName']) && $r['parentName'] !== null) ? (string)$r['parentName'] : null;
    }
    unset($r);

    return $rows;
}

/**
 * Resolve ONE theme by slug, with its VISIBLE-song count — for the /tag/<slug>
 * OG matcher (#1148 §3.6).
 *
 * ELI5: "what's this theme's name + description + how many songs a visitor can
 * see carry it?", for the social-preview meta tags.
 *
 * WHY A SIBLING IN THIS FILE, NOT AN INLINE COUNT IN index.php (A.5): an OG
 * description count that disagreed with the page it advertises would be the
 * same count-drift bug in a crawler's clothes. This resolves the tag row, then
 * counts its visible songs with the SAME songVisibleSql + songServableSql
 * filter tag.php lists by — so the OG count and the page count are one number.
 * A known-but-empty slug returns a real row with useCount 0 (it's a valid
 * page); an unknown slug returns null (the caller falls through to the generic
 * OG block).
 *
 * @return array{id:int,name:string,slug:string,description:?string,useCount:int}|null
 */
function themeIndexOne(\mysqli $db, string $slug): ?array
{
    $slug = trim($slug);
    if ($slug === '' || !themeIndexReady($db)) {
        return null;
    }
    try {
        /* 1. Resolve the tag row (indexed on Slug). */
        $stmt = $db->prepare('SELECT Id, Name, Slug, Description FROM tblSongTags WHERE Slug = ?');
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $tag = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$tag) {
            return null;   /* unknown slug — caller uses the generic OG block */
        }
        /* 2. Its visible-song count — the SAME filter tag.php lists by, so the
           OG count matches the page (source-constant fragments, rule #5). */
        $visible  = songVisibleSql($db, 's');
        $servable = songServableSql($db, 's');
        $cstmt = $db->prepare(
            "SELECT COUNT(*) AS c
               FROM tblSongTagMap m JOIN tblSongs s ON s.SongId = m.SongId
              WHERE m.TagId = ? AND {$visible} AND {$servable}"
        );
        $tid = (int)$tag['Id'];
        $cstmt->bind_param('i', $tid);
        $cstmt->execute();
        $crow = $cstmt->get_result()->fetch_assoc();
        $cstmt->close();
    } catch (\Throwable $e) {
        error_log('[theme_index] one: ' . $e->getMessage());
        return null;
    }
    return [
        'id'          => (int)$tag['Id'],
        'name'        => (string)$tag['Name'],
        'slug'        => (string)$tag['Slug'],
        'description' => $tag['Description'] !== null ? (string)$tag['Description'] : null,
        'useCount'    => (int)($crow['c'] ?? 0),
    ];
}

/**
 * A raw, table-wide "has tag MEMBERSHIP changed at all?" signal — NOT a
 * per-tag usage count (never shown to a user; sits alongside
 * `themeIndexCounts()`/`themeIndexOne()`, not a rival to them).
 *
 * ELI5: `tblSongTagMap` has no `UpdatedAt` column at all, so there is no
 * date to ask "did anything change?" — but a plain row COUNT still answers
 * it: a tag being added to or removed from a song moves this number even
 * though nothing else about the song necessarily did. Its ONE consumer is
 * `sitemap.xml.php`'s conditional-GET fingerprint (a themes/tag page's
 * content changes when tag membership does, not only when a song's own
 * `UpdatedAt` moves).
 *
 * WHY THIS LIVES HERE, NOT AS A SECOND `COUNT(...) FROM tblSongTagMap` IN
 * THE SITEMAP FILE: `tests/php/test-theme-index.php` bans any second public
 * surface from holding its own tag-count query — this file is the ONE core
 * (rule #22) — so a NEW theme-adjacent count gets a new function HERE, not a
 * bespoke query wherever it's needed.
 *
 * Deliberately UNFILTERED (no `songVisibleSql`/`songServableSql` — a
 * change-detection signal must count a hidden row's tag being touched too,
 * the same "over-invalidation is the safe direction" argument
 * `songs_index_etag.php`'s own version-signal query makes) and
 * schema-tolerant (0 on a pre-#1152 install or any transient failure,
 * never a throw).
 *
 * @param \mysqli $db
 * @return int Total row count in tblSongTagMap, or 0 when unavailable.
 */
function themeIndexMembershipCount(\mysqli $db): int
{
    if (!themeIndexReady($db)) {
        return 0;
    }
    try {
        $res = $db->query('SELECT COUNT(*) AS c FROM tblSongTagMap');
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        return (int)($row['c'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}
