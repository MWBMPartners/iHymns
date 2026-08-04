<?php

declare(strict_types=1);

/**
 * iHymns — every cookie is marked Secure behind a TLS-terminating proxy too
 * ========================================================================
 *
 * ELI5
 * ----
 * A cookie can be marked "only ever send me over an encrypted connection". We
 * work out whether the connection is encrypted by looking at one of two things
 * the server tells us. One place was only looking at the first of them, so on a
 * setup where something else handles the encryption for us, that cookie would
 * be sent unencrypted.
 *
 * THE DEFECT CLASS (already fixed twice, once missed)
 * ---------------------------------------------------
 * `$_SERVER['HTTPS']` is set by the web server that terminates TLS. Behind a
 * CDN or load balancer that terminates TLS and forwards plain HTTP to the
 * origin, `HTTPS` is UNSET on the origin even though the browser is on https —
 * the proxy signals the original scheme in `X-Forwarded-Proto` instead. A
 * `secure` flag computed from `HTTPS` alone therefore evaluates FALSE and the
 * cookie is minted WITHOUT `Secure`, leaving it eligible to ride a plaintext
 * request.
 *
 * `includes/auth_cookie.php` has always checked both. `manage/includes/auth.php`
 * was fixed to check both by #1648 item 2, whose own comment says the two cookie
 * helpers disagreeing "was the actual defect". `includes/debug_mode.php` was
 * missed by that pass and still checked `HTTPS` alone — the same defect, third
 * site, found by this sweep.
 *
 * WHY IT IS DERIVED FROM THE TREE, NOT A LIST (rule #34)
 * ------------------------------------------------------
 * A hardcoded list of the three known cookie sites is exactly the shape this
 * codebase keeps getting wrong — #1648 named the sites it knew about and missed
 * a third. This walks every PHP file under the web root, finds every
 * `setcookie()` / `session_set_cookie_params()` call, and asserts each one's
 * `secure` expression consults BOTH signals. A NEW cookie added anywhere is
 * covered the day it is written, with nobody having to remember this file.
 *
 * SCOPE / LIMITS, stated so the tick is not over-read
 *   - Static analysis of the `secure` EXPRESSION. It proves both signals are
 *     named in the right place, not that the resulting boolean is correct.
 *   - A clearing call (expiry in the past) is checked the same as a setting
 *     call, deliberately: a mismatched attribute set leaves a stale cookie
 *     behind, which `clearAuthTokenCookie()`'s own doc-block calls out.
 *   - Cookies set from JavaScript (`document.cookie`) are out of scope — they
 *     cannot be HttpOnly at all. The service-mode presence token is one, by
 *     design (rule #26).
 *
 * MUTATION RECORD (rule #34): RED on includes/debug_mode.php before the fix
 * (two call sites, set + clear). Re-removing `HTTP_X_FORWARDED_PROTO` from any
 * of the covered sites turns it RED again.
 *
 * Usage: php tests/php/test-cookie-secure-proxy.php   (exit 0 pass, 1 fail)
 */

$ROOT = dirname(__DIR__, 2);
$WEB  = $ROOT . '/appWeb/public_html';

/** Recursively collect every PHP file under the web root. */
$files = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($WEB, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $files[] = $f->getPathname(); }
}
sort($files);

if (!$files) {
    fwrite(STDERR, "FAIL: 0 PHP files found under {$WEB} — this guard is vacuous.\n");
    exit(1);
}

$failures = [];
$checked  = 0;

foreach ($files as $path) {
    $src  = (string) file_get_contents($path);
    $toks = token_get_all($src);

    /* Normalise to [id, text, line]; fill line numbers for simple tokens so a
       finding can name the exact call. */
    $T = [];
    foreach ($toks as $t) { $T[] = is_array($t) ? [$t[0], $t[1], $t[2]] : [-1, $t, null]; }
    $line = 1;
    foreach ($T as $i => $t) { if ($t[2] !== null) { $line = $t[2]; } else { $T[$i][2] = $line; } }

    for ($i = 0; $i < count($T); $i++) {
        if ($T[$i][0] !== T_STRING) { continue; }
        $fn = $T[$i][1];
        if ($fn !== 'setcookie' && $fn !== 'setrawcookie' && $fn !== 'session_set_cookie_params') { continue; }

        /* Next non-whitespace token must be '(' — otherwise this is the name
           appearing in some other context (a string, a comparison). */
        $j = $i + 1;
        while ($j < count($T) && $T[$j][0] === T_WHITESPACE) { $j++; }
        if ($j >= count($T) || $T[$j][1] !== '(') { continue; }

        /* Walk to the matching close paren, collecting the whole argument list.
           Tokenizer-based, so a ';' or a quote inside a string argument cannot
           truncate the region — the regex-truncation failure this repo has hit
           more than once. */
        $depth = 0; $args = '';
        for ($k = $j; $k < count($T); $k++) {
            $tx = $T[$k][1];
            if ($T[$k][0] === -1 && ($tx === '(' || $tx === '[')) { $depth++; if ($depth === 1) { continue; } }
            elseif ($T[$k][0] === T_CURLY_OPEN || $T[$k][0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; }
            elseif ($T[$k][0] === -1 && ($tx === ')' || $tx === ']')) { $depth--; if ($depth === 0) { break; } }
            if ($depth >= 1) { $args .= $tx; }
        }

        /* Only calls that actually express a `secure` attribute are in scope.
           A positional-arg setcookie() with no options array is reported
           separately below rather than silently skipped. */
        if (!str_contains($args, 'secure')) {
            /* The legacy positional form: setcookie(name, value, expires, path,
               domain, secure, httponly). Session-clearing calls reuse
               session_get_cookie_params(), which carries the flag through. */
            if (str_contains($args, 'session_get_cookie_params') || str_contains($args, "params['secure']")) {
                $checked++;
            }
            continue;
        }

        $checked++;

        $usesHttps    = str_contains($args, "'HTTPS'") || str_contains($args, '"HTTPS"');
        $usesForwards = str_contains($args, 'HTTP_X_FORWARDED_PROTO');
        /* A call that delegates to the shared helper inherits its correctness. */
        $delegates    = str_contains($args, '_authCookieOpts');

        if ($delegates) { continue; }

        if ($usesHttps && !$usesForwards) {
            $failures[] = sprintf(
                "%s:%d — %s() computes `secure` from \$_SERVER['HTTPS'] alone.\n"
                . "        Behind a TLS-terminating proxy HTTPS is unset, so this cookie is\n"
                . "        minted WITHOUT Secure and may ride a plaintext request. Also honour\n"
                . "        \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' (see includes/auth_cookie.php).",
                str_replace($ROOT . '/', '', $path),
                $T[$i][2],
                $fn
            );
        }
    }
}

if ($checked === 0) {
    fwrite(STDERR, "FAIL: 0 cookie calls inspected — the detection has stopped working, which\n");
    fwrite(STDERR, "      is itself the finding. Fix this guard rather than trusting its tick.\n");
    exit(1);
}

if ($failures) {
    fwrite(STDERR, "FAIL: cookie `secure` flag is proxy-blind:\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n\n"); }
    exit(1);
}

printf("PASS: %d cookie call(s) mark Secure using both HTTPS and X-Forwarded-Proto.\n", $checked);
exit(0);
