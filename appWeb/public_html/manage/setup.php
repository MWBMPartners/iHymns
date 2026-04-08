<?php

declare(strict_types=1);

/**
 * iHymns — Initial Admin Setup (#229)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * First-run setup page that creates the initial admin account.
 * Automatically disabled once at least one user exists.
 */

require_once __DIR__ . '/includes/auth.php';

/* If users already exist, setup is disabled — redirect to login */
if (!needsSetup()) {
    header('Location: /manage/login');
    exit;
}

/* Handle form submission */
$error   = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token       = $_POST['csrf_token'] ?? '';
    $username    = $_POST['username'] ?? '';
    $displayName = $_POST['display_name'] ?? '';
    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['password_confirm'] ?? '';

    if (!validateCsrf($token)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif (strlen(trim($username)) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            createUser($username, $password, $displayName ?: $username, 'admin');
            $success = true;
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — iHymns Admin</title>
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
        .setup-card {
            background: var(--ih-surface);
            border: 1px solid var(--ih-border);
            border-radius: 12px;
            width: 100%;
            max-width: 450px;
            padding: 2rem;
        }
        .setup-card h1 {
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
    <div class="setup-card">
        <div class="text-center mb-4">
            <h1><i class="bi bi-music-note-beamed me-2"></i>iHymns</h1>
            <p class="text-muted mb-0">Create your admin account</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success" role="alert">
                <i class="bi bi-check-circle me-1"></i>Admin account created successfully.
            </div>
            <a href="/manage/login" class="btn btn-amber w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i>Go to Login
            </a>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2" role="alert">
                    <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                <div class="mb-3">
                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control"
                           id="username"
                           name="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           minlength="3"
                           autofocus
                           required>
                    <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                        Minimum 3 characters. Will be lowercased automatically.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="display_name" class="form-label">Display Name</label>
                    <input type="text"
                           class="form-control"
                           id="display_name"
                           name="display_name"
                           value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
                           placeholder="Optional — defaults to username">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password"
                           class="form-control"
                           id="password"
                           name="password"
                           minlength="8"
                           required>
                    <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                        Minimum 8 characters.
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password_confirm" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password"
                           class="form-control"
                           id="password_confirm"
                           name="password_confirm"
                           minlength="8"
                           required>
                </div>

                <button type="submit" class="btn btn-amber w-100">
                    <i class="bi bi-shield-lock me-1"></i>Create Admin Account
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
