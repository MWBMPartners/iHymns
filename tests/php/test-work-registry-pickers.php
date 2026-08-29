<?php

declare(strict_types=1);

/**
 * iHymns — Works + Songbook + Groups/Publishers/Catalogues/Requests
 * registry-picker guard (#1864/#1865/#1868/#1866/#1867, epic #1863 / rule #43)
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
 * §F below extends the same guard to #1865's Songbook Publisher picker (the
 * create form's quick Publisher field): the create-save path resolves it
 * through the SAME `publisherResolvePickedOrCreate()` funnel #1864 built
 * (never a raw client id, never a direct `publisherFindOrCreateByName()`
 * bypass), seeds `tblSongbookPublishers` (never a second registry-create
 * path), and the `publisher_id` hidden field is honoured at both ends
 * (rule #33). Reuses this file's EXISTING `wrp*` helpers rather than
 * duplicating them into a new file — one guard mechanism, two features.
 *
 * §G below extends the same guard to #1868's TWO search-select-ONLY
 * surfaces — `manage/groups.php`'s add-a-member picker and
 * `manage/publishers.php`'s merge-target picker. Unlike #1864/#1865, NEITHER
 * of these has a create arm (rule #43's spec explicitly forbids one here —
 * this workflow must never invent a user or a merge target), so §G's shape
 * is the mirror image of §A-F: it proves the ABSENCE of a create/find-or-
 * create call on both paths, the PRESENCE of a server-side existence check
 * before the id is used, and — for the merge-target specifically — that its
 * search reuses `publisherSearchRows()`'s `excludeId` arm bound to whichever
 * publisher is currently chosen as Source, so a self-merge is unreachable
 * through the UI, not merely rejected after the fact.
 *
 * §H below extends the same guard to #1866's `manage/catalogues.php`
 * "Add a song" picker — ANOTHER search-select-ONLY surface (songs are
 * authored in the editor, never minted from a catalogue form), the same
 * shape as §G: it proves the PRESENCE of a server-side SongId existence
 * check BEFORE the `tblCatalogueSongs` write, the ABSENCE of any
 * `INSERT INTO tblSongs`/find-or-create call on that path, that the old
 * free-text `pattern="[A-Za-z]+-\d+"` box is gone in favour of the picker's
 * hidden id, and that the client wiring reuses catalogues.php's OWN
 * pre-existing `?action=song_search` handler (which existed as an unwired
 * scaffold before #1866) rather than a second local fork.
 *
 * §I below covers #1867's ADMIN surface — `manage/requests.php`'s
 * "Resolved SongId" picker — the same search-select-ONLY shape as §G/§H: a
 * server-side SongId existence check BEFORE the `tblSongRequests` UPDATE
 * (position-checked, same as G1/H1), no `INSERT INTO tblSongs`, a NEW
 * page-local `?action=song_search` handler (this page had none before —
 * unlike §H's catalogues.php, which reused a pre-existing one) declared
 * exactly once, and the picker's `validateCsrfRequest()` CSRF gate
 * (rule #29) rather than the bare `validateCsrf()` this page carried before.
 *
 * §J below covers #1867's PUBLIC surface — the `/request` page's Songbook
 * field, wired entirely inside `js/modules/request-a-song.js` — structurally
 * different from every picker before it, so §J does NOT reuse §A-I's
 * "attach() + pickMode:'value'" assertions at all. This field is
 * deliberately NOT `window.iHymnsPlaceSearch` (an admin-only script never
 * shipped to the public bundle — CLAUDE.md rule #30/#31's territory, not
 * rule #43's), so §J instead proves: the module imports `combobox-a11y.js`
 * for its side effect and never references `iHymnsPlaceSearch`; every
 * network read goes through `apiFetch(`, never a bare `fetch(` (rule #31 —
 * proven by a literal, case-sensitive `'fetch('` scan that cannot
 * false-positive on `apiFetch(`, verified against a fixture below); the
 * songbook data source is the PUBLIC `/api?action=songbooks` read, and the
 * file never mentions a `/manage/` path at all (an admin, auth-gated
 * endpoint would 401/403 for the anonymous visitors this page serves); the
 * picker is actually invoked from the page's entry point
 * (`initRequestASong()`); and the page fragment it decorates
 * (`includes/pages/request-a-song.php`) still carries no `<script>` tag —
 * this feature adds ZERO PHP/markup surface, confirming
 * `test-fragment-inline-scripts.php` has nothing new to catch here.
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
 * @see appWeb/public_html/manage/publishers.php             delegates to publisherSearchRows() (was the superset fork); §G: merge-target picker
 * @see appWeb/public_html/manage/songbooks.php              delegates to publisherSearchRows(); §F: the 'create' case's Publisher picker + tblSongbookPublishers seed (#1865)
 * @see appWeb/public_html/manage/includes/songbook-form-fields.php  the create-only Publisher picker markup (#1865)
 * @see appWeb/public_html/manage/groups.php                 §G: add-a-member picker, search-select only (#1868)
 * @see appWeb/public_html/manage/catalogues.php             §H: "Add a song" picker, search-select only (#1866)
 * @see appWeb/public_html/manage/requests.php                §I: admin "Resolved SongId" picker, search-select only (#1867)
 * @see appWeb/public_html/js/modules/request-a-song.js      §J: public Songbook field combobox (#1867)
 * @see appWeb/public_html/includes/pages/request-a-song.php §J: the fragment the module decorates — no <script> added
 * @see tests/php/test-tune-lockstep.php                     the sibling guard this mirrors in shape
 * @see /tmp/.../pickers-1864-spec.md                        the implementation spec this proves
 * @see #1864, #1865, #1868, #1866, #1867, epic #1863
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
 * Slice a `case 'LABEL': { ... }` block (#1865, §F) — same brace-counting
 * approach as `wrpSliceIfBlockByCondition()`/`wrpSliceAssignedClosure()`,
 * anchored on a `switch`/`case` label instead of an `if` condition or a
 * closure assignment. `manage/songbooks.php`'s POST handler is one big
 * `switch ($action) { case 'create': { ... } case 'update': { ... } ... }`,
 * so this is what lets §F bound its assertions to the 'create' arm ONLY —
 * without it, a substring check against the whole file could not tell "the
 * create path resolves the picker" apart from "the file contains this text
 * somewhere, possibly in the unrelated update path".
 */
function wrpSliceCaseBlock(string $src, string $label): string
{
    $needle = "case '" . $label . "':";
    $pos = strpos($src, $needle);
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

/* --- wrpSliceCaseBlock() (#1865, §F): finds the 'create' case block only,
   brace-counts through a nested if, and does not bleed into the sibling
   'update' case. --- */
$fixtureF = "switch (\$action) {\n"
  . "    case 'create': {\n"
  . "        if (true) { echo 'InnerCreateNeedle'; }\n"
  . "        echo 'OuterCreateNeedle';\n"
  . "        break;\n"
  . "    }\n"
  . "    case 'update': {\n"
  . "        echo 'UpdateNeedle';\n"
  . "        break;\n"
  . "    }\n"
  . "}\n";
$sliceF = wrpSliceCaseBlock($fixtureF, 'create');
foreach (['InnerCreateNeedle', 'OuterCreateNeedle'] as $needle) {
    if (strpos($sliceF, $needle) === false) {
        $mutationFailures[] = "wrpSliceCaseBlock() FAILS-HIGH self-test did not find '{$needle}' inside its own fixture 'create' block (nested-brace handling)";
    }
}
if (strpos($sliceF, 'UpdateNeedle') !== false) {
    $mutationFailures[] = "wrpSliceCaseBlock() FAILS-LOW self-test: 'create' slice bled into the sibling 'update' case";
}
if (wrpSliceCaseBlock($fixtureF, 'no_such_case') !== '') {
    $mutationFailures[] = 'wrpSliceCaseBlock() FAILS-LOW self-test: a non-existent case label should return an empty slice';
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
/* #1988 — manage/works.php's $persistWorkExtraFields (and the other #1741
   P4b closures) now one-line-delegate to includes/work_admin.php (the
   $slugFor -> workSlugify() precedent, rule #22) so the API-coverage
   admin_work_create/_update actions reuse the SAME write core. B1 below
   follows the delegation to check the CopyrightHolder<->CopyrightHolderId
   lockstep where it now actually lives. */
$workAdminFile   = $web . '/includes/work_admin.php';

foreach ([
    'includes/publisher_helpers.php' => $helpersFile,
    'manage/works.php'               => $worksFile,
    'manage/publishers.php'          => $publishersFile,
    'manage/songbooks.php'           => $songbooksFile,
    'includes/work_admin.php'        => $workAdminFile,
] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read {$label} at {$path}\n");
        exit(1);
    }
}

/* #1865 §F — the create-form Publisher picker's markup lives in the shared
   partial, not songbooks.php itself (rule #22 — one field, one partial). */
$sbFormFieldsFile = $web . '/manage/includes/songbook-form-fields.php';
if (!is_readable($sbFormFieldsFile)) {
    fwrite(STDERR, "FATAL: could not read manage/includes/songbook-form-fields.php at {$sbFormFieldsFile}\n");
    exit(1);
}

/* #1993 — songbooks.php's 'create' case was re-pointed onto the shared
   songbookAdminValidateCreate()/…Create() core (rule #22, same posture as
   #1988's work_admin.php extraction above): the field-parsing + the
   publisher-resolve-and-link block §F polices moved OUT of the case body
   and into this file. §F below now follows that ONE level of delegation,
   the same way B1 already follows works.php's delegation into
   work_admin.php. */
$songbookAdminFile = $web . '/includes/songbook_admin.php';
if (!is_readable($songbookAdminFile)) {
    fwrite(STDERR, "FATAL: could not read includes/songbook_admin.php at {$songbookAdminFile}\n");
    exit(1);
}

/* #1868 §G — the two search-select-only surfaces. manage/publishers.php is
   already read via $publishersFile above (declared for §A/§B's tree sweep);
   groups.php is new to this file. */
$groupsFile = $web . '/manage/groups.php';
if (!is_readable($groupsFile)) {
    fwrite(STDERR, "FATAL: could not read manage/groups.php at {$groupsFile}\n");
    exit(1);
}

/* #1866 §H — the catalogues.php "Add a song" search-select-only surface. */
$cataloguesFile = $web . '/manage/catalogues.php';
if (!is_readable($cataloguesFile)) {
    fwrite(STDERR, "FATAL: could not read manage/catalogues.php at {$cataloguesFile}\n");
    exit(1);
}

/* #1969 API-coverage batch 4b-i (A4) — catalogues.php's add_member handler
   was re-pointed at includes/catalogue_admin.php's shared core (rule #22),
   so §H's "verify before write" checks below now follow the delegation one
   level deep into THIS file rather than finding the raw SQL inline. */
$catalogueAdminFile = $web . '/includes/catalogue_admin.php';
if (!is_readable($catalogueAdminFile)) {
    fwrite(STDERR, "FATAL: could not read includes/catalogue_admin.php at {$catalogueAdminFile}\n");
    exit(1);
}

/* #1867 §I — manage/requests.php's admin "Resolved SongId" picker. */
$requestsFile = $web . '/manage/requests.php';
if (!is_readable($requestsFile)) {
    fwrite(STDERR, "FATAL: could not read manage/requests.php at {$requestsFile}\n");
    exit(1);
}

/* #1867 §J — the PUBLIC surface: the picker lives entirely in the ES module,
   not in the PHP fragment it decorates (rule #30 — no inline <script> in a
   fragment). Both are read so §J can prove the module is wired AND that the
   fragment stayed script-free. */
$reqJsFile  = $web . '/js/modules/request-a-song.js';
$reqPhpFile = $web . '/includes/pages/request-a-song.php';
foreach (['js/modules/request-a-song.js' => $reqJsFile, 'includes/pages/request-a-song.php' => $reqPhpFile] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read {$label} at {$path}\n");
        exit(1);
    }
}

$helpersRaw = (string)file_get_contents($helpersFile);
$worksRaw   = (string)file_get_contents($worksFile);
$songbooksRaw      = (string)file_get_contents($songbooksFile);
$sbFormFieldsRaw   = (string)file_get_contents($sbFormFieldsFile);
$groupsRaw         = (string)file_get_contents($groupsFile);
$publishersRaw     = (string)file_get_contents($publishersFile);
$cataloguesRaw     = (string)file_get_contents($cataloguesFile);
$catalogueAdminRaw = (string)file_get_contents($catalogueAdminFile);
$requestsRaw       = (string)file_get_contents($requestsFile);
$reqJsRaw          = (string)file_get_contents($reqJsFile);
$reqPhpRaw         = (string)file_get_contents($reqPhpFile);

/* Two independently-tested views of works.php (see the file-level
   doc-block's "stripper trap" section) — never conflate them. Same pattern
   applied to songbooks.php for §F, to groups.php/publishers.php for §G, and
   to catalogues.php for §H (all have picker wiring living inside a plain
   <script> tag — the identical T_INLINE_HTML trap). */
$workAdminRaw = (string)file_get_contents($workAdminFile);
$songbookAdminRaw = (string)file_get_contents($songbookAdminFile);

$helpersPhpView   = wrpStripPhpComments($helpersRaw);
$worksPhpView     = wrpStripPhpComments($worksRaw);
$worksAllView     = wrpStripAllComments($worksRaw);
$workAdminPhpView = wrpStripPhpComments($workAdminRaw);
$songbooksPhpView = wrpStripPhpComments($songbooksRaw);
$songbooksAllView = wrpStripAllComments($songbooksRaw);
$songbookAdminPhpView = wrpStripPhpComments($songbookAdminRaw);
$groupsPhpView     = wrpStripPhpComments($groupsRaw);
$groupsAllView     = wrpStripAllComments($groupsRaw);
$publishersPhpView = wrpStripPhpComments($publishersRaw);
$publishersAllView = wrpStripAllComments($publishersRaw);
$cataloguesPhpView = wrpStripPhpComments($cataloguesRaw);
$cataloguesAllView = wrpStripAllComments($cataloguesRaw);
$catalogueAdminPhpView = wrpStripPhpComments($catalogueAdminRaw);
$requestsPhpView   = wrpStripPhpComments($requestsRaw);
$requestsAllView   = wrpStripAllComments($requestsRaw);
/* request-a-song.js is JS, not PHP — token_get_all() is PHP-syntax-specific,
   so only the language-agnostic wrpStripAllComments() view applies to it
   (the SAME regex stripper §C/§F/§G/§H already use on the JS living inside
   works.php/songbooks.php/groups.php/publishers.php/catalogues.php's
   <script> blocks — proven safe there by this file's own mutation
   self-test above, "JsCommentNeedle"/"JsCodeNeedle"). */
$reqJsAllView  = wrpStripAllComments($reqJsRaw);
$reqPhpAllView = wrpStripAllComments($reqPhpRaw);

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
   shape (rule #37/#43), never a raw client-supplied id.
   #1988 — the closure now one-line-delegates to the shared
   workPersistExtraFields() (includes/work_admin.php, rule #22) so
   admin_work_create/_update (api.php) write the SAME lockstep; B1 follows
   the delegation and checks the three literals at their new home instead
   of expecting them inside the (now one-line) closure body. PHP-comment-
   stripped view throughout: this file's OWN doc-comment right above the
   write deliberately names publisherResolvePickedOrCreate() beside the
   real call (rule #34's wrong-but-green trap), so an unstripped scan
   would stay green even if the real call were deleted. ---- */
$persistBlock = wrpSliceAssignedClosure($worksPhpView, 'persistWorkExtraFields');
if ($persistBlock === '') {
    $failures[] = 'could not locate $persistWorkExtraFields = static function (...) { ... }; in manage/works.php';
} elseif (strpos($persistBlock, 'workPersistExtraFields(') === false) {
    $failures[] = '$persistWorkExtraFields no longer delegates to the shared workPersistExtraFields() (includes/work_admin.php) — either re-forked inline, or the #1988 extraction was reverted';
}
$workPersistExtraFieldsFn = wrpSliceFunctionDecl($workAdminPhpView, 'workPersistExtraFields');
if ($workPersistExtraFieldsFn === '') {
    $failures[] = 'includes/work_admin.php does not declare function workPersistExtraFields() — the #1988 extraction target is missing';
} else {
    foreach (['CopyrightHolder = ?', 'CopyrightHolderId = ?', 'publisherResolvePickedOrCreate('] as $needle) {
        if (strpos($workPersistExtraFieldsFn, $needle) === false) {
            $failures[] = "workPersistExtraFields() (includes/work_admin.php) no longer contains '{$needle}' — the CopyrightHolder<->CopyrightHolderId lockstep write is broken (#1864/#1988, rule #37/#43)";
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
   PHP-comment-stripped view for the read side.
   #1988 — $parseWorkExtraFields now one-line-delegates to the shared
   workParseExtraFields() (includes/work_admin.php, rule #22), which is
   where the ACTUAL $post['copyright_holder_id'] read now lives (the SAME
   function admin_work_create/_update in api.php call with their JSON
   body). Checked at BOTH ends of the delegation: the closure genuinely
   delegates, AND the shared function still does the read. ---- */
if (substr_count($worksRaw, 'name="copyright_holder_id"') < 2) {
    $failures[] = 'works.php emits name="copyright_holder_id" fewer than 2 times (expected at least 2: the create form + the edit modal) — the picker\'s hidden id is not wired into both forms';
}
$parseBlock = wrpSliceAssignedClosure($worksPhpView, 'parseWorkExtraFields');
if ($parseBlock === '') {
    $failures[] = 'could not locate $parseWorkExtraFields = static function (...) { ... }; in manage/works.php';
} elseif (strpos($parseBlock, 'workParseExtraFields(') === false) {
    $failures[] = '$parseWorkExtraFields no longer delegates to the shared workParseExtraFields() (includes/work_admin.php) — either re-forked inline, or the #1988 extraction was reverted';
}
$workParseExtraFieldsFn = wrpSliceFunctionDecl($workAdminPhpView, 'workParseExtraFields');
if ($workParseExtraFieldsFn === '') {
    $failures[] = 'includes/work_admin.php does not declare function workParseExtraFields() — the #1988 extraction target is missing';
} elseif (!preg_match('/\$(?:_POST|post)\[\s*\'copyright_holder_id\'\s*\]/', $workParseExtraFieldsFn)) {
    $failures[] = 'workParseExtraFields() (includes/work_admin.php) never reads $post[\'copyright_holder_id\'] (or $_POST[...]) — the markup emits the field but nothing consumes it server-side (rule #33: a param nobody reads)';
}

/* =========================================================================
 * §F — #1865: the Songbook create-form Publisher picker
 * ========================================================================= */

/* ---- F1: the songbooks.php 'create' case resolves the Publisher field
   through publisherResolvePickedOrCreate() (never a raw client-supplied id,
   never a direct publisherFindOrCreateByName() bypass — the trust-but-verify
   contract rule #37/#43 requires) and seeds tblSongbookPublishers — the SAME
   funnel + the SAME table the Edit arm's own richer multi-publisher
   reconciliation writes, so there is exactly one write path per table
   (rule #37's "never a second sync path"). #1993 moved this block out of
   the case body and into includes/songbook_admin.php's songbookAdminCreate()
   (rule #22 — the SAME extraction B1 above already follows one level deep
   for works.php/work_admin.php) — so this now (a) confirms the case body
   DELEGATES via songbookAdminCreate(, then (b) follows that ONE level of
   indirection into the core function's own body for the actual
   resolve-and-link assertions, rather than expecting them inline. Bounded
   to the 'create' case ONLY via wrpSliceCaseBlock() — the sibling 'update'
   case legitimately contains neither of these (it goes through the
   multi-publisher picker instead). */
$sbCreateBlock = wrpSliceCaseBlock($songbooksPhpView, 'create');
$sbAdminCreateFn = wrpSliceFunctionDecl($songbookAdminPhpView, 'songbookAdminCreate');
if ($sbCreateBlock === '') {
    $failures[] = "could not locate case 'create': { ... } in manage/songbooks.php (slicer or file shape changed)";
} elseif (strpos($sbCreateBlock, 'songbookAdminCreate(') === false) {
    $failures[] = "songbooks.php's 'create' case never calls songbookAdminCreate() — the #1993 delegation to includes/songbook_admin.php is broken (rule #22)";
}
if ($sbAdminCreateFn === '') {
    $failures[] = 'could not locate function songbookAdminCreate() in includes/songbook_admin.php (slicer or file shape changed)';
} else {
    if (strpos($sbAdminCreateFn, 'publisherResolvePickedOrCreate(') === false) {
        $failures[] = "includes/songbook_admin.php's songbookAdminCreate() never calls publisherResolvePickedOrCreate() — the Publisher field's find-or-create-on-commit is broken (#1865, rule #37/#43)";
    }
    /* Word-boundary, not strpos: rule #34's own documented trap —
       'tblSongbookPublishersXXX' still contains 'tblSongbookPublishers' as
       a bare substring, so a table-name typo/rename would stay wrong-but-
       green under a plain strpos() (see test-publisher-registry.php's
       pubHas() doc-comment for the identical lesson, applied here). */
    if (!preg_match('/INSERT INTO tblSongbookPublishers\b/', $sbAdminCreateFn)) {
        $failures[] = "includes/songbook_admin.php's songbookAdminCreate() never writes INSERT INTO tblSongbookPublishers — a brand-new songbook's Publisher field stops short of the registry link (#1865, rule #37)";
    }
    if (strpos($sbAdminCreateFn, 'publisherFindOrCreateByName(') !== false) {
        $failures[] = "includes/songbook_admin.php's songbookAdminCreate() calls publisherFindOrCreateByName() directly — it must go through publisherResolvePickedOrCreate() so a verified picker claim is trusted over a blind name resolve (rule #37/#43)";
    }
}

/* ---- F2: no bare free-text write becomes authoritative over the registry
   — the create INSERT's Publisher column is fed by $publisher (the typed
   string) as always, but the SAME function must ALSO resolve+link it; F1
   above already proves the link exists. This assertion additionally locks
   in that the resolver call is gated on the caller's hasPublishersSchema
   flag (pre-migration safety — rule #9), so an un-migrated install
   degrades to "display-string-only" instead of a fatal on a missing table.
   #1993: the flag arrives as $flags['hasPublishersSchema'] (an array key
   the caller's own hoisted $hasPublishersSchema is passed in as), not the
   bare local variable §F2 checked for pre-#1993 — same gating discipline,
   new call shape. ---- */
if ($sbAdminCreateFn !== '' && !preg_match('/hasPublishersSchema[\s\S]{0,400}publisherResolvePickedOrCreate\(/', $sbAdminCreateFn)) {
    $failures[] = "includes/songbook_admin.php's songbookAdminCreate() calls publisherResolvePickedOrCreate() without gating it on hasPublishersSchema first — a pre-migration install (no tblSongbookPublishers yet) would risk a fatal instead of degrading gracefully (rule #9)";
}
if ($sbCreateBlock !== '' && !preg_match('/songbookAdminCreate\([\s\S]{0,400}hasPublishersSchema/', $sbCreateBlock)) {
    $failures[] = "songbooks.php's 'create' case never passes hasPublishersSchema through to songbookAdminCreate() — the pre-migration gate in F2 above would never actually engage";
}

/* ---- F3: the publisher_id wire contract is honoured at BOTH ends
   (rule #33) — the shared form-fields partial EMITS name="publisher_id" and
   includes/songbook_admin.php's songbookAdminValidateCreate() READS
   $in['publisher_id'] (#1993 — the read moved from the page's own
   $_POST[...] to the shared core's $in[...] parameter, which the case body
   populates by passing $_POST straight through; both halves are checked
   below so a delegation that silently drops the field on either side goes
   red). Raw markup (an HTML attribute is never inside a comment in the
   real file) + comment-stripped views for the read side. ---- */
if (strpos($sbFormFieldsRaw, 'name="publisher_id"') === false) {
    $failures[] = 'manage/includes/songbook-form-fields.php no longer emits name="publisher_id" — the create-form Publisher picker\'s hidden id is not wired';
}
$sbAdminValidateFn = wrpSliceFunctionDecl($songbookAdminPhpView, 'songbookAdminValidateCreate');
if ($sbAdminValidateFn === '' || !preg_match('/\$in\[\s*\'publisher_id\'\s*\]/', $sbAdminValidateFn)) {
    $failures[] = 'includes/songbook_admin.php\'s songbookAdminValidateCreate() never reads $in[\'publisher_id\'] — the partial emits the field but nothing consumes it server-side (rule #33: a param nobody reads)';
}
if ($sbCreateBlock === '' || !preg_match('/songbookAdminValidateCreate\(\s*\$db\s*,\s*\$_POST\s*\)/', $sbCreateBlock)) {
    $failures[] = "songbooks.php's 'create' case never calls songbookAdminValidateCreate(\$db, \$_POST) — the page's raw POST body must reach the core's \$in[...] reads for F3's publisher_id wire (and every other field) to actually arrive";
}

/* ---- F4: the client wiring actually attaches the create-only picker with
   pickMode:'value', and reuses THIS page's own ?action=publisher_search
   (never a fabricated endpoint). All-comments-stripped view, same reasoning
   as C1/C2 above. ---- */
if (!preg_match("/'create-publisher'[\\s\\S]{0,200}'create-publisher-id'/", $songbooksAllView)) {
    $failures[] = "songbooks.php's attach() wiring is missing the ['create-publisher', 'create-publisher-id'] pair — the #1865 picker is not wired";
}
if (strpos($songbooksAllView, 'iHymnsPlaceSearch.attach') === false) {
    $failures[] = 'songbooks.php no longer calls window.iHymnsPlaceSearch.attach(...) for the create-form Publisher picker';
}
if (strpos($songbooksAllView, "pickMode: 'value'") === false) {
    $failures[] = "songbooks.php's create-publisher picker no longer sets pickMode: 'value' — a picker default that isn't 'value' would try to network-upsert on pick, which the #1865 field does not want (persistence is the form POST, not a pick-time write)";
}

/* ---- F5: the Edit arm's pre-existing quick Publisher field (datalist
   convenience) and its richer multi-publisher picker are UNCHANGED by
   #1865 — this locks in the "create-arm only" scope decision so a future
   edit can't silently regress the Edit modal's own working path while
   touching the shared partial. ---- */
foreach (['id="edit-publisher"', 'list="edit-publisher-datalist"'] as $needle) {
    if (strpos($sbFormFieldsRaw, $needle) === false) {
        $failures[] = "manage/includes/songbook-form-fields.php no longer emits {$needle} — the Edit modal's quick Publisher field regressed (#1865 was scoped to leave it as-is)";
    }
}
foreach (['name="publisher_ids[]"', 'name="publisher_roles[]"', 'name="publisher_notes[]"'] as $needle) {
    if (strpos($songbooksRaw, $needle) === false) {
        $failures[] = "songbooks.php no longer emits {$needle} — the Edit arm's richer multi-publisher picker regressed (#1865 was scoped to leave it as-is)";
    }
}

/* =========================================================================
 * §G — #1868: groups.php add-a-member + publishers.php merge-target
 * (search-select ONLY — no create arm on either surface)
 * ========================================================================= */

$groupsAddMemberBlock = wrpSliceCaseBlock($groupsPhpView, 'add_member');

/* ---- G1: groups.php's add_member handler verifies the submitted user_id
   names a REAL tblUsers row (a SELECT ... WHERE Id = ?) BEFORE the UPDATE
   that writes GroupId — "resolve to a real existing user id" (#1868's
   explicit server-side requirement). Position-based (not just presence): a
   verify-AFTER-the-UPDATE would already be too late to matter. ---- */
if ($groupsAddMemberBlock === '') {
    $failures[] = "could not locate case 'add_member': { ... } in manage/groups.php (slicer or file shape changed)";
} else {
    $selectPos = -1;
    if (preg_match('/SELECT\s+[\s\S]{0,80}?FROM\s+tblUsers\s+WHERE\s+Id\s*=\s*\?/', $groupsAddMemberBlock, $m, PREG_OFFSET_CAPTURE)) {
        $selectPos = $m[0][1];
    }
    $updatePos = strpos($groupsAddMemberBlock, 'UPDATE tblUsers SET GroupId');
    if ($selectPos < 0) {
        $failures[] = "groups.php's add_member handler no longer verifies the submitted user_id against tblUsers before use (#1868, rule #43 — 'the server MUST verify the id exists')";
    } elseif ($updatePos === false) {
        $failures[] = "groups.php's add_member handler no longer writes UPDATE tblUsers SET GroupId — the write itself is missing";
    } elseif (!($selectPos < $updatePos)) {
        $failures[] = "groups.php's add_member handler's existence check does not run BEFORE the UPDATE that uses the id — a check that runs after the write is too late to prevent it";
    }
}

/* ---- G2: groups.php's add_member handler never mints a user row — this
   surface is search-select ONLY, unlike #1864's Tune/Publisher fields which
   deliberately DO fall back to a create funnel. ---- */
if ($groupsAddMemberBlock !== '' && strpos($groupsAddMemberBlock, 'INSERT INTO tblUsers') !== false) {
    $failures[] = "groups.php's add_member handler contains \"INSERT INTO tblUsers\" — this surface must never invent a user (#1868's explicit no-create-arm scope)";
}

/* ---- G3: the add-a-member markup was actually converted from the old
   `LIMIT 500` <select> to the picker's hidden id + search input, and the
   client wiring attaches it with pickMode:'value' against the EXISTING api2
   user_search action (never a new local search route on groups.php). ---- */
if (strpos($groupsRaw, '<select name="user_id"') !== false) {
    $failures[] = "groups.php still renders <select name=\"user_id\"> — the #1868 add-a-member picker did not replace the old LIMIT-500 dropdown";
}
foreach (['id="add-member-user-id"', 'id="add-member-user-name"'] as $needle) {
    if (strpos($groupsRaw, $needle) === false) {
        $failures[] = "groups.php no longer emits {$needle} — the #1868 add-a-member picker's markup is missing";
    }
}
if (strpos($groupsAllView, 'iHymnsPlaceSearch.attach') === false) {
    $failures[] = 'groups.php no longer calls window.iHymnsPlaceSearch.attach(...) for the add-a-member picker';
}
if (strpos($groupsAllView, "pickMode: 'value'") === false) {
    $failures[] = "groups.php's add-a-member picker no longer sets pickMode: 'value'";
}
if (strpos($groupsAllView, 'action=user_search') === false || strpos($groupsAllView, '/manage/editor/api2') === false) {
    $failures[] = "groups.php's add-a-member picker no longer points at the EXISTING /manage/editor/api2?action=user_search endpoint (rule #22 — it must reuse it, not fork a local one)";
}
if (preg_match('/case\s+\'user_search\'\s*:/', $groupsAllView)) {
    $failures[] = "groups.php declares its own case 'user_search': — a local fork of api2's user search (rule #22 forbids this)";
}

/* ---- G4: publishers.php's merge-target field was converted from a second
   `<select>` (rendering EVERY publisher) to a search input + hidden id, and
   is attached with pickMode:'value' + a dynamic `exclude=` bound to the
   Source select's live value — the mechanism that makes "merge a publisher
   into itself" unreachable through the UI. Windowed proximity match (not a
   file-wide strpos) so this actually proves the EXCLUDE is wired to THIS
   specific attach() call, not merely present somewhere else on the page. ---- */
if (strpos($publishersRaw, '<select name="target_id"') !== false) {
    $failures[] = 'publishers.php still renders <select name="target_id"> — the #1868 merge-target picker did not replace the old all-publishers dropdown';
}
foreach (['id="merge-target-id"', 'id="merge-target-name"', 'id="merge-source-id"'] as $needle) {
    if (strpos($publishersRaw, $needle) === false) {
        $failures[] = "publishers.php no longer emits {$needle} — the #1868 merge-target picker's markup is missing";
    }
}
if (!preg_match("/attach\\(mergeTargetName[\\s\\S]{0,600}pickMode:\\s*'value'[\\s\\S]{0,600}action=publisher_search[\\s\\S]{0,300}exclude=[\\s\\S]{0,200}mergeSourceSel/", $publishersAllView)) {
    $failures[] = "publishers.php's merge-target attach() call is not wired to search ?action=publisher_search with an exclude= bound to the source select's live value (#1868 — the excludeId arm that keeps a publisher from being merged into itself)";
}

/* ---- G5: publishers.php's merge handler never mints or forks — search-
   select ONLY (no publisherFindOrCreateByName()/INSERT), and the merge
   itself still flows through the ONE existing publisherAdminMerge() core
   (rule #22 — "do not fork the merge"). ---- */
$publishersMergeBlock = wrpSliceCaseBlock($publishersPhpView, 'merge');
if ($publishersMergeBlock === '') {
    $failures[] = "could not locate case 'merge': { ... } in manage/publishers.php (slicer or file shape changed)";
} else {
    if (strpos($publishersMergeBlock, 'publisherFindOrCreateByName(') !== false) {
        $failures[] = "publishers.php's merge handler calls publisherFindOrCreateByName() — this surface must never invent a merge target (#1868's explicit no-create-arm scope)";
    }
    if (strpos($publishersMergeBlock, 'INSERT INTO tblPublishers') !== false) {
        $failures[] = "publishers.php's merge handler contains \"INSERT INTO tblPublishers\" — this surface must never invent a merge target (#1868's explicit no-create-arm scope)";
    }
    if (strpos($publishersMergeBlock, 'publisherAdminMerge(') === false) {
        $failures[] = "publishers.php's merge handler no longer calls publisherAdminMerge() — the ONE shared merge core (rule #22) must not be forked or bypassed";
    }
}

/* =========================================================================
 * §H — #1866: catalogues.php "Add a song" picker (search-select ONLY —
 * no create arm; songs are authored in the editor, never minted here)
 * ========================================================================= */

$cataloguesAddMemberBlock = wrpSliceCaseBlock($cataloguesPhpView, 'add_member');

/* ---- H1: catalogues.php's add_member handler verifies the submitted
   song_id names a REAL tblSongs row BEFORE writing tblCatalogueSongs —
   "resolve to a real existing SongId" (#1866's explicit server-side
   requirement). #1969 (API-coverage batch 4b-i A4) re-pointed this handler
   at includes/catalogue_admin.php's shared core (rule #22), so the check
   now follows the delegation ONE LEVEL DEEP rather than finding the raw SQL
   inline: (a) the page's add_member case calls
   catalogueAdminFindVisibleSongTitle( BEFORE catalogueAdminAddMember( —
   position-based, same "verify-after-write is too late" reasoning as
   before, just re-anchored on the delegate calls; (b) the delegate
   functions THEMSELVES are sliced out of catalogue_admin.php and proven to
   still do the real work — the verify function's body contains a
   `SELECT ... FROM tblSongs WHERE SongId = ?`, and the write function's
   body contains the `INSERT IGNORE INTO tblCatalogueSongs`. Same shape as
   G1's groups.php check, one indirection deeper. ---- */
if ($cataloguesAddMemberBlock === '') {
    $failures[] = "could not locate case 'add_member': { ... } in manage/catalogues.php (slicer or file shape changed)";
} else {
    $verifyCallPos = strpos($cataloguesAddMemberBlock, 'catalogueAdminFindVisibleSongTitle(');
    $addCallPos    = strpos($cataloguesAddMemberBlock, 'catalogueAdminAddMember(');
    if ($verifyCallPos === false) {
        $failures[] = "catalogues.php's add_member handler no longer calls catalogueAdminFindVisibleSongTitle( — the submitted song_id is no longer verified against tblSongs before use (#1866, rule #43 — 'the server MUST verify the id exists')";
    } elseif ($addCallPos === false) {
        $failures[] = "catalogues.php's add_member handler no longer calls catalogueAdminAddMember( — the tblCatalogueSongs write itself is missing";
    } elseif (!($verifyCallPos < $addCallPos)) {
        $failures[] = "catalogues.php's add_member handler's catalogueAdminFindVisibleSongTitle( call does not run BEFORE catalogueAdminAddMember( — a check that runs after the write is too late to prevent it";
    }
}

$catFindVisibleSongTitleFn = wrpSliceFunctionDecl($catalogueAdminPhpView, 'catalogueAdminFindVisibleSongTitle');
$catAddMemberFn            = wrpSliceFunctionDecl($catalogueAdminPhpView, 'catalogueAdminAddMember');
if ($catFindVisibleSongTitleFn === '') {
    $failures[] = 'could not locate function catalogueAdminFindVisibleSongTitle(...) { ... } in includes/catalogue_admin.php (slicer or file shape changed)';
} elseif (!preg_match('/SELECT\s+[\s\S]{0,80}?FROM\s+tblSongs\s+WHERE\s+SongId\s*=\s*\?/', $catFindVisibleSongTitleFn)) {
    $failures[] = "includes/catalogue_admin.php's catalogueAdminFindVisibleSongTitle() no longer verifies song_id against tblSongs (a SELECT ... FROM tblSongs WHERE SongId = ?) — the delegate call exists but the real check behind it is gone";
}
if ($catAddMemberFn === '') {
    $failures[] = 'could not locate function catalogueAdminAddMember(...) { ... } in includes/catalogue_admin.php (slicer or file shape changed)';
} elseif (strpos($catAddMemberFn, 'INSERT IGNORE INTO tblCatalogueSongs') === false) {
    $failures[] = "includes/catalogue_admin.php's catalogueAdminAddMember() no longer writes INSERT IGNORE INTO tblCatalogueSongs — the write itself is missing";
}

/* ---- H2: neither the add_member handler nor either of its two delegate
   functions ever mints a song row — this surface is search-select ONLY,
   unlike #1864's Tune/Publisher fields which deliberately DO fall back to a
   create funnel. ---- */
foreach ([
    'manage/catalogues.php\'s add_member handler' => $cataloguesAddMemberBlock,
    'includes/catalogue_admin.php\'s catalogueAdminFindVisibleSongTitle()' => $catFindVisibleSongTitleFn,
    'includes/catalogue_admin.php\'s catalogueAdminAddMember()' => $catAddMemberFn,
] as $label => $src) {
    if ($src !== '' && strpos($src, 'INSERT INTO tblSongs') !== false) {
        $failures[] = "{$label} contains \"INSERT INTO tblSongs\" — this surface must never invent a song (#1866's explicit no-create-arm scope; songs are authored in the editor)";
    }
}

/* ---- H3: the "Add a song" markup was actually converted from the old
   free-text `pattern="[A-Za-z]+-\d+"` box to the picker's hidden id + search
   input, and the client wiring attaches it with pickMode:'value' against
   catalogues.php's OWN pre-existing ?action=song_search action. ---- */
if (strpos($cataloguesRaw, 'pattern="[A-Za-z]+-\d+"') !== false) {
    $failures[] = 'catalogues.php still renders the old pattern="[A-Za-z]+-\\d+" free-text song id box — the #1866 "Add a song" picker did not replace it';
}
foreach (['cat-add-song-id', 'cat-add-song-name'] as $needle) {
    if (strpos($cataloguesRaw, $needle) === false) {
        $failures[] = "catalogues.php no longer emits an element carrying class \"{$needle}\" — the #1866 \"Add a song\" picker's markup is missing";
    }
}
if (strpos($cataloguesAllView, 'iHymnsPlaceSearch.attach') === false) {
    $failures[] = 'catalogues.php no longer calls window.iHymnsPlaceSearch.attach(...) for the "Add a song" picker';
}
if (strpos($cataloguesAllView, "pickMode: 'value'") === false) {
    $failures[] = "catalogues.php's \"Add a song\" picker no longer sets pickMode: 'value'";
}
if (strpos($cataloguesAllView, 'action=song_search') === false) {
    $failures[] = "catalogues.php's \"Add a song\" picker no longer points at ?action=song_search";
}

/* ---- H4: catalogues.php declares exactly ONE ?action=song_search gate —
   the handler already existed as an unwired scaffold before #1866; the
   picker must reuse it, never add a second local fork (rule #22). This is
   the §H analogue of C2/D1/G3's "reuse, don't fork" checks, but the fork
   risk here is a SECOND gate in the SAME file rather than a competing
   endpoint elsewhere, since the handler was already page-local. ---- */
$songSearchGateCount = substr_count($cataloguesPhpView, "=== 'song_search'");
if ($songSearchGateCount !== 1) {
    $failures[] = "catalogues.php declares {$songSearchGateCount} \"=== 'song_search'\" action gate(s) — expected exactly 1 (the pre-existing handler the #1866 picker reuses; a second gate would be a duplicate fork, rule #22)";
}

/* ---- H5: the song_id wire contract is honoured at BOTH ends (rule #33) —
   the markup EMITS name="song_id" on the hidden input and the add_member
   handler READS $_POST['song_id']. Raw markup (an HTML attribute is never
   inside a comment in the real file) + the sliced add_member block for the
   read side. ---- */
if (strpos($cataloguesRaw, 'name="song_id"') === false) {
    $failures[] = 'catalogues.php no longer emits name="song_id" — the "Add a song" picker\'s hidden id is not wired into the form';
}
if ($cataloguesAddMemberBlock === '' || !preg_match('/\$_POST\[\s*\'song_id\'\s*\]/', $cataloguesAddMemberBlock)) {
    $failures[] = 'catalogues.php\'s add_member handler never reads $_POST[\'song_id\'] — the markup emits the field but nothing consumes it server-side (rule #33: a param nobody reads)';
}

/* =========================================================================
 * §I — #1867 ADMIN surface: manage/requests.php "Resolved SongId" picker
 * (search-select ONLY — no create arm; curators link an existing song)
 * ========================================================================= */

$requestsPostBlock = wrpSliceIfBlockByCondition($requestsPhpView, "'POST'");

/* ---- I1: requests.php's POST handler verifies the submitted
   resolved_song_id names a REAL, visible tblSongs row (a SELECT ... FROM
   tblSongs WHERE SongId = ?) BEFORE the UPDATE that writes ResolvedSongId —
   "resolve to a real existing SongId" (#1867's explicit server-side
   requirement, same shape as G1/H1). Position-based: a verify-AFTER-the-
   UPDATE would already be too late to matter. ---- */
if ($requestsPostBlock === '') {
    $failures[] = "could not locate if (\$_SERVER['REQUEST_METHOD'] === 'POST') { ... } in manage/requests.php (slicer or file shape changed)";
} else {
    $selectPos = -1;
    if (preg_match('/SELECT\s+SongId\s+FROM\s+tblSongs\s+WHERE\s+SongId\s*=\s*\?/', $requestsPostBlock, $m, PREG_OFFSET_CAPTURE)) {
        $selectPos = $m[0][1];
    }
    $updatePos = strpos($requestsPostBlock, 'UPDATE tblSongRequests');
    if ($selectPos < 0) {
        $failures[] = "requests.php's POST handler no longer verifies the submitted resolved_song_id against tblSongs before use (#1867, rule #43 — 'the server MUST verify the id exists')";
    } elseif ($updatePos === false) {
        $failures[] = "requests.php's POST handler no longer writes UPDATE tblSongRequests — the write itself is missing";
    } elseif (!($selectPos < $updatePos)) {
        $failures[] = "requests.php's POST handler's existence check does not run BEFORE the UPDATE that uses the id — a check that runs after the write is too late to prevent it";
    }

    /* ---- I2: no create arm — this surface must never mint a song. ---- */
    if (strpos($requestsPostBlock, 'INSERT INTO tblSongs') !== false) {
        $failures[] = "requests.php's POST handler contains \"INSERT INTO tblSongs\" — this surface must never invent a song (#1867's explicit no-create-arm scope; songs are authored in the editor)";
    }

    /* ---- I3: rule #29 — the POST handler's CSRF gate is
       validateCsrfRequest(), not the bare validateCsrf() this page used to
       rely on alone. */
    if (strpos($requestsPostBlock, 'validateCsrfRequest(') === false) {
        $failures[] = "requests.php's POST handler no longer calls validateCsrfRequest() — reverted to the bare validateCsrf()-only CSRF gate rule #29 replaced";
    }
}

/* ---- I4: the "Resolved SongId" markup was actually converted from the old
   plain free-text `name="resolved_song_id"` box to the picker's hidden id +
   search input — the OLD shape (a *visible, typed* input carrying that
   `name`) must be gone, while the hidden id itself still carries the name
   (rule #33 — the write side still needs a POST key to read). ---- */
if (preg_match('/type="text"[^>]*name="resolved_song_id"/', $requestsRaw)) {
    $failures[] = 'requests.php still renders a visible type="text" input with name="resolved_song_id" — the #1867 picker did not replace the old free-text box (the hidden hand-off must carry the name now, not the visible search box)';
}
/* A `class="…"` ATTRIBUTE containing the token, not a bare substring: the
   adjoining doc-comment in the real file explains the wiring in prose using
   these SAME words ("fills the hidden req-resolved-song-id below") — a bare
   substring scan would stay green even if the real class attribute were
   renamed or deleted, exactly the stripper-trap class this file's own
   doc-block (§H3/G3 precedent) warns about. The regex requires the token
   inside `class="…"` (word-bounded, so `req-resolved-song-id` can't
   false-match inside a longer class like `req-resolved-song-id-extra`) but
   tolerates OTHER classes sharing the attribute (the real markup is
   `class="form-control form-control-sm req-resolved-song-name"`) — a bare
   `class="req-resolved-song-name"` equality check would itself be
   wrong-but-red against the real file; caught by mutation below. */
foreach (['req-resolved-song-name', 'req-resolved-song-id'] as $cls) {
    if (!preg_match('/\bclass="[^"]*\b' . preg_quote($cls, '/') . '\b[^"]*"/', $requestsRaw)) {
        $failures[] = "requests.php no longer emits an element carrying class=\"…{$cls}…\" — the #1867 \"Resolved SongId\" picker's markup is missing";
    }
}
if (strpos($requestsRaw, 'name="resolved_song_id"') === false) {
    $failures[] = 'requests.php no longer emits name="resolved_song_id" anywhere — the picker\'s hidden id is not wired into the form (rule #33)';
}

/* ---- I5: the client wiring attaches the picker with pickMode:'value'
   against THIS page's own ?action=song_search (never api2's, never a
   fabricated endpoint) — same reasoning as C1/C2/G3/H3. ---- */
if (strpos($requestsAllView, 'iHymnsPlaceSearch.attach') === false) {
    $failures[] = 'requests.php no longer calls window.iHymnsPlaceSearch.attach(...) for the "Resolved SongId" picker';
}
if (strpos($requestsAllView, "pickMode: 'value'") === false) {
    $failures[] = "requests.php's \"Resolved SongId\" picker no longer sets pickMode: 'value'";
}
if (strpos($requestsAllView, 'action=song_search') === false || strpos($requestsAllView, '/manage/requests') === false) {
    $failures[] = "requests.php's picker no longer points at THIS page's own /manage/requests?action=song_search — it must reuse its own handler, not api2's or a fabricated one";
}

/* ---- I6: requests.php declares exactly ONE ?action=song_search gate — this
   page had NO pre-existing handler (unlike §H's catalogues.php), so #1867
   added the ONE new handler; a second gate would be a duplicate fork. ---- */
$requestsSongSearchGateCount = substr_count($requestsPhpView, "=== 'song_search'");
if ($requestsSongSearchGateCount !== 1) {
    $failures[] = "requests.php declares {$requestsSongSearchGateCount} \"=== 'song_search'\" action gate(s) — expected exactly 1 (rule #22 — reuse the one handler, never fork a second)";
}

/* =========================================================================
 * §J — #1867 PUBLIC surface: the /request page's Songbook field
 * (js/modules/request-a-song.js — NOT window.iHymnsPlaceSearch, which is
 * admin-only and never ships in the public bundle; NOT an FK picker either
 * — tblSongRequests.Songbook stays free text, rule #44 — so §J's shape is
 * deliberately different from §A-I: no pickMode/hidden-id assertions here)
 * ========================================================================= */

/* ---- J1: the module imports combobox-a11y.js for its side effect (the
   #1594 part-2 keyboard/ARIA helper) and NEVER references
   window.iHymnsPlaceSearch — the admin-only script this public module must
   not depend on (it is loaded only via a classic <script src> on /manage/*
   pages and is not part of the public bundle). ---- */
if (strpos($reqJsAllView, "import './combobox-a11y.js'") === false) {
    $failures[] = "js/modules/request-a-song.js no longer imports './combobox-a11y.js' for its side effect — the Songbook picker's keyboard/ARIA handling is missing its dependency";
}
if (strpos($reqJsAllView, 'iHymnsPlaceSearch') !== false) {
    $failures[] = 'js/modules/request-a-song.js references iHymnsPlaceSearch — that module is admin-only (/manage/* classic <script src>) and is never shipped to the public bundle; the public Songbook picker must use combobox-a11y.js + apiFetch instead';
}
if (strpos($reqJsAllView, 'handleComboboxKeydown') === false || strpos($reqJsAllView, 'applyComboboxAria') === false) {
    $failures[] = 'js/modules/request-a-song.js no longer calls both window.iHymnsComboboxA11y.handleComboboxKeydown() and .applyComboboxAria() — the Songbook picker\'s keyboard/ARIA wiring is incomplete';
}

/* ---- J2: rule #31 — every network read goes through apiFetch(, never a
   bare fetch(. A literal, CASE-SENSITIVE substring scan for 'fetch(' is
   deliberately used instead of a word-boundary regex: 'apiFetch(' does NOT
   contain the lowercase substring 'fetch(' (its 6th character is capital
   'F', not 'f'), so this cannot false-positive on the module's own
   legitimate apiFetch(...) calls — verified against a fixture pair
   immediately below, not asserted on faith. ---- */
if (strpos($reqJsAllView, 'fetch(') !== false) {
    $failures[] = "js/modules/request-a-song.js contains a bare fetch(...) call (rule #31 — same-origin reads must go through apiFetch()/apiFetchJson() from js/utils/api-client.js, never bare fetch or a fetch override)";
}
/* Fixture proof for the J2 mechanism itself (not a mutation self-test up
   front, since it depends on nothing this file declares — inlined here,
   beside the assertion it backs, so the "why is this safe" reasoning and
   its proof stay together). */
if (strpos('await apiFetch(url)', 'fetch(') !== false) {
    $failures[] = 'INTERNAL: the J2 case-sensitive fetch( scan false-positives on apiFetch( — the mechanism itself is broken, not the file being scanned';
}
if (strpos('await fetch(url)', 'fetch(') === false) {
    $failures[] = 'INTERNAL: the J2 case-sensitive fetch( scan fails to catch a genuine bare fetch( call — the mechanism itself is broken, not the file being scanned';
}

/* ---- J3: the songbook data source is the PUBLIC /api?action=songbooks
   read, and the file never mentions a /manage/ path at all — an admin,
   auth-gated endpoint would 401/403 for the anonymous visitors /request
   serves (the whole point of this investigation step, per the task's
   "MUST use public api.php reads" constraint). ---- */
if (strpos($reqJsAllView, 'action=songbooks') === false) {
    $failures[] = "js/modules/request-a-song.js no longer fetches the public /api?action=songbooks read — the Songbook picker's data source is missing";
}
if (strpos($reqJsAllView, '/manage/') !== false) {
    $failures[] = 'js/modules/request-a-song.js references a /manage/ path — this module runs on the PUBLIC, anonymous-reachable /request page and must never call an auth-gated /manage/* endpoint';
}

/* ---- J4: the picker is actually invoked from the page's entry point,
   initRequestASong() — a helper function that exists but is never called is
   the exact "looks alive, does nothing" shape rule #30's own failure class
   warns about, just via a different mechanism (an unwired function instead
   of a CSP-blocked script). Windowed proximity match (same technique as
   G4/H-style checks) rather than a bare substring-anywhere check, so this
   actually proves the call happens INSIDE initRequestASong(), not merely
   somewhere else in the file. ---- */
if (!preg_match('/function\s+initRequestASong[\s\S]{0,3000}initSongbookPicker\(root\)/', $reqJsAllView)) {
    $failures[] = 'js/modules/request-a-song.js declares initSongbookPicker() but never calls it from initRequestASong() — the picker is dead code, unreachable from the page\'s own entry point';
}

/* ---- J5: the field STAYS free text (rule #44 — no vanity FK for a value
   that has no registry row to point at until a curator resolves the
   request). Locks in the "no hidden id, no pickMode" design decision so a
   future edit can't silently re-fork the admin FK-picker shape onto a field
   that was deliberately kept simpler — a hidden `songbook_id`/`songbook_abbr`
   field appearing here would be an unread param (rule #33) since
   tblSongRequests.Songbook has no such column to receive it. ---- */
if (preg_match('/name="songbook_(id|abbr)"/', $reqPhpRaw) || preg_match('/name="songbook_(id|abbr)"/', $reqJsAllView)) {
    $failures[] = 'a songbook_id/songbook_abbr field was added to the public request form or its module — tblSongRequests.Songbook has no such column; #1867\'s public surface deliberately fills the SAME free-text field, it does not add a parallel hidden FK (rule #33/#44)';
}

/* ---- J6: the fragment this module decorates carries NO <script> tag —
   #1867 adds zero PHP/markup surface to includes/pages/request-a-song.php,
   so test-fragment-inline-scripts.php has nothing new to catch here; this
   is a light, redundant confirmation of that fact scoped to just this one
   file (all-comments-stripped, since the fragment's own doc-block prose
   literally contains the string "<script>" inside a comment — the exact
   D1/§7 stripper-trap this file's doc-block already warns about). ---- */
if (strpos($reqPhpAllView, '<script') !== false) {
    $failures[] = 'includes/pages/request-a-song.php now contains a <script> tag — #1867\'s public Songbook picker must be wired entirely from js/modules/request-a-song.js (rule #30); an inline <script> in a $_cacheablePages fragment is CSP-dead and fails silently';
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
        fwrite(STDERR, "FAIL: Works + Songbook + Groups/Publishers/Catalogues/Requests registry-picker guard (#1864/#1865/#1868/#1866/#1867):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}

echo "PASS: Works + Songbook + Groups/Publishers/Catalogues/Requests registry-picker guard — publisherSearchRows() declared "
   . "exactly once in includes/publisher_helpers.php; all {$gateHandlerCount} manage/*.php "
   . "publisher_search handlers delegate to it with no re-inlined fork; \$persistWorkExtraFields writes "
   . "CopyrightHolder + CopyrightHolderId in lockstep via publisherResolvePickedOrCreate(), which falls "
   . "back to publisherFindOrCreateByName(); \"INSERT INTO tblPublishers\" is confined to the two funnel "
   . "files (" . count($insertSites) . " site(s) found); works.php's client wiring attaches all 4 #1864 "
   . "pickers with pickMode:'value', reuses api2's tune_search (no local fork) and this page's own "
   . "publisher_search; the copyright_holder_id wire contract is honoured at both ends. "
   . "§F (#1865): songbooks.php's 'create' case resolves the Publisher field via "
   . "publisherResolvePickedOrCreate() (never a direct publisherFindOrCreateByName() bypass) and seeds "
   . "tblSongbookPublishers; the publisher_id wire contract is honoured at both ends; the create-only "
   . "picker is wired with pickMode:'value' against this page's own publisher_search; the Edit arm's "
   . "quick field + richer multi-publisher picker are unchanged. "
   . "§G (#1868): groups.php's add_member handler verifies the submitted user_id against tblUsers BEFORE "
   . "the write and never mints a user; its picker reuses the EXISTING api2 user_search action (no local "
   . "fork). publishers.php's merge-target picker replaced the old all-publishers <select>, is wired with "
   . "pickMode:'value' against this page's own publisher_search with a dynamic exclude= bound to the "
   . "source select (a self-merge is unreachable through the UI); its merge handler never mints/forks and "
   . "still flows through the ONE publisherAdminMerge() core. Both #1868 surfaces are confirmed "
   . "search-select ONLY — no create arm on either path. "
   . "§H (#1866): catalogues.php's add_member handler verifies the submitted song_id against tblSongs "
   . "BEFORE the tblCatalogueSongs write and never mints a song; the old free-text SongId box is gone in "
   . "favour of the picker's hidden id, wired with pickMode:'value' against this page's OWN pre-existing "
   . "?action=song_search handler (exactly 1 gate found — no duplicate local fork); the song_id wire "
   . "contract is honoured at both ends. "
   . "§I (#1867 admin): requests.php's POST handler verifies the submitted resolved_song_id against tblSongs "
   . "BEFORE the tblSongRequests UPDATE, never mints a song, and its CSRF gate is validateCsrfRequest() "
   . "(rule #29); the old free-text box is gone in favour of the picker's hidden id, wired with "
   . "pickMode:'value' against this page's OWN NEW ?action=song_search handler ({$requestsSongSearchGateCount} "
   . "gate found — no duplicate fork); the resolved_song_id wire contract is honoured at both ends. "
   . "§J (#1867 public): js/modules/request-a-song.js's Songbook field imports combobox-a11y.js (never "
   . "iHymnsPlaceSearch, which is admin-only), wires both handleComboboxKeydown()/applyComboboxAria(), reads "
   . "exclusively via apiFetch() (zero bare fetch( calls, rule #31) against the PUBLIC /api?action=songbooks "
   . "read (zero /manage/ references), is actually invoked from initRequestASong(), keeps the field free "
   . "text with no vanity FK field (rule #44), and adds no <script> to the fragment it decorates (rule #30). "
   . "All mutation self-tests went red/green as expected.\n";
exit(0);
