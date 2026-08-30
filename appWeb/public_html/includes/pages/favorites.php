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

    <!-- Sort control (#1786) — array mode: favourites.js reads the saved
         spec and sorts its in-memory array before rendering (there is no
         data-list-sort-list container — a favourites list is JS-rendered
         from localStorage, not server-rendered DOM to reorder in place).
         Wired via wireListSortControl('favorites', …) from
         Favorites.loadFavoritesList(). -->
    <?php
        $listSortSurface = 'favorites';
        $listSortDefault = 'Date added';
        $listSortOptions = [
            'added' => ['label' => 'Date added', 'type' => 'date',   'dir' => 'desc'],
            'title' => ['label' => 'Title',      'type' => 'text',   'dir' => 'asc'],
            'book'  => ['label' => 'Songbook & number', 'type' => 'text', 'dir' => 'asc'],
        ];
        require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
    ?>

    <!-- Tag filter (#122) — populated by JS -->
    <div id="favorites-tag-filter" class="d-none mb-3">
        <div class="d-flex flex-wrap gap-1" id="favorites-tag-pills" role="group" aria-label="Filter by tag">
            <!-- Tag pills rendered by JS -->
        </div>
    </div>

    <!-- Favourites list container — populated by JavaScript -->
    <?php /* A20 fix (a11y audit 2026-08-30): each row is an <a href> link,
       and favorites.js used to stamp role="listitem" onto it. An explicit
       ARIA role always overrides the element's native one for assistive
       tech, so that link stopped being announced as a link at all — while
       still being clickable/keyboard-focusable via its real href, screen
       reader users lost it from their links list (WCAG 4.1.2). role="list"
       here (which needs listitem-role children to be valid ARIA) would
       have the same problem in reverse if we "fixed" it by demoting the
       role on a wrapper instead — and a real per-row wrapper element can't
       be added without breaking the Bootstrap list-group CSS this page
       relies on for borders/corner-radius (its `:first-child`/`+` sibling
       selectors need `.list-group-item` to stay a DIRECT child here) and
       app.css's `.song-list-item:nth-child(n)` stagger-in animation. So:
       drop BOTH roles (favorites.js drops role="listitem" too) and use
       role="group" instead of role="list" — the same "named collection of
       controls with no per-item semantics" pattern this page already uses
       two elements up for #favorites-tag-pills. */ ?>
    <div id="favorites-list"
         class="list-group song-list"
         role="group"
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
