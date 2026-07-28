<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Swagger UI for the REST API (audit follow-up, hardened #1587)
 *
 * Renders Swagger UI against /api-docs.yaml — the single-source-of-truth
 * OpenAPI 3.0 file that lives at appWeb/public_html/api-docs.yaml.
 *
 * Auth: the `view_api_docs` entitlement (editor / admin / global_admin —
 * see includes/entitlements.php:186).
 *
 * ELI5: only people whose account is allowed to see the API docs can open
 * this page, and it's the SAME permission the menu link checks.
 *
 * Why it changed (#1587): the page used to call requireEditor(), which admits
 * every role >= 2 — including `curator`, who is NOT in the `view_api_docs`
 * list. So the nav and the page disagreed in both directions: a curator saw no
 * link but could deep-link straight in, and any future role granted the
 * entitlement without the role level would have seen a link that 403s. Rule #4
 * of .claude/CLAUDE.md: pages check the shared entitlement, never a parallel
 * hand-rolled rule. The deny idiom below is copied from manage/tags.php so
 * every entitlement-gated page fails the same way.
 *
 * Assets: Swagger UI is version-pinned with Subresource Integrity and falls
 * back to the vendored copy under /vendor/swagger-ui/ (populated by
 * tools/download-vendor.sh, which deploy.yml already runs) — the #354 / #526
 * CDN-plus-local-fallback pattern index.php uses for Bootstrap and jQuery.
 * Previously this floated the `swagger-ui-dist@5` MAJOR tag with no integrity
 * hash, so jsDelivr could serve different code into an authenticated admin
 * session on any 5.x release, and the "Try it out" runner fires real requests
 * at the live environment.
 *
 * Note there is no CSP on /manage/*, so the inline bootstrap script below is
 * legitimate here — rule #30 (no inline scripts) governs the PUBLIC SPA
 * fragments served by api.php, which are shared-cached and nonce-gated.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('view_api_docs', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — view_api_docs required</h1></body></html>';
    exit;
}
$activePage  = 'api-docs';

/* Pinned CDN URLs + SRI hashes + local fallback paths (#1587). Same registry
   index.php reads for Bootstrap / jQuery / Font Awesome. */
$libs   = APP_CONFIG['libraries'];
$swagger = $libs['swaggerui'];

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Docs — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <!-- Swagger UI stylesheet — pinned + SRI, falling back to the vendored
         copy if the CDN is unreachable or the hash doesn't match (#1587).
         The onerror handler must clear integrity/crossorigin before retrying:
         the local file is same-origin and its bytes are the CDN's, but a
         stale integrity attribute would block the retry too. -->
    <link rel="stylesheet"
          href="<?= htmlspecialchars($swagger['css_cdn'], ENT_QUOTES) ?>"
          integrity="<?= htmlspecialchars($swagger['css_sri'], ENT_QUOTES) ?>"
          crossorigin="anonymous"
          onerror="this.onerror=null;this.removeAttribute('integrity');this.removeAttribute('crossorigin');this.href='/<?= htmlspecialchars($swagger['css_local'], ENT_QUOTES) ?>';">
    <style>
        /* Constrain Swagger UI's hard-coded white background to the
           pane only — the rest of the page still respects the admin
           theme (light / dark / high-contrast). */
        #swagger-ui {
            background: #fff;
            color: #222;
            border-radius: 0.375rem;
            padding: 0.5rem;
        }
        /* Light-on-dark surfaces in the admin chrome need a hairline
           around the Swagger box so the white pane doesn't look
           untethered. */
        [data-bs-theme="dark"] #swagger-ui,
        [data-ihymns-theme="dark"] #swagger-ui,
        [data-ihymns-theme="hc"] #swagger-ui {
            border: 1px solid var(--bs-border-color, #444);
        }
    </style>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-file-earmark-code me-2"></i>API Docs
            </h1>
            <p class="text-secondary small mb-0">
                Browseable rendering of <code>/api-docs.yaml</code> — every
                endpoint of the iHymns REST API with request / response
                schemas, examples, and a "Try it out" runner. Authenticated
                endpoints need a Bearer token; paste yours into Swagger's
                <em>Authorize</em> dialog to exercise admin / user-scoped
                routes inline.
                <span class="badge bg-info-subtle text-info-emphasis ms-1" style="font-size: 0.7rem; font-weight: 600;">
                    Editor / Admin only
                </span>
                <span class="badge bg-warning-subtle text-warning-emphasis ms-1" style="font-size: 0.7rem; font-weight: 600;">
                    Try it out hits this environment for real
                </span>
            </p>
        </div>
        <a href="/api-docs.yaml" class="btn btn-sm btn-outline-secondary" download>
            <i class="bi bi-download me-1"></i>Download spec
        </a>
    </div>

    <div id="swagger-ui" aria-live="polite"></div>
</main>

<script src="<?= htmlspecialchars($swagger['js_cdn'], ENT_QUOTES) ?>"
        integrity="<?= htmlspecialchars($swagger['js_sri'], ENT_QUOTES) ?>"
        crossorigin="anonymous"
        charset="UTF-8"></script>
<script src="<?= htmlspecialchars($swagger['preset_cdn'], ENT_QUOTES) ?>"
        integrity="<?= htmlspecialchars($swagger['preset_sri'], ENT_QUOTES) ?>"
        crossorigin="anonymous"
        charset="UTF-8"></script>
<script>
    /* Fallback: load Swagger UI locally if the CDN failed or the SRI check
       rejected the response (#1587). Same symbol-presence idiom index.php uses
       for jQuery and Bootstrap — `async = false` keeps the two files in order,
       and document.write is avoided because we are past parse time.

       ELI5: if the copy on the internet didn't arrive, use the copy we ship. */
    (function () {
        var pending = [];
        if (typeof SwaggerUIBundle !== 'function') {
            pending.push('/<?= htmlspecialchars($swagger['js_local'], ENT_QUOTES) ?>');
        }
        if (typeof SwaggerUIStandalonePreset !== 'function') {
            pending.push('/<?= htmlspecialchars($swagger['preset_local'], ENT_QUOTES) ?>');
        }
        pending.forEach(function (src) {
            var s = document.createElement('script');
            s.src = src;
            s.async = false;
            document.head.appendChild(s);
        });
    })();
</script>
<script>
    /* Render after both Swagger UI scripts have parsed (they ship
       synchronous, no defer/async) AND after any local fallback injected
       above has run — `load` fires once every such script has executed. */
    window.addEventListener('load', function () {
        if (typeof SwaggerUIBundle !== 'function') {
            /* Never fail silently (#1582). This page has no error monitor and
               /manage/* has no client error beacon, so a blank pane with a
               console message is indistinguishable from "the docs are broken
               forever" — exactly the silent-no-op class that hid the public
               Export outage for seven weeks. Say so on screen. */
            console.error('[api-docs] Swagger UI failed to load from both the CDN and /vendor/swagger-ui/.');
            var host = document.getElementById('swagger-ui');
            if (host) {
                host.innerHTML =
                    '<div class="alert alert-warning mb-0" role="alert">'
                    + '<strong>The API browser could not load its viewer.</strong> '
                    + 'This usually means the network blocked the Swagger UI files. '
                    + 'Check your connection and reload the page — or '
                    + '<a href="/api-docs.yaml" download>download the spec</a> '
                    + 'and open it in another OpenAPI viewer.'
                    + '</div>';
            }
            return;
        }
        SwaggerUIBundle({
            url: '/api-docs.yaml',
            dom_id: '#swagger-ui',
            deepLinking: true,
            docExpansion: 'none',
            filter: true,
            tryItOutEnabled: true,
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIStandalonePreset,
            ],
            plugins: [
                SwaggerUIBundle.plugins.DownloadUrl,
            ],
            layout: 'StandaloneLayout',
        });
    });
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
