<?php

declare(strict_types=1);

/**
 * iHymns — Analytics ingestion validation test (#1448)
 *
 * Pure-function coverage (no DB): exercises
 * analyticsIngestValidateEvent()/analyticsIngestValidatePlatform()/
 * analyticsIngestValidateTimestamp() directly against the closed
 * event-name allow-list, the batch/name/param caps, and the timestamp
 * clamp — the same validation `?action=analytics_ingest` (api.php) runs
 * on every event in a POSTed batch.
 *
 *   php tests/php/test-analytics-ingest.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/analytics_ingest.php';

$fail = 0;
function ok(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $fail++; }
}

/* --- analyticsIngestValidateEvent(): the happy path ------------------- */

$valid = analyticsIngestValidateEvent([
    'name' => 'song_opened',
    'parameters' => ['song_id' => 'MP-1008'],
    'clientTimestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
]);
ok('a well-formed, allow-listed event validates', $valid !== null);
ok('validated event keeps the exact name', ($valid['name'] ?? null) === 'song_opened');
ok('validated event JSON-encodes its parameters', ($valid['paramsJson'] ?? null) === '{"song_id":"MP-1008"}');
ok('validated event keeps a plausible client timestamp', ($valid['clientTimestamp'] ?? null) !== null);

$noParams = analyticsIngestValidateEvent(['name' => 'screen_view']);
ok('an event with no parameters key validates (defaults to empty map)', $noParams !== null);
/* Direct key access (not `??`) — the key legitimately holds a NULL value,
   and `??` treats an existing-but-null key the same as a missing one, which
   would mask a real regression here. */
ok('an event with no parameters stores a NULL ParametersJson (not "{}"), keeping rows lean', array_key_exists('paramsJson', $noParams) && $noParams['paramsJson'] === null);

/* --- closed event-name allow-list -------------------------------------- */

ok('every client-known event name is allow-listed', analyticsIngestValidateEvent(['name' => 'search_performed']) !== null);
ok('every client-known event name is allow-listed (offline_save)', analyticsIngestValidateEvent(['name' => 'offline_save']) !== null);
ok('an unknown event name is rejected outright (not stored under an ad-hoc name)', analyticsIngestValidateEvent(['name' => 'totally_made_up_event']) === null);
ok('an empty event name is rejected', analyticsIngestValidateEvent(['name' => '']) === null);
ok('a missing name key is rejected', analyticsIngestValidateEvent(['parameters' => []]) === null);
ok('a non-array raw event is rejected', analyticsIngestValidateEvent('not-an-array') === null);
ok('an oversized name is rejected even before the allow-list check', analyticsIngestValidateEvent(['name' => str_repeat('a', ANALYTICS_INGEST_MAX_NAME_LENGTH + 1)]) === null);

/* --- parameters: flat string map only, capped -------------------------- */

ok('a nested array value in parameters is rejected (flat string map only)', analyticsIngestValidateEvent([
    'name' => 'song_opened',
    'parameters' => ['song_id' => ['nested' => 'value']],
]) === null);
ok('a non-string (int) parameter value is rejected', analyticsIngestValidateEvent([
    'name' => 'search_performed',
    'parameters' => ['result_count' => 3],
]) === null);
ok('an over-length parameter value is rejected', analyticsIngestValidateEvent([
    'name' => 'song_opened',
    'parameters' => ['song_id' => str_repeat('x', ANALYTICS_INGEST_MAX_PARAM_VALUE_LENGTH + 1)],
]) === null);
ok('an over-length parameter key is rejected', analyticsIngestValidateEvent([
    'name' => 'song_opened',
    'parameters' => [str_repeat('k', ANALYTICS_INGEST_MAX_PARAM_KEY_LENGTH + 1) => 'v'],
]) === null);
$tooManyParams = [];
for ($i = 0; $i <= ANALYTICS_INGEST_MAX_PARAM_KEYS; $i++) { $tooManyParams['k' . $i] = 'v'; }
ok('too many parameter keys is rejected', analyticsIngestValidateEvent([
    'name' => 'song_opened',
    'parameters' => $tooManyParams,
]) === null);
ok('parameters must itself be an array, not a scalar', analyticsIngestValidateEvent([
    'name' => 'song_opened',
    'parameters' => 'not-an-array',
]) === null);

/* --- analyticsIngestValidateTimestamp(): clamp implausible clocks ------ */

ok('a missing timestamp is fine (returns null, not rejected)', analyticsIngestValidateTimestamp(null) === null);
ok('a garbage timestamp string is dropped to null', analyticsIngestValidateTimestamp('not-a-date') === null);
ok('a plausible recent timestamp round-trips', analyticsIngestValidateTimestamp(
    (new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM)
) !== null);
ok('a timestamp 2 days in the future is clamped to null', analyticsIngestValidateTimestamp(
    (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM)
) === null);
ok('a timestamp 60 days in the past is clamped to null', analyticsIngestValidateTimestamp(
    (new DateTimeImmutable('-60 days', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM)
) === null);
ok('a timestamp 10 minutes in the future (clock skew) is still accepted', analyticsIngestValidateTimestamp(
    (new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM)
) !== null);

/* --- analyticsIngestValidatePlatform() ---------------------------------- */

ok('a recognised platform is kept', analyticsIngestValidatePlatform('apple') === 'apple');
ok('platform matching is case-insensitive', analyticsIngestValidatePlatform('Apple') === 'apple');
ok('an unrecognised platform falls back to "unknown", not rejected', analyticsIngestValidatePlatform('symbian') === 'unknown');
ok('a missing platform falls back to "unknown"', analyticsIngestValidatePlatform(null) === 'unknown');

/* --- allow-list stays exactly in sync with the client's 4 known events - */

ok('the allow-list has exactly the 4 events IHAnalyticsEvent.swift defines today', ANALYTICS_INGEST_ALLOWED_EVENT_NAMES === [
    'screen_view', 'song_opened', 'search_performed', 'offline_save',
]);

if ($fail === 0) {
    echo "\nAll analytics-ingest assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
