<?php

declare(strict_types=1);

/**
 * iHymns — validateCsrfRequest() same-origin authority matching (#1709)
 * =====================================================================
 *
 * ELI5
 * ----
 * When the browser sends a "save this" request, the server checks the request
 * really came from our own site. It does that by comparing the address the
 * browser says it is on (`Origin`) with the address it asked for (`Host`).
 * Those two write the port number differently, so on any site NOT running on
 * the standard port the comparison never matched and every save was refused.
 * This file hands the real function a table of made-up requests and checks the
 * yes/no answers.
 *
 * THE BUG (#1709)
 * ---------------
 * `$_SERVER['HTTP_HOST']` KEEPS the port — `example.com:8080`.
 * `parse_url($origin, PHP_URL_HOST)` STRIPS it — `example.com`.
 * The old code compared those two strings directly, so the same-origin route
 * of `validateCsrfRequest()` could NEVER pass whenever the site ran on a
 * non-default port. Every state-changing AJAX write that relies on the
 * `X-Requested-With` route (rule #29: the editor save, `/manage/duplicate-songs`
 * merge + delete, `/manage/places-api.php`, the whole legacy editor POST gate)
 * was rejected with a CSRF error on such a deployment, UNLESS the caller also
 * happened to carry a still-valid baked session token — i.e. exactly the stale
 * token that route exists to stop depending on.
 *
 * THE SECOND DEFECT, found while fixing the first
 * -----------------------------------------------
 * Because the port was dropped from the Origin and never compared, a DIFFERENT
 * origin on the SAME host was accepted: on a site at `example.com` (port 443),
 * an `Origin: https://example.com:9090` matched, since both sides reduced to
 * `example.com`. Port is part of an origin (RFC 6454 §4), so that is a genuine
 * — if narrow — same-origin-policy hole: anything the attacker can get to
 * answer on another port of the same host inherits the ability to forge writes.
 * Case 10 pins it closed.
 *
 * WHY THIS IS BEHAVIOURAL, NOT A SOURCE SCAN (rule #34)
 * -----------------------------------------------------
 * It CALLS `validateCsrfRequest()` — the shipping function, loaded from the
 * shipping file — with `$_SERVER` populated. A function cannot pass here by
 * containing the right words, only by answering correctly. `auth.php` requires
 * config/db/entitlements/activity-log but touches no database at load time, so
 * it loads under CLI with no MySQL; and passing `$token = null` short-circuits
 * the `validateCsrf()` branch before it would ever call `session_start()`.
 *
 * WHAT IT DOES NOT COVER, so its tick is not over-read
 *   - That callers actually SEND `X-Requested-With` (that is
 *     tests/php/test-editor-api2-contract.php's job).
 *   - Cross-SCHEME origins: `http://example.com` is accepted against an https
 *     deployment. That is deliberate — see the note on the fix. Case 17 pins
 *     the CURRENT behaviour so a future change to it is a conscious one.
 *   - The EXPLICIT IPv6 branch inside `_csrfSplitAuthority()`. See the mutation
 *     record below: case 14 pins the BEHAVIOUR ("an IPv6 literal with a port
 *     must match") but does NOT pin that branch, because the generic
 *     last-colon path reaches the same answer. Stated rather than implied, so
 *     this file's tick is not read as covering code it does not reach.
 *
 * MUTATION RECORD (rule #34 — proven able to fail before it was trusted):
 *   RED against the PRE-FIX auth.php: cases 2, 3, 6, 10, 14 fail — 2/3/6/14 are
 *   the #1709 functional bug, 10 is the port-confusion hole.
 *
 *   Deleting the port comparison (`|| $headerPort !== $requestPort`): RED on
 *   cases 10, 12, 13. So the port half is genuinely exercised.
 *
 *   Disabling the explicit IPv6 branch in `_csrfSplitAuthority()`: STILL GREEN,
 *   and that is the honest result, not a gap papered over. A bracketed host
 *   always ends in `]`, and RFC 3986 puts the port AFTER the closing bracket —
 *   so for every well-formed authority the generic "split on the last colon if
 *   what follows is all digits" rule gives the identical answer, verified for
 *   `[::1]`, `[::1]:8080`, `[fe80::1]:443` and `[::ffff:192.168.1.1]`. The two
 *   paths diverge ONLY on malformed input (`[::1]:abc`, `[::1]junk`), where the
 *   explicit branch fails closed and the generic one returns a host that then
 *   fails the caller's comparison anyway — i.e. both reject. The branch is kept
 *   for explicitness and fail-closed behaviour, not because it changes a
 *   verdict, and no case here claims otherwise.
 *
 * Usage: php tests/php/test-csrf-same-origin.php   (exit 0 pass, 1 fail)
 *
 * @see appWeb/public_html/manage/includes/auth.php  validateCsrfRequest()
 * @link https://datatracker.ietf.org/doc/html/rfc6454#section-4  origin = scheme+host+port
 * @link https://fetch.spec.whatwg.org/#origin-header
 */

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/appWeb/public_html/manage/includes/auth.php';

if (!function_exists('validateCsrfRequest')) {
    fwrite(STDERR, "FAIL: validateCsrfRequest() not defined — auth.php did not load.\n");
    exit(1);
}

/**
 * One row of the table: populate $_SERVER exactly as PHP would for the
 * described request, then ask the real function.
 *
 * @param string      $host    Value of the Host header (HTTP_HOST) — port INCLUDED, as PHP gives it.
 * @param string|null $origin  Origin header, or null for "not sent".
 * @param string|null $referer Referer header, or null for "not sent".
 * @param bool        $xrw     Whether X-Requested-With was sent.
 */
function csrfCase(string $host, ?string $origin, ?string $referer, bool $xrw): bool
{
    /* Rebuild the request environment from scratch each time so a previous
       case's header can never leak into the next one — the failure mode that
       makes a table test quietly assert less than it claims. */
    foreach (['HTTP_HOST', 'HTTP_ORIGIN', 'HTTP_REFERER', 'HTTP_X_REQUESTED_WITH'] as $k) {
        unset($_SERVER[$k]);
    }
    $_SERVER['HTTP_HOST']       = $host;
    $_SERVER['REQUEST_METHOD']  = 'POST';
    $_SERVER['REQUEST_URI']     = '/manage/editor/api2.php';
    if ($origin  !== null) { $_SERVER['HTTP_ORIGIN']  = $origin; }
    if ($referer !== null) { $_SERVER['HTTP_REFERER'] = $referer; }
    if ($xrw)              { $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest'; }

    /* null token → the (a) session-token branch short-circuits without ever
       starting a session, so this exercises ONLY the same-origin route. */
    return validateCsrfRequest(null);
}

/* ---------------------------------------------------------------------------
 * THE UNIT TABLE (#1709)
 *   [ label, HTTP_HOST, Origin, Referer, X-Requested-With, expected ]
 * ------------------------------------------------------------------------- */
$TABLE = [
    /*  1 */ ['plain same-origin, default port',        'example.com',      'https://example.com',      null, true,  true],
    /*  2 */ ['#1709 non-standard port (Origin)',       'example.com:8080', 'http://example.com:8080',  null, true,  true],
    /*  3 */ ['#1709 dev TLS port',                     'localhost:8443',   'https://localhost:8443',   null, true,  true],
    /*  4 */ ['cross-origin host is rejected',          'example.com',      'https://evil.com',         null, true,  false],
    /*  5 */ ['Referer with a path matches',            'example.com',      null, 'https://example.com/manage/x',    true,  true],
    /*  6 */ ['#1709 non-standard port (Referer)',      'example.com:8080', null, 'http://example.com:8080/manage/x', true, true],
    /*  7 */ ['no Origin AND no Referer is rejected',   'example.com',      null, null,                              true,  false],
    /*  8 */ ['missing X-Requested-With is rejected',   'example.com',      'https://example.com',      null, false, false],
    /*  9 */ ['unparseable Origin is rejected',         'example.com',      'garbage',                  null, true,  false],
    /* 10 */ ['different PORT, same host, rejected',    'example.com',      'https://example.com:9090', null, true,  false],
    /* 11 */ ['host compare is case-insensitive',       'example.com',      'https://EXAMPLE.COM',      null, true,  true],
    /* 12 */ ['port mismatch both explicit, rejected',  'example.com:8080', 'http://example.com:9090',  null, true,  false],
    /* 13 */ ['port on one side only, rejected',        'example.com:8080', 'http://example.com',       null, true,  false],
    /* 14 */ ['IPv6 literal with a port',               '[::1]:8080',       'http://[::1]:8080',        null, true,  true],
    /* 15 */ ['explicit default port normalises away',  'example.com',      'https://example.com:443',  null, true,  true],
    /* 16 */ ['BOTH headers must match, not either',    'example.com',      'https://example.com', 'https://evil.com/x', true, false],
    /* 17 */ ['cross-scheme accepted (documented)',     'example.com',      'http://example.com',       null, true,  true],
];

$failures = [];
foreach ($TABLE as $i => [$label, $host, $origin, $referer, $xrw, $expected]) {
    $got = csrfCase($host, $origin, $referer, $xrw);
    if ($got !== $expected) {
        $failures[] = sprintf(
            "case %d (%s)\n      Host: %-18s Origin: %-28s Referer: %-28s XRW: %s\n      expected %s, got %s",
            $i + 1,
            $label,
            $host,
            var_export($origin, true),
            var_export($referer, true),
            $xrw ? 'yes' : 'no',
            $expected ? 'ACCEPT' : 'REJECT',
            $got ? 'ACCEPT' : 'REJECT'
        );
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: validateCsrfRequest() same-origin matching (#1709):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n\n"); }
    fwrite(STDERR, "HTTP_HOST keeps the port; parse_url(..., PHP_URL_HOST) strips it. The two\n");
    fwrite(STDERR, "authorities must be compared as host + port, not as raw strings.\n");
    exit(1);
}

printf("PASS: %d validateCsrfRequest() same-origin cases (#1709).\n", count($TABLE));
exit(0);
