<?php

declare(strict_types=1);

/**
 * iHymns — Voice-marker review queue: scan, list, accept, dismiss, undo (#2073 commit 14)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5: a lot of OLD lyric text has a line like "WOMEN" or "MEN: You are
 * holy," typed straight into the words, because whoever typed the song up
 * years ago had no other way to say "the women sing this bit". This file
 * finds those lines (by asking the ALREADY-BUILT detector in
 * `vocal_part_detect.php` — this file never re-guesses what a marker word
 * means), writes each one down as a SUGGESTION for a curator to look at,
 * and — once a curator says "yes, do that" — actually does it: assigns the
 * real voice part to the real lines, and tidies up the marker text so it
 * doesn't sit there looking like a sung line any more. If a curator ever
 * changes their mind, "Undo" puts it back exactly the way it was.
 *
 * DETAILED — WHY A SUGGESTION QUEUE AND NOT AN AUTOMATIC FIX: #2073's own
 * rule for this whole feature is that a plain-text HEURISTIC (a guess from
 * the shape of the words) may only ever produce a SUGGESTION, never change
 * a song on its own — a false positive silently rewriting a real lyric
 * line would be far worse than a missed marker sitting in a review list a
 * little longer (the same "wrong is worse than missing" principle the
 * #2073 task brief states for per-line data generally). Every row this
 * file writes lands in `tblVocalPartSuggestions` (`appWeb/.sql/migrate-
 * vocal-parts-rounds.php`, commit 2) with `Status='pending'` and sits there
 * until a human calls `vocalPartReviewAccept()` or `…Dismiss()`.
 *
 * THE THREE FORMS THIS FILE ACTUALLY HANDLES — READ THIS BEFORE TOUCHING
 * `Form`: `includes/vocal_part_detect.php`'s OWN `IHYMNS_VOCAL_DETECT_FORMS`
 * constant (the file this one is required to call, never fork) lists only
 * `['standalone', 'prefix', 'paren']` — a fourth shape ("IN CANON" / "AS A
 * ROUND" written on its own line) was DESCRIBED in early design passes of
 * `.claude/vocal-parts-2073-plan.md` (and is still mentioned in this
 * table's own migration-file COMMENT text as `canon-note`) but was NEVER
 * IMPLEMENTED in the detector that actually shipped. Per this task's own
 * instruction to call the landed detector rather than re-invent it, this
 * file follows the DETECTOR'S real vocabulary, not the older design note —
 * `canon-note` is dead prose in a comment, not a live code path, and this
 * file's own report (see the parent conversation this shipped in) flags
 * that mismatch for a future pass rather than quietly inventing a fourth
 * branch nothing calls.
 *
 * THE PURE CORE, AND WHY SO MUCH OF THIS FILE NEEDS NO DATABASE AT ALL
 * (mirrors `tests/php/test-vocal-parts-core.php`'s own stated reasoning —
 * this repo's CI PHP image has no MySQL/MariaDB, so a DECISION has to live
 * in a function that takes plain arrays before it can be proven by a truth
 * table): every genuinely interesting judgement call this file makes —
 * "how far does a WOMEN marker's run of lines extend", "what should Accept
 * actually DO for this one finding", "has a re-scan re-found the SAME
 * marker or has it moved/vanished", "is this scan-vs-existing-row pair an
 * insert, an update, or a hands-off skip" — is pushed into a small, pure,
 * `\mysqli`-free function below. The functions that DO take a `\mysqli $db`
 * are thin: they fetch rows, hand the plain-array facts to a pure decision
 * function, and write back whatever that function decided. `tests/php/
 * test-vocal-part-review.php` truth-tables every one of the pure functions;
 * the DB-touching ones are covered by manual / staging verification, same
 * posture as `vocal_parts.php`'s own write half.
 *
 * TRANSACTIONS — THIS FILE STARTS NONE OF ITS OWN, same convention as
 * `vocal_parts.php` / `lyric_rounds.php` (grep either file for
 * `begin_transaction` — neither has one): `vocalPartReviewAccept()` and
 * `…Undo()` run several related statements that want to succeed or fail
 * together, but per this codebase's established split between a CORE file
 * (assumes the caller already opened a transaction) and an ENDPOINT file
 * (opens one), that responsibility belongs to the future admin/API layer
 * (#2073 commit 15, explicitly out of this commit's scope) exactly the way
 * `manage/editor/api2.php`'s existing vocal-part cases wrap `vocal_parts.php`
 * calls in their own `begin_transaction()`/`commit()`. The migration's own
 * batch scan (`vocalPartReviewScanSong()`, called once per song) needs no
 * transaction of its own — every write it makes is a single, independent,
 * already-atomic `INSERT`/`UPDATE` statement, the same posture `migrate-
 * reconcile-media-flags.php` (#1862) uses for its own per-song writes.
 *
 * IDOR — every function that takes a `$suggestionId` resolves it via
 * `vocalPartReviewResolveRow()`, which requires the row's OWN `SongId` to
 * equal the `$songId` the caller named (mirrors `vocalPartsResolvePart()`'s
 * identical convention in `vocal_parts.php`) — a caller can never act on
 * another song's suggestion by guessing an id.
 *
 * STATUS CODES (rule #35 of .claude/CLAUDE.md — branch on a STRUCTURED
 * signal, never a regex over a sentence): a "not found for this song" is a
 * plain `\RuntimeException` (the existing `vocalPartsResolvePart()` 404
 * convention); bad input is `\InvalidArgumentException` (400); a row that
 * exists but is in the WRONG STATE for the action asked of it (accepting
 * an already-dismissed suggestion, undoing one that was never accepted, a
 * race where the marker line vanished between scan and accept) throws the
 * new `VocalPartReviewConflictException` below, carrying a machine-readable
 * `$reason` a future endpoint maps to HTTP 409 — never a message string a
 * caller would otherwise have to pattern-match.
 *
 * PER-LINE IDENTITY (the task brief's own warning, repeated here because
 * this file is exactly the kind of code that warning is about): nothing in
 * this file ever assumes a line still sits at the array position it was
 * detected at. `vocalPartReviewLocateLine()` re-finds a line by its real
 * `tblLyricLines.Id` every time, and `vocalPartReviewAccept()` re-reads
 * `lyricLinesEditableComponents()` AFTER every line-content write before
 * trusting a line's new id for the voice assignment that follows it — the
 * exact "rewrite first, then look up by position in the FRESH read" shape
 * "Design pass 7" §3.5 itself prescribes for the `prefix` form, because
 * `lyricLinesDiff()`'s Id-preserving match is a CONTENT-similarity guess
 * (see that function's own doc-block), not a guarantee — this file never
 * treats "I remember what id used to be here" as good enough on its own.
 *
 * @see appWeb/public_html/includes/vocal_part_detect.php   the ONE detector this file calls, never re-implements
 * @see appWeb/public_html/includes/vocal_parts.php          the ONE part/line-assignment write core this file calls
 * @see appWeb/public_html/includes/lyric_lines_sync.php     lyricLinesWriteComponents() — the ONE line-content write path (rule #25)
 * @see appWeb/.sql/migrate-vocal-parts-rounds.php            tblVocalPartSuggestions — see its own header for the F1/F2 cross-review that shaped ProposedJson/DetectionLineId/MarkerOffset/uq_Detection
 * @see appWeb/.sql/migrate-backfill-vocal-part-suggestions.php   the batch that calls vocalPartReviewScanSong() over the whole catalogue
 * @see tests/php/test-vocal-part-review.php                  the truth table over every pure function below
 * @see .claude/vocal-parts-2073-plan.md                      the plan of record ("Design pass 7" §3.5, "Design pass 6" §6 — see this file's own note above on where reality (the shipped detector) diverges from that plan's `canon-note` form)
 * @see #2073, #2075, #1260
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* The three cores this file builds on. All three are documented lazy-
   connect / side-effect-free requires (see each file's own doc-block) —
   requiring them here opens no connection and runs no query. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';           // bindParamSafe()
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_parts.php';        // the part/line-assignment write core + vocalPartsTablesReady()
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_part_detect.php';  // the PURE marker detector this file calls, never re-implements
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';   // lyricLinesPrimaryLyricsId() / lyricLinesEditableComponents()

/* =====================================================================
 * VOCABULARY (rule #20 — VARCHAR-shaped, app-validated, never an ENUM;
 * these mirror the growable columns migrate-vocal-parts-rounds.php's own
 * header explains were deliberately made VARCHAR for exactly this reason).
 * ===================================================================== */

/** `tblVocalPartSuggestions.Status`'s five legal values. */
const IHYMNS_VOCAL_REVIEW_STATUSES = ['pending', 'accepted', 'dismissed', 'undone', 'stale'];

/** `tblVocalPartSuggestions.Confidence`'s three legal values. */
const IHYMNS_VOCAL_REVIEW_CONFIDENCES = ['high', 'medium', 'low'];

/**
 * The `action` key every `ProposedJson` / `AppliedJson` list item carries —
 * see the migration file's own `ProposedJson` COMMENT (its "F1" cross-
 * review note) for why this is a list of actions, not a single one: a
 * `standalone` marker typically needs BOTH an assignment AND a marker-line
 * removal, and neither alone tells Undo everything it needs to reverse.
 */
const IHYMNS_VOCAL_REVIEW_ACTIONS = ['assign-lines', 'rewrite-marker-line', 'remove-marker-line'];

/**
 * Thrown when a review-queue action is refused because the row exists but
 * is not in the STATE that action needs — accepting an already-dismissed
 * suggestion, undoing one that was never accepted, or a race where the
 * marker line vanished between when it was queued and when a curator
 * clicked Accept. Deliberately its OWN exception class rather than a
 * `\RuntimeException` with a particular message: rule #35 of
 * .claude/CLAUDE.md bans distinguishing failure KINDS by matching the
 * server's sentence, so a future endpoint (#2073 commit 15) catches THIS
 * class specifically — before the generic `\RuntimeException` → 404
 * fallback every other core in this feature already uses — and maps it to
 * HTTP 409 with `$reason` as the machine-readable payload key, the same
 * `err.status` / structured-reason shape `manage/editor/v2/api-client.js`
 * already expects from this codebase's other write cores.
 */
final class VocalPartReviewConflictException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}

/* =====================================================================
 * READINESS
 * ===================================================================== */

/**
 * Is the review queue usable end to end? Requires BOTH `tblVocalPartSuggestions`
 * (this feature's own table) AND the #1137 vocal-parts trio (`vocalPartsTablesReady()`)
 * — Accept has to be able to create/find a part and assign it to lines, so a
 * queue table with no part registry to write into is not actually usable,
 * even though the two migrations are independent and either can land first.
 */
function vocalPartReviewReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblVocalPartSuggestions'"
        );
        $row = $r ? $r->fetch_row() : null;
        $tableOk = ($row !== null && (int)$row[0] >= 1);
        if ($r) {
            $r->close();
        }
        $ready = $tableOk && vocalPartsTablesReady($db);
    } catch (\Throwable $_e) {
        if (function_exists('songRelocateIsTransactionFatal') && songRelocateIsTransactionFatal($_e)) {
            throw $_e;
        }
        $ready = false;
    }
    return $ready;
}

/* =====================================================================
 * PURE — detection -> proposal (no DB; tests/php/test-vocal-part-review.php
 * truth-tables every function in this section directly).
 * ===================================================================== */

/**
 * ELI5: once we know a bare line reads "WOMEN" and nothing else, how far
 * does that assignment run? Answer: every line right after it, in the SAME
 * song section, up to (but not including) the next line that ALSO looks
 * like a voice cue — or the end of the section if there isn't one. A
 * marker that is the very last line of its section, or is immediately
 * followed by another marker, governs nothing and is never queued.
 *
 * @param list<int> $markerIndexesInComponent  0-based line indexes of
 *        EVERY finding (any form) inside this ONE component, in any order.
 * @param int $markerLineIndex  the standalone marker's own line index.
 * @param int $componentLineCount  total lines in this component.
 * @return array{0:int,1:int}|null  [firstIndex, lastIndex] inclusive of the
 *         run this marker governs, or null when there is nothing to govern.
 */
function vocalPartReviewStandaloneRunBounds(array $markerIndexesInComponent, int $markerLineIndex, int $componentLineCount): ?array
{
    $first = $markerLineIndex + 1;
    if ($first >= $componentLineCount) {
        return null;   // the marker is the last line of its section
    }
    $last = $componentLineCount - 1;
    $indexes = array_values(array_unique(array_map('intval', $markerIndexesInComponent)));
    sort($indexes);
    foreach ($indexes as $idx) {
        /* Defensive floor: a real caller only ever passes indexes this
           component actually has (they come straight from the detector's
           own `lineIndex` on THIS component's lines), so `$idx` should
           never reach here >= `$componentLineCount` — but if it somehow
           did, treating it as a real boundary would EXTEND the run past
           the end of the component's own `lines` array. Skipping it keeps
           the already-correct `$last` default instead. */
        if ($idx > $markerLineIndex && $idx < $componentLineCount) {
            $last = $idx - 1;
            break;
        }
    }
    return ($last >= $first) ? [$first, $last] : null;   // two markers back-to-back govern nothing
}

/**
 * ELI5: given what the detector found on one line, build the exact
 * ordered TODO list Accept must run — "assign these lines to this part"
 * and/or "the marker line itself needs to go/change".
 *
 * `standalone`: assign the governed run (when any), then remove the
 * marker line — it carries no real lyric, just the cue.
 * `prefix`: rewrite the marker line down to its lyric remainder FIRST,
 * then assign that same (now-clean) line — order matters for the AUDIT
 * trail this produces, even though the two underlying writes do not
 * depend on running in this order.
 * `paren`: assign the marker line as-is (background/echo) — the
 * parenthetical text is left exactly as typed; nothing about a paren
 * form is a cue to REMOVE anything, only to mark WHO sings it.
 *
 * @param list<int> $targetLineIds  the lines this part governs; empty for
 *        `standalone` when `vocalPartReviewStandaloneRunBounds()` found
 *        nothing to govern (Accept then only removes the marker line).
 * @return list<array<string,mixed>>  ProposedJson's `action` list (rule #20
 *         — every `action` value is one of `IHYMNS_VOCAL_REVIEW_ACTIONS`).
 */
function vocalPartReviewBuildProposal(
    string $form,
    int $markerLineId,
    string $kind,
    ?string $label,
    bool $isBackground,
    array $targetLineIds,
    ?string $rewriteText
): array {
    $targetLineIds = array_values(array_map('intval', $targetLineIds));
    $actions = [];

    if ($form === 'standalone') {
        if ($targetLineIds) {
            $actions[] = [
                'action' => 'assign-lines', 'lineIds' => $targetLineIds,
                'partKind' => $kind, 'label' => $label, 'isBackground' => $isBackground,
            ];
        }
        $actions[] = ['action' => 'remove-marker-line', 'lineId' => $markerLineId];
    } elseif ($form === 'prefix') {
        $actions[] = ['action' => 'rewrite-marker-line', 'lineId' => $markerLineId, 'text' => (string)$rewriteText];
        $actions[] = [
            'action' => 'assign-lines', 'lineIds' => [$markerLineId],
            'partKind' => $kind, 'label' => $label, 'isBackground' => $isBackground,
        ];
    } elseif ($form === 'paren') {
        $actions[] = [
            'action' => 'assign-lines', 'lineIds' => [$markerLineId],
            'partKind' => $kind, 'label' => $label, 'isBackground' => $isBackground,
        ];
    }
    /* An unrecognised form proposes nothing rather than guessing — the
       caller (vocalPartReviewScanSong()) never reaches here for a form the
       real detector cannot produce in the first place (see this file's own
       doc-block on the retired `canon-note` shape), but a future detector
       version adding a form this file has not been taught yet degrades to
       "queue it with an empty TODO list" rather than a fatal or a guess. */

    return $actions;
}

/**
 * ELI5: turn one detector finding (plus the real line ids it resolves to)
 * into the exact row `tblVocalPartSuggestions` wants — every column this
 * file's INSERT/UPDATE needs, computed in ONE place so the migration's
 * insert path and its update-on-rescan path can never quietly disagree
 * about what a finding means.
 *
 * `markerOffset` is hard-coded to 0: every one of the three forms the real
 * detector (`vocal_part_detect.php`) implements matches from the START of
 * a whole line (`^...` in each of its three regexes) — it can never report
 * two markers on one line today. The column exists for a FUTURE, finer-
 * grained detector (see the migration's own "F2" cross-review note); this
 * function does not pretend to support that yet.
 *
 * @param list<int> $targetLineIds  see `vocalPartReviewBuildProposal()`.
 * @param ?string   $rewriteText    only for `prefix` — the lyric text left
 *        after the marker word is stripped (the detector's own `rest`).
 * @return array<string,mixed>  {songId,lyricsId,markerLineId,detectionLineId,
 *         markerOffset,startLineId,endLineId,form,markerText,partKind,label,
 *         isBackground,confidence,detectorVersion,proposedJson}
 */
function vocalPartReviewBuildRow(
    string $songId,
    int $lyricsId,
    int $markerLineId,
    string $form,
    string $marker,
    string $kind,
    ?string $label,
    bool $isBackground,
    string $confidence,
    array $targetLineIds,
    ?string $rewriteText = null
): array {
    $targetLineIds = array_values(array_map('intval', $targetLineIds));
    $confidence    = in_array($confidence, IHYMNS_VOCAL_REVIEW_CONFIDENCES, true) ? $confidence : 'medium';

    return [
        'songId'          => $songId,
        'lyricsId'        => $lyricsId,
        'markerLineId'    => $markerLineId,
        'detectionLineId' => $markerLineId,   // the STABLE snapshot id (F2) — never re-pointed once written
        'markerOffset'    => 0,
        'startLineId'     => $targetLineIds ? $targetLineIds[0] : null,
        'endLineId'       => $targetLineIds ? (int)end($targetLineIds) : null,
        'form'            => $form,
        'markerText'      => mb_substr($marker, 0, 120, 'UTF-8'),
        'partKind'        => $kind,
        'label'           => $label,
        'isBackground'    => $isBackground,
        'confidence'      => $confidence,
        'detectorVersion' => IHYMNS_VOCAL_DETECT_VERSION,
        'proposedJson'    => vocalPartReviewBuildProposal($form, $markerLineId, $kind, $label, $isBackground, $targetLineIds, $rewriteText),
    ];
}

/**
 * The `uq_Detection (DetectionLineId, Form, MarkerOffset)` key, as a plain
 * string — ONE place both the scan's in-memory de-duplication and its
 * against-the-database reconciliation build this key, so they can never
 * drift into two different ideas of "the same finding" (rule #35).
 */
function vocalPartReviewDetectionKey(int $detectionLineId, string $form, int $markerOffset): string
{
    return $detectionLineId . '|' . $form . '|' . $markerOffset;
}

/**
 * ELI5: a scan just found (or failed to find) a suggestion that may
 * already be sitting in the table — what should happen to it?
 *
 * `null` (nothing stored yet) -> `'insert'`. `'pending'` or `'stale'` (this
 * scan re-confirms it, or it had gone stale and is back) -> `'update'` —
 * a re-run REFRESHES a still-open suggestion's detail rather than leaving
 * a second, duplicate row (the task's own "idempotent" requirement).
 * Anything a human has already acted on (`'accepted'`, `'dismissed'`,
 * `'undone'`) -> `'skip'`, UNCONDITIONALLY: this migration's whole point is
 * to propose things for review, never to silently reopen or overwrite a
 * decision a curator already made.
 */
function vocalPartReviewScanDecision(?string $existingStatus): string
{
    if ($existingStatus === null) {
        return 'insert';
    }
    if (in_array($existingStatus, ['pending', 'stale'], true)) {
        return 'update';
    }
    return 'skip';
}

/**
 * ELI5: a suggestion that is still sitting in the queue, waiting for a
 * curator — should THIS scan flip it to `'stale'`? Only when it is
 * genuinely still `'pending'` (an `'accepted'`/`'dismissed'`/`'undone'`/
 * already-`'stale'` row is left exactly alone either way) AND this fresh
 * scan did NOT reproduce its exact detection key — meaning the marker line
 * it was found on has since been edited, reordered past recognition, or
 * deleted, so the row's frozen snapshot (`MarkerText`, `StartLineId`/
 * `EndLineId`) can no longer be trusted to describe what is really there.
 */
function vocalPartReviewShouldStale(string $status, bool $keyStillDetected): bool
{
    return $status === 'pending' && !$keyStillDetected;
}

/**
 * ELI5: before Accept is allowed to delete or rewrite a marker line, make
 * sure the WORDS on that line are still what the suggestion thinks they
 * are — not just that the line's id still exists. An id can survive an
 * edit even though every word on the line changed (identity is not
 * content — the same lesson rule #51 states for lines in general): a
 * curator could type something completely different into the line and,
 * without this check, Accept would still delete it (standalone) or paste
 * the OLD leftover lyric text back over it (prefix), because all it used
 * to check was "does this id still exist".
 *
 * Re-runs the SAME detector this whole file exists to call rather than
 * re-implement (`vocalPartDetectClassifyLine()`) against the line's
 * CURRENT text, and requires it to report the IDENTICAL finding: the same
 * form, the same marker word, and — for `prefix`, the one form where the
 * marker is only part of the line — the same leftover text after the
 * marker. Anything else (the line no longer reads as a marker at all, a
 * different word, or different words after the colon) means the line has
 * genuinely changed since detection, so the caller must treat the
 * suggestion as stale instead of acting on a snapshot that no longer
 * matches reality.
 *
 * @param string $form           the suggestion's own recorded Form
 * @param string $currentText    the marker line's CURRENT text, read fresh
 * @param string $expectedMarker the suggestion's own recorded MarkerText
 * @param string $expectedRest   `prefix` only: the lyric text ProposedJson
 *        recorded as following the marker at detection time; '' for every
 *        other form (a `standalone` line and a `paren` line have no
 *        "rest" — the whole line, or the whole parenthetical, IS the
 *        marker, so nothing follows it to compare)
 */
function vocalPartReviewTextStillMatches(string $form, string $currentText, string $expectedMarker, string $expectedRest): bool
{
    $finding = vocalPartDetectClassifyLine($currentText);
    if ($finding === null) {
        return false;
    }
    if ((string)($finding['form'] ?? '') !== $form) {
        return false;
    }
    /* Mirrors how MarkerText itself was built at detection time
       (`vocalPartReviewBuildRow()`) so a marker at the storage column's
       own 120-char cap still compares equal rather than false-flagging
       every long marker as "changed". */
    $currentMarker = mb_substr((string)($finding['marker'] ?? ''), 0, 120, 'UTF-8');
    if ($currentMarker !== $expectedMarker) {
        return false;
    }
    return (string)($finding['rest'] ?? '') === $expectedRest;
}

/* =====================================================================
 * PURE — line-content edits Accept/Undo apply through the ONE write path
 * (`lyricLinesWriteComponents()`, rule #25). Every function below only
 * REBUILDS the plain-array shape that function already accepts; none of
 * them touch a database.
 * ===================================================================== */

/**
 * ELI5: find which section (component) and which position within it one
 * real line id currently lives at, in the editor's own read shape
 * (`lyricLinesEditableComponents()`) — the thing every write below does
 * FIRST, and re-does AFTER writing, rather than ever trusting a
 * remembered position (see this file's own doc-block on why).
 *
 * @param list<array{lineIds?:list<int>}> $editableComponents
 * @return array{0:int,1:int}|null  [componentIndex, lineIndex], or null
 *         when the line id is not present in ANY component any more.
 */
function vocalPartReviewLocateLine(array $editableComponents, int $lineId): ?array
{
    foreach ($editableComponents as $ci => $c) {
        $idx = array_search($lineId, $c['lineIds'] ?? [], true);
        if ($idx !== false) {
            return [(int)$ci, (int)$idx];
        }
    }
    return null;
}

/**
 * ELI5: strip one component (from the editor's read shape) down to just
 * the fields `lyricLinesWriteComponents()` actually wants, so every write
 * below rebuilds the SAME shape and can never accidentally drop a field
 * (label, per-line language override, …) it never meant to touch.
 */
function vocalPartReviewComponentForWrite(array $c): array
{
    return [
        'type'         => (string)($c['type'] ?? 'verse'),
        'number'       => (int)($c['number'] ?? 0),
        'language'     => $c['language'] ?? null,
        'lines'        => is_array($c['lines'] ?? null) ? array_values($c['lines']) : [],
        'chords'       => is_array($c['chords'] ?? null) ? array_values($c['chords']) : null,
        'notes'        => is_array($c['notes'] ?? null) ? array_values($c['notes']) : null,
        'languages'    => is_array($c['languages'] ?? null) ? array_values($c['languages']) : null,
        'label'        => $c['label'] ?? null,
        'sourceWorkId' => $c['sourceWorkId'] ?? null,
    ];
}

/** Drop index `$idx` from `lines` and (only where present) every parallel array. */
function vocalPartReviewRemoveParallelIndex(array $comp, int $idx): array
{
    foreach (['lines', 'chords', 'notes', 'languages'] as $key) {
        if (is_array($comp[$key] ?? null)) {
            $arr = $comp[$key];
            unset($arr[$idx]);
            $comp[$key] = array_values($arr);
        }
    }
    return $comp;
}

/** Insert `$value` at index `$idx`, shifting everything from `$idx` on up by one. */
function vocalPartReviewArrayInsertAt(array $arr, int $idx, mixed $value): array
{
    array_splice($arr, $idx, 0, [$value]);
    return $arr;
}

/** Insert one new line's text (plus a null cell in every parallel array present) at index `$idx`. */
function vocalPartReviewInsertParallelIndex(array $comp, int $idx, string $text): array
{
    $comp['lines'] = vocalPartReviewArrayInsertAt($comp['lines'], $idx, $text);
    foreach (['chords', 'notes', 'languages'] as $key) {
        if (is_array($comp[$key] ?? null)) {
            $comp[$key] = vocalPartReviewArrayInsertAt($comp[$key], $idx, null);
        }
    }
    return $comp;
}

/**
 * ELI5: rebuild the WHOLE song's components with ONE line either removed
 * or rewritten — the shape `lyricLinesWriteComponents()` needs to apply
 * that one change through the real write path, never a second one.
 *
 * @param list<array<string,mixed>> $editableComponents  `lyricLinesEditableComponents()`'s own return.
 * @param array{type:'remove-line'|'rewrite-line',lineId:int,text?:string} $op
 * @return list<array<string,mixed>>|null  the full components array ready
 *         for `lyricLinesWriteComponents()`, or null when `$op['lineId']`
 *         is not found in any component (the caller treats this as
 *         `VocalPartReviewConflictException('stale_line', ...)`).
 */
function vocalPartReviewApplyLineOp(array $editableComponents, array $op): ?array
{
    $lineId = (int)($op['lineId'] ?? 0);
    $kind   = (string)($op['type'] ?? '');
    $found  = false;
    $out    = [];

    foreach ($editableComponents as $c) {
        $comp = vocalPartReviewComponentForWrite($c);
        $idx  = array_search($lineId, $c['lineIds'] ?? [], true);
        if ($idx !== false) {
            $found = true;
            if ($kind === 'remove-line') {
                $comp = vocalPartReviewRemoveParallelIndex($comp, (int)$idx);
            } elseif ($kind === 'rewrite-line') {
                $comp['lines'][(int)$idx] = (string)($op['text'] ?? '');
            }
        }
        $out[] = $comp;
    }
    return $found ? $out : null;
}

/**
 * ELI5: Undo's mirror of the `remove-line` op above — put a line of plain
 * text BACK, immediately before whichever real line id it used to sit in
 * front of.
 *
 * @return list<array<string,mixed>>|null  null when `$beforeLineId` is not
 *         found in any component any more (the caller falls back to
 *         appending at the end of the last component instead of losing
 *         the marker text outright — see `vocalPartReviewUndo()`).
 */
function vocalPartReviewInsertLineBefore(array $editableComponents, int $beforeLineId, string $text): ?array
{
    $found = false;
    $out   = [];
    foreach ($editableComponents as $c) {
        $comp = vocalPartReviewComponentForWrite($c);
        $idx  = array_search($beforeLineId, $c['lineIds'] ?? [], true);
        if ($idx !== false) {
            $found = true;
            $comp = vocalPartReviewInsertParallelIndex($comp, (int)$idx, $text);
        }
        $out[] = $comp;
    }
    return $found ? $out : null;
}

/* =====================================================================
 * DB — the batch scan (called once per song by the migration, and again
 * by vocalPartReviewRefreshSong() for one song at a time from a future
 * "re-scan this song" admin action).
 * ===================================================================== */

/**
 * ELI5: look at one song's lyrics right now, find every voice marker in
 * it, and make sure the review queue matches what is really there —
 * adding a row for a new finding, refreshing one still pending or gone
 * stale, leaving anything a human already decided on untouched, and
 * flagging any PENDING row whose marker has since moved or vanished as
 * `'stale'` so a curator sees it needs another look rather than acting on
 * a snapshot that no longer matches the real text.
 *
 * Calls the ALREADY-LANDED, PURE `vocalPartDetectSong()` — this function
 * never re-implements what counts as a marker.
 *
 * @param bool $dryRun  when true, computes and returns the exact counts a
 *        real run would produce, WITHOUT writing a single row — the
 *        migration's default mode.
 * @return array{found:int,inserted:int,updated:int,skipped:int,staled:int,byForm:array<string,int>}
 */
function vocalPartReviewScanSong(\mysqli $db, string $songId, bool $dryRun = false): array
{
    $counts = ['found' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'staled' => 0, 'byForm' => []];

    /* @lyrics-version-cache-ok: a plain read outside any transaction — this
       function never creates a lyrics version itself (it only reads
       components and writes to tblVocalPartSuggestions), so it runs no
       begin_transaction() of its own and there is nothing a rollback could
       invalidate. Identical reasoning to vocalPartsForSong()'s own marker
       one file over — see lyricLinesPrimaryLyricsId()'s "WHY A FOUND ROW…"
       doc-block for the read-vs-write distinction this lets a reviewer
       confirm at a glance. */
    $lyricsId = lyricLinesPrimaryLyricsId($db, $songId);
    if ($lyricsId <= 0) {
        return $counts;   // no primary ('ihymns') lyrics version to scan yet
    }

    $components = lyricLinesEditableComponents($db, $songId);
    if (!$components) {
        return $counts;
    }

    /* NOTE: an empty `$findings` here is NOT an early return — this
       function still falls through to the stale-check below. A song that
       USED to have a marker, and had it edited away by a curator since
       the last scan, needs its now-orphaned pending row flagged stale,
       not silently left claiming a line that no longer says what it says. */
    $findings = vocalPartDetectSong($components);

    /* Every finding's line index, grouped per component, so a `standalone`
       marker's run can stop at the NEXT marker in the SAME section
       (`vocalPartReviewStandaloneRunBounds()`). */
    $indexesByComponent = [];
    foreach ($findings as $f) {
        $indexesByComponent[$f['componentIndex']][] = $f['lineIndex'];
    }

    $rowsToWrite = [];   // detectionKey => built row
    foreach ($findings as $f) {
        $ci   = (int)$f['componentIndex'];
        $li   = (int)$f['lineIndex'];
        $comp = $components[$ci] ?? null;
        if ($comp === null) {
            continue;
        }
        $markerLineId = (int)($comp['lineIds'][$li] ?? 0);
        if ($markerLineId <= 0) {
            continue;   // defensive — every editable line carries a real id (rule #21); never true in practice
        }

        $targetLineIds = [];
        $rewriteText   = null;
        $form          = (string)$f['form'];

        if ($form === 'standalone') {
            $bounds = vocalPartReviewStandaloneRunBounds($indexesByComponent[$ci] ?? [], $li, count($comp['lines']));
            if ($bounds === null) {
                continue;   // governs nothing — never queue an empty-run standalone marker
            }
            [$first, $last] = $bounds;
            for ($k = $first; $k <= $last; $k++) {
                if (isset($comp['lineIds'][$k])) {
                    $targetLineIds[] = (int)$comp['lineIds'][$k];
                }
            }
        } elseif ($form === 'prefix') {
            $targetLineIds = [$markerLineId];
            $rewriteText   = (string)($f['rest'] ?? '');
        } elseif ($form === 'paren') {
            $targetLineIds = [$markerLineId];
        } else {
            continue;   // a form this file has not been taught (see doc-block) — queue nothing rather than guess
        }

        $built = vocalPartReviewBuildRow(
            $songId,
            $lyricsId,
            $markerLineId,
            $form,
            (string)$f['marker'],
            (string)$f['kind'],
            ($f['label'] ?? null) !== null ? (string)$f['label'] : null,
            (bool)$f['bg'],
            (string)$f['confidence'],
            $targetLineIds,
            $rewriteText
        );
        $key = vocalPartReviewDetectionKey($built['detectionLineId'], $built['form'], $built['markerOffset']);
        $rowsToWrite[$key] = $built;
        $counts['found']++;
        $counts['byForm'][$form] = ($counts['byForm'][$form] ?? 0) + 1;
    }

    /* --- Reconcile against what this lyrics version already has stored --- */
    $existingByKey = [];
    $sel = $db->prepare(
        'SELECT Id, DetectionLineId, Form, MarkerOffset, Status FROM tblVocalPartSuggestions WHERE LyricsId = ?'
    );
    bindParamSafe(__FUNCTION__ . ':select-existing', $sel, 'i', $lyricsId);
    $sel->execute();
    $existingRows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
    $sel->close();
    foreach ($existingRows as $er) {
        $k = vocalPartReviewDetectionKey((int)$er['DetectionLineId'], (string)$er['Form'], (int)$er['MarkerOffset']);
        $existingByKey[$k] = $er;
    }

    foreach ($rowsToWrite as $key => $built) {
        $existingRow = $existingByKey[$key] ?? null;
        $decision    = vocalPartReviewScanDecision($existingRow['Status'] ?? null);
        if ($decision === 'skip') {
            $counts['skipped']++;
            continue;
        }
        if ($dryRun) {
            $counts[$decision === 'insert' ? 'inserted' : 'updated']++;
            continue;
        }
        if ($decision === 'insert') {
            _vocalPartReviewInsertRow($db, $built);
            $counts['inserted']++;
        } else {
            _vocalPartReviewUpdateRow($db, (int)$existingRow['Id'], $built);
            $counts['updated']++;
        }
    }

    /* --- Stale check: a still-pending row this scan did NOT reproduce --- */
    foreach ($existingRows as $er) {
        $k = vocalPartReviewDetectionKey((int)$er['DetectionLineId'], (string)$er['Form'], (int)$er['MarkerOffset']);
        if (vocalPartReviewShouldStale((string)$er['Status'], isset($rowsToWrite[$k]))) {
            $counts['staled']++;
            if (!$dryRun) {
                _vocalPartReviewMarkStale($db, (int)$er['Id']);
            }
        }
    }

    return $counts;
}

/**
 * ELI5: the "re-scan this one song" action a future review page offers —
 * identical to what the batch backfill does for every song, just for one.
 */
function vocalPartReviewRefreshSong(\mysqli $db, string $songId): array
{
    return vocalPartReviewScanSong($db, $songId, false);
}

function _vocalPartReviewInsertRow(\mysqli $db, array $r): int
{
    $cols  = ['SongId', 'LyricsId', 'MarkerLineId', 'DetectionLineId', 'MarkerOffset', 'StartLineId', 'EndLineId',
              'Form', 'MarkerText', 'PartKind', 'Label', 'IsBackground', 'ProposedJson', 'Confidence', 'Status', 'DetectorVersion'];
    $types = 'siiiiii' . 'ssss' . 'i' . 's' . 's' . 's' . 'i';   // s,i,i,i,i,i,i, s,s,s,s, i, s, s, i  (16 columns)
    $ins   = $db->prepare(
        'INSERT INTO tblVocalPartSuggestions (' . implode(', ', $cols) . ')
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    bindParamSafe(
        __FUNCTION__,
        $ins,
        $types,
        $r['songId'],
        $r['lyricsId'],
        $r['markerLineId'],
        $r['detectionLineId'],
        $r['markerOffset'],
        $r['startLineId'],
        $r['endLineId'],
        $r['form'],
        $r['markerText'],
        $r['partKind'],
        $r['label'],
        $r['isBackground'] ? 1 : 0,
        json_encode($r['proposedJson'], JSON_UNESCAPED_UNICODE),
        $r['confidence'],
        'pending',
        $r['detectorVersion']
    );
    $ins->execute();
    $id = (int)$db->insert_id;
    $ins->close();
    return $id;
}

/** Refresh a re-detected, still-open (pending/stale) row's mutable fields; NEVER touches an already-reviewed one (callers only reach this via vocalPartReviewScanDecision() === 'update'). */
function _vocalPartReviewUpdateRow(\mysqli $db, int $id, array $r): void
{
    $upd = $db->prepare(
        "UPDATE tblVocalPartSuggestions
            SET MarkerLineId = ?, StartLineId = ?, EndLineId = ?, MarkerText = ?, PartKind = ?, Label = ?,
                IsBackground = ?, ProposedJson = ?, Confidence = ?, Status = 'pending', DetectorVersion = ?
          WHERE Id = ?"
    );
    bindParamSafe(
        __FUNCTION__,
        $upd,
        'iiisssissii',   // MarkerLineId,StartLineId,EndLineId(iii) MarkerText,PartKind,Label(sss) IsBackground(i) ProposedJson,Confidence(ss) DetectorVersion,Id(ii)
        $r['markerLineId'],
        $r['startLineId'],
        $r['endLineId'],
        $r['markerText'],
        $r['partKind'],
        $r['label'],
        $r['isBackground'] ? 1 : 0,
        json_encode($r['proposedJson'], JSON_UNESCAPED_UNICODE),
        $r['confidence'],
        $r['detectorVersion'],
        $id
    );
    $upd->execute();
    $upd->close();
}

function _vocalPartReviewMarkStale(\mysqli $db, int $id): void
{
    $upd = $db->prepare("UPDATE tblVocalPartSuggestions SET Status = 'stale' WHERE Id = ?");
    bindParamSafe(__FUNCTION__, $upd, 'i', $id);
    $upd->execute();
    $upd->close();
}

/* =====================================================================
 * DB — the queue: list / accept / dismiss / undo.
 * ===================================================================== */

/**
 * IDOR resolver (mirrors `vocalPartsResolvePart()`): null when the id
 * doesn't exist OR belongs to a different song — the caller never learns
 * which, so a suggestion id can't be used to enumerate another song's rows.
 */
function vocalPartReviewResolveRow(\mysqli $db, string $songId, int $suggestionId): ?array
{
    $stmt = $db->prepare('SELECT * FROM tblVocalPartSuggestions WHERE Id = ? AND SongId = ? LIMIT 1');
    bindParamSafe(__FUNCTION__, $stmt, 'is', $suggestionId, $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** DB row -> the API-ish shape a future list/detail response emits. */
function vocalPartReviewShape(array $row): array
{
    $proposed = json_decode((string)($row['ProposedJson'] ?? 'null'), true);
    $applied  = ($row['AppliedJson'] ?? null) !== null ? json_decode((string)$row['AppliedJson'], true) : null;

    return [
        'id'              => (int)$row['Id'],
        'songId'          => (string)$row['SongId'],
        'lyricsId'        => (int)$row['LyricsId'],
        'markerLineId'    => $row['MarkerLineId'] !== null ? (int)$row['MarkerLineId'] : null,
        'detectionLineId' => (int)$row['DetectionLineId'],
        'markerOffset'    => (int)$row['MarkerOffset'],
        'startLineId'     => $row['StartLineId'] !== null ? (int)$row['StartLineId'] : null,
        'endLineId'       => $row['EndLineId'] !== null ? (int)$row['EndLineId'] : null,
        'form'            => (string)$row['Form'],
        'markerText'      => (string)$row['MarkerText'],
        'partKind'        => (string)$row['PartKind'],
        'label'           => $row['Label'] !== null ? (string)$row['Label'] : null,
        'isBackground'    => (int)$row['IsBackground'] === 1,
        'confidence'      => (string)$row['Confidence'],
        'status'          => (string)$row['Status'],
        'detectorVersion' => (int)$row['DetectorVersion'],
        'proposed'        => is_array($proposed) ? $proposed : [],
        'applied'         => is_array($applied) ? $applied : null,
        'reviewedBy'      => $row['ReviewedBy'] !== null ? (int)$row['ReviewedBy'] : null,
        'reviewedAt'      => $row['ReviewedAt'] !== null ? (string)$row['ReviewedAt'] : null,
        'createdAt'       => (string)$row['CreatedAt'],
        'updatedAt'       => (string)$row['UpdatedAt'],
        'songTitle'       => (array_key_exists('SongTitle', $row) && $row['SongTitle'] !== null) ? (string)$row['SongTitle'] : null,
        'songbookAbbr'    => (array_key_exists('SongbookAbbr', $row) && $row['SongbookAbbr'] !== null) ? (string)$row['SongbookAbbr'] : null,
    ];
}

/**
 * @param array{status?:string,confidence?:string,form?:string,songbook?:string,songId?:string} $filter
 * @throws \InvalidArgumentException  an unrecognised status/confidence/form value
 */
function vocalPartReviewList(\mysqli $db, array $filter, int $limit, int $offset): array
{
    /* @deleted-visible: admin surface (#1694) — this is the curator-only
       review queue (`edit_songs`-gated, never a public read), and a
       suggestion for a song that has since been soft-deleted must keep
       showing its Title so the curator can tell what it WAS and
       Dismiss/Undo it correctly, instead of a silently blank row. The
       predicate-free LEFT JOIN below also means a genuinely hard-deleted
       song's suggestion still shows (Title/SongbookAbbr simply come back
       null) rather than the row vanishing from the list entirely.
       @disabled-visible: admin surface (#1765) — same reasoning for a
       disabled songbook: its abbreviation must keep showing here so a
       curator can still find and act on that book's pending suggestions. */
    $limit  = max(1, min(500, $limit));
    $offset = max(0, $offset);

    $where  = [];
    $types  = '';
    $params = [];

    if (!empty($filter['status'])) {
        $status = (string)$filter['status'];
        if (!in_array($status, IHYMNS_VOCAL_REVIEW_STATUSES, true)) {
            throw new \InvalidArgumentException('status must be one of: ' . implode(', ', IHYMNS_VOCAL_REVIEW_STATUSES));
        }
        $where[] = 'vps.Status = ?';
        $types  .= 's';
        $params[] = $status;
    }
    if (!empty($filter['confidence'])) {
        $conf = (string)$filter['confidence'];
        if (!in_array($conf, IHYMNS_VOCAL_REVIEW_CONFIDENCES, true)) {
            throw new \InvalidArgumentException('confidence must be one of: ' . implode(', ', IHYMNS_VOCAL_REVIEW_CONFIDENCES));
        }
        $where[] = 'vps.Confidence = ?';
        $types  .= 's';
        $params[] = $conf;
    }
    if (!empty($filter['form'])) {
        $form = (string)$filter['form'];
        if (!in_array($form, IHYMNS_VOCAL_DETECT_FORMS, true)) {
            throw new \InvalidArgumentException('form must be one of: ' . implode(', ', IHYMNS_VOCAL_DETECT_FORMS));
        }
        $where[] = 'vps.Form = ?';
        $types  .= 's';
        $params[] = $form;
    }
    if (!empty($filter['songbook'])) {
        $where[] = 'vps.SongId LIKE ?';
        $types  .= 's';
        $params[] = ((string)$filter['songbook']) . '-%';
    }
    if (!empty($filter['songId'])) {
        $where[] = 'vps.SongId = ?';
        $types  .= 's';
        $params[] = (string)$filter['songId'];
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT vps.*, s.Title AS SongTitle, s.SongbookAbbr AS SongbookAbbr
              FROM tblVocalPartSuggestions vps
              LEFT JOIN tblSongs s ON s.SongId = vps.SongId
              {$whereSql}
             ORDER BY (vps.Status = 'pending') DESC, (vps.Confidence = 'high') DESC, vps.CreatedAt DESC, vps.Id DESC
             LIMIT ? OFFSET ?";
    $types   .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    bindParamSafe(__FUNCTION__, $stmt, $types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map('vocalPartReviewShape', $rows);
}

/** How many `tblVocalParts` rows exist for one lyrics version — used to detect whether `vocalPartsFindOrCreate()` just minted a NEW part (rule #22: a before/after count, never a re-forked copy of that function's own three-branch match logic). */
function _vocalPartReviewCountParts(\mysqli $db, int $lyricsId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM tblVocalParts WHERE LyricsId = ?');
    bindParamSafe(__FUNCTION__, $stmt, 'i', $lyricsId);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $n;
}

/** Does any OTHER live row still reference this vocal part (line, span, or round voice)? Guards Undo's part cleanup from deleting a part something else still needs. */
function _vocalPartReviewPartStillUsed(\mysqli $db, int $partId): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM tblLyricLineVocalParts WHERE VocalPartId = ?');
    bindParamSafe(__FUNCTION__, $stmt, 'i', $partId);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    if ($n > 0) {
        return true;
    }

    if (vocalPartsSpansReady($db)) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblLyricLineVocalSpans WHERE VocalPartId = ?');
        bindParamSafe(__FUNCTION__ . ':spans', $stmt, 'i', $partId);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        if ($n > 0) {
            return true;
        }
    }

    /* lyric_rounds.php is a lazy require (matches vocal_parts.php's own
       one-way-edge posture toward it, see that file's doc-block) — only
       pulled in here, when we actually need lyricRoundsReady(). */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_rounds.php';
    if (lyricRoundsReady($db)) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblLyricRoundVoices WHERE VocalPartId = ?');
        bindParamSafe(__FUNCTION__ . ':rounds', $stmt, 'i', $partId);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        if ($n > 0) {
            return true;
        }
    }
    return false;
}

/**
 * ELI5: a curator looked at a suggestion and said "yes" — actually assign
 * the voice part, and tidy up the marker text (remove it for a standalone
 * cue, strip it off the front of a prefix line; a paren cue's text is left
 * untouched). Records exactly what happened in `AppliedJson` so
 * `vocalPartReviewUndo()` can put it all back.
 *
 * @param array{kind?:?string,label?:?string,isBackground?:bool} $overrides
 *        a curator may correct the proposed kind/label/echo flag before
 *        accepting — every key is OMITTED-MEANS-KEEP (`array_key_exists`,
 *        rule #45), mirroring `vocalPartsUpsert()`'s own convention.
 * @throws \RuntimeException                   not found for this song
 * @throws VocalPartReviewConflictException     not pending, or the marker
 *         line has vanished/moved since it was queued (`reason: 'not_pending'|'stale_line'`)
 * @throws \InvalidArgumentException            an override names an unknown kind
 */
function vocalPartReviewAccept(\mysqli $db, string $songId, int $suggestionId, ?int $userId = null, array $overrides = []): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';   // lyricLinesWriteComponents()

    $row = vocalPartReviewResolveRow($db, $songId, $suggestionId);
    if ($row === null) {
        throw new \RuntimeException('Suggestion not found for this song.');
    }
    if ((string)$row['Status'] !== 'pending') {
        throw new VocalPartReviewConflictException('not_pending', 'Only a pending suggestion can be accepted.');
    }
    if ($row['MarkerLineId'] === null) {
        throw new VocalPartReviewConflictException('stale_line', 'This suggestion’s marker line no longer exists — refresh to update it.');
    }

    $lyricsId     = (int)$row['LyricsId'];
    $markerLineId = (int)$row['MarkerLineId'];
    $form         = (string)$row['Form'];

    $kind = (array_key_exists('kind', $overrides) && $overrides['kind'] !== null)
        ? vocalPartsRequireKind((string)$overrides['kind'])
        : (string)$row['PartKind'];
    $label = array_key_exists('label', $overrides)
        ? vocalPartsNormalizeLabelInput($overrides['label'])
        : ($row['Label'] !== null ? (string)$row['Label'] : null);
    $isBackground = array_key_exists('isBackground', $overrides)
        ? (bool)$overrides['isBackground']
        : ((int)$row['IsBackground'] === 1);

    $proposed = json_decode((string)$row['ProposedJson'], true);
    if (!is_array($proposed)) {
        $proposed = [];
    }

    $applied = [
        'actions'         => [],
        'assignedLineIds' => [],
        'partId'          => null,
        'partCreated'     => false,
        'markerRemoved'   => false,
        'markerRewrittenFrom' => null,
        /* BUG FIX (independent review, 2026-09): Undo used to read the
           SUGGESTION's original IsBackground column, not what Accept
           actually used — an `overrides['isBackground']` at accept time
           made Undo remove the wrong voice class. Recording the value
           Accept genuinely applied here (set below, once assignment
           actually happens) is what lets Undo reverse the real thing that
           was done instead of re-deriving a guess. */
        'isBackground'    => null,
    ];

    $targetLineIds = [];

    /* BUG-FIX (independent review, 2026-09): a `prefix` row's rewrite text
       is needed both for the text-match check just below AND for the
       actual rewrite further down, so it is pulled out of ProposedJson
       once, up front, for every form. */
    $rewriteText = null;
    if ($form === 'prefix') {
        foreach ($proposed as $act) {
            if (($act['action'] ?? '') === 'rewrite-marker-line') {
                $rewriteText = (string)($act['text'] ?? '');
                break;
            }
        }
        if ($rewriteText === null) {
            throw new \RuntimeException('This suggestion has no recorded rewrite text.');
        }
    }

    $edit   = lyricLinesEditableComponents($db, $songId);
    $before = vocalPartReviewLocateLine($edit, $markerLineId);
    if ($before === null) {
        throw new VocalPartReviewConflictException('stale_line', 'The marker line has moved or been deleted since this was detected — refresh to update it.');
    }
    [$ci, $li] = $before;
    $currentMarkerLineText = (string)($edit[$ci]['lines'][$li] ?? '');

    /* BUG FIX (independent review, 2026-09): the OLD code checked only
       that the marker line's ID still existed, never that its WORDS still
       matched what the suggestion was built from — so an edit that kept
       the id but changed the text (or, for `prefix`, changed only the
       lyric AFTER the marker) still got deleted or overwritten as if
       nothing had happened. Re-check the CURRENT text, for every form,
       before doing anything destructive. */
    $expectedRest = $form === 'prefix' ? (string)$rewriteText : '';
    if (!vocalPartReviewTextStillMatches($form, $currentMarkerLineText, (string)$row['MarkerText'], $expectedRest)) {
        _vocalPartReviewMarkStale($db, $suggestionId);
        throw new VocalPartReviewConflictException('stale_text', 'This line’s wording has changed since the suggestion was made — refresh to update it.');
    }

    if ($form === 'standalone') {
        /* BUG FIX (independent review, 2026-09 — the SAME positional-
           identity mistake CLAUDE.md rule #51 names, a fourth time on
           this branch): the OLD code re-derived the run's LENGTH from
           Start/EndLineId and then picked target lines by COUNTING
           FORWARD from the marker's position in a freshly-re-read
           component. If a line had been inserted, or the marker had
           moved, that count landed on whatever lines happened to sit at
           those positions NOW — not the lines the suggestion actually
           meant — and silently assigned the voice to the wrong lyrics.
           The fix trusts IDENTITY instead: ProposedJson's own
           `assign-lines` action already recorded the EXACT line ids this
           suggestion proposed, at the time it was built. Every one of
           those ids is revalidated against the read taken BEFORE the
           marker line is touched (so a missing id is caught before
           anything is deleted, never after), and the recorded ids are
           used verbatim — no recount, no guess. */
        $proposedLineIds = [];
        foreach ($proposed as $act) {
            if (($act['action'] ?? '') === 'assign-lines') {
                $proposedLineIds = array_map('intval', $act['lineIds'] ?? []);
                break;
            }
        }
        foreach ($proposedLineIds as $lid) {
            if (vocalPartReviewLocateLine($edit, $lid) === null) {
                _vocalPartReviewMarkStale($db, $suggestionId);
                throw new VocalPartReviewConflictException('stale_line', 'The lines this suggestion targets have changed since it was made — refresh to update it.');
            }
        }

        $newComponents = vocalPartReviewApplyLineOp($edit, ['type' => 'remove-line', 'lineId' => $markerLineId]);
        if ($newComponents === null) {
            throw new VocalPartReviewConflictException('stale_line', 'The marker line has moved or been deleted since this was detected — refresh to update it.');
        }
        lyricLinesWriteComponents($db, $songId, $newComponents);
        $applied['markerRemoved'] = true;
        $applied['actions'][] = ['action' => 'remove-marker-line', 'lineId' => $markerLineId];

        $targetLineIds = $proposedLineIds;
    } elseif ($form === 'prefix') {
        $oldText = $currentMarkerLineText;

        $newComponents = vocalPartReviewApplyLineOp($edit, ['type' => 'rewrite-line', 'lineId' => $markerLineId, 'text' => $rewriteText]);
        if ($newComponents === null) {
            throw new VocalPartReviewConflictException('stale_line', 'The marker line has moved or been deleted since this was detected — refresh to update it.');
        }
        lyricLinesWriteComponents($db, $songId, $newComponents);
        $applied['markerRewrittenFrom'] = $oldText;
        $applied['actions'][] = ['action' => 'rewrite-marker-line', 'lineId' => $markerLineId, 'from' => $oldText, 'to' => $rewriteText];

        /* Rewrite-first-then-look-up-by-position: "Design pass 7" §3.5's
           own prescribed order (see this file's doc-block) — the diff's
           Id-preserving match is a content-similarity GUESS, so the
           correct id to assign is whatever is REALLY at this position
           after the write actually ran, not the id we walked in with. */
        $fresh   = lyricLinesEditableComponents($db, $songId);
        $freshId = $fresh[$ci]['lineIds'][$li] ?? null;
        if ($freshId !== null) {
            $targetLineIds = [(int)$freshId];
        }
    } else {   // 'paren' — no line-content change; the marker's own id is unaffected
        $targetLineIds = [$markerLineId];
    }

    if ($targetLineIds) {
        $beforeCount = _vocalPartReviewCountParts($db, $lyricsId);
        $partId      = vocalPartsFindOrCreate($db, $lyricsId, $kind, $label, 'import-marker');
        $afterCount  = _vocalPartReviewCountParts($db, $lyricsId);

        $applied['partId']      = $partId;
        $applied['partCreated'] = $afterCount > $beforeCount;

        /* 'add', never 'replace' (rule #45's caution against a silent
           destructive default): Accept only ever ADDS the newly-found
           voice — it must never delete an assignment a curator already
           made on the same lines by some other route. */
        vocalPartsAssignLines($db, $songId, $targetLineIds, [$partId], 'add', $isBackground);
        $applied['assignedLineIds'] = $targetLineIds;
        $applied['isBackground']    = $isBackground;
        $applied['actions'][] = ['action' => 'assign-lines', 'lineIds' => $targetLineIds, 'partId' => $partId, 'isBackground' => $isBackground];
    }

    $upd = $db->prepare(
        "UPDATE tblVocalPartSuggestions SET Status = 'accepted', AppliedJson = ?, ReviewedBy = ?, ReviewedAt = UTC_TIMESTAMP() WHERE Id = ?"
    );
    bindParamSafe(__FUNCTION__ . ':save', $upd, 'sii', json_encode($applied, JSON_UNESCAPED_UNICODE), $userId, $suggestionId);
    $upd->execute();
    $upd->close();

    $fresh = vocalPartReviewResolveRow($db, $songId, $suggestionId);
    return vocalPartReviewShape($fresh);
}

/**
 * @throws \RuntimeException                 not found for this song
 * @throws VocalPartReviewConflictException  not pending (`reason: 'not_pending'`)
 */
function vocalPartReviewDismiss(\mysqli $db, string $songId, int $suggestionId, ?int $userId = null): array
{
    $row = vocalPartReviewResolveRow($db, $songId, $suggestionId);
    if ($row === null) {
        throw new \RuntimeException('Suggestion not found for this song.');
    }
    if ((string)$row['Status'] !== 'pending') {
        throw new VocalPartReviewConflictException('not_pending', 'Only a pending suggestion can be dismissed.');
    }

    $upd = $db->prepare("UPDATE tblVocalPartSuggestions SET Status = 'dismissed', ReviewedBy = ?, ReviewedAt = UTC_TIMESTAMP() WHERE Id = ?");
    bindParamSafe(__FUNCTION__, $upd, 'ii', $userId, $suggestionId);
    $upd->execute();
    $upd->close();

    $fresh = vocalPartReviewResolveRow($db, $songId, $suggestionId);
    return vocalPartReviewShape($fresh);
}

/**
 * ELI5: put an accepted suggestion back the way it was — clear the voice
 * assignment Accept made, delete the part Accept minted if nothing else is
 * using it any more, and put the marker text back where it used to sit.
 *
 * ⚠️ QUEUE-NATIVE UNDO, NOT A REVISIONS-TAB RESTORE ("Design pass 7"'s own
 * C5 resolution, cited in the #2073 task brief): the v2 editor's revision
 * snapshot is api2-local and carries no voice-assignment rows at all, so
 * restoring an OLD revision would put the marker TEXT back while leaving
 * the voice-part ROWS Accept created behind — a half-undo a curator would
 * never notice happened. This function is the ONLY correct way to reverse
 * an Accept.
 *
 * Best-effort on the "put the marker text back" step: if the line Accept
 * removed can no longer be re-anchored (every line it used to sit beside
 * has ALSO since been deleted), the marker text is appended to the end of
 * the song's last component instead of silently vanishing — the row's own
 * `MarkerText` column is the permanent record either way, so nothing about
 * the original finding is ever lost, only its exact original position.
 *
 * @throws \RuntimeException                 not found for this song
 * @throws VocalPartReviewConflictException  not accepted (`reason: 'not_accepted'`)
 */
function vocalPartReviewUndo(\mysqli $db, string $songId, int $suggestionId, ?int $userId = null): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';

    $row = vocalPartReviewResolveRow($db, $songId, $suggestionId);
    if ($row === null) {
        throw new \RuntimeException('Suggestion not found for this song.');
    }
    if ((string)$row['Status'] !== 'accepted') {
        throw new VocalPartReviewConflictException('not_accepted', 'Only an accepted suggestion can be undone.');
    }

    $applied = json_decode((string)$row['AppliedJson'], true);
    if (!is_array($applied)) {
        $applied = [];
    }

    /* 1 — undo the assignment.
       BUG FIX (independent review, 2026-09 — data loss): this used to
       clear EVERY assignment of the suggestion's background class on
       these lines, with no filter for WHICH voice part — so undoing one
       suggestion could wipe a hand-made assignment, or one from a
       DIFFERENT accepted suggestion, on the very same lines. Undo must
       remove only what Accept itself created: the one part it assigned,
       on the exact lines it assigned it to. Passing `$partId` scopes the
       clear to that part alone (vocalPartsClearLines()'s new optional
       filter), so a curator's own manual work on those same lines is left
       untouched.
       This also fixes a second bug from the same review: `$isBg` used to
       read the SUGGESTION's original IsBackground column, not what Accept
       actually used — if an override at accept time flipped it, Undo
       cleared the wrong voice class. `$applied['isBackground']` is what
       was genuinely applied; the row's own column is kept only as a
       fallback for a suggestion accepted before this field existed. */
    if (!empty($applied['assignedLineIds']) && ($applied['partId'] ?? null) !== null) {
        $isBg = array_key_exists('isBackground', $applied) && $applied['isBackground'] !== null
            ? (bool)$applied['isBackground']
            : ((int)$row['IsBackground'] === 1);
        vocalPartsClearLines(
            $db,
            $songId,
            array_map('intval', $applied['assignedLineIds']),
            $isBg,
            (int)$applied['partId']
        );
    }

    /* 2 — remove the part Accept minted, but ONLY if nothing else needs it
       any more (a curator may have since added a second line to it by
       hand, in which case deleting it would take that assignment with it). */
    if (!empty($applied['partCreated']) && ($applied['partId'] ?? null) !== null) {
        $partId = (int)$applied['partId'];
        if (!_vocalPartReviewPartStillUsed($db, $partId)) {
            try {
                vocalPartsDelete($db, $songId, $partId);
            } catch (\Throwable $_e) {
                /* Best-effort — an orphaned, unused part row is harmless
                   curator-visible clutter, never worth failing the whole
                   Undo over. */
            }
        }
    }

    /* 3 — put the marker text back. */
    if (!empty($applied['markerRemoved'])) {
        $edit = lyricLinesEditableComponents($db, $songId);
        $anchorId = null;
        foreach (($applied['assignedLineIds'] ?? []) as $lid) {
            if (vocalPartReviewLocateLine($edit, (int)$lid) !== null) {
                $anchorId = (int)$lid;
                break;
            }
        }
        $newComponents = ($anchorId !== null)
            ? vocalPartReviewInsertLineBefore($edit, $anchorId, (string)$row['MarkerText'])
            : null;
        if ($newComponents === null && $edit) {
            /* Nothing left to anchor before — append to the end of the
               LAST component rather than lose the text (see doc-block). */
            $lastIdx = count($edit) - 1;
            $newComponents = [];
            foreach ($edit as $i => $c) {
                $comp = vocalPartReviewComponentForWrite($c);
                if ($i === $lastIdx) {
                    $comp['lines'][] = (string)$row['MarkerText'];
                    foreach (['chords', 'notes', 'languages'] as $key) {
                        if (is_array($comp[$key] ?? null)) {
                            $comp[$key][] = null;
                        }
                    }
                }
                $newComponents[] = $comp;
            }
        }
        if ($newComponents !== null) {
            lyricLinesWriteComponents($db, $songId, $newComponents);
        }
    } elseif (!empty($applied['markerRewrittenFrom']) && !empty($applied['assignedLineIds'])) {
        $lineId = (int)$applied['assignedLineIds'][0];
        $edit   = lyricLinesEditableComponents($db, $songId);
        $newComponents = vocalPartReviewApplyLineOp($edit, [
            'type' => 'rewrite-line', 'lineId' => $lineId, 'text' => (string)$applied['markerRewrittenFrom'],
        ]);
        if ($newComponents !== null) {
            lyricLinesWriteComponents($db, $songId, $newComponents);
        }
        /* $newComponents === null (the line has since been deleted by a
           curator) is left alone — there is nothing sane to rewrite back,
           and the original marker text still lives in `MarkerText`. */
    }

    $upd = $db->prepare("UPDATE tblVocalPartSuggestions SET Status = 'undone', ReviewedBy = ?, ReviewedAt = UTC_TIMESTAMP() WHERE Id = ?");
    bindParamSafe(__FUNCTION__, $upd, 'ii', $userId, $suggestionId);
    $upd->execute();
    $upd->close();

    $fresh = vocalPartReviewResolveRow($db, $songId, $suggestionId);
    return vocalPartReviewShape($fresh);
}
