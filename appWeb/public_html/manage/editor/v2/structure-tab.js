/* ==========================================================================
 *  structure-tab.js — the v2 Song Editor "Structure" tab (#1200, Phase 1)
 *
 *  The components + arrangement editor — the worst pain in the old editor (a
 *  full rebuild of every card on every keystroke/delete). Here, each component
 *  card is built ONCE and updates itself; only STRUCTURAL changes (add / delete
 *  / reorder) re-render the list, driven off the store's `components` slice.
 *  Every edit is an atomic granular save to api2.php — no whole-song save, no
 *  race. A failed save throws → a real error toast, local state untouched.
 *
 *  mountStructureTab(container, { store, api, songId, toast, registerFlush }) -> teardown fn
 *    store        : reactive store with a `components` slice (array of component rows)
 *    api          : editorApi from api-client.js
 *    songId       : the server SongId
 *    toast        : (message, type) => void   (optional)
 *    registerFlush: (fn) => void   (optional; #1846 — hands the shell a "flush
 *                   my pending debounced saves now" function for its manual
 *                   Save button — see flushPending() below)
 *
 *  #1627 items 1+3 — chords UI + per-line enrichment panel. Both extend
 *  buildCard(), which is why they land in one PR: a chords UI (ABOVE
 *  the enrichment panel, mirroring v1's card layout order), and
 *  enrichment-panel.js's buildEnrichmentPanel() for per-line
 *  language/translation/annotation. See enrichment-panel.js's own file
 *  header for the full per-line-enrichment design; see chordCellText()
 *  below + onChordRowInput()'s chords branch for the chords clear-semantics
 *  trap (empty ARRAY clears, `null` PRESERVES).
 *
 *  #1263 (chord-drift-on-reorder fix) — the chords box above became a
 *  PER-LINE editor: one chord input per lyric line (keyed to
 *  comp.lineIds[i] where available), replacing the single multiline
 *  textarea #1627 originally shipped. THE BUG: that textarea stored
 *  `comp.chords` as one array PARALLEL to `comp.lines` BY INDEX — correct
 *  while lines never moved, but the server's desired-lines builder
 *  (lyricLinesBuildDesiredFromComponents, includes/lyric_lines_sync.php)
 *  assigns `chords[i]` to line `i` positionally too, so REORDERING lines in
 *  the lyrics textarea silently left every chord behind on its OLD index —
 *  even though the server correctly Id-preserves each LINE's own identity
 *  (rule #25) and every per-line translation/annotation FK'd to it. A
 *  chord would drift onto whichever line now sat at its old position, with
 *  no error anywhere. STORAGE IS UNCHANGED (rule #25/#45 — still the same
 *  lines-parallel `chords` array on the SAME write path); only the EDITOR
 *  now (a) tracks each row against the line's own CONTENT/identity as the
 *  lyrics textarea changes, via the pure `remapChordsOnLinesChange()`
 *  helper below, and (b) adopts the server's read-back `lineIds` (rule
 *  #35) after every save in saveComponent(), which also un-stales the
 *  enrichment panel's own `comp.lineIds` anchors as a free side effect —
 *  see that function's own doc-block for the detail.
 * ========================================================================== */

import { buildEnrichmentPanel, componentLineId } from './enrichment-panel.js';
import { iconBtn } from './ui-helpers.js';

/* #1869 (epic #1863, CLAUDE.md rule #43's LAST picker item — registry
   SOURCING, not a typeahead). This was the whole section-type vocabulary
   until #1869: 10 types, hand-typed, requiring a code change + redeploy to
   grow. It now survives ONLY as the MINIMAL BUILT-IN FALLBACK — used when
   editor2.php's bootstrap payload (window._iHymnsSongPartTypes, sourced from
   the tblSongPartTypes registry via includes/song_part_type_helpers.php) is
   missing or empty, which happens on an un-migrated install (rule #19/#20:
   the migration is web-run, not automatic on deploy) or a test harness that
   never set the global. NEVER delete this const — it is what keeps the
   Structure tab usable on a database that hasn't run the #1138 migration. */
const COMPONENT_TYPES = ['verse', 'chorus', 'refrain', 'bridge', 'pre-chorus', 'tag', 'coda', 'intro', 'outro', 'interlude'];

/**
 * resolvePartTypes() — the section-type vocabulary the "Structure" tab's type
 * <select> renders (#1869). PREFERS the live `tblSongPartTypes` registry, shipped
 * by editor2.php as `window._iHymnsSongPartTypes` (a list of `{slug, name}`,
 * already ordered by the registry's own SortOrder — see
 * includes/song_part_type_helpers.php::songPartTypesForPicker()); FALLS BACK to
 * the hardcoded COMPONENT_TYPES list above only when that global is absent, not
 * an array, or empty (an un-migrated `tblSongPartTypes`, or this module running
 * outside editor2.php, e.g. a future standalone test harness).
 *
 * ELI5: normally the dropdown's choices come from the database, so a curator can
 * add "Vamp" or "Ad-Lib" without anyone touching this file — but if the database
 * hasn't been updated yet, the dropdown still shows the original 10 choices
 * instead of going blank.
 *
 * Computed ONCE at module-evaluation time (top-level `const` below), matching
 * every other classic-global vocab this page ships (window._iHymnsLinkTypes,
 * window._iHymnsRecordingIdTypes, window._iHymnsLicenceTypes — external-ids-panel.js
 * / rights-panel.js read theirs the same "read once, module scope" way).
 *
 * @returns {Array<{value:string, label:string}>}
 */
function resolvePartTypes() {
    const served = (typeof window !== 'undefined') ? window._iHymnsSongPartTypes : undefined;
    if (Array.isArray(served) && served.length > 0) {
        const mapped = served
            .filter((t) => t && typeof t.slug === 'string' && t.slug !== '')
            .map((t) => ({ value: t.slug, label: (typeof t.name === 'string' && t.name !== '') ? t.name : t.slug }));
        if (mapped.length > 0) { return mapped; }
    }
    return COMPONENT_TYPES.map((t) => ({ value: t, label: t.replace(/^\w/, (c) => c.toUpperCase()) }));
}

/* The resolved vocabulary — a list of {value, label} pairs, DB-sourced when
   available. buildCard() below is the ONE consumer (the type <select>). */
const PART_TYPES = resolvePartTypes();

const SAVE_DEBOUNCE_MS = 500;

/**
 * chordCellText(c) — render ONE stored chord cell back into its editable
 * string. A freshly-LOADED cell is an array per line (["C","G"], #1094); a
 * cell the user has already typed into THIS session is a plain per-line
 * string ("C G"), because the per-line chord input's own 'input' handler
 * (onChordRowInput(), in buildCard() below) writes strings, not arrays —
 * both render identically. Ported from v1's componentChordsToText()
 * (editor.js ~1304) and this file's own pre-#1263 whole-textarea version,
 * narrowed to ONE cell now that #1263 gives each lyric line its own input
 * instead of one shared multiline textarea. (#1094, #1627 item 1, #1263)
 * @param {*} c
 * @returns {string}
 */
function chordCellText(c) {
    if (Array.isArray(c)) { return c.join(' '); }
    return (c == null) ? '' : String(c);
}

/**
 * remapChordsOnLinesChange(oldLines, oldChords, newLines) — #1263. Re-maps a
 * component's per-line chord array onto a NEW lyric-line list after the
 * lyrics textarea changes, so a chord row FOLLOWS its lyric line's content
 * instead of staying pinned to a numeric index that just shifted under it —
 * the drift bug described in this file's own header. Storage is a `chords`
 * array PARALLEL to `lines` BY INDEX (rule #25/#45 — the wire contract this
 * helper must never change); this function only decides which OLD index's
 * value belongs at each NEW index.
 *
 * ELI5: move a lyric line and its chords move with it; fix a typo on a line
 * (this runs on every keystroke of the lyrics textarea — see its 'input'
 * handler below) and the chord stays put; add or remove a line and only the
 * truly new/removed line is affected — a chord never lands on a stranger's
 * line.
 *
 * ALGORITHM — the same "content first" matching family as the server's
 * Id-preserving diff (lyricLinesDiff(), includes/lyric_lines_sync.php),
 * simplified for a single component (no part-identity bucket, no
 * Levenshtein fuzzy pass — see below for why):
 *   PASS 1 — exact content match. Old-line indices are bucketed by TRIMMED
 *     text into FIFO queues; walking the new lines in order, each claims
 *     the OLDEST still-unclaimed old line with identical text. This alone
 *     is what makes a REORDER carry its chord, and what makes two
 *     DUPLICATE lines each keep their OWN chord rather than both grabbing
 *     the first one (they claim the queue in the same relative order they
 *     appear).
 *   PASS 2 — positional fallback for the LEFTOVERS only. Whatever pass 1
 *     could not place — because its text genuinely changed — is paired up
 *     by walking the remaining unmatched old lines and unmatched new lines
 *     BOTH in ascending original order and zipping them together. This is
 *     what makes an IN-PLACE EDIT keep its chord: every OTHER line already
 *     matched exactly in pass 1 (nothing else changed on this keystroke),
 *     so the one edited line is the ONLY leftover on both sides and pairs
 *     with itself. A genuinely inserted line has no leftover old
 *     counterpart once every unrelated line has exact-matched, so it
 *     correctly gets no chord; a genuinely removed line's chord is simply
 *     never claimed and is dropped with it.
 *   Any new line still unmatched after both passes gets `''`.
 * No Levenshtein/fuzzy pass, unlike the server's pass 3: pass 2 already
 * handles the dominant per-keystroke single-line-changed case exactly, and
 * a coarser multi-line rewrite (e.g. a paste) is a best-effort alignment
 * either way — that machinery's cost isn't worth paying on every keystroke
 * of a textarea `input` handler.
 *
 * CLEAR-SEMANTICS (rule #45, the SAME trap onChordRowInput() below
 * documents): when every remapped cell comes out blank, return `[]` — a
 * genuine empty array clears the stored chords server-side; a same-length
 * array of `''` does not (component_upsert's `isset()` gate treats it as
 * "chords supplied, all blank" rather than "no chords at all").
 *
 * @param {Array<string>} oldLines   comp.lines BEFORE this change
 * @param {?Array} oldChords         comp.chords BEFORE this change (parallel
 *   to oldLines); each cell may be a string or an array (#1094) — carried
 *   over VERBATIM, never renormalised, so a cell's own shape survives a move.
 * @param {Array<string>} newLines   comp.lines AFTER this change
 * @returns {Array} the remapped chords, same length as newLines, or `[]`
 *   when every remapped cell is blank.
 */
export function remapChordsOnLinesChange(oldLines, oldChords, newLines) {
    const oLines = Array.isArray(oldLines) ? oldLines : [];
    const oChords = Array.isArray(oldChords) ? oldChords : [];
    const nLines = Array.isArray(newLines) ? newLines : [];

    // PASS 1 — bucket old-line indices by trimmed text, FIFO per bucket.
    const buckets = new Map();
    oLines.forEach((line, i) => {
        const key = String(line == null ? '' : line).trim();
        if (!buckets.has(key)) { buckets.set(key, []); }
        buckets.get(key).push(i);
    });

    const claimedOld = new Array(oLines.length).fill(false);
    const matchedOldForNew = new Array(nLines.length).fill(-1);
    nLines.forEach((line, ni) => {
        const key = String(line == null ? '' : line).trim();
        const queue = buckets.get(key);
        if (queue && queue.length) {
            const oi = queue.shift();
            claimedOld[oi] = true;
            matchedOldForNew[ni] = oi;
        }
    });

    // PASS 2 — leftover old/new lines pair up in their own ascending relative order.
    const leftoverOld = [];
    for (let oi = 0; oi < oLines.length; oi++) { if (!claimedOld[oi]) { leftoverOld.push(oi); } }
    let cursor = 0;
    for (let ni = 0; ni < nLines.length; ni++) {
        if (matchedOldForNew[ni] === -1 && cursor < leftoverOld.length) {
            matchedOldForNew[ni] = leftoverOld[cursor];
            cursor++;
        }
    }

    const result = nLines.map((_line, ni) => {
        const oi = matchedOldForNew[ni];
        if (oi === -1) { return ''; }
        const v = oChords[oi];
        return (v === undefined) ? '' : v;
    });

    const hasAny = result.some((r) => (Array.isArray(r)
        ? r.some((c) => c != null && String(c).trim() !== '')
        : (r != null && String(r).trim() !== '')));
    return hasAny ? result : [];
}

/**
 * languageAutonym(tag) — the language's own native name for a BCP 47 tag
 * ("zh-Hans" -> "Chinese (Simplified)"), used by the "Use language name"
 * button below (#1860 Phase 5 §4.2, SD10). Browser-native
 * `Intl.DisplayNames` — no dependency, no server round-trip; falls back to
 * the raw tag on ANY failure (an unrecognised subtag, an engine without
 * `Intl.DisplayNames`), so a bad/unusual tag degrades to "showing the code"
 * rather than throwing. Kept LOCAL to this module (rule #22's posture) —
 * extract to `js/utils/` only once a second consumer needs it; today the
 * "Use language name" button is the only caller.
 * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Intl/DisplayNames
 * @param {string} tag BCP 47 language tag (e.g. "zh-Hans", "sr-Cyrl", "en").
 * @returns {string} The language's autonym, or the raw tag on failure.
 */
function languageAutonym(tag) {
    try {
        const base = String(tag).split('-')[0];
        return new Intl.DisplayNames([tag], { type: 'language' }).of(base) || String(tag);
    } catch (_e) { return String(tag); }
}

/**
 * derivedComponentName(comp) — the "Type Number" heading a component would
 * show with NO custom Label set ("Verse 1", "Chorus", "Bridge 2"). Extracted
 * so both headerText() below (the effective, custom-first heading) and the
 * Label input's LIVE placeholder (recomputed on type/number change, #1860
 * Phase 5 §4.2) share the ONE derivation instead of two copies drifting.
 * @param {{type?:string, number?:number}} comp
 * @returns {string}
 */
function derivedComponentName(comp) {
    return (comp.type || 'verse').replace(/^\w/, (c) => c.toUpperCase()) + (comp.number ? ' ' + comp.number : '');
}

/**
 * headerText(comp) — the EFFECTIVE card-header text: the curator's custom
 * Label when one is set, else the derived "Type Number" heading (#1860 Phase
 * 5 §4.2, D1). D1's rule-#27 hide-when-equal fold is enforced SERVER-SIDE in
 * `component_upsert` (a typed label equal to the derived name is stored as
 * NULL) — saveComponent()'s rule-#35 read-back copies that fold back onto
 * `comp.label` client-side, so this helper never has to duplicate the
 * comparison itself; it only ever sees a label the server actually kept.
 * ONE helper for the three sites that used to inline this derivation
 * (initial build + the type/number change handlers below) — extracted per
 * the modularity rule so the D1 override lands in exactly one place.
 * @param {{type?:string, number?:number, label?:?string}} comp
 * @returns {string}
 */
function headerText(comp) {
    const derived = derivedComponentName(comp);
    return (comp.label && comp.label.trim()) ? comp.label.trim() : derived;
}

export function mountStructureTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    /* #1846 — hand the shell a "flush me now" function once, at mount time
       (a plain injected callback, not a DOM event — rule #35/#1581). Defaults
       to a no-op so the tab still mounts standalone in a test harness that
       doesn't pass registerFlush. */
    const registerFlush = opts.registerFlush || function () {};

    /* Per-component debounce timers for lyric/chord saves. */
    const saveTimers = new Map();
    /* #1846 — comp._key -> the pending comp, same key space as saveTimers
       above. Lets the shell's manual Save button fire a pending debounced
       write early via flushPending() below. Structural ops (move/remove/add)
       call saveComponent()/the API directly and never touch this map — only
       a lyric/chord textarea's debouncedSave() does. */
    const pendingSaves = new Map();

    /* #1860 Phase 5 §4.3 — detach fns for every card's "Source work"
       iHymnsPlaceSearch instance currently mounted. UNLIKE saveTimers/
       pendingSaves above (keyed by the persistent comp._key, so they survive
       a re-render), a picker instance is bound to the ACTUAL <input> DOM
       node buildCard() just created — and place-search.js appends its
       dropdown panel + status icon + live region to `document.body`, OUTSIDE
       this tab's `container` (mirrors metadata-tab.js's placeDetach/
       tuneDetach/holderDetach for the SAME shared module). `render()` wipes
       and rebuilds every card on every structural op (move/remove/add), so
       without detaching these FIRST, each such op would strand another
       picker's body-appended nodes + its `window` scroll/resize listeners
       forever — never cleaned up until the whole tab tears down. Cleared in
       both render() (before the rebuild) and teardown() below. */
    let cardPickerDetachFns = [];

    function debouncedSave(comp) {
        const key = comp._key;
        if (saveTimers.has(key)) { clearTimeout(saveTimers.get(key)); }
        pendingSaves.set(key, comp);
        saveTimers.set(key, setTimeout(() => { pendingSaves.delete(key); saveComponent(comp); }, SAVE_DEBOUNCE_MS));
    }

    /**
     * #1846 — the shell's manual Save button's hook into this tab: cancel
     * every pending per-component debounce timer and fire each recorded
     * component's save immediately instead of waiting out SAVE_DEBOUNCE_MS.
     *
     * ELI5: if you were mid-typing a lyric or chord line, clicking Save
     * shouldn't make you wait for the pause-timer — this sends it right now.
     *
     * @returns {Promise<number>} Resolves once every flushed save has
     *   settled to the COUNT of saves that FAILED (0 = all ok) — the shell's
     *   Save button (#1846/#1851) sums this across every tab to decide
     *   between "All changes saved." and a real failure report. saveComponent()
     *   already catches its own rejection into a toast and resolves a
     *   boolean rather than rethrowing, so the `.catch(() => false)` here is
     *   belt-and-braces, not the normal path — this function itself must
     *   still never reject.
     */
    function flushPending() {
        saveTimers.forEach((t) => clearTimeout(t));
        saveTimers.clear();
        const proms = [];
        pendingSaves.forEach((comp) => {
            proms.push(Promise.resolve(saveComponent(comp)).catch(() => false));
        });
        pendingSaves.clear();
        return Promise.all(proms).then((results) => results.reduce((n, ok) => n + (ok === false ? 1 : 0), 0));
    }
    registerFlush(flushPending);

    /** Persist one component (create or update) atomically. On a CREATE, adopt
     *  the server-assigned componentId so later edits UPDATE in place.
     *  #1846/#1851 — resolves TRUE on success, FALSE on failure (never
     *  rejects); flushPending() below sums the FALSEs into a failure count
     *  for the shell's Save-button outcome report. */
    async function saveComponent(comp) {
        try {
            const payload = {
                id:        comp.id || 0,
                type:      comp.type,
                number:    comp.number,
                sortOrder: comp.sortOrder,
                lines:     comp.lines,
                chords:    comp.chords || null,
                language:  comp.language || null,
                /* #1860 Phase 5 §4.1 — custom display Label (REQ 3b) +
                   provenance SourceWorkId (REQ 2). ALWAYS sent, exactly like
                   `language` above (never gated behind a "did this change"
                   check): this module's own `comp` is authoritative for its
                   OWN saves — the three independent silent-wipe-preserve
                   layers in component_upsert / lyric_lines_sync.php (§3)
                   exist to protect every OTHER funnel that might omit these
                   keys (a stale v1 editor tab, a lyrics_ingest re-ingest, an
                   old revision restore), not this always-authoritative one. */
                label:        comp.label || null,
                sourceWorkId: comp.sourceWorkId || null,
                /* #1627 item 3 — per-line language OVERRIDES (comp.languages,
                   an array parallel to lines), distinct from the single
                   per-SECTION `language` above. `comp.languages || null`:
                   once enrichment-panel.js's setLineLang() has turned this
                   into an array it is NEVER null again (see that file's
                   clear-semantics note), so this only ever sends `null` when
                   no per-line override has ever been set on this component —
                   a true no-op, not a clear attempt. */
                languages: comp.languages || null,
            };
            const res = await api.upsertComponent(songId, payload);
            if (!comp.id && res.componentId) { comp.id = res.componentId; }
            /* #1860 Phase 5 §4.1 — rule #35 read-back: adopt what the server
               actually stored, never assume the just-sent payload survived
               verbatim. component_upsert's D1 hide-when-equal fold (a typed
               Label equal to the derived "Verse 1" heading is stored as
               NULL, §3.2) happens SERVER-SIDE only — copying `res.label`
               back onto `comp.label` here is what makes that fold visible
               client-side; without it the Label input would keep showing
               text the database no longer has. `hasOwnProperty`, not a
               truthy check: the server always sends the key (possibly
               `null`), and a `null` IS the value to adopt. */
            if (Object.prototype.hasOwnProperty.call(res, 'label')) { comp.label = res.label; }
            /* #1263 — rule #35 read-back, ADDITIVE (api2.php's component_upsert
               response gains `lineIds` alongside the existing `label`/
               `sourceWorkId` echoes, never removes anything): adopt the
               FRESHLY re-read line ids for this component so comp.lineIds never
               goes stale after a save. Before this, comp.lineIds was set ONCE
               at load_song and never refreshed — every subsequent lyric edit
               (insert/delete/reorder a line) silently staled it, and the
               enrichment panel's own per-line translation/annotation "add"
               buttons (keyed on comp.lineIds[i], enrichment-panel.js) degrade
               to "not saved yet" for a line whose real id had already changed
               underneath them. `hasOwnProperty`, matching the `label` adoption
               immediately above: the key is always present (possibly `[]` on
               an un-migrated/lines-json-fallback install), and an explicit `[]`
               IS the value to adopt, not a reason to skip the assignment. */
            if (Object.prototype.hasOwnProperty.call(res, 'lineIds')) { comp.lineIds = res.lineIds; }
            if (res.sourceWorkIdIgnored) {
                /* SD1 — the server coerced an unresolvable sourceWorkId to
                   NULL rather than failing the whole section save (a
                   work-link problem must never block a save); tell the
                   curator why the link they just set silently didn't take. */
                toast('Source work not found — cleared.', 'warning');
                comp.sourceWorkId = null;
            }
            return true;
        } catch (e) {
            toast('Could not save section: ' + e.message, 'danger');
            return false;
        }
    }

    /** Build one component card. Wires its own inputs; no list re-render on edit. */
    function buildCard(comp, index, total) {
        const card = document.createElement('div');
        card.className = 'card mb-3 component-card';
        card.dataset.key = comp._key;

        const header = document.createElement('div');
        header.className = 'card-header d-flex align-items-center gap-2 flex-wrap';

        const label = document.createElement('strong');
        label.className = 'me-auto';
        label.textContent = headerText(comp);

        const typeSel = document.createElement('select');
        typeSel.className = 'form-select form-select-sm';
        typeSel.style.width = '150px';
        /* #1869 — PART_TYPES (module scope, resolved once above), not the raw
           COMPONENT_TYPES fallback const directly: DB-sourced when
           window._iHymnsSongPartTypes was served, hardcoded-fallback
           otherwise. If the currently-loaded component's type isn't in the
           resolved list (e.g. free-text data older than the registry), no
           option is marked selected — the same pre-existing behaviour as
           before #1869, unchanged here. */
        PART_TYPES.forEach((t) => {
            const o = document.createElement('option');
            o.value = t.value;
            o.textContent = t.label;
            if (t.value === comp.type) { o.selected = true; }
            typeSel.appendChild(o);
        });
        typeSel.addEventListener('change', () => {
            comp.type = typeSel.value;
            label.textContent = headerText(comp);
            /* #1860 Phase 5 §4.2 — the Label input's placeholder is the LIVE
               derived name (recomputed here on every type change) so an
               EMPTY label box always previews what would actually render —
               the store-NULL-when-equal affordance (D1). labelInput is
               declared further down this same buildCard() call but this
               callback only runs later, on 'change', by which point it's
               assigned — no temporal-dead-zone hazard. */
            labelInput.placeholder = derivedComponentName(comp);
            saveComponent(comp);
        });

        const numInput = document.createElement('input');
        numInput.type = 'number';
        numInput.min = '0';
        numInput.className = 'form-control form-control-sm';
        numInput.style.width = '90px';
        numInput.placeholder = '#';
        numInput.value = comp.number ? String(comp.number) : '';
        numInput.addEventListener('change', () => {
            comp.number = parseInt(numInput.value, 10) || 0;
            label.textContent = headerText(comp);
            /* #1860 Phase 5 §4.2 — see the matching comment on typeSel's
               'change' handler above; same live-placeholder reason. */
            labelInput.placeholder = derivedComponentName(comp);
            saveComponent(comp);
        });

        /* #1206 — per-section language override (BCP 47, incl. script subtag).
           Blank = inherit the song language. The public render emits this as
           lang=… + dir on the verse (#858/#1205); component_upsert already
           persists it. Loose client validation (non-blocking) flags a malformed
           tag without preventing the save. */
        const langInput = document.createElement('input');
        langInput.type = 'text';
        langInput.className = 'form-control form-control-sm';
        langInput.style.width = '130px';
        langInput.placeholder = 'lang (e.g. zh-Hans)';
        langInput.title = 'Per-section language override — BCP 47, including script where relevant (en, sw, zh-Hans, sr-Cyrl, ja-Latn). Blank = inherit the song language.';
        langInput.setAttribute('aria-label', 'Section language (BCP 47)');
        langInput.value = comp.language || '';
        langInput.addEventListener('change', () => {
            const v = langInput.value.trim();
            comp.language = v !== '' ? v : null;
            langInput.classList.toggle('is-invalid', v !== '' && !/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/.test(v));
            /* #1860 Phase 5 §4.2 — the "Use language name" button is only
               meaningful once a language is set; keep its disabled state
               live as this field changes rather than only correct at
               mount. langNameBtn is declared further down this same
               buildCard() call — same no-TDZ-hazard reasoning as
               labelInput above (this callback only runs later). */
            langNameBtn.disabled = !comp.language;
            saveComponent(comp);
        });

        /* #1860 Phase 5 §4.2 — optional custom display Label (REQ 3b). D1:
           storing a label EQUAL to the derived name is folded to NULL
           SERVER-SIDE (component_upsert, rule #27) — see saveComponent()'s
           rule-#35 read-back above, which is what surfaces that fold here.
           Commit on 'change' (blur/Enter), never a keystroke — matches
           every other section control on this card (langInput immediately
           above). */
        const labelInput = document.createElement('input');
        labelInput.type = 'text';
        labelInput.className = 'form-control form-control-sm';
        labelInput.style.width = '160px';
        labelInput.maxLength = 100;
        /* LIVE derived name — an empty box always previews what would
           actually render (the store-NULL-when-equal affordance), kept in
           sync by the typeSel/numInput 'change' handlers above. */
        labelInput.placeholder = derivedComponentName(comp);
        labelInput.title = 'Custom display name for this section, replacing the derived heading above (e.g. a "Kyrie" or "isiZulu"). Display only — the section Type still drives styling, arrangement and machine exports.';
        labelInput.setAttribute('aria-label', 'Custom section label (display only)');
        labelInput.value = comp.label || '';
        labelInput.addEventListener('change', () => {
            comp.label = labelInput.value.trim() || null;
            label.textContent = headerText(comp);
            saveComponent(comp);
        });

        /* #1860 Phase 5 §4.2 — D2/SD10: OPT-IN language-name fill, never an
           automatic render-time substitution. Disabled until this section
           has a Language set (kept live by langInput's 'change' handler
           above). */
        const langNameBtn = document.createElement('button');
        langNameBtn.type = 'button';
        langNameBtn.className = 'btn btn-sm btn-outline-secondary';
        langNameBtn.textContent = 'Use language name';
        langNameBtn.setAttribute('aria-label', 'Use language name as label');
        langNameBtn.title = 'Fill the label above with this section\'s language name (e.g. zh-Hans -> "Chinese (Simplified)").';
        langNameBtn.disabled = !comp.language;
        langNameBtn.addEventListener('click', () => {
            const autonym = languageAutonym(comp.language);
            labelInput.value = autonym;
            comp.label = autonym;
            label.textContent = headerText(comp);
            saveComponent(comp);
        });

        const btnUp = iconBtn('bi-arrow-up', 'Move up', index === 0, () => move(index, -1));
        const btnDown = iconBtn('bi-arrow-down', 'Move down', index === total - 1, () => move(index, 1));
        const btnDel = iconBtn('bi-x-lg', 'Remove section', false, () => removeComponent(comp));
        btnDel.classList.add('text-danger');

        const btns = document.createElement('span');
        btns.className = 'btn-group btn-group-sm';
        btns.append(btnUp, btnDown, btnDel);

        header.append(label, typeSel, numInput, langInput, labelInput, langNameBtn, btns);

        /* #1860 Phase 5 §4.3 — per-section "Source work" picker (REQ 2, rule
           #43). PROGRESSIVE DISCLOSURE: a collapsed "Source work" toggle row
           (the `w-100` class forces it onto its own line inside `header`'s
           flex-wrap layout) so a plain, non-medley section's card looks
           exactly as it did before this feature; auto-expanded when the
           loaded component already carries a link. This is a LINK-ONLY
           typeahead (pickMode:'value') over EXISTING tblWorks rows via
           work_search — deliberately NO find-or-create here: a wrong
           auto-mint would pollute the works registry, and #1860 §3.3.1's
           "never auto-unlink" rule means this widget only ever sets/clears
           THIS section's SourceWorkId, never deletes a tblWorkComponents row
           (that lockstep is server-side, additive-only — Commit 5). */
        const workRow = document.createElement('div');
        workRow.className = 'w-100 mt-2';

        const workToggle = document.createElement('button');
        workToggle.type = 'button';
        workToggle.className = 'btn btn-sm btn-link p-0 text-decoration-none';
        workToggle.innerHTML = '<i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Source work';

        const workBox = document.createElement('div');
        workBox.className = 'mt-1 d-flex align-items-center gap-2';
        /* Auto-expanded only when a link is already set — otherwise this
           whole row stays collapsed so an ordinary section's card is
           unchanged from before this feature. */
        workBox.style.display = comp.sourceWorkId ? '' : 'none';

        const workInput = document.createElement('input');
        workInput.type = 'text';
        workInput.className = 'form-control form-control-sm';
        workInput.style.width = '260px';
        workInput.placeholder = 'Link this section to a work (medley)…';
        workInput.title = 'Links this section to an existing Work — e.g. a hymn stitched into a medley. Never creates a new work.';
        workInput.setAttribute('aria-label', 'Source work for this section (medley provenance)');
        /* v1 roughness the spec explicitly accepts: show the linked work's
           title from the LAST pick made THIS session (comp._sourceWorkTitle,
           set by onSelect below) — no extra request is sent at mount to
           resolve a title from the id alone, so a component that already
           had a link BEFORE this page load shows "Work #<id>" until the
           curator opens the picker and re-picks, or Commit 9's snapshot
           `works` attach supplies real titles. */
        workInput.value = comp.sourceWorkId
            ? (comp._sourceWorkTitle || ('Work #' + comp.sourceWorkId))
            : '';

        workToggle.addEventListener('click', () => {
            workBox.style.display = (workBox.style.display === 'none') ? '' : 'none';
            if (workBox.style.display !== 'none') { workInput.focus(); }
        });

        workInput.addEventListener('input', () => {
            /* Free-typing invalidates a previously-picked work — the same
               contract the Copyright Holder input uses in metadata-tab.js
               (#1862). This only clears LOCAL state; nothing is sent to the
               server until a commit event fires (rule #43 — never a mint or
               a save on a debounced keystroke, #1679's anti-pattern). */
            comp.sourceWorkId = null;
            comp._sourceWorkTitle = null;
        });
        workInput.addEventListener('change', () => {
            /* Commit-on-change clearing only: an emptied box unlinks and
               saves. A typed-but-never-picked name is deliberately NOT
               resolved to a work here (no find-or-create for provenance,
               rule #43) — it simply isn't persisted; the 'input' listener
               above has already dropped the stale sourceWorkId locally. */
            if (workInput.value.trim() === '') {
                comp.sourceWorkId = null;
                comp._sourceWorkTitle = null;
                saveComponent(comp);
            }
        });

        if (window.iHymnsPlaceSearch && typeof window.iHymnsPlaceSearch.attach === 'function') {
            const workDetach = window.iHymnsPlaceSearch.attach(workInput, {
                minChars: 2,
                pickMode: 'value',
                noun: { singular: 'work', plural: 'works' },
                // #1855-style: extensionless, matches every sibling searchUrl on this shell.
                searchUrl: (q) => '/manage/editor/api2?action=work_search&q=' + encodeURIComponent(q) + '&limit=10',
                parseResults: (d) => (d.suggestions || []).map((s) => ({
                    id: s.id,
                    display_name: s.title,
                    hint: s.iswc || s.ccli || '',
                })),
                onSelect: (c) => {
                    comp.sourceWorkId = c.id;
                    comp._sourceWorkTitle = c.display_name;
                    workInput.value = c.display_name;
                    saveComponent(comp);
                },
            });
            /* #1860 Phase 5 §4.3 — track this card's picker so render()/
               teardown() below can detach it before its <input> is
               discarded (see the cardPickerDetachFns declaration up top for
               why: the module appends body-level nodes this tab's own
               container.innerHTML='' cannot reach). */
            if (workDetach) { cardPickerDetachFns.push(workDetach); }
        }

        workBox.appendChild(workInput);
        workRow.append(workToggle, workBox);
        header.appendChild(workRow);

        const body = document.createElement('div');
        body.className = 'card-body';
        const ta = document.createElement('textarea');
        ta.className = 'form-control component-lyrics';
        ta.rows = Math.max(2, (comp.lines || []).length);
        ta.placeholder = 'Enter lyrics here…';
        ta.value = Array.isArray(comp.lines) ? comp.lines.join('\n') : '';
        ta.addEventListener('input', () => {
            const oldLines = Array.isArray(comp.lines) ? comp.lines : [];
            const newLines = ta.value.split('\n');
            /* #1263 — remap BEFORE reassigning comp.lines (remapChordsOnLinesChange
               needs the PRE-edit line list to match against), and ONLY when chords
               have actually been engaged with (comp.chords is already an array —
               see that helper's own doc-block): a component that has never had a
               chord touched stays `null`/absent, so a plain lyric edit never
               manufactures — and therefore never sends — a chords array out of
               thin air. */
            if (Array.isArray(comp.chords)) {
                comp.chords = remapChordsOnLinesChange(oldLines, comp.chords, newLines);
            }
            comp.lines = newLines;
            renderChordRows();   // keep the per-line chord rows in lockstep — LOCAL re-render, not store.set
            debouncedSave(comp);   // incremental — NO list re-render
        });
        body.appendChild(ta);

        /* #1627 item 1 / #1263 — one chord INPUT per lyric line (collapsible),
           ABOVE the enrichment panel (mirrors v1's card layout order). Persisted
           via saveComponent()'s existing `chords: comp.chords || null` (above);
           see this file's own #1263 header paragraph for why a single shared
           textarea was replaced by per-line inputs. Auto-opened when the loaded
           component already carries chords, same "hasChords" test as v1's
           chordsBox.style.display. */
        const chordsWrap = document.createElement('div');
        chordsWrap.className = 'mt-2';
        const hasChords = Array.isArray(comp.chords) && comp.chords.some((c) => c && (Array.isArray(c) ? c.length : String(c).trim()));
        const chordsToggle = document.createElement('button');
        chordsToggle.type = 'button';
        chordsToggle.className = 'btn btn-sm btn-link p-0 text-decoration-none';
        chordsToggle.innerHTML = '<i class="bi bi-music-note-beamed me-1" aria-hidden="true"></i>Chords';
        const chordsBox = document.createElement('div');
        chordsBox.className = 'mt-1';
        chordsBox.style.display = hasChords ? '' : 'none';
        const chordsRowsHost = document.createElement('div');
        chordsRowsHost.className = 'component-chords-rows';

        /* Every currently-mounted chord <input>, index-parallel to comp.lines —
           rebuilt fresh by renderChordRows() below on every call, so it is
           always in step with whatever DOM renderChordRows() most recently
           built. Read by onChordRowInput() to recompute the FULL chords array
           on any single row's edit (the same "whole textarea's value" shape
           the old single-textarea handler read, just sourced from N inputs). */
        let chordRowInputs = [];

        /** Rebuild the per-line chord rows from the CURRENT comp.lines /
         *  comp.chords. Called once at mount and again — LOCALLY, never via
         *  store.set('components', …) — from the lyrics textarea's own
         *  'input' handler above whenever comp.lines changes, so a row's
         *  lyric preview and its input's line-identity never go stale
         *  (#1263). Each row's `data-line-id` is comp.lineIds[i] via the
         *  SAME "unsaved line ⇒ 0" degrade enrichment-panel.js already
         *  established (componentLineId(), imported above — the modularity
         *  rule) — a display/debugging aid only; the SAVED payload stays the
         *  index-parallel `chords` array onChordRowInput() builds below,
         *  never keyed by this id. */
        function renderChordRows() {
            chordsRowsHost.innerHTML = '';
            chordRowInputs = [];
            const lines = Array.isArray(comp.lines) ? comp.lines : [];
            lines.forEach((lineText, i) => {
                const row = document.createElement('div');
                row.className = 'd-flex align-items-center gap-2 mb-1 component-chord-row';
                row.dataset.lineId = String(componentLineId(comp, i));

                const preview = document.createElement('span');
                preview.className = 'text-muted small text-truncate component-chord-line-preview';
                preview.style.width = '200px';
                preview.style.flex = '0 0 auto';
                const text = (lineText && String(lineText).trim()) ? String(lineText) : '(blank line)';
                preview.textContent = text;
                preview.title = text;

                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control form-control-sm component-chord-line font-monospace';
                input.placeholder = 'Chords for this line';
                input.setAttribute('aria-label', 'Chords for line ' + (i + 1) + ': ' + text);
                input.value = chordCellText(Array.isArray(comp.chords) ? comp.chords[i] : null);
                input.addEventListener('input', onChordRowInput);

                chordRowInputs.push(input);
                row.append(preview, input);
                chordsRowsHost.appendChild(row);
            });
        }

        /** ANY chord row's input fired — recompute the FULL per-line chords
         *  array from EVERY row (never just the one that changed) and hand it
         *  to the exact save path + clear-semantics #1627 item 1 established. */
        function onChordRowInput() {
            /* #1968 P6 (commit C5) — RIGHT-trim only, never l.trim(). Mirrors the identical fix
               in v1's editor.js (manage/editor/editor.js) — a stored chord cell is a POSITIONED
               STRING (#299/#1094, and — since #1968 P6 — PP7's own import/export shape, plan
               §2.2), so `l.trim()` would silently destroy a PP7-imported chord's leading column
               the moment a curator touched this box. A right-trim-only transform still correctly
               collapses an all-whitespace row to '' (every character IS trailing whitespace), so
               the CLEAR-SEMANTICS logic two lines below (`rows.some((r) => r !== '')`) is
               unaffected — only a row with a REAL leading gap before its first chord changes
               behaviour, from corrupted to preserved. */
            const rows = chordRowInputs.map((inp) => inp.value.replace(/\s+$/, ''));
            /* CLEAR-SEMANTICS TRAP, opposite direction from the per-line
               language one in enrichment-panel.js: component_upsert PRESERVES
               the stored chords when the `chords` key is absent/null
               (isset() gate) but CLEARS them when it's an empty array. So an
               all-blank set of rows must become `[]`, never a same-length array
               of '' — sending `['','','']` would "succeed" but silently
               leave the old chord symbols in place, and `null` would too.
               `[] || null` in saveComponent() stays `[]` (a truthy array),
               so this is the one line that has to get it right. */
            comp.chords = rows.some((r) => r !== '') ? rows : [];
            debouncedSave(comp);   // incremental — NO list re-render
        }

        renderChordRows();
        chordsToggle.addEventListener('click', () => {
            chordsBox.style.display = (chordsBox.style.display === 'none') ? '' : 'none';
            if (chordsBox.style.display !== 'none' && chordRowInputs[0]) { chordRowInputs[0].focus(); }
        });
        const chordsHint = document.createElement('div');
        chordsHint.className = 'form-text small';
        chordsHint.textContent = 'Optional. One chord field per lyric line — leading spaces position the chord within it.';
        chordsBox.append(chordsRowsHost, chordsHint);
        chordsWrap.append(chordsToggle, chordsBox);
        body.appendChild(chordsWrap);

        /* #1627 item 3 — per-line language / translation / annotation panel. */
        body.appendChild(buildEnrichmentPanel(comp, { store, api, songId, toast, saveComponent }));

        card.append(header, body);
        return card;
    }

    /* ---- structural ops (these DO re-render via the store) ---- */

    async function move(index, dir) {
        const comps = store.get('components').slice();
        const target = index + dir;
        if (target < 0 || target >= comps.length) { return; }
        const tmp = comps[index]; comps[index] = comps[target]; comps[target] = tmp;
        comps.forEach((c, i) => { c.sortOrder = i; });
        store.set('components', comps);   // re-renders
        try {
            await api.reorderComponents(songId, comps.filter((c) => c.id).map((c) => c.id));
        } catch (e) { toast('Could not reorder: ' + e.message, 'danger'); }
    }

    /**
     * #1851 — cancel this component's pending debounced lyric/chord autosave
     * FIRST. Without this, an edit-then-immediate-delete (within
     * SAVE_DEBOUNCE_MS) left the debounce timer armed: it fired
     * component_upsert AFTER deleteComponent had already removed the row,
     * and api2.php's upsert re-APPENDS a component whose id no longer
     * exists — the section resurrects itself right after being deleted.
     * Same key space as debouncedSave() above (comp._key).
     */
    async function removeComponent(comp) {
        const key = comp._key;
        if (saveTimers.has(key)) { clearTimeout(saveTimers.get(key)); saveTimers.delete(key); }
        pendingSaves.delete(key);
        const comps = store.get('components').filter((c) => c !== comp);
        comps.forEach((c, i) => { c.sortOrder = i; });
        store.set('components', comps);   // re-renders immediately
        if (comp.id) {
            try { await api.deleteComponent(songId, comp.id); }
            catch (e) { toast('Could not delete section: ' + e.message, 'danger'); }
        }
    }

    async function addComponent() {
        const comps = store.get('components').slice();
        const comp = { _key: 'c' + comps.length + '_' + comps.reduce((a, c) => a + (c.id || 0), 0), id: 0, type: 'verse', number: comps.length + 1, sortOrder: comps.length, lines: [''], chords: null, language: null };
        comps.push(comp);
        store.set('components', comps);   // re-renders
        await saveComponent(comp);        // create → adopts the server id
    }

    /* ---- render ---- */

    function render() {
        /* #1860 Phase 5 §4.3 — detach every card's "Source work" picker
           BEFORE wiping the container: place-search.js appends its dropdown
           panel/status icon/live region to `document.body` (outside
           `container`), so `container.innerHTML = ''` below cannot reach
           them — without this, every structural op (move/remove/add) would
           strand another instance's body-level nodes + `window` scroll/
           resize listeners forever. Mirrors metadata-tab.js's placeDetach/
           tuneDetach/holderDetach doing the same thing at the top of ITS
           render() for the SAME shared module. */
        cardPickerDetachFns.forEach((fn) => { try { fn(); } catch (_e) {} });
        cardPickerDetachFns = [];
        container.innerHTML = '';
        const comps = store.get('components') || [];
        comps.forEach((c, i) => {
            if (!c._key) { c._key = 'c' + i + '_' + (c.id || 'new'); }
            container.appendChild(buildCard(c, i, comps.length));
        });

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn btn-sm btn-outline-primary';
        addBtn.innerHTML = '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add section';
        addBtn.addEventListener('click', addComponent);
        container.appendChild(addBtn);
    }

    const off = store.subscribe('components', render);
    render();

    return function teardown() {
        off();
        saveTimers.forEach((t) => clearTimeout(t));
        saveTimers.clear();
        pendingSaves.clear();   // #1846 — no lingering references once the tab is gone
        /* #1860 Phase 5 §4.3 — detach every still-mounted "Source work"
           picker; see the matching comment at the top of render() above for
           why this can't be skipped (body-level nodes container.innerHTML
           can't reach). */
        cardPickerDetachFns.forEach((fn) => { try { fn(); } catch (_e) {} });
        cardPickerDetachFns = [];
        container.innerHTML = '';
    };
}
