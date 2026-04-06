/**
 * iHymns — Sheet Music Viewer Module (#91)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides an inline PDF viewer for song sheet music using PDF.js.
 * Renders sheet music in a Bootstrap modal with page navigation,
 * zoom controls, and a download button.
 *
 * ARCHITECTURE:
 *   1. PDF.js is dynamically loaded from CDN (with local fallback)
 *      on first use of the sheet music button.
 *   2. The PDF file is fetched and rendered onto a <canvas> element
 *      inside a modal dialog.
 *   3. Users can navigate pages, zoom in/out, and download the file.
 *
 * FILE PATH:
 *   PDF files are expected at: /data/music/{SONG_ID}.pdf
 *   e.g., /data/music/CP-0001.pdf
 */

export class SheetMusic {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {object|null} PDF.js library reference (pdfjsLib) */
        this.pdfjsLib = null;

        /** @type {boolean} Whether PDF.js has been loaded */
        this.pdfjsLoaded = false;

        /** @type {boolean} Whether loading is in progress */
        this.pdfjsLoading = false;

        /** @type {object|null} Currently loaded PDF document proxy */
        this.pdfDoc = null;

        /** @type {number} Current page number (1-based) */
        this.currentPage = 1;

        /** @type {number} Current zoom scale (1.0 = 100%) */
        this.scale = 1.5;

        /** @type {string|null} Currently loaded song ID */
        this.currentSongId = null;

        /** @type {boolean} Whether a sheet music load is in progress (#115) */
        this.isLoading = false;

        /** @type {HTMLElement|null} Modal element reference */
        this.modalEl = null;

        /** @type {object|null} Bootstrap Modal instance */
        this.bsModal = null;
    }

    /** Initialise — nothing needed on startup; PDF.js loaded on demand */
    init() {}

    /**
     * Handle sheet music button click on a song page.
     * Opens the viewer modal and loads the PDF.
     *
     * @param {string} songId Song ID (e.g., 'CP-0001')
     */
    async handleSheetMusicClick(songId) {
        /* Guard against rapid clicks opening multiple modals (#115) */
        if (this.isLoading) return;
        this.isLoading = true;

        this.currentSongId = songId;
        this.currentPage = 1;

        /* Create and show the modal */
        this.createModal(songId);
        this.bsModal = new bootstrap.Modal(this.modalEl);
        this.bsModal.show();

        /* Load PDF.js if not already loaded */
        if (!this.pdfjsLoaded) {
            this.updateStatus('Loading PDF viewer...');
            const loaded = await this.loadPdfJs();
            if (!loaded) {
                this.updateStatus('PDF viewer unavailable.');
                this.showDownloadFallback(songId);
                this.isLoading = false;
                return;
            }
        }

        /* Fetch and render the PDF */
        this.updateStatus('Loading sheet music...');
        try {
            const url = this.app.config.musicBasePath + songId + '.pdf';
            this.pdfDoc = await this.pdfjsLib.getDocument(url).promise;
            this.updatePageInfo();
            await this.renderPage(this.currentPage);
            this.enableControls(true);
            this.updateStatus('');
        } catch (err) {
            console.error('[SheetMusic] Error loading PDF:', err);
            this.updateStatus('Sheet music not available for this song.');
            this.showDownloadFallback(songId);
        } finally {
            this.isLoading = false;
        }
    }

    /**
     * Create the sheet music viewer modal.
     * If the modal already exists in the DOM, it is reused.
     *
     * @param {string} songId Song ID for the download link
     */
    createModal(songId) {
        /* Remove existing modal if any */
        document.getElementById('sheet-music-modal')?.remove();

        const modal = document.createElement('div');
        modal.id = 'sheet-music-modal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-labelledby', 'sheet-music-modal-label');
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="sheet-music-modal-label">
                            <i class="fa-solid fa-file-pdf me-2" aria-hidden="true"></i>
                            Sheet Music
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center p-2">
                        <!-- Status message -->
                        <div id="sheet-music-status" class="text-muted py-3">Loading...</div>
                        <!-- PDF canvas container -->
                        <div id="sheet-music-canvas-container" class="sheet-music-canvas-container">
                            <canvas id="sheet-music-canvas" class="sheet-music-canvas"></canvas>
                        </div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <!-- Zoom controls -->
                        <div class="btn-group btn-group-sm" role="group" aria-label="Zoom controls">
                            <button type="button" class="btn btn-outline-secondary" id="sm-zoom-out"
                                    disabled aria-label="Zoom out" title="Zoom out">
                                <i class="fa-solid fa-magnifying-glass-minus" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="sm-zoom-reset"
                                    disabled aria-label="Reset zoom" title="Reset zoom">
                                <span id="sm-zoom-level">150%</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="sm-zoom-in"
                                    disabled aria-label="Zoom in" title="Zoom in">
                                <i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i>
                            </button>
                        </div>
                        <!-- Page navigation -->
                        <div class="btn-group btn-group-sm" role="group" aria-label="Page navigation">
                            <button type="button" class="btn btn-outline-secondary" id="sm-prev-page"
                                    disabled aria-label="Previous page">
                                <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                            </button>
                            <span class="btn btn-outline-secondary disabled" id="sm-page-info">1 / 1</span>
                            <button type="button" class="btn btn-outline-secondary" id="sm-next-page"
                                    disabled aria-label="Next page">
                                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                            </button>
                        </div>
                        <!-- Download button -->
                        <a href="${this.app.config.musicBasePath}${songId}.pdf"
                           class="btn btn-sm btn-primary" download
                           aria-label="Download sheet music PDF">
                            <i class="fa-solid fa-download me-1" aria-hidden="true"></i>
                            Download
                        </a>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(modal);
        this.modalEl = modal;

        /* Bind control events */
        modal.querySelector('#sm-zoom-in')?.addEventListener('click', () => this.zoom(0.25));
        modal.querySelector('#sm-zoom-out')?.addEventListener('click', () => this.zoom(-0.25));
        modal.querySelector('#sm-zoom-reset')?.addEventListener('click', () => { this.scale = 1.5; this.renderPage(this.currentPage); this.updateZoomDisplay(); });
        modal.querySelector('#sm-prev-page')?.addEventListener('click', () => this.changePage(-1));
        modal.querySelector('#sm-next-page')?.addEventListener('click', () => this.changePage(1));

        /* Clean up on modal close */
        modal.addEventListener('hidden.bs.modal', () => {
            this.pdfDoc = null;
            this.currentSongId = null;
            this.isLoading = false;
            modal.remove();
        });
    }

    /**
     * Dynamically load PDF.js from CDN with local fallback.
     *
     * @returns {Promise<boolean>} True if loaded successfully
     */
    async loadPdfJs() {
        if (this.pdfjsLoaded) return true;
        if (this.pdfjsLoading) return false;
        this.pdfjsLoading = true;

        try {
            /* Try CDN first */
            const pdfjsModule = await import(this.app.config.pdfjsCdn);
            this.pdfjsLib = pdfjsModule;
            this.pdfjsLib.GlobalWorkerOptions.workerSrc = this.app.config.pdfjsWorkerCdn;
            this.pdfjsLoaded = true;
            console.log('[SheetMusic] PDF.js loaded from CDN');
            return true;
        } catch {
            console.warn('[SheetMusic] PDF.js CDN failed, trying local fallback');
        }

        try {
            /* Local fallback */
            const pdfjsLocal = await import('/' + this.app.config.pdfjsLocal);
            this.pdfjsLib = pdfjsLocal;
            this.pdfjsLib.GlobalWorkerOptions.workerSrc = '/' + this.app.config.pdfjsWorkerLocal;
            this.pdfjsLoaded = true;
            console.log('[SheetMusic] PDF.js loaded from local');
            return true;
        } catch {
            console.error('[SheetMusic] PDF.js failed to load from both CDN and local');
        }

        this.pdfjsLoading = false;
        return false;
    }

    /**
     * Render a specific page of the PDF onto the canvas.
     *
     * @param {number} pageNum Page number (1-based)
     */
    async renderPage(pageNum) {
        if (!this.pdfDoc) return;

        try {
            const page = await this.pdfDoc.getPage(pageNum);
            const viewport = page.getViewport({ scale: this.scale });
            const canvas = document.getElementById('sheet-music-canvas');
            if (!canvas) return;

            const context = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;

            /* Hide status, show canvas */
            const statusEl = document.getElementById('sheet-music-status');
            if (statusEl) statusEl.style.display = 'none';
            canvas.style.display = 'block';

            await page.render({ canvasContext: context, viewport }).promise;

            this.currentPage = pageNum;
            this.updatePageInfo();
            this.updateZoomDisplay();
        } catch (err) {
            console.error('[SheetMusic] Render error:', err);
        }
    }

    /* =====================================================================
     * NAVIGATION & ZOOM
     * ===================================================================== */

    /**
     * Change page by a delta (e.g., +1 for next, -1 for previous).
     *
     * @param {number} delta Number of pages to advance (can be negative)
     */
    changePage(delta) {
        if (!this.pdfDoc) return;
        const newPage = this.currentPage + delta;
        if (newPage >= 1 && newPage <= this.pdfDoc.numPages) {
            this.renderPage(newPage);
        }
    }

    /**
     * Adjust zoom scale by a delta.
     *
     * @param {number} delta Zoom change (e.g., 0.25 = +25%)
     */
    zoom(delta) {
        const newScale = Math.max(0.5, Math.min(3.0, this.scale + delta));
        if (newScale !== this.scale) {
            this.scale = newScale;
            this.renderPage(this.currentPage);
        }
    }

    /* =====================================================================
     * UI HELPERS
     * ===================================================================== */

    /** Update the page info display and prev/next button states */
    updatePageInfo() {
        if (!this.pdfDoc) return;
        const total = this.pdfDoc.numPages;
        const infoEl = document.getElementById('sm-page-info');
        const prevBtn = document.getElementById('sm-prev-page');
        const nextBtn = document.getElementById('sm-next-page');

        if (infoEl) infoEl.textContent = `${this.currentPage} / ${total}`;
        if (prevBtn) prevBtn.disabled = this.currentPage <= 1;
        if (nextBtn) nextBtn.disabled = this.currentPage >= total;
    }

    /** Update the zoom level display */
    updateZoomDisplay() {
        const el = document.getElementById('sm-zoom-level');
        if (el) el.textContent = Math.round(this.scale * 100) + '%';
    }

    /** Enable or disable all viewer controls */
    enableControls(enabled) {
        ['sm-zoom-in', 'sm-zoom-out', 'sm-zoom-reset', 'sm-prev-page', 'sm-next-page'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = !enabled;
        });
        this.updatePageInfo();
    }

    /** Update the status message in the modal */
    updateStatus(text) {
        const el = document.getElementById('sheet-music-status');
        if (el) {
            el.textContent = text;
            el.style.display = text ? 'block' : 'none';
        }
    }

    /** Show a download fallback when the viewer can't render */
    showDownloadFallback(songId) {
        const statusEl = document.getElementById('sheet-music-status');
        if (statusEl) {
            const url = this.app.config.musicBasePath + songId + '.pdf';
            statusEl.innerHTML = `
                <p class="mb-2">Unable to display sheet music in the viewer.</p>
                <a href="${url}" download class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-download me-1" aria-hidden="true"></i>
                    Download PDF
                </a>`;
        }
    }
}
