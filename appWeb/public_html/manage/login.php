<?php

declare(strict_types=1);

/**
 * iHymns — Admin Login Page (#229)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

/* Redirect to setup if no users exist */
if (needsSetup()) {
    header('Location: /manage/setup');
    exit;
}

/* Already logged in? Go to the Dashboard (#693). The post-login
   default used to be /manage/editor/, but that surprised curators who
   clicked "Manage" from the brand dropdown expecting the admin
   Dashboard — they kept landing on the Song Editor on first visit
   (when no $_SESSION['redirect_after_login'] was queued by an upstream
   requireAuth bounce). The Dashboard is the correct landing surface
   for "Manage", and curators who specifically want the editor have
   the Song Editor card a single click away. */
if (isAuthenticated()) {
    $redirect = $_SESSION['redirect_after_login'] ?? '/manage/';
    unset($_SESSION['redirect_after_login']);
    header('Location: ' . $redirect);
    exit;
}

/* Idle-timeout banner: requireAuth() drops a `login_notice` into
   $_SESSION when it kicks an idle session out (#531). Surface it
   above the form once, then clear so a refresh doesn't re-show. */
$notice = $_SESSION['login_notice'] ?? '';
if ($notice !== '') {
    unset($_SESSION['login_notice']);
}

/* Handle login form submission */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = $_POST['csrf_token'] ?? '';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!validateCsrf($token)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $user = attemptLogin($username, $password);
        if ($user) {
            /* Default to /manage/ (Dashboard) — see #693 explanation
               above the matching block at the top of this file. */
            $redirect = $_SESSION['redirect_after_login'] ?? '/manage/';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect);
            exit;
        }
        $error = 'Invalid username or password.';
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <!-- Shared iHymns palette + admin styles -->
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body class="auth-center-page">
    <div class="login-card">
        <div class="text-center mb-4">
            <h1><i class="bi bi-music-note-beamed me-2"></i>iHymns</h1>
            <p class="text-muted mb-0">Admin Login</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2" role="alert">
                <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php elseif ($notice !== ''): ?>
            <div class="alert alert-warning py-2" role="status">
                <i class="bi bi-clock-history me-1"></i><?= htmlspecialchars($notice) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text"
                       class="form-control"
                       id="username"
                       name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username"
                       autofocus
                       required>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password"
                       class="form-control"
                       id="password"
                       name="password"
                       autocomplete="current-password"
                       required>
            </div>

            <button type="submit" class="btn btn-amber w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
            </button>
        </form>
    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
