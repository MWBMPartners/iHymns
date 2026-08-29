<?php

declare(strict_types=1);

/**
 * analytics_ingest.php — validation for the first-party, anonymous
 * analytics event-ingestion endpoint (#1448)
 * ============================================================================
 *
 * WHY:
 *   The Apple app's Phase-1 analytics work (#189) recorded a small, CLOSED
 *   set of first-party, anonymous, consent-gated events client-side
 *   (`IHAnalyticsEvent`'s static factories: screen_view / song_opened /
 *   search_performed / offline_save) but had nowhere to SEND them — the
 *   default `IHAnalyticsSink` was a genuine no-op. This file is the
 *   validation core `?action=analytics_ingest` (api.php) uses to accept a
 *   batch POST of those events and turn it into safe, capped rows for
 *   `tblAppAnalyticsEvents` (migrate-add-analytics-events.php, #1448).
 *
 * PII / RETENTION STANCE (load-bearing — read before touching this file):
 *   - `tblAppAnalyticsEvents` carries NO user id, NO device/install id, and
 *     NO IP address column. The whole point of "first-party anonymous
 *     analytics" is that a row can never be traced back to a person or
 *     device — see that table's own COMMENT in schema.sql.
 *   - The caller's IP address IS read here, but ONLY to key the per-IP
 *     write-rate-limit counter (`checkRateLimit()` / `tblLoginAttempts`,
 *     CLAUDE.md's existing generic write-limiter) — it is never written to
 *     the analytics table itself, and `tblLoginAttempts` already has its
 *     own separate, pre-existing retention/purge posture shared by every
 *     other caller of that limiter (login attempts, service_join, …).
 *   - No bearer/session association is attempted even when a token is
 *     present on the request — a deliberate, conservative v1 scope
 *     decision (see #1448's "MAY associate an anonymous per-install id"
 *     option, which this endpoint does NOT implement) so "anonymous" stays
 *     simple and auditable in ONE file rather than a promise every future
 *     change has to keep.
 *
 * VALIDATION / CAPS (all load-bearing):
 *   - `EventName` is checked against a CLOSED allow-list mirroring the
 *     client's closed factory set (`IHAnalyticsEvent.swift`) — an unknown
 *     event name is REJECTED, not stored-as-is. Rule #20 (CLAUDE.md) still
 *     applies: the column itself is VARCHAR, not ENUM, so growing the
 *     allow-list to add a 5th event later is a one-line PHP change, never a
 *     migration.
 *   - Every event is validated INDEPENDENTLY — one malformed event in a
 *     batch does not fail the whole batch; it is silently dropped and
 *     counted in the `rejected` tally the caller gets back (a best-effort,
 *     analytics-grade contract, not a transactional one).
 *   - `parameters` MUST be a flat `string => string` map (matching
 *     `IHAnalyticsEvent.parameters`'s own Swift shape exactly) — nested
 *     objects/arrays/non-string values are rejected outright, not coerced,
 *     so a client bug can't smuggle an unexpectedly large/nested payload
 *     into storage.
 *   - `clientTimestamp` is clamped to a plausible window (1 day in the
 *     future .. 30 days in the past) rather than trusted outright — a
 *     wildly wrong device clock degrades to "timestamp omitted", never a
 *     poisoned analytics timeline.
 *
 * @see appWeb/.sql/migrate-add-analytics-events.php
 * @see appWeb/public_html/includes/rate_limit.php :: checkRateLimit()
 * @see https://www.php.net/manual/en/book.mysqli.php
 * @see https://www.w3.org/TR/NOTE-datetime (ISO 8601, `clientTimestamp`'s wire shape)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/** Max events accepted in a single POST body — a client-side batching cap,
 *  not a per-user cap; a legitimate client batches locally and flushes
 *  every so often, so a single huge batch is itself a signal of misuse. */
const ANALYTICS_INGEST_MAX_BATCH = 50;

/** Max `EventName` length — defensive only; the allow-list check below is
 *  the REAL gate, this just avoids doing a strlen-heavy compare against an
 *  absurdly long string first. */
const ANALYTICS_INGEST_MAX_NAME_LENGTH = 64;

/** Max number of keys in one event's `parameters` map. */
const ANALYTICS_INGEST_MAX_PARAM_KEYS = 10;

/** Max length of one `parameters` key. */
const ANALYTICS_INGEST_MAX_PARAM_KEY_LENGTH = 40;

/** Max length of one `parameters` value. */
const ANALYTICS_INGEST_MAX_PARAM_VALUE_LENGTH = 200;

/**
 * The CLOSED set of event names this endpoint accepts — kept in exact sync
 * with `IHAnalyticsEvent.swift`'s static factories (`screenView` /
 * `songOpened` / `searchPerformed` / `offlineSave`). Adding a new client
 * event REQUIRES adding its name here too (one line, no migration — rule
 * #20); an event name reaching this endpoint that ISN'T in this list is
 * rejected, never stored under an ad-hoc name.
 */
const ANALYTICS_INGEST_ALLOWED_EVENT_NAMES = [
    'screen_view',
    'song_opened',
    'search_performed',
    'offline_save',
];

/**
 * The reporting-client platforms this endpoint recognises. An unrecognised
 * or absent value is stored as 'unknown' rather than rejecting the whole
 * batch — the platform label is informational (for the future
 * manage/analytics.php panel, #1448), not a security boundary.
 */
const ANALYTICS_INGEST_ALLOWED_PLATFORMS = ['apple', 'android', 'web'];

/**
 * Validate the batch-level `platform` field.
 *
 * ELI5: "which app sent this?" — if it's not one we recognise, call it
 * 'unknown' rather than rejecting the whole request over a label.
 */
function analyticsIngestValidatePlatform(mixed $raw): string
{
    $platform = is_string($raw) ? strtolower(trim($raw)) : '';
    return in_array($platform, ANALYTICS_INGEST_ALLOWED_PLATFORMS, true) ? $platform : 'unknown';
}

/**
 * Validate + normalise a single raw event from the POSTed batch.
 *
 * ELI5: "is this one event shaped correctly, from our known list of event
 * names, and small enough to be safe to store?" Returns `null` (never
 * throws) for anything that fails any check — the caller drops it and
 * counts it as rejected, per this file's "independent validation" rule.
 *
 * @return array{name:string,paramsJson:?string,clientTimestamp:?string}|null
 */
function analyticsIngestValidateEvent(mixed $raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }

    $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
    if ($name === '' || mb_strlen($name) > ANALYTICS_INGEST_MAX_NAME_LENGTH) {
        return null;
    }
    if (!in_array($name, ANALYTICS_INGEST_ALLOWED_EVENT_NAMES, true)) {
        return null;   // closed allow-list — see this file's header
    }

    $paramsRaw = $raw['parameters'] ?? [];
    if (!is_array($paramsRaw) || count($paramsRaw) > ANALYTICS_INGEST_MAX_PARAM_KEYS) {
        return null;
    }

    $params = [];
    foreach ($paramsRaw as $key => $value) {
        if (!is_string($key) || $key === '' || mb_strlen($key) > ANALYTICS_INGEST_MAX_PARAM_KEY_LENGTH) {
            return null;
        }
        /* Flat string map ONLY — matches IHAnalyticsEvent.parameters's own
           Swift shape ([String: String]) exactly; a nested array/object or
           any non-string scalar rejects the whole event rather than being
           silently coerced/flattened. */
        if (!is_string($value) || mb_strlen($value) > ANALYTICS_INGEST_MAX_PARAM_VALUE_LENGTH) {
            return null;
        }
        $params[$key] = $value;
    }

    return [
        'name' => $name,
        'paramsJson' => $params === [] ? null : json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'clientTimestamp' => analyticsIngestValidateTimestamp($raw['clientTimestamp'] ?? null),
    ];
}

/**
 * Validate + normalise the optional `clientTimestamp` field into a UTC
 * `Y-m-d H:i:s` string, or `null` if absent/malformed/implausible.
 *
 * ELI5: "does this look like a real, roughly-current point in time?" A
 * device clock that's wildly wrong (or a client that sends garbage) just
 * loses its timestamp rather than poisoning the stored data — the row is
 * still kept (ordered by the trustworthy server-side `ReceivedAt` instead).
 *
 * @return string|null UTC `Y-m-d H:i:s`, or null.
 */
function analyticsIngestValidateTimestamp(mixed $raw): ?string
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    try {
        $dt = new \DateTimeImmutable($raw);
    } catch (\Throwable $_) {
        return null;   // not a parseable date string at all
    }

    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $diffSeconds = $dt->getTimestamp() - $now->getTimestamp();
    /* Clamp to a plausible window: up to 1 day ahead (clock skew) .. 30 days
       behind (a device that was offline for a while before flushing its
       queued batch). Anything outside that is treated as "no usable
       timestamp" rather than trusted verbatim. */
    if ($diffSeconds > 86400 || $diffSeconds < -30 * 86400) {
        return null;
    }

    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/* =============================================================================
 * ADMIN DASHBOARD READS — /manage/analytics "Top songs" / "Top books" panels
 * (API-coverage Batch 5, A14, `.claude/api-coverage-2026-08-28.md` §4.3/§9)
 * =============================================================================
 *
 * A SEPARATE concern from the anonymous event-ingestion validation above —
 * these two functions are the ADMIN-ONLY historical view-count reads
 * `manage/analytics.php`'s CSV export always ran, extracted here (rule #22)
 * so `api.php`'s new `admin_analytics_top` action can offer a native admin
 * client the SAME unfiltered, all-time-eligible read instead of a second
 * copy of the SQL. Grouped into this file per the API-coverage plan's own
 * "Core: includes/analytics_ingest.php read queries" placement — both
 * halves of this file are about `tblSongHistory`-adjacent analytics, just
 * on opposite ends: write-path validation above, admin read-path below.
 *
 * DELIBERATELY NOT the same data as the public `popular_songs` action
 * (api.php): `popular_songs` filters through `songVisibleSql()` +
 * `songServableSql()` (a hidden/soft-deleted song or a disabled songbook's
 * song must never appear in a public recommendation) and applies the
 * caller's language filter — an admin historical report must NOT apply
 * either filter, because a book being disabled or a song being soft-deleted
 * TODAY must not erase what genuinely happened in the view history
 * (`@deleted-visible`/`@disabled-visible`, mirrored from the page's own
 * doc-block). `analyticsTopBooks()` also has no `popular_songs` counterpart
 * at all — that action returns per-SONG rows, never a songbook-level
 * rollup. So this is NOT the "fully redundant, skip it" case the plan
 * flagged as a possibility.
 */

/**
 * iHymns — the N most-viewed songs since `$since`, UNFILTERED (admin
 * historical report — see the section doc-block above for why this must
 * NOT apply the public visibility/servability filters `popular_songs`
 * does). Byte-identical extraction of `manage/analytics.php`'s former
 * `case 'top_songs'` CSV-export query.
 *
 * @param string $since `Y-m-d H:i:s` — rows with `ViewedAt >= $since`.
 * @return list<array{0:string,1:?string,2:?string,3:?int,4:int}> numeric
 *         rows in column order [SongId, Title, SongbookAbbr, Number, Views]
 *         — the SAME shape `fetch_row()` produced at the original call
 *         site, so a caller can `ihymns_fputcsv($fp, $row)` each one
 *         unchanged.
 */
function analyticsTopSongs(\mysqli $db, string $since): array
{
    /* @deleted-visible: historical analytics (#1694) — a view that
       happened, happened; the tblSongs join only labels/groups it.
       Hiding a soft-deleted song here would misstate real usage.
       @disabled-visible: admin reporting (#1765) — counts/stats over all
       books; a book being disabled today must not erase its past view
       history from this admin-only report. */
    $stmt = $db->prepare(
        'SELECT h.SongId, s.Title, s.SongbookAbbr, s.Number, COUNT(*) AS views
           FROM tblSongHistory h
           LEFT JOIN tblSongs s ON s.SongId = h.SongId
          WHERE h.ViewedAt >= ?
          GROUP BY h.SongId
          ORDER BY views DESC'
    );
    $stmt->bind_param('s', $since);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_NUM);
    $stmt->close();
    return $rows;
}

/**
 * iHymns — total views per songbook since `$since`, UNFILTERED (see the
 * section doc-block above). Byte-identical extraction of
 * `manage/analytics.php`'s former `case 'top_books'` CSV-export query. Has
 * no equivalent anywhere else in the API surface — `popular_songs` returns
 * per-song rows only, never a book-level rollup.
 *
 * @return list<array{0:string,1:int}> numeric rows [SongbookAbbr, Views].
 */
function analyticsTopBooks(\mysqli $db, string $since): array
{
    /* @deleted-visible: historical analytics (#1694) — same reasoning as
       analyticsTopSongs() above: a view that happened, happened.
       @disabled-visible: admin reporting (#1765) — same reasoning as
       analyticsTopSongs() above: a book being disabled today must not
       erase its past view history from this admin-only report. */
    $stmt = $db->prepare(
        'SELECT s.SongbookAbbr, COUNT(*) AS views
           FROM tblSongHistory h
           JOIN tblSongs s ON s.SongId = h.SongId
          WHERE h.ViewedAt >= ?
          GROUP BY s.SongbookAbbr
          ORDER BY views DESC'
    );
    $stmt->bind_param('s', $since);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_NUM);
    $stmt->close();
    return $rows;
}
