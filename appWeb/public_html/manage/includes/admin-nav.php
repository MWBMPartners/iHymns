<?php

declare(strict_types=1);

/**
 * iHymns — Admin Navbar Component
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Shared navigation bar for all /manage/ pages. Structure matches the
 * main-site header (appWeb/public_html/index.php ≈ line 563) so admins
 * don't context-switch visually when crossing from `/` to `/manage/`:
 *   - Left: "iHymns Admin" brand with dropdown (parity with main site)
 *   - Right: [search (hidden)] [theme] [username+role] [avatar] [hamburger]
 *   - Hamburger opens an offcanvas panel listing every admin surface,
 *     each gated by the same entitlement that controls its page.
 *
 * Expected caller state:
 *   require_once __DIR__ . '/includes/auth.php';
 *   $currentUser = getCurrentUser();
 *   $activePage  = 'dashboard'; // or 'users', 'groups', ...
 *   require __DIR__ . '/includes/admin-nav.php';
 *
 * Note: as of #512, the auth.php bootstrap loads /includes/entitlements.php
 * automatically, so callers no longer need to require it separately. The
 * userHasEntitlement() function used by admin-links.php's closure is
 * available globally once auth.php is included.
 */

/* Prevent direct access */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

$_activePage = $activePage ?? '';
$_role       = $currentUser['role'] ?? null;
$_displayName = $currentUser['display_name'] ?? $currentUser['username'] ?? 'admin';
$_username    = $currentUser['username'] ?? '';
/* Header shows just the first word of the display name — keeps the bar
   compact for users with long full names. The dropdown still renders
   the full name so identity isn't lost. */
$_headerName  = preg_split('/\s+/', trim($_displayName), 2)[0] ?: $_displayName;
$_roleBadge   = match($_role) {
    'global_admin' => ['bg-danger',             'Global Admin'],
    'admin'        => ['bg-warning text-dark',  'Admin'],
    'editor'       => ['bg-primary',            'Curator / Editor'],
    default        => ['bg-secondary',          'User'],
};

/* Admin-surface link registry lives in admin-links.php so the sidebar
   (#460, lg+) and the hamburger offcanvas (< lg) iterate the same
   source and stay in lock-step. `visibleAdminLinks()` applies the
   per-link entitlement gate for the current role. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin-links.php';
$_visibleAdminLinks = visibleAdminLinks($_role);

?>
<header class="app-header navbar-admin" role="banner">
    <nav class="navbar navbar-expand" aria-label="Admin navigation">
        <div class="container-fluid px-3">

            <!-- ============================================================
                 LEFT — Brand with dropdown (mirrors main site header)
                 ============================================================ -->
            <div class="dropdown">
                <button type="button"
                        class="navbar-brand d-flex align-items-center gap-2 dropdown-toggle"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        aria-label="iHymns Admin navigation menu"
                        id="admin-brand-btn">
                    <i class="bi bi-music-note-beamed fs-5" aria-hidden="true"></i>
                    <span class="fw-bold">iHymns</span>
                    <span class="badge bg-warning text-dark ms-1 small">Admin</span>
                </button>
                <ul class="dropdown-menu" aria-labelledby="admin-brand-btn">
                    <li><a class="dropdown-item" href="/manage/">
                        <i class="bi bi-speedometer2 me-2" aria-hidden="true"></i>Dashboard
                    </a></li>
                    <li><a class="dropdown-item" href="/">
                        <i class="bi bi-house me-2" aria-hidden="true"></i>Home (Main site)
                    </a></li>
                    <li><a class="dropdown-item" href="/help" target="_blank" rel="noopener">
                        <i class="bi bi-question-circle me-2" aria-hidden="true"></i>Help
                    </a></li>
                </ul>
            </div>

            <!-- ============================================================
                 RIGHT — Search (hidden) · Theme · Name+role · Avatar · Burger
                 ============================================================ -->
            <div class="d-flex align-items-center gap-2 ms-auto">

                <!-- Search — layout slot reserved, hidden until admin
                     search is wired up. Keeping the button in the DOM
                     means enabling it later doesn't reflow the bar. -->
                <button type="button"
                        class="btn btn-header-icon invisible"
                        id="admin-search-btn"
                        aria-hidden="true"
                        tabindex="-1"
                        title="Admin search (coming soon)">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </button>

                <!-- Theme toggle — same shape as main site but admin is
                     currently always dark. Offered anyway so admins can
                     temporarily switch for screenshots / demos. -->
                <div class="dropdown">
                    <button type="button"
                            class="btn btn-header-icon dropdown-toggle"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Theme"
                            id="admin-theme-btn">
                        <i class="bi bi-circle-half" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="admin-theme-btn">
                        <li><button type="button" class="dropdown-item" data-bs-theme-value="light">
                            <i class="bi bi-sun me-2" aria-hidden="true"></i>Light
                        </button></li>
                        <li><button type="button" class="dropdown-item" data-bs-theme-value="dark">
                            <i class="bi bi-moon me-2" aria-hidden="true"></i>Dark
                        </button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><button type="button" class="dropdown-item" data-bs-theme-value="auto">
                            <i class="bi bi-laptop me-2" aria-hidden="true"></i>System
                        </button></li>
                    </ul>
                </div>

                <!-- Account dropdown — avatar on xs, avatar + username +
                     role badge on sm+. A single Bootstrap dropdown (so
                     the toggle is the one element Bootstrap wires), the
                     inline text is rendered as a visual affordance
                     inside the same button; no separate toggle, no
                     custom data-bs-target (which doesn't apply to
                     dropdowns). -->
                <div class="dropdown" id="admin-user-dropdown">
                    <button type="button"
                            class="btn btn-sm btn-header-icon admin-account-btn d-flex align-items-center gap-2"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Account menu"
                            id="admin-user-btn">
                        <i class="bi bi-person-circle" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline text-nowrap"><?= htmlspecialchars($_headerName) ?></span>
                        <span class="badge <?= $_roleBadge[0] ?> d-none d-sm-inline"
                              style="font-size: 0.65rem;">
                            <?= htmlspecialchars($_roleBadge[1]) ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end"
                        aria-labelledby="admin-user-btn"
                        id="admin-user-dropdown-menu">
                        <li class="dropdown-item-text small">
                            <div class="fw-semibold"><?= htmlspecialchars($_displayName) ?></div>
                            <?php if ($_username && $_username !== $_displayName): ?>
                                <div class="text-muted small">@<?= htmlspecialchars($_username) ?></div>
                            <?php endif; ?>
                            <span class="badge <?= $_roleBadge[0] ?> mt-1" style="font-size: 0.65rem;">
                                <?= htmlspecialchars($_roleBadge[1]) ?>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/settings#tab-profile" target="_blank" rel="noopener">
                            <i class="bi bi-person me-2" aria-hidden="true"></i>Profile &amp; settings
                        </a></li>
                        <li><a class="dropdown-item" href="/">
                            <i class="bi bi-house me-2" aria-hidden="true"></i>Back to main site
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="/manage/logout">
                            <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Log out
                        </a></li>
                    </ul>
                </div>

                <!-- Hamburger — opens the offcanvas surface nav. Hidden
                     at lg+ where the pinned sidebar (#460) takes over. -->
                <button type="button"
                        class="btn btn-header-icon d-lg-none"
                        data-bs-toggle="offcanvas"
                        data-bs-target="#admin-nav-offcanvas"
                        aria-controls="admin-nav-offcanvas"
                        aria-label="Admin surfaces menu"
                        title="All admin surfaces">
                    <i class="bi bi-list fs-5" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </nav>
</header>

<!-- ================================================================
     OFFCANVAS — Every admin surface, each entitlement-gated.
     Rendered once per page so the hamburger has a consistent list
     regardless of which page the user is on.
     ================================================================ -->
<div class="offcanvas offcanvas-end"
     tabindex="-1"
     id="admin-nav-offcanvas"
     aria-labelledby="admin-nav-offcanvas-label">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="admin-nav-offcanvas-label">
            <i class="bi bi-grid-3x3-gap me-2" aria-hidden="true"></i>Admin surfaces
        </h5>
        <button type="button"
                class="btn-close btn-close-white"
                data-bs-dismiss="offcanvas"
                aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
        <nav class="list-group list-group-flush" aria-label="Admin sections">
            <?php foreach ($_visibleAdminLinks as $l): ?>
                <?php [$id, $href, $icon, $label] = $l; ?>
                <a href="<?= htmlspecialchars($href) ?>"
                   class="list-group-item list-group-item-action d-flex align-items-center gap-2<?= $_activePage === $id ? ' active' : '' ?>"
                   <?= $_activePage === $id ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="offcanvas-footer border-top small text-muted p-3">
        Each item is shown only when your role holds the entitlement that controls it.
    </div>
</div>

<?php
/* Open the sidebar + main flex wrapper for #460. `admin-footer.php`
   closes it again. A GLOBALS flag lets the footer know the wrapper
   was actually opened — login.php / setup.php / editor/index.php
   include the footer without first including this nav, so the
   footer must not close containers that were never opened. */
$GLOBALS['_adminLayoutOpen'] = true;
?>
<div class="admin-layout">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'admin-sidebar.php'; ?>
    <main class="admin-main" role="main">
