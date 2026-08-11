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

if (!function_exists('ihymns_sim_fold')) {
    /**
     * The common fold shared by every normaliser in this file: lowercase,
     * strip diacritics, strip punctuation, collapse whitespace. Deliberately
     * does NOT strip a leading article — that is ihymns_sim_normalise()'s
     * job, layered on top, because the NAME-similarity normaliser
     * (ihymns_sim_name_normalise(), #1785) must NOT strip one: a person
     * surname particle like "La Trobe" would otherwise lose "La" and
     * collide with unrelated "Trobe" names.
     *
     * ELI5: squash any piece of text down to its bare lowercase words so
     * small spelling/typography differences don't stop two near-identical
     * strings from matching.
     *
     * DETAILED / WHY extracted (#1785 rule #22): before this, the fold
     * logic lived only inside ihymns_sim_normalise() (title fold, which
     * also strips a leading article). The musician-name similarity work
     * needs the SAME accent/punctuation/whitespace fold WITHOUT the
     * article-strip, so rather than copy the four preg_replace lines a
     * second time (the exact kind of fork rule #22 exists to prevent),
     * this splits the fold out and re-expresses ihymns_sim_normalise() as
     * article-strip(ihymns_sim_fold($s)) below — byte-identical output,
     * proved by tests/php/test-song-similarity.php passing unchanged.
     *
     * @see https://www.php.net/manual/en/function.iconv.php  (ASCII//TRANSLIT)
     */
    function ihymns_sim_fold(string $s): string
    {
        // Lowercase first (mb-aware) so downstream regexes + compares are case-insensitive.
        $s = mb_strtolower($s, 'UTF-8');
        // Strip diacritics by transliterating to ASCII. iconv() is wrapped: on
        // hosts where TRANSLIT isn't available we keep the original rather than
        // blanking the string (best-effort, locale-dependent).
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }
        // Keep only letters / numbers / whitespace; everything else → space.
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
        // Collapse runs of whitespace to a single space.
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}

if (!function_exists('ihymns_sim_normalise')) {
    /**
     * Fuzzy-compare normalisation: ihymns_sim_fold() PLUS dropping a
     * leading article — correct for TITLES ("The Church's One Foundation"
     * vs "Church's One Foundation" should compare as near-equal) but wrong
     * for PERSON NAMES (see ihymns_sim_name_normalise() below, which
     * shares the fold but skips this step).
     *
     * ELI5: squash a title down to its bare words so small spelling/wording
     * differences don't stop two versions of the same hymn from matching.
     */
    function ihymns_sim_normalise(string $s): string
    {
        $s = ihymns_sim_fold($s);
        // Drop a single leading article (English + a few common Romance/German
        // forms) — "the/a/an/o/el/la/le/les/der/die/das". Fold already
        // collapsed whitespace to single spaces, so the trailing \s+ in this
        // pattern always matches exactly one space when the article fires.
        $s = preg_replace('/^(the|a|an|o|el|la|le|les|der|die|das)\s+/u', '', $s) ?? $s;
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

/* =========================================================================
 * NAME similarity (#1785) — a PERSON-name sibling of the title/lyrics
 * scoring above, added for the musicians registry-duplicate detector
 * (/manage/musician-duplicates, plan §4.1). Built ON the existing
 * primitives (ihymns_sim_fold(), ihymns_sim_text(),
 * ihymns_sim_authors_jaccard()) rather than a fresh set — this is what
 * rule #22 means by "extend the ONE scorer, never re-fork the maths".
 * The private fork this replaces was manage/musicians-bulk-promote.php's
 * _musBulkNormalise()/_musBulkTokens()/_musBulkSimilarity() (deleted in
 * the very next commit of the #1785 sequence) — it even said in its own
 * doc-comment "mirrors the scoring shape from
 * build-song-link-suggestions.php", i.e. it already knew it was a fork.
 * ========================================================================= */

if (!function_exists('ihymns_sim_name_normalise')) {
    /**
     * PERSON-NAME fold: ihymns_sim_fold() ALONE — no leading-article strip.
     *
     * ELI5: same accent/punctuation/whitespace squash the title scorer uses,
     * but a person's name never has its first word treated as "the/a/an" —
     * that would wrongly eat the "La" off a surname particle like "La Trobe"
     * and make it collide with an unrelated "Trobe".
     *
     * DETAILED / WHY a separate function instead of just calling
     * ihymns_sim_fold() at each name call site: naming the musician-specific
     * entry point ihymns_sim_name_normalise() documents INTENT at the call
     * site (this is the person-name fold, not the title fold) and gives
     * musicianNameVariantClass() (includes/musician_helpers.php, #1785) one
     * stable symbol to depend on even if the shared fold's internals change
     * later.
     */
    function ihymns_sim_name_normalise(string $name): string
    {
        return ihymns_sim_fold($name);
    }
}

if (!function_exists('ihymns_sim_name_tokens')) {
    /**
     * Whitespace-split the folded name into its word tokens, dropping any
     * empty entries a run of punctuation-turned-spaces might otherwise
     * leave behind.
     *
     * ELI5: "John Newton" → ["john", "newton"] — used to compare two names
     * as BAGS of words rather than as one long string, which is what makes
     * "Newton, John" and "John Newton" score highly despite the comma and
     * word order.
     *
     * @return list<string>
     */
    function ihymns_sim_name_tokens(string $name): array
    {
        $folded = ihymns_sim_name_normalise($name);
        if ($folded === '') {
            return [];
        }
        return array_values(array_filter(explode(' ', $folded), static fn(string $t): bool => $t !== ''));
    }
}

if (!function_exists('ihymns_sim_name_score')) {
    /**
     * Score a candidate PERSON-NAME pair in [0, 1]. Re-expresses the maths
     * that used to live privately in manage/musicians-bulk-promote.php's
     * _musBulkSimilarity() through the shared primitives: fold-equal names
     * score 1.0 outright; otherwise a 0.6·token-Jaccard + 0.4·edit-distance
     * blend (the bulk-promote weights, kept unchanged so its existing 0.85
     * default threshold still means roughly the same thing after the
     * switch — #1785 C3).
     *
     * ELI5: "how likely are these two typed names the same person?" — a
     * blend of "do they share the same words, in any order" (token overlap)
     * and "how many single-character edits turn one into the other" (edit
     * distance), because either signal alone is fooled by a common case:
     * word-overlap alone can't tell "John Newton" from "Newton John Smith"
     * as well as this blend does, and edit-distance alone scores
     * "Newton, John" vs "John Newton" low despite them being the same name
     * because of the comma + reordering.
     *
     * DETAILED / two documented behaviour deltas vs the fork this replaces
     * (#1785 C3 commit body expands on these with real before/after
     * numbers): (1) diacritics now fold via ihymns_sim_fold()'s
     * iconv(...//TRANSLIT...) step, so "José" vs "Jose" now scores 1.0
     * (strictly better — the fork's own preg_replace-based normaliser had
     * no accent-folding step at all); (2) the >255-byte path now truncates
     * (ihymns_sim_text()'s rule, mirroring PHP's levenshtein() byte cap)
     * instead of falling back to similar_text() — unreachable in practice
     * for personal names, which never approach 255 bytes.
     *
     * @return float 0.0 (nothing alike) .. 1.0 (same name)
     */
    function ihymns_sim_name_score(string $a, string $b): float
    {
        $foldA = ihymns_sim_name_normalise($a);
        $foldB = ihymns_sim_name_normalise($b);
        if ($foldA === '' || $foldB === '') {
            return 0.0;
        }
        if ($foldA === $foldB) {
            return 1.0;
        }

        $editScore = ihymns_sim_text($foldA, $foldB);

        $tokensA = ihymns_sim_name_tokens($a);
        $tokensB = ihymns_sim_name_tokens($b);
        $tokenScore = ihymns_sim_authors_jaccard(implode('|', $tokensA), implode('|', $tokensB));

        // Token-overlap weighted higher than edit-distance — tuned (in the
        // fork this replaces) to land "J. Newton" vs "John Newton" near the
        // 0.85 default merge-review threshold.
        return (0.6 * $tokenScore) + (0.4 * $editScore);
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
