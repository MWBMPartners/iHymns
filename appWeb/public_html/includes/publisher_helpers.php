<?php

declare(strict_types=1);

/**
 * iHymns — Publisher registry shared helpers (#93 / epic #1765)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The vocab lists (what "Kind" and "Role" values a publisher/link can have),
 * the name -> URL-slug fold, the "make this slug unique" loop, and the ONE
 * find-or-create funnel for a publisher name — the small shared pieces that the
 * admin page, the editor picker, the /publisher page and the API all need.
 * Kept in ONE file (mirrors includes/tune_helpers.php) so no two callers can
 * drift on the vocab or the slug rules (rule #22 / #35).
 *
 * DETAILED / WHY VARCHAR-VOCAB CONSTANTS, NOT ENUM (rule #20)
 * ----------------------------------------------------------------------------
 * `tblPublishers.Kind` and `tblSongbookPublishers.Role` are VARCHAR columns
 * app-validated against IHYMNS_PUBLISHER_KINDS / IHYMNS_PUBLISHER_ROLES here —
 * growing either vocabulary is a one-line edit here, never an ALTER (an ENUM
 * value-add is the "second migration" rule #20 forbids). Both are the SAME map
 * the admin dropdowns render from and the persist path validates against, so
 * the UI and the store can't disagree on the allowed set.
 *
 * Direct access is blocked (same guard as tune_helpers.php / musician_helpers.php)
 * so this file can't be requested as an endpoint via an open Apache config.
 *
 * @link appWeb/public_html/includes/tune_helpers.php  the precedent this mirrors
 * @link appWeb/public_html/includes/publisher_admin.php  the CRUD cores that consume this
 * @see #93
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Publisher entity kinds (tblPublishers.Kind). App-validated vocabulary — add a
 * kind here, never an ENUM value (rule #20). key = stored value, value = label.
 */
const IHYMNS_PUBLISHER_KINDS = [
    'company' => 'Company',
    'person'  => 'Person',
    'imprint' => 'Imprint',
    'society' => 'Society',
    'other'   => 'Other',
];

/**
 * Songbook<->publisher roles (tblSongbookPublishers.Role). App-validated
 * vocabulary — add a role here, never an ENUM value (rule #20).
 */
const IHYMNS_PUBLISHER_ROLES = [
    'publisher'     => 'Publisher',
    'co-publisher'  => 'Co-publisher',
    'distributor'   => 'Distributor',
    'imprint'       => 'Imprint',
    'administrator' => 'Administrator',
    'printer'       => 'Printer',
];

/**
 * Publisher name -> URL handle. RE-HOMED byte-for-byte from
 * ihymns_tune_slugify() (same iconv fall-through: a name that cannot
 * transliterate falls back to the raw name, the [^a-z0-9]+ strip removes what
 * is left, and an all-non-Latin name collapses to '' -> 'publisher', which the
 * caller's collision loop turns into 'publisher-2'). The 110-char cap leaves
 * headroom inside tblPublishers.Slug's VARCHAR(120) for the '-N' suffix.
 *
 * @link https://www.php.net/manual/en/function.iconv.php
 */
function ihymns_publisher_slugify(string $name): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    $s = $s === false ? $name : $s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s === '' ? 'publisher' : substr($s, 0, 110);
}

/**
 * True when tblPublishers exists in the current database — the one probe the
 * find-or-create funnel and the editor read paths gate on (mysqli STRICT +
 * web-run migrations, rule #9).
 */
function publisherTableExists(\mysqli $db): bool
{
    try {
        $r = $db->query("SHOW TABLES LIKE 'tblPublishers'");
        return (bool)($r && $r->num_rows > 0);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Make $base unique against tblPublishers.Slug (auto-suffixing '-2', '-3', …).
 * Mirrors tuneSlugEnsureUnique() exactly; the 114-char cut leaves room for the
 * suffix inside VARCHAR(120).
 *
 * @param int|null $excludeId  Exclude a row's own current slug (so a no-op
 *                             re-save never suffixes itself).
 */
function publisherSlugEnsureUnique(\mysqli $db, string $base, ?int $excludeId = null): string
{
    $slug = $base;
    $n    = 1;
    if ($excludeId !== null) {
        $slugTaken = $db->prepare('SELECT 1 FROM tblPublishers WHERE Slug = ? AND Id <> ? LIMIT 1');
    } else {
        $slugTaken = $db->prepare('SELECT 1 FROM tblPublishers WHERE Slug = ? LIMIT 1');
    }
    while (true) {
        if ($excludeId !== null) {
            $slugTaken->bind_param('si', $slug, $excludeId);
        } else {
            $slugTaken->bind_param('s', $slug);
        }
        $slugTaken->execute();
        $taken = $slugTaken->get_result()->fetch_row() !== null;
        if (!$taken) break;
        $n++;
        $slug = substr($base, 0, 114) . '-' . $n;
    }
    $slugTaken->close();
    return $slug;
}

/**
 * The ONE find-or-create funnel for a publisher NAME (mirrors
 * tuneFindOrCreateByName()). Used by the editor's multi-publisher picker when a
 * curator types a name that isn't yet a registry row, so a book can be linked
 * to a publisher in one step. Case-insensitive match (Name is unicode_ci).
 *
 *   - On a hit, returns the existing Id.
 *   - On a miss, INSERTs a bare (Name, Slug, Kind='company') row and returns
 *     its Id — a curator refines Kind / person-link / parent afterward on
 *     /manage/publishers.
 *   - Returns null only when tblPublishers is unavailable (pre-migration).
 *
 * @return int|null
 */
function publisherFindOrCreateByName(\mysqli $db, string $name): ?int
{
    $name = mb_substr(trim($name), 0, 255);
    if ($name === '') { return null; }
    try {
        if (!publisherTableExists($db)) { return null; }

        $stmt = $db->prepare('SELECT Id FROM tblPublishers WHERE Name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) { return (int)$row['Id']; }

        $base = ihymns_publisher_slugify($name);
        $slug = publisherSlugEnsureUnique($db, $base);
        $ins  = $db->prepare("INSERT INTO tblPublishers (Name, Slug, Kind) VALUES (?, ?, 'company')");
        $ins->bind_param('ss', $name, $slug);
        $ins->execute();
        $newId = (int)$db->insert_id;
        $ins->close();
        return $newId;
    } catch (\Throwable $e) {
        error_log('[publisherFindOrCreateByName] ' . $e->getMessage());
        return null;
    }
}
