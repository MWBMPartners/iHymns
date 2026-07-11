<?php

/**
 * iHymns — Device-Code Pairing "Link a device" page (#1407)
 *
 * PURPOSE:
 * ELI5: this is the page a human lands on to approve a TV (or any other
 * limited-input device) signing in to their iHymns account. The TV shows a
 * short code on-screen; the human comes here (by typing the URL, scanning a
 * QR code, or following the `verification_uri_complete` deep link the TV's
 * own on-screen instructions give), enters that code, and taps Approve.
 *
 * DETAILED:
 * Server-rendered shell only — the "look up this code" / "approve" / "deny"
 * work happens client-side via js/modules/device-link.js (SPA fragment
 * pattern, mirrors settings.js/setlist.js: this template renders static
 * markup + an optional prefill, the JS module does the fetch()es against
 * `?action=auth_device_code_link_lookup` / `_approve` / `_deny`). Loaded via
 * AJAX: `/api?page=link`.
 *
 * Auth-required + per-user (which device is pending, whether Approve/Deny
 * succeeded) — like settings.php/favorites.php/setlist.php this page is
 * deliberately NOT in api.php's `$_cacheablePages` allow-list, so it is
 * never served from the shared-fragment ETag cache.
 *
 * The `?user_code=` query param is the `verification_uri_complete` deep-link
 * shape RFC 8628 §3.3 describes (optional convenience — a plain
 * `verification_uri` + manually-typed code always works too). We prefill the
 * input with it, normalised through the SAME parser the server-side lookup/
 * approve/deny endpoints use, so a garbled or bogus query string just yields
 * an empty (not broken) prefill rather than echoing raw attacker-controlled
 * text into the page.
 *
 * @link https://www.rfc-editor.org/rfc/rfc8628#section-3.3  verification_uri_complete
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'device_code.php';

/**
 * Variables provided by the calling context (api.php) before this template
 * is included. Declared here so intelephense can resolve the references in
 * the body of the file (#634); the runtime injection itself is unchanged.
 *
 * @var array $app Parsed iHymns application metadata — unused by this
 *                  template today, kept for parity with sibling page
 *                  partials that DO read $app.
 */

/* deviceCode_normaliseUserCode() both validates AND reformats (uppercase,
   dash-inserted) — an invalid/garbled/absent query param safely collapses
   to '' (empty prefill), never a raw echo of untrusted input. */
$_prefillUserCode = deviceCode_normaliseUserCode($_GET['user_code'] ?? '');

?>
<section class="page-link" aria-label="Link a device">

    <h1 class="h4 mb-3">
        <i class="fa-solid fa-tv me-2" aria-hidden="true"></i>
        Link a Device
    </h1>

    <p class="text-muted small mb-4">
        Enter the code shown on your TV (or other device) to sign it in to
        your iHymns account.
    </p>

    <!-- Shown when the visitor isn't signed in; device-link.js toggles
         visibility based on window.iHymnsApp.userAuth.isLoggedIn(). -->
    <div id="link-signed-out" class="alert alert-info d-none" role="alert">
        <p class="mb-2">Sign in to approve a device.</p>
        <button type="button" class="btn btn-sm btn-outline-primary" id="link-login-btn">
            Sign In
        </button>
    </div>

    <!-- Shown once signed in; device-link.js toggles visibility. -->
    <div id="link-signed-in-wrap" class="d-none">
        <form id="link-code-form" class="mb-3" autocomplete="off" novalidate>
            <label for="link-user-code" class="form-label">Device code</label>
            <input type="text" class="form-control text-uppercase" id="link-user-code" name="user_code"
                   placeholder="ABCD-EFGH" maxlength="9"
                   value="<?= htmlspecialchars($_prefillUserCode, ENT_QUOTES, 'UTF-8') ?>"
                   inputmode="text" autocapitalize="characters" autocorrect="off" spellcheck="false" required>
            <button type="submit" class="btn btn-primary mt-2" id="link-lookup-btn">
                <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>
                Look Up
            </button>
        </form>

        <!-- device-link.js renders the "found — Approve/Deny" confirmation,
             or an opaque not-found/expired message, into this region. -->
        <div id="link-result" aria-live="polite"></div>
    </div>

</section>
