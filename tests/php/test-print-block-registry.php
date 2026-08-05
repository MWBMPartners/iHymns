<?php

declare(strict_types=1);

/**
 * iHymns — Print block-type registry agreement guard (#1767, rule #35)
 *
 * ELI5: three files each keep their own copy of "what print blocks exist"
 * and "what options each block has". If they drift apart, a curator adds a
 * block or ticks an option that then silently does nothing on the printout —
 * no error, no toast (rule #30 / #35 failure class). This guard makes the
 * three copies prove they still agree, so the drift can't happen unseen.
 *
 * Detail: the print system's block contract lives in THREE places that must
 * agree with nothing but a code-comment holding them together — the exact
 * "comment is not a mechanism" shape rule #35 exists to kill:
 *
 *   1. `PRINT_BLOCK_TYPES` in js/modules/print.js — the EDITOR palette +
 *      per-block option defaults. What a curator can add and tweak.
 *   2. the `renderBlock()` switch in the SAME file — the RENDERER. A type in
 *      the registry but missing a `case` renders nothing (silent no-op).
 *   3. `$BLOCK_SCHEMA` in manage/print-templates.php — the SERVER allow-list.
 *      A POSTed type or option key absent here is DROPPED on save, so a
 *      control the editor offers whose key the server discards is a silent
 *      no-op too (the #1565/#1581 failure class, applied to print).
 *
 * The guard derives all three sets FROM THE TREE (rule #34 — never a list
 * typed here) and asserts:
 *   (a) block-type sets are identical across all three;
 *   (b) for every type, the option-key set in PRINT_BLOCK_TYPES.options
 *       equals the option-key set in $BLOCK_SCHEMA (so no editor control is
 *       silently stripped, and no server-accepted option is unreachable);
 *   (c) a parser-sanity FLOOR (>= 8 types) so a regex that silently matched
 *       nothing can't report a false "they agree because both are empty"
 *       (rule #34 — a scanner that under-reports is worse than none).
 *
 * Mutation-proven (rule #34): remove a type or an option key from ANY of the
 * three sources and this goes red; restore and it is byte-identically green.
 *
 *   php tests/php/test-print-block-registry.php
 *
 * Exit status 0 = agree, 1 = drift.
 *
 * @see .claude/CLAUDE.md rule #35 (cross-file agreement needs a mechanism)
 * @see .claude/CLAUDE.md rule #34 (tree-derived, mutation-proven guards)
 */

$repoRoot  = dirname(__DIR__, 2);
$printJs   = $repoRoot . '/appWeb/public_html/js/modules/print.js';
$adminPhp  = $repoRoot . '/appWeb/public_html/manage/print-templates.php';

$fail = [];
foreach (['print.js' => $printJs, 'print-templates.php' => $adminPhp] as $label => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FATAL: $label not found at $path\n");
        exit(1);
    }
}

$jsSrc  = (string)file_get_contents($printJs);
$phpSrc = (string)file_get_contents($adminPhp);

/**
 * Extract the body of a delimited block, e.g. the `{ ... }` after
 * `PRINT_BLOCK_TYPES = ` or the `[ ... ]` after `$BLOCK_SCHEMA = `.
 * Returns '' when the anchor isn't found (caller treats '' as a hard fail
 * via the sanity floor, so a moved/renamed anchor cannot pass silently).
 */
function ptRegion(string $src, string $pattern): string
{
    return preg_match($pattern, $src, $m) ? $m[1] : '';
}

/* ---- 1. PRINT_BLOCK_TYPES: type keys + per-type option keys ---- */
/* Region between `PRINT_BLOCK_TYPES = {` and the closing `\n};`. */
$jsRegion = ptRegion($jsSrc, '/PRINT_BLOCK_TYPES\s*=\s*\{(.*?)\n\};/s');
$jsTypes  = [];           // type => [optKey, ...]
/* Each type is one line: `    title:  { label: '…', options: { a: true } },`
   Match the type key and the inner `options: { … }` (no nested braces here). */
if (preg_match_all('/^\s*(\w+)\s*:\s*\{.*?options\s*:\s*\{([^{}]*)\}/m', $jsRegion, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $row) {
        $type = $row[1];
        $opts = [];
        if (preg_match_all('/(\w+)\s*:/', $row[2], $om)) {
            $opts = $om[1];
        }
        sort($opts);
        $jsTypes[$type] = $opts;
    }
}

/* ---- 2. renderBlock() switch: the `case '<type>':` set ---- */
$switchTypes = [];
if (preg_match_all("/case\s+'([a-z]+)'\s*:/", $jsSrc, $cm)) {
    /* De-dupe: `song_detail`/`song_data` style fall-through doesn't occur here,
       but a type could appear in more than one switch across the file; scope to
       renderBlock by intersecting later against the registry set. */
    $switchTypes = array_values(array_unique($cm[1]));
}

/* ---- 3. $BLOCK_SCHEMA: type keys + per-type option keys ---- */
$phpRegion = ptRegion($phpSrc, '/\$BLOCK_SCHEMA\s*=\s*\[(.*?)\n\];/s');
$phpTypes  = [];          // type => [optKey, ...]
/* Each type: `'title' => [],`  or  `'lyrics' => ['showLabels' => 'bool', …],` */
if (preg_match_all("/'(\w+)'\s*=>\s*\[([^\]]*)\]/", $phpRegion, $pm, PREG_SET_ORDER)) {
    foreach ($pm as $row) {
        $type = $row[1];
        $opts = [];
        if (preg_match_all("/'(\w+)'\s*=>/", $row[2], $om)) {
            $opts = $om[1];
        }
        sort($opts);
        $phpTypes[$type] = $opts;
    }
}

/* ---- (c) parser-sanity floor (rule #34 anti-under-report) ---- */
const PT_MIN_TYPES = 8;
if (count($jsTypes) < PT_MIN_TYPES) {
    $fail[] = sprintf('PRINT_BLOCK_TYPES parsed only %d types (< %d) — parser anchor moved or file emptied.',
        count($jsTypes), PT_MIN_TYPES);
}
if (count($phpTypes) < PT_MIN_TYPES) {
    $fail[] = sprintf('$BLOCK_SCHEMA parsed only %d types (< %d) — parser anchor moved or file emptied.',
        count($phpTypes), PT_MIN_TYPES);
}

/* ---- (a) block-type sets identical across all three ---- */
$jsKeys  = array_keys($jsTypes);
$phpKeys = array_keys($phpTypes);
sort($jsKeys); sort($phpKeys);

$missingInPhp = array_diff($jsKeys, $phpKeys);
$missingInJs  = array_diff($phpKeys, $jsKeys);
foreach ($missingInPhp as $t) { $fail[] = "Type '$t' is in PRINT_BLOCK_TYPES (print.js) but missing from \$BLOCK_SCHEMA (print-templates.php) — server would DROP it on save."; }
foreach ($missingInJs  as $t) { $fail[] = "Type '$t' is in \$BLOCK_SCHEMA (print-templates.php) but missing from PRINT_BLOCK_TYPES (print.js) — editor can't offer it."; }

/* Every registry type must have a renderBlock() case (else it renders nothing). */
$notRendered = array_diff($jsKeys, array_intersect($switchTypes, $jsKeys));
foreach ($notRendered as $t) { $fail[] = "Type '$t' is registered but has no `case '$t':` in renderBlock() — it would render NOTHING (silent no-op)."; }

/* ---- (b) per-type option-key sets match between JS defaults and PHP schema ---- */
foreach ($jsKeys as $t) {
    if (!isset($phpTypes[$t])) { continue; }   // already reported by (a)
    $jsOpts  = $jsTypes[$t];
    $phpOpts = $phpTypes[$t];
    $onlyJs  = array_diff($jsOpts, $phpOpts);
    $onlyPhp = array_diff($phpOpts, $jsOpts);
    foreach ($onlyJs as $k)  { $fail[] = "Block '$t' option '$k' has a JS default (PRINT_BLOCK_TYPES) but no \$BLOCK_SCHEMA entry — the server would DROP it on save (silent no-op)."; }
    foreach ($onlyPhp as $k) { $fail[] = "Block '$t' option '$k' is accepted by \$BLOCK_SCHEMA but has no PRINT_BLOCK_TYPES default — the editor never offers it (unreachable)."; }
}

/* ---- 4. PAGE OPTIONS: PRINT_PAGE_OPTIONS (print.js) == $PAGE_OPTION_SCHEMA (php) ----
   Same rule-#35 lockstep as the block types (#1767 G/V/AB/AM/F): a page option
   the editor offers but the server doesn't allow-list is silently dropped on
   save; one the server accepts but the editor never renders is unreachable. */
$jsPageRegion = ptRegion($jsSrc, '/PRINT_PAGE_OPTIONS\s*=\s*\{(.*?)\n\};/s');
$jsPageKeys   = [];
if (preg_match_all('/^\s*(\w+)\s*:\s*\{/m', $jsPageRegion, $jm)) {
    $jsPageKeys = $jm[1];
}
$phpPageRegion = ptRegion($phpSrc, '/\$PAGE_OPTION_SCHEMA\s*=\s*\[(.*?)\n\];/s');
$phpPageKeys   = [];
/* Anchor to LINE-START keys (`/m` + `^\s*`): each top-level option is one line,
   but its value nests a `'choices' => [...]` array — an un-anchored `'key' => [`
   would wrongly capture that nested `choices` too. (This bug was caught on first
   run precisely because the guard went red, not silently green — rule #34.) */
if (preg_match_all("/^\s*'(\w+)'\s*=>\s*\[/m", $phpPageRegion, $ppm)) {
    $phpPageKeys = $ppm[1];
}
sort($jsPageKeys); sort($phpPageKeys);

const PT_MIN_PAGE_OPTS = 4;   // parser-sanity floor (there are 6)
if (count($jsPageKeys) < PT_MIN_PAGE_OPTS) {
    $fail[] = sprintf('PRINT_PAGE_OPTIONS parsed only %d options (< %d) — parser anchor moved or file emptied.',
        count($jsPageKeys), PT_MIN_PAGE_OPTS);
}
if (count($phpPageKeys) < PT_MIN_PAGE_OPTS) {
    $fail[] = sprintf('$PAGE_OPTION_SCHEMA parsed only %d options (< %d) — parser anchor moved or file emptied.',
        count($phpPageKeys), PT_MIN_PAGE_OPTS);
}
foreach (array_diff($jsPageKeys, $phpPageKeys) as $k) { $fail[] = "Page option '$k' is in PRINT_PAGE_OPTIONS (print.js) but missing from \$PAGE_OPTION_SCHEMA (print-templates.php) — server would DROP it on save."; }
foreach (array_diff($phpPageKeys, $jsPageKeys) as $k) { $fail[] = "Page option '$k' is in \$PAGE_OPTION_SCHEMA (print-templates.php) but missing from PRINT_PAGE_OPTIONS (print.js) — editor can't offer it."; }

/* ---- 5. CONDITIONAL VISIBILITY vocab: PRINT_SHOWIF_CONDITIONS (js) == $SHOWIF_CONDITIONS (php) ----
   The universal `showIf` block property (#1767 Y): a condition the editor offers
   but the server doesn't allow-list is dropped on save (silent). */
$jsShowIfRegion = ptRegion($jsSrc, '/PRINT_SHOWIF_CONDITIONS\s*=\s*\{(.*?)\n\};/s');
$jsShowIf = [];
if (preg_match_all('/^\s*(\w+)\s*:\s*\{/m', $jsShowIfRegion, $sjm)) { $jsShowIf = $sjm[1]; }
/* PHP: $SHOWIF_CONDITIONS = ['always', 'hasChords', …]; — a flat string list. */
$phpShowIfRegion = ptRegion($phpSrc, '/\$SHOWIF_CONDITIONS\s*=\s*\[(.*?)\];/s');
$phpShowIf = [];
if (preg_match_all("/'([a-zA-Z]\w*)'/", $phpShowIfRegion, $spm)) { $phpShowIf = $spm[1]; }
sort($jsShowIf); sort($phpShowIf);

const PT_MIN_SHOWIF = 4;   // parser-sanity floor (there are 7)
if (count($jsShowIf) < PT_MIN_SHOWIF) {
    $fail[] = sprintf('PRINT_SHOWIF_CONDITIONS parsed only %d conditions (< %d) — parser anchor moved.', count($jsShowIf), PT_MIN_SHOWIF);
}
if (count($phpShowIf) < PT_MIN_SHOWIF) {
    $fail[] = sprintf('$SHOWIF_CONDITIONS parsed only %d conditions (< %d) — parser anchor moved.', count($phpShowIf), PT_MIN_SHOWIF);
}
foreach (array_diff($jsShowIf, $phpShowIf) as $c) { $fail[] = "showIf condition '$c' is in PRINT_SHOWIF_CONDITIONS (print.js) but missing from \$SHOWIF_CONDITIONS (print-templates.php) — server would DROP it on save."; }
foreach (array_diff($phpShowIf, $jsShowIf) as $c) { $fail[] = "showIf condition '$c' is in \$SHOWIF_CONDITIONS (print-templates.php) but missing from PRINT_SHOWIF_CONDITIONS (print.js) — editor can't offer it / renderer can't evaluate it."; }

if ($fail) {
    fwrite(STDERR, "FAIL: print block-type registry drift (#1767, rule #35)\n");
    foreach ($fail as $f) { fwrite(STDERR, "  - $f\n"); }
    exit(1);
}

printf("OK: print registry agrees — %d block types (option keys aligned across print.js + renderBlock() + \$BLOCK_SCHEMA) + %d page options aligned across PRINT_PAGE_OPTIONS + \$PAGE_OPTION_SCHEMA.\n",
    count($jsKeys), count($jsPageKeys));
exit(0);
