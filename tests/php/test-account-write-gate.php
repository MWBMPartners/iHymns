<?php

declare(strict_types=1);

/**
 * iHymns - every personal-data write in api.php checks the account-writes brake (C2, epic #2180)
 *
 * ELI5
 * ----
 * The "account writes" switch is an emergency brake: when an admin pauses a site,
 * that site must refuse to change anyone's saved data. A brake only works if
 * EVERY handler that changes saved data checks it. This guard finds those
 * handlers itself and fails if one forgets, so a new one cannot slip through.
 *
 * How it finds them (rule #34: derived from the tree, not typed here)
 * -------------------------------------------------------------------
 *  1. Every table that links to a user in schema.sql is listed. Each one MUST
 *     be classified below as "gate" (a person's own saved data) or "exempt" with
 *     a stated reason. A new user-linked table with no classification fails, so
 *     someone has to decide on purpose.
 *  2. Every function under includes/ that writes a gated table (or a personal
 *     column of tblUsers) is found, then every function that calls one of those,
 *     until nothing new turns up.
 *  3. Every `case` in api.php whose body writes a gated table directly, or calls
 *     one of those functions, is a personal-data write. It must call
 *     accountWritesRefuseIfOff() (or, for song_view, accountWritesAllowedHere())
 *     BEFORE its first write.
 *  4. A short reviewed list of cases is exempt, each with a reason. An exempt
 *     case that no longer writes anything fails (a stale exemption would hide a
 *     future write).
 *
 * Also checked: sign-in, sign-out and registration are NOT gated; the browser's
 * reason code equals the server's (rule #35); every gated action documents the
 * 409 in api-docs.yaml.
 *
 * Mutation proofs are in the C2 commit: removing the call from favorites_remove,
 * moving it after the write, and adding it to auth_login each turn this red.
 *
 *   php tests/php/test-account-write-gate.php
 */

$repo = dirname(__DIR__, 2);
$web = $repo . '/appWeb/public_html';
require_once $repo . '/tests/php/lib/dispatch_parser.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}
function stripc(string $src): string
{
    $o = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) { $o .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1]; } else { $o .= $t; }
    }
    return $o;
}

/* ------------------------------------------------------------------
 * The reviewed classification of EVERY table that links to a user.
 * 'gate' = a person's own saved data: writes to it must check the brake.
 * Anything else is exempt, with the reason it is not the person's own data.
 * ------------------------------------------------------------------ */
$CLASS = [
    'tblUserFavorites' => 'gate', 'tblUserSetlists' => 'gate', 'tblUserSetlistTombstones' => 'gate',
    'tblSharedSetlists' => 'gate', 'tblSetlistCollaborators' => 'gate', 'tblSetlistSchedule' => 'gate',
    'tblUserCustomTags' => 'gate', 'tblUserSyncTombstones' => 'gate', 'tblUserProfileItems' => 'gate',
    'tblUserSongMarkup' => 'gate', 'tblSongHistory' => 'gate',

    'tblAccountEnrolKeys'  => 'exempt: device sign-in keys (access, not saved data)',
    'tblActivityLog'       => 'exempt: audit log written by the system',
    'tblApiKeyRequests'    => 'exempt: developer API-key administration',
    'tblApiKeys'           => 'exempt: developer API-key administration',
    'tblApiTokens'         => 'exempt: sign-in tokens',
    'tblApnsTokens'        => 'exempt: device push registration',
    'tblPushSubscriptions' => 'exempt: device push registration',
    'tblPushTokens'        => 'exempt: device push registration',
    'tblAuthDeviceCodes'   => 'exempt: sign-in pairing',
    'tblContentLicences'   => 'exempt: organisation licence administration',
    'tblEmailLoginTokens'  => 'exempt: sign-in',
    'tblEmailVerificationTokens' => 'exempt: sign-in',
    'tblPasswordResetTokens' => 'exempt: account recovery',
    'tblGatingCapabilities' => 'exempt: admin configuration (attribution only)',
    'tblGatingRules'       => 'exempt: admin configuration (attribution only)',
    'tblLicenceTypes'      => 'exempt: admin configuration (attribution only)',
    'tblLiveFollowSessions' => 'exempt: a live service session, not saved personal data',
    'tblLyricAnnotationVotes' => 'exempt: catalogue contribution',
    'tblLyricLineAnnotations' => 'exempt: catalogue contribution (attribution)',
    'tblLyricLineTranslations' => 'exempt: catalogue contribution (attribution)',
    'tblLyrics'            => 'exempt: catalogue contribution (attribution)',
    'tblLyricsConflicts'   => 'exempt: catalogue editing (attribution)',
    'tblLyricsReviewQueue' => 'exempt: catalogue editing (attribution)',
    'tblNotifications'     => 'exempt: generated by the system',
    'tblOrgServiceScheduleExternalRefs' => 'exempt: organisation administration',
    'tblOrgVenueExternalRefs' => 'exempt: organisation administration',
    'tblOrganisationExternalRefs' => 'exempt: organisation administration',
    'tblOrganisationLogos' => 'exempt: organisation administration',
    'tblOrganisationMembers' => 'exempt: organisation administration',
    'tblPresentationThemeAssignments' => 'exempt: organisation projection settings',
    'tblPrintTemplateCustomLayout' => 'exempt: organisation print administration',
    'tblPrintTemplates'    => 'exempt: organisation print administration (not in the plan\'s list of saved data)',
    'tblSearchQueries'     => 'exempt: no longer linked to anyone (step A4)',
    'tblServiceDriverKeys' => 'exempt: organisation administration',
    'tblSetlistTemplates'  => 'exempt: curator-made templates (attribution)',
    'tblSongIdentityMap'   => 'exempt: catalogue editing (attribution)',
    'tblSongQualityFindings' => 'exempt: catalogue editing (attribution)',
    'tblSongRevisions'     => 'exempt: catalogue editing (attribution)',
    'tblSongTagMap'        => 'exempt: catalogue editing (attribution)',
    'tblSongRequests'      => 'exempt: a submission to the curators, not synced saved data (not in the plan\'s list)',
    'tblSongUsageEvents'   => 'exempt: organisation licence usage log',
    'tblUserAuthProviders' => 'exempt: linked sign-in identities (sign-in)',
    'tblWebhookSubscriptions' => 'exempt: organisation administration',
];

/* Cases that write a gated table but are deliberately NOT gated. */
$CASE_EXEMPT = [
    'setlist_get'                 => 'a public shared-link view only stamps "viewed at" on the shared list; anyone, signed in or not',
    'user_setlists'               => 'a READ whose only write is the deterministic housekeeping that turns an expired set list into a delete marker',
    'auth_reset_password'         => 'account recovery for a signed-out person; sign-in class, not a change to saved data',
    'admin_user_update'           => 'an operator editing another account (same as /manage/users, which is not gated); the brake protects people\'s OWN saved data',
    'admin_user_rename'           => 'operator action, as above',
    'admin_user_password_reset'   => 'operator action, as above',
    'admin_user_delete'           => 'operator action, as above',
];

/* Cases that must NEVER be gated (getting in and out). */
$NEVER_GATED = ['auth_login', 'auth_register', 'auth_logout', 'auth_apple', 'auth_me', 'auth_email_login_request', 'auth_email_login_verify'];

/* ---- 1. every user-linked table is classified ---- */
echo "1. every user-linked table is classified\n";
$schema = (string)file_get_contents($repo . '/appWeb/.sql/schema.sql');
$linked = [];
if (preg_match_all('/CREATE TABLE IF NOT EXISTS (tbl\w+) \((.*?)\n\) ENGINE/s', $schema, $m, PREG_SET_ORDER)) {
    foreach ($m as $x) {
        if (preg_match('/FOREIGN KEY \(\w+\)\s+REFERENCES tblUsers\s*\(Id\)/', $x[2])) { $linked[$x[1]] = true; }
    }
}
if (preg_match_all('/ALTER TABLE (tbl\w+) ADD CONSTRAINT \w+ FOREIGN KEY \(\w+\) REFERENCES tblUsers/', $schema, $m2)) {
    foreach ($m2[1] as $t) { $linked[$t] = true; }
}
chk('the scan found the user-linked tables (' . count($linked) . ')', count($linked) >= 40);
$unclassified = array_values(array_diff(array_keys($linked), array_keys($CLASS)));
chk('no user-linked table is unclassified (decide: gate or exempt with a reason)', $unclassified === [], implode(', ', $unclassified));
$stale = array_values(array_diff(array_keys($CLASS), array_keys($linked)));
chk('no classification names a table that no longer links to users', $stale === [], implode(', ', $stale));
$gated = array_keys(array_filter($CLASS, fn($v) => $v === 'gate'));
chk('there are gated tables to protect', count($gated) >= 10);

/* ---- 2. writer functions ---- */
echo "2. writer functions are derived\n";
$usersCols = 'Settings|PreferredLanguagesJson|DisplayName|Email|AvatarService|Username|PasswordHash';
$writeRe = '/(INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+(' . implode('|', $gated) . ')\b/i';
$usersRe = '/UPDATE\s+tblUsers\s+SET\s+[^;"\']*?\b(' . $usersCols . ')\s*=/is';
$funcs = [];
foreach (array_merge(glob($web . '/includes/*.php'), glob($web . '/manage/includes/*.php')) as $f) {
    $src = stripc((string)file_get_contents($f));
    if (!preg_match_all('/function\s+(\w+)\s*\(/', $src, $fm, PREG_OFFSET_CAPTURE)) { continue; }
    foreach ($fm[1] as $i => $mm) {
        $brace = strpos($src, '{', $fm[0][$i][1]);
        if ($brace === false) { continue; }
        $d = 0; $n = strlen($src);
        for ($k = $brace; $k < $n; $k++) {
            if ($src[$k] === '{') { $d++; } elseif ($src[$k] === '}') { $d--; if ($d === 0) { break; } }
        }
        $funcs[$mm[0]][] = substr($src, $brace, $k - $brace + 1);
    }
}
$writers = [];
foreach ($funcs as $name => $bodies) {
    foreach ($bodies as $b) { if (preg_match($writeRe, $b) || preg_match($usersRe, $b)) { $writers[$name] = true; } }
}
do {
    $grew = false;
    foreach ($funcs as $name => $bodies) {
        if (isset($writers[$name])) { continue; }
        foreach ($bodies as $b) {
            foreach (array_keys($writers) as $w) {
                if (preg_match('/\b' . preg_quote($w, '/') . '\s*\(/', $b)) { $writers[$name] = true; $grew = true; continue 3; }
            }
        }
    }
} while ($grew);
chk('the scan found writer functions (' . count($writers) . ')', count($writers) >= 8);
chk('it found the one locked settings writer', isset($writers['userSettingsMutate']));

/* ---- 3 + 4. the cases ---- */
echo "3. every personal-data write case checks the brake\n";
$apiFile = $web . '/api.php';
$cases = dispatchParserCaseTokens($apiFile, '$action');
$toks = dispatchParserTokens($apiFile);
chk('the scan found the api.php cases (' . count($cases) . ')', count($cases) > 250);
$bodies = [];
for ($i = 0; $i < count($cases); $i++) {
    $a = $cases[$i]['index'];
    $b = $cases[$i + 1]['index'] ?? count($toks);
    $body = '';
    for ($k = $a; $k < $b; $k++) {
        $t = $toks[$k];
        $body .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
    }
    $bodies[$cases[$i]['name']] = $body;
}
$firstWrite = static function (string $body) use ($writeRe, $usersRe, $writers): ?int {
    $pos = [];
    if (preg_match($writeRe, $body, $m, PREG_OFFSET_CAPTURE)) { $pos[] = $m[0][1]; }
    if (preg_match($usersRe, $body, $m, PREG_OFFSET_CAPTURE)) { $pos[] = $m[0][1]; }
    foreach (array_keys($writers) as $w) {
        if (preg_match('/\b' . preg_quote($w, '/') . '\s*\(/', $body, $m, PREG_OFFSET_CAPTURE)) { $pos[] = $m[0][1]; }
    }
    return $pos ? min($pos) : null;
};
$writerCases = [];
foreach ($bodies as $name => $body) {
    $fw = $firstWrite($body);
    if ($fw !== null) { $writerCases[$name] = $fw; }
}
chk('the scan found personal-data write cases (' . count($writerCases) . ')', count($writerCases) >= 25);
foreach ($writerCases as $name => $fw) {
    if (isset($CASE_EXEMPT[$name])) { continue; }
    $body = $bodies[$name];
    $gateAt = null;
    foreach (['accountWritesRefuseIfOff(', 'accountWritesAllowedHere('] as $needle) {
        $p = strpos($body, $needle);
        if ($p !== false) { $gateAt = $gateAt === null ? $p : min($gateAt, $p); }
    }
    chk("case '$name' checks the brake before its first write", $gateAt !== null && $gateAt < $fw);
}
foreach ($CASE_EXEMPT as $name => $why) {
    chk("exempt case '$name' still writes (else the exemption is stale): $why", isset($writerCases[$name]));
    chk("exempt case '$name' really is not gated", !str_contains($bodies[$name] ?? '', 'accountWritesRefuseIfOff('));
}
echo "4. getting in and out is never gated\n";
foreach ($NEVER_GATED as $name) {
    if (!isset($bodies[$name])) { continue; }
    chk("case '$name' is not gated", !str_contains($bodies[$name], 'accountWrites'));
}

/* ---- the reason code agrees, browser and server ---- */
echo "5. browser and server agree on the reason code\n";
$js = (string)file_get_contents($web . '/js/constants.js');
$php = (string)file_get_contents($web . '/includes/account_write_gate.php');
preg_match("/ACCOUNT_WRITES_OFF_REASON\s*=\s*'([^']+)'/", $js, $jm);
preg_match("/const ACCOUNT_WRITES_OFF_REASON = '([^']+)'/", $php, $pm);
chk('constants.js and account_write_gate.php carry the same reason code', isset($jm[1], $pm[1]) && $jm[1] === $pm[1] && $jm[1] === 'account_writes_off_here');
chk('api-client.js dispatches the event from the constant (not a raw literal)', str_contains((string)file_get_contents($web . '/js/utils/api-client.js'), 'EVT_ACCOUNT_WRITES_OFF'));

/* ---- documented ---- */
echo "6. every gated action documents the 409\n";
$docs = (string)file_get_contents($web . '/api-docs.yaml');
foreach (array_keys($writerCases) as $name) {
    if (isset($CASE_EXEMPT[$name])) { continue; }
    if (!preg_match('/\n  \/api\.php\?action=' . preg_quote($name, '/') . ':\n/', $docs, $dm, PREG_OFFSET_CAPTURE)) {
        chk("api-docs.yaml has a path item for '$name'", false);
        continue;
    }
    $start = $dm[0][1] + strlen($dm[0][0]);
    $next = preg_match('/\n  \//', $docs, $nm, PREG_OFFSET_CAPTURE, $start) ? $nm[0][1] : strlen($docs);
    $blk = substr($docs, $start, $next - $start);
    chk("api-docs.yaml documents account_writes_off_here for '$name'", str_contains($blk, 'AccountWritesOff') || str_contains($blk, 'account_writes_off_here'));
}

exit($fail === 0 ? 0 : 1);
