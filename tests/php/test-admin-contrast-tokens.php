<?php

declare(strict_types=1);

/**
 * iHymns — Admin light-theme contrast token guard (a11y audit A2/A3/A4/A5
 * + A25.3, GitHub #2000, 2026-08-30).
 *
 * ELI5: this file protects two fixes at once, mutation-proof style —
 * one dead-simple ("this class should never come back"), one a bit
 * cleverer ("this class is fine to keep using, but only because a CSS
 * rule elsewhere is quietly making it readable — don't let THAT rule
 * disappear without noticing").
 *
 *   1. `.link-light` is a straight-up misuse on every one of the 19
 *      places this codebase ever used it (Bootstrap's `.link-light` is
 *      near-white text, `#f8f9fa` — on this app's light `--surface-card`
 *      (`#ffffff`) that measures ≈1.07:1, i.e. invisible). The audit
 *      found ZERO legitimate uses, so this class is simply banned under
 *      manage/ from now on (WCAG 1.4.3, a11y audit A2).
 *
 *   2. `.text-warning` / `.text-info` are DIFFERENT: ~32 manage/*.php
 *      files use them correctly and keep using them after this pass —
 *      the fix is a single light-theme CSS override in css/app.css
 *      (`[data-bs-theme="light"] .text-warning { color: var(--bs-
 *      warning-text-emphasis) }` and its `.text-info` twin) that makes
 *      EVERY existing and future bare usage readable at once, rather
 *      than editing 32 files. That means the bare CLASS is safe to keep
 *      writing — but only as long as that override rule keeps existing.
 *      So this guard does NOT ban the class. Instead it checks: does the
 *      override still exist in css/app.css? If yes, every bare usage
 *      under manage/ is legitimately covered and the guard stays quiet.
 *      If the override rule is ever deleted (accidental CSS cleanup,
 *      merge conflict, …), THIS is the guard that notices — it falls
 *      back to flagging every bare `.text-warning`/`.text-info` class
 *      usage under manage/ as now-unsafe, because at that point they
 *      really would be (WCAG 1.4.3, a11y audit A3/A4/A25.3).
 *
 *   The same "does the override still exist?" check also covers the two
 *   sibling button fixes from the same pass — `.btn-outline-warning` /
 *   `.btn-outline-info` (a11y audit A5, GitHub #2000) and `.btn-amber`
 *   (GitHub #2000) — as a direct existence assertion (there's no bare-
 *   class fallback scan for these two: Bootstrap's own `--bs-btn-color`/
 *   `--bs-btn-border-color` CSS-variable API means the fix lives INSIDE
 *   the existing `.btn-outline-warning`/`.btn-outline-info` rule, not as
 *   a class an author could "forget" to add).
 *
 * Deliberately narrow (rule #34's own warning): `text-warning-emphasis`,
 * `text-info-emphasis` etc. are the CORRECT Bootstrap classes and must
 * NEVER be flagged — the word-boundary matching below is proven not to
 * catch them by a fixture below, not just assumed.
 *
 * No DB, no network — a source-tree scan, same shape as
 * test-a11y-static-checks.php / test-fragment-inline-scripts.php.
 *
 * Usage: php tests/php/test-admin-contrast-tokens.php
 * Exit 0 = pass, 1 = fail.
 */

/**
 * Strip HTML/PHP comments so prose that MENTIONS a class name (like this
 * very doc-block) is never mistaken for the real thing. Newlines are kept
 * so line-numbered diagnostics stay truthful. Same trap + same fix as
 * test-a11y-static-checks.php's a11yStripComments() — see its doc-block.
 */
function contrastStripComments(string $src): string
{
    $src = preg_replace_callback(
        '~<!--.*?-->~s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    ) ?? $src;
    $src = preg_replace_callback(
        '~/\*.*?\*/~s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    ) ?? $src;
    return $src;
}

/**
 * Neutralise `<?php … ?>` / `<?= … ?>` blocks to same-count newlines so an
 * interpolated attribute value (e.g. `class="<?= $x ?> text-warning"`)
 * can never truncate a `class="…"` match early — the exact trap
 * test-a11y-static-checks.php documents at length for its own scanners.
 */
function contrastNeutralisePhp(string $src): string
{
    return preg_replace_callback('~<\?(?:php|=)?[\s\S]*?\?>~', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $src) ?? $src;
}

/**
 * @return int[] 1-based line numbers of every `class="…"` attribute that
 * carries the literal `link-light` token (word-bounded within the
 * attribute VALUE, so a hypothetical `link-light-ish` class — none exist
 * today — would not false-match, mirroring a11yIsBiIconClass()'s token
 * boundary approach in test-a11y-static-checks.php).
 */
function contrastFindLinkLightUsage(string $src): array
{
    $lines = [];
    $neutral = contrastNeutralisePhp($src);
    if (preg_match_all('~class\s*=\s*"([^"]*)"~i', $neutral, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[1] as [$classVal, $offset]) {
            if (preg_match('~(?:^|\s)link-light(?:$|\s)~', $classVal) === 1) {
                $lines[] = substr_count(substr($neutral, 0, $offset), "\n") + 1;
            }
        }
    }
    return $lines;
}

/**
 * @return int[] 1-based line numbers of every `class="…"` attribute that
 * carries a BARE `text-warning` or `text-info` token — word-bounded so
 * `text-warning-emphasis` / `text-info-emphasis` (the correct Bootstrap
 * classes, used elsewhere in this same tree) are never matched. This is
 * ONLY ever called when contrastLightOverrideSelectorsPresent() has
 * already reported the covering CSS rule missing — see the doc-block
 * above for why a present override makes bare usage legitimate.
 */
function contrastFindBareWarningInfoTextClass(string $src): array
{
    $lines = [];
    $neutral = contrastNeutralisePhp($src);
    if (preg_match_all('~class\s*=\s*"([^"]*)"~i', $neutral, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[1] as [$classVal, $offset]) {
            if (preg_match('~(?:^|\s)text-(?:warning|info)(?:$|\s)~', $classVal) === 1) {
                $lines[] = substr_count(substr($neutral, 0, $offset), "\n") + 1;
            }
        }
    }
    return $lines;
}

/**
 * @return array<string,bool> one entry per light-theme override this pass
 * added to css/app.css — true when BOTH the selector text AND its
 * meaningful property/value are present (not just the selector alone,
 * which could exist with its body emptied by an incomplete edit). CSS
 * comments are stripped first so a doc-block that merely MENTIONS one of
 * these selectors (this test file's own sibling explanatory comment in
 * app.css does exactly that) can never satisfy the check on its own —
 * the check requires the selector immediately followed by a `{ … }` body
 * containing the expected property.
 */
function contrastLightOverrideSelectorsPresent(string $appCssSrc): array
{
    $css = contrastStripComments($appCssSrc);

    // Each pattern: selector (tolerant of quote style/whitespace) followed
    // by a brace body containing the property that actually does the work.
    // [^}]* keeps the match inside ONE rule body, so it can never wander
    // into an unrelated later rule and claim a false pass.
    $checks = [
        'text-warning' =>
            '~\[data-bs-theme=([\'"])light\1\]\s*\.text-warning\s*\{[^}]*--bs-warning-text-emphasis~i',
        'text-info' =>
            '~\[data-bs-theme=([\'"])light\1\]\s*\.text-info\s*\{[^}]*--bs-info-text-emphasis~i',
        'btn-outline-warning' =>
            '~\[data-bs-theme=([\'"])light\1\]\s*\.btn-outline-warning\s*\{[^}]*--bs-warning-text-emphasis~i',
        'btn-outline-info' =>
            '~\[data-bs-theme=([\'"])light\1\]\s*\.btn-outline-info\s*\{[^}]*--bs-info-text-emphasis~i',
        // btn-amber's fix must ALSO exclude high-contrast mode (which
        // carries data-bs-theme="light" too, see app.css's own comment on
        // this rule) — so the selector chain, not just the class, is part
        // of what "present and correct" means here.
        'btn-amber' =>
            '~\[data-bs-theme=([\'"])light\1\]:not\(\[data-ihymns-theme=([\'"])high-contrast\2\]\)\s*\.btn-amber\s*\{[^}]*#4f46e5~i',
    ];

    $out = [];
    foreach ($checks as $name => $pattern) {
        $out[$name] = preg_match($pattern, $css) === 1;
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * SELF-TEST — prove the scanners can actually fail (rule #34) against
 * hand-typed fixtures before trusting them on the real tree.
 * ------------------------------------------------------------------------- */
$selfTestFailures = [];

// link-light: must flag a bare usage, must NOT flag a class that merely
// contains "light" as a different word, and must survive a PHP-echoed
// attribute sitting right next to it (the interpolated-close-tag trap).
$linkLightBad = '<a href="/x" class="link-light">Broken</a>';
if (contrastFindLinkLightUsage($linkLightBad) !== [1]) {
    $selfTestFailures[] = 'contrastFindLinkLightUsage() did not flag a bare class="link-light" — got: '
        . implode(',', contrastFindLinkLightUsage($linkLightBad));
}
$linkLightOk = '<a href="/x" class="link-body-emphasis">Fine</a>' . "\n"
    . '<a href="/y" class="fw-semibold">Also fine</a>';
if (contrastFindLinkLightUsage($linkLightOk) !== []) {
    $selfTestFailures[] = 'contrastFindLinkLightUsage() false-flagged a class that only CONTAINS "light" as '
        . 'part of a different token (link-body-emphasis) — token-boundary matching regressed.';
}
$phpOpen = '<' . '?=';
$phpClose = '?' . '>';
$linkLightInterp = '<a href="' . $phpOpen . ' $u ' . $phpClose . '" class="link-light">Still broken</a>';
if (contrastFindLinkLightUsage($linkLightInterp) === []) {
    $selfTestFailures[] = 'contrastFindLinkLightUsage() missed a real link-light usage sitting next to a '
        . 'PHP-interpolated href — the neutraliser over-stripped the tag.';
}

// bare text-warning/text-info: must flag the bare class, must NOT flag the
// -emphasis variants (the one false-positive rule #34 explicitly warns
// against for this exact guard), and must survive a PHP-echoed suffix.
$bareBad = '<p class="text-warning mb-2">Careful</p>' . "\n"
    . '<span class="badge text-info">Stat</span>';
if (contrastFindBareWarningInfoTextClass($bareBad) !== [1, 2]) {
    $selfTestFailures[] = 'contrastFindBareWarningInfoTextClass() did not flag both bare usages — got: '
        . implode(',', contrastFindBareWarningInfoTextClass($bareBad));
}
$emphasisOk = '<p class="small text-warning-emphasis border-start">Fine</p>' . "\n"
    . '<span class="badge bg-info-subtle text-info-emphasis">Fine too</span>';
if (contrastFindBareWarningInfoTextClass($emphasisOk) !== []) {
    $selfTestFailures[] = 'contrastFindBareWarningInfoTextClass() false-flagged the CORRECT text-warning-'
        . 'emphasis/text-info-emphasis classes — the word-boundary regressed into a substring match.';
}
$bareInterp = '<i class="' . $phpOpen . ' $c ' . $phpClose . '"></i>' . "\n"
    . '<p class="text-warning">Real one, two lines down</p>';
if (contrastFindBareWarningInfoTextClass($bareInterp) !== [2]) {
    $selfTestFailures[] = 'contrastFindBareWarningInfoTextClass() mishandled a PHP-interpolated class= sitting '
        . 'before a real bare text-warning — expected only line 2 flagged, got: '
        . implode(',', contrastFindBareWarningInfoTextClass($bareInterp));
}

// override-selector presence: must accept a real, complete rule; must
// reject the selector alone with an unrelated/empty body (an incomplete
// edit that LOOKS present but does nothing); must reject a doc-comment
// that only MENTIONS the selector in prose (comments are stripped first).
$overridePresentFixture = <<<'CSS'
[data-bs-theme="light"] .text-warning {
    color: var(--bs-warning-text-emphasis, #664d03);
}
[data-bs-theme="light"] .text-info {
    color: var(--bs-info-text-emphasis, #055160);
}
[data-bs-theme="light"] .btn-outline-warning {
    --bs-btn-color: var(--bs-warning-text-emphasis, #664d03);
}
[data-bs-theme="light"] .btn-outline-info {
    --bs-btn-color: var(--bs-info-text-emphasis, #055160);
}
[data-bs-theme="light"]:not([data-ihymns-theme="high-contrast"]) .btn-amber {
    color: #4f46e5;
}
CSS;
$presentResult = contrastLightOverrideSelectorsPresent($overridePresentFixture);
if (in_array(false, $presentResult, true)) {
    $selfTestFailures[] = 'contrastLightOverrideSelectorsPresent() false-negatived on a fixture that has every '
        . 'override correctly present — got: ' . json_encode($presentResult);
}
$overrideEmptiedFixture = '[data-bs-theme="light"] .text-warning { }'; // selector present, body gutted
$emptiedResult = contrastLightOverrideSelectorsPresent($overrideEmptiedFixture);
if ($emptiedResult['text-warning'] !== false) {
    $selfTestFailures[] = 'contrastLightOverrideSelectorsPresent() accepted a .text-warning rule whose body was '
        . 'emptied of the actual colour property — selector-only presence is not enough to trust.';
}
$overrideCommentOnlyFixture = '/* see [data-bs-theme="light"] .text-warning { color: var(--bs-warning-text-emphasis) } '
    . 'for the pattern used elsewhere */';
$commentOnlyResult = contrastLightOverrideSelectorsPresent($overrideCommentOnlyFixture);
if ($commentOnlyResult['text-warning'] !== false) {
    $selfTestFailures[] = 'contrastLightOverrideSelectorsPresent() was fooled by a CODE COMMENT that merely '
        . 'mentions the selector in prose — comment-stripping regressed.';
}
$overrideHighContrastLeakFixture = '[data-bs-theme="light"] .btn-amber { color: #4f46e5; }'; // missing the :not() guard
$leakResult = contrastLightOverrideSelectorsPresent($overrideHighContrastLeakFixture);
if ($leakResult['btn-amber'] !== false) {
    $selfTestFailures[] = 'contrastLightOverrideSelectorsPresent() accepted a .btn-amber override with NO '
        . ':not([data-ihymns-theme="high-contrast"]) guard — that shape would stomp high-contrast\'s own '
        . '#0000cc accent with this indigo, exactly the regression this guard exists to catch.';
}

if ($selfTestFailures) {
    fwrite(STDERR, "FAIL: test-admin-contrast-tokens.php self-test (the scanners themselves are broken):\n\n");
    foreach ($selfTestFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

/* ---------------------------------------------------------------------------
 * LIVE MUTATION PROOF (rule #34) — prove the guard can catch a regression
 * in the REAL files, not just hand-typed fixtures. Every mutation below
 * happens to an IN-MEMORY STRING ONLY (php file_get_contents()'d into a
 * variable) — nothing is ever written back to disk, mirroring the
 * established pattern in test-a11y-static-checks.php's own "LIVE
 * MUTATION PROOF" section.
 * ------------------------------------------------------------------------- */
$root       = dirname(__DIR__, 2);
$public     = $root . '/appWeb/public_html';
$appCssPath = $public . '/css/app.css';

if (!is_file($appCssPath)) {
    $selfTestFailures[] = 'live-mutation proof: css/app.css not found.';
} else {
    $appCssSrc = (string) file_get_contents($appCssPath);
    $realPresence = contrastLightOverrideSelectorsPresent($appCssSrc);
    if (in_array(false, $realPresence, true)) {
        $missing = array_keys(array_filter($realPresence, static fn(bool $ok): bool => !$ok));
        $selfTestFailures[] = 'live-mutation proof: css/app.css AS-IS is missing the override(s): '
            . implode(', ', $missing) . ' — either the fix regressed or this test\'s pattern needs updating '
            . 'to match a legitimate reformat.';
    }

    // Mutate a COPY of the real text-warning rule out of the string (never
    // written back) and confirm the checker goes red for exactly that key.
    $mutatedCss = preg_replace(
        '~\[data-bs-theme="light"\]\s*\.text-warning\s*\{[^}]*\}~s',
        '/* removed for mutation proof */',
        $appCssSrc,
        1
    );
    if ($mutatedCss === $appCssSrc) {
        $selfTestFailures[] = 'live-mutation proof: the in-memory removal of the .text-warning override rule '
            . 'did not change app.css\'s content — the anchor pattern no longer matches the real file, so this '
            . 'proof cannot run.';
    } else {
        $mutatedPresence = contrastLightOverrideSelectorsPresent((string) $mutatedCss);
        if ($mutatedPresence['text-warning'] !== false) {
            $selfTestFailures[] = 'live-mutation proof: deleting the REAL .text-warning override rule (in '
                . 'memory only) did NOT make contrastLightOverrideSelectorsPresent() report it missing — this '
                . 'guard cannot be trusted to notice the rule disappearing from the real file.';
        }
        // ...and every OTHER override must still read as present — the
        // mutation must be scoped to exactly one rule, not swallow its
        // neighbours (a regex that over-matched would hide a real gap
        // behind an unrelated one). Checked on a COPY of the array (never
        // mutating $mutatedPresence itself) since the text-warning entry
        // is still needed below.
        $mutatedPresenceOthersOnly = $mutatedPresence;
        unset($mutatedPresenceOthersOnly['text-warning']);
        if (in_array(false, $mutatedPresenceOthersOnly, true)) {
            $selfTestFailures[] = 'live-mutation proof: removing ONLY the .text-warning rule also knocked out '
                . 'another override in the mutated copy — the removal pattern is too greedy.';
        }

        // The knock-on effect: with the override gone, the REAL SCAN below
        // gates on `$overrideMissing` and would flag every genuine bare
        // .text-warning/.text-info usage under manage/ as newly-unsafe.
        // manage/works.php:1439 is the audit's own cited real instance
        // (still bare `.text-warning` today, by design — the fix is the
        // CSS override, not a per-file sweep) — prove the SCANNER finds
        // it on real content (not just the hand-typed fixture above),
        // and separately confirm the gate variable itself flips the way
        // the real scan below relies on it (present → false, mutated →
        // true) — two independent facts, not one conflated assertion.
        $worksPath = $public . '/manage/works.php';
        if (!is_file($worksPath)) {
            $selfTestFailures[] = 'live-mutation proof: manage/works.php not found.';
        } else {
            $worksSrc = (string) file_get_contents($worksPath);
            if (!preg_match('~class="text-warning\b~', $worksSrc)) {
                $selfTestFailures[] = 'live-mutation proof: manage/works.php no longer has a bare class="text-'
                    . 'warning…" usage — the file changed shape and this proof needs a new anchor.';
            } elseif (contrastFindBareWarningInfoTextClass($worksSrc) === []) {
                $selfTestFailures[] = 'contrastFindBareWarningInfoTextClass() did NOT find manage/works.php\'s '
                    . 'known real bare class="text-warning" usage — the scanner cannot be trusted against real '
                    . '(non-fixture) content.';
            }
        }
        // The gate variable itself: present → not missing; mutated → missing.
        // (This is exactly what the REAL SCAN section below computes as
        // `$overrideMissing` and wraps the bare-usage flagging in.)
        if (in_array(false, $realPresence, true)) {
            $selfTestFailures[] = 'live-mutation proof: the real (unmutated) override presence already reads '
                . 'as missing something — the "present → bare usage is safe" gate could never engage as designed.';
        }
        if ($mutatedPresence['text-warning'] !== false) {
            $selfTestFailures[] = 'live-mutation proof: the mutated override presence does not read text-warning '
                . 'as missing — the "override gone → bare usage becomes unsafe" gate could never engage.';
        }
    }
}

if ($selfTestFailures) {
    fwrite(STDERR, "FAIL: test-admin-contrast-tokens.php live-mutation proof (guard cannot be trusted against the real tree):\n\n");
    foreach ($selfTestFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

/* ---------------------------------------------------------------------------
 * REAL SCAN — derived from the tree, not a hand-typed file list (rule #34).
 * ------------------------------------------------------------------------- */
$manageDir = $public . '/manage';
if (!is_dir($manageDir)) {
    fwrite(STDERR, "FAIL: appWeb/public_html/manage not found — the tree moved and this guard needs updating.\n");
    exit(1);
}

/** @return string[] every *.php file under $dir, any depth (tree-derived — no hand-typed subdirectory list) */
function contrastFindPhpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

$manageFiles = contrastFindPhpFiles($manageDir);
if (!$manageFiles) {
    fwrite(STDERR, "FAIL: no .php files found anywhere under manage/ — the tree moved and this guard needs updating.\n");
    exit(1);
}

$failures = [];

/* --- Assertion (b): the light-theme override rules exist in css/app.css --- */
$appCssSrcNow = (string) file_get_contents($appCssPath);
$presenceNow  = contrastLightOverrideSelectorsPresent($appCssSrcNow);
foreach ($presenceNow as $name => $present) {
    if (!$present) {
        $failures[] = sprintf(
            'css/app.css: the light-theme contrast override for "%s" is missing (a11y audit A3/A4/A5, #2000). '
            . 'Without it, every bare usage of this class under manage/ (and on the public settings/home pages) '
            . 'goes back to failing WCAG 1.4.3 in light theme.',
            $name
        );
    }
}
$overrideMissing = in_array(false, $presenceNow, true);

/* --- Assertion (a1): .link-light is banned outright under manage/ (zero
   legitimate uses per the audit — this is a straight regression tripwire,
   not conditional on anything). --- */
foreach ($manageFiles as $file) {
    $rel = substr($file, strlen($public) + 1);
    $src = contrastStripComments((string) file_get_contents($file));
    foreach (contrastFindLinkLightUsage($src) as $line) {
        $failures[] = sprintf(
            '%s:%d — class="link-light" (a11y audit A2). Bootstrap\'s .link-light (#f8f9fa) measures ≈1.07:1 '
            . 'on this app\'s light --surface-card (#fff) — invisible in Light/System-light. The audit found '
            . 'ZERO legitimate uses; remove the class (admin links are themed by css/admin.css\'s default '
            . 'a:not(.btn)… rule already) or use link-body-emphasis.',
            $rel,
            $line
        );
    }

    /* --- Assertion (a2): bare .text-warning/.text-info ONLY becomes a
       failure if the covering override is missing — see this file's own
       doc-block for why a PRESENT override makes the bare class safe. --- */
    if ($overrideMissing) {
        foreach (contrastFindBareWarningInfoTextClass($src) as $line) {
            $failures[] = sprintf(
                '%s:%d — bare .text-warning/.text-info class, and the covering css/app.css light-theme override '
                . '(a11y audit A3/A4) is currently MISSING (see the css/app.css failure above) — this usage is '
                . 'now genuinely unreadable in light theme (≈1.6:1). Restore the override rather than editing '
                . 'every usage.',
                $rel,
                $line
            );
        }
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: admin light-theme contrast token guard (a11y audit A2/A3/A4/A5, GitHub #2000):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

printf(
    "PASS: %d .php file(s) scanned under manage/ for link-light (banned) and bare text-warning/text-info "
    . "(override-gated); all 5 light-theme contrast overrides present in css/app.css.\n",
    count($manageFiles)
);
exit(0);
