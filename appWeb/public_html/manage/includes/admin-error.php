<?php

declare(strict_types=1);

/**
 * iHymns — Admin error screens ("you can't do that here")
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The ONE place the /manage/ area turns "no permission" and "your form timed
 * out" into something a person can read. Before this file, every admin page
 * answered those cases with a bare line of unstyled text such as
 * "403 — manage_tunes required" or "Invalid CSRF token" — it looked
 * unfinished, it showed a raw internal key as its main message, and it gave
 * the reader no way back. Now they all share the same themed error page the
 * rest of the site already uses (includes/error_page.php).
 *
 * ELI5:
 * If you open an admin page you are not allowed to use, or you leave a form
 * open for ages and it stops working, you now get a friendly screen that says
 * what happened in plain words and gives you buttons to get back — instead of
 * a blank page with a code on it.
 *
 * WHAT IS HERE:
 *   adminDeny()            — the general helper. Pick a status and a message.
 *   adminDenyEntitlement() — "you don't have permission to <do that>".
 *   adminDenyRole()        — "this page is for admins / curators / ..." (used by
 *                            requireAdmin() and friends in auth.php).
 *   adminDenyCsrf()        — "your session expired", for a failed CSRF check.
 *   adminEntitlementPhrase() — turns an entitlement key into plain words.
 *   adminRequestWantsJson()  — is a script (not a person) waiting for the answer?
 *
 * DETAILED / WHY:
 *  - PRESENTATION ONLY. None of these functions decides who is allowed in.
 *    Every caller keeps its own check (isAuthenticated(), userHasEntitlement(),
 *    validateCsrf() / validateCsrfRequest()) in the same place and the same
 *    order; the helper only runs once that check has already said "no", and it
 *    always ends the request with exit — exactly like the bare echo + exit
 *    it replaces (CLAUDE.md checkpoint 4: auth checks are never reinvented).
 *  - SCRIPTS GET JSON, PEOPLE GET A PAGE. A fetch() call that fails a
 *    permission or CSRF check expects to read a reply it can parse. Handing
 *    it a full HTML page would turn a clear "your session expired" into a
 *    confusing parse error. So when the request carries the
 *    X-Requested-With header — the same signal validateCsrfRequest() uses to
 *    recognise a script-driven same-origin call (rule #29); a browser cannot
 *    send it on a normal page visit or a plain form post — the reply is
 *    {"ok":false,"error":"…"} with the right status code instead of HTML.
 *    An Accept header that asks only for JSON counts too.
 *  - The status code is the contract, not the wording (rule #35): callers that
 *    care read the 403, never the sentence.
 *  - Entitlement wording is derived, not copied. The friendly label map
 *    ($ENTITLEMENT_LABELS) is a page-local variable inside
 *    manage/entitlements.php (and tests/php/test-orphan-inventory.php reads it
 *    from there), so it cannot be reached from here. Most keys read well once
 *    the underscores become spaces ("manage_tunes" -> "manage tunes"); the few
 *    that do not get a short override below, and
 *    tests/php/test-admin-error-pages.php checks every override names a real
 *    entitlement so the list cannot quietly go stale (rule #35).
 *
 * USAGE (from an admin page, after requireAuth() / isAuthenticated()):
 *   if (!userHasEntitlement('manage_tunes', $currentUser['role'] ?? null)) {
 *       adminDenyEntitlement('manage_tunes');     // renders the page and exits
 *   }
 *   if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
 *       adminDenyCsrf();                          // renders the page and exits
 *   }
 *
 * @see appWeb/public_html/includes/error_page.php        renderErrorPage()
 * @see appWeb/public_html/manage/includes/auth.php       requires this file
 * @see tests/php/test-admin-error-pages.php               the guard that keeps the bare output out
 * @requires PHP 8.5+
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* The themed page itself. require_once so loading it twice (auth.php plus a
   page that also pulls it in) never redeclares its functions. It has no
   dependencies of its own, so this is safe at the very top of an admin
   bootstrap. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
          . DIRECTORY_SEPARATOR . 'error_page.php';

/**
 * Plain-words phrases for the entitlement keys whose underscored form does not
 * read well in a sentence. Anything not listed falls back to the key with
 * underscores turned into spaces (see adminEntitlementPhrase()).
 *
 * Each value completes the sentence "You don't have permission to ___."
 *
 * @return array<string,string>
 */
function adminEntitlementPhraseOverrides(): array
{
    return [
        'run_db_install'        => 'install or upgrade the database',
        'run_db_migrate'        => 'run database migrations',
        'run_db_backup'         => 'back up the database',
        'run_db_restore'        => 'restore the database from a backup',
        'drop_legacy_tables'    => 'retire old database tables',
        'view_activity_log'     => 'view the activity log',
        'view_ccli_report'      => 'view the CCLI usage report',
        'view_org_ccli_report'  => "view your organisation's CCLI report",
        'view_api_docs'         => 'view the API documentation',
        'view_diagnostics'      => 'view SQL diagnostics',
        'manage_configuration'  => 'manage system configuration',
        'manage_own_organisation' => 'manage your own organisation',
    ];
}

/**
 * Turn an entitlement key into a short plain-English phrase.
 *
 * ELI5: "manage_tunes" becomes "manage tunes", so the message can say
 * "You don't have permission to manage tunes." and never shows a raw key.
 *
 * @param string $key Entitlement key, e.g. 'manage_tunes'
 * @return string Lower-case phrase that finishes "permission to ___"
 */
function adminEntitlementPhrase(string $key): string
{
    $overrides = adminEntitlementPhraseOverrides();
    if (isset($overrides[$key])) {
        return $overrides[$key];
    }
    $phrase = trim(str_replace('_', ' ', $key));
    return $phrase !== '' ? $phrase : 'do that';
}

/**
 * Is the caller a script waiting for data, rather than a person looking at a
 * page?
 *
 * ELI5: a person opening a page sends no special header; a script that calls
 * fetch() on our own pages adds "X-Requested-With". If that header is there we
 * answer with data the script can read, not a whole web page.
 *
 * Same signal validateCsrfRequest() trusts (rule #29): a browser cannot add
 * that header on a normal navigation or a plain form post, so a person never
 * gets JSON by accident.
 *
 * @return bool
 */
function adminRequestWantsJson(): bool
{
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        return true;
    }
    /* A request that asks ONLY for JSON (no text/html in its Accept list) is
       also clearly not a person opening a page. */
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $accept !== ''
        && str_contains($accept, 'application/json')
        && !str_contains($accept, 'text/html');
}

/**
 * The path of the page being asked for, safe to use as a "Go back" link.
 *
 * ELI5: the address of the admin page the person was on, without any extra
 * bits after a "?". Anything that does not look like one of our own admin
 * pages falls back to the dashboard.
 *
 * Why so strict: the value comes from the request, so it is only used if it is
 * a plain path under /manage. That rules out a "//other-site" style address
 * turning the button into a link that leaves the site.
 *
 * @return string A path starting with /manage
 */
function adminSelfPath(): string
{
    $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
    $ok   = ($path === '/manage' || str_starts_with($path, '/manage/'))
         && preg_match('~^/[A-Za-z0-9/_.\-]*$~', $path) === 1
         && !str_contains($path, '..')      /* no climbing out of /manage */
         && !str_contains($path, '//');     /* no protocol-relative tricks */
    return $ok ? $path : '/manage/';
}

/**
 * Show a themed error screen on an admin page and stop.
 *
 * ELI5: one call that says "tell the person what went wrong, nicely, give them
 * a way back, and end the request". It never returns.
 *
 * @param int    $status  HTTP status to send (403 for a refusal)
 * @param string $message Plain-English sentence(s) shown to the person
 * @param array  $opts {
 *   title?:string   Heading. Defaults to the standard title for the status.
 *   actions?:list<array{label:string,href:string,primary?:bool}>
 *                   Buttons. Defaults to "Back to dashboard" + "Go to iHymns".
 *   format?:string  'auto' (default — JSON for a script, page for a person),
 *                   'html' (always the page) or 'json' (always JSON).
 *   json?:array     Body to send when the reply is JSON. Defaults to
 *                   {"ok":false,"error":<message>} — pass this when the
 *                   endpoint's own errors use a different shape.
 * }
 * @return never
 */
function adminDeny(int $status, string $message, array $opts = []): never
{
    $format = (string)($opts['format'] ?? 'auto');
    if ($format === 'auto') {
        $format = adminRequestWantsJson() ? 'json' : 'html';
    }

    if ($format === 'json') {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        $body = $opts['json'] ?? ['ok' => false, 'error' => $message];
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $title   = (string)($opts['title'] ?? errorPageContent($status)['title']);
    $actions = $opts['actions'] ?? [
        ['label' => 'Back to dashboard', 'href' => '/manage/', 'primary' => true],
        ['label' => 'Go to iHymns',      'href' => '/'],
    ];

    renderErrorPage($status, [
        'title'   => $title,
        'message' => $message,
        'actions' => $actions,
    ]);
    exit;
}

/**
 * "You don't have permission to <do that>" — the standard refusal for a missing
 * entitlement.
 *
 * Call this where the page used to echo "403 — <key> required". The caller
 * keeps its own userHasEntitlement() check; this only answers once it failed.
 *
 * @param string $entitlement Entitlement key the person lacked (never shown raw)
 * @param array  $opts        Same options as adminDeny()
 * @return never
 */
function adminDenyEntitlement(string $entitlement, array $opts = []): never
{
    adminDeny(
        403,
        "You don't have permission to " . adminEntitlementPhrase($entitlement)
            . '. Ask an administrator if you need access.',
        $opts + ['title' => "You don't have access"]
    );
}

/**
 * "This page is for <role>" — the standard refusal for a role check
 * (requireAdmin() / requireEditor() / requireGlobalAdmin() in auth.php, and the
 * editor pages that check hasRole() themselves).
 *
 * @param string $role Minimum role needed: 'editor', 'admin' or 'global_admin'
 * @param array  $opts Same options as adminDeny()
 * @return never
 */
function adminDenyRole(string $role, array $opts = []): never
{
    $who = match ($role) {
        'global_admin' => 'global administrators',
        'admin'        => 'administrators',
        'editor'       => 'curators and editors',
        default        => 'people with a higher role',
    };
    adminDeny(
        403,
        "This page is for {$who}. Ask an administrator if you need access.",
        $opts + ['title' => "You don't have access"]
    );
}

/**
 * "Your session expired" — the standard reply when a form's CSRF check fails.
 *
 * ELI5: the form sat open too long (or the page was open in two tabs), so its
 * safety token no longer matches. Nothing was saved. Tell the person that and
 * give them a button back to the page so they can reload and try again.
 *
 * Status stays 403 (what the bare reply sent before). A script gets
 * {"ok":false,"error":"…"} instead of the page (see adminRequestWantsJson()).
 *
 * @param array $opts Same options as adminDeny()
 * @return never
 */
function adminDenyCsrf(array $opts = []): never
{
    adminDeny(
        403,
        "For your security this form timed out. Go back, reload the page and try again — your changes weren't saved.",
        $opts + [
            'title'   => 'Your session expired',
            'actions' => [
                ['label' => 'Go back',          'href' => adminSelfPath(), 'primary' => true],
                ['label' => 'Back to dashboard', 'href' => '/manage/'],
            ],
            'json'    => ['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'],
        ]
    );
}
