<?php

declare(strict_types=1);

/**
 * iHymns — External-link helpers (#845)
 *
 * Shared loader used by every admin edit-modal that ships the
 * `tblExternalLinkTypes` registry to its row builder via
 * `window._iHymnsLinkTypes`. Attaches each type's URL → provider
 * patterns from `tblExternalLinkPatterns` so the JS auto-detect
 * module reads its rules from the DB rather than the hard-coded
 * fallback list.
 *
 * Probe-gated on the patterns table existing — pre-migration
 * deployments get an empty `patterns` array per type and the JS
 * module silently falls back to its bundled RULES.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * #1748 §5.2 — the entity types a link-type may apply to (the CSV tokens stored
 * in `tblExternalLinkTypes.AppliesTo`).
 *
 * ELI5: "which kinds of thing can a provider link be attached to?" — songs,
 * songbooks, musicians, works, and (new in #1748) tunes.
 *
 * DETAILED / WHY ONE CENTRAL CONST (rule #20/#35): `AppliesTo` is a growable
 * VARCHAR vocabulary (widened from a SET by #1741 P1), NOT an ENUM — growing it
 * is one line HERE, and `manage/external-link-types.php`'s tick UI + save both
 * read this list rather than each carrying a page-local copy. A legacy token
 * that predates this const (e.g. the pre-rename `'person'`) is deliberately
 * PRESERVED by the save path (it array_diff()s tokens NOT in this list and keeps
 * them), so introducing the const never zeroes an existing row's AppliesTo.
 */
const IHYMNS_LINK_ENTITY_TYPES = ['song', 'songbook', 'musician', 'work', 'tune'];

/**
 * #1992 — the curator-facing labels for `tblExternalLinkTypes.Category`
 * (VARCHAR(20), app-validated — rule #20, never an ENUM). ORDER matters: this
 * is the display order both `/manage/external-link-types` and the public
 * site's category grouping use.
 *
 * ELI5: which shelf does a provider's card sit on — "Listen", "Watch",
 * "Read", …?
 *
 * DETAILED / WHY ONE CENTRAL CONST (rule #20/#35): `Category` used to live
 * only as a page-local `$categoryLabels` array inside
 * `manage/external-link-types.php` — fine while the page was the only writer
 * of a Category value, but the #1992 create paths (manual form, guided
 * wizard, the `admin_external_link_type_create` API twin) all need to
 * VALIDATE a posted category against the same known set, not just render
 * one. Centralising here means `externalLinkTypeAdminValidateNewType()`
 * (includes/external_link_type_admin.php) and the page's render both read
 * ONE list — growing the vocabulary is one line here, never a second
 * page-local copy (mirrors IHYMNS_LINK_ENTITY_TYPES immediately above).
 *
 * Kept byte-identical to the pre-#1992 page-local `$categoryLabels` array
 * (schema.sql:2634's Category column COMMENT lists the same 10 keys) — this
 * is a lift, not a rewrite.
 *
 * @see appWeb/public_html/manage/external-link-types.php  $categoryLabels re-pointed to this
 * @see appWeb/public_html/includes/external_link_type_admin.php  externalLinkTypeAdminValidateNewType()
 * @see appWeb/.sql/schema.sql  tblExternalLinkTypes.Category column COMMENT
 */
const IHYMNS_LINK_TYPE_CATEGORIES = [
    'official'    => 'Official',
    'information' => 'Information',
    'read'        => 'Read',
    'sheet-music' => 'Sheet music',
    'listen'      => 'Listen',
    'watch'       => 'Watch',
    'purchase'    => 'Purchase',
    'authority'   => 'Authority',
    'social'      => 'Social',
    'other'       => 'Other',
];

/**
 * Attach a `patterns` array to each link-type row in $types.
 *
 * @param \mysqli              $db
 * @param array<int,array>     $types  Rows already loaded from
 *                                     tblExternalLinkTypes — each
 *                                     row must carry an `id` key.
 * @return array<int,array>            Same array, mutated in place
 *                                     (returned for chaining).
 */
function attachExternalLinkPatterns(\mysqli $db, array $types): array
{
    if (empty($types)) return $types;

    /* Probe the patterns table — quietly no-op when missing. */
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblExternalLinkPatterns' LIMIT 1"
        );
        $hasTable = $r && $r->fetch_row() !== null;
        if ($r) $r->close();
    } catch (\Throwable $_e) {
        $hasTable = false;
    }
    if (!$hasTable) {
        foreach ($types as &$t) {
            if (!isset($t['patterns'])) $t['patterns'] = [];
        }
        return $types;
    }

    $idList = array_values(array_filter(array_map(
        static fn($t) => (int)($t['id'] ?? 0),
        $types
    )));
    if (empty($idList)) return $types;
    $ph = implode(',', array_fill(0, count($idList), '?'));

    try {
        $sql = "SELECT LinkTypeId, Host, PathPrefix, MatchSubdomains, Priority
                  FROM tblExternalLinkPatterns
                 WHERE LinkTypeId IN ($ph)
                   AND COALESCE(IsActive, 1) = 1
                 ORDER BY Priority ASC, Host ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($idList)), ...$idList);
        $stmt->execute();
        $res = $stmt->get_result();
        $byType = [];
        while ($row = $res->fetch_assoc()) {
            $tid = (int)$row['LinkTypeId'];
            $byType[$tid][] = [
                'host'            => (string)$row['Host'],
                'pathPrefix'      => $row['PathPrefix'] !== null ? (string)$row['PathPrefix'] : null,
                'matchSubdomains' => (int)$row['MatchSubdomains'] === 1,
                'priority'        => (int)$row['Priority'],
            ];
        }
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[attachExternalLinkPatterns] ' . $e->getMessage());
        $byType = [];
    }

    foreach ($types as &$t) {
        $tid = (int)($t['id'] ?? 0);
        $t['patterns'] = $byType[$tid] ?? [];
    }
    unset($t);
    return $types;
}

/**
 * Load the active `tblExternalLinkTypes` registry for a given surface
 * (songbook / work / song / musician / …) and attach URL → provider
 * patterns. Returns a list shaped for `window._iHymnsLinkTypes` consumption
 * by the shared `external-links-editor.js` module.
 *
 * Returns `[]` when the schema isn't live (pre-migration deployment) so
 * callers can render the rest of their page without external-links UI.
 *
 * @param \mysqli $db
 * @param string  $appliesTo  Value to test against tblExternalLinkTypes.AppliesTo
 *                            via FIND_IN_SET. Common values: 'songbook',
 *                            'work', 'song', 'musician'.
 * @return list<array{id:int,slug:string,name:string,category:string,iconClass:string,allowMultiple:int,patterns:array}>
 */
function loadExternalLinkTypesFor(\mysqli $db, string $appliesTo): array
{
    try {
        $probe = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblExternalLinkTypes' LIMIT 1"
        );
        $hasTable = $probe && $probe->fetch_row() !== null;
        if ($probe) $probe->close();
    } catch (\Throwable $_e) {
        $hasTable = false;
    }
    if (!$hasTable) return [];

    $types = [];
    try {
        $stmt = $db->prepare(
            "SELECT Id, Slug, Name, Category, IconClass, AllowMultiple, DisplayOrder
               FROM tblExternalLinkTypes
              WHERE COALESCE(IsActive, 1) = 1
                AND FIND_IN_SET(?, AppliesTo) > 0
              ORDER BY Category ASC, DisplayOrder ASC, Name ASC"
        );
        $stmt->bind_param('s', $appliesTo);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $types[] = [
                'id'            => (int)$row['Id'],
                'slug'          => (string)$row['Slug'],
                'name'          => (string)$row['Name'],
                'category'      => (string)$row['Category'],
                'iconClass'     => (string)($row['IconClass']  ?? ''),
                'allowMultiple' => (int)($row['AllowMultiple'] ?? 0),
            ];
        }
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[loadExternalLinkTypesFor] ' . $e->getMessage());
        return [];
    }

    return attachExternalLinkPatterns($db, $types);
}

/**
 * Save the per-row external-links sub-form posted from any admin edit
 * surface using the canonical `ext_link_*[]` field naming. Runs inside
 * the caller's transaction (caller is responsible for begin/commit so a
 * downstream failure rolls back together with the parent UPDATE).
 *
 * Performs DELETE-then-INSERT against the surface's link table, mirroring
 * the original songbook implementation (#833) which the songbook,
 * work and song surfaces all converged on.
 *
 * @param \mysqli $db
 * @param string  $table     Target link table — 'tblSongbookExternalLinks',
 *                           'tblWorkExternalLinks', 'tblSongExternalLinks'.
 * @param string  $fkColumn  FK column on $table that references the owning
 *                           row — 'SongbookId', 'WorkId', 'SongId'.
 * @param mixed   $fkValue   Owning row's PK value (int for the numeric
 *                           surfaces, string for SongId).
 * @param mixed   $rawTypeIds  $_POST['ext_link_type_ids']  (any shape — non-array → [])
 * @param mixed   $rawUrls     $_POST['ext_link_urls']
 * @param mixed   $rawNotes    $_POST['ext_link_notes']
 * @param mixed   $rawVerified $_POST['ext_link_verified']
 * @return int   Number of link rows inserted.
 */
function saveExternalLinksForRow(
    \mysqli $db,
    string  $table,
    string  $fkColumn,
    mixed   $fkValue,
    mixed   $rawTypeIds,
    mixed   $rawUrls,
    mixed   $rawNotes,
    mixed   $rawVerified
): int {
    $typeIds  = is_array($rawTypeIds)  ? $rawTypeIds  : [];
    $urls     = is_array($rawUrls)     ? $rawUrls     : [];
    $notes    = is_array($rawNotes)    ? $rawNotes    : [];
    $verified = is_array($rawVerified) ? $rawVerified : [];

    /* Whitelist the table + fk-column names — these come from the
       caller, but we still guard against accidental mistypes leaking
       into an unescaped SQL identifier position. */
    $allowedTables = [
        'tblSongbookExternalLinks'     => 'SongbookId',
        'tblWorkExternalLinks'         => 'WorkId',
        'tblSongExternalLinks'         => 'SongId',
        'tblMusicianExternalLinks' => 'MusicianId',
        'tblTuneExternalLinks'         => 'TuneId',   // #1748 §5.1 — tune-entity external links
    ];
    if (!isset($allowedTables[$table]) || $allowedTables[$table] !== $fkColumn) {
        throw new \InvalidArgumentException("Unknown external-links table/fk: $table/$fkColumn");
    }

    /* FK bind char — SongId is varchar in tblSongs, the rest are ints. */
    $fkBindType = ($fkColumn === 'SongId') ? 's' : 'i';

    $del = $db->prepare("DELETE FROM {$table} WHERE {$fkColumn} = ?");
    if ($fkBindType === 's') {
        $sv = (string)$fkValue;
        $del->bind_param('s', $sv);
    } else {
        $iv = (int)$fkValue;
        $del->bind_param('i', $iv);
    }
    $del->execute();
    $del->close();

    $inserted = 0;
    $count    = max(count($typeIds), count($urls));
    if ($count === 0) return 0;

    $ins = $db->prepare(
        "INSERT INTO {$table}
            ({$fkColumn}, LinkTypeId, Url, Note, SortOrder, Verified)
         VALUES (?, ?, ?, NULLIF(?, ''), ?, ?)"
    );
    for ($i = 0; $i < $count; $i++) {
        $typeId = (int)($typeIds[$i] ?? 0);
        $url    = trim((string)($urls[$i] ?? ''));
        $note   = mb_substr(trim((string)($notes[$i] ?? '')), 0, 255);
        $ver    = !empty($verified[$i]) ? 1 : 0;

        if ($typeId <= 0 || $url === '') continue;
        if (!preg_match('#^https?://#i', $url)) continue;
        if (mb_strlen($url) > 2048) continue;

        if ($fkBindType === 's') {
            $sv = (string)$fkValue;
            $ins->bind_param('sissii', $sv, $typeId, $url, $note, $i, $ver);
        } else {
            $iv = (int)$fkValue;
            $ins->bind_param('iissii', $iv, $typeId, $url, $note, $i, $ver);
        }
        $ins->execute();
        $inserted++;
    }
    $ins->close();
    return $inserted;
}

/**
 * Fetch the external links attached to one row across any of the
 * tbl*ExternalLinks tables, shaped for the JS row-builder
 * (`typeId / url / note / verified`). Used by the editor + admin
 * modals to pre-fill the rows on load.
 *
 * @param \mysqli $db
 * @param string  $table    'tblSongbookExternalLinks' | 'tblWorkExternalLinks' | 'tblSongExternalLinks'
 * @param string  $fkColumn 'SongbookId' | 'WorkId' | 'SongId'
 * @param mixed   $fkValue  Owning row's PK
 * @return list<array{typeId:int,url:string,note:string,verified:int,sortOrder:int}>
 */
function loadExternalLinksForRow(
    \mysqli $db,
    string  $table,
    string  $fkColumn,
    mixed   $fkValue
): array {
    $allowedTables = [
        'tblSongbookExternalLinks'     => 'SongbookId',
        'tblWorkExternalLinks'         => 'WorkId',
        'tblSongExternalLinks'         => 'SongId',
        'tblMusicianExternalLinks' => 'MusicianId',
        'tblTuneExternalLinks'         => 'TuneId',   // #1748 §5.1 — tune-entity external links
    ];
    if (!isset($allowedTables[$table]) || $allowedTables[$table] !== $fkColumn) {
        throw new \InvalidArgumentException("Unknown external-links table/fk: $table/$fkColumn");
    }

    try {
        $probe = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = '" . $table . "' LIMIT 1"
        );
        $exists = $probe && $probe->fetch_row() !== null;
        if ($probe) $probe->close();
    } catch (\Throwable $_e) {
        $exists = false;
    }
    if (!$exists) return [];

    $fkBindType = ($fkColumn === 'SongId') ? 's' : 'i';
    $stmt = $db->prepare(
        "SELECT LinkTypeId, Url, COALESCE(Note, '') AS Note, SortOrder, Verified
           FROM {$table}
          WHERE {$fkColumn} = ?
       ORDER BY SortOrder ASC, LinkTypeId ASC"
    );
    if ($fkBindType === 's') {
        $sv = (string)$fkValue;
        $stmt->bind_param('s', $sv);
    } else {
        $iv = (int)$fkValue;
        $stmt->bind_param('i', $iv);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'typeId'    => (int)$row['LinkTypeId'],
            'url'       => (string)$row['Url'],
            'note'      => (string)$row['Note'],
            'verified'  => (int)$row['Verified'],
            'sortOrder' => (int)$row['SortOrder'],
        ];
    }
    $stmt->close();
    return $out;
}
