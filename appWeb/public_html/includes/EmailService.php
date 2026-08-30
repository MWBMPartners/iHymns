<?php

declare(strict_types=1);

/**
 * iHymns - EmailService (#898)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Single entry point for outbound transactional email. Reads provider
 * config from tblAppSettings, dispatches to the right driver, returns
 * a structured EmailSendResult, and writes a sanitised email.send row
 * to tblActivityLog so admins can answer "did the email actually go
 * out?" without server-shell access.
 *
 * Replaces the three error_log()-only paths flagged in #898:
 *   - api.php:1751  auth_email_login_request   (magic-link login)
 *   - api.php:1631  auth_request_password_reset
 *   - api.php:7464  admin_user_password_reset  (notify target)
 * and adds the previously-missing verification email after
 * password-based auth_register (api.php:972).
 *
 * Provider drivers (in priority order):
 *   - sendgrid : SendGrid v3 REST API
 *   - mailgun  : Mailgun v3 REST API (auto-detects EU / US region)
 *   - ses      : AWS SES SendEmail (SigV4-signed POST, no AWS SDK)
 *   - smtp     : Self-contained SMTP-AUTH client (no PHPMailer / Composer).
 *                Speaks ESMTP over a raw socket with STARTTLS or implicit
 *                SSL/TLS, AUTH LOGIN, and an RFC 5322 MIME multipart body.
 *                Supports Microsoft 365 (smtp.office365.com) and Google
 *                Workspace (smtp.gmail.com) via the provider presets in
 *                /manage/configuration.php, plus DELEGATE / "send-as"
 *                sending where the From / Sender address differs from the
 *                SMTP AUTH login (the login mailbox must be granted
 *                Send-As on the delegate mailbox in the provider's admin
 *                console — see the configuration.php setup guides). (feature C)
 *   - graph    : Microsoft 365 via Microsoft Graph (OAuth2 app-only) — #1311.
 *                client-credentials token → POST /users/{sender}/sendMail with
 *                the Mail.Send application permission. No SMTP, no app password;
 *                the modern path immune to Basic-Auth / SMTP-AUTH deprecation.
 *                Selected when email_service=office365 + email_auth_method=oauth2.
 *   - gmail-api : Google Workspace via the Gmail API (OAuth2 service-account +
 *                domain-wide delegation) — #1311. Signed JWT → access token
 *                impersonating the sender → users/{sender}/messages/send (raw
 *                base64url RFC822). Selected when email_service=gmail +
 *                email_auth_method=oauth2. (MailerMatt remains the eventual
 *                consolidated mechanism; these are the native OAuth2 drivers.)
 *   - log      : developer-only "send to PHP error_log" mode. Sanitises
 *                the log line — never writes the magic-link token, the
 *                6-digit code, or the verification token. The raw
 *                secret is delivered via the email body in production
 *                providers; in `log` mode we emit only the recipient
 *                hash + template name for offline correlation.
 *   - none     : EmailService::isConfigured() returns false; callers
 *                must short-circuit (see api.php:1711 for the existing
 *                503 pattern).
 *
 * USAGE:
 *   require_once __DIR__ . '/EmailService.php';
 *   if (!EmailService::isConfigured()) { ... 503 ... }
 *   $r = EmailService::sendTemplate('magic-link-login', $email, [
 *       'code'         => '123456',
 *       'link'         => 'https://ihymns.app/login?token=...',
 *       'displayName'  => 'Lance',
 *       'expiresInMin' => 10,
 *   ]);
 *   if (!$r->ok) { ... return 500 to caller ... }
 *
 * SECURITY:
 *   - Never logs the raw token / code anywhere. Production providers
 *     receive the secret over TLS via the request body + the recipient
 *     receives it via the rendered template; nothing else does.
 *   - tblActivityLog rows record only: template name, provider, the
 *     sha256(email) prefix as a debug correlator, the provider's
 *     Message-Id (so admins can cross-check with the provider
 *     dashboard), and ok/error_class. Never the body, never the
 *     secret, never the recipient address in plaintext.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'activity_log.php';
/* #2004 — getAppSetting() (a plain tblAppSettings key/value read that
   transparently decrypts a secret-flagged value — see secret_crypto.php's
   own doc). deliveryTest() below reads the NON-secret 'email_service' key
   through it rather than re-opening its own SELECT, so the "which provider
   is this test running against?" label can never disagree with what the
   rest of the app calls the current provider. maintenance.php is itself
   require-safe (constants/functions only, its own doc-block) so this adds
   no side effect to loading EmailService.php. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php';

/**
 * Result envelope for every send attempt.
 *
 * Constructed by the EmailService and returned to callers. Callers
 * MUST surface a non-success HTTP status when ->ok is false (see #898
 * acceptance criteria — the previous code lied with HTTP 200 even
 * when delivery never happened).
 */
final class EmailSendResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $provider,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
        public readonly ?string $errorClass = null,
        public readonly ?int $httpStatus = null
    ) {}

    public static function success(string $provider, ?string $messageId = null): self
    {
        return new self(true, $provider, $messageId);
    }

    public static function failure(string $provider, string $error, ?string $errorClass = null, ?int $httpStatus = null): self
    {
        return new self(false, $provider, null, $error, $errorClass, $httpStatus);
    }
}

final class EmailService
{
    /** Settings cache for the lifetime of one PHP request. */
    private static ?array $settings = null;

    /**
     * True when an active provider is selected. Mirrors the existing
     * api.php:1711 gating contract — this is the only check call sites
     * should rely on, never a direct tblAppSettings.email_service read.
     */
    public static function isConfigured(): bool
    {
        $service = self::loadSettings()['email_service'] ?? 'none';
        return $service !== 'none' && $service !== '';
    }

    /**
     * Render an email template (HTML + text variants) and dispatch it.
     *
     * Templates live in includes/email-templates/<name>.{html,txt}.php
     * and consume a $vars array via PHP-include. Subject lines come
     * from a top-of-file `$SUBJECT = '...'` declaration.
     *
     * @param string $template Template basename (no extension).
     * @param string $to       Recipient email address.
     * @param array  $vars     Substitution data (template-specific).
     * @return EmailSendResult
     */
    public static function sendTemplate(string $template, string $to, array $vars = []): EmailSendResult
    {
        $rendered = self::renderTemplate($template, $vars);
        if ($rendered === null) {
            return self::recordAndReturn(
                $template,
                $to,
                EmailSendResult::failure('none', 'template_not_found:' . $template, 'TemplateNotFound')
            );
        }

        return self::recordAndReturn(
            $template,
            $to,
            self::dispatch($to, $rendered['subject'], $rendered['html'], $rendered['text'])
        );
    }

    /**
     * Send an ad-hoc message (subject + bodies prepared by the caller).
     * Used by the /manage/configuration.php "Send test email" button.
     */
    public static function send(string $to, string $subject, string $bodyHtml, string $bodyText = ''): EmailSendResult
    {
        if ($bodyText === '') {
            $bodyText = trim(strip_tags($bodyHtml));
        }
        return self::recordAndReturn(
            '_adhoc',
            $to,
            self::dispatch($to, $subject, $bodyHtml, $bodyText)
        );
    }

    /**
     * Reset the per-request settings cache. Used by save handlers that
     * mutate tblAppSettings inside the same request and then want a
     * subsequent send to see the new values.
     */
    public static function resetCache(): void
    {
        self::$settings = null;
    }

    /**
     * The ONE "send a real test email to this admin and tell me what
     * happened" core (#2004, epic #2002) — the body of
     * `manage/configuration.php`'s `test_email` classic button, extracted
     * VERBATIM so the "Connect a service" guided wizard's own email test
     * (`integrationTestEmail()`, `includes/integration_registry.php`) calls
     * the SAME send instead of forking a second one (rule #22/#35).
     *
     * ELI5: "send me a test email and tell me, structurally, what
     * happened" — never configured (no provider chosen), no valid admin
     * address to send it to, sent-and-failed, or sent-and-ok. Both callers
     * turn the SAME structured answer into their own copy — a plain-English
     * sentence here (`deliveryTestMessage()`), a `{ok,status,detail}`
     * envelope in the wizard's test function.
     *
     * WHY THE RETURN SHAPE IS STRUCTURED, NOT A SENTENCE (rule #35): the
     * wizard branches on `status`, never on prose — a future reworded
     * sentence in `deliveryTestMessage()` must never silently break the
     * wizard's status→copy mapping the way a regex-matched sentence would.
     *
     * @param string|null $adminEmail The current admin's own email address
     *                                  (the ONLY recipient a test send is
     *                                  ever aimed at — harmless even on a
     *                                  typo'd From, per the classic
     *                                  button's own original rationale).
     * @return array{ok:bool, status:'ok'|'no_admin_email'|'unconfigured'|'send_failed',
     *               provider:string, messageId:?string, errorClass:?string,
     *               error:?string, adminEmail:string}
     */
    public static function deliveryTest(?string $adminEmail): array
    {
        /* The save_email branch on the calling page may have JUST changed
           the provider config in this same request; reset the cache so
           this test reads the fresh values (kept from the original branch —
           harmless defensiveness even when nothing changed). */
        self::resetCache();

        /* Non-secret — getAppSetting() is the SAME read path every other
           "what's the currently saved X?" caller on this page uses, so this
           test can never disagree with what the Email service card itself
           shows as "Configured: <provider>". */
        $providerLabel = function_exists('getAppSetting')
            ? (string)(getAppSetting('email_service', 'none') ?? 'none')
            : 'none';
        $adminEmail = trim((string)$adminEmail);

        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok' => false, 'status' => 'no_admin_email', 'provider' => $providerLabel,
                'messageId' => null, 'errorClass' => null, 'error' => null, 'adminEmail' => $adminEmail,
            ];
        }
        if (!self::isConfigured()) {
            return [
                'ok' => false, 'status' => 'unconfigured', 'provider' => $providerLabel,
                'messageId' => null, 'errorClass' => null, 'error' => null, 'adminEmail' => $adminEmail,
            ];
        }

        $stamp    = gmdate('Y-m-d H:i:s') . ' UTC';
        $bodyHtml = '<h1>iHymns email delivery test</h1>'
                  . '<p>This is a delivery test from <strong>' . htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') . '</strong>'
                  . ' at <strong>' . htmlspecialchars($stamp, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                  . '<p>If you received this, your email provider is correctly configured.</p>';
        $bodyText = "iHymns email delivery test from {$providerLabel} at {$stamp}.\n\n"
                  . "If you received this, your email provider is correctly configured.\n";
        $sendResult = self::send(
            $adminEmail,
            'iHymns email delivery test (' . $providerLabel . ')',
            $bodyHtml,
            $bodyText
        );

        return [
            'ok'         => $sendResult->ok,
            'status'     => $sendResult->ok ? 'ok' : 'send_failed',
            'provider'   => $sendResult->provider,
            'messageId'  => $sendResult->providerMessageId,
            'errorClass' => $sendResult->errorClass,
            'error'      => $sendResult->error,
            'adminEmail' => $adminEmail,
        ];
    }

    /**
     * Turn `deliveryTest()`'s structured answer into the EXACT plain-English
     * sentence the classic "Send test email" button has always shown —
     * byte-for-byte, so this extraction changes nothing an admin sees.
     *
     * @param array $r deliveryTest()'s return value.
     * @return string
     */
    public static function deliveryTestMessage(array $r): string
    {
        if (($r['status'] ?? '') === 'no_admin_email') {
            return 'Send-test failed: your admin account has no valid email address on file. '
                 . 'Set one in Users -> your row -> Edit, then retry.';
        }
        if (($r['status'] ?? '') === 'unconfigured') {
            return 'Send-test failed: provider is "' . (string)($r['provider'] ?? 'none') . '". Pick a real provider and Save before testing.';
        }
        if (($r['ok'] ?? false) === true) {
            $messageId = (string)($r['messageId'] ?? '');
            return 'Test email dispatched via ' . (string)($r['provider'] ?? '')
                 . ($messageId !== '' ? ' (Message-Id: ' . $messageId . ')' : '')
                 . '. Check ' . (string)($r['adminEmail'] ?? '') . ' to confirm delivery.';
        }
        return 'Test email FAILED via ' . (string)($r['provider'] ?? '')
             . ' (' . (string)($r['errorClass'] ?? 'Error') . '): '
             . (string)($r['error'] ?? '')
             . '. See the Activity Log "email.send" row for the full record.';
    }

    /* -------------------------------------------------------------------
     * Internal: provider dispatch
     * ----------------------------------------------------------------- */

    private static function dispatch(string $to, string $subject, string $bodyHtml, string $bodyText): EmailSendResult
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return EmailSendResult::failure('none', 'invalid_recipient', 'InvalidArgument');
        }

        $cfg = self::loadSettings();
        $service = $cfg['email_service'] ?? 'none';

        switch ($service) {
            case 'none':
                return EmailSendResult::failure('none', 'email_service_disabled', 'NotConfigured');
            case 'log':
                return self::sendViaLog($to, $subject, $cfg);
            case 'sendgrid':
                return self::sendViaSendGrid($to, $subject, $bodyHtml, $bodyText, $cfg);
            case 'mailgun':
                return self::sendViaMailgun($to, $subject, $bodyHtml, $bodyText, $cfg);
            case 'ses':
                return self::sendViaSes($to, $subject, $bodyHtml, $bodyText, $cfg);
            case 'office365':
                /* #1309 — Microsoft 365 / Google Workspace are first-class
                   providers. #1311 — each can authenticate via SMTP-AUTH (app
                   password) OR an OAuth2 REST API. email_auth_method picks;
                   default 'smtp' preserves the #1309 behaviour for existing
                   configs. M365's OAuth2 path is Microsoft Graph (app-only). */
                if (($cfg['email_auth_method'] ?? 'smtp') === 'oauth2') {
                    return self::sendViaGraph($to, $subject, $bodyHtml, $bodyText, $cfg);
                }
                return self::sendViaSmtp($to, $subject, $bodyHtml, $bodyText, $cfg);
            case 'gmail':
                /* #1311 — Google Workspace OAuth2 path is the Gmail API
                   (service-account + domain-wide delegation). */
                if (($cfg['email_auth_method'] ?? 'smtp') === 'oauth2') {
                    return self::sendViaGmailApi($to, $subject, $bodyHtml, $bodyText, $cfg);
                }
                return self::sendViaSmtp($to, $subject, $bodyHtml, $bodyText, $cfg);
            case 'smtp':
                /* feature C — real SMTP-AUTH client with STARTTLS / implicit
                   SSL, AUTH LOGIN, and delegate ("send-as") From support.
                   Supersedes the #898 "not yet implemented" stub. */
                return self::sendViaSmtp($to, $subject, $bodyHtml, $bodyText, $cfg);
            default:
                return EmailSendResult::failure(
                    (string)$service,
                    'unknown_provider:' . $service,
                    'NotConfigured'
                );
        }
    }

    /* -------------------------------------------------------------------
     * Driver: log (developer-only)
     * -----------------------------------------------------------------
     * Writes a sanitised one-liner to PHP error_log so a dev hacking on
     * the auth flow can see "an email would have been sent here"
     * without configuring a real provider. Crucially, the line carries
     * the template + recipient hash but NOT the magic-link token /
     * code / verification secret. The pre-#898 code violated this
     * contract; the new line is enumeration-safe. */
    private static function sendViaLog(string $to, string $subject, array $cfg): EmailSendResult
    {
        error_log(sprintf(
            '[iHymns email log-driver] subject=%s to_hash=%s from=%s',
            self::sanitiseForLog($subject, 80),
            substr(hash('sha256', mb_strtolower($to)), 0, 12),
            self::sanitiseForLog($cfg['email_from_address'] ?? '', 60)
        ));
        return EmailSendResult::success('log', 'log-' . bin2hex(random_bytes(6)));
    }

    /* -------------------------------------------------------------------
     * Driver: SendGrid v3
     * ----------------------------------------------------------------- */
    private static function sendViaSendGrid(string $to, string $subject, string $bodyHtml, string $bodyText, array $cfg): EmailSendResult
    {
        $apiKey = (string)($cfg['email_sendgrid_api_key'] ?? '');
        $from   = self::fromAddress($cfg);
        if ($apiKey === '' || $from['email'] === '') {
            return EmailSendResult::failure('sendgrid', 'missing_credentials_or_from_address', 'NotConfigured');
        }

        $payload = [
            'personalizations' => [[
                'to' => [['email' => $to]],
            ]],
            'from'             => $from,
            'subject'          => $subject,
            'content'          => [
                ['type' => 'text/plain', 'value' => $bodyText !== '' ? $bodyText : strip_tags($bodyHtml)],
                ['type' => 'text/html',  'value' => $bodyHtml],
            ],
        ];

        $resp = self::httpPostJson(
            'https://api.sendgrid.com/v3/mail/send',
            $payload,
            [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ]
        );
        if ($resp['err'] !== '') {
            return EmailSendResult::failure('sendgrid', $resp['err'], 'TransportError');
        }
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            /* SendGrid returns Message-Id in X-Message-Id response header. */
            $msgId = self::extractHeader($resp['headers'], 'X-Message-Id') ?: null;
            return EmailSendResult::success('sendgrid', $msgId);
        }
        return EmailSendResult::failure(
            'sendgrid',
            'http_' . $resp['code'] . ':' . substr($resp['body'], 0, 200),
            'ProviderError',
            $resp['code']
        );
    }

    /* -------------------------------------------------------------------
     * Driver: Mailgun v3
     * ----------------------------------------------------------------- */
    private static function sendViaMailgun(string $to, string $subject, string $bodyHtml, string $bodyText, array $cfg): EmailSendResult
    {
        $apiKey = (string)($cfg['email_mailgun_api_key'] ?? '');
        $domain = (string)($cfg['email_mailgun_domain']  ?? '');
        $from   = self::fromAddress($cfg);
        if ($apiKey === '' || $domain === '' || $from['email'] === '') {
            return EmailSendResult::failure('mailgun', 'missing_credentials_or_domain_or_from', 'NotConfigured');
        }

        /* EU vs US: Mailgun's docs say a domain provisioned in the EU
           region can only send via api.eu.mailgun.net. The configuration
           UI in #898 flagged this as a region inference task. Heuristic:
           if the domain itself contains ".eu." treat as EU. Otherwise
           default to US (api.mailgun.net) which Mailgun also routes
           gracefully via cross-region redirects. */
        $base = (stripos($domain, '.eu.') !== false || str_starts_with($domain, 'eu.'))
            ? 'https://api.eu.mailgun.net'
            : 'https://api.mailgun.net';

        $body = [
            'from'    => self::formatFrom($from),
            'to'      => $to,
            'subject' => $subject,
            'text'    => $bodyText !== '' ? $bodyText : strip_tags($bodyHtml),
            'html'    => $bodyHtml,
        ];

        $resp = self::httpPostForm(
            $base . '/v3/' . rawurlencode($domain) . '/messages',
            $body,
            [
                'Authorization: Basic ' . base64_encode('api:' . $apiKey),
            ]
        );
        if ($resp['err'] !== '') {
            return EmailSendResult::failure('mailgun', $resp['err'], 'TransportError');
        }
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            $decoded = json_decode($resp['body'], true);
            $msgId = is_array($decoded) ? (string)($decoded['id'] ?? '') : '';
            return EmailSendResult::success('mailgun', $msgId !== '' ? $msgId : null);
        }
        return EmailSendResult::failure(
            'mailgun',
            'http_' . $resp['code'] . ':' . substr($resp['body'], 0, 200),
            'ProviderError',
            $resp['code']
        );
    }

    /* -------------------------------------------------------------------
     * Driver: AWS SES (SendEmail action, SigV4-signed POST)
     *
     * Avoids the AWS SDK to keep the repo Composer-free. The signature
     * recipe is the standard SigV4 query-flow described at
     * docs.aws.amazon.com/general/latest/gr/sigv4_signing.html. We POST
     * the action as form-encoded data to https://email.<region>.amazonaws.com/
     * with Action=SendEmail.
     * ----------------------------------------------------------------- */
    private static function sendViaSes(string $to, string $subject, string $bodyHtml, string $bodyText, array $cfg): EmailSendResult
    {
        $region    = (string)($cfg['email_ses_region']     ?? '');
        $accessKey = (string)($cfg['email_ses_access_key'] ?? '');
        $secretKey = (string)($cfg['email_ses_secret_key'] ?? '');
        $from      = self::fromAddress($cfg);
        if ($region === '' || $accessKey === '' || $secretKey === '' || $from['email'] === '') {
            return EmailSendResult::failure('ses', 'missing_credentials_region_or_from', 'NotConfigured');
        }

        $params = [
            'Action'                          => 'SendEmail',
            'Source'                          => self::formatFrom($from),
            'Destination.ToAddresses.member.1'=> $to,
            'Message.Subject.Data'            => $subject,
            'Message.Subject.Charset'         => 'UTF-8',
            'Message.Body.Html.Data'          => $bodyHtml,
            'Message.Body.Html.Charset'       => 'UTF-8',
            'Message.Body.Text.Data'          => $bodyText !== '' ? $bodyText : strip_tags($bodyHtml),
            'Message.Body.Text.Charset'       => 'UTF-8',
            'Version'                         => '2010-12-01',
        ];
        ksort($params);

        $host    = 'email.' . $region . '.amazonaws.com';
        $service = 'ses';
        $tNow    = gmdate('Ymd\THis\Z');
        $tDate   = gmdate('Ymd');

        $canonicalQuery = '';
        $payload = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $payloadHash = hash('sha256', $payload);

        $canonicalHeaders = "host:{$host}\nx-amz-date:{$tNow}\n";
        $signedHeaders    = 'host;x-amz-date';
        $canonicalRequest = "POST\n/\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $credentialScope = "{$tDate}/{$region}/{$service}/aws4_request";
        $stringToSign    = "AWS4-HMAC-SHA256\n{$tNow}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $tDate,    'AWS4' . $secretKey, true);
        $kRegion  = hash_hmac('sha256', $region,   $kDate,              true);
        $kService = hash_hmac('sha256', $service,  $kRegion,            true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService,      true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $auth = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, "
              . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $resp = self::httpPostRaw(
            'https://' . $host . '/',
            $payload,
            [
                'Host: ' . $host,
                'X-Amz-Date: ' . $tNow,
                'Authorization: ' . $auth,
                'Content-Type: application/x-www-form-urlencoded',
            ]
        );
        if ($resp['err'] !== '') {
            return EmailSendResult::failure('ses', $resp['err'], 'TransportError');
        }
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            $msgId = '';
            if (preg_match('#<MessageId>([^<]+)</MessageId>#i', $resp['body'], $m)) {
                $msgId = $m[1];
            }
            return EmailSendResult::success('ses', $msgId !== '' ? $msgId : null);
        }
        return EmailSendResult::failure(
            'ses',
            'http_' . $resp['code'] . ':' . substr($resp['body'], 0, 200),
            'ProviderError',
            $resp['code']
        );
    }

    /* -------------------------------------------------------------------
     * Driver: SMTP (feature C — self-contained ESMTP client)
     * -----------------------------------------------------------------
     * Speaks ESMTP over a raw socket so the repo stays Composer-free,
     * mirroring the no-SDK approach of the SES driver. Supports:
     *   - STARTTLS (port 587, the Microsoft 365 / Google Workspace default)
     *     and implicit SSL/TLS (port 465).
     *   - AUTH LOGIN with the SMTP username + password.
     *   - DELEGATE / "send-as" sending: the MAIL FROM envelope sender and
     *     the From: header use email_smtp_from_address (the delegate
     *     mailbox) when set, while authentication still uses email_smtp_user
     *     (the login mailbox). The provider must have granted the login
     *     mailbox Send-As permission on the delegate mailbox — that's an
     *     admin-console step, documented in configuration.php.
     *
     * NOTE: the SMTP password is read here and written ONLY into the
     * AUTH LOGIN exchange on the encrypted socket — it is never logged,
     * never echoed, never placed in the activity-log row.
     * ----------------------------------------------------------------- */
    private static function sendViaSmtp(string $to, string $subject, string $bodyHtml, string $bodyText, array $cfg): EmailSendResult
    {
        $host   = trim((string)($cfg['email_smtp_host'] ?? ''));
        $port   = (int)($cfg['email_smtp_port'] ?? 0);
        $user   = (string)($cfg['email_smtp_user'] ?? '');
        $pass   = (string)($cfg['email_smtp_pass'] ?? '');
        $secure = (string)($cfg['email_smtp_secure'] ?? 'tls');

        /* #1309 — when a first-class office365 / gmail provider is selected,
           default the connection endpoint from the shared preset (keyed by the
           service) for any field the admin left blank. The UI pre-fills these
           too, but this guarantees a correct send even if the JS pre-fill never
           ran (e.g. the value was saved before this field was filled). The
           preset keys are identical to the email_service values by design. */
        $service = (string)($cfg['email_service'] ?? '');
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'smtp_presets.php';
        $presets = ihymns_smtp_presets();
        if (isset($presets[$service])) {
            if ($host === '')              { $host   = (string)$presets[$service]['host']; }
            if ($port <= 0)                { $port   = (int)$presets[$service]['port']; }
            if ($secure === '' && $presets[$service]['secure'] !== '') {
                $secure = (string)$presets[$service]['secure'];
            }
        }

        /* fromAddress() already prefers the delegate / send-as address
           (email_smtp_from_address → falls back to email_from_address). */
        $from   = self::fromAddress($cfg);

        if ($host === '' || $port <= 0 || $user === '' || $pass === '' || $from['email'] === '') {
            return EmailSendResult::failure('smtp', 'missing_host_port_credentials_or_from', 'NotConfigured');
        }
        if (!function_exists('fsockopen') && !function_exists('stream_socket_client')) {
            return EmailSendResult::failure('smtp', 'no_socket_transport_available', 'TransportError');
        }

        /* Implicit-SSL connects to an already-TLS socket (port 465);
           STARTTLS and "none" connect in cleartext and (for tls) upgrade
           after EHLO. */
        $useImplicitSsl = ($secure === 'ssl');
        $useStartTls    = ($secure === 'tls');
        $transport      = $useImplicitSsl ? 'ssl://' : '';

        $errno  = 0;
        $errstr = '';
        $timeout = 15;
        /* @ suppresses the connection-warning so a refused/timed-out
           connection becomes a clean structured failure instead of a
           PHP warning leaking host details into the output. */
        $fp = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($fp === false) {
            return EmailSendResult::failure(
                'smtp',
                'connect_failed:' . self::sanitiseForLog($errstr !== '' ? $errstr : ('errno_' . $errno), 80),
                'TransportError'
            );
        }
        stream_set_timeout($fp, $timeout);

        /* RFC 5321 conversation. Each helper reads the multi-line reply
           and asserts the leading 3-digit code is in the accepted set;
           any deviation aborts and closes the socket. */
        $fail = static function (string $why, ?string $detail = null) use ($fp): EmailSendResult {
            @fclose($fp);
            $msg = $detail !== null ? ($why . ':' . self::sanitiseForLog($detail, 120)) : $why;
            return EmailSendResult::failure('smtp', $msg, 'ProviderError');
        };

        $ehloName = self::smtpEhloName($from['email']);

        $greet = self::smtpRead($fp);
        if (substr($greet, 0, 1) !== '2') {
            return $fail('no_greeting', $greet);
        }

        self::smtpWrite($fp, 'EHLO ' . $ehloName);
        $ehlo = self::smtpRead($fp);
        if (substr($ehlo, 0, 1) !== '2') {
            return $fail('ehlo_rejected', $ehlo);
        }

        if ($useStartTls) {
            self::smtpWrite($fp, 'STARTTLS');
            $tls = self::smtpRead($fp);
            if (substr($tls, 0, 1) !== '2') {
                return $fail('starttls_rejected', $tls);
            }
            /* Negotiate TLS on the existing socket. Use the modern "any
               TLS" crypto-method constant so 1.2 / 1.3 are both allowed. */
            $cryptoOk = @stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            if ($cryptoOk !== true) {
                return $fail('starttls_handshake_failed', null);
            }
            /* RFC 3207: re-issue EHLO over the now-encrypted channel. */
            self::smtpWrite($fp, 'EHLO ' . $ehloName);
            $ehlo2 = self::smtpRead($fp);
            if (substr($ehlo2, 0, 1) !== '2') {
                return $fail('ehlo_after_tls_rejected', $ehlo2);
            }
        }

        /* AUTH LOGIN — username + password base64-encoded, each on its
           own line after the server's 334 challenge. The password leaves
           this process only here, over the (TLS) socket. */
        self::smtpWrite($fp, 'AUTH LOGIN');
        $authChallenge = self::smtpRead($fp);
        if (substr($authChallenge, 0, 3) !== '334') {
            return $fail('auth_login_unsupported', $authChallenge);
        }
        self::smtpWrite($fp, base64_encode($user));
        $userChallenge = self::smtpRead($fp);
        if (substr($userChallenge, 0, 3) !== '334') {
            return $fail('auth_username_rejected', $userChallenge);
        }
        self::smtpWrite($fp, base64_encode($pass));
        $authResult = self::smtpRead($fp);
        if (substr($authResult, 0, 3) !== '235') {
            /* 535 here = bad credentials / SMTP-AUTH disabled / app
               password required. We surface the SERVER's text but never
               the password itself. */
            return $fail('auth_failed', $authResult);
        }

        /* Envelope. MAIL FROM is the delegate address when send-as is
           configured; some providers (M365) require it to match an
           address the login mailbox is authorised to send as. */
        self::smtpWrite($fp, 'MAIL FROM:<' . self::smtpAddr($from['email']) . '>');
        $mailFrom = self::smtpRead($fp);
        if (substr($mailFrom, 0, 1) !== '2') {
            return $fail('mail_from_rejected', $mailFrom);
        }
        self::smtpWrite($fp, 'RCPT TO:<' . self::smtpAddr($to) . '>');
        $rcptTo = self::smtpRead($fp);
        if (substr($rcptTo, 0, 1) !== '2') {
            return $fail('rcpt_to_rejected', $rcptTo);
        }

        self::smtpWrite($fp, 'DATA');
        $dataPrompt = self::smtpRead($fp);
        if (substr($dataPrompt, 0, 3) !== '354') {
            return $fail('data_rejected', $dataPrompt);
        }

        $message = self::smtpBuildMessage($from, $to, $subject, $bodyHtml, $bodyText);
        /* Dot-stuffing (RFC 5321 §4.5.2): a line that is just "." would
           terminate DATA prematurely, so any leading dot is doubled. */
        $message = preg_replace('/^\./m', '..', $message) ?? $message;
        /* Terminate DATA with CRLF.CRLF. */
        self::smtpWriteRaw($fp, $message . "\r\n.\r\n");
        $sent = self::smtpRead($fp);
        if (substr($sent, 0, 1) !== '2') {
            return $fail('message_rejected', $sent);
        }

        /* Best-effort polite close; the message is already accepted. */
        self::smtpWrite($fp, 'QUIT');
        @fclose($fp);

        /* Pull the queue id out of the 250 acceptance line if present
           (e.g. "250 2.0.0 OK <id>") so admins can cross-reference. */
        $msgId = null;
        if (preg_match('/queued as ([A-Za-z0-9]+)/i', $sent, $m)) {
            $msgId = $m[1];
        }
        return EmailSendResult::success('smtp', $msgId);
    }

    /** Send one SMTP command line terminated with CRLF. */
    private static function smtpWrite($fp, string $line): void
    {
        self::smtpWriteRaw($fp, $line . "\r\n");
    }

    /** Write a raw payload to the socket. */
    private static function smtpWriteRaw($fp, string $data): void
    {
        @fwrite($fp, $data);
    }

    /**
     * Read a (possibly multi-line) SMTP reply. Continuation lines have a
     * hyphen after the 3-digit code ("250-..."); the final line has a
     * space ("250 ..."). Returns the full block so callers inspect the
     * leading code via substr().
     */
    private static function smtpRead($fp): string
    {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                break;
            }
            $data .= $line;
            /* 4th char is a space on the last line of a reply. */
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
            $info = stream_get_meta_data($fp);
            if (!empty($info['timed_out'])) {
                break;
            }
        }
        return $data;
    }

    /**
     * Strip CR/LF from an address before it goes into a MAIL FROM /
     * RCPT TO command or a header — prevents SMTP command / header
     * injection via a crafted address.
     */
    private static function smtpAddr(string $addr): string
    {
        return str_replace(["\r", "\n"], '', trim($addr));
    }

    /**
     * Derive the EHLO hostname. RFC 5321 wants a FQDN; the From domain is
     * a reasonable, non-leaking choice (falls back to a literal).
     */
    private static function smtpEhloName(string $fromEmail): string
    {
        $at = strrpos($fromEmail, '@');
        $domain = $at !== false ? substr($fromEmail, $at + 1) : '';
        $domain = preg_replace('/[^A-Za-z0-9.\-]/', '', $domain) ?? '';
        return $domain !== '' ? $domain : 'localhost';
    }

    /**
     * Build the RFC 5322 message: headers + multipart/alternative body
     * (text + HTML). All header values are CRLF-stripped so neither the
     * subject nor the From name can inject extra headers.
     */
    private static function smtpBuildMessage(array $from, string $to, string $subject, string $bodyHtml, string $bodyText): string
    {
        $headerSafe = static fn (string $v): string => str_replace(["\r", "\n"], '', $v);

        $fromHeader = self::formatFrom($from);
        $boundary   = 'ihymns_' . bin2hex(random_bytes(16));
        $date       = gmdate('r');
        $messageId  = '<' . bin2hex(random_bytes(16)) . '@' . self::smtpEhloName($from['email']) . '>';
        if ($bodyText === '') {
            $bodyText = trim(strip_tags($bodyHtml));
        }

        $headers = [
            'Date: ' . $date,
            'From: ' . $headerSafe($fromHeader),
            'To: <' . self::smtpAddr($to) . '>',
            'Subject: ' . $headerSafe($subject),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $crlf = "\r\n";
        $body = '--' . $boundary . $crlf
              . 'Content-Type: text/plain; charset=UTF-8' . $crlf
              . 'Content-Transfer-Encoding: 8bit' . $crlf . $crlf
              . $bodyText . $crlf . $crlf
              . '--' . $boundary . $crlf
              . 'Content-Type: text/html; charset=UTF-8' . $crlf
              . 'Content-Transfer-Encoding: 8bit' . $crlf . $crlf
              . $bodyHtml . $crlf . $crlf
              . '--' . $boundary . '--' . $crlf;

        return implode($crlf, $headers) . $crlf . $crlf . $body;
    }

    /* -------------------------------------------------------------------
     * Driver: Microsoft Graph (OAuth2 app-only) — #1311
     *
     * The modern alternative to SMTP-AUTH for Microsoft 365, immune to the
     * Basic-Auth / SMTP-AUTH deprecation. OAuth2 client-credentials grant
     * (app-only) against the tenant token endpoint → POST /users/{sender}/
     * sendMail with the Mail.Send APPLICATION permission. Requires (Azure):
     * an app registration with Mail.Send application permission + admin
     * consent, a client secret, and the sender mailbox UPN. The client
     * secret + bearer token are NEVER logged (only short error codes are).
     * ----------------------------------------------------------------- */
    private static function sendViaGraph(string $to, string $subject, string $bodyHtml, string $bodyText, array $cfg): EmailSendResult
    {
        $tenant = trim((string)($cfg['email_graph_tenant_id'] ?? ''));
        $client = trim((string)($cfg['email_graph_client_id'] ?? ''));
        $secret = (string)($cfg['email_graph_client_secret'] ?? '');
        $sender = trim((string)($cfg['email_graph_sender'] ?? ''));
        if ($tenant === '' || $client === '' || $secret === '' || $sender === '') {
            return EmailSendResult::failure('graph', 'missing_tenant_client_secret_or_sender', 'NotConfigured');
        }

        $from      = self::oauthFrom($cfg, $sender);   /* default From = sender; explicit delegate = Send-As */
        $fromEmail = $from['email'];

        /* 1) OAuth2 client-credentials token. scope=.default grants the app's
              consented application permissions (Mail.Send). */
        $tok = self::httpPostForm(
            'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
            [
                'client_id'     => $client,
                'client_secret' => $secret,
                'scope'         => 'https://graph.microsoft.com/.default',
                'grant_type'    => 'client_credentials',
            ],
            []
        );
        if ($tok['err'] !== '') {
            return EmailSendResult::failure('graph', 'token_' . $tok['err'], 'TransportError');
        }
        if ($tok['code'] < 200 || $tok['code'] >= 300) {
            return EmailSendResult::failure('graph', 'token_http_' . $tok['code'] . ':' . self::oauthErr($tok['body']), 'AuthError', $tok['code']);
        }
        $accessToken = (string)(json_decode($tok['body'], true)['access_token'] ?? '');
        if ($accessToken === '') {
            return EmailSendResult::failure('graph', 'token_no_access_token', 'AuthError');
        }

        /* 2) sendMail. From defaults to the sender mailbox; an explicit
              different From requires Send-As granted on it (the SMTP delegate
              model). Setting From = the sender's own address is always valid. */
        $fromAddr = ['address' => $fromEmail];
        if (($from['name'] ?? '') !== '') { $fromAddr['name'] = (string)$from['name']; }
        $message = [
            'subject'      => $subject,
            'body'         => ['contentType' => 'HTML', 'content' => $bodyHtml],
            'toRecipients' => [['emailAddress' => ['address' => $to]]],
            'from'         => ['emailAddress' => $fromAddr],
        ];

        $resp = self::httpPostJson(
            'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender) . '/sendMail',
            ['message' => $message, 'saveToSentItems' => false],
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );
        if ($resp['err'] !== '') {
            return EmailSendResult::failure('graph', $resp['err'], 'TransportError');
        }
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            return EmailSendResult::success('graph', null);   /* 202 Accepted, no Message-Id */
        }
        return EmailSendResult::failure('graph', 'http_' . $resp['code'] . ':' . self::oauthErr($resp['body']), 'ProviderError', $resp['code']);
    }

    /* -------------------------------------------------------------------
     * Driver: Gmail API (OAuth2 service-account + domain-wide delegation) — #1311
     *
     * The Google Workspace equivalent of the Graph path. A service account
     * with domain-wide delegation for the gmail.send scope signs a JWT,
     * exchanges it for an access token impersonating the configured sender,
     * then POSTs a base64url RFC822 message to users/{sender}/messages/send.
     * Requires (Google Cloud): a project with the Gmail API enabled, a
     * service-account JSON key, and domain-wide delegation authorised for the
     * gmail.send scope in the Workspace Admin console. The private key +
     * bearer token are NEVER logged.
     * ----------------------------------------------------------------- */
    private static function sendViaGmailApi(string $to, string $subject, string $bodyHtml, string $bodyText, array $cfg): EmailSendResult
    {
        $saJson = (string)($cfg['email_gmail_sa_json'] ?? '');
        $sender = trim((string)($cfg['email_gmail_sender'] ?? ''));
        if ($saJson === '' || $sender === '') {
            return EmailSendResult::failure('gmail-api', 'missing_service_account_or_sender', 'NotConfigured');
        }
        if (!function_exists('openssl_sign')) {
            return EmailSendResult::failure('gmail-api', 'openssl_extension_missing', 'TransportError');
        }
        $sa = json_decode($saJson, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            return EmailSendResult::failure('gmail-api', 'invalid_service_account_json', 'NotConfigured');
        }
        /* token_uri comes from the pasted SA JSON; constrain it to a Google
           host (defence-in-depth) so a tampered key can't aim the signed
           assertion elsewhere. Falls back to the canonical endpoint. */
        $tokenUri = (string)($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $tuHost   = (string)parse_url($tokenUri, PHP_URL_HOST);
        if (stripos($tokenUri, 'https://') !== 0 || substr($tuHost, -15) !== '.googleapis.com') {
            $tokenUri = 'https://oauth2.googleapis.com/token';
        }

        $from = self::oauthFrom($cfg, $sender);   /* default From = impersonated sender; explicit delegate = verified send-as */

        /* 1) Build + sign the JWT assertion (RS256), impersonating $sender via
              domain-wide delegation (the `sub` claim). iat is backdated 60s to
              absorb server clock-skew vs Google (a fast clock → invalid_grant). */
        $now    = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => (string)$sa['client_email'],
            'sub'   => $sender,
            'scope' => 'https://www.googleapis.com/auth/gmail.send',
            'aud'   => $tokenUri,
            'iat'   => $now - 60,
            'exp'   => $now + 3600,
        ];
        $signingInput = self::b64url((string)json_encode($header)) . '.' . self::b64url((string)json_encode($claims));
        $signature    = '';
        if (!@openssl_sign($signingInput, $signature, (string)$sa['private_key'], OPENSSL_ALGO_SHA256)) {
            return EmailSendResult::failure('gmail-api', 'jwt_signing_failed', 'AuthError');
        }
        $assertion = $signingInput . '.' . self::b64url($signature);

        /* 2) Exchange the JWT for an access token. */
        $tok = self::httpPostForm($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ], []);
        if ($tok['err'] !== '') {
            return EmailSendResult::failure('gmail-api', 'token_' . $tok['err'], 'TransportError');
        }
        if ($tok['code'] < 200 || $tok['code'] >= 300) {
            return EmailSendResult::failure('gmail-api', 'token_http_' . $tok['code'] . ':' . self::oauthErr($tok['body']), 'AuthError', $tok['code']);
        }
        $accessToken = (string)(json_decode($tok['body'], true)['access_token'] ?? '');
        if ($accessToken === '') {
            return EmailSendResult::failure('gmail-api', 'token_no_access_token', 'AuthError');
        }

        /* 3) Build the RFC822 message (reuse the SMTP MIME builder) and send it
              base64url-encoded via the Gmail API. */
        $raw  = self::smtpBuildMessage($from, $to, $subject, $bodyHtml, $bodyText);
        $resp = self::httpPostJson(
            'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode($sender) . '/messages/send',
            ['raw' => self::b64url($raw)],
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );
        if ($resp['err'] !== '') {
            return EmailSendResult::failure('gmail-api', $resp['err'], 'TransportError');
        }
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            $msgId = (string)(json_decode($resp['body'], true)['id'] ?? '');
            return EmailSendResult::success('gmail-api', $msgId !== '' ? $msgId : null);
        }
        return EmailSendResult::failure('gmail-api', 'http_' . $resp['code'] . ':' . self::oauthErr($resp['body']), 'ProviderError', $resp['code']);
    }

    /** base64url (RFC 4648 §5, unpadded) — JWT segments + Gmail raw message. */
    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Extract a SHORT, secret-free error summary from an OAuth/REST error body.
     * Never receives or returns a token/secret — the success bodies (which carry
     * access_token) are parsed separately and never reach here.
     */
    private static function oauthErr(string $body): string
    {
        $j = json_decode($body, true);
        if (is_array($j)) {
            if (isset($j['error']) && is_array($j['error'])) {        /* Graph: {error:{code,message}} */
                return self::sanitiseForLog(trim((string)($j['error']['code'] ?? '') . ' ' . (string)($j['error']['message'] ?? '')), 180);
            }
            if (isset($j['error_description'])) {                     /* Google/AAD: {error,error_description} */
                return self::sanitiseForLog((string)$j['error_description'], 180);
            }
            if (isset($j['error']) && is_string($j['error'])) {
                return self::sanitiseForLog($j['error'], 180);
            }
        }
        return self::sanitiseForLog($body, 120);
    }

    /**
     * #1311 — From for the OAuth2 drivers. Prefers an EXPLICIT delegate /
     * send-as address (email_smtp_from_address) only; otherwise defaults to the
     * OAuth sender mailbox (always authorised), NOT the generic cross-provider
     * email_from_address — so a leftover From from another provider's setup
     * can't silently 400/rewrite a Graph/Gmail send. A delegate From still
     * needs Send-As (Graph) / a verified "Send mail as" alias (Gmail).
     */
    private static function oauthFrom(array $cfg, string $sender): array
    {
        $delegate = trim((string)($cfg['email_smtp_from_address'] ?? ''));
        $email = ($delegate !== '' && filter_var($delegate, FILTER_VALIDATE_EMAIL)) ? $delegate : $sender;
        return ['email' => $email, 'name' => self::fromAddress($cfg)['name']];
    }

    /* -------------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------- */

    /**
     * Render a template's HTML + text + subject from the includes/email-templates
     * directory. Returns null if either variant is missing.
     */
    private static function renderTemplate(string $template, array $vars): ?array
    {
        $base = __DIR__ . DIRECTORY_SEPARATOR . 'email-templates';
        $htmlPath = $base . DIRECTORY_SEPARATOR . $template . '.html.php';
        $textPath = $base . DIRECTORY_SEPARATOR . $template . '.txt.php';
        if (!is_file($htmlPath) || !is_file($textPath)) {
            return null;
        }

        $renderOne = static function (string $path, array $vars): array {
            $SUBJECT = '';
            extract($vars, EXTR_SKIP);
            ob_start();
            /** @psalm-suppress UnresolvableInclude */
            include $path;
            $body = (string)ob_get_clean();
            return ['subject' => (string)$SUBJECT, 'body' => $body];
        };

        $html = $renderOne($htmlPath, $vars);
        $text = $renderOne($textPath, $vars);

        return [
            /* The HTML template is the canonical subject source — both
               templates declare $SUBJECT but the HTML one wins to keep
               the two variants aligned without runtime checks. */
            'subject' => $html['subject'] !== '' ? $html['subject'] : $text['subject'],
            'html'    => $html['body'],
            'text'    => $text['body'],
        ];
    }

    /**
     * Resolve and cache email_service settings for the current request.
     */
    private static function loadSettings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        $keys = [
            'email_service',
            'email_from_address', 'email_from_name',
            'email_smtp_host', 'email_smtp_port', 'email_smtp_user',
            'email_smtp_pass', 'email_smtp_secure',
            /* feature C — SMTP provider preset + delegate / send-as From.
               The preset only pre-fills host/port/secure in the UI; the
               delegate address overrides the From / Sender at send time. */
            'email_smtp_preset',
            'email_smtp_from_address', 'email_smtp_from_name',
            'email_sendgrid_api_key',
            'email_mailgun_api_key', 'email_mailgun_domain',
            'email_ses_region', 'email_ses_access_key', 'email_ses_secret_key',
            /* #1311 — OAuth2 API transport. email_auth_method ('smtp'|'oauth2')
               selects, for the office365/gmail providers, between the SMTP-AUTH
               driver and the OAuth2 REST driver (Microsoft Graph / Gmail API).
               office365 → Graph (tenant/client/secret + sender mailbox);
               gmail → Gmail API (service-account JSON + impersonated sender). */
            'email_auth_method',
            'email_graph_tenant_id', 'email_graph_client_id',
            'email_graph_client_secret', 'email_graph_sender',
            'email_gmail_sa_json', 'email_gmail_sender',
        ];
        try {
            $db = getDbMysqli();
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $db->prepare(
                "SELECT SettingKey, SettingValue FROM tblAppSettings WHERE SettingKey IN ({$placeholders})"
            );
            $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[EmailService] settings load failed: ' . $e->getMessage());
            $rows = [];
        }
        /* Transparently decrypt secret-flagged values (#1466). secretDecrypt()
           returns a legacy PLAINTEXT value unchanged (a verified no-op until the
           encrypt-in-place migration runs) and the decrypted plaintext for an
           enc:v1 envelope. A decrypt failure (master key absent/wrong on this
           docroot) is FAIL-SAFE: the value becomes '' (unset), so the driver
           treats the credential as not-configured (dormant) rather than
           attempting auth with ciphertext. secret_crypto.php is loaded
           DEFENSIVELY (mirrors maintenance.php) so a docroot that hasn't yet
           received it (a lagging mirror during the readers-first rollout)
           degrades to raw passthrough — email still works — instead of a
           fatal require error. */
        $secretCryptoFile = __DIR__ . DIRECTORY_SEPARATOR . 'secret_crypto.php';
        if (is_file($secretCryptoFile)) {
            require_once $secretCryptoFile;
        }
        $out = [];
        foreach ($rows as $r) {
            $rawVal = (string)$r['SettingValue'];
            if (function_exists('secretIsEncrypted') && secretIsEncrypted($rawVal)) {
                $decrypted = secretDecrypt($rawVal);
                $out[(string)$r['SettingKey']] = $decrypted ?? '';
            } else {
                $out[(string)$r['SettingKey']] = $rawVal;
            }
        }
        return self::$settings = $out;
    }

    /**
     * Resolve the effective From / Sender.
     *
     * feature C — DELEGATE / "send-as": when an SMTP delegate address is
     * configured (email_smtp_from_address), it overrides the generic
     * email_from_address so the message is sent on behalf of the
     * delegated mailbox while AUTH still uses email_smtp_user. The
     * delegate name (email_smtp_from_name) falls back to the generic
     * From name. If no delegate is set, behaviour is identical to the
     * pre-feature-C path (generic From for every provider).
     *
     * The delegate keys are SMTP-specific in the UI, but reading them
     * here is harmless for the API providers (they have no delegate
     * fields, so the keys are empty and we fall through to the generic
     * From) and keeps the resolution in one place.
     */
    private static function fromAddress(array $cfg): array
    {
        $delegate = trim((string)($cfg['email_smtp_from_address'] ?? ''));
        $email = ($delegate !== '' && filter_var($delegate, FILTER_VALIDATE_EMAIL))
            ? $delegate
            : (string)($cfg['email_from_address'] ?? '');

        $delegateName = trim((string)($cfg['email_smtp_from_name'] ?? ''));
        $name = $delegateName !== ''
            ? $delegateName
            : (string)($cfg['email_from_name'] ?? 'iHymns');

        return [
            'email' => $email,
            'name'  => $name,
        ];
    }

    private static function formatFrom(array $from): string
    {
        if (($from['name'] ?? '') !== '') {
            /* RFC 5322 display-name + addr-spec. Provider parsers all
               accept this canonical form. */
            return sprintf('"%s" <%s>',
                addslashes(str_replace('"', '', $from['name'])),
                $from['email']
            );
        }
        return (string)$from['email'];
    }

    private static function recordAndReturn(string $template, string $to, EmailSendResult $r): EmailSendResult
    {
        if (function_exists('logActivity')) {
            logActivity(
                'email.send',
                'email',
                $template . ':' . ($r->providerMessageId ?? ''),
                [
                    'template'    => $template,
                    'provider'    => $r->provider,
                    'ok'          => $r->ok,
                    'error_class' => $r->errorClass,
                    'http_status' => $r->httpStatus,
                    /* Recipient is recorded as a hash prefix so the
                       activity log doesn't grow into a deliverability
                       database. The provider's dashboard remains the
                       source of truth for "did this email reach this
                       address". */
                    'to_hash'     => substr(hash('sha256', mb_strtolower($to)), 0, 12),
                ],
                $r->ok ? 'success' : 'failure'
            );
        }
        if (!$r->ok) {
            error_log(sprintf(
                '[EmailService] send failed template=%s provider=%s class=%s err=%s',
                self::sanitiseForLog($template, 60),
                self::sanitiseForLog($r->provider, 30),
                self::sanitiseForLog((string)$r->errorClass, 40),
                self::sanitiseForLog((string)$r->error, 200)
            ));
        }
        return $r;
    }

    /**
     * Strip control characters + truncate. Provider error bodies can
     * carry newlines that would let log-injection fragment a single
     * audit line into spoofed multi-line entries.
     */
    private static function sanitiseForLog(string $s, int $maxLen): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        if (strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen) . '...';
        }
        return $s;
    }

    /**
     * Extract a header value (case-insensitive) from a raw response
     * header block — used by the SendGrid driver to fish out
     * X-Message-Id without pulling in the curl info array.
     */
    private static function extractHeader(string $headers, string $name): string
    {
        $lines = preg_split('/\r?\n/', $headers) ?: [];
        $needle = strtolower($name);
        foreach ($lines as $line) {
            $sep = strpos($line, ':');
            if ($sep === false) continue;
            if (strtolower(substr($line, 0, $sep)) === $needle) {
                return trim(substr($line, $sep + 1));
            }
        }
        return '';
    }

    /* HTTP helpers — small wrappers around curl. Kept private to the
       service so a future swap (e.g. Guzzle if Composer ever lands)
       has one surface to update. */

    private static function httpPostJson(string $url, array $payload, array $headers): array
    {
        return self::httpPostRaw($url, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', $headers);
    }

    private static function httpPostForm(string $url, array $payload, array $headers): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return self::httpPostRaw($url, http_build_query($payload, '', '&', PHP_QUERY_RFC3986), $headers);
    }

    private static function httpPostRaw(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return ['err' => 'curl_extension_missing', 'code' => 0, 'body' => '', 'headers' => ''];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['err' => 'curl_init_failed', 'code' => 0, 'body' => '', 'headers' => ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'iHymns-EmailService/1.0',
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch) ?: 'curl_exec_failed';
            curl_close($ch);
            return ['err' => $err, 'code' => 0, 'body' => '', 'headers' => ''];
        }
        $code       = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $hdr = (string)substr((string)$raw, 0, $headerSize);
        $bod = (string)substr((string)$raw, $headerSize);
        return ['err' => '', 'code' => $code, 'body' => $bod, 'headers' => $hdr];
    }
}
