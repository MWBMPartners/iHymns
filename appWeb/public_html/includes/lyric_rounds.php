<?php

declare(strict_types=1);

/**
 * iHymns — Rounds / canon / partner-song core (#2073, commit 5)
 *
 * ELI5: this file is the ONE place that knows how a "sing this as a round"
 * instruction works — a round/canon/partner-song definition over a run of
 * lyric lines (`tblLyricRounds`), one row per VOICE of it
 * (`tblLyricRoundVoices`, each carrying its own entry offset), and — the
 * piece a Present-mode projector actually needs — a PURE function that
 * expands "3 voices, entering 2 lines apart, twice through" into an exact
 * step-by-step timeline with no database involved at all, so it can be
 * unit-tested here AND mirrored byte-for-byte in JavaScript later (the plan
 * calls this D1).
 *
 * SHIPPED SCHEMA THIS FILE READS/WRITES (`appWeb/.sql/migrate-vocal-parts-rounds.php`,
 * #2073 commit 2 — dormant until this commit gives it a caller):
 *   - tblLyricRounds       — the round/canon/partner-song definition itself.
 *   - tblLyricRoundVoices  — one row per voice, `UNIQUE (RoundId, VoiceNumber)`,
 *                            a NULLABLE `VocalPartId` (an unnamed "Voice N" is
 *                            a fully legal row), and THREE independently
 *                            nullable entry-offset columns (`EntryLines`
 *                            always populated, `EntryBeats`/`EntryMs` only
 *                            when that basis applies) with `EntryBasis`
 *                            naming which one is authoritative for THIS
 *                            voice — see that migration's own "F3" note.
 * Both readiness probes below memoise per request and degrade to `false` on
 * an un-migrated install, matching every other reader/writer in this
 * feature (rule #19).
 *
 * MUTUAL DEPENDENCY WITH `vocal_parts.php` (read this before adding a new
 * `require_once` between the two): a round's `StartLineId`/`EndLineId` and
 * a voice's own partner-song span are OWNERSHIP-CHECKED via
 * `vocalPartsResolveLines()`, and a voice's `VocalPartId` via
 * `vocalPartsResolvePart()` — both live in `vocal_parts.php`, so THIS file
 * `require_once`s that one, at the top, unconditionally. The reverse need
 * (`vocal_parts.php`'s bulk `vocalPartsForSong()` wants `lyricRoundsReady()`
 * / `lyricRoundsForVersion()` from THIS file) is resolved the OTHER way —
 * lazily, `require_once`d only inside `vocalPartsForSong()`'s own body, not
 * at that file's top — so there is only ONE unconditional top-of-file
 * `require_once` edge between the two files, not a hard two-way cycle a
 * future editor could break by reordering an unrelated line. (A two-way
 * `require_once` at both files' TOP would still be technically safe in PHP —
 * `require_once` tracks a file as "included" the moment it STARTS running,
 * so a nested re-require of the file already in progress is silently
 * skipped and both files' functions still end up defined before either is
 * ever CALLED — but a single directed edge is simpler to reason about and
 * is what this file chooses.)
 *
 * Same throw contract as `vocal_parts.php`: `\InvalidArgumentException` ->
 * the caller answers 400; `\RuntimeException` -> 404 (not found / not
 * owned — never says which); `lyricRoundsReady() === false` -> the CALLER
 * answers 409. Every DB value bound via `bindParamSafe()`; the only
 * interpolated SQL text is an `array_fill()`-built `?,?,?` placeholder
 * string (rule #5).
 *
 * @see .claude/vocal-parts-2073-plan.md   "Design pass 7" §2.2-§2.3 (the DDL
 *                                          this file's readers/writers bind
 *                                          to), §3.2-§3.3 (this file's own
 *                                          contract and the timeline algorithm)
 * @see appWeb/public_html/includes/vocal_parts.php   the sibling core this
 *                                          file requires for line/part
 *                                          ownership resolution
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_parts.php';

/**
 * The three round "shapes" a curator can pick — a VARCHAR-validated
 * vocabulary, never an ENUM (rule #20): a value-add here is one array
 * entry, not a migration.
 */
const IHYMNS_ROUND_KINDS = ['round', 'canon', 'partner-song'];

/**
 * How a round ENDS. `complete` — every voice sings its own full count of
 * passes through, however long that takes; `together` — every voice stops
 * the instant voice 1 finishes its own count (a later, longer-count voice
 * is cut mid-phrase — deliberate, see `lyricRoundTimeline()`'s own
 * doc-block); `coda` — after `together`'s cutoff, every voice sings the
 * round's `CodaStartLineId..CodaEndLineId` span together, once.
 */
const IHYMNS_ROUND_ENDINGS = ['complete', 'together', 'coda'];

/**
 * Which of a voice's THREE entry-offset columns
 * (`EntryLines`/`EntryBeats`/`EntryMs`) is authoritative for that voice —
 * see `tblLyricRoundVoices.EntryBasis`'s own COMMENT (migration "F3") for
 * why this exists at all: `EntryLines = 0` is otherwise indistinguishable
 * from "not set".
 */
const IHYMNS_ROUND_ENTRY_BASES = ['lines', 'beats', 'ms'];

/**
 * The two values `tblLyricRounds.IntegrityStatus` may hold — the
 * discoverability flag the migration's own "F4" note describes: a line
 * delete that quietly changes what a round means (its end line vanishing,
 * a partner-song span losing one half, a coda span disappearing) flips this
 * to `needs-review` instead of leaving a row that looks healthy but isn't.
 * Set by `includes/lyric_lines_sync.php`'s delete path (this commit), never
 * by a curator directly.
 */
const IHYMNS_ROUND_INTEGRITY_STATUSES = ['ok', 'needs-review'];

/* =====================================================================
 * READINESS
 * ===================================================================== */

/**
 * Are `tblLyricRounds` AND `tblLyricRoundVoices` both present? Memoised.
 * Catch posture mirrors `vocalPartsTablesReady()` exactly: a genuine
 * transaction-fatal error propagates (`songRelocateIsTransactionFatal()`),
 * anything else degrades to "not ready" — this runs inside the same save
 * transactions `vocal_parts.php`'s functions do.
 */
function lyricRoundsReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('tblLyricRounds', 'tblLyricRoundVoices')"
        );
        $row = $r ? $r->fetch_row() : null;
        $ready = ($row !== null && (int)$row[0] >= 2);
        if ($r) {
            $r->close();
        }
    } catch (\Throwable $_e) {
        if (function_exists('songRelocateIsTransactionFatal') && songRelocateIsTransactionFatal($_e)) {
            throw $_e;
        }
        $ready = false;
    }
    return $ready;
}

/* =====================================================================
 * PURE — no DB. Directly unit-tested (tests/php/test-lyric-rounds-timeline.php).
 * ===================================================================== */

/**
 * ELI5: "which lines does this round actually cover?" — from a component's
 * (or the whole version's) ordered line ids plus the round's own start/end,
 * hand back the exact slice.
 *
 * `$endLineId === null` means "just the one line" (mirrors
 * `tblLyricRounds.EndLineId`'s own NULL semantics). `[]` — never a thrown
 * error — when either id genuinely is not IN `$orderedLineIds` at all, or
 * when `$endLineId` sits BEFORE `$startLineId` in that order (a caller
 * handed this stale/inconsistent ids — this function only slices, it does
 * not diagnose why).
 *
 * @param list<int> $orderedLineIds
 * @return list<int>
 */
function lyricRoundSubjectLineIds(array $orderedLineIds, int $startLineId, ?int $endLineId): array
{
    $sIdx = array_search($startLineId, $orderedLineIds, true);
    if ($sIdx === false) {
        return [];
    }
    if ($endLineId === null) {
        return [$orderedLineIds[$sIdx]];
    }
    $eIdx = array_search($endLineId, $orderedLineIds, true);
    if ($eIdx === false || $eIdx < $sIdx) {
        return [];
    }
    return array_values(array_slice($orderedLineIds, $sIdx, $eIdx - $sIdx + 1));
}

/**
 * ELI5: validate + normalise a round's proposed `voices` list — everything
 * about "is this a legal set of voices" that does NOT need a database
 * (numbering, the voice-1 rule, which entry-offset column each voice
 * actually needs given its own basis, the partner-song span's
 * both-or-neither rule). The DB-touching ownership checks (does `partId`
 * really exist on this version? does the partner-song span really sit on
 * this version's lines?) are layered on TOP of this by `lyricRoundUpsert()`
 * — kept separate so the RULES are testable without a live MySQL.
 *
 * Rules (`Design pass 7` §3.2, verbatim):
 *   - 1..8 voices.
 *   - `number` exactly 1..N contiguous once sorted — no gaps, no repeats,
 *     starting at 1.
 *   - voice 1 is ALWAYS `entryBasis = 'lines'` with `entryLines = 0`
 *     (voice 1 always enters at the very start — the plan's own words).
 *   - every OTHER voice's `entryBasis` is one of `IHYMNS_ROUND_ENTRY_BASES`;
 *     `entryLines` is always required (>= 0, default 0) regardless of
 *     basis (it is the guaranteed fallback the pure timeline reads when a
 *     stronger basis isn't computable — see `lyricRoundTimeline()`);
 *     `entryBeats` is required (and >= 0) when that voice's own basis is
 *     `'beats'`; `entryMs` is required (and >= 0) when it is `'ms'`.
 *   - a partner-song `startLineId`/`endLineId` pair is BOTH-OR-NEITHER
 *     (unlike the round's own `StartLineId`/`EndLineId`, where a `null`
 *     `EndLineId` legitimately means "just the one line" — a VOICE's own
 *     span has no such single-line shorthand; the schema's own COMMENT on
 *     `tblLyricRoundVoices.StartLineId` says so explicitly).
 *
 * @param list<array<string,mixed>> $voices
 * @return list<array{number:int,partId:?int,label:?string,entryBasis:string,
 *                     entryLines:int,entryBeats:?float,entryMs:?int,
 *                     intervalSemitones:?int,startLineId:?int,endLineId:?int,
 *                     timesThrough:?int,sortOrder:int}>
 * @throws \InvalidArgumentException
 */
function lyricRoundsValidateVoicesShape(array $voices): array
{
    if (!$voices) {
        throw new \InvalidArgumentException('A round needs at least one voice.');
    }
    if (count($voices) > 8) {
        throw new \InvalidArgumentException('A round may have at most 8 voices.');
    }

    $out = [];
    $seenNumbers = [];
    foreach ($voices as $raw) {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Each voice must be an object.');
        }
        $number = (int)($raw['number'] ?? 0);
        if ($number <= 0) {
            throw new \InvalidArgumentException('Each voice needs a positive whole-number "number".');
        }
        if (isset($seenNumbers[$number])) {
            throw new \InvalidArgumentException("Voice number {$number} is repeated.");
        }
        $seenNumbers[$number] = true;

        $entryBasis = strtolower(trim((string)($raw['entryBasis'] ?? 'lines')));
        if (!in_array($entryBasis, IHYMNS_ROUND_ENTRY_BASES, true)) {
            throw new \InvalidArgumentException("entryBasis must be one of: " . implode(', ', IHYMNS_ROUND_ENTRY_BASES));
        }
        $entryLines = max(0, (int)($raw['entryLines'] ?? 0));
        $entryBeats = isset($raw['entryBeats']) && $raw['entryBeats'] !== null ? (float)$raw['entryBeats'] : null;
        $entryMs    = isset($raw['entryMs'])    && $raw['entryMs']    !== null ? (int)$raw['entryMs']      : null;

        if ($number === 1) {
            if ($entryBasis !== 'lines' || $entryLines !== 0) {
                throw new \InvalidArgumentException('Voice 1 must enter at the very start (entryBasis "lines", entryLines 0).');
            }
            $entryBeats = null;
            $entryMs    = null;
        } else {
            if ($entryBasis === 'beats' && ($entryBeats === null || $entryBeats < 0)) {
                throw new \InvalidArgumentException("Voice {$number}: entryBeats is required (and must be >= 0) when entryBasis is 'beats'.");
            }
            if ($entryBasis === 'ms' && ($entryMs === null || $entryMs < 0)) {
                throw new \InvalidArgumentException("Voice {$number}: entryMs is required (and must be >= 0) when entryBasis is 'ms'.");
            }
        }

        $startLineId = isset($raw['startLineId']) && $raw['startLineId'] !== null ? (int)$raw['startLineId'] : null;
        $endLineId   = isset($raw['endLineId'])   && $raw['endLineId']   !== null ? (int)$raw['endLineId']   : null;
        if (($startLineId !== null) !== ($endLineId !== null)) {
            throw new \InvalidArgumentException("Voice {$number}: a partner-song span needs BOTH a start and an end line, or neither.");
        }

        $timesThrough = isset($raw['timesThrough']) && $raw['timesThrough'] !== null
            ? max(1, min(8, (int)$raw['timesThrough']))
            : null;

        $out[] = [
            'number'            => $number,
            'partId'            => isset($raw['partId']) && $raw['partId'] !== null ? (int)$raw['partId'] : null,
            'label'             => vocalPartsNormalizeLabelInput($raw['label'] ?? null),
            'entryBasis'        => $entryBasis,
            'entryLines'        => $entryLines,
            'entryBeats'        => $entryBeats,
            'entryMs'           => $entryMs,
            'intervalSemitones' => isset($raw['intervalSemitones']) && $raw['intervalSemitones'] !== null ? (int)$raw['intervalSemitones'] : null,
            'startLineId'       => $startLineId,
            'endLineId'         => $endLineId,
            'timesThrough'      => $timesThrough,
            'sortOrder'         => 0,   // filled in below, after the contiguity check
        ];
    }

    usort($out, static fn(array $a, array $b): int => $a['number'] <=> $b['number']);
    foreach ($out as $i => &$v) {
        if ($v['number'] !== $i + 1) {
            throw new \InvalidArgumentException('Voice numbers must be exactly 1..N with no gaps.');
        }
        $v['sortOrder'] = $i;
    }
    unset($v);

    return $out;
}

/**
 * ELI5: turn "3 voices, entering 2 lines apart, twice through" into an
 * exact list of steps a Present-mode projector can render one at a time —
 * for each step, which line (or "waiting"/"finished") every voice is on,
 * and (when the round's timing supports it) how many real milliseconds
 * into the round that step falls. PURE — no DB, no clock, no side effect —
 * so this SAME function can be unit-tested here with fabricated data and
 * mirrored byte-for-byte in JavaScript for the projector's own client-side
 * preview, without either copy ever touching a network request.
 *
 * ALGORITHM (`Design pass 7` §3.3, transcribed exactly, with two corners
 * the plan's prose leaves unstated for CODA under an `ms` basis — flagged
 * below rather than silently guessed at):
 *
 * `n` = the number of subject lines. For voice `v`, `T_v` = that voice's
 * own `timesThrough` override, or the round's own `timesThrough` when it
 * has none. `e_v` (the voice's entry, in LINE STEPS) is `entryLines`
 * UNLESS the round's overall `basis` (below) is `'beats'` and this voice's
 * own `entryBeats` is set (`e_v = round(entryBeats / beatsPerLine)`), or
 * the basis is `'ms'` and this voice's own `entryMs` is set (`e_v` = the
 * smallest step index whose cumulative subject-line duration is at least
 * `entryMs` — a documented rounding: the offset the curator TYPED in
 * milliseconds is translated onto the nearest whole LINE-STEP boundary,
 * because every voice fundamentally moves in line-steps, real time is only
 * ever a LABEL on top of that grid).
 *
 * BASIS resolution: `'ms'` when every voice numbered above 1 has an
 * `entryMs` AND every subject line has a start+end ms in the two maps;
 * else `'beats'` when the round's own `bpm`/`beatsPerLine` are both
 * positive; else `'lines'` (no real-time label at all — the projector
 * still shows step-by-step progress, just with no clock).
 *
 * TOTAL STEPS `S`: `'complete'` -> the LATEST voice's own finish point
 * (`max_v(e_v + n*T_v)`) — a round only ends once EVERY voice has sung its
 * own full count; `'together'`/`'coda'` -> exactly `n * T_1` (voice 1's
 * own count) — every OTHER voice is cut off at that same boundary even if
 * its own count would need more or fewer steps (a longer per-voice
 * override is genuinely CUT MID-PHRASE by design, per the plan's own risk
 * note — that is not a bug in this function).
 *
 * PER STEP `i`, PER VOICE `v`: `p = i - e_v`; `line = -1` when `p < 0`
 * (still waiting to enter), `-2` when `p >= n*T_v` (already finished its
 * own count), else `p mod n` (an index into the SUBJECT line list).
 *
 * CODA (only under `endingMode === 'coda'`, appended AFTER the `S` subject
 * steps): one extra step per coda line, and — per the plan's own words —
 * "every voice's line = n + k", i.e. every voice shows the SAME coda
 * position simultaneously (a coda is sung together, in unison, not
 * staggered — the plan's schema COMMENT calls a coda "sung in unison after
 * the round", which this reading takes literally: the whole ensemble moves
 * through the coda lines in lockstep). `line` is always an index into the
 * CONCEPTUAL `lineIds ++ codaLineIds` array the caller's `roundShape` will
 * expose alongside this timeline.
 *
 * ⚠️ TWO CORNERS THE PLAN LEAVES SILENT — RESOLVED HERE, FLAGGED LOUDLY
 * (both are cosmetic-only: they only affect the `atMs` LABEL a coda step
 * carries, never which line/voice is showing, and both are trivially
 * revisited once a real timed round exists to test against):
 *   1. `atMs` for a coda step under the `'ms'` basis. The plan states this
 *      only for `'beats'` ("coda continues the grid"). Chosen here: the
 *      SAME idea, generalised — a coda step's `atMs` is the cumulative
 *      subject-round duration PLUS the cumulative duration of the coda
 *      lines already passed, using the SAME `$lineStartMs`/`$lineEndMs`
 *      maps (the caller may populate them for coda line ids too, in
 *      addition to subject ones). A coda line missing from those maps
 *      degrades that step's `atMs` to `null` (and every coda step after
 *      it, since the running total is no longer knowable) rather than
 *      guessing — `'ms'`-basis timing was never PROMISED for the coda,
 *      only for the subject, so losing it there is a graceful narrowing,
 *      not a silent wrong answer.
 *   2. Converting a raw `entryMs` into a line-step index (`e_v`) needs an
 *      upper search bound so a voice with a nonsensically large `entryMs`
 *      (or an all-zero-duration subject, were that ever possible) cannot
 *      loop forever. Capped at `max(8, roundTimesThrough) * n + 1` steps —
 *      generous relative to the schema's own `TimesThrough` ceiling of 8 —
 *      past which `e_v` simply stops growing (the voice is treated as
 *      entering at that capped step; in practice this only matters for
 *      deliberately-malformed input, since a curator-entered `entryMs`
 *      will always resolve well inside a round's real duration).
 *
 * @param array{timesThrough?:int,endingMode?:string,bpm?:?float,beatsPerLine?:?float,codaLineIds?:list<int>} $round
 * @param list<array{number:int,entryBasis?:string,entryLines?:int,entryBeats?:?float,entryMs?:?int,timesThrough?:?int}> $voices
 * @param list<int> $subjectLineIds
 * @param array<int,int> $lineStartMs  lineId => ms (subject AND, optionally, coda lines)
 * @param array<int,int> $lineEndMs
 * @return array{basis:string,stepMs:?int,steps:list<array{i:int,atMs:?int,voices:list<array{n:int,line:int}>}>}
 */
function lyricRoundTimeline(array $round, array $voices, array $subjectLineIds, array $lineStartMs, array $lineEndMs): array
{
    $n = count($subjectLineIds);
    if ($n === 0 || !$voices) {
        return ['basis' => 'lines', 'stepMs' => null, 'steps' => []];
    }

    $voices = array_values($voices);
    usort($voices, static fn(array $a, array $b): int => (int)($a['number'] ?? 0) <=> (int)($b['number'] ?? 0));

    $endingMode        = (string)($round['endingMode'] ?? 'complete');
    $roundTimesThrough = max(1, (int)($round['timesThrough'] ?? 2));
    $bpm               = $round['bpm'] ?? null;
    $beatsPerLine      = $round['beatsPerLine'] ?? null;
    $codaLineIds       = array_values($round['codaLineIds'] ?? []);

    /* ---- 1. Which basis? ---- */
    $msPossible = true;
    foreach ($voices as $v) {
        if ((int)($v['number'] ?? 0) > 1 && ($v['entryMs'] ?? null) === null) {
            $msPossible = false;
            break;
        }
    }
    if ($msPossible) {
        foreach ($subjectLineIds as $lid) {
            if (!isset($lineStartMs[$lid]) || !isset($lineEndMs[$lid])) {
                $msPossible = false;
                break;
            }
        }
    }
    if ($msPossible) {
        $basis = 'ms';
    } elseif (is_numeric($bpm) && (float)$bpm > 0 && is_numeric($beatsPerLine) && (float)$beatsPerLine > 0) {
        $basis = 'beats';
    } else {
        $basis = 'lines';
    }
    $stepMs = ($basis === 'beats') ? (int)round(60000 / (float)$bpm * (float)$beatsPerLine) : null;

    /* ---- 2. Subject-line durations + a memoised cumulative clock. ----
       cumDur(k) = total ms elapsed at the START of subject step k, cycling
       the n subject lines as k grows past n (k may legitimately exceed n —
       every voice keeps looping through its own passes). */
    $dur = [];
    if ($basis === 'ms') {
        foreach ($subjectLineIds as $lid) {
            $dur[$lid] = max(0, (int)$lineEndMs[$lid] - (int)$lineStartMs[$lid]);
        }
    }
    $cumCache = [0 => 0];
    $cumDur = function (int $k) use (&$cumCache, $subjectLineIds, $dur, $n): int {
        if (isset($cumCache[$k])) {
            return $cumCache[$k];
        }
        $from = 0;
        foreach (array_keys($cumCache) as $known) {
            if ($known <= $k && $known > $from) {
                $from = $known;
            }
        }
        $total = $cumCache[$from];
        for ($j = $from; $j < $k; $j++) {
            $total += $dur[$subjectLineIds[$j % $n]] ?? 0;
        }
        $cumCache[$k] = $total;
        return $total;
    };

    /* ---- 3. Per-voice entry offset e_v, in LINE STEPS. ---- */
    $maxMsSearch = max(8, $roundTimesThrough) * $n + 1;
    $entrySteps  = [];
    foreach ($voices as $v) {
        $num        = (int)($v['number'] ?? 0);
        $voiceBasis = (string)($v['entryBasis'] ?? 'lines');
        if ($basis === 'beats' && $voiceBasis === 'beats' && ($v['entryBeats'] ?? null) !== null && $beatsPerLine) {
            $entrySteps[$num] = (int)round(((float)$v['entryBeats']) / (float)$beatsPerLine);
        } elseif ($basis === 'ms' && $voiceBasis === 'ms' && ($v['entryMs'] ?? null) !== null) {
            $target = (int)$v['entryMs'];
            $k = 0;
            while ($k < $maxMsSearch && $cumDur($k) < $target) {
                $k++;
            }
            $entrySteps[$num] = $k;
        } else {
            $entrySteps[$num] = (int)($v['entryLines'] ?? 0);
        }
    }

    /* ---- 4. Per-voice T_v, and voice 1's own T for the together/coda total. ---- */
    $timesThroughByVoice = [];
    foreach ($voices as $v) {
        $num = (int)($v['number'] ?? 0);
        $timesThroughByVoice[$num] = ($v['timesThrough'] ?? null) !== null
            ? max(1, (int)$v['timesThrough'])
            : $roundTimesThrough;
    }
    $t1 = $timesThroughByVoice[1] ?? $roundTimesThrough;

    /* ---- 5. Total subject steps S. ---- */
    if ($endingMode === 'together' || $endingMode === 'coda') {
        $S = $n * $t1;
    } else {
        $S = 0;
        foreach ($voices as $v) {
            $num  = (int)($v['number'] ?? 0);
            $cand = $entrySteps[$num] + $n * $timesThroughByVoice[$num];
            if ($cand > $S) {
                $S = $cand;
            }
        }
    }

    /* ---- 6. Subject steps. ---- */
    $steps = [];
    for ($i = 0; $i < $S; $i++) {
        $stepVoices = [];
        foreach ($voices as $v) {
            $num = (int)($v['number'] ?? 0);
            $e   = $entrySteps[$num];
            $tv  = $timesThroughByVoice[$num];
            $p   = $i - $e;
            if ($p < 0) {
                $line = -1;
            } elseif ($p >= $n * $tv) {
                $line = -2;
            } else {
                $line = $p % $n;
            }
            $stepVoices[] = ['n' => $num, 'line' => $line];
        }
        $atMs = null;
        if ($basis === 'beats') {
            $atMs = $i * $stepMs;
        } elseif ($basis === 'ms') {
            $atMs = $cumDur($i);
        }
        $steps[] = ['i' => $i, 'atMs' => $atMs, 'voices' => $stepVoices];
    }

    /* ---- 7. Coda steps (endingMode === 'coda' only). ---- */
    $codaCount = count($codaLineIds);
    if ($endingMode === 'coda' && $codaCount > 0) {
        $subjectTotalMs = ($basis === 'ms') ? $cumDur($S) : null;
        $codaCumMs   = 0;
        $codaHasMs   = ($basis === 'ms');
        for ($j = 0; $j < $codaCount; $j++) {
            $stepVoices = [];
            foreach ($voices as $v) {
                $stepVoices[] = ['n' => (int)($v['number'] ?? 0), 'line' => $n + $j];
            }
            $atMs = null;
            if ($basis === 'beats') {
                $atMs = ($S + $j) * $stepMs;
            } elseif ($basis === 'ms' && $codaHasMs) {
                $lid = $codaLineIds[$j];
                if (isset($lineStartMs[$lid]) && isset($lineEndMs[$lid])) {
                    $atMs = $subjectTotalMs + $codaCumMs;
                    $codaCumMs += max(0, (int)$lineEndMs[$lid] - (int)$lineStartMs[$lid]);
                } else {
                    $codaHasMs = false;   // graceful narrowing — see doc-block corner (1)
                }
            }
            $steps[] = ['i' => $S + $j, 'atMs' => $atMs, 'voices' => $stepVoices];
        }
    }

    return ['basis' => $basis, 'stepMs' => $stepMs, 'steps' => $steps];
}

/**
 * ELI5: given the round rows and round-VOICE rows a caller already fetched
 * (BEFORE a batch of line deletes runs) and the ids of the lines about to be
 * deleted, work out exactly which round ids need to be flagged
 * `needs-review` — PURE, no DB, so this decision can be proven by a truth
 * table (tests/php/test-vocal-parts-core.php) without a live mysqli, the
 * same posture `lyricRoundTimeline()` above already established for the
 * projector's own step-by-step math.
 *
 * #2073 commit 5 cross-review finding F4: the version of this decision that
 * shipped first (inline inside `includes/lyric_lines_sync.php`'s
 * `lyricLinesSnapshotDeletedEnrichment()`) only ever looked at the ROUND's
 * own four line columns (`StartLineId`/`EndLineId`/`CodaStartLineId`/
 * `CodaEndLineId`). A VOICE's own partner-song span
 * (`tblLyricRoundVoices.StartLineId`/`EndLineId`) can be invalidated by the
 * SAME line delete (`ON DELETE SET NULL` — see that column's own migration
 * COMMENT) while the PARENT round's own fields are completely untouched,
 * and that case was silently missed entirely: the round was never even
 * fetched (the original SQL only searched `tblLyricRounds`' own columns),
 * so it was neither snapshotted nor flagged — a partner-song voice's "both
 * or neither" span invariant could break with no trace anywhere. The
 * caller (`lyric_lines_sync.php`) now fetches every round that EITHER its
 * own fields OR any of its voices' own fields could implicate, and hands
 * BOTH row sets here so this one function makes the whole decision.
 *
 * A round whose OWN `StartLineId` is being deleted is CASCADE-deleted in
 * full (`ON DELETE CASCADE`) — there is no surviving row to flag, so it is
 * excluded here exactly as the original logic already did, regardless of
 * whether the reason this round is even being LOOKED AT is one of its own
 * fields or one of its voices'.
 *
 * @param list<array<string,mixed>> $rounds       full tblLyricRounds rows (SELECT *, pre-delete)
 * @param list<array<string,mixed>> $roundVoices  full tblLyricRoundVoices rows for those SAME rounds (SELECT *, pre-delete)
 * @param list<int>                 $deleteIds    line ids about to be deleted
 * @return list<int>  round ids to flag `needs-review`
 */
function lyricRoundsToFlagFromRows(array $rounds, array $roundVoices, array $deleteIds): array
{
    $deleteSet = array_flip(array_map('intval', $deleteIds));

    $voicesByRound = [];
    foreach ($roundVoices as $rv) {
        $voicesByRound[(int)$rv['RoundId']][] = $rv;
    }

    $flag = [];
    foreach ($rounds as $round) {
        $roundId = (int)$round['Id'];
        if (isset($deleteSet[(int)$round['StartLineId']])) {
            continue;   // cascade-deleted in full — nothing survives to flag
        }

        $loses = isset($deleteSet[(int)($round['EndLineId'] ?? 0)])
            || ($round['CodaStartLineId'] !== null && isset($deleteSet[(int)$round['CodaStartLineId']]))
            || ($round['CodaEndLineId']   !== null && isset($deleteSet[(int)$round['CodaEndLineId']]));

        if (!$loses) {
            foreach ($voicesByRound[$roundId] ?? [] as $v) {
                if (($v['StartLineId'] !== null && isset($deleteSet[(int)$v['StartLineId']]))
                    || ($v['EndLineId']   !== null && isset($deleteSet[(int)$v['EndLineId']]))
                ) {
                    $loses = true;
                    break;
                }
            }
        }

        if ($loses) {
            $flag[] = $roundId;
        }
    }

    return $flag;
}

/* =====================================================================
 * SHAPE + READ
 * ===================================================================== */

/**
 * ELI5: turn a raw `tblLyricRounds` row + its `tblLyricRoundVoices` rows
 * into the ONE wire shape every consumer (the editor, the public read
 * path, the projector) reads.
 *
 * @param array<string,mixed>            $round
 * @param list<array<string,mixed>>      $voices     raw tblLyricRoundVoices rows, ORDER BY VoiceNumber
 * @param array<int,array<string,mixed>> $partsById  vocalPartsShape() results, keyed by id (for displayLabel fallback)
 */
function lyricRoundShape(array $round, array $voices, array $partsById): array
{
    $voiceShapes = [];
    foreach ($voices as $v) {
        $partId = $v['VocalPartId'] !== null ? (int)$v['VocalPartId'] : null;
        $label  = ($v['Label'] !== null && trim((string)$v['Label']) !== '') ? (string)$v['Label'] : null;
        $displayLabel = $label
            ?? ($partId !== null && isset($partsById[$partId]) ? $partsById[$partId]['displayLabel'] : null)
            ?? ('Voice ' . (int)$v['VoiceNumber']);
        $voiceShapes[] = [
            'id'                => (int)$v['Id'],
            'number'            => (int)$v['VoiceNumber'],
            'partId'            => $partId,
            'label'             => $label,
            'displayLabel'      => $displayLabel,
            'entryBasis'        => (string)$v['EntryBasis'],
            'entryLines'        => (int)$v['EntryLines'],
            'entryBeats'        => $v['EntryBeats'] !== null ? (float)$v['EntryBeats'] : null,
            'entryMs'           => $v['EntryMs'] !== null ? (int)$v['EntryMs'] : null,
            'intervalSemitones' => $v['IntervalSemitones'] !== null ? (int)$v['IntervalSemitones'] : null,
            'startLineId'       => $v['StartLineId'] !== null ? (int)$v['StartLineId'] : null,
            'endLineId'         => $v['EndLineId'] !== null ? (int)$v['EndLineId'] : null,
            'timesThrough'      => $v['TimesThrough'] !== null ? (int)$v['TimesThrough'] : null,
            'sortOrder'         => (int)$v['SortOrder'],
        ];
    }

    return [
        'id'              => (int)$round['Id'],
        'kind'            => (string)$round['Kind'],
        'label'           => ($round['Label'] !== null && trim((string)$round['Label']) !== '') ? (string)$round['Label'] : null,
        'startLineId'     => (int)$round['StartLineId'],
        'endLineId'       => $round['EndLineId'] !== null ? (int)$round['EndLineId'] : null,
        'timesThrough'    => (int)$round['TimesThrough'],
        'endingMode'      => (string)$round['EndingMode'],
        'codaStartLineId' => $round['CodaStartLineId'] !== null ? (int)$round['CodaStartLineId'] : null,
        'codaEndLineId'   => $round['CodaEndLineId']   !== null ? (int)$round['CodaEndLineId']   : null,
        'bpm'             => $round['Bpm']          !== null ? (float)$round['Bpm']          : null,
        'beatsPerBar'     => $round['BeatsPerBar']  !== null ? (int)$round['BeatsPerBar']    : null,
        'beatsPerLine'    => $round['BeatsPerLine'] !== null ? (float)$round['BeatsPerLine'] : null,
        'integrityStatus' => (string)($round['IntegrityStatus'] ?? 'ok'),
        'source'          => (string)$round['Source'],
        'sortOrder'       => (int)$round['SortOrder'],
        'voices'          => $voiceShapes,
    ];
}

/**
 * Every round on one lyrics version, shaped, `ORDER BY SortOrder, Id`, each
 * with its voices nested `ORDER BY VoiceNumber`. `[]` on an un-migrated
 * install (mirrors every other reader in this feature — `SongData.php`
 * already calls this behind a `function_exists()` guard for exactly that
 * reason, so this file being ABSENT and this file being PRESENT-but-empty
 * degrade identically).
 *
 * @return list<array>  lyricRoundShape() results
 */
function lyricRoundsForVersion(\mysqli $db, int $lyricsId): array
{
    if (!lyricRoundsReady($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT * FROM tblLyricRounds WHERE LyricsId = ? ORDER BY SortOrder, Id'
    );
    bindParamSafe(__FUNCTION__ . ':rounds', $stmt, 'i', $lyricsId);
    $stmt->execute();
    $rounds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$rounds) {
        return [];
    }

    $roundIds = array_map(static fn($r) => (int)$r['Id'], $rounds);
    $place = implode(',', array_fill(0, count($roundIds), '?'));
    $vStmt = $db->prepare("SELECT * FROM tblLyricRoundVoices WHERE RoundId IN ({$place}) ORDER BY RoundId, VoiceNumber");
    bindParamSafe(__FUNCTION__ . ':voices', $vStmt, str_repeat('i', count($roundIds)), ...$roundIds);
    $vStmt->execute();
    /* get_result() may be called only ONCE for an executed statement — a second
       call returns false, and false->fetch_assoc() is a fatal error. So take the
       result set once, then read rows from it. Calling it inside the loop
       condition crashes on any round with more than one voice. (#2073) */
    $vRes = $vStmt->get_result();
    $voicesByRound = [];
    $allPartIds = [];
    while ($row = $vRes->fetch_assoc()) {
        $voicesByRound[(int)$row['RoundId']][] = $row;
        if ($row['VocalPartId'] !== null) {
            $allPartIds[(int)$row['VocalPartId']] = true;
        }
    }
    $vStmt->close();

    $partsById = [];
    if ($allPartIds) {
        $partIds = array_keys($allPartIds);
        $pPlace  = implode(',', array_fill(0, count($partIds), '?'));
        $pStmt   = $db->prepare(
            "SELECT vp.*, m.Name AS musician_name FROM tblVocalParts vp
               LEFT JOIN tblMusicians m ON m.Id = vp.MusicianId
              WHERE vp.Id IN ({$pPlace})"
        );
        bindParamSafe(__FUNCTION__ . ':parts', $pStmt, str_repeat('i', count($partIds)), ...$partIds);
        $pStmt->execute();
        /* Same rule as above: one get_result() per execute. */
        $pRes = $pStmt->get_result();
        while ($row = $pRes->fetch_assoc()) {
            $musicianName = $row['musician_name'] ?? null;
            unset($row['musician_name']);
            $partsById[(int)$row['Id']] = vocalPartsShape($row, $musicianName !== null ? (string)$musicianName : null);
        }
        $pStmt->close();
    }

    $out = [];
    foreach ($rounds as $r) {
        $out[] = lyricRoundShape($r, $voicesByRound[(int)$r['Id']] ?? [], $partsById);
    }
    return $out;
}

/**
 * Resolve one round, ownership-checked against `$songId` (a round's
 * `LyricsId` must be a version of that song) — the same shape as
 * `vocalPartsResolvePart()` one file over. `null` on not-found-or-not-yours
 * (the caller maps that to a 404, never distinguishing the two).
 */
function lyricRoundResolve(\mysqli $db, string $songId, int $roundId): ?array
{
    /* @lyrics-version-exempt: a specific $roundId is already known, so this
       JOIN through tblLyrics only needs to confirm the round's OWN lyrics
       version belongs to $songId at all (the ownership/IDOR check this
       function exists for) — never deciding which version is the song's
       "current" one. The identical exemption vocalPartsResolvePart() and
       vocalPartsSpanUpsert()/…SpanDelete() carry one file over. */
    $stmt = $db->prepare(
        'SELECT r.* FROM tblLyricRounds r
           JOIN tblLyrics ly ON ly.Id = r.LyricsId
          WHERE r.Id = ? AND ly.SongId = ?
          LIMIT 1'
    );
    bindParamSafe(__FUNCTION__, $stmt, 'is', $roundId, $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* =====================================================================
 * WRITE
 * ===================================================================== */

/**
 * ELI5: create or edit ONE round — its subject-line span, timing, ending
 * behaviour, and its whole voice list (replaced as a SET — every write
 * states the complete voice list, never a partial patch).
 *
 * Input `{id?, kind?, label?, startLineId, endLineId?, timesThrough?,
 * endingMode?, codaStartLineId?, codaEndLineId?, bpm?, beatsPerBar?,
 * beatsPerLine?, voices:[...]}` — `voices` shape validated by
 * `lyricRoundsValidateVoicesShape()` (pure, above); everything else
 * validated here (needs a DB for `startLineId`/`endLineId`/`partId`
 * ownership).
 *
 * IDOR: `startLineId`/`endLineId`/`codaStartLineId`/`codaEndLineId`, and
 * every voice's own `partId`/`startLineId`/`endLineId`, ALL go through
 * `vocalPartsResolveLines()` / `vocalPartsResolvePart()` (rule #22 — the
 * same resolvers every other write in this feature uses, never a second
 * ad-hoc JOIN) — a round can never point at a line or part outside this
 * song's own primary lyrics version.
 *
 * VOICES ARE REPLACED AS A SET: every existing `tblLyricRoundVoices` row
 * for this round whose `VoiceNumber` is not in the new list is DELETED;
 * every number in the new list is `INSERT ... ON DUPLICATE KEY UPDATE`
 * against `uq_Round_Voice (RoundId, VoiceNumber)`.
 *
 * @param array<string,mixed> $input
 * @return array  `lyricRoundShape()` of the row as it now stands
 * @throws \InvalidArgumentException  bad kind/endingMode/timesThrough/bpm/
 *                                     beatsPerBar/beatsPerLine, a missing or
 *                                     mismatched coda span, or anything
 *                                     `lyricRoundsValidateVoicesShape()` rejects
 * @throws \RuntimeException          `id` given but not found for this song,
 *                                     or a referenced line/part is not on
 *                                     this song's primary lyrics version
 */
function lyricRoundUpsert(\mysqli $db, string $songId, array $input, ?int $userId = null): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'line_enrichment.php';   // lineEnrichmentNormalizeVocab()
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';  // lyricLinesEnsurePrimaryVersion()

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $existing = null;
    if ($id > 0) {
        $existing = lyricRoundResolve($db, $songId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Round not found for this song.');
        }
    }

    $kindInput = isset($input['kind']) ? (string)$input['kind'] : ($existing['Kind'] ?? 'round');
    $kind = lineEnrichmentNormalizeVocab($kindInput, IHYMNS_ROUND_KINDS);
    if ($kind === null) {
        throw new \InvalidArgumentException('kind must be one of: ' . implode(', ', IHYMNS_ROUND_KINDS));
    }

    $endingModeInput = isset($input['endingMode']) ? (string)$input['endingMode'] : ($existing['EndingMode'] ?? 'complete');
    $endingMode = lineEnrichmentNormalizeVocab($endingModeInput, IHYMNS_ROUND_ENDINGS);
    if ($endingMode === null) {
        throw new \InvalidArgumentException('endingMode must be one of: ' . implode(', ', IHYMNS_ROUND_ENDINGS));
    }

    $timesThrough = array_key_exists('timesThrough', $input) && $input['timesThrough'] !== null
        ? (int)$input['timesThrough']
        : (int)($existing['TimesThrough'] ?? 2);
    if ($timesThrough < 1 || $timesThrough > 8) {
        throw new \InvalidArgumentException('timesThrough must be between 1 and 8.');
    }

    $label = array_key_exists('label', $input)
        ? vocalPartsNormalizeLabelInput($input['label'])
        : ($existing['Label'] ?? null);

    $startLineIdInput = array_key_exists('startLineId', $input) ? $input['startLineId'] : ($existing['StartLineId'] ?? null);
    if ($startLineIdInput === null) {
        throw new \InvalidArgumentException('startLineId is required.');
    }
    $endLineIdInput = array_key_exists('endLineId', $input) ? $input['endLineId'] : ($existing['EndLineId'] ?? null);

    $spanIds = [(int)$startLineIdInput];
    if ($endLineIdInput !== null) {
        $spanIds[] = (int)$endLineIdInput;
    }
    $resolvedSpan = vocalPartsResolveLines($db, $songId, $spanIds);   // IDOR + same-primary-version guard
    $lyricsId = $resolvedSpan[(int)$startLineIdInput]['lyricsId'];
    $startLineId = (int)$startLineIdInput;
    $endLineId   = $endLineIdInput !== null ? (int)$endLineIdInput : null;
    if ($endLineId !== null
        && $resolvedSpan[$endLineId]['sortOrder'] < $resolvedSpan[$startLineId]['sortOrder']
    ) {
        throw new \InvalidArgumentException('endLineId must not come before startLineId.');
    }

    $codaStartInput = array_key_exists('codaStartLineId', $input) ? $input['codaStartLineId'] : ($existing['CodaStartLineId'] ?? null);
    $codaEndInput   = array_key_exists('codaEndLineId', $input)   ? $input['codaEndLineId']   : ($existing['CodaEndLineId']   ?? null);
    $codaStartLineId = $codaStartInput !== null ? (int)$codaStartInput : null;
    $codaEndLineId   = $codaEndInput   !== null ? (int)$codaEndInput   : null;
    if ($endingMode === 'coda') {
        if ($codaStartLineId === null) {
            throw new \InvalidArgumentException("A 'coda' ending needs a codaStartLineId.");
        }
    } elseif ($codaStartLineId !== null || $codaEndLineId !== null) {
        throw new \InvalidArgumentException('codaStartLineId/codaEndLineId only apply when endingMode is "coda".');
    }
    if ($codaStartLineId !== null) {
        $codaIds = [$codaStartLineId];
        if ($codaEndLineId !== null) {
            $codaIds[] = $codaEndLineId;
        }
        $resolvedCoda = vocalPartsResolveLines($db, $songId, $codaIds);
        if ($resolvedCoda[$codaStartLineId]['lyricsId'] !== $lyricsId) {
            throw new \RuntimeException('The coda span must be on the same lyrics version as the round itself.');
        }
        if ($codaEndLineId !== null
            && $resolvedCoda[$codaEndLineId]['sortOrder'] < $resolvedCoda[$codaStartLineId]['sortOrder']
        ) {
            throw new \InvalidArgumentException('codaEndLineId must not come before codaStartLineId.');
        }
    }

    $bpm = array_key_exists('bpm', $input) && $input['bpm'] !== null ? (float)$input['bpm'] : ($existing['Bpm'] !== null ? (float)$existing['Bpm'] : null);
    if ($bpm !== null && $bpm <= 0) {
        throw new \InvalidArgumentException('bpm must be greater than 0.');
    }
    $beatsPerBar = array_key_exists('beatsPerBar', $input) && $input['beatsPerBar'] !== null
        ? (int)$input['beatsPerBar']
        : ($existing['BeatsPerBar'] !== null ? (int)$existing['BeatsPerBar'] : null);
    if ($beatsPerBar !== null && ($beatsPerBar < 1 || $beatsPerBar > 16)) {
        throw new \InvalidArgumentException('beatsPerBar must be between 1 and 16.');
    }
    $beatsPerLine = array_key_exists('beatsPerLine', $input) && $input['beatsPerLine'] !== null
        ? (float)$input['beatsPerLine']
        : ($existing['BeatsPerLine'] !== null ? (float)$existing['BeatsPerLine'] : null);
    if ($beatsPerLine !== null && $beatsPerLine <= 0) {
        throw new \InvalidArgumentException('beatsPerLine must be greater than 0.');
    }

    $sortOrder = array_key_exists('sortOrder', $input) && $input['sortOrder'] !== null
        ? max(0, (int)$input['sortOrder'])
        : (int)($existing['SortOrder'] ?? 0);

    $voices = lyricRoundsValidateVoicesShape($input['voices'] ?? []);

    /* Ownership-check every voice's own partId / partner-song span, all
       against the SAME $lyricsId the round itself is on. */
    foreach ($voices as $v) {
        if ($v['partId'] !== null) {
            $part = vocalPartsResolvePart($db, $songId, $v['partId']);
            if ($part === null || (int)$part['LyricsId'] !== $lyricsId) {
                throw new \RuntimeException("Voice {$v['number']}'s partId must belong to this song's own lyrics version.");
            }
        }
        if ($v['startLineId'] !== null) {
            $vIds = [$v['startLineId']];
            if ($v['endLineId'] !== null) {
                $vIds[] = $v['endLineId'];
            }
            $vResolved = vocalPartsResolveLines($db, $songId, $vIds);
            if ($v['endLineId'] !== null
                && $vResolved[$v['endLineId']]['sortOrder'] < $vResolved[$v['startLineId']]['sortOrder']
            ) {
                throw new \InvalidArgumentException("Voice {$v['number']}'s own end line must not come before its start line.");
            }
        }
    }

    if ($id > 0) {
        $upd = $db->prepare(
            'UPDATE tblLyricRounds SET Kind = ?, Label = ?, StartLineId = ?, EndLineId = ?, TimesThrough = ?,
                    EndingMode = ?, CodaStartLineId = ?, CodaEndLineId = ?, Bpm = ?, BeatsPerBar = ?,
                    BeatsPerLine = ?, SortOrder = ?
              WHERE Id = ?'
        );
        bindParamSafe(
            __FUNCTION__ . ':update',
            $upd,
            implode('', ['s', 's', 'i', 'i', 'i', 's', 'i', 'i', 'd', 'i', 'd', 'i', 'i']),
            $kind,
            $label,
            $startLineId,
            $endLineId,
            $timesThrough,
            $endingMode,
            $codaStartLineId,
            $codaEndLineId,
            $bpm,
            $beatsPerBar,
            $beatsPerLine,
            $sortOrder,
            $id
        );
        $upd->execute();
        $upd->close();
        $roundId = $id;
    } else {
        $ins = $db->prepare(
            'INSERT INTO tblLyricRounds
                (LyricsId, Kind, Label, StartLineId, EndLineId, TimesThrough, EndingMode,
                 CodaStartLineId, CodaEndLineId, Bpm, BeatsPerBar, BeatsPerLine, SortOrder, Source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'ihymns\')'
        );
        bindParamSafe(
            __FUNCTION__ . ':insert',
            $ins,
            implode('', ['i', 's', 's', 'i', 'i', 'i', 's', 'i', 'i', 'd', 'i', 'd', 'i']),
            $lyricsId,
            $kind,
            $label,
            $startLineId,
            $endLineId,
            $timesThrough,
            $endingMode,
            $codaStartLineId,
            $codaEndLineId,
            $bpm,
            $beatsPerBar,
            $beatsPerLine,
            $sortOrder
        );
        $ins->execute();
        $roundId = (int)$db->insert_id;
        $ins->close();
    }

    /* Voices replaced as a SET: delete numbers no longer present, upsert
       every number the caller sent. */
    $keepNumbers = array_map(static fn($v) => $v['number'], $voices);
    if ($keepNumbers) {
        $place = implode(',', array_fill(0, count($keepNumbers), '?'));
        $del = $db->prepare("DELETE FROM tblLyricRoundVoices WHERE RoundId = ? AND VoiceNumber NOT IN ({$place})");
        bindParamSafe(__FUNCTION__ . ':voices-prune', $del, 'i' . str_repeat('i', count($keepNumbers)), $roundId, ...$keepNumbers);
    } else {
        $del = $db->prepare('DELETE FROM tblLyricRoundVoices WHERE RoundId = ?');
        bindParamSafe(__FUNCTION__ . ':voices-prune-all', $del, 'i', $roundId);
    }
    $del->execute();
    $del->close();

    $vIns = $db->prepare(
        'INSERT INTO tblLyricRoundVoices
            (RoundId, VoiceNumber, VocalPartId, Label, EntryBasis, EntryLines, EntryBeats, EntryMs,
             IntervalSemitones, StartLineId, EndLineId, TimesThrough, SortOrder)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            VocalPartId = VALUES(VocalPartId), Label = VALUES(Label), EntryBasis = VALUES(EntryBasis),
            EntryLines = VALUES(EntryLines), EntryBeats = VALUES(EntryBeats), EntryMs = VALUES(EntryMs),
            IntervalSemitones = VALUES(IntervalSemitones), StartLineId = VALUES(StartLineId),
            EndLineId = VALUES(EndLineId), TimesThrough = VALUES(TimesThrough), SortOrder = VALUES(SortOrder)'
    );
    foreach ($voices as $v) {
        bindParamSafe(
            __FUNCTION__ . ':voice-upsert',
            $vIns,
            implode('', ['i', 'i', 'i', 's', 's', 'i', 'd', 'i', 'i', 'i', 'i', 'i', 'i']),
            $roundId,
            $v['number'],
            $v['partId'],
            $v['label'],
            $v['entryBasis'],
            $v['entryLines'],
            $v['entryBeats'],
            $v['entryMs'],
            $v['intervalSemitones'],
            $v['startLineId'],
            $v['endLineId'],
            $v['timesThrough'],
            $v['sortOrder']
        );
        $vIns->execute();
    }
    $vIns->close();

    $round = lyricRoundResolve($db, $songId, $roundId);
    if ($round === null) {
        throw new \RuntimeException('Round not found for this song.');   // defensive — just wrote it
    }
    $vStmt = $db->prepare('SELECT * FROM tblLyricRoundVoices WHERE RoundId = ? ORDER BY VoiceNumber');
    bindParamSafe(__FUNCTION__ . ':refetch-voices', $vStmt, 'i', $roundId);
    $vStmt->execute();
    $voiceRows = $vStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $vStmt->close();

    $partsById = [];
    foreach ($voiceRows as $vr) {
        if ($vr['VocalPartId'] === null) {
            continue;
        }
        $pid = (int)$vr['VocalPartId'];
        if (!isset($partsById[$pid])) {
            $prow = vocalPartsResolvePart($db, $songId, $pid);
            if ($prow !== null) {
                $partsById[$pid] = vocalPartsShape($prow);
            }
        }
    }

    return lyricRoundShape($round, $voiceRows, $partsById);
}

/**
 * IDOR via `lyricRoundResolve()`. CASCADE drops the round's own voices.
 *
 * @throws \RuntimeException  not found for this song
 */
function lyricRoundDelete(\mysqli $db, string $songId, int $roundId): bool
{
    $existing = lyricRoundResolve($db, $songId, $roundId);
    if ($existing === null) {
        throw new \RuntimeException('Round not found for this song.');
    }
    $stmt = $db->prepare('DELETE FROM tblLyricRounds WHERE Id = ?');
    bindParamSafe(__FUNCTION__, $stmt, 'i', $roundId);
    $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}
