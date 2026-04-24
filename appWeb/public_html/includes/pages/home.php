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
                        <div class="card card-songbook h-100 position-relative"
                             data-songbook-id="<?= htmlspecialchars($book['id']) ?>"
                             data-songbook-songs="<?= (int)$book['songCount'] ?>">
                            <!-- Stretched link covers the whole card body,
                                 keeping the download button clickable because
                                 the button's stacking context is raised by
                                 position: relative + z-index on the button. -->
                            <a href="/songbook/<?= htmlspecialchars($book['id']) ?>"
                               class="stretched-link text-decoration-none text-reset"
                               data-navigate="songbook"
                               aria-label="<?= htmlspecialchars($book['name']) ?> — <?= $book['songCount'] ?> songs"></a>
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
                            <!-- Rendered visible; a capability-free browser
                                 picks up `body.offline-unsupported` and the
                                 shared CSS rule hides all download UI. -->
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

    <!-- Inline JS for dynamic home page sections (#303, #304, #305) -->
    <script>
    (function() {
        var esc = function(s) { return (s||'').replace(/[&<>"']/g, function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]}); };
        var SONGBOOK_NAMES = {CP:'Carol Praise',JP:'Junior Praise',MP:'Mission Praise',SDAH:'Seventh-day Adventist Hymnal',CH:'The Church Hymnal',Misc:'Miscellaneous'};
        /* Minimal title-case for this inline script — mirrors utils/text.js toTitleCase. */
        var MINOR = {a:1,an:1,and:1,as:1,at:1,but:1,by:1,for:1,in:1,nor:1,of:1,on:1,or:1,so:1,the:1,to:1,up:1,yet:1};
        var titleCase = function(s) {
            if (!s) return s || '';
            var w = String(s).toLowerCase().split(/\s+/), last = w.length - 1;
            return w.map(function(word, i) {
                var prev = i > 0 ? w[i - 1] : '';
                var newClause = i > 0 && /[.!?:\u2014\u2013]$/.test(prev);
                var bare = word.replace(/[^\p{L}\p{N}']/gu, '');
                if (i === 0 || i === last || newClause || !MINOR[bare]) {
                    word = word.replace(/^([^\p{L}]*)(\p{L})/u, function(_, p, c){ return p + c.toUpperCase(); });
                }
                return word.replace(/-\w/g, function(m){ return m.toUpperCase(); });
            }).join(' ');
        };

        // #303 — Popular Songs (server or client-side fallback)
        fetch('/api?action=popular_songs&period=month&limit=10')
            .then(function(r){return r.json()})
            .then(function(data) {
                var el = document.getElementById('popular-songs-list');
                if (!el) return;

                var songs = data.songs || [];

                /* If server returned empty (JSON fallback mode), build from localStorage history */
                if (!songs.length) {
                    try {
                        var hist = JSON.parse(localStorage.getItem('ihymns_history') || '[]');
                        /* Count song occurrences to estimate popularity */
                        var counts = {};
                        hist.forEach(function(h) {
                            if (!counts[h.id]) counts[h.id] = { songId: h.id, title: h.title, songbook: h.songbook, number: h.number, views: 0 };
                            counts[h.id].views++;
                        });
                        songs = Object.values(counts).sort(function(a,b){return b.views-a.views}).slice(0, 10);
                    } catch(e) { songs = []; }
                }

                if (!songs.length) { el.closest('#popular-songs-section')?.remove(); return; }

                el.innerHTML = songs.map(function(s) {
                    var id = s.songId || s.id || '';
                    var title = titleCase(s.title || id);
                    var book = s.songbook || id.split('-')[0] || '';
                    var bookName = SONGBOOK_NAMES[book] || book;
                    return '<a href="/song/' + esc(id) + '" data-navigate="song" data-song-id="' + esc(id) + '" class="list-group-item list-group-item-action song-list-item">' +
                        '<span class="song-number-badge" data-songbook="' + esc(book) + '">' + (s.number ?? '') + '</span>' +
                        '<div class="song-info flex-grow-1">' +
                            '<span class="song-title">' + esc(title) + '</span>' +
                            '<small class="text-muted d-block"><span class="songbook-name-full">' + esc(bookName) + '</span><span class="songbook-name-abbr">' + esc(book) + '</span></small>' +
                        '</div>' +
                        '<span class="badge bg-secondary">' + s.views + '</span>' +
                    '</a>';
                }).join('');
            }).catch(function() { document.getElementById('popular-songs-section')?.remove(); });

        // #304 — Recently Viewed (authenticated users only)
        var token = localStorage.getItem('ihymns_auth_token');
        if (token) {
            fetch('/api?action=song_history&limit=8', {
                headers: { 'Authorization': 'Bearer ' + token }
            }).then(function(r){return r.json()}).then(function(data) {
                var section = document.getElementById('recent-songs-section');
                var el = document.getElementById('recent-songs-list');
                if (!data.history?.length) return;
                section.style.display = '';
                el.innerHTML = data.history.map(function(h) {
                    return '<a href="/song/' + esc(h.songId) + '" data-navigate="song" class="list-group-item list-group-item-action">' + esc(h.songId) + '</a>';
                }).join('');
            }).catch(function(){});
        }

        // #305 — Browse by Theme
        fetch('/api?action=tags')
            .then(function(r){return r.json()})
            .then(function(data) {
                var el = document.getElementById('tags-list');
                if (!data.tags?.length) { el?.closest('#tags-section')?.remove(); return; }
                el.innerHTML = data.tags.map(function(t) {
                    return '<a href="/tag/' + esc(t.slug) + '" data-navigate="tag" class="btn btn-sm btn-outline-secondary">' + esc(t.name) + '</a>';
                }).join('');
            }).catch(function() { document.getElementById('tags-section')?.remove(); });
    })();
    </script>

</section>
