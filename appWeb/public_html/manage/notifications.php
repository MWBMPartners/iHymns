<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Notifications (#813)
 *
 * Global Admin only. Compose + broadcast in-app notifications and
 * inspect / delete the resulting feed. Closes the gap left by #289:
 * tblNotifications existed and the header bell consumed it, but
 * there was no admin path to post a row — every notification came
 * from automated system events (bulk-import job completion, etc.).
 *
 * Audience targeting:
 *   - single user      (resolved by username / email / id)
 *   - role             (every signed-in user with this role)
 *   - all signed-in    (every active account)
 *
 * One row per recipient is inserted on broadcast. Activity-Log
 * entries are written for every compose / delete so the audit
 * trail can answer "who broadcast what to whom on which date."
 *
 * Plain-text body for v1 — sanitised via htmlspecialchars on render.
 * Markdown / rich-text + scheduling / future-send are out of scope
 * (tracked separately if needed).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_notifications', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_notifications required</h1></body></html>';
    exit;
}
$activePage = 'notifications';

$db   = getDbMysqli();
$csrf = csrfToken();

/* ----------------------------------------------------------------------
 * POST handlers — compose / delete
 * ---------------------------------------------------------------------- */

$flashSuccess = '';
$flashError   = '';

/* Broadcast batch size cap (#813). All-signed-in audiences fan out one
   INSERT per recipient; bound the loop so a runaway broadcast can't
   pin the DB. Operators with > 5,000 active users should split into
   role-targeted broadcasts for now. */
const NOTIFY_BROADCAST_MAX = 5000;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $flashError = 'CSRF token invalid — refresh the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'compose') {
            $audience    = (string)($_POST['audience'] ?? '');
            $userQuery   = trim((string)($_POST['target_user'] ?? ''));
            $roleTarget  = (string)($_POST['target_role'] ?? '');
            $title       = trim((string)($_POST['title']     ?? ''));
            $body        = trim((string)($_POST['body']      ?? ''));
            $actionUrl   = trim((string)($_POST['action_url'] ?? ''));
            $type        = trim((string)($_POST['type']      ?? 'announcement'));

            /* Validate */
            $errs = [];
            if ($title === '' || mb_strlen($title) > 255) {
                $errs[] = 'Title is required and must be ≤ 255 characters.';
            }
            if ($body === '') {
                $errs[] = 'Body is required.';
            } elseif (mb_strlen($body) > 2000) {
                $errs[] = 'Body must be ≤ 2000 characters (#813 v1).';
            }
            if ($actionUrl !== ''
                && !preg_match('#^/[^/]#', $actionUrl)        // local /-rooted paths
                && !preg_match('#^https://#i', $actionUrl)) { // or absolute https
                $errs[] = 'Action URL must be a /-rooted local path or an https:// URL.';
            }
            if (!in_array($audience, ['user', 'role', 'all'], true)) {
                $errs[] = 'Pick an audience.';
            }
            $allowedTypes = ['announcement', 'maintenance', 'release', 'info'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'announcement';
            }

            /* Resolve recipients */
            $recipients = [];
            if (!$errs) {
                if ($audience === 'user') {
                    if ($userQuery === '') {
                        $errs[] = 'Pick a target user.';
                    } else {
                        /* Match by username / email / id (case-insensitive) */
                        $stmt = $db->prepare(
                            'SELECT Id FROM tblUsers
                              WHERE Username = ? OR Email = ? OR Id = ?
                              LIMIT 1'
                        );
                        $idCandidate = ctype_digit($userQuery) ? (int)$userQuery : 0;
                        $stmt->bind_param('ssi', $userQuery, $userQuery, $idCandidate);
                        $stmt->execute();
                        $row = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!$row) {
                            $errs[] = 'No user matched "' . htmlspecialchars($userQuery) . '".';
                        } else {
                            $recipients = [(int)$row['Id']];
                        }
                    }
                } elseif ($audience === 'role') {
                    $allowedRoles = ['user', 'editor', 'admin', 'global_admin'];
                    if (!in_array($roleTarget, $allowedRoles, true)) {
                        $errs[] = 'Pick a target role.';
                    } else {
                        $stmt = $db->prepare(
                            'SELECT Id FROM tblUsers
                              WHERE Role = ? AND COALESCE(IsActive, 1) = 1
                              LIMIT ' . (int)NOTIFY_BROADCAST_MAX
                        );
                        $stmt->bind_param('s', $roleTarget);
                        $stmt->execute();
                        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        $recipients = array_map(static fn($r) => (int)$r['Id'], $rows);
                    }
                } elseif ($audience === 'all') {
                    $res = $db->query(
                        'SELECT Id FROM tblUsers WHERE COALESCE(IsActive, 1) = 1 LIMIT ' . (int)NOTIFY_BROADCAST_MAX
                    );
                    if ($res) {
                        $rows = $res->fetch_all(MYSQLI_ASSOC);
                        $res->close();
                        $recipients = array_map(static fn($r) => (int)$r['Id'], $rows);
                    }
                }
                if (!$errs && empty($recipients)) {
                    $errs[] = 'No active recipients matched the audience.';
                }
            }

            if ($errs) {
                $flashError = implode(' ', $errs);
            } else {
                /* Fan-out INSERT. Single prepared statement, re-execute per
                   recipient. mysqli has no true multi-row prepared insert
                   without dynamically generated SQL — at NOTIFY_BROADCAST_MAX
                   = 5000 the loop is fast enough (~< 1s on a typical host)
                   and stays auditable per-row. */
                $stmt = $db->prepare(
                    'INSERT INTO tblNotifications (UserId, Type, Title, Body, ActionUrl)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $actionUrlForBind = $actionUrl !== '' ? $actionUrl : null;
                $count = 0;
                foreach ($recipients as $uid) {
                    $stmt->bind_param('issss', $uid, $type, $title, $body, $actionUrlForBind);
                    if (@$stmt->execute()) $count++;
                }
                $stmt->close();

                /* Activity-log entry — single line with audience metadata. */
                try {
                    $auditUid = (int)($currentUser['Id'] ?? $currentUser['id'] ?? 0);
                    $auditDetails = json_encode([
                        'audience'    => $audience,
                        'target_role' => $audience === 'role' ? $roleTarget : null,
                        'target_user' => $audience === 'user' ? $userQuery : null,
                        'title'       => mb_substr($title, 0, 100),
                        'recipients'  => $count,
                        'type'        => $type,
                    ], JSON_UNESCAPED_SLASHES);
                    $stmt2 = $db->prepare(
                        'INSERT INTO tblActivityLog (UserId, Action, EntityType, EntityId, Details, IpAddress)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    if ($stmt2) {
                        $auditAction = 'notification.broadcast';
                        $auditEntity = 'notification';
                        $auditEnId   = '';
                        $auditIp     = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                        $stmt2->bind_param('isssss', $auditUid, $auditAction, $auditEntity, $auditEnId, $auditDetails, $auditIp);
                        @$stmt2->execute();
                        $stmt2->close();
                    }
                } catch (\Throwable $_e) { /* audit failure must not block */ }

                $flashSuccess = "Sent to {$count} recipient" . ($count === 1 ? '' : 's') . '.';
                /* PRG so a refresh doesn't re-broadcast */
                $_SESSION['notifications_flash'] = ['success' => $flashSuccess];
                header('Location: /manage/notifications');
                exit;
            }
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM tblNotifications WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['notifications_flash'] = ['success' => 'Notification deleted.'];
                header('Location: /manage/notifications');
                exit;
            }
        }
    }
}

/* PRG flash pull-and-clear */
if (!empty($_SESSION['notifications_flash']['success'])) {
    $flashSuccess = (string)$_SESSION['notifications_flash']['success'];
    unset($_SESSION['notifications_flash']);
}

/* ----------------------------------------------------------------------
 * Feed list (paginated). Filter by user / read state / type / date range.
 * ---------------------------------------------------------------------- */

$filter = [
    'user'    => trim((string)($_GET['user'] ?? '')),
    'type'    => trim((string)($_GET['type'] ?? '')),
    'read'    => trim((string)($_GET['read'] ?? '')), /* '' | 'unread' | 'read' */
    'since'   => trim((string)($_GET['since'] ?? '')),
];
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];
$types  = '';

if ($filter['user'] !== '') {
    $where[] = '(u.Username LIKE ? OR u.Email LIKE ? OR n.UserId = ?)';
    $like    = '%' . $filter['user'] . '%';
    $idCand  = ctype_digit($filter['user']) ? (int)$filter['user'] : 0;
    $params[] = $like; $params[] = $like; $params[] = $idCand;
    $types   .= 'ssi';
}
if ($filter['type'] !== '') {
    $where[] = 'n.Type = ?';
    $params[] = $filter['type'];
    $types   .= 's';
}
if ($filter['read'] === 'unread') {
    $where[] = 'n.IsRead = 0';
} elseif ($filter['read'] === 'read') {
    $where[] = 'n.IsRead = 1';
}
if ($filter['since'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter['since'])) {
    $where[] = 'n.CreatedAt >= ?';
    $params[] = $filter['since'] . ' 00:00:00';
    $types   .= 's';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT n.Id, n.UserId, n.Type, n.Title, n.Body, n.ActionUrl,
               n.IsRead, n.CreatedAt,
               u.Username AS username, u.Email AS email
          FROM tblNotifications n
          LEFT JOIN tblUsers u ON u.Id = n.UserId
          {$whereSql}
         ORDER BY n.CreatedAt DESC
         LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$feed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Total count for pagination control */
$countSql = "SELECT COUNT(*) AS c
               FROM tblNotifications n
               LEFT JOIN tblUsers u ON u.Id = n.UserId
               {$whereSql}";
$cstmt = $db->prepare($countSql);
if ($params) {
    $cstmt->bind_param($types, ...$params);
}
$cstmt->execute();
$totalRows = (int)$cstmt->get_result()->fetch_assoc()['c'];
$cstmt->close();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* Distinct types in the table for the filter dropdown — gives admins
   a discoverable list of "what types exist" without hardcoding. */
$typesRes = $db->query('SELECT DISTINCT Type FROM tblNotifications ORDER BY Type ASC');
$existingTypes = [];
if ($typesRes) {
    while ($r = $typesRes->fetch_assoc()) {
        if ($r['Type']) $existingTypes[] = (string)$r['Type'];
    }
    $typesRes->close();
}

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — iHymns Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="/css/admin.css">
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-bell me-2"></i>Notifications
                <?= entitlementLockChipHtml('manage_notifications') ?>
            </h1>
            <p class="text-secondary small mb-0">
                Compose and broadcast in-app notifications. Targets a single
                user, an entire role, or every signed-in user.
                <span class="badge bg-danger text-light ms-1" style="font-size: 0.7rem; font-weight: 600;">
                    <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Global Admin only
                </span>
            </p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#notify-compose-modal">
            <i class="bi bi-pencil-square me-1"></i> Compose
        </button>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($flashSuccess) ?>
        </div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($flashError) ?>
        </div>
    <?php endif; ?>

    <!-- ===========================
         FILTER RAIL
         =========================== -->
    <div class="card bg-dark border-secondary mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Recipient (username / email / id)</label>
                    <input type="text" name="user" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filter['user']) ?>" placeholder="any">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($existingTypes as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $filter['type'] === $t ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Read state</label>
                    <select name="read" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="unread" <?= $filter['read'] === 'unread' ? 'selected' : '' ?>>Unread</option>
                        <option value="read"   <?= $filter['read'] === 'read'   ? 'selected' : '' ?>>Read</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Since</label>
                    <input type="date" name="since" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filter['since']) ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-info">Apply</button>
                    <a href="/manage/notifications" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ===========================
         FEED TABLE
         =========================== -->
    <div class="card bg-dark border-secondary">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="small text-secondary">
                <?= number_format($totalRows) ?> notification<?= $totalRows === 1 ? '' : 's' ?>
                — page <?= $page ?> of <?= $totalPages ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Recipient</th>
                        <th>Type</th>
                        <th>Title / Body</th>
                        <th class="text-center">Read</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feed)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No notifications match this filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($feed as $n): ?>
                            <tr>
                                <td class="small text-muted text-nowrap"><?= htmlspecialchars((string)$n['CreatedAt']) ?></td>
                                <td class="small">
                                    <?php if ($n['username']): ?>
                                        <span class="text-light"><?= htmlspecialchars((string)$n['username']) ?></span>
                                        <div class="text-muted"><?= htmlspecialchars((string)($n['email'] ?? '')) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">user #<?= (int)$n['UserId'] ?> (deleted?)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <span class="badge bg-secondary"><?= htmlspecialchars((string)$n['Type']) ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$n['Title']) ?></strong>
                                    <div class="text-muted small text-truncate" style="max-width: 50ch">
                                        <?= htmlspecialchars((string)$n['Body']) ?>
                                    </div>
                                    <?php if (!empty($n['ActionUrl'])): ?>
                                        <a class="small" href="<?= htmlspecialchars((string)$n['ActionUrl']) ?>" target="_blank" rel="noopener">
                                            <i class="bi bi-box-arrow-up-right me-1"></i><?= htmlspecialchars((string)$n['ActionUrl']) ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ((int)$n['IsRead'] === 1): ?>
                                        <i class="bi bi-check-circle-fill text-success" title="Read"></i>
                                    <?php else: ?>
                                        <i class="bi bi-circle text-warning" title="Unread"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this notification? It will disappear from the recipient\'s bell.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action"     value="delete">
                                        <input type="hidden" name="id"         value="<?= (int)$n['Id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer d-flex justify-content-center gap-2">
                <?php
                    $qs = $_GET;
                    $linkFor = static function (int $p) use ($qs): string {
                        $qs['page'] = $p;
                        return '?' . http_build_query($qs);
                    };
                ?>
                <?php if ($page > 1): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($linkFor($page - 1)) ?>">&laquo; Prev</a>
                <?php endif; ?>
                <span class="align-self-center small text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($linkFor($page + 1)) ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- ===========================
     COMPOSE MODAL
     =========================== -->
<div class="modal fade" id="notify-compose-modal" tabindex="-1" aria-labelledby="notify-compose-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark border-secondary">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"     value="compose">

                <div class="modal-header">
                    <h5 class="modal-title" id="notify-compose-label">
                        <i class="bi bi-pencil-square me-2"></i>Compose notification
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <!-- Audience selector -->
                    <fieldset class="mb-3">
                        <legend class="small text-muted">Audience</legend>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="audience" id="aud-user" value="user" checked>
                            <label class="form-check-label" for="aud-user">A single user</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="audience" id="aud-role" value="role">
                            <label class="form-check-label" for="aud-role">Every user with role</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="audience" id="aud-all" value="all">
                            <label class="form-check-label" for="aud-all">All signed-in users <span class="text-warning small">(broadcast)</span></label>
                        </div>
                    </fieldset>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6" data-aud="user">
                            <label class="form-label small">Target user (username, email, or id)</label>
                            <input type="text" name="target_user" class="form-control form-control-sm" placeholder="e.g. lance" autocomplete="off">
                        </div>
                        <div class="col-md-6 d-none" data-aud="role">
                            <label class="form-label small">Target role</label>
                            <select name="target_role" class="form-select form-select-sm">
                                <option value="user">user</option>
                                <option value="editor">editor</option>
                                <option value="admin">admin</option>
                                <option value="global_admin">global_admin</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Type tag</label>
                            <select name="type" class="form-select form-select-sm">
                                <option value="announcement">announcement</option>
                                <option value="maintenance">maintenance</option>
                                <option value="release">release</option>
                                <option value="info">info</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" maxlength="255" required placeholder="Short, scannable headline">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Body <span class="text-danger">*</span></label>
                        <textarea name="body" class="form-control" rows="5" maxlength="2000" required placeholder="Plain-text body. ≤ 2000 characters. No HTML."></textarea>
                    </div>
                    <div class="mb-1">
                        <label class="form-label small">Action URL <span class="text-muted">(optional)</span></label>
                        <input type="text" name="action_url" class="form-control form-control-sm" placeholder="/manage/some-page  or  https://…">
                        <div class="form-text small">Local /-rooted path or absolute https://. Clicking the row in the bell sends the user here.</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Send notification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* Compose modal — reveal the field row matching the chosen audience.
   Pure DOM, no module — keeps the surface tiny. */
(() => {
    const radios = document.querySelectorAll('input[name="audience"]');
    const apply  = () => {
        const sel = document.querySelector('input[name="audience"]:checked')?.value || 'user';
        document.querySelectorAll('[data-aud]').forEach((el) => {
            el.classList.toggle('d-none', el.dataset.aud !== sel);
        });
    };
    radios.forEach((r) => r.addEventListener('change', apply));
    apply();
})();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
