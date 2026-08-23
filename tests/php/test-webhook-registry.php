<?php

declare(strict_types=1);

/**
 * iHymns — Webhook event-registry coverage guard (#1909)
 *
 * ELI5
 * ----
 * Two things must always be true about webhook events: (1) every event we EMIT
 * somewhere in the code is a real, registered event type — never a typo the
 * registry doesn't know; and (2) every registered CONTENT event actually has at
 * least one place that emits it — never a dead entry nobody fires. Plus (3) the
 * admin page builds its event checkboxes FROM the registry, never a typed list.
 *
 * WHY IT IS DERIVED, NOT A HARDCODED LIST (rule #34)
 * ----------------------------------------------------
 * Both sides are read out of their own source at run time: the registry from
 * `includes/webhook_events.php`'s IHYMNS_WEBHOOK_EVENTS, the emit sites by
 * scanning every `webhookEmit*()` call in the tree. A hardcoded list here would
 * be a third copy of the registry with the exact drift problem rule #34 exists
 * to prevent. The emit-site scan bounds each call's window to the call STATEMENT
 * (up to its `;`), NOT a fixed char count: that still catches a type passed
 * inside a ternary (`… ? 'song.created' : 'song.updated'`) — which a same-line
 * scan would miss — while NOT spilling into an adjacent `logActivity('song.edit')`
 * on the next line (whose action keys look event-shaped but aren't ours). That
 * spill was this guard's own first-draft bug: it flagged correct code (the
 * rule-#34 "guard too blunt" trap), fixed here.
 *
 * Mutation-proven: emitting `webhookEmit('song.bogus', …)` fails check 1; deleting
 * the only emit site of a content type fails check 2; making the admin page type
 * its own event list fails check 3.
 *
 * Usage: php tests/php/test-webhook-registry.php
 * Exit 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/appWeb/public_html/includes/webhook_events.php';

$passed = 0; $failed = 0; $failures = [];
function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

/* ---- gather every PHP source file under the web root (excluding tests + the
   registry itself, which legitimately names every type) -------------------- */
$srcDir = $root . '/appWeb/public_html';
$files  = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if ($rel === 'appWeb/public_html/includes/webhook_events.php') { continue; }
        $files[$rel] = (string)file_get_contents($f->getPathname());
    }
}
ok('scanned a plausible number of source files (>= 50)', count($files) >= 50);

/* Registry facts. */
$allTypes      = array_keys(IHYMNS_WEBHOOK_EVENTS);
$contentTypes  = [];   /* non-platform types must each have an emit site */
$prefixes      = [];
foreach (IHYMNS_WEBHOOK_EVENTS as $type => [$label, $entity, $family]) {
    if ($family !== 'platform') { $contentTypes[] = $type; }
    $prefixes[substr($type, 0, (int)strpos($type, '.'))] = true;
}
$prefixRe = implode('|', array_map('preg_quote', array_keys($prefixes)));

/* ---- CHECK 1 — every emitted type literal is a registered type ------------
   For each webhookEmit / webhookEmitSongEvent / webhookEmitSongbookEvent call,
   bound the window to the call STATEMENT (match offset → the next ';', capped)
   and pull every event-type-shaped literal whose prefix is a known family
   prefix. Statement-bounding avoids spilling into an adjacent logActivity() on
   the next line while still spanning a multi-line ternary type argument. */
$emittedTypes = [];
foreach ($files as $rel => $src) {
    if (strpos($src, 'webhookEmit') === false) { continue; }
    if (preg_match_all('/webhookEmit(?:SongEvent|SongbookEvent)?\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$match, $off]) {
            $semi   = strpos($src, ';', $off);
            $len    = $semi === false ? 600 : min(600, $semi - $off);
            $window = substr($src, $off, $len);
            if (preg_match_all('/\'((?:' . $prefixRe . ')\.[a-z_]+)\'/', $window, $lm)) {
                foreach ($lm[1] as $lit) {
                    $emittedTypes[$lit][$rel] = true;
                }
            }
        }
    }
}
$unknownEmitted = array_values(array_diff(array_keys($emittedTypes), $allTypes));
ok('every emitted event-type literal is registered in IHYMNS_WEBHOOK_EVENTS',
    $unknownEmitted === []);
foreach ($unknownEmitted as $t) {
    echo "       emitted '{$t}' is NOT a registered event type (files: "
        . implode(', ', array_keys($emittedTypes[$t])) . ")\n";
}
ok('the scan found emitted types at all (sanity — >= 6 distinct)',
    count($emittedTypes) >= 6);

/* ---- CHECK 2 — every CONTENT (non-platform) type has an emit site ---------- */
$uncovered = [];
foreach ($contentTypes as $t) {
    if (!isset($emittedTypes[$t])) { $uncovered[] = $t; }
}
ok('every non-platform registered event type has at least one emit site ('
    . count($contentTypes) . ' content types)', $uncovered === []);
foreach ($uncovered as $t) {
    echo "       registered '{$t}' has NO webhookEmit* call anywhere\n";
}

/* ---- CHECK 3 — the admin page renders events FROM the registry ------------- */
$page = $srcDir . '/manage/webhooks.php';
if (is_file($page)) {
    $pageSrc = (string)file_get_contents($page);
    ok("manage/webhooks.php builds its event list from webhookEventFamilies() (not a typed list)",
        strpos($pageSrc, 'webhookEventFamilies(') !== false);
} else {
    /* The page is added in the same batch; once present, this check is enforced.
       Skipping-with-a-note (never silently) keeps the guard honest pre-page. */
    echo "  ℹ️  manage/webhooks.php not present yet — check 3 (renders-from-registry) skipped\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nThe webhook event registry (includes/webhook_events.php) is the ONE source\n";
    echo "of truth: add an event as a map line + an emit call; never emit an\n";
    echo "unregistered type, never leave a content type with no emitter, and never\n";
    echo "type the event list into the admin page.\n";
    exit(1);
}
echo "\nWebhook event registry, emit sites and admin page are all in lockstep.\n";
