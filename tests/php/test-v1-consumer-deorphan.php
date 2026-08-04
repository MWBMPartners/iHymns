<?php

declare(strict_types=1);

/**
 * iHymns — v1 editor api.php consumer de-orphan guard (#1629)
 *
 * ELI5: five things secretly depend on the old `manage/editor/api.php` — a
 * typeahead, a songbooks export button, a stale-job poller, a "go fix it in
 * the editor" error message, and (added by #1678) the v2 editor's OWN
 * EasyWorship export button. This test makes sure all five were actually
 * moved off v1 before anyone deletes it, by reading the real source files
 * and checking the after-state, not by trusting a changelog entry.
 *
 * WHY THIS EXISTS
 * ----------------
 * Epic #1601 is retiring `manage/editor/api.php` in favour of the v2
 * editor API (`manage/editor/api2.php`). Four consumers of v1 identified by
 * the original #1629 audit were NOT part of the editor UI and would have
 * broken invisibly the day v1 disappears:
 *
 *   1. Content Restrictions' name-first picker
 *      (manage/includes/renderEntityPicker.js) called v1's user_search /
 *      org_search. Neither was ever an editor feature (zero call sites in
 *      editor.js/index.php/editor2.php/v2/*) — this corrects sibling
 *      issue #1609, which had assumed otherwise. Fix: port both cases
 *      into api2.php verbatim (same query/bind_param), repoint the picker.
 *   2. The admin Songbooks bundle-export button called v1's
 *      songbook_export. Fix: repoint to the public, gated equivalent at
 *      /api?action=songbook_export (api.php).
 *   3. js/modules/bulk-import-progress.js (loaded globally on every admin
 *      page via admin-footer.php) falls back to v1's
 *      ?action=bulk_import_status for a stale localStorage job with no
 *      pollUrl. Fix: repoint to api2.php's ?action=import_zip_status —
 *      a pure URL swap, same envelope shape.
 *   4. /manage/duplicate-songs could not remove a tblSongLinks row —
 *      only the editor's (retiring) link CRUD could — so its own 409
 *      conflict message told curators to go "unlink one in the editor
 *      first", a dead end once v1 is gone. Fix: add an `unlink` action to
 *      duplicate-songs.php itself (edit_songs-gated, validateCsrfRequest-
 *      CSRF'd, same as its sibling `link`/`dismiss` actions) and repoint
 *      the message at it.
 *
 *   5. (#1678 — the ONE the #1629 audit above MISSED) the v2 editor's own
 *      EXPORT MENU (manage/editor/v2/export.js) called v1's
 *      `?action=easyworship_export` for its "EasyWorship (beta)" item —
 *      invisible to the #1629 audit because it lives INSIDE the v2 editor
 *      surface the audit assumed was already v1-free, not in a sibling
 *      admin page. api2.php had no equivalent case at all, so retiring v1
 *      as planned would have broken that button outright. Fix: extract the
 *      three RTF/SQLite builder functions VERBATIM into the new shared
 *      `includes/easyworship_export.php` (the `song_importers.php`
 *      precedent, #1200 Phase 4b), `require_once` it from BOTH api.php
 *      (unchanged v1 behaviour while it still exists) and api2.php (a new
 *      `easyworship_export` case), and repoint export.js at api2.php.
 *
 * METHOD — source analysis, no DB, no HTTP:
 *   Every assertion strips `//` and `/* *\/` comments (and preserves
 *   string literal CONTENTS, not blanked, since the very thing several
 *   assertions look for — an endpoint URL, an error string — legitimately
 *   lives inside a string literal) before matching. This is LOAD-BEARING,
 *   not decorative: this file's own #1629 commit message and in-code
 *   doc-comments quote the OLD v1 URLs and the OLD "…in the editor
 *   first." string verbatim, for readers researching the history — a
 *   naive raw-source grep would find those quotes and falsely pass a
 *   regression where the code reverted but the prose didn't get cleaned
 *   up, or (for the "no longer says" assertions) falsely FAIL on a
 *   correctly-migrated file merely because an explanatory comment still
 *   mentions the retired string. See stripComments() below.
 *
 * Usage:
 *   php tests/php/test-v1-consumer-deorphan.php
 *
 * Exit status: 0 = all assertions passed, 1 = at least one failed.
 */

$failures = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures++;
        if ($detail !== '') {
            echo '        ' . $detail . "\n";
        }
    }
}

/**
 * Strip `//` line comments and `/* *\/` block comments from PHP/JS source,
 * blanking each with matching whitespace/newlines (so byte offsets used by
 * the block-extraction helper below stay meaningful) while leaving every
 * quoted string's CONTENTS untouched. A `//` or `/*` sequence inside a
 * single/double/backtick-quoted string is not mistaken for the start of a
 * comment (mirrors tests/test-api-client-usage.js's stripCommentsAndStrings
 * character-scanner shape, adapted to keep string bodies instead of
 * blanking them too — several assertions below need to find their target
 * text INSIDE a string literal, e.g. an endpoint URL or an error message).
 *
 * REGEX LITERALS (the part a naive quote-tracking scanner gets wrong):
 * bulk-import-progress.js's escapeHtml() contains `.replace(/"/g, ...)` and
 * `.replace(/\s+/g, ' ')` — a `/pattern/flags` regex literal whose pattern
 * contains a `"` character. A scanner that treats every `"` as a string
 * delimiter regardless of context reads that `/"/ ` as "open a double-
 * quoted string", then keeps consuming everything — including this very
 * file's OWN later doc-comments — as "inside a string" until the next
 * unrelated `"` happens to close it (in this codebase, an HTML
 * `class="…"` attribute two dozen lines later), silently corrupting every
 * assertion downstream. `/` vs division is genuinely ambiguous in JS
 * without a real tokenizer, so this uses the standard heuristic real
 * linters use: a `/` starts a regex literal (not division) when the
 * previous non-whitespace character is one that can't end an expression —
 * `(`, `,`, `=`, `:`, `[`, `!`, `&`, `|`, `?`, `{`, `}`, `;`, or start-of-
 * file. Every regex literal actually present in the files this test scans
 * follows a `(` (a `.replace(/…/, …)` call), which this heuristic
 * classifies correctly; see the self-test block below for the pinned
 * regression case.
 *
 * @param string $src Raw file contents.
 * @return string Same length; comment bodies replaced with spaces/newlines.
 */
function stripComments(string $src): string
{
    $out = '';
    $n = strlen($src);
    $i = 0;
    $state = 'code'; // code | block | line | sq | dq | tpl
    /* Last non-whitespace character actually emitted while in 'code'
       state — drives the regex-vs-division heuristic above. Empty string
       = start of file (also a valid regex-literal position). */
    $prevSig = '';
    $regexPrecedingChars = "(,=:[!&|?{};\n";
    while ($i < $n) {
        $c  = $src[$i];
        $c2 = ($i + 1 < $n) ? $src[$i + 1] : '';

        if ($state === 'code') {
            if ($c === '/' && $c2 === '*') { $state = 'block'; $out .= '  '; $i += 2; continue; }
            if ($c === '/' && $c2 === '/') { $state = 'line';  $out .= '  '; $i += 2; continue; }
            if ($c === '/' && ($prevSig === '' || strpos($regexPrecedingChars, $prevSig) !== false)) {
                /* Regex literal: consume verbatim up to the closing
                   unescaped `/` (a `/` inside a `[...]` character class
                   does not close it — JS regex grammar), then any trailing
                   flag letters. Never enters sq/dq/tpl for its body, so a
                   quote character inside the pattern can't derail the
                   scanner. */
                $j = $i + 1;
                $inClass = false;
                while ($j < $n) {
                    $rc = $src[$j];
                    if ($rc === '\\' && $j + 1 < $n) { $j += 2; continue; }
                    if ($rc === '[') { $inClass = true; $j++; continue; }
                    if ($rc === ']') { $inClass = false; $j++; continue; }
                    if ($rc === '/' && !$inClass) { $j++; break; }
                    if ($rc === "\n") { break; } /* malformed / not actually a regex — bail out */
                    $j++;
                }
                while ($j < $n && ctype_alpha($src[$j])) { $j++; }
                $out .= substr($src, $i, $j - $i);
                $prevSig = $src[$j - 1];
                $i = $j;
                continue;
            }
            if ($c === "'")  { $state = 'sq';  $out .= $c; $i++; continue; }
            if ($c === '"')  { $state = 'dq';  $out .= $c; $i++; continue; }
            if ($c === '`')  { $state = 'tpl'; $out .= $c; $i++; continue; }
            $out .= $c;
            if (!ctype_space($c)) { $prevSig = $c; }
            $i++;
            continue;
        }

        if ($state === 'block') {
            if ($c === '*' && $c2 === '/') { $state = 'code'; $out .= '  '; $i += 2; continue; }
            $out .= ($c === "\n") ? "\n" : ' ';
            $i++;
            continue;
        }

        if ($state === 'line') {
            if ($c === "\n") { $state = 'code'; $out .= "\n"; $i++; continue; }
            $out .= ' ';
            $i++;
            continue;
        }

        /* sq / dq / tpl — inside a quoted string: copy verbatim (this is the
           deliberate difference from the fetch-usage guard's stripper —
           string CONTENT must survive so a URL/message literal inside it is
           still matchable), but still consume an escaped character as a
           pair so an escaped quote can't be mistaken for the closer. */
        $closers = ['sq' => "'", 'dq' => '"', 'tpl' => '`'];
        $closer = $closers[$state];
        if ($c === '\\' && $i + 1 < $n) {
            $out .= $c . $src[$i + 1];
            $i += 2;
            continue;
        }
        if ($c === $closer) { $state = 'code'; $out .= $c; $prevSig = $c; $i++; continue; }
        $out .= $c;
        $i++;
    }
    return $out;
}

/** Read + comment-strip one file, or fail hard (a missing file is a setup bug, not a soft skip). */
function readStripped(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "FATAL: expected file not found: {$path}\n");
        exit(1);
    }
    return stripComments((string)file_get_contents($path));
}

/**
 * Extract the substring of a `stripped` source starting at the first
 * `if ($action === '<name>')` block header and running up to (but not
 * including) the NEXT `if ($action === '...')` header, or end of string if
 * this is the last one. Used to check that a specific action's own block —
 * not the whole file — does or doesn't reference something (e.g. that
 * `unlink`'s block does not also require manage_duplicate_songs).
 *
 * @return string|null null if the block header wasn't found at all.
 */
function extractActionBlock(string $stripped, string $actionName): ?string
{
    $headerPattern = "/if \(\\\$action === '" . preg_quote($actionName, '/') . "'\)/";
    if (!preg_match($headerPattern, $stripped, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1];
    /* Find the next "if ($action === '...')" header after this one. */
    if (preg_match("/if \(\\\$action === '[a-z_]+'\)/", $stripped, $nextM, PREG_OFFSET_CAPTURE, $start + strlen($m[0][0]))) {
        $end = $nextM[0][1];
        return substr($stripped, $start, $end - $start);
    }
    return substr($stripped, $start);
}

$repoRoot = dirname(__DIR__, 2);
$webRoot  = $repoRoot . '/appWeb/public_html';

/* ======================================================================
 * 1. Content Restrictions typeahead — renderEntityPicker.js + api2.php
 *    (item #1 in the #1629 plan)
 * ====================================================================== */
echo "1. Content Restrictions typeahead (user_search / org_search):\n";

$pickerJs = readStripped($webRoot . '/manage/includes/renderEntityPicker.js');
$api2Php  = readStripped($webRoot . '/manage/editor/api2.php');

check(
    'renderEntityPicker.js no longer targets v1 api.php for user_search',
    strpos($pickerJs, "/manage/editor/api?action=user_search") === false,
    'found the v1 (no ".php", no "2") user_search URL literal — the picker still points at the retiring endpoint'
);
check(
    'renderEntityPicker.js no longer targets v1 api.php for org_search',
    strpos($pickerJs, "/manage/editor/api?action=org_search") === false,
    'found the v1 (no ".php", no "2") org_search URL literal — the picker still points at the retiring endpoint'
);
check(
    'renderEntityPicker.js targets api2.php for user_search',
    strpos($pickerJs, "/manage/editor/api2.php?action=user_search") !== false,
    'expected a "/manage/editor/api2.php?action=user_search" URL literal in the "user" picker source'
);
check(
    'renderEntityPicker.js targets api2.php for org_search',
    strpos($pickerJs, "/manage/editor/api2.php?action=org_search") !== false,
    'expected a "/manage/editor/api2.php?action=org_search" URL literal in the "organisation" picker source'
);
check(
    "api2.php now has a 'user_search' case",
    (bool)preg_match("/case\s+'user_search'\s*:/", $api2Php),
    "api2.php's switch has no case 'user_search': — the port never landed"
);
check(
    "api2.php now has an 'org_search' case",
    (bool)preg_match("/case\s+'org_search'\s*:/", $api2Php),
    "api2.php's switch has no case 'org_search': — the port never landed"
);

/* ======================================================================
 * 2. Admin Songbooks bundle export — songbooks.php
 * ====================================================================== */
echo "\n2. Admin Songbooks bundle export:\n";

$songbooksPhp = readStripped($webRoot . '/manage/songbooks.php');

check(
    "songbooks.php no longer fetches the editor's v1 songbook_export",
    strpos($songbooksPhp, '/manage/editor/api.php?action=songbook_export') === false,
    'found a fetch() targeting the retiring v1 editor endpoint'
);
check(
    "songbooks.php no longer fetches an editor v2 songbook_export either (api2.php doesn't have one)",
    strpos($songbooksPhp, '/manage/editor/api2.php?action=songbook_export') === false,
    'found a fetch() targeting an api2.php songbook_export — the plan is to use the PUBLIC endpoint, not grow an editor one'
);
check(
    'songbooks.php fetches the public /api?action=songbook_export instead',
    strpos($songbooksPhp, '/api?action=songbook_export') !== false,
    'expected the bundle-export click handler to call the public, gated api.php songbook_export endpoint'
);

/* ======================================================================
 * 3. Bulk-import stale-job poller — bulk-import-progress.js
 * ====================================================================== */
echo "\n3. Bulk-import stale-job poller:\n";

$bulkImportJs = readStripped($webRoot . '/js/modules/bulk-import-progress.js');

check(
    'bulk-import-progress.js no longer references bulk_import_status (in code — comments are stripped)',
    strpos($bulkImportJs, 'bulk_import_status') === false,
    'the retired v1 action name is still reachable from code, not just from an explanatory comment'
);
check(
    'bulk-import-progress.js polls import_zip_status instead',
    strpos($bulkImportJs, 'action=import_zip_status') !== false,
    'expected the fallback poll URL to target api2.php?action=import_zip_status'
);
check(
    'bulk-import-progress.js targets api2.php (not the bare v1 /manage/editor/api path)',
    strpos($bulkImportJs, '/manage/editor/api2.php?action=import_zip_status') !== false,
    'expected the literal "/manage/editor/api2.php?action=import_zip_status" URL'
);

/* ======================================================================
 * 4. /manage/duplicate-songs unlink action
 * ====================================================================== */
echo "\n4. /manage/duplicate-songs unlink action:\n";

$dupSongsPhp = readStripped($webRoot . '/manage/duplicate-songs.php');

check(
    "duplicate-songs.php has an 'unlink' action",
    (bool)preg_match("/if \(\\\$action === 'unlink'\)/", $dupSongsPhp),
    "no \"if (\$action === 'unlink')\" dispatcher branch found"
);

/* Gate ordering: the page-level edit_songs entitlement check must occur
   BEFORE the unlink dispatcher branch (i.e. it's in scope by the time POST
   is handled at all) — same structural guarantee link/dismiss rely on. */
$gatePos   = strpos($dupSongsPhp, "userHasEntitlement('edit_songs', \$role)");
$unlinkPos = strpos($dupSongsPhp, "if (\$action === 'unlink')");
check(
    "duplicate-songs.php's page-level edit_songs gate covers the unlink action (declared first, same dispatcher)",
    $gatePos !== false && $unlinkPos !== false && $gatePos < $unlinkPos,
    "expected the top-level userHasEntitlement('edit_songs', ...) gate (offset {$gatePos}) to appear before the unlink branch (offset {$unlinkPos})"
);

/* validateCsrfRequest() must gate the POST dispatcher BEFORE the unlink
   branch is reachable — never a bare validateCsrf()-only check. */
$csrfReqPos = strpos($dupSongsPhp, 'validateCsrfRequest(');
check(
    'duplicate-songs.php POST dispatcher uses validateCsrfRequest() (rule #29), not a baked-token-only check, ahead of the unlink branch',
    $csrfReqPos !== false && $unlinkPos !== false && $csrfReqPos < $unlinkPos,
    'expected validateCsrfRequest( to appear, and to appear before the unlink dispatcher branch'
);

/* The unlink action's OWN block must not additionally require
   manage_duplicate_songs — task spec: same gate as link/dismiss
   (edit_songs), explicitly NOT the destructive-merge entitlement. */
$unlinkBlock = extractActionBlock($dupSongsPhp, 'unlink');
check(
    "duplicate-songs.php's unlink block exists and is isolatable",
    $unlinkBlock !== null,
    'extractActionBlock() could not locate the unlink block — dispatcher shape may have changed'
);
if ($unlinkBlock !== null) {
    check(
        "duplicate-songs.php's unlink action does not additionally gate on manage_duplicate_songs / \$canMerge",
        strpos($unlinkBlock, 'manage_duplicate_songs') === false && strpos($unlinkBlock, '$canMerge') === false,
        'unlink should be edit_songs-only (same as link/dismiss), not require the destructive-merge entitlement'
    );
    check(
        'duplicate-songs.php unlink action deletes from tblSongLinks',
        strpos($unlinkBlock, 'tblSongLinks') !== false,
        'expected the unlink block to operate on tblSongLinks'
    );
}

/* ======================================================================
 * 5. The old ":306" conflict message no longer points at the editor
 * ====================================================================== */
echo "\n5. Link-conflict error message:\n";

check(
    'duplicate-songs.php\'s link-conflict message no longer says "in the editor" (comment-stripped — the commit\'s own doc-comment quotes the old string for history and must not fool this check)',
    stripos($dupSongsPhp, 'in the editor') === false,
    'found "in the editor" (case-insensitive) in code after comment-stripping — either the message wasn\'t changed or a comment leaked into executable source'
);
check(
    'duplicate-songs.php still has the "already in different counterpart groups" conflict message (just repointed, not deleted)',
    strpos($dupSongsPhp, 'already in different counterpart groups') !== false,
    'the 409 conflict message text seems to have been removed entirely rather than repointed'
);

/* ======================================================================
 * 6. EasyWorship export button — v2 export.js + includes/easyworship_export.php
 *    (#1678 — the FIFTH v1 consumer, missed by the original #1629 audit
 *    above because it lives inside the v2 editor surface itself)
 * ====================================================================== */
echo "\n6. EasyWorship export button (#1678, the fifth v1 consumer):\n";

$easyworshipInc = readStripped($webRoot . '/includes/easyworship_export.php');
$apiPhp         = readStripped($webRoot . '/manage/editor/api.php');
$exportJs       = readStripped($webRoot . '/manage/editor/v2/export.js');

foreach (['_ewExport_rtfEscape', '_ewExport_buildRtf', '_ewExport_writeDb'] as $ewFn) {
    check(
        "includes/easyworship_export.php defines {$ewFn}()",
        (bool)preg_match('/function\s+' . preg_quote($ewFn, '/') . '\s*\(/', $easyworshipInc),
        "expected \"function {$ewFn}(\" in includes/easyworship_export.php — the extraction never landed"
    );
    check(
        "manage/editor/api.php no longer DEFINES {$ewFn}() (moved, not merely called)",
        !(bool)preg_match('/function\s+' . preg_quote($ewFn, '/') . '\s*\(/', $apiPhp),
        "found a \"function {$ewFn}(\" declaration still in api.php — the move left a duplicate definition behind"
    );
}
check(
    "api2.php now has an 'easyworship_export' case",
    (bool)preg_match("/case\s+'easyworship_export'\s*:/", $api2Php),
    "api2.php's switch has no case 'easyworship_export': — the v2 endpoint was never added"
);
check(
    'export.js no longer references the legacy /manage/editor/api.php path',
    strpos($exportJs, '/manage/editor/api.php') === false,
    'found a "/manage/editor/api.php" reference in export.js — the retiring v1 endpoint is still targeted'
);
check(
    'export.js targets api2.php for the EasyWorship export',
    /* Anchored on a `&` or the closing quote after the action name — a bare
       strpos() substring match would be fooled by e.g.
       "action=easyworship_export_wrong", which contains the wanted string
       as a prefix but names a different (nonexistent) action entirely. */
    (bool)preg_match("/\\/manage\\/editor\\/api2\\.php\\?action=easyworship_export(?:&|')/", $exportJs),
    'expected a "/manage/editor/api2.php?action=easyworship_export" URL literal (action name exact, not just a prefix) in export.js'
);

/* ======================================================================
 * Self-test: stripComments() must actually be CAPABLE of both stripping a
 * comment and preserving a string literal — a guard that can't fail proves
 * nothing (the #1581 precedent named in this repo's own test-event-names.js).
 * ====================================================================== */
echo "\nSelf-test — stripComments() correctness:\n";

check(
    'a /* */ comment is blanked out',
    strpos(stripComments("/* mentions bulk_import_status here */ \$x = 1;"), 'bulk_import_status') === false
);
check(
    'a // line comment is blanked out',
    strpos(stripComments("// mentions in the editor here\n\$x = 1;"), 'in the editor') === false
);
check(
    'a string literal survives stripping (the load-bearing difference from a blank-everything stripper)',
    strpos(stripComments("\$url = '/manage/editor/api2.php?action=user_search';"), '/manage/editor/api2.php?action=user_search') !== false
);
check(
    'a URL containing // inside a string is not mistaken for a line comment',
    strpos(stripComments("\$u = 'https://example.test/x?action=songbook_export';"), 'action=songbook_export') !== false
);
check(
    'a regex literal containing a quote character (.replace(/"/g, …)) does not derail string tracking — '
        . 'the exact bug this stripper hit against bulk-import-progress.js\'s escapeHtml() during development',
    (function (): bool {
        $sample = ".replace(/\"/g, '&quot;');\n/* mentions bulk_import_status here, AFTER the tricky regex */\n\$x = 1;";
        $stripped = stripComments($sample);
        return strpos($stripped, 'bulk_import_status') === false
            && strpos($stripped, "'&quot;'") !== false; /* the real string after the regex must survive */
    })()
);

echo "\n" . ($failures === 0 ? 'All assertions passed.' : "{$failures} assertion(s) FAILED.") . "\n";
exit($failures === 0 ? 0 : 1);
