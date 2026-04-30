<?php

/**
 * iHymns — Home Page Template
 *
 * PURPOSE:
 * Landing page for the iHymns application. Displays a welcome message,
 * collection statistics, quick-access songbook cards, and action buttons
 * for searching, shuffling, and number lookup.
 *
 * This file is loaded as an HTML fragment via AJAX (api.php?page=home).
 * It should NOT include <html>, <head>, or <body> tags.
 */

declare(strict_types=1);

/* Load song data for statistics */
$stats = $songData->getStats();
$songbooks = $songData->getSongbooks();

?>

<!-- ================================================================
     HOME PAGE — Welcome & Quick Access
     ================================================================ -->
<section class="page-home" aria-label="Home">

    <!-- Hero Section — App introduction with gradient background -->
    <div class="hero-section text-center mb-4">
        <div class="hero-content">
            <h1 class="hero-title">
                <i class="fa-solid fa-music me-2" aria-hidden="true"></i>
                <?= htmlspecialchars($app["Application"]["Name"]) ?>
            </h1>
            <p class="hero-subtitle">
                Your library of Christian hymns &amp; worship songs
            </p>
            <p class="hero-stats">
                <span class="badge bg-primary bg-gradient rounded-pill px-3 py-2">
                    <i class="fa-solid fa-music me-1" aria-hidden="true"></i>
                    <?= number_format($stats['totalSongs']) ?> Songs
                </span>
                <span class="badge bg-secondary bg-gradient rounded-pill px-3 py-2 ms-2">
                    <i class="fa-solid fa-book me-1" aria-hidden="true"></i>
                    <?= $stats['totalSongbooks'] ?> Songbooks
                </span>
            </p>
        </div>
    </div>

    <!-- Quick Action Buttons -->
    <section id="quick-actions" aria-label="Quick actions">
    <div class="row g-3 mb-4">
        <!-- Search by text -->
        <div class="col-6 col-md-3">
            <button type="button"
                    class="btn btn-action-card w-100 h-100"
                    data-action="open-search"
                    aria-label="Search songs by text">
                <i class="fa-solid fa-magnifying-glass fa-2x mb-2" aria-hidden="true"></i>
                <span class="d-block fw-semibold">Search</span>
                <small class="text-muted">Find by title or lyrics</small>
            </button>
        </div>

        <!-- Search by number -->
        <div class="col-6 col-md-3">
            <button type="button"
                    class="btn btn-action-card w-100 h-100"
                    data-action="open-numpad"
                    aria-label="Search by song number">
                <i class="fa-solid fa-hashtag fa-2x mb-2" aria-hidden="true"></i>
                <span class="d-block fw-semibold">By Number</span>
                <small class="text-muted">Use the number pad</small>
            </button>
        </div>

        <!-- Shuffle / Random song -->
        <div class="col-6 col-md-3">
            <button type="button"
                    class="btn btn-action-card w-100 h-100"
                    data-action="open-shuffle"
                    aria-label="Pick a random song">
                <i class="fa-solid fa-shuffle fa-2x mb-2" aria-hidden="true"></i>
                <span class="d-block fw-semibold">Shuffle</span>
                <small class="text-muted">Random song pick</small>
            </button>
        </div>

        <!-- Favourites -->
        <div class="col-6 col-md-3">
            <a href="/favorites"
               class="btn btn-action-card w-100 h-100"
               data-navigate="favorites"
               aria-label="View your favourites">
                <i class="fa-solid fa-heart fa-2x mb-2" aria-hidden="true"></i>
                <span class="d-block fw-semibold">Favourites</span>
                <small class="text-muted">Your saved songs</small>
            </a>
        </div>
    </div>
    </section>

    <!-- Recent songbooks quick tabs (#121) — populated by JS -->
    <div id="recent-songbooks" class="d-none mb-4"></div>

    <!-- Song of the Day (#108) — populated by JS -->
    <div id="song-of-the-day"></div>

    <!-- Songbook Cards Grid (#151 — section ID for sitelink eligibility).
         Moved up from below "Browse by Theme" in #678 so a returning
         user sees the full songbook list straight after the Recent
         badges + Song of the Day, without having to scroll past
         Recently Viewed / Popular Songs / Themes first. -->
    <section id="songbooks" aria-label="Songbooks">
        <h2 class="h5 mb-3">
            <i class="fa-solid fa-book-open me-2" aria-hidden="true"></i>
            Songbooks
        </h2>

        <!-- Language filter (#679). The partial silently returns
             early if the catalogue spans only one language, so a
             single-English deployment doesn't see a useless filter.
             Pure client-side hide/show via the booted JS module
             below. -->
        <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'songbook-language-filter.php'; ?>

        <!-- `row-cols-*` ladders the column count with the viewport so
             cards stop stretching on xl/xxl monitors: 2 → 3 → 4 → 5 → 6
             as the breakpoints unlock. Each child is just `.col`. -->
        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 row-cols-xxl-6 g-3 mb-4">
            <?php foreach ($songbooks as $index => $book): ?>
                <?php if (($book['songCount'] ?? 0) > 0): ?>
                    <?php
                        /* Pull the IETF BCP 47 tag (or empty) and extract
                           the 2-3 letter language subtag for the badge
                           (#680). Empty = no badge — communicates
                           "multi-lingual / not specified" the same way
                           the filter in #679 does. */
                        $bookLang = (string)($book['language'] ?? '');
                        $langCode = '';
                        if ($bookLang !== '' && preg_match('/^([a-z]{2,3})/i', $bookLang, $m)) {
                            $langCode = mb_strtoupper($m[1]);
                        }
                    ?>
                    <div class="col" id="songbook-<?= htmlspecialchars($book['id']) ?>">
                        <div class="card card-songbook h-100 position-relative"
                             data-songbook-id="<?= htmlspecialchars($book['id']) ?>"
                             data-songbook-songs="<?= (int)$book['songCount'] ?>"
                             <?php if ($langCode !== ''): ?>data-songbook-language="<?= htmlspecialchars($bookLang) ?>"<?php endif; ?>>
                            <!-- Stretched link covers the whole card body,
                                 keeping the download button clickable because
                                 the button's stacking context is raised by
                                 position: relative + z-index on the button. -->
                            <a href="/songbook/<?= htmlspecialchars($book['id']) ?>"
                               class="stretched-link text-decoration-none text-reset"
                               data-navigate="songbook"
                               aria-label="<?= htmlspecialchars($book['name']) ?> — <?= $book['songCount'] ?> songs<?= $langCode !== '' ? ' (' . htmlspecialchars($langCode) . ')' : '' ?>"></a>
                            <?php if ($langCode !== ''): ?>
                                <!-- Language indicator badge (#680) — small uppercase
                                     ISO 639 code in the tile's top-right corner,
                                     positioned to clear the offline-download cloud
                                     button below. aria-hidden because the language
                                     is already announced by the stretched link. -->
                                <span class="songbook-tile-language-badge"
                                      title="Language: <?= htmlspecialchars($bookLang) ?>"
                                      aria-hidden="true"><?= htmlspecialchars($langCode) ?></span>
                            <?php endif; ?>
                            <div class="card-body text-center">
                                <div class="songbook-icon songbook-icon-<?= htmlspecialchars($book['id']) ?> mb-2">
                                    <i class="fa-solid fa-book" aria-hidden="true"></i>
                                </div>
                                <h3 class="card-title h6 mb-1">
                                    <?= htmlspecialchars($book['name']) ?>
                                </h3>
                                <span class="badge bg-body-secondary rounded-pill">
                                    <?= htmlspecialchars($book['id']) ?>
                                </span>
                                <p class="card-text text-muted small mt-2 mb-0">
                                    <?= number_format($book['songCount']) ?> songs
                                </p>
                            </div>
                            <!-- Offline-download button (#453). Hidden by
                                 default; the offline-support check in
                                 js/modules/offline.js reveals it on capable
                                 browsers. Always rendered so server-HTML
                                 stays stable; never rendered enabled if
                                 the browser can't act on it. -->
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary songbook-download-btn"
                                    data-songbook-download="<?= htmlspecialchars($book['id']) ?>"
                                    aria-label="Download <?= htmlspecialchars($book['name']) ?> for offline use"
                                    title="Download this songbook for offline use">
                                <i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Recently Viewed Songs (#304) — shown for authenticated users -->
    <div class="mb-4" id="recent-songs-section" style="display:none">
        <h5><i class="fa-solid fa-clock-rotate-left me-2"></i>Recently Viewed</h5>
        <div id="recent-songs-list" class="list-group list-group-flush"></div>
    </div>

    <!-- Popular Songs (#303) -->
    <div class="mb-4" id="popular-songs-section">
        <h5><i class="fa-solid fa-fire me-2 text-warning"></i>Popular Songs</h5>
        <div id="popular-songs-list" class="list-group list-group-flush">
            <div class="text-muted small p-2">Loading...</div>
        </div>
    </div>

    <!-- Browse by Theme (#305) -->
    <div class="mb-4" id="tags-section">
        <h5><i class="fa-solid fa-tags me-2"></i>Browse by Theme</h5>
        <div id="tags-list" class="d-flex flex-wrap gap-2">
            <span class="text-muted small">Loading...</span>
        </div>
    </div>

    <!-- The dynamic sections above (Popular Songs, Recently Viewed,
         Browse by Theme) are populated client-side by
         js/modules/home-page.js, which the SPA router imports and
         invokes after loading this template. Keeping the logic in a
         proper module instead of an inline <script> means it runs
         reliably through the normal import graph — inline <script>
         tags in AJAX-injected HTML do not execute natively, and the
         previous re-parse shim was an extra transport dependency this
         feature does not need. -->

</section>
