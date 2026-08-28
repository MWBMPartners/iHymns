<?php

declare(strict_types=1);

/**
 * iHymns — Organisation-licence admin CRUD shared core (#1969)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A church can hold SEVERAL licences at once — CCLI for the lyrics, MRL for the
 * music, an iHymns plan, … — stored one row per type in `tblOrganisationLicences`.
 * FOUR places used to add/edit/remove those rows with four hand-written copies
 * that had drifted apart (two of them silently WIPED an org's expiry/notes on
 * every save, and two hard-coded a licence-key list that was MISSING `mrl` and
 * `custom`). This file is the ONE place that logic now lives; every surface
 * (the global-admin org page, the member self-service page, and the two native
 * `admin`/`org_admin` API actions) calls these functions instead (rule #22 / #35).
 * Modelled on includes/publisher_admin.php.
 *
 * DETAILED
 * --------
 *  - Licence-type validation delegates to the `tblLicenceTypes` REGISTRY
 *    (`licenceTypeKeys()`, includes/licence_registry.php) — NEVER a hard-coded
 *    key list (rule #9). The registry degrades to its seed keys on an
 *    un-migrated install, so validation is safe everywhere.
 *  - `orgLicenceSyncSet()` is the NON-DESTRUCTIVE replacement for the old
 *    DELETE-all + re-INSERT: it keeps every staying row's metadata
 *    (number/active/expiry/notes), inserts only genuinely-new types, and
 *    deletes only types the admin removed. This is the fix for the data-loss
 *    regression on the global-admin org form + the `admin_organisation_update`
 *    API action.
 *  - Every read/write is `orgLicenceTableExists()`-gated: mysqli runs STRICT
 *    and migrations are web-run, so an un-migrated docroot degrades to a
 *    graceful no-op (writers) / empty list (reader) rather than a thrown
 *    `mysqli_sql_exception` (CLAUDE.md rule #9/#19).
 *  - Every function is pure-PHP-plus-`\mysqli` (no superglobal reads), so a
 *    form-POST caller and a JSON-body caller normalise into the same array
 *    shape (via `orgLicenceNormaliseFields()`) first.
 *
 * @link appWeb/public_html/includes/publisher_admin.php   the shape this mirrors
 * @link appWeb/public_html/includes/licence_registry.php  the type registry (rule #9)
 * @see #1969
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'licence_registry.php';

/**
 * True when `tblOrganisationLicences` exists in the current database. Memoised
 * (the schema does not change within a request). Every read/write below gates
 * on this so an un-migrated install degrades gracefully instead of throwing
 * under STRICT mysqli.
 *
 * @link https://www.php.net/manual/en/mysqli.report.php
 */
function orgLicenceTableExists(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblOrganisationLicences' LIMIT 1"
        );
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[orgLicenceTableExists] ' . $e->getMessage());
        $exists = false;
    }
    return $exists;
}

/**
 * Is $key a valid organisation-licence type? Delegates to the registry
 * (rule #9). The `none` sentinel is rejected by default (it is the "no licence"
 * marker on the primary org column, never a real join-table row); pass
 * $allowNone = true only where the caller genuinely accepts it.
 */
function orgLicenceValidateType(\mysqli $db, string $key, bool $allowNone = false): bool
{
    $key = trim($key);
    if ($key === '' || $key === 'none') {
        return $allowNone && $key === 'none';
    }
    return in_array($key, licenceTypeKeys($db), true);
}

/**
 * Normalise a raw field set (from a form POST or a JSON body) into the ONE
 * shape the write functions accept, so the two caller kinds can't diverge.
 * Keys read (all optional): licence_number|licenceNumber, is_active|isActive,
 * expires_at|expiresAt, notes.
 *
 * @param array<string,mixed> $in
 * @return array{licenceNumber:string,isActive:int,expiresAt:?string,notes:?string}
 */
function orgLicenceNormaliseFields(array $in): array
{
    $number = trim((string)($in['licence_number'] ?? $in['licenceNumber'] ?? ''));

    /* Accept a truthy flag from either a checkbox ("1"/"on") or a JSON bool. */
    $rawActive = $in['is_active'] ?? $in['isActive'] ?? 1;
    $isActive  = (is_string($rawActive) ? ($rawActive !== '' && $rawActive !== '0') : (bool)$rawActive) ? 1 : 0;

    $expiresRaw = trim((string)($in['expires_at'] ?? $in['expiresAt'] ?? ''));
    $expiresAt  = $expiresRaw !== '' ? $expiresRaw : null;

    $notesRaw = trim((string)($in['notes'] ?? ''));
    $notes    = $notesRaw !== '' ? $notesRaw : null;

    return [
        'licenceNumber' => mb_substr($number, 0, 100),
        'isActive'      => $isActive,
        'expiresAt'     => $expiresAt,
        'notes'         => $notes,
    ];
}

/**
 * List an org's licence rows (empty on an un-migrated install).
 *
 * @return array<int,array{Id:int,LicenceType:string,LicenceNumber:string,IsActive:int,ExpiresAt:?string,Notes:?string}>
 */
function orgLicenceList(\mysqli $db, int $orgId): array
{
    if ($orgId <= 0 || !orgLicenceTableExists($db)) {
        return [];
    }
    try {
        $stmt = $db->prepare(
            'SELECT Id, LicenceType, LicenceNumber, IsActive, ExpiresAt, Notes
               FROM tblOrganisationLicences
              WHERE OrganisationId = ?
              ORDER BY LicenceType ASC'
        );
        $stmt->bind_param('i', $orgId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (\Throwable $e) {
        error_log('[orgLicenceList] ' . $e->getMessage());
        return [];
    }
}

/**
 * Add or update ONE licence row by (OrganisationId, LicenceType) — the table's
 * UNIQUE key. Used by the per-row "add" affordance on both admin surfaces and
 * the `org_admin_licence_add` API action. Validates the type against the
 * registry (rule #9).
 *
 * @param array<string,mixed> $fields Raw fields (see orgLicenceNormaliseFields).
 * @return array{ok:bool,error?:string}
 */
function orgLicenceUpsert(\mysqli $db, int $orgId, string $licenceType, array $fields): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid organisation.'];
    }
    if (!orgLicenceTableExists($db)) {
        return ['ok' => false, 'error' => 'Organisation licences are not available on this server.'];
    }
    if (!orgLicenceValidateType($db, $licenceType)) {
        return ['ok' => false, 'error' => 'Unknown licence type.'];
    }
    $f = orgLicenceNormaliseFields($fields);
    try {
        $stmt = $db->prepare(
            'INSERT INTO tblOrganisationLicences
                (OrganisationId, LicenceType, LicenceNumber, IsActive, ExpiresAt, Notes)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                LicenceNumber = VALUES(LicenceNumber),
                IsActive      = VALUES(IsActive),
                ExpiresAt     = VALUES(ExpiresAt),
                Notes         = VALUES(Notes)'
        );
        $stmt->bind_param('ississ',
            $orgId, $licenceType, $f['licenceNumber'], $f['isActive'], $f['expiresAt'], $f['notes']);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true];
    } catch (\Throwable $e) {
        error_log('[orgLicenceUpsert] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save the licence.'];
    }
}

/**
 * Update ONE licence row's metadata by its Id, ownership-scoped to $orgId (the
 * row's OrganisationId is checked IN the UPDATE's WHERE, so a crafted POST that
 * mixes a licence_id from one org with an org_id the caller can admin can never
 * touch a foreign row). The LicenceType is NOT editable here — it is the UNIQUE
 * key, so "changing the type" is a remove + add.
 *
 * @param array<string,mixed> $fields Raw fields (see orgLicenceNormaliseFields).
 * @return array{ok:bool,error?:string}
 */
function orgLicenceUpdateById(\mysqli $db, int $orgId, int $licenceId, array $fields): array
{
    if ($orgId <= 0 || $licenceId <= 0) {
        return ['ok' => false, 'error' => 'Invalid licence row.'];
    }
    if (!orgLicenceTableExists($db)) {
        return ['ok' => false, 'error' => 'Organisation licences are not available on this server.'];
    }
    $f = orgLicenceNormaliseFields($fields);
    try {
        $stmt = $db->prepare(
            'UPDATE tblOrganisationLicences
                SET LicenceNumber = ?, IsActive = ?, ExpiresAt = ?, Notes = ?
              WHERE Id = ? AND OrganisationId = ?'
        );
        $stmt->bind_param('sissii',
            $f['licenceNumber'], $f['isActive'], $f['expiresAt'], $f['notes'], $licenceId, $orgId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        /* affected_rows is 0 for BOTH "not found/foreign" and "no change", so a
           0 here is not necessarily an error; the ownership guarantee is the
           WHERE clause, not the row count. Callers that need a hard "exists"
           answer read orgLicenceList() first. */
        return ['ok' => true, 'changed' => $affected > 0];
    } catch (\Throwable $e) {
        error_log('[orgLicenceUpdateById] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not update the licence.'];
    }
}

/**
 * Delete ONE licence row by its Id, ownership-scoped to $orgId (the
 * OrganisationId is IN the DELETE's WHERE — never checked after the fact).
 *
 * @return array{ok:bool,error?:string,deleted?:bool}
 */
function orgLicenceDeleteById(\mysqli $db, int $orgId, int $licenceId): array
{
    if ($orgId <= 0 || $licenceId <= 0) {
        return ['ok' => false, 'error' => 'Invalid licence row.'];
    }
    if (!orgLicenceTableExists($db)) {
        return ['ok' => false, 'error' => 'Organisation licences are not available on this server.'];
    }
    try {
        $stmt = $db->prepare(
            'DELETE FROM tblOrganisationLicences WHERE Id = ? AND OrganisationId = ?'
        );
        $stmt->bind_param('ii', $licenceId, $orgId);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        return ['ok' => true, 'deleted' => $deleted];
    } catch (\Throwable $e) {
        error_log('[orgLicenceDeleteById] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not remove the licence.'];
    }
}

/**
 * NON-DESTRUCTIVELY reconcile an org's licence SET to a desired list of types,
 * folding in the org's primary licence so the join table and the legacy denorm
 * (`tblOrganisations.LicenceType`/`LicenceNumber`) stay coherent. This REPLACES
 * the old DELETE-all + re-INSERT (which wiped every row's IsActive/ExpiresAt/
 * Notes and every non-primary number on every save — the #1969 data-loss bug).
 *
 * Behaviour:
 *   - Types validated against the registry; `none`/`''` dropped.
 *   - The primary type (if real) is always in the desired set (never deleted).
 *   - A desired type with no existing row is INSERTED (its number = the primary
 *     number iff it is the primary type, else '').
 *   - An existing type NOT in the desired set is DELETED (the admin removed it).
 *   - Existing rows that STAY keep ALL their metadata; only the primary row's
 *     number is refreshed, and only when a non-empty primary number was given
 *     (so clearing the primary number never silently blanks a stored number).
 *
 * @param array<int,string> $desiredTypes The submitted set of licence-type keys.
 * @param bool $prune When true (the default — the checkbox "set replaces" API
 *   contract), types NOT in the desired set are deleted. When false, the call
 *   is ADDITIVE only (insert new + refresh the primary number, never delete) —
 *   used by the web org-edit form, where a separate per-row editor owns removals
 *   and the form only guarantees the primary licence has a matching join row.
 * @return array{ok:bool,error?:string}
 */
function orgLicenceSyncSet(\mysqli $db, int $orgId, array $desiredTypes, string $primaryType, string $primaryNumber, bool $prune = true): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid organisation.'];
    }
    if (!orgLicenceTableExists($db)) {
        return ['ok' => true]; /* un-migrated install: no-op, matches prior try/catch */
    }

    $primaryType   = trim($primaryType);
    $primaryNumber = mb_substr(trim($primaryNumber), 0, 100);

    /* Validated desired set (registry-checked, de-duped, none/'' dropped). */
    $valid = [];
    foreach ($desiredTypes as $t) {
        $t = trim((string)$t);
        if ($t === '' || $t === 'none') { continue; }
        if (orgLicenceValidateType($db, $t) && !in_array($t, $valid, true)) {
            $valid[] = $t;
        }
    }
    /* Fold in the primary so the two surfaces agree. */
    if ($primaryType !== '' && $primaryType !== 'none'
        && orgLicenceValidateType($db, $primaryType) && !in_array($primaryType, $valid, true)) {
        $valid[] = $primaryType;
    }

    try {
        /* Existing types. */
        $existing = [];
        $sel = $db->prepare('SELECT LicenceType FROM tblOrganisationLicences WHERE OrganisationId = ?');
        $sel->bind_param('i', $orgId);
        $sel->execute();
        foreach ($sel->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $existing[] = (string)$r['LicenceType'];
        }
        $sel->close();

        /* INSERT genuinely-new types (only metadata is the primary's number). */
        $toAdd = array_values(array_diff($valid, $existing));
        if ($toAdd) {
            $ins = $db->prepare(
                'INSERT INTO tblOrganisationLicences (OrganisationId, LicenceType, LicenceNumber)
                 VALUES (?, ?, ?)'
            );
            foreach ($toAdd as $t) {
                $num = ($t === $primaryType) ? $primaryNumber : '';
                $ins->bind_param('iss', $orgId, $t, $num);
                $ins->execute();
            }
            $ins->close();
        }

        /* Refresh ONLY the primary row's number (keep its other metadata), and
           only when a non-empty number was supplied. */
        if ($primaryType !== '' && $primaryType !== 'none' && $primaryNumber !== ''
            && in_array($primaryType, $existing, true)) {
            $upd = $db->prepare(
                'UPDATE tblOrganisationLicences SET LicenceNumber = ?
                  WHERE OrganisationId = ? AND LicenceType = ?'
            );
            $upd->bind_param('sis', $primaryNumber, $orgId, $primaryType);
            $upd->execute();
            $upd->close();
        }

        /* DELETE only the types the admin removed — skipped entirely in
           additive mode ($prune = false), where a separate per-row editor
           owns removals. */
        $toRemove = $prune ? array_values(array_diff($existing, $valid)) : [];
        if ($toRemove) {
            $del = $db->prepare(
                'DELETE FROM tblOrganisationLicences WHERE OrganisationId = ? AND LicenceType = ?'
            );
            foreach ($toRemove as $t) {
                $del->bind_param('is', $orgId, $t);
                $del->execute();
            }
            $del->close();
        }

        return ['ok' => true];
    } catch (\Throwable $e) {
        error_log('[orgLicenceSyncSet] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not sync the organisation licences.'];
    }
}
