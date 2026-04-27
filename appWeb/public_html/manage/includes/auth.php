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

/* App configuration constants — defines APP_CONFIG used by head-libs.php
   and other shared partials. Loaded FIRST in the admin bootstrap so
   every /manage/* page has APP_CONFIG available transitively without
   each page (or each shared partial) having to require it itself.
   Without this, head-libs.php's `APP_CONFIG['libraries']['bootstrap']`
   read fatals on PHP 8+ (undefined constant), halting output mid-stream
   right after `<title>` and producing a near-blank /manage/login page —
   the symptom that surfaced once #531's idle timeout started kicking
   users back to the login form. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
          . DIRECTORY_SEPARATOR . 'config.php';

/* On-demand debug mode (#507 / #509) — wire as early as possible so a
   fatal in db.php, the auth bootstrap, or the page itself surfaces in
   the response instead of producing a silent blank page. Every manage
   page requires this file first, so this hook covers the whole admin
   surface in one place. Honoured only on Alpha/Beta with both
   `?_debug=1` and `?_dev=1` (or the cookie set by either). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
          . DIRECTORY_SEPARATOR . 'debug_mode.php';
enableDebugModeIfRequested();

/* db.php (PDO) is no longer used by this file after the #554 Batch 4
   migration, but it's kept in the require chain because other files
   (api.php, editor/api.php) still call getDb() during their own
   batch migrations. The capstone PR (#555) removes both this require
   and db.php itself once Batches 5+6 land. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
          . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* Entitlement helpers — paired with this file as the canonical /manage/
   bootstrap per the modularity rule in .claude/CLAUDE.md ("Auth + CSRF
   + role + entitlement checks → manage/includes/auth.php + includes/
   entitlements.php"). Loading it here means shared partials such as
   admin-nav.php / admin-links.php — which call userHasEntitlement()
   transitively — work everywhere this bootstrap runs, instead of each
   admin page having to remember its own entitlements.php require. The
   helper file is just const definitions + pure functions, so it's
   side-effect-free apart from a direct-access guard. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
          . DIRECTORY_SEPARATOR . 'entitlements.php';

/* Activity log helper (#535) — every auth event below writes one row
   via logActivity(). Loaded here so the helper is available as soon
   as anything in the /manage/ stack runs. Best-effort, never throws. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
          . DIRECTORY_SEPARATOR . 'activity_log.php';

/* =========================================================================
 * SESSION CONFIGURATION
 * ========================================================================= */

/** Session lifetime in seconds (24 hours) */
define('SESSION_LIFETIME', 86400);

/** Cookie name for the session */
define('SESSION_COOKIE', 'ihymns_manage_session');

/**
 * Idle-timeout window for /manage/ sessions (#531).
 *
 * The session cookie itself lives for SESSION_LIFETIME (24 h) so that
 * the cookie isn't dropped mid-Sunday-morning, but if no protected
 * page has been hit for IDLE_TIMEOUT_SECONDS we treat the session as
 * stale and re-prompt for credentials. Defends against the
 * "admin walked away from the laptop and someone else opened a
 * browser tab" scenario without making the active-use experience
 * frustrating.
 *
 * 30 minutes — long enough to write a long edit-song note + grab a
 * coffee, short enough that an unattended workstation doesn't stay
 * authenticated all afternoon.
 */
define('IDLE_TIMEOUT_SECONDS', 30 * 60);

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

    $db = getDbMysqli();
    /* Aliased to lowercase keys so the rest of /manage/ (index.php,
       users.php, entitlements.php, admin-nav.php, …) can use the
       $currentUser['role'] / ['username'] / ['display_name'] shape
       consistently. Passing a null key to the typed hasRole()
       parameter previously fatal-errored the admin dashboard mid-
       render. */
    $stmt = $db->prepare(
        'SELECT Id AS id,
                Username AS username,
                DisplayName AS display_name,
                Role AS role,
                Email AS email
         FROM tblUsers
         WHERE Id = ? AND IsActive = 1'
    );
    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
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

    /* Idle timeout (#531) — auto-logout after IDLE_TIMEOUT_SECONDS of
       inactivity. last_activity is bumped on every protected-page hit
       below, so an active session glides forward indefinitely while
       an abandoned one expires within half an hour. */
    $now = time();
    $lastActive = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActive > 0 && ($now - $lastActive) > IDLE_TIMEOUT_SECONDS) {
        $kickedUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        /* Record the timeout BEFORE wiping the session so the row is
           attributed to the user who got kicked, not to NULL. logout()
           is told not to write its own auth.logout row to avoid
           double-logging the same event. (#535) */
        logActivity(
            'auth.session_timeout',
            'user',
            (string)($kickedUserId ?? ''),
            ['idle_seconds' => $now - $lastActive],
            'success',
            $kickedUserId
        );
        logout(false);
        $_SESSION = [];
        initSession();
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/manage/';
        $_SESSION['login_notice'] = 'Your session timed out. Please sign in again.';
        header('Location: /manage/login');
        exit;
    }
    $_SESSION['last_activity'] = $now;

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
    $normalised = strtolower(trim($username));
    $db = getDbMysqli();
    $stmt = $db->prepare('SELECT * FROM tblUsers WHERE Username = ? AND IsActive = 1');
    $stmt->bind_param('s', $normalised);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['PasswordHash'])) {
        /* Failed login (#535) — record reason without leaking whether
           the username existed (the `reason` distinguishes "no such
           user" from "wrong password" only at the row level, never
           in the user-facing response). */
        logActivity(
            'auth.login',
            'user',
            $normalised,
            [
                'reason'   => $user ? 'wrong_password' : 'unknown_user',
                'username' => $normalised,
            ],
            'failure',
            $user ? (int)$user['Id'] : null
        );
        return null;
    }

    /* Set session */
    initSession();
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$user['Id'];
    $_SESSION['username'] = $user['Username'];

    /* Successful login (#535). */
    logActivity(
        'auth.login',
        'user',
        (string)$user['Id'],
        ['username' => $user['Username']],
        'success',
        (int)$user['Id']
    );

    return $user;
}

/**
 * Log out the current user and destroy the session.
 *
 * @param bool $logEvent Set false when the caller is already writing
 *                       a more-specific row (e.g. the idle-timeout
 *                       path in requireAuth() emits auth.session_timeout
 *                       beforehand and would otherwise double-log).
 */
function logout(bool $logEvent = true): void
{
    initSession();

    /* Capture the user id BEFORE we wipe the session — otherwise the
       log row gets attributed to NULL and we lose the "who logged out"
       information. (#535) */
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    if ($logEvent && $userId !== null && $userId > 0) {
        logActivity('auth.logout', 'user', (string)$userId, [], 'success', $userId);
    }

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
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (int)($row[0] ?? 0) === 0;
    } catch (\Throwable $e) {
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
    $db = getDbMysqli();
    /* Preserve the case the user chose — the `Username` column is
       utf8mb4_unicode_ci, so uniqueness + login lookups remain
       case-insensitive without us lowercasing. */
    $username = trim($username);

    /* Validate role */
    if (!isset(ROLE_LEVELS[$role])) {
        throw new \RuntimeException('Invalid role: ' . $role);
    }

    /* Check for duplicate */
    $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE Username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ((int)($row[0] ?? 0) > 0) {
        throw new \RuntimeException('Username already exists.');
    }

    $stmt = $db->prepare('INSERT INTO tblUsers (Username, PasswordHash, DisplayName, Role, Email) VALUES (?, ?, ?, ?, ?)');
    $passHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $displayTrim = trim($displayName);
    $emailTrim   = trim($email);
    $stmt->bind_param('sssss',
        $username, $passHash, $displayTrim, $role, $emailTrim);
    $stmt->execute();
    $newUserId = (int)$db->insert_id;
    $stmt->close();

    /* Audit (#535). Password is hashed before insert and the hash
       never enters Details per project-rules.md §10. */
    logActivity(
        'user.create',
        'user',
        (string)$newUserId,
        [
            'username'     => $username,
            'display_name' => trim($displayName),
            'role'         => $role,
            'email'        => trim($email),
        ]
    );

    return $newUserId;
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

    $db = getDbMysqli();
    /* $actingUser comes from getCurrentUser() which is lowercase-
       aliased; match that shape when reading role. */
    $stmt = $db->prepare('SELECT Role AS role FROM tblUsers WHERE Id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();
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

    $stmt = $db->prepare('UPDATE tblUsers SET Role = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->bind_param('si', $newRole, $userId);
    $stmt->execute();
    $stmt->close();

    /* Audit row (#535) — `before` / `after` makes the timeline
       readable in /manage/activity-log without joining anything. */
    logActivity(
        'auth.role_change',
        'user',
        (string)$userId,
        [
            'before' => ['role' => $target['role']],
            'after'  => ['role' => $newRole],
            'acting_user_id' => isset($actingUser['id']) ? (int)$actingUser['id'] : null,
        ]
    );

    /* Session-fixation defence (#531) — if the role change applies to
       the currently-signed-in user (e.g. self-demotion before
       handover, rare but possible), rotate the PHP session ID so
       any pre-change session token is invalidated. Other users'
       sessions are unaffected; their next requireAuth() picks up
       the new role from the DB regardless. */
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
        initSession();
        session_regenerate_id(true);
    }

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
    $db = getDbMysqli();
    $input = strtolower(trim($usernameOrEmail));

    /* Look up by username or email — same value bound twice (mysqli
       requires per-position binds). */
    $stmt = $db->prepare('SELECT Id, Username, Email FROM tblUsers WHERE (Username = ? OR Email = ?) AND IsActive = 1');
    $stmt->bind_param('ss', $input, $input);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) return null;

    /* Generate a secure token (48 chars hex = 24 random bytes) */
    $token = bin2hex(random_bytes(24));
    $expiresAt = gmdate('c', time() + 3600); /* 1 hour */

    /* Invalidate any existing tokens for this user */
    $userIdInt = (int)$user['Id'];
    $stmt = $db->prepare('DELETE FROM tblPasswordResetTokens WHERE UserId = ?');
    $stmt->bind_param('i', $userIdInt);
    $stmt->execute();
    $stmt->close();

    /* Insert new token (store SHA-256 hash; raw token is returned to the caller) */
    $hashedToken = hash('sha256', $token);
    $stmt = $db->prepare('INSERT INTO tblPasswordResetTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
    $stmt->bind_param('sis', $hashedToken, $userIdInt, $expiresAt);
    $stmt->execute();
    $stmt->close();

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
    $db = getDbMysqli();
    $hashedToken = hash('sha256', $token);
    $now = gmdate('c');
    $stmt = $db->prepare(
        'SELECT t.UserId, u.Username
         FROM tblPasswordResetTokens t
         JOIN tblUsers u ON u.Id = t.UserId
         WHERE t.Token = ? AND t.ExpiresAt > ? AND t.Used = 0 AND u.IsActive = 1'
    );
    $stmt->bind_param('ss', $hashedToken, $now);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
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
    if (!$tokenData) {
        /* Invalid or expired token (#535) — log without leaking the
           token itself (only its sha256 prefix). */
        logActivity(
            'auth.password_reset',
            'user',
            '',
            ['reason' => 'invalid_or_expired_token',
             'token_prefix' => substr(hash('sha256', $token), 0, 12)],
            'failure'
        );
        return false;
    }

    $db = getDbMysqli();

    /* Update password */
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $resetUserId = (int)$tokenData['UserId'];
    $stmt = $db->prepare('UPDATE tblUsers SET PasswordHash = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->bind_param('si', $hash, $resetUserId);
    $stmt->execute();
    $stmt->close();

    /* Mark token as used */
    $hashedToken = hash('sha256', $token);
    $stmt = $db->prepare('UPDATE tblPasswordResetTokens SET Used = 1 WHERE Token = ?');
    $stmt->bind_param('s', $hashedToken);
    $stmt->execute();
    $stmt->close();

    /* Invalidate all API tokens for this user (force re-login) */
    $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ?');
    $stmt->bind_param('i', $resetUserId);
    $stmt->execute();
    $stmt->close();

    /* Audit (#535). Note PasswordHash itself never goes into Details
       per the privacy contract in project-rules.md §10. */
    logActivity(
        'auth.password_reset',
        'user',
        (string)$tokenData['UserId'],
        ['method' => 'reset_token'],
        'success',
        (int)$tokenData['UserId']
    );

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
    $db = getDbMysqli();
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare('UPDATE tblUsers SET PasswordHash = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    $stmt->close();

    /* Invalidate all API tokens (force re-login) */
    $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    /* Session-fixation defence (#531) — if the password change applies
       to the current /manage/ session, rotate the PHP session ID
       alongside the bearer-token wipe above so a stolen pre-change
       session can't continue to act as the user. */
    $isSelf = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId);
    if ($isSelf) {
        initSession();
        session_regenerate_id(true);
    }

    /* Audit (#535). The new hash itself never enters Details per
       the privacy contract in project-rules.md §10. */
    logActivity(
        'auth.password_change',
        'user',
        (string)$userId,
        ['self' => $isSelf]
    );

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
    $db = getDbMysqli();

    /* Capture the before state for the audit row (#535) so admins
       can see what changed in /manage/activity-log without joining
       a separate revisions table. */
    $beforeStmt = $db->prepare('SELECT DisplayName AS display_name, Email AS email FROM tblUsers WHERE Id = ?');
    $beforeStmt->bind_param('i', $userId);
    $beforeStmt->execute();
    $before = $beforeStmt->get_result()->fetch_assoc() ?: ['display_name' => null, 'email' => null];
    $beforeStmt->close();

    $newDisplayName = trim($displayName);
    $newEmail       = trim($email);

    $stmt = $db->prepare('UPDATE tblUsers SET DisplayName = ?, Email = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->bind_param('ssi', $newDisplayName, $newEmail, $userId);
    $stmt->execute();
    $stmt->close();

    /* Build a fields-changed list so the audit row is concise. */
    $changed = [];
    if ($before['display_name'] !== $newDisplayName) $changed[] = 'DisplayName';
    if ($before['email']        !== $newEmail)       $changed[] = 'Email';

    if (!empty($changed)) {
        logActivity(
            'user.profile_edit',
            'user',
            (string)$userId,
            [
                'fields' => $changed,
                'before' => array_intersect_key($before, array_flip(array_map('strtolower', $changed))),
                'after'  => [
                    'display_name' => $newDisplayName,
                    'email'        => $newEmail,
                ],
            ]
        );
    }
    return true;
}

/**
 * Rename a user (admin path — no password verification, since the
 * caller has already been authorised by the surrounding admin check).
 * Validation mirrors auth_register: lowercased, [a-z0-9_.\-], 3–100
 * chars. Returns false on validation failure or uniqueness collision;
 * caller should surface a friendly message.
 *
 * @param int    $userId      Target user ID
 * @param string $newUsername Desired username
 * @param string &$error      Set to a human-readable message on failure
 * @return bool
 */
function renameUser(int $userId, string $newUsername, ?string &$error = null): bool
{
    /* Preserve case the user picked. Validation allows upper + lower
       letters; the `ci` collation on Username still enforces unique-
       across-case, so nobody can create "Alice" if "alice" exists. */
    $newUsername = trim($newUsername);
    if (strlen($newUsername) < 3
        || strlen($newUsername) > 100
        || !preg_match('/^[A-Za-z0-9_.\-]+$/', $newUsername)) {
        $error = 'Username must be 3–100 characters (letters, numbers, _, -, . only).';
        return false;
    }

    $db = getDbMysqli();
    $stmt = $db->prepare('SELECT Id FROM tblUsers WHERE Username = ? AND Id <> ?');
    $stmt->bind_param('si', $newUsername, $userId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($exists) {
        $error = 'Username is already taken.';
        return false;
    }

    /* Capture old username before the update so the audit row can
       carry both halves of the rename. (#535) */
    $oldStmt = $db->prepare('SELECT Username FROM tblUsers WHERE Id = ?');
    $oldStmt->bind_param('i', $userId);
    $oldStmt->execute();
    $row = $oldStmt->get_result()->fetch_row();
    $oldStmt->close();
    $oldUsername = (string)($row[0] ?? '');

    $stmt = $db->prepare('UPDATE tblUsers SET Username = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->bind_param('si', $newUsername, $userId);
    $stmt->execute();
    $stmt->close();

    logActivity(
        'auth.username_change',
        'user',
        (string)$userId,
        [
            'before' => ['username' => $oldUsername],
            'after'  => ['username' => $newUsername],
        ]
    );

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
    $db = getDbMysqli();
    $isActiveInt = $isActive ? 1 : 0;
    $stmt = $db->prepare('UPDATE tblUsers SET IsActive = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
    $stmt->bind_param('ii', $isActiveInt, $userId);
    $stmt->execute();
    $stmt->close();

    /* If deactivating, invalidate all their tokens */
    if (!$isActive) {
        $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    /* Audit (#535) — `user.activate` / `user.deactivate` so the action
       label is unambiguous in the timeline. */
    logActivity(
        $isActive ? 'user.activate' : 'user.deactivate',
        'user',
        (string)$userId
    );

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
    $db = getDbMysqli();

    /* Capture username for the audit row before the row vanishes
       — the FK from tblActivityLog.UserId to tblUsers is `ON DELETE
       SET NULL`, so after the cascade the log loses any obvious
       handle on who got deleted unless we record it here. (#535) */
    $beforeStmt = $db->prepare('SELECT Username, DisplayName, Role, Email FROM tblUsers WHERE Id = ?');
    $beforeStmt->bind_param('i', $userId);
    $beforeStmt->execute();
    $before = $beforeStmt->get_result()->fetch_assoc() ?: null;
    $beforeStmt->close();

    $stmt = $db->prepare('DELETE FROM tblUsers WHERE Id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    if ($deleted) {
        logActivity(
            'user.delete',
            'user',
            (string)$userId,
            $before ? ['before' => $before] : []
        );
    }

    return $deleted;
}

/**
 * Get a user by ID.
 *
 * @param int $userId User ID
 * @return array|null User row or null
 */
function getUserById(int $userId): ?array
{
    $db = getDbMysqli();
    /* Aliased to lowercase keys so callers in manage/users.php can read
       $target['role'] / ['username'] / ['is_active'] consistently with
       getCurrentUser() and the main user listing query. */
    $stmt = $db->prepare(
        'SELECT Id AS id,
                Username AS username,
                DisplayName AS display_name,
                Email AS email,
                Role AS role,
                GroupId AS group_id,
                IsActive AS is_active,
                CreatedAt AS created_at,
                UpdatedAt AS updated_at
         FROM tblUsers WHERE Id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
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

    $db = getDbMysqli();

    /* Rate limit: max 5 requests per email per hour */
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM tblEmailLoginTokens
         WHERE Email = ? AND CreatedAt > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ((int)($row[0] ?? 0) >= 5) {
        return null; /* Rate limited */
    }

    /* Check if user already exists with this email */
    $stmt = $db->prepare('SELECT Id FROM tblUsers WHERE Email = ? AND IsActive = 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $existingUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userId = $existingUser ? (int)$existingUser['Id'] : null;

    /* Generate token (48-char hex) and code (6-digit numeric) */
    $token = bin2hex(random_bytes(24));
    $code  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = gmdate('c', time() + 600); /* 10 minutes */

    /* Invalidate any previous unused tokens for this email */
    $stmt = $db->prepare(
        'UPDATE tblEmailLoginTokens SET Used = 1 WHERE Email = ? AND Used = 0'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();

    /* Insert new token. UserId is nullable when the email doesn't
       match an existing user yet (first-login flow); mysqli passes
       NULL correctly with type 'i' when the bound variable is null. */
    $stmt = $db->prepare(
        'INSERT INTO tblEmailLoginTokens (Email, UserId, Token, Code, ExpiresAt, IpAddress)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sissss',
        $email, $userId, $token, $code, $expiresAt, $clientIp);
    $stmt->execute();
    $stmt->close();

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

    $db = getDbMysqli();
    $stmt = $db->prepare(
        'SELECT Id, Email, UserId FROM tblEmailLoginTokens
         WHERE Token = ? AND Used = 0 AND ExpiresAt > NOW()'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    /* Mark as used */
    $rowId = (int)$row['Id'];
    $stmt = $db->prepare('UPDATE tblEmailLoginTokens SET Used = 1 WHERE Id = ?');
    $stmt->bind_param('i', $rowId);
    $stmt->execute();
    $stmt->close();

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

    $db = getDbMysqli();
    $stmt = $db->prepare(
        'SELECT Id, Email, UserId FROM tblEmailLoginTokens
         WHERE Email = ? AND Code = ? AND Used = 0 AND ExpiresAt > NOW()
         ORDER BY CreatedAt DESC LIMIT 1'
    );
    $stmt->bind_param('ss', $email, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    /* Mark as used */
    $rowId = (int)$row['Id'];
    $stmt = $db->prepare('UPDATE tblEmailLoginTokens SET Used = 1 WHERE Id = ?');
    $stmt->bind_param('i', $rowId);
    $stmt->execute();
    $stmt->close();

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
    $db = getDbMysqli();

    if ($userId === null) {
        /* Create a new user from the email address */
        $username = strstr($email, '@', true); /* Part before @ */
        $username = preg_replace('/[^a-z0-9_.\-]/', '', mb_strtolower($username));
        if (strlen($username) < 3) {
            $username = 'user_' . bin2hex(random_bytes(3));
        }

        /* Ensure username uniqueness — same prepared statement reused
           across iterations, with $username as the bound variable
           that we update each time. */
        $baseUsername = $username;
        $counter = 1;
        $countStmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE Username = ?');
        $countStmt->bind_param('s', $username);
        while (true) {
            $countStmt->execute();
            $row = $countStmt->get_result()->fetch_row();
            if ((int)($row[0] ?? 0) === 0) break;
            $username = $baseUsername . $counter;
            $counter++;
        }
        $countStmt->close();

        /* First user gets global_admin, others get user */
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $role = ((int)($row[0] ?? 0) === 0) ? 'global_admin' : 'user';

        $displayName = ucfirst($baseUsername);
        $emptyPwHash = '';
        $stmt = $db->prepare(
            'INSERT INTO tblUsers (Username, Email, EmailVerified, PasswordHash, DisplayName, Role)
             VALUES (?, ?, 1, ?, ?, ?)'
        );
        $stmt->bind_param('sssss',
            $username, $email, $emptyPwHash, $displayName, $role);
        $stmt->execute();
        $userId = (int)$db->insert_id;
        $stmt->close();
    } else {
        /* Existing user — mark email as verified */
        $stmt = $db->prepare('UPDATE tblUsers SET EmailVerified = 1 WHERE Id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    /* Update login stats */
    $stmt = $db->prepare(
        'UPDATE tblUsers SET LastLoginAt = NOW(), LoginCount = LoginCount + 1 WHERE Id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    /* Fetch user record */
    $stmt = $db->prepare(
        'SELECT Id, Username, DisplayName, Email, Role FROM tblUsers WHERE Id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /* Generate API bearer token (30-day expiry) */
    $token = bin2hex(random_bytes(32));
    $expiresAt = gmdate('c', time() + 30 * 86400);
    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
    $stmt->bind_param('sis', $tokenHash, $userId, $expiresAt);
    $stmt->execute();
    $stmt->close();

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
