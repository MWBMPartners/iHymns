<?php
/**
 * iHymns — Migration Registry Sanity Test
 *
 * Guards against the two regressions seen during the
 * `claude/fix-pending-migrations-Vj4SQ` investigation:
 *
 *   1. A migration is appended to `$migrationOrder` but no matching
 *      `$migrationProbes` entry is added → the page treats that slug
 *      as pending forever (the dashboard's fallback when a probe is
 *      missing is "show as pending"). Cosmetic but means the curator
 *      can never reach "0 pending".
 *
 *   2. A probe is added as `static fn(\mysqli $db) => true` because
 *      writing a smart probe was deferred → the migration shows as
 *      pending forever even after running, again preventing the
 *      counter from reaching 0.
 *
 * Both regressions are silent at runtime — the page renders fine and
 * each migration is individually runnable. The signal only surfaces
 * to the curator as "the count never drops" / "the alert never flips
 * to Schema fully up-to-date". This test catches both at CI time.
 *
 *   php tests/php/test-migration-registry.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

$file = dirname(__DIR__, 2) . '/appWeb/public_html/manage/setup-database.php';
$src  = @file_get_contents($file);
if ($src === false) {
    fwrite(STDERR, "FATAL: could not read $file\n");
    exit(1);
}

$failures = 0;

/* ---------------------------------------------------------------------- *
 * Helper — extract the keys of a top-level array literal.
 *
 * Both `$migrationOrder` and `$migrationProbes` are top-level array
 * literals in the file. We don't try to be a full PHP parser — the
 * registry has a stable shape: array opener on the assignment line,
 * one key per body line (or one entry per body line for the order
 * list), array closer on its own line. Comments inside the array body
 * are stripped before key extraction so a slug mentioned in a comment
 * doesn't get treated as a key.
 *
 * @param string $varName  e.g. 'migrationOrder' (no leading $)
 * @return array<int,string>|null  null when the variable isn't found
 * ---------------------------------------------------------------------- */
function _extractTopLevelArray(string $src, string $varName): ?array
{
    /* Anchor at the assignment then walk forward, tracking bracket
       depth so nested arrays / parens inside closures don't trip us. */
    $needle = '$' . $varName . ' = [';
    $start  = strpos($src, $needle);
    if ($start === false) return null;
    $start += strlen($needle) - 1; /* land on the `[` */

    $depth   = 0;
    $end     = -1;
    $inSq    = false; /* single-quote string */
    $inDq    = false; /* double-quote string */
    $inBlock = false; /* /* … *​/ block comment */
    $inLine  = false; /* // … line comment */

    for ($i = $start, $n = strlen($src); $i < $n; $i++) {
        $ch  = $src[$i];
        $nxt = $src[$i + 1] ?? '';

        if ($inBlock) {
            if ($ch === '*' && $nxt === '/') { $inBlock = false; $i++; }
            continue;
        }
        if ($inLine) {
            if ($ch === "\n") $inLine = false;
            continue;
        }
        if ($inSq) {
            if ($ch === '\\') { $i++; continue; }
            if ($ch === "'") $inSq = false;
            continue;
        }
        if ($inDq) {
            if ($ch === '\\') { $i++; continue; }
            if ($ch === '"') $inDq = false;
            continue;
        }
        if ($ch === '/' && $nxt === '*') { $inBlock = true; $i++; continue; }
        if ($ch === '/' && $nxt === '/') { $inLine = true; $i++; continue; }
        if ($ch === "'") { $inSq = true; continue; }
        if ($ch === '"') { $inDq = true; continue; }
        if ($ch === '[') { $depth++; continue; }
        if ($ch === ']') {
            $depth--;
            if ($depth === 0) { $end = $i; break; }
            continue;
        }
    }
    if ($end === -1) return null;

    $body = substr($src, $start + 1, $end - $start - 1);

    /* Strip comments + string contents before key extraction so a slug
       appearing inside a comment / SQL fragment doesn't get treated
       as a key. We also strip closure bodies (everything between
       `static fn(...) =>` and the next top-level comma) because they
       can contain `=>` arrows in nested map literals. */
    $body = preg_replace('/\/\*.*?\*\//s', '', $body) ?? $body;
    $body = preg_replace('/\/\/[^\n]*/', '', $body)   ?? $body;

    $keys  = [];
    $depth = 0;
    $i     = 0;
    $n     = strlen($body);
    while ($i < $n) {
        /* Skip leading whitespace + commas at depth 0. */
        while ($i < $n && (ctype_space($body[$i]) || $body[$i] === ',')) $i++;
        if ($i >= $n) break;

        /* Read a key token: 'slug-string' or "slug-string". */
        $quote = $body[$i];
        if ($quote !== "'" && $quote !== '"') {
            /* Either a numeric-key entry (rare here) or a value-only
               entry in the order list. For the order list, capture the
               quoted string as the entry value, which IS the slug. */
            if (!ctype_alnum($quote) && $quote !== '_' && $quote !== '-') {
                $i++;
                continue;
            }
            $i++;
            continue;
        }
        $j     = $i + 1;
        $token = '';
        while ($j < $n && $body[$j] !== $quote) {
            if ($body[$j] === '\\' && $j + 1 < $n) {
                $token .= $body[$j + 1];
                $j += 2;
                continue;
            }
            $token .= $body[$j];
            $j++;
        }
        if ($j >= $n) break;

        /* Look-ahead: does this string sit at the start of a key=>value
           pair? Skip whitespace, then check for `=>`. If yes, it's a
           map key; if no, it's a value-only list entry. Either way the
           token is meaningful in the registry. */
        $k = $j + 1;
        while ($k < $n && ctype_space($body[$k])) $k++;
        $isMapKey = ($k + 1 < $n && $body[$k] === '=' && $body[$k + 1] === '>');
        $isListEntry = !$isMapKey;

        $keys[] = $token;

        /* Advance to next entry. We need to walk forward past the
           value (which may contain nested arrays / closures / quoted
           strings) up to the next top-level comma. */
        $i = $j + 1;
        $depth = 0;
        $inSq = $inDq = false;
        $bracketDepth = 0;
        $parenDepth = 0;
        while ($i < $n) {
            $ch  = $body[$i];
            $nxt = $body[$i + 1] ?? '';
            if ($inSq) {
                if ($ch === '\\') { $i += 2; continue; }
                if ($ch === "'") $inSq = false;
                $i++; continue;
            }
            if ($inDq) {
                if ($ch === '\\') { $i += 2; continue; }
                if ($ch === '"') $inDq = false;
                $i++; continue;
            }
            if ($ch === "'") { $inSq = true; $i++; continue; }
            if ($ch === '"') { $inDq = true; $i++; continue; }
            if ($ch === '[') { $bracketDepth++; $i++; continue; }
            if ($ch === ']') { $bracketDepth--; $i++; continue; }
            if ($ch === '(') { $parenDepth++; $i++; continue; }
            if ($ch === ')') { $parenDepth--; $i++; continue; }
            if ($ch === ',' && $bracketDepth === 0 && $parenDepth === 0) {
                $i++; break;
            }
            $i++;
        }
    }

    return $keys;
}

/* ---------------------------------------------------------------------- *
 * Assertion 1 — every $migrationOrder entry has a $migrationProbes key.
 * ---------------------------------------------------------------------- */
$order  = _extractTopLevelArray($src, 'migrationOrder');
$probes = _extractTopLevelArray($src, 'migrationProbes');

if ($order === null) {
    fwrite(STDERR, "FAIL: could not locate \$migrationOrder array literal.\n");
    $failures++;
}
if ($probes === null) {
    fwrite(STDERR, "FAIL: could not locate \$migrationProbes array literal.\n");
    $failures++;
}

if ($order !== null && $probes !== null) {
    $probeSet = array_flip($probes);
    $missing  = [];
    foreach ($order as $slug) {
        if (!isset($probeSet[$slug])) {
            $missing[] = $slug;
        }
    }
    if ($missing) {
        $failures++;
        fwrite(STDERR, "FAIL: " . count($missing) . " migration slug(s) in \$migrationOrder have no probe in \$migrationProbes:\n");
        foreach ($missing as $m) {
            fwrite(STDERR, "  - $m\n");
        }
        fwrite(STDERR, "\nWithout a probe, a slug is treated as pending forever. Add a probe in\n");
        fwrite(STDERR, "appWeb/public_html/manage/setup-database.php — see the probe block\n");
        fwrite(STDERR, "for the smart-detection patterns used by sibling migrations.\n");
    } else {
        echo "PASS: every \$migrationOrder slug has a matching \$migrationProbes entry (" . count($order) . " slugs)\n";
    }
}

/* ---------------------------------------------------------------------- *
 * Assertion 2 — no probe is literally `static fn(\mysqli $db) => true`.
 *
 * That pattern was the original bug — five probes hard-coded to always
 * report their migration as pending, which made the "Apply all pending"
 * counter impossible to drive to zero. Catching the literal here means
 * any future "I'll write a smart probe later" placeholder fails CI
 * immediately rather than silently re-introducing the regression.
 *
 * The regex is line-based and looks for the canonical shape used in
 * the registry. A smart probe always uses `static function (\mysqli
 * $db): bool { … }` (multi-line); the always-pending placeholder is
 * the one-liner `static fn(...) => true` form.
 * ---------------------------------------------------------------------- */
if (preg_match_all(
    '/^\s*\'([^\']+)\'\s*=>\s*static\s+fn\s*\(\s*\\\\?mysqli\s+\$\w+\s*\)\s*=>\s*true\s*,/m',
    $src,
    $matches,
    PREG_SET_ORDER
)) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($matches) . " probe(s) hardcoded to always-pending (=> true):\n");
    foreach ($matches as $m) {
        fwrite(STDERR, "  - {$m[1]}\n");
    }
    fwrite(STDERR, "\nAlways-true probes mean the migration shows as pending forever and the\n");
    fwrite(STDERR, "\"Apply all pending\" counter can never reach 0. Either write a smart\n");
    fwrite(STDERR, "probe that detects completion from the live schema/data, or have the\n");
    fwrite(STDERR, "migration write a sentinel row in tblAppSettings and check that.\n");
} else {
    echo "PASS: no probe is hardcoded to always-pending\n";
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures assertion(s) failed.\n");
    exit(1);
}
echo "\nAll migration-registry assertions passed.\n";
exit(0);
