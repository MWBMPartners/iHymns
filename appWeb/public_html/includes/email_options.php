<?php

declare(strict_types=1);

/**
 * iHymns — Email service vocabulary (#2004, epic #2002)
 * =========================================================================
 *
 * ELI5
 * ----
 * `/manage/configuration`'s Email service card needs four small lists to
 * draw itself: which providers exist, which fields each one needs, which
 * SMTP-encryption choices exist, and which authentication methods exist.
 * Those four lists used to be typed directly into `manage/configuration.php`
 * — this file is the SAME four lists, moved here unchanged, so a SECOND
 * reader (the "Connect a service" guided wizard's registry,
 * `includes/integration_registry.php`) can build its own email step from
 * them instead of typing a second copy (rule #22 — extract first, use
 * second; the `editorSaveSongCore()` reference shape).
 *
 * DETAIL
 * ------
 * `ihymnsEmailSettingsModel()` is the extraction of primary interest: it is
 * BYTE-IDENTICAL in the first four array elements to the `$EMAIL_SETTINGS`
 * array `manage/configuration.php`'s `save_email` handler has always
 * destructured as `[$label, $type, $secret, $providers]` — adding a FIFTH
 * element (`authShow`) is safe because PHP's list-destructuring syntax
 * `[$a, $b, $c, $d] = [...]` only ever reads the positions it names; a
 * longer source array is not an error (see the PHP manual link below). The
 * fifth element transcribes an axis the classic form's markup ALREADY
 * encodes as a `data-auth-show="smtp|oauth2"` attribute on each field
 * group (see `manage/configuration.php`'s Email service card) — this file
 * just gives that axis a machine-readable home so the wizard's registry can
 * derive a field's visibility condition instead of a human re-typing it.
 *
 * `ihymnsEmailServiceOptions()` / `ihymnsEmailAuthMethodOptions()` /
 * `ihymnsSmtpSecureOptions()` are the three small value=>label maps the
 * classic card's `<select>` elements loop over — moved verbatim.
 *
 * `emailServiceConfigured(): bool` is NEW — a plain-function wrapper around
 * `EmailService::isConfigured()`. It exists ONLY because the wizard
 * registry's `statusFn` is resolved via `function_exists()`
 * (`includes/integration_registry.php`'s `integrationClientProjection()`),
 * which is false for a static-method string like `'EmailService::isConfigured'`
 * — PHP's `function_exists()` does not recognise that shape as a class
 * method reference. `EmailService.php` itself is require_once'd lazily
 * INSIDE this wrapper (not at this file's own top) to keep this file's own
 * load side-effect-free — see below.
 *
 * REQUIRE-SAFE BY DESIGN: loading this file opens no DB connection and
 * makes no HTTP call — every top-level statement here is either a pure
 * function or a `const`-free array-returning function. This matters because
 * `includes/integration_registry.php` requires this file at ITS OWN top,
 * and that file in turn is required DB-free by the standing guard
 * (`tests/php/test-integration-connect-wizard.php`).
 *
 * @see appWeb/public_html/manage/configuration.php   the one page consumer (Email service card + save_email + test_email)
 * @see appWeb/public_html/includes/integration_registry.php  the wizard registry's SECOND reader of this vocabulary
 * @see appWeb/public_html/includes/EmailService.php   the sender this vocabulary configures
 * @see appWeb/public_html/includes/smtp_presets.php   the SEPARATE host/port/secure preset map (unrelated axis, untouched by this file)
 * @link https://www.php.net/manual/en/language.destructuring.list.php  PHP list()/[] destructuring assignment (extra source elements are silently ignored)
 * #2004 (epic #2002)
 */

/**
 * The Email service settings model — ONE row per `tblAppSettings` key the
 * Email service card manages.
 *
 * ELI5: for every box on the Email service form, this says its on-screen
 * name, what KIND of box it is (text / password / dropdown / big text
 * area), whether it's a secret (so it's never echoed back to the browser),
 * which providers show it at all, and — new here — whether it ALSO needs a
 * particular "Authentication method" chosen before it shows.
 *
 * Row shape: `[label, type, secret, providers, authShow]`
 *   - label:     the visible field label.
 *   - type:      'select' | 'text' | 'email' | 'number' | 'password' | 'textarea'.
 *   - secret:    true = never echo the current value; a blank SAVE means
 *                "leave the stored value alone" (save_email's own
 *                `if ($secret && $value === '') continue;`, unchanged).
 *   - providers: null (shown for every provider, incl. 'none' — only
 *                `email_service` itself is this), or the list of
 *                `email_service` values that show this field.
 *   - authShow:  null (no further condition), or 'smtp' / 'oauth2' — the
 *                `email_auth_method` value ALSO required, mirroring the
 *                classic card's `data-auth-show="smtp|oauth2"` markup
 *                attribute on the SAME seven SMTP-server / six OAuth2 rows.
 *
 * @return array<string, array{0:string,1:string,2:bool,3:?list<string>,4:?string}>
 */
function ihymnsEmailSettingsModel(): array
{
    return [
        /* key                     => [label, type, secret, providers, authShow] */
        'email_service'             => ['Email service',             'select', false, null, null],
        /* #1309 — 'office365' and 'gmail' are first-class SMTP-AUTH providers, so
           the SMTP + common field groups are visible for them too. */
        'email_from_address'        => ['From address',              'email',  false, ['smtp','office365','gmail','sendgrid','mailgun','ses'], null],
        'email_from_name'           => ['From name',                 'text',   false, ['smtp','office365','gmail','sendgrid','mailgun','ses'], null],
        /* feature C — SMTP provider preset (pre-fills host/port/secure in the
           UI; constrained server-side to the $SMTP_PRESETS keys). Custom SMTP
           only — for office365/gmail the preset is implied by the provider.
           authShow stays null (no data-auth-show wrapper on this row in the
           classic markup — it's nested under data-provider-show="smtp" only). */
        'email_smtp_preset'         => ['SMTP provider preset',      'select', false, ['smtp'], null],
        'email_smtp_host'           => ['SMTP host',                 'text',   false, ['smtp','office365','gmail'], 'smtp'],
        'email_smtp_port'           => ['SMTP port',                 'number', false, ['smtp','office365','gmail'], 'smtp'],
        'email_smtp_user'           => ['SMTP username',             'text',   false, ['smtp','office365','gmail'], 'smtp'],
        'email_smtp_pass'           => ['SMTP password',             'password', true, ['smtp','office365','gmail'], 'smtp'],
        'email_smtp_secure'         => ['SMTP encryption',           'select', false, ['smtp','office365','gmail'], 'smtp'],
        /* feature C — delegate / send-as. Optional; validated as an email in
           the save handler. When set, mail is sent FROM this mailbox while
           AUTH still uses the SMTP username above (the login mailbox must be
           granted Send-As on it in the provider's admin console). */
        'email_smtp_from_address'   => ['Send-as / From address (delegate)', 'email', false, ['smtp','office365','gmail'], 'smtp'],
        'email_smtp_from_name'      => ['Send-as display name',      'text',   false, ['smtp','office365','gmail'], 'smtp'],
        'email_sendgrid_api_key'    => ['SendGrid API key',          'password', true, ['sendgrid'], null],
        'email_mailgun_api_key'     => ['Mailgun API key',           'password', true, ['mailgun'], null],
        'email_mailgun_domain'      => ['Mailgun domain',            'text',   false, ['mailgun'], null],
        'email_ses_region'          => ['AWS region (e.g. eu-west-1)', 'text', false, ['ses'], null],
        'email_ses_access_key'      => ['AWS access key',            'password', true, ['ses'], null],
        'email_ses_secret_key'      => ['AWS secret key',            'password', true, ['ses'], null],
        /* #1311 — OAuth2 API transport. The auth-method selector applies to the
           office365/gmail providers; the Graph + Gmail-API credential fields show
           only when method=oauth2 (client-side data-auth-show). The secrets (client
           secret, service-account JSON) keep secret=true → blank-skip on save +
           redaction from the activity-log key list. authShow stays null for this
           row itself — it IS the selector, not something conditioned on one. */
        'email_auth_method'         => ['Authentication method',          'select',   false, ['office365','gmail'], null],
        'email_graph_tenant_id'     => ['Azure tenant ID',                'text',     false, ['office365'], 'oauth2'],
        'email_graph_client_id'     => ['Azure app (client) ID',          'text',     false, ['office365'], 'oauth2'],
        'email_graph_client_secret' => ['Azure client secret',            'password', true,  ['office365'], 'oauth2'],
        'email_graph_sender'        => ['Sender mailbox (UPN)',           'text',     false, ['office365'], 'oauth2'],
        'email_gmail_sa_json'       => ['Service-account JSON key',       'textarea', true,  ['gmail'], 'oauth2'],
        'email_gmail_sender'        => ['Sender mailbox (impersonated)',  'text',     false, ['gmail'], 'oauth2'],
    ];
}

/** #1311 — OAuth2 transport options for the office365/gmail providers. */
function ihymnsEmailAuthMethodOptions(): array
{
    return [
        'smtp'   => 'SMTP-AUTH (host + app password)',
        'oauth2' => 'OAuth2 API (Microsoft Graph / Gmail API — no SMTP)',
    ];
}

/* #1309 — Microsoft 365 + Google Workspace are FIRST-CLASS providers in this
   dropdown (they used to be a nested "preset" under a generic SMTP entry,
   which the owner reported as undiscoverable — the guides showed but the
   services weren't selectable). The keys match the shared smtp_presets map so
   the pre-fill JS + EmailService dispatch recognise them; both route through
   the SMTP-AUTH transport. 'smtp' remains for any OTHER custom server. */
function ihymnsEmailServiceOptions(): array
{
    return [
        'none'      => 'None — email login disabled',
        'office365' => 'Microsoft 365 (Exchange Online)',
        'gmail'     => 'Google Workspace / Gmail',
        'smtp'      => 'SMTP (other / custom server)',
        'sendgrid'  => 'SendGrid',
        'mailgun'   => 'Mailgun',
        'ses'       => 'AWS SES',
    ];
}

function ihymnsSmtpSecureOptions(): array
{
    return [
        'tls'  => 'STARTTLS (port 587)',
        'ssl'  => 'SSL/TLS implicit (port 465)',
        'none' => 'None (port 25 — not recommended)',
    ];
}

/**
 * Is an email provider actually selected right now? The wizard registry's
 * `statusFn` for the `email` entry (`includes/integration_registry.php`).
 *
 * ELI5: "is email turned on?" — yes once a real provider (not 'none') is
 * saved.
 *
 * WHY THIS WRAPPER EXISTS (rather than naming `EmailService::isConfigured`
 * directly in the registry): the registry resolves a `statusFn` string via
 * PHP's `function_exists()` (`integrationClientProjection()`), which only
 * recognises plain function names — never a `Class::method` string. A
 * one-line free function is cheaper than teaching the registry a second
 * dispatch shape for the sake of one entry.
 *
 * `EmailService.php` is require_once'd LAZILY, inside this function body,
 * not at this file's own top — keeps `email_options.php` itself
 * side-effect-free to require (this file's own doc-block "REQUIRE-SAFE"
 * note), since `EmailService::isConfigured()` opens a DB connection the
 * moment it is actually CALLED, not merely loaded.
 *
 * @return bool
 */
function emailServiceConfigured(): bool
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'EmailService.php';
    return EmailService::isConfigured();
}
