<?php

/**
 * iHymns — Terms of Use Page Template
 *
 * PURPOSE:
 * Provides the full terms of use for the iHymns application.
 * Covers copyright licensing requirements, acceptable use, and
 * user responsibilities regarding song lyrics and content.
 *
 * Loaded via AJAX: api.php?page=terms
 */

declare(strict_types=1);

$appName = $app["Application"]["Name"];
$vendorName = $app["Application"]["Vendor"]["Name"];
$appUrl = $app["Application"]["Website"]["URL"];

?>

<!-- ================================================================
     TERMS OF USE PAGE
     ================================================================ -->
<section class="page-terms" aria-label="Terms of Use">

    <h1 class="h4 mb-4">
        <i class="fa-solid fa-file-contract me-2" aria-hidden="true"></i>
        Terms of Use
    </h1>

    <p class="text-muted small mb-4">
        Last updated: <?= date('j F Y') ?>
    </p>

    <!-- Introduction -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">1. Introduction</h2>
            <p>
                Welcome to <strong><?= htmlspecialchars($appName) ?></strong>, a service provided by
                <strong><?= htmlspecialchars($vendorName) ?></strong>.
                <?= htmlspecialchars($appName) ?> is a digital hymn and worship song lyrics
                application designed to assist Christians and congregations with worship,
                wherever they may be.
            </p>
            <p>
                By accessing or using <?= htmlspecialchars($appName) ?>, whether via the web application,
                progressive web app (PWA), or any native application, you agree to be bound
                by these Terms of Use. If you do not agree, you must discontinue use immediately.
            </p>
        </div>
    </div>

    <!-- Purpose & Intended Use -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">2. Purpose &amp; Intended Use</h2>
            <p>
                <?= htmlspecialchars($appName) ?> is intended as a <strong>supplementary worship aid</strong>.
                It is designed for use by individuals and congregations who:
            </p>
            <ul>
                <li>
                    <strong>Already own</strong> one or more of the physical songbooks whose content
                    is featured within the application; <strong>or</strong>
                </li>
                <li>
                    Hold a valid <strong>CCLI (Christian Copyright Licensing International)</strong>
                    licence, or equivalent licensing arrangement, that permits the reproduction
                    and display of song lyrics for congregational use; <strong>or</strong>
                </li>
                <li>
                    Are accessing only songs that are in the <strong>public domain</strong>
                    (i.e., songs whose copyright has expired or was never claimed).
                </li>
            </ul>
            <p>
                <?= htmlspecialchars($appName) ?> is <strong>not a substitute</strong> for purchasing
                songbooks or obtaining proper licensing. Users are responsible for ensuring
                they have the appropriate rights to access and display the lyrics contained
                within this application.
            </p>
        </div>
    </div>

    <!-- Copyright & Intellectual Property -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">3. Copyright &amp; Intellectual Property</h2>
            <p>
                The song lyrics, melodies, and associated content displayed in
                <?= htmlspecialchars($appName) ?> are the intellectual property of their
                respective copyright holders, publishers, and licensing bodies.
            </p>
            <p>
                <?= htmlspecialchars($vendorName) ?> does not claim ownership of any song
                content. All rights remain with the original rights holders.
            </p>
            <p>
                The <?= htmlspecialchars($appName) ?> application itself — including its
                design, code, user interface, and branding — is the proprietary property
                of <?= htmlspecialchars($vendorName) ?> and is protected by applicable
                copyright and intellectual property laws.
            </p>
        </div>
    </div>

    <!-- Acceptable Use -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">4. Acceptable Use</h2>
            <p>You agree <strong>not</strong> to:</p>
            <ul>
                <li>Use <?= htmlspecialchars($appName) ?> for any commercial purpose without
                    explicit written consent</li>
                <li>Redistribute, reproduce, or republish song lyrics obtained through
                    <?= htmlspecialchars($appName) ?> without appropriate licensing</li>
                <li>Attempt to extract, scrape, or bulk download song data</li>
                <li>Interfere with the operation of the application or its infrastructure</li>
                <li>Reverse engineer, decompile, or disassemble the application</li>
                <li>Use the service in any way that violates applicable local, national,
                    or international laws</li>
            </ul>
        </div>
    </div>

    <!-- Offline Use & Caching -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">5. Offline Use &amp; Caching</h2>
            <p>
                <?= htmlspecialchars($appName) ?> may be installed as a Progressive Web App (PWA)
                and used offline. When you download songs or songbooks for offline use:
            </p>
            <ul>
                <li>Cached content is stored locally on your device for personal/congregational use only</li>
                <li>Offline access does not change the licensing requirements set out in Section 2</li>
                <li>You remain responsible for holding appropriate CCLI licensing or songbook ownership</li>
            </ul>
        </div>
    </div>

    <!-- Sharing & Set Lists -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">6. Sharing &amp; Set Lists</h2>
            <p>
                <?= htmlspecialchars($appName) ?> provides features to share song information and set lists:
            </p>
            <ul>
                <li><strong>Song Sharing:</strong> You may share song titles, writer/composer names,
                    and links using the built-in share feature. Sharing of full lyrics is subject to
                    applicable copyright licensing.</li>
                <li><strong>Set List Sharing:</strong> Shareable set list links encode song references
                    into the URL. These links are intended for worship team coordination and must not
                    be used for commercial redistribution of song content.</li>
                <li><strong>Presentation Mode:</strong> The presentation/projection feature is provided
                    for congregational worship use and remains subject to CCLI or equivalent licensing.</li>
            </ul>
        </div>
    </div>

    <!-- Third-Party Libraries -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">7. Third-Party Libraries &amp; Open Source</h2>
            <p>
                <?= htmlspecialchars($appName) ?> incorporates open-source software libraries
                (including Bootstrap, jQuery, Font Awesome, Fuse.js, and others) which are used
                under their respective licences. These libraries are the property of their
                respective authors and are not claimed as part of <?= htmlspecialchars($appName) ?>.
            </p>
        </div>
    </div>

    <!-- Privacy & Analytics -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">8. Privacy &amp; Analytics</h2>
            <p>
                <?= htmlspecialchars($appName) ?> may use optional analytics services to understand
                general usage patterns. Analytics are disabled by default and require your consent.
                No personally identifiable information is collected. For full details, see our
                <a href="/privacy" data-navigate="privacy">Privacy Policy</a>.
            </p>
        </div>
    </div>

    <!-- Availability & Updates -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">9. Availability &amp; Updates</h2>
            <p>
                <?= htmlspecialchars($appName) ?> is provided on an "as is" and "as available"
                basis. We make reasonable efforts to keep the service running but cannot
                guarantee uninterrupted availability.
            </p>
            <p>
                We reserve the right to update, modify, or discontinue any part of the
                service — including the addition or removal of songbooks, songs, or
                features — at any time without prior notice.
            </p>
        </div>
    </div>

    <!-- Limitation of Liability -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">10. Limitation of Liability</h2>
            <p>
                To the fullest extent permitted by law, <?= htmlspecialchars($vendorName) ?>
                shall not be liable for any direct, indirect, incidental, consequential, or
                punitive damages arising from your use of <?= htmlspecialchars($appName) ?>.
            </p>
            <p>
                We do not warrant the accuracy or completeness of any lyrics or metadata
                displayed. While we strive for accuracy, errors may occur.
            </p>
        </div>
    </div>

    <!-- Changes to Terms -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">11. Changes to These Terms</h2>
            <p>
                We may update these Terms of Use from time to time. Continued use of
                <?= htmlspecialchars($appName) ?> after changes are posted constitutes
                acceptance of the revised terms.
            </p>
        </div>
    </div>

    <!-- Contact -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">12. Contact</h2>
            <p>
                If you have questions about these Terms of Use, please visit
                <a href="<?= htmlspecialchars($app["Application"]["Repo"]["Issues"]["URL"]) ?>"
                   target="_blank" rel="noopener noreferrer">
                    our GitHub repository
                </a>.
            </p>
        </div>
    </div>

</section>
