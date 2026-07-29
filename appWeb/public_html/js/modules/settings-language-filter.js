/**
 * Settings page — Language preferences section (#736)
 *
 * Populates the [data-settings-language-filter] container on the
 * /settings page with a checkbox group of every language in the
 * catalogue, wired to the same persistence layer as the home-grid
 * filter (localStorage + /api?action=user_preferred_languages_save).
 *
 * Reuses bootSongbookLanguageFilter()'s persistence + sync by
 * delegating to the same filter wrapper markup pattern. The
 * settings page just renders the picker into a different host
 * element so the user can adjust their preference from a
 * non-grid context.
 */

/* #1581 — shared event-name constant; see songbook-language-filter.js for
   the case-sensitivity bug this registry exists to prevent. */
/* #1031 — shared localStorage-key constant; see constants.js's own note on
   STORAGE_LANGUAGE_FILTER for why this raw key name predates the ihymns_
   prefix convention and must not be renamed. */
import { EVT_LANGUAGE_FILTER_CHANGED, STORAGE_LANGUAGE_FILTER } from '../constants.js';
import { apiFetch } from '../utils/api-client.js';

const STORAGE_KEY = STORAGE_LANGUAGE_FILTER;

function loadSavedSubtags() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed)
            ? parsed.filter(s => typeof s === 'string' && /^[a-z]{2,3}$/.test(s))
            : [];
    } catch (_e) { return []; }
}

function saveSubtags(subtags) {
    try {
        if (subtags.length === 0) localStorage.removeItem(STORAGE_KEY);
        else                       localStorage.setItem(STORAGE_KEY, JSON.stringify(subtags));
    } catch (_e) { /* private mode */ }
}

function saveToAccount(subtags) {
    let token = null;
    try { token = localStorage.getItem('ihymns_auth_token'); } catch (_e) {}
    if (!token) return Promise.resolve();
    return apiFetch('/api?action=user_preferred_languages_save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ subtags }),
    }).catch(() => { /* best-effort */ });
}

/**
 * Build the picker UI inside the host element.
 * Available subtags come from /api?action=catalogue_language_subtags —
 * the same UNION of songbook + song-level distinct primary subtags
 * that the home page's filter partial uses, so the two surfaces
 * always show the same chip set. (#776)
 *
 * Previously hit /api?action=songbooks and harvested each row's
 * `language` — that was (a) song-level languages were missed
 * (catalogues with EN-tagged songbooks but multilingual songs only
 * showed EN) and (b) slow because it pulled the full row payload
 * for every songbook.
 */
async function buildPicker(host) {
    let available = [];
    try {
        const resp = await apiFetch('/api?action=catalogue_language_subtags');
        if (resp.ok) {
            const j = await resp.json();
            available = Array.isArray(j.subtags) ? j.subtags.slice() : [];
        }
    } catch (_e) { /* offline → empty picker */ }
    available.sort();

    if (available.length === 0) {
        host.innerHTML = '<p class="small text-muted mb-0">' +
            'No language tags exist on any songbook yet. The filter ' +
            'will appear here once the catalogue spans multiple ' +
            'languages.</p>';
        return;
    }

    const saved = loadSavedSubtags();
    const initialAll = saved.length === 0;
    const set = new Set(saved);

    /* Render the chip group. */
    const html = [];
    html.push('<div class="btn-group btn-group-sm flex-wrap" role="group">');
    html.push(
        '<input type="checkbox" class="btn-check" id="settings-lang-all" autocomplete="off"' +
        (initialAll ? ' checked' : '') + '>' +
        '<label class="btn btn-outline-info" for="settings-lang-all">All</label>'
    );
    for (const sub of available) {
        const id = 'settings-lang-' + sub;
        const checked = !initialAll && set.has(sub);
        html.push(
            `<input type="checkbox" class="btn-check js-settings-lang-opt" ` +
            `id="${id}" value="${sub}" autocomplete="off"` +
            (checked ? ' checked' : '') + '>' +
            `<label class="btn btn-outline-info" for="${id}">${sub.toUpperCase()}</label>`
        );
    }
    html.push('</div>');
    host.innerHTML = html.join('');

    const all  = host.querySelector('#settings-lang-all');
    const opts = Array.from(host.querySelectorAll('.js-settings-lang-opt'));

    function readUi() {
        if (all.checked) return [];
        return opts.filter(cb => cb.checked).map(cb => cb.value).sort();
    }

    function commit() {
        const subtags = readUi();
        saveSubtags(subtags);
        /* No `window.__iHymnsPreferredLanguages` write here any more (#1031).
           That global existed only to feed the old window.fetch override; the
           shared client reads STORAGE_LANGUAGE_FILTER from localStorage on
           every request, so saveSubtags() above IS the publication step. */
        saveToAccount(subtags);
        /* Notify other modules on the page that the filter changed
           so the songbook grid (if visible) re-applies. */
        document.dispatchEvent(new CustomEvent(EVT_LANGUAGE_FILTER_CHANGED, {
            detail: { subtags },
        }));
    }

    all.addEventListener('change', () => {
        if (all.checked) {
            opts.forEach(cb => { cb.checked = false; });
        } else {
            all.checked = true;            // never allow zero selected
        }
        commit();
    });
    opts.forEach(cb => {
        cb.addEventListener('change', () => {
            if (cb.checked) {
                all.checked = false;
            } else if (opts.every(o => !o.checked)) {
                all.checked = true;        // last opt unticked → fall back to "All"
            }
            commit();
        });
    });
}

/**
 * Boot the settings-page language picker. Idempotent.
 */
export function bootSettingsLanguageFilter(root) {
    const scope = root || document;
    const host = scope.querySelector('[data-settings-language-filter]');
    if (!host || host.dataset.langFilterBooted === '1') return;
    host.dataset.langFilterBooted = '1';
    buildPicker(host).catch(err => {
        console.warn('[settings-language-filter] boot failed', err);
        host.innerHTML = '<p class="small text-danger mb-0">' +
            'Could not load the language picker.</p>';
    });
}
