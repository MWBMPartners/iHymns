<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Gating hub + activation readiness (#1769 P6 / #1778)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE (ELI5):
 * One page that answers "what is gated, for whom, and is it safe to turn on?"
 * without spelunking the five separate gating pages. It gathers the gating
 * family (Content Restrictions, Access Tiers, Licence Types, Feature Gating,
 * Entitlements) behind a readiness checklist and shows the master-switch state.
 *
 * WHY IT IS READ-ONLY (the load-bearing design point):
 * The whole #1769 program (P0–P5) ships DORMANT and verified byte-identical
 * while `content_gating_enabled='0'` on all three shared-DB docroots. Turning
 * enforcement ON is the ONE action in the program that is NOT a no-op — it
 * changes what every reader emits at once — so it stays a deliberate human act,
 * NOT something a page flips as a side effect (#1778). This hub therefore does
 * NOT own a flip control: the switch lives in ONE place (`/manage/configuration`,
 * beside `feature_gating_rules_enabled` in the same form) so there is exactly
 * one write path for the setting (no duplicate control to drift, rule #35). The
 * hub READS the state, runs the precondition checklist, and links to the flip.
 *
 * SAFETY: gated on `manage_configuration` — the SAME entitlement its nav entry
 * advertises (#1587) and the same gate the switch itself uses. Every DB read is
 * existence-probed + wrapped so a pre-migration install degrades to a themed
 * "not ready" row, never a STRICT-mode fatal (rule #9).
 *
 * @see includes/content_gating.php        contentGatingApply (dormant until the flip)
 * @see manage/configuration.php            the ONE master-switch control
 * @see manage/gating-noop-verify.php       the OFF-baseline differential harness
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php'; // getAppSetting()

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_configuration', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied. The manage_configuration entitlement is required.');
}
$activePage = 'gating';

/* ---- current master-switch state (read-only here) --------------------- */
$gatingEnabled  = ((string)(getAppSetting('content_gating_enabled', '0') ?? '0')) === '1';
$featureEnabled = ((string)(getAppSetting('feature_gating_rules_enabled', '0') ?? '0')) === '1';

/**
 * True when EVERY named table exists (INFORMATION_SCHEMA, bound). Fail-safe:
 * any probe error reports "absent" so the checklist shows red rather than
 * throwing (rule #9 — migrations are web-run, so "present in schema.sql" never
 * means "present on this env").
 */
function gatingHub_tablesExist(\mysqli $db, array $tables): bool
{
    try {
        $ph   = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT TABLE_NAME) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($ph)"
        );
        $stmt->bind_param(str_repeat('s', count($tables)), ...$tables);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $n === count($tables);
    } catch (\Throwable $_e) {
        return false;
    }
}

/** True when $table.$column exists (fail-safe absent on error). */
function gatingHub_columnExists(\mysqli $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ok;
    } catch (\Throwable $_e) {
        return false;
    }
}

/* ---- readiness probes (all wrapped) ----------------------------------- */
$schemaReady = false;
$tierCapsReady = false;
try {
    $db = getDbMysqli();
    $schemaReady = gatingHub_tablesExist($db, [
        'tblAccessTiers', 'tblContentRestrictions', 'tblLicenceTypes',
        'tblGatingCapabilities', 'tblOrganisationLicences',
    ]);
    /* The #1769 P1 additive JSON cap column — proof the one-pass tier-caps
       batch landed (rule #28: new caps live here, not new columns). */
    $tierCapsReady = gatingHub_columnExists($db, 'tblAccessTiers', 'Capabilities');
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
   linking to their issues — never auto-ticked. */
$gatingDecisions = [
    [1772, 'mrl / custom-tier licence conferral'],
    [1773, 'RequiresCcli — registry cap vs tier-name'],
    [1774, 'sheet / midi presence-OR divergence'],
    [1775, 'audio-media.php serve-time tier gate'],
    [1776, 'Cache-API clear on login'],
    [1777, 'presence token into checkBulkAccess'],
];
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

        <h1 class="h4 mb-3"><i class="bi bi-shield-shaded me-2"></i>Content Access</h1>
        <p class="text-secondary small mb-4">
            One place to see everything that controls who can view and download your songs,
            and to check whether it is safe to switch content locking on. While the master
            switch is <strong>off</strong>, everything here is inactive and people see songs
            exactly as they do now. Turning it on is a deliberate step you take by hand — this
            page never does it for you.
        </p>

        <!-- Master-switch state (read-only; the control lives on Configuration) -->
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
                <a href="/manage/configuration#gating" class="btn btn-outline-warning btn-sm ms-auto">
                    <i class="bi bi-sliders me-1"></i>Change the switch (Configuration)
                </a>
            </div>
            <?php if (!$gatingEnabled): ?>
                <p class="small text-body-secondary mb-0 mt-2">
                    The switch is off, so everything below is inert. Work through the
                    readiness checklist before flipping it on Configuration.
                </p>
            <?php else: ?>
                <p class="small text-warning mb-0 mt-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>Enforcement is LIVE — restriction rows,
                    tier caps and licence conferral are now applied to every reader.
                </p>
            <?php endif; ?>
        </div>

        <!-- Readiness checklist -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">Activation readiness</h2>
            <ul class="list-group list-group-flush">
                <li class="list-group-item bg-transparent d-flex align-items-start gap-2">
                    <i class="bi <?= $schemaReady ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
                    <div>
                        <strong>Gating schema present</strong>
                        <div class="small text-secondary">The five family tables exist on this environment
                            (<?= $schemaReady ? 'yes' : 'NO — run the #1769 P1 migration batch on /manage/setup-database' ?>).</div>
                    </div>
                </li>
                <li class="list-group-item bg-transparent d-flex align-items-start gap-2">
                    <i class="bi <?= $tierCapsReady ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
                    <div>
                        <strong>Tier-capabilities registry column</strong>
                        <div class="small text-secondary"><code>tblAccessTiers.Capabilities</code> (the JSON cap store, rule #28)
                            <?= $tierCapsReady ? 'is present' : 'is MISSING — run its migration card' ?>.</div>
                    </div>
                </li>
                <li class="list-group-item bg-transparent d-flex align-items-start gap-2">
                    <i class="bi bi-shield-check text-info"></i>
                    <div>
                        <strong>Byte-identical no-op proven on this env</strong>
                        <div class="small text-secondary">Capture the OFF baseline and re-run Verify on
                            <a href="/manage/gating-noop-verify" class="link-light">Gating No-op Verify</a> —
                            every sampled song must hash identically before you flip the switch.</div>
                    </div>
                </li>
                <li class="list-group-item bg-transparent d-flex align-items-start gap-2">
                    <i class="bi bi-person-check text-warning"></i>
                    <div>
                        <strong>Owner decisions resolved (#1772–#1777)</strong>
                        <div class="small text-secondary">Six judgement calls the code cannot make. Confirm each on its issue
                            before enforcing — otherwise the flip applies half-decided behaviour:</div>
                        <ul class="small mb-0 mt-1">
                            <?php foreach ($gatingDecisions as [$num, $desc]): ?>
                                <li><a href="https://github.com/MWBMPartners/iHymns/issues/<?= (int)$num ?>" class="link-light" target="_blank" rel="noopener">#<?= (int)$num ?></a> — <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </li>
            </ul>
        </div>

        <!-- The family pages -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3">The gating family</h2>
            <div class="row g-3">
                <?php foreach ($gatingFamily as [$label, $route, $ent, $icon, $blurb]): ?>
                    <?php $canSee = userHasEntitlement($ent, $currentUser['role'] ?? null); ?>
                    <div class="col-md-6">
                        <div class="border border-secondary rounded p-2 h-100 <?= $canSee ? '' : 'opacity-50' ?>">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>
                                <?php if ($canSee): ?>
                                    <a href="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>" class="link-light fw-semibold"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
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

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

</body>
</html>
