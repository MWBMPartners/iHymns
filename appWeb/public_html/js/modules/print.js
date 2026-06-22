/* ============================================================================
 * iHymns — Song print templates (#1350, Phase 1)
 *
 * Replaces the old `window.print()` on the live (chromed) song page — which
 * printed toolbar buttons and app furniture and looked "tacky" for a worship
 * team or congregation — with a CLEAN, standalone print document built from the
 * song's structured data (?action=song_data), in one of a few purpose-built
 * templates the user picks first.
 *
 * Phase 1 ships built-in templates + a picker. Phase 2 (#1350) adds a WYSIWYG
 * block editor + custom templates in tblPrintTemplates.
 *
 * Pattern mirrors the set-list print path (js/modules/setlist.js): open a fresh
 * window, write a self-contained HTML doc with inline print CSS, then print().
 *
 * Docs: https://developer.mozilla.org/en-US/docs/Web/API/Window/print
 * ========================================================================== */

/* Built-in templates. `chords` = include chord lines; `fontPt` = base body size;
   `columns` = lyric column count at print. Order here is the picker order. */
const PRINT_TEMPLATES = {
    lyrics: { label: 'Lyrics only',     hint: 'Clean lyrics — ideal for a congregation handout.', chords: false, fontPt: 12, columns: 1 },
    chords: { label: 'Lyrics + chords', hint: 'Chord lines above the lyrics — for the worship band.', chords: true,  fontPt: 12, columns: 1 },
    large:  { label: 'Large print',     hint: 'Bigger type for accessibility / stage use.',          chords: false, fontPt: 18, columns: 1 },
};
const DEFAULT_TEMPLATE = 'lyrics';

/* Minimal HTML escape — the print window is a separate document we assemble as a
   string, so every interpolated value MUST be escaped (mirrors setlist.js). */
function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* Human label for a component: "Verse 2" / "Chorus" / "Bridge 1". A null / 0
   number is suppressed (mirrors the editor's componentHeaderLabel). */
function componentLabel(comp) {
    const type = String(comp.type || 'verse');
    const cap = type.charAt(0).toUpperCase() + type.slice(1);
    const n = comp.number;
    const num = (n != null && n !== '' && !isNaN(n) && parseInt(n, 10) > 0) ? parseInt(n, 10) : null;
    return num != null ? `${cap} ${num}` : cap;
}

/* Render one component to print HTML. Chord lines (when the template asks for
   them AND the component carries chords) sit on their own monospace line above
   each lyric line, so they line up without depending on per-character spacing. */
function componentHtml(comp, opts) {
    const lines  = Array.isArray(comp.lines) ? comp.lines : [];
    const chords = Array.isArray(comp.chords) ? comp.chords : [];
    const typeClass = 'lyric-' + esc(String(comp.type || 'verse'));
    const body = lines.map((line, i) => {
        const chord = (opts.chords && chords[i]) ? String(Array.isArray(chords[i]) ? chords[i].join(' ') : chords[i]).trim() : '';
        const chordHtml = chord ? `<div class="print-chord">${esc(chord)}</div>` : '';
        const text = (line && String(line).trim()) ? esc(line) : '&nbsp;';
        return `${chordHtml}<div class="print-line">${text}</div>`;
    }).join('');
    return `<div class="print-component ${typeClass}">
        <div class="print-label">${esc(componentLabel(comp))}</div>
        ${body}
    </div>`;
}

/* Assemble the full standalone print document for one song + template. */
function buildPrintDoc(song, tplKey) {
    const tpl = PRINT_TEMPLATES[tplKey] || PRINT_TEMPLATES[DEFAULT_TEMPLATE];
    const title = song.title || 'Untitled';
    const book  = song.songbookName || song.songbook || '';
    const num   = (song.number != null && parseInt(song.number, 10) > 0) ? parseInt(song.number, 10) : null;
    const subtitleBits = [];
    if (book) { subtitleBits.push(esc(book)); }
    if (num != null) { subtitleBits.push('#' + num); }
    const subtitle = subtitleBits.join(' · ');

    const components = Array.isArray(song.components) ? song.components : [];
    /* Honour a saved default arrangement if present (indices into components). */
    const order = (Array.isArray(song.arrangement) && song.arrangement.length)
        ? song.arrangement.map(i => components[i]).filter(Boolean)
        : components;
    const lyricsHtml = order.map(c => componentHtml(c, tpl)).join('');

    const footerBits = [];
    if (song.copyright) { footerBits.push(esc(song.copyright)); }
    if (song.ccli)      { footerBits.push('CCLI Song #' + esc(song.ccli)); }
    if (song.iswc)      { footerBits.push('ISWC ' + esc(song.iswc)); }
    const footer = footerBits.length ? `<div class="print-footer">${footerBits.join('<br>')}</div>` : '';

    return `<!DOCTYPE html>
<html lang="${esc(song.language || 'en')}">
<head>
<meta charset="utf-8">
<title>${esc(title)}${book ? ' — ' + esc(book) : ''}</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Georgia, 'Times New Roman', serif; color: #000; background: #fff;
           margin: 1.5cm; font-size: ${tpl.fontPt}pt; line-height: 1.35; }
    .print-title { font-size: ${tpl.fontPt + 6}pt; font-weight: bold; margin: 0 0 0.1em; }
    .print-subtitle { font-size: ${tpl.fontPt - 2}pt; color: #555; margin: 0 0 1em; }
    .print-body { columns: ${tpl.columns}; column-gap: 1.5em; }
    .print-component { margin: 0 0 0.9em; break-inside: avoid; }
    .print-label { font-weight: bold; font-size: ${tpl.fontPt - 2}pt; color: #444; margin-bottom: 0.2em; }
    .lyric-chorus .print-line, .lyric-refrain .print-line { font-style: italic; }
    .print-line { margin: 0.05em 0; }
    .print-chord { font-family: 'Courier New', monospace; font-weight: bold;
                   color: #555; white-space: pre-wrap; margin: 0.15em 0 0; }
    .print-footer { margin-top: 1.5em; padding-top: 0.6em; border-top: 1px solid #ccc;
                    font-size: ${Math.max(8, tpl.fontPt - 3)}pt; color: #777; }
    @media print { body { margin: 1.2cm; -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
    <h1 class="print-title">${esc(title)}</h1>
    ${subtitle ? `<div class="print-subtitle">${subtitle}</div>` : ''}
    <div class="print-body">${lyricsHtml || '<p>(No lyrics)</p>'}</div>
    ${footer}
</body>
</html>`;
}

/* Open a fresh window, write the doc, print once it has rendered. Returns false
   if the window was blocked (caller can surface a toast). */
function printDoc(html) {
    const w = window.open('', '_blank');
    if (!w) { return false; }
    w.document.open();
    w.document.write(html);
    w.document.close();
    w.onload = () => { try { w.focus(); w.print(); } catch (_e) { /* user can print manually */ } };
    return true;
}

/* The template-picker overlay — pick a template, then fetch + print. Self-built
   (no Bootstrap-JS dependency), focus-trapped, ESC/backdrop to cancel. */
function showPicker(app, songId) {
    const overlay = document.createElement('div');
    overlay.className = 'print-picker-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2000;display:flex;align-items:center;'
        + 'justify-content:center;background:rgba(0,0,0,.55);padding:1rem;';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'print-picker-title');

    const opts = Object.entries(PRINT_TEMPLATES).map(([key, t], i) => `
        <label class="list-group-item d-flex gap-2 align-items-start">
            <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="print-template"
                   value="${esc(key)}" ${i === 0 ? 'checked' : ''}>
            <span><strong>${esc(t.label)}</strong><br>
            <span class="small text-muted">${esc(t.hint)}</span></span>
        </label>`).join('');

    const dialog = document.createElement('div');
    dialog.className = 'card shadow-lg';
    dialog.style.cssText = 'max-width:30rem;width:100%;';
    dialog.innerHTML = `
        <div class="card-body">
            <h2 class="h5 card-title d-flex align-items-center gap-2" id="print-picker-title">
                <i class="fa-solid fa-print" aria-hidden="true"></i>Print song</h2>
            <p class="card-text small text-muted">Choose a layout — a clean, printer-friendly page (no app buttons).</p>
            <div class="list-group mb-3">${opts}</div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm print-picker-cancel">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm print-picker-go">
                    Print <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></button>
            </div>
        </div>`;
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    const goEl     = dialog.querySelector('.print-picker-go');
    const cancelEl = dialog.querySelector('.print-picker-cancel');
    const lastFocus = document.activeElement;
    function close() {
        overlay.remove();
        if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
    }
    goEl.focus();

    overlay.addEventListener('click', (e) => { if (e.target === overlay) { close(); } });
    overlay.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { close(); return; }
        if (e.key === 'Tab') { /* trap between the two buttons */
            if (e.shiftKey && document.activeElement === cancelEl) { e.preventDefault(); goEl.focus(); }
            else if (!e.shiftKey && document.activeElement === goEl) { e.preventDefault(); cancelEl.focus(); }
        }
    });
    cancelEl.addEventListener('click', close);
    goEl.addEventListener('click', async () => {
        const chosen = (overlay.querySelector('input[name="print-template"]:checked') || {}).value || DEFAULT_TEMPLATE;
        close();
        app.showToast?.('Preparing print…', 'info', 1500);
        const song = await fetchSong(app, songId);
        if (!song) { app.showToast?.('Could not load the song to print.', 'danger', 3000); return; }
        if (!printDoc(buildPrintDoc(song, chosen))) {
            app.showToast?.('Pop-up blocked — allow pop-ups to print.', 'warning', 4000);
        }
    });
}

async function fetchSong(app, songId) {
    try {
        const base = (app && app.config && app.config.apiUrl) ? app.config.apiUrl : '/api';
        const res = await fetch(`${base}?action=song_data&id=${encodeURIComponent(songId)}`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!res.ok) { return null; }
        const json = await res.json();
        return json.song || null;
    } catch (_e) { return null; }
}

/**
 * openSongPrintDialog(app) — entry point for the song page's Print action.
 * Shows the template picker for the currently-rendered song. Falls back to the
 * browser's window.print() if we can't identify a song (e.g. a non-song page).
 */
export function openSongPrintDialog(app) {
    const page = document.querySelector('.page-song');
    const songId = page && page.dataset ? page.dataset.songId : '';
    if (!songId) { window.print(); return; }
    showPicker(app, songId);
}
