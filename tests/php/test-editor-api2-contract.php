<?php

declare(strict_types=1);

/**
 * iHymns — v2 editor client <-> api2.php contract guard (#1677)
 *
 * ELI5
 * ----
 * The v2 editor's JavaScript asks the server to do things by name. This test
 * checks that every name it asks for is a name the server actually answers to,
 * and that its write requests carry the header the server insists on. Both are
 * things a human reading either file alone cannot see.
 *
 * WHY THIS EXISTS
 * ---------------
 * #1677: api2.php rejects every POST without `X-Requested-With: XMLHttpRequest`
 * (the #1307 same-origin CSRF gate). The v2 client sent that header on its GET
 * helper and on NEITHER of its two write helpers — so every mutation the v2
 * editor could perform returned 403, while browsing worked perfectly.
 *
 * Two properties of that bug are what this file is shaped around:
 *
 *  1. It is invisible from either side. api2.php is correct. api-client.js is
 *     plausible — it sends a CSRF token, just not the one that is checked. The
 *     defect exists only in the RELATION between them, which is the classic
 *     "two lists that must agree with nothing enforcing it" shape this codebase
 *     keeps rediscovering (event names, rate-limit pairs, entitlement maps,
 *     npm-vs-CI test lists). A comment is not a mechanism.
 *
 *  2. The gate's own comment names "editor.js ed2EnrichApi, which already sends
 *     it" — the V1 editor calling into api2. #1307 was verified against that
 *     caller. The v2 shell, the other consumer of the same endpoint, was never
 *     checked. So the failure was not carelessness; it was a correct check
 *     performed against an incomplete list of callers. Deriving BOTH sides from
 *     the tree is the only version of this test that could have caught it.
 *
 * WHAT IT ASSERTS
 *   (1) Both v2 write helpers send `X-Requested-With` — the direct #1677 guard.
 *   (2) Every action literal the client sends has a matching `case` in api2.php.
 *       A typo'd action is also invisible: it 400s at runtime with no CI signal.
 *
 * Neither side's list is written down here. Both are parsed out of the source,
 * so adding an endpoint or a client method needs no edit to this file — and
 * removing one cannot silently pass.
 *
 *   php tests/php/test-editor-api2-contract.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$root   = dirname(__DIR__, 2);
$editor = $root . '/appWeb/public_html/manage/editor';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  ❌ {$label}\n";
    }
}

echo "\n#1677 — v2 editor client <-> api2.php contract\n\n";

$clientSrc = (string)file_get_contents($editor . '/v2/api-client.js');
$apiSrc    = (string)file_get_contents($editor . '/api2.php');

/* Strip comments from the CLIENT before matching. This file's own doc-blocks
   necessarily quote `X-Requested-With` at length while explaining #1677, and a
   header named in prose is not a header sent on the wire — matching raw source
   would let the explanation satisfy the assertion it is explaining. */
$stripJs = static function (string $s): string {
    $s = preg_replace('#/\*[\s\S]*?\*/#', '', $s) ?? $s;
    return preg_replace('#(^|[^:])//.*$#m', '$1', $s) ?? $s;
};
$client = $stripJs($clientSrc);

/* ---- 1. both write helpers carry the header api2 actually checks ---------- */

/* Isolate each helper by brace-free heuristic: from its `async function NAME(`
   to the next `async function` (or EOF). Good enough and dependency-free —
   these are three short sibling helpers in one small module. */
$helperBody = static function (string $src, string $name): string {
    $start = strpos($src, 'async function ' . $name . '(');
    if ($start === false) { return ''; }
    $next = strpos($src, 'async function ', $start + 10);
    return substr($src, $start, $next === false ? strlen($src) : $next - $start);
};

foreach (['postJson', 'postForm'] as $fn) {
    $bodyText = $helperBody($client, $fn);
    check("v2 api-client {$fn}() exists", $bodyText !== '');
    check(
        "v2 api-client {$fn}() sends X-Requested-With — api2.php POSTs 403 without it (#1677)",
        $bodyText !== '' && str_contains($bodyText, 'X-Requested-With')
    );
}

/* ---------------------------------------------------------------------------
   THE ASSERTION ABOVE WAS NOT ENOUGH, AND ITS FAILURE IS THE POINT.

   `['postJson', 'postForm']` is a hardcoded two-name list inside ONE file. It
   went green for weeks while manage/editor/import2.php — a SECOND api2 POST
   client, with its own hand-written XHR and fetch — sent X-CSRF-Token and no
   X-Requested-With, so every v2 bulk import 403'd before any import logic ran
   (including the #1633 iHymns interchange format). The guard did not miss a
   subtlety; it was never looking at that file.

   That is rule #34 verbatim: derive the list from the tree, or the tick is not
   coverage. api2.php's own gate comment made the same mistake in prose — it
   names "editor.js ed2EnrichApi, which already sends it" as though enumerating
   the clients, and import2.php is simply absent from the sentence.

   So: find every file that POSTs to api2.php by SCANNING, and require each to
   send the header. A new POST client anywhere under manage/editor/ is covered
   the moment it exists.
   --------------------------------------------------------------------------- */

$api2PostClients = [];
$scanDir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($editor));
foreach ($scanDir as $entry) {
    if (!$entry->isFile()) { continue; }
    $path = $entry->getPathname();
    if (!preg_match('/\.(js|php)$/', $path)) { continue; }
    /* api2.php IS the server; save_song_core.php is included BY it. Neither is
       a browser client, and both legitimately mention the filename. */
    $base = basename($path);
    if ($base === 'api2.php' || $base === 'save_song_core.php') { continue; }

    $src = (string)file_get_contents($path);
    $noComments = $stripJs($src);

    /* A POST client = names api2.php ANYWHERE, and POSTs somewhere.
       Deliberately NOT a proximity match. The first version of this scan
       required `api2.php` within 400 chars of `method: 'POST'`, and found only
       import2.php — because v2/api-client.js builds its URL from
       `const ENDPOINT = '/manage/editor/api2.php'` (:17) and the literal never
       appears near the verb. Proximity is a heuristic about formatting; "this
       file talks to api2 and this file POSTs" is the actual property.

       The cost of the looser test is a file that mentions api2.php and happens
       to POST elsewhere getting checked too. That is a harmless extra
       assertion — and cheap next to missing a real client, which is the exact
       way this guard failed before. */
    $isPostClient =
        str_contains($noComments, 'api2.php')
        && (preg_match('#method\s*:\s*[\'"]POST[\'"]#i', $noComments)
            || preg_match('#\.open\(\s*[\'"]POST[\'"]#i', $noComments));

    if ($isPostClient) { $api2PostClients[] = $path; }
}

/* If the scan finds nothing, the regexes have rotted and every assertion below
   would vacuously pass — the "scanner that under-reports is worse than no
   scanner" failure. Fail loudly instead. */
check(
    'derived at least 2 api2.php POST clients from the tree (a scan finding none would pass vacuously)',
    count($api2PostClients) >= 2
);

foreach ($api2PostClients as $path) {
    $rel = str_replace($root . '/', '', $path);
    check(
        "{$rel} sends X-Requested-With — api2.php 403s every POST without it (#1677)",
        str_contains($stripJs((string)file_get_contents($path)), 'X-Requested-With')
    );
}

/* The server side of the same pair: assert the gate is still THERE. If a future
   change removes it, assertion (1) above would still pass while silently
   guarding nothing — so pin both ends, not just the client. */
check(
    'api2.php still gates POSTs on X-Requested-With (if this is removed, the client assertions above guard nothing)',
    /* Window is generous (300) because the 403 responder line is long — it
       carries the whole operator-facing error string. Tuned to 120 first, which
       failed on the real source: a guard's own regex is as capable of being
       subtly wrong as the code it guards, which is why every guard here gets
       mutation-tested rather than trusted because it went green. */
    (bool)preg_match('/HTTP_X_REQUESTED_WITH.{0,300}403/s', preg_replace('#/\*[\s\S]*?\*/#', '', $apiSrc) ?? $apiSrc)
);

/* ---- 2. every client action name is a real server case -------------------- */

/* Client side: the action is always the first argument to one of the three
   helpers, as a string literal. */
preg_match_all(
    "/(?:getJson|postJson|postForm)\s*\(\s*'([a-z0-9_]+)'/i",
    $client,
    $m
);
$clientActions = array_values(array_unique($m[1] ?? []));

/* Server side: `case 'name':` inside api2.php's action switch. Comments stripped
   so a case name discussed in a doc-block does not count as implemented. */
preg_match_all("/case\s+'([a-z0-9_]+)'\s*:/i", preg_replace('#/\*[\s\S]*?\*/#', '', $apiSrc) ?? $apiSrc, $m2);
$serverActions = array_values(array_unique($m2[1] ?? []));

check('parsed a plausible number of client actions (>= 20)', count($clientActions) >= 20);
check('parsed a plausible number of server cases (>= 20)', count($serverActions) >= 20);

$orphans = array_values(array_diff($clientActions, $serverActions));
check(
    'every action the v2 client calls has a matching case in api2.php ('
        . count($clientActions) . ' client actions checked)',
    $orphans === []
);
if ($orphans !== []) {
    foreach ($orphans as $o) {
        echo "       client calls '{$o}' — no `case '{$o}':` in api2.php (would 400 at runtime)\n";
    }
}

/* Deliberately NOT asserted in reverse: api2.php legitimately serves actions no
   v2 client method calls yet — the line-enrichment endpoints are the live
   example (#1627: v1's panel is currently their only UI consumer). Failing on
   server-only actions would punish exactly the parity work epic #1601 exists to
   do. Orphaned endpoints are tracked as issues, not as build failures. */

/* =============================================================================
 * 3. v1 -> v2 ACTION PARITY LEDGER (#1608)
 * =============================================================================
 *
 * ELI5
 * ----
 * Section 2 above checks "does every name the v2 CLIENT asks for exist on the
 * v2 SERVER". That is a different question from this one: "did every action
 * v1's server used to answer make it into v2's server, in SOME form". A v1
 * action nobody ports is invisible to section 2 (the client never asks for it,
 * so there is nothing to fail on) — which is exactly the shape #1608 was: five
 * whole actions (get_song_links / add_song_link / remove_song_link /
 * suggest_song_links / dismiss_song_link_suggestion), a working panel in v1,
 * ZERO trace in v2, and no test anywhere that could have said so.
 *
 * WHAT IT ASSERTS
 *   For EVERY action api.php's `switch ($action)` dispatches, exactly one of:
 *     (a) the SAME name exists as a `case` in api2.php's `switch ($action)`, or
 *     (b) it is in the RENAMED map below, and every one of its listed v2
 *         target(s) is verified (mechanically) to be a real api2.php case, or
 *     (c) it is in the RETIRED map below, and carries a non-empty issue
 *         citation + a non-empty one-line reason (verified mechanically).
 *   Anything matching none of the three is reported by name as an unexplained
 *   gap and fails the suite — the exact check that would have caught #1608 at
 *   the moment `manage/editor/index.php` flipped its redirect to v2-by-default.
 *
 * WHY A TYPED MAP HERE IS NOT THE rule #34 "HARDCODED LIST" ANTI-PATTERN
 * -----------------------------------------------------------------------
 * Rule #34 bans a guard whose CHECKED SET is typed by hand (the thing being
 * verified). Here the checked set — both action lists — is fully DERIVED via
 * dispatchParserCasesForSwitch(), the same token-walker section 2 above and
 * test-orphan-inventory.php / test-openapi-actions-exist.php already share
 * (never a second copy of the switch-walk, CLAUDE.md's modularity rule).
 * $RENAMED / $RETIRED are not the checked set — they are the DISPOSITION of
 * each derived item, which is a human decision no parser can discover ("was
 * get_song_links renamed, or silently dropped?" looks identical to a scanner).
 * The guard against this decision-data becoming a rubber stamp is that every
 * rename target is itself mechanically verified to exist, and every
 * retirement's citation is mechanically verified to be non-empty — an entry
 * that just says "it's fine, retired" with nothing else fails exactly as
 * loudly as one missing from both maps. This is the SAME shape as section 2's
 * `$api2PostClients` scan (derived set) feeding fixed, structural assertions.
 *
 * VERIFIED BEFORE FILING (per .claude/standing-tasks.md §2a's own warning that
 * "of 34 apparent v1->v2 API differences, 19 were renames and 8 collapsed into
 * one generic handler; only 6 were real"): every RENAMED/RETIRED entry below
 * was checked against the actual v2 source or an actual closed GitHub issue
 * before being classified — not assumed from the name alone. songbook_export
 * and load_songs both already had dedicated, INVESTIGATED parity issues
 * (#1607, #1610, both closed `completed`) reachable from Epic #1601; both are
 * cited verbatim rather than re-litigated here.
 *
 * MUTATION-TESTED (rule #34) — transcript in this commit's body:
 *   (a) comment out `case 'song_link_add':` in api2.php           -> RED
 *   (b) add a bogus `case 'zzz_probe':` to api.php's action switch -> RED
 *   (c) blank the 'why' on the songbook_export retirement entry    -> RED
 *   each reverted afterward -> GREEN.
 * ============================================================================= */

require_once __DIR__ . '/lib/dispatch_parser.php';

$v1DispatchFile = $editor . '/api.php';
$v2DispatchFile = $editor . '/api2.php';

echo "\n#1608 — v1 api.php -> v2 api2.php action parity ledger\n\n";

/* Self-skip once v1 is deleted (the eventual end-state of Epic #1601): this
   section exists to guard a MIGRATION IN PROGRESS, not to demand api.php
   exist forever. Without this, deleting api.php as #1601's last step would
   fail a test whose entire purpose was to unblock that deletion — the same
   "guard the correct end-state too" carve-out section 2's header describes
   for the parity work in general. */
if (!is_file($v1DispatchFile)) {
    echo "  (skipped — api.php no longer exists; v1 has been fully retired, #1601)\n";
} else {
    $v1Actions = dispatchParserCasesForSwitch($v1DispatchFile, '$action');
    $v2Actions = dispatchParserCasesForSwitch($v2DispatchFile, '$action');

    /* Vacuity gate FIRST (rule #34: "a scanner that under-reports is worse
       than no scanner"). If the token-walker ever silently derives nothing
       from either file, every assertion below would pass vacuously — so this
       must be provably able to fail, and it runs before anything else in this
       section trusts either list. */
    check('derived at least one v1 api.php action (vacuity check)', count($v1Actions) > 0);
    check('derived at least one v2 api2.php action (vacuity check)', count($v2Actions) > 0);
    check('parsed a plausible number of v1 actions (>= 20)', count($v1Actions) >= 20);
    check('parsed a plausible number of v2 actions (>= 20)', count($v2Actions) >= 20);

    $v2Set = array_flip($v2Actions);

    /* RENAMES — v1 action name => the v2 action name(s) that carry its
       capability forward. A multi-target entry means the v1 action's job was
       SPLIT across more than one v2 action (bulk_tag); a multi-SOURCE,
       one-target shape means several v1 actions collapsed into one generic
       v2 handler (the eight bulk_import_* formats -> import_file). Every
       target is mechanically verified against $v2Set below — this array
       supplies the CLAIM, not the proof. */
    $RENAMED = [
        /* #1608 (this branch, Block C, commit 8) — the five song-link /
           counterpart actions this parity ledger exists to guard. */
        'get_song_links'               => ['song_links'],
        'add_song_link'                => ['song_link_add'],
        'remove_song_link'             => ['song_link_remove'],
        'suggest_song_links'           => ['song_link_suggestions'],
        'dismiss_song_link_suggestion' => ['song_link_suggestion_dismiss'],

        /* Async ZIP import pipeline (#676 job tracking). */
        'bulk_import_zip'         => ['import_zip'],
        'bulk_import_status'      => ['import_zip_status'],
        'bulk_import_skipped_csv' => ['import_zip_skipped_csv'],

        /* Eight per-format single-file import actions collapsed into ONE
           generic handler (`import_file`, format=<name> in the request body)
           — verified against api2.php's own doc-block and $bodyFormats/match
           arms (#882's commit 6 wiring), not assumed from the name shape. */
        'bulk_import_chordpro'    => ['import_file'],
        'bulk_import_easyworship' => ['import_file'],
        'bulk_import_freeshow'    => ['import_file'],
        'bulk_import_openlp'      => ['import_file'],
        'bulk_import_pptx'        => ['import_file'],
        'bulk_import_pro6'        => ['import_file'],
        'bulk_import_proclaim'    => ['import_file'],
        'bulk_import_videopsalm'  => ['import_file'],

        /* v1's single `bulk_tag` action carried BOTH add[] and remove[]
           arrays; v2 split it into two granular actions — bulk_tag_attach
           landed first, bulk_tag_detach followed in 33f583e1 once #1628 item
           3 flagged the add-only gap. Both must exist for this to count as
           parity (checked below, not assumed). */
        'bulk_tag' => ['bulk_tag_attach', 'bulk_tag_detach'],

        'list_revisions'   => ['revision_list'],
        'restore_revision' => ['revision_restore'],

        'song_media_list'    => ['media_list'],
        'song_media_upload'  => ['media_upload'],
        'song_media_update'  => ['media_update'],
        'song_media_delete'  => ['media_delete'],
        'song_media_reorder' => ['media_reorder'],

        'song_tags' => ['tag_list'],

        /* v1's OWN dispatcher already retired the whole-corpus `save` action
           behind a 410 stub whose body names `save_song` as the replacement
           ("the whole-corpus save endpoint has been retired (#1016). Use
           save_song for per-record writes.") — treated as a rename to its
           own documented successor rather than a second retirement entry. */
        'save' => ['save_song'],
    ];

    /* RETIREMENTS — v1 actions with NO v2 replacement at all, each an
       INVESTIGATED, RECORDED product decision, not a shrug. Every entry MUST
       carry both a real-looking issue citation and a non-empty one-line
       reason (enforced below) — an entry that is just "retired" with nothing
       else fails exactly as loudly as an action missing from both maps,
       which is what stops this from decaying into a rubber stamp over time. */
    $RETIRED = [
        'songbook_export' => [
            'issue' => '#1607',
            'why'   => 'Owner decision (#1607, closed completed): the v2 editor stays '
                     . 'single-song export by design; whole-songbook export lives on the '
                     . 'public /songbooks list and /songbook/<ABBR> page instead (landed 9078f761).',
        ],
        'load_songs' => [
            'issue' => '#1610',
            'why'   => 'Confirmed (#1610, closed completed): v2\'s sidebar lists songs via '
                     . 'the slim getSongsSlimIndex() path (load_index) — a narrower, DB-direct '
                     . 'read (rule #17), not v1\'s whole-corpus batch-full-record fetch, which '
                     . 'existed only to feed a client-side bulk-edit shape v2 does server-side.',
        ],
    ];

    $unexplained = [];
    foreach ($v1Actions as $v1Action) {
        if (isset($v2Set[$v1Action])) { continue; }     /* verbatim match — no ledger entry needed */
        if (isset($RENAMED[$v1Action])) { continue; }   /* target(s) verified below */
        if (isset($RETIRED[$v1Action])) { continue; }   /* citation verified below */
        $unexplained[] = $v1Action;
    }
    check(
        'every v1 api.php action is present in v2 verbatim, renamed (target verified), or a cited '
            . 'retirement (' . count($v1Actions) . ' v1 actions checked)',
        $unexplained === []
    );
    if ($unexplained !== []) {
        foreach ($unexplained as $u) {
            echo "       v1 action '{$u}' has no v2 case, no \$RENAMED entry and no \$RETIRED entry"
                . " — an UNEXPLAINED GAP (this is exactly how #1608 hid)\n";
        }
    }

    /* Every RENAME target must be a REAL api2.php case. This is the
       mechanical proof behind the $RENAMED claim above — remove or typo a
       target here and this is what goes red, not the summary assertion. */
    foreach ($RENAMED as $v1Action => $targets) {
        foreach ($targets as $target) {
            check(
                "renamed action '{$v1Action}' -> '{$target}' exists as a real api2.php case",
                isset($v2Set[$target])
            );
        }
    }

    /* Every RETIREMENT must cite a real-shaped issue number and a non-empty
       reason. This is what stops a future `'some_action' => []` from being a
       silent rubber stamp — the citation-enforcement rule the plan (and
       .claude/standing-tasks.md §2a) both call for explicitly. */
    foreach ($RETIRED as $v1Action => $info) {
        check(
            "retired action '{$v1Action}' cites an issue number",
            isset($info['issue']) && preg_match('/^#\d+$/', (string)$info['issue']) === 1
        );
        check(
            "retired action '{$v1Action}' carries a non-empty reason",
            isset($info['why']) && trim((string)$info['why']) !== ''
        );
    }

    /* Reverse direction deliberately NOT asserted here either, for the same
       reason section 2 doesn't: api2.php legitimately serves actions with no
       v1 counterpart at all (credit_upsert's structured shape, the
       line_translation_upsert / line_annotation_upsert pair,
       arrangement_update, …) — v2-only growth is the point of the cutover,
       not a gap to fail on. */
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nThe v2 editor and api2.php must agree on BOTH the same-origin header and\n";
    echo "the action vocabulary. Fix the CLIENT — never weaken api2's POST gate,\n";
    echo "which is rule #29's named regression class.\n";
    exit(1);
}
echo "\nAll v2 editor contract assertions passed.\n";
