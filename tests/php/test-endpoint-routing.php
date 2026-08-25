<?php

declare(strict_types=1);

/**
 * iHymns — root-endpoint routing guard (2026-08-25, the /qr + /org-logo
 * routing-bug fix; rules #33/#34/#38/#42)
 *
 * ELI5: `appWeb/public_html/.htaccess` has one rule that 404s ANY request
 * whose raw text contains ".php" (it "hides" that the site runs PHP), with
 * a short list of extensionless aliases (`/og-image`, now `/qr`,
 * `/org-logo`, `/webhook-drain`, …) that are the ONLY way to reach the
 * matching `*.php` script from a real browser. If a page ever builds an
 * `<img src>` / `fetch()` / `curl` command that still spells out the
 * `.php`, that request 404s — silently, because the picture/fetch/cron
 * just fails somewhere the developer wasn't watching. This guard reads the
 * REAL `.htaccess` (never a re-typed copy of what it says) and the REAL
 * source tree, and proves every same-origin URL the app actually emits for
 * a root-level `*.php` endpoint would actually be served, not 404ed.
 *
 * WHY THIS GUARD EXISTS
 * ----------------------
 * `/qr.php?data=…` and `/org-logo.php?org=…` were both dead on arrival from
 * the day each endpoint shipped: `.htaccess`'s block condition reads
 * `%{THE_REQUEST}` — the client's ORIGINAL, unrewritten request line, which
 * never changes across an internal rewrite/sub-request — so a literal
 * `.php` in an `<img src>` trips the block before the endpoint's own PHP
 * ever runs. `/og-image` already had the fix (an extensionless
 * `.htaccess` alias) as a working precedent sitting right next to the two
 * broken ones; nothing generalised the pattern into a check. Fixing this
 * guard's whole PURPOSE is to have caught it: it does not hardcode "qr and
 * org-logo are the two to check" (rule #34) — it derives the full
 * candidate list from two REAL sources (every root-level `*.php` file that
 * exists, and every URL literal the tree actually emits for one) and
 * cross-checks them against the REAL parsed `.htaccess`, so a FOURTH
 * endpoint shipped tomorrow with the same mistake fails CI the same way.
 * It found a THIRD real, independently-shipped instance of this exact bug
 * while this guard was being built — `webhook-drain.php`'s own doc-block,
 * `/manage/help.php`, and the copy-pasteable cron command on
 * `/manage/configuration` all instructed an operator to crontab a literal
 * `/webhook-drain.php?key=…`, which had 404ed since #1909 shipped. Fixed
 * alongside this guard (see `.htaccess`, `webhook-drain.php`,
 * `manage/help.php`, `manage/configuration.php`).
 *
 * WHAT IT CHECKS (three checks, all tree-derived — never a typed URL list)
 * --------------------------------------------------------------------
 * (A) THE BUG ITSELF — every root-level `*.php` file under
 *     `appWeb/public_html/*.php` (glob, never typed) is checked against
 *     EVERY `.php`/`.js` file in the tree (comment-stripped, so a doc-block
 *     EXAMPLE of the broken shape — several exist, deliberately, explaining
 *     the fix — never false-positives) for a quoted string literal that
 *     starts `/<basename>.php` (optionally `/manage/<basename>.php`, the
 *     one legitimately exempt shape). For every match found, this
 *     REBUILDS the exact HTTP request line a browser would send
 *     (`"GET " . $path . " HTTP/1.1"`) and tests it against a regex BUILT
 *     FROM `.htaccess`'s OWN, LIVE `RewriteCond %{THE_REQUEST} …` pattern
 *     text (never a re-typed `\.php[\s?/]` copy — if that pattern is ever
 *     tightened or loosened, this guard's understanding of "does it 404"
 *     moves with it automatically). A match that isn't under `/manage/`
 *     and DOES trip the real block pattern is the exact bug class this
 *     guard exists to catch. The task's third disjunct — "or it does not
 *     hit the block rule" — is what makes this a REAL regex test rather
 *     than "any `.php` substring is bad": a shape that would not actually
 *     trip `.htaccess`'s own pattern (e.g. `.phpx`, no `?`/`/`/whitespace
 *     boundary) is correctly left alone.
 * (B) ALIAS SELF-CONSISTENCY — every SIMPLE (`^word$`-shaped, no regex
 *     metacharacters) `.htaccess` alias whose target is a `*.php` file is
 *     tested the SAME way: does requesting the alias's OWN literal path
 *     (e.g. `/qr`) trip the block pattern? A typo like `^qr\.php$` for the
 *     alias PATTERN itself would defeat the whole fix while looking
 *     correct at a glance; this catches that shape too. Also confirms the
 *     alias's target basename is a real file under `public_html/*.php`
 *     (a typo'd target is a dangling alias, not a functioning one).
 * (C) ALIAS COVERAGE — for every root `*.php` basename, derive its
 *     "expected extensionless segment" by stripping the trailing `.php`
 *     (`qr.php` -> `qr`, `song-media.php` -> `song-media`, …) — a
 *     mechanical derivation from the REAL basename, not a typed mapping —
 *     then scan the tree (comment-stripped) for a quoted URL literal that
 *     actually STARTS with that segment (`/qr?…`, `/song-media/…`, …). If
 *     the tree relies on that segment at all, a `.htaccess` rewrite whose
 *     literal PREFIX matches it (exact for a `^word$` alias, or a
 *     PREFIX-overlap for a capture-group alias like
 *     `^song-media/([0-9]+)$`) must exist and must target the SAME `.php`
 *     file. This is the check that catches "the `.htaccess` alias was
 *     later deleted, but the fixed `/qr?…` src is still there" — the
 *     OPPOSITE-direction regression from (A), and the one this guard's own
 *     mutation test below exercises via `.htaccess` rather than a
 *     consumer file.
 *
 * WHAT IT DELIBERATELY DOES NOT COVER
 * ------------------------------------
 * A root `*.php` file whose expected segment (C's derivation) is never
 * actually used anywhere as a URL literal is silently un-checked by (C) —
 * e.g. `audio-media.php`'s REAL alias is the differently-shaped
 * `/audio/<id>.mp3` prefix, which the mechanical `audio-media` derivation
 * does not match, but nothing in the tree emits a literal `/audio-media`
 * either, so there is nothing for (C) to flag either way. `index.php`
 * (the SPA catch-all target) and `error.php`
 * (an `ErrorDocument`-invoked target, never a URL literal in our own
 * source) are likewise never found by the scan and so never assessed —
 * correctly, since a scan of EMITTED URLs cannot find a URL nobody emits.
 *
 * MUTATION-PROVEN (rule #34) — both directions of the bug this guard
 * exists to catch were actually broken, run, confirmed RED, and restored:
 *   1. Reverted js/modules/print.js's `qr` block src from `/qr?data=` back
 *      to the historical `/qr.php?data=` (check A's exact original bug) ->
 *      RED: "print.js:NNN emits an unroutable literal '.php' URL for
 *      qr.php: /qr.php?data=…". Restored -> green.
 *   2. Removed `.htaccess`'s `RewriteRule ^qr$ qr.php [QSA,L]` line (check
 *      C's "alias silently removed" direction, the task's OTHER suggested
 *      mutation) -> RED: "qr.php's extensionless segment '/qr' is used
 *      … but no .htaccess alias routes it there". Restored -> green.
 *   (Full commands + output are in the session report that shipped this
 *   file — this guard's own execution proves nothing about a mutation
 *   that isn't ALSO actually run and restored in the working tree; see
 *   CLAUDE.md rule #34's "a guard whose first green run was never
 *   challenged is worthless in this repo".)
 *
 *   php tests/php/test-endpoint-routing.php
 *
 * Exit status 0 = every emitted root-endpoint URL actually routes, 1 = drift.
 *
 * @see appWeb/public_html/.htaccess                     the file this guard parses, never re-types
 * @see appWeb/public_html/qr.php                          the original bug (fixed alongside this guard)
 * @see appWeb/public_html/org-logo.php                    the original bug (fixed alongside this guard)
 * @see appWeb/public_html/webhook-drain.php                the THIRD instance this guard's own build surfaced
 * @see tests/php/test-org-logo-surfaces.php               the sibling wiring guard this file complements (never duplicates — that file checks org-logo's OWN feature wiring; this one checks ROUTING for every root endpoint, org-logo included)
 * @see tests/test-qr-cuercode.js                          ditto for the QR feature specifically
 * @link https://httpd.apache.org/docs/current/mod/mod_rewrite.html#thecondpattern  THE_REQUEST semantics (why a rewrite below the block rule can't rescue a ".php" literal)
 */

$repoRoot  = dirname(__DIR__, 2);
$pub       = $repoRoot . '/appWeb/public_html';
$htaccess  = $pub . '/.htaccess';

$failures = [];
function endpointRoutingFail(array &$failures, string $msg): void
{
    $failures[] = $msg;
}

/* =============================================================================
 * STEP 0 — shared helpers (mirror the established house style in
 * test-org-logo-surfaces.php / test-qr-cuercode.js: comment-stripping
 * preserves newlines so a reported line number stays correct, and the tree
 * walk is a plain recursive glob, never a typed file list).
 * ============================================================================= */

/** Blank `/* … *\/`, `// …` and `<!-- … -->` comment BODIES (keep the
 *  newlines) so a doc-block MENTIONING the broken URL shape (several exist,
 *  deliberately, explaining this very fix) never false-positives a scan
 *  that is looking for REAL, executable string literals. */
function endpointRoutingStripComments(string $src): string
{
    $src = (string)preg_replace_callback('#/\*.*?\*/#s', static fn(array $m) => str_repeat("\n", substr_count($m[0], "\n")), $src);
    $src = (string)preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
    $src = (string)preg_replace_callback('#<!--.*?-->#s', static fn(array $m) => str_repeat("\n", substr_count($m[0], "\n")), $src);
    return $src;
}

/** Recursively list every .php/.js file under $dir, skipping
 *  vendor/node_modules/.git — the SAME tree-derived shape every sibling
 *  guard in this file's family uses (rule #34). */
function endpointRoutingWalk(string $dir): array
{
    $acc = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            || str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $ext = $file->getExtension();
        if ($ext === 'php' || $ext === 'js') {
            $acc[] = $path;
        }
    }
    return $acc;
}

/** 1-based line number of byte offset $offset within $src. */
function endpointRoutingLineAt(string $src, int $offset): int
{
    return substr_count(substr($src, 0, $offset), "\n") + 1;
}

if (!is_file($htaccess)) {
    fwrite(STDERR, "FATAL: $htaccess does not exist — parser anchor moved or the file was removed.\n");
    exit(1);
}
$htaccessSrc = (string)file_get_contents($htaccess);
if (trim($htaccessSrc) === '') {
    fwrite(STDERR, "FATAL: .htaccess is empty — parser anchor moved.\n");
    exit(1);
}

/* =============================================================================
 * STEP 1 — parse the REAL .htaccess (never a hardcoded re-statement of what
 * it says, rule #34): the block condition + rule, the /manage/ passthrough,
 * and every RewriteRule that targets a *.php file.
 * ============================================================================= */

/* 1a. The block condition + the rule immediately following it. Anchored on
   the DIRECTIVE SHAPE (RewriteCond on %{THE_REQUEST}, then a RewriteRule
   matching everything with an R=404), never on the comment prose around
   it — a rewording of the surrounding "# Block direct access…" comment
   must never break this parser. */
if (!preg_match('/RewriteCond\s+%\{THE_REQUEST\}\s+(\S+)(?:\s+\[([^\]]*)\])?\s*\n\s*RewriteRule\s+\S+\s+\S+\s+\[([^\]]*R=404[^\]]*)\]/', $htaccessSrc, $blockMatch)) {
    fwrite(STDERR, "FATAL: could not find the 'RewriteCond %{THE_REQUEST} …' + 'RewriteRule … [R=404,…]' block-direct-PHP-access pair in .htaccess — parser anchor moved, or the rule was removed/restructured. This guard's whole premise depends on that rule existing; fix the parser (or the rule) before trusting this guard's output.\n");
    exit(1);
}
$blockPatternText = $blockMatch[1];              // e.g. \.php[\s?/]
$blockCondFlags    = $blockMatch[2] ?? '';        // e.g. NC
$blockNoCase       = stripos($blockCondFlags, 'NC') !== false;
/* Build the REAL tester regex FROM the parsed pattern text — this is the
   mechanism (rule #35) that keeps this guard in lockstep with .htaccess:
   if the block pattern is ever tightened/loosened, this regex moves with
   it automatically, rather than silently testing a stale copy. */
$blockRegex = '#' . $blockPatternText . '#' . ($blockNoCase ? 'i' : '');
if (@preg_match($blockRegex, '') === false) {
    fwrite(STDERR, "FATAL: the block pattern parsed from .htaccess ('$blockPatternText') is not a valid PCRE once wrapped — parser anchor moved.\n");
    exit(1);
}

/** Does requesting $urlPath (a root-relative path, e.g. "/qr.php?data=x")
 *  trip the REAL .htaccess block-direct-PHP-access rule? Reconstructs the
 *  shape of a genuine HTTP request line (%{THE_REQUEST}) — METHOD, SPACE,
 *  PATH, SPACE, "HTTP/1.1" — because the block pattern's char class
 *  `[\s?/]` is anchored on what comes AFTER ".php", and a bare path with no
 *  trailing query still has the mandatory trailing space before "HTTP/1.1"
 *  in a real request line, which is exactly the boundary the block pattern
 *  is designed to catch. */
function endpointRoutingHitsBlock(string $urlPath, string $blockRegex): bool
{
    $syntheticRequestLine = 'GET ' . $urlPath . ' HTTP/1.1';
    return preg_match($blockRegex, $syntheticRequestLine) === 1;
}

/* 1b. The /manage/ passthrough — must exist, and must sit BEFORE the block
   rule (that ordering is WHY /manage/ is exempt at all: the passthrough's
   [L] flag stops rewrite processing for a /manage/… request before it ever
   reaches the block condition below it). */
if (!preg_match('/^RewriteRule\s+\^manage\/\s+-\s+\[L\]\s*$/m', $htaccessSrc, $mm, PREG_OFFSET_CAPTURE)) {
    fwrite(STDERR, "FATAL: could not find the '/manage/' passthrough RewriteRule in .htaccess — parser anchor moved, or the rule was removed. The /manage/ exemption this guard relies on (and every /manage/*.php page's own routing) depends on it.\n");
    exit(1);
}
$managePassthroughOffset = $mm[0][1];
$blockCondOffset = (int)strpos($htaccessSrc, $blockMatch[0]);
if ($managePassthroughOffset >= $blockCondOffset) {
    endpointRoutingFail($failures, '.htaccess: the /manage/ passthrough RewriteRule appears AFTER the block-direct-PHP-access rule — this reorders away the /manage/ exemption every /manage/*.php page (and this guard\'s own manage-exempt check A) depends on.');
}

/* 1c. Every RewriteRule whose TARGET is a *.php file (the query string, if
   any, is stripped before the .php-suffix check). Captures the pattern,
   the target basename, and its own line number (for readable failures).
   Deliberately permissive on the PATTERN shape here — simple (`^qr$`) and
   capture-group (`^song-media/([0-9]+)$`) rules both get collected; they
   are told apart in step 3 (check B is simple-only; check C handles both
   via a literal-prefix derivation). */
preg_match_all('/^RewriteRule\s+(\S+)\s+(\S+?)(?:\?\S*)?\s+\[([^\]]*)\]\s*$/m', $htaccessSrc, $ruleMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
$phpTargetRules = [];
foreach ($ruleMatches as $rm) {
    $pattern = $rm[1][0];
    $target  = $rm[2][0];
    if (!str_ends_with($target, '.php')) {
        continue;
    }
    $phpTargetRules[] = [
        'pattern' => $pattern,
        'target'  => $target, // basename, e.g. 'qr.php' or '.well-known/apple-app-site-association.php'
        'line'    => endpointRoutingLineAt($htaccessSrc, $rm[0][1]),
    ];
}
if (count($phpTargetRules) < 5) {
    fwrite(STDERR, 'FATAL: only found ' . count($phpTargetRules) . " RewriteRule(s) targeting a *.php file in .htaccess (expected several — api, og-image, qr, org-logo, webhook-drain, song-media, audio-media, …) — parser anchor moved (rule #34 anti-under-report floor).\n");
    exit(1);
}

/* =============================================================================
 * STEP 2 — every root-level *.php file (glob, never a typed list) plus the
 * comment-stripped source of every .php/.js file in the tree.
 * ============================================================================= */

$rootPhpFiles = array_map('basename', glob($pub . '/*.php') ?: []);
sort($rootPhpFiles);
if (count($rootPhpFiles) < 5) {
    fwrite(STDERR, 'FATAL: only found ' . count($rootPhpFiles) . " root-level *.php file(s) under appWeb/public_html — glob anchor moved (rule #34 anti-under-report floor).\n");
    exit(1);
}

$allFiles = endpointRoutingWalk($pub);
if (count($allFiles) < 50) {
    fwrite(STDERR, "FATAL: only " . count($allFiles) . " files walked under appWeb/public_html — tree walk under-read (rule #34 anti-under-report floor).\n");
    exit(1);
}
/** @var array<string,string> $strippedByFile path => comment-stripped source, read once and reused by checks A + C */
$strippedByFile = [];
foreach ($allFiles as $path) {
    $strippedByFile[$path] = endpointRoutingStripComments((string)file_get_contents($path));
}

/* =============================================================================
 * CHECK A — the bug itself: a literal "/<basename>.php" URL emission,
 * outside /manage/, that the REAL .htaccess block pattern would 404.
 *
 * SELF-TEST FIRST (rule #34 anti-under-report floor, adapted): unlike check
 * B/C's counts (structural — how many aliases/segments EXIST — which stay
 * high regardless of the tree's current bug count), check A's live match
 * count is the very thing this fix drove to a HEALTHY zero: every real
 * '/<basename>.php' emission in the tree was corrected as part of this same
 * change (see .htaccess, qr.php/org-logo.php/webhook-drain.php's
 * consumers). A "must find at least one real occurrence" floor would
 * therefore be WRONG the moment the fix lands — it would demand the guard
 * find bugs that no longer exist. Instead, this proves the SCANNING
 * MACHINERY itself works by running it against two synthetic fixtures
 * BEFORE touching the real tree: a manufactured "<img src=\"/api.php?x=1\">"
 * (must be flagged: not /manage/, and does hit the real block pattern) and
 * "<img src=\"/manage/api.php?x=1\">" (must NOT be flagged: the /manage/
 * exemption). If .htaccess's block pattern is ever loosened so THIS
 * synthetic case stops tripping it, or the regex/exemption logic below
 * breaks, this self-test fails LOUD before the live-tree scan can silently
 * report a false "all clear" by finding nothing to check.
 * ============================================================================= */

$selfTestBasename = 'api.php';
$selfTestQuoted   = preg_quote($selfTestBasename, '#');
$selfTestRe       = '#([\'"`])/(manage/)?' . $selfTestQuoted . '(\?[^\'"`]*)?\1#';

if (preg_match($selfTestRe, '<img src="/api.php?x=1">', $stPos) !== 1) {
    fwrite(STDERR, "FATAL (check A self-test): the detection regex failed to match a manufactured '/api.php?x=1' fixture — parser anchor moved.\n");
    exit(1);
}
if (!endpointRoutingHitsBlock('/api.php?x=1', $blockRegex)) {
    fwrite(STDERR, "FATAL (check A self-test): the REAL .htaccess block pattern ('{$blockPatternText}') does not consider '/api.php?x=1' unroutable — either the pattern was loosened in a way that changes this guard's whole premise, or this guard's synthetic-request-line builder is wrong. Investigate before trusting this guard's output.\n");
    exit(1);
}
if (preg_match($selfTestRe, '<img src="/manage/api.php?x=1">', $stPos2) !== 1 || $stPos2[2][0] === '') {
    fwrite(STDERR, "FATAL (check A self-test): the /manage/ exemption capture group failed on a manufactured '/manage/api.php?x=1' fixture — parser anchor moved.\n");
    exit(1);
}
echo "  (check A self-test: detection regex + block-pattern + /manage/ exemption all verified against synthetic fixtures)\n";

$checkAMatches = 0;
foreach ($rootPhpFiles as $basename) {
    $quoted = preg_quote($basename, '#');
    /* Opening delimiter (a quote char, or a JS/HTML attribute boundary
       space) immediately followed by "/" + basename [+ "manage/" + basename
       for the one legitimately exempt shape] + an optional query string, up
       to the SAME closing delimiter. Mirrors the quote-anchored technique
       test-org-logo-surfaces.php / test-qr-cuercode.js already use — a
       genuine executable string literal, never a bare substring match. */
    $re = '#([\'"`])/(manage/)?' . $quoted . '(\?[^\'"`]*)?\1#';
    foreach ($strippedByFile as $path => $code) {
        if (preg_match_all($re, $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            continue;
        }
        foreach ($m as $one) {
            $checkAMatches++;
            $isManageExempt = $one[2][0] !== '';
            $urlPath = '/' . $one[2][0] . $basename . $one[3][0];
            if ($isManageExempt) {
                continue; // /manage/*.php is exempt — the passthrough rule (step 1b) handles it
            }
            if (!endpointRoutingHitsBlock($urlPath, $blockRegex)) {
                continue; // does not actually match the REAL block pattern — leave it (task's 3rd disjunct)
            }
            $rel  = str_starts_with($path, $pub . '/') ? substr($path, strlen($pub) + 1) : $path;
            $line = endpointRoutingLineAt($code, $one[0][1]);
            endpointRoutingFail(
                $failures,
                "appWeb/public_html/$rel:$line emits an unroutable literal '.php' URL for $basename: $urlPath"
                . " — .htaccess's block-direct-PHP-access rule 404s this before $basename's own code ever runs"
                . " (its raw request line matches .htaccess's OWN '{$blockPatternText}' pattern). Use the"
                . " extensionless .htaccess alias instead (the /og-image precedent) — see .htaccess and rules #33/#38/#42."
            );
        }
    }
}
/* Deliberately NO "must find at least one" floor here — see this check's
   own doc-block above: a healthy, already-fixed tree legitimately has
   $checkAMatches === 0, and the self-test above already proves the
   scanning machinery itself is sound. */

/* =============================================================================
 * CHECK B — alias self-consistency: a SIMPLE (`^word$`, no regex
 * metacharacter) .htaccess alias whose OWN produced request would itself
 * trip the block pattern (a typo'd `^qr\.php$` alias, say) defeats the fix
 * while looking correct at a glance. Also confirms the alias's target is a
 * real root-level file.
 * ============================================================================= */

$simpleAliasCount = 0;
foreach ($phpTargetRules as $rule) {
    if (preg_match('/^\^([A-Za-z0-9_.\/-]+)\$$/', $rule['pattern'], $sm) !== 1) {
        continue; // has a regex metacharacter (capture group, escape, …) — check C handles these
    }
    $simpleAliasCount++;
    $literalPath = '/' . $sm[1];
    if (endpointRoutingHitsBlock($literalPath, $blockRegex)) {
        endpointRoutingFail(
            $failures,
            ".htaccess:{$rule['line']} the alias 'RewriteRule {$rule['pattern']} {$rule['target']} …' produces the"
            . " request path '$literalPath', which ITSELF matches .htaccess's own block-direct-PHP-access pattern"
            . " ('{$blockPatternText}') — this alias would 404 the very request it exists to route. Likely a"
            . " stray '.php' (or other block-triggering character) baked into the alias PATTERN itself."
        );
    }
    if (!in_array($rule['target'], $rootPhpFiles, true)) {
        endpointRoutingFail(
            $failures,
            ".htaccess:{$rule['line']} the alias 'RewriteRule {$rule['pattern']} {$rule['target']} …' targets"
            . " '{$rule['target']}', which is not a file under appWeb/public_html/*.php — dangling alias"
            . " (typo'd target, or the target file was renamed/removed without updating this rule)."
        );
    }
}
if ($simpleAliasCount < 3) {
    fwrite(STDERR, "FATAL (check B): only found $simpleAliasCount simple ('^word\$') *.php alias(es) in .htaccess (expected several — api, og-image, qr, org-logo, webhook-drain, …) — parser anchor moved (rule #34 anti-under-report floor).\n");
    exit(1);
}

/* =============================================================================
 * CHECK C — alias coverage: for every root basename whose mechanically
 * derived extensionless segment (strip the trailing ".php") is ACTUALLY
 * used somewhere in the tree as a URL literal, a .htaccess rewrite whose
 * literal prefix matches that segment must exist and must target the SAME
 * .php file. This is the direction that catches "the .htaccess alias was
 * quietly deleted, but the already-fixed extensionless src is still
 * there" — the opposite regression from check A, and the one this file's
 * own mutation-proof exercises via .htaccess (see the doc-block above).
 * ============================================================================= */

/** The literal (non-regex-metacharacter) PREFIX of an Apache RewriteRule
 *  pattern, with the leading '^' stripped — e.g. '^song-media/([0-9]+)$'
 *  -> 'song-media/', '^qr$' -> 'qr', '^service-worker\.js$' ->
 *  'service-worker' (stops at the escape). Used to recognise BOTH exact
 *  (`^word$`) and capture-group/prefix (`^word/(…)$`) aliases uniformly,
 *  without hardcoding which shape each endpoint uses. */
function endpointRoutingAliasLiteralPrefix(string $pattern): string
{
    $p = ltrim($pattern, '^');
    $out = '';
    $len = strlen($p);
    for ($i = 0; $i < $len; $i++) {
        $ch = $p[$i];
        if (ctype_alnum($ch) || $ch === '-' || $ch === '_' || $ch === '/') {
            $out .= $ch;
            continue;
        }
        break; // first regex metacharacter (. \ ( [ { $ etc.) ends the literal prefix
    }
    return $out;
}

$aliasesByTarget = []; // targetBasename => list of literal prefixes
foreach ($phpTargetRules as $rule) {
    $aliasesByTarget[$rule['target']][] = endpointRoutingAliasLiteralPrefix($rule['pattern']);
}

$checkCSegmentsChecked = 0;
foreach ($rootPhpFiles as $basename) {
    $segment = substr($basename, 0, -4); // strip trailing '.php'
    if ($segment === '') {
        continue;
    }
    $quotedSeg = preg_quote($segment, '#');
    /* A quoted URL literal that STARTS with the segment, followed by '?',
       '/', or the closing quote — i.e. genuinely USED as a route, not a
       coincidental substring. Deliberately excludes the ".php"-suffixed
       form (check A already owns that) by requiring the char right after
       the segment NOT be '.'. */
    $re = '#([\'"`])/' . $quotedSeg . '(?=[?/]|\1)#';
    $usedSomewhere = false;
    foreach ($strippedByFile as $path => $code) {
        if (preg_match($re, $code) === 1) {
            $usedSomewhere = true;
            break;
        }
    }
    if (!$usedSomewhere) {
        continue; // nothing in the tree relies on this segment shape — nothing to check (documented above)
    }
    $checkCSegmentsChecked++;
    $prefixes = $aliasesByTarget[$basename] ?? [];
    $covered = false;
    foreach ($prefixes as $prefix) {
        if ($prefix !== '' && (str_starts_with($prefix, $segment) || str_starts_with($segment, $prefix))) {
            $covered = true;
            break;
        }
    }
    if (!$covered) {
        endpointRoutingFail(
            $failures,
            "$basename's extensionless segment '/$segment' is used somewhere in the tree as a URL literal, but"
            . " no .htaccess RewriteRule aliases a matching path to $basename — that request falls through to"
            . " the SPA catch-all (the index.php shell) instead of reaching $basename at all. Add an"
            . " extensionless alias (the /og-image precedent) targeting $basename."
        );
    }
}
if ($checkCSegmentsChecked < 2) {
    fwrite(STDERR, "FATAL (check C): only cross-checked $checkCSegmentsChecked root endpoint segment(s) against .htaccess (expected several — qr, org-logo, webhook-drain, api, og-image at minimum are all used somewhere) — parser anchor moved or the tree walk under-read.\n");
    exit(1);
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " endpoint-routing problem(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
printf(
    "OK: endpoint routing verified — %d root *.php file(s), %d .php-targeting .htaccess rule(s)"
        . " (%d simple), %d root-endpoint segment(s) cross-checked, %d file(s) walked,"
        . " %d '/<basename>.php' literal(s) scanned. Every emitted same-origin root-endpoint"
        . " URL actually routes.\n",
    count($rootPhpFiles),
    count($phpTargetRules),
    $simpleAliasCount,
    $checkCSegmentsChecked,
    count($allFiles),
    $checkAMatches
);
exit(0);
