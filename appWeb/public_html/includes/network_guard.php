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
 * @see tests/php/test-outbound-ssrf-guard.php            the standing guard for this file + its two callers
 * @link https://www.php.net/manual/en/filter.filters.flags.php  FILTER_FLAG_NO_PRIV_RANGE / FILTER_FLAG_NO_RES_RANGE
 * @link https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
 */

/**
 * ELI5: does this hostname (or literal IP) lead to a private/internal
 * address? WHY: an admin-typed outbound base URL is trusted input from a
 * privileged operator, but "trusted" still isn't "immune to a typo or a
 * compromised admin account" — resolving the host first means the check
 * looks at where the request would ACTUALLY go, not just what the text
 * says.
 *
 * Detail: a literal IP host is treated as already-resolved (skips DNS
 * entirely). A hostname is resolved via both IPv4 A records
 * (`gethostbynamel()`) and IPv6 AAAA records (`dns_get_record()`,
 * best-effort — a DNS hiccup here degrades to "no AAAA records found",
 * never a fatal, matching `$smtpHostIsPrivate()`'s own tolerance). ANY
 * resolved address landing in a private (RFC 1918 / RFC 4193) or reserved
 * (loopback, link-local — which is what covers the 169.254.169.254
 * cloud-metadata address — multicast, …) range makes this return true.
 *
 * An UNRESOLVABLE host (a typo, a DNS outage) returns false — "not
 * private" — deliberately: this function only ever TIGHTENS a host that a
 * caller's own scheme/shape checks already let through; it is not the only
 * gate, so it fails toward "let the real HTTP attempt fail on its own
 * terms" rather than masking an unrelated typo as a security refusal.
 *
 * @param string $host Hostname or literal IP. Case doesn't matter —
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
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;                              /* host is already a literal IP */
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
