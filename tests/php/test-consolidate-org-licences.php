<?php

declare(strict_types=1);

/**
 * iHymns — DM-1 org-licence consolidation guard (#1769 P5)
 * =======================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * DM-1 completes the org-licence consolidation into tblOrganisationLicences: it
 * carries the legacy expiry (fill-NULL-only, so it never clobbers a curator's
 * value) and consolidates hand-written ghost rows (row-count-reported, never
 * silent). This guard pins the fill-NULL-only `IS NULL` lock, the INSERT IGNORE
 * idempotency, the no-DDL posture, the ghost disclosure, the revert, and the
 * registry probe being a real data-derived closure. Mutation-proven.
 *
 * DB-free comment-stripped source scan.  php tests/php/test-consolidate-org-licences.php
 *
 * @see appWeb/.sql/migrate-consolidate-org-licences.php
 * @see appWeb/.sql/revert-consolidate-org-licences.php
 * @see appWeb/public_html/manage/includes/migration-registry.php  'consolidate-org-licences'
 * @see .claude/gating-model-review-1769-plan.md  DM-1
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

function cologStrip(string $src): string
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

/* mutation self-test */
$mf = "<?php\n// IS NULL NeedleComment\n\$x='NeedleCode';\n";
$ms = cologStrip($mf);
if (strpos($ms, 'NeedleCode') === false) { $mut[] = 'cologStrip FAILS-HIGH'; }
if (strpos($ms, 'NeedleComment') !== false) { $mut[] = 'cologStrip FAILS-LOW (kept a comment; an IS NULL in a comment would false-satisfy)'; }

$migFile = $repoRoot . '/appWeb/.sql/migrate-consolidate-org-licences.php';
$revFile = $repoRoot . '/appWeb/.sql/revert-consolidate-org-licences.php';
$regFile = $pub . '/manage/includes/migration-registry.php';
foreach (['migrate' => $migFile, 'revert' => $revFile, 'registry' => $regFile] as $k => $p) {
    if (!is_readable($p)) { fwrite(STDERR, "FATAL: cannot read {$k} at {$p}\n"); exit(1); }
}
$mig = cologStrip((string)file_get_contents($migFile));
$rev = cologStrip((string)file_get_contents($revFile));
$reg = cologStrip((string)file_get_contents($regFile));

/* Leg A — fill-NULL-only expiry carry (THE load-bearing lock). */
if (!preg_match('/UPDATE\s+tblOrganisationLicences\s+ol\s+JOIN\s+tblOrganisations\s+o.*?SET\s+ol\.ExpiresAt\s*=\s*o\.LicenceExpiresAt.*?WHERE\s+ol\.ExpiresAt\s+IS\s+NULL/s', $mig)) {
    $failures[] = 'the Leg-A expiry carry is missing its fill-NULL-only `WHERE ol.ExpiresAt IS NULL` lock — it would clobber a curator-set expiry (#1769 P5)';
}

/* Leg B — INSERT IGNORE (idempotent, dedups on the UNIQUE key). */
if (!preg_match('/INSERT\s+IGNORE\s+INTO\s+tblOrganisationLicences/i', $mig)) {
    $failures[] = 'the Leg-B ghost consolidation is not INSERT IGNORE — it must be idempotent + dedup on (OrganisationId, LicenceType)';
}

/* Ghost disclosure — the live-tier caveat is reported, not silent. */
if (strpos($mig, 'ghost') === false && strpos($mig, 'tblContentLicences') === false) {
    $failures[] = 'the migration does not consider hand-written ghost tblContentLicences rows';
}
if (!preg_match('/COUNT\(\*\)\s+FROM\s+tblContentLicences/i', $mig)) {
    $failures[] = 'the migration does not row-count-probe the ghost rows before migrating them (the disclosure the live-tier side effect requires)';
}

/* No DDL (data-only, D6). */
if (preg_match('/\b(CREATE\s+TABLE|ALTER\s+TABLE)\b/i', $mig)) {
    $failures[] = 'migrate-consolidate-org-licences.php contains DDL — it must be data-only';
}

/* Revert clears the carried expiry + deletes ghost-derived rows. */
if (!preg_match('/SET\s+ol\.ExpiresAt\s*=\s*NULL/i', $rev)) {
    $failures[] = 'revert does not clear the carried ExpiresAt back to NULL';
}
if (!preg_match('/DELETE\s+ol\s+FROM\s+tblOrganisationLicences/i', $rev)) {
    $failures[] = 'revert does not delete the ghost-derived join rows';
}

/* Registry entry + real drift probe. */
$entryPos = strpos($reg, "'consolidate-org-licences' =>");
if ($entryPos === false) {
    $failures[] = "migration-registry.php has no 'consolidate-org-licences' entry";
} else {
    $win = substr($reg, $entryPos, 3000);
    if (strpos($win, "'script' => 'migrate-consolidate-org-licences.php'") === false) {
        $failures[] = "the 'consolidate-org-licences' entry does not point at its script";
    }
    if (preg_match('/\'probe\'\s*=>\s*static\s+fn[^\n]*=>\s*true/', $win)) {
        $failures[] = "the 'consolidate-org-licences' probe is a static `=> true` — it must detect real completion (rule #19)";
    }
    if (strpos($win, 'tblOrganisationLicences') === false
        || strpos($win, 'IS NULL') === false
        || strpos($win, 'NOT EXISTS') === false) {
        $failures[] = "the 'consolidate-org-licences' probe is not the data-derived drift detector it must be (join table + IS NULL expiry check + NOT EXISTS ghost check)";
    }
}

if ($failures || $mut) {
    if ($mut) { fwrite(STDERR, "FAIL: mutation self-test(s):\n"); foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); } fwrite(STDERR, "\n"); }
    if ($failures) {
        fwrite(STDERR, "FAIL: DM-1 org-licence consolidation guard (#1769 P5):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}
echo "PASS: DM-1 — the expiry carry is fill-NULL-only, ghost consolidation is INSERT IGNORE + row-count-reported, "
   . "no DDL, revert clears + deletes, and the registry probe is a real data-derived drift detector. "
   . "Mutation self-tests behaved as expected.\n";
exit(0);
