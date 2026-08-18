<?php

declare(strict_types=1);

/**
 * iHymns — editor Rights-facts server-plumbing + panel-removal guard
 * (#1769 P4 Commit B, updated #1862 sub-build F)
 * ====================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Commit B added the per-song rights facts to the v2 editor: two columns in
 * the api2 allow-list, an existence probe, a validated write branch, a
 * restore-loop gate, a `load_song` prefill hint, an editor2 vocab global, and
 * a client panel that let a curator PICK a per-song licence key. #1862's
 * owner-refinement comment then replaced that PICKER with a DERIVED
 * read-only coverability line — but explicitly kept every server-side piece
 * (dormant facts, kept for the future P6 enforcement pass and for a stale
 * cached client's wire contract, rule #33). This file was originally the
 * wiring guard for the picker; it now asserts BOTH directions of that split:
 * sections A-F below still pin the SERVER half exactly as Commit B built it
 * (a regression here would silently strip dormant plumbing #1862 explicitly
 * chose to keep), and section G asserts the CLIENT picker is actually GONE —
 * `v2/rights-panel.js` does not exist on disk and metadata-tab.js does not
 * import it (the #1862 spec §8 test-plan item 5 assertion, the opposite
 * direction from Commit B's original section H/I, which asserted the panel
 * DID exist and DID agree on field keys with the server — now meaningless
 * once there is no panel to agree with).
 *
 * WHY MUTATION-PROVEN (rule #34): the PHP comment-stripper is exercised in both
 * directions before any real assertion trusts it, so a check can never be
 * satisfied by a symbol that lives only in an annotation (this file's own
 * comments name every symbol it asserts on).
 *
 * DB-free: pure source scan. Runs in the CI lint step.
 *
 *   php tests/php/test-rights-panel-fields.php
 *
 * @see appWeb/public_html/manage/editor/api2.php               the write path + probe + restore gate (KEPT, #1862)
 * @see appWeb/public_html/manage/editor/editor2.php            window._iHymnsLicenceTypes emit (KEPT, #1862)
 * @see appWeb/public_html/manage/editor/v2/metadata-tab.js     the derived coverage line that replaced the picker
 * @see tests/php/test-gating-pipeline-structure.php            §(g) the fact-column containment lock
 * @see .claude/gating-p4-design.md §"Commit B"
 * @see #1862, epic #1863
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

/** Strip PHP comments/doc-blocks via the tokenizer + drop inline-HTML so a CODE
 *  assertion can never be satisfied by a symbol named in a comment OR in the
 *  page's raw HTML/JS template body. */
function rpfPhpCode(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT || $tok[0] === T_INLINE_HTML) {
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

$failures = [];
$mut = [];

/* ---- mutation self-test for rpfPhpCode (rule #34) ---- */
$mf = "<?php\n// NeedleInComment\n\$x = 'NeedleInCode';\n/* NeedleInBlock */\n?>\n<div>NeedleInHtml</div>\n";
$ms = rpfPhpCode($mf);
if (strpos($ms, 'NeedleInCode') === false) { $mut[] = 'rpfPhpCode FAILS-HIGH: dropped a code string'; }
foreach (['NeedleInComment', 'NeedleInBlock', 'NeedleInHtml'] as $n) {
    if (strpos($ms, $n) !== false) { $mut[] = "rpfPhpCode FAILS-LOW: kept '{$n}'"; }
}

/* ---- load files ---- */
$files = [
    'api2'      => $pub . '/manage/editor/api2.php',
    'editor2'   => $pub . '/manage/editor/editor2.php',
    'metaTab'   => $pub . '/manage/editor/v2/metadata-tab.js',
];
foreach ($files as $k => $p) {
    if (!is_readable($p)) { fwrite(STDERR, "FATAL: cannot read {$k} at {$p}\n"); exit(1); }
}
$api2Code   = rpfPhpCode((string)file_get_contents($files['api2']));
$editor2    = (string)file_get_contents($files['editor2']);      // template — check emit line raw
$editor2Php = rpfPhpCode($editor2);
$metaTab    = (string)file_get_contents($files['metaTab']);
$panelPath  = $pub . '/manage/editor/v2/rights-panel.js';

/* ==== A. api2 ED2_META_FIELDS maps both rights keys → columns ==== */
foreach ([
    "'lyricsRightsLicenceKey'" => 'LyricsRightsLicenceKey',
    "'musicRightsLicenceKey'"  => 'MusicRightsLicenceKey',
] as $key => $col) {
    if (!preg_match('/' . preg_quote($key, '/') . '\s*=>\s*\[\s*\'' . $col . '\'/', $api2Code)) {
        $failures[] = "api2.php ED2_META_FIELDS does not map {$key} => ['{$col}', …]";
    }
}

/* ==== B. ed2_rightsColsPresent() exists + probes both columns ==== */
if (strpos($api2Code, 'function ed2_rightsColsPresent(') === false) {
    $failures[] = 'api2.php has no ed2_rightsColsPresent() probe';
}

/* ==== C. metadata_field_update rights branch: gate + entitlement + validate + audit ==== */
/* All within one window after the branch condition. */
$branchPos = strpos($api2Code, "\$column === 'LyricsRightsLicenceKey' || \$column === 'MusicRightsLicenceKey'");
if ($branchPos === false) {
    $failures[] = 'api2.php metadata_field_update has no dedicated rights-fact branch';
} else {
    $win = substr($api2Code, $branchPos, 2000);
    $need = [
        'ed2_rightsColsPresent('              => 'existence gate (409 on un-migrated)',
        "ed2_requireEntitlement('edit_songs')" => 'edit_songs entitlement (D3)',
        'licenceTypeKeys('                     => 'validates the key against the live registry',
        "logActivity('admin.song.rights_set'"  => 'admin.song.rights_set audit',
    ];
    foreach ($need as $needle => $why) {
        if (strpos($win, $needle) === false) {
            $failures[] = "api2.php rights branch is missing {$needle} ({$why})";
        }
    }
    if (strpos($win, '], 409)') === false) { $failures[] = 'api2.php rights branch does not 409 on an un-migrated install'; }
    if (strpos($win, '], 422)') === false) { $failures[] = 'api2.php rights branch does not 422 on an unknown licence key'; }
}

/* ==== D. restore loop gates + nullifies the rights columns ==== */
if (strpos($api2Code, '$ed2RightsPresence   = ed2_rightsColsPresent($db)') === false
    && strpos($api2Code, '$ed2RightsPresence = ed2_rightsColsPresent($db)') === false) {
    $failures[] = 'api2.php restore loop (ed2_applySongSnapshot) does not compute $ed2RightsPresence';
}
if (!preg_match("/in_array\\(\\\$column,\\s*\\[[^\\]]*'LyricsRightsLicenceKey'[^\\]]*'MusicRightsLicenceKey'[^\\]]*\\]/", $api2Code)) {
    $failures[] = 'api2.php restore loop does not add the two rights columns to the empty→NULL nullable list';
}

/* ==== E. load_song emits songbookRightsDefaults ==== */
if (strpos($api2Code, "'songbookRightsDefaults'") === false
    || strpos($api2Code, 'ed2_songbookRightsDefaults(') === false) {
    $failures[] = 'api2.php load_song does not emit songbookRightsDefaults via ed2_songbookRightsDefaults()';
}

/* ==== F. editor2 requires the registry + emits the vocab global ==== */
if (strpos($editor2Php, "licence_registry.php") === false
    || strpos($editor2Php, 'licenceTypesForPicker(') === false) {
    $failures[] = 'editor2.php does not build the licence vocab via licenceTypesForPicker()';
}
if (strpos($editor2, 'window._iHymnsLicenceTypes') === false) {
    $failures[] = 'editor2.php does not emit window._iHymnsLicenceTypes';
}

/* ==== G. the CLIENT picker is actually GONE (#1862 sub-build F / spec §8 item 5) ====
 * The owner's refinement comment replaced the per-part picker with a derived
 * read-only coverability line — this is the opposite-direction proof from the
 * ORIGINAL sections G/H/I this guard carried (which asserted the panel DID
 * exist and DID share field keys with the server): a regression that quietly
 * re-adds the picker, or a stale import metadata-tab.js forgot to drop, both
 * fail here. */
if (is_file($panelPath)) {
    $failures[] = 'v2/rights-panel.js still exists on disk — the owner-refinement comment (#1862) replaced the picker with a derived coverage line; delete the file';
}
/* Comment-stripped (the SAME JS block/line stripper test-tune-typeahead-ui.js
   uses) — metadata-tab.js's own doc-comments legitimately EXPLAIN this
   removal by name ("replaces the deleted rights-panel.js picker"), and a
   mention in prose must not satisfy a check for real code (rule #34's own
   "kept a comment" failure mode, restated for JS instead of PHP). */
$metaTabStripped = preg_replace('#/\*[\s\S]*?\*/#', '', $metaTab) ?? $metaTab;
$metaTabStripped = preg_replace('#(^|[^:])//.*$#m', '$1', $metaTabStripped) ?? $metaTabStripped;
if (strpos($metaTabStripped, 'rights-panel.js') !== false || strpos($metaTabStripped, 'mountRightsPanel(') !== false) {
    $failures[] = 'metadata-tab.js still references rights-panel.js / mountRightsPanel() in real code — the picker mount must be fully removed (#1862)';
}
/* The derived line that replaced the picker must actually be present — a
   silent DOUBLE removal (picker AND its replacement both gone) would still
   pass every assertion above. */
if (strpos($metaTab, 'Copyrighted') === false && strpos($metaTab, 'Public domain (both parts)') === false) {
    $failures[] = 'metadata-tab.js has no sign of the derived rights-coverage line that was supposed to replace the picker (#1862)';
}

/* ---- report ---- */
if ($failures || $mut) {
    if ($mut) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not behave as expected:\n");
        foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($failures) {
        fwrite(STDERR, "FAIL: editor Rights-facts server-plumbing + panel-removal guard:\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}
echo "PASS: editor Rights-facts plumbing — api2 still allow-lists + probes + validates + audits the two "
   . "rights facts, the restore loop still gates + nullifies them, load_song still emits the songbook-default "
   . "hint, editor2 still emits the licence vocab global (all KEPT dormant per #1862) — AND the client picker "
   . "(rights-panel.js) is confirmed gone, replaced by metadata-tab.js's derived coverage line; mutation "
   . "self-tests went red as expected.\n";
exit(0);
