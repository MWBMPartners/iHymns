<?php

declare(strict_types=1);

/**
 * tests/php/test-song-media-visibility.php — the #1968 P4 media publish-state
 * serving-gate guard (plan §6.7 G1)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A media row can be "admin only" (imported but not yet published). This guard
 * makes sure NObody forgets to apply the publish-state filter on a public
 * `tblSongMedia` read, and that the pure serve decision fails safe.
 *
 * DETAILED — WHAT THIS ASSERTS (and what a LATER commit adds)
 * ----------------------------------------------------------------------------
 * This file lands with commit 3 (the serving gate), so it asserts the checks
 * that gate makes true:
 *
 *   (a) SURFACE NET — every .php under appWeb/public_html EXCLUDING manage/
 *       whose COMMENT-STRIPPED source SELECTs `FROM tblSongMedia` must also call
 *       one of the blessed visibility mechanisms
 *       (`songMediaVisibilityPublicFilterSql(` / `…SelectFragment(` /
 *       `songMediaVisibilityRowAllowed(`). Tree-derived, never a typed list
 *       (rule #34) — a NEW public media read anywhere fails here until it is
 *       gated. Floor ≥ 3 (the vacuity check).
 *   (d) HONESTY — `video`/`image` appear in NEITHER `songMediaFlagKinds()` list,
 *       so an `admin`-only (or any) video/image row can never flip
 *       `HasAudio`/`HasSheetMusic` (they are outside the flag-kind map entirely;
 *       the recompute's own public filter is what excludes admin AUDIO rows).
 *   (e) THE PURE SERVE DECISION — `songMediaVisibilityRowAllowed()` truth table:
 *       NULL/''/'public' always allowed (every existing row; anonymous OK); any
 *       other value ('admin', or an unknown future 'org'/'pending') allowed ONLY
 *       with the `edit_songs` entitlement — fail-closed on an empty role or a
 *       missing entitlements module. Plus `songMediaVisibilityIsValid()`. When a
 *       DB is reachable, also proves the SQL fragments return '' on an
 *       un-migrated install (the verified-no-op property).
 *
 *   (b) THE WRITER — the tree-derived tblSongMedia WRITER (song_importers.php's
 *       P4 ingest) stores `Visibility='admin'`, binds Visibility in its INSERT,
 *       and is gated by `pp7_media_ingest_enabled` — imported media can never
 *       land public. A writer's own `FROM tblSongMedia` reads are dedup probes
 *       (they MUST see admin rows), so writers are EXCLUDED from (a)'s public-read
 *       filter net and held to this contract instead.
 *
 *   (c) PHP↔JS KIND LOCKSTEP — every SongMediaStorage kind (FS_KINDS + DB_KINDS)
 *       has a media-tab.js KIND_META block whose sizeCap agrees with
 *       SongMediaStorage::SIZE_CAPS (retro-covering the four original kinds too).
 *
 * The `_bulkImport_pp7ResolveMediaRef()` resolver truth table lives in its own
 * `test-pp7-media-ingest.php` (G2, commit 4).
 *
 * MUTATION PROOF (rule #34 — each applied to the real tree, this test re-run and
 * confirmed RED, then reverted):
 *   - (a): broke the SOLE `songMediaVisibilityPublicFilterSql(` call in
 *          song_media_flags.php (chosen because it has exactly ONE mechanism —
 *          SongData.php/song-media.php each have two, so removing one there
 *          leaves the file-level net satisfied, the documented limitation of a
 *          file-granularity net) → (a) RED for song_media_flags.php.
 *   - (b): changed the ingest's `$visibility = 'admin'` to `'public'` → (b) RED.
 *   - (c): changed media-tab.js's video sizeCap 100→50 MiB → the cap-agrees row
 *          RED; deleting the `video` KIND_META line → the has-kind row RED.
 *   - (d): added 'video' to `songMediaFlagKinds()['HasAudio']` → (d) RED.
 *   - (e): changed `songMediaVisibilityRowAllowed()`'s `$v === 'public'`
 *          early-return to `$v === 'PUBLIC'` (case-break) → (e) RED (a lowercase
 *          'public' row would then require an entitlement).
 * The comment-stripper carries its own fail-high/fail-low self-test below.
 *
 *   php tests/php/test-song-media-visibility.php
 *
 * Exit 0 = all pass, 1 = at least one failure.
 *
 * @see .claude/propresenter-interop-1968-plan.md §6.3, §6.7 (G1)
 * @see appWeb/public_html/includes/song_media_visibility.php  the gate under test
 * @see #1968 P4, issue #1976
 */

$repo = dirname(__DIR__, 2);
$pub  = $repo . '/appWeb/public_html';

$passed = 0; $failed = 0; $failures = [];
function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  \xE2\x9C\x85 {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/** PHP-code-only projection (drop comments/doc-blocks) — the shared idiom, so a
 *  `FROM tblSongMedia` MENTIONED in a doc-comment can never register as a real
 *  read surface. */
function smvPhpCode(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT || $tok[0] === T_INLINE_HTML) {
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

/* Comment-stripper self-test (rule #34 — prove the harness itself can fail). */
$selfMut = [];
$sf = "<?php\n// FROM tblSongMedia in a comment\n\$q='FROM tblSongMedia real';\n";
$sc = smvPhpCode($sf);
if (strpos($sc, 'FROM tblSongMedia real') === false) { $selfMut[] = 'smvPhpCode FAILS-HIGH: dropped real code'; }
if (substr_count($sc, 'FROM tblSongMedia') !== 1)     { $selfMut[] = 'smvPhpCode FAILS-LOW: kept a comment mention'; }
if ($selfMut) {
    fwrite(STDERR, "FAIL: comment-stripper self-test:\n");
    foreach ($selfMut as $m) { fwrite(STDERR, "  - {$m}\n"); }
    exit(1);
}

echo "\n#1968 P4 — tblSongMedia.Visibility serving-gate guard\n\n";

/* ============================================================================
 * (a) SURFACE NET — tree-derived public read sites all carry the gate
 * ============================================================================ */
echo "-- (a) every public FROM tblSongMedia read carries a visibility mechanism --\n";

/** Every .php under $root whose comment-stripped source matches $needleRegex,
 *  EXCLUDING anything under a /manage/ segment (the curator surfaces are
 *  deliberately unfiltered — they badge, they don't hide). */
function smvScanTree(string $root, string $needleRegex): array
{
    $hits = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        $path = $file->getPathname();
        if (strpos($path, '/manage/') !== false) { continue; }
        $code = smvPhpCode((string)file_get_contents($path));
        if (preg_match($needleRegex, $code)) { $hits[] = $path; }
    }
    sort($hits);
    return $hits;
}

$readNeedle  = '/FROM\s+tblSongMedia\b/i';
$writeNeedle = '/INSERT\s+INTO\s+tblSongMedia\b/i';
$readers = smvScanTree($pub, $readNeedle);

/* A tblSongMedia WRITER (INSERT INTO tblSongMedia) is a different category: its
   own `FROM tblSongMedia` reads are DEDUP/existence probes that MUST see EVERY
   row (public AND admin) to avoid re-storing a file already stored, so they are
   deliberately UNFILTERED. Writers are held to the write-side contract by check
   (b) below + test-editor2-metadata-1862.php, not to the public-read filter here.
   This is a principled category split (derived from the tree via $writeNeedle),
   never a typed file allowlist (rule #34). */
$publicReaders = array_values(array_filter($readers, static function (string $path): bool {
    return !preg_match($GLOBALS['writeNeedle'], smvPhpCode((string)file_get_contents($path)));
}));
ok('derived at least 3 public (non-writer) FROM tblSongMedia read files (vacuity check; found ' . count($publicReaders) . ')',
    count($publicReaders) >= 3);

$blessed = ['songMediaVisibilityPublicFilterSql(', 'songMediaVisibilitySelectFragment(', 'songMediaVisibilityRowAllowed('];
foreach ($publicReaders as $path) {
    $rel  = str_replace($repo . '/', '', $path);
    $code = smvPhpCode((string)file_get_contents($path));
    $has  = false;
    foreach ($blessed as $needle) { if (strpos($code, $needle) !== false) { $has = true; break; } }
    ok("{$rel}: a public read of tblSongMedia carries a visibility mechanism (file-level net — a new ungated read fails here)", $has);
}

/* ============================================================================
 * (b) THE WRITER — imported media cannot land public
 * ============================================================================ */
echo "\n-- (b) song_importers.php ingest stores admin-only, behind the dormancy gate --\n";
$importersPath = $pub . '/includes/song_importers.php';
$importersCode = smvPhpCode((string)file_get_contents($importersPath));
$writers = smvScanTree($pub, $writeNeedle);
ok('derived at least 1 tblSongMedia writer file outside manage/ (vacuity check; found ' . count($writers) . ')', count($writers) >= 1);
ok("song_importers.php is a tblSongMedia writer (INSERT INTO tblSongMedia present)", preg_match($writeNeedle, $importersCode) === 1);
ok("song_importers.php ingest binds the 'admin' visibility value (imported media is never public)",
    strpos($importersCode, "\$visibility = 'admin'") !== false);
ok("song_importers.php ingest is gated by pp7_media_ingest_enabled (dormant by default; fail-closed)",
    strpos($importersCode, 'pp7_media_ingest_enabled') !== false);
ok("song_importers.php ingest binds Visibility in the INSERT column list",
    preg_match('/INSERT\s+INTO\s+tblSongMedia[^;]*Visibility/is', $importersCode) === 1);

/* ============================================================================
 * (d) HONESTY — video/image can never flip HasAudio/HasSheetMusic
 * ============================================================================ */
echo "\n-- (d) video/image are outside the flag-kind map (can't flip HasAudio/HasSheetMusic) --\n";
require_once $pub . '/includes/song_media_flags.php';
$flagKinds = songMediaFlagKinds();
$allFlagKinds = array_merge($flagKinds['HasAudio'] ?? [], $flagKinds['HasSheetMusic'] ?? []);
ok("'video' is in NEITHER songMediaFlagKinds() list", !in_array('video', $allFlagKinds, true));
ok("'image' is in NEITHER songMediaFlagKinds() list", !in_array('image', $allFlagKinds, true));

/* ============================================================================
 * (e) THE PURE SERVE DECISION — songMediaVisibilityRowAllowed / IsValid
 * ============================================================================ */
echo "\n-- (e) songMediaVisibilityRowAllowed() truth table + IsValid + fragment no-op --\n";

/* A stubbed entitlement map (plan G1e): only an 'editor' role holds edit_songs.
 * Defined BEFORE requiring the helper, which calls the global via function_exists
 * and does NOT load entitlements.php — so this stub is the resolver here. */
if (!function_exists('userHasEntitlement')) {
    function userHasEntitlement(string $entitlement, ?string $role): bool
    {
        return $entitlement === 'edit_songs' && $role === 'editor';
    }
}
require_once $pub . '/includes/song_media_visibility.php';

ok('rowAllowed(null, null) === true  (pre-migration NULL — anonymous keeps every existing row)', songMediaVisibilityRowAllowed(null, null) === true);
ok("rowAllowed('', null) === true    (empty string — treated as public)", songMediaVisibilityRowAllowed('', null) === true);
ok("rowAllowed('public', null) === true  (public — anonymous OK)", songMediaVisibilityRowAllowed('public', null) === true);
ok("rowAllowed('admin', null) === false  (admin, no role — denied)", songMediaVisibilityRowAllowed('admin', null) === false);
ok("rowAllowed('admin', '') === false    (admin, empty role — denied)", songMediaVisibilityRowAllowed('admin', '') === false);
ok("rowAllowed('admin', 'viewer') === false  (admin, non-curator role — denied)", songMediaVisibilityRowAllowed('admin', 'viewer') === false);
ok("rowAllowed('admin', 'editor') === true   (admin, curator with edit_songs — allowed)", songMediaVisibilityRowAllowed('admin', 'editor') === true);
ok("rowAllowed('org', null) === false   (unknown future value, fail-CLOSED on the serve axis)", songMediaVisibilityRowAllowed('org', null) === false);
ok("rowAllowed('org', 'editor') === true  (unknown value still needs a curator — never silently public)", songMediaVisibilityRowAllowed('org', 'editor') === true);
ok("rowAllowed('ADMIN', 'viewer') === false (case-folded value, non-curator — denied)", songMediaVisibilityRowAllowed('ADMIN', 'viewer') === false);

ok("isValid('public') === true", songMediaVisibilityIsValid('public') === true);
ok("isValid('admin') === true", songMediaVisibilityIsValid('admin') === true);
ok("isValid('org') === false   (reserved but not yet a live vocabulary key)", songMediaVisibilityIsValid('org') === false);
ok("isValid('') === false", songMediaVisibilityIsValid('') === false);
ok("isValid('nonsense') === false", songMediaVisibilityIsValid('nonsense') === false);

/* Fragment no-op proof — DB-reachable only (the mysqli type hint blocks a fake;
 * un-migrated is the default CI state, so the column is absent and both
 * fragments must be ''). Skipped cleanly without a DB — the pure truth table
 * above is the load-bearing proof. */
$dbForFragments = null;
$credFile = $repo . '/appWeb/.auth/db_credentials.php';
if (is_file($credFile)) {
    require_once $credFile;
    require_once $pub . '/includes/db_mysql.php';
    try { $dbForFragments = getDbMysqli(); } catch (\Throwable $_e) { $dbForFragments = null; }
}
if ($dbForFragments instanceof \mysqli) {
    $colExists = songMediaVisibilityColumnExists($dbForFragments);
    $filter    = songMediaVisibilityPublicFilterSql($dbForFragments);
    $select    = songMediaVisibilitySelectFragment($dbForFragments);
    if ($colExists) {
        ok("filter fragment is the AND clause when the column exists", $filter === " AND Visibility = 'public'");
        ok("select fragment is ', Visibility' when the column exists", $select === ', Visibility');
    } else {
        ok("filter fragment is '' on an un-migrated install (verified no-op)", $filter === '');
        ok("select fragment is '' on an un-migrated install (verified no-op)", $select === '');
    }
} else {
    echo "  (fragment no-op check SKIPPED — no reachable database; pure truth table above stands)\n";
}

/* ============================================================================
 * (c) PHP↔JS kind/cap LOCKSTEP — SongMediaStorage ↔ media-tab.js KIND_META
 * ============================================================================
 * KIND_META's own comment says its sizeCap "mirrors SongMediaStorage::SIZE_CAPS"
 * — a rule-#35 keep-in-sync comment finally given its mechanism. Parses BOTH
 * files from the tree and asserts every server kind has a client block whose cap
 * agrees (retro-covering the four original kinds too). */
echo "\n-- (c) PHP↔JS kind/cap lockstep (SongMediaStorage ↔ media-tab.js) --\n";
$smsCode = smvPhpCode((string)file_get_contents($pub . '/includes/SongMediaStorage.php'));
$mtCode  = (string)file_get_contents($pub . '/manage/editor/v2/media-tab.js');

/** Kind identifiers inside a `const NAME = [ 'a', 'b' ];` PHP array. */
$phpKindList = static function (string $code, string $const): array {
    if (!preg_match('/const\s+' . $const . '\s*=\s*\[(.*?)\]/s', $code, $m)) { return []; }
    preg_match_all("/'([a-z\\-]+)'/", $m[1], $mm);
    return $mm[1];
};
/** kind => cap-in-MiB from a `'kind' => N * 1024 * 1024` PHP map. */
$phpCaps = static function (string $code): array {
    if (!preg_match('/const\s+SIZE_CAPS\s*=\s*\[(.*?)\];/s', $code, $m)) { return []; }
    preg_match_all("/'([a-z\\-]+)'\\s*=>\\s*([0-9]+)\\s*\\*\\s*1024\\s*\\*\\s*1024/", $m[1], $mm, PREG_SET_ORDER);
    $out = [];
    foreach ($mm as $r) { $out[$r[1]] = (int)$r[2]; }
    return $out;
};
/** kind => cap-in-MiB from media-tab.js's `'kind': { … sizeCap: N * 1024 * 1024 }`. */
$jsCaps = static function (string $code): array {
    if (!preg_match('/KIND_META\s*=\s*\{(.*)\n\};/sU', $code, $m)) { return []; }
    preg_match_all("/'([a-z\\-]+)'\\s*:\\s*\\{[^}]*sizeCap:\\s*([0-9]+)\\s*\\*\\s*1024\\s*\\*\\s*1024/s", $m[1], $mm, PREG_SET_ORDER);
    $out = [];
    foreach ($mm as $r) { $out[$r[1]] = (int)$r[2]; }
    return $out;
};

$serverKinds = array_merge($phpKindList($smsCode, 'FS_KINDS'), $phpKindList($smsCode, 'DB_KINDS'));
$serverCaps  = $phpCaps($smsCode);
$clientCaps  = $jsCaps($mtCode);
ok('parsed at least 6 server media kinds (audio/video/image/sheet-music/midi/musicxml; found ' . count($serverKinds) . ')', count($serverKinds) >= 6);
ok('parsed at least 6 server SIZE_CAPS (found ' . count($serverCaps) . ')', count($serverCaps) >= 6);
ok('parsed at least 6 client KIND_META caps (found ' . count($clientCaps) . ')', count($clientCaps) >= 6);
foreach ($serverKinds as $k) {
    ok("media-tab.js KIND_META has server kind '{$k}'", isset($clientCaps[$k]));
    if (isset($serverCaps[$k], $clientCaps[$k])) {
        ok("size cap for '{$k}' agrees (server {$serverCaps[$k]} MiB == client {$clientCaps[$k]} MiB)",
            $serverCaps[$k] === $clientCaps[$k]);
    }
}

/* ---- summary ---- */
echo "\n" . ($failed === 0
    ? "PASS: {$passed} assertion(s), the P4 media serving gate is wired on every public read.\n"
    : "FAIL: {$failed} of " . ($passed + $failed) . " assertion(s) failed:\n  - " . implode("\n  - ", $failures) . "\n");
exit($failed === 0 ? 0 : 1);
