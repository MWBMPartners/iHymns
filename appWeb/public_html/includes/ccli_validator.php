<?php

declare(strict_types=1);

/**
 * iHymns — CCLI Licence Number Validator (#346)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Validates CCLI (Christian Copyright Licensing International) licence numbers.
 * CCLI licence numbers are typically 6-7 digit numeric identifiers assigned
 * to churches and organisations for copyright compliance.
 *
 * CCLI Number Formats:
 *   - Church/Organisation licence: 6-7 digits (e.g., 123456, 1234567)
 *   - Song number (CCLI Song #): 5-7 digits (e.g., 12345, 1234567)
 *   - Both are purely numeric with no check digit algorithm published
 *
 * @requires PHP 8.1+
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/**
 * Validate a CCLI licence number format.
 *
 * @param string $number The CCLI number to validate
 * @return array{valid: bool, normalized: string, type: string, error: string}
 */
function validateCcliNumber(string $number): array
{
    /* Strip whitespace and common formatting */
    $cleaned = preg_replace('/[\s\-\.\,]/', '', trim($number));

    /* Remove leading # or "CCLI" prefix if present */
    $cleaned = preg_replace('/^(ccli[:\s#]*|#)/i', '', $cleaned);

    if ($cleaned === '') {
        return ['valid' => false, 'normalized' => '', 'type' => '', 'error' => 'CCLI number is required.'];
    }

    /* Must be purely numeric */
    if (!ctype_digit($cleaned)) {
        return ['valid' => false, 'normalized' => $cleaned, 'type' => '', 'error' => 'CCLI number must contain only digits.'];
    }

    $length = strlen($cleaned);

    /* CCLI licence numbers are 5-8 digits */
    if ($length < 5) {
        return ['valid' => false, 'normalized' => $cleaned, 'type' => '', 'error' => 'CCLI number is too short (minimum 5 digits).'];
    }

    if ($length > 8) {
        return ['valid' => false, 'normalized' => $cleaned, 'type' => '', 'error' => 'CCLI number is too long (maximum 8 digits).'];
    }

    /* Determine type based on length */
    $type = $length <= 7 ? 'licence' : 'extended';

    return [
        'valid'      => true,
        'normalized' => $cleaned,
        'type'       => $type,
        'error'      => '',
    ];
}

/**
 * Check if a user's access tier permits a specific action.
 *
 * @param string $userTier   The user's access tier (public, free, ccli, premium, pro)
 * @param string $action     The action to check (view_lyrics, view_copyrighted, play_audio, download_midi, download_pdf, offline_save)
 * @param bool   $hasCcli    Whether the user has a verified CCLI number
 * @return array{allowed: bool, reason: string, upgradeTo: string}
 */
/**
 * Tier level hierarchy — higher number = more access.
 */
const TIER_LEVELS = [
    'public'  => 0,
    'free'    => 10,
    'ccli'    => 20,
    'premium' => 30,
    'pro'     => 40,
];

/**
 * Resolve the effective tier for a user by taking the highest of:
 *   1. Their personal AccessTier
 *   2. Any organisation-level tier they inherit via membership
 *
 * Whichever tier is higher (by TIER_LEVELS) wins.
 *
 * @param int $userId The user ID
 * @return string The effective tier name
 */
function resolveEffectiveTier(int $userId): string
{
    $db = getDbMysqli();

    /* Get personal tier */
    $stmt = $db->prepare('SELECT AccessTier FROM tblUsers WHERE Id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    $personalTier = (string)($row[0] ?? 'public') ?: 'public';

    /* Get highest org-level tier from all memberships.
     * Org tier comes from tblContentLicences linked to the org,
     * or from tblOrganisations.LicenceType mapped to a tier. */
    $orgTier = 'public';

    /* Check org licence types and map to tiers */
    $stmt = $db->prepare(
        'SELECT DISTINCT o.LicenceType
         FROM tblOrganisations o
         JOIN tblOrganisationMembers m ON m.OrgId = o.Id
         WHERE m.UserId = ? AND o.IsActive = 1 AND o.LicenceType != \'none\''
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $orgLicences = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'LicenceType');
    $stmt->close();

    /* Map org licence types to access tiers */
    $licenceToTier = [
        'none'         => 'public',
        'ihymns_basic' => 'free',
        'ihymns_pro'   => 'premium',
        'ccli'         => 'ccli',
        'premium'      => 'premium',
        'pro'          => 'pro',
    ];

    foreach ($orgLicences as $licence) {
        $mapped = $licenceToTier[$licence] ?? 'free';
        if ((TIER_LEVELS[$mapped] ?? 0) > (TIER_LEVELS[$orgTier] ?? 0)) {
            $orgTier = $mapped;
        }
    }

    /* Return whichever tier is higher */
    return (TIER_LEVELS[$personalTier] ?? 0) >= (TIER_LEVELS[$orgTier] ?? 0)
        ? $personalTier
        : $orgTier;
}

function checkTierAccess(string $userTier, string $action, bool $hasCcli = false): array
{
    /* Tier capability matrix */
    $tiers = [
        'public'  => ['view_lyrics' => true],
        'free'    => ['view_lyrics' => true, 'view_copyrighted' => true],
        'ccli'    => ['view_lyrics' => true, 'view_copyrighted' => true, 'play_audio' => true],
        'premium' => ['view_lyrics' => true, 'view_copyrighted' => true, 'play_audio' => true, 'download_midi' => true, 'download_pdf' => true, 'offline_save' => true],
        'pro'     => ['view_lyrics' => true, 'view_copyrighted' => true, 'play_audio' => true, 'download_midi' => true, 'download_pdf' => true, 'offline_save' => true, 'api_access' => true, 'bulk_export' => true],
    ];

    $capabilities = $tiers[$userTier] ?? $tiers['public'];

    /* CCLI tier requires a verified CCLI number */
    if ($userTier === 'ccli' && !$hasCcli) {
        if ($action === 'view_copyrighted' || $action === 'play_audio') {
            return [
                'allowed'   => false,
                'reason'    => 'A verified CCLI licence number is required.',
                'upgradeTo' => 'ccli',
            ];
        }
    }

    if (!empty($capabilities[$action])) {
        return ['allowed' => true, 'reason' => '', 'upgradeTo' => ''];
    }

    /* Find the minimum tier that grants this action */
    $upgradeTo = 'pro';
    foreach ($tiers as $tierName => $caps) {
        if (!empty($caps[$action])) {
            $upgradeTo = $tierName;
            break;
        }
    }

    $actionLabels = [
        'view_lyrics'      => 'view lyrics',
        'view_copyrighted' => 'view copyrighted songs',
        'play_audio'       => 'play audio',
        'download_midi'    => 'download MIDI files',
        'download_pdf'     => 'download sheet music',
        'offline_save'     => 'save songs offline',
        'api_access'       => 'use the API',
        'bulk_export'      => 'export song data',
    ];

    return [
        'allowed'   => false,
        'reason'    => 'Upgrade to ' . ucfirst($upgradeTo) . ' to ' . ($actionLabels[$action] ?? $action) . '.',
        'upgradeTo' => $upgradeTo,
    ];
}
