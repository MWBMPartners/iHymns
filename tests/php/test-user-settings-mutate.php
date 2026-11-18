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

/* (d) the database half: lock before read, roll back on error, no raw write elsewhere */
$src = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/includes/user_settings.php');
$start = strpos($src, 'function userSettingsMutate(');
$body = substr($src, (int)$start);
$iBegin = strpos($body, 'begin_transaction');
$iLock = strpos($body, 'FOR UPDATE');
$iUpd = strpos($body, 'UPDATE tblUsers SET Settings');
$iCommit = strpos($body, '->commit()');
chk('transaction opens before the locking read', $iBegin !== false && $iLock !== false && $iBegin < $iLock);
chk('locking read comes before the write', $iLock < $iUpd);
chk('commit comes after the write', $iUpd < $iCommit);
chk('errors roll back', strpos($body, '->rollback()') !== false);

exit($fail === 0 ? 0 : 1);
