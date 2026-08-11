/* ============================================================================
 * iHymns — Song print templates (#1350)
 *
 * Replaces window.print() on the chromed song page with a CLEAN standalone print
 * document built from the song's structured data (?action=song_data), in a
 * user-chosen template.
 *
 * Phase 1: built-in templates + picker. Phase 2 (this): templates are an ordered
 * BLOCK LIST so curators can compose their own in the WYSIWYG editor at
 * /manage/print-templates (saved to tblPrintTemplates, fetched via
 * ?action=print_templates and merged into the picker). The 3 built-ins below stay
 * in JS so printing always works offline. The block renderer is EXPORTED so the
 * admin editor's live preview is byte-identical to the printed page (one source of
 * truth).
 *
 * A template = { id?, name, blocks: [{type, …options}], pageOptions: {fontPt, columns} }.
 *
 * Docs: https://developer.mozilla.org/en-US/docs/Web/API/Window/print
 * ========================================================================== */

import { apiFetch } from '../utils/api-client.js';

/* Block-type registry — drives BOTH the renderer (below) and the editor's palette.
   `label` = editor display; `options` = editable per-block option keys + defaults. */
export const PRINT_BLOCK_TYPES = {
    title:       { label: 'Title',           options: {} },
    subtitle:    { label: 'Songbook + number', options: { showBook: true, showNumber: true, bookAbbr: false } },  /* #1767 B — bookAbbr shows the abbreviation instead of the full name */
    credits:     { label: 'Credits (authors)', options: {} },
    lyrics:      { label: 'Lyrics',          options: { showLabels: true, showChords: false, columns: 1, align: 'left', size: 'md' } },  /* #1767 A — align + font scale */
    copyright:   { label: 'Copyright',       options: {} },
    identifiers: { label: 'CCLI / ISWC',     options: { ccli: true, iswc: true } },
    scripture:   { label: 'Scripture ref.',  options: {} },                        /* #1767 N — tblSongScriptureRefs via ?include=scriptureRefs */
    tune:        { label: 'Tune + metre',    options: { showMetre: true } },       /* #1767 O — tuneName base + metre via ?include=tune */
    themes:      { label: 'Themes / tags',   options: {} },                        /* #1767 P — song.tags (#1152 theme vocab), already in payload */
    text:        { label: 'Custom text',     options: { content: '' } },
    permalink:   { label: 'Permalink (URL)', options: {} },
    qr:          { label: 'QR code',         options: { size: 'md' } },            /* #1767 R — QR of the permalink, rendered as an <img> to the CueRCode-backed /qr.php */
    spacer:      { label: 'Spacer',          options: { size: 'md' } },
    pagebreak:   { label: 'Page break',      options: {} },
};

/* Page-level option registry (#1767 G/V/AB/AM/F) — the SAME pattern as
   PRINT_BLOCK_TYPES: one source of truth that drives the renderer (printCss),
   the editor's page-options panel, AND the server allow-list ($PAGE_OPTION_SCHEMA
   in manage/print-templates.php, held in lockstep by test-print-block-registry.php,
   rule #35). Adding a page option = one line here + printCss support + the PHP
   mirror. `kind` drives the editor control; `choices` lists enum values; `def` is
   the default the renderer falls back to (so an un-set option reproduces the
   prior output). */
export const PRINT_PAGE_OPTIONS = {
    fontPt:      { label: 'Base font (pt)',   kind: 'int',   def: 12, min: 6, max: 72 },
    pageSize:    { label: 'Page size',        kind: 'enum',  def: 'A4',     choices: ['A4', 'Letter', 'Legal'] },       /* #1767 G */
    lineHeight:  { label: 'Line spacing',     kind: 'enum',  def: 'normal', choices: ['tight', 'normal', 'relaxed'] }, /* #1767 F */
    contrast:    { label: 'High contrast',    kind: 'enum',  def: 'normal', choices: ['normal', 'high'] },             /* #1767 V */
    accentColor: { label: 'Accent colour',    kind: 'color', def: '' },                                                /* #1767 AM */
    inkSaver:    { label: 'Ink-saver (mono)', kind: 'bool',  def: false },                                             /* #1767 AB */

    /* #1767 remainder P4 (§4.3) — PDF-ONLY options, each flagged
       `serverOnly: true`. Browser `@page` margin-box support is too patchy
       to ship (the reason the original #1767 slice deferred the whole
       H-family) — these three exist ONLY because the server-PDF pipeline
       (includes/pdf_renderer.php, mPDF's own SetHTMLHeader/SetHTMLFooter)
       CAN honour them reliably. `printCss()` below never reads a
       `serverOnly` key (it destructures specific known keys only), so the
       browser Print path is a structural, code-level no-op for these — not
       a documentation promise. The editor's page-options panel
       (manage/print-templates.php) groups `serverOnly` entries under a
       "PDF only" heading so a curator never expects one to affect the
       browser preview. Mirrored 1:1 (including the `serverOnly` flag) into
       `$PAGE_OPTION_SCHEMA` (includes/print_template_schema.php), held in
       lockstep by tests/php/test-print-block-registry.php. */
    pageNumbers:   { label: 'Page numbers',   kind: 'bool', def: false,  serverOnly: true },                              /* #1767 U */
    runningHeader: { label: 'Running header', kind: 'enum', def: 'none', choices: ['none', 'title', 'titleBook'], serverOnly: true }, /* #1767 H */
    onePerPage:    { label: 'One song per page (batch PDFs)', kind: 'bool', def: true, serverOnly: true },                /* #1767 T */
};

/* Accepted CSS colour shape for the accent option — #rgb or #rrggbb. Validated
   IDENTICALLY here and in the PHP sanitiser (an <input type=color> only ever
   emits #rrggbb; this guards a crafted POST). */
const PRINT_ACCENT_RE = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

/* True when any component carries a non-empty chord line. */
function componentsHaveChords(song) {
    const comps = Array.isArray(song && song.components) ? song.components : [];
    return comps.some(c => Array.isArray(c && c.chords)
        && c.chords.some(x => String(Array.isArray(x) ? x.join('') : (x || '')).trim() !== ''));
}

/* True when the song lives in a PUBLISHED (official) songbook. A song in an
   unofficial songbook, a catalogue, or any non-published grouping (rule #24 —
   `tblSongbooks.IsOfficial = 0`) has no meaningful "Songbook + number", because
   such books don't assign published song numbers. The "Songbook + number"
   (subtitle) block outputs NOTHING for those songs — the songbook reference and
   the number are simply not relevant to the printout (owner directive
   2026-08-05). Determinant: the explicit `isOfficial` flag when the payload
   carries it (authoritative), otherwise the presence of a positive song number
   (the observable proxy — an unofficial/catalogue song has none). */
function songInPublishedBook(song) {
    if (song && song.isOfficial != null) { return !!song.isOfficial; }
    const n = song ? parseInt(song.number, 10) : NaN;
    return Number.isFinite(n) && n > 0;
}

/* Conditional block visibility (#1767 Y) — a UNIVERSAL `showIf` property any block
   may carry (not a per-type option), so one template can adapt to each song:
   e.g. a chords block only when the song has chords, a CCLI block only when it
   has a number. Evaluated by blockVisible() before rendering; unknown/absent =>
   visible (fail-open). The name set is mirrored by $SHOWIF_CONDITIONS in
   manage/print-templates.php, held in lockstep by the registry guard (rule #35).
   'always' is the default (no showIf) and is kept in the list so the editor's
   select has an explicit "always show" choice. */
export const PRINT_SHOWIF_CONDITIONS = {
    always:       { label: 'Always show',            test: () => true },
    hasChords:    { label: 'Only if it has chords',  test: (s) => componentsHaveChords(s) },
    hasCopyright: { label: 'Only if copyrighted',    test: (s) => !!(s && s.copyright && String(s.copyright).trim()) },
    hasCcli:      { label: 'Only if it has a CCLI #', test: (s) => !!(s && s.ccli && String(s.ccli).trim()) },
    hasScripture: { label: 'Only if it has scripture', test: (s) => Array.isArray(s && s.scriptureRefs) && s.scriptureRefs.length > 0 },
    hasThemes:    { label: 'Only if it has themes',   test: (s) => Array.isArray(s && s.tags) && s.tags.length > 0 },
    hasTune:      { label: 'Only if it has a tune',   test: (s) => !!(s && ((s.tune && s.tune.name) || s.tuneName)) },
};

/* Evaluate a block's showIf against the song. Fail-open: no condition, 'always',
   an unknown name, or a throwing predicate all render the block. */
function blockVisible(song, block) {
    const cond = block && block.showIf;
    if (!cond || cond === 'always') { return true; }
    const rule = PRINT_SHOWIF_CONDITIONS[cond];
    if (!rule || typeof rule.test !== 'function') { return true; }
    try { return !!rule.test(song); } catch (_e) { return true; }
}

/* The 3 built-ins, expressed as block templates (so built-in + custom unify). */
export const PRINT_BUILTIN_TEMPLATES = [
    { id: 'builtin:lyrics', name: 'Lyrics only', builtin: true, pageOptions: { fontPt: 12, columns: 1 },
      blocks: [ { type: 'title' }, { type: 'subtitle', showBook: true, showNumber: true },
                { type: 'lyrics', showLabels: true, showChords: false, columns: 1 },
                { type: 'copyright' }, { type: 'identifiers', ccli: true, iswc: true } ] },
    { id: 'builtin:chords', name: 'Lyrics + chords', builtin: true, pageOptions: { fontPt: 12, columns: 1 },
      blocks: [ { type: 'title' }, { type: 'subtitle', showBook: true, showNumber: true },
                { type: 'lyrics', showLabels: true, showChords: true, columns: 1 },
                { type: 'copyright' }, { type: 'identifiers', ccli: true, iswc: true } ] },
    { id: 'builtin:large', name: 'Large print', builtin: true, pageOptions: { fontPt: 18, columns: 1 },
      blocks: [ { type: 'title' }, { type: 'subtitle', showBook: true, showNumber: true },
                { type: 'lyrics', showLabels: true, showChords: false, columns: 1 },
                { type: 'copyright' } ] },
];

/* A representative song for the editor's live preview (no fetch needed). */
export const PRINT_SAMPLE_SONG = {
    id: 'SAMPLE-1', publicId: 'SAMPLEQR01',
    title: 'Amazing Grace', songbookName: 'Sample Hymnal', songbook: 'SAMPLE', number: 1,
    language: 'en', copyright: 'Public Domain', ccli: '22025', iswc: '',
    writers: ['John Newton'], composers: ['Traditional'],
    /* Sample enrichment so the editor preview renders the #1767 N/O/P blocks.
       In the real print path these arrive on song_data (tags) or via
       ?include=scriptureRefs,tune (scriptureRefs, tune). */
    tuneName: 'New Britain',
    tune: { name: 'New Britain', meter: 'C.M.' },
    tags: [{ name: 'Grace' }, { name: 'Salvation' }, { name: 'Assurance' }],
    scriptureRefs: [
        { book: 'Ephesians', chapter: 2, verseStart: 8, verseEnd: 9 },
        { book: 'John', chapter: 1, verseStart: 16 },
    ],
    components: [
        { type: 'verse', number: 1, lines: ['Amazing grace! how sweet the sound', 'That saved a wretch like me!'], chords: ['G        G7       C   G', 'G            D'] },
        { type: 'chorus', number: 0, lines: ['Praise God, praise God'], chords: ['C    G'] },
    ],
};

/* Minimal HTML escape — the print window/preview is assembled as a string, so every
   interpolated value MUST be escaped. */
function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function componentLabel(comp) {
    const type = String(comp.type || 'verse');
    const cap = type.charAt(0).toUpperCase() + type.slice(1);
    const n = comp.number;
    const num = (n != null && n !== '' && !isNaN(n) && parseInt(n, 10) > 0) ? parseInt(n, 10) : null;
    return num != null ? `${cap} ${num}` : cap;
}

/* Collect formatted credit lines from whatever credit arrays the song carries. */
function creditsLines(song) {
    const map = [['writers', 'Words'], ['composers', 'Music'], ['arrangers', 'Arr.'],
                ['adaptors', 'Adapt.'], ['translators', 'Trans.'], ['artists', 'Artist']];
    const out = [];
    map.forEach(([key, label]) => {
        const arr = Array.isArray(song[key]) ? song[key].filter(Boolean) : [];
        if (arr.length) {
            const names = arr.map(v => (typeof v === 'string') ? v : (v.name || '')).filter(Boolean);
            if (names.length) { out.push(`${label}: ${names.join(', ')}`); }
        }
    });
    return out;
}

/* ---- Per-block renderers. Each returns an HTML string (or '' to render nothing). ---- */
function renderLyrics(song, block) {
    const components = Array.isArray(song.components) ? song.components : [];
    const order = (Array.isArray(song.arrangement) && song.arrangement.length)
        ? song.arrangement.map(i => components[i]).filter(Boolean) : components;
    const cols = parseInt(block.columns, 10) === 2 ? 2 : 1;
    /* #1767 A — per-block alignment + font scale (relative to the page base font
       set in printCss). Defaults (left / md=1em) preserve the prior rendering. */
    const align = (block.align === 'center' || block.align === 'right') ? block.align : 'left';
    const scale = block.size === 'lg' ? '1.15em' : block.size === 'sm' ? '0.9em' : '1em';
    const body = order.map((comp) => {
        const lines = Array.isArray(comp.lines) ? comp.lines : [];
        const chords = Array.isArray(comp.chords) ? comp.chords : [];
        const typeClass = 'lyric-' + esc(String(comp.type || 'verse'));
        const label = (block.showLabels !== false)
            ? `<div class="print-label">${esc(componentLabel(comp))}</div>` : '';
        const linesHtml = lines.map((line, i) => {
            const chord = (block.showChords && chords[i])
                ? String(Array.isArray(chords[i]) ? chords[i].join(' ') : chords[i]).trim() : '';
            const chordHtml = chord ? `<div class="print-chord">${esc(chord)}</div>` : '';
            const text = (line && String(line).trim()) ? esc(line) : '&nbsp;';
            return `${chordHtml}<div class="print-line">${text}</div>`;
        }).join('');
        return `<div class="print-component ${typeClass}">${label}${linesHtml}</div>`;
    }).join('');
    return `<div class="print-lyrics" style="columns:${cols};column-gap:1.5em;text-align:${align};font-size:${scale}">${body || '<p>(No lyrics)</p>'}</div>`;
}

function renderBlock(song, block) {
    /* #1767 Y — universal conditional visibility: hide the block when its showIf
       condition doesn't hold for this song (fail-open on unknown/absent). */
    if (!blockVisible(song, block)) { return ''; }
    switch (block.type) {
        case 'title':
            return `<h1 class="print-title">${esc(song.title || 'Untitled')}</h1>`;
        case 'subtitle': {
            /* Owner directive 2026-08-05 — a song not in a published (official)
               songbook (unofficial book / catalogue / non-published grouping)
               has no meaningful "Songbook + number", so the WHOLE block outputs
               nothing: both the songbook reference AND the number are suppressed,
               not just the (already number-gated) number. See songInPublishedBook(). */
            if (!songInPublishedBook(song)) { return ''; }
            const bits = [];
            if (block.showBook !== false && (song.songbookName || song.songbook)) {
                /* #1767 B — bookAbbr prints the abbreviation (song.songbook) rather
                   than the full name; falls back to whichever is present. */
                const bookLabel = (block.bookAbbr === true)
                    ? (song.songbook || song.songbookName)
                    : (song.songbookName || song.songbook);
                bits.push(esc(bookLabel));
            }
            if (block.showNumber !== false && song.number != null && parseInt(song.number, 10) > 0) {
                bits.push('#' + parseInt(song.number, 10));
            }
            return bits.length ? `<div class="print-subtitle">${bits.join(' · ')}</div>` : '';
        }
        case 'credits': {
            const lines = creditsLines(song);
            return lines.length ? `<div class="print-credits">${lines.map(esc).join('<br>')}</div>` : '';
        }
        case 'lyrics':
            return renderLyrics(song, block);
        case 'copyright':
            return song.copyright ? `<div class="print-footer">${esc(song.copyright)}</div>` : '';
        case 'identifiers': {
            const bits = [];
            if (block.ccli !== false && song.ccli) { bits.push('CCLI Song #' + esc(song.ccli)); }
            if (block.iswc !== false && song.iswc) { bits.push('ISWC ' + esc(song.iswc)); }
            return bits.length ? `<div class="print-footer">${bits.join('<br>')}</div>` : '';
        }
        case 'scripture': {
            /* #1767 N — scripture cross-references (tblSongScriptureRefs, #1112),
               carried on the song only when the print fetch asked for
               ?include=scriptureRefs. Renders nothing when absent (graceful, like
               copyright/identifiers). Each ref → "Book C:Vs–Ve". */
            const refs = Array.isArray(song.scriptureRefs) ? song.scriptureRefs : [];
            const fmt = refs.map((r) => {
                const bookName = String(r.book || '').trim();
                if (!bookName) { return ''; }
                let s = bookName;
                const ch = parseInt(r.chapter, 10);
                if (!isNaN(ch) && ch > 0) { s += ' ' + ch; }
                const vs = parseInt(r.verseStart, 10);
                if (!isNaN(vs) && vs > 0) {
                    s += ':' + vs;
                    const ve = parseInt(r.verseEnd, 10);
                    if (!isNaN(ve) && ve > vs) { s += '–' + ve; }   // en dash for the range
                }
                return s;
            }).filter(Boolean);
            return fmt.length ? `<div class="print-scripture">${fmt.map(esc).join('; ')}</div>` : '';
        }
        case 'tune': {
            /* #1767 O — tune name (base `tuneName`, always present) + metre
               (MeterCode, carried on song.tune only via ?include=tune, #1748).
               "Tune: <name> · <metre>" — folds to just one when the other is absent. */
            const tuneName = String((song.tune && song.tune.name) || song.tuneName || '').trim();
            const metre = (block.showMetre !== false)
                ? String((song.tune && song.tune.meter) || '').trim() : '';
            if (!tuneName && !metre) { return ''; }
            let label;
            if (tuneName && metre) { label = `Tune: ${esc(tuneName)} · ${esc(metre)}`; }
            else if (tuneName)     { label = `Tune: ${esc(tuneName)}`; }
            else                   { label = `Metre: ${esc(metre)}`; }
            return `<div class="print-tune">${label}</div>`;
        }
        case 'themes': {
            /* #1767 P — theme/tag chips (song.tags, #1152 standard vocabulary),
               already on the base song_data payload. Rendered as a comma list. */
            const tags = Array.isArray(song.tags) ? song.tags : [];
            const names = tags
                .map(t => (typeof t === 'string') ? t : (t && t.name) || '')
                .map(s => String(s).trim())
                .filter(Boolean);
            return names.length
                ? `<div class="print-themes">Themes: ${names.map(esc).join(', ')}</div>` : '';
        }
        case 'text':
            return block.content ? `<div class="print-text">${esc(block.content)}</div>` : '';
        case 'permalink': {
            /* The canonical short permalink (#1343-B PublicId when present) printed as
               text so a reader can type it to open the song — and the natural payload
               for a future QR-image upgrade of this block.
               Base = window.location.origin BY DESIGN: a real printout is produced from
               the public song page, so the origin IS the public host. (The /manage
               editor's live preview runs on the admin host, so its sample URL shows that
               host — illustrative only; the actual handout uses the public origin.) */
            const origin = (typeof window !== 'undefined' && window.location) ? window.location.origin : '';
            const pid = song.publicId || song.id || '';
            if (!pid) { return ''; }
            const url = origin + '/song/' + encodeURIComponent(pid);
            return `<div class="print-permalink">Find this song online:<br>`
                + `<span class="print-permalink-url">${esc(url)}</span></div>`;
        }
        case 'qr': {
            /* #1767 R — a scannable QR of the public permalink. The QR image is
               generated by the CueRCode API server-side and served by the
               same-origin /qr.php endpoint (owner directive 2026-08-05 — QR via
               CueRCode; the secret key never reaches the browser). So this stays
               a PURE synchronous <img> emit: no client library, no async pre-pass.
               The URL caption is ALWAYS shown, so if /qr.php can't produce a code
               (CueRCode not yet configured / unreachable → 503 → the <img> fails)
               the reader still has the link (the "typed fallback always shows"
               principle). onerror hides the broken-image glyph in the print
               window (which carries no CSP); if a page's CSP blocks the handler
               it degrades harmlessly to a hidden-by-nothing image beside the URL. */
            const origin = (typeof window !== 'undefined' && window.location) ? window.location.origin : '';
            const pid = song.publicId || song.id || '';
            if (!pid) { return ''; }
            const url = origin + '/song/' + encodeURIComponent(pid);
            const px = block.size === 'lg' ? 170 : block.size === 'sm' ? 90 : 128;
            /* SVG scales, so a fixed 512 canvas prints crisply at any px. */
            const src = origin + '/qr.php?data=' + encodeURIComponent(url) + '&format=svg&size=512';
            return `<div class="print-qr">`
                + `<img class="print-qr-img" src="${esc(src)}" alt="QR code linking to this song online"`
                + ` width="${px}" height="${px}" style="width:${px}px;height:${px}px"`
                + ` onerror="this.style.display='none'">`
                + `<div class="print-qr-caption">${esc(url)}</div></div>`;
        }
        case 'spacer': {
            const h = block.size === 'lg' ? '2.5em' : block.size === 'sm' ? '0.6em' : '1.2em';
            return `<div style="height:${h}"></div>`;
        }
        case 'pagebreak':
            return '<div style="break-after:page;page-break-after:always"></div>';
        default:
            return '';
    }
}

/* Render the BODY (no <html>/<head>) — used by the editor's live preview. */
export function renderTemplateBodyHtml(song, template) {
    const blocks = Array.isArray(template && template.blocks) ? template.blocks : [];
    return blocks.map(b => renderBlock(song, b)).join('\n');
}

/* The print CSS, parameterised by the template's page options (#1767 G/V/AB/AM/F).
   Every option's ABSENCE reproduces the prior output: A4 + normal line-height +
   normal contrast + no accent + colour on. */
export function printCss(pageOptions) {
    const po = pageOptions || {};
    const fontPt = parseInt(po.fontPt, 10) || 12;
    /* G — paper size → @page size keyword (CSS accepts a4/letter/legal). */
    const pageSize = ({ A4: 'A4', Letter: 'letter', Legal: 'legal' })[po.pageSize] || 'A4';
    /* F — vertical rhythm. */
    const lh = po.lineHeight === 'tight' ? 1.2 : po.lineHeight === 'relaxed' ? 1.6 : 1.35;
    const highContrast = po.contrast === 'high';       /* V */
    const inkSaver = po.inkSaver === true;              /* AB — force mono, drop accent */
    /* V/AB — muted greys collapse to pure black for legibility / crisp mono. */
    const cMuted  = (highContrast || inkSaver) ? '#000' : '#555';
    const cMuted2 = (highContrast || inkSaver) ? '#000' : '#444';
    const cFaint  = (highContrast || inkSaver) ? '#000' : '#777';
    /* AM — accent colour on title + labels; suppressed by ink-saver (mono). */
    const accent = (!inkSaver && typeof po.accentColor === 'string' && PRINT_ACCENT_RE.test(po.accentColor))
        ? po.accentColor : '';
    const titleColor = accent || '#000';
    const labelColor = accent || cMuted2;
    return `
    * { box-sizing: border-box; }
    @page { size: ${pageSize}; }
    body { font-family: Georgia, 'Times New Roman', serif; color: #000; background: #fff;
           margin: 1.5cm; font-size: ${fontPt}pt; line-height: ${lh}; }
    .print-title { font-size: ${fontPt + 6}pt; font-weight: bold; margin: 0 0 0.1em; color: ${titleColor}; }
    .print-subtitle { font-size: ${fontPt - 2}pt; color: ${cMuted}; margin: 0 0 0.6em; }
    .print-credits { font-size: ${fontPt - 2}pt; color: ${cMuted2}; margin: 0 0 0.8em; }
    .print-scripture { font-size: ${fontPt - 2}pt; color: ${cMuted2}; font-style: italic; margin: 0 0 0.5em; }
    .print-tune { font-size: ${fontPt - 2}pt; color: ${cMuted2}; margin: 0 0 0.5em; }
    .print-themes { font-size: ${fontPt - 2}pt; color: ${cMuted}; margin: 0 0 0.6em; }
    .print-component { margin: 0 0 0.9em; break-inside: avoid; }
    .print-label { font-weight: bold; font-size: ${fontPt - 2}pt; color: ${labelColor}; margin-bottom: 0.2em; }
    .lyric-chorus .print-line, .lyric-refrain .print-line { font-style: italic; }
    .print-line { margin: 0.05em 0; }
    .print-chord { font-family: 'Courier New', monospace; font-weight: bold; color: ${cMuted}; white-space: pre-wrap; margin: 0.15em 0 0; }
    .print-text { margin: 0 0 0.8em; }
    .print-permalink { margin: 0.8em 0; font-size: ${fontPt - 2}pt; color: ${cMuted2}; }
    .print-permalink-url { font-family: 'Courier New', monospace; font-weight: bold; }
    .print-qr { margin: 0.9em 0; text-align: center; break-inside: avoid; }
    .print-qr-img { display: inline-block; max-width: 100%; height: auto;${inkSaver ? ' filter: grayscale(1);' : ''} }
    .print-qr-caption { font-family: 'Courier New', monospace; font-size: ${Math.max(8, fontPt - 4)}pt; color: ${cMuted2}; margin-top: 0.3em; word-break: break-all; }
    .print-footer { margin-top: 1em; font-size: ${Math.max(8, fontPt - 3)}pt; color: ${cFaint}; }
    @media print { body { margin: 1.2cm; -webkit-print-color-adjust: exact; print-color-adjust: exact; } }`;
}

/* Assemble the full standalone print document. */
export function buildPrintDoc(song, template) {
    const title = song.title || 'Untitled';
    const book  = song.songbookName || song.songbook || '';
    return `<!DOCTYPE html>
<html lang="${esc(song.language || 'en')}">
<head>
<meta charset="utf-8">
<title>${esc(title)}${book ? ' — ' + esc(book) : ''}</title>
<style>${printCss(template.pageOptions)}</style>
</head>
<body>
${renderTemplateBodyHtml(song, template)}
</body>
</html>`;
}

function printDoc(html) {
    const w = window.open('', '_blank');
    if (!w) { return false; }
    w.document.open();
    w.document.write(html);
    w.document.close();
    w.onload = () => { try { w.focus(); w.print(); } catch (_e) { /* user can print manually */ } };
    return true;
}

export async function fetchSong(app, songId) {
    try {
        const base = (app && app.config && app.config.apiUrl) ? app.config.apiUrl : '/api';
        /* #1767 N/O — opt-in enrichment so the Scripture-reference and Tune+metre
           blocks have data. Back-compat: unknown/absent include blocks are ignored
           server-side and omitted from the payload; tags ship on the base shape. */
        const res = await apiFetch(
            `${base}?action=song_data&id=${encodeURIComponent(songId)}&include=scriptureRefs,tune`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!res.ok) { return null; }
        const json = await res.json();
        return json.song || null;
    } catch (_e) { return null; }
}

/* #1767 R / owner directive 2026-08-05 — QR is now generated by the CueRCode API
   server-side and served by the same-origin /qr.php endpoint; renderBlock('qr')
   emits a plain <img> to it. There is deliberately NO client-side QR library or
   async pre-pass here any more (the old vendored `qrcode-generator` approach was
   removed — the secret CueRCode key must never reach the browser). */

/* Fetch curated custom templates (scope=song); merge after the built-ins. Failure or
   an un-migrated install yields just the built-ins (graceful). */
export async function loadTemplates(app) {
    let custom = [];
    try {
        const base = (app && app.config && app.config.apiUrl) ? app.config.apiUrl : '/api';
        const res = await apiFetch(`${base}?action=print_templates&scope=song`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (res.ok) {
            const json = await res.json();
            if (Array.isArray(json.templates)) {
                custom = json.templates
                    .filter(t => t && Array.isArray(t.blocks) && t.name)
                    .map(t => ({ id: 'db:' + t.id, name: String(t.name), blocks: t.blocks, pageOptions: t.pageOptions || {} }));
            }
        }
    } catch (_e) { /* built-ins only */ }
    return PRINT_BUILTIN_TEMPLATES.concat(custom);
}

/* #1767 remainder P4 (§3.3) — is the server-PDF endpoint reachable for THIS
   session? `manage/print-pdf.php?ping=1` answers 204 (authenticated + the
   mPDF engine is vendored on this install) or 401/503 (not shown — the
   Download-PDF affordance is gated on this, never assumed). Memoised at
   MODULE scope so only the FIRST print action in a page session pays the
   round trip; every subsequent pickPrintTemplate({offerPdf:true}) call
   reuses the settled promise instantly. A network failure degrades to
   "unavailable" (false), never a thrown error the picker would have to
   catch — the browser Print path is always the safe fallback. */
let _pdfPingPromise = null;
function pdfEndpointAvailable() {
    if (!_pdfPingPromise) {
        _pdfPingPromise = apiFetch('/manage/print-pdf.php?ping=1', {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then((res) => res.status === 204).catch(() => false);
    }
    return _pdfPingPromise;
}

/* Show the template picker; resolves `{ tpl, action }` (action: 'print' |
   'pdf'), or null if cancelled (Esc / backdrop / Cancel). Shared by the
   single-song Print and the set-list Print (rule #22 — one picker, one
   look). `opts.offerPdf` (default false) is what makes the "Download PDF"
   button possible to show at all — callers that haven't built the
   server-PDF POST path yet (the set-list print, still browser-only this
   phase — batch mode is #1767 remainder P6) simply never pass it, so no
   ping request fires and no button appears for them; every resolve still
   carries `action: 'print'` in that case, so an un-migrated caller needs
   no other change. */
export async function pickPrintTemplate(app, templates, opts = {}) {
    const offerPdf = !!(opts && opts.offerPdf);
    const pdfAvailable = offerPdf && await pdfEndpointAvailable();

    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'print-picker-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2000;display:flex;align-items:center;'
            + 'justify-content:center;background:rgba(0,0,0,.55);padding:1rem;';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'print-picker-title');

        const opts2 = templates.map((t, i) => `
            <label class="list-group-item d-flex gap-2 align-items-center">
                <input class="form-check-input flex-shrink-0" type="radio" name="print-template"
                       value="${i}" ${i === 0 ? 'checked' : ''}>
                <span><strong>${esc(t.name)}</strong>${t.builtin ? '' : ' <span class="badge bg-secondary-subtle text-secondary-emphasis">custom</span>'}</span>
            </label>`).join('');

        const pdfBtnHtml = pdfAvailable
            ? `<button type="button" class="btn btn-outline-secondary btn-sm print-picker-pdf">
                   <i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Download PDF</button>`
            : '';

        const dialog = document.createElement('div');
        dialog.className = 'card shadow-lg';
        dialog.style.cssText = 'max-width:30rem;width:100%;';
        dialog.innerHTML = `
            <div class="card-body">
                <h2 class="h5 card-title d-flex align-items-center gap-2" id="print-picker-title">
                    <i class="fa-solid fa-print" aria-hidden="true"></i>Print song</h2>
                <p class="card-text small text-muted">Choose a layout — a clean, printer-friendly page (no app buttons).</p>
                <div class="list-group mb-3" style="max-height:18rem;overflow:auto">${opts2}</div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm print-picker-cancel">Cancel</button>
                    ${pdfBtnHtml}
                    <button type="button" class="btn btn-primary btn-sm print-picker-go">
                        Print <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></button>
                </div>
            </div>`;
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        const goEl     = dialog.querySelector('.print-picker-go');
        const cancelEl = dialog.querySelector('.print-picker-cancel');
        const pdfEl    = dialog.querySelector('.print-picker-pdf');
        /* Tab-trap over WHICHEVER buttons actually rendered — generalised
           (rather than hardcoding "cancel then go") so the optional pdfEl
           slots in without a separate branch. */
        const focusables = [cancelEl, pdfEl, goEl].filter(Boolean);
        const lastFocus = document.activeElement;
        let resolved = false;
        function close(result) {
            overlay.remove();
            if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
            if (!resolved) { resolved = true; resolve(result); }
        }
        function pickedTemplate() {
            const sel = overlay.querySelector('input[name="print-template"]:checked');
            return templates[sel ? parseInt(sel.value, 10) : 0] || templates[0];
        }
        goEl.focus();

        overlay.addEventListener('click', (e) => { if (e.target === overlay) { close(null); } });
        overlay.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { close(null); return; }
            if (e.key === 'Tab') {
                const idx = focusables.indexOf(document.activeElement);
                if (idx === -1) { return; }
                if (e.shiftKey && idx === 0) { e.preventDefault(); focusables[focusables.length - 1].focus(); }
                else if (!e.shiftKey && idx === focusables.length - 1) { e.preventDefault(); focusables[0].focus(); }
            }
        });
        cancelEl.addEventListener('click', () => close(null));
        if (pdfEl) {
            pdfEl.addEventListener('click', () => close({ tpl: pickedTemplate(), action: 'pdf' }));
        }
        goEl.addEventListener('click', () => close({ tpl: pickedTemplate(), action: 'print' }));
    });
}

/* Turn a song title into a filesystem/URL-safe filename stem. Mirrors the
   character class manage/print-pdf.php's own Content-Disposition sanitiser
   allows (`[^A-Za-z0-9_-]` stripped server-side too) — this client-side pass
   just makes the DOWNLOADED file's name legible instead of relying solely
   on the server's stricter fallback. */
function pdfFilenameFor(song) {
    const raw = String((song && song.title) || 'ihymns-print').toLowerCase();
    const slug = raw.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    return slug || 'ihymns-print';
}

/* Trigger a browser "Save As" for an already-fetched Blob, without ever
   navigating the current page away. Revokes the object URL shortly after —
   long enough for the download to have started, short enough not to leak
   memory on a page that stays open a while (the print picker can be
   reopened many times in one session). */
function triggerBlobDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
}

/**
 * #1767 remainder P4 (§3.1/§3.3) — POST the SAME HTML/CSS pieces the browser
 * Print path builds (renderTemplateBodyHtml()/printCss() — the one-renderer
 * invariant, never a second render) to the server-PDF endpoint and download
 * the resulting file. `copies` (nullable) rides along so the server can log
 * CCLI print-usage in the SAME request (#1767 remainder P5, §6.3) — the
 * client never makes a separate log call for the PDF path.
 *
 * Branches on HTTP STATUS (rule #35), never the response prose: 503 → the
 * PDF engine isn't installed on this server, fall back to Print; 401 → the
 * session lapsed since the ping check; 429 → rate-limited; anything else →
 * a generic retry toast.
 */
async function downloadSongPdf(app, song, tpl, copies) {
    app.showToast?.('Building PDF…', 'info', 2500);
    const bodyHtml = renderTemplateBodyHtml(song, tpl);
    const css      = printCss(tpl.pageOptions);
    const meta = {
        songId: song.publicId || song.id || '',
        title:  song.title || 'Untitled',
        lang:   song.language || 'en',
        dir:    'ltr',
        book:   song.songbookName || song.songbook || '',
    };
    const payload = {
        mode: 'song',
        documents: [{ bodyHtml, meta }],
        css,
        pageOptions: tpl.pageOptions || {},
        filename: pdfFilenameFor(song),
    };
    if (copies != null) { payload.copies = copies; }

    let res;
    try {
        res = await apiFetch('/manage/print-pdf.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });
    } catch (_e) {
        app.showToast?.('Could not build the PDF — please try again.', 'danger', 4000);
        return;
    }

    if (res.status === 200) {
        const blob = await res.blob();
        triggerBlobDownload(blob, pdfFilenameFor(song) + '.pdf');
        return;
    }
    if (res.status === 503) {
        app.showToast?.("PDF isn't available on this server — use Print instead.", 'warning', 4500);
    } else if (res.status === 401) {
        app.showToast?.('Sign in again to download a PDF.', 'warning', 3500);
    } else if (res.status === 429) {
        app.showToast?.('Too many PDFs just now, try again in a moment.', 'warning', 4000);
    } else {
        app.showToast?.('Could not build the PDF — please try again.', 'danger', 4000);
    }
}

/**
 * openSongPrintDialog(app) — entry point for the song page's Print action. Loads the
 * available templates (built-in + curated), shows the shared picker (offering
 * Download PDF alongside Print when the server-PDF endpoint is reachable —
 * #1767 remainder P4), then fetches and prints/downloads the current song in
 * the chosen template. Falls back to window.print() if we can't identify a
 * song.
 */
export async function openSongPrintDialog(app) {
    const page = document.querySelector('.page-song');
    const songId = page && page.dataset ? page.dataset.songId : '';
    if (!songId) { window.print(); return; }
    const templates = await loadTemplates(app);
    const picked = await pickPrintTemplate(app, templates, { offerPdf: true });
    if (!picked) { return; }
    const { tpl, action } = picked;
    app.showToast?.('Preparing print…', 'info', 1500);
    const song = await fetchSong(app, songId);
    if (!song) { app.showToast?.('Could not load the song to print.', 'danger', 3000); return; }

    if (action === 'pdf') {
        await downloadSongPdf(app, song, tpl, null);
        return;
    }

    /* #1767 R — a `qr` block renders as an <img> to the same-origin /qr.php
       (CueRCode-backed) endpoint; no pre-pass needed. */
    if (!printDoc(buildPrintDoc(song, tpl))) {
        app.showToast?.('Pop-up blocked — allow pop-ups to print.', 'warning', 4000);
    }
}
