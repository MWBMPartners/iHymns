<?php

declare(strict_types=1);

/**
 * iHymns — Works registry-picker guard (#1864, epic #1863 / rule #43)
 * =============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The Works form's Tune Name and Copyright Holder fields used to be blind
 * free-text boxes that minted duplicate registry rows on every typo. #1864
 * wired them to the shared live-search picker instead. This guard locks the
 * shape of that fix in place so nothing quietly re-forks it later:
 *   - the publisher search SQL lives in ONE place (publisher_helpers.php),
 *     not copy-pasted into a third `?action=publisher_search` handler;
 *   - the Copyright Holder save path writes the registry FK through the
 *     ONE trust-but-verify resolver, never a raw client-supplied id;
 *   - `INSERT INTO tblPublishers` never appears outside the two funnels
 *     that are allowed to mint a publisher row;
 *   - the client wiring actually attaches all four pickers with
 *     `pickMode: 'value'`, reuses the EXISTING api2 tune_search endpoint
 *     (never a local fork), and the `copyright_holder_id` field is both
 *     emitted by the markup AND read by the PHP handler (rule #33).
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * ----------------------------------------------------------------------------
 * §1 (declaration count) and §4 (INSERT confinement) scan the actual source
 * tree for the real fingerprint of a fork — a SECOND `function
 * publisherSearchRows(` or a SECOND `INSERT INTO tblPublishers` — rather
 * than trusting a fixed list of "the files that are supposed to have it".
 * §2 walks every `manage/*.php` file that declares a `publisher_search`
 * action gate, so a FOURTH inline fork on some future admin page fails this
 * test automatically, the same way `test-tune-lockstep.php`'s write-site
 * sweep catches a future unwired `TuneName` writer.
 *
 * ⚠️ THE STRIPPER TRAP THIS FILE WAS WARNED ABOUT
 * ----------------------------------------------------------------------------
 * `manage/works.php`'s client-side wiring lives inside a plain `<script>`
 * tag, which — outside any `<?php … ?>` block — is a single `T_INLINE_HTML`
 * token to PHP's own tokenizer. A stripper that drops `T_INLINE_HTML` (the
 * `test-publisher-registry.php` `pubStrip()` shape) would delete the very
 * JS the wiring assertions (§5/§6) need to scan — so this file does NOT
 * do that. It keeps TWO independently-tested views instead:
 *   - `wrpStripPhpComments()` — tokenizer-based, strips ONLY
 *     `T_COMMENT`/`T_DOC_COMMENT`, keeps `T_INLINE_HTML` raw. Used for PHP
 *     code assertions (§1, §3, §4) where a comment sitting next to a real
 *     call (this file's OWN annotations do that deliberately, e.g. the
 *     `$persistWorkExtraFields` doc-comment names `publisherResolvePickedOrCreate()`
 *     right beside the real call) must not let a deleted call stay
 *     wrong-but-green.
 *   - `wrpStripAllComments()` — regex-based, strips `/* … *\/`, `//`, and
 *     `<!-- … -->` from the WHOLE raw file (the `test-fragment-inline-
 *     scripts.php` `stripCommentsPreservingLines()` precedent), so JS/HTML
 *     comments disappear too. This is LOAD-BEARING for §7: works.php's own
 *     attach-block doc-comment literally contains the prose "no local
 *     `FROM tblTunes` search is ever added here" — a raw, unstripped scan
 *     for that exact SQL fragment would false-positive on the very sentence
 *     that documents its absence. Proven by fixture below (needle
 *     `JsCommentNeedle`), not asserted on faith.
 *
 * MUTATION-TESTING PROTOCOL (rule #34): every pure helper below is proven
 * in BOTH directions (finds the real thing / does not false-positive) against
 * small in-memory fixtures, run FIRST, before the real assertions. A guard
 * that has never been proven able to fail is not trustworthy.
 *
 * Pure source-tree scan — no DB connection needed.
 *
 *   php tests/php/test-work-registry-pickers.php
 *
 * Exit status 0 = clean, 1 = at least one violation or a mutation self-test
 * failed to go red.
 *
 * @see appWeb/public_html/includes/publisher_helpers.php  publisherSearchRows(), publisherResolvePickedOrCreate()
 * @see appWeb/public_html/manage/works.php                 the create+edit form, the two hidden-id pairs, the attach() wiring
 * @see appWeb/public_html/manage/publishers.php             delegates to publisherSearchRows() (was the superset fork)
 * @see appWeb/public_html/manage/songbooks.php              delegates to publisherSearchRows() (kept its pre-migration note)
 * @see tests/php/test-tune-lockstep.php                     the sibling guard this mirrors in shape
 * @see /tmp/.../pickers-1864-spec.md                        the implementation spec this proves
 * @see #1864, epic #1863
 */

$repoRoot = dirname(__DIR__, 2);
$web      = $repoRoot . '/appWeb/public_html';

/* =========================================================================
 * PURE HELPER FUNCTIONS — no file I/O, no globals.
 * ========================================================================= */

/**
 * PHP-comment-stripped view: drops `T_COMMENT`/`T_DOC_COMMENT` tokens
 * (replaced by their own newline count, so line numbers survive), keeps
 * EVERYTHING else — including `T_INLINE_HTML` — byte-for-byte. Mirrors
 * `test-tune-lockstep.php`'s `ttlStripComments()` exactly.
 */
function wrpStripPhpComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/**
 * Whole-file comment-stripped view: blanks `/* … *\/`, `// …`, and
 * `<!-- … -->` wherever they occur in the RAW source — PHP region or plain
 * HTML/JS region alike — preserving newline counts. Mirrors
 * `test-fragment-inline-scripts.php`'s `stripCommentsPreservingLines()`,
 * extended with a `//` pass (JS line comments) since this file's wiring
 * assertions need to see inside a `<script>` block cleanly.
 */
function wrpStripAllComments(string $src): string
{
    $src = preg_replace_callback('/<!--.*?-->/s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $src);
    $src = preg_replace_callback('~/\*.*?\*/~s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $src);
    $src = preg_replace('~//[^\n]*~', '', $src);
    return $src;
}

/**
 * Slice the `if (...) { ... }` block whose CONDITION contains
 * `$conditionNeedle` — a proper BRACE-COUNTING scan (never a bounded regex
 * window, rule #34's #1676 lesson), so it is robust to how deeply the
 * handler body itself nests braces.
 *
 * Algorithm: find every `if (` occurring before the needle, take the
 * NEAREST one (the needle's own enclosing condition can't be opened by an
 * `if` that already closed earlier in the file); then brace-count forward
 * from the first `{` at-or-after the needle to its match.
 */
function wrpSliceIfBlockByCondition(string $src, string $conditionNeedle): string
{
    $needlePos = strpos($src, $conditionNeedle);
    if ($needlePos === false) { return ''; }

    $before = substr($src, 0, $needlePos);
    if (!preg_match_all('/\bif\s*\(/', $before, $m, PREG_OFFSET_CAPTURE) || !$m[0]) {
        return '';
    }
    $last  = end($m[0]);
    $ifPos = $last[1];

    $bracePos = strpos($src, '{', $needlePos);
    if ($bracePos === false) { return ''; }

    $depth = 0;
    $len   = strlen($src);
    for ($j = $bracePos; $j < $len; $j++) {
        if ($src[$j] === '{') {
            $depth++;
        } elseif ($src[$j] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $ifPos, $j - $ifPos + 1);
            }
        }
    }
    return substr($src, $ifPos);
}

/**
 * Slice a `$varName = static function (...) { ... };` closure assignment —
 * same brace-counting approach as `wrpSliceIfBlockByCondition()`, anchored
 * on the `$varName = static function` text instead of an `if` condition.
 */
function wrpSliceAssignedClosure(string $src, string $varName): string
{
    $pos = strpos($src, '$' . $varName . ' = static function');
    if ($pos === false) { return ''; }

    $bracePos = strpos($src, '{', $pos);
    if ($bracePos === false) { return ''; }

    $depth = 0;
    $len   = strlen($src);
    for ($j = $bracePos; $j < $len; $j++) {
        if ($src[$j] === '{') {
            $depth++;
        } elseif ($src[$j] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $pos, $j - $pos + 1);
            }
        }
    }
    return substr($src, $pos);
}

/**
 * Slice a named top-level PHP `function NAME(...) { ... }` region — from
 * `function NAME(` to the next top-level (column-0) `function ` declaration,
 * or end of file. Identical shape to `test-tune-lockstep.php`'s
 * `ttlSliceFunction()` (this file's own prefix: `wrp`).
 */
function wrpSliceFunctionDecl(string $src, string $name): string
{
    if (!preg_match(
        '/^function\s+' . preg_quote($name, '/') . '\s*\(.*?(?=\n^function\s|\z)/ms',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * DERIVED write-site sweep: scan every `.php` file under $dir (recursive)
 * for a literal string, using the PHP-comment-stripped view so a mention
 * only inside a comment can't count. Same shape as `test-tune-lockstep.php`'s
 * `ttlFindTuneNameWriteSites()`.
 *
 * @return list<string> paths (relative to $dir, '/'-separated) containing
 *                        the literal outside a comment.
 */
function wrpFindLiteralSites(string $dir, string $literal): array
{
    $hits = [];
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        $path = $file->getPathname();
        $stripped = wrpStripPhpComments((string)file_get_contents($path));
        if (strpos($stripped, $literal) !== false) {
            $rel = str_replace($dir . DIRECTORY_SEPARATOR, '', $path);
            $hits[] = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        }
    }
    sort($hits);
    return $hits;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory.
 * ========================================================================= */

$mutationFailures = [];

/* --- wrpStripPhpComments(): code survives, PHP comments vanish, inline
   HTML/JS (incl. its OWN comments) is preserved raw. --- */
$fixtureA =
    "<?php\n// LineCommentNeedle\n\$x = 'CodeNeedle';\n/* BlockCommentNeedle */\n?>\n"
  . "HTML <!-- HtmlCommentNeedle --> <script>/* JsCommentNeedle */ var y = 'JsCodeNeedle';</script>\n"
  . "<?php\n\$z = 'MoreCodeNeedle';\n";
$strippedA = wrpStripPhpComments($fixtureA);
foreach (['CodeNeedle', 'MoreCodeNeedle', 'HtmlCommentNeedle', 'JsCommentNeedle', 'JsCodeNeedle'] as $needle) {
    if (strpos($strippedA, $needle) === false) {
        $mutationFailures[] = "wrpStripPhpComments() FAILS-HIGH self-test: '{$needle}' should have survived (code or inline-HTML) but was removed";
    }
}
foreach (['LineCommentNeedle', 'BlockCommentNeedle'] as $needle) {
    if (strpos($strippedA, $needle) !== false) {
        $mutationFailures[] = "wrpStripPhpComments() FAILS-LOW self-test: PHP comment needle '{$needle}' should have been stripped but survived";
    }
}

/* --- wrpStripAllComments(): code survives, EVERY comment kind vanishes —
   including one living inside a <script> block, the exact §7/D1 trap. --- */
$fixtureB =
    "<?php\n// LineCommentNeedle\n\$x = 'CodeNeedle';\n/* BlockCommentNeedle */\n?>\n"
  . "HTML <!-- HtmlCommentNeedle --> <script>/* JsCommentNeedle */ var y = 'JsCodeNeedle'; // TrailingJsCommentNeedle\n</script>\n";
$strippedB = wrpStripAllComments($fixtureB);
foreach (['CodeNeedle', 'JsCodeNeedle'] as $needle) {
    if (strpos($strippedB, $needle) === false) {
        $mutationFailures[] = "wrpStripAllComments() FAILS-HIGH self-test: code needle '{$needle}' should have survived but was removed";
    }
}
foreach (['LineCommentNeedle', 'BlockCommentNeedle', 'HtmlCommentNeedle', 'JsCommentNeedle', 'TrailingJsCommentNeedle'] as $needle) {
    if (strpos($strippedB, $needle) !== false) {
        $mutationFailures[] = "wrpStripAllComments() FAILS-LOW self-test: comment needle '{$needle}' should have been stripped but survived — this is the exact D1/§7 stripper-trap case";
    }
}

/* --- wrpSliceIfBlockByCondition(): finds the block, brace-counts through
   a nested if, and does not bleed into what comes after the closing brace. --- */
$fixtureC = "before NeedleBefore\n"
  . "if (\$a\n    && \$b === 'needle_action'\n) {\n"
  . "    if (true) { echo 'InnerNeedle'; }\n"
  . "    echo 'OuterNeedle';\n"
  . "}\n"
  . "after NeedleAfter\n";
$sliceC = wrpSliceIfBlockByCondition($fixtureC, "'needle_action'");
foreach (['InnerNeedle', 'OuterNeedle'] as $needle) {
    if (strpos($sliceC, $needle) === false) {
        $mutationFailures[] = "wrpSliceIfBlockByCondition() FAILS-HIGH self-test did not find '{$needle}' inside its own fixture block (nested-brace handling)";
    }
}
foreach (['NeedleBefore', 'NeedleAfter'] as $needle) {
    if (strpos($sliceC, $needle) !== false) {
        $mutationFailures[] = "wrpSliceIfBlockByCondition() FAILS-LOW self-test: slice bled outside its own block ('{$needle}' leaked in)";
    }
}
if (wrpSliceIfBlockByCondition($fixtureC, "'no_such_action'") !== '') {
    $mutationFailures[] = 'wrpSliceIfBlockByCondition() FAILS-LOW self-test: a non-existent condition needle should return an empty slice';
}

/* --- wrpSliceAssignedClosure(): finds the named closure only, not a
   sibling one declared right after it. --- */
$fixtureD = "\$foo = static function (\$x) {\n    if (\$x) { echo 'NeedleInFoo'; }\n};\n"
  . "\$bar = static function (\$y) {\n    echo 'NeedleInBar';\n};\n";
$sliceD = wrpSliceAssignedClosure($fixtureD, 'foo');
if (strpos($sliceD, 'NeedleInFoo') === false) {
    $mutationFailures[] = "wrpSliceAssignedClosure() FAILS-HIGH self-test did not find 'NeedleInFoo' inside its own fixture closure";
}
if (strpos($sliceD, 'NeedleInBar') !== false) {
    $mutationFailures[] = "wrpSliceAssignedClosure() FAILS-LOW self-test: \$foo's slice bled into \$bar's closure";
}

/* --- wrpSliceFunctionDecl(): same alpha/beta shape as test-tune-lockstep.php. --- */
$fixtureE = "function alpha(): array\n{\n    \$cols = ['NeedleInAlpha'];\n    return \$cols;\n}\n\nfunction beta(): array\n{\n    \$cols = ['NeedleInBeta'];\n    return \$cols;\n}\n";
$sliceE = wrpSliceFunctionDecl($fixtureE, 'alpha');
if (strpos($sliceE, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'wrpSliceFunctionDecl() FAILS-HIGH self-test did not find the needle inside its own fixture function';
}
if (strpos($sliceE, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "wrpSliceFunctionDecl() FAILS-LOW self-test: alpha()'s slice bled into beta()";
}

/* --- wrpFindLiteralSites(): recurses, comment-strips, and reports paths
   relative to $dir. Proves the exact §4/B2 mechanism the real assertion
   below relies on: a THIRD file writing the literal must be catchable. --- */
$fixtureDir = sys_get_temp_dir() . '/wrp_fixture_' . bin2hex(random_bytes(6));
mkdir($fixtureDir, 0777, true);
mkdir($fixtureDir . '/includes', 0777, true);
file_put_contents($fixtureDir . '/includes/allowed.php', "<?php\n\$db->query(\"INSERT INTO tblPublishers (Name) VALUES (?)\");\n");
file_put_contents($fixtureDir . '/rogue-fork.php', "<?php\n\$db->query(\"INSERT INTO tblPublishers (Name) VALUES (?)\");\n");
file_put_contents($fixtureDir . '/commented-only.php', "<?php\n// INSERT INTO tblPublishers is mentioned here only in prose\necho 'nothing';\n");
file_put_contents($fixtureDir . '/unrelated.php', "<?php\necho 'nothing to see here';\n");
$fixtureHits = wrpFindLiteralSites($fixtureDir, 'INSERT INTO tblPublishers');
if (!in_array('includes/allowed.php', $fixtureHits, true)) {
    $mutationFailures[] = 'wrpFindLiteralSites() FAILS-HIGH self-test did not find the real INSERT in the allowed fixture file (recursion into a subdirectory)';
}
if (!in_array('rogue-fork.php', $fixtureHits, true)) {
    $mutationFailures[] = 'wrpFindLiteralSites() FAILS-HIGH self-test did not find the real INSERT in the rogue-fork fixture file — this is the exact violation §4/B2 must catch';
}
if (in_array('commented-only.php', $fixtureHits, true)) {
    $mutationFailures[] = 'wrpFindLiteralSites() FAILS-LOW self-test wrongly flagged a fixture where the literal only appears inside a comment';
}
if (in_array('unrelated.php', $fixtureHits, true)) {
    $mutationFailures[] = 'wrpFindLiteralSites() FAILS-LOW self-test wrongly flagged an unrelated fixture file';
}
@unlink($fixtureDir . '/includes/allowed.php');
@unlink($fixtureDir . '/rogue-fork.php');
@unlink($fixtureDir . '/commented-only.php');
@unlink($fixtureDir . '/unrelated.php');
@rmdir($fixtureDir . '/includes');
@rmdir($fixtureDir);

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

$failures = [];

$helpersFile     = $web . '/includes/publisher_helpers.php';
$worksFile       = $web . '/manage/works.php';
$publishersFile  = $web . '/manage/publishers.php';
$songbooksFile   = $web . '/manage/songbooks.php';

foreach ([
    'includes/publisher_helpers.php' => $helpersFile,
    'manage/works.php'               => $worksFile,
    'manage/publishers.php'          => $publishersFile,
    'manage/songbooks.php'           => $songbooksFile,
] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read {$label} at {$path}\n");
        exit(1);
    }
}

$helpersRaw = (string)file_get_contents($helpersFile);
$worksRaw   = (string)file_get_contents($worksFile);

/* Two independently-tested views of works.php (see the file-level
   doc-block's "stripper trap" section) — never conflate them. */
$helpersPhpView = wrpStripPhpComments($helpersRaw);
$worksPhpView   = wrpStripPhpComments($worksRaw);
$worksAllView   = wrpStripAllComments($worksRaw);

/* ---- A1: publisherSearchRows() is declared EXACTLY ONCE, and only in
   includes/publisher_helpers.php. Tree-derived — a second copy anywhere
   under appWeb/public_html fails this automatically. ---- */
$declSites = [];
$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($web, \FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $stripped = wrpStripPhpComments((string)file_get_contents($file->getPathname()));
    if (preg_match('/function\s+publisherSearchRows\s*\(/', $stripped)) {
        $rel = str_replace($web . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $declSites[] = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    }
}
if (count($declSites) !== 1) {
    $failures[] = 'publisherSearchRows() is declared ' . count($declSites) . ' time(s) — expected exactly 1 (found: ' . implode(', ', $declSites) . ')';
} elseif ($declSites[0] !== 'includes/publisher_helpers.php') {
    $failures[] = "publisherSearchRows() is declared in {$declSites[0]}, not includes/publisher_helpers.php (rule #22 — the ONE shared core)";
}

/* ---- A2: every manage/*.php file with a `?action=publisher_search` gate
   delegates to publisherSearchRows() within that handler's OWN block, and
   none re-inlines the fork fingerprint (`FROM tblPublishers` + `LIKE`
   together in the same block). Tree-derived over manage/*.php (top-level,
   non-recursive — every existing + any future publisher_search handler
   lives directly under manage/). ---- */
$gateFiles = glob($web . '/manage/*.php') ?: [];
$gateHandlerCount = 0;
foreach ($gateFiles as $path) {
    $stripped = wrpStripPhpComments((string)file_get_contents($path));
    if (strpos($stripped, "=== 'publisher_search'") === false) { continue; }
    $gateHandlerCount++;
    $rel = 'manage/' . basename($path);
    $block = wrpSliceIfBlockByCondition($stripped, "'publisher_search'");
    if ($block === '') {
        $failures[] = "{$rel}: found a publisher_search action gate but could not slice its handler block (slicer or file shape changed)";
        continue;
    }
    if (strpos($block, 'publisherSearchRows(') === false) {
        $failures[] = "{$rel}'s publisher_search handler never calls publisherSearchRows() — it must delegate to the ONE shared core, not re-implement the query (rule #22)";
    }
    if (strpos($block, 'FROM tblPublishers') !== false && preg_match('/\bLIKE\b/', $block)) {
        $failures[] = "{$rel}'s publisher_search handler still contains its own \"FROM tblPublishers ... LIKE\" query — the inline-fork fingerprint the publisherSearchRows() extraction was meant to remove";
    }
}
if ($gateHandlerCount < 3) {
    $failures[] = "found only {$gateHandlerCount} manage/*.php file(s) with a publisher_search action gate — expected at least 3 (publishers.php, songbooks.php, works.php); the tree-derived sweep itself may be broken";
}

/* ---- B1: works.php's $persistWorkExtraFields closure writes
   CopyrightHolder and CopyrightHolderId TOGETHER, resolving the latter
   through publisherResolvePickedOrCreate() — the TuneName/TuneId lockstep
   shape (rule #37/#43), never a raw client-supplied id. PHP-comment-
   stripped view: this file's OWN doc-comment right above the write
   deliberately names publisherResolvePickedOrCreate() beside the real
   call (rule #34's wrong-but-green trap), so an unstripped scan would
   stay green even if the real call were deleted. ---- */
$persistBlock = wrpSliceAssignedClosure($worksPhpView, 'persistWorkExtraFields');
if ($persistBlock === '') {
    $failures[] = 'could not locate $persistWorkExtraFields = static function (...) { ... }; in manage/works.php';
} else {
    foreach (['CopyrightHolder = ?', 'CopyrightHolderId = ?', 'publisherResolvePickedOrCreate('] as $needle) {
        if (strpos($persistBlock, $needle) === false) {
            $failures[] = "\$persistWorkExtraFields no longer contains '{$needle}' — the CopyrightHolder<->CopyrightHolderId lockstep write is broken (#1864, rule #37/#43)";
        }
    }
}

/* ---- B2a: publisherResolvePickedOrCreate() itself falls back to
   publisherFindOrCreateByName() — the "otherwise" branch of the
   trust-but-verify contract (§1.4 of the spec). ---- */
$resolveFnSlice = wrpSliceFunctionDecl($helpersPhpView, 'publisherResolvePickedOrCreate');
if ($resolveFnSlice === '') {
    $failures[] = 'includes/publisher_helpers.php does not declare function publisherResolvePickedOrCreate()';
} elseif (strpos($resolveFnSlice, 'publisherFindOrCreateByName(') === false) {
    $failures[] = 'publisherResolvePickedOrCreate() no longer calls publisherFindOrCreateByName() — the create-fallback funnel is gone, or a second copy of it was inlined (rule #22)';
}

/* ---- B2b: INSERT INTO tblPublishers is confined to the two funnels
   (rule #37 — never a second create path). Tree-derived over the whole
   appWeb/public_html tree: a THIRD writer anywhere fails this
   automatically, proven catchable by the fixture mutation self-test above. ---- */
$insertSites = wrpFindLiteralSites($web, 'INSERT INTO tblPublishers');
$allowedInsertSites = ['includes/publisher_helpers.php', 'includes/publisher_admin.php'];
foreach ($insertSites as $site) {
    if (!in_array($site, $allowedInsertSites, true)) {
        $failures[] = "{$site} contains \"INSERT INTO tblPublishers\" outside the two allowed funnels (includes/publisher_helpers.php, includes/publisher_admin.php) — a second create path (rule #37)";
    }
}
if (!$insertSites) {
    $failures[] = 'wrpFindLiteralSites() found zero "INSERT INTO tblPublishers" sites under appWeb/public_html — the sweep itself may be broken (publisher_helpers.php should always match)';
}

/* ---- C1: works.php's client wiring attaches all FOUR pickers with
   pickMode:'value'. All-comments-stripped view: robust to the exact
   column alignment/whitespace between the id-pair literals. ---- */
$idPairs = [
    ["'create-work-tune-name'", "'create-work-tune-id'"],
    ["'edit-work-tune-name'", "'edit-work-tune-id'"],
    ["'create-work-copyright-holder'", "'create-work-copyright-holder-id'"],
    ["'edit-work-copyright-holder'", "'edit-work-copyright-holder-id'"],
];
foreach ($idPairs as [$inputLit, $hiddenLit]) {
    $pattern = '/' . preg_quote($inputLit, '/') . '\s*,\s*' . preg_quote($hiddenLit, '/') . '/';
    if (!preg_match($pattern, $worksAllView)) {
        $failures[] = "works.php's attach() wiring array is missing the pair [{$inputLit}, {$hiddenLit}] — one of the four #1864 pickers is not wired";
    }
}
if (strpos($worksAllView, 'iHymnsPlaceSearch.attach') === false) {
    $failures[] = 'works.php no longer calls window.iHymnsPlaceSearch.attach(...) — the four #1864 pickers are not wired to the shared typeahead';
}
if (substr_count($worksAllView, "pickMode: 'value'") < 2) {
    $failures[] = "works.php's picker option factories no longer set pickMode: 'value' (found fewer than 2 occurrences) — a picker default that isn't 'value' would try to network-upsert on pick, which no #1864 field wants";
}

/* ---- C2: the client wiring reuses the EXISTING api2 tune_search action
   and this page's OWN publisher_search action — never a fabricated
   endpoint. ---- */
if (strpos($worksAllView, 'action=tune_search') === false) {
    $failures[] = "works.php's Tune picker no longer points at ?action=tune_search — it must reuse the existing manage/editor/api2.php endpoint (rule #22)";
}
if (strpos($worksAllView, 'action=publisher_search') === false) {
    $failures[] = "works.php's Copyright Holder picker no longer points at ?action=publisher_search";
}

/* ---- D1: works.php contains NO local fork of the tune search (no
   `case 'tune_search':`, no `FROM tblTunes` SQL) — it must always reuse
   api2's endpoint (§1.3 of the spec: "no works-local tune_search handler").
   MUST use the all-comments-stripped view: works.php's own attach-block
   doc-comment contains the literal prose "no local `FROM tblTunes` search
   is ever added here", which a PHP-comment-only stripper would leave
   behind (T_INLINE_HTML is untouched by wrpStripPhpComments) and falsely
   trip this exact check — the trap named in this file's own doc-block. ---- */
if (preg_match('/case\s+\'tune_search\'\s*:/', $worksAllView)) {
    $failures[] = "works.php declares its own case 'tune_search': — a local fork of api2's tune search (rule #22 forbids this; the Tune picker must reuse the EXISTING endpoint)";
}
if (strpos($worksAllView, 'FROM tblTunes') !== false) {
    $failures[] = 'works.php contains a "FROM tblTunes" SQL fragment outside a comment — a local fork of the tune search (rule #22)';
}

/* ---- E1: the copyright_holder_id wire contract is honoured at BOTH ends
   (rule #33) — the markup EMITS name="copyright_holder_id" (create + edit)
   and the PHP handler READS $post['copyright_holder_id'] (the
   $parseWorkExtraFields closure parameter, called with $_POST at both the
   create and update call sites). Raw markup (not comment-stripped — an
   HTML attribute is never inside a comment in the real file) + the
   PHP-comment-stripped view for the read side. ---- */
if (substr_count($worksRaw, 'name="copyright_holder_id"') < 2) {
    $failures[] = 'works.php emits name="copyright_holder_id" fewer than 2 times (expected at least 2: the create form + the edit modal) — the picker\'s hidden id is not wired into both forms';
}
if (!preg_match('/\$(?:_POST|post)\[\s*\'copyright_holder_id\'\s*\]/', $worksPhpView)) {
    $failures[] = 'works.php never reads $post[\'copyright_holder_id\'] (or $_POST[...]) — the markup emits the field but nothing consumes it server-side (rule #33: a param nobody reads)';
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red/green as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n\n");
    }
    if ($failures) {
        fwrite(STDERR, "FAIL: Works registry-picker guard (#1864):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}

echo "PASS: Works registry-picker guard — publisherSearchRows() declared exactly once in "
   . "includes/publisher_helpers.php; all {$gateHandlerCount} manage/*.php publisher_search handlers "
   . "delegate to it with no re-inlined fork; \$persistWorkExtraFields writes CopyrightHolder + "
   . "CopyrightHolderId in lockstep via publisherResolvePickedOrCreate(), which falls back to "
   . "publisherFindOrCreateByName(); \"INSERT INTO tblPublishers\" is confined to the two funnel files ("
   . count($insertSites) . " site(s) found); works.php's client wiring attaches all 4 #1864 pickers "
   . "with pickMode:'value', reuses api2's tune_search (no local fork) and this page's own "
   . "publisher_search; the copyright_holder_id wire contract is honoured at both ends. All mutation "
   . "self-tests went red/green as expected.\n";
exit(0);
