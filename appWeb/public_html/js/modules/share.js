/**
 * iHymns — Share Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
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

        /* Extract rich metadata from the current song page (#123) */
        const songPage = document.querySelector('.page-song');
        const metadata = this.extractSongMetadata(songPage, title);
        const richText = this.buildShareText(metadata, permalink);

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
                nativeBtn.classList.remove('d-none');
                nativeBtn.onclick = async () => {
                    try {
                        await navigator.share({
                            title: metadata.fullTitle,
                            text: richText,
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
                nativeBtn.classList.add('d-none');
            }
        }

        /* Copy URL button */
        const copyBtn = document.getElementById('share-copy-btn');
        if (copyBtn) {
            copyBtn.onclick = async () => {
                try {
                    await navigator.clipboard.writeText(permalink);
                    if (copyConfirm) copyConfirm.classList.remove('d-none');
                    setTimeout(() => copyConfirm?.classList.add('d-none'), 3000);
                } catch {
                    if (urlInput) {
                        urlInput.select();
                        document.execCommand('copy');
                    }
                }
            };
        }

        /* Copy formatted text button (#123) */
        const copyTextBtn = document.getElementById('share-copy-text-btn');
        if (copyTextBtn) {
            copyTextBtn.onclick = async () => {
                try {
                    await navigator.clipboard.writeText(richText + '\n' + permalink);
                    this.app.showToast('Song details copied', 'success', 2000);
                } catch {
                    console.warn('[Share] Failed to copy text');
                }
            };
        }

        /* Show the modal */
        new bootstrap.Modal(modal).show();
    }

    /**
     * Extract metadata from the rendered song page (#123).
     * @param {HTMLElement|null} songPage The .page-song element
     * @param {string} fallbackTitle Title fallback
     * @returns {object} Metadata object
     */
    extractSongMetadata(songPage, fallbackTitle) {
        const meta = {
            title: fallbackTitle,
            fullTitle: fallbackTitle + ' — iHymns',
            songbook: '',
            number: '',
            writers: '',
            composers: '',
            firstVerse: '',
        };

        if (!songPage) return meta;

        const songbook = songPage.dataset.songbook || '';
        const number = songPage.dataset.songNumber || '';
        meta.songbook = songbook;
        meta.number = number;
        meta.fullTitle = `${fallbackTitle} — ${songbook} #${number}`;

        /* Extract writers/composers */
        const writersEl = songPage.querySelector('.song-meta p:first-child');
        const composersEl = songPage.querySelector('.song-meta p:last-child');
        if (writersEl) meta.writers = writersEl.textContent.replace(/Words:\s*/, '').trim();
        if (composersEl && composersEl !== writersEl) {
            meta.composers = composersEl.textContent.replace(/Music:\s*/, '').trim();
        }

        /* Extract first verse snippet */
        const firstComponent = songPage.querySelector('.lyric-component .lyric-lines');
        if (firstComponent) {
            const lines = firstComponent.querySelectorAll('.lyric-line');
            const snippetLines = [...lines].slice(0, 2).map(l => l.textContent.trim());
            if (snippetLines.length > 0) {
                meta.firstVerse = snippetLines.join(' / ');
                if (lines.length > 2) meta.firstVerse += '...';
            }
        }

        return meta;
    }

    /**
     * Build formatted share text from metadata (#123).
     * @param {object} meta Song metadata
     * @param {string} permalink Song URL
     * @returns {string} Formatted text
     */
    buildShareText(meta, permalink) {
        let text = `"${meta.title}"`;
        if (meta.songbook && meta.number) {
            text += ` (${meta.songbook} #${meta.number})`;
        }
        if (meta.writers) text += `\nWords: ${meta.writers}`;
        if (meta.composers) text += `\nMusic: ${meta.composers}`;
        if (meta.firstVerse) text += `\n\n${meta.firstVerse}`;
        text += '\n\n— iHymns';
        return text;
    }
}
