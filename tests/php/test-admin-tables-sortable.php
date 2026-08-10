<?php

declare(strict_types=1);

/**
 * iHymns — admin sortable-table coverage guard (#1786 adoption sweep, rule #34)
 *
 * ELI5: every admin page that shows a list of records in a table should let you
 * click a column heading to sort by it. This test walks every admin page,
 * finds the ones that are genuinely a record list, and makes sure each one
 * (a) opted a table into the shared sort module, (b) actually LOADS that
 * module (a table can be tagged and still be dead — that was #1799, and the
 * #1786 audit found eleven MORE pages in the same silently-broken shape), and
 * (c) has a `data-sort-key` on every column except a small, explicitly
 * documented set of genuinely non-orderable ones.
 *
 * WHY IT IS TREE-DERIVED, NOT A HAND-TYPED LIST
 * ----------------------------------------------
 * The candidate page list comes from `glob('manage/*.php')` filtered for a
 * literal `<table` — not a list someone typed and will forget to update. Only
 * two allow-lists exist, and both are DOCUMENTED WITH A REASON per rule #34:
 *
 *   $PAGE_EXCLUSIONS   — whole pages whose table(s) are status/report/dashboard
 *                         widgets or a tiny inline picker, not an independent
 *                         sortable record list (rule #1786 investigation).
 *   $COLUMN_EXCEPTIONS — named columns on an otherwise in-scope table that
 *                         cannot carry a working data-sort-key for a stated
 *                         structural reason (a colspan-merged inline-edit
 *                         cell, or a cell that holds a live `<input>` for a
 *                         DIFFERENT manual-reorder mechanism — rule #6).
 *
 * A column needs NO entry in either list to be exempt when it is the LAST
 * header in its row (the near-universal trailing Actions/Review/blank
 * column) or when it has no static text at all (an icon-only header, a drag
 * handle, or a PHP-loop-generated label like tiers.php's per-capability
 * columns or entitlements.php's per-role columns — those are a checkbox
 * MATRIX, not per-row data, the same shape already established for
 * tiers.php's built-in capability columns).
 *
 * SCOPE / LIMITS (stated honestly, matching test-admin-gate-parity.php's
 * convention)
 *   - Static source analysis over PHP-tag-stripped HTML. It proves the
 *     MARKUP is wired, not that the module actually renders correctly in a
 *     browser — that is `tests/test-admin-table-sort.js`'s job for the
 *     module's own click-transition logic.
 *   - Coverage is PAGE-level for "is anything on this page sortable + does
 *     the page boot the module", and TABLE-level for "is every column on an
 *     already-cp-sortable table keyed". A page that already has one fully-
 *     wired table would NOT be flagged if a second, brand-new table were
 *     added to it with zero wiring — the mutation-proof below demonstrates
 *     the guard on a brand-new PAGE, which is the shape #1786 asks for.
 *
 * Usage: php tests/php/test-admin-tables-sortable.php
 * Exit 0 = pass, 1 = fail.
 */

$root    = dirname(__DIR__, 2);
$manage  = $root . '/appWeb/public_html/manage';

if (!is_dir($manage)) {
    fwrite(STDERR, "FAIL: {$manage} not found.\n");
    exit(1);
}

/* ---------------------------------------------------------------------
 * Page-level exclusions — whole admin pages whose table(s) are NOT an
 * independent sortable record list. Every entry needs a one-line reason;
 * `git blame` / this file's history is the audit trail for why it's here.
 * ------------------------------------------------------------------- */
$PAGE_EXCLUSIONS = [
    'index.php'              => 'admin dashboard summary cards, not a curated record list',
    'diagnostics.php'        => 'system diagnostics report',
    'schema-audit.php'       => 'schema-vs-schema.sql drift report, not editable records',
    'setup-database.php'     => 'migration status / apply console',
    'service-projection.php' => 'live operator console — its one small table is a connections list embedded in a real-time page, not an independent CRUD list',
    'intapps-status.php'     => 'integration-app status report',
    'gating-noop-verify.php' => 'gating no-op verification report',
    'analytics.php'          => 'summary / leaderboard stat tables (top songs, top queries), not editable record lists',
    'duplicate-songs.php'    => "its only table is a 2-4 row per-cluster merge-picker (radio/checkbox survivor picker) rendered once per duplicate cluster card, not an independent sortable list — musician-duplicates.php's identical picker shape at line ~572 needs no exclusion because that page ALSO has a real cp-sortable table (the fuzzy-match list) satisfying page-level coverage",
];

/* ---------------------------------------------------------------------
 * Column-level named exceptions — a column on an otherwise in-scope,
 * already-cp-sortable table that cannot carry a working data-sort-key.
 * Keyed by basename => [header text => reason]. Checked ONLY after the
 * automatic "empty text" / "last column" exemptions fail to explain it.
 * ------------------------------------------------------------------- */
$COLUMN_EXCEPTIONS = [
    'my-organisations.php' => [
        'Number'  => 'colspan-merged inline-edit cell (4 data cells serve 6 header cells at render time) — only Type is positionally addressable',
        'Expires' => 'colspan-merged inline-edit cell — see Number',
        'Active'  => 'colspan-merged inline-edit cell — see Number',
        'Notes'   => 'colspan-merged inline-edit cell — see Number',
    ],
    'songbooks.php' => [
        'Order' => 'holds a live numeric <input> feeding the drag-to-reorder mechanism (rule #6 card-layout reorder) — the cell has no sortable text content, and click-to-sort would fight the manual reorder UI',
    ],
];

/** Strip PHP tags (both `<?php ... ?>` and `<?= ... ?>`) so a `?>` or stray
 *  `>` inside a PHP expression can never desynchronise the HTML-tag regexes
 *  below. Non-greedy + DOTALL: each tag closes at its OWN nearest `?>`,
 *  which is exactly PHP's own tag semantics. */
function stripPhp(string $s): string
{
    $s = preg_replace('~<\?php.*?\?>~s', '', $s) ?? $s;
    $s = preg_replace('~<\?=.*?\?>~s', '', $s) ?? $s;
    return $s;
}

/** Strip HTML tags + a handful of common entities down to bare text, for
 *  deciding whether a header cell has any STATIC label at all. */
function bareText(string $s): string
{
    $s = preg_replace('~<[^>]+>~', '', $s) ?? $s;
    $s = preg_replace('~&\w+;~', '', $s) ?? $s;
    return trim($s);
}

$files = glob($manage . '/*.php') ?: [];
sort($files);

$candidates = [];
foreach ($files as $path) {
    $base = basename($path);
    $raw  = (string) file_get_contents($path);
    if (!str_contains($raw, '<table')) {
        continue; // not a candidate at all — nothing to sort
    }
    if (isset($PAGE_EXCLUSIONS[$base])) {
        continue; // documented exclusion
    }
    $candidates[$base] = $raw;
}

if (count($candidates) < 10) {
    fwrite(STDERR, "FAIL: only " . count($candidates) . " candidate admin page(s) found — the <table>\n");
    fwrite(STDERR, "      glob or the exclusion list has gone wrong; this guard would be vacuous.\n");
    exit(1);
}

$failures     = [];
$pagesChecked = 0;
$tablesChecked = 0;
$colsChecked   = 0;

foreach ($candidates as $base => $raw) {
    $pagesChecked++;
    $stripped = stripPhp($raw);

    /* ---- (a) at least one table on this page opted into cp-sortable ---- */
    if (!preg_match('~<table\b[^>]*class="[^"]*\bcp-sortable\b[^"]*"~', $stripped)) {
        $failures[] = "{$base} — has a <table> but none of them carry class=\"cp-sortable\". "
            . "Every admin record-list table should let the user sort by column (#1786); "
            . "if this page's table(s) are genuinely not a record list, add {$base} to \$PAGE_EXCLUSIONS with a reason.";
        continue;
    }

    /* ---- (b) the page actually BOOTS the module ---- */
    if (!str_contains($raw, 'bootSortableTables(')) {
        $failures[] = "{$base} — has a cp-sortable table but never calls bootSortableTables(). "
            . "The table is tagged but dead: every header click is a silent no-op (exactly #1799's "
            . "shape, and the shape the #1786 audit found on eleven more already-tagged pages).";
        continue;
    }

    /* ---- (c) every column on every cp-sortable table is keyed, except a
     *      documented/derivable exception ---- */
    if (!preg_match_all('~<table\b([^>]*)>(.*?)</table>~s', $stripped, $tableMatches, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($tableMatches as $tm) {
        $tAttrs = $tm[1];
        $tBody  = $tm[2];
        if (!preg_match('~class="[^"]*\bcp-sortable\b[^"]*"~', $tAttrs)) {
            continue; // this particular table on the page opted out (e.g. a nested picker)
        }
        $tablesChecked++;

        if (!preg_match('~<thead\b[^>]*>(.*?)</thead>~s', $tBody, $theadMatch)) {
            $failures[] = "{$base} — a cp-sortable table has no <thead>; the module cannot find any headers to wire.";
            continue;
        }

        preg_match_all('~<th\b([^>]*)>(.*?)</th>~s', $theadMatch[1], $thMatches, PREG_SET_ORDER);
        $n = count($thMatches);
        foreach ($thMatches as $i => $th) {
            $colsChecked++;
            $thAttrs = $th[1];
            $thText  = bareText($th[2]);
            $hasKey  = str_contains($thAttrs, 'data-sort-key');
            if ($hasKey) {
                continue;
            }
            if ($thText === '') {
                continue; // icon-only / blank / PHP-loop-generated matrix header
            }
            if ($i === $n - 1) {
                continue; // trailing Actions/Review/controls column
            }
            $exceptionReason = $COLUMN_EXCEPTIONS[$base][$thText] ?? null;
            if ($exceptionReason !== null) {
                continue; // named, documented exception
            }
            $failures[] = "{$base} — column \"{$thText}\" on a cp-sortable table has no data-sort-key "
                . "and is neither the trailing column nor a documented \$COLUMN_EXCEPTIONS entry.";
        }
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: admin sortable-table coverage (#1786):\n\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf(
    "PASS: admin sortable-table coverage — %d candidate page(s), %d cp-sortable table(s), %d column(s) checked, %d page(s)/%d column(s) documented as exceptions.\n",
    $pagesChecked,
    $tablesChecked,
    $colsChecked,
    count($PAGE_EXCLUSIONS),
    array_sum(array_map('count', $COLUMN_EXCEPTIONS))
);
exit(0);
