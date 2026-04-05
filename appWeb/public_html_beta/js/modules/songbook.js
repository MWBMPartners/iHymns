/**
 * iHymns — Songbook Browser Module
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Provides the two primary browsing views for the iHymns application:
 *   1. Songbook Grid  — A card-based overview of all available songbooks.
 *   2. Song List      — A detailed listing of every song within a single songbook.
 *
 * EXPORTS:
 *   renderSongbookGrid(songData, containerEl)
 *   renderSongList(songData, songbookId, containerEl)
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

/**
 * Import shared DOM / text / routing helpers from the utilities module.
 *
 * $                   — Shorthand for document.querySelector.
 * createElement       — Factory for building DOM elements declaratively.
 * escapeHtml          — Sanitises strings before innerHTML insertion.
 * setHashRoute        — Programmatic hash-based SPA navigation.
 * getSongLyricsPreview — Extracts a short preview string from a song object.
 */
import {
    $,
    createElement,
    escapeHtml,
    setHashRoute,
    getSongLyricsPreview
} from '../utils/helpers.js';

/**
 * Import favourites helpers from the favourites module.
 *
 * isFavorite    — Returns true if the given song ID is in the user's favourites.
 * toggleFavorite — Adds or removes a song ID from the user's favourites.
 */
import { isFavorite, toggleFavorite } from './favorites.js';

/* =========================================================================
 * PRIVATE CONSTANTS
 * ========================================================================= */

/**
 * SONGBOOK_GRADIENTS
 *
 * An array of CSS linear-gradient strings used to give each songbook card
 * a distinctive coloured header.  The gradient is selected by taking the
 * songbook's index modulo the array length, ensuring every card gets a
 * colour even when the number of songbooks exceeds the palette size.
 */
const SONGBOOK_GRADIENTS = [
    /* Soft warm amber — matches iLyrics dB brand, gentle warmth */
    'linear-gradient(135deg, #d4a574 0%, #e8c9a0 100%)',
    /* Soft dusty rose — gentle, warm, worship-appropriate */
    'linear-gradient(135deg, #c9a0a0 0%, #ddbfbf 100%)',
    /* Soft sage green — calm, natural, restful */
    'linear-gradient(135deg, #a0b89c 0%, #c4d4c0 100%)',
    /* Soft slate blue — trust, tradition, peaceful */
    'linear-gradient(135deg, #9aafc4 0%, #bccde0 100%)',
    /* Soft warm gold — heritage, classic, inviting */
    'linear-gradient(135deg, #c8b078 0%, #ddd0a8 100%)',
    /* Soft muted mauve — reverence, contemplative */
    'linear-gradient(135deg, #b0a0be 0%, #cfc4d8 100%)',
    /* Soft terracotta — earthy, grounded, warm */
    'linear-gradient(135deg, #c4a08c 0%, #d8c4b4 100%)',
    /* Soft cool grey — modern, neutral, clean */
    'linear-gradient(135deg, #a8b0b8 0%, #c8cdd4 100%)'
];

/* =========================================================================
 * PRIVATE HELPERS
 * ========================================================================= */

/**
 * _buildAbbreviation(name)
 *
 * Derives a short abbreviation (1-3 uppercase letters) from a songbook
 * name.  The abbreviation is displayed prominently on each card to give
 * users a quick visual identifier.
 *
 * Algorithm:
 *   1. Split the name by whitespace into individual words.
 *   2. Take the first letter of each word.
 *   3. Join them and convert to uppercase.
 *   4. Cap the result at 3 characters so it fits the card badge.
 *
 * @param {string} name - The full songbook name (e.g., "Church Hymnal")
 * @returns {string} The abbreviation (e.g., "CH")
 */
function _buildAbbreviation(name) {
    /* Split the songbook name on whitespace boundaries */
    const words = name.split(/\s+/);

    /* Map each word to its first character */
    const initials = words.map(word => word.charAt(0));

    /* Join the initials, uppercase them, and limit to 3 characters */
    return initials.join('').toUpperCase().slice(0, 3);
}

/**
 * _getGradient(index)
 *
 * Returns a CSS gradient string for the given numeric index.  Uses modulo
 * arithmetic to cycle through SONGBOOK_GRADIENTS so that any number of
 * songbooks can be rendered without running out of colours.
 *
 * @param {number} index - The songbook's positional index in the array
 * @returns {string} A CSS linear-gradient value
 */
function _getGradient(index) {
    /* Modulo wraps the index back to the start of the palette */
    return SONGBOOK_GRADIENTS[index % SONGBOOK_GRADIENTS.length];
}

/**
 * _buildMediaIcons(hasAudio, hasSheetMusic)
 *
 * Creates a small container element holding Bootstrap-icon indicators for
 * available media types (audio and/or sheet music).  Icons are only
 * rendered when the corresponding flag is true, keeping the UI clean.
 *
 * @param {boolean} hasAudio      - Whether this item has audio playback available
 * @param {boolean} hasSheetMusic - Whether this item has sheet music available
 * @returns {Element} A <span> element with zero or more icon <i> children
 */
function _buildMediaIcons(hasAudio, hasSheetMusic) {
    /* Create the outer container span for the media icons */
    const container = createElement('span', { className: 'song-list-icons' });

    /* If audio is available, add the volume-up icon */
    if (hasAudio) {
        /* Create an <i> element with the Bootstrap Icons volume-up class */
        const audioIcon = createElement('i', {
            className: 'bi bi-volume-up-fill media-indicator has-media',
            title: 'Audio available'        /* Tooltip for accessibility */
        });

        /* Append the audio icon to the container */
        container.appendChild(audioIcon);
    }

    /* If sheet music is available, add the music-note-beamed icon */
    if (hasSheetMusic) {
        /* Create an <i> element with the Bootstrap Icons music-note class */
        const sheetIcon = createElement('i', {
            className: 'bi bi-music-note-beamed media-indicator has-media',
            title: 'Sheet music available'  /* Tooltip for accessibility */
        });

        /* Append the sheet music icon to the container */
        container.appendChild(sheetIcon);
    }

    /* Return the container (may be empty if neither flag is true) */
    return container;
}

/* =========================================================================
 * PUBLIC API — renderSongbookGrid
 * ========================================================================= */

/**
 * renderSongbookGrid(songData, containerEl)
 *
 * Renders the home view: a responsive grid of songbook cards.
 *
 * Each card displays:
 *   - A coloured gradient header with the songbook abbreviation badge.
 *   - The songbook name as the card title.
 *   - The total number of songs in the songbook.
 *   - Small media-availability indicators (audio / sheet music) when at
 *     least one song in the songbook has that media type.
 *
 * Clicking a card navigates the SPA to the songbook's song list view via
 * the hash route #/songbook/<songbook-id>.
 *
 * @param {object}  songData     - The master data object containing .songbooks and .songs
 * @param {Element} containerEl  - The DOM element to render into (its contents are replaced)
 */
export function renderSongbookGrid(songData, containerEl) {
    /* -----------------------------------------------------------------
     * Step 1 — Clear the container so we start with a blank slate.
     * ----------------------------------------------------------------- */
    containerEl.innerHTML = '';

    /* -----------------------------------------------------------------
     * Step 2 — Create the outer grid wrapper.
     * Uses .songbook-grid for CSS Grid / Flexbox layout defined in
     * styles.css, and .view-fade-in for an entrance animation.
     * ----------------------------------------------------------------- */
    const gridEl = createElement('div', {
        className: 'songbook-grid view-fade-in'
    });

    /* -----------------------------------------------------------------
     * Step 3 — Pre-compute media availability per songbook.
     * We scan all songs once and build a Map keyed by songbook ID so
     * that each card can show whether audio or sheet music exists for
     * any song in that collection.
     * ----------------------------------------------------------------- */

    /** @type {Map<string, {hasAudio: boolean, hasSheetMusic: boolean}>} */
    const mediaByBook = new Map();

    /* Iterate over every song in the dataset */
    songData.songs.forEach(song => {
        /* Retrieve or initialise the media flags for this songbook */
        if (!mediaByBook.has(song.songbook)) {
            /* First time encountering this songbook — initialise both flags to false */
            mediaByBook.set(song.songbook, { hasAudio: false, hasSheetMusic: false });
        }

        /* Get the current flags object for mutation */
        const flags = mediaByBook.get(song.songbook);

        /* Set audio flag to true if ANY song in the book has audio */
        if (song.hasAudio) {
            flags.hasAudio = true;
        }

        /* Set sheet music flag to true if ANY song in the book has sheets */
        if (song.hasSheetMusic) {
            flags.hasSheetMusic = true;
        }
    });

    /* -----------------------------------------------------------------
     * Step 4 — Build a card for each songbook in the dataset.
     * ----------------------------------------------------------------- */
    songData.songbooks.forEach((songbook, index) => {

        /* ---- 4a. Create the card root element ---- */
        const cardEl = createElement('div', {
            className: 'songbook-card',
            /* Store the songbook ID on the element for potential future use */
            dataset: { songbookId: songbook.id },
            /* Indicate the card is interactive for screen readers */
            role: 'button',
            tabindex: '0',
            'aria-label': `Open ${songbook.name} songbook — ${songbook.songCount} songs`
        });

        /* ---- 4b. Build the coloured gradient header section ---- */
        const headerEl = createElement('div', {
            className: 'songbook-card-header'
        });

        /* Apply the gradient background inline (colour per card) */
        headerEl.style.background = _getGradient(index);

        /* ---- 4c. Create the large abbreviation badge ---- */
        /* Use the songbook's ID directly (e.g., "SDAH", "CH") as the canonical
           abbreviation from the source data, not a computed abbreviation */
        const badgeEl = createElement('span', {
            className: 'songbook-badge'
        }, songbook.id);

        /* Append the badge into the header */
        headerEl.appendChild(badgeEl);

        /* ---- 4d. Determine media availability for this songbook ---- */
        const media = mediaByBook.get(songbook.id) || { hasAudio: false, hasSheetMusic: false };

        /* If the songbook has audio or sheet music, add indicators to the header */
        if (media.hasAudio || media.hasSheetMusic) {
            /* Build the media icons element */
            const headerIcons = _buildMediaIcons(media.hasAudio, media.hasSheetMusic);

            /* Append the icons to the header area */
            headerEl.appendChild(headerIcons);
        }

        /* ---- 4e. Build the card body (name + song count) ---- */
        const bodyEl = createElement('div', {
            className: 'songbook-card-body'
        });

        /* Songbook title — uses escapeHtml to prevent XSS from data */
        const titleEl = createElement('h3', {
            innerHTML: escapeHtml(songbook.name)
        });

        /* Song count line displayed beneath the title */
        const countEl = createElement('p', {
            className: 'songbook-song-count'
        }, `${songbook.songCount} songs`);

        /* Append title and count into the body */
        bodyEl.appendChild(titleEl);
        bodyEl.appendChild(countEl);

        /* ---- 4f. Assemble the complete card ---- */
        cardEl.appendChild(headerEl);
        cardEl.appendChild(bodyEl);

        /* ---- 4g. Attach click handler for navigation ---- */
        cardEl.addEventListener('click', () => {
            /* Navigate to the song list view for this songbook */
            setHashRoute(`/songbook/${songbook.id}`);
        });

        /* ---- 4h. Support keyboard activation (Enter / Space) ---- */
        cardEl.addEventListener('keydown', (event) => {
            /* Only respond to Enter or Space keys */
            if (event.key === 'Enter' || event.key === ' ') {
                /* Prevent the default scroll behaviour for the Space key */
                event.preventDefault();

                /* Trigger the same navigation as a click */
                setHashRoute(`/songbook/${songbook.id}`);
            }
        });

        /* ---- 4i. Append the finished card to the grid ---- */
        gridEl.appendChild(cardEl);
    });

    /* -----------------------------------------------------------------
     * Step 5 — Append the fully-populated grid into the container.
     * ----------------------------------------------------------------- */
    containerEl.appendChild(gridEl);
}

/* =========================================================================
 * PUBLIC API — renderSongList
 * ========================================================================= */

/**
 * renderSongList(songData, songbookId, containerEl)
 *
 * Renders the song-list view for a specific songbook.
 *
 * The view consists of:
 *   - A back button that returns to the songbook grid (home view).
 *   - A breadcrumb trail: Home > Songbook Name.
 *   - A scrollable list of song items, each showing:
 *       • Song number (hymn number within the book).
 *       • Song title.
 *       • A short lyrics preview (first two lines of verse 1).
 *       • A favourite/star toggle button.
 *       • Media indicators (audio / sheet music icons).
 *
 * Clicking a song item navigates the SPA to #/song/<SONG_ID>.
 *
 * @param {object}  songData     - The master data object containing .songbooks and .songs
 * @param {string}  songbookId   - The ID of the songbook to display (e.g., "CH")
 * @param {Element} containerEl  - The DOM element to render into (its contents are replaced)
 */
export function renderSongList(songData, songbookId, containerEl) {
    /* -----------------------------------------------------------------
     * Step 1 — Clear the container.
     * ----------------------------------------------------------------- */
    containerEl.innerHTML = '';

    /* -----------------------------------------------------------------
     * Step 2 — Look up the songbook metadata by its ID.
     * ----------------------------------------------------------------- */

    /** @type {object|undefined} */
    const songbook = songData.songbooks.find(sb => sb.id === songbookId);

    /* If the songbook ID is invalid, show a user-friendly error and bail */
    if (!songbook) {
        /* Create an error message element */
        const errorEl = createElement('p', {
            className: 'text-muted'
        }, 'Songbook not found. Please go back and try again.');

        /* Append it to the container */
        containerEl.appendChild(errorEl);

        /* Stop further rendering */
        return;
    }

    /* -----------------------------------------------------------------
     * Step 3 — Build the wrapper with a fade-in animation.
     * ----------------------------------------------------------------- */
    const wrapperEl = createElement('div', {
        className: 'view-fade-in'
    });

    /* -----------------------------------------------------------------
     * Step 4 — Create the "Back" button.
     * Clicking navigates back to the songbook grid (home route).
     * ----------------------------------------------------------------- */
    const backBtn = createElement('button', {
        className: 'back-btn',
        'aria-label': 'Back to all songbooks',
        onClick: () => {
            /* Navigate to the home / songbook grid route */
            setHashRoute('/');
        }
    }, [
        /* Left-arrow icon from Bootstrap Icons */
        createElement('i', { className: 'bi bi-arrow-left' }),
        /* Visible label text next to the icon */
        ' Back'
    ]);

    /* Append the back button to the wrapper */
    wrapperEl.appendChild(backBtn);

    /* -----------------------------------------------------------------
     * Step 5 — Build the breadcrumb navigation trail.
     * Provides context: Home > Songbook Name
     * ----------------------------------------------------------------- */
    const breadcrumbEl = createElement('nav', {
        className: 'app-breadcrumb',
        'aria-label': 'Breadcrumb'
    });

    /* "Home" link — clicking returns to the songbook grid */
    const homeLink = createElement('a', {
        href: '#/',
        'aria-label': 'Home'
    }, 'Home');

    /* Separator between breadcrumb segments (e.g., " > ") */
    const separatorEl = createElement('span', {
        className: 'separator'
    }, ' / ');

    /* Current page label — the songbook name (not a link, it's the active page) */
    const currentPageEl = createElement('span', {
        'aria-current': 'page'
    }, songbook.name);

    /* Assemble the breadcrumb: Home > separator > Current Page */
    breadcrumbEl.appendChild(homeLink);
    breadcrumbEl.appendChild(separatorEl);
    breadcrumbEl.appendChild(currentPageEl);

    /* Append the breadcrumb to the wrapper */
    wrapperEl.appendChild(breadcrumbEl);

    /* -----------------------------------------------------------------
     * Step 6 — Add a heading for the songbook.
     * ----------------------------------------------------------------- */
    const headingEl = createElement('h2', {}, songbook.name);

    /* Append the heading to the wrapper */
    wrapperEl.appendChild(headingEl);

    /* -----------------------------------------------------------------
     * Step 7 — Filter songs that belong to this songbook.
     * ----------------------------------------------------------------- */

    /** @type {Array<object>} */
    const songs = songData.songs.filter(song => song.songbook === songbookId);

    /* -----------------------------------------------------------------
     * Step 8 — Sort songs by their hymn number (ascending).
     * Song numbers may be numeric or alphanumeric; we compare
     * numerically when possible, falling back to locale string compare.
     * ----------------------------------------------------------------- */
    songs.sort((a, b) => {
        /* Attempt to parse both numbers as integers */
        const numA = parseInt(a.number, 10);
        const numB = parseInt(b.number, 10);

        /* If both parse successfully, compare numerically */
        if (!isNaN(numA) && !isNaN(numB)) {
            return numA - numB;
        }

        /* Fallback: compare the raw number strings lexicographically */
        return String(a.number).localeCompare(String(b.number));
    });

    /* -----------------------------------------------------------------
     * Step 9 — Handle the case where the songbook has no songs.
     * ----------------------------------------------------------------- */
    if (songs.length === 0) {
        /* Create an informational message */
        const emptyEl = createElement('p', {
            className: 'text-muted'
        }, 'No songs found in this songbook.');

        /* Append to the wrapper and finish */
        wrapperEl.appendChild(emptyEl);
        containerEl.appendChild(wrapperEl);

        /* Stop further rendering */
        return;
    }

    /* -----------------------------------------------------------------
     * Step 10 — Build the song list container.
     * ----------------------------------------------------------------- */
    const listEl = createElement('div', {
        className: 'song-list',
        role: 'list',
        'aria-label': `Songs in ${songbook.name}`
    });

    /* -----------------------------------------------------------------
     * Step 11 — Create a list item for each song.
     * ----------------------------------------------------------------- */
    songs.forEach(song => {

        /* ---- 11a. Create the list item root ---- */
        const itemEl = createElement('div', {
            className: 'song-list-item',
            role: 'listitem',
            tabindex: '0',
            dataset: { songId: song.id },
            'aria-label': `Song ${song.number}: ${song.title}`
        });

        /* ---- 11b. Song number badge ---- */
        const numberEl = createElement('span', {
            className: 'song-list-number'
        }, String(song.number));

        /* ---- 11c. Build the text content block (title + preview) ---- */
        const contentEl = createElement('div', {
            className: 'song-list-content'
        });

        /* Song title — escaped to prevent injection */
        const titleEl = createElement('span', {
            className: 'song-list-title',
            innerHTML: escapeHtml(song.title)
        });

        /* Lyrics preview — first two lines of the first component */
        const previewText = getSongLyricsPreview(song);

        /* Only create the preview element if there is preview text */
        const metaEl = createElement('span', {
            className: 'song-list-meta',
            innerHTML: escapeHtml(previewText)
        });

        /* Assemble the content block */
        contentEl.appendChild(titleEl);
        contentEl.appendChild(metaEl);

        /* ---- 11d. Favourite toggle button ---- */

        /* Determine whether this song is currently in the user's favourites */
        const isFav = isFavorite(song.id);

        /* Choose the appropriate star icon class based on favourite state */
        const starIconClass = isFav ? 'bi bi-star-fill' : 'bi bi-star';

        /* Build the button with conditional 'active' class */
        const favBtn = createElement('button', {
            className: isFav ? 'favorite-btn active' : 'favorite-btn',
            'aria-label': isFav ? 'Remove from favourites' : 'Add to favourites',
            'aria-pressed': isFav ? 'true' : 'false',
            onClick: (event) => {
                /* Prevent the click from bubbling up to the list item handler */
                event.stopPropagation();

                /* Toggle the song's favourite state in storage */
                toggleFavorite(song.id);

                /* Re-read the updated state after toggling */
                const nowFav = isFavorite(song.id);

                /* Update the button's visual state */
                favBtn.className = nowFav ? 'favorite-btn active' : 'favorite-btn';

                /* Update the aria attributes for screen readers */
                favBtn.setAttribute('aria-label', nowFav ? 'Remove from favourites' : 'Add to favourites');
                favBtn.setAttribute('aria-pressed', nowFav ? 'true' : 'false');

                /* Swap the icon inside the button */
                const icon = favBtn.querySelector('i');
                if (icon) {
                    icon.className = nowFav ? 'bi bi-star-fill' : 'bi bi-star';
                }
            }
        });

        /* Create the star icon element and place it inside the button */
        const starIcon = createElement('i', { className: starIconClass });
        favBtn.appendChild(starIcon);

        /* ---- 11e. Media indicator icons ---- */
        const mediaIcons = _buildMediaIcons(song.hasAudio, song.hasSheetMusic);

        /* ---- 11f. Assemble the complete list item ---- */
        itemEl.appendChild(numberEl);
        itemEl.appendChild(contentEl);
        itemEl.appendChild(favBtn);
        itemEl.appendChild(mediaIcons);

        /* ---- 11g. Attach click handler to navigate to the song view ---- */
        itemEl.addEventListener('click', () => {
            /* Navigate to the full song display for this song */
            setHashRoute(`/song/${song.id}`);
        });

        /* ---- 11h. Support keyboard activation (Enter / Space) ---- */
        itemEl.addEventListener('keydown', (event) => {
            /* Only respond to Enter or Space */
            if (event.key === 'Enter' || event.key === ' ') {
                /* Prevent default scroll for Space key */
                event.preventDefault();

                /* Navigate to the song view */
                setHashRoute(`/song/${song.id}`);
            }
        });

        /* ---- 11i. Append the finished item to the list ---- */
        listEl.appendChild(itemEl);
    });

    /* -----------------------------------------------------------------
     * Step 12 — Append the list to the wrapper, and the wrapper to the
     * container, completing the render.
     * ----------------------------------------------------------------- */
    wrapperEl.appendChild(listEl);
    containerEl.appendChild(wrapperEl);
}
