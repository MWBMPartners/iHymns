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
            /* Two stored links, one language (`mo` and `ro`). The editor sent
               back exactly one of those spellings → the curator's choice: keep
               that row as it is, delete the others. Otherwise keep all. */
            $want = $desired[$key] ?? null;
            $chosen = null;
            if ($want !== null && isset($want['raw'])) {
                $hits = array_values(array_filter(
                    $rows,
                    static fn(array $r): bool => mb_strtolower((string)$r['language']) === $want['raw']
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
            foreach ($rows as $r) {
                if ((int)$r['id'] !== (int)$chosen['id']) {
                    $plan['delete'][] = (int)$r['id'];
                }
            }
            /* The kept row keeps its language as stored; only a re-point the
               curator made in the same save is applied (in place). The next
               save tidies its language, once no clash is left. */
            if (strcasecmp((string)$chosen['songId'], $want['songId']) !== 0) {
                $plan['update'][] = ['id' => (int)$chosen['id'], 'songId' => $want['songId'], 'language' => (string)$chosen['language']];
            }
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
    /* Languages whose STORED link must survive this save even
       though the editor's copy of it is skipped below for a
       reason that is not the curator's (#2137 review). */
    $keep = [];
    foreach ($sent as $tr) {
        if (!is_array($tr)) { continue; }
        $tId   = trim((string)($tr['songId']   ?? ''));
        $tLang = trim((string)($tr['language'] ?? ''));
        if ($tId === '' || $tLang === '') { continue; }
        /* A song cannot be a translation of itself — the same
           rule the legacy add_translation endpoint enforced. */
        if (strcasecmp($tId, $songId) === 0) {
            $warnings[] = 'A song cannot be a translation of itself — skipped.';
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
            $keep[mb_strtolower($tLang)] = true;   /* a stored row with this value is left as it is */
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
            $keep[$key] = true;   /* what is stored stays */
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
                $keep[$key] = true;   /* skipped for the server's reason, not removed by the curator */
                continue;
            }
            if (!isset($idOk[mb_strtolower($d['songId'])])) {
                $warnings[] = 'Song "' . $d['songId']
                    . '" no longer exists — translation link skipped.';
                unset($desired[$key]);
                /* #2137 review — skipped, not removed by the curator: a link
                   stored for this language stays as it is (it used to be
                   deleted here, translator and all). */
                $keep[$key] = true;
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

    /* ---- 4. Apply the diff ----
       A song with no translation links (the overwhelmingly common
       case) reaches here with both sides empty and performs ZERO
       writes — the save path stays behaviourally identical to
       before #1626 for every song that has never used the panel.
       songTranslationsPlanSync() decides; this only writes.
       UPDATEs are in place, so a row's Translator / Verified /
       CreatedAt survive a re-point or a tidied language. */
    $plan = songTranslationsPlanSync($desired, $existing, $keep, 'mediaLanguageTagForStorage');
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
