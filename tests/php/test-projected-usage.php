<?php

declare(strict_types=1);

/**
 * iHymns — Service-Mode PROJECTED usage-logging guard (#1897 W2)
 * ============================================================================
 *
 * ELI5: when a church runs Service Mode and switches the congregation's screen
 * to a new song, iHymns should quietly record ONE "this song was used" row for
 * that song, under the church's CCLI licence — but ONLY once per song per
 * service, ONLY if the church holds a licence, and NEVER in a way that could
 * break or slow the actual broadcast. This test proves all of that two ways:
 * (Part A) by reading the real source and confirming the gate precedes the
 * write and the hook lives in the ONE broadcast core (not copied into the two
 * api.php call sites), and (Part B) by seeding a service session in a throwaway
 * transaction and checking exactly what gets written.
 *
 * Mirrors `test-print-usage-ccli-gate.php` / `test-ccli-report-org-scope.php`:
 * Part A source scan (tokenizer comment-strip so a doc-block MENTION can never
 * vouch for a check), Part B behavioural against a live DB in ONE rolled-back
 * transaction (SKIP when no reachable dbname). Derived from the tree, not a
 * typed list; every assertion is mutation-proven (rule #34).
 *
 * PART A (always, no DB):
 *   A1. `projectionUsageLog()`'s body calls `printUsageResolveOrgCcliLicence(`,
 *       has the compound `if ($licence === null) { return false;` guard, and
 *       that guard appears AFTER the resolve call and BEFORE the
 *       `_usageEventInsert(` write — the ORG-licence gate textually precedes
 *       the write (the same before-the-write anchoring the sibling A1.2 uses,
 *       including its lesson: anchor the COMPOUND pattern, not "any return
 *       false", because the body ALSO returns false on bad input, an
 *       un-migrated table, and a CCLI-less song).
 *   A2. The hook lives in the ONE broadcast core, not the call sites: a
 *       tree-derived scan of every PHP file under `appWeb/public_html` finds
 *       `projectionUsageLog(` CALLED from exactly one file,
 *       `includes/service_mode.php` (the definition file `print_usage.php` is
 *       excluded by "calls it but does not `function`-define it"); and inside
 *       `serviceMode_applyBroadcast()`'s body the call appears AFTER the
 *       `SET CurrentSongId` UPDATE and inside a `try {` that opens after the
 *       Live-Activity push (the #1860 fail-safe wrapper).
 *   A3. The pre-read precedes the UPDATE: in the same body, the
 *       `SELECT SessionKind` pre-read appears BEFORE `SET CurrentSongId`, so a
 *       genuine song change is detectable after the write overwrites it.
 *   A4. The SessionKind gate exists: the hook condition compares the pre-read
 *       `SessionKind` against `'service'`, after the UPDATE.
 *
 * PART B (DB, else SKIP) — behavioural, one rolled-back transaction:
 *   Fixture: org A (IsActive=1) + an active `ccli` tblOrganisationLicences row,
 *   a `service`-kind tblLiveFollowSessions row (OrgId=A, SetlistId='B-list'),
 *   one song WITH a CCLI number and one WITHOUT.
 *   B1. `projectionUsageLog()` writes exactly ONE row: UsageContext='projected',
 *       Quantity=1, OrgId=A, LicenceId = the fixture licence row id (W2 finally
 *       populates LicenceId), SetlistId stamped, MetaJson.sessionId correct.
 *   B2. Same (session, song) again → NO second row (the dedup).
 *   B3. A SECOND session, same org+song → a second row (dedup is per-SESSION).
 *   B4. Org with no qualifying licence → false, zero rows. B4b an INACTIVE org
 *       that DOES hold a licence row → zero rows (the closed-org decision).
 *   B5. Integration through the core: `serviceMode_applyBroadcast(..)` on a
 *       service session → the projected row exists AND the returned revision
 *       incremented; calling again with the SAME song → revision increments
 *       again but the row count is unchanged (fail-safe + change-detection +
 *       dedup in one); a `host`-kind session → zero rows (D3 scope).
 *   B6. A CCLI-less song → zero rows (the D2 default).
 *
 * MUTATION PROOFS (each applied to the REAL tree, observed RED, reverted —
 * recorded in the commit body):
 *   1. Delete the `$licence === null` guard in projectionUsageLog() → A1 + B4
 *      RED (the fixture is a real org + real CCLI song, so only the gate can
 *      stop the write).
 *   2. Move the hook above the UPDATE in applyBroadcast() → A2 ordering RED.
 *   3. Delete the `=== 'service'` SessionKind condition → A4 RED + B5 host RED.
 *   4. Delete the dedup SELECT → B2 RED.
 *   5. Change the MetaJson key to `session_id` → B2 RED (dedup never matches).
 *   6. Call projectionUsageLog() directly from api.php's service_broadcast →
 *      A2's one-call-site scan RED.
 *   (Mutation 7 — duplicating the INSERT literal → A2b RED — lives in the
 *    sibling test-print-usage-ccli-gate.php.)
 *
 * Usage:
 *   php tests/php/test-projected-usage.php
 *      (Part B needs IHYMNS_TEST_DSN with a dbname, or
 *       appWeb/.auth/db_credentials.php; else Part A still runs.)
 * Exit: 0 = pass (Part B may SKIP), 1 = any failure.
 *
 * @see appWeb/public_html/includes/print_usage.php       projectionUsageLog() + printUsageResolveOrgCcliLicence() + _usageEventInsert()
 * @see appWeb/public_html/includes/service_mode.php       serviceMode_applyBroadcast() — the ONE hook point
 * @see appWeb/public_html/includes/ccli_report.php        the #1861 report that these rows populate
 * @see tests/php/test-print-usage-ccli-gate.php           the comment-strip + one-writer sibling
 * @link #1897
 */

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/appWeb/public_html';

$failures = 0;
$passed   = 0;
function pu2(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Strip comments via the real tokenizer (never a slash-star regex — it would
 *  match the very prose that EXPLAINS the gate; the test-print-usage-ccli-gate.php
 *  precedent). Line numbers preserved. */
function pu2StripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
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
    /* ALSO blank HTML comments (<!-- … -->). A template file (e.g.
       manage/includes/ccli-report-results.php) may legitimately DOCUMENT the
       writer by name in an HTML comment — token_get_all leaves that as
       T_INLINE_HTML, so without this a doc MENTION would read as a second call
       site and trip A2.1 (rule #34: a guard must not fail on correct code). A
       real caller writes the call in PHP code (T_STRING), which survives both
       strips — mutation 6 (a real api.php call) still goes red. Newlines kept
       so any strpos-ordering assertion stays line-stable. */
    return preg_replace_callback(
        '/<!--.*?-->/s',
        static fn(array $m): string => (string)preg_replace('/[^\n]/', ' ', $m[0]),
        $out
    );
}

/** Extract a top-level function body (BALANCED braces, so nested if/foreach
 *  spans correctly — unlike a lazy regex). Null if the function is absent. */
function pu2FunctionBody(string $src, string $name): ?string
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $bracePos = $m[0][1] + strlen($m[0][0]) - 1; // the opening '{' itself
    $depth = 0;
    $len = strlen($src);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) { return substr($src, $bracePos + 1, $i - $bracePos - 1); }
        }
    }
    return null;
}

/** Every .php file under a directory (recursive). */
function pu2AllPhpFiles(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) { return $out; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $out[] = $f->getPathname(); }
    }
    return $out;
}

$printUsagePath  = $publicRoot . '/includes/print_usage.php';
$serviceModePath = $publicRoot . '/includes/service_mode.php';

pu2(is_file($printUsagePath),  'A0.1 includes/print_usage.php exists');
pu2(is_file($serviceModePath), 'A0.2 includes/service_mode.php exists');
if ($failures > 0) {
    fwrite(STDERR, "\nFATAL: one or more #1897 W2 files are missing — cannot continue.\n");
    exit(1);
}

$printUsageSrc  = pu2StripComments((string)file_get_contents($printUsagePath));
$serviceModeSrc = pu2StripComments((string)file_get_contents($serviceModePath));

/* =============================================================================
 * A1 — the ORG-licence gate precedes the write, INSIDE projectionUsageLog().
 * ============================================================================= */
$projBody = pu2FunctionBody($printUsageSrc, 'projectionUsageLog');
pu2($projBody !== null, 'A1.0 extracted the projectionUsageLog() function body');
if ($projBody !== null) {
    $gatePos   = strpos($projBody, 'printUsageResolveOrgCcliLicence(');
    $insertPos = strpos($projBody, '_usageEventInsert(');
    pu2($gatePos !== false, 'A1.1 projectionUsageLog() calls printUsageResolveOrgCcliLicence( (the org gate)');

    /* Anchor the COMPOUND `if ($licence === null) { return false;` — the body
       ALSO returns false on bad input / un-migrated table / CCLI-less song, so
       "any return false after the resolve" would mis-anchor (the sibling
       A1.2's own documented lesson). */
    $guardMatch = preg_match(
        '/if\s*\(\s*\$licence\s*===\s*null\s*\)\s*\{\s*return\s+false\s*;/',
        $projBody,
        $gm,
        PREG_OFFSET_CAPTURE
    );
    $guardPos = $guardMatch ? $gm[0][1] : false;
    pu2(
        $gatePos !== false && $guardPos !== false && $insertPos !== false
            && $gatePos < $guardPos && $guardPos < $insertPos,
        'A1.2 projectionUsageLog() has an "if ($licence === null) { return false; }" guard AFTER the '
            . 'printUsageResolveOrgCcliLicence() call and BEFORE the _usageEventInsert() write — a projection '
            . 'under an org with no qualifying CCLI licence can never reach the write'
    );
}

/* =============================================================================
 * A2 — the hook lives in the ONE broadcast core, NOT the two api.php call
 * sites (rule #35 "keep these in sync" would rot; a 3rd broadcaster inherits).
 * ============================================================================= */
$callerFiles = [];
foreach (pu2AllPhpFiles($publicRoot) as $f) {
    $stripped = pu2StripComments((string)file_get_contents($f));
    if (str_contains($stripped, 'projectionUsageLog(')
        && !str_contains($stripped, 'function projectionUsageLog(')) {
        $callerFiles[] = ltrim(str_replace($publicRoot, '', $f), '/');
    }
}
sort($callerFiles);
pu2(
    $callerFiles === ['includes/service_mode.php'],
    'A2.1 projectionUsageLog() is CALLED from exactly ONE file — includes/service_mode.php (the ONE '
        . 'broadcast core), never from an api.php call site'
        . ($callerFiles === ['includes/service_mode.php'] ? '' : ' (found: ' . (implode(', ', $callerFiles) ?: 'none') . ')')
);

$applyBody = pu2FunctionBody($serviceModeSrc, 'serviceMode_applyBroadcast');
pu2($applyBody !== null, 'A2.2 extracted serviceMode_applyBroadcast() body');
if ($applyBody !== null) {
    $updPos     = strpos($applyBody, 'SET CurrentSongId');
    $callPos    = strpos($applyBody, 'projectionUsageLog(');
    $pushPos    = strpos($applyBody, 'liveActivitySessionPush');
    $tryAfterPush = ($pushPos !== false) ? strpos($applyBody, 'try {', $pushPos) : false;
    pu2(
        $updPos !== false && $callPos !== false && $updPos < $callPos,
        'A2.3 the projectionUsageLog() hook fires AFTER the "SET CurrentSongId" UPDATE — it logs only what '
            . 'was actually broadcast (move it above the UPDATE to see this go red)'
    );
    pu2(
        $pushPos !== false && $tryAfterPush !== false && $callPos !== false && $tryAfterPush < $callPos,
        'A2.4 the hook sits inside a try { that opens AFTER the Live-Activity push — the #1860 fail-safe: a '
            . 'logging failure can never break or delay the broadcast'
    );

    /* ------- A3: the pre-read precedes the UPDATE ------------------------- */
    $preReadPos = strpos($applyBody, 'SELECT SessionKind');
    pu2(
        $preReadPos !== false && $updPos !== false && $preReadPos < $updPos,
        'A3 the "SELECT SessionKind" pre-read appears BEFORE "SET CurrentSongId" — the prior song is captured '
            . 'before the UPDATE overwrites it, so a genuine song change is detectable'
    );

    /* ------- A4: the SessionKind == service gate exists post-write -------- */
    $serviceGate = preg_match('/SessionKind[^\n]*===\s*[\'"]service[\'"]/', $applyBody);
    $servicePos  = strpos($applyBody, "=== 'service'");
    pu2(
        (bool)$serviceGate && $servicePos !== false && $updPos !== false && $servicePos > $updPos,
        'A4 the hook gates on the pre-read SessionKind === \'service\' (after the write) — only a service-kind '
            . 'session is usage-logged (Quick/host sessions are out of scope, D3)'
    );
}

/* =============================================================================
 * PART B — behavioural (DB, else SKIP)
 * ============================================================================= */

$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null; $port = 3306; $dbName = null;
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

    require_once $publicRoot . '/includes/db_mysql.php';
    require_once $printUsagePath;   // projectionUsageLog(), printUsageResolveOrgCcliLicence()
    require_once $serviceModePath;  // serviceMode_applyBroadcast()

    try {
        $db = getDbMysqli();
        $need = ['tblOrganisations', 'tblOrganisationLicences', 'tblSongbooks',
                 'tblSongs', 'tblSongUsageEvents', 'tblLiveFollowSessions'];
        $haveAll = true;
        foreach ($need as $t) {
            if ((int)$db->query("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'")->fetch_assoc()['c'] < 1) {
                $haveAll = false;
                break;
            }
        }
    } catch (\Throwable $e) {
        $db = null; $haveAll = false;
    }

    if ($db !== null && $haveAll) {
        $db->begin_transaction();
        try {
            $tag = substr((string)mt_rand(), 0, 6);

            /* ---- fixture builders (STRICT mysqli — every NOT-NULL-no-default
                    column supplied; rows rolled back at the end) ------------ */
            $mkOrg = function (string $who, int $isActive = 1) use ($db, $tag): int {
                $n = 'ZZpu2 ' . $who . ' ' . $tag;
                $s = 'zzpu2-' . $who . '-' . $tag . '-' . bin2hex(random_bytes(2));
                $stmt = $db->prepare('INSERT INTO tblOrganisations (Name, Slug, IsActive) VALUES (?, ?, ?)');
                $stmt->bind_param('ssi', $n, $s, $isActive);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            $mkOrgLicence = function (int $orgId, string $number) use ($db): int {
                $stmt = $db->prepare(
                    "INSERT INTO tblOrganisationLicences (OrganisationId, LicenceType, LicenceNumber, IsActive)
                     VALUES (?, 'ccli', ?, 1)"
                );
                $stmt->bind_param('is', $orgId, $number);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            $mkSongbook = function (string $abbr) use ($db, $tag): void {
                $name = 'ZZpu2 book ' . $tag;
                $stmt = $db->prepare('INSERT INTO tblSongbooks (Abbreviation, Name) VALUES (?, ?)');
                $stmt->bind_param('ss', $abbr, $name);
                $stmt->execute();
                $stmt->close();
            };
            $mkSong = function (string $songId, string $abbr, string $ccli) use ($db, $tag): void {
                $title = 'ZZpu2 song ' . $tag;
                $stmt = $db->prepare('INSERT INTO tblSongs (SongId, Title, SongbookAbbr, Ccli) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('ssss', $songId, $title, $abbr, $ccli);
                $stmt->execute();
                $stmt->close();
            };
            $mkSession = function (int $orgId, string $kind, ?string $setlistId, ?string $currentSongId) use ($db): int {
                $code = strtoupper(bin2hex(random_bytes(4))); // <= 12 chars, unique enough for a txn
                $now  = gmdate('Y-m-d H:i:s');
                $chan = 'zztest';
                $stmt = $db->prepare(
                    "INSERT INTO tblLiveFollowSessions
                        (SessionCode, OrgId, SessionKind, Channel, SetlistId, CurrentSongId, StartedAt, LastHeartbeatAt)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('sissssss', $code, $orgId, $kind, $chan, $setlistId, $currentSongId, $now, $now);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };

            /* Count projected rows for one (song, org, session) triple. */
            $projCount = function (string $songId, int $orgId, int $sessionId) use ($db): int {
                $sid = (string)$sessionId;
                $s = $db->prepare(
                    "SELECT COUNT(*) c FROM tblSongUsageEvents
                      WHERE SongId = ? AND OrgId = ? AND UsageContext = 'projected'
                        AND JSON_UNQUOTE(JSON_EXTRACT(MetaJson, '$.sessionId')) = ?"
                );
                $s->bind_param('sis', $songId, $orgId, $sid);
                $s->execute();
                $c = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);
                $s->close();
                return $c;
            };

            /* ---- seed ------------------------------------------------------ */
            $orgA   = $mkOrg('A');
            $olId   = $mkOrgLicence($orgA, '7654321');   // org A's active ccli licence
            $orgNo  = $mkOrg('noLic');                   // active, but NO licence
            $orgIn  = $mkOrg('inact', 0);                // INACTIVE, but WITH a licence
            $mkOrgLicence($orgIn, '1234567');

            $abbr    = 'ZP' . strtoupper(bin2hex(random_bytes(2))); // <= 10 chars
            $ccliSong = $abbr . '-9001';
            $pdSong   = $abbr . '-9002';
            $mkSongbook($abbr);
            $mkSong($ccliSong, $abbr, '1122334'); // has a CCLI number → reportable
            $mkSong($pdSong,   $abbr, '');        // no CCLI number → the D2 default skips it

            /* ---- B1: one row, fully populated ------------------------------ */
            $sess1 = $mkSession($orgA, 'service', 'B-list', null);
            $r1 = projectionUsageLog($orgA, $ccliSong, $sess1, [
                'userId' => null, 'via' => 'bearer', 'channel' => 'zztest',
                'setlistId' => 'B-list', 'venueId' => null, 'orgScheduleId' => null,
                'occurrenceDate' => null,
            ]);
            pu2($r1 === true, 'B1.1 projectionUsageLog() under a licensed org + CCLI song returns true');
            $row = null;
            $rs = $db->prepare(
                "SELECT OrgId, UsageContext, Quantity, LicenceId, SetlistId, MetaJson
                   FROM tblSongUsageEvents
                  WHERE SongId = ? AND UsageContext = 'projected'
                    AND JSON_UNQUOTE(JSON_EXTRACT(MetaJson, '$.sessionId')) = ?
                  ORDER BY Id DESC LIMIT 1"
            );
            $sid1 = (string)$sess1;
            $rs->bind_param('ss', $ccliSong, $sid1);
            $rs->execute();
            $row = $rs->get_result()->fetch_assoc();
            $rs->close();
            $meta = $row ? json_decode((string)$row['MetaJson'], true) : null;
            pu2(
                $row !== null
                    && (int)$row['OrgId'] === $orgA
                    && $row['UsageContext'] === 'projected'
                    && (int)$row['Quantity'] === 1
                    && (int)$row['LicenceId'] === $olId
                    && (string)$row['SetlistId'] === 'B-list'
                    && is_array($meta) && (int)($meta['sessionId'] ?? 0) === $sess1,
                'B1.2 …and writes ONE fully-populated row: OrgId=A, projected, Quantity=1, LicenceId='
                    . $olId . ' (W2 finally sets LicenceId), SetlistId=B-list, MetaJson.sessionId=' . $sess1
                    . ($row === null ? ' (NO ROW)' : ' (got LicenceId=' . var_export($row['LicenceId'], true)
                        . ', SetlistId=' . var_export($row['SetlistId'], true) . ')')
            );

            /* ---- B2: dedup — same (session, song) writes nothing more ------ */
            $r2 = projectionUsageLog($orgA, $ccliSong, $sess1, ['setlistId' => 'B-list']);
            pu2($r2 === true, 'B2.1 a repeat projectionUsageLog() for the SAME (session, song) returns true (already recorded)');
            pu2($projCount($ccliSong, $orgA, $sess1) === 1, 'B2.2 …and does NOT write a second row (dedup per (session, song)) — delete the dedup SELECT or rename the MetaJson key to see this go red');

            /* ---- B3: a second session logs again (dedup is per-session) ---- */
            $sess2 = $mkSession($orgA, 'service', 'B-list', null);
            $r3 = projectionUsageLog($orgA, $ccliSong, $sess2, ['setlistId' => 'B-list']);
            pu2($r3 === true && $projCount($ccliSong, $orgA, $sess2) === 1,
                'B3 a SECOND session (same org+song) writes its OWN row (dedup is per-SESSION, not per-org/day)');

            /* ---- B4: no-licence org / inactive org write nothing ---------- */
            $sessNo = $mkSession($orgNo, 'service', null, null);
            $rNo = projectionUsageLog($orgNo, $ccliSong, $sessNo, []);
            pu2($rNo === false && $projCount($ccliSong, $orgNo, $sessNo) === 0,
                'B4 an org with NO qualifying CCLI licence → false, zero rows (delete the $licence===null guard to see this go red — real org + real CCLI song, so only the gate can stop it)');
            $sessIn = $mkSession($orgIn, 'service', null, null);
            $rIn = projectionUsageLog($orgIn, $ccliSong, $sessIn, []);
            pu2($rIn === false && $projCount($ccliSong, $orgIn, $sessIn) === 0,
                'B4b an INACTIVE org that DOES hold a licence row → still zero rows (o.IsActive=1 on both resolver arms — the closed-org decision)');

            /* ---- B5: integration through serviceMode_applyBroadcast() ------ */
            $sessInt = $mkSession($orgA, 'service', 'B-list', null);
            $rev1 = serviceMode_applyBroadcast($db, $sessInt, $ccliSong, null, null, ['userId' => null, 'via' => 'bearer']);
            pu2($rev1 === 1 && $projCount($ccliSong, $orgA, $sessInt) === 1,
                'B5.1 serviceMode_applyBroadcast() on a service session broadcasts (revision→1) AND writes the projected row (got rev=' . $rev1 . ')');
            $rev2 = serviceMode_applyBroadcast($db, $sessInt, $ccliSong, null, null, ['userId' => null, 'via' => 'bearer']);
            pu2($rev2 === 2 && $projCount($ccliSong, $orgA, $sessInt) === 1,
                'B5.2 a repeat broadcast of the SAME song still bumps the revision (→2, fail-safe) but writes NO new row (change-detection + dedup) (got rev=' . $rev2 . ')');
            $sessHost = $mkSession($orgA, 'host', null, null);
            $revH = serviceMode_applyBroadcast($db, $sessHost, $ccliSong, null, null, ['userId' => null, 'via' => 'bearer']);
            pu2($projCount($ccliSong, $orgA, $sessHost) === 0,
                'B5.3 a HOST-kind session (even with OrgId + a CCLI song) writes ZERO rows (the SessionKind gate — delete === \'service\' to see this go red)');

            /* ---- B6: CCLI-less song writes nothing (D2 default) ------------ */
            $sessPd = $mkSession($orgA, 'service', null, null);
            $rPd = projectionUsageLog($orgA, $pdSong, $sessPd, []);
            pu2($rPd === false && $projCount($pdSong, $orgA, $sessPd) === 0,
                'B6 a song with NO CCLI number → false, zero rows (the D2 default — mirrors the print path)');

            $behaviouralRan = true;
        } finally {
            $db->rollback();
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$passed} passed, {$failures} failed\n");
    exit(1);
}
echo "\n{$passed} passed, 0 failed";
echo $behaviouralRan
    ? " (Part B ran against a live DB).\n"
    : " (Part B SKIPPED: no reachable database with a dbname, or a required table is absent — Part A still ran).\n";
exit(0);
