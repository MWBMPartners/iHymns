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

/* CSRF token mint — MUST run BEFORE any HTML output (mirrors editor2.php:34).
   csrfToken() calls initSession() → session_start(), so the session cookie
   and the freshly-minted $_SESSION['csrf_token'] are committed to the response
   headers + session store while we still own the output buffer. The old code
   first called csrfToken() at the inline <script> ~1640 lines down, deep in the
   HTML body; for a token-adopted session (ihymns_auth cookie, no prior PHP
   session) that was the FIRST mint, and minting/persisting a brand-new token
   that late made api2.php's validateCsrf() (X-CSRF-Token header) reject the
   subsequent delete_song POST with a 403 "Invalid or missing CSRF token".
   Establishing it here, before <!DOCTYPE>, gives the same early-mint guarantee
   that makes the v2 editor's CSRF validate. The window.IHYMNS_EDITOR_CSRF emit
   below now just re-reads this same session token. (delete_song 403 fix) */
$editorCsrf = function_exists('csrfToken') ? csrfToken() : '';

/* External-link type registry for the Song-Editor Links tab (#833).
   Empty array on pre-migration installs — the Links tab still
   renders but the dropdown shows the empty-state hint. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
    . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
$linkTypesForSong = [];
try {
    $linkTypesForSong = loadExternalLinkTypesFor(getDbMysqli(), 'song');
} catch (\Throwable $_e) { /* probe failure → empty registry */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- =================================================================
         HEAD — Meta, Bootstrap 5.3 CDN, Bootstrap Icons, Page Title
         ================================================================= -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Page title shown in the browser tab -->
    <title>iHymns Song Editor</title>

    <!-- #955 — synchronous theme resolver. Sets data-bs-theme,
         data-ihymns-theme, data-ihymns-contrast, data-ihymns-cvd
         from localStorage BEFORE any CSS loads so the editor paints
         with the correct theme. The Song Editor has its own bespoke
         <head> (doesn't go through head-libs.php), so we include the
         partial directly. -->
    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-theme-init.php'; ?>

    <?php
    /* #1676 — Bootstrap + Bootstrap-Icons CSS from the ONE shared emitter.
       This page did carry `integrity` (unlike editor2.php / import2.php), but it
       pinned 5.3.3 while APP_CONFIG says 5.3.6 — so the v1 and v2 editors were
       running different Bootstrap builds, and neither matched the rest of admin.
       Sourcing both from the registry is what makes a version bump a one-line
       change instead of a four-file hunt. */
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap_assets.php';
    echo ihymns_bootstrap_css_links();
    ?>

    <!-- Shared iHymns palette (public site) + admin/editor styles -->
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__, 2) . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__, 2) . '/css/admin.css') ?>">
    <!-- Accessibility modes (#1643). The editor has a bespoke <head> rather than
         including head-libs.php, so it needs this link of its own — that
         divergence is exactly why it was missed the first time. Must stay AFTER
         admin.css so the high-contrast !important rules win. -->
    <link rel="stylesheet" href="/css/accessibility.css?v=<?= filemtime(dirname(__DIR__, 2) . '/css/accessibility.css') ?>">

    <!-- =================================================================
         INLINE STYLES — reserved for genuinely editor-specific tweaks only.
         Shared layout, colours, buttons, cards are in /css/admin.css.
         ================================================================= -->
    <style>
        /* Shared layout, colours, buttons, cards → /css/admin.css
           Add editor-only tweaks here if truly needed. */
    </style>
    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <!-- =================================================================
         TOP NAVBAR
         Editor branding + primary action buttons: Save, Revisions,
         Import (server-side bulk: .zip / VideoPsalm), and per-song
         Export in the sidebar footer. (The whole-corpus Load/Save/Export
         JSON workflow was retired in WS-D #1016 — the editor is fully
         DB-direct.)
         ================================================================= -->
    <nav class="navbar navbar-editor d-flex align-items-center">

        <!-- Brand / logo area. Clicking returns to the admin dashboard —
             important in PWA mode where there's no browser chrome. -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="/manage/"
           title="Back to Admin Dashboard">
            <i class="bi bi-music-note-beamed"></i>
            <span class="navbar-brand-text">iHymns Song Editor</span>
        </a>

        <!-- Quick navigation links. `/manage/` returns to the admin dashboard;
             `/` returns to the public iHymns app (important in PWA mode
             where there's no browser Back button). -->
        <div class="d-flex align-items-center gap-2 me-auto ms-2">
            <a href="/manage/"
               class="btn btn-sm btn-outline-secondary"
               title="Back to Admin Dashboard">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="/"
               class="btn btn-sm btn-outline-secondary"
               title="Back to the iHymns app home">
                <i class="bi bi-house me-1"></i>Home
            </a>
        </div>

        <!-- Action buttons group — aligned to the right -->
        <div class="d-flex align-items-center gap-2">

            <!-- LOAD JSON / LOAD URL buttons removed (#589). The editor
                 auto-loads from the MySQL backend via `?action=load` on
                 init, so a curator never needs to point it at a file or
                 remote URL. The Import button below still exists for
                 emergency restore from a JSON / CSV file. -->

            <!-- SAVE — Writes all songs to MySQL (primary path). If the DB
                 is unavailable, the editor falls back to a JSON download so
                 you never lose changes. Starts disabled (#590) — the JS
                 enables it when a song is selected so curators can't
                 click into a no-op. -->
            <button
                type="button"
                class="btn btn-sm btn-amber-solid"
                id="btn-save"
                title="Select a song to enable Save"
                disabled
            >
                <i class="bi bi-floppy me-1"></i><span class="btn-save-label">Save</span>
            </button>

            <!-- REVISIONS — Show revision history for the currently-selected
                 song, with a restore action per revision (#400). Renamed
                 from "History" in #591 for consistency with the
                 /manage/revisions admin menu entry; the underlying button
                 ID is preserved (`btn-history`) so existing JS handlers
                 keep working. -->
            <button
                type="button"
                class="btn btn-sm btn-outline-info"
                id="btn-history"
                title="Select a song to enable Revisions"
                disabled
            >
                <i class="bi bi-clock-history me-1"></i>Revisions
            </button>

            <!-- IMPORT — Triggers the hidden import file input -->
            <button
                type="button"
                class="btn btn-sm btn-amber"
                id="btn-import"
                title="Bulk-import songs from a .zip archive, a VideoPsalm songbook .json, an OpenLyrics/OpenLP .xml, a ProPresenter 6 .pro6, a FreeShow .show, an EasyWorship Songs.db (BETA — unverified against live EasyWorship), a Proclaim .txt/.rtf (whole-hymnal or single song), a ChordPro .cho/.pro/.chopro/.crd/.chord single song (OnSong / OpenSong / WorshipTools interop, #1264), or a PowerPoint .pptx worship deck (slides segmented into songs by their '# number-Songbook' title slides; existing songs are matched, not duplicated). ZIPs accept the .SourceSongData layout (one .txt per song), OpenSong .xml, OpenLyrics .xml, ProPresenter .pro6, FreeShow .show, EasyWorship Songs.db + SongWords.db (these carry their own song/songbook, so they ignore the folder shape), and VideoPsalm .json songbooks at any depth. Bulk imports insert directly into MySQL and never overwrite existing rows."
            >
                <i class="bi bi-box-arrow-in-down me-1"></i>Import
            </button>
            <!-- #1051 — opt-in title dedupe for the next import; read by editor.js,
                 posted as dedupeMode=skip-title. -->
            <span class="form-check form-check-inline ms-1 align-middle"
                  title="When ticked, the next import skips any song whose title already exists in the same songbook (matched ignoring case, punctuation and accents) — catching duplicates that have a different number.">
                <input class="form-check-input" type="checkbox" id="import-dedupe-title">
                <label class="form-check-label small text-muted" for="import-dedupe-title">Skip existing&nbsp;(by&nbsp;title)</label>
            </span>

            <!-- Separator + Admin links / Logout -->
            <span class="text-muted mx-1 navbar-editor-separator">|</span>
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

            <!-- #1180 — drag-to-resize grip (large displays only; CSS hides it
                 ≤768px). Wired by the inline script near the foot of this file. -->
            <div class="sidebar-resize-handle" id="sidebar-resize-handle"
                 role="separator" aria-orientation="vertical"
                 aria-label="Drag to resize the song list" title="Drag to resize"></div>

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

                <!-- Find missing song numbers (#285). Only enabled when a
                     specific songbook is selected — "All Songbooks" has
                     no single numbering to gap-check. -->
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary w-100 mt-2"
                    id="find-missing-numbers-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#missing-numbers-modal"
                    disabled
                    title="Shows gaps in the numbering of the currently filtered songbook"
                >
                    <i class="bi bi-binoculars me-1" aria-hidden="true"></i>
                    Find missing numbers
                </button>
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

                <!-- Empty state shown before the song index has loaded -->
                <div class="empty-state py-5" id="songListEmpty">
                    <i class="bi bi-music-note-list"></i>
                    <p class="mb-1">No songs loaded</p>
                    <small>Loading songs from the database…</small>
                </div>
            </div>

            <!-- Sidebar Footer — Song count + Add/Delete buttons -->
            <div class="sidebar-footer d-flex align-items-center justify-content-center flex-wrap gap-2">
                <!-- #1180 — the redundant "N / total" song-count was removed from
                     here: the total already shows in the page footer ("N songs
                     loaded"), and on the same flex row it crowded + mis-aligned
                     the action buttons. editor.js still updates #song-count if
                     present (guarded), so this is markup-only. -->
                <span class="d-flex gap-1 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-select-mode"
                            title="Multi-select mode (#399)" aria-pressed="false">
                        <i class="bi bi-check2-square me-1"></i>Select
                    </button>
                    <button type="button" class="btn btn-sm btn-amber" id="btn-add-song" title="Add new song">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                    <!-- Consolidated export (#1166 polish): ONE "Export ▾"
                         dropdown listing every format for the open song or the
                         active-filter songbook, replacing the former wall of
                         per-format buttons (which the mobile flex-wrap exposed).
                         Every item ID is preserved, so the existing
                         bindFormat() / inline wiring is unchanged; the old
                         per-format toggle-enable lines simply no-op now. All
                         export handlers already guard (no-song / no-filter →
                         toast), so the single toggle can stay always-enabled.
                         data-bs-auto-close="outside" keeps the menu open while
                         the Lines/slide field is used. -->
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                id="btn-export-all" data-bs-toggle="dropdown"
                                data-bs-auto-close="outside" aria-expanded="false"
                                title="Export the open song or the active-filter songbook to a worship-presentation format">
                            <i class="bi bi-box-arrow-down me-1"></i>Export
                        </button>
                        <ul class="dropdown-menu dropdown-menu-dark" style="max-height:70vh;overflow-y:auto;min-width:15rem">
                            <li><h6 class="dropdown-header">This song</h6></li>
                            <li><a class="dropdown-item" href="#" id="btn-export-song"><i class="bi bi-braces me-2"></i>iHymns JSON</a></li>
                            <li><a class="dropdown-item" href="#" id="pp-export-song"><i class="bi bi-easel me-2"></i>ProPresenter 7+ <span class="text-muted small ms-1">.pro</span></a></li>
                            <li><a class="dropdown-item" href="#" id="p6-export-song"><i class="bi bi-display me-2"></i>ProPresenter 6 <span class="text-muted small ms-1">.pro6</span></a></li>
                            <li><a class="dropdown-item" href="#" id="os-export-song"><i class="bi bi-filetype-xml me-2"></i>OpenSong <span class="text-muted small ms-1">.xml</span></a></li>
                            <li><a class="dropdown-item" href="#" id="ol-export-song"><i class="bi bi-easel me-2"></i>OpenLP / OpenLyrics <span class="text-muted small ms-1">.xml</span></a></li>
                            <li><a class="dropdown-item" href="#" id="vp-export-song"><i class="bi bi-filetype-json me-2"></i>VideoPsalm <span class="text-muted small ms-1">.json</span></a></li>
                            <li><a class="dropdown-item" href="#" id="fs-export-song"><i class="bi bi-easel2 me-2"></i>FreeShow <span class="text-muted small ms-1">.show</span></a></li>
                            <li><a class="dropdown-item" href="#" id="pc-export-song"><i class="bi bi-file-text me-2"></i>Proclaim <span class="text-muted small ms-1">.txt</span></a></li>
                            <li><a class="dropdown-item" href="#" id="ew-export-song"><i class="bi bi-database me-2"></i>EasyWorship <span class="badge bg-warning text-dark ms-1">beta</span></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">This songbook <span class="text-muted fw-normal">· active filter</span></h6></li>
                            <li><a class="dropdown-item" href="#" id="pp-export-songbook"><i class="bi bi-archive me-2"></i>ProPresenter 7+ <span class="text-muted small ms-1">.probundle</span></a></li>
                            <li><a class="dropdown-item" href="#" id="p6-export-songbook"><i class="bi bi-archive me-2"></i>ProPresenter 6 <span class="text-muted small ms-1">.zip</span></a></li>
                            <li><a class="dropdown-item" href="#" id="os-export-songbook"><i class="bi bi-archive me-2"></i>OpenSong <span class="text-muted small ms-1">.zip</span></a></li>
                            <li><a class="dropdown-item" href="#" id="ol-export-songbook"><i class="bi bi-archive me-2"></i>OpenLP / OpenLyrics <span class="text-muted small ms-1">.osz</span></a></li>
                            <li><a class="dropdown-item" href="#" id="vp-export-songbook"><i class="bi bi-journal-text me-2"></i>VideoPsalm <span class="text-muted small ms-1">.json</span></a></li>
                            <li><a class="dropdown-item" href="#" id="fs-export-songbook"><i class="bi bi-archive me-2"></i>FreeShow <span class="text-muted small ms-1">.zip</span></a></li>
                            <li><a class="dropdown-item" href="#" id="pc-export-songbook"><i class="bi bi-archive me-2"></i>Proclaim <span class="text-muted small ms-1">.zip</span></a></li>
                            <li><a class="dropdown-item" href="#" id="ew-export-songbook"><i class="bi bi-database-down me-2"></i>EasyWorship <span class="badge bg-warning text-dark ms-1">beta</span></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <div class="px-3 py-1">
                                    <label class="form-label small mb-1 d-block" for="export-lines-per-slide">
                                        Max lines / slide <span class="text-muted">· presentation formats</span>
                                    </label>
                                    <input type="number" class="form-control form-control-sm" id="export-lines-per-slide"
                                           min="0" max="20" step="1" value="0" style="width:5rem"
                                           aria-label="Maximum lyric lines per slide on export"
                                           title="0 = keep each verse on one slide. Remembered as your default.">
                                </div>
                            </li>
                        </ul>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-delete-song" title="Delete selected song">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                </span>
            </div>

            <!-- Bulk-actions toolbar — shown only in multi-select mode (#399). -->
            <div class="bulk-actions-bar d-none align-items-center justify-content-between px-3 py-2"
                 id="bulk-actions-bar"
                 style="background-color: rgba(129,140,248,0.1); border-top: 1px solid var(--card-border);">
                <span class="small">
                    <span id="bulk-selected-count">0</span> selected
                </span>
                <span class="d-flex gap-1 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bulk-select-all">All</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bulk-select-none">None</button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-bulk-verify" disabled
                            title="Mark selected songs as verified">
                        <i class="bi bi-patch-check me-1"></i>Verify
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-bulk-tag" disabled
                            title="Add or remove tags on selected songs">
                        <i class="bi bi-tags me-1"></i>Tag
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btn-bulk-move" disabled
                            title="Move selected songs to another songbook">
                        <i class="bi bi-arrow-right-circle me-1"></i>Move
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bulk-export" disabled
                            title="Export selected songs as JSON">
                        <i class="bi bi-download me-1"></i>Export
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" id="btn-bulk-delete" disabled>
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

                    <!-- Links tab trigger (#833) — external website links
                         (Hymnary.org, Wikipedia, YouTube performances, etc.)
                         attached to this song via tblSongExternalLinks. -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="tab-links"
                            data-bs-toggle="tab"
                            data-bs-target="#panel-links"
                            type="button"
                            role="tab"
                            aria-controls="panel-links"
                            aria-selected="false"
                        >
                            <i class="bi bi-link-45deg me-1"></i>Links
                        </button>
                    </li>

                    <!-- Tags tab trigger (#496) -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="tab-tags"
                            data-bs-toggle="tab"
                            data-bs-target="#panel-tags"
                            type="button"
                            role="tab"
                            aria-controls="panel-tags"
                            aria-selected="false"
                        >
                            <i class="bi bi-tags me-1"></i>Tags
                        </button>
                    </li>

                    <!-- Media tab trigger (#853) -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="tab-media"
                            data-bs-toggle="tab"
                            data-bs-target="#panel-media"
                            type="button"
                            role="tab"
                            aria-controls="panel-media"
                            aria-selected="false"
                        >
                            <i class="bi bi-music-note-list me-1"></i>Media
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

                        <!-- Song Number · Songbook · CCLI Song Number — one row (#488).
                             Song numbers never exceed 4 digits; the compact col-2
                             frees room for the CCLI field that used to sit on its
                             own full-width line. -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-2">
                                <label for="edit-number" class="form-label">
                                    Song Number<span id="edit-number-required" class="text-danger ms-1" hidden aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="number"
                                    class="form-control"
                                    id="edit-number"
                                    placeholder="e.g. 42"
                                    min="1"
                                    max="9999"
                                >
                                <!-- Toggled by editor.js when the selected songbook
                                     is unofficial (Misc, custom collections); songs
                                     in those songbooks don't have a per-songbook
                                     number and the internal Song ID is the link. #392 -->
                                <div id="edit-number-hint" class="form-text" hidden>
                                    Optional — this songbook is unofficial.
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label for="edit-songbook" class="form-label">Songbook</label>
                                <select class="form-select" id="edit-songbook">
                                    <option value="">Select songbook...</option>
                                    <!-- Options are populated dynamically by editor.js. -->
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="edit-ccli" class="form-label">CCLI Song Number</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="edit-ccli"
                                    placeholder="e.g. 1234567"
                                >
                            </div>
                        </div>

                        <!-- Tune Name + ISWC pair (#497, #488). Two identifiers that
                             are typically set together for traditionally-tuned hymns
                             (HYFRYDOL + T-xxx). -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label for="edit-tune-name" class="form-label">
                                    <i class="bi bi-music-note-list me-1"></i>Tune Name
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="edit-tune-name"
                                    placeholder="e.g. HYFRYDOL, OLD HUNDREDTH"
                                >
                                <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                    Traditional tune name, if known. Uppercase by convention.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="edit-iswc" class="form-label">
                                    <i class="bi bi-upc me-1"></i>ISWC
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="edit-iswc"
                                    placeholder="e.g. T-034.524.680-C"
                                >
                                <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                    International Standard Musical Work Code.
                                </div>
                            </div>
                        </div>

                        <!-- Composition / first-performance origin (Places
                             adoption). Visible input is the human-readable
                             display string; the sibling hidden input
                             carries the tblPlaces.Id of the picked
                             candidate, which the place-search module
                             keeps in sync. Free-typing leaves the hidden
                             id empty so the catalogue still persists the
                             curator-typed string. -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-12">
                                <label for="edit-origin-city" class="form-label">
                                    <i class="bi bi-geo-alt me-1"></i>Composition origin
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="edit-origin-city"
                                    placeholder="Start typing — e.g. Cardiff, Wales"
                                    autocomplete="off"
                                >
                                <input type="hidden" id="edit-origin-city-id">
                                <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                    Where the composition originated or was first performed.
                                    Picks from the live geocoder so two curators picking
                                    &ldquo;Cardiff&rdquo; resolve to one canonical place.
                                </div>
                            </div>
                        </div>

                        <!-- Language — IETF BCP 47 picker (shared with /manage/songbooks
                             via the partial introduced by #685). The hidden output gets a
                             stable id="edit-language" so editor.js can read the composed
                             tag via getElementById without going through a form POST.
                             Closes #687 — the editor's inline copy of the picker has been
                             removed in favour of this single source of truth, so curators
                             see the same vocabulary (live tblScripts + tblRegions, ~28 +
                             ~255 entries) on both surfaces. -->
                        <?php
                            $idPrefix = 'edit-song';
                            $name     = 'language';
                            $tag      = '';
                            $outputId = 'edit-language';
                            require dirname(__DIR__) . DIRECTORY_SEPARATOR
                                . 'includes' . DIRECTORY_SEPARATOR
                                . 'partials' . DIRECTORY_SEPARATOR
                                . 'ietf-language-picker.php';
                            unset($idPrefix, $name, $tag, $outputId);
                        ?>

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
                            <!-- Paste & Reflow (#1043) — ProPresenter-style bulk section entry.
                                 Opens a modal to paste a whole lyrics block and auto-split it
                                 into classified sections, then Apply creates components. -->
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                id="btn-paste-reflow"
                                data-bs-toggle="modal"
                                data-bs-target="#reflow-modal"
                                title="Paste a whole lyrics block and split it into sections (ProPresenter-style Reflow)"
                            >
                                <i class="bi bi-magic me-1"></i>Paste &amp; Reflow
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

                        <!-- Drag-and-drop arrangement builder (#492).
                             POOL = source chips, one per defined component; click
                             to append to the strip.
                             STRIP = the ordered sequence; drag to reorder, click
                             × on a chip to remove. -->
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1">
                                Components <small class="text-muted">(click to add)</small>
                            </label>
                            <div id="arrangement-pool"
                                 class="d-flex flex-wrap gap-1 p-2 rounded"
                                 style="min-height: 44px; background-color: var(--ih-bg-card); border: 1px solid var(--ih-border);"
                                 aria-label="Component pool">
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1">
                                Sequence <small class="text-muted">(drag to reorder, × to remove)</small>
                            </label>
                            <div id="arrangement-strip"
                                 class="d-flex flex-wrap gap-1 p-2 rounded"
                                 style="min-height: 44px; background-color: var(--ih-bg-card); border: 1px solid var(--ih-border);"
                                 aria-label="Arrangement sequence">
                            </div>
                        </div>

                        <!-- Legacy summary-chip row removed (#597) — the
                             #arrangement-strip row above already renders
                             the playback order as draggable chips, and
                             this row was a non-interactive duplicate of
                             the same data which confused curators. -->


                        <!-- Validation feedback (used by the advanced text input
                             below and for preset application errors from #493). -->
                        <div id="arrangement-feedback" class="small mb-2" style="display: none;"></div>

                        <!-- Quick action buttons (#493).
                             Each button carries data-requires with a comma-
                             separated list of component types that must be
                             present before it can fire. editor.js disables any
                             button whose requirements aren't met by the current
                             song and swaps the title to an explanation. -->
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary arrangement-preset"
                                data-preset="chorus-after-each-verse"
                                data-requires="verse,chorus"
                                title="Insert chorus after each verse"
                            >
                                <i class="bi bi-magic me-1"></i>Chorus after each verse
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary arrangement-preset"
                                data-preset="verse-prechorus-chorus"
                                data-requires="verse,pre-chorus,chorus"
                                title="Verse → Pre-Chorus → Chorus (for each verse)"
                            >
                                <i class="bi bi-magic me-1"></i>Verse · Pre-Chorus · Chorus
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary arrangement-preset"
                                data-preset="verse-bridge-verse"
                                data-requires="verse,bridge"
                                title="Verses with a Bridge near the end"
                            >
                                <i class="bi bi-magic me-1"></i>Verses · Bridge · Final Verse
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary arrangement-preset"
                                data-preset="intro-verses-outro"
                                data-requires="intro,verse,outro"
                                title="Intro → all Verses → Outro"
                            >
                                <i class="bi bi-magic me-1"></i>Intro · Verses · Outro
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary arrangement-preset"
                                data-preset="verses-only"
                                data-requires="verse"
                                title="All verses in sequence (no chorus)"
                            >
                                <i class="bi bi-magic me-1"></i>Verses only
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-warning"
                                id="btnArrangementSequential"
                                title="Clear the arrangement — falls back to the order defined above"
                            >
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to component order
                            </button>
                        </div>

                        <!-- Advanced text input — collapsed by default, kept for
                             power-users and clipboard paste-in. -->
                        <details class="mb-2">
                            <summary class="small text-muted" style="cursor: pointer;">
                                Advanced · type arrangement as text
                            </summary>
                            <div class="input-group input-group-sm mt-2">
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
                        </details>

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

                        <!-- Arrangers Section (#497) — who re-arranged the music for this setting -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="bi bi-sliders me-1"></i>Arrangers
                            </label>
                            <div id="arrangers-container"></div>
                        </div>

                        <!-- Adaptors Section (#497) — who adapted the lyrics or melody -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="bi bi-vinyl me-1"></i>Adaptors
                            </label>
                            <div id="adaptors-container"></div>
                        </div>

                        <!-- Translators Section (#497) — who translated the lyrics (distinct from the #352 translation-link list below) -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="bi bi-translate me-1"></i>Translators
                            </label>
                            <div id="translators-container"></div>
                        </div>

                        <!-- Artists Section (#587) — recording / release artist -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="bi bi-mic me-1"></i>Artists
                                <small class="text-muted ms-1">(recording / release artist — useful for contemporary worship songs)</small>
                            </label>
                            <div id="artists-container"></div>
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

                        <!-- Cross-book counterparts (#807) — same hymn in different songbooks.
                             Distinct from Translations: counterparts are typically the same
                             language, different songbook, unrelated number — e.g. Amazing Grace
                             as MP-031 and CH-376 and SDAH-108. -->
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="bi bi-link-45deg me-1"></i>Cross-book counterparts
                            </label>
                            <div class="form-text mb-2" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                Link this song to its appearances in other songbooks (same hymn,
                                different number). Use Translations for other-language versions.
                            </div>

                            <!-- Dynamic list of counterpart rows, rendered by editor.js. -->
                            <div id="song-links-container">
                                <span class="text-muted small">Save the song first, then add counterparts.</span>
                            </div>

                            <!-- Add Counterpart form -->
                            <div class="input-group input-group-sm mt-2">
                                <input type="text" class="form-control" id="add-song-link-songid"
                                       placeholder="Target Song ID (e.g. CH-0376)" list="song-link-song-list">
                                <datalist id="song-link-song-list"></datalist>
                                <button type="button" class="btn btn-outline-primary" id="add-song-link-btn">
                                    <i class="bi bi-plus-lg me-1"></i>Link
                                </button>
                            </div>

                            <!-- Suggested counterparts (#808) — top similar-titled candidates.
                                 Hidden until at least one suggestion exists for the open song. -->
                            <div id="song-link-suggestions" class="mt-3" style="display:none;">
                                <div class="form-text mb-2" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    Suggested counterparts — similar titles in other songbooks:
                                </div>
                                <div id="song-link-suggestions-list">
                                    <!-- Rendered by editor.js -->
                                </div>
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
                         LINKS TAB PANEL (#833)
                         External website links per song (Hymnary.org,
                         Wikipedia, YouTube, Spotify, etc.) — same
                         tblExternalLinkTypes-backed registry and shared
                         row-builder module that powers the songbook
                         + work admin pages. Auto-detect maps the
                         pasted URL to the matching provider so the
                         curator's dropdown selection is one less
                         click in the common case.
                         ------------------------------------------------- -->
                    <div
                        class="tab-pane fade"
                        id="panel-links"
                        role="tabpanel"
                        aria-labelledby="tab-links"
                    >
                        <div class="form-section">
                            <h6 class="section-title">
                                <i class="bi bi-link-45deg me-1"></i>External links
                            </h6>
                            <div class="text-muted small mb-3">
                                Hymnary.org · Internet Archive scans · Wikipedia ·
                                YouTube performances · Spotify recordings · etc.
                                Paste a URL — the provider dropdown auto-detects.
                                <em>Verified</em> means a curator has eyeballed
                                the URL and confirmed it's correct.
                            </div>

                            <?php
                                /* Re-use the shared partial from manage/includes/partials.
                                   The Song Editor lives one folder deeper than the rest
                                   of /manage so the path resolves through the partial's
                                   directory rather than the editor's own. */
                                $containerId    = 'edit-song-ext-links-rows';
                                $addBtnId       = 'edit-song-ext-link-add-btn';
                                $heading        = 'External links';
                                $helpText       = '';
                                $useCardHeading = false;
                                require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes'
                                    . DIRECTORY_SEPARATOR . 'partials'
                                    . DIRECTORY_SEPARATOR . 'external-links-section.php';
                            ?>

                            <div class="form-text small mt-3" style="color: var(--ih-text-muted);">
                                Save the song to persist link changes.
                                Existing links are loaded automatically on song open.
                            </div>
                        </div>
                    </div>
                    <!-- END Links Tab Panel -->


                    <!-- -------------------------------------------------
                         TAGS TAB PANEL (#496)
                         Per-song tag assignment. Chips show current tags
                         (× to remove). Autocomplete input searches
                         tblSongTags; typing a brand-new name + Enter
                         creates the tag. Writes go straight to MySQL
                         via /api?action=bulk_tag (single-songId call).
                         ------------------------------------------------- -->
                    <div
                        class="tab-pane fade"
                        id="panel-tags"
                        role="tabpanel"
                        aria-labelledby="tab-tags"
                    >
                        <div class="form-section">
                            <h6 class="section-title">
                                <i class="bi bi-tags me-1"></i>Tags &amp; Themes
                            </h6>
                            <div class="text-muted small mb-3">
                                Tags power the <strong>Browse by Theme</strong> section on the
                                home page and the <code>/tag/&lt;slug&gt;</code> listing pages.
                                Changes save immediately.
                            </div>

                            <!-- Current assignments — chip list, one per tag.
                                 Rendered by editor.js renderSongTags(). -->
                            <label class="form-label">Assigned tags</label>
                            <div id="song-tags-container"
                                 class="d-flex flex-wrap gap-1 p-2 rounded mb-3"
                                 style="min-height: 44px; background-color: var(--ih-bg-card); border: 1px solid var(--ih-border);">
                                <span class="text-muted small">Loading…</span>
                            </div>

                            <!-- Add-tag picker with live autocomplete. -->
                            <label for="song-tag-input" class="form-label">Add a tag</label>
                            <div class="position-relative">
                                <input
                                    type="text"
                                    class="form-control"
                                    id="song-tag-input"
                                    placeholder="Type to search or create — e.g. Easter, Communion"
                                    autocomplete="off"
                                >
                                <div id="song-tag-suggestions"
                                     class="list-group position-absolute w-100 shadow d-none"
                                     style="z-index: 1050; max-height: 240px; overflow-y: auto;">
                                </div>
                            </div>
                            <div class="form-text" style="color: var(--ih-text-muted); font-size: 0.75rem;">
                                Select an existing tag from the dropdown, or type a new name
                                and press Enter to create it.
                            </div>
                        </div>
                    </div>
                    <!-- END Tags Tab Panel -->


                    <!-- -------------------------------------------------
                         MEDIA TAB PANEL (#853)

                         Per-song accompanying-files manager. The contents
                         are rendered by the song-media-editor.js ESM
                         module which is booted at the bottom of this file
                         (after editor.js so the song-loaded event is
                         observable from boot onwards).
                         ------------------------------------------------- -->
                    <div
                        class="tab-pane fade"
                        id="panel-media"
                        role="tabpanel"
                        aria-labelledby="tab-media"
                    >
                        <div class="form-section">
                            <h6 class="section-title">
                                <i class="bi bi-music-note-list me-1"></i>Accompanying Media
                            </h6>
                            <div class="text-muted small mb-3">
                                Upload audio recordings, sheet music, MIDI sequences and
                                MusicXML notation alongside this song. Files inherit the
                                song's content-access rules — gated songs gate their media
                                automatically. Sheet music / MIDI / MusicXML are stored
                                inside the database; audio is stored on disk under
                                <code>appWeb/uploads/songs/</code> and served via the
                                gated <code>/song-media/&lt;id&gt;</code> route.
                            </div>
                            <div id="song-media-editor-root">
                                <!-- Populated by song-media-editor.js — see <script type="module"> at the bottom of this file. -->
                            </div>
                        </div>
                    </div>
                    <!-- END Media Tab Panel -->


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

    <!-- Bootstrap 5.3 JavaScript bundle — required for tabs, dropdowns, and other
         interactive components. #1676: emitted by the shared helper so the version
         tracks APP_CONFIG rather than a literal pinned here. -->
    <?= ihymns_bootstrap_js_script() ?>

    <!-- Revision history modal (#400). Populated on demand when the
         History button is clicked; shows the timeline + side-by-side
         JSON for each revision + a Restore button per row. -->
    <div class="modal fade" id="history-modal" tabindex="-1" aria-labelledby="history-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content bg-dark text-light border-info">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="history-modal-title">
                        <i class="bi bi-clock-history me-2"></i>Revision history
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="history-list" class="list-group list-group-flush"></div>
                    <div id="history-detail" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- PASTE & REFLOW modal (#1043) — ProPresenter-style bulk section entry.
         Paste a lyrics block, auto-split into classified sections, adjust,
         then Apply to create components via the normal save_song path.
         All section-card UI is built in editor.js (reflowRender). -->
    <div class="modal fade" id="reflow-modal" tabindex="-1" aria-labelledby="reflow-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content bg-dark text-light border-info">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="reflow-modal-title">
                        <i class="bi bi-magic me-2"></i>Paste &amp; Reflow
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- #1180 — prominent, scannable explainer (the old version
                         was small muted text that was easy to skim past). Leads
                         with the load-bearing rule: blank line = new section. -->
                    <div class="small border border-info rounded p-2 mb-3 d-flex gap-2"
                         style="background: rgba(13,202,240,0.08);" role="note">
                        <i class="bi bi-info-circle-fill text-info mt-1 flex-shrink-0"></i>
                        <div>
                            <strong>Put one blank line between each section.</strong>
                            Every block of lines (separated by an empty line) becomes its own
                            component when you Apply. Optionally begin a block with a label on its
                            own line — <code>Verse 1</code>, <code>Chorus</code>, <code>Bridge</code>,
                            <code>Pre-Chorus</code>… — to set its type; unlabelled blocks are
                            auto-classified (a repeated block → Chorus, the rest → numbered Verses).
                            Click <strong>Parse into sections</strong> to preview, adjust the
                            type / number / text of any section, then <strong>Apply sections</strong>.
                        </div>
                    </div>
                    <label for="reflow-input" class="form-label small mb-1">Raw lyrics</label>
                    <textarea id="reflow-input"
                              class="form-control bg-dark text-light border-secondary"
                              rows="8"
                              placeholder="Verse 1&#10;Amazing grace, how sweet the sound&#10;That saved a wretch like me&#10;&#10;Chorus&#10;Praise God, praise God&#10;&#10;Verse 2&#10;..."></textarea>
                    <div class="d-flex gap-2 mt-2 align-items-center">
                        <button type="button" class="btn btn-sm btn-info" id="reflow-parse-btn">
                            <i class="bi bi-arrow-down-up me-1"></i>Parse into sections
                        </button>
                        <span class="text-muted small" id="reflow-count"></span>
                    </div>
                    <hr class="border-secondary">
                    <div id="reflow-blocks">
                        <p class="text-muted small mb-0">Paste lyrics above and click <strong>Parse into sections</strong>.</p>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-amber btn-sm" id="reflow-apply-btn" disabled>
                        <i class="bi bi-check2-circle me-1"></i>Apply sections
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- External-link provider auto-detect + shared card-list editor (#833 / #841).
         Loaded before editor.js so the global module objects are
         available when the editor mounts the Links tab. The Song
         Editor has its own <head> (no head-libs.php), so we include
         the scripts directly here. -->
    <?php $_editorPublicRoot = dirname(__DIR__, 2); ?>
    <script src="/js/modules/external-link-detect.js?v=<?= filemtime($_editorPublicRoot . '/js/modules/external-link-detect.js') ?>"></script>
    <script src="/js/modules/external-links-editor.js?v=<?= filemtime($_editorPublicRoot . '/js/modules/external-links-editor.js') ?>"></script>
    <!-- Places adoption — live location autocomplete on the
         Composition origin input. Must load before editor.js so the
         iHymnsPlaceSearch global exists when editor.js's
         attachPlaceSearch() helper runs. -->
    <script src="/js/modules/place-search.js?v=<?= filemtime($_editorPublicRoot . '/js/modules/place-search.js') ?>"></script>
    <!-- #1594 part 2 — shared combobox keyboard + ARIA helper
         (window.iHymnsComboboxA11y), consumed by the tag-search and
         credit-person popovers below in editor.js. Must load before
         editor.js for the same reason as place-search.js above: this
         page has no head-libs.php (bespoke <head>), so every classic
         global editor.js depends on is loaded explicitly, in order,
         right here. -->
    <script src="/js/modules/combobox-a11y.js?v=<?= filemtime($_editorPublicRoot . '/js/modules/combobox-a11y.js') ?>"></script>
    <script>
        window._iHymnsLinkTypes = <?= json_encode($linkTypesForSong, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        /* #1235 P3 / #1088 — CSRF token for the v2 API (api2.php) that the per-line
           translation/annotation editor POSTs to. The legacy load/save path
           (api.php) is session-only; api2.php's enrichment endpoints additionally
           validate this token via the X-CSRF-Token header. */
        window.IHYMNS_EDITOR_CSRF = <?= json_encode($editorCsrf) ?>;
        window.IHYMNS_EDITOR_API2 = '/manage/editor/api2';
    </script>

    <!-- Editor JavaScript — all interactive logic (loading, saving, editing, previewing)
         is handled in this separate file to keep concerns separated -->
    <script src="editor.js"></script>

    <!-- ProPresenter 7+ exporter (#887). protobufjs is vendored locally
         (vendor/protobuf.min.js, BSD-3-Clause) so the editor works on shared
         hosts + offline; propresenter-export.js exposes window.iHymnsProPresenter.
         Loaded AFTER editor.js so the inline wiring can read its globals. -->
    <script src="vendor/protobuf.min.js"></script>
    <script src="propresenter-export.js"></script>
    <script>
    /* #887 — wire the ProPresenter dropdown to the exporter. Self-contained:
       reads editor.js globals (currentSongId / songData / EDITOR_API_URL /
       getSelectedSongbookFilter / _loadSongsFull) + the ?action=songbook_export
       endpoint; makes NO changes to editor.js. Single song -> .pro; the active
       sidebar songbook filter -> .probundle (bundle of every song in it). */
    (function () {
        'use strict';

        function notify(msg, type) {
            if (typeof showToast === 'function') {
                showToast(msg, type === 'danger' ? 'error' : type);
            } else {
                console.log('[ProPresenter] ' + msg);
            }
        }

        /* Zero-pad width for a song's songbook so files sort numerically. */
        function paddingForSong(song) {
            var sb = (songData.songbooks || []).find(function (x) { return x.id === song.songbook; });
            return (sb && window.iHymnsProPresenter) ? window.iHymnsProPresenter.paddingFor(sb) : 0;
        }

        async function exportCurrentSong() {
            if (!currentSongId) { notify('Open a song first, then export it.', 'warning'); return; }
            var song = (songData.songs || []).find(function (s) { return s.id === currentSongId; });
            if (!song) { notify('Could not find the open song.', 'danger'); return; }
            /* Ensure the FULL record (components + credits), not the slim stub. */
            if (!song._full && typeof _loadSongsFull === 'function') {
                await _loadSongsFull([currentSongId]);
                song = (songData.songs || []).find(function (s) { return s.id === currentSongId; }) || song;
            }
            var result = await window.iHymnsProPresenter.exportSong(song, { padNumber: paddingForSong(song) });
            notify('Exported ' + result.filename, 'success');
        }

        async function exportCurrentSongbook() {
            var abbr = (typeof getSelectedSongbookFilter === 'function') ? getSelectedSongbookFilter() : '';
            if (!abbr) { notify('Filter the song list to one songbook first (sidebar dropdown), then export it.', 'warning'); return; }
            notify('Building ProPresenter bundle for ' + abbr + '…', 'info');
            var resp = await fetch(EDITOR_API_URL + '?action=songbook_export&abbr=' + encodeURIComponent(abbr), { credentials: 'same-origin' });
            if (!resp.ok) { notify('Failed to load songbook ' + abbr + ' (HTTP ' + resp.status + ').', 'danger'); return; }
            var payload = await resp.json();
            var songs = payload.songs || [];
            if (!songs.length) { notify('Songbook ' + abbr + ' has no songs to export.', 'warning'); return; }
            var sb = payload.songbook || {};
            var result = await window.iHymnsProPresenter.exportAllAsBundle(songs, {
                songbookAbbrev: abbr,
                songbookName: sb.name || sb.Name || abbr
            });
            notify('Exported ' + result.count + ' song' + (result.count === 1 ? '' : 's') + ' → ' + result.filename, 'success');
        }

        /* Load the protobuf descriptor up-front; enable the dropdown only
           once the schema parses (so a broken bundle disables export rather
           than failing mid-click). */
        function eagerInit() {
            if (!window.iHymnsProPresenter || !window.iHymnsProPresenter.init) return;
            window.iHymnsProPresenter.init().then(function () {
                var btn = document.getElementById('btn-pp-export');
                if (btn) btn.disabled = false;
            }).catch(function (err) {
                console.warn('[ProPresenter] schema init failed; export disabled:', err);
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            var s = document.getElementById('pp-export-song');
            var b = document.getElementById('pp-export-songbook');
            if (s) s.addEventListener('click', function (e) {
                e.preventDefault();
                exportCurrentSong().catch(function (err) { notify('Export failed: ' + ((err && err.message) || err), 'danger'); });
            });
            if (b) b.addEventListener('click', function (e) {
                e.preventDefault();
                exportCurrentSongbook().catch(function (err) { notify('Export failed: ' + ((err && err.message) || err), 'danger'); });
            });
            eagerInit();
        });
    })();
    </script>

    <!-- File-format exporters (#1054 OpenSong / #1055 VideoPsalm / …).
         format-export.js exposes window.iHymnsFormatExport and reuses
         propresenter-export.js's ZIP writer (loaded above). -->
    <script src="format-export.js"></script>
    <script>
    /* Wire the file-format export dropdowns to window.iHymnsFormatExport via a
       single generic binder (one bindFormat() line per format). Reuses
       editor.js globals (currentSongId / songData / EDITOR_API_URL /
       getSelectedSongbookFilter / _loadSongsFull) + ?action=songbook_export.
       No editor.js changes; no protobuf needed (unlike the .pro export). */
    (function () {
        'use strict';
        function notify(msg, type) {
            if (typeof showToast === 'function') { showToast(msg, type === 'danger' ? 'error' : type); }
            else { console.log('[export] ' + msg); }
        }
        function currentFullSong() {
            if (!currentSongId) { return null; }
            return (songData.songs || []).find(function (s) { return s.id === currentSongId; }) || null;
        }
        /* #1065 — max lyric lines per slide for presentation exports. The
           toolbar input is BOTH the per-export value and (persisted to
           localStorage on change) the user's default. 0 = keep verses whole. */
        var LINES_PER_SLIDE_KEY = 'ihymns_export_lines_per_slide';
        function linesPerSlide() {
            var el = document.getElementById('export-lines-per-slide');
            if (el) {
                var v = parseInt(el.value, 10);
                return (!isNaN(v) && v > 0) ? v : 0;
            }
            var stored = parseInt(window.localStorage.getItem(LINES_PER_SLIDE_KEY), 10);
            return (!isNaN(stored) && stored > 0) ? stored : 0;
        }
        function exportOptions(extra) {
            var o = extra || {};
            o.maxLinesPerSlide = linesPerSlide();
            return o;
        }
        async function exportSong(formatKey) {
            var song = currentFullSong();
            if (!song) { notify('Open a song first, then export it.', 'warning'); return; }
            if (!song._full && typeof _loadSongsFull === 'function') {
                await _loadSongsFull([currentSongId]);
                song = currentFullSong() || song;
            }
            var r = window.iHymnsFormatExport[formatKey].exportSong(song, exportOptions());
            notify('Exported ' + r.filename, 'success');
        }
        async function exportSongbook(formatKey, label) {
            var abbr = (typeof getSelectedSongbookFilter === 'function') ? getSelectedSongbookFilter() : '';
            if (!abbr) { notify('Filter the song list to one songbook first, then export it.', 'warning'); return; }
            notify('Building ' + label + ' export for ' + abbr + '…', 'info');
            var resp = await fetch(EDITOR_API_URL + '?action=songbook_export&abbr=' + encodeURIComponent(abbr), { credentials: 'same-origin' });
            if (!resp.ok) { notify('Failed to load songbook ' + abbr + ' (HTTP ' + resp.status + ').', 'danger'); return; }
            var payload = await resp.json();
            var songs = payload.songs || [];
            if (!songs.length) { notify('Songbook ' + abbr + ' has no songs to export.', 'warning'); return; }
            var sb = payload.songbook || {};
            var r = window.iHymnsFormatExport[formatKey].exportSongbook(songs, exportOptions({
                songbookAbbr: abbr,
                songbookName: sb.name || sb.Name || abbr
            }));
            notify('Exported ' + r.count + ' song' + (r.count === 1 ? '' : 's') + ' → ' + r.filename, 'success');
        }
        function bindFormat(formatKey, label, btnId, songItemId, bookItemId) {
            var s = document.getElementById(songItemId);
            var b = document.getElementById(bookItemId);
            if (s) s.addEventListener('click', function (e) {
                e.preventDefault();
                exportSong(formatKey).catch(function (err) { notify('Export failed: ' + ((err && err.message) || err), 'danger'); });
            });
            if (b) b.addEventListener('click', function (e) {
                e.preventDefault();
                exportSongbook(formatKey, label).catch(function (err) { notify('Export failed: ' + ((err && err.message) || err), 'danger'); });
            });
            var btn = document.getElementById(btnId);
            if (btn && window.iHymnsFormatExport && window.iHymnsFormatExport[formatKey]) { btn.disabled = false; }
        }
        document.addEventListener('DOMContentLoaded', function () {
            /* #1065 — hydrate the lines-per-slide input from the saved default
               and persist any change back as the new default. */
            var lps = document.getElementById('export-lines-per-slide');
            if (lps) {
                var saved = parseInt(window.localStorage.getItem(LINES_PER_SLIDE_KEY), 10);
                if (!isNaN(saved) && saved >= 0) { lps.value = String(saved); }
                lps.addEventListener('change', function () {
                    var v = parseInt(lps.value, 10);
                    if (isNaN(v) || v < 0) { v = 0; lps.value = '0'; }
                    if (v > 20) { v = 20; lps.value = '20'; }
                    try { window.localStorage.setItem(LINES_PER_SLIDE_KEY, String(v)); } catch (_e) {}
                });
            }
            bindFormat('openSong',   'OpenSong',   'btn-os-export', 'os-export-song', 'os-export-songbook');
            bindFormat('videoPsalm', 'VideoPsalm', 'btn-vp-export', 'vp-export-song', 'vp-export-songbook');
            bindFormat('freeShow',   'FreeShow',   'btn-fs-export', 'fs-export-song', 'fs-export-songbook');
            bindFormat('openLyrics',    'OpenLP',       'btn-ol-export', 'ol-export-song', 'ol-export-songbook');
            bindFormat('proclaim',      'Proclaim',     'btn-pc-export', 'pc-export-song', 'pc-export-songbook');
            bindFormat('proPresenter6', 'ProPresenter 6', 'btn-p6-export', 'p6-export-song', 'p6-export-songbook');

            /* EasyWorship export (#1059) — server-side endpoint, so it can't go
               through bindFormat()/format-export.js. The dropdown items trigger
               a download of the generated Songs.db. BETA — see the button title.
               Honours the same lines-per-slide value. */
            function triggerEwExport(query) {
                var url = EDITOR_API_URL + '?action=easyworship_export&' + query
                        + '&maxLinesPerSlide=' + encodeURIComponent(linesPerSlide());
                var a = document.createElement('a');
                a.href = url;
                a.rel = 'noopener';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
            var ewBtn  = document.getElementById('btn-ew-export');
            var ewSong = document.getElementById('ew-export-song');
            var ewBook = document.getElementById('ew-export-songbook');
            if (ewBtn) { ewBtn.disabled = false; }
            if (ewSong) ewSong.addEventListener('click', function (e) {
                e.preventDefault();
                if (!currentSongId) { notify('Open a song first, then export it.', 'warning'); return; }
                notify('Building EasyWorship Songs.db (beta — verify it opens in EasyWorship)…', 'info');
                triggerEwExport('id=' + encodeURIComponent(currentSongId));
            });
            if (ewBook) ewBook.addEventListener('click', function (e) {
                e.preventDefault();
                var abbr = (typeof getSelectedSongbookFilter === 'function') ? getSelectedSongbookFilter() : '';
                if (!abbr) { notify('Filter the song list to one songbook first, then export it.', 'warning'); return; }
                notify('Building EasyWorship Songs.db for ' + abbr + ' (beta — verify it opens in EasyWorship)…', 'info');
                triggerEwExport('abbr=' + encodeURIComponent(abbr));
            });
        });
    })();
    </script>
    <!-- Wire the Composition origin field to the place-search module
         after editor.js boots. The hidden id input fires a synthetic
         `change` event when set, which the bindMetadataListeners
         loop already listens for — so we just need to call attach()
         once. -->
    <script>
        (function () {
            function wirePlaceSearch() {
                if (!window.iHymnsPlaceSearch) return;
                const visible = document.getElementById('edit-origin-city');
                const hidden  = document.getElementById('edit-origin-city-id');
                if (visible && hidden) {
                    window.iHymnsPlaceSearch.attach(visible, { hiddenIdInput: hidden });
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', wirePlaceSearch);
            } else {
                wirePlaceSearch();
            }
        })();
    </script>
    <!-- #1180 — drag-to-resize the song-list sidebar on large displays. The
         chosen width persists to localStorage; clamped to [240px, 60vw]; only
         active above the 768px stacked-layout breakpoint. -->
    <script>
    (function () {
        var KEY = 'ihymns_editor_sidebar_w';
        var sidebar = document.querySelector('.editor-sidebar');
        var handle  = document.getElementById('sidebar-resize-handle');
        if (!sidebar || !handle) { return; }
        function isWide() { return window.matchMedia('(min-width: 769px)').matches; }
        function clamp(w) { return Math.max(240, Math.min(w, Math.round(window.innerWidth * 0.6))); }
        /* Restore a saved width (large displays only). */
        try {
            var saved = parseInt(window.localStorage.getItem(KEY), 10);
            if (saved && isWide() && saved >= 240 && saved <= window.innerWidth * 0.6) {
                sidebar.style.width = saved + 'px';
                sidebar.style.maxWidth = 'none';
            }
        } catch (_e) { /* private mode — skip restore */ }
        var dragging = false;
        handle.addEventListener('mousedown', function (e) {
            if (!isWide()) { return; }
            dragging = true;
            handle.classList.add('dragging');
            document.body.style.userSelect = 'none';
            document.body.style.cursor = 'col-resize';
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) { return; }
            var left = sidebar.getBoundingClientRect().left;
            var w = clamp(e.clientX - left);
            sidebar.style.width = w + 'px';
            sidebar.style.maxWidth = 'none';
        });
        document.addEventListener('mouseup', function () {
            if (!dragging) { return; }
            dragging = false;
            handle.classList.remove('dragging');
            document.body.style.userSelect = '';
            document.body.style.cursor = '';
            try { window.localStorage.setItem(KEY, String(parseInt(sidebar.style.width, 10) || '')); } catch (_e) {}
        });
        /* If the viewport shrinks to the stacked layout, drop the inline width
           so the ≤768px rules (full-width sidebar) take over cleanly. */
        window.addEventListener('resize', function () {
            if (!isWide()) { sidebar.style.width = ''; sidebar.style.maxWidth = ''; }
        });
    })();
    </script>

    <!-- #858 — pre-load tblLanguages once per page so the per-component
         language override picker (rendered by editor.js renderComponents)
         can populate without each card re-fetching. Empty array fallback
         on a 4xx/5xx so the dropdown still shows "Same as song" only. -->
    <script>
    (function () {
        window.iHymnsLanguageOptions = window.iHymnsLanguageOptions || [];
        fetch('/api?action=languages', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !Array.isArray(j.languages)) return;
                window.iHymnsLanguageOptions = j.languages
                    .filter(function (l) { return l && l.code && l.name; })
                    .map(function (l) { return { code: l.code, name: l.name }; });
                /* #1581 — an 'iHymns:languages-loaded' notify event used to
                   fire here. ELI5: it told the page "the language list just
                   arrived" but nobody was listening, so it did nothing.
                   Detail: grep across the whole tree confirmed zero
                   addEventListener sites for it — editor.js reads
                   window.iHymnsLanguageOptions directly on the next
                   selectSong() instead, so no signal was ever needed.
                   Removed rather than migrated into the EVT_* registry,
                   since a dead dispatch shouldn't be kept alive by giving
                   it a name. */
            })
            .catch(function () { /* registry unavailable — fallback to identity */ });
    })();
    </script>

    <!-- IETF BCP 47 picker boot (#687). The shared module is the same one
         that powers /manage/songbooks. We expose the booted instance on
         window.editSongIetfPicker so editor.js (a classic script that
         can't `import`) can call setTag()/getTag() on it. The module is
         auto-deferred, so it executes after editor.js but before
         DOMContentLoaded — which means by the time editor.js's init()
         fires (on DOMContentLoaded) and starts loading songs into the
         form, the picker is already booted. -->
    <?php
        $_ietfModulePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'ietf-language-picker.js';
        $_ietfModuleVersion = is_file($_ietfModulePath) ? (string)filemtime($_ietfModulePath) : '1';
    ?>
    <script type="module">
        import { bootIetfLanguagePicker }
            from '/js/modules/ietf-language-picker.js?v=<?= htmlspecialchars($_ietfModuleVersion, ENT_QUOTES) ?>';
        const root = document.querySelector('.ietf-picker[data-ietf-picker-id="edit-song"]');
        if (root) {
            window.editSongIetfPicker = bootIetfLanguagePicker(root);
        }
        /* #1345 — also expose the boot function itself so editor.js (classic
           script) can instantiate per-line / per-translation pickers on demand
           (buildInlineIetfPicker). The structured picker replaces the old
           free-text per-line language textarea. */
        window.bootIetfLanguagePicker = bootIetfLanguagePicker;
    </script>

    <!-- Song Media editor boot (#853). Subscribes to the
         iHymns:song-loaded CustomEvent dispatched by editor.js.
         Cache-bust on filemtime() so a deploy invalidates the
         browser cache without a manual version bump. -->
    <?php
        $_mediaModulePath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'song-media-editor.js';
        $_mediaModuleVersion = is_file($_mediaModulePath) ? (string)filemtime($_mediaModulePath) : '1';
    ?>
    <script type="module">
        import { bootSongMediaEditor }
            from '/js/modules/song-media-editor.js?v=<?= htmlspecialchars($_mediaModuleVersion, ENT_QUOTES) ?>';
        const mediaRoot = document.getElementById('song-media-editor-root');
        if (mediaRoot) {
            window.songMediaEditor = bootSongMediaEditor(mediaRoot);
        }
    </script>

    <!-- ============================================================
         Find Missing Numbers modal (#285)
         Shows the gaps in a songbook's numbering plus an at-a-glance
         count of present / expected / missing songs. A dedicated
         "Log a request" link on each missing number jumps straight to
         the public request form with the number prefilled.
         ============================================================ -->
    <div class="modal fade" id="missing-numbers-modal" tabindex="-1"
         aria-labelledby="missing-numbers-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="missing-numbers-modal-label">
                        <i class="bi bi-binoculars me-2" aria-hidden="true"></i>
                        Missing Song Numbers
                        <span class="text-muted small ms-2" id="missing-numbers-scope"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="missing-numbers-loading" class="text-center text-muted py-4">
                        <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                        Scanning the songbook&hellip;
                    </div>
                    <div id="missing-numbers-error" class="alert alert-danger d-none" role="alert"></div>
                    <div id="missing-numbers-summary" class="row g-3 mb-3 d-none">
                        <div class="col-sm-4">
                            <div class="card-admin text-center">
                                <div class="text-muted text-uppercase small">Present</div>
                                <div class="h5 mb-0" id="missing-numbers-present">0</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="card-admin text-center">
                                <div class="text-muted text-uppercase small">Expected</div>
                                <div class="h5 mb-0" id="missing-numbers-expected">0</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="card-admin text-center">
                                <div class="text-muted text-uppercase small">Missing</div>
                                <div class="h5 mb-0 text-warning" id="missing-numbers-count">0</div>
                            </div>
                        </div>
                    </div>
                    <div id="missing-numbers-list" class="d-none"></div>
                    <div id="missing-numbers-empty" class="alert alert-success d-none" role="status">
                        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                        No gaps in this songbook — every number from 1 to the maximum is present.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    /* Wiring for #285 — Find Missing Numbers. Kept inline here to avoid
       a second editor.js round-trip; the endpoint + modal are
       self-contained. */
    (function () {
        const btn      = document.getElementById('find-missing-numbers-btn');
        const filterEl = document.getElementById('songbook-filter');
        const modalEl  = document.getElementById('missing-numbers-modal');
        if (!btn || !filterEl || !modalEl) return;

        /* Toggle the button's disabled state as the songbook filter
           changes — "All Songbooks" has no single numbering to gap. */
        const syncEnabled = () => { btn.disabled = !filterEl.value; };
        filterEl.addEventListener('change', syncEnabled);
        syncEnabled();

        const scopeEl    = document.getElementById('missing-numbers-scope');
        const loadingEl  = document.getElementById('missing-numbers-loading');
        const errorEl    = document.getElementById('missing-numbers-error');
        const summaryEl  = document.getElementById('missing-numbers-summary');
        const listEl     = document.getElementById('missing-numbers-list');
        const emptyEl    = document.getElementById('missing-numbers-empty');
        const presentEl  = document.getElementById('missing-numbers-present');
        const expectedEl = document.getElementById('missing-numbers-expected');
        const countEl    = document.getElementById('missing-numbers-count');

        const resetView = () => {
            loadingEl.classList.remove('d-none');
            errorEl.classList.add('d-none');
            summaryEl.classList.add('d-none');
            listEl.classList.add('d-none');
            emptyEl.classList.add('d-none');
            listEl.innerHTML = '';
        };

        /* Group consecutive missing numbers into ranges so a songbook
           with a big trailing gap doesn't produce a wall of badges. */
        const groupRuns = (nums) => {
            const out = [];
            let run = [];
            for (const n of nums) {
                if (run.length === 0 || n === run[run.length - 1] + 1) run.push(n);
                else { out.push(run); run = [n]; }
            }
            if (run.length) out.push(run);
            return out;
        };

        const authHeader = () => {
            /* Editor page already has a session cookie, but if an admin
               opens the editor from a native shell the bearer token is
               in localStorage. Mirror both in case. */
            const t = localStorage.getItem('ihymns_auth_token');
            return t ? { 'Authorization': 'Bearer ' + t } : {};
        };

        modalEl.addEventListener('shown.bs.modal', async () => {
            const bookId = filterEl.value;
            scopeEl.textContent = bookId ? `— ${bookId}` : '';
            resetView();
            if (!bookId) {
                errorEl.textContent = 'Select a specific songbook first.';
                errorEl.classList.remove('d-none');
                loadingEl.classList.add('d-none');
                return;
            }
            try {
                const res = await fetch(`/api?action=missing_songs&songbook=${encodeURIComponent(bookId)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', ...authHeader() },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Could not load missing numbers.');

                /* API shape (#285): { missing: int[], maxNumber, totalExisting, songbook } */
                const missing  = Array.isArray(data.missing)      ? data.missing         : [];
                const present  = Number.isFinite(data.totalExisting) ? data.totalExisting : 0;
                const expected = Number.isFinite(data.maxNumber)     ? data.maxNumber     : (present + missing.length);

                presentEl.textContent  = present.toLocaleString();
                expectedEl.textContent = expected.toLocaleString();
                countEl.textContent    = missing.length.toLocaleString();
                summaryEl.classList.remove('d-none');

                if (missing.length === 0) {
                    emptyEl.classList.remove('d-none');
                } else {
                    const runs = groupRuns(missing);
                    const html = runs.map((run) => {
                        const label = run.length === 1 ? `#${run[0]}` : `#${run[0]}–${run[run.length - 1]}`;
                        const count = run.length === 1 ? '1 song' : `${run.length} songs`;
                        return `
                            <div class="d-flex align-items-center gap-2 border-bottom py-2 missing-range">
                                <span class="badge bg-warning text-dark" style="min-width:7rem;">${label}</span>
                                <span class="text-muted small flex-grow-1">${count} missing</span>
                                <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
                                   href="/request?songbook=${encodeURIComponent(bookId)}&number=${run[0]}">
                                    <i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Log request
                                </a>
                            </div>`;
                    }).join('');
                    listEl.innerHTML = html;
                    listEl.classList.remove('d-none');
                }
            } catch (err) {
                errorEl.textContent = err.message || 'Could not load missing numbers.';
                errorEl.classList.remove('d-none');
            } finally {
                loadingEl.classList.add('d-none');
            }
        });
    })();
    </script>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
