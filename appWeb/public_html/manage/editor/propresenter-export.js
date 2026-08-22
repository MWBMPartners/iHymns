/**
 * iHymns Song Editor — ProPresenter 7+ (.pro) Exporter
 * =====================================================
 *
 * Converts an iHymns song record into a ProPresenter 7+ presentation
 * file (`.pro`). The file format is a binary Google Protocol Buffer
 * (`rv.data.Presentation`).
 *
 * Schema source of truth
 * ----------------------
 * The protobuf schema is vendored verbatim from greyshirtguy's
 * reverse-engineered ProPresenter7-Proto repository (Proto 7.16) into
 * `appWeb/private_html/editor/protos/proto-7.16/`. A precompiled JSON
 * descriptor (`protos/proto-bundle.json`) is generated at build time
 * by `tools/build-proto-bundle.js` and loaded by this module at
 * runtime.
 *
 * Encoding is delegated to `protobufjs` (loaded via the global
 * `protobuf` symbol from a `<script>` tag in `index.php`). This means
 * field numbers, wire-types, varint sizes, repeated/oneof/required
 * semantics and proto3 default-value handling are all enforced by the
 * schema rather than by hand-rolled byte-stuffing — which removes a
 * significant source of import-time errors when ProPresenter opens
 * the file.
 *
 * Public API (exposed on `window.iHymnsProPresenter`):
 *
 *   await init(options?)
 *     - Loads the protobuf descriptor. Called automatically by the
 *       export helpers; you can also call it eagerly during page
 *       load to surface schema errors early.
 *
 *   await exportSong(song)
 *     - Builds and triggers download of a single `.pro` file.
 *       Resolves to `{ filename, size }`.
 *
 *   await exportAllAsZip(songs, options?)
 *     - Builds and triggers download of a ZIP containing one `.pro`
 *       file per song. Resolves to `{ filename, size, count }`.
 *
 *   await buildPresentation(song)
 *     - Returns the raw `Uint8Array` of the encoded `.pro` file.
 *
 *   buildFilename(song) / buildRTF(lines)
 *     - Pure helpers (no I/O), useful for tests and previews.
 *
 * References:
 *   https://github.com/greyshirtguy/ProPresenter7-Proto
 *   https://github.com/jeffmikels/propresenter_parser
 *   https://github.com/JonathanMayer/ProPresenter7-PHP
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 * This software is proprietary and confidential. Unauthorised copying,
 * distribution, or modification of this file, via any medium, is
 * strictly prohibited.
 */
(function (global) {
    'use strict';

    /* ==================================================================
     *  SECTION 1 — Module-level state
     * ================================================================== */

    /* Resolved on first init() — cached for the rest of the session. */
    var protoRoot = null;
    var initPromise = null;

    /* Where to fetch the descriptor from when running in the browser.
     * ELI5: this used to be a relative path ('protos/proto-bundle.json'),
     * like a filename with no leading slash — the browser fills in "the
     * rest" using wherever the CURRENT PAGE lives, not where this script
     * file lives.
     * Detail: fetch() resolves a relative URL against the document's own
     * location, not the <script src> that defined it (same rule as
     * relative href/src elsewhere in HTML — there is no per-script base).
     * https://developer.mozilla.org/docs/Web/API/Window/fetch
     * This module is loaded from THREE surfaces sharing one docroot: the
     * v1 editor (/manage/editor/index.php), the v2 editor
     * (/manage/editor/editor2.php) and the public export-ui.js
     * (loaded from any /song/<id> or /songbook/<abbr> page, #1166). Only
     * the editor pages happen to live under /manage/editor/, so a relative
     * URL only ever resolved correctly from there. From a public song page
     * the same relative path resolved to e.g. /song/protos/proto-bundle.json,
     * which doesn't exist — but the SPA's catch-all rewrite
     * (.htaccess:180) answers every unknown path with the app shell (200 +
     * HTML) instead of a 404, so response.json() threw a SyntaxError
     * trying to parse HTML as JSON instead of failing with a clear "not
     * found" (#1566). A root-absolute path resolves the same way
     * regardless of which page imported this script, matching the
     * repo-wide root-absolute convention for shared assets. */
    var DEFAULT_BUNDLE_URL = '/manage/editor/protos/proto-bundle.json';

    /* ProPresenter ACTION_TYPE_PRESENTATION_SLIDE = 11 (from
       action.proto). Hard-coded as a named constant so the intent is
       legible at the call site below. */
    var ACTION_TYPE_PRESENTATION_SLIDE = 11;

    /* ==================================================================
     *  SECTION 2 — Protobuf root initialisation
     * ==================================================================
     * `protobufjs` exposes a `Root` object that owns the loaded type
     * descriptors. We construct one from the precompiled JSON bundle
     * and look up the message types we need.
     *
     * The protobuf runtime is provided externally via a `<script>` tag
     * (see `index.php`). We accept either `window.protobuf` (the global
     * the protobufjs UMD bundle exposes) or an explicit value passed
     * to `init()` so unit tests can inject the import directly. */

    function getProtobuf(opts) {
        if (opts && opts.protobuf) return opts.protobuf;
        if (global.protobuf) return global.protobuf;
        throw new Error(
            'protobufjs runtime not found. Include protobuf.min.js (or pass ' +
            '{ protobuf } to init()) before calling iHymnsProPresenter.'
        );
    }

    function init(options) {
        if (initPromise) return initPromise;
        options = options || {};

        initPromise = (async function () {
            /* #1788 — PREFER the precompiled STATIC schema. The reflection path
               below (protobuf.Root.fromJSON → lazy `new Function` codegen on
               first encode) is refused by the enforcing nonce CSP (#117), which
               killed every PP7 export in the browser. pp7-proto-static.js is a
               `pbjs -t static` build that writes the encoder out longhand (no
               eval) and exposes the schema tree as window.iHymnsPP7Proto. The
               three browser surfaces (v1 editor index.php, v2 editor
               editor2.php, public export-ui.js) all load it before this module,
               so the static branch is what runs in production. */
            var staticRoot = options.protoStatic
                || (typeof global !== 'undefined' && global.iHymnsPP7Proto)
                || null;
            if (staticRoot && staticRoot.rv && staticRoot.rv.data && staticRoot.rv.data.Presentation) {
                protoRoot = staticRoot;
                return protoRoot;
            }

            /* Fallback: REFLECTION descriptor. Reached only when no static tree
               is present — i.e. Node tests that inject { bundle } (eval is
               allowed there), never the browser. This path codegens via
               `new Function` and WOULD hit the CSP if it ever ran in-page. */
            var protobuf = getProtobuf(options);
            var bundle;
            if (options.bundle) {
                /* Tests inject the descriptor directly. */
                bundle = options.bundle;
            } else {
                var url = options.bundleUrl || DEFAULT_BUNDLE_URL;
                if (typeof fetch !== 'function') {
                    throw new Error('fetch() unavailable; pass { bundle } to init()');
                }
                var response = await fetch(url);
                if (!response.ok) {
                    throw new Error('Failed to load ' + url + ': HTTP ' + response.status);
                }
                bundle = await response.json();
            }

            protoRoot = protobuf.Root.fromJSON(bundle);
            /* Validate the type we depend on actually resolved. */
            protoRoot.lookupType('rv.data.Presentation');
            return protoRoot;
        })().catch(function (err) {
            /* Reset so a subsequent init() can retry. */
            initPromise = null;
            throw err;
        });

        return initPromise;
    }

    function ensureReady() {
        if (!protoRoot) throw new Error('iHymnsProPresenter: call init() first');
        return protoRoot;
    }

    /* #1788 — resolve the Presentation message type from whichever schema
       shape init() loaded: the STATIC tree is a plain namespace
       (`root.rv.data.Presentation`), while a REFLECTION Root exposes
       `.lookupType()`. Both message objects carry the same static
       `.create()`/`.encode()` API downstream, so nothing else changes. */
    function getPresentationType() {
        var root = ensureReady();
        if (typeof root.lookupType === 'function') {
            return root.lookupType('rv.data.Presentation');
        }
        var T = root.rv && root.rv.data && root.rv.data.Presentation;
        if (!T) {
            throw new Error('iHymnsProPresenter: rv.data.Presentation missing from static schema');
        }
        return T;
    }

    /* ==================================================================
     *  SECTION 3 — UUID v4 generator
     * ==================================================================
     * Used for every `rv.data.UUID` we mint (presentation, cue, group,
     * slide, slide-element, action). Real ProPresenter documents put a
     * canonical 36-char hyphenated UUID v4 string in `UUID.string`. */

    function uuidV4() {
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return global.crypto.randomUUID();
        }
        var b = new Uint8Array(16);
        if (global.crypto && global.crypto.getRandomValues) {
            global.crypto.getRandomValues(b);
        } else {
            for (var i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
        }
        b[6] = (b[6] & 0x0f) | 0x40;  /* version 4 */
        b[8] = (b[8] & 0x3f) | 0x80;  /* variant 10xx */
        var hex = [];
        for (var j = 0; j < 16; j++) {
            var s = b[j].toString(16);
            if (s.length < 2) s = '0' + s;
            hex.push(s);
        }
        return (
            hex.slice(0, 4).join('') + '-' +
            hex.slice(4, 6).join('') + '-' +
            hex.slice(6, 8).join('') + '-' +
            hex.slice(8, 10).join('') + '-' +
            hex.slice(10, 16).join('')
        );
    }

    function uuidMsg() {
        /* Convenience: the protobuf shape ProPresenter uses everywhere. */
        return { string: uuidV4() };
    }

    /* ==================================================================
     *  SECTION 4 — RTF builder for slide lyric text
     * ==================================================================
     * ProPresenter stores slide text as RTF bytes inside
     * `Graphics.Text.rtf_data`. We emit the minimum subset described
     * in iHymns issue #887: ANSI prefix, lines separated by `\par`,
     * RTF metacharacters (`\`, `{`, `}`) escaped, non-ASCII via
     * `\uN?` (signed-16-bit Unicode form).
     *
     * #1918 — deliberately NOT extended with a font table / `\pard` /
     * `\fs` run here. Now that `makeLyricCue()` sets `text.attributes`
     * (font/colour/paragraph alignment — SECTION 5c), ProPresenter uses
     * THOSE for the element's default styling; the bare RTF stays legible
     * on its own. Adding paragraph/font control words here would be
     * redundant with `attributes` and risks disagreeing with it. It would
     * also break the existing exact-prefix assertions in
     * tests/test-propresenter-export.js (notably the empty-input case,
     * which asserts the WHOLE string is `{\rtf1\ansi\uc1 }` — inserting
     * `\pard` unconditionally would no longer match). */

    function buildRTF(lines) {
        var arr;
        if (Array.isArray(lines)) {
            arr = lines;
        } else if (typeof lines === 'string') {
            arr = lines.split(/\r?\n/);
        } else {
            arr = [''];
        }

        var parts = [];
        for (var i = 0; i < arr.length; i++) {
            var raw = arr[i] == null ? '' : String(arr[i]);
            var escaped = '';
            for (var j = 0; j < raw.length; j++) {
                var ch = raw.charAt(j);
                var code = raw.charCodeAt(j);
                if (ch === '\\') {
                    escaped += '\\\\';
                } else if (ch === '{') {
                    escaped += '\\{';
                } else if (ch === '}') {
                    escaped += '\\}';
                } else if (code > 127) {
                    /* RTF \uN?: N is signed 16-bit, ? is the ANSI
                       fallback char to use if a reader doesn't grok \u. */
                    var signed = code > 32767 ? code - 65536 : code;
                    escaped += '\\u' + signed + '?';
                } else {
                    escaped += ch;
                }
            }
            parts.push(escaped);
        }
        return '{\\rtf1\\ansi\\uc1 ' + parts.join('\\par\n') + '}';
    }

    /* ==================================================================
     *  SECTION 5 — Component → cue/group label mapping
     * ==================================================================
     * Two parallel forms are useful:
     *
     *   `name`  — the full human-readable form ("Verse 1", "Chorus").
     *             Per #887 spec revision, this is what we put in
     *             `cue_groups[].group.name` so ProPresenter's group
     *             palette and arrangement view match what the curator
     *             sees in iHymns.
     *
     *   `short` — the OpenSong-style letter form ("V1", "C", "B").
     *             Used for cue.name and the underlying slide action
     *             name so ProPresenter's keyboard cue palette stays
     *             friendly to fast-fingered operators.
     */

    var COMPONENT_LABEL_MAP = {
        'verse':      { letter: 'V', name: 'Verse' },
        'chorus':     { letter: 'C', name: 'Chorus' },
        'refrain':    { letter: 'C', name: 'Refrain' },
        'bridge':     { letter: 'B', name: 'Bridge' },
        'pre-chorus': { letter: 'P', name: 'Pre-Chorus' },
        'tag':        { letter: 'T', name: 'Tag' },
        'coda':       { letter: 'T', name: 'Coda' },
        'intro':      { letter: 'I', name: 'Intro' },
        'outro':      { letter: 'O', name: 'Outro' },
        'interlude':  { letter: 'I', name: 'Interlude' }
    };

    function componentLabel(comp) {
        var t = (comp.type || 'verse').toLowerCase();
        var m = COMPONENT_LABEL_MAP[t] || { letter: 'V', name: t };
        var hasNum = comp.number != null && comp.number !== '';
        return {
            short: m.letter + (hasNum ? String(comp.number) : ''),
            name: m.name + (hasNum ? ' ' + comp.number : '')
        };
    }

    function getComponentLines(comp) {
        /* iHymns canonical data uses `lines: string[]`; the editor UI
           also persists draft edits as `lyrics: string`. Support both. */
        if (Array.isArray(comp.lines)) return comp.lines;
        if (typeof comp.lyrics === 'string') return comp.lyrics.split(/\r?\n/);
        if (typeof comp.text === 'string') return comp.text.split(/\r?\n/);
        return [''];
    }

    /* ==================================================================
     *  SECTION 5b — Export options & defaults
     * ==================================================================
     * Per #887 spec revision the curator can ask for:
     *   - linesPerSlide  : number (1..N) or 0 / null = "all on one slide"
     *   - preSlideOrder  : 'lyrics' | 'title' | 'title-blank'
     *
     * `normaliseOptions()` is the single chokepoint that turns whatever
     * the caller (modal / CLI / test) hands us into a sane,
     * fully-populated options object so the rest of the pipeline can
     * trust its inputs. */

    var DEFAULT_EXPORT_OPTIONS = {
        linesPerSlide: 0,           /* 0 = no chunking */
        preSlideOrder: 'lyrics'     /* 'lyrics' | 'title' | 'title-blank' */
    };

    function normaliseOptions(options) {
        options = options || {};
        var lps = options.linesPerSlide;
        if (lps === '' || lps == null) lps = 0;
        lps = parseInt(lps, 10);
        if (isNaN(lps) || lps < 0) lps = 0;

        var pso = options.preSlideOrder || DEFAULT_EXPORT_OPTIONS.preSlideOrder;
        if (pso !== 'lyrics' && pso !== 'title' && pso !== 'title-blank') {
            pso = DEFAULT_EXPORT_OPTIONS.preSlideOrder;
        }

        return { linesPerSlide: lps, preSlideOrder: pso };
    }

    /* Split a lines array into N-line chunks, dropping trailing empty
       chunks. Returns `[lines]` (a single-element array) when chunking
       is disabled (linesPerSlide ≤ 0). Always returns at least one
       chunk so a component with no text still produces one slide. */
    function chunkLines(lines, linesPerSlide) {
        if (!Array.isArray(lines) || lines.length === 0) return [['']];
        if (!linesPerSlide || linesPerSlide <= 0) return [lines.slice()];

        var chunks = [];
        for (var i = 0; i < lines.length; i += linesPerSlide) {
            chunks.push(lines.slice(i, i + linesPerSlide));
        }
        return chunks.length ? chunks : [['']];
    }

    /* ==================================================================
     *  SECTION 5c — Slide geometry & default text styling (#1918)
     * ==================================================================
     * ISSUE #1918: exported .pro files opened in ProPresenter 7+ as blank
     * blue slides — no visible lyric text. Root cause per
     * `rv.data.Graphics.Element` (graphicsData.proto line 17-44 —
     * `Graphics.Element.bounds = 3`, type `Graphics.Rect`): every text
     * element `makeLyricCue()` built set NO `bounds`, so the element's
     * frame defaulted to 0×0 — invisible, with nothing for ProPresenter's
     * Reflow layout to fill. The blue is not a background colour we
     * emitted (we still emit none, deliberately — see makeLyricCue() below);
     * it's ProPresenter's own "this slide is empty" placeholder, shown
     * because a 0×0 element renders nothing at all.
     *
     * SLIDE_WIDTH/SLIDE_HEIGHT are standard ProPresenter 16:9 (1920×1080).
     * MARGIN insets the text frame off all four edges (~5%) so lyric text
     * doesn't crowd right up to a projector's clipped edge.
     *
     * DEFAULT_FONT_* / DEFAULT_TEXT_COLOR give the slide legible default
     * styling via `Graphics.Text.Attributes` (graphicsData.proto line 274)
     * even though we still emit no background — an operator applying their
     * own theme/background is the intended workflow (see makeLyricCue()). */
    var SLIDE_WIDTH = 1920;
    var SLIDE_HEIGHT = 1080;
    var MARGIN = 96; /* ≈ 5% of 1920 */

    var DEFAULT_FONT_NAME = 'Arial';
    var DEFAULT_FONT_SIZE = 80; /* pt — worship-legible default (60-90pt range) */
    /* rv.data.Color (color.proto): float red/green/blue/alpha, 0..1. White. */
    var DEFAULT_TEXT_COLOR = { red: 1, green: 1, blue: 1, alpha: 1 };

    /* Horizontal alignment is DELIBERATELY left unset (no
       `Graphics.Text.Attributes.paragraph_style`). Two reasons: (1)
       ProPresenter defaults a text element's horizontal alignment to CENTRE
       already, which is what we want for lyrics; (2) — the load-bearing one —
       a `paragraph_style: { alignment }` sub-message is the ONE field in this
       payload where protobufjs's static (`pbjs -t static`) and reflection
       encoders disagree by 2 bytes per element (isolated empirically), which
       trips the #1788 byte-identical determinism guard. Both encoders produce
       a VALID file that round-trips, but the static/reflection outputs must
       stay identical so the CSP-safe static path is trustworthy. Centre
       alignment therefore rides on ProPresenter's own default; if a real
       ProPresenter check ever shows left-aligned lyrics, add `\qc` to the RTF
       in buildRTF() (RTF-level, no protobuf divergence) rather than
       re-introducing paragraph_style. See tests/test-propresenter-static-csp.js.
       Graphics.Text.VerticalAlignment (graphicsData.proto line 209-213):
       TOP=0, MIDDLE=1, BOTTOM=2. */
    var TEXT_VERTICAL_ALIGNMENT_MIDDLE = 1;
    /* Graphics.Text.ScaleBehavior (graphicsData.proto line 215-222):
       NONE=0, ADJUST_CONTAINER_HEIGHT=1, SCALE_FONT_DOWN=2,
       SCALE_FONT_UP=3, SCALE_FONT_UP_DOWN=4. SCALE_FONT_DOWN so a long
       verse shrinks to fit the bounds instead of overflowing them. */
    var TEXT_SCALE_BEHAVIOR_SCALE_FONT_DOWN = 2;

    /* The default (full-slide-minus-margin) text frame every lyric
       element uses. Built once per encode call by makeLyricCue() rather
       than module-level, purely so nothing here is a shared mutable
       object accidentally reused/mutated across slides — plain data, so
       the cost of rebuilding it is negligible. */
    function defaultTextBounds() {
        return {
            origin: { x: MARGIN, y: MARGIN },
            size: {
                width: SLIDE_WIDTH - (2 * MARGIN),
                height: SLIDE_HEIGHT - (2 * MARGIN)
            }
        };
    }

    /* Default `Graphics.Text.Attributes` (font/colour/centred paragraph)
       applied to every lyric element so ProPresenter has something
       legible to render before an operator's theme takes over. */
    function defaultTextAttributes() {
        return {
            font: { name: DEFAULT_FONT_NAME, size: DEFAULT_FONT_SIZE },
            text_solid_fill: DEFAULT_TEXT_COLOR
            /* paragraph_style deliberately omitted — see the comment on the
               VerticalAlignment constants above (#1788 determinism). */
        };
    }

    /* ==================================================================
     *  SECTION 6 — Plain-object Presentation builder
     * ==================================================================
     * Produces the JS object that protobufjs's `Presentation.create()`
     * accepts. Every field name and shape matches the schema in
     * `protos/proto-7.16/presentation.proto`; protobufjs validates them
     * via `Presentation.verify()` before encoding. */

    function buildCCLIPayload(song) {
        var writers = (song.writers || []).filter(Boolean).join(', ');
        var composers = (song.composers || []).filter(Boolean).join(', ');
        var author = '';
        if (writers && composers && writers !== composers) {
            author = writers + ' / ' + composers;
        } else {
            author = writers || composers;
        }

        var artist = '';
        if (Array.isArray(song.artists)) {
            artist = song.artists.filter(Boolean).join(', ');
        } else if (typeof song.artist === 'string') {
            artist = song.artist;
        }

        var ccli = { display: true };
        if (author) ccli.author = author;
        if (artist) ccli.artist_credits = artist;       /* the new #587 field */
        if (song.title) ccli.song_title = song.title;
        if (song.copyright) ccli.publisher = song.copyright;

        var yearMatch = (song.copyright || '').match(/\b(19|20)\d{2}\b/);
        if (yearMatch) ccli.copyright_year = parseInt(yearMatch[0], 10);

        if (song.ccli) {
            var digits = String(song.ccli).replace(/\D/g, '');
            if (digits.length > 0) {
                var n = parseInt(digits, 10);
                if (!isNaN(n) && n > 0) ccli.song_number = n;
            }
        }
        return ccli;
    }

    /* Build one (cue, slide-action) pair for a single slide's RTF body.
       Returns `{ cueId, cue }` so the caller can push the cue into the
       presentation and reference its UUID from the parent cue_group.

       #1918 THE FIX: the element now carries `bounds` (a real, non-zero
       frame — see SECTION 5c) and `text.attributes` (font/colour/centred
       paragraph) so ProPresenter has something to actually lay out and
       render, plus `vertical_alignment`/`scale_behavior` so a long verse
       centres and shrinks-to-fit rather than overflowing. `base_slide`
       also now carries `size` (`rv.data.Slide.size`, slide.proto line 19
       — the per-slide canvas the element's `bounds` are laid out inside),
       matching the same SLIDE_WIDTH/SLIDE_HEIGHT the element frame is
       computed from.

       Deliberately still NO background (no `Slide.background_color`, no
       `draws_background_color`, no `Presentation.background`): the slide
       stays transparent so the operator's own theme/background shows
       through underneath in ProPresenter's Look/Slide compositing — see
       the doc-block on SECTION 5c for why the blue this issue reported
       was never a background WE emitted. */
    function makeLyricCue(name, rtfString) {
        var cueUuid = uuidMsg();
        var actionUuid = uuidMsg();
        var elementUuid = uuidMsg();
        var slideUuid = uuidMsg();
        var rtfBytes = new TextEncoder().encode(rtfString);

        var action = {
            uuid: actionUuid,
            name: name,
            isEnabled: true,
            type: ACTION_TYPE_PRESENTATION_SLIDE,
            slide: {
                presentation: {
                    base_slide: {
                        uuid: slideUuid,
                        size: { width: SLIDE_WIDTH, height: SLIDE_HEIGHT },
                        elements: [{
                            element: {
                                uuid: elementUuid,
                                name: 'Lyrics',
                                bounds: defaultTextBounds(),
                                text: {
                                    rtf_data: rtfBytes,
                                    attributes: defaultTextAttributes(),
                                    vertical_alignment: TEXT_VERTICAL_ALIGNMENT_MIDDLE,
                                    scale_behavior: TEXT_SCALE_BEHAVIOR_SCALE_FONT_DOWN
                                }
                            }
                        }]
                    }
                }
            }
        };

        return {
            cueId: cueUuid,
            cue: {
                uuid: cueUuid,
                name: name,
                actions: [action],
                isEnabled: true
            }
        };
    }

    function buildPresentationPayload(song, options) {
        if (!song || typeof song !== 'object') {
            throw new Error('buildPresentation: song must be an object');
        }
        options = normaliseOptions(options);

        var components = song.components || [];
        if (components.length === 0) {
            /* Guarantee at least one slide so ProPresenter has
               something to render rather than an empty document. */
            components = [{ type: 'verse', number: 1, lines: [''] }];
        }

        var cues = [];
        var cue_groups = [];

        /* ---- Optional Title and Blank pre-slides --------------------
           These are emitted as their own cue_groups labelled "Title"
           and "Blank" so the operator can advance through them or skip
           them per service. The Title slide carries the song title +
           a credit line; the Blank slide is empty RTF (operator can
           drop their own background underneath). */
        if (options.preSlideOrder === 'title' || options.preSlideOrder === 'title-blank') {
            var titleLines = [song.title || 'Untitled'];
            var creditLine = (song.writers || []).filter(Boolean).join(', ');
            if (creditLine) titleLines.push('', creditLine);
            var titleCue = makeLyricCue('Title', buildRTF(titleLines));
            cues.push(titleCue.cue);
            cue_groups.push({
                group: { uuid: uuidMsg(), name: 'Title' },
                cue_identifiers: [titleCue.cueId]
            });
        }
        if (options.preSlideOrder === 'title-blank') {
            var blankCue = makeLyricCue('Blank', buildRTF(['']));
            cues.push(blankCue.cue);
            cue_groups.push({
                group: { uuid: uuidMsg(), name: 'Blank' },
                cue_identifiers: [blankCue.cueId]
            });
        }

        /* ---- One cue_group per component ---------------------------
           When linesPerSlide is set, we emit multiple cues under the
           same cue_group so the group still represents the whole
           component but ProPresenter advances slide-by-slide through
           the chunks. */
        for (var i = 0; i < components.length; i++) {
            var comp = components[i];
            var label = componentLabel(comp);
            var lineChunks = chunkLines(getComponentLines(comp), options.linesPerSlide);

            var memberCueIds = [];
            for (var c = 0; c < lineChunks.length; c++) {
                /* Action / cue name uses the short label form (V1, C);
                   when chunking, append the chunk index for clarity
                   inside ProPresenter's cue palette. */
                var actionName = label.short + (lineChunks.length > 1 ? '.' + (c + 1) : '');
                var lyricCue = makeLyricCue(actionName, buildRTF(lineChunks[c]));
                cues.push(lyricCue.cue);
                memberCueIds.push(lyricCue.cueId);
            }

            cue_groups.push({
                group: {
                    uuid: uuidMsg(),
                    /* Per #887 spec revision: full human-readable name
                       in the cue_group's group.name. */
                    name: label.name
                },
                cue_identifiers: memberCueIds
            });
        }

        /* ---- Default arrangement -----------------------------------
           Emit one arrangement named "Default" that lists every
           cue_group in the order we just built, and mark it as the
           selected_arrangement so ProPresenter opens with this order
           pre-selected. Future work: read song.arrangements[] from
           iHymns once the data model gains them. */
        var arrangementUuid = uuidMsg();
        var arrangement = {
            uuid: arrangementUuid,
            name: 'Default',
            group_identifiers: cue_groups.map(function (cg) { return cg.group.uuid; })
        };

        return {
            uuid: uuidMsg(),
            name: song.title || 'Untitled',
            category: 'Song',
            notes: song.notes || '',
            cue_groups: cue_groups,
            cues: cues,
            arrangements: [arrangement],
            selected_arrangement: arrangementUuid,
            ccli: buildCCLIPayload(song)
        };
    }

    /* ==================================================================
     *  SECTION 7 — Encode via protobufjs
     * ================================================================== */

    async function buildPresentation(song, options) {
        if (!protoRoot) await init();
        var Presentation = getPresentationType();
        var payload = buildPresentationPayload(song, options);

        /* #1788 — the static (CSP-safe) build drops `.verify()` to save ~500 KB
           (`pbjs --no-verify`); the payload is built entirely by
           buildPresentationPayload() from our own schema-shaped code, never from
           user input, so the pre-encode guard was low value. A genuinely
           malformed field still throws inside encode() below. */
        var message = Presentation.create(payload);
        var buffer = Presentation.encode(message).finish();

        /* protobufjs returns a Uint8Array in browsers and a Buffer in
           Node. Normalise to a fresh Uint8Array so callers always get
           the same concrete type back. */
        return new Uint8Array(buffer.buffer, buffer.byteOffset, buffer.byteLength);
    }

    /* ==================================================================
     *  SECTION 8 — Filename helpers
     * ==================================================================
     * Per the #887 spec revision the filename schemes are:
     *
     *   Single song (no tune):   `<Number> (<SongbookAbbrev>) - <Title>`
     *   Single song (with tune): `<Number> (<SongbookAbbrev>) - <Title> (<Tune>)`
     *   Bulk archive:            `<Songbook Title> (<Songbook Abbrev>) [Bundle]`
     *
     * The tune-suffix variant is wired up but inert until iHymns gains
     * a `tuneTitle` (or equivalent) field on songs — see #887 comment. */

    var ILLEGAL_FS_CHARS = /[\x00-\x1f\\\/:*?"<>|]/g;

    function sanitizeFilename(name) {
        var s = String(name == null ? '' : name)
            .replace(ILLEGAL_FS_CHARS, '')
            .replace(/\s+/g, ' ')
            .trim();
        if (s.length > 120) s = s.substring(0, 120).trim();
        return s || 'Untitled';
    }

    /* Pull a tune title off the song record using a small set of
       plausible field names. Returns '' when no such field is set. */
    function getTuneTitle(song) {
        if (!song || typeof song !== 'object') return '';
        var candidates = ['tuneTitle', 'tune_title', 'tune', 'tuneName', 'tune_name'];
        for (var i = 0; i < candidates.length; i++) {
            var v = song[candidates[i]];
            if (typeof v === 'string' && v.trim() !== '') return v.trim();
        }
        return '';
    }

    /* Compute how many digits to pad song numbers to so that
       lexicographic sort order in ProPresenter libraries matches
       numeric sort. The width is the digit count of the highest song
       number in the songbook — e.g. CP has 243 songs → pad to 3
       digits ("001"), MP has 3,517 songs → pad to 4 digits ("0001").
       Pass any of:
         - a songbook record `{ songCount, count, ... }`
         - a number (the max song number / song count)
         - an array of songs (we'll take the max of `song.number`)
       Returns 0 when the input is missing or non-positive — callers
       treat 0 as "no padding". */
    function paddingFor(input) {
        var n = 0;
        if (input == null) {
            n = 0;
        } else if (typeof input === 'number') {
            n = input;
        } else if (Array.isArray(input)) {
            for (var i = 0; i < input.length; i++) {
                var v = input[i] && parseInt(input[i].number, 10);
                if (!isNaN(v) && v > n) n = v;
            }
        } else if (typeof input === 'object') {
            n = input.songCount || input.count || input.maxSongNumber || 0;
        }
        if (!n || n <= 0) return 0;
        return String(Math.floor(n)).length;
    }

    function padSongNumber(num, width) {
        if (num == null || num === '') return '';
        var s = String(num);
        if (!width || width <= s.length) return s;
        var pad = '';
        for (var i = 0; i < width - s.length; i++) pad += '0';
        return pad + s;
    }

    function buildFilename(song, options) {
        options = options || {};
        var ext = options.extension || '.pro';
        var title = song.title || 'Untitled';
        var tune = getTuneTitle(song);
        var sb = song.songbook;
        var num = song.number;
        /* `padNumber` is the digit count to zero-pad to (e.g. 3 → "001"
           for a 243-song book, 4 → "0001" for a 3,500-song book).
           Computed by callers via `paddingFor(songbook)` and threaded
           through so files sort numerically in ProPresenter libraries. */
        var pad = parseInt(options.padNumber, 10) || 0;

        /* Compose: "<Number> (<SB>) - <Title>" with optional tune. */
        var head = '';
        if (num != null && num !== '') head += padSongNumber(num, pad);
        if (sb) head += (head ? ' ' : '') + '(' + sb + ')';

        var nameCore = title + (tune ? ' (' + tune + ')' : '');
        var combined = head ? (head + ' - ' + nameCore) : nameCore;
        return sanitizeFilename(combined) + ext;
    }

    /* Bulk archive filename. Falls back gracefully when the curator
       hasn't filtered to a single songbook. Extensions live alongside
       the format (`.zip`, `.probundle`). */
    function buildBundleFilename(options) {
        options = options || {};
        var ext = options.extension || '.zip';
        var sbName = options.songbookName || '';
        var sbAbbrev = options.songbookAbbrev || '';
        var stem;
        if (sbName && sbAbbrev) {
            stem = sbName + ' (' + sbAbbrev + ') [Bundle]';
        } else if (sbName) {
            stem = sbName + ' [Bundle]';
        } else if (sbAbbrev) {
            stem = sbAbbrev + ' [Bundle]';
        } else {
            stem = 'iHymns ProPresenter Bundle ' + new Date().toISOString().substring(0, 10);
        }
        return sanitizeFilename(stem) + ext;
    }

    /* ==================================================================
     *  SECTION 9 — Stored-mode ZIP writer
     * ==================================================================
     * Bulk export packages every song as one .pro file inside a single
     * download. We write a minimal "stored" (no DEFLATE) ZIP entirely
     * in JS — protobuf is already binary-compact and DEFLATE on top
     * adds little for a few-hundred-KB catalogue. */

    var CRC_TABLE = (function () {
        var t = new Uint32Array(256);
        for (var i = 0; i < 256; i++) {
            var c = i;
            for (var k = 0; k < 8; k++) {
                c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
            }
            t[i] = c >>> 0;
        }
        return t;
    })();

    function crc32(bytes) {
        var c = 0xffffffff;
        for (var i = 0; i < bytes.length; i++) {
            c = CRC_TABLE[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
        }
        return (c ^ 0xffffffff) >>> 0;
    }

    function dosTime(date) {
        var t = ((date.getHours() & 0x1f) << 11) |
                ((date.getMinutes() & 0x3f) << 5) |
                ((Math.floor(date.getSeconds() / 2)) & 0x1f);
        var d = (((date.getFullYear() - 1980) & 0x7f) << 9) |
                (((date.getMonth() + 1) & 0x0f) << 5) |
                (date.getDate() & 0x1f);
        return { time: t & 0xffff, date: d & 0xffff };
    }

    function writeU16(arr, val) {
        arr.push(val & 0xff);
        arr.push((val >>> 8) & 0xff);
    }
    function writeU32(arr, val) {
        arr.push(val & 0xff);
        arr.push((val >>> 8) & 0xff);
        arr.push((val >>> 16) & 0xff);
        arr.push((val >>> 24) & 0xff);
    }

    function buildZip(files) {
        var now = dosTime(new Date());
        var localChunks = [];
        var central = [];
        var offset = 0;

        files.forEach(function (f) {
            var nameBytes = new TextEncoder().encode(f.name);
            var crc = crc32(f.bytes);
            var size = f.bytes.length;

            /* Local file header */
            var lfh = [];
            writeU32(lfh, 0x04034b50);
            writeU16(lfh, 20);
            writeU16(lfh, 0);
            writeU16(lfh, 0);
            writeU16(lfh, now.time);
            writeU16(lfh, now.date);
            writeU32(lfh, crc);
            writeU32(lfh, size);
            writeU32(lfh, size);
            writeU16(lfh, nameBytes.length);
            writeU16(lfh, 0);
            var lfhBytes = new Uint8Array(lfh);

            var localTotal = new Uint8Array(lfhBytes.length + nameBytes.length + size);
            localTotal.set(lfhBytes, 0);
            localTotal.set(nameBytes, lfhBytes.length);
            localTotal.set(f.bytes, lfhBytes.length + nameBytes.length);
            localChunks.push(localTotal);

            /* Central directory entry */
            var cdh = [];
            writeU32(cdh, 0x02014b50);
            writeU16(cdh, 20);
            writeU16(cdh, 20);
            writeU16(cdh, 0);
            writeU16(cdh, 0);
            writeU16(cdh, now.time);
            writeU16(cdh, now.date);
            writeU32(cdh, crc);
            writeU32(cdh, size);
            writeU32(cdh, size);
            writeU16(cdh, nameBytes.length);
            writeU16(cdh, 0);
            writeU16(cdh, 0);
            writeU16(cdh, 0);
            writeU16(cdh, 0);
            writeU32(cdh, 0);
            writeU32(cdh, offset);
            var cdhBytes = new Uint8Array(cdh);
            var cdhTotal = new Uint8Array(cdhBytes.length + nameBytes.length);
            cdhTotal.set(cdhBytes, 0);
            cdhTotal.set(nameBytes, cdhBytes.length);
            central.push(cdhTotal);

            offset += localTotal.length;
        });

        var cdSize = central.reduce(function (s, c) { return s + c.length; }, 0);
        var cdOffset = offset;

        var eocd = [];
        writeU32(eocd, 0x06054b50);
        writeU16(eocd, 0);
        writeU16(eocd, 0);
        writeU16(eocd, files.length);
        writeU16(eocd, files.length);
        writeU32(eocd, cdSize);
        writeU32(eocd, cdOffset);
        writeU16(eocd, 0);

        var total = 0;
        localChunks.forEach(function (c) { total += c.length; });
        total += cdSize + eocd.length;

        var out = new Uint8Array(total);
        var pos = 0;
        localChunks.forEach(function (c) {
            out.set(c, pos);
            pos += c.length;
        });
        central.forEach(function (c) {
            out.set(c, pos);
            pos += c.length;
        });
        out.set(new Uint8Array(eocd), pos);
        return out;
    }

    /* ==================================================================
     *  SECTION 10 — Browser download helper
     * ================================================================== */

    function triggerDownload(bytes, filename, mime) {
        if (typeof document === 'undefined' || typeof URL === 'undefined') return;
        var blob = new Blob([bytes], { type: mime || 'application/octet-stream' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /* ==================================================================
     *  SECTION 11 — Public API
     * ================================================================== */

    function ensureUniqueNames(files) {
        var seen = Object.create(null);
        for (var i = 0; i < files.length; i++) {
            var name = files[i].name;
            if (!seen[name]) {
                seen[name] = 1;
            } else {
                var dot = name.lastIndexOf('.');
                var stem = dot === -1 ? name : name.substring(0, dot);
                var ext = dot === -1 ? '' : name.substring(dot);
                var suffix;
                do {
                    suffix = ' (' + seen[name]++ + ')';
                } while (seen[stem + suffix + ext]);
                files[i].name = stem + suffix + ext;
                seen[files[i].name] = 1;
            }
        }
        return files;
    }

    async function exportSong(song, options) {
        if (!song) throw new Error('exportSong: song argument is required');
        options = options || {};
        var bytes = await buildPresentation(song, options);
        var filename = buildFilename(song, {
            extension: '.pro',
            padNumber: options.padNumber
        });
        triggerDownload(bytes, filename, 'application/octet-stream');
        return { filename: filename, size: bytes.length };
    }

    /* Internal: build the per-song .pro byte set + filenames for a
       bulk export, ready to be wrapped in either a plain ZIP or a
       .probundle. Pulled out so .zip and .probundle share their
       per-song work.

       Padding strategy: when the caller passes `options.padNumber`
       directly we honour it. Otherwise we group songs by their
       `songbook` and compute the per-songbook digit width from the
       highest song number we see in the bulk subset — so a Mission
       Praise (3,517-song) export pads to 4 digits and a Carol Praise
       (243-song) export pads to 3, even when the same export bundle
       crosses both.

       #1571 — this is the ONE builder both bulk formats (.zip via
       exportAllAsZip, .probundle via exportAllAsBundle) and both surfaces
       (the public export-ui.js, the Song Editor) funnel through, so the
       progress + yield behaviour below covers all of them at once:
         - `options.onProgress?: (done, total) => void` — invoked once per
           encoded song, in its OWN try/catch: a UI callback that throws must
           never kill the export itself.
         - a cooperative MACROTASK yield every 25 songs. `await
           buildPresentation(...)` above already yields, but only to the
           MICROTASK queue (a resolved-promise `await` never lets the browser
           paint or handle input) — a real `setTimeout(fn, 0)` is required to
           actually reach the macrotask queue, which is what lets a progress
           toast paint and keeps the page responsive during a large
           (thousands-of-songs) encode instead of looking hung. */
    async function buildBulkFiles(songs, options) {
        if (!Array.isArray(songs) || songs.length === 0) {
            throw new Error('buildBulkFiles: songs must be a non-empty array');
        }
        if (!protoRoot) await init();
        options = options || {};

        var paddingBySongbook = Object.create(null);
        if (options.padNumber) {
            paddingBySongbook['*'] = parseInt(options.padNumber, 10) || 0;
        } else {
            /* If the caller passed an explicit songbookSize hint use
               that, otherwise compute per-songbook from the subset. */
            var maxBySongbook = Object.create(null);
            for (var k = 0; k < songs.length; k++) {
                var s = songs[k];
                var key = s.songbook || '*';
                var n = parseInt(s.number, 10);
                if (!isNaN(n) && n > (maxBySongbook[key] || 0)) {
                    maxBySongbook[key] = n;
                }
            }
            if (options.songbookSize) {
                /* Treat the hint as the upper bound (e.g. when
                   filtering to one songbook the caller passes its
                   total songCount, not just the subset's max). */
                paddingBySongbook['*'] = paddingFor(options.songbookSize);
            }
            Object.keys(maxBySongbook).forEach(function (k) {
                if (paddingBySongbook[k] == null) {
                    paddingBySongbook[k] = paddingFor(maxBySongbook[k]);
                }
            });
        }

        function widthFor(song) {
            var key = song.songbook || '*';
            return paddingBySongbook[key] != null
                ? paddingBySongbook[key]
                : (paddingBySongbook['*'] || 0);
        }

        var onProgress = typeof options.onProgress === 'function' ? options.onProgress : null;

        var files = [];
        for (var i = 0; i < songs.length; i++) {
            var song = songs[i];
            files.push({
                name: buildFilename(song, {
                    extension: '.pro',
                    padNumber: widthFor(song)
                }),
                bytes: await buildPresentation(song, options)
            });

            if (onProgress) {
                try {
                    onProgress(i + 1, songs.length);
                } catch (progressErr) {
                    /* #1571 — a UI callback error must never kill the export. */
                }
            }

            /* #1571 — cooperative MACROTASK yield every 25 songs (see the
               doc-comment above buildBulkFiles() for why a plain `await`
               isn't enough on its own). */
            if ((i + 1) % 25 === 0) {
                await new Promise(function (resolve) { setTimeout(resolve, 0); });
            }
        }
        ensureUniqueNames(files);
        return files;
    }

    /* Bulk export → plain ZIP of `.pro` files. */
    async function exportAllAsZip(songs, options) {
        options = options || {};
        var files = await buildBulkFiles(songs, options);
        var zipBytes = buildZip(files);
        var zipName = options.zipName
            ? sanitizeFilename(options.zipName) + '.zip'
            : buildBundleFilename({
                extension: '.zip',
                songbookName: options.songbookName,
                songbookAbbrev: options.songbookAbbrev
            });
        triggerDownload(zipBytes, zipName, 'application/zip');
        return { filename: zipName, size: zipBytes.length, count: files.length };
    }

    /* Bulk export → ProPresenter `.probundle`. The bundle format is a
       ZIP with the `.probundle` extension, conventionally laid out
       with documents under `Documents/` and a top-level
       `manifest.json` describing the contents. We follow that layout
       so a curator can also unzip and inspect the contents with any
       standard ZIP tool, and so ProPresenter's existing import path
       (which scans for `.pro` files inside) can find them.

       Notes / known unknowns:
       - We don't bundle media (backgrounds, videos, audio) — iHymns
         doesn't have any. ProPresenter will fall back to the receiving
         template's media references when the bundle is opened.
       - The exact manifest schema is not publicly documented; the
         file is intentionally minimal. ProPresenter ignores unknown
         keys and treats the bundle as a folder of documents. */
    async function exportAllAsBundle(songs, options) {
        options = options || {};
        var files = await buildBulkFiles(songs, options);

        /* Re-prefix every entry under "Documents/" so the bundle has
           the conventional layout. */
        var bundleEntries = files.map(function (f) {
            return { name: 'Documents/' + f.name, bytes: f.bytes };
        });

        /* Minimal manifest — purely informational; ProPresenter does
           not require a specific schema for `.probundle` import. */
        var manifest = {
            generator: 'iHymns Song Editor',
            generatedAt: new Date().toISOString(),
            schema: 'rv.data.Presentation (Proto 7.16)',
            songbook: options.songbookAbbrev || null,
            songbookName: options.songbookName || null,
            documentCount: files.length,
            documents: files.map(function (f) {
                return { path: 'Documents/' + f.name, bytes: f.bytes.length };
            })
        };
        bundleEntries.unshift({
            name: 'manifest.json',
            bytes: new TextEncoder().encode(JSON.stringify(manifest, null, 2))
        });

        var zipBytes = buildZip(bundleEntries);
        var bundleName = options.bundleName
            ? sanitizeFilename(options.bundleName) + '.probundle'
            : buildBundleFilename({
                extension: '.probundle',
                songbookName: options.songbookName,
                songbookAbbrev: options.songbookAbbrev
            });
        /* `application/zip` is the closest registered MIME type;
           ProPresenter goes by extension so the browser's MIME label
           is largely informational. */
        triggerDownload(zipBytes, bundleName, 'application/zip');
        return { filename: bundleName, size: zipBytes.length, count: files.length };
    }

    var api = {
        init: init,
        exportSong: exportSong,
        exportAllAsZip: exportAllAsZip,
        exportAllAsBundle: exportAllAsBundle,
        buildPresentation: buildPresentation,
        buildFilename: buildFilename,
        buildBundleFilename: buildBundleFilename,
        buildRTF: buildRTF,
        /* `paddingFor(songbookOrCount)` is exported so callers can
           compute the right zero-pad width once and thread it into
           `exportSong({ padNumber })` for single-song exports. */
        paddingFor: paddingFor,
        DEFAULT_EXPORT_OPTIONS: DEFAULT_EXPORT_OPTIONS,
        /* Internals exposed primarily for unit tests. */
        _internal: {
            uuidV4: uuidV4,
            uuidMsg: uuidMsg,
            crc32: crc32,
            buildZip: buildZip,
            componentLabel: componentLabel,
            buildPresentationPayload: buildPresentationPayload,
            buildCCLIPayload: buildCCLIPayload,
            normaliseOptions: normaliseOptions,
            chunkLines: chunkLines,
            getTuneTitle: getTuneTitle,
            paddingFor: paddingFor,
            padSongNumber: padSongNumber,
            buildBulkFiles: buildBulkFiles,
            getRoot: function () { return protoRoot; },
            resetForTests: function () { protoRoot = null; initPromise = null; }
        }
    };

    if (typeof global !== 'undefined') {
        global.iHymnsProPresenter = api;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
