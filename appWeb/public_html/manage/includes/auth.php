<?php

declare(strict_types=1);

/**
 * iHymns — Authentication Middleware
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Session-based authentication for the /manage/ admin area.
 * Provides login, logout, session validation, and user management.
 *
 * USAGE:
 *   require_once __DIR__ . '/includes/auth.php';
 *   requireAuth();  // Redirects to login if not authenticated
 *
 * @requires PHP 8.5+
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/db.php';

/* =========================================================================
 * SESSION CONFIGURATION
 * ========================================================================= */

/** Session lifetime in seconds (24 hours) */
define('SESSION_LIFETIME', 86400);

/** Cookie name for the session */
define('SESSION_COOKIE', 'ihymns_manage_session');

/* =========================================================================
 * AUTHENTICATION FUNCTIONS
 * ========================================================================= */

/**
 * Start the PHP session if not already active.
 */
function initSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/manage/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly'  => true,
            'samesite'  => 'Strict',
        ]);
        session_name(SESSION_COOKIE);
        session_start();
    }
}

/**
 * Check whether a user is currently logged in.
 *
 * @return bool True if authenticated
 */
function isAuthenticated(): bool
{
    initSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

/**
 * Get the currently authenticated user, or null.
 *
 * @return array|null User row from database, or null if not logged in
 */
function getCurrentUser(): ?array
{
    if (!isAuthenticated()) {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT id, username, display_name, role FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Require authentication — redirect to login page if not logged in.
 * Call this at the top of any protected page.
 */
function requireAuth(): void
{
    /* If no users exist yet, redirect to setup */
    if (needsSetup()) {
        header('Location: /manage/setup');
        exit;
    }

    if (!isAuthenticated()) {
        /* Store the requested URL so we can redirect back after login */
        initSession();
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/manage/';
        header('Location: /manage/login');
        exit;
    }

    /* Verify the user still exists and is active */
    $user = getCurrentUser();
    if ($user === null) {
        logout();
        header('Location: /manage/login');
        exit;
    }
}

/**
 * Require admin role — returns 403 if the user is not an admin.
 */
function requireAdmin(): void
{
    requireAuth();
    $user = getCurrentUser();
    if ($user === null || $user['role'] !== 'admin') {
        http_response_code(403);
        exit('Access denied. Admin role required.');
    }
}

/**
 * Attempt to log in with the given credentials.
 *
 * @param string $username The username
 * @param string $password The plaintext password
 * @return array|null The user row on success, null on failure
 */
function attemptLogin(string $username, string $password): ?array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([strtolower(trim($username))]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    /* Set session */
    initSession();
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['username'] = $user['username'];

    return $user;
}

/**
 * Log out the current user and destroy the session.
 */
function logout(): void
{
    initSession();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Check whether the system needs initial setup (no users exist yet).
 *
 * @return bool True if no users exist and setup is required
 */
function needsSetup(): bool
{
    try {
        $db = getDb();
        $stmt = $db->query('SELECT COUNT(*) FROM users');
        return (int)$stmt->fetchColumn() === 0;
    } catch (\Exception $e) {
        /* Table might not exist yet — needs setup */
        return true;
    }
}

/**
 * Create a new user account.
 *
 * @param string $username     Login username (will be lowercased)
 * @param string $password     Plaintext password (will be hashed)
 * @param string $displayName  Display name for the UI
 * @param string $role         User role ('admin' or 'editor')
 * @return int The new user's ID
 * @throws RuntimeException If the username already exists
 */
function createUser(string $username, string $password, string $displayName, string $role = 'editor'): int
{
    $db = getDb();
    $username = strtolower(trim($username));

    /* Check for duplicate */
    $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new \RuntimeException('Username already exists.');
    }

    $stmt = $db->prepare('INSERT INTO users (username, password_hash, display_name, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $username,
        password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        trim($displayName),
        $role,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Generate a CSRF token and store it in the session.
 *
 * @return string The CSRF token
 */
function csrfToken(): string
{
    initSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token against the session.
 *
 * @param string $token The submitted token
 * @return bool True if valid
 */
function validateCsrf(string $token): bool
{
    initSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
