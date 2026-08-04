/* ==========================================================================
 *  song-key-panel.js — "Musical key" in the v2 editor's Metadata tab
 *                      (#298 server, wired #1671 F3)
 *
 *  ELI5: lets a curator record what key a song is in, how fast it goes and its
 *  time signature — the three things a musician wants before playing it.
 *
 *  WHY THIS EXISTS. #298 shipped `tblSongKeys` plus a GET and a POST for it,
 *  and then shipped nothing that called either. The table has therefore had no
 *  writer for its entire life, which is why the public song page's key badge
 *  has never appeared for anybody. This panel is the writer.
 *
 *  WHY IT IS NOT A `metadata_field_update` FIELD. The Metadata tab saves ONE
 *  scalar per keystroke-pause against `tblSongs` columns, via api2's
 *  `ED2_META_FIELDS` allow-list. These three live on a DIFFERENT table and go
 *  through a DIFFERENT endpoint whose contract is all-or-nothing: `song_key_save`
 *  requires `original_key` on every call, so per-field debounced saving would
 *  fire a stream of 400s while a curator was still choosing. Hence one explicit
 *  Save for the group.
 *
 *  WHY THE KEY IS A CLOSED LIST. Same reasoning as #1679 H1's songbook select:
 *  the server accepts only /[A-G][#b]?m?/ and the set `transpose.js` can
 *  actually transpose is exactly that set. A free-text box would let a curator
 *  type "G major" and get a 400 they have to decode; a select cannot be got
 *  wrong.
 *
 *  mountSongKeyPanel(container, { songId, toast }) -> teardown fn
 * ========================================================================== */

import { songKeyGet, songKeySave } from './api-client.js';

/**
 * The keys the server accepts and `transpose.js` understands.
 *
 * Built rather than hand-listed so the two halves cannot drift: every natural
 * and its sharp/flat spelling, major and minor. `songKeyNormaliseKey()` in
 * includes/song_key.php accepts precisely this shape, and
 * `tests/test-v2-song-key-panel.js` re-derives the list and asserts every entry
 * satisfies the server's regex — so adding a spelling here that the server
 * would refuse fails the build rather than a curator's save.
 */
export function buildKeyOptions() {
    const roots = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];
    const out = [];
    for (const r of roots) {
        out.push(r);
        /* Flats and sharps as separate options. Both spellings are offered on
           purpose: Bb and A# are the same pitch but not the same key signature,
           and a curator transcribing from a hymnal needs the spelling that
           hymnal used. */
        out.push(r + 'b');
        out.push(r + '#');
    }
    /* Minors mirror the majors exactly — a trailing lowercase m, which is the
       only quality suffix the server's normaliser keeps. */
    return out.concat(out.map((k) => k + 'm'));
}

/**
 * Common time signatures.
 *
 * Every entry must satisfy the server's `\d{1,2}/\d{1,2}` with both parts >= 1;
 * the guard asserts that too. '' is "not specified", which the server stores as
 * the empty string because `TimeSignature` is NOT NULL.
 */
export const TIME_SIGNATURES = ['3/4', '4/4', '6/8', '2/2', '2/4', '5/4', '7/8', '9/8', '12/8'];

/**
 * What to tell the curator about a failed save, chosen BY HTTP STATUS.
 *
 * Rule #35: never regex the server's sentence. The 401 arm is the one that
 * earns its keep — a /manage sign-in mints the `ihymns_auth` cookie
 * best-effort (`mintCrossSurfaceAuthToken()` swallows its own failures so a
 * mint hiccup cannot block the dashboard), and an admin whose session predates
 * that mechanism has a valid /manage session and NO app-API cookie. They are
 * signed in, the page works, and this one save 401s. "Access denied" would send
 * them to the wrong place entirely.
 *
 * @param {number|undefined} status
 * @param {string} [serverMessage]
 * @returns {string}
 */
export function saveMessageForStatus(status, serverMessage = '') {
    const msg = typeof serverMessage === 'string' ? serverMessage.trim() : '';
    switch (status) {
        case 400:
            /* The server names the offending field and why — that sentence is
               more useful than anything this file could invent. */
            return msg !== '' ? msg : 'The key, tempo or time signature was not valid.';
        case 401:
            return 'Your admin session is not linked to the app API. Sign out of /manage and sign in again, then retry.';
        case 403:
            return 'Your role does not carry the edit_songs entitlement.';
        case 404:
            return 'This song no longer exists — reload the editor.';
        case 429:
            return 'Too many requests. Please wait a moment and try again.';
        default:
            return msg !== '' ? msg : 'Could not save the musical key.';
    }
}

/**
 * Mount the panel.
 *
 * @param {HTMLElement} container Where to append. The Metadata tab wipes its own
 *   container on every `song`-slice change, so it calls the returned teardown
 *   and re-mounts rather than relying on this to survive.
 * @param {{songId: string, toast?: Function}} opts
 * @returns {Function} teardown
 */
export function mountSongKeyPanel(container, opts) {
    const songId = opts && opts.songId ? String(opts.songId) : '';
    const toast  = (opts && opts.toast) || function () {};
    let disposed = false;

    const wrap = document.createElement('div');
    wrap.className = 'col-12';
    wrap.innerHTML = ''; /* everything below is built as nodes, never as HTML */

    const fieldset = document.createElement('fieldset');
    fieldset.className = 'border rounded p-3';
    /* A real <legend> rather than a styled <div>: it becomes the accessible
       group name, so a screen reader announces "Musical key, Key, combo box"
       instead of three unrelated controls.
       https://developer.mozilla.org/docs/Web/HTML/Element/fieldset */
    const legend = document.createElement('legend');
    legend.className = 'float-none w-auto px-2 h6 mb-0';
    legend.textContent = 'Musical key';
    fieldset.appendChild(legend);

    const row = document.createElement('div');
    row.className = 'row g-2 align-items-end';

    const keySel  = labelledControl(row, 'ed2-song-key', 'Key', 'select');
    const tempoIn = labelledControl(row, 'ed2-song-tempo', 'Tempo (BPM)', 'number');
    const tsSel   = labelledControl(row, 'ed2-song-timesig', 'Time signature', 'select');

    addOption(keySel, '', '— not set —');
    buildKeyOptions().forEach((k) => addOption(keySel, k, k));

    addOption(tsSel, '', '— not set —');
    TIME_SIGNATURES.forEach((t) => addOption(tsSel, t, t));

    tempoIn.min = '20';
    tempoIn.max = '400';
    tempoIn.step = '1';
    tempoIn.placeholder = 'e.g. 96';

    /* Save button + status, in their own column so they sit on the baseline
       with the controls. */
    const actionCol = document.createElement('div');
    actionCol.className = 'col-12 col-md-3 d-flex align-items-end';
    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn btn-sm btn-primary';
    saveBtn.textContent = 'Save key';
    saveBtn.disabled = true;             /* until the current value has loaded */
    actionCol.appendChild(saveBtn);
    row.appendChild(actionCol);

    const status = document.createElement('div');
    status.className = 'form-text small mt-2';
    /* role="status" so the outcome is ANNOUNCED, not merely painted. */
    status.setAttribute('role', 'status');
    status.textContent = 'Loading…';

    fieldset.append(row, status);
    wrap.appendChild(fieldset);
    container.appendChild(wrap);

    /* ---- load the current value ------------------------------------------ */
    songKeyGet(songId).then((key) => {
        if (disposed) return;
        if (key) {
            keySel.value   = typeof key.originalKey === 'string' ? key.originalKey : '';
            tempoIn.value  = (typeof key.tempo === 'number' && key.tempo > 0) ? String(key.tempo) : '';
            tsSel.value    = typeof key.timeSignature === 'string' ? key.timeSignature : '';
            /* A stored key the option list does not contain (data predating
               this list, or hand-entered SQL) would silently reset the select
               to blank and a Save would then WIPE it. Add it as an option so
               what is on screen is what is stored. */
            if (keySel.value === '' && key.originalKey) {
                addOption(keySel, key.originalKey, key.originalKey + ' (stored)');
                keySel.value = key.originalKey;
            }
            if (tsSel.value === '' && key.timeSignature) {
                addOption(tsSel, key.timeSignature, key.timeSignature + ' (stored)');
                tsSel.value = key.timeSignature;
            }
            status.textContent = 'Shown on the song page beside the transpose controls.';
        } else {
            status.textContent = 'No key recorded for this song yet.';
        }
        saveBtn.disabled = false;
    }).catch((e) => {
        if (disposed) return;
        /* A failed READ must not leave the form editable: saving from a form
           that never loaded would overwrite a value nobody has seen. */
        status.textContent = 'Could not read the current key (' + (e.status || '?') + '). Reload to try again.';
        saveBtn.disabled = true;
    });

    /* ---- save ------------------------------------------------------------ */
    function onSave() {
        const originalKey = keySel.value;
        if (originalKey === '') {
            /* The server requires it, so refuse locally with the same rule
               rather than spending a round trip to be told. */
            status.textContent = 'Choose a key first — tempo and time signature are optional.';
            keySel.focus();
            return;
        }
        const tempoRaw = tempoIn.value.trim();
        const tempo = tempoRaw === '' ? null : Number(tempoRaw);
        if (tempo !== null && (!Number.isInteger(tempo) || tempo < 20 || tempo > 400)) {
            status.textContent = 'Tempo must be a whole number between 20 and 400 BPM, or blank.';
            tempoIn.focus();
            return;
        }

        saveBtn.disabled = true;
        status.textContent = 'Saving…';
        songKeySave(songId, {
            originalKey: originalKey,
            tempo: tempo,
            timeSignature: tsSel.value,
        }).then((stored) => {
            if (disposed) return;
            /* Write back the STORED values, not the ones we sent — the server
               normalises and it, not this form, is the truth. */
            if (stored) {
                if (typeof stored.originalKey === 'string' && stored.originalKey !== '') {
                    if (!hasOption(keySel, stored.originalKey)) {
                        addOption(keySel, stored.originalKey, stored.originalKey);
                    }
                    keySel.value = stored.originalKey;
                }
                tempoIn.value = (typeof stored.tempo === 'number' && stored.tempo > 0)
                    ? String(stored.tempo) : '';
                if (typeof stored.timeSignature === 'string') {
                    if (stored.timeSignature !== '' && !hasOption(tsSel, stored.timeSignature)) {
                        addOption(tsSel, stored.timeSignature, stored.timeSignature);
                    }
                    tsSel.value = stored.timeSignature;
                }
            }
            status.textContent = 'Saved.';
            saveBtn.disabled = false;
            toast('Musical key saved', 'success');
        }).catch((e) => {
            if (disposed) return;
            const text = saveMessageForStatus(e && e.status, e && e.message);
            status.textContent = text;
            saveBtn.disabled = false;
            toast(text, 'danger');
        });
    }
    saveBtn.addEventListener('click', onSave);

    return function teardown() {
        disposed = true;
        saveBtn.removeEventListener('click', onSave);
        if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
    };
}

/* ---- tiny DOM helpers, local on purpose --------------------------------- */

/**
 * Build one labelled control inside a Bootstrap row column.
 *
 * `label.htmlFor` + `input.id` rather than a wrapping label, because the ids
 * are also what a curator's browser autofill and any future test harness key
 * off. An unlabelled form control is a WCAG 3.3.2 failure, and three of them in
 * a fieldset is three failures.
 *
 * @param {HTMLElement} row
 * @param {string} id
 * @param {string} labelText
 * @param {'select'|'number'} kind
 * @returns {HTMLSelectElement|HTMLInputElement}
 */
function labelledControl(row, id, labelText, kind) {
    const col = document.createElement('div');
    col.className = 'col-12 col-sm-4 col-md-3';
    const lab = document.createElement('label');
    lab.className = 'form-label small mb-1';
    lab.htmlFor = id;
    lab.textContent = labelText;
    let control;
    if (kind === 'select') {
        control = document.createElement('select');
        control.className = 'form-select form-select-sm';
    } else {
        control = document.createElement('input');
        control.type = 'number';
        control.className = 'form-control form-control-sm';
    }
    control.id = id;
    col.append(lab, control);
    row.appendChild(col);
    return control;
}

/**
 * @param {HTMLSelectElement} sel
 * @param {string} value
 * @param {string} text
 */
function addOption(sel, value, text) {
    const o = document.createElement('option');
    o.value = value;
    /* textContent, never innerHTML — a "(stored)" option's value comes from the
       database and must never be parsed as markup. */
    o.textContent = text;
    sel.appendChild(o);
}

/**
 * @param {HTMLSelectElement} sel
 * @param {string} value
 * @returns {boolean}
 */
function hasOption(sel, value) {
    return Array.prototype.some.call(sel.options, (o) => o.value === value);
}
