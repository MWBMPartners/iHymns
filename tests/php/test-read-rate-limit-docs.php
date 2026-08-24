<?php

declare(strict_types=1);

/**
 * iHymns — read-rate-limit docs<->code pairing guard (#1571 C1, rule #35)
 * ============================================================================
 *
 * ELI5: api-docs.yaml has a little table that tells API users "you can call
 * this endpoint up to N times a minute." This test makes sure that number is
 * not just a promise in a Markdown file — it checks the REAL PHP code and
 * confirms the endpoint actually enforces that same number. If a developer
 * ever changes the code's limit (or its bucket) without updating the docs,
 * or vice versa, this test goes red instead of the two silently drifting
 * apart (the exact "a comment saying keep in sync is the failure" trap rule
 * #35 names).
 *
 * WHY THIS EXISTS (#1571 C1):
 * `songbook_export` used to share the 'bulk' read-rate bucket with
 * bulk_songs/bulk_audio (60/min combined) — this commit splits it into its
 * own 'export' bucket (still 60/min, but no longer contended). Two files now
 * have to agree that split happened: api.php's `enforceReadRateLimitKeyed()`
 * call, and api-docs.yaml's read-throttle table row. Nothing in the language
 * enforces that agreement, so this test does.
 *
 * WHAT IS ASSERTED
 *   1. TARGETED — the `songbook_export` case body in api.php calls
 *      `enforceReadRateLimitKeyed('export', …)` and does NOT call
 *      `enforceReadRateLimitKeyed('bulk', …)` (the specific C1 regression:
 *      reverting the bucket rename). Mutation: revert C1 -> this goes red.
 *   2. DOCS -> CODE, tree-derived — every `| \`action\` | N / minute |` row
 *      in api-docs.yaml's "Read throttle" table is parsed (not hand-typed
 *      here), and for each named action this test locates that action's
 *      `case '<action>':` body inside api.php's `switch ($action)` block
 *      (NOT the earlier `switch ($page)` block, which reuses several of the
 *      same case-label strings for unrelated page fragments — e.g. `work` /
 *      `musician` / `search` each appear as page-fragment cases too) and
 *      asserts an `enforceReadRateLimitKeyed('…', N)` call with the
 *      DOCUMENTED N appears somewhere in that body. A stale docs row (the
 *      code's limit changed but nobody updated the table) goes red.
 *
 *      CODE -> DOCS is deliberately NOT asserted: several real
 *      `enforceReadRateLimitKeyed()` scopes (`random`, `song_of_the_day`,
 *      `setlist_get`, `songs_list`, `credit_person`, …) are legitimately
 *      undocumented in the table today, and a guard that fails on correct,
 *      already-shipped code is the rule #34 red flag that gets a guard
 *      weakened or deleted rather than fixed — so this test stays narrow.
 *
 * MUTATION-PROVEN (rule #34): temporarily reverting api.php's
 * `songbook_export` case back to `enforceReadRateLimitKeyed('bulk', 60)`
 * (the pre-#1571 C1 shape) makes assertion 1 fail; temporarily editing the
 * `songbook_export` row's number in api-docs.yaml to a value api.php does
 * not enforce makes assertion 2 fail for that row. Both were run by hand
 * during development (see the commit's verification notes) and restored.
 *
 *   php tests/php/test-read-rate-limit-docs.php
 *
 * Exit status 0 = docs and code agree, 1 = at least one drift.
 *
 * @see appWeb/public_html/includes/read_rate_limit.php
 * @see appWeb/public_html/api-docs.yaml         (the "Read throttle" table)
 * @see .claude/CLAUDE.md rule #35               (cross-file agreement needs a mechanism)
 * @see tests/php/test-rate-limit-pairing.php     (the sibling guard for the OTHER limiter family)
 */

$repo    = dirname(__DIR__, 2);
$apiFile = $repo . '/appWeb/public_html/api.php';
$docFile = $repo . '/appWeb/public_html/api-docs.yaml';

if (!is_readable($apiFile)) {
    fwrite(STDERR, "FATAL: could not read $apiFile\n");
    exit(1);
}
if (!is_readable($docFile)) {
    fwrite(STDERR, "FATAL: could not read $docFile\n");
    exit(1);
}

$apiSrc = (string)file_get_contents($apiFile);
$docSrc = (string)file_get_contents($docFile);

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "  \xE2\x9C\x93 $name\n";
        return;
    }
    $failures++;
    echo "  \xE2\x9C\x97 $name" . ($detail !== '' ? "\n      $detail" : '') . "\n";
}

/* ---------------------------------------------------------------------- *
 * Scope every case-label lookup to the API-ACTION switch, never the
 * earlier PAGE-FRAGMENT switch. Several case-label strings (work, musician,
 * search, …) are reused across both switches for unrelated purposes — a
 * naive `strpos($apiSrc, "case 'work':")` would find the PAGE fragment
 * case (no rate limit at all) instead of the ACTION case this test cares
 * about, which would make assertion 2 wrongly fail on correct code (the
 * rule #34 "guard fails on correct code" trap).
 * ---------------------------------------------------------------------- */
$actionSwitchPos = strpos($apiSrc, 'switch ($action)');
if ($actionSwitchPos === false) {
    fwrite(STDERR, "FATAL: could not find 'switch (\$action)' in api.php — file shape changed.\n");
    exit(1);
}
$actionSrc = substr($apiSrc, $actionSwitchPos);

/**
 * Find the body of `case '<action>':` inside the (already action-scoped)
 * source, bounded by the NEXT same-indent `case ` / `default:` line (or
 * end-of-file if this is the last case). Handles fall-through labels
 * (`case 'song_detail': case 'song_data':` sharing one body) naturally,
 * since the search for "next case" starts AFTER the matched label, not
 * after the whole fall-through group.
 */
function findActionCaseBody(string $actionSrc, string $action): ?string
{
    $needle = "case '" . $action . "':";
    $pos = strpos($actionSrc, $needle);
    if ($pos === false) {
        return null;
    }
    $cursor = $pos + strlen($needle);

    /* Skip over any IMMEDIATELY-following fall-through case labels (only
       whitespace between this one and the next, e.g.
       `case 'song_detail': \n case 'song_data':`), so the body returned
       is the SHARED code all these labels fall into — not the empty gap
       between two adjacent case lines. Without this, `song_detail`'s own
       window would close the instant it hit its sibling `song_data` label
       one line below, capturing zero code and wrongly failing a correct
       case (the rule #34 "guard fails on correct code" trap). */
    while (true) {
        $rest = substr($actionSrc, $cursor);
        if (!preg_match('/^\s*(case \'[^\']*\':)/', $rest, $fm)) {
            break;
        }
        $cursor += strlen($fm[0]);
    }

    $bodyStart = $cursor;
    $nextPos = null;
    if (preg_match('/\n {8}(?:case \'|default:)/', $actionSrc, $m, PREG_OFFSET_CAPTURE, $bodyStart)) {
        $nextPos = $m[0][1];
    }
    $bodyEnd = $nextPos ?? strlen($actionSrc);
    return substr($actionSrc, $bodyStart, $bodyEnd - $bodyStart);
}

/* ---------------------------------------------------------------------- *
 * Assertion 1 — targeted C1 regression check.
 * ---------------------------------------------------------------------- */
$exportBody = findActionCaseBody($actionSrc, 'songbook_export');
check(
    'songbook_export case exists in the action switch',
    $exportBody !== null,
    'expected a case \'songbook_export\': label inside switch ($action)'
);
if ($exportBody !== null) {
    check(
        "songbook_export calls enforceReadRateLimitKeyed('export', …)",
        (bool)preg_match('/enforceReadRateLimitKeyed\(\s*\'export\'\s*,/', $exportBody)
    );
    check(
        "songbook_export no longer shares the 'bulk' bucket",
        !preg_match('/enforceReadRateLimitKeyed\(\s*\'bulk\'\s*,/', $exportBody),
        "found a \"enforceReadRateLimitKeyed('bulk', …)\" call inside songbook_export's case body — the #1571 C1 bucket split regressed"
    );
}

/* ---------------------------------------------------------------------- *
 * Assertion 2 — docs -> code, tree-derived from api-docs.yaml's own table
 * (never a list typed into this test).
 * ---------------------------------------------------------------------- */
$rowCount = 0;
foreach (explode("\n", $docSrc) as $line) {
    if (strpos($line, '|') === false) {
        continue;
    }
    $cells = array_map('trim', explode('|', $line));
    /* A real table row is `| cell1 | cell2 |` -> split('|') yields
       ['', 'cell1', 'cell2', ''] (four elements: leading/trailing empties
       from the outer pipes). Anything else (prose, the header, the `---`
       separator) is skipped by the numeric-limit check below anyway, but
       this count guard keeps a malformed line from indexing past the end. */
    if (count($cells) < 3) {
        continue;
    }
    $actionCell = $cells[1];
    $limitCell  = $cells[2];

    if (!preg_match('/^(\d+)\s*\/\s*minute\b/i', $limitCell, $lm)) {
        continue; // not a "N / minute" data row (header / separator / prose)
    }
    $limitN = (int)$lm[1];

    /* Only the backtick-quoted action name(s) BEFORE the first parenthesis
       are real action names — a parenthetical aside may itself mention a
       DIFFERENT backtick token that is a bucket name, not an action (e.g.
       "`songbook_export` (own `export` bucket, #1571)" — 'export' is the
       bucket, not an action, and there is no `case 'export':` to find). */
    $actionPart = explode('(', $actionCell, 2)[0];
    if (!preg_match_all('/`([a-zA-Z_][a-zA-Z0-9_]*)`/', $actionPart, $am)) {
        continue; // a row naming no backtick action (shouldn't happen; skip defensively)
    }

    foreach ($am[1] as $action) {
        $rowCount++;
        $body = findActionCaseBody($actionSrc, $action);
        check(
            "api.php has a case '$action' in \$action switch (docs table row)",
            $body !== null,
            "api-docs.yaml documents '$action' at $limitN/minute but no matching case was found"
        );
        if ($body === null) {
            continue;
        }
        /* The scope argument is a plain string literal for most actions, but
           `musician`/`credit_person` share one call behind a ternary
           (`enforceReadRateLimitKeyed($_isLegacyPersonAction ? '…' : '…', 120)`)
           — so this matches ANYTHING (never crossing a `;` statement
           boundary) between the opening paren and the documented `, N)`,
           rather than requiring a single quoted literal first argument. */
        $hasMatchingLimit = (bool)preg_match(
            '/enforceReadRateLimitKeyed\(\s*[^;]*?,\s*' . $limitN . '\s*\)/',
            $body
        );
        check(
            "'$action' enforces the documented $limitN/minute",
            $hasMatchingLimit,
            "api-docs.yaml says $limitN/minute for '$action' but its case body has no "
                . "enforceReadRateLimitKeyed('…', $limitN) call — docs/code have drifted"
        );
    }
}
check('parsed a plausible number of table rows (parser sanity)', $rowCount >= 5,
    "only parsed $rowCount action(s) out of api-docs.yaml's read-throttle table — parser under-read");

if ($failures) {
    fwrite(STDERR, "\nFAIL: $failures read-rate-limit docs<->code check(s) failed.\n");
    exit(1);
}
echo "\nOK: read-rate-limit docs and code agree ($rowCount action(s) checked).\n";
