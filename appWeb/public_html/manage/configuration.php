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
 * The send-mechanism (PHPMailer / curl-based SMTP) is OUT OF SCOPE
 * for this PR — the configuration storage is in place so whoever
 * implements the sender hits a populated tblAppSettings on day one.
 * The "Send test email" button stub is wired but reports "Not yet
 * implemented" so admins know what to expect.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

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
    'email_from_address'        => ['From address',              'email',  false, ['smtp','sendgrid','mailgun','ses']],
    'email_from_name'           => ['From name',                 'text',   false, ['smtp','sendgrid','mailgun','ses']],
    'email_smtp_host'           => ['SMTP host',                 'text',   false, ['smtp']],
    'email_smtp_port'           => ['SMTP port',                 'number', false, ['smtp']],
    'email_smtp_user'           => ['SMTP username',             'text',   false, ['smtp']],
    'email_smtp_pass'           => ['SMTP password',             'password', true, ['smtp']],
    'email_smtp_secure'         => ['SMTP encryption',           'select', false, ['smtp']],
    'email_sendgrid_api_key'    => ['SendGrid API key',          'password', true, ['sendgrid']],
    'email_mailgun_api_key'     => ['Mailgun API key',           'password', true, ['mailgun']],
    'email_mailgun_domain'      => ['Mailgun domain',            'text',   false, ['mailgun']],
    'email_ses_region'          => ['AWS region (e.g. eu-west-1)', 'text', false, ['ses']],
    'email_ses_access_key'      => ['AWS access key',            'password', true, ['ses']],
    'email_ses_secret_key'      => ['AWS secret key',            'password', true, ['ses']],
];

$EMAIL_SERVICE_OPTIONS = [
    'none'     => 'None — email login disabled',
    'smtp'     => 'SMTP (any provider with SMTP relay)',
    'sendgrid' => 'SendGrid',
    'mailgun'  => 'Mailgun',
    'ses'      => 'AWS SES',
];

$SMTP_SECURE_OPTIONS = [
    'tls'  => 'STARTTLS (port 587)',
    'ssl'  => 'SSL/TLS implicit (port 465)',
    'none' => 'None (port 25 — not recommended)',
];

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
$testResult  = null;   /* ['ok' => bool, 'message' => string]|null */

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
            } catch (\Throwable $e) {
                error_log('[manage configuration save_email] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'test_email') {
            /* Stub: until the actual sender (PHPMailer / curl-SMTP) is
               wired up, just acknowledge and surface the configured
               provider so the admin can verify their save round-tripped.
               The "Not yet implemented" copy below sets expectations
               clearly — see #768's out-of-scope section. */
            $current = $loadSettings($db, ['email_service']);
            $service = $current['email_service'] ?? 'none';
            $testResult = [
                'ok'      => false,
                'message' => 'Send-test stub: provider is "' . $service . '". '
                           . 'Send-mechanism implementation is tracked separately — '
                           . 'configuration is persisted and ready for the sender to consume.',
            ];
        }
    }
}

/* ----------------------------------------------------------------------
 * Read current settings (after any save)
 * ---------------------------------------------------------------------- */
$currentSettings = $loadSettings($db, array_keys($EMAIL_SETTINGS));
$currentService  = $currentSettings['email_service'] ?? 'none';

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration — iHymns Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="/css/admin.css">
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
                <div class="email-fields" data-provider-show="smtp,sendgrid,mailgun,ses">
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

                <!-- SMTP-specific -->
                <div class="email-fields" data-provider-show="smtp">
                    <hr class="text-secondary">
                    <h3 class="h6 mb-3"><i class="bi bi-server me-1"></i>SMTP server</h3>
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
                <!-- SMTP -->
                <div class="accordion-item bg-dark">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed bg-dark text-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#instr-smtp">
                            <i class="bi bi-server me-2"></i>SMTP — any provider with SMTP relay
                        </button>
                    </h3>
                    <div id="instr-smtp" class="accordion-collapse collapse" data-bs-parent="#email-instructions">
                        <div class="accordion-body small">
                            <p>SMTP is the most portable option — works with any provider that exposes a relay endpoint.</p>
                            <ol class="mb-2">
                                <li><strong>Pick a provider</strong> and grab its SMTP details. Common ones:
                                    <ul>
                                        <li><strong>Gmail / Google Workspace</strong> — host <code>smtp.gmail.com</code>, port <code>587</code>, encryption <code>STARTTLS</code>. Username = your Google address, password = an <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">App Password</a> (regular password is rejected). Requires 2FA on the account.</li>
                                        <li><strong>Microsoft 365</strong> — host <code>smtp.office365.com</code>, port <code>587</code>, <code>STARTTLS</code>. Same account credentials; admin must enable SMTP AUTH on the mailbox.</li>
                                        <li><strong>Zoho Mail</strong> — host <code>smtp.zoho.com</code>, port <code>587</code> (TLS) or <code>465</code> (SSL).</li>
                                        <li><strong>Fastmail</strong> — host <code>smtp.fastmail.com</code>, port <code>465</code>, <code>SSL/TLS</code>. Use an app-specific password.</li>
                                        <li><strong>Mailgun SMTP relay</strong> — host <code>smtp.mailgun.org</code>, port <code>587</code>. Username + password are listed under <em>Sending → Domain settings → SMTP credentials</em> for each domain.</li>
                                    </ul>
                                </li>
                                <li><strong>Set the From address</strong> to a mailbox the provider allows you to send from (usually a domain you own + verified).</li>
                                <li><strong>Save configuration</strong> here. Use <strong>Send test email</strong> to verify (once the sender is implemented; tracked separately).</li>
                            </ol>
                            <p class="text-secondary mb-0"><strong>Tip:</strong> if Send test fails with <em>auth failed</em>, the username/password is wrong or the provider hasn't enabled SMTP for the account. If it fails with <em>relay denied</em>, the From address isn't verified for that account.</p>
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

    const apply = () => {
        const current = providerSel.value;
        groups.forEach((g) => {
            const allowed = (g.dataset.providerShow || '').split(',').map(s => s.trim());
            const visible = allowed.includes(current);
            g.style.display = visible ? '' : 'none';
            /* Disable inputs in hidden groups so the form doesn't
               submit stale values when the admin switches provider
               and saves. */
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
    apply();
})();
</script>
</body>
</html>
