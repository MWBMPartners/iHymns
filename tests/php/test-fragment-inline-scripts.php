<?php

declare(strict_types=1);

/**
 * iHymns — Fragment inline-script CSP guard (#1565 / #1569)
 *
 * ELI5: `includes/pages/*.php` and `includes/partials/*.php` are HTML
 * FRAGMENTS the SPA fetches over `/api?page=…` and injects into the DOM —
 * they are never a full document response. A `<script>` tag written inside
 * one of them looks like it will run, but it never does; this guard makes
 * sure nobody writes one again without a very good, tracked reason.
 *
 * Detail: the document's enforcing Content-Security-Policy is
 * `script-src 'self' 'nonce-<per-request>'` (#117) — no `unsafe-inline`.
 * A `<script>` tag that ships as part of one of these fragments can NEVER
 * carry the nonce that would make the browser trust it, for two
 * independent reasons:
 *   1. The nonce is minted once per top-level document response. The
 *      fragment is fetched separately, after the document (and its nonce)
 *      already rendered, and is spliced into the existing DOM — there is
 *      no live nonce left to stamp it with.
 *   2. Several of these fragments — the public `home`/`song`/`songbook`
 *      pages in particular — are SHARED-CACHE API responses
 *      (`/api?page=home` etc., rule #6 in .claude/CLAUDE.md): the exact
 *      same cached HTML bytes are served to every visitor, so there is no
 *      single request's nonce to bake in even in principle.
 * A fragment inline `<script>` therefore silently no-ops for every visitor
 * — this is precisely the #1565 bug (Export dropdowns doing nothing on
 * song.php / songbook.php) this guard exists to stop from recurring. The
 * fix is always the same shape: move the logic into a real
 * `js/modules/*.js` ES module and have the SPA router's `afterPageLoad()`
 * import + call it once the fragment lands (see export-ui.js / router.js).
 *
 * Algorithm (mirrors test-component-json-guard.php's shape: source-tree
 * scan, no DB, slots straight into the CI lint step):
 *   1. Strip comments BEFORE matching (see stripCommentsPreservingLines()
 *      below) — LOAD-BEARING, not a stylistic nicety. Two real files in
 *      this tree contain the literal text "<script>" inside a comment,
 *      and matching the raw source would misreport both as violations:
 *        - includes/pages/home.php — an HTML `<!-- ... -->` doc-comment
 *          that mentions "inline <script> tags" in prose while explaining
 *          why home-page.js is a real module instead.
 *        - includes/pages/person.php — a PHP `/* ... *\/` security
 *          comment that says a DB value must not "break out of this
 *          public <script> element" (it sits directly above a REAL,
 *          allowed `<script type="application/ld+json">` block, so the
 *          comment's prose neighbours an intentional match).
 *      Do NOT "simplify" this step away — without it those two files
 *      falsely fail every run.
 *   2. Match every `<script …>` opening tag. It's ALLOWED iff it is
 *      an external script (`src=`, governed by `'self'`, not the nonce)
 *      or an inert JSON data island (`type="application/(ld+)?json"`).
 *      Everything else — including `type="module"` and a bare
 *      `<script>` — is an executable inline script the CSP refuses.
 *   3. A small, COUNT-EXACT allowlist (see $allow below) is the only way
 *      a file may still contain a disallowed tag. It fails the build
 *      both when a file EXCEEDS its allowance (new/regrown debt) and when
 *      an allowlisted file has FEWER matches than allowed (the debt was
 *      paid — the stale entry must be deleted so the list self-cleans).
 *
 *   php tests/php/test-fragment-inline-scripts.php
 *
 * Exit status 0 = clean, 1 = at least one disallowed / stale reference.
 */

/**
 * Blank out `<!-- ... -->` and `/* ... *\/` comment bodies while keeping
 * every newline they contained, so a `<script>` match found afterwards
 * still reports against its correct ORIGINAL line number.
 *
 * ELI5: like using correction tape on a comment instead of cutting the
 * page out — the words disappear but the page stays the same length, so
 * line numbers below it don't shift.
 * Detail: this is the load-bearing step named in the doc-block above. A
 * plain `preg_replace(..., '', $src)` (no line preservation) is functionally
 * equivalent for MATCHING purposes — either way, a `<script>` string sitting
 * only inside a comment can no longer match — but preserving newlines
 * additionally keeps this guard's error output pointing at the real line,
 * which matters once there are two suspects (home.php, person.php) to
 * distinguish between a real vs. commented-out tag while debugging.
 *
 * @param string $src Raw file contents.
 * @return string Same length in lines; comment bodies blanked.
 */
function stripCommentsPreservingLines(string $src): string
{
    // HTML comments first (home.php's false positive lives in one of these).
    $src = preg_replace_callback('/<!--.*?-->/s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $src);

    // PHP /* ... */ block comments next (person.php's false positive is a
    // PHP security doc-comment, not an HTML comment — both forms must be
    // stripped or one of the two known false positives resurfaces).
    $src = preg_replace_callback('~/\*.*?\*/~s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $src);

    return $src;
}

/**
 * Count-exact allowlist of already-known, tracked, temporary exceptions.
 *
 * Keyed by basename (pages/ and partials/ do not currently share a
 * filename, so this is unambiguous). Every entry names the tracking issue
 * and the reason the tag is safe to leave in place a little longer — see
 * the file-level doc-block, point 3, for the two-directional check this
 * enables (regression vs. stale entry).
 */
$allow = [
    'request-a-song.php' => [
        'count' => 1,
        'why'   => '#1572 — offline-queue inline module; CSP-dead but the page has a no-JS <form action> fallback (#711). DELETE this entry when #1572 closes.',
    ],
];

const GUARD_INLINE_SCRIPT_PATTERN = '/<script\b([^>]*)>/i';
const GUARD_ALLOWED_SRC_PATTERN   = '/\bsrc\s*=/i';
const GUARD_ALLOWED_JSON_PATTERN  = '/type\s*=\s*["\']application\/(ld\+)?json["\']/i';

$repoRoot    = dirname(__DIR__, 2);
$scanDirs    = [
    $repoRoot . '/appWeb/public_html/includes/pages',
    $repoRoot . '/appWeb/public_html/includes/partials',
];

$files = [];
foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) {
        fwrite(STDERR, "FATAL: $dir not found\n");
        exit(1);
    }
    // Non-recursive on purpose — both directories are a flat list of
    // fragment templates today; a future subdirectory would need this
    // guard extended deliberately, not silently widened.
    foreach (glob($dir . '/*.php') ?: [] as $f) {
        $files[] = $f;
    }
}
sort($files);

$violationsByFile = [];   // basename => [ "line: <script ...>", ... ]
$scanned = 0;

foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) { continue; }
    $scanned++;

    $stripped = stripCommentsPreservingLines($src);
    $lines    = explode("\n", $stripped);

    if (!preg_match_all(GUARD_INLINE_SCRIPT_PATTERN, $stripped, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    $base = basename($file);
    foreach ($matches[0] as $i => [$tagText, $offset]) {
        $attrs = $matches[1][$i][0];

        $allowedExternal = preg_match(GUARD_ALLOWED_SRC_PATTERN, $attrs) === 1;
        $allowedJson     = preg_match(GUARD_ALLOWED_JSON_PATTERN, $attrs) === 1;
        if ($allowedExternal || $allowedJson) { continue; }   // not an inline-executable script

        // Line number: newlines in the stripped text up to the match offset —
        // stripCommentsPreservingLines() kept every comment's newlines, so
        // this still lands on the real source line.
        $lineNo = substr_count($stripped, "\n", 0, $offset) + 1;
        $lineText = trim($lines[$lineNo - 1] ?? $tagText);
        $violationsByFile[$base][] = sprintf('%s:%d  %s', $base, $lineNo, $lineText);
    }
}

$failures = [];

// Direction 1: any disallowed tag in a file with NO allowlist entry, or
// MORE tags than its allowlist entry permits.
foreach ($violationsByFile as $base => $hits) {
    $allowedCount = $allow[$base]['count'] ?? 0;
    if (count($hits) > $allowedCount) {
        $extra = array_slice($hits, $allowedCount);
        foreach ($extra as $h) { $failures[] = $h; }
    }
}

// Direction 2: an allowlisted file with FEWER matches than its entry
// claims — the debt was paid elsewhere and the entry is now stale.
foreach ($allow as $base => $meta) {
    $actual = count($violationsByFile[$base] ?? []);
    if ($actual < $meta['count']) {
        $failures[] = sprintf(
            '%s: allowlist claims %d, found %d — the debt was paid, delete this stale entry (%s)',
            $base,
            $meta['count'],
            $actual,
            $meta['why']
        );
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: disallowed fragment inline <script> reference(s) (#1565/#1569):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  $f\n"); }
    fwrite(STDERR, "\nA <script> in includes/pages/*.php or includes/partials/*.php is CSP-dead (no\n");
    fwrite(STDERR, "nonce ever reaches a cached/injected fragment, rule #6) — move the logic into a\n");
    fwrite(STDERR, "js/modules/*.js ES module and wire it from router.js afterPageLoad() instead, or\n");
    fwrite(STDERR, "add/adjust a count-exact \$allow entry with a tracking issue if this is a known,\n");
    fwrite(STDERR, "temporary exception (see the file's doc-block).\n");
    exit(1);
}

echo "PASS: no disallowed fragment inline <script> references ({$scanned} files scanned).\n";
exit(0);
