<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Languages
 *
 * CRUD surface for `tblLanguages` — the IETF BCP 47 language registry
 * that backs the Language picker (#681 / #685 / #687) and the language
 * filter (#737). Most rows are seeded from the IANA Language Subtag
 * Registry by migrate-iana-language-subtag-registry.php (#738) and
 * are correct out of the box; this page is the manual escape hatch
 * curators need for:
 *
 *   - Adding a private-use code (e.g. `qwx`) for a hymnal in a
 *     language IANA hasn't registered yet.
 *   - Fixing a NativeName the bundled CLDR data didn't cover.
 *   - Toggling IsActive to retire a deprecated subtag from the picker
 *     dropdown without dropping the row (cascading FK refs to
 *     tblSongs.Language and tblSongbooks.Language stay intact).
 *
 * Database access uses mysqli prepared statements throughout (project
 * policy, set 2026-04-27). Mutating actions emit a tblActivityLog row
 * with EntityType='language' so the audit trail mirrors songbooks /
 * credit-people / users.
 *
 * Gating:
 *   - manage_languages entitlement (admin + global_admin by default).
 *
 * Refuse-on-cite for delete:
 *   - tblSongs.Language and tblSongbooks.Language are soft FKs (no
 *     CHECK constraint), so a row can technically be deleted while
 *     songs still cite it. We pre-flight the count and refuse the
 *     delete unless ?force=1 is sent — same convention as
 *     credit-people / songbooks.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_languages', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_languages required</h1></body></html>';
    exit;
}
$activePage = 'languages';

$db   = getDbMysqli();
$csrf = csrfToken();

/* ----------------------------------------------------------------------
 * Activity-log helper. Best-effort — silent no-op if the helper or
 * table is missing (matches the pattern in songbooks.php / credit-
 * people.php).
 * ---------------------------------------------------------------------- */
$logLanguage = static function (string $action, string $code, array $details): void {
    if (function_exists('logActivity')) {
        try {
            logActivity('language.' . $action, 'language', $code, $details);
        } catch (\Throwable $_e) { /* audit is best-effort */ }
    }
};

/* ----------------------------------------------------------------------
 * Validators. Kept inline since they're short and only this page
 * uses them. The Code grammar follows BCP 47 — primary subtag is 2-3
 * lowercase letters or 4-8 lowercase letters for private-use, optionally
 * followed by hyphenated subtags (script, region, variant, etc).
 * ---------------------------------------------------------------------- */
$validateCode = static function (string $code): ?string {
    $code = trim($code);
    if ($code === '')                                   return 'Code is required.';
    if (strlen($code) > 35)                             return 'Code must be 35 characters or fewer.';
    if (!preg_match('/^[a-zA-Z0-9-]+$/', $code))        return 'Code may contain only letters, digits, and hyphens.';
    /* Soft-tolerant — IANA codes are lowercase primary, Title-case script,
       UPPERCASE region, but admins sometimes paste mixed case. We compare
       lowercase here; the picker lowercases on lookup so case differences
       don't matter for retrieval. */
    if (!preg_match('/^[a-z]{2,8}(-[a-z0-9]+)*$/', strtolower($code))) {
        return 'Code must look like an IETF BCP 47 tag (e.g. en, en-GB, zh-Hans).';
    }
    return null;
};

$validateName = static function (string $name): ?string {
    $name = trim($name);
    if ($name === '')              return 'Name is required.';
    if (strlen($name) > 250)       return 'Name must be 250 characters or fewer.';
    return null;
};

$validateNativeName = static function (string $native): ?string {
    /* NativeName is optional — empty is fine and explicitly meaningful
       (the picker falls back to Name when NativeName is blank). */
    if (strlen($native) > 250)     return 'NativeName must be 250 characters or fewer.';
    return null;
};

$validateTextDirection = static function (string $td): ?string {
    if (!in_array($td, ['ltr', 'rtl'], true)) {
        return 'TextDirection must be ltr or rtl.';
    }
    return null;
};

$ALLOWED_SCOPES = ['individual', 'macrolanguage', 'collection', 'private-use', 'special'];
$validateScope = static function (string $scope) use ($ALLOWED_SCOPES): ?string {
    if (!in_array($scope, $ALLOWED_SCOPES, true)) {
        return 'Scope must be one of: ' . implode(', ', $ALLOWED_SCOPES);
    }
    return null;
};

/* ----------------------------------------------------------------------
 * POST dispatcher — JSON in / JSON out so the page's client-side
 * helpers can fetch() it directly. Returns a `success` shape with
 * the affected row, or a `error` shape with a per-field message map
 * and HTTP 400. CSRF token comes from a hidden field in each modal
 * form; missing / wrong → 403.
 * ---------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid — refresh the page.']);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'create': {
                $code       = trim((string)($_POST['code']           ?? ''));
                $name       = trim((string)($_POST['name']           ?? ''));
                $native     = trim((string)($_POST['native_name']    ?? ''));
                $textDir    = trim((string)($_POST['text_direction'] ?? 'ltr'));
                $scope      = trim((string)($_POST['scope']          ?? 'individual'));
                $isActive   = !empty($_POST['is_active']) ? 1 : 0;

                $errors = array_filter([
                    'code'           => $validateCode($code),
                    'name'           => $validateName($name),
                    'native_name'    => $validateNativeName($native),
                    'text_direction' => $validateTextDirection($textDir),
                    'scope'          => $validateScope($scope),
                ]);
                if ($errors) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Validation failed.', 'fields' => $errors]);
                    exit;
                }

                /* Check the Code isn't already taken — friendlier than
                   waiting for the unique-key violation. */
                $stmt = $db->prepare('SELECT 1 FROM tblLanguages WHERE Code = ? LIMIT 1');
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) {
                    http_response_code(400);
                    echo json_encode(['error' => 'A language with this code already exists.', 'fields' => ['code' => 'Already in use.']]);
                    exit;
                }

                $stmt = $db->prepare(
                    'INSERT INTO tblLanguages (Code, Name, NativeName, TextDirection, Scope, IsActive)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('sssssi', $code, $name, $native, $textDir, $scope, $isActive);
                $stmt->execute();
                $stmt->close();

                $logLanguage('create', $code, [
                    'name'        => $name,
                    'native_name' => $native,
                    'scope'       => $scope,
                    'text_dir'    => $textDir,
                    'is_active'   => $isActive,
                ]);

                echo json_encode(['success' => true, 'code' => $code]);
                exit;
            }

            case 'update': {
                $code       = trim((string)($_POST['code']           ?? ''));
                $name       = trim((string)($_POST['name']           ?? ''));
                $native     = trim((string)($_POST['native_name']    ?? ''));
                $textDir    = trim((string)($_POST['text_direction'] ?? 'ltr'));
                $scope      = trim((string)($_POST['scope']          ?? 'individual'));
                $isActive   = !empty($_POST['is_active']) ? 1 : 0;

                $errors = array_filter([
                    'code'           => $validateCode($code),
                    'name'           => $validateName($name),
                    'native_name'    => $validateNativeName($native),
                    'text_direction' => $validateTextDirection($textDir),
                    'scope'          => $validateScope($scope),
                ]);
                if ($errors) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Validation failed.', 'fields' => $errors]);
                    exit;
                }

                /* Capture the before-state for the audit row. */
                $stmt = $db->prepare(
                    'SELECT Code, Name, NativeName, TextDirection, Scope, IsActive
                       FROM tblLanguages WHERE Code = ? LIMIT 1'
                );
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $before = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$before) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Language not found.']);
                    exit;
                }

                $stmt = $db->prepare(
                    'UPDATE tblLanguages
                        SET Name = ?, NativeName = ?, TextDirection = ?, Scope = ?, IsActive = ?
                      WHERE Code = ?'
                );
                $stmt->bind_param('ssssis', $name, $native, $textDir, $scope, $isActive, $code);
                $stmt->execute();
                $stmt->close();

                /* Build a compact diff so the audit row is useful. Skip
                   fields whose value didn't change. */
                $after = [
                    'Name' => $name, 'NativeName' => $native,
                    'TextDirection' => $textDir, 'Scope' => $scope,
                    'IsActive' => $isActive,
                ];
                $diff = [];
                foreach ($after as $k => $v) {
                    if ((string)($before[$k] ?? '') !== (string)$v) {
                        $diff[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
                    }
                }
                if ($diff) {
                    $logLanguage('edit', $code, ['diff' => $diff]);
                }

                echo json_encode(['success' => true, 'code' => $code, 'changed' => count($diff)]);
                exit;
            }

            case 'toggle_active': {
                /* Cheap one-shot toggle — used by the table's per-row
                   on/off switch. Same audit shape as a tiny `update`. */
                $code     = trim((string)($_POST['code'] ?? ''));
                $isActive = !empty($_POST['is_active']) ? 1 : 0;
                if ($code === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing code.']);
                    exit;
                }
                $stmt = $db->prepare('UPDATE tblLanguages SET IsActive = ? WHERE Code = ?');
                $stmt->bind_param('is', $isActive, $code);
                $stmt->execute();
                $touched = $stmt->affected_rows;
                $stmt->close();

                if ($touched === 0) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Language not found, or value already matched.']);
                    exit;
                }

                $logLanguage('toggle_active', $code, ['is_active' => $isActive]);
                echo json_encode(['success' => true, 'code' => $code, 'is_active' => $isActive]);
                exit;
            }

            case 'delete': {
                $code  = trim((string)($_POST['code']  ?? ''));
                $force = !empty($_POST['force']);
                if ($code === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing code.']);
                    exit;
                }

                /* Pre-flight cite count across tblSongs.Language and
                   tblSongbooks.Language. The picker normalises tags to
                   lowercase on the BCP 47 primary subtag, so we match
                   against that prefix as well as the exact code so a
                   row 'en' surfaces every 'en', 'en-GB', 'en-US' that
                   uses it. */
                $likePrefix = $code . '-%';
                $stmt = $db->prepare(
                    'SELECT
                        (SELECT COUNT(*) FROM tblSongs     WHERE Language = ? OR Language LIKE ?) AS songs,
                        (SELECT COUNT(*) FROM tblSongbooks WHERE Language = ? OR Language LIKE ?) AS songbooks'
                );
                $stmt->bind_param('ssss', $code, $likePrefix, $code, $likePrefix);
                $stmt->execute();
                $usage = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $songCount     = (int)($usage['songs']     ?? 0);
                $songbookCount = (int)($usage['songbooks'] ?? 0);

                if (!$force && ($songCount + $songbookCount) > 0) {
                    http_response_code(409);
                    echo json_encode([
                        'error'      => 'Language is in use.',
                        'songs'      => $songCount,
                        'songbooks'  => $songbookCount,
                        'requires_force' => true,
                    ]);
                    exit;
                }

                $stmt = $db->prepare('DELETE FROM tblLanguages WHERE Code = ?');
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $deleted = $stmt->affected_rows;
                $stmt->close();

                if ($deleted === 0) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Language not found.']);
                    exit;
                }

                $logLanguage('delete', $code, [
                    'forced'         => $force ? 1 : 0,
                    'songs_at_time'  => $songCount,
                    'sbooks_at_time' => $songbookCount,
                ]);
                echo json_encode(['success' => true, 'code' => $code]);
                exit;
            }

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action: ' . $action]);
                exit;
        }
    } catch (\Throwable $e) {
        error_log('[manage/languages] action=' . $action . ' failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error — see server log for details.']);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * Read-side: paginate the catalogue. tblLanguages can hold ~8000 rows
 * after the IANA registry import (#738), so unbounded SELECT * is a
 * no-go. The UI's filter inputs append query-string params; the page
 * re-renders on each filter change.
 * ---------------------------------------------------------------------- */
$pageSize     = 50;
$pageNum      = max(1, (int)($_GET['p']  ?? 1));
$qFilter      = trim((string)($_GET['q'] ?? ''));
$activeFilter = (string)($_GET['active'] ?? 'all');   // all | active | inactive
$scopeFilter  = (string)($_GET['scope']  ?? 'all');
$tdFilter     = (string)($_GET['td']     ?? 'all');   // all | ltr | rtl

$where  = [];
$types  = '';
$params = [];

if ($qFilter !== '') {
    $where[] = '(Code LIKE ? OR Name LIKE ? OR NativeName LIKE ?)';
    $like    = '%' . $qFilter . '%';
    $types  .= 'sss';
    $params  = array_merge($params, [$like, $like, $like]);
}
if ($activeFilter === 'active')   { $where[] = 'IsActive = 1'; }
if ($activeFilter === 'inactive') { $where[] = 'IsActive = 0'; }
if (in_array($scopeFilter, $ALLOWED_SCOPES, true)) {
    $where[] = 'Scope = ?';
    $types  .= 's';
    $params[] = $scopeFilter;
}
if (in_array($tdFilter, ['ltr', 'rtl'], true)) {
    $where[] = 'TextDirection = ?';
    $types  .= 's';
    $params[] = $tdFilter;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

/* Count first so the pager can render. */
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM tblLanguages' . $whereSql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalRows = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$pageNum    = min($pageNum, $totalPages);
$offset     = ($pageNum - 1) * $pageSize;

/* The result page itself. */
$stmt = $db->prepare(
    'SELECT Code, Name, NativeName, TextDirection, Scope, IsActive
       FROM tblLanguages' . $whereSql . '
      ORDER BY Code ASC
      LIMIT ? OFFSET ?'
);
$bindTypes  = $types . 'ii';
$bindParams = array_merge($params, [$pageSize, $offset]);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Sidebar summary counts so the curator sees catalogue health at a
   glance — no extra round-trip per row. */
$summary = $db->query(
    "SELECT
        COUNT(*) AS total,
        SUM(IsActive)                                    AS active,
        SUM(CASE WHEN NativeName <> '' THEN 1 ELSE 0 END) AS native_filled,
        SUM(CASE WHEN TextDirection = 'rtl' THEN 1 ELSE 0 END) AS rtl
       FROM tblLanguages"
)->fetch_assoc();

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Languages — iHymns Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="/css/admin.css">
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-translate me-2"></i>Languages
            </h1>
            <p class="text-secondary small mb-0">
                IETF BCP 47 language registry (<code>tblLanguages</code>) — backs the language picker (#681 / #685) and the language filter (#737).
                Most rows are seeded from the IANA registry by <code>migrate-iana-language-subtag-registry.php</code>; use this surface for private-use codes, NativeName fixes, and retiring deprecated subtags.
            </p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#languageModal" data-mode="create">
            <i class="bi bi-plus-lg me-1"></i>Add language
        </button>
    </div>

    <!-- Summary chips: total / active / native-filled / rtl. Cheap aggregate
         tied to the unfiltered catalogue so curators see baseline health
         without losing their current filter. -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card bg-dark border-secondary">
                <div class="card-body p-3">
                    <div class="text-secondary small">Total</div>
                    <div class="h5 mb-0"><?= number_format((int)$summary['total']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-dark border-secondary">
                <div class="card-body p-3">
                    <div class="text-secondary small">Active</div>
                    <div class="h5 mb-0"><?= number_format((int)$summary['active']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-dark border-secondary">
                <div class="card-body p-3">
                    <div class="text-secondary small">NativeName filled</div>
                    <div class="h5 mb-0"><?= number_format((int)$summary['native_filled']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-dark border-secondary">
                <div class="card-body p-3">
                    <div class="text-secondary small">RTL languages</div>
                    <div class="h5 mb-0"><?= number_format((int)$summary['rtl']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter row. Submits via GET so the URL is shareable and the
         server-side pager picks up the same params. -->
    <form method="get" class="row g-2 mb-3" role="search">
        <div class="col-md-4">
            <label class="form-label small text-muted" for="q">Search</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q"
                   value="<?= htmlspecialchars($qFilter, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="code, name, or native name">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted" for="active">Active</label>
            <select class="form-select form-select-sm" id="active" name="active">
                <option value="all"      <?= $activeFilter === 'all'      ? 'selected' : '' ?>>All</option>
                <option value="active"   <?= $activeFilter === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $activeFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted" for="scope">Scope</label>
            <select class="form-select form-select-sm" id="scope" name="scope">
                <option value="all" <?= $scopeFilter === 'all' ? 'selected' : '' ?>>All</option>
                <?php foreach ($ALLOWED_SCOPES as $s): ?>
                    <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>" <?= $scopeFilter === $s ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted" for="td">Direction</label>
            <select class="form-select form-select-sm" id="td" name="td">
                <option value="all" <?= $tdFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="ltr" <?= $tdFilter === 'ltr' ? 'selected' : '' ?>>LTR</option>
                <option value="rtl" <?= $tdFilter === 'rtl' ? 'selected' : '' ?>>RTL</option>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-secondary btn-sm w-100">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
        </div>
    </form>

    <p class="small text-secondary mb-2">
        Showing <strong><?= number_format(count($rows)) ?></strong> of
        <strong><?= number_format($totalRows) ?></strong> matching language(s).
        Page <?= $pageNum ?> of <?= $totalPages ?>.
    </p>

    <div class="table-responsive">
        <table class="table table-dark table-striped table-hover align-middle cp-sortable" id="languages-table">
            <thead>
                <tr>
                    <th scope="col" data-sort-key="code" data-sort-type="text">Code</th>
                    <th scope="col" data-sort-key="name" data-sort-type="text">Name</th>
                    <th scope="col" data-sort-key="nativeName" data-sort-type="text">NativeName</th>
                    <th scope="col" class="text-center" data-sort-key="direction" data-sort-type="text">Direction</th>
                    <th scope="col" data-sort-key="scope" data-sort-type="text">Scope</th>
                    <th scope="col" class="text-center" data-sort-key="active" data-sort-type="text">Active</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-secondary py-4">
                    No languages match these filters.
                </td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr data-code="<?= htmlspecialchars($r['Code'], ENT_QUOTES, 'UTF-8') ?>">
                    <td><code><?= htmlspecialchars($r['Code'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($r['Name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td <?= $r['TextDirection'] === 'rtl' ? 'dir="rtl"' : '' ?>>
                        <?= $r['NativeName'] !== ''
                            ? htmlspecialchars($r['NativeName'], ENT_QUOTES, 'UTF-8')
                            : '<span class="text-secondary">—</span>' ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $r['TextDirection'] === 'rtl' ? 'warning text-dark' : 'secondary' ?>">
                            <?= htmlspecialchars($r['TextDirection'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td><span class="small text-secondary"><?= htmlspecialchars($r['Scope'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td class="text-center">
                        <div class="form-check form-switch d-inline-block m-0">
                            <input class="form-check-input lang-active-toggle" type="checkbox"
                                   <?= $r['IsActive'] ? 'checked' : '' ?>
                                   aria-label="Toggle active for <?= htmlspecialchars($r['Code'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-info lang-edit-btn"
                                data-bs-toggle="modal" data-bs-target="#languageModal" data-mode="edit"
                                data-code="<?= htmlspecialchars($r['Code'], ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars($r['Name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-native="<?= htmlspecialchars($r['NativeName'], ENT_QUOTES, 'UTF-8') ?>"
                                data-td="<?= htmlspecialchars($r['TextDirection'], ENT_QUOTES, 'UTF-8') ?>"
                                data-scope="<?= htmlspecialchars($r['Scope'], ENT_QUOTES, 'UTF-8') ?>"
                                data-active="<?= (int)$r['IsActive'] ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger lang-delete-btn"
                                data-code="<?= htmlspecialchars($r['Code'], ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars($r['Name'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav aria-label="Pagination">
            <ul class="pagination pagination-sm justify-content-center">
                <?php
                /* Compact pager: first, prev, current ± 2, next, last.
                   Preserves all filter params via $queryParams. */
                $queryParams = $_GET;
                $linkFor = static function (int $p) use ($queryParams) {
                    $queryParams['p'] = $p;
                    return '?' . http_build_query($queryParams);
                };
                $windowStart = max(1, $pageNum - 2);
                $windowEnd   = min($totalPages, $pageNum + 2);
                ?>
                <li class="page-item <?= $pageNum === 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $linkFor(1) ?>">«</a>
                </li>
                <li class="page-item <?= $pageNum === 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $linkFor(max(1, $pageNum - 1)) ?>">‹</a>
                </li>
                <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                    <li class="page-item <?= $p === $pageNum ? 'active' : '' ?>">
                        <a class="page-link" href="<?= $linkFor($p) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $pageNum === $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $linkFor(min($totalPages, $pageNum + 1)) ?>">›</a>
                </li>
                <li class="page-item <?= $pageNum === $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $linkFor($totalPages) ?>">»</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</main>

<!-- Add / Edit modal — one form, mode-switched on open via data-mode. -->
<div class="modal fade" id="languageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light">
            <form id="languageForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" id="lm-action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title" id="languageModalTitle">Add language</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="lm-code" class="form-label">
                            Code <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="lm-code" name="code"
                               placeholder="e.g. en, en-GB, zh-Hans"
                               maxlength="35" required>
                        <div class="form-text small">
                            BCP 47 tag — primary subtag (lowercase ISO 639) optionally followed by Script (Title-case ISO 15924) and Region (UPPERCASE ISO 3166-1).
                        </div>
                        <div class="invalid-feedback" data-field="code"></div>
                    </div>
                    <div class="mb-3">
                        <label for="lm-name" class="form-label">
                            Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="lm-name" name="name"
                               placeholder="English-language label, e.g. German" maxlength="250" required>
                        <div class="invalid-feedback" data-field="name"></div>
                    </div>
                    <div class="mb-3">
                        <label for="lm-native-name" class="form-label">NativeName</label>
                        <input type="text" class="form-control" id="lm-native-name" name="native_name"
                               placeholder="self-name, e.g. Deutsch" maxlength="250">
                        <div class="form-text small">
                            Optional. Shown alongside the English Name in the IETF picker (#681).
                        </div>
                        <div class="invalid-feedback" data-field="native_name"></div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label for="lm-text-direction" class="form-label">Direction</label>
                            <select class="form-select" id="lm-text-direction" name="text_direction">
                                <option value="ltr">LTR (left-to-right)</option>
                                <option value="rtl">RTL (right-to-left)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="lm-scope" class="form-label">Scope</label>
                            <select class="form-select" id="lm-scope" name="scope">
                                <?php foreach ($ALLOWED_SCOPES as $s): ?>
                                    <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="lm-is-active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="lm-is-active">Active (appears in picker dropdown)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="lm-submit">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete confirmation modal — supports the "row is in use" → "force"
     two-step. -->
<div class="modal fade" id="languageDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header">
                <h5 class="modal-title">Delete language</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Permanently remove <code id="ldm-code">…</code> (<span id="ldm-name">…</span>) from <code>tblLanguages</code>?</p>
                <div id="ldm-usage-warning" class="alert alert-warning d-none" role="alert">
                    <strong>This language is in use.</strong>
                    <span id="ldm-usage-detail"></span>
                    Deleting will leave <code>tblSongs.Language</code> / <code>tblSongbooks.Language</code> values pointing at a code that's no longer in the registry — the picker won't surface it again, and existing rows will keep their tag string.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="ldm-confirm">Delete</button>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

<script>
(function () {
    'use strict';

    const CSRF       = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
    const ACTION_URL = window.location.pathname; // POST back to /manage/languages

    /* ---- Modal: add / edit ------------------------------------------- */
    const modalEl   = document.getElementById('languageModal');
    const modalTitle= document.getElementById('languageModalTitle');
    const formEl    = document.getElementById('languageForm');
    const actionInp = document.getElementById('lm-action');
    const codeInp   = document.getElementById('lm-code');
    const nameInp   = document.getElementById('lm-name');
    const nativeInp = document.getElementById('lm-native-name');
    const tdSel     = document.getElementById('lm-text-direction');
    const scopeSel  = document.getElementById('lm-scope');
    const activeInp = document.getElementById('lm-is-active');
    const submitBtn = document.getElementById('lm-submit');

    /* Reset the inline error feedback before each open so a previous
       failed save doesn't leak red borders into the next attempt. */
    const clearFieldErrors = () => {
        formEl.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        formEl.querySelectorAll('.invalid-feedback').forEach(el => { el.textContent = ''; });
    };

    modalEl.addEventListener('show.bs.modal', (event) => {
        clearFieldErrors();
        const trigger = event.relatedTarget;
        const mode    = trigger?.dataset?.mode || 'create';
        actionInp.value = mode;
        if (mode === 'edit') {
            modalTitle.textContent = 'Edit language';
            codeInp.value   = trigger.dataset.code   || '';
            codeInp.readOnly = true; // Code is the PK; rename = delete + create
            nameInp.value   = trigger.dataset.name   || '';
            nativeInp.value = trigger.dataset.native || '';
            tdSel.value     = trigger.dataset.td     || 'ltr';
            scopeSel.value  = trigger.dataset.scope  || 'individual';
            activeInp.checked = trigger.dataset.active === '1';
        } else {
            modalTitle.textContent = 'Add language';
            codeInp.readOnly = false;
            formEl.reset();
            actionInp.value = 'create';
            tdSel.value     = 'ltr';
            scopeSel.value  = 'individual';
            activeInp.checked = true;
        }
    });

    formEl.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFieldErrors();
        submitBtn.disabled = true;
        const original = submitBtn.textContent;
        submitBtn.textContent = 'Saving…';

        const payload = new URLSearchParams(new FormData(formEl));
        try {
            const r = await fetch(ACTION_URL, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload,
            });
            const d = await r.json().catch(() => ({}));
            if (!r.ok) {
                /* Per-field error feedback from the server side
                   validators. The fields object keys match the
                   form element name attributes. */
                if (d?.fields) {
                    for (const [key, msg] of Object.entries(d.fields)) {
                        const fb = formEl.querySelector(`.invalid-feedback[data-field="${key}"]`);
                        const inp = formEl.querySelector(`[name="${key}"]`);
                        if (inp) inp.classList.add('is-invalid');
                        if (fb)  fb.textContent = msg;
                    }
                }
                if (d?.error) {
                    /* Surface the top-level error in the form footer. */
                    let banner = formEl.querySelector('.alert-danger');
                    if (!banner) {
                        banner = document.createElement('div');
                        banner.className = 'alert alert-danger small';
                        formEl.querySelector('.modal-body').prepend(banner);
                    }
                    banner.textContent = d.error;
                }
                return;
            }
            /* Success — reload to pick up the new/updated row. */
            window.location.reload();
        } catch (err) {
            console.error('[languages] save failed:', err);
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = original;
        }
    });

    /* ---- Per-row IsActive toggle ------------------------------------- */
    document.querySelectorAll('.lang-active-toggle').forEach((cb) => {
        cb.addEventListener('change', async (event) => {
            const row     = event.target.closest('tr');
            const code    = row?.dataset?.code;
            const isActive = event.target.checked;
            if (!code) return;

            const body = new URLSearchParams({
                csrf_token: CSRF,
                action:     'toggle_active',
                code:       code,
                is_active:  isActive ? '1' : '',
            });
            try {
                const r = await fetch(ACTION_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: body,
                });
                if (!r.ok) {
                    /* Revert the visible toggle on failure so the UI
                       reflects the actual stored state. */
                    event.target.checked = !isActive;
                }
            } catch (err) {
                event.target.checked = !isActive;
                console.error('[languages] toggle_active failed:', err);
            }
        });
    });

    /* ---- Delete flow ------------------------------------------------- */
    const dmEl       = document.getElementById('languageDeleteModal');
    const dmCodeEl   = document.getElementById('ldm-code');
    const dmNameEl   = document.getElementById('ldm-name');
    const dmWarnEl   = document.getElementById('ldm-usage-warning');
    const dmDetailEl = document.getElementById('ldm-usage-detail');
    const dmConfirm  = document.getElementById('ldm-confirm');
    const dmModal    = bootstrap.Modal.getOrCreateInstance(dmEl);

    let pendingDelete = null; // { code, force }

    document.querySelectorAll('.lang-delete-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            pendingDelete = { code: btn.dataset.code, force: false };
            dmCodeEl.textContent = btn.dataset.code;
            dmNameEl.textContent = btn.dataset.name || '';
            dmWarnEl.classList.add('d-none');
            dmDetailEl.textContent = '';
            dmConfirm.textContent  = 'Delete';
            dmConfirm.classList.remove('btn-warning');
            dmConfirm.classList.add('btn-danger');
            dmModal.show();
        });
    });

    dmConfirm.addEventListener('click', async () => {
        if (!pendingDelete) return;
        dmConfirm.disabled = true;
        const body = new URLSearchParams({
            csrf_token: CSRF,
            action:     'delete',
            code:       pendingDelete.code,
            force:      pendingDelete.force ? '1' : '',
        });
        try {
            const r = await fetch(ACTION_URL, {
                method: 'POST',
                credentials: 'same-origin',
                body: body,
            });
            const d = await r.json().catch(() => ({}));
            if (r.status === 409 && d?.requires_force) {
                /* Two-step: first click triggered the cite-count check;
                   surface the warning and switch the button into a
                   "force delete" red+warning state. */
                pendingDelete.force = true;
                dmDetailEl.textContent =
                    ` ${d.songs ?? 0} song(s) and ${d.songbooks ?? 0} songbook(s) cite this language. ` +
                    'Click again to delete anyway.';
                dmWarnEl.classList.remove('d-none');
                dmConfirm.textContent = 'Delete anyway';
                dmConfirm.classList.add('btn-warning');
                return;
            }
            if (!r.ok) {
                dmDetailEl.textContent = d?.error || 'Delete failed.';
                dmWarnEl.classList.remove('d-none');
                return;
            }
            window.location.reload();
        } catch (err) {
            console.error('[languages] delete failed:', err);
        } finally {
            dmConfirm.disabled = false;
        }
    });
})();
</script>
</body>
</html>
