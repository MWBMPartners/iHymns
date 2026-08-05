<?php

declare(strict_types=1);

/**
 * iHymns — editor Rights-panel wiring guard (#1769 P4 Commit B)
 * ============================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Commit B adds the per-song rights facts to the v2 editor: two columns in the
 * api2 allow-list, an existence probe, a validated write branch, a restore-loop
 * gate, a `load_song` prefill hint, an editor2 vocab global, and a client panel.
 * Several of these are cross-file agreements with nothing but this guard holding
 * them together (rule #35): the field KEYS the panel POSTs must be the KEYS the
 * server allow-lists; the vocab global the page emits must be the one the panel
 * reads. This pins the server write path (the enforcement-adjacent half,
 * comment-stripped so a mention can't satisfy a check) and the boundary keys.
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
 * @see appWeb/public_html/manage/editor/api2.php               the write path + probe + restore gate
 * @see appWeb/public_html/manage/editor/editor2.php            window._iHymnsLicenceTypes emit
 * @see appWeb/public_html/manage/editor/v2/rights-panel.js     the client panel
 * @see appWeb/public_html/manage/editor/v2/metadata-tab.js     mounts the panel
 * @see tests/php/test-gating-pipeline-structure.php            §(g) the fact-column containment lock
 * @see .claude/gating-p4-design.md §"Commit B"
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
    'panel'     => $pub . '/manage/editor/v2/rights-panel.js',
    'metaTab'   => $pub . '/manage/editor/v2/metadata-tab.js',
];
foreach ($files as $k => $p) {
    if (!is_readable($p)) { fwrite(STDERR, "FATAL: cannot read {$k} at {$p}\n"); exit(1); }
}
$api2Code   = rpfPhpCode((string)file_get_contents($files['api2']));
$editor2    = (string)file_get_contents($files['editor2']);      // template — check emit line raw
$editor2Php = rpfPhpCode($editor2);
$panel      = (string)file_get_contents($files['panel']);
$metaTab    = (string)file_get_contents($files['metaTab']);

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

/* ==== G. rights-panel.js reads the vocab + branches on err.status ==== */
foreach ([
    'window._iHymnsLicenceTypes' => 'reads the licence vocab global',
    'updateMetadata('            => 'writes via the granular metadata endpoint',
    'songbookRightsDefaults'     => 'reads the songbook-default hint slice',
    '.status === 409'            => 'branches on 409 (un-migrated) by status, not prose',
    '.status === 422'            => 'branches on 422 (bad key) by status, not prose',
    "'lyricsRightsLicenceKey'"   => 'POSTs the lyrics field key the server allow-lists',
    "'musicRightsLicenceKey'"    => 'POSTs the music field key the server allow-lists',
] as $needle => $why) {
    if (strpos($panel, $needle) === false) {
        $failures[] = "rights-panel.js is missing {$needle} ({$why})";
    }
}

/* ==== H. metadata-tab.js mounts the rights panel ==== */
if (strpos($metaTab, 'rights-panel.js') === false || strpos($metaTab, 'mountRightsPanel(') === false) {
    $failures[] = 'metadata-tab.js does not import + mount rights-panel.js';
}

/* ==== I. cross-boundary key agreement (rule #35): the two field keys exist on
 * BOTH sides of the wire (server allow-list + client POST). ==== */
foreach (["'lyricsRightsLicenceKey'", "'musicRightsLicenceKey'"] as $key) {
    $onServer = strpos($api2Code, $key) !== false;
    $onClient = strpos($panel, $key) !== false;
    if (!$onServer || !$onClient) {
        $failures[] = "field key {$key} is not present on both server (api2) and client (rights-panel) — the two would drift (rule #35)";
    }
}

/* ---- report ---- */
if ($failures || $mut) {
    if ($mut) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not behave as expected:\n");
        foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($failures) {
        fwrite(STDERR, "FAIL: editor Rights-panel wiring guard (#1769 P4 Commit B):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}
echo "PASS: editor Rights-panel wiring — api2 allow-lists + probes + validates + audits the two rights facts, "
   . "the restore loop gates + nullifies them, load_song emits the songbook-default hint, editor2 emits the "
   . "licence vocab global, rights-panel.js reads it and branches on err.status, metadata-tab mounts it, and "
   . "the field keys agree across the wire; mutation self-tests went red as expected.\n";
exit(0);
