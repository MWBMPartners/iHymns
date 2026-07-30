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
 *   (b) Personal tblUsers.CcliNumber — counts as a 'ccli' licence when the
 *       number is present and WELL-FORMED per validateCcliNumber() (#1668).
 *       NOT gated on tblUsers.CcliVerified — see licenceCcliQualifies()
 *       for why that flag cannot be a precondition.
 *   (c) Licences on every organisation the user belongs to
 *       (tblOrganisationMembers).
 *   (d) Licences on every ancestor organisation, walking up
 *       tblOrganisations.ParentOrgId recursively (bounded at
 *       LICENCE_INHERITANCE_MAX_DEPTH to defend against cycles even
 *       though the FK should prevent them).
 *   (e) Legacy tblOrganisations.LicenceType column — if an org hasn't
 *       yet been migrated to tblContentLicences rows, its single-column
 *       licence still contributes.
 *   (f) tblOrganisationLicences rows for every org in that same chain
 *       (#640) — the multi-licence-per-org join table the LIVE admin UI
 *       actually writes (#1668).
 *
 * THE THREE ORG-LICENCE STORES (#1668 — why (f) exists)
 * -----------------------------------------------------
 * ELI5: churches typed their CCLI number into the website, and the part of
 * the code that decides "may this person see copyrighted lyrics?" was
 * reading a completely different drawer. So it always found nothing.
 *
 * Detail: an organisation's licence can live in THREE places, and before
 * #1668 this resolver read only two of them —
 *   1. `tblContentLicences`     — read by (a)/(c)/(d). It has NO writer
 *                                 anywhere in the codebase, so on a normal
 *                                 install it is permanently empty.
 *   2. `tblOrganisations.LicenceType/LicenceNumber/LicenceExpiresAt`
 *                               — read by (e). The LEGACY single-licence
 *                                 columns; still written by some paths.
 *   3. `tblOrganisationLicences` — the #640 join table that the shipped UI
 *                                 (`/manage/organisations`,
 *                                 `/manage/my-organisations`) and the
 *                                 `org_admin_licence_add/_change/_remove`
 *                                 API actions ALL write. It was read ZERO
 *                                 times here.
 * Net effect before this fix: an org that entered its CCLI licence through
 * the only UI that exists contributed NOTHING to its members' effective
 * licence set — organisation CCLI licences did not work at all. Branch (f)
 * is the fix. `migrate-organisation-licences.php:172` backfills store 3
 * FROM store 2, confirming 3 is the intended canonical org store.
 *
 * STRICT-MODE SAFETY for (f): mysqli runs under MYSQLI_REPORT_STRICT
 * (db_mysql.php), so querying a table that does not exist THROWS — it does
 * not return false. Migrations are web-run (never auto-applied) and the
 * three docroots share one MySQL, so a request CAN hit an install where
 * `tblOrganisationLicences` has not been created. Branch (f) is therefore
 * wrapped and memoised: an un-migrated install degrades to "no org licence
 * from store 3" and keeps every other branch working, rather than 500-ing
 * the page (the #1228 class). Mirrors manage/my-organisations.php:385.
 *
 * Results are cached per-request in a static map so repeated calls from
 * checkContentAccess() + API handlers hit the DB once per user.
 *
 * Require order: this file's queries use mysqli prepared statements
 * via getDbMysqli(); db_mysql.php is required at the top so callers
 * don't need to load it themselves.
 *
 * @see includes/content_gating.php  contentGating_userHasCcli() — delegates here
 * @see includes/service_mode.php    serviceMode_presenceCcliNumber() — the
 *      Service-Mode Phase-3 unlock, which reads the same two org stores
 * @link https://www.php.net/manual/en/mysqli-driver.report-mode.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* validateCcliNumber() — the ONE CCLI format rule (#1668). Required here, not
   re-implemented, so the resolver and the /manage forms can never disagree
   about what a valid CCLI number looks like. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ccli_validator.php';

/* Cap org hierarchy traversal depth. A real church → diocese → conference
   chain is typically 2–3 deep; 10 is generous headroom and stops any
   accidental cycle from looping forever. */
const LICENCE_INHERITANCE_MAX_DEPTH = 10;

/**
 * Does a declared CCLI number / licence row count as a live 'ccli' licence?
 *
 * ⚠️ THIS GATE IS DELIBERATELY FORMAT-ONLY, AND MUST STAY THAT WAY (#1668).
 *
 * ELI5: we can check that a CCLI number LOOKS like a CCLI number. We cannot
 * ring CCLI up and ask whether it is really yours. So this is a good-faith
 * declaration check, on purpose — not proof.
 *
 * WHY, in detail — READ THIS BEFORE "HARDENING" IT:
 * CCLI publishes no verification API, no check-digit algorithm, and no
 * public register we can query. There is therefore NO mechanism anywhere in
 * this project that can truthfully set `tblUsers.CcliVerified = 1` at scale.
 * An earlier draft of this work required that flag; the owner corrected it,
 * because requiring a flag nothing can legitimately set denies essentially
 * every real user — the exact opposite of the requirement this resolver
 * exists to satisfy ("users, OR users part of organisations, with a valid
 * CCLI license assigned to them… it must be done reliably/robustly").
 *
 * So validity here = **the number is well-formed and present**, per the
 * owner's stated criterion: all digits, of normal length. `CcliVerified`
 * survives untouched as an ADVISORY curator trust signal (surfaced as
 * `verified` in the returned rows) — it must never become a precondition
 * again unless a real verification path exists to satisfy it.
 *
 * The format rule itself is NOT re-implemented here: it is
 * `validateCcliNumber()` in includes/ccli_validator.php, the ONE place that
 * knows what a CCLI number looks like (digits only, 5–8 long, tolerating
 * spaces / `-` / `.` / `,` and a leading `#` or `CCLI:`).
 * `tests/php/test-ccli-resolver.php` derives that from the tree and fails if
 * any CCLI-handling function grows a second digits/length check.
 *
 * @param string|null $number        The declared number (may be null/empty).
 * @param bool        $requireNumber true for a PERSONAL licence — there the
 *                                   number IS the licence, so blank means no
 *                                   licence. false for an ORGANISATION row,
 *                                   where the row itself is the declaration
 *                                   and operators are permitted to leave the
 *                                   number blank (serviceMode_presenceCcli-
 *                                   Number() has always returned '' for that
 *                                   case). A blank org number still counts;
 *                                   a PRESENT one must be well-formed.
 * @return bool
 * @see includes/ccli_validator.php validateCcliNumber() — the ONE format rule
 */
function licenceCcliQualifies(?string $number, bool $requireNumber): bool
{
    $n = trim((string)$number);
    if ($n === '') {
        return !$requireNumber;
    }
    /* Delegate — never re-implement. A second format rule is precisely the
       fork #1668 exists to remove (rule #35: a mechanism, not a comment). */
    return !empty(validateCcliNumber($n)['valid']);
}

/**
 * Does a licence ROW (from any of the three licence stores) qualify?
 *
 * ELI5: every kind of licence except CCLI is taken at face value; a CCLI one
 * also has to have a number that looks like a CCLI number, if it has a
 * number at all.
 *
 * Non-'ccli' types (ihymns_basic, ihymns_pro, mrl, custom, …) pass straight
 * through — this function deliberately knows about exactly one licence type,
 * because CCLI is the only one with a published format and the only one the
 * owner's criterion covers.
 *
 * A CCLI row with a BLANK number still qualifies: a curator-issued row (or an
 * org whose operator left the number field empty) IS the declaration, and
 * serviceMode_presenceCcliNumber() has always tolerated '' there. A row with
 * a number PRESENT must have a well-formed one — a row reading "n/a" or
 * "pending" is not a licence.
 *
 * @param string      $type The row's LicenceType.
 * @param string|null $key  The row's number / key, if any.
 * @return bool
 */
function licenceOrgRowQualifies(string $type, ?string $key): bool
{
    if ($type !== 'ccli') {
        return true;
    }
    return licenceCcliQualifies($key, false);
}

/**
 * PURE — flatten a licence set (the getUserEffectiveLicences() shape) to a
 * de-duplicated list of type strings.
 *
 * ELI5: turn "here are all your licence cards" into "here are the KINDS of
 * card you hold", with no repeats.
 *
 * Split out of getUserEffectiveLicenceTypes() so the flattening can be
 * unit-tested without a database (#1668 / rule #34: prefer a pure function a
 * test can actually exercise over an integration path a test can only mock).
 *
 * @param array<int, array{type:string}> $licences
 * @return string[]
 */
function licenceTypesFromSet(array $licences): array
{
    return array_values(array_unique(array_map(
        static fn(array $l): string => (string)$l['type'],
        $licences
    )));
}

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
 *     inherited: bool,          // true if reached via ancestor org
 *     verified: ?bool           // ADVISORY ONLY (#1668) — true/false from
 *                               // tblUsers.CcliVerified for the personal
 *                               // CCLI branch; NULL for every store that
 *                               // has no verification signal at all.
 *                               // NOTHING gates on this: see
 *                               // licenceCcliQualifies() for why a flag we
 *                               // have no way to set truthfully cannot be a
 *                               // precondition. It is surfaced so a curator
 *                               // reading `licence_list` can see "declared,
 *                               // not confirmed" — not so code can branch
 *                               // on it.
 * }>
 */
function getUserEffectiveLicences(?int $userId): array
{
    static $cache = [];
    /* Request-lifetime memo of "tblOrganisationLicences is absent on this
       install" — see branch (f). Static so the probe cost (one caught
       exception) is paid once, not once per user resolved. */
    static $orgLicencesMissing = false;
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
        if (!licenceOrgRowQualifies((string)$r['type'], $r['key'] ?? null)) { continue; }
        $licences[] = [
            'type'       => (string)$r['type'],
            'key'        => (string)$r['key'],
            'expires_at' => $r['expires_at'],
            'source'     => 'user',
            'source_id'  => $userId,
            'inherited'  => false,
            'verified'   => null,
        ];
    }

    /* (b) personal tblUsers.CcliNumber — WELL-FORMED, not "verified" (#1668).
       ELI5: if you've typed in something that looks like a real CCLI number,
       we take you at your word — because there is no-one we can ask.
       WHY: see licenceCcliQualifies(). CcliVerified is still READ, and still
       surfaced as the advisory `verified` field, but it is NOT a precondition:
       nothing in this project can set it truthfully at scale, so gating on it
       would deny every genuine licence holder.
       This SELECT is also the ONLY place in the tree that reads CcliNumber +
       CcliVerified together — content_gating.php and api.php's tier_check both
       carried their own copies and could disagree with this branch.
       tests/php/test-ccli-resolver.php fails if a second copy reappears. */
    $stmt = $db->prepare('SELECT CcliNumber, CcliVerified FROM tblUsers WHERE Id = ? AND IsActive = 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && licenceCcliQualifies($row['CcliNumber'] ?? null, true)) {
        $licences[] = [
            'type'       => 'ccli',
            'key'        => (string)$row['CcliNumber'],
            'expires_at' => null,
            'source'     => 'user',
            'source_id'  => $userId,
            'inherited'  => false,
            'verified'   => !empty($row['CcliVerified']),
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
            if (!licenceOrgRowQualifies((string)$r['type'], $r['key'] ?? null)) { continue; }
            $orgId = (int)$r['OrgId'];
            $licences[] = [
                'type'       => (string)$r['type'],
                'key'        => (string)$r['key'],
                'expires_at' => $r['expires_at'],
                'source'     => 'org',
                'source_id'  => $orgId,
                'inherited'  => $allOrgIds[$orgId] ?? false,
                'verified'   => null,
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
            if (!licenceOrgRowQualifies((string)$r['LicenceType'], $r['LicenceNumber'] ?? null)) { continue; }
            $orgId = (int)$r['Id'];
            $licences[] = [
                'type'       => (string)$r['LicenceType'],
                'key'        => (string)$r['LicenceNumber'],
                'expires_at' => $r['LicenceExpiresAt'],
                'source'     => 'org',
                'source_id'  => $orgId,
                'inherited'  => $allOrgIds[$orgId] ?? false,
                'verified'   => null,
            ];
        }

        /* (f) tblOrganisationLicences — the #640 multi-licence-per-org join
               table, and the ONE store the shipped org-licence UI writes
               (#1668; see this file's header for the three-store history).
               Joined back to tblOrganisations so a DEACTIVATED org confers
               nothing, matching branch (e)'s `IsActive = 1` semantics and
               ccli_validator.php's `c.IsActive = 1`.

               Existence-gated: on an install where the migration has not
               been run this table is absent, and under mysqli STRICT that
               is a THROW, not a false. checkContentAccess() calls us with
               no try/catch of its own, so an unguarded read here would
               500 the song page on such an install. */
        if (!$orgLicencesMissing) {
            try {
                $stmt = $db->prepare(
                    "SELECT ol.OrganisationId, ol.LicenceType, ol.LicenceNumber, ol.ExpiresAt
                       FROM tblOrganisationLicences ol
                       JOIN tblOrganisations o ON o.Id = ol.OrganisationId
                      WHERE ol.OrganisationId IN ($placeholders)
                        AND o.IsActive = 1
                        AND ol.IsActive = 1
                        AND ol.LicenceType <> ''
                        AND ol.LicenceType <> 'none'
                        AND (ol.ExpiresAt IS NULL OR ol.ExpiresAt > NOW())"
                );
                $stmt->bind_param($orgIdTypes, ...$orgIds);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($rows as $r) {
                    if (!licenceOrgRowQualifies((string)$r['LicenceType'], $r['LicenceNumber'] ?? null)) { continue; }
                    $orgId = (int)$r['OrganisationId'];
                    $licences[] = [
                        'type'       => (string)$r['LicenceType'],
                        'key'        => (string)$r['LicenceNumber'],
                        'expires_at' => $r['ExpiresAt'],
                        'source'     => 'org',
                        'source_id'  => $orgId,
                        'inherited'  => $allOrgIds[$orgId] ?? false,
                        'verified'   => null,
                    ];
                }
            } catch (\Throwable $_e) {
                /* Pre-migration install (or a transient read failure).
                   Memoise so we attempt it at most once per request rather
                   than throwing/catching for every user we resolve, and
                   degrade to "no licence from store 3" — never to a 500. */
                $orgLicencesMissing = true;
            }
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
    return licenceTypesFromSet(getUserEffectiveLicences($userId));
}

/**
 * THE ONE ANSWER to "does this user hold a valid CCLI licence?" (#1668).
 *
 * ELI5: one question, one place that answers it, so two parts of the site
 * can never disagree about whether you're allowed to see a copyrighted song.
 *
 * WHY this function exists: the same question was being answered by THREE
 * different pieces of code that did not agree —
 *   - `includes/licences.php` branch (b) accepted ANY non-empty personal
 *     number, well-formed or not, and read no org store the UI writes;
 *   - `includes/content_gating.php::contentGating_userHasCcli()` ran its own
 *     `SELECT CcliNumber, CcliVerified`, required the verification flag, and
 *     ignored organisations entirely;
 *   - `api.php`'s `tier_check` case ran a third, byte-identical copy of that
 *     same SELECT.
 * So two gates could return OPPOSITE answers about the same user and the same
 * song. All three now route through here. `tests/php/test-ccli-resolver.php`
 * derives the check from the tree and fails if a second copy of the personal
 * CCLI query — or a second CCLI FORMAT rule — reappears in any file, so the
 * fork cannot regrow (rule #35 — a mechanism, not a comment).
 *
 * WHAT COUNTS as valid:
 *   - a WELL-FORMED personal number (branch (b) — `validateCcliNumber()`
 *     says yes; NOT gated on `CcliVerified`, see licenceCcliQualifies()), OR
 *   - a live `ccli` row on ANY organisation in the user's inheritance chain,
 *     from EITHER org store (branches (c)/(d)/(e)/(f)) — which is what makes
 *     the owner's requirement ("users OR users part of organisations with a
 *     valid CCLI license") actually true.
 *
 * Anonymous (null id) is always false. This does NOT catch — callers that
 * need deny-on-error wrap it (contentGating_userHasCcli does exactly that).
 *
 * @param int|null $userId Authenticated user id, or null for anonymous.
 * @return bool
 */
function userHasValidCcli(?int $userId): bool
{
    if ($userId === null) {
        return false;
    }
    return in_array('ccli', getUserEffectiveLicenceTypes($userId), true);
}
