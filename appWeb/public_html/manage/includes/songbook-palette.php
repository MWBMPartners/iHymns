<?php

declare(strict_types=1);

/**
 * iHymns — Songbook auto-colour palette (#677)
 *
 * One source of truth for the curated palette used to auto-assign a
 * Colour to a songbook when the create form / bulk import leaves the
 * field blank. Required by both:
 *   - /manage/songbooks (case 'create')
 *   - /manage/editor/api.php (_bulkImport_upsertSongbook)
 *
 * Picker contract:
 *   pickAutoSongbookColour(mysqli $db): string
 *     Returns the first palette colour not currently used by any
 *     tblSongbooks.Colour. Falls back to a deterministic hash of
 *     the abbreviation if every palette entry is in use, so a
 *     deployment with > count(palette) coloured songbooks still
 *     gets a distinct hex per book.
 *
 * The palette is curated to ~20 visually distinct hexes that work
 * on both light + dark themes. Existing seed colours (CIS purple,
 * SDAH amber, JP pink, MP teal, CP blue) lead so a fresh install's
 * auto-coloured rows look familiar to anyone who's seen the
 * project before.
 */

const SONGBOOK_AUTO_COLOURS = [
    /* Existing seeds (keep first so a fresh install reuses them). */
    '#6366F1',  // CIS purple
    '#F59E0B',  // SDAH amber
    '#EC4899',  // JP pink
    '#10B981',  // MP teal
    '#3B82F6',  // CP blue
    /* Extension palette — chosen for >= 4.5:1 contrast against the
       iHymns dark surface; double-checked against the light theme
       too. No two adjacent entries share a hue family. */
    '#EF4444',  // red
    '#8B5CF6',  // violet
    '#06B6D4',  // cyan
    '#84CC16',  // lime
    '#F97316',  // orange
    '#14B8A6',  // teal-2
    '#A855F7',  // purple
    '#0EA5E9',  // sky
    '#22C55E',  // green
    '#D946EF',  // fuchsia
    '#0891B2',  // cyan-dark
    '#7C3AED',  // violet-dark
    '#16A34A',  // green-dark
    '#DB2777',  // pink-dark
    '#9333EA',  // purple-dark
];

/**
 * Pick a palette colour for a brand-new songbook. Returns the first
 * entry not currently in tblSongbooks.Colour; falls back to a
 * hash-derived hex if every palette colour is in use.
 *
 * Pure read against tblSongbooks — never writes — so the caller is
 * free to plug the result into an existing INSERT.
 *
 * @param mysqli $db   Editor / songbooks page DB handle.
 * @param string $abbr The new songbook's abbreviation, used as the
 *                     hash seed for the fallback path.
 */
function pickAutoSongbookColour(\mysqli $db, string $abbr): string
{
    /* Read the colours currently in use. The SELECT lives in this
       helper so a future tweak to the palette / hashing rule has
       one site to change. */
    $used = [];
    try {
        $stmt = $db->prepare(
            "SELECT Colour FROM tblSongbooks
              WHERE Colour LIKE '#%' AND LENGTH(Colour) = 7"
        );
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_row()) {
            /* Normalise to uppercase so #1a73e8 vs #1A73E8 don't
               count as two different shades. */
            $used[strtoupper($row[0])] = true;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        /* If the DB read fails we fall through to the hash path —
           a single random-ish colour is better than crashing the
           save. */
        error_log('[songbook-palette] colour read skipped: ' . $e->getMessage());
    }

    /* First palette colour not currently in use wins. */
    foreach (SONGBOOK_AUTO_COLOURS as $hex) {
        if (!isset($used[strtoupper($hex)])) {
            return $hex;
        }
    }

    /* Every palette colour is taken — derive a deterministic hex
       from the abbreviation so the same songbook gets the same
       colour on every re-import. crc32 spread is uniform enough
       for distinct-colour purposes; bitwise-AND clamps to a 24-bit
       range. */
    $hash = abs(crc32($abbr));
    return sprintf('#%06X', $hash & 0xFFFFFF);
}
