<?php

declare(strict_types=1);

/**
 * iHymns — shared outbound-host SSRF guard (security audit finding L-2, 2026-08-30)
 *
 * ELI5
 * ----
 * Before this server calls out to an admin-configured web address (CueRCode,
 * IntApps, …), this file answers one question: "does that address actually
 * point somewhere on the OPEN internet, or does it secretly point back at
 * THIS server or its private network (127.0.0.1, a cloud-metadata endpoint
 * like 169.254.169.254, a private 10.x/192.168.x address)?" If it points
 * inward, the caller should refuse to dial it — an admin-configured URL
 * that reaches an internal service instead of the real outside service is
 * exactly the SSRF (Server-Side Request Forgery) shape this file exists to
 * catch.
 *
 * DETAILED — WHY A SHARED MODULE (CLAUDE.md modularity rule)
 * ------------------------------------------------------------------------
 * `manage/configuration.php` already carries this exact check as a
 * page-local closure (`$smtpHostIsPrivate`, #1304) for the SMTP "Send test
 * email" host. The L-2 security-audit finding needed the IDENTICAL check
 * for `includes/cuercode_client.php`'s and `includes/intapps_client.php`'s
 * own outbound base URLs — resolve a host to its IP(s), then test each
 * against PHP's private/reserved-range filter. Rather than a THIRD (and
 * FOURTH) hand-copied version of the same ~15 lines free to drift
 * independently, this file is the ONE place that logic lives; both client
 * files below call it. `manage/configuration.php`'s own closure predates
 * this file and is left exactly as it was (it was already correct, not
 * broken) — this file is used ADDITIONALLY, to add the same resolve-and-
 * warn heads-up to the CueRCode/IntApps save handlers (see those handlers'
 * own comments). Pointing the SMTP closure at this same function too would
 * be a reasonable follow-up, but it is a separate, lower-risk refactor from
 * the SSRF-blocking fix this file exists to ship — not bundled in here.
 *
 * PURE / SIDE-EFFECT-FREE TO REQUIRE: this file only declares a function.
 * It makes no DNS lookups, no HTTP calls, and touches no global state at
 * require time — the same discipline `cuercode_client.php`'s own doc-block
 * promises for itself.
 *
 * @see appWeb/public_html/manage/configuration.php       $smtpHostIsPrivate() — the pattern this mirrors (#1304)
 * @see appWeb/public_html/includes/cuercode_client.php   _cuercodeResolveUrl() — a caller
 * @see appWeb/public_html/includes/intapps_client.php    _intappsResolveUrl() — a caller
 * @see appWeb/public_html/includes/ia_client.php          _iaResolveUrl() — a caller
 * @see tests/php/test-outbound-ssrf-guard.php            the standing guard for this file + its three callers
 * @link https://www.php.net/manual/en/filter.filters.flags.php  FILTER_FLAG_NO_PRIV_RANGE / FILTER_FLAG_NO_RES_RANGE
 * @link https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
 *
 * CORRECTNESS-REVIEW FIX F-1 (2026-08-30, .claude sessions correctness pass)
 * ----------------------------------------------------------------------------
 * ELI5: this guard had a blind spot. Type a web address with square brackets
 * around an IPv6 number — `https://[::1]/` — or type an IP as one long
 * plain number instead of the usual dotted/colon form — `https://2130706433/`
 * (which is just 127.0.0.1 written as one 32-bit number) — and the guard used
 * to shrug and say "not private", even though that address really is this
 * server's own loopback. The fix teaches it to recognise BOTH spellings
 * before it decides.
 *
 * DETAILED — WHY THIS WAS MISSED THE FIRST TIME
 * PHP's own `parse_url()` hands back an IPv6 host WITH its square brackets
 * still attached (`[::1]`, `[fd00::1]`) — that's correct URL syntax, it's how
 * you write an IPv6 literal in a URL so the colons inside it aren't confused
 * with the `:port` separator. But `filter_var($host, FILTER_VALIDATE_IP)`
 * validates a BARE address and rejects the brackets outright, so `[::1]` fell
 * through to the "treat it as a hostname, try DNS" branch — where a real DNS
 * lookup for the literal string `[::1]` obviously finds nothing, and this
 * function's own documented "unresolvable = not private" fallback (see
 * `ihymnsHostResolvesPrivate()`'s doc-block below) let it through as "safe".
 * `curl`, meanwhile, understands the bracket syntax perfectly and dials the
 * real `::1` — so the guard and the thing it is guarding disagreed about
 * where the request was actually going. Same story for a NUMERIC IPv4 host
 * (`0x7f000001` hex, or `2130706433` decimal): some resolvers/clients accept
 * that single-number form as shorthand for `127.0.0.1`, but `filter_var()`
 * only accepts the familiar dotted-quad shape, so that spelling slipped
 * through the same way. `ihymnsNormalizeHostLiteral()` strips the brackets
 * (leaving a genuine hostname untouched — a hostname is never bracketed) and
 * `_ihymnsNumericIpv4ToDotted()` decodes the numeric forms into an ordinary
 * dotted-quad BEFORE either the loopback carve-out in each caller or the
 * private-range check below ever look at the host, so both now see and judge
 * the SAME address curl will actually dial.
 *
 * @see tests/php/test-outbound-ssrf-guard.php   truth-table rows (a10)-(a20), mutation-proven
 */

/**
 * ELI5: if this host is an IPv6 literal written the way a URL requires it
 * (wrapped in square brackets, e.g. `[::1]`), hand back the bare address
 * underneath (`::1`) so it can be compared/classified like any other IP.
 * WHY (F-1, 2026-08-30): `parse_url()` always returns an IPv6 host WITH its
 * brackets — that's correct URL syntax — but every comparison downstream
 * (the `in_array($host, ['127.0.0.1', '::1', 'localhost'])` loopback
 * carve-out in each client, and `filter_var(..., FILTER_VALIDATE_IP)` in
 * `ihymnsHostResolvesPrivate()` below) expects the BARE form and silently
 * fails to match the bracketed one — which is exactly how `[::1]` and
 * `[fd00:ec2::254]` slipped past the SSRF guard. A plain hostname is never
 * bracketed, so this is a no-op for every non-IPv6-literal input; it never
 * needs to inspect what's inside the brackets, only strip them.
 *
 * @param string $host As returned by `parse_url()['host']`. Case doesn't
 *                      matter to this function — it only looks at the first
 *                      and last characters.
 * @return string The bare host with a surrounding `[...]` pair removed, or
 *                the input unchanged if it wasn't wrapped in one.
 */
function ihymnsNormalizeHostLiteral(string $host): string
{
    $len = strlen($host);
    if ($len >= 2 && $host[0] === '[' && $host[$len - 1] === ']') {
        return substr($host, 1, -1);
    }
    return $host;
}

/**
 * ELI5: if this host is really just an IP address spelled out as ONE long
 * number instead of the usual dotted form (`2130706433` decimal, or
 * `0x7f000001` hex — both mean `127.0.0.1`), turn it back into the familiar
 * dotted-quad string. Anything else — a real hostname, a malformed number, a
 * number outside the 32-bit range an IPv4 address can hold — hands back
 * `null` untouched.
 * WHY (F-1 SD-1, 2026-08-30): this "one giant number" spelling of an IPv4
 * address is old BSD `inet_aton()` shorthand that some HTTP clients' host
 * resolution still honours, but `filter_var(..., FILTER_VALIDATE_IP)` only
 * ever accepts the dotted-quad form — so `ihymnsHostResolvesPrivate('127.0.0.1'
 * spelled as 0x7f000001)` used to fall through to "try DNS", find nothing,
 * and report "not private" for what curl would actually dial as loopback.
 * Deliberately narrow: only the two UNAMBIGUOUS single-number forms (plain
 * decimal digits, or a `0x` hex prefix) are decoded — a partial/dotted
 * mixed-radix form (`0177.0.0.1`) is NOT handled here, so an ambiguous
 * leading-zero decimal string (which traditionally means octal in this same
 * BSD parsing family) is deliberately rejected rather than guessed at: the
 * `(string)$val !== $host` round-trip check below fails closed on exactly
 * that case (e.g. `'0177'` decodes to `177`, whose string form doesn't match
 * the original `'0177'`, so this returns `null` and the host falls through
 * to the ordinary hostname/DNS path instead of a silently-wrong guess).
 *
 * @param string $host A host string that is NOT already a valid literal IP
 *                      (callers check `filter_var(...FILTER_VALIDATE_IP)`
 *                      first — this function only handles what that one
 *                      already rejected).
 * @return string|null A dotted-quad IPv4 string, or null if `$host` isn't
 *                      one of the two recognised numeric forms.
 */
function _ihymnsNumericIpv4ToDotted(string $host): ?string
{
    if (preg_match('/^0x[0-9a-f]+$/i', $host) === 1) {
        $val = hexdec(substr($host, 2));
    } elseif (preg_match('/^[0-9]+$/', $host) === 1) {
        $val = (int)$host;
        if ((string)$val !== $host) {
            return null;                              /* ambiguous leading-zero / overflow form — reject, don't guess */
        }
    } else {
        return null;
    }
    if ($val < 0 || $val > 4294967295) {
        return null;                                  /* outside the 32-bit range an IPv4 address can hold */
    }
    return long2ip($val);
}

/**
 * ELI5: does this hostname (or literal IP) lead to a private/internal
 * address? WHY: an admin-typed outbound base URL is trusted input from a
 * privileged operator, but "trusted" still isn't "immune to a typo or a
 * compromised admin account" — resolving the host first means the check
 * looks at where the request would ACTUALLY go, not just what the text
 * says.
 *
 * Detail: the host is first normalised (F-1: an IPv6 literal's `[...]`
 * brackets are stripped, and a numeric IPv4 literal like `0x7f000001` or
 * `2130706433` is decoded to its dotted-quad form) so it can be classified
 * the SAME way curl would actually resolve it. A literal IP host is then
 * treated as already-resolved (skips DNS entirely). A hostname is resolved
 * via both IPv4 A records (`gethostbynamel()`) and IPv6 AAAA records
 * (`dns_get_record()`, best-effort — a DNS hiccup here degrades to "no AAAA
 * records found", never a fatal, matching `$smtpHostIsPrivate()`'s own
 * tolerance). ANY resolved address landing in a private (RFC 1918 / RFC
 * 4193) or reserved (loopback, link-local — which is what covers the
 * 169.254.169.254 cloud-metadata address — multicast, …) range makes this
 * return true.
 *
 * An UNRESOLVABLE host (a typo, a DNS outage) returns false — "not
 * private" — deliberately: this function only ever TIGHTENS a host that a
 * caller's own scheme/shape checks already let through; it is not the only
 * gate, so it fails toward "let the real HTTP attempt fail on its own
 * terms" rather than masking an unrelated typo as a security refusal.
 *
 * @param string $host Hostname or literal IP, optionally IPv6-bracketed or a
 *                      numeric IPv4 literal (F-1). Case doesn't matter —
 *                      comparison here is value-based via filter_var(),
 *                      never a string comparison.
 * @return bool True if the host (or any of its resolved addresses) is
 *              private/reserved.
 */
function ihymnsHostResolvesPrivate(string $host): bool
{
    $host = trim($host);
    if ($host === '') {
        return false;
    }
    $host = ihymnsNormalizeHostLiteral($host);        /* F-1: [::1] -> ::1 before any classification */
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;                              /* host is already a literal IP */
    } elseif (($numericIp = _ihymnsNumericIpv4ToDotted($host)) !== null) {
        $ips[] = $numericIp;                          /* F-1 SD-1: 0x7f000001 / 2130706433 -> 127.0.0.1 */
    } else {
        $v4 = @gethostbynamel($host);                /* IPv4 A records, or false */
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);     /* IPv6 AAAA records, best-effort */
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = (string)$rec['ipv6'];
                }
            }
        }
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;                              /* a private/reserved address among the resolutions */
        }
    }
    return false;
}
