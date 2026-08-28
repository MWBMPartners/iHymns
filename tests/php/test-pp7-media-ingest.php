<?php

declare(strict_types=1);

/**
 * tests/php/test-pp7-media-ingest.php — #1968 P4 media-reference RESOLUTION
 * (plan §6.4/§6.7 G2), PURE + DB-FREE.
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * When a ProPresenter bundle says "this slide uses the video at
 * `/Users/.../Music Notes.mp4`", we have to find the matching file INSIDE the
 * bundle. This proves that matcher is right on every real path shape — and,
 * critically, that it returns NOTHING (rather than guessing) when it isn't sure,
 * because attaching the WRONG media to a song is the false-positive class the
 * owner's #1 rule for this epic bans.
 *
 * DETAILED
 * --------
 * Exercises the three PURE pieces of the ingest path — no database, no ZIP bytes:
 *   (A) `_bulkImport_pp7ResolveMediaRef()` over the three observed entry layouts
 *       (absolute external path; in-library `Media/x.png`; portable flat
 *       `CURRENT_RESOURCE`), percent-decoding (`Music%20Notes.mp4` → `Music
 *       Notes.mp4`), the same-basename → longest-suffix disambiguation, a genuine
 *       suffix tie → null, and an unmatched ref → null.
 *   (B) `_bulkImport_pp7KindFromMime()` + `_bulkImport_pp7Basename()` (the latter
 *       must split a Windows `C:\…` ref path too — real, from v7-feature-test-win).
 *   (C) the SPARSE `mediaRefs` parse exposure: a media-bearing fixture's parse
 *       carries the key; a media-free one omits it (rule #45 sparse precedent).
 *
 * MUTATION PROOF (rule #34 — applied to the real tree, re-run RED, reverted):
 *   - broke `rawurldecode()` in _bulkImport_pp7ResolveMediaRef (dropped it) →
 *     the `Music%20Notes.mp4` percent-decode row went RED (basename stayed
 *     `Music%20Notes.mp4`, never matching the decoded entry).
 *   - made the same-basename disambiguation take the FIRST candidate instead of
 *     the longest-suffix / tie→null → the ambiguous-tie row went RED (a guess
 *     was returned where null was required).
 *
 *   php tests/php/test-pp7-media-ingest.php
 *
 * Exit 0 = all pass, 1 = at least one failure.
 *
 * @see .claude/propresenter-interop-1968-plan.md §6.4, §6.7 (G2)
 * @see appWeb/public_html/includes/song_importers.php  the functions under test
 * @see #1968 P4, issue #1976
 */

$repo = dirname(__DIR__, 2);
require_once $repo . '/appWeb/public_html/includes/propresenter7_decode.php';
require_once $repo . '/appWeb/public_html/includes/song_importers.php';

$passed = 0; $failed = 0; $failures = [];
function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  \xE2\x9C\x85 {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/** Minimal media entry — the resolver only reads ['name']. */
function e(string $name): array { return ['name' => $name, 'method' => 0, 'size' => 0]; }
/** The resolved entry's name, or null. */
function resolvedName(?array $r): ?string { return $r === null ? null : (string)($r['name'] ?? ''); }

echo "\n#1968 P4 — media-reference resolution (pure, DB-free)\n\n";

/* ============================================================================
 * (A) _bulkImport_pp7ResolveMediaRef over the three layouts
 * ============================================================================ */
echo "-- (A) resolver truth table --\n";

/* Layout 1 — absolute external path (the owner's real 001 bundle shape): the
   ZIP entry NAME is the decoded absolute path; the ref's absoluteString is the
   SAME path percent-encoded (`%20`, `%5B`). */
$absEntry = e('/Users/church/Library/CloudStorage/OneDrive-SharedLibraries-CambridgeSeventh-dayAdventistChurch/ProjectionMedia - Documents/[Backgrounds]/Music/Music Notes.mp4');
$absIndex = _bulkImport_pp7IndexMediaByBasename([$absEntry]);
$absRef = [
    'absoluteString' => 'file:///Users/church/Library/CloudStorage/OneDrive-SharedLibraries-CambridgeSeventh-dayAdventistChurch/ProjectionMedia%20-%20Documents/%5BBackgrounds%5D/Music/Music%20Notes.mp4',
    'localRoot'      => 2,
    'localPath'      => 'Library/CloudStorage/OneDrive-SharedLibraries-CambridgeSeventh-dayAdventistChurch/ProjectionMedia - Documents/[Backgrounds]/Music/Music Notes.mp4',
];
ok('absolute-path ref resolves to its bundled entry (percent-decoded Music%20Notes.mp4 → Music Notes.mp4)',
    resolvedName(_bulkImport_pp7ResolveMediaRef($absRef, $absIndex)) === $absEntry['name']);

/* Layout 2 — in-library `Media/x.png`: exact-string match is unreliable BY
   DESIGN (roots differ); the decoded BASENAME still resolves. */
$libEntry = e('Media/dummy.png');
$libIndex = _bulkImport_pp7IndexMediaByBasename([$libEntry]);
$libRef = ['absoluteString' => 'file:///Users/curator/Downloads/pp-test/Media/dummy.png', 'localRoot' => 1, 'localPath' => 'Media/dummy.png'];
ok("in-library Media/x.png ref resolves by basename", resolvedName(_bulkImport_pp7ResolveMediaRef($libRef, $libIndex)) === $libEntry['name']);

/* Layout 3 — portable CURRENT_RESOURCE flat form: absoluteString is a bare
   filename, entry name is the same bare filename. */
$flatEntry = e('Loop.mov');
$flatIndex = _bulkImport_pp7IndexMediaByBasename([$flatEntry]);
$flatRef = ['absoluteString' => 'Loop.mov', 'localRoot' => 12, 'localPath' => 'Loop.mov'];
ok("portable CURRENT_RESOURCE flat ref resolves", resolvedName(_bulkImport_pp7ResolveMediaRef($flatRef, $flatIndex)) === $flatEntry['name']);

/* localPath-only ref (no absoluteString) still resolves by basename. */
$pathOnlyRef = ['absoluteString' => null, 'localRoot' => 2, 'localPath' => 'Some/Dir/dummy.png'];
ok("localPath-only ref (no absoluteString) resolves by basename", resolvedName(_bulkImport_pp7ResolveMediaRef($pathOnlyRef, $libIndex)) === $libEntry['name']);

/* Same basename in TWO entries (different dirs) — the longest common suffix of
   the full paths disambiguates. */
$twoIndex = _bulkImport_pp7IndexMediaByBasename([
    e('Media/A/clip.mp4'),
    e('Media/B/clip.mp4'),
]);
$refToB = ['absoluteString' => 'file:///x/Media/B/clip.mp4', 'localRoot' => 1, 'localPath' => 'Media/B/clip.mp4'];
ok("same-basename collision disambiguates by longest common suffix (→ Media/B/clip.mp4)",
    resolvedName(_bulkImport_pp7ResolveMediaRef($refToB, $twoIndex)) === 'Media/B/clip.mp4');

/* A genuine suffix TIE → null (never guess). Two entries whose names are equal
   suffixes of the ref path — the resolver must refuse. */
$tieIndex = _bulkImport_pp7IndexMediaByBasename([e('clip.mp4'), e('X/clip.mp4')]);
$tieRef = ['absoluteString' => null, 'localRoot' => 1, 'localPath' => 'clip.mp4'];
/* Both candidates end '…clip.mp4'; 'clip.mp4' matches len 8, 'X/clip.mp4' also
   matches its own 8-char tail against 'clip.mp4' — a tie → null. */
ok("ambiguous same-basename with a suffix tie → null (never guess)",
    _bulkImport_pp7ResolveMediaRef($tieRef, $tieIndex) === null);

/* Unmatched basename → null. */
$missRef = ['absoluteString' => 'file:///x/Nowhere/missing.png', 'localRoot' => 1, 'localPath' => 'Nowhere/missing.png'];
ok("unmatched ref → null (warn + skip, never guess)", _bulkImport_pp7ResolveMediaRef($missRef, $libIndex) === null);

/* Empty ref (no usable path) → null. */
ok("empty ref → null", _bulkImport_pp7ResolveMediaRef(['absoluteString' => null, 'localRoot' => null, 'localPath' => null], $libIndex) === null);

/* ============================================================================
 * (B) MIME→kind fold + Windows-aware basename
 * ============================================================================ */
echo "\n-- (B) MIME→kind fold + basename (Unix + Windows) --\n";
ok("video/mp4 → 'video'", _bulkImport_pp7KindFromMime('video/mp4') === 'video');
ok("video/quicktime → 'video'", _bulkImport_pp7KindFromMime('video/quicktime') === 'video');
ok("image/png → 'image'", _bulkImport_pp7KindFromMime('image/png') === 'image');
ok("audio/mpeg → 'audio'", _bulkImport_pp7KindFromMime('audio/mpeg') === 'audio');
ok("application/pdf → null (not an ingestible background kind)", _bulkImport_pp7KindFromMime('application/pdf') === null);
ok("'' → null", _bulkImport_pp7KindFromMime('') === null);

ok("basename of a Unix path", _bulkImport_pp7Basename('/Users/x/Media/Music Notes.mp4') === 'Music Notes.mp4');
ok("basename of a Windows path (splits on backslash — real, from v7-feature-test-win)",
    _bulkImport_pp7Basename('C:\\ProgramData\\Renewed Vision Media\\Images\\ImageSample1.jpg') === 'ImageSample1.jpg');
ok("basename of a bare filename", _bulkImport_pp7Basename('Loop.mov') === 'Loop.mov');

/* ============================================================================
 * (C) sparse mediaRefs parse exposure over the committed fixtures
 * ============================================================================ */
echo "\n-- (C) sparse mediaRefs parse exposure --\n";
$fx = $repo . '/tests/fixtures/propresenter';

$mediaBearing = 'owner-v21-002-sdah-sanitised.pro';   // has exactly one mediaRef
[$p1,] = _bulkImport_parsePro7((string)file_get_contents($fx . '/' . $mediaBearing));
ok("{$mediaBearing}: parse carries a non-empty 'mediaRefs' key", is_array($p1) && !empty($p1['mediaRefs']));
ok("{$mediaBearing}: the mediaRef's basename resolves to 'Music Notes.mp4'",
    is_array($p1) && _bulkImport_pp7Basename((string)($p1['mediaRefs'][0]['localPath'] ?? '')) === 'Music Notes.mp4');

$mediaFree = 'bussnet-amazing-grace.pro';             // a lyric-only fixture
[$p2,] = _bulkImport_parsePro7((string)file_get_contents($fx . '/' . $mediaFree));
ok("{$mediaFree}: parse OMITS the 'mediaRefs' key entirely (sparse — rule #45)", is_array($p2) && !array_key_exists('mediaRefs', $p2));

/* ---- summary ---- */
echo "\n" . ($failed === 0
    ? "PASS: {$passed} assertion(s) — the P4 media resolver never guesses.\n"
    : "FAIL: {$failed} of " . ($passed + $failed) . " assertion(s) failed:\n  - " . implode("\n  - ", $failures) . "\n");
exit($failed === 0 ? 0 : 1);
