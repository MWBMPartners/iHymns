/* ============================================================================
 * iHymns — "You're leaving iHymns" outbound-link interstitial (#1347)
 *
 * Shows a ONE-PER-SESSION disclaimer the first time a visitor follows a link
 * that leaves the iHymns origin (an external website iHymns isn't responsible
 * for). After they acknowledge once, every outbound link for the rest of the
 * session opens straight through — the flag lives in sessionStorage, so a new
 * tab/session shows it again.
 *
 * Why a delegated capture-phase listener: the catalogue is a single-page app, so
 * outbound links (song/person/work "find elsewhere" buttons, CCLI SongSelect,
 * etc.) are injected after load. One document-level listener covers them all,
 * present and future, without each renderer having to opt in.
 *
 * Out of scope (let the browser handle): same-origin links, non-http(s) schemes
 * (mailto:/tel:/javascript:), explicit downloads, and modified clicks
 * (cmd/ctrl/shift/middle) where the user has already chosen how to open it.
 * A link can opt out with data-no-interstitial.
 *
 * Docs: https://developer.mozilla.org/en-US/docs/Web/API/EventTarget/addEventListener
 *       (capture phase) · https://developer.mozilla.org/en-US/docs/Web/API/Window/sessionStorage
 * ========================================================================== */

/* sessionStorage key — presence means "already acknowledged this session". */
const ACK_KEY = 'ihymns_extlink_disclaimer_seen';

/* True once the visitor has acknowledged (or if storage is unavailable, so a
   privacy-mode browser that throws on sessionStorage never traps every click). */
function alreadyAcknowledged() {
    try { return sessionStorage.getItem(ACK_KEY) === '1'; }
    catch (_e) { return true; }
}
function markAcknowledged() {
    try { sessionStorage.setItem(ACK_KEY, '1'); }
    catch (_e) { /* storage blocked — degrade to per-click (still functional) */ }
}

/* Is this an OUTBOUND http(s) link we should gate? */
function isExternalHttpLink(anchor) {
    if (!anchor || anchor.dataset.noInterstitial !== undefined) { return false; }
    /* An explicit download is the browser's job — don't hijack it (#1347 review). */
    if (anchor.hasAttribute('download')) { return false; }
    const href = anchor.getAttribute('href') || '';
    if (!href || href.startsWith('#')) { return false; }
    let url;
    try { url = new URL(anchor.href, window.location.href); }
    catch (_e) { return false; }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') { return false; } /* mailto/tel/blob */
    return url.host !== window.location.host;
}

/* Build the modal DOM once and reuse it. Returns { open(host, onProceed) }. */
function buildModal() {
    const overlay = document.createElement('div');
    overlay.className = 'extlink-interstitial-overlay';
    /* Self-contained styling so it works even if a stylesheet is slow/offline. */
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2000;display:none;'
        + 'align-items:center;justify-content:center;background:rgba(0,0,0,.55);'
        + 'padding:1rem;';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'extlink-interstitial-title');
    overlay.hidden = true;

    /* Bootstrap card classes for theme-aware colours; inline fallbacks for layout. */
    const dialog = document.createElement('div');
    dialog.className = 'card shadow-lg';
    dialog.style.cssText = 'max-width:30rem;width:100%;';
    dialog.innerHTML =
        '<div class="card-body">'
      +   '<h2 class="h5 card-title d-flex align-items-center gap-2" id="extlink-interstitial-title">'
      +     '<i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>You’re leaving iHymns'
      +   '</h2>'
      +   '<p class="card-text mb-1">You’re about to open an external website at '
      +     '<strong class="extlink-interstitial-host"></strong>.</p>'
      +   '<p class="card-text small text-muted">iHymns isn’t responsible for external sites or their content. '
      +     'This notice won’t show again this session.</p>'
      +   '<div class="d-flex justify-content-end gap-2 mt-3">'
      +     '<button type="button" class="btn btn-outline-secondary btn-sm extlink-interstitial-cancel">Cancel</button>'
      +     '<button type="button" class="btn btn-primary btn-sm extlink-interstitial-proceed">'
      +       'Continue <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></button>'
      +   '</div>'
      + '</div>';
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    const hostEl    = dialog.querySelector('.extlink-interstitial-host');
    const proceedEl = dialog.querySelector('.extlink-interstitial-proceed');
    const cancelEl  = dialog.querySelector('.extlink-interstitial-cancel');

    let pendingProceed = null;
    let lastFocus = null; /* element to restore focus to on close (the trigger) */
    function close() {
        overlay.style.display = 'none';
        overlay.hidden = true;
        pendingProceed = null;
        /* a11y (#1347 review): return focus to whatever opened the dialog. */
        if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
        lastFocus = null;
    }
    function open(host, onProceed) {
        hostEl.textContent = host;
        pendingProceed = onProceed;
        lastFocus = document.activeElement;
        overlay.hidden = false;
        overlay.style.display = 'flex';
        proceedEl.focus();
    }

    proceedEl.addEventListener('click', () => { const go = pendingProceed; close(); if (go) { go(); } });
    cancelEl.addEventListener('click', close);
    /* Click on the backdrop (not the dialog) cancels. */
    overlay.addEventListener('click', (e) => { if (e.target === overlay) { close(); } });
    /* ESC cancels; Tab/Shift-Tab stay trapped between the two buttons (#1347 review). */
    overlay.addEventListener('keydown', (e) => {
        if (overlay.style.display !== 'flex') { return; }
        if (e.key === 'Escape') { close(); return; }
        if (e.key !== 'Tab') { return; }
        if (e.shiftKey && document.activeElement === cancelEl) { e.preventDefault(); proceedEl.focus(); }
        else if (!e.shiftKey && document.activeElement === proceedEl) { e.preventDefault(); cancelEl.focus(); }
    });

    return { open };
}

/**
 * bootExternalLinkInterstitial() — install the one-per-session outbound-link
 * disclaimer. Idempotent (a second call is a no-op).
 */
export function bootExternalLinkInterstitial() {
    if (window.__ihymnsExtLinkInterstitial) { return; }
    window.__ihymnsExtLinkInterstitial = true;

    let modal = null; /* lazily built on first gated click — no DOM cost otherwise */

    document.addEventListener('click', (e) => {
        /* Respect modified / non-primary clicks — the user chose how to open it. */
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        const anchor = e.target.closest && e.target.closest('a[href]');
        if (!anchor || !isExternalHttpLink(anchor)) { return; }
        if (alreadyAcknowledged()) { return; } /* seen this session — let it through */

        e.preventDefault();
        const dest = anchor.href;
        let host;
        try { host = new URL(dest, window.location.href).host; } catch (_e) { host = dest; }
        const openInNewTab = (anchor.target === '_blank');

        if (!modal) { modal = buildModal(); }
        modal.open(host, () => {
            markAcknowledged();
            /* Honour the link's own target; default to a new tab with noopener.
               If the popup is blocked despite the user gesture, fall back to
               same-tab navigation so the click is never silently lost (#1347 review). */
            if (openInNewTab) {
                const w = window.open(dest, '_blank', 'noopener');
                if (!w) { window.location.href = dest; }
            } else {
                window.location.href = dest;
            }
        });
    }, true); /* capture phase: run before SPA navigation handlers on the same link */
}
