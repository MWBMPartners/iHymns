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
 * flag and creation date survive).
 *
 * The second review (#2137) settled the clashes:
 *   - Two STORED links that tidy to one language (`mo`, `ro`): if the editor
 *     sends back EXACTLY ONE of those spellings, that is the curator's choice —
 *     that row is kept as it is (translator, verified flag, date) and the others
 *     are deleted. If it sends back several, or none, all are kept and the
 *     warning says what works: remove all but one of these links in the editor
 *     and save. (It used to point at the remap on /manage/languages, which
 *     cannot merge two links on one song — the unique key refuses it.)
 *   - Two SENT links that tidy to one language: nothing changes for that
 *     language; what is stored stays; the curator is told to keep one.
 *   - A link skipped because its target song no longer exists no longer
 *     deletes the stored link for that language.
 *
 * A THIRD fault, found after the second review's fixes were checked against a
 * server that has not yet run the #2131 migration ("Translations: allow
 * regional and script languages"): on such a server, changing a link's
 * language from `pt` to `pt-BR` deleted the `pt` link — because the save
 * could not store `pt-BR` either, the link was simply gone, with only a
 * warning to show for it. The "keep this stored row, the editor's copy of it
 * was skipped for a reason that is not the curator's" list was keyed by the
 * language the editor SENT (`pt-BR`), which is not the language the row is
 * actually stored under (`pt`) whenever the curator is trying to CHANGE it —
 * so the key never matched and the row was deleted anyway. The lead's
 * decision (#2137): when the new language cannot be stored, the existing
 * link — language, target song, translator, verified flag, date — is left
 * exactly as it is, and the warning names the "Translations: allow regional
 * and script languages" card. Fixed by keying that keep-list on the TARGET
 * SONG a failed link named, not the language it failed to become — the
 * target song is the one thing that still ties a failed change back to
 * whichever stored row it was trying to replace, whatever language that row
 * happens to be filed under.
 *
 * A FOURTH fault (the third independent review, #2137): that fix swapped one
 * key for the other instead of using both, so it broke the cases the old key
 * had covered. A link stored as `English → T1` (a name, not a code) that the
 * curator re-pointed to T2 was deleted: the failed link named T2, and no
 * stored row points at T2. Before the #2131 card, `iw → T1` re-pointed to T2
 * was deleted the same way (`iw` tidies to `he`, which that server cannot
 * link). And when two stored links clash (`mo → T3`, `ro → T4`), the clash
 * branch below never looked at the keep list at all, so a failed
 * `ro-MD → T3` still let the `mo` row be deleted. The lead's decision
 * (#2137, final): stored rows are protected BY ROW ID, and a link that
 * cannot be written protects the UNION of
 *   - every stored row that points at the same song, and
 *   - the stored row filed under the failed link's own language,
 * never one instead of the other. The planner never deletes a protected row
 * in any branch, the clash branch included. It never CHANGES one either:
 * a change that would touch a protected row is itself treated as a link
 * that could not be written, so it protects in turn the stored rows that
 * share ITS song or language (songTranslationsSaveLinks() repeats until
 * nothing new is protected). "Could not be written" covers every reason: a
 * language that is not a code, a language the server cannot store or link
 * yet, a target song that no longer exists, a link to the song itself, two
 * links sent for one language, and a link with no language or no song.
 *
 * THE FOURTH independent review (#2137 review round 5) found three more ways
 * a link could still be lost, each reproduced on MariaDB 11.8 and MySQL 8.4:
 *   - whitespace: a stored "English " (or with a tab, a line break, a
 *     vertical tab or a NUL around it) was keyed untrimmed while the save
 *     trims what the editor sends, so the protection never matched — now one
 *     trim set, IHYMNS_TRANSLATION_LINK_TRIM, serves both;
 *   - a write failing part-way: a stored junk row the database counts as the
 *     same language made the last write fail after an earlier one had
 *     deleted a link, and the caller committed what had been written — now
 *     the song save calls songTranslationsSaveLinksAllOrNothing(), which
 *     undoes every link write with ROLLBACK TO SAVEPOINT and says the links
 *     were left unchanged;
 *   - a payload entry that is not a link (`"junk"`) was skipped, and the
 *     stored links were then deleted as if removed — now the whole
 *     translation save refuses, changing nothing (songTranslationsIsLinkShaped()).
 * And one successful change that still threw details away: `pt → T1` (a
 * translator, verified) changed to `pt-BR → T1` was deleted and re-inserted
 * bare. The planner now pairs a stored row whose language nobody sent back
 * with a sent link to the SAME song whose language is new, and updates it in
 * place, as `iw → he` already was (unambiguous pairs only — see the planner).
 *
 * THE FIFTH independent review (#2137 review round 6) found the opposite
 * fault: details kept where they do not belong. A stored row's Translator,
 * Verified flag and date belong to the song it links to, but a re-point
 * (`pt-BR → T2`, Zed, verified, sent back as `pt-BR → T1`) updated the row in
 * place and so credited Zed, verified, for T1. And round 5's same-song pairing
 * kept the Verified flag for any language change (`fr → de`), not only a more
 * precise one. The lead's decisions, now the planner's rules: a re-point to a
 * song that has its own stored row nobody sent back relabels THAT row (its own
 * details), and the old song's row goes; otherwise the re-pointed row keeps no
 * Translator and is not verified. A language change of the same song keeps the
 * Translator always and the Verified flag only when the primary language is
 * the same under the shared rule (songTranslationsDetailsAfterChange()).
 *
 * WHAT THIS CANNOT DO. A failed link is tied back to a stored row only by
 * its song or its language. If a curator changes BOTH at once (`pt → T1`
 * becomes `pt-BR → T2`) and that change cannot be written, nothing ties it
 * to the `pt` row, and the save treats `pt → T1` as removed — exactly as if
 * the curator had deleted one link and added another, which the save cannot
 * tell apart. The warning still names the link that was skipped.
 *
 * songTranslationsPlanSync() decides and touches no database, so the rules are
 * tested directly; songTranslationsSaveLinks() — the song save's steps 1–4,
 * moved here from save_song_core.php — reads, decides and writes, and is
 * tested against a real database. Both: tests/php/test-song-translations-sync.php.
 *
 * @see appWeb/public_html/manage/editor/save_song_core.php  (the caller)
 * @see docs/standards/media-language-bcp47-policy.md          (LANG-001, COMPAT-040)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_language.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_translations_schema.php';   /* songTranslationsLanguageFkPresent() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_relocate.php';              /* songRelocateIsTransactionFatal() — the ONE list of errors that have already rolled the whole save back */

/**
 * The characters the save trims from both ends of what the editor sends — a
 * link's song id and its language — before it reads them: PHP's own default
 * set for trim() (space, tab, line feed, carriage return, NUL, vertical tab).
 *
 * #2137 review round 5 — named, because a STORED language must be trimmed by
 * exactly the same set before it is compared with a sent one (see
 * songTranslationsGroupKey()). This is deliberately wider than the shared
 * language rule's own trim (space, tab, CR, LF): here the question is not
 * "what tag is this?" but "is this stored row the one the editor sent back?",
 * and the editor's copy has already lost everything in this set.
 */
const IHYMNS_TRANSLATION_LINK_TRIM = " \t\n\r\0\x0B";

/**
 * The key a translation link is grouped under: its language tidied by the
 * shared rule, lower-cased. A value the rule cannot read (a name such as
 * "English", or an empty value) keys under itself, lower-cased, so a stored
 * row holding it can still be matched — and kept.
 *
 * ONE function, used for the stored rows AND for the links that could not be
 * written (#2137 review round 4), so the two can never be keyed by different
 * rules. That mismatch is exactly how the round-3 fault happened.
 *
 * #2137 review round 5 — the value is first trimmed exactly as the save trims
 * what the editor sends (IHYMNS_TRANSLATION_LINK_TRIM). A stored "English "
 * (or with a tab, a line break, a vertical tab or a NUL around it) comes back
 * from the editor as "English", because the save trims it; keyed untrimmed,
 * the stored row and the failed link it belonged with got different keys, the
 * protection never matched, and a re-point that could not be written deleted
 * the stored row (translator, verified flag and all). Found by the fourth
 * independent review; reproduced on MariaDB 11 and MySQL 8.4.
 *
 * @param callable(string): (string|false|null) $tidy mediaLanguageTagForStorage()
 */
function songTranslationsGroupKey(string $language, callable $tidy): string
{
    $language = trim($language, IHYMNS_TRANSLATION_LINK_TRIM);
    $t = $language === '' ? null : $tidy($language);
    return mb_strtolower(is_string($t) ? $t : $language);
}

/**
 * Which stored rows must be left exactly as they are, because a link this
 * save could not write may belong with them (#2137 review round 4).
 *
 * A failed link protects the UNION of: every stored row pointing at the
 * same song, and every stored row filed under the failed link's own
 * language key. Both halves are needed, never one instead of the other:
 *   - the song half catches a curator CHANGING a link's language (`pt → T1`
 *     sent back as `pt-BR → T1` on a server that cannot store `pt-BR`);
 *   - the language half catches a curator RE-POINTING a link
 *     (`English → T1` sent back as `English → T2`, or `iw → T1` as
 *     `iw → T2` on a server that cannot link `he` yet), where no stored row
 *     points at the new song.
 *
 * @param list<array{key:string, target:string}> $failed
 *        Links that could not be written: 'key' as songTranslationsGroupKey()
 *        gives it, 'target' the lower-cased song id ('' when none was sent).
 * @param list<array{id:int, songId:string, language:string}> $existing
 * @param callable(string): (string|false|null) $tidy
 * @return array<int, true> Protected row ids.
 */
function songTranslationsProtectedIds(array $failed, array $existing, callable $tidy): array
{
    $protected = [];
    foreach ($existing as $row) {
        $rowKey = songTranslationsGroupKey((string)$row['language'], $tidy);
        $rowTarget = mb_strtolower((string)$row['songId']);
        foreach ($failed as $f) {
            if ($f['key'] === $rowKey || ($f['target'] !== '' && $f['target'] === $rowTarget)) {
                $protected[(int)$row['id']] = true;
                break;
            }
        }
    }
    return $protected;
}

/**
 * Work out the writes that bring a song's stored translation links in line
 * with the ones the editor sent.
 *
 * @param array<string, array{songId:string, language:string, raw?:string}> $desired
 *        The editor's links after checking, keyed by lower-cased TIDIED
 *        language (one link per language, the table's unique key). 'raw' is
 *        the lower-cased spelling the editor sent; it picks the kept row when
 *        two stored links share the language.
 * @param list<array{id:int, songId:string, language:string}> $existing
 *        The song's stored links, exactly as stored.
 * @param array<int, true> $protected
 *        Ids of stored rows that must survive this save UNCHANGED, because a
 *        link the editor sent could not be written and may belong with them
 *        (songTranslationsProtectedIds()). Such a row is never deleted — in
 *        any branch, the clash branch included — and never changed. Until
 *        round 4 of the #2137 review this was a list of LANGUAGES, which
 *        the clash branch never consulted, and which could not name the row
 *        a failed re-point belonged to.
 * @param callable(string): (string|false|null) $tidy
 *        The shared storage rule, mediaLanguageTagForStorage().
 * @return array{
 *     delete: list<int>,
 *     update: list<array{id:int, songId:string, language:string, details:string}>,
 *     insert: list<array{songId:string, language:string}>,
 *     warnings: list<string>,
 *     blocked: list<string>
 * }
 *        'blocked' lists the $desired keys whose change would have touched a
 *        protected row, so it was NOT made. The caller treats each as a link
 *        that could not be written (it protects the rows sharing its song or
 *        language in turn) and plans again.
 *        Each update's 'details' says what happens to the row's Translator,
 *        Verified flag and date (CreatedAt) — #2137 review round 6, see
 *        songTranslationsDetailsAfterChange(): 'keep' (all three stay),
 *        'unverify' (the Translator stays; Verified is cleared and the date
 *        becomes the time of this save) or 'clear' (no Translator, not
 *        verified, the date becomes the time of this save).
 */
function songTranslationsPlanSync(array $desired, array $existing, array $protected, callable $tidy): array
{
    $plan = ['delete' => [], 'update' => [], 'insert' => [], 'warnings' => [], 'blocked' => []];
    $isProtected = static fn(array $r): bool => isset($protected[(int)$r['id']]);
    $unmatchedStored = [];   /* single stored rows whose language nobody sent back (round 5) */
    /* Round 6 — links whose language matched a stored row but that now name a
       DIFFERENT song (a re-point). Decided below, once every stored row the
       editor did not send back is known: [row => the stored row, songId => the
       song it now names, language => the language it keeps]. */
    $repoints = [];

    /* Group the stored rows by the language they tidy to (the same key a
       failed link is matched by — songTranslationsGroupKey()). */
    $byKey = [];
    foreach ($existing as $row) {
        $byKey[songTranslationsGroupKey((string)$row['language'], $tidy)][] = $row;
    }

    foreach ($byKey as $key => $rows) {
        if (count($rows) > 1) {
            /* Two stored links, one language (`mo` and `ro`). The editor sent
               back exactly one of those spellings → the curator's choice: keep
               that row as it is, delete the others. Otherwise keep all. */
            $want = $desired[$key] ?? null;
            $chosen = null;
            if ($want !== null && isset($want['raw'])) {
                /* 'raw' was trimmed by the save; the stored spelling is
                   trimmed the same way before comparing (round 5), or a
                   stored "mo " could never be recognised as the one the
                   curator kept, and the warning's advice ("remove all but
                   one … and save") would never work for it. */
                $hits = array_values(array_filter(
                    $rows,
                    static fn(array $r): bool => mb_strtolower(trim((string)$r['language'], IHYMNS_TRANSLATION_LINK_TRIM)) === $want['raw']
                ));
                $chosen = count($hits) === 1 ? $hits[0] : null;
            }
            if ($chosen === null) {
                $names = array_map(static fn(array $r): string => '"' . $r['language'] . '"', $rows);
                $plan['warnings'][] = 'The translation links stored as ' . implode(' and ', $names)
                    . ' are the same language ("' . $key . '"), so this save left them all as they are.'
                    . ' To fix it, remove all but one of these links in the editor and save.';
                continue;
            }
            /* The kept row keeps its language as stored; only a re-point the
               curator made in the same save is applied. The next save tidies
               its language, once no clash is left. A re-point of a PROTECTED
               row is not made (round 4): it goes back to the caller as
               blocked, and nothing in this group is deleted on the strength
               of a choice that is not going to happen. */
            if (strcasecmp((string)$chosen['songId'], $want['songId']) !== 0 && $isProtected($chosen)) {
                $plan['blocked'][] = (string)$key;
                continue;
            }
            foreach ($rows as $r) {
                /* #2137 review round 4 — a protected row is never deleted,
                   even when the curator chose another spelling: a link this
                   save could not write (`ro-MD → T3`, stored as `mo → T3`)
                   belongs with it. */
                if ((int)$r['id'] !== (int)$chosen['id'] && !$isProtected($r)) {
                    $plan['delete'][] = (int)$r['id'];
                }
            }
            if (strcasecmp((string)$chosen['songId'], $want['songId']) !== 0) {
                /* A re-point (round 6): decided below, like any other. */
                $repoints[] = ['row' => $chosen, 'songId' => $want['songId'], 'language' => (string)$chosen['language']];
            }
            continue;
        }
        $stored = $rows[0];
        if (!isset($desired[$key])) {
            /* Nobody sent this language back. It is deleted below — unless
               it is protected, or it pairs with a sent link to the SAME
               song whose language is new (round 5, L3), or a re-point to its
               song takes it over (round 6). */
            $unmatchedStored[] = $stored;
            continue;
        }
        $want = $desired[$key];
        if (strcasecmp((string)$stored['songId'], $want['songId']) !== 0
            || (string)$stored['language'] !== $want['language']) {
            if ($isProtected($stored)) {
                /* Round 4: a protected row is left exactly as it is. */
                $plan['blocked'][] = (string)$key;
                continue;
            }
            if (strcasecmp((string)$stored['songId'], $want['songId']) !== 0) {
                /* A re-point to a different song (round 6): decided below. */
                $repoints[] = ['row' => $stored, 'songId' => $want['songId'], 'language' => $want['language']];
                continue;
            }
            /* The same song, its language tidied (`iw` → `he`, `pt-br` →
               `pt-BR`): the same link, so in place, and its Translator,
               Verified flag and date all survive — the language key is the
               same, so the primary language is too. */
            $plan['update'][] = ['id' => (int)$stored['id'], 'songId' => $want['songId'], 'language' => $want['language'], 'details' => 'keep'];
        }
    }

    /* #2137 review round 5 (L3), corrected in round 6 — a language change of
       the SAME song keeps the link's row. A stored row whose language nobody
       sent back, and a sent link whose language is not stored, that point at
       the same song are one link whose language changed (`pt → T1` sent back
       as `pt-BR → T1`): the row is UPDATED in place rather than deleted and a
       bare row inserted. What survives (round 6, the lead's decision on the
       fifth review's finding 3 — songTranslationsDetailsAfterChange()): the
       Translator ALWAYS (it is the same song, so the same translation); the
       Verified flag and its date only when the primary language is the same
       under the shared rule (`pt` → `pt-BR`, `iw` → `he`). A change to a
       different language (`fr` → `de` on T1) keeps the Translator but is no
       longer verified — what was checked was a French link. Round 5 kept
       everything for any language change; its notes said "more precise",
       which `fr` → `de` is not.

       #2137 review round 6 (the fifth review's finding 1) — a link's details
       never move to a different song. A stored row's Translator, Verified
       flag and date belong to the song it links to. When a sent link re-
       points a stored language to a DIFFERENT song: if that song has its own
       stored row that no sent link matches, THAT row is relabelled to the
       sent language and keeps its OWN details (its Translator always; its
       Verified flag and date by the same-primary-language rule above, since
       a relabel is a language change of that song), and the displaced row
       for the old song goes. Otherwise the re-pointed row is updated in
       place with no Translator, not verified, and dated now. Until round 6
       the re-pointed row kept its old song's details, so the fifth review's
       stored `pt → T1` (Ana, verified) and `pt-BR → T2` (Zed, verified),
       sent back as `pt-BR → T1`, became `pt-BR → T1` credited to Zed and
       verified — Zed never translated T1, and nobody had checked that link.

       Pairing (both kinds) is made only when it is unambiguous: for a song,
       exactly ONE stored row nobody sent back, which is not protected (a
       protected row is never changed, so never relabelled), and exactly ONE
       claim on it — one new sent link to that song, or one re-point to it,
       not both, not two. Anything else is treated as "removed one link,
       added another": the new link is inserted bare, a re-point keeps no
       details, and the stored row is deleted (unless protected). */
    $unmatchedDesired = [];
    foreach ($desired as $key => $want) {
        if (!isset($byKey[$key])) {
            $unmatchedDesired[$key] = $want;
        }
    }
    $storedBySong = [];
    foreach ($unmatchedStored as $r) {
        $storedBySong[mb_strtolower((string)$r['songId'])][] = $r;
    }
    $desiredBySong = [];
    foreach ($unmatchedDesired as $key => $want) {
        $desiredBySong[mb_strtolower((string)$want['songId'])][] = $key;
    }
    $repointsBySong = [];
    foreach ($repoints as $i => $rp) {
        $repointsBySong[mb_strtolower((string)$rp['songId'])][] = $i;
    }
    $paired = [];
    $repointDone = [];
    foreach ($storedBySong as $song => $candidates) {
        $keys = $desiredBySong[$song] ?? [];
        $claims = count($keys) + count($repointsBySong[$song] ?? []);
        if ($claims !== 1 || count($candidates) !== 1 || $isProtected($candidates[0])) {
            continue;
        }
        $mine = $candidates[0];
        if (count($keys) === 1) {
            /* A new language for this song (L3): the row is relabelled. */
            $want = $unmatchedDesired[$keys[0]];
            $plan['update'][] = ['id' => (int)$mine['id'], 'songId' => $want['songId'], 'language' => $want['language'],
                'details' => songTranslationsDetailsAfterChange((string)$mine['language'], $want['language'])];
            unset($unmatchedDesired[$keys[0]]);
        } else {
            /* A re-point to this song: this song's own row is relabelled to
               the re-pointed language, keeping its own details, and the
               re-pointed row (the old song's) is deleted. The delete is
               written before the update (step 4 applies deletes first), so
               the language is free when the relabel takes it. */
            $i = $repointsBySong[$song][0];
            $rp = $repoints[$i];
            $plan['delete'][] = (int)$rp['row']['id'];
            $plan['update'][] = ['id' => (int)$mine['id'], 'songId' => $rp['songId'], 'language' => $rp['language'],
                'details' => songTranslationsDetailsAfterChange((string)$mine['language'], $rp['language'])];
            $repointDone[$i] = true;
        }
        $paired[(int)$mine['id']] = true;
    }
    foreach ($repoints as $i => $rp) {
        if (!isset($repointDone[$i])) {
            /* No row of its own on the new song: the row is re-pointed in
               place, and the old song's details do not come with it. */
            $plan['update'][] = ['id' => (int)$rp['row']['id'], 'songId' => $rp['songId'], 'language' => $rp['language'], 'details' => 'clear'];
        }
    }
    foreach ($unmatchedStored as $r) {
        if (!isset($paired[(int)$r['id']]) && !$isProtected($r)) {
            $plan['delete'][] = (int)$r['id'];
        }
    }
    foreach ($unmatchedDesired as $want) {
        $plan['insert'][] = $want;
    }
    return $plan;
}

/**
 * What happens to a stored row's details when its LANGUAGE changes but it
 * keeps (or takes over) its song (#2137 review round 6 — the lead's decision
 * on the fifth independent review's finding 3).
 *
 *   - 'keep'     — the primary language is the same under the shared rule
 *                  (`pt` → `pt-BR`, `iw` → `he`, `zh` → `zh-Hant`): the
 *                  Translator, the Verified flag and the date all stay. The
 *                  link is the same; only its label became more precise.
 *   - 'unverify' — a different language (`fr` → `de`), or a side the shared
 *                  rule cannot read (a name such as "English", a value with a
 *                  no-break space): the Translator stays (it is the same song,
 *                  so the same translation), but the link is no longer
 *                  verified — what someone checked was a link in the OLD
 *                  language — and its date becomes the time of this save.
 *
 * THE DATE. tblSongTranslations has no separate date for when a link was
 * verified; its only date is CreatedAt, the time the link row was written,
 * which the round-5 notes called "the date". It goes with the Verified flag:
 * kept when the flag is kept, set to the time of this save when the flag is
 * cleared. ('clear', for a link re-pointed to a song with no row of its own,
 * is decided by the planner, not here: no Translator, not verified, dated now.)
 *
 * "Primary language" is mediaLanguageGroup() — the shared rule's own answer,
 * the same one the language filter uses — after trimming exactly as the save
 * trims (IHYMNS_TRANSLATION_LINK_TRIM). An empty answer (an unreadable value)
 * never counts as "the same".
 */
function songTranslationsDetailsAfterChange(string $fromLanguage, string $toLanguage): string
{
    $from = mediaLanguageGroup(trim($fromLanguage, IHYMNS_TRANSLATION_LINK_TRIM));
    $to   = mediaLanguageGroup(trim($toLanguage, IHYMNS_TRANSLATION_LINK_TRIM));
    return ($from !== '' && $from === $to) ? 'keep' : 'unverify';
}

/**
 * Save a song's whole-song translation links: the song save's steps 1–4
 * (moved here from save_song_core.php so they can be tested against a real
 * database, #2137 review). Reads what is stored for $songId, decides with
 * songTranslationsPlanSync(), and writes only the difference. Runs inside the
 * caller's transaction and throws what mysqli throws. The song save does NOT
 * call this directly: it calls songTranslationsSaveLinksAllOrNothing() (below),
 * which undoes every write this made if any of them fails (#2137 review
 * round 5).
 *
 * #2137 review round 5 (I6) — a payload entry that is not a link (not an
 * object, or an object whose song or language is not text) makes the WHOLE
 * translation save refuse, before anything is read or written, with a plain
 * warning. It used to be skipped, and the stored links it might have named
 * were then deleted as if the curator had removed them (the fourth review
 * reproduced `de → T2` deleted by a payload of `["junk"]`). The save cannot
 * tell what such an entry meant, so it changes nothing.
 *
 * @param \mysqli $db
 * @param string  $songId The source song (SourceSongId).
 * @param array   $sent   The editor's [{songId, language}, …] — the same shape
 *                        SongData::_getTranslations() emits.
 * @return list<string>   Warnings for the curator (plain sentences).
 */
function songTranslationsSaveLinks(\mysqli $db, string $songId, array $sent): array
{
    $position = 0;
    foreach ($sent as $tr) {
        $position++;
        if (!songTranslationsIsLinkShaped($tr)) {
            return ['The translation links were left unchanged because entry ' . $position
                . ' of the list the editor sent is not a translation link, so the save could not tell'
                . ' which links you meant to keep. Reload the editor and try again.'];
        }
    }

    $warnings = [];
    /* ---- 1. Normalise the desired set, keyed by language ----
       The client sends [{songId, language}, …] — the same shape
       SongData::_getTranslations() emits, so the round trip is
       symmetrical. Keyed case-insensitively (on the TIDIED
       language) because the column collation (utf8mb4_unicode_ci)
       makes the UNIQUE key case-insensitive too.
       #2137 review — two SENT links that tidy to one language (`ro`
       and `mo`, or `pt-br` and `pt-BR` to different songs) are no
       longer merged "last one wins": the save cannot know which the
       curator meant, so it changes nothing for that language (what
       is stored stays) and says so. An exact repeat (same spelling,
       same song) is simply one link. */
    $desired = [];
    $sentByKey = [];
    /* Every link the editor sent that this save could NOT write, for
       any reason: [key => the language key it would be filed under
       (songTranslationsGroupKey()), target => its song, lower-cased].
       Once the stored rows are read (step 3), each one protects, BY
       ROW ID, every stored row that points at the same song AND the
       stored row filed under the same language key — the union
       (songTranslationsProtectedIds()). #2137 review history, because
       each round fixed one half and broke the other:
         - round 2 keyed "keep" by the language that failed, so a
           curator changing `pt → T1` to `pt-BR → T1` on a server that
           cannot store `pt-BR` lost the `pt` row (a different key);
         - round 3 keyed it by the song instead, so `English → T1`
           re-pointed to `English → T2` lost the row (no stored row
           points at T2), and so did `iw → T1` re-pointed to T2 before
           the #2131 card;
         - round 4 (this) protects both, and the planner also honours
           it in the branch for two stored links that clash, which it
           never consulted before. */
    $failed = [];
    $recordFailed = static function (string $language, string $target) use (&$failed): void {
        $failed[] = [
            'key'    => songTranslationsGroupKey($language, 'mediaLanguageTagForStorage'),
            'target' => mb_strtolower($target),
        ];
    };
    foreach ($sent as $tr) {
        if (!is_array($tr)) { continue; }   /* cannot happen: every entry passed songTranslationsIsLinkShaped() above; kept so a later edit cannot turn a stray value into a PHP error */
        /* Trimmed by IHYMNS_TRANSLATION_LINK_TRIM — the same set a stored
           language is trimmed by before it is compared (round 5). */
        $tId   = trim((string)($tr['songId']   ?? ''), IHYMNS_TRANSLATION_LINK_TRIM);
        $tLang = trim((string)($tr['language'] ?? ''), IHYMNS_TRANSLATION_LINK_TRIM);
        if ($tId === '' && $tLang === '') { continue; }   /* an empty row: it names nothing, so it can protect nothing */
        /* Half a link (#2137 review round 4): it used to be dropped in
           silence, and a stored link to the same song — or filed under
           the same language — was then deleted as if the curator had
           removed it. Skipped with a warning; what it names is left as
           it is. (The editor never builds one; a stored link whose
           language is empty comes back from it this way.) */
        if ($tId === '' || $tLang === '') {
            $warnings[] = $tId === ''
                ? 'A translation link in "' . $tLang . '" names no song — skipped.'
                : 'The translation link to ' . $tId . ' has no language — skipped; any stored link to it is left as it is.';
            $recordFailed($tLang, $tId);
            continue;
        }
        /* A song cannot be a translation of itself — the same
           rule the legacy add_translation endpoint enforced. Round 4:
           the stored link filed under this language is left as it is
           (it used to be deleted, as if removed). */
        if (strcasecmp($tId, $songId) === 0) {
            $warnings[] = 'A song cannot be a translation of itself — skipped.';
            $recordFailed($tLang, $tId);
            continue;
        }
        /* #2131 / #2137 — the language is tidied by the ONE
           shared rule (`pt-br` → `pt-BR`), so two spellings
           of one language can never make two links; a value
           that is not a language code is skipped with a
           warning rather than sent to the database. */
        $tidyLang = mediaLanguageTagForStorage($tLang);
        if (!is_string($tidyLang)) {
            $warnings[] = 'Language "' . $tLang
                . '" is not a language code this site can store — translation link to '
                . $tId . ' skipped.';
            $recordFailed($tLang, $tId);   /* keyed under the unreadable value itself, like a stored row holding it */
            continue;
        }
        $key = mb_strtolower($tidyLang);
        foreach ($sentByKey[$key] ?? [] as $already) {
            if ($already['raw'] === mb_strtolower($tLang) && strcasecmp($already['songId'], $tId) === 0) {
                continue 2;   /* the same link twice */
            }
        }
        /* 'raw' = the spelling the editor sent: when two STORED links
           share this language, it says which one the curator kept. */
        $sentByKey[$key][] = ['songId' => $tId, 'language' => $tidyLang, 'raw' => mb_strtolower($tLang)];
    }
    foreach ($sentByKey as $key => $links) {
        if (count($links) > 1) {
            $spellings = array_map(static fn(array $l): string => '"' . $l['raw'] . '" → ' . $l['songId'], $links);
            $warnings[] = 'Two links for the same language (' . implode(' and ', $spellings) . ' are both "'
                . $links[0]['language'] . '"); keep one. Nothing was changed for that language.';
            /* What is stored stays — for this language, and (round 4)
               for the songs these links name. */
            foreach ($links as $l) { $recordFailed($l['language'], $l['songId']); }
            continue;
        }
        $desired[$key] = $links[0];
    }

    /* ---- 2. Pre-validate against the FK parents ----
       tblSongTranslations has FKs to tblSongs (both id columns).
       #2131 — on a server where the migration
       migrate-drop-song-translations-language-fk.php has NOT
       been run yet, it also still has fk_Trans_Lang to
       tblLanguages.Code, which holds bare codes only, so a
       `pt-BR` link WOULD violate it. On such a server the
       language is still checked against tblLanguages first
       (songTranslationsLanguageFkPresent()), so a doomed
       statement is never issued and a curator's lyrics edit is
       never lost to it; once the migration has run, the check
       above (the shared rule) is the only one. */
    if ($desired !== []) {
        $wantLangs = array_values(array_unique(array_map(
            static fn(array $d): string => $d['language'], $desired
        )));
        $wantIds = array_values(array_unique(array_map(
            static fn(array $d): string => $d['songId'], $desired
        )));

        /* Placeholder strings are built from a COUNT, never from
           user data — the one interpolation CLAUDE.md rule #5
           permits. Every VALUE below is bound. */
        $langFkPresent = songTranslationsLanguageFkPresent($db);
        $langOk = [];
        if ($langFkPresent) {
            $lp   = implode(',', array_fill(0, count($wantLangs), '?'));
            $lStm = $db->prepare("SELECT Code FROM tblLanguages WHERE Code IN ($lp)");
            $lStm->bind_param(str_repeat('s', count($wantLangs)), ...$wantLangs);
            $lStm->execute();
            $lRes = $lStm->get_result();
            /* Key on the lowercased tag but keep the table's
               casing as the value, so we store `en`, not `EN`. */
            while ($lRow = $lRes->fetch_assoc()) { $langOk[mb_strtolower($lRow['Code'])] = $lRow['Code']; }
            $lStm->close();
        }

        $idOk = [];
        /* @deleted-visible: write-path FK pre-check (#1694)
           — a translation link naming a hidden song must
           SURVIVE the save (dropping it would silently
           destroy data that comes back on restore).
           @disabled-visible: same reasoning, one predicate over
           (#1765) — a link to a song in a disabled songbook must
           survive too; disabling a songbook is reversible. (This
           marker sat elsewhere in save_song_core.php's function
           before these steps moved here, #2137 review.) */
        $ip   = implode(',', array_fill(0, count($wantIds), '?'));
        $iStm = $db->prepare("SELECT SongId FROM tblSongs WHERE SongId IN ($ip)");
        $iStm->bind_param(str_repeat('s', count($wantIds)), ...$wantIds);
        $iStm->execute();
        $iRes = $iStm->get_result();
        while ($iRow = $iRes->fetch_assoc()) { $idOk[mb_strtolower($iRow['SongId'])] = $iRow['SongId']; }
        $iStm->close();

        foreach ($desired as $key => $d) {
            if ($langFkPresent && !isset($langOk[mb_strtolower($d['language'])])) {
                $warnings[] = 'Language "' . $d['language']
                    . '" cannot be linked on this server yet: run the "Translations: allow'
                    . ' regional and script languages" card on /manage/setup-database'
                    . ' — translation link to ' . $d['songId'] . ' skipped.';
                unset($desired[$key]);
                /* Skipped for the SERVER'S reason (the #2131 migration has
                   not run), not removed by the curator. Round 4: protects
                   BOTH the stored row pointing at this song (a language
                   change, `pt → T1` sent as `pt-BR → T1`) AND the stored
                   row filed under this language (a re-point, `iw → T1`
                   sent as `iw → T2` where `he` cannot be linked yet). */
                $recordFailed($d['language'], $d['songId']);
                continue;
            }
            if (!isset($idOk[mb_strtolower($d['songId'])])) {
                $warnings[] = 'Song "' . $d['songId']
                    . '" no longer exists — translation link skipped.';
                unset($desired[$key]);
                /* #2137 review — skipped, not removed by the curator: the
                   link stored for this language stays as it is (it used to
                   be deleted here, translator and all). This protects it
                   only when the LANGUAGE is unchanged: a song that does not
                   exist cannot be pointed at by any stored row, so the song
                   half of the protection never matches here. */
                $recordFailed($d['language'], $d['songId']);
                continue;
            }
            /* Adopt the canonical stored spellings. */
            if ($langFkPresent) {
                $desired[$key]['language'] = $langOk[mb_strtolower($d['language'])];
            }
            $desired[$key]['songId']   = $idOk[mb_strtolower($d['songId'])];
        }
    }

    /* ---- 3. Read what is already stored (idx_Source) ---- */
    $exStm = $db->prepare(
        'SELECT Id, TranslatedSongId, TargetLanguage
           FROM tblSongTranslations WHERE SourceSongId = ?'
    );
    $exStm->bind_param('s', $songId);
    $exStm->execute();
    $exRes = $exStm->get_result();
    $existing = [];
    while ($exRow = $exRes->fetch_assoc()) {
        $existing[] = [
            'id'       => (int)$exRow['Id'],
            'songId'   => (string)$exRow['TranslatedSongId'],
            'language' => (string)$exRow['TargetLanguage'],
        ];
    }
    $exStm->close();

    /* ---- 3b. Decide, before any write, which stored rows are protected ----
       Only now do we know what is actually stored, so only now can a
       failed link be matched back to the stored rows it may belong
       with — by row id, the union of "points at the same song" and
       "filed under the same language key" (songTranslationsProtectedIds();
       the key is songTranslationsGroupKey(), the very rule the planner
       groups stored rows by, so the two cannot drift apart).
       A change the planner cannot make because it would touch a
       protected row comes back as 'blocked': it is then a link this
       save could not write, too, so it protects the rows sharing ITS
       song or language, and the plan is made again. Each pass removes
       at least one link from $desired, so this ends. Nothing is written
       until the last pass. */
    while (true) {
        $protected = songTranslationsProtectedIds($failed, $existing, 'mediaLanguageTagForStorage');
        $plan = songTranslationsPlanSync($desired, $existing, $protected, 'mediaLanguageTagForStorage');
        if ($plan['blocked'] === []) {
            break;
        }
        foreach ($plan['blocked'] as $bKey) {
            $b = $desired[$bKey];
            $warnings[] = 'The translation link to ' . $b['songId'] . ' in "' . $b['language']
                . '" was not saved: it would have changed a stored link that another link in this save,'
                . ' which could not be saved, may belong with. Fix that link (see the other warnings) and save again.';
            $recordFailed($b['language'], $b['songId']);
            unset($desired[$bKey]);
        }
    }

    /* ---- 4. Apply the diff ----
       A song with no translation links (the overwhelmingly common
       case) reaches here with both sides empty and performs ZERO
       writes — the save path stays behaviourally identical to
       before #1626 for every song that has never used the panel.
       songTranslationsPlanSync() decides; this only writes.
       UPDATEs are in place (the row keeps its Id). Its Translator /
       Verified / CreatedAt survive a tidied language; for a language
       change or a re-point to another song the planner says what
       survives (round 6 — a link's details never move to a different
       song). Deletes go first, so a language a relabel takes is free. */
    foreach ($plan['warnings'] as $w) { $warnings[] = $w; }
    foreach ($plan['delete'] as $delId) {
        $dStm = $db->prepare('DELETE FROM tblSongTranslations WHERE Id = ?');
        $dStm->bind_param('i', $delId);
        $dStm->execute();
        $dStm->close();
    }
    foreach ($plan['update'] as $u) {
        /* #2137 review round 6 — what the row's Translator, Verified flag and
           date (CreatedAt) become is the planner's decision, carried on each
           update ('details'; see songTranslationsPlanSync() and
           songTranslationsDetailsAfterChange()). A value the planner never
           gives is an error (match has no default), never a silent "keep". */
        $uStm = $db->prepare(match ($u['details']) {
            'keep'     => 'UPDATE tblSongTranslations SET TranslatedSongId = ?, TargetLanguage = ? WHERE Id = ?',
            'unverify' => 'UPDATE tblSongTranslations SET TranslatedSongId = ?, TargetLanguage = ?, Verified = 0, CreatedAt = CURRENT_TIMESTAMP WHERE Id = ?',
            'clear'    => "UPDATE tblSongTranslations SET TranslatedSongId = ?, TargetLanguage = ?, Translator = '', Verified = 0, CreatedAt = CURRENT_TIMESTAMP WHERE Id = ?",
        });
        $uStm->bind_param('ssi', $u['songId'], $u['language'], $u['id']);
        $uStm->execute();
        $uStm->close();
    }
    foreach ($plan['insert'] as $d) {
        /* New link. Translator defaults to '' and Verified to 0 —
           neither is modelled by the editor payload, and both are
           curator-maintained elsewhere. */
        $nStm = $db->prepare(
            'INSERT INTO tblSongTranslations (SourceSongId, TranslatedSongId, TargetLanguage)
             VALUES (?, ?, ?)'
        );
        $nStm->bind_param('sss', $songId, $d['songId'], $d['language']);
        $nStm->execute();
        $nStm->close();
    }
    return $warnings;
}

/**
 * Is this payload entry shaped like a translation link? (#2137 review
 * round 5, I6)
 *
 * A link is an OBJECT — in PHP, after json_decode(…, true), an array that is
 * not a list — whose `songId` and `language`, where present, are text (a
 * number is accepted as text; a missing value or JSON null counts as empty).
 * An empty object names nothing, which the save already skips. Anything else
 * — a string, a number, a list such as ["T1", "pt"], or a link whose song or
 * language is itself a list or an object — is not a link.
 *
 * WHAT IT DOES NOT CHECK: whether the song exists or the language is a
 * language. Those are the save's own per-link checks, which skip one link with
 * a warning and protect what it may belong with. This check is only about
 * entries the save cannot even read as a link, where it cannot know which
 * stored link is meant — so the whole translation save is refused instead.
 */
function songTranslationsIsLinkShaped(mixed $entry): bool
{
    if (!is_array($entry)) {
        return false;
    }
    if ($entry !== [] && array_is_list($entry)) {
        return false;
    }
    foreach (['songId', 'language'] as $field) {
        $v = $entry[$field] ?? null;
        if ($v !== null && !is_string($v) && !is_int($v) && !is_float($v)) {
            return false;
        }
    }
    return true;
}

/** The savepoint that makes the translation links' writes one unit (#2137 review round 5). */
const IHYMNS_TRANSLATION_LINKS_SAVEPOINT = 'ihymns_translation_links';

/**
 * The song save's translation links, ALL OR NOTHING (#2137 review round 5,
 * finding L2). This is what save_song_core.php calls.
 *
 * ELI5: the links are saved as one piece. If any write fails part-way, every
 * link write this save already made is undone, the links are left exactly as
 * they were, and the curator is told so — while the rest of the song (lyrics,
 * credits…) is still saved.
 *
 * WHY: songTranslationsSaveLinks() deletes, then updates, then inserts. The
 * fourth independent review found a stored junk row ("pt-BR" followed by a
 * no-break space) that the database counts as the same language as "pt-BR":
 * when a curator changed `pt → T1` to `pt-BR → T1`, the save deleted the `pt`
 * row, the write of `pt-BR` then failed on the unique key, the caller caught
 * the error and COMMITTED — and the `pt` link, translator and all, was gone.
 * Reproduced on MariaDB 11.8 and MySQL 8.4.
 *
 * HOW: a SAVEPOINT is set before the first read and released only once every
 * write has succeeded. On any error that has not already rolled back the whole
 * transaction, the unit is undone with the SQL statement
 * `ROLLBACK TO SAVEPOINT` — NOT `$db->rollback(0, name)`, whose second
 * argument names a TRANSACTION and would throw away the whole song save (the
 * same trap is recorded in lyric_lines_sync.php, #2073 finding F6). The lead's
 * decision put the rollback "in the caller"; it lives in this small function,
 * which the caller calls, so that the exact code the song save runs can be
 * tested against a real database (tests/php/test-song-translations-sync.php).
 *
 * WHAT IT LETS THROUGH, on purpose — each stops the WHOLE song save, which the
 * caller's outer handler then rolls back:
 *   - an error songRelocateIsTransactionFatal() recognises (a deadlock or lock
 *     timeout): the database has already rolled the transaction back, so
 *     carrying on would "commit" nothing and report a false success;
 *   - a failure of the ROLLBACK TO SAVEPOINT itself: the links may then be
 *     half written, and committing that is exactly the fault this exists to
 *     prevent. Refusing the whole save loses nothing — the curator saves again.
 * It must be called inside the caller's transaction (a savepoint outside one
 * does not survive to be rolled back to).
 *
 * @return list<string> Warnings for the curator (plain sentences).
 */
function songTranslationsSaveLinksAllOrNothing(\mysqli $db, string $songId, array $sent): array
{
    try {
        if ($db->savepoint(IHYMNS_TRANSLATION_LINKS_SAVEPOINT) !== true) {
            /* Only reachable with mysqli error reporting switched off (the
               site switches it on in db_mysql.php): no savepoint means
               nothing could be undone, so nothing is attempted. */
            return [songTranslationsLeftUnchangedMessage(new \RuntimeException('the savepoint could not be set'))];
        }
    } catch (\Throwable $e) {
        if (songRelocateIsTransactionFatal($e)) {
            throw $e;
        }
        /* Nothing has been read or written yet, so "unchanged" is true. */
        error_log('[editor save_song] translation links left unchanged (savepoint): ' . $e->getMessage());
        return [songTranslationsLeftUnchangedMessage($e)];
    }
    try {
        $warnings = songTranslationsSaveLinks($db, $songId, $sent);
        $db->release_savepoint(IHYMNS_TRANSLATION_LINKS_SAVEPOINT);
        return $warnings;
    } catch (\Throwable $e) {
        if (songRelocateIsTransactionFatal($e)) {
            throw $e;
        }
        /* Undo this unit only. If THIS fails, it throws out of here on
           purpose — see the docblock: the whole save stops rather than
           committing half the links. */
        $db->query('ROLLBACK TO SAVEPOINT ' . IHYMNS_TRANSLATION_LINKS_SAVEPOINT);
        error_log('[editor save_song] translation links left unchanged: ' . $e->getMessage());
        return [songTranslationsLeftUnchangedMessage($e)];
    }
}

/**
 * The plain sentence for "the translation links were left unchanged", with
 * the reason when it is one a curator can act on (#2137 review round 5).
 * The database's own message goes to the server log, never to the page.
 */
function songTranslationsLeftUnchangedMessage(\Throwable $e): string
{
    $reason = 'saving them failed part-way';
    for ($depth = 0, $x = $e; $x !== null && $depth < 10; $depth++, $x = $x->getPrevious()) {
        if ($x instanceof \mysqli_sql_exception && (int)$x->getCode() === 1062) {
            /* ER_DUP_ENTRY on uq_Translation (SourceSongId, TargetLanguage) */
            $reason = 'two of them would have been stored under the same language'
                . ' (the database treats two of the languages stored or sent for this song as the same one;'
                . ' check the links\' languages for stray spaces or unusual characters)';
            break;
        }
    }
    return 'The translation links were left unchanged because ' . $reason
        . '. The rest of the song was saved; see the server logs for the details.';
}
