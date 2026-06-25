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
if (!$currentUser || !userHasEntitlement('manage_api_keys', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_api_keys required</h1><p>API key management is restricted to global administrators.</p></body></html>';
    exit;
}
$activePage = 'api-keys';

$db   = getDbMysqli();
$csrf = csrfToken();

/* Known scopes the UI offers; free-form is still allowed but these are the
   ones the platform currently authorises:
     - lyrics:ingest  (write) — the MeedyaDL-style lyrics ingest endpoint (#1064)
     - catalogue:read (read)  — read the public catalogue at the key's per-key
                                rate limit instead of the anonymous IP limit
                                (API platform Phase A; does NOT unlock gated content). */
$KNOWN_SCOPES = ['lyrics:ingest', 'catalogue:read'];

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

    try {
        switch ($action) {
            case 'create': {
                $label = trim((string)($_POST['label'] ?? ''));
                $scope = trim((string)($_POST['scope'] ?? 'lyrics:ingest'));
                if ($label === '' || strlen($label) > 120) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Label is required (max 120 chars).', 'fields' => ['label' => 'Required.']]);
                    exit;
                }
                if ($scope === '' || strlen($scope) > 255 || !preg_match('/^[a-z0-9:_\- ]+$/i', $scope)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Scope is invalid.', 'fields' => ['scope' => 'Use space-separated scope tokens.']]);
                    exit;
                }

                $gen = apiKeyGenerate();
                $createdBy = (int)($currentUser['id'] ?? 0) ?: null;
                $stmt = $db->prepare(
                    'INSERT INTO tblApiKeys (Label, KeyHash, KeyPrefix, Scope, Active, CreatedBy)
                     VALUES (?, ?, ?, ?, 1, ?)'
                );
                $stmt->bind_param('ssssi', $label, $gen['hash'], $gen['prefix'], $scope, $createdBy);
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();

                $logKey('create', (string)$newId, ['label' => $label, 'scope' => $scope, 'prefix' => $gen['prefix']]);

                /* The raw key is returned ONCE — never retrievable again. */
                echo json_encode([
                    'success' => true,
                    'id'      => $newId,
                    'rawKey'  => $gen['raw'],
                    'label'   => $label,
                    'scope'   => $scope,
                    'prefix'  => $gen['prefix'],
                ]);
                exit;
            }

            case 'toggle': {
                $id     = (int)($_POST['id'] ?? 0);
                $active = !empty($_POST['active']) ? 1 : 0;
                if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id.']); exit; }
                $stmt = $db->prepare('UPDATE tblApiKeys SET Active = ? WHERE Id = ?');
                $stmt->bind_param('ii', $active, $id);
                $stmt->execute();
                $stmt->close();
                $logKey($active ? 'reactivate' : 'revoke', (string)$id, ['active' => $active]);
                echo json_encode(['success' => true, 'id' => $id, 'active' => $active]);
                exit;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id.']); exit; }
                $stmt = $db->prepare('DELETE FROM tblApiKeys WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $logKey('delete', (string)$id, []);
                echo json_encode(['success' => true, 'id' => $id]);
                exit;
            }

            case 'set_limits': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id.']); exit; }
                /* Empty string → NULL (no limit). Otherwise a non-negative int.
                   mysqli binds a NULL PHP var as SQL NULL even on an 'i' slot. */
                $perMin = (($_POST['per_min'] ?? '') === '') ? null : max(0, (int)$_POST['per_min']);
                $perDay = (($_POST['per_day'] ?? '') === '') ? null : max(0, (int)$_POST['per_day']);
                $stmt = $db->prepare('UPDATE tblApiKeys SET RateLimitPerMin = ?, RateLimitPerDay = ? WHERE Id = ?');
                $stmt->bind_param('iii', $perMin, $perDay, $id);
                $stmt->execute();
                $stmt->close();
                $logKey('set_limits', (string)$id, ['perMin' => $perMin, 'perDay' => $perDay]);
                echo json_encode(['success' => true, 'id' => $id, 'perMin' => $perMin, 'perDay' => $perDay]);
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
                Machine-to-machine keys for external services (e.g. MeedyaDL) calling the public API without a session —
                today the lyrics-ingest endpoint (<code>scope&nbsp;lyrics:ingest</code>). Keys are stored hashed; the raw value is shown
                <strong>once</strong> at creation. <code>tblApiKeys</code> (#1064).
            </p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#keyModal">
            <i class="bi bi-plus-lg me-1"></i>Mint key
        </button>
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle admin-table-responsive">
            <thead>
                <tr>
                    <th data-col-priority="primary">Label</th>
                    <th data-col-priority="secondary">Prefix</th>
                    <th data-col-priority="secondary">Scope</th>
                    <th data-col-priority="primary">Status</th>
                    <th data-col-priority="secondary">Usage today</th>
                    <th data-col-priority="secondary">Limits (min&nbsp;&middot;&nbsp;day)</th>
                    <th data-col-priority="tertiary">Last used</th>
                    <th data-col-priority="tertiary">Created</th>
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
                    <td data-col-priority="primary">
                        <?php if ((int)$k['Active'] === 1): ?>
                            <span class="badge bg-success">active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">revoked</span>
                        <?php endif; ?>
                    </td>
                    <td data-col-priority="secondary">
                        <?php $ut = $usageToday[(int)$k['Id']] ?? null; ?>
                        <?= $ut === null
                            ? '<span class="text-secondary small">&mdash;</span>'
                            : '<span class="badge bg-light text-dark" title="Requests so far today (UTC)">' . number_format($ut) . '</span>' ?>
                    </td>
                    <td data-col-priority="secondary" class="small font-monospace">
                        <?php
                            $pm = $k['RateLimitPerMin']; $pd = $k['RateLimitPerDay'];
                            echo ($pm === null ? '<span class="text-secondary" title="no limit">&infin;</span>' : (int)$pm)
                               . '&nbsp;&middot;&nbsp;'
                               . ($pd === null ? '<span class="text-secondary" title="no limit">&infin;</span>' : (int)$pd);
                        ?>
                    </td>
                    <td data-col-priority="tertiary"><?= $k['LastUsedAt'] ? htmlspecialchars((string)$k['LastUsedAt'], ENT_QUOTES) : '<span class="text-secondary">never</span>' ?></td>
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
              <button type="button" class="btn btn-outline-secondary" id="keyResultCopy"><i class="bi bi-clipboard"></i></button>
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
              </datalist>
              <div class="form-text">Space-separated scope tokens: <code>lyrics:ingest</code> (write), <code>catalogue:read</code> (read the public catalogue at this key's rate limit; does not unlock gated content).</div>
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
})();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
