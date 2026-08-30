<?php

declare(strict_types=1);

/**
 * iHymns — Admin: External-Link Types & URL Patterns (#845)
 *
 * CRUD surface for the external-link controlled vocabulary:
 *   - tblExternalLinkTypes  — the registry rows (Wikipedia, Spotify, …)
 *   - tblExternalLinkPatterns — the URL → provider rules that drive
 *     the JS auto-detect module.
 *
 * Curator-edits to either are picked up by every admin edit-modal
 * that ships the registry to its row builder via the
 * `attachExternalLinkPatterns()` helper from
 * /includes/external_link_helpers.php.
 *
 * Gated by `manage_external_link_types`. Pre-migration safe — probes
 * for both tables on every page load.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* #1748 §5.2 / #1992 — IHYMNS_LINK_ENTITY_TYPES + IHYMNS_LINK_TYPE_CATEGORIES,
   the central AppliesTo / Category allow-lists this page's tick-UI, manual
   create form, guided wizard and save/create handlers all consume (rule
   #35 — no page-local provider/entity-type/category list). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
/* API-coverage batch 4b-ii A7 — the save_type_patterns write core. This
   page's POST handler below calls these SAME functions the new
   admin_external_link_type_save API action calls — one validation/write
   core, two thin callers (rule #22/#35). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'external_link_type_admin.php';
/* #1870 — the shared collapsed "Edit slug (advanced)" field the manual
   create form's Slug box uses below (rule #44's vanity-control discipline:
   the server derives a slug from Name when the box is left blank, so it
   stays tucked out of sight until a curator wants to override it). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'slug-field.php';
/* #1999 — the shared "Get started" empty-state launcher, rendered below
   when there are no link types yet (points at the SAME guided wizard the
   header button above already opens — rule #1, one shared partial). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'wizard-empty-state.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_external_link_types', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_external_link_types required</h1></body></html>';
    exit;
}
$activePage = 'external-link-types';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* Schema probes — externalLinkTypeAdminSchemaReady() (API-coverage batch
   4b-ii A7 extraction; byte-identical probe, now shared with the API). */
$schemaReady       = externalLinkTypeAdminSchemaReady($db);
$hasTypesSchema    = $schemaReady['types'];
$hasPatternsSchema = $schemaReady['patterns'];

/* ---- POST actions ---- */
if ($hasPatternsSchema && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    /* #1992 — the guided wizard's JSON-in/JSON-out AJAX branch. Lives
       BEFORE the classic-form dispatch below and gates on
       validateCsrfRequest() (same-origin X-Requested-With, rule #29) —
       NOT the legacy form's baked validateCsrf() token — because the
       wizard's fetch() call always sets X-Requested-With, so a long-open
       page never sporadically 403s the way a stale per-render token could
       (duplicate-songs.php precedent, manage/duplicate-songs.php). The
       classic manual-form/edit-form POSTs below are UNCHANGED and still
       gate on validateCsrf() alone. */
    if ($action === 'wizard_create_type') {
        header('Content-Type: application/json; charset=UTF-8');
        if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF check failed — please retry.']);
            exit;
        }

        $fields = null;
        try {
            $rawName       = (string)($_POST['name'] ?? '');
            $rawSlug       = (string)($_POST['slug'] ?? '');
            $rawCategory   = (string)($_POST['category'] ?? '');
            $rawIconClass  = (string)($_POST['icon_class'] ?? '');
            $postedApplies = $_POST['applies_to'] ?? [];
            if (!is_array($postedApplies)) { $postedApplies = []; }
            $allowMultiple = !empty($_POST['allow_multiple']) ? 1 : 0;
            $isActiveNew   = !empty($_POST['is_active']) ? 1 : 0;

            [$fields, $validationError, $status] = externalLinkTypeAdminValidateNewType(
                $db, $rawName, $rawSlug, $rawCategory, $rawIconClass, $postedApplies, $allowMultiple, $isActiveNew
            );
            if ($validationError !== null) {
                http_response_code($status);
                echo json_encode(['error' => $validationError]);
                exit;
            }

            $pHosts  = $_POST['pattern_host']      ?? [];
            $pPaths  = $_POST['pattern_path']      ?? [];
            $pSubs   = $_POST['pattern_subdomain'] ?? [];
            $pPrios  = $_POST['pattern_priority']  ?? [];
            $pNotes  = $_POST['pattern_note']      ?? [];
            $pActive = $_POST['pattern_active']    ?? [];
            if (!is_array($pHosts))  { $pHosts  = []; }
            if (!is_array($pPaths))  { $pPaths  = []; }
            if (!is_array($pSubs))   { $pSubs   = []; }
            if (!is_array($pPrios))  { $pPrios  = []; }
            if (!is_array($pNotes))  { $pNotes  = []; }
            if (!is_array($pActive)) { $pActive = []; }

            /* Security audit F2 — row-count DoS cap
               (IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP,
               includes/external_link_type_admin.php), checked before a single
               row is normalised — this JSON branch is bounded only by
               post_max_size, not PHP's form max_input_vars. */
            if (max(count($pHosts), count($pPaths)) > IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP) {
                http_response_code(422);
                echo json_encode(['error' => 'Too many pattern rows in one request.']);
                exit;
            }

            $patternRows = externalLinkTypeAdminNormalisePatterns($pHosts, $pPaths, $pSubs, $pPrios, $pNotes, $pActive);
            $newId = externalLinkTypeAdminCreate($db, $fields, $patternRows);

            if (function_exists('logActivity')) {
                logActivity('external_link_type.create', 'external_link_type', (string)$newId, [
                    'slug' => $fields['slug'], 'name' => $fields['name'],
                    'pattern_count' => count($patternRows), 'via' => 'wizard',
                ]);
            }
            echo json_encode([
                'ok'            => true,
                'id'            => $newId,
                'slug'          => $fields['slug'],
                'name'          => $fields['name'],
                'pattern_count' => count($patternRows),
            ]);
        } catch (ExternalLinkTypeDuplicateSlugException $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[external-link-types wizard_create_type] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not create link type.']);
        }
        exit;
    }

    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    try {
        switch ($action) {
            case 'save_type_patterns': {
                $typeId = (int)($_POST['type_id'] ?? 0);
                if ($typeId <= 0) { $error = 'Type id is required.'; break; }

                /* Toggle the parent type's IsActive at the same time —
                   the form ships an `is_active` flag (1/0). */
                $isActive = !empty($_POST['is_active']) ? 1 : 0;

                /* #1748 §5.2 — AppliesTo tick-UI resolution + pattern
                   normalisation now live in the shared core
                   includes/external_link_type_admin.php (API-coverage
                   batch 4b-ii A7 extraction) — byte-identical logic, so
                   this page's behaviour is unchanged. */
                $postedApplies = $_POST['applies_to'] ?? [];
                if (!is_array($postedApplies)) $postedApplies = [];

                $existingAppliesTo = externalLinkTypeAdminFetchAppliesTo($db, $typeId);
                if ($existingAppliesTo === null) { $error = 'Link type not found.'; break; }
                $applies = externalLinkTypeAdminResolveAppliesTo($postedApplies, $existingAppliesTo);
                $appliesToSave    = $applies['value'];
                $appliesToWarning = $applies['warning'];

                /* Patterns posted as parallel arrays. */
                $pHosts   = $_POST['pattern_host']     ?? [];
                $pPaths   = $_POST['pattern_path']     ?? [];
                $pSubs    = $_POST['pattern_subdomain'] ?? [];
                $pPrios   = $_POST['pattern_priority'] ?? [];
                $pNotes   = $_POST['pattern_note']     ?? [];
                $pActive  = $_POST['pattern_active']   ?? [];
                if (!is_array($pHosts))  $pHosts  = [];
                if (!is_array($pPaths))  $pPaths  = [];
                if (!is_array($pSubs))   $pSubs   = [];
                if (!is_array($pPrios))  $pPrios  = [];
                if (!is_array($pNotes))  $pNotes  = [];
                if (!is_array($pActive)) $pActive = [];

                /* Security audit F2 — row-count DoS cap
                   (IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP,
                   includes/external_link_type_admin.php). */
                if (max(count($pHosts), count($pPaths)) > IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP) {
                    http_response_code(422);
                    $error = 'Too many pattern rows in one request.';
                    break;
                }

                $patternRows = externalLinkTypeAdminNormalisePatterns($pHosts, $pPaths, $pSubs, $pPrios, $pNotes, $pActive);
                $insertCount = externalLinkTypeAdminSave($db, $typeId, $isActive, $appliesToSave, $patternRows);

                if (function_exists('logActivity')) {
                    logActivity('external_link_type.save_patterns', 'external_link_type', (string)$typeId, [
                        'is_active'     => (bool)$isActive,
                        'pattern_count' => $insertCount,
                        'applies_to'    => $appliesToSave,
                    ]);
                }
                $success = "Saved {$insertCount} pattern" . ($insertCount === 1 ? '' : 's') . '.' . $appliesToWarning;
                break;
            }

            /* #1992 — the plain manual "Add provider" form (the collapsed
               <details> card near the top of the page). Classic form POST,
               baked csrf_token (validateCsrf() above, unchanged), one of
               the THREE funnels that all delegate to the SAME
               externalLinkTypeAdminValidateNewType()/…Create() core — the
               guided wizard's `wizard_create_type` AJAX branch (above) and
               the admin_external_link_type_create API twin are the other
               two (rule #22). */
            case 'create_type': {
                $rawName       = (string)($_POST['name'] ?? '');
                $rawSlug       = (string)($_POST['slug'] ?? '');
                $rawCategory   = (string)($_POST['category'] ?? '');
                $rawIconClass  = (string)($_POST['icon_class'] ?? '');
                $postedApplies = $_POST['applies_to'] ?? [];
                if (!is_array($postedApplies)) $postedApplies = [];
                $allowMultiple = !empty($_POST['allow_multiple']) ? 1 : 0;
                $isActiveNew   = !empty($_POST['is_active']) ? 1 : 0;

                [$fields, $validationError, $status] = externalLinkTypeAdminValidateNewType(
                    $db, $rawName, $rawSlug, $rawCategory, $rawIconClass, $postedApplies, $allowMultiple, $isActiveNew
                );
                if ($validationError !== null) {
                    http_response_code($status);
                    $error = $validationError;
                    break;
                }

                $pHosts   = $_POST['pattern_host']     ?? [];
                $pPaths   = $_POST['pattern_path']     ?? [];
                $pSubs    = $_POST['pattern_subdomain'] ?? [];
                $pPrios   = $_POST['pattern_priority'] ?? [];
                $pNotes   = $_POST['pattern_note']     ?? [];
                $pActive  = $_POST['pattern_active']   ?? [];
                if (!is_array($pHosts))  $pHosts  = [];
                if (!is_array($pPaths))  $pPaths  = [];
                if (!is_array($pSubs))   $pSubs   = [];
                if (!is_array($pPrios))  $pPrios  = [];
                if (!is_array($pNotes))  $pNotes  = [];
                if (!is_array($pActive)) $pActive = [];

                /* Security audit F2 — row-count DoS cap
                   (IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP,
                   includes/external_link_type_admin.php). */
                if (max(count($pHosts), count($pPaths)) > IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP) {
                    http_response_code(422);
                    $error = 'Too many pattern rows in one request.';
                    break;
                }

                $patternRows = externalLinkTypeAdminNormalisePatterns($pHosts, $pPaths, $pSubs, $pPrios, $pNotes, $pActive);

                try {
                    $newId = externalLinkTypeAdminCreate($db, $fields, $patternRows);
                } catch (ExternalLinkTypeDuplicateSlugException $e) {
                    http_response_code(409);
                    $error = $e->getMessage();
                    break;
                }

                if (function_exists('logActivity')) {
                    logActivity('external_link_type.create', 'external_link_type', (string)$newId, [
                        'slug' => $fields['slug'], 'name' => $fields['name'],
                        'pattern_count' => count($patternRows), 'via' => 'manual',
                    ]);
                }
                $success = "Created link type \"{$fields['name']}\" with " . count($patternRows)
                    . ' pattern' . (count($patternRows) === 1 ? '' : 's') . '.';
                break;
            }
        }
    } catch (\Throwable $e) {
        error_log('[external-link-types POST] ' . $e->getMessage());
        /* Security audit F5 — every OTHER catch on this page (the wizard's
           JSON branch, the create_type duplicate-slug catch) already keeps
           exception prose out of the response; this generic catch was the
           odd one out, leaking mysqli_sql_exception detail (table/column/
           constraint names) to an admin. Detail stays in error_log() only. */
        $error = 'Could not save changes.';
    }
}

/* ---- Read ---- */
$types = [];
if ($hasTypesSchema) {
    try {
        $res = $db->query(
            'SELECT Id, Slug, Name, Category, IconClass, AppliesTo, AllowMultiple,
                    IsActive, DisplayOrder
               FROM tblExternalLinkTypes
              ORDER BY Category ASC, DisplayOrder ASC, Name ASC'
        );
        while ($row = $res->fetch_assoc()) {
            $types[(int)$row['Id']] = [
                'id'             => (int)$row['Id'],
                'slug'           => (string)$row['Slug'],
                'name'           => (string)$row['Name'],
                'category'       => (string)$row['Category'],
                'iconClass'      => (string)($row['IconClass'] ?? ''),
                'appliesTo'      => (string)$row['AppliesTo'],
                'allowMultiple'  => (int)$row['AllowMultiple'],
                'isActive'       => (int)$row['IsActive'],
                'displayOrder'   => (int)$row['DisplayOrder'],
                'patterns'       => [],
            ];
        }
        $res->close();

        if ($hasPatternsSchema && $types) {
            $res = $db->query(
                'SELECT Id, LinkTypeId, Host, PathPrefix, MatchSubdomains,
                        Priority, IsActive, Note
                   FROM tblExternalLinkPatterns
                  ORDER BY LinkTypeId ASC, Priority ASC, Host ASC'
            );
            while ($row = $res->fetch_assoc()) {
                $tid = (int)$row['LinkTypeId'];
                if (!isset($types[$tid])) continue;
                $types[$tid]['patterns'][] = [
                    'id'              => (int)$row['Id'],
                    'host'            => (string)$row['Host'],
                    'pathPrefix'      => (string)($row['PathPrefix'] ?? ''),
                    'matchSubdomains' => (int)$row['MatchSubdomains'],
                    'priority'        => (int)$row['Priority'],
                    'isActive'        => (int)$row['IsActive'],
                    'note'            => (string)($row['Note'] ?? ''),
                ];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[external-link-types read] ' . $e->getMessage());
    }
}

/* Group types by Category for the page render. */
$typesByCategory = [];
foreach ($types as $t) {
    $cat = (string)$t['category'];
    $typesByCategory[$cat][] = $t;
}
/* #1992 — re-pointed to the central IHYMNS_LINK_TYPE_CATEGORIES const
   (includes/external_link_helpers.php) so this page's render and the
   manual-create/wizard/API create funnels' validation all read the SAME
   list (rule #35). Byte-identical values to the pre-#1992 page-local
   array this replaces. */
$categoryLabels = IHYMNS_LINK_TYPE_CATEGORIES;

/* #1992 — the guided wizard's live pattern-test needs the REAL detection
   engine (js/modules/external-link-detect.js's window.iHymnsLinkDetect),
   which reads its DB-driven rule set from window._iHymnsLinkTypes — the
   SAME global every other admin edit-modal already seeds (works.php:1458
   is the emit-shape precedent this copies: JSON_HEX_* flags so the JSON
   blob can sit safely inside an inline <script>). Scoped to ACTIVE types'
   ACTIVE patterns only, mirroring what attachExternalLinkPatterns() feeds
   the PUBLIC detector — the wizard's "does my new pattern collide with an
   existing one?" preview must test against what a real visitor's browser
   would actually see, not every inactive/disabled row this admin page
   also renders for editing. */
$linkTypesForWizard = [];
foreach ($types as $t) {
    if (!$t['isActive']) continue;
    $activePatterns = array_values(array_filter($t['patterns'], static fn(array $p): bool => (bool)$p['isActive']));
    $linkTypesForWizard[] = [
        'id'       => $t['id'],
        'slug'     => $t['slug'],
        'name'     => $t['name'],
        'patterns' => array_map(static fn(array $p): array => [
            'host'            => $p['host'],
            'pathPrefix'      => $p['pathPrefix'] !== '' ? $p['pathPrefix'] : null,
            'matchSubdomains' => (bool)$p['matchSubdomains'],
            'priority'        => $p['priority'],
        ], $activePatterns),
    ];
}

/* Cache-busted import path for the shared stepper module (#1992) — same
   filemtime-as-version-query pattern head-libs.php uses for every other
   admin JS load. */
$_adminWizardPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'admin-wizard.js';
$adminWizardVer   = is_file($_adminWizardPath) ? (string)filemtime($_adminWizardPath) : '1';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title>External-Link Types — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><i aria-hidden="true" class="bi bi-link-45deg me-2"></i>Link Types</h1>
                <p class="text-secondary small mb-0">
                    The list of outside places a song, songbook or person can link to — such as a hymn's page on
                    Wikipedia or an artist on a music service. It fills the <strong>Find this … elsewhere</strong>
                    panels on the public site, and it also spots a pasted web address and picks the right type for
                    you. Each type can list the web addresses it recognises. Add or change a type here and it takes
                    effect straight away.
                </p>
            </div>
            <?php /* #1992 — the guided-wizard trigger, gated identically to the manual
                     "Add provider" form below (both need the same two tables). */ ?>
            <?php if ($hasTypesSchema && $hasPatternsSchema): ?>
                <button type="button" class="btn btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#linkTypeWizardModal">
                    <i aria-hidden="true" class="bi bi-magic me-1"></i>Add provider (guided)
                </button>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$hasTypesSchema || !$hasPatternsSchema): ?>
            <div class="card-admin p-4 text-center">
                <p class="mb-2">
                    <i class="bi bi-database-exclamation text-warning fs-1" aria-hidden="true"></i>
                </p>
                <h2 class="h6 mb-2">Schema not yet installed</h2>
                <p class="text-muted small mb-3">
                    The <code>tblExternalLinkTypes</code> (#833) and
                    <code>tblExternalLinkPatterns</code> (#845) tables aren't both present yet.
                </p>
                <a href="/manage/setup-database" class="btn btn-amber btn-sm">
                    <i aria-hidden="true" class="bi bi-database-gear me-1"></i>Run /manage/setup-database
                </a>
            </div>
        <?php else: ?>

        <?php /* =========================================================
                 #1992 — plain MANUAL "Add provider" form. Collapsed by
                 default (curators reach for the guided wizard above for
                 the common case; this is the raw-fields escape hatch the
                 owner's "manual method must be possible" rule requires
                 for any create-wizard). Classic form POST, baked
                 csrf_token, the NEW `create_type` case inside the SAME
                 switch as `save_type_patterns` above — reuses the
                 identical pattern-row markup + the page's existing
                 add-pattern/remove-pattern click delegation (the inline
                 <script> right before admin-footer.php, unchanged), so a
                 curator adding a pattern row here gets the exact same
                 blank-row behaviour as editing an existing type.
               ========================================================= */ ?>
        <details class="card-admin p-3 mb-4">
            <summary class="h6 mb-0 text-uppercase text-muted user-select-none" style="cursor:pointer; list-style-position: outside;">
                <i aria-hidden="true" class="bi bi-plus-circle me-2"></i>Add a link type manually
            </summary>
            <div class="mt-3">
                <p class="text-secondary small mb-3">
                    For when the guided wizard is more than you need — fill in the same fields directly.
                </p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="create_type">

                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="mc-name">Name</label>
                            <input type="text" class="form-control form-control-sm" id="mc-name" name="name" maxlength="120" required>
                        </div>
                        <div class="col-md-3">
                            <?= ihymns_slug_advanced_field([
                                'id'          => 'mc-slug',
                                'value'       => '',
                                'maxlength'   => 60,
                                'pattern'     => '[a-z0-9-]{1,60}',
                                'placeholder' => 'derived from name if blank',
                                'small'       => true,
                            ]) ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="mc-category">Category</label>
                            <select class="form-select form-select-sm" id="mc-category" name="category">
                                <?php foreach ($categoryLabels as $catKey => $catLabel): ?>
                                    <option value="<?= htmlspecialchars($catKey) ?>"><?= htmlspecialchars($catLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="mc-icon">Icon class (optional)</label>
                            <input type="text" class="form-control form-control-sm" id="mc-icon" name="icon_class" placeholder="bi-music-note" maxlength="60">
                            <div class="form-text small">
                                A <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">Bootstrap Icons</a> class name, e.g. <code>bi-music-note</code>.
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="mc-allow-multiple" name="allow_multiple" value="1" checked>
                                <label class="form-check-label" for="mc-allow-multiple">Allow multiple per item</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="mc-active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="mc-active">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1" id="mc-applies-label">Applies to</label>
                        <div class="d-flex flex-wrap gap-3" role="group" aria-labelledby="mc-applies-label">
                            <?php foreach (IHYMNS_LINK_ENTITY_TYPES as $entTok): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="applies_to[]" value="<?= htmlspecialchars($entTok) ?>"
                                           id="mc-applies-<?= htmlspecialchars($entTok) ?>">
                                    <label class="form-check-label small" for="mc-applies-<?= htmlspecialchars($entTok) ?>">
                                        <?= htmlspecialchars(ucfirst($entTok)) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <h3 class="h6 mb-2"><i aria-hidden="true" class="bi bi-link me-2"></i>URL patterns (optional)</h3>
                    <p class="form-text small mt-0 mb-2">
                        Each row matches a URL by hostname (and optionally path prefix). Lower priority numbers win.
                        You can also add these later from this type's own edit form below.
                    </p>
                    <div class="vstack gap-2 patterns-rows" data-rows></div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-info btn-sm" data-action="add-pattern">
                            <i aria-hidden="true" class="bi bi-plus-lg me-1"></i>Add pattern
                        </button>
                        <button type="submit" class="btn btn-amber btn-sm ms-auto">
                            <i aria-hidden="true" class="bi bi-save me-1"></i>Create link type
                        </button>
                    </div>
                </form>
            </div>
        </details>

        <?php /* #1999 — empty-state "Get started" launcher: only when there
                 are no link types at all yet (every category loop below
                 would otherwise render nothing and the page would look
                 blank under the manual-add details). Same schema-ready
                 gate as the header trigger + manual form above. */ ?>
        <?php if (!$types): ?>
            <?= ihymns_wizard_empty_state([
                'icon'        => 'bi-link-45deg',
                'heading'     => 'No link types yet',
                'body'        => 'Add the external providers (Spotify, Wikipedia, YouTube, …) songs and songbooks can link out to.',
                'modalId'     => 'linkTypeWizardModal',
                'buttonLabel' => 'Add provider (guided)',
                'wrap'        => 'card',
                'hint'        => 'Prefer to type it yourself? Expand "Add a link type manually" above.',
            ]) ?>
        <?php endif; ?>

        <?php foreach ($categoryLabels as $catKey => $catLabel): ?>
            <?php if (empty($typesByCategory[$catKey])) continue; ?>
            <div class="card-admin p-3 mb-4">
                <h2 class="h6 mb-3 text-uppercase text-muted"><?= htmlspecialchars($catLabel) ?></h2>
                <div class="vstack gap-3">
                    <?php foreach ($typesByCategory[$catKey] as $t): ?>
                        <details class="card bg-body-tertiary border-secondary">
                            <summary class="card-header d-flex align-items-center gap-2 user-select-none" style="cursor:pointer; list-style-position: outside;">
                                <?php if (!empty($t['iconClass'])): ?>
                                    <i class="<?= htmlspecialchars($t['iconClass']) ?>" aria-hidden="true"></i>
                                <?php endif; ?>
                                <strong><?= htmlspecialchars($t['name']) ?></strong>
                                <code class="small text-muted"><?= htmlspecialchars($t['slug']) ?></code>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1" title="Applies to entity types">
                                    <?= htmlspecialchars($t['appliesTo']) ?>
                                </span>
                                <span class="ms-auto small">
                                    <span class="badge <?= $t['isActive'] ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>">
                                        <?= $t['isActive'] ? 'active' : 'inactive' ?>
                                    </span>
                                    <span class="text-muted ms-2">
                                        <?= count($t['patterns']) ?> pattern<?= count($t['patterns']) === 1 ? '' : 's' ?>
                                    </span>
                                </span>
                            </summary>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="save_type_patterns">
                                    <input type="hidden" name="type_id" value="<?= (int)$t['id'] ?>">

                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox"
                                               name="is_active" value="1"
                                               id="is-active-<?= (int)$t['id'] ?>"
                                               <?= $t['isActive'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is-active-<?= (int)$t['id'] ?>">
                                            Active — show this provider on public pages and offer it in edit-modal dropdowns
                                        </label>
                                    </div>

                                    <!-- #1748 §5.2 — AppliesTo tick UI. One checkbox per
                                         IHYMNS_LINK_ENTITY_TYPES entry (external_link_helpers.php);
                                         growing the vocabulary is one line there, never a page-local
                                         list here. Save intersects the posted set against the const
                                         and preserves any legacy token (e.g. 'person') not in it. -->
                                    <div class="mb-3">
                                        <label class="form-label small mb-1" id="applies-to-label-<?= (int)$t['id'] ?>">Applies to</label>
                                        <div class="d-flex flex-wrap gap-3" role="group" aria-labelledby="applies-to-label-<?= (int)$t['id'] ?>">
                                            <?php
                                                $curApplies = array_map('trim', explode(',', $t['appliesTo']));
                                                foreach (IHYMNS_LINK_ENTITY_TYPES as $entTok):
                                            ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox"
                                                           name="applies_to[]" value="<?= htmlspecialchars($entTok) ?>"
                                                           id="applies-<?= (int)$t['id'] ?>-<?= htmlspecialchars($entTok) ?>"
                                                           <?= in_array($entTok, $curApplies, true) ? 'checked' : '' ?>>
                                                    <label class="form-check-label small" for="applies-<?= (int)$t['id'] ?>-<?= htmlspecialchars($entTok) ?>">
                                                        <?= htmlspecialchars(ucfirst($entTok)) ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php
                                            $legacyApplies = array_values(array_diff($curApplies, IHYMNS_LINK_ENTITY_TYPES));
                                            $legacyApplies = array_filter($legacyApplies, static fn(string $t): bool => $t !== '');
                                        ?>
                                        <?php if ($legacyApplies): ?>
                                            <div class="form-text small">
                                                Also applies to (legacy, untickable here — kept as-is on save):
                                                <?= htmlspecialchars(implode(', ', $legacyApplies)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="h6 mb-2"><i aria-hidden="true" class="bi bi-link me-2"></i>URL patterns</h3>
                                    <p class="form-text small mt-0 mb-2">
                                        Each row matches a URL by hostname (and optionally path prefix). Lower
                                        priority numbers win, so put more-specific patterns first.
                                    </p>

                                    <div class="vstack gap-2 patterns-rows" data-rows>
                                        <?php foreach ($t['patterns'] as $pIdx => $p): ?>
                                            <?php $pFieldPrefix = 'pattern-' . (int)$t['id'] . '-' . (int)$pIdx; ?>
                                            <div class="card bg-secondary-subtle border-secondary">
                                                <div class="card-body py-2">
                                                    <div class="row g-2 align-items-center">
                                                        <div class="col-md-4">
                                                            <label class="form-label small mb-0" for="<?= $pFieldPrefix ?>-host">Host</label>
                                                            <input type="text" class="form-control form-control-sm" name="pattern_host[]"
                                                                   id="<?= $pFieldPrefix ?>-host"
                                                                   value="<?= htmlspecialchars($p['host']) ?>"
                                                                   placeholder="wikipedia.org" required maxlength="255">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label small mb-0" for="<?= $pFieldPrefix ?>-path">Path prefix</label>
                                                            <input type="text" class="form-control form-control-sm" name="pattern_path[]"
                                                                   id="<?= $pFieldPrefix ?>-path"
                                                                   value="<?= htmlspecialchars($p['pathPrefix']) ?>"
                                                                   placeholder="/work/  (optional)" maxlength="255">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label small mb-0" for="<?= $pFieldPrefix ?>-priority">Priority</label>
                                                            <input type="number" class="form-control form-control-sm" name="pattern_priority[]"
                                                                   id="<?= $pFieldPrefix ?>-priority"
                                                                   value="<?= (int)$p['priority'] ?>" min="0" max="65535">
                                                        </div>
                                                        <div class="col-md-3 d-flex flex-column align-items-start gap-1 mt-3">
                                                            <div class="form-check small">
                                                                <input class="form-check-input" type="checkbox" name="pattern_subdomain[]" value="1" id="<?= $pFieldPrefix ?>-subdomain" <?= $p['matchSubdomains'] ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="<?= $pFieldPrefix ?>-subdomain">Match sub-domains</label>
                                                            </div>
                                                            <div class="form-check small">
                                                                <input class="form-check-input" type="checkbox" name="pattern_active[]" value="1" id="<?= $pFieldPrefix ?>-active" <?= $p['isActive'] ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="<?= $pFieldPrefix ?>-active">Active</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row g-2 mt-1">
                                                        <div class="col-md-9">
                                                            <input type="text" class="form-control form-control-sm" name="pattern_note[]"
                                                                   value="<?= htmlspecialchars($p['note']) ?>"
                                                                   placeholder="Optional note (curator's reference)" maxlength="255">
                                                        </div>
                                                        <div class="col-md-3 text-end">
                                                            <button type="button" class="btn btn-sm btn-outline-danger" data-action="remove-pattern">
                                                                <i aria-hidden="true" class="bi bi-x-lg"></i> Remove
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="d-flex gap-2 mt-3">
                                        <button type="button" class="btn btn-outline-info btn-sm" data-action="add-pattern" data-type-id="<?= (int)$t['id'] ?>">
                                            <i aria-hidden="true" class="bi bi-plus-lg me-1"></i>Add pattern
                                        </button>
                                        <button type="submit" class="btn btn-amber btn-sm ms-auto">
                                            <i aria-hidden="true" class="bi bi-save me-1"></i>Save
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <script>
    (function () {
        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        const blankRowHtml =
          '<div class="card bg-secondary-subtle border-secondary">' +
            '<div class="card-body py-2">' +
              '<div class="row g-2 align-items-center">' +
                '<div class="col-md-4">' +
                  '<label class="form-label small mb-0" aria-hidden="true">Host</label>' +
                  '<input type="text" class="form-control form-control-sm" name="pattern_host[]" aria-label="New pattern host" placeholder="wikipedia.org" required maxlength="255">' +
                '</div>' +
                '<div class="col-md-3">' +
                  '<label class="form-label small mb-0" aria-hidden="true">Path prefix</label>' +
                  '<input type="text" class="form-control form-control-sm" name="pattern_path[]" aria-label="New pattern path prefix" placeholder="/work/  (optional)" maxlength="255">' +
                '</div>' +
                '<div class="col-md-2">' +
                  '<label class="form-label small mb-0" aria-hidden="true">Priority</label>' +
                  '<input type="number" class="form-control form-control-sm" name="pattern_priority[]" aria-label="New pattern priority" value="100" min="0" max="65535">' +
                '</div>' +
                '<div class="col-md-3 d-flex flex-column align-items-start gap-1 mt-3">' +
                  '<div class="form-check small"><input class="form-check-input" type="checkbox" name="pattern_subdomain[]" value="1" aria-label="New pattern — match sub-domains" checked><label class="form-check-label" aria-hidden="true">Match sub-domains</label></div>' +
                  '<div class="form-check small"><input class="form-check-input" type="checkbox" name="pattern_active[]" value="1" aria-label="New pattern — active" checked><label class="form-check-label" aria-hidden="true">Active</label></div>' +
                '</div>' +
              '</div>' +
              '<div class="row g-2 mt-1">' +
                '<div class="col-md-9"><input type="text" class="form-control form-control-sm" name="pattern_note[]" placeholder="Optional note" maxlength="255"></div>' +
                '<div class="col-md-3 text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-action="remove-pattern"><i aria-hidden="true" class="bi bi-x-lg"></i> Remove</button></div>' +
              '</div>' +
            '</div>' +
          '</div>';

        document.addEventListener('click', (ev) => {
            const btn = ev.target.closest('[data-action]');
            if (!btn) return;
            const a = btn.getAttribute('data-action');
            if (a === 'add-pattern') {
                const rows = btn.closest('form').querySelector('[data-rows]');
                if (!rows) return;
                const tmp = document.createElement('div');
                tmp.innerHTML = blankRowHtml;
                rows.appendChild(tmp.firstElementChild);
            } else if (a === 'remove-pattern') {
                btn.closest('.card.bg-secondary-subtle')?.remove();
            }
        });
    })();
    </script>

    <?php /* =====================================================================
             #1992 — guided "Add provider" wizard: the Bootstrap-modal markup,
             the window._iHymnsLinkTypes seed for its live pattern-test, and the
             wizard's own wiring. Gated on the SAME schema-ready check as the
             trigger button in the page header and the manual create form above
             (guard: nothing here can run against tables that don't exist yet). */ ?>
    <?php if ($hasTypesSchema && $hasPatternsSchema): ?>
    <script>
    /* Seeded link-type registry for the wizard's live pattern-test
       (js/modules/external-link-detect.js's window.iHymnsLinkDetect reads
       its DB-driven rules from this global) — same emit shape as
       manage/works.php's own external-links row-builder seed. */
    window._iHymnsLinkTypes = <?= json_encode($linkTypesForWizard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>

    <div class="modal fade" id="linkTypeWizardModal" tabindex="-1" aria-hidden="true" aria-labelledby="linkTypeWizardModalLabel" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" id="linkTypeWizardRoot">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0" id="linkTypeWizardModalLabel">Add a link provider — guided</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div data-wiz-progress class="mb-3"></div>

                    <section data-wiz-step data-wiz-label="Identity">
                        <h3 data-wiz-heading class="h6 mb-3">1. What is it called?</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <div class="mb-3">
                            <label class="form-label" for="wiz-name">Name</label>
                            <input type="text" class="form-control" id="wiz-name" maxlength="120" aria-required="true">
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="wiz-slug">Slug</label>
                                <input type="text" class="form-control" id="wiz-slug" maxlength="60" aria-required="true">
                                <div class="form-text small">Derived from the name — edit it if you'd rather choose your own.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="wiz-category">Category</label>
                                <select class="form-select" id="wiz-category">
                                    <?php foreach ($categoryLabels as $catKey => $catLabel): ?>
                                        <option value="<?= htmlspecialchars($catKey) ?>"><?= htmlspecialchars($catLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label" for="wiz-icon">Icon (optional)</label>
                            <div class="d-flex align-items-center gap-2">
                                <i id="wiz-icon-preview" class="bi" aria-hidden="true"></i>
                                <input type="text" class="form-control" id="wiz-icon" maxlength="60" placeholder="bi-music-note">
                            </div>
                            <div class="form-text small">
                                A <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">Bootstrap Icons</a> class name, e.g. <code>bi-music-note</code>.
                            </div>
                        </div>
                    </section>

                    <section data-wiz-step data-wiz-label="Detection" hidden>
                        <h3 data-wiz-heading class="h6 mb-3">2. How do we recognise its links?</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <p class="text-secondary small">
                            Paste a sample web address for this provider — we'll suggest a matching rule and test it against every other provider's patterns live.
                        </p>
                        <div class="input-group mb-2">
                            <label class="visually-hidden" for="wiz-sample-url">Sample URL</label>
                            <input type="text" class="form-control" id="wiz-sample-url" placeholder="https://example.com/some-hymn">
                            <button type="button" class="btn btn-outline-info" data-wiz-suggest>
                                <i aria-hidden="true" class="bi bi-magic me-1"></i>Suggest a pattern
                            </button>
                        </div>
                        <div class="vstack gap-2" id="wiz-pattern-rows"></div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" data-wiz-add-pattern>
                            <i aria-hidden="true" class="bi bi-plus-lg me-1"></i>Add pattern manually
                        </button>
                    </section>

                    <section data-wiz-step data-wiz-label="Applies to" hidden>
                        <h3 data-wiz-heading class="h6 mb-3">3. What can it link from?</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <div class="d-flex flex-wrap gap-3 mb-3" role="group" aria-label="Applies to">
                            <?php foreach (IHYMNS_LINK_ENTITY_TYPES as $entTok): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="wiz-applies-<?= htmlspecialchars($entTok) ?>" value="<?= htmlspecialchars($entTok) ?>">
                                    <label class="form-check-label" for="wiz-applies-<?= htmlspecialchars($entTok) ?>"><?= htmlspecialchars(ucfirst($entTok)) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="wiz-allow-multiple" checked>
                            <label class="form-check-label" for="wiz-allow-multiple">Allow multiple per item</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="wiz-active" checked>
                            <label class="form-check-label" for="wiz-active">Active</label>
                        </div>
                    </section>

                    <section data-wiz-step data-wiz-label="Review" hidden>
                        <h3 data-wiz-heading class="h6 mb-3">4. Review &amp; save</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <dl class="row small mb-0" id="wiz-review-summary"></dl>
                    </section>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-wiz-back hidden>Back</button>
                    <button type="button" class="btn btn-amber" data-wiz-next>Next</button>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
    /* #1992 — guided "Add provider" wizard wiring, built on the shared
       stepper (js/modules/admin-wizard.js). Domain logic only — the
       framework itself knows nothing about link types (module doc-block).
       ES modules are established on /manage/* already (head-libs.php boots
       error-monitor.js the same way) and /manage/* sends no script-src, so
       there's no CSP obstacle to a plain inline module here. */
    import { createWizard } from '/js/modules/admin-wizard.js?v=<?= htmlspecialchars($adminWizardVer, ENT_QUOTES) ?>';

    (function () {
        'use strict';
        const modalEl = document.getElementById('linkTypeWizardModal');
        if (!modalEl) { return; }

        const csrfToken = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const nameInput      = document.getElementById('wiz-name');
        const slugInput      = document.getElementById('wiz-slug');
        const categorySelect = document.getElementById('wiz-category');
        const iconInput      = document.getElementById('wiz-icon');
        const iconPreview    = document.getElementById('wiz-icon-preview');
        const sampleUrlInput = document.getElementById('wiz-sample-url');
        const suggestBtn     = modalEl.querySelector('[data-wiz-suggest]');
        const addPatternBtn  = modalEl.querySelector('[data-wiz-add-pattern]');
        const patternRowsEl  = document.getElementById('wiz-pattern-rows');
        const reviewSummary  = document.getElementById('wiz-review-summary');
        const nextBtn        = modalEl.querySelector('[data-wiz-next]');
        const allowMultipleEl = document.getElementById('wiz-allow-multiple');
        const activeEl        = document.getElementById('wiz-active');

        let slugManuallyEdited = false;
        let patternSeq = 0;

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function slugify(name) {
            return String(name || '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 60);
        }

        nameInput.addEventListener('input', function () {
            if (!slugManuallyEdited) { slugInput.value = slugify(nameInput.value); }
        });
        slugInput.addEventListener('input', function () { slugManuallyEdited = true; });

        iconInput.addEventListener('input', function () {
            const v = (iconInput.value || '').trim();
            iconPreview.className = v ? v : 'bi';
        });

        /* ---- pattern rows ------------------------------------------------- */
        function addPatternRow(values) {
            values = values || {};
            patternSeq += 1;
            const row = document.createElement('div');
            row.className = 'card bg-secondary-subtle border-secondary';
            row.setAttribute('data-wiz-pattern-row', String(patternSeq));
            row.innerHTML =
              '<div class="card-body py-2">' +
                '<div class="row g-2 align-items-center">' +
                  '<div class="col-md-4"><label class="form-label small mb-0" aria-hidden="true">Host</label>' +
                    '<input type="text" class="form-control form-control-sm" data-wiz-pattern-host aria-label="Pattern host" placeholder="wikipedia.org" maxlength="255"></div>' +
                  '<div class="col-md-3"><label class="form-label small mb-0" aria-hidden="true">Path prefix</label>' +
                    '<input type="text" class="form-control form-control-sm" data-wiz-pattern-path aria-label="Pattern path prefix" placeholder="/work/ (optional)" maxlength="255"></div>' +
                  '<div class="col-md-2"><label class="form-label small mb-0" aria-hidden="true">Priority</label>' +
                    '<input type="number" class="form-control form-control-sm" data-wiz-pattern-priority aria-label="Pattern priority" value="100" min="0" max="65535"></div>' +
                  '<div class="col-md-3 d-flex flex-column gap-1 mt-3">' +
                    '<div class="form-check small"><input class="form-check-input" type="checkbox" data-wiz-pattern-subdomain aria-label="Match sub-domains" checked><label class="form-check-label" aria-hidden="true">Match sub-domains</label></div>' +
                  '</div>' +
                '</div>' +
                '<div class="row g-2 mt-1 align-items-center">' +
                  '<div class="col-md-8"><span class="small" data-wiz-pattern-status role="status">Untested</span></div>' +
                  '<div class="col-md-4 text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-wiz-pattern-remove aria-label="Remove pattern row ' + patternSeq + '">' +
                    '<i aria-hidden="true" class="bi bi-x-lg"></i> Remove</button></div>' +
                '</div>' +
              '</div>';
            row.querySelector('[data-wiz-pattern-host]').value = values.host || '';
            row.querySelector('[data-wiz-pattern-path]').value = values.path || '';
            if (values.priority != null) { row.querySelector('[data-wiz-pattern-priority]').value = values.priority; }
            row.querySelector('[data-wiz-pattern-subdomain]').checked = values.matchSubdomains !== false;
            row.querySelector('[data-wiz-pattern-remove]').addEventListener('click', function () {
                row.remove();
                updateReview();
            });
            const hostInput = row.querySelector('[data-wiz-pattern-host]');
            hostInput.addEventListener('change', function () { testPatternRow(row); updateReview(); });
            patternRowsEl.appendChild(row);
            return row;
        }

        if (addPatternBtn) {
            addPatternBtn.addEventListener('click', function () { addPatternRow({}); });
        }

        function readPatternRows() {
            return Array.from(patternRowsEl.querySelectorAll('[data-wiz-pattern-row]')).map(function (row) {
                return {
                    host: (row.querySelector('[data-wiz-pattern-host]').value || '').trim(),
                    path: (row.querySelector('[data-wiz-pattern-path]').value || '').trim(),
                    matchSubdomains: row.querySelector('[data-wiz-pattern-subdomain]').checked,
                    priority: parseInt(row.querySelector('[data-wiz-pattern-priority]').value, 10) || 100,
                };
            }).filter(function (p) { return p.host !== ''; });
        }

        /* ---- suggestion (host-anchored, never an auto-generated regex) --- */
        function suggestFromUrl(url) {
            let u;
            try { u = new URL(url); } catch (e) { return null; }
            let host = u.hostname.toLowerCase();
            if (host.indexOf('www.') === 0) { host = host.slice(4); }
            if (!host) { return null; }
            return { host: host, path: '', matchSubdomains: true, priority: 100 };
        }

        if (suggestBtn) {
            suggestBtn.addEventListener('click', function () {
                const suggestion = suggestFromUrl((sampleUrlInput.value || '').trim());
                if (!suggestion) { return; }
                const row = addPatternRow(suggestion);
                testPatternRow(row);
                updateReview();
            });
        }

        /* ---- live test — via the REAL detection engine, never a fork ----
           window.iHymnsLinkDetect (js/modules/external-link-detect.js, loaded
           globally by head-libs.php) is the SAME module the public site
           uses to auto-select a provider from a pasted URL. We temporarily
           append a draft entry to window._iHymnsLinkTypes, ask the real
           engine to detect the sample URL, then restore the original list —
           in a finally, so a throw mid-detect can never leave the page
           running against the mutated draft list. */
        function testPatternRow(row) {
            const statusEl = row.querySelector('[data-wiz-pattern-status]');
            const url = (sampleUrlInput.value || '').trim();
            const host = row.querySelector('[data-wiz-pattern-host]').value.trim();
            if (!url || !host || !window.iHymnsLinkDetect) {
                statusEl.textContent = 'Untested';
                statusEl.className = 'small text-muted';
                return;
            }
            const draftSlug = '__wizard-draft__';
            const base = Array.isArray(window._iHymnsLinkTypes) ? window._iHymnsLinkTypes : [];
            const draftEntry = {
                id: 0,
                slug: draftSlug,
                name: (nameInput.value || 'This provider').trim(),
                patterns: [{
                    host: host,
                    pathPrefix: (row.querySelector('[data-wiz-pattern-path]').value || '').trim() || null,
                    matchSubdomains: row.querySelector('[data-wiz-pattern-subdomain]').checked,
                    priority: parseInt(row.querySelector('[data-wiz-pattern-priority]').value, 10) || 100,
                }],
            };
            let result;
            try {
                window._iHymnsLinkTypes = base.concat([draftEntry]);
                window.iHymnsLinkDetect._resetDbRulesCache();
                result = window.iHymnsLinkDetect.detectFromUrl(url);
            } finally {
                window._iHymnsLinkTypes = base;
                window.iHymnsLinkDetect._resetDbRulesCache();
            }
            if (result === draftSlug) {
                statusEl.textContent = 'Matches this sample — this pattern will win.';
                statusEl.className = 'small text-success-emphasis';
            } else if (result) {
                const collision = base.find(function (t) { return t.slug === result; });
                const collisionName = collision ? collision.name : result;
                statusEl.textContent = 'Also matched by "' + collisionName + '" — try a lower priority number to win.';
                statusEl.className = 'small text-warning-emphasis';
            } else {
                statusEl.textContent = 'No match for the sample URL — check the host.';
                statusEl.className = 'small text-danger-emphasis';
            }
        }

        sampleUrlInput.addEventListener('change', function () {
            Array.from(patternRowsEl.querySelectorAll('[data-wiz-pattern-row]')).forEach(testPatternRow);
        });

        /* ---- review --------------------------------------------------- */
        function appliesToChecked() {
            return Array.from(modalEl.querySelectorAll('[id^="wiz-applies-"]:checked')).map(function (el) { return el.value; });
        }

        function updateReview() {
            if (!reviewSummary) { return; }
            const applies = appliesToChecked();
            const categoryLabel = categorySelect.selectedOptions[0] ? categorySelect.selectedOptions[0].textContent : '';
            reviewSummary.innerHTML =
                '<dt class="col-sm-4">Name</dt><dd class="col-sm-8">' + escapeHtml(nameInput.value) + '</dd>' +
                '<dt class="col-sm-4">Slug</dt><dd class="col-sm-8"><code>' + escapeHtml(slugInput.value) + '</code></dd>' +
                '<dt class="col-sm-4">Category</dt><dd class="col-sm-8">' + escapeHtml(categoryLabel) + '</dd>' +
                '<dt class="col-sm-4">Applies to</dt><dd class="col-sm-8">' +
                    (applies.length ? escapeHtml(applies.join(', ')) : '<span class="text-danger">none picked</span>') + '</dd>' +
                '<dt class="col-sm-4">Patterns</dt><dd class="col-sm-8">' + readPatternRows().length + '</dd>';
        }

        function showStepError(index, message) {
            const panes = modalEl.querySelectorAll('[data-wiz-step]');
            const pane = panes[index];
            if (!pane) { return; }
            const alertEl = pane.querySelector('[data-wiz-alert]');
            if (alertEl) {
                alertEl.hidden = false;
                alertEl.textContent = message;
                alertEl.focus();
            }
        }

        /* ---- the wizard itself ----------------------------------------- */
        const wizard = createWizard(modalEl, {
            host: 'bootstrap-modal',
            validateStep: function (index) {
                if (index === 0) {
                    if (!nameInput.value.trim()) {
                        return { ok: false, message: 'Name is required.', focus: nameInput };
                    }
                    const slug = slugInput.value.trim() || slugify(nameInput.value);
                    if (!/^[a-z0-9-]{1,60}$/.test(slug)) {
                        return { ok: false, message: 'Slug must be lowercase letters, digits or hyphens.', focus: slugInput };
                    }
                    return true;
                }
                if (index === 1) {
                    /* Untested / red patterns WARN, they don't block — only
                       having zero patterns at all blocks (#1992 §2). */
                    if (readPatternRows().length === 0) {
                        return {
                            ok: false,
                            message: 'Add at least one pattern before continuing — paste a sample URL and use "Suggest a pattern".',
                            focus: sampleUrlInput,
                        };
                    }
                    return true;
                }
                if (index === 2) {
                    if (appliesToChecked().length === 0) {
                        return { ok: false, message: 'Pick at least one thing this provider can apply to.' };
                    }
                    updateReview();
                    return true;
                }
                return true;
            },
            onStepChange: function (from, to) {
                if (nextBtn) { nextBtn.textContent = (to === 3) ? 'Create link type' : 'Next'; }
                if (to === 3) { updateReview(); }
            },
            onFinish: save,
        });

        function collectFormBody() {
            const body = new URLSearchParams();
            body.set('action', 'wizard_create_type');
            body.set('csrf_token', csrfToken);
            body.set('name', nameInput.value.trim());
            body.set('slug', slugInput.value.trim() || slugify(nameInput.value));
            body.set('category', categorySelect.value);
            body.set('icon_class', iconInput.value.trim());
            appliesToChecked().forEach(function (v) { body.append('applies_to[]', v); });
            body.set('allow_multiple', allowMultipleEl.checked ? '1' : '');
            body.set('is_active', activeEl.checked ? '1' : '');
            readPatternRows().forEach(function (p) {
                body.append('pattern_host[]', p.host);
                body.append('pattern_path[]', p.path);
                body.append('pattern_subdomain[]', p.matchSubdomains ? '1' : '');
                body.append('pattern_priority[]', String(p.priority));
                body.append('pattern_note[]', '');
                body.append('pattern_active[]', '1');
            });
            return body;
        }

        function save() {
            if (nextBtn) { nextBtn.disabled = true; }
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: collectFormBody(),
            }).then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            }).then(function (result) {
                if (result.data && result.data.ok) {
                    window.location.reload();
                    return;
                }
                const message = (result.data && result.data.error) || 'Could not create link type.';
                if (result.status === 409) {
                    wizard.goTo(0);
                    showStepError(0, message);
                } else if (result.status === 422) {
                    wizard.goTo(2);
                    showStepError(2, message);
                } else {
                    window.alert(message);
                }
            }).catch(function () {
                window.alert('Could not reach the server. Please try again.');
            }).finally(function () {
                if (nextBtn) { nextBtn.disabled = false; }
            });
        }

        /* Reset to a clean slate every time the modal is opened again. */
        modalEl.addEventListener('hidden.bs.modal', function () {
            modalEl.querySelectorAll('[data-wiz-alert]').forEach(function (el) { el.hidden = true; el.textContent = ''; });
            nameInput.value = '';
            slugInput.value = '';
            slugManuallyEdited = false;
            iconInput.value = '';
            iconPreview.className = 'bi';
            sampleUrlInput.value = '';
            patternRowsEl.innerHTML = '';
            modalEl.querySelectorAll('[id^="wiz-applies-"]').forEach(function (el) { el.checked = false; });
            allowMultipleEl.checked = true;
            activeEl.checked = true;
            if (nextBtn) { nextBtn.textContent = 'Next'; }
            wizard.goTo(0);
        });
    })();
    </script>
    <?php endif; ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
