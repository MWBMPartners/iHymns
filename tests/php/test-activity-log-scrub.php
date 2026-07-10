<?php

/**
 * iHymns — Activity Log query-string secret redaction guard (#1492)
 *
 * Exercises includes/activity_log.php::activityLogScrubQuery() — the pure
 * function that blanks credential-bearing query parameters before the raw
 * request query string is written to tblActivityLog.Details.query by
 * installRequestActivityLogger(). Without it, GET endpoints that carry a
 * secret in the query (live_follow_poll/join → code + token; service_poll →
 * presenceToken) persist that secret into the Activity Log on every request
 * (CWE-532). Asserts the load-bearing properties so a regression re-opens the
 * leak at CI time:
 *
 *   - credential-like params (token/code/presenceToken/state/secret/password/
 *     key/auth/signature/otp/nonce) have their VALUE replaced with [redacted]
 *   - the parameter NAME is preserved (the row stays forensically useful)
 *   - benign params (action/since/id/page/q) pass through UNCHANGED
 *   - matching is case-insensitive
 *   - a value containing '=' (base64 padding) is fully redacted
 *   - a bare flag (no '=') and an empty value are left as-is (nothing to leak)
 *   - duplicate params are each handled
 *   - the two real-world poll query strings redact correctly
 *
 *   php tests/php/test-activity-log-scrub.php
 *
 * Exit status 0 = clean, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/activity_log.php';

$failures = 0;
$passed = 0;

function _alsAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

function _alsEq(string $got, string $want, string $label): void
{
    _alsAssert($got === $want, $label . ($got === $want ? '' : "\n      got:  {$got}\n      want: {$want}"));
}

/* ---- 1. The two real-world leak vectors -------------------------------- */
_alsEq(
    activityLogScrubQuery('action=service_poll&presenceToken=Zx9K7realTOKENvalue43chars0000000000000000&since=41'),
    'action=service_poll&presenceToken=[redacted]&since=41',
    'service_poll: presenceToken redacted, action+since preserved'
);
_alsEq(
    activityLogScrubQuery('action=live_follow_poll&code=ABC123&token=deadbeefcafe&since=5'),
    'action=live_follow_poll&code=[redacted]&token=[redacted]&since=5',
    'live_follow_poll: BOTH code and token redacted, action+since preserved'
);
_alsEq(
    activityLogScrubQuery('action=live_follow_join&code=WXYZ99'),
    'action=live_follow_join&code=[redacted]',
    'live_follow_join: session code redacted'
);

/* ---- 2. Credential vocabulary (name-based) ----------------------------- */
foreach (['token', 'code', 'presenceToken', 'state', 'secret', 'password', 'passwd',
          'apiKey', 'api_key', 'csrf_token', 'signature', 'otp', 'nonce', 'authToken'] as $k) {
    _alsEq(
        activityLogScrubQuery("a=1&{$k}=SECRETVALUE&b=2"),
        "a=1&{$k}=[redacted]&b=2",
        "redacts credential param '{$k}'"
    );
}

/* ---- 3. Case-insensitive --------------------------------------------- */
_alsEq(activityLogScrubQuery('Token=X'),        'Token=[redacted]',        'case-insensitive: Token');
_alsEq(activityLogScrubQuery('CODE=X'),         'CODE=[redacted]',         'case-insensitive: CODE');
_alsEq(activityLogScrubQuery('PresenceToken=X'), 'PresenceToken=[redacted]', 'case-insensitive: PresenceToken');

/* ---- 4. Benign params pass through unchanged -------------------------- */
_alsEq(
    activityLogScrubQuery('action=songs_index&since=41&id=1008&page=2&q=amazing'),
    'action=songs_index&since=41&id=1008&page=2&q=amazing',
    'benign query is byte-identical (no over-redaction of action/since/id/page/q)'
);

/* ---- 5. Structural edge cases ---------------------------------------- */
_alsEq(activityLogScrubQuery(''), '', 'empty query → empty');
_alsEq(activityLogScrubQuery('debug'), 'debug', 'bare flag (no "=") left as-is');
_alsEq(activityLogScrubQuery('token='), 'token=', 'empty value not rewritten to [redacted] (nothing to leak)');
_alsEq(
    activityLogScrubQuery('state=eyJhbGciOiJ9.payload=='),
    'state=[redacted]',
    'value containing "=" (base64 padding) fully redacted'
);
_alsEq(
    activityLogScrubQuery('token=aaa&x=1&token=bbb'),
    'token=[redacted]&x=1&token=[redacted]',
    'duplicate credential params each redacted'
);
_alsEq(
    activityLogScrubQuery('&&token=x&&'),
    '&&token=[redacted]&&',
    'empty segments preserved, credential still redacted'
);

/* ---- 6. The secret VALUE never survives anywhere in the output -------- */
$leaky = 'presenceToken=SUPERSECRET_DO_NOT_LEAK_0123456789&code=SESSIONCODE99';
$scrubbed = activityLogScrubQuery($leaky);
_alsAssert(strpos($scrubbed, 'SUPERSECRET_DO_NOT_LEAK') === false, 'the raw presence token does not appear in output');
_alsAssert(strpos($scrubbed, 'SESSIONCODE99') === false, 'the raw session code does not appear in output');

/* ----------------------------------------------------------------------- */
echo "\n";
echo "activity-log-scrub: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
