<?php

declare(strict_types=1);

/**
 * iHymns — Entitlements Editor (#407)
 *
 * Admin page for reassigning which roles hold each entitlement. The
 * hardcoded map in appWeb/public_html/includes/entitlements.php sets
 * the defaults; this page writes a full override to
 * tblAppSettings.SettingKey = 'entitlements_overrides' which the
 * helper merges on top.
 *
 * Access: the `manage_entitlements` entitlement (default: global_admin).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_entitlements', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_entitlements required</h1></body></html>';
    exit;
}

$ROLES = ['user', 'editor', 'admin', 'global_admin'];
$saved = false;
$error = '';

/* ---------- POST: persist new mapping ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* CSRF check — matches the token minted by csrfToken() and stored in
       the admin session. */
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $submitted = $_POST['ent'] ?? [];
    if (!is_array($submitted)) $submitted = [];

    /* Build the new map from the posted checkboxes. Only consider keys
       that exist in the defaults — unknown keys are dropped. */
    $newMap = [];
    foreach (ENTITLEMENTS as $name => $_default) {
        $roles = $submitted[$name] ?? [];
        if (!is_array($roles)) $roles = [];
        $newMap[$name] = array_values(array_intersect($ROLES, $roles));
        /* Safety: manage_entitlements must always retain at least one
           global_admin, otherwise the edit page becomes unreachable. */
        if ($name === 'manage_entitlements' && !in_array('global_admin', $newMap[$name], true)) {
            $newMap[$name][] = 'global_admin';
        }
    }

    $gateEnabled = !empty($_POST['channel_gate_enabled']);

    if (saveEntitlementOverrides($newMap) && setChannelGateEnabled($gateEnabled)) {
        $saved = true;
    } else {
        $error = 'Could not save — database write failed.';
    }
}

/* ---------- GET: render ---------- */
$effective = effectiveEntitlements();

/* Group entitlements for readability. Anything not in a listed group
   falls into "Other" so newly-added entitlements still appear. */
$groups = [
    'Song data' => ['edit_songs', 'delete_songs', 'bulk_edit_songs', 'verify_songs'],
    'User management' => ['view_users', 'edit_users', 'change_user_roles', 'assign_global_admin', 'delete_users'],
    'Database & operations' => ['view_admin_dashboard', 'view_analytics', 'run_db_install', 'run_db_migrate', 'run_db_backup', 'run_db_restore', 'drop_legacy_tables'],
    'Content moderation' => ['review_song_requests'],
    'Content structure'  => ['manage_songbooks', 'manage_user_groups', 'manage_organisations'],
    'Channel access'     => ['access_alpha', 'access_beta'],
    'Meta' => ['manage_entitlements'],
];
$grouped  = [];
$seen     = [];
foreach ($groups as $g => $names) {
    foreach ($names as $n) {
        if (isset(ENTITLEMENTS[$n])) { $grouped[$g][] = $n; $seen[$n] = true; }
    }
}
foreach (ENTITLEMENTS as $n => $_) {
    if (empty($seen[$n])) { $grouped['Other'][] = $n; }
}

/* Human-readable labels per entitlement. Missing entries fall back to
   a humanised version of the machine key so shipping a new entitlement
   is non-fatal — but every existing one should have a label here. */
$ENTITLEMENT_LABELS = [
    'edit_songs'                => ['Edit songs',                    'Create + edit songs, metadata, arrangements'],
    'delete_songs'              => ['Delete songs',                  'Permanently remove a song'],
    'bulk_edit_songs'           => ['Bulk-edit songs',               'Multi-select → tag / move / verify / export'],
    'verify_songs'              => ['Verify songs',                  'Mark a song as editorially reviewed'],
    'view_users'                => ['View users',                    'Open the Users page'],
    'edit_users'                => ['Edit users',                    'Change profile / display name / email'],
    'change_user_roles'         => ['Change user roles',             'Promote or demote another user (below own level)'],
    'assign_global_admin'       => ['Assign Global Admin',           'Grant the highest role — Global Admin only'],
    'delete_users'              => ['Delete users',                  'Permanently remove a user account'],
    'view_admin_dashboard'      => ['Reach the Admin dashboard',     'Land on /manage/ and see any admin card'],
    'view_analytics'            => ['View analytics',                'Open the analytics dashboard'],
    'run_db_install'            => ['Install / migrate database',    'Create or upgrade schema — Global Admin only'],
    'run_db_migrate'            => ['Run database migrations',       'Apply schema migrations'],
    'run_db_backup'             => ['Back up database',              'Download a SQL snapshot'],
    'run_db_restore'            => ['Restore database from backup',  'Overwrite the live DB from a snapshot'],
    'drop_legacy_tables'        => ['Drop legacy tables',            'Retire obsolete tables — Global Admin only'],
    'review_song_requests'      => ['Review song requests',          'Triage submissions from the public queue'],
    'manage_songbooks'          => ['Manage songbooks',              'CRUD over the songbook catalogue'],
    'manage_user_groups'        => ['Manage user groups',            'CRUD over groups + channel-access toggles'],
    'manage_organisations'      => ['Manage organisations',          'CRUD over orgs + licence metadata + members'],
    'manage_content_restrictions' => ['Manage content restrictions', 'Per-song / per-songbook gating rules'],
    'manage_access_tiers'       => ['Manage access tiers',           'Define tiers that gate audio / MIDI / PDF / offline'],
    'assign_user_tier'          => ['Assign a user\'s access tier',  'Move users between tiers'],
    'manage_default_card_layout'=> ['Manage default card layout',    'Set the site-wide dashboard / home layout'],
    'customise_own_card_layout' => ['Customise own layout',          'Personalise your own dashboard / home order'],
    'access_alpha'              => ['Reach the Alpha channel',       'Sign in on alpha.ihymns.app when gate is on'],
    'access_beta'               => ['Reach the Beta channel',        'Sign in on beta.ihymns.app when gate is on'],
    'manage_entitlements'       => ['Manage entitlements',           'Edit this map itself — Global Admin only'],
];

$entLabel = static function (string $key) use ($ENTITLEMENT_LABELS): array {
    if (isset($ENTITLEMENT_LABELS[$key])) return $ENTITLEMENT_LABELS[$key];
    /* Fallback: humanise the snake_case key. */
    return [ucfirst(str_replace('_', ' ', $key)), ''];
};

$isGlobalAdmin = ($currentUser['role'] ?? '') === 'global_admin';

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entitlements — iHymns Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/css/admin.css') ?>">
    <style>
        .ent-grid th, .ent-grid td { vertical-align: middle; }
        .ent-grid td.role-col { text-align: center; }
        .ent-name { font-family: 'Menlo','Consolas',monospace; font-size: 0.85em; }
    </style>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<div class="container-admin py-4">

    <h1 class="h4 mb-3">Role → Entitlement map</h1>
    <p class="text-secondary small mb-4">
        Decide which roles hold which capabilities. A tick in a cell
        means users with that role can perform the named action; an
        unticked cell revokes it. Changes take effect on the next
        request — no sign-out required.
    </p>

    <?php if ($isGlobalAdmin): ?>
    <details class="mb-4">
        <summary class="text-muted small" style="cursor:pointer;">
            <i class="bi bi-wrench me-1" aria-hidden="true"></i>Technical notes (Global Admin)
        </summary>
        <div class="small text-muted mt-2 ps-3 border-start">
            Server-side checks always re-evaluate this map, so every
            request reads the current state. A client-side mirror lives
            in <code>js/modules/entitlements.js</code> (defaults only;
            admin overrides apply only after the user's next page
            load). Full help lives in the developer documentation.
        </div>
    </details>
    <?php endif; ?>

    <?php if ($saved): ?>
        <div class="alert alert-success py-2">Entitlement map saved.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

        <div class="card-admin p-3 mb-3">
            <h2 class="h6 mb-2">Invite-only gating</h2>
            <div class="form-check form-switch">
                <input type="checkbox"
                       class="form-check-input"
                       id="channel_gate_enabled"
                       name="channel_gate_enabled"
                       value="1"
                       <?= isChannelGateEnabled() ? 'checked' : '' ?>>
                <label class="form-check-label" for="channel_gate_enabled">
                    Enforce invite-only access on alpha / beta channels
                </label>
            </div>
            <p class="text-secondary small mb-0 mt-2">
                Off = anyone can reach the site (bootstrap / setup mode).
                On = only users whose role carries the channel-access
                entitlement below may enter. Leave off until you've set
                the role mapping to your liking, otherwise you'll lock
                yourself out.
            </p>
        </div>

        <?php foreach ($grouped as $groupName => $ents): ?>
            <div class="card-admin p-3 mb-3">
                <h2 class="h6 mb-3"><?= htmlspecialchars($groupName) ?></h2>
                <div class="table-responsive">
                    <table class="table table-sm ent-grid mb-0">
                        <thead>
                            <tr class="text-muted small">
                                <th>Capability</th>
                                <?php foreach ($ROLES as $r): ?>
                                    <th class="role-col"><?= htmlspecialchars(roleLabel($r)) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ents as $ent): ?>
                            <?php
                                $current = $effective[$ent] ?? ENTITLEMENTS[$ent];
                                [$entLbl, $entDesc] = $entLabel($ent);
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($entLbl) ?></div>
                                    <?php if ($entDesc !== ''): ?>
                                        <div class="small text-muted"><?= htmlspecialchars($entDesc) ?></div>
                                    <?php endif; ?>
                                    <?php if ($isGlobalAdmin): ?>
                                        <code class="small text-muted"><?= htmlspecialchars($ent) ?></code>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($ROLES as $r): ?>
                                    <?php $checked = in_array($r, $current, true) ? 'checked' : ''; ?>
                                    <td class="role-col">
                                        <input type="checkbox"
                                               class="form-check-input"
                                               name="ent[<?= htmlspecialchars($ent) ?>][]"
                                               value="<?= htmlspecialchars($r) ?>"
                                               <?= $checked ?>
                                               aria-label="<?= htmlspecialchars(roleLabel($r) . ' — ' . $entLbl) ?>">
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-amber-solid">
                <i class="bi bi-save me-1"></i>Save Mapping
            </button>
            <a href="?reset=1" class="btn btn-outline-secondary"
               onclick="return confirm('Clear admin overrides and restore defaults?')">
                Restore Defaults
            </a>
        </div>
    </form>

    <?php if (isset($_GET['reset']) && $_GET['reset'] === '1'): ?>
        <?php saveEntitlementOverrides([]); ?>
        <script>location.replace('/manage/entitlements');</script>
    <?php endif; ?>

    <p class="text-secondary text-center small mt-4">
        Overrides live in <code>tblAppSettings.SettingKey = 'entitlements_overrides'</code>.
        The default map (restored by "Restore Defaults") is hardcoded in
        <code>includes/entitlements.php</code>.
    </p>

</div>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
