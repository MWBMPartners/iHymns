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
                <li><strong>Favourites:</strong> Your saved favourite songs</li>
                <li><strong>Settings:</strong> Theme preference, font size, motion preference, and other display settings</li>
                <li><strong>Disclaimer acceptance:</strong> Whether you have accepted the terms of use disclaimer</li>
                <li><strong>PWA install banner:</strong> Whether you have dismissed the install prompt</li>
            </ul>
            <p>This data remains entirely on your device. You can clear it at any time via the Settings page or by clearing your browser data.</p>

            <h3 class="small fw-bold mt-3">2.2 Data Collected via Analytics</h3>
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

    <!-- Cookies -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">4. Cookies</h2>
            <p>
                <?= htmlspecialchars($appName) ?> does <strong>not</strong> set any first-party
                cookies. All user preferences are stored in browser local storage.
            </p>
            <p>
                Third-party analytics services (if enabled) may set their own cookies in
                accordance with their respective privacy policies. When DNT is active,
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
                <li>Includes only application code, styles, and song data</li>
                <li>Does not include any personal information</li>
                <li>Is stored locally in your browser's cache storage</li>
                <li>Can be cleared at any time via Settings or your browser settings</li>
            </ul>
        </div>
    </div>

    <!-- Third-Party Services -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">6. Third-Party Services</h2>
            <p>
                <?= htmlspecialchars($appName) ?> may use the following third-party services,
                each governed by their own privacy policies:
            </p>
            <ul>
                <li><strong>CDN providers</strong> (jsDelivr, cdnjs) — for loading CSS and JavaScript libraries</li>
                <li><strong>Google Analytics</strong> (if enabled) — for anonymous usage statistics</li>
                <li><strong>Microsoft Clarity</strong> (if enabled) — for anonymous usage heatmaps</li>
                <li><strong>Plausible Analytics</strong> (if enabled) — privacy-focused analytics</li>
                <li><strong>Matomo</strong> (if enabled) — self-hosted analytics</li>
                <li><strong>Fathom Analytics</strong> (if enabled) — privacy-focused analytics</li>
            </ul>
        </div>
    </div>

    <!-- Data Retention -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">7. Data Retention</h2>
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
            <h2 class="h6 mb-3">8. Your Rights</h2>
            <p>You have the right to:</p>
            <ul>
                <li><strong>Clear your data:</strong> All locally stored data can be cleared via Settings</li>
                <li><strong>Enable DNT:</strong> Activate Do Not Track in your browser to anonymise analytics</li>
                <li><strong>Block analytics:</strong> Use browser extensions (e.g., uBlock Origin) to block analytics entirely</li>
                <li><strong>Uninstall:</strong> Remove the PWA from your device at any time</li>
            </ul>
        </div>
    </div>

    <!-- Changes -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">9. Changes to This Policy</h2>
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
            <h2 class="h6 mb-3">10. Contact</h2>
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
