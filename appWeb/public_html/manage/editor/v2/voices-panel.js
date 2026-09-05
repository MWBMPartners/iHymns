/* ==========================================================================
 *  voices-panel.js — the "Who sings" panel for the v2 Song Editor's
 *  Structure tab (#2073 commit 7, plan .claude/vocal-parts-2073-plan.md
 *  "Design pass 4" §4 as corrected by "Design pass 7")
 *
 *  ELI5: a little drawer under each section (verse/chorus/…), right next to
 *  the language/translation drawer, where a curator says WHO sings each
 *  line — the men, the women, everyone, a named soloist, an echo of just a
 *  few words — and can set up a round/canon where different voices come in
 *  one after another. It is this feature's front door: everything it does
 *  is a thin call into the eight `api2.php` actions #2073 commit 6 already
 *  shipped (`vocal_part_upsert`, `vocal_part_delete`, `vocal_lines_assign`,
 *  `vocal_lines_clear`, `vocal_span_upsert`, `vocal_span_delete`,
 *  `round_upsert`, `round_delete`) — this file writes NO SQL and holds NO
 *  business rule the server doesn't already enforce (rule #22); every
 *  response is read back WHOLESALE into the shared store (rule #35), so a
 *  server-side fold (a `'replace'` clearing a sibling voice, a hide-when-
 *  equal label fold, …) is always what ends up on screen.
 *
 *  THE OWNER'S ENTRY GRAIN, AND WHY (task brief, "pick a selection
 *  mechanism and justify it"): the owner's own example is MP-0120's verse —
 *  WOMEN(2)/MEN(2)/WOMEN(2)/MEN(2)/ALL(6) lines — where per-line clicking
 *  would be six separate round-trips for something that is really THREE
 *  decisions (women sing these, men sing those, everyone sings the rest).
 *  This panel therefore selects a RUN of lines via CHECKBOXES, one per
 *  line, with:
 *    - a plain click/Space toggle on any one checkbox (always works, no
 *      chording, the one thing every input method — mouse, keyboard,
 *      switch access, screen reader — already knows how to do), and
 *    - Shift-click / Shift+Space as a PROGRESSIVE ENHANCEMENT that extends
 *      the selection from the last-touched line to the one just clicked —
 *      the same "anchor and extend" convention a desktop file manager or a
 *      spreadsheet uses, so a sighted mouse user or a keyboard user who
 *      already knows that convention gets the six-line MP-0120 verse in
 *      TWO gestures (tick line 1, Shift-click line 4) instead of six.
 *  Checkboxes were chosen over a drag-select (would need its own
 *  mouse-only reimplementation of exactly what native controls already
 *  give for free, and drags are a known WCAG 2.5.1 "pointer gestures"
 *  hazard — https://www.w3.org/WAI/WCAG21/Understanding/pointer-gestures.html)
 *  and over Shift-click as the ONLY mechanism (the #2073 plan's own risk
 *  note admits "Shift+Space on a checkbox is not a native idiom; some
 *  screen readers intercept Space" — so the PLAIN toggle must always be
 *  the reliable floor, with the range gesture layered on top, never
 *  required).
 *
 *  Checked `js/modules/combobox-a11y.js` before writing any of this file's
 *  own keyboard handling, per the task brief ("use it"). It is NOT a fit
 *  here and is deliberately NOT imported: its own doc-comment states its
 *  scope exactly — a SINGLE-select autocomplete/listbox pattern (one
 *  "active" row, committed via Enter/Tab, closed via Escape) for a
 *  search-as-you-type dropdown. This panel's line list is a MULTI-select
 *  checkbox GROUP (WAI-ARIA APG "Checkbox" pattern, several boxes checked
 *  at once, no dropdown, no "active" row to commit) and its word-span
 *  picker is a set of MULTI-select TOGGLE buttons (the APG "Button, toggle"
 *  pattern, `aria-pressed`) — neither shape is what
 *  `handleComboboxKeydown()`/`applyComboboxAria()` implement, and forcing
 *  either through it would paint `role="option"`/`aria-selected`
 *  (single-select semantics) onto controls that are genuinely
 *  multi-select, which is a WORSE a11y outcome than plain native
 *  checkboxes/buttons, not a better one. (Consistent with the rest of this
 *  very file: `structure-tab.js`'s own "Source work" picker, right above
 *  where this panel is appended, ALSO doesn't import combobox-a11y.js — it
 *  relies on `place-search.js`'s own self-contained ARIA combobox instead,
 *  the one genuine autocomplete this editor has.)
 *  @see https://www.w3.org/WAI/ARIA/apg/patterns/checkbox/
 *  @see https://www.w3.org/WAI/ARIA/apg/patterns/button/#toggle_button
 *
 *  THE PART PICKER IS A PICKER OVER THE VOCABULARY (rule #43) — never a
 *  free-text box into `tblVocalParts`. `resolveKinds()` below reads
 *  `window._iHymnsVocalPartKinds`, which `editor2.php` serves straight
 *  from the PHP core's own `vocalPartsKindsProjection()` — this file
 *  contains no second, hand-typed copy of the 21-kind list (guarded by
 *  `tests/test-v2-voices-ui.js`, mirroring the enrichment panel's own
 *  `LINE_TRANSLATION_KINDS`/`LINE_ANNOTATION_TYPES` lockstep). The ONE
 *  vocabulary member with no fixed word at all — `named-singer` — still
 *  never becomes a free-text box into the vocabulary itself; it is a
 *  free-text NAME for a person, exactly as `tblVocalParts.SingerName`
 *  (a real, first-class column sitting beside the `MusicianId` FK) already
 *  models it. See "NAMED SINGER" below for why this file does not attempt
 *  a live musician-ID search.
 *
 *  ECHO: a whole line's echo/background flag rides the SAME
 *  `vocal_lines_assign`/`vocal_lines_clear` calls as a lead-voice
 *  assignment (an `isBackground` flag on the row, `tblLyricLineVocalParts`
 *  — #1137). A PHRASE inside one line is a SUB-LINE SPAN
 *  (`vocal_span_upsert`/`_delete`, `tblLyricLineVocalSpans`, code-point
 *  offsets, rule #21) — its own `isBackground` flag marks a phrase-level
 *  echo. Both are exposed by this ONE panel; see "WORD-GRAIN SPANS" below.
 *
 *  AUTO-SAVE / D3 (owner decision, task brief): a `tblLyricLines.Id` does
 *  not exist until the section has been saved at least once. See
 *  `ensureAndResolveIds()` below for the exact four-case handling this
 *  commit's brief calls out by name (a just-added section whose create
 *  has not resolved, lines typed inside the debounce window, a failed
 *  save, and a pre-migration install) — each is a code comment right next
 *  to the branch that handles it, not a general essay here.
 *
 *  NAMED SINGER — a flagged, deliberate scope decision: the "Design pass 4"
 *  draft this task names sketches a live `credit_search`-backed musician
 *  picker for this control. Checked against the REAL, shipped
 *  `credit_search` action (api2.php) before writing any of this: it
 *  returns `{name, usage, kinds}` — union-deduplicated NAMES across every
 *  song-credit table plus `tblMusicians`, with NO `Id` in the response at
 *  all. There is no existing endpoint this file is allowed to call (the
 *  task's file list does not include api2.php) that resolves a typed name
 *  to a real `tblMusicians.Id`. `vocalPartsUpsert()`'s own validation
 *  (`vocalPartsValidateNamedSingerInputs()`) already treats a plain typed
 *  `singerName` as fully legitimate on its own — `MusicianId` is an
 *  OPTIONAL enrichment, not a requirement — so this control is a plain,
 *  clearly labelled text input for the singer's name. Wiring a picker to
 *  an endpoint that cannot actually return an id would be theatre, not a
 *  fix; a REAL id-returning musician search is a natural, separate,
 *  trivially-addable follow-up (flagged in this commit's own report) once
 *  such an endpoint exists — it would slot in here as a straight
 *  `window.iHymnsPlaceSearch.attach({pickMode:'value', ...})` call exactly
 *  like `structure-tab.js`'s own "Source work" input two panels up.
 *
 *  CLIENT-SIDE PART DEDUPE — another flagged, load-bearing gap this file
 *  works around rather than silently accepting: `vocal_part_upsert`'s
 *  create branch (`vocalPartsUpsert()` with no `id`) always INSERTs a new
 *  `tblVocalParts` row — it does NOT dedupe against an existing part of
 *  the same kind the way the sibling function `vocalPartsFindOrCreate()`
 *  does (both live in `includes/vocal_parts.php`; no api2 action calls the
 *  latter). Without a client-side guard, ticking a second run of lines and
 *  picking "Add a new part → Women" a second time would mint a SECOND
 *  "Women" part for the same song, splitting one voice's lines across two
 *  rows with no error anywhere — silent, and exactly the kind of thing
 *  rule #43 exists to prevent. `findExistingPartForKind()` below reuses an
 *  existing part (matched the same way the server's own
 *  `vocalPartsFindOrCreate()` would) BEFORE ever calling
 *  `api.upsertVocalPart()`, so the common "assign Women again" gesture
 *  never mints a duplicate. This is a client-side patch over a real gap in
 *  the shipped write core, not a substitute for fixing it there — flagged
 *  loudly in this commit's own report as a follow-up (route
 *  `vocal_part_upsert`'s no-id branch through `vocalPartsFindOrCreate()`
 *  server-side, which also closes the same race between two concurrent
 *  editors that no CLIENT-side check ever can).
 *
 *  buildVoicesPanel(comp, ctx) -> { el, refresh(), destroy() }
 *    comp : one row from the store's `components` slice (structure-tab.js's
 *           own object — `comp.lines`/`comp.lineIds` are read here, never
 *           mutated; this panel owns no field on `comp` itself).
 *    ctx  : { store, api, songId, toast, ensureSaved, hasPendingSave, onSaved }
 *           store          — the shared reactive store (store.js). Reads/
 *                            writes the `vocalParts` slice (the WHOLE
 *                            song's parts/lineAssignments/spans/rounds,
 *                            `includes/vocal_parts.php`'s
 *                            `vocalPartsForSong()` shape) and subscribes to
 *                            it so every mounted card's panel — the parts
 *                            legend especially, which is song-wide — stays
 *                            in step with every OTHER card's writes.
 *           api            — editorApi (api-client.js); the eight
 *                            vocal/round methods.
 *           songId         — the server SongId.
 *           toast          — (message, type) => void.
 *           ensureSaved    — structure-tab.js's `ensureSaved(comp)`:
 *                            Promise<boolean>, flushes this ONE component's
 *                            pending debounced `component_upsert` right
 *                            now (never a whole-song save) and resolves
 *                            whether it landed.
 *           hasPendingSave — structure-tab.js's `hasPendingSave(comp)`:
 *                            true while a lyric/chord edit on this SAME
 *                            component is still sitting inside its
 *                            debounce window.
 *           onSaved        — structure-tab.js's `(fn) => unregisterFn`:
 *                            calls `fn` the next time THIS component saves
 *                            successfully, from ANY path (the debounce, a
 *                            manual Save-button flush, or this panel's own
 *                            `ensureSaved`) — the mechanism the D3 "queued,
 *                            not lost" retry below is built on.
 *
 *  RENDER LIFECYCLE: mirrors `enrichment-panel.js`'s own note — this panel
 *  manages its own local `renderBody()` re-render (on a store write, a
 *  selection change, or `refresh()`) without needing `structure-tab.js` to
 *  rebuild the whole card, exactly because a `comp`/store-slice mutation
 *  in place never fires that file's `store.subscribe('components', render)`.
 * ========================================================================== */

import { componentLineId } from './enrichment-panel.js';
import { iconBtn } from './ui-helpers.js';

/**
 * The SMALLEST usable fallback kind set — the `structure-tab.js`
 * `COMPONENT_TYPES`/`window._iHymnsSongPartTypes` posture applied to this
 * feature's own vocabulary: used only when `window._iHymnsVocalPartKinds`
 * is missing or empty (an un-migrated install — `editor2.php` serves `[]`
 * then on purpose, see that file's own comment), so the panel's controls
 * still render something sensible rather than an empty `<select>`. On a
 * migrated install the REAL, full 21-kind list always wins (`resolveKinds()`
 * below) — this is never shown alongside real data.
 *
 * `tests/test-v2-voices-ui.js` asserts this file contains NO OTHER kind-key
 * string literal anywhere outside this block and the two `=== 'named-singer'`
 * / `=== 'group'` comparisons the two kinds needing extra input require
 * (rule #43 — a hardcoded second copy of the vocabulary is exactly the
 * regression this guards).
 */
const FALLBACK_KINDS = [
    { key: 'lead', label: 'Lead' },
    { key: 'male', label: 'Men' },
    { key: 'female', label: 'Women' },
    { key: 'all', label: 'All' },
    { key: 'backing', label: 'Backing' },
];

/** The empty `vocalPartsForSong()` shape — used before the store has ever
 *  been hydrated (`store.get('vocalParts')` is `null` until `loadSong()`
 *  runs) and as the safe fallback if a caller somehow hands this panel a
 *  malformed slice. Keys match `includes/vocal_parts.php`'s own
 *  `VOCAL_PARTS_PAYLOAD_KEYS` constant exactly — parsed and checked by
 *  `tests/test-v2-voices-ui.js`, never hand-duplicated as a second literal
 *  list a future rename could silently leave stale. */
const EMPTY_VOCAL_PARTS = {
    ready: false, spansReady: false, roundsReady: false, lyricsId: null,
    parts: [], lineAssignments: {}, spans: {}, rounds: [],
};

/** window._iHymnsVocalPartKinds, or the minimal fallback above. Rule #35/
 *  #43 — the vocabulary is served from the ONE PHP constant
 *  (`IHYMNS_VOCAL_PART_KINDS`, via `vocalPartsKindsProjection()`), never
 *  typed a second time here. */
function resolveKinds() {
    const s = window._iHymnsVocalPartKinds;
    return (Array.isArray(s) && s.length) ? s : FALLBACK_KINDS;
}

/* Unique id source for this panel's own <label for>/id pairs and toggle
   aria-controls — module-scoped (not per-panel) so two cards' panels open
   on the same page never collide on one id, mirroring enrichment-panel.js's
   own ietfPickerSeq/enrichFormSeq counters one file over. */
let panelSeq = 0;

/** A validation problem the CURATOR needs to fix before anything is sent to
 *  the server (e.g. "pick or type the singer's name") — distinct from a
 *  server-thrown error so onAssign() can show it inline without pretending
 *  a network call was ever made. */
class VoicesUiError extends Error {}

/** smallBtn(label, onClick) — matches enrichment-panel.js's identical
 *  helper exactly (a compact inline text button); duplicated rather than
 *  imported because enrichment-panel.js does not export it and this is a
 *  three-line, framework-free DOM builder, not a business rule (the
 *  modularity rule targets duplicated LOGIC/markup-with-behaviour, not
 *  every three-line `document.createElement` convenience). */
function smallBtn(label, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-sm btn-outline-secondary py-0 px-1 me-1';
    b.textContent = label;
    b.addEventListener('click', onClick);
    return b;
}

/** True when `err` is the "vocal-part tables aren't migrated on this
 *  install" 409 every one of the eight api2 actions answers with — the
 *  signal for a calm notice, never a red failure toast (mirrors
 *  enrichment-panel.js's identical `isEnrichmentUnmigrated()`, branching
 *  on the STATUS CODE per rule #35, never the server's wording). */
function isVocalUnmigrated(err) {
    return !!err && err.status === 409;
}

/**
 * Split a line's text into whitespace-separated word TOKENS, each carrying
 * its own 0-based, end-exclusive UTF-8 CODE-POINT offsets (rule #21) — the
 * exact shape `vocal_span_upsert`'s `start`/`end` need. `Array.from(text)`
 * iterates by code point (not UTF-16 code unit), so this is correct for
 * text outside the Basic Multilingual Plane (emoji, some scripts) the same
 * way `mb_substr`/`mb_strlen` are correct on the PHP side.
 * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/Symbol.iterator
 * @param {string} text
 * @returns {Array<{start:number, end:number, text:string}>}
 */
function lineWordTokens(text) {
    const cps = Array.from(text || '');
    const tokens = [];
    let i = 0;
    while (i < cps.length) {
        while (i < cps.length && /\s/.test(cps[i])) { i++; }
        if (i >= cps.length) { break; }
        const start = i;
        while (i < cps.length && !/\s/.test(cps[i])) { i++; }
        tokens.push({ start: start, end: i, text: cps.slice(start, i).join('') });
    }
    return tokens;
}

/** The exact code-point substring a span's `{start,end}` names — used to
 *  show a human-readable "on “holy, holy”" phrase rather than a bare
 *  offset pair. Code-point slicing (rule #21), matching `lineWordTokens()`. */
function codePointSlice(text, start, end) {
    return Array.from(text || '').slice(start, end).join('');
}

/**
 * buildVoicesPanel(comp, ctx) — see the file header for the full contract.
 * Returns a collapsible wrapper `<div>`; the caller (`structure-tab.js`'s
 * `buildCard()`) appends it into the component card body, right after the
 * enrichment panel.
 */
export function buildVoicesPanel(comp, ctx) {
    const { store, api, songId } = ctx;
    const toast = ctx.toast || function () {};
    const ensureSaved = ctx.ensureSaved || function () { return Promise.resolve(!!comp.id); };
    const hasPendingSave = ctx.hasPendingSave || function () { return false; };
    const onSaved = ctx.onSaved || function () { return function off() {}; };

    const uid = 'v2-voices-' + (++panelSeq);

    /* ---- panel-instance state (persists across renderBody() calls) ---- */
    let selected = new Set();      // 0-based indexes into comp.lines currently ticked
    let anchor = -1;                // last-touched index, for Shift-range
    let pressedWords = new Set();   // word-token indexes ticked in the span picker (only meaningful when selected.size === 1)
    let pending = null;             // a queued assign/span action waiting on THIS component's next successful save (D3)
    let offPendingSaveListener = null;
    let roundForm = null;           // {editingId, kind, label, timesThrough, endingMode, startLineId, endLineId, codaStartLineId, codaEndLineId, voices:[...]} | null
    let inlineAlertText = '';

    /* ---- small store/shape helpers ---- */
    function vp() {
        const v = store.get('vocalParts');
        return (v && typeof v === 'object') ? v : EMPTY_VOCAL_PARTS;
    }
    function partsById() {
        const m = {};
        (vp().parts || []).forEach((p) => { m[p.id] = p; });
        return m;
    }
    function partLabel(id) {
        const p = partsById()[id];
        return p ? p.displayLabel : ('Part ' + id);
    }
    function lineIdAt(i) { return componentLineId(comp, i); }

    /**
     * Reuse an existing song part rather than minting a duplicate — see
     * this file's own "CLIENT-SIDE PART DEDUPE" header note for WHY this
     * exists at all (`vocal_part_upsert`'s create branch has no such
     * dedupe of its own). Mirrors the match ladder the server's OWN
     * (unreachable-from-here) `vocalPartsFindOrCreate()` uses: a
     * named-singer matches by musicianId first, then by a case-folded
     * singerName; every other kind matches by an exact (case-folded)
     * Label when one is given, else the first part of that kind carrying
     * NO custom label/singer at all (the generic "the Women part").
     */
    function findExistingPartForKind(kind, opts) {
        const parts = vp().parts || [];
        opts = opts || {};
        if (kind === 'named-singer') {
            if (opts.musicianId) {
                const byId = parts.find((p) => p.kind === kind && p.musicianId === opts.musicianId);
                if (byId) { return byId; }
            }
            if (opts.singerName) {
                const norm = opts.singerName.trim().toLowerCase();
                const byName = parts.find((p) => p.kind === kind && !p.musicianId
                    && (p.singerName || '').trim().toLowerCase() === norm);
                if (byName) { return byName; }
            }
            return null;
        }
        if (opts.label) {
            const norm = opts.label.trim().toLowerCase();
            return parts.find((p) => p.kind === kind && (p.label || '').trim().toLowerCase() === norm) || null;
        }
        return parts.find((p) => p.kind === kind && !p.label && !p.singerName) || null;
    }

    /* ==================================================================
     * D3 — AUTO-SAVE. See file header. Resolves the REAL tblLyricLines.Id
     * for every index in `indexes`, saving the section first if needed.
     * @returns {Promise<{ok:true, ids:number[]}|{ok:false, reason:'unsupported'|'save-failed'}>}
     * ================================================================== */
    async function ensureAndResolveIds(indexes) {
        let ids = indexes.map(lineIdAt);
        /* `hasPendingSave(comp)` catches a case a bare id!==0 check alone
           would miss: an EXISTING line rewritten heavily enough
           (similarity below lyricLinesApplyDesired()'s 0.5 fuzzy-match
           floor) still shows its OLD id here, right up until the pending
           debounced save actually runs — a save that then DELETEs that
           old line (cascading its vocal-part rows via FK) and INSERTs a
           new one. Assigning against that soon-to-be-deleted id would
           look like it worked and then silently vanish moments later when
           the debounce fires — the exact "wrong enrichment is worse than
           missing enrichment" trap this feature's own brief warns about.
           Flushing whenever ANY edit is still pending, regardless of
           what the currently-cached ids look like, closes it. */
        if (!hasPendingSave(comp) && ids.every((id) => id !== 0)) { return { ok: true, ids: ids }; }

        /* Covers BOTH named cases at once: a brand-new section whose OWN
           "Add section" create is still in flight (structure-tab.js's
           saveComponent() de-dupes concurrent calls for the same
           component onto the ALREADY-in-flight promise, so this simply
           waits for that, never firing a second create — #2073 D3 case 1),
           and lines typed inside the 500ms debounce window that haven't
           saved yet (ensureSaved() cancels that timer and saves right now
           — case 2). */
        const saved = await ensureSaved(comp);
        ids = indexes.map(lineIdAt);
        if (ids.every((id) => id !== 0)) { return { ok: true, ids: ids }; }

        if (saved && comp.id) {
            /* The section itself is saved (a real componentId exists) but
               per-line ids STILL never appeared — this is the pre-mirror
               ("LinesJson fallback") install enrichment-panel.js's own
               `componentLineId()` doc-comment already names: `lineIds` is
               `[]` site-wide there, forever, not just "not yet". Retrying
               would wait forever for something that will never arrive, so
               this is reported as unsupported rather than queued. */
            return { ok: false, reason: 'unsupported' };
        }
        /* A genuine save failure (case 3) — saveComponent() already
           toasted why (structure-tab.js's own catch block). Queue the
           assignment rather than lose it; see queuePending()/onSaved
           below for how it retries automatically. */
        return { ok: false, reason: 'save-failed' };
    }

    /** Queue an assign/span action for automatic retry the next time this
     *  component saves successfully (D3 case 3 — "a failed save").
     *  `action` is exactly the shape `retryPending()` below expects.
     *
     *  KNOWN LIMITATION (deliberately accepted, not silently ignored — a
     *  save failure serious enough to leave a curator's FIRST assignment
     *  queued while they retry a SECOND is already an unhappy path):
     *  `pending` is a single slot, not a real queue. Queuing a second
     *  action while the first is still waiting silently replaces it — the
     *  first curator gesture is lost with no toast. This mirrors the
     *  single-slot shape `saveTimers`/`pendingSaves` in structure-tab.js
     *  already use per component (one pending lyric edit at a time), so it
     *  is consistent with the rest of this editor rather than a new class
     *  of bug, but it is real and worth a follow-up if repeated failures
     *  on one section turn out to be common in practice. */
    function queuePending(action) {
        pending = action;
        if (offPendingSaveListener) { offPendingSaveListener(); }
        offPendingSaveListener = onSaved(retryPending);
        renderBody();
    }
    function clearPending() {
        pending = null;
        if (offPendingSaveListener) { offPendingSaveListener(); offPendingSaveListener = null; }
    }

    /** Fires once this component's NEXT save succeeds, from ANY path (the
     *  debounce finally landing, the shell's manual Save button flushing
     *  it, or this panel's own ensureSaved) — never a second, independent
     *  persistence path (task brief: "Never persist it by another path"). */
    async function retryPending() {
        if (!pending) { return; }
        const action = pending;
        try {
            if (action.kind === 'assign') {
                const ids = action.lineIndexes.map(lineIdAt);
                if (ids.some((id) => id === 0)) { return; } // still not ready — stay queued
                clearPending();
                const res = await api.assignVocalLines(songId, ids, action.partIds, action.mode, action.isBackground);
                store.set('vocalParts', res.vocalParts);
            } else {
                const lineId = lineIdAt(action.lineIndex);
                if (!lineId) { return; }
                clearPending();
                const res = await api.upsertVocalSpan(songId, {
                    lineId: lineId, partId: action.partId, start: action.start, end: action.end, isBackground: action.isBackground,
                });
                store.set('vocalParts', res.vocalParts);
            }
            toast('The queued voice assignment saved — the section has now saved too.', 'success');
        } catch (err) {
            clearPending();
            handleErr(err);
        }
        renderBody();
    }

    /* ---- status / error plumbing ---- */
    let statusEl = null; // set once the toolbar is built; announces selection + outcome text
    let statusTimer = null;
    function statusMsg(text) {
        if (statusTimer) { clearTimeout(statusTimer); }
        /* #2073 — debounced 150ms so a fast Shift-click/arrow sweep across
           many lines doesn't chatter a screen reader with every
           intermediate count (mirrors the plan's own "debounced 150ms"
           note for exactly this control). */
        statusTimer = setTimeout(() => { if (statusEl) { statusEl.textContent = text; } }, 150);
    }
    function inlineAlert(text) {
        inlineAlertText = text || '';
        renderBody();
    }
    function handleErr(err) {
        if (isVocalUnmigrated(err)) {
            toast('Voice parts, echo and rounds are not available on this install yet — the vocal-parts migration has not been run.', 'warning');
            return;
        }
        if (err && err.status === 400) { inlineAlert(err.message); return; }
        if (err && err.status === 404) { toast('That line or part no longer exists — the section may have changed underneath you. Try again.', 'warning'); return; }
        toast('Could not save voices: ' + (err && err.message ? err.message : 'unknown error'), 'danger');
    }

    /* ==================================================================
     *  DOM SKELETON
     * ================================================================== */
    const wrap = document.createElement('div');
    wrap.className = 'mt-2 v2-voices-panel';

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'btn btn-sm btn-link p-0 text-decoration-none';
    toggle.id = uid + '-toggle';
    toggle.innerHTML = '<i class="bi bi-people me-1" aria-hidden="true"></i>Who sings';

    const box = document.createElement('div');
    box.id = uid + '-body';
    box.className = 'mt-1 v2-voices-body';
    box.hidden = true;
    toggle.setAttribute('aria-controls', box.id);
    toggle.setAttribute('aria-expanded', 'false');

    /* Auto-expand when this component already has at least one voice
       assigned — a curator re-opening a song with voices already set
       should see them without an extra click (the "hasChords" precedent
       structure-tab.js's own chords box uses). Computed ONCE at build
       time, unlike the toggle's live aria-expanded, which only ever
       reflects the CURRENT open/closed state after that. */
    (function autoExpand() {
        const map = vp().lineAssignments || {};
        const hasAny = (comp.lineIds || []).some((id) => id && Array.isArray(map[id]) && map[id].length);
        if (hasAny) {
            box.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
        }
    })();

    toggle.addEventListener('click', () => {
        const show = box.hidden;
        box.hidden = !show;
        toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
        if (show) { renderBody(); }
    });

    /* ==================================================================
     *  SELECTION — checkboxes + Shift-click/Shift+Space range extend.
     *  See the file header for the full justification.
     * ================================================================== */
    function setSelected(i, on) {
        if (on) { selected.add(i); } else { selected.delete(i); }
    }
    function selectionSummary() {
        const n = selected.size;
        if (n === 0) { return 'No lines selected.'; }
        return n + ' line' + (n === 1 ? '' : 's') + ' selected.';
    }

    /* ==================================================================
     *  MAIN RENDER — rebuilds `box`'s contents from current state. Called
     *  on: toggle-open, any `vocalParts` store write, a selection change,
     *  and `refresh()` (structure-tab.js's lyrics-textarea input handler).
     * ================================================================== */
    function renderBody() {
        box.innerHTML = '';
        const data = vp();

        if (!data.ready) {
            const notice = document.createElement('div');
            notice.className = 'text-muted small fst-italic';
            notice.textContent = 'Voice parts, echo and rounds are not available on this install yet — a database migration needs to be run first.';
            box.appendChild(notice);
            return;
        }

        const lines = Array.isArray(comp.lines) ? comp.lines : [];
        if (!lines.length) {
            box.innerHTML = '<div class="text-muted small fst-italic">No lines yet.</div>';
            return;
        }
        /* Clamp a stale selection after a lyric edit shortened this
           component (refresh()'s own contract — see its doc-comment). */
        selected.forEach((i) => { if (i >= lines.length) { selected.delete(i); } });
        if (anchor >= lines.length) { anchor = -1; }
        /* The word-span picker only ever makes sense while exactly one
           line is ticked (renderWordPicker()'s own guard) — drop any
           stale word ticks the instant that stops being true, so a
           second line ticked afterwards never silently carries the first
           line's word selection into a totally different Assign call. */
        if (selected.size !== 1) { pressedWords.clear(); }

        if (pending) {
            const banner = document.createElement('div');
            banner.className = 'small text-warning-emphasis bg-warning-subtle border border-warning-subtle rounded px-2 py-1 mb-2';
            banner.setAttribute('role', 'status');
            banner.textContent = 'Not saved yet — this voice will be assigned automatically once the section saves.';
            box.appendChild(banner);
        }

        renderRunSummary(box, data, lines);
        renderLineList(box, data, lines);
        renderToolbar(box, data, lines);
        renderWordPicker(box, data, lines);
        renderRoundsSection(box, data, lines);
        renderPartsLegend(box, data);
    }

    /** One-line plain-English summary of what's already assigned in this
     *  component ("Lines 1–4: Women · Lines 5–8: Men · Line 9: Women +
     *  Men (echo)"), folding adjacent lines with the SAME assignment set
     *  into one run — a light client-side echo of the read path's own
     *  run-folding (rule #45's "the fold is derived, never stored"
     *  discipline), used here purely for a friendly caption, never as a
     *  write shape. */
    function renderRunSummary(host, data, lines) {
        const p = document.createElement('p');
        p.className = 'small text-muted mb-1';
        const runs = [];
        let cur = null;
        lines.forEach((_text, i) => {
            const lineId = lineIdAt(i);
            const assigns = (lineId && data.lineAssignments[lineId]) ? data.lineAssignments[lineId] : [];
            const key = assigns.map((a) => a.partId + (a.bg ? 'b' : 'l')).sort().join(',');
            if (cur && cur.key === key) { cur.to = i; return; }
            if (cur) { runs.push(cur); }
            cur = { key: key, from: i, to: i, assigns: assigns };
        });
        if (cur) { runs.push(cur); }
        const parts = runs.filter((r) => r.assigns.length).map((r) => {
            const label = r.assigns.map((a) => partLabel(a.partId) + (a.bg ? ' (echo)' : '')).join(' + ');
            const range = (r.from === r.to) ? ('Line ' + (r.from + 1)) : ('Lines ' + (r.from + 1) + '–' + (r.to + 1));
            return range + ': ' + label;
        });
        p.textContent = parts.length ? parts.join(' · ') : 'No voices assigned yet.';
        host.appendChild(p);
    }

    function renderLineList(host, data, lines) {
        const list = document.createElement('div');
        list.setAttribute('role', 'group');
        list.setAttribute('aria-label', 'Lines of this section — tick lines, then assign a voice below');

        lines.forEach((lineText, i) => {
            const lineId = lineIdAt(i);
            const assigns = (lineId && data.lineAssignments[lineId]) ? data.lineAssignments[lineId] : [];
            const spans = (lineId && data.spans[lineId]) ? data.spans[lineId] : [];
            const text = (lineText && String(lineText).trim()) ? String(lineText) : '(blank line)';

            const row = document.createElement('div');
            row.className = 'd-flex align-items-start gap-2 mb-1 v2-voice-line-row';
            row.dataset.lineIndex = String(i);

            const cbId = uid + '-l' + i;
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'form-check-input mt-1';
            cb.id = cbId;
            cb.checked = selected.has(i);
            cb.addEventListener('click', (e) => {
                if (e.shiftKey && anchor >= 0) {
                    const lo = Math.min(anchor, i), hi = Math.max(anchor, i);
                    const state = cb.checked;
                    for (let k = lo; k <= hi; k++) { setSelected(k, state); }
                } else {
                    setSelected(i, cb.checked);
                }
                anchor = i;
                statusMsg(selectionSummary());
                renderBody();
            });
            cb.addEventListener('keydown', (e) => {
                /* Progressive enhancement only — see the file header's
                   selection-mechanism note. Space alone still just toggles
                   this one box (native browser behaviour, untouched);
                   Shift+Space extends the range from the anchor, exactly
                   like Shift-click above. */
                if (e.key !== ' ' || !e.shiftKey || anchor < 0) { return; }
                e.preventDefault();
                const newState = !selected.has(i);
                const lo = Math.min(anchor, i), hi = Math.max(anchor, i);
                for (let k = lo; k <= hi; k++) { setSelected(k, newState); }
                anchor = i;
                statusMsg(selectionSummary());
                renderBody();
            });

            const label = document.createElement('label');
            label.className = 'form-check-label small flex-grow-1';
            label.htmlFor = cbId;
            label.textContent = 'Line ' + (i + 1) + ': ' + text;
            row.append(cb, label);

            if (assigns.length || spans.length) {
                const chipsId = uid + '-chips' + i;
                const chipGroup = document.createElement('span');
                chipGroup.id = chipsId;
                chipGroup.setAttribute('role', 'group');
                const names = assigns.map((a) => partLabel(a.partId) + (a.bg ? ' (echo)' : ''))
                    .concat(spans.map((s) => partLabel(s.partId) + ' on “' + codePointSlice(text, s.start, s.end) + '”' + (s.bg ? ' (echo)' : '')));
                chipGroup.setAttribute('aria-label', 'Sung by: ' + names.join(', '));

                assigns.forEach((a) => {
                    const chipEl = document.createElement('span');
                    chipEl.className = 'badge bg-secondary-subtle text-secondary-emphasis me-1 mb-1 fw-normal v2-voice-chip' + (a.bg ? ' v2-voice-chip--bg' : '');
                    chipEl.textContent = partLabel(a.partId) + (a.bg ? ' (echo) ' : ' ');
                    const x = document.createElement('button');
                    x.type = 'button';
                    x.className = 'btn-close btn-close-sm align-middle';
                    x.style.fontSize = '0.6rem';
                    x.setAttribute('aria-label', 'Remove ' + partLabel(a.partId) + ' from line ' + (i + 1));
                    x.addEventListener('click', () => removeAssignment(lineId, a.partId, a.bg));
                    chipEl.appendChild(x);
                    chipGroup.appendChild(chipEl);
                });
                spans.forEach((s) => {
                    const chipEl = document.createElement('span');
                    chipEl.className = 'badge bg-secondary-subtle text-secondary-emphasis me-1 mb-1 fw-normal fst-italic v2-voice-span-chip' + (s.bg ? ' v2-voice-chip--bg' : '');
                    chipEl.textContent = partLabel(s.partId) + ' on “' + codePointSlice(text, s.start, s.end) + '”' + (s.bg ? ' (echo)' : '') + ' ';
                    const x = document.createElement('button');
                    x.type = 'button';
                    x.className = 'btn-close btn-close-sm align-middle';
                    x.style.fontSize = '0.6rem';
                    x.setAttribute('aria-label', 'Remove this word-level voice from line ' + (i + 1));
                    x.addEventListener('click', () => {
                        api.deleteVocalSpan(songId, s.id).then((res) => { store.set('vocalParts', res.vocalParts); })
                            .catch(handleErr);
                    });
                    chipEl.appendChild(x);
                    chipGroup.appendChild(chipEl);
                });
                row.appendChild(chipGroup);
            }

            list.appendChild(row);
        });

        host.appendChild(list);
    }

    /** Remove ONE part from ONE line — composed from the two API calls
     *  that actually exist (`vocal_lines_assign`/`vocal_lines_clear`),
     *  since neither takes a single partId to remove: replay the OTHER
     *  parts of the SAME echo/lead class back with `mode:'replace'`
     *  (leaves any different-class assignment on the line untouched, the
     *  same `IsBackground`-scoped-delete rule `vocalPartsAssignLines()`'s
     *  own doc-block states), or clear that class outright when nothing
     *  would be left. */
    function removeAssignment(lineId, partId, bg) {
        const data = vp();
        const current = (data.lineAssignments[lineId] || []).filter((a) => a.bg === bg);
        const remaining = current.map((a) => a.partId).filter((pid) => pid !== partId);
        const call = remaining.length
            ? api.assignVocalLines(songId, [lineId], remaining, 'replace', bg)
            : api.clearVocalLines(songId, [lineId], bg);
        call.then((res) => { store.set('vocalParts', res.vocalParts); }).catch(handleErr);
    }

    /* ==================================================================
     *  TOOLBAR — select-all, live status, and the Assign controls.
     * ================================================================== */
    function renderToolbar(host, data, lines) {
        const selectAllBtn = document.createElement('button');
        selectAllBtn.type = 'button';
        selectAllBtn.className = 'btn btn-sm btn-outline-secondary';
        const allSelected = lines.length > 0 && selected.size === lines.length;
        selectAllBtn.textContent = allSelected ? 'Clear selection' : 'Select all lines';
        selectAllBtn.addEventListener('click', () => {
            if (allSelected) { selected.clear(); } else { lines.forEach((_t, i) => selected.add(i)); }
            anchor = -1;
            statusMsg(selectionSummary());
            renderBody();
        });

        statusEl = document.createElement('span');
        statusEl.setAttribute('role', 'status');
        statusEl.setAttribute('aria-live', 'polite');
        statusEl.className = 'small text-muted ms-2';
        statusEl.textContent = selectionSummary();

        const topRow = document.createElement('div');
        topRow.className = 'd-flex flex-wrap align-items-center gap-2 mt-2';
        topRow.append(selectAllBtn, statusEl);
        host.appendChild(topRow);

        if (inlineAlertText) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger py-1 px-2 mt-1 mb-0 small';
            alert.setAttribute('role', 'alert');
            alert.textContent = inlineAlertText;
            host.appendChild(alert);
        }

        const row = document.createElement('div');
        row.className = 'd-flex flex-wrap align-items-end gap-2 mt-1 v2-voices-assign';

        const partWrap = document.createElement('div');
        const partLbl = document.createElement('label');
        partLbl.className = 'form-label small mb-0';
        partLbl.htmlFor = uid + '-part';
        partLbl.textContent = 'Assign selected lines to';
        const partSelect = document.createElement('select');
        partSelect.id = uid + '-part';
        partSelect.className = 'form-select form-select-sm';
        const existingGroup = document.createElement('optgroup');
        existingGroup.label = "This song's parts";
        (data.parts || []).forEach((p) => {
            const o = document.createElement('option');
            o.value = 'p:' + p.id;
            o.textContent = p.displayLabel;
            existingGroup.appendChild(o);
        });
        const newGroup = document.createElement('optgroup');
        newGroup.label = 'Add a new part';
        resolveKinds().forEach((k) => {
            const o = document.createElement('option');
            o.value = 'k:' + k.key;
            o.textContent = k.label;
            newGroup.appendChild(o);
        });
        if (existingGroup.children.length) { partSelect.appendChild(existingGroup); }
        partSelect.appendChild(newGroup);
        partWrap.append(partLbl, partSelect);

        const namedWrap = document.createElement('div');
        namedWrap.className = 'v2-voices-named' + (partSelect.value.startsWith('k:named-singer') ? '' : ' d-none');
        const namedLbl = document.createElement('label');
        namedLbl.className = 'form-label small mb-0';
        namedLbl.htmlFor = uid + '-singer';
        namedLbl.textContent = "Singer's name";
        const namedInput = document.createElement('input');
        namedInput.type = 'text';
        namedInput.id = uid + '-singer';
        namedInput.className = 'form-control form-control-sm';
        namedInput.placeholder = 'e.g. Thandiwe M.';
        /* #2073 — see the file header's "NAMED SINGER" note: a plain
           labelled text field, matching tblVocalParts.SingerName's own
           first-class, musicianId-optional column. */
        namedWrap.append(namedLbl, namedInput);

        const ordWrap = document.createElement('div');
        ordWrap.className = 'v2-voices-ordinal' + (partSelect.value.startsWith('k:group') ? '' : ' d-none');
        const ordLbl = document.createElement('label');
        ordLbl.className = 'form-label small mb-0';
        ordLbl.htmlFor = uid + '-ord';
        ordLbl.textContent = 'Group number';
        const ordInput = document.createElement('input');
        ordInput.type = 'number';
        ordInput.id = uid + '-ord';
        ordInput.min = '1';
        ordInput.max = '9';
        ordInput.value = '1';
        ordInput.className = 'form-control form-control-sm';
        ordInput.style.width = '5rem';
        ordWrap.append(ordLbl, ordInput);

        partSelect.addEventListener('change', () => {
            namedWrap.classList.toggle('d-none', !partSelect.value.startsWith('k:named-singer'));
            ordWrap.classList.toggle('d-none', !partSelect.value.startsWith('k:group'));
        });

        const bgWrap = document.createElement('div');
        bgWrap.className = 'form-check';
        const bgCheck = document.createElement('input');
        bgCheck.type = 'checkbox';
        bgCheck.className = 'form-check-input';
        bgCheck.id = uid + '-bg';
        const bgLbl = document.createElement('label');
        bgLbl.className = 'form-check-label small';
        bgLbl.htmlFor = bgCheck.id;
        bgLbl.textContent = 'Echo / background';
        bgWrap.append(bgCheck, bgLbl);

        const addWrap = document.createElement('div');
        addWrap.className = 'form-check';
        const addCheck = document.createElement('input');
        addCheck.type = 'checkbox';
        addCheck.className = 'form-check-input';
        addCheck.id = uid + '-add';
        const addLbl = document.createElement('label');
        addLbl.className = 'form-check-label small';
        addLbl.htmlFor = addCheck.id;
        /* Default UNCHECKED -> mode 'replace', matching
           vocalPartsAssignLines()'s own server-side default exactly, so a
           curator who never touches this checkbox gets the same behaviour
           the API would give them anyway if this field were simply
           omitted. */
        addLbl.textContent = 'Add to any voices already on these lines';
        addWrap.append(addCheck, addLbl);

        const assignBtn = document.createElement('button');
        assignBtn.type = 'button';
        assignBtn.className = 'btn btn-sm btn-primary';
        assignBtn.textContent = pressedWords.size ? 'Assign to words' : 'Assign';
        assignBtn.disabled = (selected.size === 0);

        row.append(partWrap, namedWrap, ordWrap, bgWrap, addWrap, assignBtn);
        host.appendChild(row);

        assignBtn.addEventListener('click', () => onAssign({
            partSelect: partSelect, namedInput: namedInput, ordInput: ordInput,
            bgCheck: bgCheck, addCheck: addCheck, assignBtn: assignBtn,
        }));
    }

    /** Resolve the picked/created part id. Throws VoicesUiError for a
     *  curator-fixable problem (never calls the network for that case);
     *  otherwise may call `api.upsertVocalPart()` once. */
    async function resolvePartSelection(ctl) {
        const v = ctl.partSelect.value;
        if (v.indexOf('p:') === 0) { return Number(v.slice(2)); }
        const kind = v.slice(2);
        const opts = {};
        if (kind === 'named-singer') {
            const name = (ctl.namedInput.value || '').trim();
            if (!name) { throw new VoicesUiError("Pick or type the singer's name."); }
            opts.singerName = name;
        }
        if (kind === 'group') {
            const n = Math.max(1, Math.min(9, parseInt(ctl.ordInput.value, 10) || 1));
            opts.label = 'Group ' + n;
        }
        const existing = findExistingPartForKind(kind, opts);
        if (existing) { return existing.id; }
        const partInput = Object.assign({ kind: kind }, opts);
        const r = await api.upsertVocalPart(songId, partInput);
        store.set('vocalParts', r.vocalParts);
        return r.part.id;
    }

    /** The Assign click — exact control flow (D3 lives here). */
    async function onAssign(ctl) {
        if (selected.size === 0) { return; }
        inlineAlert('');
        ctl.assignBtn.disabled = true;
        ctl.assignBtn.setAttribute('aria-busy', 'true');
        try {
            let partId;
            try {
                partId = await resolvePartSelection(ctl);
            } catch (err) {
                if (err instanceof VoicesUiError) { inlineAlert(err.message); return; }
                handleErr(err);
                return;
            }

            const bg = ctl.bgCheck.checked;
            const mode = ctl.addCheck.checked ? 'add' : 'replace';
            const indexes = Array.from(selected).sort((a, b) => a - b);

            if (pressedWords.size) {
                const idxs = Array.from(pressedWords).sort((a, b) => a - b);
                const contiguous = idxs.every((v, i) => i === 0 || v === idxs[i - 1] + 1);
                if (!contiguous) { inlineAlert('Tick a continuous run of words.'); return; }
                const tokens = lineWordTokens(String(lineTextAt(indexes[0])));
                const start = tokens[idxs[0]].start;
                const end = tokens[idxs[idxs.length - 1]].end;

                const resolved = await ensureAndResolveIds([indexes[0]]);
                if (!resolved.ok) {
                    if (resolved.reason === 'unsupported') { inlineAlert('This installation cannot store voices per line yet (the lyric-lines migration has not been run).'); return; }
                    queuePending({ kind: 'span', lineIndex: indexes[0], partId: partId, start: start, end: end, isBackground: bg });
                    statusMsg('Not saved yet — this voice will be assigned automatically once the section saves.');
                    return;
                }
                const res = await api.upsertVocalSpan(songId, { lineId: resolved.ids[0], partId: partId, start: start, end: end, isBackground: bg });
                store.set('vocalParts', res.vocalParts);
                statusMsg('Assigned ' + partLabel(partId) + ' to a phrase on line ' + (indexes[0] + 1) + '.');
                pressedWords.clear();
                return;
            }

            const resolved = await ensureAndResolveIds(indexes);
            if (!resolved.ok) {
                if (resolved.reason === 'unsupported') { inlineAlert('This installation cannot store voices per line yet (the lyric-lines migration has not been run).'); return; }
                queuePending({ kind: 'assign', lineIndexes: indexes, partIds: [partId], mode: mode, isBackground: bg });
                statusMsg('Not saved yet — this voice will be assigned automatically once the section saves.');
                return;
            }
            const res = await api.assignVocalLines(songId, resolved.ids, [partId], mode, bg);
            store.set('vocalParts', res.vocalParts);
            statusMsg('Assigned ' + partLabel(partId) + (bg ? ' (echo)' : '') + ' to ' + describeLines(indexes) + '.');
            selected.clear();
        } catch (err) {
            handleErr(err);
        } finally {
            renderBody();
        }
    }

    function lineTextAt(i) { return (Array.isArray(comp.lines) && comp.lines[i] != null) ? comp.lines[i] : ''; }

    function describeLines(indexes) {
        if (indexes.length === 1) { return 'line ' + (indexes[0] + 1); }
        return 'lines ' + (indexes[0] + 1) + '–' + (indexes[indexes.length - 1] + 1);
    }

    /* ==================================================================
     *  WORD-GRAIN SPANS — visible only when exactly one line is ticked
     *  AND this install has run the spans migration.
     * ================================================================== */
    function renderWordPicker(host, data, lines) {
        if (!data.spansReady || selected.size !== 1) { return; }
        const i = Array.from(selected)[0];
        const text = lineTextAt(i);
        const tokens = lineWordTokens(String(text));
        if (!tokens.length) { return; }

        const wrap = document.createElement('div');
        wrap.className = 'mt-2';
        const hint = document.createElement('div');
        hint.className = 'small text-muted mb-1';
        hint.textContent = 'Only part of this line? Tick the words:';
        wrap.appendChild(hint);

        const group = document.createElement('div');
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', 'Words of line ' + (i + 1));
        tokens.forEach((tok, ti) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm btn-outline-secondary me-1 mb-1';
            b.textContent = tok.text;
            b.setAttribute('aria-pressed', pressedWords.has(ti) ? 'true' : 'false');
            if (pressedWords.has(ti)) { b.classList.add('active'); }
            b.addEventListener('click', () => {
                if (pressedWords.has(ti)) { pressedWords.delete(ti); } else { pressedWords.add(ti); }
                renderBody();
            });
            group.appendChild(b);
        });
        wrap.appendChild(group);
        host.appendChild(wrap);
    }

    /* ==================================================================
     *  ROUNDS — a compact but complete "sing as a round/canon" sub-panel.
     *  Deliberately scoped (documented, trivially extendable): the real
     *  API also supports a per-voice `entryBasis` of 'beats'/'ms', a
     *  per-voice `timesThrough` override, `intervalSemitones`, and a
     *  partner-song per-voice span — this UI exposes only the 'lines'
     *  entry basis (the round's OWN `timesThrough`/`endingMode` apply to
     *  every voice), matching the plan's own "unit fixed to lines in this
     *  UI" simplification. A curator who needs the richer shapes can still
     *  reach them by calling the same api2 actions directly; nothing here
     *  narrows what the SERVER accepts.
     * ================================================================== */
    function roundsForComponent(data) {
        const ids = comp.lineIds || [];
        return (data.rounds || []).filter((r) => ids.indexOf(r.startLineId) !== -1);
    }

    function renderRoundsSection(host, data, lines) {
        const wrap = document.createElement('div');
        wrap.className = 'mt-2';

        const existing = roundsForComponent(data);
        existing.forEach((r) => {
            const card = document.createElement('div');
            card.className = 'v2-round-card small mb-1 p-2';
            const title = document.createElement('div');
            title.className = 'fw-semibold';
            title.textContent = (r.label || (r.kind.charAt(0).toUpperCase() + r.kind.slice(1)))
                + ' — ' + r.voices.length + ' voices, ' + r.timesThrough + '× through, ends ' + r.endingMode;
            const voicesLine = document.createElement('div');
            voicesLine.className = 'text-muted';
            voicesLine.textContent = r.voices.map((v) => v.displayLabel + ' +' + v.entryLines + ' line' + (v.entryLines === 1 ? '' : 's')).join(' · ');
            const inComponent = (comp.lineIds || []).indexOf(r.endLineId) !== -1 || r.endLineId === null;
            const btnRow = document.createElement('div');
            btnRow.className = 'mt-1';
            btnRow.appendChild(iconBtn('bi-pencil', 'Edit this round', false, () => openRoundForm(r), { className: 'btn btn-sm btn-outline-secondary me-1' }));
            btnRow.appendChild(iconBtn('bi-trash', 'Delete this round', false, () => onDeleteRound(r), { className: 'btn btn-sm btn-outline-danger' }));
            card.append(title, voicesLine);
            if (!inComponent) {
                const note = document.createElement('div');
                note.className = 'fst-italic text-secondary';
                note.textContent = '→ continues into a later section';
                card.appendChild(note);
            }
            card.appendChild(btnRow);
            wrap.appendChild(card);
        });

        if (!data.roundsReady) { host.appendChild(wrap); return; }

        if (!roundForm) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.textContent = 'Sing as a round…';
            btn.disabled = selected.size === 0;
            btn.addEventListener('click', () => openRoundForm(null));
            wrap.appendChild(btn);
        } else {
            wrap.appendChild(buildRoundForm(data));
        }

        host.appendChild(wrap);
    }

    function openRoundForm(existingRound) {
        if (existingRound) {
            roundForm = {
                editingId: existingRound.id,
                kind: existingRound.kind,
                label: existingRound.label || '',
                timesThrough: existingRound.timesThrough,
                endingMode: existingRound.endingMode,
                startLineId: existingRound.startLineId,
                endLineId: existingRound.endLineId,
                codaStartLineId: existingRound.codaStartLineId,
                codaEndLineId: existingRound.codaEndLineId,
                voices: existingRound.voices.map((v) => ({ number: v.number, partId: v.partId, label: v.label, entryLines: v.entryLines })),
            };
        } else {
            const idxs = Array.from(selected).sort((a, b) => a - b);
            roundForm = {
                editingId: null, kind: 'round', label: '', timesThrough: 2, endingMode: 'complete',
                /* D3 — a brand-new round's own lines may never have been
                   saved yet, so keep their INDEXES here (never the id,
                   which could be a placeholder 0) and resolve the real
                   tblLyricLines.Id values just before saving
                   (onSaveRound() below), the SAME ensureAndResolveIds()
                   flow the line/word Assign button already uses. An
                   EXISTING round (the `if` branch above) needs none of
                   this — a round can only ever have been CREATED from
                   already-saved lines, so its ids are always real. */
                startIndex: idxs[0], endIndex: idxs.length > 1 ? idxs[idxs.length - 1] : null,
                codaStartIndex: null, codaEndIndex: null,
                voices: [
                    { number: 1, partId: null, label: null, entryLines: 0 },
                    { number: 2, partId: null, label: null, entryLines: 1 },
                ],
            };
        }
        renderBody();
    }

    function onDeleteRound(r) {
        if (!window.confirm('Delete this round? Its own voice list goes with it — the lines themselves are untouched.')) { return; }
        api.deleteRound(songId, r.id).then((res) => { store.set('vocalParts', res.vocalParts); renderBody(); }).catch(handleErr);
    }

    function buildRoundForm(data) {
        const form = document.createElement('div');
        form.className = 'v2-round-form p-2 mt-1';

        const kindRow = document.createElement('div');
        kindRow.className = 'd-flex flex-wrap gap-2 align-items-end mb-1';

        const kindWrap = document.createElement('div');
        const kindLbl = document.createElement('label');
        kindLbl.className = 'form-label small mb-0';
        kindLbl.textContent = 'Kind';
        const kindSel = document.createElement('select');
        kindSel.className = 'form-select form-select-sm';
        ['round', 'canon', 'partner-song'].forEach((k) => {
            const o = document.createElement('option'); o.value = k; o.textContent = k; if (roundForm.kind === k) { o.selected = true; }
            kindSel.appendChild(o);
        });
        kindSel.addEventListener('change', () => { roundForm.kind = kindSel.value; });
        kindWrap.append(kindLbl, kindSel);

        const labelWrap = document.createElement('div');
        const labelLbl = document.createElement('label');
        labelLbl.className = 'form-label small mb-0';
        labelLbl.textContent = 'Label (optional)';
        const labelInput = document.createElement('input');
        labelInput.type = 'text';
        labelInput.className = 'form-control form-control-sm';
        labelInput.value = roundForm.label || '';
        labelInput.addEventListener('input', () => { roundForm.label = labelInput.value; });
        labelWrap.append(labelLbl, labelInput);

        const timesWrap = document.createElement('div');
        const timesLbl = document.createElement('label');
        timesLbl.className = 'form-label small mb-0';
        timesLbl.textContent = 'Times through';
        const timesInput = document.createElement('input');
        timesInput.type = 'number';
        timesInput.min = '1'; timesInput.max = '8';
        timesInput.className = 'form-control form-control-sm';
        timesInput.style.width = '5rem';
        timesInput.value = String(roundForm.timesThrough);
        timesInput.addEventListener('input', () => { roundForm.timesThrough = Math.max(1, Math.min(8, parseInt(timesInput.value, 10) || 2)); });
        timesWrap.append(timesLbl, timesInput);

        const endingWrap = document.createElement('div');
        const endingLbl = document.createElement('label');
        endingLbl.className = 'form-label small mb-0';
        endingLbl.textContent = 'Ending';
        const endingSel = document.createElement('select');
        endingSel.className = 'form-select form-select-sm';
        ['complete', 'together', 'coda'].forEach((k) => {
            const o = document.createElement('option'); o.value = k; o.textContent = k; if (roundForm.endingMode === k) { o.selected = true; }
            endingSel.appendChild(o);
        });
        endingSel.addEventListener('change', () => { roundForm.endingMode = endingSel.value; renderBody(); });
        endingWrap.append(endingLbl, endingSel);

        kindRow.append(kindWrap, labelWrap, timesWrap, endingWrap);
        form.appendChild(kindRow);

        const hasRange = roundForm.editingId ? !!roundForm.endLineId
            : (roundForm.endIndex !== null && roundForm.endIndex !== undefined);
        const spanNote = document.createElement('div');
        spanNote.className = 'small text-muted mb-1';
        spanNote.textContent = 'Round covers ' + (hasRange ? 'the ticked lines' : 'the first ticked line') + ' from this section.';
        form.appendChild(spanNote);

        if (roundForm.endingMode === 'coda') {
            const codaRow = document.createElement('div');
            codaRow.className = 'd-flex align-items-center gap-2 mb-1';
            /* Coda span D3: same index-vs-id split as the round's own
               start/end above — an EDITING round's coda is already a real
               saved id; a NEW round's ticked coda lines might not be
               saved yet, so keep indexes and resolve them at save time. */
            const codaBtn = smallBtn('Use ticked lines for the coda', () => {
                const idxs = Array.from(selected).sort((a, b) => a - b);
                if (!idxs.length) { return; }
                if (roundForm.editingId) {
                    roundForm.codaStartLineId = lineIdAt(idxs[0]);
                    roundForm.codaEndLineId = idxs.length > 1 ? lineIdAt(idxs[idxs.length - 1]) : null;
                } else {
                    roundForm.codaStartIndex = idxs[0];
                    roundForm.codaEndIndex = idxs.length > 1 ? idxs[idxs.length - 1] : null;
                }
                renderBody();
            });
            const hasCoda = roundForm.editingId ? !!roundForm.codaStartLineId
                : (roundForm.codaStartIndex !== null && roundForm.codaStartIndex !== undefined);
            const codaNote = document.createElement('span');
            codaNote.className = 'small text-muted';
            codaNote.textContent = hasCoda ? 'Coda span set.' : 'Coda span not set yet — tick the coda lines above, then click this.';
            codaRow.append(codaBtn, codaNote);
            form.appendChild(codaRow);
        }

        const voicesHost = document.createElement('div');
        voicesHost.className = 'mt-1';
        function renderVoiceRows() {
            voicesHost.innerHTML = '';
            roundForm.voices.forEach((v, vi) => {
                const row = document.createElement('div');
                row.className = 'd-flex align-items-center gap-1 mb-1';
                const num = document.createElement('span');
                num.className = 'small text-muted';
                num.style.width = '4.5rem';
                num.textContent = 'Voice ' + (vi + 1);

                const partSel = document.createElement('select');
                partSel.className = 'form-select form-select-sm';
                partSel.style.width = 'auto';
                const none = document.createElement('option');
                none.value = ''; none.textContent = '— unnamed voice —';
                if (!v.partId) { none.selected = true; }
                partSel.appendChild(none);
                (data.parts || []).forEach((p) => {
                    const o = document.createElement('option');
                    o.value = String(p.id); o.textContent = p.displayLabel;
                    if (v.partId === p.id) { o.selected = true; }
                    partSel.appendChild(o);
                });
                partSel.addEventListener('change', () => { v.partId = partSel.value ? Number(partSel.value) : null; });

                const entry = document.createElement('input');
                entry.type = 'number';
                entry.min = '0';
                entry.className = 'form-control form-control-sm';
                entry.style.width = '5rem';
                entry.value = String(v.entryLines);
                entry.title = 'Lines after the first voice';
                entry.setAttribute('aria-label', 'Voice ' + (vi + 1) + ' entry offset, in lines');
                if (vi === 0) { entry.value = '0'; entry.disabled = true; v.entryLines = 0; }
                entry.addEventListener('input', () => { v.entryLines = Math.max(0, parseInt(entry.value, 10) || 0); });

                row.append(num, partSel, entry);
                if (roundForm.voices.length > 2) {
                    row.appendChild(iconBtn('bi-x-lg', 'Remove voice ' + (vi + 1), false, () => {
                        roundForm.voices.splice(vi, 1);
                        roundForm.voices.forEach((vv, i2) => { vv.number = i2 + 1; });
                        renderVoiceRows();
                    }, { className: 'btn btn-sm btn-outline-danger' }));
                }
                voicesHost.appendChild(row);
            });
        }
        renderVoiceRows();
        form.appendChild(voicesHost);

        const addVoiceBtn = smallBtn('Add voice', () => {
            if (roundForm.voices.length >= 8) { return; }
            roundForm.voices.push({ number: roundForm.voices.length + 1, partId: null, label: null, entryLines: roundForm.voices.length });
            renderVoiceRows();
        });
        form.appendChild(addVoiceBtn);

        const btnRow = document.createElement('div');
        btnRow.className = 'mt-2';
        const saveBtn = smallBtn('Save round', () => onSaveRound());
        saveBtn.className = 'btn btn-sm btn-primary py-0 px-2 me-1';   // matches enrichment-panel.js's own Save-button override
        const cancelBtn = smallBtn('Cancel', () => { roundForm = null; renderBody(); });
        btnRow.append(saveBtn, cancelBtn);
        form.appendChild(btnRow);

        return form;
    }

    /**
     * D3, applied to rounds. An EDITING round's line ids are already real
     * (a round can only ever have been created from already-saved lines);
     * a NEW round may reference lines that have never been saved, so this
     * resolves every index the form is holding via the SAME
     * `ensureAndResolveIds()` the line/word Assign button uses, in ONE
     * batch (never one call per field). Unlike Assign, a save failure
     * here is surfaced inline rather than queued for automatic retry —
     * setting up a round is a deliberate, occasional action a curator is
     * actively looking at when it happens (unlike the routine "tick lines,
     * pick a voice" gesture the queue exists for), so asking them to press
     * Save again is a reasonable, simpler contract; documented narrowing,
     * not an oversight.
     * @returns {Promise<{ok:true, startLineId, endLineId, codaStartLineId, codaEndLineId}|{ok:false}>}
     */
    async function resolveRoundFormLineIds() {
        if (roundForm.editingId) {
            return {
                ok: true,
                startLineId: roundForm.startLineId,
                endLineId: roundForm.endLineId,
                codaStartLineId: roundForm.endingMode === 'coda' ? roundForm.codaStartLineId : null,
                codaEndLineId: roundForm.endingMode === 'coda' ? roundForm.codaEndLineId : null,
            };
        }
        const wantCoda = roundForm.endingMode === 'coda'
            && roundForm.codaStartIndex !== null && roundForm.codaStartIndex !== undefined;
        const idxs = [roundForm.startIndex];
        if (roundForm.endIndex !== null && roundForm.endIndex !== undefined) { idxs.push(roundForm.endIndex); }
        if (wantCoda) {
            idxs.push(roundForm.codaStartIndex);
            if (roundForm.codaEndIndex !== null && roundForm.codaEndIndex !== undefined) { idxs.push(roundForm.codaEndIndex); }
        }
        const resolved = await ensureAndResolveIds(idxs);
        if (!resolved.ok) {
            if (resolved.reason === 'unsupported') { inlineAlert('This installation cannot store rounds yet (the lyric-lines migration has not been run).'); }
            else { inlineAlert('This section has not saved yet — fix the problem shown above the lyrics box, then try saving the round again.'); }
            return { ok: false };
        }
        const byIndex = {};
        idxs.forEach((idx, k) => { byIndex[idx] = resolved.ids[k]; });
        return {
            ok: true,
            startLineId: byIndex[roundForm.startIndex],
            endLineId: (roundForm.endIndex !== null && roundForm.endIndex !== undefined) ? byIndex[roundForm.endIndex] : null,
            codaStartLineId: wantCoda ? byIndex[roundForm.codaStartIndex] : null,
            codaEndLineId: (wantCoda && roundForm.codaEndIndex !== null && roundForm.codaEndIndex !== undefined) ? byIndex[roundForm.codaEndIndex] : null,
        };
    }

    async function onSaveRound() {
        const lineIds = await resolveRoundFormLineIds();
        if (!lineIds.ok) { return; }
        if (!lineIds.startLineId) { inlineAlert('Tick at least one line before saving a round.'); return; }
        const payload = {
            id: roundForm.editingId || undefined,
            kind: roundForm.kind,
            label: roundForm.label || null,
            startLineId: lineIds.startLineId,
            endLineId: lineIds.endLineId,
            timesThrough: roundForm.timesThrough,
            endingMode: roundForm.endingMode,
            codaStartLineId: lineIds.codaStartLineId,
            codaEndLineId: lineIds.codaEndLineId,
            voices: roundForm.voices.map((v) => ({ number: v.number, partId: v.partId || null, label: v.label || null, entryLines: v.entryLines })),
        };
        try {
            const res = await api.upsertRound(songId, payload);
            store.set('vocalParts', res.vocalParts);
            roundForm = null;
            renderBody();
        } catch (err) { handleErr(err); }
    }

    /* ==================================================================
     *  PARTS LEGEND — song-wide (every component's panel renders the SAME
     *  list from the SAME store slice, kept in step by the subscription
     *  below), so a rename/delete here is visible everywhere instantly.
     * ================================================================== */
    function renderPartsLegend(host, data) {
        const details = document.createElement('details');
        details.className = 'mt-2';
        const summary = document.createElement('summary');
        summary.className = 'small text-muted';
        summary.textContent = 'Manage parts (' + (data.parts || []).length + ')';
        details.appendChild(summary);

        (data.parts || []).forEach((p) => {
            const row = document.createElement('div');
            row.className = 'v2-voice-legend-row d-flex align-items-center gap-2 mt-1';
            const kindBadge = document.createElement('span');
            kindBadge.className = 'badge bg-secondary-subtle text-secondary-emphasis';
            kindBadge.textContent = p.kind;
            const labelInput = document.createElement('input');
            labelInput.type = 'text';
            labelInput.className = 'form-control form-control-sm';
            labelInput.style.maxWidth = '220px';
            labelInput.maxLength = 120;
            labelInput.value = p.label || '';
            labelInput.placeholder = p.displayLabel;
            labelInput.setAttribute('aria-label', 'Label for ' + p.displayLabel);
            labelInput.addEventListener('change', () => {
                api.upsertVocalPart(songId, { id: p.id, label: labelInput.value }).then((res) => {
                    store.set('vocalParts', res.vocalParts);
                }).catch(handleErr);
            });
            const lineCount = Object.values(data.lineAssignments || {}).reduce((n, rows) => n + rows.filter((a) => a.partId === p.id).length, 0);
            const delBtn = iconBtn('bi-trash', 'Delete ' + p.displayLabel, false, () => {
                const msg = lineCount
                    ? ('Delete ' + p.displayLabel + '? It is sung on ' + lineCount + ' line' + (lineCount === 1 ? '' : 's') + ' — those assignments will be removed too.')
                    : ('Delete ' + p.displayLabel + '?');
                if (!window.confirm(msg)) { return; }
                api.deleteVocalPart(songId, p.id).then((res) => { store.set('vocalParts', res.vocalParts); renderBody(); }).catch(handleErr);
            }, { className: 'btn btn-sm btn-outline-danger' });
            row.append(kindBadge, labelInput, delBtn);
            details.appendChild(row);
        });

        host.appendChild(details);
    }

    /* ==================================================================
     *  PUBLIC CONTRACT
     * ================================================================== */
    const offStore = store.subscribe('vocalParts', () => { if (!box.hidden) { renderBody(); } });

    function refresh() {
        /* Called by structure-tab.js's lyrics-textarea `input` handler
           right after renderChordRows() — re-reads the CURRENT
           comp.lines/comp.lineIds (selection clamped inside renderBody()
           itself) so a line inserted/removed above a ticked one never
           leaves this panel pointing at a stale index. */
        if (!box.hidden) { renderBody(); }
    }

    function destroy() {
        offStore();
        if (offPendingSaveListener) { offPendingSaveListener(); offPendingSaveListener = null; }
        if (statusTimer) { clearTimeout(statusTimer); }
    }

    wrap.append(toggle, box);
    return { el: wrap, refresh: refresh, destroy: destroy };
}
