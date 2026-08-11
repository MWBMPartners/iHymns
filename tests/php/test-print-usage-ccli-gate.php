<?php

declare(strict_types=1);

/**
 * iHymns — CCLI print-usage: writes-only-under-licence + footer-only-under-CCLI (#1767 remainder P5)
 * ============================================================================
 *
 * ELI5: this makes sure the "we quietly wrote down that you printed N copies"
 * feature can NEVER write anything for someone who doesn't hold a real CCLI
 * licence, and that the compliance footer stamped onto a server-built PDF
 * can NEVER appear on a PDF for someone without one either. Two different
 * "only under CCLI" promises, proven two different ways.
 *
 * PART A (always, no DB) — source scan, comment-stripped via the real
 * tokenizer (the `test-ia-reconcile-guards.php` / `test-print-one-renderer.php`
 * precedent — a naive regex would match the very prose explaining the gate):
 *
 *   A1. `printUsageLog()`'s function body calls `printUsageResolveCcliLicence(`
 *       and contains the `=== null` guard's `return false` BEFORE the
 *       `INSERT INTO tblSongUsageEvents` text — i.e. the gate textually
 *       precedes the write, not just "exists somewhere in the function".
 *   A2. Exactly ONE `INSERT INTO tblSongUsageEvents` literal exists anywhere
 *       under `appWeb/public_html` (the ONE-WRITER rule, §6.2 — never a
 *       second, ungated writer bypassing the gate above).
 *   A3. `manage/print-pdf.php`: `printUsageResolveCcliLicence(` is called
 *       BEFORE `ihymnsPdfRender(` — the footer decision is made (from a
 *       RE-RESOLVED licence) before conversion, never left for later.
 *   A4. `manage/print-pdf.php`: `printUsageLog(` is called AFTER
 *       `ihymnsPdfRender(` — the usage log can only fire once a PDF has
 *       actually been produced, never before/regardless of render outcome.
 *   A5. `includes/pdf_renderer.php`: the `SetHTMLFooter` combination step
 *       only appends the `print-ccli-notice` div when `$ccliNotice !== ''`
 *       — the CCLI-notice CONSTRUCTION step (`_pdfSanitiseMetaString`/`trim`
 *       is not involved; the caller pre-builds it) never blindly stamps an
 *       empty/absent notice.
 *
 * PART B (DB, else SKIP) — behavioural:
 *   B1. `printUsageCcliNoticeText('', 'X')` (pure function, no DB) returns
 *       '' — no CCLI number means no footer, independent of any licence.
 *   B2. `printUsageCcliNoticeText('1234567', 'ABC99')` (pure) returns a
 *       non-empty string naming both the CCLI number and the licence key.
 *   B3. `printUsageLog()` for a user ID that resolves to NO qualifying
 *       licence (a random large non-existent id — `getUserEffectiveLicences()`
 *       returns `[]` for it) returns `false` and writes NO row.
 *   B4. `printUsageLog()` for a freshly-inserted user WITH a well-formed
 *       personal CCLI number (a qualifying licence, #1668) returns `true`
 *       AND a matching `tblSongUsageEvents` row (`UsageContext='printed'`,
 *       the requested `Quantity`) exists — inside a transaction, ROLLED BACK
 *       at the end so nothing persists.
 *
 * MUTATION PROOF (rule #34): removing/reordering the A1/A3/A4/A5 markers,
 * or duplicating the INSERT (A2), each turn the corresponding assertion
 * red; B3/B4 go red if the gate in `printUsageLog()` is bypassed (e.g.
 * commenting out the `if ($licence === null) { return false; }` line) —
 * see the commit message for the exact mutations run this session.
 *
 *   php tests/php/test-print-usage-ccli-gate.php
 *         (Part B needs IHYMNS_TEST_DSN with a dbname, or appWeb/.auth/db_credentials.php)
 * Exit: 0 = pass (Part B may SKIP if no DB — Part A still ran), 1 = failure.
 *
 * @see .claude/print-templates-1767-remainder-plan.md §6
 * @see appWeb/public_html/includes/print_usage.php   the ONE writer + the notice-text builder under test
 * @see appWeb/public_html/manage/print-pdf.php        the server-PDF funnel (A3/A4)
 * @see appWeb/public_html/includes/pdf_renderer.php   the enforced-footer combiner (A5)
 * @see tests/php/test-tier-level-source.php           the Part A / Part B shape this mirrors
 */

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/appWeb/public_html';

$failures = 0;
$passed   = 0;
function puc(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Strip comments via the real tokenizer (never a slash-star regex — see
 *  test-print-one-renderer.php's doc-block for the false-positive class a
 *  naive regex hits). */
function pucStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]); // keep line numbers stable
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/** Extract a top-level function's body (the braces are BALANCED, so this
 *  correctly spans nested `if`/`foreach` blocks — unlike a lazy regex). */
function pucFunctionBody(string $src, string $name): ?string
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

$printUsagePath = $publicRoot . '/includes/print_usage.php';
$printPdfPath   = $publicRoot . '/manage/print-pdf.php';
$pdfRendererPath = $publicRoot . '/includes/pdf_renderer.php';

puc(is_file($printUsagePath), 'A0.1 includes/print_usage.php exists');
puc(is_file($printPdfPath), 'A0.2 manage/print-pdf.php exists');
puc(is_file($pdfRendererPath), 'A0.3 includes/pdf_renderer.php exists');
if ($failures > 0) {
    fwrite(STDERR, "\nFATAL: one or more #1767 remainder P5 files are missing — cannot continue.\n");
    exit(1);
}

$printUsageSrc  = pucStripComments((string)file_get_contents($printUsagePath));
$printPdfSrc    = pucStripComments((string)file_get_contents($printPdfPath));
$pdfRendererSrc = pucStripComments((string)file_get_contents($pdfRendererPath));

/* =============================================================================
 * A1 — the gate precedes the write, INSIDE printUsageLog()'s own body.
 * ============================================================================= */
$logBody = pucFunctionBody($printUsageSrc, 'printUsageLog');
puc($logBody !== null, 'A1.0 extracted the printUsageLog() function body');
if ($logBody !== null) {
    $gatePos   = strpos($logBody, 'printUsageResolveCcliLicence(');
    $insertPos = strpos($logBody, 'INSERT INTO tblSongUsageEvents');
    puc($gatePos !== false, 'A1.1 printUsageLog() calls printUsageResolveCcliLicence(');

    /* A1.2 anchors on the COMPOUND pattern
       `if ($licence === null) { return false;` as ONE match, not "any
       return false; somewhere after the resolve call" — the function ALSO
       has an unrelated `if (!_printUsageTableExists($db)) { return false; }`
       existence-gate later in the same body, and a first draft of this
       check matched THAT one instead when the real licence-null guard was
       mutated away, producing a false PASS on a genuine gate bypass (caught
       by mutation-testing this session — see the commit message). */
    $gateMatch = preg_match(
        '/if\s*\(\s*\$licence\s*===\s*null\s*\)\s*\{\s*return\s+false\s*;/',
        $logBody,
        $gm,
        PREG_OFFSET_CAPTURE
    );
    $gateGuardPos = $gateMatch ? $gm[0][1] : false;
    puc(
        $gatePos !== false && $gateGuardPos !== false && $insertPos !== false
            && $gatePos < $gateGuardPos && $gateGuardPos < $insertPos,
        'A1.2 printUsageLog() has an "if ($licence === null) { return false; }" guard (the ACTUAL gate, '
            . 'not any other return-false in the function) that appears AFTER the '
            . 'printUsageResolveCcliLicence() call and BEFORE "INSERT INTO tblSongUsageEvents" — the write '
            . 'can never be reached without a qualifying licence'
    );
}

/* =============================================================================
 * A2 — exactly ONE writer of tblSongUsageEvents in the whole tree.
 * ============================================================================= */
function pucAllPhpFiles(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) { return $out; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $out[] = $f->getPathname(); }
    }
    return $out;
}
$writerSites = [];
foreach (pucAllPhpFiles($publicRoot) as $f) {
    $stripped = pucStripComments((string)file_get_contents($f));
    if (str_contains($stripped, 'INSERT INTO tblSongUsageEvents')) {
        $writerSites[] = ltrim(str_replace($publicRoot, '', $f), '/');
    }
}
puc(
    $writerSites === ['includes/print_usage.php'],
    'A2 exactly ONE file writes "INSERT INTO tblSongUsageEvents" — includes/print_usage.php'
        . (count($writerSites) === 1 && $writerSites === ['includes/print_usage.php'] ? '' : ' (found: ' . (implode(', ', $writerSites) ?: 'none') . ')')
);

/* =============================================================================
 * A3/A4 — manage/print-pdf.php: licence resolved BEFORE conversion; the
 * usage log fires only AFTER a PDF was actually produced.
 * ============================================================================= */
$pdfLicencePos = strpos($printPdfSrc, 'printUsageResolveCcliLicence(');
$pdfRenderPos  = strpos($printPdfSrc, 'ihymnsPdfRender(');
$pdfLogPos     = strpos($printPdfSrc, 'printUsageLog(');
puc($pdfLicencePos !== false, 'A3.0 manage/print-pdf.php calls printUsageResolveCcliLicence(');
puc($pdfRenderPos !== false, 'A3.1 manage/print-pdf.php calls ihymnsPdfRender(');
puc(
    $pdfLicencePos !== false && $pdfRenderPos !== false && $pdfLicencePos < $pdfRenderPos,
    'A3.2 printUsageResolveCcliLicence() is called BEFORE ihymnsPdfRender() — the footer decision is '
        . 'made from a re-resolved licence before conversion, never smuggled in after the fact'
);
puc(
    $pdfLogPos !== false && $pdfRenderPos !== false && $pdfRenderPos < $pdfLogPos,
    'A4 printUsageLog() is called AFTER ihymnsPdfRender() in manage/print-pdf.php — the usage log can '
        . 'only fire once a PDF has actually been produced'
);

/* =============================================================================
 * A5 — includes/pdf_renderer.php: the ccli-notice footer piece is appended
 * ONLY behind a non-empty check.
 * ============================================================================= */
puc(
    (bool)preg_match('/\$ccliNotice\s*!==\s*[\'"]{2}\s*\)\s*\{[^}]*print-ccli-notice/s', $pdfRendererSrc),
    'A5 includes/pdf_renderer.php only appends the print-ccli-notice footer div inside an '
        . "if (\$ccliNotice !== '') block — never unconditionally"
);

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

    require_once $printUsagePath;

    /* B1/B2 — pure function, no DB touch at all despite the require above
       (getDbMysqli() connects lazily, only when actually CALLED). */
    puc(printUsageCcliNoticeText('', 'ABC99') === '', 'B1 printUsageCcliNoticeText(\'\', …) returns \'\' — no CCLI number, no footer');
    $sampleNotice = printUsageCcliNoticeText('1234567', 'ABC99');
    puc(
        str_contains($sampleNotice, '1234567') && str_contains($sampleNotice, 'ABC99') && $sampleNotice !== '',
        'B2 printUsageCcliNoticeText(\'1234567\', \'ABC99\') names both the CCLI number and the licence key'
    );

    try {
        $db = getDbMysqli();
        $hasUsage = (bool)$db->query("SHOW TABLES LIKE 'tblSongUsageEvents'")->num_rows;
        $hasUsers = (bool)$db->query("SHOW TABLES LIKE 'tblUsers'")->num_rows;
    } catch (\Throwable $e) {
        $db = null; $hasUsage = false; $hasUsers = false;
    }

    if ($db !== null && $hasUsage && $hasUsers) {
        $db->begin_transaction();
        try {
            /* A REAL, existing SongId — used for BOTH B3 and B4 — is
               load-bearing, not a convenience: B3's whole point is proving
               the LICENCE gate refuses the write, and an invalid/foreign-
               key-violating SongId would make ANY write attempt fail for an
               unrelated reason, masking a bypassed gate behind a DB
               constraint error instead of the gate itself (caught by
               mutation-testing this session — an earlier draft used a
               fabricated SongId here, and B3 stayed GREEN even after the
               `if ($licence === null) { return false; }` line was deleted
               from printUsageLog(), because the FK violation failed the
               INSERT anyway for an unrelated reason). Skip Part B entirely
               (gracefully) if the test DB has no songs at all to borrow one
               from. */
            $songRow = $db->query('SELECT SongId FROM tblSongs LIMIT 1')->fetch_assoc();
            if ($songRow === null) {
                puc(true, 'B3/B4 SKIPPED (no songs in the test DB to satisfy the FK) — B1/B2 still ran');
                $behaviouralRan = true;
            } else {
                $bSongId = (string)$songRow['SongId'];

                /* B3 — a REAL, existing user row with NO CcliNumber (and no
                   org membership at all, so no org-licence branch can reach
                   it either) resolves to NO qualifying licence, so the gate
                   must refuse the write. Both this user AND the SongId are
                   REAL rows — a bogus/non-existent id of EITHER kind would
                   let the schema's own FK constraints reject the INSERT for
                   an unrelated reason, masking a bypassed licence gate
                   behind a DB error instead of exercising it (caught by
                   mutation-testing this session in two separate rounds: a
                   fabricated SongId first, then a fabricated UserId — see
                   the commit message for both). */
                $b3Username = 'zzpu1767u' . substr((string)mt_rand(), 0, 6);
                $b3Email    = $b3Username . '@example.invalid';
                $b3NoCcli   = '';
                $ins3 = $db->prepare(
                    "INSERT INTO tblUsers (Username, Email, EmailVerified, PasswordHash, DisplayName, Role, CcliNumber, IsActive)
                     VALUES (?, ?, 0, 'x', ?, 'user', ?, 1)"
                );
                $ins3->bind_param('ssss', $b3Username, $b3Email, $b3Username, $b3NoCcli);
                $ins3->execute();
                $b3UserId = (int)$db->insert_id;
                $ins3->close();

                $countBefore = (int)($db->query('SELECT COUNT(*) c FROM tblSongUsageEvents')->fetch_assoc()['c'] ?? 0);
                $b3Result = printUsageLog($b3UserId, $bSongId, 3, ['surface' => 'browser']);
                $countAfterB3 = (int)($db->query('SELECT COUNT(*) c FROM tblSongUsageEvents')->fetch_assoc()['c'] ?? 0);
                puc($b3Result === false, 'B3.1 printUsageLog() for a REAL, existing user with NO qualifying licence returns false');
                puc($countAfterB3 === $countBefore, 'B3.2 …and writes NO row (tblSongUsageEvents row count unchanged) — real user + real song, so only the licence gate could have stopped it');

                /* B4 — a real, qualifying (well-formed personal CCLI number,
                   #1668) user DOES get logged. Reuses the SAME real SongId. */
                $b4Username = 'zzpu1767' . substr((string)mt_rand(), 0, 6);
                $b4Email    = $b4Username . '@example.invalid';
                $b4Ccli     = '7654321'; // well-formed per validateCcliNumber() (#1668)
                $ins = $db->prepare(
                    "INSERT INTO tblUsers (Username, Email, EmailVerified, PasswordHash, DisplayName, Role, CcliNumber, IsActive)
                     VALUES (?, ?, 0, 'x', ?, 'user', ?, 1)"
                );
                $ins->bind_param('ssss', $b4Username, $b4Email, $b4Username, $b4Ccli);
                $ins->execute();
                $b4UserId = (int)$db->insert_id;
                $ins->close();

                $b4Result = printUsageLog($b4UserId, $bSongId, 5, ['surface' => 'pdf']);
                puc($b4Result === true, 'B4.1 printUsageLog() for a user WITH a well-formed personal CCLI number returns true');

                $rowStmt = $db->prepare(
                    "SELECT Quantity, UsageContext FROM tblSongUsageEvents
                      WHERE UserId = ? AND SongId = ? ORDER BY Id DESC LIMIT 1"
                );
                $rowStmt->bind_param('is', $b4UserId, $bSongId);
                $rowStmt->execute();
                $b4Row = $rowStmt->get_result()->fetch_assoc();
                $rowStmt->close();
                puc(
                    $b4Row !== null && (int)$b4Row['Quantity'] === 5 && $b4Row['UsageContext'] === 'printed',
                    'B4.2 …and writes exactly ONE row with Quantity=5, UsageContext=\'printed\''
                        . ($b4Row === null ? ' (no row found)' : ' (found Quantity=' . $b4Row['Quantity'] . ', UsageContext=' . $b4Row['UsageContext'] . ')')
                );
                $behaviouralRan = true;
            }
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
    : " (Part B SKIPPED: no reachable database, or tblSongUsageEvents/tblUsers absent — Part A still ran).\n";
exit(0);
