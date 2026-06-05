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
