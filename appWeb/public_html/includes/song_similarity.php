<?php

declare(strict_types=1);

/**
 * song_similarity.php — shared song duplicate/counterpart scoring (#1215 / #1216)
 * ============================================================================
 *
 * ONE scorer for "are these two songs the same hymn?", used by:
 *   - the batch suggestion builder (includes/tools/build-song-link-suggestions.php),
 *   - the Duplicate & Counterpart review page (manage/duplicate-songs.php),
 *   - (future) the editor's inline "Suggested counterparts" panel.
 *
 * Before this module the builder carried its own private `_bsls_*` copies of
 * the maths; extracting them here is the CLAUDE.md modularity rule (one shared
 * implementation, no divergent forks).
 *
 * TWO normalisers, deliberately distinct — do not conflate them:
 *   - ihymns_normalize_title()  (includes/title_normalize.php) — the EXACT dedup
 *     fold used for grouping + the stored tblSongs.NormalizedTitle column +
 *     the ingest resolver. Accent-fold → lowercase → strip punctuation. Keeps
 *     leading articles so "A" and "The" titles don't collide.
 *   - ihymns_sim_normalise()    (HERE) — the FUZZY-COMPARE fold. Same folding
 *     PLUS leading-article stripping, because for similarity scoring "The Church's
 *     One Foundation" and "Church's One Foundation" should compare as near-equal.
 *
 * Scoring blend (unchanged from #808): 0.50·title + 0.35·first-line-lyrics +
 * 0.15·author-overlap. A shared hard identifier (ISWC / CCLI / ISRC) overrides
 * the blend to a certain match — those codes identify the composition/recording
 * by definition (#1066 identity model).
 *
 * Framework-free (no $_SERVER / session reads) so it is safe to require from the
 * public API, the editor, the CLI builder, and any /manage page.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

if (!function_exists('ihymns_sim_normalise')) {
    /**
     * Fuzzy-compare normalisation: lowercase, strip diacritics, drop leading
     * articles, strip punctuation, collapse whitespace.
     *
     * ELI5: squash a title down to its bare words so small spelling/wording
     * differences don't stop two versions of the same hymn from matching.
     *
     * @see https://www.php.net/manual/en/function.iconv.php  (ASCII//TRANSLIT)
     */
    function ihymns_sim_normalise(string $s): string
    {
        // Lowercase first (mb-aware) so the article regex + compare are case-insensitive.
        $s = mb_strtolower($s, 'UTF-8');
        // Strip diacritics by transliterating to ASCII. iconv() is wrapped: on
        // hosts where TRANSLIT isn't available we keep the original rather than
        // blanking the string (best-effort, locale-dependent).
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }
        // Drop a single leading article (English + a few common Romance/German
        // forms) — "the/a/an/o/el/la/le/les/der/die/das".
        $s = preg_replace('/^(the|a|an|o|el|la|le|les|der|die|das)\s+/u', '', $s) ?? $s;
        // Keep only letters / numbers / whitespace; everything else → space.
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
        // Collapse runs of whitespace to a single space.
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}

if (!function_exists('ihymns_sim_text')) {
    /**
     * Normalised Levenshtein similarity: 1 - levenshtein(a,b) / max(|a|,|b|).
     *
     * ELI5: 1.0 = identical strings, 0.0 = completely different. We measure how
     * many single-character edits turn one into the other, scaled by length.
     *
     * PHP's levenshtein() caps at 255 bytes, so longer inputs (a long opening
     * verse used as the first-line signal) are truncated to keep it defined.
     *
     * @see https://www.php.net/manual/en/function.levenshtein.php
     */
    function ihymns_sim_text(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 0.0;
        }
        $a = mb_substr($a, 0, 255);
        $b = mb_substr($b, 0, 255);
        $max = max(strlen($a), strlen($b));
        if ($max === 0) {
            return 0.0;
        }
        $d = levenshtein($a, $b);
        return 1.0 - ($d / $max);
    }
}

if (!function_exists('ihymns_sim_authors_jaccard')) {
    /**
     * Jaccard overlap on the lowercase token set of two pipe-joined author
     * strings (writers + composers). |A ∩ B| / |A ∪ B|.
     *
     * ELI5: of all the writer/composer names across both songs, what fraction
     * appear on BOTH? Shared authors nudge the score up; disjoint authors don't.
     *
     * @see https://en.wikipedia.org/wiki/Jaccard_index
     */
    function ihymns_sim_authors_jaccard(string $a, string $b): float
    {
        $tokA = array_filter(array_map('trim', explode('|', mb_strtolower($a, 'UTF-8'))));
        $tokB = array_filter(array_map('trim', explode('|', mb_strtolower($b, 'UTF-8'))));
        if (!$tokA && !$tokB) {
            return 0.0;
        }
        $setA = array_flip($tokA);
        $setB = array_flip($tokB);
        $inter = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $inter;
        return $union > 0 ? ($inter / $union) : 0.0;
    }
}

if (!function_exists('ihymns_sim_confidence_tier')) {
    /**
     * Map a composite score + signal onto the tblSongLinkSuggestions.Confidence
     * ENUM ('high'|'medium'|'low'). A hard-identifier signal is always 'high';
     * otherwise the fuzzy blend tiers at 0.90 / 0.80.
     *
     * ELI5: turn the raw % into a traffic-light a curator can sort by, and treat
     * an exact ISWC/CCLI/ISRC match as a green light no matter what the words say.
     */
    function ihymns_sim_confidence_tier(float $score, string $signal): string
    {
        if ($signal !== '' && $signal !== 'fuzzy') {
            // A shared hard identifier (ISWC/CCLI/ISRC) is authoritative.
            return 'high';
        }
        if ($score >= 0.90) {
            return 'high';
        }
        if ($score >= 0.80) {
            return 'medium';
        }
        return 'low';
    }
}

if (!function_exists('ihymns_sim_score')) {
    /**
     * Score a candidate pair as "same hymn?". Returns the composite + the three
     * sub-scores + the firing signal + the confidence tier.
     *
     * ELI5: compare two songs on their title, opening line and authors, blend
     * those into one %, but if they share a song/recording code just call it a
     * certain match.
     *
     * @param array $a Feature row: normTitle, normFirstLine, authors (pipe-joined),
     *                 and optional isrc / iswc / ccli (raw, '' if none).
     * @param array $b Same shape as $a.
     * @return array{score:float,title:float,lyrics:float,authors:float,signal:string,confidence:string}
     */
    function ihymns_sim_score(array $a, array $b): array
    {
        // --- Fuzzy sub-scores (always computed, so the UI can show the breakdown
        //     even when a hard ID is what clinched it). ---
        $titleScore   = ihymns_sim_text((string)($a['normTitle'] ?? ''),     (string)($b['normTitle'] ?? ''));
        $lyricsScore  = ihymns_sim_text((string)($a['normFirstLine'] ?? ''), (string)($b['normFirstLine'] ?? ''));
        $authorsScore = ihymns_sim_authors_jaccard((string)($a['authors'] ?? ''), (string)($b['authors'] ?? ''));

        $blend = (0.50 * $titleScore) + (0.35 * $lyricsScore) + (0.15 * $authorsScore);

        // --- Hard-identifier override. A shared code means the same work/recording
        //     regardless of how the words were typed. Priority ISWC > CCLI > ISRC:
        //     ISWC + CCLI identify the COMPOSITION (the song), ISRC a specific
        //     recording of it (#1066 identity model). ---
        $signal = 'fuzzy';
        $hardPairs = [
            'shared-iswc' => [trim((string)($a['iswc'] ?? '')), trim((string)($b['iswc'] ?? ''))],
            'shared-ccli' => [trim((string)($a['ccli'] ?? '')), trim((string)($b['ccli'] ?? ''))],
            'shared-isrc' => [trim((string)($a['isrc'] ?? '')), trim((string)($b['isrc'] ?? ''))],
        ];
        foreach ($hardPairs as $sig => [$va, $vb]) {
            // Both present and equal (case-insensitive) → that code is the signal.
            if ($va !== '' && strcasecmp($va, $vb) === 0) {
                $signal = $sig;
                break;
            }
        }

        $score = ($signal === 'fuzzy') ? $blend : 1.0;

        return [
            'score'      => $score,
            'title'      => $titleScore,
            'lyrics'     => $lyricsScore,
            'authors'    => $authorsScore,
            'signal'     => $signal,
            'confidence' => ihymns_sim_confidence_tier($score, $signal),
        ];
    }
}
