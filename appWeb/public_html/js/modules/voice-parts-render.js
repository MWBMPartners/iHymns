/**
 * iHymns — Voice-part / echo render helpers, JavaScript twin (#2073 commit 8)
 *
 * ELI5: the browser-side half of "who sings this line" rendering. Every
 * function here does the EXACT same job as its PHP namesake in
 * `includes/voice_parts_render.php` — turn the sparse `voices` /
 * `voiceSpans` a song's components already carry into the actual HTML a
 * chip badge, an echoed word, or a coloured run wrapper needs. Two of these
 * exist (one PHP, one JS) because `includes/pages/song.php` renders on the
 * server, while `js/modules/setlist.js` (the arrangement preview + custom-
 * arrangement playback re-render) and `js/modules/print.js` (browser Print
 * + the server PDF's HTML source) re-render a component's lines from the
 * JSON `song_detail` payload IN THE BROWSER — there is no way to hand that
 * job to the PHP side without a network round trip nobody wants.
 *
 * WHY THE TWO MUST STAY BYTE-IDENTICAL, AND HOW THAT IS PROVEN (rule #35 —
 * "a comment saying keep these in sync is the failure, not the fix"):
 * `tests/test-voice-render-lockstep.js` feeds BOTH files the exact same
 * `tests/fixtures/voice-render-cases.json` and asserts the two answers are
 * byte-for-byte the same string, the same way
 * `tests/test-org-logo-resolver-lockstep.js` already does for the org-logo
 * themed resolver. If a future change edits the wording, a class name, or
 * the escaping rules on ONE side only, that test goes red — it does not
 * matter that "it still looks right" in whichever half got tested by hand.
 *
 * 🔴 THE SINGLE MOST IMPORTANT RULE THIS FILE ENFORCES — a voice chip is a
 * SIBLING of `<p class="lyric-line">` (or `<div class="print-line">`),
 * inside a wrapping element, NEVER a descendant of the line element itself.
 * `renderComponentLinesHtml()` below only ever opens/closes the wrapper
 * around a sequence of line elements — it never nests a chip `<span>`
 * inside a line's own tag. See `includes/voice_parts_render.php`'s file
 * header for the full "why" (five OTHER modules read a `.lyric-line`'s
 * `textContent` and would otherwise silently glue "Women" onto the front of
 * every projected slide / share snippet / highlight lookup — corruption,
 * not a missing feature, and invisible until someone notices the wrong
 * words on a screen).
 *
 * DELIBERATE SCOPE LIMITS (stated here, not silently skipped):
 *   - No `collectSlideModel()` / `parseRoundsAttr()` in this file. Design-
 *     pass-5 placed the round-projector's DOM→model reader here, but
 *     `js/modules/present-mode.js` — the ONLY thing that would call it — is
 *     explicitly a separate commit's file. Shipping an unused, unexercised
 *     DOM-scraping function with no caller and no test would itself be the
 *     kind of "wrong-but-green" code rule #34 warns against; the present-
 *     mode commit is the right place to add it alongside its first real
 *     caller.
 *   - `voiceRunsByLineIndex()` below takes ONE argument (`comp`), mirroring
 *     `ihymnsVoiceRunsByLineIndex()`'s actual PHP signature exactly — not
 *     the wider `(comp, assignmentsByLineId)` editor-shape overload Design-
 *     pass-5 sketched for `manage/editor/v2/preview-tab.js`, which is out
 *     of this commit's assigned files. Adding a second parameter nothing
 *     calls (and the lockstep test cannot exercise) is not "future-
 *     proofing" — it is untested surface area.
 *
 * @see appWeb/public_html/includes/voice_parts_render.php   the PHP original
 * @see tests/fixtures/voice-render-cases.json                the shared lockstep fixture
 * @see tests/test-voice-render-lockstep.js                   the PHP<->JS proof
 */

import { escapeHtml } from '../utils/html.js';

/* ---------------------------------------------------------------------
 * SMALL PURE HELPERS
 * ------------------------------------------------------------------ */

/**
 * ELI5: join names the way a person says them out loud — "Alice", "Alice
 * and Bob", "Alice, Bob and Carol" (no Oxford comma) — the exact twin of
 * `_ihymnsVoiceSerialJoin()` in the PHP file.
 * @param {string[]} labels
 * @returns {string}
 */
function serialJoin(labels) {
    const list = labels.filter((l) => typeof l === 'string' && l.trim() !== '');
    const n = list.length;
    if (n === 0) return '';
    if (n === 1) return list[0];
    if (n === 2) return `${list[0]} and ${list[1]}`;
    const last = list[n - 1];
    return `${list.slice(0, n - 1).join(', ')} and ${last}`;
}

/**
 * ELI5: "Round" / "Canon" / "Partner song" — the twin of
 * `_ihymnsVoiceRoundKindLabel()`.
 * @param {string} kind
 * @returns {string}
 */
function roundKindLabel(kind) {
    if (kind === 'canon') return 'Canon';
    if (kind === 'partner-song') return 'Partner song';
    return 'Round';
}

/* ---------------------------------------------------------------------
 * VOICE RUNS + SPANS
 * ------------------------------------------------------------------ */

/**
 * ELI5: "for this line index, who is singing it as part of a group, and
 * does the group's rule/chip row start, end, or carry on here?" — the twin
 * of `ihymnsVoiceRunsByLineIndex()`.
 *
 * @param {{voices?: Array<{from:number,to:number,parts:Array<{id:number,kind:string,label:string,bg:boolean,enters?:boolean}>}>}} comp
 * @returns {Object<number,{start:boolean,end:boolean,parts:Array,allBg:boolean}>}
 */
export function voiceRunsByLineIndex(comp) {
    const out = {};
    const runs = Array.isArray(comp && comp.voices) ? comp.voices : null;
    if (!runs) return out;
    runs.forEach((run) => {
        const from = (run.from | 0);
        const to = (typeof run.to === 'number' ? run.to : from) | 0;
        const parts = Array.isArray(run.parts) ? run.parts : [];
        let allBg = parts.length > 0;
        for (const p of parts) {
            if (!p.bg) { allBg = false; break; }
        }
        for (let i = from; i <= to; i++) {
            out[i] = { start: i === from, end: i === to, parts, allBg };
        }
    });
    return out;
}

/**
 * ELI5: "does part of this line's text belong to a different voice, or get
 * echoed?" — the twin of `ihymnsVoiceSpansByLineIndex()`. Groups a
 * component's flat `voiceSpans` list by line index, sorts by start, and
 * DROPS an overlapping span (logged via `console.warn`, mirroring the PHP
 * side's `error_log()`) so malformed HTML is never produced from a curator
 * mistake or a stale span left behind after a line edit.
 *
 * @param {{voiceSpans?: Array<{line:number,start:number,end:number,part:{id:number,kind:string,label:string,bg:boolean}}>}} comp
 * @returns {Object<number, Array>}
 */
export function voiceSpansByLineIndex(comp) {
    const spans = Array.isArray(comp && comp.voiceSpans) ? comp.voiceSpans : [];
    if (spans.length === 0) return {};

    const byLine = {};
    spans.forEach((s) => {
        if (!s || typeof s.line !== 'number') return;
        (byLine[s.line] = byLine[s.line] || []).push(s);
    });

    const out = {};
    Object.keys(byLine).forEach((lineKey) => {
        const line = Number(lineKey);
        const list = byLine[lineKey].slice().sort((a, b) => (a.start | 0) - (b.start | 0));
        const kept = [];
        let cursor = 0;
        list.forEach((s) => {
            const start = s.start | 0;
            const end = s.end | 0;
            if (end <= start) {
                console.warn(`[voice-parts-render] dropped a zero/negative-length voice span on line ${line} (start=${start}, end=${end})`);
                return;
            }
            if (start < cursor) {
                console.warn(`[voice-parts-render] dropped an OVERLAPPING voice span on line ${line} (start=${start} is before the previous span's end=${cursor})`);
                return;
            }
            kept.push(s);
            cursor = end;
        });
        if (kept.length > 0) out[line] = kept;
    });
    return out;
}

/* ---------------------------------------------------------------------
 * HTML BUILDERS
 * ------------------------------------------------------------------ */

/**
 * ELI5: the words announced for a run — "Women", "Women and Men", "Women,
 * echoed by Backing", "Echo, sung by Backing". The twin of
 * `ihymnsVoiceRunAriaLabel()`.
 * @param {Array<{label:string,bg?:boolean}>} parts
 * @returns {string}
 */
export function voiceRunAriaLabel(parts) {
    const lead = [];
    const bg = [];
    (parts || []).forEach((p) => {
        const label = String(p.label || '').trim();
        if (label === '') return;
        (p.bg ? bg : lead).push(label);
    });
    if (lead.length === 0 && bg.length === 0) return '';
    if (lead.length === 0) return `Echo, sung by ${serialJoin(bg)}`;
    if (bg.length === 0) return serialJoin(lead);
    return `${serialJoin(lead)}, echoed by ${serialJoin(bg)}`;
}

/**
 * ELI5: the small pill badges above a run — one per singer. The twin of
 * `ihymnsVoiceChipsHtml()`; see that function's doc-block for why a
 * background chip always reads "{label} (echo)" rather than a vocabulary-
 * dependent generic "Echo" (the JS side has no access to the PHP-only
 * `IHYMNS_VOCAL_PART_KINDS` map, so both sides use the simpler rule that
 * needs no shared vocabulary at all).
 * @param {Array<{kind?:string,label:string,bg?:boolean}>} parts
 * @param {{chipClass?:string,rowClass?:string,a11y?:boolean,dataAttrs?:boolean}} [opts]
 * @returns {string}
 */
export function voiceChipsHtml(parts, opts = {}) {
    const o = { chipClass: 'lyric-voice-chip', rowClass: 'lyric-voice-chips', a11y: true, dataAttrs: true, ...opts };
    if (!Array.isArray(parts) || parts.length === 0) return '';

    let chips = '';
    parts.forEach((p) => {
        const isBg = !!p.bg;
        const label = String(p.label || '').trim();
        const text = isBg ? `${label} (echo)`.trim() : label;
        const cls = isBg ? `${o.chipClass} ${o.chipClass}--bg` : o.chipClass;
        const dataAttr = o.dataAttrs ? ` data-voice-kind="${escapeHtml(String(p.kind || ''))}"` : '';
        const icon = isBg ? '<i class="fa-solid fa-reply fa-flip-horizontal" aria-hidden="true"></i>' : '';
        chips += `<span class="${escapeHtml(cls)}"${dataAttr}>${icon}${escapeHtml(text)}</span>`;
    });

    const rowAttrs = o.a11y ? ' aria-hidden="true"' : '';
    return `<span class="${escapeHtml(o.rowClass)}"${rowAttrs}>${chips}</span>`;
}

/**
 * ELI5: the escaped, span-aware HTML for one line's text. The twin of
 * `ihymnsVoiceLineHtml()`. Offsets are 0-based UTF-8 CODE POINTS (rule #21
 * of .claude/CLAUDE.md), sliced with `Array.from(text)` (which iterates by
 * Unicode code point, unlike a plain string index which counts UTF-16
 * code UNITS and would cut a surrogate pair in half) — never
 * `text.slice()`/`text.substring()` on the raw JS string.
 * https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array/from#array_from_iterable_objects
 *
 * @param {string} text
 * @param {Array<{start:number,end:number,part:{id:number,kind:string,label:string,bg:boolean}}>} spans
 * @param {{spanClass?:string,a11y?:boolean,dataAttrs?:boolean}} [opts]
 * @returns {string}
 */
export function voiceLineHtml(text, spans, opts = {}) {
    const o = { spanClass: 'lyric-voice-span', a11y: true, dataAttrs: true, ...opts };
    if (!Array.isArray(spans) || spans.length === 0) {
        return escapeHtml(text);
    }

    const chars = Array.from(String(text));
    const totalLen = chars.length;
    const ordered = spans.slice().sort((a, b) => (a.start | 0) - (b.start | 0));

    let html = '';
    let cursor = 0;
    ordered.forEach((span) => {
        const start = Math.max(0, span.start | 0);
        const end = Math.min(totalLen, span.end | 0);
        if (end <= start || start < cursor) {
            console.warn(`[voice-parts-render] voiceLineHtml() dropped an out-of-range/overlapping span (start=${start}, end=${end}, cursor=${cursor})`);
            return;
        }
        if (start > cursor) {
            html += escapeHtml(chars.slice(cursor, start).join(''));
        }

        const part = span.part || {};
        const isBg = !!part.bg;
        const label = String(part.label || '').trim();
        const cls = isBg ? `${o.spanClass} ${o.spanClass}--bg` : o.spanClass;
        const roleDescription = isBg ? 'Echo' : (label !== '' ? `${label} part` : 'Voice part');

        let attrs = '';
        if (o.a11y) {
            attrs += ` role="group" aria-roledescription="${escapeHtml(roleDescription)}"`;
        }
        if (o.dataAttrs) {
            attrs += ` data-voice-part="${part.id | 0}"`
                + ` data-voice-kind="${escapeHtml(String(part.kind || ''))}"`
                + ` data-voice-bg="${isBg ? '1' : '0'}"`
                + ` data-voice-start="${start}"`
                + ` data-voice-end="${end}"`;
        }

        const segment = escapeHtml(chars.slice(start, end).join(''));
        html += `<span class="${escapeHtml(cls)}"${attrs}>${segment}</span>`;
        cursor = end;
    });
    if (cursor < totalLen) {
        html += escapeHtml(chars.slice(cursor).join(''));
    }
    return html;
}

/**
 * ELI5: the opening `<div>` tag for a run wrapper. The twin of
 * `ihymnsVoiceRunOpenTag()` — the caller writes the chip row and each
 * line's own element as ordinary children, then a plain `</div>` once the
 * run's last line is done.
 * @param {Array<{id:number,kind:string,label:string,bg?:boolean}>} parts
 * @param {{runClass?:string,a11y?:boolean,dataAttrs?:boolean}} [opts]
 * @returns {string}
 */
export function voiceRunOpenTag(parts, opts = {}) {
    const o = { runClass: 'lyric-voice-run', a11y: true, dataAttrs: true, ...opts };
    const list = Array.isArray(parts) ? parts : [];

    let allBg = list.length > 0;
    for (const p of list) {
        if (!p.bg) { allBg = false; break; }
    }
    const cls = allBg ? `${o.runClass} ${o.runClass}--bg` : o.runClass;

    let attrs = '';
    if (o.a11y) {
        attrs += ` role="group" aria-label="${escapeHtml(voiceRunAriaLabel(list))}"`;
    }
    if (o.dataAttrs) {
        const slim = list.map((p) => ({ id: p.id | 0, kind: String(p.kind || ''), label: String(p.label || ''), bg: !!p.bg }));
        attrs += ` data-voice-parts="${escapeHtml(JSON.stringify(slim))}"`;
    }
    return `<div class="${escapeHtml(cls)}"${attrs}>`;
}

/**
 * ELI5: render a WHOLE component's lines (the run wrappers, the chip rows,
 * every `<p>`/`<div>` line, its sub-line echo spans) as one HTML string —
 * the composite convenience `js/modules/setlist.js`'s two JSON re-render
 * sites use (they had a simple `lines.map(l => '<p class="lyric-line
 * mb-1">'+esc(l)+'</p>').join('')` template with no per-line chords or
 * translations to interleave, so replacing the WHOLE `.lyric-lines` body in
 * one call is the natural fit).
 *
 * `js/modules/print.js` does NOT use this function — its existing
 * `renderLyrics()` interleaves a per-line CHORD row inside its own loop and
 * uses `<div class="print-line">` rather than `<p>`, so it calls the
 * smaller primitives above directly instead of forcing them through one
 * generic shape. Both paths share the SAME underlying pieces (run
 * detection, chip HTML, span-aware line HTML) — only the OUTER assembly
 * differs, because the two call sites' existing DOM shapes genuinely
 * differ. Forcing one composite function to cover every layout would make
 * it, not the sites, the source of complexity.
 *
 * @param {{lines?:string[], lineIds?:number[], voices?:Array, voiceSpans?:Array}} comp
 * @param {{lineTag?:string,lineClass?:string,lineBgClass?:string,runClass?:string,chipClass?:string,rowClass?:string,spanClass?:string,a11y?:boolean,dataAttrs?:boolean,showVoices?:boolean,lineIds?:boolean}} [opts]
 * @returns {string} the INNER html for a `.lyric-lines` container — the
 *   caller keeps wrapping it in that element itself.
 */
export function renderComponentLinesHtml(comp, opts = {}) {
    const o = {
        lineTag: 'p',
        lineClass: 'lyric-line mb-1',
        lineBgClass: 'lyric-line--bg',
        runClass: 'lyric-voice-run',
        chipClass: 'lyric-voice-chip',
        rowClass: 'lyric-voice-chips',
        spanClass: 'lyric-voice-span',
        a11y: true,
        dataAttrs: true,
        showVoices: true,
        lineIds: true,
        ...opts,
    };
    const lines = Array.isArray(comp && comp.lines) ? comp.lines : [];
    const lineIds = Array.isArray(comp && comp.lineIds) ? comp.lineIds : [];
    const runs = o.showVoices ? voiceRunsByLineIndex(comp) : {};
    const spansByLine = o.showVoices ? voiceSpansByLineIndex(comp) : {};

    let html = '';
    lines.forEach((line, i) => {
        const run = runs[i] || null;
        if (run && run.start) {
            html += voiceRunOpenTag(run.parts, o) + voiceChipsHtml(run.parts, o);
        }
        const cls = run && run.allBg ? `${o.lineClass} ${o.lineBgClass}` : o.lineClass;
        const lineId = (o.lineIds && lineIds[i]) ? (lineIds[i] | 0) : 0;
        const idAttr = lineId > 0 ? ` data-line-id="${lineId}"` : '';
        const text = voiceLineHtml(line, spansByLine[i] || [], o);
        html += `<${o.lineTag} class="${escapeHtml(cls)}"${idAttr}>${text}</${o.lineTag}>`;
        if (run && run.end) {
            html += '</div>';
        }
    });
    return html;
}
