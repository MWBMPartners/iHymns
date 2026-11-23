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
 * Step G4 (2a-8) moved the delete into apnsDeleteForSessions(), where this rule is the
 * previous-behaviour branch; per-session registrations are in test-apns-session-tie.php.
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

/* ---- every whole-person delete sits behind it ----
   Step 2a-8 (G4) moved the delete into ONE helper, apnsDeleteForSessions(), whose previous-behaviour branch is
   the A6 rule. So the derived list is now: every `DELETE FROM tblApnsTokens WHERE UserId = ?` anywhere under
   public_html must be inside that helper and behind `if ($sessionOwnsApplePush)`; and each sign-out case must
   hand the helper the answer of apiTokenSessionOwnsApplePush(). (Per-session registrations are checked by
   tests/php/test-apns-session-tie.php.) */
require_once dirname(__DIR__, 2) . '/tests/php/lib/php_source_units.php';
$web = dirname(__DIR__, 2) . '/appWeb/public_html';
$sites = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') { continue; }
    $fsrc = (string)file_get_contents($f->getPathname());
    if (stripos($fsrc, 'tblApnsTokens') === false) { continue; }
    foreach (phpSourceUnits($fsrc) as $unit => $u) {
        foreach ($u['strings'] as $q) {
            if (preg_match('/DELETE\s+FROM\s+tblApnsTokens\s+WHERE\s+UserId\s*=\s*\?\s*$/i', trim($q))) {
                $sites[substr($f->getPathname(), strlen($web) + 1) . '#' . $unit] = $u['code'];
            }
        }
    }
}
chk('the only whole-person Apple push delete is inside apnsDeleteForSessions()', array_keys($sites) === ['includes/apns.php#apnsDeleteForSessions'], implode('; ', array_keys($sites)));
foreach ($sites as $where => $code) {
    chk("$where: that delete is the first thing inside `if (!\$perSession || !apnsTokenHashReady)` AND sits behind `if (\$sessionOwnsApplePush)`",
        (bool)preg_match('/if \(!\$perSession \|\| !apnsTokenHashReady\(\$db\)\) \{ if \(\$sessionOwnsApplePush\) \{ \$del = \$db->prepare\(\'@STR@\' @SQL:DELETE:tblApnsTokens@ \);/', $code));
}
$apiUnits = phpSourceUnits((string)file_get_contents($web . '/api.php'));
foreach (['auth_logout', 'device_signout'] as $case) {
    $c = $apiUnits["case '$case'"]['code'] ?? '';
    chk("case '$case': the helper is handed apiTokenSessionOwnsApplePush()", (bool)preg_match('/apnsDeleteForSessions\([^;]*apiTokenSessionOwnsApplePush\(/', $c));
}

exit($fail === 0 ? 0 : 1);
