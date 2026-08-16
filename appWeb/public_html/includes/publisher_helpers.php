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

/**
 * The ONE publisher typeahead query core (#1864, rule #22).
 *
 * ELI5
 * ----
 * Every page with a "search for a publisher" box (Publishers admin, the
 * Songbooks editor, and now the Works Copyright Holder picker) used to run
 * its own near-identical copy of this SQL. This is the one copy; every page
 * calls it instead of writing its own.
 *
 * DETAILED
 * --------
 * Extracted verbatim from the pre-existing superset fork on
 * `manage/publishers.php` (`Name LIKE` + optional `Id <> ?` exclude, both
 * bound, `ORDER BY Name ASC LIMIT ?`) — `manage/songbooks.php`'s fork had the
 * same shape minus the exclude arm. All three pages' `?action=publisher_search`
 * handlers now delegate here (rule #22 — one core, not a third copy).
 *
 * Pre-migration safe: an absent `tblPublishers` degrades to `[]`, never a
 * thrown exception — a caller with no schema-existence gate of its own (the
 * new works.php handler, §1.3 of the #1864 spec) can call this directly. A
 * genuine SQL failure once the table DOES exist still throws under STRICT
 * mysqli (rule #9), so each caller's own `try/catch` -> HTTP 500 is
 * unchanged.
 *
 * @param int      $limit     clamped to 1..50 inside this function — a
 *                             caller doesn't need to re-clamp.
 * @param int|null $excludeId when set (and > 0), excludes that Id — used by
 *                             the parent-picker / merge-target searches so a
 *                             publisher can't pick itself.
 * @return array<int, array{id:int, name:string, slug:string, kind:string}>
 *
 * @link appWeb/public_html/manage/publishers.php the pre-existing superset fork this replaces
 * @link appWeb/public_html/manage/songbooks.php   the pre-existing fork this replaces
 * @see #1864
 */
function publisherSearchRows(\mysqli $db, string $q, int $limit, ?int $excludeId = null): array
{
    if (!publisherTableExists($db)) { return []; }

    $limit = max(1, min(50, $limit));
    $q     = trim($q);

    $sql    = 'SELECT Id, Name, Slug, Kind FROM tblPublishers WHERE IsActive = 1';
    $types  = '';
    $params = [];
    if ($q !== '') {
        $sql      .= ' AND Name LIKE ?';
        $types    .= 's';
        $params[]  = '%' . $q . '%';
    }
    if ($excludeId !== null && $excludeId > 0) {
        $sql      .= ' AND Id <> ?';
        $types    .= 'i';
        $params[]  = $excludeId;
    }
    $sql      .= ' ORDER BY Name ASC LIMIT ?';
    $types    .= 'i';
    $params[]  = $limit;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'id'   => (int)$row['Id'],
            'name' => (string)$row['Name'],
            'slug' => (string)$row['Slug'],
            'kind' => (string)$row['Kind'],
        ];
    }
    $stmt->close();
    return $out;
}

/**
 * Resolve a curator-typed copyright-holder name (+ an optional
 * picker-claimed `tblPublishers.Id`) to a registry Id (#1864, rule #43/#37).
 *
 * ELI5
 * ----
 * The Copyright Holder box on a Work is really "point at a publisher, or
 * make one if it doesn't exist yet". If the curator picked a suggestion
 * from the dropdown we already know exactly which registry row they meant
 * — this trusts that pick (after a quick double-check). If they only typed
 * free text, this falls back to the same find-or-create funnel every other
 * publisher-writing field in the app uses.
 *
 * DETAILED — TRUST-BUT-VERIFY
 * ----------------------------
 * Why the claimed id matters: `tblPublishers` deliberately has NO `uq_Name`
 * (rule #37 — same-named publishers coexist under different
 * Disambiguation), so a name-only resolve would `LIMIT 1` an arbitrary row
 * whenever two publishers share a name. An explicit picker choice must win
 * over that ambiguity.
 *
 * The claimed id is honoured ONLY when the row exists AND its Name equals
 * the typed string (collation-CI, one bound
 * `WHERE Id = ? AND Name = ?` SELECT) — a stale or forged id degrades to
 * the name funnel, never to a silently-wrong link. In practice a mismatch
 * is the exception, not the rule: `place-search.js`'s `pickMode:'value'`
 * clears the hidden id the moment the curator free-types over a picked
 * suggestion (`onInput()`), so the id and the name only travel together
 * when nothing was edited after the pick.
 *
 *   '' name             => null (no holder set — CopyrightHolderId cleared)
 *   verified claimed id => that id (NO create — the row already exists)
 *   otherwise            => publisherFindOrCreateByName($db, $name), which
 *                            is itself null-safe pre-migration
 *
 * @param int|null $claimedId the hidden `copyright_holder_id` POST value, or
 *                             null/`<= 0` for "nothing was picked".
 * @return int|null the resolved tblPublishers.Id, or null when there is no
 *                    holder to set (empty name) or tblPublishers is absent.
 *
 * @see publisherFindOrCreateByName() the shared create funnel this delegates to
 * @see #1864
 */
function publisherResolvePickedOrCreate(\mysqli $db, string $name, ?int $claimedId): ?int
{
    $name = mb_substr(trim($name), 0, 255);
    if ($name === '') { return null; }

    $claimedId = ($claimedId !== null && $claimedId > 0) ? $claimedId : null;

    if ($claimedId !== null) {
        try {
            if (publisherTableExists($db)) {
                $stmt = $db->prepare('SELECT Id FROM tblPublishers WHERE Id = ? AND Name = ? LIMIT 1');
                $stmt->bind_param('is', $claimedId, $name);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    return (int)$row['Id'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[publisherResolvePickedOrCreate] verify failed: ' . $e->getMessage());
            /* Fall through to the name funnel below — a verify failure must
               never block the save, only fall back to the safe path. */
        }
    }

    return publisherFindOrCreateByName($db, $name);
}
