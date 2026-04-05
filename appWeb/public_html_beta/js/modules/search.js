/**
 * iHymns — Search Module
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Provides fuzzy search functionality for the iHymns song library using
 * Fuse.js. Handles search index creation, query execution, result
 * rendering, and search input binding with debounced keystroke handling.
 *
 * Fuse.js is loaded globally via a <script> tag and accessed through
 * window.Fuse — it is NOT imported as an ES module.
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

/**
 * Import shared utility functions from the helpers module.
 * - $             : DOM querySelector shorthand
 * - createElement : Creates DOM elements with attributes and children
 * - escapeHtml    : Escapes HTML entities to prevent XSS
 * - setHashRoute  : Sets the URL hash for SPA navigation
 * - getSongLyricsPreview : Extracts a short lyrics preview from a song
 * - getAllLyricsText     : Concatenates all lyrics into a single string
 * - debounce      : Delays function execution until input settles
 */
import {
    $,
    createElement,
    escapeHtml,
    setHashRoute,
    getSongLyricsPreview,
    getAllLyricsText,
    debounce
} from '../utils/helpers.js';

/* =========================================================================
 * MODULE-LEVEL STATE
 * ========================================================================= */

/**
 * fuseInstance
 *
 * Holds the Fuse.js search index instance once initialised via initSearch().
 * Remains null until initSearch() is called with valid song data.
 *
 * @type {Fuse|null}
 */
let fuseInstance = null;

/**
 * previousViewHtml
 *
 * Stores the innerHTML of the results container BEFORE search results are
 * rendered. This allows the view to be restored when the user clears the
 * search input, returning them to whatever content was previously displayed
 * (e.g., a songbook listing or the home view).
 *
 * @type {string|null}
 */
let previousViewHtml = null;

/**
 * isSearchActive
 *
 * Tracks whether search results are currently displayed in the container.
 * Used to determine whether clearing the input should restore the previous
 * view or simply do nothing.
 *
 * @type {boolean}
 */
let isSearchActive = false;

/* =========================================================================
 * SEARCH INDEX INITIALISATION
 * ========================================================================= */

/**
 * initSearch(songData)
 *
 * Initialises the Fuse.js fuzzy search index with the full song library.
 * Pre-computes a 'lyrics' text field on each song by concatenating all
 * component lines into a single searchable string.
 *
 * Fuse.js configuration:
 *   - keys are weighted so title matches rank highest (0.4), followed by
 *     lyrics (0.3), then songbook name, writers, and number (0.1 each).
 *   - threshold of 0.35 allows for typos and partial matches while keeping
 *     results reasonably precise.
 *   - includeScore is enabled so results can be ranked by relevance.
 *   - includeMatches is enabled so matched text can be highlighted.
 *
 * @param {object} songData - The full song data object containing a .songs array.
 *                            Each song has { id, number, title, songbook,
 *                            songbookName, writers, composers, hasAudio,
 *                            hasSheetMusic, components }.
 */
export function initSearch(songData) {
    /* Guard: ensure songData and its songs array exist */
    if (!songData || !Array.isArray(songData.songs)) {
        console.warn('[iHymns Search] initSearch called without valid songData.');
        return;
    }

    /* Guard: ensure Fuse.js is available as a global (loaded via <script> tag) */
    if (typeof window.Fuse === 'undefined') {
        console.error('[iHymns Search] Fuse.js is not loaded. Ensure the Fuse.js script tag is included before this module.');
        return;
    }

    /**
     * Pre-compute a 'lyrics' field on each song object.
     * This concatenates all lines from all components into one string,
     * making the entire lyric body searchable as a single Fuse.js key.
     */
    songData.songs.forEach(song => {
        /* Use the helper to join all component lines into one string */
        song.lyrics = getAllLyricsText(song);
    });

    /**
     * Define the Fuse.js configuration options.
     * See: https://www.fusejs.io/api/options.html
     */
    const fuseOptions = {
        /**
         * keys: the fields to search within each song object, with weights.
         * Higher weight = more influence on the relevance score.
         *   - title (0.4)        : song title is the most important match
         *   - lyrics (0.3)       : full lyric text for content search
         *   - songbookName (0.1) : allows filtering by songbook name
         *   - writersJoined (0.1): combined writer names for author search
         *   - numberStr (0.1)    : song number as string for numeric lookup
         */
        keys: [
            { name: 'title',        weight: 0.4 },
            { name: 'lyrics',       weight: 0.3 },
            { name: 'songbookName', weight: 0.1 },
            { name: 'writersJoined', weight: 0.1 },
            { name: 'numberStr',    weight: 0.1 }
        ],

        /**
         * threshold: controls how fuzzy the matching is.
         * 0.0 = exact match only; 1.0 = match anything.
         * 0.35 is a balanced value that tolerates minor typos while
         * still filtering out clearly irrelevant results.
         */
        threshold: 0.35,

        /**
         * includeScore: when true, each result includes a 'score' property
         * indicating how close the match is (lower = better).
         */
        includeScore: true,

        /**
         * includeMatches: when true, each result includes a 'matches' array
         * detailing which keys matched and at what character indices.
         * Used for highlighting matched text in the results.
         */
        includeMatches: true,

        /**
         * ignoreLocation: when true, the position of the match within the
         * string does not affect the score. Important for lyrics search
         * where the matching text could appear anywhere in a long string.
         */
        ignoreLocation: true,

        /**
         * minMatchCharLength: minimum number of characters that must match.
         * Setting to 2 avoids meaningless single-character matches.
         */
        minMatchCharLength: 2
    };

    /**
     * Pre-compute additional searchable fields on each song.
     * These computed fields simplify the Fuse.js key configuration.
     */
    songData.songs.forEach(song => {
        /**
         * writersJoined: join the writers array into a comma-separated string.
         * This makes it searchable as a single key in the Fuse index.
         * Falls back to an empty string if no writers are defined.
         */
        song.writersJoined = Array.isArray(song.writers)
            ? song.writers.join(', ')
            : '';

        /**
         * numberStr: convert the song number to a string so Fuse.js can
         * match it as text. This allows users to type "23" in the search
         * box and find song number 23.
         */
        song.numberStr = String(song.number);
    });

    /* Create the Fuse.js instance with the prepared song data and options */
    fuseInstance = new window.Fuse(songData.songs, fuseOptions);

    /* Log confirmation for debugging */
    console.log(`[iHymns Search] Search index initialised with ${songData.songs.length} songs.`);
}

/* =========================================================================
 * SEARCH EXECUTION
 * ========================================================================= */

/**
 * performSearch(query)
 *
 * Executes a fuzzy search against the Fuse.js index and returns the
 * raw results array. Each result object contains:
 *   - item:    the matched song object
 *   - score:   relevance score (lower is better)
 *   - matches: array of match details (key, indices, value)
 *
 * Returns an empty array if the index is not initialised or the query
 * is empty/whitespace-only.
 *
 * @param {string} query - The search query string entered by the user.
 * @returns {Array} An array of Fuse.js result objects, sorted by relevance.
 */
export function performSearch(query) {
    /* Guard: return empty if the Fuse instance has not been initialised */
    if (!fuseInstance) {
        console.warn('[iHymns Search] performSearch called before initSearch.');
        return [];
    }

    /* Trim whitespace from the query */
    const trimmedQuery = query.trim();

    /* Guard: return empty if the query is blank */
    if (trimmedQuery.length === 0) {
        return [];
    }

    /* Execute the Fuse.js search and return the results array */
    const results = fuseInstance.search(trimmedQuery);

    return results;
}

/* =========================================================================
 * RESULT RENDERING
 * ========================================================================= */

/**
 * highlightMatch(text, query)
 *
 * Highlights occurrences of the query within the given text by wrapping
 * them in <mark> tags with the .search-highlight class.
 * The match is case-insensitive. All HTML in the text is escaped first
 * to prevent XSS, then the highlighting is applied.
 *
 * @param {string} text  - The raw text to highlight within.
 * @param {string} query - The search query to highlight.
 * @returns {string} HTML string with highlighted matches.
 */
function highlightMatch(text, query) {
    /* If either text or query is empty, return escaped text as-is */
    if (!text || !query) {
        return escapeHtml(text || '');
    }

    /* Escape the text to prevent XSS injection */
    const safeText = escapeHtml(text);

    /* Escape special regex characters in the query to avoid regex errors */
    const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    /* Build a case-insensitive, global regex to find all query occurrences */
    const regex = new RegExp(`(${escapedQuery})`, 'gi');

    /* Replace each match with a highlighted <mark> span */
    return safeText.replace(regex, '<mark class="search-highlight">$1</mark>');
}

/**
 * renderSearchResults(results, query, containerEl)
 *
 * Renders the search results into the specified container element.
 * Each result is displayed as a clickable list item showing:
 *   - A songbook badge (abbreviation)
 *   - The song number
 *   - The song title (with query highlighted)
 *   - A short lyrics preview (with query highlighted)
 *   - Writer names
 *   - Icons for audio/sheet music availability
 *
 * If no results match the query, a friendly "no results" message is shown.
 *
 * The previous container HTML is saved before rendering so it can be
 * restored when the search is cleared.
 *
 * @param {Array}   results     - The Fuse.js result objects from performSearch().
 * @param {string}  query       - The original search query (for highlighting).
 * @param {Element} containerEl - The DOM element to render results into.
 */
export function renderSearchResults(results, query, containerEl) {
    /* Guard: ensure the container element exists */
    if (!containerEl) {
        console.warn('[iHymns Search] renderSearchResults called without a valid container element.');
        return;
    }

    /**
     * Save the current container HTML before overwriting it with search results.
     * Only save if search is not already active (avoid overwriting the saved
     * view with search results from a previous query).
     */
    if (!isSearchActive) {
        previousViewHtml = containerEl.innerHTML;
        isSearchActive = true;
    }

    /* Clear the container to prepare for new content */
    containerEl.innerHTML = '';

    /**
     * Create the results count header.
     * Shows "X results found" or "No results found" depending on count.
     */
    const resultCount = results.length;

    /* Build the count text based on number of results */
    const countText = resultCount === 0
        ? 'No results found'
        : `${resultCount} result${resultCount !== 1 ? 's' : ''} found`;

    /* Create the count element and append it to the container */
    const countEl = createElement('div', { className: 'search-results-count' }, countText);
    containerEl.appendChild(countEl);

    /**
     * If there are no results, display a friendly "no results" message
     * encouraging the user to try different search terms.
     */
    if (resultCount === 0) {
        /* Create the no-results message container */
        const noResultsEl = createElement('div', {
            className: 'search-no-results'
        });

        /* Set the friendly message with the escaped query */
        noResultsEl.innerHTML = `
            <p>No songs matched "<strong>${escapeHtml(query)}</strong>".</p>
            <p>Try different keywords, check spelling, or search by song number.</p>
        `;

        /* Append the message to the container */
        containerEl.appendChild(noResultsEl);

        /* Apply the fade-in animation class for a smooth appearance */
        containerEl.classList.add('view-fade-in');

        return;
    }

    /**
     * Create a list container to hold all the result items.
     * Uses a <div> rather than <ul> for styling flexibility.
     */
    const listEl = createElement('div', { className: 'search-results-list' });

    /**
     * Iterate over each search result and create a clickable list item.
     * Results are already sorted by relevance (best match first) from Fuse.js.
     */
    results.forEach(result => {
        /* Extract the song object from the Fuse result wrapper */
        const song = result.item;

        /**
         * Create the outer list item element.
         * It acts as a clickable card that navigates to the song detail view.
         */
        const itemEl = createElement('div', {
            className: 'song-list-item',
            role: 'button',
            tabindex: '0',
            'aria-label': `${song.title} — ${song.songbookName} #${song.number}`
        });

        /**
         * Attach click handler to navigate to the song detail route.
         * Uses the song's unique ID in the hash route.
         */
        itemEl.addEventListener('click', () => {
            setHashRoute(`/song/${song.id}`);
        });

        /**
         * Attach keyboard handler so the item is accessible via Enter/Space.
         * This ensures keyboard-only users can activate the result.
         */
        itemEl.addEventListener('keydown', (e) => {
            /* Trigger navigation on Enter or Space key */
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault(); /* Prevent page scroll on Space */
                setHashRoute(`/song/${song.id}`);
            }
        });

        /* ----- Songbook Badge ----- */

        /**
         * Create a small badge showing the songbook abbreviation.
         * This visually identifies which songbook the song belongs to.
         */
        const badgeEl = createElement('span', {
            className: 'songbook-badge',
            dataset: { songbook: song.songbook }
        }, song.songbook);

        /* ----- Song Number ----- */

        /**
         * Display the song number prominently.
         * Highlighted if the query matches the number.
         */
        const numberEl = createElement('span', {
            className: 'song-list-number',
            innerHTML: highlightMatch(String(song.number), query)
        });

        /* ----- Song Title ----- */

        /**
         * Display the song title with query matches highlighted.
         * Uses innerHTML because highlightMatch returns HTML with <mark> tags.
         */
        const titleEl = createElement('span', {
            className: 'song-list-title',
            innerHTML: highlightMatch(song.title, query)
        });

        /* ----- Lyrics Preview ----- */

        /**
         * Show a short preview of the song lyrics (first 2 lines of verse 1).
         * Highlighted if the query matches within the preview text.
         */
        const previewText = getSongLyricsPreview(song);
        const previewEl = createElement('span', {
            className: 'song-list-preview',
            innerHTML: highlightMatch(previewText, query)
        });

        /* ----- Writers ----- */

        /**
         * Display the song writers as a metadata line.
         * Joins the writers array with commas, or shows nothing if empty.
         */
        const writersText = Array.isArray(song.writers) && song.writers.length > 0
            ? song.writers.join(', ')
            : '';

        const metaEl = createElement('span', {
            className: 'song-list-meta',
            innerHTML: writersText ? highlightMatch(writersText, query) : ''
        });

        /* ----- Feature Icons (audio / sheet music) ----- */

        /**
         * Build an icons container showing availability indicators.
         * Only adds icons if the song has audio or sheet music.
         */
        const iconsEl = createElement('span', { className: 'song-list-icons' });

        /* Add an audio icon if the song has an audio recording */
        if (song.hasAudio) {
            const audioIcon = createElement('span', {
                className: 'icon-audio',
                title: 'Audio available',
                'aria-label': 'Audio available'
            }, '\u266B'); /* ♫ musical note character */
            iconsEl.appendChild(audioIcon);
        }

        /* Add a sheet music icon if the song has sheet music */
        if (song.hasSheetMusic) {
            const sheetIcon = createElement('span', {
                className: 'icon-sheet-music',
                title: 'Sheet music available',
                'aria-label': 'Sheet music available'
            }, '\u{1D11E}'); /* 𝄞 treble clef character */
            iconsEl.appendChild(sheetIcon);
        }

        /* ----- Assemble the List Item ----- */

        /* Append all child elements to the list item in display order */
        itemEl.appendChild(badgeEl);
        itemEl.appendChild(numberEl);
        itemEl.appendChild(titleEl);
        itemEl.appendChild(previewEl);
        itemEl.appendChild(metaEl);
        itemEl.appendChild(iconsEl);

        /* Append the completed list item to the results list */
        listEl.appendChild(itemEl);
    });

    /* Append the full results list to the container */
    containerEl.appendChild(listEl);

    /* Apply the fade-in animation class for a smooth appearance */
    containerEl.classList.add('view-fade-in');
}

/* =========================================================================
 * SEARCH INPUT BINDING
 * ========================================================================= */

/**
 * bindSearchInput(containerEl)
 *
 * Binds event listeners to the search input and its associated controls.
 * Handles:
 *   1. Input events (debounced at 250ms) — triggers search and renders results.
 *   2. Clear button (#search-clear) — clears the input and restores the
 *      previous view content.
 *   3. Form submit prevention — prevents page reload if the input is inside
 *      a <form> element.
 *
 * The search input is expected to have the ID "search-input" and the
 * clear button the ID "search-clear".
 *
 * @param {Element} containerEl - The DOM element where search results are rendered.
 *                                This is the same container passed to renderSearchResults().
 */
export function bindSearchInput(containerEl) {
    /* Locate the search input element by its ID */
    const searchInput = $('#search-input');

    /* Locate the clear button element by its ID */
    const clearButton = $('#search-clear');

    /* Guard: ensure the search input exists in the DOM */
    if (!searchInput) {
        console.warn('[iHymns Search] bindSearchInput: #search-input not found in the DOM.');
        return;
    }

    /* Guard: ensure the container element is valid */
    if (!containerEl) {
        console.warn('[iHymns Search] bindSearchInput: containerEl is not a valid element.');
        return;
    }

    /**
     * handleSearchInput()
     *
     * Handles the search input's value change. Reads the current input value,
     * performs the search, and renders results. If the input is empty, restores
     * the previous view. Also toggles the visibility of the clear button.
     */
    function handleSearchInput() {
        /* Read the current value of the search input */
        const query = searchInput.value.trim();

        /**
         * If the query is non-empty, perform the search and show results.
         * Also make the clear button visible so the user can reset.
         */
        if (query.length > 0) {
            /* Show the clear button (it may be hidden when input is empty) */
            if (clearButton) {
                clearButton.style.display = ''; /* Restore default display */
            }

            /* Execute the fuzzy search */
            const results = performSearch(query);

            /* Render the results into the container */
            renderSearchResults(results, query, containerEl);
        } else {
            /**
             * The input is empty — hide the clear button and restore the
             * previous view that was displayed before the search started.
             */
            restorePreviousView(containerEl);
        }
    }

    /**
     * Create a debounced version of the input handler.
     * This waits 250ms after the last keystroke before executing the search,
     * preventing excessive search operations during rapid typing.
     */
    const debouncedHandler = debounce(handleSearchInput, 250);

    /**
     * Bind the 'input' event on the search field.
     * Fires on every value change (typing, pasting, cutting, etc.).
     * The debounced handler ensures the search only runs after the user
     * pauses typing for 250ms.
     */
    searchInput.addEventListener('input', debouncedHandler);

    /**
     * Bind the clear button click event.
     * Clears the search input value, hides the clear button, and restores
     * the container to its previous content.
     */
    if (clearButton) {
        clearButton.addEventListener('click', () => {
            /* Clear the input field value */
            searchInput.value = '';

            /* Hide the clear button since the input is now empty */
            clearButton.style.display = 'none';

            /* Restore the container to its pre-search content */
            restorePreviousView(containerEl);

            /* Return focus to the search input for continued use */
            searchInput.focus();
        });

        /**
         * Initially hide the clear button.
         * It will be shown when the user starts typing.
         */
        clearButton.style.display = 'none';
    }

    /**
     * Prevent default form submission if the search input is inside a <form>.
     * This stops the page from reloading when the user presses Enter.
     * We find the closest ancestor <form> and intercept its submit event.
     */
    const formEl = searchInput.closest('form');
    if (formEl) {
        formEl.addEventListener('submit', (e) => {
            /* Prevent the default form submission (page reload) */
            e.preventDefault();
        });
    }
}

/* =========================================================================
 * INTERNAL HELPERS
 * ========================================================================= */

/**
 * restorePreviousView(containerEl)
 *
 * Restores the container element's content to what it was before the
 * search results were rendered. This is called when the user clears
 * the search input or deletes all their query text.
 *
 * If no previous view was saved (e.g., search was never activated),
 * the container is simply cleared.
 *
 * @param {Element} containerEl - The DOM element to restore.
 */
function restorePreviousView(containerEl) {
    /* Only restore if search is currently active */
    if (isSearchActive && previousViewHtml !== null) {
        /* Replace the container content with the saved HTML */
        containerEl.innerHTML = previousViewHtml;

        /* Apply the fade-in animation for a smooth transition back */
        containerEl.classList.add('view-fade-in');
    }

    /* Reset search state flags */
    isSearchActive = false;

    /* Hide the clear button since search is no longer active */
    const clearButton = $('#search-clear');
    if (clearButton) {
        clearButton.style.display = 'none';
    }
}
