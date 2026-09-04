<?php

declare(strict_types=1);

/**
 * test-song-language-filter-coverage.php — every song read decides about language (#2069)
 * ===========================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Readers can tell iHymns which languages they read. This test makes sure
 * every part of the API that hands out songs has actually thought about that
 * choice — either it narrows the songs to those languages, or it says out loud,
 * in a comment, why it deliberately does not.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * Shuffle ignored the reader's languages for its whole life. A reader who had
 * picked English + Afrikaans pressed Shuffle and was handed songs in languages
 * they had explicitly filtered out.
 *
 * Nothing was broken in any way a machine could see. The SPA HAD been sending
 * the choice on every same-origin call all along (`X-Preferred-Languages`, from
 * `js/utils/api-client.js`, rule #31); `search`, `songs_list`, `songs`,
 * `songbooks` and `song_of_the_day` all read it; `random` simply never did, and
 * `SongData::getRandomSong()` had no parameter to receive it. No error, no
 * console warning, no failing test — the feature LOOKED entirely healthy and
 * was wrong only in a way a person had to notice and report. That is this
 * codebase's worst failure class (rule #30's silent no-op), and the reason a
 * comment saying "remember to filter" is not a mechanism (rule #35).
 *
 * WHAT IS ASSERTED (two halves, because one alone is escapable)
 * ------------------------------------------------------------
 * A. THE ENDPOINT HALF — in `api.php`, every unit that calls `$songData->`
 *    must either call `resolvePreferredLanguagesForRequest(` in its CODE view,
 *    or carry an `@lang-unfiltered: <reason>` marker in its COMMENTS view.
 *
 *    This is the half that catches the original bug: pre-fix `case 'random'`
 *    had neither, so this guard goes red on the unfixed tree.
 *
 * B. THE PLUMBING HALF — in `SongData.php`, any method that ACCEPTS an
 *    `array $langSubtags` parameter must actually DO something with it: either
 *    apply it (`applyLanguageFilterSql(` / `makeLanguageFilterPredicate(`), or
 *    hand it on to another method that is itself in this derived set. A method
 *    may not advertise the parameter and then quietly drop it.
 *
 *    Without B, half A is escapable in the most natural way possible: pass the
 *    resolved list to a method that ignores it. The endpoint looks correct at
 *    the call site and the filter never happens.
 *
 *    Forwarding is allowed because `searchSongs()` genuinely does it — it picks
 *    between `_runFulltextSearch()` and `_searchByLike()` and passes the list to
 *    whichever it uses. That is safe precisely BECAUSE the callee is in the same
 *    derived set and is therefore checked in its own right, so a chain cannot be
 *    used to smuggle the parameter into a method that drops it.
 *
 *    ⚠️ Half B is derived from the SIGNATURE text, not from the unit walker:
 *    `phpSourceUnits()` attributes a method's BODY to the method's unit but its
 *    SIGNATURE to `(file scope)`. The first draft of this guard matched
 *    `array $langSubtags` against unit CODE and therefore found exactly one
 *    "method" — `(file scope)` — and reported it as a violation while missing
 *    all five real ones. A scanner that under-reports is worse than none
 *    (#1701), which is why the floor below is not optional.
 *
 * WHY A MARKER, AND WHY THE LIST IS DERIVED
 * -----------------------------------------
 * Whether a given read SHOULD narrow by language is a judgement no scanner can
 * make: `song_detail` must NOT filter (the reader named that song; hiding it
 * would break every saved link), while `random` must (the reader named nothing,
 * so the app chooses — and choosing outside their languages is the complaint).
 * So the DECISION is declared in the source and the LIST is derived from the
 * tree — the same shape as `@deleted-visible:` in test-song-visibility-guard.php
 * (#1694), for the same reason: rule #34 says never type the list.
 *
 * A marker on a unit that DOES filter is also a failure. That combination means
 * either the marker went stale or the reason was never true, and a stale
 * exemption is how a guard quietly stops guarding.
 *
 * WHAT THIS CANNOT CATCH (stated limits, not oversights)
 * -----------------------------------------------------
 *  - Unit-level vouching: a unit that resolves the filter for one call and
 *    leaves a second `$songData->` call in the same unit unfiltered. Same
 *    limit test-song-visibility-guard.php states for the same reason.
 *  - Reads inside `includes/pages/*.php`, reached via `require` from a `?page=`
 *    case rather than through `$songData->` in the case itself.
 *  - Whether the filter is applied to the RIGHT column, or whether the SQL is
 *    correct at all. No SQL here is executed against MySQL.
 *
 * MUTATION-PROVEN (rule #34 — a guard whose first green run was never
 * challenged has, in this repo, repeatedly been silently wrong):
 *   M1  removed `resolvePreferredLanguagesForRequest(` from `case 'random'`
 *       (the pre-fix state)                                          -> RED (A)
 *   M2  removed `applyLanguageFilterSql(` from `getRandomSong()`,
 *       keeping the parameter (the "accepts it, ignores it" escape)  -> RED (B)
 *   M2b removed the `$langSubtags` forward from `searchSongs()`,
 *       keeping the parameter                                        -> RED (B)
 *   M2c mention `$langSubtags` in a decoy guard clause while forwarding
 *       `[]` to every callee (the hole the argument-list scan closed) -> RED (B)
 *   M3  added an `@lang-unfiltered:` marker to a case that DOES
 *       filter (the stale-exemption case)                            -> RED (C)
 *   M4  emptied the derived unit list (scanner under-report)         -> RED (floor)
 *   M5  removed the marker from `case 'stats'`                       -> RED (A)
 *   M6  reduced a marker to a bare `@lang-unfiltered:` with no
 *       reason — GREEN on the first draft, which read the comment's
 *       own closing marker as the reason; RED once stripped         -> RED (A)
 *
 * @see appWeb/public_html/includes/language_filter.php  the one filter core (#736)
 * @see tests/php/test-song-visibility-guard.php         the guard shape this mirrors (#1694)
 * @see tests/php/lib/php_source_units.php               the unit walker
 */

require_once __DIR__ . '/lib/php_source_units.php';

/**
 * Does this method body pass `$langSubtags` as an ARGUMENT to one of the
 * methods in $set? Walks the balanced parenthesis run after each `$this->X(`
 * so a nested call in an earlier argument cannot end the scan early.
 *
 * @param string              $body Collapsed code view of one unit.
 * @param array<string, true> $set  Method names that take array $langSubtags.
 */
function langSubtagsForwarded(string $body, array $set): bool
{
    $offset = 0;
    while (preg_match('/\$this->(\w+)\s*\(/', $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $callee = $m[1][0];
        $open   = $m[0][1] + strlen($m[0][0]) - 1;   // index of the '('
        $offset = $open + 1;

        if (!isset($set[$callee])) {
            continue;
        }

        $depth = 0;
        $len   = strlen($body);
        for ($i = $open; $i < $len; $i++) {
            if ($body[$i] === '(') {
                $depth++;
            } elseif ($body[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $args = substr($body, $open + 1, $i - $open - 1);
                    if (strpos($args, '$langSubtags') !== false) {
                        return true;
                    }
                    break;
                }
            }
        }
    }
    return false;
}


$root = dirname(__DIR__, 2);

/* The floor exists because a scanner that finds nothing prints green (#1701).
   15 is the count at the time of writing; a refactor that genuinely lowers it
   lowers this number consciously, in the same commit. */
const ENDPOINT_FLOOR = 12;
const PLUMBING_FLOOR = 4;

/* Shortest reason accepted after the marker. Not a style rule — it is the
   difference between a decision and a placeholder. Every real reason in
   api.php today is a full sentence; the number only has to be long enough
   that `@lang-unfiltered:` alone, or `@lang-unfiltered: todo`, cannot pass. */
const MARKER_MIN_REASON = 12;

$MARKER = '@lang-unfiltered:';

$fail = 0;

/* ===================================================================== *
 *  A. THE ENDPOINT HALF — api.php
 * ===================================================================== */

$apiRel  = 'appWeb/public_html/api.php';
$apiSrc  = file_get_contents($root . '/' . $apiRel);
if ($apiSrc === false) {
    fwrite(STDERR, "FAIL: cannot read {$apiRel}\n");
    exit(1);
}

$apiUnits      = phpSourceUnits($apiSrc);
$endpointUnits = 0;
$unmarked      = [];
$staleMarkers  = [];

foreach ($apiUnits as $name => $u) {
    /* Derived, never typed: any unit that reaches the song data layer. */
    if (strpos($u['code'], '$songData->') === false) {
        continue;
    }
    $endpointUnits++;

    $filters = strpos($u['code'], 'resolvePreferredLanguagesForRequest(') !== false;

    /* The marker must carry a REASON — a bare tag is not a decision, it is a
       promise to think about it later. The comment DELIMITERS have to come off
       first: the raw comment token still carries its own closing marker, so an
       early draft read that closing marker AS the reason and let a bare tag
       through (mutation M6 in the doc-block above). */
    $marked = false;
    foreach ($u['comments'] as $c) {
        $at = strpos($c, $MARKER);
        if ($at === false) continue;
        $reason = substr($c, $at + strlen($MARKER));
        $reason = (string)preg_replace('~\*/\s*$~', '', $reason);   // trailing close
        $reason = (string)preg_replace('~^[\s*]+~m', '', $reason);   // leading ` * ` per line
        $reason = trim((string)preg_replace('/\s+/', ' ', $reason));
        if (mb_strlen($reason) >= MARKER_MIN_REASON) {
            $marked = true;
            break;
        }
    }

    if ($filters && $marked) {
        $staleMarkers[] = $name;
    } elseif (!$filters && !$marked) {
        $unmarked[] = $name;
    }
}

if ($unmarked) {
    $fail++;
    fwrite(STDERR, "FAIL: api.php unit(s) serving song data with NO language decision:\n\n");
    foreach ($unmarked as $n) {
        fwrite(STDERR, "  {$apiRel} :: {$n}\n");
    }
    fwrite(STDERR, "\nEvery unit that calls \$songData-> must either resolve the reader's\n");
    fwrite(STDERR, "languages via resolvePreferredLanguagesForRequest() and pass them down,\n");
    fwrite(STDERR, "or carry an `@lang-unfiltered: <reason>` comment saying WHY this read\n");
    fwrite(STDERR, "deliberately ignores them (the reader named the record / it is an offline\n");
    fwrite(STDERR, "mirror / it is a corpus-wide count / filtering would invert its meaning).\n");
}

if ($staleMarkers) {
    $fail++;
    fwrite(STDERR, "\nFAIL: api.php unit(s) carrying an `@lang-unfiltered:` marker that DO filter:\n\n");
    foreach ($staleMarkers as $n) {
        fwrite(STDERR, "  {$apiRel} :: {$n}\n");
    }
    fwrite(STDERR, "\nThe marker means \"this read deliberately ignores the reader's languages\".\n");
    fwrite(STDERR, "A unit that resolves them anyway has a marker that is stale or was never\n");
    fwrite(STDERR, "true — remove it. A stale exemption is how a guard stops guarding.\n");
}

/* ===================================================================== *
 *  B. THE PLUMBING HALF — SongData.php
 * ===================================================================== */

$sdRel = 'appWeb/public_html/includes/SongData.php';
$sdSrc = file_get_contents($root . '/' . $sdRel);
if ($sdSrc === false) {
    fwrite(STDERR, "FAIL: cannot read {$sdRel}\n");
    exit(1);
}

$sdUnits = phpSourceUnits($sdSrc);

/* Derived from the SIGNATURE text — see the ⚠️ note in the doc-block for why
   this cannot come from the unit walker's code view. */
$langMethods = [];
if (preg_match_all('/function\s+(\w+)\s*\(([^{;]*)\)/', $sdSrc, $sigs, PREG_SET_ORDER)) {
    foreach ($sigs as $sig) {
        if (preg_match('/array\s+\$langSubtags/', $sig[2])) {
            $langMethods[$sig[1]] = true;
        }
    }
}

$plumbingUnits = count($langMethods);
$ignored       = [];
$bodyless      = [];

foreach (array_keys($langMethods) as $name) {
    if (!isset($sdUnits[$name])) {
        /* The walker could not find a body for a signature the regex found —
           the two disagree, so neither can be trusted. Loud, not skipped. */
        $bodyless[] = $name;
        continue;
    }
    $body = $sdUnits[$name]['code'];

    $applies = strpos($body, 'applyLanguageFilterSql(') !== false
            || strpos($body, 'makeLanguageFilterPredicate(') !== false;

    /* Forwarding: hands $langSubtags on to another method in this same set,
       which is checked in its own right (see the doc-block).

       ⚠️ The ARGUMENT LIST is scanned, not merely the body. An earlier draft
       asked only "does the body mention $langSubtags anywhere, and does it call
       a set member anywhere" — which a body containing `if (empty($langSubtags))`
       beside an unrelated forward would satisfy while passing `[]` to every
       callee. Same shape of hole as the file-wide `<img>` check in
       test-org-logo-surfaces.php check (k): asking whether the marker exists
       SOMEWHERE, rather than in the place that matters. */
    $forwards = langSubtagsForwarded($body, $langMethods);

    if (!$applies && !$forwards) {
        $ignored[] = $name;
    }
}

if ($bodyless) {
    $fail++;
    fwrite(STDERR, "\nFAIL: SongData signature(s) with no matching unit body — scanner disagrees with itself:\n\n");
    foreach ($bodyless as $n) {
        fwrite(STDERR, "  {$sdRel} :: {$n}()\n");
    }
    fwrite(STDERR, "\nThe signature regex found a method the unit walker did not. Fix the\n");
    fwrite(STDERR, "mismatch — do NOT skip the method, that is how a guard silently narrows.\n");
}

if ($ignored) {
    $fail++;
    fwrite(STDERR, "\nFAIL: SongData method(s) that ACCEPT \$langSubtags and never use it:\n\n");
    foreach ($ignored as $n) {
        fwrite(STDERR, "  {$sdRel} :: {$n}\n");
    }
    fwrite(STDERR, "\nA method that takes the reader's languages and drops them is worse than\n");
    fwrite(STDERR, "one that never took them: every call site now LOOKS correct while the\n");
    fwrite(STDERR, "filter silently never happens. Apply it via applyLanguageFilterSql()\n");
    fwrite(STDERR, "(SQL) or makeLanguageFilterPredicate() (in-memory), forward it to another\n");
    fwrite(STDERR, "method that takes array \$langSubtags, or drop the parameter.\n");
}

/* ===================================================================== *
 *  FLOORS — a scanner that scans nothing prints green (#1701)
 * ===================================================================== */

if ($endpointUnits < ENDPOINT_FLOOR) {
    $fail++;
    fwrite(STDERR, sprintf(
        "\nFAIL: scanner under-report — found only %d api.php song-serving unit(s), floor is %d.\n"
        . "Either the unit walker broke (fix it — do NOT lower the floor for this), or a\n"
        . "refactor genuinely reduced the count (then lower ENDPOINT_FLOOR consciously in\n"
        . "the same commit).\n",
        $endpointUnits,
        ENDPOINT_FLOOR
    ));
}

if ($plumbingUnits < PLUMBING_FLOOR) {
    $fail++;
    fwrite(STDERR, sprintf(
        "\nFAIL: scanner under-report — found only %d SongData \$langSubtags method(s), floor is %d.\n"
        . "The language-aware read methods (searchSongs / getSongsIndex / getRandomSong / …)\n"
        . "should all be visible here; finding fewer means the walker or the signature match\n"
        . "broke, not that the feature shrank.\n",
        $plumbingUnits,
        PLUMBING_FLOOR
    ));
}

if ($fail > 0) {
    exit(1);
}

printf(
    "PASS: %d api.php song-serving unit(s) each filtered or marked (floor %d); "
    . "%d SongData \$langSubtags method(s) each apply or forward it (floor %d).\n",
    $endpointUnits,
    ENDPOINT_FLOOR,
    $plumbingUnits,
    PLUMBING_FLOOR
);
exit(0);
