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
    <style>
        :root {
            --ih-bg: #1a1a2e;
            --ih-surface: #16213e;
            --ih-amber: #f59e0b;
            --ih-amber-hover: #d97706;
            --ih-text: #e2e8f0;
            --ih-text-muted: #94a3b8;
            --ih-border: #334155;
        }
        body {
            background: var(--ih-bg);
            color: var(--ih-text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: var(--ih-surface);
            border: 1px solid var(--ih-border);
            border-radius: 12px;
            width: 100%;
            max-width: 400px;
            padding: 2rem;
        }
        .login-card h1 {
            font-size: 1.5rem;
            color: var(--ih-amber);
        }
        .btn-amber {
            background: var(--ih-amber);
            border-color: var(--ih-amber);
            color: #1a1a2e;
            font-weight: 600;
        }
        .btn-amber:hover {
            background: var(--ih-amber-hover);
            border-color: var(--ih-amber-hover);
            color: #1a1a2e;
        }
        .form-control:focus {
            border-color: var(--ih-amber);
            box-shadow: 0 0 0 0.2rem rgba(245, 158, 11, 0.25);
        }
    </style>
</head>
<body>
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
</body>
</html>
