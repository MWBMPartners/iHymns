<?php
/**
 * Guard: article-aware title sort key (#150).
 *
 * ihymns_title_sort_key() / ihymns_sort_by_title_key() (includes/sort_helpers.php)
 * alphabetise songbook names ignoring a single leading article, so "The
 * Australian Hymn Book" files under A. This is a behavioural unit test of the
 * pure functions — no DB needed (CI globs tests/php/*.php).
 *
 * Mutation check: comment out the preg_replace article-strip in
 * ihymns_title_sort_key() and this test goes red on the first two assertions.
 */

require __DIR__ . '/../../appWeb/public_html/includes/sort_helpers.php';

$fails = [];
$check = function (string $label, $got, $want) use (&$fails): void {
    if ($got !== $want) {
        $fails[] = "$label — got " . var_export($got, true) . ", want " . var_export($want, true);
    }
};

/* ---- ihymns_title_sort_key: strips ONE leading article, lowercases, keeps rest ---- */
$check('strip The',   ihymns_title_sort_key('The Australian Hymn Book'), 'australian hymn book');
$check('strip A',     ihymns_title_sort_key('A Mighty Fortress'),        'mighty fortress');
$check('strip An',    ihymns_title_sort_key('An Evening Hymn'),          'evening hymn');
$check('no article',  ihymns_title_sort_key('Mission Praise'),           'mission praise');
$check('lowercased',  ihymns_title_sort_key('ZION'),                     'zion');
/* Only a LEADING, whole-word article is stripped — not "Theology", not a mid-title "the". */
$check('not Theology', ihymns_title_sort_key('Theology Today'),          'theology today');
$check('mid the kept', ihymns_title_sort_key('All The Best'),            'all the best');
/* Romance/German forms in the shared vocab. */
$check('strip La',    ihymns_title_sort_key('La Paz'),                   'paz');
$check('strip Der',   ihymns_title_sort_key('Der Tag'),                  'tag');
/* Whitespace-only / empty edge cases never fatal. */
$check('empty',       ihymns_title_sort_key(''),                        '');
$check('spaces',      ihymns_title_sort_key('   '),                     '');

/* ---- ihymns_sort_by_title_key: full ordering, article-blind ---- */
$rows = [
    ['name' => 'The Australian Hymn Book'],
    ['name' => 'Advent Songs'],
    ['name' => 'A Mighty Fortress'],
    ['name' => 'Zion'],
    ['name' => 'The Baptist Hymnal'],
    ['name' => 'Mission Praise'],
];
ihymns_sort_by_title_key($rows, 'name');
$order = array_map(static fn($r) => $r['name'], $rows);
$expected = [
    'Advent Songs',              // advent
    'The Australian Hymn Book',  // australian
    'The Baptist Hymnal',        // baptist
    'A Mighty Fortress',         // mighty
    'Mission Praise',            // mission
    'Zion',                      // zion
];
$check('full ordering', $order, $expected);

/* Deterministic tie-break: two rows whose stripped keys collide keep raw order. */
$tie = [['name' => 'The Rock'], ['name' => 'A Rock'], ['name' => 'Rock']];
ihymns_sort_by_title_key($tie, 'name');
$check('tie stable (all -> "rock")', array_map(static fn($r) => $r['name'], $tie),
    ['A Rock', 'Rock', 'The Rock']); // all key to "rock"; secondary = raw lc: "a rock" < "rock" < "the rock"

if ($fails) {
    fwrite(STDERR, "FAIL: sort-helpers (#150):\n  - " . implode("\n  - ", $fails) . "\n");
    exit(1);
}
echo "PASS: article-aware title sort key — " . (11 + 2) . " assertions, leading article stripped, tie-break deterministic.\n";
