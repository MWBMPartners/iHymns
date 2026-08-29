<?php

declare(strict_types=1);

/**
 * iHymns — My Organisations (#707)
 *
 * READ-ONLY view for users who hold an `admin` or `owner` row in
 * `tblOrganisationMembers` for at least one organisation. They see
 * the orgs they manage + each org's member list + each org's
 * licence rows.
 *
 * This file is the foundation laid in the first PR for #707; the
 * member add/remove/role-change + licence add/change/remove POST
 * endpoints are scheduled for a follow-up PR (each needs a
 * row-level org-ownership server-side check that this read-only
 * page doesn't yet exercise).
 *
 * Distinct from `/manage/organisations.php` which is system-admin-
 * only. That page covers EVERY organisation in the system; this
 * page is scoped to the orgs the current user holds an org-admin
 * row on.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';
/* Shared org helpers (#719 PR 2c) — ORG_MEMBER_ROLES + userCanActOnOrg().
   The local $canActOnOrg closure below remains for the page's session
   shape; the API layer uses userCanActOnOrg() with the bearer-token
   user shape. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'organisation_validation.php';
/* Licence-type registry (#459 / #1769 P2) — the ONE licence vocabulary; replaces
   the hardcoded key list below (fallback == today's literal exactly). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'licence_registry.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'org_licence_admin.php';  /* #1969 — shared org-licence CRUD core */
/* #1770 §4.7 — serviceMode_orgIdleColumnsExist() gates the ORG layer of the
   Live Follow leader-idle precedence chain (LiveIdleTimeoutMins /
   EnforceIdleTimeout) so this org-admin surface degrades cleanly on an
   un-migrated install (rule #19), same posture as /manage/organisations.php. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'service_mode.php';
/* #1791 G4-org — setlistOrgAudienceColumnsExist() gates the ORG layer of the
   set-list edit-link audience precedence chain (SetlistEditAudience /
   EnforceSetlistEditAudience). Column-existence-tolerant posture mirrors
   $orgIdleColsExist above verbatim (rule #19 / CLAUDE.md rule: reuse a
   shape, don't re-fork it). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'setlist_collab.php';
/* #1830 — the ONE org-logo core (kind registry + reads + the shared admin
   card renderer, also used by manage/organisations.php); org_logo_admin.php
   pulls in org_logo_helpers.php transitively. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'org_logo_admin.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser) {
    http_response_code(403);
    exit('Access denied.');
}

/* Page-level gate (#707):
   1. The system-level entitlement check confirms the role is allowed
      to hold an org-admin position at all (kept open by default for
      every signed-in user — the actual restriction is data-driven).
   2. The data-driven check looks at tblOrganisationMembers to find
      the orgs this specific user holds admin/owner role on.
   3. system-admin / global_admin shortcut to "see every org" because
      they can manage any org via /manage/organisations anyway. */
$systemAdmin = in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true);
$userId      = (int)($currentUser['id'] ?? $currentUser['Id'] ?? 0);

if (!$systemAdmin) {
    if (!userHasEntitlement('manage_own_organisation', $currentUser['role'] ?? null)) {
        http_response_code(403);
        exit('Access denied. The manage_own_organisation entitlement is required.');
    }
    if (!userHasOwnOrganisation($userId)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>403 — Not an organisation admin</h1>';
        echo '<p>You don\'t hold an admin or owner role on any organisation. ';
        echo 'A system administrator can grant you that role from /manage/organisations.</p>';
        echo '<p><a href="/manage/">Back to Dashboard</a></p>';
        echo '</body></html>';
        exit;
    }
}

$activePage = 'my-organisations';

$db = getDbMysqli();
$error   = '';
$success = '';
/* #1770 §4.7 — memoised; cheap to call once here and re-check inline below. */
$orgIdleColsExist = serviceMode_orgIdleColumnsExist($db);
/* #1791 G4-org — same posture, the set-list edit-audience org columns. */
$setlistAudienceColsExist = setlistOrgAudienceColumnsExist($db);
/* #1840 — same column-existence-tolerant posture, the brand-colour column. */
$orgBrandColsExist = orgBrandColumnsExist($db);
/* #1798 — declared here (not just before its query block below) because the
   $orgs-query branch can `goto render;` on an early empty-org exit (see
   below), which would otherwise skip straight past the declaration and
   leave $liveSessions undefined for the render section. Session-level idle
   columns are a DIFFERENT gate than $orgIdleColsExist above (that one is the
   ORG layer of the precedence chain; this is "does the SESSION even carry
   IdleTimeoutMins/LastLeaderSeenAt yet"). */
$liveSessionColsExist = serviceMode_idleColumnsExist($db);
$liveSessions = [];

/* Member-role allowlist from the shared include (#719 PR 2c). Licence-type key
   list now from the ONE registry (#459 / #1769 P2) — was the hardcoded literal
   ['ccli','mrl','ihymns_basic','ihymns_pro','custom']; licenceTypeKeys() returns
   exactly that on the fallback, and the live registry keeps them in SortOrder. */
$MEMBER_ROLES  = ORG_MEMBER_ROLES;
$LICENCE_TYPES = licenceTypeKeys($db);

/* Resolve which org IDs to show.
   - system-admin / global_admin → every org.
   - otherwise → only orgs where the current user has admin/owner role. */
$ownedOrgIds = $systemAdmin ? null : userIsOrgAdminOf($userId);

/* Row-level org-ownership gate for every action. system-admin /
   global_admin can act on any org; everyone else can only act on
   orgs they hold admin/owner role on. Returns true if allowed,
   false otherwise. (#707) */
$canActOnOrg = function (int $orgId) use ($systemAdmin, $userId): bool {
    if ($orgId <= 0) return false;
    if ($systemAdmin) return true;
    return in_array($orgId, userIsOrgAdminOf($userId), true);
};

/* ====================================================================
 * POST handlers — six edit endpoints (#707)
 *
 * Each handler:
 *   1. Validates CSRF.
 *   2. Calls $canActOnOrg($orgId) for the row-level gate. A forged POST
 *      against an org the current user doesn't admin returns 403 even
 *      if CSRF is valid.
 *   3. Performs the action (INSERT / UPDATE / DELETE).
 *   4. Writes an Activity Log row under org_admin.<verb>.
 *   5. Surfaces success / error banner.
 * ==================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $orgId  = (int)($_POST['org_id'] ?? 0);

    if (!$canActOnOrg($orgId)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>403 — Not authorised on this organisation</h1>';
        echo '<p>You don\'t hold an admin or owner role on the target organisation. ';
        echo 'This action was rejected at the server-side row-level check.</p>';
        echo '<p><a href="/manage/my-organisations">Back to My Organisations</a></p>';
        echo '</body></html>';
        exit;
    }

    try {
        switch ($action) {
            case 'member_add': {
                /* Add a user to the org by username or email. The form
                   posts a free-text identifier; we resolve it to a
                   tblUsers.Id so a curator can paste either form. */
                $identifier = trim((string)($_POST['user_identifier'] ?? ''));
                $role       = (string)($_POST['member_role'] ?? 'member');
                if ($identifier === '') { $error = 'Username or email is required.'; break; }
                if (!in_array($role, $MEMBER_ROLES, true)) { $error = 'Unknown member role.'; break; }

                $stmt = $db->prepare(
                    'SELECT Id FROM tblUsers WHERE Username = ? OR Email = ? LIMIT 1'
                );
                $stmt->bind_param('ss', $identifier, $identifier);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { $error = "User '{$identifier}' not found."; break; }
                $targetUserId = (int)$row['Id'];

                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisationMembers (UserId, OrgId, Role)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE Role = VALUES(Role)'
                );
                $stmt->bind_param('iis', $targetUserId, $orgId, $role);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.member_add', 'organisation', (string)$orgId, [
                    'user_id'    => $targetUserId,
                    'identifier' => $identifier,
                    'role'       => $role,
                ]);
                $success = "Added {$identifier} as {$role}.";
                break;
            }

            case 'member_role_change': {
                $targetUserId = (int)($_POST['user_id'] ?? 0);
                $role         = (string)($_POST['member_role'] ?? 'member');
                if ($targetUserId <= 0) { $error = 'Invalid user.'; break; }
                if (!in_array($role, $MEMBER_ROLES, true)) { $error = 'Unknown member role.'; break; }

                $stmt = $db->prepare('UPDATE tblOrganisationMembers SET Role = ? WHERE OrgId = ? AND UserId = ?');
                $stmt->bind_param('sii', $role, $orgId, $targetUserId);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.member_role_change', 'organisation', (string)$orgId, [
                    'user_id' => $targetUserId,
                    'role'    => $role,
                ]);
                $success = 'Member role updated.';
                break;
            }

            case 'member_remove': {
                $targetUserId = (int)($_POST['user_id'] ?? 0);
                if ($targetUserId <= 0) { $error = 'Invalid user.'; break; }
                /* Self-removal guard — an admin must never lock themselves
                   out of the org by accident. They have to ask a sibling
                   admin / owner / system admin to remove them. */
                if ($targetUserId === $userId && !$systemAdmin) {
                    $error = 'You cannot remove yourself from an organisation. Ask a co-admin or system admin to remove you.';
                    break;
                }
                $stmt = $db->prepare('DELETE FROM tblOrganisationMembers WHERE OrgId = ? AND UserId = ?');
                $stmt->bind_param('ii', $orgId, $targetUserId);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.member_remove', 'organisation', (string)$orgId, [
                    'user_id' => $targetUserId,
                ]);
                $success = 'Member removed.';
                break;
            }

            /* #1969 — the three per-row handlers delegate to the shared core
               (includes/org_licence_admin.php). The core validates the type
               against the registry, normalises the fields, and scopes every
               write to $orgId (own-only in the WHERE). The org-membership
               authorisation for $orgId happened earlier in this handler. */
            case 'licence_add': {
                $licenceType = (string)($_POST['licence_type'] ?? '');
                $res = orgLicenceUpsert($db, $orgId, $licenceType, $_POST);
                if (!$res['ok']) { $error = $res['error'] ?? 'Could not save the licence.'; break; }
                logActivity('org_admin.licence_add', 'organisation', (string)$orgId, [
                    'licence_type'   => $licenceType,
                    'licence_number' => trim((string)($_POST['licence_number'] ?? '')),
                    'is_active'      => !empty($_POST['is_active']),
                ]);
                $success = "Licence '{$licenceType}' saved.";
                break;
            }

            case 'licence_change': {
                $licenceId = (int)($_POST['licence_id'] ?? 0);
                if ($licenceId <= 0) { $error = 'Invalid licence row.'; break; }

                /* Belt-and-braces existence/ownership check for the user-facing
                   "does not belong" error (the core's UPDATE is already own-only,
                   but affected_rows can't tell "not found" from "no change"). */
                $stmt = $db->prepare(
                    'SELECT 1 FROM tblOrganisationLicences WHERE Id = ? AND OrganisationId = ?'
                );
                $stmt->bind_param('ii', $licenceId, $orgId);
                $stmt->execute();
                $owns = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if (!$owns) { $error = 'Licence row does not belong to that organisation.'; break; }

                $res = orgLicenceUpdateById($db, $orgId, $licenceId, $_POST);
                if (!$res['ok']) { $error = $res['error'] ?? 'Could not update the licence.'; break; }
                logActivity('org_admin.licence_change', 'organisation', (string)$orgId, [
                    'licence_id'     => $licenceId,
                    'licence_number' => trim((string)($_POST['licence_number'] ?? '')),
                    'is_active'      => !empty($_POST['is_active']),
                ]);
                $success = 'Licence updated.';
                break;
            }

            case 'licence_remove': {
                $licenceId = (int)($_POST['licence_id'] ?? 0);
                if ($licenceId <= 0) { $error = 'Invalid licence row.'; break; }

                $res = orgLicenceDeleteById($db, $orgId, $licenceId);
                if (!$res['ok']) { $error = $res['error'] ?? 'Could not remove the licence.'; break; }
                if (empty($res['deleted'])) { $error = 'Licence row does not belong to that organisation.'; break; }
                logActivity('org_admin.licence_remove', 'organisation', (string)$orgId, [
                    'licence_id' => $licenceId,
                ]);
                $success = 'Licence removed.';
                break;
            }

            case 'idle_timeout_update': {
                /* #1770 §4.7 — the ORG layer of the Live Follow leader-idle
                   precedence chain, editable by the org's OWN admin (not just
                   a system admin — this is the "my organisation" surface).
                   Column-existence-gated (rule #19); same clamp/NULL
                   semantics as the system-admin form on /manage/organisations
                   (empty minutes = "no override"). */
                if (!$orgIdleColsExist) { $error = 'Not available on this environment yet.'; break; }
                $idleMinsRaw = trim((string)($_POST['live_idle_timeout_mins'] ?? ''));
                $idleMinsIn  = ($idleMinsRaw === '') ? null : filter_var($idleMinsRaw, FILTER_VALIDATE_INT);
                $idleMinsVal = ($idleMinsIn === null || $idleMinsIn === false)
                    ? null
                    : max(LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES, min(LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES, (int)$idleMinsIn));
                $idleEnforceVal = !empty($_POST['enforce_idle_timeout']) ? 1 : 0;

                $stmt = $db->prepare(
                    'UPDATE tblOrganisations
                        SET LiveIdleTimeoutMins = ?, EnforceIdleTimeout = ?
                      WHERE Id = ?'
                );
                $stmt->bind_param('iii', $idleMinsVal, $idleEnforceVal, $orgId);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.idle_timeout_update', 'organisation', (string)$orgId, [
                    'live_idle_timeout_mins' => $idleMinsVal,
                    'enforce_idle_timeout'   => (bool)$idleEnforceVal,
                ]);
                $success = 'Live Follow idle-timeout setting saved.';
                break;
            }

            case 'setlist_edit_audience_update': {
                /* #1791 G4-org — the ORG layer of the set-list edit-link
                   audience precedence chain, editable by the org's OWN admin.
                   Column-existence-gated (rule #19); modelled line-for-line
                   on idle_timeout_update above (CLAUDE.md rule: reuse a
                   shape, don't re-fork it). The <select> only ever offers
                   three choices, so they are read as ONE combined field
                   rather than a separate audience + enforce pair — 'anyone'
                   is never a meaningful ORG preference (it is already the
                   app-wide default), so the only two audience values worth
                   an org opinion on are "advisory authenticated" and
                   "enforced authenticated". */
                if (!$setlistAudienceColsExist) { $error = 'Not available on this environment yet.'; break; }
                $audienceChoice = (string)($_POST['setlist_edit_audience_choice'] ?? 'none');
                switch ($audienceChoice) {
                    case 'require':
                        $audienceVal = 'authenticated';
                        $enforceVal  = 1;
                        break;
                    case 'default':
                        $audienceVal = 'authenticated';
                        $enforceVal  = 0;
                        break;
                    case 'none':
                    default:
                        $audienceVal = null;
                        $enforceVal  = 0;
                        break;
                }

                $stmt = $db->prepare(
                    'UPDATE tblOrganisations
                        SET SetlistEditAudience = ?, EnforceSetlistEditAudience = ?
                      WHERE Id = ?'
                );
                $stmt->bind_param('sii', $audienceVal, $enforceVal, $orgId);
                $stmt->execute();
                $stmt->close();
                logActivity('org_admin.setlist_edit_audience_update', 'organisation', (string)$orgId, [
                    'setlist_edit_audience'         => $audienceVal,
                    'enforce_setlist_edit_audience' => (bool)$enforceVal,
                ]);
                $success = 'Set-list edit-link preference saved.';
                break;
            }

            /* #1830 — organisation logos, editable by the org's OWN admin.
               $orgId here is ALREADY the row-level-gated global (the
               $canActOnOrg() check above ran before this switch), unlike
               manage/organisations.php's per-case re-parse. */
            case 'logo_upload': {
                $kind = (string)($_POST['kind'] ?? '');
                if (!in_array($kind, ihymnsOrgLogoKindKeys(), true)) { $error = 'Invalid request.'; break; }
                /* #1840 — the variant slot this upload targets. Defaults to
                   'default' so the ORIGINAL (pre-#1840) upload form, which
                   never sent a `variant` field at all, keeps working
                   byte-identically. Re-validated here even though
                   orgLogoUpsert() validates again (rule #5 belt-and-braces —
                   never trust a hidden form field alone). */
                $variant = (string)($_POST['variant'] ?? 'default');
                if (!in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)) { $error = 'Invalid request.'; break; }
                $altText = trim((string)($_POST['alt_text'] ?? '')) ?: null;
                $file    = $_FILES['logo_file'] ?? null;
                $fileErr = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                if ($fileErr !== UPLOAD_ERR_OK) {
                    $error = in_array($fileErr, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                        ? 'That file is too large for a logo.'
                        : 'Please choose a logo file to upload.';
                    break;
                }
                try {
                    $staged = orgLogoValidateAndStage((string)$file['tmp_name'], (int)$file['size']);
                    orgLogoUpsert($db, $orgId, $kind, $variant, $staged, $altText, $userId);
                    logActivity('org_admin.logo_upload', 'organisation', (string)$orgId, [
                        'kind' => $kind, 'variant' => $variant, 'mime' => $staged['mime'], 'bytes' => $staged['byteSize'],
                    ]);
                    $success = $variant === 'default' ? 'Logo uploaded.' : 'Theme version uploaded.';
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage(); // plain-English, safe to show verbatim (§4.4)
                }
                break;
            }

            case 'logo_remove': {
                $kind = (string)($_POST['kind'] ?? '');
                if (!in_array($kind, ihymnsOrgLogoKindKeys(), true)) { $error = 'Invalid request.'; break; }
                $variant = (string)($_POST['variant'] ?? 'default');
                if (!in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)) { $error = 'Invalid request.'; break; }
                /* #1840 — removing the DEFAULT row cascades to its light/dark
                   theme versions too (they'd otherwise become invisible
                   orphans the card can no longer manage); removing an
                   explicit light/dark row alone removes just that one. */
                if ($variant === 'default') {
                    orgLogoDeleteKindAll($db, $orgId, $kind);
                } else {
                    orgLogoDelete($db, $orgId, $kind, $variant);
                }
                logActivity('org_admin.logo_remove', 'organisation', (string)$orgId, ['kind' => $kind, 'variant' => $variant]);
                $success = $variant === 'default' ? 'Logo removed.' : 'Theme version removed.';
                break;
            }

            case 'logo_toggle': {
                $kind = (string)($_POST['kind'] ?? '');
                if (!in_array($kind, ihymnsOrgLogoKindKeys(), true)) { $error = 'Invalid request.'; break; }
                $active = !empty($_POST['active']);
                /* #1840 — kind-level toggle: one visibility switch per ASSET
                   (every variant of this kind together), not per rendition —
                   a kind half-hidden on only one theme is the same silent-
                   half state the removal cascade above also refuses to mint. */
                orgLogoSetActiveKind($db, $orgId, $kind, $active);
                logActivity('org_admin.logo_toggle', 'organisation', (string)$orgId, ['kind' => $kind, 'active' => $active]);
                $success = $active ? 'Logo shown again.' : 'Logo hidden.';
                break;
            }

            case 'brand_save': {
                /* #1840 §4.3 — org brand colour, editable by the org's OWN
                   admin. Column-existence-gated (rule #19, same posture as
                   idle_timeout_update/setlist_edit_audience_update above). */
                if (!$orgBrandColsExist) { $error = 'Not available on this environment yet.'; break; }
                $rawColour  = (string)($_POST['brand_colour'] ?? '');
                $normalised = ihymnsOrgBrandColourNormalise($rawColour);
                if ($normalised === false) {
                    /* Plain-English rejection (§4.3 quote) — the ONE
                       allowlist (ihymnsOrgBrandColourNormalise()) is the
                       gate; nothing malformed is ever stored or echoed. */
                    $error = "That doesn't look like a colour code — use the picker or a value like #6a1b9a.";
                    break;
                }
                orgSetBrandColour($db, $orgId, $normalised);
                logActivity('org_admin.brand_save', 'organisation', (string)$orgId, ['colour' => $normalised]);
                $success = $normalised === null ? 'Brand colour cleared.' : 'Brand colour saved.';
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/my-organisations.php] ' . $e->getMessage());
        if (function_exists('logActivityError')) {
            logActivityError('admin.my_organisations.save', 'organisation',
                (string)$orgId, $e, ['action' => $action]);
        }
        $where = $e->getFile() ? (' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') : '';
        $error = $error ?: 'Database error: ' . $e->getMessage() . $where;
    }
}

/* #1770 §4.7 — additive column list, appended only when the columns exist
   (rule #19); the hardcoded-source-constant interpolation is safe per rule
   #5 ($orgIdleColsExist is a bool, not request input). */
$orgIdleSelectCols = $orgIdleColsExist ? ', LiveIdleTimeoutMins, EnforceIdleTimeout' : '';
/* #1791 G4-org — same posture, the set-list edit-audience org columns. */
$setlistAudienceSelectCols = $setlistAudienceColsExist ? ', SetlistEditAudience, EnforceSetlistEditAudience' : '';
/* #1840 — same posture, the brand-colour column. */
$orgBrandSelectCols = $orgBrandColsExist ? ', BrandColor' : '';
try {
    if ($systemAdmin) {
        $stmt = $db->prepare(
            "SELECT Id, Name, Slug, Description, LicenceType, LicenceNumber, IsActive{$orgIdleSelectCols}{$setlistAudienceSelectCols}{$orgBrandSelectCols}
               FROM tblOrganisations
              ORDER BY Name ASC"
        );
        $stmt->execute();
    } else {
        if (empty($ownedOrgIds)) {
            $orgs = [];
            goto render;
        }
        $placeholders = implode(',', array_fill(0, count($ownedOrgIds), '?'));
        $stmt = $db->prepare(
            "SELECT Id, Name, Slug, Description, LicenceType, LicenceNumber, IsActive{$orgIdleSelectCols}{$setlistAudienceSelectCols}{$orgBrandSelectCols}
               FROM tblOrganisations
              WHERE Id IN ({$placeholders})
              ORDER BY Name ASC"
        );
        $types  = str_repeat('i', count($ownedOrgIds));
        $stmt->bind_param($types, ...$ownedOrgIds);
        $stmt->execute();
    }
    $orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/my-organisations.php] orgs load failed: ' . $e->getMessage());
    if (function_exists('logActivityError')) {
        logActivityError('admin.my_organisations.list', 'organisation', '', $e);
    }
    $orgs = [];
}

/* Per-org members + licences. Two extra queries per org — fine for
   the scale we expect (one user is admin of maybe 1-3 orgs). */
$orgMembers  = [];
$orgLicences = [];
foreach ($orgs as $o) {
    $orgId = (int)$o['Id'];
    try {
        $stmt = $db->prepare(
            'SELECT u.Id AS UserId, u.Username, u.DisplayName, u.Role AS SystemRole,
                    m.Role AS OrgRole, m.JoinedAt
               FROM tblOrganisationMembers m
               JOIN tblUsers u ON u.Id = m.UserId
              WHERE m.OrgId = ?
              ORDER BY m.JoinedAt DESC'
        );
        $stmt->bind_param('i', $orgId);
        $stmt->execute();
        $orgMembers[$orgId] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $_e) {
        $orgMembers[$orgId] = [];
    }

    try {
        /* #1969 — read via the shared core (same SELECT, one place). */
        $orgLicences[$orgId] = orgLicenceList($db, $orgId);
    } catch (\Throwable $_e) {
        /* tblOrganisationLicences may not exist on a pre-migration deployment.
           Fall through to no licences shown. */
        $orgLicences[$orgId] = [];
    }
}

/* #1798 — "Members' live sessions": active Quick ("Go Live") sessions whose
   HOST is a member of an org this viewer administers, each with an Extend
   control (POSTs the SAME `live_follow_extend` action the leader's own host
   bar uses — api.php's serviceMode_liveFollowExtendAuthorize() already
   grants an org-admin/site-admin of the host's org that authority). Quick
   sessions keep `OrgId = NULL` (rule #26), so this is resolved via the
   HOST's `tblOrganisationMembers` rows, never a session column — the SAME
   direction api.php's authorizer walks. System admins (who can act on any
   org anyway) see every active Quick session on this channel; everyone else
   sees only sessions whose host shares an org they administer. */
if ($liveSessionColsExist && ($systemAdmin || !empty($ownedOrgIds))) {
    try {
        $channel = serviceMode_channel();
        if ($systemAdmin) {
            $stmt = $db->prepare(
                'SELECT DISTINCT s.Id, s.SessionCode, s.HostUserId, u.DisplayName AS HostName,
                        u.Username AS HostUsername, s.CurrentSongId, s.IdleTimeoutMins,
                        s.LastLeaderSeenAt, s.StartedAt
                   FROM tblLiveFollowSessions s
                   JOIN tblUsers u ON u.Id = s.HostUserId
                  WHERE s.SessionKind = \'host\' AND s.IsActive = 1 AND s.Channel = ?
                  ORDER BY s.StartedAt DESC'
            );
            $stmt->bind_param('s', $channel);
        } else {
            /* Placeholders built from a COUNT — a hardcoded construction,
               never request data (rule #5). */
            $orgPlaceholders = implode(',', array_fill(0, count($ownedOrgIds), '?'));
            $stmt = $db->prepare(
                "SELECT DISTINCT s.Id, s.SessionCode, s.HostUserId, u.DisplayName AS HostName,
                        u.Username AS HostUsername, s.CurrentSongId, s.IdleTimeoutMins,
                        s.LastLeaderSeenAt, s.StartedAt
                   FROM tblLiveFollowSessions s
                   JOIN tblUsers u ON u.Id = s.HostUserId
                   JOIN tblOrganisationMembers m ON m.UserId = s.HostUserId
                  WHERE m.OrgId IN ({$orgPlaceholders})
                    AND s.SessionKind = 'host' AND s.IsActive = 1 AND s.Channel = ?
                  ORDER BY s.StartedAt DESC"
            );
            $types  = str_repeat('i', count($ownedOrgIds)) . 's';
            $params = array_merge($ownedOrgIds, [$channel]);
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $liveSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[manage/my-organisations.php] live sessions load failed: ' . $e->getMessage());
        $liveSessions = [];
    }
}

render:
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Organisations — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<div class="container-admin py-4">
    <h1 class="h4 mb-3">
        <i class="bi bi-building me-2"></i>My Organisations
    </h1>
    <p class="text-muted small">
        The organisations you help run as an admin or owner. Here you can add or remove members, change each member's role, and keep their licence details up to date. System administrators see every organisation because they can manage any of them.
    </p>

    <?php if ($liveSessionColsExist && !empty($liveSessions)): ?>
        <!-- #1798 — Members' live sessions: Extend/keep-alive on behalf of a
             leader whose phone died mid-service. POSTs the SAME
             live_follow_extend action the leader's own host bar uses. -->
        <div class="card-admin p-3 mb-3">
            <h2 class="h5 mb-2">
                <i class="bi bi-broadcast-pin me-2"></i>Members&rsquo; live sessions
            </h2>
            <p class="text-muted small">
                Active &ldquo;Go Live&rdquo; sessions led by someone in one of your organisations.
                Use Extend if a leader&rsquo;s device died mid-service and their session is about
                to auto-close from inactivity.
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-dark mb-0 small align-middle">
                    <thead>
                        <tr>
                            <th>Leader</th>
                            <th>Code</th>
                            <th>Idle timeout</th>
                            <th>Last seen</th>
                            <th>Started</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liveSessions as $ls): ?>
                            <tr data-live-session-code="<?= htmlspecialchars((string)$ls['SessionCode']) ?>">
                                <td>
                                    <?= htmlspecialchars((string)($ls['HostName'] ?: $ls['HostUsername'])) ?>
                                </td>
                                <td><code><?= htmlspecialchars((string)$ls['SessionCode']) ?></code></td>
                                <td>
                                    <?= $ls['IdleTimeoutMins'] !== null ? ((int)$ls['IdleTimeoutMins'] . ' min') : 'site default' ?>
                                </td>
                                <td><?= htmlspecialchars((string)($ls['LastLeaderSeenAt'] ?? '—')) ?></td>
                                <td><?= htmlspecialchars((string)($ls['StartedAt'] ?? '—')) ?></td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <select class="form-select form-select-sm live-session-duration" style="width:auto;">
                                            <option value="30">30 min</option>
                                            <option value="60" selected>1 hour</option>
                                            <option value="120">2 hours</option>
                                            <option value="240">Until leader ends it</option>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-secondary live-session-extend-btn"
                                                data-code="<?= htmlspecialchars((string)$ls['SessionCode']) ?>">
                                            <i class="bi bi-clock-history me-1"></i>Extend
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success py-2 small"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($orgs)): ?>
        <div class="alert alert-info">
            You're not currently an admin or owner of any organisation.
            A system administrator can grant you that role from
            <a href="/manage/organisations">Manage &rsaquo; Organisations</a>.
        </div>
    <?php else: ?>
        <?php foreach ($orgs as $o): $orgId = (int)$o['Id']; ?>
            <div class="card-admin p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h2 class="h5 mb-0">
                            <?= htmlspecialchars((string)$o['Name']) ?>
                            <?php if (empty($o['IsActive'])): ?>
                                <span class="badge bg-secondary ms-1">inactive</span>
                            <?php endif; ?>
                        </h2>
                        <div class="text-muted small">
                            <code><?= htmlspecialchars((string)$o['Slug']) ?></code>
                            <?php if ($o['LicenceType']): ?>
                                · primary licence:
                                <code><?= htmlspecialchars((string)$o['LicenceType']) ?></code>
                                <?php if ($o['LicenceNumber']): ?>
                                    (<?= htmlspecialchars((string)$o['LicenceNumber']) ?>)
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($systemAdmin): ?>
                        <a href="/manage/organisations?edit=<?= $orgId ?>"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil me-1"></i>System edit
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($o['Description']): ?>
                    <p class="small mb-2"><?= htmlspecialchars((string)$o['Description']) ?></p>
                <?php endif; ?>

                <h3 class="h6 mt-3 mb-2">Members (<?= count($orgMembers[$orgId] ?? []) ?>)</h3>
                <?php if (empty($orgMembers[$orgId])): ?>
                    <p class="text-muted small">No members yet — use the Add member form below.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-dark mb-2 small align-middle cp-sortable admin-table-responsive">
                            <thead><tr>
                                <th data-sort-key="username" data-sort-type="text">Username</th>
                                <th data-sort-key="displayname" data-sort-type="text">Display Name</th>
                                <th data-sort-key="sysrole" data-sort-type="text">System role</th>
                                <th data-sort-key="orgrole" data-sort-type="text">Org role</th>
                                <th class="text-end">Actions</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($orgMembers[$orgId] as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$m['Username']) ?></td>
                                    <td><?= htmlspecialchars((string)($m['DisplayName'] ?? '')) ?></td>
                                    <td><code><?= htmlspecialchars((string)($m['SystemRole'] ?? 'user')) ?></code></td>
                                    <td data-sort-value="<?= htmlspecialchars((string)$m['OrgRole'], ENT_QUOTES) ?>">
                                        <form method="POST" class="d-inline-flex align-items-center gap-1">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="member_role_change">
                                            <input type="hidden" name="org_id"  value="<?= $orgId ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)($m['UserId'] ?? 0) ?>">
                                            <select name="member_role" class="form-select form-select-sm py-0" style="width:auto;">
                                                <?php foreach ($MEMBER_ROLES as $mr): ?>
                                                    <option value="<?= $mr ?>" <?= $m['OrgRole'] === $mr ? 'selected' : '' ?>><?= $mr ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-info py-0 px-2" title="Change role"
                                                    aria-label="Save role change for <?= htmlspecialchars($m['Username'], ENT_QUOTES) ?>">
                                                <i class="bi bi-check2" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Remove <?= htmlspecialchars($m['Username'], ENT_QUOTES) ?> from this organisation?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="member_remove">
                                            <input type="hidden" name="org_id"  value="<?= $orgId ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)($m['UserId'] ?? 0) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Remove from organisation"
                                                    aria-label="Remove <?= htmlspecialchars($m['Username'], ENT_QUOTES) ?> from this organisation">
                                                <i class="bi bi-x" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Add member form -->
                <form method="POST" class="row g-2 align-items-end small mb-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="member_add">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <div class="col-md-5">
                        <label class="form-label small mb-0" for="member-identifier-<?= $orgId ?>">Add member (username or email)</label>
                        <input type="text" name="user_identifier" id="member-identifier-<?= $orgId ?>" class="form-control form-control-sm"
                               placeholder="username or email" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="member-role-<?= $orgId ?>">Role</label>
                        <select name="member_role" id="member-role-<?= $orgId ?>" class="form-select form-select-sm">
                            <?php foreach ($MEMBER_ROLES as $mr): ?>
                                <option value="<?= $mr ?>" <?= $mr === 'member' ? 'selected' : '' ?>><?= $mr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-sm btn-amber-solid">
                            <i class="bi bi-plus-circle me-1"></i>Add member
                        </button>
                    </div>
                </form>

                <h3 class="h6 mt-3 mb-2">Licences</h3>
                <?php if (empty($orgLicences[$orgId])): ?>
                    <p class="text-muted small">No licences attached. Use the Add licence form below.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-dark mb-2 small align-middle cp-sortable admin-table-responsive">
                            <thead><tr>
                                <!-- Only Type is sortable: Number, Expires, Active and Notes below
                                     collapse into a single colspan-merged inline-edit form cell at
                                     render time (four data cells serve six header cells), so the
                                     module's positional cell-index lookup cannot address them
                                     individually (#1786 sweep). -->
                                <th data-sort-key="type" data-sort-type="text">Type</th><th>Number</th><th>Expires</th><th>Active</th><th>Notes</th>
                                <th class="text-end">Actions</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($orgLicences[$orgId] as $l): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars((string)$l['LicenceType']) ?></code></td>
                                    <td>
                                        <form method="POST" class="d-inline-flex align-items-center gap-1 flex-wrap">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="licence_change">
                                            <input type="hidden" name="org_id"     value="<?= $orgId ?>">
                                            <input type="hidden" name="licence_id" value="<?= (int)($l['Id'] ?? 0) ?>">
                                            <!-- #936: inline labels so each input is self-describing
                                                 without depending on the table-header alignment, which
                                                 doesn't work here because the form is contained inside
                                                 a single cell with colspan="3" trailing it. -->
                                            <span class="text-muted small" aria-hidden="true">№</span>
                                            <input type="text" name="licence_number"
                                                   class="form-control form-control-sm py-0"
                                                   value="<?= htmlspecialchars((string)$l['LicenceNumber']) ?>"
                                                   title="Licence number"
                                                   aria-label="Licence number"
                                                   placeholder="Licence number"
                                                   style="width: 10rem;">
                                            <span class="text-muted small" aria-hidden="true">Expires</span>
                                            <input type="date" name="expires_at"
                                                   class="form-control form-control-sm py-0"
                                                   value="<?= htmlspecialchars(substr((string)($l['ExpiresAt'] ?? ''), 0, 10)) ?>"
                                                   title="Licence expiration date"
                                                   aria-label="Licence expiration date"
                                                   style="width: 9rem;">
                                            <div class="form-check form-check-inline mb-0">
                                                <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                                       id="licence-active-<?= (int)($l['Id'] ?? 0) ?>"
                                                       <?= !empty($l['IsActive']) ? 'checked' : '' ?>
                                                       title="Licence is currently active"
                                                       aria-label="Licence is currently active">
                                                <label class="form-check-label small" for="licence-active-<?= (int)($l['Id'] ?? 0) ?>">active</label>
                                            </div>
                                            <input type="text" name="notes"
                                                   class="form-control form-control-sm py-0"
                                                   value="<?= htmlspecialchars((string)($l['Notes'] ?? '')) ?>"
                                                   title="Notes"
                                                   aria-label="Licence notes"
                                                   placeholder="Notes" style="width: 11rem;">
                                            <button type="submit" class="btn btn-sm btn-outline-info py-0 px-2" title="Save"
                                                    aria-label="Save <?= htmlspecialchars($l['LicenceType'], ENT_QUOTES) ?> licence changes">
                                                <i class="bi bi-check2" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td colspan="3"></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Remove the <?= htmlspecialchars($l['LicenceType'], ENT_QUOTES) ?> licence row?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="licence_remove">
                                            <input type="hidden" name="org_id"     value="<?= $orgId ?>">
                                            <input type="hidden" name="licence_id" value="<?= (int)($l['Id'] ?? 0) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Remove licence"
                                                    aria-label="Remove the <?= htmlspecialchars($l['LicenceType'], ENT_QUOTES) ?> licence">
                                                <i class="bi bi-x" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Add licence form -->
                <form method="POST" class="row g-2 align-items-end small">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="licence_add">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <div class="col-md-2">
                        <label class="form-label small mb-0" for="licence-type-<?= $orgId ?>">Type</label>
                        <select name="licence_type" id="licence-type-<?= $orgId ?>" class="form-select form-select-sm" required>
                            <option value="">— pick —</option>
                            <?php foreach ($LICENCE_TYPES as $lt): ?>
                                <option value="<?= $lt ?>"><?= $lt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="licence-number-<?= $orgId ?>">Licence number</label>
                        <input type="text" name="licence_number" id="licence-number-<?= $orgId ?>" class="form-control form-control-sm"
                               placeholder="e.g. CCLI 1234567">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-0" for="licence-expires-<?= $orgId ?>">Expires</label>
                        <input type="date" name="expires_at" id="licence-expires-<?= $orgId ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-1 form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="licence-active-new-<?= $orgId ?>" value="1" checked>
                        <label class="form-check-label small" for="licence-active-new-<?= $orgId ?>">active</label>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="licence-notes-<?= $orgId ?>">Notes</label>
                        <input type="text" name="notes" id="licence-notes-<?= $orgId ?>" class="form-control form-control-sm" placeholder="optional">
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-sm btn-amber-solid">
                            <i class="bi bi-plus-circle me-1"></i>Add licence
                        </button>
                    </div>
                </form>

                <!-- #1770 §4.7 — Live Follow leader-idle ORG override. -->
                <?php if ($orgIdleColsExist): ?>
                <h3 class="h6 mt-3 mb-2">Live Follow idle timeout</h3>
                <form method="POST" class="row g-2 align-items-end small">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="idle_timeout_update">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="idle-minutes-<?= $orgId ?>">Minutes <span class="text-muted">(blank = site default)</span></label>
                        <input type="number" name="live_idle_timeout_mins" id="idle-minutes-<?= $orgId ?>" class="form-control form-control-sm"
                               min="<?= LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES ?>" max="<?= LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES ?>" step="1"
                               placeholder="site default"
                               value="<?= isset($o['LiveIdleTimeoutMins']) && $o['LiveIdleTimeoutMins'] !== null ? (int)$o['LiveIdleTimeoutMins'] : '' ?>">
                    </div>
                    <div class="col-md-6 form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="enforce_idle_timeout" id="enforce-idle-<?= $orgId ?>" value="1"
                               <?= !empty($o['EnforceIdleTimeout']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enforce-idle-<?= $orgId ?>">
                            Lock this value for members (their own Settings preference is ignored)
                        </label>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-sm btn-amber-solid">
                            <i class="bi bi-save me-1"></i>Save
                        </button>
                    </div>
                    <div class="col-12">
                        <p class="text-muted small mb-0">
                            A worship leader's "Go Live" session in this organisation auto-closes after this many
                            minutes of no genuine leader interaction.
                        </p>
                    </div>
                </form>
                <?php endif; ?>

                <!-- #1791 G4-org — set-list EDIT-LINK audience org preference. -->
                <?php if ($setlistAudienceColsExist):
                    $setlistAudienceChoice = 'none';
                    if (!empty($o['EnforceSetlistEditAudience'])) {
                        $setlistAudienceChoice = 'require';
                    } elseif (($o['SetlistEditAudience'] ?? null) === 'authenticated') {
                        $setlistAudienceChoice = 'default';
                    }
                ?>
                <h3 class="h6 mt-3 mb-2">Set-list edit links</h3>
                <form method="POST" class="row g-2 align-items-end small">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="setlist_edit_audience_update">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <div class="col-md-5">
                        <label class="form-label small mb-0" for="setlist-edit-audience-<?= $orgId ?>">
                            Who may edit a shared set-list link
                        </label>
                        <select name="setlist_edit_audience_choice" id="setlist-edit-audience-<?= $orgId ?>"
                                class="form-select form-select-sm">
                            <option value="none" <?= $setlistAudienceChoice === 'none' ? 'selected' : '' ?>>No preference</option>
                            <option value="default" <?= $setlistAudienceChoice === 'default' ? 'selected' : '' ?>>Default to signed-in (members may still loosen it)</option>
                            <option value="require" <?= $setlistAudienceChoice === 'require' ? 'selected' : '' ?>>Require signed-in (locked for members)</option>
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-sm btn-amber-solid">
                            <i class="bi bi-save me-1"></i>Save
                        </button>
                    </div>
                    <div class="col-12">
                        <p class="text-muted small mb-0">
                            Controls who can edit a set list via an anonymous "anyone with the link can edit" link
                            created by this organisation's members. "No preference" leaves the app default (anyone
                            with the link, no account needed). "Require signed-in" caps every member's edit links
                            so an account is always needed to make a change, even if a member asks for "anyone".
                        </p>
                    </div>
                </form>
                <?php endif; ?>

                <!-- #1840 §4.3 — org brand colour (Share Card Option B). Column-
                     existence-gated (rule #19), same posture as the two blocks
                     above. Reuses the shared colour-picker partial (rule: reuse a
                     swatch widget, don't fork one, #1791/#715 precedent) rather
                     than a bespoke <input type="color">. -->
                <?php if ($orgBrandColsExist):
                    $orgBrandColourVal = (string)($o['BrandColor'] ?? '');
                ?>
                <h3 class="h6 mt-3 mb-2">Brand colour</h3>
                <form method="POST" class="row g-2 align-items-end small">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="brand_save">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <div class="col-md-6">
                        <?php
                            /* Local vars consumed by the partial (its own
                               documented contract) — never a hand-rolled
                               <input type="color"> here. */
                            $name        = 'brand_colour';
                            $value       = $orgBrandColourVal;
                            $idPrefix    = 'brand-colour-' . $orgId;
                            $label       = 'Brand colour';
                            $placeholder = '#6a1b9a';
                            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'colour-picker.php';
                        ?>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-sm btn-amber-solid">
                            <i class="bi bi-save me-1"></i>Save
                        </button>
                    </div>
                </form>
                <?php if ($orgBrandColourVal !== ''): ?>
                <form method="POST" class="d-inline mt-1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="brand_save">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <input type="hidden" name="brand_colour" value="">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Clear brand colour</button>
                </form>
                <?php endif; ?>
                <p class="text-muted small mb-0">
                    Used where iHymns shows your church's branding — for example the coloured band
                    on shared set-list preview images.
                </p>
                <?php endif; ?>

                <?php /* #1830 — renders '' (nothing) on an un-migrated install (rule #19). */
                      echo orgLogoRenderAdminCard($db, $orgId, $csrf); ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Sortable table headers (#1786 sweep). -->
<script type="module">
    import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
    bootSortableTables();
</script>

<?php if ($orgBrandColsExist): ?>
<!-- #1840 — swatch<->hex two-way binding for every .colour-picker on the
     page (the Brand colour field above), shared with songbooks.php rather
     than a bespoke handler here. -->
<script type="module">
    import { bootColourPickers } from '/js/modules/colour-picker.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/colour-picker.js') ?>';
    bootColourPickers();
</script>
<?php endif; ?>

<?php if ($liveSessionColsExist && !empty($liveSessions)): ?>
<!-- #1798 — "Members' live sessions" Extend wiring. Cookie-authed
     (same-origin); X-Requested-With satisfies the CSRF guard — mirrors the
     apiCall() helper already established on manage/service-projection.php.
     A full page reload after a successful extend is the deliberately
     lightweight refresh (this card's rows are server-rendered, not a live
     JS view — a reload just re-runs the same query with the new values). -->
<script type="module">
    function apiCall(action, opts) {
        opts = opts || {};
        const init = { method: opts.method || 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' };
        if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
        return fetch('/api?action=' + action + (opts.query || ''), init).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, ok: r.ok, j: j }; });
        });
    }

    document.querySelectorAll('.live-session-extend-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const code = btn.getAttribute('data-code');
            const row = btn.closest('tr');
            const sel = row ? row.querySelector('.live-session-duration') : null;
            const mins = sel ? parseInt(sel.value, 10) : 60;
            btn.disabled = true;
            apiCall('live_follow_extend', { method: 'POST', body: { code: code, idleTimeoutMins: mins } })
                .then(function (res) {
                    if (res.status === 409) {
                        window.alert('This install hasn’t been migrated for Live Follow session lengths yet.');
                        btn.disabled = false;
                        return;
                    }
                    if (!res.ok || !res.j || !res.j.ok) {
                        window.alert((res.j && res.j.error) || 'Could not extend that session.');
                        btn.disabled = false;
                        return;
                    }
                    window.location.reload();
                })
                .catch(function () {
                    window.alert('Could not extend that session (network error).');
                    btn.disabled = false;
                });
        });
    });
</script>
<?php endif; ?>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
