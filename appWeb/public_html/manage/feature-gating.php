<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Feature Gating (#1481 P1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Lets a Global System Admin define + gate ADDITIONAL capabilities (beyond
 * the built-in seven — Lyrics / Copyrighted / Audio / MIDI / PDF / Offline /
 * Needs-CCLI) from the UI, with no dev code. Every enabled row in
 * tblGatingCapabilities is unioned into the code TIER_CAPS registry by
 * tierCapsEffective() (includes/access_tier_validation.php), so a
 * newly-defined capability:
 *   - auto-grows a column + checkbox on the /manage/tiers matrix,
 *   - is surfaced (when EmitInApi=1) on the public access_tiers API emit,
 *   - is immediately enforceable via checkTierAccess()/capValueForTierAction()
 *     the moment any caller (a future endpoint, a future P2 rule) passes its
 *     camelCase or PascalCase name —
 * with ZERO further code changes. Per-tier VALUES still ride the existing
 * tblAccessTiers.Capabilities JSON column (#1352) — this page only edits
 * DEFINITIONS, never a tier's per-capability grant (that stays on
 * /manage/tiers, under manage_access_tiers).
 *
 * This is CAPABILITIES-only (P1). tblGatingRules (P2 — mapping a capability
 * to an enforcement behaviour-kind) was created in the same one-pass
 * migration (rule #20) but its CRUD + enforcement loop are a separate,
 * not-yet-built change; this page only reads tblGatingRules to decide
 * whether a capability is hard-deletable.
 *
 * Gated by the `manage_feature_gating` entitlement (Global-Admin-only —
 * deliberately narrower than manage_access_tiers, since a bad capability
 * DEFINITION affects every tier at once).
 *
 * Pre-migration safe — probes for tblGatingCapabilities before rendering
 * any form (mirrors manage/external-link-types.php).
 *
 * @see includes/access_tier_validation.php  tierCapsEffective() / TIER_CAPS
 * @see appWeb/.sql/migrate-add-gating-registry.php  the migration
 * @see .claude/admin-configurable-gating-strategy.md  the approved design
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'access_tier_validation.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_feature_gating', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_feature_gating required</h1></body></html>';
    exit;
}
$activePage = 'feature-gating';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* ELI5: has the migration run on THIS docroot yet?
   WHY: the 3 docroots share one MySQL but migrations are web-run, never
   auto-applied on deploy (project-rules.md) — a page must never assume
   schema.sql === the live database. Existence-gated exactly like
   manage/external-link-types.php. */
$hasCapsSchema  = false;
$hasRulesSchema = false;
try {
    $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblGatingCapabilities' LIMIT 1");
    $hasCapsSchema = $r && $r->fetch_row() !== null;
    if ($r) { $r->close(); }
    $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblGatingRules' LIMIT 1");
    $hasRulesSchema = $r && $r->fetch_row() !== null;
    if ($r) { $r->close(); }
} catch (\Throwable $e) {
    error_log('[feature-gating] schema probe failed: ' . $e->getMessage());
}

/* ---- POST actions ---- */
if ($hasCapsSchema && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    /* validateCsrfRequest() (#1352 rule #29) rather than validateCsrf()
       alone — this is a plain <form> POST so the baked session token
       (branch a) satisfies it; the same-origin fallback (branch b) is
       free robustness against a long-lived tab whose session token has
       rotated. */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            /* ------------------------------------------------------------
             * create — define a brand-new admin-configured capability.
             * ------------------------------------------------------------ */
            case 'create': {
                $capKey       = trim((string)($_POST['cap_key']      ?? ''));
                $label        = trim((string)($_POST['label']        ?? ''));
                $description  = trim((string)($_POST['description']  ?? ''));
                $defaultValue = !empty($_POST['default_value']) ? 1 : 0;
                $emitInApi    = !empty($_POST['emit_in_api'])    ? 1 : 0;
                $enabled      = !empty($_POST['enabled'])        ? 1 : 0;
                $sortOrder    = (int)($_POST['sort_order']        ?? 0);

                /* Grammar first (GATING_CAP_KEY_PATTERN — the shared source
                   with tierCapsMergeUnion()'s defence-in-depth re-check). */
                if (!preg_match(GATING_CAP_KEY_PATTERN, $capKey)) {
                    $error = "Capability key must be PascalCase, start with 'Can' + an uppercase letter, "
                           . 'contain only letters/digits, and be 5–33 characters (e.g. CanRequestSongs).';
                    break;
                }
                if ($label === '' || mb_strlen($label) > 30) {
                    $error = 'Label is required and must be 30 characters or fewer.';
                    break;
                }
                if (mb_strlen($description) > 255) {
                    $error = 'Description must be 255 characters or fewer.';
                    break;
                }
                if ($sortOrder < 0 || $sortOrder > 100000) {
                    $error = 'Sort order must be between 0 and 100000.';
                    break;
                }

                /* Case-insensitive uniqueness vs the 7 built-in TIER_CAPS
                   keys (shared helper — same test tierCapsMergeUnion() uses
                   to keep code always winning a real collision). */
                if (tierCapKeyCollidesWithCode($capKey)) {
                    $error = "'{$capKey}' collides with a built-in capability key (case-insensitive) — choose a different name.";
                    break;
                }

                /* Case-insensitive uniqueness vs EXISTING admin-defined keys
                   (incl. previously soft-disabled ones — a re-enable should
                   go through 'update', not a second 'create'). */
                $stmt = $db->prepare('SELECT CapKey FROM tblGatingCapabilities');
                $stmt->execute();
                $existingKeys = array_map(
                    static fn(array $row): string => (string)$row['CapKey'],
                    $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
                );
                $stmt->close();
                $collideDb = null;
                foreach ($existingKeys as $ek) {
                    if (strcasecmp($ek, $capKey) === 0) { $collideDb = $ek; break; }
                }
                if ($collideDb !== null) {
                    $error = "A capability named '{$collideDb}' already exists.";
                    break;
                }

                $userId = (int)($currentUser['id'] ?? 0);
                $stmt = $db->prepare(
                    'INSERT INTO tblGatingCapabilities
                         (CapKey, Label, Description, DefaultValue, EmitInApi, Enabled, SortOrder, Source, CreatedBy, UpdatedBy)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $source = 'admin';
                $stmt->bind_param(
                    'sssiiiisii',
                    $capKey, $label, $description, $defaultValue, $emitInApi, $enabled, $sortOrder, $source, $userId, $userId
                );
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();

                logActivity('admin.gating.capability_create', 'gating_capability', (string)$newId, [
                    'cap_key' => $capKey,
                    'after'   => [
                        'label'         => $label,
                        'description'   => $description,
                        'default_value' => $defaultValue,
                        'emit_in_api'   => $emitInApi,
                        'enabled'       => $enabled,
                        'sort_order'    => $sortOrder,
                    ],
                ]);
                $success = "Capability '{$capKey}' created.";
                break;
            }

            /* ------------------------------------------------------------
             * update — edit an existing admin-defined capability. CapKey
             * itself is IMMUTABLE (not accepted here) — too many places
             * (tblAccessTiers.Capabilities JSON keys, a future P2 rule's
             * CapKey FK) would silently orphan on a rename.
             * ------------------------------------------------------------ */
            case 'update': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Capability id missing.'; break; }

                $stmt = $db->prepare('SELECT * FROM tblGatingCapabilities WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $beforeRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$beforeRow) { $error = 'Capability not found.'; break; }
                $capKey = (string)$beforeRow['CapKey'];

                $label        = trim((string)($_POST['label']        ?? ''));
                $description  = trim((string)($_POST['description']  ?? ''));
                $defaultValue = !empty($_POST['default_value']) ? 1 : 0;
                $emitInApi    = !empty($_POST['emit_in_api'])    ? 1 : 0;
                $enabled      = !empty($_POST['enabled'])        ? 1 : 0;
                $sortOrder    = (int)($_POST['sort_order']        ?? 0);

                if ($label === '' || mb_strlen($label) > 30) {
                    $error = 'Label is required and must be 30 characters or fewer.';
                    break;
                }
                if (mb_strlen($description) > 255) {
                    $error = 'Description must be 255 characters or fewer.';
                    break;
                }
                if ($sortOrder < 0 || $sortOrder > 100000) {
                    $error = 'Sort order must be between 0 and 100000.';
                    break;
                }

                $userId = (int)($currentUser['id'] ?? 0);
                $stmt = $db->prepare(
                    'UPDATE tblGatingCapabilities
                        SET Label = ?, Description = ?, DefaultValue = ?, EmitInApi = ?, Enabled = ?, SortOrder = ?, UpdatedBy = ?
                      WHERE Id = ?'
                );
                $stmt->bind_param(
                    'ssiiiiii',
                    $label, $description, $defaultValue, $emitInApi, $enabled, $sortOrder, $userId, $id
                );
                $stmt->execute();
                $stmt->close();

                $afterRow = [
                    'Label'        => $label,
                    'Description'  => $description,
                    'DefaultValue' => $defaultValue,
                    'EmitInApi'    => $emitInApi,
                    'Enabled'      => $enabled,
                    'SortOrder'    => $sortOrder,
                ];
                $changed = [];
                foreach ($afterRow as $k => $v) {
                    if ((string)($beforeRow[$k] ?? '') !== (string)$v) { $changed[] = $k; }
                }
                logActivity('admin.gating.capability_update', 'gating_capability', (string)$id, [
                    'cap_key' => $capKey,
                    'fields'  => $changed,
                    'before'  => array_intersect_key($beforeRow, array_flip($changed)),
                    'after'   => array_intersect_key($afterRow,  array_flip($changed)),
                ]);
                $success = "Capability '{$capKey}' updated.";
                break;
            }

            /* ------------------------------------------------------------
             * delete — HARD delete. Blocked (app-level, friendly message)
             * while any tblGatingRules row references the CapKey — the FK
             * (ON DELETE RESTRICT) backstops this at the DB layer too, so a
             * race is a 500 with a clear cause rather than silent data loss.
             * A curator who wants to retire a capability without deleting
             * should soft-disable it via 'update' (Enabled=0) instead —
             * excludes it from tierCapsEffective() but keeps history/audit.
             * ------------------------------------------------------------ */
            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare('SELECT CapKey FROM tblGatingCapabilities WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $capKey = (string)($row[0] ?? '');
                if ($capKey === '') { $error = 'Capability not found.'; break; }

                $ruleCount = 0;
                if ($hasRulesSchema) {
                    $stmt = $db->prepare('SELECT COUNT(*) FROM tblGatingRules WHERE CapKey = ?');
                    $stmt->bind_param('s', $capKey);
                    $stmt->execute();
                    $cRow = $stmt->get_result()->fetch_row();
                    $stmt->close();
                    $ruleCount = (int)($cRow[0] ?? 0);
                }
                if ($ruleCount > 0) {
                    $error = "Cannot delete '{$capKey}': {$ruleCount} enforcement rule(s) still reference it. "
                           . 'Remove those rules first, or soft-disable the capability instead (uncheck Enabled and Save).';
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblGatingCapabilities WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                logActivity('admin.gating.capability_delete', 'gating_capability', (string)$id, [
                    'cap_key' => $capKey,
                ]);
                $success = "Capability '{$capKey}' deleted.";
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[feature-gating] ' . $e->getMessage());
        logActivityError('admin.gating.save', 'gating_capability', (string)($_POST['id'] ?? ''), $e, [
            'action' => $_POST['action'] ?? null,
        ]);
        $where = $e->getFile() ? (' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') : '';
        $error = $error ?: 'Database error: ' . $e->getMessage() . $where;
    }
}

/* ---- Read ---- */
$dbCaps = [];
if ($hasCapsSchema) {
    try {
        $sql = 'SELECT c.*'
             . ($hasRulesSchema ? ', (SELECT COUNT(*) FROM tblGatingRules r WHERE r.CapKey = c.CapKey) AS RuleCount' : ', 0 AS RuleCount')
             . ' FROM tblGatingCapabilities c ORDER BY c.SortOrder ASC, c.CapKey ASC';
        $res = $db->query($sql);
        while ($res && ($row = $res->fetch_assoc())) {
            $dbCaps[] = $row;
        }
        if ($res) { $res->close(); }
    } catch (\Throwable $e) {
        error_log('[feature-gating] read failed: ' . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feature Gating — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-toggles me-2"></i>Feature Gating</h1>
        <p class="text-secondary small mb-4">
            Define <strong>additional</strong> gateable capabilities — beyond the built-in seven
            (Lyrics / Copyrighted / Audio / MIDI / PDF / Offline / Needs&nbsp;CCLI) — without a code
            deploy. Every enabled capability here appears as a new column + checkbox on
            <a href="/manage/tiers" class="text-info">Access Tiers</a>, where you set its
            per-tier value. This page only defines <em>what</em> a capability is — not which
            tiers grant it.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$hasCapsSchema): ?>
            <div class="card-admin p-4 text-center">
                <p class="mb-2">
                    <i class="bi bi-database-exclamation text-warning fs-1" aria-hidden="true"></i>
                </p>
                <h2 class="h6 mb-2">Schema not yet installed</h2>
                <p class="text-muted small mb-3">
                    <code>tblGatingCapabilities</code> (#1481) isn't present on this docroot yet.
                </p>
                <a href="/manage/setup-database" class="btn btn-amber btn-sm">
                    <i class="bi bi-database-gear me-1"></i>Run /manage/setup-database
                </a>
            </div>
        <?php else: ?>

        <!-- Built-in capabilities (read-only, native-app contract) -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">Built-in capabilities</h2>
            <p class="text-muted small mb-3">
                These seven ship with the app and back the native-app API contract — they cannot
                be edited or deleted here.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 admin-table-responsive cp-sortable"
                       data-default-sort-key="key" data-default-sort-dir="asc">
                    <thead>
                        <tr class="text-muted small">
                            <th data-col-priority="primary" data-sort-key="key" data-sort-type="text">Key</th>
                            <th data-col-priority="primary" data-sort-key="label" data-sort-type="text">Label</th>
                            <th data-col-priority="secondary">Description</th>
                            <th class="text-center" data-col-priority="tertiary">Storage</th>
                            <th class="text-end" data-col-priority="primary">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (TIER_CAPS as $col => [$lbl, $hint, $storage, $_default]): ?>
                            <tr>
                                <td data-col-priority="primary"><code><?= htmlspecialchars($col) ?></code></td>
                                <td data-col-priority="primary"><?= htmlspecialchars($lbl) ?></td>
                                <td data-col-priority="secondary" class="small text-muted"><?= htmlspecialchars($hint) ?></td>
                                <td class="text-center" data-col-priority="tertiary"><code><?= htmlspecialchars($storage) ?></code></td>
                                <td class="text-end" data-col-priority="primary">
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis" title="Ships with the app; backs the native-app API contract">
                                        code / native contract
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Admin-defined capabilities (editable) -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">Admin-defined capabilities</h2>
            <?php if (!$dbCaps): ?>
                <p class="text-muted small mb-0">None yet — add one below.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 admin-table-responsive cp-sortable"
                           data-default-sort-key="key" data-default-sort-dir="asc">
                        <thead>
                            <tr class="text-muted small">
                                <th data-col-priority="primary" data-sort-key="key" data-sort-type="text">Key</th>
                                <th data-col-priority="primary" data-sort-key="label" data-sort-type="text">Label</th>
                                <th data-col-priority="secondary">Description</th>
                                <th class="text-center" data-col-priority="tertiary">Default</th>
                                <th class="text-center" data-col-priority="tertiary">Emit in API</th>
                                <th class="text-center" data-col-priority="secondary" data-sort-key="enabled" data-sort-type="text">Enabled</th>
                                <th class="text-end" data-col-priority="primary">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dbCaps as $c): ?>
                                <?php
                                    $ruleCount = (int)($c['RuleCount'] ?? 0);
                                    $rowId     = (int)$c['Id'];
                                ?>
                                <tr>
                                    <td data-col-priority="primary">
                                        <code><?= htmlspecialchars((string)$c['CapKey']) ?></code>
                                        <span class="badge bg-info-subtle text-info-emphasis ms-1">admin</span>
                                    </td>
                                    <td data-col-priority="primary"><?= htmlspecialchars((string)$c['Label']) ?></td>
                                    <td data-col-priority="secondary" class="small text-muted"><?= htmlspecialchars((string)$c['Description']) ?></td>
                                    <td class="text-center" data-col-priority="tertiary"><?= ((int)$c['DefaultValue'] === 1) ? 'Yes' : 'No' ?></td>
                                    <td class="text-center" data-col-priority="tertiary"><?= ((int)$c['EmitInApi'] === 1) ? 'Yes' : 'No' ?></td>
                                    <td class="text-center" data-col-priority="secondary">
                                        <?= ((int)$c['Enabled'] === 1)
                                            ? '<span class="badge bg-success-subtle text-success-emphasis">enabled</span>'
                                            : '<span class="badge bg-secondary-subtle text-secondary-emphasis">disabled</span>' ?>
                                    </td>
                                    <td class="text-end" data-col-priority="primary">
                                        <button type="button" class="btn btn-sm btn-outline-info"
                                                title="Edit capability"
                                                aria-label="Edit capability <?= htmlspecialchars((string)$c['CapKey'], ENT_QUOTES) ?>"
                                                onclick='openEditCap(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>
                                        <?php if ($ruleCount === 0): ?>
                                            <form method="POST" class="d-inline"
                                                  onsubmit="return confirm('Delete capability <?= htmlspecialchars((string)$c['CapKey'], ENT_QUOTES) ?>? This cannot be undone.')">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $rowId ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        title="Delete capability"
                                                        aria-label="Delete capability <?= htmlspecialchars((string)$c['CapKey'], ENT_QUOTES) ?>">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                                    title="<?= $ruleCount ?> enforcement rule(s) reference this capability — remove those first"
                                                    aria-label="Delete capability <?= htmlspecialchars((string)$c['CapKey'], ENT_QUOTES) ?> (disabled: referenced by rules)">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Create -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a capability</h2>
            <div class="row g-2 mb-2">
                <div class="col-sm-3">
                    <label class="form-label small">Key (machine)</label>
                    <input type="text" name="cap_key" class="form-control form-control-sm" maxlength="40" required
                           placeholder="e.g. CanRequestSongs" pattern="Can[A-Z][A-Za-z0-9]{1,29}"
                           title="PascalCase, starts 'Can' + an uppercase letter, letters/digits only">
                    <div class="form-text small">Cannot be changed after creation.</div>
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Label</label>
                    <input type="text" name="label" class="form-control form-control-sm" maxlength="30" required
                           placeholder="e.g. Requests">
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Sort order</label>
                    <input type="number" name="sort_order" class="form-control form-control-sm" min="0" max="100000" value="0">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm" maxlength="255"
                           placeholder="What does this capability unlock?">
                </div>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-2">
                <div class="form-check" title="Assumed value when a tier hasn't set this capability">
                    <input class="form-check-input" type="checkbox" name="default_value" id="new-default-value" value="1">
                    <label class="form-check-label" for="new-default-value">Default value</label>
                </div>
                <div class="form-check" title="Surface this capability as a camelCase key on the public access_tiers API">
                    <input class="form-check-input" type="checkbox" name="emit_in_api" id="new-emit-in-api" value="1" checked>
                    <label class="form-check-label" for="new-emit-in-api">Emit in API</label>
                </div>
                <div class="form-check" title="Include this capability in the effective registry immediately">
                    <input class="form-check-input" type="checkbox" name="enabled" id="new-enabled" value="1" checked>
                    <label class="form-check-label" for="new-enabled">Enabled</label>
                </div>
            </div>
            <button type="submit" class="btn btn-amber-solid btn-sm mt-3">
                <i class="bi bi-plus me-1"></i>Create capability
            </button>
        </form>

        <?php endif; ?>
    </div>

    <!-- Edit Capability Modal -->
    <div class="modal fade" id="editCapModal" tabindex="-1" aria-labelledby="editCapModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit-cap-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title" id="editCapModalLabel">
                            <i class="bi bi-pencil me-2" aria-hidden="true"></i>Edit capability — <code id="edit-cap-key"></code>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label small">Label</label>
                                <input type="text" name="label" id="edit-cap-label" class="form-control form-control-sm" maxlength="30" required>
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label small">Sort order</label>
                                <input type="number" name="sort_order" id="edit-cap-sort" class="form-control form-control-sm" min="0" max="100000">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Description</label>
                            <input type="text" name="description" id="edit-cap-description" class="form-control form-control-sm" maxlength="255">
                        </div>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="default_value" id="edit-cap-default" value="1">
                                <label class="form-check-label" for="edit-cap-default">Default value</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="emit_in_api" id="edit-cap-emit" value="1">
                                <label class="form-check-label" for="edit-cap-emit">Emit in API</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="enabled" id="edit-cap-enabled" value="1">
                                <label class="form-check-label" for="edit-cap-enabled">Enabled</label>
                            </div>
                        </div>
                        <p class="form-text mt-3 mb-0">
                            The capability key (machine name) cannot be changed — it's the key inside
                            every tier's <code>tblAccessTiers.Capabilities</code> JSON and (if used by a
                            future rule) <code>tblGatingRules.CapKey</code>. Uncheck <strong>Enabled</strong>
                            to soft-disable without losing history; deleting is only available while no
                            rule references this key.
                        </p>
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-amber-solid">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditCap(c) {
            document.getElementById('edit-cap-id').value          = c.Id;
            document.getElementById('edit-cap-key').textContent   = c.CapKey;
            document.getElementById('edit-cap-label').value       = c.Label ?? '';
            document.getElementById('edit-cap-sort').value        = c.SortOrder ?? 0;
            document.getElementById('edit-cap-description').value = c.Description ?? '';
            document.getElementById('edit-cap-default').checked   = Number(c.DefaultValue) === 1;
            document.getElementById('edit-cap-emit').checked      = Number(c.EmitInApi) === 1;
            document.getElementById('edit-cap-enabled').checked   = Number(c.Enabled) === 1;
            new bootstrap.Modal(document.getElementById('editCapModal')).show();
        }
    </script>

    <!-- Sortable table headers (#644 / #842 / #844). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
