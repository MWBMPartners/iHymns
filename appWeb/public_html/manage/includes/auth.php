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
 *   require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
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

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';

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
    $stmt = $db->prepare('SELECT Id, Username, DisplayName, Role FROM tblUsers WHERE Id = ? AND IsActive = 1');
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
    if ($user === null || !hasRole($user['Role'], 'admin')) {
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
    if ($user === null || !hasRole($user['Role'], 'editor')) {
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
    if ($user === null || $user['Role'] !== 'global_admin') {
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
    $stmt = $db->prepare('SELECT * FROM tblUsers WHERE Username = ? AND IsActive = 1');
    $stmt->execute([strtolower(trim($username))]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['PasswordHash'])) {
        return null;
    }

    /* Set session */
    initSession();
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$user['Id'];
    $_SESSION['username'] = $user['Username'];

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
        $stmt = $db->query('SELECT COUNT(*) FROM tblUsers');
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
    $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE Username = ?');
    $stmt->execute([$username]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new \RuntimeException('Username already exists.');
    }

    $stmt = $db->prepare('INSERT INTO tblUsers (Username, PasswordHash, DisplayName, Role, Email) VALUES (?, ?, ?, ?, ?)');
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
    $stmt = $db->prepare('SELECT Id, Role FROM tblUsers WHERE Id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) throw new \RuntimeException('User not found.');

    $actingLevel = roleLevel($actingUser['Role']);
    $targetLevel = roleLevel($target['Role']);
    $newLevel    = roleLevel($newRole);

    /* Cannot promote above your own level */
    if ($newLevel > $actingLevel) {
        throw new \RuntimeException('Cannot assign a role higher than your own.');
    }

    /* Cannot demote someone at or above your level (unless you are global_admin) */
    if ($targetLevel >= $actingLevel && $actingUser['Role'] !== 'global_admin') {
        throw new \RuntimeException('Cannot modify a user at or above your role level.');
    }

    /* Only global_admin can assign global_admin */
    if ($newRole === 'global_admin' && $actingUser['Role'] !== 'global_admin') {
        throw new \RuntimeException('Only Global Admin can assign Global Admin role.');
    }

    $stmt = $db->prepare('UPDATE tblUsers SET Role = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
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
    $stmt = $db->prepare('SELECT Id, Username, Email FROM tblUsers WHERE (Username = ? OR Email = ?) AND IsActive = 1');
    $stmt->execute([$input, $input]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return null;

    /* Generate a secure token (48 chars hex = 24 random bytes) */
    $token = bin2hex(random_bytes(24));
    $expiresAt = gmdate('c', time() + 3600); /* 1 hour */

    /* Invalidate any existing tokens for this user */
    $stmt = $db->prepare('DELETE FROM tblPasswordResetTokens WHERE UserId = ?');
    $stmt->execute([$user['Id']]);

    /* Insert new token (store SHA-256 hash; raw token is returned to the caller) */
    $hashedToken = hash('sha256', $token);
    $stmt = $db->prepare('INSERT INTO tblPasswordResetTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
    $stmt->execute([$hashedToken, $user['Id'], $expiresAt]);

    return [
        'token'    => $token,
        'user_id'  => (int)$user['Id'],
        'username' => $user['Username'],
        'email'    => $user['Email'],
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
    $hashedToken = hash('sha256', $token);
    $stmt = $db->prepare(
        'SELECT t.UserId, u.Username
         FROM tblPasswordResetTokens t
         JOIN tblUsers u ON u.Id = t.UserId
         WHERE t.Token = ? AND t.ExpiresAt > ? AND t.Used = 0 AND u.IsActive = 1'
    );
    $stmt->execute([$hashedToken, gmdate('c')]);
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
    $stmt = $db->prepare('UPDATE tblUsers SET PasswordHash = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->execute([$hash, $tokenData['UserId']]);

    /* Mark token as used */
    $hashedToken = hash('sha256', $token);
    $stmt = $db->prepare('UPDATE tblPasswordResetTokens SET Used = 1 WHERE Token = ?');
    $stmt->execute([$hashedToken]);

    /* Invalidate all API tokens for this user (force re-login) */
    $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ?');
    $stmt->execute([$tokenData['UserId']]);

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

/* =========================================================================
 * USER PROFILE MANAGEMENT
 * ========================================================================= */

/**
 * Change a user's password (by user ID).
 * Invalidates all existing API tokens for the user.
 *
 * @param int    $userId      Target user ID
 * @param string $newPassword New plaintext password
 * @return bool
 */
function changeUserPassword(int $userId, string $newPassword): bool
{
    $db = getDb();
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare('UPDATE tblUsers SET PasswordHash = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->execute([$hash, $userId]);

    /* Invalidate all API tokens (force re-login) */
    $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ?');
    $stmt->execute([$userId]);

    return true;
}

/**
 * Update a user's profile fields (display name, email).
 *
 * @param int    $userId      Target user ID
 * @param string $displayName New display name
 * @param string $email       New email address
 * @return bool
 */
function updateUserProfile(int $userId, string $displayName, string $email): bool
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE tblUsers SET DisplayName = ?, Email = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->execute([trim($displayName), trim($email), $userId]);
    return true;
}

/**
 * Activate or deactivate a user account.
 *
 * @param int  $userId   Target user ID
 * @param bool $isActive True to activate, false to deactivate
 * @return bool
 */
function setUserActive(int $userId, bool $isActive): bool
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE tblUsers SET IsActive = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->execute([$isActive ? 1 : 0, $userId]);

    /* If deactivating, invalidate all their tokens */
    if (!$isActive) {
        $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ?');
        $stmt->execute([$userId]);
    }

    return true;
}

/**
 * Delete a user account permanently.
 * Foreign key cascades will remove tokens, setlists, etc.
 *
 * @param int $userId Target user ID
 * @return bool
 */
function deleteUser(int $userId): bool
{
    $db = getDb();
    $stmt = $db->prepare('DELETE FROM tblUsers WHERE Id = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->rowCount() > 0;
}

/**
 * Get a user by ID.
 *
 * @param int $userId User ID
 * @return array|null User row or null
 */
function getUserById(int $userId): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT Id, Username, DisplayName, Email, Role, GroupId, IsActive, CreatedAt, UpdatedAt
         FROM tblUsers WHERE Id = ?'
    );
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* =========================================================================
 * EMAIL LOGIN (MAGIC LINK / CODE) — Passwordless Authentication
 *
 * Supports two verification modes:
 *   1. Magic link: email contains a URL with ?token=<48-char hex>
 *   2. Code entry: email contains a 6-digit code the user enters manually
 * Both expire after 10 minutes and are single-use.
 * ========================================================================= */

/**
 * Generate an email login token and 6-digit code for a given email address.
 *
 * If the email matches an existing user, the token is linked to that user.
 * Rate-limited: max 5 requests per email per hour.
 *
 * @param string $email    The email address to send the login link/code to
 * @param string $clientIp The requesting IP address (for rate limiting)
 * @return array{token: string, code: string, userId: int|null, isNewUser: bool}|null
 *               Null if rate-limited or email is invalid
 */
function generateEmailLoginToken(string $email, string $clientIp = ''): ?array
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $db = getDb();

    /* Rate limit: max 5 requests per email per hour */
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM tblEmailLoginTokens
         WHERE Email = ? AND CreatedAt > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $stmt->execute([$email]);
    if ((int)$stmt->fetchColumn() >= 5) {
        return null; /* Rate limited */
    }

    /* Check if user already exists with this email */
    $stmt = $db->prepare('SELECT Id FROM tblUsers WHERE Email = ? AND IsActive = 1');
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    $userId = $existingUser ? (int)$existingUser['Id'] : null;

    /* Generate token (48-char hex) and code (6-digit numeric) */
    $token = bin2hex(random_bytes(24));
    $code  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = gmdate('c', time() + 600); /* 10 minutes */

    /* Invalidate any previous unused tokens for this email */
    $stmt = $db->prepare(
        'UPDATE tblEmailLoginTokens SET Used = 1 WHERE Email = ? AND Used = 0'
    );
    $stmt->execute([$email]);

    /* Insert new token */
    $stmt = $db->prepare(
        'INSERT INTO tblEmailLoginTokens (Email, UserId, Token, Code, ExpiresAt, IpAddress)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$email, $userId, $token, $code, $expiresAt, $clientIp]);

    return [
        'token'     => $token,
        'code'      => $code,
        'userId'    => $userId,
        'isNewUser' => ($userId === null),
    ];
}

/**
 * Verify an email login token (magic link mode).
 *
 * @param string $token The 48-char hex token from the magic link
 * @return array{userId: int|null, email: string}|null Null if invalid/expired
 */
function verifyEmailLoginToken(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) > 64) {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT Id, Email, UserId FROM tblEmailLoginTokens
         WHERE Token = ? AND Used = 0 AND ExpiresAt > NOW()'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    /* Mark as used */
    $stmt = $db->prepare('UPDATE tblEmailLoginTokens SET Used = 1 WHERE Id = ?');
    $stmt->execute([$row['Id']]);

    return [
        'userId' => $row['UserId'] ? (int)$row['UserId'] : null,
        'email'  => $row['Email'],
    ];
}

/**
 * Verify an email login code (manual code entry mode).
 *
 * @param string $email The email address the code was sent to
 * @param string $code  The 6-digit code entered by the user
 * @return array{userId: int|null, email: string}|null Null if invalid/expired
 */
function verifyEmailLoginCode(string $email, string $code): ?array
{
    $email = mb_strtolower(trim($email));
    $code  = trim($code);

    if ($email === '' || $code === '' || !preg_match('/^\d{6}$/', $code)) {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT Id, Email, UserId FROM tblEmailLoginTokens
         WHERE Email = ? AND Code = ? AND Used = 0 AND ExpiresAt > NOW()
         ORDER BY CreatedAt DESC LIMIT 1'
    );
    $stmt->execute([$email, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    /* Mark as used */
    $stmt = $db->prepare('UPDATE tblEmailLoginTokens SET Used = 1 WHERE Id = ?');
    $stmt->execute([$row['Id']]);

    return [
        'userId' => $row['UserId'] ? (int)$row['UserId'] : null,
        'email'  => $row['Email'],
    ];
}

/**
 * Complete the email login flow: find or create the user, mark email as
 * verified, update login stats, and generate a bearer token.
 *
 * @param string   $email  Verified email address
 * @param int|null $userId Existing user ID (null if new user)
 * @return array{token: string, user: array} Bearer token and user info
 */
function completeEmailLogin(string $email, ?int $userId): array
{
    $db = getDb();

    if ($userId === null) {
        /* Create a new user from the email address */
        $username = strstr($email, '@', true); /* Part before @ */
        $username = preg_replace('/[^a-z0-9_.\-]/', '', mb_strtolower($username));
        if (strlen($username) < 3) {
            $username = 'user_' . bin2hex(random_bytes(3));
        }

        /* Ensure username uniqueness */
        $baseUsername = $username;
        $counter = 1;
        while (true) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE Username = ?');
            $stmt->execute([$username]);
            if ((int)$stmt->fetchColumn() === 0) break;
            $username = $baseUsername . $counter;
            $counter++;
        }

        /* First user gets global_admin, others get user */
        $stmt = $db->query('SELECT COUNT(*) FROM tblUsers');
        $role = ((int)$stmt->fetchColumn() === 0) ? 'global_admin' : 'user';

        $displayName = ucfirst($baseUsername);
        $stmt = $db->prepare(
            'INSERT INTO tblUsers (Username, Email, EmailVerified, PasswordHash, DisplayName, Role)
             VALUES (?, ?, 1, ?, ?, ?)'
        );
        $stmt->execute([$username, $email, '', $displayName, $role]);
        $userId = (int)$db->lastInsertId();
    } else {
        /* Existing user — mark email as verified */
        $stmt = $db->prepare('UPDATE tblUsers SET EmailVerified = 1 WHERE Id = ?');
        $stmt->execute([$userId]);
    }

    /* Update login stats */
    $stmt = $db->prepare(
        'UPDATE tblUsers SET LastLoginAt = NOW(), LoginCount = LoginCount + 1 WHERE Id = ?'
    );
    $stmt->execute([$userId]);

    /* Fetch user record */
    $stmt = $db->prepare(
        'SELECT Id, Username, DisplayName, Email, Role FROM tblUsers WHERE Id = ?'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    /* Generate API bearer token (30-day expiry) */
    $token = bin2hex(random_bytes(32));
    $expiresAt = gmdate('c', time() + 30 * 86400);
    $stmt = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
    $stmt->execute([hash('sha256', $token), $userId, $expiresAt]);

    return [
        'token' => $token,
        'user'  => [
            'id'           => (int)$user['Id'],
            'username'     => $user['Username'],
            'display_name' => $user['DisplayName'],
            'email'        => $user['Email'],
            'role'         => $user['Role'],
        ],
    ];
}
