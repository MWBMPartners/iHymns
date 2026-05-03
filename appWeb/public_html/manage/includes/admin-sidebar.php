<?php

declare(strict_types=1);

/**
 * iHymns — Shared Admin Sidebar (#460, accordion-fied #819)
 *
 * Pinned-left navigation visible at lg+ viewports; hidden below lg
 * by the `d-none d-lg-flex` utility classes, where the hamburger
 * offcanvas rendered by admin-nav.php takes over instead.
 *
 * Iterates `visibleAdminLinks()` from admin-links.php so the
 * destinations, entitlement gates and active-state highlighting stay
 * in lock-step with the offcanvas. Renders each group as a
 * collapsible accordion section — only the group containing the
 * active page is expanded by default; the rest stay tucked away to
 * keep the long admin nav scannable. Per-group open/closed state
 * persists in localStorage between navigations.
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
   (Dashboard → Songs → Catalogue → Access → People → Operations → Help). */
$_sidebarGrouped = [];
foreach ($_sidebarLinks as $l) {
    $_sidebarGrouped[$l[5] ?? ''][] = $l;
}

/* Identify the group containing the active page so the accordion
   renderer can force-expand it on initial render, regardless of any
   stored localStorage preference. */
$_sidebarActiveGroup = '';
foreach ($_sidebarLinks as $l) {
    if (($l[0] ?? null) === $_sidebarActive) {
        $_sidebarActiveGroup = (string)($l[5] ?? '');
        break;
    }
}

/* Build a stable, slug-safe id for each group so
   data-bs-target / aria-controls + JS persistence agree. */
$_sidebarGroupSlug = static function (string $g): string {
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $g));
    return 'admin-nav-grp-' . trim($slug, '-');
};

?>
<aside class="admin-sidebar d-none d-lg-flex flex-column" aria-label="Admin sections">
    <nav class="admin-sidebar-nav flex-grow-1" aria-label="Primary"
         data-admin-accordion="sidebar">
        <?php foreach ($_sidebarGrouped as $group => $links): ?>
            <?php if ($group === ''): ?>
                <?php /* Top-level (Dashboard) — render flat, no accordion. */ ?>
                <?php foreach ($links as $l): ?>
                    <?php [$id, $href, $icon, $label, $entitlement] = $l; ?>
                    <a href="<?= htmlspecialchars($href) ?>"
                       class="admin-sidebar-link d-flex align-items-center gap-2 px-3 py-2<?= $_sidebarActive === $id ? ' active' : '' ?>"
                       <?= $_sidebarActive === $id ? 'aria-current="page"' : '' ?>>
                        <i class="bi <?= htmlspecialchars($icon) ?> fs-6" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($label) ?><?= entitlementLockChipHtml($entitlement) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php continue; ?>
            <?php endif; ?>
            <?php
                $_isActiveGroup = ($group === $_sidebarActiveGroup);
                $_groupId       = $_sidebarGroupSlug($group);
            ?>
            <div class="admin-sidebar-group" data-group="<?= htmlspecialchars((string)$group) ?>">
                <button type="button"
                        class="admin-sidebar-group-toggle btn btn-link w-100 d-flex align-items-center justify-content-between text-uppercase small fw-semibold px-3 pt-3 pb-1<?= $_isActiveGroup ? '' : ' collapsed' ?>"
                        data-bs-toggle="collapse"
                        data-bs-target="#<?= htmlspecialchars($_groupId) ?>"
                        aria-expanded="<?= $_isActiveGroup ? 'true' : 'false' ?>"
                        aria-controls="<?= htmlspecialchars($_groupId) ?>">
                    <span class="text-muted"><?= htmlspecialchars((string)$group) ?></span>
                    <i class="bi bi-chevron-down ms-2 small admin-sidebar-group-chev" aria-hidden="true"></i>
                </button>
                <div id="<?= htmlspecialchars($_groupId) ?>"
                     class="admin-sidebar-group-body collapse<?= $_isActiveGroup ? ' show' : '' ?>">
                    <?php foreach ($links as $l): ?>
                        <?php [$id, $href, $icon, $label, $entitlement] = $l; ?>
                        <a href="<?= htmlspecialchars($href) ?>"
                           class="admin-sidebar-link d-flex align-items-center gap-2 px-3 py-2<?= $_sidebarActive === $id ? ' active' : '' ?>"
                           <?= $_sidebarActive === $id ? 'aria-current="page"' : '' ?>>
                            <i class="bi <?= htmlspecialchars($icon) ?> fs-6" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($label) ?><?= entitlementLockChipHtml($entitlement) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>

<script>
/* Sidebar accordion persistence (#819). On any group toggle, store
   the open/closed state under "ihymns-admin-nav-<group>". On every
   page load, restore stored state EXCEPT for the active group which
   is always forced open via PHP-rendered `.show` so the user can
   see their position. Failure to access localStorage is silent —
   defaults work without persistence. */
(() => {
    const KEY_PREFIX = 'ihymns-admin-nav-';

    /* Apply stored open/closed to non-active groups on initial load. */
    document.querySelectorAll('[data-admin-accordion] [data-group]').forEach((el) => {
        const group = el.getAttribute('data-group') || '';
        const body  = el.querySelector('.admin-sidebar-group-body, .offcanvas-group-body');
        const btn   = el.querySelector('[data-bs-toggle="collapse"]');
        if (!body || !btn) return;
        /* Active group's `.show` is rendered server-side; don't fight it. */
        if (body.classList.contains('show') && body.dataset.activeForced === '1') return;
        try {
            const stored = localStorage.getItem(KEY_PREFIX + group);
            if (stored === 'open') {
                body.classList.add('show');
                btn.setAttribute('aria-expanded', 'true');
                btn.classList.remove('collapsed');
            } else if (stored === 'closed') {
                body.classList.remove('show');
                btn.setAttribute('aria-expanded', 'false');
                btn.classList.add('collapsed');
            }
        } catch (_e) { /* localStorage blocked — accept defaults */ }
    });

    /* Persist on toggle. Bootstrap fires `shown.bs.collapse` /
       `hidden.bs.collapse` on the collapse target. */
    document.querySelectorAll('[data-admin-accordion] [data-group]').forEach((el) => {
        const group = el.getAttribute('data-group') || '';
        const body  = el.querySelector('.admin-sidebar-group-body, .offcanvas-group-body');
        if (!body) return;
        body.addEventListener('shown.bs.collapse', () => {
            try { localStorage.setItem(KEY_PREFIX + group, 'open'); } catch (_e) {}
        });
        body.addEventListener('hidden.bs.collapse', () => {
            try { localStorage.setItem(KEY_PREFIX + group, 'closed'); } catch (_e) {}
        });
    });
})();
</script>
