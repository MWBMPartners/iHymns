<?php

declare(strict_types=1);

/**
 * iHymns — manage-tree link-param coverage (silent-wiring sweep, epic #2008)
 * ============================================================================
 *
 * ELI5
 * ----
 * `tests/test-editor-deep-links.js` (#1680/#1628/#1623) already checks that
 * every `?param=` a link to the SONG EDITOR carries is actually read by the
 * editor. This is the same check for every OTHER `/manage/*` page: a link
 * that says "open this with X set" is silently ignored if the destination
 * page never reads `X` — the page loads, nothing errors, and the one thing
 * the link asked for just never happens (rule #33).
 *
 * SCOPE (v1 — the narrow, high-precision core; see "WHAT THIS DOES NOT
 * COVER" below for what is deliberately out)
 * ---------------------------------------------------------------------
 *  1. Self-links inside `manage/*.php` — `href="?p=…"` — destination is the
 *     SAME file.
 *  2. Sibling links — `href="page?p=…"` / `href="/manage/page?p=…"` /
 *     `href="/manage/page.php?p=…"` — destination is `manage/page.php` (or
 *     `manage/page/index.php`).
 *  3. `header('Location: …')` redirects with literal params.
 *  4. `window.location`/`window.open` string literals aimed at
 *     `/manage/…?…` (JS + inline `<script>` bodies).
 * Editor destinations (`manage/editor/**`) are OUT OF SCOPE — already
 * guarded by `tests/test-editor-deep-links.js`; this file never duplicates
 * that check.
 *
 * EXTRACTION DISCIPLINE (copied VERBATIM from test-editor-deep-links.js's
 * own two recorded first-draft bugs — rule #34's "a guard's first green run
 * was never challenged" lesson, already paid for once)
 * ---------------------------------------------------------------------
 *  - `&amp;`-normalise BEFORE splitting params (`&amp;x=1` is `&x=1`).
 *  - Replace `<?…?>`/`${…}` interpolations with a placeholder BEFORE any
 *    bounded character-class match — matching straight through an
 *    interpolation truncates the string at the first `?>` (the exact first
 *    bug test-editor-deep-links.js records).
 *  - Anchor the param regex at `(?:^|&)` on the QUERY PORTION ONLY, never
 *    the whole href — matching against the whole href mis-anchors `^`
 *    against the path, not the query start (the second recorded bug).
 *
 * FALSE-POSITIVE SUPPRESSIONS (all three were REAL, measured findings
 * during the sweep's analysis pass — each is load-bearing, not defensive
 * gold-plating; each has its own mutation test below)
 * ---------------------------------------------------------------------
 *  A. WHOLESALE SUPERGLOBAL PASS-THROUGH — `manage/ccli-report.php`:
 *     `$window = ccliReportWindow($_GET)`. A destination whose closure
 *     passes `($_GET)`/`($_REQUEST)` as a bare function argument delegates
 *     its OWN parsing to a helper this guard cannot see inside of — its
 *     read-set is marked OPEN (every param check against it passes) rather
 *     than judged textually. Precision over recall. ⚠️ Deliberately GET/
 *     REQUEST only, NEVER `$_POST` — every emission this guard checks is a
 *     GET-style link/redirect param, and a bare `($_POST)` argument is an
 *     extremely common, UNRELATED shape ("hand the whole submitted form to
 *     a validator", e.g. `catalogueAdminValidateCreateFields($_POST)` in
 *     `manage/catalogues.php`). Including `$_POST` in this check was the
 *     first draft's bug — it made nearly every page that validates a
 *     posted form read-OPEN and silently swallowed a real mutation-test
 *     miss; see the mutation-proofing notes for how it was caught.
 *  B. PARTIAL-RESOLVES-TO-INCLUDER — `manage/includes/setup-wizard-modal.php`
 *     emits `?reconfigure=1`; the read (`$_GET['reconfigure']`) lives in
 *     `manage/setup-database.php`, which `require`s the partial. A self-link
 *     inside `manage/includes/*.php` is checked against the UNION of every
 *     page that includes it, not the partial file alone.
 *  C. CROSS-TREE REQUIRES — `manage/setup-database.php` reads `?limit=50`
 *     itself for one action, but for `verify-cutover` the real read lives in
 *     `appWeb/.sql/verify-lyrics-cutover.php`, `require`d via a VARIABLE
 *     (`require $vfScript;`, `$vfScript` built from `$scriptDir . '…php'`)
 *     — not a literal `require 'file.php'` a regex can follow directly. The
 *     resolver therefore also does a LOOSE, bounded fallback: any `.php`
 *     BASENAME mentioned as a quoted string anywhere in a file that also
 *     contains the word `require`/`include` is looked up in a whole-tree
 *     basename index and its reads are merged in too. This can only ever
 *     ADD a read (never remove one), so it costs recall on some
 *     hypothetical unrelated file, never produces a false alarm — the same
 *     "looseness only helps this direction" trade `dispatch_parser.php`'s
 *     map-keys extension makes.
 *
 * ⚠️ NEVER `rg`/shell out — plain `RecursiveDirectoryIterator`, same
 * incident history as `test-orphan-inventory.php`'s header (`rg` skips
 * dot-directories by default, which is exactly where suppression C's real
 * instance — `appWeb/.sql/verify-lyrics-cutover.php` — lives).
 *
 * WHAT THIS DOES NOT COVER (stated so its tick is not over-read)
 * ---------------------------------------------------------------------
 *  - The public SPA (router params, `#hash=` contracts, `?page=` fragment
 *    params) — genuinely dynamic and thin on instances (one candidate found
 *    during the sweep's analysis pass, already correctly read); a stated
 *    non-goal rather than a noisy scanner.
 *  - Dead READS (a destination reading a param nothing emits) — deliberately
 *    NOT flagged. Those are aliases by design (rule #33: "links outlive
 *    code"; `?open=` is the worked example).
 *  - A param aimed at the ROUTER/`.htaccess` (a `?legacy=1` class) — v1
 *    avoids this entirely by scoping to `/manage`.
 *
 *   php tests/php/test-manage-link-params.php
 *
 * Exit status 0 = clean, 1 = at least one failure.
 */

$root   = dirname(__DIR__, 2);
$appWeb = $root . '/appWeb';
$pub    = $appWeb . '/public_html';
$manage = $pub . '/manage';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; return; }
    $failed++;
    $failures[] = $label;
    echo "  ❌ {$label}\n";
    if ($detail !== '') {
        foreach (explode("\n", rtrim($detail)) as $line) { echo "       {$line}\n"; }
    }
}

echo "\nManage-tree link-param coverage guard (epic #2008)\n\n";

/* =========================================================================
 * PART 0 — corpus + shared helpers
 * ========================================================================= */

/** @return array<int,string> absolute paths under $dir matching $extRe, never a shell-out. */
function mlpWalk(string $dir, string $extRe): array
{
    $out = [];
    if (!is_dir($dir)) { return $out; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile()) { continue; }
        $path = $f->getPathname();
        if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) { continue; }
        if (preg_match($extRe, $path)) { $out[] = $path; }
    }
    sort($out);
    return $out;
}

/** Comment-strip, NEWLINE-PRESERVING (rule #34 gotcha #1 — a reported line
 * number must stay true; see test-action-caller-coverage.php's own
 * doc-comment for the mutation test that caught this the first time it was
 * gotten wrong in this sweep). */
function mlpStripComments(string $s): string
{
    $s = (string)preg_replace_callback('/<!--.*?-->/s', static fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), $s);
    $s = (string)preg_replace_callback('/\/\*.*?\*\//s', static fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), $s);
    return $s;
}

/** &amp;-normalise + blank interpolations to a placeholder BEFORE any
 * bounded-character-class match — the two test-editor-deep-links.js bugs,
 * applied verbatim (see file header). */
function mlpNormaliseUrl(string $url): string
{
    $url = str_replace('&amp;', '&', $url);
    $url = (string)preg_replace('/<\?[^?]*\?>/', 'X', $url);
    $url = (string)preg_replace('/\$\{[^}]*\}/', 'X', $url);
    return $url;
}

/** Param names from the QUERY PORTION ONLY, anchored (?:^|&) — never the
 * whole href (the second test-editor-deep-links.js bug). `$query` must
 * already have any leading `?` stripped and any `#fragment` removed. */
function mlpParamsFromQuery(string $query): array
{
    if (!preg_match_all('/(?:^|&)([A-Za-z_][\w-]*)=/', $query, $m)) { return []; }
    return $m[1];
}

/* Whole-appWeb basename index — built once, used by the cross-tree require
 * follower (suppression C) so it never needs to shell out or repeatedly
 * walk the tree. */
function mlpBasenameIndex(string $appWeb): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $idx = [];
    foreach (mlpWalk($appWeb, '/\.php$/') as $f) { $idx[basename($f)][] = $f; }
    return $cache = $idx;
}

/**
 * The param names a destination file's CLOSURE reads, following requires —
 * relative first, then the whole-tree basename index (suppression C) — plus
 * whether it passes the superglobal wholesale to a helper (suppression A).
 *
 * @return array{reads:array<string,bool>, wholesale:bool}
 */
function mlpReadsOf(string $absFile, string $appWeb, array &$visited = [], int $depth = 0): array
{
    $key = $absFile;
    if (isset($visited[$key]) || $depth > 6 || !is_file($absFile)) {
        return ['reads' => [], 'wholesale' => false];
    }
    $visited[$key] = true;

    $raw  = (string)file_get_contents($absFile);
    $text = mlpStripComments($raw);

    $reads = [];
    if (preg_match_all('/\$_(?:GET|POST|REQUEST)\s*\[\s*[\'"]([\w-]+)[\'"]\s*\]/', $text, $m)) {
        foreach ($m[1] as $p) { $reads[$p] = true; }
    }
    if (preg_match_all('/filter_input\(\s*INPUT_(?:GET|POST)\s*,\s*[\'"]([\w-]+)[\'"]/', $text, $m)) {
        foreach ($m[1] as $p) { $reads[$p] = true; }
    }
    /* Inline-JS URLSearchParams reads (a page's own <script> parsing its own
       query string, e.g. `new URLSearchParams(location.search).get('x')`). */
    if (preg_match_all('/\.get\(\s*[\'"]([\w-]+)[\'"]\s*\)/', $text, $m)) {
        foreach ($m[1] as $p) { $reads[$p] = true; }
    }

    /* Suppression A — wholesale pass-through. GET/REQUEST only, deliberately
       NOT $_POST: every emission this guard checks is a GET-style link/
       redirect param, so a bare `($_POST)` argument — the extremely common
       "hand the whole submitted form to a validator" shape
       (`catalogueAdminValidateCreateFields($_POST)`, manage/catalogues.php,
       found during this suppression's OWN mutation-proofing) — is a
       different, unrelated pattern that has nothing to do with reading a
       query-string param. Matching $_POST here made EVERY page that
       validates a posted form read-OPEN, which would have silently
       disabled this guard almost everywhere; scoping to GET/REQUEST fixed
       it (see the file's mutation-proofing notes). */
    $wholesale = (bool)preg_match('/\(\s*\$_(?:GET|REQUEST)\s*\)/', $text);

    /* Follow requires — literal path first (relative to this file's own
       directory, the usual case), then the basename index (suppression C).
       Every `require`/`include`(_once) with a literal-ish `.php` string
       anywhere in its expression is a candidate; a bare-variable require
       (`require $vfScript;`) has no literal here at all, which is exactly
       why the basename-index fallback below exists — it is not gated on
       proving the string came from the require call syntactically, only
       on the file containing BOTH the word require/include AND the
       filename literal somewhere (loose on purpose — see suppression C). */
    $hasRequireKeyword = (bool)preg_match('/\b(?:require|include)(?:_once)?\b/', $text);
    if ($hasRequireKeyword && preg_match_all('/[\'"]([A-Za-z0-9_.\\-]+\.php)[\'"]/', $text, $m)) {
        $selfBasename = basename($absFile);
        $tried = [];
        foreach (array_unique($m[1]) as $incRaw) {
            $incBasename = basename($incRaw);
            if ($incBasename === $selfBasename) { continue; } /* don't re-follow self-mentions */
            if (isset($tried[$incBasename])) { continue; }
            $tried[$incBasename] = true;

            /* Direct relative resolution first (covers the common
               `__DIR__ . '/includes/x.php'` shape without needing to parse
               DIRECTORY_SEPARATOR concatenation — the basename is enough
               once combined with "try this file's own directory"). */
            $candidates = [dirname($absFile) . '/' . $incBasename];
            $found = false;
            foreach ($candidates as $cand) {
                if (is_file($cand)) {
                    $sub = mlpReadsOf($cand, $appWeb, $visited, $depth + 1);
                    foreach ($sub['reads'] as $p => $_) { $reads[$p] = true; }
                    if ($sub['wholesale']) { $wholesale = true; }
                    $found = true;
                    break;
                }
            }
            if ($found) { continue; }

            /* Basename-index fallback (suppression C). Only follow when the
               basename resolves to a SMALL, unambiguous set (<=2 hits) —
               a common name (e.g. a hypothetical `index.php`) resolving to
               dozens of files would make this fallback noise, not signal. */
            $bidx = mlpBasenameIndex($appWeb);
            $hits = $bidx[$incBasename] ?? [];
            if ($hits !== [] && count($hits) <= 2) {
                foreach ($hits as $cand) {
                    $sub = mlpReadsOf($cand, $appWeb, $visited, $depth + 1);
                    foreach ($sub['reads'] as $p => $_) { $reads[$p] = true; }
                    if ($sub['wholesale']) { $wholesale = true; }
                }
            }
        }
    }

    return ['reads' => $reads, 'wholesale' => $wholesale];
}

/**
 * Suppression B — partial-resolves-to-includer. Every `manage/*.php` page
 * that `require`s a given `manage/includes/*.php` partial (direct mentions
 * only — one hop, which covers every real instance in this tree today).
 *
 * @return array<string,array<int,string>> partial basename => [including page abs paths]
 */
function mlpIncludersIndex(string $manage): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $includers = [];
    foreach (mlpWalk($manage, '/\.php$/') as $f) {
        if (str_starts_with($f, $manage . '/includes/')) { continue; } /* partials including partials: not needed today */
        $text = mlpStripComments((string)file_get_contents($f));
        if (preg_match_all('/[\'"]([A-Za-z0-9_.\\-]+\.php)[\'"]/', $text, $m)) {
            foreach (array_unique($m[1]) as $basename) {
                $partialPath = $manage . '/includes/' . $basename;
                if (is_file($partialPath)) { $includers[$basename][] = $f; }
            }
        }
    }
    return $cache = $includers;
}

/**
 * The effective read-set + wholesale flag for a DESTINATION, applying
 * suppression B when the destination is a `manage/includes/*.php` partial.
 *
 * @return array{reads:array<string,bool>, wholesale:bool}
 */
function mlpEffectiveReads(string $destAbs, string $appWeb, string $manage): array
{
    $visited = [];
    $own = mlpReadsOf($destAbs, $appWeb, $visited);
    if (!str_starts_with($destAbs, $manage . '/includes/')) { return $own; }

    $includers = mlpIncludersIndex($manage);
    $basename = basename($destAbs);
    $reads = $own['reads'];
    $wholesale = $own['wholesale'];
    foreach (($includers[$basename] ?? []) as $includerAbs) {
        $v2 = [];
        $sub = mlpReadsOf($includerAbs, $appWeb, $v2);
        foreach ($sub['reads'] as $p => $_) { $reads[$p] = true; }
        if ($sub['wholesale']) { $wholesale = true; }
    }
    return ['reads' => $reads, 'wholesale' => $wholesale];
}

/* =========================================================================
 * PART 1 — EMISSIONS: self-links, sibling links, redirects, window.location
 * ========================================================================= */

/**
 * Resolve a bare page slug (from a sibling link or an absolute /manage/…
 * link) to an absolute manage/*.php file, or null if it doesn't resolve to
 * one (editor destinations are excluded here — out of scope, guarded
 * elsewhere).
 */
function mlpResolvePage(string $slug, string $manage): ?string
{
    $slug = ltrim($slug, '/');
    if (str_starts_with($slug, 'manage/')) { $slug = substr($slug, strlen('manage/')); }
    if (str_starts_with($slug, 'editor')) { return null; } /* out of scope — test-editor-deep-links.js */
    $slug = preg_replace('/\.php$/', '', $slug) ?? $slug;
    if ($slug === '' || !preg_match('/^[a-z0-9_\/-]+$/i', $slug)) { return null; }
    foreach (["{$manage}/{$slug}.php", "{$manage}/{$slug}/index.php"] as $cand) {
        if (is_file($cand)) { return $cand; }
    }
    return null;
}

/** @return array<int,array{dest:string, param:string, loc:string, url:string}> */
function mlpGatherEmissions(string $pub, string $manage): array
{
    $out = [];

    /* --- 1+2+3: self-links, sibling links, header() redirects — scoped to
       files INSIDE manage/*.php (self/sibling links only make sense
       relative to a page that lives there). --- */
    foreach (mlpWalk($manage, '/\.php$/') as $f) {
        $raw = (string)file_get_contents($f);
        $stripped = mlpStripComments($raw);
        $lines = explode("\n", $stripped);
        foreach ($lines as $i => $line) {
            $loc = substr($f, strlen($pub) + 1) . ':' . ($i + 1);

            /* Self-link: href="?p=…" (query starts the string). */
            if (preg_match_all('/href\s*=\s*"(\?[^"]*)"/', $line, $m)) {
                foreach ($m[1] as $href) {
                    $norm = mlpNormaliseUrl($href);
                    $query = preg_replace('/#.*$/', '', substr($norm, 1)) ?? '';
                    foreach (mlpParamsFromQuery($query) as $p) {
                        $out[] = ['dest' => $f, 'param' => $p, 'loc' => $loc, 'url' => $href];
                    }
                }
            }
            /* Sibling link: href="page?p=…" — no leading / or ?. */
            if (preg_match_all('/href\s*=\s*"([a-z0-9_-]+)\?([^"]*)"/i', $line, $m)) {
                foreach ($m[1] as $idx => $slug) {
                    $dest = mlpResolvePage($slug, $manage);
                    if ($dest === null) { continue; }
                    $norm = mlpNormaliseUrl($m[2][$idx]);
                    $query = preg_replace('/#.*$/', '', $norm) ?? '';
                    foreach (mlpParamsFromQuery($query) as $p) {
                        $out[] = ['dest' => $dest, 'param' => $p, 'loc' => $loc, 'url' => "{$slug}?{$m[2][$idx]}"];
                    }
                }
            }
            /* header('Location: …') literal redirects with a query. */
            if (preg_match_all('/header\(\s*[\'"]Location:\s*([^\'"]+)[\'"]/', $line, $m)) {
                foreach ($m[1] as $loc2) {
                    $loc2 = preg_split('/\s/', trim($loc2))[0] ?? '';
                    $qpos = strpos($loc2, '?');
                    if ($qpos === false) { continue; }
                    $pathPart  = substr($loc2, 0, $qpos);
                    $query     = preg_replace('/#.*$/', '', substr($loc2, $qpos + 1)) ?? '';
                    $norm      = mlpNormaliseUrl($query);
                    $dest = str_starts_with($pathPart, '/') || str_starts_with($pathPart, 'manage/')
                        ? mlpResolvePage($pathPart, $manage)
                        : (str_starts_with($pathPart, '?') || $pathPart === '' ? $f : mlpResolvePage($pathPart, $manage));
                    if ($dest === null) { continue; }
                    foreach (mlpParamsFromQuery($norm) as $p) {
                        $out[] = ['dest' => $dest, 'param' => $p, 'loc' => $loc, 'url' => "Location: {$loc2}"];
                    }
                }
            }
        }
    }

    /* --- 4: absolute /manage/…?… links + window.location/window.open,
       anywhere under public_html (php inline scripts + standalone .js). --- */
    foreach (mlpWalk($pub, '/\.(php|js)$/') as $f) {
        $raw = (string)file_get_contents($f);
        $stripped = mlpStripComments($raw);
        $lines = explode("\n", $stripped);
        foreach ($lines as $i => $line) {
            $loc = substr($f, strlen($pub) + 1) . ':' . ($i + 1);
            $found = [];
            if (preg_match_all('/href\s*=\s*"(\/manage\/[^"]*\?[^"]*)"/', $line, $m)) { foreach ($m[1] as $u) { $found[] = $u; } }
            if (preg_match_all('/(?:location\.href|window\.location)\s*=\s*[\'"`](\/manage\/[^\'"`]*\?[^\'"`]*)[\'"`]/', $line, $m)) { foreach ($m[1] as $u) { $found[] = $u; } }
            if (preg_match_all('/window\.open\(\s*[\'"`](\/manage\/[^\'"`]*\?[^\'"`]*)[\'"`]/', $line, $m)) { foreach ($m[1] as $u) { $found[] = $u; } }

            foreach ($found as $url) {
                $norm = mlpNormaliseUrl($url);
                if (!preg_match('#^/manage/([a-z0-9_/-]+?)(?:\.php)?\?(.+)$#i', $norm, $pm)) { continue; }
                $dest = mlpResolvePage($pm[1], $manage);
                if ($dest === null) { continue; }
                $query = preg_replace('/#.*$/', '', $pm[2]) ?? '';
                foreach (mlpParamsFromQuery($query) as $p) {
                    $out[] = ['dest' => $dest, 'param' => $p, 'loc' => $loc, 'url' => $url];
                }
            }
        }
    }

    return $out;
}

/* =========================================================================
 * RUN
 * ========================================================================= */

$emissions = mlpGatherEmissions($pub, $manage);
echo '  Derived: ' . count($emissions) . " param emissions parsed\n\n";

ok('scanner found a plausible number of emissions to check (>= 40)', count($emissions) >= 40,
    'got ' . count($emissions));

$destSet = [];
foreach ($emissions as $e) { $destSet[$e['dest']] = true; }
ok('scanner resolved a plausible number of distinct destinations (>= 8)', count($destSet) >= 8,
    'got ' . count($destSet));

/* Positive controls — each is a case this guard's design got wrong once
   during the analysis pass, before the matching suppression landed. */
$ccli = $manage . '/ccli-report.php';
ok("control: manage/ccli-report.php is read-OPEN (wholesale \$_GET pass-through — suppression A)",
    is_file($ccli) && mlpEffectiveReads($ccli, $appWeb, $manage)['wholesale'] === true);

$wizardModal = $manage . '/includes/setup-wizard-modal.php';
ok("control: manage/includes/setup-wizard-modal.php's effective reads include 'reconfigure' via its includer (suppression B)",
    is_file($wizardModal) && isset(mlpEffectiveReads($wizardModal, $appWeb, $manage)['reads']['reconfigure']));

$setupDb = $manage . '/setup-database.php';
ok("control: manage/setup-database.php's effective reads include 'limit' via the cross-tree .sql/ require (suppression C)",
    is_file($setupDb) && isset(mlpEffectiveReads($setupDb, $appWeb, $manage)['reads']['limit']));

/* =========================================================================
 * CHECK — every emitted param is read by its destination (or the
 * destination's read-set is OPEN, or a partial's includer reads it)
 * ========================================================================= */

echo "\n";
$misses = [];
foreach ($emissions as $e) {
    $eff = mlpEffectiveReads($e['dest'], $appWeb, $manage);
    if ($eff['wholesale']) { continue; }
    if (isset($eff['reads'][$e['param']])) { continue; }
    $key = substr($e['dest'], strlen($pub) + 1) . ' ?' . $e['param'];
    $misses[$key][] = "{$e['loc']}  ({$e['url']})";
}
ksort($misses);
$missCount = count($misses);
ok("CHECK — every param a manage self/sibling link, redirect, or window.location emits is read by its destination ({$missCount} unread)",
    $misses === [],
    $misses === [] ? '' : implode("\n", array_map(
        static fn ($k, $locs) => "{$k}\n      " . implode("\n      ", array_slice(array_unique($locs), 0, 3)),
        array_keys($misses), $misses
    )));

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — in memory, every invocation.
 * ========================================================================= */

echo "\n  Mutation self-tests (the guard proving it can still fail):\n";

/* M1 — an injected emission with an unread param must be caught. */
$m1 = $emissions;
$m1[] = ['dest' => $setupDb, 'param' => 'zz_sweep_probe', 'loc' => 'synthetic:1', 'url' => '?zz_sweep_probe=1'];
$m1Miss = false;
foreach ($m1 as $e) {
    $eff = mlpEffectiveReads($e['dest'], $appWeb, $manage);
    if (!$eff['wholesale'] && !isset($eff['reads'][$e['param']]) && $e['param'] === 'zz_sweep_probe') { $m1Miss = true; }
}
ok('M1: injecting an emission with an unread param is flagged', $m1Miss);

/* M2 — the wholesale suppression is load-bearing, not redundant with a
   literal read: `ccliReportWindow($_GET)` (includes/ccli_report.php) reads
   'from'/'to'/'show_all' from the array it is HANDED, invisible to this
   guard's literal-$_GET-in-THIS-file scan since that read happens inside a
   different function in a different file, reached only via the bare-array
   argument. Prove those three names are genuinely absent from
   ccli-report.php's own literal read-set (so, without suppression A, an
   `?org=…&from=…` link would false-flag `from`) while the file's own
   directly-read params ('org', 'export' — plain literal $_GET[...] in the
   file itself) ARE present, showing the two are cleanly distinct. */
$ccliOwnReads = mlpEffectiveReads($ccli, $appWeb, $manage)['reads'];
ok("M2: ccli-report.php's OWN literal reads include 'org'/'export' but NOT 'from'/'to'/'show_all' "
    . '(those three exist only inside ccliReportWindow($_GET) — proves suppression A is load-bearing, '
    . 'not redundant with a literal read)',
    isset($ccliOwnReads['org']) && isset($ccliOwnReads['export'])
    && !isset($ccliOwnReads['from']) && !isset($ccliOwnReads['to']) && !isset($ccliOwnReads['show_all']));

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nA link that emits a param its destination never reads is a silently lost\n";
    echo "instruction (rule #33) — the page loads, nothing errors, and the one thing the\n";
    echo "link asked for just never happens. Read the param, drop it from the link, or if\n";
    echo "the destination genuinely delegates all parsing to a helper, make sure that\n";
    echo "delegation is visible as a bare \$_GET/\$_REQUEST argument.\n";
    exit(1);
}
echo "\nEvery manage self/sibling link, redirect, and window.location param is read.\n";
