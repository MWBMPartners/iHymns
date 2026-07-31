<?php

declare(strict_types=1);

/**
 * test-song-redirect-claim.php — a redirect outranks the number heuristic (#1689)
 * ==============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING PINNED
 * --------------------
 * `SongData::getSongById()` falls back to `getSongByNumber($prefix, $number)`
 * when an exact SongId misses. `tblSongs`' `(SongbookAbbr, Number)` index is
 * NON-UNIQUE and a `Number` can drift from the digits in its SongId through
 * ordinary curation, so for a dead or moved id that fallback could return
 * WHATEVER NOW HOLDS THAT NUMBER — HTTP 200, a different song, and no
 * `redirectedFrom` — ahead of the redirect row that knew where the song really
 * went.
 *
 * The fix suppresses the fallback when a redirect CLAIMS the id, and this file
 * is the lock on the word "claims".
 *
 * WHY THIS FILE CALLS FUNCTIONS INSTEAD OF READING SOURCE
 * ------------------------------------------------------
 * Because the property is a behaviour, and behaviours have runtime handles.
 * Four consecutive rounds on this branch shipped a guard that was green while
 * the thing it guarded was broken — every one of them because source inspection
 * was used as evidence for something a test could simply have CALLED
 * (`tests/php/test-transaction-fatal.php` documents that regress in full).
 *
 * `songRedirectClaims()` takes its one-hop lookup as a parameter, so every case
 * below runs with no database and no connection: the lookup is just a closure
 * over a fixture array. That is the same seam `songRedirectFollow()` has always
 * had, reused rather than reinvented.
 *
 * THE CASE THAT MATTERS MOST is the TOMBSTONE. "This song was removed" is as
 * definite a statement as "it moved to X", and if it did not count as a claim
 * the heuristic would resurrect a DIFFERENT song in the removed one's place —
 * the precise outcome #1689 exists to prevent. A reviewer optimising
 * `songRedirectClaims()` down to "does it resolve to a live target?" would
 * reintroduce the bug while making the code look tidier, so that case is
 * asserted twice: once on the predicate, once on the shape it derives from.
 *
 * @see appWeb/public_html/includes/song_redirects.php  songRedirectClaims()
 * @see appWeb/public_html/includes/SongData.php        getSongById()'s fallback
 * @link https://dev.mysql.com/doc/refman/8.0/en/create-index.html  non-unique indexes
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

require_once $root . '/appWeb/public_html/includes/song_redirects.php';

/**
 * Build a one-hop lookup over a fixture map, matching the THREE-VALUED contract
 * the real `songRedirectLookup()` returns:
 *   absent key => false  (no row)
 *   null value => null   (tombstone row)
 *   string     => string (forwards here)
 *
 * It also records every id it was asked about, so a test can assert that a
 * decision was reached BY LOOKING rather than by accident — a verdict returned
 * without consulting the data is not the check we think it is.
 */
function claimLookup(array $rows, array &$asked): callable
{
    return static function (string $id) use ($rows, &$asked) {
        $asked[] = $id;
        return array_key_exists($id, $rows) ? $rows[$id] : false;
    };
}

echo "1 — songRedirectClaims(): which ids the redirect layer speaks for\n";

/* ---- no row at all ----------------------------------------------------- */
$asked = [];
ok('an id with NO redirect row is NOT claimed — the number fallback may run',
   songRedirectClaims(claimLookup([], $asked), 'MP-1008') === false,
   'this is the ordinary case: nearly every request takes it, and reporting a '
   . 'claim here would disable the drift-forgiving fallback for the whole app');

ok('…and it reached that answer by actually asking',
   $asked === ['MP-1008'],
   'the lookup was not consulted, so the "false" is an accident rather than a '
   . 'finding — asked: ' . json_encode($asked));

/* ---- a live forward ---------------------------------------------------- */
$asked = [];
ok('an id that FORWARDS to a live song IS claimed',
   songRedirectClaims(claimLookup(['MP-1008' => 'CP-0412'], $asked), 'MP-1008') === true,
   'the #1679 songbook-move case: without this, a request for the old id can '
   . 'match whatever now holds number 1008 in MP and serve it as a 200');

/* ---- THE TOMBSTONE — the case a "tidying" refactor would break ---------- */
$asked = [];
$tombstoneClaimed = songRedirectClaims(claimLookup(['MP-1008' => null], $asked), 'MP-1008');
ok('a TOMBSTONE (removed, no replacement) IS claimed',
   $tombstoneClaimed === true,
   'a tombstone resolves to a NULL target, so a predicate written as "does this '
   . 'resolve to a live song?" answers false here — and the number fallback then '
   . 'serves a DIFFERENT song in the removed one\'s place, at 200, with nothing '
   . 'anywhere going red. This is the single most important assertion in the file.');

ok('…and the underlying resolve really does report it as a tombstone with a null target',
   (static function () use (&$asked) {
       $r = songRedirectFollow(claimLookup(['MP-1008' => null], $asked), 'MP-1008');
       /* array_key_exists, NOT `??`: the value being asserted IS null, and `??`
          returns its default for a present-but-null key just as readily as for
          an absent one. Written with `??` first, this assertion failed against
          correct code — which is the other half of rule #34's warning, since a
          guard that cries wolf gets weakened or deleted rather than fixed.
          https://www.php.net/manual/en/language.types.null.php */
       return array_key_exists('target', $r)
           && $r['target'] === null
           && ($r['redirected'] ?? null) === true
           && ($r['tombstone'] ?? null) === true;
   })(),
   'the claim above would still pass if songRedirectFollow() stopped reporting '
   . 'tombstones this way, so the shape it derives from is pinned too');

/* ---- transitive chains ------------------------------------------------- */
$asked = [];
ok('a TRANSITIVE chain (A→B→C) claims the original id',
   songRedirectClaims(claimLookup(['A-1' => 'B-2', 'B-2' => 'C-3'], $asked), 'A-1') === true);

ok('…and the FINAL target of that chain is itself unclaimed, so it still resolves normally',
   songRedirectClaims(claimLookup(['A-1' => 'B-2', 'B-2' => 'C-3'], $asked), 'C-3') === false,
   'if the end of a chain counted as claimed, following a redirect would land on '
   . 'a page that then refused its own number fallback — a redirect loop in effect');

/* ---- malformed chains are still claims --------------------------------- */
$asked = [];
ok('a CYCLE is a claim, not a free id',
   songRedirectClaims(claimLookup(['A-1' => 'B-2', 'B-2' => 'A-1'], $asked), 'A-1') === true,
   'a malformed chain is still a statement that this id is not free; treating it '
   . 'as unclaimed would hand the id straight back to the heuristic');

/* A chain longer than the hop limit reports redirected+maxed. Built from the
   limit itself rather than a typed-out number of rows, so raising maxHops does
   not silently turn this into a test of an ordinary chain (rule #34 — derive it,
   do not hardcode it). */
$long = [];
for ($i = 0; $i < 40; $i++) { $long['H-' . $i] = 'H-' . ($i + 1); }
$asked = [];
ok('a chain longer than the hop limit is a claim too',
   songRedirectClaims(claimLookup($long, $asked), 'H-0') === true,
   'exhausting maxHops means "I could not resolve this", which is not the same '
   . 'as "nothing claims it"');

/* ---- degenerate input -------------------------------------------------- */
$asked = [];
ok('an EMPTY id is not claimed, and costs no lookup',
   songRedirectClaims(claimLookup(['' => 'X-1'], $asked), '') === false && $asked === [],
   'an empty id reaching the redirect layer is a caller bug; answering "claimed" '
   . 'would make it un-debuggable, and querying for it is pure waste');

echo "\n2 — the suppression is actually wired into the read path\n";

/* Source-shaped, and legitimately so: "this call site exists, in this order"
   has no runtime handle short of a live database with a drifted Number. The
   BEHAVIOUR of the decision is covered above; what is left is that getSongById()
   consults it, and consults it BEFORE the fallback rather than after. */
$songDataRaw = file_get_contents($root . '/appWeb/public_html/includes/SongData.php');
ok('SongData.php was read', is_string($songDataRaw) && $songDataRaw !== '');

/**
 * Strip PHP comments using PHP'S OWN TOKENIZER, preserving byte offsets.
 *
 * ELI5: blank out the comments before searching, so a sentence that merely
 * MENTIONS a function is never mistaken for a call to it.
 *
 * This is not defensive coding, it is a bug that actually happened. The scan
 * below anchored on the FIRST occurrence of `songRedirectClaimsId(` in the file.
 * When #1694's sweep added a class doc-block at :231 that MENTIONS the consult in
 * prose, the anchor jumped ~1,978 lines to that comment, the search region became
 * almost the whole file, and the scan matched an unrelated `if (...) { return
 * null; }` — reporting correct code as broken.
 *
 * `token_get_all()` is exact where a regex cannot be: it knows a `//` inside a
 * string is not a comment and a `$` inside a comment is not a variable. Comments
 * are replaced with spaces of the SAME LENGTH so every subsequent strpos offset
 * still lines up with the real file.
 *
 * @link https://www.php.net/manual/en/function.token-get-all.php
 */
function stripPhpComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            $text = $tok[1];
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                /* Keep newlines so line numbers in any diagnostic stay true. */
                $out .= preg_replace('/[^\n]/', ' ', $text);
                continue;
            }
            $out .= $text;
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

$songData = stripPhpComments($songDataRaw);
ok('…and its comments stripped without changing its length',
   strlen($songData) === strlen($songDataRaw),
   'offsets would no longer line up with the real file: ' . strlen($songData)
   . ' vs ' . strlen($songDataRaw));
ok('…and stripping really removed the prose mention that broke this scan',
   substr_count($songDataRaw, 'songRedirectClaimsId(') > substr_count($songData, 'songRedirectClaimsId('),
   'no comment mention was removed, so this guard is not actually protected from the '
   . 'anchor-jump bug it was written to survive (raw='
   . substr_count($songDataRaw, 'songRedirectClaimsId(') . ', stripped='
   . substr_count($songData, 'songRedirectClaimsId(') . ')');

$posClaim    = strpos($songData, 'songRedirectClaimsId(');
$posFallback = strpos($songData, 'return $this->getSongByNumber($prefix, $number);');

ok('getSongById() consults songRedirectClaimsId()',
   $posClaim !== false,
   'the redirect check is gone from the read path — a moved song\'s old id can '
   . 'again resolve to whatever now holds that number (#1689)');

ok('…and it does so BEFORE the number fallback, not after',
   $posClaim !== false && $posFallback !== false && $posClaim < $posFallback,
   'order is the entire fix: consulted after the fallback, the wrong song has '
   . 'already been chosen and returned');

/* The suppression must RETURN, not merely compute — the #1679 A12 defect, "a gate
   whose answer is DISCARDED is decoration", which has now been reproduced twice in
   freshly-written files on this branch.

   TWO EARLIER VERSIONS OF THIS ASSERTION WERE WRONG, in opposite directions, and
   both are worth recording because each is a documented failure mode:

     1. It first required the call and the `return null;` to be textually
        ADJACENT, so it failed on the perfectly ordinary
        `$claimedByRedirect = songRedirectClaimsId(...); if ($claimedByRedirect)`.
        A guard that fails on correct code gets weakened or deleted rather than
        fixed (rule #34) — that version was the more dangerous of the two.
     2. The replacement scanned forward FROM the call, so the `if (` wrapping it
        — which sits EARLIER in the file — was outside the window it searched.
        It went red against the real tree on its first run.

   What finally works is not a cleverer regex but a different question: find the
   `if (…) { return null; }` by BALANCED PARENTHESES, then require its condition
   to be the claim ALONE. That accepts both correct spellings, and it rejects
   both a condition that merely mentions the claim without depending on it (the
   #1679 A12 "discarded answer" shape) and one widened with an extra conjunct
   that could only narrow when the suppression fires. Verified by mutating each
   in turn against the real tree. */
/**
 * Return the condition of the `if (…) { return null; }` in $region, or null.
 *
 * Balanced-paren, not a regex: the condition we most expect to find is
 * `songRedirectClaimsId($this->db, $id)`, whose own parentheses defeat any
 * `\(([^)]*)\)` form — it would capture up to the FIRST `)` and report a
 * truncated condition. This is the same under-reporting shape as #1691's D7,
 * and a scanner that under-reports is worse than none because its tick reads as
 * coverage.
 */
function conditionGatingReturnNull(string $region): ?string
{
    $offset = 0;
    while (($at = strpos($region, 'if (', $offset)) !== false) {
        $i     = $at + 3;          // sits on the '('
        $depth = 0;
        $len   = strlen($region);
        for (; $i < $len; $i++) {
            if ($region[$i] === '(') { $depth++; }
            elseif ($region[$i] === ')') {
                $depth--;
                if ($depth === 0) { break; }
            }
        }
        if ($depth !== 0) { return null; }                 // unbalanced — give up
        $cond = substr($region, $at + 4, $i - $at - 4);
        $tail = substr($region, $i + 1);
        if (preg_match('/\A\s*\{\s*return null;/', $tail) === 1) {
            return trim($cond);
        }
        $offset = $at + 4;
    }
    return null;
}

/* The scan starts BEFORE the claim call, because the `if (` that wraps it (or
   the assignment on the line above) sits earlier in the file than the call
   itself — the first version of this started AT the call and could therefore
   never see its own enclosing `if`, which made the whole suite red against
   correct code. */
$scanStart = $posClaim !== false ? max(0, $posClaim - 240) : 0;
$region    = ($posClaim !== false && $posFallback !== false && $posClaim < $posFallback)
    ? substr($songData, $scanStart, $posFallback - $scanStart)
    : '';
$cond = $region !== '' ? conditionGatingReturnNull($region) : null;

/* The return must be gated on the claim ALONE. Two spellings are correct — the
   call inline, or assigned to a local first — and both are accepted. Anything
   ANDed alongside it is rejected on purpose: an extra conjunct can only ever
   narrow when the suppression fires, which is a weakening of the fix and should
   not pass silently. That is also what catches the #1679 A12 shape, where the
   answer is computed and then not actually depended upon. */
$gatedOnClaimAlone = false;
if ($cond !== null) {
    if (strpos($cond, 'songRedirectClaimsId(') === 0 && substr_count($cond, '&&') === 0
        && substr_count($cond, '||') === 0) {
        $gatedOnClaimAlone = true;
    } elseif (preg_match('/\A\$(\w+)\z/', $cond, $m) === 1) {
        /* A bare local — accept it only if THAT variable is what the claim was
           assigned to. `if ($unrelatedFlag)` must not qualify. */
        $gatedOnClaimAlone = preg_match(
            '/\$' . preg_quote($m[1], '/') . '\s*=\s*songRedirectClaimsId\(/', $region
        ) === 1;
    }
}

ok('…and the claim RETURNS null, gated on its answer ALONE',
   $gatedOnClaimAlone,
   'no `return null;` is gated purely on the claim before the fallback runs. '
   . 'Either the answer is computed and dropped (the #1679 A12 shape), or the '
   . 'condition has been widened with an extra conjunct that narrows when the '
   . 'suppression fires. Condition found: ' . json_encode($cond));

echo "\n3 — the DB driver keeps the fail-OPEN contract this read path needs\n";

/* A reader must not be turned into a 404 by a probe that broke: on an
   un-migrated install no redirect can exist, so the honest degradation is the
   pre-#1689 behaviour. This is the OPPOSITE call from songRelocateIdTaken(),
   which is about to mint a permanent id and uses the strict probe — the two
   contracts are asserted separately in test-optional-table-probes.php.

   ClaimProbeFailingMysqli was born in this file; the song soft-delete suite
   (#1694) became its second consumer, so it moved verbatim into the shared
   tests/php/lib/mysqli_doubles.php (modularity rule — extraction, not a copy). */
require_once __DIR__ . '/lib/mysqli_doubles.php';

$claimThrew = false;
$claimAnswer = null;
try {
    $claimAnswer = songRedirectClaimsId(new ClaimProbeFailingMysqli(), 'MP-1008');
} catch (\Throwable $e) {
    $claimThrew = true;
}
ok('songRedirectClaimsId() does NOT throw when the table probe fails',
   !$claimThrew,
   'a broken probe would propagate out of getSongById() and white-screen the '
   . 'hottest read path in the app — the #1228 STRICT-mode class');

ok('…and answers "not claimed", so the read degrades to pre-#1689 behaviour',
   $claimAnswer === false,
   'answering "claimed" on a failed probe would suppress the fallback for every '
   . 'id on an un-migrated install, converting working pages into 404s');

echo "\n";
if ($fail > 0) {
    echo "$fail assertion(s) failed.\n";
    exit(1);
}
echo "All redirect-claim assertions passed.\n";
