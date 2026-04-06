/**
 * iHymns — Missing Song Request Module (#107)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides a form for users to report missing songs or request additions.
 * Generates a mailto: link with pre-filled subject and body. Includes
 * client-side rate limiting (one submission per minute).
 */

export class Request {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {number} Timestamp of last submission (rate limiting) */
        this.lastSubmitTime = 0;

        /** @type {number} Minimum interval between submissions (ms) */
        this.rateLimitMs = 60000;

        /** @type {string} Contact email for song requests */
        this.contactEmail = app.config.contactEmail || 'support@ihymns.app';
    }

    /** Initialise — nothing needed on startup */
    init() {}

    /**
     * Show the missing song request modal.
     * @param {string} [prefillTitle] Optional pre-filled song title from search query
     */
    showRequestModal(prefillTitle = '') {
        document.getElementById('request-song-modal')?.remove();

        const modal = document.createElement('div');
        modal.id = 'request-song-modal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-label', 'Request a missing song');

        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fa-solid fa-paper-plane me-2" aria-hidden="true"></i>
                            Request a Song
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            Can't find a song? Let us know and we'll try to add it.
                        </p>
                        <form id="request-song-form" novalidate>
                            <div class="mb-3">
                                <label for="request-title" class="form-label fw-semibold">
                                    Song Title <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="request-title"
                                       value="${this.escapeAttr(prefillTitle)}"
                                       placeholder="e.g. Amazing Grace" required>
                            </div>
                            <div class="mb-3">
                                <label for="request-songbook" class="form-label fw-semibold">
                                    Songbook <small class="text-muted fw-normal">(optional)</small>
                                </label>
                                <input type="text" class="form-control" id="request-songbook"
                                       placeholder="e.g. Church Praise, Junior Praise">
                            </div>
                            <div class="mb-3">
                                <label for="request-notes" class="form-label fw-semibold">
                                    Additional Notes <small class="text-muted fw-normal">(optional)</small>
                                </label>
                                <textarea class="form-control" id="request-notes" rows="3"
                                          placeholder="Any extra details (song number, author, first line...)"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="request-email" class="form-label fw-semibold">
                                    Your Email <small class="text-muted fw-normal">(optional)</small>
                                </label>
                                <input type="email" class="form-control" id="request-email"
                                       placeholder="So we can let you know when it's added">
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="request-submit-btn">
                            <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>
                            Send Request
                        </button>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();

        /* Submit handler */
        modal.querySelector('#request-submit-btn')?.addEventListener('click', () => {
            this.handleSubmit(bsModal);
        });

        /* Clean up on close */
        modal.addEventListener('hidden.bs.modal', () => modal.remove());
    }

    /**
     * Handle form submission — generate mailto: link.
     * @param {object} bsModal Bootstrap modal instance
     */
    handleSubmit(bsModal) {
        const title = document.getElementById('request-title')?.value.trim();
        const songbook = document.getElementById('request-songbook')?.value.trim();
        const notes = document.getElementById('request-notes')?.value.trim();
        const email = document.getElementById('request-email')?.value.trim();

        /* Validate required field */
        if (!title) {
            document.getElementById('request-title')?.classList.add('is-invalid');
            document.getElementById('request-title')?.focus();
            return;
        }

        /* Rate limiting */
        const now = Date.now();
        if (now - this.lastSubmitTime < this.rateLimitMs) {
            const waitSecs = Math.ceil((this.rateLimitMs - (now - this.lastSubmitTime)) / 1000);
            this.app.showToast(`Please wait ${waitSecs}s before submitting another request`, 'warning', 3000);
            return;
        }

        /* Build email body */
        const subject = `Song Request: ${title}`;
        let body = `Song Request from iHymns\n`;
        body += `${'='.repeat(30)}\n\n`;
        body += `Song Title: ${title}\n`;
        if (songbook) body += `Songbook: ${songbook}\n`;
        if (notes) body += `Notes: ${notes}\n`;
        if (email) body += `Contact Email: ${email}\n`;
        body += `\nSubmitted: ${new Date().toLocaleString()}\n`;

        /* Generate mailto: link */
        const mailto = `mailto:${encodeURIComponent(this.contactEmail)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;

        /* Open the email client */
        window.location.href = mailto;

        /* Record submission time for rate limiting */
        this.lastSubmitTime = now;

        /* Close modal and show confirmation */
        bsModal.hide();
        this.app.showToast('Request sent! Thank you for your feedback.', 'success', 3000);
    }

    /**
     * Escape a string for use in an HTML attribute value.
     * @param {string} str
     * @returns {string}
     */
    escapeAttr(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML.replace(/"/g, '&quot;');
    }
}
