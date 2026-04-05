/**
 * iHymns — Favourites Module
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Manages the user's favourite songs collection. Provides functions to
 * add, remove, query, and display favourite songs. Favourite song IDs
 * are persisted in localStorage so they survive page reloads and
 * browser sessions. The module dispatches custom events when the
 * favourites list changes, allowing other parts of the app (e.g.,
 * song view heart icons, badge counts) to react in real time.
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

/**
 * Import shared utility functions from the helpers module:
 *
 * - $              : Shorthand for document.querySelector()
 * - createElement  : Creates a DOM element with attributes and children
 * - escapeHtml     : Escapes HTML special characters to prevent XSS
 * - setHashRoute   : Programmatically navigates to a hash-based route
 * - getSongLyricsPreview : Extracts the first two lines of a song for preview
 */
import {
    $,
    createElement,
    escapeHtml,
    setHashRoute,
    getSongLyricsPreview
} from '../utils/helpers.js';

/* =========================================================================
 * CONSTANTS
 * ========================================================================= */

/**
 * STORAGE_KEY
 *
 * The localStorage key under which the favourites array is stored.
 * The value is a JSON-serialised array of song ID strings
 * (e.g., '["CH-0001","CH-0045","RH-0012"]').
 *
 * Using a namespaced key ('ihymns_favorites') prevents collisions
 * with other applications that might share the same origin.
 */
const STORAGE_KEY = 'ihymns_favorites';

/* =========================================================================
 * INTERNAL HELPERS
 * ========================================================================= */

/**
 * _readFavorites()
 *
 * Reads the raw favourites array from localStorage and parses it.
 * This is an internal function used by the public API functions.
 *
 * If the key does not exist or the stored value is not valid JSON,
 * an empty array is returned as a safe fallback.
 *
 * @returns {string[]} An array of song ID strings
 * @private
 */
function _readFavorites() {
    try {
        /* Attempt to read the raw JSON string from localStorage */
        const raw = localStorage.getItem(STORAGE_KEY);

        /* If the key does not exist, getItem returns null — return empty array */
        if (raw === null) {
            return [];
        }

        /* Parse the JSON string into a JavaScript array */
        const parsed = JSON.parse(raw);

        /* Validate that the parsed result is actually an array */
        if (!Array.isArray(parsed)) {
            return [];
        }

        /* Return the parsed array of song IDs */
        return parsed;
    } catch (error) {
        /**
         * If JSON.parse throws (corrupted data), log a warning
         * and return an empty array so the app does not crash.
         */
        console.warn('[Favorites] Failed to parse localStorage data:', error);
        return [];
    }
}

/**
 * _writeFavorites(favorites)
 *
 * Serialises the favourites array to JSON and writes it to localStorage.
 * This is an internal function used after any mutation (add/remove/clear).
 *
 * @param {string[]} favorites - The updated array of song ID strings
 * @private
 */
function _writeFavorites(favorites) {
    try {
        /* Convert the array to a JSON string and store it */
        localStorage.setItem(STORAGE_KEY, JSON.stringify(favorites));
    } catch (error) {
        /**
         * localStorage.setItem can throw if storage is full (QuotaExceededError)
         * or if the browser is in private mode with storage disabled.
         * Log the error so developers can diagnose the issue.
         */
        console.error('[Favorites] Failed to write to localStorage:', error);
    }
}

/**
 * _dispatchChanged()
 *
 * Dispatches a custom 'favorites-changed' event on the document.
 * Other modules can listen for this event to update their UI
 * (e.g., re-rendering heart icon states, updating a favourites count badge).
 *
 * The event is non-bubbling and non-cancelable since it is purely
 * informational — listeners should read the new state via getFavorites().
 *
 * @private
 */
function _dispatchChanged() {
    /* Create a new custom event with the name 'favorites-changed' */
    const event = new CustomEvent('favorites-changed', {
        /* Include the current favourites array in the event detail for convenience */
        detail: { favorites: _readFavorites() },
        /* Allow the event to bubble up through the DOM tree */
        bubbles: true
    });

    /* Dispatch the event on the document so any listener can catch it */
    document.dispatchEvent(event);
}

/* =========================================================================
 * PUBLIC API — DATA FUNCTIONS
 * ========================================================================= */

/**
 * getFavorites()
 *
 * Returns the current array of favourite song IDs from localStorage.
 * This is a read-only snapshot — mutating the returned array will NOT
 * affect the stored data. Use toggleFavorite() to modify favourites.
 *
 * @returns {string[]} An array of song ID strings (e.g., ['CH-0001', 'RH-0012'])
 */
export function getFavorites() {
    /* Delegate to the internal read function and return the result */
    return _readFavorites();
}

/**
 * isFavorite(songId)
 *
 * Checks whether a specific song is currently in the user's favourites.
 * Used by the song view to determine whether to show a filled or
 * outlined heart icon.
 *
 * @param {string} songId - The unique song identifier (e.g., 'CH-0003')
 * @returns {boolean} True if the song is a favourite, false otherwise
 */
export function isFavorite(songId) {
    /* Read the current favourites list */
    const favorites = _readFavorites();

    /* Check if the songId exists in the array using .includes() */
    return favorites.includes(songId);
}

/**
 * toggleFavorite(songId)
 *
 * Adds a song to favourites if it is not already there, or removes
 * it if it is. This is the primary mutation function for favourites.
 *
 * After mutating, it persists the change to localStorage and dispatches
 * a 'favorites-changed' custom event on the document so that other
 * parts of the UI can react (e.g., update heart icon fill state).
 *
 * @param {string} songId - The unique song identifier to toggle
 * @returns {boolean} The new favourite state: true if added, false if removed
 */
export function toggleFavorite(songId) {
    /* Read the current favourites array from storage */
    const favorites = _readFavorites();

    /* Check if the song is already in the favourites list */
    const index = favorites.indexOf(songId);

    /* Declare a variable to hold the new state after toggling */
    let isNowFavorite;

    if (index === -1) {
        /**
         * Song is NOT in favourites — add it.
         * Push the songId to the end of the array so that
         * most-recently-favourited songs appear last.
         */
        favorites.push(songId);

        /* The song is now a favourite */
        isNowFavorite = true;
    } else {
        /**
         * Song IS already in favourites — remove it.
         * splice(index, 1) removes exactly one element at the found position.
         */
        favorites.splice(index, 1);

        /* The song is no longer a favourite */
        isNowFavorite = false;
    }

    /* Persist the updated array back to localStorage */
    _writeFavorites(favorites);

    /* Notify the rest of the application that favourites have changed */
    _dispatchChanged();

    /* Return the new state so the caller knows what happened */
    return isNowFavorite;
}

/**
 * clearAllFavorites()
 *
 * Removes ALL songs from the favourites list. Called when the user
 * taps the "Clear All" button in the favourites view.
 *
 * Writes an empty array to localStorage and dispatches the
 * 'favorites-changed' event.
 */
function clearAllFavorites() {
    /* Overwrite the stored favourites with an empty array */
    _writeFavorites([]);

    /* Notify listeners that favourites have been cleared */
    _dispatchChanged();
}

/* =========================================================================
 * PUBLIC API — RENDERING
 * ========================================================================= */

/**
 * renderFavorites(songData, containerEl)
 *
 * Renders the full favourites view into the given container element.
 * This includes:
 *   - A header with the view title and a "Clear All" button
 *   - A scrollable list of favourite songs (matching the song-list style)
 *   - A friendly empty-state message if no favourites exist
 *
 * Each song list item displays:
 *   - The song number (e.g., "003")
 *   - The song title
 *   - A lyrics preview (first two lines)
 *   - A songbook badge (e.g., "CH" for Church Hymnal)
 *   - A filled heart icon (since all listed songs are favourites)
 *
 * Clicking a song item navigates to #/song/<songId> via hash routing.
 *
 * @param {object} songData     - The full song dataset object; must have a
 *                                 'songs' property which is an array of song objects.
 *                                 Each song object must have: id, number, title,
 *                                 songbookId, and components.
 * @param {Element} containerEl - The DOM element to render the view into.
 *                                 Its contents will be replaced entirely.
 */
export function renderFavorites(songData, containerEl) {
    /* Clear any existing content inside the container */
    containerEl.innerHTML = '';

    /* Read the current list of favourite song IDs */
    const favoriteIds = _readFavorites();

    /**
     * Look up each favourite song ID in the full song dataset.
     * Filter out any IDs that no longer match a song (e.g., if a
     * songbook was removed or a song ID changed between app versions).
     */
    const favoriteSongs = favoriteIds
        .map(id => songData.songs.find(song => song.id === id))  /* Map each ID to its song object */
        .filter(song => song !== undefined);                      /* Remove any unresolved (undefined) entries */

    /* -----------------------------------------------------------------
     * VIEW WRAPPER
     *
     * Create the outermost container div. The .view-fade-in class
     * triggers a CSS fade-in animation when the view appears.
     * ----------------------------------------------------------------- */
    const viewWrapper = createElement('div', {
        className: 'view-fade-in'
    });

    /* -----------------------------------------------------------------
     * HEADER SECTION
     *
     * Contains a back button, the "Favourites" title, and optionally
     * a "Clear All" button (only shown when there are favourites).
     * ----------------------------------------------------------------- */
    const header = createElement('div', {
        className: 'view-header'
    });

    /* Back button — navigates the user to the previous/home view */
    const backBtn = createElement('button', {
        className: 'back-btn',
        'aria-label': 'Go back',
        onClick: () => {
            /* Navigate to the app home route */
            setHashRoute('/');
        }
    });

    /* Back arrow icon (using an inline SVG for a left-pointing chevron) */
    backBtn.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
             viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6"></polyline>
        </svg>
    `;

    /* Page title element */
    const title = createElement('h1', {}, 'Favourites');

    /* Append the back button and title to the header */
    header.appendChild(backBtn);
    header.appendChild(title);

    /**
     * "Clear All" button — only rendered when there are favourites.
     * When clicked, it clears all favourites and re-renders the view
     * to show the empty state.
     */
    if (favoriteSongs.length > 0) {
        const clearBtn = createElement('button', {
            className: 'clear-all-btn',
            'aria-label': 'Clear all favourites',
            onClick: () => {
                /* Remove all favourite song IDs from storage */
                clearAllFavorites();

                /* Re-render the entire favourites view to reflect the empty state */
                renderFavorites(songData, containerEl);
            }
        }, 'Clear All');

        /* Add the Clear All button to the header */
        header.appendChild(clearBtn);
    }

    /* Add the completed header to the view wrapper */
    viewWrapper.appendChild(header);

    /* -----------------------------------------------------------------
     * EMPTY STATE
     *
     * If the user has no favourites, display a friendly message with
     * an icon encouraging them to add songs.
     * ----------------------------------------------------------------- */
    if (favoriteSongs.length === 0) {
        /* Create the empty-state container */
        const emptyState = createElement('div', {
            className: 'favorites-empty'
        });

        /* Heart icon SVG — a large outlined heart to illustrate the concept */
        const emptyIcon = createElement('div', {
            className: 'favorites-empty-icon'
        });
        emptyIcon.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
            </svg>
        `;

        /* Friendly message telling the user how to add favourites */
        const emptyMessage = createElement('p', {}, 'No favourites yet');

        /* Sub-message with instructions */
        const emptyHint = createElement('p', {
            className: 'favorites-empty-hint'
        }, 'Tap the heart icon on any song to add it to your favourites.');

        /* Assemble the empty state elements */
        emptyState.appendChild(emptyIcon);    /* Heart icon */
        emptyState.appendChild(emptyMessage); /* Primary message */
        emptyState.appendChild(emptyHint);    /* Instructional hint */

        /* Add empty state to the view and render into the container */
        viewWrapper.appendChild(emptyState);
        containerEl.appendChild(viewWrapper);

        /* Early return — no song list to render */
        return;
    }

    /* -----------------------------------------------------------------
     * SONG LIST
     *
     * Renders each favourite song as a clickable list item.
     * The layout mirrors the main song list used in songbook views
     * for visual consistency across the app.
     * ----------------------------------------------------------------- */

    /* Create the list container element */
    const listContainer = createElement('div', {
        className: 'song-list',
        role: 'list',
        'aria-label': 'Favourite songs'
    });

    /* Iterate over each favourite song and create a list item */
    favoriteSongs.forEach(song => {

        /* --- List Item Wrapper ---
         * The .song-list-item class provides the row layout styling.
         * A click handler navigates to the song's detail view. */
        const item = createElement('div', {
            className: 'song-list-item',
            'role': 'listitem',
            'tabindex': '0',
            'aria-label': `View ${song.title}`,
            onClick: () => {
                /* Navigate to the song detail route (e.g., #/song/CH-0003) */
                setHashRoute(`/song/${song.id}`);
            }
        });

        /* Keyboard activation: Enter/Space navigates to the song */
        item.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                setHashRoute(`/song/${song.id}`);
            }
        });

        /* --- Song Number ---
         * Displays the numeric portion of the song (e.g., "003").
         * The .song-list-number class right-aligns and mono-spaces it. */
        const numberEl = createElement('span', {
            className: 'song-list-number'
        }, String(song.number));

        /* --- Text Content Area ---
         * Contains the song title and a lyrics preview. */
        const textArea = createElement('div', {
            className: 'song-list-text'
        });

        /* Song title — displayed prominently */
        const titleEl = createElement('span', {
            className: 'song-list-title'
        }, escapeHtml(song.title));

        /* Lyrics preview — first two lines of the first component */
        const preview = getSongLyricsPreview(song);

        /* Meta line showing the lyrics preview text */
        const metaEl = createElement('span', {
            className: 'song-list-meta'
        }, escapeHtml(preview));

        /* Assemble the text area: title on top, meta (preview) below */
        textArea.appendChild(titleEl);
        textArea.appendChild(metaEl);

        /* --- Icons / Badges Area ---
         * Contains the songbook badge and the favourite heart button.
         * Positioned on the right side of the list item. */
        const iconsArea = createElement('div', {
            className: 'song-list-icons'
        });

        /* Songbook badge — shows which hymnal the song belongs to (e.g., "CH") */
        const badge = createElement('span', {
            className: 'songbook-badge'
        }, song.songbookId);

        /* Favourite (heart) button — shown as active since the song IS a favourite */
        const favBtn = createElement('button', {
            className: 'favorite-btn active',
            'aria-label': 'Remove from favourites',
            'aria-pressed': 'true',
            onClick: (event) => {
                /**
                 * Stop the click from propagating to the parent item,
                 * which would navigate to the song view. We only want
                 * to toggle the favourite state here.
                 */
                event.stopPropagation();

                /* Toggle the favourite state (this will remove it) */
                toggleFavorite(song.id);

                /* Re-render the entire favourites view to reflect the removal */
                renderFavorites(songData, containerEl);
            }
        });

        /* Filled heart SVG icon — indicates the song is currently a favourite */
        favBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                 viewBox="0 0 24 24" fill="currentColor" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
            </svg>
        `;

        /* Assemble the icons area: badge first, then the heart button */
        iconsArea.appendChild(badge);
        iconsArea.appendChild(favBtn);

        /* --- Assemble the List Item ---
         * Layout: [number] [text area] [icons area] */
        item.appendChild(numberEl);
        item.appendChild(textArea);
        item.appendChild(iconsArea);

        /* Add the completed list item to the list container */
        listContainer.appendChild(item);
    });

    /* Add the populated song list to the view wrapper */
    viewWrapper.appendChild(listContainer);

    /* -----------------------------------------------------------------
     * FINAL RENDER
     *
     * Append the fully assembled view wrapper into the target container.
     * The container's contents were cleared at the top of this function,
     * so this is a clean replacement of the previous view.
     * ----------------------------------------------------------------- */
    containerEl.appendChild(viewWrapper);
}
