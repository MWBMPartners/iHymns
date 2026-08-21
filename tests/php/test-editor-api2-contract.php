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

    /* A POST client = REFERENCES the api2 endpoint in any spelling, and POSTs.
       Deliberately NOT a proximity match, and deliberately NOT the literal
       filename.

       THIS NEEDLE HAS NOW BEEN WRONG TWICE, in two different ways, and both
       failures were silent under-coverage — the mode rule #34 calls worse than
       no scanner:

         v1: required `api2.php` within 400 chars of `method: 'POST'`. Found
             ONLY import2.php, because v2/api-client.js builds its URL from
             `const ENDPOINT = '/manage/editor/api2.php'` and the literal never
             appears near the verb. Proximity is a fact about formatting, not
             about behaviour.

         v2: dropped proximity but kept the literal `api2.php`. Still missed
             manage/editor/editor.js, which POSTs to
             `window.IHYMNS_EDITOR_API2 || '/manage/editor/api2'` — EXTENSIONLESS
             (:1409, :5122). Its only `api2.php` occurrences are inside comments,
             which this scan strips, so the file scored zero hits.

       The v2 mutation test did not catch that, and it is worth understanding
       why: it added a NEW file containing the literal `api2.php`. That proves
       the scan finds files matching the needle — it says nothing about whether
       the needle matches every real client. A mutation built from the same
       assumption as the code cannot falsify that assumption.

       So match `manage/editor/api2` and let the optional `.php` fall where it
       may. The endpoint's PATH is the stable thing; the extension is not. */
    $isPostClient =
        preg_match('#manage/editor/api2(\.php)?#', $noComments)
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

/* =============================================================================
 * 4. revision_get — the before-snapshot resolution LADDER (#1628 item 4)
 * =============================================================================
 *
 * ELI5
 * ----
 * revision_get answers "what did the song look like right before this edit,
 * and right after it". The "right before" half isn't always stored directly —
 * the server has to try a couple of things, in a specific order, before it
 * gives up and says "nothing recorded". This section checks that the order
 * really is what the doc-block claims.
 *
 * WHY A SOURCE-TEXT CHECK, AND WHY A GENEROUS WINDOW (not the whole file)
 * ------------------------------------------------------------------------
 * There is no live DB in this test run to exercise the three rungs
 * behaviourally (PreviousData present / PreviousData absent with an older
 * row / no older row at all), so this — like section 1's X-Requested-With
 * check — verifies the SHAPE of the handler source instead: the three
 * `beforeSource` vocabulary strings ('previousData' | 'priorRevision' |
 * 'none') must all appear inside the `revision_get` case, in that source
 * order. Isolating the case body (rather than grepping the whole file) stops
 * an unrelated case elsewhere from making this pass vacuously; requiring
 * >= 300 chars is this test's own recorded lesson (line ~199 above) that a
 * narrow window silently misses real source.
 *
 * WHY 'none' MUST BE THE STRING'S *LAST* OCCURRENCE, NOT ITS FIRST
 * -------------------------------------------------------------------
 * A naive implementation could default `$beforeSource` to the literal
 * 'none' up front and only overwrite it on success — which would put the
 * word 'none' EARLIEST in the source, ahead of 'previousData', even though
 * it is logically the LAST rung. That shape would fail this ordering
 * assertion on otherwise-correct code (the exact rule #34 trap: "a guard
 * that fails on correct code gets weakened or deleted rather than fixed").
 * The shipped handler avoids this by keeping `$beforeSource` as PHP `null`
 * through both rungs and only writing the literal 'none' once, via
 * `?? 'none'`, at the point the response is built — so 'none' is both
 * textually and logically last. This assertion is written to match THAT
 * shape; if a future edit needs an early 'none' default, this assertion (not
 * the code) should be revisited.
 *
 * MUTATION-TESTED (rule #34) — transcript in this commit's body:
 *   invert the ladder (assign 'priorRevision' before 'previousData' in
 *   source order) -> the ordering assertion below goes RED. Reverted -> GREEN.
 * ============================================================================= */

echo "\n#1628 item 4 — revision_get before-snapshot resolution ladder\n\n";

/* Isolate the `case 'revision_get': { ... }` block, comments stripped first
   so the literal vocabulary words appearing in this case's own doc-block
   prose (which lists all three, in order, as documentation) cannot satisfy
   the assertion in place of the actual resolution code. */
$apiNoComments = preg_replace('#/\*[\s\S]*?\*/#', '', $apiSrc) ?? $apiSrc;
$caseStart = strpos($apiNoComments, "case 'revision_get':");
$revisionGetBody = '';
if ($caseStart !== false) {
    if (preg_match('/\n    case \'/', $apiNoComments, $mEnd, PREG_OFFSET_CAPTURE, $caseStart + 1)) {
        $revisionGetBody = substr($apiNoComments, $caseStart, $mEnd[0][1] - $caseStart);
    } else {
        $revisionGetBody = substr($apiNoComments, $caseStart);
    }
}

check("api2.php has a 'revision_get' case", $revisionGetBody !== '');
check(
    "revision_get's isolated case body is a real handler, not a stub (>= 300 chars — "
        . 'this test\'s own recorded lesson about generous regex windows)',
    strlen($revisionGetBody) >= 300
);

$posPreviousData  = strpos($revisionGetBody, "'previousData'");
$posPriorRevision = strpos($revisionGetBody, "'priorRevision'");
$posNone          = strrpos($revisionGetBody, "'none'");   // LAST occurrence — see doc-block above
check(
    "revision_get's handler names all three beforeSource rungs: 'previousData', 'priorRevision', 'none'",
    $posPreviousData !== false && $posPriorRevision !== false && $posNone !== false
);
check(
    'revision_get resolves the before-snapshot ladder in source order: '
        . "previousData -> priorRevision -> none (rule #20 vocabulary discipline; rule #35 — "
        . 'the server resolves this chain ONCE, the client never re-implements it)',
    $posPreviousData !== false && $posPriorRevision !== false && $posNone !== false
        && $posPreviousData < $posPriorRevision && $posPriorRevision < $posNone
);

/* The client side of the same pair: getRevision() exists beside listRevisions
   and asks for the 'revision_get' action (already proven to be a real
   api2.php case by section 2's client<->server action check above — this
   just pins the method's existence and its action-name literal, the same
   shape section 1 uses for the write helpers). */
check(
    "v2 api-client exposes getRevision() calling the 'revision_get' action",
    (bool)preg_match(
        "/getRevision\s*:\s*\([^)]*\)\s*=>\s*getJson\(\s*'revision_get'/",
        $client
    )
);

/* =============================================================================
 * 5. bulk_move / bulk_delete — the per-song funnel discipline (Wave 4 C4, #1628 item 3)
 * =============================================================================
 *
 * ELI5
 * ----
 * Moving or deleting many songs at once must go through the SAME per-song
 * machinery the single-song actions already use — a bulk action that grew its
 * own copy of "how to move/delete a song" would drift the moment either copy
 * changed underneath it. This pins that the two bulk handlers CALL the shared
 * cores, and never reach for the raw SQL those cores exist to replace.
 *
 * WHY A SOURCE-TEXT CHECK (no live DB in this container, same reasoning as
 * section 4). Each case body is isolated the SAME way section 4 isolates
 * revision_get — from `case '<name>':` to the next top-level `case '` — so an
 * unrelated case elsewhere in the file cannot satisfy this vacuously.
 *
 * `tests/php/test-song-relocate-funnels.php` already asserts, TREE-WIDE, that
 * no per-song `SongbookAbbr` write anywhere skips `songRelocate()`. That guard
 * was verified LIVE, during this commit's own implementation, against a
 * deliberately-wrong `bulk_move` draft that wrote `SongbookAbbr` directly —
 * CHECK 1 named the bad site and failed the build (transcript in the commit
 * body). This section is a narrower, faster pin on the same two facts, scoped
 * to just these two actions, so a regression here fails fast and by name
 * without needing the full tree walk.
 *
 * MUTATION-TESTED (rule #34) — transcript in this commit's body:
 *   rename the `songRelocate(` call inside `case 'bulk_move'` to
 *   `songRelocateXXX(`                                             -> RED
 *   rename the `songSoftDelete(` call inside `case 'bulk_delete'` to
 *   `songSoftDeleteXXX(`                                            -> RED
 *   both reverted afterward                                         -> GREEN.
 * ============================================================================= */

echo "\nWave 4 C4 — bulk_move / bulk_delete funnel discipline (#1628 item 3)\n\n";

/** Isolate `case '$caseName':` from a comment-stripped switch-dispatch source,
 *  up to the next top-level `case '`. Generalises section 4's own inline
 *  isolation (there specific to revision_get) so this section can reuse it
 *  for two more case names without a third copy of the same four lines. */
$isolateCase = static function (string $noCommentsSrc, string $caseName): string {
    $start = strpos($noCommentsSrc, "case '{$caseName}':");
    if ($start === false) { return ''; }
    if (preg_match('/\n    case \'/', $noCommentsSrc, $mEnd, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($noCommentsSrc, $start, $mEnd[0][1] - $start);
    }
    return substr($noCommentsSrc, $start);
};

$bulkMoveBody = $isolateCase($apiNoComments, 'bulk_move');
check("api2.php has a 'bulk_move' case", $bulkMoveBody !== '');
check(
    'bulk_move delegates to songRelocate() — never a direct SongbookAbbr write (rule #27; '
        . 'test-song-relocate-funnels.php CHECK 1 is the tree-wide version of this same fact)',
    $bulkMoveBody !== '' && (bool)preg_match('/\bsongRelocate\s*\(/', $bulkMoveBody)
);
check(
    'bulk_move contains no direct `UPDATE tblSongs … SongbookAbbr` write',
    $bulkMoveBody !== '' && !preg_match('/UPDATE\s+`?tblSongs`?[^;]{0,200}SongbookAbbr/is', $bulkMoveBody)
);

$bulkDeleteBody = $isolateCase($apiNoComments, 'bulk_delete');
check("api2.php has a 'bulk_delete' case", $bulkDeleteBody !== '');
check(
    'bulk_delete delegates to songSoftDelete() — never a raw hard DELETE (#1694)',
    $bulkDeleteBody !== '' && (bool)preg_match('/\bsongSoftDelete\s*\(/', $bulkDeleteBody)
);
check(
    'bulk_delete contains no `DELETE FROM tblSongs`',
    $bulkDeleteBody !== '' && !preg_match('/DELETE\s+FROM\s+`?tblSongs`?/i', $bulkDeleteBody)
);

/* =============================================================================
 * 6. bulk move/delete/export UI plumbing (Wave 4 C5, #1628 item 3)
 * =============================================================================
 *
 * ELI5
 * ----
 * The bulk buttons in the editor toolbar have to call the RIGHT client
 * methods, and the export button has to reuse the SAME format serializers the
 * single-song Export menu uses rather than re-inventing a bulk fetch. This
 * checks both, plus the one thing that would be silently dangerous to skip:
 * after a bulk MOVE, the sidebar's selection and its cached song list are
 * both stale (every moved SongId was just re-keyed, #1679 option B) — a
 * handler that forgot to refresh would leave the curator staring at dead ids.
 *
 * WHY THE HANDLERS ARE ISOLATED BY DOM ID, NOT `case '...'`
 * ----------------------------------------------------------
 * editor2.php's inline module is not a PHP switch — its handlers are wired in
 * a fixed, known source ORDER (this file's own), so each is isolated from its
 * `byId('<id>').addEventListener(` start to the NEXT handler's identical
 * marker (the export handler's end is the resizable-sidebar IIFE that follows
 * it in source). Comments are stripped FIRST (the same $stripJs section 1
 * already defines) so a doc-comment merely NAMING `sidebar.refresh()` cannot
 * satisfy an assertion about the CODE calling it — the exact prose-satisfies-
 * a-code-check trap CLAUDE.md rule #34 / this file's section 1 already guards
 * against for the X-Requested-With header.
 *
 * MUTATION-TESTED (rule #34) — transcript in this commit's body:
 *   delete the `sidebar.refresh()` line from the bulk-move handler -> RED
 *   reverted afterward                                              -> GREEN.
 * ============================================================================= */

echo "\nWave 4 C5 — bulk move/delete/export UI plumbing (#1628 item 3)\n\n";

check(
    "v2 api-client exposes bulkMove() calling the 'bulk_move' action",
    (bool)preg_match("/bulkMove\s*:\s*\([^)]*\)\s*=>\s*postJson\(\s*'bulk_move'/", $client)
);
check(
    "v2 api-client exposes bulkDelete() calling the 'bulk_delete' action",
    (bool)preg_match("/bulkDelete\s*:\s*\([^)]*\)\s*=>\s*postJson\(\s*'bulk_delete'/", $client)
);

$editor2Src         = (string)file_get_contents($editor . '/editor2.php');
$editor2NoComments  = $stripJs($editor2Src);

/** Isolate one inline-module click handler by its DOM id, from
 *  `byId('$startId').addEventListener(` to $endNeedle (a literal that must
 *  survive comment-stripping — i.e. real code, not prose naming the next
 *  handler). Mirrors $isolateCase above, applied to a fixed source order
 *  instead of a switch. */
$isolateHandler = static function (string $src, string $startId, string $endNeedle): string {
    $start = strpos($src, "byId('{$startId}').addEventListener(");
    if ($start === false) { return ''; }
    $end = $endNeedle === '' ? false : strpos($src, $endNeedle, $start + 10);
    return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
};

$bulkMoveGoBlock   = $isolateHandler($editor2NoComments, 'v2-bulk-move-go', "byId('v2-bulk-delete').addEventListener(");
$bulkDeleteBlock   = $isolateHandler($editor2NoComments, 'v2-bulk-delete', "byId('v2-bulk-export').addEventListener(");
$bulkExportGoBlock = $isolateHandler($editor2NoComments, 'v2-bulk-export-go', '(function () {');

check(
    "editor2.php wires a 'v2-bulk-move-go' handler calling editorApi.bulkMove()",
    $bulkMoveGoBlock !== '' && str_contains($bulkMoveGoBlock, 'editorApi.bulkMove(')
);
check(
    "editor2.php's bulk-move handler clears the selection AND refreshes the sidebar's slim index "
        . 'UNCONDITIONALLY — option B re-keys every SongId, so a stale sidebar after a move is a '
        . 'silent data hazard, not a cosmetic one (A2)',
    $bulkMoveGoBlock !== '' && str_contains($bulkMoveGoBlock, 'sidebar.clearSelection()')
        && str_contains($bulkMoveGoBlock, 'sidebar.refresh(')
);

check(
    "editor2.php wires a 'v2-bulk-delete' handler calling editorApi.bulkDelete()",
    $bulkDeleteBlock !== '' && str_contains($bulkDeleteBlock, 'editorApi.bulkDelete(')
);
check(
    "editor2.php's bulk-delete handler also clears the selection and refreshes the sidebar's slim index",
    $bulkDeleteBlock !== '' && str_contains($bulkDeleteBlock, 'sidebar.clearSelection()')
        && str_contains($bulkDeleteBlock, 'sidebar.refresh(')
);

check(
    "editor2.php's bulk-export handler reuses window.iHymnsFormatExport's exportSongbook() — never a second serializer",
    $bulkExportGoBlock !== '' && str_contains($bulkExportGoBlock, 'exportSongbook')
);
check(
    "editor2.php's bulk-export handler contains no whole-corpus/bulk-fetch action "
        . "('load_songs' — v1's retired _loadSongsFull() anti-pattern, rule #17)",
    $bulkExportGoBlock !== '' && !str_contains($bulkExportGoBlock, 'load_songs')
);

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
