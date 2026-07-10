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

    /* Get highest org-level tier from all memberships, walking up the
     * nesting chain (#636). A direct membership of "Hillsong London"
     * also inherits the licence/tier of its parent "Hillsong Worship"
     * — using a recursive CTE up tblOrganisations.ParentOrgId so the
     * walker stays a single round-trip regardless of nesting depth.
     * Falls back to the old single-level query on MySQL versions
     * that don't support WITH RECURSIVE (pre-8.0). */
    $orgTier = 'public';

    $orgLicences = [];
    /* Walk the user → org → ancestor chain via recursive CTE (#636),
       then union licences from BOTH the legacy primary column on
       tblOrganisations AND the new tblOrganisationLicences join
       table (#640). The LEFT JOIN keeps installs without the new
       table working — the join just yields NULL rows that get
       filtered out by the WHERE. */
    $sqlRecursive = "
        WITH RECURSIVE org_chain AS (
            /* Anchor: every org the user is a direct member of. */
            SELECT o.Id, o.LicenceType, o.IsActive, o.ParentOrgId
              FROM tblOrganisations o
              JOIN tblOrganisationMembers m ON m.OrgId = o.Id
             WHERE m.UserId = ?
            UNION ALL
            /* Recursive step: parent orgs along the nesting chain. */
            SELECT po.Id, po.LicenceType, po.IsActive, po.ParentOrgId
              FROM tblOrganisations po
              JOIN org_chain c ON c.ParentOrgId = po.Id
        )
        SELECT LicenceType FROM (
            /* Legacy primary licence on the org row (back-compat). */
            SELECT DISTINCT LicenceType
              FROM org_chain
             WHERE IsActive = 1 AND LicenceType <> 'none'
            UNION
            /* Multi-licence join table (#640). Each org along the
               chain may carry any number of additional active
               licences here. */
            SELECT DISTINCT ol.LicenceType
              FROM org_chain c
              JOIN tblOrganisationLicences ol ON ol.OrganisationId = c.Id
             WHERE c.IsActive = 1
               AND ol.IsActive = 1
               AND ol.LicenceType <> 'none'
        ) AS uniq_licences
    ";
    try {
        $stmt = $db->prepare($sqlRecursive);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $orgLicences = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'LicenceType');
            $stmt->close();
        }
    } catch (\Throwable $_e) {
        /* MySQL < 8.0 / MariaDB without recursive CTE — fall back to
         * the direct-membership query so the page keeps working. */
        $stmt = $db->prepare(
            'SELECT DISTINCT o.LicenceType
             FROM tblOrganisations o
             JOIN tblOrganisationMembers m ON m.OrgId = o.Id
             WHERE m.UserId = ? AND o.IsActive = 1 AND o.LicenceType <> \'none\''
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $orgLicences = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'LicenceType');
        $stmt->close();
    }

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

/**
 * Map the eight action strings checkTierAccess understands to the
 * tblAccessTiers capability key that grants them. Six map to a real
 * cap (column- or json-backed, read via tierCapRead); api_access /
 * bulk_export have no cap row yet (#1353) so they stay matrix-only and
 * are intentionally ABSENT here.
 *
 * The registry resolver (capsForTierFromRegistry) also accepts an action
 * that IS a cap key directly — either the camelCase form (lcfirst of the
 * PascalCase TIER_CAPS key, e.g. `canRequestSongs`) or the exact PascalCase
 * column/json key (e.g. `CanRequestSongs`). That lets a NEW json cap added
 * by #1352 be enforced as soon as a caller passes its name, with zero edit
 * to this map.
 */
const TIER_ACTION_CAP_MAP = [
    'view_lyrics'      => 'CanViewLyrics',
    'view_copyrighted' => 'CanViewCopyrighted',
    'play_audio'       => 'CanPlayAudio',
    'download_midi'    => 'CanDownloadMidi',
    'download_pdf'     => 'CanDownloadPdf',
    'offline_save'     => 'CanOfflineSave',
];

/**
 * Resolve a tier's allowed-action map from the LIVE tblAccessTiers
 * registry, so the caps a curator edits (and any new one-line json cap
 * added per #1352) drive enforcement automatically instead of the
 * hardcoded matrix below.
 *
 * Returns `null` to signal "fall back to the matrix" whenever the DB is
 * un-migrated, the tier row is missing, or any read throws — so an edge
 * / un-migrated env behaves EXACTLY as it did before this change
 * (rule #C: never let an optional read throw; STRICT mode means a bad
 * SELECT throws, so every read is wrapped).
 *
 * Shape on success: `['view_lyrics' => true, 'play_audio' => false, ...]`
 * — one entry per action in TIER_ACTION_CAP_MAP, value = the tier's cap.
 * Request-cached per tier name (the registry is stable within a request).
 *
 * @param string $userTier Tier name (public/free/ccli/premium/pro/…).
 * @return array<string,bool>|null Allowed-action map, or null to fall back.
 */
function capsForTierFromRegistry(string $userTier): ?array
{
    /* ELI5: remember each tier's answer for the rest of this request.
       WHY: song_detail can call checkTierAccess up to 6× per song (one
       per cap); caching the per-tier SELECT keeps it to one round-trip. */
    static $cache = [];
    if (array_key_exists($userTier, $cache)) {
        return $cache[$userTier];
    }

    /* The shared cap registry + storage helpers (column vs json, defaults,
       the migration probe). Required lazily so the matrix-only callers
       (tier_check on an un-migrated DB) never pay for it. */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'access_tier_validation.php';

    try {
        $db = getDbMysqli();

        /* Pull the json column ONLY when it has been migrated — selecting a
           non-existent column THROWS under STRICT (rule #C). The 7 column
           caps are always present (they ship in the base schema). */
        $hasCaps   = function_exists('tierCapsColumnExists') && tierCapsColumnExists($db);
        $capsCol   = $hasCaps ? ', Capabilities' : '';
        $stmt = $db->prepare(
            'SELECT Name, CanViewLyrics, CanViewCopyrighted, CanPlayAudio,
                    CanDownloadMidi, CanDownloadPdf, CanOfflineSave, RequiresCcli'
            . $capsCol .
            ' FROM tblAccessTiers WHERE Name = ? LIMIT 1'
        );
        $stmt->bind_param('s', $userTier);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            /* Unknown tier — let the caller fall back to the matrix so an
               unexpected tier name degrades to public-tier behaviour. */
            return $cache[$userTier] = null;
        }

        $out = [];
        foreach (TIER_ACTION_CAP_MAP as $action => $capKey) {
            /* tierCapRead handles column vs json + the per-cap default when
               a json key is absent / the column is un-migrated. */
            $out[$action] = tierCapRead($row, $capKey) === 1;
        }
        return $cache[$userTier] = $out;
    } catch (\Throwable $_e) {
        /* Any failure (un-migrated table, DB blip) → fall back to matrix. */
        return $cache[$userTier] = null;
    }
}

/**
 * Resolve a SINGLE cap value for a tier when the action passed to
 * checkTierAccess is itself a capability key — either the camelCase API
 * shape (`canRequestSongs`) or the exact PascalCase TIER_CAPS key
 * (`CanRequestSongs`). Returns the bool value off the live tier row, or
 * null when the action isn't a known cap key / the row can't be read
 * (caller then leaves the matrix value untouched).
 *
 * This is what lets a #1352 one-line json cap be ENFORCED the moment any
 * caller passes its name, with zero edit to TIER_ACTION_CAP_MAP. Since
 * #1481, "known cap key" means the EFFECTIVE registry — TIER_CAPS union
 * enabled tblGatingCapabilities rows (tierCapsEffective()) — so a
 * Global-Admin-defined capability is enforceable the moment any caller
 * passes its name too, with zero further code changes. Dormant: with
 * tblGatingCapabilities empty/absent, tierCapsEffective() === TIER_CAPS so
 * this resolves exactly as before #1481.
 *
 * @param string $userTier Tier name.
 * @param string $action   A cap key (camelCase or PascalCase) OR a plain action.
 * @return bool|null true/false when $action resolved to a cap; null otherwise.
 */
function capValueForTierAction(string $userTier, string $action): ?bool
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'access_tier_validation.php';

    /* Resolve $action → an effective-registry key (#1481: TIER_CAPS ∪
       enabled DB caps, via tierCapsEffective() — never the bare TIER_CAPS
       constant, so a DB-defined cap resolves here too). Accept the exact
       PascalCase key, OR find the key whose lcfirst (the camelCase API
       shape) matches. */
    $capKey        = null;
    $effectiveCaps = (defined('TIER_CAPS') && function_exists('tierCapsEffective'))
        ? tierCapsEffective()
        : [];
    if (array_key_exists($action, $effectiveCaps)) {
        $capKey = $action;                      /* PascalCase column/json key */
    } else {
        foreach (array_keys($effectiveCaps) as $k) {
            if (lcfirst($k) === $action) { $capKey = $k; break; }
        }
    }
    if ($capKey === null) {
        return null;   /* not a cap key — caller keeps the matrix value */
    }
    /* RequiresCcli is a tier PROPERTY, not a grantable action — never treat
       passing it as an "allow" check. */
    if ($capKey === 'RequiresCcli') {
        return null;
    }

    try {
        $db      = getDbMysqli();
        $hasCaps = function_exists('tierCapsColumnExists') && tierCapsColumnExists($db);
        $capsCol = $hasCaps ? ', Capabilities' : '';
        $stmt = $db->prepare(
            'SELECT Name, CanViewLyrics, CanViewCopyrighted, CanPlayAudio,
                    CanDownloadMidi, CanDownloadPdf, CanOfflineSave, RequiresCcli'
            . $capsCol .
            ' FROM tblAccessTiers WHERE Name = ? LIMIT 1'
        );
        $stmt->bind_param('s', $userTier);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        return tierCapRead($row, $capKey) === 1;
    } catch (\Throwable $_e) {
        return null;
    }
}

function checkTierAccess(string $userTier, string $action, bool $hasCcli = false): array
{
    /* Tier capability matrix — the FALLBACK source of truth. Used verbatim
       when the registry can't answer (un-migrated DB / unknown tier) and for
       the two actions with no cap column yet (api_access / bulk_export). */
    $tiers = [
        'public'  => ['view_lyrics' => true],
        'free'    => ['view_lyrics' => true, 'view_copyrighted' => true],
        'ccli'    => ['view_lyrics' => true, 'view_copyrighted' => true, 'play_audio' => true],
        'premium' => ['view_lyrics' => true, 'view_copyrighted' => true, 'play_audio' => true, 'download_midi' => true, 'download_pdf' => true, 'offline_save' => true],
        'pro'     => ['view_lyrics' => true, 'view_copyrighted' => true, 'play_audio' => true, 'download_midi' => true, 'download_pdf' => true, 'offline_save' => true, 'api_access' => true, 'bulk_export' => true],
    ];

    $capabilities = $tiers[$userTier] ?? $tiers['public'];

    /* #1353 — registry-drive the six cap-backed actions. capsForTierFromRegistry
       reads the LIVE tblAccessTiers row (incl. #1352 json caps); when it returns
       a map we OVERLAY it onto the matrix capabilities so a curator's edits +
       any new json cap are enforced without touching this matrix. A null return
       (un-migrated DB / unknown tier / read threw) leaves $capabilities exactly
       as the matrix had it — byte-identical to pre-#1353 behaviour. The action
       may also BE a cap key directly (camelCase or PascalCase) so a new json cap
       is enforceable the moment a caller passes its name (see TIER_ACTION_CAP_MAP). */
    $registry = capsForTierFromRegistry($userTier);
    if ($registry !== null) {
        /* Overlay the six mapped actions. */
        foreach ($registry as $regAction => $allowed) {
            $capabilities[$regAction] = $allowed;
        }
        /* Accept an action that is itself a cap key — camelCase (the API emit
           shape) or PascalCase (the TIER_CAPS key). Resolve it against the live
           row so a #1352 json cap is enforced with no matrix/map edit. */
        if (!isset($capabilities[$action]) || !array_key_exists($action, TIER_ACTION_CAP_MAP)) {
            $directVal = capValueForTierAction($userTier, $action);
            if ($directVal !== null) {
                $capabilities[$action] = $directVal;
            }
        }
    }

    /* CCLI tier requires a verified CCLI number — UNCHANGED gate. A ccli-tier
       user without a live verified CCLI licence is still denied the copyrighted
       + audio actions regardless of what the registry says. */
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
