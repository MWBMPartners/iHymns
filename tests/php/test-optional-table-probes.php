<?php

declare(strict_types=1);

/**
 * iHymns — the two optional-table probes have OPPOSITE failure contracts (#1691 D5)
 * =================================================================================
 *
 * ELI5
 * ----
 * Two little functions both ask "does this table exist yet?". One is allowed to
 * shrug and say no when it cannot tell. The other must refuse to guess. This
 * file checks each one behaves the way its job requires.
 *
 * WHY THE DIFFERENCE IS LOAD-BEARING
 * ----------------------------------
 * Migrations here are run by hand from a web page, so a table in `schema.sql`
 * may simply not exist on a given docroot yet. Every optional read is therefore
 * probed first. But what a probe should do when the probe ITSELF fails depends
 * entirely on what is gated behind it:
 *
 *   `songRedirectsTableReady()`  — SWALLOWS. Returns false on a throw. Correct:
 *       the worst case of a wrongly-absent redirect table is a missing
 *       forwarding note. Degrading quietly is the right call (rule #28 C,
 *       fail-open on an un-migrated install).
 *
 *   `songRelocateTableExists()`  — DOES NOT SWALLOW. Correct for a different
 *       reason: it gates the `tblContentRestrictions` rewrite, and a
 *       false-because-the-probe-broke would silently skip it — leaving a
 *       restriction pointing at the dead SongId, i.e. **withheld content
 *       becomes readable**, with the move committing anyway. That is the M3
 *       finding #1688 exists to remove.
 *
 * So the difference is deliberate, it is documented in `song_relocate.php`'s
 * doc-block, and **nothing verified it** — an adversarial review (#1691 D5)
 * showed the strict probe could be swapped for the swallowing one at the call
 * site with the entire suite staying green. This file cannot stop a swap (that
 * is a source-shaped property, see the note at the bottom), but it does pin the
 * CONTRACT each probe promises, so a rewrite that quietly makes the strict one
 * swallow — the more likely regression, since "make it not throw" reads like a
 * robustness improvement — fails here.
 *
 * WHY IT IS A BEHAVIOURAL TEST
 * ----------------------------
 * This branch shipped four consecutive guards that were green while the thing
 * they guarded was broken, every time because source inspection was used as
 * evidence for a property that has a runtime handle. These two probes are
 * callable, so this file CALLS them, with a mysqli double that fails on
 * demand. See `tests/php/test-transaction-fatal.php` for the worked example
 * that established the rule.
 *
 *   php tests/php/test-optional-table-probes.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/song_relocate.php  — songRelocateTableExists()
 * @see appWeb/public_html/includes/song_redirects.php — songRedirectsTableReady()
 */

$root = dirname(__DIR__, 2);

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        $detail\n"; }
    }
}

/* ------------------------------------------------------------------ *
 * A mysqli double whose prepare() throws the way a real one does under
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT. We never reach a real
 * database here — the question is purely "what does the probe do when
 * the underlying call throws?", which needs no server to answer.
 * ------------------------------------------------------------------ */
final class ProbeFailingMysqli extends \mysqli
{
    public function __construct(private int $code = 1146) {}

    #[\ReturnTypeWillChange]
    public function prepare(string $query): mixed
    {
        throw new \mysqli_sql_exception('synthetic probe failure', $this->code);
    }
}

/* Both files are self-contained enough to include without a DB connection:
   they declare functions and require only db_mysql.php, which does not connect
   at include time. */
require_once $root . '/appWeb/public_html/includes/song_relocate.php';

echo "Optional-table probe failure contracts\n";

/* ---- the STRICT probe -------------------------------------------------- */
$threw = false;
$wrong = null;
try {
    songRelocateTableExists(new ProbeFailingMysqli(1146), 'tblProbeContractCheckA');
} catch (\Throwable $e) {
    $threw = true;
    $wrong = $e;
}
ok('songRelocateTableExists() THROWS when the probe itself fails — it must not guess',
   $threw,
   'it returned instead of throwing. A false-because-the-probe-broke silently skips the '
   . 'tblContentRestrictions rewrite, leaving a restriction on the dead SongId — withheld '
   . 'content becomes readable and the move commits anyway (#1688 M3).');

ok('…and the thrown exception still carries the mysqli error, so callers can classify it',
   $wrong instanceof \mysqli_sql_exception || ($wrong && $wrong->getPrevious() instanceof \mysqli_sql_exception),
   'the cause was wrapped in a way that loses the mysqli type; songRelocateIsTransactionFatal() '
   . 'walks getPrevious(), so a wrapper is fine, but the mysqli exception must survive somewhere '
   . 'in the chain');

/* A deadlock during the probe must be distinguishable from a missing table.
   This is the N1 lesson: the strict probe is exactly where a wrapped 1213 was
   found being swallowed. */
$deadlockPropagated = false;
try {
    songRelocateTableExists(new ProbeFailingMysqli(1213), 'tblProbeContractCheckB');
} catch (\Throwable $e) {
    $deadlockPropagated = songRelocateIsTransactionFatal($e);
}
ok('a DEADLOCK raised inside the strict probe is still recognised as transaction-fatal',
   $deadlockPropagated,
   'songRelocateIsTransactionFatal() could not see the 1213 through this probe — the exact '
   . 'N1 defect, where a wrapper hid the cause and the caller committed a rolled-back move');

/* ---- the SWALLOWING probe ---------------------------------------------- */
require_once $root . '/appWeb/public_html/includes/song_redirects.php';

$swallowed = null;
try {
    $swallowed = songRedirectsTableReady(new ProbeFailingMysqli(1146));
} catch (\Throwable $e) {
    $swallowed = 'THREW: ' . $e->getMessage();
}
ok('songRedirectsTableReady() SWALLOWS a probe failure and reports "not ready"',
   $swallowed === false,
   'got ' . var_export($swallowed, true) . '. A missing forwarding note is a degraded '
   . 'permalink, not a rights failure — this one is deliberately fail-open (rule #28 C).');

/* ---- the distinction itself -------------------------------------------- */
ok('the two probes genuinely differ — this file would be pointless if they agreed',
   $threw === true && $swallowed === false,
   'both probes behaved the same way, so the deliberate asymmetry documented in '
   . 'song_relocate.php no longer exists in the code');

/* ---------------------------------------------------------------------- *
 * WHAT THIS FILE CANNOT DO, stated so its tick is not over-read.
 *
 * It pins what each probe DOES. It cannot stop somebody swapping which probe a
 * CALL SITE uses — that is a source-shaped property with no runtime handle
 * short of a live database, and it is #1691 D5's actual ask. The structural fix
 * recommended there is to remove the choice rather than assert it: have the
 * content-restriction rewrite reach its gate through one named helper, so there
 * is no call site left to swap. Until that lands, this covers the likelier
 * regression — somebody "hardening" the strict probe into a swallowing one,
 * which reads like an improvement right up until content leaks.
 * ---------------------------------------------------------------------- */

if ($fail === 0) {
    echo "\nBoth probes honour their stated failure contracts.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
