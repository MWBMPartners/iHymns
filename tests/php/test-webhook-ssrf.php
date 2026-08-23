<?php

declare(strict_types=1);

/**
 * iHymns — Webhook SSRF public-IP truth table (#1909)
 *
 * ELI5: proves the ONE gate that decides whether a webhook is allowed to be sent
 * to a given IP address only ever says "yes" to a real public-internet address —
 * and "no" to anything inside our own network, the cloud metadata service, or a
 * private address disguised as an IPv6.
 *
 * DETAIL:
 * `includes/webhooks.php::webhookIpIsPublic()` is a PURE function (no DB, no
 * network) — a webhook target is ATTACKER-CONTROLLED, so this is the load-bearing
 * SSRF defence (design §6.5.2). This suite is a full truth table over every
 * range the design enumerates, INCLUDING IPv4-mapped IPv6 (::ffff:10.0.0.1) which
 * must be judged as the private v4 it really is. Mutation-proven: deleting any
 * single range check in _webhookIpv4IsPublic()/webhookIpIsPublic() flips at
 * least one assertion here to FAIL.
 *
 *   php tests/php/test-webhook-ssrf.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 * @link https://datatracker.ietf.org/doc/html/rfc1918
 * @link https://datatracker.ietf.org/doc/html/rfc6598 (CGNAT 100.64/10)
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $failures++; }
}

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/webhooks.php';

echo "1 — public addresses ARE public (allowed to dial)\n";
foreach (['8.8.8.8', '1.1.1.1', '13.107.42.14', '93.184.216.34',
          '2606:2800:220:1:248:1893:25c8:1946', '2001:4860:4860::8888'] as $ip) {
    check("public: $ip", webhookIpIsPublic($ip) === true);
}

echo "\n2 — IPv4 private / reserved ranges are REFUSED\n";
$privateV4 = [
    '0.0.0.0'          => '0.0.0.0/8',
    '0.1.2.3'          => '0/8 interior',
    '10.0.0.1'         => '10/8 RFC1918',
    '10.255.255.255'   => '10/8 top',
    '100.64.0.1'       => '100.64/10 CGNAT low',
    '100.127.255.254'  => '100.64/10 CGNAT high',
    '127.0.0.1'        => '127/8 loopback',
    '169.254.169.254'  => '169.254/16 cloud metadata',
    '169.254.0.1'      => '169.254/16 link-local',
    '172.16.0.1'       => '172.16/12 low',
    '172.31.255.254'   => '172.16/12 high',
    '192.168.1.1'      => '192.168/16 RFC1918',
    '224.0.0.1'        => '224/4 multicast',
    '239.255.255.255'  => '224/4 multicast top',
    '240.0.0.1'        => '240/4 reserved',
    '255.255.255.255'  => '240/4 broadcast',
];
foreach ($privateV4 as $ip => $why) {
    check("refuse $ip ($why)", webhookIpIsPublic($ip) === false);
}

echo "\n3 — 100.63.x and 100.128.x are OUTSIDE CGNAT (public — proves the /10 bound is exact)\n";
check('100.63.0.1 is public (below 100.64/10)', webhookIpIsPublic('100.63.0.1') === true);
check('100.128.0.1 is public (above 100.64/10)', webhookIpIsPublic('100.128.0.1') === true);
check('172.15.0.1 is public (below 172.16/12)', webhookIpIsPublic('172.15.0.1') === true);
check('172.32.0.1 is public (above 172.16/12)', webhookIpIsPublic('172.32.0.1') === true);

echo "\n4 — IPv6 special ranges are REFUSED\n";
$privateV6 = [
    '::'                  => 'unspecified',
    '::1'                 => 'loopback',
    'fe80::1'             => 'fe80::/10 link-local',
    'febf::1'             => 'fe80::/10 top',
    'fc00::1'             => 'fc00::/7 ULA',
    'fdff::1'             => 'fc00::/7 ULA top',
    'ff02::1'             => 'ff00::/8 multicast',
];
foreach ($privateV6 as $ip => $why) {
    check("refuse $ip ($why)", webhookIpIsPublic($ip) === false);
}

echo "\n5 — IPv4-mapped IPv6 is judged as the embedded v4 (the disguise attack)\n";
check('::ffff:10.0.0.1 refused (private v4 in v6 clothing)', webhookIpIsPublic('::ffff:10.0.0.1') === false);
check('::ffff:169.254.169.254 refused (metadata in v6 clothing)', webhookIpIsPublic('::ffff:169.254.169.254') === false);
check('::ffff:127.0.0.1 refused (loopback in v6 clothing)', webhookIpIsPublic('::ffff:127.0.0.1') === false);
check('::ffff:8.8.8.8 is public (public v4 in v6 clothing)', webhookIpIsPublic('::ffff:8.8.8.8') === true);

echo "\n6 — non-IP garbage never reads as public\n";
foreach (['', 'not-an-ip', '999.1.1.1', 'example.com', '10.0.0.1/8', ' 8.8.8.8 '] as $junk) {
    check("garbage refused: '" . $junk . "'", webhookIpIsPublic($junk) === false);
}

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} SSRF truth-table assertion(s) failed.\n");
    exit(1);
}
echo "All webhook-ssrf assertions passed.\n";
exit(0);
