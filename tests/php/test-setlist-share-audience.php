<?php

/**
 * iHymns — set-list share EDIT-AUDIENCE guards (#1791 G3/G4/G4-org)
 * ============================================================================
 *
 * ELI5: checks that "who can edit an anonymous share link" (anyone vs.
 * sign-in-required) is decided by the ONE central vocabulary + resolver, and
 * that the two endpoints which must honour it (`setlist_get`'s canWrite,
 * `setlist_token_update`'s write gate) actually call it — never a second,
 * independently-typed `['anyone','authenticated']` fork, and never a silent
 * "unknown value = allow anonymous write" fail-open.
 *
 * This suite has three halves, mirroring test-setlist-collab.php's shape:
 *
 *   1. BEHAVIOURAL — the pure vocabulary + normaliser in
 *      includes/setlist_collab.php. `setlistNormaliseEditAudience()` is the
 *      authorisation decision for a STORED value, so its fail-closed boundary
 *      (unknown / empty / wrong-type -> 'authenticated', the MORE restrictive
 *      of the two values) is pinned individually rather than assumed — the
 *      same discipline test-setlist-collab.php already applies to
 *      `setlistCollabNormalisePermission()`.
 *
 *   2. STRUCTURAL (resolver shape) — `setlistResolveEditAudienceDefault()`
 *      exists and its SOURCE references the three layers the plan specifies
 *      (app default via getAppSetting, org layer via tblOrganisationMembers
 *      JOIN tblOrganisations + EnforceSetlistEditAudience, user layer via
 *      tblUsers.Settings) — proving the resolver is actually structured like
 *      serviceMode_resolveIdleTimeoutMins(), not just present under the right
 *      name with a stub body.
 *
 *   3. STRUCTURAL (endpoint wiring) — `setlist_token_update` actually gates
 *      on the normalised audience before it reaches the write core, refusing
 *      with a distinct 401 + a machine-readable `signin_required` reason
 *      (rule #35: status is the contract, not prose); `setlist_get` emits
 *      `sharedByName` ONLY inside the `_showSharerName` conditional, never
 *      unconditionally (the G3 privacy invariant). A source-grep is a weak
 *      proof but it is the strongest one available with no MySQL, and it
 *      catches the specific regression of someone deleting the call while
 *      leaving the helper function itself intact.
 *
 * Every assertion here was proven able to FAIL (rule #34): each was run once
 * against a deliberately broken copy of the guarded code and confirmed RED,
 * then the working tree was restored via `git checkout --`. See the commit
 * message for the per-assertion mutation log.
 *
 *   php tests/php/test-setlist-share-audience.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/setlist_collab.php';

$root       = dirname(__DIR__, 2);
$apiFile    = $root . '/appWeb/public_html/api.php';
$collabFile = $root . '/appWeb/public_html/includes/setlist_collab.php';

foreach ([$apiFile, $collabFile] as $f) {
    if (!is_readable($f)) {
        fwrite(STDERR, "FATAL: could not read $f\n");
        exit(1);
    }
}
$apiSrc    = (string)file_get_contents($apiFile);
$collabSrc = (string)file_get_contents($collabFile);

$failures = 0;
$passed   = 0;

function _saAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/**
 * Extract the body of one `case '<action>':` arm from the api.php dispatcher.
 *
 * Bounded by the NEXT `case '` at the same indentation, which is how every
 * arm in that file is written — the same helper test-setlist-collab.php uses
 * (`_scCaseBody`), copied under a suite-local name rather than shared, since
 * a test-only helper is not the kind of duplication the modularity rule is
 * aimed at (CLAUDE.md's rule targets product code, and every PHP test file in
 * this repo is a standalone script with no shared harness module). Returns
 * '' when the action is absent.
 */
function _saCaseBody(string $src, string $action): string
{
    $needle = "case '" . $action . "':";
    $start  = strpos($src, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($src, "\n        case '", $start + strlen($needle));
    return $next === false
        ? substr($src, $start)
        : substr($src, $start, $next - $start);
}

/* ====================================================================== *
 * 1. BEHAVIOURAL — the vocabulary + fail-closed normaliser
 * ====================================================================== */

echo "\n-- SETLIST_EDIT_AUDIENCES --\n";

_saAssert(defined('SETLIST_EDIT_AUDIENCES'), 'SETLIST_EDIT_AUDIENCES is defined');
_saAssert(SETLIST_EDIT_AUDIENCES === ['anyone', 'authenticated'],
    "SETLIST_EDIT_AUDIENCES is ['anyone','authenticated'] in ascending restrictiveness order");

echo "\n-- setlistNormaliseEditAudience --\n";

/* KNOWN values map through unchanged. */
_saAssert(setlistNormaliseEditAudience('anyone') === 'anyone', "'anyone' passes through");
_saAssert(setlistNormaliseEditAudience('authenticated') === 'authenticated', "'authenticated' passes through");

/* Whitespace and case come from hand-edited rows and sloppy clients alike —
   the same tolerance setlistCollabNormalisePermission() already applies. */
_saAssert(setlistNormaliseEditAudience('  anyone  ') === 'anyone', 'trimmed');
_saAssert(setlistNormaliseEditAudience('AUTHENTICATED') === 'authenticated', 'case-folded');
_saAssert(setlistNormaliseEditAudience('Anyone') === 'anyone', 'mixed case folded');

/* THE FAIL-CLOSED BOUNDARY. EditAudience is a VARCHAR (rule #20 — a growable
   vocabulary is never an ENUM), so the column can physically hold anything.
   Every unrecognised STORED value must resolve to 'authenticated' — the MORE
   restrictive of the two — NOT to the column's mint DEFAULT of 'anyone' and
   NOT to a bare "leave it alone". Defaulting an unknown string to the more
   permissive side is exactly how a data-drift typo becomes a silent
   anonymous-write door — the same class of bug setlistCollabNormalisePermission()
   already guards against for view|edit. */
_saAssert(setlistNormaliseEditAudience('bogus') === 'authenticated', "unknown 'bogus' -> 'authenticated' (fail closed)");
_saAssert(setlistNormaliseEditAudience('org-members') === 'authenticated', "a plausible FUTURE value not yet in the map still fails closed");
_saAssert(setlistNormaliseEditAudience('') === 'authenticated', 'empty string -> authenticated');
_saAssert(setlistNormaliseEditAudience('   ') === 'authenticated', 'whitespace-only -> authenticated');
_saAssert(setlistNormaliseEditAudience(null) === 'authenticated', 'null -> authenticated');

/* Substring / prefix attacks: a naive str_contains / str_starts_with check
   would let these through as 'anyone'. */
_saAssert(setlistNormaliseEditAudience('anyone-else') === 'authenticated', "'anyone-else' is NOT 'anyone'");
_saAssert(setlistNormaliseEditAudience('anyoneelse') === 'authenticated', "'anyoneelse' is NOT 'anyone'");

echo "\n-- setlistMoreRestrictiveEditAudience --\n";

_saAssert(setlistMoreRestrictiveEditAudience('anyone', 'authenticated') === 'authenticated',
    "'authenticated' beats 'anyone' (order 1)");
_saAssert(setlistMoreRestrictiveEditAudience('authenticated', 'anyone') === 'authenticated',
    "'authenticated' beats 'anyone' (order 2, commutative)");
_saAssert(setlistMoreRestrictiveEditAudience('anyone', 'anyone') === 'anyone',
    'two equal values return that value');
_saAssert(setlistMoreRestrictiveEditAudience('authenticated', 'authenticated') === 'authenticated',
    'two equal (restrictive) values return that value');

/* ====================================================================== *
 * 2. STRUCTURAL — setlistResolveEditAudienceDefault() is shaped like the
 *    #1770 idle-timeout resolver (app -> org -> user, most-restrictive-wins,
 *    an exposed $enforcedOut), not merely present under the right name.
 * ====================================================================== */

echo "\n-- setlistResolveEditAudienceDefault --\n";

_saAssert(function_exists('setlistResolveEditAudienceDefault'),
    'setlistResolveEditAudienceDefault() is defined');
_saAssert(function_exists('setlistOrgAudienceColumnsExist'),
    'setlistOrgAudienceColumnsExist() (the org-layer column gate) is defined');

$resolverBody = (static function (string $src): string {
    $start = strpos($src, 'function setlistResolveEditAudienceDefault(');
    if ($start === false) {
        return '';
    }
    /* Bounded at the next top-level `function ` declaration — this file has
       no nested function definitions, so the first one after $start ends the
       body. */
    $next = strpos($src, "\nfunction ", $start + 10);
    return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
})($collabSrc);

_saAssert($resolverBody !== '', 'the resolver body was extracted from includes/setlist_collab.php');

/* Layer 1 — app default. */
_saAssert(str_contains($resolverBody, 'getAppSetting('),
    'resolver reads the APP-LEVEL default via getAppSetting()');
_saAssert(str_contains($resolverBody, 'SETLIST_EDIT_AUDIENCE_APP_SETTING_KEY'),
    'resolver uses the named app-setting key constant (not a bare string literal)');

/* Layer 2 — org layer, most-restrictive-wins, split enforced vs advisory. */
_saAssert(str_contains($resolverBody, 'tblOrganisationMembers'),
    'resolver joins tblOrganisationMembers for the ORG layer');
_saAssert(str_contains($resolverBody, 'tblOrganisations'),
    'resolver joins tblOrganisations for the ORG layer');
_saAssert(str_contains($resolverBody, 'EnforceSetlistEditAudience'),
    'resolver reads EnforceSetlistEditAudience (the G4-org mandate flag)');
_saAssert(str_contains($resolverBody, 'SetlistEditAudience'),
    'resolver reads SetlistEditAudience (the G4-org preference column)');
_saAssert(str_contains($resolverBody, 'setlistOrgAudienceColumnsExist('),
    'the org layer is column-existence-gated (dormant-safe on an un-migrated tblOrganisations)');
_saAssert(str_contains($resolverBody, 'catch (\Throwable'),
    'the org layer fails OPEN on a query error (never breaks minting)');

/* Layer 3 — user layer, tblUsers.Settings JSON. */
_saAssert(str_contains($resolverBody, 'tblUsers'),
    'resolver reads the USER layer from tblUsers');
_saAssert(str_contains($resolverBody, 'Settings'),
    'resolver reads the USER layer from the Settings JSON column');
_saAssert(str_contains($resolverBody, 'SETLIST_EDIT_AUDIENCE_USER_SETTING_KEY'),
    'resolver uses the named user-setting JSON key constant (not a bare string literal)');

/* The exposed enforced-floor OUT param (the #1798 $enforcedMinsOut precedent),
   so a mint caller can clamp a CLIENT-CHOSEN value independently of which
   layer produced the pre-selected default. */
_saAssert(str_contains($resolverBody, '&$enforcedOut'),
    'resolver exposes the enforced org mandate via a by-ref $enforcedOut parameter');

/* ====================================================================== *
 * 3. STRUCTURAL — the two endpoints actually consult the audience, and
 *    setlist_get's byline stays privacy-gated.
 * ====================================================================== */

echo "\n-- setlist_token_update gates on the audience --\n";

$tokUpdate = _saCaseBody($apiSrc, 'setlist_token_update');
_saAssert($tokUpdate !== '', "api.php still has the `setlist_token_update` arm");
_saAssert(str_contains($tokUpdate, 'setlistNormaliseEditAudience('),
    'setlist_token_update normalises the token EditAudience through the shared fold');
_saAssert(str_contains($tokUpdate, "'authenticated'"),
    "setlist_token_update compares against the literal 'authenticated'");
_saAssert(str_contains($tokUpdate, 'signin_required'),
    "setlist_token_update can respond with reason 'signin_required'");
_saAssert(str_contains($tokUpdate, '401'),
    'the signin_required refusal uses a DISTINCT 401 status (rule #35, never a generic 403/500)');

/* The audience check and its 401/signin_required response must sit CLOSE
   together — proves it is one guarded branch, not two unrelated mentions of
   the same words scattered across the 130-line handler. Window taken from
   the actual source gap (~250 chars) with headroom (rule #34: an
   under-sized window is a confident false green). */
$audienceIdx = strpos($tokUpdate, 'setlistNormaliseEditAudience(');
$reasonIdx   = strpos($tokUpdate, 'signin_required', $audienceIdx === false ? 0 : $audienceIdx);
_saAssert(
    $audienceIdx !== false && $reasonIdx !== false && ($reasonIdx - $audienceIdx) < 400,
    'the signin_required reason sits inside the SAME branch as the audience check (< 400 chars apart)'
);

/* Gate ORDER: the audience check must run BEFORE the write core is reached —
   a refusal must happen before any data is touched, not as an afterthought
   that races the write. */
$writeCoreIdx = strpos($tokUpdate, 'setlistCollabPerformUpdate(');
_saAssert(
    $audienceIdx !== false && $writeCoreIdx !== false && $audienceIdx < $writeCoreIdx,
    'the audience gate runs BEFORE setlistCollabPerformUpdate() (refuse before writing)'
);

/* 'anyone' must still proceed anonymously — the gate must be an IF, not an
   unconditional refusal that would break every existing anonymous edit. */
_saAssert(str_contains($tokUpdate, 'getAuthenticatedUser()'),
    'setlist_token_update actually checks for a signed-in requester (not a hardcoded refusal)');

echo "\n-- setlist_get's sharedByName stays privacy-gated --\n";

$getBody = _saCaseBody($apiSrc, 'setlist_get');
_saAssert($getBody !== '', "api.php still has the `setlist_get` arm");
_saAssert(str_contains($getBody, 'sharedByName'),
    'setlist_get can emit sharedByName');
_saAssert(str_contains($getBody, '_showSharerName'),
    'setlist_get consults the private _showSharerName flag before emitting it');
_saAssert(str_contains($getBody, 'lockReason'),
    'setlist_get can emit lockReason (explains a false canWrite)');
_saAssert(str_contains($getBody, "'signin_required'"),
    "setlist_get's lockReason vocabulary includes 'signin_required'");

/* THE PRIVACY INVARIANT (G3): the sharedByName ASSIGNMENT must sit inside the
   _showSharerName CONDITIONAL, not merely somewhere in the same case body —
   proven by requiring the guard to appear BEFORE the assignment and within a
   tight window (measured gap ~530 chars; headroom to ~900 per rule #34). A
   wider gap would let a "mention it in a comment, assign it unconditionally
   two screens later" regression pass silently — exactly the #1535 owner-id
   leak class this endpoint's whole allow-list discipline exists to prevent. */
$guardIdx      = strpos($getBody, '_showSharerName');
$sharedNameIdx = strpos($getBody, "'sharedByName'", $guardIdx === false ? 0 : $guardIdx);
_saAssert(
    $guardIdx !== false && $sharedNameIdx !== false && ($sharedNameIdx - $guardIdx) < 900,
    'the sharedByName assignment sits inside the _showSharerName-guarded branch (< 900 chars apart)'
);

/* And the owner id/email must still never be echoed — the pre-existing
   #1535/#1380 invariant this feature must not weaken. A crude but effective
   check: the response-building block never assigns 'ownerId' / '_ownerUserId'
   / 'email' as an OUTPUT key. */
_saAssert(!preg_match('/\'(ownerId|email)\'\s*=>/', $getBody),
    "setlist_get's response never assigns an 'ownerId'/'email' key (privacy invariant holds)");

/* ====================================================================== *
 * Summary
 * ====================================================================== */

echo "\n" . str_repeat('=', 60) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failures\n";
exit($failures > 0 ? 1 : 0);
