<?php

declare(strict_types=1);

/**
 * iHymns — Channel Access Gate (#407)
 *
 * Gates alpha / beta subdomain access behind the `access_alpha` /
 * `access_beta` entitlements. Production (`ihymns.app`) is always
 * open. Called from index.php after auth + entitlement helpers are
 * loaded.
 *
 * Behaviour:
 *   - Production channel:                do nothing.
 *   - Alpha/Beta + user has entitlement: do nothing.
 *   - Alpha/Beta + no entitlement:       render the gate page + exit.
 *
 * The gate page explains that this is a pre-release build and offers
 * a sign-in link. Requests for the API, service worker, og-image,
 * sitemap, manifest and static assets pass through untouched so that
 * the sign-in round-trip + magic-link flow still works.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'entitlements.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
          . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

/**
 * Resolve the authenticated user from the bearer-cookie set by the
 * public API. Returns null for guests. Kept intentionally minimal —
 * index.php doesn't need the full user object, only the role.
 */
function _channelGateCurrentRole(): ?string
{
    /* Cookie issued by api.php on auth_login/register/magic-link (#390) */
    $token = $_COOKIE['ihymns_auth'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }
    try {
        $db = getDb();
        $stmt = $db->prepare(
            'SELECT u.Role
               FROM tblApiTokens t
               JOIN tblUsers u ON u.Id = t.UserId
              WHERE t.Token = ? AND t.ExpiresAt > ? AND u.IsActive = 1'
        );
        $stmt->execute([hash('sha256', $token), gmdate('c')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (string)$row['Role'] : null;
    } catch (\Throwable $_e) {
        return null;
    }
}

/**
 * Main entry point. $devStatus is 'Alpha', 'Beta', or null (production).
 */
function enforceChannelGate(?string $devStatus): void
{
    if ($devStatus !== 'Alpha' && $devStatus !== 'Beta') {
        return; /* Production — never gated. */
    }

    /* ----------------------------------------------------------------
     * TEMPORARILY DISABLED
     * ----------------------------------------------------------------
     * The gate is off while admin accounts and role/entitlement
     * mappings are being set up properly (the first admin account was
     * created as `admin` rather than an email address, and nobody can
     * sign in through the gate until that's fixed).
     *
     * To re-enable invite-only gating once setup is complete:
     *   1. Delete this early return.
     *   2. Visit /manage/entitlements and flick the "Enforce
     *      invite-only access" switch on.
     * ---------------------------------------------------------------- */
    return;

    /* Bootstrap mode: the gate stays open until an admin explicitly
       turns it on, so the first admin in can sign in and configure
       role-based access without locking themselves out. */
    if (!isChannelGateEnabled()) {
        return;
    }

    $entitlement = ($devStatus === 'Alpha') ? 'access_alpha' : 'access_beta';
    $role        = _channelGateCurrentRole();

    if (userHasEntitlement($entitlement, $role)) {
        return; /* Pass-through for entitled users. */
    }

    /* Render the gate page and short-circuit index.php. */
    _renderChannelGate();
    exit;
}

function _renderChannelGate(): void
{
    http_response_code(401);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iHymns — Sign in</title>
    <meta name="robots" content="noindex, nofollow">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .gate-card {
            background-color: var(--surface-card);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            box-shadow: var(--card-shadow);
            max-width: 460px;
            width: 100%;
            padding: 2rem;
            text-align: center;
        }
        h1 { font-size: 1.5rem; margin: 1rem 0 0.5rem; }
    </style>
</head>
<body>
    <div class="gate-card">
        <h1>Restricted access</h1>
        <p class="text-muted">
            Please sign in to continue.
        </p>
        <div id="gate-msg" class="alert d-none py-2" role="alert"></div>

        <form id="gate-login-form" class="d-grid gap-2 text-start">
            <label for="gate-username" class="form-label small mb-0">Username</label>
            <input type="text" id="gate-username" class="form-control" required
                   autocomplete="username" autocapitalize="none" spellcheck="false">

            <label for="gate-password" class="form-label small mb-0 mt-1">Password</label>
            <input type="password" id="gate-password" class="form-control" required
                   autocomplete="current-password">

            <button type="submit" class="btn btn-primary mt-2">
                Sign in
            </button>
        </form>

        <a class="btn btn-link btn-sm mt-2" href="https://ihymns.app/">Go to the stable site</a>
    </div>

<script>
(function () {
    const msg  = document.getElementById('gate-msg');
    const show = (text, kind) => {
        msg.className = 'alert py-2 alert-' + kind;
        msg.textContent = text;
        msg.classList.remove('d-none');
    };
    const form = document.getElementById('gate-login-form');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('gate-username').value.trim();
        const password = document.getElementById('gate-password').value;
        if (!username || !password) return;
        try {
            const res = await fetch('/api?action=auth_login', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ username, password }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Sign-in failed.');
            /* Server has set the HttpOnly auth cookie; reload and the
               gate will re-evaluate against the new session. */
            show('Signed in. Redirecting…', 'success');
            setTimeout(() => window.location.reload(), 400);
        } catch (err) {
            show(err.message, 'danger');
        }
    });
})();
</script>

</body>
</html>
    <?php
}
