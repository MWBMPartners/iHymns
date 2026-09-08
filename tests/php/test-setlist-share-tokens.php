<?php

declare(strict_types=1);

/**
 * iHymns — Shared set-list id grammar agreement guard (#1791, #2110, rule #35)
 *
 * ELI5
 * ----
 * When you share a set list, the link ends in a short code — something like
 * `a1b2c3d4`, or a longer random-looking string for an edit link. Three
 * separate pieces of this app each decide, on their own, whether a code
 * "looks like a real share code":
 *
 *   1. the SERVER's front door for the id, `sharedSetlistSafeShareId()`
 *      in `appWeb/public_html/includes/SharedSetlist.php`;
 *   2. the WEB ADDRESS the site listens on, the route regular expression
 *      in `appWeb/public_html/index.php` that answers
 *      `/setlist/shared/<code>`;
 *   3. the BROWSER's copy, `SHARE_ID_RE` in
 *      `appWeb/public_html/js/constants.js`, which the shared-set-list page
 *      uses to tell a modern share code apart from a very old link that
 *      carried the whole set list inside the address itself.
 *
 * If those three ever stop agreeing about what a valid code looks like, a
 * share link works in one place and quietly fails in another — the page
 * loads, nothing turns red, the set list just isn't there. Nothing in the
 * app forced them to agree. This file is that force.
 *
 * WHY THIS FILE EXISTS AT ALL (#2110)
 * -----------------------------------
 * The comment above `SHARE_ID_RE` in `js/constants.js` said the two halves
 * were "kept in sync by the guard tests/php/test-setlist-share-tokens.php,
 * not by this comment". That file had never existed, in any commit, on any
 * branch. That is worse than having no comment: a reviewer reads it, is told
 * a machine is already checking, and reasonably stops checking themselves.
 * This file makes the sentence true.
 *
 * HOW IT CHECKS (rule #34 — derive from the tree, never from a list typed
 * here; prove by BEHAVIOUR, not by matching words)
 * ---------------------------------------------------------------------
 * The pattern `^[A-Za-z0-9_-]{6,64}$` is deliberately NOT written down
 * anywhere in this file. Writing it here would only prove that this file
 * agrees with itself. Instead:
 *
 *   - the server fold is CALLED for real (this file `require`s
 *     SharedSetlist.php and runs `sharedSetlistSafeShareId()`), so what is
 *     tested is the function's actual behaviour, not the look of its source;
 *   - the browser's regular expression and the route's regular expression
 *     are READ OUT of their own files at run time and compiled here;
 *   - all three are then run over the same list of candidate codes, and
 *     every one of them must give the same yes/no answer.
 *
 * Comparing behaviour rather than text also catches the sneaky case where
 * two patterns look different but mean the same thing, or look the same but
 * do not — for example `[A-Za-z0-9_-]` versus `[A-Za-z0-9-_]` (identical)
 * versus `[A-Za-z0-9_\-a-z]` (not).
 *
 * THE ONE TRANSLATION THIS FILE MAKES, AND WHY IT IS SAFE
 * ------------------------------------------------------
 * PHP cannot run a JavaScript regular expression, so the browser pattern is
 * compiled with PHP's own engine (PCRE). For the plain "anchors, character
 * class, length range" shape used here the two engines agree exactly, with
 * ONE difference worth naming: JavaScript's `$` means "the very end of the
 * string", while PHP's `$` also allows one trailing newline unless you add
 * the `D` option. This file adds `D`, which makes PHP behave exactly like
 * JavaScript. If the pattern ever grows a feature where the two engines
 * genuinely differ (a lookbehind, a `\p{…}` Unicode property, a `\uXXXX`
 * escape, or a JavaScript-only flag), this guard REFUSES to pretend it can
 * still compare them and fails loudly asking a person to look — see
 * `shareIdPortableJsPattern()` below.
 *
 * DELIBERATELY OUT OF SCOPE (rule #34 — a guard must not go red on correct
 * code)
 * ---------------------------------------------------------------------
 *   - Codes with a space or newline stuck on the front or back. The server
 *     fold calls `trim()` before it matches and the browser copy does not,
 *     so they legitimately disagree there. It cannot matter in practice: a
 *     share code arrives as one segment of a web address, and the route
 *     regular expression would never have matched a padded one in the first
 *     place. Every candidate below is therefore written unpadded.
 *   - "Does every part of the app actually call the shared fold rather than
 *     rolling its own check?" That is a different question with a different
 *     answer; today `api.php` and `og-image.php` both call
 *     `sharedSetlistSafeShareId()`. This file proves the three grammars
 *     agree, not that nobody has invented a fourth.
 *
 * PROVEN ABLE TO FAIL (rule #34): change the length range on ANY ONE of the
 * three sides and this goes red naming that side and the code it disagreed
 * about; restore it and this goes green again. The exact runs are in the
 * pull request that added this file.
 *
 *   php tests/php/test-setlist-share-tokens.php
 *
 * Exit status 0 = the three agree, 1 = they have drifted apart.
 *
 * @see appWeb/public_html/includes/SharedSetlist.php  sharedSetlistSafeShareId() — the server fold
 * @see appWeb/public_html/js/constants.js             SHARE_ID_RE — the browser mirror
 * @see appWeb/public_html/index.php                   the /setlist/shared/<code> route
 * @see .claude/CLAUDE.md rule #35                     cross-file agreement needs a mechanism, not a comment
 * @see .claude/CLAUDE.md rule #40                     set-list sharing is capability-URL-scoped (#1791)
 * @see #1791, #2110
 */

$root = dirname(__DIR__, 2);

/* Calling the real function is the strongest form of check available here:
   it has a runtime handle, so no amount of rewording the source can fool it.
   SharedSetlist.php loads cleanly with no database — it only opens a
   connection inside the functions that need one, and the fold is not one of
   them. */
require $root . '/appWeb/public_html/includes/SharedSetlist.php';

$constantsJs = $root . '/appWeb/public_html/js/constants.js';
$indexPhp    = $root . '/appWeb/public_html/index.php';

$passed = 0;
$failed = 0;

/**
 * Record one assertion. `$detail` is printed only on failure, and should say
 * which file held which value — a guard that just says "they disagree" makes
 * the reader do the work all over again.
 */
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        if ($detail !== '') {
            echo "        $detail\n";
        }
        $failed++;
    }
}

/** Stop immediately with a clear reason. Used when the guard cannot even
 *  find the thing it is supposed to be checking — carrying on from there
 *  would report a confident, meaningless green (rule #34: a checker that
 *  quietly checks nothing is worse than no checker). */
function fatal(string $why): never
{
    echo "\n  FAIL  $why\n\n";
    echo "This guard could not read one of the three grammars it compares, so it\n";
    echo "cannot honestly say they agree. Fix the anchor (or this guard) rather\n";
    echo "than deleting the check.\n";
    exit(1);
}

/**
 * Turn a JavaScript regular-expression body + flags into an equivalent PHP
 * (PCRE) pattern string, or return null when the two engines could not be
 * trusted to agree about it.
 *
 * The `D` option is always added: it makes PHP's `$` mean "the very end of
 * the string", which is what JavaScript's `$` already means when the `m`
 * flag is absent. Without it PHP would also accept one trailing newline and
 * this guard would report an agreement that does not exist.
 * https://www.php.net/manual/en/reference.pcre.pattern.modifiers.php
 * https://developer.mozilla.org/docs/Web/JavaScript/Reference/Regular_expressions/Input_boundary_assertion
 */
function shareIdPortableJsPattern(string $body, string $flags, ?string &$why = null): ?string
{
    /* Constructs where JavaScript and PHP genuinely differ, or need extra
       options to line up. Short and specific on purpose: this list is not
       "anything unfamiliar", it is "things this translation would get
       wrong". */
    $unportable = [
        '(?<'   => 'a lookbehind or named group',
        '\p{'   => 'a Unicode property escape',
        '\P{'   => 'a negated Unicode property escape',
        '\u'    => 'a \\uXXXX escape (PHP writes those as \\x{XXXX})',
    ];
    foreach ($unportable as $needle => $describe) {
        if (str_contains($body, $needle)) {
            $why = "the pattern contains $describe, which PHP and JavaScript do not read the same way";
            return null;
        }
    }
    /* Only flags that mean the same thing in both engines. `g` and `y` make
       JavaScript's .test() remember where it stopped last time, `u`/`v`
       change how the whole pattern is parsed, `d` changes the result shape —
       none of those survive a naive translation. */
    $portableFlags = '';
    for ($i = 0, $n = strlen($flags); $i < $n; $i++) {
        $f = $flags[$i];
        if ($f === 'i' || $f === 'm' || $f === 's') {
            $portableFlags .= $f;
            continue;
        }
        $why = "the pattern carries the JavaScript flag '$f', which has no straight PHP equivalent";
        return null;
    }
    /* `#` is used as the delimiter, so the body must not contain one. */
    if (str_contains($body, '#')) {
        $why = 'the pattern contains a "#", which collides with the delimiter this guard uses';
        return null;
    }
    /* `D` last: "dollar means the very end", matching JavaScript. */
    return '#' . $body . '#' . $portableFlags . 'D';
}

echo "\n#1791 / #2110 — shared set-list id grammar: server fold vs route vs browser mirror\n\n";

/* ======================================================================
 * 1. Find the three grammars. Each of these is read out of the tree at run
 *    time; none of them is written down in this file.
 * ====================================================================== */

if (!function_exists('sharedSetlistSafeShareId')) {
    fatal('sharedSetlistSafeShareId() is not defined after loading includes/SharedSetlist.php — '
        . 'the server fold has been renamed or removed.');
}

$jsSrc = @file_get_contents($constantsJs);
if ($jsSrc === false) {
    fatal("Could not read $constantsJs.");
}
/* `export const SHARE_ID_RE = /^…$/;` — the body is everything between the
   first and last unescaped slash on that line, the flags whatever follows. */
if (!preg_match('~SHARE_ID_RE\s*=\s*/((?:\\\\.|[^/\\\\])+)/([a-z]*)\s*;~', $jsSrc, $jm)) {
    fatal('Could not find `SHARE_ID_RE = /…/` in js/constants.js — the browser mirror has been '
        . 'renamed, removed, or rewritten in a shape this guard cannot read.');
}
$jsBody  = $jm[1];
$jsFlags = $jm[2];

$indexSrc = @file_get_contents($indexPhp);
if ($indexSrc === false) {
    fatal("Could not read $indexPhp.");
}
/* The route: `preg_match('#^/setlist/shared/(…)$#', $requestPath, …)`.
   Captured whole, delimiters and all, so what gets exercised below is the
   very pattern the live router runs — not a reconstruction of it. */
if (!preg_match('~preg_match\(\s*(\'|")(.{1,3}\^/setlist/shared/.*?)\1~', $indexSrc, $rm)) {
    fatal('Could not find the /setlist/shared/<code> route regular expression in index.php — '
        . 'the route has been renamed, removed, or rewritten in a shape this guard cannot read.');
}
$routePattern = $rm[2];

$jsPattern = shareIdPortableJsPattern($jsBody, $jsFlags, $jsWhy);
if ($jsPattern === null) {
    fatal("SHARE_ID_RE can no longer be compared against the PHP side: $jsWhy. "
        . 'Someone must check by hand that the browser and the server still agree, and then teach '
        . 'shareIdPortableJsPattern() about the new shape.');
}

/* Both borrowed patterns must actually compile. An uncompilable one would
   make every later `preg_match` return false and the whole truth table would
   read as "everything is rejected, and all three agree about that" — a
   textbook silent pass. */
foreach ([
    'SHARE_ID_RE (js/constants.js)' => $jsPattern,
    'the /setlist/shared/ route (index.php)' => $routePattern,
] as $label => $pattern) {
    if (@preg_match($pattern, 'probe') === false) {
        fatal("The pattern taken from $label does not compile as a PHP regular expression: $pattern");
    }
}

ok('found all three grammars in the tree (server fold, route, browser mirror)', true);
echo "        browser mirror : $jsBody" . ($jsFlags !== '' ? " (flags: $jsFlags)" : '') . "\n";
echo "        route          : $routePattern\n";
echo "        server fold    : sharedSetlistSafeShareId() (called for real, not read)\n\n";

/* ======================================================================
 * 2. The candidate codes. These are inputs, not the thing under test — the
 *    grammars themselves still come from the tree. Each is written to
 *    probe one real property of the #1791 id grammar.
 * ====================================================================== */

/** Build a code of exactly $len characters from the safe alphabet. */
function shareIdOfLength(int $len): string
{
    return substr(str_repeat('aB3_-x', (int)ceil($len / 6)), 0, $len);
}

$candidates = [
    // label                                          code
    'a legacy 8-character hex id (#1791 keeps these valid forever, rule #33)'
        => 'a1b2c3d4',
    'a 22-character view token (128 bits, base64url)'
        => 'Ab3-_dEfGhIjKlMnOpQrSt',
    'a 43-character edit token (256 bits, base64url)'
        => 'Ab3-_dEfGhIjKlMnOpQrStUvWxYz0123456789_-AbC',
    'the shortest allowed code'
        => shareIdOfLength(6),
    'one character shorter than the shortest allowed'
        => shareIdOfLength(5),
    'the longest allowed code'
        => shareIdOfLength(64),
    'one character longer than the longest allowed'
        => shareIdOfLength(65),
    'nothing at all'
        => '',
    'underscores and hyphens only'
        => '__--__',
    'contains a plus sign (plain base64, not base64url)'
        => 'abc+defg',
    'contains a forward slash (plain base64, not base64url)'
        => 'abc/defg',
    'contains an equals sign (base64 padding)'
        => 'abcdefg=',
    'a very old inline-payload share link (long plain base64 blob)'
        => 'eyJuYW1lIjoiU3VuZGF5IiwiaXRlbXMiOlt7ImlkIjoiTVAtMTAwOCJ9XX0=+/abcdefghijklmnop',
    'contains a full stop'
        => 'abc.defg',
    'contains a space in the middle'
        => 'abc defg',
    'contains a percent sign (a half-decoded web address)'
        => 'abc%2Fdef',
    'contains an accented letter'
        => 'abcdéfg',
    'contains a colon'
        => 'abc:defg',
];

/* ======================================================================
 * 3. The truth table: all three must answer the same way about every one.
 * ====================================================================== */

$foldAccepts  = 0;
$foldRejects  = 0;
$disagreement = 0;

foreach ($candidates as $label => $code) {
    /* The server's answer: the fold returns the trimmed code when it is
       acceptable and an empty string when it is not. */
    $foldSays  = sharedSetlistSafeShareId($code) !== '';
    /* The browser's answer. */
    $jsSays    = preg_match($jsPattern, $code) === 1;
    /* The route's answer, asked the way the router really asks it: against
       the whole request path. */
    $routeSays = preg_match($routePattern, '/setlist/shared/' . $code) === 1;

    $foldSays ? $foldAccepts++ : $foldRejects++;

    $agree = ($foldSays === $jsSays) && ($foldSays === $routeSays);
    if (!$agree) { $disagreement++; }

    $shown = $code === '' ? '(empty)' : (strlen($code) > 40 ? substr($code, 0, 37) . '…' : $code);
    ok(
        sprintf('%s — all three say %s', $label, $foldSays ? 'YES' : 'no'),
        $agree,
        sprintf(
            "code %s\n        includes/SharedSetlist.php sharedSetlistSafeShareId() says %s\n"
            . "        js/constants.js SHARE_ID_RE (%s) says %s\n"
            . "        index.php route (%s) says %s\n"
            . "        A share link built on one side would be refused by the other.",
            $shown,
            $foldSays ? 'ACCEPT' : 'REJECT',
            $jsBody,
            $jsSays ? 'ACCEPT' : 'REJECT',
            $routePattern,
            $routeSays ? 'ACCEPT' : 'REJECT'
        )
    );
}

/* ======================================================================
 * 4. Sanity floor (rule #34 — a checker that quietly checks nothing).
 *    If a pattern were extracted wrongly and ended up accepting everything
 *    or nothing, every row above could still "agree". Insisting that the
 *    server fold both accepts and rejects some of the candidates makes that
 *    impossible.
 * ====================================================================== */
echo "\n";
ok(
    'the candidate list genuinely exercises the grammar: some codes are accepted and some refused',
    $foldAccepts > 0 && $foldRejects > 0,
    sprintf('accepted %d, refused %d — if one of those is zero the candidates are not testing anything.',
        $foldAccepts, $foldRejects)
);

echo "\n";
if ($failed > 0) {
    echo "$failed check(s) failed ($passed passed).\n";
    echo "The three share-code grammars have drifted apart. Bring them back into line —\n";
    echo "the server fold in includes/SharedSetlist.php is the one that decides, and the\n";
    echo "other two must mirror it.\n";
    exit(1);
}
echo "All $passed checks passed — the server fold, the /setlist/shared/ route and the browser's\n";
echo "SHARE_ID_RE agree about every one of the " . count($candidates) . " candidate share codes.\n";
