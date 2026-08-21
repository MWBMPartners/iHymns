<?php
/**
 * title_normalize.php — shared song-title normalisation (#1064 / #1051 / #1908)
 * ================================================================================
 *
 * One canonical title-collapse used everywhere titles are de-duplicated, so
 * the bulk-import matcher, the lyrics-ingest song resolver (#1064) and the
 * duplicate-songs review page (#1064) all fold titles identically:
 *
 *   - Unicode-normalise (NFKD) + strip combining marks — folds "Niño" → "nino"
 *     AND, unlike the old iconv-only fold, PRESERVES non-Latin scripts
 *     ("耶稣爱我" stays "耶稣爱我" instead of collapsing to '')
 *   - lowercase
 *   - a 10-entry map for the handful of letters that are distinct code points,
 *     not base+combining-mark, so NFKD alone can't fold them (ł, ø, ß, …)
 *   - drop punctuation / apostrophes / smart quotes (anything that isn't a
 *     Unicode letter, number or whitespace)
 *   - collapse whitespace
 *
 * #1908 — WHY THIS CHANGED: the previous body was `iconv('UTF-8',
 * 'ASCII//TRANSLIT//IGNORE', $t)`, which is locale-dependent (differs between
 * glibc and musl/C-locale hosts — "Miłość" degraded to just "mio" on some
 * hosts) and, worse, is DESTRUCTIVE for any script iconv can't transliterate:
 * glibc's TRANSLIT substitutes a literal '?' per untransliterable code point
 * ("耶稣爱我" → "????"), which the old `[^\p{L}\p{N}\s]` strip then erased to
 * '' — every CJK/Cyrillic/Greek/Thai/… title folded to the empty string and
 * was unfindable by dedup or the folded search arm. `Normalizer::FORM_KD`
 * (ext-intl / ICU) is deterministic across hosts AND non-destructive: a
 * Chinese or Greek title decomposes and re-composes through NFKD with its
 * letters intact, so it now folds to itself (lowercased) instead of to ''.
 * See `slugifyMusicianName()` (musician_helpers.php:1023-1032) for the
 * existing Normalizer-in-this-codebase precedent this mirrors.
 *
 * NON-LATIN NOTES (read before "fixing" what looks like over-stripping):
 *   - THAI vowel signs are Unicode combining marks (`\p{Mn}`), so the
 *     combining-mark strip removes them too: "พระเยซู" → "พระเยซ". This is
 *     CONSISTENT on both the stored and query side (the same input always
 *     folds to the same output, so matching still works) and mirrors the
 *     deliberate Arabic-harakat insensitivity below — it is not special-cased.
 *   - ARABIC harakat (short-vowel marks) are combining marks too and are
 *     stripped the same way, which is the conventional (desirable) behaviour
 *     for Arabic search — most real-world Arabic text omits harakat anyway.
 *   - HANGUL (Korean) is NOT combining-mark-based, but NFKD still decomposes
 *     each precomposed syllable block into 2-3 conjoining jamo code points
 *     ("예수" stores as 4 decomposed jamo, not 2 syllables). This is
 *     self-consistent — every consumer folds through the SAME function, so a
 *     stored value and a live query decompose identically and still match —
 *     but it means NormalizedTitle is not meant to ever be RENDERED for
 *     Korean; it is a dedup/search key only (verified: every consumer is
 *     MATCH()/LIKE()/equality, never display).
 *
 * Framework-free (no $_SERVER / session reads) so it is safe to require from
 * the public API, the editor, and any /manage page.
 *
 * @link https://www.php.net/manual/en/class.normalizer.php
 * @link https://www.php.net/manual/en/normalizer.normalize.php
 * @link https://www.php.net/manual/en/function.iconv.php
 * @link https://unicode.org/reports/tr15/  Unicode Normalization Forms (NFKD)
 * @see includes/musician_helpers.php  slugifyMusicianName() — the existing
 *      Normalizer::FORM_KD precedent this mirrors
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

declare(strict_types=1);

if (!defined('IHYMNS_FOLD_SPECIAL')) {
    /**
     * IHYMNS_FOLD_SPECIAL — the 10 letters that are their OWN distinct Unicode
     * code points (not a base letter + a combining mark), so Normalizer::FORM_KD
     * cannot decompose them into an ASCII base automatically.
     *
     * ELI5: "ø" isn't secretly "o" with an invisible accent glued on the way
     * "é" is "e" + an accent — it's simply a different letter — so we have to
     * say by hand what its ASCII stand-in is.
     *
     * DETAILED / WHY THIS EXACT SET: these are precisely the letters the OLD
     * iconv ASCII//TRANSLIT fold used to fold that NFKD leaves untouched — this
     * map exists so the new fold's Latin-script output stays byte-identical to
     * the old one for these 10 letters even though the underlying mechanism
     * changed (D1). It is byte-identical, key-for-key and value-for-value, to
     * the client-side mirror `FOLD_SPECIAL` in js/utils/text.js — the
     * rule-#35 lockstep test in tests/php/test-search-fold.php parses that JS
     * object literal and asserts set-equality against this constant, so the
     * two can never silently drift apart. Never edit one without the other.
     *
     * @see appWeb/public_html/js/utils/text.js  FOLD_SPECIAL — the JS mirror
     */
    define('IHYMNS_FOLD_SPECIAL', [
        'ł' => 'l', 'ø' => 'o', 'đ' => 'd', 'æ' => 'ae', 'œ' => 'oe',
        'ħ' => 'h', 'ß' => 'ss', 'ð' => 'd', 'þ' => 'th', 'ı' => 'i',
    ]);
}

if (!function_exists('ihymns_normalize_title')) {
    /**
     * Collapse a song title for fuzzy de-duplication AND for the folded
     * search arm (ihymns_search_fold() below is an alias over this).
     *
     * ELI5: makes two spellings of "the same" title compare equal —
     * "Niño"/"Nino" and, since #1908, "耶稣爱我"/"耶稣爱我" (non-Latin
     * titles used to vanish to '' here; now they survive, lowercased and
     * mark-stripped, just like every other script).
     *
     * DETAILED: the primary path (ext-intl present, which is the overwhelming
     * majority of PHP hosts) is fully deterministic and non-destructive:
     * `Normalizer::FORM_KD` decomposes precomposed characters into a base
     * code point plus combining marks (also folding compatibility forms like
     * full-width Latin "Ａ"→"A" and the ideographic space U+3000 to an ASCII
     * space), then a `\p{M}+` strip removes every combining mark the
     * decomposition produced. The fallback path (no ext-intl — rare, but this
     * function must not fatal there) keeps the old iconv transliteration, but
     * with a NEW acceptance guard (D2): iconv's `//TRANSLIT//IGNORE` doesn't
     * return '' or false for untransliterable input the way the OLD guard
     * assumed — it substitutes a literal '?' per untransliterable character
     * ("耶稣爱我" → "????", a non-empty 4-char string), which the old
     * `!== ''` check could never catch, and the subsequent punctuation strip
     * then silently erased it to ''. The fix is to count '?' characters
     * BEFORE and after: if iconv minted any NEW '?' that wasn't already in
     * the input, the transliteration is rejected wholesale and the original
     * (un-transliterated) text is kept instead — un-accented loss beats
     * silent erasure to ''.
     *
     * Both branches then share the same tail: lowercase, apply the 10-entry
     * IHYMNS_FOLD_SPECIAL map for letters NFKD can't decompose on its own,
     * strip everything that isn't a Unicode letter/number/whitespace
     * (punctuation, apostrophes, smart quotes, hyphens, musical dingbats like
     * "♪"), and collapse runs of whitespace to one space.
     *
     * @param string $title Raw, untrimmed, any-script title text.
     * @return string The folded key. '' only for input with no letters/digits
     *                 at all (e.g. "♪ ♫ ♬" — this is the ONE legitimate '',
     *                 not a fold failure; every consumer that compares this
     *                 value for equality already guards `!== ''` before
     *                 treating a match as real — see D4 in the #1908 plan).
     * @link https://www.php.net/manual/en/normalizer.normalize.php
     */
    function ihymns_normalize_title(string $title): string
    {
        $t = trim($title);
        if ($t === '') {
            return '';
        }
        if (class_exists('Normalizer')) {
            /* Deterministic, locale-independent (ext-intl / ICU). FORM_KD also
               folds full-width Latin (Ａ→A) and the U+3000 ideographic space
               to an ASCII space, which the old iconv path happened to do too. */
            $n = \Normalizer::normalize($t, \Normalizer::FORM_KD);
            if (is_string($n)) {
                $t = $n;
            }
            $t = (string)preg_replace('/\p{M}+/u', '', $t);
        } else {
            /* intl-absent fallback: best-effort iconv, ACCEPTED only when it
               minted no NEW '?' — glibc TRANSLIT substitutes '?' per
               untransliterable char ("耶稣爱我" → "????"), which the strip
               below would otherwise erase to ''. See the D2 note above. */
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
            if (is_string($folded) && $folded !== ''
                && substr_count($folded, '?') === substr_count($t, '?')) {
                $t = $folded;
            }
        }
        $t = mb_strtolower($t, 'UTF-8');
        /* Fold the 10 letters NFKD leaves alone (ł, ø, ß, …) — see
           IHYMNS_FOLD_SPECIAL's own doc-block for why these need a map. */
        $t = strtr($t, IHYMNS_FOLD_SPECIAL);
        /* Keep only letters / numbers / whitespace (any script); drop
           apostrophes, hyphens, punctuation, smart quotes, dingbats, etc. */
        $t = (string)preg_replace('/[^\p{L}\p{N}\s]+/u', '', $t);
        $t = (string)preg_replace('/\s+/', ' ', $t);
        return trim($t);
    }
}

if (!function_exists('ihymns_search_fold')) {
    /**
     * ihymns_search_fold() — the ONE fold point for diacritic/apostrophe/script-
     * aware SEARCH (#1039 Part A). A thin semantic ALIAS over
     * ihymns_normalize_title(): the exact same Normalizer::FORM_KD (or
     * guarded-iconv fallback) + lowercase + special-letter map + unicode-
     * property strip (#1908).
     *
     * ELI5: turns "Miłość", "Noël", "aren’t" AND "耶稣爱我" into "milosc",
     * "noel", "arent" and "耶稣爱我" so a reader typing plain ASCII still finds
     * the accented / apostrophised song, and a reader typing in their own
     * non-Latin script still finds it too (pre-#1908 this used to fold every
     * non-Latin title to '', making it unfindable via the folded arm).
     *
     * WHY AN ALIAS, NOT A NEW FOLD (rule #22): using the identical fold on BOTH
     * the stored column (tblSongs.NormalizedTitle / LyricsTextFolded) AND the
     * live query is what makes a match internally consistent regardless of the
     * host's iconv/ICU quirks — a second, subtly-different transliterator
     * would drift the two apart. This is a third *use* of the exact dedup
     * fold, NOT a third fold: it is deliberately DISTINCT from
     * ihymns_sim_normalise() (song_similarity.php), the FUZZY-compare fold
     * that also strips a leading article.
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
