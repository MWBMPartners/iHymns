<?php

declare(strict_types=1);

/**
 * test-text-encoding.php — raw-bytes-to-UTF-8 detector (#1908 Commit 6)
 * ========================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Two deliberately-different halves (the #1701/#1708 lesson — a static
 * functional model and a tree-derived wiring guard each catch a class of
 * bug the other cannot):
 *
 *   1. FUNCTIONAL truth table (no DB, no files on disk) — every rung of
 *      ihymnsTextToUtf8()'s detection ladder, built entirely from fixtures
 *      the test constructs itself with mb_convert_encoding() (never a
 *      checked-in binary fixture, so the exact bytes under test are visible
 *      right here in the diff): UTF-16LE/BE with a BOM, BOM-less UTF-16LE
 *      caught only by the interleaved-NUL heuristic (rung 6), UTF-32LE with
 *      a BOM, UTF-8 with and without a BOM, plain ASCII, a CJK UTF-8 string
 *      that MUST pass through byte-identical (`===` on the raw bytes — the
 *      hot path every existing importer fixture takes today), random
 *      garbage bytes that must be REJECTED (never guessed at), and the
 *      empty string.
 *
 *   2. TREE-DERIVED wiring guard (rule #34) — includes/song_importers.php
 *      must call ihymnsTextToUtf8( from EXACTLY four places, one inside
 *      each of the four parser functions #1908 Commit 6 wires it into.
 *      Comment-stripped via the token_get_all idiom (test-search-fold.php),
 *      per-function body isolated via balanced-brace extraction (the
 *      test-print-pdf-batch.php "A5" idiom) so a call sitting OUTSIDE any
 *      of the four named functions — or a stray duplicate inside one of
 *      them — is caught, not just "the string appears somewhere".
 *
 *   php tests/php/test-text-encoding.php
 *
 * @see appWeb/public_html/includes/text_encoding.php   ihymnsTextToUtf8()
 * @see appWeb/public_html/includes/song_importers.php  the four call sites
 * @link https://www.php.net/manual/en/function.mb-convert-encoding.php
 * @link https://www.php.net/manual/en/function.mb-check-encoding.php
 */

$root = dirname(__DIR__, 2);

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        $detail\n"; }
    }
}

/* ====================================================================== *
 * HALF 1 — functional truth table (no DB, no binary fixture files)
 * ====================================================================== */
echo "Half 1 — ihymnsTextToUtf8() truth table\n";

require_once $root . '/appWeb/public_html/includes/text_encoding.php';
ok('ihymnsTextToUtf8() is defined', function_exists('ihymnsTextToUtf8'));

/* -- rung 1: empty string --------------------------------------------- */
ok("'' -> '' (rung 1, nothing to detect)",
   ihymnsTextToUtf8('') === '');

/* -- rung 5: plain ASCII, no BOM, already valid UTF-8 (hot path) ------- */
$ascii = 'Amazing Grace, how sweet the sound';
ok('plain ASCII round-trips byte-identical (rung 5 hot path)',
   ihymnsTextToUtf8($ascii) === $ascii);

/* -- rung 5: CJK UTF-8, no BOM — MUST be byte-identical passthrough ---- *
   This is the load-bearing assertion for every existing importer fixture:
   valid UTF-8 must NEVER be routed through a conversion "just in case" —
   only detected-as-already-UTF-8 and handed back untouched. */
$cjk = '耶稣爱我 — 一首古老的赞美诗';
$cjkOut = ihymnsTextToUtf8($cjk);
ok('CJK UTF-8 (no BOM) passes through BYTE-IDENTICAL (rung 5)',
   $cjkOut === $cjk,
   'in bytes: ' . bin2hex($cjk) . "\n        out bytes: " . bin2hex((string)$cjkOut));

/* -- rung 4: UTF-8 with a BOM ------------------------------------------ */
$utf8Bom = "\xEF\xBB\xBFHello, world";
ok('UTF-8 with BOM -> BOM stripped, text intact (rung 4)',
   ihymnsTextToUtf8($utf8Bom) === 'Hello, world');

/* -- rung 3: UTF-16LE / UTF-16BE, each with its BOM --------------------- */
$mixed = 'Café Noël — 耶稣爱我';
$u16leBom = "\xFF\xFE" . mb_convert_encoding($mixed, 'UTF-16LE', 'UTF-8');
ok('UTF-16LE + BOM -> decoded correctly (rung 3)',
   ihymnsTextToUtf8($u16leBom) === $mixed,
   'got: ' . json_encode(ihymnsTextToUtf8($u16leBom), JSON_UNESCAPED_UNICODE));

$u16beBom = "\xFE\xFF" . mb_convert_encoding($mixed, 'UTF-16BE', 'UTF-8');
ok('UTF-16BE + BOM -> decoded correctly (rung 3)',
   ihymnsTextToUtf8($u16beBom) === $mixed,
   'got: ' . json_encode(ihymnsTextToUtf8($u16beBom), JSON_UNESCAPED_UNICODE));

/* -- rung 2: UTF-32LE + BOM (also proves the UTF-32-before-UTF-16        *
   ordering: the UTF-32LE BOM `FF FE 00 00` STARTS WITH the UTF-16LE BOM  *
   `FF FE` — if rung 2 didn't run before rung 3, this fixture would be    *
   misdetected as UTF-16LE with a leading NUL character.) ---------------- */
$u32leBom = "\xFF\xFE\x00\x00" . mb_convert_encoding($mixed, 'UTF-32LE', 'UTF-8');
ok('UTF-32LE + BOM -> decoded correctly, NOT misread as UTF-16LE (rung 2 before rung 3)',
   ihymnsTextToUtf8($u32leBom) === $mixed,
   'got: ' . json_encode(ihymnsTextToUtf8($u32leBom), JSON_UNESCAPED_UNICODE));

$u32beBom = "\x00\x00\xFE\xFF" . mb_convert_encoding($mixed, 'UTF-32BE', 'UTF-8');
ok('UTF-32BE + BOM -> decoded correctly (rung 2)',
   ihymnsTextToUtf8($u32beBom) === $mixed,
   'got: ' . json_encode(ihymnsTextToUtf8($u32beBom), JSON_UNESCAPED_UNICODE));

/* -- rung 6: BOM-less UTF-16LE, caught ONLY by the interleaved-NUL       *
   heuristic. Deliberately includes a non-ASCII character (é) so the raw  *
   bytes are NOT already valid UTF-8 on their own — a pure-ASCII UTF-16   *
   string (every byte 0-127) is indistinguishable from valid UTF-8 with   *
   embedded NUL "characters" and would be caught by rung 5 instead,       *
   never reaching this heuristic at all (a documented quirk, not a bug —  *
   see text_encoding.php's doc-block). ----------------------------------- */
$blMixed = 'Café Noël';
$u16leNoBom = mb_convert_encoding($blMixed, 'UTF-16LE', 'UTF-8');
ok('bytes are NOT already valid UTF-8 (precondition for the heuristic to be exercised)',
   !mb_check_encoding($u16leNoBom, 'UTF-8'));
ok('BOM-less UTF-16LE -> decoded correctly via the interleaved-NUL heuristic (rung 6)',
   ihymnsTextToUtf8($u16leNoBom) === $blMixed,
   'got: ' . json_encode(ihymnsTextToUtf8($u16leNoBom), JSON_UNESCAPED_UNICODE));

$u16beNoBom = mb_convert_encoding($blMixed, 'UTF-16BE', 'UTF-8');
ok('BOM-less UTF-16BE -> decoded correctly via the interleaved-NUL heuristic (rung 6, mirror case)',
   ihymnsTextToUtf8($u16beNoBom) === $blMixed,
   'got: ' . json_encode(ihymnsTextToUtf8($u16beNoBom), JSON_UNESCAPED_UNICODE));

/* -- rung 7: garbage — must be REJECTED, never guessed at --------------- */
$garbage = random_bytes(64);
ok('random_bytes(64) garbage -> null (never a guessed conversion)',
   ihymnsTextToUtf8($garbage) === null);

/* -- idempotence: re-running the already-UTF-8 output through again      *
   returns the identical string (a caller that accidentally double-calls  *
   this on an already-converted body must not corrupt it further). ------ */
ok('re-running an already-UTF-8 result through again is a no-op',
   ihymnsTextToUtf8((string)ihymnsTextToUtf8($mixed)) === $mixed);

/* -- STRUCTURAL rung-5 check, on top of the functional one above --------
   WHY BOTH: on this host's mbstring build, mb_convert_encoding($raw,
   'UTF-8','UTF-8') happens to ALSO be byte-identical for every valid-UTF-8
   fixture above — UTF-8 has exactly one canonical byte encoding per code
   point, so a same-to-same round-trip through libmbfl's internal wchar
   form doesn't (on this build) introduce drift the way a real cross-
   encoding conversion could on some OTHER libmbfl build/substitution-
   character setting. That means the `===` fixtures above, while still the
   correct and meaningful assertion of the CONTRACT ("rung 5 must be
   byte-identical"), cannot be relied on ALONE to catch a mutation that
   swaps `return $raw;` for a same-to-same `mb_convert_encoding()` round-
   trip on every host — rule #34 requires the guard to be PROVEN able to
   fail, not just plausible. So this second check reads the rung 5 branch
   of text_encoding.php's own SOURCE directly and asserts it returns $raw
   with no conversion call in that branch at all — independent of any
   runtime mbstring quirk. Uses the same balanced-brace body-extraction
   idiom as tteExtractFunctionBody() above / the test-print-pdf-batch.php
   "A5" precedent, keyed on the `if (...) {` condition instead of a
   function name since rung 5 is an inline branch, not its own function. */
$textEncPath = $root . '/appWeb/public_html/includes/text_encoding.php';
$textEncStripped = tteStripComments((string)file_get_contents($textEncPath));
$rung5Needle = "if (mb_check_encoding(\$raw, 'UTF-8')) {";
$rung5Pos = strpos($textEncStripped, $rung5Needle);
ok("text_encoding.php's rung-5 `if (mb_check_encoding(\$raw, 'UTF-8'))` branch was located",
   $rung5Pos !== false);
if ($rung5Pos !== false) {
    $rung5BracePos = $rung5Pos + strlen($rung5Needle) - 1; // the '{' itself
    $depth = 0;
    $rung5Body = null;
    for ($i = $rung5BracePos, $len = strlen($textEncStripped); $i < $len; $i++) {
        if ($textEncStripped[$i] === '{') { $depth++; }
        elseif ($textEncStripped[$i] === '}') {
            $depth--;
            if ($depth === 0) { $rung5Body = substr($textEncStripped, $rung5BracePos + 1, $i - $rung5BracePos - 1); break; }
        }
    }
    ok('rung 5 body extracted', $rung5Body !== null);
    if ($rung5Body !== null) {
        ok('rung 5 returns $raw DIRECTLY (contains "return $raw;")',
           str_contains($rung5Body, 'return $raw;'));
        ok('rung 5 never routes through mb_convert_encoding( (would defeat the byte-identical contract)',
           !str_contains($rung5Body, 'mb_convert_encoding('));
    }
}

/* ====================================================================== *
 * HALF 2 — tree-derived wiring guard (rule #34)
 * ====================================================================== */
echo "\nHalf 2 — includes/song_importers.php wiring (exactly 4 call sites)\n";

/** Strip PHP comments so a comment MENTIONING the function name (a
 *  doc-block cross-reference, e.g. "@see ihymnsTextToUtf8()") can never be
 *  miscounted as a real call site (rule #34) — mirrors
 *  test-search-fold.php's fold_stripComments() / test-print-pdf-batch.php's
 *  ppbStripComments(), replacing comment bodies with spaces so line numbers
 *  in any future diagnostic stay stable. */
function tteStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $t[1]);
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/**
 * Balanced-brace extraction of one named function's BODY (the
 * test-print-pdf-batch.php "A5" idiom, adapted to search by function name
 * since the four target signatures differ in return type/arity — so a
 * fixed `$needle . '{'` literal can't be shared across all four). Finds
 * `function $name(`, then the function's own opening `{` (the parameter
 * list here never itself contains a `{`), then walks brace depth to the
 * matching close. Returns null if the function or its closing brace can't
 * be found.
 */
function tteExtractFunctionBody(string $strippedSrc, string $functionName): ?string
{
    $fnPos = strpos($strippedSrc, 'function ' . $functionName . '(');
    if ($fnPos === false) {
        return null;
    }
    $bracePos = strpos($strippedSrc, '{', $fnPos);
    if ($bracePos === false) {
        return null;
    }
    $depth = 0;
    $len = strlen($strippedSrc);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($strippedSrc[$i] === '{') {
            $depth++;
        } elseif ($strippedSrc[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($strippedSrc, $bracePos + 1, $i - $bracePos - 1);
            }
        }
    }
    return null;
}

$importersPath = $root . '/appWeb/public_html/includes/song_importers.php';
ok('includes/song_importers.php exists', is_file($importersPath));
$importersRaw      = (string)file_get_contents($importersPath);
$importersStripped = tteStripComments($importersRaw);

/* The four parsers #1908 Commit 6 wires ihymnsTextToUtf8() into — derived
   from the plan, not re-derived from the tree (there is no structural
   marker distinguishing "a bulk-import body parser" from any other
   function in this 4900-line file), but every one of the four is proven
   to actually call the helper below, and the total-call-site count proves
   no FIFTH site exists anywhere else in the file either. */
$targetFunctions = [
    '_bulkImport_parseTxt',
    '_bulkImport_parseVideoPsalmSongbook',
    '_bulkImport_parseFreeShow',
    '_bulkImport_parseIHymnsJson',
];

preg_match_all('/ihymnsTextToUtf8\s*\(/', $importersStripped, $allCalls);
$totalCallSites = count($allCalls[0]);
ok('EXACTLY four ihymnsTextToUtf8( call sites in song_importers.php (comment-stripped)',
   $totalCallSites === 4,
   "found $totalCallSites");

$sitesInsideTargets = 0;
foreach ($targetFunctions as $fn) {
    $body = tteExtractFunctionBody($importersStripped, $fn);
    ok("$fn( ) exists and its body was extracted", $body !== null);
    if ($body === null) {
        continue;
    }
    $callCount = preg_match_all('/ihymnsTextToUtf8\s*\(/', $body);
    ok("$fn( ) calls ihymnsTextToUtf8( exactly once inside its own body",
       $callCount === 1,
       "found $callCount call(s)");
    $sitesInsideTargets += $callCount;
}
ok('every call site found file-wide is accounted for INSIDE one of the four named functions '
   . '(no call site living outside all four, e.g. a stray top-level helper)',
   $sitesInsideTargets === $totalCallSites,
   "sum inside targets = $sitesInsideTargets, file-wide total = $totalCallSites");

echo "\n";
if ($fail > 0) {
    echo "$fail assertion(s) failed.\n";
    exit(1);
}
echo "All text-encoding assertions passed.\n";
