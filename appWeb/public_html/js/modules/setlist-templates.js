/**
 * iHymns — Set-list templates + service plans (#301; UI #1671 F4)
 *
 * ELI5: a service usually has a SHAPE before it has songs — welcome, two praise
 * items, a reading, the sermon, a closing. This is the screen that lets you keep
 * those shapes as templates, start a set list from one, and then drop your songs
 * into the labelled spaces.
 *
 * ============================================================================
 * WHY THIS FILE DID NOT EXIST UNTIL NOW
 * ============================================================================
 *
 * `setlist_templates` and `setlist_template_save` shipped with #301 and never
 * had a caller. `includes/pages/setlist.php` even carried a "From Template"
 * dropdown — hidden behind `style="display:none !important"`, with not one line
 * of JS in the tree referencing its ids. Exactly the #298 musical-key shape:
 * markup, endpoints, no wire.
 *
 * #1671 Batch 8 wired three sibling orphans and DELIBERATELY REFUSED this one,
 * because a template's whole payload is its slots and `user_setlists_sync` had
 * nowhere to keep them — a plan applied here would round-trip to the server and
 * come back GONE, silently, for signed-in users only. That was a SERVER
 * problem, so it was fixed server-first: `tblUserSetlists.SlotsJson` +
 * `includes/setlist_templates.php` + the missing `setlist_template_update` /
 * `_delete` endpoints. This module is the UI those changes make honest.
 *
 * ============================================================================
 * THE STANDING CONSTRAINTS THIS FILE OBEYS
 * ============================================================================
 *
 *  - **rule #30** — the set-list fragment carries NO executable inline
 *    `<script>` and never can: `index.php` sends an enforcing nonce CSP (#117)
 *    and the fragment is a separate HTTP response that never sees the
 *    per-request nonce, so the browser refuses a nonce-less inline script with
 *    a console-only violation — a feature that looks alive and dies on click
 *    (#1565). This is a real ES module, imported from `router.js`'s
 *    `afterPageLoad()`, finding its own hooks in the DOM.
 *  - **rule #31** — every request goes through `apiFetch`, never bare `fetch()`
 *    and never a `window.fetch` override.
 *  - **rule #35** — failure KINDS are told apart by HTTP STATUS, never by
 *    regex-matching the server's sentence. See `templateErrorForStatus()`.
 *  - **the authorisation policy is NOT re-derived here.** The server emits
 *    `canEdit` per template and this module draws buttons from that. A client
 *    that recomputed "createdBy === me" would be a second copy of an access
 *    policy with nothing keeping the two in step — and the copy would be the
 *    one deciding what the user sees.
 *
 * ACCESSIBILITY. Deleting a template is irreversible, so it is behind a
 * confirm; outcomes are spoken through the shared announcer
 * (`js/utils/announce.js`, WCAG 4.1.3 Status Messages); every per-row control
 * carries an accessible name that says WHICH template or slot it acts on; and
 * slot assignment is a native `<select>` rather than drag-and-drop, which is
 * the WCAG 2.5.7 single-pointer path and the #1644 precedent in this repo.
 *
 * @see appWeb/public_html/includes/setlist_templates.php — the ONE slot model
 * @see appWeb/public_html/api.php — setlist_templates / _save / _update / _delete
 * @link https://www.w3.org/WAI/WCAG21/Understanding/status-messages.html
 * @link https://www.w3.org/WAI/WCAG21/Understanding/pointer-gestures.html
 */

import { escapeHtml } from '../utils/html.js';
import { announce } from '../utils/announce.js';
import { apiFetch } from '../utils/api-client.js';

/* ==========================================================================
 * PURE HELPERS
 *
 * Everything below this line and above the DOM section is a pure function of
 * its arguments — no document, no fetch, no globals. That is deliberate:
 * `tests/test-setlist-template-helpers.js` imports this module under plain Node
 * and CALLS them. Source inspection proves a file contains the right words;
 * only calling it proves the file gives the right answers
 * (tests/php/test-transaction-fatal.php is the standing worked example).
 * ========================================================================== */

/**
 * Mint a slot id the server's charset will accept unchanged.
 *
 * ELI5: a short random name for one row of the running order.
 *
 * The charset is exactly `setlistTemplateSanitiseSlotId()`'s
 * (`[A-Za-z0-9_-]`), so a minted id survives the server untouched. If it did
 * not, the id the client keyed its DOM by and the id the server stored would
 * differ, and every reorder would look like a delete-plus-insert.
 *
 * @returns {string}
 */
export function newSlotId() {
    /* Math.random is right here and crypto.randomUUID would be overkill: this
       id is a DOM key scoped to one set list, never a secret and never a
       security boundary. Two colliding ids would merely mis-key two rows. */
    return 's' + Math.random().toString(36).slice(2, 10);
}

/**
 * Turn a template (as `setlist_templates` returns it) into a fresh set-list plan.
 *
 * ELI5: copy the shape of the service, giving every row its own new name tag,
 * and remember which template it came from.
 *
 * THE COPY IS THE POINT. Slots are copied, not referenced, so deleting or
 * renaming the template later can never reach into somebody's Sunday service
 * and change it. `templateName` is a SNAPSHOT for the same reason — it is what
 * lets the plan still say where it came from after the template is gone.
 *
 * Every slot gets a NEW id even if the template had one: two set lists built
 * from the same template would otherwise share slot ids, which is harmless
 * today and a trap the moment anything keys across set lists.
 *
 * @param {object} template A row from `setlist_templates`.
 * @returns {{templateId: (number|null), templateName: (string|null), slots: Array}|null}
 */
export function planFromTemplate(template) {
    const slots = Array.isArray(template?.slotsJson) ? template.slotsJson : [];
    if (slots.length === 0) { return null; }
    return {
        templateId: Number.isFinite(template?.id) ? template.id : null,
        templateName: typeof template?.name === 'string' && template.name.trim() !== ''
            ? template.name.trim()
            : null,
        slots: slots.map((slot) => ({
            id: newSlotId(),
            label: String(slot?.label ?? '').trim(),
            type: String(slot?.type ?? 'song').trim() || 'song',
        })).filter((slot) => slot.label !== ''),
    };
}

/**
 * Assign (or clear) the song sitting in one slot.
 *
 * ELI5: put this song in that space in the running order, or empty the space.
 *
 * RETURNS A NEW PLAN rather than mutating: the caller holds the old one while
 * it decides whether the write succeeded, and an in-place edit would have
 * already changed what it is comparing against.
 *
 * Passing a blank/undefined songId REMOVES the key entirely rather than setting
 * it to null, matching what the server's sanitiser stores. If the two disagreed,
 * a plan would come back from a sync differing from the one just sent and every
 * dirty-check would report unsaved changes forever.
 *
 * @param {object} plan
 * @param {string} slotId
 * @param {string} songId Blank to clear.
 * @returns {object} A new plan object.
 */
export function assignSongToSlot(plan, slotId, songId) {
    const slots = Array.isArray(plan?.slots) ? plan.slots : [];
    const clean = typeof songId === 'string' ? songId.trim() : '';
    return {
        ...plan,
        slots: slots.map((slot) => {
            if (slot.id !== slotId) { return slot; }
            const next = { ...slot };
            if (clean === '') {
                delete next.songId;
            } else {
                next.songId = clean;
            }
            return next;
        }),
    };
}

/**
 * How much of the plan is filled in?
 *
 * ELI5: "3 of 6 songs chosen" — and only SONG slots count, because a prayer
 * has nothing to choose.
 *
 * @param {object} plan
 * @returns {{filled: number, total: number, complete: boolean}}
 */
export function planProgress(plan) {
    const slots = Array.isArray(plan?.slots) ? plan.slots : [];
    const songSlots = slots.filter((slot) => (slot?.type ?? 'song') === 'song');
    const filled = songSlots.filter((slot) => typeof slot.songId === 'string' && slot.songId !== '').length;
    return {
        filled,
        total: songSlots.length,
        /* An all-non-song plan is "complete" — there is nothing outstanding.
           Reporting 0/0 as incomplete would show a permanent warning on a plan
           with nothing wrong with it. */
        complete: filled === songSlots.length,
    };
}

/**
 * Map an HTTP status onto a sentence for the user.
 *
 * ELI5: say what actually went wrong, based on the number the server sent, not
 * on the words it happened to use.
 *
 * rule #35 / #1677: a client that regex-matches the server's prose degrades
 * silently to a generic toast the day somebody rewords a string, with nothing
 * red anywhere. The server's own message is preferred when it sends one — but
 * the BRANCH is on the number, so the classification cannot rot.
 *
 * @param {number} status
 * @param {string} serverMessage
 * @returns {string}
 */
export function templateErrorForStatus(status, serverMessage = '') {
    if (serverMessage) { return serverMessage; }
    if (status === 401) { return 'Sign in to manage templates.'; }
    if (status === 403) { return 'You can only change templates you created.'; }
    if (status === 404) { return 'That template no longer exists.'; }
    if (status === 413) { return 'That service order has too many rows. Nothing was changed.'; }
    if (status === 429) { return 'Too many requests — wait a moment and try again.'; }
    if (status >= 500) { return 'The server had a problem. Nothing was changed.'; }
    return 'Could not complete that. Nothing was changed.';
}

/**
 * Build the slots a "save this set list as a template" action should send.
 *
 * ELI5: if the set list already has a running order, keep it (minus the actual
 * song choices, which belong to that one service). If it does not, make one row
 * per song so the template still describes the shape.
 *
 * DROPPING `songId` IS THE WHOLE POINT. A template is a SHAPE; carrying one
 * service's song choices into it would apply last Sunday's songs to every
 * future Sunday, which reads as the app losing the user's edits.
 *
 * @param {object} setlist A set list `{name, songs: [], plan?: {}}`.
 * @returns {Array<{label: string, type: string}>}
 */
export function templateSlotsFromSetlist(setlist) {
    const planSlots = Array.isArray(setlist?.plan?.slots) ? setlist.plan.slots : [];
    if (planSlots.length > 0) {
        return planSlots
            .filter((slot) => String(slot?.label ?? '').trim() !== '')
            .map((slot) => ({
                label: String(slot.label).trim(),
                type: String(slot?.type ?? 'song').trim() || 'song',
            }));
    }
    const songs = Array.isArray(setlist?.songs) ? setlist.songs : [];
    return songs.map((song, i) => ({
        label: String(song?.title ?? '').trim() || ('Song ' + (i + 1)),
        type: 'song',
    }));
}

/* ==========================================================================
 * DOM WIRING
 *
 * ⚠️ NOTHING BELOW THIS LINE IS EXERCISED BY ANY TEST IN THIS REPO. There is no
 * browser in the build container, so the rendering, the listeners and the
 * request paths are covered by no assertion — the pure half above is, and this
 * comment exists so a green suite is never read as "the screen works".
 * ========================================================================== */

/** Cached `setlist_templates` response, so opening the menu twice is one fetch. */
let cachedCatalogue = null;
/** The container this module is currently bound to (idempotency guard). */
let boundRoot = null;

/**
 * Fetch the caller's visible templates (theirs + their orgs' + public ones).
 *
 * @param {boolean} force Bypass the cache.
 * @returns {Promise<{templates: Array, slotTypes: object, maxSlots: number}>}
 */
export async function loadTemplateCatalogue(force = false) {
    if (cachedCatalogue && !force) { return cachedCatalogue; }
    const res = await apiFetch('/api?action=setlist_templates', { auth: true });
    if (!res.ok) {
        throw Object.assign(new Error('setlist_templates failed'), { status: res.status });
    }
    const data = await res.json();
    cachedCatalogue = {
        templates: Array.isArray(data?.templates) ? data.templates : [],
        /* The slot-type picker is built from the SERVER's registry, never a
           hardcoded copy here — adding a type stays one line in
           setlistTemplateSlotTypes() (rule #35: a mechanism, not a comment). */
        slotTypes: (data && typeof data.slotTypes === 'object' && data.slotTypes) || { song: 'Song' },
        maxSlots: Number.isFinite(data?.maxSlots) ? data.maxSlots : 100,
    };
    return cachedCatalogue;
}

/** Invalidate the cache after any write. */
export function invalidateTemplateCatalogue() {
    cachedCatalogue = null;
}

/**
 * POST a JSON body to one of the template endpoints.
 *
 * Throws an Error carrying `.status` so callers branch on the code, never on
 * the sentence (rule #35 — `unwrap()` in the v2 editor client sets the same
 * property for the same reason).
 *
 * @param {string} action
 * @param {object} body
 */
async function postTemplate(action, body) {
    const res = await apiFetch('/api?action=' + encodeURIComponent(action), {
        method: 'POST',
        auth: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    let payload = null;
    try { payload = await res.json(); } catch { /* a non-JSON error page */ }
    if (!res.ok) {
        throw Object.assign(
            new Error(templateErrorForStatus(res.status, String(payload?.error ?? ''))),
            { status: res.status }
        );
    }
    invalidateTemplateCatalogue();
    return payload;
}

/** Create a template. */
export function createTemplate(body) { return postTemplate('setlist_template_save', body); }
/** Update a template the caller authored. */
export function updateTemplate(body) { return postTemplate('setlist_template_update', body); }
/** Delete a template the caller authored. */
export function deleteTemplate(id) { return postTemplate('setlist_template_delete', { id }); }

/**
 * Render the "From Template" dropdown's contents.
 *
 * @param {Element} list  The `<ul>` to fill.
 * @param {Array}   templates
 * @returns {string} The HTML written (returned so a test could assert it).
 */
export function renderTemplateMenu(list, templates) {
    const html = templates.length === 0
        ? '<li><span class="dropdown-item-text text-muted small">'
            + 'No templates yet — use “Save as template” on a set list.</span></li>'
        : templates.map((tpl) => {
            const count = Array.isArray(tpl.slotsJson) ? tpl.slotsJson.length : 0;
            return '<li><button type="button" class="dropdown-item" '
                + 'data-template-apply="' + escapeHtml(String(tpl.id)) + '">'
                + escapeHtml(String(tpl.name ?? 'Untitled'))
                + ' <span class="text-muted small">(' + count + ' item'
                + (count === 1 ? '' : 's') + ')</span>'
                + '</button></li>';
        }).join('');

    const manage = '<li><hr class="dropdown-divider"></li>'
        + '<li><button type="button" class="dropdown-item" data-template-manage>'
        + '<i class="fa-solid fa-gear me-1" aria-hidden="true"></i>Manage templates…</button></li>';

    if (list) { list.innerHTML = html + manage; }
    return html + manage;
}

/**
 * Render the service plan for one set list.
 *
 * Each song slot gets a native `<select>` listing the set list's own songs —
 * the WCAG 2.5.7 single-pointer path, and the #1644 precedent in this repo for
 * choosing buttons/selects over drag-and-drop. Non-song slots render as plain
 * labelled rows, because there is nothing to choose.
 *
 * @param {object} plan
 * @param {Array}  songs   The set list's songs.
 * @param {object} slotTypes The server's type registry (key => label).
 * @returns {string} HTML.
 */
export function renderPlanHtml(plan, songs, slotTypes = {}) {
    const slots = Array.isArray(plan?.slots) ? plan.slots : [];
    if (slots.length === 0) { return ''; }

    const progress = planProgress(plan);
    const options = (selected) => ['<option value="">— not chosen —</option>']
        .concat((Array.isArray(songs) ? songs : []).map((song) => {
            const id = String(song?.id ?? '');
            return '<option value="' + escapeHtml(id) + '"'
                + (id === selected ? ' selected' : '') + '>'
                + escapeHtml(String(song?.title ?? id)) + '</option>';
        })).join('');

    const rows = slots.map((slot, i) => {
        const type = String(slot?.type ?? 'song');
        const typeLabel = typeof slotTypes[type] === 'string' ? slotTypes[type] : type;
        const label = escapeHtml(String(slot?.label ?? ''));
        const head = '<div class="d-flex align-items-center gap-2">'
            + '<span class="badge bg-secondary">' + (i + 1) + '</span>'
            + '<strong>' + label + '</strong>'
            + '<span class="text-muted small">' + escapeHtml(typeLabel) + '</span>'
            + '</div>';
        if (type !== 'song') {
            return '<li class="list-group-item">' + head + '</li>';
        }
        const selectId = 'plan-slot-' + escapeHtml(String(slot?.id ?? i));
        return '<li class="list-group-item">' + head
            + '<label class="visually-hidden" for="' + selectId + '">Song for “' + label + '”</label>'
            + '<select class="form-select form-select-sm mt-2" id="' + selectId + '" '
            + 'data-plan-slot="' + escapeHtml(String(slot?.id ?? i)) + '">'
            + options(typeof slot?.songId === 'string' ? slot.songId : '')
            + '</select></li>';
    }).join('');

    const from = plan?.templateName
        ? '<span class="text-muted small">from “' + escapeHtml(String(plan.templateName)) + '”</span>'
        : '';

    return '<div class="card mb-3" data-setlist-plan>'
        + '<div class="card-body">'
        + '<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">'
        + '<h3 class="h6 mb-0"><i class="fa-solid fa-list-check me-2" aria-hidden="true"></i>Service plan ' + from + '</h3>'
        + '<div class="d-flex gap-2 align-items-center">'
        + '<span class="text-muted small">' + progress.filled + ' of ' + progress.total + ' songs chosen</span>'
        + '<button type="button" class="btn btn-sm btn-outline-danger" data-plan-clear>Remove plan</button>'
        + '</div></div>'
        + '<ul class="list-group list-group-flush">' + rows + '</ul>'
        + '</div></div>';
}

/**
 * Entry point — call after the set-list fragment is in the DOM.
 *
 * Idempotent: `afterPageLoad()` runs on EVERY navigation and a user can bounce
 * off and back onto /setlist without a document load. A cheap no-op on any
 * other page, because the hook is absent there.
 *
 * @param {object} setList The SetList instance from app.js (its storage + render).
 */
export function bootSetlistTemplates(setList) {
    const dropdown = document.getElementById('template-dropdown');
    if (!dropdown || !setList) { boundRoot = null; return; }

    /* The dropdown ships hidden. It was `style="display:none !important"`,
       which NO class toggle can override — part of why the block stayed
       invisible even to somebody trying to revive it, exactly as #298's
       song-key container did (fixed the same way in Batch 8). */
    dropdown.style.removeProperty('display');
    dropdown.classList.remove('d-none');

    if (boundRoot === dropdown) { return; }
    boundRoot = dropdown;

    const list = document.getElementById('template-list');

    /* Populate on first open rather than on page load: most visits to /setlist
       never touch templates, and an eager fetch would put an authenticated
       request on the critical path of every navigation. */
    dropdown.addEventListener('show.bs.dropdown', () => {
        loadTemplateCatalogue()
            .then((cat) => renderTemplateMenu(list, cat.templates))
            .catch((err) => {
                if (list) {
                    list.innerHTML = '<li><span class="dropdown-item-text text-danger small">'
                        + escapeHtml(templateErrorForStatus(err?.status ?? 0, '')) + '</span></li>';
                }
            });
    });

    /* ONE delegated handler rather than one per row, so a re-render cannot leak
       listeners. Bound to the dropdown, which the router discards with the
       fragment, so it goes away with it. */
    dropdown.addEventListener('click', (e) => {
        const target = e.target instanceof Element ? e.target : null;
        const applyBtn = target?.closest('[data-template-apply]');
        if (applyBtn) {
            applyTemplate(setList, applyBtn.getAttribute('data-template-apply'));
            return;
        }
        if (target?.closest('[data-template-manage]')) {
            openManageDialog(setList);
        }
    });
}

/**
 * Create a new set list from a template and apply its plan.
 *
 * @param {object} setList
 * @param {string} templateId
 */
async function applyTemplate(setList, templateId) {
    try {
        const cat = await loadTemplateCatalogue();
        const tpl = cat.templates.find((t) => String(t.id) === String(templateId));
        if (!tpl) { announce('That template is no longer available.'); return; }

        const plan = planFromTemplate(tpl);
        if (!plan) { announce('That template has no rows to apply.'); return; }

        const created = setList.create(String(tpl.name ?? 'New set list'));
        if (!created) { return; }

        const lists = setList.getAll();
        const target = lists.find((l) => l.id === created.id) || created;
        target.plan = plan;
        setList.saveAll(lists);
        announce('Created “' + String(target.name) + '” from a template.');
        setList.renderSetListOverview?.();
    } catch (err) {
        announce(templateErrorForStatus(err?.status ?? 0, ''));
    }
}

/**
 * Open the manage-templates dialog.
 *
 * Deliberately a plain `window.prompt`/`confirm` flow rather than a bespoke
 * modal: rename and delete are the two operations #1671 F4 was blocked on, the
 * native dialogs are keyboard- and screen-reader-accessible by construction,
 * and a hand-rolled modal is a focus-trap bug waiting to happen on a screen
 * nobody can exercise in this container. A richer editor belongs in its own
 * change, on a branch somebody can actually open in a browser.
 *
 * @param {object} setList
 */
async function openManageDialog(setList) {
    try {
        const cat = await loadTemplateCatalogue(true);
        const mine = cat.templates.filter((t) => t.canEdit === true);
        if (mine.length === 0) {
            announce('You have no templates of your own to manage.');
            return;
        }
        const menu = mine.map((t, i) => (i + 1) + '. ' + String(t.name ?? 'Untitled')).join('\n');
        const pick = window.prompt('Your templates:\n' + menu + '\n\nEnter a number to rename, or "d" then the number to delete (e.g. d2).');
        if (!pick) { return; }

        const wantsDelete = /^d/i.test(pick.trim());
        const index = parseInt(pick.trim().replace(/^d/i, ''), 10) - 1;
        const tpl = mine[index];
        if (!tpl) { announce('No template matched that.'); return; }

        if (wantsDelete) {
            if (!window.confirm('Delete “' + String(tpl.name) + '”? Set lists already built from it are unaffected.')) { return; }
            await deleteTemplate(tpl.id);
            announce('Deleted “' + String(tpl.name) + '”.');
            return;
        }

        const name = window.prompt('New name for this template:', String(tpl.name ?? ''));
        if (name === null || name.trim() === '') { return; }
        await updateTemplate({
            id: tpl.id,
            name: name.trim(),
            description: String(tpl.description ?? ''),
            slots: Array.isArray(tpl.slotsJson) ? tpl.slotsJson : [],
            is_public: tpl.isPublic === true,
        });
        announce('Renamed to “' + name.trim() + '”.');
    } catch (err) {
        announce(templateErrorForStatus(err?.status ?? 0, err?.message ?? ''));
    }
    /* setList is accepted so a future richer dialog can offer "save the current
       set list as a template" from here without changing the call site. */
    void setList;
}

/**
 * Save one set list as a reusable template.
 *
 * Exported (rather than only wired to a button) because the set-list detail
 * view calls it, and because it is the operation that makes the whole feature
 * self-sustaining: without it a user can only ever apply templates somebody
 * else made.
 *
 * @param {object} setlist A set list object.
 * @returns {Promise<void>}
 */
export async function saveSetlistAsTemplate(setlist) {
    const slots = templateSlotsFromSetlist(setlist);
    if (slots.length === 0) {
        announce('Add some songs first — there is nothing to save as a template.');
        return;
    }
    const name = window.prompt('Name for this template:', String(setlist?.name ?? 'Service order'));
    if (name === null || name.trim() === '') { return; }
    try {
        await createTemplate({ name: name.trim(), description: '', slots, is_public: false });
        announce('Saved “' + name.trim() + '” as a template.');
    } catch (err) {
        announce(templateErrorForStatus(err?.status ?? 0, err?.message ?? ''));
    }
}
