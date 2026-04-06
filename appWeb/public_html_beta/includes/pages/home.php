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

    <!-- Recent songbooks quick tabs (#121) — populated by JS -->
    <div id="recent-songbooks" class="d-none mb-4"></div>

    <!-- Song of the Day (#108) — populated by JS -->
    <div id="song-of-the-day"></div>

    <!-- Songbook Cards Grid -->
    <h2 class="h5 mb-3">
        <i class="fa-solid fa-book-open me-2" aria-hidden="true"></i>
        Songbooks
    </h2>

    <div class="row g-3 mb-4">
        <?php foreach ($songbooks as $index => $book): ?>
            <?php if (($book['songCount'] ?? 0) > 0): ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <a href="/songbook/<?= htmlspecialchars($book['id']) ?>"
                       class="card card-songbook h-100 text-decoration-none"
                       data-navigate="songbook"
                       data-songbook-id="<?= htmlspecialchars($book['id']) ?>"
                       aria-label="<?= htmlspecialchars($book['name']) ?> — <?= $book['songCount'] ?> songs">
                        <div class="card-body text-center">
                            <!-- Songbook icon with colour variation -->
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
                    </a>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

</section>
