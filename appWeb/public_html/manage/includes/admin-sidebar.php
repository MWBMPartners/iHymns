<?php

declare(strict_types=1);

/**
 * iHymns — Shared Admin Sidebar (#460)
 *
 * Pinned-left navigation visible at lg+ viewports; hidden below lg
 * by the `d-none d-lg-flex` utility classes, where the hamburger
 * offcanvas rendered by admin-nav.php takes over instead.
 *
 * Iterates `visibleAdminLinks()` from admin-links.php so the
 * destinations, entitlement gates and active-state highlighting stay
 * in lock-step with the offcanvas.
 *
 * Expects the caller (admin-nav.php) to have already:
 *   - required admin-links.php (so visibleAdminLinks() is defined)
 *   - set $currentUser + $activePage in the surrounding scope
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

$_sidebarActive = $activePage ?? '';
$_sidebarRole   = $currentUser['role'] ?? null;
$_sidebarLinks  = visibleAdminLinks($_sidebarRole);

/* Collect into { groupHeading => [link, …] } while preserving the
   registry's declaration order so groups surface in a stable sequence
   (top-level → Content → People → Operations). */
$_sidebarGrouped = [];
foreach ($_sidebarLinks as $l) {
    $_sidebarGrouped[$l[5] ?? ''][] = $l;
}

?>
<aside class="admin-sidebar d-none d-lg-flex flex-column" aria-label="Admin sections">
    <nav class="admin-sidebar-nav flex-grow-1" aria-label="Primary">
        <?php foreach ($_sidebarGrouped as $group => $links): ?>
            <?php if ($group !== ''): ?>
                <div class="admin-sidebar-group-label text-uppercase small text-muted px-3 pt-3 pb-1">
                    <?= htmlspecialchars((string)$group) ?>
                </div>
            <?php endif; ?>
            <?php foreach ($links as $l): ?>
                <?php [$id, $href, $icon, $label, $entitlement] = $l; ?>
                <a href="<?= htmlspecialchars($href) ?>"
                   class="admin-sidebar-link d-flex align-items-center gap-2 px-3 py-2<?= $_sidebarActive === $id ? ' active' : '' ?>"
                   <?= $_sidebarActive === $id ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= htmlspecialchars($icon) ?> fs-6" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($label) ?><?= entitlementLockChipHtml($entitlement) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
</aside>
