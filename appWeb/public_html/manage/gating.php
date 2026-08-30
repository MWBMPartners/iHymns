<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Gating hub + guided activation wizard (#1769 P6 / #1778 / #2006)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE (ELI5):
 * One page that answers "what is gated, for whom, and is it safe to turn on?"
 * without spelunking the five separate gating pages, AND — as of #2006 — a
 * guided, step-by-step wizard that walks an administrator through checking
 * it really is safe, then flips the switch for them with the server doing
 * every safety check, and hands them a one-click way to undo it.
 *
 * WHY THE FLIP IS STILL A DELIBERATE, PRECONDITION-CHECKED ACT (unchanged
 * from before #2006 — only WHO drives it changed):
 * The whole #1769 program (P0–P5) ships DORMANT and verified byte-identical
 * while `content_gating_enabled='0'` on all three shared-DB docroots. Turning
 * enforcement ON is the ONE action in the program that is NOT a no-op — it
 * changes what every reader emits at once — so it stays a deliberate human
 * act, gated on typed confirmation and server-side preconditions, NEVER
 * something a page flips as a side effect (#1778).
 *
 * ⚠️ #2006 UPDATE — this hub is NO LONGER read-only. Before #2006 this
 * doc-block (and the on-page copy below it) said the switch lives ONLY on
 * `/manage/configuration` and this page "never does it for you". That is
 * now the STALE half of the story (rule #26's "never leave a now-false
 * claim" lesson) — the guided wizard on THIS page is the RECOMMENDED way to
 * flip the switch, because it is the only door that runs the precondition
 * checklist (schema readiness, a live CCLI-holder census, the typed
 * `ENFORCE` confirmation) before writing. The raw switch on
 * `/manage/configuration` still exists and still works byte-identically —
 * it is the emergency off-switch and the no-wizard escape hatch — but BOTH
 * doors now write through the SAME shared core
 * (`includes/maintenance.php`'s `setAppSetting()` — already the shared
 * write path `manage/notifications.php` used for its VAPID key, #1671 F6 —
 * called directly by `configuration.php`'s delegate closure and via
 * `includes/gating_wizard.php`'s `gatingWizardSetFlag()` here), so there is
 * still exactly ONE place the write itself happens — never a duplicated,
 * drifting control (rule #35).
 *
 * SAFETY: gated on `manage_configuration` — the SAME entitlement its nav entry
 * advertises (#1587) and the same gate the raw switch itself uses. The
 * wizard's optional row-seeding step carries a FINER, server-enforced gate
 * on top (`manage_content_restrictions`) because it writes
 * `tblContentRestrictions`, a different entitlement's table. Every DB read
 * is existence-probed + wrapped so a pre-migration install degrades to a
 * themed "not ready" row, never a STRICT-mode fatal (rule #9). This wizard
 * NEVER edits the enforcement chain itself (content_gating.php,
 * access_context.php, access_resolver.php, ccli_validator.php,
 * licences.php, content_access.php, licence_registry.php,
 * access_tier_validation.php) — rule #28's A/B/C contract is untouched by
 * construction; the wizard's dry-run drives the documented PURE seam
 * (`accessViewerAssemble()`/`accessApplySong()`) the same way
 * `test-gating-equivalence.php` already does.
 *
 * WEB-ONLY BY DESIGN: all three of this page's writes
 * (`wizard_seed_restrictions`, `wizard_flip_gating`, `wizard_rollback_gating`)
 * are GATING SWITCHES — flipping enforcement, or seeding the rules it
 * enforces, from a network API would defeat the dormancy discipline the
 * whole #1769 program is built on (rule #28A), exactly like
 * `feature-gating.php`'s own switches and `configuration.php`'s
 * `save_feature_gating` row. `tests/php/test-manage-action-api-coverage.php`
 * maps all three `web_only:gating-switches`. The wizard's preview/test reads
 * (`wizard_status`, `wizard_song_test`) are GET-dispatched pure reads, so
 * they fall outside that guard's scope entirely — the SAME precedent
 * `manage/gating-noop-verify.php`'s GET-only actions already set.
 *
 * @see includes/gating_wizard.php          the wizard's shared core (census, precondition evaluator, planner, dry-run, flip)
 * @see includes/restriction_admin.php      the ONE content-restriction validate/create/delete core
 * @see includes/maintenance.php            setAppSetting() — the ONE tblAppSettings write core
 * @see includes/content_gating.php         contentGatingApply (dormant until the flip)
 * @see manage/configuration.php            the raw master-switch control — still works, same write core
 * @see manage/gating-noop-verify.php       the OFF-baseline differential harness the wizard links to
 * @see js/modules/admin-wizard.js          the shared stepper this wizard is built on (#1992)
 * @see tests/php/test-gating-wizard.php    the standing guard over this page + its shared core
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php'; // getAppSetting()
/* #2006 — the wizard's shared core (census + pure precondition evaluator +
   row-seeding planner + dry-run simulator + the ONE flip function). Pulls
   in restriction_admin.php and maintenance.php (setAppSetting()) itself. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'gating_wizard.php';

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_configuration', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied. The manage_configuration entitlement is required.');
}
$activePage = 'gating';

$db   = getDbMysqli();
$csrf = csrfToken();

/* Finer, server-enforced gate for the row-seeding step ONLY (§ D of the
   plan / rule #1587's "finer gate" shape, mirroring the organisations
   wizard's own licence-editing gate). The page-top gate above stays
   manage_configuration; this is checked AGAIN, server-side, inside the
   wizard_seed_restrictions handler below — never trust the client's own
   "is this button disabled" state. */
$canEditRestrictions = userHasEntitlement('manage_content_restrictions', $currentUser['role'] ?? null);

/* =========================================================================
 * #2006 — Wizard JSON dispatch. Placed BEFORE any HTML output, immediately
 * after the entitlement gate, exactly as external-link-types.php's
 * `wizard_create_type` branch and the organisations wizard do.
 *
 * GET reads dispatch off `$_GET['action']` ONLY (never `$_POST`/`$_REQUEST`)
 * — this is what keeps them correctly OUT of
 * tests/php/test-manage-action-api-coverage.php's scope (that guard only
 * ever enumerates `$_POST`/`$_REQUEST`-sourced actions — the documented
 * gating-noop-verify.php precedent). They are pure reads: no write of any
 * kind happens on this branch (tests/php/test-gating-wizard.php asserts
 * this by scanning the span for INSERT/UPDATE/DELETE/write-function
 * tokens).
 *
 * POST writes dispatch off `$_POST['action']`, each: sets the JSON header,
 * calls `validateCsrfRequest()` (same-origin, rule #29 — this page's
 * classic HTML render below has no form POST of its own, so there is no
 * competing CSRF convention to keep in sync with), runs inside its own
 * try/catch, and always `exit`s.
 * ========================================================================= */

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'GET' && isset($_GET['action'])) {
    $getAction = (string)$_GET['action'];

    if ($getAction === 'wizard_status') {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $schemaReady   = gatingWizardTablesExist($db, GATING_WIZARD_SCHEMA_TABLES);
            $tierCapsReady = gatingWizardColumnExists($db, 'tblAccessTiers', 'Capabilities');
            $stats         = gatingWizardPreviewStats($db);
            $holders       = gatingWizardLiveCcliHolders($db);

            $resp = [
                'flag'            => (getAppSetting('content_gating_enabled', '0') ?? '0') === '1',
                'featureFlag'     => (getAppSetting('feature_gating_rules_enabled', '0') ?? '0') === '1',
                'schemaReady'     => $schemaReady,
                'tierCapsReady'   => $tierCapsReady,
                'stats'           => $stats,
                'holders'         => $holders,
                'requireLicenceRowCount' => (int)($stats['restrictions']['byType']['require_licence'] ?? 0),
            ];

            /* Optional "how many rows would this plan create?" preview,
               requested by passing seed_scope (+ songbooks[] for scope
               'songbooks'). Never writes anything — gatingWizardPlanSeed()
               is a SELECT-only planner. */
            $seedScope = (string)($_GET['seed_scope'] ?? '');
            if (in_array($seedScope, ['songbooks', 'all'], true)) {
                $seedBooks = $_GET['songbooks'] ?? [];
                if (!is_array($seedBooks)) {
                    $seedBooks = [];
                }
                $plan = gatingWizardPlanSeed($db, $seedScope, array_map('strval', $seedBooks));
                $resp['plannedSeedCount'] = count($plan);
            }

            echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            error_log('[gating.php wizard_status] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not load the current status.']);
        }
        exit;
    }

    if ($getAction === 'wizard_song_test') {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $songId = trim((string)($_GET['song'] ?? ''));
            /* SongId shape (rule #27): letters, digits, and a dash — e.g.
               MP-1008. Rejecting anything else BEFORE it ever reaches
               getSongById() keeps this endpoint from being usable to probe
               arbitrary strings. */
            if (!preg_match('/^[A-Za-z0-9]{1,10}-\d+$/', $songId)) {
                http_response_code(422);
                echo json_encode(['error' => 'That doesn\'t look like a valid song id (expected e.g. "MP-1008").']);
                exit;
            }

            $pickedUserId = null;
            if (isset($_GET['user']) && $_GET['user'] !== '') {
                $pickedUserId = (int)$_GET['user'];
                if ($pickedUserId <= 0) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Invalid user id.']);
                    exit;
                }
            }

            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
            $songData = new SongData();
            $song = $songData->getSongById($songId);
            if ($song === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Song not found.']);
                exit;
            }

            /* Entity axis — the REAL evaluator, always-evaluating (its own
               doc-block documents this), read-only here. */
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'content_access.php';
            $entity = checkContentAccess('song', (string)($song['id'] ?? $songId), null, 'PWA');

            /* Tier axis — one simulated viewer per named tier, through the
               PURE dry-run seam (works whether the flag is on or off — a
               true dry-run). Never leaks lyric text: gatingWizardSummarisePayload()
               reduces every variant to booleans/counts/kind-lists only. */
            $variantDefs = [
                'public'  => ['tier' => 'public',  'hasCcli' => false, 'licences' => []],
                'free'    => ['tier' => 'free',    'hasCcli' => false, 'licences' => []],
                'ccli'    => ['tier' => 'ccli',    'hasCcli' => true,  'licences' => ['ccli']],
                'premium' => ['tier' => 'premium', 'hasCcli' => false, 'licences' => []],
            ];
            $variants = [];
            foreach ($variantDefs as $key => $def) {
                $gated = gatingWizardSimulateApply($song, $def['tier'], $def['hasCcli'], $def['licences']);
                $variants[$key] = gatingWizardSummarisePayload($gated, $song);
            }

            /* The REAL anonymous result, through the REAL flag — proves the
               no-op before the flip, and matches the simulation after it. */
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'content_gating.php';
            $liveGated = contentGatingApply($song, null, 'PWA');

            $pickedUserResult = null;
            if ($pickedUserId !== null) {
                require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'licences.php';
                $pickedUserResult = ['hasValidCcli' => userHasValidCcli($pickedUserId)];
            }

            echo json_encode([
                'songId'        => $songId,
                'title'         => (string)($song['title'] ?? ''),
                'live'          => ((getAppSetting('content_gating_enabled', '0') ?? '0') === '1'),
                'entity'        => $entity,
                'variants'      => $variants,
                'liveAnonymous' => gatingWizardSummarisePayload($liveGated, $song),
                'pickedUser'    => $pickedUserResult,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            error_log('[gating.php wizard_song_test] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not run the test.']);
        }
        exit;
    }
}

if ($requestMethod === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');

    if (in_array($postAction, ['wizard_seed_restrictions', 'wizard_flip_gating', 'wizard_rollback_gating'], true)) {
        header('Content-Type: application/json; charset=UTF-8');
        if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? null))) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF check failed — please retry.']);
            exit;
        }

        try {
            switch ($postAction) {

                case 'wizard_seed_restrictions': {
                    /* Finer gate — see the $canEditRestrictions comment
                       above. The button is also disabled client-side when
                       this is false, but the server is the real gate. */
                    if (!$canEditRestrictions) {
                        http_response_code(403);
                        echo json_encode(['error' => 'The manage_content_restrictions entitlement is required to add rules.']);
                        break;
                    }

                    $scope = (string)($_POST['scope'] ?? 'none');
                    if (!in_array($scope, ['songbooks', 'all'], true)) {
                        http_response_code(422);
                        echo json_encode(['error' => 'Invalid seeding scope — expected "songbooks" or "all".']);
                        break;
                    }
                    $songbooks = $_POST['songbooks'] ?? [];
                    if (!is_array($songbooks)) {
                        $songbooks = [];
                    }
                    $songbooks = array_map('strval', $songbooks);
                    if ($scope === 'songbooks' && $songbooks === []) {
                        http_response_code(422);
                        echo json_encode(['error' => 'Pick at least one songbook.']);
                        break;
                    }

                    $plan = gatingWizardPlanSeed($db, $scope, $songbooks);
                    $result = gatingWizardSeedRestrictions($db, $plan, $scope, (int)($currentUser['id'] ?? 0) ?: null);

                    /* ONE log row for the whole batch, not one per row —
                       thousands of per-row log rows would be pure noise. */
                    if (function_exists('logActivity')) {
                        logActivity('admin.gating_wizard.seed', 'content_restriction', '', [
                            'scope'   => $scope,
                            'created' => count($result['created']),
                            'skipped' => $result['skipped'],
                        ]);
                    }

                    $freshStats = gatingWizardPreviewStats($db);
                    echo json_encode([
                        'ok'               => true,
                        'created'          => count($result['created']),
                        'skipped'          => $result['skipped'],
                        'totalRequireRows' => (int)($freshStats['restrictions']['byType']['require_licence'] ?? 0),
                    ]);
                    break;
                }

                case 'wizard_flip_gating': {
                    $confirm     = (string)($_POST['confirm'] ?? '');
                    $ackWarnings = !empty($_POST['ack_warnings']);

                    $schemaReady   = gatingWizardTablesExist($db, GATING_WIZARD_SCHEMA_TABLES);
                    $tierCapsReady = gatingWizardColumnExists($db, 'tblAccessTiers', 'Capabilities');
                    $holders       = gatingWizardLiveCcliHolders($db);
                    $liveCcliHolderCount = count($holders['orgs']) + $holders['personalCount'];
                    $stats = gatingWizardPreviewStats($db);
                    $requireLicenceRowCount = (int)($stats['restrictions']['byType']['require_licence'] ?? 0);

                    /* THE precondition check — see includes/gating_wizard.php
                       for the full truth table (owner-approved warn-but-allow
                       shape). This call MUST happen before gatingWizardSetFlag()
                       below — tests/php/test-gating-wizard.php asserts the
                       ordering structurally. */
                    $eval = gatingWizardEvaluatePreconditions(
                        $schemaReady,
                        $tierCapsReady,
                        $liveCcliHolderCount,
                        $requireLicenceRowCount,
                        $ackWarnings,
                        $confirm
                    );

                    if (!$eval['ok']) {
                        http_response_code(409);
                        echo json_encode([
                            'blockerCodes' => $eval['blockers'],
                            'blockers'     => array_map('gatingWizardBlockerMessage', $eval['blockers']),
                            'warningCodes' => $eval['warnings'],
                            'warnings'     => array_map('gatingWizardWarningMessage', $eval['warnings']),
                        ]);
                        break;
                    }

                    gatingWizardSetFlag($db, true);

                    if (function_exists('logActivity')) {
                        logActivity('app_setting.update', 'app_setting', 'content_gating_enabled', [
                            'via'                     => 'gating-wizard',
                            'content_gating_enabled'  => true,
                            'liveCcliHolderCount'     => $liveCcliHolderCount,
                            'requireLicenceRowCount'  => $requireLicenceRowCount,
                            'warningsAcknowledged'    => $ackWarnings,
                        ], 'success');
                    }

                    /* Rule #35 read-back — never trust the value we just
                       WROTE; ask the setting back so the client renders
                       exactly what the server now believes. */
                    $flagNow = (string)(getAppSetting('content_gating_enabled', '0') ?? '0');
                    echo json_encode(['ok' => true, 'flag' => $flagNow]);
                    break;
                }

                case 'wizard_rollback_gating': {
                    /* NO preconditions, ever — rollback must always work,
                       from any state, regardless of entitlement drift or a
                       half-finished wizard run. This span deliberately never
                       calls gatingWizardEvaluatePreconditions(). */
                    $removeSeeded = !empty($_POST['remove_seeded']);

                    gatingWizardSetFlag($db, false);

                    if (function_exists('logActivity')) {
                        logActivity('app_setting.update', 'app_setting', 'content_gating_enabled', [
                            'via'                    => 'gating-wizard-rollback',
                            'content_gating_enabled' => false,
                        ], 'success');
                    }

                    $removedRows = 0;
                    if ($removeSeeded && $canEditRestrictions) {
                        $sentinel = gatingWizardReadSeedSentinel($db);
                        $ids = is_array($sentinel['ids'] ?? null) ? $sentinel['ids'] : [];
                        foreach ($ids as $rawId) {
                            $id = (int)$rawId;
                            if ($id > 0) {
                                restrictionAdminDelete($db, $id);
                                $removedRows++;
                            }
                        }
                        gatingWizardClearSeedSentinel($db);
                        if ($removedRows > 0 && function_exists('logActivity')) {
                            logActivity('admin.gating_wizard.unseed', 'content_restriction', '', [
                                'removed' => $removedRows,
                            ]);
                        }
                    }

                    $flagNow = (string)(getAppSetting('content_gating_enabled', '0') ?? '0');
                    echo json_encode(['ok' => true, 'flag' => $flagNow, 'removedRows' => $removedRows]);
                    break;
                }
            }
        } catch (\Throwable $e) {
            error_log('[gating.php wizard POST] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not complete the request.']);
        }
        exit;
    }
}

/* =========================================================================
 * The rest of this file is the classic HTML render — unchanged in spirit
 * from the pre-#2006 read-only hub, plus the wizard trigger, the always-
 * reachable rollback button, and the modal itself.
 * ========================================================================= */

/* ---- current master-switch state (read-only here) --------------------- */
$gatingEnabled  = ((string)(getAppSetting('content_gating_enabled', '0') ?? '0')) === '1';
$featureEnabled = ((string)(getAppSetting('feature_gating_rules_enabled', '0') ?? '0')) === '1';

/* ---- readiness probes (all wrapped) ----------------------------------- */
$schemaReady = false;
$tierCapsReady = false;
try {
    $schemaReady = gatingWizardTablesExist($db, GATING_WIZARD_SCHEMA_TABLES);
    /* The #1769 P1 additive JSON cap column — proof the one-pass tier-caps
       batch landed (rule #28: new caps live here, not new columns). */
    $tierCapsReady = gatingWizardColumnExists($db, 'tblAccessTiers', 'Capabilities');
} catch (\Throwable $_e) {
    /* $schemaReady stays false → the checklist shows the DB row red. */
}

/* The family pages this hub gathers — label / route / entitlement / icon.
   Kept as data so the render is a single loop (no five copy-pasted cards). */
$gatingFamily = [
    ['Content Restrictions', '/manage/restrictions',   'manage_content_restrictions', 'bi-shield-lock',  'Per-entity restriction rows (require_licence:ccli, …) — nothing is gated without one.'],
    ['Access Tiers',         '/manage/tiers',          'manage_access_tiers',         'bi-diagram-3',    'The capability registry (TIER_CAPS): which tier may view lyrics, play audio, download, offline-save.'],
    ['Licence Types',        '/manage/licence-types',  'manage_licence_types',        'bi-patch-check',  'The licence vocabulary (ccli, mrl, …) an organisation can hold.'],
    ['Feature Gating',       '/manage/feature-gating', 'manage_feature_gating',       'bi-toggles',      'Admin-configurable additional capabilities (tblGatingCapabilities).'],
    ['Entitlements',         '/manage/entitlements',   'manage_entitlements',         'bi-key',          'Which role may perform which admin action — the operator-side permission map.'],
];

/* The six deferred owner decisions #1778 lists as flip preconditions. These are
   JUDGEMENT calls the code cannot make, so they render as owner-attestation rows
   linking to their issues — never auto-ticked. Also reused by the wizard's
   step-5 checklist (the SAME array — one source of truth). */
$gatingDecisions = [
    [1772, 'mrl / custom-tier licence conferral'],
    [1773, 'RequiresCcli — registry cap vs tier-name'],
    [1774, 'sheet / midi presence-OR divergence'],
    [1775, 'audio-media.php serve-time tier gate'],
    [1776, 'Cache-API clear on login'],
    [1777, 'presence token into checkBulkAccess'],
];

/* Songbooks for the wizard's step-3 "seed for these songbooks" multi-select
   (small list — server-rendered, the restrictions.php:284 pattern).
   @disabled-visible: admin surface (#1765) — disabled songbooks stay fully
   visible/editable in /manage (owner decision, mirrors restrictions.php's
   own identical picker); a curator must still be able to pick a disabled
   book when deciding which songbooks should require a CCLI licence. */
$picker_songbooks = [];
try {
    $stmt = $db->prepare('SELECT Abbreviation, Name, SongCount FROM tblSongbooks ORDER BY Name ASC');
    $stmt->execute();
    $picker_songbooks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $_e) { /* empty list is a safe default */ }

/* Cache-busted import path for the shared stepper module (#1992) — same
   filemtime-as-version-query pattern every other admin wizard uses. */
$_adminWizardPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'admin-wizard.js';
$adminWizardVer   = is_file($_adminWizardPath) ? (string)filemtime($_adminWizardPath) : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Access — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><i aria-hidden="true" class="bi bi-shield-shaded me-2"></i>Content Access</h1>
                <p class="text-secondary small mb-0">
                    One place to see everything that controls who can view and download your songs, and to switch
                    content locking on safely. While the master switch is <strong>off</strong>, everything here is
                    inactive and people see songs exactly as they do now. The guided wizard below is the recommended
                    way to turn locking on — it checks things over first and gives you a one-click way to undo it.
                </p>
            </div>
            <button type="button" class="btn btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#gatingWizardModal">
                <i aria-hidden="true" class="bi bi-magic me-1"></i>Guided setup…
            </button>
        </div>

        <!-- Master-switch state (read-only card; the wizard above and the raw
             switch on Configuration are the two write doors — both go
             through the SAME shared core, includes/maintenance.php's
             setAppSetting()). -->
        <div class="card-admin p-3 mb-4">
            <div class="d-flex align-items-center flex-wrap gap-3">
                <div>
                    <div class="small text-secondary">Content gating (master switch)</div>
                    <span class="badge fs-6 <?= $gatingEnabled ? 'bg-warning text-dark' : 'bg-secondary' ?>">
                        <?= $gatingEnabled ? 'ENFORCING (ON)' : 'Dormant (OFF)' ?>
                    </span>
                </div>
                <div>
                    <div class="small text-secondary">Feature-gating rules</div>
                    <span class="badge fs-6 <?= $featureEnabled ? 'bg-info text-dark' : 'bg-secondary' ?>">
                        <?= $featureEnabled ? 'ON' : 'Off' ?>
                    </span>
                </div>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <?php if ($gatingEnabled): ?>
                        <!-- #2006 — the unconditional one-click rollback, reachable
                             OUTSIDE the modal so it works even if the wizard itself
                             is misbehaving. Posts straight to this page's own
                             wizard_rollback_gating action; NO precondition check on
                             the server side (see the handler above). -->
                        <form method="POST" onsubmit="return confirm('Switch content locking off? Visitors will see everything exactly as they did before it was turned on.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="wizard_rollback_gating">
                            <button type="submit" class="btn btn-outline-warning btn-sm">
                                <i aria-hidden="true" class="bi bi-arrow-counterclockwise me-1"></i>Switch enforcement off (rollback)
                            </button>
                        </form>
                    <?php endif; ?>
                    <a href="/manage/configuration#gating" class="btn btn-outline-secondary btn-sm text-nowrap">
                        <i aria-hidden="true" class="bi bi-sliders me-1"></i>Raw switch (Configuration)
                    </a>
                </div>
            </div>
            <?php if (!$gatingEnabled): ?>
                <p class="small text-body-secondary mb-0 mt-2">
                    The switch is off, so everything below is inert. Use the guided setup above, or work through the
                    readiness checklist below before flipping the raw switch on Configuration.
                </p>
            <?php else: ?>
                <p class="small text-warning mb-0 mt-2">
                    <i aria-hidden="true" class="bi bi-exclamation-triangle me-1"></i>Enforcement is LIVE — restriction rules,
                    tier caps and licence conferral are now applied to every reader.
                </p>
            <?php endif; ?>
        </div>

        <!-- Readiness checklist -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">Activation readiness</h2>
            <ul class="list-group list-group-flush">
                <li class="list-group-item bg-transparent d-flex align-items-start gap-2">
                    <i aria-hidden="true" class="bi <?= $schemaReady ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
                    <div>
                        <strong>Gating schema present</strong>
                        <div class="small text-secondary">The five family tables exist on this environment
                            (<?= $schemaReady ? 'yes' : 'NO — run the #1769 P1 migration batch on /manage/setup-database' ?>).</div>
                    </div>
                </li>
                <li class="list-group-item bg-transparent d-flex align-items-start gap-2">
                    <i aria-hidden="true" class="bi <?= $tierCapsReady ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
                    <div>
                        <strong>Tier-capabilities registry column</strong>
                        <div class="small text-secondary"><code>tblAccessTiers.Capabilities</code> (the JSON cap store, rule #28)
                            <?= $tierCapsReady ? 'is present' : 'is MISSING — run its migration card' ?>.</div>
                    </div>
                </li>
                <li class="list-group-item bg-transparent d-flex align-items-start gap-2">
                    <i aria-hidden="true" class="bi bi-shield-check text-info"></i>
                    <div>
                        <strong>Byte-identical no-op proven on this env</strong>
                        <div class="small text-secondary">Capture the OFF baseline and re-run Verify on
                            <a href="/manage/gating-noop-verify">Gating No-op Verify</a> —
                            every sampled song must hash identically before you flip the switch.</div>
                    </div>
                </li>
                <li class="list-group-item bg-transparent d-flex align-items-start gap-2">
                    <i aria-hidden="true" class="bi bi-person-check text-warning"></i>
                    <div>
                        <strong>Owner decisions resolved (#1772–#1777)</strong>
                        <div class="small text-secondary">Six judgement calls the code cannot make. Confirm each on its issue
                            before enforcing — otherwise the flip applies half-decided behaviour:</div>
                        <ul class="small mb-0 mt-1">
                            <?php foreach ($gatingDecisions as [$num, $desc]): ?>
                                <li><a href="https://github.com/MWBMPartners/iHymns/issues/<?= (int)$num ?>" target="_blank" rel="noopener">#<?= (int)$num ?></a> — <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </li>
            </ul>
            <p class="small text-secondary mt-3 mb-0">
                The guided wizard above walks through this same checklist and asks you to confirm each item before it
                lets you flip the switch — you don't need to work through it by hand first.
            </p>
        </div>

        <!-- The family pages -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">The gating family</h2>
            <div class="row g-3">
                <?php foreach ($gatingFamily as [$label, $route, $ent, $icon, $blurb]): ?>
                    <?php $canSee = userHasEntitlement($ent, $currentUser['role'] ?? null); ?>
                    <div class="col-md-6">
                        <?php /* a11y audit A22 (2026-08-30) — opacity-50 on the WHOLE card
                                 (title, blurb, badge included) pushed the blurb's already-muted
                                 text-secondary down near 2.1:1 contrast in light theme. These are
                                 informational cards, not disabled controls, and the "no access"
                                 badge already carries the state in plain text — dropping the
                                 opacity loses nothing a sighted user needs to tell the two states
                                 apart, and fixes the contrast for everyone. */ ?>
                        <div class="border border-secondary rounded p-2 h-100">
                            <div class="d-flex align-items-center gap-2">
                                <i aria-hidden="true" class="bi <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>
                                <?php if ($canSee): ?>
                                    <a href="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>" class="fw-semibold"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                                <?php else: ?>
                                    <span class="fw-semibold"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="badge bg-secondary ms-1">no access</span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-secondary mt-1"><?= htmlspecialchars($blurb, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <?php /* =====================================================================
             #2006 — the guided content-gating activation wizard: modal markup +
             the 7 steps + its wiring, built on the shared stepper
             (js/modules/admin-wizard.js, #1992). Every step's copy is
             plain-language on purpose (owner standing preference) — technical
             terms are explained in ordinary words the first time they appear. */ ?>
    <div class="modal fade" id="gatingWizardModal" tabindex="-1" aria-hidden="true" aria-labelledby="gatingWizardModalLabel" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" id="gatingWizardRoot">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0" id="gatingWizardModalLabel">Turn on content locking — guided setup</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div data-wiz-progress class="mb-3"></div>

                    <!-- Step 1 — Understand -->
                    <section data-wiz-step data-wiz-label="Understand">
                        <h3 data-wiz-heading class="h6 mb-3">1. What this does</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <p>
                            <strong>Content locking</strong> decides who can see copyrighted song words and use audio,
                            sheet music, MIDI files and offline saving, based on each person's membership level and
                            any licence (such as a CCLI licence) their church or account holds.
                        </p>
                        <p id="gwiz-switch-state-text">
                            <?php if ($gatingEnabled): ?>
                                The master switch is currently <strong>ON</strong> — enforcement is already live. Use
                                steps 6–7 below to test a song or to switch it back off.
                            <?php else: ?>
                                Right now the master switch is <strong>OFF</strong>, so nothing is locked and everyone
                                sees songs exactly as they do today.
                            <?php endif; ?>
                        </p>
                        <p class="mb-0">
                            Nothing in this wizard changes what visitors see until step 5, and step 5 comes with a
                            one-click undo you can use at any time afterwards — from this page, without opening the
                            wizard again.
                        </p>
                    </section>

                    <!-- Step 2 — Preview -->
                    <section data-wiz-step data-wiz-label="Preview" hidden>
                        <h3 data-wiz-heading class="h6 mb-3">2. Preview the impact</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <p class="text-secondary small">
                            This is what would change if content locking were switched on right now, with no extra
                            rules added.
                        </p>
                        <button type="button" class="btn btn-outline-info btn-sm mb-3" data-gwiz-refresh-status>
                            <i aria-hidden="true" class="bi bi-arrow-clockwise me-1"></i>Refresh
                        </button>
                        <?php /* a11y audit A8 (2026-08-30) — Refresh mutates this
                                 silently otherwise; role="status" announces the
                                 new preview text without moving focus off the
                                 button the admin just clicked. */ ?>
                        <div id="gwiz-preview-body" role="status">
                            <p class="text-muted small">Loading…</p>
                        </div>
                    </section>

                    <!-- Step 3 — Rules (optional) -->
                    <section data-wiz-step data-wiz-label="Rules" hidden>
                        <h3 data-wiz-heading class="h6 mb-3">3. Extra rules (optional)</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <p class="text-secondary small">
                            By default, copyrighted songs are handled automatically by each person's membership
                            level — you don't need to add anything here. Only add a rule below if you specifically
                            want certain songs to always require a CCLI licence, no matter what membership level the
                            visitor has.
                        </p>
                        <?php /* a11y audit A16 (2026-08-30) — three well-labelled radios
                                 with no group name: a screen-reader user landing on the
                                 SECOND or THIRD radio never hears what question they're
                                 answering, only its own option text. A real <fieldset>/
                                 <legend> is the native HTML way to name a radio group (the
                                 songbook picker just below already uses the ARIA-attribute
                                 equivalent, role="group"+aria-labelledby, for the SAME
                                 reason). The legend is visually-hidden because the step's
                                 own visible heading + intro paragraph already say what this
                                 choice is for — the legend exists for the accessibility
                                 tree, not to duplicate visible copy. */ ?>
                        <fieldset class="mb-3">
                            <legend class="visually-hidden">Extra CCLI rules — who does this apply to?</legend>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gwiz-seed-scope" id="gwiz-scope-none" value="none" checked>
                                <label class="form-check-label" for="gwiz-scope-none">
                                    <strong>Add no rules</strong> — recommended. Membership levels already handle this.
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gwiz-seed-scope" id="gwiz-scope-songbooks" value="songbooks">
                                <label class="form-check-label" for="gwiz-scope-songbooks">
                                    Require a CCLI licence for copyrighted songs in specific songbooks
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gwiz-seed-scope" id="gwiz-scope-all" value="all">
                                <label class="form-check-label" for="gwiz-scope-all">
                                    Require a CCLI licence for every copyrighted song on the site
                                </label>
                            </div>
                        </fieldset>
                        <div id="gwiz-songbook-picker" class="mb-3" hidden>
                            <label class="form-label small mb-1" id="gwiz-songbook-picker-label">Which songbooks?</label>
                            <div class="d-flex flex-wrap gap-2" role="group" aria-labelledby="gwiz-songbook-picker-label" style="max-height:180px; overflow-y:auto;">
                                <?php foreach ($picker_songbooks as $sb): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="gwiz-songbook" value="<?= htmlspecialchars($sb['Abbreviation'], ENT_QUOTES) ?>" id="gwiz-sb-<?= htmlspecialchars($sb['Abbreviation'], ENT_QUOTES) ?>">
                                        <label class="form-check-label small" for="gwiz-sb-<?= htmlspecialchars($sb['Abbreviation'], ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($sb['Name'], ENT_QUOTES) ?> (<?= htmlspecialchars($sb['Abbreviation'], ENT_QUOTES) ?>)
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <p class="small text-warning" id="gwiz-rules-consequence" hidden>
                            <i aria-hidden="true" class="bi bi-exclamation-triangle me-1"></i>
                            These rules come first: even a Premium member would need a CCLI licence for these songs.
                            Songs added to the site later will <strong>not</strong> automatically get this rule — you
                            would need to come back and add them.
                        </p>
                        <?php if (!$canEditRestrictions): ?>
                            <p class="small text-muted">You don't have permission to add these rules — ask an admin
                                with the "manage content restrictions" permission, or skip this step.</p>
                        <?php endif; ?>
                        <div class="d-flex gap-2 align-items-center">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-gwiz-preview-seed>Preview how many rows this would add</button>
                            <button type="button" class="btn btn-amber-solid btn-sm" data-gwiz-add-rules <?= $canEditRestrictions ? '' : 'disabled' ?>>Add the rules</button>
                            <?php /* a11y audit A8 — the ONLY feedback for either button above; without
                                     role="status" the result was silent. */ ?>
                            <span class="small text-muted" id="gwiz-seed-readback" role="status"></span>
                        </div>
                    </section>

                    <!-- Step 4 — Licences -->
                    <section data-wiz-step data-wiz-label="Licences" hidden>
                        <h3 data-wiz-heading class="h6 mb-3">4. Licence check</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <p class="text-secondary small">
                            A CCLI licence lets a church legally show copyrighted song words on a screen. This checks
                            whether anyone on this site currently has one on file.
                        </p>
                        <?php /* a11y audit A8 — same reasoning as gwiz-preview-body above. */ ?>
                        <div id="gwiz-licence-body" role="status">
                            <p class="text-muted small">Loading…</p>
                        </div>
                        <button type="button" class="btn btn-outline-info btn-sm mt-2" data-gwiz-refresh-status>
                            <i aria-hidden="true" class="bi bi-arrow-clockwise me-1"></i>Re-check
                        </button>
                        <a href="/manage/organisations" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm mt-2">
                            Add an organisation's licence…
                        </a>
                    </section>

                    <!-- Step 5 — Enable -->
                    <section data-wiz-step data-wiz-label="Enable" hidden>
                        <h3 data-wiz-heading class="h6 mb-3">5. Turn it on</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <div id="gwiz-enable-body">

                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="gwiz-ack-noop">
                                <label class="form-check-label small" for="gwiz-ack-noop">
                                    I have checked <a href="/manage/gating-noop-verify" target="_blank" rel="noopener">Gating No-op Verify</a>
                                    on this environment and every sampled song matched.
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="gwiz-ack-decisions">
                                <label class="form-check-label small" for="gwiz-ack-decisions">
                                    I have reviewed the owner decisions
                                    (<?php foreach ($gatingDecisions as $i => [$num, $desc]): ?><?= $i > 0 ? ', ' : '' ?><a href="https://github.com/MWBMPartners/iHymns/issues/<?= (int)$num ?>" target="_blank" rel="noopener">#<?= (int)$num ?></a><?php endforeach; ?>).
                                </label>
                            </div>
                            <div id="gwiz-dynamic-warnings"></div>

                            <div class="mb-3 mt-3">
                                <label class="form-label small" for="gwiz-confirm-text">
                                    This changes what every visitor sees, immediately. Type <code>ENFORCE</code> to confirm.
                                    You can switch it off again with one click at any time.
                                </label>
                                <input type="text" class="form-control" id="gwiz-confirm-text" autocomplete="off" placeholder="ENFORCE">
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="gwiz-skip-enable">
                                <label class="form-check-label small" for="gwiz-skip-enable">
                                    Not ready yet — let me keep browsing the wizard without turning it on
                                </label>
                            </div>

                            <button type="button" class="btn btn-danger" data-gwiz-flip>
                                <i aria-hidden="true" class="bi bi-toggle-on me-1"></i>Enable content locking
                            </button>
                        </div>
                        <div id="gwiz-enabled-readback" tabindex="-1" role="status" hidden>
                            <p class="text-success"><i aria-hidden="true" class="bi bi-check-circle-fill me-1"></i>Content locking is ON.</p>
                            <button type="button" class="btn btn-outline-warning btn-sm" data-gwiz-rollback-inline>
                                <i aria-hidden="true" class="bi bi-arrow-counterclockwise me-1"></i>Undo — switch it back off
                            </button>
                        </div>
                    </section>

                    <!-- Step 6 — Test -->
                    <section data-wiz-step data-wiz-label="Test" hidden>
                        <h3 data-wiz-heading class="h6 mb-3">6. Test a song</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <p class="text-secondary small">
                            This asks the real locking code what each kind of visitor would receive for one song. It
                            changes nothing and never shows the song's words here — only what WOULD be shown.
                        </p>
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="gwiz-song-search">Find a song</label>
                            <div class="position-relative">
                                <input type="text" class="form-control form-control-sm" id="gwiz-song-search" autocomplete="off" placeholder="Type a song title or number…">
                                <div class="list-group position-absolute w-100 shadow d-none" id="gwiz-song-results" style="z-index:1060; max-height:220px; overflow-y:auto;"></div>
                            </div>
                            <?php /* a11y audit A1 (2026-08-30) — combobox/listbox roles + arrow-key
                                     handling are applied by JS via the shared window.iHymnsComboboxA11y
                                     helper (rule #43); this visually-hidden region is the one thing that
                                     helper deliberately doesn't own (module doc-block) — a polite
                                     announcement of how many results just appeared, since the results
                                     list itself isn't the kind of content a screen reader should hear
                                     read out in full on every keystroke. */ ?>
                            <div class="visually-hidden" role="status" id="gwiz-song-results-status"></div>
                            <input type="hidden" id="gwiz-song-id">
                        </div>
                        <button type="button" class="btn btn-outline-info btn-sm mb-3" data-gwiz-run-test>Run test</button>
                        <?php /* a11y audit A8 — the results are a whole TABLE; live-
                                 announcing the entire table on every re-run would be
                                 noisy and hard to follow. Instead the JS below prepends
                                 a one-line role="status" summary sentence INSIDE this
                                 div (built in the runTestBtn handler) — see the
                                 gwiz-test-results-summary paragraph it emits. */ ?>
                        <div id="gwiz-test-results"></div>
                    </section>

                    <!-- Step 7 — Finish -->
                    <section data-wiz-step data-wiz-label="Finish" hidden>
                        <h3 data-wiz-heading class="h6 mb-3">7. Done</h3>
                        <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                        <?php /* a11y audit A8 — announces the finish/rollback summary. */ ?>
                        <div id="gwiz-finish-summary" role="status">
                            <p class="text-muted small">Nothing was changed yet — go back to step 5 whenever you're ready.</p>
                        </div>
                        <p class="small">
                            You can always come back — <a href="/manage/restrictions">Content Restrictions</a>,
                            <a href="/manage/tiers">Access Tiers</a>, and the "Switch enforcement off (rollback)"
                            button on this page (outside this window) all stay available after you close this wizard.
                        </p>
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
    /* #2006 — guided content-gating activation wizard wiring, built on the
       shared stepper (js/modules/admin-wizard.js). Domain logic only — the
       framework itself knows nothing about gating (module doc-block).
       Every write goes through this page's own JSON actions
       (wizard_seed_restrictions / wizard_flip_gating / wizard_rollback_gating);
       every read is a GET action (wizard_status / wizard_song_test). The
       SERVER re-checks every precondition on write — everything below is
       convenience, never the real gate. */
    import { createWizard } from '/js/modules/admin-wizard.js?v=<?= htmlspecialchars($adminWizardVer, ENT_QUOTES) ?>';

    (function () {
        'use strict';
        const modalEl = document.getElementById('gatingWizardModal');
        if (!modalEl) { return; }

        const csrfToken = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const canEditRestrictions = <?= $canEditRestrictions ? 'true' : 'false' ?>;
        const pageUrl = window.location.pathname;

        const STEP_UNDERSTAND = 0;
        const STEP_PREVIEW    = 1;
        const STEP_RULES      = 2;
        const STEP_LICENCES   = 3;
        const STEP_ENABLE     = 4;
        const STEP_TEST       = 5;
        const STEP_FINISH     = 6;
        const LAST_STEP       = STEP_FINISH;

        const state = { status: null, flipped: false, removedRowsAvailable: false, songId: '', songTitle: '' };

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        /* `body`/`query` carry `action` as one of their own fields (e.g.
           `{ action: 'wizard_status' }`) — never a separate parameter. Every
           call site below therefore reads `action: 'literal-name'` right at
           the point it fires, the SAME shape every other admin wizard in
           this codebase uses (organisations.php's `body.set('action', …)`,
           external-link-types.php's `params.set('action', 'wizard_create_type')`)
           — load-bearing for tests/php/test-orphan-inventory.php's
           tree-derived caller detector, which looks for `action` paired
           textually with its literal value and cannot see through an
           indirected "pass the name as a function argument" shape. */
        function postJson(body) {
            const params = new URLSearchParams(body || {});
            params.set('csrf_token', csrfToken);
            return fetch(pageUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: params,
            }).then((res) => res.json().then((data) => ({ status: res.status, data: data })).catch(() => ({ status: res.status, data: null })));
        }

        function getJson(query) {
            const params = new URLSearchParams(query || {});
            return fetch(pageUrl + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).then((res) => res.json().then((data) => ({ status: res.status, data: data })).catch(() => ({ status: res.status, data: null })));
        }

        /* ---- step 2 + 4 shared status fetch ------------------------------ */
        function refreshStatus() {
            return getJson({ action: 'wizard_status' }).then(function (result) {
                if (result.data) { state.status = result.data; }
                renderPreview();
                renderLicences();
                return result;
            });
        }

        function fmtCount(n) {
            return (n === null || n === undefined) ? 'unknown' : String(n);
        }

        function renderPreview() {
            const el = document.getElementById('gwiz-preview-body');
            if (!el) { return; }
            const s = state.status;
            if (!s) { el.innerHTML = '<p class="text-muted small">Loading…</p>'; return; }
            const songs = s.stats && s.stats.songs ? s.stats.songs : {};
            const restr = s.stats && s.stats.restrictions ? s.stats.restrictions : { byType: {} };
            let html = '';
            html += '<p class="small mb-1"><strong>' + fmtCount(songs.copyrighted) + '</strong> songs are copyrighted — '
                + 'signed-out visitors would stop seeing their words. <strong>' + fmtCount(songs.publicDomain)
                + '</strong> songs are public domain and are never affected.</p>';
            const restrictionTypeCount = Object.keys(restr.byType || {}).length;
            const requireCount = (restr.byType && restr.byType.require_licence) || 0;
            html += '<p class="small mb-1">You already have <strong>' + Object.values(restr.byType || {}).reduce((a, b) => a + b, 0)
                + '</strong> existing rule(s) across ' + restrictionTypeCount + ' kind(s), including <strong>' + requireCount
                + '</strong> that require a specific licence.</p>';
            if (!s.schemaReady || !s.tierCapsReady) {
                html += '<p class="small text-danger mb-0"><i aria-hidden="true" class="bi bi-x-circle-fill me-1"></i>'
                    + 'This site is not fully set up for content locking yet — see the readiness checklist on this page.</p>';
            } else {
                html += '<p class="small text-success mb-0"><i aria-hidden="true" class="bi bi-check-circle-fill me-1"></i>'
                    + 'This site is set up and ready for content locking.</p>';
            }
            el.innerHTML = html;
        }

        function renderLicences() {
            const el = document.getElementById('gwiz-licence-body');
            if (!el) { return; }
            const s = state.status;
            if (!s) { el.innerHTML = '<p class="text-muted small">Loading…</p>'; return; }
            const holders = s.holders || { orgs: [], personalCount: 0 };
            const total = (holders.orgs ? holders.orgs.length : 0) + (holders.personalCount || 0);
            let html = '';
            if (total > 0) {
                html += '<p class="small text-success mb-1"><i aria-hidden="true" class="bi bi-check-circle-fill me-1"></i>'
                    + total + ' licence holder(s) found.</p>';
                if (holders.orgs && holders.orgs.length) {
                    html += '<ul class="small mb-0">' + holders.orgs.map((o) => '<li>' + escapeHtml(o.name) + '</li>').join('') + '</ul>';
                }
                if (holders.personalCount) {
                    html += '<p class="small text-muted mb-0">Plus ' + holders.personalCount + ' individual member(s) with a CCLI number on file.</p>';
                }
            } else {
                html += '<p class="small text-danger mb-0"><i aria-hidden="true" class="bi bi-exclamation-triangle-fill me-1"></i>'
                    + 'No organisation or member currently holds a CCLI licence. If you lock copyrighted content behind '
                    + 'CCLI, nobody on this install can unlock it — unless you add one first.</p>';
            }
            el.innerHTML = html;
            renderDynamicWarnings();
        }

        /* ---- step 5 dynamic warning checkboxes --------------------------- */
        function renderDynamicWarnings() {
            const wrap = document.getElementById('gwiz-dynamic-warnings');
            if (!wrap) { return; }
            const s = state.status;
            /* a11y audit A23 (2026-08-30) — this function wipes + rebuilds the
               acknowledgement checkbox on EVERY status refresh (entering
               steps 2/4/5, any Refresh click), which used to silently
               UN-TICK it even though nothing the admin did changed. Read the
               PRIOR checkbox's checked state + which warning code it was
               ticked for BEFORE clearing, so a re-render for the SAME
               warning preserves the tick — but a re-render for a DIFFERENT
               warning (or no warning at all) correctly starts unticked: an
               acknowledgement of warning A must never silently carry over
               as an acknowledgement of a DIFFERENT warning B. */
            const prevInput = document.getElementById('gwiz-ack-warning');
            const prevChecked = !!(prevInput && prevInput.checked);
            const prevCode = prevInput ? prevInput.getAttribute('data-gwiz-warning-code') : null;
            wrap.innerHTML = '';
            if (!s) { return; }
            const holders = s.holders || { orgs: [], personalCount: 0 };
            const holderCount = (holders.orgs ? holders.orgs.length : 0) + (holders.personalCount || 0);
            const requireRows = s.requireLicenceRowCount || 0;
            let code = null;
            let message = '';
            if (holderCount === 0 && requireRows > 0) {
                code = 'require_rows_without_holder';
                message = 'You are about to require a CCLI licence to view copyrighted songs, but no organisation on '
                    + 'this install currently holds one — so nobody here will be able to unlock that content until a '
                    + 'licence is added.';
            } else if (holderCount === 0) {
                code = 'no_live_ccli';
                message = 'No organisation or member currently holds a CCLI licence — the ccli membership level will '
                    + 'grant nothing, and signed-out visitors will lose copyrighted lyrics with no licence route open.';
            }
            if (code) {
                const div = document.createElement('div');
                div.className = 'form-check mb-2';
                div.innerHTML = '<input class="form-check-input" type="checkbox" id="gwiz-ack-warning" data-gwiz-warning-code="' + code + '">'
                    + '<label class="form-check-label small text-warning" for="gwiz-ack-warning">' + escapeHtml(message) + '</label>';
                wrap.appendChild(div);
                if (prevChecked && prevCode === code) {
                    /* Same warning as before this refresh — restore the tick
                       rather than silently discarding it (A23). */
                    wrap.querySelector('#gwiz-ack-warning').checked = true;
                }
            }
        }

        function allEnableChecksTicked() {
            const noop = document.getElementById('gwiz-ack-noop');
            const decisions = document.getElementById('gwiz-ack-decisions');
            const warning = document.getElementById('gwiz-ack-warning');
            if (noop && !noop.checked) { return false; }
            if (decisions && !decisions.checked) { return false; }
            if (warning && !warning.checked) { return false; }
            return true;
        }

        /* ---- step 3 seeding ------------------------------------------------ */
        const scopeRadios = Array.from(modalEl.querySelectorAll('input[name="gwiz-seed-scope"]'));
        const songbookPicker = document.getElementById('gwiz-songbook-picker');
        const rulesConsequence = document.getElementById('gwiz-rules-consequence');
        function currentSeedScope() {
            const checked = scopeRadios.find((r) => r.checked);
            return checked ? checked.value : 'none';
        }
        function currentSeedSongbooks() {
            return Array.from(modalEl.querySelectorAll('input[name="gwiz-songbook"]:checked')).map((el) => el.value);
        }
        function syncScopeUi() {
            const scope = currentSeedScope();
            if (songbookPicker) { songbookPicker.hidden = (scope !== 'songbooks'); }
            if (rulesConsequence) { rulesConsequence.hidden = (scope === 'none'); }
        }
        scopeRadios.forEach((r) => r.addEventListener('change', syncScopeUi));
        syncScopeUi();

        const previewSeedBtn = modalEl.querySelector('[data-gwiz-preview-seed]');
        if (previewSeedBtn) {
            previewSeedBtn.addEventListener('click', function () {
                const scope = currentSeedScope();
                const readback = document.getElementById('gwiz-seed-readback');
                if (scope === 'none') {
                    if (readback) { readback.textContent = 'No rows would be added.'; }
                    return;
                }
                const query = { action: 'wizard_status', seed_scope: scope };
                if (scope === 'songbooks') {
                    const books = currentSeedSongbooks();
                    if (books.length === 0) {
                        if (readback) { readback.textContent = 'Pick at least one songbook first.'; }
                        return;
                    }
                    query['songbooks[]'] = books;
                }
                const params = new URLSearchParams();
                Object.keys(query).forEach((k) => {
                    if (Array.isArray(query[k])) { query[k].forEach((v) => params.append(k, v)); }
                    else { params.set(k, query[k]); }
                });
                getJson(params).then(function (result) {
                    if (result.data && typeof result.data.plannedSeedCount === 'number') {
                        if (readback) { readback.textContent = 'This would add ' + result.data.plannedSeedCount + ' rule row(s).'; }
                    }
                });
            });
        }

        const addRulesBtn = modalEl.querySelector('[data-gwiz-add-rules]');
        if (addRulesBtn) {
            addRulesBtn.addEventListener('click', function () {
                const scope = currentSeedScope();
                const readback = document.getElementById('gwiz-seed-readback');
                if (scope === 'none') {
                    if (readback) { readback.textContent = 'Choose "specific songbooks" or "every copyrighted song" first.'; }
                    return;
                }
                const body = { action: 'wizard_seed_restrictions', scope: scope };
                if (scope === 'songbooks') {
                    const books = currentSeedSongbooks();
                    if (books.length === 0) {
                        if (readback) { readback.textContent = 'Pick at least one songbook first.'; }
                        return;
                    }
                    books.forEach((b, i) => { body['songbooks[' + i + ']'] = b; });
                }
                addRulesBtn.disabled = true;
                postJson(body).then(function (result) {
                    addRulesBtn.disabled = false;
                    if (result.data && result.data.ok) {
                        state.removedRowsAvailable = true;
                        if (readback) {
                            readback.textContent = 'Added ' + result.data.created + ' rule(s)'
                                + (result.data.skipped ? (', skipped ' + result.data.skipped + ' already-covered song(s)') : '') + '.';
                        }
                        refreshStatus();
                    } else {
                        const msg = (result.data && result.data.error) || 'Could not add the rules.';
                        if (readback) { readback.textContent = msg; }
                    }
                }).catch(function () {
                    addRulesBtn.disabled = false;
                    if (readback) { readback.textContent = 'Could not reach the server. Please try again.'; }
                });
            });
        }

        /* ---- step 5 flip --------------------------------------------------- */
        const flipBtn = modalEl.querySelector('[data-gwiz-flip]');
        if (flipBtn) {
            flipBtn.addEventListener('click', function () {
                const confirmInput = document.getElementById('gwiz-confirm-text');
                const body = {
                    action: 'wizard_flip_gating',
                    confirm: confirmInput ? confirmInput.value.trim() : '',
                    ack_warnings: allEnableChecksTicked() ? '1' : '',
                };
                flipBtn.disabled = true;
                postJson(body).then(function (result) {
                    flipBtn.disabled = false;
                    if (result.data && result.data.ok) {
                        state.flipped = true;
                        document.getElementById('gwiz-enable-body').hidden = true;
                        const enabledReadback = document.getElementById('gwiz-enabled-readback');
                        enabledReadback.hidden = false;
                        document.getElementById('gwiz-switch-state-text').innerHTML =
                            'The master switch is now <strong>ON</strong> — enforcement is live.';
                        renderFinishSummary(true);
                        /* a11y audit A9 — the just-clicked "Enable content
                           locking" button's own container is hidden right
                           above, so without this, focus silently falls to
                           <body> after the single biggest state change this
                           app makes. Move it onto the readback region
                           (role="status", tabindex="-1") so a keyboard/
                           screen-reader user is told the flip happened and
                           lands somewhere sensible — right next to the Undo
                           button A0 just made reachable. */
                        enabledReadback.focus();
                        return;
                    }
                    if (result.status === 409 && result.data) {
                        const parts = [];
                        (result.data.blockers || []).forEach((m) => parts.push(m));
                        (result.data.warnings || []).forEach((m) => parts.push(m));
                        showStepError(STEP_ENABLE, parts.join(' ') || 'Could not turn content locking on yet.');
                    } else {
                        showStepError(STEP_ENABLE, (result.data && result.data.error) || 'Could not turn content locking on — please retry.');
                    }
                }).catch(function () {
                    flipBtn.disabled = false;
                    showStepError(STEP_ENABLE, 'Could not reach the server. Please try again.');
                });
            });
        }
        /* a11y audit A0 — CRITICAL (2026-08-30). The button below is
           rendered with `data-gwiz-rollback-inline` and NO `id` (see the
           markup at "gwiz-enabled-readback"), so
           `getElementById('gwiz-rollback-inline')` always returned null and
           this listener was never attached — a named, focusable,
           completely dead control (rule #30's "silent no-op" class) for
           every admin who used the wizard's in-modal Undo instead of the
           page-level rollback form. Fixed by looking it up the same way
           every other wizard control on this page already is: a
           `[data-*]` attribute selector via `querySelector()`, scoped to
           this modal. Wiring is proven by tests/php/test-gating-wizard.php. */
        const rollbackInlineBtn = modalEl.querySelector('[data-gwiz-rollback-inline]');
        if (rollbackInlineBtn) {
            rollbackInlineBtn.addEventListener('click', function () {
                postJson({ action: 'wizard_rollback_gating', remove_seeded: state.removedRowsAvailable ? '1' : '' }).then(function (result) {
                    if (result.data && result.data.ok) {
                        state.flipped = false;
                        document.getElementById('gwiz-enable-body').hidden = false;
                        document.getElementById('gwiz-enabled-readback').hidden = true;
                        document.getElementById('gwiz-switch-state-text').innerHTML =
                            'Content locking is <strong>OFF</strong> again. Visitors see everything exactly as they did before you started.';
                        renderFinishSummary(false);
                        window.location.reload();
                    }
                });
            });
        }

        function renderFinishSummary(enabled) {
            const el = document.getElementById('gwiz-finish-summary');
            if (!el) { return; }
            if (enabled) {
                el.innerHTML = '<p class="text-success mb-0"><i aria-hidden="true" class="bi bi-check-circle-fill me-1"></i>'
                    + 'Content locking is now ON. You can switch it off at any time with the "Switch enforcement off '
                    + '(rollback)" button on this page — you do not need to reopen this wizard.</p>';
            } else {
                el.innerHTML = '<p class="text-muted small mb-0">Nothing was changed yet — go back to step 5 whenever you\'re ready.</p>';
            }
        }

        /* ---- step 6 song test ------------------------------------------- */
        const songSearchInput = document.getElementById('gwiz-song-search');
        const songResultsEl = document.getElementById('gwiz-song-results');
        const songResultsStatusEl = document.getElementById('gwiz-song-results-status');
        const songIdInput = document.getElementById('gwiz-song-id');
        let songSearchTimer = null;
        /* a11y audit A1 (2026-08-30) — this hand-rolled typeahead had no
           combobox semantics: no role="combobox"/aria-expanded/aria-controls,
           no result-count announcement, no arrow-key support (Tab+Enter DID
           work — the results are real <button>s in DOM order — but discovery
           of "results appeared" was sighted-only). Rule #43 says reuse the
           shared picker rather than hand-roll a second one:
           window.iHymnsComboboxA11y (js/modules/combobox-a11y.js, already
           loaded globally for every /manage/* page via head-libs.php) owns
           the ARIA bookkeeping + arrow/Home/End/Enter/Tab/Escape keys; this
           file keeps its own fetch/debounce/render exactly as before (that
           split is the module's own documented contract). */
        let songActiveIndex = -1;
        let songResultItems = [];

        function songResultsRender() {
            if (window.iHymnsComboboxA11y) {
                window.iHymnsComboboxA11y.applyComboboxAria({
                    input: songSearchInput,
                    panel: songResultsEl,
                    items: songResultItems,
                    activeIndex: songActiveIndex,
                    idPrefix: 'gwiz-song-result',
                });
            }
            songResultItems.forEach(function (el, i) {
                el.classList.toggle('active', i === songActiveIndex);
            });
        }

        function songResultsClose() {
            songResultsEl.classList.add('d-none');
            songResultsEl.innerHTML = '';
            songResultItems = [];
            songActiveIndex = -1;
            if (window.iHymnsComboboxA11y) {
                window.iHymnsComboboxA11y.applyComboboxAria({
                    input: songSearchInput, panel: songResultsEl, items: [], activeIndex: -1, idPrefix: 'gwiz-song-result',
                });
            }
        }

        function songPick(el) {
            songIdInput.value = el.getAttribute('data-song-id');
            songSearchInput.value = el.getAttribute('data-song-title');
            songResultsClose();
        }

        if (songSearchInput) {
            songSearchInput.addEventListener('input', function () {
                window.clearTimeout(songSearchTimer);
                const q = songSearchInput.value.trim();
                songIdInput.value = '';
                if (q.length < 2) {
                    songResultsClose();
                    if (songResultsStatusEl) { songResultsStatusEl.textContent = ''; }
                    return;
                }
                songSearchTimer = window.setTimeout(function () {
                    fetch('/api?action=search&q=' + encodeURIComponent(q) + '&limit=10', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    }).then((r) => r.json()).then(function (data) {
                        const results = (data && data.results) || [];
                        if (!results.length) {
                            songResultsClose();
                            if (songResultsStatusEl) { songResultsStatusEl.textContent = 'No results.'; }
                            return;
                        }
                        songResultsEl.innerHTML = results.map(function (s) {
                            return '<button type="button" class="list-group-item list-group-item-action" data-song-id="'
                                + escapeHtml(s.id) + '" data-song-title="' + escapeHtml(s.title || s.id) + '">'
                                + escapeHtml(s.title || s.id) + (s.songbook ? ' <span class="text-muted small">· ' + escapeHtml(s.songbook) + '</span>' : '')
                                + '</button>';
                        }).join('');
                        songResultsEl.classList.remove('d-none');
                        songResultItems = Array.from(songResultsEl.querySelectorAll('[data-song-id]'));
                        songActiveIndex = -1;
                        songResultsRender();
                        if (songResultsStatusEl) {
                            songResultsStatusEl.textContent = results.length + (results.length === 1 ? ' result.' : ' results.');
                        }
                    }).catch(function () { /* silent — the run-test button just won't have an id yet */ });
                }, 250);
            });
            songSearchInput.addEventListener('keydown', function (ev) {
                if (!window.iHymnsComboboxA11y) { return; }
                window.iHymnsComboboxA11y.handleComboboxKeydown(ev, {
                    isOpen: function () { return !songResultsEl.classList.contains('d-none'); },
                    getItems: function () { return songResultItems; },
                    getActiveIndex: function () { return songActiveIndex; },
                    setActiveIndex: function (i) { songActiveIndex = i; },
                    render: songResultsRender,
                    onCommit: function (i, el) { songPick(el); },
                    onClose: songResultsClose,
                });
            });
            songResultsEl.addEventListener('click', function (ev) {
                const btn = ev.target.closest('[data-song-id]');
                if (!btn) { return; }
                songPick(btn);
            });
        }

        function mediaBadges(kinds) {
            if (!kinds || !kinds.length) { return '<span class="text-muted">none</span>'; }
            return kinds.map((k) => '<span class="badge bg-secondary me-1">' + escapeHtml(k) + '</span>').join('');
        }
        function variantRowHtml(label, v) {
            if (!v) { return ''; }
            return '<tr><td>' + escapeHtml(label) + '</td>'
                + '<td>' + (v.lyricsIncluded ? '<span class="text-success">yes</span>' : '<span class="text-danger">no</span>')
                + (v.restrictionReason ? ' <span class="text-muted small">(' + escapeHtml(v.restrictionReason) + ')</span>' : '') + '</td>'
                + '<td>' + mediaBadges(v.mediaKinds) + '</td>'
                + '<td>' + (v.offlineAllowed ? 'yes' : 'no') + '</td></tr>';
        }

        const runTestBtn = modalEl.querySelector('[data-gwiz-run-test]');
        if (runTestBtn) {
            runTestBtn.addEventListener('click', function () {
                const id = songIdInput ? songIdInput.value.trim() : '';
                const resultsEl = document.getElementById('gwiz-test-results');
                if (!id) {
                    showStepError(STEP_TEST, 'Search for a song and pick one from the list first.');
                    return;
                }
                runTestBtn.disabled = true;
                getJson({ action: 'wizard_song_test', song: id }).then(function (result) {
                    runTestBtn.disabled = false;
                    if (!result.data || result.data.error) {
                        showStepError(STEP_TEST, (result.data && result.data.error) || 'Could not run the test.');
                        return;
                    }
                    const d = result.data;
                    /* a11y audit A8 — a data TABLE is what follows, and live-
                       announcing the whole thing on every re-run would be
                       noisy and hard to follow (WCAG 4.1.3, but a considerate
                       one). role="status" on just this ONE summary line
                       announces "test complete for <title>" without the
                       table's contents being read out cell by cell. */
                    let html = '<p class="small" role="status">Testing <strong>' + escapeHtml(d.title || d.songId) + '</strong> — '
                        + (d.live ? '<span class="badge bg-warning text-dark">live</span>' : '<span class="badge bg-secondary">simulated (switch still off)</span>')
                        + ' — table updated below.</p>';
                    html += '<p class="small mb-1">Entity rule check: '
                        + (d.entity && d.entity.allowed ? '<span class="text-success">allowed</span>' : '<span class="text-danger">blocked' + (d.entity && d.entity.reason ? ' — ' + escapeHtml(d.entity.reason) : '') + '</span>')
                        + '</p>';
                    html += '<div class="table-responsive"><table class="table table-sm"><thead><tr>'
                        + '<th scope="col">Visitor kind</th><th scope="col">Words shown?</th><th scope="col">Media</th><th scope="col">Offline?</th>'
                        + '</tr></thead><tbody>';
                    html += variantRowHtml('Anonymous (simulated)', d.variants && d.variants.public);
                    html += variantRowHtml('Signed-in, free', d.variants && d.variants.free);
                    html += variantRowHtml('Signed-in, CCLI', d.variants && d.variants.ccli);
                    html += variantRowHtml('Signed-in, Premium', d.variants && d.variants.premium);
                    html += variantRowHtml('Anonymous (live now)', d.liveAnonymous);
                    html += '</tbody></table></div>';
                    resultsEl.innerHTML = html;
                }).catch(function () {
                    runTestBtn.disabled = false;
                    showStepError(STEP_TEST, 'Could not reach the server. Please try again.');
                });
            });
        }

        /* ---- wizard chrome ------------------------------------------------- */
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

        const nextBtn = modalEl.querySelector('[data-wiz-next]');
        const wizard = createWizard(modalEl, {
            host: 'bootstrap-modal',
            validateStep: function (index) {
                /* Only step 5 (Enable) can block Next — and only until the
                   flip has actually happened OR the admin explicitly ticks
                   "not ready yet" (the escape hatch that keeps browsing
                   possible without implying the flip is done). Every other
                   step is always navigable — this wizard never forces an
                   admin through a step they don't need. */
                if (index === STEP_ENABLE) {
                    const skip = document.getElementById('gwiz-skip-enable');
                    if (state.flipped || (skip && skip.checked)) { return true; }
                    return { ok: false, message: 'Turn content locking on above, or tick "Not ready yet" to keep browsing without enabling it.' };
                }
                return true;
            },
            onStepChange: function (from, to) {
                if (nextBtn) { nextBtn.textContent = (to === LAST_STEP) ? 'Close' : 'Next'; }
                if (to === STEP_PREVIEW || to === STEP_LICENCES || to === STEP_ENABLE) {
                    refreshStatus();
                }
            },
            onFinish: function () {
                const modal = window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;
                if (modal) { modal.hide(); }
            },
        });

        const refreshBtns = modalEl.querySelectorAll('[data-gwiz-refresh-status]');
        refreshBtns.forEach((btn) => btn.addEventListener('click', refreshStatus));

        /* Reload the whole page once the modal is closed AFTER a successful
           flip or rollback, so the master-switch card + readiness checklist
           (both server-rendered) reflect the new state without a stale
           reload-less page sitting behind the modal. */
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (state.flipped) { window.location.reload(); }
        });

        /* Prime step 2/4's data as soon as the modal is first shown, so the
           admin doesn't have to click "Refresh" on first view. */
        modalEl.addEventListener('shown.bs.modal', function () {
            if (!state.status) { refreshStatus(); }
        }, { once: true });
    })();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

</body>
</html>
