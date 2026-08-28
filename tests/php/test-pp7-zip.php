<?php

declare(strict_types=1);

/**
 * iHymns — ProPresenter 7+ tolerant ZIP reader cross-validation (#1968 P2)
 * ============================================================================================
 *
 * ELI5
 * ----
 * `includes/propresenter7_zip.php` reads `.probundle` files a non-standard way (walking local
 * file headers from byte 0, never trusting the end-of-file index) because real ProPresenter
 * exports corrupt that index. This test proves the hand-rolled reader gets the RIGHT answer on
 * the two real, committed `.probundle` fixtures — by checking it against THREE independent
 * sources of ground truth (`unzip -l`/extraction, Python's `zipfile`, and PHP's own
 * `\ZipArchive`, all run by hand during this task and recorded below) and by feeding the
 * extracted inner `.pro` bytes through the ALREADY-cross-validated `pp7DecodePresentation()`
 * (`includes/propresenter7_decode.php`, itself proven against `protobufjs` in
 * `tests/php/test-pp7-decode.php`) — so a decode failure here can only mean the ZIP layer handed
 * it the wrong bytes, not that the decoder is wrong.
 *
 * FINDING WORTH RECORDING PLAINLY (task instruction: report deviations, don't bury them)
 * ------------------------------------------------------------------------------------------
 * `.claude/propresenter-interop-1968-plan.md` §4.1 predicts `unzip`/`zipfile`/`ZipArchive` will
 * REJECT real ProPresenter bundles ("verify during implementation") — grounded in the owner's own
 * genuine v21.4 export, which is not committed here (copyrighted content, decision D3 still
 * open). Tested directly against the two REAL fixtures that ARE committed
 * (`bussnet-testbild.probundle`, `bussnet-export-from-pp.probundle`): **all three tools opened
 * and extracted both cleanly.** Byte inspection explains why — neither file contains a ZIP64
 * end-of-central-directory record/locator (`PK\x06\x06`/`PK\x07\x07`) or a per-entry ZIP64 extra
 * field at all; both are small enough (~1.1-1.2 KB) that a compliant writer never needed ZIP64.
 * These two fixtures are genuine PP7 output and DO exercise the "`.probundle` layout, no
 * manifest, media at its original relative/absolute path" shape — they just don't happen to
 * trigger the specific broken-EOCD byte pattern the owner's larger real export does. See
 * `test_ziparchive_oracle_on_real_fixtures()` below for the recorded proof, and
 * `includes/propresenter7_zip.php`'s file-level doc-block for why the shipped reader still never
 * depends on `\ZipArchive` for anything — using it as a "fast path" that only ever gets exercised
 * on non-representative small files would be exactly the kind of false-positive-by-omission this
 * epic exists to stop (the fast path would never be tested against what breaks it).
 *
 * GAP CLOSED — a REAL-SHAPED fixture that actually forces the broken-EOCD path (section (g))
 * -------------------------------------------------------------------------------------------
 * The finding above leaves CI without any committed file that exercises the specific defect the
 * whole reader exists for. `tests/fixtures/propresenter/synthetic-zip64.probundle`
 * (`tools/pp7-gen-zip64-bundle.js`) closes that gap: it assembles a `.probundle` from
 * copyright-safe, ALREADY-committed parts (the real bytes of `bussnet-test.pro`, STORED, plus a
 * synthetic placeholder media entry, both ZIP64-sentineled) and forges the exact, independently
 * documented ProPresenter defect — the central-directory-size field overstated by **98 bytes** in
 * both the ZIP64 EOCD record and its classic-EOCD mirror (`bussnet/propresenter7-php-lib`'s
 * `Zip64Fixer` + `doc/internal/learnings.md` name this precise magnitude, arrived at completely
 * independently of this task). The result reproduces the real bug with uncanny precision — not
 * merely "ZipArchive fails", but the SAME three tool-specific symptoms confirmed by hand during
 * this task: PHP `\ZipArchive::open()` returns `ZIPARCHIVE_ER_INCONS` (21), the SAME code the
 * coordinator observed on the owner's real 2 MB bundle; Python `zipfile` raises
 * `BadZipFile('Corrupt zip64 end of central directory record')`, the SAME message quoted in the
 * plan; and `unzip -l` prints "missing 98 bytes in zipfile … reported length of central directory
 * is 98 bytes too long … Compensating…", the SAME wording `learnings.md` records for genuine
 * ProPresenter output. Section (g) below asserts the PHP-testable pieces of that (ZipArchive
 * rejection + the hand-reader/decoder succeeding anyway); the Python/`unzip` confirmations were
 * run by hand (recorded here, not re-run per CI invocation, to avoid a PHP test suite depending on
 * external `python3`/`unzip` binaries being present).
 *
 * MUTATION-PROVEN (rule #34; task instruction "break the ZIP64 extra-field size read (ignore the
 * 0x0001 field)") — performed twice, by hand, at authoring time, then reverted each time (a
 * pristine-file `diff` was run after reverting to confirm a byte-identical restore):
 *   - m1: In `includes/propresenter7_zip.php`'s `pp7ZipListEntries()`, commented out the
 *     `if ($csize === 0xFFFFFFFF || $usize === 0xFFFFFFFF) { … }` block that calls
 *     `_pp7ZipParseZip64Extra()` — i.e. exactly "ignore the 0x0001 field" — leaving the raw
 *     `0xFFFFFFFF` sentinel (4294967295) in place as both `$usize` and `$csize`. RESULT: RED.
 *     Not the failure mode originally guessed at authoring time (a bounds-check throw inside
 *     `pp7ZipReadEntry()`) — the ALREADY-present per-entry size cap fired first and earlier,
 *     inside `pp7ZipListEntries()` itself ("entry 'zip64-test-entry.bin' declares a size over the
 *     25 MiB per-entry cap"), because the un-resolved sentinel reads as a ~4 GiB declared size.
 *     Either way the synthetic-entry section (f) assertions fail — the mutation is caught, just by
 *     a different one of this file's own defensive layers than first predicted; recording the
 *     ACTUAL observed message here rather than the guessed one. Reverted -> green, 42/42 passed.
 *   - m2: in the same function, inverted the STORED-method consistency guard's comparison from
 *     `if ($method === 0 && $csize !== $usize)` to `… && $csize === $usize)`, flipping when it
 *     throws. Both REAL committed fixtures are DEFLATE (method 8), so this guard is dormant on
 *     them either way — it is exercised only by section (f)'s STORED synthetic entry, which went
 *     RED exactly as expected ("STORED entry 'zip64-test-entry.bin' declares csize(495) !=
 *     usize(495)" — fired on a genuinely equal-size STORED entry once the comparison was
 *     inverted, i.e. it now throws on the CORRECT case instead of the malformed one). Reverted ->
 *     green, 42/42 passed.
 *   - m3: repeated m1 (comment out the same ZIP64-extra-parse block) AFTER
 *     `synthetic-zip64.probundle` existed, specifically to prove the resolution is exercised by
 *     the REAL-FILE-SHAPED fixture too, not only section (f)'s hand-built literal-bytes entry.
 *     RESULT: RED — 5 assertions failed across BOTH section (f) (the same failure as m1) AND
 *     section (g) ("entry '/Users/curator/Downloads/pp-test/Media/dummy.png' declares a size
 *     over the 25 MiB per-entry cap", "entry count matches expected (0 vs 2)", "inner .pro bytes
 *     were extracted to decode-validate" all failing on the real-shaped fixture specifically —
 *     `\ZipArchive::open()` FAILING was, correctly, the one (g) assertion that stayed green,
 *     since that doesn't touch the mutated code at all). Reverted -> green, 65/65 passed, `diff`
 *     against the pristine file confirmed byte-identical.
 *
 * Usage:
 *   php tests/php/test-pp7-zip.php
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see .claude/propresenter-interop-1968-plan.md    §4.1 (the reader's contract), §8.3(e) (this
 *      test's brief, there named test-pp7-zip-reader.php; kept as test-pp7-zip.php per this
 *      task's explicit file name)
 * @see includes/propresenter7_zip.php                the reader under test
 * @see includes/propresenter7_decode.php              the `.pro` decoder used for cross-validation
 * @see tools/pp7-gen-zip64-bundle.js                  generates synthetic-zip64.probundle (section (g))
 * @see tests/fixtures/propresenter/README.md          fixture provenance + licensing
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_zip.php';
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_decode.php';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  ❌ {$label}\n";
    }
}

echo "\n#1968 P2 — ProPresenter 7+ tolerant ZIP reader (.probundle)\n\n";

$fixturesDir = dirname(__DIR__, 2) . '/tests/fixtures/propresenter';

/* ============================================================================================
 * (a) REAL-FIXTURE ENTRY INVENTORY — derived independently (unzip -l / python zipfile /
 *     \ZipArchive, all three agree), NOT guessed. See tests/fixtures/propresenter/README.md's
 *     own fixture-inventory table for the same numbers recorded at fixture-commit time.
 * ============================================================================================ */

echo "-- (a) real .probundle entry inventory (tree-derived glob, cross-checked against unzip/zipfile/ZipArchive) --\n";

/**
 * Ground truth for each committed `.probundle`, established during this task by running
 * `unzip -l`, Python's `zipfile.ZipFile.namelist()`/`.read()`, and PHP's `\ZipArchive` against
 * the actual committed bytes (all three agreed) — see the file-level doc-block's "FINDING WORTH
 * RECORDING PLAINLY" section for why all three could open these two particular files. Keyed by
 * basename so a coverage-floor + per-file lookup (below) fails loudly if a new `.probundle` is
 * committed without a matching entry here, rather than silently skipping it.
 */
$expectedInventory = [
    'bussnet-testbild.probundle' => [
        'test-background.png' => ['method' => 8, 'size' => 717],
        'TestBild.pro'         => ['method' => 8, 'size' => 767],
    ],
    'bussnet-export-from-pp.probundle' => [
        'Media/sample-media.png' => ['method' => 8, 'size' => 717],
        'TestBild.pro'           => ['method' => 8, 'size' => 948],
    ],
    // Synthesised (not third-party) — tools/pp7-gen-zip64-bundle.js. STORED + real ZIP64
    // sentinels, unlike the two DEFLATE/non-ZIP64 real fixtures above; its DEFINING property
    // (ZipArchive rejects it, the hand-reader doesn't) is covered separately in section (g) below
    // — this entry just lets the generic entry-inventory/decode-cross-validation loops below
    // cover it too, for free.
    'synthetic-zip64.probundle' => [
        '/Users/curator/Downloads/pp-test/Media/dummy.png' => ['method' => 0, 'size' => 210],
        'Test.pro'                                          => ['method' => 0, 'size' => 7779],
    ],
];

$bundleFixtures = glob($fixturesDir . '/*.probundle') ?: [];
sort($bundleFixtures);

// Coverage floor (rule #34's under-report clause): guards against the glob silently matching
// fewer files than the corpus actually has.
ok('at least 2 committed .probundle fixtures exist (found ' . count($bundleFixtures) . ')',
    count($bundleFixtures) >= 2);

$decodedProBytesByBundle = []; // basename => extracted .pro bytes, reused in section (c)

foreach ($bundleFixtures as $bundlePath) {
    $base = basename($bundlePath);

    if (!isset($expectedInventory[$base])) {
        ok("{$base} has a matching expected-inventory entry in this test", false);
        continue;
    }
    $expected = $expectedInventory[$base];

    $bytes = file_get_contents($bundlePath);
    if ($bytes === false) {
        ok("{$base} is readable", false);
        continue;
    }

    try {
        $entries = pp7ZipListEntries($bytes);
    } catch (\Throwable $e) {
        ok("{$base}: pp7ZipListEntries() succeeds (" . $e->getMessage() . ')', false);
        continue;
    }

    ok("{$base}: entry count matches expected (" . count($entries) . ' vs ' . count($expected) . ')',
        count($entries) === count($expected));

    $sawRootPro = false;
    $sawMedia = false;

    foreach ($entries as $entry) {
        $name = $entry['name'];
        if (!isset($expected[$name])) {
            ok("{$base}: entry '{$name}' is a known/expected entry", false);
            continue;
        }
        $exp = $expected[$name];
        ok("{$base}: '{$name}' method matches ({$entry['method']} vs {$exp['method']})",
            $entry['method'] === $exp['method']);
        ok("{$base}: '{$name}' uncompressed size matches ({$entry['size']} vs {$exp['size']})",
            $entry['size'] === $exp['size']);

        // A root .pro entry has the .pro extension and no '/' in its name (basename === name);
        // a media entry is everything else — at least one of each is expected per the plan §4
        // bundle layout ("`.pro`(s) at ROOT; media stored under its original path").
        if (substr($name, -4) === '.pro' && strpos($name, '/') === false) {
            $sawRootPro = true;
        } else {
            $sawMedia = true;
        }

        // Actually read the bytes (needed for sections (b)/(c)/(d) below).
        try {
            $data = pp7ZipReadEntry($bytes, $entry);
        } catch (\Throwable $e) {
            ok("{$base}: '{$name}' extracts without throwing (" . $e->getMessage() . ')', false);
            continue;
        }
        ok("{$base}: '{$name}' extracted byte length matches its declared size (" . strlen($data) . ' vs ' . $exp['size'] . ')',
            strlen($data) === $exp['size']);

        if (substr($name, -4) === '.pro') {
            $decodedProBytesByBundle[$base] = $data;
        }
    }

    ok("{$base}: at least one root-level .pro entry present", $sawRootPro);
    ok("{$base}: at least one media entry present", $sawMedia);
}

/* ============================================================================================
 * (b) \ZipArchive ORACLE — an independent second implementation cross-validating the SAME real
 *     fixtures byte-for-byte, mirroring how test-pp7-decode.php cross-validates against
 *     protobufjs rather than trusting the hand-rolled reader on its own say-so. NOT part of the
 *     shipped reader (see the file-level doc-block for why) — used here purely as an oracle.
 * ============================================================================================ */

echo "\n-- (b) \\ZipArchive oracle on the real fixtures (test-only; never the shipped path) --\n";

if (!extension_loaded('zip') || !class_exists('\\ZipArchive')) {
    echo "  (skipped: ext-zip not available in this PHP build — the hand-rolled reader above is\n";
    echo "   independently verified via section (a)'s unzip/zipfile-derived expected inventory)\n";
} else {
    foreach ($bundleFixtures as $bundlePath) {
        $base = basename($bundlePath);

        // synthetic-zip64.probundle's DEFINING property is the OPPOSITE of every assertion in
        // this loop — \ZipArchive is SUPPOSED to reject it (that's the whole reason it exists).
        // Asserting rejection gets its own, more thorough coverage in section (g) below; skipping
        // it here (rather than silently letting it fail this loop's "must succeed" assertion) is
        // a deliberate, documented exception, not a coverage gap.
        if ($base === 'synthetic-zip64.probundle') {
            continue;
        }

        $za = new \ZipArchive();
        $openResult = $za->open($bundlePath);
        ok("{$base}: \\ZipArchive::open() succeeds (result={$openResult}) — recorded finding: it DOES open this real fixture, see doc-block",
            $openResult === true);
        if ($openResult !== true) {
            continue;
        }

        $bytes = file_get_contents($bundlePath);
        $entries = pp7ZipListEntries($bytes);

        ok("{$base}: \\ZipArchive entry count matches pp7ZipListEntries() (" . $za->numFiles . ' vs ' . count($entries) . ')',
            $za->numFiles === count($entries));

        foreach ($entries as $entry) {
            $oracleData = $za->getFromName($entry['name']);
            $ourData = pp7ZipReadEntry($bytes, $entry);
            ok("{$base}: '{$entry['name']}' bytes match the \\ZipArchive oracle byte-for-byte",
                $oracleData !== false && $oracleData === $ourData);
        }
        $za->close();
    }
}

/* ============================================================================================
 * (c) DECODER CROSS-VALIDATION — the extracted inner .pro bytes are a valid presentation
 * ============================================================================================ */

echo "\n-- (c) extracted .pro bytes decode via the already-cross-validated pp7DecodePresentation() --\n";

ok('at least 2 .pro files were extracted from the bundles to decode-validate (found ' . count($decodedProBytesByBundle) . ')',
    count($decodedProBytesByBundle) >= 2);

foreach ($decodedProBytesByBundle as $base => $proBytes) {
    try {
        $decoded = pp7DecodePresentation($proBytes);
    } catch (\Throwable $e) {
        ok("{$base}: extracted .pro decodes without throwing (" . $e->getMessage() . ')', false);
        continue;
    }
    ok("{$base}: decoded presentation has a non-empty name ('{$decoded['name']}')",
        $decoded['name'] !== '');
    ok("{$base}: decoded presentation has at least one cue (" . count($decoded['cues']) . ' found)',
        count($decoded['cues']) >= 1);
}

/* ============================================================================================
 * (d) TOLERANCE PROOF — corrupting the central directory is invisible; corrupting a local
 *     header is not. This is the plan §8.3 m6 mutation, run as an actual assertion (not just a
 *     by-hand note) because it demonstrates a real, load-bearing property of the shipped reader
 *     rather than a defect being mutated in.
 * ============================================================================================ */

echo "\n-- (d) tolerance proof: corrupt central directory (still reads fine) vs corrupt local header (fails) --\n";

$exportBytes = file_get_contents($fixturesDir . '/bussnet-export-from-pp.probundle');
if ($exportBytes === false) {
    ok('bussnet-export-from-pp.probundle is readable for the tolerance proof', false);
} else {
    // Byte-verified during this task: the central directory in this fixture starts at offset
    // 1059 (first central-directory-header PK\x01\x02 signature). Flip 4 bytes well inside it
    // (comfortably past the signature and any single field) — the local-header scan must never
    // reach this region at all.
    $corruptedCd = $exportBytes;
    for ($i = 1070; $i < 1074; $i++) {
        $corruptedCd[$i] = chr(~ord($corruptedCd[$i]) & 0xFF);
    }
    try {
        $entriesAfterCdCorruption = pp7ZipListEntries($corruptedCd);
        ok('corrupting 4 bytes mid-central-directory still yields the same entry count (local-header scan never reads the CD)',
            count($entriesAfterCdCorruption) === count(pp7ZipListEntries($exportBytes)));
    } catch (\Throwable $e) {
        ok('corrupting 4 bytes mid-central-directory still yields the same entry count (local-header scan never reads the CD) — threw instead: ' . $e->getMessage(),
            false);
    }

    // Now corrupt 4 bytes INSIDE the first entry's local file header (offset 8 = the
    // compression-method field) — this the reader DOES read, so it must notice.
    $corruptedHeader = $exportBytes;
    for ($i = 8; $i < 12; $i++) {
        $corruptedHeader[$i] = chr(~ord($corruptedHeader[$i]) & 0xFF);
    }
    $threwOrDiffered = false;
    try {
        $entriesAfterHeaderCorruption = pp7ZipListEntries($corruptedHeader);
        $threwOrDiffered = ($entriesAfterHeaderCorruption !== pp7ZipListEntries($exportBytes));
    } catch (\Throwable $e) {
        $threwOrDiffered = true;
    }
    ok('corrupting 4 bytes inside the first local file header changes the outcome (throws or differs)',
        $threwOrDiffered);
}

/* ============================================================================================
 * (e) NEGATIVE TESTS — malformed input never crashes/hangs; always a clean InvalidArgumentException
 * ============================================================================================ */

echo "\n-- (e) negative tests: garbage and truncated input --\n";

try {
    pp7ZipListEntries('this is plainly not a ZIP file, just some prose used to test the negative path');
    ok('garbage (non-ZIP) input throws InvalidArgumentException', false);
} catch (\InvalidArgumentException $e) {
    ok('garbage (non-ZIP) input throws InvalidArgumentException (' . $e->getMessage() . ')', true);
} catch (\Throwable $e) {
    ok('garbage (non-ZIP) input throws InvalidArgumentException specifically (got ' . get_class($e) . ')', false);
}

$realBytesForTruncation = file_get_contents($fixturesDir . '/bussnet-testbild.probundle');
if ($realBytesForTruncation === false) {
    ok('bussnet-testbild.probundle is readable for the truncation test', false);
} else {
    // 40 bytes cuts through the middle of the first entry's 19-byte name (header ends at byte
    // 30, name would need to run to byte 49) — a genuinely truncated, mid-entry cut.
    $truncated = substr($realBytesForTruncation, 0, 40);
    try {
        pp7ZipListEntries($truncated);
        ok('truncated (mid-entry) input throws InvalidArgumentException', false);
    } catch (\InvalidArgumentException $e) {
        ok('truncated (mid-entry) input throws InvalidArgumentException (' . $e->getMessage() . ')', true);
    } catch (\Throwable $e) {
        ok('truncated (mid-entry) input throws InvalidArgumentException specifically (got ' . get_class($e) . ')', false);
    }
}

// pp7ZipReadEntry() itself must also reject a hand-built out-of-bounds descriptor rather than
// silently returning garbage or a PHP warning-laden empty string.
try {
    pp7ZipReadEntry('short', ['name' => 'x', 'method' => 0, 'size' => 100, 'csize' => 100, 'offset' => 0]);
    ok('pp7ZipReadEntry() throws InvalidArgumentException on an out-of-bounds descriptor', false);
} catch (\InvalidArgumentException $e) {
    ok('pp7ZipReadEntry() throws InvalidArgumentException on an out-of-bounds descriptor (' . $e->getMessage() . ')', true);
}

/* ============================================================================================
 * (f) SYNTHETIC ZIP64 ENTRY — neither committed real fixture actually needs the ZIP64 extra
 *     field (see the file doc-block's "FINDING WORTH RECORDING PLAINLY"), so this hand-built
 *     entry is what exercises + mutation-proves _pp7ZipParseZip64Extra() at all. Built directly
 *     from the ZIP local-file-header + ZIP64-extra-field byte layout (APPNOTE §4.3.7 / §4.5.3),
 *     independently of includes/propresenter7_zip.php's own field offsets.
 * ============================================================================================ */

echo "\n-- (f) synthetic ZIP64 entry (0xFFFFFFFF sentinel + real ZIP64 extra field) --\n";

/**
 * Hand-assemble a minimal one-entry ZIP buffer: a single STORED entry whose 32-bit csize/usize
 * header fields are both the ZIP64 sentinel 0xFFFFFFFF, with the TRUE sizes carried in a genuine
 * ZIP64 extra field (header id 0x0001, order: uncompressed size then compressed size, per
 * APPNOTE §4.5.3 — see includes/propresenter7_zip.php's _pp7ZipParseZip64Extra() doc-block).
 * No central directory / EOCD at all — pp7ZipListEntries() never needs one.
 */
function pp7TestBuildSyntheticZip64Entry(string $name, string $content): string
{
    $size = strlen($content); // STORED: compressed size === uncompressed size
    $crc = crc32($content);
    $zip64Body = pack('PP', $size, $size); // usize, then csize (both were 0xFFFFFFFF, so both present)
    $extra = pack('vv', 0x0001, strlen($zip64Body)) . $zip64Body;
    $nlen = strlen($name);
    $elen = strlen($extra);
    $fixedHeader = pack(
        'vvvvvVVVvv',
        45,          // version needed to extract (45 = ZIP64 support required)
        0,           // general purpose bit flag
        0,           // compression method: STORED
        0,           // last mod file time
        0,           // last mod file date
        $crc,
        0xFFFFFFFF,  // compressed size -> ZIP64 sentinel
        0xFFFFFFFF,  // uncompressed size -> ZIP64 sentinel
        $nlen,
        $elen
    );
    return "PK\x03\x04" . $fixedHeader . $name . $extra . $content;
}

function test_synthetic_zip64_entry_resolves_true_sizes(): void
{
    $content = str_repeat('The quick brown fox jumps over the lazy dog. ', 11); // 45*11 = 495 bytes
    $synthetic = pp7TestBuildSyntheticZip64Entry('zip64-test-entry.bin', $content);

    try {
        $entries = pp7ZipListEntries($synthetic);
    } catch (\Throwable $e) {
        ok('synthetic ZIP64 entry: pp7ZipListEntries() succeeds (' . $e->getMessage() . ')', false);
        return;
    }
    ok('synthetic ZIP64 entry: exactly one entry found', count($entries) === 1);
    if (count($entries) !== 1) {
        return;
    }
    $entry = $entries[0];
    ok('synthetic ZIP64 entry: name preserved', $entry['name'] === 'zip64-test-entry.bin');
    ok('synthetic ZIP64 entry: resolved size is the TRUE 495-byte length, not the 0xFFFFFFFF sentinel ('
        . $entry['size'] . ')', $entry['size'] === strlen($content));
    ok('synthetic ZIP64 entry: resolved csize is the TRUE 495-byte length, not the 0xFFFFFFFF sentinel ('
        . $entry['csize'] . ')', $entry['csize'] === strlen($content));

    try {
        $extracted = pp7ZipReadEntry($synthetic, $entry);
        ok('synthetic ZIP64 entry: extracted bytes are byte-identical to the original content',
            $extracted === $content);
    } catch (\Throwable $e) {
        ok('synthetic ZIP64 entry: extraction succeeds (' . $e->getMessage() . ')', false);
    }
}
test_synthetic_zip64_entry_resolves_true_sizes();

/* ============================================================================================
 * (g) SYNTHESISED broken-EOCD FIXTURE — the defining property
 * ============================================================================================
 * `tests/fixtures/propresenter/synthetic-zip64.probundle` (generated by
 * `tools/pp7-gen-zip64-bundle.js`, real `bussnet-test.pro` bytes + a synthetic placeholder media
 * entry, both STORED with genuine ZIP64 sentinels, and a central-directory size deliberately
 * overstated by 98 bytes in both EOCD copies — see that generator's own doc-block for the full
 * mechanism and the three independent real-world confirmations) exists for exactly ONE property:
 * a strict reader rejects it while this reader does not. Sections (a)/(c) above already cover its
 * entry-inventory/decode correctness generically (it is in `$expectedInventory` and therefore
 * flows through those loops too) — this section asserts the DEFINING property directly and
 * explicitly, so it reads as one coherent claim in isolation rather than being inferred by
 * cross-referencing three other sections.
 * ============================================================================================ */

echo "\n-- (g) synthetic-zip64.probundle: the defining broken-EOCD property --\n";

$syntheticZip64Path = $fixturesDir . '/synthetic-zip64.probundle';
if (!is_file($syntheticZip64Path)) {
    ok('synthetic-zip64.probundle exists (run: node tools/pp7-gen-zip64-bundle.js)', false);
} else {
    $syntheticZip64Bytes = file_get_contents($syntheticZip64Path);

    // (g1) \ZipArchive REJECTS it — the defining property, the whole reason this fixture exists.
    if (!extension_loaded('zip') || !class_exists('\\ZipArchive')) {
        echo "  (skipped g1: ext-zip not available in this PHP build)\n";
    } else {
        $za = new \ZipArchive();
        $openResult = $za->open($syntheticZip64Path);
        ok('synthetic-zip64.probundle: \\ZipArchive::open() FAILS (result=' . var_export($openResult, true) . ') — a strict reader rejects this file, same as the real bug',
            $openResult !== true);
        if ($openResult === \ZipArchive::ER_INCONS) {
            ok('synthetic-zip64.probundle: \\ZipArchive failure is specifically ER_INCONS (21) — the SAME code observed on the owner\'s real bundle',
                true);
        }
        if ($openResult === true) {
            $za->close();
        }
    }

    // (g2) the hand-reader lists both entries with the TRUE sizes (ZIP64 sentinel resolved).
    $g2Entries = null;
    try {
        $g2Entries = pp7ZipListEntries($syntheticZip64Bytes);
        ok('synthetic-zip64.probundle: pp7ZipListEntries() succeeds where \\ZipArchive fails', true);
    } catch (\Throwable $e) {
        ok('synthetic-zip64.probundle: pp7ZipListEntries() succeeds where \\ZipArchive fails (' . $e->getMessage() . ')', false);
    }

    $g2Expected = $expectedInventory['synthetic-zip64.probundle'];
    ok('synthetic-zip64.probundle: entry count matches expected (' . count($g2Entries ?? []) . ' vs ' . count($g2Expected) . ')',
        $g2Entries !== null && count($g2Entries) === count($g2Expected));

    $syntheticProBytes = null;
    foreach (($g2Entries ?? []) as $entry) {
        $name = $entry['name'];
        $exp = $g2Expected[$name] ?? null;
        if ($exp === null) {
            ok("synthetic-zip64.probundle: entry '{$name}' is a known/expected entry", false);
            continue;
        }
        ok("synthetic-zip64.probundle: '{$name}' method is STORED (0) as declared, and size resolved from the ZIP64 sentinel to the true value ({$entry['method']}/{$entry['size']} vs {$exp['method']}/{$exp['size']})",
            $entry['method'] === $exp['method'] && $entry['size'] === $exp['size']);
        try {
            $data = pp7ZipReadEntry($syntheticZip64Bytes, $entry);
            ok("synthetic-zip64.probundle: '{$name}' extracted byte length matches (" . strlen($data) . ' vs ' . $exp['size'] . ')',
                strlen($data) === $exp['size']);
            if (substr($name, -4) === '.pro') {
                $syntheticProBytes = $data;
            }
        } catch (\Throwable $e) {
            ok("synthetic-zip64.probundle: '{$name}' extraction succeeds (" . $e->getMessage() . ')', false);
        }
    }

    // (g3) the extracted inner .pro decodes — and, since we KNOW its real content (it's the
    // committed bussnet-test.pro's own bytes, byte-for-byte), assert the KNOWN real values, not
    // just "non-empty" (a stronger claim than sections (a)/(c)'s generic cross-fixture loop makes).
    if ($syntheticProBytes === null) {
        ok('synthetic-zip64.probundle: inner .pro bytes were extracted to decode-validate', false);
    } else {
        ok('synthetic-zip64.probundle: extracted .pro bytes are byte-identical to the committed bussnet-test.pro source',
            $syntheticProBytes === file_get_contents($fixturesDir . '/bussnet-test.pro'));
        try {
            $decoded = pp7DecodePresentation($syntheticProBytes);
            ok("synthetic-zip64.probundle: decoded name matches bussnet-test.pro's known content ('{$decoded['name']}' === 'Test')",
                $decoded['name'] === 'Test');
            ok('synthetic-zip64.probundle: decoded cue count matches bussnet-test.pro\'s known content (' . count($decoded['cues']) . ' vs 5)',
                count($decoded['cues']) === 5);
            ok('synthetic-zip64.probundle: decoded arrangement count matches bussnet-test.pro\'s known content (' . count($decoded['arrangements']) . ' vs 2)',
                count($decoded['arrangements']) === 2);
        } catch (\Throwable $e) {
            ok('synthetic-zip64.probundle: extracted .pro decodes without throwing (' . $e->getMessage() . ')', false);
        }
    }
}

/* ============================================================================================ */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA failure here means the tolerant ZIP reader disagrees with an independent oracle\n";
    echo "(unzip/zipfile-derived expected inventory, or \\ZipArchive) on a REAL ProPresenter\n";
    echo "bundle, or mis-resolves a ZIP64 size, or fails to reject malformed input cleanly.\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ ZIP reader assertions passed.\n";
