/**
 * iHymns — In-App Help Module
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Renders the in-app help documentation as an interactive accordion.
 * Provides searchable help content covering: searching, songbooks,
 * favourites, installation, accessibility, keyboard shortcuts, and about.
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

/* Import DOM helpers from the shared utilities module */
import { $, createElement, setHashRoute } from '../utils/helpers.js';

/* =========================================================================
 * HELP CONTENT DATA
 * Each section is an object with id, icon, title, and HTML content.
 * This structure makes it easy to add/edit help topics.
 * ========================================================================= */

const HELP_SECTIONS = [
    {
        id: 'help-search',
        icon: 'bi-search',
        title: 'Searching Songs',
        content: `
            <p>Use the search bar at the top to find songs by:</p>
            <ul>
                <li><strong>Title</strong> — type the song title or part of it</li>
                <li><strong>Lyrics</strong> — search for a line or phrase from any verse or chorus</li>
                <li><strong>Song number</strong> — type the number (e.g., "150" or "CH 3")</li>
                <li><strong>Writer/Composer</strong> — search by author name (e.g., "Wesley")</li>
                <li><strong>Songbook</strong> — type the songbook name or abbreviation (CH, SDAH, MP, JP, CP)</li>
            </ul>
            <h6 class="mt-3">Search Tips</h6>
            <ul>
                <li>Searches are <strong>fuzzy</strong> — you don't need exact spelling</li>
                <li>Use the <strong>123</strong> button to switch to numpad mode for quick number lookup</li>
                <li>Results are ranked by relevance — best matches appear first</li>
                <li>Use fewer words for broader results, more words to narrow down</li>
            </ul>
        `
    },
    {
        id: 'help-songbooks',
        icon: 'bi-book',
        title: 'Songbooks',
        content: `
            <p>iHymns includes songs from <strong>5 songbooks</strong>:</p>
            <table class="table table-sm">
                <thead><tr><th>Songbook</th><th>Abbrev</th><th>Songs</th><th>Audio</th></tr></thead>
                <tbody>
                    <tr><td>The Church Hymnal</td><td>CH</td><td>702</td><td>—</td></tr>
                    <tr><td>SDA Hymnal</td><td>SDAH</td><td>695</td><td>—</td></tr>
                    <tr><td>Mission Praise</td><td>MP</td><td>1,355</td><td>MIDI</td></tr>
                    <tr><td>Junior Praise</td><td>JP</td><td>617</td><td>MIDI</td></tr>
                    <tr><td>Carol Praise</td><td>CP</td><td>243</td><td>MIDI</td></tr>
                </tbody>
            </table>
            <p>Click on a songbook card on the home page to browse its songs by number.</p>
        `
    },
    {
        id: 'help-favorites',
        icon: 'bi-star',
        title: 'Favourites',
        content: `
            <p>Save songs you love for quick access:</p>
            <ol>
                <li>Open any song's lyrics view</li>
                <li>Click the <i class="bi bi-star"></i> <strong>star icon</strong> to save it</li>
                <li>Access all saved songs via <strong>Favourites</strong> in the navigation bar</li>
            </ol>
            <p><strong>Note:</strong> Favourites are stored locally on your device (browser storage).
               They do not sync between devices. Clearing your browser data will remove them.</p>
        `
    },
    {
        id: 'help-install',
        icon: 'bi-download',
        title: 'Installing the App',
        content: `
            <p>iHymns can be installed as an app for offline use:</p>
            <h6>Chrome / Edge (Desktop & Android)</h6>
            <ul>
                <li>Click the install banner when it appears, or</li>
                <li>Click the install icon <i class="bi bi-box-arrow-in-down"></i> in the address bar</li>
            </ul>
            <h6>Safari (iOS / iPadOS)</h6>
            <ol>
                <li>Tap the <strong>Share</strong> button <i class="bi bi-box-arrow-up"></i></li>
                <li>Select <strong>"Add to Home Screen"</strong></li>
            </ol>
            <p>Once installed, iHymns works <strong>offline</strong> — perfect for worship when there's no internet!</p>
        `
    },
    {
        id: 'help-themes',
        icon: 'bi-palette',
        title: 'Themes & Accessibility',
        content: `
            <h6>Dark Mode</h6>
            <p>Click the <i class="bi bi-moon-fill"></i> moon icon in the navbar to switch to dark mode.
               Click the <i class="bi bi-sun-fill"></i> sun icon to switch back. Your preference is saved automatically.</p>
            <h6>Colourblind-Friendly Mode</h6>
            <p>Click the <i class="bi bi-eye"></i> eye icon in the navbar to enable a colour palette
               designed for users with colour vision deficiency. This mode uses the CVD-safe Wong (2011) palette
               and works in both light and dark modes.</p>
            <h6>Reduced Motion</h6>
            <p>If your device is set to "reduce motion" in system settings, iHymns will automatically
               disable all animations and transitions.</p>
        `
    },
    {
        id: 'help-keyboard',
        icon: 'bi-keyboard',
        title: 'Keyboard Shortcuts',
        content: `
            <table class="table table-sm">
                <thead><tr><th>Action</th><th>Shortcut</th></tr></thead>
                <tbody>
                    <tr><td>Focus search bar</td><td><kbd>/</kbd> or <kbd>Ctrl+K</kbd></td></tr>
                    <tr><td>Navigate results</td><td><kbd>Tab</kbd> / <kbd>Shift+Tab</kbd></td></tr>
                    <tr><td>Open selected item</td><td><kbd>Enter</kbd> or <kbd>Space</kbd></td></tr>
                    <tr><td>Go back</td><td><kbd>Alt+←</kbd> (browser back)</td></tr>
                    <tr><td>Print song</td><td><kbd>Ctrl+P</kbd></td></tr>
                </tbody>
            </table>
        `
    },
    {
        id: 'help-sharing',
        icon: 'bi-share',
        title: 'Sharing Songs',
        content: `
            <p>You can share song lyrics in several ways:</p>
            <ul>
                <li><strong>Share button</strong> — on supported devices, use the share button on any song
                    to send it via messaging apps, email, AirDrop, etc.</li>
                <li><strong>Direct link</strong> — copy the URL from your browser's address bar.
                    Each song has a unique deep link (e.g., <code>ihymns.app/#/song/CH-0003</code>)</li>
                <li><strong>Print</strong> — use the print button or <kbd>Ctrl+P</kbd> for a clean printed copy</li>
            </ul>
        `
    },
    {
        id: 'help-about',
        icon: 'bi-info-circle',
        title: 'About iHymns',
        content: `
            <p><strong>iHymns</strong> is a Christian lyrics application providing searchable
               hymn and worship song lyrics from multiple songbooks, designed to enhance worship.</p>
            <p>Currently featuring <strong>5 songbooks</strong> with over <strong>3,600 songs</strong>.</p>
            <h6>Platforms</h6>
            <ul>
                <li>Web (PWA) — <a href="https://ihymns.app" target="_blank" rel="noopener">ihymns.app</a></li>
                <li>Apple iOS / iPadOS / tvOS — coming soon</li>
                <li>Android — coming soon</li>
            </ul>
            <h6>Feedback & Issues</h6>
            <p>Report bugs or request features on
               <a href="https://github.com/MWBMPartners/iHymns/issues" target="_blank" rel="noopener">GitHub Issues</a>.</p>
            <p class="text-muted small mt-3">© MWBM Partners Ltd. All rights reserved.</p>
        `
    }
];

/* =========================================================================
 * RENDER FUNCTION
 * ========================================================================= */

/**
 * renderHelpView(containerEl)
 *
 * Renders the full in-app help documentation into the given container.
 * Uses Bootstrap 5 accordion for collapsible sections.
 *
 * @param {Element} containerEl - The DOM container to render into
 */
export function renderHelpView(containerEl) {
    /* Clear the container */
    containerEl.innerHTML = '';

    /* Create the wrapper div with max-width for readability */
    const wrapper = createElement('div', {
        className: 'view-fade-in',
        style: 'max-width: 750px; margin: 0 auto; padding: 1rem;'
    });

    /* Back button: returns to the songbook grid */
    const backBtn = createElement('button', {
        className: 'back-btn',
        'aria-label': 'Back to Songbooks',
        onClick: () => setHashRoute('/')
    });
    backBtn.innerHTML = '<i class="bi bi-arrow-left"></i> Back to Songbooks';
    wrapper.appendChild(backBtn);

    /* Page heading */
    const heading = createElement('h2', { className: 'mb-4' });
    heading.innerHTML = '<i class="bi bi-question-circle me-2"></i>Help & Documentation';
    wrapper.appendChild(heading);

    /* Build the Bootstrap accordion */
    const accordion = createElement('div', {
        className: 'accordion',
        id: 'help-accordion'
    });

    /* Create an accordion item for each help section */
    HELP_SECTIONS.forEach((section, index) => {
        /* The first section is expanded by default */
        const isFirst = index === 0;

        /* Build the accordion item HTML */
        const item = createElement('div', { className: 'accordion-item' });

        /* Accordion header with toggle button */
        const header = createElement('h3', { className: 'accordion-header' });
        const button = createElement('button', {
            className: `accordion-button${isFirst ? '' : ' collapsed'}`,
            type: 'button',
            'data-bs-toggle': 'collapse',
            'data-bs-target': `#${section.id}`,
            'aria-expanded': String(isFirst),
            'aria-controls': section.id
        });
        button.innerHTML = `<i class="${section.icon} me-2"></i> ${section.title}`;
        header.appendChild(button);
        item.appendChild(header);

        /* Accordion collapse body */
        const collapseDiv = createElement('div', {
            id: section.id,
            className: `accordion-collapse collapse${isFirst ? ' show' : ''}`,
            'data-bs-parent': '#help-accordion'
        });
        const body = createElement('div', {
            className: 'accordion-body',
            innerHTML: section.content
        });
        collapseDiv.appendChild(body);
        item.appendChild(collapseDiv);

        accordion.appendChild(item);
    });

    wrapper.appendChild(accordion);
    containerEl.appendChild(wrapper);
}
