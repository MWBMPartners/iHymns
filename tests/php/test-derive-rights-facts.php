<?php

declare(strict_types=1);

/**
 * iHymns — DM-2 rights-fact derivation guard (#1769 P4 Commit D)
 * =============================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * DM-2 copies each song's existing require_licence restriction into a per-song
 * rights FACT column, but only where the fact is still NULL. The whole thing
 * hinges on ONE mapping fold (rightsFactColumnForLicence) shared by the
 * migration AND its completeness probe, and on the `IS NULL` clause that makes
 * the copy non-destructive. This guard exercises the fold as a real truth table,
 * and pins (mutation-proven) that the migration keeps its `IS NULL` lock, uses
 * the shared fold rather than a re-typed map, adds no DDL, and that the registry
 * probe is a real data-derived closure (not a static `=> true`).
 *
 * DB-free: the fold is pure (require of licence_registry.php defines functions
 * only), the rest is a comment-stripped source scan.
 *
 *   php tests/php/test-derive-rights-facts.php
 *
 * @see appWeb/public_html/includes/licence_registry.php       rightsFactColumnForLicence() — the ONE fold
 * @see appWeb/.sql/migrate-derive-rights-facts.php            the forward pass
 * @see appWeb/.sql/revert-derive-rights-facts.php             the recovery tool
 * @see appWeb/public_html/manage/includes/migration-registry.php  'derive-rights-facts' entry + probe
 * @see .claude/gating-p4-design.md §"Commit D"
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

/** PHP-code-only projection (drop comments/doc-blocks + inline HTML). */
function drfPhpCode(string $src): string
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

/* ---- mutation self-test for drfPhpCode ---- */
$mf = "<?php\n// NeedleComment IS NULL\n\$x='NeedleCode';\n";
$ms = drfPhpCode($mf);
if (strpos($ms, 'NeedleCode') === false) { $mut[] = 'drfPhpCode FAILS-HIGH: dropped code'; }
if (strpos($ms, 'NeedleComment') !== false) { $mut[] = 'drfPhpCode FAILS-LOW: kept a comment (an IS NULL in a comment must not satisfy the real check)'; }

/* =========================================================================
 * (1) BEHAVIOURAL — rightsFactColumnForLicence() truth table (pure).
 * ========================================================================= */
require_once $pub . '/includes/licence_registry.php';
if (!function_exists('rightsFactColumnForLicence')) {
    fwrite(STDERR, "FATAL: rightsFactColumnForLicence() is not defined\n");
    exit(1);
}
$truth = [
    'lyrics_display → Lyrics'            => [['lyrics_display' => new stdClass()], 'LyricsRightsLicenceKey'],
    'lyrics_print → Lyrics'              => [['lyrics_print' => new stdClass()], 'LyricsRightsLicenceKey'],
    'music_reproduction → Music'        => [['music_reproduction' => new stdClass()], 'MusicRightsLicenceKey'],
    'lyrics+music → Lyrics (precedence)'=> [['lyrics_display' => new stdClass(), 'music_reproduction' => new stdClass()], 'LyricsRightsLicenceKey'],
    'audio_playback → null'             => [['audio_playback' => new stdClass()], null],
    'empty covers → null'               => [[], null],
    'null covers → null'                => [null, null],
];
foreach ($truth as $label => [$covers, $want]) {
    $got = rightsFactColumnForLicence($covers);
    if ($got !== $want) {
        $failures[] = "fold: {$label} — got " . var_export($got, true) . ", expected " . var_export($want, true);
    }
}

/* =========================================================================
 * (2) STRUCTURAL — the migration + revert + probe.
 * ========================================================================= */
$migFile    = $repoRoot . '/appWeb/.sql/migrate-derive-rights-facts.php';
$revFile    = $repoRoot . '/appWeb/.sql/revert-derive-rights-facts.php';
$regFile    = $pub . '/manage/includes/migration-registry.php';
foreach (['migrate' => $migFile, 'revert' => $revFile, 'registry' => $regFile] as $k => $p) {
    if (!is_readable($p)) { fwrite(STDERR, "FATAL: cannot read {$k} at {$p}\n"); exit(1); }
}
$mig = drfPhpCode((string)file_get_contents($migFile));
$rev = drfPhpCode((string)file_get_contents($revFile));
$reg = drfPhpCode((string)file_get_contents($regFile));

/* Single-fold: migration + revert both call the shared fold; neither re-types a
   coverage→column map (rule #22). */
foreach (['migrate' => $mig, 'revert' => $rev] as $k => $code) {
    if (strpos($code, 'rightsFactColumnForLicence(') === false) {
        $failures[] = "{$k} does not call the shared rightsFactColumnForLicence() fold (a re-typed map is the regression, rule #22)";
    }
    /* A re-forked mapping would name the coverage-kind literals itself. */
    if (strpos($code, "'lyrics_display'") !== false || strpos($code, "'music_reproduction'") !== false) {
        $failures[] = "{$k} names raw coverage-kind literals — it should map via rightsFactColumnForLicence(), not a local copy (rule #22)";
    }
}

/* THE LOAD-BEARING LOCK — the derive UPDATE is fill-NULL-only. */
if (!preg_match('/UPDATE\s+tblSongs\s+s\s+JOIN\s+tblContentRestrictions.*?SET\s+s\.`?\{?\$?col\}?`?\s*=\s*\?.*?WHERE\s+s\.`?\{?\$?col\}?`?\s+IS\s+NULL/s', $mig)) {
    $failures[] = 'the DM-2 derive UPDATE is missing its fill-NULL-only `WHERE s.{col} IS NULL` lock — it would overwrite a curator-set fact (#1769 P4)';
}

/* No DDL (D6 — data-only migration, no schema.sql edit). */
if (preg_match('/\b(CREATE\s+TABLE|ALTER\s+TABLE)\b/i', $mig)) {
    $failures[] = 'migrate-derive-rights-facts.php contains DDL — it must be data-only (D6, no schema.sql change)';
}

/* Revert clears to NULL via the same join shape. */
if (!preg_match('/UPDATE\s+tblSongs.*?SET\s+s\.`?\{?\$?col\}?`?\s*=\s*NULL/s', $rev)) {
    $failures[] = 'revert-derive-rights-facts.php does not clear the fact column back to NULL via the join';
}

/* =========================================================================
 * (3) REGISTRY — the card exists + the probe is a real closure, not `=> true`.
 * ========================================================================= */
$entryPos = strpos($reg, "'derive-rights-facts' =>");
if ($entryPos === false) {
    $failures[] = "migration-registry.php has no 'derive-rights-facts' entry";
} else {
    /* Window must clear the long card-body string + the probe comment (stripped
       to newlines) and reach the probe code — hence generous. */
    $win = substr($reg, $entryPos, 5000);
    if (strpos($win, "'script' => 'migrate-derive-rights-facts.php'") === false) {
        $failures[] = "the 'derive-rights-facts' entry does not point at migrate-derive-rights-facts.php";
    }
    /* Real data-derived probe: names the shared fold + the restriction table +
       an IS NULL check, and is NOT a static `=> true`. */
    if (preg_match('/\'probe\'\s*=>\s*static\s+fn[^\n]*=>\s*true/', $win)) {
        $failures[] = "the 'derive-rights-facts' probe is a static `=> true` — it must detect real completion (rule #19)";
    }
    if (strpos($win, 'rightsFactColumnForLicence(') === false
        || strpos($win, 'require_licence') === false
        || strpos($win, 'IS NULL') === false) {
        $failures[] = "the 'derive-rights-facts' probe is not the data-derived drift detector it must be (shared fold + require_licence + IS NULL)";
    }
}

/* ---- report ---- */
if ($failures || $mut) {
    if ($mut) {
        fwrite(STDERR, "FAIL: mutation self-test(s):\n");
        foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($failures) {
        fwrite(STDERR, "FAIL: DM-2 rights-fact derivation guard (#1769 P4 Commit D):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}
echo "PASS: DM-2 derivation — rightsFactColumnForLicence() maps coverage→column correctly (lyrics precedence; "
   . "audio/plan → null); the migration + revert use the ONE fold with no re-typed map; the derive UPDATE keeps "
   . "its IS NULL fill-NULL-only lock and adds no DDL; the registry probe is a real data-derived drift detector. "
   . "Mutation self-tests behaved as expected.\n";
exit(0);
