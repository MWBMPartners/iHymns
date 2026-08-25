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
 * picks a provider, pastes both keys, and ticks at least one form. Dropping an
 * empty CAPTCHA_DISABLED file beside this one returns a CONFIGURED install to
 * exactly that state (the SFTP break-glass — see CAPTCHA_KILL_FILE_NAME).
 *
 * FAIL POSTURE (the abuse-protection floor stays underneath, never replaced):
 *   - FAIL-OPEN on infrastructure: a provider outage / DB blip / missing curl
 *     must never lock a congregation out on Sunday morning. A transport
 *     failure, non-200, or unparsable body from the provider is logged and the
 *     request is ALLOWED — the per-IP/per-account/per-identifier rate-limit
 *     budgets (includes/rate_limit.php) still cap the rate underneath.
 *   - FAIL-CLOSED on the challenge itself: a missing / overlong / provider-
 *     rejected token on an ENABLED form is a loud, branchable 403.
 *   - …EXCEPT during a SERVER-VERIFIED provider outage (the grace window). The
 *     fail-open above only ever helped a request that CARRIED a token, which
 *     during a widget-load outage means it only ever helped bots: a real user
 *     whose widget never rendered has nothing to send and fails closed. The
 *     window closes that asymmetry — while this server's own probes confirm the
 *     provider is unreachable (or is rejecting our secret), non-strict gated
 *     forms fall back to the pre-CAPTCHA defence floor, loudly and
 *     self-closingly. It reads NOTHING from the request: see the
 *     "PROVIDER HEALTH" section and captchaOutageDecision().
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
 * @see .claude/captcha-native-and-outage-plan.md §3 (the outage grace window)
 * @see includes/cuercode_client.php (the mirrored outbound-service precedent)
 * @see includes/intapps_client.php (the cached outbound-service-state precedent)
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

/* -------------------------------------------------------------------------
 * OUTAGE-FALLBACK VOCABULARY + BOUNDS (the grace window)
 *
 * ELI5: if the human-check company's own service goes down, real people can no
 * longer get a "you're human" ticket — so the forms would lock EVERYONE out
 * until an admin noticed. These settings/bounds power a temporary, automatic
 * "let them through on the normal rate limits instead" window that opens only
 * when THIS SERVER has checked the provider itself and found it dead, and
 * closes again the moment the provider answers.
 *
 * DETAIL — the load-bearing rule this whole family encodes: the ALLOW decision
 * derives EXCLUSIVELY from SERVER-SIDE observations (our own outbound probes +
 * the transport results of real siteverify calls). A client may TRIGGER a probe
 * and increment a telemetry counter; it can NEVER influence a decision — a
 * request-borne "the widget failed" assertion would be a universal bypass the
 * moment a bot copied it. Guard-banned (tests/php/test-captcha-gate.php §8).
 *
 * Two settings rows (zero DDL — rule #20's CSV/JSON-in-a-setting doctrine):
 *   captcha_outage_strict_forms  CSV ⊆ captchaFormKeys(); a form listed here
 *                                 keeps TODAY's fail-closed behaviour under
 *                                 every state. Seeded EMPTY (owner decision
 *                                 D-F1 = A: all ticked forms degrade open).
 *   captcha_health_state         a small JSON blob of machine state (below).
 *
 * The bounds are CODE CONSTANTS, not settings rows — the same "fewer knobs, no
 * way to misconfigure the fail-soft bound" doctrine as CAPTCHA_CURL_*.
 * ---------------------------------------------------------------------- */
const CAPTCHA_SETTING_STRICT_FORMS = 'captcha_outage_strict_forms';
const CAPTCHA_SETTING_HEALTH_STATE = 'captcha_health_state';

/* A probe result is authoritative for this long. A DOWN state older than this
   is STALE and must NOT admit on its own — it forces a re-probe first, which is
   precisely what makes the window SELF-CLOSING (the first healthy probe flips
   the state and the very next request enforces again). */
const CAPTCHA_HEALTH_FRESH_SECONDS = 60;

/* Hard floor between outbound probes, enforced through the existing windowed
   limiter. A flood of token-less junk therefore buys an attacker at most one
   probe per 30 s GLOBALLY (not per request) — less amplification than the
   siteverify calls their token-carrying junk could already trigger. */
const CAPTCHA_HEALTH_PROBE_MIN_INTERVAL = 30;

/* Tighter than the verify band (3/5): a probe can sit on a user-facing request,
   so its worst case must stay well inside a human's patience. */
const CAPTCHA_PROBE_CONNECT_TIMEOUT = 2;
const CAPTCHA_PROBE_TIMEOUT         = 3;

/* The widget script leg follows up to 2 redirects (CDNs re-home bundles); the
   verify POST stays redirect-free (SSRF discipline, unchanged). */
const CAPTCHA_PROBE_MAX_REDIRECTS = 2;

/* Response cap for the script leg — we only need the STATUS, never the bytes. */
const CAPTCHA_PROBE_MAX_SCRIPT_BYTES = 262144;   /* 256 KiB */

/* A deliberately invalid token for the verify leg. All three providers consume
   a token at first siteverify, so this consumes NOTHING real: a parsable body
   carrying a `success` key — even `success:false` — proves the verify service
   is answering, which is the only thing the probe is asking. */
const CAPTCHA_PROBE_SENTINEL_TOKEN = 'ihymns-health-probe';

/* Rate-limiter action names. Declared here so the check and its paired record
   can never drift (rule #35); tests/php/test-rate-limit-pairing.php picks both
   up automatically because it derives the action list from the tree. */
const CAPTCHA_RATE_ACTION_PROBE = 'captcha_probe';
const CAPTCHA_RATE_ACTION_HINT  = 'captcha_widget_health';

/* The probe limiter is a GLOBAL bucket, not a per-IP one: the resource being
   protected is this server's outbound egress, which every client shares. */
const CAPTCHA_PROBE_BUCKET_KEY = 'global';

/* Client hint budget (per IP). Generous — the hint is decision-inert telemetry;
   the cap exists so a flood cannot fill tblLoginAttempts, not to protect a
   decision (there is no decision to protect). */
const CAPTCHA_HINT_MAX_PER_WINDOW = 5;
const CAPTCHA_HINT_WINDOW_SECONDS = 300;

/* The SFTP break-glass (owner decision D-F2 = yes). An EMPTY file with this
   name, dropped beside this one in includes/ — a directory the web server
   denies outright (.htaccess `RewriteRule ^includes/ - [F,L]`) but which every
   deploy already reaches over SFTP — makes captchaConfig() resolve to null, so
   the ENTIRE feature goes dormant through the existing P1 chain: gates, CSP,
   app_status emit and the server-rendered widget all revert to their
   pre-#947 behaviour. It is the ONLY recovery from the admin-lockout trap
   (both `login` AND `manage_login` ticked while the widget cannot load) that
   does not require a DB client. Presence can only ever DOWNGRADE the posture —
   it can never enable, widen or bypass anything else.
   Resolved relative to __DIR__, so rule #41's renamed-docroot trap (alpha
   deploys to public_html_dev/, beta to public_html_beta/) cannot arise. */
const CAPTCHA_KILL_FILE_NAME = 'CAPTCHA_DISABLED';

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
 *   cspConnect   connect-src origins the provider needs. Carried as DATA
 *                rather than assumed away: the original design asserted the
 *                widget's XHR always runs inside the provider's OWN frame (so
 *                our connect-src would be irrelevant) — an UNVERIFIED reading
 *                of provider documentation, and demonstrably wrong for
 *                hCaptcha, whose published CSP guidance names connect-src. A
 *                too-NARROW CSP is a DEAD WIDGET that presents exactly like a
 *                provider outage, so each entry declares its own origins and
 *                index.php appends them conditionally, the same shape as
 *                cspScript/cspFrame. Every value here is an origin the SAME
 *                entry already trusts in cspScript — so listing it widens
 *                nothing an active provider had not already been granted.
 *   secretErrorCodes
 *                the provider's machine-readable `error-codes` values that mean
 *                "the SECRET we sent is missing/wrong" — i.e. an ADMIN
 *                MISCONFIGURATION, not a failed human and not an outage. All
 *                three providers happen to use the same two strings, but they
 *                are carried PER ENTRY because the registry is the one table
 *                (rule #35) and a fourth provider need not agree. An attacker
 *                cannot induce these: the secret in the request is ours, the
 *                URL is a constant from this source, and TLS is verified.
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
            /* The provider's own origin — already trusted for script + frame
               above, so declaring it for connect-src widens nothing new. The
               original design assumed [] here on the reading that Turnstile's
               network traffic all happens inside its own iframe; that reading
               was never verified against a live widget, and the cost of being
               wrong is asymmetric (a blocked XHR = a widget that never mints a
               token = every gated form refusing every real user, which looks
               EXACTLY like the outage this file now works to survive). */
            'cspConnect'  => ['https://challenges.cloudflare.com'],
            'secretErrorCodes' => ['missing-input-secret', 'invalid-input-secret'],
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
            /* hCaptcha's published CSP guidance names connect-src explicitly —
               this is the entry that proves the field had to exist at all. */
            'cspConnect'  => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
            'secretErrorCodes' => ['missing-input-secret', 'invalid-input-secret'],
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
            /* Same reasoning as Turnstile: both origins are already trusted for
               script above, so this is a no-widening declaration that removes a
               dead-widget failure mode rather than an assumption we cannot
               check without live keys. */
            'cspConnect'  => ['https://www.google.com', 'https://www.gstatic.com'],
            'secretErrorCodes' => ['missing-input-secret', 'invalid-input-secret'],
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

/* -------------------------------------------------------------------------
 * PURE OUTAGE CORE — the grace-window maths.
 *
 * Everything below is DB-free and network-free so the whole outage policy is a
 * truth table a test can drive (tests/php/test-captcha-gate.php §7), exactly as
 * captchaGateDecision() above is. The impure half (reading/writing the settings
 * row, running the probe) is a thin shell over these.
 * ---------------------------------------------------------------------- */

/**
 * ELI5: the three words the health state is ever allowed to be.
 *
 * WHY A FUNCTION, NOT AN ENUM COLUMN: this vocabulary lives in a JSON blob
 * inside one tblAppSettings row, and it is expected to GROW (a future
 * 'degraded' or 'rate-limited' observation would be one entry here). Rule #20:
 * a growable vocabulary is VARCHAR + a central app-validated map, never an
 * ENUM — an ENUM value-add is an ALTER, which is the second migration this
 * codebase forbids. Every reader and writer validates against THIS list.
 *
 *   up        the provider answered us — enforce normally
 *   down      the provider did not answer us — the grace window may open
 *   misconfig the provider answered and REJECTED OUR SECRET — an admin
 *             mistake, not an outage; it degrades open too (a wrong secret
 *             refuses every real human forever) but is reported differently
 *             because the remedy is completely different.
 *
 * @return list<string>
 */
function captchaHealthStatuses(): array
{
    return ['up', 'down', 'misconfig'];
}

/**
 * ELI5: the "we have never looked" starting state.
 *
 * WHY status='up' AND checkedAt=0 (and not a fourth 'unknown' status): those
 * two facts answer the two different questions asked of this blob, and answer
 * both FAIL-SAFE. captchaOutageDecision() asks "may we admit?" and reads
 * status — 'up' means enforce, so a cold install NEVER admits (a cold-start
 * bypass would be the whole feature defeated by deleting a settings row).
 * captchaHealthEnsureFresh() asks "is this fresh?" and reads checkedAt — 0 is
 * infinitely stale, so the first refusal probes for real. Surfaces that want to
 * SAY "not yet checked" (the admin card) test checkedAt === 0 rather than
 * needing a status word for it.
 *
 * @return array<string,mixed>
 */
function captchaHealthColdState(): array
{
    return [
        'status'              => 'up',
        'checkedAt'           => 0,
        'downSince'           => null,
        'consecutiveFailures' => 0,
        'lastErrno'           => 0,
        'lastHttpStatus'      => 0,
        'hintCount'           => 0,
        'admitCount'          => 0,
    ];
}

/**
 * ELI5: read the little JSON note we keep about the provider's health, and
 * repair anything odd about it rather than trusting it.
 *
 * WHY TOTAL, NEVER THROWING: this blob is one free-text tblAppSettings value.
 * It can be absent (never written), empty (the migration's seed), hand-edited
 * to nonsense by an admin poking at the settings table, or truncated. EVERY one
 * of those must degrade to the cold state — which enforces — rather than
 * throwing on a login request or, far worse, decoding to something that
 * admits. An unrecognised status word is coerced to 'up' for the same reason.
 *
 * @param string $raw the stored SettingValue
 * @return array<string,mixed> always the full, coerced shape
 */
function captchaHealthNormaliseState(string $raw): array
{
    $cold = captchaHealthColdState();
    $raw  = trim($raw);
    if ($raw === '') {
        return $cold;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $cold;
    }
    $status = (string)($decoded['status'] ?? '');
    if (!in_array($status, captchaHealthStatuses(), true)) {
        $status = 'up';   /* unrecognised → the fail-safe (enforcing) word */
    }
    $downSince = $decoded['downSince'] ?? null;
    return [
        'status'              => $status,
        'checkedAt'           => max(0, (int)($decoded['checkedAt'] ?? 0)),
        'downSince'           => ($downSince === null || $downSince === '') ? null : max(0, (int)$downSince),
        'consecutiveFailures' => max(0, (int)($decoded['consecutiveFailures'] ?? 0)),
        'lastErrno'           => (int)($decoded['lastErrno'] ?? 0),
        'lastHttpStatus'      => (int)($decoded['lastHttpStatus'] ?? 0),
        'hintCount'           => max(0, (int)($decoded['hintCount'] ?? 0)),
        'admitCount'          => max(0, (int)($decoded['admitCount'] ?? 0)),
    ];
}

/**
 * ELI5: THE decision. Given what the server last observed about the provider,
 * should a request that failed the challenge be let through on the ordinary
 * rate limits instead ('admit'), or refused as it is today ('enforce')?
 *
 * DETAIL — the four properties this function exists to make provable:
 *
 *  1. IT TAKES NO REQUEST DATA. Its only inputs are the server's own recorded
 *     observation and the clock. There is deliberately no parameter through
 *     which a header, body flag or client hint could reach it — the universal
 *     bypass of §3.2 is impossible BY SIGNATURE, the same construction trick
 *     captchaGateDecision() uses for account enumeration. Reflection-pinned in
 *     the guard.
 *  2. A STALE 'down' NEVER ADMITS. Freshness is re-checked on every call, so
 *     the window cannot wedge open: once probing stops confirming the outage,
 *     the state ages out within CAPTCHA_HEALTH_FRESH_SECONDS and enforcement
 *     resumes on its own. This is the SELF-CLOSING property; deleting the
 *     freshness test is the single most damaging possible regression here, and
 *     it has its own guard row.
 *  3. A FUTURE checkedAt NEVER ADMITS. `$now - $checkedAt` is negative for a
 *     stamp in the future, which would otherwise read as "extremely fresh"
 *     forever — a clock jump backwards, or a hand-edited row, would pin the
 *     window open permanently. Refused explicitly.
 *  4. ONLY 'down'/'misconfig' ADMIT. 'up' — including the cold state — always
 *     enforces.
 *
 * @param array<string,mixed> $state a captchaHealthNormaliseState() shape
 * @param int                 $now   unix timestamp
 * @return string 'admit' (grace window open) | 'enforce' (today's behaviour)
 */
function captchaOutageDecision(array $state, int $now): string
{
    $status = (string)($state['status'] ?? '');
    if ($status !== 'down' && $status !== 'misconfig') {
        return 'enforce';                 /* healthy, or an unknown/cold state */
    }
    $checkedAt = (int)($state['checkedAt'] ?? 0);
    if ($checkedAt <= 0) {
        return 'enforce';                 /* never actually observed */
    }
    if ($now < $checkedAt) {
        return 'enforce';                 /* stamp in the future — never trust it */
    }
    if (($now - $checkedAt) > CAPTCHA_HEALTH_FRESH_SECONDS) {
        return 'enforce';                 /* STALE down — re-probe before admitting */
    }
    return 'admit';
}

/**
 * ELI5: work out what the health note should say after a fresh observation —
 * or that it should not be rewritten at all.
 *
 * WHY PURE + NULLABLE: separating "what should the state become" from "write
 * it" makes the interesting half testable, and the null return encodes the one
 * optimisation worth having: while everything is healthy, a real siteverify
 * happens on every gated form submission, and re-stamping the settings row on
 * each of them would be a needless write per successful sign-in. So an
 * up→up observation inside CAPTCHA_HEALTH_PROBE_MIN_INTERVAL is skipped.
 *
 * ⚠️ THE SKIP IS SAFE BY CONSTRUCTION, and it must stay that way: it applies
 * ONLY when the status is unchanged AND already 'up'. A down→up observation —
 * the one that CLOSES the grace window — can never be skipped, because its
 * status differs. Widening this condition to "status unchanged" alone would
 * stop a sustained outage from refreshing checkedAt, the state would go stale,
 * and the window would flap shut mid-outage.
 *
 * @param array<string,mixed> $prev       the current normalised state
 * @param string              $status     the observation ('up'|'down'|'misconfig')
 * @param int                 $errno      curl errno of the observation (0 = none)
 * @param int                 $httpStatus HTTP status of the observation (0 = none)
 * @param int                 $now        unix timestamp
 * @return array<string,mixed>|null the state to persist, or null = leave it alone
 */
function captchaHealthNextState(array $prev, string $status, int $errno, int $httpStatus, int $now): ?array
{
    if (!in_array($status, captchaHealthStatuses(), true)) {
        return null;                       /* not a word we recognise — no observation */
    }
    $prevStatus = (string)($prev['status'] ?? 'up');
    $changed    = ($prevStatus !== $status);
    $checkedAt  = (int)($prev['checkedAt'] ?? 0);

    if (!$changed && $status === 'up' && $checkedAt > 0
        && ($now - $checkedAt) < CAPTCHA_HEALTH_PROBE_MIN_INTERVAL && $now >= $checkedAt) {
        return null;                       /* healthy and recently stamped — nothing to say */
    }

    return [
        'status'    => $status,
        'checkedAt' => $now,
        /* When did the CURRENT unhealthy spell start? Cleared on recovery; set
           on the transition into it; carried unchanged while it continues (so
           the admin card can say "since 09:14", not "since 3 seconds ago"). */
        'downSince' => $status === 'up'
            ? null
            : ($changed ? $now : ((int)($prev['downSince'] ?? 0) ?: $now)),
        'consecutiveFailures' => $status === 'up' ? 0 : ((int)($prev['consecutiveFailures'] ?? 0) + 1),
        'lastErrno'           => $errno,
        'lastHttpStatus'      => $httpStatus,
        /* Both counters are "since the last state change", so a transition
           zeroes them — the outgoing values are reported in the transition's
           own activity-log row before they go. */
        'hintCount'  => $changed ? 0 : (int)($prev['hintCount'] ?? 0),
        'admitCount' => $changed ? 0 : (int)($prev['admitCount'] ?? 0),
    ];
}

/**
 * ELI5: did the provider tell us OUR SECRET KEY is wrong?
 *
 * WHY THIS IS NOT AN OUTAGE, AND WHY IT MATTERS: a mis-pasted secret produces a
 * perfectly healthy `200 {"success":false,"error-codes":["invalid-input-secret"]}`
 * on every single verify. Treated as an ordinary failed challenge, that refuses
 * every legitimate user forever while everything looks fine — no transport
 * error, no timeout, nothing in the log but a rising 403 count. So it gets its
 * own state word, its own admin banner naming the actual remedy, and (like an
 * outage) it degrades open rather than bricking the site on a typo.
 *
 * WHY IT CANNOT BE SPOOFED: these codes are a function of the secret WE sent,
 * to a URL that is a constant in this file, over verified TLS. An attacker
 * controls the `response` field only, and every response-side error code
 * (invalid-input-response, timeout-or-duplicate, …) is deliberately NOT in the
 * per-provider list — those stay fail-closed exactly as today.
 *
 * @param array<string,mixed> $decoded          the parsed siteverify body
 * @param list<string>        $secretErrorCodes the registry entry's list
 */
function captchaSecretErrorCodeHit(array $decoded, array $secretErrorCodes): bool
{
    if ($secretErrorCodes === []) {
        return false;
    }
    $codes = $decoded['error-codes'] ?? null;
    if (!is_array($codes)) {
        return false;
    }
    foreach ($codes as $c) {
        if (is_string($c) && in_array($c, $secretErrorCodes, true)) {
            return true;
        }
    }
    return false;
}

/**
 * ELI5: which extra web addresses the browser must be allowed to talk to for
 * THIS provider's widget to work — worked out from the config alone.
 *
 * WHY A PURE SIBLING OF captchaCspOrigins(): the dormancy claim "an
 * unconfigured install's CSP header is byte-identical" is the single most
 * load-bearing promise this feature makes, and with the DB-reading version it
 * could only be asserted structurally. Split out, it is a two-line truth table
 * (null config → three empty lists) that a test actually executes.
 *
 * @param array<string,mixed>|null $config a captchaResolveConfig() result
 * @return array{script:list<string>,frame:list<string>,connect:list<string>}
 */
function captchaCspOriginsFor(?array $config): array
{
    if ($config === null) {
        return ['script' => [], 'frame' => [], 'connect' => []];
    }
    return [
        'script'  => array_values((array)($config['cspScript'] ?? [])),
        'frame'   => array_values((array)($config['cspFrame'] ?? [])),
        'connect' => array_values((array)($config['cspConnect'] ?? [])),
    ];
}

/**
 * ELI5: is the emergency "switch CAPTCHA off" file sitting in this folder?
 *
 * DETAIL: the break-glass of last resort (owner decision D-F2). See
 * CAPTCHA_KILL_FILE_NAME above for the full custody argument. The directory is
 * a PARAMETER purely so this can be truth-tabled against temp directories in
 * CI without ever creating a file in a real docroot — production callers pass
 * nothing and get __DIR__, which is includes/ on whichever renamed docroot
 * (public_html / _dev / _beta) is actually deployed (rule #41).
 *
 * @param string|null $dir directory to look in; defaults to this file's own
 */
function captchaKillFilePresent(?string $dir = null): bool
{
    $dir = ($dir === null || $dir === '') ? __DIR__ : $dir;
    return is_file(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . CAPTCHA_KILL_FILE_NAME);
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
    /* BREAK-GLASS, CHECKED FIRST — before the settings reads, deliberately.
       The failure this rescues is precisely "the DB-backed configuration is the
       thing that is wrong" (a mis-pasted secret with both sign-in doors gated),
       so a switch that had to read that configuration to decide would be no
       switch at all. Resolving null here makes the WHOLE feature dormant
       through the existing P1 chain — gates, CSP, app_status emit, widget
       markup — with no other code aware the file exists.
       Cost: one is_file() stat on the first captchaConfig() of a request. The
       result is memoized in $cached alongside everything else, so it is one
       stat per request, not one per gate. */
    if (captchaKillFilePresent()) {
        return $cached = null;
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
 * WHY: index.php appends these to script-src / frame-src / connect-src ONLY
 * when configured, so an unconfigured install's CSP is byte-identical. Origins
 * live in the registry alone — index.php never names a hostname (guard-banned).
 *
 * THREE lists, not two: see the registry's `cspConnect` doc-block for why the
 * connect-src third was added as a mechanism rather than assumed unnecessary.
 * index.php must consume all three — a list produced and never appended is a
 * silent dead widget, so the guard asserts each one reaches its directive.
 *
 * The maths is the pure captchaCspOriginsFor(); this is the DB-reading shell.
 *
 * @return array{script:list<string>,frame:list<string>,connect:list<string>}
 */
function captchaCspOrigins(): array
{
    return captchaCspOriginsFor(captchaConfig());
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
 * PROVIDER HEALTH — the impure shell over the pure outage core above.
 *
 * ELI5: this is the part that actually goes and LOOKS at the provider, and
 * remembers what it saw in one little settings row. Everything that decides
 * anything from what it saw lives in the pure core above.
 *
 * THE ONE RULE, restated where the network code is: an ALLOW is only ever
 * derived from something THIS SERVER observed — an outbound probe we made, or
 * the transport result of a real siteverify we sent. Nothing a client says
 * reaches captchaOutageDecision(); the client hint endpoint can make the server
 * LOOK sooner, and can never make it BELIEVE.
 *
 * NOTHING HERE RUNS ON A DORMANT INSTALL, OR ON AN UNGATED FORM: every entry
 * point below is reached only from inside captchaGate()'s post-short-circuit
 * region, from the dormant-gated hint endpoint, or from the admin card's
 * explicit button. And nothing here runs on an ALLOWED request either — health
 * state is consulted only once a token has ALREADY failed.
 * ========================================================================= */

/**
 * ELI5: the forms an admin has decided must keep failing closed even during a
 * confirmed provider outage.
 *
 * WHY IT EXISTS AT ALL, given the owner chose "everything degrades open"
 * (decision D-F1 = A): the stakes genuinely differ per form — a locked-out
 * congregation on Sunday morning is the failure that matters for sign-in,
 * whereas registration is the spam magnet. Shipping the opt-out EMPTY but
 * PRESENT means changing that judgement later is ticking a box, not a schema
 * change and a deploy (rule #20 — CSV, app-validated, no ENUM, no DDL).
 *
 * Validated through the SAME captchaParseForms() the enabled list uses, so an
 * unknown key is dropped rather than fatal, and the strict list can never name
 * a form that does not exist.
 *
 * NOTE the direction of failure: a typo here means a form silently is NOT
 * strict (it degrades open with the rest) — which is the same posture the
 * owner chose as the default, so a typo costs nothing surprising.
 *
 * @return list<string>
 */
function captchaOutageStrictForms(): array
{
    return captchaParseForms((string)(getAppSetting(CAPTCHA_SETTING_STRICT_FORMS, '') ?? ''));
}

/**
 * ELI5: read (or replace) the little note about how the provider is doing.
 *
 * WHY ITS OWN MEMO RATHER THAN LEANING ON getAppSetting()'s: getAppSetting()
 * caches per request and is NOT invalidated by setAppSetting(), so after a
 * write in this same request it would keep handing back the pre-write value —
 * and this state is written and re-read inside a single request (record an
 * observation, then decide from it). The optional $replace parameter is how the
 * writer keeps this memo honest; it is the same accessor shape
 * activityLogWriteCount() uses.
 *
 * Always returns a full, coerced shape (never null, never throws) — see
 * captchaHealthNormaliseState().
 *
 * @param array<string,mixed>|null $replace internal: the writer's memo update
 * @return array<string,mixed>
 */
function captchaHealthState(?array $replace = null): array
{
    static $memo = null;
    if ($replace !== null) {
        return $memo = $replace;
    }
    if ($memo !== null) {
        return $memo;
    }
    return $memo = captchaHealthNormaliseState((string)(getAppSetting(CAPTCHA_SETTING_HEALTH_STATE, '') ?? ''));
}

/**
 * ELI5: save the health note.
 *
 * WHY IT SWALLOWS EVERYTHING: this is called from the middle of a sign-in. A
 * database hiccup while recording telemetry must never turn a working login
 * into a 500. The in-request memo is updated FIRST, so even a failed write
 * leaves this request internally consistent (it decides from what it just
 * observed); the next request simply re-observes.
 *
 * No DDL anywhere: setAppSetting() is an INSERT … ON DUPLICATE KEY UPDATE, so
 * the row does not need to pre-exist for this to work — the migration that
 * seeds it (migrate-captcha-outage-settings.php) is for schema.sql/description
 * hygiene, not correctness.
 *
 * @param array<string,mixed> $state
 */
function captchaHealthWrite(array $state): void
{
    captchaHealthState($state);   /* memo first — correct even if the write fails */
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
        $db = getDbMysqli();
        setAppSetting($db, CAPTCHA_SETTING_HEALTH_STATE, (string)json_encode(
            $state,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    } catch (\Throwable $e) {
        error_log('[iHymns] CAPTCHA health state write failed: ' . $e->getMessage());
    }
}

/**
 * ELI5: "I just saw the provider do X" — write that down, and if it is a CHANGE
 * of story (working → broken, or broken → working), record one audit row.
 *
 * THE ONE STATE WRITER for status/checkedAt. Every observation — active probe
 * or passive real-verify result — funnels through here, so there is exactly one
 * place that can decide the provider's health, and the counters
 * (captchaHealthBumpCounter) deliberately cannot touch either field.
 *
 * AUDIT ROWS ARE TRANSITIONS ONLY, NEVER PER REQUEST. A busy outage would
 * otherwise write one tblActivityLog row per refused request — turning an
 * outage into a self-inflicted database flood on top of it. The per-request
 * volume lives in the state blob's counters instead, and the counts are
 * reported in the row written when the spell ENDS (which is the row an admin
 * actually wants: "we were down for 6 minutes and admitted 41 requests").
 *
 * logActivity() is function_exists-guarded rather than required: this file is
 * deliberately side-effect-free to include, and activity logging needs a DB
 * connection this path may not have.
 *
 * @param string $status     one of captchaHealthStatuses()
 * @param int    $errno      curl errno behind the observation (0 = none)
 * @param int    $httpStatus HTTP status behind the observation (0 = none)
 */
function captchaHealthRecordObservation(string $status, int $errno = 0, int $httpStatus = 0): void
{
    $prev = captchaHealthState();
    $next = captchaHealthNextState($prev, $status, $errno, $httpStatus, time());
    if ($next === null) {
        return;   /* unrecognised status, or a healthy no-news re-stamp */
    }
    captchaHealthWrite($next);

    if ((string)($prev['status'] ?? '') !== (string)$next['status'] && function_exists('logActivity')) {
        logActivity('captcha.health', 'app_setting', CAPTCHA_SETTING_HEALTH_STATE, [
            'from'              => (string)($prev['status'] ?? ''),
            'to'                => (string)$next['status'],
            'errno'             => $errno,
            'httpStatus'        => $httpStatus,
            /* The OUTGOING counters — what happened during the spell that has
               just ended (or, on the way in, during the healthy stretch). */
            'admittedSincePrev' => (int)($prev['admitCount'] ?? 0),
            'hintsSincePrev'    => (int)($prev['hintCount'] ?? 0),
        ], 'success');
    }
}

/**
 * ELI5: add one to a tally in the health note, leaving the health verdict
 * itself completely untouched.
 *
 * ⚠️ LOAD-BEARING: this function must NEVER write `status` or `checkedAt`.
 * Re-stamping checkedAt from a counter bump would make a stale DOWN look
 * permanently fresh — the grace window would stop self-closing and stay open
 * for as long as traffic kept arriving, which is the exact wedge the whole
 * freshness design exists to prevent. It carries the previous state through
 * verbatim and changes one integer. Guard-asserted (test-captcha-gate.php §8).
 *
 * @param string $counter 'hintCount' | 'admitCount'
 */
function captchaHealthBumpCounter(string $counter): void
{
    if ($counter !== 'hintCount' && $counter !== 'admitCount') {
        return;
    }
    $state = captchaHealthState();
    $state[$counter] = (int)($state[$counter] ?? 0) + 1;
    captchaHealthWrite($state);
}

/** ELI5: tally one request that the grace window let through. */
function captchaHealthNoteAdmit(): void
{
    captchaHealthBumpCounter('admitCount');
}

/** ELI5: tally one browser reporting that the widget would not load. */
function captchaHealthNoteHint(): void
{
    captchaHealthBumpCounter('hintCount');
}

/**
 * ELI5: make one bounded outbound request and report only WHETHER it worked.
 *
 * WHY A PRIVATE HELPER: the two probe legs differ only in method, redirect
 * policy and size cap, and duplicating the hardened curl setup would be exactly
 * the second copy rule #22 forbids — the divergence that matters here would be
 * a forgotten CURLOPT_SSL_VERIFYPEER, which is silent.
 *
 * Same hardening as captchaVerifyToken(): HTTPS-only, peer + host verified,
 * response read through an aborting write-callback so an oversized body is
 * never fully buffered. No SSRF surface exists at all — both URLs are constants
 * from this file's registry, never anything a caller supplies.
 *
 * @param string      $url          a registry-constant https URL
 * @param string|null $postFields   urlencoded body, or null for a GET
 * @param int         $maxRedirects 0 = never follow
 * @param int         $cap          response byte cap
 * @return array{ok:bool,errno:int,httpStatus:int,body:string}
 *         ok = the transport completed with a 2xx
 */
function _captchaProbeHttp(string $url, ?string $postFields, int $maxRedirects, int $cap): array
{
    $fail = ['ok' => false, 'errno' => 0, 'httpStatus' => 0, 'body' => ''];
    $ch = curl_init();
    if ($ch === false) {
        return $fail;
    }
    $buf = '';
    $writeFn = static function ($handle, string $chunk) use (&$buf, $cap): int {
        $buf .= $chunk;
        if (strlen($buf) > $cap) {
            return -1;   /* abort — never buffer an oversized body */
        }
        return strlen($chunk);
    };
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => ['Accept: */*'],
        CURLOPT_USERAGENT      => 'iHymns-CAPTCHA-probe/1.0',
        CURLOPT_CONNECTTIMEOUT => CAPTCHA_PROBE_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => CAPTCHA_PROBE_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => $maxRedirects > 0,
        CURLOPT_MAXREDIRS      => $maxRedirects,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,   /* a redirect may not downgrade to http */
        CURLOPT_WRITEFUNCTION  => $writeFn,
    ];
    if ($postFields !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $postFields;
        $opts[CURLOPT_HTTPHEADER] = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $errno      = curl_errno($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'         => ($errno === 0 && $httpStatus >= 200 && $httpStatus < 300),
        'errno'      => $errno,
        'httpStatus' => $httpStatus,
        'body'       => $buf,
    ];
}

/**
 * ELI5: go and check on the provider ourselves — can we fetch the widget
 * script a browser would need, and does the answer-checking service reply?
 *
 * DETAIL — TWO LEGS, because there are two independent ways this breaks and
 * only one of them is visible from ordinary traffic:
 *
 *   1. THE WIDGET SCRIPT (GET). This is the leg that matters most and the one
 *      no amount of real traffic would ever reveal: when the widget URL is
 *      unreachable, browsers mint no token, so the server receives only
 *      token-less requests — which are refused with NO network call at all.
 *      During a widget-only outage the server would otherwise never observe
 *      anything. Up to CAPTCHA_PROBE_MAX_REDIRECTS hops are followed here
 *      (CDNs re-home bundles); the verify leg stays redirect-free.
 *   2. THE VERIFY SERVICE (POST). Sends CAPTCHA_PROBE_SENTINEL_TOKEN — a
 *      deliberately invalid answer, so nothing real is consumed. A parsable
 *      body carrying a `success` key proves the service is answering, and
 *      `success:false` is a PASS for this purpose: we are asking "are you
 *      alive?", not "is this token good?".
 *
 * EITHER LEG FAILING ⇒ 'down'. A widget-only partial outage is precisely the
 * scenario this feature was asked for, so requiring both to fail would miss it.
 *
 * The verify leg doubles as the misconfiguration detector — see
 * captchaSecretErrorCodeHit(). 'misconfig' outranks 'down' because it is
 * actionable and 'down' is not.
 *
 * HONEST RESIDUAL, stated in the admin card too: this answers "reachable FROM
 * THIS SERVER". A geo-partial outage that blocks clients while our host is fine
 * keeps the window CLOSED; only the client hint counter makes that visible.
 *
 * @param array<string,mixed> $config a resolved captchaConfig() shape
 * @return array{status:string,errno:int,httpStatus:int}|null
 *         null = could not observe at all (no curl / unusable registry URLs);
 *         the caller records NOTHING rather than inventing a verdict.
 */
function captchaProbeProvider(array $config): ?array
{
    if (!function_exists('curl_init')) {
        return null;   /* no way to look — an absence of evidence, not evidence */
    }
    $scriptUrl = (string)($config['script'] ?? '');
    $verifyUrl = (string)($config['verify'] ?? '');
    if (!str_starts_with($scriptUrl, 'https://') || !str_starts_with($verifyUrl, 'https://')) {
        return null;   /* registry entry unusable — not the provider's fault */
    }

    /* Leg 1 — the widget script a browser would fetch. */
    $leg1 = _captchaProbeHttp($scriptUrl, null, CAPTCHA_PROBE_MAX_REDIRECTS, CAPTCHA_PROBE_MAX_SCRIPT_BYTES);

    /* Leg 2 — the siteverify service, with a token that cannot be valid. */
    $leg2 = _captchaProbeHttp(
        $verifyUrl,
        http_build_query([
            'secret'   => (string)($config['secret_key'] ?? ''),
            'response' => CAPTCHA_PROBE_SENTINEL_TOKEN,
        ]),
        0,
        CAPTCHA_MAX_RESPONSE_BYTES
    );

    $verifyAnswering = false;
    if ($leg2['ok']) {
        $decoded = json_decode($leg2['body'], true);
        if (is_array($decoded) && array_key_exists('success', $decoded)) {
            $verifyAnswering = true;
            if ($decoded['success'] === false
                && captchaSecretErrorCodeHit($decoded, (array)($config['secretErrorCodes'] ?? []))) {
                /* The service is perfectly healthy and is telling us our own
                   secret is wrong. Actionable, and outranks everything else. */
                return ['status' => 'misconfig', 'errno' => 0, 'httpStatus' => $leg2['httpStatus']];
            }
        }
    }

    if ($leg1['ok'] && $verifyAnswering) {
        return ['status' => 'up', 'errno' => 0, 'httpStatus' => $leg1['httpStatus']];
    }
    /* Report the FAILING leg's diagnostics — the script leg first, since a dead
       widget is what a user actually experienced. */
    $blame = !$leg1['ok'] ? $leg1 : $leg2;
    return ['status' => 'down', 'errno' => (int)$blame['errno'], 'httpStatus' => (int)$blame['httpStatus']];
}

/**
 * ELI5: if what we know about the provider is older than a minute, go and look
 * again — but never more often than once every 30 seconds across the whole
 * site, however many requests arrive.
 *
 * WHERE IT IS CALLED FROM, AND WHY THAT IS THE WHOLE COST STORY: only from the
 * REFUSAL path of captchaGate() and from the dormant-gated hint endpoint. A
 * request that passes its challenge never reaches this; a dormant install never
 * reaches this; an ungated form never reaches this. So when everything is
 * healthy, the hot-path cost of this entire feature is ZERO outbound calls.
 *
 * The floor is the existing windowed limiter on a GLOBAL bucket, so the worst
 * an attacker gets from flooding token-less junk is one probe per 30 s for the
 * whole server — bounded further by the 2 s / 3 s timeouts. Single-flight is
 * approximate (check→record is not atomic, so two concurrent stale reads can
 * both probe once); that costs at most one duplicate outbound call per window,
 * which is not worth a lock table. The intapps conditional-UPDATE lock is the
 * documented upgrade path if it ever matters.
 *
 * The limiter itself fails OPEN on a DB error, so a simultaneous DB + provider
 * incident degrades to at most one probe per refused request — still bounded by
 * the curl timeouts, and by then the endpoints are failing upstream anyway.
 *
 * @param array<string,mixed> $config a resolved captchaConfig() shape
 */
function captchaHealthEnsureFresh(array $config): void
{
    $state     = captchaHealthState();
    $checkedAt = (int)($state['checkedAt'] ?? 0);
    $now       = time();
    if ($checkedAt > 0 && $now >= $checkedAt && ($now - $checkedAt) <= CAPTCHA_HEALTH_FRESH_SECONDS) {
        return;   /* what we know is still authoritative */
    }

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'rate_limit.php';
    /* The READ half of the two-call contract; the paired WRITE is immediately
       below (tests/php/test-rate-limit-pairing.php derives both from the tree). */
    if (!checkRateLimit(CAPTCHA_RATE_ACTION_PROBE, CAPTCHA_PROBE_BUCKET_KEY, 1, CAPTCHA_HEALTH_PROBE_MIN_INTERVAL)) {
        return;   /* probed too recently — the stale state stands, and a stale
                     'down' does NOT admit, so this is fail-safe */
    }
    recordRateLimitHit(CAPTCHA_RATE_ACTION_PROBE, CAPTCHA_PROBE_BUCKET_KEY);

    $probe = captchaProbeProvider($config);
    if ($probe === null) {
        return;   /* could not observe — record nothing */
    }
    captchaHealthRecordObservation((string)$probe['status'], (int)$probe['errno'], (int)$probe['httpStatus']);
}

/**
 * ELI5: the admin card's "Check now" button — look at the provider this very
 * second, ignoring the every-30-seconds rule, and always say what happened.
 *
 * WHY IT BYPASSES THE INTERVAL LIMITER: the intappsForceRefresh() honesty rule
 * (includes/intapps_client.php). A refresh button that quietly does nothing
 * because a backoff clause matched is at its most useless on the one page an
 * operator opened to find out what is wrong. This always performs a real probe
 * and always returns either a verdict or an explicit null the card renders as
 * "could not check" — never a silent no-op.
 *
 * No lock is taken: two admins clicking at once cost one extra outbound GET +
 * POST, and the button is admin-only and CSRF-gated, so there is nothing here
 * worth a lock table.
 *
 * @param array<string,mixed> $config a resolved captchaConfig() shape
 * @return array{status:string,errno:int,httpStatus:int}|null null = could not observe
 */
function captchaForceProbe(array $config): ?array
{
    $probe = captchaProbeProvider($config);
    if ($probe === null) {
        return null;
    }
    captchaHealthRecordObservation((string)$probe['status'], (int)$probe['errno'], (int)$probe['httpStatus']);
    return $probe;
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
        /* PASSIVE FEEDER. This IS a probe of the verify leg, already paid for,
           so real traffic keeps the health state accurate at zero extra
           outbound cost — and it is server-side evidence by construction (we
           made this call; the client only supplied a token string). */
        captchaHealthRecordObservation('down', $errno, $httpStatus);
        return true;
    }
    $decoded = json_decode($buf, true);
    if (!is_array($decoded) || !array_key_exists('success', $decoded)) {
        error_log('[iHymns] CAPTCHA verify returned unparsable body — failing open');
        captchaHealthRecordObservation('down', 0, $httpStatus);
        return true;
    }

    /* MISCONFIGURATION, NOT A FAILED HUMAN. A `success:false` carrying a
       SECRET-side error code means the secret WE hold is wrong — every real
       user would be refused forever, with nothing visible but a rising 403
       count. It fails OPEN (a typo must not brick the site) and is recorded
       under its own status so the admin surface can name the actual remedy.
       Response-side codes are deliberately absent from the registry list, so an
       ordinary wrong/expired/replayed token stays fail-CLOSED exactly as
       today — see captchaSecretErrorCodeHit(). */
    if ($decoded['success'] === false
        && captchaSecretErrorCodeHit($decoded, (array)($config['secretErrorCodes'] ?? []))) {
        error_log('[iHymns] CAPTCHA provider rejected our SECRET KEY (check /manage/configuration) — failing open');
        captchaHealthRecordObservation('misconfig', 0, $httpStatus);
        return true;
    }

    /* The service gave a definitive answer either way — it is demonstrably
       alive, which is the only thing the health state tracks. */
    captchaHealthRecordObservation('up', 0, $httpStatus);
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
 * THE GRACE WINDOW (the outage fallback) sits between the verify and the
 * verdict, and ONLY on the refusal path:
 *
 *   1. dormant / ungated        → allowed, no I/O at all              (unchanged)
 *   2. token verified           → allowed                              (unchanged)
 *   3. token failed, form STRICT→ refuse                               (unchanged)
 *   4. token failed, non-strict → make sure our knowledge of the provider is
 *                                 fresh (a rate-limited probe at most), then
 *                                 admit IFF the SERVER has itself confirmed
 *                                 the provider is down/misconfigured right now
 *   5. otherwise                → refuse                               (unchanged)
 *
 * WHAT "ADMIT" ACTUALLY MEANS: nothing is switched off. The request proceeds
 * into the SAME defence stack that guarded these forms before CAPTCHA existed —
 * per-IP, per-account and per-identifier budgets, the honeypot, the daily caps.
 * The window is not "no protection"; it is "the pre-CAPTCHA protection floor,
 * temporarily, loudly, and self-closing".
 *
 * WHY IT COSTS ~NOTHING IN SECURITY: during a real outage the shipped
 * fail-open already lets through any request carrying ANY non-empty garbage
 * token (captchaVerifyToken()'s transport branch above). So the population that
 * fail-closed still punishes during an outage is exactly the LEGITIMATE users
 * whose widget never loaded and who therefore have no token to send. The window
 * gives them back the door a bot never lost.
 *
 * WHY IT CANNOT BE FORCED OPEN: the decision reads only server-side
 * observations. To open the window an attacker must make the provider
 * unreachable FROM THIS SERVER — precisely the capability the shipped
 * verify-time fail-open already stands on. No new attacker capability is
 * minted; the same door is widened during the same event.
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

    if (!$ok && !in_array($form, captchaOutageStrictForms(), true)) {
        /* Only now — after a failure, on a non-strict form — is health state
           consulted at all. captchaHealthEnsureFresh() re-probes only when what
           we know has aged out, and at most once per 30 s globally. */
        captchaHealthEnsureFresh($config);
        if (captchaOutageDecision(captchaHealthState(), time()) === 'admit') {
            captchaHealthNoteAdmit();   /* a counter, never an audit row per request */
            return null;                /* grace window: the pre-CAPTCHA floor applies */
        }
    }

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
