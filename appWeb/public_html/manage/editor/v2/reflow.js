/* ==========================================================================
 *  reflow.js — the v2 Paste & Reflow PARSER (#1200, Phase 4)
 *
 *  A pure text→sections parser, extracted VERBATIM from the legacy editor.js
 *  reflow logic (#1043, refined #1180/#1197/#1198) — zero DOM/state deps, so it
 *  ports across cleanly. Takes raw pasted lyrics and splits them into classified
 *  component blocks: blank lines separate sections; a leading "Verse 2" /
 *  "Chorus:" line is detected as a label (only when there's a body after it);
 *  repeated identical blocks are auto-tagged chorus; everything else is a verse;
 *  repeated types are auto-numbered.
 *
 *  reflowParseText(rawText) -> [{ type, number|null, lines:string[] }, ...]
 *  The v2 reflow modal maps each block to a component {type,number,sortOrder,
 *  lines} and persists the whole set atomically via api2.php components_replace.
 * ========================================================================== */

/* Canonical component types reflow normalises into — the superset the legacy
   parser recognised (refrain folds to chorus; there is no distinct 'refrain'
   component type). Self-contained so the parser never depends on a tab's list. */
const REFLOW_TYPES = ['verse', 'chorus', 'bridge', 'pre-chorus', 'tag', 'coda', 'intro', 'outro', 'interlude', 'vamp', 'ad-lib'];

/* A line that is ONLY a section label — e.g. "Chorus", "Verse 2", "Pre-Chorus:",
 * "Bridge 1." — optionally a number and a trailing separator. Never matches a
 * real lyric line (which has more words). */
const REFLOW_LABEL_RE = /^[ \t]*(verses?|chorus(?:es)?|refrains?|bridges?|pre[ \t-]?chorus|tags?|coda|intro|outro|interlude|vamp|ad[ \t-]?lib)\.?[ \t]*(\d+)?[ \t]*[:.)\-–]?[ \t]*$/i;

/* Map a detected label word to a canonical REFLOW_TYPES value. */
function reflowNormalizeType(raw) {
    let t = String(raw || '').toLowerCase().trim().replace(/[ \t]+/g, '-');
    if (t === 'verses') { t = 'verse'; }
    if (t === 'choruses') { t = 'chorus'; }
    if (t === 'bridges') { t = 'bridge'; }
    if (t === 'tags') { t = 'tag'; }
    if (t === 'prechorus') { t = 'pre-chorus'; }
    if (t === 'adlib') { t = 'ad-lib'; }
    if (t === 'refrain' || t === 'refrains') { t = 'chorus'; } /* no distinct 'refrain' type */
    return (REFLOW_TYPES.indexOf(t) !== -1) ? t : 'verse';
}

/* Whitespace-normalised text key, for detecting repeated (chorus) blocks. */
function reflowBlockKey(block) {
    return block.lines.join('\n').toLowerCase().replace(/\s+/g, ' ').trim();
}

/* Auto-number repeated types (Verse 1, Verse 2, …); single-instance types stay
 * unnumbered. Respects a number already set from an explicit label. */
function reflowAutoNumber(blocks) {
    const totals = {};
    blocks.forEach((b) => { totals[b.type] = (totals[b.type] || 0) + 1; });
    const seen = {};
    blocks.forEach((b) => {
        if (totals[b.type] > 1) {
            seen[b.type] = (seen[b.type] || 0) + 1;
            if (b.number == null) { b.number = seen[b.type]; }
        }
    });
}

/* Fill in the types/numbers the paste didn't label explicitly. */
function reflowApplySmartDefaults(blocks) {
    /* Repeated identical blocks are almost always the chorus. */
    const counts = {};
    blocks.forEach((b) => {
        const k = reflowBlockKey(b);
        counts[k] = (counts[k] || 0) + 1;
    });
    blocks.forEach((b) => {
        if (!b.type && counts[reflowBlockKey(b)] > 1) { b.type = 'chorus'; }
    });
    /* Anything still unclassified is a verse. */
    blocks.forEach((b) => { if (!b.type) { b.type = 'verse'; } });
    reflowAutoNumber(blocks);
}

/* Split raw pasted text into classified section blocks. */
export function reflowParseText(rawText) {
    const text = String(rawText || '').replace(/\r\n?/g, '\n');
    /* One or more blank lines separate sections. */
    const chunks = text.split(/\n[ \t]*\n+/);
    const blocks = [];
    chunks.forEach((chunk) => {
        let lines = chunk.split('\n');
        while (lines.length && lines[0].trim() === '') { lines.shift(); }
        while (lines.length && lines[lines.length - 1].trim() === '') { lines.pop(); }
        if (!lines.length) { return; }

        let type = null;
        let number = null;
        const m = lines[0].match(REFLOW_LABEL_RE);
        /* Only treat the first line as a label when there's a lyric body after it
           — a one-line block that merely looks like a label is kept as lyrics. */
        if (m && lines.length > 1) {
            type = reflowNormalizeType(m[1]);
            number = m[2] ? parseInt(m[2], 10) : null;
            lines = lines.slice(1);
            while (lines.length && lines[0].trim() === '') { lines.shift(); }
        }
        blocks.push({ type: type, number: number, lines: lines });
    });
    reflowApplySmartDefaults(blocks);
    return blocks;
}

export { REFLOW_TYPES };
