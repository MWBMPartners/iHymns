<?php

declare(strict_types=1);

/**
 * iHymns — Publisher admin CRUD shared cores (#93 / epic #1765)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/publishers` (the web admin page) and the `admin_publisher_*` native
 * API actions both need to do the SAME things: check a publisher's fields, save
 * them, replace its alias list, count its usage, delete one, and merge two
 * together. This file is the ONE place each of those is written — both surfaces
 * call these functions instead of re-typing their own copy (rule #22 / #35).
 * Modelled line-for-line on includes/tune_admin.php (#1748).
 *
 * DETAILED / THE DENORM MIRROR
 * ----------------------------------------------------------------------------
 * tblSongbooks.Publisher stays as a free-text JOIN-free display denorm (#93 —
 * same posture as tblSongs.TuneName vs tblTunes). So a publisher RENAME and a
 * publisher MERGE both cascade into that denorm the same way the tune equivalents
 * cascade into TuneName: a rename updates the denorm on the books LINKED to this
 * publisher whose denorm still shows the old name; a merge rewrites every book
 * whose denorm shows the source name to the target name.
 *
 * Every function is pure-PHP-plus-\mysqli (no superglobal reads) so a form-POST
 * caller and a JSON-body caller normalise into the same array shape first.
 *
 * @link appWeb/public_html/includes/tune_admin.php     the precedent this mirrors
 * @link appWeb/public_html/includes/publisher_helpers.php  vocab + slug + find-or-create
 * @link appWeb/public_html/manage/publishers.php       page consumer
 * @see #93
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'publisher_helpers.php';

/**
 * True when $table.$column exists in the current database (INFORMATION_SCHEMA).
 * mysqli STRICT throws on an unknown column and migrations are web-run, so every
 * optional read gates on this first (rule #9).
 *
 * @link https://www.php.net/manual/en/mysqli.report.php
 */
function publisherAdminColumnExists(\mysqli $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $exists;
    } catch (\Throwable $e) {
        error_log('[publisherAdminColumnExists] probe failed for ' . $table . '.' . $column . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * One request-scoped probe of every optional table/column the publisher admin
 * touches. Each caller (page / API) calls this once and passes the result into
 * the functions below, so the two surfaces can't diverge on the gate shape.
 *
 * @return array{hasPublishers:bool,hasSongbookPublishers:bool,hasAliases:bool,hasLinks:bool,hasMusicians:bool,hasPlaces:bool,hasSongbookPublisherCol:bool}
 */
function publisherAdminProbeGates(\mysqli $db): array
{
    $gates = [
        'hasPublishers'          => false,
        'hasSongbookPublishers'  => false,
        'hasAliases'             => false,
        'hasLinks'               => false,
        'hasMusicians'           => false,
        'hasPlaces'              => false,
        'hasSongbookPublisherCol'=> false,   /* tblSongbooks.Publisher denorm column */
    ];
    try {
        $tableNames = ['tblPublishers', 'tblSongbookPublishers', 'tblPublisherAliases', 'tblPublisherExternalLinks', 'tblMusicians', 'tblPlaces'];
        $ph    = implode(',', array_fill(0, count($tableNames), '?'));
        $stmt  = $db->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($ph)"
        );
        $types = str_repeat('s', count($tableNames));
        $stmt->bind_param($types, ...$tableNames);
        $stmt->execute();
        $res = $stmt->get_result();
        $found = [];
        while ($row = $res->fetch_assoc()) { $found[(string)$row['TABLE_NAME']] = true; }
        $stmt->close();
        $gates['hasPublishers']         = isset($found['tblPublishers']);
        $gates['hasSongbookPublishers'] = isset($found['tblSongbookPublishers']);
        $gates['hasAliases']            = isset($found['tblPublisherAliases']);
        $gates['hasLinks']              = isset($found['tblPublisherExternalLinks']);
        $gates['hasMusicians']          = isset($found['tblMusicians']);
        $gates['hasPlaces']             = isset($found['tblPlaces']);
    } catch (\Throwable $e) {
        error_log('[publisherAdminProbeGates] table probe failed: ' . $e->getMessage());
    }
    $gates['hasSongbookPublisherCol'] = publisherAdminColumnExists($db, 'tblSongbooks', 'Publisher');
    return $gates;
}

/**
 * Validate a publisher's scalar fields. Never touches the DB. Same field-array
 * shape for create AND update (mirrors tuneAdminValidateFields()).
 *
 *   - `name` required, ≤255.
 *   - `slug` optional; when supplied must be /^[a-z0-9-]{1,120}$/ (persist
 *     resolves the actual unique slug via publisherSlugEnsureUnique()).
 *   - `kind` must be a key of IHYMNS_PUBLISHER_KINDS, defaults 'company'.
 *   - `subtitle`/`disambiguation` ≤255; `notes` ≤65000.
 *   - `ipi`/`isni` ≤20 length-check only (no documented per-value shape here;
 *     the BOWI precedent of a null validator).
 *   - `city_name` ≤255.
 *   - `musician_id`/`parent_id`/`city_id` are FK ints resolved+existence-checked
 *     in the persist step (0/'' → NULL); they are passed through here untouched.
 *   - `is_active` coerced to 0/1.
 *
 * @param array<string,mixed> $in
 * @return array{0: array<string,mixed>, 1: ?string} [$fields, $error]
 */
function publisherAdminValidateFields(array $in): array
{
    $name           = mb_substr(trim((string)($in['name'] ?? '')), 0, 255);
    $slugIn         = trim((string)($in['slug'] ?? ''));
    $kind           = trim((string)($in['kind'] ?? 'company'));
    $subtitle       = mb_substr(trim((string)($in['subtitle'] ?? '')), 0, 255);
    $disambiguation = mb_substr(trim((string)($in['disambiguation'] ?? '')), 0, 255);
    $ipi            = mb_substr(trim((string)($in['ipi'] ?? '')), 0, 20);
    $isni           = mb_substr(trim((string)($in['isni'] ?? '')), 0, 20);
    $cityName       = mb_substr(trim((string)($in['city_name'] ?? '')), 0, 255);
    $notes          = mb_substr(trim((string)($in['notes'] ?? '')), 0, 65000);

    if ($name === '') {
        return [[], 'Name is required.'];
    }
    if ($slugIn !== '' && !preg_match('/^[a-z0-9-]{1,120}$/', $slugIn)) {
        return [[], 'Slug must be lowercase letters, digits and hyphens only.'];
    }
    if (!array_key_exists($kind, IHYMNS_PUBLISHER_KINDS)) {
        return [[], 'Kind is not a recognised value.'];
    }

    return [[
        'name'           => $name,
        'slug'           => $slugIn,
        'kind'           => $kind,
        'subtitle'       => $subtitle,
        'disambiguation' => $disambiguation,
        'ipi'            => $ipi,
        'isni'           => $isni,
        'cityName'       => $cityName,
        'notes'          => $notes,
        /* FK candidates — resolved in persist. Pass raw ints through. */
        'musicianId'     => (int)($in['musician_id'] ?? 0),
        'parentId'       => (int)($in['parent_id'] ?? 0),
        'cityId'         => (int)($in['city_id'] ?? 0),
        'isActive'       => !empty($in['is_active']) ? 1 : 0,
    ], null];
}

/**
 * Ipi / Isni uniqueness (the tune-MBID pattern narrowed to publishers' two
 * NULL-distinct UNIQUE-keyed identity columns). Slug is deliberately excluded —
 * a colliding slug is auto-suffixed by publisherSlugEnsureUnique(), never
 * rejected. Name is NOT checked — two publishers may share a name under
 * different Disambiguation (there is no uq_Name).
 *
 * @return string|null user-facing error, or null when clear.
 */
function publisherAdminCheckUniqueness(\mysqli $db, array $fields, ?int $excludeId): ?string
{
    $checks = [
        ['col' => 'Ipi',  'val' => (string)$fields['ipi'],  'label' => 'IPI'],
        ['col' => 'Isni', 'val' => (string)$fields['isni'], 'label' => 'ISNI'],
    ];
    foreach ($checks as $check) {
        $col = $check['col'];
        $val = $check['val'];
        if ($val === '') continue;
        if ($excludeId !== null) {
            $stmt = $db->prepare("SELECT Id FROM tblPublishers WHERE {$col} = ? AND Id <> ?");
            $stmt->bind_param('si', $val, $excludeId);
        } else {
            $stmt = $db->prepare("SELECT Id FROM tblPublishers WHERE {$col} = ?");
            $stmt->bind_param('s', $val);
        }
        $stmt->execute();
        $used = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if ($used) {
            return "{$check['label']} '{$val}' is already on another publisher.";
        }
    }
    return null;
}

/**
 * Would setting $publisherId's parent to $proposedParentId create a cycle
 * (i.e. is $publisherId itself an ancestor of $proposedParentId, or are they the
 * same)? Bounded ancestor walk up the ParentId chain (mirrors the songbooks
 * parent cycle guard). A depth cap of 64 is a belt-and-braces stop against a
 * pre-existing cycle in the data.
 */
function publisherAdminWouldCycle(\mysqli $db, int $publisherId, int $proposedParentId): bool
{
    if ($proposedParentId === $publisherId) { return true; }
    $stmt = $db->prepare('SELECT ParentId FROM tblPublishers WHERE Id = ?');
    $cursor = $proposedParentId;
    $depth  = 0;
    while ($cursor > 0 && $depth < 64) {
        $stmt->bind_param('i', $cursor);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || $row['ParentId'] === null) { break; }
        $cursor = (int)$row['ParentId'];
        if ($cursor === $publisherId) { $stmt->close(); return true; }
        $depth++;
    }
    $stmt->close();
    return false;
}

/**
 * True when a positive id exists in $table.Id (bound existence check). Used to
 * null out an FK candidate (musician / parent / city) that doesn't resolve.
 */
function publisherAdminIdExists(\mysqli $db, string $table, int $id): bool
{
    if ($id <= 0) return false;
    /* $table is a hardcoded caller constant (never user input) — see call sites. */
    $stmt = $db->prepare("SELECT 1 FROM {$table} WHERE Id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ok;
}

/**
 * Write a publisher's scalar + FK fields in ONE UPDATE. Used for BOTH create
 * (after publisherAdminCreate() has inserted a bare row) and update. Resolves a
 * unique Slug via publisherSlugEnsureUnique() (rule #22 — one funnel), and
 * null-checks each FK candidate (MusicianId / ParentId / CityId) so a stale or
 * cycle-forming id is stored as NULL rather than throwing or corrupting the tree.
 *
 * @param array{hasMusicians:bool,hasPlaces:bool,...} $gates
 */
function publisherAdminPersistFields(\mysqli $db, int $id, array $fields, array $gates): void
{
    $slugBase = $fields['slug'] !== '' ? $fields['slug'] : ihymns_publisher_slugify($fields['name']);
    $slug     = publisherSlugEnsureUnique($db, $slugBase, $id);

    $subtitle = $fields['subtitle'] !== '' ? $fields['subtitle'] : null;
    $ipi      = $fields['ipi']  !== '' ? $fields['ipi']  : null;
    $isni     = $fields['isni'] !== '' ? $fields['isni'] : null;
    $cityName = $fields['cityName'] !== '' ? $fields['cityName'] : null;
    $notes    = $fields['notes'] !== '' ? $fields['notes'] : null;

    /* FK resolution — each candidate becomes NULL unless it exists (and, for
       ParentId, unless it would create a cycle). */
    $musicianId = null;
    if (!empty($gates['hasMusicians']) && publisherAdminIdExists($db, 'tblMusicians', (int)$fields['musicianId'])) {
        $musicianId = (int)$fields['musicianId'];
    }
    $cityId = null;
    if (!empty($gates['hasPlaces']) && publisherAdminIdExists($db, 'tblPlaces', (int)$fields['cityId'])) {
        $cityId = (int)$fields['cityId'];
    }
    $parentId = null;
    $parentCand = (int)$fields['parentId'];
    if ($parentCand > 0
        && publisherAdminIdExists($db, 'tblPublishers', $parentCand)
        && !publisherAdminWouldCycle($db, $id, $parentCand)) {
        $parentId = $parentCand;
    }

    $stmt = $db->prepare(
        'UPDATE tblPublishers
            SET Name = ?, Slug = ?, Kind = ?, Subtitle = ?, Disambiguation = ?,
                Ipi = ?, Isni = ?, CityName = ?, CityId = ?, MusicianId = ?,
                ParentId = ?, Notes = ?, IsActive = ?
          WHERE Id = ?'
    );
    $stmt->bind_param(
        'ssssssssiiisii',
        $fields['name'], $slug, $fields['kind'], $subtitle, $fields['disambiguation'],
        $ipi, $isni, $cityName, $cityId, $musicianId,
        $parentId, $notes, $fields['isActive'], $id
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * Create a brand-new publisher row (bare INSERT + the scalar-fields UPDATE).
 * Both the page's `create` action and the API's `admin_publisher_add` call this.
 *
 * @return int the new tblPublishers.Id.
 */
function publisherAdminCreate(\mysqli $db, array $fields, array $gates): int
{
    $slugBase = $fields['slug'] !== '' ? $fields['slug'] : ihymns_publisher_slugify($fields['name']);
    $slug     = publisherSlugEnsureUnique($db, $slugBase);
    $ins = $db->prepare("INSERT INTO tblPublishers (Name, Slug, Kind) VALUES (?, ?, ?)");
    $ins->bind_param('sss', $fields['name'], $slug, $fields['kind']);
    $ins->execute();
    $newId = (int)$db->insert_id;
    $ins->close();
    publisherAdminPersistFields($db, $newId, $fields, $gates);
    return $newId;
}

/**
 * Denorm rename cascade: when a publisher's Name changes, update the free-text
 * tblSongbooks.Publisher mirror on the books LINKED to this publisher whose
 * denorm still shows the old name. Scoped to linked books (not every book that
 * happens to share the old free-text string) so renaming one same-named
 * publisher never rewrites another's books.
 *
 * @param array{hasSongbookPublishers:bool,hasSongbookPublisherCol:bool,...} $gates
 */
function publisherAdminRenameCascade(\mysqli $db, int $id, string $oldName, string $newName, array $gates): void
{
    if ($oldName === $newName) return;
    if (empty($gates['hasSongbookPublishers']) || empty($gates['hasSongbookPublisherCol'])) return;

    $stmt = $db->prepare(
        'UPDATE tblSongbooks b
           JOIN tblSongbookPublishers sp ON sp.SongbookId = b.Id AND sp.PublisherId = ?
            SET b.Publisher = ?
          WHERE b.Publisher = ?'
    );
    $stmt->bind_param('iss', $id, $newName, $oldName);
    $stmt->execute();
    $stmt->close();
}

/**
 * Delete-then-insert a publisher's alias list (mirrors tuneAdminReplaceAliases).
 * Trims, caps 255, drops empties, drops case-folded dupes, drops any alias equal
 * to the publisher's own Name.
 *
 * @param list<string> $aliasNames
 */
function publisherAdminReplaceAliases(\mysqli $db, int $id, array $aliasNames, string $publisherName): void
{
    $stmt = $db->prepare('DELETE FROM tblPublisherAliases WHERE PublisherId = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $nameFold = mb_strtolower($publisherName);
    $seen     = [];
    $clean    = [];
    foreach ($aliasNames as $raw) {
        $name = mb_substr(trim((string)$raw), 0, 255);
        if ($name === '') continue;
        $fold = mb_strtolower($name);
        if ($fold === $nameFold) continue;
        if (isset($seen[$fold])) continue;
        $seen[$fold] = true;
        $clean[] = $name;
    }
    if (!$clean) return;

    $ins = $db->prepare('INSERT INTO tblPublisherAliases (PublisherId, Name) VALUES (?, ?)');
    foreach ($clean as $name) {
        $ins->bind_param('is', $id, $name);
        $ins->execute();
    }
    $ins->close();
}

/**
 * Usage + child-row counts for a publisher — the confirmation-modal numbers and
 * the list table columns. Each count is gated on its own table.
 *
 * @param array{hasSongbookPublishers:bool,hasAliases:bool,hasLinks:bool,hasPublishers:bool,...} $gates
 * @return array{songbookCount:int,childCount:int,aliasCount:int,linkCount:int}
 */
function publisherAdminUsageCounts(\mysqli $db, int $id, array $gates): array
{
    $out = ['songbookCount' => 0, 'childCount' => 0, 'aliasCount' => 0, 'linkCount' => 0];

    if (!empty($gates['hasSongbookPublishers'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblSongbookPublishers WHERE PublisherId = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['songbookCount'] = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    if (!empty($gates['hasPublishers'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblPublishers WHERE ParentId = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['childCount'] = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    if (!empty($gates['hasAliases'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblPublisherAliases WHERE PublisherId = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['aliasCount'] = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    if (!empty($gates['hasLinks'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblPublisherExternalLinks WHERE PublisherId = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['linkCount'] = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    return $out;
}

/**
 * Delete a publisher row. tblSongbookPublishers / tblPublisherAliases /
 * tblPublisherExternalLinks FKs are ON DELETE CASCADE; tblPublishers.ParentId is
 * ON DELETE SET NULL (children become top-level) — so delete needs no force
 * gate. The free-text tblSongbooks.Publisher denorm is untouched (a book keeps
 * its display string). Caller captures usage counts BEFORE calling, for the
 * confirmation UI / activity log.
 *
 * @return string|null the publisher's Name, or null when no such row.
 */
function publisherAdminDelete(\mysqli $db, int $id): ?string
{
    $stmt = $db->prepare('SELECT Name FROM tblPublishers WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $stmt = $db->prepare('DELETE FROM tblPublishers WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    return (string)$row['Name'];
}

/**
 * Collapse $sourceId into $targetId (mirrors tuneAdminMerge). Caller MUST wrap
 * this in a transaction. In order:
 *   1. Load both rows; reject missing.
 *   2. Repoint tblSongbookPublishers source -> target with UPDATE IGNORE (a book
 *      that already links to target in the same Role keeps its source row, which
 *      the CASCADE FK sweeps when the source publisher is deleted in step 7).
 *   3. Repoint child publishers (ParentId source -> target), excluding target
 *      itself; the ancestor walk in persist prevents new user-made cycles, and a
 *      merge only ever shortens the tree.
 *   4. Denorm cascade: rewrite tblSongbooks.Publisher source-name -> target-name
 *      (the tune-merge TuneName cascade applied to the publisher denorm).
 *   5. Move source aliases the target lacks; add the source's own Name as a
 *      target alias (rule #33 — old /publisher/<source-slug> still resolves via
 *      the name/alias ladder once built).
 *   6. Move source external links the target lacks (by LinkTypeId+Url).
 *   7. DELETE the source; CASCADE sweeps whatever wasn't explicitly moved.
 *
 * @param array{hasSongbookPublishers:bool,hasSongbookPublisherCol:bool,hasAliases:bool,hasLinks:bool,hasPublishers:bool,...} $gates
 * @return array{source:array{id:int,name:string},target:array{id:int,name:string},booksRepointed:int,childrenRepointed:int,aliasesMoved:int,linksMoved:int}
 */
function publisherAdminMerge(\mysqli $db, int $sourceId, int $targetId, array $gates): array
{
    $stmt = $db->prepare('SELECT Id, Name FROM tblPublishers WHERE Id IN (?, ?)');
    $stmt->bind_param('ii', $sourceId, $targetId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['Id']] = (string)$r['Name']; }
    if (!isset($byId[$sourceId]) || !isset($byId[$targetId])) {
        throw new \RuntimeException('Source or target publisher not found.');
    }
    $sourceName = $byId[$sourceId];
    $targetName = $byId[$targetId];

    $booksRepointed = 0;
    if (!empty($gates['hasSongbookPublishers'])) {
        $stmt = $db->prepare('UPDATE IGNORE tblSongbookPublishers SET PublisherId = ? WHERE PublisherId = ?');
        $stmt->bind_param('ii', $targetId, $sourceId);
        $stmt->execute();
        $booksRepointed = $stmt->affected_rows;
        $stmt->close();
    }

    $childrenRepointed = 0;
    if (!empty($gates['hasPublishers'])) {
        $stmt = $db->prepare('UPDATE tblPublishers SET ParentId = ? WHERE ParentId = ? AND Id <> ?');
        $stmt->bind_param('iii', $targetId, $sourceId, $targetId);
        $stmt->execute();
        $childrenRepointed = $stmt->affected_rows;
        $stmt->close();
    }

    if (!empty($gates['hasSongbookPublisherCol'])) {
        $stmt = $db->prepare('UPDATE tblSongbooks SET Publisher = ? WHERE Publisher = ?');
        $stmt->bind_param('ss', $targetName, $sourceName);
        $stmt->execute();
        $stmt->close();
    }

    $aliasesMoved = 0;
    if (!empty($gates['hasAliases'])) {
        $stmt = $db->prepare('SELECT Name FROM tblPublisherAliases WHERE PublisherId = ?');
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $sourceAliases = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Name');
        $stmt->close();

        $stmt = $db->prepare('SELECT Name FROM tblPublisherAliases WHERE PublisherId = ?');
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $targetAliasFold = array_map('mb_strtolower', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Name'));
        $stmt->close();

        $targetNameFold = mb_strtolower($targetName);
        $ins = $db->prepare('INSERT INTO tblPublisherAliases (PublisherId, Name) VALUES (?, ?)');
        foreach ($sourceAliases as $aliasName) {
            $fold = mb_strtolower((string)$aliasName);
            if ($fold === $targetNameFold || in_array($fold, $targetAliasFold, true)) continue;
            $ins->bind_param('is', $targetId, $aliasName);
            $ins->execute();
            $targetAliasFold[] = $fold;
            $aliasesMoved++;
        }
        $ins->close();

        $sourceNameFold = mb_strtolower($sourceName);
        if ($sourceNameFold !== $targetNameFold && !in_array($sourceNameFold, $targetAliasFold, true)) {
            $ins = $db->prepare('INSERT INTO tblPublisherAliases (PublisherId, Name) VALUES (?, ?)');
            $ins->bind_param('is', $targetId, $sourceName);
            $ins->execute();
            $ins->close();
            $aliasesMoved++;
        }
    }

    $linksMoved = 0;
    if (!empty($gates['hasLinks'])) {
        $stmt = $db->prepare('SELECT LinkTypeId, Url, Note, SortOrder, Verified FROM tblPublisherExternalLinks WHERE PublisherId = ?');
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $sourceLinks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $db->prepare('SELECT LinkTypeId, Url FROM tblPublisherExternalLinks WHERE PublisherId = ?');
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $targetPairs = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $tl) {
            $targetPairs[$tl['LinkTypeId'] . "\0" . $tl['Url']] = true;
        }
        $stmt->close();

        $ins = $db->prepare(
            'INSERT INTO tblPublisherExternalLinks (PublisherId, LinkTypeId, Url, Note, SortOrder, Verified) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($sourceLinks as $l) {
            $key = $l['LinkTypeId'] . "\0" . $l['Url'];
            if (isset($targetPairs[$key])) continue;
            $linkTypeId = (int)$l['LinkTypeId'];
            $url        = (string)$l['Url'];
            $note       = $l['Note'] !== null ? (string)$l['Note'] : null;
            $sortOrder  = (int)$l['SortOrder'];
            $verified   = (int)$l['Verified'];
            $ins->bind_param('iissii', $targetId, $linkTypeId, $url, $note, $sortOrder, $verified);
            $ins->execute();
            $targetPairs[$key] = true;
            $linksMoved++;
        }
        $ins->close();
    }

    $stmt = $db->prepare('DELETE FROM tblPublishers WHERE Id = ?');
    $stmt->bind_param('i', $sourceId);
    $stmt->execute();
    $stmt->close();

    return [
        'source'            => ['id' => $sourceId, 'name' => $sourceName],
        'target'            => ['id' => $targetId, 'name' => $targetName],
        'booksRepointed'    => $booksRepointed,
        'childrenRepointed' => $childrenRepointed,
        'aliasesMoved'      => $aliasesMoved,
        'linksMoved'        => $linksMoved,
    ];
}
