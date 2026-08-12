<?php

declare(strict_types=1);

/**
 * iHymns — Print template server-side schema (#1767 remainder P3)
 *
 * ELI5: this file is the ONE rulebook for "what is allowed to be in a print
 * template" on the server side — which block types exist, which options each
 * one may carry, which `showIf` conditions are legal, and which page-wide
 * options (font size, page size, ink-saver, …) are legal. Two different
 * server surfaces need EXACTLY the same rulebook — the admin editor that
 * saves a template, and the new PDF endpoint that re-checks a template's
 * options before rendering — so this file exists precisely so those two
 * surfaces cannot quietly drift into two different rulebooks.
 *
 * DETAIL
 * ------
 * Extracted verbatim (byte-identical logic, zero behaviour change) out of
 * `manage/print-templates.php` in the #1767 remainder's P3 commit
 * (`.claude/print-templates-1767-remainder-plan.md` §3.1): "To avoid a third
 * copy of the schema (rule #35), P3 extracts `$BLOCK_SCHEMA`,
 * `$SHOWIF_CONDITIONS`, `$PAGE_OPTION_SCHEMA`, `ptSanitiseBlocks()` and
 * `ptSanitisePageOptions()` from `manage/print-templates.php` into a new
 * shared `includes/print_template_schema.php`; the admin page and the
 * endpoint both require it."
 *
 * WHY TOP-LEVEL `require_once`, NOT A CLASS/NAMESPACE: this file is meant to
 * be `require_once`d from the CALLER'S own top-level scope (never from
 * inside a function), exactly like `manage/print-templates.php` used to
 * declare these symbols inline. A PHP `require` executed at the top level
 * runs as if the file's contents were pasted in place — so the three
 * variables below (`$BLOCK_SCHEMA`, `$SHOWIF_CONDITIONS`,
 * `$PAGE_OPTION_SCHEMA`) land directly in the includer's own global scope,
 * and the two functions become ordinary globally-available PHP functions —
 * `manage/print-templates.php`'s existing `$BLOCK_SCHEMA` / `ptSanitiseBlocks(...)`
 * call sites needed NO changes at all, only the twelve lines of definitions
 * they used to carry were replaced by one `require_once`.
 *
 * CONSUMERS (rule "modularity" — reuse, never fork a second allow-list):
 *   1. `manage/print-templates.php` — the admin editor's save/clone paths
 *      (unchanged behaviour after this extraction).
 *   2. `manage/print-pdf.php` — the new PDF endpoint (#1767 remainder P3)
 *      re-validates a POSTed `pageOptions` object against the SAME
 *      `$PAGE_OPTION_SCHEMA` + `ptSanitisePageOptions()` before it ever
 *      reaches mPDF, so a crafted POST cannot smuggle an unrecognised page
 *      option into the PDF pipeline any more than it could into a saved
 *      template row.
 *
 * LOCKSTEP GUARD: `tests/php/test-print-block-registry.php` parses this
 * file's `$BLOCK_SCHEMA` / `$SHOWIF_CONDITIONS` / `$PAGE_OPTION_SCHEMA`
 * regions (re-anchored here from `manage/print-templates.php` in the same
 * P3 commit that created this file) and asserts they still agree with
 * `PRINT_BLOCK_TYPES` / `PRINT_SHOWIF_CONDITIONS` / `PRINT_PAGE_OPTIONS` in
 * `js/modules/print.js` (rule #35 — a mechanism, not a comment).
 *
 * @see appWeb/public_html/manage/print-templates.php  the admin editor consumer
 * @see appWeb/public_html/manage/print-pdf.php         the PDF endpoint consumer
 * @see appWeb/public_html/js/modules/print.js          the CLIENT mirror this stays in lockstep with
 * @see tests/php/test-print-block-registry.php         the mutation-proven agreement guard
 * @see .claude/print-templates-1767-remainder-plan.md §3.1
 * @link https://www.php.net/manual/en/function.require-once.php  top-level require = "pasted in place"
 */

/* #1830 — the ONE kind registry (org_logo_helpers.php is side-effect-free
   to require, per its own file doc-block's §4.1 contract) so the `logo`
   block's `kind` option validates against the SAME map the admin card and
   the serving endpoint use, rather than a second typed kind list here. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'org_logo_helpers.php';

/* The CANONICAL block-type allow-list — mirrors PRINT_BLOCK_TYPES in
   js/modules/print.js. A POSTed block whose `type` isn't here is
   dropped, and only the option keys declared here are kept (so a
   crafted POST cannot persist arbitrary JSON). The value is the
   per-type option schema: key => coercion kind. Keeping this beside
   the JS registry is deliberate — the JS drives the editor UI, this
   drives the server-side gate; both enumerate the same 10 types. */
$BLOCK_SCHEMA = [
    'title'       => [],
    'subtitle'    => ['showBook' => 'bool', 'showNumber' => 'bool', 'bookAbbr' => 'bool'],   // #1767 B
    'credits'     => [],
    'lyrics'      => ['showLabels' => 'bool', 'showChords' => 'bool', 'columns' => 'cols', 'align' => 'align', 'size' => 'size'],  // #1767 A
    'copyright'   => [],
    'identifiers' => ['ccli' => 'bool', 'iswc' => 'bool'],
    'scripture'   => [],                          // #1767 N
    'tune'        => ['showMetre' => 'bool'],     // #1767 O
    'themes'      => [],                          // #1767 P
    'text'        => ['content' => 'str'],
    'permalink'   => [],
    'qr'          => ['size' => 'size'],          // #1767 R
    'logo'        => ['kind' => 'logokind', 'size' => 'size', 'align' => 'align'],  // #1830
    'spacer'      => ['size' => 'size'],
    'pagebreak'   => [],
];

/* The CANONICAL conditional-visibility vocabulary (#1767 Y) — a UNIVERSAL block
   property `showIf` any block may carry. Mirrors PRINT_SHOWIF_CONDITIONS in
   js/modules/print.js, held in lockstep by the registry guard (rule #35). A
   posted showIf not in this list (or 'always', the default) is dropped. */
$SHOWIF_CONDITIONS = ['always', 'hasChords', 'hasCopyright', 'hasCcli', 'hasScripture', 'hasThemes', 'hasTune'];

/* The CANONICAL page-option allow-list (#1767 G/V/AB/AM/F) — mirrors
   PRINT_PAGE_OPTIONS in js/modules/print.js, held in lockstep by
   tests/php/test-print-block-registry.php (rule #35). Each entry's `kind` drives
   coercion in ptSanitisePageOptions(); an option not listed here is DROPPED. */
$PAGE_OPTION_SCHEMA = [
    'fontPt'      => ['kind' => 'int',  'min' => 6, 'max' => 72],
    'pageSize'    => ['kind' => 'enum', 'choices' => ['A4', 'Letter', 'Legal']],
    'lineHeight'  => ['kind' => 'enum', 'choices' => ['tight', 'normal', 'relaxed']],
    'contrast'    => ['kind' => 'enum', 'choices' => ['normal', 'high']],
    'accentColor' => ['kind' => 'color'],
    'inkSaver'    => ['kind' => 'bool'],

    /* #1767 remainder P4 (§4.3) — PDF-only options, mirroring
       PRINT_PAGE_OPTIONS's `serverOnly: true` entries in print.js. The
       'kind' drives the SAME ptSanitisePageOptions() coercion as every
       other option below — 'server_only' carries no coercion behaviour of
       its own (the browser/server DISTINCTION lives entirely in how
       includes/pdf_renderer.php reads $pageOptions vs. how printCss()
       ignores unknown keys); it exists purely so
       tests/php/test-print-block-registry.php can assert the flag agrees
       with print.js — an option marked serverOnly on one side and not the
       other would mislead either the editor's "PDF only" grouping or a
       future browser-CSS author into thinking it has no effect when it
       does (or vice versa). */
    'pageNumbers'   => ['kind' => 'bool', 'server_only' => true],
    'runningHeader' => ['kind' => 'enum', 'choices' => ['none', 'title', 'titleBook'], 'server_only' => true],
    'onePerPage'    => ['kind' => 'bool', 'server_only' => true],
];

/**
 * Sanitise a decoded blocks array against $BLOCK_SCHEMA.
 *
 * Drops unknown types and unknown option keys; coerces every kept
 * value to its declared kind. Returns a clean array safe to persist.
 *
 * @param array $raw               The json_decode'd POST blocks (assoc).
 * @param array $schema            $BLOCK_SCHEMA.
 * @param array $showIfConditions  $SHOWIF_CONDITIONS (universal visibility vocab).
 * @return array                   Sanitised ordered block list.
 */
function ptSanitiseBlocks(array $raw, array $schema, array $showIfConditions = []): array
{
    $clean = [];
    foreach ($raw as $block) {
        if (!is_array($block)) { continue; }                 // not an object — drop
        $type = (string)($block['type'] ?? '');
        if (!isset($schema[$type])) { continue; }            // unknown type — drop
        $row = ['type' => $type];
        /* #1767 Y — a UNIVERSAL showIf on ANY block. Kept only when it names a
           known condition and isn't the 'always' default (which we omit to keep
           the stored JSON minimal — absent showIf == always visible). */
        if (isset($block['showIf'])
            && in_array($block['showIf'], $showIfConditions, true)
            && $block['showIf'] !== 'always') {
            $row['showIf'] = (string)$block['showIf'];
        }
        foreach ($schema[$type] as $key => $kind) {
            if (!array_key_exists($key, $block)) { continue; } // option not posted — use renderer default
            $v = $block[$key];
            switch ($kind) {
                case 'bool':
                    $row[$key] = (bool)$v;
                    break;
                case 'cols':
                    $row[$key] = ((int)$v === 2) ? 2 : 1;       // lyrics columns: only 1 or 2
                    break;
                case 'size':
                    $row[$key] = in_array($v, ['sm', 'md', 'lg'], true) ? $v : 'md';
                    break;
                case 'align':                                    // #1767 A
                    $row[$key] = in_array($v, ['left', 'center', 'right'], true) ? $v : 'left';
                    break;
                case 'logokind':                                  // #1830
                    /* 'auto' (the default) resolves via the ladder at render
                       time (§6.3); an explicit kind must be in the ONE
                       registry or it coerces to 'auto' — never persisted
                       unvalidated, so a crafted POST can't smuggle a kind
                       past ihymnsOrgLogoKindKeys(). */
                    $row[$key] = ($v === 'auto' || in_array($v, ihymnsOrgLogoKindKeys(), true)) ? $v : 'auto';
                    break;
                case 'str':
                default:
                    $row[$key] = mb_substr((string)$v, 0, 2000); // custom text — cap length
                    break;
            }
        }
        $clean[] = $row;
    }
    return $clean;
}

/**
 * Sanitise decoded page options against $PAGE_OPTION_SCHEMA (#1767 G/V/AB/AM/F).
 *
 * Drops unknown keys; coerces each kept value to its declared kind (int clamp,
 * enum membership, #rgb/#rrggbb colour, bool). Returns null when nothing valid
 * was supplied. We persist the RE-ENCODED clean options, never the raw POST.
 *
 * @param mixed $raw      The json_decode'd POST page_options (assoc), or non-array.
 * @param array $schema   $PAGE_OPTION_SCHEMA.
 * @return array|null     Sanitised page options, or null when empty.
 */
function ptSanitisePageOptions($raw, array $schema): ?array
{
    if (!is_array($raw)) { return null; }
    $out = [];
    foreach ($schema as $key => $def) {
        if (!array_key_exists($key, $raw)) { continue; }   // not posted — renderer default
        $v = $raw[$key];
        switch ($def['kind']) {
            case 'int':
                $out[$key] = max((int)$def['min'], min((int)$def['max'], (int)$v));
                break;
            case 'enum':
                if (in_array($v, $def['choices'], true)) { $out[$key] = $v; }  // else drop
                break;
            case 'color':
                $s = (string)$v;
                if ($s !== '' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $s)) { $out[$key] = $s; }
                break;
            case 'bool':
                $out[$key] = (bool)$v;
                break;
        }
    }
    return $out ?: null;
}
