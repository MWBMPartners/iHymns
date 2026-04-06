<?php

/**
 * iHymns — Shared Set List Page Template (#147)
 *
 * PURPOSE:
 * Displays a read-only view of a shared set list received via URL.
 * The set list data is encoded in the URL as base64 JSON and decoded
 * client-side by the SetList JS module. This template provides the
 * shell which JavaScript populates dynamically.
 *
 * Loaded via AJAX: api.php?page=setlist-shared
 */

declare(strict_types=1);

?>

<!-- ================================================================
     SHARED SET LIST PAGE — Read-only view of a shared set list
     ================================================================ -->
<section class="page-setlist-shared" aria-label="Shared set list">

    <!-- Loading state (replaced by JS) -->
    <div id="shared-setlist-loading" class="text-center py-5">
        <div class="spinner-border text-primary mb-3" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <p class="text-muted">Loading shared set list...</p>
    </div>

    <!-- Error state (hidden by default) -->
    <div id="shared-setlist-error" class="d-none">
        <div class="alert alert-warning" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
            <strong>Invalid link</strong> — This shared set list link appears to be broken or expired.
        </div>
        <a href="/setlist" class="btn btn-outline-primary btn-sm" data-navigate="setlist">
            <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>
            Go to My Set Lists
        </a>
    </div>

    <!-- Shared set list content (hidden by default, populated by JS) -->
    <div id="shared-setlist-content" class="d-none">

        <!-- Shared indicator banner -->
        <div class="alert alert-info d-flex align-items-center gap-2 mb-3" role="status">
            <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
            <div>
                <strong>Shared Set List</strong>
                <small class="d-block">Someone shared this set list with you. Import it to use it.</small>
            </div>
        </div>

        <!-- Set list header -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h1 class="h4 mb-0" id="shared-setlist-name">
                <i class="fa-solid fa-list-ol me-2" aria-hidden="true"></i>
                <span id="shared-setlist-title"></span>
            </h1>
            <button type="button" class="btn btn-primary btn-sm" id="shared-setlist-import-btn">
                <i class="fa-solid fa-download me-1" aria-hidden="true"></i>
                Import to My Set Lists
            </button>
        </div>

        <p class="text-muted small mb-3">
            <span id="shared-setlist-count">0</span> song<span id="shared-setlist-plural">s</span>
        </p>

        <!-- Song list (populated by JS) -->
        <div id="shared-setlist-songs" class="list-group" role="list" aria-label="Songs in shared set list">
            <!-- Songs rendered by JS -->
        </div>

        <!-- Bottom import button -->
        <div class="text-center mt-4">
            <button type="button" class="btn btn-primary" id="shared-setlist-import-btn-bottom">
                <i class="fa-solid fa-download me-1" aria-hidden="true"></i>
                Import to My Set Lists
            </button>
            <div class="mt-2">
                <a href="/setlist" class="btn btn-outline-secondary btn-sm" data-navigate="setlist">
                    <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>
                    Go to My Set Lists
                </a>
            </div>
        </div>

    </div>

</section>
