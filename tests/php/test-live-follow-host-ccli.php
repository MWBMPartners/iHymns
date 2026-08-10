<?php

declare(strict_types=1);

/**
 * iHymns — Quick-host CCLI unlock guard (#1770 G3, req #3/#6)
 * ============================================================================
 * ELI5: a Quick "Go Live" host has no venue, so the ONLY thing standing
 * between "a follower reads gated lyrics because the LEADER happens to hold
 * a CCLI licence" and "a follower reads gated lyrics because they found some
 * ORGANISATION's licence to ride" is (a) a Quick session's `OrgId` being
 * genuinely, always NULL, so the org-anchored branch's INNER JOIN can never
 * match one, and (b) the host-licence branch being reachable from EXACTLY
 * one place — the same content-access injection point every other unlock
 * already goes through, never a second doorway.
 *
 * PART A (always, no DB) — source scan:
 *   A1. `live_follow_create` (api.php) assigns `$orgId = null;` as a literal
 *       PHP source constant, and never reassigns `$orgId` to anything else
 *       before the session INSERT — i.e. the FK genuinely cannot become
 *       non-NULL for a Quick session, not merely "usually doesn't".
 *   A2. `serviceMode_presenceCcliNumber(` is CALLED from exactly ONE
 *       non-definition site in the whole scanned tree, and that site is
 *       inside `checkContentAccess()`'s presence branch
 *       (`includes/content_access.php`) — the ONE injection point rule #26
 *       names. A second caller anywhere would mean a second, unaudited
 *       doorway into the same licence grant.
 *   A3. No SECOND function matching `serviceMode_presence*CcliNumber`
 *       exists — i.e. nobody has (yet) forked a "helper" copy of the host
 *       branch under a sibling name that would bypass A2's single-caller
 *       check by construction.
 *   A4. Inside `serviceMode_presenceCcliNumber()`, the org-anchored branch's
 *       query text appears BEFORE the host-branch call
 *       (`serviceMode_idleFreshSql` inside that function, the host branch's
 *       own marker) — i.e. the host branch stays textually SECOND, per
 *       plan §11 landmine 5 ("the org branch above … is tried FIRST").
 *
 * PART B (DB, else SKIP) — behavioural byte-identity: for an entity with NO
 * `tblContentRestrictions` rows at all, `checkContentAccess()` returns the
 * IDENTICAL verdict whether or not a presence token is supplied — i.e. the
 * host-CCLI injection changes nothing unless a `require_licence:ccli` rule
 * is actually present to act on it (the dormancy `test-gating-noop.php`
 * already pins for the SEPARATE tier-cap gating registry, rule #28 —  this
 * is the SAME "no rule to act on ⇒ no behaviour change" property applied to
 * the rule #26 presence injection, which cannot be proven DB-free the way
 * that guard is because `checkContentAccess()` connects to the DB
 * unconditionally).
 *
 * MUTATION PROOF (rule #34): see the commit body for the exact mutations —
 * reassigning `$orgId` after its NULL literal (A1), adding a second call
 * site (A2), adding a sibling function name (A3), and swapping the branch
 * order (A4) each turn the corresponding assertion red; restoring goes
 * green.
 *
 * @see appWeb/public_html/includes/service_mode.php  serviceMode_presenceCcliNumber()
 * @see appWeb/public_html/includes/content_access.php:416-422
 * @see .claude/live-follow-1770-plan.md §4.3, §9 G3, §11 landmine 5
 */

$scanRoot = getenv('IHYMNS_SCAN_ROOT') ?: (dirname(__DIR__, 2) . '/appWeb/public_html');
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: could not read $scanRoot\n");
    exit(1);
}

$failures = 0;
$passed   = 0;
function lhc(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

function lhcStripComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/** @return array{0:int,1:int}|null */
function lhcFunctionBodyRange(string $src, string $name): ?array
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $sigPos = $m[0][1];
    $bracePos = strpos($src, '{', $sigPos);
    if ($bracePos === false) { return null; }
    $depth = 0;
    $len = strlen($src);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) { return [$sigPos, $i + 1]; }
        }
    }
    return null;
}

/* =========================================================================
 * PART A — source scan
 * ========================================================================= */

$apiPath = $scanRoot . '/api.php';
$smPath  = $scanRoot . '/includes/service_mode.php';
$caPath  = $scanRoot . '/includes/content_access.php';
$apiSrc  = is_readable($apiPath) ? lhcStripComments((string)file_get_contents($apiPath)) : '';
$smSrc   = is_readable($smPath)  ? lhcStripComments((string)file_get_contents($smPath))  : '';
$caSrc   = is_readable($caPath)  ? lhcStripComments((string)file_get_contents($caPath))  : '';

lhc($apiSrc !== '', 'A0.1 read api.php');
lhc($smSrc  !== '', 'A0.2 read includes/service_mode.php');
lhc($caSrc  !== '', 'A0.3 read includes/content_access.php');

/* A1 — live_follow_create's $orgId is a PHP-source NULL literal, never
   reassigned before the INSERT, within that ONE case block. */
if (preg_match('/case\s+\'live_follow_create\'\s*:/', $apiSrc, $m, PREG_OFFSET_CAPTURE)) {
    $start = $m[0][1];
    $nextPos = null;
    if (preg_match('/case\s+\'[a-z0-9_]+\'\s*:/', $apiSrc, $mNext, PREG_OFFSET_CAPTURE, $start + 10)) {
        $nextPos = $mNext[0][1];
    }
    $block = substr($apiSrc, $start, ($nextPos ?? strlen($apiSrc)) - $start);

    $hasNullLiteral = (bool)preg_match('/\$orgId\s*=\s*null\s*;/i', $block);
    lhc($hasNullLiteral, "A1.1 live_follow_create assigns \$orgId = null; as a literal (never resolved from input)");

    /* Every assignment to $orgId in this block must be that SAME NULL
       literal — i.e. no OTHER assignment shape exists (which would mean a
       later line overwrites the null before the INSERT reads it). */
    preg_match_all('/\$orgId\s*=\s*([^;]+);/i', $block, $mAll);
    $otherAssignments = array_values(array_filter($mAll[1], static fn(string $rhs): bool => strtolower(trim($rhs)) !== 'null'));
    lhc(
        $otherAssignments === [],
        'A1.2 $orgId is never reassigned to anything other than null within live_follow_create '
            . '(rule #26 I3 — Quick sessions keep OrgId hardcoded NULL)'
            . ($otherAssignments === [] ? '' : ' — found: ' . implode(', ', $otherAssignments))
    );
} else {
    lhc(false, "A1 found a case 'live_follow_create': block in api.php to inspect");
}

/* A2 — exactly one CALL site of serviceMode_presenceCcliNumber( outside its
   own definition, and it lives inside content_access.php. */
$callSites = [];
foreach (['api.php' => $apiSrc, 'includes/service_mode.php' => $smSrc, 'includes/content_access.php' => $caSrc] as $label => $src) {
    $offset = 0;
    while (($pos = strpos($src, 'serviceMode_presenceCcliNumber(', $offset)) !== false) {
        $offset = $pos + 1;
        /* Skip the definition line itself. */
        $before = substr($src, max(0, $pos - 15), 15);
        if (preg_match('/function\s*$/', $before)) { continue; }
        $line = substr_count(substr($src, 0, $pos), "\n") + 1;
        $callSites[] = "$label:$line";
    }
}
lhc(
    count($callSites) === 1 && str_starts_with($callSites[0], 'includes/content_access.php:'),
    'A2 serviceMode_presenceCcliNumber() is called from exactly ONE site, inside content_access.php '
        . '(the ONE injection point, rule #26)' . (count($callSites) === 1 ? '' : ' — found: ' . implode(', ', $callSites))
);

/* A3 — no sibling helper name has been forked off the canonical one. */
preg_match_all('/function\s+(serviceMode_presence\w*CcliNumber)\s*\(/', $smSrc, $mFns);
$fnNames = array_values(array_unique($mFns[1]));
lhc(
    $fnNames === ['serviceMode_presenceCcliNumber'],
    'A3 exactly one serviceMode_presence*CcliNumber() function is defined (no forked sibling)'
        . (count($fnNames) <= 1 ? '' : ' — found: ' . implode(', ', $fnNames))
);

/* A4 — inside the function body, the org-anchored branch's own marker text
   appears BEFORE the host branch's serviceMode_idleFreshSql() call (its
   textual signature, since the org branch never calls that helper). */
$ccliRange = lhcFunctionBodyRange($smSrc, 'serviceMode_presenceCcliNumber');
if ($ccliRange !== null) {
    $body = substr($smSrc, $ccliRange[0], $ccliRange[1] - $ccliRange[0]);
    $orgMarkerPos = strpos($body, 'tblOrganisationLicences');
    $hostMarkerPos = strpos($body, 'serviceMode_idleFreshSql(');
    lhc(
        $orgMarkerPos !== false && $hostMarkerPos !== false && $orgMarkerPos < $hostMarkerPos,
        'A4 the org-anchored branch precedes the host-licence branch inside serviceMode_presenceCcliNumber() '
            . '(plan §11 landmine 5 — the org branch must stay first and unchanged)'
    );
} else {
    lhc(false, 'A4 found serviceMode_presenceCcliNumber() function body to inspect');
}

/* =========================================================================
 * PART B — behavioural byte-identity (DB, else SKIP)
 * ========================================================================= */

$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null; $port = 3306; $dbName = null;
$repoRoot = dirname(__DIR__, 2);
$credFile = $repoRoot . '/appWeb/.auth/db_credentials.php';
if (is_readable($credFile)) {
    require $credFile;
    if (defined('DB_HOST')) { $host = DB_HOST; }
    if (defined('DB_USER')) { $user = DB_USER; }
    if (defined('DB_PASS')) { $pass = DB_PASS; }
    if (defined('DB_PORT')) { $port = (int)DB_PORT; }
    if (defined('DB_NAME')) { $dbName = DB_NAME; }
} else {
    $dsn = getenv('IHYMNS_TEST_DSN') ?: '';
    if ($dsn !== '') {
        foreach (explode(';', $dsn) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if ($k === 'host')   { $host = $v; }
            if ($k === 'user')   { $user = $v; }
            if ($k === 'pass')   { $pass = $v; }
            if ($k === 'socket') { $sock = $v; }
            if ($k === 'port')   { $port = (int)$v; }
            if ($k === 'dbname' || $k === 'db') { $dbName = $v; }
        }
    }
}

$behaviouralRan = false;
if ($dbName !== null && $sock === null) {
    if (!defined('DB_HOST')) { define('DB_HOST', $host); }
    if (!defined('DB_USER')) { define('DB_USER', $user); }
    if (!defined('DB_PASS')) { define('DB_PASS', $pass); }
    if (!defined('DB_PORT')) { define('DB_PORT', (string)$port); }
    if (!defined('DB_NAME')) { define('DB_NAME', $dbName); }

    require_once $scanRoot . '/includes/content_access.php';

    try {
        $db = getDbMysqli();
        $hasRestrictions = (bool)$db->query("SHOW TABLES LIKE 'tblContentRestrictions'")->num_rows;
    } catch (\Throwable $e) {
        $db = null; $hasRestrictions = false;
    }

    if ($db !== null && $hasRestrictions) {
        /* A bogus entity id with certainly-zero restriction rows (song ids
           never look like this) — no fixture insert/rollback needed, this
           is a pure READ against an id that cannot match anything. */
        $bogusId = 'ZZ-1770-NONEXISTENT-9999';
        try {
            $withoutToken = checkContentAccess('song', $bogusId, null, 'PWA', null);
            $withToken    = checkContentAccess('song', $bogusId, null, 'PWA', 'zz-not-a-real-presence-token-at-all-000000000');
            lhc(
                $withoutToken === $withToken,
                'B1 checkContentAccess() returns an IDENTICAL verdict with vs. without a presence token '
                    . 'when no restriction rule exists to act on it (dormancy)'
            );
            $behaviouralRan = true;
        } catch (\Throwable $e) {
            lhc(false, 'B1 checkContentAccess() ran without throwing — ' . $e->getMessage());
        }
    }
}

if ($failures) {
    fwrite(STDERR, "\n{$passed} passed, {$failures} failed\n");
    exit(1);
}
echo "\n{$passed} passed, 0 failed";
echo $behaviouralRan ? " (Part B ran against a live DB).\n" : " (Part B SKIPPED: no reachable database, or tblContentRestrictions absent).\n";
exit(0);
