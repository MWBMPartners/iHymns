<?php

declare(strict_types=1);

/**
 * iHymns — Songbook Series admin CRUD shared cores (#782/#1765, API-coverage batch 4b-i A5)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/songbook-series` (the web admin page) and the new
 * `admin_songbook_series_*` native API actions both need to do the SAME
 * things: create/update/delete a series row and reconcile its member
 * songbook list. This file is the ONE place each of those is written — both
 * surfaces call these functions instead of re-typing their own copy
 * (rule #22/#35). Modelled on includes/publisher_admin.php (#93).
 *
 * SCOPE (deliberate)
 * ----------------------------------------------------------------------------
 * The page's two read-only GET handlers (`songbook_search` typeahead,
 * `marcxml_export`) stay page-only — no API twin, nothing to extract.
 * `marcxml_import` (a file-upload flow) was ALSO out of scope at first
 * extraction, deferred by `.claude/api-coverage-2026-08-28.md` §4.3 A5/A17
 * pending a confirmed native-curator surface (§8 Q1). Q1 came back yes, and
 * API-coverage batch 6b ported it as `admin_songbook_series_marcxml_import`
 * in api.php — it calls `songbookSeriesAdminCreate()` /
 * `songbookSeriesAdminSlugify()` / `songbookSeriesAdminSlugTaken()` /
 * `songbookSeriesAdminPersistPublicationIds()` right here, the SAME
 * functions `create`/`update` already used, so the API side never forked
 * the row write. The PAGE's own `marcxml_import` POST handler
 * (`manage/songbook-series.php`) is UNCHANGED — it still does its own
 * inline INSERT rather than calling this file (that pre-dates this batch
 * and was out of this batch's scope; a future pass could re-point it at
 * `songbookSeriesAdminCreate()` too, eliminating the last duplicate
 * INSERT).
 *
 * `create` and `update` validated an IDENTICAL name/slug/description/
 * colour block (only `update` additionally required + pre-checked `id`
 * first) — `songbookSeriesAdminValidateCoreFields()` below is that ONE
 * shared block, lifted verbatim; the `id` check itself stays in each
 * caller so the exact original error ORDER (id, then name, then slug) is
 * preserved byte-for-byte.
 *
 * @link appWeb/public_html/includes/publisher_admin.php   the extraction precedent this mirrors
 * @link appWeb/public_html/includes/media_identifiers.php the ONE publication-identifier validator (isbn/issn/ark/OpenLibrary)
 * @link appWeb/public_html/manage/songbook-series.php     page consumer
 * @link appWeb/public_html/api.php                        admin_songbook_series_* API consumer
 * @see #782 #1765
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_identifiers.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'places.php';

/** URL-safe lowercase slug from free text; non-ASCII dropped (routing key,
 * not a display field). Identical to the pre-extraction page-local $slugFor. */
function songbookSeriesAdminSlugify(string $name): string
{
    $ascii = (string)preg_replace('/[^A-Za-z0-9]+/u', '-', $name);
    return trim(strtolower($ascii), '-');
}

/** True once tblSongbookSeries exists — the page's own pre-migration-safe
 * probe, extracted so the API action gates on the identical check. */
function songbookSeriesAdminTableExists(\mysqli $db): bool
{
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongbookSeries' LIMIT 1"
        );
        $probe->execute();
        $exists = $probe->get_result()->fetch_row() !== null;
        $probe->close();
        return $exists;
    } catch (\Throwable $e) {
        error_log('[songbookSeriesAdminTableExists] probe failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * True once the #1765 Feature 3 publication-identifier columns
 * (Isbn/Issn/ArkId/OpenLibraryWorkId/OpenLibraryEditionId — one ALTER
 * batch) all exist on tblSongbookSeries. Combined COUNT=5 probe, same
 * "treat the whole batch as one unit" simplification the page always used.
 */
function songbookSeriesAdminPubIdColumnsReady(\mysqli $db): bool
{
    return placeColumnExists($db, 'tblSongbookSeries', 'Isbn')
        && placeColumnExists($db, 'tblSongbookSeries', 'Issn')
        && placeColumnExists($db, 'tblSongbookSeries', 'ArkId')
        && placeColumnExists($db, 'tblSongbookSeries', 'OpenLibraryWorkId')
        && placeColumnExists($db, 'tblSongbookSeries', 'OpenLibraryEditionId');
}

/**
 * Validate isbn/issn/ark_id/openlibrary_work_id/openlibrary_edition_id via
 * the ONE shared validator (mediaIdentifierPublicationClean(), rule #22) —
 * the IDENTICAL five-field block `create` and `update` each had inline.
 *
 * @param array<string,mixed> $in
 * @return array{0: array{isbn:?string,issn:?string,ark:?string,olWork:?string,olEdition:?string}, 1: ?string} [$fields, $error]
 */
function songbookSeriesAdminValidatePublicationIds(array $in): array
{
    $isbnClean = mediaIdentifierPublicationClean('isbn', (string)($in['isbn'] ?? ''));
    if ($isbnClean['error'] !== null) { return [[], $isbnClean['error']]; }
    $issnClean = mediaIdentifierPublicationClean('issn', (string)($in['issn'] ?? ''));
    if ($issnClean['error'] !== null) { return [[], $issnClean['error']]; }
    $arkClean = mediaIdentifierPublicationClean('ark', (string)($in['ark_id'] ?? ''));
    if ($arkClean['error'] !== null) { return [[], $arkClean['error']]; }
    $olWorkClean = mediaIdentifierPublicationClean('openlibrary-work', (string)($in['openlibrary_work_id'] ?? ''));
    if ($olWorkClean['error'] !== null) { return [[], $olWorkClean['error']]; }
    $olEditionClean = mediaIdentifierPublicationClean('openlibrary-edition', (string)($in['openlibrary_edition_id'] ?? ''));
    if ($olEditionClean['error'] !== null) { return [[], $olEditionClean['error']]; }
    return [[
        'isbn' => $isbnClean['value'], 'issn' => $issnClean['value'], 'ark' => $arkClean['value'],
        'olWork' => $olWorkClean['value'], 'olEdition' => $olEditionClean['value'],
    ], null];
}

/**
 * Validate + cap the name/slug/description/colour block shared VERBATIM by
 * `create` and `update` (the caller checks `id` itself first, for update —
 * see this file's doc-block on the preserved error order).
 *
 * @param array<string,mixed> $in
 * @return array{0: array{name:string,slug:string,description:string,colour:string}, 1: ?string} [$fields, $error]
 */
function songbookSeriesAdminValidateCoreFields(array $in): array
{
    $name        = trim((string)($in['name']        ?? ''));
    $description = trim((string)($in['description'] ?? ''));
    $slug        = trim((string)($in['slug']        ?? ''));

    if ($name === '') { return [[], 'Name is required.']; }
    if ($slug === '') { $slug = songbookSeriesAdminSlugify($name); }
    if ($slug === '') { return [[], 'Name has no usable slug characters — provide one explicitly.']; }

    /* Cap to schema widths (Name 120, Slug 120, Description 255). */
    $name        = mb_substr($name, 0, 120);
    $slug        = mb_substr($slug, 0, 120);
    $description = mb_substr($description, 0, 255);

    $colour = strtoupper(trim((string)($in['colour'] ?? '')));
    if ($colour !== '' && !preg_match('/^#[0-9A-F]{6}$/', $colour)) {
        return [[], 'Colour must be a #RRGGBB hex value or left blank.'];
    }

    return [['name' => $name, 'slug' => $slug, 'description' => $description, 'colour' => $colour], null];
}

/**
 * True when $slug is already taken by another series row. Pass
 * $excludeId to allow a row to keep its OWN current slug on update.
 */
function songbookSeriesAdminSlugTaken(\mysqli $db, string $slug, ?int $excludeId = null): bool
{
    if ($excludeId !== null) {
        $stmt = $db->prepare('SELECT Id FROM tblSongbookSeries WHERE Slug = ? AND Id <> ?');
        $stmt->bind_param('si', $slug, $excludeId);
    } else {
        $stmt = $db->prepare('SELECT Id FROM tblSongbookSeries WHERE Slug = ?');
        $stmt->bind_param('s', $slug);
    }
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/** One series row's Name/Slug/Description by id — update's before-diff
 * capture AND delete's existence check (which reads only ->Name). */
function songbookSeriesAdminFetch(\mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT Name, Slug, Description FROM tblSongbookSeries WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/** Insert a new series row (Colour only — publication-identifier columns
 * are written separately via songbookSeriesAdminPersistPublicationIds()). */
function songbookSeriesAdminCreate(\mysqli $db, array $fields): int
{
    $stmt = $db->prepare('INSERT INTO tblSongbookSeries (Name, Slug, Description, Colour) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $fields['name'], $fields['slug'], $fields['description'], $fields['colour']);
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    return $newId;
}

/** Write an existing series row's Name/Slug/Description/Colour. */
function songbookSeriesAdminUpdate(\mysqli $db, int $id, array $fields): void
{
    $stmt = $db->prepare('UPDATE tblSongbookSeries SET Name = ?, Slug = ?, Description = ?, Colour = ? WHERE Id = ?');
    $stmt->bind_param('ssssi', $fields['name'], $fields['slug'], $fields['description'], $fields['colour'], $id);
    $stmt->execute();
    $stmt->close();
}

/** Schema-tolerant secondary UPDATE for the five publication-identifier
 * columns, shared by create + update — called only when
 * songbookSeriesAdminPubIdColumnsReady() is true. */
function songbookSeriesAdminPersistPublicationIds(\mysqli $db, int $id, array $pubIds): void
{
    $stmt = $db->prepare(
        'UPDATE tblSongbookSeries
            SET Isbn = ?, Issn = ?, ArkId = ?, OpenLibraryWorkId = ?, OpenLibraryEditionId = ?
          WHERE Id = ?'
    );
    $stmt->bind_param('sssssi', $pubIds['isbn'], $pubIds['issn'], $pubIds['ark'], $pubIds['olWork'], $pubIds['olEdition'], $id);
    $stmt->execute();
    $stmt->close();
}

/** Delete a series row. tblSongbookSeriesMembership cascades via its FK
 * (ON DELETE CASCADE) — the member songbooks themselves are untouched. */
function songbookSeriesAdminDelete(\mysqli $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM tblSongbookSeries WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Normalise a posted membership list into a de-duplicated, positive-int id
 * list plus its sort-order map. Accepts either shape a caller might hand
 * in: PHP's own `$_POST['member_ids']`/`$_POST['member_sort']` array
 * (page) or a decoded JSON body's `member_ids`/`member_sort` (API) — both
 * arrive as plain PHP arrays by the time they reach here.
 *
 * @param mixed $rawIds
 * @param mixed $rawSort
 * @return array{0: list<int>, 1: array<int|string,mixed>} [$postedIds, $postedSort]
 */
function songbookSeriesAdminParseMemberPost($rawIds, $rawSort): array
{
    $postedIds = is_array($rawIds) ? $rawIds : [];
    $postedSrt = is_array($rawSort) ? $rawSort : [];
    $postedIds = array_values(array_unique(array_map('intval', $postedIds)));
    $postedIds = array_values(array_filter($postedIds, static fn(int $v): bool => $v > 0));
    return [$postedIds, $postedSrt];
}

/**
 * Reconcile a series' membership rows to EXACTLY $postedIds (with each
 * row's SortOrder from $postedSrt): delete anything not in the posted list,
 * then upsert every posted id (ON DUPLICATE KEY UPDATE keeps this
 * idempotent on a re-save). Caller wraps this in a transaction alongside
 * the scalar-field UPDATE (mirrors the page's own single-transaction
 * shape).
 *
 * @param list<int> $postedIds
 * @param array<int|string,mixed> $postedSrt
 */
function songbookSeriesAdminReplaceMembership(\mysqli $db, int $seriesId, array $postedIds, array $postedSrt): void
{
    if ($postedIds) {
        $ph = implode(',', array_fill(0, count($postedIds), '?'));
        $sql = "DELETE FROM tblSongbookSeriesMembership WHERE SeriesId = ? AND SongbookId NOT IN ($ph)";
        $stmt = $db->prepare($sql);
        $types = 'i' . str_repeat('i', count($postedIds));
        $args  = array_merge([$seriesId], $postedIds);
        $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $db->prepare('DELETE FROM tblSongbookSeriesMembership WHERE SeriesId = ?');
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $stmt->close();
    }

    if ($postedIds) {
        $stmt = $db->prepare(
            'INSERT INTO tblSongbookSeriesMembership (SeriesId, SongbookId, SortOrder)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE SortOrder = VALUES(SortOrder)'
        );
        foreach ($postedIds as $sbId) {
            $sortOrder = isset($postedSrt[$sbId])
                ? max(0, min(32767, (int)$postedSrt[$sbId]))
                : 0;
            $stmt->bind_param('iii', $seriesId, $sbId, $sortOrder);
            $stmt->execute();
        }
        $stmt->close();
    }
}

/**
 * Read a series' CURRENT member list back (rule #35 — the response
 * reflects what was actually stored, not what was posted). Not used by the
 * page today (its own list query batches ALL series' members in one go for
 * the table render, a different concern) — this is the API's per-series
 * read-back after create/update.
 *
 * @return list<array{songbook_id:int,sort_order:int,abbreviation:string,name:string}>
 */
function songbookSeriesAdminMembers(\mysqli $db, int $seriesId): array
{
    /* @disabled-visible: admin surface (#1765) — mirrors the page's own
       (unfiltered) bulk membership read: disabled songbooks stay fully
       visible/editable in /manage, so a curator must still be able to see
       (and re-order/remove) a disabled book's membership in a series. */
    $stmt = $db->prepare(
        'SELECT m.SongbookId, m.SortOrder, b.Abbreviation, b.Name
           FROM tblSongbookSeriesMembership m
           JOIN tblSongbooks b ON b.Id = m.SongbookId
          WHERE m.SeriesId = ?
          ORDER BY m.SortOrder ASC, b.Name ASC'
    );
    $stmt->bind_param('i', $seriesId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static fn(array $r): array => [
        'songbook_id'  => (int)$r['SongbookId'],
        'sort_order'   => (int)$r['SortOrder'],
        'abbreviation' => (string)$r['Abbreviation'],
        'name'         => (string)$r['Name'],
    ], $rows);
}
