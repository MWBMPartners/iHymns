<?php
/**
 * iHymns — shared display-sort key helpers (#150)
 *
 * ELI5: when you alphabetise a shelf of hymnals, "The Australian Hymn Book"
 * belongs under A, not T. This turns a title into the string a human would
 * actually file it under, so the sort ignores a leading "The"/"A"/"An".
 *
 * WHY A SEPARATE HELPER (not ihymns_sim_normalise): the similarity fold in
 * includes/song_similarity.php ALSO strips diacritics, punctuation and
 * collapses whitespace — right for fuzzy DUPLICATE matching, wrong for a sort
 * key, because it would fold "Church's" → "churchs" and lose the natural
 * tie-break order on the rest of the title. This helper strips ONLY the single
 * leading article and lowercases for a case-insensitive compare; everything
 * after the article is preserved verbatim, so titles that share a first word
 * still sort sensibly against each other.
 *
 * The article list mirrors ihymns_sim_normalise()'s (English + a few common
 * Romance/German forms) so the two folds agree on "what is an article". It is
 * a stable, closed vocabulary; if it ever grows, update both call sites
 * (this file + song_similarity.php) together.
 *
 * @see includes/song_similarity.php  ihymns_sim_normalise() — the fuzzy fold
 * @see https://www.php.net/manual/en/function.preg-replace.php
 * @link https://github.com/MWBMPartners/iHymns/issues/150
 */

if (!function_exists('ihymns_title_sort_key')) {
    /**
     * Case-insensitive alphabetical sort key with a single leading article
     * ("the/a/an/o/el/la/le/les/der/die/das") removed.
     *
     * @param string $title The display title/name to derive a sort key from.
     * @return string       Lowercased title with a leading article stripped;
     *                      the remainder (punctuation, spacing) is preserved.
     */
    function ihymns_title_sort_key(string $title): string
    {
        // Lowercase (mb-aware) so both the article match and the compare are
        // case-insensitive — the article regex below is intentionally NOT /i so
        // this single fold governs case.
        $k = mb_strtolower(trim($title), 'UTF-8');
        // Strip ONE leading article. Same vocabulary as ihymns_sim_normalise().
        $k = preg_replace('/^(the|a|an|o|el|la|le|les|der|die|das)\s+/u', '', $k) ?? $k;
        return trim($k);
    }
}

if (!function_exists('ihymns_sort_by_title_key')) {
    /**
     * Stable in-place sort of a list of associative rows by an article-stripped
     * title key, with the raw (lowercased) title as the tie-break so two rows
     * whose stripped keys collide keep a deterministic order.
     *
     * @param array<int,array<string,mixed>> $rows  Rows to sort (by reference).
     * @param string                         $field The title/name field key.
     * @return void
     */
    function ihymns_sort_by_title_key(array &$rows, string $field): void
    {
        usort($rows, static function ($a, $b) use ($field): int {
            $an = (string)($a[$field] ?? '');
            $bn = (string)($b[$field] ?? '');
            $ak = ihymns_title_sort_key($an);
            $bk = ihymns_title_sort_key($bn);
            // Primary: article-stripped key. Secondary: raw lowercased title,
            // so "A Mighty Fortress" and "Mighty Fortress" stay deterministic.
            return [$ak, mb_strtolower($an, 'UTF-8')] <=> [$bk, mb_strtolower($bn, 'UTF-8')];
        });
    }
}
