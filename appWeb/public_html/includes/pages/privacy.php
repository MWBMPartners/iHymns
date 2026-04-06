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
$appUrl = $app["Application"]["Website"]["URL"];

?>

<!-- ================================================================
     PRIVACY POLICY PAGE
     ================================================================ -->
<section class="page-privacy" aria-label="Privacy Policy">

    <h1 class="h4 mb-4">
        <i class="fa-solid fa-shield-halved me-2" aria-hidden="true"></i>
        Privacy Policy
    </h1>

    <p class="text-muted small mb-4">
        Last updated: <?= date('j F Y') ?>
    </p>

    <!-- Overview -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">1. Overview</h2>
            <p>
                <strong><?= htmlspecialchars($appName) ?></strong> is committed to protecting
                your privacy. This policy explains what information we collect, how we use it,
                and your rights regarding your data when using the
                <?= htmlspecialchars($appName) ?> web application and progressive web app (PWA).
            </p>
            <p>
                <strong>In summary:</strong> We collect minimal data, store preferences locally
                on your device, and respect Do Not Track (DNT) signals.
            </p>
        </div>
    </div>

    <!-- Data We Collect -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">2. Data We Collect</h2>

            <h3 class="small fw-bold mt-3">2.1 Data Stored Locally on Your Device</h3>
            <p>The following data is stored in your browser's local storage and <strong>never</strong> sent to our servers:</p>
            <ul>
                <li><strong>Favourites:</strong> Your saved favourite songs and any tags/categories you assign</li>
                <li><strong>Set Lists:</strong> Custom song collections you create for worship services</li>
                <li><strong>Browsing History:</strong> Recently viewed songs (for quick access on the home page)</li>
                <li><strong>Search History:</strong> Recent search queries (for search suggestions)</li>
                <li><strong>Display Settings:</strong> Theme preference, font size, motion/transparency preferences, and other display options</li>
                <li><strong>Recent Songbooks:</strong> Which songbooks you have recently browsed</li>
                <li><strong>Analytics Consent:</strong> Your choice to accept or decline analytics tracking</li>
                <li><strong>PWA Install Banner:</strong> Whether you have dismissed the install prompt</li>
                <li><strong>Disclaimer Acceptance:</strong> Whether you have accepted the terms of use disclaimer</li>
            </ul>
            <p>This data remains entirely on your device. You can clear it at any time via the Settings page or by clearing your browser data.</p>

            <h3 class="small fw-bold mt-3">2.2 Analytics Consent</h3>
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

            <h3 class="small fw-bold mt-3">2.3 Data Collected via Analytics (When Consented)</h3>
            <p>If analytics services are enabled, we may collect:</p>
            <ul>
                <li>Pages visited and general usage patterns (aggregate, non-personal)</li>
                <li>Browser type and version</li>
                <li>Device type (mobile, desktop, tablet)</li>
                <li>Operating system</li>
                <li>Referring website</li>
                <li>Country/region (derived from IP, not stored individually)</li>
            </ul>
            <p>We do <strong>not</strong> collect or store:</p>
            <ul>
                <li>Your name, email, or any personally identifiable information</li>
                <li>Your favourites, search queries, or browsing history</li>
                <li>Any form of user account data (there are no user accounts)</li>
            </ul>
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
            <h2 class="h6 mb-3">4. Cookies &amp; Cross-Domain Synchronisation</h2>
            <p>
                <?= htmlspecialchars($appName) ?> uses <strong>one first-party cookie</strong>
                (<code>ihymns_sync</code>) solely for synchronising your display preferences
                (theme, font size, motion settings) across <?= htmlspecialchars($appName) ?>
                subdomains (e.g., <code>beta.ihymns.net</code>, <code>ihymns.app</code>). This
                cookie contains only your settings preferences — no personal or tracking data.
            </p>
            <p>
                Additionally, a lightweight cross-domain storage bridge (using a hidden iframe)
                may be used to keep your localStorage preferences in sync if you access
                <?= htmlspecialchars($appName) ?> from multiple subdomains. This mechanism
                transfers only your app settings and never collects personal information.
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
                <li><strong>No Server-Side Storage:</strong> No personal data is stored on our servers — all user data remains in your browser</li>
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
                <li><strong>CDN providers</strong> (jsDelivr, cdnjs) — for loading libraries including Bootstrap, jQuery, Font Awesome, Animate.css, Fuse.js, Tone.js, and PDF.js. These providers may log access in their server logs.</li>
                <li><strong>Google Analytics 4</strong> (if enabled and consented) — anonymous usage statistics. <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Privacy policy</a></li>
                <li><strong>Microsoft Clarity</strong> (if enabled and consented) — anonymous usage heatmaps. <a href="https://privacy.microsoft.com/privacystatement" target="_blank" rel="noopener noreferrer">Privacy policy</a></li>
                <li><strong>Plausible Analytics</strong> (if enabled) — privacy-focused, cookieless analytics. <a href="https://plausible.io/data-policy" target="_blank" rel="noopener noreferrer">Data policy</a></li>
                <li><strong>Matomo</strong> (if enabled) — self-hosted analytics</li>
                <li><strong>Fathom Analytics</strong> (if enabled) — privacy-focused analytics. <a href="https://usefathom.com/legal/privacy" target="_blank" rel="noopener noreferrer">Privacy policy</a></li>
            </ul>
        </div>
    </div>

    <!-- Data Retention -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">9. Data Retention</h2>
            <p>
                Since <?= htmlspecialchars($appName) ?> does not collect personal data,
                there is no personal data to retain or delete on our servers.
            </p>
            <p>
                Analytics data (if collected) is retained by the respective analytics
                provider in accordance with their retention policies and is aggregated
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
                <li><strong>Export your data:</strong> Export all locally stored data via Settings</li>
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
