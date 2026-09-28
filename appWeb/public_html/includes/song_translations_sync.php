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
 *     update: list<array{id:int, songId:string, language:string}>,
 *     insert: list<array{songId:string, language:string}>,
 *     warnings: list<string>,
 *     blocked: list<string>
 * }
 *        'blocked' lists the $desired keys whose change would have touched a
 *        protected row, so it was NOT made. The caller treats each as a link
 *        that could not be written (it protects the rows sharing its song or
 *        language in turn) and plans again.
 */
function songTranslationsPlanSync(array $desired, array $existing, array $protected, callable $tidy): array
{
    $plan = ['delete' => [], 'update' => [], 'insert' => [], 'warnings' => [], 'blocked' => []];
    $isProtected = static fn(array $r): bool => isset($protected[(int)$r['id']]);

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
               curator made in the same save is applied (in place). The next
               save tidies its language, once no clash is left. A re-point of
               a PROTECTED row is not made (round 4): it goes back to the
               caller as blocked, and nothing in this group is deleted on
               the strength of a choice that is not going to happen. */
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
                $plan['update'][] = ['id' => (int)$chosen['id'], 'songId' => $want['songId'], 'language' => (string)$chosen['language']];
            }
            continue;
        }
        $stored = $rows[0];
        if (!isset($desired[$key])) {
            if (!$isProtected($stored)) {
                $plan['delete'][] = (int)$stored['id'];
            }
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

/**
 * Save a song's whole-song translation links: the song save's steps 1–4
 * (moved here from save_song_core.php so they can be tested against a real
 * database, #2137 review). Reads what is stored for $songId, decides with
 * songTranslationsPlanSync(), and writes only the difference. Runs inside the
 * caller's transaction and throws what mysqli throws; the caller keeps its
 * best-effort catch.
 *
 * @param \mysqli $db
 * @param string  $songId The source song (SourceSongId).
 * @param array   $sent   The editor's [{songId, language}, …] — the same shape
 *                        SongData::_getTranslations() emits.
 * @return list<string>   Warnings for the curator (plain sentences).
 */
function songTranslationsSaveLinks(\mysqli $db, string $songId, array $sent): array
{
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
        if (!is_array($tr)) { continue; }
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
       UPDATEs are in place, so a row's Translator / Verified /
       CreatedAt survive a re-point or a tidied language. */
    foreach ($plan['warnings'] as $w) { $warnings[] = $w; }
    foreach ($plan['delete'] as $delId) {
        $dStm = $db->prepare('DELETE FROM tblSongTranslations WHERE Id = ?');
        $dStm->bind_param('i', $delId);
        $dStm->execute();
        $dStm->close();
    }
    foreach ($plan['update'] as $u) {
        $uStm = $db->prepare(
            'UPDATE tblSongTranslations SET TranslatedSongId = ?, TargetLanguage = ? WHERE Id = ?'
        );
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
