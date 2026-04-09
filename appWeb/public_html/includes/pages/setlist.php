<?php

/**
 * iHymns — Set List Page Template (#94)
 *
 * PURPOSE:
 * Displays the set list management interface. All data is stored
 * client-side in localStorage; this template provides the shell
 * which the SetList JS module populates dynamically.
 *
 * Loaded via AJAX: api.php?page=setlist
 */

declare(strict_types=1);

?>

<!-- ================================================================
     SET LIST PAGE — Worship set list management
     ================================================================ -->
<section class="page-setlist" aria-label="Set lists">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">
            <i class="fa-solid fa-list-ol me-2" aria-hidden="true"></i>
            Set Lists
        </h1>
        <button type="button" class="btn btn-primary btn-sm" id="create-setlist-btn">
            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i>
            New Set List
        </button>
    </div>

    <p class="text-muted small mb-4">
        Create ordered lists of songs for worship services or events.
        Add songs from any song page using the "Set List" button.
    </p>

    <!-- Account sync indicator (populated by JS) -->
    <div id="setlist-sync-bar" class="d-none mb-3">
        <!-- Shown by JS when user is logged in or as a prompt to sign in -->
    </div>

    <!-- Set list container (populated by JS) -->
    <div id="setlist-container" aria-live="polite">
        <!-- JS module renders content here -->
    </div>

</section>
