<?php

declare(strict_types=1);

/**
 * iHymns — CAPTCHA verification core (#947 / #340)
 *
 * ELI5: this file lets an admin switch on a "prove you're human" challenge
 * (Cloudflare Turnstile, hCaptcha or Google reCAPTCHA v2) in front of a few
 * forms — sign-up, sign-in, password reset, song requests. The browser shows
 * the challenge widget; this server checks the answer with the provider before
 * letting the request through. The provider's SECRET KEY never reaches a
 * browser — this server does the talking (rule #38's CueRCode custody).
 *
 * DETAIL:
 * -------
 * ONE provider-agnostic, DORMANT-UNTIL-KEYED, FAIL-OPEN-on-infrastructure core.
 * Side-effect-free to require: including this file opens no connection and
 * makes no HTTP call — it only declares functions/constants (the same
 * discipline as includes/cuercode_client.php, the outbound-service precedent
 * this is modelled on — rule #22).
 *
 * DORMANCY (rule #28's posture): with no provider + both keys configured, every
 * gate returns "allowed" BEFORE any network I/O, captchaCspOrigins() is empty
 * (so index.php's CSP is byte-identical), captchaClientConfig() is null (so
 * app_status emits no new key), and captchaWidgetHtml() is '' (so
 * manage/login.php is byte-identical). The feature does NOTHING until an admin
 * picks a provider, pastes both keys, and ticks at least one form.
 *
 * FAIL POSTURE (the abuse-protection floor stays underneath, never replaced):
 *   - FAIL-OPEN on infrastructure: a provider outage / DB blip / missing curl
 *     must never lock a congregation out on Sunday morning. A transport
 *     failure, non-200, or unparsable body from the provider is logged and the
 *     request is ALLOWED — the per-IP/per-account/per-identifier rate-limit
 *     budgets (includes/rate_limit.php) still cap the rate underneath.
 *   - FAIL-CLOSED on the challenge itself: a missing / overlong / provider-
 *     rejected token on an ENABLED form is a loud, branchable 403.
 *
 * THE SECRET NEVER REACHES A BROWSER (rule #38): captcha_secret_key is
 * server-proxied only, registered in secretSettingKeys() (encrypted at rest
 * from its first save), and appears in NO client emit.
 *
 * ONE registry, one verify seam, one gate, one body key (rule #22 / #35):
 * providers are DATA in captchaProviders(); enforcement sites call the ONE
 * captchaGate(); the client learns provider/site-key/script-URL from the
 * server emit (captchaClientConfig()) — there is NO PHP<->JS provider table to
 * drift. Clients branch on HTTP 403 + reason:'captcha_required', never on prose
 * (rule #35).
 *
 * Provider wire shape (Turnstile / hCaptcha / reCAPTCHA v2 all share it):
 *   POST {verify-url}  (application/x-www-form-urlencoded)
 *     secret=<secret>&response=<token>&remoteip=<ip>
 *   200: {"success": true|false, ...}
 * and one browser API shape (window.<renderGlobal>.render/getResponse/reset),
 * which is what makes a single seam honest rather than aspirational.
 *
 * @see .claude/account-security-1027-947-340-plan.md (full design)
 * @see includes/cuercode_client.php (the mirrored outbound-service precedent)
 * @link https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 * @link https://docs.hcaptcha.com/#verify-the-user-response-server-side
 * @link https://developers.google.com/recaptcha/docs/verify
 * @link https://www.php.net/manual/en/book.curl.php
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';    /* getAppSetting() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'secret_crypto.php';  /* transparent decrypt of captcha_secret_key inside getAppSetting() */

/* -------------------------------------------------------------------------
 * SETTINGS KEYS + WIRE VOCABULARY — defined ONCE here so /manage and any
 * status surface never re-type a literal and drift (rule #35).
 * ---------------------------------------------------------------------- */
const CAPTCHA_SETTING_PROVIDER   = 'captcha_provider';       /* registry key, or 'none' */
const CAPTCHA_SETTING_SITE_KEY   = 'captcha_site_key';       /* public key — safe to emit to a browser */
const CAPTCHA_SETTING_SECRET_KEY = 'captcha_secret_key';     /* SECRET — encrypted at rest, never emitted */
const CAPTCHA_SETTING_FORMS      = 'captcha_enabled_forms';  /* CSV of form keys, app-validated (rule #20 — no ENUM/SET, no DDL) */

/* The token the browser widget produces travels as THIS body key on every
   gated JSON endpoint (the plain manage/login.php form reads the provider's
   own POST field name instead — see the registry 'field'). Mirrored once in
   js/modules/captcha-widget.js; PHP<->JS lockstep-guarded (test-captcha-client.js). */
const IHYMNS_CAPTCHA_BODY_KEY = 'captcha_token';

/* The ONE machine-readable refusal reason. ONE value for both "token missing"
   and "token invalid" — distinguishing them would tell a bot which failure it
   had, and the client behaviour is identical either way (render/reset the
   widget, retry). Clients branch on this + HTTP 403, never on the prose. */
const IHYMNS_CAPTCHA_REASON = 'captcha_required';

/* Outbound-call bounds (code constants, not settings rows — fewer knobs, no
   way to misconfigure the fail-soft bound). The house curl band. */
const CAPTCHA_CURL_CONNECT_TIMEOUT = 3;
const CAPTCHA_CURL_TIMEOUT         = 5;
const CAPTCHA_MAX_RESPONSE_BYTES   = 65536;  /* 64 KiB — a siteverify JSON is tiny; generously capped */
const CAPTCHA_MAX_TOKEN_BYTES      = 2048;   /* provider tokens are well under this; longer = garbage */

/**
 * The ONE list of gateable form keys. A new form is ONE entry here + one
 * captchaGate() call site + the tree-derived guard (test-captcha-gate.php)
 * that demands an enforcement site for every key.
 *
 * No 'contact' / 'share_setlist' keys — no such forms exist (rule #44:
 * collect nothing the app acts on).
 *
 * @return list<string>
 */
function captchaFormKeys(): array
{
    return [
        'registration',   /* api.php auth_register            — breaks native signup if enabled */
        'login',          /* api.php auth_login               — breaks native sign-in if enabled */
        'password_reset', /* api.php auth_forgot_password      — breaks native reset if enabled */
        'email_login',    /* api.php auth_email_login_request  — breaks native magic-link if enabled */
        'song_request',   /* api.php song_request_submit       — web-only endpoint, no native impact */
        'manage_login',   /* manage/login.php POST             — no native impact */
    ];
}

/**
 * The provider registry — the ONE table. A new provider is one entry; nothing
 * else in PHP or JS names a provider (the client learns everything it needs
 * from captchaClientConfig(), which reads this).
 *
 * Fields:
 *   label        human name for the admin <select>
 *   script       the widget <script src> (also the CSP script origin's source)
 *   verify       the server-side siteverify endpoint — a CONSTANT from PHP
 *                source, never user-influenced, so it is SSRF-safe by
 *                construction (unlike CueRCode's admin-set base URL)
 *   field        the POST field the widget injects into a plain form (read by
 *                manage/login.php; the JS surfaces send IHYMNS_CAPTCHA_BODY_KEY)
 *   widgetClass  the div class the provider auto-renders into
 *   renderGlobal window.<name>.render/getResponse/reset (the shared JS API)
 *   cspScript    script-src origins the provider needs
 *   cspFrame     frame-src origins the provider needs
 *   selectable   false = reserved (refused at save AND at read); a different
 *                flow (e.g. reCAPTCHA v3 is score-based) or simply not shipped
 *
 * @return array<string,array<string,mixed>>
 */
function captchaProviders(): array
{
    return [
        'turnstile' => [
            'label'       => 'Cloudflare Turnstile',
            'script'      => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'verify'      => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'field'       => 'cf-turnstile-response',
            'widgetClass' => 'cf-turnstile',
            'renderGlobal'=> 'turnstile',
            'cspScript'   => ['https://challenges.cloudflare.com'],
            'cspFrame'    => ['https://challenges.cloudflare.com'],
            'selectable'  => true,
        ],
        'hcaptcha' => [
            'label'       => 'hCaptcha',
            'script'      => 'https://js.hcaptcha.com/1/api.js',
            'verify'      => 'https://api.hcaptcha.com/siteverify',
            'field'       => 'h-captcha-response',
            'widgetClass' => 'h-captcha',
            'renderGlobal'=> 'hcaptcha',
            /* The one wildcard — hCaptcha's documented CSP requirement (it
               loads sub-resources from *.hcaptcha.com). Present in the CSP
               ONLY while hCaptcha is the active provider (§A.7). */
            'cspScript'   => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
            'cspFrame'    => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
            'selectable'  => true,
        ],
        'recaptcha_v2' => [
            'label'       => 'Google reCAPTCHA v2',
            'script'      => 'https://www.google.com/recaptcha/api.js',
            'verify'      => 'https://www.google.com/recaptcha/api/siteverify',
            'field'       => 'g-recaptcha-response',
            'widgetClass' => 'g-recaptcha',
            'renderGlobal'=> 'grecaptcha',
            'cspScript'   => ['https://www.google.com', 'https://www.gstatic.com'],
            'cspFrame'    => ['https://www.google.com'],
            'selectable'  => true,
        ],
        /* Reserved: reCAPTCHA v3 is score-based with a different (invisible,
           action-scored) flow — adding it later is registry data + one scoring
           branch, no schema (rule #20). Picking it is refused at save AND at
           read (selectable=false → captchaResolveConfig() returns null). */
        'recaptcha_v3' => [
            'label'      => 'Google reCAPTCHA v3 (reserved — score-based, not yet wired)',
            'selectable' => false,
        ],
    ];
}

/* =========================================================================
 * PURE CORE (no DB, no network) — the apiForgotPasswordDecision() test pattern.
 * ========================================================================= */

/**
 * ELI5: "do we have a real, usable CAPTCHA setup?" — a provider we support AND
 * both keys. Returns the full config, or null.
 * WHY PURE: the single resolution rule, testable with no DB. 'none' / '' /
 * unknown / reserved provider → null. A missing site OR secret key → null.
 *
 * @return array<string,mixed>|null The registry entry merged with the keys, or null.
 */
function captchaResolveConfig(?string $provider, ?string $siteKey, ?string $secretKey): ?array
{
    $provider  = trim((string)$provider);
    $siteKey   = trim((string)$siteKey);
    $secretKey = trim((string)$secretKey);
    if ($provider === '' || $provider === 'none' || $siteKey === '' || $secretKey === '') {
        return null;
    }
    $reg = captchaProviders();
    if (!isset($reg[$provider]) || empty($reg[$provider]['selectable'])) {
        return null;   /* unknown or reserved (non-selectable) provider */
    }
    return array_merge($reg[$provider], [
        'provider'   => $provider,
        'site_key'   => $siteKey,
        'secret_key' => $secretKey,
    ]);
}

/**
 * ELI5: turn the admin's comma list of form names into a clean, validated set.
 * WHY: a typo must fail CLOSED-to-disabled, never fatal — an unknown key is
 * dropped, not an error. '' → []. Whitespace + duplicates folded.
 *
 * @return list<string>
 */
function captchaParseForms(?string $csv): array
{
    $csv = (string)$csv;
    if (trim($csv) === '') {
        return [];
    }
    $valid = captchaFormKeys();
    $out   = [];
    foreach (explode(',', $csv) as $part) {
        $k = trim($part);
        if ($k !== '' && in_array($k, $valid, true) && !in_array($k, $out, true)) {
            $out[] = $k;
        }
    }
    return $out;
}

/**
 * ELI5: the final yes/no — "should this request be refused for want of a valid
 * challenge?" null = allowed; an array = the 403 body the caller sends.
 *
 * SIGNATURE PROPERTY (the #1028 anti-enumeration trick): there is NO account /
 * identity parameter, so account existence CANNOT influence the refusal —
 * enumeration safety BY CONSTRUCTION. Asserted via Reflection in the guard.
 *
 * @param array<string,mixed>|null $config       resolved provider config, or null (dormant)
 * @param list<string>             $enabledForms parsed enabled-form list
 * @param string                   $form         the form key being gated
 * @param bool                     $tokenOk       did the provider accept the token?
 * @return array{error:string,reason:string}|null null=allowed, array=refuse (send as 403)
 */
function captchaGateDecision(?array $config, array $enabledForms, string $form, bool $tokenOk): ?array
{
    if ($config === null || !in_array($form, $enabledForms, true)) {
        return null;   /* dormant, or this form isn't gated → allowed */
    }
    if ($tokenOk) {
        return null;   /* provider accepted the answer → allowed */
    }
    return [
        'error'  => 'Please complete the verification challenge and try again.',
        'reason' => IHYMNS_CAPTCHA_REASON,
    ];
}

/* =========================================================================
 * THIN DB WRAPPERS — read the three/four tblAppSettings rows via getAppSetting()
 * (transparent secret decrypt), all empty-handed when unconfigured.
 * ========================================================================= */

/**
 * ELI5: the live, resolved config for this install — or null when dormant.
 * WHY: the ONE resolution point every DB-backed caller shares. The secret
 * arrives already decrypted (getAppSetting() transparently unwraps the
 * `enc:v1:` envelope once captcha_secret_key is in secretSettingKeys()).
 * Memoized per request.
 *
 * @return array<string,mixed>|null
 */
function captchaConfig(): ?array
{
    static $cached = false;   /* false = unresolved; null = resolved-and-dormant */
    if ($cached !== false) {
        return $cached;
    }
    $provider  = (string)(getAppSetting(CAPTCHA_SETTING_PROVIDER, 'none') ?? 'none');
    $siteKey   = (string)(getAppSetting(CAPTCHA_SETTING_SITE_KEY, '') ?? '');
    $secretKey = (string)(getAppSetting(CAPTCHA_SETTING_SECRET_KEY, '') ?? '');
    return $cached = captchaResolveConfig($provider, $siteKey, $secretKey);
}

/** ELI5: is CAPTCHA set up at all (provider + both keys)? */
function captchaConfigured(): bool
{
    return captchaConfig() !== null;
}

/**
 * ELI5: which forms has the admin actually switched the challenge on for?
 * @return list<string>
 */
function captchaEnabledFormsList(): array
{
    return captchaParseForms((string)(getAppSetting(CAPTCHA_SETTING_FORMS, '') ?? ''));
}

/** ELI5: is the challenge live for THIS specific form? (config + ticked) */
function captchaEnabledForForm(string $form): bool
{
    return captchaConfig() !== null && in_array($form, captchaEnabledFormsList(), true);
}

/**
 * ELI5: which extra origins does the active provider need in the CSP?
 * WHY: index.php appends these to script-src/frame-src ONLY when configured, so
 * an unconfigured install's CSP is byte-identical. Origins live in the registry
 * alone — index.php never names a hostname (guard-banned).
 *
 * @return array{script:list<string>,frame:list<string>}
 */
function captchaCspOrigins(): array
{
    $config = captchaConfig();
    if ($config === null) {
        return ['script' => [], 'frame' => []];
    }
    return [
        'script' => array_values($config['cspScript'] ?? []),
        'frame'  => array_values($config['cspFrame'] ?? []),
    ];
}

/**
 * ELI5: the small bundle of NON-SECRET facts a browser needs to draw + read the
 * widget — provider, public site key, the script URL, the JS global, the POST
 * field name, and which forms are gated. NULL when dormant (so app_status emits
 * no new key). The SECRET is deliberately absent.
 *
 * @return array{provider:string,siteKey:string,scriptUrl:string,renderGlobal:string,field:string,forms:list<string>}|null
 */
function captchaClientConfig(): ?array
{
    $config = captchaConfig();
    if ($config === null) {
        return null;
    }
    return [
        'provider'     => (string)$config['provider'],
        'siteKey'      => (string)$config['site_key'],
        'scriptUrl'    => (string)$config['script'],
        'renderGlobal' => (string)$config['renderGlobal'],
        'field'        => (string)$config['field'],
        'forms'        => captchaEnabledFormsList(),
    ];
}

/* =========================================================================
 * THE VERIFY SEAM + THE ONE GATE.
 * ========================================================================= */

/**
 * ELI5: ask the provider "is this human's answer genuine?" — true/false, never
 * throw. Garbage answers are rejected without a network call; a provider we
 * can't reach FAILS OPEN (true) so an outage never bricks a form.
 *
 * WHY the SSRF worry that CueRCode had does NOT apply: the verify URL is a
 * CONSTANT from PHP source (the registry), never anything the caller supplies —
 * so no host-binding dance is needed. No redirects, SSL verify on, response
 * read through an aborting write-callback so an oversized body is never fully
 * buffered.
 *
 * SINGLE-USE is the provider's contract: all three providers consume a token at
 * first siteverify, so a replayed token verifies false — #947's "token not
 * reusable" with no local replay cache to build, corrupt or leak.
 *
 * @param string|null $token    the widget's response token
 * @param string      $clientIp REMOTE_ADDR (passed to the provider as remoteip)
 * @return bool true = accept (or fail-open on infra failure); false = reject
 */
function captchaVerifyToken(?string $token, string $clientIp): bool
{
    $token = (string)$token;
    if ($token === '' || strlen($token) > CAPTCHA_MAX_TOKEN_BYTES) {
        return false;   /* missing / overlong = garbage → fail CLOSED, no outbound call */
    }
    $config = captchaConfig();
    if ($config === null || !function_exists('curl_init')) {
        /* Cannot verify (dormant — unreachable via the gate — or curl missing):
           FAIL OPEN. The rate-limit floor still caps the rate. */
        return true;
    }
    $verifyUrl = (string)($config['verify'] ?? '');
    if ($verifyUrl === '' || !str_starts_with($verifyUrl, 'https://')) {
        return true;   /* registry misconfigured — fail open, log below via the caller path */
    }

    $ch = curl_init();
    if ($ch === false) {
        return true;   /* fail open */
    }
    $buf = '';
    $cap = CAPTCHA_MAX_RESPONSE_BYTES;
    $writeFn = static function ($handle, string $chunk) use (&$buf, $cap): int {
        $buf .= $chunk;
        if (strlen($buf) > $cap) {
            return -1;   /* abort — never buffer an oversized body */
        }
        return strlen($chunk);
    };
    curl_setopt_array($ch, [
        CURLOPT_URL            => $verifyUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => (string)$config['secret_key'],
            'response' => $token,
            'remoteip' => $clientIp,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_USERAGENT      => 'iHymns-CAPTCHA/1.0',
        CURLOPT_CONNECTTIMEOUT => CAPTCHA_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => CAPTCHA_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,   /* never follow a redirect (SSRF) */
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION  => $writeFn,
    ]);
    curl_exec($ch);
    $errno      = curl_errno($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
        /* Transport failure / non-2xx: an attacker CANNOT induce this from
           outside (garbage tokens get a definitive success:false above), so
           fail-open here degrades only on a genuine provider outage — logged,
           floor limits intact (§A.4). */
        error_log('[iHymns] CAPTCHA verify transport failure (errno=' . $errno . ', http=' . $httpStatus . ') — failing open');
        return true;
    }
    $decoded = json_decode($buf, true);
    if (!is_array($decoded) || !array_key_exists('success', $decoded)) {
        error_log('[iHymns] CAPTCHA verify returned unparsable body — failing open');
        return true;
    }
    return $decoded['success'] === true;
}

/**
 * ELI5: the ONE line every gated endpoint calls. Given a form name and the
 * token the client sent, returns null ("let them through") or a 403 body
 * ("refuse — show the challenge").
 *
 * ORDER IS LOAD-BEARING: when the form is NOT enabled (or the install is
 * dormant) this returns null BEFORE any network call — so a disabled form never
 * costs an outbound siteverify, and dormancy stays a property of the install.
 * Only when a form is genuinely gated do we spend a verify call.
 *
 * @param string      $form  a captchaFormKeys() value
 * @param string|null $token the response token from the widget
 * @return array{error:string,reason:string}|null null=allowed, array=send 403
 */
function captchaGate(string $form, ?string $token): ?array
{
    $config = captchaConfig();
    $forms  = captchaEnabledFormsList();
    if ($config === null || !in_array($form, $forms, true)) {
        return null;   /* dormant / form not gated — NO network call */
    }
    $ok = captchaVerifyToken($token, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return captchaGateDecision($config, $forms, $form, $ok);
}

/**
 * ELI5: the server-rendered widget markup for a plain (non-SPA) form — the
 * provider's auto-render div + its script tag. '' when the form is disabled.
 * Sole consumer: manage/login.php (a full server-rendered page, NOT an SPA
 * fragment — rule #30 untouched; the /manage CSP sets no script-src so no CSP
 * change is needed there).
 *
 * @param string $form a captchaFormKeys() value (typically 'manage_login')
 * @return string HTML, or '' when dormant / this form is not gated
 */
function captchaWidgetHtml(string $form): string
{
    $config = captchaConfig();
    if ($config === null || !in_array($form, captchaEnabledFormsList(), true)) {
        return '';
    }
    $cls     = htmlspecialchars((string)($config['widgetClass'] ?? ''), ENT_QUOTES, 'UTF-8');
    $siteKey = htmlspecialchars((string)($config['site_key'] ?? ''), ENT_QUOTES, 'UTF-8');
    $script  = htmlspecialchars((string)($config['script'] ?? ''), ENT_QUOTES, 'UTF-8');
    return '<div class="' . $cls . '" data-sitekey="' . $siteKey . '"></div>'
         . "\n" . '<script src="' . $script . '" async defer></script>';
}
