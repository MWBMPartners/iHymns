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

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
          . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* Cross-subdomain `ihymns_auth` cookie helpers (#1377/#1376) — the SAME file
   api.php uses. On a successful /manage login we mint an `ihymns_auth` API
   token + cookie so the admin is recognised on the PUBLIC app too (full
   access + the maintenance bypass in includes/maintenance.php). Without this,
   a /manage sign-in only sets the path-scoped `ihymns_manage_session` cookie,
   which never reaches the public site, so the admin is gated there (the
   owner-reported "can't verify changes outside /manage during maintenance"
   bug). setAuthTokenCookie() / clearAuthTokenCookie() come from here. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
          . DIRECTORY_SEPARATOR . 'auth_cookie.php';

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
/* Mirror every uncaught \Throwable + PHP fatal across the entire
   /manage/* admin surface into tblActivityLog. Every admin page
   requires this auth bootstrap first, so installing the global
   handler here covers all of them — schema-audit, organisations,
   credit-people, songbooks, setup-database, etc. — without each
   page having to remember to register its own. */
installGlobalActivityLogHandlers('manage');

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
            /* Honour X-Forwarded-Proto as well as HTTPS (#1648 item 2).
               ELI5: if something in front of us handled the encryption, we
               still need to mark this cookie as HTTPS-only.
               Detail: behind a TLS-terminating proxy (a CDN, a load balancer)
               $_SERVER['HTTPS'] is unset on the origin even though the browser
               is on https — so this minted the /manage SESSION cookie WITHOUT
               `secure`, leaving it eligible to be sent over a plaintext
               request. includes/auth_cookie.php:61-63 already checks both for
               exactly this reason; the two cookie helpers in one codebase
               disagreeing was the actual defect. Latent on DreamHost today,
               which sets HTTPS directly — it becomes real the moment anything
               sits in front. */
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
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
 * If the PHP session has no user but the cross-subdomain `ihymns_auth`
 * cookie carries a valid API token (set by main-app sign-in via
 * `auth_login` / `auth_email_login_verify` / `auth_register`), promote
 * that token into the admin session so a user signed in on the main
 * app doesn't get bounced to /manage/login when they click Manage.
 * Without this, the two auth surfaces are independent — the main app
 * uses the Bearer/cookie token, /manage/ uses PHP $_SESSION — and a
 * curator/admin who's already signed in had to sign in twice. (#578)
 *
 * @return bool True if authenticated
 */
function isAuthenticated(): bool
{
    initSession();
    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        return true;
    }
    return adoptApiTokenSession();
}

/**
 * If the request carries a valid `ihymns_auth` cookie (the API token
 * issued by the main-app sign-in flow), look it up in tblApiTokens and
 * seed $_SESSION as if the user had signed in via /manage/login. The
 * token must be unexpired and the user active. Sliding-expiry behaviour
 * mirrors the main API (`getAuthenticatedUser` in api.php) so the same
 * user being active on /manage/ keeps the token alive.
 *
 * Returns true on success (session now populated), false otherwise.
 */
function adoptApiTokenSession(): bool
{
    /* Cookie name + shape must match api.php. The cookie is httponly so
       JS can't read it but the request carries it for us. */
    if (empty($_COOKIE['ihymns_auth'])) {
        return false;
    }
    $token = (string)$_COOKIE['ihymns_auth'];
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return false;
    }

    try {
        $db          = getDbMysqli();
        $hashedToken = hash('sha256', $token);
        $now         = gmdate('c');
        $stmt        = $db->prepare(
            'SELECT u.Id        AS id,
                    u.Username  AS username,
                    t.ExpiresAt AS expires_at
               FROM tblApiTokens t
               JOIN tblUsers     u ON u.Id = t.UserId
              WHERE t.Token     = ?
                AND t.ExpiresAt > ?
                AND u.IsActive  = 1'
        );
        $stmt->bind_param('ss', $hashedToken, $now);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[manage/auth] adoptApiTokenSession failed: ' . $e->getMessage());
        return false;
    }

    if (!$row) {
        return false;
    }

    /* Promote the token into $_SESSION exactly as /manage/login.php's
       successful POST handler would. The rest of the admin surface
       reads $_SESSION['user_id'] / ['username'] and never touches the
       cookie directly, so this keeps the call sites unchanged. */
    $_SESSION['user_id']       = (int)$row['id'];
    $_SESSION['username']      = (string)$row['username'];
    $_SESSION['last_activity'] = time();

    /* Slide the token expiry (#390) so an active manage-side user
       keeps their main-app sign-in alive too. The DB write is gated
       to once-per-day-per-token by the main API helper; we duplicate
       that gate here to keep the two paths consistent without having
       to share code. */
    try {
        $cap = $row['expires_at'];
        if (is_string($cap) && $cap !== '') {
            $capTs    = strtotime($cap);
            $nowTs    = time();
            $newCapTs = $nowTs + 30 * 86400;
            if ($capTs && ($newCapTs - $capTs) >= 86400) {
                $stmt = $db->prepare(
                    'UPDATE tblApiTokens SET ExpiresAt = ? WHERE Token = ? AND ExpiresAt < ?'
                );
                $newCap = gmdate('c', $newCapTs);
                $stmt->bind_param('sss', $newCap, $hashedToken, $newCap);
                $stmt->execute();
                $stmt->close();
            }
        }
    } catch (\Throwable $e) {
        error_log('[manage/auth] sliding-expiry failed: ' . $e->getMessage());
    }

    return true;
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
    /* AvatarService (#616) is included via COALESCE-on-COLUMN_EXISTS
       so the admin nav still renders on a partly-migrated install
       that hasn't yet applied migrate-user-avatar-service. NULL value
       means "inherit project default" — admin-nav.php passes it
       straight to userAvatarUrl()'s third arg, which treats NULL as
       "use APP_CONFIG default". */
    $hasAvatarService = false;
    $colCheck = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblUsers'
            AND COLUMN_NAME  = 'AvatarService' LIMIT 1"
    );
    if ($colCheck && $colCheck->fetch_row() !== null) { $hasAvatarService = true; }
    if ($colCheck) { $colCheck->close(); }

    $avatarSvcCol = $hasAvatarService ? ', AvatarService AS avatar_service' : ', NULL AS avatar_service';

    $stmt = $db->prepare(
        "SELECT Id AS id,
                Username AS username,
                DisplayName AS display_name,
                Role AS role,
                Email AS email
                {$avatarSvcCol}
         FROM tblUsers
         WHERE Id = ? AND IsActive = 1"
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

    /* Idle timeout (#531) — after IDLE_TIMEOUT_SECONDS without a
       protected-page hit, drop the PHP session and silently re-adopt
       from the cross-subdomain `ihymns_auth` API-token cookie so an
       active main-app sign-in carries the user straight back in
       without a login prompt. The 30-day rolling token is the source
       of truth for "is this user signed in"; the /manage/ PHP session
       is just a per-tab cache that gets refreshed periodically.
       Only when the API token is also gone (expired / revoked /
       password-changed) do we fall through to /manage/login.

       Note: we MUST NOT call logout() here — that deletes the
       tblApiTokens row + clears the ihymns_auth cookie, which would
       sign the user out of the main app too and defeat the silent
       re-adoption below. We only wipe $_SESSION. */
    $now = time();
    $lastActive = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActive > 0 && ($now - $lastActive) > IDLE_TIMEOUT_SECONDS) {
        $kickedUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $idleSeconds  = $now - $lastActive;

        /* Drop the stale session contents but keep the cookie/DB row
           that adoptApiTokenSession() needs to read back below.
           Rotating the session id defends against fixation across
           the refresh boundary. */
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        if (adoptApiTokenSession()) {
            /* Silent refresh — token cookie was still valid, user is
               none the wiser. Recorded distinctly from a forced
               re-login so admins auditing the activity log can tell
               the two apart. (#535) */
            $refreshedUserId = (int)($_SESSION['user_id'] ?? 0);
            logActivity(
                'auth.session_refresh',
                'user',
                (string)$refreshedUserId,
                ['idle_seconds' => $idleSeconds],
                'success',
                $refreshedUserId ?: null
            );
            /* Fall through to the last_activity bump + getCurrentUser
               check below so the rest of requireAuth() runs as if the
               session had never lapsed. */
        } else {
            /* No valid API token to fall back on — true timeout. Log
               BEFORE redirecting so the row is attributed to the user
               who got kicked, not to NULL. */
            logActivity(
                'auth.session_timeout',
                'user',
                (string)($kickedUserId ?? ''),
                ['idle_seconds' => $idleSeconds],
                'success',
                $kickedUserId
            );
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/manage/';
            $_SESSION['login_notice'] = 'Your session timed out. Please sign in again.';
            header('Location: /manage/login');
            exit;
        }
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

    /* SECURITY (brute-force — #1386 audit): the /manage admin login (the
       highest-value accounts: admin / global_admin) was previously unthrottled,
       unlike the public api.php auth_login. Mirror that IP lockout — reject
       after 10 failed attempts from this IP in 15 minutes BEFORE verifying the
       password, so the lockout holds even for a correct guess during the window.
       REMOTE_ADDR (not a spoofable forwarded header) is the rate-limit key. */
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($clientIp !== '') {
        $bf = $db->prepare(
            'SELECT COUNT(*) FROM tblLoginAttempts
             WHERE IpAddress = ? AND Success = 0
               AND AttemptedAt > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $bf->bind_param('s', $clientIp);
        $bf->execute();
        $recentFailures = (int)($bf->get_result()->fetch_row()[0] ?? 0);
        $bf->close();
        if ($recentFailures >= 10) {
            logActivity('auth.login', 'user', $normalised,
                ['reason' => 'rate_limited', 'recent_failures' => $recentFailures], 'failure');
            return null;
        }
    }

    $stmt = $db->prepare('SELECT * FROM tblUsers WHERE Username = ? AND IsActive = 1');
    $stmt->bind_param('s', $normalised);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['PasswordHash'])) {
        /* SECURITY: record the failed attempt in tblLoginAttempts so the
           brute-force counter above can see it (mirrors api.php auth_login). */
        if ($clientIp !== '') {
            $fa = $db->prepare('INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, 0)');
            $fa->bind_param('ss', $clientIp, $normalised);
            $fa->execute();
            $fa->close();
        }
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

    /* Cross-surface sign-in (#1377/#1376) — ALSO mint an `ihymns_auth` API
       token + cookie so this admin is recognised as logged-in on the PUBLIC
       app, not just inside the path-scoped /manage area. Best-effort: a mint
       failure must NOT block the /manage login itself (the PHP session is
       already set), so it is wrapped + logged, never thrown. The matching
       revocation happens in logout() below, which already DELETEs the token
       row + clears the cookie — so login/logout are symmetric. */
    mintCrossSurfaceAuthToken((int)$user['Id']);

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
 * Mint a cross-subdomain `ihymns_auth` API token for a just-authenticated
 * /manage user and set the matching cookie, so the PUBLIC app recognises
 * them as signed-in (full access + the maintenance bypass in
 * includes/maintenance.php). (#1377/#1376)
 *
 * STORAGE SHAPE — must match api.php EXACTLY or getCurrentUser() / the public
 * api.php would never resolve the token:
 *   - raw token  = bin2hex(random_bytes(32))  → 64-char hex (api.php line ~2141)
 *   - tblApiTokens.Token stores hash('sha256', rawToken)  (api.php line ~2144-2146)
 *   - the COOKIE carries the RAW token; lookups re-hash it (adoptApiTokenSession()
 *     above + getAuthenticatedUser() in api.php both compute hash('sha256', cookie)).
 *
 * EXPIRY — 30 days, matching the main-app login (api.php auth_login/auth_register
 * both use `time() + 30 * 86400`). The /manage PHP session's own 30-min idle
 * timeout is unchanged; this token is the longer-lived "is this user signed in
 * across subdomains" record, exactly as a main-app sign-in would create. Picking
 * the SAME 30 days keeps a /manage sign-in indistinguishable from a public-app
 * sign-in (and lets the existing sliding-expiry logic treat them identically).
 *
 * Best-effort: ANY failure is logged and swallowed — the caller has already
 * established the /manage PHP session, so a token-mint hiccup must not break
 * the admin's ability to reach the dashboard. All SQL is bound (rule #5).
 *
 * @param int $userId The just-authenticated user's id (already validated).
 */
function mintCrossSurfaceAuthToken(int $userId): void
{
    try {
        $db = getDbMysqli();

        /* Same generation as api.php: 32 random bytes → 64 hex chars. The
           RAW token goes in the cookie; the DB stores only its sha256 hash so
           a DB dump can't be replayed as a live bearer token. */
        $rawToken    = bin2hex(random_bytes(32));
        $tokenHash   = hash('sha256', $rawToken);
        $expiresAtTs = time() + 30 * 86400;            /* 30 days — see docblock. */
        $expiresAt   = gmdate('c', $expiresAtTs);

        /* Bound INSERT — UserId is an int, Token/ExpiresAt are strings, mirroring
           the 'sis' bind api.php uses for the same statement. */
        $stmt = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
        $stmt->bind_param('sis', $tokenHash, $userId, $expiresAt);
        $stmt->execute();
        $stmt->close();

        /* Set the cross-subdomain cookie with the RAW token + the shared cookie
           options (domain `.ihymns.app`, path `/`, HttpOnly, SameSite=Lax,
           Secure-on-HTTPS) so the public app's getAuthenticatedUser() picks it
           up. Helper comes from includes/auth_cookie.php (required at top). */
        if (function_exists('setAuthTokenCookie')) {
            setAuthTokenCookie($rawToken, $expiresAtTs);
        }
    } catch (\Throwable $e) {
        /* Non-fatal: the /manage PHP session is already established, so a
           cross-surface mint failure just means the admin is signed in on
           /manage but not (yet) on the public app — degraded, not broken. */
        error_log('[manage/auth] mintCrossSurfaceAuthToken failed: ' . $e->getMessage());
    }
}

/**
 * Log out the current user and destroy the session.
 *
 * Also revokes the cross-subdomain `ihymns_auth` API token so the
 * main app gets signed out at the same time (#764) — only call this
 * for an explicit user-initiated logout. The idle-timeout path in
 * requireAuth() deliberately does NOT call logout(): it wipes the
 * /manage/ session but preserves the API token so silent re-adoption
 * can carry the same user back in on the next request.
 *
 * This is the REVOKE half of the cross-surface sign-in introduced in
 * #1377/#1376 (attemptLogin() now MINTs an `ihymns_auth` token on every
 * successful /manage login). Because login mints and logout revokes, an
 * admin who signs out of /manage is also signed out of the public app —
 * the two surfaces stay symmetric. The DELETE below already matched the
 * minted shape (sha256 hash of the raw cookie), so no change was needed
 * there; the cookie clear now reuses the shared clearAuthTokenCookie()
 * helper so its attributes are byte-identical to the set-side.
 *
 * @param bool $logEvent Set false when the caller is already writing
 *                       a more-specific row, to avoid double-logging.
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

    /* Also invalidate any `ihymns_auth` API-token cookie that the
       main-site sign-in flow set (#764). Without this, the
       isAuthenticated() fallback in this same file calls
       adoptApiTokenSession(), which reads the still-valid cookie,
       looks up tblApiTokens, and re-seeds $_SESSION — so /manage/login
       would adopt the token immediately after logout() destroys the
       session and bounce the user straight back to /manage/.

       Three things have to happen:
         1. DELETE the row in tblApiTokens (otherwise the cookie value
            keeps working on a future request that re-presents it).
         2. Clear the cookie so the browser doesn't keep sending it.
         3. Be tolerant: a missing tblApiTokens table or DB outage
            must not block the rest of the logout. */
    $tokenCookie = (string)($_COOKIE['ihymns_auth'] ?? '');
    if ($tokenCookie !== '' && preg_match('/^[a-f0-9]{64}$/i', $tokenCookie)) {
        try {
            if (function_exists('getDbMysqli')) {
                $db = getDbMysqli();
                $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE Token = ?');
                if ($stmt) {
                    $tokenHash = hash('sha256', $tokenCookie);
                    $stmt->bind_param('s', $tokenHash);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } catch (\Throwable $e) {
            error_log('[manage logout] tblApiTokens delete failed: ' . $e->getMessage());
        }
    }
    if (!empty($_COOKIE['ihymns_auth']) && !headers_sent()) {
        /* Clear via the SHARED helper (#1377/#1376) so the expiry cookie
           carries the EXACT same path/domain/secure/HttpOnly/SameSite
           attributes as the set-side (setAuthTokenCookie()). The previous
           inline version scoped the clear to the bare host (`$host`) rather
           than the `.ihymns.app` parent domain the cookie was actually set
           with — a domain mismatch can leave the parent-domain cookie behind
           in some browsers, so an admin who "logged out" could still be seen
           as signed-in on a sibling subdomain. clearAuthTokenCookie() mirrors
           _authCookieOpts() exactly, eliminating that drift. */
        if (function_exists('clearAuthTokenCookie')) {
            clearAuthTokenCookie();
        }
        unset($_COOKIE['ihymns_auth']);
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

/**
 * Split a Host-header authority into [host, port] — PURE. (#1709)
 *
 * ELI5: turns `example.com:8080` into "example.com" plus the number 8080, and
 * `example.com` into "example.com" plus "no port given".
 *
 * Detail: this is the counterpart of `parse_url(..., PHP_URL_HOST/PORT)` for a
 * value that is an AUTHORITY rather than a URL — `$_SERVER['HTTP_HOST']` cannot
 * be handed to parse_url() reliably (with no scheme, `example.com:8080` parses
 * as scheme `example.com` with path `8080`). Extracted as its own function so
 * `tests/php/test-csrf-same-origin.php` can drive it through the caller with no
 * MySQL and no session.
 *
 * IPv6 literals are handled explicitly: a bracketed host may itself contain
 * colons (`[::1]:8080`), so the port can only be the colon AFTER the closing
 * bracket. Splitting on the last colon without this check would turn `[::1]`
 * into host `[:` — a silent corruption that then fails every comparison.
 * parse_url() keeps the brackets on the host component (verified), so this
 * keeps them too and the two sides compare equal.
 *
 * The host is lower-cased; the port is an int, or NULL when none was stated.
 * A non-numeric trailing `:segment` is treated as part of the host rather than
 * as a port, so a malformed value fails the caller's comparison instead of
 * being silently reinterpreted.
 *
 * @param  string $authority Raw Host-header value, e.g. `example.com:8080`.
 * @return array{0:string,1:int|null} [lower-cased host, port or null]
 * @link https://datatracker.ietf.org/doc/html/rfc3986#section-3.2
 */
function _csrfSplitAuthority(string $authority): array
{
    $authority = trim($authority);
    if ($authority === '') {
        return ['', null];
    }

    /* IPv6 literal — the host is everything up to and including `]`. */
    if ($authority[0] === '[') {
        $close = strpos($authority, ']');
        if ($close === false) {
            return ['', null];              // malformed; caller rejects
        }
        $hostPart = substr($authority, 0, $close + 1);
        $rest     = substr($authority, $close + 1);
        $port     = null;
        if ($rest !== '' && $rest[0] === ':') {
            $digits = substr($rest, 1);
            if ($digits !== '' && ctype_digit($digits)) {
                $port = (int)$digits;
            } else {
                return ['', null];          // garbage after the bracket
            }
        } elseif ($rest !== '') {
            return ['', null];
        }
        return [strtolower($hostPart), $port];
    }

    $colon = strrpos($authority, ':');
    if ($colon === false) {
        return [strtolower($authority), null];
    }
    $digits = substr($authority, $colon + 1);
    if ($digits !== '' && ctype_digit($digits)) {
        return [strtolower(substr($authority, 0, $colon)), (int)$digits];
    }
    /* A colon that isn't a port (malformed) — keep it in the host so the
       comparison fails rather than silently matching a truncated host. */
    return [strtolower($authority), null];
}

/**
 * Robust CSRF check for same-origin AJAX writes — fixes the SPORADIC "CSRF error"
 * on long-lived editor pages (merge / delete / save).
 *
 * WHY: validateCsrf() above compares a token BAKED INTO THE PAGE at render against
 * $_SESSION['csrf_token']. On a long-lived single-page editor, that session token can
 * rotate / be GC'd / change across a multi-tab session while the page stays open — so
 * a perfectly legitimate POST then carries a STALE token and is rejected. That is the
 * intermittent failure the owner saw. (The v2 editor api2.php already sidesteps it.)
 *
 * This check does NOT depend on the baked token. It accepts the request when EITHER:
 *   (a) a valid session token was supplied (back-compat — existing callers still work), OR
 *   (b) it is a genuine same-origin AJAX request: the custom header X-Requested-With is
 *       present (a browser cannot set it on a CROSS-origin request without a CORS
 *       preflight this server never grants), AND — if an Origin or Referer header is
 *       present — its host matches this site (an explicit cross-origin host is rejected;
 *       an ABSENT Origin/Referer is allowed, since the custom header already proves
 *       same-origin and some privacy setups strip Referer).
 *
 * Either signal alone is an OWASP-recognised CSRF mitigation; together they are robust
 * and — crucially — never go stale. State-changing AJAX endpoints should call this
 * instead of validateCsrf() directly. Pass the submitted token (or null) for (a).
 *
 * Docs: https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
 *
 * @param string|null $token Submitted CSRF token (header/field), or null if none.
 * @return bool True if the request is a legitimate same-origin write.
 */
function validateCsrfRequest(?string $token = null): bool
{
    /* (a) A valid session token still passes — zero behaviour change for callers
       whose baked token happens to be current. */
    if ($token !== null && $token !== '' && validateCsrf($token)) {
        return true;
    }

    /* (b) Same-origin AJAX. The custom header is the strong signal — required. */
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        return false;
    }

    /* If Origin / Referer is present it MUST match this host AND port (#1709).
     *
     * ELI5: the browser writes our address two different ways in two different
     * headers — one keeps the port number, the other splits it off. Comparing
     * them as plain text therefore never matched on any site not running on the
     * standard port, and every save was refused.
     *
     * Detail: `$_SERVER['HTTP_HOST']` is the Host header VERBATIM, so it keeps
     * the port (`example.com:8080`). `parse_url($origin, PHP_URL_HOST)` returns
     * the host COMPONENT, which by definition excludes the port. The previous
     * `strcasecmp($h, $host)` compared `example.com` against `example.com:8080`
     * and could never be equal — so on a non-default port this whole
     * same-origin route was dead and every rule-#29 caller (the editor save,
     * duplicate-songs merge/delete, places-api, the legacy editor POST gate)
     * fell back to needing the baked session token that route exists to stop
     * depending on.
     *
     * It also silently dropped the port from the comparison ENTIRELY, so a
     * genuinely different origin on the same host — `https://example.com:9090`
     * against a site on 443 — was accepted. Port is part of an origin
     * (RFC 6454 §4), so that was a real, if narrow, same-origin-policy hole.
     * Both are fixed by comparing host AND port.
     *
     * The request's port is resolved against the SCHEME THE HEADER STATES, not
     * against a scheme we infer for ourselves. That matters: behind a
     * TLS-terminating proxy this process cannot reliably tell http from https
     * (the very ambiguity `initSession()` above has to paper over with
     * X-Forwarded-Proto), and guessing wrong here would reject every legitimate
     * write. A browser builds Host and Origin from the SAME URL, so it omits
     * the port from both or from neither — making "explicit port, else this
     * scheme's default" an exact comparison on both sides rather than a guess.
     *
     * Consequence stated plainly: a cross-SCHEME origin (`http://example.com`
     * against an https deployment) still matches, because both reduce to the
     * same host and each side's own default port. That is deliberate — it is
     * same-SITE, HSTS is sent site-wide, and rejecting it would mean inferring
     * the request scheme, which is the proxy-fragile thing above. Pinned as
     * case 17 of tests/php/test-csrf-same-origin.php so changing it is a
     * conscious decision rather than a drift.
     *
     * https://datatracker.ietf.org/doc/html/rfc6454#section-4
     * https://www.php.net/manual/en/function.parse-url.php
     */
    $host    = (string)($_SERVER['HTTP_HOST'] ?? '');
    $sawHost = false;
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $hdr) {
        if (empty($_SERVER[$hdr])) {
            continue;
        }
        $raw    = (string)$_SERVER[$hdr];
        $h      = parse_url($raw, PHP_URL_HOST);
        /* Unparseable / relative value → no positive same-origin evidence. */
        if ($h === null || $h === false || $h === '') {
            return false;
        }
        $scheme = parse_url($raw, PHP_URL_SCHEME);
        $port   = parse_url($raw, PHP_URL_PORT);

        /* Default port for the scheme the header itself declares. NULL for any
           other scheme means "no default to fall back on", so an explicit port
           is then required on both sides to agree. */
        $defaultPort = match (strtolower((string)$scheme)) {
            'https' => 443,
            'http'  => 80,
            default => null,
        };

        [$reqHost, $reqPort] = _csrfSplitAuthority($host);
        if ($reqHost === '') {
            return false;
        }

        $headerPort  = ($port !== null && $port !== false) ? (int)$port : $defaultPort;
        $requestPort = $reqPort ?? $defaultPort;

        if (strcasecmp((string)$h, $reqHost) !== 0 || $headerPort !== $requestPort) {
            return false;
        }
        $sawHost = true;
    }

    /* (c) #1388 — BOTH absent is no longer enough on its own.
     *
     * ELI5: the custom header proves a browser didn't send this from another
     * site. It does NOT prove the request came from a browser at all. We now
     * also want to see where it came from.
     *
     * Detail: X-Requested-With alone rests entirely on the browser's inability
     * to set a custom header cross-origin without a CORS preflight this server
     * never grants. That is sound for browsers, but any non-browser client can
     * set it freely, and it leaves no positive evidence of origin. Per the Fetch
     * spec, browsers send `Origin` on EVERY request whose method is not GET or
     * HEAD — so a genuine same-origin POST from a real browser always carries
     * one, whatever the Referrer-Policy. Absent BOTH headers therefore means
     * "not a browser POST", and we fall back to requiring a valid session token
     * (checked as (a) above, which already failed if we reached here).
     * https://fetch.spec.whatwg.org/#origin-header
     *
     * Logged, not silent. This tightens the exact path #1590 built to stop the
     * sporadic editor "CSRF error", so if it ever rejects a legitimate caller
     * the operator gets a line naming the endpoint instead of a mystery. */
    if (!$sawHost) {
        error_log(sprintf(
            '[csrf] rejected %s %s — X-Requested-With present but no Origin/Referer and no valid token',
            (string)($_SERVER['REQUEST_METHOD'] ?? '?'),
            (string)($_SERVER['REQUEST_URI'] ?? '?')
        ));
        return false;
    }

    return true;
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
 * Activate or deactivate a user account — THE ONE ACCOUNT-STATE WRITER (#1698).
 *
 * ELI5: switches an account off (or back on), and while it is off, ends
 * everything it was in the middle of.
 *
 * WHY THIS IS THE ONLY PLACE `IsActive` AND `Status` ARE WRITTEN TOGETHER.
 * The two columns carry ONE fact between them, with an invariant:
 *
 *      IsActive = (Status === 'active')
 *
 * `IsActive` is what every authentication path filters on — `getAuthenticatedUser()`
 * plus thirteen enforcement points in this file — and `Status` is what
 * distinguishes a reversible DISABLE from an anonymised-tombstone ERASE. A
 * second writer that set one without the other would produce an account that
 * reads as active on one surface and closed on another, and nothing would go
 * red. So both live here, and `accountErase()` is the only other writer (it sets
 * `Status='deleted'` + `IsActive=0` in the same statement, for the same reason).
 *
 * The `Status` write is COLUMN-GATED: migrations are web-run from
 * /manage/setup-database and three docroots share one MySQL, so an un-migrated
 * install keeps the pre-#1698 behaviour exactly — `IsActive` alone, which is
 * still the lock, and `userStateFromRow()` still classifies it correctly.
 *
 * REFUSES TO REACTIVATE A TOMBSTONE. An erased account's identity is gone —
 * username replaced by a reserved placeholder, email and password hash blanked,
 * private rows deleted. Flipping `IsActive` back to 1 would produce a signed-out
 * husk that nothing can log into but that every "active users" count and
 * last-global-admin guard would believe in. Enforced HERE rather than only in
 * the admin UI, because a guard that lives in a form is a guard the API does not
 * have.
 *
 * @param int  $userId   Target user ID
 * @param bool $isActive True to activate, false to deactivate
 * @return bool True on success; FALSE when refused (target is an erased tombstone).
 */
function setUserActive(int $userId, bool $isActive): bool
{
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
        . DIRECTORY_SEPARATOR . 'user_status.php';

    $db = getDbMysqli();
    $statusReady = userStatusColumnReady($db);

    /* The tombstone guard. Only askable once the column exists — before that no
       tombstone can exist, because nothing has ever written one. */
    if ($isActive && $statusReady) {
        $stmt = $db->prepare('SELECT Status FROM tblUsers WHERE Id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row !== null && userStateFromRow(null, (string)$row[0]) === 'deleted') {
            logActivity(
                'user.activate',
                'user',
                (string)$userId,
                ['reason' => 'refused_erased_account'],
                'failure'
            );
            return false;
        }
    }

    $isActiveInt = $isActive ? 1 : 0;
    if ($statusReady) {
        /* ONE statement, so there is no window in which the two columns
           disagree — the invariant is not "kept in sync", it is written
           atomically. `StatusChangedAt` is UTC_TIMESTAMP() into a DATETIME
           (rule #20): these rows outlive the session that wrote them. */
        $newStatus = $isActive ? 'active' : 'disabled';
        $stmt = $db->prepare(
            'UPDATE tblUsers
                SET IsActive = ?, Status = ?, StatusChangedAt = UTC_TIMESTAMP(),
                    UpdatedAt = CURRENT_TIMESTAMP
              WHERE Id = ?'
        );
        $stmt->bind_param('isi', $isActiveInt, $newStatus, $userId);
    } else {
        $stmt = $db->prepare('UPDATE tblUsers SET IsActive = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
        $stmt->bind_param('ii', $isActiveInt, $userId);
    }
    $stmt->execute();
    $stmt->close();

    /* If deactivating, invalidate all their tokens */
    if (!$isActive) {
        $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        /* #1698 — end any Live Follow session this user is HOSTING. The auth
           lock already stops them broadcasting further, but a session left
           marked active keeps appearing to congregants as a live service that
           will never advance again. Ending it eagerly is the same reasoning as
           revoking the tokens above: the lock stops NEW actions, and anything
           already in flight should be closed rather than left hanging.
           Table-existence-gated — `tblLiveFollowSessions` arrives with a
           migration, and mysqli under STRICT throws on a missing table. */
        try {
            $probe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblLiveFollowSessions' LIMIT 1"
            );
            $probe->execute();
            $hasSessions = $probe->get_result()->fetch_row() !== null;
            $probe->close();
            if ($hasSessions) {
                $stmt = $db->prepare('UPDATE tblLiveFollowSessions SET IsActive = 0 WHERE HostUserId = ? AND IsActive = 1');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $_e) {
            /* Best-effort courtesy, never a reason to leave the account
               enabled: the deactivation itself has already committed. */
            error_log('[setUserActive] could not end hosted sessions: ' . $_e->getMessage());
        }
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
 * ERASE a user account — anonymised tombstone, not a row removal (#1698).
 *
 * ELI5: empties the account and blanks the person behind it, while leaving
 * anything they had already published working for everybody else.
 *
 * ⚠ THE NAME IS UNCHANGED ON PURPOSE, THE BEHAVIOUR IS NOT. This used to be
 * `DELETE FROM tblUsers WHERE Id = ?`, relying on the FK graph's cascades to
 * take the rest. Two call sites (`manage/users.php`, `admin_user_delete`) invoke
 * it and neither needed to change, so it keeps its name — but what it now does
 * is delegate to `accountErase()`, which:
 *   - deletes every private row the FK graph declares `ON DELETE CASCADE`,
 *     i.e. exactly what the old hard delete destroyed;
 *   - NULLs the behavioural/analytics columns, i.e. exactly what the old hard
 *     delete's `ON DELETE SET NULL` produced for those tables;
 *   - re-freezes the user's live share links first, so a link somebody else
 *     bookmarked keeps serving what it showed a moment ago instead of falling
 *     back to a months-old snapshot;
 *   - scrubs every PII column and leaves an inactive, anonymous tombstone row
 *     so ownership/attribution FKs keep resolving and nothing is orphaned.
 *
 * The full reasoning, including why a genuine hard purge is deliberately NOT
 * built, is in includes/account_lifecycle.php's header.
 *
 * TRANSACTION: this function owns one, because both its callers are plain page
 * actions with no surrounding transaction of their own. The self-service
 * `account_delete` endpoint deliberately does NOT come through here — it has its
 * own FOR UPDATE lock, re-auth and idempotency to keep atomic, so it calls
 * `accountErase()` inside its own transaction.
 *
 * AUDIT — the strict convention, deliberately changed. This used to log the
 * erased user's username, display name, role and email into `tblActivityLog`,
 * which partly defeats the scrub it now performs (the log outlives the account
 * and is readable by every admin). `account_delete` has always refused to, and
 * that is the convention adopted here: the numeric id ONLY. Reversing it is one
 * line, if the owner would rather have the forensics.
 *
 * @param int $userId Target user ID
 * @return bool True when the account was erased; false when there was no such user.
 * @throws AccountEraseException when the migration has not run, or the live FK
 *         graph has drifted (see accountErase()). Callers surface the message —
 *         never fall back to a hard delete.
 */
function deleteUser(int $userId): bool
{
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
        . DIRECTORY_SEPARATOR . 'account_lifecycle.php';

    $db = getDbMysqli();

    $db->begin_transaction();
    try {
        $result = accountErase($db, $userId);
        $db->commit();
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_ignore) { /* best-effort */ }
        throw $e;
    }

    /* Audit (#535/#1698) — numeric id only. No username, no email, no display
       name, no role. Same shape as `account.delete`. */
    logActivity(
        'user.delete',
        'user',
        (string)$userId,
        [
            'mode'            => 'anonymised_tombstone',
            'tokens_revoked'  => $result['tokensRevoked'],
            'shares_refrozen' => $result['sharesRefrozen'],
            'tables_cleared'  => count($result['deleted']),
            'tables_nulled'   => count($result['nulled']),
        ]
    );

    return true;
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
 * Resolve an email address to EXACTLY ONE active, email-VERIFIED account.
 *
 * ELI5: "whose account is this address? — and only answer if there is exactly
 * one answer and that person has actually proved the address is theirs."
 *
 * SECURITY — THE ACCOUNT-TAKEOVER KILL (#1635). tblUsers.Email has no UNIQUE
 * constraint (it is `NOT NULL DEFAULT ''`, and every passwordless/no-email row
 * shares ''), so an attacker can register an account carrying the VICTIM's
 * address with a password the attacker knows. Any lookup that picks a winner
 * from several matches — or that accepts an `EmailVerified = 0` row — will
 * eventually hand the victim's session, invitation or membership to that
 * planted account. Both refusals are therefore load-bearing:
 *
 *   - EXACTLY ONE match. Zero is "unknown"; two or more is UNRESOLVABLE, and
 *     guessing (the old `ORDER BY Id ASC LIMIT 1`, which deterministically
 *     preferred the OLDEST row, i.e. the attacker's) is the bug.
 *   - That one match must be EmailVerified = 1. Only the mailbox owner can
 *     flip that bit, so an unverified claim on someone else's address is
 *     worth nothing.
 *
 * This is deliberately the SAME rule the Sign-in-with-Apple auto-link already
 * applies (api.php::_authAppleMapAccountTxn() step (c), #1402) — that path was
 * hardened first and this one was not, which is what #1635 found. Keep the two
 * in step; do not invent a third rule.
 *
 * IsActive = 1 is part of the MATCH SET, not a post-filter, and that choice is
 * deliberate: a deactivated row cannot be logged into anyway, and excluding it
 * gives an admin a one-click remediation for a planted duplicate (deactivate
 * the impostor and the victim's address resolves cleanly again) instead of
 * requiring a row deletion.
 *
 * Case handling: tblUsers is `COLLATE=utf8mb4_unicode_ci` and Email carries no
 * column-level override, so `Email = ?` is already case-INSENSITIVE — which is
 * itself security-relevant (a `Victim@Example.com` plant must collide with
 * `victim@example.com`, not slip past as a distinct address). Comparing with
 * `Email = ?` rather than `LOWER(Email) = ?` keeps idx_Email usable.
 *
 * @param \mysqli     $db     Shared connection (rule #5 — never open one here).
 * @param string      $email  Address to resolve; normalised internally.
 * @param string|null $reason OUT — why null was returned, for SERVER-SIDE logs
 *                            only: 'invalid_email' | 'no_match' | 'ambiguous'
 *                            | 'unverified'. NEVER surface this to a caller:
 *                            the distinction is exactly what an attacker wants.
 * @return int|null The user's Id, or null (see $reason).
 */
function resolveVerifiedAccountByEmail(\mysqli $db, string $email, ?string &$reason = null): ?int
{
    $reason = null;
    $email  = mb_strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reason = 'invalid_email';
        return null;
    }

    /* No LIMIT and no ORDER BY on purpose — we need the COUNT of matches to
       detect ambiguity. A LIMIT 1 here would silently re-create the bug. */
    $stmt = $db->prepare('SELECT Id, EmailVerified FROM tblUsers WHERE Email = ? AND IsActive = 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($matches) === 0) { $reason = 'no_match';  return null; }
    if (count($matches) > 1)   { $reason = 'ambiguous'; return null; }
    if ((int)$matches[0]['EmailVerified'] !== 1) { $reason = 'unverified'; return null; }

    return (int)$matches[0]['Id'];
}

/**
 * Generate an email login token and 6-digit code for a given email address.
 *
 * If the email matches an existing user, the token is linked to that user.
 * Rate-limited: max 5 requests per email per hour.
 *
 * @param string $email    The email address to send the login link/code to
 * @param string $clientIp The requesting IP address (for rate limiting)
 * @return array{token: string, code: string, userId: int|null, isNewUser: bool}|null
 *               Null if rate-limited, email is invalid, or the address cannot
 *               be resolved to a single verified account (#1635). The caller
 *               MUST answer all of those identically — see api.php's
 *               `auth_email_login_request` case.
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

    /* Check if a user already exists with this email.
       #1635 (SECURITY) — this used to be
           SELECT Id FROM tblUsers WHERE Email = ? AND IsActive = 1
           ORDER BY Id ASC LIMIT 1
       whose #1093 BUG-4 rationale was only that the tiebreak be STABLE. Stable
       is not the same as safe: with no UNIQUE on Email, `ORDER BY Id ASC`
       deterministically prefers the OLDEST row — so an attacker who registers
       the victim's address BEFORE the victim always wins the tiebreak, and the
       consume path below (_completeEmailLoginTxn) then stamps EmailVerified = 1
       on the attacker's row. The victim sees a completely normal login into an
       account whose password the attacker knows.
       The fix is to stop tiebreaking at all: resolve through the shared
       verified + unambiguous resolver, and REFUSE rather than pick. */
    $resolveReason = null;
    $userId = resolveVerifiedAccountByEmail($db, $email, $resolveReason);

    if ($userId === null && $resolveReason !== 'no_match') {
        /* 'ambiguous' | 'unverified' | 'invalid_email' → refuse to mint a
           token at all. Returning null puts us on the caller's existing
           "rate limited or no such account" branch, which answers with the
           SAME non-committal 200 as every other outcome — the refusal must not
           become an oracle telling an attacker "that address is registered but
           unverified" or "that address has a duplicate". The distinction is
           recorded HERE, server-side only. (This is the log row api.php's
           `auth_email_login_request` comment has always claimed the resolver
           writes; before #1635 it did not exist.) */
        logActivity(
            'auth.login_email_request',
            '',
            '',
            ['email' => $email, 'reason' => $resolveReason],
            'failure'
        );
        return null;
    }
    /* 'no_match' keeps the pre-existing first-login behaviour: $userId stays
       null and verification creates a brand-new account for the address. */

    /* Generate token (48-char hex raw, sha256-hashed on disk) and
       code (6-digit numeric, plaintext on disk).
       Code stays plaintext on purpose: ~20 bits of entropy is too low
       for hashing to add real defence (a 1M-entry rainbow is trivial),
       so we rely on the existing single-use + 10-minute expiry +
       email-scoped lookup. The high-entropy Token is the magic-link
       leg; that one we do hash. (#898 follow-up) */
    $token = bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $token);
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
       NULL correctly with type 'i' when the bound variable is null.
       The Token column stores the SHA-256 hex hash of the raw
       token; the raw token is returned to the caller and only ever
       leaves the server inside the outbound email body. */
    $stmt = $db->prepare(
        'INSERT INTO tblEmailLoginTokens (Email, UserId, Token, Code, ExpiresAt, IpAddress)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sissss',
        $email, $userId, $tokenHash, $code, $expiresAt, $clientIp);
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
    /* Hash the supplied raw token and look up by hash (#898 follow-up).
       Raw tokens never enter the table; storing only the sha256 hex
       means a DB dump can't be replayed against the verify endpoint. */
    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare(
        'SELECT Id, Email, UserId FROM tblEmailLoginTokens
         WHERE Token = ? AND Used = 0 AND ExpiresAt > NOW()'
    );
    $stmt->bind_param('s', $tokenHash);
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
 * @param string      $email       Verified email address
 * @param int|null    $userId      Existing user ID (null if new user)
 * @param string|null $deviceName  #1409 OPTIONAL client-declared device name
 *                                 — never required, byte-identical login
 *                                 for any caller that omits it (the default
 *                                 null).
 * @param string|null $platform    #1409 OPTIONAL 'apple'|'android'|'web'.
 * @param string|null $appVersion  #1409 OPTIONAL client app version string.
 * @return array{token: string, user: array} Bearer token and user info
 */
function completeEmailLogin(string $email, ?int $userId, ?string $deviceName = null, ?string $platform = null, ?string $appVersion = null): array
{
    $db = getDbMysqli();

    /* #1011 — atomic find-or-create + token issue. Old bug: the new-user
       row was INSERTed (and committed under autocommit), then a failed
       follow-up read threw an uncaught 500 — leaving the user in the DB
       while the client saw an error. The whole flow now runs in ONE
       transaction: it either fully commits or fully rolls back, and a
       missing post-upsert row raises a clear exception instead of
       indexing a null record. (InnoDB; on a MyISAM build begin/commit are
       no-ops but the explicit null-guard still prevents the crash.) */
    $db->begin_transaction();
    try {
        $result = _completeEmailLoginTxn($db, $email, $userId);
        $db->commit();
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_ignore) { /* best-effort */ }
        throw $e;
    }

    /* #1409 — OPTIONAL device metadata, written OUTSIDE the login
       transaction (mirrors auth_login/auth_apple's own post-insert write in
       api.php): a metadata-store hiccup must never turn a successful login
       into a failed one. require_once here (not assumed pre-loaded) so this
       function stays self-sufficient regardless of which caller context
       reaches it. */
    if (!empty($result['token'])) {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                  . DIRECTORY_SEPARATOR . 'api_tokens.php';
        apiTokenDeviceMetaStore($db, hash('sha256', (string)$result['token']), $deviceName, $platform, $appVersion);
    }

    return $result;
}

/**
 * Inner body of completeEmailLogin(), executed inside the transaction
 * opened by the wrapper above. Returns { token, user } on success or
 * throws (which the wrapper rolls back). (#1011)
 *
 * @return array{token: string, user: array}
 */
function _completeEmailLoginTxn(\mysqli $db, string $email, ?int $userId): array
{
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

        /* First user gets global_admin, others get user.

           FOR UPDATE (#1388). This runs inside the #1011 transaction, but a
           plain COUNT takes no locks — under REPEATABLE READ two concurrent
           genesis logins can BOTH read 0 and both insert a global_admin. The
           window is small and only exists on a virgin install, but the payoff
           is the highest privilege in the system, and a fresh deploy where two
           people click their magic links together is exactly when it opens.

           ELI5: "nobody's here yet, so I'm the boss" — asked by two people at
           once, both hear yes. FOR UPDATE makes the second one wait.

           FOR UPDATE takes a lock the concurrent transaction must wait for, so
           the loser re-reads 1 and gets 'user'. On an empty table InnoDB takes
           a gap lock, which is what makes the zero-row case serialise at all.
           https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html */
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers FOR UPDATE');
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

    /* #1011 — never index a null row. If the just-upserted user can't be
       read back, something is wrong; throw so the wrapper rolls the whole
       flow back (no orphaned account) and the caller returns a clean
       error instead of a 500 mid-response. */
    if (!is_array($user)) {
        throw new \RuntimeException(
            'completeEmailLogin: user row not found after upsert (id ' . (int)$userId . ')'
        );
    }

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

/* =========================================================================
 * EMAIL VERIFICATION (#898)
 *
 * Single-use tokens that flip tblUsers.EmailVerified 0 -> 1 when
 * consumed. Backed by tblEmailVerificationTokens; raw token is only
 * ever in the email body (delivery via EmailService); the table
 * stores the SHA-256 hash so a DB dump never leaks live tokens.
 * Default lifetime: 24 hours.
 * ========================================================================= */

/**
 * Issue an email-verification token for a user. Caller is responsible
 * for delivering the raw token via EmailService::sendTemplate(
 * 'email-verification', ...). Any prior unused tokens for this user
 * are invalidated to keep one outstanding token per user at a time.
 *
 * @return array{token:string, expiresAt:string}|null Null if the user
 *         doesn't exist or has no email on file.
 */
function generateEmailVerificationToken(int $userId): ?array
{
    $db = getDbMysqli();

    /* Look up the email + active status. */
    $stmt = $db->prepare('SELECT Email, IsActive FROM tblUsers WHERE Id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (string)($row['Email'] ?? '') === '' || (int)$row['IsActive'] !== 1) {
        return null;
    }
    $email = (string)$row['Email'];

    /* 48-char hex (24 random bytes) raw token; SHA-256 hash on disk. */
    $token = bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $token);
    $expiresAt = gmdate('c', time() + 86400); /* 24 hours */

    /* Drop any prior outstanding tokens for this user. Single-token
       invariant matches the password-reset table's
       DELETE-then-INSERT pattern. */
    $stmt = $db->prepare('DELETE FROM tblEmailVerificationTokens WHERE UserId = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        'INSERT INTO tblEmailVerificationTokens (TokenHash, UserId, Email, ExpiresAt) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('siss', $tokenHash, $userId, $email, $expiresAt);
    $stmt->execute();
    $stmt->close();

    return ['token' => $token, 'expiresAt' => $expiresAt];
}

/**
 * Consume an email-verification token. On success, flips
 * tblUsers.EmailVerified to 1 and marks the row Used.
 *
 * @return array{userId:int, email:string}|null Null if the token is
 *         invalid, expired, already used, or the address has changed
 *         since issuance (anti-spoof: don't verify a stale address).
 */
function consumeEmailVerificationToken(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) > 64) {
        return null;
    }

    $db = getDbMysqli();
    $tokenHash = hash('sha256', $token);
    $now = gmdate('c');
    $stmt = $db->prepare(
        'SELECT t.UserId, t.Email AS TokenEmail, u.Email AS CurrentEmail
           FROM tblEmailVerificationTokens t
           JOIN tblUsers u ON u.Id = t.UserId
          WHERE t.TokenHash = ? AND t.ExpiresAt > ? AND t.Used = 0 AND u.IsActive = 1'
    );
    $stmt->bind_param('ss', $tokenHash, $now);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }
    /* Anti-spoof: the user's email must still match the address that
       was on file when the token was issued. If the user changed it
       in the meantime the old verification link must not be honoured. */
    if (mb_strtolower((string)$row['TokenEmail']) !== mb_strtolower((string)$row['CurrentEmail'])) {
        return null;
    }

    $userId = (int)$row['UserId'];
    $stmt = $db->prepare('UPDATE tblUsers SET EmailVerified = 1 WHERE Id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('UPDATE tblEmailVerificationTokens SET Used = 1 WHERE TokenHash = ?');
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $stmt->close();

    return ['userId' => $userId, 'email' => (string)$row['CurrentEmail']];
}
