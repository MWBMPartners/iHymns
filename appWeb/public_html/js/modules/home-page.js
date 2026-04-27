/**
 * iHymns — Home Page Dynamic Sections (#303, #304, #305)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Wires up the three data-driven sections on the SPA home page —
 *   - Popular Songs (#303)            server-ranked last-30-day views
 *   - Recently Viewed (#304)          authenticated users only
 *   - Browse by Theme (#305)          tag list → /tag/<slug>
 *
 * History:
 * This code originally lived as an inline <script> at the bottom of
 * includes/pages/home.php. The SPA router loads each page via AJAX and
 * injects the returned HTML via innerHTML, and browsers intentionally
 * do not execute <script> descendants inserted that way. A replaceWith-
 * based shim in router.js was added to re-run injected inline scripts,
 * but that still left a single-point-of-failure transport path for
 * three separate page features. Moving the logic into a proper ES
 * module removes the transport dependency entirely — the router
 * imports and invokes this module directly after loading the home
 * page, so the sections work whether the shim fires or not.
 */

import { toTitleCase } from '../utils/text.js';
import { escapeHtml } from '../utils/html.js';
import { SONGBOOK_NAMES, STORAGE_HISTORY, STORAGE_AUTH_TOKEN } from '../constants.js';

/**
 * Entry point — call after the home page HTML has been injected into
 * the DOM. Idempotent: if a section's target element is missing (e.g.
 * the server already removed it, or we navigated away mid-fetch) the
 * corresponding block no-ops.
 */
export function initHomePage() {
    loadPopularSongs();
    loadRecentlyViewed();
    loadTags();
}

/* ==================================================================
 * POPULAR SONGS (#303)
 *
 * Server returns the last-30-day top-10 by view count. On DB-less
 * (JSON fallback) deployments or empty installs the server returns
 * an empty list; we then build a local approximation from the
 * viewing history in localStorage. If neither source yields songs,
 * the section is removed entirely so the page doesn't carry an
 * empty heading.
 * ================================================================== */
async function loadPopularSongs() {
    const el = document.getElementById('popular-songs-list');
    if (!el) return;

    let songs = [];
    try {
        const res  = await fetch('/api?action=popular_songs&period=month&limit=10');
        const data = await res.json();
        songs = Array.isArray(data.songs) ? data.songs : [];
    } catch {
        /* Network failure → fall through to the localStorage fallback;
           if that's also empty the section gets removed below. */
    }

    if (!songs.length) {
        songs = popularFromLocalHistory();
    }

    if (!songs.length) {
        document.getElementById('popular-songs-section')?.remove();
        return;
    }

    el.innerHTML = songs.map(renderPopularRow).join('');
}

/**
 * Build a rough local popularity list from localStorage viewing history.
 * Used when the server returns nothing (JSON fallback mode) so the
 * section still shows something on a fresh install.
 *
 * @returns {Array<{songId:string,title:string,songbook:string,number:(string|number),views:number}>}
 */
function popularFromLocalHistory() {
    try {
        const hist = JSON.parse(localStorage.getItem(STORAGE_HISTORY) || '[]');
        const counts = {};
        for (const h of hist) {
            if (!h?.id) continue;
            if (!counts[h.id]) {
                counts[h.id] = {
                    songId:   h.id,
                    title:    h.title,
                    songbook: h.songbook,
                    number:   h.number,
                    views:    0,
                };
            }
            counts[h.id].views++;
        }
        return Object.values(counts)
            .sort((a, b) => b.views - a.views)
            .slice(0, 10);
    } catch {
        return [];
    }
}

function renderPopularRow(s, opts = {}) {
    const showViews = opts.showViews !== false;
    const id       = s.songId || s.id || '';
    /* No usable title means the API didn't join (#546) or the song
       was deleted — drop the row rather than render a bare ID. */
    if (!id || !s.title) return '';
    const title    = toTitleCase(s.title);
    const book     = s.songbook || id.split('-')[0] || '';
    const bookName = s.songbookName || SONGBOOK_NAMES[book] || book;
    const number   = s.number ?? '';
    const views    = s.views ?? 0;

    const viewsBadge = showViews
        ? `<span class="badge bg-secondary">${escapeHtml(String(views))}</span>`
        : '';

    return `<a href="/song/${escapeHtml(id)}"
               data-navigate="song"
               data-song-id="${escapeHtml(id)}"
               class="list-group-item list-group-item-action song-list-item">
                <span class="song-number-badge" data-songbook="${escapeHtml(book)}">${escapeHtml(String(number))}</span>
                <div class="song-info flex-grow-1">
                    <span class="song-title">${escapeHtml(title)}</span>
                    <small class="text-muted d-block">
                        <span class="songbook-name-full">${escapeHtml(bookName)}</span>
                        <span class="songbook-name-abbr">${escapeHtml(book)}</span>
                    </small>
                </div>
                ${viewsBadge}
            </a>`;
}

/* ==================================================================
 * RECENTLY VIEWED (#304)
 *
 * Authenticated users only. Returns the latest 8 song views recorded
 * against the current user. If there's no auth token or the API
 * returns an empty list, leave the section hidden (it starts as
 * display:none in the server template).
 * ================================================================== */
async function loadRecentlyViewed() {
    const token = localStorage.getItem(STORAGE_AUTH_TOKEN);
    if (!token) return;

    const section = document.getElementById('recent-songs-section');
    const el      = document.getElementById('recent-songs-list');
    if (!section || !el) return;

    try {
        const res  = await fetch('/api?action=song_history&limit=8', {
            headers: { 'Authorization': 'Bearer ' + token },
        });
        const data = await res.json();
        const hist = Array.isArray(data.history) ? data.history : [];
        if (!hist.length) return;

        section.style.display = '';
        /* Reuse renderPopularRow so the recently-viewed and popular lists
           feel consistent (title + songbook badge + number). The 'views'
           badge is meaningless for a per-row history entry, so suppress
           it by passing showViews:false. (#546) */
        el.innerHTML = hist.map(h => renderPopularRow({
            songId:   h.songId,
            title:    h.title,
            songbook: h.songbook,
            number:   h.number,
        }, { showViews: false })).join('');
    } catch {
        /* Non-fatal — leave the section hidden. */
    }
}

/* ==================================================================
 * BROWSE BY THEME (#305)
 *
 * Lists every song-tag as a pill linking to /tag/<slug>. If the tag
 * registry is empty (fresh install / JSON fallback) the section is
 * removed so the empty heading doesn't linger.
 * ================================================================== */
async function loadTags() {
    const el = document.getElementById('tags-list');
    if (!el) return;

    let tags = [];
    try {
        const res  = await fetch('/api?action=tags');
        const data = await res.json();
        tags = Array.isArray(data.tags) ? data.tags : [];
    } catch {
        /* Fall through — no tags will remove the section. */
    }

    if (!tags.length) {
        el.closest('#tags-section')?.remove();
        return;
    }

    el.innerHTML = tags.map(t => {
        const slug = escapeHtml(t.slug || '');
        const name = escapeHtml(t.name || '');
        return `<a href="/tag/${slug}"
                   data-navigate="tag"
                   class="btn btn-sm btn-outline-secondary">${name}</a>`;
    }).join('');
}
