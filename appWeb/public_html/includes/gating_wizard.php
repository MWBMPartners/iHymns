<?php

declare(strict_types=1);

/**
 * iHymns — Content-gating activation wizard: shared core (#2006, epic #2002)
 * =============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Turning content locking ON is the single most consequential switch in
 * the whole gating program (rule #28) — it changes, in one instant, what
 * every visitor to the site sees. This file is the "brain" behind the
 * guided wizard on `/manage/gating` that walks an administrator through
 * checking it is actually safe to do that: what would change, whether
 * anyone could still unlock a CCLI-gated song, and a dry-run test of one
 * real song — before it lets them flip the switch, and it hands them an
 * unconditional one-click way to switch it back off again.
 *
 * DETAILED / WHY THIS FILE EXISTS AND WHAT IT DOES NOT DO
 * -----------------------------------------------------------
 * `manage/gating.php` is the PAGE (HTML, POST/GET dispatch, the modal
 * markup). This file is the CORE it delegates every non-trivial decision
 * to (rule #22) — the read-only census helpers, the pure precondition
 * evaluator, the row-seeding planner, the dry-run simulator, and the ONE
 * function that actually flips the flag. Splitting it out this way means
 * `tests/php/test-gating-wizard.php` can load and unit-test the PURE
 * functions (the precondition evaluator, the row planner) with NO database
 * connection at all — this file has no top-level code, only function
 * definitions, so simply requiring it never touches the DB (rule #9's
 * "never let the mere act of loading a file need a live connection").
 *
 * THIS FILE NEVER MODIFIES THE ENFORCEMENT CHAIN. It reads
 * `tblAccessTiers` / `tblContentRestrictions` / licence tables the same
 * way the read-only gating hub and `/manage/restrictions` already do, and
 * it drives a DRY-RUN through the documented PURE seam
 * (`accessViewerAssemble()` / `accessApplySong()`, the exact mechanism
 * `tests/php/test-gating-equivalence.php` already uses to replay synthetic
 * viewers). It never edits `content_gating.php`, `access_context.php`,
 * `access_resolver.php`, `ccli_validator.php`, `licences.php`,
 * `content_access.php`, `licence_registry.php` or
 * `access_tier_validation.php` — rule #28's A/B/C contract is untouched by
 * construction.
 *
 * THE ONLY WRITES THIS FILE PERFORMS are (a) seeding `require_licence:ccli`
 * restriction rows through the shared `restriction_admin.php` core — never
 * a bespoke bulk INSERT — and (b) flipping `content_gating_enabled` (plus
 * this wizard's own seed-sentinel key) through the ALREADY-SHARED
 * `setAppSetting()` core (`includes/maintenance.php`, added by #1671 F6 for
 * `manage/notifications.php`'s VAPID-key write; `manage/configuration.php`'s
 * `$saveSetting` closure delegates to the SAME function as of #2006) —
 * never a second `INSERT INTO tblAppSettings`. `tests/php/test-gating-wizard.php`
 * assertion (c) censuses every site that writes that setting and expects
 * to find exactly two: `manage/configuration.php`'s delegate closure, and
 * `gatingWizardSetFlag()` below.
 *
 * ⚠️ OWNER OVERRIDE ON THE PRECONDITION TABLE (read this before touching
 * `gatingWizardEvaluatePreconditions()`): a design draft of this wizard
 * treated "rules exist that require a CCLI licence, but nobody on this
 * install holds one" as a hard BLOCKER. The owner corrected this to
 * WARN-BUT-ALLOW — a loud, must-tick warning plus the typed confirmation,
 * not a wall the admin cannot get past. The ONLY thing that still blocks
 * the flip outright is a genuine TECHNICAL prerequisite (the gating schema
 * itself not being migrated yet) — see the function's own doc-block for
 * the full truth table.
 *
 * @see appWeb/public_html/manage/gating.php       the page — dispatch + markup, delegates here
 * @see includes/restriction_admin.php             the ONE restriction-row write/validate core
 * @see includes/maintenance.php                   setAppSetting() — the ONE tblAppSettings write core
 * @see includes/access_context.php                accessViewerAssemble() — the pure dry-run seam
 * @see includes/access_resolver.php               accessApplySong() — the pipeline the dry-run drives
 * @see includes/licences.php                      licenceOrgRowQualifies()/licenceCcliQualifies() — never re-implement
 * @see includes/ccli_validator.php                capsForTierFromRegistry() — the registry-backed tier caps
 * @see includes/content_access.php                checkContentAccess() — the entity-axis gate (read-only here)
 * @see manage/gating-noop-verify.php               the byte-identical no-op proof this wizard links to, never re-implements
 * @see tests/php/test-gating-wizard.php            the standing guard over this file + manage/gating.php
 */

/* Direct-hit guard — library, never a page (mirrors licence_registry.php,
   content_access.php, access_context.php). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php';       /* getAppSetting() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licences.php';          /* licenceOrgRowQualifies()/licenceCcliQualifies() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ccli_validator.php';    /* capsForTierFromRegistry() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'restriction_admin.php'; /* restrictionAdminValidate()/…Create()/…Delete() */
/* setAppSetting() — the ONE tblAppSettings write core (#1671 F6) — comes
   from maintenance.php, already required above for getAppSetting(). Not a
   separate require: same file, same load. */
/* songVisibleSql() (#1694 soft-delete) + songServableSql() (#1765 disabled-
   songbook) — every tblSongs read in this file embeds BOTH, exactly as
   manage/gating-noop-verify.php's own sample-picker does, so the wizard's
   "how many songs would this affect?" numbers and its optional row-seeding
   never count or restrict a song nobody can currently see. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_visibility.php';
/* access_context.php / access_resolver.php are required LAZILY inside
   gatingWizardSimulateApply() only — same reasoning content_gating.php's
   delegates use: no file-scope dependency on them until the dry-run is
   actually run, so a page that only asks for the census (steps 1-4) never
   pays for loading the resolver pipeline at all. */

/** The sentinel key this wizard's optional row-seeding step writes its
 *  "what did I add?" record under, so an unconditional rollback can offer
 *  to remove EXACTLY those rows and nothing a curator added by hand
 *  (mirrors gating-noop-verify.php's GATING_NOOP_SENTINEL pattern). */
const GATING_WIZARD_SEED_SENTINEL = 'gating_wizard_seeded';

/* =========================================================================
 * §1 — Readiness probes (moved verbatim from manage/gating.php's
 * gatingHub_tablesExist()/gatingHub_columnExists(), renamed so both the
 * read-only hub and the wizard's own precondition check call the SAME
 * functions instead of two copies).
 * ========================================================================= */

/**
 * True when EVERY named table exists (INFORMATION_SCHEMA, bound). Fail-safe:
 * any probe error reports "absent" so the checklist shows red rather than
 * throwing (rule #9 — migrations are web-run, so "present in schema.sql"
 * never means "present on this env").
 *
 * @param \mysqli  $db
 * @param string[] $tables
 * @return bool
 */
function gatingWizardTablesExist(\mysqli $db, array $tables): bool
{
    try {
        $ph   = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT TABLE_NAME) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($ph)"
        );
        $stmt->bind_param(str_repeat('s', count($tables)), ...$tables);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $n === count($tables);
    } catch (\Throwable $_e) {
        return false;
    }
}

/** True when $table.$column exists (fail-safe absent on error). */
function gatingWizardColumnExists(\mysqli $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ok;
    } catch (\Throwable $_e) {
        return false;
    }
}

/** The five gating-family tables the schema-readiness probe checks for —
 *  ONE list, so the read-only hub, the wizard's step-2 preview and the
 *  wizard's own flip-time precondition check can never drift apart. */
const GATING_WIZARD_SCHEMA_TABLES = [
    'tblAccessTiers', 'tblContentRestrictions', 'tblLicenceTypes',
    'tblGatingCapabilities', 'tblOrganisationLicences',
];

/* =========================================================================
 * §2 — Step-2 "preview the impact" census. Every sub-read is individually
 * wrapped so ONE missing table degrades THAT row to "unknown" rather than
 * failing the whole preview (rule #9) — an un-migrated env still gets a
 * usable, honest wizard.
 * ========================================================================= */

/**
 * The numbers step 2 of the wizard shows: copyrighted vs public-domain song
 * counts, media counts by kind, existing restriction counts by type (+ the
 * full list of `require_licence` rows for display), and the per-tier
 * capability matrix straight from the LIVE registry (never a local matrix
 * copy — rule #28B).
 *
 * @param \mysqli $db
 * @return array{
 *   songs: array{copyrighted: ?int, publicDomain: ?int},
 *   media: array<string,int>,
 *   restrictions: array{byType: array<string,int>, requireLicenceRows: array<int,array<string,mixed>>},
 *   tierCaps: array<string, array<string,bool>|null>,
 *   tierCapsRegistryReadable: bool
 * }
 */
function gatingWizardPreviewStats(\mysqli $db): array
{
    $out = [
        'songs'        => ['copyrighted' => null, 'publicDomain' => null],
        'media'        => [],
        'restrictions' => ['byType' => [], 'requireLicenceRows' => []],
        'tierCaps'     => [],
        'tierCapsRegistryReadable' => true,
    ];

    /* Songs: copyrighted vs public-domain lyric counts. A plain census —
       nothing here reads a per-user viewer, so it costs one query.
       songVisibleSql()/songServableSql() (#1694/#1765, alias '' — the bare
       table name, no JOIN here) keep this an honest count of what a real
       visitor could ever see: a soft-deleted or disabled-songbook song is
       never reachable regardless of the gating flip, so counting it here
       would overstate the impact the wizard is trying to preview. Both
       degrade to '1=1' on an un-migrated install (no cost, no behaviour
       change there). */
    try {
        $visSql = songVisibleSql($db, '') . ' AND ' . songServableSql($db, '');
        $res = $db->query("SELECT LyricsPublicDomain, COUNT(*) AS n FROM tblSongs WHERE {$visSql} GROUP BY LyricsPublicDomain");
        if ($res instanceof \mysqli_result) {
            $copyrighted = 0;
            $pd = 0;
            while ($row = $res->fetch_assoc()) {
                if ((int)$row['LyricsPublicDomain'] === 1) {
                    $pd += (int)$row['n'];
                } else {
                    $copyrighted += (int)$row['n'];
                }
            }
            $res->free();
            $out['songs'] = ['copyrighted' => $copyrighted, 'publicDomain' => $pd];
        }
    } catch (\Throwable $_e) {
        /* stays null -> the page renders "unknown" for this row */
    }

    /* Media counts by kind (audio / midi / sheet-music / musicxml, …).
       songMediaVisibilityPublicFilterSql() (#1968 P4) restricts this to
       PUBLICLY-servable rows — the wizard's preview asks "how much of the
       catalogue is publicly reachable and would be affected", so a
       not-yet-public row (imported, pending review) correctly does not
       inflate the count. Degrades to '' (no filter) on an un-migrated
       install. */
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_media_visibility.php';
        $mediaFilter = songMediaVisibilityPublicFilterSql($db, '');
        $res = $db->query("SELECT Kind, COUNT(*) AS n FROM tblSongMedia WHERE 1=1{$mediaFilter} GROUP BY Kind");
        if ($res instanceof \mysqli_result) {
            $media = [];
            while ($row = $res->fetch_assoc()) {
                $media[(string)$row['Kind']] = (int)$row['n'];
            }
            $res->free();
            $out['media'] = $media;
        }
    } catch (\Throwable $_e) {
        /* absent table -> stays [] */
    }

    /* Existing restrictions, by type, plus the require_licence rows
       themselves (curators reviewing THIS list is exactly what step 2 is
       for — a direct admin-listing read, the same exemption
       manage/restrictions.php already embodies; rule #8 governs REQUEST-path
       evaluation, not admin CRUD/listing). Capped at 200 for display, same
       cap manage/restrictions.php's own list view uses. */
    try {
        $res = $db->query('SELECT RestrictionType, COUNT(*) AS n FROM tblContentRestrictions GROUP BY RestrictionType');
        if ($res instanceof \mysqli_result) {
            $byType = [];
            while ($row = $res->fetch_assoc()) {
                $byType[(string)$row['RestrictionType']] = (int)$row['n'];
            }
            $res->free();
            $out['restrictions']['byType'] = $byType;
        }

        $stmt = $db->prepare(
            "SELECT Id, EntityType, EntityId, TargetId, Priority, Reason
               FROM tblContentRestrictions
              WHERE RestrictionType = 'require_licence'
              ORDER BY Priority DESC, Id DESC
              LIMIT 200"
        );
        $stmt->execute();
        $out['restrictions']['requireLicenceRows'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $_e) {
        /* absent table -> both stay at their [] defaults */
    }

    /* Per-tier capability matrix, straight off the LIVE registry
       (capsForTierFromRegistry(), ccli_validator.php) — never a local copy
       of the tier->cap matrix (rule #28B). A null return means "un-migrated
       / unreadable", surfaced honestly rather than guessed at. */
    foreach (['public', 'free', 'ccli', 'premium', 'pro'] as $tier) {
        $caps = capsForTierFromRegistry($tier);
        if ($caps === null) {
            $out['tierCapsRegistryReadable'] = false;
            $out['tierCaps'][$tier] = null;
        } else {
            $out['tierCaps'][$tier] = $caps;
        }
    }

    return $out;
}

/**
 * Step-4 "licence check": who on this install could actually clear a
 * `require_licence: ccli` hurdle right now? Mirrors the SAME three licence
 * stores `includes/licences.php` reads (branches (e) + (f), plus the
 * personal `tblUsers.CcliNumber` column) but WITHOUT a per-user filter —
 * this is an install-wide census, not a single user's effective set — and
 * qualifies every row through the delegated `licenceOrgRowQualifies()` /
 * `licenceCcliQualifies()` (never a second CCLI format rule — the
 * `test-ccli-resolver.php` ban).
 *
 * @param \mysqli $db
 * @return array{orgs: array<int,array{id:int,name:string,source:string}>, personalCount:int}
 */
function gatingWizardLiveCcliHolders(\mysqli $db): array
{
    $orgs = [];

    /* Store 2 — the legacy single-licence columns on tblOrganisations. */
    try {
        $res = $db->query(
            "SELECT Id, Name, LicenceType, LicenceNumber FROM tblOrganisations
              WHERE IsActive = 1 AND LicenceType = 'ccli'
                AND (LicenceExpiresAt IS NULL OR LicenceExpiresAt > NOW())"
        );
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                if (!licenceOrgRowQualifies((string)$row['LicenceType'], $row['LicenceNumber'] ?? null)) {
                    continue;
                }
                $orgs[(int)$row['Id']] = [
                    'id'     => (int)$row['Id'],
                    'name'   => (string)$row['Name'],
                    'source' => 'legacy',
                ];
            }
            $res->free();
        }
    } catch (\Throwable $_e) {
        /* absent/unreadable -> skip this store */
    }

    /* Store 3 — tblOrganisationLicences, the #640 join table the shipped
       org-licence admin UI actually writes (existence-gated: absent on an
       un-migrated install, and mysqli STRICT throws on a missing table). */
    try {
        $res = $db->query(
            "SELECT o.Id, o.Name, ol.LicenceType, ol.LicenceNumber
               FROM tblOrganisationLicences ol
               JOIN tblOrganisations o ON o.Id = ol.OrganisationId
              WHERE o.IsActive = 1 AND ol.IsActive = 1 AND ol.LicenceType = 'ccli'
                AND (ol.ExpiresAt IS NULL OR ol.ExpiresAt > NOW())"
        );
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                if (!licenceOrgRowQualifies((string)$row['LicenceType'], $row['LicenceNumber'] ?? null)) {
                    continue;
                }
                $orgs[(int)$row['Id']] = [
                    'id'     => (int)$row['Id'],
                    'name'   => (string)$row['Name'],
                    'source' => 'org_licences',
                ];
            }
            $res->free();
        }
    } catch (\Throwable $_e) {
        /* un-migrated -> skip this store, legacy store above still counts */
    }

    /* Personal tblUsers.CcliNumber — bounded, matches licences.php branch
       (b)'s "well-formed, not verified" rule (licenceCcliQualifies()) —
       never a second format check. */
    $personalCount = 0;
    try {
        $res = $db->query(
            "SELECT CcliNumber FROM tblUsers WHERE IsActive = 1 AND CcliNumber <> '' LIMIT 5000"
        );
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                if (licenceCcliQualifies((string)$row['CcliNumber'], true)) {
                    $personalCount++;
                }
            }
            $res->free();
        }
    } catch (\Throwable $_e) {
        /* absent/unreadable -> stays 0 */
    }

    return ['orgs' => array_values($orgs), 'personalCount' => $personalCount];
}

/* =========================================================================
 * §3 — The PURE precondition evaluator. NO I/O of any kind — every input is
 * a value the caller has already resolved. This is what
 * tests/php/test-gating-wizard.php truth-tables directly, with no database
 * connection at all.
 * ========================================================================= */

/**
 * Plain-English messages for each blocker code — shared between the
 * server's 409 response body and (optionally) client-side copy, so the
 * wording lives in exactly one place.
 */
const GATING_WIZARD_BLOCKER_MESSAGES = [
    'schema_unmigrated' => 'This site\'s database hasn\'t had the access-control updates applied yet. '
        . 'Run the pending cards on Database Setup (/manage/setup-database) first — turning the switch '
        . 'on now would simply do nothing, which is confusing at best.',
    'warnings_unacknowledged' => 'Please tick every warning above to confirm you understand what will '
        . 'happen before turning content locking on.',
    'confirm_mismatch' => 'Type ENFORCE exactly (all capitals) to confirm you want to turn content '
        . 'locking on.',
];

/**
 * Plain-English messages for each warning code.
 *
 * ⚠️ `require_rows_without_holder`'s wording is OWNER-SPECIFIED verbatim
 * (2026 override of this wizard's original design, which had this as a
 * hard blocker) — do not soften or rephrase it; it must stay blunt about
 * the exact consequence.
 */
const GATING_WIZARD_WARNING_MESSAGES = [
    'require_rows_without_holder' => 'You are about to require a CCLI licence to view copyrighted songs, '
        . 'but no organisation on this install currently holds one — so nobody here will be able to '
        . 'unlock that content until a licence is added.',
    'no_live_ccli' => 'No organisation or member currently holds a CCLI licence — the ccli tier will '
        . 'grant nothing, and signed-out visitors will lose copyrighted lyrics with no licence route open.',
];

/** Look up a blocker code's plain-English message (falls back to the code
 *  itself so an unmapped future code never renders blank). */
function gatingWizardBlockerMessage(string $code): string
{
    return GATING_WIZARD_BLOCKER_MESSAGES[$code] ?? $code;
}

/** Look up a warning code's plain-English message (same fallback). */
function gatingWizardWarningMessage(string $code): string
{
    return GATING_WIZARD_WARNING_MESSAGES[$code] ?? $code;
}

/**
 * PURE precondition evaluator for the flip. No DB, no I/O — every argument
 * is a fact the caller has already resolved. This is the ONLY thing that
 * can refuse `wizard_flip_gating`.
 *
 * THE TRUTH TABLE (owner-approved shape — see this file's header for the
 * override history):
 *
 *   1. `!$schemaReady || !$tierCapsReady`
 *        -> BLOCKER `schema_unmigrated`. This is a genuine TECHNICAL
 *           prerequisite (the machinery to enforce isn't installed on this
 *           environment yet), not a policy judgement call, so it is the
 *           one thing that stops the flip outright regardless of
 *           acknowledgement.
 *   2. `$requireLicenceRowCount > 0 && $liveCcliHolderCount === 0`
 *        -> WARNING `require_rows_without_holder` (⚠️ owner override: this
 *           was a hard blocker in an earlier draft of this wizard — the
 *           owner chose warn-but-allow instead, because a licensing risk
 *           is a policy choice for the administrator to accept, not a
 *           thing the code should refuse to let them do). Rules already
 *           exist demanding a CCLI licence, and NOBODY on this install
 *           could currently pass them — this is the closest thing to the
 *           blanket-lockout scenario, so it gets the loudest warning text,
 *           but it does NOT block by itself.
 *   3. else if `$liveCcliHolderCount === 0` (no require rows at all)
 *        -> WARNING `no_live_ccli`. Milder: nobody holds a CCLI licence
 *           yet, but nothing is *forcing* one either — the ccli tier just
 *           won't grant anything until someone adds one.
 *   4. Any warning present AND `!$warningsAcknowledged`
 *        -> BLOCKER `warnings_unacknowledged` — a PROCEDURAL blocker (goes
 *           away the instant every warning checkbox is ticked), never a
 *           permanent one.
 *   5. `$confirmText !== 'ENFORCE'`
 *        -> BLOCKER `confirm_mismatch` — the #1218 §2 type-to-confirm
 *           shape, case- and content-exact on purpose (no trimming/
 *           case-folding — an admin who mistypes should have to look at
 *           what they typed, not have it silently accepted).
 *   6. Otherwise -> `ok = true`.
 *
 * NET EFFECT: the ONLY thing that can stop the flip permanently is a
 * genuine technical prerequisite (#1); every LICENSING risk is a loud,
 * must-tick warning plus the typed confirmation, then allowed.
 *
 * @param bool   $schemaReady            The 5-table schema-readiness probe.
 * @param bool   $tierCapsReady          tblAccessTiers.Capabilities column present.
 * @param int    $liveCcliHolderCount    Count of orgs + personal holders who could pass a ccli hurdle right now.
 * @param int    $requireLicenceRowCount Count of existing `require_licence` restriction rows (any target).
 * @param bool   $warningsAcknowledged   Did the admin tick every warning checkbox?
 * @param string $confirmText            The typed confirmation text.
 * @return array{ok: bool, blockers: string[], warnings: string[]}
 */
function gatingWizardEvaluatePreconditions(
    bool $schemaReady,
    bool $tierCapsReady,
    int $liveCcliHolderCount,
    int $requireLicenceRowCount,
    bool $warningsAcknowledged,
    string $confirmText
): array {
    $blockers = [];
    $warnings = [];

    if (!$schemaReady || !$tierCapsReady) {
        $blockers[] = 'schema_unmigrated';
    }

    if ($requireLicenceRowCount > 0 && $liveCcliHolderCount === 0) {
        /* Owner override — warn, never block (see the doc-block above). */
        $warnings[] = 'require_rows_without_holder';
    } elseif ($liveCcliHolderCount === 0) {
        $warnings[] = 'no_live_ccli';
    }

    if ($warnings !== [] && !$warningsAcknowledged) {
        $blockers[] = 'warnings_unacknowledged';
    }

    if ($confirmText !== 'ENFORCE') {
        $blockers[] = 'confirm_mismatch';
    }

    return ['ok' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings];
}

/* =========================================================================
 * §4 — Step-3 optional row-seeding: a PURE planner, then the DB-touching
 * seed function that delegates every write to restriction_admin.php.
 * ========================================================================= */

/**
 * PURE row-shaping: turn a list of song rows (`{SongId, LyricsPublicDomain}`)
 * into the restriction-row PLAN the wizard would create — never the rows
 * themselves (no DB access here, so this is exactly what
 * tests/php/test-gating-wizard.php truth-tables for the no-wildcard /
 * no-PD-song / correct-shape guarantees).
 *
 * ⚠️ NEVER emits `EntityId === '*'` — a wildcard row would deny EVERY song
 * of the entity type, public-domain included, to anyone without a `ccli`
 * licence (content_access.php's documented blanket-lockout hazard) AND
 * destroys `checkBulkAccess()`'s early-exit for every other rule on the
 * install. This function actively skips any row whose SongId is empty or
 * literally `*` as defence in depth, even though the caller (which reads
 * real SongIds from `tblSongs`) should never produce one.
 *
 * @param array<int,array{SongId?:mixed,LyricsPublicDomain?:mixed}> $songs
 * @return array<int,array{EntityType:string,EntityId:string,RestrictionType:string,
 *              TargetType:string,TargetId:string,Effect:string,Priority:int,Reason:string}>
 */
function gatingWizardPlanRowsFor(array $songs): array
{
    $plans = [];
    foreach ($songs as $s) {
        $songId = trim((string)($s['SongId'] ?? ''));
        if ($songId === '' || $songId === '*') {
            continue; /* never a wildcard (or empty) row */
        }
        if (!empty($s['LyricsPublicDomain'])) {
            continue; /* never seed a public-domain song */
        }
        $plans[] = [
            'EntityType'      => 'song',
            'EntityId'        => $songId,
            'RestrictionType' => 'require_licence',
            'TargetType'      => 'licence_type',
            'TargetId'        => 'ccli',
            'Effect'          => 'deny',
            'Priority'        => 100,
            'Reason'          => 'A CCLI licence is required to view this copyrighted song.',
        ];
    }
    return $plans;
}

/** Hard cap on how many rows a single seeding request will plan/create —
 *  matches the LIMIT below; a catalogue larger than this needs more than
 *  one "Add the rules" click, which the wizard's readback makes clear. */
const GATING_WIZARD_SEED_ROW_CAP = 5000;

/**
 * Build the seeding PLAN for scope `songbooks` (a curator-picked set of
 * abbreviations) or `all` (every copyrighted song) — `none` (the default)
 * always returns `[]`. Excludes songs that ALREADY have a
 * `require_licence:ccli` row so a re-run of the wizard is idempotent
 * (never double-seeds).
 *
 * @param \mysqli  $db
 * @param string   $scope         'none' | 'songbooks' | 'all'
 * @param string[] $songbookAbbrs Only consulted when $scope === 'songbooks'.
 * @return array<int,array<string,mixed>> row plans, see gatingWizardPlanRowsFor()
 */
function gatingWizardPlanSeed(\mysqli $db, string $scope, array $songbookAbbrs): array
{
    if ($scope !== 'songbooks' && $scope !== 'all') {
        return [];
    }

    /* songVisibleSql()/songServableSql() (#1694/#1765) — never plan a
       restriction row for a song that is soft-deleted or lives in a
       disabled songbook: nobody can reach it today, and seeding a row for
       it would only surprise a curator later if it were ever restored. */
    $where = ['LyricsPublicDomain = 0', songVisibleSql($db, ''), songServableSql($db, '')];
    $types = '';
    $args  = [];

    if ($scope === 'songbooks') {
        $songbookAbbrs = array_values(array_unique(array_filter(
            array_map('strval', $songbookAbbrs),
            static fn(string $a): bool => $a !== ''
        )));
        if ($songbookAbbrs === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($songbookAbbrs), '?'));
        $where[] = "SongbookAbbr IN ($ph)";
        $types  .= str_repeat('s', count($songbookAbbrs));
        $args    = array_merge($args, $songbookAbbrs);
    }

    $sql = 'SELECT SongId, LyricsPublicDomain FROM tblSongs WHERE '
         . implode(' AND ', $where)
         . ' ORDER BY SongId ASC LIMIT ' . GATING_WIZARD_SEED_ROW_CAP;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$args);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows === []) {
        return [];
    }

    /* Exclude songs that already carry a require_licence:ccli row — one
       grouped IN fetch, not one query per song. */
    $candidateIds = array_map(static fn($r) => (string)$r['SongId'], $rows);
    $already = [];
    $ph2 = implode(',', array_fill(0, count($candidateIds), '?'));
    $stmt2 = $db->prepare(
        "SELECT DISTINCT EntityId FROM tblContentRestrictions
          WHERE EntityType = 'song' AND RestrictionType = 'require_licence' AND TargetId = 'ccli'
            AND EntityId IN ($ph2)"
    );
    $stmt2->bind_param(str_repeat('s', count($candidateIds)), ...$candidateIds);
    $stmt2->execute();
    $already = array_column($stmt2->get_result()->fetch_all(MYSQLI_ASSOC), 'EntityId');
    $stmt2->close();

    $alreadySet = array_fill_keys($already, true);
    $rows = array_values(array_filter(
        $rows,
        static fn(array $r): bool => empty($alreadySet[(string)$r['SongId']])
    ));

    return gatingWizardPlanRowsFor($rows);
}

/**
 * Create every planned row through the ONE restriction-write core
 * (`restrictionAdminValidate()` + `restrictionAdminCreate()` — never a
 * bespoke bulk INSERT), then record what was created in the sentinel so an
 * unconditional rollback can offer to remove exactly those rows.
 *
 * @param \mysqli $db
 * @param array<int,array<string,mixed>> $plans From gatingWizardPlanSeed().
 * @param string  $scope     'songbooks' | 'all' — recorded for the sentinel/readback only.
 * @param int|null $byUserId The acting admin's user id, recorded for the sentinel only.
 * @return array{created: int[], skipped: int}
 */
function gatingWizardSeedRestrictions(\mysqli $db, array $plans, string $scope, ?int $byUserId): array
{
    $created = [];
    $skipped = 0;

    foreach ($plans as $plan) {
        /* Defence in depth — the planner never emits a wildcard, but this
           write path re-checks it anyway before it ever reaches the DB. */
        if ((string)($plan['EntityId'] ?? '') === '*') {
            $skipped++;
            continue;
        }
        $result = restrictionAdminValidate([
            'entity_type'      => $plan['EntityType']      ?? 'song',
            'entity_id'        => $plan['EntityId']        ?? '',
            'restriction_type' => $plan['RestrictionType'] ?? 'require_licence',
            'target_type'      => $plan['TargetType']      ?? 'licence_type',
            'target_id'        => $plan['TargetId']        ?? 'ccli',
            'effect'           => $plan['Effect']          ?? 'deny',
            'priority'         => $plan['Priority']        ?? 100,
            'reason'           => $plan['Reason']          ?? '',
        ]);
        if ($result['error'] !== null) {
            $skipped++;
            continue;
        }
        $created[] = restrictionAdminCreate($db, $result['fields']);
    }

    /* Merge with any ids already on the sentinel (a second seeding run —
       "songbooks" then "all", say — should not forget the first batch). */
    $existing = gatingWizardReadSeedSentinel($db);
    $existingIds = array_map('intval', is_array($existing['ids'] ?? null) ? $existing['ids'] : []);
    $allIds = array_values(array_unique(array_merge($existingIds, $created)));

    $payload = (string)json_encode([
        'ids'   => $allIds,
        'scope' => $scope,
        'at'    => gmdate('c'),
        'by'    => $byUserId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    setAppSetting($db, GATING_WIZARD_SEED_SENTINEL, $payload);

    return ['created' => $created, 'skipped' => $skipped];
}

/** Read the wizard's own "what did I seed?" sentinel (decoded), or `[]`
 *  when none / unreadable — never throws (mirrors gating-noop-verify.php's
 *  gatingNoop_readBaseline()). */
function gatingWizardReadSeedSentinel(\mysqli $db): array
{
    try {
        $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
        $key  = GATING_WIZARD_SEED_SENTINEL;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row === null) {
            return [];
        }
        $decoded = json_decode((string)$row[0], true);
        return is_array($decoded) ? $decoded : [];
    } catch (\Throwable $_e) {
        return [];
    }
}

/** Clear the seed sentinel back to an empty record (used after a rollback
 *  has removed the rows it named — never deletes the setting row outright,
 *  matching the upsert-only shape setAppSetting() always uses). */
function gatingWizardClearSeedSentinel(\mysqli $db): void
{
    $payload = (string)json_encode(
        ['ids' => [], 'scope' => null, 'at' => gmdate('c'), 'by' => null],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    setAppSetting($db, GATING_WIZARD_SEED_SENTINEL, $payload);
}

/* =========================================================================
 * §5 — Step-6 "test a song": drives the REAL pipeline through the pure
 * seam, never a fork, and never lets lyric text reach the JSON response.
 * ========================================================================= */

/**
 * Run ONE simulated viewer through the real resolver pipeline. This is
 * EXACTLY how `tests/php/test-gating-equivalence.php` already drives
 * viewers synthetically (`access_context.php`'s own doc-block names this as
 * the seam's purpose) — it is NOT a second resolver, does not touch the
 * flag, and this whole file is unreachable outside the admin-gated wizard
 * page.
 *
 * @param array  $song     A song_detail-shape array (SongData::getSongById()).
 * @param string $tier     'public' | 'free' | 'ccli' | 'premium' | 'pro'.
 * @param bool   $hasCcli  Simulated CCLI-holder flag for this viewer.
 * @param string[] $licences Simulated effective licence types (e.g. ['ccli']).
 * @return array The gated song payload (same shape as $song, trimmed).
 */
function gatingWizardSimulateApply(array $song, string $tier, bool $hasCcli, array $licences): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'access_context.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'access_resolver.php';
    /* Force gatingEnabled=true — the whole point of a DRY RUN is "what
       WOULD happen if this were on", independent of the live flag, so the
       wizard can show a meaningful preview even before the flip. */
    $viewer = accessViewerAssemble(
        true,       /* gatingEnabled (forced on for the simulation) */
        null,       /* userId — anonymous simulated viewer */
        'PWA',      /* platform */
        $tier,
        $hasCcli,
        $licences,
        null,       /* presenceToken */
        false,      /* presenceCcli */
        false       /* bypass */
    );
    return accessApplySong($song, $viewer);
}

/**
 * Reduce a gated song payload to a STRUCTURAL summary only — booleans,
 * counts, and a list of media KINDS (never a kind's bytes or url). This is
 * what keeps the wizard's test-a-song endpoint from becoming a second,
 * unintended way to read a song's lyrics — the admin can already see every
 * lyric elsewhere in `/manage`; this endpoint's job is to answer "would
 * this viewer see them at all?", not to hand the text over again.
 *
 * `tests/php/test-gating-wizard.php` assertion (j) feeds this function a
 * synthetic song whose component text contains a marker string and asserts
 * the marker is absent from `json_encode()` of the result — this function
 * must never grow a key that echoes any part of `$gated['components']`,
 * `$gated['translations']`, `$gated['annotations']` or
 * `$gated['vocalParts']` verbatim.
 *
 * @param array $gated The output of gatingWizardSimulateApply() or contentGatingApply().
 * @param array $raw   The pre-gating song (unused today — kept for a future
 *                      "what was removed" diff without widening what THIS
 *                      function emits; never read into the response).
 * @return array{contentRestricted:bool, restrictionReason:string,
 *   lyricsIncluded:bool, componentCount:int, translationsIncluded:bool,
 *   mediaKinds:string[], hasAudio:bool, hasSheetMusic:bool, offlineAllowed:bool}
 */
function gatingWizardSummarisePayload(array $gated, array $raw): array
{
    $components = $gated['components'] ?? null;
    $translations = $gated['translations'] ?? null;
    $media = is_array($gated['media'] ?? null) ? $gated['media'] : [];

    return [
        'contentRestricted'    => !empty($gated['contentRestricted']),
        'restrictionReason'    => (string)($gated['restrictionReason'] ?? ''),
        'lyricsIncluded'       => $components !== null,
        'componentCount'       => is_array($components) ? count($components) : 0,
        'translationsIncluded' => $translations !== null,
        'mediaKinds'           => array_values(array_unique(array_map(
            static fn($m): string => is_array($m) ? (string)($m['kind'] ?? '') : '',
            $media
        ))),
        'hasAudio'      => !empty($gated['hasAudio']),
        'hasSheetMusic' => !empty($gated['hasSheetMusic']),
        'offlineAllowed' => !empty($gated['offlineAllowed']),
    ];
}

/* =========================================================================
 * §6 — The flip. ONE thin function, so a tree-derived census can verify
 * exactly two call sites write `content_gating_enabled` in the whole repo.
 * ========================================================================= */

/**
 * Set (or clear) the master content-gating switch. Thin on purpose: this
 * function's ENTIRE body is one delegate call, so
 * `tests/php/test-gating-wizard.php` can census every place the literal
 * `'content_gating_enabled'` is written and expect to find exactly this
 * function plus `manage/configuration.php`'s `$saveSetting` delegate.
 *
 * Callers decide WHETHER it is safe to call this — this function itself
 * enforces NOTHING (the precondition check happens in the caller, before
 * this is ever reached, for the flip-ON path; the flip-OFF/rollback path
 * deliberately calls this with NO precondition check at all — rollback
 * must always work).
 *
 * @param \mysqli $db
 * @param bool    $on true = '1' (enforcing), false = '0' (dormant).
 * @return void
 */
function gatingWizardSetFlag(\mysqli $db, bool $on): void
{
    setAppSetting($db, 'content_gating_enabled', $on ? '1' : '0');
}
