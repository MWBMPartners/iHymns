<?php

declare(strict_types=1);

/**
 * ============================================================================
 * iHymns Song Editor — Shared whole-song SAVE core (save_song)
 * ============================================================================
 *
 * The single, authoritative implementation of the Song Editor's whole-song
 * save (the legacy `save_song` action, #394). Extracted VERBATIM from the
 * legacy editor api.php `case 'save_song':` so BOTH the legacy api.php and the
 * v2 api2.php call the SAME code — no forked save logic, no drift, no
 * divergence in the project's PRIMARY SAVE PATH (CLAUDE.md modularity rule).
 *
 * This is a pure-ish extraction: the SQL, transaction, component/credit/
 * revision/ISWC-works write logic is byte-for-byte the original. The ONLY
 * change is response EMISSION — every place the original case did
 *   `http_response_code(N); echo json_encode(X); break;`
 * (or the success `echo`) now RETURNS `['status' => N, 'body' => X]` so each
 * caller can emit the response in its own house style (legacy api.php echoes
 * directly; api2.php routes it through ed2_respond()).
 *
 * Behaviour-preserving: the upstream callers must already have established
 * authentication + the editor role (both api.php and api2.php gate this above
 * the dispatch), read no request state this function doesn't read itself
 * (it reads php://input + $_SERVER directly, exactly as the original did),
 * and the returned status/body is identical to what the original case emitted.
 *
 * @return array{status:int, body:array} HTTP status + JSON-encodable body.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 * @license Proprietary — All rights reserved
 * @requires PHP 8.1+ with mysqli extension
 * ============================================================================
 */

/* Places adoption helper — exposes placeColumnExists() so the save persists
   OriginCityId alongside the legacy OriginCity display string only when the
   places-adoption migration has landed. require_once is idempotent: the legacy
   api.php also loads this at module scope; loading it here makes the core
   self-sufficient when called from api2.php (which does NOT pull places.php). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'places.php';
/* #1679 — songRelocate() + the two cross-cutting predicates this file needs
   EVERYWHERE, not just in the move branch:
     songRelocateIsTransactionFatal() — the ONE list of MySQL errors that have
       already rolled the caller's transaction back. Every best-effort
       `catch (\Throwable)` between begin_transaction() and commit() below opens
       with it (A1), which is why it can no longer be a lazy require inside the
       move branch;
     SongRelocateEnvironmentException — recognised by TYPE in the failure handler
       so an un-applied-migration refusal actually reaches the curator (A8).
   require_once is idempotent; the move branch keeps its own local require as a
   statement of intent. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_relocate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* #1694 — songVisibleSql() for the SongCount recomputes */

/**
 * Cached check for the tblSongArtists table (#587). The table arrives
 * via migrate-song-artists.php; until that migration has been
 * applied, the save_song path needs to skip both the DELETE and the
 * INSERT for artists rather than 500ing on a partly-migrated install.
 * Static cache so the INFORMATION_SCHEMA round-trip happens once per
 * request even when save_song runs in a loop.
 *
 * MOVED VERBATIM from the legacy editor api.php (where it was a free function
 * used ONLY by the save_song case) so this shared core is self-contained for
 * api2.php too. function_exists guard prevents a redeclaration collision if a
 * caller already defines it.
 */
if (!function_exists('_songArtistsTableExists')) {
    function _songArtistsTableExists(\mysqli $db): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongArtists' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $cached;
    }
}

/**
 * Cached check for the tblSongTranslations table (#352 / #1626).
 *
 * ELI5: "does the translations table exist here yet?" — asked once per request.
 *
 * Same partly-migrated tolerance as _songArtistsTableExists() above, and for the
 * same reason: the three docroots share ONE MySQL and migrations are web-run,
 * never auto-applied on deploy, so "it is in schema.sql" does NOT mean "it exists
 * on this env". mysqli runs under MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
 * (includes/db_mysql.php), so touching a missing table THROWS rather than
 * returning false — an unguarded write here would 500 the whole save (the #1228
 * lesson). Probing first degrades to "skip the translation links" instead.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/information-schema-tables-table.html
 */
if (!function_exists('_songTranslationsTableExists')) {
    function _songTranslationsTableExists(\mysqli $db): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongTranslations' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $cached;
    }
}

if (!function_exists('editorSaveSongCore')) {
/**
 * Run the whole-song save and return the HTTP status + JSON body the caller
 * should emit. See the file doc-block: the body is the verbatim legacy
 * `case 'save_song':` with response points converted to returns.
 *
 * @return array{status:int, body:array}
 */
function editorSaveSongCore(): array
{
        /* Behaviour-preserving init: in the original case body, $action was the
           module-level $_GET['action'] ('save_song') until the transaction block
           reassigned it to 'create'/'edit'. That global isn't in scope inside this
           function, so seed the SAME initial value — this only matters if the
           transaction throws BEFORE the create/edit decision below, in which case
           the catch's logActivityError() row records 'save_song', exactly as the
           inline case did (avoids an "undefined variable" warning + a null audit
           field on a pre-decision failure). It is overwritten with 'create'/'edit'
           inside the try, untouched on the happy path. */
        $action = 'save_song';

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['status' => 405, 'body' => ['error' => 'POST method required.']];
        }

        $rawBody = file_get_contents('php://input');
        $song    = json_decode($rawBody ?: '', true);
        if (!is_array($song) || empty($song['id']) || empty($song['title'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing required fields: id, title.']];
        }

        $songId = (string)$song['id'];
        /* #1679 M2 — was the songbook ACTUALLY sent? Kept as its own flag,
           read from the RAW payload, because $songbookAbbr below is defaulted a
           line later and can never afterwards be distinguished from a real
           value. The relocate branch keys off this, not off the defaulted
           string: a partial save that simply omits the key must not be read as
           "move this song to Misc" — which, since the move became a re-key, is
           a NEW id, a cleared Number and a permanent redirect row. */
        $songbookSent = array_key_exists('songbook', $song)
                     && trim((string)($song['songbook'] ?? '')) !== '';
        $songbookAbbr = trim((string)($song['songbook'] ?? ''));
        /* Every song MUST belong to a songbook — tblSongs.SongbookAbbr is
           NOT NULL with an FK to tblSongbooks. When a save arrives with no
           songbook, default to the canonical generic "Misc" collection
           rather than failing the INSERT on the constraint (or, on a
           pre-FK install, silently creating an orphan with a blank
           songbook). Misc is a seeded songbook whose Number is nullable,
           so an unnumbered Misc song is valid.

           #1679 M2 — but "Misc" is the right default only for a song that does
           not exist yet. For an EXISTING song the payload's silence means "I am
           not saying anything about the songbook", and the UPSERT below writes
           SongbookAbbr unconditionally: defaulting to Misc there would relabel
           the column while the SongId kept the old book's prefix, i.e. recreate
           by omission the exact id/column mismatch #1679 exists to remove.
           So an existing song's own book is the default, and Misc is reached
           only when there is genuinely no row to ask. Read here (before the
           IsOfficial probe) so $isOfficialSongbook and $number are derived from
           the same book the UPSERT will write. */
        if ($songbookAbbr === '') {
            /* @deleted-visible: write-path state read (#1694) — saving into a
               hidden row is harmless and restore-preserving; its own book must
               still be found or the save would silently relabel it Misc. */
            $keep = getDbMysqli()->prepare('SELECT SongbookAbbr FROM tblSongs WHERE SongId = ? LIMIT 1');
            $keep->bind_param('s', $songId);
            $keep->execute();
            $keepRow = $keep->get_result()->fetch_assoc();
            $keep->close();
            $songbookAbbr = trim((string)($keepRow['SongbookAbbr'] ?? ''));
        }
        if ($songbookAbbr === '') {
            $songbookAbbr = 'Misc';
        }

        /* Probe tblSongbooks for IsOfficial so songs in unofficial
           songbooks (Misc, custom collections) can persist Number as
           NULL while songs in official songbooks keep the per-songbook
           number. The probe happens here (outside the transaction
           below) because the songbook row is read-only context. #392. */
        $isOfficialSongbook = false;
        if ($songbookAbbr !== '') {
            $probe = getDbMysqli()->prepare(
                'SELECT IsOfficial FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1'
            );
            $probe->bind_param('s', $songbookAbbr);
            $probe->execute();
            $row = $probe->get_result()->fetch_assoc();
            $probe->close();
            $isOfficialSongbook = (bool)($row['IsOfficial'] ?? false);
        }

        /* Songs in non-official songbooks (Misc, custom collections)
           persist Number as NULL — and so does anything missing or
           non-positive. The internal SongId is the cross-record link
           in that case. #392. */
        $rawNumber = $song['number'] ?? null;
        $number    = (!$isOfficialSongbook || $rawNumber === null || $rawNumber === '' || (int)$rawNumber <= 0)
            ? null
            : (int)$rawNumber;

        $title        = (string)$song['title'];
        $songbookName = (string)($song['songbookName'] ?? '');
        /* IETF BCP 47 validation (#681). Empty string normalises to
           'en' for tblSongs.Language NOT NULL DEFAULT 'en'; a malformed
           tag (variants, extensions, anything past the v1 grammar) is
           rejected up-front so the rest of the save runs against a
           well-formed value. */
        $rawLang = (string)($song['language'] ?? 'en');
        $valid   = _ietfBcp47Validate($rawLang);
        if ($valid === false) {
            return ['status' => 400, 'body' => ['error' => 'Invalid IETF BCP 47 language tag: ' . $rawLang]];
        }
        $language     = $valid ?? 'en';
        $copyright    = (string)($song['copyright']   ?? '');
        /* Places adoption — composition / first-performance origin.
           VARCHAR mirror persists either way; the FK is set only
           when the curator picked a candidate from the live
           autocomplete (the editor.js wiring keeps both halves in
           sync). Schema-tolerant: the per-place UPDATE below
           skips itself on pre-adoption-migration installs. */
        $originCityRaw = trim((string)($song['originCity'] ?? ''));
        $originCity    = $originCityRaw === '' ? null : $originCityRaw;
        $originCityId  = (int)($song['originCityId'] ?? 0) ?: null;
        /* TuneName + Iswc are nullable (#497). Empty/whitespace-only
           input normalises to NULL so the indexed TuneName column
           groups "unknown" rows together. */
        $tuneRaw      = trim((string)($song['tuneName'] ?? ''));
        $tuneName     = $tuneRaw === '' ? null : $tuneRaw;
        $ccli         = (string)($song['ccli']        ?? '');
        $iswcRaw      = trim((string)($song['iswc']   ?? ''));
        $iswc         = $iswcRaw === '' ? null : $iswcRaw;
        $verified     = (int)($song['verified']           ?? 0);
        $lyricsPD     = (int)($song['lyricsPublicDomain'] ?? 0);
        $musicPD      = (int)($song['musicPublicDomain']  ?? 0);
        $hasAudio     = (int)($song['hasAudio']           ?? 0);
        $hasSheet     = (int)($song['hasSheetMusic']      ?? 0);

        /* Build lyrics_text for FULLTEXT index */
        $lyricsLines = [];
        foreach ($song['components'] ?? [] as $comp) {
            foreach ($comp['lines'] ?? [] as $line) {
                $lyricsLines[] = $line;
            }
        }
        $lyricsText = implode("\n", $lyricsLines);

        /* #892 — sanitise the arrangement payload before the txn so any
           validation rejection 400s the caller cleanly. The editor sends
           an int[] of indices into components[] (repetition allowed —
           the entire reason this column exists). NULL / empty / invalid
           → store NULL so the public render falls back to plain
           SortOrder (current pre-#892 behaviour). */
        /* Data-integrity guard (#1178): collapse EXACT-duplicate components
           (same type + number + lines) to a single instance, keeping the first.
           A client-side accumulation bug could otherwise persist the same blank
           "Verse 1" many times (observed on a new Misc song). Only EXACT repeats
           collapse, so a single in-progress blank component is preserved; the
           arrangement (int[] indices into components) is remapped through the
           dedup so its integrity survives. */
        if (is_array($song['components'] ?? null) && count($song['components']) > 1) {
            $seenComp = [];
            $keptComp = [];
            $idxRemap = [];   /* old component index → new index */
            foreach ($song['components'] as $oldIdx => $comp) {
                $cType  = (string)($comp['type'] ?? 'verse');
                $cNum   = isset($comp['number']) ? (int)$comp['number'] : 0;
                $cLines = is_array($comp['lines'] ?? null) ? $comp['lines'] : [];
                $sig    = $cType . '|' . $cNum . '|' . json_encode($cLines, JSON_UNESCAPED_UNICODE);
                if (isset($seenComp[$sig])) {
                    $idxRemap[$oldIdx] = $seenComp[$sig];   /* point dup refs at the kept copy */
                    continue;
                }
                $seenComp[$sig]    = count($keptComp);
                $idxRemap[$oldIdx] = count($keptComp);
                $keptComp[]        = $comp;
            }
            if (count($keptComp) !== count($song['components'])) {
                $song['components'] = $keptComp;
                if (is_array($song['arrangement'] ?? null)) {
                    $remappedArr = [];
                    foreach ($song['arrangement'] as $ai) {
                        if (isset($idxRemap[$ai])) { $remappedArr[] = $idxRemap[$ai]; }
                    }
                    $song['arrangement'] = $remappedArr;
                }
            }
        }

        /* #1178 — strip all-BLANK components when the song has real lyrics. A
           blank "Verse 1" (no non-empty line) left over from the accumulation
           bug is junk once any filled component exists; never persist it. If
           EVERY component is blank (a genuinely empty in-progress draft) they're
           kept as-is so the Structure tab isn't emptied. Arrangement indices are
           remapped through the same old→new map so they stay valid (mirrors the
           exact-dup collapse above). */
        if (is_array($song['components'] ?? null) && count($song['components']) > 0) {
            $hasContent = false;
            foreach ($song['components'] as $comp) {
                foreach (($comp['lines'] ?? []) as $ln) {
                    if (trim((string)$ln) !== '') { $hasContent = true; break 2; }
                }
            }
            if ($hasContent) {
                $keptComp = [];
                $idxRemap = [];
                foreach ($song['components'] as $oldIdx => $comp) {
                    $blank = true;
                    foreach (($comp['lines'] ?? []) as $ln) {
                        if (trim((string)$ln) !== '') { $blank = false; break; }
                    }
                    if ($blank) { continue; }   /* drop the empty component */
                    $idxRemap[$oldIdx] = count($keptComp);
                    $keptComp[]        = $comp;
                }
                if (count($keptComp) !== count($song['components'])) {
                    $song['components'] = $keptComp;
                    if (is_array($song['arrangement'] ?? null)) {
                        $remappedArr = [];
                        foreach ($song['arrangement'] as $ai) {
                            if (isset($idxRemap[$ai])) { $remappedArr[] = $idxRemap[$ai]; }
                        }
                        $song['arrangement'] = $remappedArr;
                    }
                }
            }
        }

        $componentCount = is_array($song['components'] ?? null) ? count($song['components']) : 0;
        $arrangementJson = _sanitiseArrangement($song['arrangement'] ?? null, $componentCount);

        /* #1380 — canonical SongId assignment for DRAFT ids. The editor mints a
           client-side draft id ("song-" + base36 ts + random) when a curator hits
           "New Song" (editor.js addNewSong) and the save UPSERTs it verbatim — so a
           saved song could keep an ugly, non-canonical `song-XXXX` id forever. Mint
           the proper `<Abbr>-NNNN` id on the FIRST save instead, BEFORE any INSERT,
           and tell the client to relabel its in-memory copy (assignedId/previousId
           in the response). A non-draft id (an existing real SongId) is untouched. */
        $previousId = $songId;                                   /* the id the client sent */
        $assignedId = null;                                      /* set only when we rename */
        $isDraftId  = (strncmp($songId, 'song-', 5) === 0);
        /* #1380 FIX 3 flag — see $upsertDuplicateClause below. TRUE only for a
           freshly-MINTED brand-new id (a draft promotion), which is the case that
           must INSERT without ON DUPLICATE KEY UPDATE. Deliberately NOT the same
           thing as `$assignedId !== null`: since #1679 a songbook MOVE also sets
           $assignedId (the client relabel signal is shared) but the row it names
           already EXISTS — a plain INSERT there would hit the PK and 500 a
           legitimate save. */
        $mintedNewId = false;

        try {
            $db = getDbMysqli();
            $db->begin_transaction();

            if ($isDraftId) {
                /* Reuse the ONE canonical-id mint (includes/song_relocate.php) so the
                   editor, the songbook-move re-key and the bulk importers all agree on
                   how a songbook's next slot is chosen — the collision-safe loop and
                   its _bulkImport_nextSongNumberFor() seed live in one place now
                   (#1679; the loop itself is byte-for-byte the one that was inlined
                   here). $songbookAbbr is already coerced to 'Misc' when blank, so we
                   always have a book. */
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_relocate.php';
                [$candidate, $n] = songRelocateMintId($db, $songbookAbbr);

                $songId      = $candidate;       /* canonical id used by ALL inserts below */
                $assignedId  = $candidate;       /* signal the rename to the client */
                $mintedNewId = true;             /* brand-new row -> plain INSERT (FIX 3) */

                /* An official songbook numbers its songs; if the curator hadn't set
                   a Number yet, adopt the slot we just chose so the number and the id
                   tail agree. Unofficial / Misc books leave Number NULL (the id is the
                   cross-record link there — #392). */
                if ($isOfficialSongbook && $number === null) {
                    $number = $n;
                }
            }

            /* Capture previous state for the revision row (#400).
               @deleted-visible: revision bookkeeping (#1694) — the snapshot
               must capture the row's real prior state, visible or not. */
            $previousData = null;
            $prevStmt = $db->prepare('SELECT * FROM tblSongs WHERE SongId = ? LIMIT 1');
            $prevStmt->bind_param('s', $songId);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            $prevStmt->close();
            if ($prevRow !== null) {
                $previousData = json_encode($prevRow, JSON_UNESCAPED_UNICODE);
            }
            $action = $prevRow === null ? 'create' : 'edit';

            /* #1679 A13a — the LATER snapshot decides the songbook column, and
             * everything derived from it is re-derived together.
             *
             * ELI5: when the save didn't mention a songbook we keep the song
             * where it is. We read "where it is" twice — once before the
             * transaction opened and once inside it — so trust the reading taken
             * inside, and re-do the two things derived from the other one.
             *
             * Detail: the M2 fix reads the song's current book BEFORE
             * begin_transaction() (it has to — $isOfficialSongbook and $number
             * are computed from it, and the IsOfficial probe is read-only
             * context). $prevRow is then re-read INSIDE the transaction and
             * already carries SongbookAbbr. Two snapshots deciding one column is
             * a race by construction: a concurrent move landing between them
             * would make this save write the OLD book back over the new one, and
             * the UPSERT writes SongbookAbbr unconditionally — so the column and
             * the SongId prefix would disagree, which is #1679's exact defect
             * arrived at by omission rather than by asking.
             *
             * WHAT THIS DOES **NOT** ACHIEVE (#1691 §1b — an earlier revision of
             * this comment claimed "ONE snapshot decides", which overstated it).
             * $prevRow is a PLAIN `SELECT … LIMIT 1`, not `SELECT … FOR UPDATE`,
             * so under InnoDB REPEATABLE READ it is a consistent-snapshot read:
             * a concurrent move that COMMITS after this transaction's first read
             * is still invisible to it, and this save's unconditional UPSERT
             * still last-writer-wins over that move. What the re-derivation
             * actually buys is narrower and real: this save can no longer
             * disagree with ITSELF — both derived values now come from the same
             * single read, closing the window between the pre-transaction read
             * and the transaction (previously open across the mint, the probes
             * and everything above). Serialising against a genuinely concurrent
             * move would need a locking read, which is a deliberate non-goal
             * here: the residual window is commit-vs-commit on the same row,
             * the loser is a curator racing another curator over the same song
             * within milliseconds, and the failure is an overwrite both can see
             * — not the silent self-inconsistency M2 removed.
             * https://dev.mysql.com/doc/refman/8.0/en/innodb-consistent-read.html
             *
             * Only for a save that did NOT send `songbook` (one that did is an
             * explicit instruction and is the relocate branch's business).
             * $prevRow is null for a create / a freshly-minted draft id, where
             * the pre-transaction default (the song's book, else 'Misc') stands.
             *
             * $isOfficialSongbook / $number are re-derived TOGETHER with it —
             * fixing the abbreviation alone would just move the inconsistency
             * into the Number column (an official book numbers its songs, an
             * unofficial one stores NULL). The re-probe only runs in the rare
             * case where the two snapshots actually disagreed. */
            if (!$songbookSent && $prevRow !== null) {
                $txnBook = trim((string)($prevRow['SongbookAbbr'] ?? ''));
                if ($txnBook !== '' && $txnBook !== $songbookAbbr) {
                    $songbookAbbr = $txnBook;
                    $reProbe = $db->prepare(
                        'SELECT IsOfficial FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1'
                    );
                    $reProbe->bind_param('s', $songbookAbbr);
                    $reProbe->execute();
                    $reRow = $reProbe->get_result()->fetch_assoc();
                    $reProbe->close();
                    $isOfficialSongbook = (bool)($reRow['IsOfficial'] ?? false);
                    $number = (!$isOfficialSongbook || $rawNumber === null || $rawNumber === ''
                               || (int)$rawNumber <= 0)
                        ? null
                        : (int)$rawNumber;
                }
            }

            /* #1679 — SONGBOOK MOVE. `tblSongbooks.Abbreviation` IS the SongId
               prefix (rule #27), so a save that changes an EXISTING song's book
               must re-key the id too; leaving it alone is what produced songs whose
               id claimed one book and whose column claimed another.
               songRelocate() mints the new id, cascades every child row, rewrites
               the two non-FK soft references (content restrictions + the
               tblSongbookEntries home row), clears Number and writes the
               tblSongRedirects row so old permalinks keep resolving.
               It runs INSIDE this transaction, so a later failure rolls the move
               back with the rest of the save.
               After it, the normal UPSERT below proceeds under the NEW id and takes
               the ON DUPLICATE KEY UPDATE path against the row we just renamed —
               which is why $mintedNewId, not $assignedId, drives the FIX-3 clause.
               $previousData / $prevRow deliberately keep the PRE-move snapshot: the
               revision row is a historical record, and the SongCount refresh below
               reads the OLD SongbookAbbr out of it (recomputing both books is
               idempotent with songRelocate's own recompute).

               #1679 M2 — gated on $songbookSent (the RAW payload actually carried
               a non-empty `songbook` key), NOT on `$songbookAbbr !== ''`. That
               older test could never be false: $songbookAbbr is defaulted several
               hundred lines above, so a partial save which omitted the key
               compared 'Misc' against the song's real book, found them different,
               and performed a full destructive move — a new id, a cleared Number
               and a permanent redirect — for a payload that never mentioned the
               songbook at all. A move is now something a client has to ASK for. */
            if ($prevRow !== null && $songbookSent
                && (string)($prevRow['SongbookAbbr'] ?? '') !== $songbookAbbr) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_relocate.php';
                $moveUserId = null;
                if (function_exists('getCurrentUser')) {
                    $moveUser   = getCurrentUser();
                    $moveUserId = isset($moveUser['id']) ? (int)$moveUser['id'] : null;
                }
                $relocation = songRelocate($db, $songId, $songbookAbbr, $moveUserId);
                if ($relocation['renamed']) {
                    $previousId = $relocation['previousId'];
                    $songId     = $relocation['songId'];
                    /* Reuse the #1380 client-relabel contract verbatim — editor.js
                       already re-keys its in-memory song, dirty set, rendered-song
                       ref and sidebar on assignedId/previousId. */
                    $assignedId = $relocation['songId'];
                    /* Owner's stated default: a moved song's Number is CLEARED
                       (v1 parity; also dodges a collision in the target book). The
                       UPSERT below must not write the client's stale number back. */
                    $number = null;
                }
            }

            /* #892 — schema-probe for the optional ArrangementJson column.
               Pre-migration deploys keep the legacy 16-column UPSERT; once
               the column exists we add a 17th bound parameter so the
               curator's chosen verse/refrain/verse… order finally
               round-trips through save → reload. */
            $hasArrangementCol = false;
            try {
                $arrProbe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblSongs'
                        AND COLUMN_NAME  = 'ArrangementJson' LIMIT 1"
                );
                $arrProbe->execute();
                $hasArrangementCol = $arrProbe->get_result()->fetch_row() !== null;
                $arrProbe->close();
            } catch (\Throwable $_e) {
                /* #1679 A1 — the FIRST statement of every best-effort catch between
                   begin_transaction() and commit() in this function. Each of these blocks
                   is correct about what it was written for (a probe / a follow-up must not
                   cost the curator their edit) and none was written for "the transaction I
                   am inside may already have been rolled back underneath me". Swallowing
                   one of those makes commit() below succeed trivially and answers ok:true
                   for work that no longer exists — and since #1679 that work includes a
                   songbook MOVE whose songRelocate() carefully re-throws the very same
                   codes a few frames up. The list lives in ONE predicate rather than being
                   copied into nine catches (rule #35: cross-file agreement needs a
                   mechanism, not a "keep these in sync" comment). */
                if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
                /* default false */
            }

            /* #1380 FIX 3 — TOCTOU-safe write for a FRESHLY-MINTED canonical id.
               The mint loop above checks "is this id free?" then we INSERT — a
               read-then-write window. Two concurrent FIRST saves in one songbook can
               BOTH pass the existence check before either writes, then both reach the
               shared UPSERT — and `ON DUPLICATE KEY UPDATE` would SILENTLY OVERWRITE
               the first song's row with the second's (data loss, no error). So when we
               just minted a canonical id ($mintedNewId — always a brand-new CREATE,
               $prevRow is null), use a PLAIN INSERT with NO ON DUPLICATE KEY
               UPDATE clause: a colliding PK throws ER_DUP_ENTRY (1062), which the
               surrounding catch turns into a clean rollback → 500 the client retries
               (the editor's per-song save loop re-mints a fresh slot on retry).
               A GENUINE update (or a non-draft create) keeps the existing UPSERT — a
               re-save of an existing real SongId MUST update it, not error. The
               ON DUPLICATE KEY UPDATE tail is therefore appended only when NOT minting.
               #1679 — the test is $mintedNewId, NOT `$assignedId !== null`: a songbook
               move ALSO sets $assignedId (it shares the client-relabel contract) but
               its row already exists, so it needs the UPDATE tail, not a plain INSERT. */
            $upsertDuplicateClause = $mintedNewId
                ? ''
                : ' ON DUPLICATE KEY UPDATE
                        Number = VALUES(Number), Title = VALUES(Title),
                        SongbookAbbr = VALUES(SongbookAbbr),
                        Language = VALUES(Language), Copyright = VALUES(Copyright),
                        TuneName = VALUES(TuneName),
                        Ccli = VALUES(Ccli), Iswc = VALUES(Iswc),
                        Verified = VALUES(Verified),
                        LyricsPublicDomain = VALUES(LyricsPublicDomain),
                        MusicPublicDomain = VALUES(MusicPublicDomain),
                        HasAudio = VALUES(HasAudio), HasSheetMusic = VALUES(HasSheetMusic),
                        LyricsText = VALUES(LyricsText)';

            /* UPSERT tblSongs — now carries TuneName + Iswc (#497) and
               ArrangementJson (#892) when the column exists. The duplicate-key tail
               is conditional per the FIX-3 minted-create rule above. */
            if ($hasArrangementCol) {
                /* The ArrangementJson column adds one more VALUES()-updated field to
                   the (non-minting) UPDATE tail. */
                $arrUpdateTail = $upsertDuplicateClause === ''
                    ? ''
                    : $upsertDuplicateClause . ',
                        ArrangementJson = VALUES(ArrangementJson)';
                $upsert = $db->prepare(
                    'INSERT INTO tblSongs
                        (SongId, Number, Title, SongbookAbbr, Language,
                         Copyright, TuneName, Ccli, Iswc, Verified, LyricsPublicDomain,
                         MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText,
                         ArrangementJson)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                     . $arrUpdateTail
                );
                /* SongbookName denorm column dropped (WS-E #1013 ph2) —
                   16 cols / 16 placeholders / 16 bind types. */
                $upsert->bind_param(
                    'sisssssssiiiiiss',
                    $songId, $number, $title, $songbookAbbr,
                    $language, $copyright, $tuneName, $ccli, $iswc,
                    $verified, $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText,
                    $arrangementJson
                );
            } else {
                $upsert = $db->prepare(
                    'INSERT INTO tblSongs
                        (SongId, Number, Title, SongbookAbbr, Language,
                         Copyright, TuneName, Ccli, Iswc, Verified, LyricsPublicDomain,
                         MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                     . $upsertDuplicateClause
                );
                /* SongbookName denorm column dropped (WS-E #1013 ph2) —
                   15 cols / 15 placeholders / 15 bind types. */
                $upsert->bind_param(
                    'sisssssssiiiiis',
                    $songId, $number, $title, $songbookAbbr,
                    $language, $copyright, $tuneName, $ccli, $iswc,
                    $verified, $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText
                );
            }
            $upsert->execute();
            $upsert->close();

            /* Places adoption — write the composition-origin
               columns in a separate small UPDATE so the carefully-
               tuned 16/17-param UPSERT above stays untouched.
               Skipped silently on pre-adoption installs. */
            if (placeColumnExists($db, 'tblSongs', 'OriginCityId')) {
                $placeStmt = $db->prepare(
                    'UPDATE tblSongs
                        SET OriginCity = ?, OriginCityId = ?
                      WHERE SongId = ?'
                );
                $placeStmt->bind_param('sis', $originCity, $originCityId, $songId);
                $placeStmt->execute();
                $placeStmt->close();
            }

            /* #1235 PF1 / R1 — carry-forward (data-loss guard) + write-path selection.
               A STALE client (a Service-Worker-cached pre-#1094 / pre-P3 editor.js)
               POSTs components WITHOUT `chords` / `languages`, so a naive recreate
               would NULL them song-wide. BEFORE the write we snapshot the existing
               per-component arrays keyed by Type | Number | line-count as a FIFO queue
               (repeated identical parts — a refrain reprised after each verse — pair
               1:1); a component whose POST OMITS the key reclaims its carried value, a
               component that SENDS the key (even empty) stays authoritative.

               #1235 P4/C5 — on a MIRRORED install the carry SOURCE is the AUTHORITATIVE
               tblLyricLines (assembled editor shape), NOT the doomed LinesJson/
               ChordsJson/LanguagesJson columns, and the save uses the inverted write
               path below (lines authoritative; JSON shadow) — so it survives the C6
               drop. On an un-migrated install (no mirror) the carry is the pre-delete
               JSON-column snapshot, reattached as raw JSON in the legacy path. */
            $ll_syncReady = lyricLinesSyncReady($db);
            $carryChords  = [];   // "type\x1fnumber\x1flineCount" => FIFO list (arrays when mirrored; JSON strings legacy)
            $carryLangs   = [];
            $pf1HasChords = false;
            $pf1HasLangs  = false;
            /* Clean a component's per-line `chords` (array OR space-separated string per
               line) into a null-padded parallel array (or null). Editor-input
               normalisation kept at the funnel boundary; the shared write path stores it
               verbatim. */
            $saveSongCleanChords = static function ($chords, int $lineCount): ?array {
                if (!is_array($chords) || $lineCount <= 0) { return null; }
                $out = []; $any = false;
                for ($ci = 0; $ci < $lineCount; $ci++) {
                    $cell = $chords[$ci] ?? null;
                    if (is_array($cell)) {
                        $clean = array_values(array_filter(array_map(static fn($c) => trim((string)$c), $cell), static fn($c) => $c !== ''));
                    } elseif (is_string($cell) && trim($cell) !== '') {
                        $clean = array_values(array_filter(preg_split('/\s+/', trim($cell)) ?: [], static fn($c) => $c !== ''));
                    } else {
                        $clean = null;
                    }
                    if ($clean !== null && $clean !== []) { $out[] = $clean; $any = true; }
                    else { $out[] = null; }
                }
                return $any ? $out : null;
            };
            if ($ll_syncReady) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
                foreach (lyricLinesEditableComponents($db, $songId) as $pc) {
                    $key = (string)$pc['type'] . "\x1f" . (string)(int)$pc['number'] . "\x1f" . count($pc['lines']);
                    $carryChords[$key][] = $pc['chords'];      // prior parallel chords array, or null
                    $carryLangs[$key][]  = $pc['languages'];   // prior per-line override array, or null
                }
            } else {
                /* lines-json-fallback (#1235 P4): un-migrated install (no mirror).
                   Column names are hardcoded constants (rule #5). */
                try {
                    $pf1Probe = $db->prepare(
                        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'tblSongComponents'
                            AND COLUMN_NAME  = 'ChordsJson' LIMIT 1"
                    );
                    $pf1Probe->execute();
                    $pf1HasChords = $pf1Probe->get_result()->fetch_row() !== null;
                    $pf1Probe->close();
                } catch (\Throwable $_e) {
                    if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #1679 A1 */
                    /* default false */
                }
                $pf1HasLangs = lyricLinesComponentsLangReady($db);
                if ($pf1HasChords || $pf1HasLangs) {
                    $snapCols = 'Type, Number, LinesJson';
                    if ($pf1HasChords) { $snapCols .= ', ChordsJson'; }
                    if ($pf1HasLangs)  { $snapCols .= ', LanguagesJson'; }
                    $snap = $db->prepare(
                        "SELECT {$snapCols} FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder, Id"
                    );
                    $snap->bind_param('s', $songId);
                    $snap->execute();
                    $snapRes = $snap->get_result();
                    while ($snapRow = $snapRes->fetch_assoc()) {
                        $decoded = json_decode((string)$snapRow['LinesJson'], true);
                        $lc      = is_array($decoded) ? count($decoded) : 0;
                        $key     = (string)$snapRow['Type'] . "\x1f" . (string)(int)$snapRow['Number'] . "\x1f" . $lc;
                        if ($pf1HasChords) { $carryChords[$key][] = $snapRow['ChordsJson']; }
                        if ($pf1HasLangs)  { $carryLangs[$key][]  = $snapRow['LanguagesJson']; }
                    }
                    $snap->close();
                }
            }

            /* Child rows: DELETE then INSERT — simpler than diffing and
               the row counts per song are small (≈1–20 each). New credit
               tables from #497 are cleaned up here too. NOTE (#1235 P4/C5):
               tblSongComponents is NO LONGER in this blanket DELETE — the
               component+line write below is an Id-STABLE upsert
               (lyricLinesWriteComponents) so ComponentId no longer churns on
               every save. The legacy (un-migrated) branch DELETEs it itself. */
            foreach ([
                'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
                'tblSongAdaptors', 'tblSongTranslators', 'tblSongArtists',
            ] as $childTable) {
                /* tblSongArtists (#587) only exists once
                   migrate-song-artists.php has been applied. Skip the
                   DELETE on a partly-migrated install rather than 500ing
                   the save path; the schema-audit page (#518) flags the
                   missing table separately. */
                if ($childTable === 'tblSongArtists' && !_songArtistsTableExists($db)) {
                    continue;
                }
                $del = $db->prepare("DELETE FROM {$childTable} WHERE SongId = ?");
                $del->bind_param('s', $songId);
                $del->execute();
                $del->close();
            }

            /* Insert credit collections. Each collection is a separate
               prepared statement to keep the field name explicit at each
               call site. The artists insert (#587) is gated on the
               table existing — same partly-migrated tolerance as the
               DELETE above. */
            $creditInserts = [
                'writers'     => 'INSERT INTO tblSongWriters     (SongId, Name) VALUES (?, ?)',
                'composers'   => 'INSERT INTO tblSongComposers   (SongId, Name) VALUES (?, ?)',
                'arrangers'   => 'INSERT INTO tblSongArrangers   (SongId, Name) VALUES (?, ?)',
                'adaptors'    => 'INSERT INTO tblSongAdaptors    (SongId, Name) VALUES (?, ?)',
                'translators' => 'INSERT INTO tblSongTranslators (SongId, Name) VALUES (?, ?)',
            ];
            if (_songArtistsTableExists($db)) {
                /* SortOrder defaults to 0 — display order falls back to
                   Id (insertion order) which matches how the editor
                   sends the artists array. Same 2-param shape as the
                   other credit inserts so the existing loop below works
                   without a special case. */
                $creditInserts['artists'] = 'INSERT INTO tblSongArtists (SongId, Name) VALUES (?, ?)';
            }
            /* #960 — Each credit can arrive as either a legacy
               string ("John Newton") OR a structured-parts object
               ({name, first, surname, suffix}). The new chip-list
               editor sends the latter so the registry write below
               can populate FirstNames / Surname / Suffix on
               auto-promote (PR #935 columns). Normalise into a
               uniform array shape up front. */
            require_once dirname(dirname(__DIR__)) . '/includes/credit_people_helpers.php';
            $regParts = []; /* keyed by composed Name → ['first'=>…,'surname'=>…,'suffix'=>…] */
            /* #960 — normalisation itself now lives in creditEntryNormalise()
               (includes/credit_people_helpers.php) so the v2 editor's granular
               credit_upsert endpoint shares the exact same decompose/compose
               behaviour instead of re-forking it. This whole-song save keeps
               only what's genuinely whole-save-specific: the richest-parts
               accumulation loop below (a name typed differently across two
               role lists picks the more complete entry for the registry). */

            foreach ($creditInserts as $key => $sql) {
                $stmt = $db->prepare($sql);
                /* Dedup (#1178): a song must never list the same person twice in
                   the same role — a client accumulation bug duplicated the whole
                   credit list many times over. Case-insensitive, first wins. */
                $seenCredit = [];
                foreach ($song[$key] ?? [] as $raw) {
                    $entry = creditEntryNormalise($raw);
                    if ($entry === null) continue;
                    $dedupKey = function_exists('mb_strtolower') ? mb_strtolower($entry['name']) : strtolower($entry['name']);
                    if (isset($seenCredit[$dedupKey])) continue;
                    $seenCredit[$dedupKey] = true;
                    $stmt->bind_param('ss', $songId, $entry['name']);
                    $stmt->execute();
                    /* Keep the richest parts seen for this name across
                       all five role lists — if "J. Newton" was typed
                       in Writers and "John Newton" in Composers, the
                       structured-parts version wins for the registry
                       upsert. (Both arrived as separate junction rows
                       above; this dedup only affects the registry.) */
                    if (!isset($regParts[$entry['name']])
                        || ($regParts[$entry['name']]['first'] === '' && $regParts[$entry['name']]['surname'] === '' && $regParts[$entry['name']]['suffix'] === '')
                    ) {
                        $regParts[$entry['name']] = [
                            'first'   => $entry['first'],
                            'surname' => $entry['surname'],
                            'suffix'  => $entry['suffix'],
                        ];
                    }
                }
                $stmt->close();
            }
            /* Silently keep the credit-people registry in sync
               (#545 / #960). When the FirstNames / Surname / Suffix
               columns exist (PR #935 migration applied), upsert the
               structured parts too — but only fill them in when the
               existing row's parts are NULL/empty, so a curated
               edit on the /manage/credit-people page never gets
               overwritten by an auto-promote from the editor.
               Pre-migration installs fall back to the legacy
               Name-only path. The promote-and-backfill pairing itself
               now lives in creditPersonPromote() (credit_people_helpers.php)
               so every credit write path — this whole-song save, v2's
               credit_upsert/revision_restore, and lyrics_ingest.php's
               artist insert — shares one implementation instead of
               re-forking it (#960; project modularity rule). */
            foreach ($regParts as $regName => $p) {
                creditPersonPromote($db, $regName, $p);
            }

            if ($ll_syncReady) {
                /* #1235 P4/C5 — inverted write path. Build the components payload from
                   the POST (clean per-line chords; reattach the PF1-carried
                   chords/languages a stale client omitted), then hand it to the shared
                   writer: tblLyricLines becomes the source of truth and the JSON columns
                   are shadow-written from the SAME payload while they exist. Id-stable —
                   no component DELETE (so ComponentId no longer churns every save). */
                $writeComps = [];
                foreach ($song['components'] ?? [] as $comp) {
                    /* Normalise type/number EXACTLY as lyricLinesWriteComponents stores them
                       (trim + 20-char cap; non-negative int) so the PF1 carry key matches the
                       snapshot key (built above from the STORED, normalised values via
                       lyricLinesEditableComponents) — a raw-vs-normalised mismatch would
                       silently fail the chords/languages carry-forward (the C5 review finding). */
                    $cType  = function_exists('mb_substr')
                        ? (mb_substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse')
                        : (substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse');
                    $cNum   = max(0, (int)($comp['number'] ?? 0));
                    $cLines = is_array($comp['lines'] ?? null) ? array_values($comp['lines']) : [];
                    $pf1Key = $cType . "\x1f" . (string)$cNum . "\x1f" . count($cLines);
                    /* chords: explicit (even empty) wins; omitted reclaims the carried prior array. */
                    if (array_key_exists('chords', $comp)) {
                        $cChords = $saveSongCleanChords($comp['chords'] ?? null, count($cLines));
                    } else {
                        $carried = !empty($carryChords[$pf1Key]) ? array_shift($carryChords[$pf1Key]) : null;
                        $cChords = is_array($carried) ? $carried : null;
                    }
                    /* languages (per-line override): same explicit-vs-carry rule. */
                    if (array_key_exists('languages', $comp)) {
                        $cLangs = is_array($comp['languages'] ?? null) ? $comp['languages'] : null;
                    } else {
                        $carriedL = !empty($carryLangs[$pf1Key]) ? array_shift($carryLangs[$pf1Key]) : null;
                        $cLangs = is_array($carriedL) ? $carriedL : null;
                    }
                    $writeComps[] = [
                        'type'      => $cType,
                        'number'    => $cNum,
                        'language'  => (isset($comp['language']) && trim((string)$comp['language']) !== '') ? trim((string)$comp['language']) : null,
                        'lines'     => $cLines,
                        'chords'    => $cChords,
                        'languages' => $cLangs,
                    ];
                }
                lyricLinesWriteComponents($db, $songId, $writeComps);
            } else {
            /* lines-json-fallback (#1235 P4): un-migrated install (no tblLyricLines
               mirror) — the legacy component write. tblSongComponents was removed from
               the shared child-table DELETE loop above, so DELETE it here first, then
               re-INSERT LinesJson [+ shadow ChordsJson / LanguagesJson]. LinesJson
               provably still exists (the C6 drop only runs once the mirror is present). */
            $delLegacyComp = $db->prepare("DELETE FROM tblSongComponents WHERE SongId = ?");
            $delLegacyComp->bind_param('s', $songId);
            $delLegacyComp->execute();
            $delLegacyComp->close();
            /* #858 — schema-probe for the optional Language column.
               When present, the INSERT carries a per-component
               override; pre-migration deploys keep the legacy
               5-column INSERT shape. */
            $hasComponentLanguage = false;
            try {
                $colProbe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblSongComponents'
                        AND COLUMN_NAME  = 'Language' LIMIT 1"
                );
                $colProbe->execute();
                $hasComponentLanguage = $colProbe->get_result()->fetch_row() !== null;
                $colProbe->close();
            } catch (\Throwable $_e) {
                if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #1679 A1 */
                /* default false */
            }

            /* #1094 — schema-probe for the optional ChordsJson column (#1066).
               When present, manual per-line chords are persisted via a guarded
               UPDATE after each component INSERT (leaving the proven INSERT shape
               untouched); pre-migration deploys simply skip it. */
            $hasComponentChords = false;
            try {
                $chProbe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblSongComponents'
                        AND COLUMN_NAME  = 'ChordsJson' LIMIT 1"
                );
                $chProbe->execute();
                $hasComponentChords = $chProbe->get_result()->fetch_row() !== null;
                $chProbe->close();
            } catch (\Throwable $_e) {
                if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #1679 A1 */
                /* default false */
            }
            $updChords = $hasComponentChords
                ? $db->prepare('UPDATE tblSongComponents SET ChordsJson = ? WHERE Id = ?')
                : null;
            /* #1235 P3 — per-line language overrides (LanguagesJson), written like
               chords: one UPDATE per component by its insert_id when present.
               No-op (null) on an un-migrated install. */
            $updLangs = lyricLinesComponentsLangReady($db)
                ? $db->prepare('UPDATE tblSongComponents SET LanguagesJson = ? WHERE Id = ?')
                : null;

            if ($hasComponentLanguage) {
                $insComp = $db->prepare(
                    'INSERT INTO tblSongComponents
                        (SongId, Type, Number, SortOrder, LinesJson, Language)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
            } else {
                $insComp = $db->prepare(
                    'INSERT INTO tblSongComponents
                        (SongId, Type, Number, SortOrder, LinesJson)
                     VALUES (?, ?, ?, ?, ?)'
                );
            }
            $order = 0;
            foreach ($song['components'] ?? [] as $comp) {
                $type   = (string)($comp['type'] ?? 'verse');
                $cNum   = isset($comp['number']) ? (int)$comp['number'] : 0;
                $lines  = json_encode($comp['lines'] ?? [], JSON_UNESCAPED_UNICODE);
                if ($hasComponentLanguage) {
                    /* Trim + cap to 35 chars (the BCP 47 column width).
                       Empty / null = inherit from the song; only persist
                       a value that's plausibly a tag. */
                    $lang = trim((string)($comp['language'] ?? ''));
                    if ($lang === '' || !preg_match('/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{1,8})*$/', $lang)) {
                        $lang = null;
                    } else {
                        $lang = function_exists('mb_substr') ? mb_substr($lang, 0, 35) : substr($lang, 0, 35);
                    }
                    $insComp->bind_param('ssiiss', $songId, $type, $cNum, $order, $lines, $lang);
                } else {
                    $insComp->bind_param('ssiis', $songId, $type, $cNum, $order, $lines);
                }
                $insComp->execute();
                /* Capture the new component Id ONCE — the chords UPDATE below
                   would zero $db->insert_id, so the per-line-language write that
                   follows it must reuse this captured value. */
                $newCompId = (int)$db->insert_id;

                /* #1094 — persist manual per-line chords (parallel to LinesJson).
                   comp['chords'][i] may be a space-separated string or an array
                   of chord symbols; null-padded to the line count. Stored only
                   when at least one line has chords. */
                if ($updChords !== null && isset($comp['chords']) && is_array($comp['chords'])) {
                    $lineCount = is_array($comp['lines'] ?? null) ? count($comp['lines']) : 0;
                    $chordsArr = [];
                    $anyChords = false;
                    for ($ci = 0; $ci < $lineCount; $ci++) {
                        $cell = $comp['chords'][$ci] ?? null;
                        if (is_array($cell)) {
                            $clean = array_values(array_filter(array_map(static fn($c) => trim((string)$c), $cell), static fn($c) => $c !== ''));
                        } elseif (is_string($cell) && trim($cell) !== '') {
                            $clean = array_values(array_filter(preg_split('/\s+/', trim($cell)) ?: [], static fn($c) => $c !== ''));
                        } else {
                            $clean = null;
                        }
                        if ($clean !== null && $clean !== []) { $chordsArr[] = $clean; $anyChords = true; }
                        else { $chordsArr[] = null; }
                    }
                    if ($anyChords) {
                        $chordsJson = json_encode($chordsArr, JSON_UNESCAPED_UNICODE);
                        $updChords->bind_param('si', $chordsJson, $newCompId);
                        $updChords->execute();
                    }
                } elseif ($updChords !== null && !isset($comp['chords'])) {
                    /* PF1 / R1 carry-forward — the client did NOT send `chords` for
                       this component, so don't let the reinsert leave ChordsJson NULL:
                       reclaim the pre-delete array for the position-matched part
                       (same Type | Number | line-count), FIFO so duplicate parts pair
                       1:1. An explicit (even empty) `chords` above stays authoritative. */
                    $pf1Key = $type . "\x1f" . (string)$cNum . "\x1f"
                            . (is_array($comp['lines'] ?? null) ? count($comp['lines']) : 0);
                    if (!empty($carryChords[$pf1Key])) {
                        $pf1Carried = array_shift($carryChords[$pf1Key]);
                        if ($pf1Carried !== null) {
                            $updChords->bind_param('si', $pf1Carried, $newCompId);
                            $updChords->execute();
                        }
                    }
                }

                /* #1235 P3 — persist per-line language overrides (parallel to
                   LinesJson; null-padded BCP47 tags). Stored only when at least
                   one line carries a real override; a null/absent entry inherits
                   the component Language. Same shared builder as api2.php. */
                if ($updLangs !== null) {
                    if (isset($comp['languages'])) {
                        $lineCount = is_array($comp['lines'] ?? null) ? count($comp['lines']) : 0;
                        $langsJson = lineEnrichmentBuildLanguagesJson($comp['languages'], $lineCount);
                        if ($langsJson !== null) {
                            $updLangs->bind_param('si', $langsJson, $newCompId);
                            $updLangs->execute();
                        }
                    } else {
                        /* PF1 / R1 carry-forward — client OMITTED `languages`; preserve
                           the existing per-line language array (FIFO by Type | Number |
                           line-count) instead of letting the reinsert NULL it. */
                        $pf1KeyL = $type . "\x1f" . (string)$cNum . "\x1f"
                                 . (is_array($comp['lines'] ?? null) ? count($comp['lines']) : 0);
                        if (!empty($carryLangs[$pf1KeyL])) {
                            $pf1CarriedL = array_shift($carryLangs[$pf1KeyL]);
                            if ($pf1CarriedL !== null) {
                                $updLangs->bind_param('si', $pf1CarriedL, $newCompId);
                                $updLangs->execute();
                            }
                        }
                    }
                }
                $order++;
            }
            $insComp->close();
            if ($updChords !== null) { $updChords->close(); }
            if ($updLangs  !== null) { $updLangs->close(); }
            } /* end lines-json-fallback (legacy un-migrated component write) */

            /* Revision audit log (#400) — authenticated editors only.
               Silent no-op if the user isn't authenticated via the /manage
               session or if the revisions table is missing. */
            $revisionId = null;
            try {
                require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
                $editor = getCurrentUser();
                $userId = $editor['id'] ?? null;
                $newData = json_encode($song, JSON_UNESCAPED_UNICODE);
                $rev = $db->prepare(
                    'INSERT INTO tblSongRevisions
                        (SongId, UserId, Action, PreviousData, NewData, Status)
                     VALUES (?, ?, ?, ?, ?, "approved")'
                );
                $userIdParam = $userId !== null ? (int)$userId : null;
                $rev->bind_param('sisss', $songId, $userIdParam, $action, $previousData, $newData);
                $rev->execute();
                $revisionId = (int)$db->insert_id;
                $rev->close();
            } catch (\Throwable $_e) {
                if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #1679 A1 */
                /* revisions are best-effort */
            }

            /* Refresh tblSongbooks.SongCount for every songbook this
               save touched (#791). The home + /songbooks tiles gate
               render on `songCount > 0`, and SongCount is a cached
               column — without this recompute, a brand-new song
               saved to Misc / any not-yet-populated songbook lands
               in tblSongs but the songbook tile never appears
               because the cache stays at 0.

               Two paths recompute:
                 - The current row's SongbookAbbr (always).
                 - The PREVIOUS row's SongbookAbbr if it differs
                   (song moved between books — old book shrinks,
                   new book grows). previousData is the JSON dump
                   of the row before this save.

               Wrapped in try/catch so a SongCount recompute failure
               (e.g. transient deadlock) doesn't roll back the song
               save itself — the cache will self-heal on the next
               recompute pass. */
            try {
                $cnt = $db->prepare(
                    /* #1694 D1 — SongCount counts VISIBLE songs; must agree
                       with the predicate-aware triggers (trigger-denied hosts
                       rely on THIS recompute). */
                    'UPDATE tblSongbooks
                        SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ')
                      WHERE Abbreviation = ?'
                );
                $cnt->bind_param('ss', $songbookAbbr, $songbookAbbr);
                $cnt->execute();
                $cnt->close();

                /* If the row moved songbooks, the OLD book also needs
                   its count refreshed so its tile shrinks (or hides
                   entirely if this was its last song). */
                if ($previousData !== null) {
                    $prev = json_decode($previousData, true);
                    $prevAbbr = is_array($prev) ? (string)($prev['SongbookAbbr'] ?? '') : '';
                    if ($prevAbbr !== '' && $prevAbbr !== $songbookAbbr) {
                        $cnt = $db->prepare(
                            /* #1694 D1 — same visible-count recompute for the
                               OLD book after a move. */
                            'UPDATE tblSongbooks
                                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ')
                              WHERE Abbreviation = ?'
                        );
                        $cnt->bind_param('ss', $prevAbbr, $prevAbbr);
                        $cnt->execute();
                        $cnt->close();
                    }
                }
            } catch (\Throwable $_e) {
                if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #1679 A1 — see the first such guard above. */
                error_log('[editor save_song] SongCount recompute failed: ' . $_e->getMessage());
            }

            /* #833 — persist song-level external links when the client
               opted into the new editor section by sending the
               `externalLinks` key. The legacy / pre-#833 clients
               (bulk-import path, older editor snapshots) omit the key
               entirely and the existing rows stay untouched. The shared
               saver expects four parallel arrays in the canonical
               ext_link_*[] shape, so we unpack the JSON list of
               {typeId, url, note, verified} into that shape inline.
               Runs before the song.edit / song.create logActivity row
               below so the inserted-link count can be folded into the
               audit payload (mirroring the songbook / work surfaces'
               external_link_count audit field). */
            $externalLinkCount = null;
            if (array_key_exists('externalLinks', $song)) {
                try {
                    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                        . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
                    $extProbe = $db->query(
                        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'tblSongExternalLinks' LIMIT 1"
                    );
                    $hasExtTable = $extProbe && $extProbe->fetch_row() !== null;
                    if ($extProbe) $extProbe->close();

                    if ($hasExtTable) {
                        $linkRows  = is_array($song['externalLinks']) ? $song['externalLinks'] : [];
                        $typeIds   = [];
                        $urls      = [];
                        $notes     = [];
                        $verified  = [];
                        foreach ($linkRows as $row) {
                            if (!is_array($row)) continue;
                            $typeIds[]  = (int)($row['typeId'] ?? 0);
                            $urls[]     = (string)($row['url'] ?? '');
                            $notes[]    = (string)($row['note'] ?? '');
                            $verified[] = !empty($row['verified']) ? '1' : '';
                        }
                        $externalLinkCount = saveExternalLinksForRow(
                            $db,
                            'tblSongExternalLinks',
                            'SongId',
                            $songId,
                            $typeIds,
                            $urls,
                            $notes,
                            $verified
                        );
                    }
                } catch (\Throwable $_e) {
                    if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #1679 A1 — see the first such guard above. */
                    /* Match the Works-auto-link pattern — link
                       reconciliation must not block the core save.
                       The user's metadata edit is already committed
                       below; a link write failure surfaces in
                       error_log + audit but doesn't 500 the request. */
                    error_log('[editor save_song] external-links reconcile failed: ' . $_e->getMessage());
                }
            }

            /* Activity log (#535) — high-level "song.create" / "song.edit"
               row with a cross-link to the revisions row above so a
               timeline reader can drill into the full before/after diff
               without bloating Details here. external_link_count is
               null when the client didn't opt into the externalLinks
               key (e.g. bulk-import path); otherwise it carries the
               number of rows inserted by the reconcile above. */
            if (function_exists('logActivity')) {
                $logDetails = [
                    'title'         => $title,
                    'songbook'      => $songbookAbbr,
                    'number'        => $number,
                    'verified'      => (bool)$verified,
                    'revision_id'   => $revisionId,
                ];
                if ($externalLinkCount !== null) {
                    $logDetails['external_link_count'] = $externalLinkCount;
                }
                logActivity(
                    $action === 'create' ? 'song.create' : 'song.edit',
                    'song',
                    $songId,
                    $logDetails
                );
            }

            /* ISWC auto-link to tblWorks (admin audit follow-up).
               When this song carries an ISWC the editor expects a
               matching Work row to exist so the public /song page
               can show the "Part of work X" panel and so the Works
               admin reflects every catalogued composition. The
               batch backfill-works-from-iswc migration handles
               existing rows; this block keeps the invariant on
               every NEW save. Schema-tolerant: silently skips when
               tblWorks isn't present yet (pre-migration installs). */
            if ($iswc !== null && $iswc !== '') {
                try {
                    /* Probe schema once per request. */
                    static $hasWorksSchema = null;
                    if ($hasWorksSchema === null) {
                        $probe = $db->prepare(
                            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME   IN ('tblWorks', 'tblWorkSongs')"
                        );
                        $probe->execute();
                        $hasWorksSchema = count($probe->get_result()->fetch_all()) === 2;
                        $probe->close();
                    }
                    if ($hasWorksSchema) {
                        /* Look for an existing Work keyed on this ISWC. */
                        $wStmt = $db->prepare('SELECT Id FROM tblWorks WHERE Iswc = ? LIMIT 1');
                        $wStmt->bind_param('s', $iswc);
                        $wStmt->execute();
                        $wRow = $wStmt->get_result()->fetch_row();
                        $wStmt->close();

                        if ($wRow) {
                            $workId = (int)$wRow[0];
                        } else {
                            /* Create a new Work — Title mirrors this song's
                               title; Slug derived from Title with
                               collision suffix to satisfy uq_slug. */
                            $slugBase = mb_strtolower(trim((string)$title));
                            if (class_exists('Normalizer')) {
                                $slugBase = \Normalizer::normalize($slugBase, \Normalizer::FORM_KD);
                                $slugBase = preg_replace('/\p{M}+/u', '', $slugBase) ?? '';
                            }
                            $slugBase = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slugBase) ?? '';
                            $slugBase = trim((string)$slugBase, '-');
                            if ($slugBase === '') $slugBase = 'work';
                            if (mb_strlen($slugBase) > 76) $slugBase = mb_substr($slugBase, 0, 76);

                            $slug      = $slugBase;
                            $slugCheck = $db->prepare('SELECT 1 FROM tblWorks WHERE Slug = ? LIMIT 1');
                            $suffix    = 1;
                            while (true) {
                                $slugCheck->bind_param('s', $slug);
                                $slugCheck->execute();
                                $taken = $slugCheck->get_result()->fetch_row() !== null;
                                if (!$taken) break;
                                $suffix++;
                                $slug = $slugBase . '-' . $suffix;
                            }
                            $slugCheck->close();

                            $notes = sprintf('Auto-created from ISWC %s when song %s was saved.', $iswc, $songId);
                            $wIns  = $db->prepare(
                                'INSERT INTO tblWorks (Iswc, Title, Slug, Notes) VALUES (?, ?, ?, ?)'
                            );
                            $wIns->bind_param('ssss', $iswc, $title, $slug, $notes);
                            $wIns->execute();
                            $workId = (int)$db->insert_id;
                            $wIns->close();

                            if (function_exists('logActivity')) {
                                logActivity('work.auto_create', 'work', (string)$workId, [
                                    'iswc'      => $iswc,
                                    'title'     => $title,
                                    'slug'      => $slug,
                                    'source'    => 'song_editor.save_song',
                                    'song_id'   => $songId,
                                ]);
                            }
                        }

                        /* Idempotent membership. IsCanonical defaults to 0
                           — the first member of a brand-new Work is
                           promoted to canonical inline via a follow-up
                           UPDATE only when no canonical member exists. */
                        $linkStmt = $db->prepare(
                            'INSERT IGNORE INTO tblWorkSongs (WorkId, SongId, IsCanonical, SortOrder)
                             VALUES (?, ?, 0, 0)'
                        );
                        $linkStmt->bind_param('is', $workId, $songId);
                        $linkStmt->execute();
                        $linkStmt->close();

                        $canonStmt = $db->prepare(
                            'SELECT 1 FROM tblWorkSongs WHERE WorkId = ? AND IsCanonical = 1 LIMIT 1'
                        );
                        $canonStmt->bind_param('i', $workId);
                        $canonStmt->execute();
                        $hasCanon = $canonStmt->get_result()->fetch_row() !== null;
                        $canonStmt->close();
                        if (!$hasCanon) {
                            $upd = $db->prepare(
                                'UPDATE tblWorkSongs SET IsCanonical = 1 WHERE WorkId = ? AND SongId = ?'
                            );
                            $upd->bind_param('is', $workId, $songId);
                            $upd->execute();
                            $upd->close();
                        }
                    }
                } catch (\Throwable $_e) {
                    if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #1679 A1 — see the first such guard above. */
                    /* Best-effort — Works linkage must never block the
                       core song save. The user's edit is already
                       captured in tblSongs + tblSongRevisions. */
                    error_log('[editor save_song] Works auto-link failed: ' . $_e->getMessage());
                }
            }

            /* ------------------------------------------------------------------
             * Translation links (#352) — tblSongTranslations  [restored in #1626]
             * ------------------------------------------------------------------
             * ELI5: the editor's Translations panel lets a curator say "this song
             * is the same hymn as SDAH-123, but in Spanish". Before #1626 the
             * whole-song save never wrote that table, so every link a curator
             * staged was thrown away on save while the UI cheerfully toasted
             * "Translation link added." — silent data loss.
             *
             * WHY it broke: the only writers were the legacy api.php
             * add_translation / remove_translation endpoints, which have ZERO JS
             * call sites (fully implemented, never wired). When the whole-corpus
             * `save` was tombstoned (#1016) the per-record save_song became the
             * only write path, and the translations write never came across.
             * Fixing it HERE — in the shared core — means both the legacy editor
             * (api.php) and the v2 editor (api2.php) get it, per the CLAUDE.md
             * modularity rule, rather than resurrecting the orphaned endpoints.
             *
             * SEMANTICS (directional, NOT reciprocal — this matches every existing
             * reader/writer, so no guess was needed):
             *   - api.php get_translations reads `WHERE t.SourceSongId = ?`
             *   - SongData::_getTranslations() — the shape this very payload is
             *     LOADED from — reads `WHERE SourceSongId = ?`
             *   - api.php add_translation INSERTs exactly ONE row, no counterpart
             * So the rows owned by this save are precisely those with
             * SourceSongId = this song. The public song page's
             * SongData::getSongTranslations() UNIONs outward + inward + sibling
             * links for DISPLAY, but that is a read-side convenience — it does not
             * make the storage bidirectional, and we must not invent reverse rows
             * here (that would fight the (SourceSongId, TargetLanguage) UNIQUE key
             * from the other song's side and make its own save delete them again).
             *
             * DIFF, not DELETE-all + re-INSERT. Two concrete reasons:
             *   1. tblSongTranslations carries curator state this payload does not
             *      model — `Translator` (NOT NULL DEFAULT '') and `Verified`. A
             *      blunt delete/re-insert would silently reset both on every
             *      unrelated save of the song.
             *   2. Id stability. Nothing FKs to tblSongTranslations.Id today
             *      (verified: no `REFERENCES tblSongTranslations` anywhere in
             *      appWeb/.sql), but the legacy remove_translation endpoint keys
             *      off it and churning the PK on every save is gratuitous.
             *
             * The diff is keyed on TargetLanguage because that is what the table's
             * `uq_Translation (SourceSongId, TargetLanguage)` UNIQUE key allows to
             * exist at most once — one translation per language per source song.
             * @see https://dev.mysql.com/doc/refman/8.0/en/create-index.html
             * ------------------------------------------------------------------ */
            $translationWarnings = [];
            /* ABSENT key ⇒ do nothing at all, not even a read. This is the safety
               valve: a caller that does not manage the collection (a slim stub, an
               importer, a future client) must never have a curator's existing links
               deleted out from under it. An EMPTY ARRAY is different — it means the
               client is managing the collection and currently holds none, which is
               exactly how "the curator removed the last link" arrives. */
            if (is_array($song['translations'] ?? null)) {
                try {
                    if (_songTranslationsTableExists($db)) {
                        /* ---- 1. Normalise the desired set, keyed by language ----
                           The client sends [{songId, language}, …] — the same shape
                           SongData::_getTranslations() emits, so the round trip is
                           symmetrical. Keyed case-insensitively because the column
                           collation (utf8mb4_unicode_ci) makes the UNIQUE key
                           case-insensitive too; last-one-wins on a collision
                           mirrors the legacy endpoint's
                           `ON DUPLICATE KEY UPDATE TranslatedSongId = VALUES(…)`. */
                        $desired = [];
                        foreach ($song['translations'] as $tr) {
                            if (!is_array($tr)) { continue; }
                            $tId   = trim((string)($tr['songId']   ?? ''));
                            $tLang = trim((string)($tr['language'] ?? ''));
                            if ($tId === '' || $tLang === '') { continue; }
                            /* A song cannot be a translation of itself — the same
                               rule the legacy add_translation endpoint enforced. */
                            if (strcasecmp($tId, $songId) === 0) {
                                $translationWarnings[] = 'A song cannot be a translation of itself — skipped.';
                                continue;
                            }
                            $desired[mb_strtolower($tLang)] = ['songId' => $tId, 'language' => $tLang];
                        }

                        /* ---- 2. Pre-validate against both FK parents ----
                           tblSongTranslations has FKs to tblSongs (both id columns)
                           and to tblLanguages.Code. tblSongs.Language is a free-text
                           BCP-47 tag (`pt-BR`, `zh-Hans-CN`) while tblLanguages.Code
                           holds IANA SUBTAGS, so a composite tag copied off the
                           target song WILL violate fk_Trans_Lang. Validating up front
                           means we never issue a doomed statement — a curator's
                           lyrics edit is never lost to an unlinkable language code. */
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
                            $langOk = [];
                            $lp   = implode(',', array_fill(0, count($wantLangs), '?'));
                            $lStm = $db->prepare("SELECT Code FROM tblLanguages WHERE Code IN ($lp)");
                            $lStm->bind_param(str_repeat('s', count($wantLangs)), ...$wantLangs);
                            $lStm->execute();
                            $lRes = $lStm->get_result();
                            /* Key on the lowercased tag but keep the table's CANONICAL
                               casing as the value, so we store `en`, not `EN`. */
                            while ($lRow = $lRes->fetch_assoc()) { $langOk[mb_strtolower($lRow['Code'])] = $lRow['Code']; }
                            $lStm->close();

                            $idOk = [];
                            /* @deleted-visible: write-path FK pre-check (#1694)
                               — a translation link naming a hidden song must
                               SURVIVE the save (dropping it would silently
                               destroy data that comes back on restore). */
                            $ip   = implode(',', array_fill(0, count($wantIds), '?'));
                            $iStm = $db->prepare("SELECT SongId FROM tblSongs WHERE SongId IN ($ip)");
                            $iStm->bind_param(str_repeat('s', count($wantIds)), ...$wantIds);
                            $iStm->execute();
                            $iRes = $iStm->get_result();
                            while ($iRow = $iRes->fetch_assoc()) { $idOk[mb_strtolower($iRow['SongId'])] = $iRow['SongId']; }
                            $iStm->close();

                            foreach ($desired as $key => $d) {
                                if (!isset($langOk[mb_strtolower($d['language'])])) {
                                    $translationWarnings[] = 'Language "' . $d['language']
                                        . '" is not in the languages table — translation link to '
                                        . $d['songId'] . ' skipped.';
                                    unset($desired[$key]);
                                    continue;
                                }
                                if (!isset($idOk[mb_strtolower($d['songId'])])) {
                                    $translationWarnings[] = 'Song "' . $d['songId']
                                        . '" no longer exists — translation link skipped.';
                                    unset($desired[$key]);
                                    continue;
                                }
                                /* Adopt the canonical stored spellings. */
                                $desired[$key]['language'] = $langOk[mb_strtolower($d['language'])];
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
                            $existing[mb_strtolower((string)$exRow['TargetLanguage'])] = [
                                'id'     => (int)$exRow['Id'],
                                'songId' => (string)$exRow['TranslatedSongId'],
                            ];
                        }
                        $exStm->close();

                        /* ---- 4. Apply the diff ----
                           A song with no translation links (the overwhelmingly common
                           case) reaches here with both sides empty and performs ZERO
                           writes — the save path stays behaviourally identical to
                           before #1626 for every song that has never used the panel. */
                        foreach ($existing as $langKey => $ex) {
                            if (isset($desired[$langKey])) { continue; }
                            $dStm = $db->prepare('DELETE FROM tblSongTranslations WHERE Id = ?');
                            $dStm->bind_param('i', $ex['id']);
                            $dStm->execute();
                            $dStm->close();
                        }
                        foreach ($desired as $langKey => $d) {
                            if (isset($existing[$langKey])) {
                                /* Re-pointed to a different song: UPDATE in place so
                                   the row's Translator / Verified / CreatedAt survive. */
                                if (strcasecmp($existing[$langKey]['songId'], $d['songId']) !== 0) {
                                    $uStm = $db->prepare(
                                        'UPDATE tblSongTranslations SET TranslatedSongId = ? WHERE Id = ?'
                                    );
                                    $uStm->bind_param('si', $d['songId'], $existing[$langKey]['id']);
                                    $uStm->execute();
                                    $uStm->close();
                                }
                                continue; /* unchanged ⇒ no write at all */
                            }
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
                    }
                } catch (\Throwable $_e) {
                    if (songRelocateIsTransactionFatal($_e)) { throw $_e; }   /* #1679 A1 — see the first such guard above. */
                    /* Best-effort, exactly like the Works auto-link above: a
                       translation-link problem must never cost the curator the
                       lyrics/credits edit they actually came here to make. The
                       warning is surfaced to the client so the failure is VISIBLE
                       (the whole point of #1626 was a silent no-op). */
                    error_log('[editor save_song] translation links failed: ' . $_e->getMessage());
                    $translationWarnings[] = 'Translation links could not be saved — see server logs.';
                }
            }

            /* #1235 P4/C5 — the tblLyricLines mirror was already written, in-place and
               Id-stably, by lyricLinesWriteComponents() above (mirrored install) — so
               there is NO separate projector call here anymore (the old
               lyricLinesProjectSong() RE-READ LinesJson, which is not drop-safe). The
               un-migrated (no-mirror) branch has no mirror to sync. */

            $db->commit();

            /* WS-J #1020: no songs.json cache to refresh — all reads are now
               live MySQL (editor sidebar via load_index, songbook export via
               songbook_export, the PWA via the slim songs_index). The corpus
               file cache + its regeneration hooks were removed. */

            /* $action is 'create' or 'edit' here (reassigned in the txn) — preserve
               the EXACT legacy wire response, which emitted that, not 'save_song'.
               #1380 — when a draft id was promoted to a canonical id, also return
               assignedId (the new canonical id) + previousId (the draft id the
               client sent) so editor.js can relabel its in-memory song without a
               reload. #1679 reuses the SAME pair for a songbook MOVE (the id is
               re-keyed to the new book's prefix), which is precisely why no client
               change was needed for the v1 editor. Both are OMITTED on a normal
               save, so the wire shape is byte-identical for the common case. */
            $respBody = ['ok' => true, 'songId' => $songId, 'action' => $action];
            if ($assignedId !== null) {
                $respBody['assignedId'] = $assignedId;
                $respBody['previousId'] = $previousId;
                /* #1679 F5 — the AUTHORITATIVE Number that was just written, sent
                   only alongside a rename. Both renaming paths change it and the
                   client cannot infer which happened: a MOVE clears it to null,
                   while a #1380 draft promotion into an official book ADOPTS the
                   slot the mint chose. editor.js held the value the curator had
                   typed, so its very next save posted the stale number straight
                   back — silently undoing the clear, and able to collide in the
                   target book (tblSongs has only INDEX idx_SongbookNumber, not a
                   UNIQUE key, so nothing would even complain).
                   Sent as a fact rather than left to be re-derived: two files
                   agreeing by reasoning is the failure mode rule #35 names. */
                $respBody['number'] = $number;
            }
            /* #1626 — only present when a staged translation link could NOT be
               persisted (unknown language tag, vanished target song, self-link).
               OMITTED entirely on the happy path, so the wire shape stays
               byte-identical for every save that has nothing to report. The client
               toasts these; a link that cannot be stored must never fail silently
               again — that silence IS the bug this restored. */
            if ($translationWarnings !== []) {
                $respBody['translationWarnings'] = array_values(array_unique($translationWarnings));
            }
            return ['status' => 200, 'body' => $respBody];
        } catch (\Throwable $e) {
            if (isset($db) && $db instanceof mysqli) {
                try { $db->rollback(); } catch (\Throwable $_) {}
            }
            error_log('[editor save_song] ' . $e->getMessage());

            /* Capture the failure in tblActivityLog as a Result='error'
               row so curators reviewing the audit log see a record of
               the failed save alongside successful ones (#759). The
               helper is best-effort — a logging failure must not
               compound the original error. */
            if (function_exists('logActivityError')) {
                $mysqliCode = ($e instanceof \mysqli_sql_exception) ? $e->getCode() : null;
                logActivityError(
                    'song.save_failed',
                    'song',
                    $songId,
                    $e,
                    [
                        'action'        => $action,
                        'songbook'      => $songbookAbbr,
                        'mysqli_code'   => $mysqliCode,
                    ]
                );
            }

            /* Surface the underlying error to admin / global_admin
               users so they can self-diagnose without server-shell
               access. Lower-privilege users still see the generic
               message — DB internals are not for general consumption.
               (#759) */
            $payload = ['error' => 'Failed to save song. Check server logs for details.'];
            /* #1679 A8 — the one failure whose explanation belongs to EVERY role.
               `error_detail` below is admin-only on purpose (raw mysqli text,
               file paths, line numbers). The songbook-move refusal is different
               in kind: an environment fault with no sensitive content, whose
               entire value is the sentence naming the migration card to run.
               Both editor funnels admit the `editor` role while that detail
               channel is gated on `admin`, so without a separate ungated key the
               person most likely to hit it saw "Failed to save song. Check server
               logs" — no better than the raw ER_ROW_IS_REFERENCED_2 the refusal
               replaced. Matched on the exception TYPE, never on its wording
               (rule #35); the admin-only channel is deliberately NOT widened. */
            if ($e instanceof SongRelocateEnvironmentException) {
                $payload['error_hint'] = $e->getMessage();
            }
            /* The original case body read the module-level $currentUser that
               api.php establishes at file scope. Inside this shared function
               that variable is out of scope, so re-resolve the SAME value via
               getCurrentUser() — the identical call both api.php and api2.php
               use to populate their own $currentUser. Behaviour-preserving. */
            $currentUser = getCurrentUser();
            $role    = is_array($currentUser) ? ($currentUser['role'] ?? null) : null;
            if (in_array($role, ['admin', 'global_admin'], true)) {
                $payload['error_detail'] = $e->getMessage();
                $payload['error_class']  = get_class($e);
                if ($e instanceof \mysqli_sql_exception) {
                    $payload['mysqli_code'] = $e->getCode();
                }
            }
            return ['status' => 500, 'body' => $payload];
        }
}
}
