<?php

declare(strict_types=1);

/**
 * iHymns — SPA route allow-list coverage + probe deny-list guard (#1905)
 *
 * ELI5: the front controller now returns a real 404 for a made-up path (a
 * /wp-admin/ scanner probe) instead of a fake 200. That only stays safe if the
 * "these are the real pages" list actually covers every page the app has — miss
 * one and a real route would start 404-ing. This test derives the client route
 * table from js/modules/router.js and fails the build if the derived allow-list
 * (includes/spa_routes.php) doesn't cover it. It also proves the scanner
 * deny-list can't accidentally block a real route, and that the deny-list stays
 * identical between .htaccess and the dev-server mirror.
 *
 * WHY DERIVED, NOT A TYPED LIST (rule #34)
 * ----------------------------------------
 * The authoritative first-segment vocabulary lives in router.js parseRoute().
 * spaKnownRouteSegments() composes it from the page fragments + identifier
 * schemes + a small alias list. If those two ever drift — a new router route not
 * covered by a page file or an alias — a real route would soft-404 server-side
 * while working client-side (#1905's dangerous direction). This guard is the
 * mechanism (rule #35) that makes the drift impossible: it reads BOTH sources
 * and asserts coverage, so the day someone adds a client route uncovered here,
 * CI goes red and names the segment.
 *
 * WHAT IT ASSERTS
 *   A) COVERAGE (safety-critical): every `case '<seg>':` in router.js's
 *      parseRoute switch is in spaKnownRouteSegments(). A superset is fine (it
 *      only over-permits a dead URL to 200); a SUBSET would 404 a live route.
 *   B) DENY-LIST COLLISION-FREEDOM: no valid route segment starts with any
 *      .htaccess deny prefix (wp-/phpmyadmin/…), so the probe rule can never
 *      shadow a real route. (Only this direction — a deny prefix that is a
 *      superstring of a segment, e.g. 'administrator' vs the 'admin' alias, is
 *      NOT a collision: the rule is ^administrator and never matches /admin.)
 *   C) MIRROR (rule #35): the deny alternation is byte-identical in .htaccess
 *      and tests/browser/router.php, so the dev server and Apache agree.
 *
 * PROVEN-ABLE-TO-FAIL (rule #34) — verified by mutation, each restored after:
 *   - add `case 'zzz':` to router.js parseRoute            → A goes red (uncovered)
 *   - drop 'search' from a page glob / the alias list      → A goes red
 *   - add 'song' to the .htaccess deny alternation         → B goes red (collision)
 *   - change one deny family in router.php only            → C goes red (mirror drift)
 *   - delete the directory-404 rule from either .htaccess,
 *     or the is_dir()/index.php check from router.php      → D goes red (#1906)
 *
 *   php tests/php/test-route-allowlist-coverage.php
 *
 * Exit 0 = allow-list covers the router + deny-list is safe + mirrors agree
 * + all three files carry the #1906 directory-listing-kill logic.
 *
 * @see appWeb/public_html/includes/spa_routes.php
 * @see appWeb/public_html/js/modules/router.js  parseRoute()
 */

$repoRoot        = dirname(__DIR__, 2);
$routerJs        = $repoRoot . '/appWeb/public_html/js/modules/router.js';
$htaccess        = $repoRoot . '/appWeb/public_html/.htaccess';
$manageHtaccess  = $repoRoot . '/appWeb/public_html/manage/.htaccess';
$devRouter       = $repoRoot . '/tests/browser/router.php';
$spaRoutes       = $repoRoot . '/appWeb/public_html/includes/spa_routes.php';

$failures = [];
$passed   = 0;
function rcOk(string $label, bool $cond): void
{
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

foreach ([$routerJs, $htaccess, $manageHtaccess, $devRouter, $spaRoutes] as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "FATAL: missing $f — cannot derive the route contract.\n");
        exit(1);
    }
}

/* ---- the derived allow-list (the actual runtime registry) ------------------ */
require_once $spaRoutes;
$allowList = spaKnownRouteSegments();
rcOk('spaKnownRouteSegments() returns a non-empty list', count($allowList) > 0);

/* ---- A) parse router.js parseRoute() switch → the client route segments ---- */
$jsSrc = (string) file_get_contents($routerJs);
/* Strip JS comments (block + line), newline-preserving, so a `case` mentioned in
   prose can't be read as a route. */
$jsSrc = preg_replace_callback('~/\*.*?\*/~s', static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $jsSrc) ?? $jsSrc;
$jsSrc = preg_replace('~//[^\n]*~', '', $jsSrc) ?? $jsSrc;

/* Scope to the `switch (segments[0]) { … }` body by brace-matching from the
   unique anchor, so cases in any OTHER switch in the file are never counted. */
$anchor = strpos($jsSrc, 'switch (segments[0])');
$routerSegments = [];
if ($anchor === false) {
    $failures[] = "router.js: could not find the `switch (segments[0])` anchor — parseRoute shape changed; update this guard.";
} else {
    $braceStart = strpos($jsSrc, '{', $anchor);
    $depth = 0; $end = null;
    for ($i = $braceStart, $n = strlen($jsSrc); $i < $n; $i++) {
        if ($jsSrc[$i] === '{') { $depth++; }
        elseif ($jsSrc[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    $switchBody = ($end !== null) ? substr($jsSrc, $braceStart, $end - $braceStart) : '';
    if ($switchBody === '') {
        $failures[] = "router.js: could not brace-match the segment switch body — update this guard.";
    }
    preg_match_all('/case\s+[\'"]([a-z0-9-]+)[\'"]\s*:/', $switchBody, $m);
    $routerSegments = array_values(array_unique($m[1]));
}
rcOk('router.js parseRoute exposes a non-trivial set of route cases (>= 20)', count($routerSegments) >= 20);

/* COVERAGE — every client route MUST be in the derived allow-list. */
$uncovered = array_values(array_diff($routerSegments, $allowList));
rcOk(
    'every router.js route segment is covered by spaKnownRouteSegments()'
        . ($uncovered ? ' — UNCOVERED: ' . implode(', ', $uncovered) : ''),
    $uncovered === []
);

/* ---- B) parse the .htaccess deny alternation → collision-freedom ----------- */
$htSrc = (string) file_get_contents($htaccess);
/* Match ONLY the real RewriteRule line (comment lines start with #, excluded by
   the RewriteRule keyword + the exact tail). */
$denyPrefixesHt = [];
if (preg_match('/^\s*RewriteRule\s+\^\(([^)]*)\)\s+-\s+\[R=404,L,NC\]/m', $htSrc, $m)) {
    $denyPrefixesHt = array_values(array_filter(array_map('trim', explode('|', $m[1]))));
}
rcOk('a probe deny-list RewriteRule was found in .htaccess', count($denyPrefixesHt) > 0);

/* No valid route segment may START WITH a deny prefix (that is the only real
   shadowing direction — the rule is ^<prefix>). Clause deliberately one-way:
   'administrator' being a superstring of the 'admin' alias is NOT a collision. */
$collisions = [];
foreach ($allowList as $seg) {
    foreach ($denyPrefixesHt as $prefix) {
        if ($prefix !== '' && str_starts_with($seg, $prefix)) {
            $collisions[] = "$seg (starts with deny prefix '$prefix')";
        }
    }
}
rcOk(
    'no valid route segment starts with a deny-list prefix'
        . ($collisions ? ' — COLLISION: ' . implode('; ', $collisions) : ''),
    $collisions === []
);

/* ---- C) mirror: the dev router's deny alternation must equal .htaccess's ---- */
$devSrc = (string) file_get_contents($devRouter);
$denyPrefixesDev = [];
if (preg_match('~preg_match\(\s*[\'"]#\^/\(([^)]*)\)#i[\'"]~', $devSrc, $m)) {
    $denyPrefixesDev = array_values(array_filter(array_map('trim', explode('|', $m[1]))));
}
rcOk('a probe deny-list preg_match was found in tests/browser/router.php', count($denyPrefixesDev) > 0);
rcOk(
    '.htaccess and tests/browser/router.php deny alternations are byte-identical (rule #35)'
        . ($denyPrefixesHt !== $denyPrefixesDev ? ' — htaccess=[' . implode('|', $denyPrefixesHt) . '] dev=[' . implode('|', $denyPrefixesDev) . ']' : ''),
    $denyPrefixesHt === $denyPrefixesDev
);

/* ---- D) directory-listing kill (#1906) is present in all three files ------- *
 * The `Options -Indexes` EFFECT is delivered via mod_rewrite instead (the
 * literal Options directive 500s the whole site under an unknown
 * AllowOverride — see .htaccess's own "DIRECTORY-LISTING KILL" comment and
 * CLAUDE.md rule #1906/§A.3). This asserts the mechanism actually landed in
 * BOTH .htaccess files (the exact 3-line RewriteCond/RewriteCond/RewriteRule
 * triple) and its is_dir()+index.php mirror in tests/browser/router.php —
 * a comment describing the intent is not the same as the rule existing
 * (rule #35: cross-file agreement needs a mechanism, not a comment). */
$manageHtSrc = (string) file_get_contents($manageHtaccess);

/* Matches the exact triple, back-to-back and in this order:
 *   RewriteCond %{REQUEST_FILENAME} -d
 *   RewriteCond %{REQUEST_FILENAME}/index.php !-f
 *   RewriteRule ^.+ - [R=404,...
 * `\s*\n` between clauses tolerates trailing horizontal whitespace and
 * blank lines (both `\s` classes include newline) but NOT an interleaved
 * comment line — a stray `#` between the directives breaks the match,
 * which is correct: the shipped blocks keep the three lines adjacent with
 * no explanatory comment wedged between them. */
$dirKillHtaccessPattern = '/RewriteCond\s+%\{REQUEST_FILENAME\}\s+-d\s*\n'
    . '\s*RewriteCond\s+%\{REQUEST_FILENAME\}\/index\.php\s+!-f\s*\n'
    . '\s*RewriteRule\s+\^\.\+\s+-\s+\[R=404/';

rcOk(
    'appWeb/public_html/.htaccess carries the #1906 directory-404 RewriteCond/RewriteCond/RewriteRule triple',
    preg_match($dirKillHtaccessPattern, $htSrc) === 1
);
rcOk(
    'appWeb/public_html/manage/.htaccess carries the #1906 directory-404 RewriteCond/RewriteCond/RewriteRule triple',
    preg_match($dirKillHtaccessPattern, $manageHtSrc) === 1
);

/* router.php mirror: an is_dir($x) check immediately AND-chained with a
 * !is_file($x . '/index.php') check (variable name unconstrained — only the
 * shape matters, so a future rename of $dirCandidate doesn't false-fail
 * this guard). */
$dirKillRouterPattern = '/is_dir\(\$\w+\)\s*\n'
    . '\s*&&\s*!is_file\(\$\w+\s*\.\s*[\'"]\/index\.php[\'"]\)/';
rcOk(
    'tests/browser/router.php carries the #1906 is_dir()+index.php directory-404 mirror',
    preg_match($dirKillRouterPattern, $devSrc) === 1
);

/* ---- report ---------------------------------------------------------------- */
if ($failures) {
    fwrite(STDERR, "FAIL: SPA route allow-list / deny-list contract (#1905):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  ✗ $f\n"); }
    fwrite(STDERR, "\nThe derived allow-list (includes/spa_routes.php) must COVER every router.js\n");
    fwrite(STDERR, "route (add the page fragment or an alias), the deny-list must not shadow a\n");
    fwrite(STDERR, "real route, and the two deny alternations must match. See #1905.\n");
    exit(1);
}
printf(
    "PASS: route allow-list contract (%d assertions) — %d router segments all covered by %d allow-list entries; %d deny prefixes, collision-free, mirrored.\n",
    $passed,
    count($routerSegments),
    count($allowList),
    count($denyPrefixesHt)
);
exit(0);
