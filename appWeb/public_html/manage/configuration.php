<?php

declare(strict_types=1);

/**
 * iHymns — Admin: System Configuration (#768)
 *
 * Global Admin only. v1 ships the Email service section; the page is
 * scaffolded so future system-config sections (CAPTCHA provider,
 * MOTD, ads_enabled, registration_mode, …) can each become an
 * additional accordion without rebuilding form / save / audit
 * plumbing.
 *
 * Settings persist as rows in tblAppSettings (key/value). Each save
 * emits a `app_setting.update` activity-log row with a key list
 * (values redacted for secrets — only the change-set key names are
 * recorded so the audit trail shows "an admin changed SMTP creds at
 * timestamp X" without writing the password into the log).
 *
 * Real send-mechanism landed in #898 via includes/EmailService.php
 * (SendGrid / Mailgun / SES drivers). feature C adds a working SMTP-AUTH
 * driver with provider presets (Microsoft 365 priority, Google Workspace)
 * and DELEGATE / "send-as" support — the From / Sender mailbox can differ
 * from the SMTP login when the provider has granted Send-As. OAuth2 /
 * MailerMatt is the eventual path; today it is SMTP-AUTH + app password.
 * The "Send test email" button posts to test_email and renders the
 * EmailSendResult inline so admins can verify provider config without
 * triggering a real auth flow.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'environment.php'; // #1233 per-env maintenance

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_configuration', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_configuration required</h1></body></html>';
    exit;
}
$activePage = 'configuration';

$db   = getDbMysqli();
$csrf = csrfToken();

/* ----------------------------------------------------------------------
 * Settings model. The list is kept in one place so the form, the
 * save handler, and the audit-log redaction list all stay in sync.
 *
 * `secret` => true: don't echo the current value to the rendered HTML
 * (still saveable; the field shows a placeholder); also redacted from
 * the activity-log details. The on-the-wire POST still carries it
 * (over HTTPS) when the admin actually changes it.
 * ---------------------------------------------------------------------- */
$EMAIL_SETTINGS = [
    /* key                     => [label, type, secret, providers] */
    'email_service'             => ['Email service',             'select', false, null],
    /* #1309 — 'office365' and 'gmail' are first-class SMTP-AUTH providers, so
       the SMTP + common field groups are visible for them too. */
    'email_from_address'        => ['From address',              'email',  false, ['smtp','office365','gmail','sendgrid','mailgun','ses']],
    'email_from_name'           => ['From name',                 'text',   false, ['smtp','office365','gmail','sendgrid','mailgun','ses']],
    /* feature C — SMTP provider preset (pre-fills host/port/secure in the
       UI; constrained server-side to the $SMTP_PRESETS keys). Custom SMTP
       only — for office365/gmail the preset is implied by the provider. */
    'email_smtp_preset'         => ['SMTP provider preset',      'select', false, ['smtp']],
    'email_smtp_host'           => ['SMTP host',                 'text',   false, ['smtp','office365','gmail']],
    'email_smtp_port'           => ['SMTP port',                 'number', false, ['smtp','office365','gmail']],
    'email_smtp_user'           => ['SMTP username',             'text',   false, ['smtp','office365','gmail']],
    'email_smtp_pass'           => ['SMTP password',             'password', true, ['smtp','office365','gmail']],
    'email_smtp_secure'         => ['SMTP encryption',           'select', false, ['smtp','office365','gmail']],
    /* feature C — delegate / send-as. Optional; validated as an email in
       the save handler. When set, mail is sent FROM this mailbox while
       AUTH still uses the SMTP username above (the login mailbox must be
       granted Send-As on it in the provider's admin console). */
    'email_smtp_from_address'   => ['Send-as / From address (delegate)', 'email', false, ['smtp','office365','gmail']],
    'email_smtp_from_name'      => ['Send-as display name',      'text',   false, ['smtp','office365','gmail']],
    'email_sendgrid_api_key'    => ['SendGrid API key',          'password', true, ['sendgrid']],
    'email_mailgun_api_key'     => ['Mailgun API key',           'password', true, ['mailgun']],
    'email_mailgun_domain'      => ['Mailgun domain',            'text',   false, ['mailgun']],
    'email_ses_region'          => ['AWS region (e.g. eu-west-1)', 'text', false, ['ses']],
    'email_ses_access_key'      => ['AWS access key',            'password', true, ['ses']],
    'email_ses_secret_key'      => ['AWS secret key',            'password', true, ['ses']],
    /* #1311 — OAuth2 API transport. The auth-method selector applies to the
       office365/gmail providers; the Graph + Gmail-API credential fields show
       only when method=oauth2 (client-side data-auth-show). The secrets (client
       secret, service-account JSON) keep secret=true → blank-skip on save +
       redaction from the activity-log key list. */
    'email_auth_method'         => ['Authentication method',          'select',   false, ['office365','gmail']],
    'email_graph_tenant_id'     => ['Azure tenant ID',                'text',     false, ['office365']],
    'email_graph_client_id'     => ['Azure app (client) ID',          'text',     false, ['office365']],
    'email_graph_client_secret' => ['Azure client secret',            'password', true,  ['office365']],
    'email_graph_sender'        => ['Sender mailbox (UPN)',           'text',     false, ['office365']],
    'email_gmail_sa_json'       => ['Service-account JSON key',       'textarea', true,  ['gmail']],
    'email_gmail_sender'        => ['Sender mailbox (impersonated)',  'text',     false, ['gmail']],
];

/* #1311 — OAuth2 transport options for the office365/gmail providers. */
$EMAIL_AUTH_METHOD_OPTIONS = [
    'smtp'   => 'SMTP-AUTH (host + app password)',
    'oauth2' => 'OAuth2 API (Microsoft Graph / Gmail API — no SMTP)',
];

/* #1309 — Microsoft 365 + Google Workspace are now FIRST-CLASS providers in
   this dropdown (they used to be a nested "preset" under a generic SMTP entry,
   which the owner reported as undiscoverable — the guides showed but the
   services weren't selectable). The keys match the shared smtp_presets map so
   the pre-fill JS + EmailService dispatch recognise them; both route through
   the SMTP-AUTH transport. 'smtp' remains for any OTHER custom server. */
$EMAIL_SERVICE_OPTIONS = [
    'none'      => 'None — email login disabled',
    'office365' => 'Microsoft 365 (Exchange Online)',
    'gmail'     => 'Google Workspace / Gmail',
    'smtp'      => 'SMTP (other / custom server)',
    'sendgrid'  => 'SendGrid',
    'mailgun'   => 'Mailgun',
    'ses'       => 'AWS SES',
];

$SMTP_SECURE_OPTIONS = [
    'tls'  => 'STARTTLS (port 587)',
    'ssl'  => 'SSL/TLS implicit (port 465)',
    'none' => 'None (port 25 — not recommended)',
];

/* ----------------------------------------------------------------------
 * feature C — SMTP provider presets. Selecting one pre-fills host / port /
 * encryption in the UI (client-side JS, below). 'custom' leaves the manual
 * fields as-is. The value is stored only as a hint for the next page load;
 * the authoritative connection details are the host/port/secure fields.
 *
 * The host/port/secure here are also the SINGLE source of truth the JS
 * reads, so adding a provider is a one-line change. (Microsoft 365 is the
 * priority target; Google Workspace second.)
 * ---------------------------------------------------------------------- */
/* #1309 — the preset map now lives in the shared includes/smtp_presets.php so
   EmailService.php reads the SAME host/port/secure when an office365 / gmail
   provider is sent server-side (no drift between UI and sender). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'smtp_presets.php';
$SMTP_PRESETS = ihymns_smtp_presets();

/* ----------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */
$loadSettings = function (mysqli $db, array $keys): array {
    if (empty($keys)) return [];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare(
        "SELECT SettingKey, SettingValue FROM tblAppSettings WHERE SettingKey IN ({$placeholders})"
    );
    $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['SettingKey']] = (string)$r['SettingValue'];
    }
    return $out;
};

$saveSetting = function (mysqli $db, string $key, string $value): void {
    $stmt = $db->prepare(
        'INSERT INTO tblAppSettings (SettingKey, SettingValue)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
};

/* ----------------------------------------------------------------------
 * POST handlers
 * ---------------------------------------------------------------------- */
$saveSuccess = '';
$saveError   = '';
$saveWarning = '';     /* #1304 — non-blocking SSRF heads-up (private/reserved SMTP host) */
$testResult  = null;   /* ['ok' => bool, 'message' => string]|null */

/* #1304 — defence-in-depth: does an SMTP host resolve to a private/reserved
   network address? Used to WARN (never block) on save — an admin pointing the
   "Send test email" connect at an internal host (e.g. 127.0.0.1, 169.254.169.254,
   10.x) is a low-risk SSRF surface (admin-gated, SMTP-not-HTTP), so we surface a
   heads-up rather than reject. Returns false for unresolvable hosts (don't warn on
   a typo) and for the public provider endpoints (office365/gmail), so the common
   case never trips. FILTER_VALIDATE_IP with NO_PRIV|NO_RES returns false when the
   IP IS in a private/reserved range — see the PHP manual on filter flags. */
$smtpHostIsPrivate = function (string $host): bool {
    $host = trim($host);
    if ($host === '') { return false; }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;                                  /* host is already a literal IP */
    } else {
        $v4 = @gethostbynamel($host);                    /* IPv4 A records, or false */
        if (is_array($v4)) { $ips = array_merge($ips, $v4); }
        $aaaa = @dns_get_record($host, DNS_AAAA);        /* IPv6 AAAA records, best-effort */
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) { $ips[] = (string)$rec['ipv6']; }
            }
        }
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;                                 /* a private/reserved address among the resolutions */
        }
    }
    return false;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $saveError = 'CSRF token invalid — refresh the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_email') {
            $changedKeys = [];
            try {
                foreach ($EMAIL_SETTINGS as $key => [$label, $type, $secret, $providers]) {
                    if (!array_key_exists($key, $_POST)) continue;
                    $value = trim((string)$_POST[$key]);
                    /* Service is constrained to the option list. */
                    if ($key === 'email_service' && !isset($EMAIL_SERVICE_OPTIONS[$value])) {
                        $value = 'none';
                    }
                    if ($key === 'email_smtp_secure' && !isset($SMTP_SECURE_OPTIONS[$value])) {
                        $value = 'tls';
                    }
                    /* feature C — preset constrained to the known keys;
                       anything else (incl. a tampered POST) → 'custom'. */
                    if ($key === 'email_smtp_preset' && !isset($SMTP_PRESETS[$value])) {
                        $value = 'custom';
                    }
                    /* #1311 — auth method constrained to the option list. */
                    if ($key === 'email_auth_method' && !isset($EMAIL_AUTH_METHOD_OPTIONS[$value])) {
                        $value = 'smtp';
                    }
                    /* #1311 — service-account JSON must be valid + carry the
                       fields the Gmail-API driver needs (client_email +
                       private_key) before it can reach EmailService. Empty is
                       allowed (secret blank-skip below keeps the existing key). */
                    if ($key === 'email_gmail_sa_json' && $value !== '') {
                        $sa = json_decode($value, true);
                        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
                            throw new \RuntimeException('Service-account JSON is not valid — it must be the downloaded key file containing "client_email" and "private_key".');
                        }
                    }
                    /* feature C — delegate / send-as address. Empty is
                       allowed (means "no delegate, use the generic From");
                       a non-empty value MUST be a syntactically valid email
                       or the save is rejected, so a bad address can never
                       reach the From / MAIL FROM in EmailService. */
                    if ($key === 'email_smtp_from_address' && $value !== ''
                        && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new \RuntimeException('Send-as / From address is not a valid email address.');
                    }
                    /* Empty password fields mean "leave existing value"
                       (the form doesn't echo current secrets back, so
                       a blank submission is the user not editing). */
                    if ($secret && $value === '') continue;
                    $saveSetting($db, $key, $value);
                    $changedKeys[] = $key;
                }
                if (function_exists('logActivity')) {
                    logActivity(
                        'app_setting.update',
                        'app_setting',
                        'email_service',
                        ['keys' => $changedKeys],
                        'success'
                    );
                }
                $saveSuccess = 'Email configuration saved (' . count($changedKeys) . ' field' . (count($changedKeys) === 1 ? '' : 's') . ' updated).';
                /* #1304 — SSRF heads-up (never blocks): if the EFFECTIVE SMTP host
                   (posted host, or the preset default for office365/gmail) resolves
                   to a private/reserved range, flag it. Public provider endpoints
                   never trip this; only a custom internal host does. Plain text —
                   the render escapes it. */
                $svc = (string)($_POST['email_service'] ?? '');
                if (in_array($svc, ['smtp', 'office365', 'gmail'], true)) {
                    $effHost = trim((string)($_POST['email_smtp_host'] ?? ''));
                    if ($effHost === '' && isset($SMTP_PRESETS[$svc])) {
                        $effHost = (string)$SMTP_PRESETS[$svc]['host'];
                    }
                    if ($effHost !== '' && $smtpHostIsPrivate($effHost)) {
                        $saveWarning = 'Heads-up: the SMTP host “' . $effHost . '” resolves to a '
                            . 'private/reserved network address. That is allowed, but if it is not a '
                            . 'deliberate internal relay, double-check it — “Send test email” will connect to it.';
                    }
                }
            } catch (\Throwable $e) {
                error_log('[manage configuration save_email] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'test_email') {
            /* Real send (#898) — replaces the previous stub. Uses
               EmailService::send() with an ad-hoc payload targeting
               the current admin's own email so the button is harmless
               even if a typo lands in From. The EmailSendResult is
               mirrored into the alert and a structured email.send
               row goes into tblActivityLog. */
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'EmailService.php';
            /* The save_email branch above may have just changed the
               provider config in this same request; reset the cache
               so the test reads the fresh values. */
            EmailService::resetCache();
            /* Reload current settings so the alert text reflects the
               just-saved provider (the page-level $currentSettings
               below is fetched after this block runs). */
            $current = $loadSettings($db, array_keys($EMAIL_SETTINGS));
            $providerLabel = (string)($current['email_service'] ?? 'none');

            $adminEmail = trim((string)($currentUser['email'] ?? ''));
            if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $testResult = [
                    'ok'      => false,
                    'message' => 'Send-test failed: your admin account has no valid email address on file. '
                               . 'Set one in Users -> your row -> Edit, then retry.',
                ];
            } elseif (!EmailService::isConfigured()) {
                $testResult = [
                    'ok'      => false,
                    'message' => 'Send-test failed: provider is "' . $providerLabel . '". Pick a real provider and Save before testing.',
                ];
            } else {
                $stamp    = gmdate('Y-m-d H:i:s') . ' UTC';
                $bodyHtml = '<h1>iHymns email delivery test</h1>'
                          . '<p>This is a delivery test from <strong>' . htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') . '</strong>'
                          . ' at <strong>' . htmlspecialchars($stamp, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                          . '<p>If you received this, your email provider is correctly configured.</p>';
                $bodyText = "iHymns email delivery test from {$providerLabel} at {$stamp}.\n\n"
                          . "If you received this, your email provider is correctly configured.\n";
                $sendResult = EmailService::send(
                    $adminEmail,
                    'iHymns email delivery test (' . $providerLabel . ')',
                    $bodyHtml,
                    $bodyText
                );
                if ($sendResult->ok) {
                    $testResult = [
                        'ok'      => true,
                        'message' => 'Test email dispatched via ' . $sendResult->provider
                                   . (($sendResult->providerMessageId ?? '') !== '' ? ' (Message-Id: ' . $sendResult->providerMessageId . ')' : '')
                                   . '. Check ' . $adminEmail . ' to confirm delivery.',
                    ];
                } else {
                    $testResult = [
                        'ok'      => false,
                        'message' => 'Test email FAILED via ' . $sendResult->provider
                                   . ' (' . ($sendResult->errorClass ?? 'Error') . '): '
                                   . (string)$sendResult->error
                                   . '. See the Activity Log "email.send" row for the full record.',
                    ];
                }
            }
        } elseif ($action === 'save_maintenance') {
            /* System maintenance mode (WS-K #1021). Toggles the public site
               into a 503 maintenance landing page; /manage stays reachable
               (separate entry point) so this can always be turned back off. */
            try {
                /* #1233 — per-environment maintenance. The shared DB means each
                   env keys its own flags; this form toggles the CURRENT env only.
                   Manage another env from its own /manage. */
                $env      = ihymns_environment();
                $mmVal    = ((string)($_POST['maintenance_mode'] ?? '0')) === '1' ? '1' : '0';
                $msgVal   = mb_substr(trim((string)($_POST['maintenance_message'] ?? '')), 0, 500);
                $allowVal = ((string)($_POST['maintenance_allow_admins'] ?? '0')) === '1' ? '1' : '0';
                /* Auto-refresh interval for the maintenance 503 page (#1276
                   feature A). Coerce to int and clamp to the same [30, 3600]
                   range maintenanceRefreshSeconds() enforces on read, so a
                   hand-edited or out-of-range POST can never store a value the
                   page would have to defend against again. */
                require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';
                $refreshIn  = filter_var((string)($_POST['maintenance_refresh_seconds'] ?? ''), FILTER_VALIDATE_INT);
                $refreshVal = $refreshIn === false
                    ? MAINTENANCE_REFRESH_DEFAULT
                    : max(MAINTENANCE_REFRESH_MIN, min(MAINTENANCE_REFRESH_MAX, (int)$refreshIn));
                $saveSetting($db, 'maintenance_mode_' . $env, $mmVal);
                $saveSetting($db, 'maintenance_message_' . $env, $msgVal);
                $saveSetting($db, 'maintenance_allow_admins_' . $env, $allowVal);
                $saveSetting($db, 'maintenance_refresh_seconds_' . $env, (string)$refreshVal);
                if (function_exists('logActivity')) {
                    logActivity(
                        'app_setting.update',
                        'app_setting',
                        'maintenance_mode_' . $env,
                        ['keys' => ['maintenance_mode_' . $env, 'maintenance_message_' . $env, 'maintenance_allow_admins_' . $env, 'maintenance_refresh_seconds_' . $env], 'enabled' => $mmVal === '1'],
                        'success'
                    );
                }
                $saveSuccess = $mmVal === '1'
                    ? ('Maintenance mode is now ON for ' . $env . ' — that environment\'s public site shows a maintenance page; /manage stays open so you can turn it off.')
                    : ('Maintenance mode is now OFF for ' . $env . ' — its public site is live again.');
            } catch (\Throwable $e) {
                error_log('[manage configuration save_maintenance] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

/* ----------------------------------------------------------------------
 * Read current settings (after any save)
 * ---------------------------------------------------------------------- */
$currentSettings    = $loadSettings($db, array_keys($EMAIL_SETTINGS));
$currentService     = $currentSettings['email_service'] ?? 'none';
$envCurrent          = ihymns_environment();   // #1233 — per-env maintenance keys
$maintenanceSettings = $loadSettings($db, [
    'maintenance_mode_' . $envCurrent,
    'maintenance_message_' . $envCurrent,
    'maintenance_allow_admins_' . $envCurrent,
    'maintenance_refresh_seconds_' . $envCurrent,
]);
$maintenanceOn       = ($maintenanceSettings['maintenance_mode_' . $envCurrent] ?? '0') === '1';
$maintenanceMsg      = (string)($maintenanceSettings['maintenance_message_' . $envCurrent] ?? '');
$maintenanceAllowAdm = ($maintenanceSettings['maintenance_allow_admins_' . $envCurrent] ?? '0') === '1';
/* Auto-refresh interval (#1276). Reuse maintenanceRefreshSeconds() so the
   form's displayed value is the SAME clamped value the 503 page would use —
   no drift between what the admin sees and what visitors get. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';
$maintenanceRefresh  = maintenanceRefreshSeconds();

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration — iHymns Admin</title>
    <?php /* #965 — use the shared head bundler. Loads admin-theme-init.php
             so the page obeys the user's theme preference (was light-only),
             and syncs Bootstrap + Bootstrap-Icons versions with the rest
             of the admin area via APP_CONFIG['libraries']['bootstrap']. */ ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-sliders me-2"></i>Configuration
                <?= entitlementLockChipHtml('manage_configuration') ?>
            </h1>
            <p class="text-secondary small mb-0">
                System-wide settings. Changes apply immediately across the app.
                <span class="badge bg-danger text-light ms-1" style="font-size: 0.7rem; font-weight: 600;">
                    <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Global Admin only
                </span>
            </p>
        </div>
    </div>

    <?php if ($saveSuccess !== ''): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($saveSuccess, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <?php if ($saveError !== ''): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <?php if ($saveWarning !== ''): /* #1304 — non-blocking SSRF heads-up; host value escaped here */ ?>
        <div class="alert alert-warning">
            <i class="bi bi-shield-exclamation me-1"></i><?= htmlspecialchars($saveWarning, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- ===========================
         SYSTEM MAINTENANCE SECTION (WS-K #1021)
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-cone-striped me-2"></i>System maintenance
                <span class="badge bg-secondary ms-1 text-uppercase"><?= htmlspecialchars($envCurrent, ENT_QUOTES, 'UTF-8') ?></span>
            </h2>
            <span class="badge <?= $maintenanceOn ? 'bg-danger' : 'bg-success' ?>">
                <?= $maintenanceOn ? 'ON — public site in maintenance' : 'OFF — site is live' ?>
            </span>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">
                When ON, the public site and API return a 503 maintenance page to
                visitors. This admin area (<code>/manage</code>) and sign-in stay
                available so you can turn it back off. Returning app users keep
                their cached offline experience and see a maintenance banner.
            </p>
            <p class="small text-secondary mb-3">
                <i class="bi bi-hdd-network me-1"></i><strong>Per-environment.</strong>
                The three environments share one database, but each has its own flag —
                this toggles <strong><?= htmlspecialchars($envCurrent, ENT_QUOTES, 'UTF-8') ?></strong>
                only. Manage another environment from <em>its own</em> <code>/manage</code>.
                <strong>Global admins</strong> always keep access to the live site while
                maintenance is on; you can optionally extend that to regular admins below.
            </p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_maintenance">
                <!-- hidden 0 before the checkbox so an unchecked box still posts a value -->
                <input type="hidden" name="maintenance_mode" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="maintenance_mode" name="maintenance_mode" value="1" <?= $maintenanceOn ? 'checked' : '' ?>>
                    <label class="form-check-label" for="maintenance_mode">Enable maintenance mode</label>
                </div>
                <div class="mb-3">
                    <label for="maintenance_message" class="form-label">Message shown to visitors (optional)</label>
                    <textarea name="maintenance_message" id="maintenance_message" class="form-control" rows="2"
                              maxlength="500"
                              placeholder="We&rsquo;ll be back shortly &mdash; scheduled maintenance in progress."><?= htmlspecialchars($maintenanceMsg, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="maintenance_refresh_seconds" class="form-label">
                        Auto-refresh interval (seconds)
                    </label>
                    <input type="number" name="maintenance_refresh_seconds" id="maintenance_refresh_seconds"
                           class="form-control" style="max-width: 12rem;"
                           min="<?= MAINTENANCE_REFRESH_MIN ?>" max="<?= MAINTENANCE_REFRESH_MAX ?>" step="1"
                           value="<?= (int)$maintenanceRefresh ?>">
                    <div class="form-text">
                        How often the maintenance page reloads itself to check if the site is back
                        (a corner countdown shows the time remaining). Clamped to
                        <?= MAINTENANCE_REFRESH_MIN ?>&ndash;<?= MAINTENANCE_REFRESH_MAX ?> seconds; default
                        <?= MAINTENANCE_REFRESH_DEFAULT ?>.
                    </div>
                </div>
                <!-- hidden 0 so an unchecked box still posts a value -->
                <input type="hidden" name="maintenance_allow_admins" value="0">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="maintenance_allow_admins"
                           name="maintenance_allow_admins" value="1" <?= $maintenanceAllowAdm ? 'checked' : '' ?>>
                    <label class="form-check-label" for="maintenance_allow_admins">
                        Also let <strong>regular admins</strong> bypass maintenance on this environment
                        <span class="text-secondary">(global admins always can)</span>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Save maintenance settings
                </button>
            </form>
        </div>
    </div>

    <!-- ===========================
         EMAIL SERVICE SECTION
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-envelope-at me-2"></i>Email service
            </h2>
            <span class="badge <?= $currentService === 'none' ? 'bg-secondary' : 'bg-success' ?>">
                <?= $currentService === 'none' ? 'Not configured' : 'Configured: ' . htmlspecialchars($currentService, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
        <div class="card-body">
            <p class="small text-secondary">
                Powers password-reset emails, magic-link / one-time-code sign-in
                (#766), and any future transactional notifications. While set to
                <strong>None</strong>, the Sign In modal hides the email-login
                option and falls back to password-only mode (#766 / PR #767).
            </p>

            <?php /* feature C — preset host/port/secure data for the JS,
                     emitted as a JSON island so the script reads ONE source.
                     json_encode with JSON_HEX_* hardens against an XSS via a
                     future preset label containing < or ". */ ?>
            <script type="application/json" data-smtp-presets>
                <?= json_encode($SMTP_PRESETS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
            </script>

            <form method="post" id="email-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_email">

                <!-- Provider selector -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="email_service" class="form-label">Provider</label>
                        <select name="email_service" id="email_service" class="form-select" data-email-provider>
                            <?php foreach ($EMAIL_SERVICE_OPTIONS as $val => $label): ?>
                                <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $currentService === $val ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Common fields (visible when provider != 'none') -->
                <div class="email-fields" data-provider-show="smtp,office365,gmail,sendgrid,mailgun,ses">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email_from_address" class="form-label">From address</label>
                            <input type="email" name="email_from_address" id="email_from_address"
                                   class="form-control"
                                   value="<?= htmlspecialchars($currentSettings['email_from_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="no-reply@yourdomain.com">
                        </div>
                        <div class="col-md-6">
                            <label for="email_from_name" class="form-label">From name</label>
                            <input type="text" name="email_from_name" id="email_from_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($currentSettings['email_from_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="iHymns">
                        </div>
                    </div>
                </div>

                <!-- #1311 — transport selector for the office365 / gmail providers:
                     SMTP-AUTH (host + app password) vs OAuth2 API (Graph / Gmail API). -->
                <div class="email-fields" data-provider-show="office365,gmail">
                    <hr class="text-secondary">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email_auth_method" class="form-label">Authentication method</label>
                            <select name="email_auth_method" id="email_auth_method" class="form-select" data-email-auth-method>
                                <?php
                                    $authMethod = $currentSettings['email_auth_method'] ?? 'smtp';
                                    foreach ($EMAIL_AUTH_METHOD_OPTIONS as $amVal => $amLabel):
                                ?>
                                    <option value="<?= htmlspecialchars($amVal, ENT_QUOTES, 'UTF-8') ?>" <?= $authMethod === $amVal ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($amLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <strong>SMTP-AUTH</strong> = host + app password (simplest).
                                <strong>OAuth2 API</strong> = Microsoft Graph (M365) / Gmail API (Google) — recommended where Basic-Auth / SMTP-AUTH is disabled on the tenant.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SMTP-specific: custom SMTP, OR the office365/gmail providers when method=SMTP-AUTH (#1309/#1311) -->
                <div class="email-fields" data-provider-show="smtp,office365,gmail" data-auth-show="smtp">
                    <hr class="text-secondary">
                    <h3 class="h6 mb-3"><i class="bi bi-server me-1"></i>SMTP server</h3>

                    <!-- feature C — provider preset. Pre-fills host / port /
                         encryption client-side from $SMTP_PRESETS (see the
                         data-smtp-presets script JSON below). #1309: shown ONLY
                         for the generic "SMTP (custom server)" choice — for the
                         Microsoft 365 / Google Workspace providers the endpoint
                         is implied and auto-filled by the provider-change JS. -->
                    <div class="email-fields" data-provider-show="smtp">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email_smtp_preset" class="form-label">Provider preset</label>
                            <select name="email_smtp_preset" id="email_smtp_preset"
                                    class="form-select" data-smtp-preset>
                                <?php
                                    $smtpPreset = $currentSettings['email_smtp_preset'] ?? 'custom';
                                    foreach ($SMTP_PRESETS as $pVal => $pCfg):
                                ?>
                                    <option value="<?= htmlspecialchars($pVal, ENT_QUOTES, 'UTF-8') ?>" <?= $smtpPreset === $pVal ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pCfg['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Pre-fills host, port and encryption for common providers.
                                Choose <strong>Custom / manual</strong> to enter them yourself.
                            </div>
                        </div>
                    </div>
                    </div><!-- /preset row (custom-SMTP only, #1309) -->

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email_smtp_host" class="form-label">SMTP host</label>
                            <input type="text" name="email_smtp_host" id="email_smtp_host"
                                   class="form-control"
                                   value="<?= htmlspecialchars($currentSettings['email_smtp_host'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="smtp.example.com">
                        </div>
                        <div class="col-md-3">
                            <label for="email_smtp_port" class="form-label">Port</label>
                            <input type="number" name="email_smtp_port" id="email_smtp_port"
                                   class="form-control"
                                   value="<?= htmlspecialchars($currentSettings['email_smtp_port'] ?? '587', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="587" min="1" max="65535">
                        </div>
                        <div class="col-md-3">
                            <label for="email_smtp_secure" class="form-label">Encryption</label>
                            <select name="email_smtp_secure" id="email_smtp_secure" class="form-select">
                                <?php
                                    $smtpSecure = $currentSettings['email_smtp_secure'] ?? 'tls';
                                    foreach ($SMTP_SECURE_OPTIONS as $val => $label):
                                ?>
                                    <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $smtpSecure === $val ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email_smtp_user" class="form-label">Username</label>
                            <input type="text" name="email_smtp_user" id="email_smtp_user"
                                   class="form-control" autocomplete="username"
                                   value="<?= htmlspecialchars($currentSettings['email_smtp_user'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="email_smtp_pass" class="form-label">
                                Password
                                <?php if (!empty($currentSettings['email_smtp_pass'])): ?>
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">saved — leave blank to keep</span>
                                <?php endif; ?>
                            </label>
                            <input type="password" name="email_smtp_pass" id="email_smtp_pass"
                                   class="form-control" autocomplete="new-password"
                                   placeholder="<?= !empty($currentSettings['email_smtp_pass']) ? '••••••••' : 'Enter password' ?>">
                        </div>
                    </div>

                    <!-- feature C — delegate / send-as. Optional: when set,
                         mail is sent FROM this mailbox while AUTH stays on the
                         username above. Requires Send-As granted on the
                         delegate mailbox in the provider's admin console. -->
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="form-text mb-1">
                                <i class="bi bi-person-badge me-1"></i><strong>Send on behalf of a different mailbox (optional).</strong>
                                Leave blank to send as the username above. To send as a shared / delegate
                                mailbox, the username must be granted <em>Send As</em> on it in your provider's
                                admin console (see the setup guide below).
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="email_smtp_from_address" class="form-label">Send-as / From address</label>
                            <input type="email" name="email_smtp_from_address" id="email_smtp_from_address"
                                   class="form-control"
                                   value="<?= htmlspecialchars($currentSettings['email_smtp_from_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="noreply@yourdomain.com">
                        </div>
                        <div class="col-md-6">
                            <label for="email_smtp_from_name" class="form-label">Send-as display name</label>
                            <input type="text" name="email_smtp_from_name" id="email_smtp_from_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($currentSettings['email_smtp_from_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="iHymns">
                        </div>
                    </div>
                </div>

                <!-- #1311 — Microsoft Graph (OAuth2 app-only); M365 + method=OAuth2 API -->
                <div class="email-fields" data-provider-show="office365" data-auth-show="oauth2">
                    <hr class="text-secondary">
                    <h3 class="h6 mb-3"><i class="bi bi-microsoft me-1"></i>Microsoft Graph (OAuth2)</h3>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email_graph_tenant_id" class="form-label">Azure tenant ID</label>
                            <input type="text" name="email_graph_tenant_id" id="email_graph_tenant_id" class="form-control" autocomplete="off"
                                   value="<?= htmlspecialchars($currentSettings['email_graph_tenant_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="00000000-0000-0000-0000-000000000000">
                        </div>
                        <div class="col-md-6">
                            <label for="email_graph_client_id" class="form-label">App (client) ID</label>
                            <input type="text" name="email_graph_client_id" id="email_graph_client_id" class="form-control" autocomplete="off"
                                   value="<?= htmlspecialchars($currentSettings['email_graph_client_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="00000000-0000-0000-0000-000000000000">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email_graph_client_secret" class="form-label">
                                Client secret
                                <?php if (!empty($currentSettings['email_graph_client_secret'])): ?>
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">saved — leave blank to keep</span>
                                <?php endif; ?>
                            </label>
                            <input type="password" name="email_graph_client_secret" id="email_graph_client_secret" class="form-control" autocomplete="new-password"
                                   placeholder="<?= !empty($currentSettings['email_graph_client_secret']) ? '••••••••' : 'client secret VALUE (not the secret ID)' ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="email_graph_sender" class="form-label">Sender mailbox (UPN)</label>
                            <input type="email" name="email_graph_sender" id="email_graph_sender" class="form-control" autocomplete="off"
                                   value="<?= htmlspecialchars($currentSettings['email_graph_sender'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="noreply@yourtenant.com">
                        </div>
                    </div>
                    <div class="form-text">Sends via the <strong>Mail.Send</strong> application permission (admin-consented). No SMTP, no app password — survives Basic-Auth deprecation. See the setup guide below.</div>
                </div>

                <!-- #1311 — Gmail API (OAuth2 service-account + domain-wide delegation); Google + method=OAuth2 API -->
                <div class="email-fields" data-provider-show="gmail" data-auth-show="oauth2">
                    <hr class="text-secondary">
                    <h3 class="h6 mb-3"><i class="bi bi-google me-1"></i>Gmail API (OAuth2 service account)</h3>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email_gmail_sender" class="form-label">Sender mailbox (impersonated)</label>
                            <input type="email" name="email_gmail_sender" id="email_gmail_sender" class="form-control" autocomplete="off"
                                   value="<?= htmlspecialchars($currentSettings['email_gmail_sender'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="noreply@yourdomain.com">
                            <div class="form-text">The Workspace user the service account impersonates (domain-wide delegation).</div>
                        </div>
                        <div class="col-md-6">
                            <label for="email_gmail_sa_json" class="form-label">
                                Service-account JSON key
                                <?php if (!empty($currentSettings['email_gmail_sa_json'])): ?>
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">saved — leave blank to keep</span>
                                <?php endif; ?>
                            </label>
                            <textarea name="email_gmail_sa_json" id="email_gmail_sa_json" class="form-control font-monospace" rows="4" autocomplete="off" spellcheck="false"
                                      placeholder="<?= !empty($currentSettings['email_gmail_sa_json']) ? '•••••••• (saved — paste a new key to replace)' : 'Paste the downloaded service-account .json key file' ?>"></textarea>
                            <div class="form-text">The downloaded key file (contains <code>client_email</code> + <code>private_key</code>). Stored in <code>tblAppSettings</code>; never echoed back to this form.</div>
                        </div>
                    </div>
                    <div class="form-text">Requires the Gmail API enabled + domain-wide delegation for the <code>gmail.send</code> scope. See the setup guide below.</div>
                </div>

                <!-- SendGrid -->
                <div class="email-fields" data-provider-show="sendgrid">
                    <hr class="text-secondary">
                    <h3 class="h6 mb-3"><i class="bi bi-cloud me-1"></i>SendGrid</h3>
                    <div class="mb-3">
                        <label for="email_sendgrid_api_key" class="form-label">
                            API key
                            <?php if (!empty($currentSettings['email_sendgrid_api_key'])): ?>
                                <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">saved — leave blank to keep</span>
                            <?php endif; ?>
                        </label>
                        <input type="password" name="email_sendgrid_api_key" id="email_sendgrid_api_key"
                               class="form-control" autocomplete="off"
                               placeholder="<?= !empty($currentSettings['email_sendgrid_api_key']) ? '••••••••' : 'SG.xxxxxxxx' ?>">
                    </div>
                </div>

                <!-- Mailgun -->
                <div class="email-fields" data-provider-show="mailgun">
                    <hr class="text-secondary">
                    <h3 class="h6 mb-3"><i class="bi bi-cloud me-1"></i>Mailgun</h3>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email_mailgun_domain" class="form-label">Sending domain</label>
                            <input type="text" name="email_mailgun_domain" id="email_mailgun_domain"
                                   class="form-control"
                                   value="<?= htmlspecialchars($currentSettings['email_mailgun_domain'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="mg.yourdomain.com">
                        </div>
                        <div class="col-md-6">
                            <label for="email_mailgun_api_key" class="form-label">
                                API key
                                <?php if (!empty($currentSettings['email_mailgun_api_key'])): ?>
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">saved — leave blank to keep</span>
                                <?php endif; ?>
                            </label>
                            <input type="password" name="email_mailgun_api_key" id="email_mailgun_api_key"
                                   class="form-control" autocomplete="off"
                                   placeholder="<?= !empty($currentSettings['email_mailgun_api_key']) ? '••••••••' : 'key-xxxxxxxx' ?>">
                        </div>
                    </div>
                </div>

                <!-- AWS SES -->
                <div class="email-fields" data-provider-show="ses">
                    <hr class="text-secondary">
                    <h3 class="h6 mb-3"><i class="bi bi-cloud me-1"></i>AWS SES</h3>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="email_ses_region" class="form-label">Region</label>
                            <input type="text" name="email_ses_region" id="email_ses_region"
                                   class="form-control"
                                   value="<?= htmlspecialchars($currentSettings['email_ses_region'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="eu-west-1">
                        </div>
                        <div class="col-md-4">
                            <label for="email_ses_access_key" class="form-label">
                                Access key ID
                                <?php if (!empty($currentSettings['email_ses_access_key'])): ?>
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">saved</span>
                                <?php endif; ?>
                            </label>
                            <input type="password" name="email_ses_access_key" id="email_ses_access_key"
                                   class="form-control" autocomplete="off"
                                   placeholder="<?= !empty($currentSettings['email_ses_access_key']) ? '••••••••' : 'AKIA…' ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="email_ses_secret_key" class="form-label">
                                Secret access key
                                <?php if (!empty($currentSettings['email_ses_secret_key'])): ?>
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">saved</span>
                                <?php endif; ?>
                            </label>
                            <input type="password" name="email_ses_secret_key" id="email_ses_secret_key"
                                   class="form-control" autocomplete="off"
                                   placeholder="<?= !empty($currentSettings['email_ses_secret_key']) ? '••••••••' : 'secret' ?>">
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save email configuration
                    </button>
                    <button type="submit" name="action" value="test_email" class="btn btn-outline-info"
                            <?= $currentService === 'none' ? 'disabled title="Configure a provider first"' : '' ?>>
                        <i class="bi bi-send me-1"></i>Send test email
                    </button>
                </div>
            </form>

            <?php if ($testResult !== null): ?>
                <div class="alert <?= $testResult['ok'] ? 'alert-success' : 'alert-warning' ?> mt-3 mb-0">
                    <i class="bi bi-<?= $testResult['ok'] ? 'check-circle' : 'info-circle' ?> me-1"></i>
                    <?= htmlspecialchars($testResult['message'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===========================
         STEP-BY-STEP INSTRUCTIONS
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header">
            <h2 class="h5 mb-0">
                <i class="bi bi-book me-2"></i>Step-by-step provider setup
            </h2>
        </div>
        <div class="card-body">
            <div class="accordion" id="email-instructions">
                <!-- #1311 — Microsoft Graph (OAuth2, recommended for M365) -->
                <div class="accordion-item bg-dark">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed bg-dark text-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#instr-graph">
                            <i class="bi bi-microsoft me-2"></i>OAuth2 — Microsoft 365 via Graph (recommended; no SMTP)
                        </button>
                    </h3>
                    <div id="instr-graph" class="accordion-collapse collapse" data-bs-parent="#email-instructions">
                        <div class="accordion-body small">
                            <p>Pick provider <strong>Microsoft 365</strong>, then <strong>Authentication method → OAuth2 API</strong>. No SMTP host or app password — works even when SMTP AUTH is disabled on the tenant.</p>
                            <ol class="mb-2">
                                <li><strong>Register an app.</strong> <a href="https://entra.microsoft.com" target="_blank" rel="noopener">Microsoft Entra admin centre</a> → <em>Applications → App registrations → New registration</em>. Copy the <strong>Application (client) ID</strong> and <strong>Directory (tenant) ID</strong> into the fields above.</li>
                                <li><strong>Grant the application permission.</strong> <em>API permissions → Add a permission → Microsoft Graph → Application permissions → Mail.Send</em>, then <strong>Grant admin consent</strong>. (Application — <em>not</em> Delegated.)</li>
                                <li><strong>Create a client secret.</strong> <em>Certificates &amp; secrets → New client secret</em>. Copy the secret <strong>Value</strong> (not the Secret ID) into <strong>Client secret</strong> — it's shown only once.</li>
                                <li><strong>Sender mailbox (UPN)</strong> = the mailbox the app sends as (e.g. <code>noreply@yourtenant.com</code>). App-only Mail.Send can reach any mailbox; restrict it with an <a href="https://learn.microsoft.com/graph/auth-limit-mailbox-access" target="_blank" rel="noopener">application access policy</a> if desired.</li>
                                <li><strong>Save</strong>, then <strong>Send test email</strong>.</li>
                            </ol>
                            <p class="text-secondary mb-0"><strong>If it fails:</strong> <em>token_http_401</em> → wrong tenant / client / secret; <em>http_403</em> → admin consent missing or an access policy blocks the sender; <em>http_400</em> → the From differs from the sender mailbox without Send-As.</p>
                        </div>
                    </div>
                </div>

                <!-- #1311 — Gmail API (OAuth2, recommended for Google Workspace) -->
                <div class="accordion-item bg-dark">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed bg-dark text-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#instr-gmail-api">
                            <i class="bi bi-google me-2"></i>OAuth2 — Google Workspace via Gmail API (recommended; no SMTP)
                        </button>
                    </h3>
                    <div id="instr-gmail-api" class="accordion-collapse collapse" data-bs-parent="#email-instructions">
                        <div class="accordion-body small">
                            <p>Pick provider <strong>Google Workspace / Gmail</strong>, then <strong>Authentication method → OAuth2 API</strong>. Uses a service account with domain-wide delegation — no app password, no per-user OAuth dance.</p>
                            <ol class="mb-2">
                                <li><strong>Create a service account + JSON key.</strong> <a href="https://console.cloud.google.com" target="_blank" rel="noopener">Google Cloud console</a> → a project → <em>IAM &amp; Admin → Service accounts → Create</em>, then <em>Keys → Add key → JSON</em> and download the key file.</li>
                                <li><strong>Enable the Gmail API</strong> on the project (<em>APIs &amp; Services → Enable APIs → Gmail API</em>).</li>
                                <li><strong>Authorise domain-wide delegation.</strong> Copy the service account's <em>Client ID</em>, then in the <a href="https://admin.google.com" target="_blank" rel="noopener">Workspace Admin console</a> → <em>Security → Access and data control → API controls → Domain-wide delegation → Add new</em>, paste the Client ID and the scope <code>https://www.googleapis.com/auth/gmail.send</code>.</li>
                                <li><strong>Paste the JSON key</strong> into <strong>Service-account JSON key</strong> above, and set <strong>Sender mailbox</strong> to the Workspace user to impersonate.</li>
                                <li><strong>Save</strong>, then <strong>Send test email</strong>.</li>
                            </ol>
                            <p class="text-secondary mb-0"><strong>If it fails:</strong> <em>invalid_service_account_json</em> → the pasted key is malformed; <em>token_http_400 / unauthorized_client</em> → domain-wide delegation not authorised for the scope (or wrong Client ID); <em>http_403</em> → Gmail API not enabled, or the sender isn't a real mailbox.</p>
                        </div>
                    </div>
                </div>

                <!-- Microsoft 365 (priority) -->
                <div class="accordion-item bg-dark">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed bg-dark text-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#instr-m365">
                            <i class="bi bi-microsoft me-2"></i>SMTP — Microsoft 365 (recommended)
                        </button>
                    </h3>
                    <div id="instr-m365" class="accordion-collapse collapse" data-bs-parent="#email-instructions">
                        <div class="accordion-body small">
                            <p>Pick provider <strong>Microsoft 365 (Exchange Online)</strong> — that fills
                               host <code>smtp.office365.com</code>, port <code>587</code>, encryption <code>STARTTLS</code>.</p>
                            <ol class="mb-2">
                                <li><strong>Enable SMTP AUTH on the sending mailbox.</strong> In the
                                    <a href="https://admin.microsoft.com" target="_blank" rel="noopener">Microsoft 365 admin centre</a> →
                                    <em>Users → Active users → (the mailbox) → Mail → Manage email apps</em>, tick
                                    <em>Authenticated SMTP</em>. SMTP AUTH is off by default on most tenants.</li>
                                <li><strong>Create an app password.</strong> M365 requires MFA, and basic SMTP can't do an
                                    interactive MFA prompt, so generate an app password for the mailbox at
                                    <a href="https://mysignins.microsoft.com/security-info" target="_blank" rel="noopener">My Sign-Ins → Security info → Add → App password</a>.
                                    Use that as the SMTP <strong>password</strong> here (your normal password will be rejected).</li>
                                <li><strong>Username</strong> = the mailbox you authenticate as (e.g. <code>automation@yourtenant.com</code>).</li>
                                <li><strong>Send-as a different mailbox (optional).</strong> To send from, say,
                                    <code>noreply@yourtenant.com</code> while authenticating as <code>automation@…</code>,
                                    grant the automation account <em>Send As</em> on the target in the
                                    <a href="https://admin.exchange.microsoft.com" target="_blank" rel="noopener">Exchange admin centre</a> →
                                    <em>Recipients → Mailboxes → (target) → Delegation → Send as</em>, then put the target
                                    address in <strong>Send-as / From address</strong> above.</li>
                                <li><strong>Save</strong>, then <strong>Send test email</strong> to confirm.</li>
                            </ol>
                            <p class="text-secondary mb-0"><strong>If Send test fails:</strong>
                               <em>auth_failed</em> → SMTP AUTH not enabled, or you used the account password instead of an app password.
                               <em>mail_from_rejected</em> / <em>rcpt</em> with a 550 → the Send-as address isn't authorised for the login mailbox.</p>
                        </div>
                    </div>
                </div>

                <!-- Google Workspace -->
                <div class="accordion-item bg-dark">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed bg-dark text-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#instr-gws">
                            <i class="bi bi-google me-2"></i>SMTP — Google Workspace / Gmail
                        </button>
                    </h3>
                    <div id="instr-gws" class="accordion-collapse collapse" data-bs-parent="#email-instructions">
                        <div class="accordion-body small">
                            <p>Pick provider <strong>Google Workspace / Gmail</strong> — that fills
                               host <code>smtp.gmail.com</code>, port <code>587</code>, encryption <code>STARTTLS</code>.</p>
                            <ol class="mb-2">
                                <li><strong>Turn on 2-Step Verification</strong> on the sending account (required before
                                    you can create an app password).</li>
                                <li><strong>Create an app password</strong> at
                                    <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a>
                                    and use it as the SMTP <strong>password</strong> here. A normal account password is rejected
                                    over SMTP.</li>
                                <li><strong>Username</strong> = the full Google Workspace address (e.g. <code>automation@yourdomain.com</code>).</li>
                                <li><strong>Allow SMTP for the org (if needed).</strong> A Workspace admin can confirm SMTP
                                    sending is permitted in the
                                    <a href="https://admin.google.com" target="_blank" rel="noopener">Google Admin console</a>
                                    (<em>Apps → Google Workspace → Gmail → End User Access</em>); some tenants block app passwords entirely.</li>
                                <li><strong>Send-as a different address (optional).</strong> Gmail can send as an alias or a
                                    delegated address only if it's been added under
                                    <a href="https://mail.google.com/mail/u/0/#settings/accounts" target="_blank" rel="noopener">Gmail Settings → Accounts → Send mail as</a>
                                    (verified), or granted to the account in the Admin console. Put that address in
                                    <strong>Send-as / From address</strong> above; unverified send-as addresses are silently
                                    rewritten by Gmail to the login address.</li>
                                <li><strong>Save</strong>, then <strong>Send test email</strong>.</li>
                            </ol>
                            <p class="text-secondary mb-0"><strong>If Send test fails with</strong> <em>auth_failed</em>:
                               2-Step Verification / app password isn't set up, or the org blocks app passwords.</p>
                        </div>
                    </div>
                </div>

                <!-- Custom SMTP + future-direction note -->
                <div class="accordion-item bg-dark">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed bg-dark text-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#instr-smtp">
                            <i class="bi bi-server me-2"></i>SMTP — any other provider (custom)
                        </button>
                    </h3>
                    <div id="instr-smtp" class="accordion-collapse collapse" data-bs-parent="#email-instructions">
                        <div class="accordion-body small">
                            <p>SMTP works with any provider that exposes an authenticated relay endpoint. Choose the
                               <strong>Custom / manual</strong> preset and enter the details by hand:</p>
                            <ul class="mb-2">
                                <li><strong>Zoho Mail</strong> — host <code>smtp.zoho.com</code>, port <code>587</code> (STARTTLS) or <code>465</code> (SSL).</li>
                                <li><strong>Fastmail</strong> — host <code>smtp.fastmail.com</code>, port <code>465</code>, <code>SSL/TLS</code>. Use an app-specific password.</li>
                                <li><strong>Mailgun SMTP relay</strong> — host <code>smtp.mailgun.org</code>, port <code>587</code>. Credentials are under <em>Sending → Domain settings → SMTP credentials</em>.</li>
                            </ul>
                            <p class="mb-2">Set the <strong>From address</strong> to a mailbox the provider lets you send from.
                               Use the optional <strong>Send-as / From address</strong> when the message should appear from a
                               delegate mailbox the login account is authorised to send as.</p>
                            <p class="text-secondary mb-2"><strong>Tip:</strong> if Send test fails with <em>auth_failed</em>,
                               the username / password is wrong or the provider hasn't enabled SMTP AUTH. <em>mail_from_rejected</em>
                               / <em>relay denied</em> means the From / Send-as address isn't authorised for that login.</p>
                            <p class="text-info mb-0"><i class="bi bi-info-circle me-1"></i><strong>Future direction:</strong>
                               this uses SMTP AUTH with an app password + delegate today. OAuth2 sign-in (and the planned
                               <em>MailerMatt</em> delivery service) is the eventual path — not built yet; SMTP AUTH is the
                               supported mechanism for now.</p>
                        </div>
                    </div>
                </div>

                <!-- SendGrid -->
                <div class="accordion-item bg-dark">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed bg-dark text-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#instr-sendgrid">
                            <i class="bi bi-cloud me-2"></i>SendGrid — API key
                        </button>
                    </h3>
                    <div id="instr-sendgrid" class="accordion-collapse collapse" data-bs-parent="#email-instructions">
                        <div class="accordion-body small">
                            <ol class="mb-2">
                                <li>Sign in to <a href="https://app.sendgrid.com" target="_blank" rel="noopener">app.sendgrid.com</a>.</li>
                                <li>Open <strong>Settings → API Keys → Create API Key</strong>.</li>
                                <li>Name it <code>iHymns transactional</code> and grant the <strong>Mail Send</strong> permission only (least privilege).</li>
                                <li>Copy the key (it's shown once). Paste it into <strong>API key</strong> here.</li>
                                <li>Verify your sender domain under <strong>Settings → Sender Authentication</strong> — needed for the From address to pass DMARC.</li>
                                <li>Save configuration; <strong>Send test email</strong>.</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- Mailgun -->
                <div class="accordion-item bg-dark">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed bg-dark text-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#instr-mailgun">
                            <i class="bi bi-cloud me-2"></i>Mailgun — API key + verified domain
                        </button>
                    </h3>
                    <div id="instr-mailgun" class="accordion-collapse collapse" data-bs-parent="#email-instructions">
                        <div class="accordion-body small">
                            <ol class="mb-2">
                                <li>Sign in to <a href="https://app.mailgun.com" target="_blank" rel="noopener">app.mailgun.com</a> and open <strong>Sending → Domains</strong>.</li>
                                <li>Add and verify a domain (typically a subdomain like <code>mg.yourdomain.com</code>) — set the SPF, DKIM, and MX records Mailgun lists; the verification step polls DNS until it sees them.</li>
                                <li>From <strong>Sending → API Keys</strong>, copy your private API key.</li>
                                <li>Paste the API key + the verified domain into the form here.</li>
                                <li>Save; Send test.</li>
                            </ol>
                            <p class="text-secondary mb-0"><strong>EU vs US region:</strong> Mailgun has separate API endpoints for EU (<code>api.eu.mailgun.net</code>) and US (<code>api.mailgun.net</code>). The sender will infer the right one from your domain's region — no extra field here.</p>
                        </div>
                    </div>
                </div>

                <!-- SES -->
                <div class="accordion-item bg-dark">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed bg-dark text-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#instr-ses">
                            <i class="bi bi-cloud me-2"></i>AWS SES — IAM user + verified identity
                        </button>
                    </h3>
                    <div id="instr-ses" class="accordion-collapse collapse" data-bs-parent="#email-instructions">
                        <div class="accordion-body small">
                            <ol class="mb-2">
                                <li>Sign in to the <a href="https://console.aws.amazon.com/ses/" target="_blank" rel="noopener">SES console</a>.</li>
                                <li>Verify the From address or its domain under <strong>Verified identities</strong>. SES starts in sandbox mode (you can only send to verified addresses); request production access via <strong>Account dashboard → Request production access</strong> when you're ready.</li>
                                <li>In <strong>IAM</strong>, create a new user with programmatic access. Attach a custom policy:
                                    <pre class="mb-0 mt-2"><code>{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": [ "ses:SendEmail", "ses:SendRawEmail" ],
    "Resource": "*"
  }]
}</code></pre>
                                </li>
                                <li>Copy the access key ID + secret access key shown at user-creation time (the secret is shown once).</li>
                                <li>Paste them here along with the SES region (e.g. <code>eu-west-1</code>, <code>us-east-1</code>).</li>
                                <li>Save; Send test.</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

<script>
(function () {
    'use strict';
    /* Show / hide the per-provider field groups based on the current
       Provider dropdown value. The `data-provider-show` attribute
       carries a comma-separated list of provider keys that the group
       should be visible for; if it includes the current value the
       group renders, otherwise it's hidden. (#768) */
    const providerSel = document.querySelector('[data-email-provider]');
    const groups = document.querySelectorAll('[data-provider-show]');
    if (!providerSel || groups.length === 0) return;
    /* #1311 — second visibility axis: the auth-method selector (SMTP-AUTH vs
       OAuth2 API), which only exists for the office365/gmail providers. A group
       tagged data-auth-show is shown only when its method matches; groups with
       no data-auth-show ignore the axis. For any provider WITHOUT an auth-method
       selector (smtp/sendgrid/…), the method is implicitly 'smtp'. */
    const authSel = document.querySelector('[data-email-auth-method]');

    const apply = () => {
        const current = providerSel.value;
        const hasAuthAxis = (current === 'office365' || current === 'gmail');
        const method = (hasAuthAxis && authSel) ? authSel.value : 'smtp';
        groups.forEach((g) => {
            const allowed = (g.dataset.providerShow || '').split(',').map(s => s.trim());
            let visible = allowed.includes(current);
            if (visible && g.dataset.authShow !== undefined) {
                const authAllowed = (g.dataset.authShow || '').split(',').map(s => s.trim());
                visible = authAllowed.includes(method);
            }
            g.style.display = visible ? '' : 'none';
            /* Disable inputs in hidden groups so the form doesn't
               submit stale values when the admin switches provider /
               method and saves. */
            g.querySelectorAll('input, select, textarea').forEach((inp) => {
                if (visible) {
                    inp.removeAttribute('disabled');
                } else {
                    inp.setAttribute('disabled', 'disabled');
                }
            });
        });
    };
    providerSel.addEventListener('change', apply);
    if (authSel) { authSel.addEventListener('change', apply); }
    apply();
})();

(function () {
    'use strict';
    /* feature C — SMTP provider preset. Selecting Microsoft 365 or Google
       Workspace fills the host / port / encryption fields from the JSON
       island so admins don't memorise endpoints. 'Custom / manual' leaves
       the fields untouched. The fields stay editable after pre-fill — the
       preset is a convenience, not a lock. */
    const presetSel = document.querySelector('[data-smtp-preset]');
    const dataEl    = document.querySelector('[data-smtp-presets]');
    if (!presetSel || !dataEl) return;

    let presets = {};
    try {
        presets = JSON.parse(dataEl.textContent || '{}');
    } catch (e) {
        return; /* malformed JSON — leave manual entry working */
    }

    const hostEl   = document.getElementById('email_smtp_host');
    const portEl   = document.getElementById('email_smtp_port');
    const secureEl = document.getElementById('email_smtp_secure');

    const fill = (cfg) => {
        /* 'custom' (and any unknown key) has empty host/port/secure — skip
           so we never wipe what the admin already typed. */
        if (!cfg || !cfg.host) return;
        if (hostEl)   hostEl.value = cfg.host;
        if (portEl)   portEl.value = cfg.port;
        if (secureEl && cfg.secure) secureEl.value = cfg.secure;
    };
    presetSel.addEventListener('change', () => fill(presets[presetSel.value]));

    /* #1309 — Microsoft 365 / Google Workspace are now first-class PROVIDERS.
       Their email_service value matches a preset key, so when the admin picks
       one we apply the same host/port/encryption pre-fill. Only on an explicit
       change (never on initial load) so a previously-saved host isn't clobbered
       when the page renders with the provider already selected. */
    const providerSel = document.querySelector('[data-email-provider]');
    if (providerSel) {
        providerSel.addEventListener('change', () => fill(presets[providerSel.value]));
    }
})();
</script>
</body>
</html>
