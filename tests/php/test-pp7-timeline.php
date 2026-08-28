<?php

declare(strict_types=1);

/**
 * tests/php/test-pp7-timeline.php — ProPresenter auto-advance timeline capture guard (#1968
 * dormant groundwork)
 * ================================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Proves two things at once: (1) the DECODER (`pp7DecodeTimeline()`/`pp7DecodeTimelineCue()`,
 * `includes/propresenter7_decode.php`) reads a ProPresenter auto-advance schedule correctly,
 * never fabricating one where none exists, and never leaking `cues_v2`'s Clear-action automation
 * data into the schedule it hands back; and (2) the CAPTURE side (`includes/pp7_timeline.php`,
 * plus its wiring into `includes/song_importers.php`) is genuinely gated OFF by the
 * `pp7_timeline_import_enabled` toggle, so this whole feature is a verified no-op on every
 * install until an operator flips it.
 *
 * DETAILED — sections
 * -----------------------
 *   (a) DECODE — real + synthetic fixtures decode to the exact expected cue schedule. Uses the
 *       ALREADY-COMMITTED real fixture `owner-v21-heretostay-video-sanitised.pro` (one genuine
 *       cue, the `action` oneof branch — no `cue_id`) plus the synthesised
 *       `tests/fixtures/propresenter/timeline/*.pro` fixtures built by
 *       `tools/pp7-gen-timeline-fixture.js` (whose own doc-block records the real-file evidence
 *       these numbers come from — see especially the field-semantics finding: `cues_v2` (field
 *       11) is NEVER the source of the captured schedule).
 *   (b) NO FALSE POSITIVE ON ABSENCE — `synthetic-timeline-absent.pro` (field 17 genuinely
 *       deleted) decodes to `timeline: null, hasTimeline: false`; a real committed fixture whose
 *       Timeline is present-but-empty (`bussnet-test.pro`, `duration=300, cues=[]` — the
 *       placeholder EVERY real ProPresenter export appears to write) decodes to an EMPTY `cues`
 *       array, never a fabricated one.
 *   (c) CAPTURE GATING — `pp7TimelineStore()` is gated behind BOTH
 *       `pp7TimelineImportEnabled()` and `pp7TimelineTableExists()`. A live, reachable `\mysqli`
 *       is required to call any of these three functions at all (they type-hint `\mysqli`, and —
 *       the same conclusion `tests/php/test-song-media-visibility.php`'s own doc-block already
 *       states for its sibling gate — "the mysqli type hint blocks a fake"), so this section
 *       follows that file's SAME idiom: a source-derived (tree-derived, never a hand-typed
 *       line-number) assertion that `pp7TimelineStore()`'s own function body checks the gate
 *       BEFORE its `INSERT INTO tblSongPresentationCues`, which runs unconditionally in every
 *       environment (this sandbox has no reachable MySQL at all — no server, no credentials
 *       file — so it is the load-bearing proof here); PLUS an optional real-DB behavioural block
 *       (same `db_credentials.php`-or-`IHYMNS_TEST_DSN` idiom as
 *       `tests/php/test-pp7-probundle-import.php`'s Part B) that, when a database IS reachable,
 *       proves the SAME gate behaviourally — real 0-row returns when the table is absent or the
 *       toggle is off, real inserted rows (cleaned up afterwards) when both are satisfied.
 *   (d) WIRING — a tree-derived source assertion that `_bulkImport_processPro7()`
 *       (`includes/song_importers.php`, the ONE shared single-file pipeline every `.pro`/
 *       `.probundle`/`.proplaylist` import path funnels through) actually CALLS
 *       `pp7TimelineStore(` — proving the capture path is reachable from the real import flow,
 *       not dead code only this test exercises.
 *
 * ⚠️ FIELD-SEMANTICS FINDING THIS FILE GUARDS (see `tools/pp7-gen-timeline-fixture.js`'s doc-block
 * for the full write-up): an earlier draft of this feature's spec called for the decoder to
 * PREFER `Timeline.cues_v2` (field 11) over `Timeline.cues` (field 1) when present. Independently
 * decoding two real multi-cue ProPresenter exports during implementation (both real
 * "Rescuer (Good News)" variants) disproved that — `cues_v2` on those files is a SUPERSET
 * carrying `ACTION_TYPE_CLEAR_GROUP`/`ACTION_TYPE_CLEAR` automation entries (`trigger_time`
 * frequently 0, no `cue_id`) interleaved with duplicates of the real cues, so preferring it would
 * have captured automation actions as the auto-advance schedule — exactly the false-positive
 * class this epic's owner rule forbids. Section (a)'s
 * `synthetic-timeline-cues-v2-must-be-ignored.pro` check is the guard against ever
 * re-introducing that regression.
 *
 * MUTATION-PROVEN (rule #34) — performed by hand at authoring time, each confirmed RED, then
 * reverted byte-identically, confirmed GREEN again:
 *   1. Reintroduced the disproven "prefer cues_v2" mistake: changed
 *      `PP7_FIELDS_TIMELINE['cues']` from `1` to `11` in `includes/propresenter7_decode.php`
 *      (redirecting the decoder onto `cues_v2` instead of `cues`) -> section (a)'s
 *      `synthetic-timeline-cues.pro` check went RED (decoded `cues` came back EMPTY — that
 *      fixture's `cues_v2` is empty) AND (the more dangerous case)
 *      `synthetic-timeline-cues-v2-must-be-ignored.pro`'s check went RED with the WRONG
 *      777.x sentinel values surfacing in the decoded schedule — the exact false positive this
 *      guard exists to catch. Reverted -> both green again.
 *   2. Broke the decoder's `trigger_time` unpack: changed `unpack('e', $raw)` to
 *      `unpack('E', $raw)` (big-endian instead of little-endian) in `pp7DecodeTimelineCue()` ->
 *      every decoded `triggerSeconds` in section (a) came back as nonsense huge/garbage values
 *      instead of the expected small second counts -> RED. Reverted -> green.
 *   3. Broke the absence guard: changed `pp7DecodePresentation()`'s timeline case to
 *      unconditionally set `$out['timeline'] = pp7DecodeTimeline('', 1); $out['hasTimeline'] =
 *      true;` regardless of whether field 17 was ever seen -> section (b)'s
 *      `synthetic-timeline-absent.pro` check (asserting `timeline === null`) went RED (a
 *      fabricated non-null timeline appeared for a file that genuinely carries none — the exact
 *      false-positive class the owner's #1 rule for this epic forbids). Reverted -> green.
 *   4. Broke the capture gate: commented out the
 *      `if (!pp7TimelineImportEnabled($db) || !pp7TimelineTableExists($db)) { return 0; }` line
 *      inside `pp7TimelineStore()` (`includes/pp7_timeline.php`). FIRST attempt stayed WRONGLY
 *      GREEN (rule #34's own warning realised in the writing of this very guard): the source scan
 *      read the raw file text, and `str_contains()` still found `pp7TimelineImportEnabled(` sitting
 *      right there IN THE COMMENT the mutation left behind — checking for the text of a call is not
 *      the same as checking for a live call. Fixed by adding `ptlStripComments()` (PHP's own
 *      tokenizer, the `test-song-media-visibility.php` `smvPhpCode()` shape) so both source scans
 *      in this file read comment-stripped code -> re-ran the SAME mutation -> now correctly RED
 *      (4 assertions). Reverted -> green.
 *   5. Broke the wiring: commented out the `pp7TimelineStore($db, ...)` call inside
 *      `_bulkImport_processPro7()` (`includes/song_importers.php`) -> section (d) went RED (no
 *      call to `pp7TimelineStore(` found in that function's body). Reverted -> green.
 *
 * Usage:
 *   php tests/php/test-pp7-timeline.php
 *   IHYMNS_TEST_DSN='host=127.0.0.1;user=root;pass=;dbname=ihymns' php tests/php/test-pp7-timeline.php
 *
 * Exit status: 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/propresenter7_decode.php   pp7DecodeTimeline()/pp7DecodeTimelineCue()
 * @see appWeb/public_html/includes/pp7_timeline.php             the gated capture helpers
 * @see appWeb/public_html/includes/song_importers.php           _bulkImport_processPro7() — the wiring
 * @see appWeb/.sql/migrate-pp7-timeline-groundwork.php           the dormant schema + toggle seed
 * @see tools/pp7-gen-timeline-fixture.js                         builds the synthetic fixtures this reads
 * @see tests/php/test-pp7-decode.php                             the sibling decoder cross-validation suite
 * @see tests/php/test-song-media-visibility.php                  the DB-optional-block idiom this mirrors
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

require_once $pub . '/includes/propresenter7_decode.php';
require_once $pub . '/includes/song_importers.php';
require_once $pub . '/includes/pp7_timeline.php';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  \xE2\x9C\x85 {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  \xE2\x9D\x8C {$label}\n";
    }
}

/**
 * Strip PHP comments (// # and block, incl. doc-blocks) from source, replacing each with
 * blank lines so byte offsets/line numbers still line up — the `test-song-media-visibility.php`
 * `smvPhpCode()` shape, using PHP's OWN tokenizer rather than a hand-rolled regex, so a
 * `//`/`/* … *␀/` mentioning a function name in PROSE (this very file's own doc-block, or a
 * "MUTATION TEST" comment marking a disabled gate check) can never be mistaken for a real call —
 * load-bearing for this file's own mutation-proof #4 below: commenting out a gate check must make
 * the corresponding source assertion go RED, not stay accidentally green because the check merely
 * looks for the TEXT of a call, comment or not.
 */
function ptlStripComments(string $src): string
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

$fixturesDir = $repoRoot . '/tests/fixtures/propresenter';
$timelineDir = $fixturesDir . '/timeline';

echo "\n#1968 dormant groundwork — ProPresenter auto-advance timeline capture guard\n\n";

/* ============================================================================================
 * (a) DECODE — real + synthetic fixtures decode to the exact expected cue schedule
 * ============================================================================================ */

echo "-- (a) pp7DecodeTimeline()/pp7DecodeTimelineCue() decode real + synthetic fixtures --\n";

/** Load one .pro fixture's bytes, or fail the given label and return null. */
function ptlLoadBytes(string $path, string $label): ?string
{
    if (!is_file($path)) {
        ok("{$label}: fixture file exists ({$path})", false);
        return null;
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        ok("{$label}: fixture is readable", false);
        return null;
    }
    return $bytes;
}

/* -- (a1) REAL fixture: owner-v21-heretostay-video-sanitised.pro — genuinely committed, already
 *    copyright-vetted, ONE real timeline cue on the `action` oneof branch (no cue_id): a
 *    media-triggering entry, trigger_time ~0.62s, name = the media filename. This is real
 *    ProPresenter output, not synthesised. -- */
$realBytes = ptlLoadBytes($fixturesDir . '/owner-v21-heretostay-video-sanitised.pro', 'owner-v21-heretostay-video-sanitised.pro');
if ($realBytes !== null) {
    $decoded = pp7DecodePresentation($realBytes);
    ok('owner-v21-heretostay-video-sanitised.pro: hasTimeline is true', $decoded['hasTimeline'] === true);
    $tl = $decoded['timeline'];
    ok('owner-v21-heretostay-video-sanitised.pro: timeline decoded (not null)', $tl !== null);
    if ($tl !== null) {
        ok('owner-v21-heretostay-video-sanitised.pro: exactly 1 real cue', count($tl['cues']) === 1);
        if (count($tl['cues']) === 1) {
            $c = $tl['cues'][0];
            ok('owner-v21-heretostay-video-sanitised.pro: cues[0].triggerSeconds matches the real decoded value (0.619806180334395)',
                abs($c['triggerSeconds'] - 0.619806180334395) < 0.0000001);
            ok('owner-v21-heretostay-video-sanitised.pro: cues[0].cueUuid is empty (a real media-triggering cue — the `action` oneof branch, no cue_id)',
                $c['cueUuid'] === '');
            ok('owner-v21-heretostay-video-sanitised.pro: cues[0].name is the media filename',
                $c['name'] === "Here to Stay (Acoustic) \xe2\x80\x93 Anthem Worship.mp4");
        }
    }
}

/* -- (a2) SYNTHETIC: synthetic-timeline-cues.pro — 3 genuine cue_id-carrying cues, increasing
 *    trigger_time, loop=true (the non-default value — proves the bool is actually read). The
 *    first trigger_time (0.787310792) is the exact value independently observed — by TWO
 *    decoders (protobufjs and this PHP walker) — on the real, non-"Extended", "Rescuer (Good
 *    News) (Life Church Kids Video)" sample's own cues[0] during this task's authoring (that
 *    real file is NOT committed here — live CCLI copyright, see the fixtures README). -- */
$cuesBytes = ptlLoadBytes($timelineDir . '/synthetic-timeline-cues.pro', 'synthetic-timeline-cues.pro');
if ($cuesBytes !== null) {
    $decoded = pp7DecodePresentation($cuesBytes);
    $tl = $decoded['timeline'];
    ok('synthetic-timeline-cues.pro: timeline decoded (not null)', $tl !== null);
    if ($tl !== null) {
        ok('synthetic-timeline-cues.pro: duration decoded correctly (217.5)', $tl['duration'] === 217.5);
        ok('synthetic-timeline-cues.pro: loop decoded correctly (true — the non-default value)', $tl['loop'] === true);
        ok('synthetic-timeline-cues.pro: 3 cues decoded', count($tl['cues']) === 3);
        if (count($tl['cues']) === 3) {
            ok("synthetic-timeline-cues.pro: cues[0].triggerSeconds \xe2\x89\x88 0.787310792 (the real Rescuer sample's own first cue)",
                abs($tl['cues'][0]['triggerSeconds'] - 0.787310792) < 0.0000001);
            ok('synthetic-timeline-cues.pro: cues[0].cueUuid is non-empty', $tl['cues'][0]['cueUuid'] !== '');
            ok('synthetic-timeline-cues.pro: cues[0].cueUuid matches the expected real cue UUID',
                $tl['cues'][0]['cueUuid'] === 'A18EF896-F83A-44CE-AEFB-5AE8969A9653');
            ok('synthetic-timeline-cues.pro: trigger times are strictly increasing (monotonic)',
                $tl['cues'][0]['triggerSeconds'] < $tl['cues'][1]['triggerSeconds']
                && $tl['cues'][1]['triggerSeconds'] < $tl['cues'][2]['triggerSeconds']);
            ok('synthetic-timeline-cues.pro: every cue carries a non-empty cueUuid',
                $tl['cues'][0]['cueUuid'] !== '' && $tl['cues'][1]['cueUuid'] !== '' && $tl['cues'][2]['cueUuid'] !== '');
            ok('synthetic-timeline-cues.pro: cue names decoded correctly',
                $tl['cues'][0]['name'] === 'Cue A' && $tl['cues'][1]['name'] === 'Cue B' && $tl['cues'][2]['name'] === 'Cue C');
        }
    }
}

/* -- (a3) SYNTHETIC: synthetic-timeline-cues-v2-must-be-ignored.pro — `cues` (field 1) holds the
 *    CORRECT 3-entry schedule; `cues_v2` (field 11) holds sentinel 777.x entries that must NEVER
 *    surface. This is the direct guard against the disproven "prefer cues_v2" mistake — see this
 *    file's own doc-block "FIELD-SEMANTICS FINDING" section. -- */
$v2Bytes = ptlLoadBytes($timelineDir . '/synthetic-timeline-cues-v2-must-be-ignored.pro', 'synthetic-timeline-cues-v2-must-be-ignored.pro');
if ($v2Bytes !== null) {
    $decoded = pp7DecodePresentation($v2Bytes);
    $tl = $decoded['timeline'];
    ok('synthetic-timeline-cues-v2-must-be-ignored.pro: timeline decoded (not null)', $tl !== null);
    if ($tl !== null) {
        ok('synthetic-timeline-cues-v2-must-be-ignored.pro: exactly 3 cues decoded (cues_v2\'s 3 sentinel entries are NOT merged in)',
            count($tl['cues']) === 3);
        $triggerTimes = array_map(static fn(array $c) => $c['triggerSeconds'], $tl['cues']);
        ok('synthetic-timeline-cues-v2-must-be-ignored.pro: decoded trigger times are the CORRECT [1.0, 2.0, 3.0] — never the cues_v2 sentinel 777.x values',
            $triggerTimes === [1.0, 2.0, 3.0]);
        $names = array_map(static fn(array $c) => $c['name'], $tl['cues']);
        $leaked = false;
        foreach ($names as $n) {
            if (str_contains($n, 'WRONG') || str_contains($n, 'Clear All')) { $leaked = true; }
        }
        ok('synthetic-timeline-cues-v2-must-be-ignored.pro: no cues_v2 name ("WRONG…"/"Clear All") leaked into the decoded schedule',
            !$leaked);
    }
}

/* ============================================================================================
 * (b) NO FALSE POSITIVE — absence stays absence; presence-but-empty stays empty
 * ============================================================================================ */

echo "\n-- (b) no false positive: absent timeline decodes to null, empty timeline decodes to [] --\n";

$absentBytes = ptlLoadBytes($timelineDir . '/synthetic-timeline-absent.pro', 'synthetic-timeline-absent.pro');
if ($absentBytes !== null) {
    $decoded = pp7DecodePresentation($absentBytes);
    ok('synthetic-timeline-absent.pro: hasTimeline is false (field 17 was genuinely never written)',
        $decoded['hasTimeline'] === false);
    ok('synthetic-timeline-absent.pro: timeline is null (not a fabricated empty/default shape)',
        $decoded['timeline'] === null);
}

$emptyBytes = ptlLoadBytes($fixturesDir . '/bussnet-test.pro', 'bussnet-test.pro');
if ($emptyBytes !== null) {
    $decoded = pp7DecodePresentation($emptyBytes);
    ok('bussnet-test.pro: hasTimeline is true (ProPresenter\'s placeholder Timeline submessage IS present)',
        $decoded['hasTimeline'] === true);
    $tl = $decoded['timeline'];
    ok('bussnet-test.pro: timeline decoded (not null)', $tl !== null);
    if ($tl !== null) {
        ok('bussnet-test.pro: duration decoded correctly (300.0, the placeholder default)', $tl['duration'] === 300.0);
        ok('bussnet-test.pro: cues is an EMPTY array — no cues fabricated where none exist', $tl['cues'] === []);
    }
}

/* Also assert _bulkImport_parsePro7()'s sparse carry-through never surfaces a `timeline` key for
 * an empty/placeholder timeline (the mediaRefs-precedent sparse convention — see
 * song_importers.php's doc-comment at the carry-through site). */
[$parsedEmpty, ] = _bulkImport_parsePro7((string)$emptyBytes);
ok('_bulkImport_parsePro7(bussnet-test.pro): parsed result has NO "timeline" key (sparse — nothing to carry)',
    $parsedEmpty !== null && !array_key_exists('timeline', $parsedEmpty));

[$parsedReal, ] = _bulkImport_parsePro7((string)$realBytes);
ok('_bulkImport_parsePro7(owner-v21-heretostay-video-sanitised.pro): parsed result DOES carry a "timeline" key (one real cue exists)',
    $parsedReal !== null && array_key_exists('timeline', $parsedReal));
if ($parsedReal !== null && array_key_exists('timeline', $parsedReal)) {
    ok('_bulkImport_parsePro7(owner-v21-heretostay-video-sanitised.pro): carried-through timeline matches the decoder\'s own output exactly',
        $parsedReal['timeline'] === pp7DecodePresentation((string)$realBytes)['timeline']);
}

/* ============================================================================================
 * (c) CAPTURE GATING — pp7TimelineStore() never writes unless BOTH the toggle is on AND the
 *     table exists
 * ============================================================================================ */

echo "\n-- (c) pp7TimelineStore() capture gating (toggle + table-exists) --\n";

/* (c1) Source-derived gate-ordering proof — no live DB required, so this is the load-bearing
 * check in an environment with no reachable MySQL (this sandbox has none: no server, no
 * appWeb/.auth/db_credentials.php). Mirrors tests/php/test-song-media-visibility.php's own
 * documented conclusion ("the mysqli type hint blocks a fake") for why a source-derived proof,
 * not a mocked \mysqli, is this codebase's established fallback here. Tree-derived: extracts
 * pp7TimelineStore()'s OWN function body from the real source file (never a hand-typed line
 * range) and asserts the gate check textually precedes the INSERT. */
$pp7TimelineSrcRaw = (string)file_get_contents($pub . '/includes/pp7_timeline.php');
$pp7TimelineSrc = ptlStripComments($pp7TimelineSrcRaw); // comment-stripped — see ptlStripComments()'s doc-block for why
if (!preg_match('/function\s+pp7TimelineStore\s*\([^)]*\)\s*:\s*int\s*\{(.*)\n    \}\n\}/s', $pp7TimelineSrc, $m)) {
    ok('pp7TimelineStore() function body was located in includes/pp7_timeline.php', false);
} else {
    $body = $m[1];
    $gatePos = strpos($body, 'pp7TimelineImportEnabled(');
    $tableGatePos = strpos($body, 'pp7TimelineTableExists(');
    $insertPos = strpos($body, 'INSERT INTO tblSongPresentationCues');
    ok('pp7TimelineStore() body calls pp7TimelineImportEnabled(', $gatePos !== false);
    ok('pp7TimelineStore() body calls pp7TimelineTableExists(', $tableGatePos !== false);
    ok('pp7TimelineStore() body has an INSERT INTO tblSongPresentationCues', $insertPos !== false);
    ok('pp7TimelineStore(): the pp7TimelineImportEnabled() gate check appears BEFORE the INSERT',
        $gatePos !== false && $insertPos !== false && $gatePos < $insertPos);
    ok('pp7TimelineStore(): the pp7TimelineTableExists() gate check appears BEFORE the INSERT',
        $tableGatePos !== false && $insertPos !== false && $tableGatePos < $insertPos);
}

/* (c2) Also prove pp7TimelineTableExists()'s own INFORMATION_SCHEMA probe is used (not, say, a
 * bare "SHOW TABLES" the STRICT-mysqli convention this codebase avoids for probes elsewhere is
 * inconsistent with) and that it is memoised (a `static $exists` cache) — both textual/tree
 * properties, not requiring a live DB. */
ok('pp7TimelineTableExists() queries INFORMATION_SCHEMA.TABLES', str_contains($pp7TimelineSrc, 'INFORMATION_SCHEMA.TABLES'));
ok('pp7TimelineTableExists() is memoised (static $exists cache — the songMediaVisibilityColumnExists() shape)',
    (bool)preg_match('/function\s+pp7TimelineTableExists.*?static\s+\$exists\s*=\s*null;/s', $pp7TimelineSrc));

/* (c3) DB-optional behavioural block — same idiom as test-pp7-probundle-import.php's Part B /
 * test-song-media-visibility.php's fragment-no-op block: reachable-database ? real behaviour :
 * skip cleanly, the source-derived proof above stands on its own either way. Safe to run against
 * ANY reachable database, not just a disposable one — every write this block makes is scoped to
 * a throwaway SongId under a throwaway ArrangementName and is cleaned up in a `finally`, and the
 * app-setting toggle is restored to its original value afterwards regardless of outcome. */
echo "\n-- (c3) DB-optional behavioural gate proof --\n";

$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null; $port = 3306; $dbName = null;
$credFile = $repoRoot . '/appWeb/.auth/db_credentials.php';
if (is_readable($credFile)) {
    require $credFile;
    if (defined('DB_HOST')) { $host = DB_HOST; }
    if (defined('DB_USER')) { $user = DB_USER; }
    if (defined('DB_PASS')) { $pass = DB_PASS; }
    if (defined('DB_PORT')) { $port = (int)DB_PORT; }
    if (defined('DB_NAME')) { $dbName = DB_NAME; }
} else {
    $dsn = getenv('IHYMNS_TEST_DSN') ?: '';
    if ($dsn !== '') {
        foreach (explode(';', $dsn) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if ($k === 'host')   { $host = $v; }
            if ($k === 'user')   { $user = $v; }
            if ($k === 'pass')   { $pass = $v; }
            if ($k === 'socket') { $sock = $v; }
            if ($k === 'port')   { $port = (int)$v; }
            if ($k === 'dbname' || $k === 'db') { $dbName = $v; }
        }
    }
}

$behaviouralRan = false;
if ($dbName !== null && $sock === null) {
    if (!defined('DB_HOST')) { define('DB_HOST', $host); }
    if (!defined('DB_USER')) { define('DB_USER', $user); }
    if (!defined('DB_PASS')) { define('DB_PASS', $pass); }
    if (!defined('DB_PORT')) { define('DB_PORT', (string)$port); }
    if (!defined('DB_NAME')) { define('DB_NAME', $dbName); }
    require_once $pub . '/includes/db_mysql.php';

    try {
        $db = getDbMysqli();
    } catch (\Throwable $e) {
        $db = null;
    }

    if ($db instanceof \mysqli) {
        $tableExists = pp7TimelineTableExists($db);

        if (!$tableExists) {
            /* The un-migrated case — per test-song-media-visibility.php's own note, this is the
             * DEFAULT state even on a reachable dev/CI database (migrations are never
             * auto-applied). Real behaviour: the table-absent gate alone must return 0, with no
             * throw, regardless of the toggle. */
            $rows = pp7TimelineStore($db, 'TEST-TIMELINE-1', '', [
                'duration' => 100.0, 'loop' => false,
                'cues' => [['triggerSeconds' => 1.0, 'cueUuid' => '', 'name' => 'x']],
            ]);
            ok('DB reachable, tblSongPresentationCues absent: pp7TimelineStore() returns 0 (writes nothing)', $rows === 0);
            $behaviouralRan = true;
        } else {
            /* The migrated case — full round-trip, restoring every touched value in `finally`. */
            $origSetting = null;
            $testSongId = null;
            try {
                $r = $db->query("SELECT SettingValue FROM tblAppSettings WHERE SettingKey = 'pp7_timeline_import_enabled'");
                $row = $r ? $r->fetch_row() : null;
                $origSetting = $row[0] ?? null;

                /* Toggle OFF (whatever the live value, force '0' for this half of the proof). */
                $db->query("UPDATE tblAppSettings SET SettingValue = '0' WHERE SettingKey = 'pp7_timeline_import_enabled'");
                if ($db->affected_rows === 0) {
                    $db->query("INSERT IGNORE INTO tblAppSettings (SettingKey, SettingValue) VALUES ('pp7_timeline_import_enabled', '0')");
                }
                $rowsOff = pp7TimelineStore($db, 'TEST-TIMELINE-1', '', [
                    'duration' => 100.0, 'loop' => false,
                    'cues' => [['triggerSeconds' => 1.0, 'cueUuid' => '', 'name' => 'x']],
                ]);
                ok('DB reachable, table present, toggle OFF: pp7TimelineStore() returns 0 (writes nothing)', $rowsOff === 0);

                /* Toggle ON + a real SongId (the FK requires one) -> a genuine write, then clean up. */
                $sr = $db->query('SELECT SongId FROM tblSongs LIMIT 1');
                $songRow = $sr ? $sr->fetch_row() : null;
                if ($songRow !== null) {
                    $testSongId = (string)$songRow[0];
                    $db->query("UPDATE tblAppSettings SET SettingValue = '1' WHERE SettingKey = 'pp7_timeline_import_enabled'");

                    $rowsOn = pp7TimelineStore($db, $testSongId, '__ihymns_test_timeline__', [
                        'duration' => 42.0, 'loop' => false,
                        'cues' => [
                            ['triggerSeconds' => 1.0, 'cueUuid' => 'A18EF896-F83A-44CE-AEFB-5AE8969A9653', 'name' => 'Cue 1'],
                            ['triggerSeconds' => 2.0, 'cueUuid' => '', 'name' => 'Cue 2'],
                        ],
                    ]);
                    ok('DB reachable, table present, toggle ON: pp7TimelineStore() returns 2 (2 rows written)', $rowsOn === 2);

                    $cr = $db->prepare('SELECT COUNT(*) FROM tblSongPresentationCues WHERE SongId = ? AND ArrangementName = ?');
                    $arr = '__ihymns_test_timeline__';
                    $cr->bind_param('ss', $testSongId, $arr);
                    $cr->execute();
                    $cnt = (int)($cr->get_result()->fetch_row()[0] ?? -1);
                    $cr->close();
                    ok('DB reachable: exactly 2 rows are actually present in tblSongPresentationCues', $cnt === 2);

                    /* Re-storing (idempotent delete-then-insert) must replace, not accumulate. */
                    $rowsAgain = pp7TimelineStore($db, $testSongId, '__ihymns_test_timeline__', [
                        'duration' => 42.0, 'loop' => false,
                        'cues' => [['triggerSeconds' => 5.0, 'cueUuid' => '', 'name' => 'Solo cue']],
                    ]);
                    $cr2 = $db->prepare('SELECT COUNT(*) FROM tblSongPresentationCues WHERE SongId = ? AND ArrangementName = ?');
                    $cr2->bind_param('ss', $testSongId, $arr);
                    $cr2->execute();
                    $cnt2 = (int)($cr2->get_result()->fetch_row()[0] ?? -1);
                    $cr2->close();
                    ok('DB reachable: re-storing the same (SongId, ArrangementName) REPLACES rather than accumulates (1 row, not 3)',
                        $rowsAgain === 1 && $cnt2 === 1);

                    $behaviouralRan = true;
                }
            } finally {
                if ($testSongId !== null) {
                    $del = $db->prepare('DELETE FROM tblSongPresentationCues WHERE SongId = ? AND ArrangementName = ?');
                    $arr = '__ihymns_test_timeline__';
                    $del->bind_param('ss', $testSongId, $arr);
                    $del->execute();
                    $del->close();
                }
                if ($origSetting !== null) {
                    $db->query("UPDATE tblAppSettings SET SettingValue = '" . $db->real_escape_string($origSetting) . "' WHERE SettingKey = 'pp7_timeline_import_enabled'");
                } else {
                    $db->query("DELETE FROM tblAppSettings WHERE SettingKey = 'pp7_timeline_import_enabled'");
                }
            }
        }
    }
}
if (!$behaviouralRan) {
    echo "  (behavioural gate proof SKIPPED — no reachable database; the source-derived proof (c1)/(c2) above stands)\n";
}

/* ============================================================================================
 * (d) WIRING — _bulkImport_processPro7() actually calls pp7TimelineStore()
 * ============================================================================================ */

echo "\n-- (d) capture is wired into the real import flow, not dead code --\n";

$importersSrcRaw = (string)file_get_contents($pub . '/includes/song_importers.php');
$importersSrc = ptlStripComments($importersSrcRaw); // comment-stripped — see ptlStripComments()'s doc-block for why
if (!preg_match(
    '/function\s+_bulkImport_processPro7\s*\([^)]*\)\s*:\s*array\s*\{(.*?)\n\}\s*\n\s*function\s/s',
    $importersSrc,
    $pm
)) {
    ok('_bulkImport_processPro7() function body was located in includes/song_importers.php', false);
} else {
    $procBody = $pm[1];
    ok('_bulkImport_processPro7() calls pp7TimelineStore(', str_contains($procBody, 'pp7TimelineStore('));
    ok('_bulkImport_processPro7() requires includes/pp7_timeline.php',
        str_contains($procBody, "pp7_timeline.php'") || str_contains($procBody, 'pp7_timeline.php"'));
    ok('_bulkImport_processPro7()\'s pp7TimelineStore() call is wrapped in its own try/catch (non-blocking — a timeline hiccup never fails the song import)',
        (bool)preg_match('/try\s*\{[^}]*pp7TimelineStore\(/s', $procBody));
}

/* ============================================================================================ */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nA section (a)/(b) failure means the decoder disagrees with real/verified ProPresenter\n";
    echo "timeline data, or fabricated one where none exists — the owner's #1 rule for this epic\n";
    echo "is that this must never ship. A section (c)/(d) failure means the dormant capture path\n";
    echo "is not actually gated, or is not reachable from the real import flow.\n";
    exit(1);
}
echo "\nAll ProPresenter timeline capture assertions passed.\n";
