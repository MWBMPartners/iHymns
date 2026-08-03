<?php

declare(strict_types=1);

/**
 * test-musician-slug-resolve.php — the /writer/ → /musician/ legacy-slug ladder (#1741 P4a-3)
 * ==============================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING PINNED
 * ---------------------
 * `musicianLegacySlugPlan()` / `musicianResolveLegacySlug()` in
 * `includes/musician_helpers.php` are the ONE fold + ONE ladder shared by
 * THREE call sites (index.php's top-level 301, api.php's `case 'writer'`
 * via musician.php, and musician.php's own no-registry-row discography
 * fallback) that turn a legacy `/writer/<name-slug>` — or a name-slug
 * `/musician/` credit link — into the real `tblMusicians.Slug`. Getting
 * the RUNG ORDER wrong (names tried before slugs, or the exact-slug rung
 * skipped) either misses the common case or, worse, resolves to the wrong
 * registry row when a credited-name spelling collides with a different
 * person's slug.
 *
 * WHY THIS FILE CALLS FUNCTIONS INSTEAD OF READING SOURCE
 * ---------------------------------------------------------
 * Same reasoning as `test-song-redirect-claim.php`'s doc-block (which this
 * file's shape mirrors): the property under test — "which rung resolved
 * this, and in what order were the others tried" — is a BEHAVIOUR, and
 * behaviours have runtime handles. `musicianResolveLegacySlug()` takes its
 * two lookups as injected callables (the same seam `songRedirectFollow()`
 * has always used), so every case below runs with NO database — the
 * lookups are simple closures over a fixture map that also RECORD every
 * candidate they were asked about, so an assertion about ORDER is
 * evidence about what actually happened, not an accident of a lucky
 * return value.
 *
 * @see appWeb/public_html/includes/musician_helpers.php  musicianLegacySlugPlan() / musicianResolveLegacySlug()
 * @see tests/php/test-song-redirect-claim.php             the pure/driver + recording-spy pattern this mirrors
 * @see .claude/catalogue-1741-P4a3-plan.md §1.2, §4.2      the ladder's design + this file's spec
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

/* Direct access to musician_helpers.php is blocked by a
   `basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)` guard —
   see the file's own doc-block. That guard compares basenames, and this
   test script's own basename is `test-musician-slug-resolve.php`, never
   `musician_helpers.php`, so a plain require_once here sails straight
   through it exactly as every OTHER consumer of this file does. */
require_once $root . '/appWeb/public_html/includes/musician_helpers.php';

/* ========================================================================
 * 1 — musicianLegacySlugPlan(): the PURE fold, no DB, no lookups at all.
 * ======================================================================== */
echo "1 — musicianLegacySlugPlan(): the candidate plan a slug folds into\n";

$p1 = musicianLegacySlugPlan('john-newton');
ok("'john-newton' → slugs = ['john-newton'] (rung-2 fold is a no-op, deduped away)",
    $p1['slugs'] === ['john-newton'],
    'got: ' . json_encode($p1['slugs']));
ok("'john-newton' → names = ['John Newton', 'john-newton'] (CI-dedupe collapses the spaced-lower variant)",
    $p1['names'] === ['John Newton', 'john-newton'],
    'got: ' . json_encode($p1['names']));

$p2 = musicianLegacySlugPlan('charles-h.-gabriel');
ok("'charles-h.-gabriel' → slugs include the raw punctuated slug AND its slugifyMusicianName() fold",
    $p2['slugs'] === ['charles-h.-gabriel', 'charles-h-gabriel'],
    'got: ' . json_encode($p2['slugs']));

$p3 = musicianLegacySlugPlan('söderberg');
if (!class_exists('Normalizer')) {
    /* Mirrors slugifyMusicianName()'s own class_exists('Normalizer') guard
       — without the intl extension the diacritic fold is skipped and the
       slug is returned unfolded. Not a failure of THIS ladder; recorded so
       a CI run without intl doesn't silently look untested. */
    echo "  SKIP  'söderberg' NFKD-fold rung (ext-intl / Normalizer class not available in this PHP)\n";
} else {
    ok("'söderberg' → slugs include the NFKD-folded 'soderberg' (rung 2, ext-intl)",
        in_array('soderberg', $p3['slugs'], true),
        'got: ' . json_encode($p3['slugs']));
}

$p4 = musicianLegacySlugPlan('smith-jones');
ok("'smith-jones' → names include the raw hyphenated slug itself (the D4 no-silent-404 variant)",
    in_array('smith-jones', $p4['names'], true),
    'a hyphenated credited name ("Smith-Jones") only ever matches as a NAME via its raw-slug form — '
    . 'spacing the hyphen away turns it into "Smith Jones", which is a DIFFERENT string. got: '
    . json_encode($p4['names']));

/* Degenerate input: an empty slug must not crash and must not fabricate
   non-empty candidates out of nothing. */
$pEmpty = musicianLegacySlugPlan('');
ok('an EMPTY slug plans no candidates at all',
    $pEmpty === ['slugs' => [], 'names' => []],
    'got: ' . json_encode($pEmpty));

/* ========================================================================
 * 2 — musicianResolveLegacySlug(): the ladder, walked with recording spies.
 * ======================================================================== */
echo "\n2 — musicianResolveLegacySlug(): rung order, via recording spies\n";

/**
 * Build a $bySlug spy over a fixture map, recording every candidate it was
 * asked about (in order) into $asked. Mirrors claimLookup() in
 * test-song-redirect-claim.php.
 *
 * @param array<string,string> $rows candidate slug => canonical Slug on a hit
 */
function slugSpy(array $rows, array &$asked): callable
{
    return static function (string $cand) use ($rows, &$asked): ?string {
        $asked[] = $cand;
        return $rows[$cand] ?? null;
    };
}

/**
 * Build a $byName spy, recording the FULL name list it was called with (or
 * leaving $calls empty if it's never called at all — the ladder must only
 * reach $byName once BOTH slug rungs have missed).
 *
 * @param array<string,string> $rows candidate name => canonical Slug on a hit
 */
function nameSpy(array $rows, array &$calls): callable
{
    return static function (array $names) use ($rows, &$calls): ?string {
        $calls[] = $names;
        foreach ($names as $n) {
            if (isset($rows[$n])) { return $rows[$n]; }
        }
        return null;
    };
}

/* ---- rung 1 hit: exact slug — $byName must NEVER be consulted --------- */
$asked = []; $nameCalls = [];
$r1 = musicianResolveLegacySlug(
    slugSpy(['john-newton' => 'john-newton'], $asked),
    nameSpy([], $nameCalls),
    'john-newton'
);
ok("an exact registry-slug hit resolves on RUNG 1", $r1 === 'john-newton', 'got: ' . json_encode($r1));
ok('…and rung 1 (the raw slug) was tried FIRST',
    ($asked[0] ?? null) === 'john-newton',
    'asked order: ' . json_encode($asked));
ok('…and $byName was never consulted at all — a slug hit short-circuits the whole name rung',
    $nameCalls === [],
    'byName was called with: ' . json_encode($nameCalls));

/* ---- rung 2 hit: punctuated slug only resolves after the raw slug misses ---- */
$asked = []; $nameCalls = [];
$r2 = musicianResolveLegacySlug(
    slugSpy(['charles-h-gabriel' => 'charles-h-gabriel'], $asked), /* the RAW candidate misses */
    nameSpy([], $nameCalls),
    'charles-h.-gabriel'
);
ok('a punctuated slug resolves on RUNG 2 (the slugifyMusicianName() fold)',
    $r2 === 'charles-h-gabriel', 'got: ' . json_encode($r2));
ok('…and the RAW slug was tried before the folded one (order, not just presence)',
    $asked === ['charles-h.-gabriel', 'charles-h-gabriel'],
    'asked order: ' . json_encode($asked));
ok('…and $byName was STILL never consulted — rung 2 also short-circuits it',
    $nameCalls === [],
    'byName was called with: ' . json_encode($nameCalls));

/* ---- rung 3 hit: BOTH slug rungs miss before $byName is ever asked ---- */
$asked = []; $nameCalls = [];
$r3 = musicianResolveLegacySlug(
    slugSpy([], $asked),                          /* every slug candidate misses */
    nameSpy(['John Newton' => 'john-newton-2'], $nameCalls),
    'john-newton'
);
ok('when both slug rungs miss, a registry Name match resolves on RUNG 3',
    $r3 === 'john-newton-2', 'got: ' . json_encode($r3));
ok('…and BOTH slug candidates were tried (in order) before falling through',
    $asked === ['john-newton'],   /* slugifyMusicianName('john-newton') dedupes to the same candidate */
    'asked order: ' . json_encode($asked));
ok('…and $byName received the FULL deduped name candidate list in ONE call, not one name at a time',
    $nameCalls === [['John Newton', 'john-newton']],
    'byName call log: ' . json_encode($nameCalls));

/* ---- total miss: every rung tried, null returned, no exception -------- */
$asked = []; $nameCalls = [];
$r4 = musicianResolveLegacySlug(slugSpy([], $asked), nameSpy([], $nameCalls), 'nobody-here');
ok('when every rung misses, the ladder returns null (never a fatal, never a fabricated guess)',
    $r4 === null, 'got: ' . json_encode($r4));
ok('…having actually tried the slug rung', $asked === ['nobody-here'], 'asked: ' . json_encode($asked));
ok('…and the name rung too', $nameCalls !== [], 'byName was never consulted at all');

/* ---- an empty-string answer from a lookup is a MISS, not a hit -------- */
$asked = []; $nameCalls = [];
$r5 = musicianResolveLegacySlug(
    slugSpy(['john-newton' => ''], $asked),   /* answers '' — must NOT count as a hit */
    nameSpy(['John Newton' => 'john-newton-3'], $nameCalls),
    'john-newton'
);
ok("an empty-string '' answer from \$bySlug is treated as a MISS, not a hit — the ladder falls through to \$byName",
    $r5 === 'john-newton-3', 'got: ' . json_encode($r5));

/* ---- idempotence: a value that already IS canonical resolves to itself
   on rung 1, in one hop — this is what makes leg A (index.php) loop-safe:
   feeding a resolved slug back through the ladder can never redirect
   again. ---- */
$asked = []; $nameCalls = [];
$r6 = musicianResolveLegacySlug(
    slugSpy(['already-canonical' => 'already-canonical'], $asked),
    nameSpy([], $nameCalls),
    'already-canonical'
);
ok('IDEMPOTENCE: a slug the spy maps to ITSELF resolves to itself, on rung 1, in one hop',
    $r6 === 'already-canonical' && $asked === ['already-canonical'] && $nameCalls === [],
    'resolved=' . json_encode($r6) . ' asked=' . json_encode($asked) . ' nameCalls=' . json_encode($nameCalls));

echo "\n";
if ($fail > 0) {
    echo "$fail assertion(s) failed.\n";
    exit(1);
}
echo "All musician legacy-slug-resolve assertions passed.\n";
