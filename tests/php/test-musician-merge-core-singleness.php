<?php

declare(strict_types=1);

/**
 * test-musician-merge-core-singleness.php — merge-core singleness (G5, #1785)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT THIS GUARDS
 * -----------------
 * #1785 C4 extracted musicianMergeExecute() (includes/musician_helpers.php)
 * as the ONE place that re-points a musician's song-credit rows and deletes
 * the source registry row during a MERGE. Before that extraction,
 * manage/musicians.php's `case 'merge'` and api.php's `admin_musician_merge`
 * each carried their own copy of that exact logic — and a fix landing in
 * one (the discovered tblSongArtists gap; the alias/relation cascade-delete
 * data loss, #1796) had to be independently re-applied to the other or it
 * would silently reappear. This guard is the MECHANISM that stops a THIRD
 * inline copy from ever being pasted back in — anywhere in the app.
 *
 * WHAT IT DERIVES (tree-derived, rule #34 — never a hand-typed line list)
 * -------------------------------------------------------------------------
 * Every occurrence, across the WHOLE appWeb/public_html tree
 * (comment-stripped, via PHP's own tokenizer), of TWO statement shapes:
 *
 *   1. A credit-table RENAME CASCADE: `UPDATE <table> SET Name = ? WHERE
 *      [BINARY] Name = ?` — rewriting every song-credit row that matches an
 *      OLD name to a NEW name. This is the shape a merge/rename/reconcile
 *      needs; it is NOT the shape of an ordinary single-row-by-Id edit
 *      (`WHERE Id = ?`), which manage/editor/api2.php's `credit_upsert`
 *      legitimately does elsewhere for an entirely different reason
 *      (editing ONE credit row's spelling on ONE song, not cascading a
 *      name change across every song that cites it) — and so never matches
 *      this pattern at all.
 *   2. `DELETE FROM tblMusicians WHERE …` — removing a registry row.
 *
 * For each match, the nearest ENCLOSING scope is found by scanning upward
 * for the closest `function NAME(`, `case 'LABEL':`, or (for manage/
 * musicians-bulk-promote.php's if/elseif action dispatch, which isn't a
 * switch) `$act === 'LABEL'` — then the (file, scope) pair is checked
 * against a PINNED allow-list below, each entry with a one-line reason.
 * Anything NOT on the allow-list — a brand new inline cascade, or an old
 * one resurrected — fails the build.
 *
 * THE PINNED ALLOW-LIST (why each of these 8 sites, and only these):
 *
 *   - musician_helpers.php / musicianMergeExecute()        — the ONE merge
 *     core (#1785 C4/C5). Contributes BOTH statement shapes (the cascade
 *     AND the DELETE) since a merge does both in one transaction.
 *   - musician_helpers.php / musicianReconcileCreditNameBytes() — the #1784
 *     byte-reconcile sweep (adopts a registry row's exact spelling onto
 *     stray-byte-variant credit rows). A DIFFERENT operation from a merge
 *     (no registry row is ever deleted here — see rule #1785's plan §3.1:
 *     it is explicitly IDENTITY-PRESERVING, never a merge of two distinct
 *     people), so it does not delegate to musicianMergeExecute().
 *   - musician_helpers.php / musicianReapOrphanedAutoRow() — the #1843
 *     promote-then-reap janitor. A NARROWER operation than a merge: it
 *     removes ONE registry row that the v2 Credits-tab auto-promote minted
 *     from a partial keystroke ("Joh"/"John") and that is now provably an
 *     orphan (no song credit, no satellite/child row, no curation signal,
 *     and NOT the row the current name resolved to). There is no SECOND
 *     registry row and no credit-rename CASCADE at all — the song credits
 *     already cite the new name, which is exactly WHY the old row is
 *     reapable — so nothing about musicianMergeExecute() (looking up two
 *     rows, re-pointing credits, deleting the source) applies. Its ONLY
 *     overlap with the allow-listed sites is the `DELETE FROM tblMusicians`
 *     shape, contributed once here.
 *   - manage/musicians.php / case 'rename'                 — the page's
 *     single-registry-row rename (old spelling -> new spelling, same
 *     person, no second row involved — structurally not a merge).
 *   - manage/musicians.php / case 'delete_from_registry'    — an explicit
 *     single-row delete with no credit-cascade at all (only a usage COUNT,
 *     never a rename).
 *   - api.php / case 'admin_musician_rename'                — the API
 *     sibling of musicians.php's rename case (same justification).
 *   - api.php / case 'admin_musician_delete'                — the API
 *     sibling of delete_from_registry (same justification).
 *   - manage/musicians-bulk-promote.php / $act === 'merge'  — a NARROWER,
 *     deliberately separate operation: folding a CITED-BUT-UNREGISTERED
 *     name into an EXISTING registry row. There is no source REGISTRY ROW
 *     to look up, carry aliases/relations from, or delete — the whole
 *     point of musicianMergeExecute() (looking up two tblMusicians rows,
 *     one of which gets removed) does not apply. Re-pointing credit rows
 *     by NAME is the only overlap, and the two are similar-shaped by
 *     necessity, not by copy-paste — its own doc-comment says so
 *     ("uses the same UPDATE pattern the single-row merge action uses").
 *
 *   php tests/php/test-musician-merge-core-singleness.php
 *
 * Exit status 0 = every match is on the allow-list, 1 = an unlisted site
 * was found (or an allow-listed site has disappeared — the list is stale).
 *
 * @see .claude/musicians-dedup-1785-plan.md §10 (guard G5)
 */

$root   = dirname(__DIR__, 2);
$pubDir = $root . '/appWeb/public_html';

function musMcsStripPhpComments(string $src): string
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
 * Nearest enclosing "scope name" for a matched line, scanning UPWARD
 * through the stripped source's lines for the closest function
 * declaration, switch-case label, or ($act === '…') action branch.
 */
function musMcsEnclosingScope(array $lines, int $matchLineIdx): string
{
    for ($i = $matchLineIdx; $i >= 0; $i--) {
        if (preg_match('/function\s+(\w+)\s*\(/', $lines[$i], $m)) {
            return "function {$m[1]}()";
        }
        if (preg_match("/case\s+'([\\w-]+)'\\s*:/", $lines[$i], $m)) {
            return "case '{$m[1]}'";
        }
        if (preg_match("/\\\$act\\s*===\\s*'(\\w+)'/", $lines[$i], $m)) {
            return "\$act === '{$m[1]}'";
        }
    }
    return '(unknown enclosing scope)';
}

/* The two statement shapes this guard cares about. Both require the table/
   registry name to be present literally in the SQL text (a bound `?`
   placeholder for the VALUE, never the identifier) — matches rule #5's own
   allow-list of legitimate interpolations (hardcoded constants / bound
   `{$var}` table names), so this guard and rule #5 agree on what "the
   table name is a real identifier here" looks like. */
$patterns = [
    'credit-rename-cascade' => '/UPDATE\s+[`{$]\S*?\s+SET\s+Name\s*=\s*\?\s+WHERE\s+(?:BINARY\s+)?Name\s*=\s*\?/',
    'musicians-delete'      => '/DELETE\s+FROM\s+tblMusicians\s+WHERE/',
];

/* Pinned, derived-and-justified allow-list — see the file doc-block above
   for the reasoning behind each entry. Keyed by
   "relative/path.php|scope|pattern-label" — the PATTERN LABEL is part of
   the key (not just file+scope) so that a mutation which REMOVES one of
   musicianMergeExecute()'s two statement shapes (say, its DELETE) while
   its OTHER shape (the credit-rename cascade) still matches in the same
   function is still caught as "missing" rather than masked by the
   scope still being present for a different reason (rule #34: a guard
   must be able to fail in BOTH directions — this file's own mutation
   testing caught exactly this gap in an earlier, coarser-keyed draft). */
$allowed = [
    'includes/musician_helpers.php|function musicianMergeExecute()|credit-rename-cascade'             => true,
    'includes/musician_helpers.php|function musicianMergeExecute()|musicians-delete'                   => true,
    'includes/musician_helpers.php|function musicianReconcileCreditNameBytes()|credit-rename-cascade'  => true,
    'includes/musician_helpers.php|function musicianReapOrphanedAutoRow()|musicians-delete'             => true,
    'manage/musicians.php|case \'rename\'|credit-rename-cascade'                                       => true,
    'manage/musicians.php|case \'delete_from_registry\'|musicians-delete'                               => true,
    'api.php|case \'admin_musician_rename\'|credit-rename-cascade'                                     => true,
    'api.php|case \'admin_musician_delete\'|musicians-delete'                                          => true,
    'manage/musicians-bulk-promote.php|$act === \'merge\'|credit-rename-cascade'                        => true,
];

$found = []; // "$rel|$scope|$label" => list of "line N"
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pubDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $stripped = musMcsStripPhpComments((string)file_get_contents($path));
    $lines = explode("\n", $stripped);
    $rel = str_replace($pubDir . '/', '', $path);

    foreach ($patterns as $label => $pattern) {
        foreach ($lines as $i => $line) {
            if (!preg_match($pattern, $line)) {
                continue;
            }
            $scope = musMcsEnclosingScope($lines, $i);
            $key = "{$rel}|{$scope}|{$label}";
            $found[$key][] = 'line ' . ($i + 1);
        }
    }
}

$failed = 0;

$unexpected = array_diff(array_keys($found), array_keys($allowed));
if ($unexpected) {
    $failed++;
    fwrite(STDERR, "FAIL: " . count($unexpected) . " unlisted merge/rename/delete site(s) found:\n");
    foreach ($unexpected as $key) {
        fwrite(STDERR, "  - $key (" . implode('; ', $found[$key]) . ")\n");
    }
    fwrite(STDERR, "This is either a NEW inline copy of the merge core (rule #22/#35 — delegate to\n");
    fwrite(STDERR, "musicianMergeExecute() in includes/musician_helpers.php instead) or a genuinely\n");
    fwrite(STDERR, "new, narrower operation that needs its own justified entry added to \$allowed,\n");
    fwrite(STDERR, "mirroring the reasoning in this file's doc-block.\n");
} else {
    echo "PASS: no unlisted merge/rename/delete site found\n";
}

$missing = array_diff(array_keys($allowed), array_keys($found));
if ($missing) {
    $failed++;
    fwrite(STDERR, "FAIL: " . count($missing) . " allow-listed site(s) no longer exist — the allow-list is stale:\n");
    foreach ($missing as $key) {
        fwrite(STDERR, "  - $key\n");
    }
    fwrite(STDERR, "Remove the stale entry (and its justification) from \$allowed in this test.\n");
} else {
    echo "PASS: every allow-listed site still exists (" . count($allowed) . " sites)\n";
}

if ($failed > 0) {
    fwrite(STDERR, "\n$failed check(s) failed.\n");
    exit(1);
}
echo "\nmusician-merge-core-singleness: the derived tree matches the allow-list exactly.\n";
exit(0);
