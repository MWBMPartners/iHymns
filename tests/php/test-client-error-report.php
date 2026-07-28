<?php

declare(strict_types=1);

/**
 * iHymns — Client error-report validation/scrub/fingerprint test (#1582)
 *
 * Pure-function coverage (no DB): exercises
 * clientErrorScrub()/clientErrorValidate()/clientErrorFingerprint()
 * directly — the same validation `?action=client_error_report` (api.php)
 * runs on every beacon `js/modules/error-monitor.js` sends. Modelled on
 * tests/php/test-analytics-ingest.php (its sibling pure-validator test).
 *
 * A test that cannot fail is worthless (hard project rule). This file's
 * PR description records a deliberate red run for the scrub assertions
 * (Bearer-token / hex64 patterns temporarily neutered) and the
 * fingerprint-stability/env-scoping assertions (the environment prefix
 * temporarily dropped), each restored immediately after.
 *
 *   php tests/php/test-client-error-report.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/client_error_report.php';

$fail = 0;
function ok(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $fail++; }
}

/* --- clientErrorScrub(): the load-bearing secret patterns -------------- */

ok(
    'a Bearer token is redacted',
    !str_contains(clientErrorScrub('Authorization: Bearer abc123.def456-ghi'), 'abc123.def456-ghi')
);
ok(
    'a redacted Bearer value keeps the "Bearer" marker (still forensically useful)',
    str_contains(clientErrorScrub('Authorization: Bearer abc123.def456-ghi'), 'Bearer [redacted]')
);
$hex64 = str_repeat('a1b2c3d4', 8); // exactly 64 hex chars
ok('a 64-hex-char string is redacted', !str_contains(clientErrorScrub("session={$hex64}"), $hex64));
ok('a redacted hex-64 value leaves the "[redacted]" marker', str_contains(clientErrorScrub("session={$hex64}"), '[redacted]'));
ok('a 63-char hex string (one short) is NOT touched — the pattern is exact-length', clientErrorScrub(str_repeat('a', 63)) === str_repeat('a', 63));
ok('plain text with no secret shape round-trips unchanged', clientErrorScrub('TypeError: x is not a function') === 'TypeError: x is not a function');
ok('an empty string round-trips unchanged', clientErrorScrub('') === '');
ok('scrub is case-insensitive on "bearer"', !str_contains(clientErrorScrub('bearer sometoken123'), 'sometoken123'));

/* --- clientErrorValidate(): happy path ---------------------------------- */

$valid = clientErrorValidate([
    'm'  => 'TypeError: x is not a function',
    's'  => 'https://ihymns.app/js/app.js',
    'l'  => 1204,
    'c'  => 7,
    'r'  => '/song/MP-1008',
    'v'  => '3.4.0',
    'ch' => 'Alpha',
    'k'  => 'error',
    'st' => ['/js/app.js:1204', '/js/other.js:10'],
]);
ok('a well-formed report validates', $valid !== null);
ok('validated report keeps the message', ($valid['m'] ?? null) === 'TypeError: x is not a function');
ok('validated report keeps the kind', ($valid['k'] ?? null) === 'error');
ok('validated report keeps the line number', ($valid['l'] ?? null) === 1204);
ok('validated report keeps the stack frames (capped list)', ($valid['st'] ?? null) === ['/js/app.js:1204', '/js/other.js:10']);

/* --- k (kind): closed 2-value allow-list -------------------------------- */

ok('kind "rejection" is accepted', clientErrorValidate(['m' => 'x', 'k' => 'rejection']) !== null);
ok('an unrecognised kind is rejected outright', clientErrorValidate(['m' => 'x', 'k' => 'fatal']) === null);
ok('a missing kind is rejected', clientErrorValidate(['m' => 'x']) === null);
ok('an empty-string kind is rejected', clientErrorValidate(['m' => 'x', 'k' => '']) === null);
ok('a completely empty payload (no k, no m) is rejected', clientErrorValidate([]) === null);

/* --- m (message): required, non-empty, scrubbed + capped ---------------- */

ok('a missing message is rejected', clientErrorValidate(['k' => 'error']) === null);
ok('an empty-string message is rejected', clientErrorValidate(['k' => 'error', 'm' => '']) === null);
ok('a whitespace-only message is rejected (trimmed to empty)', clientErrorValidate(['k' => 'error', 'm' => "   \n\t"]) === null);
$longMessage = str_repeat('x', CLIENT_ERROR_MAX_MESSAGE_LENGTH + 50);
$cappedResult = clientErrorValidate(['k' => 'error', 'm' => $longMessage]);
ok('an over-length message is capped, not rejected', $cappedResult !== null && mb_strlen($cappedResult['m']) === CLIENT_ERROR_MAX_MESSAGE_LENGTH);
$secretMessage = clientErrorValidate(['k' => 'error', 'm' => 'AuthError: Bearer topsecret123 failed']);
ok('the message field is scrubbed before storage', $secretMessage !== null && !str_contains($secretMessage['m'], 'topsecret123'));

/* --- s / r (path fields): query string + fragment stripped, capped ------ */

$withQuery = clientErrorValidate([
    'k' => 'error', 'm' => 'x',
    's' => 'https://ihymns.app/js/app.js?token=leak-me',
    'r' => '/song/MP-1008?ref=leak-me#section',
]);
ok('a query string is never trusted from the client — s is stripped of it', $withQuery !== null && !str_contains($withQuery['s'], 'leak-me'));
ok('a query string is never trusted from the client — r is stripped of it', $withQuery !== null && !str_contains($withQuery['r'], 'leak-me'));
ok('a fragment is stripped from r', $withQuery !== null && !str_contains($withQuery['r'], 'section'));
ok('r keeps the path portion', $withQuery !== null && str_starts_with($withQuery['r'], '/song/MP-1008'));

/* 'z' (not a hex digit) — deliberately NOT 'a'-'f', so this padding can
   never accidentally look like a 64-hex-char token and get shortened by
   clientErrorScrub() before the length cap runs; that would make this
   assertion about the CAP conflate with the separate scrub behaviour
   already covered above. */
$longPath = 'https://ihymns.app/' . str_repeat('z', CLIENT_ERROR_MAX_PATH_LENGTH + 50);
$cappedPath = clientErrorValidate(['k' => 'error', 'm' => 'x', 's' => $longPath]);
ok('an over-length path is capped, not rejected', $cappedPath !== null && mb_strlen($cappedPath['s']) === CLIENT_ERROR_MAX_PATH_LENGTH);

/* --- s / r absent: degrade to empty string, never reject ---------------- */

$noPaths = clientErrorValidate(['k' => 'error', 'm' => 'x']);
ok('a report with no s/r fields still validates', $noPaths !== null);
ok('a missing s degrades to empty string, not null/rejection', ($noPaths['s'] ?? null) === '');
ok('a missing r degrades to empty string, not null/rejection', ($noPaths['r'] ?? null) === '');

/* --- l / c (line/column): clamped, never reject the whole report -------- */

ok('a non-numeric line degrades to null (report still validates)', clientErrorValidate(['k' => 'error', 'm' => 'x', 'l' => 'not-a-number'])['l'] === null);
ok('a negative line degrades to null', clientErrorValidate(['k' => 'error', 'm' => 'x', 'l' => -5])['l'] === null);
ok('an absurdly large line degrades to null', clientErrorValidate(['k' => 'error', 'm' => 'x', 'l' => CLIENT_ERROR_MAX_LINE_NUMBER + 1])['l'] === null);
ok('a numeric string line is coerced to int', clientErrorValidate(['k' => 'error', 'm' => 'x', 'l' => '42'])['l'] === 42);
ok('zero is a legitimate line number (not treated as missing)', clientErrorValidate(['k' => 'error', 'm' => 'x', 'l' => 0])['l'] === 0);

/* --- st (stack frames): array of strings, capped, scrubbed -------------- */

ok('a non-array st degrades to an empty list', clientErrorValidate(['k' => 'error', 'm' => 'x', 'st' => 'not-an-array'])['st'] === []);
$manyFrames = clientErrorValidate(['k' => 'error', 'm' => 'x', 'st' => ['/a:1', '/b:2', '/c:3', '/d:4', '/e:5']]);
ok('st is capped to CLIENT_ERROR_MAX_FRAMES entries', count($manyFrames['st']) === CLIENT_ERROR_MAX_FRAMES);
ok('a non-string frame entry is dropped, not fatal', clientErrorValidate(['k' => 'error', 'm' => 'x', 'st' => [123, '/a:1']])['st'] === ['/a:1']);
$secretFrame = clientErrorValidate(['k' => 'error', 'm' => 'x', 'st' => ['Bearer leak-token-here']]);
ok('a stack frame is scrubbed too', !str_contains($secretFrame['st'][0] ?? '', 'leak-token-here'));

/* --- v / ch (version / channel labels): capped, degrade to empty -------- */

ok('a missing v degrades to empty string', clientErrorValidate(['k' => 'error', 'm' => 'x'])['v'] === '');
ok('a missing ch degrades to empty string', clientErrorValidate(['k' => 'error', 'm' => 'x'])['ch'] === '');
$longLabel = clientErrorValidate(['k' => 'error', 'm' => 'x', 'v' => str_repeat('9', CLIENT_ERROR_MAX_LABEL_LENGTH + 10)]);
ok('an over-length v is capped', mb_strlen($longLabel['v']) === CLIENT_ERROR_MAX_LABEL_LENGTH);

/* --- clientErrorFingerprint(): stable + env-scoped ---------------------- */

$payloadA = ['m' => 'TypeError: x', 's' => '/js/app.js', 'l' => 42];
$payloadB = ['m' => 'TypeError: x', 's' => '/js/app.js', 'l' => 42]; // same content, different array instance
ok('the same payload+env produces the same fingerprint (stable)', clientErrorFingerprint($payloadA, 'alpha') === clientErrorFingerprint($payloadB, 'alpha'));
ok('a fingerprint is exactly 16 lowercase-hex characters', (bool)preg_match('/^[0-9a-f]{16}$/', clientErrorFingerprint($payloadA, 'alpha')));
ok(
    'the SAME payload in a DIFFERENT environment produces a DIFFERENT fingerprint (env-scoped)',
    clientErrorFingerprint($payloadA, 'alpha') !== clientErrorFingerprint($payloadA, 'production')
);
ok(
    'alpha and beta are also distinguished from each other',
    clientErrorFingerprint($payloadA, 'alpha') !== clientErrorFingerprint($payloadA, 'beta')
);
$payloadDifferentLine = ['m' => 'TypeError: x', 's' => '/js/app.js', 'l' => 43];
ok('a different line number changes the fingerprint', clientErrorFingerprint($payloadA, 'alpha') !== clientErrorFingerprint($payloadDifferentLine, 'alpha'));

if ($fail === 0) {
    echo "\nAll client-error-report assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
