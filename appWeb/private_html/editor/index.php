<?php
/**
 * ============================================================================
 * iHymns Song Editor — Web-Based Developer Tool
 * ============================================================================
 *
 * This file provides a browser-based interface for editing the songs.json
 * data that powers the iHymns application. It is intended exclusively for
 * developer use behind the private_html/ directory — no authentication is
 * included because access is restricted at the server/directory level.
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or modification of this file, via any medium, is strictly
 * prohibited.
 *
 * @package   iHymns
 * @subpackage SongEditor
 * @author    MWBM Partners Ltd
 * @copyright 2026 MWBM Partners Ltd
 * @license   Proprietary — All rights reserved
 * ============================================================================
 */
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
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1p6QIGY0RSBS0gI05TQFE3pDf3rJpp"
        crossorigin="anonymous"
    >

    <!-- Bootstrap Icons — icon font for UI controls (drag handles, buttons, etc.) -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- =================================================================
         INLINE STYLES — Editor-specific theming and layout overrides
         Dark/warm theme with amber accent colours matching the main iHymns app.
         All styles are inline so there is no external CSS dependency.
         ================================================================= -->
    <style>
        /* ---------------------------------------------------------------
           ROOT VARIABLES — Amber/warm palette tokens used throughout.
           These mirror the warm tones of the main iHymns application.
           --------------------------------------------------------------- */
        :root {
            --ih-amber:          #f59e0b;   /* Primary amber accent           */
            --ih-amber-light:    #fbbf24;   /* Lighter amber for hover states */
            --ih-amber-dark:     #d97706;   /* Darker amber for active states */
            --ih-amber-subtle:   #78350f;   /* Very dark amber for backgrounds*/
            --ih-bg-body:        #1a1a1a;   /* Page background                */
            --ih-bg-sidebar:     #141414;   /* Left sidebar background        */
            --ih-bg-card:        #222222;   /* Card / panel backgrounds       */
            --ih-bg-input:       #2a2a2a;   /* Form input backgrounds         */
            --ih-border:         #333333;   /* Default border colour          */
            --ih-text-primary:   #f5f5f5;   /* Primary text (near-white)      */
            --ih-text-muted:     #9ca3af;   /* Muted / secondary text         */
        }

        /* ---------------------------------------------------------------
           GLOBAL BODY — Dark background, warm-tinted text
           --------------------------------------------------------------- */
        body {
            background-color: var(--ih-bg-body);
            color: var(--ih-text-primary);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            /* Prevent body scroll — the sidebar and main panel scroll independently */
            overflow: hidden;
            height: 100vh;
        }

        /* ---------------------------------------------------------------
           TOP NAVBAR — Fixed dark bar with amber branding
           --------------------------------------------------------------- */
        .navbar-editor {
            background-color: #111111;
            border-bottom: 1px solid var(--ih-border);
            padding: 0.5rem 1rem;
        }

        /* Brand text styled in amber */
        .navbar-editor .navbar-brand {
            color: var(--ih-amber);
            font-weight: 700;
            font-size: 1.15rem;
        }
        .navbar-editor .navbar-brand:hover {
            color: var(--ih-amber-light);
        }

        /* Amber-outline buttons used throughout the navbar */
        .btn-amber {
            color: var(--ih-amber);
            border-color: var(--ih-amber);
            background-color: transparent;
        }
        .btn-amber:hover,
        .btn-amber:focus {
            color: #000;
            background-color: var(--ih-amber);
            border-color: var(--ih-amber);
        }
        .btn-amber:active {
            color: #000;
            background-color: var(--ih-amber-dark);
            border-color: var(--ih-amber-dark);
        }

        /* Solid amber button variant */
        .btn-amber-solid {
            color: #000;
            background-color: var(--ih-amber);
            border-color: var(--ih-amber);
            font-weight: 600;
        }
        .btn-amber-solid:hover {
            background-color: var(--ih-amber-light);
            border-color: var(--ih-amber-light);
            color: #000;
        }

        /* ---------------------------------------------------------------
           LAYOUT CONTAINER — Full-height flex layout beneath the navbar
           --------------------------------------------------------------- */
        .editor-wrapper {
            display: flex;
            /* Fill remaining viewport height below navbar and above status bar */
            height: calc(100vh - 56px - 36px);
            overflow: hidden;
        }

        /* ---------------------------------------------------------------
           LEFT SIDEBAR — Songbook filter, search, and song list
           --------------------------------------------------------------- */
        .editor-sidebar {
            background-color: var(--ih-bg-sidebar);
            border-right: 1px solid var(--ih-border);
            width: 25%;
            min-width: 260px;
            max-width: 380px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Sidebar header area (filter + search) */
        .sidebar-header {
            padding: 0.75rem;
            border-bottom: 1px solid var(--ih-border);
            flex-shrink: 0;
        }

        /* Sidebar song list — scrollable list of songs */
        .song-list-container {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }

        /* Individual song entry in the sidebar list */
        .song-list-item {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--ih-border);
            cursor: pointer;
            transition: background-color 0.15s ease;
        }
        .song-list-item:hover {
            background-color: var(--ih-bg-card);
        }
        /* Currently selected / active song highlighted with amber accent */
        .song-list-item.active {
            background-color: var(--ih-amber-subtle);
            border-left: 3px solid var(--ih-amber);
        }

        /* Song title within the list item */
        .song-list-item .song-title {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--ih-text-primary);
            margin-bottom: 0;
        }
        /* Song metadata line (number, songbook) beneath the title */
        .song-list-item .song-meta {
            font-size: 0.75rem;
            color: var(--ih-text-muted);
        }

        /* Sidebar footer showing song count */
        .sidebar-footer {
            padding: 0.5rem 0.75rem;
            border-top: 1px solid var(--ih-border);
            font-size: 0.8rem;
            color: var(--ih-text-muted);
            flex-shrink: 0;
            text-align: center;
        }

        /* ---------------------------------------------------------------
           MAIN EDIT PANEL — Tabs and form area (right side)
           --------------------------------------------------------------- */
        .editor-main {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.25rem;
            background-color: var(--ih-bg-body);
        }

        /* Tab navigation styling — amber active indicator */
        .nav-tabs .nav-link {
            color: var(--ih-text-muted);
            border: none;
            border-bottom: 2px solid transparent;
            padding: 0.6rem 1rem;
        }
        .nav-tabs .nav-link:hover {
            color: var(--ih-text-primary);
            border-bottom-color: var(--ih-border);
        }
        .nav-tabs .nav-link.active {
            color: var(--ih-amber);
            background-color: transparent;
            border-bottom: 2px solid var(--ih-amber);
        }

        /* ---------------------------------------------------------------
           FORM CONTROLS — Dark-themed inputs, selects, and textareas
           --------------------------------------------------------------- */
        .form-control,
        .form-select {
            background-color: var(--ih-bg-input);
            border-color: var(--ih-border);
            color: var(--ih-text-primary);
        }
        .form-control:focus,
        .form-select:focus {
            background-color: var(--ih-bg-input);
            border-color: var(--ih-amber);
            color: var(--ih-text-primary);
            box-shadow: 0 0 0 0.2rem rgba(245, 158, 11, 0.25);
        }
        .form-label {
            color: var(--ih-text-muted);
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.3rem;
        }

        /* ---------------------------------------------------------------
           SONG COMPONENT CARDS — Individual verse/chorus/bridge blocks
           in the Structure tab. Each has a drag handle on the left.
           --------------------------------------------------------------- */
        .component-card {
            background-color: var(--ih-bg-card);
            border: 1px solid var(--ih-border);
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            position: relative;
        }

        /* Drag handle icon on the left edge of each component card */
        .drag-handle {
            cursor: grab;
            color: var(--ih-text-muted);
            font-size: 1.2rem;
            padding: 0.25rem;
            user-select: none;
            transition: color 0.15s ease;
        }
        .drag-handle:hover {
            color: var(--ih-amber);
        }
        /* Visual feedback while actively dragging */
        .drag-handle:active {
            cursor: grabbing;
        }

        /* Component number badge */
        .component-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background-color: var(--ih-amber-subtle);
            color: var(--ih-amber);
            font-weight: 600;
            font-size: 0.8rem;
        }

        /* ---------------------------------------------------------------
           PREVIEW TAB — Read-only song preview styled similarly to app
           --------------------------------------------------------------- */
        .preview-container {
            background-color: var(--ih-bg-card);
            border: 1px solid var(--ih-border);
            border-radius: 0.5rem;
            padding: 1.5rem;
            min-height: 300px;
        }

        /* Song title in the preview pane */
        .preview-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--ih-amber);
            margin-bottom: 0.25rem;
        }

        /* Component type labels in preview (e.g., "Verse 1", "Chorus") */
        .preview-component-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--ih-amber-dark);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 1rem;
            margin-bottom: 0.25rem;
        }

        /* Lyrics text block in preview */
        .preview-lyrics {
            white-space: pre-wrap;
            font-size: 1rem;
            line-height: 1.6;
            color: var(--ih-text-primary);
        }

        /* Credits section at the bottom of the preview */
        .preview-credits {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--ih-border);
            font-size: 0.85rem;
            color: var(--ih-text-muted);
        }

        /* ---------------------------------------------------------------
           BOTTOM STATUS BAR — Fixed bar showing save state and stats
           --------------------------------------------------------------- */
        .status-bar {
            background-color: #111111;
            border-top: 1px solid var(--ih-border);
            height: 36px;
            display: flex;
            align-items: center;
            padding: 0 1rem;
            font-size: 0.8rem;
            color: var(--ih-text-muted);
        }

        /* Unsaved changes indicator dot */
        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 0.4rem;
        }
        /* Green = saved / no pending changes */
        .status-indicator.saved {
            background-color: #22c55e;
        }
        /* Amber = unsaved changes present */
        .status-indicator.unsaved {
            background-color: var(--ih-amber);
        }

        /* ---------------------------------------------------------------
           UTILITY — Scrollbar styling for webkit browsers
           --------------------------------------------------------------- */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--ih-bg-sidebar);
        }
        ::-webkit-scrollbar-thumb {
            background: var(--ih-border);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* ---------------------------------------------------------------
           EMPTY STATE — Shown when no song is selected or loaded
           --------------------------------------------------------------- */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--ih-text-muted);
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--ih-border);
        }

        /* ---------------------------------------------------------------
           DYNAMIC LIST INPUTS — Writer / composer add/remove rows
           in the Credits tab
           --------------------------------------------------------------- */
        .dynamic-list-row {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            align-items: center;
        }
        .dynamic-list-row .form-control {
            flex: 1;
        }

        /* Small remove button on dynamic list rows */
        .btn-remove-row {
            color: #ef4444;
            background: transparent;
            border: 1px solid #ef4444;
            border-radius: 0.375rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
            line-height: 1;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-remove-row:hover {
            background-color: #ef4444;
            color: #fff;
        }

        /* ---------------------------------------------------------------
           RESPONSIVE OVERRIDE — Stack sidebar below main on small screens
           --------------------------------------------------------------- */
        @media (max-width: 768px) {
            .editor-wrapper {
                flex-direction: column;
                height: calc(100vh - 56px - 36px);
            }
            .editor-sidebar {
                width: 100%;
                max-width: none;
                max-height: 40vh;
            }
        }
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
                id="btnLoadJson"
                title="Load a songs.json file from disk"
            >
                <i class="bi bi-folder2-open me-1"></i>Load JSON
            </button>

            <!-- SAVE JSON — Saves the current state back to a downloadable JSON file -->
            <button
                type="button"
                class="btn btn-sm btn-amber-solid"
                id="btnSaveJson"
                title="Download the current songs as a JSON file"
            >
                <i class="bi bi-download me-1"></i>Save JSON
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
                        <a class="dropdown-item" href="#" id="exportJson">
                            <i class="bi bi-filetype-json me-2"></i>Export as JSON
                        </a>
                    </li>
                    <!-- Export as CSV — tabular export for spreadsheets -->
                    <li>
                        <a class="dropdown-item" href="#" id="exportCsv">
                            <i class="bi bi-filetype-csv me-2"></i>Export as CSV
                        </a>
                    </li>
                </ul>
            </div>

            <!-- IMPORT — Triggers the hidden import file input -->
            <button
                type="button"
                class="btn btn-sm btn-amber"
                id="btnImport"
                title="Import songs from an external JSON or CSV file"
            >
                <i class="bi bi-box-arrow-in-down me-1"></i>Import
            </button>
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
                        id="filterSongbook"
                        aria-label="Filter by songbook"
                        title="Filter songs by songbook"
                    >
                        <!-- Default option showing all songbooks -->
                        <option value="">All Songbooks</option>
                        <!-- Additional <option> elements are populated by editor.js -->
                    </select>
                </div>

                <!-- Search input — live text search across song titles -->
                <div class="input-group input-group-sm">
                    <span class="input-group-text" style="background-color: var(--ih-bg-input); border-color: var(--ih-border); color: var(--ih-text-muted);">
                        <i class="bi bi-search"></i>
                    </span>
                    <input
                        type="text"
                        class="form-control"
                        id="searchSongs"
                        placeholder="Search songs..."
                        aria-label="Search songs by title"
                    >
                </div>
            </div>

            <!-- Song list — scrollable container; each song is a clickable row -->
            <div class="song-list-container" id="songList">
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

            <!-- Sidebar Footer — Song count display -->
            <div class="sidebar-footer">
                <span id="songCount">0 songs</span>
                <!-- Filtered count shown when a filter is active -->
                <span id="songCountFiltered" style="display: none;"> (showing <span id="filteredCount">0</span>)</span>
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
                            <label for="fieldTitle" class="form-label">
                                Song Title <span class="text-danger">*</span>
                            </label>
                            <input
                                type="text"
                                class="form-control"
                                id="fieldTitle"
                                placeholder="Enter song title"
                                required
                            >
                        </div>

                        <!-- Song Number and Songbook — displayed side by side -->
                        <div class="row mb-3">
                            <!-- Song Number — the numeric identifier within a songbook -->
                            <div class="col-md-4">
                                <label for="fieldNumber" class="form-label">Song Number</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    id="fieldNumber"
                                    placeholder="e.g. 42"
                                    min="1"
                                >
                            </div>

                            <!-- Songbook — the collection this song belongs to -->
                            <div class="col-md-8">
                                <label for="fieldSongbook" class="form-label">Songbook</label>
                                <select class="form-select" id="fieldSongbook">
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
                            <label for="fieldCCLI" class="form-label">CCLI Number</label>
                            <input
                                type="text"
                                class="form-control"
                                id="fieldCCLI"
                                placeholder="e.g. 1234567"
                            >
                            <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                The CCLI song number for licensing and reporting purposes.
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
                                id="btnAddComponent"
                                title="Add a new song component (verse, chorus, etc.)"
                            >
                                <i class="bi bi-plus-circle me-1"></i>Add Component
                            </button>
                        </div>

                        <!-- Legend explaining the available component types -->
                        <div class="mt-3 p-2 rounded" style="background-color: var(--ih-bg-card); border: 1px solid var(--ih-border);">
                            <small class="text-muted">
                                <strong>Component types:</strong>
                                Verse, Chorus, Refrain, Bridge, Pre-Chorus, Tag, Coda, Intro, Outro
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
                            <div id="writersList">
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

                            <!-- Add Writer button -->
                            <button
                                type="button"
                                class="btn btn-sm btn-amber mt-1"
                                id="btnAddWriter"
                                title="Add another writer"
                            >
                                <i class="bi bi-plus me-1"></i>Add Writer
                            </button>
                        </div>

                        <!-- Composers Section — list of music composer names -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="bi bi-music-note me-1"></i>Composers
                            </label>

                            <!-- Dynamic list of composer input rows -->
                            <div id="composersList">
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

                            <!-- Add Composer button -->
                            <button
                                type="button"
                                class="btn btn-sm btn-amber mt-1"
                                id="btnAddComposer"
                                title="Add another composer"
                            >
                                <i class="bi bi-plus me-1"></i>Add Composer
                            </button>
                        </div>

                        <!-- Copyright Text — free-text copyright notice -->
                        <div class="mb-3">
                            <label for="fieldCopyright" class="form-label">
                                <i class="bi bi-c-circle me-1"></i>Copyright
                            </label>
                            <textarea
                                class="form-control"
                                id="fieldCopyright"
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
                        <div class="preview-container" id="previewContainer">
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
            <span class="status-indicator saved" id="statusIndicator"></span>
            <!-- Status text (e.g., "All changes saved" or "Unsaved changes") -->
            <span id="statusText">Ready</span>
        </div>

        <!-- Centre section — total songs loaded -->
        <div class="mx-3">
            <i class="bi bi-collection me-1"></i>
            <span id="statusTotalSongs">0</span> songs loaded
        </div>

        <!-- Right section — last saved timestamp -->
        <div>
            <i class="bi bi-clock me-1"></i>
            Last saved: <span id="statusLastSaved">Never</span>
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
                                <option value="refrain">Refrain</option>
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
