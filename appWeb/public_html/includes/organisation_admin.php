<?php

declare(strict_types=1);

/**
 * iHymns — Organisation CREATE admin write core (#1996, rule #22)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/organisations`'s "Add an organisation" form and the NEW guided
 * "New Organisation + licence" wizard on the same page both need to do the
 * exact same thing: check a brand-new organisation's fields are sane, insert
 * the row, optionally attach its licence(s), and optionally add its first
 * member(s). Before #1996 this was ONE hand-typed copy inlined straight into
 * the page's `create` POST case (organisations.php) with NO admin API twin
 * at all — the only existing `organisation_create` API action is a DIFFERENT
 * product (api.php's consumer self-service "create my own org" endpoint,
 * which auto-suffix-dedups the slug, drops licence/active/city entirely, and
 * inserts the caller as owner — deliberately untouched by this file). This
 * is the ONE place the validation + row-write now happens; the page's
 * `create` case, the page's new `wizard_create_organisation` case, and the
 * new `admin_organisation_create` API twin all call it.
 *
 * WHAT MOVED VERBATIM
 * ----------------------------------------------------------------------------
 * `orgAdminValidateCreate()` is `manage/organisations.php`'s pre-#1996
 * `case 'create':` field-parsing block, moved essentially verbatim — the
 * only real change is the read source, `$_POST` → the passed-in `$in`
 * array (the page's own `$_POST`, the wizard's own `$_POST`, and the API's
 * json_decode()'d request body are all plain string-keyed arrays sharing
 * the IDENTICAL snake_case key set, so every caller can pass its raw input
 * straight through unmodified), PLUS threading `$canEditOrgLicences`
 * through as an explicit parameter (see the #1986 note below) rather than
 * reading it off a page-global. `orgAdminCreate()` is the 7-column INSERT +
 * its schema-tolerant PhysicalCity/PhysicalCityId secondary UPDATE, also
 * moved verbatim (the INSERT itself is now wrapped so a 1062 unique-key
 * race — extremely rare, since every caller already pre-checks slug
 * uniqueness in Validate() — throws `OrgAdminDuplicateSlugException` instead
 * of an opaque `mysqli_sql_exception`, closing the pre-check/INSERT TOCTOU
 * window with the SAME friendly message the pre-check already gives; mirrors
 * `SongbookAdminDuplicateAbbreviationException`, includes/songbook_admin.php).
 *
 * THE #1986 FINER GATE — `manage_org_licences`
 * ----------------------------------------------------------------------------
 * `organisations.php`'s licence STEP renders only when the caller holds
 * `manage_org_licences` (page ~L63, `$canEditOrgLicences`) — but that DOM
 * omission is UX only. `orgAdminValidateCreate()` takes `$canEditOrgLicences`
 * as an explicit parameter and, when false, FORCES the primary
 * `licence_type`/`licence_number` to `'none'`/`''` before validating them —
 * the exact same force the page's pre-#1996 `create` case already applied
 * inline. This is the CREATE-time degenerate case of the #1986
 * `admin_organisation_update` "preserve, don't reject" discipline: an UPDATE
 * preserves the org's EXISTING licence for a caller who can't edit it; a
 * brand-new org has no existing licence to preserve, so the un-permitted
 * case is simply "no licence" instead. The SEPARATE multi-licence-row step
 * (`orgAdminApplyLicenceRows()`, below) is gated the SAME way by each
 * caller individually (rule: never call it without checking
 * `$canEditOrgLicences` first — see the two call sites for the explicit
 * `userHasEntitlement('manage_org_licences', ...)` resolution).
 *
 * WHAT STAYS PER-CALLER
 * ----------------------------------------------------------------------------
 * `logActivity()` (different action key / `via` tag per caller), each
 * funnel's own success message / JSON response shape, and — for the wizard
 * + API twin only — the optional multi-licence-row + member-row application
 * (`orgAdminApplyLicenceRows()` / `orgAdminMemberAdd()`), which the manual
 * "Add an organisation" form has no fields for at all (rule #43's
 * find-or-create pickers didn't exist on this create form pre-#1996; the
 * wizard is the first funnel that offers them at CREATE time — a
 * pre-existing gap, not a #1996 regression, tracked in the file doc-block
 * on `manage/organisations.php`'s `create` case).
 *
 * `orgAdminMemberAdd()` is ALSO the one write path for adding a member to an
 * organisation, re-pointing the page's own `add_member` case AND the API's
 * `admin_organisation_member_add` action onto it (verbatim 3-column
 * upsert) — never a third hand-typed copy.
 *
 * @link appWeb/public_html/includes/songbook_admin.php   the #1993 extraction precedent this mirrors
 * @link appWeb/public_html/includes/org_licence_admin.php the ONE licence CRUD core — orgAdminApplyLicenceRows() is a thin loop over its orgLicenceUpsert(), never a fork
 * @link appWeb/public_html/includes/organisation_validation.php  slugifyOrganisationName() / ORG_MEMBER_ROLES
 * @link appWeb/public_html/manage/organisations.php       page consumer — manual "Add an organisation" form + guided wizard
 * @link appWeb/public_html/api.php                        admin_organisation_create API consumer (beside admin_organisation_update)
 * @see #719 #1969 #1986 #1996 rule #22 rule #28 rule #43
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'organisation_validation.php'; /* slugifyOrganisationName() / ORG_MEMBER_ROLES */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licence_registry.php';       /* licenceTypesForPicker() — the ONE licence-type registry (rule #9) */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'places.php';                 /* placeColumnExists() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'org_licence_admin.php';      /* orgLicenceUpsert() — the ONE licence-row write path, never forked here */

/**
 * Thrown by orgAdminCreate() when the INSERT itself hits the Slug
 * UNIQUE-key violation (mysqli errno 1062) — a race between two curators
 * creating the same slug at once, or a caller that skipped
 * orgAdminValidateCreate()'s own pre-check. Callers catch this specifically
 * to respond 409 with the SAME friendly message the pre-check already
 * gives, rather than a generic 500 (closes the pre-check/INSERT TOCTOU
 * window — mirrors SongbookAdminDuplicateAbbreviationException,
 * includes/songbook_admin.php).
 */
final class OrgAdminDuplicateSlugException extends \RuntimeException
{
}

/**
 * Validate + normalise a brand-new organisation's fields, shared by all
 * three create funnels (manual form / guided wizard / API twin). Read-only
 * except for the slug-uniqueness probe (see the class doc-block above for
 * why a race is still possible and how it's closed).
 *
 * @param  array<string,mixed> $in  Raw request fields — the page's own
 *         `$_POST`, the wizard's own `$_POST`, or the API's json_decode()'d
 *         body all share the identical snake_case key set, so any of the
 *         three can be passed straight through unmodified.
 * @param  bool $canEditOrgLicences  The caller's resolved `manage_org_licences`
 *         entitlement (#1986 finer gate — see the file doc-block above).
 *         When false, `licence_type`/`licence_number` are FORCED to
 *         `'none'`/`''` before validation, regardless of what `$in` carries —
 *         a crafted POST can never smuggle a licence past a caller who lacks
 *         this permission.
 * @return array{0:?array<string,mixed>,1:?string,2:int,3:?string}
 *         `[$fields, $error, $httpStatus, $field]` — exactly one of
 *         `$fields`/`$error` is non-null. `$field` (nullable) names which
 *         INPUT the error is about — `'name'`/`'slug'`/`'licence_type'` —
 *         so a JSON caller (the guided wizard / the API twin) can route the
 *         curator back to the right STEP by this structured key rather than
 *         pattern-matching `$error`'s prose (rule #35); the classic page
 *         caller ignores it and just shows `$error`.
 */
function orgAdminValidateCreate(\mysqli $db, array $in, bool $canEditOrgLicences): array
{
    $name        = trim((string)($in['name']            ?? ''));
    $slugInput   = trim((string)($in['slug']             ?? ''));
    $parent      = (int)($in['parent_org_id']            ?? 0);
    $desc        = trim((string)($in['description']      ?? ''));
    $licenceType = (string)($in['licence_type']          ?? 'none');
    $licenceNum  = trim((string)($in['licence_number']   ?? ''));
    $active      = !empty($in['is_active']) ? 1 : 0;
    /* Places adoption — physical address. Verbatim from organisations.php's
       pre-#1996 case 'create'. */
    $physicalCity   = trim((string)($in['physical_city']    ?? '')) ?: null;
    $physicalCityId = (int)($in['physical_city_id'] ?? 0) ?: null;

    if ($name === '') { return [null, 'Name is required.', 400, 'name']; }
    $slug = $slugInput !== '' ? slugifyOrganisationName($slugInput) : slugifyOrganisationName($name);
    if ($slug === '') { return [null, 'Slug could not be derived — supply one explicitly.', 400, 'slug']; }

    /* #1986 finer gate — see the file doc-block "THE #1986 FINER GATE"
       section above. A new organisation has no previous licence to
       preserve, so the un-permitted case is the unlicensed default rather
       than a carried-over value (mirrors organisations.php's pre-#1996
       inline force exactly). */
    if (!$canEditOrgLicences) {
        $licenceType = 'none';
        $licenceNum  = '';
    }
    /* licenceTypesForPicker($db, true) prepends the 'none' sentinel — the
       SAME shape organisations.php's own $LICENCE_TYPE_KEYS uses (page
       ~L76-77), never a hand-rolled key list (rule #9). */
    $licenceKeys = array_keys(licenceTypesForPicker($db, true));
    if (!in_array($licenceType, $licenceKeys, true)) {
        return [null, 'Unknown licence type.', 400, 'licence_type'];
    }

    $stmt = $db->prepare('SELECT Id FROM tblOrganisations WHERE Slug = ?');
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($exists) { return [null, 'That slug is already in use.', 409, 'slug']; }

    return [[
        'name' => $name, 'slug' => $slug, 'parent' => $parent ?: null, 'desc' => $desc,
        'licenceType' => $licenceType, 'licenceNum' => $licenceNum, 'active' => $active,
        'physicalCity' => $physicalCity, 'physicalCityId' => $physicalCityId,
    ], null, 200, null];
}

/**
 * THE ONE CREATOR — inserts a brand-new `tblOrganisations` row plus its
 * schema-tolerant PhysicalCity/PhysicalCityId secondary UPDATE (#1996).
 * Caller has already validated via orgAdminValidateCreate() (so `$fields`
 * is the validated/normalised shape that function returns).
 *
 * @param  array<string,mixed> $fields  The validated shape from
 *         orgAdminValidateCreate().
 * @return array{id:int}
 * @throws OrgAdminDuplicateSlugException on a uq_Slug race (1062) —
 *         belt-and-braces on top of the pre-check in orgAdminValidateCreate().
 */
function orgAdminCreate(\mysqli $db, array $fields): array
{
    $name         = (string)$fields['name'];
    $slug         = (string)$fields['slug'];
    $parentOrNull = $fields['parent'];
    $desc         = (string)$fields['desc'];
    $licenceType  = (string)$fields['licenceType'];
    $licenceNum   = (string)$fields['licenceNum'];
    $active       = (int)$fields['active'];

    $stmt = $db->prepare(
        'INSERT INTO tblOrganisations
            (Name, Slug, ParentOrgId, Description, LicenceType, LicenceNumber, IsActive)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    /* Types: Name(s), Slug(s), ParentOrgId(i nullable), Description(s),
       LicenceType(s), LicenceNumber(s), IsActive(i). mysqli passes NULL
       correctly when the bound variable is null.
       Wrapped so a uq_Slug race (1062 — two curators racing the SAME slug
       past the Validate() pre-check) is reported the SAME friendly way the
       pre-check already is, instead of an opaque mysqli_sql_exception
       (#1996 — closes the pre-check/INSERT TOCTOU, mirrors
       songbookAdminCreate()'s identical pattern). */
    try {
        $stmt->bind_param(
            'ssisssi',
            $name, $slug, $parentOrNull, $desc, $licenceType, $licenceNum, $active
        );
        $stmt->execute();
    } catch (\mysqli_sql_exception $e) {
        $stmt->close();
        if ((int)$e->getCode() === 1062) {
            throw new OrgAdminDuplicateSlugException('That slug is already in use.', 409, $e);
        }
        throw $e;
    }
    $newOrgId = (int)$db->insert_id;
    $stmt->close();

    /* Place columns — schema-tolerant separate UPDATE. Skipped on
       pre-places-adoption-migration installs (probe returns false). */
    if (placeColumnExists($db, 'tblOrganisations', 'PhysicalCityId')) {
        $physicalCity   = $fields['physicalCity'];
        $physicalCityId = $fields['physicalCityId'];
        $stmt = $db->prepare(
            'UPDATE tblOrganisations
                SET PhysicalCity = ?, PhysicalCityId = ?
              WHERE Id = ?'
        );
        $stmt->bind_param('sii', $physicalCity, $physicalCityId, $newOrgId);
        $stmt->execute();
        $stmt->close();
    }

    return ['id' => $newOrgId];
}

/**
 * Thin loop over the EXISTING `orgLicenceUpsert()` (includes/org_licence_admin.php,
 * #1969) — registry-validates each row's licence type and upserts it. This
 * is the wizard/API-only "additional licences at CREATE time" step; the
 * manual "Add an organisation" form has no fields for it (see the file
 * doc-block's "WHAT STAYS PER-CALLER" note). NEVER call this without first
 * checking the caller holds `manage_org_licences` — the #1986 finer gate is
 * the CALLER's responsibility (both call sites resolve
 * `userHasEntitlement('manage_org_licences', ...)` explicitly immediately
 * before calling this), not re-checked here, so this function's own
 * behaviour stays identical to `orgLicenceUpsert()`'s (never a silent
 * second permission check that could disagree with the caller's).
 *
 * @param array<int,array<string,mixed>> $rows  Each row: at minimum
 *        `licence_type` (or `licenceType`); the rest are whatever
 *        `orgLicenceNormaliseFields()` (org_licence_admin.php) accepts —
 *        `licence_number`/`expires_at`/`is_active`/`notes`. A row with an
 *        empty/missing type is silently skipped (never a blank-type upsert).
 * @return array<int,array{type:string,ok:bool,error:?string}>  Per-row
 *         outcome, in submitted order — the wizard's DONE pane and the API
 *         twin's response both surface this so a partial failure (e.g. an
 *         un-migrated `tblOrganisationLicences`) is REPORTED, never silently
 *         swallowed or rolled back (the org itself already committed).
 */
function orgAdminApplyLicenceRows(\mysqli $db, int $orgId, array $rows): array
{
    $results = [];
    foreach ($rows as $row) {
        $type = trim((string)($row['licence_type'] ?? $row['licenceType'] ?? ''));
        if ($type === '') { continue; }
        $res = orgLicenceUpsert($db, $orgId, $type, $row);
        $results[] = [
            'type'  => $type,
            'ok'    => (bool)($res['ok'] ?? false),
            'error' => ($res['ok'] ?? false) ? null : (string)($res['error'] ?? 'Could not save the licence.'),
        ];
    }
    return $results;
}

/**
 * THE ONE write path for adding/updating an organisation member — a
 * 3-column `INSERT … ON DUPLICATE KEY UPDATE`, verbatim from
 * organisations.php's pre-#1996 `add_member` case. Re-points the page's
 * `add_member` case AND the API's `admin_organisation_member_add` action
 * onto this (rule #22 — never a third hand-typed copy). Caller validates
 * `$role` against `ORG_MEMBER_ROLES` and — for the wizard/API create-time
 * flow — that `$userId` names a real, active `tblUsers` row BEFORE calling
 * this (this function itself does not re-probe user existence, matching the
 * pre-#1996 `add_member`/`admin_organisation_member_add` behaviour exactly —
 * neither of those checked it either, only role + non-zero ids).
 */
function orgAdminMemberAdd(\mysqli $db, int $orgId, int $userId, string $role): void
{
    $stmt = $db->prepare(
        'INSERT INTO tblOrganisationMembers (UserId, OrgId, Role)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE Role = VALUES(Role)'
    );
    $stmt->bind_param('iis', $userId, $orgId, $role);
    $stmt->execute();
    $stmt->close();
}
