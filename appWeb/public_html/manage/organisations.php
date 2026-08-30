<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Organisations
 *
 * Minimal CRUD over `tblOrganisations` + member management via
 * `tblOrganisationMembers`. Gated by `manage_organisations`.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'slug-field.php';   /* #1870 — ihymns_slug_advanced_field() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* Shared org-validation include (#719 PR 2c) — exports the
   ORG_MEMBER_ROLES const + slugifyOrganisationName(). Same helpers
   used by the admin_organisation_* and org_admin_* API endpoints. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'organisation_validation.php';
/* Places adoption helper — exposes placeColumnExists() so the
   create / update paths can persist PhysicalCityId alongside the
   legacy PhysicalCity display string only when the
   places-adoption migration has landed. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'places.php';
/* Licence-type registry (#459, built #1769 P2) — the ONE source of the licence
   vocabulary. Replaces the hardcoded $LICENCE_TYPES literal below; degrades to
   LICENCE_TYPES_FALLBACK on an un-migrated install. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'licence_registry.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'org_licence_admin.php';  /* #1969 — shared org-licence CRUD core */
/* #1996 — the shared organisation CREATE core (mirrors #1993's
   songbook_admin.php): orgAdminValidateCreate()/orgAdminCreate()/
   orgAdminApplyLicenceRows()/orgAdminMemberAdd(), reused by this page's
   `create`/`add_member` cases, the new guided wizard's
   `wizard_create_organisation` case below, and the new
   admin_organisation_create API twin (rule #22). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'organisation_admin.php';
/* #1770 §4.7 — serviceMode_orgIdleColumnsExist() gates the ORG layer of the
   leader-idle precedence chain (LiveIdleTimeoutMins / EnforceIdleTimeout);
   same column-existence-tolerant posture as placeColumnExists() above. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'service_mode.php';
/* #1830 — the ONE org-logo core (kind registry + reads + the shared admin
   card renderer); org_logo_admin.php (validate/stage/upsert/delete) is
   required transitively for the logo_upload/_remove/_toggle POST actions. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'org_logo_admin.php';
/* #1999 — the shared "Get started" empty-state launcher, rendered in the
   list table's empty row below (points at the SAME guided wizard the
   header button above already opens — rule #1). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'wizard-empty-state.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_organisations', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_organisations required</h1></body></html>';
    exit;
}
$activePage = 'organisations';

/* Can this admin edit the LICENCE fields, as distinct from the organisation
   itself? (#462's stated intent, wired as a #1590 follow-up.)
   ELI5: one tick-box decides whether the licence boxes on this page are yours
   to change; everything else on the page is governed by manage_organisations.
   Detail: #462 registered `manage_org_licences` "so licence edits can be
   delegated without granting full org admin", then never wired it — the licence
   fields live on the same form and the same UPDATE as Name/Slug/Parent, so
   there was no separate handler to attach it to. Its DEFAULT roles are
   byte-identical to `manage_organisations` (both admin+), so nobody's access
   changes today; what changes is that NARROWING it now does something, which is
   the whole point of the #1590 truth-up. Not to be confused with
   `manage_own_organisation` — that is the ORG OWNER's path on
   /manage/my-organisations and deliberately includes plain users. */
$canEditOrgLicences = userHasEntitlement('manage_org_licences', $currentUser['role'] ?? null);

$error   = '';
$success = '';
$db      = getDbMysqli();

/* Machine key → human label + short description, sourced from the ONE licence
   registry (#459 delivered by #1769 P2 — was a hardcoded literal here). The key
   is what the DB / evaluator reference; the label is what every UI surface
   renders so admins never see raw tokens like `ihymns_pro`. includeNone:true
   prepends the "None" empty-state (tblOrganisations.LicenceType DEFAULT 'none').
   The picker now also offers `mrl` + `custom` (registry rows the old literal
   omitted); it degrades to LICENCE_TYPES_FALLBACK on an un-migrated install. */
$LICENCE_TYPES  = licenceTypesForPicker($db, true);
$LICENCE_TYPE_KEYS = array_keys($LICENCE_TYPES);
/* Member roles + slugify lifted into includes/organisation_validation.php
   (#719 PR 2c). Closure kept as a thin wrapper so existing call sites
   below keep working unchanged. */
$MEMBER_ROLES = ORG_MEMBER_ROLES;
$slugify      = fn(string $s): string => slugifyOrganisationName($s);

/* ----- POST actions ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    /* #1996 — the guided "New Organisation + licence" wizard's JSON-in/
       JSON-out AJAX branch. Lives BEFORE the classic-form dispatch below
       and gates on validateCsrfRequest() (same-origin X-Requested-With,
       rule #29) — NOT the legacy form's baked validateCsrf() token — the
       SAME shape manage/external-link-types.php's `wizard_create_type`
       branch uses (rule #22): this page's whole POST handler is NOT
       already validateCsrfRequest()-gated (unlike songbooks.php's
       `wizard_create_songbook`, an ordinary case inside a switch whose
       WHOLE handler already gates on validateCsrfRequest()), so the
       wizard needs its own same-origin check here, ahead of the classic
       forms' validateCsrf() gate below. The classic manual-form/edit-form
       POSTs further down are UNCHANGED and still gate on validateCsrf()
       alone. */
    if ($action === 'wizard_create_organisation') {
        header('Content-Type: application/json; charset=UTF-8');
        if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF check failed — please retry.']);
            exit;
        }

        try {
            /* #1986 finer gate — $canEditOrgLicences was already resolved
               once, above the POST-handling block (page-level, ~L63), the
               SAME variable the manual create/update forms already use.
               Passed straight into orgAdminValidateCreate(), which forces
               the PRIMARY licence_type/number to 'none'/'' when false —
               see includes/organisation_admin.php's "THE #1986 FINER GATE"
               doc-block. The licence STEP being absent from the wizard's
               own DOM (organisations.php ~L63's $canEditOrgLicences also
               gates step 2's markup, below) is UX only; this server-side
               force is the actual gate — a crafted POST cannot bypass it. */
            [$fields, $err, $errStatus, $errField] = orgAdminValidateCreate($db, $_POST, $canEditOrgLicences);
            if ($err !== null) {
                http_response_code($errStatus);
                echo json_encode(['error' => $err, 'field' => $errField]);
                exit;
            }

            try {
                $created = orgAdminCreate($db, $fields);
            } catch (OrgAdminDuplicateSlugException $e) {
                http_response_code(409);
                echo json_encode(['error' => $e->getMessage(), 'field' => 'slug']);
                exit;
            }
            /* The freshly-minted id is the ONLY organisation id this branch
               ever touches — it is never read from the request (IDOR is
               structurally impossible: there is no $_POST['org_id'] read
               anywhere in this case body). */
            $newOrgId = $created['id'];

            logActivity('org.create', 'organisation', (string)$newOrgId, [
                'name'           => $fields['name'],
                'slug'           => $fields['slug'],
                'parent_org_id'  => $fields['parent'],
                'licence_type'   => $fields['licenceType'],
                'is_active'      => (bool)$fields['active'],
                'via'            => 'wizard',
            ]);

            /* Additional licence rows (#1969 multi) — wizard/API-only step,
               the manual "Add an organisation" form has no fields for this.
               #1986 finer gate, resolved explicitly here (mirrors the
               admin_organisation_create API twin below) — never called
               without checking $canEditOrgLicences immediately first. A
               caller without the entitlement gets zero rows applied,
               regardless of what the request body carries. Post-create
               failures here are REPORTED (per-row {type,ok,error}), never
               rolled back — the org itself already committed (songbooks
               wizard precedent). */
            $licenceResults = [];
            if ($canEditOrgLicences) {
                /* Deliberately DISTINCT key names from the singular
                   `licence_type`/`licence_number` orgAdminValidateCreate()
                   just read above (the PRIMARY licence) — `licence_type=A`
                   and `licence_type[]=B` collide onto the SAME $_POST key
                   in PHP's form-urlencoded parser (the later array-notation
                   entries silently clobber the scalar into an array),
                   which would both corrupt the primary and throw an
                   "Array to string conversion" inside Validate(). Verified
                   against a live parse_str() probe before this shape was
                   chosen — see this commit's report. */
                $lTypes  = (array)($_POST['licence_row_type']        ?? []);
                $lNums   = (array)($_POST['licence_row_number']      ?? []);
                $lExps   = (array)($_POST['licence_row_expires_at']  ?? []);
                $lActive = (array)($_POST['licence_row_active']      ?? []);
                $lNotes  = (array)($_POST['licence_row_notes']       ?? []);
                /* Security audit F2 — row-count DoS cap (IHYMNS_ORG_WIZARD_ROW_CAP,
                   includes/organisation_admin.php). Checked before a single row is
                   parsed — this JSON branch is bounded only by post_max_size, not
                   PHP's form max_input_vars. */
                if (count($lTypes) > IHYMNS_ORG_WIZARD_ROW_CAP) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Too many licence rows in one request.']);
                    exit;
                }
                $licenceRows = [];
                foreach ($lTypes as $i => $t) {
                    $t = trim((string)$t);
                    if ($t === '' || $t === 'none') { continue; }
                    $licenceRows[] = [
                        'licence_type'   => $t,
                        'licence_number' => (string)($lNums[$i]   ?? ''),
                        'expires_at'     => (string)($lExps[$i]   ?? ''),
                        'is_active'      => !empty($lActive[$i]) ? 1 : 0,
                        'notes'          => (string)($lNotes[$i]  ?? ''),
                    ];
                }
                if ($licenceRows) {
                    $licenceResults = orgAdminApplyLicenceRows($db, $newOrgId, $licenceRows);
                }
            }

            /* Members (optional) — each row validated: role must be a real
               ORG_MEMBER_ROLES value, and the user id must name a real,
               ACTIVE tblUsers row (never trust a client-claimed picker id —
               the picker's own resolved-pick guard is UX only). Failures
               are reported per-row, never fatal to the whole request. */
            $memberResults = [];
            $mUserIds = (array)($_POST['member_user_id'] ?? []);
            $mRoles   = (array)($_POST['member_role']    ?? []);
            /* Security audit F2 — row-count DoS cap (IHYMNS_ORG_WIZARD_ROW_CAP,
               includes/organisation_admin.php), same reasoning as the licence-row
               cap above. */
            if (count($mUserIds) > IHYMNS_ORG_WIZARD_ROW_CAP) {
                http_response_code(422);
                echo json_encode(['error' => 'Too many member rows in one request.']);
                exit;
            }
            foreach ($mUserIds as $i => $rawUserId) {
                $memberUserId = (int)$rawUserId;
                $memberRole   = (string)($mRoles[$i] ?? 'member');
                if ($memberUserId <= 0) { continue; }
                if (!in_array($memberRole, $MEMBER_ROLES, true)) {
                    $memberResults[] = ['userId' => $memberUserId, 'ok' => false, 'error' => 'Unknown member role.'];
                    continue;
                }
                $uStmt = $db->prepare('SELECT DisplayName, Username FROM tblUsers WHERE Id = ? AND IsActive = 1');
                $uStmt->bind_param('i', $memberUserId);
                $uStmt->execute();
                $uRow = $uStmt->get_result()->fetch_assoc();
                $uStmt->close();
                if (!$uRow) {
                    $memberResults[] = ['userId' => $memberUserId, 'ok' => false, 'error' => 'User not found or inactive.'];
                    continue;
                }
                orgAdminMemberAdd($db, $newOrgId, $memberUserId, $memberRole);
                logActivity('org.member_add', 'organisation', (string)$newOrgId, [
                    'user_id' => $memberUserId, 'role' => $memberRole, 'via' => 'wizard',
                ]);
                $memberResults[] = [
                    'userId' => $memberUserId, 'ok' => true,
                    'label'  => $uRow['DisplayName'] ?: $uRow['Username'],
                ];
            }

            echo json_encode([
                'ok'       => true,
                'id'       => $newOrgId,
                'slug'     => $fields['slug'],
                'name'     => $fields['name'],
                'licences' => $licenceResults,
                'members'  => $memberResults,
            ]);
        } catch (\Throwable $e) {
            error_log('[organisations wizard_create_organisation] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not create organisation.']);
        }
        exit;
    }

    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    try {
        switch ($action) {
            case 'create': {
                /* #1996 (rule #22) — re-pointed onto the SAME
                   orgAdminValidateCreate()/orgAdminCreate() core the new
                   `wizard_create_organisation` branch above and the new
                   admin_organisation_create API twin call — the field
                   parsing, the #1986 finer-gate force, the registry check,
                   the slug pre-check and the INSERT + PhysicalCity UPDATE
                   are all byte-identical to the pre-#1996 inline version;
                   only the SQL's home moved (includes/organisation_admin.php). */
                [$fields, $err, $errStatus, $errField] = orgAdminValidateCreate($db, $_POST, $canEditOrgLicences);
                if ($err !== null) {
                    $error = $err;
                    break;
                }
                try {
                    $created = orgAdminCreate($db, $fields);
                } catch (OrgAdminDuplicateSlugException $e) {
                    $error = $e->getMessage();
                    break;
                }
                $newOrgId = $created['id'];
                logActivity('org.create', 'organisation', (string)$newOrgId, [
                    'name'           => $fields['name'],
                    'slug'           => $fields['slug'],
                    'parent_org_id'  => $fields['parent'],
                    'licence_type'   => $fields['licenceType'],
                    'is_active'      => (bool)$fields['active'],
                ]);
                $success = "Organisation '{$fields['name']}' created.";
                break;
            }

            case 'update': {
                $id          = (int)($_POST['id'] ?? 0);
                $name        = trim((string)($_POST['name']         ?? ''));
                $slug        = $slugify((string)($_POST['slug']     ?? ''));
                $parent      = (int)($_POST['parent_org_id']        ?? 0);
                $desc        = trim((string)($_POST['description']  ?? ''));
                $licenceType = (string)($_POST['licence_type']      ?? 'none');
                $licenceNum  = trim((string)($_POST['licence_number'] ?? ''));
                $active      = !empty($_POST['is_active']) ? 1 : 0;
                $physicalCity   = trim((string)($_POST['physical_city']    ?? '')) ?: null;
                $physicalCityId = (int)($_POST['physical_city_id'] ?? 0) ?: null;
                if ($id <= 0) { $error = 'Organisation id missing.'; break; }
                if ($name === '') { $error = 'Name is required.'; break; }
                if ($slug === '') { $error = 'Slug is required.'; break; }
                if (!in_array($licenceType, $LICENCE_TYPE_KEYS, true)) { $error = 'Unknown licence type.'; break; }
                if ($parent === $id) { $error = 'An organisation cannot be its own parent.'; break; }

                $stmt = $db->prepare('SELECT Id FROM tblOrganisations WHERE Slug = ? AND Id <> ?');
                $stmt->bind_param('si', $slug, $id);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = 'That slug is already in use.'; break; }

                /* Capture before-row for the audit diff (#535). */
                $beforeStmt = $db->prepare(
                    'SELECT Name, Slug, ParentOrgId, Description, LicenceType, LicenceNumber, IsActive
                       FROM tblOrganisations WHERE Id = ?'
                );
                $beforeStmt->bind_param('i', $id);
                $beforeStmt->execute();
                $beforeOrg = $beforeStmt->get_result()->fetch_assoc() ?: null;
                $beforeStmt->close();

                /* #462's intent, finally wired (#1590 follow-up).
                   ELI5: if you are not allowed to change licences, your edit
                   keeps whatever licence the organisation already had.
                   Detail: `manage_org_licences` was registered by #462 alongside
                   `manage_user_licences` and `view_licence_audit` so that
                   "licence edits can be delegated without granting full org
                   admin" — its own words. The other two got endpoints; this one
                   never did, because the licence fields share ONE form and ONE
                   UPDATE with Name/Slug/Parent/Description, so there was no
                   separate handler to hang it on. Splitting the FIELDS rather
                   than the statement is what makes the entitlement real.
                   PRESERVE, do not reject: a curator without the licence
                   permission editing a description must not have their save
                   refused, and must not silently blank the licence either. The
                   before-row read just above already has the current values. */
                if (!$canEditOrgLicences) {
                    $licenceType = (string)($beforeOrg['LicenceType']   ?? 'none');
                    $licenceNum  = (string)($beforeOrg['LicenceNumber'] ?? '');
                }

                $stmt = $db->prepare(
                    'UPDATE tblOrganisations
                        SET Name = ?, Slug = ?, ParentOrgId = ?, Description = ?,
                            LicenceType = ?, LicenceNumber = ?, IsActive = ?
                      WHERE Id = ?'
                );
                /* Types: Name(s), Slug(s), ParentOrgId(i nullable),
                   Description(s), LicenceType(s), LicenceNumber(s),
                   IsActive(i), Id(i). */
                $parentOrNull = $parent ?: null;
                $stmt->bind_param(
                    'ssisssii',
                    $name, $slug, $parentOrNull, $desc, $licenceType, $licenceNum, $active, $id
                );
                $stmt->execute();
                $stmt->close();

                /* Place columns — schema-tolerant separate UPDATE. */
                if (placeColumnExists($db, 'tblOrganisations', 'PhysicalCityId')) {
                    $stmt = $db->prepare(
                        'UPDATE tblOrganisations
                            SET PhysicalCity = ?, PhysicalCityId = ?
                          WHERE Id = ?'
                    );
                    $stmt->bind_param('sii', $physicalCity, $physicalCityId, $id);
                    $stmt->execute();
                    $stmt->close();
                }

                /* #1770 §4.7 — leader-idle ORG layer, schema-tolerant separate
                   UPDATE (same pattern as the place columns immediately above).
                   An EMPTY minutes field means "no override" (NULL — the
                   resolver then falls through to the user/app layers); a
                   value is clamped to the SAME [5,240] band the resolver
                   clamps to. `EnforceIdleTimeout` is only meaningful
                   alongside a non-NULL minutes value, but is stored as
                   submitted regardless — serviceMode_resolveIdleTimeoutMins()
                   already ignores Enforce on a NULL-minutes row (its query
                   filters `LiveIdleTimeoutMins IS NOT NULL`), so a stray
                   enforce-with-no-value can never do anything. */
                if (serviceMode_orgIdleColumnsExist($db)) {
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
                    $stmt->bind_param('iii', $idleMinsVal, $idleEnforceVal, $id);
                    $stmt->execute();
                    $stmt->close();
                }

                /* #1969 — multi-licence sync via the shared core, NON-
                   DESTRUCTIVELY (orgLicenceSyncSet keeps every staying row's
                   number/expiry/active/notes — the old DELETE-all + re-INSERT
                   here wiped all of that on every save). Only run when the user
                   may edit licences; a curator without the entitlement must not
                   be able to change the licence set at all (the primary fields
                   above are already preserved for them). The per-licence
                   metadata (number/expiry/active/notes) is edited in the grid
                   below the form via the `licence_change` action. */
                if ($canEditOrgLicences) {
                    $picked = array_map('strval', (array)($_POST['additional_licences'] ?? []));
                    orgLicenceSyncSet($db, $id, $picked, $licenceType, $licenceNum, true);
                }

                if ($beforeOrg !== null) {
                    $afterOrg = [
                        'Name' => $name, 'Slug' => $slug,
                        'ParentOrgId' => $parent ?: null, 'Description' => $desc,
                        'LicenceType' => $licenceType, 'LicenceNumber' => $licenceNum,
                        'IsActive' => $active,
                    ];
                    $changed = [];
                    foreach ($afterOrg as $k => $v) {
                        if ((string)($beforeOrg[$k] ?? '') !== (string)$v) $changed[] = $k;
                    }
                    logActivity('org.edit', 'organisation', (string)$id, [
                        'fields' => $changed,
                        'before' => array_intersect_key($beforeOrg, array_flip($changed)),
                        'after'  => array_intersect_key($afterOrg,  array_flip($changed)),
                    ]);
                }

                $success = "Organisation updated.";
                break;
            }

            /* #1969 — edit ONE licence row's metadata (number / expiry /
               active / notes) via the shared core. Which licence TYPES the org
               holds is managed by the "Additional licences" checkboxes on the
               update form above; this grid (rendered below the form) edits the
               details of each held row. `manage_org_licences`-gated. */
            case 'licence_change': {
                if (!$canEditOrgLicences) {
                    $error = 'You do not have permission to edit organisation licences.';
                    break;
                }
                $orgId     = (int)($_POST['id'] ?? $_POST['org_id'] ?? 0);
                $licenceId = (int)($_POST['licence_id'] ?? 0);
                if ($orgId <= 0 || $licenceId <= 0) { $error = 'Invalid licence row.'; break; }

                $res = orgLicenceUpdateById($db, $orgId, $licenceId, $_POST);
                if (!$res['ok']) {
                    $error = $res['error'] ?? 'Could not update the licence.';
                    break;
                }
                logActivity('admin.organisations.licence_change', 'organisation', (string)$orgId, [
                    'licence_id' => $licenceId,
                ]);
                $success = 'Licence updated.';
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);

                $stmt = $db->prepare('SELECT Name FROM tblOrganisations WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') { $error = 'Organisation not found.'; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisationMembers WHERE OrgId = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $members = (int)($row[0] ?? 0);
                if ($members > 0) { $error = "Cannot delete '{$name}': {$members} member(s) still listed."; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisations WHERE ParentOrgId = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $children = (int)($row[0] ?? 0);
                if ($children > 0) { $error = "Cannot delete '{$name}': {$children} sub-organisation(s) still reference it as parent."; break; }

                $stmt = $db->prepare('DELETE FROM tblOrganisations WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                logActivity('org.delete', 'organisation', (string)$id, ['name' => $name]);
                $success = "Organisation '{$name}' deleted.";
                break;
            }

            case 'add_member': {
                $orgId  = (int)($_POST['org_id'] ?? 0);
                $userId = (int)($_POST['user_id'] ?? 0);
                $role   = (string)($_POST['member_role'] ?? 'member');
                if ($orgId <= 0 || $userId <= 0) { $error = 'Invalid request.'; break; }
                if (!in_array($role, $MEMBER_ROLES, true)) { $error = 'Unknown member role.'; break; }

                /* #1996 (rule #22) — re-pointed onto the SAME
                   orgAdminMemberAdd() core the API's
                   admin_organisation_member_add action now also calls;
                   byte-identical 3-column upsert. */
                orgAdminMemberAdd($db, $orgId, $userId, $role);
                logActivity('org.member_add', 'organisation', (string)$orgId, [
                    'user_id' => $userId,
                    'role'    => $role,
                ]);
                $success = 'Member added / updated.';
                break;
            }

            case 'update_member_role': {
                $orgId  = (int)($_POST['org_id'] ?? 0);
                $userId = (int)($_POST['user_id'] ?? 0);
                $role   = (string)($_POST['member_role'] ?? 'member');
                if ($orgId <= 0 || $userId <= 0) { $error = 'Invalid request.'; break; }
                if (!in_array($role, $MEMBER_ROLES, true)) { $error = 'Unknown member role.'; break; }

                $stmt = $db->prepare('UPDATE tblOrganisationMembers SET Role = ? WHERE OrgId = ? AND UserId = ?');
                $stmt->bind_param('sii', $role, $orgId, $userId);
                $stmt->execute();
                $stmt->close();
                logActivity('org.member_role_change', 'organisation', (string)$orgId, [
                    'user_id' => $userId,
                    'role'    => $role,
                ]);
                $success = 'Member role updated.';
                break;
            }

            case 'remove_member': {
                $orgId  = (int)($_POST['org_id'] ?? 0);
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($orgId <= 0 || $userId <= 0) { $error = 'Invalid request.'; break; }
                $stmt = $db->prepare('DELETE FROM tblOrganisationMembers WHERE OrgId = ? AND UserId = ?');
                $stmt->bind_param('ii', $orgId, $userId);
                $stmt->execute();
                $stmt->close();
                logActivity('org.member_remove', 'organisation', (string)$orgId, [
                    'user_id' => $userId,
                ]);
                $success = 'Member removed.';
                break;
            }

            /* #1830 — organisation logos. Same field-name shape as the
               member cases above (org_id/kind hidden fields), a classic
               full-page form POST under this page's own validateCsrf()
               gate (rule #29 doesn't apply — no long-lived AJAX here). */
            case 'logo_upload': {
                $orgId = (int)($_POST['org_id'] ?? 0);
                $kind  = (string)($_POST['kind'] ?? '');
                if ($orgId <= 0 || !in_array($kind, ihymnsOrgLogoKindKeys(), true)) {
                    $error = 'Invalid request.';
                    break;
                }
                /* #1840 — the variant slot this upload targets; defaults to
                   'default' so the pre-#1840 upload form (no `variant`
                   field) keeps working byte-identically. Re-validated here
                   even though orgLogoUpsert() validates again (rule #5). */
                $variant = (string)($_POST['variant'] ?? 'default');
                if (!in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)) {
                    $error = 'Invalid request.';
                    break;
                }
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
                    orgLogoUpsert($db, $orgId, $kind, $variant, $staged, $altText, (int)($currentUser['id'] ?? 0));
                    logActivity('org.logo_upload', 'organisation', (string)$orgId, [
                        'kind' => $kind, 'variant' => $variant, 'mime' => $staged['mime'], 'bytes' => $staged['byteSize'],
                    ]);
                    $success = $variant === 'default' ? 'Logo uploaded.' : 'Theme version uploaded.';
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage(); // plain-English, safe to show verbatim (§4.4)
                }
                break;
            }

            case 'logo_remove': {
                $orgId = (int)($_POST['org_id'] ?? 0);
                $kind  = (string)($_POST['kind'] ?? '');
                if ($orgId <= 0 || !in_array($kind, ihymnsOrgLogoKindKeys(), true)) {
                    $error = 'Invalid request.';
                    break;
                }
                $variant = (string)($_POST['variant'] ?? 'default');
                if (!in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)) {
                    $error = 'Invalid request.';
                    break;
                }
                /* #1840 — removing the DEFAULT row cascades to its light/dark
                   theme versions too; an explicit light/dark row removes
                   just that one. */
                if ($variant === 'default') {
                    orgLogoDeleteKindAll($db, $orgId, $kind);
                } else {
                    orgLogoDelete($db, $orgId, $kind, $variant);
                }
                logActivity('org.logo_remove', 'organisation', (string)$orgId, ['kind' => $kind, 'variant' => $variant]);
                $success = $variant === 'default' ? 'Logo removed.' : 'Theme version removed.';
                break;
            }

            case 'logo_toggle': {
                $orgId = (int)($_POST['org_id'] ?? 0);
                $kind  = (string)($_POST['kind'] ?? '');
                if ($orgId <= 0 || !in_array($kind, ihymnsOrgLogoKindKeys(), true)) {
                    $error = 'Invalid request.';
                    break;
                }
                $active = !empty($_POST['active']);
                /* #1840 — kind-level toggle: one visibility switch per ASSET
                   (every variant together), never a half-hidden kind. */
                orgLogoSetActiveKind($db, $orgId, $kind, $active);
                logActivity('org.logo_toggle', 'organisation', (string)$orgId, ['kind' => $kind, 'active' => $active]);
                $success = $active ? 'Logo shown again.' : 'Logo hidden.';
                break;
            }

            case 'brand_save': {
                /* #1840 §4.3 — org brand colour, system-admin surface.
                   Column-existence-gated (rule #19). Same field-name shape
                   as the logo_* cases above. */
                $orgId = (int)($_POST['org_id'] ?? 0);
                if ($orgId <= 0) {
                    $error = 'Invalid request.';
                    break;
                }
                if (!orgBrandColumnsExist($db)) {
                    $error = 'Not available on this environment yet.';
                    break;
                }
                $rawColour  = (string)($_POST['brand_colour'] ?? '');
                $normalised = ihymnsOrgBrandColourNormalise($rawColour);
                if ($normalised === false) {
                    $error = "That doesn't look like a colour code — use the picker or a value like #6a1b9a.";
                    break;
                }
                orgSetBrandColour($db, $orgId, $normalised);
                logActivity('org.brand_save', 'organisation', (string)$orgId, ['colour' => $normalised]);
                $success = $normalised === null ? 'Brand colour cleared.' : 'Brand colour saved.';
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/organisations.php] ' . $e->getMessage());
        /* Mirror to Activity Log so admins can see why an org-edit
           save failed without server-log access (#695). */
        logActivityError('admin.organisations.save', 'organisation',
            (string)($_POST['id'] ?? ''), $e, [
                'action' => $_POST['action'] ?? null,
            ]);
        $error = $error ?: 'Database error — check server logs for details.';
    }
}

/* ----- Fetch list ----- */
$orgs = [];
try {
    $stmt = $db->prepare(
        'SELECT o.*, p.Name AS ParentName,
                (SELECT COUNT(*) FROM tblOrganisationMembers WHERE OrgId = o.Id) AS MemberCount
           FROM tblOrganisations o
           LEFT JOIN tblOrganisations p ON p.Id = o.ParentOrgId
          ORDER BY o.Name ASC'
    );
    $stmt->execute();
    $orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/organisations.php] ' . $e->getMessage());
    logActivityError('admin.organisations.list', 'organisation', '', $e);
    $error = $error ?: 'Could not load organisations.';
}

/* Edit mode */
$editOrg      = null;
$editMembers  = [];
$candidates   = [];
$editLicences     = [];              /* keys present in tblOrganisationLicences (#640) */
$editLicencesFull = [];              /* #1969 — the full rows for the per-row metadata grid */
$multiLicenceTableExists = false;    /* Cached so the listing render can decide quickly */
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    try {
        $stmt = $db->prepare('SELECT * FROM tblOrganisations WHERE Id = ?');
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editOrg = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        /* #1969 — pre-load the multi-licence rows via the shared core (empty on
           an un-migrated install). `$editLicences` (the checkbox "checked"
           state) is now every type the org holds — active or not — so a
           held-but-inactive licence still shows ticked, with its Active flag
           editable in the grid below; `$editLicencesFull` carries the rows for
           that grid. */
        if (orgLicenceTableExists($db)) {
            $editLicencesFull = orgLicenceList($db, $editId);
            $editLicences     = array_column($editLicencesFull, 'LicenceType');
            $multiLicenceTableExists = true;
        }

        if ($editOrg) {
            $stmt = $db->prepare(
                'SELECT u.Id, u.Username, u.DisplayName, u.Role AS SystemRole,
                        m.Role AS OrgRole, m.JoinedAt
                   FROM tblOrganisationMembers m
                   JOIN tblUsers u ON u.Id = m.UserId
                  WHERE m.OrgId = ?
                  ORDER BY m.JoinedAt DESC'
            );
            $stmt->bind_param('i', $editId);
            $stmt->execute();
            $editMembers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $stmt = $db->prepare(
                'SELECT u.Id, u.Username, u.DisplayName
                   FROM tblUsers u
                   LEFT JOIN tblOrganisationMembers m ON m.UserId = u.Id AND m.OrgId = ?
                  WHERE u.IsActive = 1 AND m.UserId IS NULL
                  ORDER BY u.Username ASC
                  LIMIT 500'
            );
            $stmt->bind_param('i', $editId);
            $stmt->execute();
            $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } catch (\Throwable $e) {
        error_log('[manage/organisations.php] ' . $e->getMessage());
        logActivityError('admin.organisations.edit_load', 'organisation',
            (string)$editId, $e);
    }
}

$csrf = csrfToken();

/* #1996 — guided "New Organisation + licence" wizard seed data + the
   cache-busted import path for the shared stepper module (js/modules/
   admin-wizard.js, #1992) — same filemtime-as-version-query pattern
   head-libs.php uses for every other admin JS load, and the SAME shape
   external-link-types.php (#1992) / songbooks.php (#1993) / venues.php
   (#1995) already use. */
$_adminWizardPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'admin-wizard.js';
$adminWizardVer   = is_file($_adminWizardPath) ? (string)filemtime($_adminWizardPath) : '1';
/* Seeded slug set for the wizard's live availability check — same
   shape/purpose as songbooks.php's window._iHymnsSongbookWizard.abbrs
   seed: derived from the SAME $orgs this page already fetched, never a
   second query. The server (orgAdminValidateCreate()'s own uniqueness
   pre-check + the INSERT's own UNIQUE-key race guard) stays authoritative
   either way — this is a same-page nicety, not the source of truth. */
$wizardSlugs = array_column($orgs, 'Slug');
/* Licence-type options for the wizard's repeatable Licences step,
   EXCLUDING the 'none' sentinel (a row in this step IS a real licence —
   'none' has no meaning as a repeatable row; the picker step is simply
   omitted/left empty for "no licence"). Same registry $LICENCE_TYPES
   already built above (page ~L76), just filtered. */
$wizardLicenceTypes = array_filter($LICENCE_TYPES, static fn(string $k): bool => $k !== 'none', ARRAY_FILTER_USE_KEY);
/* #1969 — whether the multi-licence join table exists on THIS install
   (independent of whether any org happens to have rows in it yet, unlike
   $multiLicenceTableExists above which is only ever set inside the
   edit-mode branch). Un-migrated installs degrade the wizard's Licences
   step to a single primary type+number row with no "add another" control —
   additional rows would only ever no-op against orgLicenceUpsert(). */
$wizardLicenceTableReady = orgLicenceTableExists($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organisations — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
            <div>
                <h1 class="h4 mb-2"><i aria-hidden="true" class="bi bi-building me-2"></i>Organisations</h1>
                <p class="text-secondary small mb-0">
                    Add and edit organisations (churches and groups), manage who belongs to each one,
                    and keep their licence details up to date.
                </p>
            </div>
            <?php /* #1996 — the guided-wizard trigger, list view only (the manual
                     "Add an organisation" form below is likewise only shown when
                     !$editOrg). */ ?>
            <?php if (!$editOrg): ?>
                <button type="button" class="btn btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#orgWizardModal">
                    <i aria-hidden="true" class="bi bi-magic me-1"></i>New organisation (guided)
                </button>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$editOrg): ?>

            <div class="card-admin p-3 mb-4">
                <h2 class="h6 mb-3">All organisations</h2>
                <table class="table table-sm mb-0 align-middle cp-sortable admin-table-responsive">
                    <thead>
                        <tr class="text-muted small">
                            <th scope="col" data-sort-key="name"    data-sort-type="text">Name</th>
                            <th scope="col" data-sort-key="slug"    data-sort-type="text">Slug</th>
                            <th scope="col" data-sort-key="parent"  data-sort-type="text">Parent</th>
                            <th scope="col" data-sort-key="licence" data-sort-type="text">Licence</th>
                            <th scope="col" class="text-center" data-sort-key="active"  data-sort-type="text">Active</th>
                            <th scope="col" class="text-center" data-sort-key="members" data-sort-type="number">Members</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orgs as $o): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($o['Name']) ?></strong></td>
                                <td><code class="small"><?= htmlspecialchars($o['Slug']) ?></code></td>
                                <td class="text-muted small"><?= htmlspecialchars($o['ParentName'] ?? '—') ?></td>
                                <td>
                                    <?php
                                    $_ltKey = (string)$o['LicenceType'];
                                    $_ltLabel = $LICENCE_TYPES[$_ltKey]['label'] ?? $_ltKey;
                                    $_ltDesc  = $LICENCE_TYPES[$_ltKey]['description'] ?? '';
                                    ?>
                                    <span class="badge bg-secondary" style="font-size: 0.7rem"
                                          title="<?= htmlspecialchars($_ltDesc) ?>"><?= htmlspecialchars($_ltLabel) ?></span>
                                    <?php if ($o['LicenceNumber']): ?>
                                        <small class="text-muted ms-1"><?= htmlspecialchars($o['LicenceNumber']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" data-sort-value="<?= (int)$o['IsActive'] ?>">
                                    <?= (int)$o['IsActive'] ? '<i aria-hidden="true" class="bi bi-check-circle text-success"></i>' : '<i aria-hidden="true" class="bi bi-x-circle text-muted"></i>' ?>
                                </td>
                                <td class="text-center"><?= (int)$o['MemberCount'] ?></td>
                                <td class="text-end">
                                    <a href="?edit=<?= (int)$o['Id'] ?>" class="btn btn-sm btn-outline-info" title="Edit and manage members"
                                       aria-label="Edit and manage members of <?= htmlspecialchars($o['Name'], ENT_QUOTES) ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <?php if ((int)$o['MemberCount'] === 0): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete organisation <?= htmlspecialchars($o['Name'], ENT_QUOTES) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$o['Id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete (empty org)"
                                                    aria-label="Delete organisation <?= htmlspecialchars($o['Name'], ENT_QUOTES) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Org has members — remove them first"
                                                aria-label="Delete organisation <?= htmlspecialchars($o['Name'], ENT_QUOTES) ?> — disabled, it has members, remove them first"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$orgs): ?>
                            <tr><td colspan="7" class="p-0 border-0">
                                <?php /* #1999 — empty-state "Get started" launcher, nested under
                                         this card's own "All organisations" <h2> (hence headingTag
                                         'h3' — keeps the heading outline sequential, rule WCAG
                                         2.4.6). $error-aware: on a load failure there is nothing to
                                         "get started" with, so fall back to a plain note. */ ?>
                                <?php if (!$error): ?>
                                    <?= ihymns_wizard_empty_state([
                                        'icon'        => 'bi-building',
                                        'heading'     => 'No organisations yet',
                                        'body'        => 'Organisations group members and licences — add your first one to get started.',
                                        'modalId'     => 'orgWizardModal',
                                        'buttonLabel' => 'New organisation (guided)',
                                        'wrap'        => 'bare',
                                        'hint'        => 'Prefer to type it yourself? Use the manual "Add an organisation" form below.',
                                        'headingTag'  => 'h3',
                                    ]) ?>
                                <?php else: ?>
                                    <span class="text-muted text-center d-block py-4">No organisations to show.</span>
                                <?php endif; ?>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" class="card-admin p-3 mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-plus-circle me-2"></i>Add an organisation</h2>
                <div class="row g-2 mb-2">
                    <div class="col-sm-5">
                        <label class="form-label small" for="create-org-name">Name</label>
                        <input type="text" name="name" id="create-org-name" class="form-control form-control-sm" maxlength="255" required>
                    </div>
                    <div class="col-sm-3">
                        <?= ihymns_slug_advanced_field([
                            'value'       => '',
                            'maxlength'   => 100,
                            'placeholder' => 'auto',
                            'small'       => true,
                        ]) ?>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="create-org-parent">Parent organisation</label>
                        <select name="parent_org_id" id="create-org-parent" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            <?php foreach ($orgs as $o): ?>
                                <option value="<?= (int)$o['Id'] ?>"><?= htmlspecialchars($o['Name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="create-org-description">Description</label>
                    <input type="text" name="description" id="create-org-description" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="create-physical-city">Physical city <small class="text-muted">(optional)</small></label>
                    <input type="text" id="create-physical-city" name="physical_city"
                           class="form-control form-control-sm js-place-search"
                           maxlength="255"
                           placeholder="Start typing — e.g. Brisbane, Queensland">
                    <input type="hidden" id="create-physical-city-id" name="physical_city_id" value="">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-4">
                        <label class="form-label small" for="create-org-licence-type">Licence type</label>
                        <?php /* Disabled, not hidden, when the admin lacks
                           manage_org_licences: a control that vanishes teaches
                           nobody why. A disabled <select> submits nothing, and
                           the handler independently preserves/defaults the
                           value, so the UI and the server agree without the
                           form being the thing enforcing it. */ ?>
                        <select name="licence_type" id="create-org-licence-type" class="form-select form-select-sm"
                                <?= $canEditOrgLicences ? '' : 'disabled' ?>>
                            <?php foreach ($LICENCE_TYPES as $key => $info): ?>
                                <option value="<?= htmlspecialchars($key) ?>"
                                        title="<?= htmlspecialchars($info['description']) ?>">
                                    <?= htmlspecialchars($info['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="create-org-licence-number">Licence number</label>
                        <input type="text" name="licence_number" id="create-org-licence-number" class="form-control form-control-sm" maxlength="100"
                               <?= $canEditOrgLicences ? '' : 'disabled' ?>>
                    </div>
                    <div class="col-sm-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="new-is-active" value="1" checked>
                            <label class="form-check-label" for="new-is-active">Active</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-amber-solid btn-sm mt-2">
                    <i aria-hidden="true" class="bi bi-plus me-1"></i>Create organisation
                </button>
            </form>

        <?php else: ?>

            <div class="mb-3">
                <a href="/manage/organisations" class="btn btn-sm btn-outline-secondary">
                    <i aria-hidden="true" class="bi bi-arrow-left me-1"></i>Back to organisation list
                </a>
            </div>

            <form method="POST" class="card-admin p-3 mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$editOrg['Id'] ?>">
                <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-sliders me-2"></i>Settings — <?= htmlspecialchars($editOrg['Name']) ?></h2>
                <div class="row g-2 mb-2">
                    <div class="col-sm-5">
                        <label class="form-label small" for="edit-org-name">Name</label>
                        <input type="text" name="name" id="edit-org-name" class="form-control form-control-sm" maxlength="255" required
                               value="<?= htmlspecialchars($editOrg['Name']) ?>">
                    </div>
                    <div class="col-sm-3">
                        <?php /* #1870 — the client `required` attribute is deliberately
                                 DROPPED here: a `required` control inside a *closed*
                                 <details> blocks form submit with browser focus aimed at
                                 an invisible field. The server's own 'Slug is required.'
                                 check (organisations.php's update handler) already
                                 answers the empty case — status/server-truth over a
                                 client-side claim, rule #35's spirit. No other slug
                                 input in this partial's callers carries `required`. */ ?>
                        <?= ihymns_slug_advanced_field([
                            'value'     => $editOrg['Slug'],
                            'maxlength' => 100,
                            'small'     => true,
                        ]) ?>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="edit-org-parent">Parent organisation</label>
                        <select name="parent_org_id" id="edit-org-parent" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            <?php foreach ($orgs as $o): ?>
                                <?php if ((int)$o['Id'] === (int)$editOrg['Id']) continue; /* no self-parent */ ?>
                                <option value="<?= (int)$o['Id'] ?>" <?= (int)$o['Id'] === (int)$editOrg['ParentOrgId'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($o['Name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="edit-org-description">Description</label>
                    <input type="text" name="description" id="edit-org-description" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($editOrg['Description']) ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="edit-physical-city">Physical city <small class="text-muted">(optional)</small></label>
                    <input type="text" id="edit-physical-city" name="physical_city"
                           class="form-control form-control-sm js-place-search"
                           maxlength="255"
                           placeholder="Start typing — e.g. Brisbane, Queensland"
                           value="<?= htmlspecialchars((string)($editOrg['PhysicalCity'] ?? '')) ?>">
                    <input type="hidden" id="edit-physical-city-id" name="physical_city_id"
                           value="<?= isset($editOrg['PhysicalCityId']) && $editOrg['PhysicalCityId'] !== null ? (int)$editOrg['PhysicalCityId'] : '' ?>">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-4">
                        <label class="form-label small" for="edit-org-licence-type">Primary licence</label>
                        <select name="licence_type" id="edit-org-licence-type" class="form-select form-select-sm"
                                <?= $canEditOrgLicences ? '' : 'disabled' ?>>
                            <?php foreach ($LICENCE_TYPES as $key => $info): ?>
                                <option value="<?= htmlspecialchars($key) ?>"
                                        title="<?= htmlspecialchars($info['description']) ?>"
                                        <?= $editOrg['LicenceType'] === $key ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($info['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small" for="edit-org-licence-number">Licence number</label>
                        <input type="text" name="licence_number" id="edit-org-licence-number" class="form-control form-control-sm" maxlength="100"
                               value="<?= htmlspecialchars($editOrg['LicenceNumber']) ?>"
                               <?= $canEditOrgLicences ? '' : 'disabled' ?>>
                    </div>
                    <div class="col-sm-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="edit-is-active" value="1" <?= (int)$editOrg['IsActive'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="edit-is-active">Active</label>
                        </div>
                    </div>
                </div>

                <!-- Additional licences (#640). Each org can hold any
                     number of licences alongside the primary — e.g. a
                     church holding both CCLI (lyrics) and MRL (musical
                     notation). Tier resolution unions all of them. The
                     Primary picker above is kept for back-compat with
                     existing tools that read tblOrganisations.LicenceType
                     directly; saving the form syncs primary into the
                     join table too so neither side can drift. -->
                <?php if ($multiLicenceTableExists): ?>
                <div class="mb-2">
                    <label class="form-label small mb-1" id="edit-org-additional-licences-label">Additional licences <small class="text-muted">(beyond the primary above)</small></label>
                    <div class="d-flex flex-wrap gap-3" role="group" aria-labelledby="edit-org-additional-licences-label">
                        <?php foreach ($LICENCE_TYPES as $key => $info): ?>
                            <?php if ($key === 'none') continue; ?>
                            <div class="form-check small">
                                <input class="form-check-input" type="checkbox"
                                       name="additional_licences[]"
                                       value="<?= htmlspecialchars($key) ?>"
                                       id="edit-add-licence-<?= htmlspecialchars($key) ?>"
                                       <?= in_array($key, $editLicences, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="edit-add-licence-<?= htmlspecialchars($key) ?>"
                                       title="<?= htmlspecialchars($info['description']) ?>">
                                    <?= htmlspecialchars($info['label']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text small">
                        Tick every licence the org holds. The org's effective
                        access tier is the max across the primary + every
                        additional licence + every parent-org licence (#636).
                    </div>
                </div>
                <?php endif; ?>

                <!-- #1770 §4.7 — Live Follow leader-idle ORG override. Column-
                     existence-gated so an un-migrated install renders the form
                     without it (rule #19). NULL minutes = "no override", the
                     resolver falls through to the user's own preference, then
                     this org's default, then the site-wide default. -->
                <?php if (serviceMode_orgIdleColumnsExist($db)): ?>
                <div class="mb-2">
                    <label class="form-label small mb-1" id="edit-org-idle-timeout-label">Live Follow idle-timeout override <small class="text-muted">(optional)</small></label>
                    <div class="row g-2 align-items-center" role="group" aria-labelledby="edit-org-idle-timeout-label">
                        <div class="col-sm-4">
                            <input type="number" name="live_idle_timeout_mins" id="edit-org-idle-timeout-mins" class="form-control form-control-sm"
                                   min="<?= LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES ?>" max="<?= LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES ?>" step="1"
                                   aria-label="Idle-timeout minutes override"
                                   placeholder="site default"
                                   value="<?= isset($editOrg['LiveIdleTimeoutMins']) && $editOrg['LiveIdleTimeoutMins'] !== null ? (int)$editOrg['LiveIdleTimeoutMins'] : '' ?>">
                        </div>
                        <div class="col-sm-8">
                            <div class="form-check small mb-0">
                                <input class="form-check-input" type="checkbox" name="enforce_idle_timeout" id="edit-enforce-idle" value="1"
                                       <?= !empty($editOrg['EnforceIdleTimeout']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="edit-enforce-idle">
                                    Lock this value — members' own Settings preference is ignored
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="form-text small">
                        A worship leader's "Go Live" session auto-closes after this many minutes
                        of no genuine leader interaction. Leave blank to use the site default
                        (<?= LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES ?>&ndash;<?= LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES ?> minutes).
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-amber-solid btn-sm mt-2">
                    <i aria-hidden="true" class="bi bi-save me-1"></i>Save settings
                </button>
            </form>

            <!-- #1969 — per-licence METADATA grid. Which licence TYPES the org
                 holds is chosen by the "Additional licences" checkboxes in the
                 Settings form above (synced non-destructively); THIS grid edits
                 the details of each held licence — number, expiry, active flag,
                 notes. A SEPARATE form per row (licence_change), sibling to the
                 Settings form above, because HTML forbids nested forms. Gated on
                 the manage_org_licences entitlement + the table existing. The
                 same shared core (includes/org_licence_admin.php) backs the
                 member self-service editor on /manage/my-organisations. -->
            <?php if ($multiLicenceTableExists && $canEditOrgLicences && !empty($editLicencesFull)): ?>
            <div class="card-admin p-3 mb-3">
                <h3 class="h6 mb-2"><i aria-hidden="true" class="bi bi-award me-2"></i>Licence details</h3>
                <p class="text-muted small mb-2">Edit the number, expiry, active state and notes for each licence the organisation holds. Add or remove licence <em>types</em> with the “Additional licences” checkboxes in Settings above.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-dark mb-0 small align-middle admin-table-responsive">
                        <thead><tr>
                            <th scope="col">Type</th><th scope="col">Number</th><th scope="col">Expires</th><th scope="col">Active</th><th scope="col">Notes</th><th scope="col" class="text-end"></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($editLicencesFull as $l): ?>
                            <tr>
                                <td><code><?= htmlspecialchars((string)$l['LicenceType']) ?></code></td>
                                <td colspan="5">
                                    <form method="POST" class="d-inline-flex align-items-center gap-1 flex-wrap">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="licence_change">
                                        <input type="hidden" name="org_id" value="<?= (int)$editOrg['Id'] ?>">
                                        <input type="hidden" name="licence_id" value="<?= (int)($l['Id'] ?? 0) ?>">
                                        <span class="text-muted small" aria-hidden="true">№</span>
                                        <input type="text" name="licence_number"
                                               class="form-control form-control-sm py-0"
                                               value="<?= htmlspecialchars((string)$l['LicenceNumber']) ?>"
                                               title="Licence number" aria-label="Licence number for the <?= htmlspecialchars((string)$l['LicenceType'], ENT_QUOTES) ?> licence"
                                               placeholder="Licence number" maxlength="100" style="width: 10rem;">
                                        <span class="text-muted small" aria-hidden="true">Expires</span>
                                        <input type="date" name="expires_at"
                                               class="form-control form-control-sm py-0"
                                               value="<?= htmlspecialchars(substr((string)($l['ExpiresAt'] ?? ''), 0, 10)) ?>"
                                               title="Licence expiry date" aria-label="Expiry date for the <?= htmlspecialchars((string)$l['LicenceType'], ENT_QUOTES) ?> licence"
                                               style="width: 9rem;">
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                                   id="lic-active-<?= (int)($l['Id'] ?? 0) ?>"
                                                   <?= !empty($l['IsActive']) ? 'checked' : '' ?>
                                                   aria-label="The <?= htmlspecialchars((string)$l['LicenceType'], ENT_QUOTES) ?> licence is active">
                                            <label class="form-check-label small" for="lic-active-<?= (int)($l['Id'] ?? 0) ?>">active</label>
                                        </div>
                                        <input type="text" name="notes"
                                               class="form-control form-control-sm py-0"
                                               value="<?= htmlspecialchars((string)($l['Notes'] ?? '')) ?>"
                                               title="Notes" aria-label="Notes for the <?= htmlspecialchars((string)$l['LicenceType'], ENT_QUOTES) ?> licence"
                                               placeholder="Notes" maxlength="255" style="width: 11rem;">
                                        <button type="submit" class="btn btn-sm btn-outline-info py-0 px-2" title="Save"
                                                aria-label="Save <?= htmlspecialchars((string)$l['LicenceType'], ENT_QUOTES) ?> licence changes">
                                            <i class="bi bi-check2" aria-hidden="true"></i> Save
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- #1840 §4.3 — org brand colour (Share Card Option B). A SEPARATE
                 form/action (brand_save) rather than folded into the "Save
                 settings" form above — mirrors the logo_* cases' own
                 standalone-form shape. Column-existence-gated (rule #19). -->
            <?php if (orgBrandColumnsExist($db)):
                $orgBrandColourVal = (string)($editOrg['BrandColor'] ?? '');
            ?>
            <div class="card-admin p-3 mb-3">
                <h3 class="h6 mb-2"><i aria-hidden="true" class="bi bi-palette me-2"></i>Brand colour</h3>
                <form method="POST" class="row g-2 align-items-end small">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="brand_save">
                    <input type="hidden" name="org_id" value="<?= (int)$editOrg['Id'] ?>">
                    <div class="col-md-6">
                        <?php
                            /* Local vars consumed by the shared partial's
                               documented contract — never a hand-rolled
                               <input type="color"> here. */
                            $name        = 'brand_colour';
                            $value       = $orgBrandColourVal;
                            $idPrefix    = 'edit-brand-colour';
                            $label       = 'Brand colour';
                            $placeholder = '#6a1b9a';
                            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'colour-picker.php';
                        ?>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-sm btn-amber-solid">
                            <i aria-hidden="true" class="bi bi-save me-1"></i>Save
                        </button>
                    </div>
                </form>
                <?php if ($orgBrandColourVal !== ''): ?>
                <form method="POST" class="d-inline mt-1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="brand_save">
                    <input type="hidden" name="org_id" value="<?= (int)$editOrg['Id'] ?>">
                    <input type="hidden" name="brand_colour" value="">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Clear brand colour</button>
                </form>
                <?php endif; ?>
                <p class="text-muted small mb-0">
                    Used where iHymns shows this organisation's branding — for example the
                    coloured band on shared set-list preview images.
                </p>
            </div>
            <?php endif; ?>

            <?php /* #1830 — renders '' (nothing) on an un-migrated install (rule #19). */
                  echo orgLogoRenderAdminCard($db, (int)$editOrg['Id'], $csrf); ?>

            <div class="row g-3">
                <div class="col-md-7">
                    <div class="card-admin p-3 h-100">
                        <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-people me-2"></i>Members (<?= count($editMembers) ?>)</h2>
                        <?php if (!$editMembers): ?>
                            <p class="text-muted small mb-0">No members yet.</p>
                        <?php else: ?>
                            <table class="table table-sm align-middle mb-0 cp-sortable admin-table-responsive">
                                <thead>
                                    <tr class="text-muted small">
                                        <th scope="col" data-sort-key="user" data-sort-type="text">User</th>
                                        <th scope="col" data-sort-key="role" data-sort-type="text">Role</th>
                                        <th scope="col" data-sort-key="joined" data-sort-type="text">Joined</th>
                                        <th scope="col" class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($editMembers as $m): ?>
                                        <tr>
                                            <td data-sort-value="<?= htmlspecialchars($m['Username'], ENT_QUOTES) ?>">
                                                <code><?= htmlspecialchars($m['Username']) ?></code>
                                                <small class="text-muted ms-1"><?= htmlspecialchars($m['DisplayName']) ?></small>
                                            </td>
                                            <td data-sort-value="<?= htmlspecialchars((string)$m['OrgRole'], ENT_QUOTES) ?>">
                                                <form method="POST" class="d-flex gap-1">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action" value="update_member_role">
                                                    <input type="hidden" name="org_id"  value="<?= (int)$editOrg['Id'] ?>">
                                                    <input type="hidden" name="user_id" value="<?= (int)$m['Id'] ?>">
                                                    <select name="member_role" class="form-select form-select-sm" onchange="this.form.submit()">
                                                        <?php foreach ($MEMBER_ROLES as $mr): ?>
                                                            <option value="<?= $mr ?>" <?= $m['OrgRole'] === $mr ? 'selected' : '' ?>><?= $mr ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </form>
                                            </td>
                                            <td class="text-muted small" data-sort-value="<?= htmlspecialchars((string)$m['JoinedAt'], ENT_QUOTES) ?>"><?= htmlspecialchars(substr((string)$m['JoinedAt'], 0, 10)) ?></td>
                                            <td class="text-end">
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove <?= htmlspecialchars($m['Username'], ENT_QUOTES) ?> from this organisation?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action"  value="remove_member">
                                                    <input type="hidden" name="org_id"  value="<?= (int)$editOrg['Id'] ?>">
                                                    <input type="hidden" name="user_id" value="<?= (int)$m['Id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                                            aria-label="Remove <?= htmlspecialchars($m['Username'], ENT_QUOTES) ?> from this organisation"><i class="bi bi-x" aria-hidden="true"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="card-admin p-3 h-100">
                        <h2 class="h6 mb-3"><i aria-hidden="true" class="bi bi-person-plus me-2"></i>Add a member</h2>
                        <?php if (!$candidates): ?>
                            <p class="text-muted small mb-0">Every active user is already a member.</p>
                        <?php else: ?>
                            <form method="POST" class="d-flex gap-2 flex-wrap">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action"     value="add_member">
                                <input type="hidden" name="org_id"     value="<?= (int)$editOrg['Id'] ?>">
                                <select name="user_id" class="form-select form-select-sm" style="min-width: 180px;" required>
                                    <option value="">— pick a user —</option>
                                    <?php foreach ($candidates as $u): ?>
                                        <option value="<?= (int)$u['Id'] ?>">
                                            <?= htmlspecialchars($u['Username']) ?>
                                            <?php if ($u['DisplayName']): ?> — <?= htmlspecialchars($u['DisplayName']) ?><?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="member_role" class="form-select form-select-sm" style="width: 8rem;">
                                    <?php foreach ($MEMBER_ROLES as $mr): ?>
                                        <option value="<?= $mr ?>"><?= $mr ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-amber-solid" aria-label="Add selected user to this organisation">
                                    <i class="bi bi-plus" aria-hidden="true"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>


    <!-- Sortable table headers (#644). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php if ($editOrg && orgBrandColumnsExist($db)): ?>
    <!-- #1840 — swatch<->hex two-way binding for the Brand colour field
         above, shared with songbooks.php rather than a bespoke handler. -->
    <script type="module">
        import { bootColourPickers } from '/js/modules/colour-picker.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/colour-picker.js') ?>';
        bootColourPickers();
    </script>
    <?php endif; ?>

    <!-- Live location autocomplete on the Physical city inputs
         (both create form + edit form). Powered by /manage/places-api.php
         (Photon + Nominatim) with tblPlaces upsert on pick. -->
    <script src="/js/modules/place-search.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/place-search.js') ?>"></script>
    <script>
        (function () {
            if (!window.iHymnsPlaceSearch) return;
            [
                ['create-physical-city', 'create-physical-city-id'],
                ['edit-physical-city',   'edit-physical-city-id'],
            ].forEach(([inputId, hiddenId]) => {
                const visible = document.getElementById(inputId);
                const hidden  = document.getElementById(hiddenId);
                if (visible && hidden) {
                    window.iHymnsPlaceSearch.attach(visible, { hiddenIdInput: hidden });
                }
            });
        })();
    </script>

    <?php /* =========================================================================
             #1996 — guided "New Organisation + licence" wizard: the Bootstrap-modal
             markup, the seed data, and the wizard's own wiring. Built on the shared
             stepper (js/modules/admin-wizard.js, #1992) — mirrors
             manage/external-link-types.php's (#1992) / manage/songbooks.php's
             (#1993) wizard shape for the server round-trip (ONE page JSON action,
             `wizard_create_organisation`, does the org create + licence rows +
             member rows server-side — unlike venues.php's #1995 wizard, which
             client-orchestrates three PRE-EXISTING api.php actions; here the
             capability is brand-new, so it lives behind ONE new endpoint, per
             rule #22's "extract first"). Additive only — the manual "Add an
             organisation" form + the multi-licence grid + the member-management
             forms above are byte-identical, untouched by this block. Every
             wizard input is id-prefixed `orgwiz-*` and carries NO `name=`
             attribute (rule #43 shape) — the JS below assembles the POST body
             itself, so a wizard field can never accidentally get swept up by
             the classic forms' own submits. */ ?>
    <script>
        /* Seed data for the wizard — derived from what this page-load already
           fetched/computed server-side (rule: never a second query from JS).
           JSON_HEX_* flags mirror every other admin-wizard seed's emit shape
           (songbooks.php / external-link-types.php) so this blob sits safely
           inside an inline <script>. */
        window._iHymnsOrgWizard = {
            csrf: <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            slugs: <?= json_encode(array_values($wizardSlugs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            canEditOrgLicences: <?= $canEditOrgLicences ? 'true' : 'false' ?>,
            licenceTableReady: <?= $wizardLicenceTableReady ? 'true' : 'false' ?>,
            licenceTypes: <?= json_encode(array_map(
                static fn(string $k, array $info): array => ['key' => $k, 'label' => $info['label']],
                array_keys($wizardLicenceTypes), array_values($wizardLicenceTypes)
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            memberRoles: <?= json_encode(array_values($MEMBER_ROLES), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
        };
    </script>

    <div class="modal fade" id="orgWizardModal" tabindex="-1" aria-hidden="true" aria-labelledby="orgWizardModalLabel" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" id="orgWizardRoot">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0" id="orgWizardModalLabel">New organisation — guided</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="orgwiz-steps-wrap">
                        <div data-wiz-progress class="mb-3"></div>

                        <!-- Step — Organisation -->
                        <section data-wiz-step data-wiz-label="Organisation">
                            <h3 data-wiz-heading class="h6 mb-3">1. The organisation</h3>
                            <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                            <div class="row g-3 mb-2">
                                <div class="col-sm-7">
                                    <label class="form-label" for="orgwiz-name">Name</label>
                                    <input type="text" class="form-control" id="orgwiz-name" maxlength="255" placeholder="e.g. Grace Community Church" aria-required="true">
                                </div>
                                <div class="col-sm-5">
                                    <label class="form-label" for="orgwiz-slug">Slug</label>
                                    <input type="text" class="form-control" id="orgwiz-slug" maxlength="100" autocomplete="off" aria-required="true" aria-describedby="orgwiz-slug-status">
                                    <div class="form-text small" id="orgwiz-slug-status" role="status">Derived from the name — edit it if you'd rather choose your own.</div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label" for="orgwiz-parent">Parent organisation <span class="text-muted">(optional)</span></label>
                                <select class="form-select" id="orgwiz-parent">
                                    <option value="">— None —</option>
                                    <?php foreach ($orgs as $o): ?>
                                        <option value="<?= (int)$o['Id'] ?>"><?= htmlspecialchars($o['Name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label" for="orgwiz-description">Description <span class="text-muted">(optional)</span></label>
                                <input type="text" class="form-control" id="orgwiz-description" maxlength="500">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="orgwiz-physical-city">Physical city <span class="text-muted">(optional)</span></label>
                                <input type="text" class="form-control" id="orgwiz-physical-city" autocomplete="off" placeholder="Start typing — e.g. Brisbane, Queensland">
                                <input type="hidden" id="orgwiz-physical-city-id" value="">
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="orgwiz-active" checked>
                                <label class="form-check-label" for="orgwiz-active">Active</label>
                            </div>
                        </section>

                        <!-- Step — Licences (ONLY rendered when the caller holds
                             manage_org_licences — the #1986 finer gate; the
                             SERVER independently forces licence_type='none' when
                             this entitlement is absent, so this DOM omission is
                             UX only, never the actual gate — see
                             includes/organisation_admin.php's doc-block). -->
                        <?php if ($canEditOrgLicences): ?>
                        <section data-wiz-step data-wiz-label="Licences" hidden>
                            <h3 data-wiz-heading class="h6 mb-3">2. Licences <span class="text-muted small">(optional)</span></h3>
                            <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                            <p class="text-secondary small">
                                The first licence you add becomes the organisation's primary licence. You can hold
                                several at once — e.g. CCLI for lyrics and MRL for musical notation.
                            </p>
                            <?php if (!$wizardLicenceTableReady): ?>
                                <div class="alert alert-secondary small mb-3">
                                    <i aria-hidden="true" class="bi bi-info-circle me-1"></i>Only a single primary licence
                                    is available on this environment yet — the multi-licence table isn't migrated.
                                </div>
                            <?php endif; ?>
                            <div class="vstack gap-2" id="orgwiz-licence-rows"></div>
                            <?php if ($wizardLicenceTableReady): ?>
                                <button type="button" class="btn btn-outline-info btn-sm mt-2" id="orgwiz-add-licence">
                                    <i aria-hidden="true" class="bi bi-plus-lg me-1"></i>Add licence
                                </button>
                            <?php endif; ?>
                        </section>
                        <?php endif; ?>

                        <!-- Step — Members (optional) -->
                        <section data-wiz-step data-wiz-label="Members" hidden>
                            <h3 data-wiz-heading class="h6 mb-3"><?= $canEditOrgLicences ? '3' : '2' ?>. Members <span class="text-muted small">(optional)</span></h3>
                            <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                            <p class="text-secondary small">
                                Add existing iHymns users as members now, or skip this and add them later from the
                                organisation's settings page.
                            </p>
                            <div class="vstack gap-2" id="orgwiz-member-rows"></div>
                            <button type="button" class="btn btn-outline-info btn-sm mt-2" id="orgwiz-add-member">
                                <i aria-hidden="true" class="bi bi-plus-lg me-1"></i>Add member
                            </button>
                        </section>

                        <!-- Step — Review -->
                        <section data-wiz-step data-wiz-label="Review" hidden>
                            <h3 data-wiz-heading class="h6 mb-3"><?= $canEditOrgLicences ? '4' : '3' ?>. Review &amp; create</h3>
                            <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                            <dl class="row small mb-0" id="orgwiz-review-summary"></dl>
                        </section>
                    </div>

                    <div id="orgwiz-done" hidden>
                        <h3 tabindex="-1" id="orgwiz-done-heading" class="h6 mb-3">Organisation created</h3>
                        <div id="orgwiz-done-body" class="small"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-wiz-back hidden>Back</button>
                    <button type="button" class="btn btn-amber" data-wiz-next>Next</button>
                    <button type="button" class="btn btn-amber" id="orgwiz-done-close" data-bs-dismiss="modal" hidden>Close</button>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
    /* #1996 — guided "New Organisation + licence" wizard wiring, built on the
       shared stepper (js/modules/admin-wizard.js). Domain logic only — the
       framework itself knows nothing about organisations (module doc-block).
       Transport is a single fetch() to this page's own new JSON action
       (`wizard_create_organisation`), mirroring external-link-types.php's
       `wizard_create_type` shape — a bare fetch + X-Requested-With, since
       this page's whole POST handler is NOT already validateCsrfRequest()-
       gated (unlike songbooks.php). */
    import { createWizard } from '/js/modules/admin-wizard.js?v=<?= htmlspecialchars($adminWizardVer, ENT_QUOTES) ?>';

    (function () {
        'use strict';
        const modalEl = document.getElementById('orgWizardModal');
        if (!modalEl) { return; }

        const seed = window._iHymnsOrgWizard || {};
        const csrfToken = seed.csrf || '';
        const seededSlugs = Array.isArray(seed.slugs) ? seed.slugs : [];
        const canEditOrgLicences = !!seed.canEditOrgLicences;
        const licenceTableReady = !!seed.licenceTableReady;
        const licenceTypes = Array.isArray(seed.licenceTypes) ? seed.licenceTypes : [];
        const memberRoles = Array.isArray(seed.memberRoles) && seed.memberRoles.length ? seed.memberRoles : ['member'];

        /* Step indices — DERIVED at boot from which steps the SERVER chose to
           render (the Licences step is entirely absent from the DOM when
           !canEditOrgLicences), never hardcoded, so validateStep()/
           onStepChange()/showStepError() below stay correct either way. */
        const STEP_ORG = 0;
        const STEP_LIC = canEditOrgLicences ? 1 : -1;
        const STEP_MEM = canEditOrgLicences ? 2 : 1;
        const STEP_REVIEW = canEditOrgLicences ? 3 : 2;
        const LAST_STEP = STEP_REVIEW;

        const stepsWrap  = document.getElementById('orgwiz-steps-wrap');
        const doneEl     = document.getElementById('orgwiz-done');
        const doneBodyEl = document.getElementById('orgwiz-done-body');
        const nextBtn    = modalEl.querySelector('[data-wiz-next]');
        const backBtn    = modalEl.querySelector('[data-wiz-back]');
        const doneCloseBtn = document.getElementById('orgwiz-done-close');

        const nameInput   = document.getElementById('orgwiz-name');
        const slugInput   = document.getElementById('orgwiz-slug');
        const slugStatusEl = document.getElementById('orgwiz-slug-status');
        const parentSelect = document.getElementById('orgwiz-parent');
        const descInput   = document.getElementById('orgwiz-description');
        const placeInput  = document.getElementById('orgwiz-physical-city');
        const placeIdInput = document.getElementById('orgwiz-physical-city-id');
        const activeEl    = document.getElementById('orgwiz-active');

        const licenceRowsEl = document.getElementById('orgwiz-licence-rows');
        const addLicenceBtn = document.getElementById('orgwiz-add-licence');
        const memberRowsEl  = document.getElementById('orgwiz-member-rows');
        const addMemberBtn  = document.getElementById('orgwiz-add-member');

        const reviewSummary = document.getElementById('orgwiz-review-summary');

        let slugManuallyEdited = false;
        let licenceRowSeq = 0;
        let memberRowSeq = 0;
        const state = { orgId: 0 };

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function slugify(s) {
            return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 100);
        }

        /* ---- Name -> Slug auto-derive + live uniqueness (mirrors
           songbooks.php's abbrValidate()/checkAbbr() shape — the seeded set
           is a same-page nicety, the server stays authoritative either
           way). ---- */
        function checkSlug() {
            const v = slugInput.value.trim();
            if (!slugStatusEl) { return { ok: v !== '' }; }
            if (v === '') {
                slugStatusEl.textContent = 'Derived from the name — edit it if you\'d rather choose your own.';
                slugStatusEl.className = 'form-text small';
                return { ok: false, message: 'Slug is required.' };
            }
            if (!/^[a-z0-9-]+$/.test(v)) {
                slugStatusEl.textContent = '❌ Lowercase letters, numbers and hyphens only.';
                slugStatusEl.className = 'form-text small text-danger-emphasis';
                return { ok: false, message: 'Slug must be lowercase letters, digits or hyphens.' };
            }
            if (seededSlugs.some((s) => String(s).toLowerCase() === v)) {
                slugStatusEl.textContent = '❌ That slug is already in use.';
                slugStatusEl.className = 'form-text small text-danger-emphasis';
                return { ok: false, message: 'That slug is already in use — pick another.' };
            }
            slugStatusEl.textContent = '✅ Available.';
            slugStatusEl.className = 'form-text small text-success-emphasis';
            return { ok: true };
        }
        nameInput.addEventListener('input', function () {
            if (!slugManuallyEdited) {
                slugInput.value = slugify(nameInput.value);
                checkSlug();
            }
        });
        slugInput.addEventListener('input', function () {
            slugManuallyEdited = true;
            checkSlug();
        });

        /* ---- location typeahead — the SAME shared module + attach shape
           the manual create/edit forms below already use; place-search.js is
           already loaded on this page, never re-loaded here. ---- */
        if (window.iHymnsPlaceSearch && placeInput && placeIdInput) {
            window.iHymnsPlaceSearch.attach(placeInput, { hiddenIdInput: placeIdInput });
        }

        /* ---- Licences: repeatable rows (#1969 multi shape), mirroring
           external-link-types.php's addPatternRow()/readPatternRows() shape.
           When the multi-licence table isn't migrated, exactly ONE row is
           ever rendered, with no expiry/active/notes fields and no
           add/remove controls (the "single primary type+number only"
           degrade). ---- */
        function licenceTypeOptionsHtml(selected) {
            let html = '<option value="">— Select a licence —</option>';
            licenceTypes.forEach(function (t) {
                html += '<option value="' + escapeHtml(t.key) + '"' + (t.key === selected ? ' selected' : '') + '>' + escapeHtml(t.label) + '</option>';
            });
            return html;
        }
        function addLicenceRow() {
            licenceRowSeq += 1;
            /* a11y audit F1/F12 — a real id per repeatable row, minted from
               the SAME licenceRowSeq counter already used for
               data-wiz-licence-row, so every control below gets a genuine
               label[for] (never just a visually-styled-but-aria-hidden
               <label>, which names nothing) and every row's Remove button
               gets a name distinct from every other row's. */
            const seq = licenceRowSeq;
            const row = document.createElement('div');
            row.className = 'card bg-secondary-subtle border-secondary';
            row.setAttribute('data-wiz-licence-row', String(seq));
            if (licenceTableReady) {
                /* Template-literal HTML (not the file's usual '...' + '...'
                   concatenation) so each id="…" is a genuine ${seq}
                   interpolation — test-a11y-static-checks.php's static
                   duplicate-id scanner already special-cases exactly this
                   shape (the SAME one manage/print-templates.php's dynamic
                   option-row builder uses) as "per-instance unique by
                   construction", the same way it already excludes a
                   PHP-loop-built id; a plain '...' + seq + '...'
                   concatenation reads as ONE static literal id to that
                   scanner and false-positives as a duplicate the moment a
                   second row is added. */
                row.innerHTML = `<div class="card-body py-2">
                    <div class="row g-2 align-items-center">
                      <div class="col-md-4"><label class="form-label small mb-0" for="orgwiz-lic-type-${seq}">Type</label>
                        <select class="form-select form-select-sm" data-wiz-lic-type id="orgwiz-lic-type-${seq}">${licenceTypeOptionsHtml('')}</select></div>
                      <div class="col-md-3"><label class="form-label small mb-0" for="orgwiz-lic-number-${seq}">Number</label>
                        <input type="text" class="form-control form-control-sm" data-wiz-lic-number id="orgwiz-lic-number-${seq}" maxlength="100" placeholder="Licence number"></div>
                      <div class="col-md-3"><label class="form-label small mb-0" for="orgwiz-lic-expires-${seq}">Expires</label>
                        <input type="date" class="form-control form-control-sm" data-wiz-lic-expires id="orgwiz-lic-expires-${seq}"></div>
                      <div class="col-md-2 d-flex align-items-center gap-1 mt-3">
                        <div class="form-check small mb-0"><input class="form-check-input" type="checkbox" data-wiz-lic-active id="orgwiz-lic-active-${seq}" checked><label class="form-check-label" for="orgwiz-lic-active-${seq}">Active</label></div>
                      </div>
                    </div>
                    <div class="row g-2 mt-1">
                      <div class="col-md-9"><label class="visually-hidden" for="orgwiz-lic-notes-${seq}">Notes</label>
                        <input type="text" class="form-control form-control-sm" data-wiz-lic-notes id="orgwiz-lic-notes-${seq}" maxlength="255" placeholder="Notes (optional)"></div>
                      <div class="col-md-3 text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-wiz-lic-remove aria-label="Remove licence row ${seq}"><i aria-hidden="true" class="bi bi-x-lg"></i> Remove</button></div>
                    </div>
                  </div>`;
                row.querySelector('[data-wiz-lic-remove]').addEventListener('click', function () { row.remove(); });
            } else {
                row.innerHTML = `<div class="card-body py-2">
                    <div class="row g-2 align-items-center">
                      <div class="col-md-6"><label class="form-label small mb-0" for="orgwiz-lic-type-${seq}">Type</label>
                        <select class="form-select form-select-sm" data-wiz-lic-type id="orgwiz-lic-type-${seq}">${licenceTypeOptionsHtml('')}</select></div>
                      <div class="col-md-6"><label class="form-label small mb-0" for="orgwiz-lic-number-${seq}">Number</label>
                        <input type="text" class="form-control form-control-sm" data-wiz-lic-number id="orgwiz-lic-number-${seq}" maxlength="100" placeholder="Licence number"></div>
                    </div>
                  </div>`;
            }
            licenceRowsEl.appendChild(row);
            return row;
        }
        function readLicenceRows() {
            return Array.from(licenceRowsEl.querySelectorAll('[data-wiz-licence-row]')).map(function (row) {
                const typeEl = row.querySelector('[data-wiz-lic-type]');
                const numberEl = row.querySelector('[data-wiz-lic-number]');
                const expiresEl = row.querySelector('[data-wiz-lic-expires]');
                const activeEl2 = row.querySelector('[data-wiz-lic-active]');
                const notesEl = row.querySelector('[data-wiz-lic-notes]');
                return {
                    type: typeEl ? typeEl.value : '',
                    number: numberEl ? numberEl.value.trim() : '',
                    expiresAt: expiresEl ? expiresEl.value : '',
                    active: activeEl2 ? activeEl2.checked : true,
                    notes: notesEl ? notesEl.value.trim() : '',
                };
            }).filter(function (r) { return r.type !== ''; });
        }
        if (canEditOrgLicences) {
            if (addLicenceBtn) { addLicenceBtn.addEventListener('click', function () { addLicenceRow(); }); }
            if (!licenceTableReady) { addLicenceRow(); } /* the one always-present degraded row */
        }

        /* ---- Members: repeatable rows, each a resolved-user picker (SAME
           api2 user_search endpoint + resolved-pick guard as groups.php's
           own Add-a-member picker, rule #43) + a role select. ---- */
        function memberRoleOptionsHtml() {
            let html = '';
            memberRoles.forEach(function (r) {
                html += '<option value="' + escapeHtml(r) + '"' + (r === 'member' ? ' selected' : '') + '>' + escapeHtml(r) + '</option>';
            });
            return html;
        }
        function addMemberRow() {
            memberRowSeq += 1;
            /* a11y audit F1/F12 — real id per row from memberRowSeq (same
               reasoning as addLicenceRow() above): a genuine label[for] for
               User/Role, and a Remove name distinct per row rather than the
               same "Remove this member row" repeated for every one. */
            const seq = memberRowSeq;
            const row = document.createElement('div');
            row.className = 'card bg-secondary-subtle border-secondary';
            row.setAttribute('data-wiz-member-row', String(seq));
            /* Template-literal HTML for the SAME reason addLicenceRow()
               documents above — a real ${seq} interpolation, not a
               '...' + seq + '...' concatenation that reads as one static
               literal id to test-a11y-static-checks.php's duplicate-id
               scanner. */
            row.innerHTML = `<div class="card-body py-2">
                <div class="row g-2 align-items-center">
                  <div class="col-md-6"><label class="form-label small mb-0" for="orgwiz-mem-name-${seq}">User</label>
                    <input type="text" class="form-control form-control-sm" data-wiz-mem-name id="orgwiz-mem-name-${seq}" autocomplete="off" placeholder="Start typing a name or username…"></div>
                  <div class="col-md-4"><label class="form-label small mb-0" for="orgwiz-mem-role-${seq}">Role</label>
                    <select class="form-select form-select-sm" data-wiz-mem-role id="orgwiz-mem-role-${seq}">${memberRoleOptionsHtml()}</select></div>
                  <div class="col-md-2 text-end mt-3"><button type="button" class="btn btn-sm btn-outline-danger" data-wiz-mem-remove aria-label="Remove member row ${seq}"><i aria-hidden="true" class="bi bi-x-lg"></i></button></div>
                </div>
              </div>`;
            const nameEl = row.querySelector('[data-wiz-mem-name]');
            const hiddenId = document.createElement('input');
            hiddenId.type = 'hidden';
            hiddenId.setAttribute('data-wiz-mem-id', '');
            row.appendChild(hiddenId);
            if (window.iHymnsPlaceSearch && nameEl) {
                window.iHymnsPlaceSearch.attach(nameEl, {
                    hiddenIdInput: hiddenId,
                    minChars: 2,
                    pickMode: 'value',
                    noun: { singular: 'user', plural: 'users' },
                    searchUrl: (q) => '/manage/editor/api2?action=user_search&q=' + encodeURIComponent(q) + '&limit=10',
                    parseResults: (d) => (d.suggestions || []).map((s) => ({ id: s.id, display_name: s.label, hint: s.hint || '' })),
                });
            }
            row.querySelector('[data-wiz-mem-remove]').addEventListener('click', function () { row.remove(); });
            memberRowsEl.appendChild(row);
            return row;
        }
        if (addMemberBtn) { addMemberBtn.addEventListener('click', function () { addMemberRow(); }); }
        function readMemberRows() {
            return Array.from(memberRowsEl.querySelectorAll('[data-wiz-member-row]')).map(function (row) {
                const nameEl = row.querySelector('[data-wiz-mem-name]');
                const idEl = row.querySelector('[data-wiz-mem-id]');
                const roleEl = row.querySelector('[data-wiz-mem-role]');
                return {
                    typed: nameEl ? nameEl.value.trim() : '',
                    userId: idEl && idEl.value ? parseInt(idEl.value, 10) : 0,
                    role: roleEl ? roleEl.value : 'member',
                };
            });
        }

        /* ---- review ---- */
        function updateReview() {
            if (!reviewSummary) { return; }
            const parentLabel = parentSelect && parentSelect.selectedOptions[0] ? parentSelect.selectedOptions[0].textContent : '— None —';
            let rows = '';
            rows += '<dt class="col-sm-4">Name</dt><dd class="col-sm-8">' + escapeHtml(nameInput.value.trim()) + '</dd>';
            rows += '<dt class="col-sm-4">Slug</dt><dd class="col-sm-8"><code>' + escapeHtml(slugInput.value.trim()) + '</code></dd>';
            rows += '<dt class="col-sm-4">Parent</dt><dd class="col-sm-8">' + escapeHtml(parentLabel) + '</dd>';
            rows += '<dt class="col-sm-4">Active</dt><dd class="col-sm-8">' + (activeEl.checked ? 'Yes' : 'No') + '</dd>';
            if (canEditOrgLicences) {
                const lic = readLicenceRows();
                rows += '<dt class="col-sm-4">Licences</dt><dd class="col-sm-8">' +
                    (lic.length ? escapeHtml(lic.map((r) => r.type).join(', ')) : 'None') + '</dd>';
            }
            const mem = readMemberRows().filter((m) => m.userId > 0);
            rows += '<dt class="col-sm-4">Members</dt><dd class="col-sm-8">' + (mem.length ? mem.length + ' to add' : 'None') + '</dd>';
            reviewSummary.innerHTML = rows;
        }

        function showStepError(index, message) {
            const panes = modalEl.querySelectorAll('[data-wiz-step]');
            const pane = panes[index];
            if (!pane) { return; }
            const alertEl = pane.querySelector('[data-wiz-alert]');
            if (alertEl) {
                alertEl.hidden = false;
                alertEl.textContent = message;
                alertEl.focus();
            }
        }
        function clearAllStepAlerts() {
            modalEl.querySelectorAll('[data-wiz-alert]').forEach(function (el) { el.hidden = true; el.textContent = ''; });
        }

        /* ---- the wizard itself ------------------------------------------ */
        const wizard = createWizard(modalEl, {
            host: 'bootstrap-modal',
            validateStep: function (index) {
                if (index === STEP_ORG) {
                    if (!nameInput.value.trim()) {
                        return { ok: false, message: 'Name is required.', focus: nameInput };
                    }
                    if (!slugInput.value.trim()) { slugInput.value = slugify(nameInput.value); }
                    const slugResult = checkSlug();
                    if (!slugResult.ok) {
                        return { ok: false, message: slugResult.message || 'Fix the slug before continuing.', focus: slugInput };
                    }
                    return true;
                }
                if (index === STEP_LIC) {
                    const rows = readLicenceRows();
                    const types = rows.map((r) => r.type);
                    const dupes = types.some((t, i) => types.indexOf(t) !== i);
                    if (dupes) {
                        return { ok: false, message: 'Each licence row must be a different type.' };
                    }
                    return true;
                }
                if (index === STEP_MEM) {
                    const rows = readMemberRows();
                    for (const r of rows) {
                        if (r.typed !== '' && r.userId <= 0) {
                            return { ok: false, message: 'Pick a user from the search results for every row (or remove it).' };
                        }
                    }
                    const ids = rows.filter((r) => r.userId > 0).map((r) => r.userId);
                    if (ids.some((id, i) => ids.indexOf(id) !== i)) {
                        return { ok: false, message: 'The same user is listed more than once.' };
                    }
                    return true;
                }
                if (index === STEP_REVIEW) {
                    updateReview();
                    return true;
                }
                return true;
            },
            onStepChange: function (from, to) {
                if (nextBtn) { nextBtn.textContent = (to === LAST_STEP) ? 'Create organisation' : 'Next'; }
                if (to === LAST_STEP) { updateReview(); }
            },
            onFinish: save,
        });

        const FIELD_TO_STEP = { name: STEP_ORG, slug: STEP_ORG, licence_type: (STEP_LIC >= 0 ? STEP_LIC : STEP_ORG) };

        function collectFormBody() {
            const body = new URLSearchParams();
            body.set('action', 'wizard_create_organisation');
            body.set('csrf_token', csrfToken);
            body.set('name', nameInput.value.trim());
            body.set('slug', slugInput.value.trim());
            if (parentSelect.value) { body.set('parent_org_id', parentSelect.value); }
            body.set('description', descInput.value.trim());
            body.set('is_active', activeEl.checked ? '1' : '');
            if (placeInput.value.trim()) { body.set('physical_city', placeInput.value.trim()); }
            if (placeIdInput.value) { body.set('physical_city_id', placeIdInput.value); }

            if (canEditOrgLicences) {
                const licRows = readLicenceRows();
                if (licRows.length > 0) {
                    body.set('licence_type', licRows[0].type);
                    body.set('licence_number', licRows[0].number);
                }
                /* Distinct `licence_row_*[]` key names — see the matching
                   server-side comment in the wizard_create_organisation
                   branch for why these must NOT be `licence_type[]` /
                   `licence_number[]` (PHP would collide those onto the
                   SAME $_POST key as the singular primary fields set
                   above). */
                licRows.forEach(function (r) {
                    body.append('licence_row_type[]', r.type);
                    body.append('licence_row_number[]', r.number);
                    body.append('licence_row_expires_at[]', r.expiresAt);
                    body.append('licence_row_active[]', r.active ? '1' : '');
                    body.append('licence_row_notes[]', r.notes);
                });
            }

            readMemberRows().filter((m) => m.userId > 0).forEach(function (m) {
                body.append('member_user_id[]', String(m.userId));
                body.append('member_role[]', m.role);
            });

            return body;
        }

        function showDonePane(data) {
            if (stepsWrap) { stepsWrap.hidden = true; }
            if (doneEl) { doneEl.hidden = false; }
            if (backBtn) { backBtn.hidden = true; }
            if (nextBtn) { nextBtn.hidden = true; }
            if (doneCloseBtn) { doneCloseBtn.hidden = false; }

            let html = '<p><i aria-hidden="true" class="bi bi-check-circle-fill text-success me-1"></i>Organisation <strong>'
                + escapeHtml(data.name) + '</strong> was created.</p>';

            const licences = Array.isArray(data.licences) ? data.licences : [];
            if (licences.length) {
                html += '<p class="mb-1">Licences:</p><ul class="mb-2">';
                licences.forEach(function (l) {
                    html += '<li>' + (l.ok
                        ? '<i aria-hidden="true" class="bi bi-check-circle text-success me-1"></i>' + escapeHtml(l.type) + ' saved.'
                        : '<i aria-hidden="true" class="bi bi-exclamation-triangle text-warning me-1"></i>' + escapeHtml(l.type) + ' — ' + escapeHtml(l.error || 'could not be saved.')) + '</li>';
                });
                html += '</ul>';
            }

            const members = Array.isArray(data.members) ? data.members : [];
            if (members.length) {
                html += '<p class="mb-1">Members:</p><ul class="mb-2">';
                members.forEach(function (m) {
                    html += '<li>' + (m.ok
                        ? '<i aria-hidden="true" class="bi bi-check-circle text-success me-1"></i>' + escapeHtml(m.label || ('#' + m.userId)) + ' added.'
                        : '<i aria-hidden="true" class="bi bi-exclamation-triangle text-warning me-1"></i>' + '#' + escapeHtml(m.userId) + ' — ' + escapeHtml(m.error || 'could not be added.')) + '</li>';
                });
                html += '</ul>';
            }

            html += '<p class="mb-0"><a href="/manage/organisations?edit=' + encodeURIComponent(data.id) + '">Open organisation settings</a> '
                + 'to add more licences, members, or a logo.</p>';

            if (doneBodyEl) { doneBodyEl.innerHTML = html; }
            const heading = document.getElementById('orgwiz-done-heading');
            if (heading) { heading.focus(); }
        }

        function routeSaveError(status, message, field) {
            if (status === 409) {
                wizard.goTo(STEP_ORG);
                showStepError(STEP_ORG, message);
                return;
            }
            if (status === 400 && field && FIELD_TO_STEP[field] !== undefined) {
                const step = FIELD_TO_STEP[field];
                wizard.goTo(step);
                showStepError(step, message);
                return;
            }
            window.alert(message);
        }

        function save() {
            if (nextBtn) { nextBtn.disabled = true; }
            clearAllStepAlerts();
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: collectFormBody(),
            }).then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; })
                    .catch(function () { return { status: res.status, data: null }; });
            }).then(function (result) {
                if (result.data && result.data.ok) {
                    state.orgId = result.data.id;
                    showDonePane(result.data);
                    return;
                }
                const message = (result.data && result.data.error) || 'Could not create organisation — please retry.';
                const field = result.data && result.data.field;
                routeSaveError(result.status, message, field);
            }).catch(function () {
                window.alert('Could not reach the server. Please try again.');
            }).finally(function () {
                if (nextBtn) { nextBtn.disabled = false; }
            });
        }

        /* Reset to a clean slate every time the modal is opened again —
           including collapsing the DONE pane back to the stepper, and
           reloading the page if a create actually succeeded this session
           (so the freshly-minted organisation shows up in the list below,
           which was rendered server-side before this modal ever opened). */
        modalEl.addEventListener('hidden.bs.modal', function () {
            const shouldReload = state.orgId > 0;
            clearAllStepAlerts();
            nameInput.value = '';
            slugInput.value = '';
            slugManuallyEdited = false;
            if (slugStatusEl) {
                slugStatusEl.textContent = 'Derived from the name — edit it if you\'d rather choose your own.';
                slugStatusEl.className = 'form-text small';
            }
            parentSelect.value = '';
            descInput.value = '';
            placeInput.value = '';
            placeIdInput.value = '';
            activeEl.checked = true;
            licenceRowsEl.innerHTML = '';
            if (canEditOrgLicences && !licenceTableReady) { addLicenceRow(); }
            memberRowsEl.innerHTML = '';
            state.orgId = 0;
            if (stepsWrap) { stepsWrap.hidden = false; }
            if (doneEl) { doneEl.hidden = true; }
            if (backBtn) { backBtn.hidden = true; }
            if (nextBtn) { nextBtn.hidden = false; nextBtn.textContent = 'Next'; nextBtn.disabled = false; }
            if (doneCloseBtn) { doneCloseBtn.hidden = true; }
            wizard.goTo(0);
            if (shouldReload) { window.location.reload(); }
        });
    })();
    </script>
    <?php /* #1996 — guided "New Organisation + licence" wizard: modal + wiring. END */ ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
