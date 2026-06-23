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

if ($fail === 0) { echo "\nAll song-redirect follow assertions passed.\n"; exit(0); }
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
