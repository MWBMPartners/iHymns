/* ==========================================================================
 *  new-song-wizard.js — guided "New Song" wizard for Editor2 (#1997)
 *
 *  ELI5
 *  ----
 *  The plain "New" button (editor2.php's #v2-new-modal) asks for a
 *  songbook and a title, then drops you straight into an empty editor. This
 *  file is a SEPARATE, optional "Guided" button that walks a curator
 *  through the same job one small screen at a time — pick the book, check
 *  the number is free, add a title (+ any other names it's known by), seed
 *  a starting Verse/Chorus/Verse shape — and then, exactly like the plain
 *  button, opens the finished song in the normal editor. Nothing here is a
 *  new way to SAVE a song; it is a friendlier way to reach the same
 *  create_song/metadata_field_update/song_alt_title_add/components_replace
 *  calls the editor already makes.
 *
 *  DETAILED — WHY THIS SHAPE (CLAUDE.md modularity rule + rule #22)
 *  ----------------------------------------------------------------
 *  This module owns ZERO new server behaviour. It is pure client
 *  orchestration over api2.php actions that already exist and are already
 *  exercised elsewhere in this exact file's own manual paths:
 *    - create_song              (the plain New-song modal's #v2-new-create)
 *    - metadata_field_update    (field:'number' — editor2.php's own
 *                                 runPrefill(), the Missing-Numbers prefill)
 *    - song_alt_title_add       (alt-titles-panel.js, on an EXISTING song)
 *    - components_replace       (structure-tab.js's Paste & Reflow / import,
 *                                 which is the ONE gate onto
 *                                 lyricLinesWriteComponents() — CLAUDE.md
 *                                 rule #25: a seeded verse/chorus MUST go
 *                                 through this path, never a raw write)
 *  The one genuinely NEW server-side surface this feature needed —
 *  "is number N free in book ABC, including a slot only a hidden song
 *  holds?" — was already answered by `/api?action=missing_songs`
 *  (SongData::getMissingSongNumbers(), #285); the only addition anywhere on
 *  the server side of this feature is the ONE client helper that calls it
 *  (`missingSongNumbers()`, api-client.js). See manage/venues.php's #1995
 *  "Live Service setup" wizard for the precedent this mirrors: a guided
 *  stepper built entirely on EXISTING write actions, zero new endpoints.
 *
 *  onFinish() below deliberately MIRRORS editor2.php's own runPrefill()
 *  step for step (create -> set number -> add alt titles -> seed structure
 *  -> open): create is the one step that MUST succeed (nothing exists
 *  without it); everything after it is best-effort and NON-FATAL, because
 *  by the time an optional step could fail the song is already real and
 *  reachable — refusing to open it would just make a curator go hunting
 *  for something that is already sitting in their song list (the exact
 *  trap runPrefill's own doc-comment names). The editor is the recovery
 *  surface for a partial failure, not a second create attempt.
 *
 *  mountNewSongWizard(ctx) -> void
 *    ctx = {
 *      api,                  the SAME editorApi export api-client.js gives
 *                             every other tab (rule #31 in spirit — no raw
 *                             fetch in this file; every write goes through
 *                             an existing editorApi.* method).
 *      getSongbooks,          () => [{abbr, name}] — sidebar.getSongbooks().
 *      whenSongbooksReady,    () => Promise — sidebar.whenLoaded().
 *      findByBookAndNumber,   (abbr, n) => SongId|null — sidebar's slim-index
 *                             lookup, used ONLY for the "open it instead"
 *                             convenience link when a typed number is
 *                             already taken (the AUTHORITATIVE check is the
 *                             server's missing_songs read below — this is a
 *                             one-hop shortcut to the id, not a source of
 *                             truth about availability).
 *      addSong,               (stub) => void — sidebar.addSong(), so the
 *                             freshly-created song appears in the list
 *                             without a full index refetch (same call the
 *                             manual New-song handler + runPrefill make).
 *      loadSong,              (id) => Promise — editor2.php's own loadSong,
 *                             the function that actually opens a song in
 *                             the tabbed editor.
 *      toast, status,         the shared notification helpers editor2.php
 *                             hands to every tab.
 *    }
 *
 *  Mounted EXACTLY ONCE at editor boot — like mountSidebar(), never like
 *  mountTabs() — because #v2-new-wizard-modal is server-rendered once in
 *  editor2.php's <body>, not per open song. The modal itself is reset and
 *  re-populated fresh on every `show.bs.modal` (see resetAndOpen() below),
 *  so re-opening it always starts a brand-new, empty run — it never
 *  resumes a previous attempt.
 *
 *  @see appWeb/public_html/js/modules/admin-wizard.js         the shared stepper (#1992) — this file supplies ZERO stepper/focus/a11y logic of its own
 *  @see appWeb/public_html/manage/venues.php                  #1995's "Live Service setup" wizard — the closest analog (client orchestration, zero new endpoints, host:'bootstrap-modal')
 *  @see appWeb/public_html/manage/editor/editor2.php           runPrefill() — the exact sequence onFinish() below mirrors
 *  @see appWeb/public_html/manage/editor/api2.php               create_song / metadata_field_update / song_alt_title_add / components_replace
 *  @see appWeb/public_html/includes/SongData.php                getMissingSongNumbers() — the authoritative availability read
 *  @see https://getbootstrap.com/docs/5.3/components/modal/     Bootstrap Modal events (show.bs.modal) this file listens for
 * ========================================================================== */

import { createWizard } from '/js/modules/admin-wizard.js';
import { missingSongNumbers } from './api-client.js';
import { escapeHtml } from '/js/utils/html.js';

/* Step indexes — named rather than magic numbers wherever this file reads
   or jumps to one (wizard.goTo(STEP_NUMBER) reads a lot better than
   wizard.goTo(1)). MUST match the DOM order of the five
   [data-wiz-step] panes in editor2.php's #v2-new-wizard-modal markup —
   admin-wizard.js derives step order from that markup, never from a JS-side
   list (its own doc-block, rule #35), so these constants are a LOCAL
   convenience for readability, not a second source of truth. */
const STEP_SONGBOOK  = 0;
const STEP_NUMBER    = 1;
const STEP_TITLE     = 2;
const STEP_STRUCTURE = 3;
const STEP_REVIEW    = 4;

/**
 * Pure classification of one candidate song number against a
 * `SongData::getMissingSongNumbers()` / `missingSongNumbers()` response.
 *
 * EXPORTED so the guard (tests/php/test-new-song-wizard.php, which defers
 * this specific check to a Node truth-table per CLAUDE.md rule #34 —
 * "functional not text") can call the REAL function with REAL inputs
 * rather than pattern-matching this file's source.
 *
 * FIVE outcomes, not three, because "available" is not one thing a curator
 * needs told apart the same way:
 *   'blank'        no (usable) number was typed — legal; Number is optional
 *                   even for an official songbook (rule #44 — this is not a
 *                   vanity field, so nothing forces it).
 *   'free'         the number is a GAP inside the book's existing range
 *                   (`missing[]`) — this is what "next free" hands back
 *                   first.
 *   'beyond-max'   the number sits ABOVE the book's current highest number.
 *                   Also available, but worth saying DIFFERENTLY from a
 *                   gap: picking it EXTENDS the book rather than filling a
 *                   hole, and on a songbook with zero songs yet every
 *                   number is "beyond-max" (maxNumber starts at 0) rather
 *                   than a gap that doesn't exist yet.
 *   'hidden-held'  the number is occupied, but ONLY by a soft-deleted song
 *                   (#1829's `hiddenHeld[]`) — a curator who fills this
 *                   "looks free" slot would collide the moment that song is
 *                   ever restored. Warned, not blocked (restoring is rare
 *                   and the curator may know it never will be) — see the
 *                   RISKS note in the build plan: the (SongbookAbbr,Number)
 *                   pair is deliberately NON-unique, so a collision is a
 *                   data-quality nudge, never a corruption to guard against
 *                   with a UNIQUE constraint.
 *   'taken'        occupied by at least one LIVE song — the only outcome
 *                   that blocks the wizard's Next (see validateStep(1)
 *                   below); everything else is advisory.
 *
 * @param {number|string|null|undefined} rawNumber  Whatever the Number
 *   field currently holds — a trimmed string from the DOM, or a bare number
 *   from a test. Never assumed to already be a clean integer.
 * @param {{missing?: number[], maxNumber?: number, hiddenHeld?: number[]}} [data]
 *   One songbook's `missingSongNumbers()` response. A missing/undefined
 *   `data` degrades to "every positive integer looks beyond-max" — the
 *   CALLER (updateAvailability() below) is responsible for treating an
 *   absent/failed fetch as "cannot verify" rather than trusting that.
 * @returns {'blank'|'free'|'beyond-max'|'hidden-held'|'taken'}
 */
export function numberAvailability(rawNumber, data) {
    const isBlank = rawNumber === null || rawNumber === undefined || String(rawNumber).trim() === '';
    const num = Number(rawNumber);
    if (isBlank || !Number.isInteger(num) || num < 1) {
        return 'blank';
    }
    const d = data || {};
    const hiddenHeld = Array.isArray(d.hiddenHeld) ? d.hiddenHeld : [];
    const missing = Array.isArray(d.missing) ? d.missing : [];
    const maxNumber = Number(d.maxNumber) || 0;
    /* Order matters: a hidden-held number is a MEMBER of the occupied set
       (SongData::getMissingSongNumbers() counts a soft-deleted row as
       physically occupying its slot — @deleted-visible), so it can never
       also appear in `missing[]` and is always <= maxNumber. Checking it
       FIRST is what stops it from being misreported as plain 'taken'. */
    if (hiddenHeld.indexOf(num) !== -1) { return 'hidden-held'; }
    if (num > maxNumber) { return 'beyond-max'; }
    if (missing.indexOf(num) !== -1) { return 'free'; }
    return 'taken';
}

/** "Next free" = the first gap in the existing range, else one past the
 *  current highest — the same two-tier answer Missing Numbers itself would
 *  offer a curator working through a book in order. */
function nextFreeNumber(data) {
    const missing = Array.isArray(data && data.missing) ? data.missing : [];
    if (missing.length) { return missing[0]; }
    return (Number(data && data.maxNumber) || 0) + 1;
}

/**
 * Mount the guided wizard. See the file header for `ctx`'s full shape.
 * No-op (returns undefined) if #v2-new-wizard-modal isn't in the DOM —
 * mirrors every other "mount once" boot call's defensive shape in this
 * tree (e.g. venues.php's own `if (!modalEl) { return; }`).
 */
export function mountNewSongWizard(ctx) {
    const modalEl = document.getElementById('v2-new-wizard-modal');
    if (!modalEl) { return; }

    /* ---- element handles --------------------------------------------- */
    const songbookSelect  = document.getElementById('v2-nsw-songbook');
    const numberInput     = document.getElementById('v2-nsw-number');
    const nextFreeBtn      = document.getElementById('v2-nsw-next-free');
    const availEl          = document.getElementById('v2-nsw-avail');
    const titleInput       = document.getElementById('v2-nsw-title');
    const altTitleInput    = document.getElementById('v2-nsw-alt-title-input');
    const altTitleAddBtn   = document.getElementById('v2-nsw-alt-title-add');
    const altTitlesListEl  = document.getElementById('v2-nsw-alt-titles');
    const versesInput      = document.getElementById('v2-nsw-verses');
    const chorusCheckbox   = document.getElementById('v2-nsw-chorus');
    const bridgeCheckbox   = document.getElementById('v2-nsw-bridge');
    const reviewEl         = document.getElementById('v2-nsw-review');
    const nextBtn           = modalEl.querySelector('[data-wiz-next]');

    /* window.bootstrap is the SAME global every other modal on this page
       uses (never a module import — admin-footer.php loads Bootstrap's JS
       once, CLAUDE.md red flag against a second load). getOrCreateInstance
       (not `new Modal(...)`) because the trigger button below opens this
       modal DECLARATIVELY via data-bs-toggle/data-bs-target — Bootstrap
       itself may already have minted an instance by the time this code
       ever calls .hide() on it, and creating a SECOND one here would fight
       the first over backdrop/focus state.
       https://getbootstrap.com/docs/5.3/components/modal/#methods */
    const modalInstance = (window.bootstrap && window.bootstrap.Modal)
        ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
        : null;

    /* Per-songbook cache of the last missingSongNumbers() read, keyed by
       abbreviation. `undefined` = not fetched yet; `null` = fetch failed
       (treated as "cannot verify", never as "must be taken"); an object =
       loaded. Cleared on every modal open (resetWizardState()) so a stale
       read from a PREVIOUS run of the wizard can never silently answer a
       later one. */
    const availByAbbr = Object.create(null);
    /* Local staging list of alt-title strings — nothing is written to the
       server until Finish, since the song itself doesn't exist yet. */
    let altTitles = [];

    /* ---- alert / step-error plumbing (mirrors venues.php's own #1995
       wizard — createWizard() already renders [data-wiz-alert] for a
       FORWARD (Next) validation failure; showStepError()/clearAllAlerts()
       below are for the ONE case that isn't a forward-Next failure: a
       Finish-time availability race routed BACK to step 2 via
       wizard.goTo(), which createWizard() deliberately never validates
       (see admin-wizard.js's own goTo() doc-block). ---- */
    function showStepError(index, message) {
        const panes = modalEl.querySelectorAll('[data-wiz-step]');
        const pane = panes[index];
        if (!pane) { return; }
        const alertEl = pane.querySelector('[data-wiz-alert]');
        if (alertEl) {
            alertEl.hidden = false;
            alertEl.textContent = message;
            alertEl.focus();
        }
    }
    function clearAllAlerts() {
        modalEl.querySelectorAll('[data-wiz-alert]').forEach((el) => { el.hidden = true; el.textContent = ''; });
    }

    /* ---- Step 1: songbook -> Step 2: availability --------------------- */

    /**
     * Fetch + cache one songbook's availability read, UNLESS already
     * cached (change songbook back and forth without re-hitting the
     * server every time). `finish()` below bypasses this cache on
     * purpose for its own re-check — see that function's comment.
     */
    async function ensureAvailLoaded(abbr) {
        if (!abbr || Object.prototype.hasOwnProperty.call(availByAbbr, abbr)) {
            updateAvailability();
            return;
        }
        try {
            availByAbbr[abbr] = await missingSongNumbers(abbr);
        } catch (_e) {
            availByAbbr[abbr] = null;   // "could not verify" — never treated as 'taken'
        }
        updateAvailability();
    }

    function renderAvailability(status, raw, abbr, data) {
        availEl.hidden = false;
        availEl.className = 'small';
        let message = '';
        let offerOpen = false;
        if (status === 'free') {
            availEl.classList.add('text-success-emphasis');
            message = 'Song ' + raw + ' is free in ' + abbr + '.';
        } else if (status === 'beyond-max') {
            availEl.classList.add('text-success-emphasis');
            message = (data && data.maxNumber)
                ? ('Song ' + raw + ' is free — it will be the new highest number in ' + abbr + ' (currently up to ' + data.maxNumber + ').')
                : ('Song ' + raw + ' is free — it will be the first numbered song in ' + abbr + '.');
        } else if (status === 'hidden-held') {
            availEl.classList.add('text-warning-emphasis');
            message = 'Song ' + raw + ' is occupied by a hidden (deleted) song in ' + abbr
                + ' — restore or purge it at /manage/deleted-songs, or pick a different number.';
        } else if (status === 'taken') {
            availEl.classList.add('text-danger-emphasis');
            message = 'Song ' + raw + ' already exists in ' + abbr + '.';
            offerOpen = true;
        } else {
            availEl.hidden = true;
            return;
        }
        availEl.textContent = message + (offerOpen ? ' ' : '');
        if (offerOpen) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary ms-1';
            btn.textContent = 'Open it instead';
            btn.addEventListener('click', () => {
                const existingId = ctx.findByBookAndNumber(abbr, raw);
                if (!existingId) { return; }
                if (modalInstance) { modalInstance.hide(); }
                ctx.loadSong(existingId);
            });
            availEl.appendChild(btn);
        }
    }

    function updateAvailability() {
        const abbr = songbookSelect.value;
        const raw = numberInput.value.trim();
        if (raw === '') { availEl.hidden = true; availEl.textContent = ''; return; }
        if (!Object.prototype.hasOwnProperty.call(availByAbbr, abbr)) {
            availEl.hidden = false;
            availEl.className = 'small text-secondary';
            availEl.textContent = 'Checking availability…';
            return;
        }
        const data = availByAbbr[abbr];
        if (data === null) {
            availEl.hidden = false;
            availEl.className = 'small text-secondary';
            availEl.textContent = 'Could not check availability right now — you can still continue.';
            return;
        }
        renderAvailability(numberAvailability(raw, data), raw, abbr, data);
    }

    /* Debounced DISPLAY-ONLY re-classification on every keystroke — no
       network call here (the fetch already happened once per songbook
       selection, above), and no WRITE of any kind, per rule #43's
       "never a debounced keystroke side effect". The debounce exists only
       to stop the aria-live-ish availability line re-announcing on every
       single keypress while typing a multi-digit number. */
    let debounceTimer = null;
    numberInput.addEventListener('input', () => {
        if (debounceTimer) { clearTimeout(debounceTimer); }
        debounceTimer = setTimeout(updateAvailability, 200);
    });
    nextFreeBtn.addEventListener('click', () => {
        const abbr = songbookSelect.value;
        const data = availByAbbr[abbr];
        if (!data) { return; }   // not loaded / failed — nothing to compute from
        numberInput.value = String(nextFreeNumber(data));
        updateAvailability();
    });
    songbookSelect.addEventListener('change', () => {
        numberInput.value = '';
        availEl.hidden = true;
        availEl.textContent = '';
        ensureAvailLoaded(songbookSelect.value);
    });

    /**
     * Populate Step 1's <select> from the SAME songbook catalogue the
     * toolbar's own New-song modal uses (sidebar.getSongbooks(), which
     * includes zero-song books — #1679 A2), EXCLUDING the hidden staging
     * book a Duplicate (#1783) lands in — read from load_index's own
     * `pendingSongbook` key so this file never hardcodes that abbreviation
     * (rule #35). The manual New-song modal does NOT filter it out today;
     * that's a separate, smaller fix tracked outside this change (see the
     * build plan's RISKS note) — this wizard simply doesn't repeat it.
     *
     * `ctx.getSongbooks()`/`ctx.whenSongbooksReady()` give the LIST;
     * `pendingSongbook` isn't part of that shape (it's sidebar-internal),
     * so this reads it straight off `ctx.api.loadIndex()` — the SAME
     * editorApi method the sidebar itself calls (no new fetch, no new
     * api2 action; just the existing client used a second time).
     */
    async function populateSongbookSelect() {
        songbookSelect.innerHTML = '<option value="">Loading…</option>';
        await ctx.whenSongbooksReady();
        let pending = '';
        try {
            const idx = await ctx.api.loadIndex();
            pending = idx.pendingSongbook || '';
        } catch (_e) { /* best-effort — if this fails, nothing is excluded */ }
        const books = ctx.getSongbooks().filter((b) => b.abbr !== pending);
        songbookSelect.innerHTML = '';
        if (!books.length) {
            const o = document.createElement('option');
            o.value = ''; o.textContent = '(song list still loading…)';
            songbookSelect.appendChild(o);
        } else {
            books.forEach((b) => {
                const o = document.createElement('option');
                o.value = b.abbr;
                o.textContent = b.name + ' (' + b.abbr + ')';
                songbookSelect.appendChild(o);
            });
        }
        if (songbookSelect.value) { ensureAvailLoaded(songbookSelect.value); }
    }

    /* ---- Step 3: title + alt titles ------------------------------------ */

    function renderAltTitles() {
        altTitlesListEl.innerHTML = '';
        altTitles.forEach((t, i) => {
            const li = document.createElement('li');
            li.className = 'badge text-bg-light border fw-normal d-inline-flex align-items-center gap-1';
            const span = document.createElement('span');
            span.textContent = t;
            const rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'btn-close';
            rm.style.fontSize = '0.55rem';
            rm.setAttribute('aria-label', 'Remove alternative title "' + t + '"');
            rm.addEventListener('click', () => { altTitles.splice(i, 1); renderAltTitles(); });
            li.append(span, rm);
            altTitlesListEl.appendChild(li);
        });
    }
    function addAltTitleFromInput() {
        const v = altTitleInput.value.trim();
        if (!v) { return; }
        if (altTitles.some((t) => t.toLowerCase() === v.toLowerCase())) { altTitleInput.value = ''; return; }
        altTitles.push(v);
        altTitleInput.value = '';
        renderAltTitles();
        altTitleInput.focus();
    }
    altTitleAddBtn.addEventListener('click', addAltTitleFromInput);
    altTitleInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); addAltTitleFromInput(); }
    });

    /* ---- Step 4: starting structure ------------------------------------
       Built in structure-tab.js's OWN client component shape
       ({type,number,sortOrder,lines,chords,language} — see that file's
       addComponent()) so components_replace's normalise step treats a
       wizard-seeded section identically to one added by hand on the
       Structure tab afterwards. Order: Verse 1, Chorus (if enabled),
       Verse 2..N, then Bridge (if enabled) last — a plain, common shape a
       curator can freely reorder/add to once the song is open (rule #44:
       this is a STARTING point, not a structure editor of its own).
       Chorus/Bridge get Number 0 (never 1) — the wizard only ever seeds
       ONE of each, and 0 is what real song data uses for an unnumbered
       single section (tests/fixtures/synthetic-songs.json's own chorus
       rows) — structure-tab.js's derivedComponentName() only appends a
       number when it's truthy, so 0 renders as a clean "Chorus"/"Bridge"
       heading rather than a misleading "Chorus 1". */
    function buildSeedComponents() {
        const verseCount = Math.max(0, Math.min(10, parseInt(versesInput.value, 10) || 0));
        const withChorus = !!chorusCheckbox.checked;
        const withBridge = !!bridgeCheckbox.checked;
        const seed = [];
        let sortOrder = 0;
        const push = (type, number) => {
            seed.push({ type: type, number: number, sortOrder: sortOrder++, lines: [''], chords: null, language: null });
        };
        if (verseCount >= 1) { push('verse', 1); }
        if (withChorus) { push('chorus', 0); }
        for (let v = 2; v <= verseCount; v++) { push('verse', v); }
        if (withBridge) { push('bridge', 0); }
        return seed;
    }

    /* ---- Step 5: review -------------------------------------------------- */

    /**
     * The "Type Number" heading one seed section would show — same
     * derivation as structure-tab.js's own `derivedComponentName()`
     * (#1860 Phase 5 §4.2), mirrored here rather than imported because
     * that function isn't exported and this review line is the ONE
     * consumer on this side. Custom-first: a seed component here never
     * carries a `.label` today (the wizard doesn't offer custom labels —
     * rule #44, nothing collected that isn't used yet), but honouring it
     * anyway keeps this render site in step with rule #45's "every
     * display site is custom-first or it half-ships" — the SAME
     * "structural deriver" shape structure-tab.js's `headerText()` and
     * every other component-heading site in the tree follow
     * (tests/test-component-label-sites.js, tree-derived, is what would
     * catch a future site here that forgot this).
     */
    function seedComponentLabel(s) {
        if (s.label && String(s.label).trim()) { return String(s.label).trim(); }
        return (s.type || 'verse').replace(/^\w/, (c) => c.toUpperCase()) + (s.number ? ' ' + s.number : '');
    }
    function structureSummary(seed) {
        if (!seed.length) { return '<span class="text-secondary">none — add sections on the Structure tab</span>'; }
        return escapeHtml(seed.map(seedComponentLabel).join(', '));
    }
    function updateReview() {
        const abbr = songbookSelect.value;
        const book = ctx.getSongbooks().find((b) => b.abbr === abbr);
        const bookLabel = book ? (book.name + ' (' + abbr + ')') : abbr;
        const num = numberInput.value.trim();
        const title = titleInput.value.trim() || 'New Song';
        let rows = '';
        rows += '<dt class="col-sm-4">Songbook</dt><dd class="col-sm-8">' + escapeHtml(bookLabel) + '</dd>';
        rows += '<dt class="col-sm-4">Number</dt><dd class="col-sm-8">' + (num ? escapeHtml(num) : '<span class="text-secondary">not set</span>') + '</dd>';
        rows += '<dt class="col-sm-4">Title</dt><dd class="col-sm-8">' + escapeHtml(title) + '</dd>';
        if (altTitles.length) {
            rows += '<dt class="col-sm-4">Also known as</dt><dd class="col-sm-8">' + escapeHtml(altTitles.join(', ')) + '</dd>';
        }
        rows += '<dt class="col-sm-4">Starting structure</dt><dd class="col-sm-8">' + structureSummary(buildSeedComponents()) + '</dd>';
        reviewEl.innerHTML = rows;
    }

    /* ---- the wizard itself ----------------------------------------------- */
    const LAST_STEP = modalEl.querySelectorAll('[data-wiz-step]').length - 1;

    const wizard = createWizard(modalEl, {
        /* Bootstrap's OWN Modal instance already supplies the focus trap /
           Escape / focus-restore for this dialog (editor2.php loads
           Bootstrap JS once via admin-footer.php and already drives every
           other modal on this page the same way) — 'overlay' would lazily
           pull in dialog-a11y.js and double-trap focus, which
           admin-wizard.js's own doc-block explicitly warns against. */
        host: 'bootstrap-modal',
        validateStep(index) {
            if (index === STEP_SONGBOOK) {
                if (!songbookSelect.value) {
                    return { ok: false, message: 'Choose a songbook.', focus: songbookSelect };
                }
                return true;
            }
            if (index === STEP_NUMBER) {
                const raw = numberInput.value.trim();
                if (raw === '') { return true; }   // optional — blank always passes
                const abbr = songbookSelect.value;
                const data = availByAbbr[abbr];
                if (!data) { return true; }   // couldn't verify — fail open, never block on an unknown
                if (numberAvailability(raw, data) === 'taken') {
                    return {
                        ok: false,
                        message: 'Song ' + raw + ' already exists in ' + abbr + '. Pick a different number, or use "Open it instead" below.',
                        focus: numberInput,
                    };
                }
                return true;
            }
            if (index === STEP_TITLE) {
                const title = titleInput.value.trim();
                if (!title) { return { ok: false, message: 'Title is required.', focus: titleInput }; }
                if (title.length > 500) { return { ok: false, message: 'Title must be 500 characters or fewer.', focus: titleInput }; }
                return true;
            }
            if (index === STEP_STRUCTURE) {
                const v = parseInt(versesInput.value, 10);
                if (Number.isNaN(v) || v < 0 || v > 10) {
                    return { ok: false, message: 'Verses must be between 0 and 10.', focus: versesInput };
                }
                return true;
            }
            if (index === STEP_REVIEW) {
                updateReview();
                return true;
            }
            return true;
        },
        onStepChange(_from, to) {
            if (nextBtn) { nextBtn.textContent = (to === LAST_STEP) ? 'Create' : 'Next'; }
            if (to === STEP_NUMBER) { ensureAvailLoaded(songbookSelect.value); }
            if (to === STEP_REVIEW) { updateReview(); }
        },
        onFinish: finish,
    });

    /**
     * onFinish — mirrors editor2.php's own runPrefill() step for step:
     *   re-check availability -> createSong -> set number (if any) ->
     *   add alt titles (if any) -> seed structure (if any) -> add to the
     *   sidebar -> open it.
     *
     * create_song is the ONE step that can abort the whole thing (nothing
     * exists yet if it fails). Every step AFTER it is best-effort: the
     * song is already real by then, so a failure there is reported and
     * the song is opened anyway — the editor's own tabs (Metadata,
     * Structure) are the recovery surface, exactly as runPrefill's own
     * "Created X but could not set number N — set it on the Metadata tab"
     * message already establishes for this exact editor. Reported via
     * ctx.toast() (which stacks rather than overwrites, unlike the
     * persistent #v2-status strip loadSong() itself immediately rewrites)
     * so a curator can see EVERY partial failure, not just whichever one
     * happened to be shown last.
     */
    async function finish() {
        if (nextBtn) { nextBtn.disabled = true; }
        clearAllAlerts();

        const abbr = songbookSelect.value;
        const rawNum = numberInput.value.trim();
        const num = rawNum === '' ? null : parseInt(rawNum, 10);
        const title = titleInput.value.trim() || 'New Song';
        const seed = buildSeedComponents();
        const altTitlesToAdd = altTitles.slice();

        try {
            /* Finish-time re-check, BYPASSING the per-songbook cache on
               purpose: the wizard may have sat open for a while, and the
               (SongbookAbbr,Number) pair is deliberately non-unique
               (rule: never add a UNIQUE constraint here) — a race is a
               data-quality nudge to catch before minting, not a
               corruption to prevent after the fact. A failed re-check
               fails OPEN (proceeds) rather than blocking on an unknown,
               matching validateStep(1)'s own "couldn't verify" posture. */
            if (num !== null) {
                let fresh = null;
                try {
                    fresh = await missingSongNumbers(abbr);
                    availByAbbr[abbr] = fresh;
                } catch (_e) { /* couldn't verify — proceed */ }
                if (fresh && numberAvailability(num, fresh) === 'taken') {
                    wizard.goTo(STEP_NUMBER);
                    showStepError(STEP_NUMBER, 'Song ' + num + ' now exists in ' + abbr
                        + ' — it may have just been created. Pick a different number, or use "Open it instead" below.');
                    updateAvailability();
                    return;
                }
            }

            const res = await ctx.api.createSong(abbr, title);

            if (num !== null) {
                try {
                    await ctx.api.updateMetadata(res.songId, 'number', num);
                } catch (e) {
                    ctx.toast('Created ' + res.songId + ' but could not set number ' + num
                        + ': ' + e.message + ' — set it on the Metadata tab.', 'danger');
                }
            }
            for (const t of altTitlesToAdd) {
                try {
                    await ctx.api.addAltTitle(res.songId, t, '', '');
                } catch (e) {
                    ctx.toast('Created ' + res.songId + ' but could not add the alternative title "' + t
                        + '": ' + e.message, 'danger');
                }
            }
            if (seed.length) {
                try {
                    await ctx.api.replaceComponents(res.songId, seed, 'replace');
                } catch (e) {
                    ctx.toast('Created ' + res.songId + ' but could not seed the starting structure: '
                        + e.message + ' — add sections on the Structure tab.', 'danger');
                }
            }

            const book = ctx.getSongbooks().find((b) => b.abbr === res.songbook);
            ctx.addSong({
                id: res.songId, number: num, title: res.title,
                songbook: res.songbook, songbookName: book ? book.name : res.songbook,
            });

            if (modalInstance) { modalInstance.hide(); }
            ctx.loadSong(res.songId);   // ALWAYS — the editor is the recovery surface for any warning above
        } catch (e) {
            /* create_song ITSELF failed — nothing was made. Stay on the
               Review step (rather than the modal closing on a failure)
               so the curator can retry without re-entering steps 1-4. */
            showStepError(STEP_REVIEW, 'Could not create the song: ' + e.message);
        } finally {
            if (nextBtn) { nextBtn.disabled = false; }
        }
    }

    /* Reopening the wizard always starts a fresh, empty run — never resumes
       a previous attempt's in-memory state (mirrors venues.php's own
       #1995 wizard's identical reset-on-open posture). */
    function resetWizardState() {
        clearAllAlerts();
        numberInput.value = '';
        availEl.hidden = true; availEl.textContent = '';
        titleInput.value = '';
        altTitles = [];
        renderAltTitles();
        altTitleInput.value = '';
        versesInput.value = '3';
        chorusCheckbox.checked = true;
        bridgeCheckbox.checked = false;
        Object.keys(availByAbbr).forEach((k) => { delete availByAbbr[k]; });
        if (nextBtn) { nextBtn.textContent = 'Next'; nextBtn.disabled = false; }
        wizard.goTo(STEP_SONGBOOK);
    }

    modalEl.addEventListener('show.bs.modal', () => {
        resetWizardState();
        populateSongbookSelect();
    });
}
