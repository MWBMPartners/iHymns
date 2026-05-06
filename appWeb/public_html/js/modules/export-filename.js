/**
 * iHymns — Export Filename Helpers
 *
 * Single source of truth for the default-output-filename convention
 * used across every export surface (editor's Bulk Export, the
 * per-song Export action on the song toolbar, the per-songbook
 * Export-as-bundle action on /manage/songbooks). Keeping the rules
 * in one module means a tweak to padding or sanitisation lands on
 * every flow at once instead of drifting between three copies.
 *
 * Conventions:
 *
 *   1. Individual song WITH a Tune Title:
 *        "<padded#> (<ABBR>) - <Title> (<Tune>)"
 *
 *   2. Individual song WITHOUT a Tune Title:
 *        "<padded#> (<ABBR>) - <Title>"
 *
 *   3. Whole-songbook bundle:
 *        "<Songbook Title> (<ABBR>) [Bundle]"
 *
 * Padding width: derived per-songbook from the largest song number
 * present, with a floor of 3 digits so single-digit songs in tiny
 * songbooks still get a "001"-style prefix that sorts the way a
 * curator expects in Finder / Explorer / iTunes / etc.
 *
 *   - 700-song songbook  → 3 digits  ("042 (CIS) - …")
 *   - 5,000-song songbook → 4 digits ("0042 (XYZ) - …")
 *
 * Filenames are sanitised against the strictest filesystem we ship
 * to (Windows): the reserved characters /\:*?"<>| are replaced with
 * a hyphen, control characters are stripped, and trailing dots /
 * spaces (which Windows silently drops) are trimmed.
 *
 * The helpers don't append an extension — callers add ".json",
 * ".zip", etc. so the same helper serves every export format.
 */

/**
 * Characters Windows refuses in filenames, plus the path separator
 * on the other two OSes we care about. Replaced with "-" so a song
 * titled "Faith / Hope / Love" becomes "Faith - Hope - Love"
 * instead of vanishing path components. Control characters are NOT
 * in this set — see sanitiseSegment for why they're handled
 * separately.
 */
const FILESYSTEM_UNSAFE = /[\\/:*?"<>|]/g;

/**
 * Strip / replace characters that would break a filename on
 * Windows, macOS, Linux, iOS, or Android, then trim trailing dots
 * and spaces (which Windows silently drops on save). An empty or
 * whitespace-only input falls through to "_" so the caller still
 * gets a usable segment.
 *
 * Whitespace control characters (tab, newline, CR) are folded to a
 * single space FIRST, before the filesystem-unsafe replacement, so
 * a title accidentally containing a tab doesn't generate a hyphen
 * where the curator clearly meant a word boundary. Other control
 * characters (NULs and the like) are stripped outright.
 *
 * @param {string} name  Raw segment (title, songbook name, tune…).
 * @returns {string}     Filesystem-safe segment.
 */
export function sanitiseSegment(name) {
    if (name === null || name === undefined) return '_';
    let out = String(name);
    /* Whitespace control chars → single space; other control chars → drop. */
    out = out.replace(/[\x00-\x1F\x7F]/g, (ch) => (/\s/.test(ch) ? ' ' : ''));
    /* Collapse runs of whitespace so "Two\n  spaces" doesn't keep
       its double-spaces in the filename. */
    out = out.replace(/\s+/g, ' ');
    /* Replace the Windows-reserved set with a hyphen. Done AFTER the
       whitespace pass so a tab-separated word boundary stays a space,
       not a hyphen. */
    out = out.replace(FILESYSTEM_UNSAFE, '-');
    out = out.trim();
    /* Windows drops trailing "." and " " on save — strip them
       proactively so we don't produce a filename that mutates
       silently between download and disk. */
    out = out.replace(/[. ]+$/, '');
    return out !== '' ? out : '_';
}

/**
 * Compute the zero-padding width for song numbers in a songbook so
 * that filenames sort numerically when listed lexicographically.
 *
 * @param {Array<{number?: number|string}>} songbookSongs
 *        The songs that belong to the songbook in question. Only
 *        their `number` fields are read; non-numeric / missing
 *        numbers are ignored when computing the max.
 * @param {number} [min=3]  Floor on the returned width. Three is
 *                          enough for any songbook under 1,000
 *                          songs and matches the convention used
 *                          by .SourceSongData/.
 * @returns {number}        Number of digits to pad to.
 */
export function paddingWidthFor(songbookSongs, min = 3) {
    let maxNumber = 0;
    if (Array.isArray(songbookSongs)) {
        for (const s of songbookSongs) {
            const n = parseInt(s && s.number, 10);
            if (Number.isFinite(n) && n > maxNumber) {
                maxNumber = n;
            }
        }
    }
    const widthFromMax = maxNumber > 0 ? String(maxNumber).length : 0;
    return Math.max(min | 0, widthFromMax);
}

/**
 * Zero-pad a song number to the given width.
 *
 *   padNumber(7,  3) → "007"
 *   padNumber(42, 4) → "0042"
 *   padNumber(0,  3) → "000"   (caller decides whether 0/null is meaningful)
 */
export function padNumber(number, width) {
    const n = parseInt(number, 10);
    const safe = Number.isFinite(n) && n >= 0 ? n : 0;
    return String(safe).padStart(Math.max(1, width | 0), '0');
}

/**
 * Build the default filename for an individual-song export.
 *
 *   songExportFilename({ number: 7, songbook: 'CIS', title: 'Joy' },
 *                      cisSongs)
 *     → "007 (CIS) - Joy"
 *
 *   songExportFilename({ number: 42, songbook: 'CIS', title: 'Joy',
 *                        tuneName: 'Lasst uns erfreuen' },
 *                      cisSongs)
 *     → "042 (CIS) - Joy (Lasst uns erfreuen)"
 *
 * @param {object} song
 *        Song record. Reads .number, .songbook (abbrev), .title,
 *        and .tuneName (optional).
 * @param {Array<object>} songbookSongs
 *        Other songs in the same songbook — used to derive the
 *        padding width. Pass an empty array to fall back to the
 *        floor (3 digits).
 * @param {object} [opts]
 * @param {number} [opts.minWidth=3]  Padding floor.
 * @returns {string}                  Filename WITHOUT extension.
 */
export function songExportFilename(song, songbookSongs, opts = {}) {
    const minWidth = Number.isFinite(opts.minWidth) ? opts.minWidth : 3;
    const width    = paddingWidthFor(songbookSongs, minWidth);
    const padded   = padNumber(song && song.number, width);
    const abbr     = sanitiseSegment(song && song.songbook ? song.songbook : '');
    const title    = sanitiseSegment(song && song.title ? song.title : 'Untitled');
    const tune     = song && song.tuneName ? String(song.tuneName).trim() : '';
    const base     = `${padded} (${abbr}) - ${title}`;
    if (tune !== '') {
        return `${base} (${sanitiseSegment(tune)})`;
    }
    return base;
}

/**
 * Build the default filename for a whole-songbook bundle export.
 *
 *   songbookExportFilename({ name: 'Christ in Song', id: 'CIS' })
 *     → "Christ in Song (CIS) [Bundle]"
 *
 * Accepts either { id, name } (the editor's in-memory shape) or
 * { abbreviation, name } (the songbooks page's row shape). The
 * first non-empty of those two abbrev fields wins.
 *
 * @param {object} songbook
 * @returns {string}  Filename WITHOUT extension.
 */
export function songbookExportFilename(songbook) {
    const name = sanitiseSegment(songbook && songbook.name ? songbook.name : 'Songbook');
    const abbr = sanitiseSegment(
        (songbook && (songbook.abbreviation || songbook.abbrev || songbook.id)) || ''
    );
    if (abbr === '' || abbr === '_') {
        return `${name} [Bundle]`;
    }
    return `${name} (${abbr}) [Bundle]`;
}
