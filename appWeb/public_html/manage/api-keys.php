<?php

declare(strict_types=1);

/**
 * iHymns — Admin: API keys (#1064)
 *
 * Mint, list and revoke the machine-to-machine API keys external services
 * (e.g. MeedyaDL #907) use to call the public API without a session — today
 * the lyrics-ingest endpoint (scope `lyrics:ingest`).
 *
 * Security model (see includes/api_keys.php + tblApiKeys):
 *   - The raw key is generated server-side, shown ONCE in the create response,
 *     and never stored — only its SHA-256 hash + a short non-secret prefix.
 *   - Keys are revocable (Active toggle) and deletable; both are audited.
 *
 * Gating: global_admin only (the most sensitive admin surface). All DB access
 * is mysqli prepared statements; mutations are CSRF-protected + activity-logged.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'api_keys.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
/* manage_api_keys (global admin) = full control + approve requests; request_api_keys
   (admin) = self-serve: submit a request + track your own. Phase D. */
$canManage  = $currentUser ? userHasEntitlement('manage_api_keys', $currentUser['role'] ?? null) : false;
$canRequest = $currentUser ? userHasEntitlement('request_api_keys', $currentUser['role'] ?? null) : false;
if (!$currentUser || (!$canManage && !$canRequest)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — API key access required</h1><p>API key management or request access is required.</p></body></html>';
    exit;
}
$activePage = 'api-keys';

/* Self-serve requests only offer the safe read scope; sensitive scopes
   (lyrics:ingest, content:gated) stay approval-only via direct minting.
   The scope constant now lives in includes/api_keys.php (API_KEY_SELF_SERVE_SCOPE)
   so this page and the admin_api_key_* / api_key_request API actions
   (API-coverage Batch 6a) can never drift on it independently. */

/* Is the self-serve requests table migrated on this env? (STRICT-safe gate,
   shared with the API actions via apiKeyRequestsTableReady().) */
$apiKeyRequestsTable = apiKeyRequestsTableReady(getDbMysqli());

$db   = getDbMysqli();
$csrf = csrfToken();

/* Known scopes the UI offers; free-form is still allowed but these are the
   ones the platform currently authorises:
     - lyrics:ingest  (write) — the MeedyaDL-style lyrics ingest endpoint (#1064)
     - catalogue:read (read)  — read the public catalogue at the key's per-key
                                rate limit instead of the anonymous IP limit
                                (API platform Phase A; does NOT unlock gated content).
     - content:gated  (read)  — DELIBERATE, separate grant: read gated/copyrighted
                                content unstripped. DORMANT (needs content_gating_enabled
                                + this scope). Issuing it is a per-partner LICENSING
                                decision (redistribution to an external system; broader
                                than the #1324 in-service basis). Grant sparingly. */
$KNOWN_SCOPES = ['lyrics:ingest', 'catalogue:read', 'content:gated'];

$logKey = static function (string $action, string $id, array $details): void {
    if (function_exists('logActivity')) {
        try { logActivity('apikey.' . $action, 'api_key', $id, $details); }
        catch (\Throwable $_e) { /* audit is best-effort */ }
    }
};

/* ----------------------------------------------------------------------
 * POST dispatcher — JSON in / JSON out.
 * ---------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    /* Robust same-origin check (not the stale-prone baked token) so a long-open
       API-keys page never sporadically 403s a create/toggle/delete; the client
       sends X-Requested-With. A valid token still passes. */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF check failed — please retry.']);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    /* Capability gate per action (Phase D): management actions need manage_api_keys;
       the self-serve 'request' needs request_api_keys. */
    $manageOnly = ['create', 'toggle', 'delete', 'set_limits', 'approve_request', 'reject_request'];
    if (in_array($action, $manageOnly, true) && !$canManage) {
        http_response_code(403); echo json_encode(['error' => 'Global-admin (manage_api_keys) required.']); exit;
    }
    if ($action === 'request' && !$canRequest) {
        http_response_code(403); echo json_encode(['error' => 'request_api_keys required.']); exit;
    }

    try {
        switch ($action) {
            /* Every case below delegates to includes/api_keys.php's admin CRUD
               core (rule #22) — see that file's "Admin CRUD core" section
               doc-block for the full show-once secret discipline this page
               and api.php's admin_api_key_* / api_key_request actions both
               honour. This page's own response shape (`success`, matching its
               front-end JS which reads `res.j.success`) is unchanged from
               before extraction — only the internals moved. */
            case 'create': {
                $label = trim((string)($_POST['label'] ?? ''));
                $scope = trim((string)($_POST['scope'] ?? 'lyrics:ingest'));
                $createdBy = (int)($currentUser['id'] ?? 0) ?: null;
                $result = apiKeyAdminCreate($db, $label, $scope, $createdBy);
                if (!$result['ok']) {
                    http_response_code($result['status'] ?? 400);
                    $resp = ['error' => $result['error']];
                    if (isset($result['field'])) { $resp['fields'] = [$result['field'] => ($result['field'] === 'label' ? 'Required.' : 'Use space-separated scope tokens.')]; }
                    echo json_encode($resp);
                    exit;
                }

                $logKey('create', (string)$result['id'], ['label' => $result['label'], 'scope' => $result['scope'], 'prefix' => $result['prefix']]);

                /* The raw key is returned ONCE — never retrievable again. */
                echo json_encode([
                    'success' => true,
                    'id'      => $result['id'],
                    'rawKey'  => $result['rawKey'],
                    'label'   => $result['label'],
                    'scope'   => $result['scope'],
                    'prefix'  => $result['prefix'],
                ]);
                exit;
            }

            case 'toggle': {
                $id     = (int)($_POST['id'] ?? 0);
                $active = !empty($_POST['active']) ? 1 : 0;
                $result = apiKeyAdminToggle($db, $id, $active);
                if (!$result['ok']) { http_response_code($result['status']); echo json_encode(['error' => $result['error']]); exit; }
                $logKey($result['active'] ? 'reactivate' : 'revoke', (string)$result['id'], ['active' => $result['active']]);
                echo json_encode(['success' => true, 'id' => $result['id'], 'active' => $result['active']]);
                exit;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                $result = apiKeyAdminDelete($db, $id);
                if (!$result['ok']) { http_response_code($result['status']); echo json_encode(['error' => $result['error']]); exit; }
                $logKey('delete', (string)$result['id'], []);
                echo json_encode(['success' => true, 'id' => $result['id']]);
                exit;
            }

            case 'set_limits': {
                $id = (int)($_POST['id'] ?? 0);
                /* Empty string → NULL (no limit). Otherwise a non-negative int.
                   mysqli binds a NULL PHP var as SQL NULL even on an 'i' slot. */
                $perMin = (($_POST['per_min'] ?? '') === '') ? null : max(0, (int)$_POST['per_min']);
                $perDay = (($_POST['per_day'] ?? '') === '') ? null : max(0, (int)$_POST['per_day']);
                $result = apiKeyAdminSetLimits($db, $id, $perMin, $perDay);
                if (!$result['ok']) { http_response_code($result['status']); echo json_encode(['error' => $result['error']]); exit; }
                $logKey('set_limits', (string)$result['id'], ['perMin' => $result['perMin'], 'perDay' => $result['perDay']]);
                echo json_encode(['success' => true, 'id' => $result['id'], 'perMin' => $result['perMin'], 'perDay' => $result['perDay']]);
                exit;
            }

            /* ---- Self-serve key requests (Phase D) ---- */
            case 'request': {
                $label = trim((string)($_POST['label'] ?? ''));
                $just  = trim((string)($_POST['justification'] ?? ''));
                $reqUserId = (int)($currentUser['id'] ?? 0);
                $result = apiKeyAdminRequestCreate($db, $reqUserId, $label, $just);
                if (!$result['ok']) { http_response_code($result['status']); echo json_encode(['error' => $result['error']]); exit; }
                $logKey('request', (string)$result['id'], ['label' => $result['label'], 'scope' => $result['scope']]);
                echo json_encode(['success' => true, 'id' => $result['id']]);
                exit;
            }

            case 'approve_request': {
                $reqId = (int)($_POST['id'] ?? 0);
                $approver = (int)($currentUser['id'] ?? 0) ?: null;
                $result = apiKeyAdminRequestApprove($db, $reqId, $approver);
                if (!$result['ok']) { http_response_code($result['status']); echo json_encode(['error' => $result['error']]); exit; }
                $logKey('approve_request', (string)$result['id'], ['keyId' => $result['keyId'], 'label' => $result['label']]);
                /* Raw key shown ONCE to the approver to relay to the requester. */
                echo json_encode(['success' => true, 'id' => $result['id'], 'keyId' => $result['keyId'], 'rawKey' => $result['rawKey'], 'label' => $result['label']]);
                exit;
            }

            case 'reject_request': {
                $reqId = (int)($_POST['id'] ?? 0);
                $note  = trim((string)($_POST['note'] ?? ''));
                $reviewer = (int)($currentUser['id'] ?? 0) ?: null;
                $result = apiKeyAdminRequestReject($db, $reqId, $reviewer, $note);
                if (!$result['ok']) { http_response_code($result['status']); echo json_encode(['error' => $result['error']]); exit; }
                $logKey('reject_request', (string)$result['id'], ['note' => $result['note']]);
                echo json_encode(['success' => true, 'id' => $result['id']]);
                exit;
            }

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action.']);
                exit;
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * GET — load the key list.
 * ---------------------------------------------------------------------- */
$keys = [];
if ($canManage) {   /* requesters never see the full key list — only their own requests */
    $res = $db->query(
        'SELECT k.Id, k.Label, k.KeyPrefix, k.Scope, k.Active, k.LastUsedAt, k.LastUsedIp, k.CreatedAt,
                k.RateLimitPerMin, k.RateLimitPerDay,
                u.Username AS CreatedByName
           FROM tblApiKeys k
           LEFT JOIN tblUsers u ON u.Id = k.CreatedBy
          ORDER BY k.Active DESC, k.CreatedAt DESC'
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) { $keys[] = $row; }
    }
}

/* Today's request count per key from the #1066 usage counters (Theme B). The
   table is existence-gated (it may not be migrated on every env — STRICT mode
   throws on a missing table) and wrapped, so an un-migrated install just shows
   no usage rather than white-screening. WindowStart is computed SQL-side. */
$usageToday = [];   // [ApiKeyId => requests today]
try {
    $hasUsage = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblApiKeyUsage' LIMIT 1"
    );
    if ($hasUsage && $hasUsage->fetch_row() !== null) {
        $ures = $db->query(
            "SELECT ApiKeyId, SUM(RequestCount) AS Today
               FROM tblApiKeyUsage
              WHERE WindowType = 'day'
                AND WindowStart = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d 00:00:00')
              GROUP BY ApiKeyId"
        );
        if ($ures) {
            while ($u = $ures->fetch_assoc()) { $usageToday[(int)$u['ApiKeyId']] = (int)$u['Today']; }
        }
    }
} catch (\Throwable $_e) { /* un-migrated / DB blip — no usage shown */ }

/* Self-serve requests (Phase D): the pending queue for reviewers ($canManage)
   and the current user's own requests ($canRequest). Existence-gated. */
$pendingRequests = [];
$myRequests      = [];
if ($apiKeyRequestsTable) {
    try {
        if ($canManage) {
            $pr = $db->query(
                "SELECT r.Id, r.Label, r.Scope, r.Justification, r.CreatedAt, u.Username AS Requester
                   FROM tblApiKeyRequests r
                   LEFT JOIN tblUsers u ON u.Id = r.RequesterId
                  WHERE r.Status = 'pending'
                  ORDER BY r.CreatedAt ASC"
            );
            if ($pr) { while ($row = $pr->fetch_assoc()) { $pendingRequests[] = $row; } }
        }
        if ($canRequest) {
            $myId = (int)($currentUser['id'] ?? 0);
            $mr = $db->prepare(
                "SELECT Id, Label, Scope, Status, ReviewNote, CreatedAt, ReviewedAt
                   FROM tblApiKeyRequests WHERE RequesterId = ? ORDER BY CreatedAt DESC LIMIT 50"
            );
            $mr->bind_param('i', $myId);
            $mr->execute();
            $mrRes = $mr->get_result();
            while ($row = $mrRes->fetch_assoc()) { $myRequests[] = $row; }
            $mr->close();
        }
    } catch (\Throwable $_e) { /* degrade to empty */ }
}

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Keys — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-key me-2"></i>API Keys</h1>
            <p class="text-secondary small mb-0">
                Keys that let trusted outside services (for example MeedyaDL)
                connect to iHymns automatically, without anyone signing in —
                today, to send in song lyrics. Each key is secret and shown in
                full only <strong>once</strong>, when it's created, so copy it
                somewhere safe straight away.
            </p>
        </div>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#keyModal">
            <i class="bi bi-plus-lg me-1"></i>Mint key
        </button>
        <?php elseif ($canRequest): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal">
            <i class="bi bi-send me-1"></i>Request a key
        </button>
        <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
    <h2 class="h5 mb-2">Issued keys</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle admin-table-responsive cp-sortable">
            <thead>
                <tr>
                    <th data-col-priority="primary" data-sort-key="label" data-sort-type="text">Label</th>
                    <th data-col-priority="secondary" data-sort-key="prefix" data-sort-type="text">Prefix</th>
                    <th data-col-priority="secondary" data-sort-key="scope" data-sort-type="text">Scope</th>
                    <th data-col-priority="primary" data-sort-key="status" data-sort-type="text">Status</th>
                    <th data-col-priority="secondary" data-sort-key="usage" data-sort-type="number">Usage today</th>
                    <th data-col-priority="secondary" data-sort-key="limits" data-sort-type="number">Limits (min&nbsp;&middot;&nbsp;day)</th>
                    <th data-col-priority="tertiary" data-sort-key="lastused" data-sort-type="date">Last used</th>
                    <th data-col-priority="tertiary" data-sort-key="created" data-sort-type="date">Created</th>
                    <th data-col-priority="primary" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($keys)): ?>
                <tr><td colspan="9" class="text-center text-secondary py-4">No API keys yet. Mint one for your external service.</td></tr>
            <?php else: foreach ($keys as $k): ?>
                <tr data-id="<?= (int)$k['Id'] ?>">
                    <td data-col-priority="primary"><?= htmlspecialchars((string)$k['Label'], ENT_QUOTES) ?></td>
                    <td data-col-priority="secondary"><code><?= htmlspecialchars((string)$k['KeyPrefix'], ENT_QUOTES) ?>…</code></td>
                    <td data-col-priority="secondary"><code class="small"><?= htmlspecialchars((string)$k['Scope'], ENT_QUOTES) ?></code></td>
                    <td data-col-priority="primary" data-sort-value="<?= (int)$k['Active'] === 1 ? 'active' : 'revoked' ?>">
                        <?php if ((int)$k['Active'] === 1): ?>
                            <span class="badge bg-success">active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">revoked</span>
                        <?php endif; ?>
                    </td>
                    <td data-col-priority="secondary" data-sort-value="<?= (int)($usageToday[(int)$k['Id']] ?? 0) ?>">
                        <?php $ut = $usageToday[(int)$k['Id']] ?? null; ?>
                        <?= $ut === null
                            ? '<span class="text-secondary small">&mdash;</span>'
                            : '<span class="badge bg-light text-dark" title="Requests so far today (UTC)">' . number_format($ut) . '</span>' ?>
                    </td>
                    <td data-col-priority="secondary" class="small font-monospace" data-sort-value="<?= $k['RateLimitPerMin'] === null ? -1 : (int)$k['RateLimitPerMin'] ?>">
                        <?php
                            $pm = $k['RateLimitPerMin']; $pd = $k['RateLimitPerDay'];
                            echo ($pm === null ? '<span class="text-secondary" title="no limit">&infin;</span>' : (int)$pm)
                               . '&nbsp;&middot;&nbsp;'
                               . ($pd === null ? '<span class="text-secondary" title="no limit">&infin;</span>' : (int)$pd);
                        ?>
                    </td>
                    <td data-col-priority="tertiary" data-sort-value="<?= htmlspecialchars((string)($k['LastUsedAt'] ?? ''), ENT_QUOTES) ?>"><?= $k['LastUsedAt'] ? htmlspecialchars((string)$k['LastUsedAt'], ENT_QUOTES) : '<span class="text-secondary">never</span>' ?></td>
                    <td data-col-priority="tertiary"><?= htmlspecialchars((string)$k['CreatedAt'], ENT_QUOTES) ?></td>
                    <td data-col-priority="primary" class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-edit-limits
                                data-per-min="<?= $k['RateLimitPerMin'] === null ? '' : (int)$k['RateLimitPerMin'] ?>"
                                data-per-day="<?= $k['RateLimitPerDay'] === null ? '' : (int)$k['RateLimitPerDay'] ?>"
                                data-label="<?= htmlspecialchars((string)$k['Label'], ENT_QUOTES) ?>">Limits</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle-key data-active="<?= (int)$k['Active'] ?>">
                            <?= (int)$k['Active'] === 1 ? 'Revoke' : 'Reactivate' ?>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-delete-key>Delete</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; /* $canManage issued-keys table */ ?>

    <?php if ($canManage && !empty($pendingRequests)): ?>
    <section class="mt-4">
        <h2 class="h5 mb-2"><i class="bi bi-inbox me-2"></i>Pending key requests <span class="badge bg-warning text-dark"><?= count($pendingRequests) ?></span></h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle cp-sortable admin-table-responsive">
                <thead><tr>
                    <th data-sort-key="requester" data-sort-type="text">Requester</th>
                    <th data-sort-key="label" data-sort-type="text">Label</th>
                    <th data-sort-key="scope" data-sort-type="text">Scope</th>
                    <th data-sort-key="justification" data-sort-type="text">Justification</th>
                    <th data-sort-key="requested" data-sort-type="date">Requested</th>
                    <th class="text-end">Review</th>
                </tr></thead>
                <tbody>
                <?php foreach ($pendingRequests as $rq): ?>
                    <tr data-req-id="<?= (int)$rq['Id'] ?>">
                        <td><?= htmlspecialchars((string)($rq['Requester'] ?? '—'), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars((string)$rq['Label'], ENT_QUOTES) ?></td>
                        <td><code class="small"><?= htmlspecialchars((string)$rq['Scope'], ENT_QUOTES) ?></code></td>
                        <td class="small"><?= $rq['Justification'] !== '' ? htmlspecialchars((string)$rq['Justification'], ENT_QUOTES) : '<span class="text-secondary">—</span>' ?></td>
                        <td class="small text-secondary"><?= htmlspecialchars((string)$rq['CreatedAt'], ENT_QUOTES) ?></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-success" data-approve-req>Approve &amp; mint</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-reject-req>Reject</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-secondary">Approving mints the key with the requested scope and shows the raw value <strong>once</strong> — copy it and pass it to the requester securely.</p>
    </section>
    <?php elseif ($canManage && $apiKeyRequestsTable): ?>
    <p class="text-secondary small mt-4"><i class="bi bi-inbox me-1"></i>No pending key requests.</p>
    <?php endif; ?>

    <?php if ($canRequest): ?>
    <section class="mt-4">
        <h2 class="h5 mb-2"><i class="bi bi-send me-2"></i>My key requests</h2>
        <?php if (!$apiKeyRequestsTable): ?>
            <p class="text-secondary small">Self-serve requests aren't available yet — a global admin needs to run the migration.</p>
        <?php elseif (empty($myRequests)): ?>
            <p class="text-secondary small">You haven't requested any keys. Use <em>Request a key</em> above.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle cp-sortable admin-table-responsive">
                    <thead><tr>
                        <th data-sort-key="label" data-sort-type="text">Label</th>
                        <th data-sort-key="scope" data-sort-type="text">Scope</th>
                        <th data-sort-key="status" data-sort-type="text">Status</th>
                        <th data-sort-key="note" data-sort-type="text">Note</th>
                        <th data-sort-key="requested" data-sort-type="date">Requested</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($myRequests as $rq):
                        $st    = (string)$rq['Status'];
                        $badge = $st === 'approved' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
                    ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$rq['Label'], ENT_QUOTES) ?></td>
                            <td><code class="small"><?= htmlspecialchars((string)$rq['Scope'], ENT_QUOTES) ?></code></td>
                            <td data-sort-value="<?= htmlspecialchars($st, ENT_QUOTES) ?>"><span class="badge <?= $badge ?>"><?= htmlspecialchars($st, ENT_QUOTES) ?></span></td>
                            <td class="small"><?= htmlspecialchars((string)($rq['ReviewNote'] ?? ''), ENT_QUOTES) ?></td>
                            <td class="small text-secondary"><?= htmlspecialchars((string)$rq['CreatedAt'], ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-secondary">When a request is <em>approved</em>, the global admin sends you the key out-of-band (it's shown to them once).</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>

<!-- Mint-key modal -->
<div class="modal fade" id="keyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="keyForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-key me-2"></i>Mint API key</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="keyResult" class="d-none">
            <div class="alert alert-warning">
              <i class="bi bi-exclamation-triangle me-1"></i>
              <strong>Copy this key now — it is shown only once</strong> and cannot be retrieved later.
            </div>
            <div class="input-group mb-2">
              <input type="text" class="form-control font-monospace" id="keyResultValue" readonly>
              <button type="button" class="btn btn-outline-secondary" id="keyResultCopy" aria-label="Copy API key to clipboard"><i class="bi bi-clipboard" aria-hidden="true"></i></button>
            </div>
            <p class="small text-secondary">Send it as <code>Authorization: Bearer &lt;key&gt;</code> (or <code>X-API-Key: &lt;key&gt;</code>) to <code>/api?action=lyrics_ingest</code>.</p>
          </div>
          <div id="keyFields">
            <div class="mb-3">
              <label class="form-label" for="keyLabel">Label</label>
              <input type="text" class="form-control" id="keyLabel" maxlength="120" placeholder="e.g. MeedyaDL" required>
              <div class="form-text">A human name so you can recognise + revoke this client later.</div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="keyScope">Scope</label>
              <input type="text" class="form-control font-monospace" id="keyScope" maxlength="255" value="lyrics:ingest" list="scopeOptions">
              <datalist id="scopeOptions">
                <option value="lyrics:ingest"></option>
                <option value="catalogue:read"></option>
                <option value="content:gated"></option>
              </datalist>
              <div class="form-text">Space-separated scope tokens: <code>lyrics:ingest</code> (write), <code>catalogue:read</code> (read the public catalogue at this key's rate limit; does not unlock gated content), <code>content:gated</code> (read gated/copyrighted content unstripped &mdash; a deliberate per-partner licensing grant; dormant until content gating is on).</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="keyCloseBtn">Cancel</button>
          <button type="submit" class="btn btn-primary" id="keySubmitBtn">Mint key</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit rate-limits modal -->
<div class="modal fade" id="limitsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="limitsForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-speedometer2 me-2"></i>Rate limits &mdash; <span id="limitsLabel"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-secondary">Per-key request ceilings (#1066). Leave a field <strong>blank</strong> for no limit. The key gets a <code>429</code> + <code>Retry-After</code> once a window is exceeded.</p>
          <input type="hidden" id="limitsId">
          <div class="row g-3">
            <div class="col">
              <label class="form-label" for="limitsPerMin">Per minute</label>
              <input type="number" min="0" step="1" class="form-control" id="limitsPerMin" placeholder="&infin;">
            </div>
            <div class="col">
              <label class="form-label" for="limitsPerDay">Per day (UTC)</label>
              <input type="number" min="0" step="1" class="form-control" id="limitsPerDay" placeholder="&infin;">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="limitsSubmitBtn">Save limits</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Request-key modal (self-serve, Phase D) -->
<div class="modal fade" id="requestModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="requestForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-send me-2"></i>Request an API key</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-secondary">A global admin reviews requests. Self-serve keys are scoped to <code>catalogue:read</code> (read the public catalogue at the key's rate limit); sensitive scopes are issued directly by an admin.</p>
          <div class="mb-3">
            <label class="form-label" for="reqLabel">Label</label>
            <input type="text" class="form-control" id="reqLabel" maxlength="120" placeholder="e.g. My church website" required>
            <div class="form-text">A name so the key can be recognised + revoked later.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="reqJustification">Justification <span class="text-secondary">(optional)</span></label>
            <textarea class="form-control" id="reqJustification" maxlength="1000" rows="3" placeholder="What you'll use it for"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="reqSubmitBtn">Submit request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    function post(data) {
        var body = new URLSearchParams(data);
        body.append('csrf_token', CSRF);
        return fetch('/manage/api-keys', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); });
    }
    function toast(msg, ok) { if (window.showToast) { window.showToast(msg, ok ? 'success' : 'error'); } else { alert(msg); } }

    var form = document.getElementById('keyForm');
    var resultBox = document.getElementById('keyResult');
    var fieldsBox = document.getElementById('keyFields');
    var submitBtn = document.getElementById('keySubmitBtn');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var label = document.getElementById('keyLabel').value.trim();
        var scope = document.getElementById('keyScope').value.trim() || 'lyrics:ingest';
        if (!label) { toast('Label is required.', false); return; }
        submitBtn.disabled = true;
        post({ action: 'create', label: label, scope: scope }).then(function (res) {
            submitBtn.disabled = false;
            if (!res.ok || !res.j.success) { toast((res.j && res.j.error) || 'Failed to mint key.', false); return; }
            document.getElementById('keyResultValue').value = res.j.rawKey;
            resultBox.classList.remove('d-none');
            fieldsBox.classList.add('d-none');
            submitBtn.classList.add('d-none');
            document.getElementById('keyCloseBtn').textContent = 'Done';
        }).catch(function () { submitBtn.disabled = false; toast('Network error.', false); });
    });
    document.getElementById('keyResultCopy').addEventListener('click', function () {
        var v = document.getElementById('keyResultValue');
        v.select();
        if (navigator.clipboard) { navigator.clipboard.writeText(v.value); }
        else { document.execCommand('copy'); }
        toast('Copied to clipboard.', true);
    });
    document.getElementById('keyModal').addEventListener('hidden.bs.modal', function () {
        /* reset for next mint */
        resultBox.classList.add('d-none');
        fieldsBox.classList.remove('d-none');
        submitBtn.classList.remove('d-none');
        submitBtn.disabled = false;
        document.getElementById('keyCloseBtn').textContent = 'Cancel';
        document.getElementById('keyLabel').value = '';
        document.getElementById('keyScope').value = 'lyrics:ingest';
        if (window._keyMinted) { window.location.reload(); }
    });
    /* reload the list after a successful mint when the modal closes */
    form.addEventListener('submit', function () { window._keyMinted = true; });

    document.querySelectorAll('[data-toggle-key]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            var id = tr.getAttribute('data-id');
            var newActive = btn.getAttribute('data-active') === '1' ? 0 : 1;
            post({ action: 'toggle', id: id, active: newActive }).then(function (res) {
                if (!res.ok || !res.j.success) { toast((res.j && res.j.error) || 'Failed.', false); return; }
                window.location.reload();
            });
        });
    });
    document.querySelectorAll('[data-delete-key]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Delete this API key permanently? Any client using it will stop working immediately.')) { return; }
            var tr = btn.closest('tr');
            var id = tr.getAttribute('data-id');
            post({ action: 'delete', id: id }).then(function (res) {
                if (!res.ok || !res.j.success) { toast((res.j && res.j.error) || 'Failed.', false); return; }
                tr.remove();
                toast('Key deleted.', true);
            });
        });
    });

    /* --- Rate-limit editor (Phase B): per-key RateLimitPerMin/PerDay --- */
    var limitsEl = document.getElementById('limitsModal');
    var limitsModal = (limitsEl && window.bootstrap) ? new bootstrap.Modal(limitsEl) : null;
    document.querySelectorAll('[data-edit-limits]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            document.getElementById('limitsId').value      = tr.getAttribute('data-id');
            document.getElementById('limitsLabel').textContent = btn.getAttribute('data-label') || '';
            document.getElementById('limitsPerMin').value   = btn.getAttribute('data-per-min') || '';
            document.getElementById('limitsPerDay').value   = btn.getAttribute('data-per-day') || '';
            if (limitsModal) { limitsModal.show(); }
        });
    });
    var limitsForm = document.getElementById('limitsForm');
    if (limitsForm) {
        limitsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var sbtn = document.getElementById('limitsSubmitBtn');
            sbtn.disabled = true;
            post({
                action:  'set_limits',
                id:      document.getElementById('limitsId').value,
                per_min: document.getElementById('limitsPerMin').value,
                per_day: document.getElementById('limitsPerDay').value
            }).then(function (res) {
                sbtn.disabled = false;
                if (!res.ok || !res.j.success) { toast((res.j && res.j.error) || 'Failed to save limits.', false); return; }
                toast('Limits saved.', true);
                window.location.reload();
            }).catch(function () { sbtn.disabled = false; toast('Network error.', false); });
        });
    }

    /* --- Self-serve key requests (Phase D) --- */
    var reqForm = document.getElementById('requestForm');
    if (reqForm) {
        reqForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('reqSubmitBtn');
            var label = document.getElementById('reqLabel').value.trim();
            if (!label) { toast('Label is required.', false); return; }
            btn.disabled = true;
            post({ action: 'request', label: label, justification: document.getElementById('reqJustification').value.trim() })
                .then(function (res) {
                    btn.disabled = false;
                    if (!res.ok || !res.j.success) { toast((res.j && res.j.error) || 'Request failed.', false); return; }
                    toast('Request submitted.', true);
                    window.location.reload();
                }).catch(function () { btn.disabled = false; toast('Network error.', false); });
        });
    }
    document.querySelectorAll('[data-approve-req]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.closest('tr').getAttribute('data-req-id');
            if (!confirm('Approve this request and mint the key now?')) { return; }
            btn.disabled = true;
            post({ action: 'approve_request', id: id }).then(function (res) {
                btn.disabled = false;
                if (!res.ok || !res.j.success) { toast((res.j && res.j.error) || 'Approve failed.', false); return; }
                window.prompt('Approved. Copy this key and send it to the requester — it is shown ONLY once:', res.j.rawKey || '');
                window.location.reload();
            }).catch(function () { btn.disabled = false; toast('Network error.', false); });
        });
    });
    document.querySelectorAll('[data-reject-req]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.closest('tr').getAttribute('data-req-id');
            var note = window.prompt('Reject this request? Optional note for the requester:', '');
            if (note === null) { return; }
            btn.disabled = true;
            post({ action: 'reject_request', id: id, note: note }).then(function (res) {
                btn.disabled = false;
                if (!res.ok || !res.j.success) { toast((res.j && res.j.error) || 'Reject failed.', false); return; }
                toast('Request rejected.', true);
                window.location.reload();
            }).catch(function () { btn.disabled = false; toast('Network error.', false); });
        });
    });
})();
</script>

<!-- Sortable table headers (#1786 sweep). -->
<script type="module">
    import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
    bootSortableTables();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
