<?php

/**
 * iHymns — Usage Statistics Page Template (#120)
 *
 * PURPOSE:
 * Displays the user's song usage patterns: most-viewed songs,
 * favourite counts by songbook, set list frequency, search trends,
 * and time-based statistics. All personal data is computed client-side
 * from localStorage; collection stats come from the server.
 *
 * Loaded via AJAX: api.php?page=stats
 */

declare(strict_types=1);

$stats = $songData->getStats();

?>

<!-- ================================================================
     USAGE STATISTICS PAGE (#120)
     ================================================================ -->
<section class="page-stats" aria-label="Usage Statistics">

    <!-- Page header -->
    <h1 class="h4 mb-4">
        <i class="fa-solid fa-chart-simple me-2 text-primary" aria-hidden="true"></i>
        Usage Statistics
    </h1>

    <!-- Summary cards row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h3 mb-1 text-primary" id="stats-total-views">0</div>
                    <small class="text-muted">Songs Viewed</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h3 mb-1 text-danger" id="stats-total-favorites">0</div>
                    <small class="text-muted">Favourites</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h3 mb-1 text-success" id="stats-total-setlists">0</div>
                    <small class="text-muted">Set Lists</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h3 mb-1 text-info" id="stats-total-searches">0</div>
                    <small class="text-muted">Searches</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Collection stats -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-database me-2 text-muted" aria-hidden="true"></i>
                Collection
            </h2>
            <div class="row text-center">
                <div class="col">
                    <div class="h4 mb-0"><?= number_format($stats['totalSongs']) ?></div>
                    <small class="text-muted">Total Songs</small>
                </div>
                <div class="col">
                    <div class="h4 mb-0"><?= count($stats['songbooks']) ?></div>
                    <small class="text-muted">Songbooks</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Most viewed songs -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-fire me-2 text-muted" aria-hidden="true"></i>
                Most Viewed Songs
            </h2>
            <div id="stats-most-viewed">
                <p class="text-muted small">No viewing history yet. Browse some songs to see your stats!</p>
            </div>
        </div>
    </div>

    <!-- Favourites by songbook -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-heart me-2 text-muted" aria-hidden="true"></i>
                Favourites by Songbook
            </h2>
            <div id="stats-favorites-by-songbook">
                <p class="text-muted small">No favourites yet.</p>
            </div>
        </div>
    </div>

    <!-- Search trends -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-magnifying-glass me-2 text-muted" aria-hidden="true"></i>
                Top Searches
            </h2>
            <div id="stats-search-trends">
                <p class="text-muted small">No search history yet.</p>
            </div>
        </div>
    </div>

    <!-- Activity timeline -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-clock me-2 text-muted" aria-hidden="true"></i>
                Recent Activity
            </h2>
            <div class="row text-center mb-3">
                <div class="col">
                    <div class="h5 mb-0" id="stats-views-today">0</div>
                    <small class="text-muted">Today</small>
                </div>
                <div class="col">
                    <div class="h5 mb-0" id="stats-views-week">0</div>
                    <small class="text-muted">This Week</small>
                </div>
                <div class="col">
                    <div class="h5 mb-0" id="stats-views-month">0</div>
                    <small class="text-muted">This Month</small>
                </div>
            </div>
        </div>
    </div>

</section>
