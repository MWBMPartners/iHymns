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

    function buildOpenSong(song) {
        if (!song) { throw new Error('buildOpenSong: song required'); }
        var authors = []
            .concat(song.writers || [], song.composers || [])
            .filter(Boolean);
        var lyrics = '';
        (song.components || []).forEach(function (comp) {
            lyrics += openSongMarker(comp) + '\n';
            (comp.lines || []).forEach(function (line) {
                /* leading space = a lyric line (vs a chord/comment row). */
                lyrics += ' ' + String(line == null ? '' : line) + '\n';
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

    function exportSongOpenSong(song) {
        var xml = buildOpenSong(song);
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
            return { name: name, bytes: enc.encode(buildOpenSong(song)) };
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

    function buildVideoPsalm(song) {
        if (!song) { throw new Error('buildVideoPsalm: song required'); }
        var s = {
            Text:   String(song.title || 'Untitled'),
            Verses: (song.components || []).map(function (comp) {
                return { Tag: vpTag(comp), Text: (comp.lines || []).join('\n') };
            })
        };
        if (song.number != null && song.number !== '') {
            var n = parseInt(song.number, 10);
            s.Number = isNaN(n) ? song.number : n;
        }
        if (song.copyright) { s.Memo1 = String(song.copyright); }
        if (song.ccli)      { s.Memo2 = 'CCLI ' + String(song.ccli); }
        return s;
    }

    function exportSongVideoPsalm(song) {
        var book = { Text: String(song.title || 'Untitled'), Songs: [buildVideoPsalm(song)] };
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
            Songs: songs.map(buildVideoPsalm)
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

    function buildFreeShow(song) {
        if (!song) { throw new Error('buildFreeShow: song required'); }
        var slides = {};
        var layoutSlides = [];
        (song.components || []).forEach(function (comp) {
            var sid = fsId();
            slides[sid] = {
                group: fsGroup(comp),
                color: null,
                settings: {},
                notes: '',
                items: [{
                    style: 'top:120px;left:50px;height:840px;width:1820px;',
                    lines: (comp.lines || []).map(function (line) {
                        return { align: '', text: [{ value: String(line == null ? '' : line), style: '' }] };
                    })
                }]
            };
            layoutSlides.push({ id: sid });
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

    function exportSongFreeShow(song) {
        var json = JSON.stringify(buildFreeShow(song));
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
            return { name: name, bytes: enc.encode(JSON.stringify(buildFreeShow(song))) };
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

    function buildOpenLyrics(song) {
        if (!song) { throw new Error('buildOpenLyrics: song required'); }
        var comps = song.components || [];
        var names = [];
        var verseXml = '';
        var vIdx = 0;
        comps.forEach(function (comp) {
            vIdx++;
            var vname = olVerseName(comp, vIdx);
            names.push(vname);
            var body = (comp.lines || []).map(function (line) {
                return escapeXml(String(line == null ? '' : line));
            }).join('<br/>');
            verseXml += '    <verse name="' + escapeXml(vname) + '">\n'
                     +  '      <lines>' + body + '</lines>\n'
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

    function exportSongOpenLyrics(song) {
        var xml = buildOpenLyrics(song);
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
            return { name: name, bytes: enc.encode(buildOpenLyrics(song)) };
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
        _internal: { escapeXml: escapeXml, baseFilename: baseFilename, buildZip: buildZip, download: download }
    };

    if (typeof global !== 'undefined') { global.iHymnsFormatExport = api; }
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
