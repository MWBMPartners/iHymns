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
        (song.components || []).forEach(function (comp) {
            lyrics += openSongMarker(comp) + '\n';
            /* Split into slides of <= maxLines; OpenSong separates slides
               within a section with a blank line. */
            var chunks = chunkLines(comp.lines, maxLines);
            chunks.forEach(function (chunk, ci) {
                if (ci > 0) { lyrics += '\n'; } /* slide break */
                chunk.forEach(function (line) {
                    /* leading space = a lyric line (vs a chord/comment row). */
                    lyrics += ' ' + String(line == null ? '' : line) + '\n';
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

    function buildOpenLyrics(song, options) {
        if (!song) { throw new Error('buildOpenLyrics: song required'); }
        var maxLines = maxLinesOf(options);
        var comps = song.components || [];
        var names = [];
        var verseXml = '';
        var vIdx = 0;
        comps.forEach(function (comp) {
            vIdx++;
            var vname = olVerseName(comp, vIdx);
            names.push(vname);
            /* OpenLyrics represents slides within a verse as multiple <lines>
               blocks — one per chunk of <= maxLines. */
            var blocks = chunkLines(comp.lines, maxLines).map(function (chunk) {
                var body = chunk.map(function (line) {
                    return escapeXml(String(line == null ? '' : line));
                }).join('<br/>');
                return '      <lines>' + body + '</lines>\n';
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

    function buildProclaim(song) {
        if (!song) { throw new Error('buildProclaim: song required'); }
        var out = String(song.title || 'Untitled') + '\n';
        (song.components || []).forEach(function (comp) {
            out += '\n' + pcLabel(comp) + '\n';
            (comp.lines || []).forEach(function (line) {
                out += String(line == null ? '' : line) + '\n';
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
     *  ChordPro (.cho) — #1264  (lyrics-only v1)
     * ====================================================================
     * ChordPro is the lingua franca chord-chart text format and the only
     * format WorshipTools Presenter/Planning imports under six extensions
     * (.chord/.cho/.crd/.chopro/.pro/.txt) — also read by OnSong, SongBeamer
     * and Planning Center. This v1 emits the header directives WorshipTools
     * documents plus section labels as {comment:} (the section directive
     * WorshipTools confirms), with LYRICS ONLY. Inline [chord] markers are a
     * follow-on once per-line chords are surfaced on the export read path
     * (#299 / #1094) — they aren't in the export song-shape yet. A songbook
     * exports as a zip of .cho files. */

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
        (song.components || []).forEach(function (comp) {
            /* Section label as a {comment:} (e.g. "Verse 1", "Chorus") —
               reuses the Proclaim label map so labelling stays consistent. */
            out += '\n' + cpDirective('comment', pcLabel(comp));
            (comp.lines || []).forEach(function (line) {
                out += String(line == null ? '' : line) + '\n';
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
        _internal: { escapeXml: escapeXml, baseFilename: baseFilename, buildZip: buildZip, download: download }
    };

    if (typeof global !== 'undefined') { global.iHymnsFormatExport = api; }
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
