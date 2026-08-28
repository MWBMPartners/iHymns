<?php

declare(strict_types=1);

/**
 * iHymns — Static WCAG 2.2 AA guards from the #1150/#1151 accessibility
 * sweep.
 *
 * ELI5: three cheap, purely-textual checks over the actual page templates
 * that catch a screen-reader user landing on an invisible control, a
 * screen reader losing track of which element an id refers to, or an
 * image with nothing to say about itself. None of these need a browser —
 * they are facts about the HTML source, derivable from the tree.
 *
 * WHAT IT ASSERTS
 *
 *   (1) `.card-layout-handle` (includes/pages/home.php) is never rendered
 *       `aria-hidden="true"`. That element is the drag-handle strip
 *       card-layout.js injects real "Move up" / "Move down" / "Hide this
 *       card" <button>s into once edit mode is entered (#1151) — an
 *       aria-hidden ancestor removes focusable descendants from the
 *       accessibility tree while leaving them in the visual/keyboard tab
 *       order (WCAG 4.1.2 Name, Role, Value): a screen-reader user
 *       tabbing through lands on a control with no announced name or
 *       role at all. This was the actual shipped state before #1151;
 *       this guard is the regression tie.
 *
 *   (2) No PHP template under includes/pages/, includes/partials/ or
 *       manage/*.php declares the same STATIC `id="…"` twice. Two
 *       elements sharing an id is invalid HTML5 and breaks `aria-
 *       labelledby` / `for` / `aria-controls` references and any
 *       `#id` deep link — whichever element the browser picks first
 *       "wins" every `getElementById` / `:target` / label association,
 *       silently mis-wiring the other. The file list is DERIVED from
 *       the tree (glob), not hand-typed, per rule #34.
 *
 *   (3) Every `<img>` tag in the same tree carries an `alt` attribute
 *       (decorative images still need `alt=""` — an absent attribute is
 *       the one shape screen readers cannot recover from; they announce
 *       the filename instead of nothing).
 *
 * Checks (2) and (3) are PROVEN able to fail (rule #34) via the
 * self-test at the bottom of this file, which feeds the same scanner
 * functions a deliberately-broken fixture string before the real
 * source-tree scan runs — the guard is exercised against a known-bad
 * input on every single run, not just asserted to have once gone red.
 *
 * No DB, no network — a source-tree scan, same shape as
 * test-fragment-inline-scripts.php / test-accessibility-css-coverage.php.
 *
 * Usage: php tests/php/test-a11y-static-checks.php
 * Exit 0 = pass, 1 = fail.
 */

/**
 * Strip HTML comments and PHP comments before any pattern matching, so
 * prose that MENTIONS a tag/attribute (like this very file's doc-block)
 * is never mistaken for the real thing. Newlines are preserved so any
 * line-numbered diagnostic below stays truthful.
 * (test-fragment-inline-scripts.php / test-accessibility-css-coverage.php
 * hit this same trap first — worth repeating here rather than assuming.)
 */
function a11yStripComments(string $src): string
{
    // HTML comments: <!-- ... -->
    $src = preg_replace_callback(
        '~<!--.*?-->~s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    ) ?? $src;
    // PHP block comments: /* ... */ (deliberately not touching // or # —
    // those are rare in this codebase's PHP and risk eating real code
    // after a URL containing '//').
    $src = preg_replace_callback(
        '~/\*.*?\*/~s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    ) ?? $src;
    return $src;
}

/**
 * Find every STATIC `id="…"` attribute value in a source string.
 * Deliberately anchored on a preceding whitespace/tag-open boundary so a
 * `data-colour-picker-id="…"` or similar suffix match never counts as a
 * real `id` attribute (the naive `id="[^"]+"` regex this replaces caught
 * exactly that false positive during the #1150/#1151 sweep — `manage/
 * songbooks.php`'s `data-colour-picker-id="edit-songbook-colour"`
 * appeared "duplicated" purely because the substring `id="edit-songbook-
 * colour"` occurs at the tail of that longer attribute name three times).
 * Only literal, fully-static values are considered — an id built from a
 * PHP loop variable (`id="row-<?= $i ?>"`) never reaches this regex
 * because the `<?php ... ?>` tag breaks the `[^">?]+` character class,
 * and an id built from a JS TEMPLATE LITERAL inside an embedded
 * `<script>` block (`id="${id}"`, `manage/print-templates.php`'s
 * dynamic option-row builder) never reaches it either, because `$`/`{`
 * are also excluded — both are already per-instance unique by
 * construction (a fresh value substituted per PHP loop iteration / per
 * JS call), and a text scanner cannot evaluate PHP or JS to check them.
 * A first pass of this scanner DIDN'T exclude `${…}` and flagged four
 * `id="${id}"` JS template-literal occurrences in print-templates.php as
 * "duplicates" — kept as the a11yFindStaticIds self-test below so that
 * false positive can't come back.
 *
 * @return string[] every static id value found, in source order (with duplicates)
 */
function a11yFindStaticIds(string $src): array
{
    if (preg_match_all('~(?:^|[\s<])id="([^">?${}]+)"~m', $src, $m) !== false) {
        return $m[1];
    }
    return [];
}

/**
 * @return array<string,int> id => how many times it appears (only ids seen 2+ times)
 */
function a11yDuplicateIds(string $src): array
{
    $counts = [];
    foreach (a11yFindStaticIds($src) as $id) {
        $counts[$id] = ($counts[$id] ?? 0) + 1;
    }
    return array_filter($counts, static fn(int $n): bool => $n > 1);
}

/**
 * @return int[] 1-based line numbers of every `<img` tag missing `alt="…"` (or `alt`)
 */
function a11yImagesMissingAlt(string $src): array
{
    $lines = [];
    // Strip PHP tags first (#1968 P4): an interpolated attribute value (a PHP
    // short-echo in src) ends with a PHP close tag, whose bare > would otherwise
    // truncate the non-greedy <img …> match BEFORE a later alt="…" — a false
    // "no alt", the exact "interpolated close-tag truncates the match" class rule
    // #34 warns about. Replace each PHP block with only its own newlines so no >
    // survives inside it AND the byte offsets used for line reporting stay exact.
    $src = preg_replace_callback('~<\?(?:php|=)?[\s\S]*?\?>~', static function (array $mm): string {
        return str_repeat("\n", substr_count($mm[0], "\n"));
    }, $src) ?? $src;
    // A single <img ...> can span multiple lines in this codebase's formatted
    // markup, so match across the whole string (DOTALL-ish via [\s\S]) up to
    // the closing '>', not line-by-line.
    if (preg_match_all('~<img\b[\s\S]*?>~i', $src, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[0] as [$tag, $offset]) {
            if (!preg_match('~\balt\s*=~i', $tag)) {
                $lines[] = substr_count(substr($src, 0, $offset), "\n") + 1;
            }
        }
    }
    return $lines;
}

/* ---------------------------------------------------------------------------
 * SELF-TEST — prove the scanners can actually fail (rule #34) before
 * trusting them against the real tree.
 * ------------------------------------------------------------------------- */
$selfTestFailures = [];

$dupFixture = '<div id="thing"></div><span id="thing"></span><p data-colour-picker-id="thing"></p>';
$dupFound = a11yDuplicateIds($dupFixture);
if (!isset($dupFound['thing']) || $dupFound['thing'] !== 2) {
    $selfTestFailures[] = 'a11yDuplicateIds() did not catch a deliberately duplicated id="thing" '
        . '(or miscounted the data-colour-picker-id look-alike) — the scanner cannot be trusted.';
}
$cleanFixture = '<div id="thing-a"></div><span id="thing-b"></span>';
if (a11yDuplicateIds($cleanFixture) !== []) {
    $selfTestFailures[] = 'a11yDuplicateIds() flagged two DIFFERENT ids as duplicates — false positive.';
}

// Regression fixture for the real false positive this scanner produced on its
// first run against manage/print-templates.php: a JS template literal
// `id="${id}"` repeated across several independent, runtime-branched code
// paths inside an embedded <script> block is NOT a static duplicate id.
$jsTemplateFixture = 'let s = `<select id="${id}">` + `<input id="${id}">` + `<input id="${id}">`;';
if (a11yDuplicateIds($jsTemplateFixture) !== []) {
    $selfTestFailures[] = 'a11yDuplicateIds() flagged a JS template-literal id="${id}" as a static duplicate '
        . '(the print-templates.php false positive) — the ${…} exclusion regressed.';
}

$altFixture = '<img src="/x.png" alt="A photo"><img src="/y.png">';
$altMissing = a11yImagesMissingAlt($altFixture);
if ($altMissing !== [1]) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() did not flag the second <img> (no alt) on fixture line 1, '
        . 'or wrongly flagged the first (which has alt) — got line(s): ' . implode(',', $altMissing);
}
$altOkFixture = "<img src=\"/x.png\" alt=\"\">\n<img src=\"/y.png\" alt=\"Decorative\">";
if (a11yImagesMissingAlt($altOkFixture) !== []) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() flagged an <img> that HAS alt (including alt="") — false positive.';
}

$commentFixture = "<!-- an <img src=x> in a comment --><div id=\"real\"></div>";
if (a11yImagesMissingAlt(a11yStripComments($commentFixture)) !== []) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() flagged an <img> that only existed inside an HTML comment.';
}

// #1968 P4 — an <img> whose src is a PHP short-echo (a PHP close tag inside the
// tag) but which DOES carry an alt must NOT be flagged (the truncation removed)...
$phpOpen = '<' . '?=';
$phpClose = '?' . '>';
$altInterpOk = '<img class="c"' . "\n"
    . '     src="' . $phpOpen . ' h($u) ' . $phpClose . '"' . "\n"
    . '     alt="' . $phpOpen . ' h($a) ' . $phpClose . '">';
if (a11yImagesMissingAlt($altInterpOk) !== []) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() false-flagged an interpolated <img> that HAS alt after a PHP echo in src — the PHP-strip regressed.';
}
// ...while an interpolated <img> with NO alt is STILL flagged (no over-strip).
$altInterpMissing = '<img src="' . $phpOpen . ' h($u) ' . $phpClose . '">';
if (a11yImagesMissingAlt($altInterpMissing) === []) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() missed an interpolated <img> with NO alt — the PHP-strip over-stripped the tag.';
}

if ($selfTestFailures) {
    fwrite(STDERR, "FAIL: test-a11y-static-checks.php self-test (the scanners themselves are broken):\n\n");
    foreach ($selfTestFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

/* ---------------------------------------------------------------------------
 * REAL SCAN — derived from the tree, not a hand-typed list (rule #34).
 * ------------------------------------------------------------------------- */
$root   = dirname(__DIR__, 2);
$public = $root . '/appWeb/public_html';

$targets = [];
foreach ([$public . '/includes/pages', $public . '/includes/partials'] as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    foreach (glob($dir . '/*.php') ?: [] as $f) {
        $targets[] = $f;
    }
}
foreach (glob($public . '/manage/*.php') ?: [] as $f) {
    $targets[] = $f;
}
sort($targets);

if (!$targets) {
    fwrite(STDERR, "FAIL: no target .php files found under includes/pages, includes/partials or manage/ — "
        . "the tree moved and this guard's glob needs updating.\n");
    exit(1);
}

$failures = [];

/* --- Assertion 1: home.php's card-layout-handle is never aria-hidden --- */
$homePath = $public . '/includes/pages/home.php';
if (!is_file($homePath)) {
    $failures[] = "includes/pages/home.php not found — cannot check the card-layout-handle regression.";
} else {
    $homeSrc = a11yStripComments((string) file_get_contents($homePath));
    if (preg_match('~class="card-layout-handle"[^>]*aria-hidden="true"~', $homeSrc)
        || preg_match('~aria-hidden="true"[^>]*class="card-layout-handle"~', $homeSrc)
    ) {
        $failures[] = 'includes/pages/home.php: .card-layout-handle is aria-hidden="true" again. '
            . 'card-layout.js injects real, focusable "Move up"/"Move down"/"Hide this card" buttons '
            . 'into this element in edit mode — an aria-hidden ancestor strips them from the '
            . 'accessibility tree while they stay in the visual/keyboard tab order (WCAG 4.1.2). '
            . 'Only the decorative grip <i> icon should carry aria-hidden, not the strip itself.';
    }
}

/* --- Assertions 2 & 3: duplicate ids + missing alt, across every target --- */
foreach ($targets as $file) {
    $rel = substr($file, strlen($public) + 1);
    $src = a11yStripComments((string) file_get_contents($file));

    $dups = a11yDuplicateIds($src);
    foreach ($dups as $id => $count) {
        $failures[] = sprintf(
            '%s: id="%s" is declared %d times — invalid HTML5, and breaks any aria-labelledby/for/'
            . 'aria-controls reference or #%s deep link that expects a single element.',
            $rel,
            $id,
            $count,
            $id
        );
    }

    $missingAltLines = a11yImagesMissingAlt($src);
    foreach ($missingAltLines as $line) {
        $failures[] = sprintf(
            '%s:%d — <img> with no alt attribute. Use a meaningful alt="…", or alt="" + aria-hidden="true" '
            . 'if the image is purely decorative — an absent alt makes a screen reader announce the file path instead.',
            $rel,
            $line
        );
    }
}

/* ------------------------------------------------------------------------- */
if ($failures) {
    fwrite(STDERR, "FAIL: static WCAG 2.2 AA checks (#1150/#1151):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

printf(
    "PASS: %d template(s) scanned under includes/pages, includes/partials and manage/ — "
    . "no duplicate static ids, no <img> missing alt, home.php's card-layout-handle stays perceivable.\n",
    count($targets)
);
exit(0);
