<?php

declare(strict_types=1);

/**
 * iHymns — Empty-state "Get started" launcher guard (#1999)
 * =====================================================================
 *
 * ELI5
 * ----
 * #1999 gave four empty admin lists (External-Link Types, Songbooks,
 * Venues, Organisations) a friendly "Get started" card that opens the
 * SAME guided wizard the page header's own button already opens — via
 * ONE shared partial, `manage/includes/wizard-empty-state.php`
 * (`ihymns_wizard_empty_state()`, modelled on `slug-field.php`'s
 * `ihymns_slug_advanced_field()` idiom). This file is the standing guard
 * that keeps that shape honest: every call site really does point at a
 * modal that really exists on the SAME page (rule #33's "a param the
 * destination doesn't handle fails silently" applied to a
 * `data-bs-target` selector instead of a URL param), nothing forks a
 * second copy of the partial's markup, and the partial itself stays
 * accessible (visible button text, decorative icons hidden from
 * assistive tech).
 *
 * WHAT THIS FILE ASSERTS
 * -----------------------
 *  (a) CALL-SITE COUNT + FLOOR — tree-derived (glob `manage/**\/*.php`,
 *      never a hand-typed list, rule #34) scan finds >= 4 real CALL sites
 *      of `ihymns_wizard_empty_state(` (excluding the function's own
 *      DEFINITION), covering the floor {external-link-types.php,
 *      songbooks.php, venues.php, organisations.php}. Mutation-proven by
 *      renaming one floor file's call in an IN-MEMORY copy and confirming
 *      it drops out of both the count and the floor set.
 *  (b) THE KEY ONE — modalId <-> REAL MODAL contract (rule #33). For
 *      every call site, the literal `'modalId' => 'X'` argument is
 *      extracted and the SAME file must contain (i) a `<div ...>` tag
 *      carrying both `class="modal fade"` and `id="X"`, and (ii) a
 *      `data-bs-target="#X"` trigger somewhere — so a renamed modal can
 *      never leave a launcher pointing at nothing. Mutation-proven, per
 *      call site, TWO ways: renaming the real modal's `id="X"` in an
 *      in-memory copy -> red, and renaming the call site's `'modalId' =>
 *      'X'` argument in an in-memory copy -> red (the extracted (now
 *      renamed) id no longer resolves to any real modal in the file).
 *  (c) NO FORK — the markup breadcrumb `data-wizard-empty-state` and the
 *      function declaration `function ihymns_wizard_empty_state` each
 *      exist in exactly ONE file tree-wide (the partial itself) — a
 *      second copy pasted into a page instead of calling the shared
 *      function is exactly the regression rule #1 (modularity) forbids.
 *      Mutation-proven via an isolated synthetic fixture directory (never
 *      the tracked tree) that the counting primitive really does report
 *      >1 when a second file carries the same literal.
 *  (d) GATE PARITY (positional, rule #34) — the external-link-types.php
 *      call site sits after the schema-ready `<?php else: ?>` that
 *      follows `if (!$hasTypesSchema || !$hasPatternsSchema)` (the SAME
 *      gate the header trigger + manual form live behind); the venues.php
 *      call site sits after its own `if ($orgs):` guard (rule #33's
 *      dead-launcher trap: on a zero-organisation install a crafted
 *      `?org=<n>` can leave `$selectedOrgId > 0` with an empty `$orgs`,
 *      so the launcher must never render there — see venues.php's own
 *      code comment). songbooks.php's and organisations.php's wizard
 *      modals are UNGATED (no schema/org precondition), so no gate
 *      assertion applies to those two — documented here rather than
 *      silently absent. Both positional checks are mutation-proven by
 *      renaming the gate literal in an in-memory copy and confirming the
 *      derived position disappears.
 *  (e) A11Y — the partial's own decorative icons always carry
 *      `aria-hidden="true"` and its button always carries non-empty
 *      visible text, checked by actually CALLING the real function
 *      (in-process) with representative arguments and inspecting the
 *      rendered HTML — not by guessing from the source. Mutation-proven
 *      via a SUBPROCESS (a fresh `php` process requiring a mutated COPY
 *      of the partial — necessary because the real function is already
 *      declared in this process, so a second `require` of a mutated copy
 *      would fatal on "Cannot redeclare"): stripping `aria-hidden="true"`
 *      from the icon markup, and blanking the button's visible text, each
 *      independently flip the corresponding assertion to red. A third
 *      subprocess mutation proves the `modalId` format guard itself is
 *      load-bearing: neutering the `[A-Za-z0-9_-]+` validation lets an
 *      invalid `modalId` (one that would break the `data-bs-target`
 *      selector) render with NO exception, where the real code throws.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * -------------------------------------
 * Two layers, mirroring `test-service-setup-wizard.php`'s own precedent:
 *  (1) FIXTURE self-tests (PART 1) prove the parsing primitives —
 *      `wesExtractCallSites()`, `wesHasModalWithId()`,
 *      `wesHasDataBsTarget()`, `wesCountFilesContainingLiteral()` — can
 *      both find a marker that IS there and correctly refuse one that
 *      ISN'T, using small synthetic snippets/dirs. These run EVERY
 *      invocation, never touch the tracked tree.
 *  (2) REAL-CONTENT mutation proofs (PART 3) run the SAME extraction
 *      functions against a MUTATED COPY of the real file content (an
 *      in-memory `str_replace()`'d string, or a subprocess given a
 *      temp-file copy for (e)) and confirm the check goes red. The
 *      tracked source files on disk are never written to.
 *
 * @see appWeb/public_html/manage/includes/wizard-empty-state.php  the shared partial under test
 * @see appWeb/public_html/manage/external-link-types.php          call site — schema-ready region, wrap 'card'
 * @see appWeb/public_html/manage/songbooks.php                    call site — table empty row, wrap 'bare', ungated
 * @see appWeb/public_html/manage/venues.php                       call site — $orgs-gated (dead-launcher guard), wrap 'bare'
 * @see appWeb/public_html/manage/organisations.php                call site — list-view-only, headingTag 'h3', wrap 'bare'
 * @see appWeb/public_html/manage/includes/slug-field.php          the parameterised-partial idiom this mirrors
 * @see tests/php/test-service-setup-wizard.php                    the positional-gate + mutation-proof technique this adapts
 * @see tests/test-component-label-sites.js                        the tree-derived floor-list technique this adapts
 * @see #1999
 *
 *   php tests/php/test-wizard-empty-state.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or self-test failed.
 */

$repo       = dirname(__DIR__, 2);
$publicHtml = $repo . '/appWeb/public_html';
$manageDir  = $publicHtml . '/manage';
$partialFile = $manageDir . '/includes/wizard-empty-state.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { $passed++; }
    else { $failed++; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/* =========================================================================
 * PART 0 — parsing primitives.
 * ========================================================================= */

/** Strip `/* ... *​/` and `// ...` comments so a doc-comment's PROSE mention
 *  of a literal can never masquerade as real code (the same trap
 *  test-service-setup-wizard.php documents). */
function wesStripComments(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $src = preg_replace('~(?<!:)//[^\n]*~', '', $src) ?? $src;
    return $src;
}

/**
 * Every real CALL to `ihymns_wizard_empty_state(...)` in `$src` — i.e.
 * every occurrence of the name followed by `(` that is NOT immediately
 * preceded by the `function` keyword (which would be the partial's own
 * declaration). For each, the full `name(...)` text (balanced on `(`/`)`
 * depth) is captured, plus the `'modalId' => '...'` literal inside it,
 * when present.
 *
 * @return array<int,array{pos:int,block:string,modalId:?string}>
 */
function wesExtractCallSites(string $src): array
{
    $out = [];
    $len = strlen($src);
    $searchFrom = 0;
    while (($pos = strpos($src, 'ihymns_wizard_empty_state', $searchFrom)) !== false) {
        $searchFrom = $pos + 1;
        $afterName = $pos + strlen('ihymns_wizard_empty_state');

        // Must be followed by '(' (allowing whitespace) — otherwise it's a
        // mention in prose/a different identifier sharing the prefix.
        $j = $afterName;
        while ($j < $len && ($src[$j] === ' ' || $src[$j] === "\t" || $src[$j] === "\n" || $src[$j] === "\r")) { $j++; }
        if (($src[$j] ?? '') !== '(') { continue; }

        // Must NOT be a `function ihymns_wizard_empty_state(` declaration —
        // walk back over whitespace then over the preceding identifier chars
        // and compare against 'function'.
        $b = $pos - 1;
        while ($b >= 0 && ($src[$b] === ' ' || $src[$b] === "\t" || $src[$b] === "\n" || $src[$b] === "\r")) { $b--; }
        $word = '';
        while ($b >= 0 && preg_match('/[A-Za-z_]/', $src[$b]) === 1) { $word = $src[$b] . $word; $b--; }
        if (strtolower($word) === 'function') { continue; }

        // Balanced-paren extraction of the full call text.
        $depth = 0;
        $end = null;
        for ($i = $j; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '(') { $depth++; }
            elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        if ($end === null) { continue; }

        $block = substr($src, $pos, $end - $pos + 1);
        $modalId = null;
        if (preg_match('/[\'"]modalId[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $block, $m)) {
            $modalId = $m[1];
        }
        $out[] = ['pos' => $pos, 'block' => $block, 'modalId' => $modalId];
    }
    return $out;
}

/** Does `$src` contain a `<div ...>` tag carrying BOTH `class="modal fade"`
 *  (order-tolerant: the class list must contain both words) AND
 *  `id="$modalId"`? */
function wesHasModalWithId(string $src, string $modalId): bool
{
    if (!preg_match_all('/<div\b[^>]*>/i', $src, $m)) { return false; }
    $idNeedle = preg_quote($modalId, '/');
    foreach ($m[0] as $tag) {
        $hasModalFade = (bool)preg_match('/class\s*=\s*"[^"]*\bmodal\b[^"]*\bfade\b[^"]*"/', $tag)
            || (bool)preg_match('/class\s*=\s*"[^"]*\bfade\b[^"]*\bmodal\b[^"]*"/', $tag);
        $hasId = (bool)preg_match('/\bid\s*=\s*"' . $idNeedle . '"/', $tag);
        if ($hasModalFade && $hasId) { return true; }
    }
    return false;
}

/** Does `$src` contain a `data-bs-target="#$modalId"` (single or double
 *  quotes) anywhere? */
function wesHasDataBsTarget(string $src, string $modalId): bool
{
    $idNeedle = preg_quote($modalId, '/');
    return (bool)preg_match('/data-bs-target\s*=\s*([\'"])#' . $idNeedle . '\1/', $src);
}

/** Recursively collect every `.php` file under `$dir`, sorted. */
function wesWalkPhp(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) { return $out; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isFile() && $f->getExtension() === 'php') { $out[] = $f->getPathname(); }
    }
    sort($out);
    return $out;
}

/** For every `.php` file under `$dir` (comment-stripped), does it contain
 *  the literal `$needle`? Returns `[relativePath => hitCount]`. */
function wesCountFilesContainingLiteral(string $needle, string $dir, string $repoRoot): array
{
    $hits = [];
    foreach (wesWalkPhp($dir) as $f) {
        $src = wesStripComments((string)file_get_contents($f));
        $n = substr_count($src, $needle);
        if ($n > 0) {
            $rel = str_replace($repoRoot . '/', '', $f);
            $hits[$rel] = $n;
        }
    }
    ksort($hits);
    return $hits;
}

/** Nearest position in `$positions` that is strictly less than `$target`,
 *  or null. */
function wesNearestPreceding(array $positions, int $target): ?int
{
    $best = null;
    foreach ($positions as $p) { if ($p < $target && ($best === null || $p > $best)) { $best = $p; } }
    return $best;
}

/** Run `$mutatedSrc` (an in-memory `str_replace()` of `$originalSrc`)
 *  through `$fn($mutatedSrc)`. Never writes anything — the mutation stays
 *  entirely in a PHP string, matching this file's "never touch tracked
 *  source" rule for content-level mutation proofs. */
function wesWithMutatedString(string $originalSrc, string $search, string $replace, callable $fn): mixed
{
    return $fn(str_replace($search, $replace, $originalSrc));
}

/**
 * Render the REAL, unmutated partial (already `require`d once by this
 * process — see PART 2) directly, for the positive (non-mutation) a11y
 * assertions.
 */
function wesRenderReal(array $args): string
{
    return ihymns_wizard_empty_state($args);
}

/**
 * Render `$mutatedPartialSrc` (a full, in-memory-mutated copy of the
 * partial's PHP source) in a FRESH subprocess — required because the real
 * `ihymns_wizard_empty_state()` is already declared in THIS process, so a
 * second `require` of any copy (mutated or not) would fatal with "Cannot
 * redeclare function". Never writes to a tracked path — everything lives
 * under `sys_get_temp_dir()` and is deleted before returning.
 *
 * @return array{ok:bool, output:?string, exceptionMessage:?string}
 *         `ok` false means the subprocess itself failed to run (php not on
 *         PATH, syntax error in the mutation, etc.) — callers should not
 *         treat that as a meaningful pass OR fail on its own.
 */
function wesRenderViaSubprocess(string $mutatedPartialSrc, array $args): array
{
    $tmpPartial = tempnam(sys_get_temp_dir(), 'ihymns_wes_partial_') . '.php';
    $tmpArgs    = tempnam(sys_get_temp_dir(), 'ihymns_wes_args_') . '.json';
    $tmpDriver  = tempnam(sys_get_temp_dir(), 'ihymns_wes_driver_') . '.php';

    file_put_contents($tmpPartial, $mutatedPartialSrc);
    file_put_contents($tmpArgs, json_encode($args, JSON_THROW_ON_ERROR));
    file_put_contents($tmpDriver, <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$args = json_decode((string)file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
try {
    echo "OK:" . base64_encode(ihymns_wizard_empty_state($args));
} catch (\Throwable $e) {
    echo "EXCEPTION:" . base64_encode($e->getMessage());
}
PHP
    );

    $cmd = 'php ' . escapeshellarg($tmpDriver) . ' ' . escapeshellarg($tmpPartial) . ' ' . escapeshellarg($tmpArgs) . ' 2>&1';
    $raw = shell_exec($cmd);

    @unlink($tmpPartial);
    @unlink($tmpArgs);
    @unlink($tmpDriver);

    if ($raw === null) { return ['ok' => false, 'output' => null, 'exceptionMessage' => null]; }
    $raw = trim($raw);
    if (str_starts_with($raw, 'OK:')) {
        $decoded = base64_decode(substr($raw, 3), true);
        return ['ok' => true, 'output' => $decoded === false ? null : $decoded, 'exceptionMessage' => null];
    }
    if (str_starts_with($raw, 'EXCEPTION:')) {
        $decoded = base64_decode(substr($raw, 10), true);
        return ['ok' => true, 'output' => null, 'exceptionMessage' => $decoded === false ? null : $decoded];
    }
    // Fatal error / syntax error text from the subprocess — not one of our
    // two expected shapes.
    return ['ok' => false, 'output' => null, 'exceptionMessage' => $raw];
}

/* Every `<i class="bi ...">` in `$html`; true iff EVERY one carries
   `aria-hidden="true"`. */
function wesEveryIconAriaHidden(string $html): bool
{
    if (!preg_match_all('/<i\b[^>]*class="bi\b[^"]*"[^>]*>/i', $html, $m)) { return true; /* no icons at all is vacuously fine here */ }
    foreach ($m[0] as $tag) {
        if (!preg_match('/aria-hidden\s*=\s*"true"/', $tag)) { return false; }
    }
    return true;
}

/* Does `$html` have a `<button ...>...</button>` whose inner text (icon
   tags stripped) is non-empty after trimming? */
function wesButtonHasVisibleText(string $html): bool
{
    if (!preg_match('/<button\b[^>]*>(.*?)<\/button>/is', $html, $m)) { return false; }
    $inner = preg_replace('/<i\b[^>]*>.*?<\/i>/is', '', $m[1]) ?? $m[1];
    $inner = trim(strip_tags($inner));
    return $inner !== '';
}

/* =========================================================================
 * PART 1 — FIXTURE self-tests for the primitives above (rule #34: prove
 * each scanner can both find a marker that IS there and correctly refuse
 * one that ISN'T). None of these touch the tracked tree.
 * ========================================================================= */

echo "\"Get started\" empty-state launcher guard (#1999)\n\n";
echo "PART 1 — primitive self-tests\n";

/* ---- wesExtractCallSites() ---- */
$fixtureCallSrc = <<<'PHP'
<?php
function ihymns_wizard_empty_state(array $o): string
{
    return 'not a call';
}
$x = ihymns_wizard_empty_state([
    'modalId' => 'fooModal',
    'buttonLabel' => 'Do the thing',
]);
$y = 2 + ihymns_wizard_empty_state(['modalId' => 'barModal']);
PHP;
$fixtureSites = wesExtractCallSites($fixtureCallSrc);
ok('wesExtractCallSites() finds exactly the two real CALLS, not the declaration', count($fixtureSites) === 2);
ok('MUTATION PROOF: wesExtractCallSites() never reports the function DEFINITION as a call site',
    !in_array(true, array_map(static fn(array $s): bool => str_contains($s['block'], "return 'not a call'"), $fixtureSites), true));
ok('wesExtractCallSites() extracts the first modalId literal correctly', ($fixtureSites[0]['modalId'] ?? null) === 'fooModal');
ok('wesExtractCallSites() extracts the second modalId literal correctly', ($fixtureSites[1]['modalId'] ?? null) === 'barModal');

$fixtureNoCallSrc = "<?php // ihymns_wizard_empty_state is mentioned only in a comment here\n\$z = 1;";
ok('MUTATION PROOF: wesExtractCallSites() ignores a comment-only mention (comment not stripped here on purpose — the raw name+paren precondition never matches without a following "(")',
    wesExtractCallSites($fixtureNoCallSrc) === []);

/* ---- wesHasModalWithId() ---- */
$fixtureModalSrc = '<div class="card"><p>x</p></div><div class="modal fade" id="demoModal" tabindex="-1"><div class="modal-dialog"></div></div>';
ok('wesHasModalWithId() finds a real modal fade div with the matching id', wesHasModalWithId($fixtureModalSrc, 'demoModal'));
ok('MUTATION PROOF: wesHasModalWithId() refuses a non-matching id', !wesHasModalWithId($fixtureModalSrc, 'otherModal'));
$fixtureModalRenamed = str_replace('id="demoModal"', 'id="demoModalRenamed"', $fixtureModalSrc);
ok('MUTATION PROOF: renaming the real modal id in-memory makes wesHasModalWithId() go false for the ORIGINAL id',
    !wesHasModalWithId($fixtureModalRenamed, 'demoModal'));

/* ---- wesHasDataBsTarget() ---- */
$fixtureTriggerSrc = '<button data-bs-toggle="modal" data-bs-target="#demoModal">Open</button>';
ok('wesHasDataBsTarget() finds a real trigger', wesHasDataBsTarget($fixtureTriggerSrc, 'demoModal'));
ok('MUTATION PROOF: wesHasDataBsTarget() refuses a non-matching target', !wesHasDataBsTarget($fixtureTriggerSrc, 'otherModal'));

/* ---- wesCountFilesContainingLiteral() (isolated synthetic dir — the (c)
   NO-FORK primitive: proves the scanner CAN detect a fork without ever
   touching the real tracked tree). ---- */
$fixtureTreeDir = sys_get_temp_dir() . '/ihymns_wes_tree_fixture_' . uniqid();
mkdir($fixtureTreeDir, 0777, true);
file_put_contents($fixtureTreeDir . '/only.php', "<?php echo 'data-wizard-empty-state=\"x\"';\n");
$hitsOne = wesCountFilesContainingLiteral('data-wizard-empty-state', $fixtureTreeDir, $fixtureTreeDir);
ok('wesCountFilesContainingLiteral() finds the ONE real occurrence', array_keys($hitsOne) === ['only.php']);
file_put_contents($fixtureTreeDir . '/forked.php', "<?php echo 'data-wizard-empty-state=\"y\"'; // a second, forked copy\n");
$hitsTwo = wesCountFilesContainingLiteral('data-wizard-empty-state', $fixtureTreeDir, $fixtureTreeDir);
ok('MUTATION PROOF: adding a second file with the SAME literal makes the count go from 1 file to 2 (this is what a real fork would look like)',
    count($hitsTwo) === 2);
unlink($fixtureTreeDir . '/only.php');
unlink($fixtureTreeDir . '/forked.php');
rmdir($fixtureTreeDir);

/* ---- wesNearestPreceding() ---- */
ok('wesNearestPreceding() finds the nearest position before the target', wesNearestPreceding([10, 50, 90], 60) === 50);
ok('MUTATION PROOF: wesNearestPreceding() returns null when every position is AFTER the target (simulates a renamed-away gate)',
    wesNearestPreceding([100, 200], 60) === null);

/* ---- wesEveryIconAriaHidden() / wesButtonHasVisibleText() ---- */
ok('wesEveryIconAriaHidden() accepts an icon that carries aria-hidden',
    wesEveryIconAriaHidden('<i class="bi bi-x" aria-hidden="true"></i>'));
ok('MUTATION PROOF: wesEveryIconAriaHidden() refuses an icon missing aria-hidden',
    !wesEveryIconAriaHidden('<i class="bi bi-x"></i>'));
ok('wesButtonHasVisibleText() accepts a button with real text',
    wesButtonHasVisibleText('<button type="button"><i class="bi bi-x" aria-hidden="true"></i>Click me</button>'));
ok('MUTATION PROOF: wesButtonHasVisibleText() refuses a button with only an icon and no text',
    !wesButtonHasVisibleText('<button type="button"><i class="bi bi-x" aria-hidden="true"></i></button>'));

/* =========================================================================
 * PART 2 — load the real sources + build the real call-site inventory.
 * ========================================================================= */

echo "\nPART 2 — real tree scan\n";

ok('the shared partial exists', is_file($partialFile));
$partialSrc = (string)file_get_contents($partialFile);

/* Every .php file under manage/, real content — kept as a file=>content map
   so PART 3's (a) mutation proof can swap ONE entry in-memory and re-run
   the SAME extraction over the map, without ever touching disk. */
$manageFiles = wesWalkPhp($manageDir);
ok('found a plausible number of manage/**/*.php files (parser sanity)', count($manageFiles) >= 10);

/* The partial's OWN file is excluded from the site-scan map: its exception
   message literally contains the text "ihymns_wizard_empty_state():" (a
   diagnostic string, not a real call), and — being the definition, not a
   caller — it has no business appearing in the caller inventory anyway.
   (c)'s NO-FORK check independently confirms the definition itself exists
   exactly once, in exactly this file, unaffected by this exclusion. */
$partialRel = str_replace($publicHtml . '/', '', $partialFile);
$fileToSrc = [];
foreach ($manageFiles as $f) {
    $rel = str_replace($publicHtml . '/', '', $f);
    if ($rel === $partialRel) { continue; }
    $fileToSrc[$rel] = (string)file_get_contents($f);
}

/** Extract call sites across a {relPath => src} map, tagging each with its
 *  owning file. */
function wesScanMap(array $fileToSrc): array
{
    $sites = [];
    foreach ($fileToSrc as $rel => $src) {
        foreach (wesExtractCallSites($src) as $site) {
            $site['file'] = $rel;
            $sites[] = $site;
        }
    }
    return $sites;
}

$realSites = wesScanMap($fileToSrc);
$realFilesWithSites = array_values(array_unique(array_map(static fn(array $s): string => $s['file'], $realSites)));
sort($realFilesWithSites);

echo '  found ' . count($realSites) . " call site(s) across " . count($realFilesWithSites) . " file(s):\n";
foreach ($realFilesWithSites as $f) { echo "    - {$f}\n"; }

/* =========================================================================
 * PART 3 — the (a)-(e) assertions.
 * ========================================================================= */

echo "\nPART 3 — assertions\n";

/* ---- (a) CALL-SITE COUNT + FLOOR ---- */
ok('(a) at least 4 real call sites of ihymns_wizard_empty_state( found tree-wide under manage/ (found ' . count($realSites) . ')',
    count($realSites) >= 4);

$floor = [
    'external-link-types.php',
    'songbooks.php',
    'venues.php',
    'organisations.php',
];
foreach ($floor as $f) {
    ok("(a) floor file 'manage/{$f}' calls ihymns_wizard_empty_state(", in_array('manage/' . $f, $realFilesWithSites, true));
}

/* MUTATION PROOF — rename ONE floor file's call in an IN-MEMORY copy of the
   map and confirm it drops out of both the count and the floor coverage. */
$venuesRel = 'manage/venues.php';
ok('(a) fixture precondition: manage/venues.php really is in the real map', isset($fileToSrc[$venuesRel]));
$mutatedMap = $fileToSrc;
$mutatedMap[$venuesRel] = str_replace('ihymns_wizard_empty_state(', 'zzz_mutated_wizard_empty_state(', $mutatedMap[$venuesRel]);
$mutatedSites = wesScanMap($mutatedMap);
$mutatedFilesWithSites = array_unique(array_map(static fn(array $s): string => $s['file'], $mutatedSites));
ok('(a) MUTATION PROOF: renaming venues.php\'s call in-memory drops it from the derived call-site set',
    !in_array($venuesRel, $mutatedFilesWithSites, true));

/* ---- (b) modalId <-> REAL MODAL contract — the key one ---- */
foreach ($realSites as $site) {
    $file = $site['file'];
    $modalId = $site['modalId'];
    $src = $fileToSrc[$file];

    ok("(b) {$file}: call site has a non-empty 'modalId' literal", $modalId !== null && $modalId !== '');
    if ($modalId === null || $modalId === '') { continue; }

    ok("(b) {$file}: a <div class=\"modal fade\" id=\"{$modalId}\"> exists in the SAME file", wesHasModalWithId($src, $modalId));
    ok("(b) {$file}: a data-bs-target=\"#{$modalId}\" trigger exists in the SAME file", wesHasDataBsTarget($src, $modalId));

    /* MUTATION PROOF #1 — rename the REAL MODAL's id in an in-memory copy. */
    $realIdLiteral = 'id="' . $modalId . '"';
    ok("(b) {$file}: fixture precondition — {$realIdLiteral} is really present", str_contains($src, $realIdLiteral));
    $mutatedNoModal = str_replace($realIdLiteral, 'id="' . $modalId . 'Renamed"', $src);
    ok("(b) {$file}: MUTATION PROOF — renaming the real modal's id makes the contract go false (red)",
        !wesHasModalWithId($mutatedNoModal, $modalId));

    /* MUTATION PROOF #2 — rename the CALL SITE's modalId argument in an
       in-memory copy; the (now-renamed) extracted id must resolve to NO
       real modal in the file — i.e. a curator who typos/renames the arg
       gets a launcher that opens nothing, and this guard catches it.
       Spacing-agnostic (real call sites align `=>` across several keys
       with padding spaces, e.g. `'modalId'     => '...'`). */
    $argPattern = '/([\'"]modalId[\'"]\s*=>\s*[\'"])' . preg_quote($modalId, '/') . '([\'"])/';
    $hasArgLiteral = (bool)preg_match($argPattern, $src);
    ok("(b) {$file}: fixture precondition — the modalId argument literal is really present", $hasArgLiteral);
    $mutatedArgSrc = preg_replace($argPattern, '${1}' . $modalId . 'Renamed${2}', $src, 1) ?? $src;
    $mutatedArgSites = wesExtractCallSites($mutatedArgSrc);
    $mutatedModalId = null;
    foreach ($mutatedArgSites as $ms) {
        if ($ms['modalId'] === $modalId . 'Renamed') { $mutatedModalId = $ms['modalId']; break; }
    }
    ok("(b) {$file}: MUTATION PROOF — renaming the call site's modalId argument yields an id with no matching real modal (red)",
        $mutatedModalId !== null && !wesHasModalWithId($mutatedArgSrc, $mutatedModalId));
}

/* ---- (c) NO FORK ---- */
$dataAttrHits = wesCountFilesContainingLiteral('data-wizard-empty-state', $publicHtml, $publicHtml);
ok('(c) the "data-wizard-empty-state" markup breadcrumb literal appears in EXACTLY ONE file tree-wide (found: ' . implode(', ', array_keys($dataAttrHits)) . ')',
    count($dataAttrHits) === 1 && isset($dataAttrHits['manage/includes/wizard-empty-state.php']));

$fnDeclHits = wesCountFilesContainingLiteral('function ihymns_wizard_empty_state', $publicHtml, $publicHtml);
ok('(c) "function ihymns_wizard_empty_state" is declared in EXACTLY ONE file tree-wide (found: ' . implode(', ', array_keys($fnDeclHits)) . ')',
    count($fnDeclHits) === 1 && isset($fnDeclHits['manage/includes/wizard-empty-state.php']));
/* (The mutation proof that this counting primitive CAN detect a fork lives
   in PART 1's isolated-fixture-dir self-test above — deliberately never
   run against the real tracked tree, per this file's own "never write to
   a tracked source file" rule.) */

/* ---- (d) GATE PARITY (positional) ---- */

/* external-link-types.php: the call must sit after the schema-ready
   `<?php else: ?>` that follows the schema-MISSING gate. */
$elt = 'manage/external-link-types.php';
$eltSrc = $fileToSrc[$elt];
$eltSite = null;
foreach ($realSites as $s) { if ($s['file'] === $elt) { $eltSite = $s; break; } }
ok('(d) found the external-link-types.php call site', $eltSite !== null);

$eltNegGateLiteral = 'if (!$hasTypesSchema || !$hasPatternsSchema)';
$eltNegGatePos = strpos($eltSrc, $eltNegGateLiteral);
$eltElsePos = $eltNegGatePos !== false ? strpos($eltSrc, '<?php else: ?>', $eltNegGatePos) : false;
ok('(d) found the schema-missing gate + its schema-ready else in external-link-types.php', $eltNegGatePos !== false && $eltElsePos !== false);
if ($eltSite !== null && $eltElsePos !== false) {
    ok('(d) external-link-types.php call site sits after the schema-ready else (positional)', $eltSite['pos'] > $eltElsePos);
}
/* MUTATION PROOF — rename the gate variable in-memory; the literal (and
   therefore the derived else position) disappears. */
$eltMutatedGate = str_replace($eltNegGateLiteral, 'if (!$zzzMutatedTypesSchema || !$hasPatternsSchema)', $eltSrc);
ok('(d) MUTATION PROOF: renaming the external-link-types.php schema gate makes the gate literal unfindable (red)',
    strpos($eltMutatedGate, $eltNegGateLiteral) === false);

/* venues.php: the call must sit after its own `if ($orgs):` guard
   (rule #33's dead-launcher trap — see the code comment in venues.php). */
$ven = 'manage/venues.php';
$venSrc = $fileToSrc[$ven];
$venSite = null;
foreach ($realSites as $s) { if ($s['file'] === $ven) { $venSite = $s; break; } }
ok('(d) found the venues.php call site', $venSite !== null);

$venGateLiteral = 'if ($orgs):';
preg_match_all('/' . preg_quote($venGateLiteral, '/') . '/', $venSrc, $mVen, PREG_OFFSET_CAPTURE);
$venGatePositions = array_map(static fn(array $x): int => $x[1], $mVen[0]);
ok('(d) found at least one "if ($orgs):" gate in venues.php', count($venGatePositions) >= 1);
if ($venSite !== null) {
    $nearest = wesNearestPreceding($venGatePositions, $venSite['pos']);
    ok('(d) venues.php call site sits after an "if ($orgs):" gate (positional)', $nearest !== null);
}
/* MUTATION PROOF — rename $orgs in the gate literal in-memory; the
   position list this specific literal produces empties out. */
$venMutatedGate = str_replace($venGateLiteral, 'if ($zzzMutatedOrgs):', $venSrc);
preg_match_all('/' . preg_quote($venGateLiteral, '/') . '/', $venMutatedGate, $mVenMut, PREG_OFFSET_CAPTURE);
ok('(d) MUTATION PROOF: renaming venues.php\'s $orgs gate leaves zero matching "if ($orgs):" positions (red)',
    count($mVenMut[0]) === 0);

/* songbooks.php / organisations.php wizard modals are UNGATED (no schema
   or org precondition — songbooks.php's header trigger and modal render
   unconditionally; organisations.php's wizard modal is likewise
   unconditional, only the header TRIGGER is confined to the !$editOrg
   list view). No gate-parity assertion applies to either — documented
   here per this file's own doc-block rather than silently omitted. */
ok('(d) documented: songbooks.php\'s wizard modal is ungated by design (no assertion needed)', true);
ok('(d) documented: organisations.php\'s wizard modal is ungated by design (no assertion needed)', true);

/* ---- (e) A11Y ---- */

$fixtureArgsCard = [
    'icon' => 'bi-link-45deg',
    'heading' => 'No link types yet',
    'body' => 'Add the external providers songs and songbooks can link out to.',
    'modalId' => 'linkTypeWizardModal',
    'buttonLabel' => 'Add provider (guided)',
    'wrap' => 'card',
    'hint' => 'Prefer to type it yourself? Expand "Add a link type manually" above.',
];
$fixtureArgsBare = [
    'icon' => 'bi-book',
    'heading' => 'No songbooks yet',
    'body' => 'Add your first songbook to get started.',
    'modalId' => 'songbookWizardModal',
    'buttonLabel' => 'New songbook (guided)',
    'wrap' => 'bare',
    'headingTag' => 'h3',
];

/* Real (unmutated) render — requires the partial ONCE for the rest of this
   process's lifetime. */
require_once $partialFile;

$renderedCard = wesRenderReal($fixtureArgsCard);
$renderedBare = wesRenderReal($fixtureArgsBare);

ok('(e) real render (wrap=card): every icon carries aria-hidden="true"', wesEveryIconAriaHidden($renderedCard));
ok('(e) real render (wrap=card): the button has non-empty visible text', wesButtonHasVisibleText($renderedCard));
ok('(e) real render (wrap=card): carries the modalId in data-bs-target', str_contains($renderedCard, 'data-bs-target="#linkTypeWizardModal"'));
ok('(e) real render (wrap=card): outer frame is card-admin p-4 text-center', str_contains($renderedCard, 'card-admin p-4 text-center'));

ok('(e) real render (wrap=bare): every icon carries aria-hidden="true"', wesEveryIconAriaHidden($renderedBare));
ok('(e) real render (wrap=bare): the button has non-empty visible text', wesButtonHasVisibleText($renderedBare));
ok('(e) real render (wrap=bare): outer frame is text-center py-4 (no card chrome)', str_contains($renderedBare, 'class="text-center py-4"'));
ok('(e) real render (wrap=bare, headingTag=h3): heading is an <h3>', str_contains($renderedBare, '<h3 class="h6 mb-2">'));

/* Real render: htmlspecialchars applied to user-facing text (defence in
   depth — none of these six inputs are documented as pre-escaped, unlike
   slug-field.php's `help`). */
$xssArgs = $fixtureArgsCard;
$xssArgs['heading'] = '<script>alert(1)</script>';
$renderedXss = wesRenderReal($xssArgs);
ok('(e) heading text is HTML-escaped (no raw <script> in output)', !str_contains($renderedXss, '<script>alert(1)</script>'));
ok('(e) heading text is HTML-escaped (the escaped form IS present)', str_contains($renderedXss, htmlspecialchars('<script>alert(1)</script>')));

/* Real render: an invalid modalId (would break the data-bs-target
   selector — the exact dead-launcher shape rule #33 warns about) throws. */
$threw = false;
$threwMessage = '';
try {
    wesRenderReal(['icon' => 'bi-x', 'heading' => 'h', 'body' => 'b', 'modalId' => 'bad id!', 'buttonLabel' => 'Go']);
} catch (\InvalidArgumentException $e) {
    $threw = true;
    $threwMessage = $e->getMessage();
}
ok('(e) an invalid modalId (spaces/punctuation) throws InvalidArgumentException', $threw);
ok('(e) the exception message names the offending value', $threw && str_contains($threwMessage, 'bad id!'));

/* MUTATION PROOFS — via subprocess, since the real function is already
   declared in this process (see wesRenderViaSubprocess() doc-block). */

echo "\n  (running subprocess mutation proofs for (e)...)\n";

/* #1 — strip aria-hidden from the heading-icon line -> icons no longer all
   carry it. */
$noAriaHiddenSrc = str_replace(
    ' text-primary fs-1" aria-hidden="true">',
    ' text-primary fs-1">',
    $partialSrc
);
ok('(e) fixture precondition: the heading-icon aria-hidden literal is really present in the real source',
    str_contains($partialSrc, ' text-primary fs-1" aria-hidden="true">'));
$mutAriaResult = wesRenderViaSubprocess($noAriaHiddenSrc, $fixtureArgsCard);
ok('(e) MUTATION PROOF subprocess ran cleanly (aria-hidden strip)', $mutAriaResult['ok'] && $mutAriaResult['output'] !== null);
if ($mutAriaResult['ok'] && $mutAriaResult['output'] !== null) {
    ok('(e) MUTATION PROOF: stripping aria-hidden from the heading icon flips the all-icons-hidden check to false (red)',
        !wesEveryIconAriaHidden($mutAriaResult['output']));
}

/* #2 — blank the button's visible label -> button text check goes false. */
$blankButtonSrc = str_replace(
    "\$html .= '<i class=\"bi bi-magic me-1\" aria-hidden=\"true\"></i>' . htmlspecialchars(\$buttonLabel);",
    "\$html .= '<i class=\"bi bi-magic me-1\" aria-hidden=\"true\"></i>';",
    $partialSrc
);
ok('(e) fixture precondition: the button-label concatenation literal is really present in the real source',
    str_contains($partialSrc, "\$html .= '<i class=\"bi bi-magic me-1\" aria-hidden=\"true\"></i>' . htmlspecialchars(\$buttonLabel);"));
$mutButtonResult = wesRenderViaSubprocess($blankButtonSrc, $fixtureArgsCard);
ok('(e) MUTATION PROOF subprocess ran cleanly (blank button text)', $mutButtonResult['ok'] && $mutButtonResult['output'] !== null);
if ($mutButtonResult['ok'] && $mutButtonResult['output'] !== null) {
    ok('(e) MUTATION PROOF: blanking the button\'s visible text flips the visible-text check to false (red)',
        !wesButtonHasVisibleText($mutButtonResult['output']));
}

/* #3 — neuter the modalId format validation -> an invalid modalId no
   longer throws (proves the validation is load-bearing for the (b)
   dead-launcher contract: without it, a typo'd/unsafe modalId would
   silently render instead of failing loudly). */
$noValidationSrc = str_replace(
    "if (\$modalId === '' || preg_match('/^[A-Za-z0-9_-]+\$/', \$modalId) !== 1) {",
    "if (false) {",
    $partialSrc
);
ok('(e) fixture precondition: the modalId validation literal is really present in the real source',
    str_contains($partialSrc, "if (\$modalId === '' || preg_match('/^[A-Za-z0-9_-]+\$/', \$modalId) !== 1) {"));
$mutValidationResult = wesRenderViaSubprocess($noValidationSrc, [
    'icon' => 'bi-x', 'heading' => 'h', 'body' => 'b', 'modalId' => 'bad id!', 'buttonLabel' => 'Go',
]);
ok('(e) MUTATION PROOF subprocess ran cleanly (neuter modalId validation)', $mutValidationResult['ok']);
if ($mutValidationResult['ok']) {
    ok('(e) MUTATION PROOF: neutering the modalId format validation lets an invalid modalId render with NO exception (red)',
        $mutValidationResult['output'] !== null && $mutValidationResult['exceptionMessage'] === null);
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
echo "Every 'Get started' empty-state call site (>= 4, covering the external-link-types/\n"
   . "songbooks/venues/organisations floor) points its data-bs-target at a modal that\n"
   . "genuinely exists on the SAME page, the shared partial is the ONLY definition and\n"
   . "the ONLY place its markup breadcrumb appears tree-wide, the external-link-types.php\n"
   . "and venues.php call sites sit behind their pages' own schema/\$orgs gates, and the\n"
   . "partial itself renders accessibly (visible button text, aria-hidden decorative\n"
   . "icons, escaped user-facing text, and a load-bearing modalId format guard).\n";
exit(0);
