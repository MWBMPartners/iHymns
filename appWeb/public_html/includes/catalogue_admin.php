<?php

declare(strict_types=1);

/**
 * iHymns — Catalogue ("Collection") admin CRUD shared cores (#941/#1765, API-coverage batch 4b-i A4)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/catalogues` (the web admin page — user-facing label "Collections",
 * rule #24) and the new `admin_catalogue_*` native API actions both need to
 * do the SAME things: create/update/delete a catalogue row, and add/remove a
 * song from one. This file is the ONE place each of those is written — both
 * surfaces call these functions instead of re-typing their own copy
 * (rule #22/#35). Modelled on includes/publisher_admin.php (#93).
 *
 * SCOPE (deliberate)
 * ----------------------------------------------------------------------------
 * The page's two read-only GET handlers (`song_search` typeahead,
 * `marcxml_export`) stay page-only — no API twin, nothing to extract.
 * `marcxml_import` (a file-upload flow) was ALSO out of scope at first
 * extraction, deferred by `.claude/api-coverage-2026-08-28.md` §4.3 A4/A17
 * pending a confirmed native-curator surface (§8 Q1). Q1 came back yes, and
 * API-coverage batch 6b ported it as `admin_catalogue_marcxml_import` in
 * api.php — it calls `catalogueAdminCreate()` / `catalogueAdminSlugify()` /
 * `catalogueAdminSlugTaken()` / `catalogueAdminPersistPublicationIds()` right
 * here, the SAME functions `create`/`update` already used, so the API side
 * never forked the row write. The PAGE's own `marcxml_import` POST handler
 * (`manage/catalogues.php`) is UNCHANGED — it still does its own inline
 * INSERT rather than calling this file (that pre-dates this batch and was
 * out of this batch's scope; a future pass could re-point it at
 * `catalogueAdminCreate()` too, eliminating the last duplicate INSERT).
 *
 * `tblCatalogues`/`tblCatalogueSongs`/the `/manage/catalogues` route/the
 * `admin.catalogues.*` activity-log key prefix/the `catalogue` entity-type
 * string all stay `catalogue` INTERNALLY — the Catalogue→Collection relabel
 * is UI copy only (rule #24). Never rename these identifiers.
 *
 * Two validation asymmetries preserved deliberately (byte-identical lift
 * from the pre-extraction `manage/catalogues.php`, not "fixed" here):
 * `catalogueAdminValidateCreateFields()` checks Title <= 255 chars AND
 * derives/validates a Slug; `catalogueAdminValidateUpdateFields()` does
 * NEITHER (update never touches Slug at all, and never re-checked the
 * length cap) — that is what the original page already did on both counts.
 *
 * @link appWeb/public_html/includes/publisher_admin.php   the extraction precedent this mirrors
 * @link appWeb/public_html/includes/media_identifiers.php the ONE publication-identifier validator (ark/OpenLibrary)
 * @link appWeb/public_html/includes/song_soft_delete.php  songVisibleSql() — #1694 hidden-song filter
 * @link appWeb/public_html/manage/catalogues.php          page consumer
 * @link appWeb/public_html/api.php                        admin_catalogue_* API consumer
 * @see #941 #1765 #1863 rule #24
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_identifiers.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'places.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ilyrics_id.php';

/** URL-safe lowercase slug from free text; non-ASCII dropped (routing key,
 * not a display field). Identical to the pre-extraction page-local $slugFor. */
function catalogueAdminSlugify(string $name): string
{
    $ascii = (string)preg_replace('/[^A-Za-z0-9]+/u', '-', $name);
    return trim(strtolower($ascii), '-');
}

/** True once tblCatalogues exists — the page's own pre-migration-safe probe,
 * extracted so the API action gates on the identical check (503, not a
 * mysqli STRICT throw, on a long-running un-migrated install). */
function catalogueAdminTableExists(\mysqli $db): bool
{
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblCatalogues' LIMIT 1"
        );
        $probe->execute();
        $exists = $probe->get_result()->fetch_row() !== null;
        $probe->close();
        return $exists;
    } catch (\Throwable $e) {
        error_log('[catalogueAdminTableExists] probe failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * True once the #1765 Feature 3 publication-identifier columns
 * (ArkId/OpenLibraryWorkId/OpenLibraryEditionId — Collections carry no
 * ISBN/ISSN, that's a series/songbook concern) exist on tblCatalogues.
 */
function catalogueAdminPubIdColumnsReady(\mysqli $db): bool
{
    return placeColumnExists($db, 'tblCatalogues', 'ArkId')
        && placeColumnExists($db, 'tblCatalogues', 'OpenLibraryWorkId')
        && placeColumnExists($db, 'tblCatalogues', 'OpenLibraryEditionId');
}

/**
 * Validate ark_id/openlibrary_work_id/openlibrary_edition_id via the ONE
 * shared validator (mediaIdentifierPublicationClean(), rule #22) — the
 * IDENTICAL three-field block `create`/`update`/`marcxml_import` each had
 * inline, extracted once here since `create` and `update` literally
 * duplicated it verbatim.
 *
 * @param array<string,mixed> $in
 * @return array{0: array{ark:?string,olWork:?string,olEdition:?string}, 1: ?string} [$fields, $error]
 */
function catalogueAdminValidatePublicationIds(array $in): array
{
    $arkClean = mediaIdentifierPublicationClean('ark', (string)($in['ark_id'] ?? ''));
    if ($arkClean['error'] !== null) { return [[], $arkClean['error']]; }
    $olWorkClean = mediaIdentifierPublicationClean('openlibrary-work', (string)($in['openlibrary_work_id'] ?? ''));
    if ($olWorkClean['error'] !== null) { return [[], $olWorkClean['error']]; }
    $olEditionClean = mediaIdentifierPublicationClean('openlibrary-edition', (string)($in['openlibrary_edition_id'] ?? ''));
    if ($olEditionClean['error'] !== null) { return [[], $olEditionClean['error']]; }
    return [[
        'ark'       => $arkClean['value'],
        'olWork'    => $olWorkClean['value'],
        'olEdition' => $olEditionClean['value'],
    ], null];
}

/**
 * Validate the CREATE-only scalar fields (title/slug/description/
 * visibility/sortOrder/colour) — same order, same messages, same 255-char
 * Title/Slug caps the pre-extraction `add` case used.
 *
 * @param array<string,mixed> $in
 * @return array{0: array{title:string,slug:string,description:?string,visibility:string,sortOrder:int,colour:string}, 1: ?string} [$fields, $error]
 */
function catalogueAdminValidateCreateFields(array $in): array
{
    $title       = trim((string)($in['title']       ?? ''));
    $slugRaw     = trim((string)($in['slug']        ?? ''));
    $description = trim((string)($in['description'] ?? '')) ?: null;
    $visibility  = trim((string)($in['visibility']  ?? 'public'));
    $sortOrder   = (int)($in['sort_order'] ?? 0);

    if ($title === '')           { return [[], 'Title is required.']; }
    if (mb_strlen($title) > 255) { return [[], 'Title must be 255 characters or fewer.']; }
    if (!in_array($visibility, ['public', 'curated', 'admin_only'], true)) {
        return [[], 'Invalid visibility.'];
    }

    $slug = $slugRaw !== '' ? catalogueAdminSlugify($slugRaw) : catalogueAdminSlugify($title);
    if ($slug === '')           { return [[], 'Slug could not be derived — provide one explicitly.']; }
    if (mb_strlen($slug) > 255) { return [[], 'Slug must be 255 characters or fewer.']; }

    $colour = strtoupper(trim((string)($in['colour'] ?? '')));
    if ($colour !== '' && !preg_match('/^#[0-9A-F]{6}$/', $colour)) {
        return [[], 'Colour must be a #RRGGBB hex value or left blank.'];
    }

    return [[
        'title' => $title, 'slug' => $slug, 'description' => $description,
        'visibility' => $visibility, 'sortOrder' => $sortOrder, 'colour' => $colour,
    ], null];
}

/**
 * Validate the UPDATE-only scalar fields. Deliberately does NOT touch Slug
 * (the page never let an update change it) and deliberately does NOT
 * re-check the 255-char Title cap (the pre-extraction `update` case never
 * did either) — see this file's doc-block.
 *
 * @param array<string,mixed> $in
 * @return array{0: array{title:string,description:?string,visibility:string,sortOrder:int,colour:string}, 1: ?string} [$fields, $error]
 */
function catalogueAdminValidateUpdateFields(array $in): array
{
    $title       = trim((string)($in['title']       ?? ''));
    $description = trim((string)($in['description'] ?? '')) ?: null;
    $visibility  = trim((string)($in['visibility']  ?? 'public'));
    $sortOrder   = (int)($in['sort_order'] ?? 0);

    if ($title === '') { return [[], 'Title is required.']; }
    if (!in_array($visibility, ['public', 'curated', 'admin_only'], true)) {
        return [[], 'Invalid visibility.'];
    }

    $colour = strtoupper(trim((string)($in['colour'] ?? '')));
    if ($colour !== '' && !preg_match('/^#[0-9A-F]{6}$/', $colour)) {
        return [[], 'Colour must be a #RRGGBB hex value or left blank.'];
    }

    return [[
        'title' => $title, 'description' => $description,
        'visibility' => $visibility, 'sortOrder' => $sortOrder, 'colour' => $colour,
    ], null];
}

/** True when $slug is already taken by another catalogue row. */
function catalogueAdminSlugTaken(\mysqli $db, string $slug): bool
{
    $stmt = $db->prepare('SELECT Id FROM tblCatalogues WHERE Slug = ?');
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/**
 * Insert a new catalogue row + mint its permanent IL-id (ILC…, #1860
 * go-live). Publication-identifier columns are NOT written here — call
 * catalogueAdminPersistPublicationIds() separately when
 * catalogueAdminPubIdColumnsReady() is true (mirrors the page's own
 * schema-tolerant secondary UPDATE).
 *
 * @param array{title:string,slug:string,description:?string,visibility:string,sortOrder:int,colour:string} $fields
 * @return int the new tblCatalogues.Id.
 */
function catalogueAdminCreate(\mysqli $db, array $fields): int
{
    $stmt = $db->prepare(
        'INSERT INTO tblCatalogues (Slug, Title, Description, SortOrder, Visibility, Colour)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sssiss',
        $fields['slug'], $fields['title'], $fields['description'],
        $fields['sortOrder'], $fields['visibility'], $fields['colour']
    );
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    ilidStampNewRow($db, 'catalogue', $newId);
    return $newId;
}

/** Write an existing catalogue row's scalar fields (never Slug — see
 * catalogueAdminValidateUpdateFields()'s doc-block). */
function catalogueAdminUpdate(\mysqli $db, int $id, array $fields): void
{
    $stmt = $db->prepare(
        'UPDATE tblCatalogues
            SET Title = ?, Description = ?, SortOrder = ?, Visibility = ?, Colour = ?
          WHERE Id = ?'
    );
    $stmt->bind_param(
        'ssissi',
        $fields['title'], $fields['description'], $fields['sortOrder'],
        $fields['visibility'], $fields['colour'], $id
    );
    $stmt->execute();
    $stmt->close();
}

/** Schema-tolerant secondary UPDATE for the ArkId/OpenLibrary* columns,
 * shared by create + update — called only when
 * catalogueAdminPubIdColumnsReady() is true. */
function catalogueAdminPersistPublicationIds(\mysqli $db, int $id, array $pubIds): void
{
    $stmt = $db->prepare(
        'UPDATE tblCatalogues SET ArkId = ?, OpenLibraryWorkId = ?, OpenLibraryEditionId = ? WHERE Id = ?'
    );
    $stmt->bind_param('sssi', $pubIds['ark'], $pubIds['olWork'], $pubIds['olEdition'], $id);
    $stmt->execute();
    $stmt->close();
}

/** One catalogue's Title by id — the delete action's existence check +
 * audit-log/success-message name. @return string|null null when no such row. */
function catalogueAdminFetchTitle(\mysqli $db, int $id): ?string
{
    $stmt = $db->prepare('SELECT Title FROM tblCatalogues WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['Title'] : null;
}

/** Delete a catalogue row. tblCatalogueSongs cascades via its FK
 * (ON DELETE CASCADE) — the underlying songs themselves are untouched. */
function catalogueAdminDelete(\mysqli $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM tblCatalogues WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Verify $songId names a real, currently-visible tblSongs row (#1694 —
 * hidden/soft-deleted songs are neither offered by the search picker NOR
 * accepted here even via a forged id; #1765 @disabled-visible — a song from
 * a DISABLED songbook stays addable, matching the songbook_search /
 * song_search pickers' own posture on /manage surfaces).
 *
 * @return string|null the song's Title, or null when not found/not visible.
 */
function catalogueAdminFindVisibleSongTitle(\mysqli $db, string $songId): ?string
{
    /* @disabled-visible: admin surface (#1765) — disabled songbooks stay
       fully visible/editable in /manage, so a curator can still add a song
       from a disabled book into a Collection; this existence check
       deliberately filters ONLY on songVisibleSql() (#1694 soft-delete),
       never songServableSql() (#1765 disabled-book), matching the page's
       own pre-extraction posture verbatim. */
    $stmt = $db->prepare(
        'SELECT Title FROM tblSongs WHERE SongId = ? AND ' . songVisibleSql($db, '')
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['Title'] : null;
}

/**
 * Add a song to a catalogue (INSERT IGNORE — idempotent on a re-add).
 * Caller MUST have already resolved the song's Title via
 * catalogueAdminFindVisibleSongTitle() (the 404-equivalent "not found or
 * not visible" case is decided by the caller, matching the page's own
 * two-step shape).
 *
 * @return bool true when a new membership row was actually inserted (false
 *   means "was already in the catalogue" — not an error).
 */
function catalogueAdminAddMember(\mysqli $db, int $catalogueId, string $songId, ?int $userId): bool
{
    $stmt = $db->prepare(
        'INSERT IGNORE INTO tblCatalogueSongs (CatalogueId, SongId, AddedBy, AddedAt) VALUES (?, ?, ?, NOW())'
    );
    $stmt->bind_param('isi', $catalogueId, $songId, $userId);
    $stmt->execute();
    $added = $stmt->affected_rows > 0;
    $stmt->close();
    return $added;
}

/** Remove a song from a catalogue. @return bool true when a row was
 * actually removed (false means "wasn't a member" — not an error). */
function catalogueAdminRemoveMember(\mysqli $db, int $catalogueId, string $songId): bool
{
    $stmt = $db->prepare('DELETE FROM tblCatalogueSongs WHERE CatalogueId = ? AND SongId = ?');
    $stmt->bind_param('is', $catalogueId, $songId);
    $stmt->execute();
    $removed = $stmt->affected_rows > 0;
    $stmt->close();
    return $removed;
}
