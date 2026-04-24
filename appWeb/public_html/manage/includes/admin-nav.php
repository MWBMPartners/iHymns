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
 *   require_once dirname(__DIR__) . '/includes/entitlements.php';
 *   $currentUser = getCurrentUser();
 *   $activePage  = 'dashboard'; // or 'users', 'groups', ...
 *   require __DIR__ . '/includes/admin-nav.php';
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
$_roleBadge   = match($_role) {
    'global_admin' => ['bg-danger',             'Global Admin'],
    'admin'        => ['bg-warning text-dark',  'Admin'],
    'editor'       => ['bg-primary',            'Curator / Editor'],
    default        => ['bg-secondary',          'User'],
};

/* Admin-surface links. Each entry:
 *   id          — matches $activePage set by the page, for highlight
 *   href        — destination
 *   icon        — bi-* class
 *   label       — menu text
 *   entitlement — required entitlement name (null → always visible)
 * Kept in one place so the offcanvas, the brand dropdown, and future
 * command-palette can all iterate the same list. */
$_adminLinks = [
    ['dashboard',    '/manage/',                'bi-speedometer2',   'Dashboard',          null],
    ['editor',       '/manage/editor/',         'bi-pencil-square',  'Song Editor',        'edit_songs'],
    ['requests',     '/manage/requests',        'bi-lightbulb',      'Song Requests',      'review_song_requests'],
    ['revisions',    '/manage/revisions',       'bi-clock-history',  'Revisions Audit',    'verify_songs'],
    ['users',        '/manage/users',           'bi-people',         'Users',              'view_users'],
    ['groups',       '/manage/groups',          'bi-people-fill',    'User Groups',        'manage_user_groups'],
    ['organisations','/manage/organisations',   'bi-building',       'Organisations',      'manage_organisations'],
    ['songbooks',    '/manage/songbooks',       'bi-book',           'Songbooks',          'manage_songbooks'],
    ['restrictions', '/manage/restrictions',    'bi-shield-lock',    'Content Restrictions','manage_content_restrictions'],
    ['tiers',        '/manage/tiers',           'bi-stars',          'Access Tiers',       'manage_access_tiers'],
    ['entitlements', '/manage/entitlements',    'bi-key',            'Entitlements',       'manage_entitlements'],
    ['analytics',    '/manage/analytics',       'bi-graph-up',       'Analytics',          'view_analytics'],
    ['data-health',  '/manage/data-health',     'bi-activity',       'Data Health',        'drop_legacy_tables'],
    ['setup-database','/manage/setup-database', 'bi-database-gear',  'Database Setup',     'run_db_install'],
];

$_visibleAdminLinks = array_values(array_filter(
    $_adminLinks,
    static fn(array $l): bool => $l[4] === null || userHasEntitlement($l[4], $_role)
));

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
                        <span class="d-none d-sm-inline text-nowrap"><?= htmlspecialchars($_displayName) ?></span>
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

                <!-- Hamburger — opens the offcanvas surface nav -->
                <button type="button"
                        class="btn btn-header-icon"
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
