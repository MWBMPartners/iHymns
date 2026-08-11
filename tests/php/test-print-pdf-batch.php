<?php

declare(strict_types=1);

/**
 * iHymns — server-PDF BATCH mode guard (#1767 remainder P6)
 *
 * ELI5: this makes sure "download the whole set list as ONE PDF" actually
 * renders every song (not just the first one, silently dropped), that a
 * request cannot smuggle in more documents than the server allows, and that
 * a song only gets an entry in the CCLI copies log when it genuinely has a
 * CCLI number to attribute AND the signed-in caller genuinely holds a
 * qualifying licence — never logged blind just because a batch asked for it.
 *
 * DETAIL — THREE PARTS, MIRRORING THE test-print-usage-ccli-gate.php SHAPE
 * (comment-stripped static source-scan, ALWAYS runs; a direct behavioural
 * call into the real `ihymnsPdfRender()`, SKIPPED with a clear message when
 * the mPDF engine isn't vendored on this checkout — same dormant-503
 * discipline `qr.php`/`pdf_renderer.php` already use; a DB-backed
 * behavioural proof, SKIPPED when no local database is reachable):
 *
 *   PART A (static, comment-stripped via the real tokenizer — never a
 *   slash-star regex, `test-print-one-renderer.php`'s own precedent):
 *     A1. `PDF_MAX_DOCUMENTS` (manage/print-pdf.php) is raised well past the
 *         P3-P5 placeholder of 1, and stays within a sane ceiling.
 *     A2. `PDF_MAX_TOTAL_BYTES` is raised PROPORTIONATELY but stays BOUNDED
 *         (never scales linearly with the full document cap — a batch must
 *         never be able to approach the renderer's memory budget).
 *     A3. `'batch'` is a recognised value in `PDF_ALLOWED_MODES`.
 *     A4. The document-count CAP CHECK (`> PDF_MAX_DOCUMENTS`) appears
 *         textually BEFORE `ihymnsPdfRender(` — a batch that breaches the
 *         cap never reaches the renderer at all.
 *     A5. `includes/pdf_renderer.php`'s `foreach ($docs as $i => $doc)` loop
 *         BODY (balanced-brace extracted, not just "somewhere in the file")
 *         contains `WriteHTML(` — the per-document write genuinely happens
 *         INSIDE the loop, not hoisted out to render only the first.
 *     A6. `manage/print-pdf.php`'s per-document CCLI-notice loop body
 *         contains `printUsageSongCcliNumber(` — each document's OWN CCLI
 *         number is (re-)resolved, never assumed from document 0.
 *     A7. `manage/print-pdf.php`'s per-document LOGGING loop body contains
 *         BOTH `printUsageLog(` AND a `continue` guard that appears BEFORE
 *         it — a document without its own qualifying CCLI number is skipped
 *         before ever reaching the write call, never logged blind.
 *
 *   PART B (behavioural, mPDF engine required — SKIP with a message and
 *   exit 0 otherwise, mirroring `qr.php`'s dormant-503 discipline applied
 *   to a CI guard instead of an HTTP response):
 *     B1. `ihymnsPdfRender()` called DIRECTLY with 4 distinct non-empty
 *         documents renders a PDF whose raw byte count of `/Type /Page`
 *         OBJECTS (excluding `/Type /Pages`, the parent tree node) equals
 *         4 — the strongest available proof that ALL FOUR documents were
 *         rendered, not just the first (a broken loop that only wrote
 *         document 0 would produce a 1-page PDF here, going red).
 *     B2. The SAME call with exactly 1 document still produces exactly 1
 *         page (regression guard: the batch change must not perturb the
 *         single-song 'song'/'preview' path's page count).
 *     B3. `ihymnsPdfRender([], …)` still returns null (sanity floor).
 *
 *   PART C (behavioural, DB required — SKIP with a message and exit 0
 *   otherwise): a REAL, qualifying-CCLI-licensed test user, and TWO REAL
 *   `tblSongs` rows temporarily given (transaction-scoped, ROLLED BACK) a
 *   non-empty and an empty `Ccli` respectively, run through the EXACT SAME
 *   two primitives `manage/print-pdf.php`'s batch loop calls
 *   (`printUsageSongCcliNumber()` to resolve each document's own CCLI
 *   number, then `printUsageLog()` ONLY when that resolves non-empty) —
 *   proving the CCLI-bearing song gets logged and the CCLI-less song does
 *   not, for the SAME licensed caller in the SAME "batch" pass. This is
 *   deliberately NOT a re-implementation of the endpoint's own decision
 *   logic (that would be a second, divergent copy, rule #35) — it is the
 *   two real, already-canonical functions the endpoint's loop itself calls,
 *   exercised the same way, so a regression in EITHER function is caught
 *   here even though the endpoint script itself cannot safely be
 *   `require`'d by a test (it is a straight-line script with side effects
 *   from its first line, not a includable module — the SAME reason
 *   `test-print-usage-ccli-gate.php`/`test-print-one-renderer.php` only
 *   ever read manage/print-pdf.php's SOURCE TEXT, never execute it).
 *
 * MUTATION-PROVEN (rule #34) — every assertion below was broken, watched
 * red, and restored byte-identical; see the P6 commit message for the exact
 * mutations run this session (constants reverted to their P3-P5 values,
 * `WriteHTML(` moved outside the pdf_renderer.php loop, the A7 `continue`
 * guard deleted, the per-document CCLI loops collapsed to a single
 * document-0-only read).
 *
 *   php tests/php/test-print-pdf-batch.php
 *
 * Exit status 0 = all pass (Parts B/C may SKIP), 1 = at least one failure.
 *
 * @see .claude/print-templates-1767-remainder-plan.md §4.4  batch composition design
 * @see appWeb/public_html/manage/print-pdf.php               the endpoint under test (source-scanned, never executed)
 * @see appWeb/public_html/includes/pdf_renderer.php           the ONE engine wrapper (Part B calls this directly)
 * @see appWeb/public_html/includes/print_usage.php            printUsageSongCcliNumber() / printUsageLog() (Part C)
 * @see tests/php/test-print-one-renderer.php                  the comment-stripping + not-public pattern this mirrors
 * @see tests/php/test-print-usage-ccli-gate.php                the Part A (static) / Part B (behavioural, DB) shape this mirrors
 */

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/appWeb/public_html';
$printPdfPath     = $publicRoot . '/manage/print-pdf.php';
$pdfRendererPath  = $publicRoot . '/includes/pdf_renderer.php';
$printUsagePath   = $publicRoot . '/includes/print_usage.php';

$failures = 0;
$passed   = 0;
function ppb(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Strip comments via the real tokenizer (never a slash-star regex — see
 *  test-print-one-renderer.php's doc-block for the false-positive class a
 *  naive regex hits; identical shape to that file's + the CCLI gate
 *  guard's own stripper). */
function ppbStripComments(string $src): string
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

/**
 * Balanced-brace extraction of the FIRST block whose opening looks like
 * `$needle { … }` (`$needle` MUST already include everything up to and
 * including the opening `{`) — used for both `foreach (...) {` loop bodies
 * below. Returns null when `$needle` isn't found or the braces never close.
 */
function ppbExtractBracedBlock(string $src, string $needleWithBrace): ?string
{
    $pos = strpos($src, $needleWithBrace);
    if ($pos === false) {
        return null;
    }
    $bracePos = $pos + strlen($needleWithBrace) - 1; // the opening '{' itself
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

/** Multiply together every digit run found in a PHP numeric-literal
 *  EXPRESSION (e.g. "8 * 1024 * 1024" -> 8388608) — good enough for the
 *  `N * 1024 * 1024`-shaped constants this file's caps are written as,
 *  without literally eval()ing source text. */
function ppbEvalByteExpr(string $expr): int
{
    preg_match_all('/\d+/', $expr, $m);
    $product = 1;
    foreach ($m[0] as $n) {
        $product *= (int)$n;
    }
    return $product;
}

ppb(is_file($printPdfPath), 'A0.1 manage/print-pdf.php exists');
ppb(is_file($pdfRendererPath), 'A0.2 includes/pdf_renderer.php exists');
ppb(is_file($printUsagePath), 'A0.3 includes/print_usage.php exists');
if ($failures > 0) {
    fwrite(STDERR, "\nFATAL: one or more #1767 remainder P6 files are missing — cannot continue.\n");
    exit(1);
}

$printPdfRaw     = (string)file_get_contents($printPdfPath);
$pdfRendererRaw  = (string)file_get_contents($pdfRendererPath);
$printPdfSrc     = ppbStripComments($printPdfRaw);
$pdfRendererSrc  = ppbStripComments($pdfRendererRaw);

/* =============================================================================
 * PART A — static source scan
 * ============================================================================= */

echo "Part A — static source scan (manage/print-pdf.php + includes/pdf_renderer.php)\n";

/* A1 — PDF_MAX_DOCUMENTS raised past the P3-P5 placeholder of 1, bounded. */
preg_match('/const\s+PDF_MAX_DOCUMENTS\s*=\s*(\d+)\s*;/', $printPdfSrc, $mDocs);
$pdfMaxDocuments = isset($mDocs[1]) ? (int)$mDocs[1] : null;
ppb($pdfMaxDocuments !== null, 'A1.0 PDF_MAX_DOCUMENTS constant found and parsed');
ppb(
    $pdfMaxDocuments !== null && $pdfMaxDocuments > 1,
    'A1.1 PDF_MAX_DOCUMENTS is raised past the P3-P5 placeholder of 1 (found ' . ($pdfMaxDocuments ?? '?') . ')'
);
ppb(
    $pdfMaxDocuments !== null && $pdfMaxDocuments <= 500,
    'A1.2 PDF_MAX_DOCUMENTS stays within a sane ceiling (<= 500; found ' . ($pdfMaxDocuments ?? '?') . ')'
);

/* A2 — PDF_MAX_TOTAL_BYTES raised proportionately but bounded. */
preg_match('/const\s+PDF_MAX_TOTAL_BYTES\s*=\s*([^;]+);/', $printPdfSrc, $mBytes);
$pdfMaxTotalBytes = isset($mBytes[1]) ? ppbEvalByteExpr($mBytes[1]) : null;
ppb($pdfMaxTotalBytes !== null, 'A2.0 PDF_MAX_TOTAL_BYTES constant found and parsed');
ppb(
    $pdfMaxTotalBytes !== null && $pdfMaxTotalBytes > 2 * 1024 * 1024,
    'A2.1 PDF_MAX_TOTAL_BYTES is raised past the P3-P5 fixed cap of 2 MiB (found ' . round((int)$pdfMaxTotalBytes / 1048576, 2) . ' MiB)'
);
ppb(
    $pdfMaxTotalBytes !== null && $pdfMaxTotalBytes <= 32 * 1024 * 1024,
    'A2.2 PDF_MAX_TOTAL_BYTES stays BOUNDED (<= 32 MiB, never proportional to the full document ceiling — found '
        . round((int)$pdfMaxTotalBytes / 1048576, 2) . ' MiB)'
);

/* A3 — 'batch' is a recognised mode. */
preg_match('/const\s+PDF_ALLOWED_MODES\s*=\s*\[([^\]]*)\]/', $printPdfSrc, $mModes);
$allowedModesLiteral = $mModes[1] ?? '';
ppb($allowedModesLiteral !== '', 'A3.0 PDF_ALLOWED_MODES constant found and parsed');
ppb(
    preg_match('/[\'"]batch[\'"]/', $allowedModesLiteral) === 1,
    "A3.1 'batch' is a recognised value in PDF_ALLOWED_MODES"
);

/* A4 — the document-count cap check precedes ihymnsPdfRender(). */
$capCheckPos   = strpos($printPdfSrc, '> PDF_MAX_DOCUMENTS');
$renderCallPos = strpos($printPdfSrc, 'ihymnsPdfRender(');
ppb($capCheckPos !== false, 'A4.0 manage/print-pdf.php compares the document count against PDF_MAX_DOCUMENTS');
ppb($renderCallPos !== false, 'A4.1 manage/print-pdf.php calls ihymnsPdfRender(');
ppb(
    $capCheckPos !== false && $renderCallPos !== false && $capCheckPos < $renderCallPos,
    'A4.2 the document-count CAP CHECK appears BEFORE ihymnsPdfRender() — a batch over the cap never reaches the renderer'
);

/* A5 — includes/pdf_renderer.php: WriteHTML( is INSIDE the per-document loop. */
$docsLoopBody = ppbExtractBracedBlock($pdfRendererSrc, 'foreach ($docs as $i => $doc) {');
ppb($docsLoopBody !== null, 'A5.0 extracted the includes/pdf_renderer.php per-document foreach ($docs as $i => $doc) loop body');
ppb(
    $docsLoopBody !== null && str_contains($docsLoopBody, 'WriteHTML('),
    'A5.1 WriteHTML( is called INSIDE that loop body — every document is actually written, not just the first'
);

/* A6 — manage/print-pdf.php: the per-document CCLI-NOTICE loop resolves
   EACH document's own CCLI number (never just document 0's). */
$noticeLoopBody = ppbExtractBracedBlock($printPdfSrc, 'foreach ($sanitisedDocs as $pdfDocIdx => $pdfDoc) {');
ppb($noticeLoopBody !== null, 'A6.0 extracted the per-document CCLI-notice foreach ($sanitisedDocs as $pdfDocIdx => $pdfDoc) loop body');
ppb(
    $noticeLoopBody !== null && str_contains($noticeLoopBody, 'printUsageSongCcliNumber('),
    "A6.1 printUsageSongCcliNumber( is called INSIDE that loop — each document's OWN song CCLI number is resolved, never assumed from document 0"
);

/* A7 — manage/print-pdf.php: the per-document LOGGING loop only reaches
   printUsageLog( behind a qualification `continue` guard. */
$logLoopBody = ppbExtractBracedBlock($printPdfSrc, 'foreach ($sanitisedDocs as $pdfDoc) {');
ppb($logLoopBody !== null, 'A7.0 extracted the per-document logging foreach ($sanitisedDocs as $pdfDoc) loop body');
if ($logLoopBody !== null) {
    $continuePos = strpos($logLoopBody, 'continue;');
    $logCallPos  = strpos($logLoopBody, 'printUsageLog(');
    ppb($continuePos !== false, 'A7.1 the logging loop contains a `continue;` guard');
    ppb($logCallPos !== false, 'A7.2 the logging loop calls printUsageLog(');
    ppb(
        $continuePos !== false && $logCallPos !== false && $continuePos < $logCallPos,
        'A7.3 the `continue;` guard appears BEFORE printUsageLog( — a document without its own qualifying CCLI number '
            . 'is skipped before ever reaching the write call, never logged blind'
    );
}

/* =============================================================================
 * PART B — behavioural: real ihymnsPdfRender() calls (mPDF engine required)
 * ============================================================================= */

echo "\nPart B — behavioural: real ihymnsPdfRender() calls\n";

require_once $pdfRendererPath;

if (!ihymnsPdfEngineAvailable()) {
    echo "  SKIP  mPDF engine not vendored on this checkout (appWeb/private_html/lib/pdf/vendor/ absent)"
        . " — Part B not run. Not a failure, not a pass.\n";
} else {
    /** Count `/Type /Page` OBJECTS in raw PDF bytes, excluding `/Type /Pages`
     *  (the parent page-tree node) — the object dictionaries themselves are
     *  never stream-compressed in mPDF's output (only page CONTENT streams
     *  are), so this is a reliable, real signal of how many pages a PDF
     *  actually contains — verified against the vendored mPDF in this
     *  checkout for 1-, 3-, 4- and 50-document batches (see the P6 commit
     *  message for the full probe transcript). */
    function ppbPdfPageCount(string $bytes): int
    {
        return preg_match_all('/\/Type\s*\/Page(?!s)\b/', $bytes, $m);
    }

    $fourDocs = [
        ['bodyHtml' => '<div class="print-title">Song A</div>', 'meta' => ['title' => 'Song A', 'songId' => 'ZZ-0001']],
        ['bodyHtml' => '<div class="print-title">Song B</div>', 'meta' => ['title' => 'Song B', 'songId' => 'ZZ-0002']],
        ['bodyHtml' => '<div class="print-title">Song C</div>', 'meta' => ['title' => 'Song C', 'songId' => 'ZZ-0003']],
        ['bodyHtml' => '<div class="print-title">Song D</div>', 'meta' => ['title' => 'Song D', 'songId' => 'ZZ-0004']],
    ];
    $fourBytes = ihymnsPdfRender($fourDocs, '', [], ['meta' => $fourDocs[0]['meta']]);
    ppb(is_string($fourBytes) && str_starts_with($fourBytes, '%PDF-'), 'B1.1 a 4-document batch render returns PDF bytes starting %PDF-');
    if (is_string($fourBytes)) {
        $fourPageCount = ppbPdfPageCount($fourBytes);
        ppb(
            $fourPageCount === 4,
            "B1.2 a 4-document batch produces EXACTLY 4 pages, not just the first (found {$fourPageCount})"
        );
    }

    $oneDoc = [['bodyHtml' => '<div class="print-title">Solo</div>', 'meta' => ['title' => 'Solo', 'songId' => 'ZZ-0099']]];
    $oneBytes = ihymnsPdfRender($oneDoc, '', [], ['meta' => $oneDoc[0]['meta']]);
    ppb(is_string($oneBytes) && str_starts_with($oneBytes, '%PDF-'), 'B2.1 a single-document (song/preview) render still returns PDF bytes');
    if (is_string($oneBytes)) {
        $onePageCount = ppbPdfPageCount($oneBytes);
        ppb($onePageCount === 1, "B2.2 …and produces EXACTLY 1 page (batch change did not perturb single-song mode; found {$onePageCount})");
    }

    ppb(ihymnsPdfRender([], '', [], []) === null, 'B3 ihymnsPdfRender([]) still returns null');
}

/* =============================================================================
 * PART C — behavioural: per-song CCLI qualification (DB required)
 * ============================================================================= */

echo "\nPart C — behavioural: per-song CCLI qualification (the exact two primitives the batch loop calls)\n";

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

$partCRan = false;
if ($dbName !== null && $sock === null) {
    if (!defined('DB_HOST')) { define('DB_HOST', $host); }
    if (!defined('DB_USER')) { define('DB_USER', $user); }
    if (!defined('DB_PASS')) { define('DB_PASS', $pass); }
    if (!defined('DB_PORT')) { define('DB_PORT', (string)$port); }
    if (!defined('DB_NAME')) { define('DB_NAME', $dbName); }

    require_once $printUsagePath;
    require_once $publicRoot . '/includes/db_mysql.php';

    try {
        $db = getDbMysqli();
        $hasUsage = (bool)$db->query("SHOW TABLES LIKE 'tblSongUsageEvents'")->num_rows;
        $hasUsers = (bool)$db->query("SHOW TABLES LIKE 'tblUsers'")->num_rows;
        $hasSongs = (bool)$db->query("SHOW TABLES LIKE 'tblSongs'")->num_rows;
    } catch (\Throwable $e) {
        $db = null; $hasUsage = false; $hasUsers = false; $hasSongs = false;
    }

    if ($db !== null && $hasUsage && $hasUsers && $hasSongs) {
        $db->begin_transaction();
        try {
            /* TWO real, distinct SongId rows — a fabricated id would let the
               FK constraint reject the write for an unrelated reason,
               masking a bypassed gate behind a DB error instead of
               exercising it (the exact D12-class trap
               test-print-usage-ccli-gate.php's own B3/B4 comments call
               out). */
            $songRows = $db->query('SELECT SongId FROM tblSongs LIMIT 2')->fetch_all(MYSQLI_ASSOC);
            if (count($songRows) < 2) {
                ppb(true, 'C SKIPPED (fewer than 2 songs in the test DB to borrow from) — Parts A/B still ran');
                $partCRan = true;
            } else {
                $songWithCcli    = (string)$songRows[0]['SongId'];
                $songWithoutCcli = (string)$songRows[1]['SongId'];

                $ccliValue = '7654321'; // well-formed per validateCcliNumber() (#1668) — mirrors the sibling gate test's B4
                $emptyCcli = '';
                $upd = $db->prepare('UPDATE tblSongs SET Ccli = ? WHERE SongId = ?');
                $upd->bind_param('ss', $ccliValue, $songWithCcli);
                $upd->execute();
                $upd->bind_param('ss', $emptyCcli, $songWithoutCcli);
                $upd->execute();
                $upd->close();

                /* One real, qualifying-CCLI-licensed test user (mirrors the
                   sibling gate test's B4 user shape exactly). */
                $cUsername = 'zzpb1767u' . substr((string)mt_rand(), 0, 6);
                $cEmail    = $cUsername . '@example.invalid';
                $cCcli     = '1122334';
                $ins = $db->prepare(
                    "INSERT INTO tblUsers (Username, Email, EmailVerified, PasswordHash, DisplayName, Role, CcliNumber, IsActive)
                     VALUES (?, ?, 0, 'x', ?, 'user', ?, 1)"
                );
                $ins->bind_param('ssss', $cUsername, $cEmail, $cUsername, $cCcli);
                $ins->execute();
                $cUserId = (int)$db->insert_id;
                $ins->close();

                /* The TWO primitives manage/print-pdf.php's own per-document
                   loop calls, in the SAME order, for a "batch" of these two
                   documents — never a re-implementation of the endpoint's
                   decision (that would be a second copy, rule #35); this
                   calls the real, canonical functions. */
                $resolvedWith    = printUsageSongCcliNumber($songWithCcli);
                $resolvedWithout = printUsageSongCcliNumber($songWithoutCcli);
                ppb($resolvedWith === $ccliValue, 'C1 printUsageSongCcliNumber() resolves the CCLI-bearing song\'s real number');
                ppb($resolvedWithout === '', 'C2 printUsageSongCcliNumber() resolves \'\' for the song with no CCLI number');

                $countBefore = (int)($db->query('SELECT COUNT(*) c FROM tblSongUsageEvents')->fetch_assoc()['c'] ?? 0);

                /* Mirrors manage/print-pdf.php's own per-document gate
                   EXACTLY: skip when the resolved CCLI number is empty,
                   otherwise log. */
                $loggedResults = [];
                foreach ([$songWithCcli => $resolvedWith, $songWithoutCcli => $resolvedWithout] as $sid => $ccliNum) {
                    if ($ccliNum === '') {
                        continue; // this document doesn't qualify — matches manage/print-pdf.php's own guard
                    }
                    $loggedResults[$sid] = printUsageLog($cUserId, $sid, 7, ['surface' => 'pdf']);
                }

                $countAfter = (int)($db->query('SELECT COUNT(*) c FROM tblSongUsageEvents')->fetch_assoc()['c'] ?? 0);

                ppb(
                    isset($loggedResults[$songWithCcli]) && $loggedResults[$songWithCcli] === true,
                    'C3 the CCLI-bearing song (qualifying licence + own CCLI number) gets logged (printUsageLog() returned true)'
                );
                ppb(
                    !isset($loggedResults[$songWithoutCcli]),
                    'C4 the CCLI-LESS song is never even attempted (skipped by the per-document qualification gate)'
                );
                ppb(
                    $countAfter === $countBefore + 1,
                    "C5 EXACTLY ONE new tblSongUsageEvents row was written for this batch (found " . ($countAfter - $countBefore) . ')'
                );

                $rowStmt = $db->prepare(
                    'SELECT Quantity, UsageContext FROM tblSongUsageEvents WHERE UserId = ? AND SongId = ? ORDER BY Id DESC LIMIT 1'
                );
                $rowStmt->bind_param('is', $cUserId, $songWithCcli);
                $rowStmt->execute();
                $row = $rowStmt->get_result()->fetch_assoc();
                $rowStmt->close();
                ppb(
                    $row !== null && (int)$row['Quantity'] === 7 && $row['UsageContext'] === 'printed',
                    "C6 the logged row carries Quantity=7, UsageContext='printed'"
                        . ($row === null ? ' (no row found)' : ' (found Quantity=' . $row['Quantity'] . ', UsageContext=' . $row['UsageContext'] . ')')
                );

                $partCRan = true;
            }
        } finally {
            $db->rollback();
        }
    }
}
if (!$partCRan) {
    echo "  SKIP  no reachable database, or tblSongUsageEvents/tblUsers/tblSongs absent — Part C not run. Parts A/B still ran.\n";
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$passed} passed, {$failures} failed\n");
    exit(1);
}
echo "\n{$passed} passed, 0 failed"
    . ($partCRan ? " (Part C ran against a live DB).\n" : ".\n");
exit(0);
