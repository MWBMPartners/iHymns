<?php

declare(strict_types=1);

/**
 * iHymns — Unified themed error-page renderer.
 *
 * One place that produces every error surface so they share branding and,
 * crucially, the user's THEME (light / dark / high-contrast + CVD) — instead
 * of the old hardcoded-dark inline pages that flashed for light-mode users.
 *
 * Two render modes:
 *   - renderErrorPage()     — a complete, SELF-CONTAINED HTML document for
 *     surfaces that render BEFORE/INSTEAD of the SPA (maintenance 503,
 *     bootstrap 503/500, channel gate 401, standalone 404/403/429, the
 *     service-worker offline shell). It inlines a tiny synchronous theme
 *     resolver (mirrors manage/includes/admin-theme-init.php) + a minimal
 *     theme-token stylesheet, so it paints correctly with NO dependency on
 *     app.css / JS having loaded — which is often exactly what failed.
 *   - renderErrorFragment() — a Bootstrap card (same shape as the existing
 *     includes/pages/not-found.php) for surfaces injected INTO the already-
 *     themed SPA (page-route not-found, song/songbook/work/person not-found,
 *     api.php ?page= errors).
 *
 * This file has NO dependencies, so it is safe to require at the very top of
 * a bootstrap handler.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * The one copy of the status → copy map (#1704). Kept as its own function
 * — rather than a local inline both errorPageContent() and
 * errorPageStatuses() build separately — so the two can never drift: a
 * status added here is simultaneously "has friendly copy" and "is in the
 * whitelist", with nothing to keep in sync by hand (rule #35).
 *
 * @return array<int,array{emoji:string,fa:string,title:string,message:string}>
 */
function errorPageMap(): array
{
    return [
        400 => ['emoji' => '&#9888;&#65039;', 'fa' => 'fa-circle-exclamation',  'title' => 'Bad request',           'message' => "We couldn't process that request."],
        401 => ['emoji' => '&#128274;',        'fa' => 'fa-lock',                'title' => 'Sign in required',       'message' => 'You need to be signed in to view this.'],
        403 => ['emoji' => '&#128683;',        'fa' => 'fa-ban',                 'title' => 'Access denied',          'message' => "You don't have permission to view this."],
        404 => ['emoji' => '&#129517;',        'fa' => 'fa-map-signs',           'title' => 'Page not found',         'message' => "Sorry, the page you're looking for doesn't exist or has moved."],
        405 => ['emoji' => '&#9995;',           'fa' => 'fa-hand',                'title' => 'Request not supported',  'message' => "That page can't be opened the way it was asked for. Opening it from the home page should put things right."],
        410 => ['emoji' => '&#128230;',         'fa' => 'fa-box-archive',         'title' => 'No longer here',         'message' => "This page has been removed and isn't coming back. If you were looking for a song, searching for its title may find another copy."],
        429 => ['emoji' => '&#9203;',          'fa' => 'fa-hourglass-half',      'title' => 'Too many requests',      'message' => "You've made a lot of requests in a short time. Please wait a moment and try again."],
        500 => ['emoji' => '&#9888;&#65039;', 'fa' => 'fa-triangle-exclamation', 'title' => 'Something went wrong',   'message' => 'An unexpected error occurred on our end. Please try again in a moment.'],
        503 => ['emoji' => '&#128295;',        'fa' => 'fa-screwdriver-wrench',  'title' => 'Temporarily unavailable', 'message' => 'The service is briefly unavailable. Please check back in a few minutes.'],
    ];
}

/**
 * Default copy (emoji for the self-contained page, Font Awesome class for the
 * SPA fragment, title, message) per HTTP status.
 *
 * @param int $status
 * @return array{emoji:string,fa:string,title:string,message:string}
 */
function errorPageContent(int $status): array
{
    $map = errorPageMap();
    return $map[$status] ?? ['emoji' => '&#9888;&#65039;', 'fa' => 'fa-triangle-exclamation', 'title' => 'Error', 'message' => 'Something went wrong.'];
}

/**
 * Every status this file has dedicated copy for — i.e. the map's keys.
 *
 * ELI5: the list of error numbers we know how to explain nicely.
 *
 * Consumed by error.php so its render whitelist can never drift from this
 * map (#1704, rule #35 — "cross-file agreement needs a mechanism, not a
 * comment"). A hand-typed second list here would rot the moment a status is
 * added to one file and not the other, which is exactly how 405 and 410
 * went unmapped for as long as they did (#1704).
 *
 * @return list<int>
 */
function errorPageStatuses(): array
{
    return array_keys(errorPageMap());
}

/**
 * The compact synchronous theme-init <script>. Mirrors
 * manage/includes/admin-theme-init.php so a standalone error page resolves the
 * user's saved theme identically (same localStorage keys), with no flash.
 */
function errorPageThemeScript(): string
{
    return '<script>(function(){try{var t=(window.localStorage&&localStorage.getItem("ihymns_theme"))||"system";'
         . 'var bs="light",it=t;if(t==="system"){var d=window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches;'
         . 'it=d?"dark":"light";bs=it;}else if(t==="dark"){bs="dark";}else if(t==="high-contrast"){bs="light";}'
         . 'var h=document.documentElement;h.setAttribute("data-bs-theme",bs);h.setAttribute("data-ihymns-theme",it);'
         . 'if(it==="high-contrast")h.setAttribute("data-ihymns-contrast","high");'
         . 'var c=localStorage.getItem("ihymns_cvd_mode");if(c)h.setAttribute("data-ihymns-cvd",c);}catch(e){}})();</script>';
}

/**
 * Minimal inline theme-token stylesheet — a subset of css/app.css's tokens,
 * enough for the error card, in light / dark / high-contrast. Self-contained
 * so the page renders even when app.css didn't load.
 */
function errorPageStyles(): string
{
    return <<<'CSS'
<style>
:root{--ep-bg:#f8fafc;--ep-card:#fff;--ep-text:#1e293b;--ep-muted:#64748b;--ep-accent:#6366f1;--ep-accent2:#8b5cf6;--ep-border:rgba(0,0,0,.08)}
[data-bs-theme=dark]{--ep-bg:#0f172a;--ep-card:#1e293b;--ep-text:#f1f5f9;--ep-muted:#94a3b8;--ep-accent:#818cf8;--ep-accent2:#a78bfa;--ep-border:rgba(255,255,255,.1)}
[data-ihymns-theme=high-contrast]{--ep-bg:#fff;--ep-card:#fff;--ep-text:#000;--ep-muted:#333;--ep-accent:#0000cc;--ep-accent2:#0000cc;--ep-border:#000}
*{box-sizing:border-box}html,body{margin:0;height:100%}
body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--ep-bg);color:var(--ep-text);
 display:flex;min-height:100vh;align-items:center;justify-content:center;padding:1.5rem;line-height:1.6}
.ep-card{max-width:30rem;width:100%;text-align:center;background:var(--ep-card);border:1px solid var(--ep-border);
 border-radius:16px;padding:2.5rem 1.75rem;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.ep-emoji{font-size:2.5rem;line-height:1}
.ep-code{font-size:3.25rem;font-weight:800;line-height:1;margin:.25rem 0 .5rem;color:var(--ep-accent);
 background:linear-gradient(135deg,var(--ep-accent),var(--ep-accent2));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
/* High-contrast: drop the clipped-gradient effect for solid, maximally-legible
   text (and protect against any engine that honours text-fill-color but not
   background-clip:text, which would otherwise render the number invisible). */
[data-ihymns-theme=high-contrast] .ep-code{background:none;-webkit-text-fill-color:currentColor}
.ep-title{font-size:1.4rem;margin:0 0 .6rem}
.ep-msg{color:var(--ep-muted);margin:0 0 1.5rem}
.ep-detail{font-family:ui-monospace,monospace;font-size:.8rem;text-align:left;color:var(--ep-muted);background:var(--ep-bg);
 border:1px solid var(--ep-border);border-radius:8px;padding:.75rem;margin-bottom:1.25rem;overflow-wrap:break-word}
.ep-actions{display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center}
.ep-btn{display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;font-weight:600;font-size:.95rem;
 padding:.6rem 1.2rem;border-radius:50px;border:1px solid transparent}
.ep-btn-primary{background:linear-gradient(135deg,var(--ep-accent),var(--ep-accent2));color:#fff}
.ep-btn-ghost{background:transparent;color:var(--ep-text);border-color:var(--ep-border)}
.ep-btn:focus-visible{outline:3px solid var(--ep-accent);outline-offset:2px}
/* SR-only helper (Bootstrap's .visually-hidden equivalent) — this page is
   self-contained and does not load Bootstrap, so define it inline for the
   aria-live announcements callers inject via extraBody. */
.visually-hidden{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;
 overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
</style>
CSS;
}

/**
 * Render a complete, self-contained, theme-aware error page (and set the HTTP
 * status + headers). Does NOT exit — callers decide.
 *
 * @param int   $status
 * @param array $opts {
 *   title?:string, message?:string, emoji?:string, code?:string,
 *   detail?:string (already-safe-to-show text; caller gates by env),
 *   retryAfter?:int, noStatusCode?:bool,
 *   actions?:list<array{label:string,href:string,primary?:bool}>,
 *   extraHead?:string, extraBody?:string,
 *   extraBodyAfter?:string (markup placed AFTER </main>, before </body> — for
 *     content that must sit OUTSIDE the main's role="alert" assertive live
 *     region, e.g. a separate aria-live="polite" announcement that must not be
 *     double-announced by the alert)
 * }
 */
function renderErrorPage(int $status, array $opts = []): void
{
    $c          = errorPageContent($status);
    $title      = (string)($opts['title']   ?? $c['title']);
    $message    = (string)($opts['message'] ?? $c['message']);
    $emoji      = (string)($opts['emoji']   ?? $c['emoji']);
    $codeLabel  = (string)($opts['code']    ?? (string)$status);
    $detail     = $opts['detail'] ?? null;
    $retryAfter = $opts['retryAfter'] ?? null;
    $actions    = $opts['actions'] ?? [['label' => 'Go to home', 'href' => '/', 'primary' => true]];

    if (empty($opts['noStatusCode']) && !headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        if ($retryAfter !== null) header('Retry-After: ' . (int)$retryAfter);
        header('Cache-Control: no-store');
    }

    $eTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $eMsg   = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

    $actionsHtml = '';
    foreach ($actions as $a) {
        $cls = !empty($a['primary']) ? 'ep-btn ep-btn-primary' : 'ep-btn ep-btn-ghost';
        /* Optional leading icon. Only the self-contained-safe 'padlock' is
           supported (no Font Awesome on this page); it renders two inline
           glyphs — a CLOSED lock shown at rest and an OPEN lock revealed on
           hover/focus by the caller's CSS (.mtn-lock-closed / .mtn-lock-open),
           giving the "signing in unlocks the site" affordance. */
        $iconHtml = '';
        if (($a['icon'] ?? '') === 'padlock') {
            $iconHtml = '<span class="mtn-lock" aria-hidden="true">'
                      . '<span class="mtn-lock-closed">&#128274;</span>'   /* 🔒 closed */
                      . '<span class="mtn-lock-open">&#128275;</span>'      /* 🔓 open   */
                      . '</span> ';
        }
        $actionsHtml .= '<a class="' . $cls . '" href="' . htmlspecialchars((string)$a['href'], ENT_QUOTES, 'UTF-8') . '">'
                      . $iconHtml
                      . htmlspecialchars((string)$a['label'], ENT_QUOTES, 'UTF-8') . '</a>';
    }
    $detailHtml = ($detail !== null && $detail !== '')
        ? '<div class="ep-detail">' . htmlspecialchars((string)$detail, ENT_QUOTES, 'UTF-8') . '</div>'
        : '';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       /* viewport-fit=cover is REQUIRED for env(safe-area-inset-*) to resolve
          to non-zero inside an installed iOS PWA — without it the standalone
          safe-area padding block in maintenancePageExtraHead() is a no-op
          (env() stays 0 in the default contain mode). #1276 feature A. */
       . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
       . '<meta name="robots" content="noindex">'
       . '<title>iHymns &mdash; ' . $eTitle . '</title>'
       . errorPageThemeScript()
       . errorPageStyles()
       . ($opts['extraHead'] ?? '')
       . '</head><body><main class="ep-card" role="alert">'
       . '<div class="ep-emoji" aria-hidden="true">' . $emoji . '</div>'
       . ($codeLabel !== '' ? '<div class="ep-code">' . htmlspecialchars($codeLabel, ENT_QUOTES, 'UTF-8') . '</div>' : '')
       . '<h1 class="ep-title">' . $eTitle . '</h1>'
       . '<p class="ep-msg">' . $eMsg . '</p>'
       . $detailHtml
       . '<div class="ep-actions">' . $actionsHtml . '</div>'
       . ($opts['extraBody'] ?? '')
       . '</main>'
       . ($opts['extraBodyAfter'] ?? '')   /* outside the role="alert" main */
       . '</body></html>';
}

/**
 * Render a Bootstrap error CARD for injection into the already-themed SPA
 * (same shape as includes/pages/not-found.php). Returns the HTML; the caller
 * echoes it and sets any HTTP status. Font Awesome + app.css are already
 * loaded in the SPA, so this stays theme-aware via Bootstrap tokens.
 *
 * @param int   $status
 * @param array $opts { title?:string, message?:string, fa?:string,
 *                      actions?:list<array{label,href,navigate?,fa?,primary?}> }
 * @return string
 */
function renderErrorFragment(int $status, array $opts = []): string
{
    $c       = errorPageContent($status);
    $title   = htmlspecialchars((string)($opts['title']   ?? $c['title']),   ENT_QUOTES, 'UTF-8');
    $message = nl2br(htmlspecialchars((string)($opts['message'] ?? $c['message']), ENT_QUOTES, 'UTF-8'));
    $fa      = htmlspecialchars((string)($opts['fa'] ?? $c['fa']), ENT_QUOTES, 'UTF-8');
    $actions = $opts['actions'] ?? [
        ['label' => 'Go Home', 'href' => '/', 'navigate' => 'home', 'primary' => true, 'fa' => 'fa-house'],
    ];

    $actionsHtml = '';
    foreach ($actions as $a) {
        $cls   = !empty($a['primary']) ? 'btn btn-primary' : 'btn btn-outline-secondary';
        $nav   = !empty($a['navigate'])
            ? ' data-navigate="' . htmlspecialchars((string)$a['navigate'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $aicon = !empty($a['fa'])
            ? '<i class="fa-solid ' . htmlspecialchars((string)$a['fa'], ENT_QUOTES, 'UTF-8') . ' me-2" aria-hidden="true"></i>' : '';
        $actionsHtml .= '<a href="' . htmlspecialchars((string)$a['href'], ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '"' . $nav . '>'
                      . $aicon . htmlspecialchars((string)$a['label'], ENT_QUOTES, 'UTF-8') . '</a>';
    }

    return '<section class="page-error text-center py-5" role="alert" aria-label="' . $title . '">'
         . '<i class="fa-solid ' . $fa . ' fa-4x mb-4 text-muted opacity-50" aria-hidden="true"></i>'
         . '<h1 class="h4 mb-3">' . $title . '</h1>'
         . '<p class="text-muted mb-4">' . $message . '</p>'
         . '<div class="d-flex justify-content-center gap-3 flex-wrap">' . $actionsHtml . '</div>'
         . '</section>';
}

/**
 * Themed "content protected" card — for gated content such as copyrighted
 * lyrics once content gating is switched on. Sign-in (opens the SPA auth modal
 * via the /login route) + browse-free-songs actions. SPA fragment.
 *
 * @param string $reason Optional rule/admin reason; shown when non-empty.
 * @param array  $opts   { title?:string, message?:string }
 * @return string
 */
function renderContentGatedFragment(string $reason = '', array $opts = []): string
{
    $message = (string)($opts['message'] ?? ($reason !== ''
        ? $reason
        : 'These lyrics are protected by copyright. Sign in with an eligible account to view them.'));

    return renderErrorFragment(403, [
        'title'   => (string)($opts['title'] ?? 'Lyrics protected'),
        'message' => $message,
        'fa'      => 'fa-lock',
        'actions' => [
            ['label' => 'Sign in',             'href' => '/login',     'navigate' => 'login',     'primary' => true, 'fa' => 'fa-right-to-bracket'],
            ['label' => 'Public-domain songs', 'href' => '/songbooks', 'navigate' => 'songbooks', 'fa' => 'fa-book-open'],
        ],
    ]);
}
