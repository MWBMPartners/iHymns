/**
 * iHymns — Utility Helpers
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Shared utility functions used across multiple modules.
 * Provides DOM helpers, text formatting, and common operations.
 */

/* =========================================================================
 * DOM HELPERS
 * ========================================================================= */

/**
 * $(selector)
 *
 * Shorthand for document.querySelector(). Returns the first element
 * matching the CSS selector, or null if no match is found.
 *
 * @param {string} selector - A CSS selector string (e.g., '#my-id', '.my-class')
 * @returns {Element|null} The first matching DOM element, or null
 */
export function $(selector) {
    return document.querySelector(selector);
}

/**
 * $$(selector)
 *
 * Shorthand for document.querySelectorAll(). Returns all elements
 * matching the CSS selector as a NodeList.
 *
 * @param {string} selector - A CSS selector string
 * @returns {NodeList} A list of all matching DOM elements
 */
export function $$(selector) {
    return document.querySelectorAll(selector);
}

/**
 * createElement(tag, attributes, children)
 *
 * Creates a DOM element with the given tag, attributes, and child content.
 * Simplifies dynamic element creation compared to multiple DOM API calls.
 *
 * @param {string} tag          - The HTML tag name (e.g., 'div', 'span', 'button')
 * @param {object} attributes   - Key-value pairs of attributes (e.g., { class: 'my-class', id: 'my-id' })
 * @param {string|Element|Array} children - Text content, a child element, or an array of children
 * @returns {Element} The created DOM element
 */
export function createElement(tag, attributes = {}, children = null) {
    /* Create the element with the specified tag name */
    const el = document.createElement(tag);

    /* Set each attribute on the element */
    for (const [key, value] of Object.entries(attributes)) {
        if (key === 'className') {
            /* 'className' maps to the element's class attribute */
            el.className = value;
        } else if (key === 'dataset') {
            /* 'dataset' sets data-* attributes from an object */
            for (const [dataKey, dataValue] of Object.entries(value)) {
                el.dataset[dataKey] = dataValue;
            }
        } else if (key.startsWith('on') && typeof value === 'function') {
            /* Event handlers: 'onClick' → addEventListener('click', ...) */
            const eventName = key.slice(2).toLowerCase();
            el.addEventListener(eventName, value);
        } else if (key === 'innerHTML') {
            /* Allow setting innerHTML directly (use with caution — sanitise inputs!) */
            el.innerHTML = value;
        } else {
            /* Standard attribute: set via setAttribute */
            el.setAttribute(key, value);
        }
    }

    /* Append children to the element */
    if (children !== null) {
        if (Array.isArray(children)) {
            /* If children is an array, append each child */
            children.forEach(child => {
                if (typeof child === 'string') {
                    el.appendChild(document.createTextNode(child));
                } else if (child instanceof Node) {
                    el.appendChild(child);
                }
            });
        } else if (typeof children === 'string') {
            /* If children is a string, set as text content */
            el.textContent = children;
        } else if (children instanceof Node) {
            /* If children is a single DOM node, append it */
            el.appendChild(children);
        }
    }

    /* Return the fully constructed element */
    return el;
}

/* =========================================================================
 * TEXT FORMATTING
 * ========================================================================= */

/**
 * escapeHtml(text)
 *
 * Escapes HTML special characters to prevent XSS attacks when
 * inserting user-provided or data-sourced text into the DOM via innerHTML.
 *
 * @param {string} text - The raw text to escape
 * @returns {string} The HTML-safe escaped text
 */
export function escapeHtml(text) {
    /* Create a temporary div element */
    const div = document.createElement('div');

    /* Set the text as textContent (which auto-escapes HTML entities) */
    div.textContent = text;

    /* Return the escaped innerHTML */
    return div.innerHTML;
}

/**
 * formatComponentLabel(component)
 *
 * Formats a song component's label for display.
 * E.g., { type: 'verse', number: 1 } → "Verse 1"
 *       { type: 'refrain', number: null } → "Refrain"
 *       { type: 'chorus', number: null } → "Chorus"
 *
 * @param {object} component - A song component object with 'type' and 'number'
 * @returns {string} The formatted label string
 */
export function formatComponentLabel(component) {
    /* Capitalise the first letter of the component type */
    const typeName = component.type.charAt(0).toUpperCase() + component.type.slice(1);

    /* If the component has a number (e.g., verse 1), append it */
    if (component.number !== null && component.number !== undefined) {
        return `${typeName} ${component.number}`;
    }

    /* Otherwise, return just the type name (e.g., "Refrain") */
    return typeName;
}

/**
 * truncateText(text, maxLength)
 *
 * Truncates text to a maximum length, appending "..." if truncated.
 * Useful for preview text in search results and song lists.
 *
 * @param {string} text      - The text to truncate
 * @param {number} maxLength - Maximum character length (default: 100)
 * @returns {string} The truncated text
 */
export function truncateText(text, maxLength = 100) {
    /* If the text is shorter than the max, return it as-is */
    if (text.length <= maxLength) {
        return text;
    }

    /* Truncate and append ellipsis */
    return text.slice(0, maxLength).trimEnd() + '…';
}

/**
 * getSongLyricsPreview(song)
 *
 * Extracts a short preview of the song lyrics (first few lines of verse 1).
 * Used in search results and song list items.
 *
 * @param {object} song - A song object from songs.json
 * @returns {string} A short lyrics preview (first 2 lines of verse 1)
 */
export function getSongLyricsPreview(song) {
    /* Find the first component (usually verse 1) */
    if (song.components.length === 0) {
        return '';
    }

    /* Get the lines from the first component */
    const firstComponent = song.components[0];

    /* Join the first 2 lines for a compact preview */
    const previewLines = firstComponent.lines.slice(0, 2);

    /* Return joined with " / " separator for inline display */
    return previewLines.join(' / ');
}

/**
 * getAllLyricsText(song)
 *
 * Concatenates all lyrics from all components into a single string.
 * Used by the search index to enable full-text lyric search.
 *
 * @param {object} song - A song object from songs.json
 * @returns {string} All lyrics joined as a single string
 */
export function getAllLyricsText(song) {
    /* Map each component to its lines, flatten, and join with spaces */
    return song.components
        .map(component => component.lines.join(' '))
        .join(' ');
}

/* =========================================================================
 * URL / ROUTING HELPERS
 * ========================================================================= */

/**
 * getHashRoute()
 *
 * Parses the current URL hash into a route object.
 * Uses hash-based routing for SPA navigation (e.g., #/songbook/CH, #/song/CH-0003).
 *
 * @returns {object} A route object with 'path' (string) and 'params' (array)
 */
export function getHashRoute() {
    /* Get the hash portion of the URL, removing the leading '#' */
    const hash = window.location.hash.slice(1) || '/';

    /* Split by '/' and filter out empty segments */
    const segments = hash.split('/').filter(Boolean);

    /* Return a structured route object */
    return {
        /* The full path string (e.g., '/songbook/CH') */
        path: '/' + segments.join('/'),
        /* The path segments as an array (e.g., ['songbook', 'CH']) */
        segments: segments
    };
}

/**
 * setHashRoute(path)
 *
 * Sets the URL hash to navigate to a new route.
 * This triggers the hashchange event, which the router listens to.
 *
 * @param {string} path - The route path (e.g., '/songbook/CH', '/song/CH-0003')
 */
export function setHashRoute(path) {
    /* Set the hash, ensuring it starts with '#' */
    window.location.hash = path.startsWith('#') ? path : '#' + path;
}

/* =========================================================================
 * DEBOUNCE
 * ========================================================================= */

/**
 * debounce(fn, delay)
 *
 * Creates a debounced version of a function that delays execution
 * until 'delay' milliseconds after the last invocation.
 * Essential for search input: prevents firing a search on every keystroke.
 *
 * @param {Function} fn    - The function to debounce
 * @param {number}   delay - Delay in milliseconds (default: 250ms)
 * @returns {Function} The debounced function
 */
export function debounce(fn, delay = 250) {
    /* Variable to hold the timeout ID */
    let timeoutId;

    /* Return a wrapper function that resets the timer on each call */
    return function (...args) {
        /* Clear any previously set timeout */
        clearTimeout(timeoutId);

        /* Set a new timeout that will call the function after the delay */
        timeoutId = setTimeout(() => fn.apply(this, args), delay);
    };
}
