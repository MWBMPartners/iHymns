<?php

declare(strict_types=1);

/**
 * iHymns — Partner-event outbound webhooks: delivery engine (#1909)
 *
 * ELI5: this file does the actual work of sending webhooks — it records an event
 * in the durable outbox, works out which partners want it, signs each POST,
 * dials the partner's URL SAFELY (an attacker-controlled URL must never let us
 * reach our own network), and retries on failure until it either lands or is
 * declared dead. Everything here is best-effort at the emit boundary (a webhook
 * hiccup must NEVER fail a song save) and durable-at-least-once from the ledger
 * onward.
 *
 * DETAIL:
 * -------
 * The vocabulary + payload shapes live in the sibling includes/webhook_events.php
 * (pure, side-effect-free — rule #22); THIS file is the machinery: gates, emit +
 * fan-out, HMAC signing, the SSRF-hardened dialer, the claim/attempt/backoff
 * drain pass and the retention prune. Side-effect-free to require (functions +
 * constants only).
 *
 * DORMANCY (design §9): four independently-sufficient gates. (1) the
 * webhooks_enabled_channels setting (CSV of alpha|beta|production, default empty
 * = fully dormant); (2) memoized INFORMATION_SCHEMA table probes (an un-migrated
 * docroot no-ops, never 500s — the 3 docroots share ONE MySQL, migrations are
 * web-run, mysqli is STRICT); (3) a zero-active-subscription short-circuit; (4)
 * fail-open everywhere — every emit/drain/prune body is try/catch → error_log,
 * so webhooks can only ever lose a webhook, never break a save, a page, or a
 * drain caller. With the setting empty, webhookEmit() performs ZERO DB writes.
 *
 * SSRF (design §6.5): the house outbound clients (cuercode/intapps) bind to a
 * TRUSTED configured host; a webhook target is ATTACKER-CONTROLLED, so this adds
 * DNS pre-resolution, a private/reserved-range truth table applied to EVERY
 * resolved A/AAAA, an IP pin (CURLOPT_RESOLVE) defeating DNS rebinding, a
 * no-redirect / https-only / verify-peer curl band, and a self-host block.
 *
 * @see .claude/webhooks-1909-design.md (locked design — §5 emit, §6 delivery, §7 security)
 * @see includes/webhook_events.php (the vocabulary + payload builders this consumes)
 * @see includes/cuercode_client.php (the trusted-host outbound-client precedent hardened here)
 * @link https://www.php.net/manual/en/book.curl.php
 * @link https://stripe.com/docs/webhooks/signatures (the signature scheme partners expect)
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';        /* getDbMysqli() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';     /* getAppSetting() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'secret_crypto.php';   /* secretEncrypt()/secretDecrypt()/secretCryptoReady() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'environment.php';     /* ihymns_environment() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'webhook_events.php';  /* registry + payload builders */

/* =========================================================================
 * SETTINGS KEYS — defined ONCE (rule #35) so configuration.php / the drain
 * endpoint / status surfaces never re-type a literal and drift.
 * ========================================================================= */
const WEBHOOK_SETTING_ENABLED_CHANNELS = 'webhooks_enabled_channels'; /* CSV of alpha|beta|production; empty = dormant */
const WEBHOOK_SETTING_DRAIN_KEY        = 'webhook_drain_key';         /* tblAppSettings secret (secretSettingKeys) */
const WEBHOOK_SETTING_ALLOW_LOOPBACK   = 'webhook_allow_loopback';    /* '1' only on a local/test install */
const WEBHOOK_SETTING_LAST_DRAIN_AT    = 'webhook_last_drain_at';     /* UTC of the last drain pass — makes "cron not wired" VISIBLE */

/* =========================================================================
 * CODE CONSTANTS (rule #44: knobs the app doesn't need are code, not settings —
 * one fail-soft band for everyone, no misconfigurable bound).
 * ========================================================================= */
const WEBHOOK_SECRET_PREFIX      = 'whsec_';
const WEBHOOK_EVENT_UID_PREFIX   = 'evt_';
const WEBHOOK_USER_AGENT         = 'iHymns-Webhooks/1.0';

const WEBHOOK_CURL_CONNECT_TIMEOUT = 3;
const WEBHOOK_CURL_TIMEOUT         = 5;   /* a partner endpoint may be slower than CueRCode; the pass budget divides by this */
const WEBHOOK_MAX_RESPONSE_BYTES   = 65536;   /* 64 KiB — status + the small verification echo, nothing more */
const WEBHOOK_MAX_PAYLOAD_BYTES    = 262144;  /* 256 KiB emit-time cap — refuses an oversized payload build */

/* Retry schedule: seconds to wait AFTER attempt N before attempt N+1. 8 entries
   ⇒ 8 retries + the initial attempt ≈ 2.2 days of coverage (design §6.3).
   ±20 % jitter is applied at scheduling time so a dead endpoint's backlog does
   not thunder back in lock-step. */
const WEBHOOK_RETRY_SCHEDULE = [60, 300, 1800, 7200, 21600, 43200, 86400, 86400];

const WEBHOOK_RETENTION_DAYS         = 30;   /* event + delivery TTL */
const WEBHOOK_ROTATION_GRACE_HOURS   = 24;   /* dual-signing window after a secret rotation */
const WEBHOOK_CLAIM_LEASE_SECS       = 600;  /* a 'delivering' row older than this is reclaimable (crashed worker) */
const WEBHOOK_EMIT_PER_REQUEST_CAP   = 200;  /* a runaway loop cannot flood the ledger (mirrors IHYMNS_LOG_PER_REQUEST_CAP) */
const WEBHOOK_PER_HOST_SUB_CAP       = 5;    /* max active subscriptions per TargetHost per channel */
const WEBHOOK_AUTODISABLE_FAILURES   = 20;   /* consecutive dead deliveries before auto-disable */
const WEBHOOK_AUTODISABLE_DAYS       = 3;    /* …AND failing at least this long */

const WEBHOOK_DRAIN_MAX_DEFAULT      = 25;    /* deliveries per cron drain pass */
const WEBHOOK_DRAIN_BUDGET_MS_DEFAULT = 20000; /* wall-clock budget per cron drain pass */
const WEBHOOK_PRUNE_MAX_DEFAULT      = 200;   /* expired rows per prune pass (bounded, service_mode precedent) */

/* Canonical public hosts — the whitelist mirrors sitemap.xml.php (#526); used
   both for the payload `url` base and the SSRF self-host block. */
const WEBHOOK_CANONICAL_HOSTS = ['ihymns.app', 'www.ihymns.app', 'dev.ihymns.app', 'beta.ihymns.app'];

/* =========================================================================
 * GATES
 * ========================================================================= */

/**
 * ELI5: which channels have webhooks switched on?
 * WHY: a CSV allow-list, not a boolean — tblAppSettings is shared by all three
 * docroots (the INTAPPS_SETTING_ENABLED_CHANNELS precedent), so an alpha-only
 * soak is expressible. Default empty ⇒ fully dormant.
 *
 * @return array<int,string> subset of {alpha, beta, production}
 */
function webhookEnabledChannels(): array
{
    $raw = (string)(getAppSetting(WEBHOOK_SETTING_ENABLED_CHANNELS, '') ?? '');
    $out = [];
    foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
        $c = strtolower(trim($c));
        if (in_array($c, ['alpha', 'beta', 'production'], true)) {
            $out[] = $c;
        }
    }
    return array_values(array_unique($out));
}

/**
 * ELI5: are webhooks live on THIS docroot right now?
 * WHY: gate #1. Memoized per request (the setting can't change mid-request).
 */
function webhooksEnabled(): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    return $on = in_array(ihymns_environment(), webhookEnabledChannels(), true);
}

/**
 * ELI5: do the three webhook tables actually exist on this install?
 * WHY: gate #2 — an un-migrated docroot no-ops cleanly (mysqli is STRICT, so a
 * missing table would otherwise throw). Memoized (the _apiKeyTableExists()
 * pattern).
 */
function webhookSchemaReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('tblWebhookSubscriptions','tblWebhookEvents','tblWebhookDeliveries')"
        );
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $ready = ($n === 3);
    } catch (\Throwable $e) {
        return $ready = false;
    }
}

/**
 * ELI5: are there ANY active subscriptions on this channel?
 * WHY: gate #3 — the zero-subscription short-circuit. Memoized indexed COUNT on
 * (Channel, Status); the hot-path cost when idle is one count per request at
 * most, zero once memoized.
 */
function webhookHasActiveSubscriptions(\mysqli $db): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $chan = ihymns_environment();
        $stmt = $db->prepare(
            "SELECT 1 FROM tblWebhookSubscriptions
              WHERE Channel = ? AND Status = 'active' LIMIT 1"
        );
        $stmt->bind_param('s', $chan);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $has = $exists;
    } catch (\Throwable $e) {
        return $has = false;
    }
}

/** ELI5: is the local-only http:// carve-out on? WHY: reach a test stub receiver. */
function webhookAllowLoopback(): bool
{
    return (string)(getAppSetting(WEBHOOK_SETTING_ALLOW_LOOPBACK, '0') ?? '0') === '1';
}

/* =========================================================================
 * SECRET CUSTODY + TOKEN MINTING
 * ========================================================================= */

/** ELI5: a fresh 192-bit signing secret. WHY: server-minted only — a chosen
 *  secret enables cross-service signature-oracle games (design §7.2). */
function webhookMintSecret(): string
{
    return WEBHOOK_SECRET_PREFIX . bin2hex(random_bytes(24));
}

/** ELI5: a globally-unique event id (the receiver's dedupe key). */
function webhookMintEventUid(): string
{
    return WEBHOOK_EVENT_UID_PREFIX . bin2hex(random_bytes(16));
}

/** ELI5: a drain-pass lease token (CHAR(32)). */
function webhookMintClaimToken(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * ELI5: turn a plaintext secret into the exact bytes to store in the Secret
 * column.
 * WHY: the appSettingValueForStorage() LADDER LOGIC (design §7.2) — the Secret
 * is a TABLE column, not a tblAppSettings row, so secretSettingKeys() does not
 * govern it; we apply the same fail-closed reasoning directly. When
 * secret_encryption_active='1', encrypt or REFUSE (never silently store
 * plaintext on an active-encryption install); otherwise the plaintext bridge.
 *
 * @throws \RuntimeException when a secret must be encrypted and cannot be.
 */
function webhookSecretForStorage(string $plaintext): string
{
    if ($plaintext === '') {
        return $plaintext;
    }
    $active = (string)(getAppSetting('secret_encryption_active', '0') ?? '0') === '1';
    if (!$active) {
        return $plaintext; /* plaintext bridge (secretDecrypt reads both) */
    }
    if (!secretCryptoReady()) {
        throw new \RuntimeException(
            'Secret encryption is active but the master key is unavailable on this server '
            . '— refusing to store a webhook signing secret unencrypted.'
        );
    }
    return secretEncrypt($plaintext);
}

/** ELI5: read a stored secret back to clear text to sign with (handles both the
 *  enc:v1 envelope and the plaintext bridge). */
function webhookSecretReveal(?string $stored): ?string
{
    return secretDecrypt($stored);
}

/* =========================================================================
 * SIGNING
 * ========================================================================= */

/**
 * ELI5: sign a webhook body so the receiver can prove it really came from us.
 * WHY: Stripe's scheme VERBATIM (`hash_hmac('sha256', "$ts.$rawBody", secret)`),
 * because partner ecosystems already have verifiers for it. The timestamp inside
 * the signed string is the replay defence (the receiver rejects |now - t| > 300s).
 *
 * ⚠️ This DELIBERATELY differs from intappsSign()'s `body.ts` order and is NOT a
 * fork of it: there WE consume the gateway's contract; here WE issue the
 * contract. Do not "unify the two" — it would break every partner verifier.
 *
 * @param string $rawBody Exact bytes of the POST body (the frozen envelope JSON).
 * @param int    $ts      Unix seconds (the value echoed in the X-iHymns-Signature header).
 * @param string $secret  The subscription's plaintext signing secret.
 * @return string Lowercase hex HMAC-SHA256.
 */
function webhookSign(string $rawBody, int $ts, string $secret): string
{
    return hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
}

/**
 * ELI5: build the full signature header value, including a second signature under
 * the previous secret while a rotation grace window is open.
 * WHY: `t=<ts>,v1=<hmac>[,v1=<hmac prev>]` — receivers accept if ANY v1 matches,
 * so a rotation never needs a hard partner cutover (design §7.1).
 *
 * @param string      $rawBody       The POST body.
 * @param int         $ts            Unix seconds.
 * @param string      $secret        Current plaintext secret.
 * @param string|null $prevSecret    Previous plaintext secret (or null).
 * @param bool        $prevActive    Whether the rotation grace window is still open.
 */
function webhookSignatureHeader(string $rawBody, int $ts, string $secret, ?string $prevSecret, bool $prevActive): string
{
    $h = 't=' . $ts . ',v1=' . webhookSign($rawBody, $ts, $secret);
    if ($prevActive && $prevSecret !== null && $prevSecret !== '') {
        $h .= ',v1=' . webhookSign($rawBody, $ts, $prevSecret);
    }
    return $h;
}

/* =========================================================================
 * SSRF-HARDENED DIALER
 * ========================================================================= */

/**
 * ELI5: is this IP one we are allowed to send a webhook to (i.e. a real public
 * internet address, not something inside our own network or the cloud metadata
 * service)?
 * WHY: the attacker-controlled-target defence as a PURE truth table (design
 * §6.5.2), mutation-proven by tests/php/test-webhook-ssrf.php. Rejects loopback,
 * RFC1918, link-local + cloud metadata, CGNAT, ULA, multicast/reserved, and
 * IPv4-mapped IPv6 forms (checked AFTER normalisation via inet_pton so
 * ::ffff:10.0.0.1 cannot slip a private v4 through in v6 clothing).
 *
 * @param string $ip A textual IP (v4 or v6).
 * @return bool true only if the address is a routable public one.
 */
function webhookIpIsPublic(string $ip): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false; /* not a parseable IP ⇒ never dial it */
    }
    $len = strlen($packed);
    $b   = array_values(unpack('C*', $packed)); /* 1-indexed → 0-indexed byte array */

    if ($len === 4) {
        return _webhookIpv4IsPublic($b[0], $b[1], $b[2], $b[3]);
    }

    if ($len === 16) {
        /* IPv4-mapped (::ffff:a.b.c.d) — bytes 0..9 zero, 10..11 = 0xff — the
           address is really the embedded v4, so judge it as v4. */
        $mapped = true;
        for ($i = 0; $i < 10; $i++) {
            if ($b[$i] !== 0) { $mapped = false; break; }
        }
        if ($mapped && $b[10] === 0xff && $b[11] === 0xff) {
            return _webhookIpv4IsPublic($b[12], $b[13], $b[14], $b[15]);
        }
        /* Unspecified :: */
        $allZero = true;
        for ($i = 0; $i < 16; $i++) {
            if ($b[$i] !== 0) { $allZero = false; break; }
        }
        if ($allZero) {
            return false;
        }
        /* ::1 loopback */
        $loop = true;
        for ($i = 0; $i < 15; $i++) {
            if ($b[$i] !== 0) { $loop = false; break; }
        }
        if ($loop && $b[15] === 1) {
            return false;
        }
        if ($b[0] === 0xff) {                       return false; } /* ff00::/8 multicast */
        if ($b[0] === 0xfe && ($b[1] & 0xc0) === 0x80) { return false; } /* fe80::/10 link-local */
        if (($b[0] & 0xfe) === 0xfc) {              return false; } /* fc00::/7 ULA */
        return true;
    }

    return false; /* unknown address family */
}

/** @internal The IPv4 half of webhookIpIsPublic() — also used for v4-mapped v6. */
function _webhookIpv4IsPublic(int $b0, int $b1, int $b2, int $b3): bool
{
    if ($b0 === 0)   { return false; }                       /* 0.0.0.0/8 */
    if ($b0 === 10)  { return false; }                       /* 10.0.0.0/8 RFC1918 */
    if ($b0 === 100 && $b1 >= 64 && $b1 <= 127) { return false; } /* 100.64.0.0/10 CGNAT */
    if ($b0 === 127) { return false; }                       /* 127.0.0.0/8 loopback */
    if ($b0 === 169 && $b1 === 254) { return false; }        /* 169.254.0.0/16 link-local + cloud metadata */
    if ($b0 === 172 && $b1 >= 16 && $b1 <= 31) { return false; }  /* 172.16.0.0/12 RFC1918 */
    if ($b0 === 192 && $b1 === 168) { return false; }        /* 192.168.0.0/16 RFC1918 */
    if ($b0 >= 224 && $b0 <= 239) { return false; }          /* 224.0.0.0/4 multicast */
    if ($b0 >= 240) { return false; }                        /* 240.0.0.0/4 reserved (incl. 255.255.255.255) */
    return true;
}

/**
 * ELI5: is this host one of our OWN sites? WHY: refuse to loop an event back
 * through our own API (design §6.5.5 / abuse A.16).
 */
function webhookIsSelfHost(string $host): bool
{
    $host = strtolower($host);
    if (in_array($host, WEBHOOK_CANONICAL_HOSTS, true)) {
        return true;
    }
    $self = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $self !== '' && $host === $self;
}

/**
 * ELI5: look up every IP a hostname points at right now.
 * WHY: we validate ALL of them (a name that resolves to one public and one
 * private address is refused) and pin the connection to a validated one.
 *
 * @return array<int,string> resolved IPs (may be empty)
 */
function _webhookResolveTargetIps(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host]; /* already a literal IP */
    }
    $ips = [];
    $a = @gethostbynamel($host);           /* IPv4 A records (false on failure) */
    if (is_array($a)) {
        $ips = array_merge($ips, $a);
    }
    if (function_exists('dns_get_record')) {
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = (string)$rec['ipv6'];
                }
            }
        }
    }
    return array_values(array_unique($ips));
}

/**
 * ELI5: POST a webhook body to a partner URL, SAFELY, and report what happened.
 * WHY: the ONE outbound dialer (design §6.5). Every failure is a return value,
 * never a throw — the caller records it as an attempt.
 *
 * @param string $url     Target URL (already validated at save; re-validated here at DIAL time).
 * @param string $body    The exact POST body.
 * @param array  $headers Request headers (Content-Type, User-Agent, signature, delivery metadata).
 * @return array{status:int,ms:int,err:string,body:string}
 *         status 0 = transport error; body is the (≤64 KiB) response for the verify echo.
 */
function webhookHttpPost(string $url, string $body, array $headers): array
{
    $result = ['status' => 0, 'ms' => 0, 'err' => '', 'body' => ''];
    $t0 = microtime(true);
    $allowLoopback = webhookAllowLoopback();

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        $result['err'] = 'bad_url';
        return $result;
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host   = strtolower((string)$parts['host']);
    $isLoopbackHost = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    $port   = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);

    /* Scheme + port policy: https:443 only, unless the loopback test carve-out. */
    if ($scheme === 'https') {
        if ($port !== 443) {
            $result['err'] = 'port_refused';
            return $result;
        }
    } elseif ($scheme === 'http' && $allowLoopback && $isLoopbackHost) {
        /* local/test carve-out — any port for the stub receiver */
    } else {
        $result['err'] = 'scheme_refused';
        return $result;
    }

    if (webhookIsSelfHost($host)) {
        $result['err'] = 'self_host';
        return $result;
    }

    /* DNS pre-resolve + validate EVERY address (design §6.5.2), then pin. The
       loopback carve-out is the one case that skips the public-IP check. */
    $ips   = _webhookResolveTargetIps($host);
    if (!$ips) {
        $result['err'] = 'dns_fail';
        return $result;
    }
    $pinIp = null;
    foreach ($ips as $ip) {
        if ($allowLoopback && $isLoopbackHost) {
            $pinIp = $pinIp ?? $ip;
            continue;
        }
        if (!webhookIpIsPublic($ip)) {
            $result['err'] = 'private_ip';
            return $result; /* ANY resolved private address refuses the whole dial */
        }
        $pinIp = $pinIp ?? $ip;
    }
    if ($pinIp === null) {
        $result['err'] = 'no_ip';
        return $result;
    }

    if (!function_exists('curl_init')) {
        $result['err'] = 'no_curl';
        return $result;
    }
    $ch = curl_init();
    if ($ch === false) {
        $result['err'] = 'curl_init';
        return $result;
    }
    $buf = '';
    $cap = WEBHOOK_MAX_RESPONSE_BYTES;
    $writeFn = static function ($handle, string $chunk) use (&$buf, $cap): int {
        $buf .= $chunk;
        if (strlen($buf) > $cap) {
            return -1; /* abort — never buffer an oversized body */
        }
        return strlen($chunk);
    };
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . $pinIp], /* pin the validated IP — defeats DNS rebinding */
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => WEBHOOK_USER_AGENT,
        CURLOPT_CONNECTTIMEOUT => WEBHOOK_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => WEBHOOK_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,   /* host-based, so pinning the IP does NOT weaken verification */
        CURLOPT_FOLLOWLOCATION => false, /* a 3xx is a delivery failure — closes the redirect-to-internal bypass */
        CURLOPT_PROTOCOLS      => $scheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        CURLOPT_WRITEFUNCTION  => $writeFn,
    ]);
    curl_exec($ch);
    $errno  = curl_errno($ch);
    $errstr = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result['ms']     = (int)round((microtime(true) - $t0) * 1000);
    $result['status'] = $status;
    $result['body']   = $buf;
    if ($errno !== 0) {
        /* Sanitised class only — never a response body, never a secret. */
        $result['err'] = 'curl_' . $errno . ($errstr !== '' ? ': ' . substr($errstr, 0, 120) : '');
    }
    return $result;
}

/* =========================================================================
 * RETRY / BACKOFF
 * ========================================================================= */

/**
 * ELI5: how many seconds until the next retry, given how many attempts we've
 * already made — or null if we've run out of retries (the delivery is dead).
 * WHY: PURE schedule lookup (jitter applied by the caller), mutation-proven by
 * tests/php/test-webhook-backoff.php. AttemptCount is attempts MADE; after the
 * Nth attempt the delay is schedule[N-1]; past the schedule length ⇒ dead.
 *
 * @param int $attemptCount Attempts made so far (>= 1 after the first attempt).
 * @return int|null Base seconds to wait, or null ⇒ dead-letter.
 */
function webhookNextDelaySeconds(int $attemptCount): ?int
{
    $idx = $attemptCount - 1;
    if ($idx < 0 || $idx >= count(WEBHOOK_RETRY_SCHEDULE)) {
        return null;
    }
    return WEBHOOK_RETRY_SCHEDULE[$idx];
}

/** @internal Apply ±20 % jitter to a base delay (uses randomness — not pure). */
function _webhookJitter(int $seconds): int
{
    $delta = (int)round($seconds * 0.2);
    if ($delta <= 0) {
        return $seconds;
    }
    return max(1, $seconds + random_int(-$delta, $delta));
}

/* =========================================================================
 * PAYLOAD URL BASE
 * ========================================================================= */

/**
 * ELI5: the canonical public origin used to build permalink `url` fields in
 * payloads (e.g. https://ihymns.app).
 * WHY: mirrors sitemap.xml.php's host whitelist — a poisoned Host header can
 * never leak into a frozen payload URL. The current request host is used only
 * when it is one of the four canonical channels; otherwise production.
 */
function webhookCanonicalBaseUrl(): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (in_array($host, WEBHOOK_CANONICAL_HOSTS, true)) {
        return 'https://' . $host;
    }
    return 'https://ihymns.app';
}

/* =========================================================================
 * EMIT + FAN-OUT
 * ========================================================================= */

/**
 * ELI5: record that something happened and queue it for delivery to every
 * partner who asked for it.
 * WHY: the ONE emitter (design §5.1). BEST-EFFORT, NEVER BLOCKS, NEVER THROWS —
 * the whole body is wrapped in try/catch → error_log, exactly the logActivity()
 * posture, because a webhook hiccup must never fail a song save. From the ledger
 * onward, delivery is durable at-least-once.
 *
 * Gated: gate closed / tables absent / zero active subscriptions ⇒ return before
 * any insert (a verified byte-identical no-op while dormant). Per-request emit
 * cap prevents a runaway loop flooding the ledger.
 *
 * @param string $type  A known event type (validated against the registry).
 * @param array  $facts Raw facts for webhookBuildPayload() (see design §3.3).
 * @param array  $opts  { source?:string, source_ref?:string, actor_user_id?:int,
 *                        entity_id?:string }
 */
function webhookEmit(string $type, array $facts, array $opts = []): void
{
    static $emitted = 0;

    try {
        if (!webhooksEnabled()) {
            return;
        }
        if ($emitted >= WEBHOOK_EMIT_PER_REQUEST_CAP) {
            if ($emitted === WEBHOOK_EMIT_PER_REQUEST_CAP) {
                error_log('[webhooks] per-request emit cap reached (' . WEBHOOK_EMIT_PER_REQUEST_CAP . ') — suppressing further events this request');
                $emitted++;
            }
            return;
        }
        if (!webhookEventTypeValid($type)) {
            error_log('[webhooks] unknown event type refused: ' . $type);
            return;
        }
        $db = getDbMysqli();
        if (!webhookSchemaReady($db) || !webhookHasActiveSubscriptions($db)) {
            return; /* dormant / un-migrated / nobody subscribed ⇒ nothing to do */
        }

        $chan       = ihymns_environment();
        $eventUid   = webhookMintEventUid();
        $occurredAt = gmdate('Y-m-d\TH:i:s\Z');
        $facts['base_url'] = $facts['base_url'] ?? webhookCanonicalBaseUrl();

        $data     = webhookBuildPayload($type, $facts);
        $envelope = webhookBuildEnvelope($type, $eventUid, $chan, $occurredAt, $data);
        $payload  = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false || strlen($payload) > WEBHOOK_MAX_PAYLOAD_BYTES) {
            error_log('[webhooks] payload build failed or oversized for ' . $type);
            return;
        }

        [$label, $entityType] = IHYMNS_WEBHOOK_EVENTS[$type];
        $entityId  = (string)($opts['entity_id'] ?? '');
        $source    = (string)($opts['source'] ?? '');
        $sourceRef = isset($opts['source_ref']) && $opts['source_ref'] !== '' ? (string)$opts['source_ref'] : null;
        $actorId   = isset($opts['actor_user_id']) && $opts['actor_user_id'] !== null ? (int)$opts['actor_user_id'] : null;
        $occDt     = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + WEBHOOK_RETENTION_DAYS * 86400);

        /* Insert the frozen ledger row. (Source,SourceRef) UNIQUE makes a
           re-runnable funnel idempotent — a duplicate SourceRef throws, caught
           below as "already emitted" (design §5.2 / rule #20). */
        try {
            $stmt = $db->prepare(
                "INSERT INTO tblWebhookEvents
                    (EventUid, Channel, EventType, EntityType, EntityId, PayloadJson,
                     OccurredAt, ActorUserId, Source, SourceRef, ExpiresAt)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param(
                'ssssssissss',
                $eventUid, $chan, $type, $entityType, $entityId, $payload,
                $occDt, $actorId, $source, $sourceRef, $expiresAt
            );
            $stmt->execute();
            $eventId = (int)$db->insert_id;
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            if ($db->errno === 1062) { /* duplicate key — already emitted (idempotent) */
                return;
            }
            throw $e;
        }

        $emitted++;
        webhookFanOut($db, $eventId, $chan, $type, $expiresAt);
    } catch (\Throwable $e) {
        error_log('[webhooks] emit failed for ' . $type . ': ' . $e->getMessage());
    }
}

/**
 * ELI5: create one delivery row for every active subscription on this channel
 * whose selector matches this event type.
 * WHY: fan-out (design §5.1). uq_Event_Subscription makes it idempotent — a
 * re-run INSERT-IGNOREs into existing rows.
 */
function webhookFanOut(\mysqli $db, int $eventId, string $chan, string $type, string $expiresAt): void
{
    $now = gmdate('Y-m-d H:i:s');
    $res = $db->prepare(
        "SELECT Id, Events FROM tblWebhookSubscriptions
          WHERE Channel = ? AND Status = 'active'"
    );
    $res->bind_param('s', $chan);
    $res->execute();
    $subs = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    $res->close();

    $ins = $db->prepare(
        "INSERT INTO tblWebhookDeliveries
            (EventId, SubscriptionId, Channel, Status, AttemptCount, NextAttemptAt, ExpiresAt)
         VALUES (?,?,?, 'pending', 0, ?, ?)
         ON DUPLICATE KEY UPDATE Id = Id"
    );
    foreach ($subs as $sub) {
        if (!webhookEventMatches((string)$sub['Events'], $type)) {
            continue;
        }
        $subId = (int)$sub['Id'];
        $ins->bind_param('iisss', $eventId, $subId, $chan, $now, $expiresAt);
        $ins->execute();
    }
    $ins->close();
}

/**
 * ELI5: emit a song.* event, filling in the song's title / songbook / stable
 * public id from the database so every song funnel is a genuine one-liner.
 * WHY: the ONE song-fact gatherer (rule #22) — a caller passes the type + id (and
 * anything it already has, e.g. changed_fields), and this resolves the rest. It
 * SHORT-CIRCUITS on the dormancy gates BEFORE any DB read, so a save on an
 * install with webhooks off (or no active subscription) pays nothing. public_id
 * is column-gated (null on a pre-#1343 install), never a throw under STRICT.
 *
 * @param string $type  song.created | song.updated | song.deleted | song.restored
 * @param string $songId The SongId (e.g. MP-1008).
 * @param array  $extra Facts already known to the caller (title, songbook_abbr,
 *                       changed_fields) — used verbatim, not re-fetched.
 * @param array  $opts  webhookEmit() opts (source, source_ref, actor_user_id).
 */
function webhookEmitSongEvent(\mysqli $db, string $type, string $songId, array $extra = [], array $opts = []): void
{
    try {
        if (!webhooksEnabled() || !webhookSchemaReady($db) || !webhookHasActiveSubscriptions($db)) {
            return; /* dormant / nobody subscribed — before any DB read */
        }
        $facts = ['song_id' => $songId] + $extra;

        /* @deleted-visible: @disabled-visible: webhook emission reads the specific
           song by its SongId to build an INTERNAL partner notification — including
           a song.deleted / song.restored event ABOUT a just-soft-deleted row, and a
           song in a disabled/unofficial book. It must therefore NOT apply the
           catalogue visibility (#1694) or the disabled-songbook (#1765) predicate:
           that would hide the very row the event is announcing. This is a
           notification, never a public catalogue read (#1909). */
        if (!isset($facts['title']) || !isset($facts['songbook_abbr'])) {
            $stmt = $db->prepare("SELECT Title, SongbookAbbr FROM tblSongs WHERE SongId = ? LIMIT 1");
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $facts['title']         = $facts['title']         ?? (string)$row['Title'];
                $facts['songbook_abbr'] = $facts['songbook_abbr'] ?? (string)$row['SongbookAbbr'];
            }
        }
        if (!isset($facts['public_id']) && function_exists('songPublicId_columnReady') && songPublicId_columnReady($db)) {
            $p = $db->prepare("SELECT PublicId FROM tblSongs WHERE SongId = ? LIMIT 1");
            $p->bind_param('s', $songId);
            $p->execute();
            $pr = $p->get_result()->fetch_assoc();
            $p->close();
            if ($pr && $pr['PublicId'] !== null && $pr['PublicId'] !== '') {
                $facts['public_id'] = (string)$pr['PublicId'];
            }
        }
        $opts['entity_id'] = $opts['entity_id'] ?? $songId;
        webhookEmit($type, $facts, $opts);
    } catch (\Throwable $e) {
        error_log('[webhooks] song-event helper failed for ' . $songId . ': ' . $e->getMessage());
    }
}

/**
 * ELI5: emit a songbook.* event, filling in the songbook's name + official flag
 * from the database when the caller didn't already pass them.
 * WHY: the ONE songbook-fact gatherer (rule #22), sibling of
 * webhookEmitSongEvent(). SHORT-CIRCUITS on the dormancy gates before any DB
 * read. For songbook.deleted the row is already gone, so the caller passes what
 * it captured and no lookup is attempted.
 *
 * @param string $type  songbook.created | songbook.updated | songbook.deleted
 * @param string $abbr  The Abbreviation (the SongId prefix / entity id).
 * @param array  $extra title / is_official already known to the caller.
 * @param array  $opts  webhookEmit() opts.
 */
function webhookEmitSongbookEvent(\mysqli $db, string $type, string $abbr, array $extra = [], array $opts = []): void
{
    try {
        if (!webhooksEnabled() || !webhookSchemaReady($db) || !webhookHasActiveSubscriptions($db)) {
            return;
        }
        $facts = ['abbr' => $abbr] + $extra;
        /* @disabled-visible: webhook emission reads the specific songbook by its
           Abbreviation to build an INTERNAL partner notification — it must NOT
           filter out a disabled or unofficial (IsOfficial=0) book; a webhook about
           a book applies whatever its status. This is a notification, never a
           public catalogue read (#1765 / #1909). */
        if ($type !== 'songbook.deleted' && (!isset($facts['title']) || !isset($facts['is_official']))) {
            $stmt = $db->prepare("SELECT Name, IsOfficial FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1");
            $stmt->bind_param('s', $abbr);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $facts['title']       = $facts['title']       ?? (string)$row['Name'];
                $facts['is_official'] = $facts['is_official'] ?? (bool)$row['IsOfficial'];
            }
        }
        $opts['entity_id'] = $opts['entity_id'] ?? $abbr;
        webhookEmit($type, $facts, $opts);
    } catch (\Throwable $e) {
        error_log('[webhooks] songbook-event helper failed for ' . $abbr . ': ' . $e->getMessage());
    }
}

/* =========================================================================
 * DELIVERY — claim, attempt, drain pass, prune
 * ========================================================================= */

/**
 * ELI5: run one batch of pending/failed deliveries: pick a few that are due,
 * send each, and record what happened.
 * WHY: the drain worker core (design §6.2/§6.4). Called post-flush in an
 * emitting request AND by webhook-drain.php (cron). Claim is race-safe via an
 * atomic UPDATE + ClaimToken; a strict wall-clock budget stops it overrunning.
 *
 * @param \mysqli $db
 * @param array   $opts { max?:int, budget_ms?:int, prefer_event?:int }
 * @return array{claimed:int,sent:int,failed:int,dead:int}
 */
function webhookDrainPass(\mysqli $db, array $opts = []): array
{
    $out = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'dead' => 0];
    try {
        if (!webhookSchemaReady($db)) {
            return $out;
        }
        $chan     = ihymns_environment();
        $max      = max(1, (int)($opts['max'] ?? WEBHOOK_DRAIN_MAX_DEFAULT));
        $budgetMs = max(1000, (int)($opts['budget_ms'] ?? WEBHOOK_DRAIN_BUDGET_MS_DEFAULT));
        $t0       = microtime(true);

        /* Crashed-worker recovery: return stale 'delivering' rows to 'failed' so
           the claim below re-picks them. */
        $lease = WEBHOOK_CLAIM_LEASE_SECS;
        $rec = $db->prepare(
            "UPDATE tblWebhookDeliveries
                SET Status = 'failed'
              WHERE Channel = ? AND Status = 'delivering'
                AND ClaimedAt < (UTC_TIMESTAMP() - INTERVAL ? SECOND)"
        );
        $rec->bind_param('si', $chan, $lease);
        $rec->execute();
        $rec->close();

        $preferEvent = isset($opts['prefer_event']) ? (int)$opts['prefer_event'] : 0;
        $remaining   = $max;

        /* Optional: claim the emitting request's own event first (design §6.2). */
        if ($preferEvent > 0 && $remaining > 0) {
            $claimed = webhookClaimDue($db, $chan, $remaining, $preferEvent);
            foreach ($claimed as $row) {
                if ((microtime(true) - $t0) * 1000 >= $budgetMs) { break; }
                _webhookProcessClaimed($db, $row, $out);
                $remaining--;
            }
        }

        /* General claim for the rest of the budget. */
        while ($remaining > 0 && (microtime(true) - $t0) * 1000 < $budgetMs) {
            $claimed = webhookClaimDue($db, $chan, min($remaining, 5), 0);
            if (!$claimed) {
                break;
            }
            foreach ($claimed as $row) {
                if ((microtime(true) - $t0) * 1000 >= $budgetMs) { break 2; }
                _webhookProcessClaimed($db, $row, $out);
                $remaining--;
            }
        }
    } catch (\Throwable $e) {
        error_log('[webhooks] drain pass failed: ' . $e->getMessage());
    }
    return $out;
}

/**
 * ELI5: atomically grab up to $limit due deliveries so no other drain can touch
 * them.
 * WHY: the race-safe claim (design §6.3) — one UPDATE stamps a fresh ClaimToken
 * on the due rows, then we SELECT them back by that token. Two concurrent drains
 * can never double-send.
 *
 * @param int $onlyEvent 0 = any; else restrict to this event's deliveries.
 * @return array<int,array> claimed delivery rows joined to their subscription.
 */
function webhookClaimDue(\mysqli $db, string $chan, int $limit, int $onlyEvent): array
{
    $limit = max(1, $limit);
    $token = webhookMintClaimToken();

    if ($onlyEvent > 0) {
        $upd = $db->prepare(
            "UPDATE tblWebhookDeliveries
                SET Status = 'delivering', ClaimToken = ?, ClaimedAt = UTC_TIMESTAMP()
              WHERE Channel = ? AND EventId = ?
                AND Status IN ('pending','failed') AND NextAttemptAt <= UTC_TIMESTAMP()
              ORDER BY NextAttemptAt LIMIT ?"
        );
        $upd->bind_param('ssii', $token, $chan, $onlyEvent, $limit);
    } else {
        $upd = $db->prepare(
            "UPDATE tblWebhookDeliveries
                SET Status = 'delivering', ClaimToken = ?, ClaimedAt = UTC_TIMESTAMP()
              WHERE Channel = ?
                AND Status IN ('pending','failed') AND NextAttemptAt <= UTC_TIMESTAMP()
              ORDER BY NextAttemptAt LIMIT ?"
        );
        $upd->bind_param('ssi', $token, $chan, $limit);
    }
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();
    if ($affected <= 0) {
        return [];
    }

    /* Fetch the claimed rows joined to the subscription (secret + selectors). */
    $sel = $db->prepare(
        "SELECT d.Id, d.EventId, d.SubscriptionId, d.AttemptCount, d.AttemptLogJson,
                e.EventUid, e.EventType, e.PayloadJson,
                s.Secret, s.SecretPrevious, s.SecretPreviousExpiresAt, s.TargetUrl, s.Status AS SubStatus
           FROM tblWebhookDeliveries d
           JOIN tblWebhookEvents e         ON e.Id = d.EventId
           JOIN tblWebhookSubscriptions s  ON s.Id = d.SubscriptionId
          WHERE d.ClaimToken = ?"
    );
    $sel->bind_param('s', $token);
    $sel->execute();
    $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
    $sel->close();
    return $rows;
}

/** @internal Attempt one claimed delivery and update tallies + row state. */
function _webhookProcessClaimed(\mysqli $db, array $row, array &$out): void
{
    $out['claimed']++;
    $outcome = _webhookAttemptDelivery($db, $row);
    if ($outcome === 'succeeded') {
        $out['sent']++;
    } elseif ($outcome === 'dead') {
        $out['dead']++;
    } else {
        $out['failed']++;
    }
}

/**
 * ELI5: send ONE claimed delivery, then write down what happened and when to try
 * again.
 * WHY: the attempt state machine (design §6.3). A subscription that is no longer
 * active cancels the delivery; a 2xx succeeds and resets the subscription's
 * failure counters; anything else schedules a retry (or dead-letters when the
 * schedule is exhausted) and advances the auto-disable ladder.
 *
 * @return string 'succeeded' | 'failed' | 'dead' | 'cancelled'
 */
function _webhookAttemptDelivery(\mysqli $db, array $row): string
{
    $deliveryId = (int)$row['Id'];
    $subId      = (int)$row['SubscriptionId'];
    $subStatus  = (string)$row['SubStatus'];

    /* If the subscription is no longer active, cancel this queued delivery. */
    if ($subStatus !== 'active') {
        $stmt = $db->prepare("UPDATE tblWebhookDeliveries SET Status = 'cancelled', ClaimToken = NULL WHERE Id = ?");
        $stmt->bind_param('i', $deliveryId);
        $stmt->execute();
        $stmt->close();
        return 'cancelled';
    }

    $rawBody = (string)$row['PayloadJson'];
    $ts      = time();
    $secret  = (string)(webhookSecretReveal((string)$row['Secret']) ?? '');
    $prevRaw = $row['SecretPrevious'] !== null ? (string)$row['SecretPrevious'] : null;
    $prev    = $prevRaw !== null ? webhookSecretReveal($prevRaw) : null;
    $prevActive = false;
    if ($prev !== null && $prev !== '' && $row['SecretPreviousExpiresAt'] !== null) {
        $prevActive = strtotime((string)$row['SecretPreviousExpiresAt'] . ' UTC') > $ts;
    }
    $attemptNo = (int)$row['AttemptCount'] + 1;

    $headers = [
        'Content-Type: application/json; charset=UTF-8',
        'X-iHymns-Event-Id: ' . (string)$row['EventUid'],
        'X-iHymns-Event-Type: ' . (string)$row['EventType'],
        'X-iHymns-Delivery-Id: whd_' . $deliveryId,
        'X-iHymns-Attempt: ' . $attemptNo,
        'X-iHymns-Signature: ' . webhookSignatureHeader($rawBody, $ts, $secret, $prev, $prevActive),
    ];

    $resp     = webhookHttpPost((string)$row['TargetUrl'], $rawBody, $headers);
    $status   = (int)$resp['status'];
    $ok       = $status >= 200 && $status < 300;
    $nowDt    = gmdate('Y-m-d H:i:s');

    /* Bounded per-attempt history — capped by construction (design §4.3). */
    $log = [];
    if (!empty($row['AttemptLogJson'])) {
        $decoded = json_decode((string)$row['AttemptLogJson'], true);
        if (is_array($decoded)) {
            $log = $decoded;
        }
    }
    $log[] = [
        'at'     => $nowDt,
        'status' => $status,
        'ms'     => (int)$resp['ms'],
        'err'    => (string)$resp['err'],
    ];
    if (count($log) > count(WEBHOOK_RETRY_SCHEDULE) + 1) {
        $log = array_slice($log, -(count(WEBHOOK_RETRY_SCHEDULE) + 1));
    }
    $logJson = json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $lastErr = $ok ? null : substr(($resp['err'] !== '' ? $resp['err'] : ('http_' . $status)), 0, 500);

    if ($ok) {
        $stmt = $db->prepare(
            "UPDATE tblWebhookDeliveries
                SET Status = 'succeeded', AttemptCount = ?, LastHttpStatus = ?, LastError = NULL,
                    LastAttemptAt = ?, DeliveredAt = ?, AttemptLogJson = ?, ClaimToken = NULL
              WHERE Id = ?"
        );
        $stmt->bind_param('iisssi', $attemptNo, $status, $nowDt, $nowDt, $logJson, $deliveryId);
        $stmt->execute();
        $stmt->close();
        _webhookSubscriptionOnSuccess($db, $subId, $nowDt);
        return 'succeeded';
    }

    /* Failure — schedule a retry or dead-letter. */
    $delay = webhookNextDelaySeconds($attemptNo);
    if ($delay === null) {
        $stmt = $db->prepare(
            "UPDATE tblWebhookDeliveries
                SET Status = 'dead', AttemptCount = ?, LastHttpStatus = ?, LastError = ?,
                    LastAttemptAt = ?, AttemptLogJson = ?, ClaimToken = NULL
              WHERE Id = ?"
        );
        $stmt->bind_param('iisssi', $attemptNo, $status, $lastErr, $nowDt, $logJson, $deliveryId);
        $stmt->execute();
        $stmt->close();
        _webhookSubscriptionOnFailure($db, $subId, $nowDt);
        return 'dead';
    }

    $nextAt = gmdate('Y-m-d H:i:s', $ts + _webhookJitter($delay));
    $stmt = $db->prepare(
        "UPDATE tblWebhookDeliveries
            SET Status = 'failed', AttemptCount = ?, LastHttpStatus = ?, LastError = ?,
                LastAttemptAt = ?, NextAttemptAt = ?, AttemptLogJson = ?, ClaimToken = NULL
          WHERE Id = ?"
    );
    $stmt->bind_param('iissssi', $attemptNo, $status, $lastErr, $nowDt, $nextAt, $logJson, $deliveryId);
    $stmt->execute();
    $stmt->close();
    _webhookSubscriptionOnFailure($db, $subId, $nowDt);
    return 'failed';
}

/** @internal A success resets the subscription's consecutive-failure counters. */
function _webhookSubscriptionOnSuccess(\mysqli $db, int $subId, string $nowDt): void
{
    $stmt = $db->prepare(
        "UPDATE tblWebhookSubscriptions
            SET ConsecutiveFailures = 0, FailingSince = NULL,
                LastAttemptAt = ?, LastSuccessAt = ?
          WHERE Id = ?"
    );
    $stmt->bind_param('ssi', $nowDt, $nowDt, $subId);
    $stmt->execute();
    $stmt->close();
}

/**
 * @internal A failure bumps the counter, stamps FailingSince, and applies the
 * auto-disable ladder (design §6.3): >= WEBHOOK_AUTODISABLE_FAILURES in a row AND
 * failing for >= WEBHOOK_AUTODISABLE_DAYS ⇒ Status='disabled_failing' + its
 * pending/failed deliveries cancelled.
 */
function _webhookSubscriptionOnFailure(\mysqli $db, int $subId, string $nowDt): void
{
    $stmt = $db->prepare(
        "UPDATE tblWebhookSubscriptions
            SET ConsecutiveFailures = ConsecutiveFailures + 1,
                FailingSince = COALESCE(FailingSince, UTC_TIMESTAMP()),
                LastAttemptAt = ?
          WHERE Id = ?"
    );
    $stmt->bind_param('si', $nowDt, $subId);
    $stmt->execute();
    $stmt->close();

    $thresholdFailures = WEBHOOK_AUTODISABLE_FAILURES;
    $thresholdDays     = WEBHOOK_AUTODISABLE_DAYS;
    $dis = $db->prepare(
        "UPDATE tblWebhookSubscriptions
            SET Status = 'disabled_failing'
          WHERE Id = ? AND Status = 'active'
            AND ConsecutiveFailures >= ?
            AND FailingSince IS NOT NULL
            AND FailingSince < (UTC_TIMESTAMP() - INTERVAL ? DAY)"
    );
    $dis->bind_param('iii', $subId, $thresholdFailures, $thresholdDays);
    $dis->execute();
    $disabled = $dis->affected_rows;
    $dis->close();

    if ($disabled > 0) {
        $cancel = $db->prepare(
            "UPDATE tblWebhookDeliveries
                SET Status = 'cancelled', ClaimToken = NULL
              WHERE SubscriptionId = ? AND Status IN ('pending','failed')"
        );
        $cancel->bind_param('i', $subId);
        $cancel->execute();
        $cancel->close();
        error_log('[webhooks] subscription ' . $subId . ' auto-disabled after sustained failure');
    }
}

/**
 * ELI5: delete expired ledger + delivery rows so the tables never grow forever.
 * WHY: retention prune (design §6.4/A.13) — bounded per pass (the service_mode
 * cap rationale). Deliveries are CASCADE-linked, but we prune both explicitly so
 * a bounded pass makes steady progress on each.
 *
 * @return int rows removed this pass.
 */
function webhookPruneExpired(\mysqli $db, int $max = WEBHOOK_PRUNE_MAX_DEFAULT): int
{
    $removed = 0;
    try {
        if (!webhookSchemaReady($db)) {
            return 0;
        }
        $max = max(1, $max);

        $d = $db->prepare("DELETE FROM tblWebhookDeliveries WHERE ExpiresAt < UTC_TIMESTAMP() LIMIT ?");
        $d->bind_param('i', $max);
        $d->execute();
        $removed += $d->affected_rows;
        $d->close();

        $e = $db->prepare("DELETE FROM tblWebhookEvents WHERE ExpiresAt < UTC_TIMESTAMP() LIMIT ?");
        $e->bind_param('i', $max);
        $e->execute();
        $removed += $e->affected_rows;
        $e->close();
    } catch (\Throwable $ex) {
        error_log('[webhooks] prune failed: ' . $ex->getMessage());
    }
    return $removed;
}
