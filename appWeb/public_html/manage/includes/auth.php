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

/* =========================================================================
 * ROLE HIERARCHY
 *
 * Levels (highest to lowest):
 *   global_admin — Auto-assigned to first registered user; full powers
 *   admin        — Can manage users (assign roles up to 'admin')
 *   editor       — Curator/editor: can edit songs via /manage/editor/
 *   user         — Public user: can sync setlists across devices
 *
 * Each role inherits the capabilities of all roles below it.
 * ========================================================================= */

/** @var array<string,int> Role name → numeric level (higher = more privileged) */
const ROLE_LEVELS = [
    'user'         => 1,
    'editor'       => 2,
    'admin'        => 3,
    'global_admin' => 4,
];

/**
 * All valid role names in display order (lowest to highest).
 * @return string[]
 */
function allRoles(): array
{
    return ['user', 'editor', 'admin', 'global_admin'];
}

/**
 * Get the numeric privilege level for a role.
 *
 * @param string $role Role name
 * @return int Level (0 if unknown)
 */
function roleLevel(string $role): int
{
    return ROLE_LEVELS[$role] ?? 0;
}

/**
 * Human-readable label for a role.
 *
 * @param string $role Role name
 * @return string Display label
 */
function roleLabel(string $role): string
{
    return match ($role) {
        'global_admin' => 'Global Admin',
        'admin'        => 'Admin',
        'editor'       => 'Curator / Editor',
        'user'         => 'User',
        default        => ucfirst($role),
    };
}

/**
 * Check whether a user's role meets or exceeds a minimum required role.
 *
 * @param string $userRole    The user's actual role
 * @param string $requiredRole The minimum role needed
 * @return bool
 */
function hasRole(string $userRole, string $requiredRole): bool
{
    return roleLevel($userRole) >= roleLevel($requiredRole);
}

/**
 * Require admin role (admin or global_admin) — returns 403 otherwise.
 */
function requireAdmin(): void
{
    requireAuth();
    $user = getCurrentUser();
    if ($user === null || !hasRole($user['role'], 'admin')) {
        http_response_code(403);
        exit('Access denied. Admin role required.');
    }
}

/**
 * Require editor role (editor, admin, or global_admin) — returns 403 otherwise.
 */
function requireEditor(): void
{
    requireAuth();
    $user = getCurrentUser();
    if ($user === null || !hasRole($user['role'], 'editor')) {
        http_response_code(403);
        exit('Access denied. Curator/Editor role required.');
    }
}

/**
 * Require global admin — returns 403 for all other roles.
 */
function requireGlobalAdmin(): void
{
    requireAuth();
    $user = getCurrentUser();
    if ($user === null || $user['role'] !== 'global_admin') {
        http_response_code(403);
        exit('Access denied. Global Admin role required.');
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
 * @param string $role         User role ('global_admin', 'admin', 'editor', or 'user')
 * @param string $email        Optional email address (for password resets)
 * @return int The new user's ID
 * @throws RuntimeException If the username already exists
 */
function createUser(string $username, string $password, string $displayName, string $role = 'editor', string $email = ''): int
{
    $db = getDb();
    $username = strtolower(trim($username));

    /* Validate role */
    if (!isset(ROLE_LEVELS[$role])) {
        throw new \RuntimeException('Invalid role: ' . $role);
    }

    /* Check for duplicate */
    $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new \RuntimeException('Username already exists.');
    }

    $stmt = $db->prepare('INSERT INTO users (username, password_hash, display_name, role, email) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $username,
        password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        trim($displayName),
        $role,
        trim($email),
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Update a user's role. Enforces hierarchy rules:
 *   - Only global_admin can assign global_admin
 *   - Admin can assign up to 'admin' (not global_admin)
 *   - Cannot demote a global_admin unless you are also global_admin
 *
 * @param int    $userId      Target user ID
 * @param string $newRole     New role to assign
 * @param array  $actingUser  The user performing the change
 * @return bool True on success
 * @throws RuntimeException On permission violation
 */
function updateUserRole(int $userId, string $newRole, array $actingUser): bool
{
    if (!isset(ROLE_LEVELS[$newRole])) {
        throw new \RuntimeException('Invalid role: ' . $newRole);
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT id, role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) throw new \RuntimeException('User not found.');

    $actingLevel = roleLevel($actingUser['role']);
    $targetLevel = roleLevel($target['role']);
    $newLevel    = roleLevel($newRole);

    /* Cannot promote above your own level */
    if ($newLevel > $actingLevel) {
        throw new \RuntimeException('Cannot assign a role higher than your own.');
    }

    /* Cannot demote someone at or above your level (unless you are global_admin) */
    if ($targetLevel >= $actingLevel && $actingUser['role'] !== 'global_admin') {
        throw new \RuntimeException('Cannot modify a user at or above your role level.');
    }

    /* Only global_admin can assign global_admin */
    if ($newRole === 'global_admin' && $actingUser['role'] !== 'global_admin') {
        throw new \RuntimeException('Only Global Admin can assign Global Admin role.');
    }

    $stmt = $db->prepare('UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$newRole, $userId]);
    return true;
}

/* =========================================================================
 * PASSWORD RESET
 * ========================================================================= */

/**
 * Generate a password reset token for a user.
 * The token is valid for 1 hour.
 *
 * @param string $usernameOrEmail Username or email to look up
 * @return array|null { token, user_id, username, email } or null if not found
 */
function generatePasswordResetToken(string $usernameOrEmail): ?array
{
    $db = getDb();
    $input = strtolower(trim($usernameOrEmail));

    /* Look up by username or email */
    $stmt = $db->prepare('SELECT id, username, email FROM users WHERE (username = ? OR email = ?) AND is_active = 1');
    $stmt->execute([$input, $input]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return null;

    /* Generate a secure token (48 chars hex = 24 random bytes) */
    $token = bin2hex(random_bytes(24));
    $expiresAt = gmdate('c', time() + 3600); /* 1 hour */

    /* Invalidate any existing tokens for this user */
    $stmt = $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
    $stmt->execute([$user['id']]);

    /* Insert new token */
    $stmt = $db->prepare('INSERT INTO password_reset_tokens (token, user_id, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$token, $user['id'], $expiresAt]);

    return [
        'token'    => $token,
        'user_id'  => (int)$user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
    ];
}

/**
 * Validate a password reset token and return the associated user.
 *
 * @param string $token The reset token
 * @return array|null User data { id, username } or null if invalid/expired
 */
function validatePasswordResetToken(string $token): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT t.user_id, u.username
         FROM password_reset_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > ? AND t.used = 0 AND u.is_active = 1'
    );
    $stmt->execute([$token, gmdate('c')]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Reset a user's password using a valid reset token.
 *
 * @param string $token       The reset token
 * @param string $newPassword The new plaintext password
 * @return bool True on success
 */
function resetPassword(string $token, string $newPassword): bool
{
    $tokenData = validatePasswordResetToken($token);
    if (!$tokenData) return false;

    $db = getDb();

    /* Update password */
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare('UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$hash, $tokenData['user_id']]);

    /* Mark token as used */
    $stmt = $db->prepare('UPDATE password_reset_tokens SET used = 1 WHERE token = ?');
    $stmt->execute([$token]);

    /* Invalidate all API tokens for this user (force re-login) */
    $stmt = $db->prepare('DELETE FROM api_tokens WHERE user_id = ?');
    $stmt->execute([$tokenData['user_id']]);

    return true;
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
