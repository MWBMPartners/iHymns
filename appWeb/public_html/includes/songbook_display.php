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

if (!function_exists('ihymns_songbook_name_label')) {
    /**
     * The full-songbook-NAME sub-line label for a song list row (#1531),
     * server-side twin of the JS `songbookLabel(abbr, fullName)`
     * (js/constants.js) — emit the SAME markup so a PHP-rendered list row and a
     * client-rendered one look identical and the CSS in app.css (Section
     * `.songbook-name-full` / `.songbook-name-abbr`, #1531) governs both.
     *
     * ELI5: under a song's title, show the book's FULL NAME ("Seventh-day
     * Adventist Hymnal") instead of the jargon code ("SDAH"). This one helper
     * builds that little grey line so every page does it the same way (rule
     * #22) rather than hand-inlining the two spans.
     *
     * Mirrors `songbookLabel()` branch-for-branch: when no distinct full name
     * is available (empty, or byte-equal to the abbreviation) it returns the
     * bare escaped abbreviation — never an empty label and never the name
     * duplicated. Otherwise it returns BOTH the full name and the abbreviation,
     * each in its own span, so the responsive CSS can swap the full name for
     * the compact code on a narrow viewport (the abbr span is display:none by
     * default, shown only under ~360px — the same width-toggle the JS relies
     * on). The caller supplies the name (e.g. from a `tblSongbooks` JOIN); this
     * helper does NOT query — there is no server-side songbook registry, and
     * adding one per row would be the whole-corpus anti-pattern (rule #17).
     *
     * All output is HTML-escaped here, so the caller passes raw values.
     *
     * @param string|null $abbr The songbook Abbreviation (the SongId prefix; rule #27).
     * @param string|null $name The songbook full name / Title (NULL/'' ⇒ show the abbr alone).
     * @return string HTML: either the bare escaped abbr, or the two-span name+abbr structure.
     */
    function ihymns_songbook_name_label(?string $abbr, ?string $name): string
    {
        $abbr = trim((string) $abbr);
        $name = trim((string) $name);
        $safeAbbr = htmlspecialchars($abbr, ENT_QUOTES, 'UTF-8');
        /* No distinct full name (absent, or identical to the code) ⇒ show the
           code alone, exactly as songbookLabel() returns `safeAbbr` when
           `full === abbr`. strcasecmp so "MP" vs "mp" collapses too. */
        if ($name === '' || strcasecmp($name, $abbr) === 0) {
            return $safeAbbr;
        }
        return '<span class="songbook-name-full">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span class="songbook-name-abbr">' . $safeAbbr . '</span>';
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
