/**
 * search-history-sync-row.js - the Settings row "Keep my recent searches in my account"
 * ============================================================================
 * PR 2b review fix 3 (epic #2180, #2177). Pure of the page: it takes the document, the app and the storage, so a test can drive it.
 *
 * ELI5
 * ----
 * The row is a switch. It must only be there while the account sync is really working, because flipping it is a request
 * to the account ("keep my searches" / "delete them"). Two things went wrong before: the switch could change this device
 * even though the request could not be queued, so the device and the account disagreed; and the row was only shown or hidden
 * when the account section was redrawn, so it could stay on screen after the sync was switched off.
 *
 * Detailed
 * --------
 *  - refresh(): the row is shown only while `app.profileSync.isActive()`; the checkbox shows what is stored here.
 *  - The change handler waits for `setSearchHistorySync()`. When that answers false (nothing could be queued, so
 *    nothing was changed) the checkbox is put back to what is stored and the person is told. The stored value is never
 *    changed behind the account's back.
 *  - The row refreshes itself on EVT_PROFILE_ACTIVE_CHANGED (sign-in, sign-out, the person's own settings-sync switch, the
 *    site answering "off"). The listener removes itself once the row has left the page (the settings page is redrawn on each visit).
 *
 * @module modules/search-history-sync-row
 */

import { EVT_PROFILE_ACTIVE_CHANGED, STORAGE_SYNC_SEARCH_HISTORY } from '../constants.js';

/**
 * Show or hide the row, and set the checkbox from what is stored on this device.
 *
 * @param {Document} doc
 * @param {{profileSync?: {isActive?: () => boolean}}} app
 * @param {Storage} storage
 */
export function refreshSearchHistorySyncRow(doc, app, storage) {
    const toggle = doc.getElementById('setting-sync-search-history');
    const wrap = doc.getElementById('setting-sync-search-history-wrap');
    if (!toggle || !wrap) return;
    wrap.classList.toggle('d-none', !app.profileSync?.isActive?.());
    let on = false;
    try { on = storage.getItem(STORAGE_SYNC_SEARCH_HISTORY) === 'true'; } catch (_e) { on = false; }
    toggle.checked = on;
}

/**
 * Wire the row up (safe to call every time the account section is drawn).
 *
 * @param {Document} doc
 * @param {object} app the app (needs `profileSync`, and optionally `showToast`)
 * @param {Storage} storage
 */
export function bindSearchHistorySyncRow(doc, app, storage) {
    const toggle = doc.getElementById('setting-sync-search-history');
    const wrap = doc.getElementById('setting-sync-search-history-wrap');
    if (!toggle || !wrap) return;
    refreshSearchHistorySyncRow(doc, app, storage);
    if (toggle.dataset.bound) return;
    toggle.dataset.bound = '1';
    toggle.addEventListener('change', async () => {
        const wanted = toggle.checked;
        let ok = false;
        try { ok = (await app.profileSync?.setSearchHistorySync?.(wanted)) === true; } catch (_e) { ok = false; }
        if (!ok) {
            refreshSearchHistorySyncRow(doc, app, storage);   /* put the box back to what is really stored */
            try { app.showToast?.('That could not be changed just now, so it was left as it was. Please try again in a moment.', 'warning', 5000); } catch (_e) { /* no toast */ }
        }
    });
    const onActive = () => {
        if (!wrap.isConnected) { doc.removeEventListener(EVT_PROFILE_ACTIVE_CHANGED, onActive); return; }
        refreshSearchHistorySyncRow(doc, app, storage);
    };
    doc.addEventListener(EVT_PROFILE_ACTIVE_CHANGED, onActive);
}
