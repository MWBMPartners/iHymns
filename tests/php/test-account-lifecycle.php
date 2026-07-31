<?php

declare(strict_types=1);

/**
 * iHymns — the account lifecycle, CALLED not read (#1698)
 * =======================================================
 *
 * ELI5
 * ----
 * Accounts can be switched off or erased, and content they own has to lock
 * accordingly. This file hands the deciding functions made-up accounts — "off",
 * "erased", "never had an owner", "a state we have never heard of" — and checks
 * the answers. It also reads every user foreign key out of `schema.sql` and asks
 * the erasure core what it would do with each, so a table added next year is
 * covered without anybody remembering this file exists. No MySQL involved.
 *
 * WHY IT IS BEHAVIOURAL AND NOT A SOURCE SCAN
 * -------------------------------------------
 * Four consecutive rounds on this branch shipped a guard that was GREEN while
 * the thing it guarded was broken, and every one had the same cause: source
 * inspection used as evidence for a property that has a runtime handle.
 * `tests/php/test-transaction-fatal.php` is the worked example — two successive
 * versions asserted that a predicate's body MENTIONED `1213`, and a reviewer
 * replaced the body with `return false;` while leaving the number in a now-dead
 * array. Everything stayed green while every call site resumed swallowing
 * deadlocks.
 *
 * So #1698 was shaped to leave runtime handles. `userStateFromRow()`,
 * `userContentLocked()`, `userStateLockReason()`, `setlistCollabApplyOwnerLock()`,
 * `accountEraseActionFor()` and `accountEraseUsernamePlaceholder()` are all PURE,
 * and this file CALLS them. A function cannot pass here by containing the right
 * words, only by answering correctly.
 *
 * THE ONE PROPERTY THAT MATTERS MOST — TOTALITY (§7)
 * --------------------------------------------------
 * The erasure is DERIVED: every foreign key referencing `tblUsers(Id)` carries
 * an `ON DELETE` rule that already declares whether those rows belong to the
 * user, and `accountEraseActionFor()` turns that declaration into an
 * instruction. Section 7 parses `schema.sql` — the TREE, never a list typed here
 * (rule #34) — feeds all 56 keys through the classifier, and asserts the
 * partition is exhaustive with no refusals. A table with a user FK added in
 * 2027 is therefore covered the day it lands, and if somebody adds one with a
 * RESTRICT rule it goes red here AND in test-account-delete-fk-coverage.php.
 *
 * WHAT THIS FILE CANNOT CATCH, so its tick is not over-read
 * ---------------------------------------------------------
 *  - Whether `accountErase()`'s six steps run in the right ORDER against a real
 *    database. The re-freeze MUST precede the set-list delete and the login-
 *    attempt scrub MUST precede the username scrub; both are argued in the
 *    source and neither is executable without MySQL.
 *  - Whether the tombstone UPDATE names every PII column `tblUsers` actually
 *    has. §8 pins it against schema.sql, which is the best available proxy.
 *  - Whether the migration applies, whether the derived DELETE/UPDATE statements
 *    parse on a live server, or whether the collaborator lock behaves in a
 *    browser. Those need the alpha deploy (see the PR's "not verified here").
 *
 * MUTATION RECORD (rule #34 — a guard whose first green run was never
 * challenged has, in this repo, repeatedly been silently wrong)
 * ------------------------------------------------------------------
 * Each mutant was applied to the REAL tree, then restored from a copy taken
 * beforehand — never `git checkout --`, which an agent on this branch used to
 * destroy two files of uncommitted work — with `git status --porcelain` and file
 * checksums confirmed afterwards. Each is recorded with the assertions it turned
 * red, because "it went red" without saying WHICH is how an over-broad guard
 * gets mistaken for a precise one.
 *
 *  M1  `userContentLocked()` → `return false;` → RED ×8 (§2 all four states, §4
 *      three locked-collaborator cases, §4's lock-reason pairing).
 *  M2  `userContentLocked()` → `$state === 'deleted'` (the plausible wrong
 *      answer: forgetting that 'disabled' and 'absent' lock too) → RED ×6,
 *      naming 'disabled' and 'absent' specifically.
 *  M3  `accountEraseActionFor()` → `return 'keep';` first → RED ×13, led by the
 *      §6 action table and every refusal case.
 *  M4  `accountEraseActionFor()`'s SET NULL branch → always `'null'` → RED ×6:
 *      the attribution keys (`tblSongRevisions.UserId`, `tblActivityLog.UserId`,
 *      `tblSetlistTemplates.CreatedBy`, `tblSharedSetlists.OwnerUserId`) would
 *      be blanked, i.e. the "nothing is orphaned" decision reversed.
 *  M5  `accountEraseActionFor()`'s default → `'keep'` instead of `'refuse'`
 *      → RED ×4 (the four explicit refusal cases in §6). WORTH RECORDING: the
 *      §7 TOTALITY assertion stayed GREEN, because no live FK is RESTRICT —
 *      which is exactly why §6 exercises the refusal directly rather than
 *      trusting the schema to contain an example.
 *  M6  drop `#` from the tombstone placeholder (`'deleted' . $userId`) → RED ×2,
 *      one per username-validation regex found in the tree: `deleted41` is a
 *      perfectly mintable username, so the second erasure on an install would
 *      have collided on `Username UNIQUE`.
 *  M7  `userStateFromRow()` → consult `IsActive` FIRST → RED ×2: an erased
 *      account (`IsActive=0`, `Status='deleted'`) reports 'disabled', which is
 *      the reactivation guard silently disarmed.
 *  M8  `userStateFromRow()` → throw on an unrecognised Status → RED ×1. First
 *      run it took the whole FILE down with an uncaught throw rather than naming
 *      the rule; §1's fallback assertion is wrapped in a try for that reason —
 *      "the suite exploded" tells a future reader far less than "this property
 *      broke".
 *  M9  `setlistCollabApplyOwnerLock()` → also set `canRead = false` → RED ×4.
 *      This is the owner's decision in one assertion: an innocent collaborator
 *      must not be punished for somebody else's account closing.
 *  M10 `setlistCollabApplyOwnerLock()` → return `$access` untouched → RED ×4.
 *  M11 delete one behavioural column from `accountEraseBehaviouralColumns()`
 *      → **GREEN on the first attempt.** §3 asserted every entry was REAL, which
 *      does not imply none went MISSING; an erased user's usage telemetry would
 *      have stayed keyed to their tombstone with nothing reporting it. §3 gained
 *      the named subset check (a POLICY assertion — see the note there — because
 *      "which columns are behavioural" is not derivable from any tree), and M11
 *      is now RED ×1.
 *  M12 typo a behavioural key (`tblsearchqueries.userid` → `…userid2`) → RED ×4:
 *      §3's reality check plus §6's behavioural cases. Without §3 this typo
 *      would have silently downgraded that column from NULL to KEEP.
 *  M13 remove `StatusChangedAt` from schema.sql → RED ×2 (§8's DDL mirror, and
 *      test-schema-coverage.php).
 *  M14 change the migration's COMMENT text on `Status` by one word → RED ×1 in
 *      §8. Rule #19 requires the DDL be byte-identical to its schema.sql mirror,
 *      and NOTHING else in CI compares the COMMENT — test-schema-coverage.php
 *      only checks that the column NAME appears.
 *  M15 add a fictional `fk_Future_User … ON DELETE RESTRICT` to schema.sql
 *      → RED ×2 (§7 refuses it; test-account-delete-fk-coverage.php flags it
 *      too) — proof the parse reads the real file rather than trivially agreeing
 *      with itself.
 *  M16 delete `userOwnerState()`'s null/zero guard → RED ×1 (fatal). The two
 *      answers that need no query must be given before the query.
 *  M17 `setlistCollabResolveAccess()` returns `$access` without applying the
 *      lock → RED ×2 in §10. Sections 1–9 alone would pass in full against a
 *      build where the lock is flawless and nothing invokes it.
 *  M18 the no-row answer in `userOwnerState()` → `'active'` → **GREEN on the
 *      first attempt.** An unconnected mysqli throws long before the null branch
 *      is evaluated, so the single most fail-open-able line in the file had no
 *      runtime handle at all — a hard-deleted user (the pre-#1698 behaviour)
 *      would have unlocked everything they ever owned. Extracted as the pure
 *      `userStateFromOwnerRow()`; now RED ×1.
 *  M19 make the ERASED badge render identically to the DISABLED one → **GREEN on
 *      the first attempt**, when the mapping was inline in `/manage/users.php`
 *      and only source-scanned. That mapping IS the owner's "clearly
 *      distinguished" requirement, so it moved to the pure `userStateBadge()`;
 *      now RED ×1 on the three-distinct-labels assertion.
 *  M20 the erase type-to-confirm comparison → `return true;` → **GREEN on the
 *      first attempt** while inline in the page handler, i.e. the confirmation
 *      could be disarmed entirely without a single assertion noticing. Extracted
 *      as `userEraseConfirmMatches()`; now RED ×3.
 *  M21 delete that function's empty-expected-username guard → RED ×1.
 *  M22 delete the `publish_public_templates` gate from the CREATE path (leaving
 *      it on update) → RED ×1. A gate on one of two write paths is not a gate.
 *  M23 delete `|| $tplCanManageAny` from the LIST's `canEdit` → **GREEN on the
 *      first attempt**, and green AGAIN on the first attempted fix: the
 *      entitlement is still RESOLVED there, so counting the entitlement name
 *      proved nothing, and a consumption threshold set one below the true count
 *      tolerates exactly the deletion it guards against. This is the "a gate
 *      whose answer is DISCARDED is decoration" defect (#1679 A12) reproduced
 *      twice in one session. Now RED ×1. The consequence it protects against:
 *      an admin holds the override and the list tells their client there is no
 *      button to draw.
 * CORRECT-BUT-DIFFERENT (must stay GREEN — rule #34's other failure mode, which
 * ends in a guard being weakened or deleted rather than fixed):
 *  N1  reword every lock-reason sentence → green (§5 asserts non-empty, distinct
 *      and leak-free, never the prose).
 *  N2  reorder `accountEraseBehaviouralColumns()` → green (compared as a set).
 *  N3  add a fourth behavioural column that really is SET NULL in schema.sql
 *      → green (the list may GROW — that is the safe direction; only shrinking
 *      is caught).
 *  N4  rename the tombstone placeholder's prefix `deleted#` → `erased#`
 *      → green (§6 asserts unmintability, not the word).
 *  N5  rename the ERASED badge's label to "Removed" → green (§4c asserts three
 *      DISTINCT labels, not three particular ones).
 *
 *   php tests/php/test-account-lifecycle.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/user_status.php
 * @see appWeb/public_html/includes/account_lifecycle.php
 * @see appWeb/public_html/includes/setlist_collab.php
 * @see appWeb/.sql/schema.sql
 * @see tests/php/test-account-delete-fk-coverage.php  (the reach half)
 * @link https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html
 */

$ROOT = dirname(__DIR__, 2);
$PUB  = $ROOT . '/appWeb/public_html';

/* user_status.php and setlist_collab.php are pure enough to load bare.
   account_lifecycle.php pulls in db_mysql.php + SharedSetlist.php, which define
   functions but open no connection at include time — nothing below connects. */
require_once $PUB . '/includes/user_status.php';
require_once $PUB . '/includes/setlist_collab.php';
require_once $PUB . '/includes/account_lifecycle.php';

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        " . str_replace("\n", "\n        ", $detail) . "\n"; }
    }
}

/**
 * Every FK `schema.sql` declares against `tblUsers(Id)` — DERIVED FROM THE TREE,
 * never typed here (rule #34).
 *
 * Read with `file_get_contents()` on an explicit path, deliberately: `rg`
 * silently skips dot-directories, so `appWeb/.sql/` is invisible to it and any
 * "I grepped and found nothing" reasoning about this file is worthless.
 *
 * SQL comments are stripped FIRST so a constraint mentioned in prose cannot be
 * counted — the same prose-satisfies-the-guard trap this repo keeps re-shipping.
 * The owning table is the nearest preceding `CREATE TABLE` / `ALTER TABLE`, so a
 * constraint added by a standalone `ALTER` later in the file is still attributed
 * correctly.
 *
 * The `ON DELETE` clause is conventionally on the LINE AFTER the `REFERENCES`,
 * so the pattern spans newlines. A single-line pattern reads every one of these
 * as "no rule" — the precise mistake the #1698 planning pass made on its first
 * FK scan, which reported all 56 as RESTRICT.
 *
 * @return list<array{0:string,1:string,2:string,3:string}> [table, column, constraint, rule]
 */
function schemaUserFks(string $schemaPath): array
{
    $raw = (string)file_get_contents($schemaPath);
    $sql = (string)preg_replace('#/\*[\s\S]*?\*/#', ' ', $raw);
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);

    $hits = [];
    if (!preg_match_all(
        '/CONSTRAINT\s+`?(\w+)`?\s+FOREIGN\s+KEY\s*\(\s*`?(\w+)`?\s*\)\s*'
        . 'REFERENCES\s+`?tblUsers`?\s*\(\s*`?Id`?\s*\)\s*'
        . '(ON\s+DELETE\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))?/i',
        $sql,
        $hits,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        return [];
    }

    $out = [];
    foreach ($hits as $hit) {
        $head = substr($sql, 0, $hit[0][1]);
        if (!preg_match_all(
            '/(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE)\s+`?(\w+)`?/i',
            $head,
            $owners
        )) {
            continue;
        }
        $rule = strtoupper((string)preg_replace('/\s+/', ' ', trim($hit[3][0] ?? '')));
        $rule = (string)preg_replace('/^ON DELETE /', '', $rule);
        $out[] = [(string)end($owners[1]), $hit[2][0], $hit[1][0], $rule];
    }
    return $out;
}

/* ======================================================================== *
 * 1 — userStateFromRow(): the resolution order IS the function
 * ======================================================================== */

echo "1 — classifying an account from the two columns a reader already has\n";

ok('a live account is active', userStateFromRow(1, 'active') === 'active');
ok('a switched-off account is disabled', userStateFromRow(0, 'disabled') === 'disabled');
ok('an erased account is deleted', userStateFromRow(0, 'deleted') === 'deleted');

/* THE ORDER. Status wins where it speaks, because it is the only column that
   can say 'deleted' at all. Reading IsActive first makes a tombstone report as
   merely disabled — and the reactivation guard, which is the ONE thing standing
   between an admin and re-enabling an identity that no longer exists, keys on
   exactly that word. */
ok('a recognised Status WINS over IsActive (a tombstone is never merely disabled)',
   userStateFromRow(1, 'deleted') === 'deleted',
   'IsActive said 1 and Status said deleted; got ' . userStateFromRow(1, 'deleted'));
ok('…and the same in the other direction',
   userStateFromRow(0, 'active') === 'active');

/* THE UN-MIGRATED INSTALL. Migrations here are web-run and three docroots share
   one MySQL, so every caller passes null for Status until the card is run. This
   is not a nicety — it is the normal state of at least one docroot at any time. */
ok('no Status column yet: IsActive = 1 → active', userStateFromRow(1, null) === 'active');
ok('no Status column yet: IsActive = 0 → disabled', userStateFromRow(0, null) === 'disabled');
ok('no Status column and no IsActive either → disabled (fail CLOSED)',
   userStateFromRow(null, null) === 'disabled',
   'an unknown account must never read as active');

/* AN UNRECOGNISED VALUE. The column is a VARCHAR precisely so the vocabulary can
   grow (rule #20), which means it can physically hold a value this release has
   never heard of — from a newer deployment sharing the same MySQL, a restored
   backup, or a hand-edited row. Falling back is the only answer that neither
   takes the install down nor guesses "active". */
/* Wrapped, because "fatal" is one of the answers being ruled out and an
   uncaught throw would take the whole FILE down — reporting a crash rather than
   naming the property that broke. A mutant that made this throw did exactly
   that on its first run, and a guard whose failure mode is "the suite exploded"
   tells a future reader far less than one that says which rule was violated. */
$fallbackAnswers = null;
try {
    $fallbackAnswers = [userStateFromRow(1, 'suspended'), userStateFromRow(0, 'suspended')];
} catch (\Throwable $e) {
    $fallbackAnswers = 'threw ' . get_class($e);
}
ok('an unknown Status falls back to IsActive rather than being fatal',
   $fallbackAnswers === ['active', 'disabled'],
   'got ' . json_encode($fallbackAnswers)
   . '. The column is a VARCHAR so it can hold a value from a newer deployment '
   . 'sharing this MySQL, or a restored backup — taking the install down over a '
   . 'string is not a guard, and answering "active" is a fail-OPEN guess');
ok('…and an empty Status does the same', userStateFromRow(1, '') === 'active');
ok('Status is matched case- and whitespace-insensitively',
   userStateFromRow(1, '  DELETED ') === 'deleted',
   'a value written by another tool must not silently mean something else');

ok('the vocabulary is exactly the three documented states',
   array_keys(userStatusVocabulary()) === ['active', 'disabled', 'deleted'],
   json_encode(array_keys(userStatusVocabulary())));

/* ======================================================================== *
 * 2 — userContentLocked(): the allow-list of one
 * ======================================================================== */

echo "\n2 — which owner states lock the content they own\n";

ok('active → NOT locked', userContentLocked('active') === false);
ok('disabled → locked', userContentLocked('disabled') === true);
ok('deleted → locked', userContentLocked('deleted') === true);

/* THE #1698 ORPHAN, PINNED BY EXECUTION. `fk_Template_User` is ON DELETE SET
   NULL, so a template outlives its author with CreatedBy = NULL and was then
   editable by NOBODY — a public one could only be removed with direct database
   access. That was a documented, deliberately-unbuilt gap in
   setlistTemplateCanEdit()'s doc-block. It is now the SAME predicate as every
   other locked state rather than a special case somebody has to remember. */
ok('absent (no owner at all) → locked — the #1698 orphan, not a special case',
   userContentLocked(userStateAbsent()) === true);

/* Written as `!== 'active'` rather than a list of the locked states. The
   negative-list form silently GRANTS write to every state added later, which
   would mean a future 'suspended' unlocking everything it was invented to
   lock, with nothing anywhere going red. */
ok('a state this release has never heard of is locked, not unlocked',
   userContentLocked('suspended') === true && userContentLocked('') === true,
   'fail-open on an unknown state is how a gate stops being a gate');

/* ======================================================================== *
 * 3 — the behavioural NULL-list is real, and is the only thing NULLed
 * ======================================================================== */

echo "\n3 — the behavioural columns: derived, lower-cased, and really SET NULL\n";

$schemaPath = $ROOT . '/appWeb/.sql/schema.sql';
$userFks    = schemaUserFks($schemaPath);

ok('schema.sql parsed and yielded a plausible number of FKs to tblUsers(Id)',
   count($userFks) >= 50,
   count($userFks) . ' found. A broken parse returning [] would make every '
   . 'comparison below pass vacuously, so it is asserted separately');

$setNullKeys = [];
foreach ($userFks as [$t, $c, $_k, $rule]) {
    if ($rule === 'SET NULL') { $setNullKeys[strtolower($t) . '.' . strtolower($c)] = true; }
}

$behavioural = accountEraseBehaviouralColumns();
ok('the behavioural list is non-empty', $behavioural !== []);

/* ⚠ THIS ONE IS A POLICY ASSERTION, NOT A DERIVED ONE, AND IT IS NAMED HERE
   BECAUSE IT HAS TO BE. Rule #34 says derive a guard's list from the tree — but
   "which SET NULL columns are BEHAVIOURAL rather than attribution" is a
   judgement about what a person's data is, and no amount of schema parsing
   yields it. The tree cannot tell you that a search query is a record of
   somebody's behaviour and a song revision is a curatorial fact.

   It was added because a mutation run proved the section above INSUFFICIENT:
   deleting `tblSongUsageEvents.UserId` from the list left every assertion green
   while an erased user's presentation telemetry silently stayed keyed to their
   tombstone. "Each entry is real" does not imply "no entry went missing".

   Deliberately a SUBSET check, not equality: the list may GROW (more NULLing is
   the safe direction, and §7 separately pins the four attribution columns whose
   reclassification would be the harmful one), but it may not SHRINK without
   this going red — because shrinking silently retains data a previous release
   removed, which is the direction nobody would notice. */
foreach ([
    'tblsearchqueries.userid'   => 'what the user searched for',
    'tblsonghistory.userid'     => 'which songs the user opened',
    'tblsongusageevents.userid' => 'presentation / usage telemetry',
] as $mustHave => $what) {
    ok("the behavioural list still NULLs $what ($mustHave)",
       isset($behavioural[$mustHave]),
       'removing an entry silently downgrades that column from NULL to KEEP, so '
       . 'an erased account keeps a record of its own behaviour with nothing '
       . 'reporting the change. If this removal is deliberate, it is a privacy '
       . 'decision and changing this line is the right cost.');
}

/* A TYPO IN THIS LIST IS SILENT. `accountEraseActionFor()` looks the key up
   case-folded; a key that matches nothing simply means that column falls to
   'keep', i.e. a user's search history stays keyed to their tombstone with
   nothing reporting anything. This is the assertion that makes the list a
   mechanism rather than a hopeful comment (rule #35). */
foreach (array_keys($behavioural) as $key) {
    ok("behavioural key '$key' is a real SET NULL FK column in schema.sql",
        isset($setNullKeys[$key]),
        'a key matching no live column downgrades that column from NULL to KEEP '
        . 'with nothing going red. SET NULL columns are: '
        . implode(', ', array_slice(array_keys($setNullKeys), 0, 6)) . ' …');
    ok("…and '$key' is stored lower-cased (the lookup folds case)",
        $key === strtolower($key),
        'MySQL folds identifier case by server and filesystem, so the lookup '
        . 'lower-cases both sides — an upper-case key here would never match');
}

/* ======================================================================== *
 * 4 — setlistCollabApplyOwnerLock(): visible but locked
 * ======================================================================== */

echo "\n4 — a collaborator on a locked owner's set list\n";

$editAccess = [
    'role'       => 'collaborator',
    'ownerId'    => 42,
    'permission' => 'edit',
    'canRead'    => true,
    'canWrite'   => true,
];

$locked = setlistCollabApplyOwnerLock($editAccess, 'disabled');
ok('an edit collaborator loses canWrite when the owner is disabled',
   $locked['canWrite'] === false,
   json_encode($locked));
/* THE OWNER'S DECISION, in one assertion. Removing read access would punish the
   collaborator for somebody else's account closing — which is exactly what
   "already-shared items stay VISIBLE but LOCKED" rules out. */
ok('…but KEEPS canRead — visible, not withdrawn',
   $locked['canRead'] === true,
   'the collaborator is an innocent third party: ' . json_encode($locked));
ok('…and the response says WHY, so the client need not guess',
   ($locked['ownerState'] ?? null) === 'disabled' && ($locked['lockReason'] ?? '') !== '',
   json_encode($locked));

$erased = setlistCollabApplyOwnerLock($editAccess, 'deleted');
ok('the same holds for an erased owner',
   $erased['canWrite'] === false && $erased['canRead'] === true && $erased['ownerState'] === 'deleted');

$orphan = setlistCollabApplyOwnerLock($editAccess, userStateAbsent());
ok('…and for an owner row that is gone entirely',
   $orphan['canWrite'] === false && $orphan['canRead'] === true);

/* "The lock works" and "the lock is always on" produce identical results on
   every locked case above. Only the second is a bug, so the ACTIVE case is
   asserted to be a pass-through. */
$untouched = setlistCollabApplyOwnerLock($editAccess, 'active');
ok('an ACTIVE owner leaves the access array untouched',
   $untouched['canWrite'] === true && $untouched['canRead'] === true
   && $untouched['permission'] === 'edit' && $untouched['ownerId'] === 42,
   json_encode($untouched));
ok('…and adds no lock reason', ($untouched['lockReason'] ?? '') === '');

/* A view-only collaborator was already read-only; the lock must not accidentally
   promote or otherwise disturb them. */
$viewAccess = $editAccess;
$viewAccess['permission'] = 'view';
$viewAccess['canWrite']   = false;
$viewLocked = setlistCollabApplyOwnerLock($viewAccess, 'disabled');
ok('a view-only collaborator stays exactly as they were, plus the reason',
   $viewLocked['canWrite'] === false && $viewLocked['canRead'] === true
   && $viewLocked['permission'] === 'view');

/* ======================================================================== *
 * 4b — userOwnerState(): the branches that answer WITHOUT a database
 * ======================================================================== */

echo "\n4b — resolving an owner id, including the two answers needing no query\n";

/* `mysqli_init()` returns a real but UNCONNECTED handle: every statement on it
   fails. That makes it a precise probe for "did this path touch the database?"
   — the two guard branches must answer before it would, and the lookup branch
   must fail rather than invent an answer. */
$deadHandle = mysqli_init();

ok('a NULL owner id is "absent" without touching the database',
   userOwnerState($deadHandle, null) === userStateAbsent());
ok('a zero / negative owner id is "absent" too',
   userOwnerState($deadHandle, 0) === userStateAbsent()
   && userOwnerState($deadHandle, -1) === userStateAbsent(),
   'a SET NULL orphan and a malformed id are the same question, and neither is '
   . 'a reason to query');

$ownerThrew = null;
$ownerAnswer = null;
try {
    $ownerAnswer = userOwnerState($deadHandle, 99);
} catch (\Throwable $e) {
    $ownerThrew = get_class($e);
}
ok('an unreachable database never resolves an owner to "active"',
   $ownerAnswer !== 'active',
   'returned ' . var_export($ownerAnswer, true) . ' / threw ' . var_export($ownerThrew, true)
   . '. Answering "active" on a failed lookup would UNLOCK every collaborator '
   . 'write during a database blip — fail-open on the one gate #1698 added');

/* THE NO-ROW BRANCH. This exists as its own pure function because a mutant that
   changed the answer to 'active' survived EVERY assertion above: an unconnected
   mysqli throws long before the null check is evaluated, so the property had no
   runtime handle until it was extracted. A user id resolving to nothing means
   the account was hard-deleted at some point — the pre-#1698 behaviour, or a
   future legal purge — and reading that as "active" would unlock everything
   they had ever owned. */
ok('a user id that resolves to NO ROW is "deleted", never "active"',
   userStateFromOwnerRow(null) === 'deleted');
ok('…and a real row is classified exactly as userStateFromRow() would',
   userStateFromOwnerRow(['IsActive' => 1, 'Status' => 'active']) === 'active'
   && userStateFromOwnerRow(['IsActive' => 0, 'Status' => 'deleted']) === 'deleted'
   && userStateFromOwnerRow(['IsActive' => 0, 'Status' => null]) === 'disabled');
ok('…and a row missing the Status key entirely (un-migrated SELECT) still works',
   userStateFromOwnerRow(['IsActive' => 1]) === 'active');

/* ======================================================================== *
 * 4c — the two admin-surface decisions, made callable
 * ======================================================================== */

echo "\n4c — the admin badge and the erase type-to-confirm\n";

/* The owner asked for disabled and deleted to be CLEARLY DISTINGUISHED. Before
   #1698 both rendered as "Disabled". Asserted as three distinct labels rather
   than by matching the words, so rewording stays green (rule #34). */
$labels = [];
foreach (['active', 'disabled', 'deleted'] as $state) {
    [$cls, $label, $title] = userStateBadge($state);
    $labels[$state] = $label;
    ok("the $state badge has a class, a label and a tooltip",
       $cls !== '' && $label !== '' && $title !== '');
}
ok('the three states produce three DIFFERENT labels — the owner\'s "clearly distinguished"',
   count(array_unique($labels)) === 3, json_encode($labels));
ok('an unknown state degrades to the DISABLED presentation, never the active one',
   userStateBadge('suspended')[1] === userStateBadge('disabled')[1]
   && userStateBadge('suspended')[1] !== userStateBadge('active')[1],
   json_encode(userStateBadge('suspended')));

/* Type-to-confirm (#1218 §2). Extracted for the same reason as the no-row
   branch: an inline `!==` in the page handler had no runtime handle, and a
   mutant replacing it with `false` — which disarms the confirmation entirely —
   went completely unnoticed. */
ok('an exactly-typed username confirms', userEraseConfirmMatches('alice', 'alice') === true);
ok('…case-insensitively, because Username is utf8mb4_unicode_ci',
   userEraseConfirmMatches('ALICE', 'alice') === true,
   'the account really IS reachable by any casing, so a case-sensitive gate here '
   . 'refuses a correctly-typed name and teaches the admin the field is broken');
ok('…and surrounding whitespace is forgiven', userEraseConfirmMatches('  alice  ', 'alice') === true);
ok('a DIFFERENT username does not confirm', userEraseConfirmMatches('alicia', 'alice') === false);
ok('an EMPTY box does not confirm', userEraseConfirmMatches('', 'alice') === false);
ok('…and neither does an empty box against an empty expected name',
   userEraseConfirmMatches('', '') === false,
   'a caller that failed to load the target row must not be confirmed by a blank field');
ok('a prefix does not confirm', userEraseConfirmMatches('ali', 'alice') === false);

/* ======================================================================== *
 * 5 — the lock reason: present when locked, absent when not
 * ======================================================================== */

echo "\n5 — the user-facing lock reason\n";

ok('an active owner has no lock reason', userStateLockReason('active') === '');
foreach (['disabled', 'deleted', 'absent'] as $state) {
    ok("a $state owner has a non-empty lock reason", userStateLockReason($state) !== '');
}
/* Asserted as DIFFERENT rather than by matching prose: rewording a sentence must
   not turn a guard red (rule #34's other failure mode), but a closed account and
   a suspended one genuinely read differently to a viewer. */
ok('"closed" and "unavailable" are distinct sentences',
   userStateLockReason('deleted') !== userStateLockReason('disabled'));
/* It must not leak WHY. A shared link is readable by anyone holding the URL, and
   whose account was suspended, and when, is nobody else's business. */
foreach (['disabled', 'deleted', 'absent'] as $state) {
    $reason = strtolower(userStateLockReason($state));
    ok("the $state reason names no username, email or admin action",
        strpos($reason, '@') === false && strpos($reason, 'suspend') === false
        && strpos($reason, 'ban') === false,
        userStateLockReason($state));
}

/* ======================================================================== *
 * 6 — accountEraseActionFor(): the four actions, and the refusal
 * ======================================================================== */

echo "\n6 — turning an ON DELETE rule into an instruction\n";

ok('CASCADE means delete the row (it is the user\'s own)',
   accountEraseActionFor('tblUserFavorites', 'UserId', 'CASCADE') === 'delete');
ok('SET NULL on a behavioural column means NULL it',
   accountEraseActionFor('tblSearchQueries', 'UserId', 'SET NULL') === 'null');
ok('SET NULL anywhere else means KEEP it (attribution resolves to the tombstone)',
   accountEraseActionFor('tblSongRevisions', 'UserId', 'SET NULL') === 'keep',
   'the owner\'s "nothing is orphaned": a revision keeps a valid author id that '
   . 'renders as a blank placeholder, rather than a NULL every reader must special-case');

/* THE REFUSALS. Exercised directly rather than trusted to appear in schema.sql,
   because no live FK is RESTRICT — a mutant that changed the default from
   'refuse' to 'keep' left the totality assertion in §7 completely green. */
ok('RESTRICT is refused (we have no policy, so we do not guess)',
   accountEraseActionFor('tblAnything', 'UserId', 'RESTRICT') === 'refuse');
ok('NO ACTION is refused', accountEraseActionFor('tblAnything', 'UserId', 'NO ACTION') === 'refuse');
ok('an EMPTY rule is refused (a missing clause is not a licence to improvise)',
   accountEraseActionFor('tblAnything', 'UserId', '') === 'refuse');
ok('a rule from some future MySQL is refused',
   accountEraseActionFor('tblAnything', 'UserId', 'SET DEFAULT') === 'refuse');

/* The rule and the identifiers come off the wire from INFORMATION_SCHEMA, whose
   spelling depends on the server. */
ok('the rule is matched case- and whitespace-insensitively',
   accountEraseActionFor('tblUserFavorites', 'UserId', '  cascade ') === 'delete'
   && accountEraseActionFor('tblSearchQueries', 'UserId', 'set  null') === 'null');
ok('a case-folding server still finds a behavioural column',
   accountEraseActionFor('tblsearchqueries', 'userid', 'SET NULL') === 'null',
   'lower_case_table_names would otherwise turn every behavioural NULL into a KEEP');

echo "\n6b — the tombstone username cannot collide with a real one\n";

$placeholder = accountEraseUsernamePlaceholder(41);
ok('the placeholder embeds the id (unique across erasures, no counter needed)',
   strpos($placeholder, '41') !== false, $placeholder);

/* DERIVED FROM THE TREE, not typed: every username-validation regex in the two
   files that can mint one. If somebody widens a charset to include the
   placeholder's reserved character, this goes red — which is the whole point,
   because `tblUsers.Username` is NOT NULL UNIQUE and a mintable placeholder
   would make the SECOND erasure on an install fail with a duplicate key. */
$usernamePatterns = [];
foreach ([$PUB . '/api.php', $PUB . '/manage/includes/auth.php'] as $srcPath) {
    $src = (string)file_get_contents($srcPath);
    if (preg_match_all(
        "/preg_match\(\s*'(\/\^\[[^\]]+\]\+\\\$\/)'\s*,\s*\\\$\w*[Uu]sername\b/",
        $src,
        $m
    )) {
        foreach ($m[1] as $pat) { $usernamePatterns[$pat] = true; }
    }
}
ok('found the username-charset regexes in the tree',
   count($usernamePatterns) >= 2,
   count($usernamePatterns) . ' distinct pattern(s): ' . json_encode(array_keys($usernamePatterns))
   . '. Zero would make the assertions below vacuous');

foreach (array_keys($usernamePatterns) as $pattern) {
    ok("no user could ever register '$placeholder' (pattern $pattern)",
       preg_match($pattern, $placeholder) !== 1,
       'a placeholder a live user can hold makes the second erasure on this '
       . 'install fail on Username UNIQUE');
}

/* ======================================================================== *
 * 7 — TOTALITY: every FK schema.sql declares, classified
 * ======================================================================== */

echo "\n7 — every user foreign key in the tree, fed through the classifier\n";

$byAction = ['delete' => [], 'null' => [], 'keep' => [], 'refuse' => []];
foreach ($userFks as [$t, $c, $k, $rule]) {
    $action = accountEraseActionFor($t, $c, $rule);
    $byAction[$action][] = "$k ($t.$c ON DELETE " . ($rule === '' ? '(none)' : $rule) . ')';
}

ok('NO foreign key in schema.sql is refused by the erasure core',
   $byAction['refuse'] === [],
   count($byAction['refuse']) . " key(s) would make accountErase() refuse outright:\n  "
   . implode("\n  ", $byAction['refuse'])
   . "\nEvery key referencing tblUsers(Id) must be CASCADE (the row is the user's) "
   . 'or SET NULL (the row outlives them). Fix the DDL, not this test.');

/* EXHAUSTIVE. Every key landed in exactly one bucket — the classifier has no
   path that returns something none of the callers handle. */
$classified = count($byAction['delete']) + count($byAction['null'])
            + count($byAction['keep']) + count($byAction['refuse']);
ok('the partition is exhaustive — every key got exactly one action',
   $classified === count($userFks),
   "$classified classified out of " . count($userFks));

/* Non-vacuous in BOTH directions. An all-keep answer would satisfy "no
   refusals" while erasing nothing at all; an all-delete answer would satisfy it
   while destroying every attribution row in the database. */
ok('…and it is non-vacuous: real work in every bucket',
   count($byAction['delete']) >= 15 && count($byAction['null']) >= 1 && count($byAction['keep']) >= 15,
   'delete=' . count($byAction['delete']) . ' null=' . count($byAction['null'])
   . ' keep=' . count($byAction['keep'])
   . '. A classifier answering the same thing for everything passes the refusal '
   . 'check trivially while doing the wrong thing to every table');

/* The four tables whose treatment is most consequential, named individually so a
   silent reclassification is visible rather than absorbed into a count. */
$actionOf = static function (string $table, string $column) use ($userFks): ?string {
    foreach ($userFks as [$t, $c, $_k, $rule]) {
        if (strcasecmp($t, $table) === 0 && strcasecmp($c, $column) === 0) {
            return accountEraseActionFor($t, $c, $rule);
        }
    }
    return null;
};
foreach ([
    ['tblUserSetlists',      'UserId',      'delete', 'the user\'s own set lists'],
    ['tblApiTokens',         'UserId',      'delete', 'every bearer token — a STATED security requirement'],
    ['tblUserAuthProviders', 'UserId',      'delete', 'the Apple refresh token must never outlive the account'],
    ['tblSongHistory',       'UserId',      'null',   'behavioural: which songs they opened'],
    ['tblSongRevisions',     'UserId',      'keep',   'curatorial attribution resolves to the tombstone'],
    ['tblActivityLog',       'UserId',      'keep',   'the audit trail must not be rewritten by an erasure'],
    ['tblSetlistTemplates',  'CreatedBy',   'keep',   'a published template survives its author (that IS #1698)'],
    ['tblSharedSetlists',    'OwnerUserId', 'keep',   're-frozen at erase time, not blanked by the loop'],
] as [$t, $c, $want, $why]) {
    ok("$t.$c → '$want' ($why)", $actionOf($t, $c) === $want,
       'got ' . var_export($actionOf($t, $c), true));
}

/* ======================================================================== *
 * 8 — the migration's DDL is byte-identical to its schema.sql mirror
 * ======================================================================== */

echo "\n8 — rule #19: the migration and schema.sql declare the same columns\n";

$migPath = $ROOT . '/appWeb/.sql/migrate-user-account-status.php';
$migSrc  = (string)file_get_contents($migPath);
$schemaRaw = (string)file_get_contents($schemaPath);

ok('the migration exists on disk', $migSrc !== '', $migPath);

/* Both columns must carry a `@migration-adds` doctag. The schema-coverage
   scanner reads ONE `ADD COLUMN` per ALTER, so a two-column migration with one
   doctag silently ships a column schema.sql never hears about (rule #19). */
foreach (['tblUsers.Status', 'tblUsers.StatusChangedAt'] as $doctag) {
    ok("the migration carries a @migration-adds doctag for $doctag",
       strpos($migSrc, '@migration-adds ' . $doctag) !== false);
}

/* BYTE-IDENTICAL, COMMENT text included. Nothing else in CI compares these:
   test-schema-coverage.php only asserts the column NAME appears in schema.sql,
   so a migration whose COMMENT or type drifted from the mirror would ship a
   fresh install that differs from a migrated one and light up every row of
   /manage/schema-audit. */
if (preg_match_all('/ADD COLUMN\s+(\w+)\s+([^\n]+?)"\s*\n/', $migSrc, $ddl, PREG_SET_ORDER)) {
    ok('parsed the migration\'s ADD COLUMN declarations', count($ddl) === 2,
       count($ddl) . ' found: ' . json_encode(array_column($ddl, 1)));
    foreach ($ddl as [$_full, $col, $decl]) {
        $needle = $col . ' ' . trim($decl);
        ok("schema.sql mirrors the $col declaration byte-for-byte",
           strpos($schemaRaw, $needle) !== false,
           "migration says:\n  $needle\n…and schema.sql does not contain that string. "
           . 'A fresh install would then differ from a migrated one, and every row '
           . 'of /manage/schema-audit would report it.');
    }
} else {
    ok('parsed the migration\'s ADD COLUMN declarations', false,
       'the regex found none — the migration\'s shape changed and this check is '
       . 'now vacuous, which is worse than absent');
}

ok('schema.sql also declares the index the migration adds',
   preg_match('/INDEX\s+idx_Status\s*\(\s*Status\s*\)/i', $schemaRaw) === 1);

/* ======================================================================== *
 * 9 — the erasure core fails CLOSED
 * ======================================================================== */

echo "\n9 — accountErase() refuses rather than improvising\n";

$threw = null;
try {
    accountErase(mysqli_init(), 0);
} catch (AccountEraseException $e) {
    $threw = 'AccountEraseException';
} catch (\Throwable $e) {
    $threw = get_class($e);
}
ok('a non-positive user id is an AccountEraseException, not a silent no-op',
   $threw === 'AccountEraseException', 'threw: ' . var_export($threw, true));

/* An UNUSABLE connection must not be read as "the Status column is absent" (which
   would be a refusal — fine) or as "present" (which would not). Either way the
   one unacceptable outcome is returning normally, i.e. reporting an erasure that
   never happened. `mysqli_init()` returns a real, UNCONNECTED handle, so every
   statement on it fails — exactly the "database unreachable" case. */
$returned = null;
$threw    = null;
try {
    $returned = accountErase(mysqli_init(), 7);
} catch (\Throwable $e) {
    $threw = get_class($e);
}
ok('an unusable connection never returns a success shape',
   $returned === null && $threw !== null,
   'returned ' . json_encode($returned) . ', threw ' . var_export($threw, true)
   . '. An erasure that reports success without running is unrecoverable: the '
   . 'caller commits, tells the user their data is gone, and it is not');

/* ======================================================================== *
 * 10 — REACH: a perfect gate nobody calls is decoration
 * ======================================================================== */

echo "\n10 — the pure decisions above are actually consulted\n";

/*
 * SOURCE-SCANNED, and the limit is stated so the tick is not over-read: this
 * proves the call EXISTS, not that it is reached on every path. There is no
 * runtime handle for "the handler consults the helper" without a live database
 * to run the handler against.
 *
 * It exists because of a lesson this repo has now learned twice. #1679 A12
 * shipped a gate whose answer was computed and then DISCARDED, and the #1690
 * guard reproduced the same defect in a brand-new file: deleting the clause that
 * CONSUMED a correctly-built list left every assertion green. Sections 1–9 above
 * would pass in full against a build where `setlistCollabApplyOwnerLock()` is
 * flawless and nothing anywhere invokes it.
 *
 * COMMENTS ARE STRIPPED FIRST, by the tokenizer rather than a regex: this file
 * and the files it scans both name every one of these symbols in prose, and
 * prose satisfying a guard is the trap this repo keeps re-shipping.
 */
$stripComments = static function (string $src): string {
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) { continue; }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
};

$collabSrc = $stripComments((string)file_get_contents($PUB . '/includes/setlist_collab.php'));
ok('setlistCollabResolveAccess() actually applies the owner lock',
   substr_count($collabSrc, 'setlistCollabApplyOwnerLock(') >= 2,
   "'setlistCollabApplyOwnerLock(' appears "
   . substr_count($collabSrc, 'setlistCollabApplyOwnerLock(')
   . ' time(s) in the comment-stripped source. One occurrence is the declaration '
   . 'alone: the lock is defined, tested, and never consulted — the collaborator '
   . 'write gate #1698 added would simply not exist.');
ok('…and it asks userOwnerState() for the owner it just resolved',
   strpos($collabSrc, 'userOwnerState($db,') !== false,
   'the lock has to be given a state; a hardcoded one is not a gate');

$apiSrc = $stripComments((string)file_get_contents($PUB . '/api.php'));
foreach ([
    'setlist_collab_shared_with_me + setlist_templates classify owners' => 'userStateFromRow(',
    'both list endpoints ask whether that state locks the content'      => 'userContentLocked(',
    'the Status column is existence-gated before being SELECTed'        => 'userStatusColumnReady(',
    'the erasure core is reached from the self-service endpoint'        => 'accountErase(',
] as $what => $needle) {
    ok("api.php: $what", strpos($apiSrc, $needle) !== false, "missing: $needle");
}

/* The two new entitlements, at the endpoints that must honour them. Counted
   rather than merely present: `publish_public_templates` gates BOTH the create
   and the update path, and a gate on only one of the two is not a gate — an
   update that sets IsPublic = 1 publishes exactly as thoroughly as a create
   does. `manage_setlist_templates` likewise gates update, delete AND the
   `canEdit` the list emits, because a server that grants the power while the
   list says `canEdit: false` gives an admin a capability with no button. */
ok('api.php gates is_public on publish_public_templates in BOTH write paths',
   substr_count($apiSrc, "'publish_public_templates'") >= 2,
   "found " . substr_count($apiSrc, "'publish_public_templates'") . ' occurrence(s). '
   . 'Gating create but not update leaves the whole publish route open');
ok('api.php honours manage_setlist_templates at update, delete AND the list',
   substr_count($apiSrc, "'manage_setlist_templates'") >= 3,
   'found ' . substr_count($apiSrc, "'manage_setlist_templates'") . ' occurrence(s)');

/* SOURCING THE ANSWER IS NOT USING IT. The assertion above counts where the
   entitlement is RESOLVED; this counts where the result is CONSUMED. A mutation
   run proved the first insufficient on its own: deleting `|| $tplCanManageAny`
   from the list's `canEdit` — which is what tells the client to draw the button
   at all — left the resolution in place and every assertion green, so an admin
   would hold the power with nothing on screen to use it with. Same "a gate whose
   answer is DISCARDED is decoration" defect as #1679 A12, and as the #1690
   guard's own M10.
   Three resolutions + seven consumptions (one in the list's canEdit, three each
   in update and delete). The threshold is the EXACT current count, measured
   against the comment-stripped source — a threshold set one below it is a
   threshold that tolerates exactly the deletion being guarded against, which is
   how this assertion was wrong on its first attempt. LIMIT: this cannot tell
   WHICH consumption went missing, only that one did. */
ok('…and the resolved override is actually CONSUMED, not merely computed',
   substr_count($apiSrc, '$tplCanManageAny') >= 10,
   '$tplCanManageAny appears ' . substr_count($apiSrc, '$tplCanManageAny')
   . ' time(s) in the comment-stripped source. Three of those are the '
   . 'resolutions; fewer than seven others means a branch stopped asking.');

/* Both maps carry both keys. test-entitlement-parity.php proves the two maps
   AGREE; this proves the keys #1698 introduced are in them at all — a pair of
   maps that agree on the absence of a key is perfectly consistent and useless,
   and userHasEntitlement() fails closed on an unknown key, so a missing entry
   would silently deny every role instead of erroring. */
$entPhp = (string)file_get_contents($PUB . '/includes/entitlements.php');
$entJs  = (string)file_get_contents($PUB . '/js/modules/entitlements.js');
foreach (['manage_setlist_templates', 'publish_public_templates'] as $ent) {
    ok("the '$ent' entitlement exists in the PHP map",
       strpos($entPhp, "'" . $ent . "'") !== false);
    ok("…and is mirrored in the JS map",
       strpos($entJs, $ent . ':') !== false);
}

$authSrc = $stripComments((string)file_get_contents($PUB . '/manage/includes/auth.php'));
ok('setUserActive() refuses to reactivate a tombstone (it consults the state)',
   strpos($authSrc, 'userStateFromRow(') !== false && strpos($authSrc, 'userStatusColumnReady(') !== false,
   'without this an admin can re-enable an account whose identity was scrubbed, '
   . 'producing a husk nothing can log into that every "active users" count and '
   . 'the last-global-admin guard still believe in');

$usersSrc = $stripComments((string)file_get_contents($PUB . '/manage/users.php'));
ok('/manage/users distinguishes the three states in its list',
   strpos($usersSrc, 'userStateFromRow(') !== false,
   'the owner asked for disabled and deleted to be clearly distinguishable; a '
   . 'page that renders both as "Disabled" has not done that');
ok('…and requires the username typed to confirm an erasure (#1218 §2)',
   strpos($usersSrc, 'confirm_username') !== false);

if ($fail === 0) {
    echo "\nAll account-lifecycle assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
