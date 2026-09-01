/* ==========================================================================
 *  song-markup.js — Per-user song highlights & notes, client (#1266 Phase 2)
 *
 *  ELI5: your own private highlighter and sticky notes on a song — highlight
 *  a line in one of five colours, jot a note under any line, or leave a note
 *  about the whole song. Nobody else ever sees them; they live against your
 *  account, not the song.
 *
 * ============================================================================
 *  WHAT THIS CONSUMES — the #1266 Phase 1 backend (commit f389dcb4, dormant
 *  until THIS module gave it a caller)
 * ============================================================================
 *  GET  /api?action=user_markup_list&songId=…   -> { markup: [ {id, songId,
 *       kind:'note'|'highlight', startLineId, endLineId, colour, body,
 *       createdAt, updatedAt}, … ] }; 401 unauthenticated.
 *  POST /api?action=user_markup_upsert  { id?, songId, kind, startLineId?,
 *       colour?, body? } -> the RE-READ stored row; 401; 404 song not
 *       visible; 409 {reason:'not_migrated'} — tblUserSongMarkup absent on
 *       this env; 422 invalid.
 *  POST /api?action=user_markup_delete  { id } -> {ok:true} always.
 *  See appWeb/public_html/includes/user_markup.php for the full contract —
 *  USER_MARKUP_KINDS / USER_MARKUP_COLOURS there are the ONE source of truth
 *  for the vocabulary (rule #35); this module never hardcodes a shadow copy,
 *  it reads window.iHymnsConfig.markupKinds / .markupColours, which
 *  index.php's $iHymnsConfig block populates straight from those same PHP
 *  constants.
 *
 * ============================================================================
 *  v1 SCOPE (design-locked, #1266 Phase 2 build spec) — do not extend beyond:
 * ============================================================================
 *   - Whole-LINE highlight (StartLineId only — no sub-line offsets; the
 *     schema's StartOffset/EndOffset/MetaJson stay dormant, per
 *     includes/user_markup.php's own doc-block).
 *   - One per-line note, one song-level note (StartLineId NULL) surfaced
 *     through this UI. The backend technically allows many rows of the same
 *     kind against one line/song; this client always upserts by `id` once it
 *     has seen one, so it never accidentally MINTS duplicates of its own.
 *   - Signed-in users only. No anonymous affordance, no entitlement check —
 *     every signed-in user gets their own private layer.
 *
 * ============================================================================
 *  WIRING — CSP / shared-cache-fragment rules (#30) and DOM-first inputs (#33)
 * ============================================================================
 *  includes/pages/song.php sends an enforcing nonce CSP with no per-request
 *  nonce available to a shared-cache fragment (rule #6), so this is a real ES
 *  module, dynamic-imported by router.js's afterPageLoad() song branch — the
 *  `home-page.js` / `song-translations.js` pattern, not a fragment-inline
 *  <script>. `initSongMarkup()` takes NO arguments: it reads the song id from
 *  `.page-song[data-song-id]` and every anchorable line from `[data-line-id]`
 *  in the fragment already on the page (rule #33).
 *
 * ============================================================================
 *  THE POPOVER LIVES ON <body>, NOT INSIDE THE FRAGMENT (rule #32)
 * ============================================================================
 *  The "add/edit markup" popover is `position: fixed`, which does not work
 *  reliably as a descendant of `.page-song` — `#page-content` carries a CSS
 *  transform for the page-transition animation (app.css, the
 *  `.reading-progress-bar` comment), and a transformed ancestor creates its
 *  own containing block for `position:fixed` descendants. So, like
 *  reading-progress-bar / the #1533 set-list bar / the #1770 host bar, the
 *  popover is appended straight to `<body>` — which means it does NOT get
 *  swept away by the router's `content.innerHTML = html` swap on the next
 *  navigation. `teardownSongMarkup()` is the explicit cleanup for that: it is
 *  dynamic-imported and called UNCONDITIONALLY at the top of every
 *  `afterPageLoad()` call (not only when leaving a song page), and as its
 *  first statement, before any early return — the exact shape rule #32
 *  documents.
 *
 * ============================================================================
 *  ACCESSIBILITY
 * ============================================================================
 *  The popover reuses js/utils/dialog-a11y.js's `openModalDialog()` — the ONE
 *  shared focus-trap / inert-background / Escape-to-close / focus-restore
 *  recipe (modularity rule #6) — rather than a fourth hand-rolled copy. Each
 *  anchorable line gets `role="button" tabindex="0"` only while markup mode
 *  is on, with Enter/Space proxied to a synthetic click (the
 *  `search-history.js` chip pattern). `#btn-my-markup` carries `aria-pressed`
 *  reflecting markup-mode state. Per-line/song notes render as native
 *  `<details>/<summary>` — free keyboard operability, no extra ARIA needed.
 *
 * ============================================================================
 *  FAILURE HANDLING — status codes, never prose (rule #35)
 * ============================================================================
 *  `apiFetchJson()` throws with `.status` set from the HTTP response (the v2
 *  envelope `{ok:false,error:{code,message,…}}` unwraps to exactly that
 *  shape — see includes/api_envelope.php). Every branch here keys off
 *  `err.status`, never off `err.message`/`err.error.message` text:
 *    401  — the session lapsed (a long-lived tab, a GC'd token): treat as
 *           signed-out, hide #btn-my-markup, drop any open popover.
 *    409  — {reason:'not_migrated'}: tblUserSongMarkup doesn't exist on this
 *           env (#1266 Phase 1's fail-open contract). Hidden SILENTLY (no
 *           console.error) — this is an EXPECTED state on an un-migrated
 *           install, not a bug — same "hide rather than leave a dead
 *           control" convention as the translation toggle only rendering
 *           when there is something to toggle.
 *    other — logged via console.error (a genuine unexpected failure) and
 *           surfaced as a short status line in the popover.
 *
 * @see appWeb/public_html/includes/pages/song.php   data-line-id emission, #btn-my-markup
 * @see appWeb/public_html/includes/user_markup.php  the vocabulary + validators this mirrors
 * @see appWeb/public_html/js/modules/song-translations.js  the sibling .lyric-line-translation rendering this parallels
 * @see appWeb/public_html/js/utils/dialog-a11y.js    openModalDialog() — the reused focus/Escape/inert recipe
 * @link https://github.com/MWBMPartners/iHymns/issues/1266
 * ========================================================================== */

import { apiFetchJson } from '../utils/api-client.js';
import { openModalDialog } from '../utils/dialog-a11y.js';
import { announce } from '../utils/announce.js';
import { escapeHtml } from '../utils/html.js';

/* =========================================================================
 * MODULE STATE — one song page's worth at a time. Reset unconditionally at
 * the top of both initSongMarkup() and teardownSongMarkup() (rule #32).
 * ========================================================================= */

let pageElRef = null;          // the current .page-song element
let currentSongId = null;      // its SongId
let markupModeOn = false;      // is the "add/edit" toggle currently on?
let lineState = new Map();     // lineId (string) -> { highlight: row|null, note: row|null }
let songNotes = [];            // note rows with StartLineId === null (song-level)

let popoverEl = null;          // the single reused popover DOM node (on <body>)
let closePopoverFn = null;     // openModalDialog()'s returned close(), or null when shut
let currentPopoverLineId = null;  // string lineId, or null for the song-level popover
let currentHighlightId = null;    // this line's highlight row id, if any
let currentNoteId = null;         // this line's (or the song's) note row id, if any
let currentActiveColour = null;   // the swatch currently shown as pressed

/* =========================================================================
 * PUBLIC API — called from router.js's afterPageLoad()
 * ========================================================================= */

/**
 * Wire the "My notes & highlights" toolbar button + fetch/render the
 * signed-in user's existing markup for the current song. DOM-first (rule
 * #33) — no arguments; reads `.page-song[data-song-id]` and `[data-line-id]`
 * out of the fragment router.js already injected.
 *
 * `#btn-my-markup` is revealed synchronously the moment a signed-in user is
 * detected (mirroring #btn-edit-song's own client-side-only reveal, just
 * above it in the toolbar — song.php) and re-hidden if the follow-up fetch
 * comes back 401/409/anything else, so a visitor never sees a control that
 * cannot work (the same "no dead control" shape as the translation toggle
 * only rendering when there is something to toggle).
 */
export async function initSongMarkup() {
    teardownSongMarkup();

    const pageEl = document.querySelector('.page-song[data-song-id]');
    const btn = document.getElementById('btn-my-markup');
    if (!pageEl || !btn) { return; }

    const songId = pageEl.dataset.songId;
    if (!songId) { return; }

    /* Pure client-side signal, no network round-trip — every signed-in user
       gets the layer, there is no entitlement to check (unlike Edit). */
    const user = window.iHymnsApp?.userAuth?.getUser?.();
    if (!user) { return; }

    btn.classList.remove('d-none');
    pageElRef = pageEl;
    currentSongId = songId;

    let rows;
    try {
        const data = await apiFetchJson(
            `/api?action=user_markup_list&songId=${encodeURIComponent(songId)}`,
            { auth: true }
        );
        rows = Array.isArray(data?.markup) ? data.markup : [];
    } catch (err) {
        btn.classList.add('d-none');
        pageElRef = null;
        currentSongId = null;
        if (err?.status !== 401 && err?.status !== 409) {
            console.error('[song-markup] failed to load markup:', err);
        }
        return;
    }

    renderAll(rows, pageEl);
    wireToggleButton(pageEl, btn);
}

/**
 * Tear down anything this module put outside the swapped `.page-song`
 * fragment: the body-level popover and the document/window listeners it
 * opened while visible. MUST run on EVERY navigation (rule #32) — router.js
 * dynamic-imports and calls this unconditionally at the top of
 * afterPageLoad(), before the page===`'song'` branch that would otherwise
 * call initSongMarkup() fresh. Safe to call any number of times, including
 * before this module has ever initialised anything (every module-level
 * variable starts null/empty).
 */
export function teardownSongMarkup() {
    if (closePopoverFn) {
        closePopoverFn();
    } else {
        detachScrollClose();
    }
    if (popoverEl && popoverEl.parentNode) {
        popoverEl.remove();
    }
    popoverEl = null;
    closePopoverFn = null;

    pageElRef = null;
    currentSongId = null;
    markupModeOn = false;
    lineState = new Map();
    songNotes = [];
    currentPopoverLineId = null;
    currentHighlightId = null;
    currentNoteId = null;
    currentActiveColour = null;
}

/* =========================================================================
 * VOCABULARY — read, never hardcoded (rule #35)
 * ========================================================================= */

/** The server's highlight-colour allow-list, or [] if the config bridge
 *  somehow didn't carry it (a rare json_encode-failure fallback path in
 *  index.php) — an empty palette just means no highlight swatches render;
 *  notes still work. */
function getMarkupColours() {
    const c = window.iHymnsConfig && window.iHymnsConfig.markupColours;
    return Array.isArray(c) ? c : [];
}

/* =========================================================================
 * FETCH + RENDER
 * ========================================================================= */

function renderAll(rows, pageEl) {
    clearRendered(pageEl);
    lineState = new Map();
    songNotes = [];

    rows.forEach((row) => {
        const key = row.startLineId != null ? String(row.startLineId) : null;
        if (row.kind === 'highlight') {
            if (key === null) { return; } /* nothing to anchor a song-level highlight onto in v1 */
            const st = lineState.get(key) || {};
            st.highlight = row;
            lineState.set(key, st);
        } else if (row.kind === 'note') {
            if (key === null) {
                songNotes.push(row);
            } else {
                const st = lineState.get(key) || {};
                st.note = row;
                lineState.set(key, st);
            }
        }
    });

    lineState.forEach((st, key) => {
        const lineEl = pageEl.querySelector(`.lyric-line[data-line-id="${key}"]`);
        if (!lineEl) { return; }
        if (st.highlight) { applyHighlightClass(lineEl, st.highlight.colour); }
        if (st.note) { insertNoteAfterLine(lineEl, key, buildNoteDetailsEl(key, st.note.body || '')); }
    });

    renderSongNotePanel();
}

/** Reconciliation pass — removes everything a previous renderAll()/reopen
 *  produced, so re-rendering after an upsert never doubles up a class or a
 *  note row (rule #35's "read back what the server stored" applied to the
 *  DOM, not just the JS state). */
function clearRendered(pageEl) {
    pageEl.querySelectorAll('.lyric-line.markup-highlighted').forEach((el) => applyHighlightClass(el, null));
    pageEl.querySelectorAll('.lyric-line-note').forEach((el) => el.remove());
    const panel = document.getElementById('song-markup-note-panel');
    if (panel) { panel.remove(); }
}

function applyHighlightClass(lineEl, colour) {
    getMarkupColours().forEach((c) => lineEl.classList.remove('markup-hl-' + c));
    lineEl.classList.toggle('markup-highlighted', !!colour);
    if (colour) { lineEl.classList.add('markup-hl-' + colour); }
}

/** Builds the `<details>` note row — a visual + structural sibling of
 *  `.lyric-line-translation` (song.php), just client-rendered since a note's
 *  body is per-user data that can never live in the shared-cache fragment. */
function buildNoteDetailsEl(lineIdStr, bodyText) {
    const details = document.createElement('details');
    details.className = 'lyric-line-note';
    details.dataset.lineNoteFor = lineIdStr;

    const summary = document.createElement('summary');
    summary.className = 'lyric-line-note-summary';
    summary.innerHTML = '<i class="fa-solid fa-note-sticky me-1" aria-hidden="true"></i>My note';

    const body = document.createElement('div');
    body.className = 'lyric-line-note-body';
    body.textContent = bodyText; /* textContent — never innerHTML for user-supplied text */

    details.append(summary, body);
    return details;
}

/** Inserts `detailsEl` right after `lineEl`, but AFTER any existing
 *  `.lyric-line-translation` rows for the same line (song.php interleaves
 *  those directly under the source line) — keeps reading order
 *  lyric → translation → my note. */
function insertNoteAfterLine(lineEl, lineIdStr, detailsEl) {
    let anchor = lineEl;
    let next = anchor.nextElementSibling;
    while (next && next.classList.contains('lyric-line-translation')
        && next.dataset.lineTranslationFor === lineIdStr) {
        anchor = next;
        next = anchor.nextElementSibling;
    }
    anchor.insertAdjacentElement('afterend', detailsEl);
}

function removeNoteEl(lineIdStr) {
    const existing = pageElRef?.querySelector(`.lyric-line-note[data-line-note-for="${lineIdStr}"]`);
    if (existing) { existing.remove(); }
}

/** Reconcile one line's highlight after a successful upsert/delete — updates
 *  `lineState` AND the DOM in one place (rule #35: the server's re-read row
 *  is the truth being applied, never an optimistic local guess). */
function setLineHighlightState(lineIdStr, row) {
    const st = lineState.get(lineIdStr) || {};
    st.highlight = row || null;
    lineState.set(lineIdStr, st);
    const lineEl = pageElRef?.querySelector(`.lyric-line[data-line-id="${lineIdStr}"]`);
    if (lineEl) { applyHighlightClass(lineEl, row ? row.colour : null); }
}

/** Same, for a per-line note. */
function setLineNoteState(lineIdStr, row) {
    const st = lineState.get(lineIdStr) || {};
    st.note = row || null;
    lineState.set(lineIdStr, st);
    removeNoteEl(lineIdStr);
    if (row) {
        const lineEl = pageElRef?.querySelector(`.lyric-line[data-line-id="${lineIdStr}"]`);
        if (lineEl) { insertNoteAfterLine(lineEl, lineIdStr, buildNoteDetailsEl(lineIdStr, row.body || '')); }
    }
}

/**
 * Renders (or removes) the song-level note panel above `.song-lyrics`. Shown
 * whenever there is at least one song-level note, OR markup mode is on (so
 * the "+ Add a note about this song" affordance is reachable). v1 exposes at
 * most ONE song-level note through this UI — the backend permits more, but
 * nothing here creates a second; editing always targets `songNotes[0]`.
 */
function renderSongNotePanel() {
    const lyricsEl = pageElRef?.querySelector('.song-lyrics');
    if (!lyricsEl) { return; }

    let panel = document.getElementById('song-markup-note-panel');
    const hasNotes = songNotes.length > 0;
    if (!hasNotes && !markupModeOn) {
        if (panel) { panel.remove(); }
        return;
    }
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'song-markup-note-panel';
        panel.className = 'song-markup-note-panel';
    }
    panel.innerHTML = '';

    songNotes.slice(0, 1).forEach((row) => {
        const details = document.createElement('details');
        details.className = 'song-markup-note-item';
        details.open = true;

        const summary = document.createElement('summary');
        summary.innerHTML = '<i class="fa-solid fa-note-sticky me-1" aria-hidden="true"></i>My note about this song';

        const body = document.createElement('div');
        body.className = 'song-markup-note-body';
        body.textContent = row.body || '';

        details.append(summary, body);

        if (markupModeOn) {
            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'btn btn-link btn-sm p-0 song-markup-edit-song-note';
            editBtn.textContent = 'Edit note';
            editBtn.addEventListener('click', (e) => {
                e.preventDefault();
                openPopover({ lineId: null, anchorEl: editBtn });
            });
            details.appendChild(editBtn);
        }

        panel.appendChild(details);
    });

    if (markupModeOn && songNotes.length === 0) {
        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn btn-sm btn-outline-secondary song-markup-add-song-note';
        addBtn.innerHTML = '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add a note about this song';
        addBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openPopover({ lineId: null, anchorEl: addBtn });
        });
        panel.appendChild(addBtn);
    }

    if (!panel.parentNode) {
        lyricsEl.insertAdjacentElement('beforebegin', panel);
    }
}

/* =========================================================================
 * MARKUP MODE — the #btn-my-markup toggle
 * ========================================================================= */

function wireToggleButton(pageEl, btn) {
    if (btn.dataset.markupWired !== '1') {
        btn.dataset.markupWired = '1';
        btn.addEventListener('click', () => {
            if (markupModeOn) { exitMarkupMode(); } else { enterMarkupMode(); }
        });
    }

    const lyricsEl = pageEl.querySelector('.song-lyrics');
    if (lyricsEl && lyricsEl.dataset.markupWired !== '1') {
        lyricsEl.dataset.markupWired = '1';
        lyricsEl.addEventListener('click', onLineClick);
        lyricsEl.addEventListener('keydown', onLineKeydown);
    }
}

function enterMarkupMode() {
    markupModeOn = true;
    document.getElementById('btn-my-markup')?.setAttribute('aria-pressed', 'true');

    const lyricsEl = pageElRef?.querySelector('.song-lyrics');
    if (lyricsEl) {
        lyricsEl.classList.add('markup-mode-active');
        lyricsEl.querySelectorAll('.lyric-line[data-line-id]').forEach((el) => {
            el.setAttribute('role', 'button');
            el.setAttribute('tabindex', '0');
        });
    }
    renderSongNotePanel();
    announce('Notes and highlights editing is on. Select a line to add a highlight or note.');
}

function exitMarkupMode() {
    markupModeOn = false;
    document.getElementById('btn-my-markup')?.setAttribute('aria-pressed', 'false');

    const lyricsEl = pageElRef?.querySelector('.song-lyrics');
    if (lyricsEl) {
        lyricsEl.classList.remove('markup-mode-active');
        lyricsEl.querySelectorAll('.lyric-line[data-line-id]').forEach((el) => {
            el.removeAttribute('role');
            el.removeAttribute('tabindex');
        });
    }
    closePopoverNow();
    renderSongNotePanel();
}

function onLineClick(e) {
    if (!markupModeOn) { return; }
    const lineEl = e.target.closest('.lyric-line[data-line-id]');
    if (!lineEl) { return; }
    openPopover({ lineId: lineEl.dataset.lineId, anchorEl: lineEl });
}

/** Enter/Space proxy to a synthetic click — same recipe as
 *  search-history.js's `role="button"` chips. */
function onLineKeydown(e) {
    if (!markupModeOn) { return; }
    if (e.key !== 'Enter' && e.key !== ' ') { return; }
    const lineEl = e.target.closest('.lyric-line[data-line-id]');
    if (!lineEl) { return; }
    e.preventDefault();
    lineEl.click();
}

/* =========================================================================
 * POPOVER — one reused DOM node, built lazily on first use
 * ========================================================================= */

function buildPopover() {
    if (popoverEl) { return popoverEl; }

    const el = document.createElement('div');
    el.id = 'song-markup-popover';
    el.className = 'song-markup-popover';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'My notes and highlights');
    el.hidden = true;

    const swatchesHtml = getMarkupColours().map((c) => (
        `<button type="button" class="song-markup-swatch markup-hl-${escapeHtml(c)}" `
        + `data-colour="${escapeHtml(c)}" aria-pressed="false" `
        + `aria-label="Highlight ${escapeHtml(c)}"></button>`
    )).join('');

    el.innerHTML = `
        <div class="song-markup-popover-backdrop"></div>
        <div class="song-markup-popover-box" tabindex="-1">
            <div class="song-markup-popover-head">
                <span class="song-markup-popover-title"></span>
                <button type="button" class="song-markup-popover-close" aria-label="Close">&times;</button>
            </div>
            <div class="song-markup-colours-section">
                <span class="song-markup-popover-label" id="song-markup-colour-label">Highlight</span>
                <div class="song-markup-swatches" role="group" aria-labelledby="song-markup-colour-label">${swatchesHtml}</div>
            </div>
            <div class="song-markup-popover-section">
                <label class="song-markup-popover-label" for="song-markup-note-text">Note</label>
                <textarea id="song-markup-note-text" class="form-control form-control-sm" rows="3" maxlength="5000"></textarea>
            </div>
            <div class="song-markup-popover-actions">
                <button type="button" class="btn btn-sm btn-outline-danger song-markup-delete-note" hidden>Delete note</button>
                <button type="button" class="btn btn-sm btn-primary song-markup-save-note">Save note</button>
            </div>
            <p class="song-markup-popover-status small text-muted mb-0" role="status" aria-live="polite"></p>
        </div>
    `;

    document.body.appendChild(el);
    popoverEl = el;

    el.querySelector('.song-markup-popover-backdrop').addEventListener('click', () => closePopoverNow());
    el.querySelector('.song-markup-popover-close').addEventListener('click', () => closePopoverNow());
    el.querySelectorAll('.song-markup-swatch').forEach((swatchBtn) => {
        swatchBtn.addEventListener('click', () => { onSwatchClick(swatchBtn.dataset.colour); });
    });
    el.querySelector('.song-markup-save-note').addEventListener('click', () => { onSaveNoteClick(); });
    el.querySelector('.song-markup-delete-note').addEventListener('click', () => { onDeleteNoteClick(); });

    return el;
}

/**
 * Open the popover for one line (`lineId` a string/number) or for the
 * song-level note (`lineId` null/undefined) — pre-filled from the already-
 * fetched `lineState` / `songNotes`, so opening never re-fetches.
 */
function openPopover({ lineId, anchorEl }) {
    if (!currentSongId) { return; }
    const el = buildPopover();
    const isLineMode = lineId !== null && lineId !== undefined;
    currentPopoverLineId = isLineMode ? String(lineId) : null;

    el.querySelector('.song-markup-colours-section').hidden = !isLineMode;

    const st = isLineMode
        ? (lineState.get(currentPopoverLineId) || {})
        : { note: songNotes[0] || null };

    currentHighlightId = st.highlight ? st.highlight.id : null;
    currentNoteId = st.note ? st.note.id : null;

    updateSwatchUI(el, st.highlight ? st.highlight.colour : null);

    const textarea = el.querySelector('#song-markup-note-text');
    textarea.value = st.note ? (st.note.body || '') : '';
    el.querySelector('.song-markup-delete-note').hidden = !st.note;
    el.querySelector('.song-markup-popover-title').textContent = isLineMode ? 'This line' : 'About this song';
    setStatus(el, '');

    el.hidden = false;
    positionPopover(el, anchorEl);

    const focusTarget = isLineMode
        ? (el.querySelector('.song-markup-swatch') || textarea)
        : textarea;

    closePopoverFn = openModalDialog(el, {
        onClose: () => {
            el.hidden = true;
            detachScrollClose();
            closePopoverFn = null;
        },
        initialFocus: focusTarget,
    });
    attachScrollClose();
}

function closePopoverNow() {
    if (closePopoverFn) { closePopoverFn(); }
}

/** Positions `.song-markup-popover-box` just under (or, if there is not
 *  enough room, just above) `anchorEl`, clamped to the viewport. Overridden
 *  entirely by a `max-width: 576px` media query, which just centres it —
 *  there is rarely room to anchor sensibly next to a line on a phone. */
function positionPopover(el, anchorEl) {
    const box = el.querySelector('.song-markup-popover-box');
    const rect = anchorEl.getBoundingClientRect();
    const margin = 8;
    const boxWidth = Math.min(320, window.innerWidth - margin * 2);
    const estimatedHeight = 260; /* rough — the box scrolls internally if the note is long */

    let left = Math.max(margin, Math.min(rect.left, window.innerWidth - boxWidth - margin));
    let top = rect.bottom + margin;
    if (top + estimatedHeight > window.innerHeight - margin) {
        top = Math.max(margin, rect.top - estimatedHeight - margin);
    }

    box.style.left = `${left}px`;
    box.style.top = `${top}px`;
    box.style.width = `${boxWidth}px`;
}

function onScrollOrResizeClose() { closePopoverNow(); }

/** A scrolled-away popover would visually detach from its line, so it just
 *  closes on scroll/resize rather than tracking position — `document`-level
 *  + capture:true because the actual scrolling element is `#main-content`,
 *  not `window` (`scroll` does not bubble, but capture-phase listeners on an
 *  ancestor still see it). */
function attachScrollClose() {
    document.addEventListener('scroll', onScrollOrResizeClose, true);
    window.addEventListener('resize', onScrollOrResizeClose);
}
function detachScrollClose() {
    document.removeEventListener('scroll', onScrollOrResizeClose, true);
    window.removeEventListener('resize', onScrollOrResizeClose);
}

function updateSwatchUI(el, activeColour) {
    el.querySelectorAll('.song-markup-swatch').forEach((swatchBtn) => {
        swatchBtn.setAttribute('aria-pressed', String(swatchBtn.dataset.colour === activeColour));
    });
    currentActiveColour = activeColour || null;
}

function setStatus(el, msg) {
    const p = el.querySelector('.song-markup-popover-status');
    if (p) { p.textContent = msg || ''; }
}

/* =========================================================================
 * NETWORK ACTIONS — literal `?action=…` at every call site (never built from
 * a variable) so the endpoint stays statically discoverable by
 * tests/php/test-orphan-inventory.php, matching every other module's own
 * `/api?action=…` call sites.
 * ========================================================================= */

function postJson(url, payload) {
    return apiFetchJson(url, {
        method: 'POST',
        auth: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
}

async function onSwatchClick(colour) {
    if (!currentPopoverLineId) { return; }
    const el = popoverEl;
    const wasActive = currentActiveColour === colour;
    setStatus(el, 'Saving…');
    try {
        if (wasActive) {
            if (currentHighlightId) {
                await postJson('/api?action=user_markup_delete', { id: currentHighlightId });
            }
            currentHighlightId = null;
            setLineHighlightState(currentPopoverLineId, null);
            updateSwatchUI(el, null);
            announce('Highlight removed.');
        } else {
            const payload = {
                songId: currentSongId,
                kind: 'highlight',
                colour,
                startLineId: Number(currentPopoverLineId),
            };
            if (currentHighlightId) { payload.id = currentHighlightId; }
            const res = await postJson('/api?action=user_markup_upsert', payload);
            const row = res && res.markup;
            currentHighlightId = row ? row.id : null;
            setLineHighlightState(currentPopoverLineId, row || null);
            updateSwatchUI(el, row ? row.colour : null);
            announce('Highlight saved.');
        }
        setStatus(el, '');
    } catch (err) {
        onMarkupActionError(err, el);
    }
}

async function onSaveNoteClick() {
    const el = popoverEl;
    const textarea = el.querySelector('#song-markup-note-text');
    const body = textarea.value.trim();
    if (!body) { return; } /* Save stays inert on empty text — use Delete for an existing note */
    setStatus(el, 'Saving…');
    try {
        const payload = { songId: currentSongId, kind: 'note', body };
        if (currentPopoverLineId !== null) { payload.startLineId = Number(currentPopoverLineId); }
        if (currentNoteId) { payload.id = currentNoteId; }
        const res = await postJson('/api?action=user_markup_upsert', payload);
        const row = res && res.markup;
        currentNoteId = row ? row.id : null;
        if (currentPopoverLineId !== null) {
            setLineNoteState(currentPopoverLineId, row || null);
        } else if (row) {
            songNotes = [row];
            renderSongNotePanel();
        }
        el.querySelector('.song-markup-delete-note').hidden = !row;
        setStatus(el, 'Note saved.');
        announce('Note saved.');
    } catch (err) {
        onMarkupActionError(err, el);
    }
}

async function onDeleteNoteClick() {
    const el = popoverEl;
    if (!currentNoteId) { return; }
    setStatus(el, 'Deleting…');
    try {
        await postJson('/api?action=user_markup_delete', { id: currentNoteId });
        if (currentPopoverLineId !== null) {
            setLineNoteState(currentPopoverLineId, null);
        } else {
            songNotes = songNotes.filter((n) => n.id !== currentNoteId);
            renderSongNotePanel();
        }
        currentNoteId = null;
        el.querySelector('#song-markup-note-text').value = '';
        el.querySelector('.song-markup-delete-note').hidden = true;
        setStatus(el, 'Note deleted.');
        announce('Note deleted.');
    } catch (err) {
        onMarkupActionError(err, el);
    }
}

/** Branch on HTTP status only (rule #35) — `err.error.message`, when shown,
 *  is DISPLAYED verbatim as the server's own explanation, never PARSED to
 *  decide what happened. */
function onMarkupActionError(err, el) {
    const status = err?.status;
    if (status === 401 || status === 409) {
        /* Session lapsed mid-edit, or the table vanished from under us
           (practically never, but the same fail-open contract as the
           initial load applies here too) — hide the whole feature rather
           than leave a popover the next click would just fail again. */
        closePopoverNow();
        hideMarkupFeature();
        return;
    }
    const message = (err && err.error && typeof err.error.message === 'string' && err.error.message)
        || 'Could not save — please try again.';
    setStatus(el, message);
    if (status !== 422 && status !== 404) {
        console.error('[song-markup] action failed:', err);
    }
}

function hideMarkupFeature() {
    exitMarkupMode();
    document.getElementById('btn-my-markup')?.classList.add('d-none');
}
