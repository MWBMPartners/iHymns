<?php

declare(strict_types=1);

/**
 * iHymns — `songRelocateIsTransactionFatal()` behaves correctly (#1688 A1/D1/N1)
 * =============================================================================
 *
 * ELI5
 * ----
 * When the database kills your transaction, carrying on and saying "saved!" is
 * a lie. One small function decides whether an error means that. This file
 * CALLS that function with real exception objects and checks its answers.
 *
 * WHY THIS FILE EXISTS — it ends a regress
 * ----------------------------------------
 * The transaction-fatal rule has now been guarded three ways, and the first two
 * were both defeated:
 *
 *   1. Inline, per catch block. An adversarial review showed the rule only held
 *      inside `songRelocate()` while ELEVEN catch blocks between the relocate
 *      and the caller's `commit()` still swallowed 1213/1205 — the fix did not
 *      reach the thing it was protecting.
 *   2. Extracted to one predicate, guarded by SOURCE INSPECTION — assertions
 *      that the call sites mention the predicate and that the predicate's body
 *      mentions `1213`, `1205` and `mysqli_sql_exception`. A second review
 *      replaced the predicate's body with `return false;` — leaving all that
 *      vocabulary in place in a now-dead `$fatalCodes` array — and the whole
 *      suite stayed green while every one of the eleven sites resumed
 *      swallowing. Extraction fixed the REACH and left the REACHED THING
 *      unpinned.
 *
 * The third way is this file, and it is different in kind: it does not read the
 * source at all. It constructs exceptions and asserts the returned booleans. A
 * predicate cannot pass by CONTAINING the right words, only by ANSWERING
 * correctly — so `return false;` fails here immediately, and so does any future
 * rewrite that is subtly wrong.
 *
 * The general lesson, worth applying elsewhere: when a property lives in a PURE
 * function, test the function. Source inspection is for properties that have no
 * runtime handle — "every call site does X", "no page hardcodes Y" — and it
 * should never be the primary evidence for something you could simply call.
 * No database is needed here: the predicate only inspects exception objects.
 *
 * THE CASE THAT MOTIVATED N1
 * --------------------------
 * `songRedirectsTableReady($db, true)` — added in the SAME pass as the
 * predicate — wraps whatever it caught in a plain `\RuntimeException` with the
 * original as `previous`. The predicate's first version type-checked only the
 * OUTERMOST exception, so a deadlock raised inside that probe arrived as a
 * RuntimeException, was declared not-fatal, and was swallowed — reinstating the
 * false success through a brand-new code path introduced by its own fix. Hence
 * the wrapped cases below; they are not hypothetical.
 *
 *   php tests/php/test-transaction-fatal.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/song_relocate.php
 * @see https://www.php.net/manual/en/exception.getprevious.php
 * @see https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_relocate.php';

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

/** A mysqli_sql_exception carrying a specific MySQL error number. */
function txnFatalMysqli(int $code): \mysqli_sql_exception
{
    return new \mysqli_sql_exception('synthetic MySQL error', $code);
}

echo "Behaviour of songRelocateIsTransactionFatal()\n";

/* ---- the two fatal codes, bare ---------------------------------------- */
ok('1213 ER_LOCK_DEADLOCK is fatal',
   songRelocateIsTransactionFatal(txnFatalMysqli(1213)) === true);
ok('1205 ER_LOCK_WAIT_TIMEOUT is fatal',
   songRelocateIsTransactionFatal(txnFatalMysqli(1205)) === true);

/* ---- codes that must NOT be fatal -------------------------------------- *
 * 1146 is the one that matters most: an un-migrated install reading an
 * optional table MUST still degrade quietly (rule #28 C, fail-open). If this
 * ever returns true, every existence probe in the tree starts throwing on
 * installs that simply have not run a migration yet. */
foreach ([
    1146 => 'ER_NO_SUCH_TABLE — an un-migrated install must still fail OPEN',
    1062 => 'ER_DUP_ENTRY — a real constraint violation, but not transaction-fatal',
    1054 => 'ER_BAD_FIELD_ERROR — a missing column, same reasoning as 1146',
    1451 => 'ER_ROW_IS_REFERENCED_2 — the FK refusal; fatal to the STATEMENT, not the transaction',
    0    => 'code 0 — no error number at all',
] as $code => $why) {
    ok("$code is NOT fatal ($why)",
       songRelocateIsTransactionFatal(txnFatalMysqli($code)) === false);
}

/* ---- non-mysqli exceptions --------------------------------------------- */
ok('a plain RuntimeException is not fatal',
   songRelocateIsTransactionFatal(new \RuntimeException('something else')) === false);
ok('a plain Error is not fatal',
   songRelocateIsTransactionFatal(new \Error('type error')) === false);
ok('a RuntimeException whose CODE is 1213 but which is not a mysqli error is not fatal',
   songRelocateIsTransactionFatal(new \RuntimeException('not from mysqli', 1213)) === false,
   'the code alone must not be enough — only mysqli reports MySQL error numbers');

/* ---- WRAPPED — this is N1, the defect that shipped ---------------------- */
ok('a RuntimeException WRAPPING a 1213 is fatal',
   songRelocateIsTransactionFatal(new \RuntimeException('probe failed', 0, txnFatalMysqli(1213))) === true,
   'songRedirectsTableReady($db, true) wraps exactly like this — if this is false, '
   . 'a deadlock inside that probe is swallowed and the caller commits');
ok('a RuntimeException WRAPPING a 1205 is fatal',
   songRelocateIsTransactionFatal(new \RuntimeException('probe failed', 0, txnFatalMysqli(1205))) === true);
ok('a DOUBLE wrap is still fatal',
   songRelocateIsTransactionFatal(
       new \LogicException('outer', 0, new \RuntimeException('inner', 0, txnFatalMysqli(1213)))
   ) === true);
ok('a RuntimeException wrapping a NON-fatal mysqli error is not fatal',
   songRelocateIsTransactionFatal(new \RuntimeException('probe failed', 0, txnFatalMysqli(1146))) === false,
   'walking the chain must not turn every wrapped DB error into a rollback');

/* ---- termination ------------------------------------------------------- *
 * A guard must not be the thing that hangs a save. A userland cause chain can
 * be made cyclic; the walk is depth-bounded so it cannot spin. */
$a = new \RuntimeException('a');
$deep = txnFatalMysqli(1213);
for ($i = 0; $i < 50; $i++) { $deep = new \RuntimeException("wrap $i", 0, $deep); }
$t0 = microtime(true);
$verdict = songRelocateIsTransactionFatal($deep);
$elapsed = microtime(true) - $t0;
ok('a 50-deep chain terminates promptly', $elapsed < 0.5,
   sprintf('took %.3fs', $elapsed));
ok('…and the depth bound is honest about giving up rather than lying',
   $verdict === false,
   'the bound is 10; a 50-deep wrap is beyond it. This asserts the DOCUMENTED '
   . 'behaviour — if the bound is ever raised, update this expectation '
   . 'deliberately rather than discovering it by surprise');

if ($fail === 0) {
    echo "\nAll transaction-fatal behaviour assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
