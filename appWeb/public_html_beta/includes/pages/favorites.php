<?php

/**
 * iHymns — Favourites Page Template
 *
 * PURPOSE:
 * Displays the user's saved favourite songs. Favourites are stored
 * client-side in localStorage and managed by the JavaScript favourites
 * module. This template provides the container; the JavaScript module
 * populates the list dynamically.
 *
 * Loaded via AJAX: api.php?page=favorites
 */

declare(strict_types=1);

?>

<!-- ================================================================
     FAVOURITES PAGE — User's saved songs
     ================================================================ -->
<section class="page-favorites" aria-label="Favourites">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h1 class="h4 mb-0">
            <i class="fa-solid fa-heart me-2 text-danger" aria-hidden="true"></i>
            Favourites
        </h1>
        <div class="d-flex gap-2">
            <!-- Select mode toggle (#119) -->
            <button type="button"
                    class="btn btn-outline-secondary btn-sm d-none"
                    id="favorites-select-toggle"
                    aria-label="Toggle select mode"
                    aria-pressed="false">
                <i class="fa-solid fa-check-double me-1" aria-hidden="true"></i>
                Select
            </button>
            <!-- Clear all button (only visible when favourites exist) -->
            <button type="button"
                    class="btn btn-outline-danger btn-sm d-none"
                    id="clear-all-favorites"
                    aria-label="Remove all favourites">
                <i class="fa-solid fa-trash-can me-1" aria-hidden="true"></i>
                Clear All
            </button>
        </div>
    </div>

    <!-- Favourites count badge -->
    <div id="favorites-count-badge" class="mb-3 d-none">
        <span class="badge bg-primary bg-gradient rounded-pill" id="favorites-count">
            0 songs
        </span>
    </div>

    <!-- Favourites list container — populated by JavaScript -->
    <div id="favorites-list"
         class="list-group song-list"
         role="list"
         aria-label="Favourite songs list">
        <!-- Songs loaded dynamically by favorites.js -->
    </div>

    <!-- Batch actions toolbar (#119) — visible in select mode when items selected -->
    <div id="favorites-batch-toolbar" class="favorites-batch-toolbar d-none" role="toolbar" aria-label="Batch actions">
        <div class="d-flex align-items-center justify-content-between gap-2 p-2 bg-body-tertiary rounded border">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="favorites-select-all">
                    Select All
                </button>
                <span class="badge bg-primary" id="favorites-selected-count">0 selected</span>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" id="favorites-batch-setlist" disabled>
                    <i class="fa-solid fa-list-ol me-1" aria-hidden="true"></i>
                    Set List
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" id="favorites-batch-remove" disabled>
                    <i class="fa-solid fa-trash-can me-1" aria-hidden="true"></i>
                    Remove
                </button>
            </div>
        </div>
    </div>

    <!-- Empty state — shown when no favourites saved -->
    <div id="favorites-empty" class="text-center py-5">
        <i class="fa-regular fa-heart fa-4x mb-3 text-muted opacity-25" aria-hidden="true"></i>
        <h2 class="h5 text-muted">No favourites yet</h2>
        <p class="text-muted">
            Tap the <i class="fa-regular fa-heart" aria-hidden="true"></i> button on any song to save it here.
        </p>
        <a href="/songbooks"
           class="btn btn-primary"
           data-navigate="songbooks">
            <i class="fa-solid fa-book-open me-2" aria-hidden="true"></i>
            Browse Songbooks
        </a>
    </div>

</section>
