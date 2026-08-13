/* ==========================================================================
 *  credits-tab.js — the v2 Song Editor "Credits" tab (#1200 Phase 2, #960)
 *
 *  ELI5: this is the box of name fields for Writers / Composers / Arrangers /
 *  Adaptors / Translators / Artists. Each person gets THREE small boxes —
 *  First names, Surname, Suffix — instead of one big "Name" box, because
 *  splitting the name lets the rest of the app sort by surname, spot the same
 *  person spelled two ways, and build a real "who wrote this" page. When you
 *  stop typing for about a second the row saves itself; you never press Save.
 *
 *  DETAILED / WHY (#960): the ORIGINAL v2 rewrite (#1200) shipped this tab
 *  with a single flat "Name" input and a `credit_upsert` endpoint that wrote
 *  only the role table (tblSongWriters etc.) — it never touched the
 *  `tblMusicians` registry that powers /musician/<slug>, aliases and
 *  identifiers. The legacy v1 editor (editor.js's createCreditNameRow(),
 *  #960) had the three-field entry from the start; v2 dropped it. This file
 *  restores the three-field UI WITHOUT restoring v1's other habit — v1 also
 *  carries its own composePersonNameJs()/decomposePersonNameJs() mirror of
 *  the PHP name-splitting heuristic and runs it in the browser on every load
 *  (editor.js:3572-3607, doc'd there as "MUST stay byte-equal to the PHP
 *  implementation"). Two copies of that heuristic is exactly the kind of
 *  duplication the project's modularity rule (.claude/CLAUDE.md) exists to
 *  prevent, and #960's OWN fix (the extraction in commit 3f505b86) already
 *  centralised the maths server-side in musician_helpers.php
 *  (creditEntryNormalise() / musicianPromote() / decomposePersonName()).
 *  So this file carries NO compose/decompose logic at all: it sends whatever
 *  parts the curator typed, and only ever DISPLAYS what the server's response
 *  says the registry actually holds — including on the initial load, which
 *  is why api2.php's ed2_buildSongSnapshot() (the load_song builder) now
 *  ships first/surname/suffix pre-split for every existing credit instead of
 *  handing this file a bare name to guess at.
 *
 *  Each row saves ITS OWN credit atomically via the granular `credit_upsert`
 *  / `credit_delete` endpoints — no whole-song save, and (unlike the legacy
 *  editor) no way for a save-race to duplicate a credit 15× (#1178). A new
 *  row adopts the server-assigned credit id on first save so later edits
 *  UPDATE in place.
 *
 *  THE SERVER RESPONSE IS AUTHORITATIVE, NOT THE INPUT (#960 D2). The
 *  registry's never-overwrite backfill (musician_helpers.php's
 *  musicianPromote()) means a curator's typed parts can be silently
 *  refused when the registry already holds different curated values for
 *  that name — credit_upsert's response always echoes the REGISTRY's
 *  first/surname/suffix, and this file writes that back into the three
 *  boxes so the refusal is visible instead of masked (rule #30's "looks
 *  alive, isn't" failure class, applied to data instead of a feature). The
 *  one exception: a box the curator is CURRENTLY TYPING INTO is never
 *  overwritten by an in-flight response, however it disagrees — see
 *  adoptServerCredit() below.
 *
 *  mountCreditsTab(container, { store, api, songId, toast, registerFlush }) -> teardown fn
 *  Reads the store's `credits` slice: { writers:[{id,name,first,surname,
 *  suffix}], composers:[…], … } — first/surname/suffix are present whenever
 *  the server has them (gated on the #935 columns existing; see
 *  ed2_buildSongSnapshot()'s doc-block in api2.php).
 *
 *  registerFlush(fn) — #1846: hands the shell a "flush my pending debounced
 *  saves now" function for its manual Save button. See flushPending() below.
 * ========================================================================== */

const ROLES = [
    ['writers',     'Writers'],
    ['composers',   'Composers'],
    ['arrangers',   'Arrangers'],
    ['adaptors',    'Adaptors'],
    ['translators', 'Translators'],
    ['artists',     'Artists'],
];
/* #1843 — raised 500 → 1000 ms so a mid-word pause is far less likely to fire
   a save on a partial name ("Joh", "John") and auto-mint a junk tblMusicians
   registry row. The server-side reap janitor (musicianReapOrphanedAutoRow(),
   credit_upsert/credit_delete in api2.php) is the real fix; this just widens
   the window so fewer junk rows are minted in the first place. */
const SAVE_DEBOUNCE_MS = 1000;

const SEARCH_DEBOUNCE_MS = 180;

/* The three name-part fields, in display/tab order. Kept as one array so
   the render loop, the input-wiring loop and adoptServerCredit() all walk
   the same three fields instead of three near-identical hand-written
   blocks that could quietly drift apart (e.g. one gaining a trim() the
   others lack). aria-label text matches the legacy editor's
   createCreditNameRow() EXACTLY (editor.js:3674-3676) — same labels, same
   screen-reader experience, whichever editor a curator lands in. */
const NAME_FIELDS = [
    ['first',   'First names'],
    ['surname', 'Surname'],
    ['suffix',  'Suffix'],
];

export function mountCreditsTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    const timers = new Map();
    /* #1846 — `role:credit._key` -> { role, credit } not yet fired, the SAME
       key space as `timers` above. Lets the shell's manual Save button fire a
       pending debounced write early via flushPending() below. Cleared
       wherever `timers` is cleared outside of debouncedSave() too (the D11
       "all fields emptied" branch and the suggestion-pick handler both cancel
       a pending timer directly), so it never outlives the timer it shadows. */
    const pending = new Map();
    /* #1846 — hand the shell a "flush me now" function once, at mount time
       (a plain injected callback, not a DOM event — rule #35/#1581). Defaults
       to a no-op so the tab still mounts standalone in a test harness that
       doesn't pass registerFlush. */
    const registerFlush = opts.registerFlush || function () {};

    /* credit._key -> { firstEl, surnameEl, suffixEl, del, hint }. Populated
       fresh by render() every time the `credits` slice changes (add/remove
       rebuild the whole tab, same as before #960); used by saveCredit's
       response-adoption and by the D11 delete-affordance highlight to reach
       a SPECIFIC row's live DOM nodes without a full re-render — a full
       re-render is exactly what requirement 3 says a debounced autosave
       must never trigger, because rebuilding the DOM mid-keystroke would
       drop focus out of whichever box the curator is still typing in. */
    const rowEls = new Map();

    /* One shared autocomplete dropdown, repositioned (fixed) under the active
       credit input — so the per-render container wipe never strands per-input
       dropdowns. Suggestions come from credit_search (all roles + the registry). */
    let searchTimer = null;
    const dropdown = document.createElement('div');
    dropdown.className = 'list-group shadow-sm';
    dropdown.style.position = 'fixed';
    dropdown.style.zIndex = '1056';
    dropdown.style.maxHeight = '240px';
    dropdown.style.overflowY = 'auto';
    dropdown.style.display = 'none';
    document.body.appendChild(dropdown);

    function hideDropdown() { dropdown.style.display = 'none'; dropdown.innerHTML = ''; }
    function positionDropdown(input) {
        const r = input.getBoundingClientRect();
        dropdown.style.left = r.left + 'px';
        dropdown.style.top = r.bottom + 'px';
        dropdown.style.minWidth = r.width + 'px';
    }
    function showSuggestions(input, role, credit, suggestions) {
        dropdown.innerHTML = '';
        const existing = new Set((getCredits()[role] || []).map((c) => String(c.name || '').toLowerCase()));
        let shown = 0;
        suggestions.forEach((s) => {
            if (existing.has(String(s.name || '').toLowerCase())) { return; }   // already credited in this role
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1';
            const nm = document.createElement('span');
            nm.textContent = s.name;
            const badge = document.createElement('span');
            badge.className = 'badge bg-secondary rounded-pill';
            badge.textContent = String(s.usage || 0);
            badge.title = (s.usage || 0) + ' song(s)';
            item.append(nm, badge);
            item.addEventListener('mousedown', (ev) => {
                /* Picking a suggestion is an explicit, deliberate replace —
                   unlike the autosave path, it must win even if the box the
                   curator was searching from still has focus (preventDefault()
                   below stops the browser from blurring it on mousedown), so
                   this is the one caller that passes { force: true } into
                   saveCredit(). Also cancels any pending debounced autosave
                   for this row so a stale in-flight save can't race the pick
                   and overwrite it right back. */
                ev.preventDefault();
                hideDropdown();
                const key = role + ':' + credit._key;
                if (timers.has(key)) { clearTimeout(timers.get(key)); timers.delete(key); }
                pending.delete(key);   // #1846 — this explicit save supersedes any debounced one
                saveCredit(role, credit, { id: credit.id || 0, name: s.name }, { force: true });
            });
            dropdown.appendChild(item);
            shown++;
        });
        if (shown === 0) { hideDropdown(); return; }
        positionDropdown(input);
        dropdown.style.display = '';
    }
    function runSearch(input, role, credit) {
        /* #960 plan §4 item 5 — the search query is a TRIVIAL join of
           whatever's currently in the three boxes, not name maths: no
           comma-swap, no suffix-token recognition, nothing that could drift
           from musician_helpers.php's decomposePersonName(). It only
           has to be good enough to find matching rows server-side; the
           server's own answer is what ends up in the boxes. */
        const q = [credit.first, credit.surname, credit.suffix]
            .filter((v) => v && String(v).trim() !== '')
            .join(' ')
            .trim();
        if (q === '') { hideDropdown(); return; }
        api.searchCredits(q, 'any')
            .then((res) => showSuggestions(input, role, credit, res.suggestions || []))
            .catch(() => { /* autocomplete is best-effort */ });
    }

    function getCredits() {
        const c = store.get('credits') || {};
        ROLES.forEach(([role]) => { if (!Array.isArray(c[role])) { c[role] = []; } });
        return c;
    }

    /** True once every one of the three name boxes is blank (whitespace-only
     *  counts as blank). Used both to decide whether a debounced save is even
     *  worth sending (requirement 4) and to drive the D11 "looks deleted"
     *  affordance below. */
    function partsAllEmpty(credit) {
        return !NAME_FIELDS.some(([field]) => String(credit[field] || '').trim() !== '');
    }

    /**
     * D11 — a credit row that already exists on the server (has an `id`) and
     * has been typed down to nothing must not just quietly stop saving: that
     * looks IDENTICAL to the row having been deleted, with no way to tell
     * the two apart short of reloading the song. Surface the real Delete
     * button instead, so "get rid of this credit" and "I'm mid-retype and
     * about to put a new name in" read as two different, visible states.
     *
     * Deliberately does NOT move keyboard focus. Auto-redirecting focus the
     * instant an input empties would fire on every backspace past the last
     * character and is the kind of unannounced context change WCAG 2.1
     * Success Criterion 3.2.2 "On Input" warns against
     * (https://www.w3.org/WAI/WCAG21/Understanding/on-input.html) — jarring
     * for anyone, actively disorienting for a screen-reader or switch-access
     * user who didn't ask to be moved. A visible + programmatically-exposed
     * HIGHLIGHT (colour swap, hint text in an ARIA `status` live region, an
     * updated aria-label) satisfies "focus or highlight it" without that
     * risk; the curator's next Tab / click still reaches the button in the
     * normal tab order.
     */
    function refreshDeleteAffordance(role, credit) {
        const els = rowEls.get(credit._key);
        if (!els) { return; }
        /* `_touched` distinguishes "the curator just cleared this row" from
           "the server never sent parts for this row" (an un-migrated
           install — musicianNamePartsColumnsExist() is false, so
           first/surname/suffix are simply absent keys, not empty strings —
           see ed2_buildSongSnapshot()'s doc-block in api2.php). Without this
           flag, EVERY pre-existing credit on an un-migrated install would
           render as "looks deleted" on first paint, which is worse than the
           bug this is fixing. */
        const cleared = !!credit.id && !!credit._touched && partsAllEmpty(credit);
        els.del.classList.toggle('btn-danger', cleared);
        els.del.classList.toggle('btn-outline-danger', !cleared);
        els.del.setAttribute(
            'aria-label',
            cleared ? 'Remove — all three name fields are empty; this credit was NOT deleted' : 'Remove'
        );
        els.hint.classList.toggle('d-none', !cleared);
    }

    /**
     * Write the server's answer back into the row — id, composed name (used
     * only for the autocomplete's already-credited dedup, never displayed
     * directly), and whichever of first/surname/suffix the response carries.
     *
     * `force` is what tells this function whether the caller was a plain
     * autosave (never clobber a box the curator is actively typing in — the
     * D2/requirement-3 guarantee) or an explicit suggestion pick (the
     * curator asked for exactly this replacement, so it applies regardless
     * of focus — see the mousedown handler above).
     */
    function adoptServerCredit(role, credit, res, opts) {
        const force = !!(opts && opts.force);
        if (res.creditId) { credit.id = res.creditId; }
        if (typeof res.name === 'string') { credit.name = res.name; }
        const els = rowEls.get(credit._key);
        NAME_FIELDS.forEach(([field]) => {
            if (typeof res[field] !== 'string') { return; }
            const input = els ? els[field + 'El'] : null;
            if (input && document.activeElement === input && !force) { return; }
            credit[field] = res[field];
            if (input) { input.value = res[field]; }
        });
        refreshDeleteAffordance(role, credit);
    }

    async function saveCredit(role, credit, payloadOverride, opts) {
        const payload = payloadOverride || {
            id:      credit.id || 0,
            first:   credit.first   || '',
            surname: credit.surname || '',
            suffix:  credit.suffix  || '',
        };
        try {
            const res = await api.upsertCredit(songId, role, payload);
            adoptServerCredit(role, credit, res, opts);
        } catch (e) {
            /* Rule #35 — nothing here branches on e.message's wording; the
               only thing this tab does with a failure is show it. A future
               caller that needs to distinguish failure KINDS reads
               e.status (api-client.js's unwrap() attaches it), never the
               sentence. */
            toast('Could not save credit: ' + e.message, 'danger');
        }
    }
    function debouncedSave(role, credit) {
        const key = role + ':' + credit._key;
        if (timers.has(key)) { clearTimeout(timers.get(key)); }
        pending.set(key, { role, credit });   // #1846 — recorded so flushPending() can fire it early
        timers.set(key, setTimeout(() => { pending.delete(key); saveCredit(role, credit); }, SAVE_DEBOUNCE_MS));
    }

    /**
     * #1846 — the shell's manual Save button's hook into this tab: cancel
     * every pending per-row debounce timer and fire each recorded (role,
     * credit) save immediately instead of waiting out SAVE_DEBOUNCE_MS.
     *
     * ELI5: if you were mid-typing a name and paused for less than a second,
     * clicking Save shouldn't make you wait for the timer — this sends it
     * right now.
     *
     * @returns {Promise} Resolves once every flushed save has settled
     *   (success OR failure) — saveCredit() already catches its own rejection
     *   into a toast and resolves rather than rethrowing, so the
     *   `.catch(() => {})` here is belt-and-braces, not the normal path.
     */
    function flushPending() {
        timers.forEach((t) => clearTimeout(t));
        timers.clear();
        const proms = [];
        pending.forEach(({ role, credit }) => {
            proms.push(Promise.resolve(saveCredit(role, credit)).catch(() => {}));
        });
        pending.clear();
        return Promise.all(proms);
    }
    registerFlush(flushPending);

    async function removeCredit(role, credit) {
        const credits = getCredits();
        credits[role] = credits[role].filter((c) => c !== credit);
        store.set('credits', credits);   // re-renders
        if (credit.id) {
            try { await api.deleteCredit(songId, role, credit.id); }
            catch (e) { toast('Could not delete credit: ' + e.message, 'danger'); }
        }
    }

    function addCredit(role) {
        const credits = getCredits();
        credits[role].push({
            _key: 'k' + Date.now().toString(36) + credits[role].length,
            id: 0, name: '', first: '', surname: '', suffix: '',
        });
        store.set('credits', credits);   // re-renders; focus handled below
    }

    function render() {
        const credits = getCredits();
        if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
        hideDropdown();
        rowEls.clear();
        container.innerHTML = '';
        const row = document.createElement('div');
        row.className = 'row g-3';

        ROLES.forEach(([role, label]) => {
            const col = document.createElement('div');
            col.className = 'col-12 col-md-6';
            const card = document.createElement('div');
            card.className = 'card h-100';
            const body = document.createElement('div');
            body.className = 'card-body';

            const h = document.createElement('h3');
            h.className = 'h6 mb-2';
            h.textContent = label;
            body.appendChild(h);

            credits[role].forEach((credit) => {
                if (!credit._key) { credit._key = 'k' + (credit.id || 'n') + Math.random().toString(36).slice(2, 6); }
                const wrap = document.createElement('div');
                wrap.className = 'mb-2';
                const group = document.createElement('div');
                group.className = 'input-group input-group-sm credit-chip-row';

                const els = { del: null, hint: null };
                NAME_FIELDS.forEach(([field, labelText]) => {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control credit-name-' + field + '-input';
                    input.placeholder = labelText;
                    input.autocomplete = 'off';
                    input.value = credit[field] || '';
                    input.setAttribute('aria-label', labelText);
                    input.addEventListener('input', () => {
                        credit[field] = input.value;
                        credit._touched = true;   // see refreshDeleteAffordance()'s doc-comment
                        if (partsAllEmpty(credit)) {
                            /* Requirement 4 — never send an empty save; requirement
                               D11 — but don't just go quiet either. */
                            const key = role + ':' + credit._key;
                            if (timers.has(key)) { clearTimeout(timers.get(key)); timers.delete(key); }
                            pending.delete(key);   // #1846 — nothing left to flush for this row
                        } else {
                            debouncedSave(role, credit);
                        }
                        refreshDeleteAffordance(role, credit);
                        /* Suffix is rarely discriminative on its own, so — like the
                           legacy editor's attachStructuredCreditAutocomplete() — only
                           First/Surname drive the live-search popover. */
                        if (field !== 'suffix') {
                            if (searchTimer) { clearTimeout(searchTimer); }
                            searchTimer = setTimeout(() => runSearch(input, role, credit), SEARCH_DEBOUNCE_MS);
                        }
                    });
                    if (field !== 'suffix') {
                        input.addEventListener('blur', () => { setTimeout(hideDropdown, 150); });
                    }
                    els[field + 'El'] = input;
                    group.appendChild(input);
                });

                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'btn btn-outline-danger';
                del.title = 'Remove';
                del.setAttribute('aria-label', 'Remove');
                del.innerHTML = '<i class="bi bi-x-lg"></i>';
                del.addEventListener('click', () => removeCredit(role, credit));
                group.appendChild(del);
                els.del = del;

                wrap.appendChild(group);

                /* D11's visible surfacing for a cleared-but-still-registered row.
                   role="status" = an ARIA live region announced politely, so a
                   screen-reader user hears it the moment it appears without
                   anything having to steal focus (see refreshDeleteAffordance()'s
                   doc-comment for why focus-stealing was rejected). Starts hidden;
                   refreshDeleteAffordance() below unhides it when it applies. */
                const hint = document.createElement('div');
                hint.className = 'form-text text-danger d-none';
                hint.setAttribute('role', 'status');
                hint.textContent = 'All three fields are empty — this credit was NOT deleted. Type a name to keep it, or use Remove.';
                wrap.appendChild(hint);
                els.hint = hint;

                rowEls.set(credit._key, els);
                body.appendChild(wrap);
                refreshDeleteAffordance(role, credit);
            });

            const add = document.createElement('button');
            add.type = 'button';
            add.className = 'btn btn-sm btn-outline-primary';
            add.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add ' + label.replace(/s$/, '');
            add.addEventListener('click', () => addCredit(role));
            body.appendChild(add);

            card.appendChild(body);
            col.appendChild(card);
            row.appendChild(col);
        });

        container.appendChild(row);
    }

    const off = store.subscribe('credits', render);
    render();

    return function teardown() {
        off();
        timers.forEach((t) => clearTimeout(t));
        timers.clear();
        pending.clear();   // #1846 — no lingering references once the tab is gone
        if (searchTimer) { clearTimeout(searchTimer); }
        dropdown.remove();
        rowEls.clear();
        container.innerHTML = '';
    };
}
