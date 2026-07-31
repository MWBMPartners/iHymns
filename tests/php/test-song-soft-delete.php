<?php

declare(strict_types=1);

/**
 * test-song-soft-delete.php — the soft-delete module BEHAVES (#1694 commit 2)
 * ===========================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * This file does not read includes/song_soft_delete.php for the right words —
 * it CALLS it, against the shared pretend database that writes down every
 * statement, across every state that matters: migrated, un-migrated, and
 * "the probe itself broke".
 *
 * WHAT IS PINNED, AND WHY EACH PIN EXISTS
 * ---------------------------------------
 *   - songVisibleSqlFor(): the exact fragment both branches mint ('1=1' is the
 *     whole un-migrated trick — call sites embed it unconditionally), and that
 *     a hostile alias is REFUSED, not interpolated (rule #5 — this string goes
 *     into SQL). Correct-but-unusual aliases must stay ACCEPTED (rule #34's
 *     other half: a guard that fails on correct code gets weakened or deleted).
 *   - songSoftDeletedHolds() FAILS OPEN: it only suppresses a heuristic and
 *     upgrades a 404 to a 410, so a broken probe must never 404 live pages
 *     (the songRedirectClaimsId() contract, asserted with the same double).
 *   - songSoftDeleteReady() memoises SUCCESS ONLY: a transient probe failure
 *     must not pin the gate for the rest of the request.
 *   - songSoftDelete() prepares an UPDATE and NEVER a DELETE, and NO statement
 *     touching tblSongRedirects — the load-bearing absences: a DELETE is the
 *     CASCADE disaster this feature exists to prevent, and a redirect write
 *     cannot be un-written on restore (#1694 plan §4).
 *   - Every wrong-state call REFUSES with the contracted HTTP status (rule
 *     #35: status is the contract, never the prose): 409 un-migrated /
 *     already-deleted / not-deleted, 422 unknown reason, 404 absent — and an
 *     un-migrated write NEVER falls back to a hard delete.
 *
 * WHY CHILD PROCESSES (same reason as test-song-relocate-gates.php)
 * -----------------------------------------------------------------
 * songSoftDeleteReady() memoises per request (`static`), so within one process
 * the migration state can be settled exactly once. 'unmigrated' / 'migrated' /
 * 'probe-failure' therefore each run in their own process; the parent requires
 * a zero exit AND the mode's completion sentinel, so a child that fatals early
 * is a failure, never a silent skip. The PURE functions (fragment minting, the
 * verdict, the vocabulary) have no memo and run in the parent.
 *
 * MUTATIONS THIS SUITE WAS PROVEN RED AGAINST (recorded per house style):
 *   M1 songVisibleSqlFor() '1=1' branch inverted (ready ignored)   → red
 *   M2 alias validation deleted (hostile alias interpolated)       → red
 *   M3 holds' catch removed (probe failure propagates)             → red
 *   M4 songSoftDelete's UPDATE respelled as DELETE FROM tblSongs   → red
 *   M5 a songRedirectWrite-style INSERT added to songSoftDelete    → red
 *       (surfaces as the double's faithful 1146 — tblSongRedirects is
 *       optional and unset in the fixture — killing the mode's sentinel)
 *   M6 un-migrated refusal downgraded to fall through and write    → red
 *   M7 verdict's already-deleted clause dropped (delete twice ok)  → red
 *   M8 ready's lockstep `=== 5` weakened to `>= 1`                 → red
 *       (the partial-apply mode exists for exactly this mutant)
 *
 *   php tests/php/test-song-soft-delete.php            # parent: all modes
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/song_soft_delete.php  the module under test
 * @see tests/php/lib/mysqli_doubles.php                  the shared doubles
 * @see tests/php/test-song-relocate-gates.php            the harness this copies
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/appWeb/public_html/includes/song_soft_delete.php';
require_once __DIR__ . '/lib/mysqli_doubles.php';

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        " . str_replace("\n", "\n        ", $detail) . "\n"; }
    }
}

/* ===================================================================== *
 *  THE MODES
 * ===================================================================== */

const SOFT_DELETE_MODE_ENV = 'IHYMNS_SOFT_DELETE_MODE';
const SOFT_DELETE_MODES    = ['unmigrated', 'partial-apply', 'migrated', 'probe-failure'];

$mode = getenv(SOFT_DELETE_MODE_ENV) ?: '';

if ($mode === '') {
    /* ------------------------------------------------------------------- *
     *  PARENT — the PURE surface first (no DB, no memo), then each DB mode
     *  in its own process.
     * ------------------------------------------------------------------- */
    echo "0 — the pure surface: fragments, vocabulary, the one verdict\n";

    /* ---- songVisibleSqlFor(): exact strings, both branches -------------- */
    ok("migrated + alias 's' mints exactly 's.IsDeleted = 0'",
       songVisibleSqlFor(true, 's') === 's.IsDeleted = 0',
       'got ' . var_export(songVisibleSqlFor(true, 's'), true));
    ok("migrated + empty alias mints exactly 'IsDeleted = 0'",
       songVisibleSqlFor(true, '') === 'IsDeleted = 0');
    ok("migrated + a longer alias prefixes it verbatim",
       songVisibleSqlFor(true, 'songs') === 'songs.IsDeleted = 0');
    ok("un-migrated mints exactly '1=1' — the degrade that keeps every embedding valid",
       songVisibleSqlFor(false, 's') === '1=1',
       'anything else breaks the "embed unconditionally after WHERE/AND" contract '
       . 'and forces per-site branching, which is the drift the shared unit exists to prevent');
    ok("un-migrated + empty alias is also '1=1'",
       songVisibleSqlFor(false, '') === '1=1');

    /* ---- alias validation: hostile shapes REFUSED in BOTH branches ------ *
     * The un-migrated branch never interpolates the alias, but a hostile
     * alias is a caller bug and must not be masked by migration state — a
     * page that "works" on dev (un-migrated) and injects on prod is the
     * worst possible split. */
    $hostile = [
        's; DROP TABLE tblSongs; --' => 'a classic injection tail',
        's`.`IsDeleted` = 1 OR `x'   => 'backtick escape',
        "s\n"                        => "a trailing newline — the \$-vs-\\z PCRE trap (sql_identifier.php)",
        '1abc'                       => 'leading digit (outside the stated alias grammar)',
        'tbl$x'                      => 'a $ — legal to MySQL, outside the stated alias grammar',
        's.x'                        => 'a dotted path is not one identifier',
        'a b'                        => 'whitespace',
    ];
    foreach ($hostile as $alias => $why) {
        foreach ([true, false] as $ready) {
            $threw = false;
            try {
                songVisibleSqlFor($ready, $alias);
            } catch (\InvalidArgumentException $e) {
                $threw = true;
            }
            ok('hostile alias ' . json_encode($alias) . ' is refused (ready=' . var_export($ready, true) . ") — {$why}",
               $threw,
               'it was ACCEPTED — this string is interpolated into SQL (rule #5)');
        }
    }

    /* ---- …and correct-but-different aliases stay ACCEPTED --------------- */
    foreach (['s2', '_x', 'SongsAlias', 'b'] as $goodAlias) {
        $threw = false;
        $got   = null;
        try {
            $got = songVisibleSqlFor(true, $goodAlias);
        } catch (\Throwable $e) {
            $threw = true;
        }
        ok("valid alias '{$goodAlias}' is accepted and prefixed",
           !$threw && $got === $goodAlias . '.IsDeleted = 0',
           'a validator that refuses correct identifiers gets weakened or deleted (rule #34)');
    }

    /* ---- the reason vocabulary ------------------------------------------ */
    $reasons = songDeleteReasons();
    ok('songDeleteReasons() is a non-empty key => label map',
       is_array($reasons) && $reasons !== [] && array_keys($reasons) !== range(0, count($reasons) - 1));
    $tooLong = array_filter(array_keys($reasons), static fn($k) => strlen((string)$k) > 50);
    ok('every reason key fits tblSongs.DeletedReason VARCHAR(50) — derived from the map, not typed',
       $tooLong === [],
       'too long: ' . json_encode(array_values($tooLong)));
    ok('NULL / blank reasons are valid (the column is optional)',
       songDeleteReasonValid(null) && songDeleteReasonValid('') && songDeleteReasonValid('   '));
    $allKnownValid = true;
    foreach (array_keys($reasons) as $k) {
        if (!songDeleteReasonValid((string)$k)) { $allKnownValid = false; }
    }
    ok('every key in the map validates — the map IS the allow-list',
       $allKnownValid);
    ok("an unknown reason is refused — free-text drift is what VARCHAR-not-ENUM trades away",
       !songDeleteReasonValid('nonsense-reason'));

    /* ---- the ONE verdict: every clause, in its contracted order --------- */
    $v = static fn(...$a) => songSoftDeleteVerdict(...$a);
    ok('empty id → 400, whatever else is true',
       $v('delete', '', false, null)['status'] === 400
       && $v('delete', '', true, 1)['status'] === 400);
    ok('un-migrated → 409, and it beats the absent-row clause (partial state stays honest)',
       $v('delete', 'MP-1', false, null)['status'] === 409,
       'a driver that stopped at the migration gate passes row=null; reading that as 404 '
       . 'would report "not found" for a song that is right there');
    ok('un-migrated refusal is ok=false — NEVER a fallback to a hard delete',
       $v('delete', 'MP-1', false, null)['ok'] === false);
    ok('unknown reason → 422 (delete only), ahead of the row clauses',
       $v('delete', 'MP-1', true, null, 'bogus')['status'] === 422
       && $v('delete', 'MP-1', true, 0, 'bogus')['status'] === 422);
    ok('restore/purge ignore the reason argument entirely',
       $v('restore', 'MP-1', true, 1, 'bogus')['ok'] === true
       && $v('purge', 'MP-1', true, 1, 'bogus')['ok'] === true);
    ok('absent row → 404',
       $v('delete', 'MP-1', true, null)['status'] === 404
       && $v('restore', 'MP-1', true, null)['status'] === 404
       && $v('purge', 'MP-1', true, null)['status'] === 404);
    ok('delete on an already-deleted row → 409',
       $v('delete', 'MP-1', true, 1)['status'] === 409);
    ok('restore on a live row → 409',
       $v('restore', 'MP-1', true, 0)['status'] === 409);
    ok('purge on a live row → 409 — purge is reachable ONLY from the deleted state',
       $v('purge', 'MP-1', true, 0)['status'] === 409,
       'two-step by construction: dropping this clause makes purge a one-click hard delete again');
    ok('the three happy paths are ok=true / 200',
       $v('delete', 'MP-1', true, 0)['ok'] === true
       && $v('delete', 'MP-1', true, 0, 'duplicate')['ok'] === true
       && $v('restore', 'MP-1', true, 1)['ok'] === true
       && $v('purge', 'MP-1', true, 1)['ok'] === true);
    $threw = false;
    try { songSoftDeleteVerdict('obliterate', 'MP-1', true, 0); } catch (\InvalidArgumentException $e) { $threw = true; }
    ok('an unknown op throws — a driver bug must be loud, not a guessed refusal', $threw);

    /* ------------------------------------------------------------------- *
     *  The three DB modes, each in its own process (memo isolation).
     * ------------------------------------------------------------------- */
    $allOk = ($fail === 0);
    foreach (SOFT_DELETE_MODES as $m) {
        echo "\n== mode: {$m} ==\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
        putenv(SOFT_DELETE_MODE_ENV . '=' . $m);
        exec($cmd . ' 2>&1', $lines, $code);
        putenv(SOFT_DELETE_MODE_ENV);
        echo implode("\n", $lines) . "\n";
        /* Exit code AND sentinel — a child that fatals prints no sentinel; one
           that exits 0 without running has none either (the gates-harness rule:
           both are failures, never silent skips). */
        $sentinel = (bool)preg_grep('/^MODE COMPLETE ' . preg_quote($m, '/') . ' \(\d+ assertion/', $lines);
        if ($code !== 0 || !$sentinel) {
            $allOk = false;
            fwrite(STDERR, "mode '{$m}' FAILED (exit {$code}" . ($sentinel ? '' : ', sentinel missing') . ")\n");
        }
        $lines = [];
    }
    if ($allOk && $fail === 0) {
        echo "\nAll song-soft-delete assertions passed.\n";
        exit(0);
    }
    fwrite(STDERR, "\none or more modes failed.\n");
    exit(1);
}

if (!in_array($mode, SOFT_DELETE_MODES, true)) {
    fwrite(STDERR, "Unknown mode '{$mode}'. Valid: " . implode(', ', SOFT_DELETE_MODES) . "\n");
    exit(1);
}

$checks = 0;
$countedOk = function (string $label, bool $cond, string $detail = '') use (&$checks): void {
    $checks++;
    ok($label, $cond, $detail);
};

/** The five columns the migration adds — the lockstep set the gate requires. */
function softDeleteColumnsFixture(): array
{
    return [
        'tblSongs.IsDeleted'     => true,
        'tblSongs.DeletedAt'     => true,
        'tblSongs.DeletedBy'     => true,
        'tblSongs.DeletedReason' => true,
        'tblSongs.DeleteNote'    => true,
    ];
}

/* --------------------------------------------------------------------- */
if ($mode === 'unmigrated') {
    echo "An install where the soft-delete card has NOT run (migrations are web-run)\n";

    $db = new GateRecorderMysqli();   // columns = [] — nothing soft-delete exists

    /* -- the read fragment degrades ------------------------------------- */
    $countedOk("songVisibleSql() mints '1=1' — un-migrated reads behave byte-identically to today",
       songVisibleSql($db) === '1=1',
       'got ' . var_export(songVisibleSql($db), true));
    $countedOk('…and it reached that answer by actually ASKING (the I_S probe ran)',
       $db->preparedMatching('/INFORMATION_SCHEMA\.COLUMNS/') !== [],
       'no probe was prepared — a hardcoded 1=1 would also never filter on MIGRATED installs, '
       . 'which the migrated mode catches from the other side');

    /* -- the tombstone probe stays quiet --------------------------------- */
    $countedOk('songSoftDeletedHolds() answers false — no soft-deleted song can exist here',
       songSoftDeletedHolds($db, 'MP-1008') === false);
    $countedOk('…and it never touched tblSongs (the gate short-circuits)',
       $db->preparedMatching('/FROM tblSongs\b/') === [],
       'statements prepared: ' . json_encode($db->prepared));

    /* -- ALL THREE write cores REFUSE, with 409, touching nothing --------- */
    foreach ([
        'songSoftDelete' => static fn(\mysqli $d) => songSoftDelete($d, 'MP-1008', 7, 'duplicate', 'note'),
        'songRestore'    => static fn(\mysqli $d) => songRestore($d, 'MP-1008', 7),
        'songPurge'      => static fn(\mysqli $d) => songPurge($d, 'MP-1008', 7),
    ] as $name => $call) {
        $verdict = $call($db);
        $countedOk("{$name}() refuses with HTTP 409 on an un-migrated install (status is the contract, rule #35)",
           $verdict['ok'] === false && $verdict['status'] === 409,
           'got ' . json_encode($verdict));
    }
    $countedOk('…no UPDATE or DELETE was EVER prepared — refusal, never a hard-delete fallback',
       $db->preparedMatching('/^(UPDATE|DELETE)\b/i') === [],
       'statements prepared: ' . json_encode($db->prepared)
       . ' — a fallback here quietly defeats the entire feature (the accountErase() stance)');
    $countedOk('…no transaction was ever opened',
       $db->tx === [],
       'tx events: ' . json_encode($db->tx));
    $countedOk('…and nothing touched tblSongRedirects',
       $db->preparedMatching('/tblSongRedirects/') === []);

    echo "MODE COMPLETE {$mode} ({$checks} assertions)\n";
}

/* --------------------------------------------------------------------- */
if ($mode === 'partial-apply') {
    echo "A PARTIAL apply — four of five columns landed, then the connection dropped\n";

    /* The migration adds the five columns as five separately-guarded ALTERs
       (so a partial state is genuinely reachable), and the registry probe
       keeps the card pending for exactly this state. The GATE must agree:
       "all five in lockstep" means 4/5 reads as UN-migrated — treating it as
       migrated would SELECT/UPDATE the column that never landed and throw
       under STRICT mid-page (#1228). */
    $db = new GateRecorderMysqli();
    $db->columns = array_slice(softDeleteColumnsFixture(), 0, 4, true);   // DeleteNote missing

    $countedOk("four of five columns still mints '1=1' — the lockstep contract",
       songVisibleSql($db) === '1=1',
       'got ' . var_export(songVisibleSql($db), true)
       . ' — a >=1 or table-existence gate would filter on a column set that '
       . 'cannot satisfy the write cores');
    $countedOk('…and the write cores refuse with 409, exactly as if nothing had landed',
       songSoftDelete($db, 'MP-1008', 7)['status'] === 409
       && songRestore($db, 'MP-1008', 7)['status'] === 409
       && songPurge($db, 'MP-1008', 7)['status'] === 409);
    $countedOk('…with no UPDATE/DELETE prepared and no transaction opened',
       $db->preparedMatching('/^(UPDATE|DELETE)\b/i') === [] && $db->tx === []);

    echo "MODE COMPLETE {$mode} ({$checks} assertions)\n";
}

/* --------------------------------------------------------------------- */
if ($mode === 'migrated') {
    echo "An install with all five columns — the module must actually WORK\n";

    $db = new GateRecorderMysqli();
    $db->columns = softDeleteColumnsFixture() + ['tblSongs.PublicId' => true];
    $db->songRows = [
        'MP-1008' => ['IsDeleted' => 0],   // a live song
        'CP-0412' => ['IsDeleted' => 1],   // a soft-deleted song
    ];
    $db->publicIdMap = ['ABCDEFGHJK' => 'CP-0412'];   // the deleted song's permalink id

    /* -- the read fragment filters ---------------------------------------- */
    $countedOk("songVisibleSql() mints 's.IsDeleted = 0' once migrated",
       songVisibleSql($db) === 's.IsDeleted = 0');
    $countedOk("…and honours the alias argument through the DB driver",
       songVisibleSql($db, '') === 'IsDeleted = 0' && songVisibleSql($db, 'x') === 'x.IsDeleted = 0');

    /* -- ready memoises: a later call with a BROKEN connection still answers - */
    $countedOk('songSoftDeleteReady() memoised the success — a throwing connection is never re-probed',
       songSoftDeleteReady(new ClaimProbeFailingMysqli()) === true,
       'the memo is per-request by design (the answer cannot change mid-request); '
       . 'this also proves the first probe was what settled it');

    /* -- the tombstone probe, all four shapes ----------------------------- */
    $countedOk('a soft-deleted SongId HOLDS', songSoftDeletedHolds($db, 'CP-0412') === true);
    $countedOk('a live SongId does not', songSoftDeletedHolds($db, 'MP-1008') === false);
    $countedOk('an absent id does not', songSoftDeletedHolds($db, 'ZZ-9999') === false);
    $countedOk('the deleted song\'s PublicId HOLDS too — share links reach the same answer (#1343-B)',
       songSoftDeletedHolds($db, 'ABCDEFGHJK') === true,
       'a permalink carrying the opaque id must not fall past the suppression '
       . 'into the number heuristic');
    $countedOk('…and the PublicId answer came via the shared resolver (its SELECT ran)',
       $db->preparedMatching('/WHERE PublicId = \?/') !== [],
       'no PublicId lookup was prepared — the "true" above was reached some other way');

    /* -- songSoftDelete(): the happy path --------------------------------- */
    $txBefore = count($db->tx);
    $verdict  = songSoftDelete($db, 'MP-1008', 7, 'duplicate', 'merged into CP-0412');
    $upd      = $db->executedMatching('/^UPDATE tblSongs SET IsDeleted = 1/i');
    $countedOk('a live song soft-deletes: ok=true / 200',
       $verdict['ok'] === true && $verdict['status'] === 200,
       'got ' . json_encode($verdict));
    $countedOk('…via exactly ONE UPDATE … SET IsDeleted = 1',
       count($upd) === 1,
       'executed: ' . json_encode($db->executed));
    $countedOk('…binding (userId, reason, note, songId) in statement order',
       $upd !== [] && $upd[0]['params'] === [7, 'duplicate', 'merged into CP-0412', 'MP-1008'],
       'params were ' . json_encode($upd[0]['params'] ?? null));
    $countedOk('…inside a transaction that COMMITTED',
       array_slice($db->tx, $txBefore) === ['begin', 'commit'],
       'tx events this call: ' . json_encode(array_slice($db->tx, $txBefore)));
    $countedOk('…and the row was read FOR UPDATE first (the check-then-act race is locked out)',
       $db->preparedMatching('/FOR UPDATE$/i') !== []);

    /* -- the load-bearing absences, checked over the WHOLE mode at the end - */

    /* -- a NULL reason and an overlong note are both stored sanely --------- */
    $longNote = str_repeat('x', 300);
    $verdict  = songSoftDelete($db, 'MP-1008', null, null, $longNote);
    $updAll   = $db->executedMatching('/^UPDATE tblSongs SET IsDeleted = 1/i');
    $last     = $updAll !== [] ? $updAll[count($updAll) - 1] : null;
    $countedOk('a reasonless, anonymous delete still succeeds (both columns are nullable)',
       $verdict['ok'] === true && $last !== null
       && $last['params'][0] === null && $last['params'][1] === null,
       'params were ' . json_encode($last['params'] ?? null));
    $countedOk('…and the note is clamped to the column\'s 255 (mb code points, rule #21 discipline)',
       $last !== null && mb_strlen((string)$last['params'][2]) === 255,
       'note length stored: ' . ($last !== null ? mb_strlen((string)$last['params'][2]) : 'n/a'));

    /* -- refusals in the wrong state --------------------------------------- */
    $txBefore = count($db->tx);
    $verdict  = songSoftDelete($db, 'CP-0412', 7, null, '');
    $countedOk('deleting an ALREADY-deleted song refuses with 409',
       $verdict['ok'] === false && $verdict['status'] === 409,
       'got ' . json_encode($verdict));
    $countedOk('…and the transaction ROLLED BACK, writing nothing',
       array_slice($db->tx, $txBefore) === ['begin', 'rollback'],
       'tx events this call: ' . json_encode(array_slice($db->tx, $txBefore)));
    $countedOk('deleting an ABSENT song refuses with 404',
       songSoftDelete($db, 'ZZ-9999', 7)['status'] === 404);
    $txBefore  = count($db->tx);
    $prepBefore = count($db->prepared);
    $verdict = songSoftDelete($db, 'MP-1008', 7, 'not-a-reason');
    $countedOk('an unknown reason refuses with 422 — the map IS the allow-list',
       $verdict['ok'] === false && $verdict['status'] === 422,
       'got ' . json_encode($verdict));
    $countedOk('…decided BEFORE any transaction or row read (a vocabulary bug costs no DB work)',
       count($db->tx) === $txBefore && count($db->prepared) === $prepBefore,
       'tx: ' . json_encode(array_slice($db->tx, $txBefore))
       . ' prepared: ' . json_encode(array_slice($db->prepared, $prepBefore)));

    /* -- songRestore(): the mirror-image UPDATE ----------------------------- */
    $txBefore = count($db->tx);
    $verdict  = songRestore($db, 'CP-0412', 7);
    $rst      = $db->executedMatching('/^UPDATE tblSongs SET IsDeleted = 0/i');
    $countedOk('a deleted song restores: ok=true / 200, one UPDATE clearing all five columns',
       $verdict['ok'] === true && count($rst) === 1
       && stripos($rst[0]['sql'], 'DeletedAt = NULL') !== false
       && stripos($rst[0]['sql'], 'DeletedBy = NULL') !== false
       && stripos($rst[0]['sql'], 'DeletedReason = NULL') !== false
       && stripos($rst[0]['sql'], "DeleteNote = ''") !== false,
       'executed: ' . json_encode($rst));
    $countedOk('…bound to the one song id',
       $rst !== [] && $rst[0]['params'] === ['CP-0412']);
    $countedOk('…inside a committed transaction',
       array_slice($db->tx, $txBefore) === ['begin', 'commit']);
    $countedOk('restoring a LIVE song refuses with 409',
       songRestore($db, 'MP-1008', 7)['status'] === 409);
    $countedOk('restoring an ABSENT song refuses with 404',
       songRestore($db, 'ZZ-9999', 7)['status'] === 404);

    /* -- songPurge(): gates enforced, body deliberately absent -------------- */
    $countedOk('purging a LIVE song refuses with 409 — purge only from the deleted state',
       songPurge($db, 'MP-1008', 7)['status'] === 409);
    $countedOk('purging an ABSENT song refuses with 404',
       songPurge($db, 'ZZ-9999', 7)['status'] === 404);
    $caught = null;
    try {
        songPurge($db, 'CP-0412', 7);
    } catch (\Throwable $e) {
        $caught = $e;
    }
    $countedOk('purge gates PASSING throws LogicException — the body lands in commit 4, and a fake "purged" would be worse than none',
       $caught instanceof \LogicException,
       'caught ' . ($caught === null ? 'nothing — songPurge() claimed success it cannot deliver yet'
           : get_class($caught)));

    /* -- a failing UPDATE propagates and rolls back -------------------------- */
    $db->failExecutePattern = '/^UPDATE tblSongs SET IsDeleted = 1/i';
    $txBefore = count($db->tx);
    $caught = null;
    try {
        songSoftDelete($db, 'MP-1008', 7, 'duplicate');
    } catch (\Throwable $e) {
        $caught = $e;
    }
    $countedOk('a failing soft-delete UPDATE propagates out (fatal, never swallowed) after a rollback',
       $caught instanceof \mysqli_sql_exception
       && array_slice($db->tx, $txBefore) === ['begin', 'rollback'],
       'caught ' . ($caught === null ? 'nothing' : get_class($caught))
       . '; tx: ' . json_encode(array_slice($db->tx, $txBefore)));
    $db->failExecutePattern = null;

    /* -- THE LOAD-BEARING ABSENCES, over every statement this whole mode ----- */
    $countedOk('NO DELETE statement was prepared by ANYTHING in this mode',
       $db->preparedMatching('/^\s*DELETE\b/i') === [],
       'prepared: ' . json_encode($db->preparedMatching('/^\s*DELETE\b/i'))
       . ' — a DELETE here is the CASCADE disaster the whole feature exists to prevent');
    $countedOk('NO statement touching tblSongRedirects was prepared by ANYTHING in this mode',
       $db->preparedMatching('/tblSongRedirects/i') === [],
       'a redirect write cannot be un-written on restore (#1694 plan §4) — '
       . 'redirects are purge\'s business, in commit 4');

    echo "MODE COMPLETE {$mode} ({$checks} assertions)\n";
}

/* --------------------------------------------------------------------- */
if ($mode === 'probe-failure') {
    echo "The INFORMATION_SCHEMA probe itself breaks — who degrades, who refuses\n";

    /* -- the READER fails OPEN (the songRedirectClaimsId() contract) ------- */
    $threw = false;
    $answer = null;
    try {
        $answer = songSoftDeletedHolds(new ClaimProbeFailingMysqli(), 'MP-1008');
    } catch (\Throwable $e) {
        $threw = true;
    }
    $countedOk('songSoftDeletedHolds() does NOT throw when the probe fails',
       !$threw,
       'a broken probe would propagate out of getSongById() and white-screen the '
       . 'hottest read path in the app — the #1228 STRICT-mode class');
    $countedOk('…and answers "not deleted", so the read degrades to today\'s behaviour',
       $answer === false,
       'answering true on a failed probe would 410 every song on a struggling install');

    /* -- the WRITER does the opposite: it must NOT proceed on uncertainty --- */
    $caught = null;
    try {
        songSoftDelete(new ClaimProbeFailingMysqli(), 'MP-1008', 7, 'duplicate');
    } catch (\Throwable $e) {
        $caught = $e;
    }
    $countedOk('songSoftDelete() propagates a probe failure — "I could not check" must never become a write',
       $caught instanceof \mysqli_sql_exception,
       'it answered ' . ($caught === null ? 'a verdict instead of throwing' : get_class($caught))
       . ' — a swallowed probe failure would have to GUESS between refusing wrongly and writing blind');

    /* -- memoise SUCCESS ONLY ----------------------------------------------- */
    $threwDirect = false;
    try {
        songSoftDeleteReady(new ClaimProbeFailingMysqli());
    } catch (\Throwable $e) {
        $threwDirect = true;
    }
    $countedOk('songSoftDeleteReady() throws on a failed probe rather than answering (the userStatusColumnReady() shape)',
       $threwDirect);
    $good = new GateRecorderMysqli();
    $good->columns = softDeleteColumnsFixture();
    $countedOk('…and the failure was NOT memoised — a later good probe answers true',
       songSoftDeleteReady($good) === true,
       'a memoised failure would pin the gate false for the whole request, silently '
       . 'un-filtering every read after one transient hiccup');

    echo "MODE COMPLETE {$mode} ({$checks} assertions)\n";
}

if ($fail === 0) {
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
