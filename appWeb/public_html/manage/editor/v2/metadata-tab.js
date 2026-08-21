/* ==========================================================================
 *  metadata-tab.js — the v2 Song Editor "Metadata" tab (#1200, Phase 2)
 *
 *  The song's scalar fields. Each input saves ITS OWN field atomically via the
 *  granular `metadata_field_update` endpoint (debounced for text, immediate for
 *  selects/checkboxes) — no whole-song save. A failed save surfaces a real error
 *  and the field keeps the typed value for retry.
 *
 *  mountMetadataTab(container, { store, api, songId, toast, onSongIdChange,
 *                                getSongbooks, whenSongbooksReady, registerFlush }) -> teardown fn
 *  registerFlush(fn) — #1846: hands the shell a "flush my pending debounced
 *  saves now" function for its manual Save button. See flushPending() below.
 *  Reads initial values from the store's `song` slice (the tblSongs row, as
 *  returned by api2.php load_song — note the columns are PascalCase).
 *
 *  #1862 (epic #1863) — MAJOR REORG. The flat `row g-3` grid this tab used to
 *  pour every field into is now three labelled `<fieldset>` blocks (Identity /
 *  Composition IDs / Publication & Copyright — see BLOCKS below), matching the
 *  song-key/external-ids panels' visual shape. Four fields RETIRED from the
 *  editable grid because the app now DERIVES them instead of asking a curator
 *  to maintain them by hand (rule #44):
 *    - `copyright` (free text)      -> a collapsed "Custom statement (override)"
 *      disclosure, used only when the structured years+holder fields are both
 *      empty; a live "Displayed as: …" preview replaces the plain text row.
 *    - `copyrightHolder` (free text)-> a bespoke tblPublishers-backed picker
 *      (mirrors the Tune control below), activating the #1864 dormant
 *      CopyrightHolderId FK.
 *    - `hasAudio`/`hasSheetMusic` (checkboxes) -> a read-only derived line —
 *      the server now computes both from a union of hosted media + legacy
 *      static files (includes/song_media_flags.php); manually ticking a box
 *      that predicted a file's existence, rather than checking, was the
 *      exact "vanity field" rule #44 exists to remove.
 *  Two NEW derived lines replace the deleted rights-panel.js picker (owner's
 *  #1862 refinement comment: rights coverage is DERIVED, never picked) and
 *  surface a public-domain SUGGESTION next to each PD checkbox — a hint with
 *  a one-click "Use" adopt, never an auto-tick (owner-stated, twice).
 * ========================================================================== */

/* #1849 — the Language field is the shared IETF BCP 47 live-search picker
   (js/modules/ietf-language-picker.js, #681), NOT a plain FIELDS text row —
   see the FIELDS comment below for why, and the render()-time block near
   "Composition origin" for where it actually mounts. Reused, not re-forked
   (v1's editor + /manage/songbooks already mount the same module). Only
   `bootIetfLanguagePicker` is imported: seeding uses the module's OWN
   `data-initial-tag` hydration path (see that render()-time block), so
   `decomposeTag` is never called directly here — importing it unused would
   also fail this repo's `no-unused-vars` ESLint rule. */
import { bootIetfLanguagePicker } from '../../../js/modules/ietf-language-picker.js';

const SAVE_DEBOUNCE_MS = 500;

/* field key (api2 ED2_META_FIELDS) -> [label, tblSongs column, input kind, block]
 *
 * Input kinds: 'text' | 'number' (debounced on `input`), 'check' (immediate on
 * `change`), 'select' (immediate on `change`, options supplied by the caller).
 *
 * `block` routes the control into one of the three BLOCKS fieldsets below
 * (#1862) — the single field registry stays intact (rule "extract first, use
 * second" — never fork the per-kind control builders), only the CONTAINER
 * each control's `col` div is appended to changes.
 *
 * #1679 H1 — `songbook` is a SELECT, and deliberately not a text box. Since the
 * move became a re-key, writing this field mints a NEW SongId, clears Number,
 * cascades ~41 child tables and writes a permanent tblSongRedirects row. As a
 * debounced text input that irreversible action fired on a KEYSTROKE PAUSE: a
 * curator clearing the field and typing `C`, `P` who paused half a second after
 * the `C` moved the song into songbook `C`, and moving it back restored neither
 * the id nor the number. A closed list removes the typo entirely, `change`
 * removes the timer, and the confirm below removes the surprise. v1 has always
 * validated its move target against the loaded songbook list (editor.js's bulk
 * move) — this is the same rule, expressed as a control the user cannot get
 * wrong rather than as a check after the fact. */
const FIELDS = [
    ['title',              'Song Title',        'Title',              'text',   'identity'],
    ['subtitle',           'Subtitle',          'Subtitle',           'text',   'identity'],
    ['disambiguation',     'Disambiguation (short parenthetical)', 'Disambiguation', 'text', 'identity'],
    ['number',             'Song Number',       'Number',             'number', 'identity'],
    ['songbook',           'Songbook',          'SongbookAbbr',       'select', 'identity'],
    /* #1849 — 'language' DELETED from this list. A plain FIELDS text row made
       curators type/paste a raw BCP 47 tag ("pt-BR") by hand; the rich
       live-search Language/Script/Region picker this field needs already
       exists and is mounted by BOTH v1's editor and /manage/songbooks
       (js/modules/ietf-language-picker.js, #681) — v2 just never adopted it.
       Rendered as a BESPOKE control in the Identity block instead (mirrors
       'tuneName's #1741 P5c removal for the identical reason: a generic
       metadata_field_update row only ever saves ONE column, and the picker
       module already owns the compose/decompose logic — reusing it beats
       re-typing that logic a third time, rule "extract first, use second"). */
    ['verified',           'Verified',          'Verified',           'check',  'identity'],
    ['ccli',               'CCLI Number',       'Ccli',               'text',   'composition'],
    ['iswc',               'ISWC',              'Iswc',               'text',   'composition'],
    /* #1862 — 'isrc' DELETED from this list (issue §2: it's a RECORDING-grain
       code, not a work-grain composition id). Rendered as a bespoke row at
       the end of the Composition IDs block instead, `id="meta-isrc"`
       preserved — both save('isrc', …)'s echo and onIsrcDenorm() below look
       it up by that id — with the identical debounced save('isrc', value)
       wiring. Server untouched. */
    /* #1741 P5c — 'tuneName' DELETED from this list. A plain FIELDS row
       saved TuneName alone through the generic metadata_field_update path,
       stranding TuneId on every edit (the drift this phase retires). The
       Tune control is now a BESPOKE live-search widget rendered in the
       Identity block (see render()), backed by the shared
       ed2_songTuneApply() write core via api.setSongTune() — never a plain
       text FIELDS row again. */
    ['firstPublishedYear', 'First published (year)', 'FirstPublishedYear', 'number', 'publication'],
    ['copyrightYears',     'Copyright year(s)', 'CopyrightYears',     'text',   'publication'],
    /* #1862 — 'copyright' / 'copyrightHolder' / 'hasAudio' / 'hasSheetMusic'
       DELETED from this list — see the file header. copyright ->
       the "Custom statement (override)" disclosure; copyrightHolder -> the
       bespoke tblPublishers picker; hasAudio/hasSheetMusic -> the read-only
       derived line. All rendered as bespoke controls in the Publication
       block by render() below, in the order the #1862 spec's issue §2
       specifies: firstPublishedYear -> copyrightYears -> holder picker ->
       PD checkboxes (+ hints) -> derived statement -> rights line -> media
       line. */
    ['lyricsPublicDomain', 'Lyrics Public Domain', 'LyricsPublicDomain', 'check', 'publication'],
    ['musicPublicDomain',  'Music Public Domain',  'MusicPublicDomain',  'check', 'publication'],
];

/* #1741 P1 — these five tblSongs columns may not exist yet on an install that
 * hasn't run the "song-identity-fields" migration card (`ISRC` is #1064 —
 * pre-P1 — and is deliberately NOT in this set). load_song's `song` slice is
 * a raw `SELECT *` (api2.php ed2_buildSongSnapshot(), #1741 P5a §0.5), so an
 * absent column simply never appears as a key in `song` on an un-migrated
 * install — checking `column in song` is a zero-extra-request client gate
 * that gives a curator no dead control to click (the server's 409 remains
 * for a stale Service-Worker-cached client that tries anyway).
 * #1862 — 'CopyrightHolder' REMOVED from this set: it is no longer a FIELDS
 * row at all (it's the bespoke picker below), gated directly on
 * `('CopyrightHolder' in song)` at its own render call — the same
 * zero-extra-request idiom, just no longer routed through this shared Set.
 */
const GATED_COLUMNS = new Set(['Subtitle', 'Disambiguation', 'FirstPublishedYear', 'CopyrightYears']);

/**
 * The ONE copyright-statement precedence fold, byte-identical to
 * `ihymns_copyright_statement()` (includes/copyright_display.php) — #1862's
 * PHP<->JS lockstep pair. Exported so both the live "Displayed as: …" preview
 * below AND `tests/test-copyright-preview-lockstep.js` can import this exact
 * function rather than either side re-typing the precedence rule (rule #35).
 * Fixtures for both sides: tests/fixtures/copyright-statement-cases.json.
 *
 * @param {string} years  Typed/loaded CopyrightYears.
 * @param {string} holder Typed/loaded CopyrightHolder (display name).
 * @param {string} legacy Typed/loaded legacy Copyright override text.
 * @returns {string}
 */
export function ihymnsCopyrightPreview(years, holder, legacy) {
    const split = (String(years == null ? '' : years).trim() + ' ' + String(holder == null ? '' : holder).trim()).trim();
    return split !== '' ? split : String(legacy == null ? '' : legacy).trim();
}

/**
 * The public-domain suggestion read fold, mirroring `pdSuggestFold()`
 * (includes/pd_suggest.php) — same precedence, same "publication fallback
 * ONLY when pdFromYear is unknown" rule. Kept as a small pure function (not
 * exported — no PHP<->JS lockstep test covers this one, unlike the copyright
 * fold; it has no server-side rendering counterpart to drift from) so the
 * hint-rendering code below stays readable.
 *
 * @param {?number} pdFromYear
 * @param {?number} firstPublishedYear
 * @param {number}  threshold
 * @param {number}  currentYear
 * @returns {{suggested: boolean, basis: ?string, fromYear: ?number}}
 */
function pdSuggestHintFold(pdFromYear, firstPublishedYear, threshold, currentYear) {
    if (pdFromYear != null && pdFromYear <= currentYear) {
        return { suggested: true, basis: 'death', fromYear: pdFromYear };
    }
    if (pdFromYear == null && firstPublishedYear != null && firstPublishedYear < threshold) {
        return { suggested: true, basis: 'publication', fromYear: firstPublishedYear };
    }
    return { suggested: false, basis: null, fromYear: null };
}

export function mountMetadataTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    /* #1679 — the shell's "this song now has a different id" handler. A plain
       injected callback rather than a DOM event, so there is no event-name
       literal to keep in sync with anything (rule #35 / #1581). Defaults to a
       no-op so the tab still mounts standalone in a test harness. */
    const onSongIdChange = opts.onSongIdChange || function () {};
    /* #1679 H1 — the songbook options, injected the same way. The shell passes
       sidebar.getSongbooks(), the SAME list the New-song modal offers, so there
       is one implementation of "which songbooks exist".
       #1679 A2 — that list is now the REAL catalogue (api2 load_index's
       `songbooks`), not the distinct books present in the loaded song index. The
       difference is not cosmetic: an index-derived list cannot contain a book
       with zero songs, so the first song could never be moved INTO a
       newly-created book — a move v1 has always allowed. Defaults to an empty
       list so the tab still mounts standalone. */
    const getSongbooks = opts.getSongbooks || function () { return []; };
    /* #1679 A2 — "tell me when that list is actually populated".
       ELI5: the song opens straight away, but the list of songbooks arrives a
       moment later, so we re-fill the dropdown when it does.
       Detail: getSongbooks() is read ONCE per render, and render() only runs when
       the `song` store slice changes — which in production happens exactly once,
       immediately before mountTabs(). The sidebar's index is fetched in parallel,
       so on a DEEP LINK (?song=…) the tabs routinely mount BEFORE it lands and
       the select froze holding a single option: the song's own book. The
       doc-block that claimed the arrow "resolves at render time" was true and
       irrelevant — nothing re-rendered. The shell passes sidebar.whenLoaded();
       the default resolves immediately so the tab still mounts standalone. */
    const whenSongbooksReady = opts.whenSongbooksReady || function () { return Promise.resolve(); };
    const timers = new Map();
    /* #1846 — field -> value not yet fired by its debounce timer, the SAME key
       space as `timers` above. Lets the shell's manual Save button fire a
       pending debounced write early via flushPending() below, instead of
       making the curator wait out SAVE_DEBOUNCE_MS. Only debounced (text/
       number) fields ever land here — the checkbox/select/immediate saves a
       few lines down call save() directly and never touch this map. */
    const pending = new Map();
    /* #1846 — hand the shell a "flush me now" function once, at mount time
       (mirrors how onSongIdChange/getSongbooks/whenSongbooksReady above are
       ALL plain injected callbacks, never DOM events — rule #35/#1581).
       Defaults to a no-op so the tab still mounts standalone in a test
       harness that doesn't pass registerFlush. */
    const registerFlush = opts.registerFlush || function () {};
    let disposed = false;     // set by teardown, so a late list can't touch a dead tab
    let placeDetach = null;   // teardown for the geocoder attached to the origin picker
    /* #1741 P5c — teardown for the tune typeahead (the SAME shared
       place-search.js module the origin picker above uses, generalised
       rather than forked — pickMode:'value', noun:{tune,tunes}). Same
       render()-wipes-the-container reason as placeDetach immediately
       above: a fresh attach() happens on every `song`-slice change. */
    let tuneDetach = null;
    /* #1862 — teardown for the Copyright Holder typeahead, the SAME shared
       module again (pickMode:'value', noun:{publisher,publishers}) —
       mirrors tuneDetach immediately above in every respect. */
    let holderDetach = null;
    /* #1860 Phase 5 Commit 9 — teardown for the manual "Part of work"
       typeahead (the SAME shared module again, pickMode:'value',
       noun:{work,works} — mirrors tuneDetach/holderDetach immediately
       above in every respect). */
    let workDetach = null;
    /* #1849 — teardown for the language picker (js/modules/ietf-language-
       picker.js). Same render()-wipes-the-container reason as placeDetach/
       tuneDetach immediately above — BUT the module itself returns no
       detach/unbind function (unlike place-search.js's `attach()`): every
       listener it wires is registered directly on elements INSIDE the
       picker's own wrapper node, and that whole node is discarded by
       `container.innerHTML = ''` below, so there is nothing left to leak
       once it's gone. This var exists anyway, set to a no-op, so the
       picker's cleanup stays in the SAME shape as its neighbours rather
       than being the one bespoke control on this tab with no listed
       teardown at all — and so a future version of the module that DOES
       start returning a real detach fn has an obvious place to wire it in. */
    let langPickerDetach = null;
    /* #1671 F3 — teardown for the "Musical key" fieldset. render() wipes the
       container on every `song`-slice change, so the panel is torn down and
       re-mounted with it; without this its in-flight fetch would resolve into a
       detached node and its listener would leak. */
    let keyPanelDetach = null;
    /* #1741 P5b — teardown for the "Recording / external IDs" fieldset. Same
       reason as keyPanelDetach immediately above: render() wipes the whole
       container on every `song`-slice change, so this panel is torn down and
       re-mounted with it. */
    let extIdsDetach = null;
    /* #1669 — teardown for the "Alternative titles" fieldset. Same reason as
       extIdsDetach immediately above: render() wipes the whole container on
       every `song`-slice change, so this panel is torn down and re-mounted
       with it. */
    let altTitlesDetach = null;
    /* #1862 — teardown for the store subscription that keeps the derived
       media-availability line in sync with the Media tab (subscribes to the
       `media` store slice). render() wipes the container on every
       `song`-slice change, so this subscription is torn down and
       re-established with it — same reason as every teardown above. */
    let mediaLineOff = null;
    /* #1862 — reassigned inside render() (to refreshDerivedLines, a function
       declared in that scope) so save()'s echo-handling below — which lives
       in the OUTER mountMetadataTab scope, not inside render() — can still
       reach the current render pass's line-refresh function. Starts as a
       no-op so an echo arriving before the first render ever completes
       (impossible in practice, but cheap to guard) is a silent no-op rather
       than a throw. */
    let refreshMediaLine = function () {};

    /**
     * @param {string}   field
     * @param {*}        value
     * @param {Function} [onError]  called after the failure toast, so a control
     *                              that cannot be left showing a value the server
     *                              rejected (the songbook select) can revert.
     */
    function save(field, value, onError) {
        /* #1846 — returned so flushPending() (below) can await this specific
           save's completion. Nothing else in this file used the return value
           before (every existing caller fires save() and moves on), so
           returning it here changes nothing for them.
           #1851 — resolves TRUE on success, FALSE on failure (never
           rejects); flushPending() sums the FALSEs into a failure count for
           the shell's Save-button outcome report. */
        return api.updateMetadata(songId, field, value).then((res) => {
            /* #1679 — changing the songbook RE-KEYS the SongId server-side
               (`tblSongbooks.Abbreviation` IS the id prefix, rule #27). Every id
               this tab captured at mount — and the shell's ?song= URL, the
               sidebar row, the other tabs — now points at a dead id, so hand the
               new one back to the shell, which re-opens the song under it.
               Compared by VALUE, not by field name: the server tells us whether
               a rename actually happened, so a no-op move (same book) doesn't
               churn the UI. */
            if (res && res.previousId && res.songId && res.songId !== res.previousId) {
                /* Guarded so a fault in the shell's re-open cannot fall through to
                   the .catch() below and toast "could not save" about a save that
                   actually succeeded — a misleading error is worse than none. */
                try { onSongIdChange(res.previousId, res.songId); } catch (_e) { /* shell owns its own errors */ }
            }
            /* #1749 full unification — the server's `value` is the STORE's
               projected value for an isrc save, which can legitimately differ
               from what was typed: clearing the field while a manual
               second-recording row still exists in tblSongExternalIds
               PROMOTES that row's value back into the column (§2.1's
               deliberate, documented consequence). Reflect that in the input
               immediately rather than waiting for the next full reload —
               but ONLY when the field isn't mid-keystroke (focus-guarded),
               so this can never fight a curator who is still typing. */
            if (field === 'isrc' && res && typeof res.value !== 'undefined') {
                const isrcInput = document.getElementById('meta-isrc');
                if (isrcInput && document.activeElement !== isrcInput) {
                    isrcInput.value = res.value == null ? '' : String(res.value);
                }
            }
            /* #1862 — HasAudio/HasSheetMusic's alias branch echoes the DERIVED
               truth (server ignores whatever a stale client sent). Neither
               field has a live control on this tab any more, but a stale
               Service-Worker-cached tab could still be the caller — reflect
               the derived value into `song` + the read-only line so a
               leftover caller can't show a lie even transiently. */
            if ((field === 'hasAudio' || field === 'hasSheetMusic') && res && typeof res.value !== 'undefined') {
                /* #1874 — read the LIVE `song` slice from the store, not a
                   closure variable: `song` is declared only inside render()
                   (below), so referencing it from this outer-scope save() threw
                   a ReferenceError → landed in the .catch() → toasted "Could not
                   save … song is not defined" about a save that actually
                   succeeded server-side (the misleading-error class the comment
                   at 296-299 calls "worse than none"). Reading from the store
                   also survives a re-render between request and echo, which a
                   captured closure variable would not have. */
                const s = store.get('song');
                if (s) { s[field === 'hasAudio' ? 'HasAudio' : 'HasSheetMusic'] = res.value; }
                refreshMediaLine();   // always a function — see its declaration above
            }
            return true;
        }).catch((e) => {
            toast('Could not save ' + field + ': ' + e.message, 'danger');
            if (typeof onError === 'function') { try { onError(e); } catch (_e) {} }
            return false;
        });
    }
    function debouncedSave(field, value) {
        if (timers.has(field)) { clearTimeout(timers.get(field)); }
        pending.set(field, value);   // #1846 — recorded so flushPending() can fire it early
        timers.set(field, setTimeout(() => { pending.delete(field); save(field, value); }, SAVE_DEBOUNCE_MS));
    }

    /**
     * #1846 — the shell's manual Save button's hook into this tab: cancel
     * every pending debounce timer and fire each recorded (field, value) save
     * immediately instead of waiting out SAVE_DEBOUNCE_MS.
     *
     * ELI5: if you just typed something and paused for less than half a
     * second, clicking Save shouldn't make you wait for the timer to catch up
     * — this sends it right now.
     *
     * @returns {Promise<number>} Resolves once every flushed save has
     *   settled to the COUNT of saves that FAILED (0 = all ok) — the shell's
     *   Save button (#1846/#1851) sums this across every tab to decide
     *   between "All changes saved." and a real failure report. save()
     *   already turns a rejection into a toast and resolves a boolean
     *   rather than rethrowing, so the `.catch(() => false)` here is
     *   belt-and-braces, not the normal path. Never rejects, so one field's
     *   failure can't stop the Save button from re-enabling.
     */
    function flushPending() {
        timers.forEach((t) => clearTimeout(t));
        timers.clear();
        const proms = [];
        pending.forEach((value, field) => {
            proms.push(Promise.resolve(save(field, value)).catch(() => false));
        });
        pending.clear();
        return Promise.all(proms).then((results) => results.reduce((n, ok) => n + (ok === false ? 1 : 0), 0));
    }
    registerFlush(flushPending);

    /**
     * The songbook control (#1679 H1) — a closed list, saved immediately on
     * `change`, behind a confirm that spells out what a move actually does.
     *
     * ELI5: pick the book the song belongs in. It is a proper move, not a label
     * change, so we ask first — the song gets a new id and loses its number.
     *
     * Detail, in the order it matters:
     *  - OPTIONS come from the loaded index (see getSongbooks above). The song's
     *    CURRENT book is prepended when the list does not contain it, so the
     *    control always shows the truth even if the index is still loading or
     *    the book has only this one song.
     *  - `change`, never `input` + debouncedSave. A debounce timer turns an
     *    irreversible re-key into something that happens while you are still
     *    deciding; on a <select> there is no partial state to debounce anyway.
     *  - CONFIRM before the request, not a toast after it. The three
     *    consequences are stated plainly because none of them is guessable from
     *    the words "songbook": a new SongId, a cleared Number, and the old id
     *    demoted to a redirect. Moving back does not undo any of them.
     *    window.confirm matches what the shell already uses for Delete — the one
     *    other irreversible action in this editor — rather than introducing a
     *    second confirmation idiom.
     *  - On CANCEL or on a server error the select snaps back to the book the
     *    song is actually in, so the control never sits there claiming a move
     *    that did not happen.
     */
    function renderSongbookSelect(song, field, label, column) {
        /* #1783 — a not-yet-assigned duplicate lives in the hidden staging book.
           Present it as an ASSIGNMENT: the field starts EMPTY (the owner's "empty
           Songbook"), the staging book itself is excluded from the options, and
           picking a book IS the assignment (the existing songbook re-key runs it,
           so no separate Assign panel and no second save model). The Number field
           is already empty because the duplicate's Number is NULL. */
        const isPending = !!store.get('pendingDuplicate');

        const wrap = document.createElement('div');
        const lab = document.createElement('label');
        lab.className = 'form-label small mb-1';
        lab.htmlFor = 'meta-' + field;
        lab.textContent = isPending ? (label + ' — assign to save') : label;

        const current = song[column] != null ? String(song[column]) : '';

        const sel = document.createElement('select');
        sel.className = 'form-select form-select-sm';
        sel.id = 'meta-' + field;

        /* Options are (re)built from whatever getSongbooks() answers NOW, with
           the song's own book guaranteed present — a book the index has not
           listed (empty, or still loading) must never make the control display
           the wrong songbook. Idempotent, so it can run again when the real
           catalogue arrives. */
        function fillOptions() {
            let books = (getSongbooks() || []).slice();
            if (isPending) {
                /* Never offer the staging book (= current) as a target. */
                books = books.filter((b) => b.abbr !== current);
            } else if (current !== '' && !books.some((b) => b.abbr === current)) {
                books.unshift({ abbr: current, name: current });
            }
            sel.innerHTML = '';
            if (isPending) {
                const ph = document.createElement('option');
                ph.value = '';
                ph.textContent = '— choose a songbook —';
                sel.appendChild(ph);
            }
            books.forEach((b) => {
                const o = document.createElement('option');
                o.value = b.abbr;
                o.textContent = (b.name && b.name !== b.abbr) ? (b.name + ' (' + b.abbr + ')') : b.abbr;
                sel.appendChild(o);
            });
            /* Re-assert the selection: replacing the options resets it. A pending
               duplicate stays EMPTY (unassigned); a normal song keeps its book. */
            sel.value = isPending ? '' : current;
        }
        fillOptions();

        /* Re-fill once the catalogue lands. Without this a deep-linked song is
           stuck with the one option it mounted with, i.e. the move is impossible
           on exactly the entry point a curator follows from /manage/revisions or
           Missing Numbers. `.catch` swallows deliberately: a failed index already
           reports itself in the sidebar, and a rejected promise here must not
           surface as an unhandled rejection. */
        try {
            Promise.resolve(whenSongbooksReady())
                .then(() => { if (!disposed) { fillOptions(); } })
                .catch(() => {});
        } catch (_e) { /* a caller that returns a non-thenable: keep the mounted list */ }

        const help = document.createElement('div');
        help.className = 'form-text small';
        help.textContent = isPending
            ? 'Pick a songbook to assign this duplicate — that saves it as a new song. Then set its number below. You can edit anything else first.'
            : 'Moving a song re-keys its id and clears its number.';

        sel.addEventListener('change', () => {
            const target = sel.value;
            if (!target || target === current) { return; }
            /* A pending duplicate is a minutes-old copy with no public id, no
               number and no history to lose, so the scary generic move-confirm
               (redirects, cleared number) is wrong for it — picking a book simply
               assigns it. A normal song keeps the move confirm. */
            if (!isPending) {
                const confirmed = window.confirm(
                    'Move this song to songbook "' + target + '"?\n\n'
                    + 'This is a move, not a label change:\n'
                    + '  • the song gets a NEW id (' + (current || '?') + '-… becomes ' + target + '-…)\n'
                    + '  • its song number is cleared\n'
                    + '  • the old id becomes a permanent redirect to the new one\n\n'
                    + 'Moving it back later will NOT restore the old id or number.'
                );
                if (!confirmed) { sel.value = current; return; }
            }
            /* Immediate — no debouncedSave. The store is NOT updated optimistically:
               a successful move/assign makes the shell re-open the song under its
               new id, which re-hydrates the whole slice from the server (and the
               re-opened song is no longer a pending duplicate, so the field renders
               normally). */
            save(field, target, () => { sel.value = isPending ? '' : current; });
        });

        wrap.append(lab, sel, help);
        return wrap;
    }

    /** A labelled fieldset matching the song-key/external-ids panels' visual
     *  shape (#1862). Returns {fieldset, row} — `row` is the Bootstrap grid
     *  row FIELDS' controls (and this tab's own bespoke controls) append into. */
    function buildBlock(legendText) {
        const fieldset = document.createElement('fieldset');
        fieldset.className = 'border rounded p-3 mb-3';
        const legend = document.createElement('legend');
        legend.className = 'float-none w-auto px-2 fs-6 fw-semibold mb-2';
        legend.textContent = legendText;
        const row = document.createElement('div');
        row.className = 'row g-3';
        fieldset.append(legend, row);
        return { fieldset, row };
    }

    function render() {
        const song = store.get('song') || {};
        if (placeDetach) { try { placeDetach(); } catch (_e) {} placeDetach = null; }
        if (tuneDetach) { try { tuneDetach(); } catch (_e) {} tuneDetach = null; }
        if (holderDetach) { try { holderDetach(); } catch (_e) {} holderDetach = null; }
        if (workDetach) { try { workDetach(); } catch (_e) {} workDetach = null; }
        if (langPickerDetach) { try { langPickerDetach(); } catch (_e) {} langPickerDetach = null; }
        if (keyPanelDetach) { try { keyPanelDetach(); } catch (_e) {} keyPanelDetach = null; }
        if (extIdsDetach) { try { extIdsDetach(); } catch (_e) {} extIdsDetach = null; }
        if (altTitlesDetach) { try { altTitlesDetach(); } catch (_e) {} altTitlesDetach = null; }
        if (mediaLineOff) { try { mediaLineOff(); } catch (_e) {} mediaLineOff = null; }
        container.innerHTML = '';

        /* #1862 — the three BLOCKS (issue §2): Identity, Composition IDs,
           Publication & Copyright, in that order. FIELDS' loop below routes
           each control's `col` div into the matching block's `row`; bespoke
           controls (Language, Composition origin, Tune, ISRC, Copyright
           Holder, PD hints, the derived statement, the two derived lines)
           are appended into whichever block's `row` they conceptually
           belong to, further down. */
        const identity    = buildBlock('Identity');
        const composition = buildBlock('Composition IDs');
        const publication = buildBlock('Publication & Copyright');
        const BLOCKS = { identity: identity.row, composition: composition.row, publication: publication.row };

        /* Captured during the FIELDS loop so the bespoke controls built AFTER
           it (PD hints, the lockout, the derived preview) can reach them
           without a DOM query. */
        let copyrightYearsInput = null;
        let lyricsPDInput = null;
        let musicPDInput = null;
        let lyricsPDHintEl = null;
        let musicPDHintEl = null;
        /* Set below, in the Copyright Holder block, ONLY when the column
           exists on this install ('CopyrightHolder' in song) — stays null
           otherwise, and every reader below already null-guards it. */
        let copyrightHolderInput = null;

        FIELDS.forEach(([field, label, column, kind, block]) => {
            /* #1741 P1 gate — skip a field the server would 409 on rather
               than render a control that can never save. */
            if (GATED_COLUMNS.has(column) && !(column in song)) { return; }
            const col = document.createElement('div');
            col.className = kind === 'check' ? 'col-12 col-sm-6 col-md-4' : 'col-12 col-md-6';

            if (kind === 'check') {
                const wrap = document.createElement('div');
                wrap.className = 'form-check';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.id = 'meta-' + field;
                input.checked = !!Number(song[column]);
                input.addEventListener('change', () => {
                    song[column] = input.checked ? 1 : 0;
                    save(field, input.checked ? 1 : 0);   // immediate
                    /* #1862 — both-PD lockout + hint refresh (defined further
                       down; hoisted function declarations make this safe to
                       call from here). Harmless no-op for 'verified'. */
                    if (column === 'LyricsPublicDomain' || column === 'MusicPublicDomain') {
                        syncPdLockout();
                        refreshPdHints();
                    }
                });
                const lab = document.createElement('label');
                lab.className = 'form-check-label';
                lab.htmlFor = input.id;
                lab.textContent = label;
                wrap.append(input, lab);
                col.appendChild(wrap);
                /* #1862 — an empty hint slot right after the PD checkboxes'
                   wrap; renderPdHint() (below) fills or clears it. */
                if (column === 'LyricsPublicDomain') {
                    lyricsPDInput = input;
                    lyricsPDHintEl = document.createElement('div');
                    col.appendChild(lyricsPDHintEl);
                } else if (column === 'MusicPublicDomain') {
                    musicPDInput = input;
                    musicPDHintEl = document.createElement('div');
                    col.appendChild(musicPDHintEl);
                }
            } else if (kind === 'select') {
                col.appendChild(renderSongbookSelect(song, field, label, column));
            } else {
                const lab = document.createElement('label');
                lab.className = 'form-label small mb-1';
                lab.textContent = label;
                lab.htmlFor = 'meta-' + field;
                const input = document.createElement('input');
                input.type = kind === 'number' ? 'number' : 'text';
                input.className = 'form-control form-control-sm';
                input.id = 'meta-' + field;
                input.value = song[column] != null ? String(song[column]) : '';
                input.addEventListener('input', () => {
                    const val = kind === 'number' ? (parseInt(input.value, 10) || '') : input.value;
                    song[column] = val;
                    debouncedSave(field, val);
                    /* #1862 — copyrightYears feeds the derived preview + is
                       disabled by the both-PD lockout; firstPublishedYear
                       feeds the publication-basis PD hint. */
                    if (field === 'copyrightYears') { updateDerivedPreview(); }
                    if (field === 'firstPublishedYear') { refreshPdHints(); }
                });
                if (field === 'copyrightYears') { copyrightYearsInput = input; }
                if (field === 'ccli' || field === 'iswc') {
                    /* #1860 Phase 5 Commit 9 item (a) — the auto-link SIDE-EFFECT
                       hook (#1679 discipline): fires on `change` (blur/Enter)
                       ONLY, never coupled to the debounced `input` save above,
                       which still owns saving the field itself unchanged. A
                       SEPARATE call (not a read of that save's own
                       `res.workAutolink`, which `metadata_field_update` already
                       returns as a go-live safety net, api2.php:2512-2524) —
                       the badge update is deliberately driven by ONE commit-time
                       request per the locked spec, not by every keystroke-pause
                       autosave. triggerWorkAutolink() is declared further down
                       in this render() pass; safe to reference here — a
                       `function` declaration is hoisted to the top of its
                       enclosing scope, the same reason updateDerivedPreview()/
                       refreshPdHints() above are already callable before their
                       own declarations appear later in this file. */
                    input.addEventListener('change', () => { triggerWorkAutolink(); });
                }
                col.append(lab, input);
            }
            (BLOCKS[block] || identity.row).appendChild(col);
        });

        /* Language (#1849) — the shared IETF BCP 47 live-search picker
           (js/modules/ietf-language-picker.js, #681), rendered as a BESPOKE
           control for the same reason Tune is below: the FIELDS loop above
           only knows how to save one plain scalar per row, and this picker
           already owns the compose/decompose logic v1's editor and
           /manage/songbooks both rely on — reusing it beats re-forking that
           logic a third time (rule "extract first, use second"; the FIELDS
           array comment above has the full story of why 'language' isn't in
           that list any more). Lives in the IDENTITY block (#1862 issue §2). */
        const lcol = document.createElement('div');
        lcol.className = 'col-12 col-md-6';
        const llab = document.createElement('label');
        llab.className = 'form-label small mb-1';
        llab.htmlFor = 'meta-language-lang';
        llab.textContent = 'Language (IETF BCP 47)';
        const lwrap = document.createElement('div');
        lwrap.className = 'ietf-picker';
        lwrap.setAttribute('data-ietf-picker-id', 'ed2');
        /* Seed via the module's OWN hydration path: bootIetfLanguagePicker()
           reads `data-initial-tag` off the root element and calls its
           internal setTag() itself (which decomposes the tag, awaits the
           languages/script/region lookups, then fills the three inputs) —
           the SAME attribute manage/includes/partials/ietf-language-
           picker.php sets server-side for v1. Setting it here, rather than
           calling decomposeTag()/setTag() by hand, avoids a second,
           parallel way to seed the one picker the module already knows how
           to hydrate on every page that mounts it today. */
        lwrap.setAttribute('data-initial-tag', song.Language != null ? String(song.Language) : '');
        /* Static structure mirroring the module's own doc-comment markup
           contract (js/modules/ietf-language-picker.js header): three
           labelled inputs, a live tag preview, a hidden composed-tag output,
           and one <datalist> per input. The only interpolation is the
           literal 'ed2' picker id above (never user data), so this innerHTML
           is not an XSS surface — the same shape v2/enrichment-panel.js's
           buildIetfPicker() already builds for its own inline per-line
           picker, and the same reasoning for why THAT innerHTML is safe. */
        lwrap.innerHTML =
            '<div class="row g-1">'
          +   '<div class="col"><input type="text" class="form-control form-control-sm ietf-picker-language" id="meta-language-lang" list="ietf-lang-list-ed2" autocomplete="off" placeholder="English"></div>'
          +   '<div class="col"><input type="text" class="form-control form-control-sm ietf-picker-script" list="ietf-script-list-ed2" autocomplete="off" placeholder="Script (e.g. Latin)"></div>'
          +   '<div class="col"><input type="text" class="form-control form-control-sm ietf-picker-region" list="ietf-region-list-ed2" autocomplete="off" placeholder="Region"></div>'
          + '</div>'
          + '<div class="form-text small mt-1">IETF tag: <code class="ietf-tag-preview">—</code> <span class="ietf-tag-display fst-italic ms-1"></span></div>'
          + '<input type="hidden" class="ietf-tag-output" value="">'
          + '<datalist id="ietf-lang-list-ed2"></datalist>'
          + '<datalist id="ietf-script-list-ed2"></datalist>'
          + '<datalist id="ietf-region-list-ed2"></datalist>';
        /* Grabbed now (querySelector walks lwrap's own subtree regardless of
           whether lwrap is connected to the document yet) but not USED until
           after the block is attached to `container` below — see the comment
           there for why booting the picker itself has to wait that long. */
        const langTagOutput = lwrap.querySelector('.ietf-tag-output');
        lcol.append(llab, lwrap);
        identity.row.appendChild(lcol);

        /* Composition origin — a geocoded place picker (visible text + hidden id),
           reusing the shared window.iHymnsPlaceSearch (places-api.php). Free-typing
           saves OriginCity; picking a place also saves the OriginCityId FK. Lives in
           the IDENTITY block (#1862 issue §2). */
        const pcol = document.createElement('div');
        pcol.className = 'col-12 col-md-6';
        const plab = document.createElement('label');
        plab.className = 'form-label small mb-1';
        plab.htmlFor = 'meta-originCity';
        plab.textContent = 'Composition origin';
        const pinput = document.createElement('input');
        pinput.type = 'text';
        pinput.className = 'form-control form-control-sm';
        pinput.id = 'meta-originCity';
        pinput.autocomplete = 'off';
        pinput.placeholder = 'City / place…';
        pinput.value = song.OriginCity != null ? String(song.OriginCity) : '';
        const phidden = document.createElement('input');
        phidden.type = 'hidden';
        phidden.value = song.OriginCityId != null ? String(song.OriginCityId) : '';
        pinput.addEventListener('input', () => {
            song.OriginCity = pinput.value;
            debouncedSave('originCity', pinput.value);   // free typing
        });
        phidden.addEventListener('change', () => {
            const n = parseInt(phidden.value, 10);
            const id = (phidden.value !== '' && !isNaN(n)) ? n : null;   // int or null, never ''
            song.OriginCityId = id;
            save('originCityId', id);            // a pick set the FK (or a clear set it null)
            song.OriginCity = pinput.value;
            save('originCity', pinput.value);    // a pick also set the visible display name
        });
        pcol.append(plab, pinput, phidden);
        identity.row.appendChild(pcol);

        /* Tune (#1741 P5c) — a BESPOKE live-search control, not a plain
           FIELDS row (the old `['tuneName', …]` row, DELETED above, saved
           TuneName alone and stranded TuneId on every edit). Rendered in the
           IDENTITY block (#1862 issue §2), mirroring the origin-city
           picker's shape: visible text input + hidden TuneId + (once a metre
           is actually known) a small badge and a "Matching metre only"
           toggle that narrows the typeahead to same-metre tunes — the
           swap-lyrics-between-tunes affordance the parent plan names. Both
           the badge and the toggle start HIDDEN — dormant-by-data, like
           P4c's own meter section — so a tune with no recorded MeterCode
           shows nothing extra. */
        const tcol = document.createElement('div');
        tcol.className = 'col-12 col-md-6';
        const tlab = document.createElement('label');
        tlab.className = 'form-label small mb-1';
        tlab.htmlFor = 'meta-tuneName';
        tlab.textContent = 'Tune Name';
        const tinput = document.createElement('input');
        tinput.type = 'text';
        tinput.className = 'form-control form-control-sm';
        tinput.id = 'meta-tuneName';
        tinput.placeholder = 'Tune name…';
        tinput.value = song.TuneName != null ? String(song.TuneName) : '';
        const thidden = document.createElement('input');
        thidden.type = 'hidden';
        thidden.value = song.TuneId != null ? String(song.TuneId) : '';

        const tmeterRow = document.createElement('div');
        tmeterRow.className = 'form-text small d-flex align-items-center gap-2 mt-1';
        tmeterRow.style.display = 'none';   // shown once a metre is known
        const tbadge = document.createElement('span');
        tbadge.className = 'badge bg-body-secondary';
        const tmeterCheckWrap = document.createElement('div');
        tmeterCheckWrap.className = 'form-check form-check-inline mb-0';
        const tmeterCheck = document.createElement('input');
        tmeterCheck.type = 'checkbox';
        tmeterCheck.className = 'form-check-input';
        tmeterCheck.id = 'meta-tuneMeterOnly';
        const tmeterCheckLabel = document.createElement('label');
        tmeterCheckLabel.className = 'form-check-label';
        tmeterCheckLabel.htmlFor = tmeterCheck.id;
        tmeterCheckLabel.textContent = 'Matching metre only';
        tmeterCheckWrap.append(tmeterCheck, tmeterCheckLabel);
        tmeterRow.append(tbadge, tmeterCheckWrap);

        let currentMeter = null;   // ?string — null until a metre is known
        function showMeter(meterCode) {
            currentMeter = meterCode || null;
            if (currentMeter) {
                tbadge.textContent = 'Metre ' + currentMeter;
                tmeterRow.style.display = '';
            } else {
                tmeterRow.style.display = 'none';
                tmeterCheck.checked = false;
            }
        }

        /**
         * Persist a tune edit/pick via the ONE tune write (`song_tune_set`,
         * the shared `ed2_songTuneApply()` core server-side).
         *
         * #1679 H1's exact reasoning, restated for tunes: this fires on
         * `change` (blur/Enter) and on a typeahead pick — DELIBERATELY NOT
         * debounced-per-keystroke like every sibling text field. Unlike a
         * plain scalar column, a tune name can FIND-OR-CREATE a `tblTunes`
         * registry row; a debounced write firing on every half-second pause
         * while a curator is still typing ("HYF", "HYFRY", "HYFRYDOL", …)
         * would mint a junk row per pause instead of one clean save. A
         * side-effectful write belongs on a COMMIT event, never a typing
         * pause — the songbook <select>'s own `change`-not-`input` wiring
         * a few fields up is the same rule applied to a different
         * irreversible-ish action.
         *
         * @param {string} name Tune name to save (server-trimmed; '' clears).
         * @param {?string} meterFromPick MeterCode a typeahead pick already
         *   carried (avoids waiting on the save's own echo to show the badge).
         */
        function saveTune(name, meterFromPick) {
            api.setSongTune(songId, name).then((res) => {
                song.TuneName = res.tuneName;
                song.TuneId = res.tuneId;
                thidden.value = res.tuneId != null ? String(res.tuneId) : '';
                showMeter(res.meterCode || meterFromPick || null);
            }).catch((e) => {
                toast('Could not save tune: ' + e.message, 'danger');
            });
        }

        tinput.addEventListener('input', () => {
            /* Free-typing invalidates a previously-picked TuneId — the SAME
               "the form falls back to display-string-only" contract
               place-search.js documents for its own hidden input. */
            thidden.value = '';
        });
        tinput.addEventListener('change', () => { saveTune(tinput.value, null); });

        tcol.append(tlab, tinput, thidden, tmeterRow);
        identity.row.appendChild(tcol);

        /* ---- Composition IDs block: ISRC (#1862 issue §2) ----
           A bespoke row, NOT a FIELDS entry (recording-grain, not work-grain
           like CCLI/ISWC above it) — rendered last in the Composition IDs
           block, immediately above where external-ids-panel.js mounts
           itself (further down, into THIS block's fieldset — same visual
           grouping). `id="meta-isrc"` preserved: save()'s echo above and
           onIsrcDenorm() below both look it up by that id. Debounced save,
           unchanged server wiring. */
        const icol = document.createElement('div');
        icol.className = 'col-12 col-md-6';
        const ilab = document.createElement('label');
        ilab.className = 'form-label small mb-1';
        ilab.textContent = 'ISRC';
        ilab.htmlFor = 'meta-isrc';
        const iinput = document.createElement('input');
        iinput.type = 'text';
        iinput.className = 'form-control form-control-sm';
        iinput.id = 'meta-isrc';
        iinput.placeholder = 'e.g. USABC1234567';
        iinput.value = song.Isrc != null ? String(song.Isrc) : '';
        iinput.addEventListener('input', () => {
            song.Isrc = iinput.value;
            debouncedSave('isrc', iinput.value);
        });
        icol.append(ilab, iinput);
        composition.row.appendChild(icol);

        /* ---- "Part of work" (#1860 Phase 5 Commit 9, design §3.7 items 1-3) ----
           Composition IDs block, last row: CCLI/ISWC (FIELDS loop above)
           auto-link into a Work — item (a)'s `change` hook two blocks up
           calls triggerWorkAutolink() below; item (b) is a manual
           find-or-create picker for identifier-less hymns, the SAME
           Copyright Holder attach shape a few blocks up (~:909-923
           pre-Commit-9); item (c) is a read-only "Medley of: A, B, C" line
           when a linked Work is itself a medley. renderWorkInfo() reads
           `song.works` — the LEAN snapshot attach ed2_buildSongSnapshot()
           now carries (api2.php D6, nested onto the song row exactly like
           SongData::getSongById()'s own $row['works'] attach) — so a song
           already linked when the editor opens shows its badge with NO
           extra request; the autolink hook and the manual picker each
           merge their response into `song.works` in place and re-call
           renderWorkInfo() (rule #35 — adopt what the server stored). */
        const workCol = document.createElement('div');
        workCol.className = 'col-12';

        const workInfoWrap = document.createElement('div');
        workInfoWrap.className = 'mb-2';
        workInfoWrap.style.display = 'none';

        /** Redraw the read-only "Part of work" / "Medley of" lines from
         *  `song.works` (mutated in place by applyWorkResult() below). */
        function renderWorkInfo() {
            workInfoWrap.innerHTML = '';
            const works = Array.isArray(song.works) ? song.works : [];
            if (!works.length) { workInfoWrap.style.display = 'none'; return; }
            workInfoWrap.style.display = '';
            works.forEach((w) => {
                const line = document.createElement('div');
                line.className = 'form-text small mb-1';
                line.appendChild(document.createTextNode('Part of work: '));
                const link = document.createElement('a');
                /* target=_blank + noopener/noreferrer — same-site but leaves
                   THIS song mid-edit; opening in a new tab keeps the editor
                   session alive, matching manage/works.php's own link out
                   to a public /work/<slug> page (works.php:1137-1139). */
                link.href = '/work/' + encodeURIComponent(w.slug || '');
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = w.title || ('Work #' + w.id);
                line.appendChild(link);
                const n = Number(w.songCount) || 0;
                line.appendChild(document.createTextNode(' (' + n + (n === 1 ? ' song' : ' songs') + ')'));
                workInfoWrap.appendChild(line);

                /* Item (c) — read-only, names linked to /work/<slug>. */
                if (Array.isArray(w.constituents) && w.constituents.length) {
                    const mLine = document.createElement('div');
                    mLine.className = 'form-text small mb-1';
                    mLine.appendChild(document.createTextNode('Medley of: '));
                    w.constituents.forEach((c, i) => {
                        if (i > 0) { mLine.appendChild(document.createTextNode(', ')); }
                        const cLink = document.createElement('a');
                        cLink.href = '/work/' + encodeURIComponent(c.slug || '');
                        cLink.target = '_blank';
                        cLink.rel = 'noopener noreferrer';
                        cLink.textContent = c.title || ('Work #' + c.id);
                        mLine.appendChild(cLink);
                    });
                    workInfoWrap.appendChild(mLine);
                }
            });

            /* Plain "Manage works" link, NO query params (SD9/rule #33) —
               works.php's own GET handling accepts only action/q/limit; an
               ?id=/?edit= here would be an unhonoured deep link, exactly
               the regression rule #33 exists to prevent. */
            const manageLine = document.createElement('div');
            manageLine.className = 'form-text small';
            const manageLink = document.createElement('a');
            manageLink.href = '/manage/works';
            manageLink.target = '_blank';
            manageLink.rel = 'noopener noreferrer';
            manageLink.textContent = 'Manage works';
            manageLine.appendChild(manageLink);
            workInfoWrap.appendChild(manageLine);
        }
        renderWorkInfo();

        /* Merge a song_work_autolink / song_work_set response into
           `song.works` in place (rule #35 read-back — never assume the
           request's own claimed value survived) and redraw. Only a
           successful LINK carries a workId; an un-linked/not-ready
           response (`res.linked === false`, e.g. no CCLI/ISWC to match on
           yet) leaves `song.works` untouched. */
        function applyWorkResult(res) {
            if (!res || !res.workId) { return; }
            const works = Array.isArray(song.works) ? song.works.slice() : [];
            const idx = works.findIndex((w) => Number(w.id) === Number(res.workId));
            const shaped = {
                id:           res.workId,
                title:        res.workTitle,
                slug:         res.workSlug,
                iswc:         idx >= 0 ? works[idx].iswc : null,
                isCanonical:  idx >= 0 ? works[idx].isCanonical : false,
                songCount:    res.songCount,
                constituents: idx >= 0 ? works[idx].constituents : [],
            };
            if (idx >= 0) { works[idx] = shaped; } else { works.push(shaped); }
            song.works = works;
            renderWorkInfo();
        }

        /* Item (a) — the CCLI/ISWC `change` listener above calls this.
           Server-authoritative (api-client.js's autolinkWork doc-comment):
           this client sends only songId, never a locally-typed identifier
           value. */
        function triggerWorkAutolink() {
            api.autolinkWork(songId).then((res) => {
                if (res.conflict) { toast(res.conflict, 'warning'); }
                if (res.linked) { applyWorkResult(res); }
            }).catch((e) => {
                /* rule #35 — branch on STATUS, never the error sentence.
                   409 = the work-identity migration cards aren't applied
                   yet on this install: hide the affordance silently,
                   exactly like structure-tab.js's own SourceWorkId picker
                   degrades on the same install. Any OTHER failure is a
                   background enrichment hook, not the field save itself
                   (which already succeeded via its own debounced path) —
                   logged for diagnosis, never a distracting toast. */
                if (e && e.status === 409) { return; }
                console.error('[metadata-tab] work autolink failed:', e);
            });
        }

        /* Item (b) — manual "Part of work" picker, the Copyright Holder
           attach shape (~:909-923 above) over searchWorks. Adds a link
           rather than editing one in place (a song may legitimately belong
           to more than one Work), so the box clears after a successful
           commit instead of holding the picked value like Tune/Holder do. */
        const wlab = document.createElement('label');
        wlab.className = 'form-label small mb-1';
        wlab.htmlFor = 'meta-partOfWork';
        wlab.textContent = 'Link to a work…';
        const wInput = document.createElement('input');
        wInput.type = 'text';
        wInput.className = 'form-control form-control-sm';
        wInput.id = 'meta-partOfWork';
        wInput.placeholder = 'Search an existing work, or type a new title…';
        wInput.value = '';
        const wHidden = document.createElement('input');
        wHidden.type = 'hidden';

        /**
         * Persist a manual work link via the ONE write (`setSongWork`,
         * `song_work_set` server-side) — pick -> {workId}; a typed-but-
         * never-picked title -> {title}, the endpoint's OWN find-or-create
         * mode (never a client-side mint, rule #43).
         *
         * @param {?number} workId  A typeahead pick's claimed id, else null.
         * @param {string}  title   The typed text (used only when workId is null).
         */
        function commitWorkPick(workId, title) {
            const opts = workId ? { workId: workId } : { title: title };
            api.setSongWork(songId, opts).then((res) => {
                if (res.conflict) { toast(res.conflict, 'warning'); }
                applyWorkResult(res);
                wInput.value = '';
                wHidden.value = '';
            }).catch((e) => {
                if (e && e.status === 409) { return; }   // un-migrated — hide silently (rule #35)
                toast('Could not link work: ' + e.message, 'danger');
            });
        }

        wInput.addEventListener('input', () => {
            /* Free-typing invalidates a previously-picked workId — the same
               contract the Tune/Copyright Holder inputs use above. */
            wHidden.value = '';
        });
        wInput.addEventListener('change', () => {
            const typed = wInput.value.trim();
            if (typed === '') { return; }   // nothing typed — no CLEAR mode exists here, unlike Tune/Holder
            const pickedId = wHidden.value ? Number(wHidden.value) : null;
            commitWorkPick(pickedId, typed);
        });

        if (window.iHymnsPlaceSearch && typeof window.iHymnsPlaceSearch.attach === 'function') {
            workDetach = window.iHymnsPlaceSearch.attach(wInput, {
                hiddenIdInput: wHidden,
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
                onSelect: (c) => { commitWorkPick(c.id, c.display_name); },
            }) || null;
        }

        workCol.append(workInfoWrap, wlab, wInput, wHidden);
        composition.row.appendChild(workCol);

        /* ==================================================================
         * Publication & Copyright block (#1862 issue §2 order):
         *   firstPublishedYear (FIELDS loop, above) -> copyrightYears
         *   (FIELDS loop, above) -> Copyright Holder picker -> the two PD
         *   checkboxes + hints (FIELDS loop, above; hints filled below) ->
         *   derived-statement preview + override disclosure -> derived
         *   rights-coverage line -> derived media-availability line.
         * ================================================================== */

        /* Copyright Holder (#1862) — a BESPOKE tblPublishers-backed
           live-search control, mirroring the Tune control above in every
           respect (activates the #1864 dormant CopyrightHolderId FK).
           Gated the SAME zero-extra-request way GATED_COLUMNS' fields are —
           just no longer routed through that shared Set, since this isn't a
           FIELDS row (rule #43 — never a free-text box into a registry). */
        if ('CopyrightHolder' in song) {
            const hcol = document.createElement('div');
            hcol.className = 'col-12 col-md-6';
            const hlab = document.createElement('label');
            hlab.className = 'form-label small mb-1';
            hlab.htmlFor = 'meta-copyrightHolder';
            hlab.textContent = 'Copyright holder';
            const hinput = document.createElement('input');
            hinput.type = 'text';
            hinput.className = 'form-control form-control-sm';
            hinput.id = 'meta-copyrightHolder';
            hinput.placeholder = 'Publisher / holder name…';
            hinput.value = song.CopyrightHolder != null ? String(song.CopyrightHolder) : '';
            const hhidden = document.createElement('input');
            hhidden.type = 'hidden';
            hhidden.value = song.CopyrightHolderId != null ? String(song.CopyrightHolderId) : '';

            /**
             * Persist a holder edit/pick via the ONE write
             * (`song_copyright_holder_set`, `ed2_songCopyrightHolderApply()`
             * server-side) — the SAME "commit event, never a typing pause"
             * rule saveTune() above documents at length: a find-or-create
             * write must not fire on a debounced keystroke pause (#1679's
             * anti-pattern, rule #43).
             *
             * @param {string} name Holder name to save (server-trimmed; '' clears).
             * @param {?number} publisherId A typeahead pick's claimed id, else null.
             */
            function saveHolder(name, publisherId) {
                api.setCopyrightHolder(songId, name, publisherId).then((res) => {
                    song.CopyrightHolder = res.holderName;
                    song.CopyrightHolderId = res.publisherId;
                    hhidden.value = res.publisherId != null ? String(res.publisherId) : '';
                    updateDerivedPreview();
                }).catch((e) => {
                    toast('Could not save copyright holder: ' + e.message, 'danger');
                });
            }

            hinput.addEventListener('input', () => {
                /* Free-typing invalidates a previously-picked publisherId —
                   the same contract tinput's own listener documents above. */
                hhidden.value = '';
                updateDerivedPreview();
            });
            hinput.addEventListener('change', () => { saveHolder(hinput.value, null); });

            hcol.append(hlab, hinput, hhidden);
            publication.row.appendChild(hcol);

            if (window.iHymnsPlaceSearch && typeof window.iHymnsPlaceSearch.attach === 'function') {
                holderDetach = window.iHymnsPlaceSearch.attach(hinput, {
                    hiddenIdInput: hhidden,
                    minChars: 2,
                    pickMode: 'value',
                    noun: { singular: 'publisher', plural: 'publishers' },
                    // #1855: extensionless, matches every sibling searchUrl here.
                    searchUrl: (q) => '/manage/editor/api2?action=publisher_search&q=' + encodeURIComponent(q) + '&limit=10',
                    parseResults: (d) => (d.suggestions || []).map((s) => ({
                        id: s.id,
                        display_name: s.name,
                        hint: s.kind || '',
                    })),
                    onSelect: (c) => saveHolder(c.display_name, c.id),
                }) || null;
            }

            copyrightHolderInput = hinput;
        }

        /* ---- Derived copyright statement preview + override disclosure (#1862 issue §3) ---- */
        const previewWrap = document.createElement('div');
        previewWrap.className = 'col-12';
        const previewLine = document.createElement('div');
        previewLine.className = 'form-text small';
        const previewLabel = document.createElement('span');
        previewLabel.textContent = 'Displayed as: ';
        const previewValue = document.createElement('strong');
        previewLine.append(previewLabel, previewValue);
        previewWrap.appendChild(previewLine);

        const details = document.createElement('details');
        details.className = 'mt-1';
        const summary = document.createElement('summary');
        summary.className = 'small text-body-secondary';
        summary.style.cursor = 'pointer';
        summary.textContent = 'Custom statement (override)';
        const overrideWrap = document.createElement('div');
        overrideWrap.className = 'mt-2';
        const overrideHelp = document.createElement('div');
        overrideHelp.className = 'form-text small';
        overrideHelp.id = 'meta-copyright-help';   // #1874 — referenced by aria-describedby below
        overrideHelp.textContent = 'Used only when Copyright year(s) and holder are both empty. Prefer the structured fields.';
        const overrideInput = document.createElement('input');
        overrideInput.type = 'text';
        overrideInput.className = 'form-control form-control-sm';
        overrideInput.id = 'meta-copyright';
        /* #1874 — the collapsed <summary> heading ("Custom statement
           (override)") is not programmatically associated with this input, so a
           screen-reader user tabbing in heard an unnamed edit field on a tab
           where every sibling control is labelled. Give it a real accessible
           name (WCAG 4.1.2 / 3.3.2, both Level A) and announce the guidance via
           aria-describedby (WCAG 3.3.2). The visible summary already carries the
           heading, so an aria-label avoids a redundant second visible label. */
        overrideInput.setAttribute('aria-label', 'Custom copyright statement (override)');
        overrideInput.setAttribute('aria-describedby', 'meta-copyright-help');
        overrideInput.value = song.Copyright != null ? String(song.Copyright) : '';
        overrideInput.addEventListener('input', () => {
            song.Copyright = overrideInput.value;
            debouncedSave('copyright', overrideInput.value);
            updateDerivedPreview();
        });
        overrideWrap.append(overrideInput, overrideHelp);
        details.append(summary, overrideWrap);
        /* Auto-open when the legacy field is live (issue §3's "the cases
           where it's live" — non-empty override, both structured fields
           empty at load time). */
        const yearsAtLoad = song.CopyrightYears != null ? String(song.CopyrightYears).trim() : '';
        const holderAtLoad = song.CopyrightHolder != null ? String(song.CopyrightHolder).trim() : '';
        const legacyAtLoad = song.Copyright != null ? String(song.Copyright).trim() : '';
        if (legacyAtLoad !== '' && yearsAtLoad === '' && holderAtLoad === '') { details.open = true; }
        previewWrap.appendChild(details);
        publication.row.appendChild(previewWrap);

        /** Recompute + render the "Displayed as: …" preview from the live
         *  input values (client-side twin of ihymns_copyright_statement(),
         *  imported by tests/test-copyright-preview-lockstep.js — never
         *  re-typed inline here). Shows "Public domain" while the both-PD
         *  lockout is engaged (issue §5). */
        function updateDerivedPreview() {
            const bothPD = !!(lyricsPDInput && lyricsPDInput.checked) && !!(musicPDInput && musicPDInput.checked);
            if (bothPD) {
                previewValue.textContent = 'Public domain';
                return;
            }
            const years = copyrightYearsInput ? copyrightYearsInput.value : yearsAtLoad;
            const holder = copyrightHolderInput ? copyrightHolderInput.value : holderAtLoad;
            const legacy = overrideInput.value;
            const shown = ihymnsCopyrightPreview(years, holder, legacy);
            previewValue.textContent = shown !== '' ? shown : '(none)';
        }

        /* ---- Both-PD lockout (#1862 issue §5) ----
           When BOTH PD checkboxes are checked: disable copyrightYears, the
           holder picker input, and the override input (values RETAINED —
           disabling, never clearing; re-enabled on uncheck). Called once at
           render (below) and from both checkboxes' own change handlers
           (wired inside the FIELDS loop above via hoisting — this function
           declaration runs before that closure is ever INVOKED, which is
           all JS function-hoisting requires). */
        function syncPdLockout() {
            const bothPD = !!(lyricsPDInput && lyricsPDInput.checked) && !!(musicPDInput && musicPDInput.checked);
            if (copyrightYearsInput) { copyrightYearsInput.disabled = bothPD; }
            if (copyrightHolderInput) { copyrightHolderInput.disabled = bothPD; }
            overrideInput.disabled = bothPD;
            updateDerivedPreview();
        }

        /* ---- PD suggestion hints (#1862 issue §7 + owner comment) ----
           Beside each PD checkbox: a hint + one-click "Use" adopt when the
           fold says suggested — NEVER an auto-tick. Gated on the denorm
           columns existing (`('LyricsPdFromYear' in song)`), the same
           zero-extra-request idiom GATED_COLUMNS uses. */
        function renderOnePdHint(hintEl, checkboxInput, pdFromYearRaw, partLabel, roleLabel) {
            if (!hintEl) { return; }
            hintEl.innerHTML = '';
            if (!checkboxInput || checkboxInput.checked) { return; }   // already ticked — nothing to suggest
            const cfg = window._iHymnsPdSuggest || { lifePlusYears: 70, publicationThreshold: 1900 };
            const currentYear = new Date().getFullYear();
            const pdFromYear = pdFromYearRaw != null && pdFromYearRaw !== '' ? Number(pdFromYearRaw) : null;
            const firstPublishedYear = song.FirstPublishedYear != null && song.FirstPublishedYear !== ''
                ? Number(song.FirstPublishedYear) : null;
            const fold = pdSuggestHintFold(pdFromYear, firstPublishedYear, Number(cfg.publicationThreshold), currentYear);
            if (!fold.suggested) { return; }

            const p = document.createElement('div');
            p.className = 'form-text small text-body-secondary mt-1';
            let text;
            if (fold.basis === 'death') {
                const diedBefore = fold.fromYear - (Number(cfg.lifePlusYears) + 1);
                text = 'Suggested: the ' + partLabel + ' appear to be public domain — every credited '
                    + roleLabel + ' died before ' + diedBefore + '; PD from ' + fold.fromYear
                    + ' under life + ' + cfg.lifePlusYears + '. Verify before ticking — terms vary by jurisdiction.';
            } else {
                text = 'Suggested (assumed from publication age): first published ' + fold.fromYear
                    + ', before ' + cfg.publicationThreshold + '. Please verify.';
            }
            p.appendChild(document.createTextNode(text + ' '));
            const useBtn = document.createElement('button');
            useBtn.type = 'button';
            useBtn.className = 'btn btn-sm btn-outline-secondary py-0 px-1';
            useBtn.textContent = 'Use';
            /* #1874 — both PD-hint adopt buttons read just "Use", so in a
               screen-reader buttons list the lyrics one and the music one are
               indistinguishable out of context (WCAG 2.4.6). partLabel is
               "lyrics"/"music"; keep the visible "Use" text. */
            useBtn.setAttribute('aria-label', 'Mark ' + partLabel + ' as public domain');
            useBtn.addEventListener('click', () => {
                checkboxInput.checked = true;
                checkboxInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
            p.appendChild(useBtn);
            hintEl.appendChild(p);
        }

        function refreshPdHints() {
            if ('LyricsPdFromYear' in song) {
                renderOnePdHint(lyricsPDHintEl, lyricsPDInput, song.LyricsPdFromYear, 'lyrics', 'lyricist');
            }
            if ('MusicPdFromYear' in song) {
                renderOnePdHint(musicPDHintEl, musicPDInput, song.MusicPdFromYear, 'music', 'composer/arranger');
            }
        }

        /* ---- Derived rights-coverage line (#1862 issue §1, owner's refinement) ----
           Replaces the deleted rights-panel.js picker: coverability is
           DERIVED (every iHymns song is IsChristian, owner-stated, so that
           term drops out of the sentence entirely), never picked. Recomputed
           on PD-checkbox change (folded into refreshDerivedLines() below). */
        const rightsLineWrap = document.createElement('div');
        rightsLineWrap.className = 'col-12';
        const rightsLine = document.createElement('div');
        rightsLine.className = 'form-text small';
        rightsLineWrap.appendChild(rightsLine);
        publication.row.appendChild(rightsLineWrap);

        /* ---- Derived media-availability line (#1862 issue §6) ----
           Replaces the deleted hasAudio/hasSheetMusic checkboxes: read-only,
           derived from the server union (includes/song_media_flags.php) —
           "manage files on the Media tab" is the only affordance left. */
        const mediaLineWrap = document.createElement('div');
        mediaLineWrap.className = 'col-12';
        const mediaLine = document.createElement('div');
        mediaLine.className = 'form-text small';
        mediaLineWrap.appendChild(mediaLine);
        publication.row.appendChild(mediaLineWrap);

        function refreshDerivedLines() {
            const bothPD = !!Number(song.LyricsPublicDomain) && !!Number(song.MusicPublicDomain);
            rightsLine.textContent = bothPD
                ? 'Public domain (both parts) — no licence required.'
                : 'Copyrighted — coverable by a CCLI / MRL licence when the viewing organisation holds one.';

            /* #1862 — the SAME union kind-map as includes/song_media_flags.php's
               songMediaFlagKinds() (D8): 'audio'+'midi' -> HasAudio,
               'sheet-music' -> HasSheetMusic. This is a client-side ECHO for
               optimistic Media-tab feedback only — the server denorm
               (song.HasAudio/HasSheetMusic) is the source of truth; this
               never writes anything, it only makes the line update the
               instant a file is attached rather than waiting for a reload. */
            const mediaRows = store.get('media') || [];
            const mediaHasAudio = mediaRows.some((m) => m && (m.kind === 'audio' || m.kind === 'midi'));
            const mediaHasSheet = mediaRows.some((m) => m && m.kind === 'sheet-music');
            const hasAudio = !!Number(song.HasAudio) || mediaHasAudio;
            const hasSheet = !!Number(song.HasSheetMusic) || mediaHasSheet;
            mediaLine.textContent = 'Audio: ' + (hasAudio ? 'yes' : 'no') + ' · Sheet music: ' + (hasSheet ? 'yes' : 'no')
                + ' (derived from attached media and legacy files — manage files on the Media tab)';
        }
        /* Reassign the OUTER-scope `refreshMediaLine` (declared once, in
           mountMetadataTab's own scope, above) so save()'s echo-handling —
           which lives outside render() — can reach THIS render pass's
           line-refresh function. */
        refreshMediaLine = refreshDerivedLines;

        mediaLineOff = store.subscribe('media', refreshDerivedLines);

        /* Initial paint of everything the FIELDS loop + bespoke controls
           above just built — must run AFTER copyrightYearsInput/
           lyricsPDInput/musicPDInput/copyrightHolderInput are all captured. */
        syncPdLockout();
        refreshPdHints();
        refreshDerivedLines();

        container.append(identity.fieldset, composition.fieldset, publication.fieldset);

        /* #1849 — boot the language picker only NOW, after `lwrap` is
           actually attached to the live document via the `container.append(…)`
           immediately above — not right after building its markup earlier.
           bootIetfLanguagePicker() resolves its three <datalist>s via
           `document.getElementById(input.getAttribute('list'))`, which
           searches the WHOLE DOCUMENT: a <datalist> that exists only inside a
           detached `document.createElement()` subtree is invisible to that
           lookup, and the null it gets back is captured ONCE into a closure
           the module never re-queries later. Booting too early would
           silently strand the typeahead — free typing would still work (the
           module's own resolveCode() falls through to whatever was typed
           when no matching option exists), so this would misread as "the
           picker works, it just never suggests anything" rather than fail
           loudly. Mirrors why the geocoder/tune/holder `attach()` calls all
           wait for this exact same live-DOM requirement. */
        bootIetfLanguagePicker(lwrap);
        langPickerDetach = function () { /* see the langPickerDetach declaration above — nothing to detach */ };

        /* The module exposes no "tag changed" hook — its own listeners write
           the composed tag straight into `.ietf-tag-output` via
           `input.addEventListener('input' | 'blur', refreshTag)` on the
           three subtag inputs, never via `.dispatchEvent()`, so a listener
           bound to the HIDDEN output itself would never fire (a script-set
           `.value` raises no DOM event of its own). Delegating `input` at
           the WRAPPER instead works correctly, because 'input' bubbles: the
           subtag input's own listener runs first, at the AT_TARGET phase
           (registered directly on it by the module), and has already
           finished updating `.ietf-tag-output` by the time the event
           reaches this bubble-phase wrapper listener. This is DELIBERATELY
           NOT v1's version of the same wiring (editor.js ~L916-929), which
           listens on the CAPTURE phase and therefore reads the tag ONE edit
           stale — harmless there, because v1 only saves the whole song
           later on an explicit action; v2 saves every field the instant it
           changes, so reading one edit stale here would persist the WRONG
           tag on every keystroke. */
        if (langTagOutput) {
            /* #1851 FIX #4 — seed from the SAME expression that seeds the
               picker itself (`data-initial-tag` above), not from
               `langTagOutput.value`. The picker hydrates ASYNCHRONOUSLY
               (bootIetfLanguagePicker() below awaits the languages/script/
               region lookups before it fills the hidden output), so reading
               `langTagOutput.value` here — synchronously, before that await
               resolves — captured '' even for a song WITH a Language. Once
               hydration later wrote the real tag into the hidden output, the
               very next genuine clear-the-field edit composed '' again,
               which now matched the WRONG baseline ('' still) and produced a
               no-op: the field visibly emptied but the stale Language value
               resurrected on reload. Reading `song.Language` matches the
               picker's own seed and is available synchronously. */
            let lastSavedTag = (song.Language != null ? String(song.Language) : '');
            /* #1851 FIX #5 — one shared handler for both events (factored so
               'input' and 'focusout' can never drift into two different save
               conditions). 'input' catches ordinary typing; 'focusout' is
               ALSO needed because the picker module rewrites
               `.ietf-tag-output` on the subtag inputs' own non-bubbling
               'blur' listener (canonicalising typed text like
               "en-UNITED KINGDOM" -> "en-GB") — a canonicalisation with no
               accompanying 'input' event of its own, so without a bubbling
               listener the canonical tag was silently never saved.
               'focusout' bubbles (unlike 'blur') and, per this file's own
               capture-vs-bubble note a few lines above, fires AFTER the
               module's at-target 'blur' listener has already finished
               rewriting the hidden output — so by the time this delegated
               listener reads `langTagOutput.value` here, it is reading the
               canonicalised value, not one edit stale. */
            const onLanguageChangeEvent = function () {
                const val = langTagOutput.value;
                if (val === lastSavedTag) { return; }
                lastSavedTag = val;
                song.Language = val;
                debouncedSave('language', val);   // matches every other text FIELDS row's save timing
            };
            lwrap.addEventListener('input', onLanguageChangeEvent);
            lwrap.addEventListener('focusout', onLanguageChangeEvent);
        }

        /* Attach the geocoder typeahead (best-effort — no-op if the module didn't load). */
        if (window.iHymnsPlaceSearch && typeof window.iHymnsPlaceSearch.attach === 'function') {
            placeDetach = window.iHymnsPlaceSearch.attach(pinput, { hiddenIdInput: phidden }) || null;

            /* #1741 P5c — the SAME shared module, GENERALISED (never forked)
               via its additive options: pickMode:'value' (no implicit
               upsert — song_tune_set is the one write, fired from
               saveTune() above, not from inside place-search.js itself),
               noun:{tune,tunes} (user-facing strings read "2 tunes found."),
               a custom searchUrl (injects `&meter=` only once BOTH a metre
               is known AND the toggle is checked) and parseResults (maps
               tune_search's `{ suggestions }` shape into the candidate
               shape place-search.js renders, folding meter/alias/usage into
               one `hint` line for the meta row). */
            tuneDetach = window.iHymnsPlaceSearch.attach(tinput, {
                hiddenIdInput: thidden,
                minChars: 2,
                pickMode: 'value',
                noun: { singular: 'tune', plural: 'tunes' },
                // #1855: extensionless — matches api-client.js's ENDPOINT;
                // this GET would survive the .htaccess 301, but goes straight
                // to the clean URL to skip the redirect hop.
                searchUrl: (q) => '/manage/editor/api2?action=tune_search&q=' + encodeURIComponent(q)
                    + '&limit=10' + (tmeterCheck.checked && currentMeter ? '&meter=' + encodeURIComponent(currentMeter) : ''),
                parseResults: (d) => (d.suggestions || []).map((s) => ({
                    id: s.id,
                    display_name: s.name,
                    hint: [
                        s.meterCode ? 'Metre ' + s.meterCode : '',
                        s.matchedAlias ? 'aka ' + s.matchedAlias : '',
                        s.usage ? s.usage + ' song' + (s.usage === 1 ? '' : 's') : '',
                    ].filter(Boolean).join(' · '),
                    meterCode: s.meterCode || '',
                })),
                onSelect: (c) => saveTune(c.display_name, c.meterCode),
            }) || null;
        }

        /* #1741 P5c — meter hydration on mount: a single best-effort read
           (never a write) so an existing tune's badge/toggle appear WITHOUT
           waiting for the curator to touch the field. Silent failure leaves
           the toggle hidden — this is decoration, not a blocking load. */
        if (song.TuneName) {
            const tName = String(song.TuneName);
            api.searchTunes(tName, 1).then((res) => {
                const suggestions = res.suggestions || [];
                const exact = suggestions.find((s) => s.name === tName) || suggestions[0];
                if (exact && exact.meterCode) { showMeter(exact.meterCode); }
            }).catch(() => { /* best-effort — toggle stays hidden */ });
        }

        /* Musical key / tempo / time signature (#298 server, wired #1671 F3).
           A separate table (`tblSongKeys`) behind a separate endpoint pair with
           an all-or-nothing contract, so it is its own fieldset with its own
           Save rather than three more debounced `metadata_field_update` fields.
           Mounted LAST so the tab's own scalar grid keeps its position, and
           dynamically imported so a curator who never opens Metadata does not
           pay for it. Failure is logged, never fatal: the rest of the tab must
           still work if this one chunk cannot load. */
        import('./song-key-panel.js')
            .then((m) => {
                if (disposed || !container.isConnected) { return; }
                keyPanelDetach = m.mountSongKeyPanel(container, { songId: songId, toast: toast });
            })
            .catch((e) => { console.error('[metadata-tab] song-key panel failed to load:', e); });

        /* Recording / external IDs (#1741 P5b, tblSongExternalIds' first UI
           write path) — a card-list of {Spotify, ISRC, MusicBrainz, …} ids for
           this recording. Mounted INTO the Composition IDs fieldset (#1862 —
           "immediately above the external-ids panel mount, same fieldset
           visually": the panel's mountFn accepts any container, so passing
           `composition.fieldset` groups it directly beneath the bespoke ISRC
           row instead of appending to the whole tab). Own fieldset, own
           teardown var, curator who never opens Metadata never pays for the
           extra module. Lives here rather than as a new editor2.php tab
           because these ARE song metadata (identifiers about the song), not
           a Links-tab row — the Links tab's rows carry a `typeId` FK into a
           completely different registry (tblExternalLinkTypes), so reusing
           that editor here would mean faking typeIds for a store that
           doesn't have them. */
        import('./external-ids-panel.js')
            .then((m) => {
                if (disposed || !container.isConnected) { return; }
                extIdsDetach = m.mountExternalIdsPanel(composition.fieldset, { songId: songId, toast: toast, onIsrcDenorm: onIsrcDenorm });
            })
            .catch((e) => { console.error('[metadata-tab] external-ids panel failed to load:', e); });

        /* Alternative titles (#1669, epic #832) — tblSongAlternativeTitles'
           first UI write path. A card-list of "also known as" titles for
           THIS song (per-song free text — rule #43 does not apply, see
           alt-titles-panel.js's doc-block), mounted INTO the Identity
           fieldset so it sits beside the Title field it is a variant of
           (`identity.fieldset` — the same block the FIELDS loop above
           routed the Title/Subtitle/Number/Songbook controls into). Own
           fieldset, own teardown var, dynamically imported so a curator who
           never opens Metadata never pays for the extra module — the SAME
           reasoning as the song-key and external-ids panels immediately
           above. `api` is passed through explicitly (this panel takes an
           injected client rather than importing api-client.js itself — see
           its own doc-block for why). */
        import('./alt-titles-panel.js')
            .then((m) => {
                if (disposed || !container.isConnected) { return; }
                altTitlesDetach = m.mountAltTitlesPanel(identity.fieldset, { api: api, songId: songId, toast: toast });
            })
            .catch((e) => { console.error('[metadata-tab] alt-titles panel failed to load:', e); });

        /* #1862 — the Rights fieldset (#1769 P4's rights-panel.js) is GONE:
           the owner's refinement comment replaced the per-part picker with
           the derived coverage line built above. The server plumbing
           (LyricsRightsLicenceKey/MusicRightsLicenceKey columns, the
           metadata_field_update rights branch, window._iHymnsLicenceTypes)
           all STAYS — dormant facts kept for the future P6 enforcement pass
           and the stale-client wire contract (rule #33) — only this tab's
           now-removed picker is gone. */
    }

    /**
     * #1749 full unification — the panel's add/remove echoes the store's
     * projected primary ISRC via an `isrcDenorm` response key (server-side:
     * api2.php's song_external_id_add/_delete, key-presence per rule #35).
     * This is the callback the panel invokes with that value so the
     * Metadata tab's OWN `#meta-isrc` box reflects a change made from a
     * DIFFERENT control on the same tab, without waiting for a full reload.
     *
     * ELI5: "the little external-IDs list just changed the song's primary
     * ISRC (adding or removing a row can do that) — update the ISRC box up
     * above to match, right now."
     *
     * Focus-guarded exactly like save()'s own echo-handling immediately
     * above: never overwrite a box the curator is actively typing into.
     *
     * @param {?string} v The projected value, or null/undefined for "none".
     */
    function onIsrcDenorm(v) {
        const isrcInput = document.getElementById('meta-isrc');
        if (isrcInput && document.activeElement !== isrcInput) {
            isrcInput.value = v == null ? '' : String(v);
        }
    }

    const off = store.subscribe('song', render);
    render();

    return function teardown() {
        disposed = true;
        off();
        timers.forEach((t) => clearTimeout(t));
        timers.clear();
        pending.clear();   // #1846 — no lingering references once the tab is gone
        if (placeDetach) { try { placeDetach(); } catch (_e) {} placeDetach = null; }
        if (tuneDetach) { try { tuneDetach(); } catch (_e) {} tuneDetach = null; }
        if (holderDetach) { try { holderDetach(); } catch (_e) {} holderDetach = null; }
        if (workDetach) { try { workDetach(); } catch (_e) {} workDetach = null; }
        if (langPickerDetach) { try { langPickerDetach(); } catch (_e) {} langPickerDetach = null; }
        if (keyPanelDetach) { try { keyPanelDetach(); } catch (_e) {} keyPanelDetach = null; }
        if (extIdsDetach) { try { extIdsDetach(); } catch (_e) {} extIdsDetach = null; }
        if (altTitlesDetach) { try { altTitlesDetach(); } catch (_e) {} altTitlesDetach = null; }
        if (mediaLineOff) { try { mediaLineOff(); } catch (_e) {} mediaLineOff = null; }
        container.innerHTML = '';
    };
}
