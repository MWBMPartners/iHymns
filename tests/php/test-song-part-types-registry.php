<?php

declare(strict_types=1);

/**
 * iHymns — Song-part type registry sourcing guard (#1869, epic #1863, rule #43)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * #1869 is the LAST item in the picker rollout (#1863/rule #43), and it is a
 * DIFFERENT mechanism from every other item in that epic: not a live-search
 * typeahead, but REGISTRY SOURCING — the v2 Song Editor's Structure tab used
 * to have its 10 section types (Verse, Chorus, Bridge, …) typed directly into
 * `structure-tab.js`; they now come from the `tblSongPartTypes` database table
 * (#1138, already seeded with 16), with the original 10 kept as a minimal
 * built-in fallback for an install that hasn't run that migration yet. This
 * guard proves four things stay true:
 *
 *   A. `includes/song_part_type_helpers.php`'s reader is existence-gated and
 *      NEVER throws — it degrades to `[]` rather than crashing the editor
 *      page on an un-migrated `tblSongPartTypes` (rule #19/#20/#9's STRICT
 *      mysqli hazard: a bare SELECT against a missing table throws).
 *   B. That degrade-to-`[]` behaviour is BEHAVIOURAL, not just a source-scan
 *      claim — proven by handing the reader a mysqli double whose every
 *      `prepare()` throws and asserting the call still returns `[]`.
 *   C. `manage/editor/editor2.php` (the ONE caller — rule #22) actually wires
 *      the reader into the SAME classic-global bootstrap-payload convention
 *      the page's three other registries already use (window._iHymnsLinkTypes
 *      / _iHymnsRecordingIdTypes / _iHymnsLicenceTypes), emitted BEFORE the
 *      `<script type="module">` block that imports structure-tab.js.
 *   D. `structure-tab.js` (the ONE consumer — rule #22) actually SOURCES its
 *      type `<select>` from that served data, AND its pre-#1869 hardcoded
 *      list survives byte-for-byte as the fallback used only when the served
 *      data is missing/empty — never deleted, never silently ignored.
 *
 * WHY A NEW FILE, NOT AN EXTENSION OF test-work-registry-pickers.php
 * --------------------------------------------------------------------------
 * That guard's §A-J all cover the SAME mechanism (`window.iHymnsPlaceSearch
 * .attach()` live-search typeaheads across works.php/songbooks.php/
 * groups.php/publishers.php/catalogues.php/requests.php/request-a-song.js) —
 * one PHP-source-units-based toolkit, one family of assertions. #1869 shares
 * NONE of that: no typeahead, no `pickMode`, no find-or-create funnel, and its
 * one client file is plain JavaScript with no PHP in it at all, needing its
 * own (self-tested) comment stripper rather than that file's `phpSourceUnits()`
 * PHP-token approach. Per the #1869 spec's own build-order note, this item
 * "has no dependency on #1864's cores" — bolting it onto that file would mix
 * two unrelated mechanisms under one guard name (rule #34's "a scanner that
 * covers everything covers nothing precisely" concern).
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * --------------------------------------------------------------------------
 * The served-global name (`_iHymnsSongPartTypes`) and the reader function name
 * (`songPartTypesForPicker`) are read ONCE from the real source files below
 * and reused for every subsequent assertion, rather than typed twice — so a
 * future rename of either updates every assertion that depends on it instead
 * of silently going stale (the #1581 event-name class of drift).
 *
 * Every assertion below names the RED mutation that was applied to the real
 * tree, confirmed red, and reverted during development (rule #34).
 *
 * @link appWeb/public_html/includes/song_part_type_helpers.php  the reader under guard
 * @link appWeb/public_html/manage/editor/editor2.php             the ONE caller
 * @link appWeb/public_html/manage/editor/v2/structure-tab.js     the ONE consumer
 * @link tests/php/lib/php_source_units.php                       the PHP-side scanner
 * @see  tests/php/test-work-registry-pickers.php                 the sibling picker guard (different mechanism)
 * @see  #1869, epic #1863, CLAUDE.md rule #43
 */

$repoRoot  = dirname(__DIR__, 2);
$failures  = [];

require_once $repoRoot . '/tests/php/lib/php_source_units.php';
require_once $repoRoot . '/tests/php/lib/mysqli_doubles.php';

$helpersPath   = $repoRoot . '/appWeb/public_html/includes/song_part_type_helpers.php';
$editor2Path   = $repoRoot . '/appWeb/public_html/manage/editor/editor2.php';
$structurePath = $repoRoot . '/appWeb/public_html/manage/editor/v2/structure-tab.js';

foreach (['song_part_type_helpers.php' => $helpersPath, 'editor2.php' => $editor2Path, 'structure-tab.js' => $structurePath] as $label => $path) {
    if (!is_file($path)) {
        $failures[] = "Missing expected file: {$label} ({$path})";
    }
}
if ($failures) {
    fwrite(STDERR, "FAIL: song-part-types registry guard (#1869) — cannot locate source:\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

$helpersSrc   = (string)file_get_contents($helpersPath);
$editor2Src   = (string)file_get_contents($editor2Path);
$structureSrc = (string)file_get_contents($structurePath);

/* =========================================================================
 * A tiny, SELF-TESTED, quote-aware JS comment stripper (structure-tab.js has
 * no PHP/HTML in it — token_get_all() is the wrong tool here, unlike the
 * mixed PHP+HTML files test-work-registry-pickers.php's toolkit targets).
 * Blanks `// …` and `/* … *\/` while (a) never touching text inside a
 * '…'/"…"/`…` string or template literal, and (b) preserving newline COUNT
 * so a future line-numbered failure report would still point at the right
 * line. A naive `preg_replace('~//.*~', ...)` would corrupt a string like
 * `"https://example.com"` — the self-test below proves this stripper does
 * NOT make that mistake, and does not mis-fire on a `/^\w/`-style regex
 * literal (this file has several — see the self-test fixture).
 * ========================================================================= */
function spTypesStripJsComments(string $src): string
{
    $out   = '';
    $n     = strlen($src);
    $i     = 0;
    $inStr = null; // ', ", ` — or null when not inside a string/template literal
    while ($i < $n) {
        $c = $src[$i];
        if ($inStr !== null) {
            if ($c === '\\' && $i + 1 < $n) { $out .= $c . $src[$i + 1]; $i += 2; continue; }
            $out .= $c;
            if ($c === $inStr) { $inStr = null; }
            $i++;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $inStr = $c; $out .= $c; $i++; continue; }
        if ($c === '/' && $i + 1 < $n && $src[$i + 1] === '/') {
            $j = strpos($src, "\n", $i);
            if ($j === false) { $i = $n; } else { $out .= "\n"; $i = $j + 1; }
            continue;
        }
        if ($c === '/' && $i + 1 < $n && $src[$i + 1] === '*') {
            $j   = strpos($src, '*/', $i + 2);
            $end = ($j === false) ? $n : $j + 2;
            $out .= str_repeat("\n", substr_count(substr($src, $i, $end - $i), "\n"));
            $i = $end;
            continue;
        }
        $out .= $c;
        $i++;
    }
    return $out;
}

/* ---- self-test the stripper BEFORE trusting it against real source (rule #34) ---- */
$stripperFixture = <<<'JS'
const a = "https://example.com"; // a trailing comment with // inside it
/* a block comment
   spanning lines with a fake // inside */
const re = /^\w/;
const b = `template with // not a comment` + 'and /* not a comment either */';
JS;
$stripped = spTypesStripJsComments($stripperFixture);
if (strpos($stripped, 'https://example.com') === false) {
    $failures[] = 'Stripper self-test: a URL inside a string was corrupted (the // false-triggered a line-comment strip).';
}
if (strpos($stripped, 'template with // not a comment') === false) {
    $failures[] = 'Stripper self-test: a template literal containing // was corrupted.';
}
if (strpos($stripped, "and /* not a comment either */") === false) {
    $failures[] = 'Stripper self-test: a string containing /* */ was corrupted.';
}
if (strpos($stripped, 'a trailing comment') !== false) {
    $failures[] = 'Stripper self-test: a real // line comment was NOT removed.';
}
if (strpos($stripped, 'spanning lines with a fake') !== false) {
    $failures[] = 'Stripper self-test: a real /* */ block comment was NOT removed.';
}
if (strpos($stripped, 'const re = /^\w/;') === false) {
    $failures[] = 'Stripper self-test: a regex literal (/^\\w/) was corrupted by the comment stripper.';
}
if (substr_count($stripperFixture, "\n") !== substr_count($stripped, "\n")) {
    $failures[] = 'Stripper self-test: line count changed — future failures would misreport their line number.';
}

$structureCode = spTypesStripJsComments($structureSrc);

/* =========================================================================
 * PART A — includes/song_part_type_helpers.php: existence-gated, never throws.
 * ========================================================================= */
$helperUnits = phpSourceUnits($helpersSrc);

if (!isset($helperUnits['songPartTypesForPicker'])) {
    $failures[] = 'A0: includes/song_part_type_helpers.php has no songPartTypesForPicker() function. '
                . 'RED mutation: rename/delete the function.';
} else {
    $pickerCode = $helperUnits['songPartTypesForPicker']['code'];

    /* A1 — the picker reader is gated behind an existence check before it
       touches tblSongPartTypes at all. RED mutation: delete the
       `songPartTypesTableExists(` call and query tblSongPartTypes unconditionally. */
    if (strpos($pickerCode, 'songPartTypesTableExists(') === false) {
        $failures[] = 'A1: songPartTypesForPicker() does not call songPartTypesTableExists() — '
                    . 'an un-migrated install would throw under STRICT mysqli on the bare SELECT.';
    }

    /* A2 — wrapped in try/catch (mysqli STRICT throws on a broken connection
       even with the existence gate — the RESULT of that call can itself
       throw a Throwable while executing/fetching). RED mutation: delete the
       try/catch around the query. */
    if (phpUnitsCountToken($pickerCode, T_TRY) < 1 || phpUnitsCountToken($pickerCode, T_CATCH) < 1) {
        $failures[] = 'A2: songPartTypesForPicker() is missing a try/catch — a DB failure would propagate '
                    . 'instead of degrading to [].';
    }

    /* A3 — the catch path degrades to an EMPTY array, not a rethrow / a null /
       a fatal. RED mutation: change `$out = [];` inside the catch to
       `throw $_e;` (or remove the reset entirely, leaving a partially-built
       $out from before the failure). */
    $catches = phpUnitsCatchBlocks($pickerCode);
    $degrades = false;
    foreach ($catches as $cb) {
        if (preg_match('/\$out\s*=\s*\[\s*\]\s*;/', $cb['body'])) { $degrades = true; break; }
    }
    if (!$degrades) {
        $failures[] = 'A3: songPartTypesForPicker()\'s catch block does not reset the result to [] — '
                    . 'a failure mid-read could leak a partial list or fail to degrade cleanly.';
    }

    /* A4 — never a bare, ungated `FROM tblSongPartTypes` outside the exists-gate
       (i.e. the function body should have exactly the one query, guarded).
       RED mutation: add a second, unguarded `$conn->query('SELECT * FROM
       tblSongPartTypes')` above the existing gated one. */
    if (!preg_match('/if\s*\(\s*songPartTypesTableExists\s*\([^)]*\)\s*\)\s*\{[^}]*@SQL:SELECT:tblSongPartTypes@/s', $pickerCode)) {
        $failures[] = 'A4: the SELECT against tblSongPartTypes is not textually inside the '
                    . 'songPartTypesTableExists(...) if-guard (or the query shape changed unexpectedly).';
    }
}

if (!isset($helperUnits['songPartTypesTableExists'])) {
    $failures[] = 'A5: includes/song_part_type_helpers.php has no songPartTypesTableExists() function.';
} else {
    $existsCode = $helperUnits['songPartTypesTableExists']['code'];
    /* A6 — the existence probe itself never throws: try/catch, degrading to
       false. RED mutation: delete the try/catch around the INFORMATION_SCHEMA
       probe. */
    if (phpUnitsCountToken($existsCode, T_TRY) < 1 || phpUnitsCountToken($existsCode, T_CATCH) < 1) {
        $failures[] = 'A6: songPartTypesTableExists() is missing a try/catch around its INFORMATION_SCHEMA probe.';
    }
    /* A7 — it actually probes INFORMATION_SCHEMA (never `SELECT … FROM
       tblSongPartTypes` directly, which is exactly the STRICT-throw hazard
       an existence gate exists to avoid). RED mutation: replace the
       INFORMATION_SCHEMA probe with a bare SELECT against the real table. */
    $existsSql = implode(' | ', $helperUnits['songPartTypesTableExists']['sqlOnly']);
    if (stripos($existsSql, 'INFORMATION_SCHEMA') === false) {
        $failures[] = 'A7: songPartTypesTableExists() does not query INFORMATION_SCHEMA — '
                    . 'it may throw on an un-migrated install instead of probing safely.';
    }
}

/* =========================================================================
 * PART B — BEHAVIOURAL fail-open proof: a mysqli double whose every
 * prepare() throws must still make songPartTypesForPicker() return [],
 * never propagate. This is the strongest form of "won't throw on an
 * un-migrated env" — a real call, not just a source-scan claim.
 * ========================================================================= */
$_SERVER['SCRIPT_FILENAME'] = 'test-runner'; // dodge the direct-HTTP-access 403 guard
require_once $helpersPath;

$throwing = new \ClaimProbeFailingMysqli();
try {
    $result = songPartTypesForPicker($throwing);
    if ($result !== []) {
        $failures[] = 'B1: songPartTypesForPicker($throwingDb) did not return [] — got ' . var_export($result, true);
    }
} catch (\Throwable $e) {
    $failures[] = 'B1: songPartTypesForPicker($throwingDb) THREW instead of degrading: ' . get_class($e) . ': ' . $e->getMessage();
}
try {
    if (songPartTypesTableExists($throwing) !== false) {
        $failures[] = 'B2: songPartTypesTableExists($throwingDb) did not return false.';
    }
} catch (\Throwable $e) {
    $failures[] = 'B2: songPartTypesTableExists($throwingDb) THREW instead of returning false: ' . $e->getMessage();
}

/* B1b — a SECOND, more targeted double: the existence probe reports "table
   present" (so the exists-gate is satisfied and the code proceeds to the main
   query), but the main query() call itself throws (a connection drop
   mid-request, distinct from "table missing"). This is the ONE scenario that
   specifically exercises songPartTypesForPicker()'s OWN try/catch rather than
   songPartTypesTableExists()'s — B1 above (ClaimProbeFailingMysqli) fails at
   the EXISTS probe, so it never actually reaches the outer catch; without
   this double the outer try/catch's removal (A2's mutation) would stay
   invisible to every behavioural assertion and only be caught by the source
   scan. RED mutation: remove songPartTypesForPicker()'s try/catch (the exact
   A2 mutation) — confirmed during development to turn B1b red on its own. */
final class SongPartTypesExistsOkThenQueryThrowsStmt
{
    public function bind_param(string $t, &...$vars): bool { return true; }
    public function execute(): bool { return true; }
    public function get_result(): object { return new class { public function fetch_row(): array { return ['1']; } }; }
    public function close(): bool { return true; }
}
final class SongPartTypesExistsOkThenQueryThrowsMysqli extends \mysqli
{
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query): mixed { return new SongPartTypesExistsOkThenQueryThrowsStmt(); }
    #[\ReturnTypeWillChange]
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mixed
    {
        throw new \mysqli_sql_exception('synthetic mid-query connection drop', 2006);
    }
}
try {
    $result = songPartTypesForPicker(new SongPartTypesExistsOkThenQueryThrowsMysqli());
    if ($result !== []) {
        $failures[] = 'B1b: songPartTypesForPicker() with an exists-ok-then-query-throws double did not return [] — got ' . var_export($result, true);
    }
} catch (\Throwable $e) {
    $failures[] = 'B1b: songPartTypesForPicker() with an exists-ok-then-query-throws double THREW: ' . get_class($e) . ': ' . $e->getMessage();
}

/* B3 — the live-shape happy path (a fixture double, not a real DB): the
   reader returns the rows it's handed, ordered by SortOrder, as
   {slug,name} pairs. Proves the reader isn't ACCIDENTALLY always returning
   [] regardless of what the "DB" holds. */
final class SongPartTypesFixtureResult
{
    public function __construct(private array $rows) {}
    public function fetch_all(int $mode = MYSQLI_ASSOC): array { return $this->rows; }
}
final class SongPartTypesFixtureStmt
{
    public function __construct(private SongPartTypesFixtureMysqli $db) {}
    public function bind_param(string $t, &...$vars): bool { return true; }
    public function execute(): bool { return true; }
    public function get_result(): object { return new class { public function fetch_row(): array { return ['1']; } }; }
    public function close(): bool { return true; }
}
final class SongPartTypesFixtureMysqli extends \mysqli
{
    public function __construct(private array $rows) {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query): mixed { return new SongPartTypesFixtureStmt($this); }
    #[\ReturnTypeWillChange]
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mixed { return new SongPartTypesFixtureResult($this->rows); }
}
$fixtureRows = [
    ['Slug' => 'chorus', 'Name' => 'Chorus', 'IsNumbered' => 1, 'SortOrder' => 20],
    ['Slug' => 'intro',  'Name' => 'Intro',  'IsNumbered' => 0, 'SortOrder' => 0],
];
$fixtureDb = new SongPartTypesFixtureMysqli($fixtureRows);
$fixtureOut = songPartTypesForPicker($fixtureDb);
if ($fixtureOut !== [['slug' => 'chorus', 'name' => 'Chorus'], ['slug' => 'intro', 'name' => 'Intro']]) {
    $failures[] = 'B3: songPartTypesForPicker($fixtureDb) did not pass through the fixture rows unchanged — got ' . var_export($fixtureOut, true);
}

/* =========================================================================
 * PART C — editor2.php wiring: the ONE caller (rule #22) actually requires
 * the helper, calls songPartTypesForPicker(), and emits it via the SAME
 * classic-global bootstrap convention the page's other three registries use
 * — BEFORE the `<script type="module">` block that imports structure-tab.js.
 * ========================================================================= */
$editor2Units = phpSourceUnits($editor2Src);
$editor2Code  = $editor2Units['(file scope)']['code'];

/* C1 — requires the helper file. RED mutation: delete the require_once line. */
if (strpos($editor2Code, "song_part_type_helpers.php") === false) {
    $failures[] = "C1: editor2.php never references song_part_type_helpers.php.";
}
/* C2 — calls the reader. RED mutation: replace the call with a hardcoded []. */
if (!preg_match('/\$\w+\s*=\s*songPartTypesForPicker\s*\(/', $editor2Code)) {
    $failures[] = 'C2: editor2.php never calls songPartTypesForPicker(...).';
}

/* Tree-derive the served global's name from the emission site itself, rather
   than hand-typing "_iHymnsSongPartTypes" twice — a future rename of the
   global only has to happen in ONE place to keep this guard passing. */
if (!preg_match('/window\.(_iHymns\w*SongPartTypes\w*)\s*=/', $editor2Src, $gm)) {
    $failures[] = 'C3: editor2.php does not assign a window._iHymns…SongPartTypes… global anywhere '
                . '(the served bootstrap payload structure-tab.js is meant to read).';
} else {
    $globalName = $gm[1];

    /* C4 — the assignment right-hand side is a json_encode(...) of the SAME
       variable songPartTypesForPicker() was assigned into (not some other,
       unrelated array) — checked on the raw source since the RHS is a PHP
       short-echo `<?= ... ?>` sitting inside inline HTML. */
    if (!preg_match('/\$(\w+)\s*=\s*songPartTypesForPicker\s*\(/', $editor2Src, $vm)) {
        $failures[] = 'C4: could not find the variable songPartTypesForPicker(...) is assigned to.';
    } else {
        $varName = $vm[1];
        $emitPattern = '/window\.' . preg_quote($globalName, '/') . '\s*=\s*<\?=\s*json_encode\(\s*\$' . preg_quote($varName, '/') . '\b/';
        if (!preg_match($emitPattern, $editor2Src)) {
            $failures[] = "C5: window.{$globalName} is not assigned json_encode(\${$varName}, ...) — "
                        . 'the emitted global and the PHP variable songPartTypesForPicker() populated have drifted apart. '
                        . 'RED mutation: emit an unrelated array (e.g. []) instead of $' . $varName . '.';
        }
    }

    /* C6 — ORDERING: the <script> emitting window.{$globalName} appears
       BEFORE the <script type="module"> block that imports structure-tab.js
       (a fragment served AFTER the module runs would be read as `undefined`
       — rule #30's "wire it from a real ES module" concern, applied to
       ordering rather than existence). RED mutation: move the emission
       <script> tag to after the `<script type="module">` block. */
    $globalPos = strpos($editor2Src, 'window.' . $globalName . ' =');
    $modulePos = strpos($editor2Src, "import { mountStructureTab } from './v2/structure-tab.js';");
    if ($globalPos === false || $modulePos === false || $globalPos > $modulePos) {
        $failures[] = 'C6: window.' . $globalName . ' is not emitted BEFORE the module script that imports '
                    . 'structure-tab.js — it would read as undefined at the time the module evaluates.';
    }

    /* C7 — structure-tab.js actually reads the SAME global name editor2.php
       emits (tree-derived — not hand-typed on either side). RED mutation:
       rename the global on one side only. */
    if (strpos($structureCode, 'window.' . $globalName) === false && strpos($structureCode, '_iHymnsSongPartTypes') === false) {
        $failures[] = "D-link: structure-tab.js never references window.{$globalName} — editor2.php emits a "
                    . 'global nothing reads (rule #33\'s "both ends" check, applied to a bootstrap payload).';
    }
}

/* =========================================================================
 * PART D — structure-tab.js: sources from the served registry, AND the
 * pre-#1869 hardcoded list survives, byte-for-byte, as the fallback.
 * ========================================================================= */

/* D1 — the original 10-entry COMPONENT_TYPES fallback list is still present,
   with its EXACT original values (the "minimal built-in fallback" the task
   requires — not a shortened or reordered stand-in). RED mutation: delete one
   entry from the array, or delete the const entirely. */
$expectedFallback = ['verse', 'chorus', 'refrain', 'bridge', 'pre-chorus', 'tag', 'coda', 'intro', 'outro', 'interlude'];
if (!preg_match('/const\s+COMPONENT_TYPES\s*=\s*\[([^\]]*)\]/s', $structureCode, $fm)) {
    $failures[] = 'D1: structure-tab.js no longer declares a COMPONENT_TYPES const — the built-in fallback is gone.';
} else {
    preg_match_all("/'([^']*)'/", $fm[1], $items);
    if ($items[1] !== $expectedFallback) {
        $failures[] = 'D1: COMPONENT_TYPES no longer matches its pre-#1869 fallback list. Expected '
                    . implode(',', $expectedFallback) . ' got ' . implode(',', $items[1])
                    . ' — the minimal built-in fallback must survive unchanged.';
    }
}

/* D2 — the <select> is populated from a RESOLVED variable (fed by the served
   registry when present), not directly from COMPONENT_TYPES.forEach(...).
   RED mutation: revert the option-building loop to `COMPONENT_TYPES.forEach`. */
if (preg_match('/typeSel\.style\.width[^;]*;(.{0,400}?)\.forEach\s*\(\s*\(t\)\s*=>\s*\{/s', $structureCode, $sm)) {
    if (strpos($sm[0], 'COMPONENT_TYPES.forEach') !== false) {
        $failures[] = 'D2: the type <select> is populated directly from COMPONENT_TYPES.forEach(...) — '
                    . 'it must source from the resolved (registry-aware) list instead.';
    }
} else {
    $failures[] = 'D2: could not locate the type <select> option-building loop in structure-tab.js '
                . '(file structure changed — re-anchor this assertion).';
}

/* D3 — there IS a fallback path: some function reads window._iHymns…
   SongPartTypes… AND, in the same breath, falls back to COMPONENT_TYPES when
   it is missing/empty (Array.isArray + a length check, not just an existence
   check that would accept `[]` as "present"). RED mutation: delete the
   `.length > 0` / emptiness check, or delete the COMPONENT_TYPES fallback
   branch, leaving only the served-data branch (crashes/renders empty on an
   un-migrated install). */
if (!preg_match('/function\s+resolvePartTypes\s*\([^)]*\)\s*\{(.*?)\n\}/s', $structureCode, $rm)) {
    $failures[] = 'D3: structure-tab.js has no resolvePartTypes()-shaped resolver function '
                . '(source structure changed — re-anchor this assertion).';
} else {
    $body = $rm[1];
    $mentionsGlobal   = (bool)preg_match('/window\.\w*SongPartTypes\w*/', $body);
    /* Bound TOGETHER (Array.isArray(x) && x.length > 0 on the SAME variable),
       not merely present somewhere in the function — the resolved list is
       separately length-checked too (`mapped.length > 0`), so a looser
       "does .length>0 appear anywhere in this body" check would stay green
       even after the served-array's OWN emptiness guard was deleted (this
       was caught live during mutation testing: removing `served.length > 0`
       and leaving only `Array.isArray(served)` did not go red until this
       regex was tightened to name the SAME identifier on both halves). */
    $checksArrayShape = (bool)preg_match('/Array\.isArray\s*\(\s*(\w+)\s*\)\s*&&\s*\1\.length\s*>\s*0/', $body);
    $fallsBackToConst = strpos($body, 'COMPONENT_TYPES') !== false;
    if (!$mentionsGlobal) {
        $failures[] = 'D3a: resolvePartTypes() never reads the window._iHymns…SongPartTypes… bootstrap global.';
    }
    if (!$checksArrayShape) {
        $failures[] = 'D3b: resolvePartTypes() does not guard the served value with '
                    . 'Array.isArray(x) && x.length > 0 (same variable, both halves) — '
                    . 'a served [] (un-migrated install) could slip through as "present" and leave the dropdown empty.';
    }
    if (!$fallsBackToConst) {
        $failures[] = 'D3c: resolvePartTypes() never falls back to COMPONENT_TYPES — the fallback path is unreachable.';
    }
}

/* D4 — no bare `fetch(` was introduced (rule #31): this feature is
   server-bootstrap-sourced, not a client-side network call, so
   structure-tab.js should still contain NEITHER `fetch(` NOR `apiFetch(` for
   this vocabulary. RED mutation: add a client-side `fetch('/manage/editor/
   api2?action=song_part_types')` call instead of reading the bootstrap
   global. */
if (preg_match('/[^\.\w]fetch\s*\(/', $structureCode)) {
    $failures[] = 'D4: structure-tab.js now calls fetch(...) — #1869 is bootstrap-payload-sourced, not a live '
                . 'client request; a new network call here would also need to go through apiFetch()/apiFetchJson() '
                . '(rule #31), not a bare fetch().';
}

if ($failures) {
    fwrite(STDERR, "FAIL: song-part-types registry guard (#1869):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\n");
    exit(1);
}
echo "PASS: song-part-types registry guard — stripper self-test ok; "
   . "includes/song_part_type_helpers.php is existence-gated + never throws (source scan + a throwing-double "
   . "behavioural call + a fixture-double round-trip); editor2.php wires it into the classic-global bootstrap "
   . "payload BEFORE the module script; structure-tab.js sources the <select> from the served registry with the "
   . "pre-#1869 10-entry list surviving byte-for-byte as the fallback.\n";
exit(0);
