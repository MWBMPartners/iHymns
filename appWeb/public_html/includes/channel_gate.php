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

    $entitlement = ($devStatus === 'Alpha') ? 'access_alpha' : 'access_beta';
    $role        = _channelGateCurrentRole();

    if (userHasEntitlement($entitlement, $role)) {
        return; /* Pass-through for entitled users. */
    }

    /* Render the gate page and short-circuit index.php. */
    _renderChannelGate($devStatus);
    exit;
}

function _renderChannelGate(string $channel): void
{
    http_response_code(401);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    $label = htmlspecialchars($channel);
    ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iHymns <?= $label ?> — Early Access</title>
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
        .gate-badge {
            display: inline-block;
            background: linear-gradient(135deg, var(--accent-start), var(--accent-end));
            color: #fff;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-size: 0.75rem;
            padding: 0.2em 0.8em;
            border-radius: 999px;
        }
        h1 { font-size: 1.5rem; margin: 1rem 0 0.5rem; }
    </style>
</head>
<body>
    <div class="gate-card">
        <span class="gate-badge"><?= $label ?> · Early Access</span>
        <h1>iHymns <?= $label ?> is invite-only</h1>
        <p class="text-muted">
            This build is a pre-release preview. Sign in with an account
            that has <code><?= $channel === 'Alpha' ? 'access_alpha' : 'access_beta' ?></code>
            privileges to continue.
        </p>
        <p class="text-muted small">
            Don't have an account yet? You can sign up below, and an admin
            can grant you early-access on the
            <code>/manage/entitlements</code> page.
        </p>
        <div id="gate-msg" class="alert d-none py-2" role="alert"></div>

        <!-- Step 1 — request magic link / code -->
        <form id="gate-email-form" class="d-grid gap-2 text-start">
            <label for="gate-email" class="form-label small mb-0">Email address</label>
            <input type="email" id="gate-email" class="form-control" required autocomplete="email"
                   placeholder="you@example.com">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-paper-plane me-1"></i>
                Send login code
            </button>
        </form>

        <!-- Step 2 — verify 6-digit code (hidden until we've sent the email) -->
        <form id="gate-code-form" class="d-grid gap-2 text-start d-none mt-2">
            <label for="gate-code" class="form-label small mb-0">6-digit code</label>
            <input type="text" id="gate-code" class="form-control text-center" maxlength="6"
                   pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required
                   placeholder="000000">
            <button type="submit" class="btn btn-success">
                <i class="fa-solid fa-check me-1"></i>
                Verify &amp; continue
            </button>
        </form>

        <a class="btn btn-link btn-sm mt-2" href="https://ihymns.app/">Go to the stable site</a>
    </div>

<script>
(function () {
    const msg   = document.getElementById('gate-msg');
    const show  = (text, kind) => {
        msg.className = 'alert py-2 alert-' + kind;
        msg.textContent = text;
        msg.classList.remove('d-none');
    };
    const emailForm = document.getElementById('gate-email-form');
    const codeForm  = document.getElementById('gate-code-form');
    let rememberedEmail = '';

    emailForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('gate-email').value.trim();
        if (!email) return;
        rememberedEmail = email;
        try {
            const res = await fetch('/api?action=auth_email_login_request', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ email }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Could not send code.');
            show('Code sent. Check your inbox and enter the 6-digit code.', 'info');
            codeForm.classList.remove('d-none');
            document.getElementById('gate-code').focus();
        } catch (err) {
            show(err.message, 'danger');
        }
    });

    codeForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const code = document.getElementById('gate-code').value.trim();
        if (!code || !rememberedEmail) return;
        try {
            const res = await fetch('/api?action=auth_email_login_verify', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ email: rememberedEmail, code }),
            });
            const data = await res.json();
            if (!res.ok || !data.token) throw new Error(data.error || 'Invalid code.');
            /* Server has set the HttpOnly cookie; a reload will pass the
               gate if the user has the relevant access entitlement. */
            show('Verified. Redirecting…', 'success');
            setTimeout(() => window.location.reload(), 600);
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
