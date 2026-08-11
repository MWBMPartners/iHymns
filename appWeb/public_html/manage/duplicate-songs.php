<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Duplicate & Counterpart Review (#1064 / #1215)
 *
 * The single review surface for "are these the same hymn?" — it absorbed the
 * old /manage/song-link-suggestions page (#807/#808). A curator triages
 * candidate groups that the engine flags, then **Links** them as cross-book
 * counterparts, **Merges** true duplicates, or **Dismisses** false positives.
 *
 * Detection (read, on load) — candidate discovery is cheap; scoring is bounded:
 *   1. Cheap pass: one row per song (id/title/NormalizedTitle/songbook/IsOfficial
 *      /hard-ids). Group into candidate clusters via union-find over:
 *        - exact normalised-title classes (ihymns_normalize_title),
 *        - shared hard identifiers (Isrc / Iswc / Ccli),
 *        - shared external-link URL,
 *        - pre-scored fuzzy cross-book pairs from tblSongLinkSuggestions.
 *   2. Heavy pass (CANDIDATE MEMBERS ONLY — never the whole corpus, rule #17):
 *      fetch first-line lyric + writer/composer names + lyrics count.
 *   3. Score every cluster with the shared includes/song_similarity.php scorer
 *      (0.50·title + 0.35·first-line + 0.15·authors, hard-id → certain).
 *
 * Taxonomy (deterministic, by book span):
 *   §1 Cross-book counterparts (bookSpan ≥ 2)  → Link primary, Merge secondary.
 *   §2 Same title, one official songbook       → likely DISTINCT; guarded Merge.
 *   §3 Same title, one non-official collection  → probable import dupe; Merge.
 *
 * Actions (POST, CSRF-gated; per-action entitlements):
 *   - merge   (manage_duplicate_songs) — repoints every SongId FK to the
 *     survivor, then deletes the duplicate. Irreversible. Same-official-songbook
 *     pairs require force=1 (the §2 guard, #1218).
 *   - link    (edit_songs) — write tblSongLinks counterpart group (#1219).
 *   - unlink  (edit_songs) — remove ONE song from its tblSongLinks group,
 *     cleaning up an orphaned singleton (#1629 — the editor's link CRUD
 *     had no home outside the editor before this; retiring v1 api.php
 *     would have made links append-only without it).
 *   - dismiss (edit_songs) — write tblSongLinkSuggestionsDismissed (#1219).
 *   - rebuild (edit_songs) — re-run the fuzzy suggestion builder (#1219).
 *
 * Gating: page view = edit_songs (curators); merge = manage_duplicate_songs.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'title_normalize.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_similarity.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_redirects.php';   /* #1343 */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* #1694 */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_external_ids.php';   /* #1749 — merge must MOVE store rows, not let the FK cascade eat them (#1755) */

/* @disabled-visible: admin surface (#1765) — this ENTIRE page (detection,
   link/unlink, dismiss, rebuild, merge) is gated edit_songs / manage_duplicate_songs
   and disabled songbooks stay fully visible/editable in /manage (owner
   decision): a curator must be able to spot, link, or merge duplicates
   inside a book that has been disabled from the public site just as freely
   as any other book. Every raw tblSongs/tblSongbooks read in this file is
   covered by this ONE marker (file-scope unit). */
if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
$role        = $currentUser['role'] ?? null;
/* Page view is curator-level (the suggestions page it absorbed was edit_songs);
   the destructive Merge stays admin-level. */
if (!$currentUser || !userHasEntitlement('edit_songs', $role)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — edit_songs required</h1></body></html>';
    exit;
}
$canMerge = userHasEntitlement('manage_duplicate_songs', $role);
$activePage = 'duplicate-songs';

$db   = getDbMysqli();
$csrf = csrfToken();

/* Every table that references tblSongs.SongId (authoritative, from schema.sql).
   The merge re-points each to the survivor. Table + column names are fixed
   constants from THIS source (never user input) — safe to interpolate; the
   SongId VALUES are always bound. (See #1064 for the FK-vs-soft-ref split.) */
const MERGE_FK_TABLES_SINGLE = [
    'tblSongbookEntries', 'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
    'tblSongAdaptors', 'tblSongTranslators', 'tblSongArtists', 'tblSongComponents',
    'tblLyrics', 'tblUserFavorites', 'tblSongKeys', 'tblSongHistory', 'tblSongTagMap',
    'tblSongLinks', 'tblCatalogueSongs', 'tblSongExternalLinks', 'tblSongAlternativeTitles',
    'tblSongLanguages', 'tblSongMedia', 'tblWorkSongs',
];
const MERGE_FK_TABLES_PAIR = [
    'tblSongTranslations'   => ['SourceSongId', 'TranslatedSongId'],
    'tblSongLinkSuggestions' => ['SongIdA', 'SongIdB'],
];
const MERGE_SOFT_REFS = [
    'tblSongRequests'                 => ['ResolvedSongId'],
    'tblSongRevisions'                => ['SongId'],
    'tblSongLinkSuggestionsDismissed' => ['SongIdA', 'SongIdB'],
];

/* ----------------------------------------------------------------------
 * POST dispatcher — JSON in / JSON out.
 * ---------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    /* Robust same-origin check (not the stale-prone baked token) so a long-open
       duplicates page never sporadically 403s a legit merge/delete (owner report;
       the client now also sends X-Requested-With). A valid token still passes. */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF check failed — please retry.']);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    /* -------- merge (admin only) -------- */
    if ($action === 'merge') {
        if (!$canMerge) {
            http_response_code(403);
            echo json_encode(['error' => 'Merge requires the manage_duplicate_songs entitlement.']);
            exit;
        }
        $survivor  = trim((string)($_POST['survivor_id'] ?? ''));
        $duplicate = trim((string)($_POST['duplicate_id'] ?? ''));
        if ($survivor === '' || $duplicate === '') {
            http_response_code(400);
            echo json_encode(['error' => 'survivor_id and duplicate_id are required.']);
            exit;
        }
        if ($survivor === $duplicate) {
            http_response_code(400);
            echo json_encode(['error' => 'A song cannot be merged into itself.']);
            exit;
        }

        /* Both must exist AND be visible (#1694) — a soft-deleted song is
           never OFFERED for merge (the listings below are filtered), so a
           merge naming one is a stale request; refuse rather than destroy or
           resurrect. Restore first, then merge. */
        $chk = $db->prepare('SELECT SongId FROM tblSongs WHERE SongId IN (?, ?) AND ' . songVisibleSql($db, ''));
        $chk->bind_param('ss', $survivor, $duplicate);
        $chk->execute();
        $found = [];
        $r = $chk->get_result();
        while ($row = $r->fetch_assoc()) { $found[(string)$row['SongId']] = true; }
        $chk->close();
        if (!isset($found[$survivor]) || !isset($found[$duplicate])) {
            http_response_code(400);
            echo json_encode(['error' => 'Both songs must exist.']);
            exit;
        }

        /* #1218 guard — two songs in the SAME official songbook with the same
           title are almost always DIFFERENT hymns (a published hymnal does not
           list one song twice under two numbers). Refuse to merge such a pair
           unless the curator explicitly forces it via the §2 type-to-confirm
           path, so a casual click or a replayed request can't quietly destroy a
           distinct record. */
        $force = (string)($_POST['force'] ?? '') === '1';
        if (!$force) {
            $bstmt = $db->prepare(
                'SELECT s.SongId, s.SongbookAbbr, COALESCE(sb.IsOfficial, 0) AS IsOfficial
                   FROM tblSongs s
                   LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                  WHERE s.SongId IN (?, ?)'
            );
            $bstmt->bind_param('ss', $survivor, $duplicate);
            $bstmt->execute();
            $books = [];
            $br = $bstmt->get_result();
            while ($row = $br->fetch_assoc()) { $books[(string)$row['SongId']] = $row; }
            $bstmt->close();
            $sa = $books[$survivor]  ?? null;
            $sd = $books[$duplicate] ?? null;
            if ($sa && $sd
                && (string)$sa['SongbookAbbr'] === (string)$sd['SongbookAbbr']
                && (int)$sa['IsOfficial'] === 1) {
                http_response_code(409);
                echo json_encode(['error' =>
                    'Both songs are in the same official songbook (' . (string)$sa['SongbookAbbr']
                    . ') — almost certainly different hymns that share a title. Merge is blocked here; '
                    . 'use the type-to-confirm control if you are certain.']);
                exit;
            }
        }

        $db->begin_transaction();
        try {
            /* Single-column FK tables. */
            foreach (MERGE_FK_TABLES_SINGLE as $t) {
                $u = $db->prepare("UPDATE IGNORE `{$t}` SET SongId = ? WHERE SongId = ?");
                $u->bind_param('ss', $survivor, $duplicate);
                $u->execute();
                $u->close();
                /* Any rows that couldn't move (UNIQUE collision with a survivor row)
                   are leftover duplicates — drop them. */
                $d = $db->prepare("DELETE FROM `{$t}` WHERE SongId = ?");
                $d->bind_param('s', $duplicate);
                $d->execute();
                $d->close();
            }

            /* #1749 full unification — the store is the recording-ID
             * authority; a merge must MOVE the duplicate's tblSongExternalIds
             * rows to the survivor, not let fk_SongExternalIds_Song's
             * ON DELETE CASCADE (schema.sql) silently eat them the moment
             * the duplicate row is deleted below. Before this fix a merge
             * quietly destroyed the duplicate's external-ID history — data
             * loss that pre-dates this commit (issue #1755).
             *
             * ELI5: when two song records get merged into one, any Spotify /
             * ISRC / MusicBrainz ids recorded against the one that's going
             * away need to move to the one that survives, not vanish.
             *
             * The duplicate's own MARKER row (its `SourceRef =
             * SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF` column-projection
             * artefact, if it has one) is DEMOTED to a plain second-recording
             * row (`SourceRef` -> NULL, `Source` kept for provenance) as part
             * of the SAME UPDATE, so the survivor can never end up with TWO
             * marker rows for one song — an anomaly `songExternalIdIsrcProjectionSql()`'s
             * `ORDER BY` only ever expects at most one of (§2.1 in the build
             * spec). The survivor's OWN marker, if it has one, is untouched
             * and stays the §2.1 primary; the demoted row simply becomes an
             * ordinary rank-2-by-Id candidate.
             *
             * `UPDATE IGNORE` + DELETE-leftover is the SAME collision idiom
             * the `MERGE_FK_TABLES_SINGLE` loop above uses for
             * `uq_Song_Type_Value (SongId, IdType, IdValue)` — a row that
             * can't move because the survivor already has the identical
             * (IdType, IdValue) pair is a leftover duplicate fact, safe to
             * drop.
             *
             * Deliberately NOT added to `MERGE_FK_TABLES_SINGLE`: that loop's
             * plain `SET SongId = ?` has no way to ALSO carry the SourceRef
             * demotion, and running it ungated on an un-migrated install
             * would STRICT-throw on a missing table (rule #9) — so this stays
             * its own gated block, existence-probed the same way every other
             * `tblSongExternalIds` touch in this codebase is.
             *
             * The duplicate's OWN `tblSongs.Isrc` column needs nothing here:
             * the whole row is hard-deleted a few statements below. */
            if (songExternalIdsTableExists($db)) {
                $isrcMirrorSourceRef = SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF;
                $extIdMove = $db->prepare(
                    "UPDATE IGNORE tblSongExternalIds
                        SET SongId = ?,
                            SourceRef = IF(IdType = 'isrc' AND SourceRef <=> ?, NULL, SourceRef)
                      WHERE SongId = ?"
                );
                $extIdMove->bind_param('sss', $survivor, $isrcMirrorSourceRef, $duplicate);
                $extIdMove->execute();
                $extIdMove->close();
                /* Leftover rows that couldn't move (uq_Song_Type_Value
                   collision with a row the survivor already has) — same
                   drop-the-duplicate-fact posture as every other table above. */
                $extIdLeftover = $db->prepare('DELETE FROM tblSongExternalIds WHERE SongId = ?');
                $extIdLeftover->bind_param('s', $duplicate);
                $extIdLeftover->execute();
                $extIdLeftover->close();
                /* The store has the last word (D-1): re-project the
                   survivor's now-possibly-changed set of isrc rows into its
                   tblSongs.Isrc column — this is what lets a survivor with NO
                   prior ISRC of its own get PROMOTED to the duplicate's, and
                   what re-confirms the survivor's own marker stays primary
                   when it already had one. */
                songExternalIdSyncIsrcDenorm($db, $survivor);
            }

            /* Two-column relationship tables. */
            foreach (MERGE_FK_TABLES_PAIR as $t => $cols) {
                foreach ($cols as $c) {
                    $u = $db->prepare("UPDATE IGNORE `{$t}` SET `{$c}` = ? WHERE `{$c}` = ?");
                    $u->bind_param('ss', $survivor, $duplicate);
                    $u->execute();
                    $u->close();
                    $d = $db->prepare("DELETE FROM `{$t}` WHERE `{$c}` = ?");
                    $d->bind_param('s', $duplicate);
                    $d->execute();
                    $d->close();
                }
            }
            /* Soft references (no FK constraint → repoint explicitly or they
               dangle after the delete). Same UPDATE IGNORE + DELETE-leftover. */
            foreach (MERGE_SOFT_REFS as $t => $cols) {
                foreach ($cols as $c) {
                    $u = $db->prepare("UPDATE IGNORE `{$t}` SET `{$c}` = ? WHERE `{$c}` = ?");
                    $u->bind_param('ss', $survivor, $duplicate);
                    $u->execute();
                    $u->close();
                    $d = $db->prepare("DELETE FROM `{$t}` WHERE `{$c}` = ?");
                    $d->bind_param('s', $duplicate);
                    $d->execute();
                    $d->close();
                }
            }
            /* #1343 — keep the duplicate's permalink alive: forward any redirects
               that already pointed AT the duplicate to the survivor (so they aren't
               nulled by the FK cascade when the row goes), then add the duplicate ->
               survivor redirect itself. Gated — no-ops if tblSongRedirects isn't
               migrated. Done before the delete so the cascade can't strand a chain. */
            $mergeBy = (int)($currentUser['id'] ?? 0) ?: null;
            songRedirectRepoint($db, $duplicate, $survivor);
            songRedirectWrite($db, $duplicate, $survivor, 'merge', $mergeBy);
            /* #1343-B — also keep the duplicate's opaque PublicId permalink alive
               (a shared /song/<PublicId> must survive the merge). Gated. */
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_public_id.php';
            if (songPublicId_columnReady($db)) {
                $mp = $db->prepare('SELECT PublicId FROM tblSongs WHERE SongId = ? LIMIT 1');
                $mp->bind_param('s', $duplicate);
                $mp->execute();
                $dupPubId = (string)($mp->get_result()->fetch_assoc()['PublicId'] ?? '');
                $mp->close();
                if ($dupPubId !== '') { songRedirectWrite($db, $dupPubId, $survivor, 'merge', $mergeBy); }
            }

            /* Finally remove the duplicate song. */
            $del = $db->prepare('DELETE FROM tblSongs WHERE SongId = ?');
            $del->bind_param('s', $duplicate);
            $del->execute();
            $del->close();

            $db->commit();

            if (function_exists('logActivity')) {
                try {
                    logActivity('song.merge', 'song', $survivor, [
                        'merged_from' => $duplicate,
                        'tables'      => count(MERGE_FK_TABLES_SINGLE) + count(MERGE_FK_TABLES_PAIR) + count(MERGE_SOFT_REFS),
                    ]);
                } catch (\Throwable $_e) { /* audit best-effort */ }
            }
            echo json_encode(['success' => true, 'survivorId' => $survivor, 'mergedFrom' => $duplicate]);
            exit;
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable $_) {}
            http_response_code(500);
            echo json_encode(['error' => 'Merge failed (rolled back): ' . $e->getMessage()]);
            exit;
        }
    }

    /* -------- link (curator) — bulk cross-book counterpart link (#1219) -------- */
    if ($action === 'link') {
        $ids = array_values(array_unique(array_filter(array_map('trim',
            explode(',', (string)($_POST['song_ids'] ?? ''))))));
        if (count($ids) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Pick at least two songs to link.']);
            exit;
        }
        try {
            $ph    = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('s', count($ids));

            /* All must exist AND be visible (#1694 — same stale-request
               reasoning as the merge gate above). */
            $chk = $db->prepare("SELECT SongId FROM tblSongs WHERE SongId IN ($ph) AND " . songVisibleSql($db, ''));
            $chk->bind_param($types, ...$ids);
            $chk->execute();
            $exist = [];
            $r = $chk->get_result();
            while ($row = $r->fetch_assoc()) { $exist[(string)$row['SongId']] = true; }
            $chk->close();
            foreach ($ids as $id) {
                if (!isset($exist[$id])) {
                    http_response_code(404);
                    echo json_encode(['error' => "Song not found: {$id}"]);
                    exit;
                }
            }

            /* Existing counterpart-group memberships among the picked songs. */
            $lk = $db->prepare("SELECT SongId, GroupId FROM tblSongLinks WHERE SongId IN ($ph)");
            $lk->bind_param($types, ...$ids);
            $lk->execute();
            $member = []; $groups = [];
            $r = $lk->get_result();
            while ($row = $r->fetch_assoc()) {
                $member[(string)$row['SongId']] = (int)$row['GroupId'];
                $groups[(int)$row['GroupId']]   = true;
            }
            $lk->close();

            /* Mirror the editor add_song_link invariant: 0 groups → mint; 1 → join;
               ≥2 distinct → refuse (the curator must unlink one first).

               #1629: this used to say "Unlink one in the editor first" —
               the editor's own link CRUD (v1 api.php:475/552/689,
               editor.js:3176-3459) is retiring with the rest of v1, which
               would have turned this into a dead end pointing at a page
               that won't exist. The `unlink` action below (this same
               dispatcher, same page) is that home now, so the message
               points at the button next to each linked row instead. */
            if (count($groups) >= 2) {
                http_response_code(409);
                echo json_encode(['error' => 'These songs are already in different counterpart groups. Click "Unlink" next to one of them below, then Link again.']);
                exit;
            }
            $createdBy = (int)($currentUser['id'] ?? 0) ?: null;
            if (count($groups) === 1) {
                $groupId = (int)array_key_first($groups);
            } else {
                $rr = $db->query('SELECT COALESCE(MAX(GroupId), 0) + 1 AS NextId FROM tblSongLinks');
                $groupId = $rr ? (int)$rr->fetch_assoc()['NextId'] : 1;
                if ($rr) { $rr->close(); }
            }

            $db->begin_transaction();
            $ins = $db->prepare('INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy) VALUES (?, ?, ?, ?)');
            $emptyNote = '';
            $added = 0;
            foreach ($ids as $id) {
                if (isset($member[$id])) { continue; } /* already in the group */
                $ins->bind_param('issi', $groupId, $id, $emptyNote, $createdBy);
                $ins->execute();
                $added++;
            }
            $ins->close();
            $db->commit();

            /* Best-effort: drop any now-resolved fuzzy suggestion rows for the
               linked pairs (outside the transaction — a missing table or stray
               row must not undo the link itself). */
            try {
                $delS = $db->prepare('DELETE FROM tblSongLinkSuggestions WHERE SongIdA = ? AND SongIdB = ?');
                $m = count($ids);
                for ($i = 0; $i < $m; $i++) {
                    for ($j = $i + 1; $j < $m; $j++) {
                        $a = $ids[$i]; $b = $ids[$j];
                        if ($a > $b) { [$a, $b] = [$b, $a]; }
                        $delS->bind_param('ss', $a, $b);
                        $delS->execute();
                    }
                }
                $delS->close();
            } catch (\Throwable $_e) { /* cleanup best-effort */ }

            if (function_exists('logActivity')) {
                try { logActivity('song.link', 'song', (string)$ids[0], ['group' => $groupId, 'members' => $ids, 'added' => $added]); }
                catch (\Throwable $_e) {}
            }
            echo json_encode(['success' => true, 'groupId' => $groupId, 'added' => $added]);
            exit;
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable $_) {}
            http_response_code(500);
            echo json_encode(['error' => 'Link failed: ' . $e->getMessage()]);
            exit;
        }
    }

    /* -------- unlink (curator) — remove ONE song from its existing
       counterpart group (#1629).
       ELI5: takes one song out of its "these are the same hymn" group so
       it's free to join a different group.

       WHY THIS EXISTS: nothing outside the editor could remove a
       tblSongLinks row — the `link` action above refuses to combine two
       songs that are already in DIFFERENT groups with the 409 above, and
       until now the only way to clear that conflict was the editor's
       remove_song_link (v1 api.php:692, editor.js:3176-3459). Retiring v1
       (the v2 editor cutover, #1601) would have made counterpart links
       APPEND-ONLY with no way to fix a bad link short of a DB console —
       so this ports the same delete-then-singleton-cleanup logic here,
       the one place left that needs it. This also resolves #1608: the
       editor's inline suggestions panel can retire only once unlink has
       another home, and this is that home.

       Entitlement: edit_songs — the SAME gate as link/dismiss above (the
       page-level check at the top of this file), deliberately NOT
       manage_duplicate_songs. Unlinking doesn't delete a song or lose
       data (the group row is the only thing removed, and Link can always
       recreate it) — merge is the one irreversible, admin-only action
       here.
       CSRF: validateCsrfRequest() already gated this whole POST
       dispatcher above (line ~98) — same same-origin check (rule #29),
       never a baked $_SESSION token compared with validateCsrf() alone. */
    if ($action === 'unlink') {
        $songId = trim((string)($_POST['song_id'] ?? ''));
        if ($songId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'song_id is required.']);
            exit;
        }
        try {
            /* Resolve the row + its GroupId before deleting so we can
               clean up an orphaned singleton in the same pass — mirrors
               v1's remove_song_link (api.php:692) exactly. */
            $stmt = $db->prepare('SELECT Id, GroupId FROM tblSongLinks WHERE SongId = ?');
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                /* Already not linked — success, not an error, so a
                   double-click (or a stale page) doesn't surface a
                   spurious failure. Same choice v1 made. */
                echo json_encode(['success' => true, 'deleted' => 0]);
                exit;
            }

            $groupId = (int)$row['GroupId'];
            $rowId   = (int)$row['Id'];

            $del = $db->prepare('DELETE FROM tblSongLinks WHERE Id = ?');
            $del->bind_param('i', $rowId);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();

            /* A group with fewer than two members left is meaningless —
               drop the remainder too (same rule the editor enforced). */
            $r = $db->prepare('SELECT COUNT(*) AS n FROM tblSongLinks WHERE GroupId = ?');
            $r->bind_param('i', $groupId);
            $r->execute();
            $remaining = (int)$r->get_result()->fetch_assoc()['n'];
            $r->close();
            if ($remaining < 2) {
                $cleanup = $db->prepare('DELETE FROM tblSongLinks WHERE GroupId = ?');
                $cleanup->bind_param('i', $groupId);
                $cleanup->execute();
                $cleanup->close();
            }

            if (function_exists('logActivity')) {
                try { logActivity('song.unlink', 'song', $songId, ['group' => $groupId]); }
                catch (\Throwable $_e) {}
            }
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            exit;
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Unlink failed: ' . $e->getMessage()]);
            exit;
        }
    }

    /* -------- dismiss (curator) — mark a group reviewed / not-the-same (#1219) -------- */
    if ($action === 'dismiss') {
        $ids = array_values(array_unique(array_filter(array_map('trim',
            explode(',', (string)($_POST['song_ids'] ?? ''))))));
        if (count($ids) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Need at least two songs to dismiss as a group.']);
            exit;
        }
        try {
            $by = (int)($currentUser['id'] ?? 0) ?: null;
            $reason = 'reviewed: not the same hymn';
            /* Record every unordered pair so the whole grouping stops surfacing
               (the detection skips a cluster only when ALL its pairs are
               dismissed). Canonical SongIdA < SongIdB. */
            $insD = $db->prepare(
                'INSERT INTO tblSongLinkSuggestionsDismissed (SongIdA, SongIdB, DismissedBy, Reason)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE DismissedBy = VALUES(DismissedBy),
                                         Reason = VALUES(Reason),
                                         DismissedAt = CURRENT_TIMESTAMP'
            );
            $delS = $db->prepare('DELETE FROM tblSongLinkSuggestions WHERE SongIdA = ? AND SongIdB = ?');
            $m = count($ids);
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $ids[$i]; $b = $ids[$j];
                    if ($a > $b) { [$a, $b] = [$b, $a]; }
                    $insD->bind_param('ssis', $a, $b, $by, $reason);
                    $insD->execute();
                    $delS->bind_param('ss', $a, $b);
                    $delS->execute();
                }
            }
            $insD->close();
            $delS->close();
            echo json_encode(['success' => true]);
            exit;
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Dismiss failed: ' . $e->getMessage()]);
            exit;
        }
    }

    /* -------- rebuild (curator) — re-run the fuzzy suggestion builder (#1219) -------- */
    if ($action === 'rebuild') {
        ob_start();
        try {
            require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes'
                . DIRECTORY_SEPARATOR . 'tools'
                . DIRECTORY_SEPARATOR . 'build-song-link-suggestions.php';
            $out = (string)ob_get_clean();
            echo json_encode(['success' => true, 'message' => trim(strip_tags(str_replace('<br>', ' · ', $out)))]);
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) { ob_end_clean(); }
            http_response_code(500);
            echo json_encode(['error' => 'Rebuild failed: ' . $e->getMessage()]);
        }
        exit;
    }

    /* -------- auto_link (admin) — bulk-link cross-book songs sharing a hard id (#1125) --------
       A shared ISWC/CCLI/ISRC across different songbooks is a certain same-hymn
       signal, so we promote whole groups without a per-cluster click. Gated on
       manage_duplicate_songs (a bulk, non-interactive write — stricter than the
       interactive single-cluster `link`). The shared driver does the matching,
       same-official-songbook exclusion, dismissal/existing-link respect + the
       writes (includes/tools/auto-link-hard-id-counterparts.php). */
    if ($action === 'auto_link') {
        if (!$canMerge) {
            http_response_code(403);
            echo json_encode(['error' => 'Auto-link requires the manage_duplicate_songs entitlement.']);
            exit;
        }
        try {
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes'
                . DIRECTORY_SEPARATOR . 'tools'
                . DIRECTORY_SEPARATOR . 'auto-link-hard-id-counterparts.php';
            $createdBy = (int)($currentUser['id'] ?? 0) ?: null;
            $r = autoLinkHardIdCounterparts($db, $createdBy);
            if (function_exists('logActivity')) {
                try { logActivity('song.link.auto', 'song', '', $r); } catch (\Throwable $_e) {}
            }
            echo json_encode(['success' => true] + $r);
            exit;
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Auto-link failed: ' . $e->getMessage()]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
    exit;
}

/* ----------------------------------------------------------------------
 * GET — detect candidate clusters.
 * ---------------------------------------------------------------------- */

/* The detection pass below issues several read queries. The DB layer runs
   mysqli under MYSQLI_REPORT_STRICT (includes/db_mysql.php), so a query against
   a table/column missing on THIS environment — e.g. a #1215 / #1064 migration
   not yet applied on alpha — THROWS mysqli_sql_exception; it does NOT return
   false, so the `if ($res)` guards below cannot soak it up. A single uncaught
   throw used to white-screen the whole page (#1228). Two layers of defence:
     (1) every OPTIONAL edge source is gated on an explicit table-existence
         probe, so a missing suggestions / dismissed / external-links table just
         contributes no edges (the page still works off exact-title + shared-id
         detection); and
     (2) the whole pass is wrapped in one try/catch, so a CORE failure degrades
         to an actionable error card in the render — never a blank page. */
$detectError    = null;
$songs          = [];
$lyricsCount    = [];
$sectioned      = ['cross' => [], 'official' => [], 'other' => []];
$totalClusters  = 0;
$dismissedPairs = [];

/* Local table-existence probe. INFORMATION_SCHEMA is always present, so this
   read itself never throws — making it a safe gate for the optional sources. */
$tableExists = static function (\mysqli $db, string $table): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
};

try {

/* Which optional edge-source tables are present on this install? (Any may be
   unmigrated.) Read only as edge sources, so absence is non-fatal. */
$suggTableExists      = $tableExists($db, 'tblSongLinkSuggestions');
$dismissedTableExists = $tableExists($db, 'tblSongLinkSuggestionsDismissed');
$extLinksTableExists  = $tableExists($db, 'tblSongExternalLinks');

/* Curator-dismissed pairs ("not the same hymn" / "reviewed, kept separate") — a
   candidate cluster is suppressed only when ALL of its internal pairs have been
   dismissed (#1219), so adding a new member later re-surfaces the group. Gated
   on ITS OWN table's existence — previously gated on tblSongLinkSuggestions
   (the wrong table), which is why the page blanked when only the newer
   Dismissed table was unmigrated (#1228). */
if ($dismissedTableExists) {
    $dr = $db->query('SELECT SongIdA, SongIdB FROM tblSongLinkSuggestionsDismissed');
    if ($dr) {
        while ($row = $dr->fetch_assoc()) {
            $dismissedPairs[(string)$row['SongIdA'] . '|' . (string)$row['SongIdB']] = true;
        }
        $dr->close();
    }
}

/* ---- 1. Cheap pass: one lightweight row per song. ---- */
$songs = [];
$res = $db->query(
    'SELECT s.SongId, s.Title, s.NormalizedTitle, s.SongbookAbbr, s.Number, s.Verified,
            s.Isrc, s.Iswc, s.Ccli,
            COALESCE(sb.IsOfficial, 0) AS IsOfficial, sb.Name AS SongbookName
       FROM tblSongs s
       LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
      WHERE ' . songVisibleSql($db, 's')
);   /* #1694 — a hidden song must not be offered for merge/link */
if ($res) {
    while ($row = $res->fetch_assoc()) { $songs[(string)$row['SongId']] = $row; }
    $res->close();
}

/* ---- Union-find over candidate edges. Only candidate members are touched. ---- */
$parent = [];
$find = static function (string $x) use (&$parent, &$find): string {
    if (!isset($parent[$x])) { $parent[$x] = $x; }
    if ($parent[$x] !== $x) { $parent[$x] = $find($parent[$x]); }
    return $parent[$x];
};
$union = static function (string $a, string $b) use (&$parent, $find): void {
    $ra = $find($a); $rb = $find($b);
    if ($ra !== $rb) { $parent[$ra] = $rb; }
};
/* Link every member of a group to the first member (turns an N-member class
   into one connected component without N² edges). */
$unionGroup = static function (array $ids) use ($union): void {
    $n = count($ids);
    for ($i = 1; $i < $n; $i++) { $union($ids[0], $ids[$i]); }
};

/* (a) Exact normalised-title classes. */
$byNorm = [];
foreach ($songs as $sid => $row) {
    $norm = (string)($row['NormalizedTitle'] ?? '');
    if ($norm === '') { $norm = ihymns_normalize_title((string)$row['Title']); }
    if ($norm === '') { continue; }
    $byNorm[$norm][] = $sid;
}
foreach ($byNorm as $ids) {
    if (count($ids) >= 2) { $unionGroup($ids); }
}

/* (b) Shared hard identifiers — Isrc / Iswc / Ccli. */
foreach (['Isrc', 'Iswc', 'Ccli'] as $col) {
    $byId = [];
    foreach ($songs as $sid => $row) {
        $v = trim((string)($row[$col] ?? ''));
        if ($v === '') { continue; }
        $byId[mb_strtolower($v, 'UTF-8')][] = $sid;
    }
    foreach ($byId as $ids) {
        if (count($ids) >= 2) { $unionGroup($ids); }
    }
}

/* (c) Shared external-link URL (same provider + Url across >1 song).
   Optional table (#833) — skipped when unmigrated (it would otherwise throw
   under MYSQLI_REPORT_STRICT). */
if ($extLinksTableExists) {
    $lr = $db->query(
        "SELECT GROUP_CONCAT(x.SongId) AS SongIds
           FROM tblSongExternalLinks x
          GROUP BY x.LinkTypeId, x.Url
         HAVING COUNT(DISTINCT x.SongId) > 1
          LIMIT 1000"
    );
    if ($lr) {
        while ($row = $lr->fetch_assoc()) {
            $ids = array_values(array_unique(array_filter(explode(',', (string)$row['SongIds']))));
            $ids = array_values(array_filter($ids, static fn($id) => isset($songs[$id])));
            if (count($ids) >= 2) { $unionGroup($ids); }
        }
        $lr->close();
    }
}

/* (d) Pre-scored fuzzy cross-book pairs (not dismissed, not already linked).
   The NOT EXISTS pre-filter joins the dismissed table, so it is dropped when
   that table is unmigrated (dismissal suppression then has nothing to filter,
   and $dismissedPairs is empty anyway). */
if ($suggTableExists) {
    $sr = $db->query(
        $dismissedTableExists
            ? 'SELECT s.SongIdA, s.SongIdB
                 FROM tblSongLinkSuggestions s
                WHERE NOT EXISTS (
                        SELECT 1 FROM tblSongLinkSuggestionsDismissed d
                         WHERE d.SongIdA = s.SongIdA AND d.SongIdB = s.SongIdB)
                LIMIT 5000'
            : 'SELECT s.SongIdA, s.SongIdB FROM tblSongLinkSuggestions s LIMIT 5000'
    );
    if ($sr) {
        while ($row = $sr->fetch_assoc()) {
            $a = (string)$row['SongIdA']; $b = (string)$row['SongIdB'];
            if (isset($songs[$a], $songs[$b])) { $union($a, $b); }
        }
        $sr->close();
    }
}

/* Collect cluster membership (components of size ≥ 2). */
$clusters = [];
foreach (array_keys($parent) as $sid) {
    $root = $find($sid);
    $clusters[$root][] = $sid;
}
$clusters = array_values(array_filter($clusters, static fn($m) => count($m) >= 2));

/* Candidate member set = everyone in a cluster. */
$candidateIds = [];
foreach ($clusters as $members) {
    foreach ($members as $sid) { $candidateIds[$sid] = true; }
}
$candidateIds = array_keys($candidateIds);

/* ---- 2. Heavy pass — first-line lyric + authors + lyrics count, CANDIDATES
 *         ONLY (never the whole corpus; rule #17). Chunked IN(). ---- */
$feat = [];        // sid => ['normTitle','normFirstLine','authors','isrc','iswc','ccli']
$lyricsCount = []; // sid => int
$groupOf = [];     // sid => existing tblSongLinks GroupId (0 = none)
/* #1235 P4 — the preview first-line comes from the authoritative tblLyricLines mirror
   (shared bulk reader, chunked), NOT tblSongComponents.LinesJson. Only an un-migrated
   install (no mirror table) falls back to the legacy correlated LinesJson subquery —
   keeps this page rendering on a fresh install (the #1228/#1229 lesson). */
$mirrorReady = lyricLinesMirrorPresent($db);
foreach (array_chunk($candidateIds, 200) as $chunk) {
    $ph    = implode(',', array_fill(0, count($chunk), '?'));
    $types = str_repeat('s', count($chunk));

    /* Mirror-ready: first lines come from the bulk map below, not the SQL. Un-migrated:
       a correlated first-component LinesJson subquery (lines-json-fallback, #1235 P4). */
    $firstLineBySid = $mirrorReady ? lyricLinesFirstLineMap($db, $chunk) : [];
    $firstLineCol   = $mirrorReady
        ? ''
        : ", (SELECT cmp.LinesJson FROM tblSongComponents cmp
              WHERE cmp.SongId = s.SongId ORDER BY cmp.SortOrder ASC LIMIT 1) AS FirstLines";

    $stmt = $db->prepare(
        "SELECT s.SongId,
                COALESCE(GROUP_CONCAT(DISTINCT w.Name SEPARATOR '|'), '') AS Writers,
                COALESCE(GROUP_CONCAT(DISTINCT c.Name SEPARATOR '|'), '') AS Composers,
                (SELECT COUNT(*) FROM tblLyrics l WHERE l.SongId = s.SongId) AS LyricsCount{$firstLineCol}
           FROM tblSongs s
           LEFT JOIN tblSongWriters   w ON w.SongId = s.SongId
           LEFT JOIN tblSongComposers c ON c.SongId = s.SongId
          WHERE s.SongId IN ($ph) AND " . songVisibleSql($db, 's') . "
          GROUP BY s.SongId"
    );   /* #1694 — belt-and-braces; the id chunks come from the filtered cheap pass */
    $stmt->bind_param($types, ...$chunk);
    $stmt->execute();
    $rr = $stmt->get_result();
    while ($row = $rr->fetch_assoc()) {
        $sid = (string)$row['SongId'];
        /* First non-empty line of the song = the strongest dedup signal. */
        if ($mirrorReady) {
            $firstLine = $firstLineBySid[$sid] ?? '';
        } else {
            /* lines-json-fallback (#1235 P4): decode the first component's LinesJson. */
            $firstLine = '';
            $linesJson = (string)($row['FirstLines'] ?? '');
            if ($linesJson !== '') {
                $lines = json_decode($linesJson, true);
                if (is_array($lines)) {
                    foreach ($lines as $ln) {
                        $ln = trim((string)$ln);
                        if ($ln !== '') { $firstLine = $ln; break; }
                    }
                }
            }
        }
        $s = $songs[$sid] ?? [];
        $feat[$sid] = [
            'normTitle'     => ihymns_sim_normalise((string)($s['Title'] ?? '')),
            'normFirstLine' => ihymns_sim_normalise($firstLine),
            'authors'       => trim((string)$row['Writers'] . '|' . (string)$row['Composers'], '|'),
            'isrc'          => trim((string)($s['Isrc'] ?? '')),
            'iswc'          => trim((string)($s['Iswc'] ?? '')),
            'ccli'          => trim((string)($s['Ccli'] ?? '')),
        ];
        $lyricsCount[$sid] = (int)$row['LyricsCount'];
    }
    $stmt->close();

    /* Existing counterpart-group membership (so we can skip already-linked sets). */
    $gstmt = $db->prepare("SELECT SongId, GroupId FROM tblSongLinks WHERE SongId IN ($ph)");
    $gstmt->bind_param($types, ...$chunk);
    $gstmt->execute();
    $gr = $gstmt->get_result();
    while ($row = $gr->fetch_assoc()) { $groupOf[(string)$row['SongId']] = (int)$row['GroupId']; }
    $gstmt->close();
}

/* ---- 3. Assemble + score each cluster; bucket by section. ---- */
/* Section buckets: 'cross' (§1), 'official' (§2), 'other' (§3). */
$sectioned = ['cross' => [], 'official' => [], 'other' => []];

foreach ($clusters as $members) {
    /* Sort members for stable display (songbook, then number). */
    usort($members, static function ($a, $b) use ($songs) {
        $ba = (string)($songs[$a]['SongbookAbbr'] ?? '');
        $bb = (string)($songs[$b]['SongbookAbbr'] ?? '');
        if ($ba !== $bb) { return strcmp($ba, $bb); }
        return ((int)($songs[$a]['Number'] ?? 0)) <=> ((int)($songs[$b]['Number'] ?? 0));
    });

    /* Skip clusters whose members are ALL already in one counterpart group —
       they're linked; re-proposing them is noise. */
    $groups = array_unique(array_map(static fn($sid) => $groupOf[$sid] ?? 0, $members));
    if (count($groups) === 1 && (int)reset($groups) > 0) { continue; }

    /* Best pairwise score across the cluster + the set of signals fired. Also
       track whether EVERY internal pair has been dismissed → suppress it. */
    $best = ['score' => 0.0, 'title' => 0.0, 'lyrics' => 0.0, 'authors' => 0.0, 'signal' => 'fuzzy', 'confidence' => 'low'];
    $signalsFired = [];
    $allDismissed = true;
    $n = count($members);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $s = ihymns_sim_score($feat[$members[$i]] ?? [], $feat[$members[$j]] ?? []);
            if ($s['signal'] !== 'fuzzy') { $signalsFired[$s['signal']] = true; }
            if ($s['score'] > $best['score']) { $best = $s; }
            $pa = $members[$i]; $pb = $members[$j];
            if ($pa > $pb) { [$pa, $pb] = [$pb, $pa]; }
            if (!isset($dismissedPairs[$pa . '|' . $pb])) { $allDismissed = false; }
        }
    }
    if ($allDismissed) { continue; }

    /* Book span + per-official-book collision detection. */
    $bookCount = [];
    foreach ($members as $sid) {
        $abbr = (string)($songs[$sid]['SongbookAbbr'] ?? '');
        $bookCount[$abbr] = ($bookCount[$abbr] ?? 0) + 1;
    }
    $bookSpan = count($bookCount);

    /* A member is "distinct?" when another member shares its OFFICIAL songbook
       (same title, same published hymnal, different number → almost always a
       different hymn). */
    $distinct = [];
    foreach ($members as $sid) {
        $abbr = (string)($songs[$sid]['SongbookAbbr'] ?? '');
        $isOfficial = (int)($songs[$sid]['IsOfficial'] ?? 0) === 1;
        $distinct[$sid] = ($isOfficial && ($bookCount[$abbr] ?? 0) >= 2);
    }
    $hasOfficialCollision = in_array(true, $distinct, true);

    if ($bookSpan >= 2) {
        $section = 'cross';
    } else {
        /* Single songbook — official → likely distinct (§2); else import dupe (§3). */
        $onlyOfficial = (int)($songs[$members[0]]['IsOfficial'] ?? 0) === 1;
        $section = $onlyOfficial ? 'official' : 'other';
    }

    $sectioned[$section][] = [
        'members'  => $members,
        'best'     => $best,
        'signals'  => array_keys($signalsFired),
        'distinct' => $distinct,
        'hasOfficialCollision' => $hasOfficialCollision,
    ];
}

/* Sort each section by descending confidence so the strongest candidates lead. */
foreach ($sectioned as &$bucket) {
    usort($bucket, static fn($a, $b) => $b['best']['score'] <=> $a['best']['score']);
}
unset($bucket);

$totalClusters = count($sectioned['cross']) + count($sectioned['official']) + count($sectioned['other']);

} catch (\Throwable $e) {
    /* Degrade gracefully instead of white-screening — see the note above the
       `try`. The most common cause is a feature migration (#1215 / #1064) not
       yet applied on this environment; the render surfaces an actionable card
       pointing at /manage/setup-database (#1228). */
    $detectError   = $e->getMessage();
    $sectioned     = ['cross' => [], 'official' => [], 'other' => []];
    $totalClusters = 0;
    error_log('[duplicate-songs] detection failed: ' . $e->getMessage());
}

/* ---- Render helpers ---- */
$songLabel = static function (array $s): string {
    $num = ($s['Number'] !== null && $s['Number'] !== '') ? (' #' . (int)$s['Number']) : '';
    return htmlspecialchars((string)$s['SongId'] . ' · ' . (string)$s['Title']
        . ' [' . (string)$s['SongbookAbbr'] . $num . ']', ENT_QUOTES);
};

/* Confidence badge colour by tier. */
$confClass = static fn(string $c): string => match ($c) {
    'high'   => 'bg-danger',
    'medium' => 'bg-warning text-dark',
    default  => 'bg-secondary',
};

/* Signal chips: hard-ids + the fuzzy sub-scores that meaningfully fired. */
$signalChips = static function (array $best, array $signals): string {
    $chips = [];
    $map = ['shared-iswc' => 'ISWC', 'shared-ccli' => 'CCLI', 'shared-isrc' => 'ISRC'];
    foreach ($signals as $sig) {
        if (isset($map[$sig])) {
            $chips[] = '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle me-1">'
                     . 'shared ' . $map[$sig] . '</span>';
        }
    }
    if ($best['title']   >= 0.85) { $chips[] = '<span class="badge bg-body-secondary me-1">title</span>'; }
    if ($best['lyrics']  >= 0.80) { $chips[] = '<span class="badge bg-body-secondary me-1">first line</span>'; }
    if ($best['authors'] >  0.0)  { $chips[] = '<span class="badge bg-body-secondary me-1">shared writer</span>'; }
    return implode('', $chips);
};

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Duplicates — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="mb-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-git-compare me-2"></i>Find Duplicates</h1>
            <p class="text-secondary small mb-0" style="max-width:60rem;">
                Songs that might be the same are grouped here, matched on their title, first line and
                writers, plus any shared music-industry code. <strong>Link</strong> the same hymn when it
                appears in more than one songbook; <strong>Merge</strong> two records that are truly the
                same into one (this cannot be undone); <strong>Dismiss</strong> a group that is not really a
                match. Two songs with the same title inside one published songbook are usually different
                hymns, so they are listed separately for a careful look.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($canMerge): ?>
            <button type="button" class="btn btn-sm btn-outline-success dup-autolink-btn"
                    title="Link cross-book songs that share an exact ISWC / CCLI / ISRC code as counterparts. Respects dismissals and existing links; never merges.">
                <i class="bi bi-magic me-1"></i>Auto-link by shared ID
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-primary dup-rebuild-btn"
                    title="Re-run the fuzzy similarity engine across the whole catalogue">
                <i class="bi bi-arrow-clockwise me-1"></i>Rebuild suggestions
            </button>
        </div>
    </div>

    <?php if ($detectError !== null): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-octagon me-1"></i>
            <strong>Duplicate detection couldn't run.</strong>
            This usually means a database migration for this feature hasn't been applied on this
            environment yet. Open <a href="/manage/setup-database" class="alert-link">Setup&nbsp;Database</a>,
            apply any pending migrations, then reload this page.
            <div class="small text-muted mt-2">Detail: <code><?= htmlspecialchars($detectError, ENT_QUOTES) ?></code></div>
        </div>
    <?php elseif ($totalClusters === 0): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>No potential duplicates or counterparts detected.</div>
    <?php endif; ?>

    <?php
    /* ---- Unified cluster renderer. ---- */
    $renderCluster = function (array $cl, string $section) use ($songs, $songLabel, $confClass, $signalChips, $lyricsCount, $canMerge, $groupOf): void {
        $members = $cl['members'];
        $best    = $cl['best'];
        $pct     = (int)round($best['score'] * 100);
        $isOfficialSection = ($section === 'official');

        echo '<div class="card mb-2 dup-cluster" data-section="' . htmlspecialchars($section, ENT_QUOTES) . '">';
        echo '<div class="card-body py-2">';

        /* Header: confidence + signals (+ the §2 warning). */
        echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-2">';
        echo '<span class="badge ' . $confClass($best['confidence']) . '" title="Composite similarity">'
           . $pct . '% · ' . htmlspecialchars($best['confidence'], ENT_QUOTES) . '</span>';
        echo '<span class="small">' . $signalChips($best, $cl['signals']) . '</span>';
        if ($isOfficialSection) {
            echo '<span class="text-warning-emphasis small ms-auto">'
               . '<i class="bi bi-exclamation-triangle me-1"></i>Same title within one published hymnal — '
               . 'likely <strong>different hymns</strong>. Review before merging.</span>';
        }
        echo '</div>';

        echo '<form class="dup-form">';
        echo '<table class="table table-sm mb-2 admin-table-responsive"><thead><tr>';
        if ($canMerge) { echo '<th title="Survivor (the song to keep on merge)">Keep</th>'; }
        echo '<th title="Include in the action">Pick</th>';
        echo '<th data-col-priority="primary">Song</th>';
        echo '<th data-col-priority="secondary">Verified</th>';
        echo '<th data-col-priority="tertiary">Lyrics</th>';
        echo '<th data-col-priority="secondary">Codes</th>';
        echo '</tr></thead><tbody>';

        $first = true;
        foreach ($members as $sid) {
            $s = $songs[$sid];
            $isDistinct = !empty($cl['distinct'][$sid]);
            $abbr = (string)$s['SongbookAbbr'];
            $official = (int)($s['IsOfficial'] ?? 0) === 1;
            /* Default "Pick" = checked, except a same-official-book "distinct?" row. */
            $checked = $isDistinct ? '' : ' checked';

            /* #1150/#1151 — the "Keep"/"Pick" <th> headers give these columns a
               VISUAL label, but a <th> is not an accessible NAME source for a
               form control (that needs a <label>, aria-label or aria-labelledby
               — WCAG 4.1.2/3.3.2). Without one a screen-reader user tabbing
               through the table hears only "radio button" / "checkbox, checked"
               with no indication of which of the several songs in this cluster
               it refers to. $songLabel() is already htmlspecialchars()-escaped
               for HTML body use, which is equally safe to drop into a
               double-quoted attribute value. */
            $ariaSongLabel = $songLabel($s);
            echo '<tr' . ($isDistinct ? ' class="table-warning"' : '') . '>';
            if ($canMerge) {
                echo '<td><input type="radio" name="survivor" value="' . htmlspecialchars($sid, ENT_QUOTES) . '"' . ($first ? ' checked' : '') . ' aria-label="Keep ' . $ariaSongLabel . ' as the survivor"></td>';
            }
            echo '<td><input type="checkbox" class="dup-pick" value="' . htmlspecialchars($sid, ENT_QUOTES) . '" data-book="' . htmlspecialchars($abbr, ENT_QUOTES) . '" data-official="' . ($official ? '1' : '0') . '"' . $checked . ' aria-label="Include ' . $ariaSongLabel . ' in this action"></td>';
            echo '<td data-col-priority="primary"><a href="/manage/editor/?song=' . urlencode($sid) . '" target="_blank" rel="noopener">' . $songLabel($s) . '</a>';
            if ($isDistinct) {
                echo ' <span class="badge bg-warning text-dark" title="Another song shares this title in the same official songbook — probably a different hymn">distinct?</span>';
            }
            /* #1629 — this song is already a member of an existing
               tblSongLinks group. Surface it + offer Unlink right here,
               since that existing membership is exactly what makes Link
               refuse to combine this cluster when another member sits in
               a DIFFERENT group (the 409 above). */
            $existingGroup = $groupOf[$sid] ?? 0;
            if ($existingGroup > 0) {
                echo ' <span class="badge bg-info-subtle text-info-emphasis" title="Already linked as a counterpart, group #' . $existingGroup . '">linked</span>'
                   . ' <button type="button" class="btn btn-sm btn-outline-secondary dup-unlink-btn py-0 px-1" '
                   . 'data-song-id="' . htmlspecialchars($sid, ENT_QUOTES) . '" '
                   . 'title="Remove this song from counterpart group #' . $existingGroup . ' so it can join a different one">'
                   . '<i class="bi bi-x-circle"></i> Unlink</button>';
            }
            echo '</td>';
            echo '<td data-col-priority="secondary">' . ((int)$s['Verified'] === 1 ? '<span class="badge bg-success">verified</span>' : '<span class="badge bg-warning text-dark">unverified</span>') . '</td>';
            echo '<td data-col-priority="tertiary">' . (int)($lyricsCount[$sid] ?? 0) . '</td>';
            $codes = array_filter([
                trim((string)($s['Iswc'] ?? '')) !== '' ? 'ISWC' : '',
                trim((string)($s['Ccli'] ?? '')) !== '' ? 'CCLI' : '',
                trim((string)($s['Isrc'] ?? '')) !== '' ? 'ISRC' : '',
            ]);
            echo '<td data-col-priority="secondary"><span class="small text-secondary">' . htmlspecialchars(implode(' ', $codes), ENT_QUOTES) . '</span></td>';
            echo '</tr>';
            $first = false;
        }
        echo '</tbody></table>';

        /* Action row. Link = cross-book only (curator). Merge: one-click for
           §1/§3 (admin); §2 (same official songbook) needs a type-to-confirm
           (#1218). Dismiss = every section (curator). */
        echo '<div class="d-flex flex-wrap gap-2 align-items-center">';

        if ($section === 'cross') {
            echo '<button type="button" class="btn btn-sm btn-outline-success dup-link-btn" '
               . 'title="Link the ticked songs as cross-book counterparts (tblSongLinks)">'
               . '<i class="bi bi-link-45deg me-1"></i>Link picked as counterparts</button>';
        }

        if ($canMerge && !$isOfficialSection) {
            echo '<button type="button" class="btn btn-sm btn-outline-danger dup-merge-btn">'
               . '<i class="bi bi-union me-1"></i>Merge picked into kept</button>';
        } elseif ($canMerge && $isOfficialSection) {
            /* Guarded merge (#1218): type the kept song-id to arm the button;
               the request then carries force=1 to clear the server block. */
            echo '<span class="small text-secondary">If these really are one hymn, type the kept song-id to enable merge:</span>';
            echo '<input type="text" class="form-control form-control-sm dup-confirm-input" style="max-width:11rem" '
               . 'placeholder="e.g. ' . htmlspecialchars((string)$members[0], ENT_QUOTES) . '" autocomplete="off" spellcheck="false" aria-label="Type the kept song id to confirm merge">';
            echo '<button type="button" class="btn btn-sm btn-outline-danger dup-merge-btn" data-force="1" disabled>'
               . '<i class="bi bi-union me-1"></i>Merge picked into kept</button>';
        }

        echo '<button type="button" class="btn btn-sm btn-outline-secondary dup-dismiss-btn ms-auto" '
           . 'title="Mark reviewed — not the same hymn; stop surfacing this group">'
           . '<i class="bi bi-x-lg me-1"></i>Dismiss</button>';

        echo '</div>';

        echo '</form></div></div>';
    };

    /* §1 — Cross-book counterparts. */
    if (!empty($sectioned['cross'])) {
        echo '<h2 class="h5 mt-4"><i class="bi bi-link-45deg me-1"></i>Cross-book counterparts (' . count($sectioned['cross']) . ')</h2>';
        echo '<p class="text-secondary small mb-2">The same hymn appearing in different songbooks — usually you want to <strong>Link</strong> these, not merge.</p>';
        foreach ($sectioned['cross'] as $cl) { $renderCluster($cl, 'cross'); }
    }
    /* §2 — Same title, one official songbook. */
    if (!empty($sectioned['official'])) {
        echo '<h2 class="h5 mt-4"><i class="bi bi-exclamation-triangle me-1"></i>Same title, one official songbook (' . count($sectioned['official']) . ')</h2>';
        echo '<p class="text-secondary small mb-2">A published hymnal rarely lists one song twice — these are almost certainly <strong>different hymns sharing a title</strong>. Listed for review; merge is guarded.</p>';
        foreach ($sectioned['official'] as $cl) { $renderCluster($cl, 'official'); }
    }
    /* §3 — Same title, non-official collection. */
    if (!empty($sectioned['other'])) {
        echo '<h2 class="h5 mt-4"><i class="bi bi-collection me-1"></i>Same title, one collection (' . count($sectioned['other']) . ')</h2>';
        echo '<p class="text-secondary small mb-2">Same title within an unstructured collection (Misc / curated grouping) — often a genuine import duplicate.</p>';
        foreach ($sectioned['other'] as $cl) { $renderCluster($cl, 'other'); }
    }
    ?>
</main>

<script>
(function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    function toast(msg, ok) { if (window.showToast) { window.showToast(msg, ok ? 'success' : 'error'); } else { alert(msg); } }

    /* Merge: keep the survivor (radio), merge every PICKED other member into it.
       §2 buttons carry data-force="1" to clear the same-official-songbook guard. */
    document.querySelectorAll('.dup-merge-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('.dup-form');
            var force = btn.dataset.force === '1';
            var keep = form.querySelector('input[name="survivor"]:checked');
            if (!keep) { toast('Choose which song to keep.', false); return; }
            var survivor = keep.value;
            var dups = Array.prototype.map.call(form.querySelectorAll('.dup-pick:checked'), function (c) { return c.value; })
                .filter(function (id) { return id !== survivor; });
            if (!dups.length) { toast('Tick at least one other song to merge in.', false); return; }
            if (!confirm('Merge ' + dups.length + ' song(s) into ' + survivor + '? This re-points all references and deletes the other song(s). This cannot be undone.')) { return; }
            btn.disabled = true;
            var chain = Promise.resolve();
            dups.forEach(function (dup) {
                chain = chain.then(function () {
                    var params = { action: 'merge', survivor_id: survivor, duplicate_id: dup, csrf_token: CSRF };
                    if (force) { params.force = '1'; }
                    var body = new URLSearchParams(params);
                    return fetch('/manage/duplicate-songs', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.success) { throw new Error(j.error || 'Merge failed'); } }); });
                });
            });
            chain.then(function () {
                toast('Merged into ' + survivor + '.', true);
                var card = btn.closest('.dup-cluster');
                if (card) { card.remove(); }
            }).catch(function (e) { btn.disabled = false; toast(e.message || 'Merge failed.', false); });
        });
    });

    /* §2 type-to-confirm: arm the guarded merge button only while the typed
       value matches the selected survivor's song-id (case-insensitive). */
    function syncConfirm(form) {
        var input = form.querySelector('.dup-confirm-input');
        var btn   = form.querySelector('.dup-merge-btn[data-force="1"]');
        if (!input || !btn) { return; }
        var keep = form.querySelector('input[name="survivor"]:checked');
        var want = keep ? keep.value.trim().toLowerCase() : '';
        btn.disabled = !(want && input.value.trim().toLowerCase() === want);
    }
    document.querySelectorAll('.dup-form').forEach(function (form) {
        var input = form.querySelector('.dup-confirm-input');
        if (!input) { return; }
        input.addEventListener('input', function () { syncConfirm(form); });
        form.querySelectorAll('input[name="survivor"]').forEach(function (r) {
            r.addEventListener('change', function () { syncConfirm(form); });
        });
    });

    /* Shared POST helper → JSON, throwing on any non-success. */
    function postAction(params) {
        params.csrf_token = CSRF;
        return fetch('/manage/duplicate-songs', { method: 'POST', body: new URLSearchParams(params), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.success) { throw new Error(j.error || 'Action failed'); } return j; }); });
    }

    /* Link the PICKED members as cross-book counterparts (one tblSongLinks group). */
    document.querySelectorAll('.dup-link-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('.dup-form');
            var ids = Array.prototype.map.call(form.querySelectorAll('.dup-pick:checked'), function (c) { return c.value; });
            if (ids.length < 2) { toast('Tick at least two songs to link as counterparts.', false); return; }
            btn.disabled = true;
            postAction({ action: 'link', song_ids: ids.join(',') }).then(function () {
                toast('Linked ' + ids.length + ' songs as counterparts.', true);
                var card = btn.closest('.dup-cluster'); if (card) { card.remove(); }
            }).catch(function (e) { btn.disabled = false; toast(e.message || 'Link failed.', false); });
        });
    });

    /* Unlink ONE song from its existing counterpart group (#1629) — the
       button next to a row already tagged "linked". This is what the
       409 error above (from .dup-link-btn) now points curators at,
       replacing the dead-end "in the editor" message. Reloads on
       success rather than removing just the card, because freeing a
       song from its old group can change which OTHER clusters are
       eligible to link (a cluster is filtered out server-side only
       while ALL its members share one group — see the $groups check
       above the render pass) — a full reload keeps that in sync rather
       than the page silently going stale. */
    document.querySelectorAll('.dup-unlink-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var songId = btn.dataset.songId;
            if (!songId) return;
            if (!confirm('Remove ' + songId + ' from its existing counterpart group?')) return;
            btn.disabled = true;
            postAction({ action: 'unlink', song_id: songId }).then(function () {
                toast('Unlinked ' + songId + '. Reloading…', true);
                setTimeout(function () { location.reload(); }, 700);
            }).catch(function (e) { btn.disabled = false; toast(e.message || 'Unlink failed.', false); });
        });
    });

    /* Dismiss the WHOLE group (every member) as reviewed / not-the-same-hymn. */
    document.querySelectorAll('.dup-dismiss-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('.dup-form');
            var ids = Array.prototype.map.call(form.querySelectorAll('.dup-pick'), function (c) { return c.value; });
            if (ids.length < 2) { return; }
            if (!confirm('Dismiss this group as reviewed (not the same hymn)? It will stop appearing here.')) { return; }
            btn.disabled = true;
            postAction({ action: 'dismiss', song_ids: ids.join(',') }).then(function () {
                toast('Dismissed.', true);
                var card = btn.closest('.dup-cluster'); if (card) { card.remove(); }
            }).catch(function (e) { btn.disabled = false; toast(e.message || 'Dismiss failed.', false); });
        });
    });

    /* Rebuild the fuzzy suggestion table, then reload to show fresh candidates. */
    var rb = document.querySelector('.dup-rebuild-btn');
    if (rb) {
        rb.addEventListener('click', function () {
            var orig = rb.innerHTML;
            rb.disabled = true; rb.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Rebuilding…';
            postAction({ action: 'rebuild' }).then(function (j) {
                toast('Rebuilt: ' + (j.message || 'done') + '. Reloading…', true);
                setTimeout(function () { location.reload(); }, 900);
            }).catch(function (e) { rb.disabled = false; rb.innerHTML = orig; toast(e.message || 'Rebuild failed.', false); });
        });
    }

    /* Auto-link cross-book songs sharing an exact hard id (#1125), then reload. */
    var al = document.querySelector('.dup-autolink-btn');
    if (al) {
        al.addEventListener('click', function () {
            if (!confirm('Auto-link all cross-book songs that share an exact ISWC / CCLI / ISRC code as counterparts? This creates link groups (reversible). Dismissed pairs, same-songbook collisions and existing links are respected; nothing is merged.')) { return; }
            var orig = al.innerHTML;
            al.disabled = true; al.innerHTML = '<i class="bi bi-magic me-1"></i>Linking…';
            postAction({ action: 'auto_link' }).then(function (j) {
                var msg = 'Auto-linked ' + (j.songsLinked || 0) + ' song(s) into ' + (j.groupsCreated || 0) + ' new group(s)';
                if (j.joined) { msg += ', ' + j.joined + ' joined existing'; }
                if (j.skippedConflict) { msg += '; ' + j.skippedConflict + ' conflict(s) skipped'; }
                if (j.skippedDismissed) { msg += '; ' + j.skippedDismissed + ' dismissed'; }
                toast(msg + '. Reloading…', true);
                setTimeout(function () { location.reload(); }, 1200);
            }).catch(function (e) { al.disabled = false; al.innerHTML = orig; toast(e.message || 'Auto-link failed.', false); });
        });
    }
})();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
