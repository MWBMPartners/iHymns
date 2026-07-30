/* ==========================================================================
 *  enrichment-panel.js — per-line LANGUAGE / TRANSLATION / ANNOTATION panel
 *  for the v2 Song Editor's Structure tab (#1627 item 3, #1235 P3 / #1088)
 *
 *  ELI5: the little "expand for extra per-line info" drawer under each
 *  section's lyrics box — give one line its own language, add a translated
 *  or romanized version of it, or attach a note explaining what it means.
 *
 *  Detail: ports v1's `buildEnrichmentPanel()` (editor.js ~1434-1658) to a
 *  clean ES module that fits v2's granular, atomic-save architecture (every
 *  edit is its own confirmed server round-trip, never a whole-song save).
 *  Two different kinds of per-line data live here, and they persist through
 *  TWO DIFFERENT paths — this is the load-bearing distinction the whole file
 *  is organised around:
 *
 *    - Per-line LANGUAGE override (`comp.languages[i]`) is INDEX-keyed, part
 *      of the component row itself, and rides along on the component's own
 *      next `component_upsert` (structure-tab.js's `saveComponent()`) — same
 *      mechanism as `lines`/`chords`. That means it works even on a line that
 *      has never been saved (no DB id yet).
 *    - Per-line TRANSLATIONS + ANNOTATIONS are their OWN rows
 *      (`tblLyricLineTranslations` / `tblLyricLineAnnotations`, #1088),
 *      anchored on `tblLyricLines.Id` (rule #21 — never a `LinesJson` array
 *      index: an index shifts the moment a line is inserted or removed
 *      above it, and the note would silently drift onto the wrong line).
 *      That id is `comp.lineIds[i]`, parallel to `comp.lines`
 *      (`includes/lyric_lines_read.php`'s `lyricLinesEditableComponents()`,
 *      #1627's addition — see that function's own comment for why it was
 *      the ONE thing blocking this feature). It is `0` (falsy) for a line
 *      that hasn't been through a save yet, or `[]` site-wide on a
 *      pre-mirror install — either way the add controls are hidden in favour
 *      of a hint, never a button that would 400/409 on click.
 *      Each add/delete is its own atomic write straight to api2.php
 *      (`line_translation_upsert/delete`, `line_annotation_upsert/delete`)
 *      — never folded into the component-content save (rule #25: ONE write
 *      path per concern, and enrichment is a different concern to lyric
 *      content, same as media is a different concern to the song scalars).
 *
 *  CLEAR-SEMANTICS TRAP (per-line language) — api2's `component_upsert` uses
 *  PHP `isset()` to decide whether the caller supplied `languages` at all;
 *  `isset($x)` is FALSE for a JSON `null`, so sending `languages: null`
 *  PRESERVES whatever was stored, it does not clear it. The only way to clear
 *  the LAST remaining override is to send an ARRAY whose relevant cells are
 *  null/empty. `setLineLang()` below is written so `comp.languages`, once it
 *  has become an array, NEVER collapses back to `null` — clearing a cell
 *  sets that cell to `null` INSIDE the array, never nulls the whole field.
 *  structure-tab.js's `saveComponent()` then sends `comp.languages || null`,
 *  which stays the (all-null-capable) array because a non-empty array is
 *  truthy in JS. Getting this backwards makes overrides permanently
 *  un-clearable, silently — no error, just a save that quietly did nothing.
 *
 *  buildEnrichmentPanel(comp, ctx) -> HTMLElement (a collapsible <div>)
 *    comp : one row from the store's `components` slice. `comp.languages` is
 *           read AND mutated in place here, exactly like every other
 *           structure-tab-owned field on the same object.
 *    ctx  : { store, api, songId, toast, saveComponent }
 *           store         — the shared reactive store (store.js). This panel
 *                           reads/writes the `lineTranslations` /
 *                           `lineAnnotations` slices directly; it does not
 *                           subscribe to them (see the render-lifecycle note
 *                           below — the enclosing card is not torn down by a
 *                           per-line edit, so a local re-render is enough).
 *           api           — editorApi (api-client.js)
 *           songId        — the server SongId
 *           toast         — (message, type) => void
 *           saveComponent — structure-tab.js's atomic component_upsert saver;
 *                           called (UNDEBOUNCED — a language pick is a single
 *                           discrete action, not a keystroke stream) right
 *                           after a language-override edit.
 *
 *  RENDER LIFECYCLE: structure-tab.js only rebuilds the component list (and
 *  therefore re-creates this panel) on a STRUCTURAL change (add/remove/
 *  reorder section) — see its `store.subscribe('components', render)`. Lyric
 *  edits, chord edits, and everything this panel does are mutations of the
 *  `comp`/store-slice objects in place with NO `store.set('components', …)`
 *  call, so they never trigger that rebuild. That is what lets this panel
 *  manage its own local re-render (`renderBody()`) without fighting the
 *  card's lifecycle, and it is why the panel's open/closed state and any
 *  in-progress add-form survive a lyric or language edit on the SAME card.
 * ========================================================================== */

import { bootIetfLanguagePicker } from '/js/modules/ietf-language-picker.js';

/* Mirrors includes/line_enrichment.php's LINE_TRANSLATION_KINDS /
   LINE_ANNOTATION_TYPES EXACTLY (rule #20's central-vocabulary discipline
   extends to every client that offers the choice) — a client value the
   server doesn't recognise 400s at Save time with no build-time signal.
   Exported (not just used) so tests/test-v2-enrichment-ui.js can parse BOTH
   this array and the PHP one from source and assert they are set-equal,
   rather than either list being hand-copied into the test and able to drift
   right alongside a real drift. */
export const LINE_TRANSLATION_KINDS = ['translation', 'transliteration'];
export const LINE_ANNOTATION_TYPES = ['explanation', 'reference', 'scripture', 'history', 'translation', 'trivia'];

/* Unique id source for the IETF picker markup below — the picker module
   resolves its <datalist>s via getElementById(input.list), so two pickers
   open at once (two different lines' "Set language" forms, or a language
   form + a translation form) sharing an id would clobber each other's
   suggestion lists. Module-scoped counter, not per-panel, so it stays unique
   across every panel instance on the page. */
let ietfPickerSeq = 0;

/**
 * buildIetfPicker(initialTag) — build the picker markup
 * (js/modules/ietf-language-picker.js's documented DOM contract: three
 * labelled inputs + a hidden composed-tag output + three matching
 * <datalist>s) and boot it via the real ES module import above. Ported from
 * v1's `buildInlineIetfPicker` (editor.js ~1332) minus its classic-script
 * fallback branch — v1 needed that because `window.bootIetfLanguagePicker`
 * might not have loaded yet (deferred classic <script>); v2 `import`s the
 * module directly, so it is simply always available.
 *
 * @param {string} initialTag  saved BCP 47 tag to pre-fill, or ''
 * @returns {{el:HTMLElement, getTag:function():string, ready:Promise<void>}}
 */
function buildIetfPicker(initialTag) {
    const tag = initialTag || '';
    const id = 'v2-ietf-' + (++ietfPickerSeq);
    const wrap = document.createElement('div');
    wrap.className = 'ietf-picker';
    wrap.setAttribute('data-ietf-picker-id', id);
    /* Static structure mirroring the picker module's own doc-comment markup
       contract; the only interpolation is `id` (a generated counter, never
       user data), so this innerHTML is not an XSS surface. */
    wrap.innerHTML =
        '<div class="row g-1">'
      +   '<div class="col"><input type="text" class="form-control form-control-sm ietf-picker-language" list="ietf-lang-list-' + id + '" autocomplete="off" placeholder="Language"></div>'
      +   '<div class="col"><input type="text" class="form-control form-control-sm ietf-picker-script" list="ietf-script-list-' + id + '" autocomplete="off" placeholder="Script (e.g. Latin)"></div>'
      +   '<div class="col"><input type="text" class="form-control form-control-sm ietf-picker-region" list="ietf-region-list-' + id + '" autocomplete="off" placeholder="Region"></div>'
      + '</div>'
      + '<div class="form-text small mt-1">IETF tag: <code class="ietf-tag-preview">—</code> <span class="ietf-tag-display fst-italic ms-1"></span></div>'
      + '<input type="hidden" class="ietf-tag-output" value="">'
      + '<datalist id="ietf-lang-list-' + id + '"></datalist>'
      + '<datalist id="ietf-script-list-' + id + '"></datalist>'
      + '<datalist id="ietf-region-list-' + id + '"></datalist>';

    const ctl = bootIetfLanguagePicker(wrap);
    /* setTag() awaits the languages/script/region lookups before it fills the
       inputs + hidden output — expose that as `ready` so a caller can block
       its Save button rather than reading getTag() too early and saving ''
       over an existing tag (mirrors v1's #1345 review fix). */
    const ready = (ctl && tag && typeof ctl.setTag === 'function')
        ? Promise.resolve(ctl.setTag(tag)).catch(() => { /* degrade silently — a failed prefill still leaves a usable empty picker */ })
        : Promise.resolve();
    return {
        el: wrap,
        ready,
        getTag: () => (ctl && typeof ctl.getTag === 'function') ? (ctl.getTag() || '').trim() : '',
    };
}

/** chip(label, onRemove) — a small removable badge (used for the current
 *  language tag + each translation/annotation). Matches the visual language
 *  v1 used for the identical purpose. */
function chip(label, onRemove) {
    const c = document.createElement('span');
    c.className = 'badge bg-secondary-subtle text-secondary-emphasis me-1 mb-1 fw-normal';
    c.style.whiteSpace = 'normal';
    c.textContent = label + '  ';
    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'btn-close btn-close-sm align-middle';
    x.style.fontSize = '0.6rem';
    x.title = 'Remove';
    x.addEventListener('click', onRemove);
    c.appendChild(x);
    return c;
}

/** smallBtn(label, onClick) — a compact inline text button ("+ Translation",
 *  "🔤 Set language", "Save", "Cancel", …). */
function smallBtn(label, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-sm btn-outline-secondary py-0 px-1 me-1';
    b.textContent = label;
    b.addEventListener('click', onClick);
    return b;
}

/** True when the #1088 enrichment tables are un-migrated on this install — the
 *  signal for a calm "not available here yet" notice instead of a red failure
 *  toast.
 *
 *  Branches on the HTTP status, not the server's wording. This originally
 *  matched /not migrated/i because unwrap() discarded `res.status`; it now
 *  attaches it (see the note in api-client.js), so this reads the endpoint's
 *  documented contract rather than a sentence somebody is free to reword.
 *  Rewording a server string used to silently downgrade this to a generic red
 *  toast, with nothing failing anywhere to say so. */
function isEnrichmentUnmigrated(err) {
    return !!err && err.status === 409;
}

/**
 * buildEnrichmentPanel(comp, ctx) — see the file header for the full
 * contract. Returns a collapsible wrapper `<div>`; the caller (structure-
 * tab.js's `buildCard()`) appends it into the component card body.
 */
export function buildEnrichmentPanel(comp, ctx) {
    const { store, api, songId } = ctx;
    const toast = ctx.toast || function () {};
    const saveComponent = ctx.saveComponent || function () {};

    const wrap = document.createElement('div');
    wrap.className = 'mt-2';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'btn btn-sm btn-link p-0 text-decoration-none';
    toggle.innerHTML = '<i class="bi bi-card-text me-1"></i>Per-line language, translations &amp; annotations';
    const box = document.createElement('div');
    box.className = 'mt-1';
    box.style.display = 'none';
    toggle.addEventListener('click', () => {
        const show = box.style.display === 'none';
        box.style.display = show ? '' : 'none';
        if (show) { renderBody(); }
    });

    /* comp.lineIds is parallel to comp.lines (#1627's addition to
       lyricLinesEditableComponents()); 0/undefined means "no saved line id
       yet" (unsaved line, or a pre-mirror install where the array is [] for
       every component). */
    function lineIdAt(i) {
        return (Array.isArray(comp.lineIds) && comp.lineIds[i] != null) ? Number(comp.lineIds[i]) : 0;
    }
    function translations() { const t = store.get('lineTranslations'); return Array.isArray(t) ? t : []; }
    function annotations()  { const a = store.get('lineAnnotations');  return Array.isArray(a) ? a : []; }
    function forLine(list, key, lineId) {
        return list.filter((e) => Number(e[key]) === lineId);
    }

    /* #1627/#1345 — per-line LANGUAGE override. See the file header's
       CLEAR-SEMANTICS TRAP note: comp.languages, once it becomes an array,
       must NEVER be reassigned back to `null` — only its individual cells
       are ever nulled. That is what keeps a clear-to-empty actually clear
       server-side instead of silently preserving the old value. */
    function setLineLang(i, tag) {
        if (!Array.isArray(comp.languages)) { comp.languages = []; }
        while (comp.languages.length <= i) { comp.languages.push(null); }
        comp.languages[i] = tag || null;
        saveComponent(comp);
    }

    /* Inline structured-picker form for one line's language override. */
    function showLineLangForm(host, i) {
        const cur = (Array.isArray(comp.languages) && comp.languages[i] != null) ? String(comp.languages[i]).trim() : '';
        const form = document.createElement('div');
        form.className = 'mt-1 p-2 border rounded bg-body-tertiary';
        const picker = buildIetfPicker(cur);
        form.appendChild(picker.el);
        const btnRow = document.createElement('div');
        btnRow.className = 'mt-1';
        const save = smallBtn('Save', () => { setLineLang(i, picker.getTag()); renderBody(); });
        save.className = 'btn btn-sm btn-primary py-0 px-2';
        /* Editing an EXISTING tag: block Save until the async prefill
           resolves, so a fast click can't read '' and clear the tag. A fresh
           (empty) seed has nothing to lose, so leave it live (mirrors v1's
           #1345 review fix). */
        if (cur) {
            save.disabled = true;
            picker.ready.then(() => { save.disabled = false; });
        }
        const cancel = smallBtn('Cancel', () => renderBody());
        btnRow.append(save, cancel);
        form.appendChild(btnRow);
        host.appendChild(form);
    }

    function showTranslationForm(host, lineId) {
        const form = document.createElement('div');
        form.className = 'mt-1 p-2 border rounded bg-body-tertiary';
        const kindRow = document.createElement('div');
        kindRow.className = 'd-flex gap-1 align-items-center mb-1';
        const kindLbl = document.createElement('span');
        kindLbl.className = 'small text-muted';
        kindLbl.textContent = 'Kind:';
        const kind = document.createElement('select');
        kind.className = 'form-select form-select-sm w-auto';
        LINE_TRANSLATION_KINDS.forEach((k) => {
            const o = document.createElement('option'); o.value = k; o.textContent = k; kind.appendChild(o);
        });
        kindRow.append(kindLbl, kind);
        const picker = buildIetfPicker('');
        const text = document.createElement('input');
        text.type = 'text';
        text.className = 'form-control form-control-sm mt-1';
        text.placeholder = 'Translated / romanized line text';
        const save = smallBtn('Save', () => {
            const payload = { lineId: lineId, kind: kind.value, targetLanguage: picker.getTag(), text: text.value.trim() };
            if (!payload.targetLanguage || !payload.text) { toast('Language and text are required.', 'warning'); return; }
            api.upsertLineTranslation(songId, payload).then((d) => {
                if (d.translation) { store.set('lineTranslations', translations().concat([d.translation])); }
                renderBody();
            }).catch((err) => {
                if (isEnrichmentUnmigrated(err)) { toast('Per-line translations are not available on this install yet.', 'warning'); }
                else { toast('Save failed: ' + err.message, 'danger'); }
            });
        });
        save.className = 'btn btn-sm btn-primary py-0 px-2';
        const cancel = smallBtn('Cancel', () => renderBody());
        const btnRow = document.createElement('div');
        btnRow.className = 'mt-1';
        btnRow.append(save, cancel);
        form.append(kindRow, picker.el, text, btnRow);
        host.appendChild(form);
    }

    function showAnnotationForm(host, lineId) {
        const form = document.createElement('div');
        form.className = 'd-flex flex-wrap gap-1 align-items-start mt-1';
        const type = document.createElement('select');
        type.className = 'form-select form-select-sm w-auto';
        LINE_ANNOTATION_TYPES.forEach((t) => {
            const o = document.createElement('option'); o.value = t; o.textContent = t; type.appendChild(o);
        });
        const body = document.createElement('textarea');
        body.className = 'form-control form-control-sm';
        body.rows = 2;
        body.placeholder = 'Annotation (markdown)';
        body.style.minWidth = '220px';
        const save = smallBtn('Save', () => {
            const payload = { startLineId: lineId, annotationType: type.value, body: body.value.trim() };
            if (!payload.body) { toast('Annotation body is required.', 'warning'); return; }
            api.upsertLineAnnotation(songId, payload).then((d) => {
                if (d.annotation) { store.set('lineAnnotations', annotations().concat([d.annotation])); }
                renderBody();
            }).catch((err) => {
                if (isEnrichmentUnmigrated(err)) { toast('Per-line annotations are not available on this install yet.', 'warning'); }
                else { toast('Save failed: ' + err.message, 'danger'); }
            });
        });
        save.className = 'btn btn-sm btn-primary py-0 px-2';
        const cancel = smallBtn('Cancel', () => renderBody());
        form.append(type, body, save, cancel);
        host.appendChild(form);
        body.focus();
    }

    function deleteEnrichment(kind, id) {
        const call = kind === 'translation' ? api.deleteLineTranslation(songId, id) : api.deleteLineAnnotation(songId, id);
        call.then(() => {
            if (kind === 'translation') {
                store.set('lineTranslations', translations().filter((e) => Number(e.id) !== Number(id)));
            } else {
                store.set('lineAnnotations', annotations().filter((e) => Number(e.id) !== Number(id)));
            }
            renderBody();
        }).catch((err) => {
            if (isEnrichmentUnmigrated(err)) { toast('This install has no per-line enrichment tables to delete from.', 'warning'); }
            else { toast('Delete failed: ' + err.message, 'danger'); }
        });
    }

    function renderBody() {
        box.innerHTML = '';
        const lines = Array.isArray(comp.lines) ? comp.lines : [];
        if (!lines.length) {
            box.innerHTML = '<div class="text-muted small fst-italic">No lines yet.</div>';
            return;
        }
        lines.forEach((lineText, i) => {
            const lineId = lineIdAt(i);
            const row = document.createElement('div');
            row.className = 'border-start border-2 ps-2 mb-2 small';
            const txt = document.createElement('div');
            txt.className = 'text-muted';
            txt.textContent = (lineText && String(lineText).trim()) ? String(lineText) : '(blank line)';
            row.appendChild(txt);

            /* Per-line LANGUAGE override — index-keyed, works pre-save (unlike
               translations/annotations below). Skipped on a blank separator
               line unless one already carries a tag (matches v1). */
            const curLang = (Array.isArray(comp.languages) && comp.languages[i] != null) ? String(comp.languages[i]).trim() : '';
            const lineHasText = !!(lineText && String(lineText).trim());
            if (lineHasText || curLang) {
                const langWrap = document.createElement('div');
                langWrap.className = 'mb-1';
                if (curLang) {
                    langWrap.appendChild(chip('🔤 ' + curLang, () => { setLineLang(i, null); renderBody(); }));
                }
                langWrap.appendChild(smallBtn(curLang ? '🔤 Change language' : '🔤 Set language', () => showLineLangForm(langWrap, i)));
                row.appendChild(langWrap);
            }

            if (!lineId) {
                /* Task requirement: never offer a button that cannot work — a
                   line with no saved id (unsaved edit, or a pre-mirror
                   install where lineIds is always []) gets a hint instead of
                   the add-translation/add-annotation controls. */
                const note = document.createElement('div');
                note.className = 'fst-italic text-secondary';
                note.textContent = 'Saves in a moment… translations and annotations need this line saved first.';
                row.appendChild(note);
                box.appendChild(row);
                return;
            }

            forLine(translations(), 'lineId', lineId).forEach((t) => {
                row.appendChild(chip('🌐 ' + t.kind + ' · ' + t.targetLanguage + ': ' + t.text, () => deleteEnrichment('translation', t.id)));
            });
            forLine(annotations(), 'startLineId', lineId).forEach((a) => {
                row.appendChild(chip('📝 ' + a.annotationType + ': ' + a.body, () => deleteEnrichment('annotation', a.id)));
            });

            const btns = document.createElement('div');
            btns.className = 'mt-1';
            btns.appendChild(smallBtn('+ Translation', () => showTranslationForm(row, lineId)));
            btns.appendChild(smallBtn('+ Annotation', () => showAnnotationForm(row, lineId)));
            row.appendChild(btns);
            box.appendChild(row);
        });
    }

    wrap.append(toggle, box);
    return wrap;
}
