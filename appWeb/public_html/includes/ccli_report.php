<?php

declare(strict_types=1);

/**
 * iHymns — CCLI usage report core (#1861)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * This file answers one question two different ways: "what songs did we use,
 * and how many copies did we print?" The SYSTEM answer ("everyone, every
 * org") only a site admin ever sees. The ORG answer ("just MY church") is the
 * one an ordinary church admin is allowed to see — and this file is built so
 * that second answer can PHYSICALLY never turn into the first one by
 * accident. The function that returns an org's rows refuses to run at all if
 * it isn't given a real, server-checked list of orgs to look at.
 *
 * DETAILED
 * --------
 * One query core, two thin page consumers (rule #22 — the same shape as
 * `includes/publisher_admin.php` / `includes/tune_admin.php`):
 *   - `manage/ccli-report.php`    (system-wide, `view_ccli_report`, unchanged
 *                                   gate) — every org's usage, or narrowed to
 *                                   one org / the Unattributed slice via a
 *                                   selector.
 *   - `manage/my-ccli-report.php` (org-facing, `view_org_ccli_report` +
 *                                   `userIsOrgAdminOf()`) — exactly the
 *                                   caller's own org(s), never anyone else's.
 *
 * THE SECURITY INVARIANT (read this before touching this file)
 * ---------------------------------------------------------------------------
 * A `tblSongUsageEvents` row with `OrgId = X` is visible only to (a) a holder
 * of the SYSTEM `view_ccli_report` entitlement, or (b) a user with a
 * `tblOrganisationMembers` row `(UserId, X, Role IN ('admin','owner'))`.
 * That invariant is enforced structurally, not by convention:
 *
 *   1. `ccliReportOrgRows()` takes `array $orgIds` and REFUSES an empty
 *      array — it returns `[]` before touching the database. There is no
 *      flag, mode, or magic value that turns it into an all-orgs query.
 *      @see https://owasp.org/www-project-top-ten/ — A01:2021 Broken Access
 *           Control names exactly this shape ("insecure by default" is the
 *           fix, not "secure unless you forget a check").
 *   2. `manage/my-ccli-report.php` calls ONLY this function, and its
 *      `$orgIds` argument is derived SOLELY from `userIsOrgAdminOf($userId)`
 *      (`includes/entitlements.php`, the #707 membership-lookup convention —
 *      reused here, never reinvented per CLAUDE.md rule #22). A client-
 *      supplied `?org=` is validated by `ccliReportResolveOrgScope()` against
 *      that membership list and 403'd on any mismatch — never silently
 *      substituted or widened.
 *   3. `manage/ccli-report.php` (the system page) keeps its existing
 *      `view_ccli_report` entitlement wall; its org SELECTOR only narrows an
 *      already-all-orgs view, never grants anything new.
 *
 * `tests/php/test-ccli-report-org-scope.php` proves (1) and (2) both
 * textually (source scan) and behaviourally (a live-DB isolation test: org
 * A's admin can query exactly org A's printed copies, never org B's or the
 * unattributed ones) and is written to go RED under a set of named
 * mutations — see that file's doc-block.
 *
 * WHY THE ORG QUERY IS SHAPED DIFFERENTLY FROM THE SYSTEM QUERY
 * ---------------------------------------------------------------------------
 * The system report starts FROM `tblSongs` (LEFT JOIN usage) because a site
 * admin cares about the whole 14k-song catalogue, including songs nobody
 * used. An org's report starts FROM `tblSongUsageEvents` (INNER JOIN songs)
 * because a church only cares about what IT actually used — "all 14k songs
 * with zeros next to 13,997 of them" is not a usable report for a single
 * congregation.
 *
 * WHY THERE IS NO ORG "VIEWS" COLUMN (owner decision O3)
 * ---------------------------------------------------------------------------
 * `tblSongHistory` (the view-log table) has no `OrgId` column at all
 * (`appWeb/.sql/schema.sql` — see that table's own CREATE TABLE) — a page
 * view is never attributed to an organisation, only to a `UserId` (nullable,
 * for anonymous views). There is therefore no honest way to compute
 * "this org's views"; `ccliReportOrgRows()` always emits `view_count = 0`
 * and the org page hides the Views column + "Total views" card entirely
 * (`$showViewsColumn = false`) rather than show a mislabelled approximation.
 * The CSV keeps its 8-column CCLI-portal-compatible shape regardless (a
 * `Views` column of literal zeros, not an omitted column) — dropping a
 * column would break an existing upload template's positional mapping.
 *
 * @see appWeb/public_html/manage/ccli-report.php        system-wide report page
 * @see appWeb/public_html/manage/my-ccli-report.php      org-facing report page
 * @see appWeb/public_html/manage/includes/ccli-report-results.php  shared results partial
 * @see appWeb/public_html/includes/entitlements.php      userIsOrgAdminOf() — the membership lookup
 * @see appWeb/public_html/includes/print_usage.php       printUsageLog() — the ONE writer of tblSongUsageEvents
 * @see appWeb/public_html/includes/service_mode.php:1739  the `implode(',', array_fill(...))` + `str_repeat('i', ...)`
 *      placeholder-building shape this file follows for a variable-length org-id list (rule #5)
 * @see tests/php/test-ccli-report-org-scope.php           the mutation-proven isolation guard
 * @link https://www.php.net/manual/en/mysqli-stmt.bind-param.php
 * @link #1861
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* _printUsageTableExists() — the ONE existence-gate for tblSongUsageEvents
   (rule #22: reuse, don't re-fork the INFORMATION_SCHEMA probe that used to
   be duplicated inline in manage/ccli-report.php). Also pulls in
   getUserEffectiveLicences() transitively (includes/licences.php), which
   this file does not itself need. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'print_usage.php';
/* ihymns_fputcsv() — the CSV formula-injection neutraliser every admin CSV
   export in this codebase goes through. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'csv_safe.php';

/**
 * Parse + validate the report's date-range window from a `$_GET`-shaped
 * array. Moved verbatim (rule: one source, both pages read it, so the two
 * reports can never disagree on window semantics — e.g. one silently
 * accepting a malformed date the other rejects).
 *
 * ELI5: "what date range did the visitor ask for, and is it a real date?"
 * A malformed value falls back to the default rather than erroring — this
 * is a reporting page, not a search box, so we're strict about shape but
 * forgiving about mistakes.
 *
 * @param  array $get Typically `$_GET`.
 * @return array{from:string, to:string, showAll:bool}
 */
function ccliReportWindow(array $get): array
{
    $today       = new DateTimeImmutable('today');
    $defaultTo   = $today->format('Y-m-d');
    $defaultFrom = $today->modify('-30 days')->format('Y-m-d');

    $fromDate = (string)($get['from'] ?? $defaultFrom);
    $toDate   = (string)($get['to']   ?? $defaultTo);
    $showAll  = !empty($get['show_all']); /* include songs without CCLI */

    /* Validate & clamp — a malformed date falls back to the default. */
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $fromDate = $defaultFrom;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        $toDate = $defaultTo;
    }

    return ['from' => $fromDate, 'to' => $toDate, 'showAll' => $showAll];
}

/**
 * SYSTEM-WIDE report rows — every song (or every CCLI-numbered song, unless
 * `$showAll`), its view count, and its printed-copy count, optionally
 * narrowed to one org or to the "Unattributed" (`OrgId IS NULL`) slice.
 *
 * ELI5: "show me everything the whole app used" — or, if a filter is passed,
 * "…but only count copies printed by THIS org" / "…only copies nobody could
 * attribute to an org at all".
 *
 * SECURITY NOTE: this function is intentionally NOT org-scope-safe by
 * itself — `$orgFilter = null, $unattributed = false` (the default) means
 * "every org, unfiltered". That is correct and intended for the SYSTEM page,
 * which is already behind the `view_ccli_report` entitlement wall. The
 * org-facing page (`manage/my-ccli-report.php`) MUST NEVER call this
 * function — it calls `ccliReportOrgRows()` instead, which has the opposite
 * default (refuse unless told exactly which orgs). `test-ccli-report-org-scope.php`
 * (A1) asserts the org page's source contains no reference to this function
 * at all.
 *
 * @param  \mysqli    $db
 * @param  string     $from         Y-m-d, inclusive.
 * @param  string     $to           Y-m-d, inclusive (queries use `< DATE_ADD($to, INTERVAL 1 DAY)`).
 * @param  bool       $showAll      Include songs with no CCLI number.
 * @param  int|null   $orgFilter    Narrow printed copies to one org (validated positive int by the caller).
 * @param  bool       $unattributed Narrow printed copies to `OrgId IS NULL` rows.
 * @return array<int, array{song_id:string,title:string,songbook:string,number:?int,ccli:string,copyright:string,view_count:int,printed_copies:int,projected_uses:int}>
 * @throws \InvalidArgumentException if both $orgFilter and $unattributed are given — a programmer error, never user input (the caller parses `?org=` into exactly one of the two, or neither).
 */
function ccliReportSystemRows(
    \mysqli $db,
    string $from,
    string $to,
    bool $showAll,
    ?int $orgFilter = null,
    bool $unattributed = false
): array {
    if ($orgFilter !== null && $unattributed) {
        throw new \InvalidArgumentException(
            'ccliReportSystemRows(): $orgFilter and $unattributed are mutually exclusive.'
        );
    }

    /* $ccliFilter is built from a server-side bool ($showAll) and only ever
       takes one of two hardcoded literal values — never user input directly
       interpolated (rule #5's allow-listed-literal carve-out). */
    $ccliFilter = $showAll ? '' : "AND s.Ccli <> ''";

    $hasUsageEvents = _printUsageTableExists($db);

    $printedJoin   = '';
    $printedSelect = '0 AS printed_copies, 0 AS projected_uses';
    $orgClause     = ''; /* also a hardcoded literal, never user input (see above) */
    if ($hasUsageEvents) {
        if ($orgFilter !== null) {
            $orgClause = 'AND OrgId = ?';
        } elseif ($unattributed) {
            $orgClause = 'AND OrgId IS NULL';
        }
        /* #1897 — widened from printed-only to ALSO carry PROJECTED
           (Service-Mode) uses, in ONE scan of the same window via conditional
           SUMs. printed_copies keeps byte-identical semantics (the CASE folds
           to the old SUM(Quantity) for the 'printed' rows, since the WHERE
           already restricted to 'printed'); projected_uses is the new column
           projectionUsageLog() populates. */
        $printedJoin = "LEFT JOIN (
               SELECT SongId,
                      SUM(CASE WHEN UsageContext = 'printed'   THEN Quantity ELSE 0 END) AS printed_copies,
                      SUM(CASE WHEN UsageContext = 'projected' THEN Quantity ELSE 0 END) AS projected_uses
                 FROM tblSongUsageEvents
                WHERE UsageContext IN ('printed','projected')
                  AND UsedAt >= ?
                  AND UsedAt <  DATE_ADD(?, INTERVAL 1 DAY)
                  $orgClause
                GROUP BY SongId
           ) p ON p.SongId = s.SongId";
        $printedSelect = 'COALESCE(p.printed_copies, 0) AS printed_copies, COALESCE(p.projected_uses, 0) AS projected_uses';
    }

    /* @deleted-visible: COMPLIANCE reporting (#1694) — a song's past usage was
       real usage under the licence whether or not the song was later
       soft-deleted; hiding it here would UNDER-report, which is the direction
       that violates the licence (print_usage.php's own doc-block states the
       same rule).
       @disabled-visible: same reasoning, one predicate over (#1765) — a song's
       past usage was real CCLI usage whether or not its songbook is CURRENTLY
       disabled; admin/compliance reporting must not under-report. Verbatim
       carry-over from the pre-#1861 manage/ccli-report.php (both markers kept
       separate so BOTH visibility guards — #1694 song, #1765 songbook — see
       their own token; a single combined `deleted / disabled` phrase satisfied
       only the songbook guard). */
    $stmt = $db->prepare(
        "SELECT s.SongId        AS song_id,
                s.Title          AS title,
                s.SongbookAbbr   AS songbook,
                s.Number         AS number,
                s.Ccli           AS ccli,
                s.Copyright      AS copyright,
                COALESCE(h.view_count, 0) AS view_count,
                $printedSelect
           FROM tblSongs s
           LEFT JOIN (
               SELECT SongId, COUNT(*) AS view_count
                 FROM tblSongHistory
                WHERE ViewedAt >= ?
                  AND ViewedAt <  DATE_ADD(?, INTERVAL 1 DAY)
                GROUP BY SongId
           ) h ON h.SongId = s.SongId
          $printedJoin
          WHERE 1 = 1
          $ccliFilter
          ORDER BY view_count DESC, s.Title ASC
          LIMIT 5000"
    );

    /* Placeholder count/order follows the query text top-to-bottom (rule #5's
       "?,?,?" discipline applied to a variable placeholder COUNT): the Views
       subquery's (from,to) pair first, then — only when the printed join is
       present — its own (from,to) pair, then the optional single OrgId. */
    if ($hasUsageEvents) {
        if ($orgFilter !== null) {
            $stmt->bind_param('ssssi', $from, $to, $from, $to, $orgFilter);
        } else {
            $stmt->bind_param('ssss', $from, $to, $from, $to);
        }
    } else {
        $stmt->bind_param('ss', $from, $to);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * ORG-SCOPED report rows — driven FROM the usage events, not from the whole
 * song catalogue, because an org's report is "what WE used", never "all 14k
 * songs with zeros next to the ones we didn't".
 *
 * THE STRUCTURAL REFUSAL (read before editing this function)
 * ---------------------------------------------------------------------------
 * `$orgIds === []` returns `[]` immediately, before any `prepare()` call.
 * This is not an optimisation — it is THE isolation guarantee. There is no
 * other code path in this function that can reach the database without a
 * non-empty, caller-supplied org-id list. Do not "fix" this into a
 * fallthrough that queries unfiltered on an empty list; that would turn a
 * caller's bug (forgetting to resolve scope) into a cross-org data leak.
 * `test-ccli-report-org-scope.php` B2 asserts this, and its mutation-proof
 * log records exactly this change going red.
 *
 * @param  \mysqli $db
 * @param  int[]   $orgIds  MUST be membership-derived (see this file's
 *                          doc-block invariant) — never accept this
 *                          parameter from raw request input.
 * @param  string  $from    Y-m-d, inclusive.
 * @param  string  $to      Y-m-d, inclusive.
 * @param  bool    $showAll Include songs with no CCLI number.
 * @return array<int, array{song_id:string,title:string,songbook:string,number:?int,ccli:string,copyright:string,view_count:int,printed_copies:int,projected_uses:int}>
 *         Same row shape as ccliReportSystemRows() so rendering + CSV are
 *         shared; `view_count` is always 0 (see this file's doc-block, O3).
 */
function ccliReportOrgRows(\mysqli $db, array $orgIds, string $from, string $to, bool $showAll): array
{
    if ($orgIds === []) {
        return []; // STRUCTURAL refusal — never an unscoped query. Do not "fix" this into a fallthrough.
    }
    $orgIds = array_values(array_map('intval', $orgIds));

    if (!_printUsageTableExists($db)) {
        return []; // un-migrated install — same dormant posture as the system page's printed column
    }

    /* Placeholders built from a COUNT, never from request data (rule #5). */
    $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
    $ccliFilter   = $showAll ? '' : "AND s.Ccli <> ''"; // hardcoded literal, not user input

    /* @deleted-visible: COMPLIANCE reporting (#1694) — an org's past
       printed-copy usage was real usage under its CCLI licence whether or not
       the song was later soft-deleted; hiding it here would UNDER-report the
       return, which is the direction that violates the licence
       (print_usage.php's own doc-block states the same rule).
       @disabled-visible: same reasoning, one predicate over (#1765) — a
       disabled songbook doesn't retroactively un-print a copy. Same deliberate
       decision as the system query (ccliReportSystemRows above) — this org
       query is the same CCLI-compliance substrate, just membership-scoped, so
       it must NOT filter on songServableSql()/songbookVisibleSql(). Both
       markers kept separate so BOTH visibility guards see their own token. */
    /* #1897 — widened from printed-only to ALSO carry PROJECTED (Service-Mode)
       uses via conditional SUMs, in the SAME membership-scoped scan. Two
       consequences, both intended: printed_copies keeps its exact old value
       (the CASE folds to SUM(u.Quantity) over the 'printed' rows), and a song
       an org ONLY projected (never printed) now appears with printed_copies=0
       and projected_uses>0 — which is precisely the usage the org report exists
       to surface. ORDER stays printed_copies DESC (cosmetic; a purely-projected
       song sorts to the tail — acceptable). */
    $stmt = $db->prepare(
        "SELECT s.SongId AS song_id, s.Title AS title, s.SongbookAbbr AS songbook,
                s.Number AS number, s.Ccli AS ccli, s.Copyright AS copyright,
                0 AS view_count,
                COALESCE(SUM(CASE WHEN u.UsageContext = 'printed'   THEN u.Quantity ELSE 0 END), 0) AS printed_copies,
                COALESCE(SUM(CASE WHEN u.UsageContext = 'projected' THEN u.Quantity ELSE 0 END), 0) AS projected_uses
           FROM tblSongUsageEvents u
           JOIN tblSongs s ON s.SongId = u.SongId
          WHERE u.UsageContext IN ('printed','projected')
            AND u.OrgId IN ($placeholders)
            AND u.UsedAt >= ? AND u.UsedAt < DATE_ADD(?, INTERVAL 1 DAY)
            $ccliFilter
          GROUP BY s.SongId, s.Title, s.SongbookAbbr, s.Number, s.Ccli, s.Copyright
          ORDER BY printed_copies DESC, s.Title ASC
          LIMIT 5000"
    );
    $types  = str_repeat('i', count($orgIds)) . 'ss';
    $params = array_merge($orgIds, [$from, $to]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * THE security decision, as a pure, unit-testable primitive — the same
 * "testable primitive behind a thin case" shape as
 * `serviceMode_liveFollowExtendAuthorize()` (`includes/service_mode.php`).
 * `manage/my-ccli-report.php` calls this to turn "what did the URL ask for"
 * plus "what is this user actually allowed to see" into exactly one safe
 * org-id list, or a denial.
 *
 * ELI5: "which one organisation's report should we show?" If nobody asked
 * for a specific one, show their first. If they asked for one they don't
 * administer — even if it's a real organisation — say no. Never guess, never
 * fall back to "well here's SOME org you do administer instead" (that would
 * silently substitute the wrong report for a users who mistyped or forged
 * the URL).
 *
 * @param  string|null $requestedOrg  Raw `$_GET['org']`, or null if absent.
 * @param  int[]       $allowedOrgIds The caller's OWN membership-derived org
 *                                     list (`userIsOrgAdminOf($userId)`) —
 *                                     never anything else.
 * @return array{orgIds: int[], denied: bool} `orgIds` has exactly one
 *         element when `denied` is false; empty when `denied` is true.
 */
function ccliReportResolveOrgScope(?string $requestedOrg, array $allowedOrgIds): array
{
    if ($allowedOrgIds === []) {
        return ['orgIds' => [], 'denied' => true];
    }
    if ($requestedOrg === null || $requestedOrg === '') {
        /* Default = the caller's first org. A per-licence report is always
           ONE org at a time — there is no "combine my two churches' usage"
           use case this feature is built for. */
        return ['orgIds' => [(int)$allowedOrgIds[0]], 'denied' => false];
    }
    $requested = (int)$requestedOrg;
    if (!in_array($requested, $allowedOrgIds, true)) {
        /* Forged / mistyped ?org= that isn't one of THIS user's orgs — 403,
           never a silent fallback to an org they DO administer. Mirrors
           manage/my-organisations.php's row-level $canActOnOrg posture. */
        return ['orgIds' => [], 'denied' => true];
    }
    return ['orgIds' => [$requested], 'denied' => false];
}

/**
 * Stream `$rows` as a CCLI-portal-compatible CSV and set the download
 * headers. Moved verbatim from the pre-#1861 manage/ccli-report.php — the
 * column order is load-bearing (an existing upload template maps the
 * first 7 columns positionally; Printed copies was appended last in #1767
 * remainder P5). The org report keeps the SAME columns (Views = literal
 * 0) rather than drop a column — see this file's doc-block (O3) for why.
 * #1897 appends a 9th column (Projected uses) at the END, so the first 8
 * positions stay byte-stable for anything mapping them positionally.
 *
 * Caller is responsible for `exit`ing immediately afterwards — this
 * function only emits, it never terminates the request itself (keeps it a
 * plain, testable function rather than a hidden control-flow surprise).
 *
 * @param  array<int, array{song_id:string,title:string,songbook:string,number:?int,ccli:string,copyright:string,view_count:int,printed_copies:int,projected_uses:int}> $rows
 * @param  string $filename Suggested download filename (caller builds it —
 *                           system vs. org reports use different patterns).
 */
function ccliReportEmitCsv(array $rows, string $filename): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'wb');
    ihymns_fputcsv($out, ['SongId', 'Title', 'Songbook', 'Number', 'CCLI', 'Copyright', 'Views', 'Printed copies', 'Projected uses']);
    foreach ($rows as $r) {
        ihymns_fputcsv($out, [
            $r['song_id'],
            $r['title'],
            $r['songbook'],
            $r['number'],
            $r['ccli'],
            $r['copyright'],
            $r['view_count'],
            $r['printed_copies'],
            $r['projected_uses'] ?? 0,
        ]);
    }
    fclose($out);
}
