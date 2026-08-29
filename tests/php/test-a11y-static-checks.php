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

/**
 * @return int[] 1-based line numbers of every bare `<i>`/`<span>` that carries
 * `aria-label` with no `role` attribute anywhere on the same opening tag
 * (a11y audit M8, 2026-08-28 — ARIA 1.2 prohibits naming a generic element;
 * `role="img"` is what the codebase's own correct model, song.php's verified
 * badge, already uses).
 */
function a11yBareGenericAriaLabel(string $src): array
{
    $lines = [];
    // Same interpolated-close-tag hazard a11yImagesMissingAlt() documents —
    // strip PHP blocks to their own newlines first so a PHP short-echo inside
    // an attribute value can't truncate the `[^>]*` match early.
    $src = preg_replace_callback('~<\?(?:php|=)?[\s\S]*?\?>~', static function (array $mm): string {
        return str_repeat("\n", substr_count($mm[0], "\n"));
    }, $src) ?? $src;
    if (preg_match_all('~<(?:i|span)\b([^>]*)>~i', $src, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[1] as $idx => [$attrs, $_offset]) {
            if (preg_match('~\baria-label\s*=~i', $attrs) === 1
                && preg_match('~\brole\s*=~i', $attrs) !== 1
            ) {
                $wholeOffset = $m[0][$idx][1];
                $lines[] = substr_count(substr($src, 0, $wholeOffset), "\n") + 1;
            }
        }
    }
    return $lines;
}

/**
 * @return int[] 1-based line numbers of every `<h1>`-`<h6>` that carries
 * `role="button"` with no `tabindex` attribute on the same tag (a11y audit
 * M1, 2026-08-28 — never focusable, and Bootstrap's collapse data-API only
 * listens for `click`, so Enter/Space can never trigger it from the
 * keyboard). Deliberately scoped to HEADINGS, not every `role="button"` in
 * the tree — a real `<a href role="button">` (song.php's own correction-
 * form toggle) is natively focusable already and is the correct pattern,
 * not a variant of this bug.
 */
function a11yHeadingRoleButtonWithoutTabindex(string $src): array
{
    $lines = [];
    if (preg_match_all('~<h[1-6]\b([^>]*)>~i', $src, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[1] as $idx => [$attrs, $_offset]) {
            if (preg_match('~\brole\s*=\s*"button"~i', $attrs) === 1
                && preg_match('~\btabindex\s*=~i', $attrs) !== 1
            ) {
                $wholeOffset = $m[0][$idx][1];
                $lines[] = substr_count(substr($src, 0, $wholeOffset), "\n") + 1;
            }
        }
    }
    return $lines;
}

/**
 * True when `$src` (already comment-stripped) both emits the "Skip to main
 * content" link targeting `#main-content` AND opens a `<main id="main-
 * content">` landmark — the two-part M7 fix. Both live in the same file
 * (manage/includes/admin-nav.php), so one boolean check is enough; a real
 * scan below also proves admin-footer.php closes what this opens.
 */
function a11yHasSkipLinkAndMainLandmark(string $src): bool
{
    $hasSkipLink = preg_match('~<a\s[^>]*href="#main-content"~', $src) === 1;
    $hasMain     = preg_match('~<main\b[^>]*\bid="main-content"~', $src) === 1;
    return $hasSkipLink && $hasMain;
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

// M8 — a11yBareGenericAriaLabel() must catch a bare <i>/<span> aria-label
// with no role, must NOT flag one that already has role="img", and must NOT
// flag an aria-hidden icon (no aria-label at all) or a REAL element (e.g.
// <button aria-label="…">, which is a valid target for naming).
$bareFixture = '<i class="fa-solid fa-star" aria-label="Canonical version" title="x"></i>' . "\n"
    . '<span class="badge" aria-label="Has audio"></span>';
$bareLines = a11yBareGenericAriaLabel($bareFixture);
if ($bareLines !== [1, 2]) {
    $selfTestFailures[] = 'a11yBareGenericAriaLabel() did not flag both the bare <i> (line 1) and <span> '
        . '(line 2) with aria-label and no role — got line(s): ' . implode(',', $bareLines);
}
$roleOkFixture = '<i class="fa-solid fa-star" role="img" aria-label="Canonical version"></i>'
    . '<span aria-hidden="true"></span>'
    . '<button aria-label="Close"></button>';
if (a11yBareGenericAriaLabel($roleOkFixture) !== []) {
    $selfTestFailures[] = 'a11yBareGenericAriaLabel() false-flagged an <i role="img">, an aria-hidden <span> with '
        . 'no aria-label, or a real <button aria-label> — only a BARE i/span missing role should ever be flagged.';
}
// Interpolated attribute (PHP short-echo) must not truncate the tag match
// early and produce a false negative on a real defect two lines down.
$bareInterpFixture = '<i class="' . $phpOpen . ' h($c) ' . $phpClose . '"></i>' . "\n"
    . "\n"
    . '<i class="fa-solid fa-star" aria-label="Canonical version"></i>';
if (a11yBareGenericAriaLabel($bareInterpFixture) !== [3]) {
    $selfTestFailures[] = 'a11yBareGenericAriaLabel() mishandled a PHP-interpolated <i> attribute — expected only '
        . 'line 3 flagged, got: ' . implode(',', a11yBareGenericAriaLabel($bareInterpFixture));
}

// M7 — a11yHasSkipLinkAndMainLandmark() must require BOTH halves of the fix,
// not either alone (a skip link with no target, or a <main id> nobody can
// reach by keyboard, are each individually still the bug).
if (a11yHasSkipLinkAndMainLandmark('<a href="#main-content">Skip</a><main id="main-content">') !== true) {
    $selfTestFailures[] = 'a11yHasSkipLinkAndMainLandmark() did not accept a fixture with both the skip link '
        . 'and the id="main-content" landmark present.';
}
if (a11yHasSkipLinkAndMainLandmark('<a href="#main-content">Skip</a><main class="admin-main">') !== false) {
    $selfTestFailures[] = 'a11yHasSkipLinkAndMainLandmark() accepted a <main> with no id="main-content" — '
        . 'the skip link would target nothing.';
}
if (a11yHasSkipLinkAndMainLandmark('<main id="main-content">') !== false) {
    $selfTestFailures[] = 'a11yHasSkipLinkAndMainLandmark() accepted a page with the landmark but no skip link.';
}

// M1 — a11yHeadingRoleButtonWithoutTabindex() must catch a heading used as a
// click target with no tabindex (never keyboard-reachable, Bootstrap's
// collapse data-API is click-only), and must NOT flag one that already
// carries tabindex, nor a non-heading element (e.g. the real <a role=
// "button"> pattern this codebase correctly uses elsewhere).
$h2ButtonFixture = '<h2 role="button" data-bs-toggle="collapse">Translations</h2>';
if (a11yHeadingRoleButtonWithoutTabindex($h2ButtonFixture) !== [1]) {
    $selfTestFailures[] = 'a11yHeadingRoleButtonWithoutTabindex() did not flag <h2 role="button"> with no tabindex.';
}
$h2ButtonOkFixture = '<h2 role="button" tabindex="0" data-bs-toggle="collapse">Translations</h2>'
    . '<a role="button" href="#x">Fine</a>';
if (a11yHeadingRoleButtonWithoutTabindex($h2ButtonOkFixture) !== []) {
    $selfTestFailures[] = 'a11yHeadingRoleButtonWithoutTabindex() false-flagged a heading WITH tabindex, or a '
        . 'non-heading <a role="button"> (a real focusable element — correct usage elsewhere in this codebase).';
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

    /* --- Assertion 4 (M1, a11y audit 2026-08-28): a collapsible heading
       never regrows role="button" with no tabindex — the exact shape that
       made song.php's Translations/Related-Songs toggles keyboard-
       unreachable. Runs over the same $targets every other check here
       does (pages/partials/manage) since no legitimate use of this
       pattern exists anywhere in the tree today (a real clickable heading
       uses a real <button> INSIDE the heading, song.php's own fix). */
    foreach (a11yHeadingRoleButtonWithoutTabindex($src) as $line) {
        $failures[] = sprintf(
            '%s:%d — a heading (<h1>-<h6>) carries role="button" with no tabindex, so it is never keyboard-'
            . 'focusable and Bootstrap\'s collapse data-API (click-only) can never be triggered from a keyboard '
            . '(WCAG 2.1.1/4.1.2). Put a real <button> INSIDE the heading instead (song.php\'s Translations/'
            . 'Related-Songs toggles are the reference pattern) rather than adding tabindex here.',
            $rel,
            $line
        );
    }
}

/* ---------------------------------------------------------------------------
 * Assertion 5 (M7, a11y audit 2026-08-28) — the admin skip link + <main
 * id="main-content"> landmark, and its matching </main> close, stay wired
 * into the two shared partials every /manage/*.php page requires. Losing
 * either half here silently removes the skip link (or its target) from
 * ALL ~39+ admin pages at once, which is exactly the shape M7 found and
 * exactly why the fix lives in the shared chrome rather than per-page.
 * ------------------------------------------------------------------------- */
$adminNavPath    = $public . '/manage/includes/admin-nav.php';
$adminFooterPath = $public . '/manage/includes/admin-footer.php';
if (!is_file($adminNavPath) || !is_file($adminFooterPath)) {
    $failures[] = 'manage/includes/admin-nav.php or admin-footer.php not found — cannot check the admin '
        . 'skip-link + <main> landmark (M7).';
} else {
    $adminNavSrc = a11yStripComments((string) file_get_contents($adminNavPath));
    if (!a11yHasSkipLinkAndMainLandmark($adminNavSrc)) {
        $failures[] = 'manage/includes/admin-nav.php no longer emits BOTH the "Skip to main content" link '
            . '(href="#main-content") AND the <main id="main-content"> landmark it targets — this shared '
            . 'partial is the ONE place that fixes the skip link on every /manage/*.php page at once (WCAG '
            . '2.4.1); losing either half here silently removes it everywhere.';
    }
    $adminFooterSrc = a11yStripComments((string) file_get_contents($adminFooterPath));
    if (!str_contains($adminFooterSrc, '</main>')) {
        $failures[] = 'manage/includes/admin-footer.php no longer closes </main> — admin-nav.php opens the '
            . 'landmark, so every page using both partials would be left with an unclosed <main>.';
    }
}

/* ---------------------------------------------------------------------------
 * Assertion 6 (M8, a11y audit 2026-08-28) — no bare <i>/<span> carries
 * aria-label without role="img" (or another naming-capable role) across the
 * PUBLIC surface. Deliberately scoped to includes/pages, includes/partials,
 * js/modules and js/utils (all glob-derived, rule #34) — NOT manage/*.php,
 * which is a separate, larger, tracked sweep (this guard would otherwise
 * fail on pre-existing admin-only instances this pass never touched).
 * ------------------------------------------------------------------------- */
$publicScanTargets = [];
foreach ([
    $public . '/includes/pages',
    $public . '/includes/partials',
    $public . '/js/modules',
    $public . '/js/utils',
] as $dir) {
    if (!is_dir($dir)) { continue; }
    foreach (array_merge(glob($dir . '/*.php') ?: [], glob($dir . '/*.js') ?: []) as $f) {
        $publicScanTargets[] = $f;
    }
}
sort($publicScanTargets);

if (!$publicScanTargets) {
    $failures[] = 'no public-surface .php/.js files found under includes/pages, includes/partials, '
        . 'js/modules or js/utils — the tree moved and this guard\'s glob needs updating.';
}

foreach ($publicScanTargets as $file) {
    $rel = substr($file, strlen($public) + 1);
    $src = a11yStripComments((string) file_get_contents($file));
    foreach (a11yBareGenericAriaLabel($src) as $line) {
        $failures[] = sprintf(
            '%s:%d — a bare <i>/<span> carries aria-label with no role attribute. ARIA 1.2 prohibits naming a '
            . 'generic element this way (screen readers largely ignore it) — add role="img" (the codebase\'s '
            . 'own correct model: song.php\'s verified badge).',
            $rel,
            $line
        );
    }
}

/* ------------------------------------------------------------------------- */
if ($failures) {
    fwrite(STDERR, "FAIL: static WCAG 2.1/2.2 AA checks (#1150/#1151, a11y audit 2026-08-28 M1/M7/M8):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

printf(
    "PASS: %d template(s) scanned under includes/pages, includes/partials and manage/ (ids/alt/heading-"
    . "role-button), %d public-surface file(s) scanned for bare aria-label (M8), admin skip-link + <main> "
    . "landmark wired (M7), home.php's card-layout-handle stays perceivable.\n",
    count($targets),
    count($publicScanTargets)
);
exit(0);
