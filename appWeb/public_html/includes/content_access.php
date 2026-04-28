<?php

declare(strict_types=1);

/**
 * iHymns — Content Access Control
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Evaluates content restrictions to determine whether a user on a given
 * platform can access a specific song, songbook, or feature.
 *
 * The system uses a priority-based rule evaluation:
 *   1. Gather all matching restrictions for the entity
 *   2. Sort by Priority (descending) — higher priority overrides lower
 *   3. At equal priority, deny beats allow
 *   4. If no restrictions match, access is granted (open by default)
 *
 * @requires PHP 8.1+ with mysqli (via includes/db_mysql.php)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* Inheritance-aware licence resolver (#462). The `require_licence` branch
   below calls getUserEffectiveLicenceTypes() so a rule saying
   `require_licence: ihymns_pro` passes when the user holds that licence
   directly OR inherits it from any ancestor organisation. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licences.php';

/**
 * Check if a user has access to a specific entity (song, songbook, or feature).
 *
 * @param string   $entityType  'song', 'songbook', or 'feature'
 * @param string   $entityId    Song ID, songbook abbreviation, or feature name
 * @param int|null $userId      Authenticated user ID (null for anonymous)
 * @param string   $platform    'PWA', 'Apple', or 'Android'
 * @return array{allowed: bool, reason: string}
 */
function checkContentAccess(string $entityType, string $entityId, ?int $userId, string $platform = 'PWA'): array
{
    $db = getDbMysqli();

    /* Fetch all restrictions for this entity */
    $stmt = $db->prepare(
        'SELECT RestrictionType, TargetType, TargetId, Effect, Priority, Reason
         FROM tblContentRestrictions
         WHERE EntityType = ? AND (EntityId = ? OR EntityId = \'*\')
         ORDER BY Priority DESC, Effect ASC'
    );
    $stmt->bind_param('ss', $entityType, $entityId);
    $stmt->execute();
    $rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rules)) {
        return ['allowed' => true, 'reason' => ''];
    }

    /* Org memberships — still needed for block_org / require_org rules
       which match on direct membership, not inheritance. The licence
       set below handles its own ancestor walk. */
    $userOrgIds = [];
    $userLicenceTypes = [];

    if ($userId !== null) {
        $stmt = $db->prepare('SELECT OrgId FROM tblOrganisationMembers WHERE UserId = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        /* PDO::FETCH_COLUMN returned a flat array of column 0; mysqli has
           no direct equivalent — pull all rows then array_column. */
        $userOrgIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'OrgId');
        $stmt->close();

        /* Effective licence types: direct + via every org the user belongs
           to + every ancestor org (#462). Replaces the old single-level
           query that only looked at direct memberships. */
        $userLicenceTypes = getUserEffectiveLicenceTypes($userId);
    }

    /* Evaluate rules in priority order */
    foreach ($rules as $rule) {
        $matches = false;

        switch ($rule['RestrictionType']) {
            case 'block_platform':
                $matches = (strtoupper($rule['TargetId']) === strtoupper($platform));
                break;

            case 'block_user':
                $matches = ($userId !== null && (string)$userId === $rule['TargetId']);
                break;

            case 'block_org':
                $matches = in_array((int)$rule['TargetId'], array_map('intval', $userOrgIds));
                break;

            case 'require_licence':
                /* If user has any matching licence type, they pass */
                if (in_array($rule['TargetId'], $userLicenceTypes)) {
                    $matches = true;
                    /* require_licence with allow effect means "licence found, pass" */
                } else {
                    /* No matching licence — this is a deny */
                    return ['allowed' => false, 'reason' => $rule['Reason'] ?: 'Licence required.'];
                }
                continue 2; /* Skip the effect check below — handled inline */

            case 'require_org':
                if (empty($userOrgIds)) {
                    return ['allowed' => false, 'reason' => $rule['Reason'] ?: 'Organisation membership required.'];
                }
                if ($rule['TargetId'] !== '' && $rule['TargetId'] !== '*') {
                    if (!in_array((int)$rule['TargetId'], array_map('intval', $userOrgIds))) {
                        return ['allowed' => false, 'reason' => $rule['Reason'] ?: 'Specific organisation membership required.'];
                    }
                }
                continue 2;
        }

        if ($matches) {
            if ($rule['Effect'] === 'deny') {
                return ['allowed' => false, 'reason' => $rule['Reason'] ?: 'Access restricted.'];
            }
            /* Effect is 'allow' — explicitly allowed, stop checking */
            return ['allowed' => true, 'reason' => ''];
        }
    }

    /* No rules matched — default allow */
    return ['allowed' => true, 'reason' => ''];
}

/**
 * Bulk check access for multiple entities (used for filtering song lists).
 *
 * @param string   $entityType  'song' or 'songbook'
 * @param string[] $entityIds   Array of entity IDs to check
 * @param int|null $userId      Authenticated user ID
 * @param string   $platform    Platform identifier
 * @return array<string, bool>  Map of entityId => allowed
 */
function checkBulkAccess(string $entityType, array $entityIds, ?int $userId, string $platform = 'PWA'): array
{
    $result = [];
    foreach ($entityIds as $id) {
        $check = checkContentAccess($entityType, $id, $userId, $platform);
        $result[$id] = $check['allowed'];
    }
    return $result;
}
