/**
 * iHymns Song Editor — FreeShow Import / Export module
 * =====================================================
 * Pure-function helpers (no DOM access) for parsing FreeShow `.show` files
 * into iHymns song objects, and serialising iHymns songs back out as
 * FreeShow `.show` documents.
 *
 * The module is dual-target:
 *   - Browser: attaches an object to `window.FreeShowIO`.
 *   - Node:    `module.exports` for unit tests.
 *
 * FreeShow `.show` format reference:
 *   https://github.com/ChurchApps/FreeShow
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */
(function (root) {
    'use strict';

    /* --------------------------------------------------------------------
     * Component type map
     *
     * FreeShow uses a `globalGroup` token on each slide to indicate its
     * role in the arrangement. Where present this is preferred over the
     * free-form `group` label.
     * ------------------------------------------------------------------ */
    var GLOBAL_GROUP_TO_TYPE = {
        verse:      'verse',
        chorus:     'chorus',
        pre_chorus: 'pre-chorus',
        prechorus:  'pre-chorus',
        bridge:     'bridge',
        tag:        'tag',
        outro:      'outro',
        intro:      'intro',
        coda:       'coda',
        interlude:  'interlude',
        refrain:    'refrain',
        ending:     'outro',
        breakdown:  'bridge'
    };

    /**
     * looksLikeFreeShow(json)
     * -----------------------
     * Heuristic detection of FreeShow's `Show` shape. FreeShow is
     * distinguished from the iHymns wrapper (which has a top-level
     * `songs` array) and from VideoPsalm (which uses a top-level
     * `Songs[]` with capital S) by the simultaneous presence of
     * `slides`, `layouts`, and `meta` objects.
     */
    function looksLikeFreeShow(json) {
        return !!json
            && typeof json === 'object'
            && !Array.isArray(json)
            && typeof json.slides === 'object'
            && typeof json.layouts === 'object'
            && typeof json.meta === 'object';
    }

    /**
     * getFirstLayoutSlideOrder(layouts)
     * ---------------------------------
     * FreeShow shows can carry multiple layouts. We treat the first
     * layout as canonical and report the rest as dropped layouts.
     *
     * Returns:
     *   {
     *     order: string[],          // slide IDs in display order
     *     droppedLayoutNames: string[]
     *   }
     */
    function getFirstLayoutSlideOrder(layouts) {
        var keys = Object.keys(layouts || {});
        if (keys.length === 0) {
            return { order: [], droppedLayoutNames: [] };
        }

        var firstKey = keys[0];
        var first = layouts[firstKey] || {};
        var order = (first.slides || [])
            .map(function (s) { return s && s.id; })
            .filter(function (id) { return typeof id === 'string'; });

        var droppedLayoutNames = keys.slice(1).map(function (k) {
            return (layouts[k] && layouts[k].name) ? layouts[k].name : k;
        });

        return { order: order, droppedLayoutNames: droppedLayoutNames };
    }

    /**
     * extractSlideText(slide)
     * -----------------------
     * Pulls plain-text lines out of a single FreeShow slide. Skips
     * non-text items (images, videos, timers, scripture refs) and
     * concatenates `text[].value` fragments inside each line.
     */
    function extractSlideText(slide) {
        if (!slide || !Array.isArray(slide.items)) return [];

        var lines = [];
        slide.items.forEach(function (item) {
            if (!item || item.type !== 'text') return;
            if (!Array.isArray(item.lines)) return;

            item.lines.forEach(function (lineObj) {
                if (!lineObj || !Array.isArray(lineObj.text)) return;
                var combined = lineObj.text
                    .map(function (frag) { return (frag && frag.value) || ''; })
                    .join('');
                if (combined !== '') lines.push(combined);
            });
        });

        return lines;
    }

    /**
     * resolveSlideType(slide)
     * -----------------------
     * Determines the iHymns component type for a FreeShow slide.
     * Priority: globalGroup → group label heuristic → 'verse'.
     * Returns { type, isNumberedVerse }.
     */
    function resolveSlideType(slide) {
        if (!slide) return { type: 'verse', isNumberedVerse: false };

        var gg = (slide.globalGroup || '').toLowerCase();
        if (gg && GLOBAL_GROUP_TO_TYPE[gg]) {
            return { type: GLOBAL_GROUP_TO_TYPE[gg], isNumberedVerse: gg === 'verse' };
        }

        var group = (slide.group || '').toLowerCase().trim();
        if (!group) return { type: 'verse', isNumberedVerse: true };

        if (group.indexOf('chorus') !== -1 && group.indexOf('pre') !== -1) {
            return { type: 'pre-chorus', isNumberedVerse: false };
        }
        if (group.indexOf('chorus') !== -1)   return { type: 'chorus',    isNumberedVerse: false };
        if (group.indexOf('refrain') !== -1)  return { type: 'refrain',   isNumberedVerse: false };
        if (group.indexOf('bridge') !== -1)   return { type: 'bridge',    isNumberedVerse: false };
        if (group.indexOf('tag') !== -1)      return { type: 'tag',       isNumberedVerse: false };
        if (group.indexOf('outro') !== -1)    return { type: 'outro',     isNumberedVerse: false };
        if (group.indexOf('intro') !== -1)    return { type: 'intro',     isNumberedVerse: false };
        if (group.indexOf('coda') !== -1)     return { type: 'coda',      isNumberedVerse: false };
        if (group.indexOf('interlude') !== -1)return { type: 'interlude', isNumberedVerse: false };
        if (group.indexOf('verse') !== -1)    return { type: 'verse',     isNumberedVerse: true };

        return { type: 'verse', isNumberedVerse: true };
    }

    /**
     * splitAuthor(authorRaw)
     * ----------------------
     * FreeShow stores the author as a single string. Split on
     * common separators ("and", "&", ";", "/"). Commas are NOT
     * used as a separator because many writer credits include
     * commas inside a single name (e.g. "Smith, John").
     */
    function splitAuthor(authorRaw) {
        if (!authorRaw || typeof authorRaw !== 'string') return [];
        return authorRaw
            .split(/\s*(?:&|\band\b|;|\/)\s*/i)
            .map(function (s) { return s.trim(); })
            .filter(function (s) { return s.length > 0; });
    }

    /**
     * parseFreeShowShow(showJson, opts)
     * ---------------------------------
     * Convert one FreeShow `Show` object into an iHymns song.
     *
     * @param  {Object} showJson - The parsed FreeShow JSON document.
     * @param  {Object} opts
     *   @param {string} [opts.sourceName] - Original filename (without extension).
     *   @param {string} [opts.songbook]   - Target songbook abbreviation.
     *   @param {number} [opts.number]     - Caller-supplied song number.
     *   @param {string} [opts.songbookName] - Friendly songbook name.
     * @returns {{ song: Object|null, errors: string[], warnings: string[] }}
     */
    function parseFreeShowShow(showJson, opts) {
        opts = opts || {};
        var errors = [];
        var warnings = [];

        if (!looksLikeFreeShow(showJson)) {
            errors.push('Not a FreeShow .show file (missing slides/layouts/meta).');
            return { song: null, errors: errors, warnings: warnings };
        }

        /* Reject non-song categories early. */
        var category = (showJson.category || '').toLowerCase();
        if (category && category !== 'song') {
            errors.push('FreeShow show category is "' + showJson.category + '", not "song" — skipped.');
            return { song: null, errors: errors, warnings: warnings };
        }

        var meta = showJson.meta || {};
        var title = (meta.title && String(meta.title).trim())
                 || (showJson.name && String(showJson.name).trim())
                 || (opts.sourceName && String(opts.sourceName).trim())
                 || 'Untitled';

        /* First layout becomes the canonical arrangement. */
        var layoutInfo = getFirstLayoutSlideOrder(showJson.layouts);
        if (layoutInfo.droppedLayoutNames.length > 0) {
            warnings.push(
                'Dropped ' + layoutInfo.droppedLayoutNames.length +
                ' alternate layout(s): ' + layoutInfo.droppedLayoutNames.join(', ')
            );
        }

        /* If the layout is empty, fall back to the slide-dictionary order. */
        var slideIds = layoutInfo.order;
        if (slideIds.length === 0) {
            slideIds = Object.keys(showJson.slides || {});
            if (slideIds.length > 0) {
                warnings.push('No layout slides found; using raw slide dictionary order.');
            }
        }

        /* Walk slides, group consecutive runs sharing the same type. */
        var components = [];
        var verseCounter = 0;
        var lastType = null;

        slideIds.forEach(function (slideId) {
            var slide = showJson.slides[slideId];
            if (!slide) {
                warnings.push('Layout references missing slide id "' + slideId + '".');
                return;
            }

            var lines = extractSlideText(slide);
            if (lines.length === 0) return; // pure media slide — silently skipped

            var resolved = resolveSlideType(slide);
            var type = resolved.type;

            /* Collapse consecutive slides of the same non-verse type
             * into a single component (e.g. one Chorus over 2 slides).
             * Verses always start a new numbered component so V1 and
             * V2 don't get fused. */
            if (type !== 'verse' && type === lastType && components.length > 0) {
                var prev = components[components.length - 1];
                lines.forEach(function (l) { prev.lines.push(l); });
                prev.lyrics = prev.lines.join('\n');
            } else {
                var comp = { type: type, lines: lines.slice() };
                if (type === 'verse') {
                    verseCounter += 1;
                    comp.number = verseCounter;
                }
                comp.lyrics = comp.lines.join('\n');
                components.push(comp);
            }
            lastType = type;
        });

        if (components.length === 0) {
            errors.push('FreeShow show "' + title + '" contains no text slides.');
            return { song: null, errors: errors, warnings: warnings };
        }

        var songbookId = (opts.songbook && String(opts.songbook).trim()) || 'Misc';
        var number = (opts.number != null && !isNaN(opts.number))
            ? parseInt(opts.number, 10)
            : null;

        var song = {
            id: number != null
                ? songbookId + '-' + padNumber(number, 4)
                : songbookId + '-' + Date.now().toString(36),
            number: number != null ? number : 0,
            title: title,
            songbook: songbookId,
            songbookName: opts.songbookName || songbookId,
            writers:   splitAuthor(meta.author),
            composers: [],
            copyright: meta.copyright ? String(meta.copyright) : '',
            ccli:      meta.CCLI ? String(meta.CCLI) : '',
            hasAudio: false,
            hasSheetMusic: false,
            components: components
        };

        /* Tune title — FreeShow has no canonical field, but several
         * users store it in `meta.tune`, `meta.tuneTitle`, or `meta.tune_name`.
         * Carry it through if present so the export filename rules work. */
        var tune = meta.tune || meta.tuneTitle || meta.tune_name || meta.tunename;
        if (tune && String(tune).trim() !== '') {
            song.tune = String(tune).trim();
        }

        /* Pass-through key/BPM as optional metadata. */
        if (meta.key) song.key = String(meta.key);
        if (meta.BPM) song.bpm = String(meta.BPM);

        return { song: song, errors: errors, warnings: warnings };
    }

    /* --------------------------------------------------------------------
     * Export: iHymns song → FreeShow `.show` JSON
     * ------------------------------------------------------------------ */

    var TYPE_TO_GLOBAL_GROUP = {
        'verse':      'verse',
        'chorus':     'chorus',
        'pre-chorus': 'pre_chorus',
        'bridge':     'bridge',
        'tag':        'tag',
        'outro':      'outro',
        'intro':      'intro',
        'coda':       'outro',
        'interlude':  'bridge',
        'refrain':    'chorus'
    };

    /**
     * iHymnsSongToFreeShow(song)
     * --------------------------
     * Build a minimal but valid FreeShow `Show` JSON from an iHymns song.
     * Slide IDs are deterministic short strings derived from the
     * component index so re-exporting twice yields identical output.
     */
    function iHymnsSongToFreeShow(song) {
        var slides = {};
        var layoutSlides = [];

        (song.components || []).forEach(function (comp, idx) {
            var slideId = 's' + (idx + 1);
            var lines = Array.isArray(comp.lines) && comp.lines.length > 0
                ? comp.lines
                : (comp.lyrics ? String(comp.lyrics).split(/\r?\n/) : []);

            var groupLabel = comp.type
                ? comp.type.charAt(0).toUpperCase() + comp.type.slice(1)
                : 'Verse';
            if (comp.number != null && comp.type === 'verse') {
                groupLabel += ' ' + comp.number;
            }

            slides[slideId] = {
                group: groupLabel,
                color: null,
                globalGroup: TYPE_TO_GLOBAL_GROUP[comp.type] || 'verse',
                items: [{
                    type: 'text',
                    lines: lines.map(function (line) {
                        return { text: [{ value: line }] };
                    })
                }]
            };
            layoutSlides.push({ id: slideId });
        });

        var meta = {
            title: song.title || '',
            author: (song.writers || []).join(', '),
            copyright: song.copyright || '',
            CCLI: song.ccli || ''
        };
        if (song.tune) meta.tune = song.tune;
        if (song.key)  meta.key = song.key;
        if (song.bpm)  meta.BPM = song.bpm;

        return {
            name: song.title || '',
            category: 'song',
            settings: { template: 'default' },
            meta: meta,
            slides: slides,
            layouts: {
                'default': {
                    name: 'Default',
                    slides: layoutSlides
                }
            },
            media: {},
            timestamps: {
                created: Date.now(),
                modified: Date.now(),
                used: null
            },
            origin: 'iHymns'
        };
    }

    /* --------------------------------------------------------------------
     * Filename helpers — see Issue #884
     * ------------------------------------------------------------------ */

    /**
     * padNumber(num, width)
     * ---------------------
     * Zero-pads `num` to at least `width` digits.
     */
    function padNumber(num, width) {
        var s = String(num != null ? num : 0);
        while (s.length < width) s = '0' + s;
        return s;
    }

    /**
     * songbookPadWidth(songbookId, allSongs)
     * --------------------------------------
     * Computes the minimum number of digits required to express the
     * largest song number in this songbook. Width is at least 3, so
     * a small songbook still sorts cleanly alongside larger ones.
     */
    function songbookPadWidth(songbookId, allSongs) {
        var max = 0;
        (allSongs || []).forEach(function (s) {
            if (s.songbook === songbookId && typeof s.number === 'number' && s.number > max) {
                max = s.number;
            }
        });
        if (max === 0) return 3;
        return Math.max(3, String(max).length);
    }

    /**
     * sanitiseFilename(name)
     * ----------------------
     * Strips characters that are illegal in Windows / macOS / Linux
     * filenames and collapses runs of whitespace.
     */
    function sanitiseFilename(name) {
        var s = String(name == null ? '' : name);
        s = s.replace(/[\\\/:*?"<>|]/g, '');
        s = s.replace(/\s+/g, ' ');
        return s.trim();
    }

    /**
     * buildSongFilename(song, padWidth, ext)
     * --------------------------------------
     * Returns the per-issue-#884 single-song filename. Tune title is
     * appended in parentheses iff present on the song record.
     *
     *   "<SongNumber> (<SongbookAbbrev>) - <SongTitle>"
     *   "<SongNumber> (<SongbookAbbrev>) - <SongTitle> (<TuneTitle>)"
     */
    function buildSongFilename(song, padWidth, ext) {
        ext = ext || '.show';
        var num = padNumber(song.number, padWidth || 4);
        var base = num + ' (' + (song.songbook || '') + ') - ' + (song.title || 'Untitled');
        if (song.tune) base += ' (' + song.tune + ')';
        return sanitiseFilename(base) + ext;
    }

    /**
     * buildBundleFilename(songbook, ext)
     * ----------------------------------
     * Returns the per-issue-#884 bulk export filename:
     *   "<Songbook Title> (<Songbook Abbreviation>) [Bundle]<ext>"
     *
     * `songbook` should be { id, name }.
     */
    function buildBundleFilename(songbook, ext) {
        ext = ext || '.zip';
        var name = songbook && songbook.name ? songbook.name : 'iHymns';
        var id   = songbook && songbook.id   ? songbook.id   : 'Misc';
        return sanitiseFilename(name + ' (' + id + ') [Bundle]') + ext;
    }

    /* --------------------------------------------------------------------
     * Public API
     * ------------------------------------------------------------------ */
    var api = {
        looksLikeFreeShow:    looksLikeFreeShow,
        parseFreeShowShow:    parseFreeShowShow,
        iHymnsSongToFreeShow: iHymnsSongToFreeShow,
        extractSlideText:     extractSlideText,
        resolveSlideType:     resolveSlideType,
        splitAuthor:          splitAuthor,
        getFirstLayoutSlideOrder: getFirstLayoutSlideOrder,
        padNumber:            padNumber,
        songbookPadWidth:     songbookPadWidth,
        sanitiseFilename:     sanitiseFilename,
        buildSongFilename:    buildSongFilename,
        buildBundleFilename:  buildBundleFilename,
        GLOBAL_GROUP_TO_TYPE: GLOBAL_GROUP_TO_TYPE
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.FreeShowIO = api;
    }
}(typeof window !== 'undefined' ? window : globalThis));
