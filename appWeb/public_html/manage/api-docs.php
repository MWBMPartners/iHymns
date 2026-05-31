<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Swagger UI for the REST API (audit follow-up)
 *
 * Curator / Editor / Admin / Global Admin only — gated via
 * requireEditor() which covers role levels >= 2 (curator,
 * editor, admin, global_admin) per manage/includes/auth.php.
 *
 * Renders Swagger UI 5.x pointing at /api-docs.yaml — the
 * single-source-of-truth OpenAPI 3.0 file that already lives
 * at appWeb/public_html/api-docs.yaml. Swagger UI assets are
 * loaded from the jsdelivr CDN for v1; a follow-up can
 * vendor them locally under public_html/vendor/ via the
 * existing tools/download-vendor.sh pattern (#354 / #526)
 * if offline-admin access is needed.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';

requireEditor();

$currentUser = getCurrentUser();
$activePage  = 'api-docs';

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Docs — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
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
                    Curator / Editor / Admin only
                </span>
            </p>
        </div>
        <a href="/api-docs.yaml" class="btn btn-sm btn-outline-secondary" download>
            <i class="bi bi-download me-1"></i>Download spec
        </a>
    </div>

    <div id="swagger-ui" aria-live="polite"></div>
</main>

<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js" charset="UTF-8"></script>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-standalone-preset.js" charset="UTF-8"></script>
<script>
    /* Render after both Swagger UI scripts have parsed (they ship
       synchronous, no defer/async). DOMContentLoaded would work
       too; we use load to be defensive against future CDN swaps. */
    window.addEventListener('load', function () {
        if (typeof SwaggerUIBundle !== 'function') {
            console.error('[api-docs] Swagger UI CDN failed to load.');
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
