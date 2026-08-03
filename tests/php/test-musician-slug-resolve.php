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

/* ========================================================================
 * 3 — musicianResolveLegacySlug() rung 4 (#1754): the tblMusicianAliases
 *     name match, tried ONLY after every registry rung (1-3) misses.
 * ======================================================================== */
echo "\n3 — musicianResolveLegacySlug() rung 4 (#1754): alias-name match, via recording spies\n";

/**
 * Build a $byAliasName spy over a fixture map, recording the FULL name list
 * it was called with into $calls (or leaving $calls empty if never called),
 * mirroring nameSpy() above. $sequence is a SHARED log (passed by
 * reference, appended to by both this spy and nameSpy()'s sibling calls
 * below) so an assertion about ORDER — not just presence — is evidence
 * about what actually happened.
 *
 * @param array<string,string> $rows   candidate name => canonical Slug on a hit
 * @param list<array>          $calls  this spy's own call log (by reference)
 * @param list<string>         $sequence shared cross-spy call-order log (by reference)
 */
function aliasSpy(array $rows, array &$calls, array &$sequence): callable
{
    return static function (array $names) use ($rows, &$calls, &$sequence): ?string {
        $calls[] = $names;
        $sequence[] = 'byAliasName';
        foreach ($names as $n) {
            if (isset($rows[$n])) { return $rows[$n]; }
        }
        return null;
    };
}

/**
 * Same recording shape as nameSpy(), plus an append into the shared
 * $sequence log — kept as its own local function (rather than reusing
 * nameSpy() directly) so every rung-4 case below can opt into sequence
 * tracking without changing nameSpy()'s existing section-2 signature/
 * call sites.
 *
 * @param array<string,string> $rows candidate name => canonical Slug on a hit
 */
function nameSpySeq(array $rows, array &$calls, array &$sequence): callable
{
    return static function (array $names) use ($rows, &$calls, &$sequence): ?string {
        $calls[] = $names;
        $sequence[] = 'byName';
        foreach ($names as $n) {
            if (isset($rows[$n])) { return $rows[$n]; }
        }
        return null;
    };
}

/* ---- (1) rung-4 hit: resolves when rungs 1-3 all miss, called ONCE with
   the FULL deduped name list ---------------------------------------- */
$asked = []; $nameCalls = []; $aliasCalls = []; $sequence = [];
$r7 = musicianResolveLegacySlug(
    slugSpy([], $asked),                                    /* every slug candidate misses */
    nameSpySeq([], $nameCalls, $sequence),                  /* registry Name also misses */
    'john-newton',
    aliasSpy(['John Newton' => 'john-newton-alias'], $aliasCalls, $sequence)
);
ok('when rungs 1-3 all miss, a tblMusicianAliases Name match resolves on RUNG 4',
    $r7 === 'john-newton-alias', 'got: ' . json_encode($r7));
ok('…and $byAliasName was called EXACTLY ONCE',
    count($aliasCalls) === 1, 'alias call log: ' . json_encode($aliasCalls));
ok('…and $byAliasName received the FULL deduped name candidate list in that one call',
    $aliasCalls === [['John Newton', 'john-newton']],
    'alias call log: ' . json_encode($aliasCalls));

/* ---- (2) sequence: $byName is consulted strictly BEFORE $byAliasName -- */
ok('…and the call ORDER shows $byName consulted strictly before $byAliasName (not just both present)',
    $sequence === ['byName', 'byAliasName'],
    'sequence: ' . json_encode($sequence));

/* ---- (3) a $byName HIT never consults $byAliasName --------------------- */
$asked = []; $nameCalls = []; $aliasCalls = []; $sequence = [];
$r8 = musicianResolveLegacySlug(
    slugSpy([], $asked),
    nameSpySeq(['John Newton' => 'john-newton-registry'], $nameCalls, $sequence),
    'john-newton',
    aliasSpy(['John Newton' => 'john-newton-alias'], $aliasCalls, $sequence)
);
ok('a registry Name HIT (rung 3) resolves without ever trying rung 4',
    $r8 === 'john-newton-registry', 'got: ' . json_encode($r8));
ok('…and $byAliasName was never consulted at all — a rung-3 hit short-circuits rung 4',
    $aliasCalls === [], 'alias call log: ' . json_encode($aliasCalls));
ok('…confirmed by the shared sequence log too (only byName appears)',
    $sequence === ['byName'], 'sequence: ' . json_encode($sequence));

/* ---- (4) back-compat: omitting the 4th arg (3-arg call) still resolves/
   misses exactly as the existing r1-r6 assertions prove ----------------- */
$asked = []; $nameCalls = [];
$r9 = musicianResolveLegacySlug(
    slugSpy(['john-newton' => 'john-newton'], $asked),
    nameSpy([], $nameCalls),
    'john-newton'
);
ok('BACK-COMPAT: a 3-arg call (no $byAliasName) still resolves on rung 1, exactly as before #1754',
    $r9 === 'john-newton', 'got: ' . json_encode($r9));

$asked = []; $nameCalls = [];
$r10 = musicianResolveLegacySlug(slugSpy([], $asked), nameSpy([], $nameCalls), 'nobody-here');
ok('BACK-COMPAT: a 3-arg call still misses (returns null) exactly as before #1754 when nothing matches',
    $r10 === null, 'got: ' . json_encode($r10));

/* ---- (5) an empty-string '' answer from $byAliasName is a MISS, not a
   hit — overall result is null when every rung including rung 4 misses -- */
$asked = []; $nameCalls = []; $aliasCalls = [];
$r11 = musicianResolveLegacySlug(
    slugSpy([], $asked),
    nameSpy([], $nameCalls),
    'john-newton',
    aliasSpy(['John Newton' => ''], $aliasCalls, $sequence)   /* answers '' — must NOT count as a hit */
);
ok("an empty-string '' answer from \$byAliasName is treated as a MISS, not a hit — overall result is null",
    $r11 === null, 'got: ' . json_encode($r11));

/* ========================================================================
 * 3(source-scan) — the two public head-of-request entry points that must
 * consult the DB driver (#1754). Comment-stripped via token_get_all()
 * (rule #34) so a comment mentioning musicianResolveLegacySlugDb can never
 * satisfy this assertion. WHY THESE TWO AND NOT A TREE-DERIVED SWEEP:
 * verified this session that admin/API sites (api2.php, migration-registry.
 * php, SongData.php …) also do exact-slug `tblMusicians WHERE Slug = ?`
 * lookups where LADDER resolution would be WRONG (admin lookups must be
 * exact) — a sweep would flag correct code, the rule-#34 "guard so blunt
 * it gets weakened" anti-pattern. index.php's /musician/ elseif block and
 * musician.php's pre-lookup region are structural, public, head-of-request
 * entry points, not a growing list; scoping to them is the narrow-enough
 * guard.
 * ======================================================================== */
echo "\n3(source-scan) — index.php /musician/ + musician.php pre-lookup both call the DB driver\n";

/**
 * Strip PHP comments (T_COMMENT/T_DOC_COMMENT) via token_get_all() so a
 * regex scan can never be satisfied by a mention inside a comment.
 */
function musSliceStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) { continue; }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

/**
 * Slice from the `preg_match('#^/musician/` needle to the next top-level
 * `elseif (preg_match` occurrence (or end of file) — bounded, mirroring
 * seimSliceCase()'s shape in test-song-external-id-mirror.php.
 */
function musSliceMusicianElseif(string $src): string
{
    $needle = "preg_match('#^/musician/";
    $start = strpos($src, $needle);
    if ($start === false) { return ''; }
    $next = strpos($src, 'elseif (preg_match', $start + strlen($needle));
    return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
}

/**
 * #1754 — true when $slice both CALLS musicianResolveLegacySlugDb() AND flows
 * its return value into $personSlug (directly, or via an intermediate var).
 *
 * ELI5: not just "is the resolver mentioned?" but "is its ANSWER actually
 * used?" — a call whose result is discarded (`musicianResolveLegacySlugDb(...);`)
 * is the exact silent-no-op shape CLAUDE.md rules #30/#33 warn about, and a
 * mere "is it mentioned" scan stays green on it (an adversarial audit proved
 * the first-draft §3 assertion did).
 *
 * DETAILED: capture the variable the call is assigned to, then require
 * $personSlug to be assigned FROM that variable (the code does
 * `$v = musicianResolveLegacySlugDb(...); if ($v !== null) { $personSlug = $v; }`).
 * The direct form `$personSlug = musicianResolveLegacySlugDb(...)` is also
 * accepted. Comment-stripping is the caller's responsibility (done before this).
 */
function musResolverResultFlowsToPersonSlug(string $slice): bool
{
    if ($slice === '') { return false; }
    /* direct: $personSlug = musicianResolveLegacySlugDb(...) */
    if (preg_match('/\$personSlug\s*=\s*musicianResolveLegacySlugDb\s*\(/', $slice) === 1) {
        return true;
    }
    /* indirect: capture the assigned var, require $personSlug = <that var> */
    if (preg_match('/(\$\w+)\s*=\s*musicianResolveLegacySlugDb\s*\(/', $slice, $m) !== 1) {
        return false;
    }
    $var = $m[1];
    return preg_match('/\$personSlug\s*=\s*' . preg_quote($var, '/') . '\b/', $slice) === 1;
}

/* --- Mutation self-tests for the two slicer helpers above, in memory,
   fails-high/fails-low + the comment-only-mention fixture (rule #34). --- */
$srcScanMutationFailures = [];

/* --- musResolverResultFlowsToPersonSlug() self-test (#1754) --- */
$fixtureFlowIndirect = "\$_r = musicianResolveLegacySlugDb(\$db, \$personSlug);\nif (\$_r !== null) { \$personSlug = \$_r; }\n";
if (!musResolverResultFlowsToPersonSlug($fixtureFlowIndirect)) {
    $srcScanMutationFailures[] = 'musResolverResultFlowsToPersonSlug() FAILS-HIGH: did not recognise the indirect assign-then-apply flow';
}
$fixtureDiscarded = "musicianResolveLegacySlugDb(\$db, \$personSlug); // result discarded\n";
if (musResolverResultFlowsToPersonSlug($fixtureDiscarded)) {
    $srcScanMutationFailures[] = 'musResolverResultFlowsToPersonSlug() FAILS-LOW: a discarded-return call (silent no-op) wrongly passed';
}

$fixtureCommentOnly = "<?php\n// musicianResolveLegacySlugDb mentioned ONLY in this comment\n\$real = 'NeedleInCode';\n";
$fixtureCommentStripped = musSliceStripComments($fixtureCommentOnly);
if (strpos($fixtureCommentStripped, 'musicianResolveLegacySlugDb') !== false) {
    $srcScanMutationFailures[] = 'musSliceStripComments() FAILS-LOW self-test: a comment-only mention of musicianResolveLegacySlugDb survived stripping';
}
if (strpos($fixtureCommentStripped, 'NeedleInCode') === false) {
    $srcScanMutationFailures[] = 'musSliceStripComments() FAILS-HIGH self-test: real code was stripped along with the comment';
}

$fixtureElseifSrc = "    elseif (preg_match('#^/other/([a-z]+)\$#', \$requestPath, \$m)) {\n        NeedleBeforeMusician();\n    }\n    elseif (preg_match('#^/musician/([a-z0-9\\-]+)\$#', \$requestPath, \$matches)) {\n        NeedleInMusicianBlock();\n    }\n    elseif (preg_match('#^/work/([a-z0-9\\-]+)\$#', \$requestPath, \$matches)) {\n        NeedleAfterMusician();\n    }\n";
$elseifSlice = musSliceMusicianElseif($fixtureElseifSrc);
if (strpos($elseifSlice, 'NeedleInMusicianBlock') === false) {
    $srcScanMutationFailures[] = 'musSliceMusicianElseif() FAILS-HIGH self-test did not find the needle inside its own /musician/ fixture block';
}
if (strpos($elseifSlice, 'NeedleBeforeMusician') !== false) {
    $srcScanMutationFailures[] = "musSliceMusicianElseif() FAILS-LOW self-test: the slice bled BACKWARD into the preceding elseif block";
}
if (strpos($elseifSlice, 'NeedleAfterMusician') !== false) {
    $srcScanMutationFailures[] = "musSliceMusicianElseif() FAILS-LOW self-test: the slice bled FORWARD into the following elseif block";
}
if (musSliceMusicianElseif("<?php\necho 'no musician route here';\n") !== '') {
    $srcScanMutationFailures[] = 'musSliceMusicianElseif() FAILS-LOW self-test wrongly matched a fixture with no /musician/ route at all';
}

if ($srcScanMutationFailures) {
    foreach ($srcScanMutationFailures as $f) {
        echo "  FAIL  (mutation self-test) $f\n";
        $fail++;
    }
}

$indexPhpFile = $root . '/appWeb/public_html/index.php';
$musicianPhpFile = $root . '/appWeb/public_html/includes/pages/musician.php';
if (!is_readable($indexPhpFile)) {
    fwrite(STDERR, "FATAL: could not read {$indexPhpFile}\n");
    exit(1);
}
if (!is_readable($musicianPhpFile)) {
    fwrite(STDERR, "FATAL: could not read {$musicianPhpFile}\n");
    exit(1);
}

$indexPhpStripped = musSliceStripComments((string)file_get_contents($indexPhpFile));
$musicianElseifSlice = musSliceMusicianElseif($indexPhpStripped);
ok("index.php's (comment-stripped) /musician/ elseif block resolves via musicianResolveLegacySlugDb AND applies the result to \$personSlug (not a discarded no-op)",
    musResolverResultFlowsToPersonSlug($musicianElseifSlice),
    'slice length: ' . strlen($musicianElseifSlice));

/* musician.php's "pre-lookup region": file head through the `WHERE Slug = ?`
   prepare — bounded at the first occurrence of that literal so the window
   can't run away past it. */
$musicianPhpStripped = musSliceStripComments((string)file_get_contents($musicianPhpFile));
$slugWherePos = strpos($musicianPhpStripped, 'WHERE Slug = ?');
$musicianPreLookupSlice = $slugWherePos === false ? '' : substr($musicianPhpStripped, 0, $slugWherePos);
ok("musician.php's (comment-stripped) pre-lookup region resolves via musicianResolveLegacySlugDb AND applies the result to \$personSlug (not a discarded no-op)",
    musResolverResultFlowsToPersonSlug($musicianPreLookupSlice),
    'slice length: ' . strlen($musicianPreLookupSlice));

echo "\n";
if ($fail > 0) {
    echo "$fail assertion(s) failed.\n";
    exit(1);
}
echo "All musician legacy-slug-resolve assertions passed.\n";
