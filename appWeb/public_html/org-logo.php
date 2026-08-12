<?php

declare(strict_types=1);

/**
 * iHymns — Organisation logo image endpoint (#1830)
 *
 * PURPOSE:
 * The ONE public byte-stream source for an organisation's uploaded logo.
 * Every consumer — the admin card's preview, the print `logo` block, the
 * PDF resolver's data-URI inlining — points a plain `<img src="/org-logo.php
 * ?…">` here; nothing ever inlines the stored SVG/PNG bytes directly into a
 * page (that would defeat the whole point of sanitising it — see
 * `includes/svg_sanitizer.php`'s file doc-block). Mirrors the shape of
 * `qr.php` / `og-image.php` (a standalone top-level image-streaming file)
 * plus `song-media.php`'s schema-probe + conditional-GET pattern.
 *
 * REQUEST:
 *   GET /org-logo.php?org=<int>&kind=<one of IHYMNS_ORG_LOGO_KINDS's keys>
 *       [&variant=<default|light|dark>][&v=<hex>]
 *
 * OUTPUT:
 *   200/304  the logo bytes (or nothing, on a conditional-GET hit), with a
 *            restrictive CSP + `nosniff` + `Content-Disposition: inline`.
 *   400      a malformed org/kind/variant.
 *   404      no body — table not migrated yet, OR no active logo for this
 *            (org, kind[, variant]) — DELIBERATELY indistinguishable (a
 *            probe cannot tell "not migrated" from "no such logo"), and
 *            DELIBERATELY no placeholder image: a placeholder would render
 *            a broken grey box into a printed handout, whereas an absent
 *            `<img>` renders as absence (the caller's `onerror` hides it).
 *   503      the database is unreachable.
 *
 * ACCESS: anonymous, like `qr.php`/`og-image.php` — a logo is public
 * branding an org WANTS shown on its printed handouts; there is nothing to
 * gate (§12(d) of the plan).
 *
 * NEVER reads `ContentOriginal` — `orgLogoFetchServeRow()`
 * (`includes/org_logo_helpers.php`) SELECTs `ContentSanitised` only, and
 * that is the ONLY function this file calls to get bytes.
 * `tests/php/test-org-logo-surfaces.php` bans any other read path.
 *
 * @see .claude/org-logos-1830-plan.md §5  the full design this file implements
 * @see appWeb/public_html/qr.php           the standalone-image-endpoint shape this mirrors
 * @see appWeb/public_html/song-media.php    the schema-probe + conditional-GET pattern this mirrors
 * @see appWeb/public_html/includes/org_logo_helpers.php  orgLogoFetchServeRow(), the ONE read
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'read_rate_limit.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'org_logo_helpers.php';

/* Ordering is load-bearing (mirrors qr.php): set BEFORE any exit path. */
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

/**
 * End the request with a status and NO body — the consuming `<img>` treats
 * any non-image response as a load failure and the surface degrades to
 * nothing (never a broken-image glyph on a printed handout).
 */
function _orgLogoFail(int $status): never
{
    http_response_code($status);
    exit;
}

/* Fail-open rate limit — never let the limiter itself break the endpoint. */
try {
    enforceReadRateLimitKeyed('org-logo', 240);
} catch (\Throwable $e) {
    // fail-open
}

/* ---- Validate + clamp inputs ---- */
$orgId = isset($_GET['org']) ? (int)$_GET['org'] : 0;
if ($orgId <= 0) {
    _orgLogoFail(400);
}
$kind = isset($_GET['kind']) ? (string)$_GET['kind'] : '';
if (!in_array($kind, ihymnsOrgLogoKindKeys(), true)) {
    _orgLogoFail(400);
}
$variant = isset($_GET['variant']) ? (string)$_GET['variant'] : 'default';
if (!in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)) {
    _orgLogoFail(400);
}
$vParam = isset($_GET['v']) ? (string)$_GET['v'] : '';

/* ---- Dormancy / connectivity ---- */
try {
    $db = getDbMysqli();
} catch (\Throwable $e) {
    _orgLogoFail(503);
}
if (!orgLogoTableExists($db)) {
    /* "not migrated yet" and "no such logo" must be indistinguishable to a
       prober (song-media.php's own stated reasoning) — 404, not 503. */
    _orgLogoFail(404);
}

/* ---- Fetch the ONE row this endpoint may ever read bytes from ---- */
$row = orgLogoFetchServeRow($db, $orgId, $kind, $variant);
if ($row === null) {
    _orgLogoFail(404); // no active logo for this (org, kind[, variant]) — no placeholder, ever
}

$mime   = (string)$row['Mime'];
$sha256 = (string)$row['Sha256'];
$etag   = '"' . $sha256 . '"';

/* ---- Conditional GET — queued unconditionally, checked AFTER the row
   lookup (a public logo carries no gating concern, so unlike song-media.php
   there is no access check to accidentally short-circuit past). ---- */
header('ETag: ' . $etag);
$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && ($ifNoneMatch === '*' || $ifNoneMatch === $etag)) {
    http_response_code(304);
    exit;
}

/* ---- Success headers ---- */
$ext = ($mime === 'image/svg+xml') ? 'svg' : 'png';
header('Content-Type: ' . $mime);
/* Belt-and-braces for a DIRECT navigation to this URL: even if a hostile
   SVG somehow reached storage, a document-context load here can run no
   script, fetch nothing, and sits in an origin-less sandbox.
   `style-src 'unsafe-inline'` keeps the logo's own sanitiser-approved
   `style=` attributes rendering — the industry-standard user-SVG serving
   header set. */
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Content-Disposition: inline; filename="org-' . $orgId . '-' . $kind . '.' . $ext . '"');

/* A logo is MUTABLE (unlike a QR for a fixed payload) — blanket-immutable
   would strand a stale logo for a year. Hard-cache ONLY when the request's
   `v` matches this row's Sha256 (or a documented hex-prefix of it); the
   `&v=` token every emitting surface appends restores hard caching safely. */
if ($vParam !== '' && ($vParam === $sha256 || str_starts_with($sha256, $vParam))) {
    header('Cache-Control: public, max-age=31536000, immutable');
} else {
    header('Cache-Control: public, max-age=3600');
}

$bytes = (string)($row['ContentSanitised'] ?? '');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
