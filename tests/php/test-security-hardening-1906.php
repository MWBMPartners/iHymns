<?php

declare(strict_types=1);

/**
 * iHymns — Security-hardening wiring guard (#1906)
 *
 * ELI5: several security fixes live in DB/session-coupled code paths that no
 * existing test exercises, so a later refactor could silently delete one and
 * everything would stay green. This guard asserts each fix is still wired in.
 *
 * These are SOURCE-STRUCTURAL assertions, scoped per-function/per-case via
 * php_source_units (comment-stripped, string-opaque `code` view so a mention in
 * prose or SQL can't satisfy them). They are deliberately structural, not
 * behavioural: the paths need a live DB + PHP session + a botnet to exercise for
 * real. Each is mutation-proven — deleting the fix turns the matching assertion
 * red (see the per-assertion notes). This is the repo's account-lifecycle idiom
 * for guarding hard-to-behaviourally-test security code.
 *
 *   php tests/php/test-security-hardening-1906.php
 *
 * Exit 0 = every #1906 hardening is still wired, 1 = one was removed.
 */

require_once __DIR__ . '/lib/php_source_units.php';

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

$failures = [];
$passed   = 0;
function shOk(string $label, bool $cond): void
{
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

/** The `code` view of one function/case unit in a file (comment-stripped). */
function shUnit(string $file, string $unitName): string
{
    $src = @file_get_contents($file);
    if ($src === false) { return ''; }
    $units = phpSourceUnits($src);
    return $units[$unitName]['code'] ?? '';
}

/** All reconstructed STRING literals of a unit joined — for asserting SQL
 *  content, which the `code` view deliberately renders opaque ('@STR@'). */
function shUnitStrings(string $file, string $unitName): string
{
    $src = @file_get_contents($file);
    if ($src === false) { return ''; }
    $units = phpSourceUnits($src);
    return implode("\n", $units[$unitName]['strings'] ?? []);
}

$api  = $pub . '/api.php';
$auth = $pub . '/manage/includes/auth.php';

/* ── AUTH 1: auth_register now RECORDS an attempt + reads action-scoped ─────── */
$reg    = shUnit($api, "case 'auth_register'");
$regSql = shUnitStrings($api, "case 'auth_register'");   // SQL is opaque in the code view
shOk("auth_register is a locatable case unit", $reg !== '');
/* #1929 — the raw INSERT literal moved into the shared write primitive
   loginAttemptsInsert() (includes/rate_limit.php), which every writer now
   funnels through so the new Action column gets stamped consistently
   (rule #35: one write primitive, not a fork per call site). The case body
   now calls that helper rather than preparing its own INSERT, so the
   fingerprint moves from a SQL-literal marker to a function-call marker.
   Mutation: delete the loginAttemptsInsert(…, 'auth_register') call → the
   dead 20/hr cap never fills again → RED. */
shOk("auth_register records the attempt via the shared loginAttemptsInsert() primitive",
    strpos($reg, 'loginAttemptsInsert(') !== false
    && substr_count($regSql, 'auth_register') >= 3);   // 1 in the SELECT text below + Username/Action args
/* The count read is action-scoped so a busy login IP can't block signup: the
   SELECT WHERE clause names the action. Mutation: drop the scope → RED. */
shOk("auth_register count read is action-scoped (Username = 'auth_register')",
    (bool) preg_match("/Username\s*=\s*'auth_register'/", $regSql));

/* ── AUTH 2: session-fixation — adoptApiTokenSession rotates the id first ───── */
$adopt = shUnit($auth, 'adoptApiTokenSession');
shOk("adoptApiTokenSession is a locatable unit", $adopt !== '');
/* Mutation: delete the regenerate → an anonymous fixed PHPSESSID survives the
   promotion into an authenticated session → RED. */
shOk("adoptApiTokenSession calls session_regenerate_id before seeding the identity",
    (function (string $c): bool {
        $r = strpos($c, 'session_regenerate_id');
        $s = strpos($c, "\$_SESSION['user_id']");
        return $r !== false && $s !== false && $r < $s;   // regenerate BEFORE seed
    })($adopt));

/* ── AUTH 3: email-code verify has a PER-EMAIL bucket (distributed brute-force)  */
$ev = shUnit($api, "case 'auth_email_login_verify'");
shOk("auth_email_login_verify is a locatable case unit", $ev !== '');
/* Mutation: delete the per-email check → an IP-rotating botnet can grind the
   ~1M code space again → RED. */
shOk("email verify enforces a per-EMAIL cap (checkRateLimit 'email_verify_id')",
    strpos($ev, "checkRateLimit('email_verify_id'") !== false || strpos($ev, 'checkRateLimit(\'email_verify_id\'') !== false);
shOk("email verify SPENDS the per-email budget on a miss (recordRateLimitHit 'email_verify_id')",
    strpos($ev, "recordRateLimitHit('email_verify_id'") !== false);
/* The per-email key is hashed (fits the VARCHAR(45) bucket column, never raw). */
shOk("the per-email key is hashed, not the raw email (VARCHAR(45) safety)",
    strpos($ev, "hash('sha256'") !== false);

/* ── OG-IMAGE: copyrighted-lyric content gate + defensive headers ───────────── */
$ogSrc = (string) @file_get_contents($pub . '/og-image.php');
/* Mutation: delete the gate → a gated song's share card leaks the verse → RED. */
shOk("og-image.php gates the song card via checkContentAccess('song', …)",
    strpos($ogSrc, "checkContentAccess('song'") !== false);
shOk("og-image.php downgrades a denied song to the generic card",
    (bool) preg_match("/empty\(\\\$ogGate\['allowed'\]\).*?\\\$mode\s*=\s*'generic'/s", $ogSrc));
shOk("og-image.php sets nosniff + a tight CSP on the image response",
    strpos($ogSrc, 'X-Content-Type-Options: nosniff') !== false
    && strpos($ogSrc, "default-src 'none'; sandbox") !== false);

/* ── RATE LIMITS: the endpoints that were uncapped ──────────────────────────── */
shOk("song_of_the_day is rate-limited",
    strpos(shUnit($api, "case 'song_of_the_day'"), "enforceReadRateLimitKeyed('song_of_the_day'") !== false);
foreach (['song-media.php', 'audio-media.php'] as $mf) {
    $s = (string) @file_get_contents($pub . '/' . $mf);
    shOk("$mf caps media byte volume (enforceReadRateLimitKeyed('media', …))",
        strpos($s, "enforceReadRateLimitKeyed('media'") !== false);
}
shOk("og-image.php is rate-limited",
    strpos($ogSrc, "enforceReadRateLimitKeyed('og_image'") !== false);

/* ── /manage CSP: a genuine, non-breaking CSP is emitted from the bootstrap ─── */
$authFull = (string) @file_get_contents($auth);
shOk("/manage emits a CSP with the four zero-breakage hardening directives",
    (bool) preg_match("/Content-Security-Policy:[^\"']*object-src 'none'[^\"']*base-uri 'self'[^\"']*form-action 'self'[^\"']*frame-ancestors 'self'/", $authFull));

/* ── X-POWERED-BY: branded "iHymns/<version>", PHP runtime version hidden ────── */
$infoVer = $pub . '/includes/infoAppVer.php';
$pb      = shUnit($infoVer, 'ihymns_emit_powered_by_header');
$pbStr   = shUnitStrings($infoVer, 'ihymns_emit_powered_by_header');
shOk("X-Powered-By emitter is a locatable function unit", $pb !== '');
/* Mutation: drop the headers_sent/CLI guard → header() warns on a flushed
   response and no-ops on the setup-database CLI probe → RED. */
shOk("X-Powered-By emitter guards headers_sent + CLI before header()",
    strpos($pb, 'headers_sent') !== false
    && strpos($pb, 'PHP_SAPI') !== false
    && strpos($pb, 'header(') !== false);
/* The brand carries the APP version (Name '/' Version.Number), never PHP's.
   Mutation: emit the bare name (drop "/".$version) → the '/' literal and the
   Version/Number key reads vanish from the string view → RED. */
shOk("X-Powered-By emitter composes Name '/' Version.Number (brands the app version)",
    strpos($pbStr, 'X-Powered-By: ') !== false
    && strpos($pbStr, 'Number') !== false
    && (bool) preg_match('~^/$~m', $pbStr));
/* The two scanner-facing entry points actually call it (the SPA catch-all sees
   every unmatched path; the API sees every probe). Mutation: delete a call → RED. */
foreach (['index.php', 'api.php'] as $ep) {
    $s = (string) @file_get_contents($pub . '/' . $ep);
    shOk("$ep brands the response (calls ihymns_emit_powered_by_header)",
        strpos($s, 'ihymns_emit_powered_by_header(') !== false);
}
/* The LEAK defence: expose_php=Off is what actually hides "PHP/8.x" at source.
   Mutation: flip to On → the runtime version leaks on every FPM response → RED. */
$userIni = (string) @file_get_contents($pub . '/.user.ini');
shOk(".user.ini hides the PHP runtime version (expose_php = Off)",
    (bool) preg_match('/^\s*expose_php\s*=\s*Off\s*$/mi', $userIni));
/* .htaccess belt-and-braces (mod_php ignores .user.ini): a VALUE-matched edit
   that rewrites only a leaked "PHP/x" — and, matching on value, leaves our
   "iHymns/<version>" brand alone. A blanket `unset` would strip the brand, so
   assert the edit is present AND the blanket unset is gone. Comment-blind so a
   doc-block mention of either form can't satisfy/trip it. */
$htCode = preg_replace('/^\s*#.*$/m', '', (string) @file_get_contents($pub . '/.htaccess'));
shOk(".htaccess strips a PHP/x leak without clobbering the brand (value-matched edit)",
    (bool) preg_match('/Header\s+always\s+edit\s+X-Powered-By\s+"\^PHP\//', $htCode));
shOk(".htaccess no longer blanket-unsets X-Powered-By (that would strip our brand)",
    !preg_match('/Header\s+always\s+unset\s+X-Powered-By/', $htCode));

/* ── report ─────────────────────────────────────────────────────────────────── */
if ($failures) {
    fwrite(STDERR, "FAIL: #1906 security-hardening wiring:\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  ✗ $f\n"); }
    fwrite(STDERR, "\n{$passed} passed, " . count($failures) . " failed.\n");
    exit(1);
}
echo "PASS: #1906 security-hardening wiring ({$passed} assertions).\n";
exit(0);
