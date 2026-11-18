<?php

declare(strict_types=1);

/**
 * iHymns - the switch that refuses whole-box settings saves (A9, epic #2180)
 *
 * ELI5
 * ----
 * A settings save that names no shelf replaces the WHOLE box and erases every
 * other shelf (push choices, list sorts, card layouts). No current client does
 * that, but it is still reachable. The owner gets a switch
 * (`user_settings_legacy_blob_writes`): leave it alone and nothing changes; set
 * it to '0' and such a save is refused with 409 `namespace_required`. This
 * guard checks the refusal really sits in FRONT of the write, answers with the
 * right status and reason, only an exact '0' turns it on, and the public API
 * description says so.
 *
 * Detailed (rule #34): the handler body is cut out of api.php by its own case
 * labels and comments are stripped first, so a sentence in a comment cannot
 * satisfy a check. The order check is positional: the switch must come before
 * the first userSettingsMutate() call in the whole-box branch.
 *
 *   php tests/php/test-user-settings-legacy-switch.php
 */

$root = dirname(__DIR__, 2) . '/appWeb/public_html';
$fail = 0;
function chk(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) { $fail++; }
}
function stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            $out .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1];
        } else { $out .= $t; }
    }
    return $out;
}

$api = stripComments((string)file_get_contents($root . '/api.php'));
$a = strpos($api, "case 'user_settings':");
$b = strpos($api, "case 'auth_forgot_password':", (int)$a);
chk('found the user_settings handler', $a !== false && $b !== false && $b > $a);
$body = substr($api, (int)$a, (int)$b - (int)$a);

/* The whole-box branch starts after the namespaced branch returns. */
$legacyAt = strpos($body, "if (strlen(userSettingsEncode(\$settings)) > 16384)");
chk('found the whole-box branch', $legacyAt !== false);
$switchAt = strpos($body, "getAppSetting('user_settings_legacy_blob_writes', '1') === '0'");
chk("the switch is read with default '1' and compared to the exact text '0'", $switchAt !== false);
chk('the switch is checked BEFORE the size check and BEFORE any whole-box write', $switchAt !== false && $legacyAt !== false && $switchAt < $legacyAt);
$firstWholeWrite = strpos($body, 'userSettingsMutate($db, $authUserId, static function (array $_current)');
chk('the whole-box write comes after the switch', $firstWholeWrite !== false && $switchAt < $firstWholeWrite);
$refusal = substr($body, (int)$switchAt, 400);
chk("the refusal answers 409 with reason 'namespace_required'", str_contains($refusal, "'reason' => 'namespace_required'") && str_contains($refusal, '409'));
chk('the refusal stops the handler (break) before storing anything', (bool)preg_match('/409\);\s*break;/', $refusal));

$docs = (string)file_get_contents($root . '/api-docs.yaml');
chk('api-docs.yaml documents the switch name', str_contains($docs, 'user_settings_legacy_blob_writes'));
chk('api-docs.yaml documents the 409 namespace_required answer', str_contains($docs, 'namespace_required') && str_contains($docs, '"409"'));
chk("api-docs.yaml no longer claims this is the 'only' preference store", !str_contains($docs, 'This is the **only** user-preference store'));

exit($fail === 0 ? 0 : 1);
