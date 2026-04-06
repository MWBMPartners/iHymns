/**
 * iHymns — Share Module
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Provides song sharing functionality. Generates clean permalinks
 * for songs and allows users to:
 *   - Copy the permalink to clipboard
 *   - Use the native Web Share API (on supported platforms)
 *
 * Permalink format: https://ihymns.app/song/CP-0001
 * These URLs render proper OG meta tags for rich social media previews
 * (Facebook, Twitter, Slack, Discord, WhatsApp, etc.).
 *
 * Note: In future iterations (v2+), additional permalink formats
 * may be supported (e.g., /s/CP-0001 for short links).
 */

export class Share {
    constructor(app) {
        this.app = app;
    }

    /** Initialise ��� nothing needed on startup */
    init() {}

    /**
     * Initialise the share button on a song page.
     * Called by router after song page loads.
     */
    initSongPage() {
        const shareBtn = document.querySelector('.btn-share');
        if (!shareBtn) return;

        shareBtn.addEventListener('click', () => {
            const songId = shareBtn.dataset.songId;
            const songTitle = shareBtn.dataset.songTitle;
            this.openShareModal(songId, songTitle);
        });
    }

    /**
     * Open the share modal with the song's permalink.
     *
     * @param {string} songId Song ID (e.g., 'CP-0001')
     * @param {string} title Song title
     */
    openShareModal(songId, title) {
        const modal = document.getElementById('share-modal');
        if (!modal) return;

        /* Build the permalink URL */
        const permalink = window.location.origin + '/song/' + songId;

        /* Populate modal content */
        const titleEl = document.getElementById('share-song-title');
        const urlInput = document.getElementById('share-url-input');
        const nativeBtn = document.getElementById('share-native-btn');
        const copyConfirm = document.getElementById('share-copy-confirm');

        if (titleEl) titleEl.textContent = title;
        if (urlInput) urlInput.value = permalink;
        if (copyConfirm) copyConfirm.classList.add('d-none');

        /* Show native share button if Web Share API is available */
        if (nativeBtn) {
            if (navigator.share) {
                nativeBtn.style.display = '';
                nativeBtn.onclick = async () => {
                    try {
                        await navigator.share({
                            title: title + ' — iHymns',
                            text: `Check out "${title}" on iHymns`,
                            url: permalink,
                        });
                    } catch (error) {
                        /* User cancelled or share failed — not an error */
                        if (error.name !== 'AbortError') {
                            console.warn('[Share] Native share failed:', error);
                        }
                    }
                };
            } else {
                nativeBtn.style.display = 'none';
            }
        }

        /* Copy button */
        const copyBtn = document.getElementById('share-copy-btn');
        if (copyBtn) {
            copyBtn.onclick = async () => {
                try {
                    await navigator.clipboard.writeText(permalink);
                    if (copyConfirm) copyConfirm.classList.remove('d-none');
                    /* Hide confirmation after 3 seconds */
                    setTimeout(() => copyConfirm?.classList.add('d-none'), 3000);
                } catch {
                    /* Fallback: select the input text */
                    if (urlInput) {
                        urlInput.select();
                        document.execCommand('copy');
                    }
                }
            };
        }

        /* Show the modal */
        new bootstrap.Modal(modal).show();
    }
}
