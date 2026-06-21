<?php

declare(strict_types=1);

/**
 * iHymns — Songbook display helpers (#1328 / #1332)
 *
 * Tiny shared presentation logic for songbooks so the same rule isn't
 * re-implemented across the home tiles, the /songbooks list, the songbook
 * header, the song-meta line and the admin table.
 */

if (!function_exists('ihymns_songbook_abbr_label')) {
    /**
     * The label to show on a songbook's abbreviation badge (#1332).
     *
     * ELI5: a book has a strict code (used in song URLs, letters/numbers only)
     * and, optionally, a prettier display label that can contain any characters
     * (e.g. "AH-OLD", "Psalty:Kids"). Show the pretty one if it's set, else the
     * code. The code itself NEVER changes — only what the badge displays.
     *
     * @param string|null $abbr        The strict Abbreviation / SongId-prefix code.
     * @param string|null $displayAbbr The optional free-text display label (NULL/'' = use the code).
     */
    function ihymns_songbook_abbr_label(?string $abbr, ?string $displayAbbr = null): string
    {
        $displayAbbr = trim((string) $displayAbbr);
        return $displayAbbr !== '' ? $displayAbbr : trim((string) $abbr);
    }
}

if (!function_exists('ihymns_songbook_show_abbr')) {
    /**
     * Whether to render a songbook's Abbreviation badge alongside its Title.
     *
     * ELI5: if a book's short code is the same word as its full name, showing
     * both just prints the word twice — so we hide the code in that case.
     *
     * Returns false when the abbreviation is empty or case-insensitively equal
     * to the title (e.g. the unofficial "Psalty" book whose abbreviation is also
     * "Psalty", #1328). The badge is only meaningful when it differs from the
     * title it sits beside; abbreviation-only contexts (no title shown) should
     * NOT call this — they always render the code.
     *
     * @param string|null $title The songbook's display name / title.
     * @param string|null $abbr  The songbook's abbreviation (a.k.a. id).
     */
    function ihymns_songbook_show_abbr(?string $title, ?string $abbr): bool
    {
        $abbr = trim((string) $abbr);
        if ($abbr === '') {
            return false;
        }
        return strcasecmp(trim((string) $title), $abbr) !== 0;
    }
}
