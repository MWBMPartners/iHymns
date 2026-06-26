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

    /* #448 — hydrate the signed-in viewer's saved home layout (reorder +
       hide) client-side, since the home fragment is served shared-cache
       and can't carry a per-user order. No-op for logged-out visitors. */
    const grid = document.getElementById('home-section-grid');
    if (grid) {
        import('./card-layout.js')
            .then(m => m.applyCardLayout(grid))
            .catch(() => { /* module load blip — default order stands */ });
    }
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
        /* Remove the whole card-layout-item wrapper (#448), not just the
           inner section, so no empty draggable shell is left behind. */
        const sec = document.getElementById('popular-songs-section');
        (sec?.closest('.card-layout-item') || sec)?.remove();
        return;
    }

    el.innerHTML = uniqueBySongId(songs).map(s => renderPopularRow(s)).join('');
    /* These badges are injected AFTER the route render, so re-run the WCAG
       text-contrast pass on them (else they'd keep the CSS default colour). */
    window.iHymnsApp?.router?.fixBadgeContrast?.();
}

/**
 * Defensive dedupe by songId/id. The server already groups Popular Songs
 * by SongId, but a future regression there shouldn't surface duplicates
 * in the UI (#549). Keeps the first occurrence — the server orders by
 * relevance (views DESC for popular, recency DESC for history) so first-
 * seen is the right one to retain.
 *
 * @param {Array<{songId?:string,id?:string}>} songs
 * @returns {Array}
 */
function uniqueBySongId(songs) {
    const seen = new Set();
    const out = [];
    for (const s of songs) {
        const key = s?.songId || s?.id;
        if (!key || seen.has(key)) continue;
        seen.add(key);
        out.push(s);
    }
    return out;
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
    /* #1343-B — only derive the songbook abbreviation from the id when it has the
       <letters>-<digits> SongId shape. A PublicId (IHUID) is opaque with no hyphen,
       so it carries no songbook prefix; fall back to '' (the empty badge renders the
       book-glyph) rather than mis-using the whole PublicId as an abbreviation. */
    const book     = s.songbook || (id.includes('-') ? id.split('-')[0] : '') || '';
    const bookName = s.songbookName || SONGBOOK_NAMES[book] || book;
    const number   = s.number ?? '';
    const views    = s.views ?? 0;

    const viewsBadge = showViews
        ? `<span class="badge bg-secondary">${escapeHtml(String(views))}</span>`
        : '';

    /* Always render the coloured square (keeps the list aligned + identifies the
       songbook). Numbered/official songbooks show the number; collection /
       unofficial songbooks (Misc — number 0/empty) render the badge EMPTY so the
       `.song-number-badge:empty::before` CSS shows a book glyph instead of a
       meaningless "0". Both Popular Songs + Recently Viewed use this row.
       (#392 book-glyph; fixes the earlier over-suppression that hid the square.) */
    const numberBadge = `<span class="song-number-badge" data-songbook="${escapeHtml(book)}">${number ? escapeHtml(String(number)) : ''}</span>`;

    return `<a href="/song/${escapeHtml(id)}"
               data-navigate="song"
               data-song-id="${escapeHtml(id)}"
               class="list-group-item list-group-item-action song-list-item">
                ${numberBadge}
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
        /* Re-run the WCAG text-contrast pass on these async-injected badges. */
        window.iHymnsApp?.router?.fixBadgeContrast?.();
    } catch {
        /* Non-fatal — leave the section hidden. */
    }
}

/* ==================================================================
 * POPULAR THEMES (#305 → rethought #1148)
 *
 * The old version rendered EVERY song-tag as a pill — an unbounded
 * chip wall that didn't scale as the vocabulary grew. Now: a compact
 * strip of the top-N themes ranked by usage (with song counts), capped
 * so it can never become a wall, plus a "Browse all themes" affordance
 * that reveals the full set inline (the dedicated searchable /themes
 * index is the tracked follow-on). If the tag registry is empty
 * (fresh install / DB-less) the section is removed so the empty
 * heading doesn't linger.
 * ================================================================== */
const POPULAR_TAGS_LIMIT = 8;

/* Render one theme chip. With a positive count we append a pill badge
   carrying a visually-hidden " songs" suffix so a screen reader reads
   "Easter, 42 songs". `seen` collects slugs so the "Browse all" reveal
   can skip the ones already shown. */
function renderThemeChip(t, seen) {
    const slug = escapeHtml(t.slug || '');
    const name = escapeHtml(t.name || '');
    if (seen) seen.add(t.slug || '');
    const count = Number(t.useCount) || 0;
    const badge = count > 0
        ? ` <span class="badge rounded-pill text-bg-secondary ms-1">${count}<span class="visually-hidden"> songs</span></span>`
        : '';
    return `<a href="/tag/${slug}"
               data-navigate="tag"
               class="btn btn-sm btn-outline-secondary theme-chip">${name}${badge}</a>`;
}

async function loadTags() {
    const el = document.getElementById('tags-list');
    if (!el) return;

    let tags = [];
    try {
        const res  = await fetch('/api?action=popular_tags&limit=' + POPULAR_TAGS_LIMIT);
        const data = await res.json();
        tags = Array.isArray(data.tags) ? data.tags : [];
    } catch {
        /* Fall through — no tags will remove the section. */
    }

    if (!tags.length) {
        /* Remove the card-layout-item wrapper (#448) so no empty shell stays. */
        const sec = el.closest('#tags-section');
        (sec?.closest('.card-layout-item') || sec)?.remove();
        return;
    }

    const seen = new Set();
    el.innerHTML = tags.map(t => renderThemeChip(t, seen)).join('');

    /* If we filled the popular cap there are probably more themes — offer
       a one-click inline reveal of the remaining set. The popular chips
       (with counts) stay; the rest append without counts. */
    if (tags.length >= POPULAR_TAGS_LIMIT) {
        const moreBtn = document.createElement('button');
        moreBtn.type = 'button';
        moreBtn.className = 'btn btn-sm btn-link theme-show-all px-1';
        moreBtn.textContent = 'Browse all themes →';
        el.appendChild(moreBtn);

        moreBtn.addEventListener('click', async () => {
            moreBtn.disabled = true;
            let all = [];
            try {
                const res  = await fetch('/api?action=tags');
                const data = await res.json();
                all = Array.isArray(data.tags) ? data.tags : [];
            } catch {
                /* Network blip — leave the popular strip as-is. */
            }
            const extra = all.filter(t => !seen.has(t.slug || ''));
            if (extra.length) {
                moreBtn.insertAdjacentHTML(
                    'beforebegin',
                    extra.map(t => renderThemeChip(t, seen)).join('')
                );
            }
            moreBtn.remove();
        });
    }
}
