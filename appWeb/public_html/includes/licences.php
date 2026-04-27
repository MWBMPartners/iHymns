<?php

declare(strict_types=1);

/**
 * iHymns — Licence Evaluator with Inheritance (#462)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Resolves a user's effective licence set by unioning:
 *
 *   (a) Direct user-level rows in tblContentLicences
 *       (UserId = $userId, IsActive, within validity).
 *   (b) Legacy tblUsers.CcliNumber — if the user has a personal CCLI
 *       number recorded on their profile, it counts as a 'ccli' licence.
 *   (c) Licences on every organisation the user belongs to
 *       (tblOrganisationMembers).
 *   (d) Licences on every ancestor organisation, walking up
 *       tblOrganisations.ParentOrgId recursively (bounded at
 *       LICENCE_INHERITANCE_MAX_DEPTH to defend against cycles even
 *       though the FK should prevent them).
 *   (e) Legacy tblOrganisations.LicenceType column — if an org hasn't
 *       yet been migrated to tblContentLicences rows, its single-column
 *       licence still contributes.
 *
 * Results are cached per-request in a static map so repeated calls from
 * checkContentAccess() + API handlers hit the DB once per user.
 *
 * Require order: this file's queries use mysqli prepared statements
 * via getDbMysqli(); db_mysql.php is required at the top so callers
 * don't need to load it themselves.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* Cap org hierarchy traversal depth. A real church → diocese → conference
   chain is typically 2–3 deep; 10 is generous headroom and stops any
   accidental cycle from looping forever. */
const LICENCE_INHERITANCE_MAX_DEPTH = 10;

/**
 * Resolve the effective licence set for a user.
 *
 * @param int|null $userId The authenticated user. Null → empty set
 *                         (anonymous callers have no licences).
 * @return array<int, array{
 *     type: string,
 *     key: string,
 *     expires_at: ?string,
 *     source: string,           // 'user' | 'org'
 *     source_id: ?int,          // user id or org id this row came from
 *     inherited: bool           // true if reached via ancestor org
 * }>
 */
function getUserEffectiveLicences(?int $userId): array
{
    static $cache = [];
    if ($userId === null) {
        return [];
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $db = getDbMysqli();

    /* Collect every org the user transitively belongs to: direct
       memberships first, then walk up the ParentOrgId chain. Uses a
       frontier queue rather than recursion so the depth bound is
       explicit and easy to audit. */
    $stmt = $db->prepare('SELECT OrgId FROM tblOrganisationMembers WHERE UserId = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $directOrgIds = array_map('intval',
        array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'OrgId'));
    $stmt->close();

    $allOrgIds   = array_fill_keys($directOrgIds, false); /* false = direct, true = inherited */
    $frontier    = $directOrgIds;

    for ($depth = 0; $depth < LICENCE_INHERITANCE_MAX_DEPTH && !empty($frontier); $depth++) {
        /* Dynamic IN-list: build the placeholder list AND a matching
           type string. Frontier values are always ints (org Ids). */
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $stmt = $db->prepare(
            "SELECT DISTINCT ParentOrgId
               FROM tblOrganisations
              WHERE Id IN ($placeholders)
                AND ParentOrgId IS NOT NULL"
        );
        $stmt->bind_param(str_repeat('i', count($frontier)), ...$frontier);
        $stmt->execute();
        $parents = array_map('intval',
            array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'ParentOrgId'));
        $stmt->close();

        $newParents = array_values(array_filter(
            $parents,
            static fn(int $id): bool => !array_key_exists($id, $allOrgIds)
        ));
        foreach ($newParents as $id) {
            $allOrgIds[$id] = true; /* marked inherited */
        }
        $frontier = $newParents;
    }

    $licences = [];

    /* (a) direct user-level rows */
    $stmt = $db->prepare(
        'SELECT LicenceType AS type, LicenceKey AS `key`, ExpiresAt AS expires_at
           FROM tblContentLicences
          WHERE UserId = ?
            AND IsActive = 1
            AND (ExpiresAt IS NULL OR ExpiresAt > NOW())'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $r) {
        $licences[] = [
            'type'       => (string)$r['type'],
            'key'        => (string)$r['key'],
            'expires_at' => $r['expires_at'],
            'source'     => 'user',
            'source_id'  => $userId,
            'inherited'  => false,
        ];
    }

    /* (b) legacy tblUsers.CcliNumber */
    $stmt = $db->prepare('SELECT CcliNumber FROM tblUsers WHERE Id = ? AND IsActive = 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && trim((string)($row['CcliNumber'] ?? '')) !== '') {
        $licences[] = [
            'type'       => 'ccli',
            'key'        => (string)$row['CcliNumber'],
            'expires_at' => null,
            'source'     => 'user',
            'source_id'  => $userId,
            'inherited'  => false,
        ];
    }

    if (!empty($allOrgIds)) {
        $orgIds       = array_keys($allOrgIds);
        $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
        $orgIdTypes   = str_repeat('i', count($orgIds));

        /* (c + d) tblContentLicences rows for any org in the chain */
        $stmt = $db->prepare(
            "SELECT LicenceType AS type, LicenceKey AS `key`, ExpiresAt AS expires_at, OrgId
               FROM tblContentLicences
              WHERE OrgId IN ($placeholders)
                AND IsActive = 1
                AND (ExpiresAt IS NULL OR ExpiresAt > NOW())"
        );
        $stmt->bind_param($orgIdTypes, ...$orgIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) {
            $orgId = (int)$r['OrgId'];
            $licences[] = [
                'type'       => (string)$r['type'],
                'key'        => (string)$r['key'],
                'expires_at' => $r['expires_at'],
                'source'     => 'org',
                'source_id'  => $orgId,
                'inherited'  => $allOrgIds[$orgId] ?? false,
            ];
        }

        /* (e) legacy tblOrganisations.LicenceType / LicenceNumber /
               LicenceExpiresAt — skipped once the org has real rows in
               tblContentLicences above, but harmless to include (dedup
               happens via getUserEffectiveLicenceTypes). */
        $stmt = $db->prepare(
            "SELECT Id, LicenceType, LicenceNumber, LicenceExpiresAt
               FROM tblOrganisations
              WHERE Id IN ($placeholders)
                AND IsActive = 1
                AND LicenceType <> ''
                AND LicenceType <> 'none'
                AND (LicenceExpiresAt IS NULL OR LicenceExpiresAt > NOW())"
        );
        $stmt->bind_param($orgIdTypes, ...$orgIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) {
            $orgId = (int)$r['Id'];
            $licences[] = [
                'type'       => (string)$r['LicenceType'],
                'key'        => (string)$r['LicenceNumber'],
                'expires_at' => $r['LicenceExpiresAt'],
                'source'     => 'org',
                'source_id'  => $orgId,
                'inherited'  => $allOrgIds[$orgId] ?? false,
            ];
        }
    }

    return $cache[$userId] = $licences;
}

/**
 * True if the user's effective licence set contains a licence of the
 * given type (e.g. 'ccli', 'ihymns_pro'). Convenience wrapper around
 * getUserEffectiveLicences() for the common access-check case.
 */
function userHasEffectiveLicence(?int $userId, string $type): bool
{
    if ($userId === null) {
        return false;
    }
    foreach (getUserEffectiveLicences($userId) as $l) {
        if ($l['type'] === $type) {
            return true;
        }
    }
    return false;
}

/**
 * Flat, de-duplicated list of licence type strings in the effective
 * set — drop-in replacement for the ad-hoc query in checkContentAccess().
 *
 * @return string[]
 */
function getUserEffectiveLicenceTypes(?int $userId): array
{
    return array_values(array_unique(array_map(
        static fn(array $l): string => $l['type'],
        getUserEffectiveLicences($userId)
    )));
}
