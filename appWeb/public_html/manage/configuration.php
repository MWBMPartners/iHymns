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
/* #1466 — load the secret-encryption engine (secret_crypto.php) and getAppSetting()
   (maintenance.php) BEFORE the POST handlers run. $saveSetting's encrypt-on-save and
   its `secret_encryption_active` cutover-flag read both depend on these; without the
   top-level load they were dead code (the gate short-circuited on function_exists and
   silently stored secrets as plaintext once encryption is active — #1466 review). The
   engine is required HARD here on purpose: this admin page's save path must fail CLOSED
   (throw), never silently write a cleartext secret. maintenance.php loads it defensively
   for the public read path; this page needs the stronger guarantee. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'secret_crypto.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';
/* #1470 W1 — needed for the IHYMNS_SIWA_CLIENT_ID constant (the save_apple
   handler below rejects a pasted Services ID that matches the native App
   ID) and for the two new appleWebLoginEnabledForChannel()-adjacent
   settings this card now manages. No DB/network side effect at require
   time — see that file's own top-of-file docblock. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'apple_siwa.php';

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
        $rawVal = (string)$r['SettingValue'];
        /* Decrypt secret-flagged values (#1466) so the form's set/not-set state
           and any non-secret prefill are correct whether the DB holds plaintext
           (pre-cutover) or an enc:v1 envelope (post). Passthrough for plaintext;
           an undecryptable envelope → '' (renders as "not set"). Secret VALUES
           are still never echoed back into the form. */
        if (secretIsEncrypted($rawVal)) {
            $out[$r['SettingKey']] = secretDecrypt($rawVal) ?? '';
        } else {
            $out[$r['SettingKey']] = $rawVal;
        }
    }
    return $out;
};

$saveSetting = function (mysqli $db, string $key, string $value): void {
    /* Encrypt-at-rest for secret-flagged settings (#1466). Gated on the cutover
       flag `secret_encryption_active` (set by the encrypt-in-place migration once
       every docroot has the engine + key) so this is a verified NO-OP until then:
       pre-cutover, secrets store as plaintext exactly as before; the migration
       encrypts them in bulk and flips the flag; thereafter every new/updated
       secret is encrypted here too. secret_crypto.php is required HARD at the top
       of this page, so isSecretSettingKey()/secretEncrypt()/secretCryptoReady()
       are GUARANTEED defined here — the gate deliberately carries NO
       function_exists() guard: guarding on it would fail OPEN (silently store
       plaintext) if the engine were ever missing. Instead we fail CLOSED — a
       non-empty secret can NEVER be silently stored as plaintext once encryption
       is active: if the master key is missing on this docroot we throw rather
       than write a cleartext secret. */
    /* #1671 F6 — the rule itself now lives in the PURE
       appSettingValueForStorage() (includes/secret_crypto.php) because a second
       page (manage/notifications.php, storing the VAPID private key) needed the
       identical decision, and a second copy of "encrypt secrets at rest" is the
       kind of duplication whose divergence is invisible until a secret is
       sitting in the database in the clear. Behaviour here is UNCHANGED
       byte-for-byte — including the deliberate absence of a function_exists()
       guard, which is what makes it fail CLOSED. */
    $value = appSettingValueForStorage(
        $key,
        $value,
        getAppSetting('secret_encryption_active', '0') === '1'
    );
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
        } elseif ($action === 'save_apple') {
            /* Apple native app — Team ID (#1401) + Sign in with Apple Key ID
               and .p8 private key (#1402). Three tblAppSettings rows, no
               schema change:
                 - apple_team_id           (#1401, existing) — the AASA
                   responder (.well-known/apple-app-site-association.php)
                   reads it via getAppSetting('apple_team_id', null) and
                   falls back to an obviously-fake 'TEAMID' placeholder when
                   unset.
                 - apple_siwa_key_id       (#1402, NEW) — the SIWA key's Key
                   ID, used as the ES256 client_secret's `kid` header
                   (includes/apple_siwa.php::appleSiwaBuildClientSecret()).
                 - apple_siwa_private_key  (#1402, NEW, SECRET) — the
                   downloaded .p8's raw contents. auth_apple's RS256
                   identity-token VERIFY needs NONE of these three (Apple's
                   JWKS is public) — only the best-effort refresh-token
                   exchange (auth_apple) and Apple-side revoke
                   (account_delete) need the trio, and both degrade
                   gracefully (skip the step) while it is unset, so sign-in
                   and deletion both keep working the moment this card is
                   saved with just the Team ID, or before it is saved at all.
               This is the ONE place the owner sets any of them — never
               hard-code an Apple credential in source. */
            try {
                /* Apple Team IDs / Key IDs are exactly 10 uppercase
                   alphanumeric characters (e.g. "ABCDE12345") — validate the
                   shape so a pasted mistake (extra whitespace, a bundle id
                   instead of the Team ID, etc.) is caught here rather than
                   silently producing a broken AASA appID / client_secret.
                   Empty clears the setting back to the "unset" state. */
                $teamIdVal = strtoupper(trim((string)($_POST['apple_team_id'] ?? '')));
                if ($teamIdVal !== '' && !preg_match('/^[A-Z0-9]{10}$/', $teamIdVal)) {
                    throw new \RuntimeException('Apple Team ID must be exactly 10 letters/digits (e.g. "ABCDE12345"), or left blank.');
                }

                $keyIdVal = strtoupper(trim((string)($_POST['apple_siwa_key_id'] ?? '')));
                if ($keyIdVal !== '' && !preg_match('/^[A-Z0-9]{10}$/', $keyIdVal)) {
                    throw new \RuntimeException('SIWA Key ID must be exactly 10 letters/digits (e.g. "ABCDE12345"), or left blank.');
                }

                /* Secret field — a BLANK submission means "leave the
                   existing value alone" (the form never echoes the current
                   key back), mirroring the email_gmail_sa_json convention
                   elsewhere on this page. A NON-blank value must parse as a
                   PKCS#8 EC PRIVATE KEY on the P-256 curve — that is exactly
                   the shape Apple issues a "Sign in with Apple" key as;
                   anything else (an RSA key, a public key, the DIFFERENT
                   App Store Connect API deploy key, garbage) is rejected
                   with a specific message so a paste mistake is caught here
                   rather than surfacing later as a silent
                   skipped_no_key/"could not build client secret" on
                   production. */
                $privateKeyRaw = (string)($_POST['apple_siwa_private_key'] ?? '');
                $privateKeyVal = null; /* null = "don't touch the stored value" */
                if (trim($privateKeyRaw) !== '') {
                    $candidate = trim($privateKeyRaw);
                    if (!str_starts_with($candidate, '-----BEGIN PRIVATE KEY-----')) {
                        throw new \RuntimeException('SIWA private key must be the raw .p8 file contents, starting with "-----BEGIN PRIVATE KEY-----" (paste the ENTIRE downloaded file, including the BEGIN/END lines).');
                    }
                    $parsedKey = @openssl_pkey_get_private($candidate);
                    if ($parsedKey === false) {
                        throw new \RuntimeException('SIWA private key could not be parsed — check you pasted the complete, unmodified .p8 file contents.');
                    }
                    $keyDetails = @openssl_pkey_get_details($parsedKey);
                    $curveName  = is_array($keyDetails) ? ($keyDetails['ec']['curve_name'] ?? null) : null;
                    $isEcP256   = is_array($keyDetails)
                        && ($keyDetails['type'] ?? null) === OPENSSL_KEYTYPE_EC
                        && ($curveName === 'prime256v1' || $curveName === 'secp256r1');
                    if (!$isEcP256) {
                        throw new \RuntimeException('SIWA private key must be an EC P-256 key (the .p8 Apple issues for a "Sign in with Apple" key) — this parsed as a different key type. Do NOT paste the App Store Connect API deploy key (APPLE_ASC_KEY_P8) here; that is a separate, unrelated key.');
                    }
                    $privateKeyVal = $candidate;
                }

                /* #1470 W1 — Sign in with Apple for WEB. Two NON-secret
                   companion settings: the Services ID is public-by-definition
                   (same visibility class as the native bundle id above), and
                   the channel allow-list is just a rollout dial — neither
                   needs the secret=true / blank-means-keep convention the
                   private key above uses; both are safe to echo back into
                   the form and are saved every submit (never "leave blank to
                   keep"), matching apple_team_id/apple_siwa_key_id's own
                   always-overwrite behaviour just above. */
                $servicesIdVal = trim((string)($_POST['apple_siwa_services_id'] ?? ''));
                if ($servicesIdVal !== '') {
                    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,254}$/', $servicesIdVal)) {
                        throw new \RuntimeException('Apple Services ID doesn\'t look like a valid identifier (letters/digits/dot/hyphen, starting with a letter or digit, e.g. "app.ihymns.web").');
                    }
                    if ($servicesIdVal === IHYMNS_SIWA_CLIENT_ID) {
                        throw new \RuntimeException('That is the NATIVE App ID ("' . IHYMNS_SIWA_CLIENT_ID . '") — Sign in with Apple for web needs a SEPARATE Services ID (e.g. "app.ihymns.web"), not the App ID reused here.');
                    }
                }

                /* Channel allow-list — each comma-separated token must be one
                   of the three canonical channels (ihymns_environment()'s
                   return values) or the single literal "all". Anything else
                   (a typo, a stray docroot hostname pasted here by mistake)
                   is rejected outright rather than silently ignored, since a
                   token that never matches would look "saved" but do nothing. */
                $webLoginEnabledVal = trim((string)($_POST['apple_web_login_enabled'] ?? ''));
                if ($webLoginEnabledVal !== '') {
                    $validChannelTokens = ['alpha', 'beta', 'production', 'all'];
                    $webChannelTokens = array_values(array_filter(array_map('trim', explode(',', $webLoginEnabledVal)), static fn(string $t): bool => $t !== ''));
                    foreach ($webChannelTokens as $tok) {
                        if (!in_array(strtolower($tok), $validChannelTokens, true)) {
                            throw new \RuntimeException('Web login channel allow-list must be a comma-separated list of "alpha", "beta", "production", or "all" — "' . $tok . '" is none of those.');
                        }
                    }
                    /* Normalise to lowercase for storage — appleWebLoginEnabledForChannel()'s
                       PURE core already lowercases on read, but storing it
                       normalised keeps the admin-echoed value and the raw
                       tblAppSettings row identical (no surprise casing on
                       the next page load). */
                    $webLoginEnabledVal = implode(',', array_map('strtolower', $webChannelTokens));
                }

                /* #1429 C4 — APNs Auth Key (Live Activities push bridge,
                   #1410/#1429). A THIRD, SEPARATE Apple key from the SIWA
                   one above (same Apple Developer Team, different purpose:
                   this one signs APNs provider-authentication JWTs, not
                   Sign-in-with-Apple client_secrets) — validated with the
                   IDENTICAL "must parse as EC P-256" guard, and the
                   IDENTICAL blank-means-keep secret convention, as the SIWA
                   private key just above. Reusing apple_team_id (no second
                   Team ID setting — see includes/apns.php::apnsCredentials()'s
                   own docblock for why). Entirely dormant until BOTH this
                   Key ID and this .p8 are saved (apnsConfigured() stays
                   false otherwise, so apnsSend() keeps returning
                   'not_configured' regardless of anything else). */
                $apnsKeyIdVal = strtoupper(trim((string)($_POST['apple_apns_key_id'] ?? '')));
                if ($apnsKeyIdVal !== '' && !preg_match('/^[A-Z0-9]{10}$/', $apnsKeyIdVal)) {
                    throw new \RuntimeException('APNs Key ID must be exactly 10 letters/digits (e.g. "ABCDE12345"), or left blank.');
                }

                $apnsPrivateKeyRaw = (string)($_POST['apple_apns_private_key'] ?? '');
                $apnsPrivateKeyVal = null; /* null = "don't touch the stored value" */
                if (trim($apnsPrivateKeyRaw) !== '') {
                    $apnsCandidate = trim($apnsPrivateKeyRaw);
                    if (!str_starts_with($apnsCandidate, '-----BEGIN PRIVATE KEY-----')) {
                        throw new \RuntimeException('APNs private key must be the raw .p8 file contents, starting with "-----BEGIN PRIVATE KEY-----" (paste the ENTIRE downloaded file, including the BEGIN/END lines).');
                    }
                    $apnsParsedKey = @openssl_pkey_get_private($apnsCandidate);
                    if ($apnsParsedKey === false) {
                        throw new \RuntimeException('APNs private key could not be parsed — check you pasted the complete, unmodified .p8 file contents.');
                    }
                    $apnsKeyDetails = @openssl_pkey_get_details($apnsParsedKey);
                    $apnsCurveName  = is_array($apnsKeyDetails) ? ($apnsKeyDetails['ec']['curve_name'] ?? null) : null;
                    $apnsIsEcP256   = is_array($apnsKeyDetails)
                        && ($apnsKeyDetails['type'] ?? null) === OPENSSL_KEYTYPE_EC
                        && ($apnsCurveName === 'prime256v1' || $apnsCurveName === 'secp256r1');
                    if (!$apnsIsEcP256) {
                        throw new \RuntimeException('APNs private key must be an EC P-256 key (an "Apple Push Notifications service (APNs)" key) — this parsed as a different key type. Do NOT paste the SIWA key or the App Store Connect API deploy key here; those are separate, unrelated keys.');
                    }
                    $apnsPrivateKeyVal = $apnsCandidate;
                }

                $changedKeys = ['apple_team_id', 'apple_siwa_key_id'];
                $saveSetting($db, 'apple_team_id', $teamIdVal);
                $saveSetting($db, 'apple_siwa_key_id', $keyIdVal);
                if ($privateKeyVal !== null) {
                    $changedKeys[] = 'apple_siwa_private_key';
                    $saveSetting($db, 'apple_siwa_private_key', $privateKeyVal);
                }
                $changedKeys[] = 'apple_siwa_services_id';
                $saveSetting($db, 'apple_siwa_services_id', $servicesIdVal);
                $changedKeys[] = 'apple_web_login_enabled';
                $saveSetting($db, 'apple_web_login_enabled', $webLoginEnabledVal);
                $changedKeys[] = 'apple_apns_key_id';
                $saveSetting($db, 'apple_apns_key_id', $apnsKeyIdVal);
                if ($apnsPrivateKeyVal !== null) {
                    $changedKeys[] = 'apple_apns_private_key';
                    $saveSetting($db, 'apple_apns_private_key', $apnsPrivateKeyVal);
                }

                if (function_exists('logActivity')) {
                    logActivity(
                        'app_setting.update',
                        'app_setting',
                        'apple_team_id',
                        ['keys' => $changedKeys], /* key NAMES only — the private key value is never logged */
                        'success'
                    );
                }
                $saveSuccess = 'Apple native app settings saved.';
            } catch (\Throwable $e) {
                error_log('[manage configuration save_apple] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'save_native_apps') {
            /* Native app store IDs (#1403/#1462) — Apple App Store /
               Google Play / Amazon Appstore. Moves this OUT of the
               APP_CONFIG['native_apps'] code constant into tblAppSettings
               so the owner can set/change store IDs without a deploy.
               index.php reads these three keys FIRST via getAppSetting(),
               falling back to the constant only when unset.

               Each field accepts either a bare ID/package/ASIN or a full
               store URL — ihymnsParseAppStoreId() (includes/config.php) is
               the SAME parser verifyAppStoreApp() uses to resolve the
               saved value later, so what's accepted here can never
               disagree with what the public site does with it. Only the
               parsed, CANONICAL id is ever stored — never the admin's raw
               pasted string — and a value that doesn't match the
               platform's expected shape at all is rejected outright
               (nothing free-form ever reaches tblAppSettings). Blank
               clears the setting back to "unset" (falls back to the
               APP_CONFIG constant, or hides that platform's banner). */
            try {
                $NATIVE_APP_FIELDS = [
                    'native_app_ios'     => ['ios',     'Apple App Store'],
                    'native_app_android' => ['android', 'Google Play'],
                    'native_app_amazon'  => ['amazon',  'Amazon Appstore'],
                ];
                $parsedValues = [];
                foreach ($NATIVE_APP_FIELDS as $settingKey => [$platform, $label]) {
                    if (!array_key_exists($settingKey, $_POST)) continue;
                    $raw = trim((string)$_POST[$settingKey]);
                    if ($raw === '') {
                        $parsedValues[$settingKey] = '';
                        continue;
                    }
                    $appId = ihymnsParseAppStoreId($platform, $raw);
                    if ($appId === null) {
                        throw new \RuntimeException(
                            $label . ' value doesn\'t look like a valid ID/package/ASIN or store URL — check it and try again.'
                        );
                    }
                    $parsedValues[$settingKey] = $appId;
                }

                $changedKeys = [];
                foreach ($parsedValues as $settingKey => $val) {
                    $saveSetting($db, $settingKey, $val);
                    $changedKeys[] = $settingKey;
                }

                if (function_exists('logActivity')) {
                    logActivity(
                        'app_setting.update',
                        'app_setting',
                        'native_app_ios',
                        ['keys' => $changedKeys],
                        'success'
                    );
                }
                $saveSuccess = 'Native app store settings saved.';
            } catch (\Throwable $e) {
                error_log('[manage configuration save_native_apps] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'save_feature_gating') {
            /* #1481 — the two-flag nested topology for server-side content
               gating. `content_gating_enabled` previously had NO admin-UI
               control anywhere (it could only be flipped directly in
               tblAppSettings or via a raw SQL statement — see the hint text
               on /manage/restrictions); P2 gives it one here, alongside the
               NEW `feature_gating_rules_enabled` flag that gates the
               composable enforcement-rules loop (includes/gating_rules.php's
               gatingRulesApply(), called from contentGatingApply()).
               Rules only ever fire when BOTH flags are '1' — this form can
               flip either independently, so an admin can turn content gating
               on for the built-in seven caps while keeping the newer rules
               engine off, or vice versa (though the rules engine is itself
               a no-op without content_gating_enabled='1' too). */
            try {
                /* hidden 0 before each checkbox so an unchecked box still posts a value */
                $cgVal = ((string)($_POST['content_gating_enabled'] ?? '0')) === '1' ? '1' : '0';
                $frVal = ((string)($_POST['feature_gating_rules_enabled'] ?? '0')) === '1' ? '1' : '0';
                $saveSetting($db, 'content_gating_enabled', $cgVal);
                $saveSetting($db, 'feature_gating_rules_enabled', $frVal);
                if (function_exists('logActivity')) {
                    logActivity(
                        'app_setting.update',
                        'app_setting',
                        'content_gating_enabled',
                        [
                            'keys'                          => ['content_gating_enabled', 'feature_gating_rules_enabled'],
                            'content_gating_enabled'        => $cgVal === '1',
                            'feature_gating_rules_enabled'  => $frVal === '1',
                        ],
                        'success'
                    );
                }
                $saveSuccess = 'Feature-gating flags saved — content gating is '
                    . ($cgVal === '1' ? 'ON' : 'OFF') . ', enforcement rules are '
                    . ($frVal === '1' ? 'ON' : 'OFF') . '.';
            } catch (\Throwable $e) {
                error_log('[manage configuration save_feature_gating] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'save_editor_default') {
            /* #1601 — the Song Editor cutover switch, given a UI control for the
               same reason content_gating_enabled got one above: a flag that can
               only be flipped with a raw SQL statement is not a usable revert.
               If the v2 editor misbehaves under real curator load this is the
               fleet-wide off switch, needing no deploy and no database client.
               Note the polarity: '1' (or ABSENT) means v2. Writing '0' is the
               only thing that creates the row at all. */
            try {
                $edVal = ((string)($_POST['editor_v2_default'] ?? '0')) === '1' ? '1' : '0';
                $saveSetting($db, 'editor_v2_default', $edVal);
                if (function_exists('logActivity')) {
                    logActivity(
                        'app_setting.update',
                        'app_setting',
                        'editor_v2_default',
                        ['keys' => ['editor_v2_default'], 'editor_v2_default' => $edVal === '1'],
                        'success'
                    );
                }
                $saveSuccess = '/manage/editor/ now opens the '
                    . ($edVal === '1' ? 'NEW editor (v2).' : 'LEGACY editor.');
            } catch (\Throwable $e) {
                error_log('[manage configuration save_editor_default] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'save_intappsapi') {
            /* #1725/#1728/#1732 — MWBM-IntAppsAPI gateway credentials + the
               per-channel enablement allow-list. Requires intapps_client.php
               for the setting-key constants (rule #35 — one source of truth
               for the literal key names) and for the post-save
               intappsEnabled()/intappsConfig() re-read that drives the
               stress-test remedy 8 warning below. */
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'intapps_client.php';
            try {
                /* Channel allow-list — IDENTICAL validation to
                   apple_web_login_enabled above (same token set, same
                   normalise-to-lowercase-on-save), because it is the SAME
                   per-channel-canary mechanism (stress-test remedy 2): a
                   typo'd token would look "saved" but silently never match
                   any real channel. */
                $enabledChannelsVal = trim((string)($_POST[INTAPPS_SETTING_ENABLED_CHANNELS] ?? ''));
                if ($enabledChannelsVal !== '') {
                    $validChannelTokens = ['alpha', 'beta', 'production', 'all'];
                    $intappsChannelTokens = array_values(array_filter(array_map('trim', explode(',', $enabledChannelsVal)), static fn(string $t): bool => $t !== ''));
                    foreach ($intappsChannelTokens as $tok) {
                        if (!in_array(strtolower($tok), $validChannelTokens, true)) {
                            throw new \RuntimeException('IntAppsAPI enabled channels must be a comma-separated list of "alpha", "beta", "production", or "all" — "' . $tok . '" is none of those.');
                        }
                    }
                    $enabledChannelsVal = implode(',', array_map('strtolower', $intappsChannelTokens));
                }

                /* base_url — https:// ONLY from this admin form (stress-test
                   remedy 10). The http://127.0.0.1 loopback carve-out that
                   makes the local stub-gateway fixture testable is a
                   SEPARATE tblAppSettings row (intappsapi_allow_loopback)
                   that this page deliberately never exposes a control for —
                   it is set directly by the test fixture / a local operator
                   via SQL, never a checkbox a production admin could tick by
                   mistake. */
                $baseUrlVal = trim((string)($_POST[INTAPPS_SETTING_BASE_URL] ?? ''));
                if ($baseUrlVal === '') {
                    $baseUrlVal = INTAPPS_DEFAULT_BASE_URL;
                }
                if (!str_starts_with($baseUrlVal, 'https://')) {
                    throw new \RuntimeException('IntAppsAPI base URL must start with "https://" (this form never accepts http://).');
                }

                $appSlugVal = trim((string)($_POST[INTAPPS_SETTING_APP_SLUG] ?? ''));
                if ($appSlugVal === '') {
                    $appSlugVal = INTAPPS_DEFAULT_APP_SLUG;
                }
                if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $appSlugVal)) {
                    throw new \RuntimeException('IntAppsAPI app slug must be 1-50 letters/digits/hyphen/underscore.');
                }

                $appUuidVal = trim((string)($_POST[INTAPPS_SETTING_APP_UUID] ?? ''));
                if ($appUuidVal !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $appUuidVal)) {
                    throw new \RuntimeException('IntAppsAPI app UUID must be a standard UUID (e.g. "11111111-2222-3333-4444-555555555555"), or left blank.');
                }

                /* Secrets — blank means "leave the existing value alone",
                   the SAME convention every other secret field on this page
                   uses (SMTP password, SIWA/APNs private keys, ...). */
                $apiKeyRaw = trim((string)($_POST[INTAPPS_SETTING_API_KEY] ?? ''));
                $hmacSecretRaw = trim((string)($_POST[INTAPPS_SETTING_HMAC_SECRET] ?? ''));

                $changedKeys = [INTAPPS_SETTING_ENABLED_CHANNELS, INTAPPS_SETTING_BASE_URL, INTAPPS_SETTING_APP_SLUG, INTAPPS_SETTING_APP_UUID];
                $saveSetting($db, INTAPPS_SETTING_ENABLED_CHANNELS, $enabledChannelsVal);
                $saveSetting($db, INTAPPS_SETTING_BASE_URL, $baseUrlVal);
                $saveSetting($db, INTAPPS_SETTING_APP_SLUG, $appSlugVal);
                $saveSetting($db, INTAPPS_SETTING_APP_UUID, $appUuidVal);
                if ($apiKeyRaw !== '') {
                    $changedKeys[] = INTAPPS_SETTING_API_KEY;
                    $saveSetting($db, INTAPPS_SETTING_API_KEY, $apiKeyRaw);
                }
                if ($hmacSecretRaw !== '') {
                    $changedKeys[] = INTAPPS_SETTING_HMAC_SECRET;
                    $saveSetting($db, INTAPPS_SETTING_HMAC_SECRET, $hmacSecretRaw);
                }

                if (function_exists('logActivity')) {
                    logActivity(
                        'app_setting.update',
                        'app_setting',
                        INTAPPS_SETTING_ENABLED_CHANNELS,
                        ['keys' => $changedKeys], /* key NAMES only — secret VALUES never logged */
                        'success'
                    );
                }
                $saveSuccess = 'IntAppsAPI gateway settings saved.';

                /* Stress-test remedy 8 — an admin who lists a channel but
                   leaves a required field blank (or whose secret save fails
                   closed) must NOT be told "saved" with no further signal:
                   the module then LOOKS enabled but every consumer behaves
                   disabled. This re-reads via the SAME intappsEnabled()/
                   intappsConfig() every consumer calls, so the warning can
                   never disagree with actual runtime behaviour. */
                if ($enabledChannelsVal !== '' && intappsConfig() === null) {
                    $missing = [];
                    if ($appUuidVal === '') { $missing[] = 'App UUID'; }
                    if ($apiKeyRaw === '' && ((string)(getAppSetting(INTAPPS_SETTING_API_KEY, '') ?? '')) === '') { $missing[] = 'API key'; }
                    if ($hmacSecretRaw === '' && ((string)(getAppSetting(INTAPPS_SETTING_HMAC_SECRET, '') ?? '')) === '') { $missing[] = 'HMAC secret'; }
                    $saveWarning = 'A channel is listed in the enabled-channels field, but the'
                        . ' credential set is still incomplete (' . implode(', ', $missing !== [] ? $missing : ['unknown field'])
                        . ') — the integration will behave EXACTLY as if it were still disabled'
                        . ' until every field is set. This is not an error; it is the documented'
                        . ' fail-open default.';
                }
            } catch (\Throwable $e) {
                error_log('[manage configuration save_intappsapi] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'save_cuercode') {
            /* CueRCode QR-generation gateway (owner directive 2026-08-05). Base
               URL (https-only) + the secret API key (blank = leave the stored
               value alone, the same convention every secret field here uses).
               Requires cuercode_client.php for the setting-key constants (rule
               #35 — one source of truth for the literal key names). */
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cuercode_client.php';
            try {
                $cuercodeBaseUrlIn = trim((string)($_POST[CUERCODE_SETTING_BASE_URL] ?? ''));
                if ($cuercodeBaseUrlIn === '') {
                    $cuercodeBaseUrlIn = CUERCODE_DEFAULT_BASE_URL;
                }
                if (!str_starts_with($cuercodeBaseUrlIn, 'https://')) {
                    throw new \RuntimeException('CueRCode base URL must start with "https://".');
                }
                $cuercodeApiKeyIn = trim((string)($_POST[CUERCODE_SETTING_API_KEY] ?? ''));

                $changedKeys = [CUERCODE_SETTING_BASE_URL];
                $saveSetting($db, CUERCODE_SETTING_BASE_URL, $cuercodeBaseUrlIn);
                if ($cuercodeApiKeyIn !== '') {
                    $changedKeys[] = CUERCODE_SETTING_API_KEY;
                    $saveSetting($db, CUERCODE_SETTING_API_KEY, $cuercodeApiKeyIn);
                }
                if (function_exists('logActivity')) {
                    logActivity('app_setting.update', 'app_setting', CUERCODE_SETTING_BASE_URL,
                        ['keys' => $changedKeys], 'success'); /* key NAMES only — the secret VALUE is never logged */
                }
                $saveSuccess = 'CueRCode QR settings saved.';
            } catch (\Throwable $e) {
                error_log('[manage configuration save_cuercode] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'save_captcha') {
            /* CAPTCHA provider (#947/#340). Provider must be a SELECTABLE
               registry key (or 'none' = off); the public site key is stored
               plain; the secret key follows the blank-=-keep idiom every secret
               field here uses (encrypted at rest via $saveSetting because
               captcha_secret_key is in secretSettingKeys()); the enabled forms
               are the ticked subset of captchaFormKeys(), stored as CSV (rule
               #20 — a growable vocabulary as a CSV, never an ENUM/SET column, so
               NO DDL). Requires captcha.php for the constants + the registry
               (rule #35 — one source of truth for the provider/form vocab). */
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'captcha.php';
            try {
                $provIn    = trim((string)($_POST[CAPTCHA_SETTING_PROVIDER] ?? 'none'));
                $providers = captchaProviders();
                if ($provIn !== 'none' && (!isset($providers[$provIn]) || empty($providers[$provIn]['selectable']))) {
                    throw new \RuntimeException('Unknown or non-selectable CAPTCHA provider.');
                }
                $siteIn   = trim((string)($_POST[CAPTCHA_SETTING_SITE_KEY] ?? ''));
                $secretIn = trim((string)($_POST[CAPTCHA_SETTING_SECRET_KEY] ?? ''));

                /* Checkbox array → validated CSV (⊆ captchaFormKeys()). An
                   unknown key is dropped, never fatal — fail closed-to-disabled. */
                $postedForms = (array)($_POST['captcha_forms'] ?? []);
                $validForms  = captchaFormKeys();
                $formsOut    = [];
                foreach ($postedForms as $f) {
                    $f = trim((string)$f);
                    if (in_array($f, $validForms, true) && !in_array($f, $formsOut, true)) {
                        $formsOut[] = $f;
                    }
                }
                $formsCsv = implode(',', $formsOut);

                $changedKeys = [CAPTCHA_SETTING_PROVIDER, CAPTCHA_SETTING_SITE_KEY, CAPTCHA_SETTING_FORMS];
                $saveSetting($db, CAPTCHA_SETTING_PROVIDER, $provIn);
                $saveSetting($db, CAPTCHA_SETTING_SITE_KEY, $siteIn);
                $saveSetting($db, CAPTCHA_SETTING_FORMS, $formsCsv);
                if ($secretIn !== '') {
                    $changedKeys[] = CAPTCHA_SETTING_SECRET_KEY;
                    $saveSetting($db, CAPTCHA_SETTING_SECRET_KEY, $secretIn);
                }
                if (function_exists('logActivity')) {
                    logActivity('app_setting.update', 'app_setting', CAPTCHA_SETTING_PROVIDER,
                        ['keys' => $changedKeys, 'forms' => $formsCsv], 'success'); /* key NAMES + form list only — never the secret VALUE */
                }
                $saveSuccess = 'CAPTCHA settings saved.';
            } catch (\Throwable $e) {
                error_log('[manage configuration save_captcha] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'save_live_follow_idle') {
            /* #1770 §4.7 — the APP layer of the leader-idle precedence chain
               (includes/service_mode.php's serviceMode_resolveIdleTimeoutMins()).
               A freeform tblAppSettings key (the SERVICE_MODE_POLL_MS_* / #1406
               precedent) — no migration needed to add or read it. Clamped to the
               SAME [5, 240] band the resolver itself clamps to (mirrors the
               maintenance_refresh_seconds pattern immediately above): a
               hand-edited or out-of-range POST can never store a value the
               resolver would have to re-defend against on every read. */
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'service_mode.php';
            try {
                $idleIn  = filter_var((string)($_POST['live_follow_idle_timeout_minutes'] ?? ''), FILTER_VALIDATE_INT);
                $idleVal = $idleIn === false
                    ? LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES
                    : max(LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES, min(LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES, (int)$idleIn));
                $saveSetting($db, LIVE_FOLLOW_IDLE_TIMEOUT_APP_SETTING_KEY, (string)$idleVal);
                if (function_exists('logActivity')) {
                    logActivity('app_setting.update', 'app_setting', LIVE_FOLLOW_IDLE_TIMEOUT_APP_SETTING_KEY,
                        ['keys' => [LIVE_FOLLOW_IDLE_TIMEOUT_APP_SETTING_KEY], 'value' => $idleVal], 'success');
                }
                $saveSuccess = 'Live Follow idle-timeout default saved (' . $idleVal . ' minutes).';
            } catch (\Throwable $e) {
                error_log('[manage configuration save_live_follow_idle] ' . $e->getMessage());
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        } elseif ($action === 'save_pd_publication_threshold') {
            /* #1862 (epic #1863) — decision D3: the publication-year fallback
               threshold the public-domain suggestion falls back to when a
               part's death-basis year can't be concluded. A plain
               tblAppSettings key (no migration needed), mirroring the
               live_follow_idle pattern immediately above. Clamped to the SAME
               [500, 2100] band FirstPublishedYear itself validates against
               (api2.php's metadata_field_update — SMALLINT UNSIGNED, not
               MySQL YEAR, since hymns predate 1901) so a hand-edited or
               out-of-range POST can never store a threshold FirstPublishedYear
               itself could never carry. */
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pd_suggest.php';
            try {
                $pdThresholdIn  = filter_var((string)($_POST['pd_publication_year_threshold'] ?? ''), FILTER_VALIDATE_INT);
                $pdThresholdVal = $pdThresholdIn === false
                    ? IHYMNS_PD_PUBLICATION_THRESHOLD_DEFAULT
                    : max(500, min(2100, (int)$pdThresholdIn));
                $saveSetting($db, IHYMNS_PD_PUBLICATION_THRESHOLD_SETTING_KEY, (string)$pdThresholdVal);
                if (function_exists('logActivity')) {
                    logActivity('app_setting.update', 'app_setting', IHYMNS_PD_PUBLICATION_THRESHOLD_SETTING_KEY,
                        ['keys' => [IHYMNS_PD_PUBLICATION_THRESHOLD_SETTING_KEY], 'value' => $pdThresholdVal], 'success');
                }
                $saveSuccess = 'Public-domain publication-year threshold saved (' . $pdThresholdVal . ').';
            } catch (\Throwable $e) {
                error_log('[manage configuration save_pd_publication_threshold] ' . $e->getMessage());
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
   no drift between what the admin sees and what visitors get.
   (maintenance.php is already required at the top of this page — #1466.) */
$maintenanceRefresh  = maintenanceRefreshSeconds();

/* Apple native app Team ID (#1401) — read via the same shared, DB-safe
   getAppSetting() the AASA responder itself uses, so this admin page can
   never disagree with what the AASA file actually emits. */
$appleTeamId = (string)(getAppSetting('apple_team_id', '') ?? '');

/* Sign in with Apple Key ID + .p8 private key (#1402) — same getAppSetting()
   read path includes/apple_siwa.php's callers use. The private key's VALUE
   is deliberately never read into a form-echoable variable — only whether
   it is set is needed for the status badge (secret convention, mirrors
   email_gmail_sa_json above). */
$appleSiwaKeyId = (string)(getAppSetting('apple_siwa_key_id', '') ?? '');
$appleSiwaPrivateKeySet = ((string)(getAppSetting('apple_siwa_private_key', '') ?? '')) !== '';

/* Sign in with Apple for WEB (#1470 W1) — both NON-secret, echoed back as-is
   (no "leave blank to keep" convention). appleWebLoginEnabledForChannel()
   itself is not called here — this page shows the RAW saved setting values,
   not a resolved per-channel boolean (an admin editing this form needs to
   see exactly what is stored, on whichever docroot they happen to be
   viewing it from). */
$appleSiwaServicesId = (string)(getAppSetting('apple_siwa_services_id', '') ?? '');
$appleWebLoginEnabledSetting = (string)(getAppSetting('apple_web_login_enabled', '') ?? '');

/* APNs Auth Key (#1429 C4) — Live Activities push bridge (#1410/#1429).
   Same getAppSetting()/secret-status convention as the SIWA pair above;
   the private key's VALUE is likewise never read into a form-echoable
   variable. */
$appleApnsKeyId = (string)(getAppSetting('apple_apns_key_id', '') ?? '');
$appleApnsPrivateKeySet = ((string)(getAppSetting('apple_apns_private_key', '') ?? '')) !== '';

/* Native app store IDs (#1403/#1462) — the values echoed back are the
   CANONICAL, parsed IDs saved by save_native_apps above (never a raw
   pasted URL), read via the same getAppSetting() index.php resolves
   against, so this admin page can never disagree with what the public
   site actually shows. */
$nativeAppIos     = (string)(getAppSetting('native_app_ios', '') ?? '');
$nativeAppAndroid = (string)(getAppSetting('native_app_android', '') ?? '');
$nativeAppAmazon  = (string)(getAppSetting('native_app_amazon', '') ?? '');

/* #1481 — feature-gating two-flag topology. Read via the SAME getAppSetting()
   contentGatingApply()/gatingRulesApply() themselves call, so this admin page
   can never disagree with what enforcement actually reads. */
$contentGatingEnabledVal       = getAppSetting('content_gating_enabled', '0') === '1';
/* #1601 — the Song Editor cutover switch. Note the default is '1' (v2), the
   opposite of the gating flags above: an ABSENT key means the NEW editor, so no
   migration or seed row is needed to turn the cutover on. The row only ever
   exists once somebody has deliberately turned it OFF. */
$editorV2DefaultVal            = getAppSetting('editor_v2_default', '1') !== '0';
$featureGatingRulesEnabledVal  = getAppSetting('feature_gating_rules_enabled', '0') === '1';

/* #1725/#1732 — MWBM-IntAppsAPI gateway. Non-secret fields are echoed back
   as-typed (matches the Apple Services ID / channel-allow-list convention
   above — an admin editing this form needs to see exactly what is stored).
   Secret VALUES are never read into a form-echoable variable, only whether
   each is SET, for the status badge. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'intapps_client.php';
$intappsEnabledChannelsVal = (string)(getAppSetting(INTAPPS_SETTING_ENABLED_CHANNELS, '') ?? '');
$intappsBaseUrlVal         = (string)(getAppSetting(INTAPPS_SETTING_BASE_URL, INTAPPS_DEFAULT_BASE_URL) ?? INTAPPS_DEFAULT_BASE_URL);
$intappsAppSlugVal         = (string)(getAppSetting(INTAPPS_SETTING_APP_SLUG, INTAPPS_DEFAULT_APP_SLUG) ?? INTAPPS_DEFAULT_APP_SLUG);
$intappsAppUuidVal         = (string)(getAppSetting(INTAPPS_SETTING_APP_UUID, '') ?? '');
$intappsApiKeySet          = ((string)(getAppSetting(INTAPPS_SETTING_API_KEY, '') ?? '')) !== '';
$intappsHmacSecretSet      = ((string)(getAppSetting(INTAPPS_SETTING_HMAC_SECRET, '') ?? '')) !== '';
/* Resolved runtime state — the SAME function every consumer calls, so this
   badge can never disagree with actual behaviour (rule #35). */
$intappsResolvedEnabled    = intappsEnabled();

/* CueRCode QR-generation gateway (owner directive 2026-08-05 — QR via CueRCode).
   Same secret convention: the base URL is echoed as-typed; the API-key VALUE is
   never read into a form var, only whether it is SET (for the badge). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cuercode_client.php';
$cuercodeBaseUrlVal = (string)(getAppSetting(CUERCODE_SETTING_BASE_URL, CUERCODE_DEFAULT_BASE_URL) ?? CUERCODE_DEFAULT_BASE_URL);
$cuercodeApiKeySet  = ((string)(getAppSetting(CUERCODE_SETTING_API_KEY, '') ?? '')) !== '';
$cuercodeConfigured = cuercodeConfigured();

/* CAPTCHA provider (#947/#340) — dormant until a provider + BOTH keys are set
   AND a form is ticked. Same secret convention: the site key echoes as-typed;
   the secret VALUE is never read into a form var, only whether it is SET. The
   provider list + form list come from the registry (rule #35 — one source). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'captcha.php';
$captchaProviderVal   = (string)(getAppSetting(CAPTCHA_SETTING_PROVIDER, 'none') ?? 'none');
$captchaSiteKeyVal    = (string)(getAppSetting(CAPTCHA_SETTING_SITE_KEY, '') ?? '');
$captchaSecretSet     = ((string)(getAppSetting(CAPTCHA_SETTING_SECRET_KEY, '') ?? '')) !== '';
$captchaEnabledFormsV = captchaEnabledFormsList();
$captchaConfiguredNow = captchaConfigured();
$captchaProvidersReg  = captchaProviders();
/* Per-form native-impact captions (the D3 warning made permanent UI). Keyed by
   captchaFormKeys() value; a key without an entry falls back to a generic
   caption, so the card can never silently drop a newly-added form. */
$captchaFormMeta = [
    'registration'   => ['label' => 'Registration',        'caption' => 'Breaks native app sign-up until the apps add widget support.'],
    'login'          => ['label' => 'Login',               'caption' => 'Breaks native sign-in. Login already carries the strongest rate limits — enable last, if at all.'],
    'password_reset' => ['label' => 'Password reset',      'caption' => 'Breaks native password reset until the apps add widget support.'],
    'email_login'    => ['label' => 'Email login (code)',  'caption' => 'Breaks native magic-link login until the apps add widget support.'],
    'song_request'   => ['label' => 'Song requests',       'caption' => 'Web-only endpoint — no native impact. Ends the no-JS form fallback while enabled.'],
    'manage_login'   => ['label' => 'Admin login (/manage)','caption' => 'Admin login page — no native impact.'],
];

/* #1770 §4.7 — the APP-DEFAULT layer of the leader-idle precedence chain;
   read via the SAME resolver-adjacent constants service_mode.php declares
   (rule #35 — one source of truth for the key name + the min/max/default
   literals) so this admin field can never disagree with what
   serviceMode_resolveIdleTimeoutMins() actually falls back to. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'service_mode.php';
$liveFollowIdleTimeoutVal = (int)(getAppSetting(LIVE_FOLLOW_IDLE_TIMEOUT_APP_SETTING_KEY, (string)LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES) ?? LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES);
if ($liveFollowIdleTimeoutVal <= 0) { $liveFollowIdleTimeoutVal = LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES; }

/* #1862 (epic #1863) — decision D3's publication-year fallback threshold,
   read via the SAME constants (rule #35 — one source of truth for the key
   name + default) editor2.php's window._iHymnsPdSuggest emit uses, so this
   admin field can never disagree with what the PD-suggestion hint actually
   falls back to. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pd_suggest.php';
$pdPublicationThresholdVal = (int)(getAppSetting(IHYMNS_PD_PUBLICATION_THRESHOLD_SETTING_KEY, (string)IHYMNS_PD_PUBLICATION_THRESHOLD_DEFAULT) ?? IHYMNS_PD_PUBLICATION_THRESHOLD_DEFAULT);
if ($pdPublicationThresholdVal <= 0) { $pdPublicationThresholdVal = IHYMNS_PD_PUBLICATION_THRESHOLD_DEFAULT; }

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — iHymns Admin</title>
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
                <i class="bi bi-sliders me-2"></i>Settings
                <?= entitlementLockChipHtml('manage_configuration') ?>
            </h1>
            <p class="text-secondary small mb-0">
                Site-wide settings for iHymns, including email delivery and connections to other services. Changes take effect immediately.
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
         FEATURE GATING SECTION (#1481 — two-flag nested topology)
         =========================== -->
    <div class="card bg-dark border-secondary mb-4" id="feature-gating">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-shield-lock me-2"></i>Feature gating
            </h2>
            <span class="badge <?= ($contentGatingEnabledVal && $featureGatingRulesEnabledVal) ? 'bg-success' : 'bg-secondary' ?>">
                <?= $contentGatingEnabledVal
                    ? ($featureGatingRulesEnabledVal ? 'Content gating + rules ON' : 'Content gating ON — rules OFF')
                    : 'OFF (verified no-op)' ?>
            </span>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">
                <strong>Two independent, nested switches.</strong> <code>content_gating_enabled</code>
                turns on server-side enforcement of the built-in seven Access-Tier capabilities
                (Lyrics / Copyrighted / Audio / MIDI / PDF / Offline / Needs&nbsp;CCLI) plus any
                admin-defined capability. <code>feature_gating_rules_enabled</code> additionally
                turns on the <a href="/manage/feature-gating" class="alert-link">Enforcement rules</a>
                you've defined there — it has no effect unless content gating is ALSO on. Both default
                OFF; with either OFF, the API emits full song data exactly as it does today (a verified
                byte-identical no-op — see <code>tests/php/test-gating-noop.php</code> and the
                <a href="/manage/gating-noop-verify" class="alert-link">No-Op Verifier</a>).
            </p>
            <p class="small text-secondary mb-3">
                <i class="bi bi-info-circle me-1"></i>
                Also see <a href="/manage/restrictions" class="alert-link">Content Restrictions</a>
                (per-song/songbook/feature rules — inert while content gating is off) and
                <a href="/manage/tiers" class="alert-link">Access Tiers</a> (per-tier capability values).
            </p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_feature_gating">
                <!-- hidden 0 before each checkbox so an unchecked box still posts a value -->
                <input type="hidden" name="content_gating_enabled" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="content_gating_enabled" name="content_gating_enabled" value="1"
                           <?= $contentGatingEnabledVal ? 'checked' : '' ?>>
                    <label class="form-check-label" for="content_gating_enabled">
                        Enable content gating (master switch)
                    </label>
                </div>
                <input type="hidden" name="feature_gating_rules_enabled" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="feature_gating_rules_enabled" name="feature_gating_rules_enabled" value="1"
                           <?= $featureGatingRulesEnabledVal ? 'checked' : '' ?>>
                    <label class="form-check-label" for="feature_gating_rules_enabled">
                        Enable admin-defined enforcement rules
                        <span class="text-secondary">(needs content gating above ON too)</span>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Save feature-gating flags
                </button>
            </form>
        </div>
    </div>

    <!-- ===========================
         SONG EDITOR SECTION (#1601 — the v2 cutover switch)
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-pencil-square me-2"></i>Song Editor
            </h2>
            <span class="badge <?= $editorV2DefaultVal ? 'bg-success' : 'bg-warning text-dark' ?>">
                <?= $editorV2DefaultVal ? 'New editor (v2)' : 'Legacy editor' ?>
            </span>
        </div>
        <div class="card-body">
            <?php /* #1601 — this exists for the same reason content_gating_enabled got a control
                     above: a flag that can only be flipped by a raw SQL statement is not a usable
                     revert. If the new editor misbehaves under real curator load, this is the
                     fleet-wide off switch that needs no deploy and no database client. */ ?>
            <p class="text-secondary small">
                Controls which editor <code>/manage/editor/</code> opens. Turning this off sends
                everyone back to the legacy editor immediately — no deploy needed. Individual links
                to <code>/manage/editor/?legacy=1</code> always reach the legacy editor regardless
                of this setting.
            </p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_editor_default">
                <!-- hidden 0 before the checkbox so an unchecked box still posts a value -->
                <input type="hidden" name="editor_v2_default" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="editor_v2_default" name="editor_v2_default" value="1"
                           <?= $editorV2DefaultVal ? 'checked' : '' ?>>
                    <label class="form-check-label" for="editor_v2_default">
                        Use the new Song Editor (v2) at <code>/manage/editor/</code>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Save editor setting
                </button>
            </form>
        </div>
    </div>

    <!-- ===========================
         INTAPPSAPI GATEWAY SECTION (#1725/#1732) — dormant by design
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-broadcast-pin me-2"></i>IntAppsAPI Gateway
            </h2>
            <span class="badge <?= $intappsResolvedEnabled ? 'bg-success' : 'bg-secondary' ?>">
                <?= $intappsResolvedEnabled ? 'Active' : 'Dormant' ?>
            </span>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">
                Credentials + per-channel enablement for the MWBM-IntAppsAPI gateway — a
                server-proxied, cache-first, fail-open feature-flag kill switch (never a source
                of content-access decisions; those stay entirely local). <strong>Dormant by
                design</strong> — with no channel listed below, this integration performs zero
                HTTP calls and zero database reads beyond this page, byte-identical to a build
                with no gateway integration at all. Full status + snapshot viewer:
                <a href="/manage/intapps-status" class="link-light">IntApps Gateway status</a>.
            </p>
            <?php if (!$intappsAppUuidVal && !$intappsApiKeySet && !$intappsHmacSecretSet): ?>
                <p class="small text-body-secondary border-start border-secondary border-3 ps-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i><strong>Dormant — awaiting gateway
                    registration (#1726).</strong> Nothing below is an error; it is the expected
                    state until the owner-only gateway-registration prerequisite closes and
                    real credentials are pasted here.
                </p>
            <?php endif; ?>
            <form method="post" class="row g-3 align-items-end mb-2">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_intappsapi">
                <div class="col-md-6">
                    <label for="intappsapi_enabled_channels" class="form-label">Enabled channels</label>
                    <input type="text" name="intappsapi_enabled_channels" id="intappsapi_enabled_channels"
                           class="form-control" placeholder="e.g. alpha  (leave blank to stay fully dormant)"
                           value="<?= htmlspecialchars($intappsEnabledChannelsVal, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">Comma-separated: <code>alpha</code>, <code>beta</code>,
                        <code>production</code>, or <code>all</code>. Empty = dormant everywhere
                        (the shipped default). Canary on <code>alpha</code> first — this is the
                        <strong>only</strong> per-environment brake, since all three docroots share
                        one database.</div>
                </div>
                <div class="col-md-6">
                    <label for="intappsapi_base_url" class="form-label">Gateway base URL</label>
                    <input type="text" name="intappsapi_base_url" id="intappsapi_base_url"
                           class="form-control" placeholder="<?= htmlspecialchars(INTAPPS_DEFAULT_BASE_URL, ENT_QUOTES, 'UTF-8') ?>"
                           value="<?= htmlspecialchars($intappsBaseUrlVal, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">Must start with <code>https://</code>.</div>
                </div>
                <div class="col-md-4">
                    <label for="intappsapi_app_slug" class="form-label">App slug</label>
                    <input type="text" name="intappsapi_app_slug" id="intappsapi_app_slug"
                           class="form-control" placeholder="<?= htmlspecialchars(INTAPPS_DEFAULT_APP_SLUG, ENT_QUOTES, 'UTF-8') ?>"
                           value="<?= htmlspecialchars($intappsAppSlugVal, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-8">
                    <label for="intappsapi_app_uuid" class="form-label">App UUID</label>
                    <input type="text" name="intappsapi_app_uuid" id="intappsapi_app_uuid"
                           class="form-control" placeholder="11111111-2222-3333-4444-555555555555"
                           value="<?= htmlspecialchars($intappsAppUuidVal, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-6">
                    <label for="intappsapi_api_key" class="form-label">
                        API key <?= $intappsApiKeySet ? '<span class="badge bg-success">set</span>' : '<span class="badge bg-secondary">not set</span>' ?>
                    </label>
                    <input type="password" name="intappsapi_api_key" id="intappsapi_api_key"
                           class="form-control" autocomplete="off"
                           placeholder="<?= $intappsApiKeySet ? '(unchanged — leave blank to keep)' : '' ?>">
                </div>
                <div class="col-md-6">
                    <label for="intappsapi_hmac_secret" class="form-label">
                        HMAC secret <?= $intappsHmacSecretSet ? '<span class="badge bg-success">set</span>' : '<span class="badge bg-secondary">not set</span>' ?>
                    </label>
                    <input type="password" name="intappsapi_hmac_secret" id="intappsapi_hmac_secret"
                           class="form-control" autocomplete="off"
                           placeholder="<?= $intappsHmacSecretSet ? '(unchanged — leave blank to keep)' : '' ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save IntAppsAPI settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===========================
         CUERCODE QR SECTION (owner directive 2026-08-05) — dormant until keyed
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-qr-code me-2"></i>CueRCode QR Generator
            </h2>
            <span class="badge <?= $cuercodeConfigured ? 'bg-success' : 'bg-secondary' ?>">
                <?= $cuercodeConfigured ? 'Active' : 'Dormant' ?>
            </span>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">
                Credentials for the <a href="https://cuercode.net" class="link-light" target="_blank" rel="noopener">CueRCode</a>
                service, which generates every QR code in iHymns (the print-template QR block and the
                Service-Projection join QR) via its API — server-side, so the secret key never reaches a
                browser. <strong>Dormant until keyed</strong>: with no API key saved, the <code>/qr.php</code>
                endpoint answers 503 and each QR surface falls back to the plain URL/code text.
            </p>
            <?php if (!$cuercodeApiKeySet): ?>
                <p class="small text-body-secondary border-start border-secondary border-3 ps-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i><strong>Dormant — awaiting an API key.</strong>
                    Generate a key in the CueRCode admin panel and paste it below; QR codes light up the
                    moment it is saved.
                </p>
            <?php endif; ?>
            <form method="post" class="row g-3 align-items-end mb-2">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_cuercode">
                <div class="col-md-6">
                    <label for="cuercode_base_url" class="form-label">Base URL</label>
                    <input type="text" name="cuercode_base_url" id="cuercode_base_url"
                           class="form-control" placeholder="<?= htmlspecialchars(CUERCODE_DEFAULT_BASE_URL, ENT_QUOTES, 'UTF-8') ?>"
                           value="<?= htmlspecialchars($cuercodeBaseUrlVal, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">Must start with <code>https://</code>. Default is the production CueRCode service.</div>
                </div>
                <div class="col-md-6">
                    <label for="cuercode_api_key" class="form-label">
                        API key <?= $cuercodeApiKeySet ? '<span class="badge bg-success">set</span>' : '<span class="badge bg-secondary">not set</span>' ?>
                    </label>
                    <input type="password" name="cuercode_api_key" id="cuercode_api_key"
                           class="form-control" autocomplete="off"
                           placeholder="<?= $cuercodeApiKeySet ? '(unchanged — leave blank to keep)' : 'cuercode_…' ?>">
                    <div class="form-text">Generated in the CueRCode admin panel. Encrypted at rest.</div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save CueRCode settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===========================
         CAPTCHA SECTION (#947/#340) — dormant until a provider + both keys + a form
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-shield-check me-2"></i>CAPTCHA (bot protection)
            </h2>
            <span class="badge <?= $captchaConfiguredNow ? 'bg-success' : 'bg-secondary' ?>">
                <?= $captchaConfiguredNow ? 'Active' : 'Dormant' ?>
            </span>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">
                A "prove you're human" challenge on the forms you tick below. Verified server-side; the
                secret key never reaches a browser. <strong>Dormant until keyed</strong>: with no provider
                and both keys saved — and at least one form ticked — nothing changes. The per-IP,
                per-account and per-identifier rate limits stay in force underneath regardless.
            </p>
            <?php if (!$captchaConfiguredNow): ?>
                <p class="small text-body-secondary border-start border-secondary border-3 ps-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i><strong>Dormant.</strong>
                    Pick a provider, paste its site key + secret key (create an account with the provider
                    first), then tick the forms to guard. The challenge goes live the moment all three are set.
                </p>
            <?php endif; ?>
            <form method="post" class="row g-3 align-items-end mb-2">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_captcha">
                <div class="col-md-4">
                    <label for="captcha_provider" class="form-label">Provider</label>
                    <select name="captcha_provider" id="captcha_provider" class="form-select">
                        <option value="none"<?= $captchaProviderVal === 'none' ? ' selected' : '' ?>>None (off)</option>
                        <?php foreach ($captchaProvidersReg as $pKey => $pEntry): ?>
                            <?php if (empty($pEntry['selectable'])) { continue; } ?>
                            <option value="<?= htmlspecialchars((string)$pKey, ENT_QUOTES, 'UTF-8') ?>"<?= $captchaProviderVal === $pKey ? ' selected' : '' ?>>
                                <?= htmlspecialchars((string)($pEntry['label'] ?? $pKey), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Turnstile, hCaptcha and reCAPTCHA v2 are supported.</div>
                </div>
                <div class="col-md-4">
                    <label for="captcha_site_key" class="form-label">Site key</label>
                    <input type="text" name="captcha_site_key" id="captcha_site_key"
                           class="form-control" autocomplete="off"
                           value="<?= htmlspecialchars($captchaSiteKeyVal, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">Public — sent to browsers to draw the widget.</div>
                </div>
                <div class="col-md-4">
                    <label for="captcha_secret_key" class="form-label">
                        Secret key <?= $captchaSecretSet ? '<span class="badge bg-success">set</span>' : '<span class="badge bg-secondary">not set</span>' ?>
                    </label>
                    <input type="password" name="captcha_secret_key" id="captcha_secret_key"
                           class="form-control" autocomplete="off"
                           placeholder="<?= $captchaSecretSet ? '(unchanged — leave blank to keep)' : 'provider secret key' ?>">
                    <div class="form-text">Server-side only. Encrypted at rest; never sent to a browser.</div>
                </div>
                <div class="col-12">
                    <label class="form-label mb-1">Guard these forms</label>
                    <div class="row g-2">
                        <?php foreach (captchaFormKeys() as $fKey): ?>
                            <?php
                            $fMeta   = $captchaFormMeta[$fKey] ?? ['label' => $fKey, 'caption' => ''];
                            $fId     = 'captcha_form_' . preg_replace('/[^a-z0-9_]/', '', (string)$fKey);
                            $fOn     = in_array($fKey, $captchaEnabledFormsV, true);
                            ?>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="captcha_forms[]"
                                           value="<?= htmlspecialchars((string)$fKey, ENT_QUOTES, 'UTF-8') ?>"
                                           id="<?= htmlspecialchars($fId, ENT_QUOTES, 'UTF-8') ?>"<?= $fOn ? ' checked' : '' ?>>
                                    <label class="form-check-label" for="<?= htmlspecialchars($fId, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)$fMeta['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                    <?php if (($fMeta['caption'] ?? '') !== ''): ?>
                                        <div class="form-text small"><?= htmlspecialchars((string)$fMeta['caption'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save CAPTCHA settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===========================
         LIVE FOLLOW SECTION (#1770 §4.7 — app-default idle timeout)
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-broadcast-pin me-2"></i>Live Follow
            </h2>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">
                A worship leader's "Go Live" session auto-closes after this many minutes with no
                genuine leader interaction (opening the app doesn't count — reading, navigating,
                or driving a section does). This is the site-wide DEFAULT — a leader's own
                <a href="/settings" class="link-light">Settings</a> can shorten or lengthen it,
                and an organisation can override or lock it on
                <a href="/manage/organisations" class="link-light">Organisations</a>
                (site admin) or <a href="/manage/my-organisations" class="link-light">My organisations</a>
                (org admin).
            </p>
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_live_follow_idle">
                <div class="col-auto">
                    <label for="live_follow_idle_timeout_minutes" class="form-label">Idle-timeout default (minutes)</label>
                    <input type="number" name="live_follow_idle_timeout_minutes" id="live_follow_idle_timeout_minutes"
                           class="form-control" style="max-width: 10rem;"
                           min="<?= LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES ?>" max="<?= LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES ?>" step="1"
                           value="<?= (int)$liveFollowIdleTimeoutVal ?>">
                    <div class="form-text">
                        <?= LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES ?>&ndash;<?= LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES ?> minutes; default
                        <?= LIVE_FOLLOW_IDLE_TIMEOUT_DEFAULT_MINUTES ?>. Only affects sessions started AFTER this is saved —
                        already-running sessions keep the value they were started with.
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===========================
         PUBLIC-DOMAIN SUGGESTION SECTION (#1862, epic #1863)
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-shield-check me-2"></i>Public-domain suggestion
            </h2>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">
                The Editor2 metadata tab hints "this looks public domain" from a credited
                contributor's death date (life + 70 years — a code constant, not configurable
                here). When no death date is on record, it falls back to assuming a song
                published before this year is public domain. This is a SUGGESTION only —
                curators still tick the Public Domain box themselves; nothing here auto-sets it.
            </p>
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_pd_publication_threshold">
                <div class="col-auto">
                    <label for="pd_publication_year_threshold" class="form-label">Publication-year fallback threshold</label>
                    <input type="number" name="pd_publication_year_threshold" id="pd_publication_year_threshold"
                           class="form-control" style="max-width: 10rem;"
                           min="500" max="2100" step="1"
                           value="<?= (int)$pdPublicationThresholdVal ?>">
                    <div class="form-text">
                        500&ndash;2100; default <?= IHYMNS_PD_PUBLICATION_THRESHOLD_DEFAULT ?>. A song
                        first published before this year is suggested public domain when no
                        death-date basis is available.
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===========================
         APPLE NATIVE APP SECTION (#1401 Team ID + #1402 Sign in with Apple)
         =========================== -->
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-apple me-2"></i>Apple native app
            </h2>
            <span class="badge <?= $appleTeamId === '' ? 'bg-secondary' : 'bg-success' ?>">
                <?= $appleTeamId === '' ? 'Team ID not set (AASA uses placeholder)' : 'Team ID set' ?>
            </span>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">
                The Apple Developer <strong>Team ID</strong> for the <code>app.ihymns</code> bundle
                (developer.apple.com &rarr; Membership — a single fixed value for your Apple account,
                <em>not</em> a secret). This field is the <strong>runtime</strong> source: it is
                embedded into <code>/.well-known/apple-app-site-association</code> so Universal Links
                can open the native app instead of Safari. Left blank, that file still serves valid
                JSON with an obviously-fake <code>TEAMID</code> placeholder — safe, but Universal
                Links won't resolve until a real value is saved here.
            </p>
            <p class="small text-warning-emphasis border-start border-warning border-3 ps-2 mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i><strong>This field and the
                <code>APPLE_TEAM_ID</code> GitHub secret are two independent copies that must be
                identical — neither overrides the other.</strong> They are read by <em>different</em>
                systems: the GitHub secret signs the app <strong>at build time</strong> (baked into
                the <code>.ipa</code> via the Apple deploy pipeline), while this field is what the
                server publishes <strong>at runtime</strong> in the AASA file (PHP cannot read GitHub
                secrets, and the web deploy never injects this value). If the two differ, the AASA
                advertises a Team ID the installed app was <em>not</em> signed with, so Universal
                Links silently fail (links open in Safari instead of the app). Paste the same value
                in both places.
            </p>
            <form method="post" class="row g-3 align-items-end mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_apple">
                <div class="col-md-4">
                    <label for="apple_team_id" class="form-label">Apple Team ID</label>
                    <input type="text" name="apple_team_id" id="apple_team_id" class="form-control"
                           style="text-transform: uppercase;" maxlength="10" pattern="[A-Za-z0-9]{10}"
                           placeholder="ABCDE12345"
                           value="<?= htmlspecialchars($appleTeamId, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">Exactly 10 letters/digits. Leave blank to clear.</div>
                </div>

                <div class="col-12"><hr class="text-secondary my-2"></div>

                <div class="col-12">
                    <h3 class="h6 mb-1">
                        <i class="bi bi-key me-1"></i>Sign in with Apple (#1402)
                        <span class="badge <?= $appleSiwaKeyId === '' ? 'bg-secondary' : 'bg-success' ?> ms-1" style="font-size: 0.65rem;">
                            <?= $appleSiwaKeyId === '' ? 'Key ID not set' : 'Key ID set' ?>
                        </span>
                        <span class="badge <?= $appleSiwaPrivateKeySet ? 'bg-success' : 'bg-secondary' ?> ms-1" style="font-size: 0.65rem;">
                            <?= $appleSiwaPrivateKeySet ? '.p8 key set' : '.p8 key not set' ?>
                        </span>
                    </h3>
                    <p class="small text-secondary mb-3">
                        Only needed for the best-effort Apple refresh-token exchange (on sign-in) and the
                        Apple-side <code>/auth/revoke</code> call (on account deletion) — verifying an
                        Apple identity token at sign-in needs NEITHER of these (Apple's public key set is
                        fetched directly), so <code>?action=auth_apple</code> works before this section is
                        filled in. developer.apple.com &rarr; Certificates, Identifiers &amp; Profiles
                        &rarr; Keys &rarr; create a key with the <strong>Sign in with Apple</strong>
                        capability enabled for <code>app.ihymns</code> &rarr; download the <code>.p8</code>
                        (one-time download) and note its 10-character Key ID.
                    </p>
                </div>
                <div class="col-md-4">
                    <label for="apple_siwa_key_id" class="form-label">SIWA Key ID</label>
                    <input type="text" name="apple_siwa_key_id" id="apple_siwa_key_id" class="form-control"
                           style="text-transform: uppercase;" maxlength="10" pattern="[A-Za-z0-9]{10}"
                           placeholder="ABCDE12345" autocomplete="off"
                           value="<?= htmlspecialchars($appleSiwaKeyId, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">Exactly 10 letters/digits. Leave blank to clear.</div>
                </div>
                <div class="col-md-8">
                    <label for="apple_siwa_private_key" class="form-label">
                        SIWA private key (.p8)
                        <?php if ($appleSiwaPrivateKeySet): ?>
                            <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">saved — leave blank to keep</span>
                        <?php endif; ?>
                    </label>
                    <textarea name="apple_siwa_private_key" id="apple_siwa_private_key" class="form-control font-monospace"
                              rows="4" autocomplete="off" spellcheck="false"
                              placeholder="<?= $appleSiwaPrivateKeySet ? '•••••••• (saved — paste a new .p8 to replace)' : "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----" ?>"></textarea>
                    <div class="form-text">
                        Paste the ENTIRE downloaded <code>.p8</code> file contents, including the
                        <code>-----BEGIN/END PRIVATE KEY-----</code> lines. Stored in
                        <code>tblAppSettings</code>; never echoed back to this form. This is
                        <strong>not</strong> the App Store Connect API deploy key
                        (<code>APPLE_ASC_KEY_P8</code>) — that is a different key, used only by the
                        release pipeline.
                    </div>
                </div>

                <div class="col-12"><hr class="text-secondary my-2"></div>

                <div class="col-12">
                    <h3 class="h6 mb-1">
                        <i class="bi bi-globe me-1"></i>Sign in with Apple — Web (#1470)
                        <span class="badge <?= $appleSiwaServicesId === '' ? 'bg-secondary' : 'bg-success' ?> ms-1" style="font-size: 0.65rem;">
                            <?= $appleSiwaServicesId === '' ? 'Services ID not set' : 'Services ID set' ?>
                        </span>
                        <span class="badge <?= $appleWebLoginEnabledSetting === '' ? 'bg-secondary' : 'bg-success' ?> ms-1" style="font-size: 0.65rem;">
                            <?= $appleWebLoginEnabledSetting === '' ? 'Disabled on every channel' : ('Enabled: ' . htmlspecialchars($appleWebLoginEnabledSetting, ENT_QUOTES, 'UTF-8')) ?>
                        </span>
                    </h3>
                    <p class="small text-secondary mb-3">
                        Web/PWA Sign in with Apple uses a SEPARATE <strong>Services ID</strong>
                        (developer.apple.com &rarr; Identifiers &rarr; "+" &rarr; Services IDs — grouped
                        under the <code>app.ihymns</code> App ID, same Apple Developer Team as the
                        native app above), <strong>not</strong> the App ID itself. Register the return
                        URL <code>https://&lt;each docroot host&gt;/</code> against that Services ID for
                        EVERY docroot this app is deployed to (e.g. <code>https://ihymns.app/</code>,
                        <code>https://beta.ihymns.app/</code>, <code>https://dev.ihymns.app/</code>) —
                        it must match exactly what the web sign-in button sends, or Apple's code
                        exchange fails. Both fields below are dormant: web sign-in stays entirely
                        unavailable (a clean 503, identical to today) until BOTH a Services ID is
                        saved here AND the current channel is included in the allow-list below.
                    </p>
                </div>
                <div class="col-md-6">
                    <label for="apple_siwa_services_id" class="form-label">Services ID</label>
                    <input type="text" name="apple_siwa_services_id" id="apple_siwa_services_id" class="form-control"
                           maxlength="255" placeholder="app.ihymns.web" autocomplete="off"
                           value="<?= htmlspecialchars($appleSiwaServicesId, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">
                        Not a secret (same visibility class as the native app's bundle id) — but must
                        NOT be <code>app.ihymns</code> (that's the App ID, a different identifier).
                        Leave blank to clear (disables web sign-in on every channel).
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="apple_web_login_enabled" class="form-label">Enabled on channels</label>
                    <input type="text" name="apple_web_login_enabled" id="apple_web_login_enabled" class="form-control"
                           maxlength="60" placeholder="alpha   or   alpha,beta   or   all"
                           value="<?= htmlspecialchars($appleWebLoginEnabledSetting, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">
                        Comma-separated <code>alpha</code> / <code>beta</code> / <code>production</code>,
                        or the single word <code>all</code>. All three docroots share ONE database, so
                        this is a staged-rollout dial for the shared-DB deploy — e.g. start with
                        <code>alpha</code>, widen to <code>alpha,beta</code> once verified, then
                        <code>all</code>. Leave blank to disable on every channel (the safe default).
                    </div>
                </div>

                <div class="col-12"><hr class="text-secondary my-2"></div>

                <div class="col-12">
                    <h3 class="h6 mb-1">
                        <i class="bi bi-bell me-1"></i>APNs Auth Key — Live Activities (#1429)
                        <span class="badge <?= $appleApnsKeyId === '' ? 'bg-secondary' : 'bg-success' ?> ms-1" style="font-size: 0.65rem;">
                            <?= $appleApnsKeyId === '' ? 'Key ID not set' : 'Key ID set' ?>
                        </span>
                        <span class="badge <?= $appleApnsPrivateKeySet ? 'bg-success' : 'bg-secondary' ?> ms-1" style="font-size: 0.65rem;">
                            <?= $appleApnsPrivateKeySet ? '.p8 key set' : '.p8 key not set' ?>
                        </span>
                    </h3>
                    <p class="small text-secondary mb-3">
                        Powers push updates to the Lock Screen / Dynamic Island "Live Activity" card
                        during a Live Follow or Service Mode broadcast — entirely dormant (no push is
                        ever attempted) until BOTH fields below are saved. This is a THIRD, SEPARATE
                        Apple key from the Sign in with Apple one above — same Apple Developer Team,
                        different purpose. developer.apple.com &rarr; Certificates, Identifiers &amp;
                        Profiles &rarr; Keys &rarr; create a key with the
                        <strong>Apple Push Notifications service (APNs)</strong> capability enabled
                        &rarr; download the <code>.p8</code> (one-time download) and note its
                        10-character Key ID.
                    </p>
                </div>
                <div class="col-md-4">
                    <label for="apple_apns_key_id" class="form-label">APNs Key ID</label>
                    <input type="text" name="apple_apns_key_id" id="apple_apns_key_id" class="form-control"
                           style="text-transform: uppercase;" maxlength="10" pattern="[A-Za-z0-9]{10}"
                           placeholder="ABCDE12345" autocomplete="off"
                           value="<?= htmlspecialchars($appleApnsKeyId, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">Exactly 10 letters/digits. Leave blank to clear.</div>
                </div>
                <div class="col-md-8">
                    <label for="apple_apns_private_key" class="form-label">
                        APNs private key (.p8)
                        <?php if ($appleApnsPrivateKeySet): ?>
                            <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">saved — leave blank to keep</span>
                        <?php endif; ?>
                    </label>
                    <textarea name="apple_apns_private_key" id="apple_apns_private_key" class="form-control font-monospace"
                              rows="4" autocomplete="off" spellcheck="false"
                              placeholder="<?= $appleApnsPrivateKeySet ? '•••••••• (saved — paste a new .p8 to replace)' : "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----" ?>"></textarea>
                    <div class="form-text">
                        Paste the ENTIRE downloaded <code>.p8</code> file contents, including the
                        <code>-----BEGIN/END PRIVATE KEY-----</code> lines. Stored in
                        <code>tblAppSettings</code>, encrypted at rest; never echoed back to this
                        form. This is <strong>not</strong> the SIWA key above, and <strong>not</strong>
                        the App Store Connect API deploy key (<code>APPLE_ASC_KEY_P8</code>) — those
                        are separate, unrelated keys.
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save Apple settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===========================
         NATIVE APP STORES SECTION (#1403/#1462)
         =========================== -->
    <?php $nativeAppsAnySet = ($nativeAppIos !== '' || $nativeAppAndroid !== '' || $nativeAppAmazon !== ''); ?>
    <div class="card bg-dark border-secondary mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h5 mb-0">
                <i class="bi bi-phone me-2"></i>Native app stores
            </h2>
            <span class="badge <?= $nativeAppsAnySet ? 'bg-success' : 'bg-secondary' ?>">
                <?= $nativeAppsAnySet ? 'Configured' : 'Not configured — PWA install prompt only' ?>
            </span>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">
                When set, the public site shows a platform-aware <strong>native app download banner</strong>
                (replacing the browser's PWA install prompt on that platform) and emits the matching
                app-store meta tags. Paste either the <strong>full store URL</strong> or the
                <strong>bare ID / package / ASIN</strong> — either is accepted and normalised to the
                canonical value on save. Leave a field blank to clear it (falls back to the PWA install
                prompt, or to the code-level default if one is set).
            </p>
            <!-- Top-aligned grid: labels/inputs line up across all three columns
                 regardless of differing help-text length (the previous
                 `align-items-end` bottom-aligned the columns, producing a ragged
                 staircase because the Apple help text is 3 lines vs 1 for the
                 others). Full-width stacked below lg, 3-across from lg where the
                 long store-URL placeholders have room to breathe. (#1462) -->
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_native_apps">

                <div class="col-12 col-lg-4">
                    <label for="native_app_ios" class="form-label">
                        <i class="bi bi-apple me-1"></i>Apple App Store
                    </label>
                    <input type="text" name="native_app_ios" id="native_app_ios" class="form-control"
                           placeholder="https://apps.apple.com/app/id1234567890 or 1234567890"
                           value="<?= htmlspecialchars($nativeAppIos, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">
                        Numeric App Store ID, or the full store URL. Covers the universal app
                        (iOS/iPadOS/macOS/tvOS/watchOS/visionOS share one listing).
                    </div>
                </div>

                <div class="col-12 col-lg-4">
                    <label for="native_app_android" class="form-label">
                        <i class="bi bi-google-play me-1"></i>Google Play
                    </label>
                    <input type="text" name="native_app_android" id="native_app_android" class="form-control"
                           placeholder="https://play.google.com/store/apps/details?id=ltd.mwbmpartners.ihymns or ltd.mwbmpartners.ihymns"
                           value="<?= htmlspecialchars($nativeAppAndroid, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">Android package name, or the full Play Store URL.</div>
                </div>

                <div class="col-12 col-lg-4">
                    <label for="native_app_amazon" class="form-label">
                        <i class="bi bi-amazon me-1"></i>Amazon Appstore
                    </label>
                    <input type="text" name="native_app_amazon" id="native_app_amazon" class="form-control"
                           placeholder="https://www.amazon.com/dp/B0XXXXXXXX or B0XXXXXXXX"
                           value="<?= htmlspecialchars($nativeAppAmazon, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">10-character ASIN, or the full Amazon Appstore (Fire OS) URL.</div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save native app settings
                    </button>
                </div>
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
