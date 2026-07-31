/**
 * iHymns — "Your requests" (#1671 F2; server #280)
 *
 * ELI5: the list of song requests you have sent us, and what has happened to
 * each one.
 *
 * ============================================================================
 * WHY THIS FILE EXISTS
 * ============================================================================
 *
 * `?action=my_song_requests` has worked since #280 and had ZERO callers — web,
 * Apple and Android alike. Meanwhile `song_request_submit` has always linked a
 * submission to the signed-in user (api.php: `$authUser = getAuthenticatedUser();
 * $userId = $authUser ? (int)$authUser['Id'] : null;`), and a curator triaging
 * on /manage/requests moves `Status` through pending → reviewed → added /
 * declined and can fill in `ResolvedSongId`. All of that was WRITTEN and never
 * READ. The user's side of the conversation simply did not exist.
 *
 * This module is the smallest possible fix for that: one authenticated GET, one
 * list, one link to the song when a request is fulfilled.
 *
 * ============================================================================
 * WHAT THE SERVER ACTUALLY RETURNS — build to this, not to the issue text
 * ============================================================================
 *
 *   { requests: [ { id, title, songbook, songNumber, language, details,
 *                   status, resolvedSongId, createdAt, updatedAt }, … ] }
 *
 * ordered `CreatedAt DESC`, scoped `WHERE UserId = ?`, 401 when unauthenticated.
 * Note what is NOT there: `adminNotes` (deliberately internal), `requestType`,
 * and the correction-request fields (`SongId`/`FieldName`/`OriginalValue`/
 * `ProposedValue`, #1090 N2). A correction request submitted by some future
 * client will appear here as a bare title — worth knowing, not worth faking.
 *
 * ============================================================================
 * STANDING CONSTRAINTS
 * ============================================================================
 *  - rule #30 — no inline script in the fragment; this is a real ES module
 *    imported from `router.js`'s `afterPageLoad()`, reading `[data-my-requests]`
 *    out of the DOM. `request` is a $_cacheablePages fragment, so it can never
 *    carry the document's nonce and an inline script would be refused SILENTLY.
 *  - rule #31 — `apiFetch`, never bare `fetch()`.
 *  - rule #20 — `tblSongRequests.Status` is a VARCHAR with an app-level
 *    vocabulary, precisely so it can grow without an ALTER. `statusPresentation()`
 *    therefore has a DEFAULT arm: an unrecognised status renders as itself
 *    rather than vanishing or throwing.
 *  - rule #35 — failure kinds are told apart by HTTP status.
 *
 * @see appWeb/public_html/api.php — case 'my_song_requests'
 * @see appWeb/public_html/manage/requests.php — the curator side
 */

import { escapeHtml } from '../utils/html.js';
import { apiFetch } from '../utils/api-client.js';
import { serverTimestampToMs } from '../utils/datetime.js';
import { EVT_AUTH_CHANGED } from '../constants.js';

const ENDPOINT = '/api?action=my_song_requests';

/* =========================================================================
 * PURE HELPERS — called by tests/test-my-song-requests-ui.js, which asserts
 * the values they RETURN. Nothing in that test reads this file's source.
 * ========================================================================= */

/**
 * Badge class + label for a request status.
 *
 * The vocabulary is `pending | reviewed | added | declined` (schema.sql's own
 * comment on `tblSongRequests.Status`). It is a VARCHAR rather than an ENUM
 * *because it is expected to grow* (rule #20), so an unknown value must render
 * gracefully — dropping it would leave a row with no state at all, which is
 * strictly worse than showing a word the UI has not been taught to style.
 *
 * @param {string} status
 * @returns {{cls: string, label: string, known: boolean}}
 */
export function statusPresentation(status) {
    const key = typeof status === 'string' ? status.trim().toLowerCase() : '';
    switch (key) {
        case 'pending':
            return { cls: 'text-bg-secondary', label: 'Pending', known: true };
        case 'reviewed':
            return { cls: 'text-bg-info', label: 'Reviewed', known: true };
        case 'added':
            return { cls: 'text-bg-success', label: 'Added', known: true };
        case 'declined':
            return { cls: 'text-bg-warning', label: 'Declined', known: true };
        default:
            return {
                cls: 'text-bg-light',
                /* Show the server's own word, capitalised, rather than
                   inventing one. '' becomes 'Unknown' so the badge is never
                   an empty box. */
                label: key === '' ? 'Unknown' : key.charAt(0).toUpperCase() + key.slice(1),
                known: false,
            };
    }
}

/**
 * A one-line explanation of what a status MEANS, for the muted second line.
 *
 * Deliberately separate from the badge: the badge is a label, this is the
 * answer to "so what happens now?", which is the entire reason this screen
 * exists.
 *
 * @param {string} status
 * @param {string|null} resolvedSongId
 * @returns {string} Plain text; '' when there is nothing useful to add.
 */
export function statusExplanation(status, resolvedSongId) {
    const key = typeof status === 'string' ? status.trim().toLowerCase() : '';
    if (key === 'pending')  return 'Waiting to be looked at.';
    if (key === 'reviewed') return 'A curator has seen this and is working on it.';
    if (key === 'added')    {
        return typeof resolvedSongId === 'string' && resolvedSongId !== ''
            ? 'This song is now in the collection.'
            : 'This request has been added to the collection.';
    }
    if (key === 'declined') return 'We were not able to add this one.';
    return '';
}

/**
 * The muted context line: songbook, number, and when it was submitted.
 *
 * @param {object} request
 * @returns {string} Plain text (the caller escapes it); '' when empty.
 */
export function requestSubtitle(request) {
    const parts = [];
    const book   = typeof request?.songbook === 'string' ? request.songbook.trim() : '';
    const number = typeof request?.songNumber === 'string' ? request.songNumber.trim() : '';
    if (book !== '')   parts.push(book);
    if (number !== '') parts.push('no. ' + number);
    const when = formatDate(request?.createdAt);
    if (when !== '')   parts.push('submitted ' + when);
    return parts.join(' · ');
}

/**
 * Format a server timestamp as a short local date, or '' if unreadable.
 *
 * The parse goes through the SHARED `serverTimestampToMs()` rather than a copy
 * of devices.js's three lines — two private copies of a date normaliser drift
 * into two different notions of "when", which is the duplication the
 * modularity rule exists to stop.
 *
 * An unreadable value yields '' — never the string "Invalid Date", which is
 * exactly what a naive `new Date(x).toLocaleDateString()` puts on screen.
 *
 * @param {string|null|undefined} value
 * @returns {string}
 */
export function formatDate(value) {
    const ms = serverTimestampToMs(value);
    if (ms === null) return '';
    try {
        return new Date(ms).toLocaleDateString(undefined, {
            year: 'numeric', month: 'short', day: 'numeric',
        });
    } catch (_e) {
        return '';
    }
}

/**
 * What to tell the user about a failed load, chosen BY HTTP STATUS (rule #35).
 *
 * @param {number} status
 * @returns {string}
 */
export function loadMessageForStatus(status) {
    switch (status) {
        case 401: return 'Sign in to see the requests you have sent.';
        case 429: return 'Too many requests just now. Please try again shortly.';
        case 503: return 'The server is briefly unavailable. Please try again in a moment.';
        default:  return 'Could not load your requests (HTTP ' + status + '). Please try again.';
    }
}

/* =========================================================================
 * DOM WIRING
 * ========================================================================= */

let boundSection = null;
/** See devices.js — the router boots this module and THEN dispatches
 *  EVT_AUTH_CHANGED, so without a flag every visit fires two identical GETs. */
let loading = false;
/** The live EVT_AUTH_CHANGED subscription. `document` outlives every SPA
 *  navigation, so a re-mount must REPLACE this rather than stack a second one —
 *  see the longer note in devices.js's bootDevicesCard(). */
let authListener = null;

/**
 * Entry point — called by `router.js` after the /request fragment is injected.
 *
 * Idempotent: `afterPageLoad()` runs on every navigation, and the section is
 * absent on every other route, in which case this is a cheap no-op.
 */
export function initMySongRequests() {
    const section = document.querySelector('[data-my-requests]');
    if (!section) { boundSection = null; return; }

    if (boundSection !== section) {
        boundSection = section;
        if (authListener) { document.removeEventListener(EVT_AUTH_CHANGED, authListener); }
        authListener = () => syncSection();
        document.addEventListener(EVT_AUTH_CHANGED, authListener);
        /* Delegated so a re-render cannot leak listeners. This one hangs off the
           section, which the router discards with the fragment. */
        section.addEventListener('click', (e) => {
            const btn = e.target instanceof Element ? e.target.closest('[data-my-requests-signin]') : null;
            if (btn) {
                /* The auth modal is owned by user-auth.js and reached through
                   the app singleton rather than an import: importing it here
                   would drag the whole auth module onto a page that mostly does
                   not need it, and `window.iHymnsApp` is how every other
                   fragment-level module reaches shared state. */
                window.iHymnsApp?.userAuth?.showAuthModal?.();
            }
        });
    }

    syncSection();
}

/**
 * Re-fetch and re-render the list.
 *
 * Exported so `request-a-song.js` can call it the moment a submission
 * succeeds — a user who has just sent a request and does not see it appear
 * reasonably concludes the list is broken. A direct call rather than a custom
 * DOM event on purpose: an event name is a second thing to keep in sync
 * (rule #35 / #1581), and there is exactly one caller.
 */
export function refreshMySongRequests() {
    if (document.querySelector('[data-my-requests]')) { syncSection(); }
}

/** Show/hide the section and load it when signed in. */
function syncSection() {
    const section = document.querySelector('[data-my-requests]');
    if (!section) { boundSection = null; return; }

    const loggedIn = !!window.iHymnsApp?.userAuth?.isLoggedIn?.();
    section.classList.remove('d-none');

    if (!loggedIn) {
        renderSignInPrompt();
        return;
    }
    loadRequests();
}

/**
 * The signed-out state.
 *
 * Deliberately NOT hidden. A user who submitted a request while signed out has
 * nothing to see here either way, but showing the prompt is what tells them
 * that signing in is how they track one — hiding the section entirely teaches
 * them nothing.
 */
function renderSignInPrompt() {
    const list = document.getElementById('my-requests-list');
    if (!list) return;
    clearMessage();
    list.innerHTML =
        '<p class="text-muted small mb-2">'
        + 'Sign in to follow the requests you send — you will see when a curator '
        + 'reviews one, and a link to the song once it is added.'
        + '</p>'
        + '<button type="button" class="btn btn-outline-primary btn-sm" data-my-requests-signin>'
        + '<i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i>Sign in</button>';
}

/** Fetch + render. */
async function loadRequests() {
    const list = document.getElementById('my-requests-list');
    if (!list) return;
    if (loading) return;
    loading = true;

    try {
        const res = await apiFetch(ENDPOINT, { auth: true });
        if (res.status === 401) {
            /* The token expired while the page sat open. Offer the sign-in
               route rather than an error — the user has done nothing wrong. */
            renderSignInPrompt();
            return;
        }
        if (!res.ok) {
            showMessage(loadMessageForStatus(res.status), 'warning');
            return;
        }
        const data = await res.json();
        const requests = Array.isArray(data?.requests) ? data.requests : [];
        clearMessage();
        renderRequests(requests);
    } catch (_e) {
        showMessage('Could not load your requests. Check your connection and try again.', 'warning');
    } finally {
        loading = false;
    }
}

/**
 * @param {Array<object>} requests
 */
function renderRequests(requests) {
    const list = document.getElementById('my-requests-list');
    if (!list) return;

    if (requests.length === 0) {
        list.innerHTML =
            '<p class="text-muted small mb-0">'
            + 'You have not sent any requests yet. Use the form above and it will appear here.'
            + '</p>';
        return;
    }

    list.innerHTML = '<ul class="list-group list-group-flush mb-0">'
        + requests.map(renderRow).join('')
        + '</ul>';
}

/**
 * One request row.
 *
 * Every interpolated value is escaped: `title`, `songbook`, `details` are all
 * free text the submitter typed.
 *
 * @param {object} request
 * @returns {string} HTML
 */
function renderRow(request) {
    const badge   = statusPresentation(request?.status);
    const title   = typeof request?.title === 'string' && request.title.trim() !== ''
        ? request.title.trim()
        : 'Untitled request';
    const subtitle = requestSubtitle(request);
    const explain  = statusExplanation(request?.status, request?.resolvedSongId);
    const details  = typeof request?.details === 'string' ? request.details.trim() : '';
    const songId   = typeof request?.resolvedSongId === 'string' ? request.resolvedSongId.trim() : '';
    const ref      = Number.isFinite(Number(request?.id)) ? Number(request.id) : null;

    /* `data-navigate` is what makes this an in-app route change rather than a
       full page load — the same contract every other internal link on the site
       uses (router.js binds it globally). */
    const songLink = songId !== ''
        ? '<a href="/song/' + encodeURIComponent(songId) + '" data-navigate="song"'
          + ' class="song-meta-link d-inline-block mt-1">View the song</a>'
        : '';

    return '<li class="list-group-item px-0">'
        + '<div class="d-flex align-items-start gap-3">'
        + '<div class="flex-grow-1">'
        + '<strong>' + escapeHtml(title) + '</strong>'
        + (ref !== null
            ? ' <span class="text-muted small">#' + ref + '</span>'
            : '')
        + (subtitle !== ''
            ? '<small class="text-muted d-block">' + escapeHtml(subtitle) + '</small>'
            : '')
        + (explain !== ''
            ? '<small class="text-muted d-block">' + escapeHtml(explain) + '</small>'
            : '')
        + (details !== ''
            ? '<small class="d-block mt-1">' + escapeHtml(details) + '</small>'
            : '')
        + songLink
        + '</div>'
        + '<span class="badge ' + badge.cls + ' flex-shrink-0">' + escapeHtml(badge.label) + '</span>'
        + '</div>'
        + '</li>';
}

/**
 * @param {string} text
 * @param {'warning'|'danger'} kind
 */
function showMessage(text, kind) {
    const el = document.getElementById('my-requests-msg');
    if (!el) return;
    el.className = 'alert alert-' + kind + ' py-2 small';
    el.textContent = text;
}

function clearMessage() {
    const el = document.getElementById('my-requests-msg');
    if (!el) return;
    el.className = 'alert d-none py-2 small';
    el.textContent = '';
}
