<?php

declare(strict_types=1);

/**
 * iHymns — the stage-3 re-widen: the prune decision, and the migration (#1695)
 * ============================================================================
 *
 * Two layers, deliberately:
 *
 *  §1 PURE — `entitlementOverridesPruneIfEquals()` as a decision table. No
 *     database. This is where all the risk lives, because the function decides
 *     whether to overrule an operator, and it must get that wrong in the SAFE
 *     direction every time.
 *
 *  §2 BEHAVIOURAL — the migration driven against a real MariaDB, because the
 *     no-op it fixes is invisible in code review by construction: the PHP says
 *     editor+, the row says admin+, and the row wins. Only a real read proves
 *     which one the app obeys.
 *
 * WHY THIS TEST EXISTS AT ALL
 * ---------------------------
 * #1695's scope says "restore delete_songs to editor+ — reverting #1693's three
 * coordinated edits". Doing exactly that, and no more, produces a feature that
 * reviews as complete and **changes nothing** on any install where
 * /manage/entitlements has ever been saved: `effectiveEntitlements()` lets a
 * stored key replace the default outright, and `saveEntitlementOverrides()`
 * writes the whole map on every save. The issue does not mention this. #1590 hit
 * the identical trap on the identical setting.
 *
 * THE ASYMMETRY THAT SHAPES §1
 * ----------------------------
 * In the database, "the interim value stage 1 shipped" and "the operator
 * deliberately locked deletion to admins" are the SAME BYTES. Nothing records
 * intent. So the two possible mistakes are not equal:
 *
 *   - prune too little → an operator keeps a restriction they may not want, and
 *     fixes it with one tick box. Visible, reversible, no privilege gained.
 *   - prune too much  → every curator silently gains song deletion on a site
 *     that explicitly decided otherwise. Invisible, and it WIDENS a privilege.
 *
 * Every assertion below is oriented to that: when in doubt, preserve.
 *
 * @see appWeb/public_html/includes/entitlements.php     the pure helper
 * @see appWeb/.sql/migrate-delete-songs-rewiden.php     the migration
 * @see .claude/plan-1695-stage3.md                      §3
 */

define('IHYMNS_MIGRATION_NO_AUTORUN', true);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/entitlements.php';

$pass = 0;
$fail = 0;

function r_ok(string $what, bool $cond, string $why = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; return; }
    $fail++;
    echo "  FAIL  $what\n";
    if ($why !== '') { echo "        $why\n"; }
}

/* ======================================================================
 * §1 — the prune decision, pure
 * ====================================================================== */

echo "\n== §1: entitlementOverridesPruneIfEquals() decision table ==\n";

$interim = ['admin', 'global_admin'];

/* THE ONE CASE THAT SHOULD PRUNE. */
[$out, $changed] = entitlementOverridesPruneIfEquals(
    ['delete_songs' => ['admin', 'global_admin'], 'edit_songs' => ['editor']],
    'delete_songs',
    $interim
);
r_ok('the exact stage-1 interim value IS pruned', $changed === true);
r_ok('…and the key is genuinely gone', !array_key_exists('delete_songs', $out));
r_ok('…and every OTHER key is left untouched',
    ($out['edit_songs'] ?? null) === ['editor'],
    'a prune that disturbs neighbouring keys would silently rewrite unrelated permissions');

/* Order and duplicates are storage noise — JSON key order is not something a
   caller controls, and the two spellings mean the same permission. */
[, $c2] = entitlementOverridesPruneIfEquals(
    ['delete_songs' => ['global_admin', 'admin']], 'delete_songs', $interim);
r_ok('the same roles in a DIFFERENT ORDER still prune', $c2 === true);

[, $c3] = entitlementOverridesPruneIfEquals(
    ['delete_songs' => ['admin', 'admin', 'global_admin']], 'delete_songs', $interim);
r_ok('a duplicated role still prunes', $c3 === true);

/* ---- EVERYTHING BELOW MUST BE PRESERVED. This is the safe direction. ---- */

$preserve = [
    'a global_admin-only lockdown'          => ['global_admin'],
    'an already-widened editor+ value'      => ['editor', 'admin', 'global_admin'],
    'a custom set including editor'         => ['editor', 'global_admin'],
    'an empty list (deletion off entirely)' => [],
    'a superset of the interim'             => ['admin', 'global_admin', 'user'],
];
foreach ($preserve as $label => $value) {
    [$o, $c] = entitlementOverridesPruneIfEquals(
        ['delete_songs' => $value], 'delete_songs', $interim);
    r_ok("PRESERVED: $label",
        $c === false && array_key_exists('delete_songs', $o) && $o['delete_songs'] === $value,
        'pruning this would silently overrule a deliberate operator decision');
}

/* Shapes that must not throw or "repair" anything. */
[$o4, $c4] = entitlementOverridesPruneIfEquals(['edit_songs' => ['editor']], 'delete_songs', $interim);
r_ok('an ABSENT key is a clean no-op', $c4 === false && $o4 === ['edit_songs' => ['editor']]);

[$o5, $c5] = entitlementOverridesPruneIfEquals([], 'delete_songs', $interim);
r_ok('an EMPTY map is a clean no-op', $c5 === false && $o5 === []);

foreach ([['a string', 'admin'], ['null', null], ['an int', 1], ['a bool', true]] as [$label, $bad]) {
    [$o6, $c6] = entitlementOverridesPruneIfEquals(['delete_songs' => $bad], 'delete_songs', $interim);
    r_ok("a NON-ARRAY value ($label) is preserved, not pruned and not repaired",
        $c6 === false && array_key_exists('delete_songs', $o6));
}

/* Idempotence: running twice must not differ from running once. */
[$once, ]  = entitlementOverridesPruneIfEquals(['delete_songs' => $interim], 'delete_songs', $interim);
[$twice, $cTwice] = entitlementOverridesPruneIfEquals($once, 'delete_songs', $interim);
r_ok('IDEMPOTENT — a second run changes nothing', $cTwice === false && $twice === $once);

/* ======================================================================
 * §2 — the migration against a real database
 * ====================================================================== */

echo "\n== §2: the migration, against real MariaDB ==\n";

$sock = '/var/run/mysqld/mysqld.sock';

/* PROBE BY CONNECTING, NOT BY LOOKING FOR THE SOCKET FILE.
 *
 * This guard originally read `file_exists($sock)`, and that is wrong in the
 * one direction that matters: a Unix socket file OUTLIVES the server that
 * created it. When mariadbd stops (or is reaped by the container), the path
 * is still there, `file_exists()` still returns true, and this block ran
 * anyway — turning a legitimate "no database available, skipping" into an
 * uncaught mysqli_sql_exception and a RED suite on completely correct code.
 *
 * Observed, not theorised: it happened here, and a guard that fails on
 * correct code is precisely what rule #34 says gets weakened or deleted
 * instead of fixed. The only reliable probe for "is the server up" is to
 * try to talk to it. */
$db = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = @new mysqli('localhost', 'root', '', '', 0, $sock);
} catch (\Throwable $_e) {
    $db = null;
}

if ($db === null) {
    echo "  SKIP  MariaDB is not reachable on $sock — §2 not run.\n";
    echo "        (This is a REAL gap, not a pass. The no-op this migration fixes\n";
    echo "         is invisible without a live read. Start it with:\n";
    echo "         mariadbd --user=mysql --socket=$sock &)\n";
} else {
    $db->query('DROP DATABASE IF EXISTS ihymns_t1695');
    $db->query('CREATE DATABASE ihymns_t1695');
    $db->select_db('ihymns_t1695');
    $db->query("CREATE TABLE tblAppSettings (
        SettingKey   VARCHAR(100) PRIMARY KEY,
        SettingValue TEXT,
        Description  TEXT
    )");

    require_once dirname(__DIR__, 2) . '/appWeb/.sql/migrate-delete-songs-rewiden.php';

    $setOverrides = static function (mysqli $db, ?string $json): void {
        $db->query("DELETE FROM tblAppSettings WHERE SettingKey = 'entitlements_overrides'");
        if ($json !== null) {
            $s = $db->prepare('INSERT INTO tblAppSettings (SettingKey, SettingValue) VALUES (?, ?)');
            $k = 'entitlements_overrides';
            $s->bind_param('ss', $k, $json);
            $s->execute();
            $s->close();
        }
    };
    $readOverrides = static function (mysqli $db): ?array {
        $r = $db->query("SELECT SettingValue FROM tblAppSettings WHERE SettingKey = 'entitlements_overrides'");
        $row = $r->fetch_row();
        return $row === null ? null : json_decode((string)$row[0], true);
    };

    /* (a) the real-world case: a site that saved the page during stage 1 */
    $setOverrides($db, json_encode([
        'delete_songs' => ['admin', 'global_admin'],
        'edit_songs'   => ['editor', 'admin', 'global_admin'],
    ]));
    $res = migrateDeleteSongsRewiden($db);
    $after = $readOverrides($db);
    r_ok('(a) a stored stage-1 interim value is pruned from the live row', $res['pruned'] === true);
    r_ok('(a) …the key is gone from the stored JSON', !array_key_exists('delete_songs', $after ?? []));
    r_ok('(a) …and the neighbouring key survived verbatim',
        ($after['edit_songs'] ?? null) === ['editor', 'admin', 'global_admin']);

    /* (b) the case that MUST be left alone */
    $setOverrides($db, json_encode(['delete_songs' => ['global_admin']]));
    $res = migrateDeleteSongsRewiden($db);
    $after = $readOverrides($db);
    r_ok('(b) a deliberate global_admin lockdown is PRESERVED', $res['pruned'] === false);
    r_ok('(b) …and the stored value is byte-identical afterwards',
        ($after['delete_songs'] ?? null) === ['global_admin'],
        'this is the privilege-widening failure — the expensive direction');

    /* (c) never saved the page at all */
    $setOverrides($db, null);
    $res = migrateDeleteSongsRewiden($db);
    r_ok('(c) an install that never saved the page is a clean no-op',
        $res['pruned'] === false && $res['reason'] === 'no-overrides-row');

    /* (d) corrupt JSON must not be "repaired" */
    $setOverrides($db, '{not json at all');
    $res = migrateDeleteSongsRewiden($db);
    $raw = $db->query("SELECT SettingValue FROM tblAppSettings WHERE SettingKey='entitlements_overrides'")
              ->fetch_row()[0];
    r_ok('(d) unparseable JSON is reported, not rewritten', $res['reason'] === 'unparseable');
    r_ok('(d) …and the bytes are untouched', $raw === '{not json at all',
        'a migration that repairs a blob it cannot parse is how a permission set disappears');

    /* (e) idempotence against the live row */
    $setOverrides($db, json_encode(['delete_songs' => ['admin', 'global_admin']]));
    migrateDeleteSongsRewiden($db);
    $res2 = migrateDeleteSongsRewiden($db);
    r_ok('(e) running the migration twice is safe', $res2['pruned'] === false);

    /* (f) the sentinel the probe reads */
    migrateDeleteSongsRewidenSentinel($db);
    $seen = $db->query("SELECT 1 FROM tblAppSettings WHERE SettingKey='delete_songs_rewiden_applied'")
               ->fetch_row() !== null;
    r_ok('(f) the sentinel row is written, so the card can flip to applied', $seen);
    migrateDeleteSongsRewidenSentinel($db);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM tblAppSettings WHERE SettingKey='delete_songs_rewiden_applied'")
                   ->fetch_row()[0];
    r_ok('(f) …and writing it twice does not duplicate it', $cnt === 1);

    $db->query('DROP DATABASE ihymns_t1695');
    $db->close();
}

/* ======================================================================
 * §3 — who gets told (#1695 notification targeting)
 * ====================================================================== */

echo "\n== §3: songSoftDeleteNotifyTargets() ==\n";

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_soft_delete.php';

$roles = ['admin', 'global_admin'];
$users = [
    ['id' => 1, 'role' => 'user'],
    ['id' => 2, 'role' => 'editor'],
    ['id' => 3, 'role' => 'admin'],
    ['id' => 4, 'role' => 'global_admin'],
];

$t = songSoftDeleteNotifyTargets($roles, $users, null);
r_ok('only reviewer roles are notified', $t === [3, 4],
    'got: [' . implode(', ', $t) . '] — a curator or a plain user being notified is noise');

$t = songSoftDeleteNotifyTargets($roles, $users, 3);
r_ok('the ACTOR is excluded from their own notification', $t === [4],
    'telling somebody about their own action is how a safety notification gets muted');

$t = songSoftDeleteNotifyTargets($roles, [['id' => 3, 'role' => 'admin']], 3);
r_ok('a lone admin deleting a song notifies NOBODY', $t === []);

$t = songSoftDeleteNotifyTargets($roles, [], null);
r_ok('no users at all is empty, not an error', $t === []);

$t = songSoftDeleteNotifyTargets([], $users, null);
r_ok('an entitlement held by NO role notifies nobody', $t === []);

/* The audience follows the LIVE entitlement map, so an operator re-aiming
   purge_songs changes who is told with no second list to maintain. */
$t = songSoftDeleteNotifyTargets(['global_admin'], $users, null);
r_ok('a narrowed purge_songs narrows the audience', $t === [4]);
$t = songSoftDeleteNotifyTargets(['editor', 'admin', 'global_admin'], $users, null);
r_ok('a widened purge_songs widens the audience', $t === [2, 3, 4]);

$t = songSoftDeleteNotifyTargets($roles, [
    ['id' => 3, 'role' => 'admin'], ['id' => 3, 'role' => 'admin'],
], null);
r_ok('a duplicated user is notified once', $t === [3]);

$t = songSoftDeleteNotifyTargets($roles, [
    ['id' => 0, 'role' => 'admin'], ['id' => -5, 'role' => 'admin'],
], null);
r_ok('invalid user ids are dropped', $t === []);

/* ======================================================================
 * §4 — the push kind is offered only to those who can receive it
 * ====================================================================== */

echo "\n== §4: push-kind audience (#1695) ==\n";

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/web_push.php';

$kinds = webPushKinds();
r_ok('the song_deleted kind is registered', array_key_exists('song_deleted', $kinds));
r_ok('…and names purge_songs as its audience',
    ($kinds['song_deleted'][3] ?? null) === 'purge_songs',
    'without the 4th element every user is offered a switch they can never use');
r_ok('the pre-existing kinds stay UNIVERSAL (no 4th element)',
    !isset($kinds['announcement'][3]) && !isset($kinds['test'][3]),
    'adding an audience to an existing kind would silently hide it from users who have it on');

r_ok('an admin is offered the restricted kind',
    array_key_exists('song_deleted', webPushKindsForRole('admin')));
r_ok('a plain user is NOT offered it',
    !array_key_exists('song_deleted', webPushKindsForRole('user')));
r_ok('…but still gets the universal kinds',
    array_key_exists('announcement', webPushKindsForRole('user')));
r_ok('an anonymous viewer gets only the universal kinds',
    !array_key_exists('song_deleted', webPushKindsForRole(null))
        && array_key_exists('announcement', webPushKindsForRole(null)),
    'fail CLOSED on restricted kinds when the role is unknown');

echo "\n";
echo $fail === 0
    ? "All #1695 re-widen assertions passed ($pass).\n"
    : "FAILURES: $fail (passed $pass)\n";

exit($fail === 0 ? 0 : 1);
