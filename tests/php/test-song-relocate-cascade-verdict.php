<?php

declare(strict_types=1);

/**
 * iHymns — the songbook move's cascade verdict, CALLED not read (#1690)
 * =====================================================================
 *
 * ELI5
 * ----
 * Before a song can change songbooks its id has to be renamed, and that only
 * works if every table pointing at the old id was told to follow. This file
 * hands the deciding functions a MADE-UP database — "this link is broken, that
 * one is missing, the song has rows here but not there" — and checks the
 * answers. No MySQL involved; the functions are pure.
 *
 * WHY IT IS BEHAVIOURAL AND NOT A SOURCE SCAN
 * -------------------------------------------
 * Four consecutive rounds on this branch shipped a guard that was GREEN while
 * the thing it guarded was broken, and every one had the same cause: source
 * inspection used as evidence for a property that has a runtime handle.
 * `tests/php/test-transaction-fatal.php` is the worked example — its two
 * predecessors asserted that the predicate's body MENTIONED `1213`, and a
 * reviewer replaced the body with `return false;` while leaving the number in a
 * now-dead array. Everything stayed green while every call site resumed
 * swallowing deadlocks.
 *
 * So the #1690 work was deliberately shaped to leave a runtime handle:
 * `songRelocateCascadeGaps()` and `songRelocateCascadeVerdict()` are PURE (the
 * verdict takes its `$childHasRows` probe as an injected callable), and this
 * file CALLS them. A function cannot pass here by containing the right words,
 * only by answering correctly.
 *
 * WHAT #1690 CHANGED, WHICH IS WHAT THE TWO CENTRAL CASES PIN
 * -----------------------------------------------------------
 * `songRelocateAssertCascades()` used to memoise ONE schema-wide verdict: any
 * non-CASCADE foreign key anywhere refused EVERY move. That is far broader than
 * the harm — a RESTRICT `fk_media_song` cannot break the re-key of a song with
 * no media row — so one un-run migration froze the whole feature. The owner
 * chose option A: refuse only when THIS song actually has rows in the affected
 * child table. Case 2 below is that decision, pinned by execution.
 *
 * The check was also structurally blind to the opposite drift. A column with no
 * FK at all produces no `INFORMATION_SCHEMA` row, so "everything I found
 * cascades" was vacuously true on an install where `migrate-song-softref-fks.php`
 * never ran — and the re-key then stranded every `tblSongRevisions` row for that
 * song with no error anywhere. Case 4 is that case, and it is the one that
 * motivated the issue.
 *
 * WHAT THIS FILE CANNOT CATCH, so its tick is not over-read
 * ---------------------------------------------------------
 *  - Whether `songRelocateFkCatalogue()`'s two INFORMATION_SCHEMA queries return
 *    what this file's synthetic rows pretend they do. There is no MySQL in CI.
 *  - Whether the real `songRelocateChildRowProbe()` closure's SQL is correct —
 *    only that a probe answering "no rows" lets the move through and one
 *    answering "rows" does not.
 *  - Whether a drifted install's card really flips to pending. That needs a
 *    scratch database (see the PR's "not verified here" list).
 *
 * MUTATION RECORD (rule #34 — a guard whose first green run was never
 * challenged has, in this repo, repeatedly been silently wrong)
 * ------------------------------------------------------------------
 * Fourteen mutants were applied to the REAL tree (song_relocate.php,
 * migration-registry.php, schema.sql), each restored from a copy taken
 * beforehand — never `git checkout --`, which an agent on this branch used to
 * destroy two files of uncommitted work — with `git status --porcelain` and file
 * checksums confirmed afterwards. Each is recorded with the assertions it turned
 * red, because "it went red" without saying WHICH is how an over-broad guard
 * gets mistaken for a precise one.
 *
 *  M1  delete the `tblSongRevisions` entry from `SONG_RELOCATE_EXPECTED_SONGID_FKS`
 *      → RED ×6: schema parity, case 4 (all three), the slug partition, and the
 *      migration-script subset check.
 *  M2  `songRelocateCascadeVerdict()` returns `null` unconditionally → RED ×13,
 *      including both fail-closed assertions and both re-throw assertions.
 *  M3  block on EVERY gap without consulting the probe (the pre-#1690 behaviour)
 *      → RED ×5, led by case 2 — which IS option A.
 *  M4  drop the missing-FK half of `songRelocateCascadeGaps()` → RED ×3, all of
 *      them case 4; cases 2/3 stayed green, so the two halves are pinned apart.
 *  M5  match expectations by CONSTRAINT NAME instead of (table, column) → RED ×1,
 *      exactly the renamed-constraint assertion.
 *  M6  drop `k.COLUMN_NAME` from the catalogue query → RED ×1 in
 *      test-song-relocate-hardening.php.
 *  M7  point the second catalogue read at a table other than INFORMATION_SCHEMA.COLUMNS
 *      → RED ×1 there.
 *  M8  re-inline the verdict in `songRelocateAssertCascades()` as a hardcoded
 *      string that STILL names the migration → RED ×2 on the delegation
 *      assertions. Worth recording: the "names the migration" assertion stayed
 *      green, which is exactly why the delegation checks exist beside it.
 *  M9  revert the softref probe to the single "representative" `fk_Revisions_Song`
 *      → RED ×2.
 *  M10 delete clause (b) of the prefix-fixup probe — the whole §2.2 fix — leaving
 *      `songRelocateFksFixedBy(...)` assigning a list nothing consumed.
 *      **GREEN on the first attempt.** This file's guard was insufficient in its
 *      first version: sourcing a list is not USING it, which is the "a gate whose
 *      answer is DISCARDED is decoration" defect the hardening guard already
 *      learned once (#1679 A12), reproduced here in a brand-new file. Section 11
 *      gained the helper-call assertions, and M10 is now RED.
 *  M11 typo the helper name in a probe → RED, and the message shows WHY the call
 *      was hoisted out of the `try`: `catch (\Throwable)` would have swallowed
 *      the `Error` and answered "already applied" forever.
 *  M12 delete clause (b) AND rename its now-unused helper → RED.
 *  M13 delete the call and leave the helper named ONLY IN A COMMENT → RED (the
 *      tokenizer strips comments; prose cannot satisfy the check).
 *  M14 add a new FK to `tblSongWriters` in schema.sql → RED on schema parity,
 *      naming the unlisted constraint — proof the parse reads the real file
 *      rather than trivially agreeing with itself.
 *  M16 make `songRelocateFkCatalogue()` re-throw instead of returning the error
 *      shape → RED (uncaught fatal). An unverifiable catalogue must become a
 *      refusal, not a white screen.
 *  M17 delete the catalogue's `static` memo → **GREEN here**, and the assertion
 *      that should have caught it was removed rather than left in place. It
 *      called the catalogue twice and compared the results, which cannot fail:
 *      two failed probes return EQUAL arrays and PHP's `===` on arrays compares
 *      values, not identity. Counting queries needs a live connection, so the
 *      property moved to `test-song-relocate-hardening.php` as an honest source
 *      assertion — where M17 is now RED. Recorded because "I wrote an assertion
 *      for it" and "the assertion can fail" are different claims, and this repo's
 *      history is mostly the gap between them.
 *  M15 remove the `catch` from the softref probe → RED ("mysqli object is not
 *      fully initialized"), i.e. an unreachable database would white-screen
 *      /manage/setup-database instead of showing the card as not-pending. This
 *      mutant is also what exposed a weakness in section 11's own setup: with
 *      `_migProbe_columnExists()` stubbed to FALSE the probe short-circuited on
 *      its existence gate and never touched the handle, so the assertion passed
 *      without exercising anything. The stub answers TRUE for that reason.
 * CORRECT-BUT-DIFFERENT (must stay GREEN — rule #34's other failure mode, which
 * ends in a guard being weakened or deleted rather than fixed):
 *  N1  reword every refusal sentence except the migration filename → green.
 *  N2  reorder `SONG_RELOCATE_EXPECTED_SONGID_FKS` → green (compared as a set).
 *  N3  return the gap list in the opposite order → green.
 *  N4  rewrite clause (b) of the prefix-fixup probe as an `array_filter` over the
 *      same targets → green.
 *
 * ONE DEFECT WAS FOUND BY THIS FILE ON ITS FIRST RUN, in the code rather than in
 * a mutant: `songRelocateIdentifierSafe()` used `/^…$/`, and PCRE's `$` also
 * matches immediately before a trailing newline, so `"tblSongs\n"` validated. It
 * is `\A…\z` now. The consequence would have been a broken query rather than an
 * injection, but a validator that is not exact does not have the property it
 * claims.
 *
 *   php tests/php/test-song-relocate-cascade-verdict.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/song_relocate.php
 * @see appWeb/.sql/schema.sql
 * @see https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/appWeb/public_html/includes/song_relocate.php';

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

/* ------------------------------------------------------------ fixtures ----- */

/** One INFORMATION_SCHEMA row, shaped exactly as `songRelocateFkCatalogue()` returns it. */
function fkRow(string $table, string $column, string $constraint, string $rule = 'CASCADE'): array
{
    return [
        'CONSTRAINT_NAME' => $constraint,
        'UPDATE_RULE'     => $rule,
        'TABLE_NAME'      => $table,
        'COLUMN_NAME'     => $column,
    ];
}

/** A live catalogue in which every expected FK is present and cascading. */
function healthyFks(): array
{
    $rows = [];
    foreach (SONG_RELOCATE_EXPECTED_SONGID_FKS as [$t, $c, $k, $_f]) {
        $rows[] = fkRow($t, $c, $k, 'CASCADE');
    }
    return $rows;
}

/** Every expected (table, column) pair present in the live schema. */
function healthyColumns(): array
{
    $cols = [];
    foreach (SONG_RELOCATE_EXPECTED_SONGID_FKS as [$t, $c, $_k, $_f]) {
        $cols[songRelocateFkKey($t, $c)] = true;
    }
    return $cols;
}

/** Drop every live FK row whose constraint is $constraint. */
function withoutFk(array $rows, string $constraint): array
{
    return array_values(array_filter(
        $rows,
        static fn(array $r): bool => $r['CONSTRAINT_NAME'] !== $constraint
    ));
}

/** Rewrite one live FK row's UPDATE_RULE. */
function withRule(array $rows, string $constraint, string $rule): array
{
    foreach ($rows as $i => $r) {
        if ($r['CONSTRAINT_NAME'] === $constraint) { $rows[$i]['UPDATE_RULE'] = $rule; }
    }
    return $rows;
}

/**
 * A `$childHasRows` probe that RECORDS what it was asked.
 *
 * The recording half is not decoration: "the move proceeded" and "the move
 * proceeded because nothing was checked" produce the same `null`, and only the
 * second is a bug. Case 2 asserts the probe was consulted for the right pair.
 */
function recordingProbe(bool $answer, array &$calls): callable
{
    return static function (string $table, string $column) use ($answer, &$calls): bool {
        $calls[] = $table . '.' . $column;
        return $answer;
    };
}

/** A probe that throws — for the two fail-closed assertions. */
function throwingProbe(\Throwable $e): callable
{
    return static function (string $_t, string $_c) use ($e): bool { throw $e; };
}

/**
 * Every FK `schema.sql` declares against `tblSongs(SongId)` — DERIVED FROM THE
 * TREE, never typed here (rule #34).
 *
 * Read with `file_get_contents()` on an explicit path, deliberately: `rg`
 * silently skips dot-directories, so `appWeb/.sql/` is invisible to it and any
 * "I grepped and found nothing" reasoning about this file is worthless.
 *
 * SQL comments are stripped first so a constraint MENTIONED in prose cannot be
 * counted — the same prose-satisfies-the-guard trap this repo keeps re-shipping.
 * The owning table is the nearest preceding `CREATE TABLE` / `ALTER TABLE`
 * rather than a `\n) ENGINE`-terminated block, so a constraint added by a
 * standalone `ALTER` later in the file would still be attributed correctly.
 *
 * @return list<array{0:string,1:string,2:string}> [table, column, constraint]
 */
function schemaSongIdFks(string $schemaPath): array
{
    $raw = (string)file_get_contents($schemaPath);
    $sql = (string)preg_replace('#/\*[\s\S]*?\*/#', ' ', $raw);
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);

    $hits = [];
    if (!preg_match_all(
        '/CONSTRAINT\s+`?(\w+)`?\s+FOREIGN\s+KEY\s*\(\s*`?(\w+)`?\s*\)\s*'
        . 'REFERENCES\s+`?tblSongs`?\s*\(\s*`?SongId`?\s*\)/i',
        $sql,
        $hits,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        return [];
    }

    $out = [];
    foreach ($hits as $hit) {
        $head = substr($sql, 0, $hit[0][1]);
        if (!preg_match_all(
            '/(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE)\s+`?(\w+)`?/i',
            $head,
            $owners
        )) {
            continue;
        }
        $out[] = [(string)end($owners[1]), $hit[2][0], $hit[1][0]];
    }
    return $out;
}

/** [table, column, constraint] → a comparable string. */
function fkTriple(array $t): string { return $t[0] . '|' . $t[1] . '|' . $t[2]; }

/* ======================================================================== *
 * 1 — a healthy install: no gaps, no probing, no refusal
 * ======================================================================== */

echo "1 — every expected FK live and cascading\n";

$calls = [];
$gaps  = songRelocateCascadeGaps(healthyFks(), healthyColumns(), SONG_RELOCATE_EXPECTED_SONGID_FKS);
ok('a fully-migrated install produces NO gaps', $gaps === [],
   count($gaps) . ' gap(s): ' . json_encode(array_slice($gaps, 0, 4)));
ok('…so the verdict is null (the move proceeds)',
   songRelocateCascadeVerdict($gaps, recordingProbe(true, $calls)) === null);
ok('…and the per-song probe is never invoked (zero extra queries on a healthy install)',
   $calls === [], 'probe was asked about: ' . implode(', ', $calls));

/* ======================================================================== *
 * 2 — THE OWNER'S OPTION A: a broken FK the song has nothing behind
 * ======================================================================== */

echo "\n2 — a RESTRICT FK but no child rows for THIS song → the move proceeds\n";

$restrictFks = withRule(healthyFks(), 'fk_media_song', 'RESTRICT');
$gaps        = songRelocateCascadeGaps($restrictFks, healthyColumns(), SONG_RELOCATE_EXPECTED_SONGID_FKS);
ok('a non-CASCADE live FK is a gap', count($gaps) === 1 && $gaps[0]['kind'] === 'no-cascade',
   json_encode($gaps));
ok('…and the gap carries the identifiers from the CONST, not the wire',
   count($gaps) === 1 && $gaps[0]['table'] === 'tblSongMedia' && $gaps[0]['column'] === 'SongId'
   && $gaps[0]['known'] === true,
   json_encode($gaps));

$calls   = [];
$verdict = songRelocateCascadeVerdict($gaps, recordingProbe(false, $calls));
ok('the song has NO rows there, so the verdict is null — #1690 option A',
   $verdict === null,
   'this is the whole change: before #1690 one stale constraint refused EVERY '
   . "move on the install. Verdict was:\n" . (string)$verdict);
ok('…and the probe WAS actually consulted, about the right (table, column)',
   $calls === ['tblSongMedia.SongId'],
   'probe was asked about: ' . json_encode($calls) . '. A null verdict reached WITHOUT '
   . 'asking is not option A, it is a check that stopped running');

/* ======================================================================== *
 * 3 — the same gap, with rows behind it
 * ======================================================================== */

echo "\n3 — a RESTRICT FK WITH child rows → refused, naming the fixup migration\n";

$calls   = [];
$verdict = songRelocateCascadeVerdict($gaps, recordingProbe(true, $calls));
ok('the move is refused', is_string($verdict) && $verdict !== '');
ok('…the refusal names the offending table and constraint',
   is_string($verdict) && strpos($verdict, 'tblSongMedia') !== false
   && strpos($verdict, 'fk_media_song') !== false,
   (string)$verdict);
ok('…and it names the migration SCRIPT to run (the message IS the deliverable)',
   is_string($verdict) && strpos($verdict, 'migrate-songid-prefix-fixup.php') !== false,
   'a refusal that does not say what to run is no more actionable than the raw '
   . "ER_ROW_IS_REFERENCED_2 it replaces:\n" . (string)$verdict);
ok('…and the migration CARD title, which is what the operator actually clicks',
   is_string($verdict)
   && strpos($verdict, SONG_RELOCATE_FIX_MIGRATIONS['songid-prefix-fixup']['card']) !== false,
   (string)$verdict);

/* SET NULL is the quieter half of the same hazard: the statement SUCCEEDS and
   orphans the children, so it must be a gap too. */
$setNull = songRelocateCascadeGaps(
    withRule(healthyFks(), 'fk_media_song', 'SET NULL'),
    healthyColumns(),
    SONG_RELOCATE_EXPECTED_SONGID_FKS
);
$calls = [];
ok('ON UPDATE SET NULL is a gap as well (it succeeds and orphans, which is worse)',
   count($setNull) === 1
   && is_string(songRelocateCascadeVerdict($setNull, recordingProbe(true, $calls))),
   json_encode($setNull));

/* ======================================================================== *
 * 4 — THE CASE THAT MOTIVATED #1690: an FK that is not there at all
 * ======================================================================== */

echo "\n4 — an EXPECTED FK absent while its table+column exist → refused\n";

$missing = withoutFk(healthyFks(), 'fk_Revisions_Song');
$gaps    = songRelocateCascadeGaps($missing, healthyColumns(), SONG_RELOCATE_EXPECTED_SONGID_FKS);
ok('a missing expected FK is detected (the old check was structurally blind to this)',
   count($gaps) === 1 && $gaps[0]['kind'] === 'missing-fk'
   && $gaps[0]['table'] === 'tblSongRevisions' && $gaps[0]['column'] === 'SongId',
   'a column with no FK produces NO INFORMATION_SCHEMA row, so "everything I found '
   . 'cascades" was vacuously true and the re-key stranded every revision silently. '
   . 'Gaps: ' . json_encode($gaps));

$calls   = [];
$verdict = songRelocateCascadeVerdict($gaps, recordingProbe(true, $calls));
ok('…the move is refused when the song HAS revisions',
   is_string($verdict) && strpos($verdict, 'tblSongRevisions') !== false,
   (string)$verdict);
ok('…and the refusal names migrate-song-softref-fks.php, not the other card',
   is_string($verdict) && strpos($verdict, 'migrate-song-softref-fks.php') !== false
   && strpos($verdict, 'migrate-songid-prefix-fixup.php') === false,
   'pointing at the wrong migration is worse than pointing at none — the operator '
   . "runs it, nothing changes, and the refusal repeats:\n" . (string)$verdict);
ok('…and a song with NO revisions still moves (option A applies to missing FKs too)',
   songRelocateCascadeVerdict($gaps, recordingProbe(false, $calls)) === null);

/* ======================================================================== *
 * 5 — an un-migrated install is not "drifted": no table, no gap
 * ======================================================================== */

echo "\n5 — an expected FK whose table or column does not exist here is NOT a gap\n";

$columnsNoRevisions = healthyColumns();
unset($columnsNoRevisions[songRelocateFkKey('tblSongRevisions', 'SongId')]);
ok('column absent → no gap (a table that is not there holds no rows to strand)',
   songRelocateCascadeGaps($missing, $columnsNoRevisions, SONG_RELOCATE_EXPECTED_SONGID_FKS) === []);

ok('the whole expected set absent → no gaps at all (a minimal install must still move songs)',
   songRelocateCascadeGaps([], [], SONG_RELOCATE_EXPECTED_SONGID_FKS) === []);

/* ======================================================================== *
 * 6 — a live FK nobody listed still breaks the same UPDATE
 * ======================================================================== */

echo "\n6 — an UNKNOWN live non-CASCADE FK is reported too\n";

$withStranger = healthyFks();
$withStranger[] = fkRow('tblSomeFutureThing', 'SongId', 'fk_future_song', 'RESTRICT');
$gaps = songRelocateCascadeGaps($withStranger, healthyColumns(), SONG_RELOCATE_EXPECTED_SONGID_FKS);
ok('an unlisted RESTRICT FK is a gap, flagged as not-known',
   count($gaps) === 1 && $gaps[0]['constraint'] === 'fk_future_song'
   && $gaps[0]['known'] === false && $gaps[0]['fix'] === null,
   'a constraint nobody wrote down still throws ER_ROW_IS_REFERENCED_2. Gaps: '
   . json_encode($gaps));

$calls   = [];
$verdict = songRelocateCascadeVerdict($gaps, recordingProbe(true, $calls));
ok('…and the refusal names it, plus the generic drift remedy',
   is_string($verdict) && strpos($verdict, 'fk_future_song') !== false
   && strpos($verdict, 'schema.sql') !== false,
   (string)$verdict);

/* A constraint may legitimately be dropped and re-added under another name —
   MySQL auto-names one `<tbl>_ibfk_1` if you do not. What the re-key depends on
   is that the COLUMN cascades, whatever the constraint is called, so matching
   must be on (table, column). Name-matching would report this as BOTH a missing
   expected FK and an unknown live one. */
$renamed = withoutFk(healthyFks(), 'fk_Revisions_Song');
$renamed[] = fkRow('tblSongRevisions', 'SongId', 'tblSongRevisions_ibfk_1', 'CASCADE');
ok('a correctly-cascading FK under a DIFFERENT name is not a gap',
   songRelocateCascadeGaps($renamed, healthyColumns(), SONG_RELOCATE_EXPECTED_SONGID_FKS) === [],
   json_encode(songRelocateCascadeGaps($renamed, healthyColumns(), SONG_RELOCATE_EXPECTED_SONGID_FKS)));

/* MySQL folds identifier case depending on the server and filesystem. A live row
   spelled differently from the const must still MATCH, or a case-folding server
   reports all 41 expectations missing and refuses every move. */
$lowered = [];
foreach (healthyFks() as $r) {
    $r['TABLE_NAME']  = strtolower((string)$r['TABLE_NAME']);
    $r['COLUMN_NAME'] = strtolower((string)$r['COLUMN_NAME']);
    $lowered[] = $r;
}
ok('a case-folding server (lower_case_table_names) produces no spurious gaps',
   songRelocateCascadeGaps($lowered, healthyColumns(), SONG_RELOCATE_EXPECTED_SONGID_FKS) === [],
   json_encode(array_slice(
       songRelocateCascadeGaps($lowered, healthyColumns(), SONG_RELOCATE_EXPECTED_SONGID_FKS), 0, 3)));

/* ======================================================================== *
 * 7 — fail CLOSED, except for the one error that must propagate
 * ======================================================================== */

echo "\n7 — an unanswerable probe refuses; a transaction-fatal one is re-thrown\n";

$gaps = songRelocateCascadeGaps(
    withRule(healthyFks(), 'fk_media_song', 'RESTRICT'),
    healthyColumns(),
    SONG_RELOCATE_EXPECTED_SONGID_FKS
);

ok('a probe that THROWS counts as "the song has rows" — the move is refused',
   is_string(songRelocateCascadeVerdict($gaps, throwingProbe(new \RuntimeException('probe exploded')))),
   'an unverifiable move must not proceed; refusing is recoverable, an orphaned '
   . 'graph is not');
ok('…including a missing-table error, which must not read as "no rows here"',
   is_string(songRelocateCascadeVerdict(
       $gaps,
       throwingProbe(new \mysqli_sql_exception('no such table', 1146))
   )));

$rethrown = false;
try {
    songRelocateCascadeVerdict($gaps, throwingProbe(new \mysqli_sql_exception('deadlock', 1213)));
} catch (\mysqli_sql_exception $e) {
    $rethrown = (int)$e->getCode() === 1213;
}
ok('a transaction-fatal 1213 is RE-THROWN, never converted into a refusal',
   $rethrown,
   'by this point InnoDB may have rolled the CALLER\'s transaction back. Turning that '
   . 'into a refusal would have the caller roll back nothing and carry on — the exact '
   . 'false success #1679 F8/A1 removed');

$rethrown = false;
try {
    songRelocateCascadeVerdict(
        $gaps,
        throwingProbe(new \RuntimeException('probe wrapper', 0, new \mysqli_sql_exception('deadlock', 1213)))
    );
} catch (\Throwable $e) {
    $rethrown = true;
}
ok('…and so is a 1213 WRAPPED in a RuntimeException (the songRedirectsTableReady shape)',
   $rethrown);

/* ======================================================================== *
 * 8 — the identifier gate on the real probe's interpolation
 * ======================================================================== */

echo "\n8 — identifiers that reach a backtick are charset-validated (rule #5)\n";

foreach (['tblSongMedia', 'SongId', 'tbl_x$1', 'A'] as $good) {
    ok("'$good' is accepted as an identifier", songRelocateIdentifierSafe($good) === true);
}
foreach ([
    ''                    => 'empty',
    'tbl Songs'           => 'a space',
    'tbl`Songs'           => 'a backtick — the one character that could escape the quoting',
    'tblSongs;DROP'       => 'a statement terminator',
    'tblSongs--'          => 'a comment opener',
    "tblSongs\n"          => 'a newline',
    'tblSöngs'            => 'a non-ASCII letter (legal in MySQL, but not through this path)',
] as $bad => $why) {
    ok('rejected: ' . $why, songRelocateIdentifierSafe((string)$bad) === false);
}

/* ======================================================================== *
 * 9 — the const IS schema.sql (tree-derived, never typed)
 * ======================================================================== */

echo "\n9 — SONG_RELOCATE_EXPECTED_SONGID_FKS matches schema.sql exactly\n";

$parsed = schemaSongIdFks($ROOT . '/appWeb/.sql/schema.sql');
ok('schema.sql parsed and yielded a plausible number of FKs to tblSongs(SongId)',
   count($parsed) >= 30,
   count($parsed) . ' found. A broken parse returning [] would make the comparison '
   . 'below meaningless, so it is asserted separately');

$fromSchema = array_map('fkTriple', $parsed);
$fromConst  = array_map(
    static fn(array $e): string => fkTriple([$e[0], $e[1], $e[2]]),
    SONG_RELOCATE_EXPECTED_SONGID_FKS
);
sort($fromSchema);
sort($fromConst);

$onlySchema = array_values(array_diff($fromSchema, $fromConst));
$onlyConst  = array_values(array_diff($fromConst, $fromSchema));
ok('every FK schema.sql declares is in the const',
   $onlySchema === [],
   "missing from SONG_RELOCATE_EXPECTED_SONGID_FKS:\n  " . implode("\n  ", $onlySchema)
   . "\nA new FK to tblSongs(SongId) that is not listed is a column the move will "
   . 'strand silently on an install that has not created it yet');
ok('and the const invents nothing schema.sql does not declare',
   $onlyConst === [],
   "in the const but not in schema.sql:\n  " . implode("\n  ", $onlyConst)
   . "\nAn expectation with no schema declaration refuses moves for a constraint "
   . 'no install will ever have');

/* ======================================================================== *
 * 10 — the const agrees with the migration registry and the migration scripts
 * ======================================================================== */

echo "\n10 — the fix slugs resolve to real cards and real scripts\n";

foreach (SONG_RELOCATE_EXPECTED_SONGID_FKS as [$t, $c, $k, $fix]) {
    if ($fix === null) { continue; }
    if (!isset(SONG_RELOCATE_FIX_MIGRATIONS[$fix])) {
        ok("$k names a known fix migration", false, "unknown slug '$fix'");
    }
}
ok('every non-null fix slug resolves in SONG_RELOCATE_FIX_MIGRATIONS', true);

ok('songRelocateFksFixedBy() partitions the const by slug',
   count(songRelocateFksFixedBy('songid-prefix-fixup')) === 4
   && count(songRelocateFksFixedBy('song-softref-fks')) === 4
   && songRelocateFksFixedBy('no-such-migration') === [],
   'prefix-fixup: ' . count(songRelocateFksFixedBy('songid-prefix-fixup'))
   . ', softref: ' . count(songRelocateFksFixedBy('song-softref-fks')));

/* The registry is the point of truth for card titles and script filenames. A
   reworded card sends the operator hunting for a button that no longer exists,
   and nothing but this assertion would notice (rule #35 — documentation and
   comments are not mechanisms). */
$_SERVER['SCRIPT_FILENAME'] = '/not-the-registry.php';
/* The registry's probes call these; setup-database.php declares them for real.
 * `_migProbe_columnExists` answers TRUE deliberately: with FALSE the softref
 * probe short-circuits on its own existence gate and never touches the database
 * at all, which would make section 11's "survives an unusable connection"
 * assertion pass without exercising anything. TRUE sends it into the real
 * INFORMATION_SCHEMA read, which is the path being asserted. */
function _migProbe_tableExists(\mysqli $db, string $t): bool { return true; }
function _migProbe_columnExists(\mysqli $db, string $t, string $c): bool { return true; }
function _migProbe_columnIsNullable(\mysqli $db, string $t, string $c): bool { return false; }
function _migProbe_triggerExists(\mysqli $db, string $t): bool { return false; }
$hasCredentials = true;
$MIGRATIONS = require $ROOT . '/appWeb/public_html/manage/includes/migration-registry.php';

foreach (SONG_RELOCATE_FIX_MIGRATIONS as $slug => $meta) {
    ok("registry has an entry for '$slug'", isset($MIGRATIONS[$slug]));
    if (!isset($MIGRATIONS[$slug])) { continue; }
    ok("…and its card title matches the refusal's copy for '$slug'",
       $MIGRATIONS[$slug]['card']['title'] === $meta['card'],
       "registry: {$MIGRATIONS[$slug]['card']['title']}\nrefusal:  {$meta['card']}");
    ok("…and the script path the refusal prints ends in the registry's filename",
       str_ends_with($meta['script'], '/' . $MIGRATIONS[$slug]['script']),
       "registry: {$MIGRATIONS[$slug]['script']}\nrefusal:  {$meta['script']}");
    ok("…and that script really exists on disk for '$slug'",
       is_file($ROOT . '/' . $meta['script']),
       $ROOT . '/' . $meta['script']);
}

/* Each migration keeps its own standalone list of constraint names — it has to,
   because it must run from the CLI with nothing else loaded. A standalone copy
   is exactly the drift rule #35 names, so it is asserted to be a SUBSET of the
   const rather than left to a comment. */
$prefixSrc = (string)file_get_contents($ROOT . '/appWeb/.sql/migrate-songid-prefix-fixup.php');
preg_match_all(
    "/\[\s*'(\w+)'\s*,\s*'(fk_\w+)'\s*,\s*'(\w+)'\s*\]/",
    $prefixSrc,
    $prefixHits,
    PREG_SET_ORDER
);
$prefixNames = array_map(static fn(array $h): string => $h[2], $prefixHits);
$constPrefix = array_map(static fn(array $e): string => $e[2], songRelocateFksFixedBy('songid-prefix-fixup'));
ok('migrate-songid-prefix-fixup.php names four cascade targets',
   count($prefixNames) === 4, json_encode($prefixNames));
ok('…and every one of them is in the const, tagged with this slug',
   $prefixNames !== [] && array_diff($prefixNames, $constPrefix) === [],
   'script: ' . json_encode($prefixNames) . "\nconst:  " . json_encode($constPrefix));

$softSrc = (string)file_get_contents($ROOT . '/appWeb/.sql/migrate-song-softref-fks.php');
preg_match_all("/_migSoftFk_addFk\(\s*\\\$\w+\s*,\s*'(fk_\w+)'/", $softSrc, $softHits);
$softNames  = $softHits[1];
$constSoft  = array_map(static fn(array $e): string => $e[2], songRelocateFksFixedBy('song-softref-fks'));
ok('migrate-song-softref-fks.php adds four constraints',
   count($softNames) === 4, json_encode($softNames));
ok('…and every one of them is in the const, tagged with this slug',
   $softNames !== [] && array_diff($softNames, $constSoft) === [],
   'script: ' . json_encode($softNames) . "\nconst:  " . json_encode($constSoft));

/* ======================================================================== *
 * 11 — the two migration probes, INVOKED
 * ======================================================================== */

echo "\n11 — the probes that decide whether those two cards are pending\n";

/*
 * There is no MySQL here, so what CAN be executed is the probes' failure
 * behaviour — and that turns out to be the interesting half. Both probes end
 * `catch (\Throwable $_e) { return false; }`, and `false` means "already
 * applied"; `\Throwable` also catches `\Error`, so a renamed helper or a missing
 * require would hide the card FOREVER while looking perfectly healthy. That is
 * the same silent-not-pending failure #1690 exists to fix, re-created one level
 * up. Both probes therefore read their constraint list OUTSIDE the try, and
 * these two assertions are what stops that being quietly undone: an unusable
 * handle must degrade to `false`, but only because the DB is unusable.
 *
 * `mysqli_init()` returns a real, UNCONNECTED mysqli — every statement on it
 * fails, which is exactly the "database unreachable" case being asserted.
 */
$deadHandle = mysqli_init();
foreach (['songid-prefix-fixup', 'song-softref-fks'] as $slug) {
    $probe   = $MIGRATIONS[$slug]['probe'];
    $answer  = null;
    $blewUp  = null;
    try {
        $answer = $probe($deadHandle);
    } catch (\Throwable $e) {
        $blewUp = get_class($e) . ': ' . $e->getMessage();
    }
    ok("'$slug' probe survives an unusable connection and fails closed to false",
       $blewUp === null && $answer === false,
       $blewUp !== null
           ? "it threw: $blewUp — the setup-database page would white-screen"
           : 'returned ' . var_export($answer, true) . ' (a probe that answers TRUE on a '
             . 'dead connection makes every card look pending)');
}

/* The names those probes ask about must come from the const, not a second typed
   list — that drift is what left `songid-prefix-fixup` permanently "applied"
   while four FKs were still RESTRICT. Source-scanned because a probe's LIST is
   not observable without a database; the list's CONTENTS are pinned to
   schema.sql in section 9, so this only has to pin the sourcing.
   COMMENTS ARE STRIPPED FIRST, by the tokenizer rather than a regex: this file's
   own explanatory prose names every one of these symbols, and prose satisfying a
   guard is the trap this repo keeps re-shipping. String literals are KEPT,
   because the slug arguments below live in them. */
$registryRaw = (string)file_get_contents(
    $ROOT . '/appWeb/public_html/manage/includes/migration-registry.php'
);
$registrySrc = '';
foreach (token_get_all($registryRaw) as $tok) {
    if (is_array($tok)) {
        if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) { continue; }
        $registrySrc .= $tok[1];
        continue;
    }
    $registrySrc .= $tok;
}

foreach (['songid-prefix-fixup', 'song-softref-fks'] as $slug) {
    ok("'$slug' derives its constraint names from SONG_RELOCATE_EXPECTED_SONGID_FKS",
       strpos($registrySrc, "songRelocateFksFixedBy('" . $slug . "')") !== false,
       'a probe with its own typed copy of the names is the drift rule #35 names — '
       . 'and the reason a card can sit green while the thing it fixes is broken');
}
ok("the softref probe no longer keys off fk_Revisions_Song alone",
   substr_count($registrySrc, "'fk_Revisions_Song'") === 0,
   'a single "representative" constraint reports a PARTIAL apply as complete — '
   . 'rule #19 requires a multi-object OR-probe');

/* SOURCING THE LIST IS NOT USING IT. This pair was added because a mutation run
   proved the assertions above insufficient: deleting clause (b) of the
   prefix-fixup probe outright — the entire #1690 fix, the loop that asks whether
   the four cascade targets are still RESTRICT — left `songRelocateFksFixedBy(...)`
   sitting there assigning a list nothing consumed, and every assertion above
   stayed GREEN. That is the "a gate whose answer is DISCARDED is decoration"
   defect the hardening guard already learned once (#1679 A12), reproduced in a
   brand-new file. Each helper below exists for exactly one probe, so "is it
   called anywhere at all?" is a faithful test of whether that probe still asks
   its question; deleting the call, or the call AND the helper, both go red.
   LIMIT, so the tick is not over-read: this cannot tell whether the loop
   consumes ALL four targets or only the first — only that the question is still
   asked. Executing the probes needs a scratch database. */
foreach ([
    '_migProbe_fkUpdateRuleNotCascade' =>
        "the prefix-fixup probe's clause (b) — 'is a cascade target still not CASCADE?'",
    '_migProbe_constraintExists' =>
        "the softref probe's per-constraint existence question",
] as $helper => $what) {
    ok("$what is still asked",
       substr_count($registrySrc, $helper . '(') >= 2,
       "'$helper(' appears " . substr_count($registrySrc, $helper . '(')
       . ' time(s) in the comment-stripped registry. One occurrence is the '
       . 'declaration alone: the helper is defined and never called, so the probe '
       . 'computes its list of constraints and then never asks about them.');
}

/* ======================================================================== *
 * 12 — the wiring: the assert function is per-song
 * ======================================================================== */

echo "\n12 — songRelocateAssertCascades() takes the song it is deciding about\n";

/* The catalogue's own fail-closed contract, EXECUTED. `songRelocateFkCatalogue()`
   is the one layer that needs a live mysqli, so nothing else in this file runs a
   line of it — and an un-executed function is where a typo waits for a real
   install mid-save. An unconnected handle drives its `catch`, which must produce
   the error shape (empty `fks`, empty `columns`) rather than propagate: an
   unverifiable catalogue has to become a refusal in
   `songRelocateAssertCascades()`, not a white screen. */
$tables = songRelocateExpectedTables();
ok('songRelocateExpectedTables() de-duplicates the const into real table names',
   $tables === array_values(array_unique($tables))
   && count($tables) > 0 && count($tables) < count(SONG_RELOCATE_EXPECTED_SONGID_FKS)
   && array_filter($tables, static fn($t): bool => is_string($t) && $t !== '') === $tables,
   count($tables) . ' table(s) from ' . count(SONG_RELOCATE_EXPECTED_SONGID_FKS)
   . ' FKs (fewer, because tblSongTranslations and two others hold two each): '
   . json_encode(array_slice($tables, 0, 5)));

$cat = songRelocateFkCatalogue(mysqli_init());
ok('songRelocateFkCatalogue() fails CLOSED on an unusable connection',
   is_array($cat) && is_string($cat['error']) && $cat['fks'] === [] && $cat['columns'] === [],
   'returned: ' . json_encode($cat));
/* NO memoisation assertion here, deliberately. One was written — "call it twice
   and compare" — and a mutant that DELETED the `static` left it green, because
   two failed probes return equal (not identical) arrays and PHP's `===` on
   arrays compares values. There is no runtime handle for "it only queried once"
   without a live connection to count queries against, so the property is pinned
   where source inspection is the honest tool: test-song-relocate-hardening.php.
   A check that cannot fail is worse than none, because its tick reads as
   coverage (rule #34). */

$sig = new \ReflectionFunction('songRelocateAssertCascades');
$params = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $sig->getParameters());
ok('songRelocateAssertCascades($db, $songId) — a per-song verdict needs the song',
   count($params) === 2 && $params[1] === 'songId',
   'parameters: ' . json_encode($params) . '. Without the id there is nothing to ask '
   . '"does this song have rows there?" about, and the check silently reverts to the '
   . 'schema-wide refusal #1690 replaced');

if ($fail === 0) {
    echo "\nAll cascade-verdict assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
