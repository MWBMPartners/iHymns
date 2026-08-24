<?php

declare(strict_types=1);

/**
 * iHymns — songs_index version-signal ETag guard (#1921 server half)
 * ============================================================================
 *
 * ELI5: makes sure the "tell the browser nothing changed" shortcut for the
 * PWA's catalogue index is built correctly: the same inputs always produce
 * the same ETag, ANY of the four things that can change the bytes produces a
 * DIFFERENT ETag, the `If-None-Match` comparison follows the real HTTP rules
 * (comma lists, weak validators, `*`), and the server actually skips the
 * expensive query on a 304 instead of quietly still doing all the work.
 *
 * WHAT IS ASSERTED
 *   1. FUNCTIONAL (calls the real, side-effect-free-to-require
 *      includes/songs_index_etag.php): songsIndexEtag() is stable across
 *      repeated calls with the SAME four inputs, and sensitive to each of
 *      the four folds (signal / contractVersion / deployRef / shapeToken)
 *      varied ALONE. songsIndexEtagMatches() truth table: empty header ->
 *      false; exact match -> true; a `W/`-weak-prefixed match -> true; the
 *      etag present inside a comma-separated list -> true; `*` -> true;
 *      anything else -> false.
 *   2. STRUCTURAL on api.php's `songs_index` case (comment-stripped via
 *      token_get_all so a doc-comment mentioning these symbols in prose
 *      can't false-positive; the case body is bounded the SAME
 *      "next same-indent case/default" way as
 *      tests/php/test-read-rate-limit-docs.php, widened from a narrower
 *      window per the #1675 guard-log lesson that a too-tight window
 *      truncates real source): the `304` emit and its `exit` textually
 *      PRECEDE the `getSongsSlimIndex()` call (mutation: swap the order ->
 *      red — a 304 that runs after the heavy query defeats the entire
 *      point); the ETag call site passes BOTH `apiContractVersion()` and
 *      `slimIndexShapeToken()`; the case body contains NONE of
 *      `makeLanguageFilterPredicate`, `resolvePreferredLanguagesForRequest`,
 *      or `getAuthenticatedUser` — a NARROW tripwire (#1921 §A.4-iii): if a
 *      future change ever adds a per-viewer/per-language filter to this
 *      endpoint, the version-signal ETag (which carries no per-user axis)
 *      would start serving a cached OTHER USER'S 304 body — this case, and
 *      only this case, must never gain one of those three symbols.
 *
 *   php tests/php/test-songs-index-etag.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/songs_index_etag.php
 * @see appWeb/public_html/api.php   the `songs_index` case
 * @see tests/php/test-read-rate-limit-docs.php   (the case-body-bounding technique this mirrors)
 * @see https://www.rfc-editor.org/rfc/rfc7232
 */

$repo = dirname(__DIR__, 2);

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "  \xE2\x9C\x93 $name\n";
        return;
    }
    $failures++;
    echo "  \xE2\x9C\x97 $name" . ($detail !== '' ? "\n      $detail" : '') . "\n";
}

/* ---------------------------------------------------------------------- *
 * 1 — FUNCTIONAL: songsIndexEtag() / songsIndexEtagMatches().
 * Side-effect-free to require: no DB connection, no network call — pure
 * string/hash functions only.
 * ---------------------------------------------------------------------- */
require_once $repo . '/appWeb/public_html/includes/songs_index_etag.php';

echo "songs_index ETag — functional:\n";

$e1 = songsIndexEtag('12|2026-08-01 00:00:00|3|2026-07-01 00:00:00', 1, 'abc1234', 'shapeA');
$e2 = songsIndexEtag('12|2026-08-01 00:00:00|3|2026-07-01 00:00:00', 1, 'abc1234', 'shapeA');
check('songsIndexEtag() is stable across repeated calls with the same inputs', $e1 === $e2);
check('songsIndexEtag() returns a quoted string starting "si<version>-"',
    str_starts_with($e1, '"si1-') && str_ends_with($e1, '"'));

$baseSignal = '12|2026-08-01 00:00:00|3|2026-07-01 00:00:00';
$base = songsIndexEtag($baseSignal, 1, 'abc1234', 'shapeA');
$variants = [
    'signal (corpus content)'  => songsIndexEtag('13|2026-08-01 00:00:00|3|2026-07-01 00:00:00', 1, 'abc1234', 'shapeA'),
    'contractVersion'          => songsIndexEtag($baseSignal, 2, 'abc1234', 'shapeA'),
    'deployRef'                => songsIndexEtag($baseSignal, 1, 'def5678', 'shapeA'),
    'shapeToken'               => songsIndexEtag($baseSignal, 1, 'abc1234', 'shapeB'),
];
foreach ($variants as $axis => $variantEtag) {
    check("varying '$axis' alone produces a DIFFERENT ETag", $variantEtag !== $base);
}

echo "\nsongs_index ETag — If-None-Match truth table:\n";
$etag = '"si1-deadbeefcafef00d"';
$truthTable = [
    ['', false, 'empty header'],
    [$etag, true, 'exact match'],
    ['W/' . $etag, true, 'weak-validator prefix'],
    ['"si1-0000000000000000", ' . $etag . ', "si1-1111111111111111"', true, 'present inside a comma-separated list'],
    ['*', true, 'the any-representation wildcard'],
    ['"si1-completelydifferent"', false, 'a different ETag entirely'],
];
foreach ($truthTable as [$header, $expected, $label]) {
    check("If-None-Match '$label' -> " . ($expected ? 'match' : 'no match'),
        songsIndexEtagMatches($header, $etag) === $expected);
}

/* ---------------------------------------------------------------------- *
 * 2 — STRUCTURAL on api.php's `songs_index` case.
 * ---------------------------------------------------------------------- */
echo "\nsongs_index ETag — structural (api.php case body):\n";

function siStripComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/** Same shape as test-read-rate-limit-docs.php's findActionCaseBody(),
 *  duplicated here rather than shared (rule #22 applies to PRODUCT code;
 *  two small test-local helpers with the same shape is not a maintenance
 *  hazard the way two runtime cores would be). Bounded by the next
 *  same-indent case/default so the window is never accidentally too
 *  narrow (the #1675 guard-log lesson cited in this file's own header). */
function findApiCaseBody(string $actionSwitchSrc, string $action): ?string
{
    $needle = "case '" . $action . "':";
    $pos = strpos($actionSwitchSrc, $needle);
    if ($pos === false) {
        return null;
    }
    $cursor = $pos + strlen($needle);
    while (true) {
        $rest = substr($actionSwitchSrc, $cursor);
        if (!preg_match('/^\s*(case \'[^\']*\':)/', $rest, $fm)) {
            break;
        }
        $cursor += strlen($fm[0]);
    }
    $bodyStart = $cursor;
    $nextPos = null;
    if (preg_match('/\n {8}(?:case \'|default:)/', $actionSwitchSrc, $m, PREG_OFFSET_CAPTURE, $bodyStart)) {
        $nextPos = $m[0][1];
    }
    $bodyEnd = $nextPos ?? strlen($actionSwitchSrc);
    return substr($actionSwitchSrc, $bodyStart, $bodyEnd - $bodyStart);
}

$apiSrc = siStripComments((string)file_get_contents($repo . '/appWeb/public_html/api.php'));
$actionSwitchPos = strpos($apiSrc, 'switch ($action)');
check('found switch ($action) in api.php', $actionSwitchPos !== false);
if ($actionSwitchPos === false) {
    fwrite(STDERR, "FATAL: could not find 'switch (\$action)' in api.php — file shape changed.\n");
    exit(1);
}
$actionSrc = substr($apiSrc, $actionSwitchPos);

$body = findApiCaseBody($actionSrc, 'songs_index');
check("api.php has a case 'songs_index' in the \$action switch", $body !== null);

if ($body !== null) {
    $notModPos = null;
    if (preg_match('/http_response_code\s*\(\s*304\s*\)\s*;\s*exit\s*;/', $body, $m, PREG_OFFSET_CAPTURE)) {
        $notModPos = $m[0][1];
    }
    $slimIndexPos = null;
    if (preg_match('/getSongsSlimIndex\s*\(/', $body, $m2, PREG_OFFSET_CAPTURE)) {
        $slimIndexPos = $m2[0][1];
    }
    check('the 304 + exit textually PRECEDES the getSongsSlimIndex() call',
        $notModPos !== null && $slimIndexPos !== null && $notModPos < $slimIndexPos,
        'a 304 emitted AFTER the heavy query would defeat the entire point of #1921');

    check("the ETag is derived using apiContractVersion()", (bool)preg_match('/apiContractVersion\s*\(\s*\)/', $body));
    check("the ETag is derived using slimIndexShapeToken()", (bool)preg_match('/slimIndexShapeToken\s*\(\s*\)/', $body));

    /* #1921 §A.4-iii tripwire — deliberately NARROW (this case only): a
       version-signal ETag carries no per-viewer axis, so this case must
       never start resolving a viewer/language filter. */
    foreach (['makeLanguageFilterPredicate', 'resolvePreferredLanguagesForRequest', 'getAuthenticatedUser'] as $sym) {
        check("songs_index case does NOT call $sym() (no per-viewer axis)",
            !str_contains($body, $sym),
            "found '$sym' inside the songs_index case body — a per-user/language filter here would break the shared-ETag assumption");
    }
}

if ($failures) {
    fwrite(STDERR, "\nFAIL: $failures songs_index ETag check(s) failed.\n");
    exit(1);
}
echo "\nOK: songs_index version-signal ETag wired correctly.\n";
