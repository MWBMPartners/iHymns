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
 *   - smtp     : NOT YET IMPLEMENTED (#898 follow-up). Returns a
 *                structured failure so the caller surfaces a real 500
 *                rather than silently dropping the email.
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
            case 'smtp':
                /* Documented as TODO in #898 — see configuration.php
                   instructions panel. Returning a structured failure
                   makes the caller surface a real 500 rather than
                   silently dropping the email. */
                return EmailSendResult::failure(
                    'smtp',
                    'smtp_provider_not_yet_implemented',
                    'NotImplemented'
                );
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
            'email_sendgrid_api_key',
            'email_mailgun_api_key', 'email_mailgun_domain',
            'email_ses_region', 'email_ses_access_key', 'email_ses_secret_key',
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
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['SettingKey']] = (string)$r['SettingValue'];
        }
        return self::$settings = $out;
    }

    private static function fromAddress(array $cfg): array
    {
        return [
            'email' => (string)($cfg['email_from_address'] ?? ''),
            'name'  => (string)($cfg['email_from_name']    ?? 'iHymns'),
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
