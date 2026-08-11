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
 * THE ONE WRITER: `printUsageLog()`. Two funnels reach it — the browser-
 * print path via `api.php?action=print_usage_log` (auth + the file's global
 * X-Requested-With POST gate, rule #29 posture) and the server-PDF path
 * DIRECTLY from `manage/print-pdf.php` (no HTTP round-trip needed — it is
 * already the authenticated request that will produce the file). Never a
 * third writer, never a raw `INSERT INTO tblSongUsageEvents` anywhere else
 * in the tree (`tests/php/test-print-usage-ccli-gate.php` polices this).
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
 * @param  int|null $userId
 * @return array{type:string,key:string,source:string,source_id:?int}|null
 *         The FIRST qualifying 'ccli' row from getUserEffectiveLicences(),
 *         or null when the user is anonymous or holds none.
 */
function printUsageResolveCcliLicence(?int $userId): ?array
{
    if ($userId === null || $userId <= 0) {
        return null;
    }
    foreach (getUserEffectiveLicences($userId) as $l) {
        if (($l['type'] ?? '') === 'ccli') {
            return $l;
        }
    }
    return null;
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

        $usageContext = 'printed';
        $source       = 'app';
        $stmt = $db->prepare(
            'INSERT INTO tblSongUsageEvents
                (SongId, OrgId, UserId, SetlistId, UsedAt, UsageContext, Quantity, Source, MetaJson)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)'
        );
        $stmt->bind_param('siississ', $songId, $orgId, $userId, $setlistId, $usageContext, $copies, $source, $metaJson);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $e) {
        error_log('[print_usage] log failed: ' . $e->getMessage());
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
