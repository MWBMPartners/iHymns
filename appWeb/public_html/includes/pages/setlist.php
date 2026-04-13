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
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn btn-primary btn-sm" id="create-setlist-btn">
                <i class="fa-solid fa-plus me-1" aria-hidden="true"></i>
                New Set List
            </button>
            <div class="dropdown d-inline-block" id="template-dropdown" style="display:none !important">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa-solid fa-file-lines me-1"></i>From Template
                </button>
                <ul class="dropdown-menu" id="template-list">
                    <li><span class="dropdown-item-text text-muted small">Loading templates...</span></li>
                </ul>
            </div>
            <button class="btn btn-sm btn-outline-secondary" id="btn-export-pdf" title="Download as PDF" style="display:none">
                <i class="fa-solid fa-file-pdf me-1"></i>PDF
            </button>
        </div>
    </div>

    <p class="text-muted small mb-4">
        Create ordered lists of songs for worship services or events.
        Add songs from any song page using the "Set List" button.
    </p>

    <!-- Account sync indicator (populated by JS) -->
    <div id="setlist-sync-bar" class="d-none mb-3">
        <!-- Shown by JS when user is logged in or as a prompt to sign in -->
    </div>

    <!-- Set list schedule (#300) -->
    <div class="card mb-3" id="setlist-schedule-card" style="display:none">
        <div class="card-body">
            <h6><i class="fa-solid fa-calendar-days me-2"></i>Schedule This Set List</h6>
            <div class="input-group input-group-sm">
                <input type="date" class="form-control" id="schedule-date">
                <input type="text" class="form-control" id="schedule-notes" placeholder="Notes (optional)">
                <button class="btn btn-primary" id="btn-schedule-save" type="button">Schedule</button>
            </div>
            <div id="schedule-result" class="mt-2 small"></div>
        </div>
    </div>

    <!-- Set list container (populated by JS) -->
    <div id="setlist-container" aria-live="polite">
        <!-- JS module renders content here -->
    </div>

</section>
