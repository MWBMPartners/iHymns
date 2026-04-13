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

    <!-- Songbook Cards Grid (#151 — section ID for sitelink eligibility) -->
    <section id="songbooks" aria-label="Songbooks">
        <h2 class="h5 mb-3">
            <i class="fa-solid fa-book-open me-2" aria-hidden="true"></i>
            Songbooks
        </h2>

        <div class="row g-3 mb-4">
            <?php foreach ($songbooks as $index => $book): ?>
                <?php if (($book['songCount'] ?? 0) > 0): ?>
                    <div class="col-6 col-md-4 col-lg-3" id="songbook-<?= htmlspecialchars($book['id']) ?>">
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

    <!-- Inline JS for dynamic home page sections (#303, #304, #305) -->
    <script>
    (function() {
        // #303 — Popular Songs
        fetch('/api?action=popular_songs&period=month&limit=10')
            .then(r => r.json())
            .then(data => {
                const el = document.getElementById('popular-songs-list');
                if (!el || !data.songs?.length) { el?.closest('#popular-songs-section')?.remove(); return; }
                el.innerHTML = data.songs.map(s =>
                    `<a href="/song/${s.songId}" data-navigate="song" class="list-group-item list-group-item-action d-flex justify-content-between">
                        <span>${s.songId}</span>
                        <span class="badge bg-secondary">${s.views} views</span>
                    </a>`
                ).join('');
            }).catch(() => document.getElementById('popular-songs-section')?.remove());

        // #304 — Recently Viewed (authenticated users only)
        const token = localStorage.getItem('ihymns_auth_token');
        if (token) {
            fetch('/api?action=song_history&limit=8', {
                headers: { 'Authorization': 'Bearer ' + token }
            }).then(r => r.json()).then(data => {
                const section = document.getElementById('recent-songs-section');
                const el = document.getElementById('recent-songs-list');
                if (!data.history?.length) return;
                section.style.display = '';
                el.innerHTML = data.history.map(h =>
                    `<a href="/song/${h.songId}" data-navigate="song" class="list-group-item list-group-item-action">${h.songId}</a>`
                ).join('');
            }).catch(() => {});
        }

        // #305 — Browse by Theme
        fetch('/api?action=tags')
            .then(r => r.json())
            .then(data => {
                const el = document.getElementById('tags-list');
                if (!data.tags?.length) { el?.closest('#tags-section')?.remove(); return; }
                el.innerHTML = data.tags.map(t =>
                    `<a href="/tag/${t.slug}" class="btn btn-sm btn-outline-secondary">${t.name}</a>`
                ).join('');
            }).catch(() => document.getElementById('tags-section')?.remove());
    })();
    </script>

</section>
