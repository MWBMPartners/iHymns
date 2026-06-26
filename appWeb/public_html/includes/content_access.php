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
 * Does a restriction whose AppliesToAction = $ruleAction govern the requested
 * $action? (#1120) CCLI (and most licences) separate DISPLAY from REPRODUCTION,
 * so a rule can target one action without gating the others:
 *   - 'all' (and the legacy empty/NULL) governs every action;
 *   - 'reproduce' is the umbrella for the non-display reproduction actions
 *     (print / export / translate);
 *   - otherwise an exact action match.
 * Pure (no DB) so it is unit-testable.
 */
function contentAccessActionApplies(string $ruleAction, string $action): bool
{
    $ruleAction = ($ruleAction === '') ? 'all' : strtolower($ruleAction);
    $action     = strtolower($action) ?: 'display';
    if ($ruleAction === 'all') { return true; }
    if ($ruleAction === $action) { return true; }
    if ($ruleAction === 'reproduce') {
        return in_array($action, ['print', 'export', 'translate'], true);
    }
    return false;
}

/**
 * Check if a user has access to a specific entity (song, songbook, or feature).
 *
 * @param string   $entityType    'song', 'songbook', or 'feature'
 * @param string   $entityId      Song ID, songbook abbreviation, or feature name
 * @param int|null $userId        Authenticated user ID (null for anonymous)
 * @param string   $platform      'PWA', 'Apple', or 'Android'
 * @param ?string  $presenceToken Service-Mode presence token (#1335), or null
 * @param string   $action        'display' (default) | 'print' | 'export' | 'translate' —
 *                                 the requested ACTION; restrictions are filtered to those
 *                                 whose AppliesToAction governs it (#1120). Back-compat:
 *                                 omit for the original display-time check.
 * @return array{allowed: bool, reason: string}
 */
function checkContentAccess(string $entityType, string $entityId, ?int $userId, string $platform = 'PWA', ?string $presenceToken = null, string $action = 'display'): array
{
    $db = getDbMysqli();

    /* Is the per-action axis migrated on THIS env? (#1120 / #1141). Probed ONCE
       per request (static) — a missing column must not throw under STRICT (the
       #1228 lesson); when absent, every rule is treated as AppliesToAction='all'
       so behaviour is byte-identical to the pre-#1120 display-only gate. */
    static $hasActionCol = null;
    if ($hasActionCol === null) {
        try {
            $cstmt = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblContentRestrictions'
                    AND COLUMN_NAME = 'AppliesToAction' LIMIT 1"
            );
            $cstmt->execute();
            $hasActionCol = $cstmt->get_result()->fetch_row() !== null;
            $cstmt->close();
        } catch (\Throwable $e) { $hasActionCol = false; }
    }

    /* Fetch all restrictions for this entity (+ AppliesToAction when present). */
    $cols = 'RestrictionType, TargetType, TargetId, Effect, Priority, Reason'
          . ($hasActionCol ? ', AppliesToAction' : '');
    $stmt = $db->prepare(
        "SELECT {$cols}
         FROM tblContentRestrictions
         WHERE EntityType = ? AND (EntityId = ? OR EntityId = '*')
         ORDER BY Priority DESC, Effect ASC"
    );
    $stmt->bind_param('ss', $entityType, $entityId);
    $stmt->execute();
    $rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Drop rules that don't govern the requested action (only when the axis
       exists — otherwise all rules apply, as before). */
    if ($hasActionCol && $action !== 'display') {
        $rules = array_values(array_filter($rules, static fn($r) =>
            contentAccessActionApplies((string)($r['AppliesToAction'] ?? 'all'), $action)));
    } elseif ($hasActionCol) {
        /* action === 'display': keep rules governing display or all (exclude
           reproduction-only rules so a print/export-only restriction never gates
           on-screen viewing). */
        $rules = array_values(array_filter($rules, static fn($r) =>
            contentAccessActionApplies((string)($r['AppliesToAction'] ?? 'all'), 'display')));
    }

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

    /* Service-Mode presence unlock (#1335): a congregant physically present in a
       live service (a valid, unexpired presence token on an active session whose
       org holds a LIVE CCLI licence) rides that org's licence for the duration —
       so a `require_licence: ccli` rule passes. Works for ANONYMOUS congregants
       (no userId). Revoked the instant they leave / it expires / the licence
       lapses (serviceMode_presenceCcliNumber re-checks every call). The owner has
       accepted the licensing basis (#1324); the per-song CCL notice is rendered
       by song.php when this grant applies. */
    if ($presenceToken !== null && $presenceToken !== '') {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'service_mode.php';
        if (function_exists('serviceMode_presenceCcliNumber')
            && serviceMode_presenceCcliNumber($db, $presenceToken, serviceMode_channel()) !== null) {
            $userLicenceTypes[] = 'ccli';
        }
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

            /* ----------------------------------------------------------------
             * PUBLIC-DOMAIN GATING — design intent (#939)
             *
             * Future RestrictionType values for PD-only tiers MUST treat the
             * lyrics flag and the music flag as INDEPENDENT. The two columns
             * on tblSongs (LyricsPublicDomain, MusicPublicDomain) describe
             * separate concerns:
             *
             *   - LyricsPublicDomain — words are PD (e.g. an old hymn-text
             *     whose author died > 70 years ago).
             *   - MusicPublicDomain  — tune is PD (e.g. a mediaeval melody,
             *     or one whose composer died > 70 years ago).
             *
             * A song can have one without the other (modern lyrics set to a
             * traditional tune, or vice versa). When a tier exposes only PD
             * lyrics, the gate uses LyricsPublicDomain = 1 ALONE. When a tier
             * exposes only PD sheet music / MIDI / MusicXML, the gate uses
             * MusicPublicDomain = 1 ALONE.
             *
             * Do NOT AND the two flags together — that would gate out
             * legitimate PD-lyrics songs whose tune is still in copyright,
             * and vice versa.
             *
             * Suggested future shape:
             *
             *     case 'require_lyrics_pd':
             *         // Allow only when the target song has LyricsPublicDomain = 1.
             *         // Caller is expected to already know the song's PD flags
             *         // (e.g. via SongData::_fetchSongRow); pass them in via
             *         // a fourth checkContentAccess() argument or do a probe
             *         // here with a single-row SELECT keyed on EntityId.
             *         $matches = ($entityType === 'song'
             *                     && (bool)getSongPdFlag($entityId, 'lyrics'));
             *         break;
             *
             *     case 'require_music_pd':
             *         $matches = ($entityType === 'song'
             *                     && (bool)getSongPdFlag($entityId, 'music'));
             *         break;
             *
             * Until those cases are wired, no PD-gating tier is active and
             * songs render unrestricted regardless of their PD flags.
             * ---------------------------------------------------------------- */
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
 * @param string   $action      Requested action (default 'display'); see checkContentAccess (#1120)
 * @return array<string, bool>  Map of entityId => allowed
 */
function checkBulkAccess(string $entityType, array $entityIds, ?int $userId, string $platform = 'PWA', string $action = 'display'): array
{
    $result = [];
    foreach ($entityIds as $id) {
        $check = checkContentAccess($entityType, $id, $userId, $platform, null, $action);
        $result[$id] = $check['allowed'];
    }
    return $result;
}
