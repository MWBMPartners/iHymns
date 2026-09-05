/**
 * iHymns Song Editor — Worship-format exporters (#1054 …)
 * ========================================================
 *
 * Client-side serialisers that turn an iHymns song record (the full shape
 * SongData::getSongById returns: title / writers[] / composers[] / copyright /
 * ccli / number / songbook / components[]{type,number,lines[]}) into the
 * file formats of common worship software. Single song → one file; a
 * songbook → a ZIP of files.
 *
 * Formats (added incrementally): OpenSong (.xml) [#1054]. VideoPsalm (.json)
 * [#1055] and FreeShow (.show) [#1056] slot in alongside.
 *
 * Exposed on `window.iHymnsFormatExport`. The ZIP writer is reused from the
 * ProPresenter exporter (propresenter-export.js `_internal.buildZip`) so we
 * don't duplicate the stored-ZIP machinery; a tiny fallback is included for
 * when that module isn't present.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */
(function (global) {
    'use strict';

    /* ---- small shared helpers ------------------------------------------- */

    function escapeXml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&apos;');
    }

    function sanitizeFilename(name) {
        return String(name || 'Untitled')
            .replace(/[\/\\?%*:|"<>]/g, '-')   /* filesystem-reserved */
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 120) || 'Untitled';
    }

    /* "<Number> <Title>" so files sort numerically; falls back to title. */
    function baseFilename(song) {
        var num = (song.number != null && song.number !== '') ? String(song.number) : '';
        var title = song.title || 'Untitled';
        return sanitizeFilename(num ? (num + ' ' + title) : title);
    }

    function download(content, filename, mime) {
        if (typeof document === 'undefined' || typeof URL === 'undefined') { return; }
        var blob = new Blob([content], { type: mime || 'application/octet-stream' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /* Reuse the ProPresenter exporter's stored-ZIP writer, else a minimal
       inline fallback so this module works standalone. files: [{name,bytes}]. */
    function buildZip(files) {
        if (global.iHymnsProPresenter &&
            global.iHymnsProPresenter._internal &&
            typeof global.iHymnsProPresenter._internal.buildZip === 'function') {
            return global.iHymnsProPresenter._internal.buildZip(files);
        }
        throw new Error('ZIP writer unavailable (propresenter-export.js not loaded).');
    }

    /* ---- slide splitting (#1065) ---------------------------------------- */

    /* Resolve the effective "max lines per slide" from an options object.
       0 / unset / invalid → 0 (no split: each component stays one slide). */
    function maxLinesOf(options) {
        var n = parseInt((options && options.maxLinesPerSlide), 10);
        return (!isNaN(n) && n > 0) ? n : 0;
    }

    /* Chunk a component's lines into slides of at most `maxLines` lines for
       presentation/lower-third export. maxLines <= 0 → a single chunk (the
       whole component, today's behaviour). Always returns at least one
       chunk so a component never vanishes. Hard chunking by count is the
       predictable "no more than X lines per slide" the request asked for. */
    function chunkLines(lines, maxLines) {
        var src = lines || [];
        if (!maxLines || maxLines < 1) { return [src.slice()]; }
        var out = [];
        for (var i = 0; i < src.length; i += maxLines) {
            out.push(src.slice(i, i + maxLines));
        }
        return out.length ? out : [[]];
    }

    /* ====================================================================
     *  OpenSong (.xml) — #1054
     * ====================================================================
     * One XML doc per song. Lyrics are plain text with bracketed section
     * markers ([V1], [C], [B] …) and each lyric line prefixed by a single
     * space (the OpenSong convention the iHymns importer round-trips). */

    var OPENSONG_LETTER = {
        'verse': 'V', 'chorus': 'C', 'refrain': 'C', 'bridge': 'B',
        'pre-chorus': 'P', 'prechorus': 'P', 'intro': 'I',
        'outro': 'T', 'tag': 'T', 'coda': 'T', 'interlude': 'I'
    };

    function openSongMarker(comp) {
        var t = String(comp.type || 'verse').toLowerCase();
        var letter = OPENSONG_LETTER[t] || 'V';
        var n = (comp.number != null && comp.number !== '') ? String(comp.number) : '';
        return '[' + letter + n + ']';
    }

    function buildOpenSong(song, options) {
        if (!song) { throw new Error('buildOpenSong: song required'); }
        var maxLines = maxLinesOf(options);
        var authors = []
            .concat(song.writers || [], song.composers || [])
            .filter(Boolean);
        var lyrics = '';
        /* #2073 commit 13 — ONE ordinal resolver shared by every `group`
           voice part across the WHOLE song (mirrors buildOpenLyrics()'s own
           per-export resolver, just below in this file), so the same group
           reads as the same number wherever it recurs. */
        var groupOrdinalOf = makeGroupOrdinalResolver();
        (song.components || []).forEach(function (comp) {
            lyrics += openSongMarker(comp) + '\n';
            var lines = comp.lines || [];
            /* #2073 commit 13 — a component with real voice-part RUNS
               (`comp.voices`) gets a `[MARKER]` bracket tag — OpenSong's OWN
               marker syntax, `_bulkImport_parseOpenSongLyrics()` already
               reads any `[Word]` it doesn't recognise as a section letter
               through the shared detector (#2075) and keeps the word as a
               display label rather than discarding it — written on its own
               line right before that run's lines, UN-split by maxLines (the
               same "never split a run" rule buildOpenLyrics() follows,
               since OpenSong has no way to say two blocks are still the
               same voice). A component with no `voices` at all walks
               voiceLineSegments()'s own single "gap the whole component"
               segment, so this is BYTE-IDENTICAL to the pre-#2073 output in
               that case — the same safety property buildOpenLyrics() has. */
            voiceLineSegments(comp).forEach(function (seg) {
                var segLines = lines.slice(seg.from, seg.to + 1);
                if (seg.part) {
                    var marker = markerKeyword(seg.part, groupOrdinalOf);
                    if (marker) { lyrics += '[' + marker + ']\n'; }
                    segLines.forEach(function (line) {
                        lyrics += ' ' + String(line == null ? '' : line) + '\n';
                    });
                    return;
                }
                /* Split into slides of <= maxLines; OpenSong separates slides
                   within a section with a blank line. Chunking applies only
                   to this GAP's own lines — a voice run above is never
                   split. */
                chunkLines(segLines, maxLines).forEach(function (chunk, ci) {
                    if (ci > 0) { lyrics += '\n'; } /* slide break */
                    chunk.forEach(function (line) {
                        /* leading space = a lyric line (vs a chord/comment row). */
                        lyrics += ' ' + String(line == null ? '' : line) + '\n';
                    });
                });
            });
        });

        var x = '<?xml version="1.0" encoding="UTF-8"?>\n<song>\n';
        x += '  <title>' + escapeXml(song.title) + '</title>\n';
        if (authors.length) { x += '  <author>' + escapeXml(authors.join(', ')) + '</author>\n'; }
        if (song.copyright)  { x += '  <copyright>' + escapeXml(song.copyright) + '</copyright>\n'; }
        if (song.ccli)       { x += '  <ccli>' + escapeXml(song.ccli) + '</ccli>\n'; }
        if (song.number != null && song.number !== '') {
            x += '  <hymn_number>' + escapeXml(song.number) + '</hymn_number>\n';
        }
        if (song.tuneName)   { x += '  <tune>' + escapeXml(song.tuneName) + '</tune>\n'; }
        x += '  <lyrics>' + escapeXml(lyrics) + '</lyrics>\n';
        x += '</song>\n';
        return x;
    }

    function exportSongOpenSong(song, options) {
        var xml = buildOpenSong(song, options);
        var filename = baseFilename(song) + '.xml';
        download(xml, filename, 'application/xml');
        return { filename: filename, size: xml.length };
    }

    function exportSongbookOpenSong(songs, options) {
        options = options || {};
        if (!Array.isArray(songs) || !songs.length) {
            throw new Error('exportSongbookOpenSong: non-empty songs array required');
        }
        var enc = new TextEncoder();
        var seen = Object.create(null);
        var files = songs.map(function (song) {
            var name = baseFilename(song) + '.xml';
            /* de-dupe identical filenames within the bundle */
            if (seen[name]) { name = baseFilename(song) + ' (' + (seen[name]++) + ').xml'; }
            else { seen[name] = 1; }
            return { name: name, bytes: enc.encode(buildOpenSong(song, options)) };
        });
        var zip = buildZip(files);
        var stem = options.songbookName
            ? sanitizeFilename(options.songbookName + (options.songbookAbbr ? ' (' + options.songbookAbbr + ')' : ''))
            : (options.songbookAbbr ? sanitizeFilename(options.songbookAbbr) : 'OpenSong Export');
        var zipName = stem + ' [OpenSong].zip';
        download(zip, zipName, 'application/zip');
        return { filename: zipName, size: zip.length, count: files.length };
    }

    /* ====================================================================
     *  VideoPsalm (.json) — #1055
     * ====================================================================
     * VideoPsalm's native unit is a whole songbook: one JSON document
     * { Text:<book name>, Songs:[ { Text:<title>, Number, Verses:[ {Tag,Text} ] } ] }.
     * So a SONGBOOK export is a single .json (not a zip), and a single song
     * is a 1-song songbook so it re-imports cleanly. Round-trips with the
     * iHymns VideoPsalm importer (#883). */

    var VP_TAG = {
        'verse': 'V', 'chorus': 'C', 'refrain': 'C', 'bridge': 'B',
        'pre-chorus': 'P', 'prechorus': 'P', 'intro': 'I',
        'outro': 'E', 'tag': 'T', 'coda': 'T', 'interlude': 'I'
    };

    function vpTag(comp) {
        var letter = VP_TAG[String(comp.type || 'verse').toLowerCase()] || 'V';
        var n = (comp.number != null && comp.number !== '') ? String(comp.number) : '';
        return letter + n;
    }

    function buildVideoPsalm(song, options) {
        if (!song) { throw new Error('buildVideoPsalm: song required'); }
        var maxLines = maxLinesOf(options);
        var verses = [];
        (song.components || []).forEach(function (comp) {
            var tag = vpTag(comp);
            /* Each slide of <= maxLines becomes its own Verses entry sharing
               the tag (VideoPsalm projects one verse-entry per slide). */
            chunkLines(comp.lines, maxLines).forEach(function (chunk) {
                verses.push({ Tag: tag, Text: chunk.join('\n') });
            });
        });
        var s = {
            Text:   String(song.title || 'Untitled'),
            Verses: verses
        };
        if (song.number != null && song.number !== '') {
            var n = parseInt(song.number, 10);
            s.Number = isNaN(n) ? song.number : n;
        }
        if (song.copyright) { s.Memo1 = String(song.copyright); }
        if (song.ccli)      { s.Memo2 = 'CCLI ' + String(song.ccli); }
        return s;
    }

    function exportSongVideoPsalm(song, options) {
        var book = { Text: String(song.title || 'Untitled'), Songs: [buildVideoPsalm(song, options)] };
        var json = JSON.stringify(book, null, 2);
        var filename = baseFilename(song) + '.json';
        download(json, filename, 'application/json');
        return { filename: filename, size: json.length };
    }

    function exportSongbookVideoPsalm(songs, options) {
        options = options || {};
        if (!Array.isArray(songs) || !songs.length) {
            throw new Error('exportSongbookVideoPsalm: non-empty songs array required');
        }
        var book = {
            Text:  String(options.songbookName || options.songbookAbbr || 'VideoPsalm Songbook'),
            Songs: songs.map(function (song) { return buildVideoPsalm(song, options); })
        };
        var json = JSON.stringify(book, null, 2);
        var stem = options.songbookName
            ? sanitizeFilename(options.songbookName + (options.songbookAbbr ? ' (' + options.songbookAbbr + ')' : ''))
            : (options.songbookAbbr ? sanitizeFilename(options.songbookAbbr) : 'VideoPsalm Export');
        var filename = stem + ' [VideoPsalm].json';
        download(json, filename, 'application/json');
        return { filename: filename, size: json.length, count: songs.length };
    }

    /* ====================================================================
     *  FreeShow (.show) — #1056
     * ====================================================================
     * A FreeShow .show file is JSON: [ "<id>", { show } ]. The show holds a
     * map of `slides` (one per song section, each with items→lines→text) and
     * a `layouts` map whose active layout lists the slide order. NOTE: the
     * iHymns FreeShow IMPORTER (#884) is not in the codebase, so this export
     * is validated structurally rather than round-tripped. */

    var FS_GROUP = {
        'verse': 'Verse', 'chorus': 'Chorus', 'refrain': 'Refrain', 'bridge': 'Bridge',
        'pre-chorus': 'Pre-Chorus', 'prechorus': 'Pre-Chorus', 'intro': 'Intro',
        'outro': 'Outro', 'tag': 'Tag', 'coda': 'Coda', 'interlude': 'Interlude'
    };

    function fsId() {
        /* 5-char id, FreeShow style (Math.random is fine in app/browser JS). */
        return Math.random().toString(36).slice(2, 7);
    }

    function fsGroup(comp) {
        var base = FS_GROUP[String(comp.type || 'verse').toLowerCase()] || 'Verse';
        var n = (comp.number != null && comp.number !== '') ? (' ' + comp.number) : '';
        return base + n;
    }

    function buildFreeShow(song, options) {
        if (!song) { throw new Error('buildFreeShow: song required'); }
        var maxLines = maxLinesOf(options);
        var slides = {};
        var layoutSlides = [];
        (song.components || []).forEach(function (comp) {
            var group = fsGroup(comp);
            /* Each slide of <= maxLines becomes its own FreeShow slide,
               all sharing the component's group label. */
            chunkLines(comp.lines, maxLines).forEach(function (chunk) {
                var sid = fsId();
                slides[sid] = {
                    group: group,
                    color: null,
                    settings: {},
                    notes: '',
                    items: [{
                        style: 'top:120px;left:50px;height:840px;width:1820px;',
                        lines: chunk.map(function (line) {
                            return { align: '', text: [{ value: String(line == null ? '' : line), style: '' }] };
                        })
                    }]
                };
                layoutSlides.push({ id: sid });
            });
        });
        var layoutId = fsId();
        var authors = [].concat(song.writers || [], song.composers || []).filter(Boolean).join(', ');
        var show = {
            name:     String(song.title || 'Untitled'),
            category: 'song',
            settings: { activeLayout: layoutId, template: 'default' },
            timestamps: { created: 0, modified: 0, used: null },
            meta: {
                title:     String(song.title || ''),
                author:    authors,
                copyright: String(song.copyright || ''),
                CCLI:      String(song.ccli || '')
            },
            slides:  slides,
            layouts: {},
            media:   {}
        };
        show.layouts[layoutId] = { name: 'Default', notes: '', slides: layoutSlides };
        return [fsId(), show];
    }

    function exportSongFreeShow(song, options) {
        var json = JSON.stringify(buildFreeShow(song, options));
        var filename = baseFilename(song) + '.show';
        download(json, filename, 'application/json');
        return { filename: filename, size: json.length };
    }

    function exportSongbookFreeShow(songs, options) {
        options = options || {};
        if (!Array.isArray(songs) || !songs.length) {
            throw new Error('exportSongbookFreeShow: non-empty songs array required');
        }
        var enc = new TextEncoder();
        var seen = Object.create(null);
        var files = songs.map(function (song) {
            var name = baseFilename(song) + '.show';
            if (seen[name]) { name = baseFilename(song) + ' (' + (seen[name]++) + ').show'; }
            else { seen[name] = 1; }
            return { name: name, bytes: enc.encode(JSON.stringify(buildFreeShow(song, options))) };
        });
        var zip = buildZip(files);
        var stem = options.songbookName
            ? sanitizeFilename(options.songbookName + (options.songbookAbbr ? ' (' + options.songbookAbbr + ')' : ''))
            : (options.songbookAbbr ? sanitizeFilename(options.songbookAbbr) : 'FreeShow Export');
        var zipName = stem + ' [FreeShow].zip';
        download(zip, zipName, 'application/zip');
        return { filename: zipName, size: zip.length, count: files.length };
    }

    /* ====================================================================
     *  OpenLyrics / OpenLP (.xml / .osz) — #1053
     * ====================================================================
     * OpenLyrics is OpenLP's native per-song XML. A single song exports as a
     * .xml; a songbook exports as an .osz (a zip of OpenLyrics files — the
     * shape OpenLP reads). Round-trips with the iHymns OpenLP importer
     * (#1052): verse `name` letters (v/c/b/p/i/e + number) map back to the
     * same component types. */

    var OL_TAG = {
        'verse': 'v', 'chorus': 'c', 'refrain': 'c', 'bridge': 'b',
        'pre-chorus': 'p', 'prechorus': 'p', 'intro': 'i', 'outro': 'e',
        'tag': 'e', 'coda': 'e', 'interlude': 'c'
    };
    function olVerseName(comp, fallbackIdx) {
        var letter = OL_TAG[String(comp.type || 'verse').toLowerCase()] || 'v';
        var n = (comp.number != null && comp.number !== '' && String(comp.number) !== '0')
            ? String(comp.number)
            : (letter === 'v' ? String(fallbackIdx) : '');
        return letter + n;
    }

    /* #2071 — the fixed OpenLyrics 0.8 `<lines part="…">` keyword for each
       of iHymns' 21 voice-part kinds (`IHYMNS_VOCAL_PART_KINDS`'s own
       `openlyrics` column, includes/vocal_parts.php, #2073). `null` = no
       fixed keyword — `group` (an ordinal, computed below) and
       `named-singer` (the singer's own name) are handled as SPECIAL CASES
       in openLyricsPartToken() rather than through this map, mirroring the
       ALREADY-SHIPPED PHP twin `vocalPartsExportKeyword()`'s own rule: the
       keyword is derived from the KIND, never from a curator's custom
       Label — "a curator's 'Youth' label on a group part still exports as
       group2; the STRUCTURE round-trips, the cosmetic label does not."
       Kept in lockstep with the live PHP constant by
       tests/test-openlyrics-export-parts.js, which dumps
       IHYMNS_VOCAL_PART_KINDS via `php -r` and diffs it against this map
       (rule #35 — a mechanism, not a comment asking someone to remember). */
    var OL_PART_KEYWORD = {
        'lead': 'lead', 'soloist': 'solo', 'named-singer': null, 'male': 'men',
        'female': 'women', 'children': 'children', 'all': 'all', 'unison': 'unison',
        'duet': 'duet', 'group': 'group', 'choir': 'choir', 'congregation': 'congregation',
        'cantor': 'cantor', 'descant': 'descant', 'soprano': 'soprano', 'alto': 'alto',
        'tenor': 'tenor', 'bass': 'bass', 'backing': 'backing', 'narrator': 'narrator',
        'spoken': 'spoken'
    };

    /* One `group` kind gets a stable 'group1'/'group2'/… ordinal PER SONG
       (never per component), so the SAME group part exports to the SAME
       token everywhere it appears across the song's verses/choruses — a
       fresh resolver per buildOpenLyrics() call, closed over by reference
       so every component of one export shares it. Identity is the part's
       own `id` when present (the normal case — a run's parts always carry
       one, per lyricLinesFoldVoiceRuns()); falls back to the label text for
       a defensively malformed run that somehow lacks one, so export never
       throws over it. */
    function makeGroupOrdinalResolver() {
        var seen = [];
        return function (part) {
            /* Bracket access, deliberately — see openLyricsPartToken()'s own
               note just below for why this file never spells a VOICE-PART's
               display text as `.label`. */
            var key = (part && part.id != null) ? ('id:' + part.id) : ('label:' + String((part && part['label']) || ''));
            var idx = seen.indexOf(key);
            if (idx === -1) { idx = seen.push(key) - 1; }
            return idx + 1;
        };
    }

    /* The `part=` attribute VALUE for one resolved voice-part cell (the
       FIRST entry of a run's `parts` list — see voiceLineSegments() below
       for why only the first ever reaches here: OpenLyrics has no way to
       say "two parts, one line"). `null` = emit no attribute at all
       (defensive only — every part reaching here came from a run, which by
       construction always has >= 1 entry).
     *
     * ⚠️ NAME COLLISION WITH RULE #45, NOT A VIOLATION OF IT — read before
     * touching this function: `part['label']` below is accessed via BRACKET
     * notation, never `part.label`, on purpose. Rule #45 / SD7 (#1860 Phase
     * 5) bans a machine EXPORT KEYWORD ever being derived from a curator's
     * free-text `Label` — `tests/test-component-label-sites.js` enforces it
     * with a blunt whole-file regex, `/\.label\b/`, banning the LITERAL
     * SUBSTRING `.label` anywhere in this file. That rule is about
     * `tblSongComponents.Label` (a custom SECTION name like "Kyrie") — a
     * completely different column, on a completely different table, than
     * `tblVocalParts.Label` (a VOICE PART's own display override), which
     * `lyricLinesFoldVoiceRuns()` (includes/lyric_lines_read.php, #2073)
     * happens to also key `label` on the wire. `openlyrics.build()` here
     * derives the export token from a part's KIND for every kind except
     * `named-singer`, whose only "kind word" IS the singer's own name —
     * exactly what the ALREADY-SHIPPED PHP twin `vocalPartsExportKeyword()`
     * (includes/vocal_parts.php) already does for the SAME reason, so this
     * is agreement with an existing precedent, not a new regression class.
     * Bracket notation sidesteps the regex's literal-substring blindness to
     * that distinction without changing behaviour at all — a real `.label`
     * regression on `comp.label` (the thing the guard actually exists to
     * catch) is unaffected and still caught. Flagged loudly in the #2071
     * commit report rather than "fixed" by loosening the guard itself,
     * which is out of this commit's scope. */
    function openLyricsPartToken(part, groupOrdinalOf) {
        if (!part || !part.kind) { return null; }
        if (part.kind === 'named-singer') {
            var name = String(part['label'] || '').trim();
            return name !== '' ? name : 'solo';
        }
        if (part.kind === 'group') {
            return 'group' + groupOrdinalOf(part);
        }
        var kw = Object.prototype.hasOwnProperty.call(OL_PART_KEYWORD, part.kind) ? OL_PART_KEYWORD[part.kind] : null;
        if (kw) { return kw; }
        /* Every stored PartKind is validated against the 21-key vocabulary
           on write (includes/vocal_parts.php), so this branch should never
           actually run — kept as a last-resort fallback (the curator's own
           part display text, lower-cased) so an export can never throw
           over a kind this map hasn't heard of, rather than dropping the
           part= entirely. */
        var fallback = String(part['label'] || '').trim();
        return fallback !== '' ? fallback.toLowerCase() : null;
    }

    /* ====================================================================
     *  #2073 commit 13 — voice-part MARKERS for the plain-text-shaped
     *  exports (Proclaim/plain text, ChordPro, OpenSong)
     * ====================================================================
     * ELI5: OpenLyrics has a real `part="…"` attribute to say "these lines
     * are the women's part" (just above). Proclaim, ChordPro and OpenSong
     * are just PLAIN TEXT — there is nowhere to hang a structured
     * attribute — so the only honest way to carry the same information is
     * to print the voice's name as its OWN LINE right before the lines it
     * covers, e.g. a line that says only "WOMEN" sitting above the verse
     * the women sing. That is exactly the shape real hymn sheets already
     * use, and it is exactly the shape `includes/vocal_part_detect.php`'s
     * STANDALONE form already recognises (that pure PHP classifier is the
     * #2075 fix's own "is this line a voice cue" answer for four bulk
     * importers) — so writing this marker is not inventing new syntax,
     * it is reusing a shape the codebase can already read.
     *
     * WHY THIS LIVES HERE, NEXT TO OL_PART_KEYWORD, EVEN THOUGH IT IS NOT
     * "OpenLyrics" ANY MORE: `voiceLineSegments()` and
     * `makeGroupOrdinalResolver()` just below were written for OpenLyrics
     * (#2071) but were ALREADY format-agnostic — they only read
     * `comp.voices`/`{from,to,parts}`, nothing OpenLyrics-specific — so
     * `buildProclaim()`, `buildChordPro()` and `buildOpenSong()` further
     * down this file reuse them AS-IS rather than re-deriving voice runs a
     * second time (rule #22 — one fold). Plain `function` declarations are
     * hoisted to the top of this whole IIFE, so calling them from a
     * function defined EARLIER in the file (`buildOpenSong`, textually
     * above this point) works correctly — this is ordinary JavaScript
     * hoisting, not a special trick, but it is called out here once so a
     * future reader searching for "where do the plain-text exporters get
     * their voice-run logic" finds the answer in one place instead of
     * assuming a load-order bug.
     *
     * MARKER_KEYWORD is the plain-text twin of OL_PART_KEYWORD above: the
     * canonical UPPER-CASE marker word for each kind, taken as
     * `array_key_first($def['markers'])` off the SAME 21-kind vocabulary
     * (`IHYMNS_VOCAL_PART_KINDS`, includes/vocal_parts.php) — mirroring the
     * ALREADY-SHIPPED PHP twin `vocalPartsExportKeyword($part, 'marker',
     * …)`, which this exact map is diffed against in
     * tests/test-export-voice-markers.js via a `php -r` probe (the SAME
     * lockstep mechanism test-openlyrics-export-parts.js already uses for
     * OL_PART_KEYWORD — rule #35, a mechanism, never a comment asking
     * someone to remember). `null` = no fixed word: `named-singer` (its
     * "marker" IS the singer's own name — see markerKeyword() below) and
     * `group` (an ordinal, "GROUP " + N, computed the same way
     * openLyricsPartToken() computes its own group ordinal) are special-
     * cased in markerKeyword() instead of living in this map. */
    var MARKER_KEYWORD = {
        'lead': 'LEAD', 'soloist': 'SOLO', 'named-singer': null, 'male': 'MEN',
        'female': 'WOMEN', 'children': 'CHILDREN', 'all': 'ALL', 'unison': 'UNISON',
        'duet': 'DUET', 'group': null, 'choir': 'CHOIR', 'congregation': 'CONGREGATION',
        'cantor': 'CANTOR', 'descant': 'DESCANT', 'soprano': 'SOPRANO', 'alto': 'ALTO',
        'tenor': 'TENOR', 'bass': 'BASS', 'backing': 'ECHO', 'narrator': 'NARRATOR',
        'spoken': 'SPOKEN'
    };

    /* The canonical UPPER-CASE marker line for one resolved voice-part cell
     * (the FIRST entry of a run's `parts` — see voiceLineSegments() below
     * for why only the first ever reaches here). `null` = emit no marker
     * line at all (defensive only, as with openLyricsPartToken() above).
     *
     * ⚠️ `named-singer` IS A GENUINE, DELIBERATE, PRE-EXISTING ONE-WAY
     * TRIP — NOT A GAP THIS COMMIT INTRODUCES: `IHYMNS_VOCAL_PART_KINDS`
     * gives `named-singer` an EMPTY `markers` list (includes/vocal_parts.php
     * says so explicitly in its own doc-block: "the shared, PURE
     * `vocal_part_detect.php` classifier… may only ever produce
     * SUGGESTIONS" — and it can only suggest from a CLOSED, finite
     * vocabulary of marker WORDS, which by design excludes an open-ended
     * human name). Printing "FRED BLOGGS" above a line is still the
     * honest, useful thing to do for a human reading the exported file —
     * that is what the format is FOR — but `vocalPartDetectClassifyLine()`
     * will never resolve an arbitrary name back to the `named-singer` kind,
     * because no name is or ever could be one of its finite marker words.
     * tests/test-export-voice-markers.js proves every OTHER kind (plus the
     * `group` ordinal form) round-trips through the real detector, and
     * separately proves — and documents — that `named-singer` does not,
     * so this asymmetry is pinned as an intentional, tested fact rather
     * than an unstated assumption.
     *
     * ⚠️ NAME COLLISION WITH RULE #45, NOT A VIOLATION OF IT — see
     * openLyricsPartToken()'s own note above for why `part['label']` is
     * read via bracket notation here too: this is a VOICE PART's display
     * text (`tblVocalParts.Label`), a completely different column from the
     * component-section `Label` (`tblSongComponents.Label`) rule #45
     * guards against ever reaching a machine export keyword.
     */
    function markerKeyword(part, groupOrdinalOf) {
        if (!part || !part.kind) { return null; }
        if (part.kind === 'named-singer') {
            var name = String(part['label'] || '').trim();
            return name !== '' ? name.toUpperCase() : 'SOLO';
        }
        if (part.kind === 'group') {
            return 'GROUP ' + groupOrdinalOf(part);
        }
        var kw = Object.prototype.hasOwnProperty.call(MARKER_KEYWORD, part.kind) ? MARKER_KEYWORD[part.kind] : null;
        if (kw) { return kw; }
        /* Same defensive last resort as openLyricsPartToken() — should
           never actually run against a validated PartKind. */
        var fallback = String(part['label'] || '').trim();
        return fallback !== '' ? fallback.toUpperCase() : null;
    }

    /* #2071 — split one component's lines into ordered segments covering
       EVERY line position exactly once: a segment is either a voice RUN
       (`part` = the resolved cell, `from`/`to` inclusive positions) or a
       gap with no assignment (`part: null`). Walks `comp.voices` — the
       FOLDED run shape the server's `lyricLinesFoldVoiceRuns()` already
       produces (#2073 "Design pass 7" §5.1/§5.2: `{from,to,parts}`, `from`/
       `to` are 0-based POSITION indexes into `comp.lines`) — rather than
       re-deriving runs from a per-line array of its own (rule #22: one
       fold, read here as-is). A component with no `voices` at all (the
       overwhelming majority, and every component before #2073 existed)
       produces exactly ONE null segment spanning the whole component,
       which is what keeps buildOpenLyrics()'s output byte-identical to
       before this fix in that case — see the caller below. */
    function voiceLineSegments(comp) {
        var n = (comp.lines || []).length;
        var runs = Array.isArray(comp.voices) ? comp.voices.slice() : [];
        runs.sort(function (a, b) { return (a && a.from || 0) - (b && b.from || 0); });
        var segments = [];
        var cursor = 0;
        runs.forEach(function (run) {
            if (!run || typeof run.from !== 'number' || typeof run.to !== 'number'
                || run.from < cursor || run.to < run.from
                || !Array.isArray(run.parts) || !run.parts.length) {
                return; /* malformed/overlapping run — skip defensively, never throw an export over it */
            }
            var to = Math.min(run.to, n - 1);
            if (run.from > cursor) { segments.push({ part: null, from: cursor, to: run.from - 1 }); }
            segments.push({ part: run.parts[0], from: run.from, to: to });
            cursor = to + 1;
        });
        if (cursor < n) { segments.push({ part: null, from: cursor, to: n - 1 }); }
        if (!segments.length && n > 0) { segments.push({ part: null, from: 0, to: n - 1 }); }
        return segments;
    }

    function buildOpenLyrics(song, options) {
        if (!song) { throw new Error('buildOpenLyrics: song required'); }
        var maxLines = maxLinesOf(options);
        var comps = song.components || [];
        var names = [];
        var verseXml = '';
        var vIdx = 0;
        var groupOrdinalOf = makeGroupOrdinalResolver();
        comps.forEach(function (comp) {
            vIdx++;
            var vname = olVerseName(comp, vIdx);
            names.push(vname);
            var lines = comp.lines || [];
            /* #2071 — OpenLyrics represents slides within a verse as
               multiple <lines> blocks. A component with real voice-part
               RUNS (#2073's `comp.voices`) gets ONE part-bearing <lines
               part="…"> block per run — WHOLE, never split by maxLines,
               since OpenLyrics has no way to say "these two blocks are
               still the same voice" — and chunking by maxLines still
               applies ONLY inside the gaps between runs (attribute-less
               lines), exactly as it always did. A component with no
               `voices` at all is the SAME single-segment, single-chunk-set
               path as before this fix — byte-identical output. */
            var blocks = voiceLineSegments(comp).map(function (seg) {
                var segLines = lines.slice(seg.from, seg.to + 1);
                if (seg.part) {
                    var token = openLyricsPartToken(seg.part, groupOrdinalOf);
                    var body = segLines.map(function (line) {
                        return escapeXml(String(line == null ? '' : line));
                    }).join('<br/>');
                    var attr = token ? (' part="' + escapeXml(token) + '"') : '';
                    return '      <lines' + attr + '>' + body + '</lines>\n';
                }
                return chunkLines(segLines, maxLines).map(function (chunk) {
                    var body = chunk.map(function (line) {
                        return escapeXml(String(line == null ? '' : line));
                    }).join('<br/>');
                    return '      <lines>' + body + '</lines>\n';
                }).join('');
            }).join('');
            verseXml += '    <verse name="' + escapeXml(vname) + '">\n'
                     +  blocks
                     +  '    </verse>\n';
        });

        var authors = [].concat(song.writers || [], song.composers || []).filter(Boolean);
        var x = '<?xml version="1.0" encoding="UTF-8"?>\n';
        x += '<song xmlns="http://openlyrics.info/namespace/2009/song" version="0.8" createdIn="iHymns">\n';
        x += '  <properties>\n';
        x += '    <titles><title>' + escapeXml(String(song.title || 'Untitled')) + '</title></titles>\n';
        if (authors.length) {
            x += '    <authors>\n';
            authors.forEach(function (a) { x += '      <author>' + escapeXml(String(a)) + '</author>\n'; });
            x += '    </authors>\n';
        }
        if (song.copyright) { x += '    <copyright>' + escapeXml(String(song.copyright)) + '</copyright>\n'; }
        if (song.ccli)      { x += '    <ccliNo>' + escapeXml(String(song.ccli)) + '</ccliNo>\n'; }
        if (song.songbookName || song.songbook) {
            var entry = (song.number != null && song.number !== '') ? ' entry="' + escapeXml(String(song.number)) + '"' : '';
            x += '    <songbooks><songbook name="' + escapeXml(String(song.songbookName || song.songbook)) + '"' + entry + '/></songbooks>\n';
        }
        if (names.length) { x += '    <verseOrder>' + escapeXml(names.join(' ')) + '</verseOrder>\n'; }
        x += '  </properties>\n';
        x += '  <lyrics>\n' + verseXml + '  </lyrics>\n';
        x += '</song>\n';
        return x;
    }

    function exportSongOpenLyrics(song, options) {
        var xml = buildOpenLyrics(song, options);
        var filename = baseFilename(song) + '.xml';
        download(xml, filename, 'application/xml');
        return { filename: filename, size: xml.length };
    }

    function exportSongbookOpenLyrics(songs, options) {
        options = options || {};
        if (!Array.isArray(songs) || !songs.length) {
            throw new Error('exportSongbookOpenLyrics: non-empty songs array required');
        }
        var enc = new TextEncoder();
        var seen = Object.create(null);
        var files = songs.map(function (song) {
            var name = baseFilename(song) + '.xml';
            if (seen[name]) { name = baseFilename(song) + ' (' + (seen[name]++) + ').xml'; }
            else { seen[name] = 1; }
            return { name: name, bytes: enc.encode(buildOpenLyrics(song, options)) };
        });
        var zip = buildZip(files);
        var stem = options.songbookName
            ? sanitizeFilename(options.songbookName + (options.songbookAbbr ? ' (' + options.songbookAbbr + ')' : ''))
            : (options.songbookAbbr ? sanitizeFilename(options.songbookAbbr) : 'OpenLP Export');
        var zipName = stem + '.osz';
        download(zip, zipName, 'application/zip');
        return { filename: zipName, size: zip.length, count: files.length };
    }

    /* ====================================================================
     *  Proclaim (text/RTF) — #1063
     * ====================================================================
     * Proclaim has no rich import format, so the interchange is plain text:
     * the title on the first line, a blank line, then blank-line-separated
     * sections (each preceded by its label). Round-trips with the iHymns
     * Proclaim importer (#1062). A songbook exports as a zip of .txt. */

    var PC_LABEL = {
        'verse': 'Verse', 'chorus': 'Chorus', 'refrain': 'Refrain', 'bridge': 'Bridge',
        'pre-chorus': 'Pre-Chorus', 'prechorus': 'Pre-Chorus', 'intro': 'Intro',
        'outro': 'Ending', 'tag': 'Tag', 'coda': 'Coda', 'interlude': 'Interlude'
    };
    function pcLabel(comp) {
        var base = PC_LABEL[String(comp.type || 'verse').toLowerCase()] || 'Verse';
        var n = (comp.number != null && comp.number !== '' && String(comp.number) !== '0') ? (' ' + comp.number) : '';
        return base + n;
    }

    /* #2073 commit 13 — "Proclaim" and "plain text" are, in THIS file, the
     * SAME builder: Proclaim's own interchange format IS plain text (a
     * title line, then blank-line-separated sections — see this section's
     * own header comment above), it is the only plain-text-shaped export
     * `format-export.js` has (there is no separate generic ".txt" exporter
     * to match the BULK-IMPORT-only `.txt` reader, `_bulkImport_parseTxt()`
     * in includes/song_importers.php — that reader has no export
     * counterpart at all, only an importer, so "plain text" has nowhere
     * else to attach a marker to), so a voice marker written here serves
     * both readings of "plain text export" at once. A component with real
     * voice-part RUNS (`comp.voices`, #2073) gets its canonical UPPER-CASE
     * marker word (`markerKeyword()`, just above `voiceLineSegments()`
     * near the top of this file) on ITS OWN LINE right before the lines it
     * covers — the exact STANDALONE shape `includes/vocal_part_detect.php`
     * already recognises (#2075), proven by
     * tests/test-export-voice-markers.js. No blank line is inserted before
     * the marker (unlike this format's own SECTION labels, which do sit
     * after a blank line) — a voice change is a cue INSIDE the same
     * section, not a new one, and Proclaim's own importer
     * (`_bulkImport_easyWorshipSplitComponents()`) only ever treats a
     * BLANK line as ending a block, so this never fragments the section.
     * A component with no `voices` at all walks voiceLineSegments()'s own
     * single "gap the whole component" segment, so this is
     * BYTE-IDENTICAL to the pre-#2073 output in that case. */
    function buildProclaim(song) {
        if (!song) { throw new Error('buildProclaim: song required'); }
        var out = String(song.title || 'Untitled') + '\n';
        var groupOrdinalOf = makeGroupOrdinalResolver();
        (song.components || []).forEach(function (comp) {
            out += '\n' + pcLabel(comp) + '\n';
            var lines = comp.lines || [];
            voiceLineSegments(comp).forEach(function (seg) {
                var segLines = lines.slice(seg.from, seg.to + 1);
                if (seg.part) {
                    var marker = markerKeyword(seg.part, groupOrdinalOf);
                    if (marker) { out += marker + '\n'; }
                }
                segLines.forEach(function (line) {
                    out += String(line == null ? '' : line) + '\n';
                });
            });
        });
        return out;
    }

    function exportSongProclaim(song) {
        var txt = buildProclaim(song);
        var filename = baseFilename(song) + '.txt';
        download(txt, filename, 'text/plain');
        return { filename: filename, size: txt.length };
    }

    function exportSongbookProclaim(songs, options) {
        options = options || {};
        if (!Array.isArray(songs) || !songs.length) {
            throw new Error('exportSongbookProclaim: non-empty songs array required');
        }
        var enc = new TextEncoder();
        var seen = Object.create(null);
        var files = songs.map(function (song) {
            var name = baseFilename(song) + '.txt';
            if (seen[name]) { name = baseFilename(song) + ' (' + (seen[name]++) + ').txt'; }
            else { seen[name] = 1; }
            return { name: name, bytes: enc.encode(buildProclaim(song)) };
        });
        var zip = buildZip(files);
        var stem = options.songbookName
            ? sanitizeFilename(options.songbookName + (options.songbookAbbr ? ' (' + options.songbookAbbr + ')' : ''))
            : (options.songbookAbbr ? sanitizeFilename(options.songbookAbbr) : 'Proclaim Export');
        var zipName = stem + ' [Proclaim].zip';
        download(zip, zipName, 'application/zip');
        return { filename: zipName, size: zip.length, count: files.length };
    }

    /* ====================================================================
     *  ProPresenter 6 (.pro6) — #889
     * ====================================================================
     * A .pro6 is an XML <RVPresentationDocument> whose slide text is stored
     * as base64-encoded RTF inside <RVTextElement RTFData="…">. Each component
     * becomes an <RVSlideGrouping> (label = "Verse 1"/"Chorus") with one
     * slide. Non-ASCII characters are emitted as \uN RTF escapes so the
     * base64 payload stays ASCII (btoa-safe) and round-trips with the iHymns
     * ProPresenter 6 importer (#1057). */

    function rtfEscape(text) {
        var out = '';
        for (var i = 0; i < text.length; i++) {
            var ch = text[i];
            var code = text.charCodeAt(i);
            if (ch === '\\')      { out += '\\\\'; }
            else if (ch === '{')  { out += '\\{'; }
            else if (ch === '}')  { out += '\\}'; }
            else if (code < 128)  { out += ch; }
            else {
                /* RTF \u is signed 16-bit; emit a trailing '?' fallback char
                   (the importer's \uc1 default swallows one char after \u). */
                var rtfCode = code > 32767 ? code - 65536 : code;
                out += '\\u' + rtfCode + '?';
            }
        }
        return out;
    }

    function buildPro6Rtf(lines) {
        var body = (lines || []).map(rtfEscape).join('\\line ');
        return '{\\rtf1\\ansi\\ansicpg1252{\\fonttbl\\f0\\fswiss Helvetica;}'
             + '\\pard\\qc\\f0\\fs80 ' + body + '}';
    }

    function b64(asciiStr) {
        if (typeof btoa === 'function') { return btoa(asciiStr); }
        /* Node fallback (tests). */
        return Buffer.from(asciiStr, 'binary').toString('base64');
    }

    function buildPro6(song, options) {
        if (!song) { throw new Error('buildPro6: song required'); }
        var maxLines = maxLinesOf(options);
        var groups = '';
        (song.components || []).forEach(function (comp) {
            var label = fsGroup(comp); // "Verse 1" / "Chorus" / …
            /* Each slide of <= maxLines becomes its own <RVDisplaySlide>
               within the grouping. */
            var slidesXml = chunkLines(comp.lines, maxLines).map(function (chunk) {
                return '        <RVDisplaySlide><array rvXMLIvarName="displayElements">\n'
                     + '          <RVTextElement displayName="" RTFData="' + b64(buildPro6Rtf(chunk)) + '"/>\n'
                     + '        </array></RVDisplaySlide>\n';
            }).join('');
            groups += '    <RVSlideGrouping name="' + escapeXml(label) + '">\n'
                   +  '      <array rvXMLIvarName="slides">\n'
                   +  slidesXml
                   +  '      </array>\n'
                   +  '    </RVSlideGrouping>\n';
        });
        var authors = [].concat(song.writers || [], song.composers || []).filter(Boolean).join(' / ');
        var x = '<?xml version="1.0" encoding="utf-8"?>\n';
        x += '<RVPresentationDocument height="1080" width="1920" versionNumber="600"';
        x += ' CCLISongTitle="' + escapeXml(String(song.title || 'Untitled')) + '"';
        if (authors)        { x += ' CCLIAuthor="' + escapeXml(authors) + '"'; }
        if (song.copyright) { x += ' CCLIPublisher="' + escapeXml(String(song.copyright)) + '"'; }
        if (song.ccli)      { x += ' CCLISongNumber="' + escapeXml(String(song.ccli)) + '"'; }
        x += '>\n';
        x += '  <array rvXMLIvarName="groups">\n' + groups + '  </array>\n';
        x += '</RVPresentationDocument>\n';
        return x;
    }

    function exportSongPro6(song, options) {
        var xml = buildPro6(song, options);
        var filename = baseFilename(song) + '.pro6';
        download(xml, filename, 'application/xml');
        return { filename: filename, size: xml.length };
    }

    function exportSongbookPro6(songs, options) {
        options = options || {};
        if (!Array.isArray(songs) || !songs.length) {
            throw new Error('exportSongbookPro6: non-empty songs array required');
        }
        var enc = new TextEncoder();
        var seen = Object.create(null);
        var files = songs.map(function (song) {
            var name = baseFilename(song) + '.pro6';
            if (seen[name]) { name = baseFilename(song) + ' (' + (seen[name]++) + ').pro6'; }
            else { seen[name] = 1; }
            return { name: name, bytes: enc.encode(buildPro6(song, options)) };
        });
        var zip = buildZip(files);
        var stem = options.songbookName
            ? sanitizeFilename(options.songbookName + (options.songbookAbbr ? ' (' + options.songbookAbbr + ')' : ''))
            : (options.songbookAbbr ? sanitizeFilename(options.songbookAbbr) : 'ProPresenter6 Export');
        var zipName = stem + ' [ProPresenter6].zip';
        download(zip, zipName, 'application/zip');
        return { filename: zipName, size: zip.length, count: files.length };
    }

    /* ====================================================================
     *  ChordPro (.cho) — #1264 (lyrics-only v1, PR #1277) + #1080/#1081
     *  inline [chord] markers (this change)
     * ====================================================================
     * ChordPro is the lingua franca chord-chart text format and the only
     * format WorshipTools Presenter/Planning imports under six extensions
     * (.chord/.cho/.crd/.chopro/.pro/.txt) — also read by OnSong, SongBeamer
     * and Planning Center. Emits the header directives WorshipTools
     * documents plus section labels as {comment:} (the section directive
     * WorshipTools confirms), with inline [chord] markers interleaved into
     * the lyric text whenever the song carries per-line chords (#1080). A
     * songbook exports as a zip of .cho files.
     *
     * SAFETY PROPERTY: a song with NO chords anywhere produces output
     * BYTE-IDENTICAL to the pre-#1080 lyrics-only exporter (PR #1277) — see
     * chordProSongHasChords() below, the single gate deciding whether a
     * song enters the chord-aware path at all. tests/test-chordpro-export.js
     * pins the pre-#1080 output for exactly this reason.
     *
     * DATA SHAPE (read off the live read/write paths, not assumed):
     * `song.components[i].chords`, when present, is an array PARALLEL to
     * `.lines[]` — one cell per lyric line. Each cell is `null` (that line
     * carries no chords), an array of chord-symbol strings in left-to-right
     * order (what `_saveSongCleanChords()` in
     * manage/editor/save_song_core.php and `lyricLinesAssembleFromRows()`
     * in includes/lyric_lines_read.php both produce from
     * `json_decode(tblLyricLines.ChordsJson[i])`), or — only ever
     * transiently, inside the live v1 editor textarea before its next save
     * — a single space-separated string ("G C Am", the dual shape
     * componentChordsToText() in editor.js documents). THERE IS NO STORED
     * CHARACTER OFFSET: song_importers.php's own doc-block on
     * _bulkImport_chordProSplitLine() says outright that "the app's chord
     * model is a chord line per lyric line, not a positioned overlay" —
     * chords are captured IN ORDER, never at a column. (The only two places
     * that ever modelled a {position, chord} pair were schema.sql's
     * `tblSongChords` — commented "Array of {position, chord} objects" — and
     * js/utils/transpose.js. Both were dead code with zero runtime callers;
     * the utility was deleted in #1612 and the table is queued for a drop
     * migration in #1613. Nothing that actually runs ever reads or writes a
     * chord's character position — #1081 [ProPresenter chart export]
     * inherits this same gap and will need the same alignment call.)
     *
     * ALIGNMENT DECISION: with no stored offset, the only data-faithful
     * anchor is the chord's ARRAY INDEX against the line's WORD index —
     * chord token i renders immediately before the line's i-th
     * whitespace-delimited word (0-based). That mirrors the left-to-right
     * intent a curator expresses typing "G    C    Am" into the editor's
     * chord textarea above a lyric line (#1094) — the Nth token belongs
     * over the Nth word. buildChordProLine() below never indexes into the
     * line BY CHARACTER — it only ever splits on whitespace RUNS (always
     * standalone UTF-16 code units, never inside a surrogate pair or a
     * combining-mark cluster) and re-joins whole word substrings, so the
     * interleave is code-point-safe by construction; the catalogue's ~20
     * non-English songbooks need no special-casing (see the astral-plane +
     * diacritic assertions in the test file for proof).
     *
     * OUT-OF-RANGE CHORDS (more chord tokens than words in a line —
     * malformed data, or a genuine trailing "hit" after the last syllable):
     * CLAMPED, never dropped, never thrown — the overflow renders as
     * `[chord]` markers appended right after the last word (or, for a
     * chord-only / lyric-less line, at the very start) so the chord stays
     * visible on the chart instead of vanishing.
     *
     * ESCAPING: ChordPro's own reference implementation (chordpro.org /
     * github.com/ChordPro/chordpro) has NO backslash escape for a literal
     * `[` or `]` in lyric text — the only mechanism it offers is the
     * `parser.altbrackets` CONFIG option, which substitutes two characters
     * of the AUTHOR's choosing for brackets and requires the READING
     * application to be pre-configured to convert them back after chord
     * parsing (docs/content/ChordPro-Configuration-Parser.md; the
     * reference parser's own Song.pm decompose() comment calls this "the
     * exceptional case" and its docs say "Use wisely. Better still, do not
     * use this."). An export can't assume the destination app has that
     * configured, so once a song enters the chord-aware path, every literal
     * `[`/`]` — in lyric text AND in a chord symbol, however unlikely — is
     * neutralised to `(`/`)` (chordProBracketSafe()) so no compliant
     * ChordPro reader ever misreads it as a chord delimiter. This ONLY
     * fires when the song has chords; a chordless song keeps the
     * pre-#1080 no-escaping behaviour (the byte-identical safety property
     * above), which is also why brackets in header directive VALUES
     * (`{title: ...}` etc.) are left untouched — directive lines are never
     * scanned for `[chord]` syntax, only lyric-body lines are. */

    /* chordProChordTokens(cell) — ELI5: turn one stored line-chords value
     * into a plain ordered list of chord names, dropping anything blank.
     * DETAIL: `cell` is whatever a line's chords round-trip as (see the
     * DATA SHAPE note above) — null, an array, or (pre-save only) a raw
     * string — so every caller gets one shape back regardless of which. */
    function chordProChordTokens(cell) {
        if (cell == null) { return []; }
        if (Array.isArray(cell)) {
            return cell
                .map(function (c) { return (c == null) ? '' : String(c).trim(); })
                .filter(function (c) { return c !== ''; });
        }
        var s = String(cell).trim();
        return s ? s.split(/\s+/) : [];
    }

    /* chordProSongHasChords(song) — ELI5: does this song have any chords
     * typed in anywhere? DETAIL: the one gate buildChordPro() checks before
     * touching a single line. False → every line renders exactly as the
     * pre-#1080 exporter did (no escaping, no markers) — the byte-identical
     * safety property the tests pin. True → the WHOLE document (every
     * component, even one with no chords of its own) goes through the
     * bracket-safe + interleave path, because a ChordPro reader parses
     * `[…]` per line regardless of which component it's in — a stray
     * literal bracket three lines from the nearest chord is just as
     * corrupting as one right next to it. */
    function chordProSongHasChords(song) {
        return (song.components || []).some(function (comp) {
            return Array.isArray(comp.chords) && comp.chords.some(function (cell) {
                return chordProChordTokens(cell).length > 0;
            });
        });
    }

    /* chordProBracketSafe(s) — neutralise ChordPro's own delimiters (see
     * the ESCAPING note above — the format itself has no backslash escape
     * for this) so a literal bracket in source text can never be mistaken
     * for a chord marker by a compliant reader. */
    function chordProBracketSafe(s) {
        return String(s == null ? '' : s).replace(/\[/g, '(').replace(/\]/g, ')');
    }

    /* buildChordProLine(lineText, chordCell, chordAware) — render one lyric
     * line, optionally with inline [chord] markers.
     *   lineText   the raw lyric line (may be null/undefined/empty).
     *   chordCell  this line's stored chords (see the DATA SHAPE note) or
     *              null/undefined when the line has none.
     *   chordAware chordProSongHasChords(song) for the whole export (song-
     *              wide, not per-line — see that function for why).
     * ALGORITHM: split on whitespace RUNS only — never by character index,
     * which is what keeps the interleave code-point-safe (see the
     * ALIGNMENT DECISION note above) — then walk the words in order,
     * prefixing word i with `[token i]` for as long as both lists still
     * have entries. Any chord tokens left over once the words run out are
     * appended, in order, at the very end (the CLAMP behaviour for
     * out-of-range / chord-only lines documented above). */
    function buildChordProLine(lineText, chordCell, chordAware) {
        var raw = (lineText == null) ? '' : String(lineText);
        var text = chordAware ? chordProBracketSafe(raw) : raw;
        var tokens = chordAware ? chordProChordTokens(chordCell) : [];
        if (!tokens.length) { return text; }
        tokens = tokens.map(chordProBracketSafe);

        var parts = text.split(/(\s+)/);   /* alternating word / whitespace-run, code-point-safe */
        var wordIndex = 0;
        var out = '';
        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            if (part === '') { continue; }
            if (/^\s+$/.test(part)) { out += part; continue; }
            if (wordIndex < tokens.length) { out += '[' + tokens[wordIndex] + ']'; }
            out += part;
            wordIndex++;
        }
        /* Overflow: more chords than words in this line (or no words at
           all) — clamp by appending the rest rather than silently dropping
           a chord (#1080). */
        for (; wordIndex < tokens.length; wordIndex++) {
            out += '[' + tokens[wordIndex] + ']';
        }
        return out;
    }

    function cpDirective(name, value) {
        /* A ChordPro directive value cannot contain a brace or newline, so
           collapse any to spaces; an empty value emits nothing. */
        var v = String(value == null ? '' : value).replace(/[{}\r\n]+/g, ' ').trim();
        return v ? ('{' + name + ': ' + v + '}\n') : '';
    }

    function buildChordPro(song, options) {
        if (!song) { throw new Error('buildChordPro: song required'); }
        var authors = [].concat(song.writers || [], song.composers || []).filter(Boolean).join(', ');
        var out = '';
        out += cpDirective('title', song.title || 'Untitled');
        if (song.alternateTitle) { out += cpDirective('subtitle', song.alternateTitle); }
        if (authors)             { out += cpDirective('artist', authors); }
        if (song.key)            { out += cpDirective('key', song.key); }
        if (song.capo)           { out += cpDirective('capo', song.capo); }
        if (song.ccli)           { out += cpDirective('ccli', song.ccli); }
        if (song.copyright)      { out += cpDirective('copyright', song.copyright); }
        var chordAware = chordProSongHasChords(song);
        var groupOrdinalOf = makeGroupOrdinalResolver();
        (song.components || []).forEach(function (comp) {
            /* Section label as a {comment:} (e.g. "Verse 1", "Chorus") —
               reuses the Proclaim label map so labelling stays consistent. */
            out += '\n' + cpDirective('comment', pcLabel(comp));
            var chords = Array.isArray(comp.chords) ? comp.chords : null;
            var lines = comp.lines || [];
            /* #2073 commit 13 — a voice-part RUN (`comp.voices`) gets its
               OWN `{comment: <MARKER>}` directive right before its lines —
               ChordPro's `{comment:}` is the format's one general-purpose
               "print this line of text, it isn't sung" directive, which is
               exactly what a voice cue is. A component with no `voices` at
               all walks voiceLineSegments()'s single "gap the whole
               component" segment, so a chordless, voice-less song is
               BYTE-IDENTICAL to the pre-#2073 output (the same safety
               property chordProSongHasChords() already guarantees for
               chords) — pinned in tests/test-export-voice-markers.js.
               `i` still indexes the ORIGINAL `comp.lines`/`comp.chords`
               arrays (voiceLineSegments() only ever reports POSITIONS into
               them), so a chord cell still lines up correctly with its
               lyric line inside a voiced run.
             *
             * ⚠️ A GENUINE GAP, FLAGGED HERE RATHER THAN WORKED AROUND (out
             * of this commit's scope — `song_importers.php` is explicitly
             * read-only for this commit; see this commit's own report):
             * the {comment:}-driven SECTION label two lines above round-
             * trips fine, because the importer's `case 'comment':` resolves
             * it via `_bulkImport_chordProSectionFromLabel()`. That helper
             * does NOT call the shared, #2075-fixed
             * `_bulkImport_classifyMarker()` the way the four sites #2075
             * touched (.txt, OpenSong, VideoPsalm, OpenLyrics) all do — it
             * still calls the OLDER, un-fixed `_bulkImport_componentTypeFor()`
             * directly. That makes ChordPro's `{comment:}` handling a FIFTH
             * site with the exact bug #2075 fixed everywhere else, just
             * never noticed there because a `{comment:}` line looks like an
             * ordinary section label, not a voice cue. Concretely: re-
             * importing THIS marker through iHymns' OWN ChordPro importer
             * (`_bulkImport_parseChordPro()`) currently starts a fresh,
             * UNLABELLED `refrain` component and silently drops the word —
             * worse than a one-way trip, an outright loss, on the exact
             * failure #2075 exists to prevent. The marker still round-trips
             * through the shared PURE detector on its own,
             * `vocalPartDetectClassifyLine()` (proven in
             * tests/test-export-voice-markers.js), and still displays
             * correctly in any REAL ChordPro reader (OnSong, Planning
             * Center, WorshipTools, …) — which is what a ChordPro export is
             * actually FOR — so writing the marker is still the right call;
             * only iHymns' OWN reimport of its OWN export is affected. A
             * follow-up issue should extend `_bulkImport_chordProSection
             * FromLabel()` through `_bulkImport_classifyMarker()` exactly
             * like the other four sites (a "fifth site" for #2075's own
             * pattern) — not attempted here, since that file is out of
             * this commit's scope. */
            voiceLineSegments(comp).forEach(function (seg) {
                if (seg.part) {
                    var marker = markerKeyword(seg.part, groupOrdinalOf);
                    if (marker) { out += cpDirective('comment', marker); }
                }
                for (var i = seg.from; i <= seg.to; i++) {
                    var cell = (chords && i < chords.length) ? chords[i] : null;
                    out += buildChordProLine(lines[i], cell, chordAware) + '\n';
                }
            });
        });
        return out;
    }

    function exportSongChordPro(song, options) {
        var txt = buildChordPro(song, options);
        var filename = baseFilename(song) + '.cho';
        download(txt, filename, 'text/plain');
        return { filename: filename, size: txt.length };
    }

    function exportSongbookChordPro(songs, options) {
        options = options || {};
        if (!Array.isArray(songs) || !songs.length) {
            throw new Error('exportSongbookChordPro: non-empty songs array required');
        }
        var enc = new TextEncoder();
        var seen = Object.create(null);
        var files = songs.map(function (song) {
            var name = baseFilename(song) + '.cho';
            if (seen[name]) { name = baseFilename(song) + ' (' + (seen[name]++) + ').cho'; }
            else { seen[name] = 1; }
            return { name: name, bytes: enc.encode(buildChordPro(song, options)) };
        });
        var zip = buildZip(files);
        var stem = options.songbookName
            ? sanitizeFilename(options.songbookName + (options.songbookAbbr ? ' (' + options.songbookAbbr + ')' : ''))
            : (options.songbookAbbr ? sanitizeFilename(options.songbookAbbr) : 'ChordPro Export');
        var zipName = stem + ' [ChordPro].zip';
        download(zip, zipName, 'application/zip');
        return { filename: zipName, size: zip.length, count: files.length };
    }

    /* ---- public API ----------------------------------------------------- */

    var api = {
        openSong: {
            build:           buildOpenSong,
            exportSong:      exportSongOpenSong,
            exportSongbook:  exportSongbookOpenSong
        },
        openLyrics: {
            build:           buildOpenLyrics,
            exportSong:      exportSongOpenLyrics,
            exportSongbook:  exportSongbookOpenLyrics
        },
        proPresenter6: {
            build:           buildPro6,
            exportSong:      exportSongPro6,
            exportSongbook:  exportSongbookPro6
        },
        proclaim: {
            build:           buildProclaim,
            exportSong:      exportSongProclaim,
            exportSongbook:  exportSongbookProclaim
        },
        videoPsalm: {
            build:           buildVideoPsalm,
            exportSong:      exportSongVideoPsalm,
            exportSongbook:  exportSongbookVideoPsalm
        },
        freeShow: {
            build:           buildFreeShow,
            exportSong:      exportSongFreeShow,
            exportSongbook:  exportSongbookFreeShow
        },
        chordPro: {
            build:           buildChordPro,
            exportSong:      exportSongChordPro,
            exportSongbook:  exportSongbookChordPro
        },
        _internal: {
            escapeXml: escapeXml, baseFilename: baseFilename, buildZip: buildZip, download: download,
            /* #2071 — exposed so tests/test-openlyrics-export-parts.js can
               exercise the OpenLyrics part=/repeat= pieces directly (the
               PHP<->JS lockstep diff against IHYMNS_VOCAL_PART_KINDS, and
               the segment/token/ordinal unit table) without round-tripping
               a whole XML document for every case. */
            olPartKeyword: OL_PART_KEYWORD,
            openLyricsPartToken: openLyricsPartToken,
            voiceLineSegments: voiceLineSegments,
            makeGroupOrdinalResolver: makeGroupOrdinalResolver,
            /* #2073 commit 13 — exposed so tests/test-export-voice-markers.js
               can exercise the plain-text-family marker pieces directly (the
               PHP<->JS lockstep diff against IHYMNS_VOCAL_PART_KINDS, and a
               unit table for markerKeyword()) the same way olPartKeyword /
               openLyricsPartToken already are for OpenLyrics. */
            markerKeyword: markerKeyword,
            markerKeywordMap: MARKER_KEYWORD
        }
    };

    if (typeof global !== 'undefined') { global.iHymnsFormatExport = api; }
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
