/* ==========================================================================
 *  present-mode.js — Full-screen slide-by-slide Presentation mode (#297) for
 *  the public song page ("Present" button in the toolbar).
 *
 *  Builds a full-screen overlay from the lyric components already rendered
 *  on the page (`.lyric-component` / `.lyric-label` / `.lyric-line`) — no
 *  extra network fetch, it just re-reads the DOM the song page already
 *  produced — and lets the user step through verse/chorus "slides" with
 *  buttons, keyboard arrows, or a touch swipe.
 *
 *  CSP history (#1568): this used to be an inline <script> at the bottom of
 *  includes/pages/song.php. The document sends an enforcing nonce CSP
 *  (script-src 'self' 'nonce-…', no unsafe-inline — #117), which refuses any
 *  inline <script> lacking that exact per-request nonce, and the song
 *  fragment is a shared-cache API response (/api?page=song, rule #6 in
 *  .claude/CLAUDE.md) that can never be stamped with one request's nonce —
 *  so the inline version silently never ran for any visitor. This is the
 *  SAME class of bug as the Export dropdown (#1565): moved into a real ES
 *  module the router imports directly. See router.js afterPageLoad(),
 *  page === 'song' block, which calls initPresentMode() once the song
 *  fragment is in the DOM.
 *
 *  ROUNDS / CANON PROJECTOR (#2073). When the song page carries one or more
 *  rounds — several voices singing the SAME words, each starting later than
 *  the one before, e.g. "Row, row, row your boat" — this module reads them
 *  from `.page-song[data-voice-rounds]` (server-rendered by
 *  `ihymnsVoiceRoundsDataAttr()`, see includes/voice_parts_render.php) and
 *  inserts ONE extra slide per round, right after the normal slide for the
 *  component holding that round's first line. The extra slide shows one
 *  panel per voice with its OWN current line, and lets the presenter step
 *  through — or, when the round carries real timing, play — the staggered
 *  schedule. A song with no rounds (the overwhelming majority) is completely
 *  unaffected: the attribute is simply absent and every code path below is
 *  skipped.
 *
 *  The step-by-step schedule itself is worked out by `roundTimeline()`
 *  below — a byte-for-byte JavaScript twin of the PHP original,
 *  `lyricRoundTimeline()` in includes/lyric_rounds.php. Two independent
 *  hand-written copies of the same maths drift apart over time (rule #35 in
 *  .claude/CLAUDE.md — "a comment saying keep these in sync is the failure,
 *  not the fix"), so `tests/test-present-round-projector.js` runs BOTH
 *  copies over the same worked-out cases and proves they agree.
 *
 *  KNOWN, FLAGGED GAP: `.page-song[data-voice-rounds]` is deliberately
 *  sparse today (see `includes/voice_parts_render.php`'s own "ROUND-SHAPE
 *  ADAPTER" doc-comment) — it does not yet carry a round's own
 *  `timesThrough`/`bpm`/`beatsPerLine`, or a voice's own `entryBasis`/
 *  `entryBeats`. `roundTimeline()` defaults every one of those exactly the
 *  way the PHP original does when they are absent, so this projector is
 *  already correct for what the markup can say today; a later commit that
 *  enriches that attribute makes the projector more accurate (real tempo,
 *  a curator-chosen repeat count) with NO change needed here. In practice
 *  this means most rounds resolve to the plain "lines" basis — no clock, no
 *  Play button, step-by-step only — until per-line timing data exists
 *  somewhere the page can read it.
 * ========================================================================== */

/**
 * Wire the "Present" button on the song page. Idempotent — the SPA injects a
 * fresh song fragment (and a fresh #btn-present) on every navigation, so the
 * `dataset.wired` guard is per-DOM-node, not a global "only once ever" flag
 * (same pattern as export-ui.js's `menu.dataset.wired`).
 */

import { announce } from '../utils/announce.js';

/**
 * iHymns — Rounds/canon PURE timeline, the JavaScript twin of
 * `lyricRoundTimeline()` in `includes/lyric_rounds.php` (#2073).
 *
 * ELI5: turns "3 voices, entering 2 lines apart, twice through" into an
 * exact list of steps — for every step, which line (or "waiting"/
 * "finished") each voice is on, and, when the round's own timing supports
 * it, how many real milliseconds into the round that step falls. This is
 * the SAME job the PHP original does, done again here so a browser can
 * step/play a round without a round trip to the server for every click —
 * and it MUST keep producing the exact same answer as that PHP twin for the
 * same input. That agreement is proved case-by-case by
 * `tests/test-present-round-projector.js`, never assumed. Do not change the
 * algorithm here without moving `lyricRoundTimeline()` the same way and
 * re-running that test.
 *
 * See the PHP original's own doc-block for the full worked-out algorithm
 * this transcribes (basis resolution, per-voice entry offsets, the
 * "together"/"coda" ending rules, and the two documented corner cases for a
 * coda under the "ms" basis and the `entryMs` search bound) — repeated
 * here only where the JavaScript itself needs a comment a reader of the PHP
 * would not.
 *
 * @see appWeb/public_html/includes/lyric_rounds.php   lyricRoundTimeline(), the original
 * @see tests/test-present-round-projector.js           the lockstep proof
 *
 * @param {{timesThrough?: ?number, endingMode?: string, bpm?: ?number, beatsPerLine?: ?number, codaLineIds?: number[]}} round
 * @param {Array<{number: number, entryBasis?: string, entryLines?: number, entryBeats?: ?number, entryMs?: ?number, timesThrough?: ?number}>} voices
 * @param {number[]} subjectLineIds
 * @param {Object<number, number>} lineStartMs  lineId -> ms (subject AND, optionally, coda lines)
 * @param {Object<number, number>} lineEndMs
 * @returns {{basis: string, stepMs: ?number, steps: Array<{i: number, atMs: ?number, voices: Array<{n: number, line: number}>}>}}
 */
export function roundTimeline(round, voices, subjectLineIds, lineStartMs, lineEndMs) {
    const n = subjectLineIds.length;
    if (n === 0 || !voices || voices.length === 0) {
        return { basis: 'lines', stepMs: null, steps: [] };
    }

    const sortedVoices = voices.slice().sort((a, b) => (a.number || 0) - (b.number || 0));

    const endingMode = round.endingMode || 'complete';
    const roundTimesThrough = Math.max(1, (round.timesThrough != null ? round.timesThrough : 2));
    const bpm = (round.bpm != null) ? round.bpm : null;
    const beatsPerLine = (round.beatsPerLine != null) ? round.beatsPerLine : null;
    const codaLineIds = round.codaLineIds || [];

    /* ---- 1. Which basis? (the same three-way decision as the PHP original) ---- */
    const hasMs = (lid) => lineStartMs[lid] !== undefined && lineStartMs[lid] !== null
        && lineEndMs[lid] !== undefined && lineEndMs[lid] !== null;
    let msPossible = true;
    for (const v of sortedVoices) {
        if ((v.number || 0) > 1 && (v.entryMs === null || v.entryMs === undefined)) { msPossible = false; break; }
    }
    if (msPossible) {
        for (const lid of subjectLineIds) {
            if (!hasMs(lid)) { msPossible = false; break; }
        }
    }
    let basis;
    if (msPossible) { basis = 'ms'; }
    else if (typeof bpm === 'number' && bpm > 0 && typeof beatsPerLine === 'number' && beatsPerLine > 0) { basis = 'beats'; }
    else { basis = 'lines'; }
    const stepMs = (basis === 'beats') ? Math.round((60000 / bpm) * beatsPerLine) : null;

    /* ---- 2. Subject-line durations + a cumulative-duration lookup.
       cumDur(k) = total ms elapsed at the START of subject step k, cycling
       the n subject lines as k grows past n — mirrors the PHP original's own
       memoised cumDur() closure exactly, including its cache shape. ---- */
    const dur = {};
    if (basis === 'ms') {
        for (const lid of subjectLineIds) { dur[lid] = Math.max(0, lineEndMs[lid] - lineStartMs[lid]); }
    }
    const cumCache = { 0: 0 };
    function cumDur(k) {
        if (cumCache[k] !== undefined) { return cumCache[k]; }
        let from = 0;
        for (const known of Object.keys(cumCache)) {
            const kn = Number(known);
            if (kn <= k && kn > from) { from = kn; }
        }
        let total = cumCache[from];
        for (let j = from; j < k; j++) { total += dur[subjectLineIds[j % n]] || 0; }
        cumCache[k] = total;
        return total;
    }

    /* ---- 3. Per-voice entry offset e_v, in LINE STEPS. ---- */
    const maxMsSearch = Math.max(8, roundTimesThrough) * n + 1;
    const entrySteps = {};
    for (const v of sortedVoices) {
        const num = v.number || 0;
        const voiceBasis = v.entryBasis || 'lines';
        if (basis === 'beats' && voiceBasis === 'beats' && v.entryBeats !== null && v.entryBeats !== undefined && beatsPerLine) {
            entrySteps[num] = Math.round(v.entryBeats / beatsPerLine);
        } else if (basis === 'ms' && voiceBasis === 'ms' && v.entryMs !== null && v.entryMs !== undefined) {
            const target = v.entryMs;
            let k = 0;
            while (k < maxMsSearch && cumDur(k) < target) { k++; }
            entrySteps[num] = k;
        } else {
            entrySteps[num] = v.entryLines || 0;
        }
    }

    /* ---- 4. Per-voice T_v, and voice 1's own T for the together/coda total. ---- */
    const timesThroughByVoice = {};
    for (const v of sortedVoices) {
        const num = v.number || 0;
        timesThroughByVoice[num] = (v.timesThrough !== null && v.timesThrough !== undefined)
            ? Math.max(1, v.timesThrough)
            : roundTimesThrough;
    }
    const t1 = (timesThroughByVoice[1] !== undefined) ? timesThroughByVoice[1] : roundTimesThrough;

    /* ---- 5. Total subject steps S. ---- */
    let S;
    if (endingMode === 'together' || endingMode === 'coda') {
        S = n * t1;
    } else {
        S = 0;
        for (const v of sortedVoices) {
            const num = v.number || 0;
            const cand = entrySteps[num] + n * timesThroughByVoice[num];
            if (cand > S) { S = cand; }
        }
    }

    /* ---- 6. Subject steps. ---- */
    const steps = [];
    for (let i = 0; i < S; i++) {
        const stepVoices = [];
        for (const v of sortedVoices) {
            const num = v.number || 0;
            const e = entrySteps[num];
            const tv = timesThroughByVoice[num];
            const p = i - e;
            let line;
            if (p < 0) { line = -1; }
            else if (p >= n * tv) { line = -2; }
            else { line = p % n; }
            stepVoices.push({ n: num, line });
        }
        let atMs = null;
        if (basis === 'beats') { atMs = i * stepMs; }
        else if (basis === 'ms') { atMs = cumDur(i); }
        steps.push({ i, atMs, voices: stepVoices });
    }

    /* ---- 7. Coda steps (endingMode === 'coda' only). ---- */
    const codaCount = codaLineIds.length;
    if (endingMode === 'coda' && codaCount > 0) {
        const subjectTotalMs = (basis === 'ms') ? cumDur(S) : null;
        let codaCumMs = 0;
        let codaHasMs = (basis === 'ms');
        for (let j = 0; j < codaCount; j++) {
            const stepVoices = sortedVoices.map((v) => ({ n: v.number || 0, line: n + j }));
            let atMs = null;
            if (basis === 'beats') { atMs = (S + j) * stepMs; }
            else if (basis === 'ms' && codaHasMs) {
                const lid = codaLineIds[j];
                if (hasMs(lid)) {
                    atMs = subjectTotalMs + codaCumMs;
                    codaCumMs += Math.max(0, lineEndMs[lid] - lineStartMs[lid]);
                } else {
                    codaHasMs = false;   // graceful narrowing — see lyricRoundTimeline()'s own doc-block corner (1)
                }
            }
            steps.push({ i: S + j, atMs, voices: stepVoices });
        }
    }

    return { basis, stepMs, steps };
}

/**
 * ELI5: the actual clock behind a round's Play button — call `onStep` for
 * each step in turn, waiting the right number of real milliseconds between
 * them (using each step's own `atMs`, so a round with real timing plays at
 * the right speed), and never leave a timer running once `stop()` is
 * called.
 *
 * Deliberately DOM-free and clock-injectable (`scheduleFn`/`cancelFn`
 * default to the real `setTimeout`/`clearTimeout`) so it can be unit tested
 * with a fake clock and no browser at all — this repo has no jsdom (see
 * `tests/test-present-round-projector.js`'s own header). A timer this
 * helper starts and never clears is a real bug: rule #32's sibling in
 * .claude/CLAUDE.md says any timer fixed to the presentation overlay MUST
 * be cleared when the overlay closes, as the very first action, before any
 * early return — `initPresentMode()`'s `close()` below calls `stop()`
 * unconditionally as its first statement for exactly this reason.
 *
 * @param {Array<{i: number, atMs: ?number}>} steps
 * @param {{onStep: (index: number) => void, scheduleFn?: Function, cancelFn?: Function}} opts
 * @returns {{start: (fromIndex?: number) => void, stop: () => void, index: number}}
 */
export function createRoundAutoAdvance(steps, opts) {
    const onStep = opts && opts.onStep;
    const scheduleFn = (opts && opts.scheduleFn) || setTimeout;
    const cancelFn = (opts && opts.cancelFn) || clearTimeout;
    /* A step with no usable atMs neighbour (only possible for the documented
       "ms"-basis coda degrade in roundTimeline()'s own doc-block) still
       needs to advance eventually rather than stall forever — generous on
       purpose, and only ever reached for that one corner case. */
    const FALLBACK_STEP_MS = 1200;

    let timer = null;
    let index = 0;

    function armNext() {
        if (index >= steps.length - 1) { return; }
        const cur = steps[index] ? steps[index].atMs : null;
        const nxt = steps[index + 1] ? steps[index + 1].atMs : null;
        const delay = (typeof cur === 'number' && typeof nxt === 'number' && nxt > cur)
            ? (nxt - cur)
            : FALLBACK_STEP_MS;
        timer = scheduleFn(() => {
            timer = null;
            index++;
            if (typeof onStep === 'function') { onStep(index); }
            armNext();
        }, delay);
    }

    return {
        start(fromIndex) {
            this.stop();
            index = (typeof fromIndex === 'number') ? fromIndex : index;
            armNext();
        },
        stop() {
            if (timer !== null) { cancelFn(timer); timer = null; }
        },
        get index() { return index; },
    };
}

export function initPresentMode() {
    const btnPresent = document.getElementById('btn-present');
    if (!btnPresent || btnPresent.dataset.wired === '1') return;
    btnPresent.dataset.wired = '1';

    btnPresent.addEventListener('click', () => {
        /* Collect all song components from the rendered page */
        const comps = document.querySelectorAll('.lyric-component');
        if (comps.length === 0) return;

        const slides = [];
        comps.forEach(comp => {
            const label = comp.querySelector('.lyric-label')?.textContent?.trim() || '';
            const lines = Array.from(comp.querySelectorAll('.lyric-line')).map(l => l.textContent);
            slides.push({ kind: 'component', label, text: lines.join('\n') });
        });

        /* #2073 — Rounds/canon staggered projector. See this file's own
           header comment for the full "why"; every step here is wrapped so
           a missing or malformed attribute degrades to "no round slides",
           never a broken Present button — the ordinary slides built above
           are already complete and correct on their own. */
        const pageEl = document.querySelector('.page-song');
        let roundsData = [];
        if (pageEl && pageEl.dataset && pageEl.dataset.voiceRounds) {
            try {
                const parsed = JSON.parse(pageEl.dataset.voiceRounds);
                if (Array.isArray(parsed)) { roundsData = parsed; }
            } catch (_e) { roundsData = []; }
        }

        /* Line text by id, straight from the DOM the song page already
           rendered — populated below only when there is at least one round
           to show, since it is otherwise unused. `.lyric-line`'s
           textContent is guaranteed pure sung words with nothing else
           mixed in — the voice chip is a SIBLING of the line, never a
           child (includes/voice_parts_render.php's own load-bearing rule) —
           so this read is exactly as safe as the plain-slide build two
           lines above it. Declared here (not inside the `if` below) so the
           round-slide render helpers, defined later in this same click
           handler, can all close over the one instance. */
        const lineTextById = {};

        if (roundsData.length > 0) {
            document.querySelectorAll('.page-song [data-line-id]').forEach((el) => {
                const id = parseInt(el.dataset.lineId, 10);
                if (Number.isInteger(id)) { lineTextById[id] = (el.textContent || '').trim(); }
            });

            /* Which rendered component (slide index) holds each line, so a
               round's extra slide can be inserted right after the
               component its FIRST subject line belongs to. */
            const compLineIdSets = Array.from(comps).map((comp) =>
                new Set(Array.from(comp.querySelectorAll('[data-line-id]')).map((el) => parseInt(el.dataset.lineId, 10)))
            );

            const insertAfter = {};   // component slide index -> list of round slides
            roundsData.forEach((round) => {
                if (!round || !Array.isArray(round.lineIds) || round.lineIds.length === 0
                    || !Array.isArray(round.voices) || round.voices.length === 0) {
                    return;   // malformed/empty — never render a round that points at nothing
                }
                const firstLineId = round.lineIds[0];
                const compIdx = compLineIdSets.findIndex((set) => set.has(firstLineId));
                if (compIdx === -1) { return; }   // this round's own lines aren't on the rendered page — degrade silently

                /* `.page-song[data-voice-rounds]` is deliberately sparse
                   today (see this file's own header) — infer entryBasis
                   from whichever entry offset the render commit actually
                   sent, and leave every field roundTimeline() itself
                   defaults (timesThrough, bpm, beatsPerLine, entryBeats) as
                   absent so its OWN documented defaults apply, exactly as
                   they would on the server. */
                const voicesForTimeline = round.voices.map((v) => ({
                    number: v.number,
                    entryBasis: (v.entryMs !== null && v.entryMs !== undefined) ? 'ms' : 'lines',
                    entryLines: v.entryLines || 0,
                    entryBeats: null,
                    entryMs: (v.entryMs !== null && v.entryMs !== undefined) ? v.entryMs : null,
                    timesThrough: null,
                }));
                const timeline = roundTimeline(
                    { timesThrough: null, endingMode: round.endingMode, bpm: null, beatsPerLine: null, codaLineIds: round.codaLineIds || [] },
                    voicesForTimeline,
                    round.lineIds,
                    {},
                    {}
                );
                if (timeline.steps.length === 0) { return; }

                const roundSlide = { kind: 'round', round, timeline, step: 0 };
                (insertAfter[compIdx] = insertAfter[compIdx] || []).push(roundSlide);
            });

            if (Object.keys(insertAfter).length > 0) {
                const withRounds = [];
                slides.forEach((s, i) => {
                    withRounds.push(s);
                    (insertAfter[i] || []).forEach((rs) => withRounds.push(rs));
                });
                slides.length = 0;
                slides.push(...withRounds);
            }
        }

        let current = 0;
        /* The one live timer this module ever starts (a round's Play
           button). Always cleared before it is replaced and, per rule #32's
           sibling, unconditionally cleared the instant the overlay closes —
           see close()'s first statement below. */
        let roundPlayer = null;

        /* The dialog's accessible name. Read from the page heading — the same
           source router.js uses for its route announcement — so a screen reader
           says "Presenting Amazing Grace" rather than an anonymous "dialog". */
        const songTitle = (document.querySelector('#page-content h1')?.textContent || '').trim();

        /* Remember what to give focus back to on close. Restoring focus is not
           optional politeness: without it, closing the overlay drops focus to
           <body> and the user's next Tab starts from the top of the document,
           losing their place entirely (WCAG 2.4.3). */
        const previouslyFocused = document.activeElement;

        /* Create overlay.
         *
         * ELI5: tell the browser this is a proper pop-up window that covers the
         * page, not just a big coloured box drawn on top of it.
         *
         * Detail (#1646 item 1, WCAG 4.1.2 + 2.4.3): this was a plain <div>.
         * Visually it covered everything; to assistive tech and to the Tab key
         * it was simply more page content. A keyboard user opening Present and
         * pressing Tab landed on the toolbar and footer links BEHIND the
         * overlay — controls they could not see, while the audience saw only
         * lyrics. A screen-reader user got no signal that anything had opened.
         *
         * role="dialog" + aria-modal="true" declares it. tabindex="-1" makes it
         * programmatically focusable so focus can be moved INTO it on open (a
         * modal that never receives focus is still one Tab away from the page
         * behind it). The name comes from the song, so the announcement is
         * "Presenting Amazing Grace" rather than an anonymous "dialog".
         * https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/ */
        const overlay = document.createElement('div');
        overlay.className = 'presentation-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', `Presenting ${songTitle || 'song'}`);
        overlay.tabIndex = -1;
        overlay.innerHTML = `
            <button class="present-close" aria-label="Close presentation">&times;</button>
            <div class="present-label"></div>
            <div class="present-lyrics"></div>
            <div class="present-nav">
                <button class="present-prev" aria-label="Previous"><i class="fa-solid fa-chevron-left me-1"></i>Prev</button>
                <span class="present-counter"></span>
                <button class="present-next" aria-label="Next">Next<i class="fa-solid fa-chevron-right ms-1"></i></button>
            </div>
        `;

        const labelEl = overlay.querySelector('.present-label');
        const lyricsEl = overlay.querySelector('.present-lyrics');
        const counterEl = overlay.querySelector('.present-counter');
        const prevBtn = overlay.querySelector('.present-prev');
        const nextBtn = overlay.querySelector('.present-next');

        function render(announceSlide = false) {
            const slide = slides[current];
            counterEl.textContent = (current + 1) + ' / ' + slides.length;
            prevBtn.disabled = current === 0;
            nextBtn.disabled = current === slides.length - 1;

            /* #2073 — a round gets its own slide kind: a split-panel grid
               (one column per voice, its own current line) instead of the
               plain label+text a component slide shows. See renderRoundSlide()
               below; everything else about this dialog (nav, counter, focus
               trap, announce) is unchanged either way. */
            if (slide.kind === 'round') {
                labelEl.textContent = slide.round.label || roundKindLabel(slide.round.kind);
                renderRoundSlide(slide);
                if (announceSlide) {
                    announce(`${labelEl.textContent}, ${current + 1} of ${slides.length}. ${roundStepAnnounceText(slide)}`);
                }
                return;
            }

            clearRoundStyleOverride();
            labelEl.textContent = slide.label;
            lyricsEl.textContent = slide.text;

            /* Announce slide changes (#1646 item 1, WCAG 4.1.3).
               ELI5: say which verse we just moved to, because the screen
               doesn't say it out loud by itself.
               Detail: this swaps textContent on plain elements, which is
               invisible to assistive tech — a screen-reader user pressing
               Next got total silence and no way to know whether anything had
               happened. Announced only on CHANGES, not on the first render:
               the dialog's own accessible name already covers opening, and
               announcing both would say the song twice. The label is included
               because "2 of 7" alone is not orientation. */
            if (announceSlide) {
                announce(`${slide.label || 'Slide'}, ${current + 1} of ${slides.length}`);
            }
        }

        /* #2073 — every round-slide render helper below reaches into these
           two: `roundPlayer` (declared with `current` above) so Play/Pause
           and the mandatory teardown share ONE handle, and `lyricsEl`
           itself so a round's grid can replace the plain lyrics text and a
           later normal slide can cleanly put it back (clearRoundStyleOverride()). */

        function clearRoundStyleOverride() {
            lyricsEl.removeAttribute('style');
        }

        function stopRoundPlayback() {
            if (roundPlayer) { roundPlayer.stop(); roundPlayer = null; }
        }

        function roundKindLabel(kind) {
            return ({ round: 'Round', canon: 'Canon', 'partner-song': 'Partner song' })[kind] || 'Round';
        }

        function roundVoiceLabel(round, num) {
            const v = (round.voices || []).find((vv) => vv.number === num);
            return (v && v.label) ? v.label : ('Voice ' + num);
        }

        /** `line` is an index into `round.lineIds ++ round.codaLineIds` — see
         *  roundTimeline()'s own doc-comment. `-1` waiting, `-2` finished. */
        function roundLineText(slide, line) {
            if (line === -1) { return '…'; }
            if (line === -2) { return '(end)'; }
            const allLineIds = slide.round.lineIds.concat(slide.round.codaLineIds || []);
            const lid = allLineIds[line];
            return (lid !== undefined && lineTextById[lid]) ? lineTextById[lid] : '';
        }

        function roundStepAnnounceText(slide) {
            const total = slide.timeline.steps.length;
            if (total === 0) { return 'No steps.'; }
            const stepIdx = Math.max(0, Math.min(slide.step, total - 1));
            const stepData = slide.timeline.steps[stepIdx];
            const parts = stepData.voices.map((v) => {
                const label = roundVoiceLabel(slide.round, v.n);
                if (v.line === -1) { return label + ' waiting'; }
                if (v.line === -2) { return label + ' finished'; }
                return label + ': ' + roundLineText(slide, v.line);
            });
            return 'Step ' + (stepIdx + 1) + ' of ' + total + '. ' + parts.join('. ');
        }

        /**
         * Draw one round slide's split-panel grid + its step controls into
         * `.present-lyrics`. Rebuilt on every call (small DOM, no leak risk —
         * the one thing that WOULD leak, the auto-advance timer, is owned by
         * `roundPlayer`/`stopRoundPlayback()` above, never by anything here).
         *
         * No colour-only cue (WCAG 1.4.1): each voice gets its own NUMERAL
         * *and* its own border style (solid/dashed/dotted/double), so the
         * distinction survives a black-and-white print or a colour-blind
         * viewer, not just a themed screen.
         */
        function renderRoundSlide(slide) {
            clearRoundStyleOverride();
            lyricsEl.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:0.9rem;overflow:auto;max-height:100%;white-space:normal;';
            lyricsEl.textContent = '';

            const timeline = slide.timeline;
            const total = timeline.steps.length;
            const stepIdx = Math.max(0, Math.min(slide.step, total - 1));
            slide.step = stepIdx;
            const stepData = total > 0 ? timeline.steps[stepIdx] : { voices: [] };

            const grid = document.createElement('div');
            grid.className = 'present-round-grid';
            grid.style.cssText = 'display:flex;flex-wrap:wrap;justify-content:center;gap:1rem;width:100%;';

            const borderStyles = ['solid', 'dashed', 'dotted', 'double'];
            stepData.voices.forEach((v, i) => {
                const col = document.createElement('div');
                col.setAttribute('role', 'group');
                const label = roundVoiceLabel(slide.round, v.n);
                const statusWord = v.line === -1 ? 'waiting' : (v.line === -2 ? 'finished' : 'singing');
                col.setAttribute('aria-label', label + ', ' + statusWord + (v.line >= 0 ? ': ' + roundLineText(slide, v.line) : ''));
                col.style.cssText = 'flex:1 1 220px;max-width:340px;padding:0.85rem 1.1rem;border-radius:0.6rem;'
                    + 'border:3px ' + borderStyles[i % borderStyles.length] + ' rgba(255,255,255,0.75);text-align:center;';
                const head = document.createElement('div');
                head.style.cssText = 'font-weight:700;letter-spacing:0.02em;opacity:0.85;margin-bottom:0.4rem;';
                head.textContent = v.n + '. ' + label;
                const body = document.createElement('div');
                body.style.cssText = 'font-size:1.35rem;line-height:1.45;min-height:2.4em;';
                body.textContent = roundLineText(slide, v.line);
                col.appendChild(head);
                col.appendChild(body);
                grid.appendChild(col);
            });
            lyricsEl.appendChild(grid);

            const controls = document.createElement('div');
            controls.className = 'present-round-controls';
            controls.style.cssText = 'display:flex;align-items:center;gap:0.75rem;margin-top:0.25rem;flex-wrap:wrap;justify-content:center;';

            /* Playback needs a real clock to advance against — a round with
               no line-length timing (the common case today, see this
               file's header) has nothing sensible to auto-play, so the
               button is disabled rather than pretending to play on a
               guessed interval. Stepping manually always works. */
            const canPlay = total > 1 && timeline.basis !== 'lines';

            const prevStepBtn = document.createElement('button');
            prevStepBtn.type = 'button';
            prevStepBtn.className = 'btn btn-outline-light btn-sm';
            prevStepBtn.textContent = '◀ Step';
            prevStepBtn.setAttribute('aria-label', 'Previous round step');
            prevStepBtn.disabled = stepIdx === 0;
            prevStepBtn.addEventListener('click', (e) => { e.stopPropagation(); stepRound(slide, -1); });

            const playBtn = document.createElement('button');
            playBtn.type = 'button';
            const isPlaying = !!roundPlayer;
            playBtn.className = 'btn btn-sm ' + (isPlaying ? 'btn-amber-solid' : 'btn-outline-light');
            playBtn.textContent = isPlaying ? 'Pause' : 'Play';
            playBtn.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
            if (!canPlay) {
                playBtn.disabled = true;
                playBtn.title = 'This round has no line-length timing yet — step through it manually.';
            }
            playBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleRoundPlay(slide); });

            const nextStepBtn = document.createElement('button');
            nextStepBtn.type = 'button';
            nextStepBtn.className = 'btn btn-outline-light btn-sm';
            nextStepBtn.textContent = 'Step ▶';
            nextStepBtn.setAttribute('aria-label', 'Next round step');
            nextStepBtn.disabled = stepIdx >= total - 1;
            nextStepBtn.addEventListener('click', (e) => { e.stopPropagation(); stepRound(slide, 1); });

            const counter = document.createElement('span');
            counter.className = 'small';
            counter.textContent = total > 0 ? ('Step ' + (stepIdx + 1) + ' of ' + total) : 'No steps';

            controls.appendChild(prevStepBtn);
            controls.appendChild(playBtn);
            controls.appendChild(nextStepBtn);
            controls.appendChild(counter);
            lyricsEl.appendChild(controls);
        }

        /** Move a round slide's OWN step (never the presentation's slide
         *  index) — the dedicated round controls / Space+arrow keys use
         *  this; the overlay's own Prev/Next move between SLIDES instead
         *  (see next()/prev() below, which always stop a running round
         *  first). Manual stepping also pauses auto-play — a curator
         *  deliberately moving the round is a "seek", not a race with the
         *  clock. */
        function stepRound(slide, delta) {
            const total = slide.timeline.steps.length;
            if (total === 0) { return; }
            stopRoundPlayback();
            const nextStep = Math.max(0, Math.min(total - 1, slide.step + delta));
            if (nextStep === slide.step) { renderRoundSlide(slide); return; }
            slide.step = nextStep;
            renderRoundSlide(slide);
            announce(roundStepAnnounceText(slide));
        }

        function toggleRoundPlay(slide) {
            if (roundPlayer) {
                stopRoundPlayback();
                renderRoundSlide(slide);
                return;
            }
            const steps = slide.timeline.steps;
            if (slide.timeline.basis === 'lines' || steps.length < 2) { return; }
            if (slide.step >= steps.length - 1) { slide.step = 0; }
            roundPlayer = createRoundAutoAdvance(steps, {
                onStep: (i) => {
                    slide.step = i;
                    renderRoundSlide(slide);
                    announce(roundStepAnnounceText(slide));
                    if (i >= steps.length - 1) { stopRoundPlayback(); renderRoundSlide(slide); }
                },
            });
            roundPlayer.start(slide.step);
            renderRoundSlide(slide);
        }

        function close() {
            /* Rule #32's sibling (.claude/CLAUDE.md) — any timer fixed to
               this overlay MUST be cleared the instant it closes, as the
               very first action, before any early return. This dialog has
               exactly one such timer: a playing round's auto-advance clock.
               tests/test-present-round-projector.js proves this line is
               present and first. */
            stopRoundPlayback();
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
            /* ELI5: stop listening for key presses once the overlay is gone,
               the same way you'd stop watching a door once it's shut.
               Detail (#1568 bug fix): this used to rely on
               `overlay.addEventListener('remove', …)` — 'remove' is not a
               real DOM event (Element has no such event; see the Element
               event reference below), so that handler never fired and every
               close via the × button left the document-level `keydown`
               listener attached forever, leaking one listener per
               presentation opened. Removing it explicitly here, on every
               close path, is the fix.
               https://developer.mozilla.org/docs/Web/API/Element#events */
            document.removeEventListener('keydown', onKey);
            /* Un-hide the page before removing the overlay, so the document is
               never left in a state where everything is inert. */
            setBackgroundInert(false);
            overlay.remove();
            /* Give focus back to whatever opened this (#1646 item 1, WCAG
               2.4.3). Without it focus falls to <body> and the user's next Tab
               restarts from the top of the document, losing their place. */
            if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                previouslyFocused.focus({ preventScroll: true });
            }
        }

        /**
         * Hide (or restore) everything behind the overlay from assistive tech
         * and from the Tab order.
         *
         * ELI5: while the pop-up is open, everything underneath it is switched
         * off so you cannot accidentally tab into it.
         *
         * Detail: `aria-modal="true"` tells a screen reader to confine itself
         * to the dialog, but it does NOT affect the Tab order — a keyboard user
         * would still tab straight out into the page behind. `inert` is the
         * attribute that does both: it removes the subtree from the tab order
         * AND from the accessibility tree. Applied to the overlay's SIBLINGS
         * rather than to <body>, because inerting an ancestor of the overlay
         * would disable the overlay too.
         * https://developer.mozilla.org/en-US/docs/Web/API/HTMLElement/inert
         *
         * @param {boolean} on
         */
        function setBackgroundInert(on) {
            Array.from(document.body.children).forEach(el => {
                if (el === overlay) return;
                if (on) { el.inert = true; } else { el.inert = false; }
            });
        }

        /* Both stop a running round FIRST — a round's clock belongs to the
           slide it is on; leaving it running while a different slide is on
           screen would be the exact "timer that outlives what it belongs
           to" bug rule #32's sibling warns about, just scoped to a slide
           change rather than the whole overlay closing. */
        function next() { stopRoundPlayback(); if (current < slides.length - 1) { current++; render(true); } }
        function prev() { stopRoundPlayback(); if (current > 0) { current--; render(true); } }

        /* Navigation events */
        overlay.querySelector('.present-close').addEventListener('click', close);
        prevBtn.addEventListener('click', (e) => { e.stopPropagation(); prev(); });
        nextBtn.addEventListener('click', (e) => { e.stopPropagation(); next(); });
        /* a11y audit m5 — .present-counter is a <span>, not a <button>: this
           listener only stops a tap on the "N / M" readout from ALSO
           triggering the lyrics-area advance behind it (line ~190). It never
           had a keyboard interaction of its own, so — unlike prev/next — it
           was a focusable control with nothing to operate; slide changes are
           already announced via render()'s announce() call above. */
        counterEl.addEventListener('click', (e) => e.stopPropagation());

        /* Click on lyrics area advances */
        lyricsEl.addEventListener('click', next);

        /* Keyboard navigation. Escape now routes through close() (rather
           than removing the listener inline) so there is exactly ONE place
           that tears down this handler — see the #1568 fix note in close(). */
        function onKey(e) {
            const slide = slides[current];
            /* #2073 — on a round slide, Space/←/→ drive the ROUND's own
               step instead of the presentation's slide index (design pass
               7 §9: "Space toggles play, arrow keys step"). PageUp/PageDown
               are deliberately left mapped to ordinary slide navigation
               below regardless of slide kind — the foot-pedal escape hatch
               (#1267) always jumps past a round rather than requiring the
               operator to first find its dedicated controls. */
            if (e.key === 'Escape') { close(); }
            else if (slide && slide.kind === 'round' && e.key === ' ') { e.preventDefault(); toggleRoundPlay(slide); }
            else if (slide && slide.kind === 'round' && e.key === 'ArrowRight') { e.preventDefault(); stepRound(slide, 1); }
            else if (slide && slide.kind === 'round' && e.key === 'ArrowLeft') { e.preventDefault(); stepRound(slide, -1); }
            else if (e.key === 'ArrowRight' || e.key === ' ') { e.preventDefault(); next(); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
            /* #1267 — the same PageDown/PageUp a Bluetooth foot pedal
               emits as real keystrokes, so a pedal advances slides here
               exactly as it advances lyric sections on the plain song
               page (app.js's own PageDown/PageUp case). */
            else if (e.key === 'PageDown') { e.preventDefault(); next(); }
            else if (e.key === 'PageUp') { e.preventDefault(); prev(); }
            else if (e.key === 'Tab') { trapTab(e); }
        }

        /**
         * Keep Tab inside the dialog (#1646 item 1, WCAG 2.4.3 / 2.1.2).
         *
         * ELI5: pressing Tab at the last button jumps back to the first, so you
         * can never tab your way out of the pop-up by accident.
         *
         * Detail: `inert` on the siblings already removes the page behind from
         * the tab order, so this is the second layer — it handles the wrap at
         * each end, which inert alone does not do (without it, Tab past the
         * last control moves focus to the browser chrome and the user has to
         * find their way back in). Belt and braces is right here: `inert` is
         * the newer of the two mechanisms, and losing focus out of a fullscreen
         * presentation mid-service is not a graceful degradation.
         *
         * @param {KeyboardEvent} e
         */
        function trapTab(e) {
            const focusables = Array.from(
                overlay.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
            ).filter(el => !el.disabled && el.offsetParent !== null);

            /* Every control can legitimately be disabled at once — first slide
               of a one-slide song disables both Prev and Next. Keep focus on
               the dialog itself rather than letting Tab escape. */
            if (focusables.length === 0) { e.preventDefault(); overlay.focus(); return; }

            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            const active = document.activeElement;

            if (e.shiftKey && (active === first || active === overlay)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && active === last) {
                e.preventDefault();
                first.focus();
            }
        }
        document.addEventListener('keydown', onKey);

        /* Touch swipe support.
           ELI5: a plain "tap" also fires touchstart+touchend a few pixels
           apart from finger jitter — 50px is chosen as comfortably above
           that noise floor but well below a real intentional swipe, so taps
           don't accidentally page forward/back.
           Detail: no formal spec value here — 50 CSS px is a common rule-
           of-thumb swipe threshold (roughly a fingertip's width) balancing
           false-positive taps against requiring an uncomfortably large drag. */
        let touchStartX = 0;
        overlay.addEventListener('touchstart', (e) => { touchStartX = e.changedTouches[0].screenX; }, { passive: true });
        overlay.addEventListener('touchend', (e) => {
            const diff = e.changedTouches[0].screenX - touchStartX;
            if (Math.abs(diff) > 50) {
                if (diff < 0) next(); else prev();
            }
        }, { passive: true });

        render();
        document.body.appendChild(overlay);

        /* Order matters: append, THEN inert the siblings (the overlay must
           already be a child so it can be excluded), THEN move focus in.
           Focusing before inerting would move focus into an element that is
           about to have its siblings disabled — harmless, but the reverse
           order (inert first) can blur the active element and leave focus on
           <body>, which is the state this is trying to avoid. */
        setBackgroundInert(true);
        overlay.focus({ preventScroll: true });

        /* Enter fullscreen if available.
           ELI5: ask the browser to hide everything else (address bar, tabs)
           so the lyrics fill the whole screen, like a real projector.
           Detail: requestFullscreen() is user-gesture-gated and can reject
           (e.g. unsupported browser, an iframe without the `allowfullscreen`
           attribute, or a user/OS policy denial) — caught here as a no-op
           since the overlay is still fully usable windowed, just without
           the fullscreen chrome-hiding.
           https://developer.mozilla.org/docs/Web/API/Element/requestFullscreen */
        if (overlay.requestFullscreen) {
            overlay.requestFullscreen().catch(() => {});
        }
    });
}
