<?php

declare(strict_types=1);

/**
 * iHymns — Live-session Channel-wall presence guard (#1770 G1)
 * ============================================================================
 * ELI5: three docroots (alpha / beta / production) share ONE MySQL database.
 * Every live-follow / service-mode row is stamped with which of the three it
 * belongs to (`Channel`). A query that resolves a session, a join code, or a
 * presence row by anything OTHER than that row's own already-known primary
 * key — a typed CODE, a VENUE, a searched TOKEN — must also filter on
 * `Channel`, or a request served by one docroot can read/write a row that
 * belongs to a DIFFERENT one (rule #26 / CLAUDE.md "the prod-stale class of
 * bug").
 *
 * WHAT IS SCANNED (tree-derived, not a typed-in file list — rule #34):
 *   appWeb/public_html/api.php
 *   appWeb/public_html/includes/service_mode.php
 *   appWeb/public_html/includes/service_driver_keys.php
 *   appWeb/public_html/includes/session_control_token.php
 * — the four files the #1770 plan §9 G1 names, because they are every file
 * this codebase's `service_mode`/`live_follow`/`service_drive`/
 * `session_control_token` machinery lives in. Every `->prepare(...)` /
 * `->query(...)` SQL literal in them is a CANDIDATE; which candidates
 * actually need a Channel filter is derived from the SQL TEXT itself (which
 * table it targets, what its WHERE clause says), never from a hand-typed
 * per-query allowlist.
 *
 * WHAT COUNTS AS "TOUCHES" A CHANNEL-WALLED TABLE: the query's own text
 * names `tblLiveFollowSessions`, `tblServicePresence`, or
 * `tblLiveFollowJoinCodes` as its FROM / UPDATE / INSERT INTO / DELETE FROM
 * subject (JOINs to OTHER tables, e.g. `tblUsers`, `tblOrganisations`,
 * `tblOrgVenues`, do not themselves trigger this — the walled table is what
 * matters).
 *
 * THE EXEMPTION (STRUCTURAL, not a name-based allowlist — I1's own wording):
 * "PK-scoped follow-up writes after a channel-scoped resolve are the only
 * exemption." A predicate counted as PK/FK-scoped here is an equality
 * against one of FOUR columns this schema makes GLOBALLY UNIQUE or a direct
 * FK to an already-identified row — never a second code/venue/lookup path:
 *   - `Id`             — every table's own primary key.
 *   - `SessionCode`    — `UNIQUE KEY uq_Code (SessionCode)` on
 *                        `tblLiveFollowSessions` (schema.sql) — GLOBALLY
 *                        unique across every channel for the row's whole
 *                        life, so a `SessionCode = ?` lookup can resolve at
 *                        most ONE row ever; a Channel filter alongside it
 *                        would be provably redundant, not a missing check
 *                        (this is WHY `live_follow_update`/`_heartbeat`/
 *                        `_leave` — all `SessionCode + HostUserId` scoped —
 *                        are correct without one).
 *   - `PresenceToken`  — `tblServicePresence.PresenceToken CHAR(43)` is
 *                        likewise a UNIQUE column (schema.sql).
 *   - `SessionId`      — the FK from `tblServicePresence` /
 *                        `tblLiveFollowJoinCodes` back to the (already
 *                        Id-resolved) session row.
 * INSERT statements are exempt from this check entirely: an INSERT creates
 * a NEW row rather than resolving an EXISTING one across channels, so the
 * cross-channel-read/write risk this guard exists for does not apply to it
 * (the columns it WRITES, including `Channel` where the table has one, are
 * a separate correctness concern, not this guard's job).
 * A WHERE clause may ALSO carry any number of simple LITERAL flag/status
 * predicates alongside a PK/FK predicate (`IsActive = 1`, `Status =
 * 'previous'`, an `IS (NOT) NULL` check, an idle-freshness
 * `TIMESTAMPDIFF(...) >= IdleTimeoutMins` comparison) without losing the
 * exemption — none of those introduce a SECOND way to select a session.
 *
 * EVERYTHING ELSE touching one of the three tables must contain the literal
 * word `Channel` somewhere in its SQL text — whether as a bound
 * `Channel = ?` predicate or a correlated `EXISTS (... s.Channel = ?)`
 * subquery (`serviceMode_retireSessionCodes()` /
 * `serviceMode_retireExpiredCodes()`'s shape).
 *
 * MUTATION-PROVEN (rule #34): see the commit body for the exact mutation —
 * the `Channel = ?` predicate was deleted from one live query, the guard
 * went RED, and restoring it went GREEN again.
 *
 * Usage:
 *   php tests/php/test-live-session-channel.php
 *   IHYMNS_SCAN_ROOT=/path/to/tree php tests/php/test-live-session-channel.php
 *
 * @see appWeb/public_html/includes/service_mode.php  header doc-block (I1)
 * @see .claude/live-follow-1770-plan.md §9 G1
 * @see tests/php/test-rate-limit-pairing.php  the sibling paired-contract
 *      scanner this guard's extraction helpers are modelled on.
 */

$scanRoot = getenv('IHYMNS_SCAN_ROOT') ?: (dirname(__DIR__, 2) . '/appWeb/public_html');
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: could not read $scanRoot\n");
    exit(1);
}

$failures = 0;
$passed   = 0;

function lsc(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Strip PHP comments (tokenizer-based — never mangles a string literal). */
function lscStripComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/**
 * Split a call's argument list (opening paren at $open) into top-level
 * argument source strings, tracking ()[]{} nesting and both quote styles.
 * Mirrors tests/php/test-rate-limit-pairing.php's rlpCallArgs().
 *
 * @return list<string>|null null on an unbalanced call (treated as FAILURE).
 */
function lscCallArgs(string $src, int $open): ?array
{
    $len = strlen($src);
    $depth = 0;
    $quote = '';
    $args  = [];
    $buf   = '';
    for ($i = $open; $i < $len; $i++) {
        $c = $src[$i];
        if ($quote !== '') {
            $buf .= $c;
            if ($c === '\\') { $i++; if ($i < $len) { $buf .= $src[$i]; } continue; }
            if ($c === $quote) { $quote = ''; }
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; $buf .= $c; continue; }
        if ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
            if ($depth === 1) { continue; }
            $buf .= $c;
            continue;
        }
        if ($c === ')' || $c === ']' || $c === '}') {
            $depth--;
            if ($depth === 0) { $args[] = trim($buf); return $args; }
            $buf .= $c;
            continue;
        }
        if ($c === ',' && $depth === 1) { $args[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    return null;
}

function lscLine(string $src, int $offset): int
{
    return substr_count($src, "\n", 0, $offset) + 1;
}

/**
 * Reduce a call-argument's raw PHP source (e.g.
 * `'UPDATE … WHERE SessionCode = ? AND HostUserId = ?' . $idleFreshSql`) to
 * the concatenated CONTENT of its quoted string literals only — quote
 * characters removed, escapes resolved, and every non-literal span (PHP glue
 * like ` . $idleFreshSql`) collapsed to a single space so word boundaries
 * survive without polluting the text with PHP syntax. This is what makes
 * the WHERE-clause regexes below anchor cleanly on `?$` instead of tripping
 * over a trailing quote character or a `.` operator.
 *
 * Every REAL Channel filter in this codebase is written inline as literal
 * `Channel = ?` SQL text (never assembled by a helper function's return
 * value), so collapsing non-literal spans to whitespace never hides one.
 */
function lscLiteralText(string $raw): string
{
    $len = strlen($raw);
    $out = '';
    $quote = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];
        if ($quote !== '') {
            if ($c === '\\') { $i++; if ($i < $len) { $out .= $raw[$i]; } continue; }
            if ($c === $quote) { $quote = ''; continue; }
            $out .= $c;
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; continue; }
        $out .= ' ';
    }
    return $out;
}

/** The three Channel-walled tables (rule #26). */
const LSC_WALLED_TABLES = ['tblLiveFollowSessions', 'tblServicePresence', 'tblLiveFollowJoinCodes'];
/** Columns whose equality this schema makes globally unique, or that are a
 *  direct FK to an already-identified row (see the file doc-block). */
const LSC_PK_COLUMNS = ['Id', 'SessionCode', 'PresenceToken', 'SessionId'];

/**
 * Is $sql (the raw call-argument text — may still contain PHP concatenation
 * operators / variable names alongside the literal SQL) an INSERT? Detected
 * on the FIRST non-quote SQL keyword to avoid a false match on a WHERE
 * clause that happens to mention the word "insert" in a comment-stripped
 * string (none do today; this is defence-in-depth, not observed).
 */
function lscIsInsert(string $sql): bool
{
    return (bool)preg_match('/^\s*[\'"]?\s*INSERT\s+INTO\b/i', $sql);
}

/**
 * Which walled table(s) does this query's FROM / UPDATE / DELETE-FROM
 * subject name? (JOIN targets are deliberately NOT scanned here — a JOIN to
 * tblLiveFollowSessions from a DIFFERENT driving table, e.g.
 * serviceMode_presenceCcliNumber()'s presence-driven queries, is still
 * caught because tblServicePresence is itself a walled table and IS the
 * driving FROM subject there.)
 */
function lscDrivingTables(string $sql): array
{
    $found = [];
    foreach (LSC_WALLED_TABLES as $t) {
        if (preg_match('/\b(?:FROM|UPDATE)\s+' . preg_quote($t, '/') . '\b/i', $sql)) {
            $found[] = $t;
        }
    }
    return $found;
}

/**
 * Isolate the WHERE clause text: from the first top-level `WHERE` keyword to
 * the next `ORDER BY` / `GROUP BY` / `LIMIT` / `FOR UPDATE` / `ON DUPLICATE
 * KEY` or end of string. "Top-level" here just means the first occurrence —
 * every query in scope has exactly one WHERE.
 */
function lscWhereClause(string $sql): ?string
{
    if (!preg_match('/\bWHERE\b(.*)$/is', $sql, $m)) { return null; }
    $tail = $m[1];
    $tail = preg_split('/\b(ORDER\s+BY|GROUP\s+BY|LIMIT|FOR\s+UPDATE|ON\s+DUPLICATE\s+KEY)\b/i', $tail, 2)[0];
    return trim($tail);
}

/**
 * Split a WHERE-clause string on top-level ` AND ` — i.e. not inside
 * ()-nesting (`(? = 0 OR s.VenueId = ?)` stays ONE chunk) — case-
 * insensitively.
 *
 * @return list<string>
 */
function lscSplitTopLevelAnd(string $where): array
{
    $len = strlen($where);
    $depth = 0;
    $chunks = [];
    $buf = '';
    $i = 0;
    while ($i < $len) {
        $c = $where[$i];
        if ($c === '(') { $depth++; $buf .= $c; $i++; continue; }
        if ($c === ')') { $depth--; $buf .= $c; $i++; continue; }
        if ($depth === 0 && preg_match('/\GAND\b/i', $where, $m, 0, $i)
            && ($i === 0 || preg_match('/\s/', $where[$i - 1]))
        ) {
            $chunks[] = trim($buf);
            $buf = '';
            $i += strlen($m[0]);
            continue;
        }
        $buf .= $c;
        $i++;
    }
    $chunks[] = trim($buf);
    return array_values(array_filter($chunks, static fn(string $s): bool => $s !== ''));
}

/**
 * Is this WHERE clause PK/FK-scoped (structurally exempt from the Channel
 * requirement)? A conjunction (top-level AND only — see
 * lscSplitTopLevelAnd()) is exempt the moment ANY ONE of its chunks is a
 * bound equality against a globally-unique-or-FK column
 * (`LSC_PK_COLUMNS`): once ONE such predicate is present, every OTHER
 * AND-ed chunk can only narrow the result further, never substitute a
 * DIFFERENT (wrong-channel) row for the one that predicate already pins —
 * see the file doc-block's `SessionCode` global-uniqueness reasoning. A
 * top-level OR is NEVER exempt this way (it stays inside one un-matching
 * chunk, since only AND is split on) — this is what stops a hypothetical
 * `WHERE SessionCode = ? OR VenueId = ?` from slipping through.
 */
function lscIsPkScopedOnly(string $where): bool
{
    $pkAlt = implode('|', LSC_PK_COLUMNS);
    /* The "alias." prefix is optional AS A WHOLE (a bare `Id = ?` with no
       table alias is just as PK-scoped as `s.Id = ?`) — it must not be
       split into "an identifier, then an optional dot", which would force
       an alias to be present in practice. */
    $pkRe = '/^(?:[a-zA-Z_][a-zA-Z0-9_]*\.)?(?:' . $pkAlt . ')\s*=\s*\?$/i';

    foreach (lscSplitTopLevelAnd(trim($where)) as $chunk) {
        $chunk = trim($chunk, "() \t\n\r");
        if (preg_match($pkRe, $chunk)) { return true; }
    }
    return false;
}

/**
 * When a prepare()/query() call's first argument is a bare `$variable`
 * (built up beforehand, e.g. serviceMode_presenceCcliNumber()'s two-branch
 * `$sql = "..."; … $sql = "...";` org/legacy fork) rather than an inline
 * literal, resolve EVERY assignment to that variable found anywhere earlier
 * in the same file. Each branch is a genuine candidate in its own right —
 * unioning them (rather than taking only the last) is what catches a
 * violation hiding in an EARLIER branch a later one happens to fix.
 *
 * @return list<string> Raw (unstripped) assignment RHS source strings.
 */
function lscResolveVarAssignments(string $src, int $beforePos, string $varName): array
{
    $out = [];
    $re = '/' . preg_quote($varName, '/') . '\s*=\s*(.+?);/s';
    if (preg_match_all($re, substr($src, 0, $beforePos), $m)) {
        foreach ($m[1] as $rhs) {
            $out[] = $rhs;
        }
    }
    return $out;
}

/* ===========================================================================
 * SCAN
 * ======================================================================== */

$targets = [
    $scanRoot . '/api.php',
    $scanRoot . '/includes/service_mode.php',
    $scanRoot . '/includes/service_driver_keys.php',
    $scanRoot . '/includes/session_control_token.php',
];

$scanned   = 0;
$candidates = 0;   // queries touching a walled table
$violations = [];  // "file:line (table) — reason"
$unparsed   = [];

foreach ($targets as $path) {
    if (!is_readable($path)) { continue; }
    $scanned++;
    $rel = basename(dirname($path)) . '/' . basename($path);
    $src = lscStripComments((string)file_get_contents($path));

    foreach (['prepare', 'query'] as $fn) {
        $offset = 0;
        while (($pos = strpos($src, '->' . $fn . '(', $offset)) !== false) {
            $callOpen = $pos + strlen('->' . $fn);
            $offset = $pos + 1;

            $args = lscCallArgs($src, $callOpen);
            $line = lscLine($src, $pos);
            if ($args === null || $args === []) { $unparsed[] = "$rel:$line ($fn)"; continue; }
            $sqlArg = $args[0];

            /* An inline literal is analysed directly; a bare `$variable`
               (built up beforehand — serviceMode_presenceCcliNumber()'s
               two-branch fork is the one real case in scope) resolves to
               EVERY assignment found earlier in the file, each analysed as
               its own candidate. Anything else (a call, a ternary inline in
               the argument, string-building not matching either shape) is
               reported as unparsed — a parse we cannot trust must not be
               silently reported as compliant. */
            if ($sqlArg !== '' && ($sqlArg[0] === "'" || $sqlArg[0] === '"')) {
                $sqlTexts = [lscLiteralText($sqlArg)];
            } elseif (preg_match('/^\$[A-Za-z_]\w*$/', $sqlArg)) {
                $assignments = lscResolveVarAssignments($src, $pos, $sqlArg);
                if ($assignments === []) { $unparsed[] = "$rel:$line ($fn — no assignment found for {$sqlArg})"; continue; }
                $sqlTexts = array_map('lscLiteralText', $assignments);
            } else {
                $unparsed[] = "$rel:$line ($fn — non-literal SQL argument: " . substr($sqlArg, 0, 60) . ')';
                continue;
            }

            foreach ($sqlTexts as $sqlText) {
                $tables = lscDrivingTables($sqlText);
                if (!$tables) { continue; }   // doesn't touch a walled table

                $candidates++;

                if (lscIsInsert($sqlText)) { continue; }   // INSERT — see file doc-block

                $where = lscWhereClause($sqlText);
                if ($where !== null && lscIsPkScopedOnly($where)) { continue; }   // Tier A exemption

                /* Tier B: "Channel" must appear in the FILTER — the WHERE
                   clause (which also covers a correlated EXISTS subquery
                   attached to it, serviceMode_retireSessionCodes()'s shape)
                   — never merely in a SELECT column list. A query that
                   SELECTS s.Channel without FILTERING on it (e.g. so the
                   caller can read the value out) proves nothing about which
                   channel's rows it touched to get there — checking the
                   whole query text for the substring would have let exactly
                   that slip through uncaught. */
                $filterText = $where ?? $sqlText;
                if (strpos($filterText, 'Channel') !== false) { continue; }

                $violations[] = "$rel:$line (" . implode(',', $tables) . ') — no Channel filter and not a bare PK/FK lookup'
                    . ($where !== null ? (' [WHERE ' . preg_replace('/\s+/', ' ', $where) . ']') : ' [no WHERE clause]');
            }
        }
    }
}

lsc($scanned === count($targets), "0.1 all {$scanned}/" . count($targets) . ' target files were readable');
lsc($candidates > 0, "0.2 found {$candidates} candidate quer" . ($candidates === 1 ? 'y' : 'ies') . ' touching a Channel-walled table (the scanner works)');
lsc($unparsed === [], '0.3 every prepare()/query() call parsed cleanly' . ($unparsed === [] ? '' : ' — unparsed: ' . implode('; ', $unparsed)));

lsc(
    $violations === [],
    '1.1 every non-INSERT query touching tblLiveFollowSessions / tblServicePresence / '
        . 'tblLiveFollowJoinCodes either filters on Channel or is a bare PK/FK-scoped '
        . 'follow-up (rule #26)' . ($violations === [] ? " ({$candidates} candidates clean)" : "\n  - " . implode("\n  - ", $violations))
);

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
