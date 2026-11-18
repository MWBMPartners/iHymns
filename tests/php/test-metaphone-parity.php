<?php

declare(strict_types=1);

/**
 * test-metaphone-parity.php — the PHP copy of metaphone() gives PHP's own answers
 * ===============================================================================
 *
 * PLAIN-ENGLISH SUMMARY
 * PHP 8.6 marks the built-in `metaphone()` as outdated, so
 * includes/phonetic.php carries a PHP copy that is used from 8.6 on. This test
 * proves the copy gives the SAME answers as the original:
 *   1. PHP below 8.6: the copy is compared with the real `metaphone()` on a
 *      list of tricky words and on 5,000 random strings (fixed seed, so the
 *      same strings every run, including some non-English bytes).
 *   2. Every PHP version: the tricky words are compared with a frozen table of
 *      answers (tests/php/fixtures/metaphone-frozen.json) taken from the real
 *      function. This is what still protects the copy once 8.6 is the only
 *      version left to run the test on.
 *
 * HOW IT CAN FAIL (rule #34): change one letter rule in
 * ihymns_metaphone_userland() and both halves go red.
 *
 * To rebuild the frozen table (only if the original function ever changes):
 *   php tests/php/test-metaphone-parity.php --regenerate   (needs PHP < 8.6)
 *
 *   php tests/php/test-metaphone-parity.php
 *
 * @see appWeb/public_html/includes/phonetic.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/appWeb/public_html/includes/phonetic.php';

/* Hand-picked words that exercise every rule: silent letters, doubled letters,
   soft C/G, GH, PH, TH, SH/SCH, TIA/TIO, leading AE/GN/KN/PN/WR/WH/X, and the
   names used in the musician duplicate-scan test. */
$words = [
    'Knight', 'Wright', 'Schmidt', 'Xavier', 'Ghost', 'Phillip', 'Thomas', 'Wheeler',
    'Aaron', 'Gnome', 'Thumb', 'Dumb', 'Cough', 'Laugh', 'Night', 'Bridge', 'Science',
    'Church', 'Christ', 'School', 'Vision', 'Nation', 'Aeon', 'Wrestle', 'Whale',
    'Pneumonia', 'Wednesday', 'Mistletoe', 'Matchless', 'Edge', 'Judge', 'Yellow',
    'Eddie', 'James', "O'Brien", 'Newton', 'John', 'Charles', 'Wesley', 'Frances',
    'Alstyne', 'Fanny', 'Crosby', 'Anna-Marie', 'Smith', '', '123', "\xC3\xA9tienne",
];

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};

echo "tests/php/test-metaphone-parity.php — the metaphone copy matches PHP's own\n";

$frozenPath = __DIR__ . '/fixtures/metaphone-frozen.json';

if (in_array('--regenerate', $argv ?? [], true)) {
    if (PHP_VERSION_ID >= 80600) {
        fwrite(STDERR, "Needs PHP below 8.6 (the native function is the source of the answers).\n");
        exit(2);
    }
    $table = [];
    foreach ($words as $w) { $table[$w] = \metaphone($w); }
    file_put_contents($frozenPath, json_encode($table, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "wrote {$frozenPath}\n";
    exit(0);
}

/* ---- Half 2 (every version): the copy against the frozen answer table ---- */
$frozen = json_decode((string)@file_get_contents($frozenPath), true);
$check('frozen table loads and covers every hand-picked word',
    is_array($frozen) && count($frozen) === count(array_unique($words)));
foreach ($words as $w) {
    $got  = ihymns_metaphone_userland($w);
    $want = is_array($frozen) ? ($frozen[$w] ?? null) : null;
    $check('frozen: ' . json_encode($w) . ' => ' . json_encode($want), $got === $want, 'copy gave ' . json_encode($got));
}

/* ---- Half 1 (below 8.6 only): the copy against the real function ---- */
if (PHP_VERSION_ID >= 80600) {
    echo "  SKIP  live comparison with native metaphone() — PHP 8.6+ has it deprecated; the frozen table above is the guard here.\n";
} else {
    foreach ($words as $w) {
        $check('native: ' . json_encode($w), ihymns_metaphone_userland($w) === \metaphone($w));
    }
    /* 5,000 seeded random strings: letters, both cases, spaces, hyphen,
       apostrophe, digits and a few non-ASCII bytes. mt_srand with a fixed seed
       gives the same sequence every run (PHP 7.1+). */
    mt_srand(8600);
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz' . "  -'0123" . "\xC3\xA9\xFF";
    $max = strlen($alphabet) - 1;
    $bad = 0;
    $firstBad = '';
    for ($i = 0; $i < 5000; $i++) {
        $s = '';
        for ($n = mt_rand(0, 14); $n > 0; $n--) { $s .= $alphabet[mt_rand(0, $max)]; }
        if (ihymns_metaphone_userland($s) !== \metaphone($s)) {
            $bad++;
            if ($firstBad === '') { $firstBad = json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE); }
        }
    }
    $check('native: 5,000 seeded random strings all match', $bad === 0, "{$bad} differ, first {$firstBad}");
    $check('the wrapper uses the native function below 8.6', ihymns_metaphone('Knight') === \metaphone('Knight'));
}

$check('the wrapper gives the same answer as the copy for a plain word', ihymns_metaphone('Newton') === ihymns_metaphone_userland('Newton'));

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
