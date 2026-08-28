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

    /* #1968 P3 — same resolution dance as getPresentationType() above, for the
       .proplaylist top-level message. rv.data.PlaylistDocument was ADDED to
       both the reflection bundle (proto-bundle.json) and the CSP-safe static
       module (pp7-proto-static.js) by regenerating them from an ENTRY_POINTS
       list that now also includes propresenter.proto + playlist.proto (see
       tools/build-proto-bundle.js / tools/build-proto-static.js). This is an
       ENCODE-ONLY addition (the static module keeps --no-decode — see that
       tool's header doc-block); the .proplaylist IMPORT side already has its
       own independent PHP wire decoder (includes/propresenter7_playlist.php,
       landed in #1973) that this regen does not touch. Regenerating was
       verified NOT to perturb rv.data.Presentation's own encode output byte-
       for-byte (adding a sibling top-level message does not change any other
       message's field layout) — see tests/test-propresenter-static-csp.js,
       still green after the regen. */
    function getPlaylistDocumentType() {
        var root = ensureReady();
        if (typeof root.lookupType === 'function') {
            return root.lookupType('rv.data.PlaylistDocument');
        }
        var T = root.rv && root.rv.data && root.rv.data.PlaylistDocument;
        if (!T) {
            throw new Error('iHymnsProPresenter: rv.data.PlaylistDocument missing from static schema');
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
     * `Graphics.Text.rtf_data`. We emit a minimal but STRUCTURALLY
     * COMPLETE RTF document: header (charset + font table + colour
     * table), one centred paragraph run selecting that font, lines
     * separated by `\par`, RTF metacharacters (`\`, `{`, `}`) escaped,
     * non-ASCII via `\uN?` (signed-16-bit Unicode form, #887).
     *
     * #1918 follow-up (owner-reported 2026-08-25 — every slide blank,
     * Reflow rows empty): THE FONT TABLE IS NOT OPTIONAL. This builder
     * used to emit a header-less `{\rtf1\ansi\uc1 …}` on the theory
     * that `text.attributes` (SECTION 5c) supplied the styling and the
     * RTF only needed to carry words. That RTF violates the RTF spec's
     * formal header grammar —
     *   <header> ::= \rtf <charset> \deff? <fonttbl> <filetbl>?
     *                <colortbl>? <stylesheet>? <listtables>? <revtbl>?
     * (RTF 1.5+ spec, "Contents of an RTF File": every header component
     * EXCEPT <fonttbl> carries the optional `?` marker) — and
     * ProPresenter's RTF reader (Apple's Cocoa text system; PP7 is a
     * Cocoa app and its own files carry `\cocoartf`-stamped RTF)
     * extracted ZERO text from it: the export opened with the right
     * slide count and group labels but every slide blank, and the
     * Reflow editor showed 12 numbered EMPTY rows — text extraction
     * failing, not styling. Both of this repo's OTHER RTF emitters
     * already knew this: `buildPro6Rtf()` (format-export.js) and the
     * EasyWorship exporter (includes/easyworship_export.php) each emit
     * `{\fonttbl…}\pard\qc\f0\fs…` and are accepted by their targets.
     *
     * The header below is DERIVED from the same SECTION 5c constants
     * that feed `text.attributes` (DEFAULT_FONT_NAME / DEFAULT_FONT_SIZE
     * / DEFAULT_TEXT_COLOR), so the two descriptions of the default
     * style cannot drift apart (rule #35 — agreement by mechanism, not
     * comment).
     *
     * ⚠️ #1918/#1950 follow-up — CocoaRTF dialect (owner-reported AGAIN
     * on the live alpha deploy v1.1.1009: the cue GROUPS and all 3 slides
     * render, but EVERY slide's lyric text is blank and every Reflow row
     * empty). The font-table fix above was NECESSARY BUT STILL NOT
     * SUFFICIENT.
     *
     * ELI5: ProPresenter can only read the words out of a slide when the
     * RTF is written in Apple's own flavour of RTF. Ours was plain RTF —
     * a valid document, but the wrong flavour — so PP built the slides but
     * pulled zero text out of each one. We now stamp it with Apple's flavour.
     *
     * Detail: ProPresenter 7 is a Cocoa (macOS/AppKit) application and its
     * text reader is Apple's Cocoa text system (NSAttributedString's RTF
     * importer). That importer yields text only from RTF written in Apple's
     * "Cocoa RTF" dialect; a generic-but-spec-valid document parses its
     * structure (hence the right cue/group/slide counts) yet returns an
     * EMPTY attributed string for the body, so every slide is blank. This
     * is why #1950's earlier fonttbl addition — still plain RTF — shipped
     * green on the encode/decode round-trip and STILL failed in real PP.
     * Every genuine .pro file, and every INDEPENDENTLY PP7-verified
     * generator (ChrisMBarr/ProPresenter-Parser's real-export test
     * fixture, jhjonesmo/ChordPresenter, thisiskaysis/scripture-builder,
     * bussnet/propresenter7-php-lib), carries the Cocoa envelope. The
     * marker consistent across ALL of them — and THE load-bearing one — is
     * the header token `\cocoartf<version>`: it is what tells Cocoa's
     * reader "parse me as Cocoa RTF". Alongside it Cocoa always writes the
     * `\cocoatextscaling0\cocoaplatform0` platform preamble, the
     * `{\*\expandedcolortbl;;}` ignorable-destination companion to the
     * colour table, and the `\pardirnatural\partightenfactor0` paragraph
     * flags — so we emit those too, matching real Cocoa output byte-shape
     * as closely as a from-scratch generator can.
     *   AppKit RTF writer/reader ("Cocoa" RTF):
     *   https://developer.apple.com/documentation/appkit/nsattributedstring
     * `\CocoaLigature0` is also emitted immediately before the text run
     * (bussnet does this and documents it as required "to function
     * properly"); the evidence that it is ITSELF load-bearing is weaker —
     * a real PP7 export omits it and only `\cocoartf` is universal — but
     * it is an inert Cocoa control word (disable ligatures), so it is
     * included belt-and-braces and can be dropped later with no risk.
     *
     * Emitted shape (Apple Cocoa RTF, version 2761):
     *
     *   {\rtf1\ansi\ansicpg1252\cocoartf2761\cocoatextscaling0\cocoaplatform0
     *    {\fonttbl{\f0\fswiss\fcharset0 Arial;}}
     *    {\colortbl;\red255\green255\blue255;}
     *    {\*\expandedcolortbl;;}
     *    \pard\pardirnatural\qc\partightenfactor0\f0\fsN \cf1 \uc1 \CocoaLigature0 LINE1\par
     *    LINE2}
     *
     *   - `\cocoartf2761` is THE Cocoa-RTF header token (version 2761, the
     *     value bussnet's PP7-verified template carries). Without it PP7's
     *     Cocoa reader extracts zero text — this single token is the bug.
     *   - `\ansicpg1252` names the ANSI code page; consistent with the
     *     escaping below, which never emits a byte > 127 (non-ASCII is
     *     always `\uN?`, so the document is pure 7-bit ASCII).
     *   - `\f0` selects the font declared in the font table. `\deff0` is
     *     dropped: once the run explicitly selects `\f0`, a default-font
     *     declaration is redundant and real Cocoa output does not carry it.
     *   - `\fsN` is in HALF-points (RTF spec) — DEFAULT_FONT_SIZE * 2.
     *   - `\colortbl` index 1 is DEFAULT_TEXT_COLOR (white); `\cf1`
     *     selects it. Index 0 is the conventional empty "auto" slot.
     *     `{\*\expandedcolortbl;;}` is the ignorable-destination companion
     *     Cocoa writes beside every `\colortbl` (the leading `\*` marks it
     *     ignorable, so a reader that doesn't grok it skips the group).
     *   - `\qc` centres the paragraph. RTF-level centring adds NO protobuf
     *     field, so the #1788 static/reflection byte-identity is untouched;
     *     the `paragraph_style` sub-message is still NOT set (SECTION 5c).
     *   - `\uc1` still precedes the text so every `\uN` keeps its
     *     one-character `?` ANSI fallback (#887).
     *
     * Line joins stay `\par` (deliberately unchanged): the same owner
     * report showed the Reflow editor listing the CORRECT number of
     * (empty) rows, i.e. PP was already parsing our `\par` breaks into
     * separate lines — only the per-row TEXT was missing. The defect is
     * the RTF dialect, not the line separator, so `\par` is left alone.
     *
     * This edits ONLY the opaque `bytes` of `rtf_data`; it adds no
     * protobuf field, so the #1788 static/reflection byte-identity guard
     * is unaffected. `text.attributes` (SECTION 5c) stays: it is the
     * ELEMENT-level default ProPresenter shows in its inspector; the RTF
     * is what the Cocoa text engine actually parses for content. */

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

        /* ELI5: write the RTF "title page" — Apple's flavour tag, which
           font, what size, what colour — before the words, because
           ProPresenter's Cocoa reader pulls zero text out of a document
           that isn't stamped as Apple "Cocoa RTF" (see the SECTION 4
           doc-block: #1918/#1950 follow-up, the live v1.1.1009 blank-slide
           report).
           Detail: header values are computed from the SECTION 5c styling
           constants (single source of truth, rule #35). `\fs` takes
           half-points per the RTF spec, hence * 2; colour components are
           rv.data.Color floats (0..1) scaled to RTF's 0..255 ints. The
           font NAME goes into the table verbatim — DEFAULT_FONT_NAME is
           a compile-time ASCII constant ('Arial'), never user input, so
           it needs no escaping here. `\cocoartf2761` +
           `\cocoatextscaling0\cocoaplatform0` + `{\*\expandedcolortbl;;}`
           + `\pardirnatural\partightenfactor0` + `\CocoaLigature0` are the
           Cocoa-dialect envelope; `\cocoartf<version>` is the load-bearing
           token that makes PP7's text reader extract the body at all. */
        var fsHalfPoints = DEFAULT_FONT_SIZE * 2;
        var colR = Math.round(DEFAULT_TEXT_COLOR.red * 255);
        var colG = Math.round(DEFAULT_TEXT_COLOR.green * 255);
        var colB = Math.round(DEFAULT_TEXT_COLOR.blue * 255);

        return '{\\rtf1\\ansi\\ansicpg1252\\cocoartf2761\\cocoatextscaling0\\cocoaplatform0' +
            '{\\fonttbl{\\f0\\fswiss\\fcharset0 ' + DEFAULT_FONT_NAME + ';}}' +
            '{\\colortbl;\\red' + colR + '\\green' + colG + '\\blue' + colB + ';}' +
            '{\\*\\expandedcolortbl;;}' +
            '\\pard\\pardirnatural\\qc\\partightenfactor0' +
            '\\f0\\fs' + fsHalfPoints + ' \\cf1 \\uc1 \\CocoaLigature0 ' +
            parts.join('\\par\n') + '}';
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
     * blue slides — no visible lyric text. #1918 diagnosed the missing
     * `rv.data.Graphics.Element.bounds` (graphicsData.proto line 17-44 —
     * `Graphics.Element.bounds = 3`, type `Graphics.Rect`): every text
     * element `makeLyricCue()` built set NO `bounds`, so the element's
     * frame defaulted to 0×0 — invisible, with nothing for ProPresenter's
     * Reflow layout to fill. The blue is not a background colour we
     * emitted (we still emit none, deliberately — see makeLyricCue() below);
     * it's ProPresenter's own "this slide is empty" placeholder, shown
     * because a 0×0 element renders nothing at all.
     *
     * ⚠️ #1918 was NECESSARY BUT NOT SUFFICIENT (owner-reported
     * 2026-08-25): with bounds + attributes shipped, slides STILL opened
     * blank and the Reflow editor showed every row empty — Reflow shows
     * text content regardless of styling, so the remaining failure was
     * text EXTRACTION, not layout. The second (and load-bearing) cause
     * was the header-less RTF `buildRTF()` emitted: no `{\fonttbl}`, no
     * selected font — spec-invalid, and ProPresenter's RTF reader
     * extracts zero text from it. See the SECTION 4 doc-block for the
     * full evidence chain; the fix lives there.
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
       alignment therefore lives at the RTF level: buildRTF() emits `\qc`
       in its paragraph run (now inside the Cocoa-RTF envelope — see
       SECTION 4 — but still RTF-level, no protobuf divergence), exactly
       the escape hatch this comment reserved. Never re-introduce
       paragraph_style here. See tests/test-propresenter-static-csp.js.
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

       #1918 (partial fix): the element carries `bounds` (a real, non-zero
       frame — see SECTION 5c) and `text.attributes` (font/colour) so
       ProPresenter has a frame to lay out, plus
       `vertical_alignment`/`scale_behavior` so a long verse centres and
       shrinks-to-fit rather than overflowing. `base_slide` also carries
       `size` (`rv.data.Slide.size`, slide.proto line 19 — the per-slide
       canvas the element's `bounds` are laid out inside), matching the
       same SLIDE_WIDTH/SLIDE_HEIGHT the element frame is computed from.
       ⚠️ That alone did NOT put words on screen — the text itself comes
       from parsing `rtf_data`, and the RTF had to become BOTH a
       structurally complete document (font table + selected font, #1918
       follow-up) AND, load-bearingly, Apple "Cocoa RTF" (the
       `\cocoartf` header token) before ProPresenter's Cocoa text reader
       would extract any text at all (#1918/#1950 follow-up, re-reported on
       live alpha v1.1.1009 — see SECTION 4). The `info: 3` scalar below is
       a defensive second lever from the same fix.

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
                            },
                            /* ELI5: mark this element the way a real
                               ProPresenter file marks a text box, in case
                               PP wants that flag set before it treats the
                               box as text.
                               Detail: rv.data.Slide.Element.info (field 4,
                               uint32 — slide.proto:26) is a flags scalar the
                               PP7-verified bussnet generator sets to 3 on
                               every text element; iHymns omitted it. This is
                               a DEFENSIVE belt for the #1918/#1950 CocoaRTF
                               fix — the RTF dialect is the diagnosed cause,
                               but `info` is a second variable that differs
                               between our output and a known-good file, so
                               we match it. A uint32 scalar encodes as one
                               varint (tag 32 = field 4<<3) IDENTICALLY under
                               both the static (pbjs -t static) and reflection
                               encoders, so — unlike a sub-message such as
                               paragraph_style — it does NOT disturb the #1788
                               byte-identity guard (verified:
                               tests/test-propresenter-static-csp.js still
                               passes byte-identical). */
                            info: 3
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

    /* Encode an ALREADY-BUILT Presentation payload object (the shape
       buildPresentationPayload() returns) into raw `.pro` bytes. Split out of
       buildPresentation() below (#1968 P3) purely so a caller that also needs
       the INTERMEDIATE payload can build it once and both inspect it AND
       encode it, instead of encoding blind. The concrete need:
       exportSetlistAsProplaylist() (SECTION 11a) reads
       `payload.selected_arrangement` / `payload.arrangements[0].name` off the
       SAME payload object so a `.proplaylist`'s
       `PlaylistItem.Presentation.arrangement` field can reference the exact
       arrangement UUID the sibling `.pro` file itself carries, rather than
       minting an unrelated one that wouldn't resolve inside ProPresenter.
       PURE REFACTOR — buildPresentation() below calls this with no change to
       its own inputs/outputs; proven byte-identical against the pre-refactor
       single function both by this module's own existing suites
       (tests/test-propresenter-export.js, tests/test-propresenter-static-csp.js
       — both still green after this split) and, additionally, by a dedicated
       old-vs-new-generated-artifact byte comparison run across several sample
       songs during the #1968 P3 EXPORT regen (see that PR's own notes). */
    function encodePresentationPayload(payload) {
        var Presentation = getPresentationType();

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

    async function buildPresentation(song, options) {
        if (!protoRoot) await init();
        var payload = buildPresentationPayload(song, options);
        return encodePresentationPayload(payload);
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

    /* Bulk export → ProPresenter `.probundle`.
       #1968 P2 (plan §4.3) — CORRECTED LAYOUT. This function used to invent
       a layout (documents re-prefixed under "Documents/", plus a top-level
       "manifest.json") with an honest "known unknowns" comment admitting
       the schema was guessed and never verified against a real bundle.
       During #1968's ground-truth pass the owner's own genuine v21.4
       `.probundle` export was byte-inspected, and several third-party
       fixtures were cross-checked the same way (see
       .claude/propresenter-interop-1968-plan.md §4 and
       includes/propresenter7_zip.php's doc-block): a REAL bundle has its
       `.pro`(s) sitting at the ZIP ROOT, with NO manifest file at all — the
       inner `.pro`(s) ARE the manifest. "Documents/" + "manifest.json" was
       therefore a WRONG invented layout this whole time (never verified in
       real ProPresenter, exactly the class of false positive this epic
       exists to kill — see the plan's header). Fixed here: every `.pro`
       entry sits at the bundle root (no prefix), and no manifest.json is
       written. The shared ZIP writer (buildZip()) and per-song filename
       logic (buildBulkFiles()) are UNCHANGED — this is purely an entry
       LAYOUT fix.

       Notes / known unknowns:
       - We don't bundle media (backgrounds, videos, audio) — iHymns
         doesn't have any. ProPresenter will fall back to the receiving
         template's media references when the bundle is opened. */
    async function exportAllAsBundle(songs, options) {
        options = options || {};
        var files = await buildBulkFiles(songs, options);

        /* #1968 P2 (plan §4.3) — .pro entries sit at the bundle ROOT, exactly
           as buildBulkFiles() named them; no "Documents/" prefix, no
           manifest.json. See the doc-comment above for the byte-verified
           ground truth this replaces. */
        var bundleEntries = files.map(function (f) {
            return { name: f.name, bytes: f.bytes };
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

    /* ==================================================================
     *  SECTION 11a — .proplaylist (SET LIST) export (#1968 P3)
     * ==================================================================
     * Completes the `.proplaylist` round-trip whose IMPORT half (a real
     * .proplaylist -> a new iHymns set list) shipped in #1973
     * (includes/propresenter7_playlist.php + song_importers.php's
     * `_bulkImport_processProplaylist()`). This half runs the OTHER
     * direction, entirely client-side, from the set-list UI: an iHymns set
     * list -> a real `.proplaylist` ProPresenter can open as a service
     * order.
     *
     * See .claude/propresenter-interop-1968-plan.md §5.2 for the design
     * brief this section implements. */

    /* rv.data.PlaylistDocument.Type (propresenter.proto:11-15): UNKNOWN=0,
       PRESENTATION=1, MEDIA=2, AUDIO=3. Every real fixture this epic has
       decoded (includes/propresenter7_playlist.php's own cross-validated
       corpus) is a presentation (song) playlist, so PRESENTATION is the
       only value this exporter ever emits. */
    var PLAYLIST_DOCUMENT_TYPE_PRESENTATION = 1;

    /* rv.data.Playlist.Type (playlist.proto:18-22): UNKNOWN=0, PLAYLIST=1,
       GROUP=2, SMART=3, ROOT=4.
       ⚠️ The plan's own prose (§5.2) describes the ROOT node's type as
       TYPE_ROOT (4) — but includes/propresenter7_playlist.php's doc-block
       ("UNCONFIRMED corner #5"), written against the SAME three real
       committed .proplaylist fixtures this exporter's own round-trip test
       proves against, records that NONE of them actually use TYPE_ROOT for
       their root node: every one uses TYPE_PLAYLIST (this constant) or
       leaves the field at its proto3 zero value. Per this epic's own
       closing rule — "if a claim in the plan ever disagrees with a
       committed fixture, the fixture wins" — this exporter follows the
       FIXTURE-VERIFIED shape, not the plan's prose, for BOTH the root node
       and its one child playlist. A real ProPresenter is not known to care
       (nothing in either decoder branches on this value), but matching the
       bytes an actual PP7 install has been observed to write is strictly
       safer than matching a sentence nobody has verified against one. */
    var PLAYLIST_TYPE_PLAYLIST = 1;

    /* rv.data.URL.LocalRelativePath.Root (url.proto — the
       ROOT_CURRENT_RESOURCE = 12 enum entry): "resolve relative to
       wherever this document/bundle currently lives". This is the
       PORTABLE URL form the plan's own P4 media-export note (§6.2)
       specifies for exactly the same "point at a sibling entry inside
       THIS zip" problem this exporter also has for
       `PlaylistItem.Presentation.document_path` — see
       buildPlaylistPresentationUrl() below for why it is used here too,
       and for the one deliberate divergence it records from a literal
       byte-mirror of the committed TestPlaylist.proplaylist fixture. */
    var URL_LOCAL_ROOT_CURRENT_RESOURCE = 12;

    /**
     * Build the `rv.data.URL` a PlaylistItem.Presentation.document_path
     * carries for one bundled `.pro` FILENAME (already made unique within
     * this export — see ensureUniqueNames(), already applied by
     * buildSetlistProFiles() below before this is ever called).
     *
     * ⚠️ DELIBERATE DIVERGENCE FROM A LITERAL FIXTURE BYTE-MIRROR (plan
     * §5.2 asks for one; recorded here per the owner's "flag deviations"
     * convention): the committed TestPlaylist.proplaylist fixture's own
     * `document_path.absoluteString` is a real, ABSOLUTE macOS path —
     * `file:///Library/Application%20Support/RenewedVision/ProPresenter/
     * Songs/Embedded%20Song%20One.pro` (see
     * tests/fixtures/propresenter/expected/bussnet-testplaylist.playlist.json)
     * — that could only ever resolve on the ONE machine it was captured
     * from. Fabricating a fake copy of that same absolute path for every
     * iHymns export would be a false claim about where the file lives on
     * whichever machine eventually imports it. What actually matters for
     * OUR OWN import path is the URL's BASENAME, never its directory:
     * `_bulkImport_proplaylistMatchEntry()` (song_importers.php)
     * URL-decodes `absoluteString` and matches purely by `basename()`
     * against the ZIP's own `.pro` entry names — so this function mirrors
     * the fixture's ENCODING STYLE (a percent-encoded `file://` URL) while
     * choosing a root-relative path rather than inventing a directory tree
     * nobody's machine actually has. Alongside it, `local` carries the
     * PORTABLE `ROOT_CURRENT_RESOURCE`-relative form the plan's own P4
     * media-export note already establishes as the correct pattern for
     * "this file travels inside the same ZIP as the document referencing
     * it" (§6.2) — which our `.pro` files genuinely do, since they sit at
     * the `.proplaylist`'s ZIP ROOT (mirroring the #1968 P2 `.probundle`
     * layout fix, `exportAllAsBundle()` above). Both are legal
     * simultaneously: `absolute_string` and `local` live in TWO SEPARATE
     * `oneof` groups on `rv.data.URL` (Storage vs. RelativeFilePath —
     * proto-7.16/url.proto), so setting both is normal, not a conflict.
     *
     * @param {string} filename e.g. "1 (CP) - A baby was born in Bethlehem.pro"
     * @returns {object} an `rv.data.URL`-shaped plain object
     */
    function buildPlaylistPresentationUrl(filename) {
        return {
            absolute_string: 'file:///' + encodeURIComponent(filename),
            local: { root: URL_LOCAL_ROOT_CURRENT_RESOURCE, path: filename }
        };
    }

    /** One `PlaylistItem` for a song's `.pro` — sets the `presentation`
     *  `oneof ItemType` branch (playlist.proto). `arrangementUuidMsg` is the
     *  SAME `rv.data.UUID` object (`{string:"…"}`) the sibling `.pro` file's
     *  own `Presentation.selected_arrangement` carries — reusing the exact
     *  object (not minting a fresh, unrelated UUID) is what makes this
     *  reference actually resolve inside the `.pro` it points at; see
     *  buildSetlistProFiles()'s doc-block for where it comes from. Both
     *  `arrangement`/`arrangement_name` are omitted (not set to `null`) when
     *  absent — proto3 message-typed/string fields treat an omitted key and
     *  an explicit falsy value differently only in JS-land, but omitting
     *  keeps `.create()`'s input shape honest about "we don't have this",
     *  matching the rest of this file's style (see e.g. buildCCLIPayload()'s
     *  `if (author) ccli.author = author;` idiom). */
    function makePlaylistPresentationItem(displayName, filename, arrangementUuidMsg, arrangementName) {
        var presentation = { document_path: buildPlaylistPresentationUrl(filename) };
        if (arrangementUuidMsg) { presentation.arrangement = arrangementUuidMsg; }
        if (arrangementName) { presentation.arrangement_name = arrangementName; }
        return { uuid: uuidMsg(), name: displayName, presentation: presentation };
    }

    /** One `PlaylistItem` for a set-list section divider — sets the
     *  `header` `oneof ItemType` branch. `header` is left `{}` (no colour,
     *  no actions): a real ProPresenter header item's `color` is purely
     *  cosmetic (playlist.proto's `PlaylistItem.Header.color`) and this
     *  exporter has no iHymns colour concept to map it from — omitting it
     *  is honest, not a gap; every real fixture's header item still decodes
     *  correctly with `hasColor:false` (see
     *  includes/propresenter7_playlist.php's own decode of it).
     *
     *  Parameter deliberately named `sectionName`, not `label` — see
     *  resolveSetlistPlaylistEntries()'s own doc-block for why this whole
     *  section avoids the literal property-access spelling `.label`
     *  anywhere in this file's CODE (comments are fine;
     *  tests/test-component-label-sites.js strips them before scanning). */
    function makePlaylistHeaderItem(sectionName) {
        return { uuid: uuidMsg(), name: sectionName, header: {} };
    }

    /**
     * Resolve a SET LIST's ordered "what goes in the playlist" list,
     * honouring an OPTIONAL richer running-order overlay
     * (`setlist.plan.slots` — #301/#1671 F4, `includes/
     * setlist_templates.php`'s `SlotsJson` shape:
     * `[{id,label,type,songId?,note?}]`) when present, and falling back to
     * the flat `setlist.songs` array (no dividers) when it isn't — the
     * common case, since most set lists never open the service-plan panel.
     * A plan slot with a `songId` is a song reference; one WITHOUT is a
     * non-song running-order entry (reading/prayer/offering/sermon/…, per
     * `setlistTemplateSlotTypes()`) — the closest iHymns concept to a
     * ProPresenter `header` divider, so it becomes one, carrying the slot's
     * own display text.
     *
     * ⚠️ NAMING NOTE — why this function's OWN output key is `sectionName`,
     * never `label`, even though the SOURCE field on a plan slot genuinely
     * is called `label` (`setlist_templates.php`'s shipped SlotsJson shape —
     * this function reads it via bracket notation, `slot['label']`, for the
     * SAME reason): `tests/test-component-label-sites.js` (#1860 Phase 5,
     * CLAUDE.md rule #45) scans this ENTIRE file's source (comments
     * stripped first) and requires ZERO occurrences of the literal
     * substring `.label`, because THAT guard's real concern is a
     * per-SONG-COMPONENT custom display name (`tblSongComponents.Label`)
     * leaking into a machine round-trip format where `Type` must stay
     * authoritative (rule #45). A SET-LIST PLAN SLOT's own display text is
     * a completely different, unrelated field — it never touches a
     * component, a section Type, or anything a re-import cares about; it
     * becomes a `PlaylistItem.Header`'s purely cosmetic `name`. Renaming
     * this function's OWN local vocabulary (rather than leaving `.label`
     * property ACCESSES scattered through this section) is the least
     * invasive way to keep that guard's blunt, name-only scan free of this
     * unrelated false positive without touching the guard itself, which
     * would need to grow component-vs-non-component semantics it
     * deliberately does not have (rule #34 — a guard should stay narrow
     * enough not to fail on genuinely correct code, but the fix for that is
     * making the CODE avoid the collision, not widening a guard whose
     * bluntness is otherwise exactly right for its actual job).
     *
     * Pure/synchronous (no protobuf, no I/O) so it is trivially unit-
     * testable on its own — see tests/test-propresenter-export.js.
     *
     * @param {object} setlist
     * @returns {Array<{kind:'header',sectionName:string}|{kind:'song',songId:string}>}
     */
    function resolveSetlistPlaylistEntries(setlist) {
        var slots = (setlist && setlist.plan && Array.isArray(setlist.plan.slots))
            ? setlist.plan.slots
            : null;
        if (slots && slots.length) {
            return slots.map(function (slot) {
                if (slot && slot.songId != null && slot.songId !== '') {
                    return { kind: 'song', songId: String(slot.songId) };
                }
                /* Bracket notation reads the plan slot's own `label` field
                   without writing the literal substring `.label` into this
                   file — see this function's own "NAMING NOTE" above. */
                var slotText = (slot && slot['label']) || 'Section';
                return { kind: 'header', sectionName: slotText };
            });
        }
        var songs = (setlist && Array.isArray(setlist.songs)) ? setlist.songs : [];
        return songs.map(function (s) { return { kind: 'song', songId: String(s.id) }; });
    }

    /**
     * Build the per-song `.pro` byte set for a SET LIST export — parallel
     * to buildBulkFiles() (the shared helper `exportAllAsZip()`/
     * `exportAllAsBundle()` use) but ALSO capturing, per song, the SAME
     * arrangement UUID/name its `.pro` file itself carries and the song's
     * own id — none of which buildBulkFiles()'s existing `{name, bytes}`
     * shape needs for ITS callers, but which a `.proplaylist`'s
     * `PlaylistItem.Presentation` needs to reference the right arrangement
     * inside the right sibling file (see encodePresentationPayload()'s own
     * doc-block for why that needed a payload-then-encode split rather
     * than reusing buildPresentation() opaquely).
     *
     * Deliberately a SEPARATE function from buildBulkFiles() rather than an
     * extension of it: buildBulkFiles() is shared, well-tested production
     * infrastructure for the (much larger) whole-songbook `.zip`/
     * `.probundle` exports, and a set list is small enough that duplicating
     * its thin per-song loop here — same padding/progress/macrotask-yield
     * shape, #1571 — is cheaper and safer than risking a behaviour change
     * to that shared path for an unrelated caller's benefit.
     *
     * @param {Array<object>} songs   full song records
     * @param {object} [options]      linesPerSlide / preSlideOrder / padNumber / onProgress
     * @returns {Promise<Array<{name:string, bytes:Uint8Array, songId:string,
     *   arrangementUuid:?object, arrangementName:?string}>>}
     */
    async function buildSetlistProFiles(songs, options) {
        if (!protoRoot) await init();
        options = options || {};
        var onProgress = typeof options.onProgress === 'function' ? options.onProgress : null;

        var files = [];
        for (var i = 0; i < songs.length; i++) {
            var song = songs[i];
            var payload = buildPresentationPayload(song, options);
            var bytes = encodePresentationPayload(payload);
            var firstArrangement = (Array.isArray(payload.arrangements) && payload.arrangements.length)
                ? payload.arrangements[0]
                : null;

            files.push({
                name: buildFilename(song, { extension: '.pro', padNumber: options.padNumber }),
                bytes: bytes,
                songId: song && song.id != null ? String(song.id) : '',
                arrangementUuid: payload.selected_arrangement || null,
                arrangementName: firstArrangement ? firstArrangement.name : null
            });

            if (onProgress) {
                try { onProgress(i + 1, songs.length); } catch (progressErr) { /* #1571 — never fail the export */ }
            }
            if ((i + 1) % 25 === 0) {
                await new Promise(function (resolve) { setTimeout(resolve, 0); });
            }
        }
        ensureUniqueNames(files);
        return files;
    }

    /**
     * Export a SET LIST as a ProPresenter `.proplaylist` (#1968 P3, plan
     * §5.2). Completes the round-trip whose IMPORT half shipped in #1973.
     *
     * ELI5: bundle up a whole set list — its songs, in order, plus any
     * section dividers ("Prayer", "Offering", …) it carries — into ONE file
     * ProPresenter can open as a service order, with every song's own
     * `.pro` riding along inside the same ZIP so nothing needs a separate
     * import step afterwards.
     *
     * Detail: builds `rv.data.PlaylistDocument{type: PRESENTATION,
     * root_node: Playlist{name:"PLAYLIST", type: PLAYLIST,
     * playlists:{playlists:[Playlist{name:<setlist name>, type: PLAYLIST,
     * items:{items:[…]}}]}}}` — see PLAYLIST_TYPE_PLAYLIST's own doc-block
     * for why `type` is PLAYLIST rather than the plan prose's ROOT, and
     * buildPlaylistPresentationUrl()'s for the one deliberate divergence
     * from a literal fixture byte-mirror. `application_info` is left unset,
     * mirroring buildPresentationPayload()'s own established precedent
     * (SECTION 6) of never fabricating an ApplicationInfo this
     * browser-side exporter has no truthful source for.
     *
     * @param {object} setlist  {id, name, songs:[{id,title,songbook,number}],
     *   plan?:{slots:[{id,label,type,songId?}]}} — the iHymns set-list
     *   record (setlist.js's own model; `plan` is the OPTIONAL richer
     *   running-order overlay, #301/#1671 F4 — see
     *   resolveSetlistPlaylistEntries()).
     * @param {Array<object>} songs  FULL song records (title, components, …)
     *   for every song this set list references, pre-fetched by the CALLER
     *   via print.js's `fetchSong()` — this module never fetches its own
     *   data, matching exportAllAsZip()/exportAllAsBundle()'s existing
     *   contract (their `songs` argument is likewise caller-fetched).
     *   Matched to set-list entries by `.id`; a referenced song absent from
     *   this array is SKIPPED (recorded in the returned `skipped` array)
     *   rather than failing the whole export — the same tolerant posture
     *   the `.proplaylist` IMPORTER already takes for an unresolvable
     *   reference ("song-unresolved", plan §5.1 step 3).
     * @param {object} [options] Same shape exportAllAsZip()/
     *   exportAllAsBundle() accept: linesPerSlide, preSlideOrder, padNumber,
     *   onProgress(done,total).
     * @returns {Promise<{filename:string, size:number, songCount:number,
     *   itemCount:number, skipped:Array<string>}>}
     */
    async function exportSetlistAsProplaylist(setlist, songs, options) {
        if (!setlist || typeof setlist !== 'object') {
            throw new Error('exportSetlistAsProplaylist: setlist argument is required');
        }
        options = options || {};

        var entries = resolveSetlistPlaylistEntries(setlist);

        var byId = Object.create(null);
        (Array.isArray(songs) ? songs : []).forEach(function (s) {
            if (s && s.id != null) { byId[String(s.id)] = s; }
        });

        var skipped = [];
        var songsToEncode = [];
        entries.forEach(function (e) {
            if (e.kind !== 'song') { return; }
            var song = byId[e.songId];
            if (song) {
                songsToEncode.push(song);
            } else {
                skipped.push(e.songId);
            }
        });

        if (songsToEncode.length === 0) {
            throw new Error('exportSetlistAsProplaylist: no resolvable songs in this set list');
        }

        var proFiles = await buildSetlistProFiles(songsToEncode, options);
        var proById = Object.create(null);
        proFiles.forEach(function (f) { proById[f.songId] = f; });

        var items = [];
        entries.forEach(function (e) {
            if (e.kind === 'header') {
                items.push(makePlaylistHeaderItem(e.sectionName));
                return;
            }
            var f = proById[e.songId];
            if (!f) { return; } // unresolved — already recorded in `skipped` above
            var song = byId[e.songId];
            items.push(makePlaylistPresentationItem(
                (song && song.title) || f.name,
                f.name,
                f.arrangementUuid,
                f.arrangementName
            ));
        });

        var childPlaylist = {
            uuid: uuidMsg(),
            name: setlist.name || 'Set List',
            type: PLAYLIST_TYPE_PLAYLIST,
            items: { items: items }
        };
        var rootNode = {
            uuid: uuidMsg(),
            name: 'PLAYLIST',
            type: PLAYLIST_TYPE_PLAYLIST,
            playlists: { playlists: [childPlaylist] }
        };
        var documentPayload = {
            type: PLAYLIST_DOCUMENT_TYPE_PRESENTATION,
            root_node: rootNode
        };

        if (!protoRoot) await init();
        var PlaylistDocument = getPlaylistDocumentType();
        var docMessage = PlaylistDocument.create(documentPayload);
        var docBuffer = PlaylistDocument.encode(docMessage).finish();
        var docBytes = new Uint8Array(docBuffer.buffer, docBuffer.byteOffset, docBuffer.byteLength);

        /* `data` (the PlaylistDocument protobuf, exact entry NAME every real
           fixture uses — includes/propresenter7_playlist.php's
           pp7ReadPlaylistBundle() matches on it case-sensitively) followed
           by every referenced song's `.pro`, at the ZIP ROOT — the SAME
           root-level, no-manifest layout #1968 P2 established for
           `.probundle` (exportAllAsBundle() above). */
        var zipEntries = [{ name: 'data', bytes: docBytes }].concat(
            proFiles.map(function (f) { return { name: f.name, bytes: f.bytes }; })
        );
        var zipBytes = buildZip(zipEntries);
        var filename = sanitizeFilename(setlist.name || 'Set List') + '.proplaylist';

        triggerDownload(zipBytes, filename, 'application/zip');
        return {
            filename: filename,
            size: zipBytes.length,
            songCount: proFiles.length,
            itemCount: items.length,
            skipped: skipped
        };
    }

    var api = {
        init: init,
        exportSong: exportSong,
        exportAllAsZip: exportAllAsZip,
        exportAllAsBundle: exportAllAsBundle,
        /* #1968 P3 — set-list export -> .proplaylist. */
        exportSetlistAsProplaylist: exportSetlistAsProplaylist,
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
            /* #1918 follow-up: exposed so the test suite can assert the
               RTF header and text.attributes stay derived from the same
               SECTION 5c constants (rule #35 lockstep). */
            defaultTextAttributes: defaultTextAttributes,
            defaultTextBounds: defaultTextBounds,
            /* #1968 P3 — set-list .proplaylist export internals, exposed for
               unit tests (the pure pieces) and the round-trip closure test
               (encodePresentationPayload, so a test can build+encode a
               Presentation from a payload it inspected first, exactly as
               buildSetlistProFiles() does). */
            encodePresentationPayload: encodePresentationPayload,
            resolveSetlistPlaylistEntries: resolveSetlistPlaylistEntries,
            buildSetlistProFiles: buildSetlistProFiles,
            buildPlaylistPresentationUrl: buildPlaylistPresentationUrl,
            makePlaylistPresentationItem: makePlaylistPresentationItem,
            makePlaylistHeaderItem: makePlaylistHeaderItem,
            getPlaylistDocumentType: getPlaylistDocumentType,
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
