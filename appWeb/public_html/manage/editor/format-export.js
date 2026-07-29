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
        (song.components || []).forEach(function (comp) {
            /* Section label as a {comment:} (e.g. "Verse 1", "Chorus") —
               reuses the Proclaim label map so labelling stays consistent. */
            out += '\n' + cpDirective('comment', pcLabel(comp));
            var chords = Array.isArray(comp.chords) ? comp.chords : null;
            (comp.lines || []).forEach(function (line, i) {
                var cell = (chords && i < chords.length) ? chords[i] : null;
                out += buildChordProLine(line, cell, chordAware) + '\n';
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
