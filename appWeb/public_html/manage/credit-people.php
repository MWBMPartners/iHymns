<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Credit People (#545)
 *
 * Catalogue-wide CRUD for the people credited on songs, unioned
 * across tblSongWriters / tblSongComposers / tblSongArrangers /
 * tblSongAdaptors / tblSongTranslators, plus the registry rows in
 * tblCreditPeople (which may exist without any current song citing
 * them — pre-registered names for upcoming songs, or names whose
 * usage was cleaned up but the metadata is being kept).
 *
 * Surfaces:
 *   - List view: every distinct name with per-role usage, lifespan,
 *     link / IPI counts. Filterable + searchable client-side.
 *   - Person detail drawer: full edit form for biographical
 *     metadata (notes, birth/death place + date), repeating
 *     external link sub-form, repeating IPI Name Number sub-form.
 *     Drives both Add and Update.
 *   - Rename modal: changes the canonical name, cascading the
 *     update across the five song-credit tables AND the registry
 *     row, atomically inside a transaction.
 *   - Merge modal: collapses two registry entries into one,
 *     re-pointing every song-credit row from source name → target
 *     name, with the source registry row removed and the
 *     ON DELETE CASCADE FK cleaning up its child rows. Admin
 *     chooses which links / IPI rows to keep on the surviving row.
 *   - Delete: removes a registry row, refusing by default if any
 *     song still cites the name; a force flag overrides.
 *   - View Songs: read-only modal listing every song that cites the
 *     person, grouped by role.
 *
 * Database access uses mysqli prepared statements throughout
 * (project policy, set 2026-04-27). All mutating actions run inside
 * $db->begin_transaction() so a partial failure rolls back cleanly.
 * Every action emits a tblActivityLog row via logActivity() with
 * EntityType='credit_person'.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_credit_people', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_credit_people required</h1></body></html>';
    exit;
}
$activePage = 'credit-people';

$error   = '';
$success = '';
$csrf    = csrfToken();

/* ----------------------------------------------------------------------
 * Activity-log helper
 *
 * Every mutating action on this page emits a tblActivityLog row via
 * logActivity() (which still lives in /includes/activity_log.php
 * and is PDO-backed until the umbrella migration #554 Batch 4 lands;
 * mixing the two helpers in the same request is fine — they manage
 * independent connections).
 *
 * The closure bakes in the EntityType so per-action call sites stay
 * tight and any future rename of the type string is a one-line
 * change here. Silent no-op if the activity-log table is missing
 * (matches the pattern in songbooks.php / organisations.php).
 * ---------------------------------------------------------------------- */
$logCreditPerson = static function (string $action, string $entityId, array $details): void {
    if (function_exists('logActivity')) {
        try {
            logActivity('credit_person.' . $action, 'credit_person', $entityId, $details);
        } catch (\Throwable $_e) { /* audit is best-effort */ }
    }
};

/* ----------------------------------------------------------------------
 * Helpers — link / IPI sub-form normalisation
 *
 * The Add and Update_person actions both accept arrays of links and
 * IPI numbers in the POST body, posted as `links[i][type|url|label]`
 * and `ipi[i][number|name_used|notes]`. Empty rows (no URL / no
 * IPI number) are silently dropped. Returns a clean array of
 * row-shaped arrays ready to INSERT.
 * ---------------------------------------------------------------------- */
/**
 * Curated link-type registry (#586).
 *
 * Replaces the seven-item hard-coded set with a grouped catalogue of
 * well-known providers — Wikipedia, Wikidata, MusicBrainz, the
 * streaming services (Spotify / Apple Music / YouTube Music / …) and
 * the major social networks. The picker in the modal is built from
 * this array via <optgroup>; the server-side validator accepts any
 * key from the flat list. "Other" is always allowed and stays as the
 * free-text fallback.
 *
 * Adding a new provider: append it under its category. The picker
 * UI and the validator both update automatically. Legacy LinkType
 * values stored in the DB before #586 (e.g. 'wikipedia', 'official')
 * remain valid because they still appear in the catalogue under the
 * General category — no data migration needed.
 */
$LINK_TYPE_CATALOGUE = [
    'General' => [
        'official'      => 'Official website',
        'wikipedia'     => 'Wikipedia',
        'wikidata'      => 'Wikidata',
        'musicbrainz'   => 'MusicBrainz',
        'discogs'       => 'Discogs',
        'imslp'         => 'IMSLP',
        'hymnary'       => 'Hymnary',
    ],
    'Music streaming / stores' => [
        'spotify'       => 'Spotify',
        'apple_music'   => 'Apple Music',
        'youtube_music' => 'YouTube Music',
        'amazon_music'  => 'Amazon Music',
        'tidal'         => 'Tidal',
        'qobuz'         => 'Qobuz',
        'pandora'       => 'Pandora',
        'bandcamp'      => 'Bandcamp',
        'soundcloud'    => 'SoundCloud',
    ],
    'Social media' => [
        'facebook'      => 'Facebook',
        'instagram'     => 'Instagram',
        'twitter'       => 'Twitter / X',
        'tiktok'        => 'TikTok',
        'youtube'       => 'YouTube',
        'snapchat'      => 'Snapchat',
        'threads'       => 'Threads',
        'mastodon'      => 'Mastodon',
    ],
    'Other' => [
        'other'         => 'Other (free text)',
    ],
];
/* Flat lookup used by the validator + by the JS-side serialiser. */
$LINK_TYPE_KEYS = array_keys(array_merge(...array_values($LINK_TYPE_CATALOGUE)));

/**
 * Cached check for the IsSpecialCase / IsGroup columns from #584/#585
 * (closes #630). Both columns ship together via migrate-credit-people-
 * flags.php, so detecting one is sufficient to assume both. The result
 * is cached for the request lifetime via a static so the add / update_
 * person paths don't pay the INFORMATION_SCHEMA round-trip twice.
 */
function _cpFlagsColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblCreditPeople'
            AND COLUMN_NAME  = 'IsSpecialCase' LIMIT 1"
    );
    $stmt->execute();
    $cached = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $cached;
}

$normaliseLinks = static function (mixed $raw) use ($LINK_TYPE_KEYS): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $i => $row) {
        if (!is_array($row)) continue;
        $url = trim((string)($row['url'] ?? ''));
        if ($url === '') continue;
        $type = trim((string)($row['type']  ?? 'other'));
        /* Unknown types collapse to 'other' rather than 500ing —
           keeps a forward-compatible UI where a future picker
           category gets dropped to a sane bucket on older servers. */
        if (!in_array($type, $LINK_TYPE_KEYS, true)) { $type = 'other'; }
        $out[] = [
            'type'       => $type,
            'url'        => $url,
            'label'      => trim((string)($row['label'] ?? '')) ?: null,
            'sort_order' => (int)($row['sort_order'] ?? $i),
        ];
    }
    return $out;
};
$normaliseIpi = static function (mixed $raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) continue;
        $num = trim((string)($row['number'] ?? ''));
        if ($num === '') continue;
        $out[] = [
            'number'    => $num,
            'name_used' => trim((string)($row['name_used'] ?? '')) ?: null,
            'notes'     => trim((string)($row['notes']     ?? '')) ?: null,
        ];
    }
    return $out;
};

/* ----------------------------------------------------------------------
 * GET endpoint — view_songs JSON
 *
 * Returns the list of songs that cite the given person, grouped by
 * role. Used by the View Songs modal. Standalone — short-circuits
 * the rest of the page (returns JSON, then exit).
 *
 * GET ?action=view_songs&id=<registry-id>
 *
 * Looks up the person's name from the registry row, then queries the
 * five song-credit tables joined to tblSongs for the human-friendly
 * Title + SongbookAbbr + Number used in the modal listing.
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['action'] ?? '') === 'view_songs') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    $personId = (int)($_GET['id'] ?? 0);
    if ($personId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        exit;
    }

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT Name FROM tblCreditPeople WHERE Id = ?');
        $stmt->bind_param('i', $personId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $name = (string)($row[0] ?? '');
        if ($name === '') {
            http_response_code(404);
            echo json_encode(['error' => 'Person not found.']);
            exit;
        }

        /* For each role table, fetch the songs that cite this name.
           Joining to tblSongs on SongId gives us the human-friendly
           Title + SongbookAbbr + Number for the modal. */
        $tables = [
            'writer'     => 'tblSongWriters',
            'composer'   => 'tblSongComposers',
            'arranger'   => 'tblSongArrangers',
            'adaptor'    => 'tblSongAdaptors',
            'translator' => 'tblSongTranslators',
        ];
        $byRole = [];
        foreach ($tables as $role => $tbl) {
            $sql = "SELECT s.SongId, s.Title, s.SongbookAbbr, s.Number
                      FROM {$tbl} c
                      JOIN tblSongs s ON s.SongId = c.SongId
                     WHERE c.Name = ?
                     ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $byRole[$role] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        echo json_encode([
            'name'    => $name,
            'by_role' => $byRole,
            'total'   => array_sum(array_map('count', $byRole)),
        ]);
        exit;
    } catch (\Throwable $e) {
        error_log('[manage/credit-people.php] view_songs failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not load songs.']);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * POST dispatch
 *
 * All actions are CSRF-checked + entitlement-gated (the entitlement
 * gate is the page-level requireAuth + userHasEntitlement above; the
 * gate covers every action below). Each action runs inside a single
 * $db->begin_transaction() so a partial failure (e.g. a child-row
 * INSERT failing after the parent UPDATE landed) rolls back cleanly.
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        $db = getDbMysqli();

        switch ($action) {
            /* --------------------------------------------------------
             * add — create a new registry row (+ optional child rows)
             * -------------------------------------------------------- */
            case 'add': {
                $name        = trim((string)($_POST['name']         ?? ''));
                $notesRaw    = trim((string)($_POST['notes']        ?? ''));
                $birthPlace  = trim((string)($_POST['birth_place']  ?? '')) ?: null;
                $birthDate   = trim((string)($_POST['birth_date']   ?? '')) ?: null;
                $deathPlace  = trim((string)($_POST['death_place']  ?? '')) ?: null;
                $deathDate   = trim((string)($_POST['death_date']   ?? '')) ?: null;
                $notes       = $notesRaw !== '' ? $notesRaw : null;
                $links       = $normaliseLinks($_POST['links'] ?? null);
                $ipi         = $normaliseIpi($_POST['ipi']     ?? null);
                /* #584 / #585 — classification flags. The two are
                   mutually exclusive in the UI; if both arrive we
                   prefer special-case (it's the more constraining
                   flag — it suppresses biographical fields). */
                $isSpecialCase = !empty($_POST['is_special_case']) ? 1 : 0;
                $isGroup       = !empty($_POST['is_group'])        ? 1 : 0;
                if ($isSpecialCase && $isGroup) { $isGroup = 0; }

                if ($name === '')                       { $error = 'Name is required.'; break; }
                if (mb_strlen($name) > 255)             { $error = 'Name must be 255 characters or fewer.'; break; }
                if ($birthDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) { $error = 'Birth date must be YYYY-MM-DD.'; break; }
                if ($deathDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deathDate)) { $error = 'Death date must be YYYY-MM-DD.'; break; }

                /* Uniqueness check before opening the transaction so we
                   don't have to reason about MySQL's UNIQUE-violation
                   error code path. */
                $stmt = $db->prepare('SELECT Id FROM tblCreditPeople WHERE Name = ?');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = "A person with the name '{$name}' is already registered."; break; }

                $db->begin_transaction();
                try {
                    /* Detect whether the flag columns from #584/#585 are
                       present yet (#630). On a partly-migrated install
                       — flags migration not applied — the INSERT must
                       skip those columns rather than throw "Unknown
                       column", which is what surfaced as the
                       "Database error" banner before #635 unmasked it. */
                    $hasFlagsCols = _cpFlagsColumnsExist($db);
                    if ($hasFlagsCols) {
                        $stmt = $db->prepare(
                            'INSERT INTO tblCreditPeople
                                (Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate, IsSpecialCase, IsGroup)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param('ssssssii',
                            $name, $notes, $birthPlace, $birthDate, $deathPlace, $deathDate,
                            $isSpecialCase, $isGroup
                        );
                    } else {
                        $stmt = $db->prepare(
                            'INSERT INTO tblCreditPeople
                                (Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param('ssssss',
                            $name, $notes, $birthPlace, $birthDate, $deathPlace, $deathDate
                        );
                    }
                    $stmt->execute();
                    $newId = (int)$db->insert_id;
                    $stmt->close();

                    if ($links) {
                        $linkStmt = $db->prepare(
                            'INSERT INTO tblCreditPersonLinks
                                (CreditPersonId, LinkType, Url, Label, SortOrder)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        foreach ($links as $l) {
                            $linkStmt->bind_param('isssi',
                                $newId, $l['type'], $l['url'], $l['label'], $l['sort_order']);
                            $linkStmt->execute();
                        }
                        $linkStmt->close();
                    }
                    if ($ipi) {
                        $ipiStmt = $db->prepare(
                            'INSERT INTO tblCreditPersonIPI
                                (CreditPersonId, IPINumber, NameUsed, Notes)
                             VALUES (?, ?, ?, ?)'
                        );
                        foreach ($ipi as $r) {
                            $ipiStmt->bind_param('isss',
                                $newId, $r['number'], $r['name_used'], $r['notes']);
                            $ipiStmt->execute();
                        }
                        $ipiStmt->close();
                    }
                    $db->commit();

                    $logCreditPerson('add', (string)$newId, [
                        'name'        => $name,
                        'fields'      => array_filter([
                            'birth_place' => $birthPlace,
                            'birth_date'  => $birthDate,
                            'death_place' => $deathPlace,
                            'death_date'  => $deathDate,
                            'notes'       => $notes,
                        ], static fn($v) => $v !== null),
                        'link_count'  => count($links),
                        'ipi_count'   => count($ipi),
                    ]);
                    $success = "Person '{$name}' added to the registry.";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            /* --------------------------------------------------------
             * update_person — edit metadata + replace child rows for
             *                 an existing registry row.
             *
             * The Name column is NOT changed by this action — renames
             * have their own handler because their blast radius is
             * cross-table. If the form's name field differs from the
             * stored name we reject and direct the admin to use Rename.
             * -------------------------------------------------------- */
            case 'update_person': {
                $id          = (int)($_POST['id']          ?? 0);
                $name        = trim((string)($_POST['name']         ?? ''));
                $notesRaw    = trim((string)($_POST['notes']        ?? ''));
                $birthPlace  = trim((string)($_POST['birth_place']  ?? '')) ?: null;
                $birthDate   = trim((string)($_POST['birth_date']   ?? '')) ?: null;
                $deathPlace  = trim((string)($_POST['death_place']  ?? '')) ?: null;
                $deathDate   = trim((string)($_POST['death_date']   ?? '')) ?: null;
                $notes       = $notesRaw !== '' ? $notesRaw : null;
                $links       = $normaliseLinks($_POST['links'] ?? null);
                $ipi         = $normaliseIpi($_POST['ipi']     ?? null);
                $isSpecialCase = !empty($_POST['is_special_case']) ? 1 : 0;
                $isGroup       = !empty($_POST['is_group'])        ? 1 : 0;
                if ($isSpecialCase && $isGroup) { $isGroup = 0; }

                if ($id <= 0)                           { $error = 'Person id missing.'; break; }
                if ($name === '')                       { $error = 'Name is required.'; break; }
                if ($birthDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) { $error = 'Birth date must be YYYY-MM-DD.'; break; }
                if ($deathDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deathDate)) { $error = 'Death date must be YYYY-MM-DD.'; break; }

                /* Pull the before-row both as a sanity check (id valid?)
                   and to compute the audit-log diff. */
                $stmt = $db->prepare(
                    'SELECT Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate
                       FROM tblCreditPeople WHERE Id = ?'
                );
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $beforeRow = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if ($beforeRow === null) { $error = 'Person not found.'; break; }

                if ((string)$beforeRow['Name'] !== $name) {
                    $error = 'Use the Rename action to change a person\'s name — the change cascades to every song that cites them, so it has its own confirmation flow.';
                    break;
                }

                $db->begin_transaction();
                try {
                    /* Update the registry row. Name is not in the SET
                       clause — renames go through the rename action. */
                    /* Gate the flag-column writes on column existence
                       (#630). Same partly-migrated tolerance as the
                       add path. */
                    if (_cpFlagsColumnsExist($db)) {
                        $stmt = $db->prepare(
                            'UPDATE tblCreditPeople
                                SET Notes = ?, BirthPlace = ?, BirthDate = ?,
                                    DeathPlace = ?, DeathDate = ?,
                                    IsSpecialCase = ?, IsGroup = ?
                              WHERE Id = ?'
                        );
                        $stmt->bind_param('sssssiii',
                            $notes, $birthPlace, $birthDate, $deathPlace, $deathDate,
                            $isSpecialCase, $isGroup, $id);
                    } else {
                        $stmt = $db->prepare(
                            'UPDATE tblCreditPeople
                                SET Notes = ?, BirthPlace = ?, BirthDate = ?,
                                    DeathPlace = ?, DeathDate = ?
                              WHERE Id = ?'
                        );
                        $stmt->bind_param('sssssi',
                            $notes, $birthPlace, $birthDate, $deathPlace, $deathDate, $id);
                    }
                    $stmt->execute();
                    $stmt->close();

                    /* Child rows: DELETE then INSERT — simpler than
                       diffing and the per-person row counts are small
                       (typically < 10 each). The child Ids change as a
                       side effect, but no other table references them. */
                    $del = $db->prepare('DELETE FROM tblCreditPersonLinks WHERE CreditPersonId = ?');
                    $del->bind_param('i', $id);
                    $del->execute();
                    $del->close();
                    if ($links) {
                        $linkStmt = $db->prepare(
                            'INSERT INTO tblCreditPersonLinks
                                (CreditPersonId, LinkType, Url, Label, SortOrder)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        foreach ($links as $l) {
                            $linkStmt->bind_param('isssi',
                                $id, $l['type'], $l['url'], $l['label'], $l['sort_order']);
                            $linkStmt->execute();
                        }
                        $linkStmt->close();
                    }

                    $del = $db->prepare('DELETE FROM tblCreditPersonIPI WHERE CreditPersonId = ?');
                    $del->bind_param('i', $id);
                    $del->execute();
                    $del->close();
                    if ($ipi) {
                        $ipiStmt = $db->prepare(
                            'INSERT INTO tblCreditPersonIPI
                                (CreditPersonId, IPINumber, NameUsed, Notes)
                             VALUES (?, ?, ?, ?)'
                        );
                        foreach ($ipi as $r) {
                            $ipiStmt->bind_param('isss',
                                $id, $r['number'], $r['name_used'], $r['notes']);
                            $ipiStmt->execute();
                        }
                        $ipiStmt->close();
                    }
                    $db->commit();

                    /* Compute the changed-fields list for audit. The
                       child-table replacement is captured as counts
                       only — diffing a links list is more noise than
                       signal in the activity-log timeline. */
                    $afterRow = [
                        'Notes'      => $notes,
                        'BirthPlace' => $birthPlace,
                        'BirthDate'  => $birthDate,
                        'DeathPlace' => $deathPlace,
                        'DeathDate'  => $deathDate,
                    ];
                    $changed = [];
                    foreach ($afterRow as $k => $v) {
                        if ((string)($beforeRow[$k] ?? '') !== (string)($v ?? '')) {
                            $changed[] = $k;
                        }
                    }
                    $logCreditPerson('update_person', (string)$id, [
                        'name'       => $name,
                        'fields'     => $changed,
                        'before'     => array_intersect_key($beforeRow, array_flip($changed)),
                        'after'      => array_intersect_key($afterRow,  array_flip($changed)),
                        'link_count' => count($links),
                        'ipi_count'  => count($ipi),
                    ]);
                    $success = "Person '{$name}' updated.";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            /* --------------------------------------------------------
             * rename — change the canonical name, cascading the UPDATE
             *          across all five song-credit tables AND the
             *          registry row inside one transaction.
             *
             * Refuses if the new name already belongs to a different
             * registry row (would clash with the UNIQUE Name index
             * and forces the admin to think about whether they
             * actually meant Merge instead).
             * -------------------------------------------------------- */
            case 'rename': {
                $id          = (int)($_POST['id'] ?? 0);
                $sourceName  = trim((string)($_POST['source_name'] ?? ''));
                $newName     = trim((string)($_POST['new_name'] ?? ''));

                if ($id <= 0 && $sourceName === '') { $error = 'Person id or source name missing.'; break; }
                if ($newName === '')           { $error = 'New name is required.'; break; }
                if (mb_strlen($newName) > 255) { $error = 'Name must be 255 characters or fewer.'; break; }

                /* In-use-only rename support (#626). When the row isn't
                   in the registry yet (Stuart Townend duplicate case),
                   the JS posts source_name instead of (or alongside)
                   id; we auto-register a row for it on the fly so the
                   rest of the rename code path doesn't have to branch. */
                if ($id <= 0 && $sourceName !== '') {
                    $reg = $db->prepare('SELECT Id FROM tblCreditPeople WHERE Name = ?');
                    $reg->bind_param('s', $sourceName);
                    $reg->execute();
                    $regRow = $reg->get_result()->fetch_row();
                    $reg->close();
                    if ($regRow) {
                        $id = (int)$regRow[0];
                    } else {
                        $ins = $db->prepare('INSERT INTO tblCreditPeople (Name) VALUES (?)');
                        $ins->bind_param('s', $sourceName);
                        $ins->execute();
                        $id = (int)$db->insert_id;
                        $ins->close();
                    }
                }

                /* Look up the current name + check that the target
                   spelling isn't already in use by a different row. */
                $stmt = $db->prepare('SELECT Name FROM tblCreditPeople WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $oldName = (string)($row[0] ?? '');
                if ($oldName === '') { $error = 'Person not found.'; break; }
                if ($oldName === $newName) { $error = 'New name is the same as the current name.'; break; }

                $stmt = $db->prepare('SELECT Id FROM tblCreditPeople WHERE Name = ? AND Id <> ?');
                $stmt->bind_param('si', $newName, $id);
                $stmt->execute();
                $clash = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($clash) {
                    $error = "Another registry row already uses '{$newName}'. Use Merge if you want to combine them.";
                    break;
                }

                $db->begin_transaction();
                try {
                    /* Cascade the rename across the five song-credit
                       tables. A song that cites the old spelling under
                       multiple roles is updated in each table. */
                    $tables = [
                        'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
                        'tblSongAdaptors', 'tblSongTranslators',
                    ];
                    $affected = [];
                    foreach ($tables as $tbl) {
                        $stmt = $db->prepare("UPDATE {$tbl} SET Name = ? WHERE Name = ?");
                        $stmt->bind_param('ss', $newName, $oldName);
                        $stmt->execute();
                        $affected[$tbl] = $stmt->affected_rows;
                        $stmt->close();
                    }

                    /* Update the registry row last — if any of the five
                       cascades fail, rollback restores everything. */
                    $stmt = $db->prepare('UPDATE tblCreditPeople SET Name = ? WHERE Id = ?');
                    $stmt->bind_param('si', $newName, $id);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();

                    $logCreditPerson('rename', (string)$id, [
                        'before'   => ['name' => $oldName],
                        'after'    => ['name' => $newName],
                        'affected' => [
                            'writers'     => $affected['tblSongWriters'],
                            'composers'   => $affected['tblSongComposers'],
                            'arrangers'   => $affected['tblSongArrangers'],
                            'adaptors'    => $affected['tblSongAdaptors'],
                            'translators' => $affected['tblSongTranslators'],
                        ],
                    ]);
                    $totalRenamed = array_sum($affected);
                    $success = "Renamed '{$oldName}' → '{$newName}' across {$totalRenamed} song-credit row(s).";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            /* --------------------------------------------------------
             * merge — collapse two registry entries into one.
             *
             * Re-points every song-credit row from the source name to
             * the target name across all five tables, then deletes
             * the source registry row. ON DELETE CASCADE on the two
             * child tables removes the source's links + IPI rows.
             *
             * The admin selected which links / IPI rows to keep on
             * the surviving target via keep_links[] / keep_ipi[]
             * checkboxes — defaults to "keep all from both sides
             * with exact-match dedupe" which the JS pre-ticks.
             *
             * For the kept child rows from the source side, we
             * re-point CreditPersonId from source to target BEFORE
             * deleting the source row. That avoids the cascade
             * dropping rows we wanted to migrate.
             * -------------------------------------------------------- */
            case 'merge': {
                $sourceId   = (int)($_POST['source_id'] ?? 0);
                $targetId   = (int)($_POST['target_id'] ?? 0);
                $sourceName = trim((string)($_POST['source_name'] ?? ''));
                $targetName = trim((string)($_POST['target_name'] ?? ''));
                $keepLinks  = array_map('intval', (array)($_POST['keep_link_ids'] ?? []));
                $keepIpi    = array_map('intval', (array)($_POST['keep_ipi_ids']  ?? []));

                /* In-use-only merge support (#626) — the JS posts a
                   *_name fallback when a row isn't yet in the registry
                   (e.g. Stuart Townend duplicates). Auto-register
                   either side as needed so the rest of the merge code
                   path can assume both sides have a registry id. */
                $resolvePersonId = static function (int $id, string $name) use ($db): int {
                    if ($id > 0)       return $id;
                    if ($name === '')  return 0;
                    $stmt = $db->prepare('SELECT Id FROM tblCreditPeople WHERE Name = ?');
                    $stmt->bind_param('s', $name);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_row();
                    $stmt->close();
                    if ($row) return (int)$row[0];
                    $ins = $db->prepare('INSERT INTO tblCreditPeople (Name) VALUES (?)');
                    $ins->bind_param('s', $name);
                    $ins->execute();
                    $newId = (int)$db->insert_id;
                    $ins->close();
                    return $newId;
                };
                $sourceId = $resolvePersonId($sourceId, $sourceName);
                $targetId = $resolvePersonId($targetId, $targetName);

                if ($sourceId <= 0 || $targetId <= 0) { $error = 'Both source and target are required.'; break; }
                if ($sourceId === $targetId)          { $error = 'Source and target must be different people.'; break; }

                /* Look up both rows. Source name is what we cascade
                   in the song-credit tables; target name is the
                   surviving spelling. */
                $stmt = $db->prepare('SELECT Id, Name FROM tblCreditPeople WHERE Id IN (?, ?)');
                $stmt->bind_param('ii', $sourceId, $targetId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $byId = [];
                foreach ($rows as $r) { $byId[(int)$r['Id']] = (string)$r['Name']; }
                if (!isset($byId[$sourceId])) { $error = 'Source person not found.'; break; }
                if (!isset($byId[$targetId])) { $error = 'Target person not found.'; break; }
                $sourceName = $byId[$sourceId];
                $targetName = $byId[$targetId];

                $db->begin_transaction();
                try {
                    /* Re-point song-credit rows: source name → target
                       name across all five tables. */
                    $tables = [
                        'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
                        'tblSongAdaptors', 'tblSongTranslators',
                    ];
                    $affected = [];
                    foreach ($tables as $tbl) {
                        $stmt = $db->prepare("UPDATE {$tbl} SET Name = ? WHERE Name = ?");
                        $stmt->bind_param('ss', $targetName, $sourceName);
                        $stmt->execute();
                        $affected[$tbl] = $stmt->affected_rows;
                        $stmt->close();
                    }

                    /* Migrate the chosen child rows from source →
                       target. Anything not in keep_link_ids / keep_ipi_ids
                       gets dropped via the cascade when the source
                       registry row is deleted below. */
                    $linksKept = 0;
                    $linksDropped = 0;
                    $ipiKept = 0;
                    $ipiDropped = 0;

                    /* Count links currently on the source so we can report
                       kept-vs-dropped accurately. */
                    $stmt = $db->prepare('SELECT Id FROM tblCreditPersonLinks WHERE CreditPersonId = ?');
                    $stmt->bind_param('i', $sourceId);
                    $stmt->execute();
                    $sourceLinkIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Id');
                    $stmt->close();

                    $stmt = $db->prepare('SELECT Id FROM tblCreditPersonIPI WHERE CreditPersonId = ?');
                    $stmt->bind_param('i', $sourceId);
                    $stmt->execute();
                    $sourceIpiIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Id');
                    $stmt->close();

                    /* Re-point each kept child row. Anything from
                       keep_links that isn't actually on the source is
                       silently ignored — the form might be stale. */
                    if ($keepLinks && $sourceLinkIds) {
                        $toMove = array_intersect($keepLinks, array_map('intval', $sourceLinkIds));
                        if ($toMove) {
                            $upd = $db->prepare(
                                'UPDATE tblCreditPersonLinks SET CreditPersonId = ? WHERE Id = ? AND CreditPersonId = ?'
                            );
                            foreach ($toMove as $lid) {
                                $upd->bind_param('iii', $targetId, $lid, $sourceId);
                                $upd->execute();
                                $linksKept += $upd->affected_rows;
                            }
                            $upd->close();
                        }
                    }
                    $linksDropped = max(0, count($sourceLinkIds) - $linksKept);

                    if ($keepIpi && $sourceIpiIds) {
                        $toMove = array_intersect($keepIpi, array_map('intval', $sourceIpiIds));
                        if ($toMove) {
                            $upd = $db->prepare(
                                'UPDATE tblCreditPersonIPI SET CreditPersonId = ? WHERE Id = ? AND CreditPersonId = ?'
                            );
                            foreach ($toMove as $iid) {
                                $upd->bind_param('iii', $targetId, $iid, $sourceId);
                                $upd->execute();
                                $ipiKept += $upd->affected_rows;
                            }
                            $upd->close();
                        }
                    }
                    $ipiDropped = max(0, count($sourceIpiIds) - $ipiKept);

                    /* Drop the source registry row. Cascade removes any
                       child rows the admin chose not to migrate. */
                    $stmt = $db->prepare('DELETE FROM tblCreditPeople WHERE Id = ?');
                    $stmt->bind_param('i', $sourceId);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();

                    $logCreditPerson('merge', (string)$targetId, [
                        'source'     => ['id' => $sourceId, 'name' => $sourceName],
                        'target'     => ['id' => $targetId, 'name' => $targetName],
                        'affected'   => [
                            'writers'     => $affected['tblSongWriters'],
                            'composers'   => $affected['tblSongComposers'],
                            'arrangers'   => $affected['tblSongArrangers'],
                            'adaptors'    => $affected['tblSongAdaptors'],
                            'translators' => $affected['tblSongTranslators'],
                        ],
                        'child_rows' => [
                            'links_kept'    => $linksKept,
                            'links_dropped' => $linksDropped,
                            'ipi_kept'      => $ipiKept,
                            'ipi_dropped'   => $ipiDropped,
                        ],
                    ]);
                    $totalRenamed = array_sum($affected);
                    $success = "Merged '{$sourceName}' → '{$targetName}' ({$totalRenamed} song-credit row(s) re-pointed).";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            /* --------------------------------------------------------
             * delete_from_registry — remove a registry row.
             *
             * Refuses by default if any song-credit table still cites
             * the name. The admin can override with force=1 to wipe
             * a registry row whose name is still in use; this is the
             * "drop a seed entry that was never adopted" use case the
             * issue body mentions.
             * -------------------------------------------------------- */
            case 'delete_from_registry': {
                $id    = (int)($_POST['id']    ?? 0);
                $force = !empty($_POST['force']);
                if ($id <= 0) { $error = 'Person id missing.'; break; }

                $stmt = $db->prepare('SELECT Name FROM tblCreditPeople WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') { $error = 'Person not found.'; break; }

                /* Count how many song-credit rows still cite this name
                   across the five tables — a single UNION ALL keeps
                   the round-trip count to one. */
                $stmt = $db->prepare(
                    "SELECT (
                        (SELECT COUNT(*) FROM tblSongWriters     WHERE Name = ?) +
                        (SELECT COUNT(*) FROM tblSongComposers   WHERE Name = ?) +
                        (SELECT COUNT(*) FROM tblSongArrangers   WHERE Name = ?) +
                        (SELECT COUNT(*) FROM tblSongAdaptors    WHERE Name = ?) +
                        (SELECT COUNT(*) FROM tblSongTranslators WHERE Name = ?)
                     ) AS total"
                );
                $stmt->bind_param('sssss', $name, $name, $name, $name, $name);
                $stmt->execute();
                $usage = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();

                if ($usage > 0 && !$force) {
                    $error = "Cannot delete '{$name}': {$usage} song-credit row(s) still cite this name. Tick the override box to delete anyway (the song credits stay — only the registry row is removed).";
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblCreditPeople WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                $logCreditPerson('delete_from_registry', (string)$id, [
                    'name'         => $name,
                    'had_credits'  => $usage > 0,
                    'force'        => $force,
                ]);
                $success = "Registry entry for '{$name}' removed.";
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/credit-people.php] action=' . $action . ': ' . $e->getMessage());
        if ($error === '') {
            /* This page is gated to global_admin — surfacing the
               actual exception message + file:line is a UX win and
               carries no security trade-off (global admins are
               trusted with DB internals). Closes #635. */
            $where = $e->getFile() ? (' ' . basename($e->getFile()) . ':' . $e->getLine()) : '';
            $error = 'Database error: ' . $e->getMessage() . $where;
        }
    }
}

/* ----------------------------------------------------------------------
 * Data load
 *
 * Two queries, merged in PHP. Cleaner than a hand-rolled FULL OUTER
 * JOIN simulation, and the row count on these tables is small enough
 * that the merge cost is negligible.
 *
 *   Q1 — every distinct name across the five song-credit tables,
 *        with per-role counts and a total. Names not currently in
 *        any registry row also surface here.
 *   Q2 — every registry row in tblCreditPeople plus its biographical
 *        metadata + child-table counts. Registry rows that no song
 *        currently cites also surface here.
 * ---------------------------------------------------------------------- */

$people = [];

try {
    $db = getDbMysqli();

    /* Q1 — usage aggregate across the five song-credit tables. */
    $usageSql = "
        SELECT Name,
               SUM(IF(kindLabel = 'writer',     cnt, 0)) AS WriterCount,
               SUM(IF(kindLabel = 'composer',   cnt, 0)) AS ComposerCount,
               SUM(IF(kindLabel = 'arranger',   cnt, 0)) AS ArrangerCount,
               SUM(IF(kindLabel = 'adaptor',    cnt, 0)) AS AdaptorCount,
               SUM(IF(kindLabel = 'translator', cnt, 0)) AS TranslatorCount,
               SUM(cnt) AS TotalUsage
          FROM (
              SELECT Name, 'writer'     AS kindLabel, COUNT(*) AS cnt FROM tblSongWriters     GROUP BY Name
              UNION ALL
              SELECT Name, 'composer'   AS kindLabel, COUNT(*) AS cnt FROM tblSongComposers   GROUP BY Name
              UNION ALL
              SELECT Name, 'arranger'   AS kindLabel, COUNT(*) AS cnt FROM tblSongArrangers   GROUP BY Name
              UNION ALL
              SELECT Name, 'adaptor'    AS kindLabel, COUNT(*) AS cnt FROM tblSongAdaptors    GROUP BY Name
              UNION ALL
              SELECT Name, 'translator' AS kindLabel, COUNT(*) AS cnt FROM tblSongTranslators GROUP BY Name
          ) u
         GROUP BY Name
    ";
    $stmt = $db->prepare($usageSql);
    $stmt->execute();
    $usageRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Q2 — registry rows + child-table counts (links, IPI). The
       sub-selects keep the query a single round-trip; if either child
       table grows large enough that the per-row sub-select cost shows
       up, swap to a GROUP BY join. Not now — current row counts make
       this trivially fast. */
    /* Build the SELECT with the classification flags from #584 / #585.
       The flags are loaded as 0/1 ints so the PHP-side merge below
       can pass them straight to JSON without further casting; the
       table renderer guards against missing columns when running on
       a schema that hasn't yet had migrate-credit-people-flags
       applied. */
    $hasFlags = false;
    $colCheck = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblCreditPeople'
            AND COLUMN_NAME  = 'IsSpecialCase'"
    );
    if ($colCheck && $colCheck->fetch_row() !== null) { $hasFlags = true; }
    if ($colCheck) { $colCheck->close(); }

    $flagCols = $hasFlags
        ? ', p.IsSpecialCase, p.IsGroup'
        : ', 0 AS IsSpecialCase, 0 AS IsGroup';

    $registrySql = "
        SELECT p.Id,
               p.Name,
               p.Notes,
               p.BirthPlace,
               p.BirthDate,
               p.DeathPlace,
               p.DeathDate,
               p.UpdatedAt,
               (SELECT COUNT(*) FROM tblCreditPersonLinks l WHERE l.CreditPersonId = p.Id) AS LinkCount,
               (SELECT COUNT(*) FROM tblCreditPersonIPI   i WHERE i.CreditPersonId = p.Id) AS IPICount
               {$flagCols}
          FROM tblCreditPeople p
    ";
    $stmt = $db->prepare($registrySql);
    $stmt->execute();
    $registryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Q3 + Q4 — pull every link / IPI row across all people so the
       Edit drawer can pre-fill without an extra round-trip. The two
       child tables are small (a handful of rows per person, low
       hundreds total), so loading them all is cheaper than fetching
       per-person on demand from JS. */
    $linksByPerson = [];
    $ipiByPerson   = [];
    $stmt = $db->prepare(
        'SELECT Id, CreditPersonId, LinkType, Url, Label, SortOrder
           FROM tblCreditPersonLinks
          ORDER BY CreditPersonId ASC, SortOrder ASC, Id ASC'
    );
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $l) {
        $linksByPerson[(int)$l['CreditPersonId']][] = [
            'id'         => (int)$l['Id'],
            'type'       => (string)$l['LinkType'],
            'url'        => (string)$l['Url'],
            'label'      => $l['Label'],
            'sort_order' => (int)$l['SortOrder'],
        ];
    }
    $stmt->close();

    $stmt = $db->prepare(
        'SELECT Id, CreditPersonId, IPINumber, NameUsed, Notes
           FROM tblCreditPersonIPI
          ORDER BY CreditPersonId ASC, Id ASC'
    );
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $ipiByPerson[(int)$r['CreditPersonId']][] = [
            'id'        => (int)$r['Id'],
            'number'    => (string)$r['IPINumber'],
            'name_used' => $r['NameUsed'],
            'notes'     => $r['Notes'],
        ];
    }
    $stmt->close();

    /* Merge — keyed by Name. A name may appear in usage only,
       registry only, or both. */
    $byName = [];
    foreach ($usageRows as $u) {
        $name = (string)$u['Name'];
        $byName[$name] = [
            'name'        => $name,
            'writers'     => (int)$u['WriterCount'],
            'composers'   => (int)$u['ComposerCount'],
            'arrangers'   => (int)$u['ArrangerCount'],
            'adaptors'    => (int)$u['AdaptorCount'],
            'translators' => (int)$u['TranslatorCount'],
            'total'       => (int)$u['TotalUsage'],
            'registry_id' => null,
            'notes'       => null,
            'birth_place' => null,
            'birth_date'  => null,
            'death_place' => null,
            'death_date'  => null,
            'updated_at'  => null,
            'link_count'  => 0,
            'ipi_count'   => 0,
            'is_special_case' => 0,   /* #584 */
            'is_group'        => 0,   /* #585 */
            'links'       => [],
            'ipi'         => [],
        ];
    }
    foreach ($registryRows as $r) {
        $name = (string)$r['Name'];
        if (!isset($byName[$name])) {
            /* Registry-only — no song currently cites this person. */
            $byName[$name] = [
                'name'        => $name,
                'writers'     => 0,
                'composers'   => 0,
                'arrangers'   => 0,
                'adaptors'    => 0,
                'translators' => 0,
                'total'       => 0,
                'registry_id' => null,
                'notes'       => null,
                'birth_place' => null,
                'birth_date'  => null,
                'death_place' => null,
                'death_date'  => null,
                'updated_at'  => null,
                'link_count'  => 0,
                'ipi_count'   => 0,
                'is_special_case' => 0,
                'is_group'        => 0,
            ];
        }
        $byName[$name]['registry_id'] = (int)$r['Id'];
        $byName[$name]['notes']       = $r['Notes'];
        $byName[$name]['birth_place'] = $r['BirthPlace'];
        $byName[$name]['birth_date']  = $r['BirthDate'];
        $byName[$name]['death_place'] = $r['DeathPlace'];
        $byName[$name]['death_date']  = $r['DeathDate'];
        $byName[$name]['updated_at']  = $r['UpdatedAt'];
        $byName[$name]['link_count']  = (int)$r['LinkCount'];
        $byName[$name]['ipi_count']   = (int)$r['IPICount'];
        $byName[$name]['is_special_case'] = (int)($r['IsSpecialCase'] ?? 0);
        $byName[$name]['is_group']        = (int)($r['IsGroup']        ?? 0);
        /* Full child rows for the Edit drawer's pre-fill. Empty arrays
           default for registry rows with no children — the drawer's JS
           handles the empty case as "no rows yet". */
        $byName[$name]['links'] = $linksByPerson[(int)$r['Id']] ?? [];
        $byName[$name]['ipi']   = $ipiByPerson[(int)$r['Id']]   ?? [];
    }

    /* Sort: highest-usage first, then alphabetical. Registry-only
       entries (total = 0) bubble to the bottom inside their alpha
       block — easy for the curator to spot which pre-registered
       names are still waiting on a song to cite them. */
    uasort($byName, static function (array $a, array $b): int {
        if ($a['total'] !== $b['total']) {
            return $b['total'] <=> $a['total'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    $people = array_values($byName);
} catch (\Throwable $e) {
    error_log('[manage/credit-people.php] load failed: ' . $e->getMessage());
    $error = 'Could not load credit people — check server logs for details.';
}

/* ----------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Render a compact "b. 1725 · d. 1807" lifespan, or '' if neither known. */
$lifespan = static function (?string $birth, ?string $death): string {
    $bYear = $birth ? substr($birth, 0, 4) : '';
    $dYear = $death ? substr($death, 0, 4) : '';
    if ($bYear === '' && $dYear === '') return '';
    if ($bYear !== '' && $dYear === '') return 'b. ' . htmlspecialchars($bYear);
    if ($bYear === '' && $dYear !== '') return 'd. ' . htmlspecialchars($dYear);
    return 'b. ' . htmlspecialchars($bYear) . ' · d. ' . htmlspecialchars($dYear);
};

/** Source badge: registry-only, in-use only, or both. */
$sourceBadge = static function (array $p): string {
    $inRegistry = $p['registry_id'] !== null;
    $inUse      = $p['total'] > 0;
    if ($inRegistry && $inUse) return '<span class="badge bg-success-subtle text-success-emphasis">Both</span>';
    if ($inRegistry)           return '<span class="badge bg-info-subtle text-info-emphasis">Registry</span>';
    return '<span class="badge bg-secondary-subtle text-secondary-emphasis">In use</span>';
};

$totalNames           = count($people);
$totalInRegistry      = count(array_filter($people, static fn($p) => $p['registry_id'] !== null));
$totalInUse           = count(array_filter($people, static fn($p) => $p['total'] > 0));
$totalRegistryOnly    = $totalNames - $totalInUse;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit People — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        /* Per-role badge colours. Picked to match the editor's chip
           colours so an admin who jumps between this page and the
           Song Editor has visual continuity. */
        .role-pill            { font-variant-numeric: tabular-nums; }
        .role-pill.role-w     { background-color: #1f6feb33; color: #79b8ff; }
        .role-pill.role-c     { background-color: #6f42c133; color: #b392f0; }
        .role-pill.role-ar    { background-color: #fb950033; color: #ffab40; }
        .role-pill.role-ad    { background-color: #2ea04333; color: #56d364; }
        .role-pill.role-t     { background-color: #d73a4933; color: #ff7b72; }
        .role-pill[data-zero] { opacity: 0.25; }

        /* Person-name column gets a slightly larger, slightly bolder
           treatment so the eye lands on it first. */
        td.person-name        { font-weight: 500; }
        td.person-name code   { font-size: 0.85em; opacity: 0.6; }

        /* Lifespan + link/IPI badge alignment. */
        td.meta-col           { white-space: nowrap; font-size: 0.85em; opacity: 0.85; }
        .badge-icon-count     { font-variant-numeric: tabular-nums; }

        /* Active filter button + search-empty placeholder row. */
        .filter-btn.active    { background-color: var(--bs-primary); color: #fff; border-color: var(--bs-primary); }
        tr.no-match           { display: none; }
        .empty-row td         { text-align: center; padding: 2rem; opacity: 0.6; }
    </style>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-2"><i class="bi bi-person-badge me-2"></i>Credit People</h1>
        <p class="text-secondary small mb-3">
            Every individual credited as a writer, composer, arranger, adaptor or
            translator across the catalogue, plus every pre-registered name in
            <code>tblCreditPeople</code>. Use this view to spot duplicates that
            need merging (e.g. <code>J. Newton</code> vs <code>John Newton</code>)
            or registry-only names that are waiting on a song to cite them.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Summary tiles -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">Total distinct names</div>
                    <div class="h5 mb-0"><?= number_format($totalNames) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">Cited by &ge; 1 song</div>
                    <div class="h5 mb-0"><?= number_format($totalInUse) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">In registry</div>
                    <div class="h5 mb-0"><?= number_format($totalInRegistry) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">Registry-only (not yet used)</div>
                    <div class="h5 mb-0"><?= number_format($totalRegistryOnly) ?></div>
                </div>
            </div>
        </div>

        <!-- Search + filters -->
        <div class="card bg-dark border-secondary p-3 mb-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <label for="cp-search" class="visually-hidden">Search names</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="cp-search"
                               placeholder="Filter by name, lifespan, notes…" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" id="cp-search-clear" title="Clear">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Filter by role">
                        <button type="button" class="btn btn-outline-secondary filter-btn active" data-filter="all">All</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="writer">Writers</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="composer">Composers</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="arranger">Arrangers</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="adaptor">Adaptors</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="translator">Translators</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="registry-only">Registry-only</button>
                    </div>
                </div>
                <div class="col-md-2 text-md-end">
                    <button type="button" class="btn btn-amber-solid btn-sm" id="cp-add-btn">
                        <i class="bi bi-plus-circle me-1"></i>Add person
                    </button>
                </div>
            </div>
        </div>

        <!-- People table -->
        <div class="card bg-dark border-secondary p-2 mb-3">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="text-muted small">
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col" class="text-center">Roles</th>
                            <th scope="col" class="text-end">Total uses</th>
                            <th scope="col">Source</th>
                            <th scope="col">Lifespan</th>
                            <th scope="col" class="text-end">Meta</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="cp-tbody">
                        <?php foreach ($people as $p):
                            $writers     = $p['writers'];
                            $composers   = $p['composers'];
                            $arrangers   = $p['arrangers'];
                            $adaptors    = $p['adaptors'];
                            $translators = $p['translators'];
                            $rolesCsv    = implode(',', array_filter([
                                $writers     ? 'writer'     : '',
                                $composers   ? 'composer'   : '',
                                $arrangers   ? 'arranger'   : '',
                                $adaptors    ? 'adaptor'    : '',
                                $translators ? 'translator' : '',
                            ]));
                            $isRegistryOnly = $p['total'] === 0;
                            $haystack = strtolower(implode(' ', array_filter([
                                $p['name'],
                                $lifespan($p['birth_date'], $p['death_date']),
                                (string)$p['birth_place'],
                                (string)$p['death_place'],
                                (string)$p['notes'],
                            ])));
                            /* Embed the full person payload in a data
                               attribute so the Edit button can pre-fill
                               the drawer without an extra round-trip.
                               JSON_HEX_* flags + ENT_QUOTES on the
                               attribute container keep apostrophes /
                               quotes / angle brackets safe. Nulls in the
                               PHP array become null literals in JSON. */
                            $personJson = json_encode([
                                'registry_id' => $p['registry_id'],
                                'name'        => $p['name'],
                                'notes'       => $p['notes'],
                                'birth_place' => $p['birth_place'],
                                'birth_date'  => $p['birth_date'],
                                'death_place' => $p['death_place'],
                                'death_date'  => $p['death_date'],
                                'is_special_case' => (int)($p['is_special_case'] ?? 0),
                                'is_group'        => (int)($p['is_group']        ?? 0),
                                /* Per-role counts so the Merge modal's
                                   "Song-credit rows to re-point" preview
                                   (#583) can render without a round-trip. */
                                'writers'     => $writers,
                                'composers'   => $composers,
                                'arrangers'   => $arrangers,
                                'adaptors'    => $adaptors,
                                'translators' => $translators,
                                'total'       => $p['total'],
                                'links'       => $p['links'],
                                'ipi'         => $p['ipi'],
                            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                            $isSpecial = !empty($p['is_special_case']);
                            $isGroup   = !empty($p['is_group']);
                        ?>
                            <tr data-roles="<?= htmlspecialchars($rolesCsv) ?>"
                                data-registry-only="<?= $isRegistryOnly ? '1' : '0' ?>"
                                data-haystack="<?= htmlspecialchars($haystack) ?>"
                                data-classification="<?= $isSpecial ? 'special' : ($isGroup ? 'group' : 'individual') ?>"
                                data-person='<?= htmlspecialchars($personJson, ENT_QUOTES) ?>'>
                                <td class="person-name <?= $isSpecial ? 'fst-italic' : '' ?>">
                                    <?php if ($isGroup): ?>
                                        <i class="bi bi-people-fill text-info me-1" title="Group / band / collective" aria-label="Group"></i>
                                    <?php elseif ($isSpecial): ?>
                                        <i class="bi bi-question-circle text-warning me-1" title="Special-case attribution" aria-label="Special case"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($p['name']) ?>
                                    <?php if ($isSpecial): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis ms-1" style="font-size: 0.6rem;">Special case</span>
                                    <?php endif; ?>
                                    <?php if ($isGroup): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis ms-1" style="font-size: 0.6rem;">Group</span>
                                    <?php endif; ?>
                                    <?php if ($p['notes']): ?>
                                        <span class="text-secondary small ms-2" title="<?= htmlspecialchars($p['notes']) ?>">
                                            <i class="bi bi-sticky"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge role-pill role-w"  <?= $writers     ? '' : 'data-zero' ?>>W&middot;<?= $writers ?></span>
                                    <span class="badge role-pill role-c"  <?= $composers   ? '' : 'data-zero' ?>>C&middot;<?= $composers ?></span>
                                    <span class="badge role-pill role-ar" <?= $arrangers   ? '' : 'data-zero' ?>>Ar&middot;<?= $arrangers ?></span>
                                    <span class="badge role-pill role-ad" <?= $adaptors    ? '' : 'data-zero' ?>>Ad&middot;<?= $adaptors ?></span>
                                    <span class="badge role-pill role-t"  <?= $translators ? '' : 'data-zero' ?>>T&middot;<?= $translators ?></span>
                                </td>
                                <td class="text-end"><strong><?= number_format($p['total']) ?></strong></td>
                                <td><?= $sourceBadge($p) ?></td>
                                <td class="meta-col">
                                    <?php $life = $lifespan($p['birth_date'], $p['death_date']);
                                          echo $life !== '' ? $life : '<span class="text-secondary">—</span>'; ?>
                                </td>
                                <td class="text-end meta-col">
                                    <?php if ($p['link_count'] > 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis badge-icon-count"
                                              title="<?= (int)$p['link_count'] ?> external link(s)">
                                            <i class="bi bi-link-45deg"></i> <?= (int)$p['link_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($p['ipi_count'] > 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis badge-icon-count"
                                              title="<?= (int)$p['ipi_count'] ?> IPI number(s)">
                                            IPI <?= (int)$p['ipi_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($p['link_count'] === 0 && $p['ipi_count'] === 0): ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end action-col">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Row actions">
                                        <?php
                                            /* Merge / Rename / Delete are available for any row that
                                               either has a registry entry OR has at least one credited
                                               use (#626). The Merge handler auto-registers source/target
                                               on submit if needed, so the UI no longer hides the action
                                               from in-use-only rows like the Stuart Townend duplicates. */
                                            $hasUse      = (int)$p['total'] > 0;
                                            $inRegistry  = $p['registry_id'] !== null;
                                            $hasActions  = $inRegistry || $hasUse;
                                        ?>
                                        <?php if (!$inRegistry && $hasUse): ?>
                                            <button type="button" class="btn btn-outline-info cp-edit-btn"
                                                    title="Add to registry — opens the detail drawer pre-filled with this person"
                                                    aria-label="Add <?= htmlspecialchars($p['name'], ENT_QUOTES) ?> to the registry">
                                                <i class="bi bi-plus-circle" aria-hidden="true"></i>
                                            </button>
                                        <?php elseif ($inRegistry): ?>
                                            <button type="button" class="btn btn-outline-info cp-edit-btn"
                                                    title="Edit person details"
                                                    aria-label="Edit person <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($hasActions): ?>
                                            <button type="button" class="btn btn-outline-warning cp-rename-btn"
                                                    title="Rename — cascades to every song that cites this person"
                                                    aria-label="Rename person <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-warning cp-merge-btn"
                                                    title="Merge into another person — combines two names into one"
                                                    aria-label="Merge person <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                                <i class="bi bi-union" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary cp-view-songs-btn"
                                                    title="View songs that cite this person"
                                                    aria-label="View songs that cite <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                                <i class="bi bi-music-note-list" aria-hidden="true"></i>
                                            </button>
                                            <?php if ($inRegistry): ?>
                                                <button type="button" class="btn btn-outline-danger cp-delete-btn"
                                                        title="Remove from registry"
                                                        aria-label="Remove <?= htmlspecialchars($p['name'], ENT_QUOTES) ?> from the registry">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="empty-row" id="cp-empty-row" style="display:none;">
                            <td colspan="7">No names match the current search / filter.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- =========================================================================
         Delete-from-registry modal — removes the registry row only.
         Shows a force-anyway checkbox when the person is still cited
         by at least one song; the song credits stay either way (they
         live in the five song-credit tables, not the registry).
         ========================================================================= -->
    <div class="modal fade" id="cpDeleteModal" tabindex="-1" aria-labelledby="cpDeleteLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content bg-dark border-secondary">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="delete_from_registry">
                    <input type="hidden" name="id" id="cp-delete-id" value="">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title" id="cpDeleteLabel">
                            <i class="bi bi-trash me-2"></i>Remove from registry
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">
                            Remove the registry row for
                            <strong id="cp-delete-name"></strong>?
                        </p>

                        <div id="cp-delete-in-use" class="d-none alert alert-warning py-2 small mb-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This person is still cited by
                            <strong id="cp-delete-usage-count"></strong>
                            song-credit row(s). The song credits stay either way —
                            this only removes the registry entry's biographical
                            metadata, links and IPI numbers.
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="cp-delete-force" name="force" value="1">
                                <label class="form-check-label" for="cp-delete-force">
                                    Yes, remove the registry row anyway
                                </label>
                            </div>
                        </div>

                        <div id="cp-delete-not-in-use" class="d-none alert alert-secondary py-2 small mb-0">
                            No songs currently cite this person — safe to remove.
                            Deletes the registry row plus its links / IPI rows
                            (cascade).
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger btn-sm" id="cp-delete-submit">
                            <i class="bi bi-trash me-1"></i>Remove
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         View Songs modal — read-only listing of every song that cites
         the person, grouped by role. Lazy-loaded from
         GET ?action=view_songs&id=<id>.
         ========================================================================= -->
    <div class="modal fade" id="cpViewSongsModal" tabindex="-1" aria-labelledby="cpViewSongsLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content bg-dark border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="cpViewSongsLabel">
                        <i class="bi bi-music-note-list me-2"></i>
                        Songs citing <strong id="cp-view-songs-name"></strong>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="cp-view-songs-loading" class="text-center py-3 text-secondary small d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Loading…
                    </div>
                    <div id="cp-view-songs-error" class="alert alert-danger py-2 small d-none"></div>
                    <div id="cp-view-songs-body"></div>
                    <div id="cp-view-songs-empty" class="alert alert-secondary py-2 small d-none">
                        No songs currently cite this person — the registry row
                        exists in isolation (pre-registered, or all citations
                        have been re-pointed elsewhere).
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         Rename modal — changes the canonical name + cascades to the
         five song-credit tables.
         ========================================================================= -->
    <div class="modal fade" id="cpRenameModal" tabindex="-1" aria-labelledby="cpRenameLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content bg-dark border-secondary">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="id" id="cp-rename-id" value="">
                    <!-- #626 — fallback for in-use-only rows; the server
                         auto-registers by name when id is empty. -->
                    <input type="hidden" name="source_name" id="cp-rename-source-name" value="">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title" id="cpRenameLabel">
                            <i class="bi bi-pencil-square me-2"></i>Rename person
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label small">Current name</label>
                            <input type="text" class="form-control form-control-sm" id="cp-rename-current" readonly>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small" for="cp-rename-new">New name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="cp-rename-new" name="new_name" maxlength="255" required>
                        </div>
                        <div class="alert alert-warning py-2 small mb-0" id="cp-rename-impact">
                            Renaming will update the spelling on every song that currently cites this person.
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning btn-sm">
                            <i class="bi bi-pencil-square me-1"></i>Rename and cascade
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         Merge modal — collapses two registry rows into one. The admin
         picks a target from the dropdown of all other registry rows;
         the source's links / IPI are listed with checkboxes (default
         checked) so the admin can drop duplicates before they migrate.
         ========================================================================= -->
    <div class="modal fade" id="cpMergeModal" tabindex="-1" aria-labelledby="cpMergeLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark border-secondary">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="merge">
                    <input type="hidden" name="source_id" id="cp-merge-source-id" value="">
                    <!-- #626 — name fallbacks for in-use-only rows; the
                         server auto-registers them on submit. The select
                         element below carries an "id:N" / "name:X" key
                         that the submit handler routes into either
                         target_id or target_name before posting. -->
                    <input type="hidden" name="source_name" id="cp-merge-source-name-hidden" value="">
                    <input type="hidden" name="target_id"   id="cp-merge-target-id-hidden"   value="">
                    <input type="hidden" name="target_name" id="cp-merge-target-name-hidden" value="">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title" id="cpMergeLabel">
                            <i class="bi bi-union me-2"></i>Merge person into another
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small">Source (will be removed)</label>
                                <input type="text" class="form-control form-control-sm" id="cp-merge-source-name" readonly>
                            </div>
                            <div class="col-6">
                                <label class="form-label small" for="cp-merge-target">Target (survives) <span class="text-danger">*</span></label>
                                <!-- name omitted — the picked option's
                                     value is an "id:N" / "name:X" key
                                     that submit JS routes to either
                                     target_id or target_name (#626). -->
                                <select class="form-select form-select-sm" id="cp-merge-target" required>
                                    <option value="">— pick the surviving person —</option>
                                </select>
                            </div>
                        </div>

                        <!-- Song-credit re-point preview (#583). The Source
                             aggregate already carries per-role counts in its
                             data-person attribute, so we render them inline
                             when the modal opens — no extra round-trip. -->
                        <div id="cp-merge-credit-preview" class="alert alert-info py-2 small mb-3 d-none">
                            <div class="fw-semibold mb-1">
                                <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>
                                Song-credit rows to re-point
                            </div>
                            <div class="d-flex flex-wrap gap-2 small">
                                <span>Writer&nbsp;<strong id="cp-merge-count-writer">0</strong></span>
                                <span>Composer&nbsp;<strong id="cp-merge-count-composer">0</strong></span>
                                <span>Arranger&nbsp;<strong id="cp-merge-count-arranger">0</strong></span>
                                <span>Adaptor&nbsp;<strong id="cp-merge-count-adaptor">0</strong></span>
                                <span>Translator&nbsp;<strong id="cp-merge-count-translator">0</strong></span>
                                <span class="ms-auto">Total&nbsp;<strong id="cp-merge-count-total">0</strong></span>
                            </div>
                        </div>

                        <div id="cp-merge-children" class="d-none">
                            <h6 class="small text-secondary mb-2">Source's links / IPI numbers</h6>
                            <p class="small text-secondary">
                                Untick any rows you want to drop. Anything ticked moves to the target;
                                anything unticked is removed when the source row is deleted.
                                The target's own links / IPI are unaffected.
                            </p>
                            <div id="cp-merge-children-empty" class="alert alert-secondary py-2 small mb-2 d-none">
                                Source has no links or IPI numbers — nothing to migrate.
                            </div>
                            <div id="cp-merge-children-links"  class="mb-2"></div>
                            <div id="cp-merge-children-ipi"></div>
                        </div>

                        <div class="alert alert-warning py-2 small mb-2">
                            Merging re-points every song-credit row from the source name to the target
                            name across the five song-credit tables, then removes the source registry
                            row. Tracked in the activity log with full per-table affected counts.
                        </div>

                        <!-- Explicit irreversibility ack (#583). The submit
                             button stays disabled until both a target is
                             picked AND this checkbox is ticked. -->
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="cp-merge-confirm" required>
                            <label class="form-check-label small" for="cp-merge-confirm">
                                I understand this is irreversible — the source registry row is
                                deleted and every song-credit row is re-pointed to the target name.
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning btn-sm" id="cp-merge-submit" disabled>
                            <i class="bi bi-union me-1"></i>Merge
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         Person detail drawer — drives both Add and Update_person actions.
         Bootstrap right-side offcanvas with a form inside; the form's
         method/action are fixed (POST to this page); the action input
         and id input switch between 'add' / 'update_person' depending
         on which button opened the drawer.
         ========================================================================= -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="cpDrawer" aria-labelledby="cpDrawerLabel" style="width: min(560px, 95vw);">
        <div class="offcanvas-header border-bottom border-secondary">
            <h5 class="offcanvas-title" id="cpDrawerLabel">
                <i class="bi bi-person-badge me-2"></i>
                <span id="cp-drawer-title">Add person</span>
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <form method="POST" id="cp-drawer-form" class="offcanvas-body d-flex flex-column gap-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" id="cp-drawer-action" value="add">
            <input type="hidden" name="id"     id="cp-drawer-id"     value="">

            <!-- Identity -->
            <div>
                <label class="form-label small mb-1" for="cp-drawer-name">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" id="cp-drawer-name" name="name" maxlength="255" required>
                <div class="form-text small" id="cp-drawer-name-help">
                    The canonical spelling. To rename, save first, then use the Rename action — renames cascade to every song that cites this person.
                </div>
            </div>

            <!-- Classification flags (#584 / #585). Mutually exclusive
                 by design — both are unticked for an individual. The
                 JS below toggles them so ticking one unticks the
                 other, and adapts the date-field labels for groups
                 (Birth/Death → Founded/Dissolved). -->
            <div class="border rounded p-2" style="border-color: var(--bs-border-color) !important;">
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" name="is_special_case" value="1" id="cp-drawer-is-special-case">
                    <label class="form-check-label small" for="cp-drawer-is-special-case">
                        <i class="bi bi-question-circle me-1" aria-hidden="true"></i>
                        Special case (Anonymous, Traditional, etc.)
                    </label>
                </div>
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" name="is_group" value="1" id="cp-drawer-is-group">
                    <label class="form-check-label small" for="cp-drawer-is-group">
                        <i class="bi bi-people-fill me-1" aria-hidden="true"></i>
                        Group / band / collective
                    </label>
                </div>
            </div>

            <!-- Birth / Founded -->
            <div class="row g-2" data-flag-section="birth">
                <div class="col-7">
                    <label class="form-label small mb-1" for="cp-drawer-birth-place">
                        <span data-flag-label="individual">Birth place</span><span data-flag-label="group" class="d-none">Founded location</span>
                    </label>
                    <input type="text" class="form-control form-control-sm" id="cp-drawer-birth-place" name="birth_place" maxlength="255" placeholder="e.g. London, England">
                </div>
                <div class="col-5">
                    <label class="form-label small mb-1" for="cp-drawer-birth-date">
                        <span data-flag-label="individual">Birth date</span><span data-flag-label="group" class="d-none">Founded date</span>
                    </label>
                    <input type="date" class="form-control form-control-sm" id="cp-drawer-birth-date" name="birth_date">
                </div>
            </div>

            <!-- Death / Disbandment -->
            <div class="row g-2" data-flag-section="death">
                <div class="col-7">
                    <label class="form-label small mb-1" for="cp-drawer-death-place">
                        <span data-flag-label="individual">Death place</span><span data-flag-label="group" class="d-none">Disbandment location</span>
                    </label>
                    <input type="text" class="form-control form-control-sm" id="cp-drawer-death-place" name="death_place" maxlength="255">
                </div>
                <div class="col-5">
                    <label class="form-label small mb-1" for="cp-drawer-death-date">
                        <span data-flag-label="individual">Death date</span><span data-flag-label="group" class="d-none">Disbandment date</span>
                    </label>
                    <input type="date" class="form-control form-control-sm" id="cp-drawer-death-date" name="death_date">
                </div>
            </div>

            <!-- External links — repeating sub-form -->
            <div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">External links</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="cp-add-link-btn">
                        <i class="bi bi-plus me-1"></i>Add link
                    </button>
                </div>
                <div id="cp-links-container" class="d-flex flex-column gap-2"></div>
                <div class="form-text small">Pick a provider from the grouped list — General (Wikipedia, MusicBrainz, official site…), streaming services (Spotify, Apple Music…), social networks (Facebook, Instagram, YouTube…), or Other for anything else.</div>
            </div>

            <!-- IPI numbers — repeating sub-form -->
            <div data-flag-section="ipi">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">IPI Name Numbers</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="cp-add-ipi-btn">
                        <i class="bi bi-plus me-1"></i>Add IPI
                    </button>
                </div>
                <div id="cp-ipi-container" class="d-flex flex-column gap-2"></div>
                <div class="form-text small">A single individual can hold more than one IPI Name Number when they're registered under different performing names.</div>
            </div>

            <!-- Notes -->
            <div>
                <label class="form-label small mb-1" for="cp-drawer-notes">Notes</label>
                <textarea class="form-control form-control-sm" id="cp-drawer-notes" name="notes" rows="3" placeholder="Anything that doesn't fit the structured fields above."></textarea>
            </div>

            <!-- Footer -->
            <div class="d-flex justify-content-end gap-2 mt-auto pt-3 border-top border-secondary">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">Cancel</button>
                <button type="submit" class="btn btn-amber-solid btn-sm">
                    <i class="bi bi-save me-1"></i>Save
                </button>
            </div>
        </form>
    </div>

    <!-- Templates for the repeating sub-form rows. {i} placeholder gets
         replaced by the JS with the row's array index so PHP receives
         them as $_POST['links'][i][...] and $_POST['ipi'][i][...]. -->
    <template id="cp-link-row-template">
        <div class="d-flex gap-1 align-items-start cp-link-row" data-row-kind="link">
            <select class="form-select form-select-sm" style="min-width: 160px; max-width: 200px;" name="links[{i}][type]">
                <?php foreach ($LINK_TYPE_CATALOGUE as $groupLabel => $items): ?>
                    <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                        <?php foreach ($items as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>"<?= $key === 'official' ? ' selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <input type="url" class="form-control form-control-sm" name="links[{i}][url]" placeholder="https://…" required>
            <input type="text" class="form-control form-control-sm" style="max-width: 140px;" name="links[{i}][label]" placeholder="Label (optional)">
            <input type="hidden" name="links[{i}][sort_order]" value="{i}">
            <button type="button" class="btn btn-sm btn-outline-danger cp-row-remove" title="Remove this link" aria-label="Remove this link">
                <i class="bi bi-x" aria-hidden="true"></i>
            </button>
        </div>
    </template>
    <template id="cp-ipi-row-template">
        <div class="d-flex gap-1 align-items-start cp-ipi-row" data-row-kind="ipi">
            <input type="text" class="form-control form-control-sm" style="max-width: 140px;" name="ipi[{i}][number]" placeholder="IPI number" required>
            <input type="text" class="form-control form-control-sm" style="max-width: 180px;" name="ipi[{i}][name_used]" placeholder="Name used (optional)">
            <input type="text" class="form-control form-control-sm" name="ipi[{i}][notes]" placeholder="Notes (optional)">
            <button type="button" class="btn btn-sm btn-outline-danger cp-row-remove" title="Remove this IPI" aria-label="Remove this IPI">
                <i class="bi bi-x" aria-hidden="true"></i>
            </button>
        </div>
    </template>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

    <script>
        /* Client-side search + filter. The list is small enough that
           re-applying both predicates over every row on every keystroke
           is comfortably under a frame, no debouncing needed. If the
           list grows past ~5k rows, swap to a server-side endpoint. */
        (function () {
            const input    = document.getElementById('cp-search');
            const clearBtn = document.getElementById('cp-search-clear');
            const tbody    = document.getElementById('cp-tbody');
            const empty    = document.getElementById('cp-empty-row');
            const buttons  = document.querySelectorAll('.filter-btn');
            if (!input || !tbody) return;

            let activeFilter = 'all';

            function apply() {
                const q = input.value.trim().toLowerCase();
                let visibleCount = 0;
                const rows = tbody.querySelectorAll('tr:not(.empty-row)');
                rows.forEach(row => {
                    const haystack    = row.dataset.haystack || '';
                    const roles       = (row.dataset.roles || '').split(',').filter(Boolean);
                    const isRegOnly   = row.dataset.registryOnly === '1';
                    let matchRole = true;
                    if (activeFilter === 'registry-only') {
                        matchRole = isRegOnly;
                    } else if (activeFilter !== 'all') {
                        matchRole = roles.includes(activeFilter);
                    }
                    const matchSearch = q === '' || haystack.includes(q);
                    const show = matchRole && matchSearch;
                    row.classList.toggle('no-match', !show);
                    if (show) visibleCount++;
                });
                empty.style.display = visibleCount === 0 ? '' : 'none';
            }

            input.addEventListener('input', apply);
            clearBtn.addEventListener('click', () => { input.value = ''; input.focus(); apply(); });
            buttons.forEach(b => b.addEventListener('click', () => {
                buttons.forEach(x => x.classList.remove('active'));
                b.classList.add('active');
                activeFilter = b.dataset.filter || 'all';
                apply();
            }));
        })();

        /* =========================================================================
           Person detail drawer — drives Add and Update_person actions.
           Open from:
             - #cp-add-btn          → empty drawer, action=add
             - .cp-edit-btn (per-row) → pre-filled drawer
                                          - action=add if row has no registry_id
                                          - action=update_person otherwise
           ========================================================================= */
        (function () {
            const drawerEl  = document.getElementById('cpDrawer');
            const form      = document.getElementById('cp-drawer-form');
            const titleEl   = document.getElementById('cp-drawer-title');
            const nameHelp  = document.getElementById('cp-drawer-name-help');
            const actionIn  = document.getElementById('cp-drawer-action');
            const idIn      = document.getElementById('cp-drawer-id');
            const nameIn    = document.getElementById('cp-drawer-name');
            const linksBox  = document.getElementById('cp-links-container');
            const ipiBox    = document.getElementById('cp-ipi-container');
            const linkTpl   = document.getElementById('cp-link-row-template');
            const ipiTpl    = document.getElementById('cp-ipi-row-template');
            const addBtn    = document.getElementById('cp-add-btn');
            const addLinkBtn= document.getElementById('cp-add-link-btn');
            const addIpiBtn = document.getElementById('cp-add-ipi-btn');
            if (!drawerEl || !form) return;

            const drawer = bootstrap.Offcanvas.getOrCreateInstance(drawerEl);
            let linkIndex = 0;
            let ipiIndex  = 0;

            /* Append a new sub-form row, optionally pre-filled. The
               template's {i} placeholders get replaced with the
               current per-kind index so PHP receives the rows as a
               densely-keyed array. */
            function addLinkRow(prefill) {
                const html = linkTpl.innerHTML.replaceAll('{i}', String(linkIndex++));
                linksBox.insertAdjacentHTML('beforeend', html);
                const row = linksBox.lastElementChild;
                if (prefill) {
                    const sel = row.querySelector('select');
                    if (sel) sel.value = prefill.type || 'other';
                    const url = row.querySelector('input[type="url"]');
                    if (url) url.value = prefill.url || '';
                    const lbl = row.querySelector('input[name$="[label]"]');
                    if (lbl) lbl.value = prefill.label || '';
                    const ord = row.querySelector('input[name$="[sort_order]"]');
                    if (ord && prefill.sort_order !== undefined) ord.value = prefill.sort_order;
                }
                return row;
            }
            function addIpiRow(prefill) {
                const html = ipiTpl.innerHTML.replaceAll('{i}', String(ipiIndex++));
                ipiBox.insertAdjacentHTML('beforeend', html);
                const row = ipiBox.lastElementChild;
                if (prefill) {
                    row.querySelector('input[name$="[number]"]').value    = prefill.number    || '';
                    row.querySelector('input[name$="[name_used]"]').value = prefill.name_used || '';
                    row.querySelector('input[name$="[notes]"]').value     = prefill.notes     || '';
                }
                /* Re-apply the flag rules (#628) — ensures rows added
                   AFTER the modal is open inherit the correct disabled
                   state when Special case is ticked. */
                if (typeof applyFlagLabels === 'function') applyFlagLabels();
                return row;
            }

            function resetDrawer() {
                form.reset();
                linksBox.innerHTML = '';
                ipiBox.innerHTML   = '';
                linkIndex = 0;
                ipiIndex  = 0;
            }

            /* Open empty drawer for Add. */
            addBtn?.addEventListener('click', () => {
                resetDrawer();
                actionIn.value = 'add';
                idIn.value     = '';
                titleEl.textContent = 'Add person';
                nameIn.readOnly = false;
                nameHelp.textContent = 'The canonical spelling. To rename later, save first, then use the Rename action — renames cascade to every song that cites this person.';
                /* form.reset() above already cleared the flag checkboxes;
                   refresh the label-swap state so a previously-opened
                   group edit doesn't leave the labels reading "Founded". */
                applyFlagLabels();
                drawer.show();
                setTimeout(() => nameIn.focus(), 200);
            });

            /* Open pre-filled drawer for Edit. Reads the person JSON
               from the row's data-person attribute. */
            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.cp-edit-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person;
                try { person = JSON.parse(raw); }
                catch (_) { return; }

                resetDrawer();
                if (person.registry_id) {
                    actionIn.value = 'update_person';
                    idIn.value     = String(person.registry_id);
                    titleEl.textContent = 'Edit person — ' + person.name;
                    nameIn.value    = person.name || '';
                    nameIn.readOnly = true;
                    nameHelp.textContent = 'Locked when editing — use the Rename action to change the canonical name and cascade across the catalogue.';
                } else {
                    /* In-use name not yet in the registry — pre-fill the
                       name and let the admin enrich it. The action stays
                       'add' (this is a new registry row); the form's
                       INSERT IGNORE-style uniqueness check on the server
                       handles the case where the name was added by a
                       concurrent editor save in the meantime. */
                    actionIn.value = 'add';
                    idIn.value     = '';
                    titleEl.textContent = 'Add to registry — ' + person.name;
                    nameIn.value    = person.name || '';
                    nameIn.readOnly = false;
                    nameHelp.textContent = 'This name is already credited on songs — adding to the registry lets you attach biographical metadata. The Name field is the canonical spelling that the song-credit tables already use; editing it here would create a mismatch, so leave it as-is and use Rename later if you need to change it.';
                }
                document.getElementById('cp-drawer-birth-place').value = person.birth_place || '';
                document.getElementById('cp-drawer-birth-date').value  = person.birth_date  || '';
                document.getElementById('cp-drawer-death-place').value = person.death_place || '';
                document.getElementById('cp-drawer-death-date').value  = person.death_date  || '';
                document.getElementById('cp-drawer-notes').value       = person.notes       || '';
                /* #584 / #585 — pre-tick the classification flags. */
                document.getElementById('cp-drawer-is-special-case').checked = !!person.is_special_case;
                document.getElementById('cp-drawer-is-group').checked        = !!person.is_group;
                applyFlagLabels();
                (person.links || []).forEach(l => addLinkRow(l));
                (person.ipi   || []).forEach(r => addIpiRow(r));
                drawer.show();
            });

            /* Mutually-exclusive checkboxes + label-swap for groups. */
            const specialCaseCb = document.getElementById('cp-drawer-is-special-case');
            const groupCb       = document.getElementById('cp-drawer-is-group');
            const birthPlaceIn  = document.getElementById('cp-drawer-birth-place');
            const birthDateIn   = document.getElementById('cp-drawer-birth-date');
            const deathPlaceIn  = document.getElementById('cp-drawer-death-place');
            const deathDateIn   = document.getElementById('cp-drawer-death-date');
            const addIpiButton  = document.getElementById('cp-add-ipi-btn');
            const ipiSection    = document.querySelector('[data-flag-section="ipi"]');

            function applyFlagLabels() {
                const isGroup       = !!groupCb?.checked;
                const isSpecialCase = !!specialCaseCb?.checked;

                /* Label swap (#629) — birth/death → founded/disbandment
                   when Group is ticked. */
                document.querySelectorAll('[data-flag-label="individual"]').forEach(el => {
                    el.classList.toggle('d-none', isGroup);
                });
                document.querySelectorAll('[data-flag-label="group"]').forEach(el => {
                    el.classList.toggle('d-none', !isGroup);
                });

                /* Field disable rules (#628 / #629):
                   - Special case → bio inputs (place + date) AND the
                     entire IPI section disabled. Special-case rows
                     (Anonymous / Traditional / Public Domain / Unknown)
                     have no real bio.
                   - Group → place inputs disabled (no single physical
                     birth/death location for a band). Date inputs
                     stay editable as Founded / Disbandment dates.
                   - Default (individual) → everything editable. */
                const placesDisabled = isSpecialCase || isGroup;
                const datesDisabled  = isSpecialCase;
                const ipiDisabled    = isSpecialCase;

                if (birthPlaceIn) birthPlaceIn.disabled = placesDisabled;
                if (deathPlaceIn) deathPlaceIn.disabled = placesDisabled;
                if (birthDateIn)  birthDateIn.disabled  = datesDisabled;
                if (deathDateIn)  deathDateIn.disabled  = datesDisabled;

                /* Disable the Add IPI button + every existing IPI row's
                   inputs. The container holds the rows added via the
                   template so we walk the children. */
                if (addIpiButton) addIpiButton.disabled = ipiDisabled;
                if (ipiSection) {
                    ipiSection.querySelectorAll('input').forEach(inp => {
                        inp.disabled = ipiDisabled;
                    });
                    ipiSection.classList.toggle('opacity-50', ipiDisabled);
                }
            }
            specialCaseCb?.addEventListener('change', () => {
                if (specialCaseCb.checked && groupCb) groupCb.checked = false;
                applyFlagLabels();
            });
            groupCb?.addEventListener('change', () => {
                if (groupCb.checked && specialCaseCb) specialCaseCb.checked = false;
                applyFlagLabels();
            });

            /* Add-row buttons inside the drawer. */
            addLinkBtn?.addEventListener('click', () => addLinkRow());
            addIpiBtn?.addEventListener('click',  () => addIpiRow());

            /* Remove-row delegation. */
            drawerEl.addEventListener('click', (ev) => {
                const remove = ev.target.closest('.cp-row-remove');
                if (!remove) return;
                const row = remove.closest('.cp-link-row, .cp-ipi-row');
                if (row) row.remove();
            });
        })();

        /* =========================================================================
           Rename modal — opens with the row's current name shown
           read-only and a new-name input. Submit POSTs action=rename.
           ========================================================================= */
        (function () {
            const modalEl = document.getElementById('cpRenameModal');
            if (!modalEl) return;
            const modal     = bootstrap.Modal.getOrCreateInstance(modalEl);
            const idIn      = document.getElementById('cp-rename-id');
            const currentIn = document.getElementById('cp-rename-current');
            const newIn     = document.getElementById('cp-rename-new');
            const impactEl  = document.getElementById('cp-rename-impact');

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.cp-rename-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person; try { person = JSON.parse(raw); } catch (_) { return; }
                /* In-use-only rows pass through too (#626) — the
                   server's rename handler auto-registers the row by
                   name on submit. */

                /* Build a "this will affect …" line from the role
                   counts already on the row. */
                const cells = row.querySelectorAll('.role-pill');
                const counts = {};
                cells.forEach(p => {
                    const txt = p.textContent.trim();
                    const [k, v] = txt.split('·');
                    if (k && v) counts[k] = parseInt(v, 10) || 0;
                });
                const total = Object.values(counts).reduce((a, b) => a + b, 0);
                if (total === 0) {
                    impactEl.textContent = 'This person isn\'t cited by any song yet — only the registry row will be updated.';
                } else {
                    const parts = [];
                    if (counts.W  > 0) parts.push(counts.W  + ' writer(s)');
                    if (counts.C  > 0) parts.push(counts.C  + ' composer(s)');
                    if (counts.Ar > 0) parts.push(counts.Ar + ' arranger(s)');
                    if (counts.Ad > 0) parts.push(counts.Ad + ' adaptor(s)');
                    if (counts.T  > 0) parts.push(counts.T  + ' translator(s)');
                    impactEl.textContent = 'Will update ' + total + ' song-credit row(s): '
                        + parts.join(', ') + '.';
                }

                idIn.value     = person.registry_id ? String(person.registry_id) : '';
                /* In-use-only rows post the name as a fallback so the
                   server can auto-register before renaming (#626). */
                const sourceNameIn = document.getElementById('cp-rename-source-name');
                if (sourceNameIn) sourceNameIn.value = person.registry_id ? '' : (person.name || '');
                currentIn.value= person.name || '';
                newIn.value    = person.name || '';
                modal.show();
                setTimeout(() => { newIn.focus(); newIn.select(); }, 200);
            });
        })();

        /* =========================================================================
           Merge modal — opens with the source row pre-set, populates
           the target dropdown from every OTHER registry row, and
           shows the source's links / IPI for migrate-or-drop selection.
           ========================================================================= */
        (function () {
            const modalEl = document.getElementById('cpMergeModal');
            if (!modalEl) return;
            const modal      = bootstrap.Modal.getOrCreateInstance(modalEl);
            const sourceIdIn = document.getElementById('cp-merge-source-id');
            const sourceNameIn = document.getElementById('cp-merge-source-name');
            const targetSel  = document.getElementById('cp-merge-target');
            const childrenWrap = document.getElementById('cp-merge-children');
            const childrenEmpty = document.getElementById('cp-merge-children-empty');
            const linksBox   = document.getElementById('cp-merge-children-links');
            const ipiBox     = document.getElementById('cp-merge-children-ipi');
            const submitBtn  = document.getElementById('cp-merge-submit');

            /* Build the all-people index once on page load (#626). Used
               to populate the target dropdown excluding the source. The
               index now includes in-use-only rows, not just registry
               rows — the server auto-registers either side on submit
               so the dropdown can offer any name. */
            const registry = [];
            document.querySelectorAll('#cp-tbody tr[data-person]').forEach(r => {
                let p; try { p = JSON.parse(r.getAttribute('data-person')); } catch (_) { return; }
                if (p.registry_id) {
                    registry.push({ id: p.registry_id, name: p.name, key: 'id:' + p.registry_id });
                } else if (p.total > 0) {
                    /* In-use-only — the merge handler will INSERT IGNORE
                       on submit. Encode the name as the option value so
                       the form posts target_name when picked. */
                    registry.push({ id: 0, name: p.name, key: 'name:' + p.name });
                }
            });
            registry.sort((a, b) => a.name.localeCompare(b.name));

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.cp-merge-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person; try { person = JSON.parse(raw); } catch (_) { return; }
                /* In-use-only sources flow through too (#626) — the
                   server auto-registers by name if source_id is empty. */

                sourceIdIn.value   = person.registry_id ? String(person.registry_id) : '';
                sourceNameIn.value = person.name || '';
                /* Hidden field that tells the server to auto-register
                   the source by name when source_id is missing. */
                const sourceNameHidden = document.getElementById('cp-merge-source-name-hidden');
                if (sourceNameHidden) sourceNameHidden.value = person.registry_id ? '' : (person.name || '');

                /* Reset the irreversibility ack on every open so a prior
                   ticked state doesn't carry across to a fresh merge. */
                const confirmCb = document.getElementById('cp-merge-confirm');
                if (confirmCb) confirmCb.checked = false;

                /* Per-role re-point preview (#583). The byName aggregate
                   already carries every count we need; just display them. */
                const previewWrap = document.getElementById('cp-merge-credit-preview');
                if (previewWrap) {
                    document.getElementById('cp-merge-count-writer').textContent     = person.writers     || 0;
                    document.getElementById('cp-merge-count-composer').textContent   = person.composers   || 0;
                    document.getElementById('cp-merge-count-arranger').textContent   = person.arrangers   || 0;
                    document.getElementById('cp-merge-count-adaptor').textContent    = person.adaptors    || 0;
                    document.getElementById('cp-merge-count-translator').textContent = person.translators || 0;
                    document.getElementById('cp-merge-count-total').textContent      = person.total       || 0;
                    previewWrap.classList.remove('d-none');
                }

                /* Populate target dropdown — every name except the
                   source itself. The option `value` is the same `key`
                   that distinguishes id-vs-name (e.g. "id:42" or
                   "name:Stuart Townend") so the submit handler can
                   route the right field to the server. Sorted
                   alphabetically. */
                targetSel.innerHTML = '<option value="">— pick the surviving person —</option>';
                registry.forEach(p => {
                    if (p.id && p.id === person.registry_id) return;
                    if (!p.id && p.name === person.name)     return;
                    const opt = document.createElement('option');
                    opt.value = p.key;
                    opt.textContent = p.name + (p.id ? '' : ' (not yet in registry)');
                    targetSel.appendChild(opt);
                });

                /* Source children — render each link + IPI as a
                   checkbox row (default checked = keep on target). */
                linksBox.innerHTML = '';
                ipiBox.innerHTML   = '';
                const links = person.links || [];
                const ipi   = person.ipi   || [];
                if (links.length === 0 && ipi.length === 0) {
                    childrenEmpty.classList.remove('d-none');
                } else {
                    childrenEmpty.classList.add('d-none');
                    if (links.length) {
                        const head = document.createElement('div');
                        head.className = 'small text-secondary mb-1';
                        head.textContent = 'External links (' + links.length + ')';
                        linksBox.appendChild(head);
                        links.forEach(l => {
                            const wrap = document.createElement('div');
                            wrap.className = 'form-check small';
                            wrap.innerHTML = '<input class="form-check-input" type="checkbox" name="keep_link_ids[]" value="' + l.id + '" id="cp-merge-link-' + l.id + '" checked>'
                                           + '<label class="form-check-label" for="cp-merge-link-' + l.id + '">'
                                           + '<code class="me-1">' + (l.type || 'other') + '</code>'
                                           + (l.label ? l.label + ' — ' : '')
                                           + '<a href="#" class="text-info text-decoration-none cp-merge-link-href"></a>'
                                           + '</label>';
                            const a = wrap.querySelector('.cp-merge-link-href');
                            a.textContent = l.url;
                            a.setAttribute('href', l.url);
                            a.setAttribute('target', '_blank');
                            a.setAttribute('rel', 'noopener');
                            linksBox.appendChild(wrap);
                        });
                    }
                    if (ipi.length) {
                        const head = document.createElement('div');
                        head.className = 'small text-secondary mb-1 mt-2';
                        head.textContent = 'IPI Name Numbers (' + ipi.length + ')';
                        ipiBox.appendChild(head);
                        ipi.forEach(r => {
                            const wrap = document.createElement('div');
                            wrap.className = 'form-check small';
                            wrap.innerHTML = '<input class="form-check-input" type="checkbox" name="keep_ipi_ids[]" value="' + r.id + '" id="cp-merge-ipi-' + r.id + '" checked>'
                                           + '<label class="form-check-label" for="cp-merge-ipi-' + r.id + '">'
                                           + '<code class="me-1">' + r.number + '</code>'
                                           + (r.name_used ? '(as ' + r.name_used + ') ' : '')
                                           + (r.notes ? '— ' + r.notes : '')
                                           + '</label>';
                            ipiBox.appendChild(wrap);
                        });
                    }
                }
                childrenWrap.classList.remove('d-none');
                submitBtn.disabled = true; /* enabled once a target is picked */
                modal.show();
            });

            /* Submit button enables only when BOTH a target is picked
               AND the irreversibility ack is ticked (#583). */
            const confirmCb = document.getElementById('cp-merge-confirm');
            function refreshSubmitState() {
                const targetPicked = targetSel?.value !== '';
                const acknowledged = !!confirmCb?.checked;
                submitBtn.disabled = !(targetPicked && acknowledged);
            }
            targetSel?.addEventListener('change', () => {
                refreshSubmitState();
            });
            confirmCb?.addEventListener('change', refreshSubmitState);

            /* On submit, translate the picked option's key into the
               correct hidden field (#626). The select carries
               "id:N" for registry rows and "name:X" for in-use-only
               rows; the server handler accepts either. */
            modalEl.querySelector('form')?.addEventListener('submit', () => {
                const picked = targetSel?.value || '';
                const idHidden   = document.getElementById('cp-merge-target-id-hidden');
                const nameHidden = document.getElementById('cp-merge-target-name-hidden');
                if (idHidden)   idHidden.value   = '';
                if (nameHidden) nameHidden.value = '';
                if (picked.startsWith('id:')) {
                    if (idHidden) idHidden.value = picked.slice(3);
                } else if (picked.startsWith('name:')) {
                    if (nameHidden) nameHidden.value = picked.slice(5);
                }
            });
        })();

        /* =========================================================================
           Delete-from-registry modal — read the row's role-pill counts
           to decide whether to show the force-anyway path.
           ========================================================================= */
        (function () {
            const modalEl = document.getElementById('cpDeleteModal');
            if (!modalEl) return;
            const modal      = bootstrap.Modal.getOrCreateInstance(modalEl);
            const idIn       = document.getElementById('cp-delete-id');
            const nameEl     = document.getElementById('cp-delete-name');
            const inUseEl    = document.getElementById('cp-delete-in-use');
            const notInUseEl = document.getElementById('cp-delete-not-in-use');
            const usageCnt   = document.getElementById('cp-delete-usage-count');
            const forceCb    = document.getElementById('cp-delete-force');
            const submitBtn  = document.getElementById('cp-delete-submit');

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.cp-delete-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person; try { person = JSON.parse(raw); } catch (_) { return; }
                if (!person.registry_id) return;

                /* Read total usage from the row's "Total uses" cell. */
                const totalCell = row.querySelector('td.text-end strong');
                const usage = parseInt((totalCell?.textContent || '0').replace(/[,]/g, ''), 10) || 0;

                idIn.value      = String(person.registry_id);
                nameEl.textContent = person.name || '';
                forceCb.checked = false;

                if (usage > 0) {
                    inUseEl.classList.remove('d-none');
                    notInUseEl.classList.add('d-none');
                    usageCnt.textContent = usage.toLocaleString();
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-trash me-1"></i>Force remove';
                } else {
                    inUseEl.classList.add('d-none');
                    notInUseEl.classList.remove('d-none');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-trash me-1"></i>Remove';
                }
                modal.show();
            });

            forceCb?.addEventListener('change', () => {
                submitBtn.disabled = !forceCb.checked;
            });
        })();

        /* =========================================================================
           View Songs modal — fetches GET ?action=view_songs&id=<id>
           on open and renders the per-role groups.
           ========================================================================= */
        (function () {
            const modalEl = document.getElementById('cpViewSongsModal');
            if (!modalEl) return;
            const modal     = bootstrap.Modal.getOrCreateInstance(modalEl);
            const nameEl    = document.getElementById('cp-view-songs-name');
            const loadingEl = document.getElementById('cp-view-songs-loading');
            const errorEl   = document.getElementById('cp-view-songs-error');
            const bodyEl    = document.getElementById('cp-view-songs-body');
            const emptyEl   = document.getElementById('cp-view-songs-empty');

            const ROLE_LABELS = {
                writer:     'Writers',
                composer:   'Composers',
                arranger:   'Arrangers',
                adaptor:    'Adaptors',
                translator: 'Translators',
            };

            function renderRoleGroup(role, songs) {
                if (!songs.length) return '';
                const items = songs.map(s => {
                    const ref = (s.SongbookAbbr || '?') + (s.Number ? '-' + s.Number : '');
                    const title = s.Title || '(untitled)';
                    return '<li class="small">'
                        + '<code class="me-2">' + ref + '</code>'
                        + title
                        + '</li>';
                }).join('');
                return '<div class="mb-3">'
                    + '<h6 class="small text-secondary mb-1">'
                    +   ROLE_LABELS[role] + ' <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">' + songs.length + '</span>'
                    + '</h6>'
                    + '<ul class="list-unstyled mb-0">' + items + '</ul>'
                    + '</div>';
            }

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.cp-view-songs-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person; try { person = JSON.parse(raw); } catch (_) { return; }
                if (!person.registry_id) return;

                nameEl.textContent = person.name || '';
                bodyEl.innerHTML = '';
                errorEl.classList.add('d-none');
                emptyEl.classList.add('d-none');
                loadingEl.classList.remove('d-none');
                modal.show();

                fetch('/manage/credit-people?action=view_songs&id=' + encodeURIComponent(person.registry_id), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                })
                    .then(r => r.json().then(j => ({ ok: r.ok, j })))
                    .then(({ ok, j }) => {
                        loadingEl.classList.add('d-none');
                        if (!ok || j.error) {
                            errorEl.textContent = j.error || ('HTTP error');
                            errorEl.classList.remove('d-none');
                            return;
                        }
                        if (!j.total) {
                            emptyEl.classList.remove('d-none');
                            return;
                        }
                        const html = ['writer', 'composer', 'arranger', 'adaptor', 'translator']
                            .map(role => renderRoleGroup(role, j.by_role[role] || []))
                            .join('');
                        bodyEl.innerHTML = html;
                    })
                    .catch(err => {
                        loadingEl.classList.add('d-none');
                        errorEl.textContent = String(err.message || err);
                        errorEl.classList.remove('d-none');
                    });
            });
        })();
    </script>

</body>
</html>
