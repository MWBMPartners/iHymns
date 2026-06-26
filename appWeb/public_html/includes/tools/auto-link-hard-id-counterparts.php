<?php

declare(strict_types=1);

/**
 * iHymns — Hard-key counterpart auto-promoter (#1125)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Automates what /manage/duplicate-songs already DETECTS but makes a curator
 * click for: linking songs that share an EXACT hard external identifier
 * (ISWC / CCLI / ISRC) across different songbooks into one tblSongLinks
 * counterpart group. A shared hard id is a *certain* same-hymn signal, so it can
 * be promoted as a unit without re-running the fuzzy scorer (the scoring maths
 * stays in includes/song_similarity.php — rule #22; we only need the SQL-proven
 * equality of the id here).
 *
 * This file is a PURE LIBRARY (function definitions only — no top-level work), so
 * it is safe to `require` from the page handler AND from a unit test. It lives
 * under includes/tools/ (web-blocked by the parent .htaccess "Deny from all" on
 * includes/) and is reachable only via PHP require, never a direct HTTP GET —
 * the same placement rationale as build-song-link-suggestions.php (#937).
 *
 * Web entry: /manage/duplicate-songs (POST action=auto_link), gated on the
 * manage_duplicate_songs entitlement (a bulk, non-interactive write).
 *
 * DESIGN INVARIANTS:
 *  - CROSS-BOOK ONLY. Two songs sharing an id within the SAME songbook are NOT
 *    counterparts (the cross-book HAVING + the same-official-songbook drop both
 *    enforce this); a same-official-songbook collision is the §2 "different hymns
 *    sharing a title" case, never auto-linked.
 *  - RESPECT THE CURATOR. A candidate group with ANY dismissed internal pair
 *    (tblSongLinkSuggestionsDismissed) is skipped wholesale — a dismissal is an
 *    explicit "not the same hymn", which overrides even a shared code.
 *  - NEVER AUTO-MERGE GROUPS. A candidate spanning ≥2 existing tblSongLinks
 *    groups is skipped + counted as a conflict (a group merge is a curator call).
 *  - IDEMPOTENT. A second run finds the songs already grouped and links 0.
 *  - STRICT-safe. Optional tables (suggestions / dismissed) and the Origin column
 *    are existence-gated, so this runs on an un-migrated install too (the #1228
 *    white-screen lesson) — without Origin it tags the link via Note instead.
 */

/**
 * PURE planning step (no DB) — decide, per candidate group, what to link.
 *
 * Kept pure so the eligibility rules (same-official drop, cross-book requirement,
 * dismissal suppression, existing-group join/conflict) are unit-testable without
 * a database (tests/php/test-auto-link-hard-id.php).
 *
 * @param array $candidateGroups Each: ['idType'=>'iswc'|'ccli'|'isrc',
 *              'members'=>[ ['sid'=>string,'book'=>string,'official'=>bool], … ]].
 * @param array $existingGroupOf  sid => existing tblSongLinks GroupId (int).
 * @param array $dismissedPairs   set keyed by "A|B" (canonical A<B) => true.
 * @return array ['decisions'=>[ ['idType','target'=>'new'|int,'insert'=>[sid],'members'=>[sid]] ],
 *                'counts'=>['skippedNotCounterpart','skippedDismissed','skippedConflict','skippedLinked']]
 */
function autoLinkPlanGroups(array $candidateGroups, array $existingGroupOf, array $dismissedPairs): array
{
    $decisions = [];
    $counts = [
        'skippedNotCounterpart' => 0,
        'skippedDismissed'      => 0,
        'skippedConflict'       => 0,
        'skippedLinked'         => 0,
    ];

    foreach ($candidateGroups as $g) {
        $members = $g['members'] ?? [];

        /* (1) Drop same-official-songbook collisions: a member in an OFFICIAL book
           that appears ≥2× in this group is almost certainly a DIFFERENT hymn that
           merely shares the id/title (mirrors the page's $distinct rule). */
        $bookCount = [];
        foreach ($members as $m) {
            $bookCount[$m['book']] = ($bookCount[$m['book']] ?? 0) + 1;
        }
        $kept = [];
        foreach ($members as $m) {
            $collision = !empty($m['official']) && ($bookCount[$m['book']] ?? 0) >= 2;
            if (!$collision) { $kept[] = $m; }
        }

        /* Re-check counterpart eligibility on the survivors: ≥2 distinct songs
           spanning ≥2 distinct books. */
        $sids  = array_values(array_unique(array_map(static fn($m) => $m['sid'], $kept)));
        $books = array_values(array_unique(array_map(static fn($m) => $m['book'], $kept)));
        if (count($sids) < 2 || count($books) < 2) {
            $counts['skippedNotCounterpart']++;
            continue;
        }

        /* (2) Dismissal suppression — skip the whole group if ANY internal pair was
           dismissed (an explicit curator override beats a shared code). */
        $dismissed = false;
        $n = count($sids);
        for ($i = 0; $i < $n && !$dismissed; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $sids[$i]; $b = $sids[$j];
                if ($a > $b) { [$a, $b] = [$b, $a]; }
                if (isset($dismissedPairs[$a . '|' . $b])) { $dismissed = true; break; }
            }
        }
        if ($dismissed) { $counts['skippedDismissed']++; continue; }

        /* (3) Existing counterpart-group membership among the survivors. */
        $gids = [];
        foreach ($sids as $sid) {
            if (isset($existingGroupOf[$sid])) { $gids[(int)$existingGroupOf[$sid]] = true; }
        }
        if (count($gids) >= 2) { $counts['skippedConflict']++; continue; } /* never auto-merge groups */

        if (count($gids) === 1) {
            $target = (int)array_key_first($gids);
            $insert = array_values(array_filter($sids, static fn($sid) => !isset($existingGroupOf[$sid])));
            if (!$insert) { $counts['skippedLinked']++; continue; } /* all already linked */
        } else {
            $target = 'new';
            $insert = $sids;
        }

        $decisions[] = [
            'idType'  => (string)($g['idType'] ?? ''),
            'target'  => $target,
            'insert'  => $insert,
            'members' => $sids,
        ];
    }

    return ['decisions' => $decisions, 'counts' => $counts];
}

/**
 * DB driver — find cross-book hard-id groups, plan them, and write the links.
 *
 * @param \mysqli  $db
 * @param int|null $createdBy  tblUsers.Id of the operator (audit), or null.
 * @return array result counts: groupsCreated, songsLinked, joined, skipped*, byType.
 */
function autoLinkHardIdCounterparts(\mysqli $db, ?int $createdBy): array
{
    /* Fixed allow-list: maps an origin label → the exact tblSongs column. The
       column name is interpolated into SQL, so it MUST come only from this
       hardcoded map (never user input) — rule #5. */
    $ID_COLS = ['iswc' => 'Iswc', 'ccli' => 'Ccli', 'isrc' => 'Isrc'];

    $colExists = static function (\mysqli $db, string $t, string $c): bool {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $st->bind_param('ss', $t, $c);
        $st->execute();
        $ok = $st->get_result()->fetch_row() !== null;
        $st->close();
        return $ok;
    };
    $tblExists = static function (\mysqli $db, string $t): bool {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        $st->bind_param('s', $t);
        $st->execute();
        $ok = $st->get_result()->fetch_row() !== null;
        $st->close();
        return $ok;
    };

    $originCol      = $colExists($db, 'tblSongLinks', 'Origin');
    $dismissedExists = $tblExists($db, 'tblSongLinkSuggestionsDismissed');
    $suggExists      = $tblExists($db, 'tblSongLinkSuggestions');

    /* Existing memberships (the link table only holds already-linked songs — small). */
    $existingGroupOf = [];
    if ($r = $db->query('SELECT SongId, GroupId FROM tblSongLinks')) {
        while ($row = $r->fetch_assoc()) { $existingGroupOf[(string)$row['SongId']] = (int)$row['GroupId']; }
        $r->close();
    }

    /* Curator-dismissed pairs (canonical A<B). */
    $dismissedPairs = [];
    if ($dismissedExists && ($r = $db->query('SELECT SongIdA, SongIdB FROM tblSongLinkSuggestionsDismissed'))) {
        while ($row = $r->fetch_assoc()) {
            $dismissedPairs[(string)$row['SongIdA'] . '|' . (string)$row['SongIdB']] = true;
        }
        $r->close();
    }

    $result = [
        'groupsCreated' => 0, 'songsLinked' => 0, 'joined' => 0,
        'skippedNotCounterpart' => 0, 'skippedDismissed' => 0,
        'skippedConflict' => 0, 'skippedLinked' => 0, 'skippedSuspect' => 0,
        'byType' => [],
    ];

    /* Guard against PLACEHOLDER ids that would auto-link a huge false group
       (e.g. Ccli='0' / 'N/A' / 'traditional' shared across many books, or a key
       so generic it spans implausibly many songs). The manual page can surface
       these for a human to ignore; the auto-writer must not act on them. */
    $suspectKey = static function (string $key, int $memberCount): bool {
        $k = trim($key);
        if (mb_strlen($k) < 4) { return true; }                 /* real ISWC≈11 / ISRC≈12 / CCLI≥4 */
        if (preg_match('/^[0\-\s\.]+$/', $k)) { return true; }   /* all zeros / dashes / dots */
        $placeholders = ['n/a', 'na', 'none', 'null', 'nil', 'tbd', 'unknown', 'public domain',
                         'pd', 'traditional', 'trad', 'various', '0000', '00000'];
        if (in_array(mb_strtolower($k), $placeholders, true)) { return true; }
        if ($memberCount > 50) { return true; }                  /* explosion = almost surely a placeholder */
        return false;
    };

    /* Prepared inserts (one shape per Origin-availability). */
    if ($originCol) {
        $ins = $db->prepare('INSERT INTO tblSongLinks (GroupId, SongId, Note, Origin, CreatedBy) VALUES (?, ?, ?, ?, ?)');
    } else {
        $ins = $db->prepare('INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy) VALUES (?, ?, ?, ?)');
    }
    $delSugg = $suggExists ? $db->prepare('DELETE FROM tblSongLinkSuggestions WHERE SongIdA = ? AND SongIdB = ?') : null;

    foreach ($ID_COLS as $idType => $col) {
        /* All members of cross-book groups that share a non-empty value of $col.
           TRIM(col) <> '' excludes both NULL (Iswc/Isrc) and '' (Ccli's NOT-NULL
           placeholder) in one predicate. $col is a hardcoded constant (allow-list). */
        $sql =
            "SELECT LOWER(TRIM(s.`{$col}`)) AS HardKey, s.SongId,
                    s.SongbookAbbr, COALESCE(sb.IsOfficial, 0) AS IsOfficial
               FROM tblSongs s
               LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
              WHERE TRIM(s.`{$col}`) <> ''
                AND LOWER(TRIM(s.`{$col}`)) IN (
                      SELECT k FROM (
                        SELECT LOWER(TRIM(s2.`{$col}`)) AS k FROM tblSongs s2
                         WHERE TRIM(s2.`{$col}`) <> ''
                         GROUP BY LOWER(TRIM(s2.`{$col}`))
                        HAVING COUNT(DISTINCT s2.SongId) > 1
                           AND COUNT(DISTINCT s2.SongbookAbbr) > 1
                         LIMIT 5000
                      ) AS shared_keys
                    )
              ORDER BY HardKey, s.SongId";
        /* NB: the LIMIT lives in a DERIVED TABLE, not directly in the IN() —
           MySQL rejects "LIMIT & IN subquery" (error 1235) otherwise. */
        $byKey = [];
        if ($res = $db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $byKey[(string)$row['HardKey']][] = [
                    'sid'      => (string)$row['SongId'],
                    'book'     => (string)$row['SongbookAbbr'],
                    'official' => ((int)$row['IsOfficial'] === 1),
                ];
            }
            $res->free();
        }
        $candidateGroups = [];
        foreach ($byKey as $key => $members) {
            if ($suspectKey((string)$key, count($members))) { $result['skippedSuspect']++; continue; }
            $candidateGroups[] = ['idType' => $idType, 'members' => $members];
        }

        /* Plan against the CURRENT memberships (so a pair already linked by an
           earlier idType pass shows as "already grouped" and is joined/skipped). */
        $plan = autoLinkPlanGroups($candidateGroups, $existingGroupOf, $dismissedPairs);
        foreach ($plan['counts'] as $k => $v) { $result[$k] += $v; }

        $note   = $originCol ? '' : ('auto-link: shared ' . strtoupper($idType));
        $origin = 'auto-' . $idType;
        $typeCreated = 0; $typeLinked = 0; $typeJoined = 0;

        foreach ($plan['decisions'] as $d) {
            $db->begin_transaction();
            try {
                if ($d['target'] === 'new') {
                    /* MAX+1 mint (same idiom as the manual link action). Not row-locked,
                       so two SIMULTANEOUS operators could mint the same GroupId — but
                       this is a single-operator, button-gated bulk tool, so that race is
                       not defended here (would need fixing in the manual action too). */
                    $rr = $db->query('SELECT COALESCE(MAX(GroupId), 0) + 1 AS NextId FROM tblSongLinks');
                    $groupId = $rr ? (int)$rr->fetch_assoc()['NextId'] : 1;
                    if ($rr) { $rr->close(); }
                    $isNew = true;
                } else {
                    $groupId = (int)$d['target'];
                    $isNew = false;
                }
                foreach ($d['insert'] as $sid) {
                    if ($originCol) {
                        $ins->bind_param('isssi', $groupId, $sid, $note, $origin, $createdBy);
                    } else {
                        $ins->bind_param('issi', $groupId, $sid, $note, $createdBy);
                    }
                    $ins->execute();
                }
                $db->commit();
            } catch (\Throwable $e) {
                try { $db->rollback(); } catch (\Throwable $_) {}
                /* Skip this group; a UNIQUE race or a vanished song shouldn't abort
                   the whole run. Don't update in-memory state for it. */
                continue;
            }

            /* Commit succeeded — reflect it in memory + tally. */
            foreach ($d['insert'] as $sid) { $existingGroupOf[$sid] = $groupId; }
            $cnt = count($d['insert']);
            $typeLinked += $cnt;
            if ($isNew) { $typeCreated++; } else { $typeJoined += $cnt; }

            /* Best-effort: drop now-resolved fuzzy suggestion rows for every pair. */
            if ($delSugg) {
                $mm = $d['members']; $c = count($mm);
                for ($i = 0; $i < $c; $i++) {
                    for ($j = $i + 1; $j < $c; $j++) {
                        $a = $mm[$i]; $b = $mm[$j];
                        if ($a > $b) { [$a, $b] = [$b, $a]; }
                        try { $delSugg->bind_param('ss', $a, $b); $delSugg->execute(); }
                        catch (\Throwable $_) { /* best-effort */ }
                    }
                }
            }
        }

        $result['groupsCreated'] += $typeCreated;
        $result['songsLinked']   += $typeLinked;
        $result['joined']        += $typeJoined;
        $result['byType'][$idType] = ['groupsCreated' => $typeCreated, 'songsLinked' => $typeLinked, 'joined' => $typeJoined];
    }

    $ins->close();
    if ($delSugg) { $delSugg->close(); }

    return $result;
}
