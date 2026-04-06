<?php
/**
 * iHymns — Navigation Bar Component
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE:
 * Reusable navigation bar with app branding, search, nav links, and theme toggles.
 *
 * REQUIRES: $app array from infoAppVer.php.
 *
 * @requires PHP 8.5+
 */

/* Prevent direct access */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Location: ' . dirname($_SERVER['REQUEST_URI'] ?? '', 3) . '/', true, 302);
    exit('<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=../../"></head><body>Redirecting to <a href="../../">iHymns</a>...</body></html>');
}
?>
    <nav class="navbar navbar-expand-lg sticky-top shadow-sm" id="main-navbar" role="banner">
        <div class="container-fluid">

            <!-- App brand/logo: clicking returns to the home/songbooks view -->
            <a class="navbar-brand d-flex align-items-center fw-bold" href="#" id="nav-brand"
               aria-label="iHymns home">
                <i class="bi bi-music-note-list me-2 fs-4" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($app["Application"]["Name"]); ?></span>
            </a>

            <!-- Mobile hamburger toggle button: shown on small screens -->
            <button class="navbar-toggler border-0" type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#navbarContent"
                    aria-controls="navbarContent"
                    aria-expanded="false"
                    aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Collapsible navbar content -->
            <div class="collapse navbar-collapse" id="navbarContent">

                <!-- Search form -->
                <form class="d-flex mx-lg-4 my-2 my-lg-0 flex-grow-1" role="search"
                      id="search-form" aria-label="Search songs">
                    <div class="input-group">
                        <input type="search"
                               class="form-control"
                               id="search-input"
                               placeholder="Search songs, lyrics, songbooks..."
                               aria-label="Search songs, lyrics, songbooks"
                               autocomplete="off">
                        <button class="btn btn-outline-secondary d-none" type="button"
                                id="search-clear" aria-label="Clear search">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                        <button class="btn btn-outline-secondary" type="button"
                                id="search-numpad-toggle"
                                aria-label="Toggle number search mode"
                                title="Search by song number (numpad)">
                            <i class="bi bi-123" aria-hidden="true"></i>
                        </button>
                        <button class="btn btn-search" type="submit"
                                id="search-btn" aria-label="Search">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </button>
                    </div>
                </form>

                <!-- Navigation links -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link active" href="#" id="nav-songbooks"
                           aria-label="Browse songbooks" aria-current="page">
                            <i class="bi bi-book me-1" aria-hidden="true"></i>
                            <span>Songbooks</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" id="nav-favorites"
                           aria-label="View favourites">
                            <i class="bi bi-star me-1" aria-hidden="true"></i>
                            <span>Favourites</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" id="nav-help"
                           aria-label="Help and documentation">
                            <i class="bi bi-question-circle me-1" aria-hidden="true"></i>
                            <span>Help</span>
                        </a>
                    </li>
                </ul>

                <!-- Right-side controls: colourblind toggle + dark mode toggle -->
                <div class="d-flex align-items-center gap-1">
                    <button class="btn btn-link nav-link px-2" type="button"
                            id="cb-toggle"
                            aria-label="Toggle colourblind-friendly mode"
                            aria-pressed="false"
                            title="Colourblind-friendly mode">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                    <button class="btn btn-link nav-link px-2" type="button"
                            id="theme-toggle"
                            aria-label="Toggle dark mode"
                            title="Toggle dark mode">
                        <i class="bi bi-sun-fill d-none" id="theme-icon-light" aria-hidden="true"></i>
                        <i class="bi bi-moon-fill" id="theme-icon-dark" aria-hidden="true"></i>
                    </button>
                </div>

            </div>
        </div>
    </nav>
