<?php

/**
 * iHymns — Search Page Template
 *
 * PURPOSE:
 * Dedicated search page with full-text search, songbook filtering,
 * and the numeric keypad for song number lookup. Results are loaded
 * dynamically via AJAX as the user types.
 *
 * Loaded via AJAX: api.php?page=search
 */

declare(strict_types=1);

$songbooks = $songData->getSongbooks();

?>

<!-- ================================================================
     SEARCH PAGE — Full search interface
     ================================================================ -->
<section class="page-search" aria-label="Search songs">

    <!-- Page header -->
    <h1 class="h4 mb-4">
        <i class="fa-solid fa-magnifying-glass me-2" aria-hidden="true"></i>
        Search Songs
    </h1>

    <!-- Search mode tabs -->
    <ul class="nav nav-pills nav-search-mode mb-4" role="tablist" aria-label="Search mode">
        <li class="nav-item" role="presentation">
            <button class="nav-link active"
                    id="tab-text-search"
                    data-bs-toggle="pill"
                    data-bs-target="#panel-text-search"
                    type="button"
                    role="tab"
                    aria-controls="panel-text-search"
                    aria-selected="true">
                <i class="fa-solid fa-font me-1" aria-hidden="true"></i>
                Text Search
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link"
                    id="tab-number-search"
                    data-bs-toggle="pill"
                    data-bs-target="#panel-number-search"
                    type="button"
                    role="tab"
                    aria-controls="panel-number-search"
                    aria-selected="false">
                <i class="fa-solid fa-hashtag me-1" aria-hidden="true"></i>
                By Number
            </button>
        </li>
    </ul>

    <!-- Tab content panels -->
    <div class="tab-content">

        <!-- ============================================================
             TEXT SEARCH PANEL — Search by title, lyrics, author
             ============================================================ -->
        <div class="tab-pane fade show active"
             id="panel-text-search"
             role="tabpanel"
             aria-labelledby="tab-text-search">

            <!-- Search input -->
            <form id="page-search-form" role="search" autocomplete="off" class="mb-3">
                <div class="input-group input-group-lg">
                    <span class="input-group-text" aria-hidden="true">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </span>
                    <input type="search"
                           class="form-control"
                           id="page-search-input"
                           placeholder="Search by title, lyrics, author..."
                           aria-label="Search songs"
                           autocomplete="off"
                           spellcheck="false"
                           autofocus>
                </div>
            </form>

            <!-- Songbook filter dropdown -->
            <div class="mb-3">
                <select class="form-select" id="page-search-filter" aria-label="Filter by songbook">
                    <option value="">All Songbooks</option>
                    <?php foreach ($songbooks as $book): ?>
                        <?php if (($book['songCount'] ?? 0) > 0): ?>
                            <option value="<?= htmlspecialchars($book['id']) ?>">
                                <?= htmlspecialchars($book['name']) ?> (<?= htmlspecialchars($book['id']) ?>)
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Search results container -->
            <div id="text-search-results"
                 class="search-results"
                 aria-live="polite"
                 aria-atomic="false">
                <!-- Placeholder shown before search -->
                <div class="text-center text-muted py-5" id="search-placeholder">
                    <i class="fa-solid fa-magnifying-glass fa-3x mb-3 opacity-25" aria-hidden="true"></i>
                    <p>Start typing to search across all songs</p>
                    <small>Search by title, lyrics, writer, or composer</small>
                </div>
            </div>
        </div>

        <!-- ============================================================
             NUMBER SEARCH PANEL — Search by song number with numpad
             ============================================================ -->
        <div class="tab-pane fade"
             id="panel-number-search"
             role="tabpanel"
             aria-labelledby="tab-number-search">

            <!-- Songbook selector -->
            <div class="mb-3">
                <label for="page-numpad-songbook" class="form-label fw-semibold">
                    Select Songbook
                </label>
                <select class="form-select form-select-lg" id="page-numpad-songbook" aria-label="Select songbook for number search">
                    <?php foreach ($songbooks as $book): ?>
                        <?php if (($book['songCount'] ?? 0) > 0): ?>
                            <option value="<?= htmlspecialchars($book['id']) ?>">
                                <?= htmlspecialchars($book['name']) ?> (<?= htmlspecialchars($book['id']) ?>)
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Number display -->
            <div class="numpad-display mb-3">
                <input type="text"
                       class="form-control form-control-lg text-center numpad-input"
                       id="page-numpad-display"
                       readonly
                       aria-label="Song number"
                       placeholder="Enter song number">
            </div>

            <!-- Embedded numeric keypad -->
            <div class="numpad-grid numpad-grid-page mb-3" role="group" aria-label="Numeric keypad">
                <button type="button" class="btn btn-numpad" data-page-num="1" aria-label="1">1</button>
                <button type="button" class="btn btn-numpad" data-page-num="2" aria-label="2">2</button>
                <button type="button" class="btn btn-numpad" data-page-num="3" aria-label="3">3</button>
                <button type="button" class="btn btn-numpad" data-page-num="4" aria-label="4">4</button>
                <button type="button" class="btn btn-numpad" data-page-num="5" aria-label="5">5</button>
                <button type="button" class="btn btn-numpad" data-page-num="6" aria-label="6">6</button>
                <button type="button" class="btn btn-numpad" data-page-num="7" aria-label="7">7</button>
                <button type="button" class="btn btn-numpad" data-page-num="8" aria-label="8">8</button>
                <button type="button" class="btn btn-numpad" data-page-num="9" aria-label="9">9</button>
                <button type="button" class="btn btn-numpad btn-numpad-action" data-page-num="clear" aria-label="Clear">
                    <i class="fa-solid fa-delete-left" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-numpad" data-page-num="0" aria-label="0">0</button>
                <button type="button" class="btn btn-numpad btn-numpad-go" data-page-num="go" aria-label="Go to song">
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Number search results -->
            <div id="page-numpad-results"
                 class="numpad-results"
                 aria-live="polite">
                <!-- Populated dynamically -->
            </div>
        </div>

    </div>

</section>
