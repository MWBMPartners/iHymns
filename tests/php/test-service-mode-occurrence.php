<?php

/**
 * iHymns — Service Mode occurrence-end floor guard (#1576)
 *
 * ELI5: when someone taps "Start" on an ad-hoc service (no saved schedule
 * picked), the server used to work out "when does this end?" from a fake
 * placeholder start time (10:00) rather than the real moment the button was
 * pressed. Start an evening service and the maths said it had already ended
 * before it began — every congregant who joined immediately saw "The service
 * has ended." This test proves that can no longer happen for the AD-HOC path,
 * while proving a REAL scheduled occurrence that genuinely finished in the
 * past is still honestly reported as expired (that is correct, not a bug).
 *
 * DETAILED — exercises includes/service_mode.php::serviceMode_occurrenceEndUtc()
 * directly (no DB / HTTP needed). Two families of assertion:
 *   1. $isAdHoc = true  → resolved end must NEVER be <= now, for a spread of
 *      "started N hours ago" offsets (covering morning/midday/evening/
 *      overnight starts) and a couple of IANA timezones, AND the floored
 *      result must respect the existing SERVICE_MODE_HARD_CEILING_HOURS cap
 *      (a huge ad-hoc duration doesn't escape the ceiling).
 *   2. $isAdHoc = false (the default — every OTHER/scheduled caller) → a
 *      genuinely-past scheduled occurrence stays in the past (un-floored);
 *      a future scheduled occurrence still resolves to the expected
 *      start+duration instant (no regression to the pre-#1576 maths).
 *
 * USAGE:
 *   php tests/php/test-service-mode-occurrence.php
 *
 * Run against the pre-fix code (this test was written to FAIL there — see
 * the #1576 commit body for the captured failing output) it reports ad-hoc
 * cases resolving to a past ExpiresAt; against the fixed code every
 * assertion passes.
 *
 * Exit status 0 = clean, 1 = at least one failure.
 *
 * @see https://www.php.net/manual/en/class.datetime.php (DST-aware local->UTC math)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/service_mode.php';

$failures = 0;
$passed = 0;

function _smoAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/**
 * UTC 'now' as a DateTime, matching what serviceMode_occurrenceEndUtc()
 * itself anchors on — truncated to whole seconds (setTime's 4th arg
 * defaults microsecond to 0) because the function's return value is a
 * 'Y-m-d H:i:s' string with second precision; comparing a microsecond-
 * bearing 'now' against a round-second parsed result is an off-by-a-
 * fraction-of-a-second false failure, not a real bug.
 */
function _smoNowUtc(): \DateTime
{
    $now = new \DateTime('now', new \DateTimeZone('UTC'));
    $now->setTime((int)$now->format('H'), (int)$now->format('i'), (int)$now->format('s'));
    return $now;
}

/** Parse the function's 'Y-m-d H:i:s' return into a UTC DateTime for comparison. */
function _smoParse(string $ymdHis): \DateTime
{
    return \DateTime::createFromFormat('Y-m-d H:i:s', $ymdHis, new \DateTimeZone('UTC'));
}

/**
 * Build an occurrenceDate/startTime pair, in $tz-local wall-clock terms, that
 * represents "the service was supposed to start $hoursAgo hours before now"
 * — i.e. deterministically in the past regardless of when this test itself
 * happens to run (never depends on the real wall-clock hour at test time).
 *
 * @return array{0:string,1:string} [occurrenceDate 'Y-m-d', startTime 'H:i:s']
 */
function _smoStartHoursAgo(float $hoursAgo, string $tz): array
{
    $local = new \DateTime('now', new \DateTimeZone($tz));
    $local->modify('-' . (int)round($hoursAgo * 60) . ' minutes');
    return [$local->format('Y-m-d'), $local->format('H:i:s')];
}

/* =========================================================================
 * 1. AD-HOC — never born already-expired, across a spread of "time of day"
 *    offsets (i.e. however many hours ago the placeholder 10:00 start would
 *    have been) and a couple of timezones (UTC + a non-UTC IANA zone, since
 *    the resolver converts local -> UTC and a tz bug could hide behind UTC
 *    alone).
 * ========================================================================= */
$duration = 90; /* matches api.php's ad-hoc placeholder duration (1.5h) */
foreach (['UTC', 'Pacific/Auckland', 'America/Los_Angeles'] as $tz) {
    /* 0.25h (a start just 15 min ago) through 23h (almost a full day ago) —
       covers every "time of day" an operator could have hit Start relative
       to now, including the exact evening-service shape from the bug report
       (start well before now, duration already elapsed). Every offset gets
       the universal "never in the past" check; offsets that are already
       PAST the placeholder's own natural end (hoursAgo >= duration/60, i.e.
       >= 1.5h — the boundary cases below it never needed flooring at all,
       so a "lands near now+duration" claim wouldn't mean anything there)
       additionally get the "floors to close to now+duration" check. */
    foreach ([0.25, 0.75, 1, 1.25, 1.5, 2, 3, 6, 9, 12, 15, 18, 21, 23] as $hoursAgo) {
        [$occDate, $startTime] = _smoStartHoursAgo($hoursAgo, $tz);
        $before = _smoNowUtc();
        $endStr = serviceMode_occurrenceEndUtc($occDate, $startTime, $duration, $tz, true);
        $end    = _smoParse($endStr);
        _smoAssert(
            $end >= $before,
            "ad-hoc floor: tz={$tz} start={$hoursAgo}h ago -> end ({$endStr}) is not before 'now' ({$before->format('Y-m-d H:i:s')})"
        );
        if ($hoursAgo * 60 >= $duration) {
            /* This offset's natural (unfloored) end was already <= now, so
               the floor engaged: the result should land close to
               now+duration, not near the stale placeholder instant. */
            $expectedFloor = (clone $before)->modify('+' . $duration . ' minutes');
            $driftSeconds  = abs($end->getTimestamp() - $expectedFloor->getTimestamp());
            _smoAssert(
                $driftSeconds <= 5,
                "ad-hoc floor: tz={$tz} start={$hoursAgo}h ago -> end lands within 5s of now+{$duration}min (drift {$driftSeconds}s)"
            );
        }
    }
}

/* Ad-hoc + a duration below the floor minimum still gets AT LEAST the
   minimum, not just "duration minutes from now". */
{
    [$occDate, $startTime] = _smoStartHoursAgo(5, 'UTC');
    $before = _smoNowUtc();
    $endStr = serviceMode_occurrenceEndUtc($occDate, $startTime, 1, 'UTC', true);
    $end    = _smoParse($endStr);
    $minFloorInstant = (clone $before)->modify('+' . SERVICE_MODE_ADHOC_MIN_MINUTES . ' minutes');
    _smoAssert(
        $end >= $minFloorInstant,
        "ad-hoc floor: a tiny duration (1 min) still floors to >= SERVICE_MODE_ADHOC_MIN_MINUTES (" . SERVICE_MODE_ADHOC_MIN_MINUTES . " min) from now"
    );
}

/* Ad-hoc floor must still respect the existing hard ceiling — a huge
   duration doesn't let an ad-hoc session escape SERVICE_MODE_HARD_CEILING_HOURS. */
{
    [$occDate, $startTime] = _smoStartHoursAgo(2, 'UTC');
    $before  = _smoNowUtc();
    $endStr  = serviceMode_occurrenceEndUtc($occDate, $startTime, 600, 'UTC', true); /* 10h duration */
    $end     = _smoParse($endStr);
    $ceiling = (clone $before)->modify('+' . SERVICE_MODE_HARD_CEILING_HOURS . ' hours');
    _smoAssert(
        $end <= $ceiling->modify('+5 seconds'),
        "ad-hoc floor: a 10h duration is still capped at the " . SERVICE_MODE_HARD_CEILING_HOURS . "h hard ceiling (end {$endStr})"
    );
    _smoAssert(
        $end > $before,
        "ad-hoc floor: the ceiling-capped result is still in the future (end {$endStr})"
    );
}

/* =========================================================================
 * 2. SCHEDULED (isAdHoc = false, the default) — behaviour is UNCHANGED:
 *    a genuinely past occurrence is reported honestly as expired; a future
 *    one resolves to the expected start+duration instant.
 * ========================================================================= */
{
    [$occDate, $startTime] = _smoStartHoursAgo(5, 'UTC');
    $before = _smoNowUtc();
    /* Explicit isAdHoc=false — the scheduled path. */
    $endStr = serviceMode_occurrenceEndUtc($occDate, $startTime, 90, 'UTC', false);
    $end    = _smoParse($endStr);
    _smoAssert(
        $end < $before,
        "scheduled (isAdHoc=false): a genuinely past occurrence stays in the past (end {$endStr}, not floored)"
    );
}
{
    /* Same past scenario, but calling WITHOUT the new 5th argument at all —
       proves the default preserves the pre-#1576 signature/behaviour for
       every caller that hasn't been updated. */
    [$occDate, $startTime] = _smoStartHoursAgo(5, 'UTC');
    $before = _smoNowUtc();
    $endStr = serviceMode_occurrenceEndUtc($occDate, $startTime, 90, 'UTC');
    $end    = _smoParse($endStr);
    _smoAssert(
        $end < $before,
        "scheduled (default 4-arg call): a genuinely past occurrence stays in the past (end {$endStr})"
    );
}
{
    /* A future scheduled occurrence (well inside the hard-ceiling window —
       see the ceiling test just below for what happens outside it) resolves
       to exactly start+duration, same as before this fix — no drift
       introduced by the new parameter. */
    $future = new \DateTime('now', new \DateTimeZone('UTC'));
    $future->modify('+45 minutes');
    $occDate   = $future->format('Y-m-d');
    $startTime = $future->format('H:i:s');
    $endStr    = serviceMode_occurrenceEndUtc($occDate, $startTime, 60, 'UTC', false);
    $expected  = (clone $future)->modify('+60 minutes')->format('Y-m-d H:i:s');
    _smoAssert(
        $endStr === $expected,
        "scheduled: near-future start +45min + 60min duration -> exact start+duration (expected {$expected}, got {$endStr})"
    );
}
{
    /* Scheduled path's own hard ceiling still applies (unrelated to the
       ad-hoc floor — this is pre-existing behaviour, asserted for
       completeness alongside the new isAdHoc branch): an occurrence whose
       computed end is beyond SERVICE_MODE_HARD_CEILING_HOURS from now
       — whether because it's far in the future or the duration is huge —
       is still capped, exactly as it was before this fix. */
    $future = new \DateTime('now', new \DateTimeZone('UTC'));
    $future->modify('+2 days');
    $occDate = $future->format('Y-m-d');
    $before  = _smoNowUtc();
    $endStr  = serviceMode_occurrenceEndUtc($occDate, '14:00:00', 60, 'UTC', false);
    $end     = _smoParse($endStr);
    $ceiling = (clone $before)->modify('+' . SERVICE_MODE_HARD_CEILING_HOURS . ' hours');
    _smoAssert(
        $end <= $ceiling->modify('+5 seconds'),
        "scheduled: an occurrence 2 days out is still capped at the {$endStr} hard ceiling (unchanged pre-existing behaviour)"
    );
}

/* ----------------------------------------------------------------------- */
echo "\n";
echo "service-mode-occurrence: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
