<?php

declare(strict_types=1);

/**
 * iHymns — Per-channel search-engine visibility guard (#2024/#2025)
 * ============================================================================
 *
 * ELI5
 * ----
 * This whole feature is a promise: "when an admin switches a channel off,
 * that channel disappears from search engines — safely, and without any two
 * pieces of the app disagreeing about what 'off' means." This guard makes
 * that promise mechanical instead of hoped-for: it runs the real parsing
 * logic against a truth table (including the exact locked defaults the
 * owner chose — production listed, beta/alpha hidden), proves the setting's
 * key is spelled in exactly one place, walks the REAL list of top-level
 * public endpoints on disk and demands each one make an explicit, reviewed
 * choice (wired for noindex, gated for a 404, or excluded with a written
 * reason), checks the SPA shell emits the matching `<meta>` tag, and checks
 * `robots.txt.php`'s own content contract — including an ACTIVE ban on the
 * one thing that would quietly undermine the entire feature: a bare
 * `Disallow: /` line, which would stop crawlers ever seeing the noindex
 * signal this feature depends on (see includes/search_visibility.php's own
 * doc-block for the full "why staying crawlable matters" explanation).
 *
 * WHAT THIS FILE ASSERTS (tree-derived + mutation-proven, rule #34)
 * ----------------------------------------------------------------------------
 *  1. EXECUTABLE TRUTH TABLE — includes/environment.php's
 *     `ihymns_parse_channels_csv()` and includes/search_visibility.php's
 *     `searchVisibilityAllows()` are REQUIRED and CALLED for real (both are
 *     side-effect-free at require time — see their own doc-blocks), against
 *     a real truth table that includes the LOCKED OWNER DEFAULTS: with
 *     `SEARCH_VISIBILITY_DEFAULT_CSV`, production is visible and beta/alpha
 *     are hidden. `SEARCH_VISIBILITY_DEFAULT_CSV` itself is asserted to
 *     equal `'production'` — changing that default is a decision the owner
 *     made explicitly, not something a stray edit should be able to do
 *     quietly.
 *  2. SINGLE SOURCE OF TRUTH FOR THE KEY — the tree under `appWeb/` (every
 *     `*.php` file, comment-stripped) is scanned for the quoted literal
 *     `'search_visibility_channels'`; it must appear in EXACTLY ONE file,
 *     `includes/search_visibility.php`. Every other consumer must instead
 *     use the `SEARCH_VISIBILITY_SETTING_KEY` constant (rule #35 — the
 *     setting's read and write sides can never spell the key differently
 *     and drift apart).
 *  3. ENDPOINT COVERAGE, TREE-DERIVED — every top-level `appWeb/public_html/
 *     *.php` file must be classified in EXACTLY ONE of three tree-derived
 *     buckets: `$WIRED` (must contain
 *     `searchVisibilityEmitNoindexHeader(`), `$GATED` (must contain
 *     `searchEngineVisibleHere(`), or `$EXCLUDED` (a non-empty, plain-
 *     English reason). A future endpoint that is neither wired nor
 *     excluded-with-a-reason fails THIS build (the
 *     `test-sitemap-coverage.php` PASS-1 mould applied to this feature).
 *  4. THE SHELL'S META TAG — `index.php`'s (comment-stripped) source
 *     contains the `<meta name="robots"` emission AND a reference to the
 *     hidden-flag variable that feeds it, so the header and the meta tag
 *     can never silently diverge.
 *  5. ROBOTS.TXT.PHP'S CONTENT CONTRACT — calls the shared
 *     `appCanonicalHost(` and holds no re-forked host literal from the
 *     four-channel allow-list (the sitemap guard's PASS-3 shape, reused);
 *     contains the advertised `/sitemap.xml` line; preserves the existing
 *     `Disallow: /api` / `Disallow: /bridge.html` directives; ACTIVELY BANS
 *     a bare `Disallow: /` from appearing anywhere in the file (the one
 *     mutation that would defeat the whole feature's SEO premise — see the
 *     ELI5 above); and carries the never-5xx `catch (\Throwable` marker.
 *  6. ROUTING LOCKSTEP — `.htaccess` has the `^robots\.txt$` rewrite,
 *     positioned BEFORE the static-file passthrough (so a stale deployed
 *     static file can never win); the static `appWeb/public_html/
 *     robots.txt` no longer exists (two sources of truth for one URL would
 *     only ever drift); and `tests/browser/router.php` mirrors the same
 *     `/robots.txt` → `/robots.txt.php` mapping (rule #35 — the dev server
 *     and `.htaccess` must agree).
 *
 * WHY THE TRUTH TABLE CALLS REAL FUNCTIONS, NOT JUST REGEXES THE SOURCE
 * ----------------------------------------------------------------------------
 * `includes/environment.php` and `includes/search_visibility.php` are both
 * function/const-definitions-only files with NO top-level side effects (see
 * their own doc-blocks) — exactly the shape `includes/sitemap_helpers.php`
 * established so a CI guard can `require` them directly and call the pure
 * functions for real, rather than pattern-matching source text and hoping
 * it behaves as it reads. Every other assertion here is static,
 * comment-stripped source-text analysis (the `scStrip()` below is a direct
 * copy of `test-sitemap-coverage.php`'s own — the established per-guard
 * pattern; each guard keeps its own copy rather than sharing state).
 *
 * MUTATION-PROOFING (run during development; the exact procedure + results
 * are recorded in the tracking issue this guard shipped with, and in this
 * PR's commit history):
 *   1. Set SEARCH_VISIBILITY_DEFAULT_CSV to ''      → PASS 1 (locked default) goes RED
 *   2. Make searchVisibilityAllows() ignore its
 *      $channel argument (always return true)        → PASS 1 (truth table) goes RED
 *   3. Retype the raw key string into configuration.php
 *      instead of the constant                        → PASS 2 goes RED
 *   4. Remove the helper call from song-media.php      → PASS 3 goes RED, naming song-media.php
 *   5. `touch appWeb/public_html/zzz-probe.php`        → PASS 3 goes RED (unclassified)
 *   6. Delete the <meta name="robots"> emission
 *      from index.php                                  → PASS 4 goes RED
 *   7. Add "Disallow: /\n" to robots.txt.php's body     → PASS 5 goes RED (the active ban)
 *   8. Replace appCanonicalHost() with a hardcoded
 *      host literal in robots.txt.php                   → PASS 5 goes RED
 *   9. Remove the .htaccess robots.txt rewrite          → PASS 6 goes RED
 *  10. `git checkout` the static robots.txt back in     → PASS 6 goes RED
 * Every mutation was performed once against the real tree, confirmed RED
 * (naming the right thing), then reverted byte-identical; the guard was
 * also confirmed GREEN on the correct code both before and after (rule #34's
 * "under-reporting is worse than no scanner, and a guard that fails on
 * correct code gets deleted" — both directions checked). Check 5's own
 * non-trip on `Disallow: /api` was verified deliberately (the regex must
 * ban a BARE `Disallow: /` only, never a real, legitimate carve-out that
 * happens to start with the same six characters).
 *
 *   php tests/php/test-search-visibility.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch.
 *
 * @see appWeb/public_html/includes/environment.php        ihymns_parse_channels_csv()
 * @see appWeb/public_html/includes/search_visibility.php  the setting/gate/header core under test
 * @see appWeb/public_html/sitemap.xml.php                  the sitemap's own gate (guarded separately, test-sitemap-coverage.php PASS 7)
 * @see appWeb/public_html/robots.txt.php                   the dynamic, per-channel robots.txt
 * @see appWeb/public_html/manage/configuration.php          the admin card + save_search_visibility handler
 * @see tests/php/test-sitemap-coverage.php                  the sibling guard this one's shape mirrors
 * @link .claude/CLAUDE.md rule #34/#35   tree-derived + mutation-proven guards; one mechanism, not a comment
 */

$repo             = dirname(__DIR__, 2);
$webRoot          = $repo . '/appWeb/public_html';
$environmentFile  = $webRoot . '/includes/environment.php';
$visibilityFile   = $webRoot . '/includes/search_visibility.php';
$sitemapFile      = $webRoot . '/sitemap.xml.php';
$robotsFile       = $webRoot . '/robots.txt.php';
$indexFile        = $webRoot . '/index.php';
$configFile       = $webRoot . '/manage/configuration.php';
$htaccessFile     = $webRoot . '/.htaccess';
$staticRobotsFile = $webRoot . '/robots.txt';
$routerFile       = $repo . '/tests/browser/router.php';

$passed = 0;
$failed = 0;

function sv(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

/** Blank out comments, preserving offsets — a direct copy of
 *  tests/php/test-sitemap-coverage.php's own `scStrip()` (each guard keeps
 *  its own copy rather than sharing state, the established per-guard
 *  pattern in this test suite). */
function svStrip(string $php): string
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

foreach ([$environmentFile, $visibilityFile, $sitemapFile, $robotsFile, $indexFile,
          $configFile, $htaccessFile, $routerFile] as $f) {
    if (!file_exists($f)) {
        fwrite(STDERR, "FATAL: missing {$f} — cannot derive the search-visibility contract.\n");
        exit(1);
    }
}

/* =========================================================================
 * PASS 1 — EXECUTABLE TRUTH TABLE: require the two pure, side-effect-free
 * files directly and call their real functions, including the LOCKED
 * OWNER DEFAULTS.
 * ========================================================================= */

require_once $environmentFile; // ihymns_parse_channels_csv()
require_once $visibilityFile;  // SEARCH_VISIBILITY_SETTING_KEY / _DEFAULT_CSV, searchVisibilityAllows()

sv('1.0 SEARCH_VISIBILITY_DEFAULT_CSV === \'production\' (the locked owner default — changing it is a decision, not a stray edit)',
    SEARCH_VISIBILITY_DEFAULT_CSV === 'production');

$parseTable = [
    // [raw CSV, expected parsed list]
    [null, []],
    ['', []],
    ['production', ['production']],
    ['Alpha, BETA  production', ['alpha', 'beta', 'production']],
    ['none', []],           // not a real channel token — parses to empty, never an error
    ['prod,junk', []],      // 'prod' is not 'production' — no partial/fuzzy match
    ['alpha,alpha,alpha', ['alpha']], // duplicates deduped
];
foreach ($parseTable as [$raw, $expect]) {
    $got = ihymns_parse_channels_csv($raw);
    sort($got);
    $expectSorted = $expect;
    sort($expectSorted);
    sv('1.1 ihymns_parse_channels_csv(' . var_export($raw, true) . ') === ' . var_export($expect, true),
        $got === $expectSorted);
}

/* The locked-defaults table: what the owner actually decided, exercised
   against the REAL default constant, not a copy of its value. */
$allowsTable = [
    // [csv, channel, expect]
    [SEARCH_VISIBILITY_DEFAULT_CSV, 'production', true],   // fresh install: production IS listed
    [SEARCH_VISIBILITY_DEFAULT_CSV, 'beta',       false],  // fresh install: beta is HIDDEN
    [SEARCH_VISIBILITY_DEFAULT_CSV, 'alpha',      false],  // fresh install: alpha (dev) is HIDDEN
    ['none',             'production', false],  // the "all hidden" sentinel actually hides production
    ['none',             'beta',       false],
    ['none',             'alpha',      false],
    ['production,beta',  'beta',       true],
    ['production,beta',  'alpha',      false],
    ['',                 'alpha',      false],
];
foreach ($allowsTable as [$csv, $channel, $expect]) {
    sv("1.2 searchVisibilityAllows(" . var_export($csv, true) . ", '{$channel}') === " . var_export($expect, true),
        searchVisibilityAllows($csv, $channel) === $expect);
}

/* =========================================================================
 * PASS 2 — SINGLE SOURCE OF TRUTH FOR THE KEY: the quoted setting-key
 * literal appears in exactly one file across the whole appWeb/ tree.
 * ========================================================================= */

function svRecursivePhpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}

$appWebDir = dirname($webRoot); // appWeb/ (covers both public_html/ and .sql/, matching the plan's "appWeb/**/*.php" scope)
$phpFiles  = svRecursivePhpFiles($appWebDir);
sv('2.0 the appWeb/ tree walk found at least one hundred *.php files (the derivation works at all)', count($phpFiles) > 100);

$keyLiteralFiles = [];
foreach ($phpFiles as $f) {
    $stripped = svStrip((string)file_get_contents($f));
    if (strpos($stripped, "'search_visibility_channels'") !== false) {
        $keyLiteralFiles[] = $f;
    }
}
$relKeyLiteralFiles = array_map(static fn(string $f): string => substr($f, strlen($appWebDir) + 1), $keyLiteralFiles);
sv('2.1 the quoted literal \'search_visibility_channels\' appears in EXACTLY ONE file across appWeb/ — found in: [' . implode(', ', $relKeyLiteralFiles) . ']',
    $keyLiteralFiles === [$visibilityFile]);

$configStripped = svStrip((string)file_get_contents($configFile));
sv('2.2 manage/configuration.php uses the SEARCH_VISIBILITY_SETTING_KEY constant (never a re-typed literal)',
    strpos($configStripped, 'SEARCH_VISIBILITY_SETTING_KEY') !== false);
sv('2.3 manage/configuration.php\'s posted-field name is NOT the settings-key literal itself (the webhooks_channels[] vs webhooks_enabled_channels precedent — a distinct field name is what keeps PASS 2.1 meaningful)',
    strpos($configStripped, "'search_engine_channels'") !== false);

/* =========================================================================
 * PASS 3 — ENDPOINT COVERAGE, TREE-DERIVED: every top-level appWeb/
 * public_html/*.php file is classified in exactly one bucket.
 * ========================================================================= */

/** basename => n/a — must contain searchVisibilityEmitNoindexHeader( */
$WIRED = [
    'index.php', 'api.php', 'og-image.php', 'qr.php', 'org-logo.php',
    'song-media.php', 'audio-media.php',
];
/** basename => n/a — must contain searchEngineVisibleHere( (the 404 gate) */
$GATED = ['sitemap.xml.php', 'robots.txt.php'];
/** basename => plain-English reason it is deliberately neither */
$EXCLUDED = [
    'error.php'                     => 'includes/error_page.php already emits <meta name="robots" content="noindex"> unconditionally on every error page, every channel — already stronger than this feature needs.',
    'opcache-bust.php'              => 'Internal deploy utility — already sends an unconditional X-Robots-Tag: noindex of its own.',
    'webhook-drain.php'             => 'Key-authed machine endpoint, never linked from any indexable document; an unauthenticated fetch gets an auth error, not content.',
    'language-registry-refresh.php' => 'Key-authed machine endpoint, never linked from any indexable document; an unauthenticated fetch gets an auth error, not content.',
    'service-worker.js.php'         => 'Fetched programmatically by the browser\'s service-worker machinery, not an indexable document — and deliberately DB-free today; wiring it would add a database dependency to a hot, light endpoint for zero SEO gain.',
];

$rootPhpFiles = [];
foreach (glob($webRoot . '/*.php') ?: [] as $file) {
    $rootPhpFiles[] = basename($file);
}
sort($rootPhpFiles);
sv('3.0 the top-level appWeb/public_html/*.php glob found at least one file (the derivation works at all)', count($rootPhpFiles) > 0);

foreach ($rootPhpFiles as $base) {
    $inWired    = in_array($base, $WIRED, true);
    $inGated    = in_array($base, $GATED, true);
    $inExcluded = array_key_exists($base, $EXCLUDED);
    $bucketCount = ($inWired ? 1 : 0) + ($inGated ? 1 : 0) + ($inExcluded ? 1 : 0);
    sv("3.1 '{$base}' is classified in exactly one of \$WIRED / \$GATED / \$EXCLUDED", $bucketCount === 1);

    if ($inWired || $inGated) {
        $needle   = $inWired ? 'searchVisibilityEmitNoindexHeader(' : 'searchEngineVisibleHere(';
        $stripped = svStrip((string)file_get_contents($webRoot . '/' . $base));
        sv("3.2 '{$base}' (" . ($inWired ? 'WIRED' : 'GATED') . ") source contains {$needle}",
            strpos($stripped, $needle) !== false);
    }
    if ($inExcluded) {
        sv("3.3 '{$base}': the EXCLUDED reason is a real, non-empty sentence", trim($EXCLUDED[$base]) !== '');
    }
}

/* Stale-entry direction: every map key must still be a real top-level file. */
foreach (array_merge($WIRED, $GATED) as $name) {
    sv("3.4 WIRED/GATED entry '{$name}' names a top-level file that still exists", in_array($name, $rootPhpFiles, true));
}
foreach (array_keys($EXCLUDED) as $name) {
    sv("3.5 EXCLUDED['{$name}'] names a top-level file that still exists", in_array($name, $rootPhpFiles, true));
}

/* =========================================================================
 * PASS 4 — THE SHELL'S META TAG: index.php emits <meta name="robots"> fed
 * by the same flag the header uses.
 * ========================================================================= */

$indexStripped = svStrip((string)file_get_contents($indexFile));
sv('4.1 index.php\'s source contains the <meta name="robots" emission', strpos($indexStripped, '<meta name="robots"') !== false);
sv('4.2 index.php references the $searchEngineHidden flag (the SAME flag the X-Robots-Tag header call sits beside)',
    strpos($indexStripped, '$searchEngineHidden') !== false);
sv('4.3 index.php calls searchVisibilityEmitNoindexHeader( (the header half of the same pair)',
    strpos($indexStripped, 'searchVisibilityEmitNoindexHeader(') !== false);

/* =========================================================================
 * PASS 5 — ROBOTS.TXT.PHP'S CONTENT CONTRACT.
 * ========================================================================= */

$robotsStripped = svStrip((string)file_get_contents($robotsFile));

sv('5.1 robots.txt.php calls the shared appCanonicalHost(', strpos($robotsStripped, 'appCanonicalHost(') !== false);
foreach (['ihymns.app', 'www.ihymns.app', 'dev.ihymns.app', 'beta.ihymns.app'] as $hostLiteral) {
    sv("5.2 robots.txt.php holds no quoted host literal '{$hostLiteral}' (would be a re-forked allow-list)",
        strpos($robotsStripped, "'{$hostLiteral}'") === false);
}
sv('5.3 robots.txt.php\'s source contains the literal "/sitemap.xml" (the advertised line exists on the visible branch)',
    strpos($robotsStripped, '/sitemap.xml') !== false);
sv('5.4 robots.txt.php preserves "Disallow: /api"', strpos($robotsStripped, 'Disallow: /api') !== false);
sv('5.5 robots.txt.php preserves "Disallow: /bridge.html"', strpos($robotsStripped, 'Disallow: /bridge.html') !== false);

/* The active ban: extract every double-quoted string literal from the
   stripped source and check NONE of them, once concatenated, forms a bare
   "Disallow: /" line (a real carve-out like "Disallow: /api\n" must NOT
   trip this — verified in the mutation-proofing log as its own explicit
   non-trip check, rule #34's "narrow enough not to fail on correct code"). */
preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $robotsStripped, $stringLiterals);
$concatenatedStrings = implode('', array_map(
    static fn(string $s): string => stripcslashes($s),
    $stringLiterals[1]
));
sv('5.6 robots.txt.php never emits a bare "Disallow: /" line (would defeat the noindex signal by blocking crawl entirely — see the ELI5 above)',
    !(bool)preg_match('~Disallow:\s*/\s*(\\\\n|$)~m', $concatenatedStrings));
/* Non-trip proof, run for real (not just asserted in a comment): a
   legitimate carve-out string must NOT match the ban pattern above. */
sv('5.6b non-trip proof: "Disallow: /api\\n" does NOT match the bare-Disallow ban pattern (the check is narrow enough for correct code)',
    !(bool)preg_match('~Disallow:\s*/\s*(\\\\n|$)~m', "Disallow: /api\n"));

sv('5.7 robots.txt.php has the never-5xx structural marker (catch (\\Throwable)',
    strpos($robotsStripped, 'catch (\\Throwable') !== false);

/* =========================================================================
 * PASS 6 — ROUTING LOCKSTEP.
 * ========================================================================= */

/** Apache-config comment strip: truncate every line at its first '#' (the
 *  Apache comment-to-end-of-line convention) — a doc-comment ABOVE the new
 *  rule that mentions the same directive text it explains (as this file's
 *  own ROBOTS.TXT ROUTING section does) must not be mistaken for the real
 *  directive when checking ORDER, only presence. None of this file's real
 *  RewriteRule/RewriteCond/Header lines contain a literal '#' (verified),
 *  so this never truncates real directive content. */
function svHtaccessStrip(string $raw): string
{
    $lines = explode("\n", $raw);
    foreach ($lines as &$line) {
        $hashPos = strpos($line, '#');
        if ($hashPos !== false) {
            $line = substr($line, 0, $hashPos);
        }
    }
    unset($line);
    return implode("\n", $lines);
}

$htaccessRaw      = (string)file_get_contents($htaccessFile);
$htaccessStripped = svHtaccessStrip($htaccessRaw);
$posRobotsRule  = strpos($htaccessStripped, '^robots\\.txt$');
$posStaticPassthrough = strpos($htaccessStripped, '%{REQUEST_FILENAME} -f');

sv('6.1 .htaccess has the "^robots\\.txt$" rewrite rule', strpos($htaccessRaw, '^robots\\.txt$') !== false);
sv('6.2 the robots.txt rewrite is positioned BEFORE the static-file passthrough (so a stale deployed static file can never win)',
    $posRobotsRule !== false && $posStaticPassthrough !== false && $posRobotsRule < $posStaticPassthrough);
sv('6.3 the static appWeb/public_html/robots.txt no longer exists (two sources of truth for one URL would only drift)',
    !file_exists($staticRobotsFile));

$routerRaw = (string)file_get_contents($routerFile);
sv('6.4 tests/browser/router.php maps \'/robots.txt\' to \'/robots.txt.php\' (the dev server must agree with .htaccess)',
    (bool)preg_match('~[\'"]\s*/robots\\.txt\s*[\'"]\s*=>\s*[\'"]\s*/robots\\.txt\\.php\s*[\'"]~', $routerRaw));

/* ========================================================================= */

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
