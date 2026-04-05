/**
 * iHymns — Sheet Music PDF Viewer Module
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Displays PDF sheet music for songs that have associated music files.
 * Uses Mozilla's PDF.js library to render PDF pages into an HTML5 canvas
 * element inside a Bootstrap modal. When PDF.js is unavailable or rendering
 * fails, falls back to a direct download link.
 *
 * PDF FILE PATH CONVENTION:
 * Sheet music files are expected at: media/<songbook>/<songId>_music.pdf
 * where <songbook> is the songbook code (e.g., 'CH') and <songId> is
 * the song identifier (e.g., 'CH-0003').
 *
 * DEPENDENCIES:
 * - PDF.js (pdfjsLib) loaded via CDN in index.php
 * - Bootstrap 5.3 Modal component for the viewer overlay
 *
 * ARCHITECTURE:
 * - initSheetMusic()        : pre-loads the PDF.js web worker
 * - renderSheetMusic(song, containerEl) : renders the "View Sheet Music" button
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

/**
 * Import DOM helpers from the shared helpers module.
 * - createElement : creates a DOM element with attributes and children
 */
import { createElement } from '../utils/helpers.js';

/* =========================================================================
 * CONSTANTS
 * ========================================================================= */

/**
 * CDN URL for the PDF.js worker script.
 * The worker runs PDF parsing off the main thread for better performance.
 * Must match the same version as the pdf.min.mjs loaded in index.php.
 */
const PDFJS_WORKER_URL = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.9.155/pdf.worker.min.mjs';

/**
 * Unique ID for the sheet music modal element.
 * Used to find or create the modal in the DOM.
 */
const MODAL_ID = 'sheet-music-modal';

/* =========================================================================
 * MODULE-LEVEL STATE
 * ========================================================================= */

/**
 * Flag indicating whether PDF.js worker has been configured.
 * Set to true after initSheetMusic() runs to avoid duplicate setup.
 */
let _workerInitialised = false;

/**
 * Reference to the Bootstrap Modal instance for the sheet music viewer.
 * Created lazily on first use.
 */
let _modalInstance = null;

/* =========================================================================
 * PUBLIC API
 * ========================================================================= */

/**
 * initSheetMusic()
 *
 * Pre-loads and configures the PDF.js web worker. The worker handles
 * the heavy lifting of PDF parsing off the main thread, preventing
 * UI jank during rendering.
 *
 * This function should be called once during application bootstrap.
 * It is safe to call multiple times (subsequent calls are no-ops).
 */
export function initSheetMusic() {
    /* ---------------------------------------------------------------
     * GUARD: only configure the worker once, even if called multiple times.
     * --------------------------------------------------------------- */
    if (_workerInitialised) {
        return;
    }

    /* ---------------------------------------------------------------
     * CHECK: ensure PDF.js (pdfjsLib) is available on the global scope.
     * It is loaded via a <script> tag in index.php before app.js.
     * --------------------------------------------------------------- */
    if (typeof window.pdfjsLib !== 'undefined') {
        /* Point PDF.js to the matching worker script on the CDN */
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER_URL;

        /* Mark as initialised so we do not repeat this setup */
        _workerInitialised = true;
    } else {
        /* ---------------------------------------------------------------
         * PDF.js is not loaded yet. This can happen if the CDN script
         * has not finished downloading. We log a warning; the render
         * function will check again and fall back gracefully.
         * --------------------------------------------------------------- */
        console.warn('PDF.js (pdfjsLib) is not available. Sheet music rendering will use download fallback.');
    }
}

/**
 * renderSheetMusic(song, containerEl)
 *
 * Renders a "View Sheet Music" button for songs that have sheet music.
 * When clicked, the button opens a Bootstrap modal containing the PDF
 * rendered via PDF.js onto a <canvas>. If PDF.js is unavailable, a
 * direct download link is shown instead.
 *
 * If song.hasSheetMusic is falsy, this function returns immediately
 * without modifying the DOM.
 *
 * @param {object}  song        - The song data object (must include id, songbook, hasSheetMusic)
 * @param {Element} containerEl - The DOM element to render the button into
 */
export function renderSheetMusic(song, containerEl) {
    /* ---------------------------------------------------------------
     * GUARD: exit early if the song has no sheet music.
     * --------------------------------------------------------------- */
    if (!song.hasSheetMusic) {
        return;
    }

    /* ---------------------------------------------------------------
     * DERIVE THE PDF FILE URL
     * Convention: media/<songbook>/<songId>_music.pdf
     * Example:    media/CH/CH-0003_music.pdf
     * --------------------------------------------------------------- */
    const pdfUrl = `media/${encodeURIComponent(song.songbook)}/${encodeURIComponent(song.id)}_music.pdf`;

    /* ---------------------------------------------------------------
     * OUTER WRAPPER
     * Create a container div for the sheet music section with Bootstrap
     * spacing utilities and a custom class for targeted styling.
     * --------------------------------------------------------------- */
    const wrapper = createElement('div', {
        className: 'sheet-music-section mb-3',
        role: 'region',
        'aria-label': 'Sheet music'
    });

    /* ---------------------------------------------------------------
     * SECTION HEADING
     * A small label so the user knows what this section is for.
     * --------------------------------------------------------------- */
    const heading = createElement('div', {
        className: 'sheet-music-label text-muted small mb-1'
    }, 'Sheet Music');

    /* Append the heading to the wrapper */
    wrapper.appendChild(heading);

    /* ---------------------------------------------------------------
     * BRANCH: PDF.js available vs. download-only fallback
     * --------------------------------------------------------------- */
    if (typeof window.pdfjsLib !== 'undefined') {
        /* ---------------------------------------------------------------
         * PDF.JS PATH — "View Sheet Music" button that opens a modal
         * --------------------------------------------------------------- */

        /* Ensure the worker is initialised (in case initSheetMusic was not called) */
        initSheetMusic();

        /* --- Button group for View + Download --- */
        const btnGroup = createElement('div', {
            className: 'btn-group',
            role: 'group',
            'aria-label': 'Sheet music actions'
        });

        /* --- View button: opens the PDF in a modal viewer --- */
        const viewBtn = createElement('button', {
            className: 'btn btn-outline-primary btn-sm',
            'aria-label': 'View sheet music',
            onClick: () => {
                /* Open the modal and load the PDF into it */
                _openSheetMusicModal(pdfUrl, song.title);
            }
        }, '');

        /* Set button content with a Bootstrap icon */
        viewBtn.innerHTML = '<i class="bi bi-file-earmark-music me-1" aria-hidden="true"></i>View Sheet Music';

        /* --- Download button: direct PDF download --- */
        const downloadBtn = createElement('a', {
            className: 'btn btn-outline-info btn-sm',
            href: pdfUrl,
            download: '',
            'aria-label': 'Download sheet music PDF'
        }, '');

        /* Set download button content with a Bootstrap icon */
        downloadBtn.innerHTML = '<i class="bi bi-download me-1" aria-hidden="true"></i>Download PDF';

        /* Assemble the button group */
        btnGroup.appendChild(viewBtn);
        btnGroup.appendChild(downloadBtn);

        /* Append the button group to the wrapper */
        wrapper.appendChild(btnGroup);
    } else {
        /* ---------------------------------------------------------------
         * FALLBACK PATH — PDF.js is not available
         * Provide a download link so the user can still access the file.
         * --------------------------------------------------------------- */
        const link = createElement('a', {
            className: 'btn btn-outline-primary btn-sm',
            href: pdfUrl,
            download: '',
            'aria-label': 'Download sheet music PDF'
        }, '');

        /* Set link content with a download icon */
        link.innerHTML = '<i class="bi bi-download me-1" aria-hidden="true"></i>Download Sheet Music (PDF)';

        /* Append the download link to the wrapper */
        wrapper.appendChild(link);
    }

    /* ---------------------------------------------------------------
     * MOUNT
     * Append the entire sheet music section to the supplied container.
     * --------------------------------------------------------------- */
    containerEl.appendChild(wrapper);
}

/* =========================================================================
 * PRIVATE HELPER FUNCTIONS
 * ========================================================================= */

/**
 * _openSheetMusicModal(pdfUrl, songTitle)
 *
 * Opens (or creates) a Bootstrap modal containing a canvas-based PDF
 * viewer. Loads the PDF from the given URL using PDF.js, renders each
 * page sequentially into canvas elements inside the modal body.
 *
 * If rendering fails for any reason, the modal body is replaced with
 * an error message and a direct download link.
 *
 * @param {string} pdfUrl    - The URL to the PDF file
 * @param {string} songTitle - The song title (used in the modal header)
 */
async function _openSheetMusicModal(pdfUrl, songTitle) {
    /* ---------------------------------------------------------------
     * GET OR CREATE THE MODAL ELEMENT
     * We reuse a single modal across all songs to avoid DOM bloat.
     * --------------------------------------------------------------- */
    let modalEl = document.getElementById(MODAL_ID);

    if (!modalEl) {
        /* ---------------------------------------------------------------
         * CREATE THE MODAL STRUCTURE
         * Uses Bootstrap 5 modal markup with a scrollable body and
         * extra-large size for comfortable sheet music viewing.
         * --------------------------------------------------------------- */
        modalEl = document.createElement('div');
        modalEl.id = MODAL_ID;
        modalEl.className = 'modal fade';
        modalEl.setAttribute('tabindex', '-1');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.innerHTML = `
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="${MODAL_ID}-title">Sheet Music</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Close sheet music viewer"></button>
                    </div>
                    <div class="modal-body text-center" id="${MODAL_ID}-body">
                        <!-- PDF pages will be rendered here -->
                    </div>
                    <div class="modal-footer">
                        <a class="btn btn-outline-primary btn-sm" id="${MODAL_ID}-download"
                           download="" aria-label="Download PDF">
                            <i class="bi bi-download me-1" aria-hidden="true"></i>Download PDF
                        </a>
                        <button type="button" class="btn btn-secondary btn-sm"
                                data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        `;

        /* Append the modal to the document body (Bootstrap requires this) */
        document.body.appendChild(modalEl);
    }

    /* ---------------------------------------------------------------
     * UPDATE MODAL CONTENT FOR THIS SONG
     * Set the title and download link to match the current song/PDF.
     * --------------------------------------------------------------- */
    const titleEl = document.getElementById(`${MODAL_ID}-title`);
    const bodyEl = document.getElementById(`${MODAL_ID}-body`);
    const downloadLink = document.getElementById(`${MODAL_ID}-download`);

    /* Set the modal title to include the song name */
    if (titleEl) {
        titleEl.textContent = `Sheet Music — ${songTitle}`;
    }

    /* Point the download link to the current PDF */
    if (downloadLink) {
        downloadLink.href = pdfUrl;
    }

    /* Clear any previously rendered pages from the modal body */
    if (bodyEl) {
        bodyEl.innerHTML = `
            <div class="spinner-border text-primary my-4" role="status">
                <span class="visually-hidden">Loading sheet music...</span>
            </div>
            <p class="text-muted">Loading sheet music...</p>
        `;
    }

    /* ---------------------------------------------------------------
     * SHOW THE MODAL
     * Use Bootstrap's Modal API to display the modal with animation.
     * --------------------------------------------------------------- */
    if (!_modalInstance) {
        /* Create a new Bootstrap Modal instance (lazy init) */
        /* global bootstrap — Bootstrap JS is loaded before our modules */
        _modalInstance = new bootstrap.Modal(modalEl);
    }

    _modalInstance.show();

    /* ---------------------------------------------------------------
     * LOAD AND RENDER THE PDF
     * Use PDF.js to load the document, then render each page into
     * a <canvas> element inside the modal body.
     * --------------------------------------------------------------- */
    try {
        /* Load the PDF document asynchronously */
        const loadingTask = window.pdfjsLib.getDocument(pdfUrl);
        const pdfDoc = await loadingTask.promise;

        /* Clear the loading spinner from the modal body */
        if (bodyEl) {
            bodyEl.innerHTML = '';
        }

        /* ---------------------------------------------------------------
         * RENDER EACH PAGE
         * Iterate through all pages in the PDF and render each one
         * onto its own <canvas> element. Pages are rendered at 1.5x
         * scale for readability on high-DPI displays.
         * --------------------------------------------------------------- */
        const totalPages = pdfDoc.numPages;

        for (let pageNum = 1; pageNum <= totalPages; pageNum++) {
            /* Retrieve the page object from the PDF document */
            const page = await pdfDoc.getPage(pageNum);

            /* ---------------------------------------------------------------
             * CALCULATE VIEWPORT DIMENSIONS
             * Use a scale factor of 1.5 for a good balance between
             * readability and performance. The viewport defines the
             * canvas dimensions based on the PDF page size.
             * --------------------------------------------------------------- */
            const scale = 1.5;
            const viewport = page.getViewport({ scale });

            /* Create a <canvas> element for this page */
            const canvas = document.createElement('canvas');
            canvas.className = 'sheet-music-page mb-3 shadow-sm border rounded';
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            /* Make the canvas responsive by constraining max-width via CSS */
            canvas.style.maxWidth = '100%';
            canvas.style.height = 'auto';

            /* Get the 2D rendering context for the canvas */
            const ctx = canvas.getContext('2d');

            /* ---------------------------------------------------------------
             * RENDER THE PAGE
             * PDF.js renders the page into the canvas context using the
             * viewport dimensions we calculated above.
             * --------------------------------------------------------------- */
            await page.render({
                canvasContext: ctx,
                viewport: viewport
            }).promise;

            /* Append the rendered canvas to the modal body */
            if (bodyEl) {
                bodyEl.appendChild(canvas);
            }

            /* ---------------------------------------------------------------
             * PAGE NUMBER INDICATOR
             * Show "Page X of Y" below each page for multi-page PDFs.
             * --------------------------------------------------------------- */
            if (totalPages > 1) {
                const pageLabel = createElement('p', {
                    className: 'text-muted small mb-4'
                }, `Page ${pageNum} of ${totalPages}`);

                if (bodyEl) {
                    bodyEl.appendChild(pageLabel);
                }
            }
        }
    } catch (err) {
        /* ---------------------------------------------------------------
         * ERROR HANDLING
         * If the PDF fails to load or render, display a friendly error
         * message inside the modal with a download link fallback.
         * --------------------------------------------------------------- */
        console.error('Failed to render sheet music PDF:', err);

        if (bodyEl) {
            /* Build the error fallback safely without interpolating pdfUrl into innerHTML */
            bodyEl.innerHTML = `
                <div class="alert alert-warning my-3" role="alert">
                    <i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>
                    Unable to display the sheet music. You can download the PDF file instead.
                </div>
            `;
            const fallbackLink = document.createElement('a');
            fallbackLink.className = 'btn btn-outline-primary btn-sm';
            fallbackLink.href = pdfUrl;
            fallbackLink.download = '';
            fallbackLink.setAttribute('aria-label', 'Download sheet music PDF');
            fallbackLink.innerHTML = '<i class="bi bi-download me-1" aria-hidden="true"></i>Download PDF';
            bodyEl.appendChild(fallbackLink);
        }
    }
}
