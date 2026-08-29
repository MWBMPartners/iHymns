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
/* userId drives the optional per-row 'org_admin' sentinel (#1667) so an org
   admin sees the Service Mode broadcaster links even without manage_organisations. */
$_adminNavUserId    = (int)($currentUser['id'] ?? $currentUser['Id'] ?? 0);
$_visibleAdminLinks = visibleAdminLinks($_role, $_adminNavUserId);

/* Data-driven hide for `my-organisations` + `my-ccli-report` (#707 / #1861).
   Both entitlements (`manage_own_organisation`, `view_org_ccli_report`) are
   open to every signed-in role so the role-based filter alone would surface
   both links to every user. The actual restriction for both is "user holds
   an admin or owner row in tblOrganisationMembers" — checked via
   userHasOwnOrganisation() (same underlying userIsOrgAdminOf() lookup
   my-ccli-report.php's own page gate uses, rule #22: reuse, don't reinvent).
   system_admin / global_admin keep both links unconditionally: they can
   manage any org via /manage/organisations, and see every org's CCLI usage
   via /manage/ccli-report (an org-less system admin clicking My CCLI Report
   gets the friendly pointer page — the harmless "widening" direction of
   #1587, same trade-off as my-organisations). */
if (!in_array($_role, ['admin', 'global_admin'], true)) {
    $_userIdForOrgCheck = (int)($currentUser['id'] ?? $currentUser['Id'] ?? 0);
    if (function_exists('userHasOwnOrganisation')
        && !userHasOwnOrganisation($_userIdForOrgCheck)) {
        $_visibleAdminLinks = array_values(array_filter(
            $_visibleAdminLinks,
            static fn(array $l): bool => !in_array($l[0], ['my-organisations', 'my-ccli-report'], true)
        ));
    }
}

/* Gravatar/Libravatar/DiceBear avatar URL for the signed-in user
   (#581). The dropdown header carries a 64px copy; the toggle button
   carries a 32px copy so the network/cache pays for both sizes only
   once each. Email may be missing on legacy accounts → helper falls
   back to the static SVG identicon so the markup never breaks. */
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'avatar.php';
$_userEmail      = $currentUser['email']          ?? '';
$_userAvatarSvc  = $currentUser['avatar_service'] ?? null;  /* #616 — NULL = inherit project default */
$_avatarUrlSmall = userAvatarUrl($_userEmail, 32, $_userAvatarSvc);
$_avatarUrlLarge = userAvatarUrl($_userEmail, 64, $_userAvatarSvc);

?>
<!-- Skip link (a11y audit M7, 2026-08-28) — mirrors index.php's public-site
     skip link exactly (same classes, same target id) so keyboard users get
     an identical first Tab stop on every admin page, regardless of which
     one they land on. Emitted here (the shared chrome every /manage/*.php
     page requires) rather than per-page — the admin surface never had a
     skip link at all before this. -->
<a href="#main-content"
   class="visually-hidden-focusable position-absolute top-0 start-0 p-3 bg-primary text-white z-3"
   id="skip-nav">
    Skip to main content
</a>
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
                    <span class="badge env-badge bg-warning text-dark ms-1">Admin</span>
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

                <!-- Search — layout slot reserved at md+ widths only,
                     hidden until admin search is wired up. Removed from
                     the layout (d-none) on phone-portrait widths so the
                     reserved slot doesn't push the hamburger off-screen
                     on iPhones (the user reported the burger sliding
                     past the right edge). -->
                <button type="button"
                        class="btn btn-header-icon invisible d-none d-md-inline-flex"
                        id="admin-search-btn"
                        aria-hidden="true"
                        tabindex="-1"
                        title="Admin search (coming soon)">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </button>

                <!-- Theme toggle — picks Light / Dark / System and persists
                     to localStorage.ihymns_theme so the choice survives
                     page reloads and is shared with the public site
                     (#955). For high-contrast / CVD modes, users go to
                     the public-site Settings page; admin pages still
                     pick those preferences up via admin-theme-init.php. -->
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
                <script>
                /* #955 — admin theme dropdown handler. Maps the BS-docs
                   data-bs-theme-value attribute (light/dark/auto) onto
                   the public-site storage key (ihymns_theme; with auto →
                   system) and re-applies via the helper exposed by
                   admin-theme-init.php. Idempotent — re-runs no-op. */
                (function () {
                    var KEY = 'ihymns_theme';
                    document.querySelectorAll('[data-bs-theme-value]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var v = btn.getAttribute('data-bs-theme-value');
                            var t = (v === 'auto') ? 'system' : v;
                            try { localStorage.setItem(KEY, t); } catch (_e) { /* private browsing */ }
                            if (typeof window.iHymnsAdminApplyTheme === 'function') {
                                window.iHymnsAdminApplyTheme(t);
                            }
                        });
                    });
                })();
                </script>

                <!-- Account dropdown (#579) — single circular avatar
                     button at every viewport, matching the main-app
                     header pattern. The username + role badge moved
                     INTO the dropdown body so the bar stays compact
                     on mobile and identical-looking when admins cross
                     between `/` and `/manage/`. -->
                <div class="dropdown" id="admin-user-dropdown">
                    <button type="button"
                            class="btn btn-header-icon admin-account-btn p-0"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Account menu"
                            id="admin-user-btn">
                        <img src="<?= htmlspecialchars($_avatarUrlSmall) ?>"
                             alt=""
                             width="24" height="24"
                             class="rounded-circle"
                             loading="lazy"
                             referrerpolicy="no-referrer"
                             onerror="this.onerror=null;this.src='/assets/avatar-fallback.svg';">
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end"
                        aria-labelledby="admin-user-btn"
                        id="admin-user-dropdown-menu">
                        <li class="dropdown-item-text">
                            <div class="d-flex align-items-center gap-2">
                                <img src="<?= htmlspecialchars($_avatarUrlLarge) ?>"
                                     alt=""
                                     width="40" height="40"
                                     class="rounded-circle"
                                     loading="lazy"
                                     referrerpolicy="no-referrer"
                                     onerror="this.onerror=null;this.src='/assets/avatar-fallback.svg';">
                                <div class="small">
                                    <div class="fw-semibold"><?= htmlspecialchars($_displayName) ?></div>
                                    <?php if ($_username && $_username !== $_displayName): ?>
                                        <div class="text-muted small">@<?= htmlspecialchars($_username) ?></div>
                                    <?php endif; ?>
                                    <span class="badge <?= $_roleBadge[0] ?> mt-1" style="font-size: 0.65rem;">
                                        <?= htmlspecialchars($_roleBadge[1]) ?>
                                    </span>
                                </div>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/manage/">
                            <i class="bi bi-speedometer2 me-2" aria-hidden="true"></i>Dashboard
                        </a></li>
                        <li><a class="dropdown-item" href="/">
                            <i class="bi bi-house me-2" aria-hidden="true"></i>Home (Main site)
                        </a></li>
                        <li><a class="dropdown-item" href="/manage/help">
                            <i class="bi bi-life-preserver me-2" aria-hidden="true"></i>Help &amp; Guides
                        </a></li>
                        <li><a class="dropdown-item" href="/settings#tab-profile" target="_blank" rel="noopener">
                            <i class="bi bi-person me-2" aria-hidden="true"></i>Profile &amp; settings
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
        <?php
            /* Mirror the desktop sidebar's accordion structure (#819) so
               mobile gets the same compact, collapsible groups. Same
               localStorage keys, same active-group force-expand
               behaviour — the JS in admin-sidebar.php scopes itself
               via [data-admin-accordion]. */
            $_offGrouped = [];
            foreach ($_visibleAdminLinks as $l) {
                $_offGrouped[$l[5] ?? ''][] = $l;
            }
            $_offActiveGroup = '';
            foreach ($_visibleAdminLinks as $l) {
                if (($l[0] ?? null) === $_activePage) {
                    $_offActiveGroup = (string)($l[5] ?? '');
                    break;
                }
            }
            $_offSlug = static function (string $g): string {
                $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $g));
                return 'admin-off-grp-' . trim($slug, '-');
            };
        ?>
        <nav class="admin-offcanvas-nav" aria-label="Admin sections"
             data-admin-accordion="offcanvas">
            <?php foreach ($_offGrouped as $_grp => $_links): ?>
                <?php if ($_grp === ''): ?>
                    <?php foreach ($_links as $l): ?>
                        <?php [$id, $href, $icon, $label, $entitlement] = $l; ?>
                        <a href="<?= htmlspecialchars($href) ?>"
                           class="list-group-item list-group-item-action d-flex align-items-center gap-2<?= $_activePage === $id ? ' active' : '' ?>"
                           <?= $_activePage === $id ? 'aria-current="page"' : '' ?>>
                            <i class="bi <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($label) ?><?= entitlementLockChipHtml($entitlement) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php continue; ?>
                <?php endif; ?>
                <?php
                    $_isActiveOff = ($_grp === $_offActiveGroup);
                    $_offId       = $_offSlug($_grp);
                ?>
                <div class="admin-offcanvas-group" data-group="<?= htmlspecialchars((string)$_grp) ?>">
                    <button type="button"
                            class="admin-offcanvas-group-toggle btn btn-link w-100 d-flex align-items-center justify-content-between text-uppercase small fw-semibold px-3 py-2<?= $_isActiveOff ? '' : ' collapsed' ?>"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?= htmlspecialchars($_offId) ?>"
                            aria-expanded="<?= $_isActiveOff ? 'true' : 'false' ?>"
                            aria-controls="<?= htmlspecialchars($_offId) ?>">
                        <span class="text-muted"><?= htmlspecialchars((string)$_grp) ?></span>
                        <i class="bi bi-chevron-down ms-2 small" aria-hidden="true"></i>
                    </button>
                    <div id="<?= htmlspecialchars($_offId) ?>"
                         class="offcanvas-group-body collapse<?= $_isActiveOff ? ' show' : '' ?>"
                         <?= $_isActiveOff ? 'data-active-forced="1"' : '' ?>>
                        <?php foreach ($_links as $l): ?>
                            <?php [$id, $href, $icon, $label, $entitlement] = $l; ?>
                            <a href="<?= htmlspecialchars($href) ?>"
                               class="list-group-item list-group-item-action d-flex align-items-center gap-2 ps-4<?= $_activePage === $id ? ' active' : '' ?>"
                               <?= $_activePage === $id ? 'aria-current="page"' : '' ?>>
                                <i class="bi <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i>
                                <span><?= htmlspecialchars($label) ?><?= entitlementLockChipHtml($entitlement) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
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
    <?php /* a11y audit M7 — id="main-content" is the skip link's target above;
             tabindex="-1" matches index.php's public <main> so activating the
             skip link actually MOVES keyboard focus onto this landmark, not
             just scrolls the viewport to it (a fragment link only focuses a
             target that is itself focusable). admin-footer.php closes this
             </main>, guarded by the SAME $_adminLayoutOpen flag this file
             already sets below. */ ?>
    <main id="main-content" class="admin-main" role="main" tabindex="-1">
