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

/* Already logged in? Go to editor */
if (isAuthenticated()) {
    $redirect = $_SESSION['redirect_after_login'] ?? '/manage/editor/';
    unset($_SESSION['redirect_after_login']);
    header('Location: ' . $redirect);
    exit;
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
            $redirect = $_SESSION['redirect_after_login'] ?? '/manage/editor/';
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
          crossorigin="anonymous">
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
