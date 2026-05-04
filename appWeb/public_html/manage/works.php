<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Works (#840)
 *
 * CRUD surface for tblWorks + tblWorkSongs membership + the
 * tblWorkExternalLinks panel. Models the MusicBrainz Work entity:
 * one Work groups multiple tblSongs rows that represent the same
 * underlying composition across different songbooks / arrangements
 * / translations.
 *
 * Supports unlimited nesting via tblWorks.ParentWorkId — original
 * Work → arrangement → translation → … (cycle-prevention enforced
 * application-side at update time).
 *
 * Gated by `manage_works`; pre-migration safe — probes tblWorks on
 * every page load and renders a friendly CTA pointing at
 * /manage/setup-database when missing.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'external_link_helpers.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_works', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_works required</h1></body></html>';
    exit;
}
$activePage = 'works';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* ---- Helpers ---- */

/**
 * URL-safe lowercase slug from a free-text title. ASCII-only —
 * non-ASCII characters drop. Multiple separators collapse to one
 * hyphen; leading/trailing hyphens are stripped.
 */
$slugFor = static function (string $name): string {
    $ascii = (string)preg_replace('/[^A-Za-z0-9]+/u', '-', $name);
    return trim(strtolower($ascii), '-');
};

/**
 * Validate the ISWC shape: T-NNN.NNN.NNN-C (15 chars). The check
 * digit isn't recomputed (mod-10/11 schemes vary by region); we
 * trust the curator and shape-validate only. Empty string is
 * valid (ISWC is optional).
 */
$validateIswc = static function (string $raw): ?string {
    $raw = strtoupper(trim($raw));
    if ($raw === '') return '';
    if (preg_match('/^T-?\d{3}\.?\d{3}\.?\d{3}-?\d$/', $raw) !== 1) return null;
    /* Re-format to canonical T-NNN.NNN.NNN-C */
    $digits = preg_replace('/\D/', '', $raw);
    if (strlen((string)$digits) !== 10) return null;
    return 'T-' . substr($digits, 0, 3) . '.' . substr($digits, 3, 3)
         . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 1);
};

/* Schema probe — render friendly CTA when the migration hasn't run. */
$hasSchema = false;
try {
    $probe = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks' LIMIT 1"
    );
    $probe->execute();
    $hasSchema = $probe->get_result()->fetch_row() !== null;
    $probe->close();
} catch (\Throwable $e) {
    error_log('[works] schema probe failed: ' . $e->getMessage());
}

/* External-links registry (#833) — probe + load applicable types. */
$hasExtLinksSchema = false;
$linkTypesForWork  = [];
try {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblWorkExternalLinks' LIMIT 1"
    );
    $hasExtLinksSchema = $r && $r->fetch_row() !== null;
    if ($r) $r->close();
    if ($hasExtLinksSchema) {
        $res = $db->query(
            "SELECT Id, Slug, Name, Category, IconClass
               FROM tblExternalLinkTypes
              WHERE COALESCE(IsActive, 1) = 1
                AND FIND_IN_SET('work', AppliesTo) > 0
              ORDER BY Category ASC, DisplayOrder ASC, Name ASC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $linkTypesForWork[] = [
                    'id'        => (int)$row['Id'],
                    'slug'      => (string)$row['Slug'],
                    'name'      => (string)$row['Name'],
                    'category'  => (string)$row['Category'],
                    'iconClass' => (string)($row['IconClass'] ?? ''),
                ];
            }
            $res->close();
        }
        /* #845 — attach DB-driven URL → provider patterns. */
        $linkTypesForWork = attachExternalLinkPatterns($db, $linkTypesForWork);
    }
} catch (\Throwable $_e) { /* probe failure → external-links UI silently absent */ }

/* ---- GET ?action=song_search&q= — typeahead for membership picker. */
if ($hasSchema
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'song_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    try {
        $like = '%' . $q . '%';
        if ($q === '') {
            $sql = "SELECT SongId, Title, SongbookAbbr, Number
                      FROM tblSongs
                     ORDER BY SongbookAbbr ASC, Number ASC
                     LIMIT ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('i', $limit);
        } else {
            $sql = "SELECT SongId, Title, SongbookAbbr, Number
                      FROM tblSongs
                     WHERE Title LIKE ?
                        OR SongId LIKE ?
                        OR SongbookAbbr LIKE ?
                     ORDER BY SongbookAbbr ASC, Number ASC
                     LIMIT ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('sssi', $like, $like, $like, $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'songId'   => (string)$row['SongId'],
                'title'    => (string)$row['Title'],
                'songbook' => (string)$row['SongbookAbbr'],
                'number'   => $row['Number'] !== null ? (int)$row['Number'] : null,
            ];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[works song_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- POST actions ---- */
if ($hasSchema && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    /**
     * Verify $candidateParent isn't a descendant of $workId — prevents
     * cycle creation when re-parenting (a → b → c, then re-parenting
     * a under c would loop). Walks the parent chain until null,
     * giving up after MAX_DEPTH iterations as a hard stop in case the
     * table has somehow already become inconsistent.
     */
    $cycleSafe = static function (int $workId, ?int $candidateParent) use ($db): bool {
        if ($candidateParent === null) return true;
        if ($candidateParent === $workId) return false;
        $cur = $candidateParent;
        $maxDepth = 64;
        while ($cur !== null && $maxDepth-- > 0) {
            if ($cur === $workId) return false;
            $stmt = $db->prepare('SELECT ParentWorkId FROM tblWorks WHERE Id = ?');
            $stmt->bind_param('i', $cur);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) return true;
            $cur = $row['ParentWorkId'] !== null ? (int)$row['ParentWorkId'] : null;
        }
        return false;
    };

    try {
        switch ($action) {
            case 'create': {
                $title  = trim((string)($_POST['title']  ?? ''));
                $slugIn = trim((string)($_POST['slug']   ?? ''));
                $iswcIn = trim((string)($_POST['iswc']   ?? ''));
                $notes  = trim((string)($_POST['notes']  ?? ''));
                $parent = (int)($_POST['parent_id'] ?? 0);
                if ($title === '') { $error = 'Title is required.'; break; }
                $slug = $slugIn !== '' ? $slugIn : $slugFor($title);
                if ($slug === '') { $error = 'Title has no usable slug characters — provide one explicitly.'; break; }
                $iswc = $validateIswc($iswcIn);
                if ($iswc === null) { $error = 'ISWC must look like T-345.246.800-1 (10 digits).'; break; }
                $title = mb_substr($title, 0, 255);
                $slug  = mb_substr($slug,  0, 80);
                $notes = mb_substr($notes, 0, 65000);

                /* Slug uniqueness */
                $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Slug = ?');
                $stmt->bind_param('s', $slug);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = "Slug '{$slug}' already taken."; break; }

                /* ISWC uniqueness when supplied */
                if ($iswc !== '') {
                    $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Iswc = ?');
                    $stmt->bind_param('s', $iswc);
                    $stmt->execute();
                    $iswcUsed = $stmt->get_result()->fetch_row() !== null;
                    $stmt->close();
                    if ($iswcUsed) { $error = "ISWC '{$iswc}' is already on another Work."; break; }
                }

                $stmt = $db->prepare(
                    'INSERT INTO tblWorks (ParentWorkId, Iswc, Title, Slug, Notes)
                     VALUES (?, NULLIF(?, ""), ?, ?, NULLIF(?, ""))'
                );
                $parentBind = $parent > 0 ? $parent : null;
                $stmt->bind_param('issss', $parentBind, $iswc, $title, $slug, $notes);
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();

                if (function_exists('logActivity')) {
                    logActivity('work.create', 'work', (string)$newId, [
                        'title' => $title, 'slug' => $slug,
                        'iswc'  => $iswc, 'parent_id' => $parent > 0 ? $parent : null,
                    ]);
                }
                $success = "Work '{$title}' created.";
                break;
            }

            case 'update': {
                $id     = (int)($_POST['id']    ?? 0);
                $title  = trim((string)($_POST['title']  ?? ''));
                $slugIn = trim((string)($_POST['slug']   ?? ''));
                $iswcIn = trim((string)($_POST['iswc']   ?? ''));
                $notes  = trim((string)($_POST['notes']  ?? ''));
                $parent = (int)($_POST['parent_id'] ?? 0);
                if ($id <= 0)     { $error = 'Work id is required.'; break; }
                if ($title === '') { $error = 'Title is required.'; break; }
                $slug = $slugIn !== '' ? $slugIn : $slugFor($title);
                if ($slug === '') { $error = 'Title has no usable slug characters.'; break; }
                $iswc = $validateIswc($iswcIn);
                if ($iswc === null) { $error = 'ISWC must look like T-345.246.800-1.'; break; }
                $title = mb_substr($title, 0, 255);
                $slug  = mb_substr($slug,  0, 80);
                $notes = mb_substr($notes, 0, 65000);

                /* Cycle check */
                $parentBind = $parent > 0 ? $parent : null;
                if (!$cycleSafe($id, $parentBind)) {
                    $error = 'Cannot set that parent — it would create a cycle.';
                    break;
                }

                /* Slug + ISWC uniqueness (excluding self) */
                $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Slug = ? AND Id <> ?');
                $stmt->bind_param('si', $slug, $id);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($dup) { $error = "Slug '{$slug}' already taken by another Work."; break; }

                if ($iswc !== '') {
                    $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Iswc = ? AND Id <> ?');
                    $stmt->bind_param('si', $iswc, $id);
                    $stmt->execute();
                    $iswcDup = $stmt->get_result()->fetch_row() !== null;
                    $stmt->close();
                    if ($iswcDup) { $error = "ISWC '{$iswc}' already on another Work."; break; }
                }

                /* Membership reconciliation */
                $postedSongs    = $_POST['member_song_ids']  ?? [];
                $postedCanon    = $_POST['member_canonical'] ?? [];
                $postedSort     = $_POST['member_sort']      ?? [];
                $postedNote     = $_POST['member_note']      ?? [];
                if (!is_array($postedSongs)) $postedSongs = [];
                if (!is_array($postedCanon)) $postedCanon = [];
                if (!is_array($postedSort))  $postedSort  = [];
                if (!is_array($postedNote))  $postedNote  = [];
                $cleanSongs = array_values(array_unique(array_filter(array_map(
                    static fn($s) => mb_substr(trim((string)$s), 0, 20),
                    $postedSongs
                ))));

                /* External-links reconciliation arrays (#833 pattern) */
                $linkTypeIds  = $_POST['ext_link_type_ids'] ?? [];
                $linkUrls     = $_POST['ext_link_urls']     ?? [];
                $linkNotes    = $_POST['ext_link_notes']    ?? [];
                $linkVerified = $_POST['ext_link_verified'] ?? [];
                if (!is_array($linkTypeIds))  $linkTypeIds  = [];
                if (!is_array($linkUrls))     $linkUrls     = [];
                if (!is_array($linkNotes))    $linkNotes    = [];
                if (!is_array($linkVerified)) $linkVerified = [];

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare(
                        'UPDATE tblWorks
                            SET ParentWorkId = ?, Iswc = NULLIF(?, ""),
                                Title = ?, Slug = ?, Notes = NULLIF(?, "")
                          WHERE Id = ?'
                    );
                    $stmt->bind_param('issssi', $parentBind, $iswc, $title, $slug, $notes, $id);
                    $stmt->execute();
                    $stmt->close();

                    /* Membership: delete-then-insert so SortOrder /
                       IsCanonical / Note all reset cleanly. Cheap on
                       small membership lists. */
                    $stmt = $db->prepare('DELETE FROM tblWorkSongs WHERE WorkId = ?');
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    if ($cleanSongs) {
                        $stmt = $db->prepare(
                            'INSERT INTO tblWorkSongs
                                (WorkId, SongId, IsCanonical, SortOrder, Note)
                             VALUES (?, ?, ?, ?, NULLIF(?, ""))'
                        );
                        foreach ($cleanSongs as $idx => $sid) {
                            $isCanon = in_array($sid, (array)$postedCanon, true) ? 1 : 0;
                            $sort    = isset($postedSort[$sid]) ? max(0, min(65535, (int)$postedSort[$sid])) : ($idx * 10);
                            $note    = mb_substr((string)($postedNote[$sid] ?? ''), 0, 255);
                            $stmt->bind_param('isiis', $id, $sid, $isCanon, $sort, $note);
                            $stmt->execute();
                        }
                        $stmt->close();
                    }

                    /* External links — DELETE-then-INSERT, mirroring #833. */
                    if ($hasExtLinksSchema) {
                        $stmt = $db->prepare('DELETE FROM tblWorkExternalLinks WHERE WorkId = ?');
                        $stmt->bind_param('i', $id);
                        $stmt->execute();
                        $stmt->close();

                        $insertedLinks = 0;
                        $count = max(count($linkTypeIds), count($linkUrls));
                        for ($i = 0; $i < $count; $i++) {
                            $typeId = (int)($linkTypeIds[$i] ?? 0);
                            $url    = trim((string)($linkUrls[$i] ?? ''));
                            $lnote  = mb_substr(trim((string)($linkNotes[$i] ?? '')), 0, 255);
                            $ver    = !empty($linkVerified[$i]) ? 1 : 0;
                            if ($typeId <= 0 || $url === '') continue;
                            if (!preg_match('#^https?://#i', $url)) continue;
                            if (mb_strlen($url) > 2048) continue;

                            $stmt = $db->prepare(
                                'INSERT INTO tblWorkExternalLinks
                                     (WorkId, LinkTypeId, Url, Note, SortOrder, Verified)
                                 VALUES (?, ?, ?, NULLIF(?, ""), ?, ?)'
                            );
                            $stmt->bind_param('iissii', $id, $typeId, $url, $lnote, $i, $ver);
                            $stmt->execute();
                            $stmt->close();
                            $insertedLinks++;
                        }
                    }

                    $db->commit();
                } catch (\Throwable $tx) {
                    $db->rollback();
                    throw $tx;
                }

                if (function_exists('logActivity')) {
                    logActivity('work.edit', 'work', (string)$id, [
                        'title'                => $title,
                        'slug'                 => $slug,
                        'iswc'                 => $iswc,
                        'parent_id'            => $parentBind,
                        'member_count'         => count($cleanSongs),
                        'external_link_count'  => $hasExtLinksSchema ? ($insertedLinks ?? 0) : null,
                    ]);
                }
                $success = "Work '{$title}' updated.";
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Work id is required.'; break; }
                $stmt = $db->prepare('SELECT Title FROM tblWorks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $before = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$before) { $error = 'Work not found.'; break; }

                /* Cascade rules:
                   - tblWorkSongs FK has ON DELETE CASCADE → memberships dropped.
                   - tblWorkExternalLinks FK has ON DELETE CASCADE → links dropped.
                   - tblWorks.ParentWorkId self-FK has ON DELETE SET NULL → child
                     works orphan rather than cascade-delete (curator decides
                     what to do with them). */
                $stmt = $db->prepare('DELETE FROM tblWorks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                if (function_exists('logActivity')) {
                    logActivity('work.delete', 'work', (string)$id, [
                        'title' => (string)$before['Title'],
                    ]);
                }
                $success = "Work '" . (string)$before['Title'] . "' deleted.";
                break;
            }
        }
    } catch (\Throwable $e) {
        error_log('[works POST] ' . $e->getMessage());
        $error = 'Could not save changes: ' . $e->getMessage();
    }
}

/* ---- Read ---- */
$rows           = [];
$memberCounts   = [];
$linkCounts     = [];
$childCounts    = [];
$parentMap      = [];
$worksByIdShort = [];
$workMembersMap = [];
$workLinksMap   = [];

if ($hasSchema) {
    try {
        $res = $db->query(
            'SELECT w.Id, w.ParentWorkId, w.Title, w.Slug, w.Iswc, w.Notes,
                    (SELECT COUNT(*) FROM tblWorkSongs ws WHERE ws.WorkId = w.Id) AS MemberCount,
                    (SELECT COUNT(*) FROM tblWorks c   WHERE c.ParentWorkId = w.Id) AS ChildCount
               FROM tblWorks w
              ORDER BY w.Title ASC'
        );
        while ($row = $res->fetch_assoc()) {
            $row['Id']           = (int)$row['Id'];
            $row['ParentWorkId'] = $row['ParentWorkId'] !== null ? (int)$row['ParentWorkId'] : null;
            $row['MemberCount']  = (int)$row['MemberCount'];
            $row['ChildCount']   = (int)$row['ChildCount'];
            $rows[] = $row;
            $worksByIdShort[$row['Id']] = [
                'id'    => $row['Id'],
                'title' => (string)$row['Title'],
            ];
            $parentMap[$row['Id']] = $row['ParentWorkId'];
        }
        $res->close();

        if ($rows) {
            /* Members per work */
            $stmt = $db->query(
                'SELECT ws.WorkId, ws.SongId, ws.IsCanonical, ws.SortOrder, ws.Note,
                        s.Title AS SongTitle, s.SongbookAbbr, s.Number
                   FROM tblWorkSongs ws
                   JOIN tblSongs s ON s.SongId = ws.SongId
                  ORDER BY ws.WorkId, ws.SortOrder ASC, s.SongbookAbbr ASC, s.Number ASC'
            );
            while ($row = $stmt->fetch_assoc()) {
                $wid = (int)$row['WorkId'];
                $workMembersMap[$wid][] = [
                    'songId'      => (string)$row['SongId'],
                    'title'       => (string)$row['SongTitle'],
                    'songbook'    => (string)$row['SongbookAbbr'],
                    'number'      => $row['Number'] !== null ? (int)$row['Number'] : null,
                    'isCanonical' => (bool)$row['IsCanonical'],
                    'sortOrder'   => (int)$row['SortOrder'],
                    'note'        => (string)($row['Note'] ?? ''),
                ];
            }
            $stmt->close();

            /* Links per work */
            if ($hasExtLinksSchema) {
                $stmt = $db->query(
                    'SELECT el.WorkId, el.Id, el.LinkTypeId, el.Url, el.Note,
                            el.SortOrder, el.Verified,
                            t.Slug, t.Name, t.Category, t.IconClass
                       FROM tblWorkExternalLinks el
                       JOIN tblExternalLinkTypes t ON t.Id = el.LinkTypeId
                      ORDER BY el.WorkId, t.Category, el.SortOrder ASC,
                               t.DisplayOrder ASC, t.Name ASC'
                );
                while ($row = $stmt->fetch_assoc()) {
                    $wid = (int)$row['WorkId'];
                    $workLinksMap[$wid][] = [
                        'typeId'    => (int)$row['LinkTypeId'],
                        'url'       => (string)$row['Url'],
                        'note'      => (string)($row['Note'] ?? ''),
                        'verified'  => (bool)$row['Verified'],
                        'slug'      => (string)$row['Slug'],
                        'name'      => (string)$row['Name'],
                        'category'  => (string)$row['Category'],
                        'iconClass' => (string)($row['IconClass'] ?? ''),
                    ];
                }
                $stmt->close();
            }
        }
    } catch (\Throwable $e) {
        error_log('[works read] ' . $e->getMessage());
    }
}

/* ----- Page render ----- */
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title>Works — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-3"><i class="bi bi-diagram-3 me-2"></i>Works</h1>
        <p class="text-secondary small mb-4">
            A <strong>Work</strong> groups multiple songs that represent the same composition
            across different songbooks, arrangements or translations — mirrors the
            <a href="https://musicbrainz.org/doc/Work" target="_blank" rel="noopener noreferrer">MusicBrainz Work</a>
            ↔ Recording relationship. Works can be nested without limit (an original work
            can have arrangement / translation children, each with their own children, etc.).
            ISWC is optional — supply it for compositions registered with a CISAC society.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$hasSchema): ?>
            <div class="card-admin p-4 text-center">
                <p class="mb-2">
                    <i class="bi bi-database-exclamation text-warning fs-1" aria-hidden="true"></i>
                </p>
                <h2 class="h6 mb-2">Schema not yet installed</h2>
                <p class="text-muted small mb-3">
                    The <code>tblWorks</code> + <code>tblWorkSongs</code> +
                    <code>tblWorkExternalLinks</code> tables haven't been created on this database yet (#840).
                </p>
                <a href="/manage/setup-database" class="btn btn-amber btn-sm">
                    <i class="bi bi-database-gear me-1"></i>Run /manage/setup-database
                </a>
            </div>
        <?php else: ?>

        <!-- Works list -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3"><i class="bi bi-list-ul me-2"></i>Existing works</h2>
            <table class="table table-sm align-middle cp-sortable mb-0 admin-table-responsive">
                <thead>
                    <tr class="text-muted small">
                        <th data-col-priority="primary"   data-sort-key="title"     data-sort-type="text">Title</th>
                        <th data-col-priority="secondary" data-sort-key="iswc"      data-sort-type="text">ISWC</th>
                        <th data-col-priority="primary"   data-sort-key="members"   data-sort-type="number" class="text-center">Members</th>
                        <th data-col-priority="tertiary"  data-sort-key="children"  data-sort-type="number" class="text-center">Children</th>
                        <th data-col-priority="tertiary"  data-sort-key="parent"    data-sort-type="text">Parent</th>
                        <th data-col-priority="primary"   class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $wid = (int)$r['Id'];
                            $rowPayload = [
                                'id'         => $wid,
                                'parent_id'  => $r['ParentWorkId'],
                                'title'      => (string)$r['Title'],
                                'slug'       => (string)$r['Slug'],
                                'iswc'       => (string)($r['Iswc'] ?? ''),
                                'notes'      => (string)($r['Notes'] ?? ''),
                                'members'    => $workMembersMap[$wid] ?? [],
                                'links'      => $workLinksMap[$wid]   ?? [],
                            ];
                            $rowJson = json_encode($rowPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $deleteJson = json_encode(['id' => $wid, 'title' => (string)$r['Title']],
                                                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $parentTitle = ($r['ParentWorkId'] !== null && isset($worksByIdShort[$r['ParentWorkId']]))
                                ? $worksByIdShort[$r['ParentWorkId']]['title']
                                : '';
                        ?>
                        <tr>
                            <td data-col-priority="primary">
                                <a href="/work/<?= htmlspecialchars((string)$r['Slug']) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   class="text-decoration-none">
                                    <?= htmlspecialchars((string)$r['Title']) ?>
                                </a>
                                <?php if (!empty($r['Iswc'])): ?>
                                    <span class="d-md-none ms-2 text-muted small"><code><?= htmlspecialchars((string)$r['Iswc']) ?></code></span>
                                <?php endif; ?>
                                <?php if ($parentTitle !== ''): ?>
                                    <small class="d-md-none d-block text-muted">↳ child of <?= htmlspecialchars($parentTitle) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="secondary">
                                <?php if (!empty($r['Iswc'])): ?>
                                    <code><?= htmlspecialchars((string)$r['Iswc']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="primary" class="text-center"><?= (int)$r['MemberCount'] ?></td>
                            <td data-col-priority="tertiary" class="text-center">
                                <?= (int)$r['ChildCount'] > 0 ? (int)$r['ChildCount'] : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td data-col-priority="tertiary">
                                <?php if ($parentTitle !== ''): ?>
                                    <small class="text-muted"><?= htmlspecialchars($parentTitle) ?></small>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="primary" class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick="openWorkEditModal(<?= htmlspecialchars((string)$rowJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Edit work + members + links">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="openWorkDeleteModal(<?= htmlspecialchars((string)$deleteJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Delete work (memberships + links cascade; child works orphan)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="text-muted text-center py-4">No works yet. Add one below.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Create -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a work</h2>
            <div class="row g-2">
                <div class="col-sm-5">
                    <label class="form-label small">Title</label>
                    <input type="text" name="title" id="create-title"
                           class="form-control form-control-sm"
                           maxlength="255" required
                           placeholder="e.g. Amazing Grace">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Slug
                        <small class="text-muted">(auto)</small>
                    </label>
                    <input type="text" name="slug" id="create-slug"
                           class="form-control form-control-sm"
                           maxlength="80" pattern="[a-z0-9-]+"
                           placeholder="amazing-grace">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">ISWC <small class="text-muted">(optional)</small></label>
                    <input type="text" name="iswc"
                           class="form-control form-control-sm"
                           maxlength="15"
                           placeholder="T-345.246.800-1">
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-6">
                    <label class="form-label small">Parent work <small class="text-muted">(optional — for nesting)</small></label>
                    <select name="parent_id" class="form-select form-select-sm">
                        <option value="">— top-level work —</option>
                        <?php foreach ($rows as $r): ?>
                            <option value="<?= (int)$r['Id'] ?>"><?= htmlspecialchars((string)$r['Title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label small">Notes <small class="text-muted">(optional)</small></label>
                    <input type="text" name="notes"
                           class="form-control form-control-sm"
                           maxlength="500"
                           placeholder="Brief context for curators">
                </div>
            </div>
            <button type="submit" class="btn btn-amber btn-sm mt-3">
                <i class="bi bi-plus me-1"></i>Create work
            </button>
            <p class="form-text small mt-2 mb-0">
                Add member songs + external links via the <em>Edit</em> button after creating.
            </p>
        </form>

        <!-- Edit Modal -->
        <div class="modal fade" id="workEditModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit-work-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title">
                                <i class="bi bi-pencil me-2"></i>Edit work — <span id="edit-work-title-label"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Title</label>
                                    <input type="text" name="title" id="edit-work-title"
                                           class="form-control" maxlength="255" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Slug</label>
                                    <input type="text" name="slug" id="edit-work-slug"
                                           class="form-control" maxlength="80"
                                           pattern="[a-z0-9-]+">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">ISWC <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="iswc" id="edit-work-iswc"
                                           class="form-control" maxlength="15"
                                           placeholder="T-345.246.800-1">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Parent work</label>
                                    <select name="parent_id" id="edit-work-parent" class="form-select">
                                        <option value="">— top-level work —</option>
                                        <?php foreach ($rows as $r): ?>
                                            <option value="<?= (int)$r['Id'] ?>"><?= htmlspecialchars((string)$r['Title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small">Cycles are blocked server-side.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Notes</label>
                                    <input type="text" name="notes" id="edit-work-notes"
                                           class="form-control" maxlength="500">
                                </div>
                            </div>

                            <hr>

                            <h6 class="mb-2"><i class="bi bi-music-note-list me-2"></i>Member songs</h6>
                            <p class="form-text small mt-0 mb-2">
                                Each row is a song that's a version of this work. Mark one as
                                <em>canonical</em> (typically the most-cited / earliest published).
                                Members in different songbooks across the catalogue are fine —
                                that's the whole point.
                            </p>

                            <table class="table table-sm align-middle mb-2">
                                <thead>
                                    <tr class="text-muted small">
                                        <th style="width:6rem">Sort</th>
                                        <th style="width:5rem" class="text-center">Canon</th>
                                        <th>Song</th>
                                        <th>Note</th>
                                        <th class="text-end" style="width:3rem"></th>
                                    </tr>
                                </thead>
                                <tbody id="edit-work-members-tbody">
                                    <!-- populated by openWorkEditModal -->
                                </tbody>
                            </table>

                            <div class="d-flex gap-2 align-items-end mb-3">
                                <div class="flex-grow-1">
                                    <label class="form-label small mb-1">Add a song to this work</label>
                                    <input type="text" id="edit-work-add-search"
                                           class="form-control form-control-sm"
                                           autocomplete="off"
                                           placeholder="Type to search by title, song id, or songbook…">
                                    <div id="edit-work-add-suggestions" class="list-group small mt-1" style="display:none; max-height: 220px; overflow-y: auto;"></div>
                                </div>
                            </div>

                            <?php if ($hasExtLinksSchema): ?>
                            <hr>

                            <div class="d-flex justify-content-between align-items-baseline mb-2">
                                <h6 class="mb-0"><i class="bi bi-link-45deg me-2"></i>External links</h6>
                                <button type="button" class="btn btn-outline-info btn-sm" id="edit-work-ext-link-add-btn">
                                    <i class="bi bi-plus-lg me-1"></i>Add link
                                </button>
                            </div>
                            <p class="form-text small mt-0 mb-2">
                                Provider auto-detects from the URL — paste e.g. a YouTube link
                                and the dropdown selects YouTube automatically.
                            </p>
                            <div id="edit-work-ext-links-rows" class="vstack gap-2"></div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer" style="border-color: var(--ih-border);">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-amber-solid">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Modal -->
        <div class="modal fade" id="workDeleteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete-work-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title">
                                <i class="bi bi-trash me-2"></i>Delete work — <span id="delete-work-name-label"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-warning mb-2">
                                Memberships and external links cascade away with the Work.
                            </p>
                            <p class="text-muted small">
                                Child works <strong>orphan</strong> (their <code>ParentWorkId</code>
                                becomes <code>NULL</code>) — you can re-parent them or leave them top-level.
                            </p>
                        </div>
                        <div class="modal-footer" style="border-color: var(--ih-border);">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        /* Seeded link-type registry for the work-ext-links row builder. */
        window._iHymnsLinkTypes = <?= json_encode($linkTypesForWork, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <?php endif; /* hasSchema */ ?>
    </div>

    <script>
    (function () {
        const modalEl   = document.getElementById('workEditModal');
        const delModal  = document.getElementById('workDeleteModal');
        if (!modalEl) return;
        const editModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const deleteModal = bootstrap.Modal.getOrCreateInstance(delModal);
        const tbody = document.getElementById('edit-work-members-tbody');
        const linkRowsEl = document.getElementById('edit-work-ext-links-rows');
        const addLinkBtn = document.getElementById('edit-work-ext-link-add-btn');
        const search = document.getElementById('edit-work-add-search');
        const sugBox = document.getElementById('edit-work-add-suggestions');

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function memberRowHtml(m) {
            const sid  = String(m.songId || '');
            const sort = (m.sortOrder !== undefined && m.sortOrder !== null) ? m.sortOrder : 0;
            const note = String(m.note || '');
            const can  = m.isCanonical ? 'checked' : '';
            const songLabel = escapeHtml((m.songbook || '') + (m.number ? (' #' + m.number) : '') + ' — ' + (m.title || sid));
            return '' +
              '<tr data-song-id="' + escapeHtml(sid) + '">' +
                '<td><input type="number" class="form-control form-control-sm" name="member_sort[' + escapeHtml(sid) + ']" value="' + Number(sort) + '" min="0" max="65535" style="width:6rem"></td>' +
                '<td class="text-center"><div class="form-check form-check-inline ms-1"><input class="form-check-input" type="checkbox" name="member_canonical[]" value="' + escapeHtml(sid) + '" ' + can + '></div></td>' +
                '<td><input type="hidden" name="member_song_ids[]" value="' + escapeHtml(sid) + '">' + songLabel + '</td>' +
                '<td><input type="text" class="form-control form-control-sm" name="member_note[' + escapeHtml(sid) + ']" maxlength="255" value="' + escapeHtml(note) + '" placeholder="e.g. \'1779 original\'"></td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-action="remove-member" title="Remove"><i class="bi bi-x-lg"></i></button></td>' +
              '</tr>';
        }

        function rebindRemoveButtons() {
            tbody.querySelectorAll('[data-action="remove-member"]').forEach(btn => {
                btn.onclick = () => btn.closest('tr')?.remove();
            });
        }

        /* === External-links card builder, mirrors the songbooks page (#833) === */
        function buildLinkSelect(selectedId) {
            const types = Array.isArray(window._iHymnsLinkTypes) ? window._iHymnsLinkTypes : [];
            const byCat = {};
            types.forEach(t => {
                const cat = t.category || 'other';
                if (!byCat[cat]) byCat[cat] = [];
                byCat[cat].push(t);
            });
            const catLabels = {
                'official':'Official','information':'Information','read':'Read',
                'sheet-music':'Sheet music','listen':'Listen','watch':'Watch',
                'purchase':'Purchase','authority':'Authority','social':'Social','other':'Other',
            };
            const catOrder = ['official','information','read','sheet-music','listen','watch','purchase','authority','social','other'];
            let html = '<select class="form-select form-select-sm" name="ext_link_type_ids[]" required>';
            html += '<option value="">— pick a link type —</option>';
            catOrder.forEach(cat => {
                if (!byCat[cat] || !byCat[cat].length) return;
                html += '<optgroup label="' + escapeHtml(catLabels[cat] || cat) + '">';
                byCat[cat].forEach(t => {
                    const sel = (Number(selectedId) === Number(t.id)) ? ' selected' : '';
                    html += '<option value="' + Number(t.id) + '"' + sel + '>' + escapeHtml(t.name) + '</option>';
                });
                html += '</optgroup>';
            });
            html += '</select>';
            return html;
        }
        function buildLinkRow(data) {
            const card = document.createElement('div');
            card.className = 'card bg-dark border-secondary';
            const url  = String(data.url || '');
            const note = String(data.note || '');
            const ver  = data.verified ? 'checked' : '';
            card.innerHTML =
                '<div class="card-body py-2">' +
                  '<div class="d-flex align-items-start gap-2">' +
                    '<i class="bi bi-grip-vertical text-muted mt-2" aria-hidden="true"></i>' +
                    '<div class="flex-grow-1">' +
                      '<div class="row g-2 mb-1">' +
                        '<div class="col-md-5">' + buildLinkSelect(data.typeId || 0) + '</div>' +
                        '<div class="col-md-7">' +
                          '<input type="url" class="form-control form-control-sm" ' +
                                  'name="ext_link_urls[]" required maxlength="2048" ' +
                                  'placeholder="https://…" value="' + escapeHtml(url) + '">' +
                        '</div>' +
                      '</div>' +
                      '<div class="row g-2">' +
                        '<div class="col-md-9">' +
                          '<input type="text" class="form-control form-control-sm" ' +
                                  'name="ext_link_notes[]" maxlength="255" ' +
                                  'placeholder="Optional note" ' +
                                  'value="' + escapeHtml(note) + '">' +
                        '</div>' +
                        '<div class="col-md-3 d-flex align-items-center">' +
                          '<div class="form-check small">' +
                            '<input class="form-check-input" type="checkbox" ' +
                                    'name="ext_link_verified[]" value="1" ' + ver + '>' +
                            '<label class="form-check-label">Verified</label>' +
                          '</div>' +
                        '</div>' +
                      '</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" ' +
                            'data-action="remove-ext-link" title="Remove this link">' +
                      '<i class="bi bi-x-lg"></i>' +
                    '</button>' +
                  '</div>' +
                '</div>';
            card.querySelector('[data-action=remove-ext-link]')
                .addEventListener('click', () => card.remove());
            /* Auto-detect provider from the pasted URL — global module
               (#841) wires the row's URL input + select together. */
            if (window.iHymnsLinkDetect && typeof window.iHymnsLinkDetect.attachAutoDetect === 'function') {
                window.iHymnsLinkDetect.attachAutoDetect(card);
            }
            return card;
        }
        if (addLinkBtn) {
            addLinkBtn.onclick = () => {
                linkRowsEl.appendChild(buildLinkRow({ typeId: 0, url: '', note: '', verified: false }));
            };
        }

        /* === Song typeahead === */
        let searchAbortController = null;
        async function runSongSearch(q) {
            try {
                if (searchAbortController) searchAbortController.abort();
                searchAbortController = new AbortController();
                const res = await fetch('/manage/works?action=song_search&q=' + encodeURIComponent(q),
                    { signal: searchAbortController.signal });
                if (!res.ok) return;
                const data = await res.json();
                const items = (data && data.suggestions) || [];
                renderSuggestions(items);
            } catch (e) { /* aborted */ }
        }
        function renderSuggestions(items) {
            if (!items.length) { sugBox.style.display = 'none'; sugBox.innerHTML = ''; return; }
            sugBox.style.display = '';
            sugBox.innerHTML = items.map(it => {
                const label = (it.songbook || '') + (it.number ? (' #' + it.number) : '') + ' — ' + (it.title || it.songId);
                const json = JSON.stringify({
                    songId: it.songId, title: it.title,
                    songbook: it.songbook, number: it.number,
                });
                return '<a href="#" class="list-group-item list-group-item-action small" data-payload=\'' + escapeHtml(json) + '\'>' +
                    escapeHtml(label) + '</a>';
            }).join('');
            sugBox.querySelectorAll('a[data-payload]').forEach(a => {
                a.onclick = (ev) => {
                    ev.preventDefault();
                    let payload;
                    try { payload = JSON.parse(a.getAttribute('data-payload')); } catch (_e) { return; }
                    /* skip if already in the list */
                    if (tbody.querySelector('tr[data-song-id="' + (payload.songId || '').replace(/"/g, '\\"') + '"]')) {
                        sugBox.style.display = 'none';
                        search.value = '';
                        return;
                    }
                    tbody.insertAdjacentHTML('beforeend', memberRowHtml({
                        songId: payload.songId, title: payload.title,
                        songbook: payload.songbook, number: payload.number,
                        sortOrder: tbody.children.length * 10,
                        isCanonical: false, note: '',
                    }));
                    rebindRemoveButtons();
                    sugBox.style.display = 'none';
                    search.value = '';
                };
            });
        }
        if (search) {
            let t = null;
            search.addEventListener('input', () => {
                clearTimeout(t);
                const q = search.value.trim();
                t = setTimeout(() => runSongSearch(q), 180);
            });
            document.addEventListener('click', (e) => {
                if (!sugBox.contains(e.target) && e.target !== search) {
                    sugBox.style.display = 'none';
                }
            });
        }

        /* === Public openers === */
        window.openWorkEditModal = function (row) {
            document.getElementById('edit-work-id').value           = row.id;
            document.getElementById('edit-work-title').value        = row.title || '';
            document.getElementById('edit-work-title-label').textContent = row.title || '';
            document.getElementById('edit-work-slug').value         = row.slug  || '';
            document.getElementById('edit-work-iswc').value         = row.iswc  || '';
            document.getElementById('edit-work-notes').value        = row.notes || '';
            const psel = document.getElementById('edit-work-parent');
            psel.value = row.parent_id ? String(row.parent_id) : '';
            /* Hide the current work itself from the parent options to make
               accidental self-parent harder (server still rejects cycles). */
            for (const opt of psel.options) {
                opt.disabled = (opt.value && Number(opt.value) === Number(row.id));
            }

            tbody.innerHTML = (row.members || []).map(m => memberRowHtml(m)).join('');
            rebindRemoveButtons();

            if (linkRowsEl) {
                linkRowsEl.innerHTML = '';
                (row.links || []).forEach(l => {
                    linkRowsEl.appendChild(buildLinkRow({
                        typeId: l.typeId, url: l.url, note: l.note, verified: l.verified,
                    }));
                });
            }

            if (sugBox) { sugBox.style.display = 'none'; sugBox.innerHTML = ''; }
            if (search) search.value = '';

            editModal.show();
        };
        window.openWorkDeleteModal = function (row) {
            document.getElementById('delete-work-id').value = row.id;
            document.getElementById('delete-work-name-label').textContent = row.title || '';
            deleteModal.show();
        };
    })();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
