<?php
/**
 * title_normalize.php — shared song-title normalisation (#1064 / #1051)
 * =====================================================================
 *
 * One canonical title-collapse used everywhere titles are de-duplicated, so
 * the bulk-import matcher, the lyrics-ingest song resolver (#1064) and the
 * duplicate-songs review page (#1064) all fold titles identically:
 *
 *   - ASCII-fold accents  ("Niño" → "nino")
 *   - lowercase
 *   - drop punctuation / apostrophes / smart quotes
 *   - collapse whitespace
 *
 * Framework-free (no $_SERVER / session reads) so it is safe to require from
 * the public API, the editor, and any /manage page.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

declare(strict_types=1);

if (!function_exists('ihymns_normalize_title')) {
    /**
     * Collapse a song title for fuzzy de-duplication.
     */
    function ihymns_normalize_title(string $title): string
    {
        $t = trim($title);
        /* Fold accents to ASCII so "Niño" ~ "Nino" (best-effort; locale-dependent). */
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        if (is_string($folded) && $folded !== '') {
            $t = $folded;
        }
        $t = mb_strtolower($t, 'UTF-8');
        /* Keep only letters / numbers / whitespace; drop apostrophes, hyphens,
           punctuation, smart quotes, etc. */
        $t = (string)preg_replace('/[^\p{L}\p{N}\s]+/u', '', $t);
        $t = (string)preg_replace('/\s+/', ' ', $t);
        return trim($t);
    }
}

if (!function_exists('ihymns_search_fold')) {
    /**
     * ihymns_search_fold() — the ONE fold point for diacritic/apostrophe-aware
     * SEARCH (#1039 Part A). A thin semantic ALIAS over ihymns_normalize_title():
     * the exact same iconv ASCII//TRANSLIT + lowercase + unicode-property strip.
     *
     * ELI5: turns "Miłość", "Noël" and "aren’t" into "milosc", "noel" and "arent"
     * so a reader typing plain ASCII still finds the accented / apostrophised song.
     *
     * WHY AN ALIAS, NOT A NEW FOLD (rule #22): using the identical fold on BOTH the
     * stored column (tblSongs.NormalizedTitle / LyricsTextFolded) AND the live query
     * is what makes a match internally consistent regardless of the host's iconv
     * quirks — a second, subtly-different transliterator would drift the two apart.
     * This is a third *use* of the exact dedup fold, NOT a third fold: it is
     * deliberately DISTINCT from ihymns_sim_normalise() (song_similarity.php), the
     * FUZZY-compare fold that also strips a leading article.
     *
     * @see ihymns_normalize_title()  the single implementation this delegates to
     * @see includes/search_fold.php  the write-path helper that stores the fold
     * @link https://www.php.net/manual/en/function.iconv.php
     */
    function ihymns_search_fold(string $text): string
    {
        return ihymns_normalize_title($text);
    }
}
