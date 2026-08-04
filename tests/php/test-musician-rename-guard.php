<?php

declare(strict_types=1);

/**
 * iHymns — Musicians rename guard (#1741 P2-B, plan §3.A.4)
 *
 * ELI5
 * ----
 * #1741 P2-A renamed the database (tblCreditPeople -> tblMusicians, etc.) and
 * left seven back-compat VIEWs under the OLD names so old app code kept
 * working. P2-B (this commit) switched the app code itself to the NEW names,
 * plus a small, deliberate set of back-compat ALIASES (old API action names,
 * old routes, the AASA claim, …) that must keep working forever because the
 * shipped Apple client still uses them. This guard makes sure BOTH halves of
 * that stay true from now on:
 *
 *   - nobody accidentally reintroduces an OLD-name reference in new code
 *     (the thing P2-B's whole point was to eliminate), and
 *   - nobody accidentally DELETES one of the deliberate survivors while
 *     "cleaning up" old-name-looking text (which would silently break the
 *     shipped Apple contract, or a stale bookmark's redirect, with no error
 *     anywhere — the rule #30 failure shape).
 *
 * WHY COUNT-EXACT, NOT JUST "BANNED" (rule #34 — a guard must be provably
 * able to fail in both directions, mirroring `test-fragment-inline-scripts.
 * php`'s allowlist pattern): a plain ban list can only ever go from green to
 * red when something NEW appears. It says nothing when something old
 * disappears. `case 'credit_person':` in api.php IS an occurrence of the
 * banned token `credit_person` — deliberately, forever, because deleting it
 * breaks the shipped Apple client with no compile error and no test failure
 * anywhere else in this tree. An allowlist that only ever raises the ceiling
 * ("credit_person is fine, don't check it") would let that deletion sail
 * through silently. Recording the EXACT expected count per (file, token)
 * makes both directions equally loud: one more occurrence than expected is a
 * NEW leak (fails HIGH); one fewer is a DELETED back-compat alias (fails
 * LOW). Every entry below was counted from the real tree, not guessed.
 *
 * SCOPE — WHY appWeb/.sql/ IS EXCLUDED WHOLESALE (not just "the rename
 * migration"):
 * #1741 P2-B's own task brief is explicit that the schema/migrations half is
 * OUT OF SCOPE ("NOT schema.sql/migrations/schema_audit.php — those are
 * done", P2-A #1746). `appWeb/.sql/` holds `schema.sql` (already migrated to
 * the new names, by design still declaring the 7 back-compat VIEWs under
 * their OLD names — that is the correct, permanent end state, not something
 * this guard should flag) AND roughly twenty historical `migrate-*.php`
 * scripts, most utterly unrelated to this rename, that each created some
 * object under the vocabulary that was current AT THE TIME they were
 * written — `migrate-credit-people.php`, `migrate-credit-people-flags.php`,
 * `migrate-add-creditperson-members.php`, and others, PLUS
 * `migrate-musicians-rename.php` itself, which necessarily writes the OLD
 * names on both sides of its `RENAME TABLE …` and inside every back-compat
 * `CREATE VIEW` statement. None of these files will ever be edited again;
 * scanning them would just be permanent, unfixable noise. Excluding the
 * whole directory (rather than hand-listing which migration is "the" rename
 * migration, which the plan's literal wording implied) is the tree-derived,
 * self-maintaining version of the same exclusion — a NEW migration added
 * later never needs this guard updated.
 *
 * TWO FURTHER EXCLUSIONS BEYOND THE PLAN'S LITERAL LIST (recorded here
 * because the plan named only `schema_audit.php`'s rename-remap — verified
 * against the real tree while implementing, not assumed):
 *   - `manage/includes/migration-registry.php` — the setup-database
 *     dashboard's per-migration probes/descriptions for those SAME
 *     historical `migrate-*.php` scripts above (rule #19). Its probes
 *     correctly check the OLD table/column names for migrations that
 *     literally created objects under those names — e.g. the
 *     `credit-people-flags` card's probe asks "does `tblCreditPeople.
 *     IsSpecialCase` exist?", which is a factual question about what THAT
 *     migration does, not app code reading/writing the entity today. The
 *     P2-A-authored `musicians-rename` entry (already renamed-aware) stays
 *     put; nothing here needed to change for P2-B.
 *   - `manage/setup-database.php` — the `$friendlyTitles` map's five
 *     `'credit-people*'` keys are dashboard-action SLUGS keyed to those same
 *     unchanged migration scripts (`$scriptMap`), not a route or an entity
 *     name; renaming the key would break the mapping to a migration file
 *     that will never be renamed.
 * Both exclusions follow the identical reasoning `schema_audit.php` already
 * gets from the plan: a file whose job is to describe or drive HISTORICAL,
 * frozen migration scripts necessarily keeps saying what those scripts say.
 *
 * THE BANNED TOKENS (plan §3.A.4's exact list — each is a literal,
 * case-sensitive substring match, `substr_count()`, not a regex; a token
 * that is itself a substring of another, e.g. `tblCreditPerson` inside a
 * satellite table name, is counted independently per-token by design, since
 * every satellite table literally contains that substring):
 *   tblCreditPeople, tblCreditPerson, CreditPersonId, GroupPersonId,
 *   MemberPersonId, credit_person, credit_people, credit-people,
 *   creditPerson, manage_credit_people
 *
 * THE DELIBERATE SURVIVORS (why each allowlist entry exists — every one
 * re-verified against the real tree, not copied from the plan's prose,
 * which named some categories — router.js's alias cases, the AASA `/person/
 * *` claim, index.php's 301 regex — that turned out NOT to use any of the
 * TEN literal tokens above at all: they alias the bare words `person` /
 * `people`, never `credit_person` / `credit-people`. Recorded here so a
 * future reader does not "fix" an apparent gap that the plan predicted but
 * the real tree never had):
 *   - appWeb/public_html/api.php (11× `credit_person`) — the five
 *     `admin_credit_person_*` fallthrough case labels + the `credit_person`
 *     GET-action fallthrough case label and its `$_isLegacyPersonAction`
 *     branch (ternary + doc comments referencing the alias by name). This
 *     is the shipped Apple contract: `action=credit_person` must return the
 *     byte-identical `{"person":{…}}` envelope forever.
 *   - appWeb/public_html/manage/musicians.php (1× `credit_person`) — a doc
 *     comment recording the log-key shape BEFORE this rename, for the
 *     historical record (not a code reference).
 *   - appWeb/public_html/manage/activity-log.php (1× `credit_person`) — a
 *     comment explaining why the EntityType filter expands to
 *     `IN ('musician','credit_person')`.
 *   - appWeb/public_html/includes/activity_log.php (3× `credit_person`) —
 *     `ACTIVITY_LOG_ENTITY_TYPE_ALIASES`, the ONE shared old<->new map (rule
 *     #35) the activity-log viewer's filter reads, plus its doc-block.
 *     Historical `tblActivityLog` rows say `EntityType='credit_person'`
 *     forever (history is never rewritten) — this map is what lets a
 *     curator filtering for "musician" still find them.
 *   - appWeb/public_html/manage/credit-people.php (2× `credit-people`) — the
 *     302-redirect stub at the OLD path (mirrors the `/manage/
 *     song-link-suggestions` precedent, #1215/#1220). Its own filename is
 *     `credit-people.php`, which is what makes `/manage/credit-people` still
 *     resolve to something at all.
 *   - appWeb/public_html/manage/credit-people-bulk-promote.php (2×
 *     `credit-people`) — the sibling 302-redirect stub for the bulk-promote
 *     companion page.
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely
 * in memory, never touching the tree; mirrors `test-orphan-inventory.php`'s
 * "Part 6" shape):
 *   FAILS-HIGH direction (a NEW old-name reference must be caught) — for
 *     EACH of the ten banned tokens individually: scan a synthetic
 *     "clean file" (a relative path that is not in the allowlist and not
 *     excluded) whose text contains exactly one occurrence of that token.
 *     Assert the scan reports a mismatch for that (file, token) pair.
 *   FAILS-LOW direction (a DELETED back-compat alias must be caught) — for
 *     each of the TWO tokens that actually have allowlist entries
 *     (`credit_person`, `credit-people`): take one of the real allowlisted
 *     files' real text, remove exactly ONE occurrence of the token (an
 *     in-memory string edit — the file on disk is never touched), and
 *     assert the scan now reports a mismatch (actual count one less than
 *     the recorded expectation) for that (file, token) pair.
 *   Both directions run against the SAME core function the real scan uses
 *   (`renameGuardCountTokensInText()`), not a re-implementation — so a
 *   passing mutation test is evidence the real scan's logic works, not
 *   evidence of a parallel checker that agrees with itself.
 *
 * Pure source-tree scan — no DB — so it slots into the CI lint step.
 *
 *   php tests/php/test-musician-rename-guard.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see .claude/catalogue-expansion-1741-plan.md §3.A.4
 */

const RENAME_GUARD_BANNED_TOKENS = [
    'tblCreditPeople',
    'tblCreditPerson',
    'CreditPersonId',
    'GroupPersonId',
    'MemberPersonId',
    'credit_person',
    'credit_people',
    'credit-people',
    'creditPerson',
    'manage_credit_people',
];

/* Directory prefixes excluded WHOLESALE (relative to the repo root, always
   trailing-slash so a prefix match can't accidentally catch e.g. a sibling
   `appWeb/.sql-extra/` directory). See the doc-block for why the whole
   migrations directory, not just "the rename migration", is excluded. */
const RENAME_GUARD_EXCLUDED_PREFIXES = [
    'appWeb/.sql/',
];

/* Individual files excluded — each requires its own doc-block paragraph
   above, not just a bare list, per the project's "a comment saying keep in
   sync is the failure" rule (#35): this list IS the sync mechanism, the
   doc-block is why each entry is here. */
const RENAME_GUARD_EXCLUDED_FILES = [
    'appWeb/public_html/includes/schema_audit.php',
    'appWeb/public_html/manage/includes/migration-registry.php',
    'appWeb/public_html/manage/setup-database.php',
];

/* Count-exact allowlist: relative file => [token => expected occurrence
   count]. A file/token pair not listed here defaults to an expectation of
   ZERO — i.e. the ban is absolute everywhere except these six files, each
   at exactly this count. */
const RENAME_GUARD_ALLOWLIST = [
    'appWeb/public_html/api.php' => [
        'credit_person' => 11,
    ],
    'appWeb/public_html/manage/musicians.php' => [
        'credit_person' => 1,
    ],
    'appWeb/public_html/manage/activity-log.php' => [
        'credit_person' => 1,
    ],
    'appWeb/public_html/includes/activity_log.php' => [
        'credit_person' => 3,
    ],
    'appWeb/public_html/manage/credit-people.php' => [
        'credit-people' => 2,
    ],
    'appWeb/public_html/manage/credit-people-bulk-promote.php' => [
        'credit-people' => 2,
    ],
];

/**
 * PURE core: count every banned token's literal occurrences in one file's
 * text. No I/O — this is what both the real scan AND the mutation
 * self-tests below call, so a passing mutation test is evidence about this
 * exact logic, not a parallel re-implementation.
 *
 * @return array<string,int> token => occurrence count (zero-count tokens
 *                            omitted, so an empty array means "clean")
 */
function renameGuardCountTokensInText(string $text): array
{
    $out = [];
    foreach (RENAME_GUARD_BANNED_TOKENS as $token) {
        $c = substr_count($text, $token);
        if ($c > 0) { $out[$token] = $c; }
    }
    return $out;
}

/**
 * Is this relative path excluded from the scan entirely (a directory
 * prefix or an individual file)?
 */
function renameGuardIsExcluded(string $rel): bool
{
    foreach (RENAME_GUARD_EXCLUDED_PREFIXES as $prefix) {
        if (str_starts_with($rel, $prefix)) { return true; }
    }
    return in_array($rel, RENAME_GUARD_EXCLUDED_FILES, true);
}

/**
 * Compare one file's actual token counts against its allowlist expectation
 * (default zero) and return a list of human-readable mismatch strings —
 * empty when the file is clean. Shared by the real scan and both mutation
 * directions.
 *
 * @param array<string,int> $actual From renameGuardCountTokensInText()
 * @return list<string>
 */
function renameGuardDiff(string $rel, array $actual): array
{
    $expected = RENAME_GUARD_ALLOWLIST[$rel] ?? [];
    $mismatches = [];
    $allTokens = array_unique(array_merge(array_keys($actual), array_keys($expected)));
    foreach ($allTokens as $token) {
        $a = $actual[$token]   ?? 0;
        $e = $expected[$token] ?? 0;
        if ($a === $e) { continue; }
        if ($a > $e) {
            $mismatches[] = sprintf(
                "%s :: '%s' — found %d occurrence(s), allowlist expects %d (%s) — "
              . 'a NEW old-name reference (rename it, or if this is a genuine new '
              . 'deliberate survivor, raise the allowlist count with a reason)',
                $rel, $token, $a, $e, $e === 0 ? 'not allowlisted at all' : 'more than expected'
            );
        } else {
            $mismatches[] = sprintf(
                "%s :: '%s' — found %d occurrence(s), allowlist expects %d — "
              . 'a DELIBERATE SURVIVOR disappeared (a back-compat alias was likely '
              . 'deleted — restore it, or if the alias is genuinely retired, lower '
              . 'the allowlist count in the SAME commit that removed it)',
                $rel, $token, $a, $e
            );
        }
    }
    return $mismatches;
}

$repoRoot = dirname(__DIR__, 2);
$scanRoot = $repoRoot . '/appWeb';

if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: $scanRoot not found\n");
    exit(1);
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) { continue; }
    $ext = strtolower($f->getExtension());
    if ($ext !== 'php' && $ext !== 'js') { continue; }
    $files[] = $f->getPathname();
}
sort($files);

$rootPrefix = $repoRoot . '/';
$scanned    = 0;
$failures   = [];

foreach ($files as $file) {
    $rel = substr($file, strlen($rootPrefix));
    if (renameGuardIsExcluded($rel)) { continue; }

    $text = file_get_contents($file);
    if ($text === false) { continue; }
    $scanned++;

    $actual = renameGuardCountTokensInText($text);
    foreach (renameGuardDiff($rel, $actual) as $m) { $failures[] = $m; }
}

/* Vacuity check (rule #34): a scan over almost nothing is not evidence the
   tree is clean. The real count as of this guard's authoring is 296
   .php/.js files under appWeb/ once appWeb/.sql/ + the three excluded
   files are removed (299 total minus 3). A floor comfortably below that,
   but far above zero, catches "the derivation itself broke" (wrong root,
   wrong extensions, an exclusion prefix eating the whole tree) without
   needing an edit every time the corpus grows by a handful of files. */
if ($scanned < 200) {
    array_unshift($failures, sprintf(
        'vacuity check failed: scan covered only %d file(s) (expected >= 200) — '
      . 'the derivation itself is broken (root path, extension filter, or '
      . 'exclusion prefix drift), not the codebase',
        $scanned
    ));
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run on every invocation, in memory only.
 * ========================================================================= */

$mutationFailures = [];

/* FAILS-HIGH: for EACH of the ten tokens, a synthetic "clean" file
   containing exactly one occurrence must be flagged. The synthetic path is
   deliberately outside both the exclusion list and the allowlist. */
foreach (RENAME_GUARD_BANNED_TOKENS as $token) {
    $syntheticRel  = 'appWeb/public_html/zz_rename_guard_mutation_probe.php';
    $syntheticText = "<?php\n// probe containing {$token} exactly once\n";
    $diff = renameGuardDiff($syntheticRel, renameGuardCountTokensInText($syntheticText));
    if ($diff === []) {
        $mutationFailures[] = "FAILS-HIGH self-test did NOT go red for token '{$token}' "
            . '— the guard cannot detect a brand-new occurrence of this token';
    }
}

/* FAILS-LOW: for the two tokens that actually appear in the allowlist,
   take the REAL file's REAL text, remove exactly one occurrence in memory,
   and confirm the scan now flags a shortfall. */
$failsLowCases = [
    'appWeb/public_html/api.php'                               => 'credit_person',
    'appWeb/public_html/manage/credit-people.php'               => 'credit-people',
];
foreach ($failsLowCases as $rel => $token) {
    $path = $repoRoot . '/' . $rel;
    $realText = is_file($path) ? (string)file_get_contents($path) : '';
    if ($realText === '' || !str_contains($realText, $token)) {
        $mutationFailures[] = "FAILS-LOW self-test could not run for {$rel} :: '{$token}' "
            . '— the real file no longer contains the token at all (has the alias already '
            . 'been removed? if so this guard\'s allowlist should already have failed above)';
        continue;
    }
    $mutatedText = preg_replace('/' . preg_quote($token, '/') . '/', 'REMOVED', $realText, 1);
    $diff = renameGuardDiff($rel, renameGuardCountTokensInText((string)$mutatedText));
    if ($diff === []) {
        $mutationFailures[] = "FAILS-LOW self-test did NOT go red for {$rel} :: '{$token}' "
            . '— removing one real occurrence was not detected, so silently deleting a '
            . 'back-compat alias from this file would pass CI clean';
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: Musicians rename guard (#1741 P2-B):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    exit(1);
}

echo "PASS: Musicians rename guard — {$scanned} .php/.js file(s) scanned under appWeb/, "
   . 'every old-name-token occurrence accounted for by the count-exact allowlist ('
   . count(RENAME_GUARD_ALLOWLIST) . " file(s) carry a deliberate survivor), and all "
   . (count(RENAME_GUARD_BANNED_TOKENS) + count($failsLowCases))
   . " mutation self-tests (10 fails-high + 2 fails-low) went red as expected.\n";
exit(0);
