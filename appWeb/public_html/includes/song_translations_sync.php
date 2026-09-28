<?php

declare(strict_types=1);

/**
 * iHymns — how a song save turns the editor's translation links into writes
 * (#352 / #1626, reworked for the #2137 review)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The song editor sends back the song's whole list of translation links
 * ("this hymn is SDAH-123 in Spanish"). The save compares that list with what
 * is stored and makes only the changes needed. This file is the comparison:
 * it decides, without touching the database, which stored links to delete,
 * which to change in place, and which to add. `save_song_core.php` then does
 * exactly that.
 *
 * WHY THIS IS ITS OWN FILE (#2137 review)
 * ---------------------------------------
 * The comparison used to match a stored link to an incoming one by the
 * language exactly as stored. But the save tidies the incoming language by
 * the shared rule first, so a link stored under a retired code no longer
 * matched its own copy coming back from the editor:
 *   - `iw` (the retired code for Hebrew) came back as `he`, so an unrelated
 *     save deleted the `iw` row and inserted a new `he` row — losing the
 *     translator credit, the verified flag and the creation date;
 *   - `mo` (retired Moldavian) and `ro` both tidy to `ro`, so the two
 *     incoming links collapsed into one and the other stored row was deleted,
 *     with no warning.
 * Now a stored link is keyed by its TIDIED language too. When the tidied
 * language matches, the row is updated in place (so its translator, verified
 * flag and creation date survive). When two stored rows tidy to the same
 * language, the save cannot know which one the curator means, so it leaves
 * both exactly as they are and says so, pointing at the remap tool on
 * /manage/languages, which is built to merge them deliberately.
 *
 * Kept free of database calls so the rule is tested directly:
 * tests/php/test-song-translations-sync.php.
 *
 * @see appWeb/public_html/manage/editor/save_song_core.php  (the caller)
 * @see docs/standards/media-language-bcp47-policy.md          (LANG-001, COMPAT-040)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Work out the writes that bring a song's stored translation links in line
 * with the ones the editor sent.
 *
 * @param array<string, array{songId:string, language:string}> $desired
 *        The editor's links after checking, keyed by lower-cased TIDIED
 *        language (one link per language, the table's unique key).
 * @param list<array{id:int, songId:string, language:string}> $existing
 *        The song's stored links, exactly as stored.
 * @param array<string, true> $keep
 *        Lower-cased tidied languages whose stored link must survive even
 *        though the editor's copy of it was skipped for a reason that is not
 *        the curator's (the server has not run a migration yet, the value
 *        could not be read). Without this, a skipped link would look removed.
 * @param callable(string): (string|false|null) $tidy
 *        The shared storage rule, mediaLanguageTagForStorage().
 * @return array{
 *     delete: list<int>,
 *     update: list<array{id:int, songId:string, language:string}>,
 *     insert: list<array{songId:string, language:string}>,
 *     warnings: list<string>
 * }
 */
function songTranslationsPlanSync(array $desired, array $existing, array $keep, callable $tidy): array
{
    $plan = ['delete' => [], 'update' => [], 'insert' => [], 'warnings' => []];

    /* Group the stored rows by the language they tidy to. A value the rule
       cannot read keys under itself, so it can still be matched and kept. */
    $byKey = [];
    foreach ($existing as $row) {
        $t = $tidy((string)$row['language']);
        $key = mb_strtolower(is_string($t) ? $t : (string)$row['language']);
        $byKey[$key][] = $row;
    }

    foreach ($byKey as $key => $rows) {
        if (count($rows) > 1) {
            /* Two stored links, one language (`mo` and `ro`). Leave both. */
            $names = array_map(static fn(array $r): string => '"' . $r['language'] . '"', $rows);
            $plan['warnings'][] = 'The translation links stored as ' . implode(' and ', $names)
                . ' are the same language ("' . $key . '"), so this save left both exactly as they are.'
                . ' Merge them with the remap on /manage/languages.';
            continue;
        }
        $stored = $rows[0];
        if (!isset($desired[$key])) {
            if (!isset($keep[$key])) {
                $plan['delete'][] = (int)$stored['id'];
            }
            continue;
        }
        $want = $desired[$key];
        if (strcasecmp((string)$stored['songId'], $want['songId']) !== 0
            || (string)$stored['language'] !== $want['language']) {
            /* In place: the row's Translator, Verified and CreatedAt survive. */
            $plan['update'][] = ['id' => (int)$stored['id'], 'songId' => $want['songId'], 'language' => $want['language']];
        }
    }

    foreach ($desired as $key => $want) {
        if (!isset($byKey[$key])) {
            $plan['insert'][] = $want;
        }
    }
    return $plan;
}
