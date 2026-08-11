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
            <!-- Service-order templates (#301; wired #1671 F4).

                 This block shipped with #301 and was invisible for its whole
                 life: `style="display:none !important"` with NOT ONE line of JS
                 in the tree referencing its ids — the same orphan shape as
                 #298's hidden song-key container. `d-none` replaces the inline
                 `!important` because an !important inline style cannot be
                 overridden by ANY class toggle, which is part of why the block
                 stayed invisible even to somebody trying to revive it (Batch 8
                 made the identical fix for #298).

                 js/modules/setlist-templates.js reveals it, imported from
                 router.js's afterPageLoad(). There is no inline <script> here
                 and there can never be one: the document sends an enforcing
                 nonce CSP (#117), this fragment is a separate HTTP response
                 that never sees the per-request nonce, and the browser refuses
                 a nonce-less inline script SILENTLY (#1565, rule #30). CI
                 guard: tests/php/test-fragment-inline-scripts.php. -->
            <div class="dropdown d-inline-block d-none" id="template-dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa-solid fa-file-lines me-1"></i>From Template
                </button>
                <ul class="dropdown-menu" id="template-list">
                    <li><span class="dropdown-item-text text-muted small">Loading templates...</span></li>
                </ul>
            </div>
            <!-- #302 — the page-level "PDF" button was a dead orphan: hidden
                 (`display:none`), no JS, and ambiguous (this page lists MANY set
                 lists, so a top-level "PDF" has no single subject). PDF export
                 now lives on each set list's DETAIL view as the "Save as PDF"
                 button (setlist.js `#setlist-pdf-btn`), next to Print — an
                 unambiguous per-set-list action. Removed to avoid a second dead
                 control (the orphan-guard's whole concern). -->
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
