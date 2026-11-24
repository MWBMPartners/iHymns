<?php

/**
 * iHymns — Privacy Policy Page Template
 *
 * PURPOSE:
 * Provides the privacy policy for the iHymns web/PWA application.
 * Covers data collection, storage, analytics, cookies, DNT support,
 * and user rights.
 *
 * Loaded via AJAX: api.php?page=privacy
 */

declare(strict_types=1);

$appName = $app["Application"]["Name"];
$vendorName = $app["Application"]["Vendor"]["Name"];
$legalEntity = $app["Application"]["Vendor"]["Parent"]["Name"] ?? $vendorName;
$appUrl = $app["Application"]["Website"]["URL"];

?>

<!-- Owner/legal review: confirm company number + registered address disclosures (UK e-commerce regs) -->

<!-- ================================================================
     PRIVACY POLICY PAGE
     ================================================================ -->
<section class="page-privacy" aria-label="Privacy Policy">

    <h1 class="h4 mb-4">
        <i class="fa-solid fa-shield-halved me-2" aria-hidden="true"></i>
        Privacy Policy
    </h1>

    <p class="text-muted small mb-4">
        Last updated: 24 November 2026
    </p>

    <!-- Overview -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">1. Overview</h2>
            <p>
                <strong><?= htmlspecialchars($appName) ?></strong> is committed to protecting
                your privacy. This policy explains what information we collect, how we use it,
                and your rights when using the <?= htmlspecialchars($appName) ?> web application,
                progressive web app (PWA), and the <?= htmlspecialchars($appName) ?> API.
            </p>
            <p>
                <?= htmlspecialchars($appName) ?> is operated by
                <strong><?= htmlspecialchars($legalEntity) ?></strong>, which is the
                <strong>data controller</strong> responsible for your personal data under
                applicable data protection law.
            </p>
            <p>
                <strong>In summary:</strong> you can browse and search <strong>anonymously</strong>,
                with your preferences and history kept on your device. If you choose to
                <strong>create an account</strong> (optional — e.g. to sync favourites and set lists
                across devices), we store the limited account data described below on our servers.
                You can download all of it, or delete your account, yourself (see section 10).
                We keep server-side collection to the minimum needed to run and secure the service,
                we do not sell your data, and we respect Do Not Track (DNT) signals.
            </p>
        </div>
    </div>

    <!-- Data We Collect -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">2. Data We Collect</h2>

            <h3 class="small fw-bold mt-3">2.1 Account Data (only if you create an account)</h3>
            <p>
                Accounts are <strong>optional</strong> — you can browse, search, and use offline
                features without one. If you choose to sign in (via a magic email link, a
                password where one has been set, or Sign in with Apple), we store the following
                on our servers:
            </p>
            <ul>
                <li><strong>Email address</strong> — used to sign you in (we email you a one-time code/link) and for essential service messages. It is never sold, and not used for marketing without your consent.</li>
                <li><strong>Profile</strong> — an optional display name, your chosen avatar source, and your preferred-languages filter.</li>
                <li><strong>Role &amp; access tier</strong> — your permission level and content-access tier; for organisation members, your organisation membership and any licence (e.g. CCLI) held by that organisation.</li>
                <li><strong>Synced content</strong> — favourites and set lists you save are synchronised to your account so they follow you across devices. Set lists may be shared via a link or collaborated on if you choose to.</li>
                <li><strong>Your choices</strong> — settings you pick while signed in follow your account to your other devices: display settings, chord style, the songbooks you opened lately, and the key, capo and octave you choose for a song.</li>
                <li>
                    <strong>Songs you open</strong> — while you are signed in we record which song you opened, when,
                    and whether it was on the website or in an app. This gives you your <strong>recently viewed</strong>
                    list on every device and the <strong>Your most viewed</strong> card on the home page, which only
                    you can see. It counts your opens on all of <?= htmlspecialchars($appName) ?>&rsquo;s sites. The public
                    &ldquo;popular&rdquo; list is made from combined totals only, counts only views on the live site,
                    and never names anyone.
                </li>
                <li>
                    <strong>Recent searches (only if you ask)</strong> — switched <strong>off</strong> unless you turn on
                    &ldquo;Keep my recent searches in my account&rdquo; in Settings. If you do, your last 20 searches are
                    kept in your account so they follow you across devices; turning it off deletes that copy from our
                    servers. Otherwise your recent searches stay on your device only.
                </li>
                <li>
                    <strong>Sign in with Apple</strong> (if you use it) — a stable, app-specific
                    Apple identifier for your account; the email Apple gives us, which may be
                    your real address or a private, forwarding &ldquo;Hide My Email&rdquo; alias
                    (ending <code>@privaterelay.appleid.com</code>); and the name Apple shares on
                    your <em>first</em> sign-in only (Apple does not send it again after that). We
                    also store a refresh token for your Apple sign-in, used solely to revoke the
                    Apple grant on Apple's side if you later delete your account or unlink Apple —
                    it is never used for anything else.
                </li>
            </ul>
            <p>
                <strong>One account on all our sites.</strong> <?= htmlspecialchars($appName) ?>&rsquo;s live site and its
                test sites (dev and beta) share one account database. The same account works on all of them, and
                anything you save while signed in on one is kept in that one account. (If saving to accounts has been
                paused on a site for maintenance, what you save there stays on your device until it is switched back
                on, and the site will say so before you sign out.)
            </p>
            <p>
                <strong>Searches are not linked to you.</strong> When you search, the words you typed are counted
                for site statistics (for example, to see what people look for) but are recorded
                <em>without</em> your name or account, even when you are signed in.
            </p>
            <p>You can download or delete your account data yourself — see <em>Your Rights</em> below.</p>

            <h3 class="small fw-bold mt-3">2.2 Data Stored Locally on Your Device</h3>
            <p>Whether or not you have an account, the following is kept in your browser. It is not sent to our servers unless you are signed in and syncing:</p>
            <ul>
                <li><strong>Recently viewed songs</strong> and <strong>recent searches</strong> (for quick access + search suggestions)</li>
                <li><strong>Display settings</strong> — theme, font size, motion/transparency, and other display options</li>
                <li><strong>Recent songbooks</strong> (also kept in your account if you are signed in), your analytics-consent choice, the PWA-install-banner and disclaimer-acceptance flags</li>
                <li>For <strong>signed-out</strong> users, your <strong>favourites and set lists</strong> are stored only on your device</li>
            </ul>
            <p>You can clear this at any time via the Settings page or by clearing your browser data.</p>
            <p>
                <strong>When you sign out.</strong> Once saving to your account is switched on for the site you are
                using, signing out on purpose also clears your personal data from <em>that browser</em>: your
                favourites, set lists, tags, history, recent songbooks and, if you keep them in your account, your recent
                searches. They stay safe in your account and come back when you sign in again. (Until saving to your
                account is switched on for that site, signing out leaves your lists in the browser, as it always did.)
                So that nothing is lost, we first send anything that has not reached your account yet; if something is
                still left (for example you have no signal), we tell you and you can try again, sign out anyway, or stay
                signed in. Your display settings stay on the browser. If your session ends by itself (for example it
                expires) your data is set aside in the browser instead, and given back when you sign in again; if
                someone else signs in first, it is kept away from them (one person&rsquo;s set-aside copy is kept at a
                time). If you then sign out on purpose, that set-aside copy is deleted as well.
            </p>
            <p>
                <strong>Signed-in devices.</strong> Once this is switched on for the site, each device you sign in on
                (each Apple device included) gets its own sign-in, so signing out of one does not sign out the others.
                In Settings, under Signed-in devices, you can see your devices, sign any one of them out, or choose
                Sign out everywhere to end every sign-in at once, this browser included. A device&rsquo;s sign-in ends
                by itself after 30 days without being used. If you use the Apple app, it can keep an automatic sign-in
                key in your iCloud Keychain, so a new Apple device of yours can get a sign-in of its own without you
                typing your password again. That key cannot be used to sign in anywhere else: all it can do is give one
                of your own devices a sign-in of its own. It lasts 180 days from the last time it was used, and you
                can end it with Turn off in the same place. Signing out any one of your devices, signing out
                everywhere, changing or resetting your password, or switching your account off also ends it. (The
                devices you did not sign out stay signed in, and a device that is still signed in can make a fresh key
                for itself.) Whoever holds a live key could get a sign-in for your account, which is why ending it is
                so easy. A device that was signed out, or whose sign-in
                ended after 30 days unused, does not sign itself back in: you sign in again on it yourself.
                (Until this is switched on for the site, your Apple devices share one sign-in, as they always did.)
            </p>

            <h3 class="small fw-bold mt-3">2.3 Server Logs &amp; Security Data</h3>
            <p>To keep the service secure and available, our servers process a limited amount of technical data:</p>
            <ul>
                <li><strong>IP address</strong> — used transiently for abuse-prevention and <strong>rate-limiting</strong> of heavy or automated requests. It is not used to build a profile of you, and is retained only as long as needed for security.</li>
                <li><strong>Request metadata</strong> — timestamps and the requested URL, in ordinary web-server logs.</li>
                <li><strong>Administrative audit log</strong> — for signed-in administrators, significant actions (logins, content edits, restores, media uploads) are recorded for accountability.</li>
            </ul>

            <h3 class="small fw-bold mt-3">2.4 Analytics Consent</h3>
            <p>
                Analytics tracking is <strong>disabled by default</strong>. When you first visit
                <?= htmlspecialchars($appName) ?>, you may be shown a consent banner asking whether
                you wish to allow analytics. You can:
            </p>
            <ul>
                <li><strong>Accept:</strong> Analytics services will be activated for your session</li>
                <li><strong>Decline:</strong> No analytics data will be collected (privacy-focused services like Plausible may still run as they do not use cookies)</li>
            </ul>
            <p>
                You can change your analytics preference at any time from the <strong>Settings &gt; Privacy</strong>
                section. Your consent choice is stored locally in your browser and is never sent to our servers.
            </p>

            <h3 class="small fw-bold mt-3">2.5 Data Collected via Analytics (When Consented)</h3>
            <p>If analytics services are enabled, we may collect:</p>
            <ul>
                <li>Pages visited and general usage patterns (aggregate, non-personal)</li>
                <li>Browser type and version</li>
                <li>Device type (mobile, desktop, tablet)</li>
                <li>Operating system</li>
                <li>Referring website</li>
                <li>Country/region (derived from IP, not stored individually)</li>
            </ul>
            <p>Our <strong>analytics</strong> do <strong>not</strong> capture your name, email, account contents, favourites, or search queries — analytics data is aggregate and non-identifiable, and is separate from the account data in 2.1.</p>

            <h3 class="small fw-bold mt-3">2.6 API Access (Developers)</h3>
            <p>
                <?= htmlspecialchars($appName) ?> offers an API that approved developers may use with an
                <strong>API key</strong> (issued or approved by an administrator). If you hold a key:
            </p>
            <ul>
                <li>The key is stored only as a <strong>hash</strong> with a short non-secret prefix, a label, and the scopes it is granted — never in plain text.</li>
                <li>We log per-key <strong>usage counts</strong> and the <strong>last-used time and IP</strong> for security, abuse-prevention, and rate-limiting.</li>
                <li>A key grants access only to the data its scopes allow — by default, the <strong>public catalogue</strong>. Copyrighted or otherwise gated content is never available through the public scopes; any access to gated content requires a separate, explicitly-granted scope under an appropriate licence.</li>
            </ul>

            <h3 class="small fw-bold mt-3">2.7 Service Mode (Live-Follow)</h3>
            <p>
                When a congregation uses <strong>Service Mode</strong> to follow a live service, a
                participant's device is identified only by an <strong>opaque presence token</strong>
                (plus a device-generated id) so the song display can stay in sync. This involves
                <strong>no personal information</strong> and no location tracking — presence is proven by
                the venue's rotating code, not by your location. The token expires automatically when the
                service ends or you leave.
            </p>
        </div>
    </div>

    <!-- Do Not Track -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">3. Do Not Track (DNT)</h2>
            <p>
                <?= htmlspecialchars($appName) ?> <strong>respects the Do Not Track</strong>
                (DNT) signal sent by your browser.
            </p>
            <p>When DNT is enabled:</p>
            <ul>
                <li>Analytics services still run for aggregate statistics (e.g., total page views)</li>
                <li>Your <strong>IP address is anonymised</strong> — it is not logged or stored individually</li>
                <li>No cookies are set for tracking purposes</li>
                <li>No personally identifiable information is collected</li>
            </ul>
            <p>
                You can enable DNT in your browser's privacy settings. Most modern browsers
                support this feature.
            </p>
        </div>
    </div>

    <!-- Cookies & Cross-Domain Sync -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">4. Cookies &amp; Synchronisation Between Our Sites</h2>
            <p>
                <?= htmlspecialchars($appName) ?> uses first-party cookies sparingly:
            </p>
            <ul>
                <li><strong>Sign-in cookie</strong> (<code>ihymns_auth</code>) — set <strong>only when you sign in</strong>. It keeps you signed in across <?= htmlspecialchars($appName) ?> subdomains (on <code>.ihymns.app</code>); it is <code>HttpOnly</code>, <code>Secure</code>, and <code>SameSite=Lax</code>, has a sliding 30-day expiry, and holds only an opaque session token — no personal data.</li>
                <li><strong>Display preferences</strong> (<code>ihymns_prefs</code>) — lets the display choices you make follow you between <?= htmlspecialchars($appName) ?>&rsquo;s own sites. It is set <strong>only after you change one of those choices</strong> (or reset them), is shared by all of <?= htmlspecialchars($appName) ?>&rsquo;s sites on <code>.ihymns.app</code> <strong>including its test sites</strong> (dev and beta), and lasts up to a year, renewed each time you visit. It holds only the choices themselves: your theme, text size, motion and transparency settings, colour-blindness filter, link emphasis, page transition, default songbook, auto-update and live-search choices, keyboard shortcuts, lyric display settings and the live-session idle time. It never holds your favourites, set lists, history, sign-in details or consent choices. It is only used on a site where it has been switched on; elsewhere the older cookie below is used.</li>
                <li><strong>Older preferences cookie</strong> (<code>ihymns_sync</code>) — the earlier version of the same idea, still used on any site where the newer cookie is not yet switched on. It holds only a few of the same display settings, and no personal or tracking data.</li>
            </ul>
            <p>
                You can clear these at any time: choose <strong>Reset all settings</strong> on the Settings
                page (which also tells the other sites to forget your choices), or delete the cookies in your
                browser. If you are signed in, your display settings are also kept on your account so they
                follow you to other devices; signing out does not remove them from your account or from the browser.
            </p>
            <p>
                Third-party analytics services (if enabled and consented to) may set their own
                cookies in accordance with their respective privacy policies. When DNT is active,
                third-party tracking cookies are suppressed where possible.
            </p>
        </div>
    </div>

    <!-- Service Worker & Offline Data -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">5. Service Worker &amp; Offline Data</h2>
            <p>
                As a Progressive Web App, <?= htmlspecialchars($appName) ?> uses a service
                worker to cache application assets for offline use. This cached data:
            </p>
            <ul>
                <li>Includes application code, styles, and song data</li>
                <li>May include individual songs or entire songbooks if you choose to download them for offline use</li>
                <li>Does not include any personal information</li>
                <li>Is stored locally in your browser's cache storage</li>
                <li>Can be cleared at any time via Settings or your browser settings</li>
            </ul>
        </div>
    </div>

    <!-- Sharing Features -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">6. Sharing Features</h2>
            <p>
                <?= htmlspecialchars($appName) ?> provides several sharing features. When you use them,
                you control what is shared:
            </p>
            <ul>
                <li><strong>Share Song:</strong> Uses your device's native share sheet (Web Share API)
                    or copies song details to clipboard. Only the song title, songbook name, and a link are shared — no personal data.</li>
                <li><strong>Shareable Set Lists:</strong> When you share a set list, the song IDs and
                    set list name are encoded into the URL. Anyone with the link can view the set list.
                    No personal data is included in the shared link.</li>
                <li><strong>Copy Song Details:</strong> Copies song metadata (title, writer, composer) to
                    your clipboard. This data does not leave your device unless you paste it elsewhere.</li>
            </ul>
        </div>
    </div>

    <!-- Security Measures -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">7. Security Measures</h2>
            <p>
                <?= htmlspecialchars($appName) ?> implements several technical measures to protect
                your browsing experience:
            </p>
            <ul>
                <li><strong>Content Security Policy (CSP):</strong> Restricts which scripts, styles, and connections are allowed, preventing cross-site scripting attacks</li>
                <li><strong>Subresource Integrity (SRI):</strong> Verifies that third-party libraries loaded from CDNs have not been tampered with</li>
                <li><strong>HTTPS:</strong> All data in transit is encrypted via TLS</li>
                <li><strong>Database security:</strong> Account data is stored in a database accessed exclusively through parameterised (prepared) statements; passwords (where set) are hashed, and session / API tokens are stored only as hashes — never in plain text.</li>
                <li><strong>Access control &amp; CSRF protection:</strong> Account and administrative actions are gated by role-based permissions and same-origin CSRF checks; database error details are never exposed to clients.</li>
            </ul>
        </div>
    </div>

    <!-- Third-Party Services -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">8. Third-Party Services</h2>
            <p>
                <?= htmlspecialchars($appName) ?> may use the following third-party services,
                each governed by their own privacy policies:
            </p>
            <ul>
                <li><strong>CDN providers</strong> (jsDelivr, cdnjs) — for loading libraries including Bootstrap, Font Awesome, Bootstrap Icons, Tone.js, and PDF.js. These providers may log access in their server logs.</li>
                <li>
                    <strong>Apple — Sign in with Apple</strong> (if you use it) — authentication only.
                    Signing in with Apple on the web loads Apple's own JavaScript SDK from
                    <code>appleid.cdn-apple.com</code>; Apple issues the sign-in credential directly
                    to your browser, which we then verify. See Apple's own privacy policy for how
                    Apple itself handles this. <a href="https://www.apple.com/legal/privacy/" target="_blank" rel="noopener noreferrer">Privacy policy</a>
                </li>
                <li><strong>Google Analytics 4</strong> (if enabled and consented) — anonymous usage statistics. <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Privacy policy</a></li>
                <li><strong>Microsoft Clarity</strong> (if enabled and consented) — anonymous usage heatmaps. <a href="https://privacy.microsoft.com/privacystatement" target="_blank" rel="noopener noreferrer">Privacy policy</a></li>
                <li><strong>Plausible Analytics</strong> (if enabled) — privacy-focused, cookieless analytics. <a href="https://plausible.io/data-policy" target="_blank" rel="noopener noreferrer">Data policy</a></li>
                <li><strong>Matomo</strong> (if enabled) — self-hosted analytics</li>
                <li><strong>Fathom Analytics</strong> (if enabled) — privacy-focused analytics. <a href="https://usefathom.com/legal/privacy" target="_blank" rel="noopener noreferrer">Privacy policy</a></li>
                <li>
                    <strong>Avatar resolvers</strong> (signed-in users only) —
                    a SHA-256 hash of your email address may be sent to one of
                    Gravatar (default), Libravatar, or DiceBear so that your
                    circular avatar can be resolved. Gravatar / Libravatar
                    return your registered avatar (or a generated identicon);
                    DiceBear renders a deterministic geometric pattern from
                    the hash without performing a registered-avatar lookup.
                    The full email never leaves <?= htmlspecialchars($appName) ?>'s servers — only its hash. You can change your
                    avatar source — or disable third-party requests entirely —
                    in Settings &gt; Profile &gt; Avatar source.
                    <a href="https://gravatar.com/support/data-and-privacy/" target="_blank" rel="noopener noreferrer">Gravatar privacy</a> ·
                    <a href="https://www.libravatar.org/privacy/" target="_blank" rel="noopener noreferrer">Libravatar privacy</a> ·
                    <a href="https://www.dicebear.com/legal/privacy-policy/" target="_blank" rel="noopener noreferrer">DiceBear privacy</a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Data Retention -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">9. Data Retention</h2>
            <p>
                We retain <strong>account data</strong> (Section 2.1) only for as long as your
                account is active. If you delete your account, your account record and synced
                content are removed from our live database; some entries may persist briefly in
                encrypted backups before those are rotated. The record of which songs you opened is
                kept only with your account number removed, and anything you
                shared earlier (such as a set-list link) stays visible to the people who have it but
                can no longer be changed.
            </p>
            <p>
                <strong>Security &amp; rate-limiting data</strong> (Section 2.3), including IP
                addresses, is retained only as long as needed for abuse-prevention and is then
                discarded; administrative audit-log entries are kept for accountability.
            </p>
            <p>
                <strong>Analytics data</strong> (if collected) is retained by the respective
                analytics provider in accordance with their retention policies and is aggregated
                and non-identifiable.
            </p>
        </div>
    </div>

    <!-- Your Rights -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">10. Your Rights</h2>
            <p>You have the right to:</p>
            <ul>
                <li><strong>Control analytics:</strong> Accept or decline analytics via the consent banner, or change your preference in Settings &gt; Privacy at any time</li>
                <li><strong>Clear your data:</strong> All locally stored data can be cleared via Settings &gt; Data &amp; Storage</li>
                <li><strong>Enable DNT:</strong> Activate Do Not Track in your browser to anonymise any analytics</li>
                <li><strong>Block analytics:</strong> Use browser extensions (e.g., uBlock Origin) to block analytics entirely</li>
                <li><strong>Access &amp; export your data:</strong> Export your locally stored data via Settings. If you have an account, go to <strong>Settings &gt; Your data</strong> and choose <strong>Download everything we hold about you</strong>: one file with your account details, favourites, set lists, settings and history. It never includes passwords, sign-in codes or keys. You can ask for it a few times an hour.</li>
                <li><strong>Delete your account:</strong> Permanently delete your account yourself, on the
                    website (<strong>Settings &gt; Your data</strong>, then <strong>Delete my account</strong>) or in the
                    iHymns app for iPhone, iPad, or Apple TV (Account &gt; Danger Zone). Either way you confirm it is
                    you first, with your password or an emailed code, and on the website you also type the word
                    DELETE. This removes your account
                    record and synced data from our live database and revokes any linked Sign in with Apple grant.
                    If you cannot sign in, use the contact link below</li>
                <li><strong>Uninstall:</strong> Remove the PWA from your device at any time</li>
            </ul>
        </div>
    </div>

    <!-- Changes -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">11. Changes to This Policy</h2>
            <p>
                We may update this Privacy Policy from time to time. Continued use of
                <?= htmlspecialchars($appName) ?> after changes are posted constitutes
                acceptance of the revised policy.
            </p>
        </div>
    </div>

    <!-- Contact -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">12. Contact</h2>
            <p>
                If you have questions about this Privacy Policy, please visit
                <a href="<?= htmlspecialchars($app["Application"]["Repo"]["Issues"]["URL"]) ?>"
                   target="_blank" rel="noopener noreferrer">
                    our GitHub repository
                </a>.
            </p>
        </div>
    </div>

</section>
