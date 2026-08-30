<?php

declare(strict_types=1);

/**
 * iHymns — "Connect a service" guided-wizard registry (#2003, epic #2002)
 * =========================================================================
 *
 * ELI5
 * ----
 * `/manage/configuration` already has cards for three outside services
 * (IntAppsAPI, CueRCode, CAPTCHA) — each with its own form, its own "is
 * this set up?" badge, and (for two of them) its own "does it actually
 * work?" button. This file is ONE list that describes all three the same
 * way — what the card is called, what an admin needs before they start,
 * where to paste it, and how to test it — so a single guided wizard can
 * walk an admin through any of them without three copies of the same
 * stepper logic. Nothing in this file talks to the network; it only
 * describes things and reads already-saved settings.
 *
 * DETAIL
 * ------
 * This is the SERVER half of the registry-driven wizard design (plan
 * `.claude/plan-integration-connect-wizard.md` §5). The CLIENT half is the
 * ONE generic driver module `js/modules/integration-connect-wizard.js`,
 * which never hardcodes a per-integration field list — it reads the JSON
 * projection this file builds (`integrationClientProjection()`) and
 * renders whatever the registry describes (rule #1 — one engine, not
 * three). `manage/configuration.php` is the ONLY consumer: it requires
 * this file, emits the projection as a `<script type="application/json">`
 * island (mirroring its own existing `data-smtp-presets` island), and adds
 * the `integration_test` JSON POST branch that calls
 * `integrationTestDispatch()` below.
 *
 * REQUIRE-SAFE BY DESIGN (mirrors `intapps_client.php` / `cuercode_client.php`):
 * loading this file opens no DB connection and makes no HTTP call — it only
 * declares constants/functions and pulls in the three client files for
 * their setting-key CONSTANTS + `captchaProviders()` (rule #35 — one source
 * of truth for every literal key/provider name; this file never re-types
 * one). The standing guard (`tests/php/test-integration-connect-wizard.php`)
 * includes this file directly, DB-free, and calls `integrationRegistry()`
 * and `integrationClientProjection()` with a stub reader to prove that.
 *
 * NO NEW STORAGE, NO NEW WRITE PATH (rule #22): every setting key named
 * below already exists in `tblAppSettings` via the three existing card save
 * actions (`save_intappsapi` / `save_cuercode` / `save_captcha`,
 * `manage/configuration.php`). This file adds no column, no table, no new
 * secret — `secretSettingKeys()` (`includes/secret_crypto.php`) already
 * lists all four secrets this registry touches
 * (`intappsapi_api_key`/`intappsapi_hmac_secret`/`cuercode_api_key`/
 * `captcha_secret_key`), and the guard's check (c) asserts that
 * subset-relation mechanically rather than trusting this comment.
 *
 * SECRET-SAFETY BY CONSTRUCTION: `integrationClientProjection()` takes an
 * INJECTED reader callable rather than calling `getAppSetting()` itself.
 * For a `secret:true` field the reader's return value is used ONLY inside a
 * `!== ''` boolean comparison — the raw string is never assigned into the
 * output array — so a secret VALUE cannot reach the emitted JSON no matter
 * what the reader returns (the guard's check (c) proves this by handing the
 * reader a sentinel string for every key and asserting the sentinel is
 * absent from the encoded output for every secret field, while still
 * present for non-secret fields).
 *
 * @see .claude/plan-integration-connect-wizard.md   the full design (§5–§10)
 * @see appWeb/public_html/manage/configuration.php   the one consumer
 * @see appWeb/public_html/js/modules/integration-connect-wizard.js  the client driver
 * @see appWeb/public_html/includes/intapps_client.php   IntAppsAPI setting keys + intappsRequest()
 * @see appWeb/public_html/includes/cuercode_client.php  CueRCode setting keys + cuercodeProbe()
 * @see appWeb/public_html/includes/captcha.php          CAPTCHA registry + captchaForceProbe()
 * @see tests/php/test-integration-connect-wizard.php    the standing guard
 * @link https://www.php.net/manual/en/language.types.callable.php  PHP callables (the injected reader)
 * #2003 (epic #2002)
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'intapps_client.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cuercode_client.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'captcha.php';
/* #2004 (epic #2002) — the extend-phase's three sibling requires, pulling in
   the setting-key CONSTANTS + pure helpers `email`/`siwa`/`webhooks` need
   below (rule #35 — never a re-typed literal). All three are documented
   require-safe (constants/functions only, no DB/network at LOAD time — see
   each file's own top-of-file doc-block), so this file's own "require-safe
   by design" guarantee (this doc-block, above) is unchanged. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'email_options.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'apple_siwa.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'webhooks.php';

/**
 * The CAPTCHA provider → "where do I sign up / get keys" portal map.
 *
 * ELI5: for each bot-protection provider, this is the web address of that
 * provider's OWN dashboard — the wizard links there on the "what you'll
 * need" step so an admin doesn't have to go search for it.
 *
 * WHY A SEPARATE CONST, NOT A NEW FIELD ON `captchaProviders()`: the
 * provider registry in `includes/captcha.php` (rule #35's one source of
 * truth for provider vocabulary) describes what iHymns needs to KNOW to
 * verify a token — script URL, siteverify endpoint, CSP origins. Where a
 * HUMAN goes to create an account is a wizard-only concern with no runtime
 * use, so it lives here instead of growing that file's registry with a
 * key nothing else reads. The guard (`test-integration-connect-wizard.php`
 * check (i)) asserts these keys equal the SELECTABLE `captchaProviders()`
 * keys in both directions — a newly selectable provider with no portal
 * entry here fails loudly rather than shipping a wizard step with a dead
 * "create an account" link.
 *
 * @see appWeb/public_html/includes/captcha.php::captchaProviders()
 */
const IHYMNS_INTEGRATION_CAPTCHA_PORTALS = [
    'turnstile'    => [
        'portalUrl'   => 'https://dash.cloudflare.com/?to=/:account/turnstile',
        'portalLabel' => 'Cloudflare dashboard → Turnstile',
    ],
    'hcaptcha'     => [
        'portalUrl'   => 'https://dashboard.hcaptcha.com/',
        'portalLabel' => 'hCaptcha dashboard',
    ],
    'recaptcha_v2' => [
        'portalUrl'   => 'https://www.google.com/recaptcha/admin/create',
        'portalLabel' => 'Google reCAPTCHA admin console',
    ],
];

/**
 * The email provider → "where do I sign up / get keys" portal map (#2004,
 * epic #2002) — the SAME shape and SAME reasoning as
 * `IHYMNS_INTEGRATION_CAPTCHA_PORTALS` immediately above (see that block's
 * own "WHY A SEPARATE CONST" note, which applies unchanged here): where a
 * human goes to create an account has no runtime use anywhere else in the
 * app, so it lives here rather than growing `includes/email_options.php`'s
 * settings model with a key nothing else reads.
 *
 * `'smtp' => [null, null]` is deliberate, not an oversight — a generic
 * "custom SMTP server" has no ONE portal to link to (it's the admin's own
 * mail server); `renderNeedPane()` (`js/modules/integration-connect-wizard.js`)
 * already degrades a null `portalUrl` to "no portal link" (the SAME fallback
 * path CAPTCHA's own null `portal` exercises today).
 *
 * Guard check (j) (`tests/php/test-integration-connect-wizard.php`) asserts
 * these keys equal `ihymnsEmailServiceOptions()`'s keys MINUS `'none'` in
 * BOTH directions (the (i) mirror) — a newly added email provider with no
 * portal entry here fails loudly rather than shipping a dead "create an
 * account" link.
 *
 * @see appWeb/public_html/includes/email_options.php::ihymnsEmailServiceOptions()
 */
const IHYMNS_INTEGRATION_EMAIL_PORTALS = [
    'office365' => [
        'portalUrl'   => 'https://admin.microsoft.com/',
        'portalLabel' => 'Microsoft 365 admin center',
    ],
    'gmail'     => [
        'portalUrl'   => 'https://admin.google.com/',
        'portalLabel' => 'Google Workspace admin console',
    ],
    'smtp'      => [
        'portalUrl'   => null, /* "your own server" — nothing to link to */
        'portalLabel' => null,
    ],
    'sendgrid'  => [
        'portalUrl'   => 'https://app.sendgrid.com/',
        'portalLabel' => 'SendGrid dashboard',
    ],
    'mailgun'   => [
        'portalUrl'   => 'https://app.mailgun.com/',
        'portalLabel' => 'Mailgun dashboard',
    ],
    'ses'       => [
        'portalUrl'   => 'https://console.aws.amazon.com/ses/',
        'portalLabel' => 'AWS SES console',
    ],
];

/**
 * The CAPTCHA per-form labels + native-impact captions.
 *
 * ELI5: this is the little explanation next to each "guard this form"
 * checkbox on the CAPTCHA card — moved here, word-for-word, so the guided
 * wizard's own checkbox step can show the SAME captions without a second
 * copy sitting in two files (rule #35 — a wizard-only copy would drift the
 * moment someone edited only one of them).
 *
 * WHY EXTRACTED (owner-decision O3, plan §5.1's sub-decision, taken as the
 * default): this map used to live inline in `manage/configuration.php`
 * (previously right above the CAPTCHA card's form). Moving it here is a
 * 2-line, OUTPUT-IDENTICAL extract-first (rule #22 — `editorSaveSongCore()`
 * is the reference shape): the card now reads
 * `$captchaFormMeta = integrationCaptchaFormMeta();` and renders it exactly
 * as before. A key without an entry still falls back to a generic caption
 * at each call site, so a newly-added form key can never silently vanish
 * from either the card or the wizard.
 *
 * @return array<string,array{label:string,caption:string}>
 */
function integrationCaptchaFormMeta(): array
{
    return [
        'registration'   => ['label' => 'Registration',        'caption' => 'Breaks native app sign-up until the apps add widget support.'],
        'login'          => ['label' => 'Login',               'caption' => 'Breaks native sign-in. Login already carries the strongest rate limits — enable last, if at all.'],
        'password_reset' => ['label' => 'Password reset',      'caption' => 'Breaks native password reset until the apps add widget support.'],
        'email_login'    => ['label' => 'Email login (code)',  'caption' => 'Breaks native magic-link login until the apps add widget support.'],
        'song_request'   => ['label' => 'Song requests',       'caption' => 'Guards the web /request form only — no native impact. The native-app request endpoint and any direct API submission carry no widget, so they stay bounded by the per-IP daily cap instead. Ends the no-JS form fallback while enabled.'],
        'manage_login'   => ['label' => 'Admin login (/manage)','caption' => 'Admin login page — no native impact.'],
    ];
}

/**
 * The webhooks channel-checkbox labels + captions (#2004, epic #2002) — the
 * `formMeta` for the `webhooks` entry's `webhooks_channels` checkbox-group
 * field, the SAME role `integrationCaptchaFormMeta()` plays for CAPTCHA's
 * `captcha_forms`/`captcha_strict_forms` fields above.
 *
 * WHY A SEPARATE FUNCTION HERE, NOT A DATA CONST ON THE FIELD ITSELF: the
 * checkbox-group renderer (`renderField()`,
 * `js/modules/integration-connect-wizard.js`) reads `entry.formMeta`
 * generically for ANY checkbox-group field on the entry — CAPTCHA already
 * established this shape for TWO checkbox-group fields sharing ONE
 * `formMeta` map, so `webhooks_channels` reuses the identical mechanism
 * rather than inventing a second one.
 *
 * Guard check (n) (`tests/php/test-integration-connect-wizard.php`) locks
 * these keys to `webhookParseChannelsCsv()`'s own allow-list in BOTH
 * directions — the classic Partner webhooks card's own channel checkboxes
 * (`manage/configuration.php`) iterate the SAME literal `['alpha', 'beta',
 * 'production']`, so a channel added to one without the other would leave
 * either the classic card or this wizard step silently out of step.
 *
 * @return array<string,array{label:string,caption:string}>
 */
function integrationWebhookChannelMeta(): array
{
    return [
        'alpha'      => ['label' => 'Alpha',      'caption' => 'The dev site (dev.ihymns.app).'],
        'beta'       => ['label' => 'Beta',       'caption' => 'The beta site.'],
        'production' => ['label' => 'Production', 'caption' => 'The live site — enable last.'],
    ];
}

/**
 * THE registry — one entry per integration the guided wizard covers.
 *
 * ELI5: a phone book for the wizard. For each integration it says: its
 * name/icon, the plain-language "what is this?" blurb, the things an admin
 * needs before they start (plus a link to go get them), which form fields
 * to show and how to save them, and where to prove it's working.
 *
 * PURE / STATIC — no DB, no network, no session. Every field's `setting`
 * value is a CONSTANT pulled from the client file that owns it
 * (`INTAPPS_SETTING_*` / `CUERCODE_SETTING_*` / `CAPTCHA_SETTING_*`),
 * never a re-typed string literal — rule #35, so a future rename of one of
 * those constants is a compile error here, not a silent drift.
 *
 * Phase 1 shipped `intapps` / `cuercode` / `captcha` (plan §0 of
 * `.claude/plan-integration-connect-wizard.md`); the extend phase (#2004,
 * epic #2002, `.claude/plan-connect-wizard-extend.md`) adds `email` /
 * `siwa` / `webhooks` — every entry key is still registry data only, and
 * both the client driver and the standing guard derive their per-entry
 * behaviour from this array's keys with no per-integration code change.
 *
 * @return array<string, array{
 *   label:string, icon:string, statusFn:string, saveAction:string, testFn:string,
 *   intro:string, need:list<string>, portal:?array{url:string,label:string},
 *   providers:?array<string,array{label:string,portalUrl:?string,portalLabel:?string}>,
 *   providerField:?string,
 *   fields:list<array{post:string,setting:?string,label:string,type:string,secret:bool,
 *                     help?:string,placeholder?:string,default?:string,parser?:string,
 *                     options?:array<string,string>,inputType?:string,
 *                     showWhen?:list<array{field:string,in:list<string>}>,carry?:bool,
 *                     validate?:string}>,
 *   formMetaFn:?string,
 *   surfaces:list<array{label:string,href:?string}>
 * }>
 */
function integrationRegistry(): array
{
    /* CAPTCHA's provider list is DERIVED, never typed here — iterate the one
       provider registry (`captchaProviders()`), keep only the selectable
       entries (the reserved reCAPTCHA v3 stays un-offerable), and overlay the
       portal link from the const map above by the SAME key. */
    $captchaProvidersOut = [];
    foreach (captchaProviders() as $pKey => $pEntry) {
        if (empty($pEntry['selectable'])) {
            continue; /* reserved (e.g. recaptcha_v3) — never offered here either */
        }
        $portal = IHYMNS_INTEGRATION_CAPTCHA_PORTALS[$pKey] ?? null;
        $captchaProvidersOut[(string)$pKey] = [
            'label'       => (string)($pEntry['label'] ?? $pKey),
            'portalUrl'   => $portal['portalUrl'] ?? null,
            'portalLabel' => $portal['portalLabel'] ?? null,
        ];
    }

    /* Email's provider list is likewise DERIVED, never typed — iterate
       `ihymnsEmailServiceOptions()` (#2004), SKIP 'none' (sub-decision E4:
       the wizard's job is connecting a service; disabling one stays a
       classic-card act), and overlay the portal link from the const map
       above by the SAME key. Guard check (j) asserts these keys equal
       `ihymnsEmailServiceOptions()` keys minus 'none' equal
       `IHYMNS_INTEGRATION_EMAIL_PORTALS` keys, in every direction. */
    $emailProvidersOut = [];
    foreach (ihymnsEmailServiceOptions() as $eKey => $eLabel) {
        if ($eKey === 'none') {
            continue;
        }
        $ePortal = IHYMNS_INTEGRATION_EMAIL_PORTALS[$eKey] ?? null;
        $emailProvidersOut[(string)$eKey] = [
            'label'       => (string)$eLabel,
            'portalUrl'   => $ePortal['portalUrl'] ?? null,
            'portalLabel' => $ePortal['portalLabel'] ?? null,
        ];
    }

    /* Email's FIELD list is likewise DERIVED from `ihymnsEmailSettingsModel()`
       (#2004) — never hand-typed a second time — in model order, skipping
       ONLY `email_smtp_preset` (sub-decision E1: a page-side pre-fill hint;
       `save_email` preserves it when absent from the POST, and an empty
       host already resolves to the office365/gmail preset server-side —
       see EmailService.php / the classic save_email branch). Each row's
       `showWhen` is MECHANICALLY built from the model's own `providers` +
       `authShow` columns — never hand-typed — so guard check (j) can assert
       the two never drift: a field's provider condition on `email_service`
       always equals its model `providers` column, by construction. */
    $emailFieldOmit = ['email_smtp_preset'];
    /* A small, wizard-ONLY overlay of help/placeholder/options text keyed by
       setting name — cosmetic copy only, never a second source of secret/
       provider/authShow truth (all of that stays model-derived above). */
    $emailFieldOverlay = [
        'email_smtp_secure' => ['options' => ihymnsSmtpSecureOptions(), 'default' => 'tls'],
        'email_auth_method' => ['options' => ihymnsEmailAuthMethodOptions(), 'default' => 'smtp'],
        'email_smtp_host'   => ['placeholder' => 'smtp.example.com',
            'help' => 'Leave blank to use Microsoft\'s / Google\'s standard server when the provider above is Microsoft 365 or Google Workspace.'],
        'email_smtp_port'   => ['placeholder' => '587'],
        'email_smtp_user'   => ['help' => 'The mailbox / app-password username your provider issued.'],
        'email_smtp_pass'   => ['placeholder' => 'App password (not your normal account password, for most providers).'],
        'email_from_address' => ['placeholder' => 'no-reply@yourdomain.com'],
        'email_from_name'    => ['placeholder' => 'iHymns'],
        'email_sendgrid_api_key' => ['placeholder' => 'SG.xxxxxxxx'],
        'email_mailgun_api_key'  => ['placeholder' => 'key-xxxxxxxx'],
        'email_mailgun_domain'   => ['placeholder' => 'mg.yourdomain.com'],
        'email_ses_region'       => ['placeholder' => 'eu-west-1'],
        'email_ses_access_key'   => ['placeholder' => 'AKIA…'],
        'email_graph_tenant_id'  => ['placeholder' => '00000000-0000-0000-0000-000000000000'],
        'email_graph_client_id'  => ['placeholder' => '00000000-0000-0000-0000-000000000000'],
        'email_graph_client_secret' => ['help' => 'The secret VALUE (not the Secret ID) from "Certificates & secrets".'],
        'email_graph_sender'     => ['placeholder' => 'noreply@yourtenant.com'],
        'email_gmail_sa_json'    => ['help' => 'The downloaded service-account key file (contains client_email + private_key).'],
        'email_gmail_sender'     => ['placeholder' => 'noreply@yourdomain.com',
            'help' => 'The Workspace user the service account impersonates (domain-wide delegation).'],
    ];
    $emailFieldsOut = [];
    foreach (ihymnsEmailSettingsModel() as $emKey => $emRow) {
        if (in_array($emKey, $emailFieldOmit, true)) {
            continue;
        }
        [$emLabel, $emType, $emSecret, $emProviders, $emAuthShow] = $emRow;

        $emField = [
            'post' => $emKey, 'setting' => $emKey, 'label' => $emLabel, 'secret' => $emSecret,
        ];
        if ($emType === 'select') {
            $emField['type'] = 'select';
        } elseif ($emType === 'textarea') {
            $emField['type'] = 'textarea';
        } else {
            $emField['type'] = 'text';
            if ($emType === 'email' || $emType === 'number') {
                $emField['inputType'] = $emType;
            }
            /* model type 'password' needs no inputType — a secret field
               always renders as a password input regardless of declared
               `type` (renderField()'s own secret-first rendering rule). */
        }

        $emShowWhen = [];
        if ($emProviders !== null) {
            $emShowWhen[] = ['field' => 'email_service', 'in' => $emProviders];
        }
        if ($emAuthShow !== null) {
            $emShowWhen[] = ['field' => 'email_auth_method', 'in' => [$emAuthShow]];
        }
        if ($emShowWhen !== []) {
            $emField['showWhen'] = $emShowWhen;
        }

        if (isset($emailFieldOverlay[$emKey])) {
            $emField += $emailFieldOverlay[$emKey];
        }

        $emailFieldsOut[] = $emField;
    }

    return [
        'intapps' => [
            'label'      => 'IntAppsAPI Gateway',
            'icon'       => 'bi-broadcast-pin',
            'statusFn'   => 'intappsEnabled',
            'saveAction' => 'save_intappsapi',
            'testFn'     => 'integrationTestIntapps',
            'intro'      => 'Links iHymns to the MWBM-IntAppsAPI gateway — a server-proxied, '
                . 'cache-first, fail-open feature-flag kill switch. It never decides content '
                . 'access; those decisions stay entirely local. Dormant by design: with no '
                . 'channel enabled it performs zero HTTP calls.',
            'need'       => [
                'Registration with the gateway (an owner-only prerequisite — #1726).',
                'The App UUID, an API key and an HMAC secret issued by the gateway.',
                'A decision which channel(s) to enable — canary on "alpha" first.',
            ],
            'portal'     => ['url' => INTAPPS_DEFAULT_BASE_URL, 'label' => 'MWBM IntApps gateway'],
            'providers'  => null,
            'providerField' => null,
            'fields'     => [
                [
                    'post' => 'intappsapi_enabled_channels', 'setting' => INTAPPS_SETTING_ENABLED_CHANNELS,
                    'label' => 'Enabled channels', 'type' => 'text', 'secret' => false,
                    'help' => 'Comma-separated: alpha, beta, production, or all. Empty = dormant everywhere (the shipped default).',
                    'placeholder' => 'e.g. alpha  (leave blank to stay fully dormant)', 'default' => '',
                    /* Client-side MIRROR only (convenience — the server's own
                       save_intappsapi validation, unchanged, remains the
                       authority; a mismatch here just means a slower round
                       trip, never a security gap). 'channel_tokens' names
                       the SAME token set save_intappsapi checks. */
                    'validate' => 'channel_tokens',
                ],
                [
                    'post' => 'intappsapi_base_url', 'setting' => INTAPPS_SETTING_BASE_URL,
                    'label' => 'Gateway base URL', 'type' => 'text', 'secret' => false,
                    'help' => 'Must start with https://.', 'placeholder' => INTAPPS_DEFAULT_BASE_URL,
                    'default' => INTAPPS_DEFAULT_BASE_URL, 'validate' => 'https_url',
                ],
                [
                    'post' => 'intappsapi_app_slug', 'setting' => INTAPPS_SETTING_APP_SLUG,
                    'label' => 'App slug', 'type' => 'text', 'secret' => false,
                    'placeholder' => INTAPPS_DEFAULT_APP_SLUG, 'default' => INTAPPS_DEFAULT_APP_SLUG,
                ],
                [
                    'post' => 'intappsapi_app_uuid', 'setting' => INTAPPS_SETTING_APP_UUID,
                    'label' => 'App UUID', 'type' => 'text', 'secret' => false,
                    'placeholder' => '11111111-2222-3333-4444-555555555555', 'default' => '',
                    'validate' => 'uuid',
                ],
                [
                    'post' => 'intappsapi_api_key', 'setting' => INTAPPS_SETTING_API_KEY,
                    'label' => 'API key', 'type' => 'text', 'secret' => true,
                ],
                [
                    'post' => 'intappsapi_hmac_secret', 'setting' => INTAPPS_SETTING_HMAC_SECRET,
                    'label' => 'HMAC secret', 'type' => 'text', 'secret' => true,
                ],
            ],
            'formMetaFn' => null,
            'surfaces'   => [
                ['label' => 'Connected Apps status & snapshot viewer', 'href' => '/manage/intapps-status'],
            ],
        ],

        'cuercode' => [
            'label'      => 'CueRCode QR Generator',
            'icon'       => 'bi-qr-code',
            'statusFn'   => 'cuercodeConfigured',
            'saveAction' => 'save_cuercode',
            'testFn'     => 'integrationTestCuercode',
            'intro'      => "Generates every QR code in iHymns (printed handouts' QR block and the "
                . 'Service-Projection join screen) via the CueRCode service — server-side, so the '
                . 'secret key never reaches a browser. Dormant until keyed: without a key, QR spots '
                . 'fall back to plain URL/code text.',
            'need'       => [
                'A CueRCode account.',
                'An API key generated in the CueRCode admin panel.',
            ],
            'portal'     => ['url' => 'https://cuercode.net', 'label' => 'CueRCode admin panel'],
            'providers'  => null,
            'providerField' => null,
            'fields'     => [
                [
                    'post' => 'cuercode_base_url', 'setting' => CUERCODE_SETTING_BASE_URL,
                    'label' => 'Base URL', 'type' => 'text', 'secret' => false,
                    'help' => 'Must start with https://; default is the production service.',
                    'placeholder' => CUERCODE_DEFAULT_BASE_URL, 'default' => CUERCODE_DEFAULT_BASE_URL,
                    'validate' => 'https_url',
                ],
                [
                    'post' => 'cuercode_api_key', 'setting' => CUERCODE_SETTING_API_KEY,
                    'label' => 'API key', 'type' => 'text', 'secret' => true,
                    'placeholder' => 'cuercode_…',
                ],
            ],
            'formMetaFn' => null,
            'surfaces'   => [
                ['label' => 'Print templates — QR block', 'href' => null],
                ['label' => 'Service Projection — join QR', 'href' => '/manage/service-projection.php'],
                ['label' => 'Live proof below', 'href' => null],
            ],
        ],

        'captcha' => [
            'label'      => 'CAPTCHA (bot protection)',
            'icon'       => 'bi-shield-check',
            'statusFn'   => 'captchaConfigured',
            'saveAction' => 'save_captcha',
            'testFn'     => 'integrationTestCaptcha',
            'intro'      => "A 'prove you're human' challenge on the forms you choose, verified "
                . 'server-side — the secret key never reaches a browser. The per-IP / per-account / '
                . 'per-identifier rate limits stay in force underneath regardless.',
            'need'       => [
                'An account with the chosen provider.',
                "That provider's site key (public) and secret key.",
                'A decision which forms to guard — the login form carries the strongest rate limits, so enable it last, if at all.',
            ],
            'portal'     => null, /* provider-specific — resolved client-side from `providers` once one is chosen */
            'providers'  => $captchaProvidersOut,
            'providerField' => 'captcha_provider',
            'fields'     => [
                [
                    'post' => 'captcha_provider', 'setting' => CAPTCHA_SETTING_PROVIDER,
                    'label' => 'Provider', 'type' => 'select', 'secret' => false, 'default' => 'none',
                ],
                [
                    'post' => 'captcha_site_key', 'setting' => CAPTCHA_SETTING_SITE_KEY,
                    'label' => 'Site key', 'type' => 'text', 'secret' => false,
                    'help' => 'Public — sent to browsers to draw the widget.', 'default' => '',
                ],
                [
                    'post' => 'captcha_secret_key', 'setting' => CAPTCHA_SETTING_SECRET_KEY,
                    'label' => 'Secret key', 'type' => 'text', 'secret' => true,
                ],
                [
                    'post' => 'captcha_forms', 'setting' => CAPTCHA_SETTING_FORMS,
                    'label' => 'Guard these forms', 'type' => 'checkbox-group', 'secret' => false,
                    'parser' => 'captchaParseForms',
                ],
                [
                    'post' => 'captcha_strict_forms', 'setting' => CAPTCHA_SETTING_STRICT_FORMS,
                    'label' => 'Keep strict during a provider outage', 'type' => 'checkbox-group', 'secret' => false,
                    'parser' => 'captchaParseForms',
                ],
            ],
            /* Generalised from the old hardcoded `($key === 'captcha') ? ...`
               ternary in integrationClientProjection() (#2004) — captcha is
               simply the first entry to NAME a formMeta builder function,
               never a special case the projection singles out by key. */
            'formMetaFn' => 'integrationCaptchaFormMeta',
            'surfaces'   => [
                ['label' => 'Provider health strip on the CAPTCHA card', 'href' => '/manage/configuration#captcha'],
            ],
        ],

        'email' => [
            'label'      => 'Email service',
            'icon'       => 'bi-envelope-at',
            'statusFn'   => 'emailServiceConfigured',
            'saveAction' => 'save_email',
            'testFn'     => 'integrationTestEmail',
            'intro'      => 'Sends the app\'s emails — password resets, sign-in codes and links, and '
                . 'notifications. While no provider is set, email sign-in stays hidden and '
                . 'password-only sign-in keeps working.',
            'need'       => [
                'An account with one of the supported email providers.',
                'That provider\'s sending credentials (an app password or an API key).',
                'The last step saves your details and sends a real test email to your own admin address.',
            ],
            'portal'     => null, /* provider-specific — resolved client-side from `providers` once one is chosen */
            'providers'  => $emailProvidersOut,
            'providerField' => 'email_service',
            'fields'     => $emailFieldsOut,
            'formMetaFn' => null,
            'surfaces'   => [
                ['label' => 'Step-by-step provider guides (bottom of the Configuration page)', 'href' => '/manage/configuration#email-instructions'],
                ['label' => 'Sign In — email code / magic-link login', 'href' => null],
            ],
        ],

        'siwa' => [
            'label'      => 'Sign in with Apple',
            'icon'       => 'bi-apple',
            'statusFn'   => 'appleSiwaConfigured',
            'saveAction' => 'save_apple',
            'testFn'     => 'integrationTestSiwa',
            'intro'      => 'Lets people sign in with their Apple ID. Sign-in itself works without any of '
                . 'this; these credentials add the refresh-token exchange, Apple-side sign-out on '
                . 'account deletion, and (optionally) Apple sign-in on the web.',
            'need'       => [
                'Your Apple Developer Team ID (developer.apple.com → Membership).',
                'A "Sign in with Apple" key: its 10-character Key ID and the downloaded .p8 file.',
                'Optional, for web sign-in: a separate Services ID (not the app\'s own App ID).',
                'No message is sent to Apple by this guide — the last step checks that your key, '
                    . 'Key ID and Team ID fit together. Apple itself is only contacted during a real sign-in.',
            ],
            'portal'     => ['url' => 'https://developer.apple.com/account/resources/authkeys/list', 'label' => 'Apple Developer — Keys'],
            'providers'  => null,
            'providerField' => null,
            'fields'     => [
                [
                    'post' => 'apple_team_id', 'setting' => APPLE_SETTING_TEAM_ID,
                    'label' => 'Apple Team ID', 'type' => 'text', 'secret' => false,
                    'validate' => 'ten_char_id', 'placeholder' => 'ABCDE12345',
                    'help' => 'Exactly 10 letters/digits. Also drives Universal Links (must match the APPLE_TEAM_ID build secret).',
                ],
                [
                    'post' => 'apple_siwa_key_id', 'setting' => APPLE_SETTING_SIWA_KEY_ID,
                    'label' => 'SIWA Key ID', 'type' => 'text', 'secret' => false,
                    'validate' => 'ten_char_id', 'placeholder' => 'ABCDE12345',
                ],
                [
                    'post' => 'apple_siwa_private_key', 'setting' => APPLE_SETTING_SIWA_PRIVATE_KEY,
                    'label' => 'SIWA private key (.p8)', 'type' => 'textarea', 'secret' => true,
                    'help' => 'Paste the ENTIRE downloaded .p8 file, including the BEGIN/END lines. Not the App Store Connect deploy key.',
                ],
                [
                    'post' => 'apple_siwa_services_id', 'setting' => APPLE_SETTING_SIWA_SERVICES_ID,
                    'label' => 'Services ID (web sign-in, optional)', 'type' => 'text', 'secret' => false,
                    'placeholder' => 'app.ihymns.web',
                    'help' => 'Only needed for Apple sign-in on the web. Must NOT be the app\'s own App ID.',
                ],
                [
                    'post' => 'apple_web_login_enabled', 'setting' => APPLE_SETTING_WEB_LOGIN_ENABLED,
                    'label' => 'Web sign-in enabled on channels', 'type' => 'text', 'secret' => false,
                    'validate' => 'channel_tokens', 'placeholder' => 'e.g. alpha  (leave blank to keep web sign-in off)',
                    'help' => 'Comma-separated: alpha, beta, production, or all.',
                ],
                /* CARRY (rule #45's carry-safety class, plan §4.2 sub-decision
                   E2) — save_apple UNCONDITIONALLY overwrites this key on
                   EVERY save (it has no "leave blank to keep" branch, unlike
                   the two .p8 fields below), so this wizard entry MUST post
                   its CURRENT value back or a wizard save silently WIPES the
                   stored APNs Key ID — the exact silent-corruption class
                   rule #45 names. Never rendered (buildPanes() skips any
                   field with carry===true); collectFields() posts its
                   projected `value` directly. Guard check (m) mechanically
                   re-derives save_apple's own unconditional-overwrite set
                   from source and asserts this field covers it — never a
                   comment alone (rule #35). */
                [
                    'post' => 'apple_apns_key_id', 'setting' => APPLE_SETTING_APNS_KEY_ID,
                    'label' => 'APNs Key ID (carried)', 'type' => 'text', 'secret' => false, 'carry' => true,
                ],
                /* apple_apns_private_key is deliberately OMITTED: save_apple
                   treats an absent/blank POST as "don't touch the stored
                   value" for this key (the SAME blank-keep convention as
                   apple_siwa_private_key) — so leaving it off this entry's
                   field list is itself carry-safe, not an oversight. */
            ],
            'formMetaFn' => null,
            'surfaces'   => [
                ['label' => 'The Sign In screen\'s "Sign in with Apple" button', 'href' => null],
                ['label' => 'Universal Links (apple-app-site-association) once the Team ID is saved', 'href' => null],
            ],
        ],

        'webhooks' => [
            'label'      => 'Partner webhooks',
            'icon'       => 'bi-broadcast',
            'statusFn'   => 'webhooksEnabled',
            'saveAction' => 'save_webhooks',
            'testFn'     => 'integrationTestWebhooks',
            'intro'      => 'Lets outside systems receive signed messages when things change here — songs, '
                . 'songbooks, shared set lists, live services. Fully dormant until a channel is '
                . 'ticked, and nothing is ever sent until a partner subscription exists.',
            'need'       => [
                'A decision which environments should send (start with alpha).',
                'A partner system to receive the messages — added afterwards on the Webhooks page.',
                'A scheduled job (cron or an uptime monitor) to poke the drain endpoint every minute — '
                    . 'the drain key below authorises it.',
            ],
            'portal'     => ['url' => '/manage/webhooks', 'label' => 'Webhooks — manage subscriptions'],
            'providers'  => null,
            'providerField' => null,
            'fields'     => [
                [
                    'post' => 'webhooks_channels', 'setting' => WEBHOOK_SETTING_ENABLED_CHANNELS,
                    'label' => 'Send from these environments', 'type' => 'checkbox-group', 'secret' => false,
                    'parser' => 'webhookParseChannelsCsv',
                ],
                [
                    'post' => 'webhook_allow_loopback', 'setting' => WEBHOOK_SETTING_ALLOW_LOOPBACK,
                    'label' => 'Allow http://127.0.0.1 targets (local testing only)', 'type' => 'checkbox', 'secret' => false,
                    'help' => 'Leave off on a real server.',
                ],
                /* A stateless COMMAND tick, not a stored value — `setting`
                   is deliberately null (legal ONLY for type:'checkbox' — see
                   integrationClientProjection()'s own doc-comment): it always
                   projects `checked:false`, since there is nothing saved to
                   reflect back. Ticking it tells save_webhooks to mint a
                   FRESH show-once drain key on THIS save (mirrors the
                   classic card's own "Regenerate the drain key on save"
                   checkbox, manage/configuration.php). */
                [
                    'post' => 'webhook_regenerate_drain_key', 'setting' => null,
                    'label' => 'Generate a new drain key now (shown once)', 'type' => 'checkbox', 'secret' => false,
                    'help' => 'Tick this on first set-up. The key appears once on the next step — copy it into your cron command.',
                ],
            ],
            'formMetaFn' => 'integrationWebhookChannelMeta',
            'surfaces'   => [
                ['label' => 'Manage webhook subscriptions', 'href' => '/manage/webhooks'],
                ['label' => 'Delivery status strip on the Partner webhooks card', 'href' => '/manage/configuration'],
            ],
        ],
    ];
}

/**
 * Build the secret-free JSON projection the wizard's client module reads.
 *
 * ELI5: turns the phone book above into exactly what the browser is allowed
 * to see — every non-secret field's CURRENT value (same as the card already
 * echoes), and for every secret field only a yes/no "is one saved?" flag,
 * never the secret itself.
 *
 * WHY AN INJECTED READER (`$getSetting`), NOT `getAppSetting()` DIRECTLY:
 * so this whole function is callable with NO database at all — the standing
 * guard hands it a stub that returns a sentinel string for every key and
 * proves structurally that the sentinel can never reach a `secret:true`
 * field's output (see this file's own doc-block). In real use,
 * `manage/configuration.php` injects `getAppSetting()` itself (the exact
 * function every card's render already reads through — rule #35, this page
 * can never disagree with what the cards show).
 *
 * `$statusFor`, when given, OVERRIDES the registry's own `statusFn` for the
 * `active` flag — again so the guard can prove the secret-leak property
 * without a live DB, by passing a stub that always returns false instead of
 * letting this function call the real (DB-touching) `intappsEnabled()` /
 * `cuercodeConfigured()` / `captchaConfigured()`.
 *
 * @param callable(string,?string=):?string $getSetting  (key, default) -> value|null
 * @param ?callable(string):bool            $statusFor    (integrationKey) -> active|null
 * @return array<string, array{
 *   key:string, label:string, icon:string, intro:string, need:list<string>,
 *   portal:?array{url:string,label:string},
 *   providers:?array<string,array{label:string,portalUrl:?string,portalLabel:?string}>,
 *   providerField:?string,
 *   fields:list<array<string,mixed>>, formMeta:?array<string,array{label:string,caption:string}>,
 *   surfaces:list<array{label:string,href:?string}>, saveAction:string, active:bool
 * }>
 */
function integrationClientProjection(callable $getSetting, ?callable $statusFor = null): array
{
    $out = [];
    foreach (integrationRegistry() as $key => $entry) {
        $fieldsOut = [];
        foreach ($entry['fields'] as $f) {
            $fieldOut = [
                'post'  => $f['post'],
                'label' => $f['label'],
                'type'  => $f['type'],
            ];
            if (isset($f['help']))        { $fieldOut['help']        = $f['help']; }
            if (isset($f['placeholder'])) { $fieldOut['placeholder'] = $f['placeholder']; }
            if (isset($f['validate']))    { $fieldOut['validate']    = $f['validate']; }
            /* #2004 — cosmetic passthrough (never used for a secret-safety
               decision, unlike `secret`/`set`/`value` below): the input's
               HTML `type` hint for a plain text control ('email'/'number'). */
            if (isset($f['inputType']))   { $fieldOut['inputType']   = $f['inputType']; }
            /* #2004 — the conditional-visibility rule the DRIVER evaluates
               client-side (`showWhen` conditional visibility,
               js/modules/integration-connect-wizard.js). Passed through
               verbatim — this function makes no visibility DECISION itself,
               it only carries the registry's own rule to the browser. */
            if (isset($f['showWhen']))    { $fieldOut['showWhen']    = $f['showWhen']; }
            /* #2004 — marks a field the DRIVER must post but must NEVER
               render (the save_apple APNs-Key-ID carry-safety field, §4.2
               of the extend plan). Non-secret only, by registry construction
               — a carry field always falls through to the plain `value`
               branch below, so a secret could never be marked carry without
               ALSO being non-secret, which the registry never does. */
            if (!empty($f['carry']))      { $fieldOut['carry']       = true; }
            /* #2004 — a `type:'select'` field's OWN option list, for every
               select EXCEPT the provider field (which is drawn on its own
               dedicated "Choose provider" pane from `entry.providers`
               instead — renderProviderSelect(), never this generic path). */
            if ($f['type'] === 'select' && $f['post'] !== $entry['providerField'] && isset($f['options'])) {
                $fieldOut['options'] = $f['options'];
            }

            if ($f['type'] === 'checkbox-group') {
                /* A multi-value CSV setting (e.g. captcha_forms). The parser
                   is named in the FIELD's own registry data (never a literal
                   here) so this loop stays generic across integrations —
                   today CAPTCHA and Partner webhooks each have one, but ANY
                   future checkbox-group field just names its own pure
                   parser function. */
                $raw    = (string)($getSetting($f['setting'], '') ?? '');
                $parser = $f['parser'] ?? null;
                $fieldOut['values'] = ($parser !== null && function_exists($parser))
                    ? (array)call_user_func($parser, $raw)
                    : [];
            } elseif ($f['type'] === 'checkbox') {
                /* #2004 — a SINGLE boolean tick (e.g. "allow loopback
                   targets"). `setting === null` is legal ONLY for this type
                   — a stateless command tick (e.g. "regenerate the drain key
                   now") with nothing stored to reflect, so it always
                   projects unticked. The raw setting value, when one exists,
                   is used ONLY inside this `=== '1'` boolean comparison —
                   same structural no-leak shape the secret branch below
                   uses, so a checkbox field can never echo its underlying
                   raw string either (guard check (c) proves this for both). */
                $fieldOut['checked'] = ($f['setting'] !== null)
                    && ((string)($getSetting($f['setting'], '') ?? '') === '1');
            } elseif (!empty($f['secret'])) {
                /* SECRET FIELD — the reader's return value is used ONLY in
                   this boolean comparison and is NEVER assigned into
                   $fieldOut. This is the structural guarantee the guard's
                   check (c) proves: no code path below this line can leak
                   a secret value, because none exists past this point. */
                $fieldOut['set'] = ((string)($getSetting($f['setting'], '') ?? '')) !== '';
            } else {
                $default = $f['default'] ?? '';
                $fieldOut['value'] = (string)($getSetting($f['setting'], $default) ?? $default);
                /* #2004 — Non-secret only (a carry field is always
                   non-secret by construction, so it reaches here too): the
                   driver's `showWhen` "effective value of a HIDDEN field"
                   rule needs the registry's OWN default when nothing is
                   saved yet — see the field renderer's own comment. */
                if (isset($f['default'])) { $fieldOut['default'] = $f['default']; }
            }
            $fieldsOut[] = $fieldOut;
        }

        $active = ($statusFor !== null)
            ? (bool)call_user_func($statusFor, $key)
            : (function_exists($entry['statusFn']) ? (bool)call_user_func($entry['statusFn']) : false);

        $out[$key] = [
            'key'           => $key,
            'label'         => $entry['label'],
            'icon'          => $entry['icon'],
            'intro'         => $entry['intro'],
            'need'          => $entry['need'],
            'portal'        => $entry['portal'],
            'providers'     => $entry['providers'],
            'providerField' => $entry['providerField'],
            'fields'        => $fieldsOut,
            /* #2004 — generalised from a hardcoded `($key === 'captcha') ?
               integrationCaptchaFormMeta() : null` ternary: ANY entry names
               its own formMeta builder via `formMetaFn` (a plain function
               name, resolved the SAME `function_exists()` way `statusFn`/
               `testFn` already are) — captcha and webhooks both use this
               today; a future checkbox-group entry names its own. */
            'formMeta'      => isset($entry['formMetaFn']) && $entry['formMetaFn'] !== null && function_exists($entry['formMetaFn'])
                ? call_user_func($entry['formMetaFn'])
                : null,
            'surfaces'      => $entry['surfaces'],
            'saveAction'    => $entry['saveAction'],
            'active'        => $active,
        ];
    }
    return $out;
}

/**
 * The `integration_test` branch's read-back companion — a small,
 * secret-free "what is ACTUALLY stored right now?" snapshot for one
 * integration, built by re-using the projection above.
 *
 * ELI5: after the wizard saves and tests, it asks this one more question —
 * "so what does the server think is configured now?" — so the confirm step
 * can never show something that disagrees with reality (rule #35/#40's
 * read-back doctrine).
 *
 * WHY IT DELEGATES TO `integrationClientProjection()` RATHER THAN RE-READING
 * SETTINGS ITSELF: a second read path is exactly the kind of divergence risk
 * rule #22 exists to prevent, and it would ALSO be a second place the
 * secret-safety guarantee above would need proving. This function is a thin
 * post-processing step over the SAME projection, so it inherits that
 * guarantee for free.
 *
 * @param string $key one of integrationRegistry()'s keys
 * @return array{active:bool, fields:list<array<string,mixed>>}
 */
function integrationConfigState(string $key): array
{
    $projection = integrationClientProjection(
        static fn(string $k, ?string $d = null): ?string => getAppSetting($k, $d)
    );
    if (!isset($projection[$key])) {
        return ['active' => false, 'fields' => []];
    }
    $entry  = $projection[$key];
    $fields = [];
    foreach ($entry['fields'] as $f) {
        $row = ['post' => $f['post']];
        if (array_key_exists('set', $f))    { $row['set']    = $f['set']; }
        if (array_key_exists('value', $f))  { $row['value']  = $f['value']; }
        if (array_key_exists('values', $f)) { $row['values'] = $f['values']; }
        $fields[] = $row;
    }
    return ['active' => $entry['active'], 'fields' => $fields];
}

/**
 * The `integration_test` branch's ONE dispatch point — resolves a
 * registry key to its `testFn` and calls it.
 *
 * ELI5: given "test the CueRCode connection", finds the one function that
 * knows how to do that and runs it.
 *
 * WHY DYNAMIC DISPATCH VIA THE REGISTRY'S OWN `testFn`, NOT A HAND-WRITTEN
 * switch: keeps the dispatcher itself registry-driven — adding a Phase-2
 * integration is a new `integrationRegistry()` entry + its own test
 * function, never a new `case` here. `\mysqli $db` is passed to every test
 * function for a uniform call shape even though only
 * `integrationTestIntapps()` currently uses it — PHP does not error when a
 * called function declares fewer parameters than are passed.
 *
 * @return array{ok:bool, status:string, detail:array<string,mixed>}
 */
function integrationTestDispatch(string $key, \mysqli $db): array
{
    $reg = integrationRegistry();
    if (!isset($reg[$key])) {
        return ['ok' => false, 'status' => 'unknown_integration', 'detail' => []];
    }
    $testFn = $reg[$key]['testFn'];
    if (!function_exists($testFn)) {
        return ['ok' => false, 'status' => 'unknown_integration', 'detail' => []];
    }
    return call_user_func($testFn, $db);
}

/**
 * IntAppsAPI live test — reuses the EXACT call `manage/intapps-status.php`'s
 * own "test_connection" button makes (`intappsRequest('GET', '/v1/heartbeat')`,
 * verbatim). No new core; this is a second CALLER of the one existing
 * client function, never a second implementation of the gateway call.
 *
 * @return array{ok:bool, status:string, detail:array<string,mixed>}
 */
function integrationTestIntapps(\mysqli $db): array
{
    $r = intappsRequest('GET', '/v1/heartbeat');
    $status = $r['ok']
        ? 'ok'
        : (($r['transport'] ?? '') === 'disabled' ? 'unconfigured' : 'error');

    /* O4 (plan §14): parity with intapps-status.php's own test_connection —
       SAME activity-log key, plus 'via' => 'wizard' so the audit trail can
       tell the two entry points apart. Key names + booleans/numbers only —
       never a secret value. */
    if (function_exists('logActivity')) {
        logActivity('intappsapi.test_connection', 'app_setting', 'intappsapi', [
            'transport'  => $r['transport'],
            'ok'         => $r['ok'],
            'httpStatus' => $r['httpStatus'],
            'via'        => 'wizard',
        ], $r['ok'] ? 'success' : 'failure');
    }

    return [
        'ok'     => (bool)$r['ok'],
        'status' => $status,
        'detail' => [
            'transport'       => $r['transport'],
            'httpStatus'      => $r['httpStatus'],
            'errorCode'       => $r['errorCode'],
            'errorMessage'    => $r['errorMessage'],
            'durationMs'      => $r['durationMs'],
            'resolvedEnabled' => intappsEnabled(),
        ],
    ];
}

/**
 * CueRCode live test — calls the NEW `cuercodeProbe()`
 * (`includes/cuercode_client.php`), the first connectivity test this
 * integration has ever had (plan §1's honest delta). Deliberately NOT
 * `cuercodeGenerateCached()` — a cache hit would "pass" the test without
 * ever touching the live service.
 *
 * @return array{ok:bool, status:string, detail:array<string,mixed>}
 */
function integrationTestCuercode(): array
{
    $p = cuercodeProbe();

    /* O4 (plan §14): a NEW activity-log key — CueRCode never had a test
       button before this wizard, so there is no existing key to match. */
    if (function_exists('logActivity')) {
        logActivity('cuercode.test_connection', 'app_setting', 'cuercode', [
            'status'     => $p['status'],
            'httpStatus' => $p['httpStatus'],
            'errno'      => $p['errno'],
            'via'        => 'wizard',
        ], ($p['status'] === 'ok') ? 'success' : 'failure');
    }

    return [
        'ok'     => ($p['status'] === 'ok'),
        'status' => $p['status'],
        'detail' => [
            'httpStatus' => $p['httpStatus'],
            'errno'      => $p['errno'],
            'durationMs' => $p['durationMs'],
        ],
    ];
}

/**
 * CAPTCHA live test — reuses the EXACT pair the card's own "Check provider
 * now" button uses: `captchaConfig()` then `captchaForceProbe($config)`
 * (`includes/captcha.php`). No new core. O4 (plan §14): logs nothing —
 * parity with the existing `captcha_probe` handler, which also doesn't
 * call `logActivity()` (the health observation itself is already recorded
 * by `captchaForceProbe()` -> `captchaHealthRecordObservation()`).
 *
 * @return array{ok:bool, status:string, detail:array<string,mixed>}
 */
function integrationTestCaptcha(): array
{
    $cfg = captchaConfig();
    if ($cfg === null) {
        /* Dormant (no provider/keys saved) OR the CAPTCHA_DISABLED
           break-glass file is present — same wording class as the card's
           own captcha_probe handler. */
        return ['ok' => false, 'status' => 'unconfigured', 'detail' => []];
    }
    $probe = captchaForceProbe($cfg);
    if ($probe === null) {
        /* No outbound HTTP client on this server — an absence of evidence,
           not evidence of failure (captcha.php's own honesty-rule wording). */
        return ['ok' => false, 'status' => 'unobservable', 'detail' => []];
    }
    return [
        'ok'     => ($probe['status'] === 'up'),
        'status' => (string)$probe['status'],
        'detail' => [
            'errno'      => $probe['errno'],
            'httpStatus' => $probe['httpStatus'],
        ],
    ];
}

/**
 * Email delivery test — reuses the EXACT core the classic "Send test email"
 * button now calls: `EmailService::deliveryTest()` (#2004, `includes/
 * EmailService.php`, extracted from the `test_email` branch of
 * `manage/configuration.php` in this SAME change — rule #22, never a second
 * send implementation). The real send is deliberate (the classic button's
 * own long-standing rationale: it lands harmlessly in the ADMIN's own
 * inbox) — the wizard auto-runs this the moment its Save & test step is
 * entered, and its own `need` list says so up front.
 *
 * @return array{ok:bool, status:string, detail:array<string,mixed>}
 */
function integrationTestEmail(\mysqli $db): array
{
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'EmailService.php';
    $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
    $r = EmailService::deliveryTest(trim((string)($user['email'] ?? '')));
    return [
        'ok'     => $r['ok'],
        'status' => $r['status'],
        'detail' => [
            'provider'   => $r['provider'],
            'messageId'  => $r['messageId'],
            'errorClass' => $r['errorClass'],
            'adminEmail' => $r['adminEmail'],
        ],
    ];
}

/**
 * Sign in with Apple credentials test — LOCAL and ZERO-NETWORK: mints an
 * ES256 client_secret JWT via the EXISTING, already-shipped
 * `appleSiwaBuildClientSecret()` (`includes/apple_siwa.php`) and checks only
 * whether minting SUCCEEDED. That function returns null on a non-parsing or
 * non-EC-P-256 key (its own docblock), so a NON-null return is proof the
 * .p8 key, Key ID and Team ID all cohere and CAN sign — Apple itself is
 * never contacted (only a real sign-in talks to Apple; this only proves the
 * saved trio is internally consistent).
 *
 * SECRET-SAFETY: the minted `$minted` JWT is used ONLY in the `=== null`
 * null-check immediately below its assignment — it is NEVER placed into the
 * returned `detail` array. Guard check (l) mechanically proves this
 * (`substr_count($body, '$minted') === 2`) — the same "used only in a
 * boolean comparison, never assigned onward" shape the projection's own
 * secret-field branch uses (this file's `integrationClientProjection()`).
 *
 * @return array{ok:bool, status:string, detail:array<string,mixed>}
 */
function integrationTestSiwa(\mysqli $db): array
{
    $teamId = (string)(getAppSetting(APPLE_SETTING_TEAM_ID, '') ?? '');
    $keyId  = (string)(getAppSetting(APPLE_SETTING_SIWA_KEY_ID, '') ?? '');
    $p8     = (string)(getAppSetting(APPLE_SETTING_SIWA_PRIVATE_KEY, '') ?? '');
    $flags  = ['teamIdSet' => $teamId !== '', 'keyIdSet' => $keyId !== '', 'p8Set' => $p8 !== ''];
    if ($teamId === '' || $keyId === '' || $p8 === '') {
        return ['ok' => false, 'status' => 'unconfigured', 'detail' => $flags];
    }

    $minted = appleSiwaBuildClientSecret($teamId, $keyId, IHYMNS_SIWA_CLIENT_ID, $p8, time());
    if ($minted === null) {
        /* O4 (plan §14 precedent): the FAILURE outcome is logged too — key
           NAMES/booleans only, never the signed JWT (which never reaches
           this point anyway — see the docblock above). */
        if (function_exists('logActivity')) {
            logActivity('apple_siwa.test_credentials', 'app_setting', 'apple_siwa', ['ok' => false, 'via' => 'wizard'], 'failure');
        }
        return ['ok' => false, 'status' => 'invalid_key', 'detail' => $flags];
    }

    if (function_exists('logActivity')) {
        logActivity('apple_siwa.test_credentials', 'app_setting', 'apple_siwa', ['ok' => true, 'via' => 'wizard'], 'success');
    }
    return [
        'ok'     => true,
        'status' => 'ok',
        'detail' => ['clientId' => IHYMNS_SIWA_CLIENT_ID, 'webEnabled' => appleWebLoginEnabledForChannel()],
    ];
}

/**
 * Partner webhooks health read — NO OUTBOUND HTTP CALL of any kind (nothing
 * is sent without a subscriber; the wizard's own copy says so). Reuses the
 * EXISTING `webhookDrainHealth()` (`includes/webhook_admin.php`, already
 * shipped for the classic card's passive health strip) — a pure read, so
 * O4 (plan §14): no `logActivity()` call, matching `captcha_probe`'s own
 * read-only precedent.
 *
 * @return array{ok:bool, status:string, detail:array<string,mixed>}
 */
function integrationTestWebhooks(\mysqli $db): array
{
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'webhook_admin.php';

    $chans = webhookEnabledChannels();
    if (count($chans) === 0) {
        return ['ok' => false, 'status' => 'unconfigured', 'detail' => ['channels' => []]];
    }
    if (!webhookSchemaReady($db)) {
        return ['ok' => false, 'status' => 'schema_missing', 'detail' => ['channels' => $chans]];
    }

    $h    = webhookDrainHealth($db);
    $here = webhooksEnabled();
    return [
        'ok'     => true,
        'status' => $here ? 'ok' : 'ok_elsewhere',
        'detail' => [
            'channels'    => $chans,
            'thisChannel' => ihymns_environment(),
            'activeSubs'  => $h['active_subs'],
            'dueNow'      => $h['due_now'],
            'lastDrainAt' => $h['last_drain_at'],
            'drainKeySet' => ((string)(getAppSetting(WEBHOOK_SETTING_DRAIN_KEY, '') ?? '')) !== '',
        ],
    ];
}
