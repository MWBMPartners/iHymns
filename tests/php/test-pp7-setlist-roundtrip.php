<?php

declare(strict_types=1);

/**
 * tests/php/test-pp7-setlist-roundtrip.php — set-list `.proplaylist` EXPORT -> import-side
 * DECODE closure test (#1968 P3-EXPORT, plan §5.2 / §8.3)
 * ============================================================================================
 *
 * ELI5
 * ----
 * Sibling to `tests/php/test-pp7-roundtrip.php` (which proves this for a single `.pro`), but
 * for a whole SET LIST exported as a `.proplaylist`: builds a small set list (one non-song
 * running-order slot + two songs), runs it through the REAL client exporter
 * (`propresenter-export.js`'s `exportSetlistAsProplaylist()` — the exact function the set-list
 * UI's Export control calls, via `tools/pp7-gen-setlist-roundtrip-sample.js`), then decodes the
 * resulting bytes with the REAL PHP `.proplaylist` decoder
 * (`includes/propresenter7_playlist.php`'s `pp7ReadPlaylistBundle()`) and asserts the playlist
 * name, item count/order/types, and every presentation item's document_path/arrangement
 * round-trip correctly.
 *
 * WHY THIS IS THE RIGHT KIND OF PROOF (the owner's #1 rule for this epic — see the plan's header
 * + §8: "no more false positives — validate against real files, never a circular same-schema
 * round-trip"): the JS ENCODER (`exportSetlistAsProplaylist()`, protobufjs against the STATIC/
 * reflection schema) and the PHP DECODER (`pp7ReadPlaylistBundle()`/`pp7DecodePresentation()`, a
 * hand-rolled wire-walker) are two INDEPENDENT implementations in two different languages that
 * have never shared a line of source — `pp7DecodePlaylistDocument()` was authored in #1973,
 * months before `exportSetlistAsProplaylist()` existed, entirely from the vendored `.proto`
 * field numbers, not from anything this exporter does. Their agreement on the same bytes is the
 * same class of evidence `test-pp7-roundtrip.php` already established for the single-`.pro` case
 * (that file's own doc-block explains at length why a same-process self-decode would prove
 * nothing — #1918/#1950 both shipped exactly that kind of green and then failed in real
 * ProPresenter).
 *
 * THE DEEPEST ASSERTION — ARRANGEMENT UUID CROSS-FILE AGREEMENT: a `.proplaylist`
 * `PlaylistItem.Presentation.arrangement` is a UUID that only means something if it matches a
 * UUID inside the REFERENCED `.pro`'s own `arrangements[]` list (or its `selected_arrangement`).
 * `exportSetlistAsProplaylist()` achieves this by reusing the SAME `rv.data.UUID` object
 * `buildPresentationPayload()` already minted for that song's `Presentation.selected_arrangement`
 * (see `encodePresentationPayload()`'s and `buildSetlistProFiles()`'s own doc-blocks in
 * propresenter-export.js) — this test PROVES that wiring actually holds, by independently
 * decoding BOTH the playlist item (via `pp7DecodePlaylistItemPresentation()`) AND its matched
 * sibling `.pro` file (via `pp7DecodePresentation()`) and asserting the two UUIDs are the exact
 * same string. Nothing about this assertion is circular: both decodes go through the shipped
 * PHP decoder, reading two DIFFERENT ZIP entries this run's export produced.
 *
 * MATCHING GOES THROUGH THE REAL PRODUCTION MATCHER, NOT A RE-DERIVED ONE: entry resolution uses
 * `_bulkImport_proplaylistMatchEntry()` (`includes/song_importers.php`, shipped in #1973) exactly
 * as `_bulkImport_processProplaylist()` itself does — proving the SHIPPED import glue, not just
 * the raw decoder, actually resolves what this exporter produces.
 *
 * MUTATION-PROVEN (rule #34), each performed by hand against the real working tree during this
 * task, this test re-run and confirmed RED, then reverted (`git diff --stat` empty afterwards):
 *   m1 — `appWeb/public_html/manage/editor/propresenter-export.js`'s
 *        `makePlaylistPresentationItem()`: changed the presentation payload's `document_path` key
 *        to `document_path_typo` (simulating a field-name drift between the exporter and the
 *        vendored schema) -> RED: `_bulkImport_proplaylistMatchEntry()` could no longer resolve
 *        either presentation item (both `documentPath.absoluteString`/`localPath` decoded empty,
 *        since protobufjs' `.create()` silently drops an unrecognised key rather than throwing —
 *        exactly the SILENT-failure shape this repo's CLAUDE.md rule #30/#35 warns about), so the
 *        "document_path resolves … to an embedded .pro entry" assertion failed for both items.
 *        Reverted -> green again.
 *   m2 — same file, `buildSetlistProFiles()`: changed `arrangementUuid: payload.selected_arrangement
 *        || null` to `arrangementUuid: null` (simulating the arrangement-UUID-reuse wiring being
 *        dropped) -> RED: the "arrangement is a real minted UUID" assertion failed for both songs
 *        (the playlist item's `arrangement` decoded back as an empty string — proto3
 *        message-typed fields have no "present but empty" state protobufjs would invent on its
 *        own; the UUID sub-message was simply never set). Reverted -> green again.
 *
 * Usage:
 *   php tests/php/test-pp7-setlist-roundtrip.php
 *
 * Requires a `node` binary on PATH (only to regenerate the fixture `.proplaylist` — the
 * assertions themselves are pure PHP), same posture as `test-pp7-roundtrip.php`.
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see tools/pp7-gen-setlist-roundtrip-sample.js                 the REAL exporter run against SAMPLE_SETLIST
 * @see appWeb/public_html/manage/editor/propresenter-export.js   exportSetlistAsProplaylist() (exporter under test)
 * @see appWeb/public_html/includes/propresenter7_playlist.php    pp7ReadPlaylistBundle()/pp7DecodePlaylistDocument() (decoder under test)
 * @see appWeb/public_html/includes/song_importers.php            _bulkImport_proplaylistMatchEntry()/_bulkImport_parsePro7() (production glue reused here)
 * @see tests/php/test-pp7-roundtrip.php                          the single-.pro sibling this mirrors
 * @see tests/php/test-pp7-playlist-decode.php                    the real-fixture-vs-protobufjs sibling this complements
 * @see .claude/propresenter-interop-1968-plan.md                 §5.2 / §8.3 — this test's brief
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_decode.php';
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_zip.php';
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/propresenter7_playlist.php';
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';

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

/** UUID v4-shaped string check (36 chars, hyphenated) — used to assert an arrangement reference
 *  is a REAL minted UUID, not an empty/degenerate value that happened to compare equal. */
function pp7SetlistLooksLikeUuid($v): bool
{
    return is_string($v) && (bool)preg_match(
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
        $v
    );
}

echo "\n#1968 P3-EXPORT — set-list .proplaylist EXPORT -> PHP decoder CLOSURE (our exporter -> our decoder)\n\n";

/* ============================================================================================
 * (1) Generate the fixture .proplaylist via the REAL exporter (a separate node process — see
 *     file header for why this must not be a same-process self-decode).
 * ============================================================================================ */

$repoRoot = dirname(__DIR__, 2);
$generatorScript = $repoRoot . '/tools/pp7-gen-setlist-roundtrip-sample.js';

ok('generator script exists (tools/pp7-gen-setlist-roundtrip-sample.js)', is_file($generatorScript));

$outPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'ihymns-pp7-setlist-roundtrip-' . bin2hex(random_bytes(6)) . '.proplaylist';

$nodeVersionProbe = @shell_exec('node --version 2>&1');
ok('a `node` binary is on PATH (required to regenerate the closure fixture — see file header)',
    is_string($nodeVersionProbe) && (bool)preg_match('/^v\d+\.\d+\.\d+/', trim($nodeVersionProbe)));

$bundle = null;
$rawBytes = null; // the full .proplaylist ZIP bytes -- kept around so the deep arrangement
                   // cross-check below can re-open a DIFFERENT entry (a matched .pro) from the
                   // SAME export without re-running the generator or touching a deleted temp file.

if ($failed === 0) {
    $cmd = escapeshellarg('node') . ' ' . escapeshellarg($generatorScript) . ' ' . escapeshellarg($outPath) . ' 2>&1';
    $cmdOutputLines = [];
    $exitCode = 1;
    exec($cmd, $cmdOutputLines, $exitCode);

    ok('generator exits 0 (output: ' . implode(' | ', $cmdOutputLines) . ')', $exitCode === 0);
    ok('generator wrote the fixture .proplaylist file', is_file($outPath));

    if ($exitCode === 0 && is_file($outPath)) {
        $body = file_get_contents($outPath);
        ok('generated .proplaylist is non-empty', $body !== false && strlen($body) > 0);

        if ($body !== false && strlen($body) > 0) {
            $rawBytes = $body;

            /* ========================================================================================
             * (2) Decode it back with the REAL PHP decoder.
             * ======================================================================================== */
            try {
                $bundle = pp7ReadPlaylistBundle($body);
            } catch (\Throwable $e) {
                ok('pp7ReadPlaylistBundle() decodes the generated .proplaylist without throwing ('
                    . $e->getMessage() . ')', false);
            }
        }
    }

    @unlink($outPath);
}

ok('pp7ReadPlaylistBundle() decoded the generated .proplaylist successfully', $bundle !== null);

/* ============================================================================================
 * (3) Assert against the known synthetic source (tools/pp7-gen-setlist-roundtrip-sample.js's
 *     SAMPLE_SETLIST / SAMPLE_SONGS — see that file for the exact fixture shape).
 * ============================================================================================ */

if ($bundle !== null && $rawBytes !== null) {
    $doc = $bundle['document'];

    /* -- top-level document shape -- */
    ok('document.type is TYPE_PRESENTATION (1)', $doc['type'] === 1);
    ok('root node carries exactly one child playlist', count($doc['playlists']) === 1);

    $playlist = $doc['playlists'][0] ?? null;
    ok('child playlist decoded', $playlist !== null);

    if ($playlist !== null) {
        ok('playlist name matches the set list\'s own name ("Roundtrip Service")',
            $playlist['name'] === 'Roundtrip Service');

        ok('playlist has exactly 3 items (1 header + 2 songs)', count($playlist['items']) === 3);

        $itemTypes = array_map(static fn ($it) => $it['itemType'], $playlist['items']);
        ok('item TYPES in order are [header, presentation, presentation] (got ['
            . implode(',', $itemTypes) . '])',
            $itemTypes === ['header', 'presentation', 'presentation']);

        /* -- the header item -- */
        $headerItem = $playlist['items'][0] ?? null;
        ok('header item name is "Welcome" (the non-song plan slot\'s label)',
            $headerItem !== null && $headerItem['name'] === 'Welcome');

        /* -- the two presentation items, IN ORDER (proves item ORDER survived, not just presence) -- */
        $expectedSongs = [
            ['title' => 'Test Roundtrip Song One', 'filenameSuffix' => 'Test Roundtrip Song One.pro'],
            ['title' => 'Test Roundtrip Song Two', 'filenameSuffix' => 'Test Roundtrip Song Two.pro'],
        ];

        $proEntries = $bundle['proEntries'];
        ok('bundle carries exactly 2 embedded .pro entries (one per song)', count($proEntries) === 2);

        // items[1] and items[2] are the two presentation items (items[0] is the header).
        foreach ($expectedSongs as $offset => $expectedSong) {
            $itemIndex = $offset + 1;
            $item = $playlist['items'][$itemIndex] ?? null;
            $label = "presentation item #{$itemIndex} ({$expectedSong['title']})";

            ok("{$label}: decoded", $item !== null);
            if ($item === null) {
                continue;
            }

            ok("{$label}: itemType is 'presentation'", $item['itemType'] === 'presentation');
            ok("{$label}: name matches the song's own title", $item['name'] === $expectedSong['title']);

            $presentation = $item['presentation'] ?? null;
            ok("{$label}: presentation sub-message decoded", $presentation !== null);

            if ($presentation === null) {
                continue;
            }

            /* document_path resolves through the REAL production matcher
               (_bulkImport_proplaylistMatchEntry(), song_importers.php — #1973) to one of the
               bundle's own embedded .pro entry names. This is the SAME function
               _bulkImport_processProplaylist() itself calls, so this proves the SHIPPED import
               glue -- not a re-derived stand-in -- actually resolves what this exporter
               produces. */
            $matched = _bulkImport_proplaylistMatchEntry($presentation['documentPath'], $proEntries);
            ok("{$label}: document_path resolves (via the REAL production matcher) to an embedded "
                . '.pro entry ending "' . $expectedSong['filenameSuffix'] . '" (got '
                . var_export($matched, true) . ')',
                is_string($matched) && str_ends_with($matched, $expectedSong['filenameSuffix']));

            ok("{$label}: arrangementName is 'Default' (the exporter's own single-arrangement name)",
                $presentation['arrangementName'] === 'Default');

            ok("{$label}: arrangement is a real minted UUID (not empty/degenerate)",
                pp7SetlistLooksLikeUuid($presentation['arrangement']));

            /* -- THE DEEPEST ASSERTION: cross-file arrangement UUID agreement -- decode the
               MATCHED sibling .pro (a completely separate ZIP entry from the one this item itself
               lives in) and assert its OWN arrangement UUID is the exact same string the playlist
               item references. See this file's own header for why this is the load-bearing proof
               of the arrangement-UUID-reuse wiring. -- */
            if (!is_string($matched)) {
                ok("{$label}: sibling .pro located for the deep arrangement cross-check", false);
                continue;
            }

            $zipEntries = pp7ZipListEntries($rawBytes);
            $proBytes = null;
            foreach ($zipEntries as $zEntry) {
                if (($zEntry['name'] ?? '') === $matched) {
                    $proBytes = pp7ZipReadEntry($rawBytes, $zEntry);
                    break;
                }
            }

            ok("{$label}: matched .pro entry bytes extracted from the SAME bundle", $proBytes !== null);
            if ($proBytes === null) {
                continue;
            }

            try {
                $decodedPro = pp7DecodePresentation($proBytes);
            } catch (\Throwable $e) {
                ok("{$label}: matched .pro decodes without throwing (" . $e->getMessage() . ')', false);
                continue;
            }

            ok("{$label}: sibling .pro's own arrangement UUID matches the playlist item's "
                . 'arrangement reference',
                $decodedPro['selectedArrangement'] === $presentation['arrangement']);
            ok("{$label}: sibling .pro's title matches the song's own title",
                $decodedPro['name'] === $expectedSong['title']);
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
    echo "\nA failure here means our OWN set-list exporter and our OWN .proplaylist decoder have\n";
    echo "drifted apart from each other -- a set list this app exports no longer decodes the way it\n";
    echo "was built. This is independent of whether the decoder is individually correct against real\n";
    echo "third-party ProPresenter files (test-pp7-playlist-decode.php covers that).\n";
    exit(1);
}
echo "\nAll ProPresenter 7+ set-list .proplaylist export->decode closure assertions passed.\n";
