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
 *
 * #2137 review round 5 — AT MOST 32 LANGUAGES (the lead's decision). The server
 * uses only the first 32 preferences (includes/language_filter.php), so this
 * picker allows at most 32: ticking a 33rd does not tick it and says, in plain
 * words, "You can choose up to 32 languages. Untick one to add another."
 * After a save it uses the SERVER'S answer — the list the account actually
 * kept (tidied, and never longer than 32) — stores that, ticks exactly those
 * boxes, and says so if it kept fewer than were chosen. So what is ticked here,
 * what the home and songbooks grids filter by, and what the server filters by
 * all agree. Before this, a person could tick 40 languages and the last 8 were
 * silently ignored. Tested in tests/test-language-preference-cap.js.
 */

/* #1581 — shared event-name constant; see songbook-language-filter.js for
   the case-sensitivity bug this registry exists to prevent. */
/* #1031 — shared localStorage-key constant; see constants.js's own note on
   STORAGE_LANGUAGE_FILTER for why this raw key name predates the ihymns_
   prefix convention and must not be renamed. */
import { EVT_LANGUAGE_FILTER_CHANGED, STORAGE_LANGUAGE_FILTER } from '../constants.js';
import { apiFetch } from '../utils/api-client.js';
import { escapeHtml } from '../utils/html.js';
import {
    isPreferenceTag, languageGroupOf, mergePreferenceOrder,
    MAX_PREFERENCES, TOO_MANY_LANGUAGES_MESSAGE, usablePreferenceList,
} from '../utils/language-tags.js';

const STORAGE_KEY = STORAGE_LANGUAGE_FILTER;

function loadSavedSubtags() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        /* #2137 — whole tags (`pt-BR`) are kept, in the order chosen.
           Round 5 — only the first 32, as the server reads it (an older list
           may hold more; the rest are left in storage until the person next
           changes the list, and then this picker saves what it shows). */
        return Array.isArray(parsed) ? usablePreferenceList(parsed) : [];
    } catch (_e) { return []; }
}

function saveSubtags(subtags) {
    try {
        if (subtags.length === 0) localStorage.removeItem(STORAGE_KEY);
        else                       localStorage.setItem(STORAGE_KEY, JSON.stringify(subtags));
    } catch (_e) { /* private mode */ }
}

/**
 * Save the list to the signed-in account. Resolves with the server's answer
 * (`{ ok, languages, subtags }` — `languages` is the list as the account kept
 * it), or null when nobody is signed in, the request failed, or the server said
 * no. Best-effort: a failed save never changes what the page shows.
 */
function saveToAccount(subtags) {
    let token = null;
    try { token = localStorage.getItem('ihymns_auth_token'); } catch (_e) {}
    if (!token) return Promise.resolve(null);
    return apiFetch('/api?action=user_preferred_languages_save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        /* #2137 — `languages` is read by the server first; `subtags` keeps an
           older server working during a staggered deploy. */
        body: JSON.stringify({ languages: subtags, subtags }),
    })
        .then((r) => (r && r.ok ? r.json() : null))
        .catch(() => null /* best-effort */);
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
    let names = {};
    try {
        const resp = await apiFetch('/api?action=catalogue_language_subtags');
        if (resp.ok) {
            const j = await resp.json();
            /* Only well-formed codes: each one is also written into the
               checkbox's id and value below. */
            available = Array.isArray(j.subtags) ? j.subtags.filter(isPreferenceTag) : [];
            /* #2137 — the server sends each language's English name, from the
               same registry the rest of the site names languages from. */
            names = (j.names && typeof j.names === 'object') ? j.names : {};
        }
    } catch (_e) { /* offline → empty picker */ }
    const nameOf = (sub) => (typeof names[sub] === 'string' && names[sub] !== '') ? names[sub] : sub;
    /* #2137 — ordered for the reader (the shared language policy, UI-020 and
       UI-040): their own languages first, in their priority order; then every
       other language alphabetically by NAME ("Afrikaans, Bemba, Chinese…"),
       not by code ("af, bem, zh…"). Drawn once; ticking a box never moves it
       (UI-050). */
    const savedForOrder = loadSavedSubtags();
    const priority = [];
    for (const g of savedForOrder.map(languageGroupOf)) {
        if (available.includes(g) && !priority.includes(g)) priority.push(g);
    }
    const rest = available
        .filter(sub => !priority.includes(sub))
        .sort((a, b) => nameOf(a).localeCompare(nameOf(b), 'en', { sensitivity: 'base' }) || (a < b ? -1 : a > b ? 1 : 0));
    available = priority.concat(rest);

    if (available.length === 0) {
        host.innerHTML = '<p class="small text-muted mb-0">' +
            'No language tags exist on any songbook yet. The filter ' +
            'will appear here once the catalogue spans multiple ' +
            'languages.</p>';
        return;
    }

    const saved = loadSavedSubtags();
    const initialAll = saved.length === 0;
    const set = new Set(saved.map(languageGroupOf));

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
            /* #2137 — the language's name, not its upper-cased code ("ZH"
               could stand for either Chinese script). The code stays as a
               tooltip. Both come from the server; escaped before use. */
            `<label class="btn btn-outline-info" for="${id}" title="${escapeHtml(sub)}">${escapeHtml(nameOf(sub))}</label>`
        );
    }
    html.push('</div>');
    /* #2137 review round 5 — where the picker says why a tick did not take, or
       what the account kept. Always present (empty when there is nothing to
       say), so a screen reader announces each new message. */
    html.push('<p class="small text-danger mt-2 mb-0" role="status" aria-live="polite" data-settings-lang-message></p>');
    host.innerHTML = html.join('');

    const all  = host.querySelector('#settings-lang-all');
    const opts = Array.from(host.querySelectorAll('.js-settings-lang-opt'));
    const message = host.querySelector('[data-settings-lang-message]');
    const say = (text) => { message.textContent = text; };

    /* #2137 — keeps the person's priority order (and full tags such as
       `pt-BR`); a newly ticked language goes last. This used to sort A-Z. */
    function readUi() {
        if (all.checked) return [];
        return mergePreferenceOrder(loadSavedSubtags(), opts.filter(cb => cb.checked).map(cb => cb.value));
    }

    /* Notify other modules on the page that the filter changed so the
       songbook grid (if visible) re-applies. */
    function announce(subtags) {
        document.dispatchEvent(new CustomEvent(EVT_LANGUAGE_FILTER_CHANGED, {
            detail: { subtags },
        }));
    }

    /* Tick exactly the boxes for a list ("All" when it is empty). */
    function showList(list) {
        const groups = new Set(list.map(languageGroupOf));
        all.checked = list.length === 0;
        opts.forEach((cb) => { cb.checked = list.length > 0 && groups.has(cb.value); });
    }

    /* #2137 review round 5 — the save's answer is what the account KEPT.
       When it differs from what was sent (the server tidies tags, drops ones
       it does not recognise, and keeps at most 32), the page stores and shows
       the kept list, so the grids and the server-filtered lists agree with it.
       No answer (signed out, an older server, a failed save) changes nothing. */
    function useSavedAnswer(sent, answer) {
        if (!answer || answer.ok !== true || !Array.isArray(answer.languages)) return;
        const kept = usablePreferenceList(answer.languages);
        if (JSON.stringify(kept) === JSON.stringify(sent)) return;
        saveSubtags(kept);
        showList(kept);
        announce(kept);
        say(kept.length < sent.length
            ? `Saved ${kept.length} of the ${sent.length} languages you chose — the site keeps at most ${MAX_PREFERENCES}, and only languages it recognises.`
            : '');
    }

    /* Each save is numbered; only the answer to the NEWEST one is used, so a
       slow answer to an older save can never undo a later change. */
    let saveNumber = 0;
    function commit() {
        const subtags = readUi();
        saveSubtags(subtags);
        /* No `window.__iHymnsPreferredLanguages` write here any more (#1031).
           That global existed only to feed the old window.fetch override; the
           shared client reads STORAGE_LANGUAGE_FILTER from localStorage on
           every request, so saveSubtags() above IS the publication step. */
        announce(subtags);
        const mine = ++saveNumber;
        return saveToAccount(subtags).then((answer) => {
            if (mine === saveNumber) useSavedAnswer(subtags, answer);
        });
    }

    all.addEventListener('change', () => {
        if (all.checked) {
            opts.forEach(cb => { cb.checked = false; });
        } else {
            all.checked = true;            // never allow zero selected
        }
        say('');
        commit();
    });
    opts.forEach(cb => {
        cb.addEventListener('change', () => {
            if (cb.checked) {
                /* #2137 review round 5 — the list this tick would make (All
                   counts as an empty list); more than 32, and the tick is
                   refused: the box is unticked again, nothing is saved, and
                   the person is told why and what to do. */
                const wouldBe = mergePreferenceOrder(all.checked ? [] : loadSavedSubtags(),
                    opts.filter(o => o.checked).map(o => o.value));
                if (wouldBe.length > MAX_PREFERENCES) {
                    cb.checked = false;
                    say(TOO_MANY_LANGUAGES_MESSAGE);
                    return;
                }
                all.checked = false;
            } else if (opts.every(o => !o.checked)) {
                all.checked = true;        // last opt unticked → fall back to "All"
            }
            say('');
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
