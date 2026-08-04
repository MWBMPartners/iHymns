<?php

declare(strict_types=1);

/**
 * iHymns — Song permalink redirect follow test (#1343)
 *
 * Exercises the PURE songRedirectFollow() transitive resolver (no DB): direct,
 * chained, tombstone, cycle and not-redirected cases.
 *
 *   php tests/php/test-song-redirects.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_redirects.php';

$fail = 0;
function ok(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $fail++; }
}

/* A redirect map for the fake lookup: id => false (no row) | null (tombstone) | target. */
$map = [
    'A-0001' => 'B-0002',   // A redirects to B
    'B-0002' => 'C-0003',   // B redirects to C (chain)
    'D-0004' => null,        // tombstone (removed)
    'E-0005' => 'F-0006',   // cycle pair…
    'F-0006' => 'E-0005',   // …back to E
    // C-0003 and B-... live ids have NO row (lookup returns false)
];
$lookup = static function (string $id) use ($map) {
    return array_key_exists($id, $map) ? $map[$id] : false;
};

/* Not redirected — a live id with no row. */
$r = songRedirectFollow($lookup, 'C-0003');
ok('live id is not redirected', $r['redirected'] === false && $r['target'] === null && $r['tombstone'] === false);

/* Single hop A->B (B is live: no row for B? here B HAS a row → so test a 1-hop where target is live). */
$r = songRedirectFollow($lookup, 'B-0002');
ok('B resolves to live C', $r['redirected'] === true && $r['target'] === 'C-0003' && $r['tombstone'] === false);

/* Transitive A->B->C (C live). */
$r = songRedirectFollow($lookup, 'A-0001');
ok('A resolves transitively to C', $r['redirected'] === true && $r['target'] === 'C-0003' && $r['tombstone'] === false);

/* Tombstone. */
$r = songRedirectFollow($lookup, 'D-0004');
ok('tombstone resolves to removed', $r['redirected'] === true && $r['target'] === null && $r['tombstone'] === true);

/* Cycle E<->F → safe tombstone, never an infinite loop. */
$r = songRedirectFollow($lookup, 'E-0005');
ok('cycle resolves to tombstone', $r['redirected'] === true && $r['tombstone'] === true && ($r['cycle'] ?? false) === true);

/* Empty id and unknown id. */
ok('unknown id not redirected', songRedirectFollow($lookup, 'Z-9999')['redirected'] === false);

/* maxHops guard — a long chain beyond the cap degrades to a safe tombstone. */
$longLookup = static function (string $id) { return 'X' . ((int)substr($id, 1) + 1); };
$r = songRedirectFollow($longLookup, 'X0', 5);
ok('over-long chain degrades to tombstone (maxed)', $r['tombstone'] === true && ($r['maxed'] ?? false) === true);

/* ---------------------------------------------------------------------------
 * #1679 — SONGBOOK MOVE chains.
 *
 * A move re-keys the SongId to the new book's prefix and writes a redirect row
 * (Reason='move', includes/song_relocate.php). The properties that must hold —
 * and that a naive "one row, one hop" resolver would get wrong — are:
 *
 *   1. A song moved TWICE (MP -> CP -> HA) resolves from its ORIGINAL id
 *      straight to its CURRENT id. Curators do move a song again after
 *      realising the first destination was wrong; a resolver that stopped at
 *      the first hop would hand out an id that is itself dead.
 *   2. A merge redirect that pre-dates the move still lands on the live song.
 *      This is the case the FK does NOT cover in the same way: an OLD row's
 *      NewSongId is re-pointed by ON UPDATE CASCADE, but a row whose OldSongId
 *      is the moved id can only be resolved by FOLLOWING, and it exists
 *      whenever the song was merged into before it moved.
 *   3. A song moved and then deleted (tombstoned) reads as REMOVED (410), not
 *      as a live target and not as a plain 404.
 * ------------------------------------------------------------------------- */
$moveMap = [
    'MP-0100' => 'CP-0007',   // move 1: MP -> CP
    'CP-0007' => 'HA-0042',   // move 2: CP -> HA (HA-0042 is live)
    'OL-0009' => 'MP-0100',   // an older MERGE into the song, written before it moved
    'FS-0003' => 'PS-0011',   // moved…
    'PS-0011' => null,        // …then deleted with no replacement
];
$moveLookup = static function (string $id) use ($moveMap) {
    return array_key_exists($id, $moveMap) ? $moveMap[$id] : false;
};

$r = songRedirectFollow($moveLookup, 'MP-0100');
ok('#1679 twice-moved song resolves from its original id to the live id',
   $r['redirected'] === true && $r['target'] === 'HA-0042' && $r['tombstone'] === false);

$r = songRedirectFollow($moveLookup, 'CP-0007');
ok('#1679 the intermediate id also resolves to the live id',
   $r['redirected'] === true && $r['target'] === 'HA-0042');

$r = songRedirectFollow($moveLookup, 'OL-0009');
ok('#1679 a pre-move merge redirect still lands on the live song',
   $r['redirected'] === true && $r['target'] === 'HA-0042');

$r = songRedirectFollow($moveLookup, 'HA-0042');
ok('#1679 the live id itself is not redirected', $r['redirected'] === false);

$r = songRedirectFollow($moveLookup, 'FS-0003');
ok('#1679 moved-then-deleted reads as removed, not as a live target',
   $r['redirected'] === true && $r['target'] === null && $r['tombstone'] === true);

if ($fail === 0) { echo "\nAll song-redirect follow assertions passed.\n"; exit(0); }
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
