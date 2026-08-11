<?php

declare(strict_types=1);

/**
 * iHymns — songbook default-rights write-path guard (#1769 P4 Commit C)
 * ====================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Commit C lets a curator set a songbook's DEFAULT lyrics/music licence keys and
 * optionally apply them to the songs in that book — but ONLY to songs that have
 * no key yet (the `IS NULL` clause is the whole safety promise; without it the
 * apply would clobber a curator's per-song choice). This guard pins that the
 * apply UPDATE keeps its `IS NULL` filter, that the default write is
 * column-gated + registry-validated, and that the row payload carries the keys.
 *
 * WHY MUTATION-PROVEN (rule #34): the comment/HTML stripper is exercised both
 * ways first; then the load-bearing check — "the fill-NULL-only apply keeps its
 * `IS NULL` lock" — is one whose deletion this guard is written to catch (drop
 * `IS NULL` → red).
 *
 * DB-free source scan. Runs in CI lint.
 *
 *   php tests/php/test-songbook-rights-defaults.php
 *
 * @see appWeb/public_html/manage/songbooks.php   the update-case write path
 * @see .claude/gating-p4-design.md §"Commit C"
 */

$repoRoot = dirname(__DIR__, 2);
$file     = $repoRoot . '/appWeb/public_html/manage/songbooks.php';
if (!is_readable($file)) { fwrite(STDERR, "FATAL: cannot read {$file}\n"); exit(1); }

/** PHP-code-only projection (drop comments + inline HTML) so a symbol named in a
 *  comment or in the page's HTML/JS body cannot satisfy a code check. */
function srdPhpCode(string $src): string
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

/* ---- mutation self-test for srdPhpCode ---- */
$mf = "<?php\n// NeedleComment\n\$x='NeedleCode';\n?>\n<div>NeedleHtml IS NULL</div>\n";
$ms = srdPhpCode($mf);
if (strpos($ms, 'NeedleCode') === false) { $mut[] = 'srdPhpCode FAILS-HIGH: dropped code'; }
if (strpos($ms, 'NeedleComment') !== false) { $mut[] = 'srdPhpCode FAILS-LOW: kept a comment'; }
if (strpos($ms, 'NeedleHtml') !== false)    { $mut[] = 'srdPhpCode FAILS-LOW: kept inline HTML (an IS NULL in HTML must not satisfy the real check)'; }

$raw  = (string)file_get_contents($file);
$code = srdPhpCode($raw);

/* ==== A. registry-validated (no hand-typed licence list, rule #35) ==== */
if (strpos($code, 'licence_registry.php') === false) {
    $failures[] = 'songbooks.php does not require licence_registry.php';
}
if (strpos($code, 'licenceTypeKeys(') === false) {
    $failures[] = 'songbooks.php does not validate default rights keys against licenceTypeKeys() (the ONE registry)';
}

/* ==== B. the default columns are written behind a column-existence gate ==== */
if (!preg_match('/placeColumnExists\(\$db,\s*\'tblSongbooks\',\s*\'DefaultLyricsRightsLicenceKey\'\)/', $code)) {
    $failures[] = 'songbooks.php does not column-gate the DefaultLyricsRightsLicenceKey write (rule #9)';
}
if (!preg_match('/UPDATE\s+tblSongbooks\s+SET\s+DefaultLyricsRightsLicenceKey\s*=\s*\?,\s*DefaultMusicRightsLicenceKey\s*=\s*\?/i', $code)) {
    $failures[] = 'songbooks.php has no UPDATE tblSongbooks SET DefaultLyricsRightsLicenceKey/DefaultMusicRightsLicenceKey write';
}

/* ==== C. THE LOAD-BEARING LOCK — the apply sweep is fill-NULL-only ====
 * Both the lyrics and the music apply UPDATEs must carry an `IS NULL` filter on
 * their own rights column. This is the promise "never overwrite a song that has
 * a key already". Deleting it is the regression this guard exists to catch. */
$lyricsApply = preg_match(
    '/UPDATE\s+tblSongs\s+SET\s+LyricsRightsLicenceKey\s*=\s*\?\s+WHERE\s+SongbookAbbr\s*=\s*\?\s+AND\s+LyricsRightsLicenceKey\s+IS\s+NULL/i',
    $code
);
$musicApply = preg_match(
    '/UPDATE\s+tblSongs\s+SET\s+MusicRightsLicenceKey\s*=\s*\?\s+WHERE\s+SongbookAbbr\s*=\s*\?\s+AND\s+MusicRightsLicenceKey\s+IS\s+NULL/i',
    $code
);
if (!$lyricsApply) {
    $failures[] = 'the lyrics apply-to-songs UPDATE is missing its `AND LyricsRightsLicenceKey IS NULL` fill-NULL-only lock — it would overwrite songs that already have a key (#1769 P4 D4)';
}
if (!$musicApply) {
    $failures[] = 'the music apply-to-songs UPDATE is missing its `AND MusicRightsLicenceKey IS NULL` fill-NULL-only lock — it would overwrite songs that already have a key (#1769 P4 D4)';
}

/* ==== D. the apply sweep is song-column-gated (rule #9) ==== */
if (!preg_match('/placeColumnExists\(\$db,\s*\'tblSongs\',\s*\'LyricsRightsLicenceKey\'\)/', $code)) {
    $failures[] = 'the apply sweep does not column-gate the tblSongs write (a pre-migration install would throw under STRICT, rule #9)';
}

/* ==== E. the row payload carries the two default keys (edit modal pre-fill) ==== */
if (strpos($code, "'default_lyrics_rights'") === false || strpos($code, "'default_music_rights'") === false) {
    $failures[] = 'the edit-row payload does not emit default_lyrics_rights / default_music_rights (the modal could not pre-select)';
}

/* ==== F. the audit records the rights change (owner directive: log it) ==== */
if (strpos($code, "'default_lyrics_rights'") !== false
    && strpos($code, 'auditExtras') === false) {
    $failures[] = 'songbooks.php does not fold the rights defaults into the songbook.edit audit ($auditExtras)';
}

/* ---- report ---- */
if ($failures || $mut) {
    if ($mut) {
        fwrite(STDERR, "FAIL: mutation self-test(s):\n");
        foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($failures) {
        fwrite(STDERR, "FAIL: songbook default-rights write-path guard (#1769 P4 Commit C):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}
echo "PASS: songbook default-rights write path — registry-validated, column-gated default write; the "
   . "apply-to-songs sweep keeps its IS NULL fill-NULL-only lock on both sides; the row payload carries "
   . "the keys; the change is audited. Mutation self-tests behaved as expected.\n";
exit(0);
