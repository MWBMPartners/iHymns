<?php

/**
 * iHymns — setlist collaboration access + structure guards (#1638)
 * ============================================================================
 *
 * Setlist collaboration shipped WRITE-ONLY. An owner could invite somebody by
 * email and pick "view" or "edit"; the invitee experienced nothing at all — no
 * notification, no listing, no way to see the setlist — and
 * `tblSetlistCollaborators.Permission` was never once read for enforcement, so
 * the view/edit choice was decorative. #1638 finished the feature.
 *
 * This suite has three halves:
 *
 *   1. BEHAVIOURAL — the pure permission helpers in
 *      includes/setlist_collab.php. These are the authorisation decision, so
 *      the fail-closed boundaries (unknown value, absent value, wrong type)
 *      are pinned individually rather than assumed.
 *
 *   2. STRUCTURAL (removal) — the three legacy handlers are GONE from both
 *      api.php and api-docs.yaml. They were unfixably broken: they referenced
 *      tblSetlistCollaborators.UserId / .CreatedAt (columns that have never
 *      existed) and their INSERT omitted the NOT NULL SetlistOwnerId, so under
 *      mysqli's STRICT reporting every call threw a 500. A future session
 *      "restoring" them from the docs would reintroduce three dead endpoints.
 *
 *   3. STRUCTURAL (wiring) — the invite handler actually calls the shared
 *      notification helper, and the collaborative write path actually consults
 *      the permission resolver. Both are the #1626 / #1638 failure class:
 *      success feedback over a half that does not exist. A source-grep is a
 *      weak proof but it is the strongest one available with no MySQL, and it
 *      catches the specific regression of someone deleting the call.
 *
 *   php tests/php/test-setlist-collab.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/setlist_collab.php';

$root       = dirname(__DIR__, 2);
$apiFile    = $root . '/appWeb/public_html/api.php';
$docsFile   = $root . '/appWeb/public_html/api-docs.yaml';
$notifFile  = $root . '/appWeb/public_html/includes/notifications.php';

foreach ([$apiFile, $docsFile, $notifFile] as $f) {
    if (!is_readable($f)) {
        fwrite(STDERR, "FATAL: could not read $f\n");
        exit(1);
    }
}
$collabFile = $root . '/appWeb/public_html/includes/setlist_collab.php';
if (!is_readable($collabFile)) { fwrite(STDERR, "FATAL: could not read $collabFile\n"); exit(1); }
$apiSrc    = (string)file_get_contents($apiFile);
$docsSrc   = (string)file_get_contents($docsFile);
$notifSrc  = (string)file_get_contents($notifFile);
/* #1791 — the collaborative write SQL moved OUT of the api.php case body into
   the shared core setlistCollabPerformUpdate() (so setlist_token_update reuses
   the exact same write). The structural safety invariant below now lives here. */
$collabSrc = (string)file_get_contents($collabFile);

$failures = 0;
$passed   = 0;

function _scAssert(bool $cond, string $label): void
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
 * Bounded by the NEXT `case '` at the same indentation, which is how every arm
 * in that file is written. Returns '' when the action is absent — which the
 * removal assertions rely on.
 */
function _scCaseBody(string $src, string $action): string
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
 * 1. setlistCollabNormalisePermission() — the canonical fold
 * ====================================================================== */

echo "\n-- setlistCollabNormalisePermission --\n";

_scAssert(setlistCollabNormalisePermission('view') === 'view', "'view' is accepted");
_scAssert(setlistCollabNormalisePermission('edit') === 'edit', "'edit' is accepted");

/* Whitespace and case come from hand-edited rows and sloppy clients alike. */
_scAssert(setlistCollabNormalisePermission('  EDIT  ') === 'edit', 'trimmed + lowercased');
_scAssert(setlistCollabNormalisePermission('View') === 'view', 'mixed case folded');

/* THE FAIL-CLOSED BOUNDARY. Permission is a VARCHAR (rule #20 — a growable
   vocabulary is never an ENUM), so the column can physically hold anything.
   Every unrecognised value must resolve to null, NOT to the column's DEFAULT
   of 'edit' and NOT to a lenient 'view'. */
_scAssert(setlistCollabNormalisePermission('admin')  === null, "unknown 'admin' → null");
_scAssert(setlistCollabNormalisePermission('owner')  === null, "unknown 'owner' → null");
_scAssert(setlistCollabNormalisePermission('')       === null, 'empty string → null');
_scAssert(setlistCollabNormalisePermission('   ')    === null, 'whitespace only → null');
_scAssert(setlistCollabNormalisePermission(null)     === null, 'null → null');
_scAssert(setlistCollabNormalisePermission(1)        === null, 'int → null (no numeric coercion)');
_scAssert(setlistCollabNormalisePermission(true)     === null, 'bool → null');
_scAssert(setlistCollabNormalisePermission(['edit']) === null, 'array → null');
/* Substring and prefix attacks: a naive str_contains / str_starts_with check
   would let these through. */
_scAssert(setlistCollabNormalisePermission('editor')     === null, "'editor' is NOT 'edit'");
_scAssert(setlistCollabNormalisePermission('edit-all')   === null, "'edit-all' is NOT 'edit'");
_scAssert(setlistCollabNormalisePermission('viewedit')   === null, "'viewedit' is NOT a permission");

/* ====================================================================== *
 * 2. setlistCollabCanWrite() — 'edit' can, nothing else can
 * ====================================================================== */

echo "\n-- setlistCollabCanWrite --\n";

_scAssert(setlistCollabCanWrite('edit') === true,  "'edit' CAN write");
_scAssert(setlistCollabCanWrite('EDIT') === true,  "'EDIT' CAN write (case-folded)");

/* The whole point of the issue: a 'view' collaborator must not be able to
   modify the owner's setlist. */
_scAssert(setlistCollabCanWrite('view') === false, "'view' CANNOT write");

/* Unknown / absent fails CLOSED. Written as an explicit === 'edit' precisely
   so `!== 'view'` cannot creep back in and grant write to everything odd. */
_scAssert(setlistCollabCanWrite('')       === false, 'empty → cannot write');
_scAssert(setlistCollabCanWrite(null)     === false, 'null → cannot write');
_scAssert(setlistCollabCanWrite('admin')  === false, 'unknown → cannot write');
_scAssert(setlistCollabCanWrite('editor') === false, "'editor' → cannot write");
_scAssert(setlistCollabCanWrite(0)        === false, 'int 0 → cannot write');

echo "\n-- setlistCollabCanRead --\n";

_scAssert(setlistCollabCanRead('view') === true,  "'view' CAN read");
_scAssert(setlistCollabCanRead('edit') === true,  "'edit' CAN read");
_scAssert(setlistCollabCanRead('')      === false, 'empty → cannot read');
_scAssert(setlistCollabCanRead(null)    === false, 'null → cannot read');
_scAssert(setlistCollabCanRead('admin') === false, 'unknown → cannot read (fully closed)');

/* ====================================================================== *
 * 3. setlistCollabSanitiseSongs() — one shape for one column
 * ====================================================================== */

echo "\n-- setlistCollabSanitiseSongs --\n";

_scAssert(setlistCollabSanitiseSongs(null) === [], 'non-array → []');
_scAssert(setlistCollabSanitiseSongs('nope') === [], 'string → []');

$out = setlistCollabSanitiseSongs([
    ['id' => 'MP-1', 'title' => 'A Song', 'songbook' => 'MP', 'number' => 7],
    ['title' => 'no id'],           // dropped: no id
    'not an array',                 // dropped: wrong type
    ['id' => 'MP-2'],               // kept, defaults filled
]);
_scAssert(count($out) === 2, 'entries without an id are dropped');
_scAssert($out[0] === ['id' => 'MP-1', 'title' => 'A Song', 'songbook' => 'MP', 'number' => 7],
    'a full entry round-trips unchanged');
_scAssert($out[1] === ['id' => 'MP-2', 'title' => '', 'songbook' => '', 'number' => 0],
    'missing fields default rather than throwing');

/* Unknown keys must not survive: the collaborator write path shares this
   sanitiser with user_setlists_sync, so anything it lets through is stored in
   the owner's row. */
$extra = setlistCollabSanitiseSongs([[
    'id' => 'MP-3', 'title' => 'X', 'songbook' => 'MP', 'number' => 1,
    'arrangementLabel' => 'stale label', 'evil' => '<script>',
]]);
_scAssert(!array_key_exists('evil', $extra[0]), 'unknown keys are discarded');
_scAssert(!array_key_exists('arrangementLabel', $extra[0]),
    'client-only arrangementLabel is NOT persisted (it is recomputed on render)');

$arr = setlistCollabSanitiseSongs([[
    'id' => 'MP-4', 'arrangement' => [0, 2, -1, 'x', 3.5, 5],
]]);
_scAssert($arr[0]['arrangement'] === [0, 2, 5],
    'arrangement keeps only non-negative ints (negatives/strings/floats dropped)');

$capped = setlistCollabSanitiseSongs(
    array_map(static fn(int $i): array => ['id' => 'MP-' . $i], range(1, 250))
);
_scAssert(count($capped) === 200, 'entries are capped at 200 (matches the sync cap)');

/* ====================================================================== *
 * 4. STRUCTURAL — the legacy trio is GONE
 * ====================================================================== */

echo "\n-- legacy handlers removed from api.php --\n";

foreach (['setlist_collaborators', 'setlist_collaborator_add', 'setlist_collaborator_remove'] as $legacy) {
    _scAssert(
        !str_contains($apiSrc, "case '" . $legacy . "':"),
        "api.php has NO `case '$legacy':` arm"
    );
}

/* The specific broken SQL, pinned so a copy-paste revival is caught even if
   the case label is renamed. Neither column has ever existed on the table. */
_scAssert(!str_contains($apiSrc, 'u.Id = c.UserId'),
    'api.php no longer JOINs tblSetlistCollaborators.UserId (column never existed)');
_scAssert(!str_contains($apiSrc, 'ORDER BY c.CreatedAt'),
    'api.php no longer ORDERs BY tblSetlistCollaborators.CreatedAt (column never existed)');
_scAssert(!str_contains($apiSrc, 'INSERT INTO tblSetlistCollaborators (SetlistId, UserId, Permission)'),
    'api.php no longer has the INSERT that omitted the NOT NULL SetlistOwnerId');

echo "\n-- legacy handlers removed from api-docs.yaml --\n";

foreach (['setlist_collaborators', 'setlist_collaborator_add', 'setlist_collaborator_remove'] as $legacy) {
    _scAssert(
        !str_contains($docsSrc, 'action=' . $legacy),
        "api-docs.yaml no longer documents `$legacy`"
    );
}
foreach (['setlistCollaboratorsLegacy', 'setlistCollaboratorAddLegacy', 'setlistCollaboratorRemoveLegacy'] as $opId) {
    _scAssert(!str_contains($docsSrc, $opId), "api-docs.yaml has no `$opId` operationId");
}

/* The live family must still be documented — removing the legacy block must
   not have taken the working endpoints with it. */
foreach (['setlist_collab_invite', 'setlist_collab_list', 'setlist_collab_remove',
          'setlist_collab_shared_with_me', 'setlist_collab_update'] as $live) {
    _scAssert(str_contains($docsSrc, 'action=' . $live), "api-docs.yaml still documents `$live`");
}

/* ====================================================================== *
 * 5. STRUCTURAL — the notification helper exists and is wired in
 * ====================================================================== */

echo "\n-- shared notification helper --\n";

_scAssert(function_exists('notificationsHasScopeColumns') || str_contains($notifSrc, 'function notificationsHasScopeColumns'),
    'includes/notifications.php defines notificationsHasScopeColumns()');
_scAssert(str_contains($notifSrc, 'function notifyUser'),
    'includes/notifications.php defines notifyUser()');

/* Best-effort is the contract, not an accident: a notification failure must
   never fail the operation that triggered it. */
_scAssert(str_contains($notifSrc, 'catch (\Throwable $e)') || str_contains($notifSrc, 'catch (\Throwable'),
    'notifyUser() swallows Throwable (best-effort contract)');

/* Every value bound, none interpolated (CLAUDE.md rule #5). */
_scAssert(!preg_match('/INSERT INTO tblNotifications[^;]*\$\w+\s*\./s', $notifSrc),
    'notifications.php never string-interpolates into the INSERT');
/* Counts the CALLS (`$stmt->bind_param(`), not every mention of the word —
   the file's own doc-block talks about bind_param in prose. Exactly two: one
   for the #1238-scoped 7-column INSERT, one for the 5-column fallback. */
_scAssert(substr_count($notifSrc, '$stmt->bind_param(') === 2,
    'notifications.php binds via bind_param on both the scoped and unscoped INSERT');

_scAssert(str_contains($apiSrc, "'includes' . DIRECTORY_SEPARATOR . 'notifications.php'"),
    'api.php requires includes/notifications.php');
_scAssert(str_contains($apiSrc, "'includes' . DIRECTORY_SEPARATOR . 'setlist_collab.php'"),
    'api.php requires includes/setlist_collab.php');

echo "\n-- invite notifies the invitee --\n";

$invite = _scCaseBody($apiSrc, 'setlist_collab_invite');
_scAssert($invite !== '', "api.php still has the `setlist_collab_invite` arm");
_scAssert(str_contains($invite, 'notifyUser('),
    'setlist_collab_invite calls notifyUser() — the invitee is actually told');
_scAssert(str_contains($invite, "'setlist_collab_invite'"),
    'the notification carries a machine-readable Type');
_scAssert(str_contains($invite, "'/setlist?shared='"),
    'the notification carries an ActionUrl deep-linking to the shared setlist');
/* The notification must come AFTER the collaborator row is committed — a
   notification about an invite that then failed to save is a lie. */
_scAssert(
    strpos($invite, 'INSERT INTO tblSetlistCollaborators') < strpos($invite, 'notifyUser('),
    'the notification is written AFTER the collaborator row is inserted'
);

/* ====================================================================== *
 * 6. STRUCTURAL — the write path enforces Permission
 * ====================================================================== */

echo "\n-- collaborative write path --\n";

$update = _scCaseBody($apiSrc, 'setlist_collab_update');
_scAssert($update !== '', "api.php has a `setlist_collab_update` arm");
_scAssert(str_contains($update, 'setlistCollabResolveAccess('),
    'setlist_collab_update resolves access through the shared helper');
_scAssert(str_contains($update, "canWrite"),
    'setlist_collab_update gates on canWrite');
_scAssert(str_contains($update, '403'),
    'a caller without edit access gets 403');
_scAssert(str_contains($update, 'setlistCollabSanitiseSongs('),
    'setlist_collab_update reuses the shared song sanitiser');
/* #1791 — the write itself moved to the shared core so setlist_token_update
   reuses the EXACT same UPDATE. The case must DELEGATE to it, not re-inline
   the SQL. */
_scAssert(str_contains($update, 'setlistCollabPerformUpdate('),
    'setlist_collab_update writes through the shared setlistCollabPerformUpdate() core');

/* THE SYNC HAZARD. The collaborative write must be an UPDATE of one existing
   row — never a DELETE, never an INSERT into the owner's namespace. A DELETE
   here would let a collaborator (or a token-edit holder, #1791) destroy the
   owner's setlists, which is the failure mode that turns sharing into data
   loss. The SQL now lives in setlistCollabPerformUpdate(), so the invariant is
   checked THERE — in the core both write paths funnel through. */
_scAssert(str_contains($collabSrc, 'function setlistCollabPerformUpdate('),
    'the shared write core setlistCollabPerformUpdate() exists');
_scAssert(str_contains($collabSrc, 'UPDATE tblUserSetlists'),
    'the write core writes via UPDATE');
_scAssert(!str_contains($collabSrc, 'DELETE FROM tblUserSetlists'),
    'the write core NEVER deletes from tblUserSetlists');
_scAssert(!str_contains($collabSrc, 'INSERT INTO tblUserSetlists'),
    'the write core NEVER inserts into tblUserSetlists');

/* And the owner-scoped bulk sync must stay owner-scoped: no collaborator
   table may be consulted inside it, or a collaborator's replace-mode payload
   could reach the owner's rows. */
$sync = _scCaseBody($apiSrc, 'user_setlists_sync');
_scAssert($sync !== '', 'api.php still has the `user_setlists_sync` arm');
_scAssert(!str_contains($sync, 'tblSetlistCollaborators'),
    'user_setlists_sync never consults tblSetlistCollaborators (stays owner-scoped)');
_scAssert(str_contains($sync, 'setlistCollabSanitiseSongs('),
    'user_setlists_sync uses the SAME song sanitiser as the collaborative write');

echo "\n-- shared_with_me is a usable read half --\n";

$sharedWithMe = _scCaseBody($apiSrc, 'setlist_collab_shared_with_me');
_scAssert($sharedWithMe !== '', "api.php still has the `setlist_collab_shared_with_me` arm");
_scAssert(str_contains($sharedWithMe, 'SongsJson'),
    'shared_with_me returns the songs, so a client can actually render the list');
_scAssert(str_contains($sharedWithMe, 'setlistCollabNormalisePermission('),
    'shared_with_me folds Permission through the shared normaliser (fails closed)');

/* ====================================================================== *
 * Summary
 * ====================================================================== */

echo "\n" . str_repeat('=', 60) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failures\n";
exit($failures > 0 ? 1 : 0);
