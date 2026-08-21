<?php

declare(strict_types=1);

/**
 * iHymns — Slug-advanced-field partial wiring guard (#1870, Wave 3 C4)
 *
 * ELI5
 * ----
 * `/manage/*` used to show an always-visible "Slug" text box on 11 forms
 * across 6 pages. #1870 tucked every one of them behind a collapsed
 * `<details>` "Edit slug (advanced)" panel rendered by the ONE shared
 * partial, `manage/includes/slug-field.php::ihymns_slug_advanced_field()`
 * (rule #44 — derive/omit the vanity control; the modularity rule — ONE
 * partial, not 11 pasted collapse blocks). This guard keeps that true: a
 * future edit that pastes a raw slug `<input>` back in, or that adds a
 * NEW page which accepts a posted `slug` without going through the shared
 * partial, fails CI.
 *
 * Three checks, all tree-derived (rule #34 — never a typed file list):
 *
 *   (1) Zero raw `<input … name="slug"` markup anywhere under
 *       `appWeb/public_html/manage/` EXCEPT inside the partial itself.
 *   (2) The partial's own `<input name="slug">` still exists AND still
 *       sits inside a `<details>` element (the whole point — an input
 *       that escaped its disclosure would be an always-visible slug box
 *       again, just moved into the shared file).
 *   (3) Every manage page that actually ACCEPTS a posted `slug` value —
 *       derived two ways, not hand-typed, because #1870's own planning
 *       pass discovered the naive derivation under-reports (see below) —
 *       either calls `ihymns_slug_advanced_field(` itself or is a
 *       recorded non-form consumer.
 *
 * DERIVATION FINGERPRINT — CAUGHT ITS OWN UNDER-REPORT BEFORE COMMITTING
 * (rule #34's "a scanner that under-reports is worse than no scanner"):
 * a first pass at check (3) grepped only for the literal `$_POST['slug']`
 * pattern. That correctly finds `catalogues.php` / `organisations.php` /
 * `songbook-series.php` / `works.php`, which read the field directly — but
 * SILENTLY MISSES `publishers.php` / `tunes.php`, which pass the WHOLE
 * `$_POST` array into a shared validator (`publisherAdminValidateFields()`
 * / `tuneAdminValidateFields()` in `includes/publisher_admin.php` /
 * `includes/tune_admin.php`) that reads `$in['slug']` itself. A guard that
 * only ever found 4 of the 6 real pages would have been confidently wrong
 * from the day it shipped. Fixed by adding a SECOND, generic derivation:
 * find every `IDENTIFIER($_POST)` call site in the manage tree, resolve
 * `IDENTIFIER`'s definition (a top-level `function` OR a `$IDENTIFIER =
 * (static) function` closure) anywhere under `appWeb/public_html/`, and
 * check whether that definition's body reads a `slug` key. The two
 * derivations are UNIONed — this is not a hardcoded function-name list
 * (a THIRD page delegating to some other new validator function is
 * picked up automatically), and both directions are mutation-proven
 * below.
 *
 * MUTATION-PROVEN (rule #34), each broken on purpose, confirmed red,
 * restored:
 *   - Re-inlined `tunes.php`'s create-form slug `<input>` (undid the
 *     partial call) -> RED, check (1): "raw <input name=\"slug\"> markup
 *     outside the partial".
 *   - Stripped `name="slug"` from the partial's own `<input>` -> RED,
 *     check (2): "the partial's own <input> no longer carries
 *     name=\"slug\"".
 *   Both restored -> green.
 *
 *   php tests/php/test-slug-field-partial.php
 *
 * Exit status 0 = clean, 1 = at least one wiring drift.
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';
$manageDir = $pub . '/manage';
$partialRel = 'appWeb/public_html/manage/includes/slug-field.php';
$partialAbs = $repoRoot . '/' . $partialRel;

$failures = [];
function slugFieldFail(array &$failures, string $msg): void
{
    $failures[] = $msg;
}

/**
 * Strip PHP block + line comments AND HTML comments so a doc-block that
 * legitimately DESCRIBES a banned pattern (this very file, the partial's
 * own doc-block, which names `<input name="slug">` in prose) never
 * false-positives a scan — mirrors `test-org-logo-surfaces.php`'s /
 * `test-component-json-guard.php`'s identical rationale.
 */
function slugFieldStripComments(string $src): string
{
    // PHP/JS block comments -> blank lines (keeps line numbers stable for
    // any future line-numbered diagnostics).
    $src = (string)preg_replace_callback('#/\*.*?\*/#s', static fn(array $m) => str_repeat("\n", substr_count($m[0], "\n")), $src);
    // PHP/JS line comments.
    $src = (string)preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
    // HTML comments (the `<!-- Edit Modal -->` style headers throughout
    // these pages could otherwise mention "slug" in prose).
    $src = (string)preg_replace('#<!--.*?-->#s', '', $src);
    return $src;
}

/** Recursively list every .php file under $dir. */
function slugFieldWalkPhp(string $dir): array
{
    $acc = [];
    if (!is_dir($dir)) {
        return $acc;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $acc[] = $file->getPathname();
        }
    }
    sort($acc);
    return $acc;
}

if (!is_dir($manageDir)) {
    fwrite(STDERR, "FATAL: $manageDir not found\n");
    exit(1);
}
if (!is_file($partialAbs)) {
    fwrite(STDERR, "FATAL: $partialAbs not found — has the #1870 partial moved?\n");
    exit(1);
}

$manageFiles = slugFieldWalkPhp($manageDir);
if (count($manageFiles) < 20) {
    // Anti-under-report floor (rule #34): the manage tree has well over
    // 100 PHP files today; a walk that finds only a handful means the
    // iterator broke, not that the tree shrank.
    fwrite(STDERR, 'FATAL: only ' . count($manageFiles) . " file(s) walked under $manageDir — tree walk under-read.\n");
    exit(1);
}

/* =============================================================================
 * CHECK (1) — zero raw `<input … name="slug"` markup anywhere under
 * manage/ except inside the partial itself.
 * ============================================================================= */

$rawInputPattern = '/<input\b[^>]*\bname\s*=\s*["\']slug["\']/i';
$rawInputSites    = [];

foreach ($manageFiles as $path) {
    $rel = 'appWeb/public_html/' . substr($path, strlen($pub) + 1);
    if ($rel === $partialRel) {
        continue; // the partial is the one legitimate owner of this markup
    }
    $code = slugFieldStripComments((string)file_get_contents($path));
    if (preg_match($rawInputPattern, $code)) {
        $rawInputSites[] = $rel;
    }
}

if ($rawInputSites) {
    foreach ($rawInputSites as $rel) {
        slugFieldFail($failures, "$rel contains a raw <input name=\"slug\"> markup outside the shared partial ($partialRel) — route it through ihymns_slug_advanced_field() instead of pasting a 12th collapse block.");
    }
}

/* =============================================================================
 * CHECK (2) — the partial's own <input> still exists AND still sits
 * inside a <details> element.
 * ============================================================================= */

/* Comment-stripped: the partial's OWN doc-block names `<input name="slug">`
   in prose (describing what the function renders) — scanning the raw
   source would match that mention instead of the real code, exactly the
   "comment mentions the banned pattern" landmine rule #34/A.2 warns about
   (the editor2.php:382 case). Stripping first means only the executable
   string-literal concatenation below is ever inspected. */
$partialCode = slugFieldStripComments((string)file_get_contents($partialAbs));
if (!preg_match($rawInputPattern, $partialCode)) {
    slugFieldFail($failures, "$partialRel no longer carries name=\"slug\" on its <input> — the shared field itself is broken.");
} elseif (!preg_match('/<details\b[^>]*class\s*=\s*["\']slug-advanced["\'][^>]*>.*?<input\b[^>]*\bname\s*=\s*["\']slug["\'].*?<\/details>/is', $partialCode)) {
    slugFieldFail($failures, "$partialRel's <input name=\"slug\"> is no longer inside a <details class=\"slug-advanced\">…</details> wrapper — it would render as an always-visible box again.");
}

/* =============================================================================
 * CHECK (3) — every manage page that ACCEPTS a posted `slug` either calls
 * the partial or is a recorded non-form consumer. Union of two independent
 * derivations (see the file doc-block's "DERIVATION FINGERPRINT" note).
 * ============================================================================= */

$slugAcceptingPages = []; // rel path => true

// --- Derivation A: direct `$_POST['slug']` reads. ---
$directPattern = '/\$_POST\s*\[\s*[\'"]slug[\'"]\s*\]/';
foreach ($manageFiles as $path) {
    $rel = 'appWeb/public_html/' . substr($path, strlen($pub) + 1);
    if ($rel === $partialRel) {
        continue; // the partial reads no POST data itself
    }
    $code = slugFieldStripComments((string)file_get_contents($path));
    if (preg_match($directPattern, $code)) {
        $slugAcceptingPages[$rel] = true;
    }
}

// --- Derivation B: `IDENTIFIER($_POST)` call sites whose IDENTIFIER's
// definition (function OR closure, anywhere under appWeb/public_html/)
// reads a `slug` key from its incoming array. ---
$allTreeFiles = slugFieldWalkPhp($pub);
if (count($allTreeFiles) < 100) {
    fwrite(STDERR, 'FATAL: only ' . count($allTreeFiles) . " file(s) walked under $pub — tree walk under-read.\n");
    exit(1);
}

/** Cache of function/closure name -> ['file' => rel, 'body' => string]. */
$defCache = [];

/**
 * Locate IDENTIFIER's definition anywhere under the tree and return its
 * body text (a bounded window — generous per rule #34's "test-editor-
 * api2-contract.php needed 120 -> 300 chars" lesson — real validator
 * functions here run 30-60 lines). Returns null if not found.
 */
function slugFieldResolveDef(string $identifier, array $allTreeFiles, string $pub, array &$defCache): ?array
{
    if (array_key_exists($identifier, $defCache)) {
        return $defCache[$identifier];
    }
    $needleFn      = 'function ' . $identifier . '(';
    $needleClosure = '$' . $identifier . ' =';
    foreach ($allTreeFiles as $path) {
        $raw = (string)file_get_contents($path);
        $pos = strpos($raw, $needleFn);
        $isClosure = false;
        if ($pos === false) {
            $pos = strpos($raw, $needleClosure);
            $isClosure = true;
        }
        if ($pos === false) {
            continue;
        }
        if ($isClosure) {
            // Confirm it's actually `= function` / `= static function`
            // shortly after the `$name =` (skip incidental matches like
            // `$name = 5;` for an unrelated variable of the same name).
            $after = substr($raw, $pos, 60);
            if (!preg_match('/=\s*(static\s+)?function\s*\(/', $after)) {
                continue;
            }
        }
        $body = substr($raw, $pos, 6000); // generous bounded window
        $rel  = 'appWeb/public_html/' . substr($path, strlen($pub) + 1);
        $result = ['file' => $rel, 'body' => $body];
        $defCache[$identifier] = $result;
        return $result;
    }
    $defCache[$identifier] = null;
    return null;
}

$callPattern = '/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(\s*\$_POST\s*\)/';
$slugKeyPattern = '/\[\s*[\'"]slug[\'"]\s*\]/';

foreach ($manageFiles as $path) {
    $rel = 'appWeb/public_html/' . substr($path, strlen($pub) + 1);
    if ($rel === $partialRel) {
        continue;
    }
    $code = slugFieldStripComments((string)file_get_contents($path));
    if (!preg_match_all($callPattern, $code, $matches)) {
        continue;
    }
    foreach (array_unique($matches[1]) as $identifier) {
        $def = slugFieldResolveDef($identifier, $allTreeFiles, $pub, $defCache);
        if ($def === null) {
            continue;
        }
        if (preg_match($slugKeyPattern, $def['body'])) {
            $slugAcceptingPages[$rel] = true;
        }
    }
}

// Anti-under-report floor: #1870's own inventory names exactly 6 pages
// that accept a posted slug today. A derivation that finds fewer than 6
// has regressed (a regex anchor moved); more than 6 is fine (a legitimate
// new slug-bearing entity) so this is a floor, not an equality check.
$SLUG_PAGE_FLOOR = 6;
if (count($slugAcceptingPages) < $SLUG_PAGE_FLOOR) {
    slugFieldFail($failures, sprintf(
        'Derived only %d manage page(s) that accept a posted slug (< floor of %d) — the derivation regex may have stopped matching. Found: %s',
        count($slugAcceptingPages),
        $SLUG_PAGE_FLOOR,
        implode(', ', array_keys($slugAcceptingPages)) ?: '(none)'
    ));
}

// Known non-form consumers: none today — every page the derivation can
// currently find IS one of the 6 real forms and already calls the
// partial. Kept as a named, empty allowlist (not omitted) so a future
// genuine non-form consumer (e.g. a JSON API endpoint that also happens
// to read $_POST['slug']) has an obvious place to be added, WITH a
// comment saying why it's exempt — never a silent skip.
$knownNonFormConsumers = [];

foreach ($slugAcceptingPages as $rel => $_) {
    if (in_array($rel, $knownNonFormConsumers, true)) {
        continue;
    }
    $code = slugFieldStripComments((string)file_get_contents($pub . '/' . substr($rel, strlen('appWeb/public_html/'))));
    if (!str_contains($code, 'ihymns_slug_advanced_field(')) {
        slugFieldFail($failures, "$rel accepts a posted 'slug' field but never calls ihymns_slug_advanced_field() — either wire it through the shared partial or add it to \$knownNonFormConsumers with a comment explaining why it's exempt.");
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " slug-field partial wiring smell(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

printf(
    "OK: slug-field partial wired correctly — %d manage file(s) scanned for raw markup, %d page(s) confirmed to accept a posted slug and all call ihymns_slug_advanced_field().\n",
    count($manageFiles),
    count($slugAcceptingPages)
);
exit(0);
