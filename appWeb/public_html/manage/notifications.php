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
/* Web Push (#311, wired #1671 F6). Required HARD, not function_exists-guarded:
   this page can WRITE the VAPID private key, and secret_crypto.php's
   appSettingValueForStorage() must fail CLOSED rather than silently storing a
   secret in the clear if the engine were ever missing. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'secret_crypto.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'web_push.php';

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

/* #1238 — the Environment / ExpiresAt columns may not exist yet (migrations are
   NOT auto-applied on deploy, per the house DB rules). Probe ONCE and degrade
   gracefully: when absent, the compose/INSERT/list all behave as before (every
   notification system-wide + never-expiring) instead of 500-ing on a missing
   column. The "Notification scope + expiry" migration adds them. */
$notifHasScope = false;
try {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblNotifications'
            AND COLUMN_NAME = 'Environment' LIMIT 1"
    );
    $notifHasScope = ($r && $r->num_rows > 0);
    if ($r) { $r->close(); }
} catch (\Throwable $_e) {
    $notifHasScope = false;
}

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
            /* #1238 — optional environment scope + expiry. */
            $environment = (string)($_POST['environment'] ?? '');      // '' = all environments
            $expiresAtIn = trim((string)($_POST['expires_at'] ?? ''));  // '' = never expires
            $expiresAtSql = '';

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
            /* #1238 — env scope must be one of the known environments (blank = all);
               expiry, when given, must parse and be in the future. */
            if (!in_array($environment, ['', 'alpha', 'beta', 'production'], true)) {
                $errs[] = 'Invalid environment scope.';
            }
            if ($expiresAtIn !== '') {
                $expTs = strtotime($expiresAtIn);
                if ($expTs === false) {
                    $errs[] = 'Expiry date/time is not valid.';
                } elseif ($expTs <= time()) {
                    $errs[] = 'Expiry must be in the future.';
                } else {
                    $expiresAtSql = date('Y-m-d H:i:s', $expTs);
                }
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
                   and stays auditable per-row.

                   #1638 DELIBERATELY LEFT ALONE. The two bulk-import call
                   sites moved to the shared notifyUser() helper in
                   includes/notifications.php, but this one has genuinely
                   different semantics: prepare-ONCE / execute-many is the
                   whole point of a 5000-recipient fan-out, whereas notifyUser()
                   prepares per call, and this loop counts per-row successes
                   with a suppressed execute() so one bad recipient does not
                   abort the broadcast. Routing it through the helper would
                   trade a documented performance property for tidiness.
                   Single-recipient callers should use notifyUser(); a future
                   notifyUsers(array $ids, ...) batch helper is the right home
                   for this loop if a second fan-out ever appears. */
                /* #1238 — include Environment + ExpiresAt only when the columns
                   exist; otherwise fall back to the original 5-column INSERT so an
                   un-migrated install still broadcasts (system-wide, never-expiring). */
                if ($notifHasScope) {
                    $stmt = $db->prepare(
                        'INSERT INTO tblNotifications (UserId, Type, Title, Body, ActionUrl, Environment, ExpiresAt)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO tblNotifications (UserId, Type, Title, Body, ActionUrl)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                }
                /* tblNotifications.ActionUrl is NOT NULL DEFAULT '' —
                   binding NULL on a blank optional field threw
                   `Column 'ActionUrl' cannot be null` and the request
                   500'd (blank white screen the user reported). The
                   schema accepts '' to mean "no deep link"; consumers
                   already treat empty strings as no-link, so swap
                   the NULL fallback for ''. */
                $actionUrlForBind = $actionUrl !== '' ? $actionUrl : '';
                /* NULL binds: blank env => all environments; blank expiry => never. */
                $envForBind     = $environment !== '' ? $environment : null;
                $expiresForBind = $expiresAtSql !== '' ? $expiresAtSql : null;
                $count = 0;
                foreach ($recipients as $uid) {
                    if ($notifHasScope) {
                        $stmt->bind_param('issssss', $uid, $type, $title, $body, $actionUrlForBind, $envForBind, $expiresForBind);
                    } else {
                        $stmt->bind_param('issss', $uid, $type, $title, $body, $actionUrlForBind);
                    }
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

        /* ==============================================================
         * WEB PUSH (#311, wired #1671 F6)
         *
         * Lives on THIS page rather than a new one because it is the same
         * job as the in-app broadcast above — "tell users something" —
         * behind the same `manage_notifications` gate, which was verified
         * to be a REAL, labelled, nav-linked entitlement before anything
         * was built on it (Batch 6 found ten decorative keys, so it was
         * checked rather than assumed).
         * ============================================================== */

        if ($action === 'push_generate_keys') {
            /* A SECOND keypair invalidates every existing subscription: a push
               service binds a subscription to the application-server key that
               created it. So generating over an existing key requires an
               explicit confirm rather than being a one-click foot-gun. */
            $existing = (string)(getAppSetting('webpush_vapid_public', '') ?? '');
            if ($existing !== '' && ($_POST['confirm_replace'] ?? '') !== '1') {
                $flashError = 'A VAPID key already exists. Replacing it will silently stop '
                    . 'notifications for every device already subscribed — tick the confirm box.';
            } else {
                $subject = trim((string)($_POST['vapid_subject'] ?? ''));
                if (!webPushSubjectValid($subject)) {
                    $flashError = 'Contact must be a mailto: address or an https:// URL (RFC 8292 §2).';
                } else {
                    $keys = webPushGenerateVapidKeys();
                    if ($keys === null) {
                        $flashError = 'This server\'s OpenSSL could not generate a P-256 key.';
                    } else {
                        try {
                            /* The PRIVATE half goes through the shared writer, which
                               encrypts it at rest (it is registered in
                               secretSettingKeys()) and THROWS rather than storing it
                               in the clear if the master key is missing here. The
                               PUBLIC half is not a secret — it is handed to every
                               browser as applicationServerKey. */
                            setAppSetting($db, 'webpush_vapid_private', $keys['privateKey']);
                            setAppSetting($db, 'webpush_vapid_public',  $keys['publicKey']);
                            setAppSetting($db, 'webpush_vapid_subject', $subject);
                            /* Same audit surface the in-app broadcast below
                               writes to, via the ONE shared writer (#1638)
                               rather than a fourth copy of the raw INSERT. */
                            logActivity('notification.push.keys_generated', 'notification', '', [
                                'replaced' => $existing !== '',
                            ]);
                            $_SESSION['notifications_flash'] = ['success' =>
                                'VAPID keypair generated. Users can now turn notifications on in Settings.'];
                            header('Location: /manage/notifications');
                            exit;
                        } catch (\Throwable $e) {
                            $flashError = 'Could not store the key: ' . $e->getMessage();
                        }
                    }
                }
            }
        }

        if ($action === 'push_send' || $action === 'push_test') {
            $pushKind  = (string)($_POST['push_kind'] ?? 'announcement');
            $pushTitle = trim((string)($_POST['push_title'] ?? ''));
            $pushBody  = trim((string)($_POST['push_body']  ?? ''));
            $pushUrl   = trim((string)($_POST['push_url']   ?? ''));

            if ($action === 'push_test') {
                /* A test is a real push through the real pipeline — same kind
                   registry, same encryption, same transport — aimed only at the
                   operator's own devices. A "test" that took a different path
                   would prove nothing about the one that matters. */
                $pushKind  = 'test';
                $pushTitle = $pushTitle !== '' ? $pushTitle : 'iHymns test notification';
                $pushBody  = $pushBody !== '' ? $pushBody : 'If you can see this, push notifications are working.';
            }

            if (!webPushConfigured()) {
                $flashError = 'Generate a VAPID keypair first — nothing can be sent without one.';
            } elseif (!webPushKindValid($pushKind)) {
                $flashError = 'Unknown notification kind.';
            } elseif ($pushTitle === '') {
                $flashError = 'A push notification needs a title.';
            } else {
                $payload = webPushBuildPayload($pushKind, $pushTitle, $pushBody, $pushUrl);
                $targets = ($action === 'push_test')
                    ? [(int)($currentUser['Id'] ?? $currentUser['id'] ?? 0)]
                    : null;
                $res = webPushBroadcast($db, $pushKind, $payload, $targets);

                logActivity('notification.push.broadcast', 'notification', '', [
                    'kind'    => $pushKind,
                    'test'    => $action === 'push_test',
                    'sent'    => $res['sent'],
                    'failed'  => $res['failed'],
                    'pruned'  => $res['pruned'],
                    'skipped' => $res['skipped'],
                ]);

                /* Every outcome is reported, including the boring ones. "Sent to
                   0 devices" is the single most useful line an operator can see
                   when nothing arrives, and a bare "Sent." would hide it. */
                $_SESSION['notifications_flash'] = ['success' => sprintf(
                    'Push: %d sent, %d failed, %d dead subscriptions removed, %d skipped (opted out).',
                    $res['sent'], $res['failed'], $res['pruned'], $res['skipped']
                )];
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

/* ----------------------------------------------------------------------
 * WEB PUSH state for the card below (#311 / #1671 F6)
 *
 * Every read is wrapped: tblPushSubscriptions ships in schema.sql but the three
 * docroots share ONE MySQL and migrations are web-run, so "it is in schema.sql"
 * is not the same statement as "it exists here". mysqli runs STRICT, so an
 * absent table THROWS — an ungated count would white-screen the whole admin
 * page rather than showing zero (#1228).
 * ---------------------------------------------------------------------- */
$pushPublicKey  = (string)(getAppSetting('webpush_vapid_public', '') ?? '');
$pushSubject    = (string)(getAppSetting('webpush_vapid_subject', '') ?? '');
$pushConfigured = webPushConfigured();
$pushSubCount   = 0;
try {
    if (webPushSubscriptionsReady($db)) {
        $pushSubCount = (int)$db->query('SELECT COUNT(*) FROM tblPushSubscriptions')->fetch_row()[0];
    }
} catch (\Throwable $_e) {
    $pushSubCount = 0;
}

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — iHymns Admin</title>
    <?php /* #965 — use the shared head bundler. Loads admin-theme-init.php
             so the page obeys the user's theme preference (was light-only),
             and syncs Bootstrap + Bootstrap-Icons versions with the rest
             of the admin area via APP_CONFIG['libraries']['bootstrap']. */ ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
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
         WEB PUSH (#311, wired #1671 F6)

         Lives on THIS page, not a new one: it is the same job as the
         in-app broadcast above, behind the same manage_notifications
         gate. That entitlement was VERIFIED real — labelled in
         manage/entitlements.php, mapped in includes/entitlements.php +
         its JS mirror, nav-linked in admin-links.php and enforced at the
         top of this file — rather than assumed, because Batch 6 found
         ten decorative permission keys in this codebase.
         =========================== -->
    <div class="card bg-body-tertiary border-secondary mb-3">
        <div class="card-body">
            <h2 class="h6 mb-2">
                <i class="fa-solid fa-mobile-screen-button me-2"></i>Web Push
                <?php if ($pushConfigured): ?>
                    <span class="badge bg-success ms-1">Configured</span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-1">Not configured</span>
                <?php endif; ?>
            </h2>
            <p class="text-secondary small">
                Push notifications go to a user's browser or installed app even when
                iHymns is closed. They are encrypted end-to-end (RFC 8291) — the push
                service that relays them cannot read the contents.
                <strong><?= number_format($pushSubCount) ?></strong>
                device<?= $pushSubCount === 1 ? '' : 's' ?> currently subscribed.
            </p>

            <?php if (!$pushConfigured): ?>
                <!-- SETUP. Nothing can be sent without an application-server
                     identity keypair (VAPID, RFC 8292). The private half is
                     stored encrypted at rest (secretSettingKeys(), #1466) and
                     never leaves the server; the public half is handed to every
                     browser as `applicationServerKey` and is not a secret. -->
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="push_generate_keys">
                    <div class="col-md-6">
                        <label class="form-label small" for="vapid-subject">Operator contact <span class="text-danger">*</span></label>
                        <input type="text" id="vapid-subject" name="vapid_subject" class="form-control form-control-sm"
                               placeholder="mailto:admin@example.com" required
                               value="<?= htmlspecialchars($pushSubject) ?>">
                        <div class="form-text small">
                            Required by RFC 8292 §2 so a push service can contact you about
                            delivery problems. <code>mailto:</code> or <code>https://</code>.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-key me-1"></i>Generate VAPID keypair
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="row g-3">
                    <div class="col-lg-7">
                        <!-- BROADCAST. Audience is "everyone opted in to this kind";
                             the kind list is rendered from webPushKinds(), the ONE
                             registry, so adding a kind is one line of PHP and this
                             form needs no edit. -->
                        <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="push_send">
                            <div class="col-sm-5">
                                <label class="form-label small" for="push-kind">Kind</label>
                                <select id="push-kind" name="push_kind" class="form-select form-select-sm">
                                    <?php foreach (webPushKinds() as $kindKey => $kindMeta): ?>
                                        <option value="<?= htmlspecialchars($kindKey) ?>">
                                            <?= htmlspecialchars($kindMeta[0]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-7">
                                <label class="form-label small" for="push-title">Title <span class="text-danger">*</span></label>
                                <input type="text" id="push-title" name="push_title" class="form-control form-control-sm"
                                       maxlength="120" required placeholder="Short — lock screens truncate">
                            </div>
                            <div class="col-12">
                                <label class="form-label small" for="push-body">Body</label>
                                <input type="text" id="push-body" name="push_body" class="form-control form-control-sm"
                                       maxlength="300" placeholder="One or two lines">
                            </div>
                            <div class="col-sm-8">
                                <label class="form-label small" for="push-url">Opens</label>
                                <input type="text" id="push-url" name="push_url" class="form-control form-control-sm"
                                       maxlength="300" placeholder="/songbooks">
                                <div class="form-text small">Site-relative path only. Blank opens the home page.</div>
                            </div>
                            <div class="col-sm-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <i class="bi bi-send me-1"></i>Send push
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="col-lg-5">
                        <!-- TEST. Goes through the REAL pipeline — same registry,
                             same RFC 8291 encryption, same transport — aimed only
                             at this operator's own devices. A "test" that took a
                             different path would prove nothing about the one that
                             matters. -->
                        <form method="post" class="mb-2">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="push_test">
                            <button type="submit" class="btn btn-sm btn-outline-info w-100">
                                <i class="bi bi-bell me-1"></i>Send a test to my devices
                            </button>
                        </form>
                        <p class="text-secondary small mb-2">
                            Contact: <code><?= htmlspecialchars($pushSubject) ?></code><br>
                            Public key: <code class="text-break"><?= htmlspecialchars(substr($pushPublicKey, 0, 24)) ?>…</code>
                        </p>
                        <details>
                            <summary class="small text-warning">Replace the VAPID keypair</summary>
                            <form method="post" class="mt-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="push_generate_keys">
                                <div class="alert alert-warning py-2 small">
                                    Replacing the keypair <strong>silently stops notifications for
                                    every one of the <?= number_format($pushSubCount) ?> devices already
                                    subscribed</strong> — a push service binds a subscription to the key
                                    that created it. Each user would have to turn notifications off and
                                    on again in Settings.
                                </div>
                                <input type="text" name="vapid_subject" class="form-control form-control-sm mb-2"
                                       value="<?= htmlspecialchars($pushSubject) ?>" required>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           name="confirm_replace" id="confirm-replace" required>
                                    <label class="form-check-label small" for="confirm-replace">
                                        I understand this breaks every existing subscription.
                                    </label>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Replace keypair</button>
                            </form>
                        </details>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===========================
         FILTER RAIL
         =========================== -->
    <div class="card bg-body-tertiary border-secondary mb-3">
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
    <div class="card bg-body-tertiary border-secondary">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="small text-secondary">
                <?= number_format($totalRows) ?> notification<?= $totalRows === 1 ? '' : 's' ?>
                — page <?= $page ?> of <?= $totalPages ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-sm table-hover mb-0 cp-sortable admin-table-responsive">
                <thead>
                    <tr>
                        <th data-sort-key="when"      data-sort-type="text">When</th>
                        <th data-sort-key="recipient" data-sort-type="text">Recipient</th>
                        <th data-sort-key="type"      data-sort-type="text">Type</th>
                        <th data-sort-key="title"     data-sort-type="text">Title / Body</th>
                        <th class="text-center" data-sort-key="read" data-sort-type="text">Read</th>
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
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete" aria-label="Delete this notification">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
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
        <div class="modal-content border-secondary">
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
<?php if ($notifHasScope): /* #1238 — only when the columns exist (migration applied) */ ?>
                    <div class="row g-2 mb-1">
                        <div class="col-sm-6">
                            <label class="form-label small">Environment <span class="text-muted">(optional)</span></label>
                            <select name="environment" class="form-select form-select-sm">
                                <option value="">All environments</option>
                                <option value="alpha">Alpha only</option>
                                <option value="beta">Beta only</option>
                                <option value="production">Production only</option>
                            </select>
                            <div class="form-text small">The three environments share one database; scope a notice to just one.</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small">Expires <span class="text-muted">(optional)</span></label>
                            <input type="datetime-local" name="expires_at" class="form-control form-control-sm">
                            <div class="form-text small">Leave blank to never expire.</div>
                        </div>
                    </div>
<?php endif; ?>
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

<!-- Sortable table headers (#1786 sweep — tagged cp-sortable but never
     booted; every header click was a silent no-op until now). -->
<script type="module">
    import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
    bootSortableTables();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
