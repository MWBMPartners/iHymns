<?php

declare(strict_types=1);

/**
 * iHymns - signing out a browser must not stop iPhone notifications (A6, epic #2180)
 *
 * ELI5
 * ----
 * Anna is signed in on a laptop and on her iPhone. When she signed the laptop
 * out (or removed it from her device list), the server deleted EVERY Apple
 * notification registration she had, so the iPhone went quiet. Now the Apple
 * registrations are only removed when the session being signed out is itself an
 * Apple one. This guard proves that in two ways: the decision function is
 * CALLED, and every place in api.php that deletes a person's Apple push rows
 * is checked to sit behind that decision.
 *
 * Detailed (rule #34): the list of delete sites is DERIVED from api.php - every
 * `DELETE FROM tblApnsTokens WHERE UserId = ?` (the broad, whole-person form;
 * the per-token unregister form is deliberately different and not matched) -
 * not typed here. For each, the text between its enclosing `case '...':` and
 * the delete must call apiTokenSessionOwnsApplePush(). The expected number of
 * such sites is not hard-coded either: we only require that the scan sees
 * `auth_logout` and `device_signout`, the two this step fixes.
 *
 * Step G4 later replaces this rule with per-session registrations.
 *
 *   php tests/php/test-signout-apple-push-guard.php
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/api_tokens.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

/* ---- the decision, called ---- */
chk('an Apple session owns Apple push rows', apiTokenSessionOwnsApplePush('apple') === true);
chk('a web session does not', apiTokenSessionOwnsApplePush('web') === false);
chk('an Android session does not', apiTokenSessionOwnsApplePush('android') === false);
chk('an undeclared platform (NULL) might be Apple (the shipped app declares none)', apiTokenSessionOwnsApplePush(null) === true);
chk('an empty platform might be Apple too', apiTokenSessionOwnsApplePush('') === true);

/* A browser sign-in is always stored as 'web', so it keeps the iPhone's rows. */
$_uaChrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
$_fb = apiTokenWebDeviceFallback(null, null, $_uaChrome);
chk('a browser sign-in with no declared platform is stored as web', $_fb['platform'] === 'web');
chk('a web session still keeps Apple push rows', apiTokenSessionOwnsApplePush($_fb['platform']) === false);
$_fbApp = apiTokenWebDeviceFallback(null, null, 'iHymns/1.0 CFNetwork/1494 Darwin/23.4.0');
chk('the Apple app (non-browser UA, no platform) stays NULL', $_fbApp['platform'] === null);
chk('so its sign-out removes its own push rows', apiTokenSessionOwnsApplePush($_fbApp['platform']) === true);

/* ---- every whole-person delete sits behind it ---- */
$src = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/api.php');
/* Keep only code strings and the surrounding code, but drop comments so a comment mentioning the call cannot satisfy the check. */
$code = '';
foreach (token_get_all($src) as $tok) {
    if (is_array($tok)) {
        if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) { $code .= str_repeat("\n", substr_count($tok[1], "\n")); continue; }
        $code .= $tok[1];
    } else {
        $code .= $tok;
    }
}
$found = [];
$off = 0;
while (preg_match('/DELETE\s+FROM\s+tblApnsTokens\s+WHERE\s+UserId\s*=\s*\?[\'"]/', $code, $m, PREG_OFFSET_CAPTURE, $off)) {
    $pos = $m[0][1];
    $off = $pos + 10;
    $caseAt = strrpos(substr($code, 0, $pos), "case '");
    $caseName = $caseAt === false ? '?' : (preg_match("/case '([^']+)'/", substr($code, $caseAt, 80), $cm) ? $cm[1] : '?');
    $between = $caseAt === false ? '' : substr($code, $caseAt, $pos - $caseAt);
    $found[$caseName] = (bool)preg_match('/if\s*\([^)]*apiTokenSessionOwnsApplePush\(/', $between);
}
chk('the scan sees the auth_logout delete', array_key_exists('auth_logout', $found));
chk('the scan sees the device_signout delete', array_key_exists('device_signout', $found));
foreach ($found as $case => $guarded) {
    chk("case '$case': the Apple push delete sits behind apiTokenSessionOwnsApplePush()", $guarded);
}

exit($fail === 0 ? 0 : 1);
