<?php

declare(strict_types=1);

/**
 * iHymns — action caller coverage: the REVERSE direction (silent-wiring sweep, epic #2008)
 * ==========================================================================================
 *
 * ELI5
 * ----
 * `tests/php/test-orphan-inventory.php` already asks "does anything dispatch
 * an action nobody ever calls?" This file asks the OPPOSITE question: "does
 * anything CALL an action name that nothing ever dispatches?" That is the
 * shape that presents as a button or a link that silently does nothing —
 * the request goes out, the server has no `case`/map entry for it, api.php
 * (or whichever dispatch surface) falls through to its default branch, and
 * the click just... doesn't work. No error dialog names the cause, because
 * there usually isn't one to catch.
 *
 * WHY A SECOND GUARD, NOT A TWEAK TO test-orphan-inventory.php
 * --------------------------------------------------------------
 * `orphanReferencesIn()` in that file is deliberately LOOSE — "does this
 * string literal appear anywhere in a caller-bucket file" — which is the
 * right trade for ITS direction (a caller that's hard to prove wrong should
 * not be flagged as a false "orphan"). Reusing that looseness here would be
 * backwards: this guard needs to be CONFIDENT a name really was emitted as
 * an action request before accusing the dispatch side of ignoring it, so it
 * uses a small set of HIGH-PRECISION emission shapes instead (see below) —
 * under-counting real emissions is the safe failure mode for this
 * direction, over-counting is not.
 *
 * EMISSION SHAPES (measured against this tree; anything looser produced a
 * false positive during the sweep's analysis pass — see each shape's note)
 * -----------------------------------------------------------------------
 *  1. `?action=name` / `&action=name` inside a URL string literal (PHP or
 *     JS), `&amp;`-normalised first.
 *  2. `<input|button name="action" value="name">` (both attribute orders).
 *  3. `.append('action', 'name')` / `.set('action', 'name')` on
 *     FormData/URLSearchParams.
 *  4. `action: 'name'` INSIDE a `JSON.stringify({ … })` call — and ONLY
 *     there. A bare `action: 'x'` object-literal key anywhere else is NOT
 *     matched: `js/modules/print.js` uses `{ tpl, action: 'pdf' | 'print' }`
 *     as a purely INTERNAL resolver value that is never sent to a server,
 *     and `data-action="…"` markup also matches a loose `action\s*[:=]`
 *     pattern — both were measured false positives during the sweep and are
 *     why this shape is scoped this tightly.
 *
 * HANDLED SIDE
 * ------------
 * The union of every dispatch surface's `dispatchParserActionsForFile()`
 * names (switch/if/in_array — the shapes the shared lib already modelled)
 * PLUS its `dispatchParserMapKeys()` names (map-keyed dispatch, e.g.
 * `manage/setup-database.php`'s `$scriptMap[$action]` — added to the shared
 * lib alongside this guard; see that function's doc-block). V1 is
 * deliberately a UNION check across every surface — "is this name handled
 * ANYWHERE" — not "does this exact caller's URL point at the surface that
 * handles it". The union already catches the silent-no-op class (a name
 * NOBODY handles) with near-zero false-positive risk; a mis-targeted-but-
 * handled-elsewhere action is a much rarer, lower-severity failure and is
 * explicitly out of scope for v1 (recorded here, not silently ignored).
 *
 * SECONDARY ASSERTION — the `data-action` VALUE contract
 * --------------------------------------------------------
 * Client-side event delegation (`btn.closest('[data-action]')` then a
 * comparison against `getAttribute('data-action')`/`.dataset.action`) is a
 * SEPARATE wiring contract from the server `?action=` name above — no HTTP
 * request is involved, just a JS comparison branch. Every `data-action="v"`
 * emitted in markup must have `v` appear in a comparison/case/selector
 * position SOMEWHERE in the corpus. Measured false-positive source: a
 * handler that reads the attribute into a variable FIRST
 * (`const a = btn.getAttribute('data-action'); if (a === 'add-pattern')`,
 * `manage/external-link-types.php`, `manage/musicians-bulk-promote.php`) —
 * the comparison literal never sits textually next to the `getAttribute`
 * call, so a narrow adjacency check would false-flag it. Suppression: for
 * any FILE that reads `data-action` generically (`getAttribute('data-action')`
 * or a bare `.dataset.action`), every string-literal `===`/`==`/`case`
 * comparison anywhere in that same file counts toward the handled set. This
 * is weaker than true per-page pairing (value-appears-anywhere-in-a-
 * reading-file, not "this exact read compares to this exact value") but
 * still catches the real regression class: a deleted handler branch.
 *
 * ⚠️ NEVER `rg`/shell out — plain `RecursiveDirectoryIterator`, same
 * incident history as tests/php/test-orphan-inventory.php's header (`rg`
 * skips dot-directories by default and drops matches on multi-root runs).
 *
 * WHAT THIS CANNOT CATCH (stated so its tick is not over-read — mirrors
 * test-orphan-inventory.php's own honesty section)
 * -----------------------------------------------------------------------
 *  - A dynamically-assembled action name (`'admin_' . $x`) — none exist in
 *    this shape today; if one appears, this guard under-reports it.
 *  - Mis-targeted-but-handled-elsewhere actions (see "HANDLED SIDE" above)
 *    — phase 2 if ever wanted.
 *  - Anything outside `appWeb/public_html` — native-app callers are a
 *    different transport (not literal `?action=` URLs) and are already
 *    covered, in the opposite direction, by test-orphan-inventory.php's
 *    APPLE/ANDROID buckets.
 *
 *   php tests/php/test-action-caller-coverage.php
 *
 * Exit status 0 = clean, 1 = at least one failure.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$root = dirname(__DIR__, 2);
$pub  = $root . '/appWeb/public_html';

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

echo "\nAction caller coverage guard — the caller-\u{2192}handler direction (epic #2008)\n\n";

/* =========================================================================
 * PART 0 — the corpus
 * ========================================================================= */

/**
 * Every `.php`/`.js` file under public_html, as absolute paths.
 * ⚠️ Plain RecursiveDirectoryIterator — never `rg`/shell-out (see header).
 *
 * @return array<int,string>
 */
function acCorpusFiles(string $pub): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pub, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile()) { continue; }
        $path = $f->getPathname();
        if (str_contains($path, '/vendor/')) { continue; }
        $ext = strtolower($f->getExtension());
        if ($ext !== 'php' && $ext !== 'js') { continue; }
        $out[] = $path;
    }
    sort($out);
    return $cache = $out;
}

/**
 * Comment-strip (HTML + block), NEWLINE-PRESERVING — blank each comment body
 * down to whitespace rather than deleting it, so every line number computed
 * against the stripped text still points at the real source line.
 *
 * ⚠️ This one is load-bearing, not decorative (rule #34's gotcha #1, paid
 * for again during this guard's own mutation-proofing): a first draft of
 * this function used a plain empty-string replacement, which deletes the
 * comment's newlines along with its text. Every check in this file computes
 * `$loc` from `explode("\n", acStripComments($raw))`, so that shifted every
 * reported line number below any multi-line comment by however many lines
 * the comment spanned — mutation test 4 (delete the real add-pattern
 * handler branch in manage/external-link-types.php) caught it immediately:
 * the failure named lines 472/629 while the real markup sat at 561/726, an
 * 89-line comment's worth of drift. Fixed here; kept as a doc-comment
 * warning rather than deleted quietly, per this codebase's own precedent
 * (test-orphan-inventory.php's word-boundary-not-str_contains() note is the
 * same shape of "leave the scar visible"). */
function acStripComments(string $s): string
{
    $s = (string)preg_replace_callback('/<!--.*?-->/s', static fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), $s);
    $s = (string)preg_replace_callback('/\/\*.*?\*\//s', static fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), $s);
    return $s;
}

/* =========================================================================
 * PART 1 — HANDLED side: union of every dispatch surface's names
 * ========================================================================= */

/**
 * @return array{names:array<string,array<int,string>>, surfaces:array<int,string>}
 */
function acHandledIndex(string $pub, string $root): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $surfaces = dispatchParserDiscoverSurfaces($pub);
    $names = [];
    foreach ($surfaces as $abs) {
        $rel = substr($abs, strlen($root) + 1);
        foreach (dispatchParserActionsForFile($abs)['names'] as $n) { $names[$n][] = $rel; }
        foreach (dispatchParserMapKeys($abs) as $n) { $names[$n][] = $rel; }
    }
    return $cache = [
        'names'    => $names,
        'surfaces' => array_map(static fn ($a) => substr($a, strlen($root) + 1), $surfaces),
    ];
}

/* =========================================================================
 * PART 2 — CLIENT-EMITTED action names (the four high-precision shapes)
 * ========================================================================= */

/**
 * @return array<string,array<int,string>> action name => ["file:line", ...]
 */
function acEmittedActions(array $files, string $root): array
{
    $emit = [];
    $add = static function (string $name, string $loc) use (&$emit): void {
        $emit[$name][] = $loc;
    };

    foreach ($files as $abs) {
        $rel = substr($abs, strlen($root) + 1);
        $raw = (string)file_get_contents($abs);
        $src = acStripComments($raw);
        $lines = explode("\n", $src);

        foreach ($lines as $i => $line) {
            $loc = "{$rel}:" . ($i + 1);

            /* Shape 1 — ?action=name / &action=name in a URL literal. */
            if (preg_match_all('/[?&](?:amp;)?action=([A-Za-z0-9_.\-]+)/', $line, $m)) {
                foreach ($m[1] as $n) { $add($n, $loc); }
            }
            /* Shape 2 — <input|button name="action" value="name">, both orders. */
            if (preg_match_all('/name=(["\'])action\1[^>]{0,200}?value=(["\'])([A-Za-z0-9_.\-]+)\2/i', $line, $m)) {
                foreach ($m[3] as $n) { $add($n, $loc); }
            }
            if (preg_match_all('/value=(["\'])([A-Za-z0-9_.\-]+)\1[^>]{0,200}?name=(["\'])action\3/i', $line, $m)) {
                foreach ($m[2] as $n) { $add($n, $loc); }
            }
            /* Shape 3 — FormData/URLSearchParams .append('action','name') / .set(...). */
            if (preg_match_all('/\.(?:append|set)\(\s*[\'"]action[\'"]\s*,\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/i', $line, $m)) {
                foreach ($m[1] as $n) { $add($n, $loc); }
            }
        }

        /* Shape 4 — action: 'name' INSIDE a JSON.stringify({ … }) call ONLY
           (see the file-header note on why this is not a bare `action:` key
           match). Scanned across the whole file text rather than per-line
           because the object literal commonly spans several lines; bounded
           to a 400-char window after the opening `{` so this cannot spill
           into an unrelated, much-later `action:` mention in the same file
           (the #1701/rule-#34 "window too narrow / too wide" lesson —
           400 chars comfortably covers this codebase's POST-body object
           literals, which are short). */
        if (preg_match_all('/JSON\.stringify\(\s*\{/', $src, $sm, PREG_OFFSET_CAPTURE)) {
            foreach ($sm[0] as [, $offset]) {
                $window = substr($src, $offset, 400);
                if (preg_match('/\baction\s*:\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/', $window, $am)) {
                    $lineNo = substr_count($src, "\n", 0, $offset) + 1;
                    $add($am[1], "{$rel}:{$lineNo}");
                }
            }
        }
    }
    return $emit;
}

/* =========================================================================
 * PART 3 — data-action VALUE contract (secondary assertion)
 * ========================================================================= */

/**
 * @return array{emitted:array<string,array<int,string>>, handled:array<string,bool>}
 */
function acDataActionIndex(array $files, string $root): array
{
    $emitted = [];
    $handledFromGenericReaders = [];

    foreach ($files as $abs) {
        $rel = substr($abs, strlen($root) + 1);
        $raw = (string)file_get_contents($abs);
        $src = acStripComments($raw);

        $hasGenericRead = (bool)preg_match('/getAttribute\(\s*[\'"`]data-action[\'"`]\s*\)/', $src)
            || (bool)preg_match('/\.dataset\.action\b/', $src);

        $lines = explode("\n", $src);
        foreach ($lines as $i => $line) {
            $loc = "{$rel}:" . ($i + 1);
            /* Emitted: data-action="v" markup + dataset.action = 'v' writes. */
            if (preg_match_all('/data-action\s*=\s*\\\\?["\']([A-Za-z0-9_:.\\-]+)\\\\?["\']/i', $line, $m)) {
                foreach ($m[1] as $v) { $emitted[$v][] = $loc; }
            }
            if (preg_match_all('/\.dataset\.action\s*=\s*[\'"]([A-Za-z0-9_:.\\-]+)[\'"]/', $line, $m)) {
                foreach ($m[1] as $v) { $emitted[$v][] = $loc; }
            }
            /* Narrow handled shapes: direct comparisons + selector + case. */
            if (preg_match_all('/\.dataset\.action\s*===?\s*[\'"]([A-Za-z0-9_:.\\-]+)[\'"]/', $line, $m)) {
                foreach ($m[1] as $v) { $handledFromGenericReaders[$v] = true; }
            }
            if (preg_match_all('/getAttribute\(\s*[\'"`]data-action[\'"`]\s*\)\s*===?\s*[\'"]([A-Za-z0-9_:.\\-]+)[\'"]/', $line, $m)) {
                foreach ($m[1] as $v) { $handledFromGenericReaders[$v] = true; }
            }
            if (preg_match_all('/\[data-action\s*=\s*\\\\?["\']?([A-Za-z0-9_:.\\-]+)\\\\?["\']?\]/i', $line, $m)) {
                foreach ($m[1] as $v) { $handledFromGenericReaders[$v] = true; }
            }
        }

        /* Suppression (see file header): a file that reads data-action
           GENERICALLY has every string-literal comparison/case anywhere in
           it counted as a handled value — catches the read-into-a-variable-
           first shape without needing to trace the variable. */
        if ($hasGenericRead) {
            if (preg_match_all('/===?\s*[\'"]([A-Za-z0-9_:.\\-]+)[\'"]/', $src, $m)) {
                foreach ($m[1] as $v) { $handledFromGenericReaders[$v] = true; }
            }
            if (preg_match_all('/case\s+[\'"]([A-Za-z0-9_:.\\-]+)[\'"]\s*:/', $src, $m)) {
                foreach ($m[1] as $v) { $handledFromGenericReaders[$v] = true; }
            }
        }
    }

    return ['emitted' => $emitted, 'handled' => $handledFromGenericReaders];
}

/* =========================================================================
 * RUN
 * ========================================================================= */

$files   = acCorpusFiles($pub);
$handled = acHandledIndex($pub, $root);
$emitted = acEmittedActions($files, $root);
$da      = acDataActionIndex($files, $root);

echo "  Derived: " . count($files) . " corpus files, " . count($handled['surfaces'])
   . " dispatch surfaces, " . count($handled['names']) . " handled action names, "
   . count($emitted) . " client-emitted action names, " . count($da['emitted']) . " data-action values emitted\n\n";

/* --- sanity of the derive pass — if these are wrong, everything below is
   meaningless (rule #34). --------------------------------------------- */
ok('corpus is a plausible size (>= 400 files under public_html)', count($files) >= 400,
    'got ' . count($files));
ok('dispatch surfaces were auto-discovered (>= 20 found)', count($handled['surfaces']) >= 20,
    'got ' . count($handled['surfaces']));
ok('handled action names cross a plausible floor (>= 300, incl. map-keyed dispatch)',
    count($handled['names']) >= 300, 'got ' . count($handled['names']));
ok('client-emitted ?action=/form/FormData/JSON.stringify names cross a plausible floor (>= 150)',
    count($emitted) >= 150, 'got ' . count($emitted));
ok('data-action values were found to check (>= 10)', count($da['emitted']) >= 10,
    'got ' . count($da['emitted']));

/* Positive control — the map-keys extension is load-bearing: without it,
   the six installer-only + every migration-registry slug action name would
   report as caller-less (they only ever appear via $scriptMap[$action], not
   a switch/if/in_array). This is checked structurally here (not just by the
   mutation test below) so a regression in dispatchParserMapKeys() itself,
   not just its removal, is caught. */
foreach (['users', 'cleanup', 'drop-legacy'] as $must) {
    ok("control: '{$must}' is a handled action name (map-keyed dispatch in manage/setup-database.php)",
        isset($handled['names'][$must]));
}

/* =========================================================================
 * CHECK 1 — every client-emitted action name is handled SOMEWHERE
 * ========================================================================= */

echo "\n";
$unhandled = [];
foreach ($emitted as $name => $locs) {
    if (isset($handled['names'][$name])) { continue; }
    $unhandled[$name] = array_slice(array_unique($locs), 0, 3);
}
ksort($unhandled);
ok('CHECK 1 — every client-emitted action name is handled by at least one dispatch surface ('
    . count($unhandled) . ' unhandled of ' . count($emitted) . ' emitted)',
    $unhandled === [],
    $unhandled === [] ? '' :
    "These action names are emitted by a client (URL literal, form control, FormData/\n"
    . "URLSearchParams, or a JSON.stringify() body) but no dispatch surface answers to them —\n"
    . "the request would silently do nothing:\n  - "
    . implode("\n  - ", array_map(static fn ($n, $l) => "{$n} <- " . implode(', ', $l), array_keys($unhandled), $unhandled)));

/* =========================================================================
 * CHECK 2 — every emitted data-action value is handled somewhere
 * ========================================================================= */

$daUnhandled = [];
foreach ($da['emitted'] as $v => $locs) {
    if (isset($da['handled'][$v])) { continue; }
    $daUnhandled[$v] = array_slice(array_unique($locs), 0, 3);
}
ksort($daUnhandled);
ok('CHECK 2 — every emitted data-action value has a matching JS comparison/case/selector ('
    . count($daUnhandled) . ' unhandled of ' . count($da['emitted']) . ' emitted)',
    $daUnhandled === [],
    $daUnhandled === [] ? '' :
    "These data-action values are emitted in markup but no JS comparison/case/selector in the\n"
    . "corpus (nor a generic-data-action-reading file's comparison literal) ever matches them:\n  - "
    . implode("\n  - ", array_map(static fn ($v, $l) => "{$v} <- " . implode(', ', $l), array_keys($daUnhandled), $daUnhandled)));

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run every invocation, in memory only.
 * ========================================================================= */

echo "\n  Mutation self-tests (the guard proving it can still fail):\n";

/* M1 — an injected client-emitted action name with no handler must be caught. */
$m1Emitted = $emitted;
$m1Emitted['zz_action_ghost'] = ['synthetic:1'];
$m1Unhandled = [];
foreach ($m1Emitted as $name => $locs) { if (!isset($handled['names'][$name])) { $m1Unhandled[$name] = true; } }
ok('M1: injecting a client-emitted action name nothing handles makes CHECK 1 flag it',
    isset($m1Unhandled['zz_action_ghost']));

/* M2 — removing the map-keys extension's contribution must orphan the
   installer-only action names (they exist ONLY via $scriptMap[$action]). */
$m2NamesWithoutMapKeys = [];
foreach ($handled['surfaces'] as $rel) {
    foreach (dispatchParserActionsForFile($root . '/' . $rel)['names'] as $n) { $m2NamesWithoutMapKeys[$n] = true; }
    /* deliberately NOT calling dispatchParserMapKeys() here */
}
$m2Missing = array_diff(['users', 'cleanup', 'drop-legacy', 'account-sync'], array_keys($m2NamesWithoutMapKeys));
ok('M2: removing the map-keys extension makes users/cleanup/drop-legacy/account-sync unhandled '
    . '(proves dispatchParserMapKeys() is load-bearing, not dead)',
    count($m2Missing) === 4, 'still handled without map-keys: ' . implode(', ', array_diff(['users', 'cleanup', 'drop-legacy', 'account-sync'], $m2Missing)));

/* M3 — the data-action suppression really requires BOTH conditions: delete
   the generic-read flag's contribution for a file and its variable-compared
   values must go unhandled. external-link-types.php's 'add-pattern' is the
   real instance: it is NEVER a direct .dataset.action/getAttribute(...)===
   comparison, only reachable via the generic-reader suppression. */
$elt = $root . '/appWeb/public_html/manage/external-link-types.php';
$m3Ok = false;
if (is_file($elt)) {
    $eltSrc = acStripComments((string)file_get_contents($elt));
    $narrowHandled = (bool)preg_match("/\\.dataset\\.action\\s*===?\\s*['\"]add-pattern['\"]/", $eltSrc)
        || (bool)preg_match("/getAttribute\\(\\s*['\"`]data-action['\"`]\\s*\\)\\s*===?\\s*['\"]add-pattern['\"]/", $eltSrc);
    $m3Ok = !$narrowHandled && isset($da['handled']['add-pattern']);
}
ok("M3: 'add-pattern' (external-link-types.php) is reachable ONLY via the generic-reader "
    . 'suppression, not a narrow direct comparison — proves the suppression is load-bearing',
    $m3Ok);

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nA client that emits an action name nothing handles is a button/link/fetch that\n";
    echo "silently does nothing — no error, no console output, the request just goes nowhere\n";
    echo "useful. Wire a handler, fix the typo, or fix the caller.\n";
    exit(1);
}
echo "\nEvery client-emitted action name and data-action value has a handler.\n";
