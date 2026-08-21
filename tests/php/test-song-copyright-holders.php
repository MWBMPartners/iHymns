<?php

declare(strict_types=1);

/**
 * iHymns — Multi-holder song copyright, pure-parts test (#1900, Wave 4 Commit C7)
 *
 * Covers the parts of the C7 schema+core landing that do not need a live
 * database:
 *   1. the statement joiner's new optional $holders arg
 *      (ihymns_copyright_statement(), includes/copyright_display.php) —
 *      0/1/n holders, and that the 0-holder (default) case is
 *      byte-identical to the pre-#1900 3-arg form;
 *   2. the IHYMNS_COPYRIGHT_HOLDER_ROLES vocabulary
 *      (includes/publisher_helpers.php) — deliberately DISTINCT from
 *      IHYMNS_PUBLISHER_ROLES;
 *   3. the pure de-dup/default/drop-empty fold
 *      (_songCopyHolders_normalizeRows(), includes/song_copyright_holders.php),
 *      including the FIRST-occurrence-wins ordering decision.
 *
 * The transactional M:N write + tblSongs denorm re-sync in
 * songCopyrightHoldersReplace() needs a live DB and is deliberately NOT
 * exercised here (its pure sub-step, the dedup fold, is what part 3 above
 * covers) — this mirrors every other tests/php/*.php pure-parser test that
 * runs with no database (e.g. test-openlyrics-parser.php).
 *
 *   php tests/php/test-song-copyright-holders.php
 *
 * Exit status 0 = all pass, 1 = at least one assertion failed.
 *
 * @see .claude/wave4-actionable-remainder-plan.md §C7
 * @see appWeb/public_html/includes/copyright_display.php
 * @see appWeb/public_html/includes/publisher_helpers.php
 * @see appWeb/public_html/includes/song_copyright_holders.php
 * @see #1900
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/publisher_helpers.php';
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/copyright_display.php';
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_copyright_holders.php';

$passed = 0; $failed = 0;
function aEq($actual, $expected, string $label): void
{
    global $passed, $failed;
    if ($actual === $expected) { echo "  PASS  $label\n"; $passed++; }
    else {
        echo "  FAIL  $label\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}
function aTrue($cond, string $label): void { aEq((bool)$cond, true, $label); }

/* ------------------------------------------------------------------------
 * 1. ihymns_copyright_statement() — the optional $holders arg (#1900).
 * ------------------------------------------------------------------------ */
echo "-- ihymns_copyright_statement() \$holders join --\n";

aEq(
    ihymns_copyright_statement('1978', 'Hope Publishing', 'Legacy text'),
    '1978 Hope Publishing',
    '3-arg form unchanged (years + holder)'
);
aEq(
    ihymns_copyright_statement('1978', 'Hope Publishing', 'Legacy text', []),
    ihymns_copyright_statement('1978', 'Hope Publishing', 'Legacy text'),
    '4-arg form with empty $holders === 3-arg form (byte-identical default, #1900)'
);
aEq(
    ihymns_copyright_statement('', '', 'Legacy text', []),
    'Legacy text',
    'both-empty structured fields still fall back to legacy with $holders=[] present'
);

/* 1 holder — identical output to passing that same name as $holder directly. */
aEq(
    ihymns_copyright_statement('1978', '', 'Legacy text', ['Hope Publishing']),
    ihymns_copyright_statement('1978', 'Hope Publishing', 'Legacy text'),
    '1-element $holders produces the same statement as passing that name as $holder'
);

/* n holders — joined with " / ", overriding $holder entirely. */
aEq(
    ihymns_copyright_statement('1978', 'ignored', 'Legacy text', ['A', 'B']),
    '1978 A / B',
    '2-element $holders joins with " / " and overrides $holder'
);
aEq(
    ihymns_copyright_statement('', '', '', ['A', 'B', 'C']),
    'A / B / C',
    '3-element $holders, no years, no legacy'
);

/* blank entries inside $holders are dropped, order preserved. */
aEq(
    ihymns_copyright_statement('1978', '', 'Legacy text', ['A', '  ', 'B']),
    '1978 A / B',
    'blank entries inside $holders are dropped, surviving order preserved'
);

/* all-blank $holders behaves exactly like an empty list. */
aEq(
    ihymns_copyright_statement('1978', 'Hope Publishing', 'Legacy text', ['   ', '']),
    '1978 Hope Publishing',
    'all-blank $holders falls back to $holder, same as []'
);

/* ------------------------------------------------------------------------
 * 2. IHYMNS_COPYRIGHT_HOLDER_ROLES — deliberate divergence from
 *    IHYMNS_PUBLISHER_ROLES (#1900).
 * ------------------------------------------------------------------------ */
echo "-- IHYMNS_COPYRIGHT_HOLDER_ROLES vocabulary --\n";

aTrue(array_key_exists('holder', IHYMNS_COPYRIGHT_HOLDER_ROLES), "'holder' is a valid copyright-holder role");
aTrue(array_key_exists('co-holder', IHYMNS_COPYRIGHT_HOLDER_ROLES), "'co-holder' is a valid copyright-holder role");
aTrue(array_key_exists('administrator', IHYMNS_COPYRIGHT_HOLDER_ROLES), "'administrator' is a valid copyright-holder role");
aTrue(array_key_exists('publisher', IHYMNS_COPYRIGHT_HOLDER_ROLES), "'publisher' is a valid copyright-holder role");
aTrue(!array_key_exists('distributor', IHYMNS_COPYRIGHT_HOLDER_ROLES), "'distributor' (a songbook-publisher-only role) is NOT a copyright-holder role");
aTrue(!array_key_exists('printer', IHYMNS_COPYRIGHT_HOLDER_ROLES), "'printer' (a songbook-publisher-only role) is NOT a copyright-holder role");
aTrue(!array_key_exists('imprint', IHYMNS_COPYRIGHT_HOLDER_ROLES), "'imprint' (a songbook-publisher-only role) is NOT a copyright-holder role");
aTrue(array_key_exists('distributor', IHYMNS_PUBLISHER_ROLES), "'distributor' IS a valid songbook-publisher role (proves the two vocabs genuinely diverge, not a typo)");

/* ------------------------------------------------------------------------
 * 3. _songCopyHolders_normalizeRows() — de-dup / default / drop-empty
 *    (#1900). Pure, no DB (rule #34).
 * ------------------------------------------------------------------------ */
echo "-- _songCopyHolders_normalizeRows() --\n";

aEq(
    _songCopyHolders_normalizeRows([
        ['publisherId' => 5, 'role' => 'holder'],
        ['publisherId' => 7, 'role' => 'co-holder'],
    ]),
    [
        ['publisherId' => 5, 'role' => 'holder'],
        ['publisherId' => 7, 'role' => 'co-holder'],
    ],
    'two distinct (publisher,role) pairs both survive, order preserved'
);

aEq(
    _songCopyHolders_normalizeRows([
        ['publisherId' => 5, 'role' => 'holder'],
        ['publisherId' => 5, 'role' => 'holder'],
    ]),
    [
        ['publisherId' => 5, 'role' => 'holder'],
    ],
    'exact (publisher,role) duplicate collapses to ONE row'
);

aEq(
    _songCopyHolders_normalizeRows([
        ['publisherId' => 5, 'role' => 'holder'],
        ['publisherId' => 5, 'role' => 'administrator'],
    ]),
    [
        ['publisherId' => 5, 'role' => 'holder'],
        ['publisherId' => 5, 'role' => 'administrator'],
    ],
    'same publisher in TWO different roles both survive (mirrors uq_song_pub_role allowing this)'
);

aEq(
    _songCopyHolders_normalizeRows([
        ['publisherId' => 0, 'role' => 'holder'],
        ['publisherId' => 5, 'role' => 'holder'],
    ]),
    [
        ['publisherId' => 5, 'role' => 'holder'],
    ],
    'a row with no resolved publisherId (<= 0) is dropped'
);

aEq(
    _songCopyHolders_normalizeRows([
        ['publisherId' => 5, 'role' => ''],
    ]),
    [
        ['publisherId' => 5, 'role' => 'holder'],
    ],
    'a blank role defaults to "holder"'
);

aEq(
    _songCopyHolders_normalizeRows([
        ['publisherId' => 5],
    ]),
    [
        ['publisherId' => 5, 'role' => 'holder'],
    ],
    'a missing role key defaults to "holder"'
);

/* THE decision this test exists to pin: FIRST occurrence wins, not last —
   see the "MUTATION-PROVE" note in the C7 brief. With input
   [5-holder, 9-holder, 5-holder], first-wins keeps 5 at its FIRST position
   (index 0) and drops the later duplicate, producing [5, 9]. A "last
   occurrence wins the position" mutant would instead produce [9, 5]. */
aEq(
    _songCopyHolders_normalizeRows([
        ['publisherId' => 5, 'role' => 'holder'],
        ['publisherId' => 9, 'role' => 'holder'],
        ['publisherId' => 5, 'role' => 'holder'],
    ]),
    [
        ['publisherId' => 5, 'role' => 'holder'],
        ['publisherId' => 9, 'role' => 'holder'],
    ],
    'FIRST occurrence of a duplicate (publisher,role) wins the ordering slot — the later duplicate is dropped, not promoted'
);

echo "\n$passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
