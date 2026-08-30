<?php

declare(strict_types=1);

/**
 * iHymns — resolveEffectiveTier() licence-expiry parity guard (F-2, #2020)
 * ==========================================================================
 *
 * ELI5
 * ----
 * "Does this organisation's licence still confer its tier?" must have exactly
 * ONE answer, checked the SAME way everywhere an expiry date exists. This file
 * proves that for `resolveEffectiveTier()` (`includes/ccli_validator.php`) — the
 * function that decides which content tier (public / free / ccli / premium /
 * pro) a user gets from their organisation memberships.
 *
 * THE BUG THIS GUARDS (F-2, 2026-08-30 correctness review, #2020)
 * -----------------------------------------------------------------
 * #1969 taught `resolveEffectiveTier()` to stop conferring a tier from an
 * EXPIRED organisation licence — but only wired that expiry check onto ONE of
 * the THREE places this function reads an org's `LicenceType`:
 *
 *   1. the recursive-CTE's LEGACY-COLUMN arm (`tblOrganisations.LicenceType`)
 *   2. the recursive-CTE's JOIN-TABLE arm (`tblOrganisationLicences`, #640)  <- #1969 fixed THIS one
 *   3. the MySQL-<8 fallback query (used when `WITH RECURSIVE` isn't supported)
 *
 * Arms 1 and 3 kept conferring a tier from an org's legacy-column licence
 * FOREVER, even years past the `LicenceExpiresAt` an admin had typed into the
 * org's own licence field — while `getUserEffectiveLicences()`
 * (`includes/licences.php` branch (e)) correctly filtered that exact same
 * column. So the "the two gates agree on expiry" parity #1969 states it
 * restored was only half-delivered. Fixed by carrying `LicenceExpiresAt`
 * through the CTE and adding the SAME `(...IS NULL OR ...> NOW())` predicate
 * to arms 1 and 3 (ccli_validator.php's own inline comments on each arm name
 * this fix and this file).
 *
 * TWO PARTS, MIRRORING THIS REPO'S ESTABLISHED SHAPE (rule #35's own guards,
 * `tests/php/test-shared-setlist-expiry.php` / `test-live-follow-idle.php`)
 * ------------------------------------------------------------------------
 * PART A — SOURCE-SHAPE, always runs, no database needed. Uses the shared
 *   `php_source_units.php` library to pull every string literal that lives
 *   INSIDE `resolveEffectiveTier()` (never a raw file-wide grep, which
 *   could be satisfied by a doc-block QUOTING the SQL rather than the SQL
 *   itself) and proves EVERY arm that filters `LicenceType <> 'none'` also
 *   carries an `ExpiresAt`/`LicenceExpiresAt` predicate nearby in the SAME
 *   statement. This is the "the must" per the F-2 harness brief: it is what
 *   actually catches a future edit that reopens the gap, on every CI run,
 *   without needing a database.
 * PART B — BEHAVIOURAL, skipped (not failed) when no application database is
 *   reachable. Seeds a real expired legacy-column CCLI org (and, if the
 *   #640 table exists, a real expired join-table CCLI licence too) inside a
 *   transaction that is ROLLED BACK at the end, and calls the REAL
 *   `resolveEffectiveTier()` against them — proving the fix's actual,
 *   end-to-end, DB-level behaviour, not just its source shape.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * -------------------------------------
 * Part A's assertions are proven able to fail by re-running the SAME analysis
 * function (`ctepFindLicenceTypeArms()`) against a MUTATED COPY of the real
 * source TEXT (a PHP string held in memory — the tracked file on disk is never
 * touched). Because Part A only ever reads and pattern-matches source text
 * (never executes it), the mutation proof needs no subprocess/sibling-file
 * dance the way `tests/php/test-outbound-ssrf-guard.php` needs for its
 * *executable* mutation — a plain in-process re-analysis is sufficient and
 * exact.
 *
 *   php tests/php/test-ccli-tier-expiry-parity.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 *
 * @see appWeb/public_html/includes/ccli_validator.php   resolveEffectiveTier() — the function under test
 * @see appWeb/public_html/includes/licences.php          getUserEffectiveLicences() branch (e) — the parity target
 * @see tests/php/lib/php_source_units.php                the shared per-function source-view library
 * @see tests/php/test-live-follow-idle.php                the DB-fixture/rollback pattern Part B mirrors
 */

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/appWeb/public_html';
$ccliFile   = $publicRoot . '/includes/ccli_validator.php';

require_once __DIR__ . '/lib/php_source_units.php';

$passed = 0;
$failed = 0;

function ctepOk(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        return;
    }
    $failed++;
    echo "  \xE2\x9D\x8C {$label}\n";
}

/**
 * ELI5: find every place in `resolveEffectiveTier()`'s own SQL text that
 * reads an organisation's `LicenceType` (the pattern `LicenceType <> 'none'`
 * — every one of the three arms described in this file's header uses this
 * EXACT literal to exclude an org with no licence), and say whether an
 * `ExpiresAt` predicate appears close by in the SAME statement.
 *
 * WHY A WINDOW, NOT "ANYWHERE IN THE STRING": the three arms all live inside
 * the SAME big multi-line SQL string (`$sqlRecursive`) or a sibling one (the
 * fallback query) — checking "does ExpiresAt appear ANYWHERE in the whole
 * function" would pass the moment ONE arm had it, hiding the other two. A
 * window bounded by the NEXT `LicenceType <> 'none'` marker (or a hard cap)
 * keeps each arm's check scoped to that arm's own statement — mirroring this
 * repo's own documented lesson (rule #34) that a regex window sized too
 * small under-reports (`test-editor-api2-contract.php`'s 120->300 char fix).
 * 400 chars comfortably covers every arm here (the longest is ~260 chars
 * from its marker to its closing paren) with headroom for a slightly longer
 * predicate before this needs revisiting.
 *
 * @param string $ccliSrc The FULL text of ccli_validator.php (or a mutated
 *                         copy of it — this function is pure source analysis,
 *                         it never executes anything).
 * @return list<array{marker:string,hasExpiry:bool}> One entry per arm found,
 *         in source order.
 */
function ctepFindLicenceTypeArms(string $ccliSrc): array
{
    $units = phpSourceUnits($ccliSrc);
    $fnStrings = $units['resolveEffectiveTier']['strings'] ?? [];
    if ($fnStrings === []) {
        return [];                      // function not found / not parseable — caller asserts on this
    }
    $combined = implode("\n", $fnStrings);

    /* php_source_units' `strings` view deliberately preserves a SQL string's
     * full CONTENT verbatim (it is real string data, not PHP source) — which
     * means the SQL-style `/* ... *\/` doc-comments INSIDE $sqlRecursive
     * (this very file's own annotations on each arm, which legitimately say
     * things like "honour ExpiresAt so an EXPIRED org licence stops
     * conferring a tier") come along too. Left in, that prose satisfies THIS
     * file's own proximity check for a NEIGHBOURING arm that has not yet
     * reached its real predicate — the exact "prose satisfies the assertion"
     * trap php_source_units.php's own doc-block warns about (#1688), just
     * one layer deeper (SQL-comment prose inside a string, not PHP-comment
     * prose around code). Stripping SQL block comments before scanning
     * leaves only the real, executable WHERE-clause text. */
    $combined = (string)preg_replace('#/\*.*?\*/#s', '', $combined);

    $window = 400;
    $out = [];
    if (preg_match_all(
        '/(?:[\w]+\.)?LicenceType\s*<>\s*\'none\'/i',
        $combined,
        $matches,
        PREG_OFFSET_CAPTURE
    )) {
        $positions = $matches[0];
        $count = count($positions);
        for ($i = 0; $i < $count; $i++) {
            [$marker, $offset] = $positions[$i];
            $nextOffset = $i + 1 < $count ? $positions[$i + 1][1] : strlen($combined);
            $sliceEnd = min($offset + $window, $nextOffset);
            $slice = substr($combined, $offset, $sliceEnd - $offset);
            $out[] = [
                'marker'    => $marker,
                'hasExpiry' => stripos($slice, 'ExpiresAt') !== false,
            ];
        }
    }
    return $out;
}

echo "\nresolveEffectiveTier() licence-expiry parity guard (F-2, #2020)\n\n";

/* =========================================================================
 * PART A — SOURCE-SHAPE (always runs, no database needed)
 * ========================================================================= */
$ccliSrc = (string)file_get_contents($ccliFile);
$arms = ctepFindLicenceTypeArms($ccliSrc);

/* Derived from the source, not hand-typed: today there are exactly THREE
 * places resolveEffectiveTier() filters an org's LicenceType (see this
 * file's header). Asserting the COUNT (not just "every found arm passes")
 * means a future refactor that silently drops an arm's LicenceType filter
 * entirely — rather than merely its expiry predicate — is also caught,
 * instead of vacuously passing an empty check. */
ctepOk("found the expected 3 LicenceType-filtering arms in resolveEffectiveTier() (found " . count($arms) . ")",
    count($arms) === 3);

foreach ($arms as $i => $arm) {
    $n = $i + 1;
    ctepOk("arm {$n} (\"{$arm['marker']}\") carries an ExpiresAt/LicenceExpiresAt predicate in the same statement",
        $arm['hasExpiry']);
}

/* MUTATION SELF-TEST: prove ctepFindLicenceTypeArms() can actually go red,
 * one arm at a time, by removing just that arm's predicate from an
 * in-memory copy of the real source text (the tracked file is never
 * touched) and re-running the SAME analysis function against it. */
$legacyArmPredicate = "AND (LicenceExpiresAt IS NULL OR LicenceExpiresAt > NOW())";
$joinArmPredicate    = "AND (ol.ExpiresAt IS NULL OR ol.ExpiresAt > NOW())";
$fallbackPredicate   = "AND (o.LicenceExpiresAt IS NULL OR o.LicenceExpiresAt > NOW())";

$mutations = [
    'legacy-column CTE arm (the exact F-2 bug)' => $legacyArmPredicate,
    'join-table CTE arm (the #1969 fix — proves the guard also covers what already worked)' => $joinArmPredicate,
    'MySQL-<8 fallback query (the other half of the F-2 bug)' => $fallbackPredicate,
];

foreach ($mutations as $label => $needle) {
    ctepOk("MUTATION setup sanity: the {$label}'s predicate text is present in real source (so removing it is a real edit)",
        str_contains($ccliSrc, $needle));

    $mutatedSrc = str_replace($needle, '/* MUTATED: predicate removed */', $ccliSrc);
    $mutatedArms = ctepFindLicenceTypeArms($mutatedSrc);
    $mutatedAllHaveExpiry = $mutatedArms !== [] && count($mutatedArms) === count(array_filter($mutatedArms, fn($a) => $a['hasExpiry']));

    ctepOk("MUTATION PROOF: removing the {$label}'s predicate makes the guard report a missing-expiry arm",
        !$mutatedAllHaveExpiry);
}

/* =========================================================================
 * PART B — BEHAVIOURAL (DB, else SKIP)
 * ========================================================================= */
echo "\nPart B — behavioural (real seeded orgs, rolled back)\n";

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

    require_once $publicRoot . '/includes/ccli_validator.php';

    try {
        $db = getDbMysqli();
        $need = ['tblUsers', 'tblOrganisations', 'tblOrganisationMembers', 'tblAccessTiers'];
        $haveAll = true;
        foreach ($need as $t) {
            $row = $db->query("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'")->fetch_assoc();
            if ((int)($row['c'] ?? 0) < 1) {
                $haveAll = false;
                break;
            }
        }
        $haveJoinTable = (int)($db->query("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblOrganisationLicences'")->fetch_assoc()['c'] ?? 0) >= 1;
    } catch (\Throwable $e) {
        $db = null; $haveAll = false; $haveJoinTable = false;
    }

    if ($db !== null && $haveAll) {
        $db->begin_transaction();
        try {
            /* Fixture helpers — mirror test-live-follow-idle.php's mkUser/mkOrg
               shape exactly (the established idiom for this file family). */
            $mkUser = function () use ($db): int {
                $tag = 'zz2020_' . bin2hex(random_bytes(4));
                $stmt = $db->prepare(
                    'INSERT INTO tblUsers (Username, Email, EmailVerified, PasswordHash, DisplayName, Role, CcliNumber)
                     VALUES (?, ?, 0, \'x\', ?, \'user\', \'\')'
                );
                $email = $tag . '@example.invalid';
                $stmt->bind_param('sss', $tag, $email, $tag);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            /* $legacyLicenceType/$legacyExpiresAt let ONE helper build every
               legacy-column scenario (live, expired, none) — no copy-paste
               per case. */
            $mkOrg = function (string $legacyLicenceType, ?string $legacyExpiresAt) use ($db): int {
                $tag = 'zz2020-' . bin2hex(random_bytes(4));
                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisations (Name, Slug, IsActive, LicenceType, LicenceExpiresAt)
                     VALUES (?, ?, 1, ?, ?)'
                );
                $name = 'ZZ #2020 ' . $tag;
                $stmt->bind_param('ssss', $name, $tag, $legacyLicenceType, $legacyExpiresAt);
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
                return $id;
            };
            $join = function (int $userId, int $orgId) use ($db): void {
                $stmt = $db->prepare('INSERT INTO tblOrganisationMembers (UserId, OrgId, Role) VALUES (?, ?, \'member\')');
                $stmt->bind_param('ii', $userId, $orgId);
                $stmt->execute();
                $stmt->close();
            };
            $mkOrgLicenceRow = function (int $orgId, string $licenceType, ?string $expiresAt) use ($db): void {
                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisationLicences (OrganisationId, LicenceType, LicenceNumber, IsActive, ExpiresAt)
                     VALUES (?, ?, \'\', 1, ?)'
                );
                $stmt->bind_param('iss', $orgId, $licenceType, $expiresAt);
                $stmt->execute();
                $stmt->close();
            };

            $past   = gmdate('Y-m-d H:i:s', time() - 86400);   // yesterday
            $future = gmdate('Y-m-d H:i:s', time() + 86400 * 365); // a year from now

            /* B1 — THE F-2 BUG ITSELF: a legacy-column CCLI licence that
               EXPIRED yesterday must NOT confer the 'ccli' tier. A fresh
               user's personal AccessTier defaults to 'free' (schema.sql),
               which ranks above the unlicensed org's 'public' — so the
               correct effective tier is exactly 'free', not 'ccli'. */
            $uB1 = $mkUser();
            $oB1 = $mkOrg('ccli', $past);
            $join($uB1, $oB1);
            $rB1 = resolveEffectiveTier($uB1);
            ctepOk("B1 an EXPIRED legacy-column 'ccli' org licence does NOT confer the ccli tier (the F-2 bug) — got '{$rB1}', want 'free'",
                $rB1 === 'free');

            /* B2 — sanity twin of B1: a LIVE (future-expiry) legacy-column
               CCLI licence must STILL confer 'ccli' — proves the fix adds a
               filter, it doesn't just always return the fallback. */
            $uB2 = $mkUser();
            $oB2 = $mkOrg('ccli', $future);
            $join($uB2, $oB2);
            $rB2 = resolveEffectiveTier($uB2);
            ctepOk("B2 a LIVE legacy-column 'ccli' org licence still confers the ccli tier (fix doesn't over-block) — got '{$rB2}', want 'ccli'",
                $rB2 === 'ccli');

            /* B3 — a NEVER-EXPIRES (NULL) legacy licence must also still
               confer its tier (NULL means "no expiry", not "expired"). */
            $uB3 = $mkUser();
            $oB3 = $mkOrg('ccli', null);
            $join($uB3, $oB3);
            $rB3 = resolveEffectiveTier($uB3);
            ctepOk("B3 a NULL-expiry (never expires) legacy-column 'ccli' org licence still confers the ccli tier — got '{$rB3}', want 'ccli'",
                $rB3 === 'ccli');

            if ($haveJoinTable) {
                /* B4 — the PRE-EXISTING #1969 join-table arm, exercised
                   end-to-end here too so this file is the ONE place both
                   arms' behaviour is pinned together (no regression from
                   this fix touching the join-table arm's SELECT list). */
                $uB4 = $mkUser();
                $oB4 = $mkOrg('none', null); // no legacy-column licence at all
                $join($uB4, $oB4);
                $mkOrgLicenceRow($oB4, 'ccli', $past);
                $rB4 = resolveEffectiveTier($uB4);
                ctepOk("B4 an EXPIRED join-table (#640) 'ccli' licence does NOT confer the ccli tier — got '{$rB4}', want 'free'",
                    $rB4 === 'free');
            } else {
                echo "  SKIP  B4 (join-table arm): tblOrganisationLicences does not exist on this database yet.\n";
            }

            $behaviouralRan = true;
        } finally {
            $db->rollback();
        }
    } else {
        echo "  SKIP  required tables (tblUsers/tblOrganisations/tblOrganisationMembers/tblAccessTiers) not all present.\n";
    }
} else {
    echo "  SKIP  no application database reachable (no db_credentials.php / no dbname on IHYMNS_TEST_DSN,\n";
    echo "        or a socket-only DSN, which resolveEffectiveTier()'s own getDbMysqli() cannot use — TCP only).\n";
    echo "        Set IHYMNS_TEST_DSN and/or configure appWeb/.auth/db_credentials.php to exercise Part B.\n";
}

/* =========================================================================
 * REPORT
 * ========================================================================= */
echo "\n{$passed} passed, {$failed} failed";
echo $behaviouralRan ? " (Part B behavioural fixtures ran against a live DB).\n" : " (Part B skipped — see SKIP line(s) above).\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
