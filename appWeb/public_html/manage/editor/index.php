<?php

declare(strict_types=1);

/**
 * ============================================================================
 * iHymns Song Editor — Web-Based Admin Tool (#227)
 * ============================================================================
 *
 * Browser-based interface for editing the songs.json data that powers
 * the iHymns application. Protected by session-based authentication
 * via the /manage/ admin area auth system.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * @package    iHymns
 * @subpackage SongEditor
 * @license    Proprietary — All rights reserved
 * @requires   PHP 8.5+
 * ============================================================================
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
requireEditor();

$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <!-- =================================================================
         HEAD — Meta, Bootstrap 5.3 CDN, Bootstrap Icons, Page Title
         ================================================================= -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Page title shown in the browser tab -->
    <title>iHymns Song Editor</title>

    <!-- Bootstrap 5.3 CSS — loaded from CDN for convenience (no local dependency) -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >

    <!-- Bootstrap Icons — icon font for UI controls (drag handles, buttons, etc.) -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
        crossorigin="anonymous"
    >

    <!-- Shared iHymns palette (public site) + admin/editor styles -->
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__, 2) . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__, 2) . '/css/admin.css') ?>">

    <!-- =================================================================
         INLINE STYLES — reserved for genuinely editor-specific tweaks only.
         Shared layout, colours, buttons, cards are in /css/admin.css.
         ================================================================= -->
    <style>
        /* Shared layout, colours, buttons, cards → /css/admin.css
           Add editor-only tweaks here if truly needed. */
    </style>
</head>
<body>

    <!-- =================================================================
         HIDDEN FILE INPUTS
         These are invisible <input type="file"> elements triggered by
         JavaScript when the user clicks "Load JSON" or "Import".
         They live outside the visible DOM to keep the layout clean.
         ================================================================= -->

    <!-- Hidden file input for loading the primary songs.json file -->
    <input
        type="file"
        id="fileInputLoad"
        accept=".json"
        style="display: none;"
        aria-label="Load songs.json file"
    >

    <!-- Hidden file input for importing songs from an external file -->
    <input
        type="file"
        id="fileInputImport"
        accept=".json,.csv"
        style="display: none;"
        aria-label="Import songs from file"
    >


    <!-- =================================================================
         TOP NAVBAR
         Contains the editor branding and primary action buttons:
         Load JSON, Save JSON, Export dropdown, and Import.
         ================================================================= -->
    <nav class="navbar navbar-editor d-flex align-items-center">

        <!-- Brand / logo area -->
        <a class="navbar-brand d-flex align-items-center gap-2 me-auto" href="#">
            <!-- Music note icon representing the iHymns brand -->
            <i class="bi bi-music-note-beamed"></i>
            iHymns Song Editor
        </a>

        <!-- Action buttons group — aligned to the right -->
        <div class="d-flex align-items-center gap-2">

            <!-- LOAD JSON — Triggers the hidden file input to select a songs.json file -->
            <button
                type="button"
                class="btn btn-sm btn-amber"
                id="btn-load-file"
                title="Load a songs.json file from disk"
            >
                <i class="bi bi-folder2-open me-1"></i>Load JSON
            </button>

            <!-- LOAD FROM URL — Load songs.json from a remote URL (#235) -->
            <button
                type="button"
                class="btn btn-sm btn-outline-amber"
                id="btn-load-url"
                title="Load songs.json from a URL"
            >
                <i class="bi bi-link-45deg me-1"></i>Load URL
            </button>

            <!-- SAVE — Writes all songs to MySQL (primary path). If the DB
                 is unavailable, the editor falls back to a JSON download so
                 you never lose changes. -->
            <button
                type="button"
                class="btn btn-sm btn-amber-solid"
                id="btn-save"
                title="Save all changes to the database"
            >
                <i class="bi bi-floppy me-1"></i>Save
            </button>

            <!-- VALIDATE — Check all songs for data quality issues (#235) -->
            <button
                type="button"
                class="btn btn-sm btn-outline-success"
                id="btn-validate"
                title="Validate all song data for errors"
            >
                <i class="bi bi-check-circle me-1"></i>Validate
            </button>

            <!-- EXPORT DROPDOWN — Provides JSON and CSV export options -->
            <div class="dropdown">
                <button
                    class="btn btn-sm btn-amber dropdown-toggle"
                    type="button"
                    id="dropdownExport"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    title="Export songs in different formats"
                >
                    <i class="bi bi-box-arrow-up me-1"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="dropdownExport">
                    <!-- Export as JSON — full data export -->
                    <li>
                        <a class="dropdown-item" href="#" id="btn-export-json">
                            <i class="bi bi-filetype-json me-2"></i>Export as JSON
                        </a>
                    </li>
                    <!-- Export as CSV — tabular export for spreadsheets -->
                    <li>
                        <a class="dropdown-item" href="#" id="btn-export-csv">
                            <i class="bi bi-filetype-csv me-2"></i>Export as CSV
                        </a>
                    </li>
                </ul>
            </div>

            <!-- IMPORT — Triggers the hidden import file input -->
            <button
                type="button"
                class="btn btn-sm btn-amber"
                id="btn-import"
                title="Import songs from an external JSON or CSV file"
            >
                <i class="bi bi-box-arrow-in-down me-1"></i>Import
            </button>

            <!-- Separator + Admin links / Logout -->
            <span class="text-muted mx-1">|</span>
            <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
            <a href="/manage/users"
               class="btn btn-sm btn-outline-secondary me-1"
               title="User management">
                <i class="bi bi-people me-1"></i>Users
            </a>
            <?php endif; ?>
            <span class="text-muted small d-none d-md-inline me-1"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'] ?? '') ?></span>
            <a href="/manage/logout"
               class="btn btn-sm btn-outline-secondary"
               title="Sign out">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </nav>


    <!-- =================================================================
         MAIN EDITOR LAYOUT — Two-column flex layout
         Left: Sidebar with songbook filter, search, and song list
         Right: Main edit panel with tabbed form
         ================================================================= -->
    <div class="editor-wrapper">

        <!-- =============================================================
             LEFT SIDEBAR
             Contains:
               1. Songbook filter dropdown — filters songs by songbook
               2. Search input — live-filters the song list by title
               3. Scrollable song list — shows all matching songs
               4. Footer — displays total song count
             ============================================================= -->
        <aside class="editor-sidebar">

            <!-- Sidebar Header — Filter and search controls -->
            <div class="sidebar-header">

                <!-- Songbook filter dropdown — populated dynamically from loaded data -->
                <div class="mb-2">
                    <select
                        class="form-select form-select-sm"
                        id="songbook-filter"
                        aria-label="Filter by songbook"
                        title="Filter songs by songbook"
                    >
                        <!-- Default option showing all songbooks -->
                        <option value="">All Songbooks</option>
                        <!-- Additional <option> elements are populated by editor.js -->
                    </select>
                </div>

                <!-- Search input — live text search across song titles -->
                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text" style="background-color: var(--ih-bg-input); border-color: var(--ih-border); color: var(--ih-text-muted);">
                        <i class="bi bi-search"></i>
                    </span>
                    <input
                        type="text"
                        class="form-control"
                        id="song-search"
                        placeholder="Search songs..."
                        aria-label="Search songs by title"
                    >
                </div>

                <!-- Sort order toggle (#251) -->
                <select
                    class="form-select form-select-sm"
                    id="song-sort"
                    aria-label="Sort songs by"
                    title="Sort order"
                    style="font-size: 0.75rem;"
                >
                    <option value="title" selected>Sort by Title (A–Z)</option>
                    <option value="number">Sort by Number</option>
                    <option value="songbook">Sort by Songbook, then Number</option>
                </select>
            </div>

            <!-- Song list — scrollable container; each song is a clickable row -->
            <div class="song-list-container" id="song-list">
                <!--
                     Song list items are rendered dynamically by editor.js.
                     Each item follows this structure:

                     <div class="song-list-item" data-song-index="0">
                         <div class="song-title">Amazing Grace</div>
                         <div class="song-meta">#1 - Hymnal</div>
                     </div>
                -->

                <!-- Empty state shown when no songs are loaded -->
                <div class="empty-state py-5" id="songListEmpty">
                    <i class="bi bi-music-note-list"></i>
                    <p class="mb-1">No songs loaded</p>
                    <small>Click "Load JSON" to begin</small>
                </div>
            </div>

            <!-- Sidebar Footer — Song count + Add/Delete buttons -->
            <div class="sidebar-footer d-flex align-items-center justify-content-between">
                <span>
                    <span id="song-count">0 songs</span>
                    <span id="songCountFiltered" style="display: none;"> (showing <span id="filteredCount">0</span>)</span>
                </span>
                <span>
                    <button type="button" class="btn btn-sm btn-amber" id="btn-add-song" title="Add new song">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-delete-song" title="Delete selected song">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                </span>
            </div>
        </aside>


        <!-- =============================================================
             MAIN EDIT PANEL
             Contains the tabbed editor form. Four tabs:
               1. Metadata  — Title, Song Number, Songbook, CCLI Number
               2. Structure — Ordered song components (verse, chorus, etc.)
               3. Credits   — Writers, Composers, Copyright notice
               4. Preview   — Read-only rendered preview of the song
             ============================================================= -->
        <main class="editor-main" id="editorMain">

            <!-- Empty state — shown when no song is selected for editing -->
            <div class="empty-state h-100" id="editorEmpty">
                <i class="bi bi-pencil-square"></i>
                <p class="mb-1">No song selected</p>
                <small>Select a song from the list, or load a JSON file to begin editing</small>
            </div>

            <!-- Song editor form — hidden until a song is selected -->
            <div id="editorForm" style="display: none;">

                <!-- Tab navigation — Bootstrap nav-tabs component -->
                <ul class="nav nav-tabs mb-3" id="editorTabs" role="tablist">

                    <!-- Metadata tab trigger -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link active"
                            id="tab-metadata"
                            data-bs-toggle="tab"
                            data-bs-target="#panel-metadata"
                            type="button"
                            role="tab"
                            aria-controls="panel-metadata"
                            aria-selected="true"
                        >
                            <i class="bi bi-info-circle me-1"></i>Metadata
                        </button>
                    </li>

                    <!-- Structure tab trigger -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="tab-structure"
                            data-bs-toggle="tab"
                            data-bs-target="#panel-structure"
                            type="button"
                            role="tab"
                            aria-controls="panel-structure"
                            aria-selected="false"
                        >
                            <i class="bi bi-list-ol me-1"></i>Structure
                        </button>
                    </li>

                    <!-- Credits tab trigger -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="tab-credits"
                            data-bs-toggle="tab"
                            data-bs-target="#panel-credits"
                            type="button"
                            role="tab"
                            aria-controls="panel-credits"
                            aria-selected="false"
                        >
                            <i class="bi bi-people me-1"></i>Credits
                        </button>
                    </li>

                    <!-- Preview tab trigger -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="tab-preview"
                            data-bs-toggle="tab"
                            data-bs-target="#panel-preview"
                            type="button"
                            role="tab"
                            aria-controls="panel-preview"
                            aria-selected="false"
                        >
                            <i class="bi bi-eye me-1"></i>Preview
                        </button>
                    </li>
                </ul>

                <!-- =====================================================
                     TAB CONTENT PANELS
                     Each panel corresponds to one of the tabs above.
                     ===================================================== -->
                <div class="tab-content" id="editorTabContent">

                    <!-- -------------------------------------------------
                         METADATA TAB PANEL
                         Core song identification fields:
                         Title, Song Number, Songbook, CCLI Number
                         ------------------------------------------------- -->
                    <div
                        class="tab-pane fade show active"
                        id="panel-metadata"
                        role="tabpanel"
                        aria-labelledby="tab-metadata"
                    >
                        <!-- Song Title — the primary display name of the song -->
                        <div class="mb-3">
                            <label for="edit-title" class="form-label">
                                Song Title <span class="text-danger">*</span>
                            </label>
                            <input
                                type="text"
                                class="form-control"
                                id="edit-title"
                                placeholder="Enter song title"
                                required
                            >
                        </div>

                        <!-- Song Number and Songbook — displayed side by side -->
                        <div class="row mb-3">
                            <!-- Song Number — the numeric identifier within a songbook -->
                            <div class="col-md-4">
                                <label for="edit-number" class="form-label">Song Number</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    id="edit-number"
                                    placeholder="e.g. 42"
                                    min="1"
                                >
                            </div>

                            <!-- Songbook — the collection this song belongs to -->
                            <div class="col-md-8">
                                <label for="edit-songbook" class="form-label">Songbook</label>
                                <select class="form-select" id="edit-songbook">
                                    <option value="">Select songbook...</option>
                                    <!--
                                         Songbook options are populated dynamically
                                         by editor.js from the loaded dataset.
                                         Users can also type a new songbook name.
                                    -->
                                </select>
                            </div>
                        </div>

                        <!-- CCLI Number — Christian Copyright Licensing International identifier -->
                        <div class="mb-3">
                            <label for="edit-ccli" class="form-label">CCLI Number</label>
                            <input
                                type="text"
                                class="form-control"
                                id="edit-ccli"
                                placeholder="e.g. 1234567"
                            >
                            <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                The CCLI song number for licensing and reporting purposes.
                            </div>
                        </div>

                        <!-- Language — IETF BCP 47 composed from Language + Script + Region (#240) -->
                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-translate me-1"></i>Language (IETF BCP 47)</label>
                            <div class="row g-2">
                                <!-- Language (required) — ISO 639 -->
                                <div class="col-4">
                                    <label for="edit-lang-language" class="form-label" style="font-size:0.75rem;">Language</label>
                                    <input type="text" class="form-control form-control-sm" id="edit-lang-language"
                                        placeholder="e.g. en" list="lang-language-list" required>
                                    <datalist id="lang-language-list">
                                        <option value="en">English</option>
                                        <option value="fr">French</option>
                                        <option value="de">German</option>
                                        <option value="es">Spanish</option>
                                        <option value="it">Italian</option>
                                        <option value="pt">Portuguese</option>
                                        <option value="la">Latin</option>
                                        <option value="cy">Welsh</option>
                                        <option value="gd">Scottish Gaelic</option>
                                        <option value="ga">Irish</option>
                                        <option value="nl">Dutch</option>
                                        <option value="sv">Swedish</option>
                                        <option value="no">Norwegian</option>
                                        <option value="da">Danish</option>
                                        <option value="fi">Finnish</option>
                                        <option value="pl">Polish</option>
                                        <option value="cs">Czech</option>
                                        <option value="hu">Hungarian</option>
                                        <option value="ro">Romanian</option>
                                        <option value="ko">Korean</option>
                                        <option value="ja">Japanese</option>
                                        <option value="zh">Chinese</option>
                                        <option value="ar">Arabic</option>
                                        <option value="he">Hebrew</option>
                                        <option value="hi">Hindi</option>
                                        <option value="sw">Swahili</option>
                                        <option value="zu">Zulu</option>
                                        <option value="xh">Xhosa</option>
                                        <option value="af">Afrikaans</option>
                                        <option value="tl">Tagalog</option>
                                    </datalist>
                                </div>
                                <!-- Script (optional) — ISO 15924 -->
                                <div class="col-4">
                                    <label for="edit-lang-script" class="form-label" style="font-size:0.75rem;">Script</label>
                                    <input type="text" class="form-control form-control-sm" id="edit-lang-script"
                                        placeholder="e.g. Latn" list="lang-script-list">
                                    <datalist id="lang-script-list">
                                        <option value="Latn">Latin</option>
                                        <option value="Cyrl">Cyrillic</option>
                                        <option value="Arab">Arabic</option>
                                        <option value="Hebr">Hebrew</option>
                                        <option value="Deva">Devanagari</option>
                                        <option value="Hans">Simplified Chinese</option>
                                        <option value="Hant">Traditional Chinese</option>
                                        <option value="Hang">Hangul</option>
                                        <option value="Kana">Katakana</option>
                                        <option value="Grek">Greek</option>
                                        <option value="Geor">Georgian</option>
                                        <option value="Armn">Armenian</option>
                                        <option value="Thai">Thai</option>
                                        <option value="Ethi">Ethiopic</option>
                                    </datalist>
                                </div>
                                <!-- Region (optional) — ISO 3166-1 alpha-2 -->
                                <div class="col-4">
                                    <label for="edit-lang-region" class="form-label" style="font-size:0.75rem;">Region</label>
                                    <input type="text" class="form-control form-control-sm" id="edit-lang-region"
                                        placeholder="e.g. GB" list="lang-region-list">
                                    <datalist id="lang-region-list">
                                        <option value="GB">United Kingdom</option>
                                        <option value="US">United States</option>
                                        <option value="AU">Australia</option>
                                        <option value="NZ">New Zealand</option>
                                        <option value="CA">Canada</option>
                                        <option value="IE">Ireland</option>
                                        <option value="ZA">South Africa</option>
                                        <option value="FR">France</option>
                                        <option value="DE">Germany</option>
                                        <option value="AT">Austria</option>
                                        <option value="CH">Switzerland</option>
                                        <option value="ES">Spain</option>
                                        <option value="MX">Mexico</option>
                                        <option value="IT">Italy</option>
                                        <option value="PT">Portugal</option>
                                        <option value="BR">Brazil</option>
                                        <option value="NL">Netherlands</option>
                                        <option value="SE">Sweden</option>
                                        <option value="NO">Norway</option>
                                        <option value="DK">Denmark</option>
                                        <option value="FI">Finland</option>
                                        <option value="PL">Poland</option>
                                        <option value="CZ">Czechia</option>
                                        <option value="HU">Hungary</option>
                                        <option value="RO">Romania</option>
                                        <option value="KR">South Korea</option>
                                        <option value="JP">Japan</option>
                                        <option value="CN">China</option>
                                        <option value="TW">Taiwan</option>
                                        <option value="IN">India</option>
                                        <option value="PH">Philippines</option>
                                        <option value="KE">Kenya</option>
                                        <option value="NG">Nigeria</option>
                                        <option value="GH">Ghana</option>
                                    </datalist>
                                </div>
                            </div>
                            <!-- Composed IETF tag preview -->
                            <div class="mt-1 d-flex align-items-center gap-2">
                                <span class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                    IETF tag:
                                </span>
                                <code id="edit-lang-preview" style="font-size: 0.8rem;">en</code>
                                <input type="hidden" id="edit-language">
                            </div>
                        </div>

                        <!-- Status & Copyright Flags (#222, #225) -->
                        <hr style="border-color: var(--ih-border);">
                        <div class="mb-3">
                            <label class="form-label d-block">
                                <i class="bi bi-flag me-1"></i>Status &amp; Copyright
                            </label>

                            <!-- Verified — lyrics confirmed as complete and accurate -->
                            <div class="form-check mb-2">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    id="edit-verified"
                                >
                                <label class="form-check-label" for="edit-verified">
                                    <i class="bi bi-patch-check me-1" style="color: var(--ih-amber);"></i>
                                    Verified — lyrics confirmed complete and accurate
                                </label>
                            </div>

                            <!-- Lyrics Public Domain — lyric text is copyright-free -->
                            <div class="form-check mb-2">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    id="edit-lyricsPublicDomain"
                                >
                                <label class="form-check-label" for="edit-lyricsPublicDomain">
                                    <i class="bi bi-unlock me-1" style="color: var(--ih-amber);"></i>
                                    Lyrics — Public Domain
                                </label>
                            </div>

                            <!-- Music Public Domain — musical composition is copyright-free -->
                            <div class="form-check mb-2">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    id="edit-musicPublicDomain"
                                >
                                <label class="form-check-label" for="edit-musicPublicDomain">
                                    <i class="bi bi-unlock me-1" style="color: var(--ih-amber);"></i>
                                    Music — Public Domain
                                </label>
                            </div>

                            <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                Only tick Public Domain if the work is explicitly in the public domain.
                                An unknown or missing copyright does not imply public domain.
                            </div>
                        </div>
                    </div>
                    <!-- END Metadata Tab Panel -->


                    <!-- -------------------------------------------------
                         STRUCTURE TAB PANEL
                         Ordered list of song components (verses, choruses,
                         bridges, etc.) with drag-and-drop reordering.
                         Each component has: type, number, and lyrics.
                         ------------------------------------------------- -->
                    <div
                        class="tab-pane fade"
                        id="panel-structure"
                        role="tabpanel"
                        aria-labelledby="tab-structure"
                    >
                        <!-- Component list container — components rendered dynamically -->
                        <div id="componentList">
                            <!--
                                 Each component card follows this structure
                                 (rendered by editor.js):

                                 <div class="component-card" data-component-index="0">
                                     <div class="d-flex align-items-start gap-2">
                                         <span class="drag-handle"><i class="bi bi-grip-vertical"></i></span>
                                         <span class="component-number">1</span>
                                         <div class="flex-grow-1">
                                             <div class="row g-2 mb-2">
                                                 <div class="col-md-6">
                                                     <select class="form-select form-select-sm component-type">...</select>
                                                 </div>
                                                 <div class="col-md-3">
                                                     <input type="number" class="form-control form-control-sm component-num">
                                                 </div>
                                                 <div class="col-md-3 text-end">
                                                     ... move / remove buttons ...
                                                 </div>
                                             </div>
                                             <textarea class="form-control component-lyrics" rows="4">...</textarea>
                                         </div>
                                     </div>
                                 </div>
                            -->
                        </div>

                        <!-- Informational message when no components exist -->
                        <div class="text-center py-4" id="componentListEmpty">
                            <p class="text-muted mb-2">No components yet. Add a verse, chorus, or other section below.</p>
                        </div>

                        <!-- Action bar — buttons for managing components -->
                        <div class="d-flex gap-2 mt-3">
                            <!-- Add Component — appends a new blank component card -->
                            <button
                                type="button"
                                class="btn btn-sm btn-amber"
                                id="btn-add-component"
                                title="Add a new song component (verse, chorus, etc.)"
                            >
                                <i class="bi bi-plus-circle me-1"></i>Add Component
                            </button>
                        </div>

                        <!-- Legend explaining the available component types -->
                        <div class="mt-3 p-2 rounded" style="background-color: var(--ih-bg-card); border: 1px solid var(--ih-border);">
                            <small class="text-muted">
                                <strong>Component types:</strong>
                                Verse, Chorus, Refrain, Bridge, Pre-Chorus, Tag, Coda, Intro, Outro, Interlude
                            </small>
                        </div>

                        <!-- -------------------------------------------------
                             ARRANGEMENT EDITOR (#161)
                             Customise the display order of song components.
                             Uses human-readable labels (e.g. "Verse 1, Chorus")
                             instead of raw component indexes.
                             ------------------------------------------------- -->
                        <hr class="my-3">
                        <h6 class="fw-semibold mb-2">
                            <i class="bi bi-arrow-down-up me-1"></i>Arrangement
                            <small class="text-muted fw-normal ms-2">(display order)</small>
                        </h6>

                        <!-- Arrangement chip display — rendered dynamically -->
                        <div id="arrangement-chips" class="d-flex flex-wrap gap-1 mb-2" style="min-height: 32px;"></div>

                        <!-- Arrangement text input for manual editing -->
                        <div class="input-group input-group-sm mb-2">
                            <input
                                type="text"
                                class="form-control"
                                id="arrangement-input"
                                placeholder="e.g. Verse 1, Chorus, Verse 2, Chorus, Verse 3, Chorus"
                                aria-label="Arrangement order (comma-separated component labels)"
                            >
                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                id="btnApplyArrangement"
                                title="Apply arrangement"
                            >
                                <i class="bi bi-check-lg"></i> Apply
                            </button>
                        </div>

                        <!-- Validation feedback -->
                        <div id="arrangement-feedback" class="small mb-2" style="display: none;"></div>

                        <!-- Quick action buttons -->
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                id="btnArrangementAuto"
                                title="Insert chorus after each verse"
                            >
                                <i class="bi bi-magic me-1"></i>Auto: Chorus after each verse
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                id="btnArrangementSequential"
                                title="Use sequential order (clear arrangement)"
                            >
                                <i class="bi bi-arrow-down me-1"></i>Sequential (clear)
                            </button>
                        </div>

                        <div class="p-2 rounded" style="background-color: var(--ih-bg-card); border: 1px solid var(--ih-border);">
                            <small class="text-muted">
                                <strong>Arrangement:</strong>
                                Type component labels separated by commas. Use the name and number
                                (e.g. <code>Verse 1</code>, <code>Chorus</code>, <code>Bridge</code>).
                                Leave empty or click "Sequential" for default order.
                            </small>
                        </div>
                    </div>
                    <!-- END Structure Tab Panel -->


                    <!-- -------------------------------------------------
                         CREDITS TAB PANEL
                         Song attribution fields:
                         - Writers (lyricists) — dynamic add/remove list
                         - Composers (music)   — dynamic add/remove list
                         - Copyright text       — single text field
                         ------------------------------------------------- -->
                    <div
                        class="tab-pane fade"
                        id="panel-credits"
                        role="tabpanel"
                        aria-labelledby="tab-credits"
                    >
                        <!-- Writers Section — list of lyricist names -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="bi bi-pen me-1"></i>Writers (Lyricists)
                            </label>

                            <!-- Dynamic list of writer input rows -->
                            <div id="writers-container">
                                <!--
                                     Each writer row is rendered by editor.js:
                                     <div class="dynamic-list-row">
                                         <input type="text" class="form-control form-control-sm writer-input" value="...">
                                         <button class="btn-remove-row" title="Remove writer">
                                             <i class="bi bi-x-lg"></i>
                                         </button>
                                     </div>
                                -->
                            </div>
                            <!-- Add Writer button is dynamically rendered by editor.js inside writers-container -->
                        </div>

                        <!-- Composers Section — list of music composer names -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="bi bi-music-note me-1"></i>Composers
                            </label>

                            <!-- Dynamic list of composer input rows -->
                            <div id="composers-container">
                                <!--
                                     Each composer row is rendered by editor.js:
                                     <div class="dynamic-list-row">
                                         <input type="text" class="form-control form-control-sm composer-input" value="...">
                                         <button class="btn-remove-row" title="Remove composer">
                                             <i class="bi bi-x-lg"></i>
                                         </button>
                                     </div>
                                -->
                            </div>
                            <!-- Add Composer button is dynamically rendered by editor.js inside composers-container -->
                        </div>

                        <!-- Translations Section — linked translations in other languages (#352) -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="bi bi-translate me-1"></i>Translations
                            </label>
                            <div class="form-text mb-2" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                Link this song to its translations in other languages. Linked songs appear on each other's page.
                            </div>

                            <!-- Dynamic list of translation rows -->
                            <div id="translations-container">
                                <!-- Rendered by editor.js -->
                            </div>

                            <!-- Add Translation form -->
                            <div class="input-group input-group-sm mt-2">
                                <input type="text" class="form-control" id="add-translation-songid"
                                       placeholder="Target Song ID (e.g. CP-0001)" list="translation-song-list">
                                <datalist id="translation-song-list"></datalist>
                                <button type="button" class="btn btn-outline-primary" id="add-translation-btn">
                                    <i class="bi bi-plus-lg me-1"></i>Link
                                </button>
                            </div>
                        </div>

                        <!-- Copyright Text — free-text copyright notice -->
                        <div class="mb-3">
                            <label for="edit-copyright" class="form-label">
                                <i class="bi bi-c-circle me-1"></i>Copyright
                            </label>
                            <textarea
                                class="form-control"
                                id="edit-copyright"
                                rows="2"
                                placeholder="e.g. Copyright 2024 Hillsong Music Publishing"
                            ></textarea>
                            <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                Full copyright text as it should appear in the application.
                            </div>
                        </div>
                    </div>
                    <!-- END Credits Tab Panel -->


                    <!-- -------------------------------------------------
                         PREVIEW TAB PANEL
                         Read-only rendered preview of the song, styled
                         similarly to how it appears in the main iHymns app.
                         Dynamically rendered by editor.js when this tab
                         is activated.
                         ------------------------------------------------- -->
                    <div
                        class="tab-pane fade"
                        id="panel-preview"
                        role="tabpanel"
                        aria-labelledby="tab-preview"
                    >
                        <div class="preview-container" id="preview-container">
                            <!-- Preview content is rendered by editor.js.
                                 The structure will look like:

                                 <div class="preview-title">Amazing Grace</div>
                                 <div class="text-muted mb-3" style="font-size: 0.85rem;">#1 - Hymnal</div>

                                 <div class="preview-component-label">Verse 1</div>
                                 <div class="preview-lyrics">Amazing grace how sweet the sound...</div>

                                 <div class="preview-component-label">Chorus</div>
                                 <div class="preview-lyrics">My chains are gone I've been set free...</div>

                                 <div class="preview-credits">
                                     <div><strong>Writers:</strong> John Newton</div>
                                     <div><strong>Copyright:</strong> Public Domain</div>
                                 </div>
                            -->

                            <!-- Placeholder text shown before preview is generated -->
                            <div class="text-center text-muted py-5" id="previewEmpty">
                                <i class="bi bi-eye-slash" style="font-size: 2rem;"></i>
                                <p class="mt-2">Preview will appear here when a song is loaded</p>
                            </div>
                        </div>
                    </div>
                    <!-- END Preview Tab Panel -->

                </div>
                <!-- END Tab Content -->

            </div>
            <!-- END Editor Form -->

        </main>
        <!-- END Main Edit Panel -->

    </div>
    <!-- END Editor Wrapper -->


    <!-- =================================================================
         BOTTOM STATUS BAR
         Persistent bar at the bottom of the viewport showing:
         - Save status indicator (green dot = saved, amber dot = unsaved)
         - Total song count in the loaded dataset
         - Timestamp of the last save/export action
         ================================================================= -->
    <footer class="status-bar">

        <!-- Left section — save status -->
        <div class="me-auto d-flex align-items-center">
            <!-- Coloured dot indicator — class toggled by editor.js -->
            <span class="status-indicator saved" id="status-indicator"></span>
            <!-- Status text (e.g., "All changes saved" or "Unsaved changes") -->
            <span id="status-text">Ready</span>
            <!-- Unsaved changes warning badge -->
            <span id="status-unsaved-warning" class="badge bg-warning text-dark ms-2" style="display: none;">
                <span id="status-modified">0</span> unsaved
            </span>
        </div>

        <!-- Centre section — total songs loaded -->
        <div class="mx-3">
            <i class="bi bi-collection me-1"></i>
            <span id="status-total">0</span> songs loaded
        </div>

        <!-- Right section — last saved timestamp -->
        <div>
            <i class="bi bi-clock me-1"></i>
            Last saved: <span id="status-save-time">Never</span>
        </div>
    </footer>


    <!-- =================================================================
         COMPONENT TEMPLATE
         Hidden template used by editor.js to clone new component cards
         when the user clicks "Add Component". Kept in the HTML so the
         structure is easy to maintain alongside the rest of the markup.
         ================================================================= -->
    <template id="componentTemplate">
        <div class="component-card" data-component-index="">
            <div class="d-flex align-items-start gap-2">

                <!-- Drag handle — allows reordering via drag-and-drop -->
                <span class="drag-handle" title="Drag to reorder">
                    <i class="bi bi-grip-vertical"></i>
                </span>

                <!-- Component number badge — updated dynamically -->
                <span class="component-number">0</span>

                <!-- Component content area -->
                <div class="flex-grow-1">
                    <div class="row g-2 mb-2">

                        <!-- Component type dropdown — verse, chorus, bridge, etc. -->
                        <div class="col-md-5">
                            <select class="form-select form-select-sm component-type" aria-label="Component type">
                                <option value="verse">Verse</option>
                                <option value="chorus">Chorus</option>
                                <option value="bridge">Bridge</option>
                                <option value="pre-chorus">Pre-Chorus</option>
                                <option value="tag">Tag</option>
                                <option value="coda">Coda</option>
                                <option value="intro">Intro</option>
                                <option value="outro">Outro</option>
                            </select>
                        </div>

                        <!-- Component number — e.g., Verse 1, Verse 2 -->
                        <div class="col-md-3">
                            <input
                                type="number"
                                class="form-control form-control-sm component-num"
                                placeholder="#"
                                min="1"
                                aria-label="Component number"
                            >
                        </div>

                        <!-- Component action buttons — move up, move down, remove -->
                        <div class="col-md-4 text-end">
                            <!-- Move Up — shifts this component one position earlier -->
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary btn-move-up"
                                title="Move component up"
                            >
                                <i class="bi bi-arrow-up"></i>
                            </button>
                            <!-- Move Down — shifts this component one position later -->
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary btn-move-down"
                                title="Move component down"
                            >
                                <i class="bi bi-arrow-down"></i>
                            </button>
                            <!-- Remove — deletes this component entirely -->
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger btn-remove-component"
                                title="Remove this component"
                            >
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Lyrics textarea — the actual lyric text for this component -->
                    <textarea
                        class="form-control component-lyrics"
                        rows="4"
                        placeholder="Enter lyrics for this section..."
                        aria-label="Component lyrics"
                    ></textarea>
                </div>
            </div>
        </div>
    </template>


    <!-- =================================================================
         WRITER ROW TEMPLATE
         Hidden template for adding a new writer input row in the
         Credits tab. Cloned by editor.js when "Add Writer" is clicked.
         ================================================================= -->
    <template id="writerTemplate">
        <div class="dynamic-list-row">
            <input
                type="text"
                class="form-control form-control-sm writer-input"
                placeholder="Writer name"
                aria-label="Writer name"
            >
            <button type="button" class="btn-remove-row" title="Remove this writer">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </template>


    <!-- =================================================================
         COMPOSER ROW TEMPLATE
         Hidden template for adding a new composer input row in the
         Credits tab. Cloned by editor.js when "Add Composer" is clicked.
         ================================================================= -->
    <template id="composerTemplate">
        <div class="dynamic-list-row">
            <input
                type="text"
                class="form-control form-control-sm composer-input"
                placeholder="Composer name"
                aria-label="Composer name"
            >
            <button type="button" class="btn-remove-row" title="Remove this composer">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </template>


    <!-- =================================================================
         JAVASCRIPT DEPENDENCIES
         Bootstrap 5.3 JS bundle (includes Popper for dropdowns) loaded
         from CDN, followed by the editor's own JavaScript module.
         ================================================================= -->

    <!-- Toast notification container — dynamically populated by editor.js -->
    <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;"></div>

    <!-- Bootstrap 5.3 JavaScript bundle — required for tabs, dropdowns, and other interactive components -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"
    ></script>

    <!-- Editor JavaScript — all interactive logic (loading, saving, editing, previewing)
         is handled in this separate file to keep concerns separated -->
    <script src="editor.js"></script>

</body>
</html>
