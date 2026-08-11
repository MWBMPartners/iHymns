<?php

declare(strict_types=1);

/**
 * test-musician-dedup-shared-scorer.php — rule #22 guard (G2, #1785)
 * ======================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT THIS GUARDS
 * -----------------
 * manage/musicians-bulk-promote.php used to carry its own private
 * _musBulkSimilarity() fork of the duplicate/counterpart similarity maths
 * (deleted in #1785 C3, re-pointed at includes/song_similarity.php's
 * ihymns_sim_name_score()). This guard makes sure that fork can never
 * silently come back — anywhere in the tree — by deriving, from the SOURCE
 * TREE ITSELF (rule #34: a guard must be tree-derived, not a hand-typed
 * list), every PHP file under appWeb/public_html whose COMMENT-STRIPPED
 * source calls PHP's own levenshtein() or similar_text(), and asserting
 * that set is EXACTLY the known-legitimate one below — no more, no fewer.
 *
 * WHY COMMENT-STRIPPED (not a plain grep)
 * -----------------------------------------
 * A bare substring search would false-positive on any file that merely
 * MENTIONS "levenshtein()" in a doc-comment explaining why it delegates to
 * the shared scorer instead of calling it directly — which is exactly what
 * includes/tools/build-song-link-suggestions.php does (a comment reading
 * "skip the levenshtein() to save cycles" describing a pre-filter that
 * short-circuits BEFORE calling ihymns_sim_score()). Stripping T_COMMENT /
 * T_DOC_COMMENT tokens via PHP's own tokenizer (token_get_all(), the same
 * idiom tests/php/test-restrictions-effect-honesty.php already uses) means
 * this guard reacts to real CODE, not commentary about code.
 *
 * WHY THREE FILES ARE ALLOWED, NOT ONE (derived, not guessed — each has a
 * documented, distinct reason it is NOT a rule-#22 fork):
 *
 *   - includes/song_similarity.php   — the ONE scorer itself. Obviously allowed.
 *   - includes/lyric_lines_sync.php  — lyricLinesSimilarity() operates on
 *     individual LYRIC LINES for the Id-preserving diff/sync write path
 *     (rule #25), comparing by UTF-8 CODE POINT (not byte) because PHP's
 *     built-in levenshtein() is byte-based and undefined past 255 bytes —
 *     its own doc-block says so explicitly. similar_text() is only a
 *     fallback for invalid-UTF8 / pathologically-long lines. This is
 *     LINE-LEVEL CHANGE-TRACKING, a different domain from duplicate/
 *     counterpart SONG or MUSICIAN detection — rule #22 governs the
 *     latter, not every possible use of an edit-distance metric anywhere
 *     in the app.
 *   - includes/song_importers.php    — _bulkImport_findDuplicateCandidates()
 *     runs a same-SONGBOOK, near-duplicate TITLE check during a bulk XML/
 *     CSV import preview. This predates #1785 (musician-registry dedup)
 *     and is a narrower, PRE-EXISTING concern (import-time title collision,
 *     not the song-similarity OR musician-name-similarity scorers this
 *     guard's sibling work touches) — refactoring it is out of scope for
 *     #1785's musicians-only remit and is intentionally left alone here
 *     (a `for consideration` candidate for a FUTURE, separately-scoped
 *     pass, not silently ignored).
 *
 * A file appearing here that ISN'T one of these three is either a brand
 * new private fork (fix it: delete + call includes/song_similarity.php's
 * ihymns_sim_text() / ihymns_sim_name_score() instead) or a genuinely new
 * legitimate user that needs its OWN entry + justification added to this
 * list, mirroring the reasoning above — never silently expanded.
 *
 *   php tests/php/test-musician-dedup-shared-scorer.php
 *
 * Exit status 0 = the derived set matches exactly, 1 = it drifted.
 *
 * @see .claude/musicians-dedup-1785-plan.md §10 (guard G2)
 * @see appWeb/public_html/includes/song_similarity.php  the ONE scorer (rule #22)
 */

$root   = dirname(__DIR__, 2);
$pubDir = $root . '/appWeb/public_html';

/* Strip PHP comments via the tokenizer (never a hand-rolled regex over
   raw source — a `//` or `/*` inside a string literal would corrupt a
   regex-based stripper). Mirrors tests/php/test-restrictions-effect-
   honesty.php's rehPhpCode(). Newlines are preserved in place of a
   stripped comment so line numbers stay meaningful if this ever needs
   to report one. */
function musDedupStripPhpComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/* Tree-derived: walk every .php file under appWeb/public_html (the whole
   web app — not a hand-picked directory) and test each one's
   comment-stripped source for a REAL call (open-paren required, so a bare
   mention of the word without a call doesn't match). */
function musDedupFindScorerUsers(string $pubDir): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pubDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $src  = (string)file_get_contents($path);
        $stripped = musDedupStripPhpComments($src);
        if (preg_match('/\b(?:levenshtein|similar_text)\s*\(/', $stripped)) {
            $hits[] = str_replace($pubDir . '/', '', $path);
        }
    }
    sort($hits);
    return $hits;
}

/* The pinned, derived-and-justified allow-list (see the file doc-block
   above for WHY each of these three, and only these three, is here). */
$allowed = [
    'includes/lyric_lines_sync.php',
    'includes/song_importers.php',
    'includes/song_similarity.php',
];

$actual = musDedupFindScorerUsers($pubDir);

$extra   = array_values(array_diff($actual, $allowed));
$missing = array_values(array_diff($allowed, $actual));

$failed = 0;

if ($extra) {
    $failed++;
    fwrite(STDERR, "FAIL: " . count($extra) . " file(s) call levenshtein()/similar_text() but are NOT on the allow-list:\n");
    foreach ($extra as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    fwrite(STDERR, "Either this is a new private similarity fork (rule #22 — delete it, call\n");
    fwrite(STDERR, "includes/song_similarity.php's ihymns_sim_text()/ihymns_sim_name_score()\n");
    fwrite(STDERR, "instead) or a genuinely new legitimate user that needs its own justified\n");
    fwrite(STDERR, "entry added to \$allowed in this test, mirroring the three existing ones.\n");
} else {
    echo "PASS: no file outside the allow-list calls levenshtein()/similar_text()\n";
}

if ($missing) {
    $failed++;
    fwrite(STDERR, "FAIL: " . count($missing) . " allow-listed file(s) no longer call levenshtein()/similar_text() — the allow-list is stale:\n");
    foreach ($missing as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    fwrite(STDERR, "Remove the stale entry (and its justification paragraph) from this file.\n");
} else {
    echo "PASS: every allow-listed file still legitimately calls levenshtein()/similar_text() (" . count($allowed) . " files)\n";
}

if ($failed > 0) {
    fwrite(STDERR, "\n$failed check(s) failed.\n");
    exit(1);
}
echo "\nmusician-dedup-shared-scorer: allow-list matches the derived tree exactly.\n";
exit(0);
