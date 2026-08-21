<?php

declare(strict_types=1);

/**
 * setlist_collab.php — setlist collaboration access resolution (#1638)
 * ============================================================================
 *
 * ELI5: works out whether the person asking is allowed to look at, or change,
 * somebody else's set list — and says "no" whenever it isn't sure.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------------
 *
 * Setlist collaboration (#312 / #398) shipped write-only. An owner could
 * invite somebody by email and pick "view" or "edit"; the invitee experienced
 * nothing at all, and `tblSetlistCollaborators.Permission` was never once read
 * for enforcement. The view/edit choice was decorative — which is worse than
 * absent, because the owner was shown a control that made a promise the server
 * did not keep.
 *
 * #1638 finishes the feature. The moment a collaborator can actually reach
 * somebody else's data, "who may do what" stops being a UI detail and becomes
 * an authorisation decision, so it gets exactly one implementation, here, that
 * every endpoint asks. A second copy inside an api.php case body is the
 * regression this file exists to prevent (CLAUDE.md modularity rule; the same
 * argument as includes/song_similarity.php and includes/user_sync.php).
 *
 * ---------------------------------------------------------------------------
 * FAIL CLOSED — THE ONE RULE
 * ---------------------------------------------------------------------------
 *
 * ELI5: if the answer isn't a clear yes, it's a no.
 *
 * `Permission` is a VARCHAR, not an ENUM (CLAUDE.md rule #20 — a growable
 * vocabulary must never be an ENUM). That is the right storage choice, but it
 * means the column can physically hold anything: a value written by a future
 * feature, a hand-edited row, a typo in a migration. Every helper here treats
 * an unrecognised value as NO ACCESS AT ALL — not as "view", and certainly not
 * as the column's DEFAULT of 'edit'. Defaulting an unknown string to the more
 * permissive side is how a typo becomes a write grant.
 *
 * ---------------------------------------------------------------------------
 * THE SYNC BOUNDARY (the hazard this design is shaped around)
 * ---------------------------------------------------------------------------
 *
 * ELI5: a set list somebody shared with you is NOT one of your set lists, and
 * your app must never tell the server otherwise.
 *
 * `user_setlists_sync` is owner-scoped and, in 'replace' mode, DELETES server
 * rows absent from the payload (#1018, guarded #1649). If a shared setlist
 * were merged into the collaborator's own local collection it would be posted
 * back as if it were theirs, and the collaborator's `tblUserSetlists` would
 * grow a *second* row with the same SetlistId under a different UserId — a
 * silent fork that also makes the collaborator pass the owner-check in
 * `setlist_collab_invite`, letting them re-share a list that isn't theirs.
 *
 * The boundary, enforced on BOTH sides:
 *
 *   SERVER — `user_setlists_sync` reads and writes `WHERE UserId = <caller>`
 *            only, and is never given a collaborator branch. A collaborator's
 *            sync therefore cannot touch, and above all cannot DELETE, a row
 *            belonging to the owner. Collaborative writes go exclusively
 *            through `setlist_collab_update`, which is an UPDATE of one
 *            already-existing (owner, setlistId) row — it can neither INSERT
 *            a row into the owner's namespace nor DELETE one.
 *
 *   CLIENT — shared setlists are never written to localStorage['ihymns_setlists'],
 *            the array that feeds the sync payload. They are fetched live and
 *            held in memory only, so there is nothing for a sync to sweep up.
 *
 * @package iHymns
 */

/* The account-lifecycle state helpers (#1698). Required at FILE scope, not
   inside the one function that needs a database, so that the PURE
   `setlistCollabApplyOwnerLock()` below can be called on its own — by a test, or
   by any future caller that already knows the owner's state. A `require_once`
   buried in a DB-touching branch would make the pure function fatal in exactly
   the context it exists to serve. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_status.php';

/**
 * The complete permission vocabulary. Anything outside this list is refused.
 *
 * Kept as one constant rather than an inline `in_array(..., ['view','edit'])`
 * per call site so a future third level (say 'comment') is a one-line change
 * that every gate picks up, instead of a grep-and-hope across api.php.
 */
const SETLIST_COLLAB_PERMISSIONS = ['view', 'edit'];

/**
 * Normalise a raw permission value to the canonical vocabulary, or null.
 *
 * ELI5: turns "  EDIT " into "edit", and turns anything we don't recognise
 * into "no idea" rather than guessing.
 *
 * Accepts mixed because it is fed both a JSON request field (could be an int,
 * an array, null) and a DB column read. A non-string is not "probably fine" —
 * it is a malformed request, so it resolves to null like any other unknown.
 *
 * @param  mixed $raw Untrusted value from a request body or a DB row.
 * @return string|null 'view' | 'edit', or null when unrecognised.
 */
function setlistCollabNormalisePermission($raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    /* mb_strtolower, not strtolower: a Turkish-locale 'İ' folded by the
       byte-wise version would not compare equal to 'i'. Irrelevant for the two
       current ASCII values, but free, and correct if the vocabulary grows. */
    $p = mb_strtolower(trim($raw));
    return in_array($p, SETLIST_COLLAB_PERMISSIONS, true) ? $p : null;
}

/**
 * May a holder of this permission READ the setlist?
 *
 * ELI5: both "view" and "edit" can look; nothing else can.
 *
 * @param  mixed $raw Raw permission value.
 * @return bool
 */
function setlistCollabCanRead($raw): bool
{
    return setlistCollabNormalisePermission($raw) !== null;
}

/**
 * May a holder of this permission MODIFY the setlist?
 *
 * ELI5: only "edit" can change anything.
 *
 * Written as an explicit `=== 'edit'` against the NORMALISED value rather than
 * `!== 'view'`. The negative form silently grants write to every unknown
 * string — an empty column, a NULL cast to '', a future 'comment' level — and
 * that is precisely the fail-open bug this whole file is built to avoid.
 *
 * @param  mixed $raw Raw permission value.
 * @return bool
 */
function setlistCollabCanWrite($raw): bool
{
    return setlistCollabNormalisePermission($raw) === 'edit';
}

/**
 * Resolve what a viewer may do with a given setlist.
 *
 * ELI5: "is this my set list, was it shared with me, or is it none of my
 * business?" — answered by the database, not by the client.
 *
 * Resolution order, deliberately owner-first: a user who owns the setlist gets
 * full access without a collaborator row ever being consulted, so an owner can
 * never be locked out of their own data by a stray/corrupt collaborator row.
 *
 * `$ownerId` is a HINT the client supplies so the collaborator lookup can hit
 * the `uq_Collab (SetlistOwnerId, SetlistId, CollaboratorId)` unique key
 * directly. It is never trusted: the returned ownerId is whatever the matched
 * DB row says. When it is omitted the collaborator lookup still works, it just
 * has to scan by (SetlistId, CollaboratorId) — and it deliberately returns
 * NOTHING when that is ambiguous (see below).
 *
 * AMBIGUITY IS A REFUSAL. `tblUserSetlists.SetlistId` is client-generated and
 * unique only per user, so two different owners can legitimately hold the same
 * SetlistId string. Without an ownerId hint, a viewer invited to one of them
 * could otherwise be handed the other. If the hint is absent and more than one
 * collaborator row matches, this returns 'none' rather than picking one.
 *
 * @param \mysqli  $db        Live connection from getDbMysqli().
 * @param int      $viewerId  Authenticated tblUsers.Id of the caller.
 * @param string   $setlistId tblUserSetlists.SetlistId.
 * @param int|null $ownerId   Claimed owner id, or null to resolve unambiguously.
 * @return array{role:string,ownerId:?int,permission:?string,canRead:bool,canWrite:bool}
 *         role is 'owner' | 'collaborator' | 'none'.
 */
function setlistCollabResolveAccess(
    \mysqli $db,
    int $viewerId,
    string $setlistId,
    ?int $ownerId = null
): array {
    /* The refusal every failure path returns. Declared once so a new early
       return cannot accidentally omit a field and leave canWrite undefined —
       an undefined array key read as a bool is falsey, but relying on that is
       how a gate quietly stops being a gate. */
    $denied = [
        'role'       => 'none',
        'ownerId'    => null,
        'permission' => null,
        'canRead'    => false,
        'canWrite'   => false,
    ];

    if ($viewerId <= 0 || $setlistId === '') {
        return $denied;
    }

    /* --- 1. Owner? Only asked when the caller could BE the owner. --------- */
    if ($ownerId === null || $ownerId === $viewerId) {
        $stmt = $db->prepare(
            'SELECT 1 FROM tblUserSetlists WHERE UserId = ? AND SetlistId = ? LIMIT 1'
        );
        $stmt->bind_param('is', $viewerId, $setlistId);
        $stmt->execute();
        $isOwner = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if ($isOwner) {
            return [
                'role'       => 'owner',
                'ownerId'    => $viewerId,
                'permission' => 'edit',   // an owner is implicitly full-access
                'canRead'    => true,
                'canWrite'   => true,
            ];
        }
        /* An explicit `ownerId === viewerId` claim that did NOT match a row is
           a dead end: they are asserting they own it, and they don't. Falling
           through to the collaborator lookup would be asking whether they
           collaborate on their own setlist, which is meaningless. */
        if ($ownerId === $viewerId) {
            return $denied;
        }
    }

    /* --- 2. Collaborator? ------------------------------------------------- */
    if ($ownerId !== null) {
        $stmt = $db->prepare(
            'SELECT SetlistOwnerId, Permission
               FROM tblSetlistCollaborators
              WHERE SetlistOwnerId = ? AND SetlistId = ? AND CollaboratorId = ?
              LIMIT 1'
        );
        $stmt->bind_param('isi', $ownerId, $setlistId, $viewerId);
    } else {
        /* No hint — fetch up to two so a duplicate SetlistId across owners is
           DETECTED rather than silently resolved to the first row MySQL feels
           like returning. Same class of bug as #1635's email resolver. */
        $stmt = $db->prepare(
            'SELECT SetlistOwnerId, Permission
               FROM tblSetlistCollaborators
              WHERE SetlistId = ? AND CollaboratorId = ?
              LIMIT 2'
        );
        $stmt->bind_param('si', $setlistId, $viewerId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($rows) !== 1) {
        return $denied;   // 0 = not invited; 2 = ambiguous, refuse either way
    }

    $permission = setlistCollabNormalisePermission($rows[0]['Permission'] ?? null);
    if ($permission === null) {
        /* Row exists but the level is unreadable. Fail closed COMPLETELY —
           not "downgrade to view". We do not know what was intended, and
           inventing an intent is how a gate becomes a guess. */
        error_log('[setlistCollabResolveAccess] unrecognised Permission "'
            . (string)($rows[0]['Permission'] ?? '') . '" for setlist ' . $setlistId);
        return $denied;
    }

    $resolvedOwnerId = (int)$rows[0]['SetlistOwnerId'];

    $access = [
        'role'       => 'collaborator',
        'ownerId'    => $resolvedOwnerId,
        'permission' => $permission,
        'canRead'    => true,
        'canWrite'   => ($permission === 'edit'),
    ];

    /* --- 3. THE OWNER-STATE LOCK (#1698). --------------------------------
     * ELI5: if the person who owns this set list has closed or lost their
     * account, you can still see it — you just cannot change it any more.
     *
     * This is the ONE derive-at-read gate the account-lifecycle work needed.
     * Every OTHER path a disabled or deleted user's data is reachable by is
     * already closed, because `getAuthenticatedUser()` filters `IsActive = 1`
     * and thirteen more checks in manage/includes/auth.php do the same — a
     * locked user simply cannot authenticate. The collaborator is the one
     * caller who is a DIFFERENT, perfectly active person writing to a locked
     * owner's row, so it is the one place a new check belongs.
     *
     * Only asked on the COLLABORATOR branch, deliberately: the owner branch
     * above returns for the authenticated caller themselves, who is active by
     * construction, so asking would be one wasted primary-key SELECT per read.
     */
    return setlistCollabApplyOwnerLock($access, userOwnerState($db, $resolvedOwnerId));
}

/**
 * Apply the owner's account state to a resolved access array — PURE.
 *
 * ELI5: "you may still read it, you may no longer write to it" — and say why.
 *
 * VISIBLE BUT LOCKED is the owner's stated rule, and this is where it is
 * written down: `canRead` is untouched, `canWrite` is forced false, and an
 * `ownerState` key is added so the response can tell a client WHY the edit
 * affordance is gone. Removing read access instead would punish the innocent
 * collaborator for somebody else's account closing, which is precisely the
 * outcome the decision rules out.
 *
 * PURE, taking the state as a string rather than looking it up, for the same
 * reason `setlistTemplateCanEdit()` is pure: `tests/php/test-account-lifecycle.php`
 * CALLS it. A gate whose only evidence is that its file contains the right
 * words is a gate this branch has already shipped four times while it was
 * broken (`tests/php/test-transaction-fatal.php` documents the regress).
 *
 * An ACTIVE owner returns the array UNTOUCHED except for the new key — asserted
 * in the test, because "the lock works" and "the lock is always on" produce the
 * same result on the locked cases and only the second is a bug.
 *
 * @param array  $access     Output of setlistCollabResolveAccess()'s resolution.
 * @param string $ownerState 'active' | 'disabled' | 'deleted' | 'absent'.
 * @return array Same shape, plus 'ownerState' and (when locked) 'lockReason'.
 */
function setlistCollabApplyOwnerLock(array $access, string $ownerState): array
{
    $access['ownerState'] = $ownerState;

    if (!userContentLocked($ownerState)) {
        return $access;
    }

    $access['canWrite']   = false;
    $access['lockReason'] = userStateLockReason($ownerState);
    return $access;
}

/**
 * The hard per-set-list songs cap (#1662 reopened).
 *
 * ELI5: the largest number of songs one set list is allowed to hold — go over
 * it and the save is refused outright, nothing is quietly cut off.
 *
 * ONE function, not a magic number copy-pasted at each of the three call
 * sites (`user_setlists_sync`, `setlist_collab_update`, `setlist_token_update`)
 * — CLAUDE.md rule #22 ("one shared helper per decision"). Kept as a function
 * rather than a `const` for the same double-include-safety reason
 * `userSyncMaxBodyBytes()` in user_sync.php is a function: a bare top-level
 * `const` would fatal if this file were ever required twice.
 *
 * VALUE: 200. A real service runs ~15–25 songs, so this is already an order
 * of magnitude of headroom; `tblUserSetlists.SongsJson` is MEDIUMTEXT, so
 * nothing about storage forces this number either way — it is a product
 * choice, not a technical ceiling, which is exactly why it lives in one place
 * an owner can change with a single-line edit rather than a grep-and-hope.
 *
 * @return int Maximum songs one set list may hold.
 * @link https://www.php.net/manual/en/language.oop5.constants.php
 */
function setlistCollabMaxSongs(): int
{
    return 200;
}

/**
 * Sanitise a client-supplied setlist songs array into the stored shape.
 *
 * ELI5: keeps only the four things a set-list entry is allowed to say about a
 * song, and trims them to fit — it does NOT decide how many entries are too
 * many; that is a REJECTION the caller makes before this ever runs (#1662
 * reopened — see setlistCollabMaxSongs() and the 413 guards at every call
 * site).
 *
 * Extracted from `user_setlists_sync` in #1638 because the collaborative write
 * path (`setlist_collab_update`) writes the very same `tblUserSetlists.SongsJson`
 * column. Two sanitisers for one column would drift, and the drift would be
 * invisible until a collaborator's write produced a row the owner's client
 * could not render. Behaviour is unchanged from the inline original.
 *
 * ⚠️ #1662 REOPENED — THIS FUNCTION NO LONGER SLICES. It used to accept a
 * `$max` parameter and silently `array_slice()` down to it — the exact
 * "quietly keep only the first N and say nothing" shape #1661 already
 * removed from the sync handler's deletion logic and #1671 removed from the
 * service-plan cap. A caller that still needs the ORIGINAL "keep only the
 * first 200, no error" behaviour for a legacy (protocol-1, no `deleted` key)
 * client does that itself, explicitly, at the call site — see the
 * `legacy-protocol1-slice` comment in api.php's `user_setlists_sync` — so the
 * decision to ever silently drop the tail is visible where it is made, not
 * buried inside a shared sanitiser every caller trusts blindly. This module
 * "deliberately owns no array_slice()" (the #1671 posture, now extended
 * here): the whole-body 4 MiB ceiling already bounds hostile input before
 * this runs, and every caller is expected to have rejected an over-cap
 * payload with a 413 already (setlistCollabMaxSongs()) before sanitising it.
 *
 * Note what is deliberately DROPPED: `arrangementLabel` is a client-side
 * display convenience recomputed on render (setlist.js), so persisting it
 * would store a stale label forever.
 *
 * @param  mixed $songs Raw `songs` array from a request body (any type).
 * @return array<int,array<string,mixed>> Clean entries, re-indexed.
 */
function setlistCollabSanitiseSongs($songs): array
{
    if (!is_array($songs)) {
        return [];
    }
    $clean = [];
    foreach ($songs as $s) {
        if (!is_array($s) || empty($s['id'])) {
            continue;
        }
        $entry = [
            'id'       => (string)$s['id'],
            'title'    => mb_substr((string)($s['title'] ?? ''), 0, 300),
            'songbook' => mb_substr((string)($s['songbook'] ?? ''), 0, 20),
            'number'   => (int)($s['number'] ?? 0),
        ];
        /* Preserve a custom per-setlist component arrangement (#94): a list of
           non-negative component indexes. Anything else in the array is
           discarded rather than coerced — a string index would break the
           renderer's lookup. */
        if (isset($s['arrangement']) && is_array($s['arrangement'])) {
            $entry['arrangement'] = array_values(array_filter(
                $s['arrangement'],
                static fn($v) => is_int($v) && $v >= 0
            ));
        }
        $clean[] = $entry;
    }
    return $clean;
}

/**
 * Thrown by setlistCollabPerformUpdate() when the clean songs array cannot be
 * JSON-encoded (malformed UTF-8). A dedicated type so every caller maps it to
 * the SAME 400 "invalid characters" response the inline original produced —
 * distinguished by TYPE, not by regex-matching a message (rule #35). Extends
 * RuntimeException so an existing broad `catch (\Throwable)` still turns it into
 * a clean 500 rather than a white-screen if a new caller forgets the 400 branch.
 */
class SetlistCollabEncodeException extends \RuntimeException {}

/**
 * The ONE write core for a collaborative set-list edit (#1791).
 *
 * ELI5: takes an already-cleaned song list and writes it onto the OWNER's one
 * set-list row — it can only ever UPDATE that single existing row, never insert
 * or delete one.
 *
 * Extracted verbatim (byte-for-byte behaviour) from the `setlist_collab_update`
 * case so the token-edit path (`setlist_token_update`, #1791) writes through the
 * EXACT same encode-refuse-on-false → targeted-UPDATE sequence. Two write paths
 * to tblUserSetlists.SongsJson would drift, and the drift would be invisible
 * until a token edit produced a row the owner's client couldn't render — the
 * same argument setlistCollabSanitiseSongs() was extracted under (#1638).
 *
 * The structural safety argument is INHERITED, not re-made: this is an UPDATE of
 * one already-existing (UserId, SetlistId) row with LIMIT 1 — it can neither
 * INSERT a row into the owner's namespace nor DELETE one, so the worst a
 * compromised edit link can do is rewrite the songs of the one list it names.
 *
 * The CALLER is responsible for authorisation (owner resolution / token gate)
 * and for sanitising via setlistCollabSanitiseSongs() before calling — this core
 * assumes $cleanSongs is already the stored shape and $ownerUserId is the
 * resolved, trusted owner.
 *
 * @param \mysqli $db          Live connection.
 * @param int     $ownerUserId The RESOLVED owner (never the client's claim).
 * @param string  $setlistId   tblUserSetlists.SetlistId.
 * @param array   $cleanSongs  Output of setlistCollabSanitiseSongs().
 * @param string  $newName     Optional rename; '' leaves the owner's title alone.
 * @return array{changed:int,songs:array}
 * @throws SetlistCollabEncodeException on malformed-UTF-8 encode failure.
 */
function setlistCollabPerformUpdate(
    \mysqli $db,
    int $ownerUserId,
    string $setlistId,
    array $cleanSongs,
    string $newName = ''
): array {
    $songsJson = json_encode($cleanSongs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    /* json_encode returns FALSE on malformed UTF-8, and binding false writes an
       empty string — which would silently blank somebody else's setlist. Refuse
       instead: a rejected save the user can see beats a successful one that ate
       the list. */
    if ($songsJson === false) {
        throw new SetlistCollabEncodeException('Song list could not be encoded (invalid characters).');
    }

    if ($newName !== '') {
        $upd = $db->prepare(
            'UPDATE tblUserSetlists
                SET Name = ?, SongsJson = ?, UpdatedAt = UTC_TIMESTAMP()
              WHERE UserId = ? AND SetlistId = ?
              LIMIT 1'
        );
        $upd->bind_param('ssis', $newName, $songsJson, $ownerUserId, $setlistId);
    } else {
        $upd = $db->prepare(
            'UPDATE tblUserSetlists
                SET SongsJson = ?, UpdatedAt = UTC_TIMESTAMP()
              WHERE UserId = ? AND SetlistId = ?
              LIMIT 1'
        );
        $upd->bind_param('sis', $songsJson, $ownerUserId, $setlistId);
    }
    $upd->execute();
    /* affected_rows is 0 both when the row vanished AND when the payload was
       byte-identical to what is already stored, so it is NOT a failure — only
       information the caller may report. */
    $changed = $upd->affected_rows;
    $upd->close();

    return ['changed' => $changed, 'songs' => $cleanSongs];
}

/* ============================================================================
 * #1791 G4 / G4-org — the EDIT-AUDIENCE vocabulary + precedence resolver
 * ============================================================================
 *
 * ELI5: an edit LINK (not a collaborator invite) can be handed to "anyone
 * with the link, no account needed" (the owner's full ask) or tightened to
 * "only people signed in to iHymns". This section is the ONE place that
 * vocabulary and its app→org→user→per-link precedence chain live, so a
 * future third value (say 'org-members') is a one-line map change picked up
 * everywhere, instead of a grep-and-hope across api.php (the same argument
 * SETLIST_COLLAB_PERMISSIONS above already makes for view|edit).
 *
 * This is DELIBERATELY separate from SETLIST_COLLAB_PERMISSIONS: that
 * vocabulary answers "may THIS person touch the setlist" for an
 * account-based collaborator row; EditAudience answers "does touching this
 * ANONYMOUS capability link require an account at all". A view-scope link
 * never consults it (setlist_get only asks for scope='edit').
 * ========================================================================= */

/**
 * The complete edit-audience vocabulary, in ASCENDING RESTRICTIVENESS order
 * (index 0 = least restrictive). 'anyone' < 'authenticated' because
 * requiring sign-in is strictly the more restrictive of the two. Kept as an
 * ordered array (not just an unordered set) so
 * `setlistMoreRestrictiveEditAudience()` can compare two values by their
 * position rather than a second hand-maintained rank map.
 */
const SETLIST_EDIT_AUDIENCES = ['anyone', 'authenticated'];

/** `tblAppSettings` key for the site-wide default (the #1406
 *  `SERVICE_MODE_POLL_MS_*` / #1770 `LIVE_FOLLOW_IDLE_TIMEOUT_APP_SETTING_KEY`
 *  precedent — a freeform tblAppSettings key needs no migration to add or
 *  read). Absent → the column DEFAULT, 'anyone' (G4's "no account needed"
 *  full ask), NOT the fail-closed normaliser value below — an unset app
 *  setting is not the same thing as an unrecognised STORED value. */
const SETLIST_EDIT_AUDIENCE_APP_SETTING_KEY = 'setlist_edit_audience_default';
const SETLIST_EDIT_AUDIENCE_APP_DEFAULT     = 'anyone';

/** `tblUsers.Settings` JSON root key (rule #28 "new prefs are JSON" posture —
 *  a personal preference gets a key, never a new tblUsers column). Mirrors
 *  LIVE_FOLLOW_IDLE_TIMEOUT_USER_SETTING_KEY's shape. */
const SETLIST_EDIT_AUDIENCE_USER_SETTING_KEY = 'setlist_edit_audience_default';

/**
 * Normalise a raw, untrusted edit-audience value to the canonical vocabulary
 * — FAIL CLOSED to the MORE RESTRICTIVE value.
 *
 * ELI5: if we don't recognise what's stored, assume the safer answer
 * ("sign in required") rather than guessing the friendlier one — the same
 * "unknown is a refusal, never a silent widen" rule setlistCollabCanWrite()
 * already applies to view|edit.
 *
 * THIS IS FOR READING A STORED VALUE (a DB column, an org preference, a
 * `tblUsers.Settings` entry) — NOT for what a fresh edit link mints with no
 * owner input. The mint DEFAULT when the owner specifies nothing is the
 * PERMISSIVE `'anyone'` (the column DEFAULT / SETLIST_EDIT_AUDIENCE_APP_DEFAULT),
 * resolved via setlistResolveEditAudienceDefault() — never this normaliser,
 * which would turn "owner said nothing" into an unwanted account wall.
 *
 * @param  mixed $raw Untrusted value — a DB column, a JSON setting, a
 *                     request field. Accepts mixed for the same reason
 *                     setlistCollabNormalisePermission() does: it may be an
 *                     int, an array, or null, and all of those are "unknown"
 *                     rather than a crash.
 * @return string 'anyone' | 'authenticated' — never anything else.
 */
function setlistNormaliseEditAudience($raw): string
{
    if (!is_string($raw)) {
        return 'authenticated';
    }
    $a = mb_strtolower(trim($raw));
    return in_array($a, SETLIST_EDIT_AUDIENCES, true) ? $a : 'authenticated';
}

/**
 * The more restrictive of two ALREADY-NORMALISED audience values.
 *
 * ELI5: "which of these two rules is stricter?" — compares their position in
 * SETLIST_EDIT_AUDIENCES rather than a second hand-written rank table, so
 * the two can never drift apart.
 *
 * PRIVATE to this file (not exported in the doc-comment as a public API)
 * because callers should go through setlistResolveEditAudienceDefault() —
 * this is the one primitive it's built from.
 *
 * @param string $a One of SETLIST_EDIT_AUDIENCES.
 * @param string $b One of SETLIST_EDIT_AUDIENCES.
 * @return string Whichever of $a/$b sits later in SETLIST_EDIT_AUDIENCES.
 */
function setlistMoreRestrictiveEditAudience(string $a, string $b): string
{
    $rankA = array_search($a, SETLIST_EDIT_AUDIENCES, true);
    $rankB = array_search($b, SETLIST_EDIT_AUDIENCES, true);
    /* array_search returning false (an already-normalised caller should
       never hit this) is treated as "least restrictive" so a coding error
       here fails toward "compare again", not toward silently widening
       access — the surrounding resolver still clamps to the OTHER value. */
    $rankA = $rankA === false ? 0 : $rankA;
    $rankB = $rankB === false ? 0 : $rankB;
    return $rankA >= $rankB ? $a : $b;
}

/**
 * #1791 G4-org — memoised probe: do BOTH org-layer audience columns exist on
 * `tblOrganisations` yet? Lockstep on purpose, mirroring
 * `serviceMode_orgIdleColumnsExist()` (service_mode.php) — the resolver
 * needs `SetlistEditAudience` and `EnforceSetlistEditAudience` together, and
 * a half-applied ALTER must not be treated as "ready".
 *
 * WHY: mysqli runs under MYSQLI_REPORT_STRICT (db_mysql.php), so selecting a
 * missing column THROWS rather than returning nothing — the #1228 lesson.
 * Every org-layer read below goes through this probe rather than assuming
 * migrate-setlist-share-scope.php has been RUN (schema.sql having the
 * columns is not the same as an install having applied them — migrations
 * are web-run, never auto-applied, rule #19).
 */
function setlistOrgAudienceColumnsExist(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblOrganisations'
                AND COLUMN_NAME IN ('SetlistEditAudience', 'EnforceSetlistEditAudience')"
        );
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        $cached = ($count === 2);
    } catch (\Throwable $_e) {
        /* Probe failure — degrade as "not migrated yet" (fail closed on the
           columns, fail OPEN on caller behaviour: no org layer, resolver
           falls straight through to user/app defaults). */
        $cached = false;
    }
    return $cached;
}

/**
 * #1791 G4/G4-org — THE edit-audience precedence resolver: app default →
 * org layer (most-restrictive-wins across the owner's active orgs) → user
 * layer → clamp to any org's ENFORCED mandate.
 *
 * Structured LINE-FOR-LINE like `serviceMode_resolveIdleTimeoutMins()`
 * (service_mode.php, #1770 §5) so the two app→org→user resolvers stay one
 * shape, not two independent forks of the same idea (CLAUDE.md rule: "reuse
 * a shape, don't re-fork it").
 *
 * ELI5: "who's allowed to edit this link with no extra checks, by default?"
 * — start from the site-wide default, let the owner's church loosen or
 * tighten it (a church that LOCKS its answer always wins, and if the owner
 * belongs to several churches the strictest locked answer wins), then let
 * the owner's OWN saved preference override that — but never end up looser
 * than whatever the church mandated.
 *
 * PRECEDENCE: `resolved = user ?? orgDefault ?? appDefault`, then CLAMPED so
 * it is never LESS restrictive than `$enforced`. This is a FLOOR on
 * restrictiveness (CLAUDE.md's "a member's edit links can never be more
 * open than SetlistEditAudience"), not a full override the way #1770's
 * single-number `IdleTimeoutMins` enforcement is — because unlike a numeric
 * ceiling, this is a two-value vocabulary and a member should always be
 * FREE to choose MORE restrictive than the church's floor, never forced
 * down to exactly it.
 *
 * @param \mysqli     $db          Live connection from getDbMysqli().
 * @param int         $ownerUserId The set-list owner (tblUsers.Id) whose
 *                                 default is being resolved — NOT the
 *                                 anonymous edit-link holder (an anonymous
 *                                 visitor has no org/user layer to consult).
 * @param string|null &$enforcedOut OUT param — the most-restrictive
 *                                 ENFORCED org value across the owner's
 *                                 active orgs, or null when no org enforces
 *                                 one. Exposed (like #1798's
 *                                 `$enforcedMinsOut`) so `setlist_share` can
 *                                 clamp a CLIENT-CHOSEN audience to it
 *                                 independently of which layer produced the
 *                                 pre-selected default below.
 * @return string 'anyone' | 'authenticated'.
 */
function setlistResolveEditAudienceDefault(
    \mysqli $db,
    int $ownerUserId,
    ?string &$enforcedOut = null
): string {
    /* --- 1. App default --------------------------------------------------- */
    $appDefault = SETLIST_EDIT_AUDIENCE_APP_DEFAULT;
    if (function_exists('getAppSetting')) {
        $raw = getAppSetting(SETLIST_EDIT_AUDIENCE_APP_SETTING_KEY, $appDefault);
        /* An UNSET app setting resolves to $appDefault ('anyone') above —
           getAppSetting()'s own $default parameter, not this normaliser. A
           value that WAS explicitly stored but is garbled still fails
           closed via the normaliser, same as every other layer. */
        $appDefault = setlistNormaliseEditAudience($raw);
    }

    /* --- 2. Org layer (enforced + default), MOST-RESTRICTIVE wins --------- */
    $enforced   = null;
    $orgDefault = null;
    if ($ownerUserId > 0 && setlistOrgAudienceColumnsExist($db)) {
        try {
            $stmt = $db->prepare(
                'SELECT o.SetlistEditAudience AS Audience, o.EnforceSetlistEditAudience AS Enforce
                   FROM tblOrganisationMembers m
                   JOIN tblOrganisations o ON o.Id = m.OrgId
                  WHERE m.UserId = ? AND o.IsActive = 1 AND o.SetlistEditAudience IS NOT NULL'
            );
            $stmt->bind_param('i', $ownerUserId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as $r) {
                $audience = setlistNormaliseEditAudience($r['Audience'] ?? null);
                if ((int)($r['Enforce'] ?? 0) === 1) {
                    $enforced = ($enforced === null)
                        ? $audience
                        : setlistMoreRestrictiveEditAudience($enforced, $audience);
                } else {
                    $orgDefault = ($orgDefault === null)
                        ? $audience
                        : setlistMoreRestrictiveEditAudience($orgDefault, $audience);
                }
            }
        } catch (\Throwable $_e) {
            /* Fail-open: treat as "no org layer" — never break minting. */
        }
    }

    /* --- 3. User layer ------------------------------------------------------ */
    $userVal = null;
    if ($ownerUserId > 0) {
        try {
            $stmt = $db->prepare('SELECT Settings FROM tblUsers WHERE Id = ?');
            $stmt->bind_param('i', $ownerUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $rawSettings = $row['Settings'] ?? null;
            $arr = (is_string($rawSettings) && $rawSettings !== '') ? json_decode($rawSettings, true) : null;
            if (is_array($arr) && isset($arr[SETLIST_EDIT_AUDIENCE_USER_SETTING_KEY])
                && is_string($arr[SETLIST_EDIT_AUDIENCE_USER_SETTING_KEY])) {
                $userVal = setlistNormaliseEditAudience($arr[SETLIST_EDIT_AUDIENCE_USER_SETTING_KEY]);
            }
        } catch (\Throwable $_e) {
            /* Fail-open: treat as "no user preference set". */
        }
    }

    /* --- 4. Resolve + clamp -------------------------------------------------- */
    /* Expose the enforced layer alone (like #1798's $enforcedMinsOut) so a
       mint caller can cap a CLIENT-CHOSEN value at it independently of
       whichever layer ends up winning $preSelected below. */
    $enforcedOut = $enforced;

    $preSelected = $userVal ?? ($orgDefault ?? $appDefault);

    return $enforced !== null
        ? setlistMoreRestrictiveEditAudience($preSelected, $enforced)
        : $preSelected;
}
