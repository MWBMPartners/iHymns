<?php

declare(strict_types=1);

/**
 * iHymns — CCLI print-usage logging (#1767 remainder P5)
 *
 * ELI5: whenever a signed-in person who holds a CCLI licence prints or
 * downloads a song's lyrics, this file is the ONE place that quietly writes
 * down "song X was copied Y times" — the exact number CCLI reporting asks
 * churches for. It never writes anything for anyone else.
 *
 * DETAIL
 * ------
 * `.claude/print-templates-1767-remainder-plan.md` §6 ("AK"). Owner decision
 * #2: count = COPIES, PROMPTED (never inferred from page count or click
 * count) — "an under-count is a compliance defect", so a curator is asked
 * "how many copies?" and the answer is what gets logged, nothing cleverer.
 *
 * STORAGE: `tblSongUsageEvents` (`appWeb/.sql/schema.sql`, #1090 P5,
 * `migrate-usage-events.php`) already exists, dormant — `UsageContext`'s
 * documented vocabulary already includes `'printed'`, and `Quantity` already
 * exists ("Copies/prints/attendance count where the licensor needs it").
 * ZERO new schema for this phase (rule #20's "grow the JSON/vocab, don't
 * ALTER" already paid for this in advance) — this file is that table's
 * FIRST writer (previously: registry card + one FK-cascade reference only).
 *
 * THE ONE GATE (never re-forked elsewhere, rule #22): `printUsageLog()`
 * re-resolves the caller's licence itself via
 * `printUsageResolveCcliLicence()` — NEVER trusts a client's earlier
 * "licensed: true" claim, because a context read and a log write are two
 * separate requests and the licence could have lapsed (or the request could
 * simply be forged) between them. `getUserEffectiveLicences()` +
 * `licenceCcliQualifies()` (includes/licences.php, #462/#1668) are the
 * SAME pair every other CCLI-gated feature in this codebase already uses
 * (#1770's host-CCLI unlock is the most recent — see
 * `includes/service_mode.php:1049-1051`) — never a second licence-format
 * check.
 *
 * THE ONE INSERT: `_usageEventInsert()`. Both write funnels
 * (`printUsageLog()` for prints, `projectionUsageLog()` for Service-Mode
 * projections, #1897 W2) delegate to this ONE private core — the single
 * `INSERT INTO tblSongUsageEvents` literal anywhere in the tree. Each funnel
 * has ALREADY passed its own licence gate before it calls the core; the core
 * only writes. Never a raw `INSERT INTO tblSongUsageEvents` anywhere else
 * (`tests/php/test-print-usage-ccli-gate.php` polices this — exactly one
 * literal, in this file, inside `_usageEventInsert()`).
 *
 * THE WRITE FUNNELS — THREE now, all gated, none bypassing the core:
 *   1. `printUsageLog()` — the PRINT path. Two callers reach it: the browser-
 *      print path via `api.php?action=print_usage_log` (auth + the file's
 *      global X-Requested-With POST gate, rule #29 posture) and the server-PDF
 *      path DIRECTLY from `manage/print-pdf.php` (no HTTP round-trip — it is
 *      already the authenticated request that produces the file). Gate = the
 *      CALLER-user's own CCLI licence (`printUsageResolveCcliLicence()`).
 *   2. `projectionUsageLog()` — the SERVICE-MODE projection path (#1897 W2).
 *      One row per (service session, song): "song X was projected in service
 *      session S under org O's CCL licence". Hooked inside the ONE broadcast
 *      core `serviceMode_applyBroadcast()` (`includes/service_mode.php`) so
 *      every broadcaster — `service_broadcast`, `service_drive`, and any
 *      future one — inherits it. Gate = the SESSION ORG's live CCLI licence
 *      (`printUsageResolveOrgCcliLicence()`), never the operator's personal
 *      licence and never a client claim — and this funnel is the FIRST writer
 *      to populate `tblSongUsageEvents.LicenceId` (the org resolver surfaces
 *      `tblOrganisationLicences.Id`, which the user-anchored resolver cannot).
 *
 * FAIL-SOFT, STRICT-safe: existence-gated (`_printUsageTableExists()`,
 * mirrors `includes/read_rate_limit.php::_readRateLimitTableExists()`) so
 * an un-migrated install degrades to a silent no-op `false`, never a 500
 * under mysqli's `MYSQLI_REPORT_STRICT` (CLAUDE.md red-flag: "a page or
 * handler that treats query() as returning false on error" — the INVERSE
 * mistake this file avoids is trusting an unguarded query not to throw at
 * all). Best-effort throughout: a failed print-usage log must NEVER block
 * the actual print/download the user is waiting for.
 *
 * §6.3 — THE SERVER-ENFORCED CCLI NOTICE: `printUsageCcliNoticeText()` is
 * the ONE format-string source for the compliance notice
 * ("CCLI Song #… · Reproduced under CCL Licence #…. Used by permission.").
 * `js/modules/print.js`'s `ccliNoticeText()` mirrors the SAME literal
 * pieces for the browser path's ADVISORY footer (a browser print can't be
 * enforced — the client just cannot stop a user editing the DOM before
 * printing); `manage/print-pdf.php` calls THIS function to build the
 * mPDF-stamped footer that a client cannot strip, because it never sees the
 * HTML that produces it. The two literals are held in lockstep by
 * `tests/php/test-print-block-registry.php` (rule #35 — a mechanism, not
 * this paragraph).
 *
 * @see .claude/print-templates-1767-remainder-plan.md §6  the full design this file implements
 * @see appWeb/public_html/includes/licences.php            getUserEffectiveLicences() / licenceCcliQualifies() — the ONE licence resolver
 * @see appWeb/public_html/includes/read_rate_limit.php      the existence-gate + fail-soft pattern this mirrors
 * @see appWeb/public_html/api.php                           print_usage_context / print_usage_log actions (the browser-path funnel)
 * @see appWeb/public_html/manage/print-pdf.php               the server-PDF funnel (direct call, no HTTP round-trip)
 * @see appWeb/public_html/js/modules/print.js                ccliNoticeText() — the browser-side ADVISORY mirror of printUsageCcliNoticeText()
 * @see tests/php/test-print-usage-ccli-gate.php              the mutation-proven "only writes/only stamps under CCLI" guard
 * @link https://www.php.net/manual/en/mysqli-driver.report-mode.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licences.php';

/**
 * Memoised INFORMATION_SCHEMA existence probe for `tblSongUsageEvents`.
 *
 * ELI5: "does the usage-log table exist? remember the answer so we only
 * ask once." Mirrors `includes/read_rate_limit.php::_readRateLimitTableExists()`
 * — the table SHIPS in schema.sql (#1090 P5) but migrations are web-run,
 * never auto-applied (CLAUDE.md rule #19), so a request can still hit an
 * install where it hasn't been created yet.
 */
function _printUsageTableExists(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongUsageEvents' LIMIT 1"
        );
        $st->execute();
        $exists = $st->get_result()->fetch_row() !== null;
        $st->close();
    } catch (\Throwable $_e) {
        $exists = false; // any error -> treat as "absent" -> fail open (no-op, never a 500)
    }
    return $exists;
}

/**
 * Does this user hold a qualifying CCLI licence RIGHT NOW? The ONE
 * resolution point `printUsageContext()` (the GET probe) and
 * `printUsageLog()` (the write gate) both go through, so the two can never
 * disagree about what "licensed" means (rule #35) — `printUsageLog()`
 * re-calls this itself rather than trusting a caller's earlier answer.
 *
 * #1861 W1 — PREFERS an org-sourced licence over a user-sourced one.
 *
 * ELI5: if you hold BOTH a personal CCLI number AND your church's CCLI
 * licence, we report the print as your CHURCH's usage and stamp your
 * church's licence number on the footer — not your personal one — because
 * that is the licence a church's usage return actually needs to reflect.
 *
 * DETAIL: `getUserEffectiveLicences()` (`includes/licences.php`) appends
 * rows in a fixed order — (a) direct user-level `tblContentLicences` rows,
 * (b) the personal `tblUsers.CcliNumber`, THEN (c–f) every org-sourced row.
 * Before this fix, this function returned the FIRST 'ccli' row it saw, so a
 * user holding both a personal number and an org licence always resolved to
 * the PERSONAL one — `printUsageLog()` then logged `OrgId = NULL` for a
 * print that was genuinely the org's usage, and the compliance footer
 * stamped the user's personal CCL number instead of the org's. That is the
 * exact under-attribution `#1861`'s org-scoped report exists to surface (see
 * the system report's "Unattributed" filter, `manage/ccli-report.php`) — and
 * per this file's doc-block, under-counting is the direction that violates
 * the licence, never the safe default.
 *
 * A multi-org user who holds several org CCLI licences still resolves to
 * the FIRST org-sourced row `getUserEffectiveLicences()` returns — direct
 * memberships resolve before inherited-parent-org rows in that function,
 * which is the defensible order; picking a "best" org licence among several
 * is a real refinement but out of scope here (#1861 owner decision O4).
 *
 * Users whose ONLY CCLI licence is personal are unaffected and continue to
 * log `OrgId = NULL` — correctly, since there is no org to attribute to.
 *
 * @param  int|null $userId
 * @return array{type:string,key:string,source:string,source_id:?int}|null
 *         An org-sourced 'ccli' row when the user holds one, else the FIRST
 *         user-sourced 'ccli' row, else null when the user is anonymous or
 *         holds no qualifying licence at all.
 */
function printUsageResolveCcliLicence(?int $userId): ?array
{
    if ($userId === null || $userId <= 0) {
        return null;
    }
    $fallback = null;
    foreach (getUserEffectiveLicences($userId) as $l) {
        if (($l['type'] ?? '') !== 'ccli') {
            continue;
        }
        if ((string)($l['source'] ?? '') === 'org') {
            return $l; // org-held licence wins: org attribution + the org's CCL number on the footer (#1861 O4)
        }
        if ($fallback === null) {
            $fallback = $l; // first user-sourced row — used only when no org holds one
        }
    }
    return $fallback;
}

/**
 * §6.1 — context read: should the print/download flow prompt this user for
 * a copy count? True only when they hold a qualifying CCLI licence
 * (server-resolved — NEVER a client claim). The caller (api.php's
 * `print_usage_context` action) additionally folds in "does the SONG have a
 * CCLI number to report against" — a licence with nothing to attribute a
 * copy to never prompts — because that check needs a song row this
 * function deliberately doesn't take a dependency on (keeps this file DB-
 * schema-agnostic beyond the one table it owns).
 *
 * @return array{requiresCcli: bool, licenceKey: ?string}
 */
function printUsageContext(?int $userId): array
{
    $licence = printUsageResolveCcliLicence($userId);
    return [
        'requiresCcli' => $licence !== null,
        'licenceKey'   => $licence !== null ? (string)($licence['key'] ?? '') : null,
    ];
}

/**
 * Look up a song's raw CCLI number by SongId — a minimal, single-column
 * read (never the full `SongData::getSongById()` machinery) so the
 * PDF-endpoint's enforced-footer step and the API's context probe both stay
 * cheap and self-contained. Returns '' for a missing/soft-deleted song or
 * ANY DB error (fail-quiet — this is best-effort chrome, never a
 * request-blocking dependency).
 */
function printUsageSongCcliNumber(string $songId): string
{
    if ($songId === '') {
        return '';
    }
    try {
        $db = getDbMysqli();
        /* @deleted-visible: COMPLIANCE (mirrors manage/ccli-report.php's own
           reasoning, #1694) — the print this footer/copies-count is being
           stamped for has ALREADY happened by the time this runs; the song's
           real CCLI number is a fact about what was actually printed
           regardless of whether the song is later soft-deleted. Hiding it
           here would produce a compliance footer with a BLANK/wrong CCLI
           number for a print that genuinely occurred — under-reporting is
           the direction that violates the licence, never the safe default.
           @disabled-visible: same reasoning, one predicate over (#1765) — a
           disabled songbook doesn't retroactively un-print a copy either. */
        $stmt = $db->prepare('SELECT Ccli FROM tblSongs WHERE SongId = ? LIMIT 1');
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? trim((string)($row['Ccli'] ?? '')) : '';
    } catch (\Throwable $_e) {
        return '';
    }
}

/**
 * THE ONE INSERT. Private core both gates (`printUsageLog()` for prints,
 * `projectionUsageLog()` for Service-Mode projections, #1897 W2) delegate to
 * — the single `INSERT INTO tblSongUsageEvents` literal in the whole tree
 * (`tests/php/test-print-usage-ccli-gate.php` A2/A2b: exactly one literal,
 * only in this file, only here).
 *
 * ELI5: "actually write the one usage row." Whether it came from a print or a
 * projection, the write is identical — so it lives in ONE place, and the two
 * callers each do their own licence check BEFORE calling this. This function
 * does no gating of its own; a caller that skips its gate is the bug, not this.
 *
 * DETAIL: extracted from `printUsageLog()`'s inline INSERT (#1897 W2) so the
 * projected funnel can share it without a second `INSERT` literal (rule #22 —
 * one writer, never re-forked). The column list gained `LicenceId` at the same
 * time: `printUsageLog()` passes `null` (unchanged v1 behaviour — the
 * user-anchored resolver never surfaces `tblOrganisationLicences.Id`), and
 * `projectionUsageLog()` passes the org resolver's `licenceId`, making W2 the
 * first writer to populate the column the schema reserves for "the licence the
 * use is reported under".
 *
 * Never throws OUT of a bad bind on its own account beyond what mysqli STRICT
 * raises — every caller already wraps this in try/catch (its own never-throws
 * contract). Returns true once the row is written.
 *
 * @param \mysqli $db
 * @param array   $row Keys: SongId(string), OrgId(?int), UserId(?int),
 *                     SetlistId(?string), LicenceId(?int), UsageContext(string),
 *                     Quantity(int), Source(string), MetaJson(string).
 * @return bool
 * @link https://www.php.net/manual/en/mysqli-stmt.bind-param.php
 */
function _usageEventInsert(\mysqli $db, array $row): bool
{
    /* Locals: bind_param() takes its arguments BY REFERENCE, so every bound
       value must be a plain variable (never an array-index expression). */
    $songId       = (string)($row['SongId'] ?? '');
    $orgId        = isset($row['OrgId'])     && $row['OrgId'] !== null     ? (int)$row['OrgId']     : null;
    $userId       = isset($row['UserId'])    && $row['UserId'] !== null    ? (int)$row['UserId']    : null;
    $setlistId    = isset($row['SetlistId']) && $row['SetlistId'] !== null ? (string)$row['SetlistId'] : null;
    $licenceId    = isset($row['LicenceId']) && $row['LicenceId'] !== null ? (int)$row['LicenceId'] : null;
    $usageContext = (string)($row['UsageContext'] ?? 'printed');
    $quantity     = (int)($row['Quantity'] ?? 1);
    $source       = (string)($row['Source'] ?? 'app');
    $metaJson     = (string)($row['MetaJson'] ?? '');

    $stmt = $db->prepare(
        'INSERT INTO tblSongUsageEvents
            (SongId, OrgId, UserId, SetlistId, LicenceId, UsedAt, UsageContext, Quantity, Source, MetaJson)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)'
    );
    /* siisisiss — SongId(s) OrgId(i) UserId(i) SetlistId(s) LicenceId(i)
       UsageContext(s) Quantity(i) Source(s) MetaJson(s). A NULL bound under
       'i' is written as SQL NULL (all four nullable FK columns). */
    $stmt->bind_param('siisisiss', $songId, $orgId, $userId, $setlistId, $licenceId,
        $usageContext, $quantity, $source, $metaJson);
    $stmt->execute();
    $stmt->close();
    return true;
}

/**
 * §6.2 — THE ONE WRITER. Inserts one `tblSongUsageEvents` row
 * (`UsageContext = 'printed'`) ONLY when `$userId` holds a qualifying CCLI
 * licence, RE-RESOLVED here (never trusting a caller's earlier context
 * check — see the file doc-block). Every printed-copy log in the whole
 * tree funnels through this one function; never a second writer
 * (`tests/php/test-print-usage-ccli-gate.php` polices this with a
 * whole-tree scan).
 *
 * ELI5: "please remember that this signed-in, CCLI-licensed person just
 * printed N copies of this song" — and if EITHER of those two conditions
 * (signed in, CCLI-licensed) isn't true, this quietly does nothing at all.
 *
 * @param  int    $userId Authenticated user id (required — anonymous
 *                         printing can never be CCLI-attributed to anyone).
 * @param  string $songId tblSongs.SongId (the FK this table cascades on).
 * @param  int    $copies Clamped 1..10000 — the number the user was PROMPTED
 *                         for (owner decision #2 — never inferred).
 * @param  array  $meta   Optional: `surface` ('browser'|'pdf'), `templateId`
 *                         (int|null), `setlistId` (string|null).
 * @return bool true on a written row; false for ANY reason it wasn't
 *              written (no qualifying licence, un-migrated install, bad
 *              input, DB error) — this function NEVER throws out.
 */
function printUsageLog(int $userId, string $songId, int $copies, array $meta = []): bool
{
    if ($userId <= 0 || $songId === '') {
        return false;
    }

    /* THE GATE — re-resolved, never trusted from a caller. Nothing below
       this line can run for a user without a qualifying CCLI licence. */
    $licence = printUsageResolveCcliLicence($userId);
    if ($licence === null) {
        return false;
    }

    try {
        $db = getDbMysqli();
        if (!_printUsageTableExists($db)) {
            return false; // un-migrated install — silent no-op, never a STRICT-mode throw
        }

        $copies = max(1, min(10000, $copies));

        /* LicenceId stays NULL in v1 — getUserEffectiveLicences() doesn't
           surface tblOrganisationLicences.Id (a noted, trivially-changeable
           follow-up; see the plan §6.2). OrgId is populated only when the
           qualifying licence came from an ORGANISATION source. */
        $orgId = ((string)($licence['source'] ?? '') === 'org') ? (int)($licence['source_id'] ?? 0) : null;
        if ($orgId === 0) {
            $orgId = null;
        }

        $surface    = (isset($meta['surface']) && $meta['surface'] === 'pdf') ? 'pdf' : 'browser';
        $templateId = isset($meta['templateId']) && $meta['templateId'] !== null ? (int)$meta['templateId'] : null;
        $setlistId  = (isset($meta['setlistId']) && $meta['setlistId'] !== '')
            ? mb_substr((string)$meta['setlistId'], 0, 100) : null;

        $metaJson = json_encode([
            'templateId' => $templateId,
            'surface'    => $surface,
            'licenceKey' => (string)($licence['key'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        /* THE WRITE — via the shared insert core (#1897 W2 extracted it out
           of here so the projected funnel can reuse it, rule #22). The gate
           above (`$licence === null` → return) still precedes this call, so a
           write is unreachable without a qualifying licence. LicenceId stays
           NULL in the print path — `getUserEffectiveLicences()` doesn't surface
           `tblOrganisationLicences.Id` (a noted, trivially-changeable follow-up:
           the print path could adopt `printUsageResolveOrgCcliLicence()` when
           the qualifying licence is org-sourced). */
        return _usageEventInsert($db, [
            'SongId'       => $songId,
            'OrgId'        => $orgId,
            'UserId'       => $userId,
            'SetlistId'    => $setlistId,
            'LicenceId'    => null,
            'UsageContext' => 'printed',
            'Quantity'     => $copies,
            'Source'       => 'app',
            'MetaJson'     => $metaJson,
        ]);
    } catch (\Throwable $e) {
        error_log('[print_usage] log failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * §W2 (#1897) — does ORGANISATION $orgId hold a live, qualifying CCLI licence
 * RIGHT NOW? Org-anchored sibling of `printUsageResolveCcliLicence()`
 * (user-anchored) and `serviceMode_presenceCcliNumber()`
 * (presence-token-anchored). This one can surface
 * `tblOrganisationLicences.Id`, so `projectionUsageLog()` can finally populate
 * `tblSongUsageEvents.LicenceId` — the column the schema reserves for "the
 * licence the use is reported under".
 *
 * ELI5: "does this church have a valid CCLI licence on file today?" If yes,
 * hand back that licence's row id and its number so the projection can be
 * logged as the church's usage under the right licence.
 *
 * Same two-store shape as `getUserEffectiveLicences()`'s org branches (e)/(f):
 * the #640 multi-licence store (`tblOrganisationLicences`) is preferred, the
 * legacy `tblOrganisations` columns are the fallback; `o.IsActive = 1` on BOTH
 * arms (the owner's closed-org decision, 2026-07-30 — a closed org confers
 * nothing). Qualification is `licenceOrgRowQualifies('ccli', $number)` — NEVER
 * a local digits/length check (`tests/php/test-ccli-resolver.php` bans a second
 * copy; rule #35).
 *
 * The #640 arm is existence-gated by its own try/catch: on an un-migrated
 * install that table is absent and a read THROWS under mysqli STRICT, so we
 * fall through to the legacy columns rather than 500. The whole body is a
 * second try/catch → null (fail-quiet — a resolver failure means "no row
 * logged", never an exception on the broadcast path).
 *
 * @param  int $orgId
 * @return array{licenceId:?int, key:string}|null null = no qualifying licence.
 * @see includes/licences.php licenceOrgRowQualifies() — the ONE CCLI format gate
 * @link #1897
 */
function printUsageResolveOrgCcliLicence(int $orgId): ?array
{
    if ($orgId <= 0) {
        return null;
    }
    try {
        $db = getDbMysqli();

        /* Arm 1 — the #640 multi-licence store (preferred). Existence-gated:
           a missing table THROWS under STRICT → caught → fall through to arm 2. */
        try {
            $stmt = $db->prepare(
                "SELECT ol.Id, ol.LicenceNumber
                   FROM tblOrganisationLicences ol
                   JOIN tblOrganisations o ON o.Id = ol.OrganisationId
                  WHERE ol.OrganisationId = ?
                    AND o.IsActive  = 1
                    AND ol.IsActive = 1
                    AND ol.LicenceType = 'ccli'
                    AND (ol.ExpiresAt IS NULL OR ol.ExpiresAt > NOW())
                  LIMIT 1"
            );
            $stmt->bind_param('i', $orgId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row !== null) {
                $number = (string)($row['LicenceNumber'] ?? '');
                if (licenceOrgRowQualifies('ccli', $number)) {
                    return ['licenceId' => (int)$row['Id'], 'key' => $number];
                }
            }
        } catch (\Throwable $_e) {
            /* table absent (pre-migration) or transient read failure — arm 2 */
        }

        /* Arm 2 — legacy single-licence columns on tblOrganisations. No row id
           to surface here, so LicenceId is left NULL for a legacy-store match. */
        $stmt = $db->prepare(
            "SELECT LicenceNumber
               FROM tblOrganisations
              WHERE Id = ?
                AND IsActive = 1
                AND LicenceType = 'ccli'
                AND (LicenceExpiresAt IS NULL OR LicenceExpiresAt > NOW())
              LIMIT 1"
        );
        $stmt->bind_param('i', $orgId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            $number = (string)($row['LicenceNumber'] ?? '');
            if (licenceOrgRowQualifies('ccli', $number)) {
                return ['licenceId' => null, 'key' => $number];
            }
        }
        return null;
    } catch (\Throwable $_e) {
        return null;
    }
}

/**
 * §W2 (#1897) — THE projected-usage writer. One row per (service session,
 * song): "song X was projected in service session S under org O's CCL
 * licence". `Quantity` is ALWAYS 1 — a projection is one use, never a copies
 * count (the print path's prompted-copies rule does not apply to display).
 *
 * ELI5: when a church's Service-Mode operator switches the congregation's
 * screen to a new song, we quietly record one usage row for that song, under
 * the church's CCLI licence — but only once per song per service, and only if
 * the church actually holds a licence and the song has a CCLI number.
 *
 * THE GATE = the SESSION ORG's live CCLI licence
 * (`printUsageResolveOrgCcliLicence()`), re-resolved here — never the
 * operator's personal licence (the control-token / driver-key broadcasters
 * have no user at all) and never a client claim.
 *
 * DEDUP = once per (sessionId, songId) via `MetaJson.sessionId` + an
 * `idx_Song`-backed existence probe. Restarting a service mints a NEW session
 * row, so a reprise across a restart can double-count — the SAFE direction
 * (an over-count, never the under-count this file's own doc-block calls the
 * compliance-violating direction).
 *
 * GATING POSTURE: `content_gating_enabled` is deliberately NOT consulted (same
 * considered posture as `printUsageLog()`). Gating governs what CONGREGANTS may
 * READ; usage logging records what the ORG PROJECTED under its licence, which
 * is true and reportable whether or not gating enforcement is switched on.
 * When Service Mode is simply not in use there are no sessions and no
 * broadcasts, so this writer is unreachable — inert, not conditional.
 *
 * NEVER throws OUT; returns false for any reason a row wasn't written (and the
 * caller — `serviceMode_applyBroadcast()` — wraps the call again anyway, the
 * #1860 broadcast fail-safe: a logging failure is an `error_log` line, never a
 * broken broadcast).
 *
 * @param int    $orgId     The session's OrgId (service sessions always have one).
 * @param string $songId    tblSongs.SongId now becoming CurrentSongId.
 * @param int    $sessionId tblLiveFollowSessions.Id — the dedup key half.
 * @param array  $meta      Optional: userId(?int), via(string), channel(string),
 *                          setlistId(?string), venueId(?int), orgScheduleId(?int),
 *                          occurrenceDate(?string).
 * @return bool true when a row was written (or already existed for this
 *              session+song); false otherwise.
 * @see includes/service_mode.php serviceMode_applyBroadcast() — the ONE hook point
 * @link #1897
 */
function projectionUsageLog(int $orgId, string $songId, int $sessionId, array $meta = []): bool
{
    if ($orgId <= 0 || $songId === '' || $sessionId <= 0) {
        return false;
    }

    try {
        /* THE GATE — the SESSION ORG's live CCLI licence, re-resolved here.
           Textually before the write core, so a projection under an org with
           no qualifying licence can never reach _usageEventInsert(). */
        $licence = printUsageResolveOrgCcliLicence($orgId);
        if ($licence === null) {
            return false;
        }

        $db = getDbMysqli();
        if (!_printUsageTableExists($db)) {
            return false; // un-migrated install — silent no-op, never a STRICT throw
        }

        /* D2 default — only CCLI-numbered songs are reportable (mirrors the
           print path's effective behaviour). A public-domain hymn projected
           every week should not write a row per song per service; nothing on a
           CCLI return wants it. Flip by deleting this guard (see the plan D2). */
        if (printUsageSongCcliNumber($songId) === '') {
            return false;
        }

        /* DEDUP — once per (session, song). No UNIQUE key backs this (a UNIQUE
           over a JSON path needs a generated column = a migration, out of scope
           here), so a same-instant race could double-insert; accepted — a
           single-operator endpoint, and an over-count is the safe direction.
           `idx_Song (SongId)` narrows the scan to this one song's events. */
        $sessionIdStr = (string)$sessionId;
        $dup = $db->prepare(
            "SELECT 1 FROM tblSongUsageEvents
              WHERE SongId = ? AND OrgId = ? AND UsageContext = 'projected'
                AND JSON_UNQUOTE(JSON_EXTRACT(MetaJson, '$.sessionId')) = ?
              LIMIT 1"
        );
        $dup->bind_param('sis', $songId, $orgId, $sessionIdStr);
        $dup->execute();
        $already = $dup->get_result()->fetch_row() !== null;
        $dup->close();
        if ($already) {
            return true; // the use is already recorded for this session+song
        }

        $setlistId = (isset($meta['setlistId']) && $meta['setlistId'] !== null && (string)$meta['setlistId'] !== '')
            ? mb_substr((string)$meta['setlistId'], 0, 100) : null;

        /* orgScheduleId rides in MetaJson, NEVER the ScheduleId COLUMN: the
           column's FK is tblSetlistSchedule, but a service session's ScheduleId
           points at tblOrgServiceSchedules — a DIFFERENT table (rule: writing it
           into the column would violate fk_Usage_Schedule). */
        $metaJson = json_encode([
            'sessionId'      => $sessionId,
            'channel'        => (string)($meta['channel'] ?? ''),
            'via'            => (string)($meta['via'] ?? 'unknown'),
            'venueId'        => isset($meta['venueId']) ? (int)$meta['venueId'] : null,
            'orgScheduleId'  => isset($meta['orgScheduleId']) ? (int)$meta['orgScheduleId'] : null,
            'occurrenceDate' => $meta['occurrenceDate'] ?? null,
            'licenceKey'     => (string)($licence['key'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return _usageEventInsert($db, [
            'SongId'       => $songId,
            'OrgId'        => $orgId,
            'UserId'       => (isset($meta['userId']) && $meta['userId'] !== null) ? (int)$meta['userId'] : null,
            'SetlistId'    => $setlistId,
            'LicenceId'    => $licence['licenceId'],
            'UsageContext' => 'projected',
            'Quantity'     => 1,
            'Source'       => 'app',
            'MetaJson'     => $metaJson,
        ]);
    } catch (\Throwable $e) {
        error_log('[print_usage] projected log failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * §6.3 — the CCLI compliance notice text. ONE format-string source, shared
 * (in wording, not in code — PHP and JS can't literally share a function)
 * with `ccliNoticeText()` in `js/modules/print.js`; the two literals are
 * held in lockstep by `tests/php/test-print-block-registry.php`.
 *
 * Returns '' when the song carries no CCLI number — there is nothing to
 * report, so no footer line is stamped (the caller must not stamp a blank
 * notice).
 *
 * @param  string $ccliNumber The song's raw CCLI number (already resolved
 *                             server-side — e.g. via `printUsageSongCcliNumber()`).
 * @param  string $licenceKey The caller's qualifying CCLI licence key/number
 *                             (from `printUsageResolveCcliLicence()`'s `key`).
 * @return string Plain text (the CALLER decides whether/how to escape it
 *                 for its own output — an mPDF footer vs. an HTML `<div>`
 *                 escape differently).
 */
function printUsageCcliNoticeText(string $ccliNumber, string $licenceKey): string
{
    $ccli = trim($ccliNumber);
    if ($ccli === '') {
        return '';
    }
    return 'CCLI Song #' . $ccli . ' · Reproduced under CCL Licence #' . $licenceKey . '. Used by permission.';
}
