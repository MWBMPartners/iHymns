/* ==========================================================================
 *  arrangement-editor.js — the v2 Song Editor's Arrangement editor
 *  (#1627 item 2 — the last day-one blocker for epic #1601; #161, #1618)
 *
 *  ELI5: this is the "what order do the verses/chorus play in" control.
 *  Without it, flipping the v2 default would turn every existing arrangement
 *  into read-only fossil data — the public render and every export format
 *  keep consuming `tblSongs.ArrangementJson`, but nobody could ever create or
 *  change one again, because v2's save model is granular (every edit is its
 *  own atomic write) and nothing in v2 wrote this one column.
 *
 *  mountArrangementEditor(container, ctx) -> teardown fn
 *    ctx = { store, api, songId, toast }   — the same shape every other v2
 *    tab module takes (structure-tab.js, tags-tab.js).
 *
 *  SERVER CONTRACT (manage/editor/api2.php, case 'arrangement_update' —
 *  read that case before changing anything here):
 *    POST ?action=arrangement_update   { songId, arrangement }
 *      arrangement is a list of 0-based indexes into the song's section
 *      list, e.g. [0,1,1,2] for verse/chorus/chorus/verse — REPETITION IS
 *      LEGAL AND IS THE WHOLE POINT, and using only a subset of the sections
 *      is legal too. null or [] CLEARS the stored arrangement.
 *    200 { ok, songId, arrangement }  — the STORED value, echoed back
 *         (null after a clear). NEVER the request value — see persist()
 *         below for why this file only ever renders from that echo.
 *    400 bad songId / non-array / an out-of-range or non-int ordinal — the
 *         message NAMES the bad position; surfaced verbatim (see toast()
 *         call in attemptSet()'s catch, generic branch).
 *    404 unknown song.
 *    409 the ArrangementJson migration has not been run on this install —
 *         an install-wide state, not something this song's curator can fix
 *         here. Handled as an EXPLANATORY notice, not an error toast.
 *    422 this song has an empty section, so the editor's section count and
 *         the published render's (assembled) section count disagree —
 *         includes/arrangement.php refuses to store ordinals that would
 *         mean one thing in the editor and another on the public page.
 *         CLEARING IS STILL ALLOWED in this state (the server's own check
 *         only runs for a non-empty `arrangement`) — that is the one way
 *         out, so the Clear button stays live even while blocked.
 *
 *  WHY BUTTONS, NOT DRAG (mandatory — do not "improve" this into SortableJS)
 *  ---------------------------------------------------------------------
 *  v1's arrangement editor (editor.js ~2188) lazy-loads SortableJS from a CDN
 *  with NO integrity attribute — a named red flag in .claude/CLAUDE.md
 *  (#1587/#1647: unpinned/unhashed third-party code inside an authenticated
 *  admin session). It is also the WCAG 2.5.7 (Dragging Movements) failure
 *  #1644 already fixed once in this exact repo, in setlist.js's arrangement
 *  modal: drag has no single-pointer equivalent, so a touch-only or
 *  switch/keyboard user simply cannot reorder anything. Move-left / move-
 *  right `<button>`s are the WCAG-compliant path AND they sidestep the SRI
 *  problem outright, by never loading a third script at all.
 *  https://www.w3.org/WAI/WCAG21/Understanding/dragging-movements.html
 *
 *  THE #1644 REFOCUS DETAIL (load-bearing — see move() below)
 *  ------------------------------------------------------------
 *  render() rebuilds the whole strip's innerHTML on every change, which
 *  DESTROYS whichever button currently has keyboard focus. Left alone, focus
 *  falls back to <body> and the next Tab/Enter from a keyboard user starts
 *  over at the top of the document instead of continuing to reorder —
 *  silently reintroducing the "technically focusable, nothing usable"
 *  failure #1644 was filed to fix, one call site later. move() re-queries
 *  the moved chip's controls at their NEW data-pos and calls .focus() on
 *  one of them after every move. Every user-visible change is also spoken
 *  through the shared assistive-tech announcer (js/utils/announce.js,
 *  #1645) — reused here, not reimplemented, per the modularity rule.
 *
 *  "DO NOT OPTIMISTICALLY TRUST THE LOCAL ARRAY" (see persist() below)
 *  ---------------------------------------------------------------------
 *  Every mutation sends a TENTATIVE array to the server and waits for the
 *  response before touching the store or re-rendering. On success the store
 *  is written from `res.arrangement` (the server's echo), never from the
 *  tentative array this file built locally. On failure the store — and
 *  therefore the rendered strip — is untouched, so a rejected move silently
 *  reverts to the last-confirmed order rather than showing a state the
 *  server never actually persisted.
 * ========================================================================== */

import { announce } from '/js/utils/announce.js';
import { iconBtn } from './ui-helpers.js';

/* ---- section labelling — ported from v1's getComponentLabel() (editor.js
   ~1832), which delegates to componentHeaderLabel(): "Verse 1", "Chorus"
   (no number when there's only one / number is 0), "Bridge 2", etc.
   Refrain is treated as an alias for Chorus in the label, matching v1 and
   structure-tab.js's own COMPONENT_TYPES list. ---- */
function getComponentLabel(comp) {
    const type = (comp && (comp.type === 'refrain' ? 'chorus' : comp.type)) || 'verse';
    const cap = type.charAt(0).toUpperCase() + type.slice(1);
    const n = comp && comp.number;
    const num = (n != null && n !== '' && !isNaN(n) && parseInt(n, 10) > 0) ? parseInt(n, 10) : null;
    return num != null ? (cap + ' ' + num) : cap;
}

/* ---- arrangement presets — ported from v1's ARRANGEMENT_PRESETS (editor.js
   ~2341) + autoGenerateArrangement() (~1959) + componentIdxByType /
   firstIndexOfType (~2381). Each entry is a pure function of the section
   list; returning null means "this song is missing a section type the
   pattern needs" and the caller must never throw for that — it drives the
   UI's own enable/disable, exactly like v1's data-requires attribute did. */
function componentIdxByType(comps, type) {
    const out = [];
    comps.forEach((c, i) => { if (c.type === type) { out.push(i); } });
    return out;
}
function firstIndexOfType(comps, type, aliasType) {
    for (let i = 0; i < comps.length; i++) {
        if (comps[i].type === type || (aliasType && comps[i].type === aliasType)) { return i; }
    }
    return -1;
}
function autoGenerateArrangement(comps) {
    if (!comps || comps.length === 0) { return null; }
    let refrainIndex = -1;
    for (let i = 0; i < comps.length; i++) {
        const t = comps[i].type;
        if (t === 'chorus' || t === 'refrain') { refrainIndex = i; break; }
    }
    if (refrainIndex === -1) { return null; }

    /* SDAH-93-style "verse-1-acts-as-chorus": when the refrain comes BEFORE
       any verse, lead the arrangement with it too, not just repeat it after
       each verse (mirrors v1's #598 fix exactly). */
    let firstVerseIndex = -1;
    for (let k = 0; k < comps.length; k++) {
        if (comps[k].type === 'verse') { firstVerseIndex = k; break; }
    }
    const leadWithRefrain = (firstVerseIndex !== -1 && refrainIndex < firstVerseIndex);

    const arrangement = [];
    if (leadWithRefrain) { arrangement.push(refrainIndex); }
    comps.forEach((comp, j) => {
        if (comp.type === 'verse') {
            arrangement.push(j, refrainIndex);
        } else if (j !== refrainIndex) {
            arrangement.push(j);
        }
    });
    return arrangement;
}

const ARRANGEMENT_PRESETS = {
    'chorus-after-each-verse': (comps) => autoGenerateArrangement(comps),
    'verses-only': (comps) => {
        const verses = componentIdxByType(comps, 'verse');
        return verses.length > 0 ? verses : null;
    },
    'verse-prechorus-chorus': (comps) => {
        const verses = componentIdxByType(comps, 'verse');
        const preIdx = firstIndexOfType(comps, 'pre-chorus');
        const choIdx = firstIndexOfType(comps, 'chorus', 'refrain');
        if (!verses.length || preIdx < 0 || choIdx < 0) { return null; }
        const arr = [];
        verses.forEach((v) => { arr.push(v, preIdx, choIdx); });
        return arr;
    },
    'verse-bridge-verse': (comps) => {
        const verses = componentIdxByType(comps, 'verse');
        const brIdx = firstIndexOfType(comps, 'bridge');
        if (verses.length < 2 || brIdx < 0) { return null; }
        const arr = verses.slice(0, -1);
        arr.push(brIdx, verses[verses.length - 1]);
        return arr;
    },
    'intro-verses-outro': (comps) => {
        const intro = firstIndexOfType(comps, 'intro');
        const outro = firstIndexOfType(comps, 'outro');
        const verses = componentIdxByType(comps, 'verse');
        if (intro < 0 || outro < 0 || !verses.length) { return null; }
        return [intro].concat(verses, [outro]);
    },
};
function presetLabel(name) {
    return name.replace(/-/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

/* ---- failure KIND, from the HTTP status.
 *
 * ELI5: 409 means "this install hasn't run the migration yet"; 422 means "this
 * song has empty sections". Both get a calm explanation instead of an error
 * toast, so we need to tell them apart.
 *
 * Detail: this originally matched the server's PROSE
 * (/migration has not been run/i, /empty sections/i) because unwrap() discarded
 * res.status — and carried a comment saying "if that wording ever changes,
 * update these two together". That is a comment where a mechanism belongs, and
 * rewording a server string would have silently downgraded this UI to a generic
 * toast with nothing failing anywhere.
 *
 * unwrap() now attaches `status` to the thrown Error, so these branch on the
 * contract (the status code, which is part of the endpoint's documented
 * interface) rather than on a sentence that is free to change. Numbers are
 * asserted against api2.php's actual ed2_respond(..., NNN) calls by
 * tests/test-v2-arrangement-ui.js. */
function isMigrationMissing(err) {
    return !!err && err.status === 409;
}
function isEmptySections(err) {
    return !!err && err.status === 422;
}

/* #1991 — the arrangement chip toolbar's icon-only buttons want a wider
   class list than the shared default (btn-sm + the arr-btn sizing hook),
   so every call site here passes it explicitly via iconBtn()'s opts —
   this keeps the rendered markup byte-identical to the pre-extraction
   local copy while still sharing the ONE implementation in ui-helpers.js. */
const ARR_ICON_BTN_OPTS = { className: 'btn btn-sm btn-outline-secondary arr-btn' };

export function mountArrangementEditor(container, ctx) {
    const { store, api, songId } = ctx;
    const toast = ctx.toast || function () {};

    /* null = normal; 'migration' = 409 (whole editor off); 'empty-sections' =
       422 (Clear stays live, everything else off — see the file header). */
    let blockedReason = null;

    function currentComponents() {
        const c = store.get('components');
        return Array.isArray(c) ? c : [];
    }

    /* Current value: `load_song` ships the raw tblSongs column, PascalCase,
       as JSON text (or null). Mirrors the task's own note on this exactly —
       never re-derive this from anything else. */
    function readArrangement() {
        const song = store.get('song') || {};
        try {
            const parsed = JSON.parse(song.ArrangementJson || 'null');
            return Array.isArray(parsed) ? parsed : null;
        } catch (_e) {
            return null;
        }
    }

    /* Ported from v1's effectiveArrangement() (editor.js ~2152): an explicit
       non-empty arrangement wins; otherwise fall back to sequential section
       order, exactly like the public render does for a song with none. */
    function effectiveArrangement() {
        const stored = readArrangement();
        if (Array.isArray(stored) && stored.length > 0) { return stored.slice(); }
        return currentComponents().map((_, i) => i);
    }

    /**
     * Send a tentative arrangement to the server and — ONLY on success — write
     * the server's ECHOED value back into the store. Never writes `arr` (the
     * local, tentative array) itself; see the file header's "do not
     * optimistically trust the local array" note. Returns the stored value
     * (an array, or null for a confirmed clear) on success, or `undefined` on
     * failure — `undefined` is the one value `readArrangement()` can never
     * produce, so it is safe as a distinct "this failed" sentinel.
     */
    async function attemptSet(arr, announceMsg) {
        try {
            const res = await api.updateArrangement(songId, arr);
            const stored = Array.isArray(res.arrangement) ? res.arrangement : null;
            store.update('song', (s) => Object.assign({}, s || {}, {
                ArrangementJson: stored ? JSON.stringify(stored) : null,
            }));
            blockedReason = null;
            render();
            if (announceMsg) { announce(announceMsg); }
            return stored;
        } catch (e) {
            if (isMigrationMissing(e)) {
                blockedReason = 'migration';
            } else if (isEmptySections(e)) {
                blockedReason = 'empty-sections';
            } else {
                /* 400 (names the bad position) / 404 / anything else — surface
                   the server's message verbatim, not reworded. */
                toast(e.message, 'danger');
            }
            render();
            return undefined;
        }
    }

    async function append(idx) {
        const comp = currentComponents()[idx];
        const arr = effectiveArrangement();
        arr.push(idx);
        await attemptSet(arr, (comp ? getComponentLabel(comp) : 'Section') + ' added, position ' + arr.length);
    }

    async function removeAt(pos) {
        const arr = effectiveArrangement();
        if (pos < 0 || pos >= arr.length) { return; }
        const comp = currentComponents()[arr[pos]];
        const label = comp ? getComponentLabel(comp) : 'Section';
        arr.splice(pos, 1);
        const stored = await attemptSet(arr.length ? arr : null, label + ' removed');
        if (stored !== undefined) {
            const total = stored ? stored.length : 0;
            const nextPos = Math.min(pos, total - 1);
            if (nextPos >= 0) {
                container.querySelector('.arr-remove[data-pos="' + nextPos + '"]')?.focus();
            }
        }
    }

    /**
     * Move the chip at `pos` one slot in `dir` (-1 = earlier, +1 = later).
     * THE #1644 REFOCUS — see the file header. render() (called inside
     * attemptSet()) just rebuilt the whole strip, destroying the button that
     * was clicked/activated. Re-query the moved chip's controls at their NEW
     * data-pos and land focus on whichever of {same-direction move button,
     * opposite-direction move button, remove button} is enabled there — the
     * remove button is NEVER disabled, so this always finds a target when the
     * move itself succeeded (a move only runs at all when the strip has >= 2
     * entries, so at least one direction is always enabled at any position).
     */
    async function move(pos, dir) {
        const arr = effectiveArrangement();
        const newPos = pos + dir;
        if (newPos < 0 || newPos >= arr.length) { return; }
        const comp = currentComponents()[arr[pos]];
        const label = comp ? getComponentLabel(comp) : 'Section';
        const tmp = arr[pos]; arr[pos] = arr[newPos]; arr[newPos] = tmp;
        const stored = await attemptSet(arr, label + ' moved to position ' + (newPos + 1) + ' of ' + arr.length);
        if (stored !== undefined) {
            const sameDirSel = (dir < 0 ? '.arr-move-left' : '.arr-move-right') + '[data-pos="' + newPos + '"]:not([disabled])';
            const otherDirSel = (dir < 0 ? '.arr-move-right' : '.arr-move-left') + '[data-pos="' + newPos + '"]:not([disabled])';
            const target = container.querySelector(sameDirSel)
                || container.querySelector(otherDirSel)
                || container.querySelector('.arr-remove[data-pos="' + newPos + '"]');
            if (target) { target.focus(); }
        }
    }

    /* Clearing is ALWAYS offered, even while `blockedReason === 'empty-
       sections'` — the server's own 422 check only runs for a non-empty
       arrangement (see the file header), so this is the one action that can
       still succeed in that state, and it is how a curator gets out of it. */
    async function clearArrangement() {
        await attemptSet(null, 'Arrangement cleared — using section order.');
    }

    function applyPreset(name) {
        const comps = currentComponents();
        const fn = ARRANGEMENT_PRESETS[name];
        if (!fn) { return; }
        const arr = fn(comps);
        if (!arr) {
            toast('This song is missing a section type needed for that pattern.', 'warning');
            return;
        }
        attemptSet(arr, 'Arrangement set: ' + presetLabel(name) + '.');
    }

    /* ---- render ---- */

    function render() {
        container.innerHTML = '';

        const wrap = document.createElement('div');
        wrap.className = 'arr-editor';

        /* Compact header row: title left; Presets dropdown + Sequential/clear
           share the SAME row (right) instead of a fifth stacked row. Actions
           appended after the early-returns (a preset can't apply in those
           states). */
        const headerRow = document.createElement('div');
        headerRow.className = 'd-flex align-items-center flex-wrap gap-2 mb-1';
        const heading = document.createElement('h3');
        heading.className = 'h6 mb-0';
        heading.innerHTML = '<i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Arrangement';
        headerRow.appendChild(heading);
        wrap.appendChild(headerRow);

        if (blockedReason === 'migration') {
            const note = document.createElement('div');
            note.className = 'alert alert-secondary py-2 small mb-0';
            note.setAttribute('role', 'status');
            note.textContent = 'Arrangement editing is not available on this install yet — the ArrangementJson migration has not been run.';
            wrap.appendChild(note);
            container.appendChild(wrap);
            return;
        }

        const comps = currentComponents();
        if (comps.length === 0) {
            const note = document.createElement('div');
            note.className = 'text-muted small';
            note.textContent = 'Add sections above before setting an arrangement.';
            wrap.appendChild(note);
            container.appendChild(wrap);
            return;
        }

        if (blockedReason === 'empty-sections') {
            const note = document.createElement('div');
            note.className = 'alert alert-warning py-2 small';
            note.setAttribute('role', 'status');
            note.textContent = 'This song has an empty section, so the editor and the published song disagree on section numbering. Remove the empty section above, or clear the arrangement below.';
            wrap.appendChild(note);
            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.className = 'btn btn-sm btn-outline-danger';
            clearBtn.textContent = 'Clear arrangement';
            clearBtn.addEventListener('click', clearArrangement);
            wrap.appendChild(clearBtn);
            container.appendChild(wrap);
            return;
        }

        /* ---- presets dropdown + Sequential/clear (right of heading) — same 5
           ARRANGEMENT_PRESETS + clear, one dropdown instead of a wrap-row.
           Bootstrap's dropdown data-API is delegated on document, so a
           dynamically-injected data-bs-toggle needs no manual init. An
           unavailable preset = disabled item with the same explanatory title. */
        const headerActions = document.createElement('div');
        headerActions.className = 'ms-auto d-flex align-items-center gap-1';

        const presetsDrop = document.createElement('div');
        presetsDrop.className = 'dropdown';
        const presetsBtn = document.createElement('button');
        presetsBtn.type = 'button';
        presetsBtn.className = 'btn btn-sm btn-outline-primary dropdown-toggle';
        presetsBtn.setAttribute('data-bs-toggle', 'dropdown');
        presetsBtn.setAttribute('aria-expanded', 'false');
        presetsBtn.innerHTML = '<i class="bi bi-stars me-1" aria-hidden="true"></i>Presets';
        const presetsMenu = document.createElement('ul');
        presetsMenu.className = 'dropdown-menu dropdown-menu-end';
        Object.keys(ARRANGEMENT_PRESETS).forEach((name) => {
            const available = ARRANGEMENT_PRESETS[name](comps) !== null;
            const li = document.createElement('li');
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'dropdown-item';
            item.textContent = presetLabel(name);
            item.disabled = !available;
            item.title = available ? '' : 'This song is missing a section type needed for that pattern.';
            item.addEventListener('click', () => applyPreset(name));
            li.appendChild(item);
            presetsMenu.appendChild(li);
        });
        presetsDrop.append(presetsBtn, presetsMenu);

        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'btn btn-sm btn-outline-secondary';
        clearBtn.textContent = 'Sequential / clear';
        clearBtn.title = 'Clear the custom arrangement — falls back to section order.';
        clearBtn.addEventListener('click', clearArrangement);

        headerActions.append(presetsDrop, clearBtn);
        headerRow.appendChild(headerActions);

        /* ---- pool (click to add) — one row, inline caption. ---- */
        const pool = document.createElement('div');
        pool.className = 'd-flex flex-wrap align-items-center gap-1 mb-1';
        const poolLabel = document.createElement('span');
        poolLabel.className = 'small text-body-secondary fw-semibold me-1';
        poolLabel.textContent = 'Add:';
        poolLabel.title = 'Click a section to append it to the running order';
        pool.appendChild(poolLabel);
        comps.forEach((comp, idx) => {
            const label = getComponentLabel(comp);
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'badge rounded-pill border-0 arr-pool-chip';
            chip.style.cursor = 'pointer';
            /* #1860 Phase 5 Commit 8 — chip TEXT stays structural (space-
               constrained: "V1"/"Chorus" identifies STRUCTURE for arrangement
               editing), but a curator-set comp.label is surfaced in the
               tooltip so it isn't invisible here. */
            const customPool = (comp && comp.label != null) ? String(comp.label).trim() : '';
            chip.title = (customPool !== '' ? customPool + ' (' + label + ')' : label) + ' — click to add';
            chip.textContent = label;
            chip.addEventListener('click', () => append(idx));
            pool.appendChild(chip);
        });
        wrap.appendChild(pool);

        /* ---- strip (running order) — inline caption INSIDE the box;
           move-left/move-right/remove buttons only (never drag — file header).
           Four flat siblings per entry (WCAG 4.1.2). ---- */
        const strip = document.createElement('div');
        strip.className = 'd-flex flex-wrap align-items-center gap-1 p-1 rounded border arr-strip';
        strip.setAttribute('aria-label', "Current arrangement order. Use each item's move and remove buttons to reorder.");
        const stripLabel = document.createElement('span');
        stripLabel.className = 'small text-body-secondary fw-semibold ms-1 me-1';
        stripLabel.textContent = 'Order:';
        strip.appendChild(stripLabel);

        const arr = effectiveArrangement();
        if (arr.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'text-muted small fst-italic';
            empty.textContent = 'Empty — click a section above to add.';
            strip.appendChild(empty);
        } else {
            arr.forEach((idx, pos) => {
                const comp = comps[idx];
                if (!comp) { return; }
                const label = getComponentLabel(comp);
                const isFirst = pos === 0;
                const isLast = pos === arr.length - 1;

                const item = document.createElement('div');
                item.className = 'd-inline-flex align-items-center gap-1 arr-strip-item';

                const tag = document.createElement('span');
                tag.className = 'badge rounded-pill arr-strip-chip';
                tag.textContent = label;
                /* #1860 Phase 5 Commit 8 — same tooltip-only surfacing as the
                   pool chip above; the ordering strip's chip text is also
                   left structural. */
                const customStrip = (comp && comp.label != null) ? String(comp.label).trim() : '';
                tag.title = (customStrip !== '' ? customStrip + ' (' + label + ')' : label)
                    + ' — position ' + (pos + 1) + ' of ' + arr.length;

                const btnLeft = iconBtn('bi-arrow-left', 'Move ' + label + ' earlier', isFirst, () => move(pos, -1), ARR_ICON_BTN_OPTS);
                btnLeft.classList.add('arr-move-left');
                btnLeft.dataset.pos = String(pos);

                const btnRight = iconBtn('bi-arrow-right', 'Move ' + label + ' later', isLast, () => move(pos, 1), ARR_ICON_BTN_OPTS);
                btnRight.classList.add('arr-move-right');
                btnRight.dataset.pos = String(pos);

                const btnRemove = iconBtn('bi-x-lg', 'Remove ' + label, false, () => removeAt(pos), ARR_ICON_BTN_OPTS);
                btnRemove.classList.add('arr-remove', 'text-danger');
                btnRemove.dataset.pos = String(pos);

                item.append(tag, btnLeft, btnRight, btnRemove);
                strip.appendChild(item);
            });
        }
        wrap.appendChild(strip);

        container.appendChild(wrap);
    }

    /* Depends on `components` (the pool + every bound index + the preset
       availability) but OWNS `song.ArrangementJson` — no other v2 module
       writes that field, so re-rendering on our own successful/failed writes
       (inside attemptSet(), above) is enough; we don't additionally subscribe
       to the `song` slice the way metadata-tab.js does, which would also fire
       on the unrelated scalar edits that slice can carry after a revision
       restore reload. A structural change elsewhere (add/remove/reorder a
       section in the Structure tab above) can only be discovered here via
       `components`, and may have resolved an `empty-sections` block, so the
       subscriber resets `blockedReason` before re-rendering. */
    const off = store.subscribe('components', () => { blockedReason = null; render(); });
    render();

    return function teardown() {
        off();
        container.innerHTML = '';
    };
}
