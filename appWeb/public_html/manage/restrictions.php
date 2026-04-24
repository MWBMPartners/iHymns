<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Content Restrictions
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * CRUD over `tblContentRestrictions` — the rule-based lockout system that
 * decides, per song / songbook / feature, whether a given user on a given
 * platform can access the entity. Pairs with the evaluator in
 * appWeb/public_html/includes/content_access.php.
 *
 * Rule model (see schema.sql:438):
 *   EntityType      — song | songbook | feature
 *   EntityId        — concrete ID, or '*' to apply to every entity of the type
 *   RestrictionType — block_platform | block_user | block_org
 *                     | require_licence | require_org
 *   TargetType/Id   — what the rule applies against
 *   Effect          — allow | deny
 *   Priority        — higher wins; deny beats allow at equal priority
 *
 * Gated by the `manage_content_restrictions` entitlement.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_content_restrictions', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_content_restrictions required</h1></body></html>';
    exit;
}
$activePage = 'restrictions';

$error   = '';
$success = '';
$db      = getDb();

/* Allowed vocabularies — kept tight so the UI can't POST rubbish the
   evaluator will silently ignore. Keep in sync with content_access.php. */
const RESTRICTIONS_ENTITY_TYPES = ['song', 'songbook', 'feature'];
const RESTRICTIONS_TYPES = [
    'block_platform' => 'Block platform',
    'block_user'     => 'Block user',
    'block_org'      => 'Block organisation',
    'require_licence'=> 'Require licence',
    'require_org'    => 'Require organisation',
];
const RESTRICTIONS_EFFECTS = ['deny', 'allow'];

/* Picker vocabularies for the #498 name-first form. Hard-coded where
   the list is small and closed (platforms, features), loaded from
   the DB where it's admin-managed (songbooks, organisations).

   * PLATFORMS — matches the set recognised by
     includes/content_access.php::checkContentAccess().
   * FEATURES  — mirrors APP_CONFIG['features'] in includes/config.php;
     this is the full set a restriction rule can reference.
   * LICENCE_TYPES — duplicated from organisations.php for now (same
     4-row map); #459 migrates this to tblLicenceTypes, at which
     point restrictions.php should source from there too. */
const RESTRICTIONS_PLATFORMS = [
    'PWA'    => 'PWA · Web app',
    'Apple'  => 'Apple · iOS / iPadOS / tvOS',
    'Android'=> 'Android · phone / tablet / TV',
    'Amazon' => 'Amazon · Fire OS',
    'Web'    => 'Web · generic browser (non-PWA)',
];
const RESTRICTIONS_FEATURES = [
    'audio_playback' => 'Audio playback (MIDI)',
    'sheet_music'    => 'Sheet music (PDF)',
    'shuffle'        => 'Shuffle / random song',
    'favorites'      => 'Favourites',
];
const RESTRICTIONS_LICENCE_TYPES = [
    'none'         => 'None — no licence on file',
    'ihymns_basic' => 'iHymns Basic — public-domain only',
    'ihymns_pro'   => 'iHymns Pro — full catalogue',
    'ccli'         => 'CCLI — licence number required',
];

/* ----- POST actions ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'create': {
                $entityType      = trim((string)($_POST['entity_type']      ?? ''));
                $entityId        = trim((string)($_POST['entity_id']        ?? ''));
                $restrictionType = trim((string)($_POST['restriction_type'] ?? ''));
                $targetType      = trim((string)($_POST['target_type']      ?? ''));
                $targetId        = trim((string)($_POST['target_id']        ?? ''));
                $effect          = trim((string)($_POST['effect']           ?? 'deny'));
                $priority        = (int)  ($_POST['priority']               ?? 0);
                $reason          = trim((string)($_POST['reason']           ?? ''));

                if (!in_array($entityType, RESTRICTIONS_ENTITY_TYPES, true)) {
                    $error = 'Invalid entity type.'; break;
                }
                if ($entityId === '') {
                    $error = 'Entity ID is required (use "*" to target every entity of the type).'; break;
                }
                if (!array_key_exists($restrictionType, RESTRICTIONS_TYPES)) {
                    $error = 'Invalid restriction type.'; break;
                }
                if (!in_array($effect, RESTRICTIONS_EFFECTS, true)) {
                    $error = 'Effect must be allow or deny.'; break;
                }
                if ($priority < 0 || $priority > 1000) {
                    $error = 'Priority must be between 0 and 1000.'; break;
                }

                $stmt = $db->prepare(
                    'INSERT INTO tblContentRestrictions
                        (EntityType, EntityId, RestrictionType, TargetType, TargetId, Effect, Priority, Reason)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $entityType, $entityId, $restrictionType,
                    $targetType, $targetId, $effect, $priority, $reason,
                ]);
                $success = 'Restriction created.';
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Invalid request.'; break; }
                $stmt = $db->prepare('DELETE FROM tblContentRestrictions WHERE Id = ?');
                $stmt->execute([$id]);
                $success = 'Restriction removed.';
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/restrictions.php] ' . $e->getMessage());
        $error = $error ?: 'Database error — check server logs for details.';
    }
}

/* ----- GET: filters + rows ----- */
$filterEntity = (string)($_GET['entity_type'] ?? '');
$filterType   = (string)($_GET['restriction_type'] ?? '');

$rows = [];
try {
    $sql = 'SELECT Id, EntityType, EntityId, RestrictionType, TargetType, TargetId,
                   Effect, Priority, Reason, CreatedAt
              FROM tblContentRestrictions';
    $where = [];
    $args  = [];
    if ($filterEntity !== '' && in_array($filterEntity, RESTRICTIONS_ENTITY_TYPES, true)) {
        $where[] = 'EntityType = ?';
        $args[]  = $filterEntity;
    }
    if ($filterType !== '' && array_key_exists($filterType, RESTRICTIONS_TYPES)) {
        $where[] = 'RestrictionType = ?';
        $args[]  = $filterType;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY Priority DESC, CreatedAt DESC LIMIT 500';
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[manage/restrictions.php] ' . $e->getMessage());
    $error = $error ?: 'Could not load restrictions.';
}

/* Summary counts per entity type for the header pills */
$counts = ['song' => 0, 'songbook' => 0, 'feature' => 0, 'total' => 0];
try {
    $rs = $db->query(
        'SELECT EntityType, COUNT(*) AS n
           FROM tblContentRestrictions
          GROUP BY EntityType'
    );
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = (string)$r['EntityType'];
        if (isset($counts[$t])) $counts[$t] = (int)$r['n'];
        $counts['total'] += (int)$r['n'];
    }
} catch (\Throwable $e) { /* ignore */ }

/* Is the master gating switch enabled? Surface it prominently so admins
   don't wonder why their restrictions have no effect. */
$gatingEnabled = false;
try {
    $stmt = $db->prepare("SELECT SettingValue FROM tblAppSettings WHERE SettingKey = 'content_gating_enabled'");
    $stmt->execute();
    $gatingEnabled = ((string)($stmt->fetchColumn() ?: '0')) === '1';
} catch (\Throwable $e) { /* ignore */ }

/* Preload songbooks + organisations for the #498 pickers. Both lists
   are small (≈6 songbooks, tens of orgs), so server-rendering the full
   dropdown avoids an API round-trip on page load. Song + user pickers
   stay AJAX-driven because their cardinalities are large. */
$picker_songbooks = [];
try {
    $rs = $db->query(
        'SELECT Abbreviation, Name, SongCount FROM tblSongbooks ORDER BY Name ASC'
    );
    $picker_songbooks = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_e) { /* empty list is a safe default */ }

$picker_organisations = [];
try {
    $rs = $db->query(
        'SELECT Id, Name, Slug, LicenceType FROM tblOrganisations
         WHERE IsActive = 1 ORDER BY Name ASC'
    );
    $picker_organisations = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_e) { /* empty; the picker will fall back to live-search */ }

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Restrictions — iHymns Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . '/css/admin.css') ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-shield-lock me-2"></i>Content Restrictions</h1>
        <p class="text-secondary small mb-3">
            Rule-based lockout for regular users: hide specific songs, whole songbooks, or app features
            based on platform, user, organisation, or licence. Rules evaluate by <code>Priority</code>
            (highest first); at equal priority, <em>deny</em> beats <em>allow</em>.
        </p>

        <?php if (!$gatingEnabled): ?>
            <div class="alert alert-warning py-2 mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i>
                The master switch <code>content_gating_enabled</code> is <strong>OFF</strong>.
                Rules here are saved but currently have no runtime effect.
                Flip it in <a href="/manage/entitlements" class="alert-link">Entitlements &amp; Gating</a>
                (or directly in <code>tblAppSettings</code>).
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Summary pills -->
        <div class="d-flex flex-wrap gap-2 small mb-3">
            <span class="badge bg-secondary">Total <strong class="ms-1"><?= (int)$counts['total'] ?></strong></span>
            <span class="badge bg-info text-dark">Songs <strong class="ms-1"><?= (int)$counts['song'] ?></strong></span>
            <span class="badge bg-primary">Songbooks <strong class="ms-1"><?= (int)$counts['songbook'] ?></strong></span>
            <span class="badge bg-warning text-dark">Features <strong class="ms-1"><?= (int)$counts['feature'] ?></strong></span>
        </div>

        <!-- Filters -->
        <form method="GET" class="card-admin p-3 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <label class="form-label small mb-1">Entity type</label>
                    <select name="entity_type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach (RESTRICTIONS_ENTITY_TYPES as $et): ?>
                            <option value="<?= htmlspecialchars($et) ?>" <?= $filterEntity === $et ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($et)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-5">
                    <label class="form-label small mb-1">Restriction type</label>
                    <select name="restriction_type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach (RESTRICTIONS_TYPES as $k => $lbl): ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= $filterType === $k ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3 d-grid">
                    <button type="submit" class="btn btn-sm btn-outline-info">
                        <i class="bi bi-funnel me-1"></i>Apply filter
                    </button>
                </div>
            </div>
        </form>

        <!-- Rules list -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">Rules <span class="text-muted small">(<?= count($rows) ?> shown, newest first within priority)</span></h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr class="text-muted small">
                            <th>Entity</th>
                            <th>Restriction</th>
                            <th>Target</th>
                            <th class="text-center">Effect</th>
                            <th class="text-center">Priority</th>
                            <th>Reason</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($r['EntityType']) ?></span>
                                    <code class="ms-1"><?= htmlspecialchars($r['EntityId']) ?></code>
                                </td>
                                <td>
                                    <?= htmlspecialchars(RESTRICTIONS_TYPES[$r['RestrictionType']] ?? $r['RestrictionType']) ?>
                                </td>
                                <td class="small text-muted">
                                    <?php if ($r['TargetType'] !== '' || $r['TargetId'] !== ''): ?>
                                        <?= htmlspecialchars($r['TargetType']) ?>
                                        <?php if ($r['TargetId'] !== ''): ?>
                                            = <code><?= htmlspecialchars($r['TargetId']) ?></code>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($r['Effect'] === 'deny'): ?>
                                        <span class="badge bg-danger">deny</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">allow</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= (int)$r['Priority'] ?></td>
                                <td class="small"><?= htmlspecialchars(mb_substr((string)$r['Reason'], 0, 140)) ?></td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this restriction?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$r['Id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                title="Remove rule"
                                                aria-label="Remove restriction rule">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr><td colspan="7" class="text-muted text-center py-4">
                                No restrictions match. Add one below to start gating content.
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create — name-first picker form (#498). Each ID field is a
             type-aware picker instead of raw text: selects for small-
             cardinality (songbook / feature / platform / licence / org)
             and live-search comboboxes for large-cardinality (song /
             user). A hidden canonical input (`entity_id`, `target_id`)
             mirrors the chosen value so the POST handler is unchanged. -->
        <form method="POST" class="card-admin p-3 mb-4" id="restriction-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a restriction</h2>

            <!-- Hidden canonical fields — populated by JS from whichever
                 picker is visible. The server sees the same names as
                 before, so the POST handler at line ~70 is unchanged. -->
            <input type="hidden" name="entity_id"  id="rx-entity-id">
            <input type="hidden" name="target_id"  id="rx-target-id">

            <div class="row g-2 mb-2">
                <div class="col-sm-3">
                    <label class="form-label small">Entity type</label>
                    <!-- data-picker-canonical points the shared helper at
                         the hidden canonical input this type-select feeds. -->
                    <select name="entity_type" id="rx-entity-type"
                            class="form-select form-select-sm"
                            data-picker-canonical="#rx-entity-id" required>
                        <?php foreach (RESTRICTIONS_ENTITY_TYPES as $et): ?>
                            <option value="<?= htmlspecialchars($et) ?>"><?= htmlspecialchars(ucfirst($et)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-5" data-picker-group-for="#rx-entity-id">
                    <label class="form-label small">Entity</label>

                    <!-- song picker: live-search combobox -->
                    <div class="rx-picker" data-picker-for="song">
                        <div class="position-relative">
                            <input type="text" class="form-control form-control-sm rx-picker-input"
                                   data-picker-source="song" autocomplete="off"
                                   placeholder="Type a song title or number — e.g. Amazing Grace">
                            <div class="rx-picker-popover list-group position-absolute w-100 shadow d-none"
                                 style="z-index: 1050; max-height: 240px; overflow-y: auto;"></div>
                        </div>
                        <small class="text-muted">Type <code>*</code> to target every song.</small>
                    </div>

                    <!-- songbook picker: server-rendered select -->
                    <div class="rx-picker d-none" data-picker-for="songbook">
                        <select class="form-select form-select-sm rx-picker-select">
                            <option value="*">* — every songbook</option>
                            <?php foreach ($picker_songbooks as $sb): ?>
                                <option value="<?= htmlspecialchars($sb['Abbreviation']) ?>">
                                    <?= htmlspecialchars($sb['Name']) ?>
                                    (<?= htmlspecialchars($sb['Abbreviation']) ?>) — <?= (int)$sb['SongCount'] ?> songs
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- feature picker: hard-coded select -->
                    <div class="rx-picker d-none" data-picker-for="feature">
                        <select class="form-select form-select-sm rx-picker-select">
                            <option value="*">* — every feature</option>
                            <?php foreach (RESTRICTIONS_FEATURES as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Restriction type</label>
                    <select name="restriction_type" id="rx-restriction-type" class="form-select form-select-sm" required>
                        <?php foreach (RESTRICTIONS_TYPES as $k => $lbl): ?>
                            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Effect</label>
                    <select name="effect" class="form-select form-select-sm">
                        <option value="deny" selected>deny</option>
                        <option value="allow">allow</option>
                    </select>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-sm-3">
                    <label class="form-label small">Target type</label>
                    <select name="target_type" id="rx-target-type"
                            class="form-select form-select-sm"
                            data-picker-canonical="#rx-target-id">
                        <option value="">— (none)</option>
                        <option value="platform">Platform</option>
                        <option value="user">User</option>
                        <option value="organisation">Organisation</option>
                        <option value="licence_type">Licence type</option>
                    </select>
                </div>
                <div class="col-sm-5" data-picker-group-for="#rx-target-id">
                    <label class="form-label small">Target</label>

                    <!-- platform picker: hard-coded select -->
                    <div class="rx-picker" data-picker-for="platform">
                        <select class="form-select form-select-sm rx-picker-select">
                            <option value="">— (any platform)</option>
                            <?php foreach (RESTRICTIONS_PLATFORMS as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- user picker: live-search combobox -->
                    <div class="rx-picker d-none" data-picker-for="user">
                        <div class="position-relative">
                            <input type="text" class="form-control form-control-sm rx-picker-input"
                                   data-picker-source="user" autocomplete="off"
                                   placeholder="Type a display name or @username">
                            <div class="rx-picker-popover list-group position-absolute w-100 shadow d-none"
                                 style="z-index: 1050; max-height: 240px; overflow-y: auto;"></div>
                        </div>
                    </div>

                    <!-- organisation picker: server-rendered select + live-search fallback -->
                    <div class="rx-picker d-none" data-picker-for="organisation">
                        <select class="form-select form-select-sm rx-picker-select">
                            <option value="">— (any organisation)</option>
                            <?php foreach ($picker_organisations as $org): ?>
                                <option value="<?= (int)$org['Id'] ?>">
                                    <?= htmlspecialchars($org['Name']) ?>
                                    — licence: <?= htmlspecialchars($org['LicenceType'] ?: 'none') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- licence type picker -->
                    <div class="rx-picker d-none" data-picker-for="licence_type">
                        <select class="form-select form-select-sm rx-picker-select">
                            <?php foreach (RESTRICTIONS_LICENCE_TYPES as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- none picker (shown when target_type = "") -->
                    <div class="rx-picker d-none" data-picker-for="">
                        <div class="form-text mb-0">No target — rule applies to all users / platforms / orgs.</div>
                    </div>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Priority</label>
                    <input type="number" name="priority" class="form-control form-control-sm"
                           min="0" max="1000" value="100">
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Reason (shown to user)</label>
                    <input type="text" name="reason" class="form-control form-control-sm" maxlength="255"
                           placeholder="e.g. Subscription required">
                </div>
            </div>

            <button type="submit" class="btn btn-amber-solid btn-sm mt-2">
                <i class="bi bi-plus me-1"></i>Add rule
            </button>
            <p class="text-muted small mt-3 mb-0">
                <strong>Tips.</strong>
                Most fields now offer pickers — the canonical IDs are saved for you automatically.
                Use <code>*</code> in the song picker (or the "every" option elsewhere) to match everything of that type.
                For <em>Require organisation</em>, leave the target blank to require any org.
            </p>
        </form>

        <!-- Picker behaviour (#498). Previously a 150-line inline script;
             now a shared helper at /manage/includes/renderEntityPicker.js
             that auto-discovers every picker group inside the form via the
             data-picker-canonical / data-picker-group-for attributes set
             on the markup above. Other admin pages can reuse the same
             module by loading this script and calling initEntityPickers. -->
        <?php
            /* Cache-bust using the helper file's mtime, so admins get
               the latest version immediately after a deploy. */
            $pickerPath = __DIR__ . DIRECTORY_SEPARATOR . 'includes' .
                          DIRECTORY_SEPARATOR . 'renderEntityPicker.js';
            $pickerVer  = @filemtime($pickerPath) ?: 0;
        ?>
        <script src="/manage/includes/renderEntityPicker.js?v=<?= (int)$pickerVer ?>"></script>
        <script>
            (function () {
                var form = document.getElementById('restriction-form');
                if (form && typeof window.initEntityPickers === 'function') {
                    window.initEntityPickers(form);
                }
            })();
        </script>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-zKzgIZcXU99qF1nNW9g+x1znB5NhCPs9qZeGzUnnFOaHJF9jCCKySBjq3vIKabk/"
            crossorigin="anonymous"></script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
