<?php

declare(strict_types=1);

/**
 * iHymns — Admin Navbar Component
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Shared navigation bar for all /manage/ pages. Shows links
 * appropriate to the current user's role.
 *
 * USAGE:
 *   $currentUser = getCurrentUser();
 *   $activePage  = 'dashboard'; // 'dashboard', 'editor', 'users'
 *   require __DIR__ . '/includes/admin-nav.php';
 */

/* Prevent direct access */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}
?>
<nav class="navbar-admin d-flex align-items-center flex-wrap gap-2">
    <a href="/manage/" class="text-decoration-none me-auto d-flex align-items-center gap-2" style="color: var(--ih-amber);">
        <i class="bi bi-music-note-beamed"></i>
        <strong>iHymns Admin</strong>
    </a>

    <a href="/manage/" class="nav-link d-inline <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>">
        <i class="bi bi-speedometer2 me-1"></i>Dashboard
    </a>

    <?php if (hasRole($currentUser['role'], 'editor')): ?>
    <a href="/manage/editor/" class="nav-link d-inline <?= ($activePage ?? '') === 'editor' ? 'active' : '' ?>">
        <i class="bi bi-pencil-square me-1"></i>Editor
    </a>
    <?php endif; ?>

    <?php if (hasRole($currentUser['role'], 'admin')): ?>
    <a href="/manage/users" class="nav-link d-inline <?= ($activePage ?? '') === 'users' ? 'active' : '' ?>">
        <i class="bi bi-people me-1"></i>Users
    </a>
    <?php endif; ?>

    <span class="text-muted mx-1 d-none d-sm-inline">|</span>
    <span class="text-muted small d-none d-sm-inline">
        <?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username']) ?>
        <span class="badge ms-1 <?= match($currentUser['role']) {
            'global_admin' => 'bg-danger',
            'admin'        => 'bg-warning text-dark',
            'editor'       => 'bg-primary',
            default        => 'bg-secondary',
        } ?>" style="font-size: 0.65rem;">
            <?= htmlspecialchars(roleLabel($currentUser['role'])) ?>
        </span>
    </span>
    <a href="/manage/logout" class="btn btn-sm btn-outline-secondary ms-1">
        <i class="bi bi-box-arrow-right me-1"></i>Logout
    </a>
</nav>
