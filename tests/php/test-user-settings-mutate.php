<?php

declare(strict_types=1);

/**
 * iHymns - the locked settings writer decides correctly (A1, epic #2180)
 *
 * ELI5: the one door to a person's settings box must (a) keep every other
 * shelf when one shelf is changed, (b) refuse, and store nothing, when the
 * caller says no, and (c) refuse a box that would grow past the safety limit.
 * The pure decision part is CALLED here; the database part (lock first, read
 * second, roll back on any error) is pinned by reading the one function's body,
 * which is the only way to reach it without a live MySQL.
 *
 *   php tests/php/test-user-settings-mutate.php
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/user_settings.php';

$fail = 0;
function chk(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) { $fail++; }
}

/* (a) a shelf change keeps the siblings */
$r = userSettingsApplyMutation(['a' => ['x' => 1], 'b' => ['y' => 2]], static function (array $c) {
    return userSettingsMergeNamespace($c, 'a', ['x' => 9]);
});
chk('shelf change succeeds', $r['ok'] === true);
chk('changed shelf holds the new value', $r['settings']['a']['x'] === 9);
chk('sibling shelf is untouched', $r['settings']['b'] === ['y' => 2]);

/* (b) a refusal stores nothing and carries the reason */
$r = userSettingsApplyMutation(['a' => 1], static fn(array $c) => 'namespace_too_large');
chk('refusal is not ok', $r['ok'] === false);
chk('refusal reason is passed through', $r['reason'] === 'namespace_too_large');
chk('refusal leaves the box as it was', $r['settings'] === ['a' => 1]);

/* garbage from the callback is refused, never written */
$r = userSettingsApplyMutation(['a' => 1], static fn(array $c) => 42);
chk('a callback returning neither array nor reason is refused', $r['ok'] === false && $r['reason'] === 'invalid_mutation');

/* (c) the whole-box backstop applies to every writer */
$r = userSettingsApplyMutation([], static fn(array $c) => ['big' => str_repeat('x', USER_SETTINGS_TOTAL_MAX_BYTES + 1)]);
chk('an oversized box is refused', $r['ok'] === false && $r['reason'] === 'total_too_large');

/* (d) the database half: lock before read, roll back on error, no raw write elsewhere.
   Since PR 2b (2b-4) the read-change-write is `userSettingsMutateLocked()` (for a caller that already
   owns the transaction and the row lock); `userSettingsMutate()` wraps it with begin / commit / roll back. */
$src = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/includes/user_settings.php');
$fnBody = static function (string $src, string $name): string {
    $s = strpos($src, 'function ' . $name . '(');
    if ($s === false) { return ''; }
    $brace = strpos($src, '{', $s);
    $d = 0; $n = strlen($src);
    for ($k = (int)$brace; $k < $n; $k++) {
        if ($src[$k] === '{') { $d++; } elseif ($src[$k] === '}') { $d--; if ($d === 0) { break; } }
    }
    return substr($src, (int)$brace, $k - (int)$brace + 1);
};
$mut = $fnBody($src, 'userSettingsMutate');
$locked = $fnBody($src, 'userSettingsMutateLocked');
chk('both functions were found', $mut !== '' && $locked !== '');
$iBegin = strpos($mut, 'begin_transaction');
$iCall = strpos($mut, 'userSettingsMutateLocked(');
$iCommit = strpos($mut, '->commit()');
chk('the wrapper opens the transaction before it calls the locked step', $iBegin !== false && $iCall !== false && $iBegin < $iCall);
chk('the wrapper commits after the locked step', $iCall < $iCommit);
chk('errors roll back', strpos($mut, '->rollback()') !== false);
chk('a refusal rolls back and nothing is stored', (bool)preg_match('/!\$outcome\[\'ok\'\]\)\s*\{\s*\$db->rollback\(\);/', $mut));
$iLock = strpos($locked, 'FOR UPDATE');
$iUpd = strpos($locked, 'UPDATE tblUsers SET Settings');
chk('the locked step takes the lock before it reads, and reads before it writes', $iLock !== false && $iUpd !== false && $iLock < $iUpd);
chk('the locked step does NOT own the transaction (no begin, commit or roll back: that is the caller\'s)', !str_contains($locked, 'begin_transaction') && !str_contains($locked, '->commit()') && !str_contains($locked, '->rollback()'));
chk('the locked step applies the whole-box cap through the same pure function (no writer can bypass it)', str_contains($locked, 'userSettingsApplyMutation('));

/* (e) the locked step, called against a database stand-in that records its statements */
$log = [];
$fake = new class($log) extends mysqli {
    public array $log;
    public function __construct(array &$log) { $this->log = &$log; }
    public function prepare(string $q): mysqli_stmt|false { $this->log[] = $q; return new class($this->log, $q) extends mysqli_stmt {
        public function __construct(public array &$l, public string $q) {}
        public function bind_param(string $t, mixed &...$v): bool { return true; }
        public function execute(?array $p = null): bool { return true; }
        public function get_result(): mysqli_result|false { return new class extends mysqli_result { public function __construct() {} public function fetch_row(): array|null|false { return ['{"a":1}']; } }; }
        public function close(): true { return true; }
    }; }
    public function begin_transaction(int $f = 0, ?string $n = null): bool { $this->log[] = 'BEGIN'; return true; }
    public function commit(int $f = 0, ?string $n = null): bool { $this->log[] = 'COMMIT'; return true; }
    public function rollback(int $f = 0, ?string $n = null): bool { $this->log[] = 'ROLLBACK'; return true; }
};
$out = userSettingsMutateLocked($fake, 7, static fn(array $c) => $c + ['b' => 2]);
chk('the locked step: lock-and-read, then write, and no transaction statement at all',
    $out['ok'] === true && $out['settings'] === ['a' => 1, 'b' => 2] && count($log) === 2 && str_contains($log[0], 'FOR UPDATE') && str_contains($log[1], 'UPDATE tblUsers SET Settings') && !in_array('BEGIN', $log, true) && !in_array('COMMIT', $log, true));
$log = [];
$out = userSettingsMutateLocked($fake, 7, static fn(array $c) => 'namespace_too_large');
chk('the locked step: a refusal writes nothing and leaves the transaction to the caller', $out['ok'] === false && $out['reason'] === 'namespace_too_large' && count($log) === 1 && !in_array('ROLLBACK', $log, true));
$log = [];
$out = userSettingsMutate($fake, 7, static fn(array $c) => $c + ['b' => 2]);
chk('the wrapper: BEGIN, lock-and-read, write, COMMIT in that order', $out['ok'] === true && $log[0] === 'BEGIN' && str_contains($log[1], 'FOR UPDATE') && str_contains($log[2], 'UPDATE tblUsers') && $log[3] === 'COMMIT');
$log = [];
$out = userSettingsMutate($fake, 7, static fn(array $c) => 'total_too_large');
chk('the wrapper: a refusal rolls back and does not commit', $out['ok'] === false && in_array('ROLLBACK', $log, true) && !in_array('COMMIT', $log, true));

exit($fail === 0 ? 0 : 1);
