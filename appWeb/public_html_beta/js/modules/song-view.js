/**
 * iHymns — Song Detail View Module
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Renders the full lyrics display for a single song.
 * Handles the song detail view including breadcrumb navigation, metadata,
 * action buttons (favourite, print, share), song component rendering
 * (verses, choruses, refrains), and a credits section.
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

/**
 * Import DOM helpers and formatting utilities from the shared helpers module.
 * - $             : shorthand for document.querySelector
 * - createElement : creates a DOM element with attributes and children
 * - escapeHtml    : escapes HTML special characters to prevent XSS
 * - formatComponentLabel : formats a component's type/number as a label string
 * - setHashRoute  : sets the URL hash to navigate to a new route
 */
import {
    $,
    createElement,
    escapeHtml,
    formatComponentLabel,
    setHashRoute
} from '../utils/helpers.js';

/**
 * Import favourites functionality from the favorites module.
 * - isFavorite    : checks if a song ID is in the user's favourites list
 * - toggleFavorite: adds or removes a song ID from the favourites list
 */
import { isFavorite, toggleFavorite } from './favorites.js';

/* =========================================================================
 * SONG DETAIL RENDERER
 * ========================================================================= */

/**
 * renderSongDetail(song, containerEl)
 *
 * Renders the full song detail view inside the given container element.
 * The view includes: breadcrumb navigation, title, metadata bar, action
 * buttons, song components (verses/choruses/refrains), and credits.
 *
 * The container's existing content is cleared before rendering.
 *
 * @param {object}  song        - The song data object from songs.json
 * @param {Element} containerEl - The DOM element to render into
 *
 * Song object shape:
 *   {
 *     id: string,           — unique song identifier (e.g., 'CH-0003')
 *     number: number,       — song number within its songbook
 *     title: string,        — display title of the song
 *     songbook: string,     — songbook code (e.g., 'CH')
 *     songbookName: string, — full songbook name (e.g., 'Church Hymnal')
 *     writers: string,      — lyricist(s) / author(s) credit
 *     composers: string,    — composer(s) / musician(s) credit
 *     copyright: string,    — copyright notice for the song
 *     ccli: string,         — CCLI licence number
 *     hasAudio: boolean,    — whether an audio recording is available
 *     hasSheetMusic: boolean, — whether sheet music is available
 *     components: [         — ordered list of song components
 *       {
 *         type: string,     — component type ('verse', 'chorus', 'refrain', etc.)
 *         number: number|null, — component number (e.g., 1 for Verse 1, null for Chorus)
 *         lines: string[]   — array of lyric lines
 *       }
 *     ]
 *   }
 */
export function renderSongDetail(song, containerEl) {
    /* Clear any existing content in the container before rendering */
    containerEl.innerHTML = '';

    /* -----------------------------------------------------------------
     * ROOT WRAPPER
     * Create the outermost wrapper div for the song detail view.
     * Uses .song-detail for styling and .view-fade-in for the entrance
     * animation defined in styles.css.
     * ----------------------------------------------------------------- */
    const wrapper = createElement('div', {
        className: 'song-detail view-fade-in',
        role: 'article',
        'aria-label': `${song.title} — song lyrics`
    });

    /* -----------------------------------------------------------------
     * 1. BREADCRUMB NAVIGATION
     * Displays: Songbooks > <Songbook Name> > <Song Title>
     * Each segment (except the current page) is clickable, allowing
     * the user to navigate back up the hierarchy.
     * ----------------------------------------------------------------- */
    const breadcrumb = buildBreadcrumb(song);

    /* Append the breadcrumb navigation to the wrapper */
    wrapper.appendChild(breadcrumb);

    /* -----------------------------------------------------------------
     * 2. SONG TITLE
     * Display the song title as an h1 heading.
     * Uses escapeHtml to prevent XSS if the title contains special chars.
     * ----------------------------------------------------------------- */
    const titleEl = createElement('h1', { className: 'song-detail-title' }, song.title);

    /* Append the title heading to the wrapper */
    wrapper.appendChild(titleEl);

    /* -----------------------------------------------------------------
     * 3. METADATA BAR
     * Shows contextual metadata: songbook badge, song number, and
     * optionally the writers and composers.
     * ----------------------------------------------------------------- */
    const metaBar = buildMetaBar(song);

    /* Append the metadata bar to the wrapper */
    wrapper.appendChild(metaBar);

    /* -----------------------------------------------------------------
     * 4. ACTION BUTTONS
     * Provides interactive buttons for: Favourite toggle, Print, Share.
     * The share button only appears if the Web Share API is available.
     * ----------------------------------------------------------------- */
    const actionsBar = buildActionButtons(song);

    /* Append the action buttons bar to the wrapper */
    wrapper.appendChild(actionsBar);

    /* -----------------------------------------------------------------
     * 5. SONG COMPONENTS (LYRICS)
     * Each component (verse, chorus, refrain, bridge, etc.) is rendered
     * with a label and its lyric lines. If no components exist, a
     * friendly fallback message is displayed instead.
     * ----------------------------------------------------------------- */
    const lyricsSection = buildLyricsSection(song);

    /* Append the lyrics section to the wrapper */
    wrapper.appendChild(lyricsSection);

    /* -----------------------------------------------------------------
     * 6. CREDITS SECTION
     * Shows writer, composer, and copyright information if any are
     * present on the song object. Only rendered when at least one
     * credit field has a value.
     * ----------------------------------------------------------------- */
    const creditsSection = buildCreditsSection(song);

    /* Only append the credits section if it was created (i.e., credits exist) */
    if (creditsSection) {
        wrapper.appendChild(creditsSection);
    }

    /* -----------------------------------------------------------------
     * MOUNT THE VIEW
     * Append the fully constructed wrapper into the container element.
     * ----------------------------------------------------------------- */
    containerEl.appendChild(wrapper);
}

/* =========================================================================
 * PRIVATE BUILDER FUNCTIONS
 * These functions build individual sections of the song detail view.
 * They are not exported — only renderSongDetail is public.
 * ========================================================================= */

/**
 * buildBreadcrumb(song)
 *
 * Constructs the breadcrumb navigation element.
 * Format: Songbooks > <Songbook Name> > <Song Title>
 *
 * "Songbooks" links to the songbooks list view (root route).
 * "<Songbook Name>" links to the specific songbook's song list.
 * "<Song Title>" is the current page (not clickable).
 *
 * @param {object} song - The song data object
 * @returns {Element} The breadcrumb nav element
 */
function buildBreadcrumb(song) {
    /* Create the breadcrumb container as a <nav> for accessibility */
    const nav = createElement('nav', { className: 'app-breadcrumb' });

    /* --- "Songbooks" link: navigates to the root songbooks list --- */
    const songbooksLink = createElement('a', {
        /* Use a hash href for the songbooks list route */
        href: '#/',
        /* Click handler navigates via setHashRoute instead of default link behaviour */
        onClick: (e) => {
            /* Prevent the default anchor navigation */
            e.preventDefault();
            /* Navigate to the songbooks list view */
            setHashRoute('/');
        }
    }, 'Songbooks');

    /* Append the songbooks link to the nav */
    nav.appendChild(songbooksLink);

    /* --- First separator: " > " between Songbooks and Songbook Name --- */
    const separator1 = createElement('span', { className: 'separator' }, ' > ');

    /* Append the first separator */
    nav.appendChild(separator1);

    /* --- Songbook name link: navigates to the songbook's song list --- */
    const songbookLink = createElement('a', {
        /* Hash href for the specific songbook route */
        href: `#/songbook/${song.songbook}`,
        /* Click handler uses setHashRoute for SPA navigation */
        onClick: (e) => {
            /* Prevent the default anchor navigation */
            e.preventDefault();
            /* Navigate to the songbook's song list view */
            setHashRoute(`/songbook/${song.songbook}`);
        }
    }, song.songbookName);

    /* Append the songbook name link */
    nav.appendChild(songbookLink);

    /* --- Second separator: " > " between Songbook Name and Song Title --- */
    const separator2 = createElement('span', { className: 'separator' }, ' > ');

    /* Append the second separator */
    nav.appendChild(separator2);

    /* --- Current song title: displayed as plain text (not clickable) --- */
    const currentPage = createElement('span', {}, song.title);

    /* Append the current page indicator */
    nav.appendChild(currentPage);

    /* Return the fully constructed breadcrumb navigation element */
    return nav;
}

/**
 * buildMetaBar(song)
 *
 * Constructs the metadata bar showing songbook badge, song number,
 * and writer/composer credits (if available).
 *
 * @param {object} song - The song data object
 * @returns {Element} The metadata bar element
 */
function buildMetaBar(song) {
    /* Create the metadata bar container */
    const metaBar = createElement('div', { className: 'song-detail-meta' });

    /* --- Songbook badge: shows the songbook code (e.g., "CH") --- */
    const badge = createElement('span', { className: 'songbook-badge' }, song.songbook);

    /* Append the songbook badge */
    metaBar.appendChild(badge);

    /* --- Song number: displayed next to the badge --- */
    const numberEl = createElement('span', {}, `#${song.number}`);

    /* Append the song number */
    metaBar.appendChild(numberEl);

    /* --- Writers: shown only if the song has writer credits --- */
    if (song.writers) {
        /* Create a span displaying the writer(s) */
        const writersEl = createElement('span', {}, `Writers: ${song.writers}`);

        /* Append the writers element to the metadata bar */
        metaBar.appendChild(writersEl);
    }

    /* --- Composers: shown only if the song has composer credits --- */
    if (song.composers) {
        /* Create a span displaying the composer(s) */
        const composersEl = createElement('span', {}, `Composers: ${song.composers}`);

        /* Append the composers element to the metadata bar */
        metaBar.appendChild(composersEl);
    }

    /* Return the fully constructed metadata bar */
    return metaBar;
}

/**
 * buildActionButtons(song)
 *
 * Constructs the action buttons bar with Favourite, Print, and Share
 * buttons. The Share button is only included if the browser supports
 * the Web Share API (navigator.share).
 *
 * @param {object} song - The song data object
 * @returns {Element} The action buttons bar element
 */
function buildActionButtons(song) {
    /* Create the action buttons container */
    const actionsBar = createElement('div', {
        className: 'song-actions',
        role: 'group',
        'aria-label': 'Song actions'
    });

    /* -----------------------------------------------------------------
     * FAVOURITE TOGGLE BUTTON
     * Checks the current favourite state and sets the button label and
     * CSS class accordingly. Clicking toggles the state and updates
     * the button appearance.
     * ----------------------------------------------------------------- */

    /* Determine whether this song is currently favourited */
    const favorited = isFavorite(song.id);

    /* Build the CSS class string — add .active if already a favourite */
    const favClass = favorited ? 'favorite-btn active' : 'favorite-btn';

    /* Set the button label based on the current favourite state */
    const favLabel = favorited ? '★ Favourited' : '☆ Favourite';

    /* Create the favourite toggle button */
    const favBtn = createElement('button', {
        className: favClass,
        /* Accessible label for screen readers */
        'aria-label': favorited ? 'Remove from favourites' : 'Add to favourites',
        /* Pressed state for toggle button accessibility */
        'aria-pressed': favorited ? 'true' : 'false',
        /* Click handler toggles the favourite state */
        onClick: () => {
            /* Toggle the song's favourite status in storage */
            toggleFavorite(song.id);

            /* Re-check the new favourite state after toggling */
            const isNowFav = isFavorite(song.id);

            /* Update the button's CSS class to reflect the new state */
            favBtn.className = isNowFav ? 'favorite-btn active' : 'favorite-btn';

            /* Update the button text to reflect the new state */
            favBtn.textContent = isNowFav ? '★ Favourited' : '☆ Favourite';

            /* Update the aria-label for screen readers */
            favBtn.setAttribute(
                'aria-label',
                isNowFav ? 'Remove from favourites' : 'Add to favourites'
            );

            /* Update the aria-pressed state for screen readers */
            favBtn.setAttribute('aria-pressed', isNowFav ? 'true' : 'false');
        }
    }, favLabel);

    /* Append the favourite button to the actions bar */
    actionsBar.appendChild(favBtn);

    /* -----------------------------------------------------------------
     * PRINT BUTTON
     * Opens the browser's native print dialog so the user can print
     * or save the song lyrics as a PDF.
     * ----------------------------------------------------------------- */
    const printBtn = createElement('button', {
        /* Accessible label for screen readers */
        'aria-label': 'Print song',
        /* Click handler triggers the browser print dialog */
        onClick: () => {
            /* Invoke the browser's built-in print functionality */
            window.print();
        }
    }, '🖨 Print');

    /* Append the print button to the actions bar */
    actionsBar.appendChild(printBtn);

    /* -----------------------------------------------------------------
     * SHARE BUTTON (CONDITIONAL)
     * Only displayed if the browser supports the Web Share API.
     * Uses navigator.share() to invoke the native share sheet on
     * mobile or the share dialog on desktop.
     * ----------------------------------------------------------------- */
    if (navigator.share) {
        /* Create the share button */
        const shareBtn = createElement('button', {
            /* Accessible label for screen readers */
            'aria-label': 'Share song',
            /* Click handler invokes the Web Share API */
            onClick: async () => {
                try {
                    /* Call the native share API with song details */
                    await navigator.share({
                        /* Share title: the song's display title */
                        title: song.title,
                        /* Share text: a brief description including songbook and number */
                        text: `${song.title} — ${song.songbookName} #${song.number}`,
                        /* Share URL: the current page URL so the recipient can open the song */
                        url: window.location.href
                    });
                } catch (err) {
                    /* User cancelled the share or an error occurred — silently ignore */
                    /* navigator.share rejects if the user dismisses the share sheet */
                }
            }
        }, '📤 Share');

        /* Append the share button to the actions bar */
        actionsBar.appendChild(shareBtn);
    }

    /* Return the fully constructed action buttons bar */
    return actionsBar;
}

/**
 * buildLyricsSection(song)
 *
 * Constructs the lyrics section containing all song components.
 * Each component (verse, chorus, refrain, bridge, etc.) is rendered
 * with a label and its lyric lines.
 *
 * If the song has no components (empty lyrics), a friendly fallback
 * message is displayed instead.
 *
 * @param {object} song - The song data object
 * @returns {Element} The lyrics section element (a wrapper div)
 */
function buildLyricsSection(song) {
    /* Create a container div for the lyrics section */
    const section = createElement('div', {
        role: 'region',
        'aria-label': 'Song lyrics'
    });

    /* -----------------------------------------------------------------
     * EMPTY LYRICS FALLBACK
     * If the song has no components at all, display a user-friendly
     * message instead of an empty section.
     * ----------------------------------------------------------------- */
    if (!song.components || song.components.length === 0) {
        /* Create a paragraph with the fallback message */
        const emptyMsg = createElement('p', { className: 'song-component' },
            'No lyrics available for this song.'
        );

        /* Append the fallback message to the section */
        section.appendChild(emptyMsg);

        /* Return early — no components to render */
        return section;
    }

    /* -----------------------------------------------------------------
     * RENDER EACH COMPONENT
     * Iterate over every component in the song's components array
     * and build its DOM representation.
     * ----------------------------------------------------------------- */
    song.components.forEach((component) => {
        /* Determine the CSS class(es) for this component block */
        let componentClass = 'song-component';

        /* Chorus components receive an additional class for distinct styling */
        if (component.type === 'chorus') {
            componentClass += ' song-component-chorus';
        }

        /* Refrain components receive an additional class for distinct styling */
        if (component.type === 'refrain') {
            componentClass += ' song-component-refrain';
        }

        /* Create the component wrapper div with the computed class(es) */
        const componentEl = createElement('div', { className: componentClass });

        /* --- Component label (e.g., "Verse 1", "Chorus", "Refrain") --- */
        const label = createElement('div', { className: 'song-component-label' },
            /* formatComponentLabel handles capitalisation and optional numbering */
            formatComponentLabel(component)
        );

        /* Append the label to the component wrapper */
        componentEl.appendChild(label);

        /* --- Component lyrics: all lines joined with newline characters --- */
        /* white-space: pre-line in CSS will honour the \n line breaks */
        const lyricsText = component.lines.join('\n');

        /* Create the lyrics paragraph element */
        const lyricsEl = createElement('div', { className: 'song-component-lyrics' }, lyricsText);

        /* Append the lyrics to the component wrapper */
        componentEl.appendChild(lyricsEl);

        /* Append the fully constructed component to the section */
        section.appendChild(componentEl);
    });

    /* Return the lyrics section containing all rendered components */
    return section;
}

/**
 * buildCreditsSection(song)
 *
 * Constructs the credits section showing writer, composer, and
 * copyright information. Only created if at least one of these
 * fields has a value on the song object.
 *
 * @param {object} song - The song data object
 * @returns {Element|null} The credits section element, or null if no credits exist
 */
function buildCreditsSection(song) {
    /* Check whether any credit fields have values */
    const hasWriters = song.writers && song.writers.trim().length > 0;
    const hasComposers = song.composers && song.composers.trim().length > 0;
    const hasCopyright = song.copyright && song.copyright.trim().length > 0;

    /* If no credit information exists, return null (section will not be rendered) */
    if (!hasWriters && !hasComposers && !hasCopyright) {
        return null;
    }

    /* Create the credits section container */
    const creditsEl = createElement('div', { className: 'song-credits' });

    /* --- Writers credit line --- */
    if (hasWriters) {
        /* Create a paragraph for the writers credit */
        const writersLine = createElement('p', {}, `Words: ${song.writers}`);

        /* Append the writers line to the credits section */
        creditsEl.appendChild(writersLine);
    }

    /* --- Composers credit line --- */
    if (hasComposers) {
        /* Create a paragraph for the composers credit */
        const composersLine = createElement('p', {}, `Music: ${song.composers}`);

        /* Append the composers line to the credits section */
        creditsEl.appendChild(composersLine);
    }

    /* --- Copyright notice line --- */
    if (hasCopyright) {
        /* Create a paragraph for the copyright notice */
        const copyrightLine = createElement('p', {}, `© ${song.copyright}`);

        /* Append the copyright line to the credits section */
        creditsEl.appendChild(copyrightLine);
    }

    /* Return the fully constructed credits section */
    return creditsEl;
}
