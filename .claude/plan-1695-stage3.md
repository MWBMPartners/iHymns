# Plan — #1695 Stage 3: curators soft-delete, admins review — queue + notification

Fable 5 deep plan, 2026-07-31, against `claude/wave3-fixes` @ `c74c9f85`. Stage 3 of epic **#1692**.
Stage 2 (**#1694**) has fully landed on this branch (commits `7eaf0d54` → `739da297` + docs
`de25f6dd`): the five soft-delete columns, the ONE lifecycle module
(`includes/song_soft_delete.php`), the read-path sweep + guard, both editor delete endpoints
soft-deleting, and `/manage/deleted-songs` with Restore (`delete_songs`) + type-to-confirm Purge
(`purge_songs`).

⚠️ **No MySQL and no `gh` in this container.** Runtime claims marked ⚗ need the alpha rehearsal
(§9). The #1695 issue body could not be fetched; the corrections in §0 are derived from the scope as
quoted in the tasking plus every in-repo reference to #1695.

---

## 0. What #1695's text gets wrong, now that #1694 has actually shipped

1. **"An admin review queue: what was soft-deleted, by whom, when; Restore or escalate to purge"
   — ALREADY BUILT.** That is `/manage/deleted-songs` verbatim (`manage/deleted-songs.php`:
   queue listing :167-191, Restore :101-116, server-enforced type-to-confirm Purge :119-155,
   `purge_songs` per-action gate :74). Stage 3 does not build the queue; it only *widens its
   audience* (via the entitlement) and adds the notification.
2. **"A curator's delete is a soft delete, reversible by them or an admin" — ALREADY MECHANICALLY
   TRUE.** Both `delete_song` endpoints delegate to `songSoftDelete()`
   (`manage/editor/api.php:3460`, `api2.php:905`) and Restore is gated on `delete_songs` — the
   ONLY thing standing between a curator and this behaviour is the interim admin-only map entry.
   Scope item 2 collapses into scope item 1.
3. **"Wire to the PUSH_KINDS registry from #1671's Web Push work IF THAT LANDED" — IT LANDED,
   under a different spelling.** The registry is **`webPushKinds()`** in
   `appWeb/public_html/includes/web_push.php:138-152` (commit `9fea9427`, "#311, #1671 F6",
   2026-07-31 11:15). The stage-2 plan's claim that it had NOT landed
   (`.claude/plan-song-soft-delete-2026-07-31.md:67`, "grepped: zero hits") was a **literal grep
   for a name that never existed** — `PUSH_KINDS` has zero hits in `appWeb/` because the thing is
   called `webPushKinds()`. Correct that line in place (dated annotation, the rule-#26 style).
   Also state plainly what the push chain IS: cryptographically proven against RFC 8291 §5's own
   vector (`tests/php/test-web-push.php`) but **dormant on every docroot**
   (`webPushConfigured()` false until an operator generates a VAPID keypair on
   `/manage/notifications`) and **no push has ever been delivered to a real device**
   (web_push.php:65-73). The mechanism that works TODAY is the in-app bell:
   `notifyUser()` in `includes/notifications.php` (#1638) + `tblNotifications`
   (`IsRead` at schema.sql:1894).
4. **"#1693's three coordinated edits" is really FOUR file-locations** (§1 below): the handoff's
   "three" counted "both maps" as one item (`.claude/sessions/2026-07-28-HANDOFF.md:762-766`).
5. **The piece #1695 does not mention at all — the overrides shadow.** `saveEntitlementOverrides()`
   writes the WHOLE map, not a delta (`includes/entitlements.php:389-419`), and
   `effectiveEntitlements()` lets a stored key completely replace the code default (:369-374).
   So on any install where `/manage/entitlements` has been saved since stage 1, widening the code
   default is a **silent no-op** — the exact class `migrate-entitlement-truthup.php` (#1590)
   exists to fix. Stage 3 needs its own data-migration card (§3).

---

## 1. (a) Scope item 1 — the re-widen: precise inventory, DERIVED BY GREP

`grep -rn delete_songs` over the tree (excluding `.claude/sessions/`), classified:

### Load-bearing — the coordinated flip (all in ONE commit, or the parity suite is red between them)

| # | File:line | Change |
|---|---|---|
| 1 | `appWeb/public_html/includes/entitlements.php:72` | `['admin','global_admin']` → `['editor','admin','global_admin']`. Rewrite the comment block :55-71 (the "⚠️ IS NOW ADMIN-ONLY … revisit this line" paragraph — it says to revisit exactly now). |
| 2 | `appWeb/public_html/js/modules/entitlements.js:29` | Same flip; rewrite comment :24-28 ("Interim — stage 2 adds soft delete, after which this returns to editor+" — that day is today). The purge comment :30-35 stays. |
| 3 | `tests/php/test-entitlement-parity.php:298-303` | `$e1Baseline['delete_songs']` → `[$editorPlus, …]` with new prose: the equivalence claim is TRUE again (the raw gate it replaced was the editor APIs' file-level `hasRole($role,'editor')` — api.php:47 / api2.php:135 — and the map now matches it once more; the interim reduction is reverted because deletion is recoverable, #1694/#1695). Comment :285-297 rewritten. |
| 4 | `tests/php/test-entitlement-parity.php:464-482` | **MUT-6 flips direction AGAIN**, exactly as its own comment predicts (:472-473). New mutant: `$m6Map['delete_songs'] = ['admin','global_admin']` (the #1692 stage-1 interim); assert mutant refuses `editor` AND the real map admits `editor` — the regression to catch is now a silent NARROWING. |

Sequencing note baked into the suite: **flip the two maps first, run
`php tests/php/test-entitlement-parity.php`, and REQUIRE the E1-baseline row + MUT-6 to go RED**
("the suite said so, loudly, the moment the default moved" — :468-470 documents that as the design).
Then flip 3 & 4 → green. That red-first run IS this commit's mutation drill, free of charge.

### NOT hardcoded — verified no change needed

- `tests/test-entitlement-parity.js` — mechanical map comparison, zero `delete_songs` literals (grepped).
- `manage/deleted-songs.php:67` page gate + `manage/includes/admin-links.php:56` nav entry — both
  say `delete_songs`; the audience widens **automatically and stays paired**
  (`test-admin-gate-parity.php` derives the pairing).
- `manage/entitlements.php:77,118` — grouping + label copy, role-agnostic.
- `manage/editor/api.php:3431`, `api2.php:888` — entitlement checks, unchanged.
- `api-docs.yaml:6733,6768` — names the entitlement, not the roles. No change.

### Stale prose to refresh IN THE FILES THIS PR TOUCHES (never a mass sweep — the rule-#28 convention)

- `manage/editor/api.php:3415-3430` — the long equivalence comment (already half-stale since
  stage 1; becomes true again — refresh + cite #1695). Touched anyway if commit 4 lands here? No —
  v1 gets no modal; refresh the comment in commit 1 since commit 1 is what changes its truth value.
- `manage/deleted-songs.php:33-36` doc-block future tense ("#1695 re-widens…") → past tense, in
  whichever commit next touches the file (commit 3 does — the restore-notify call site is nearby? No,
  restore-notify lives in the module. Fold into commit 5 docs if untouched).
- `manage/help.php:596` ("Deleting still needs `delete_songs` **for now**") and :803-806 ("Today
  both privileges sit with admins … meant to widen to editors next (#1695)") → commit 5.
- `.claude/MEMORY.md:324`, `.claude/ProjectBrief.md`, `.claude/plan-song-soft-delete-2026-07-31.md:67`
  (the PUSH_KINDS correction, §0.3) → commit 5.
- **Deliberately left alone** (historical records): `appWeb/.sql/migrate-entitlement-truthup.php:34-49`,
  `manage/includes/migration-registry.php:3073` (truth-up card text), `help.php:1305` (generic example).

---

## 2. (b) Does stage 3 need NEW SCHEMA? Verdict: **NO columns, NO tables. ONE data-only migration.**

The adversarial argument, both ways:

| Candidate need | Answer without schema | What schema would buy / cost |
|---|---|---|
| The review queue itself | `WHERE IsDeleted = 1` IS the queue; `DeletedBy/At/Reason/Note` answer who/when/why (stage-2 plan §1, proven by the shipped page) | Nothing. |
| Notification read-state | `tblNotifications.IsRead` (schema.sql:1894) already models it per-recipient | A per-deletion "seen" flag would be a SECOND read-state that can disagree with the first (rule #35). |
| "Reviewed / acknowledged" bookkeeping | The queue self-clears — Restore or Purge removes the row from `IsDeleted = 1`; both are `logActivity()`-logged | A Reviewed column would rot the moment two admins disagree about what "reviewed" means. |
| Restore attribution | `logActivity('song.restore', …)` rows (deleted-songs.php:110) | `RestoredBy/RestoredAt` columns duplicate the activity log for a row whose five delete columns are cleared anyway. |
| Reviewer audience | DERIVED from `effectiveEntitlements()['purge_songs']` → `tblUsers.Role` (idx_Role, schema.sql:750) | A subscriber table is the #1671-orphan class waiting to happen. |
| Notification opt-out | `ihymns.push` namespace via `webPushKindEnabled()` (web_push.php:182-193) — per-user, schema-free | — |
| Reason vocabulary growth | `songDeleteReasons()` map, VARCHAR column (rule #20) — one PHP line | — |
| Curator "own deletions only" view | `DeletedBy` already exists if ever wanted | — (and §5 recommends against the policy). |
| Four-eyes purge, if ever enforced | A `DeletedBy !== $purgerId` clause in `songSoftDeleteVerdict()` — zero schema | — |
| **"Proposed for deletion, still live"** (the review-only fork) | **CANNOT be done without schema** — it is a workflow state, not a visibility state; stage-2's adversarial table already reserved "its own queue table (the `tblLyricsReviewQueue` shape)" for it | This is the ONE credible second-migration threat (§8). Deliberately NOT pre-built: the owner's standing recommendation is immediate-hide, and shipping a guessed dormant workflow table violates the "never ship a guessed bridge" half of rule #20. |

**The one migration that IS needed is data-only** (§3): prune the `delete_songs` key from
`tblAppSettings.entitlements_overrides` where it stores exactly the stage-1 interim value, plus a
sentinel. No DDL; `schema.sql` gains only the sentinel seed row (the truth-up precedent,
schema.sql:1740).

---

## 3. The overrides re-widen migration — `appWeb/.sql/migrate-delete-songs-rewiden.php`

Modelled line-for-line on `migrate-entitlement-truthup.php` (#1590), with ONE deliberate
difference: since stage 1 the `/manage/entitlements` control has been LIVE, so an unconditional
prune could erase a genuine operator choice. Therefore the prune is **conditional**:

- Remove `delete_songs` from the stored JSON **only when its value is exactly
  `{'admin','global_admin'}`** (order-insensitive set compare) — indistinguishable from the form's
  default passing through a whole-map save, and identical in effect to the new default's
  *predecessor*, so pruning it restores the shipped default (editor+).
- **Any other stored value is preserved** (e.g. a deliberate `['global_admin']`-only lockdown).
- Absent row / empty / unparseable JSON → nothing to prune, still applies (truth-up :157-170).
- Output names what it dropped (`[was: admin/global_admin]`), and says plainly: an operator who
  WANTS admin-only deletes re-unticks `editor` — the control is real.
- Sentinel `delete_songs_rewiden_applied` (a data migration has no schema to probe, and "the key is
  absent" is un-drivable — re-saving the page legitimately writes it back; truth-up :197-202 states
  this exact reasoning). Mirror the sentinel seed INSERT into `schema.sql` beside :1740,
  byte-identical. `@migration-modifies tblAppSettings` doctag.
- **ONE `migration-registry.php` entry**; probe = sentinel row exists (real, drivable to zero,
  rule #19). Not `manual` — it removes a privilege *restriction* and is safe in "Apply all".
- Benign race, stated: a whole-map save between deploy and card-run stores `editor+` (the new form
  default), which the conditional prune then skips — correct either way.

**Extract the decision as a PURE function** so rule #34 has something to CALL:
`entitlementOverridesPruneIfEquals(array $decoded, string $key, array $roles): array{changed:bool, map:array}`
in `includes/entitlements.php` (the migration and any future truth-up both use it — the modularity
rule applied to migrations). Tested in `test-entitlement-parity.php` (§7, commit 2 drills).

---

## 4. (c) The notification design — grounded in what exists in the tree today

**Two channels, one plan core, both fed from inside the ONE write path.**

### 4.1 Where it fires — inside `songSoftDelete()` and `songRestore()`, post-commit, best-effort

`includes/song_soft_delete.php` gains:

- `songSoftDeleteNotificationPlan(string $event, array $verdict, string $songId, ?int $actorId, ?string $actorName, array $reviewerIds, ?string $reason, string $note): array`
  — **PURE** (state in, plan out): recipients = `$reviewerIds` minus `$actorId`; title
  `Song deleted: "<Title>" (<SongId>)` / `Song restored: …`; body folds the
  `songDeleteReasons()[$reason]` label + clamped note + actor name; url `/manage/deleted-songs`
  (passes `notifyUser()`'s `#^/[^/]#` allow-list, notifications.php:149-152); type/kind
  **`'song_deleted'` / `'song_restored'` — ONE spelling** shared by the in-app `Type` and the push
  kind, because two spellings of one event is the #1581 silent-no-op class.
- `songSoftDeleteReviewerIds(\mysqli $db, ?int $excludeUserId): array` — recipients derived from the
  registry, never a hardcoded role list (the red-flag rule):
  `SELECT Id FROM tblUsers WHERE Role IN (<effectiveEntitlements()['purge_songs']>) AND IsActive = 1 LIMIT 50`
  (Role/IsActive: schema.sql:729/:731; idx_Role :750; placeholders built from `array_fill()` count —
  rule #5's sanctioned shape; the LIMIT is the bounded-fan-out stance `webPushBroadcast()` takes).
  Audience = **holders of `purge_songs`**, i.e. the people who can do what the deleter cannot — and
  it tracks operator overrides automatically.
- `songSoftDeleteNotifyReviewers(...)` — the driver: fetch actor Username (one guarded SELECT),
  build the plan, loop `notifyUser()` (never throws by contract, notifications.php:27-45), then
  `webPushBroadcast($db, $kind, webPushBuildPayload(...), $recipientIds)` — a structural no-op until
  an operator provisions VAPID (`webPushConfigured()`), which is exactly the dormant-by-construction
  behaviour we want. **The whole driver is wrapped try/catch and called AFTER `$db->commit()`** in
  `songSoftDelete()` (:502) and `songRestore()` (:559): a notification about a rolled-back delete is
  a lie, and a notification failure must never fail the delete (the notifications.php
  "best-effort is the contract" doctrine). Lazy `require_once` of notifications.php / web_push.php /
  entitlements.php inside the driver, keeping the module light for read-only consumers (the
  `_songSoftDeleteRecountSongbook()` precedent, :411).

Putting it in the CORE, not the endpoints: both editor endpoints already duplicate their
`logActivity` calls 2×; a third funnel (a future bulk delete, an API-key delete) would forget an
endpoint-level notify. The core cannot be forgotten — the same argument as rule #25's one-write-path.

**Why notify on RESTORE too:** with editors restoring, a curator can silently undo a colleague's
`copyright`-reasoned takedown; the restore notification closes that hole for the cost of one more
plan branch. (Purge notifications are omitted — purge is already admin-initiated from the queue page
by the very audience that would be notified; noted as a trivial follow-up if wanted.)

**Honest limitation, stated:** there is no bell in `manage/includes/admin-nav.php` (grepped) — the
in-app notification surfaces in the PUBLIC site header bell. The `ActionUrl` deep-links straight to
`/manage/deleted-songs`. A `/manage` bell is out of scope (file a `for consideration` issue).

### 4.2 The push kind — ONE registry line + an audience field the registry was one entry short of

Add to `webPushKinds()` (web_push.php:140-151): `'song_deleted' => ['Song deletions', 'A curator
moved a song to Deleted songs (reviewers only).', true, 'purge_songs']` and the `song_restored`
sibling — where the **4th element is a NEW optional audience entitlement** (absent = everyone;
existing 3-tuples stay valid — back-compat asserted in `test-web-push.php`). Why the field:
`includes/pages/settings.php:403-409` renders EVERY registry entry as a checkbox for EVERY signed-in
user (`data-push-kinds`); without gating, ordinary users are offered a toggle for a notification
they can never receive (recipients are targeted by `$userIds`) — harmless but dishonest UI. The
fragment is per-user (settings.php:384-386 says so explicitly — NOT in `$_cacheablePages`), so a
per-role emit is legitimate, unlike rule #6's cached fragments. Mechanism, not comment (rule #35):

- `webPushKindAudienceEntitlement(string $kind): ?string` — PURE registry read in web_push.php
  (keeps the module standalone-loadable; no DB, no entitlements.php import).
- settings.php's emit filters:
  `if ($aud !== null && !userHasEntitlement($aud, $role)) continue;` — role from the same
  authenticated context the fragment already renders under (api.php `getAuthenticatedUser()`).
- `manage/notifications.php:637`'s composer picker renders all kinds — it is `manage_notifications`-
  gated (global_admin), fine as-is.
- Opt-in per user still resolves through `webPushKindEnabled()` unchanged; `webPushBroadcast()`'s
  `$userIds` targeting is the enforcement, the audience field is honesty + a second fence.

### 4.3 Reason/note finally get a UI (commit 4)

Neither editor collects them today — both delete flows are bare `window.confirm()`
(`editor.js:5665`, `editor2.php:537`), so the queue's Reason/note column and the notification body
would stay empty forever. Replace the **v2** confirm with a small Bootstrap modal (the `newModal`
infra already in editor2.php): reason `<select>` **server-rendered from `songDeleteReasons()`**
(editor2.php is PHP — no second vocabulary list in JS, the red-flag rule), optional note input,
required-styled Cancel/Delete. `api-client.js:164` becomes
`deleteSong: (songId, reason, note) => postJson('delete_song', { songId, reason, note })` — the
server already accepts both and 422s unknown reasons (api2.php:899-905). The LEGACY editor keeps its
confirm() deliberately (it already soft-deletes safely with no reason; it is scheduled for
retirement under #1601, and reason stays optional server-side) — noted in the commit body.

---

## 5. (d) The two policy questions — recommendation + reasoning

**Q1 — May a curator delete a song they did not create? YES (and there is no other implementable answer).**
`tblSongs` has **no `CreatedBy` column** (verified against the schema.sql CREATE block — only
`CreatedAt`), so "own songs only" has no data to stand on short of new schema plus a fragile
earliest-revision heuristic. It would also contradict the model everywhere else: `edit_songs` is
corpus-wide, `verify_songs` is corpus-wide, and stage 2 made delete *reversible* — the safety
property is recoverability + notification + `tblActivityLog`, not ownership. Document the policy in
help + the issue close.

**Q2 — Must a purge always have a second pair of eyes? NO hard enforcement — the notification IS the
second pair of eyes.** A purge already requires: the song ALREADY in the deleted state (two-step by
construction — `songSoftDeleteVerdict()`'s purge clause, song_soft_delete.php:325-329), the separate
`purge_songs` entitlement, a server-enforced type-to-confirm (deleted-songs.php:127-133), and a
logged action. Stage 3 adds real-time notification of every soft delete to every `purge_songs`
holder, which opens a genuine review window *before* any purge is possible. A hard four-eyes rule
(refuse when `DeletedBy === purger`) is zero-schema and one verdict clause away if the owner ever
wants it — but it deadlocks a one-active-admin operation, and `DeletedBy` is nullable
(`ON DELETE SET NULL`, #1698 erasure) so the rule is trivially satisfiable by an erased-account
deletion — a fence with a stile in it. Recommend: document "let a deletion sit in the queue until a
colleague has seen the notification" as PRACTICE; the queue already displays `DeletedAt` so age is
visible. Flag the verdict-clause option in the issue as `for consideration`.

---

## 6. What the widened `/manage/deleted-songs` audience sees (the stress-test the task ordered)

Stage 2's rehearsal claim "editor can Restore, sees zero purge affordances" **still holds by
construction**: the page gate is `delete_songs` (:67 — widens with the map), `$canPurge` is
`purge_songs` (:74 — stays admin+), and every purge affordance is inside `if ($canPurge)`
(:282-312) with the intro copy switching on the same flag (:215-220). ⚗ Re-verify as an editor.

**Editors see the WHOLE queue, including other curators' deletions — recommended and deliberate.**
Restricting to `DeletedBy = me` would (a) break the collective-stewardship symmetry (any editor may
edit/restore any song), (b) hide from a curator that a song they need vanished, and (c) protect
nothing — `DeletedByName` is no more sensitive than the usernames `/manage/revisions` already shows
editors. What the stage-2 rehearsal did NOT cover, now covered here: an editor restoring a
colleague's `copyright` deletion silently → closed by the restore notification (§4.1); the page
doc-block's future-tense #1695 line (§1); help copy claiming "today both privileges sit with
admins" (§1).

---

## 7. (e)+(f) Commit sequence (ONE PR to `alpha`), each with behavioural assertions + mutation drills

CI note: both suites are glob-run — `tests/php/*.php` (test.yml:248/:268 "a suite cannot exist
without being run") and `tests/*.js` via `tools/run-node-tests.js` (test.yml:176-177) — so no CI
list edits, and no npm-vs-CI drift is possible (rule #35).

**Commit 1 — `feat(entitlements)`: re-widen `delete_songs` to editor+ (#1695, reverts #1692 stage 1's interim).**
The four §1 edits + the api.php:3415-3430 comment refresh.
*Assertions:* E1 baseline admits exactly editor+; MUT-6 (flipped) proves narrowing is visible;
`test-admin-gate-parity.php` still green (pairing unchanged); JS parity suite green untouched.
*Drills (rule #34, and stage 2's lesson that a first green must be challenged):*
(1) the mandatory RED-FIRST run — maps flipped, test rows not yet: E1 + MUT-6 must FAIL, restore by
completing the flip; (2) revert ONLY entitlements.js:29 → the JS↔PHP parity comparison must go red;
(3) hand MUT-6 a mutant map identical to the real map → its self-check must fail (the "guarding
nothing" detector, :482).

**Commit 2 — `feat(db)`: the overrides re-widen card (#1695).**
§3 migration + `entitlementOverridesPruneIfEquals()` + schema.sql sentinel seed (byte-identical) +
ONE registry entry with the sentinel probe.
*Assertions:* `test-migration-registry.php` (probe present, not always-true); `test-schema-coverage.php`;
`test-schema-installs.php` (CI's real MariaDB builds schema.sql incl. the seed row). New pure-fn
tests: exact-match pruned; order-swapped `['global_admin','admin']` pruned; `['global_admin']`
preserved; missing key no-op; non-list value preserved.
*Drills:* (1) break the probe to `fn => true` → registry guard red; (2) mutate the pure fn to
unconditional prune → the `['global_admin']`-preserved assertion red; (3) sentinel key typo'd in
schema.sql seed vs migration → coverage/installs red. ⚗ Card run once on the shared DB; output
inspected for what it dropped.

**Commit 3 — `feat(notify)`: tell the reviewers (#1695).**
§4.1 plan core + reviewer enumeration + driver, called post-commit from `songSoftDelete()` and
`songRestore()`; §4.2 registry lines + audience element + `webPushKindAudienceEntitlement()` +
settings emit filter.
*Assertions (extend `test-song-soft-delete.php`, house mutation-record style M14+):* the plan core
CALLed — actor excluded from recipients; empty reviewer list → empty plan (no phantom sends); reason
key folded via `songDeleteReasons()` label, unknown-reason never reaches the plan (verdict 422s
first); url exactly `/manage/deleted-songs` (and a companion assert that it passes `notifyUser()`'s
allow-list regex — cross-file agreement by mechanism); type strings are `webPushKindValid()` (the
one-spelling lock — a typo'd kind is the #1581 class and this line is what catches it). Driver
isolation, with a RUNTIME handle: in the migrated-mode child the recording double has no
tblUsers/tblNotifications fixture, so enumeration fails internally → the delete verdict MUST still
be `ok:true` (this is the assertion, not a comment). `test-web-push.php` additions: 3-tuple
back-compat; audience fn returns null for `announcement`/`test`, `purge_songs` for the new kinds.
*Drills:* (1) delete the driver's try/catch → migrated-mode child red (double's faithful 1146 kills
the sentinel); (2) drop the actor-exclusion → plan test red; (3) respell the kind
(`song-deleted`) → kind-valid assert red; (4) strip the settings emit filter → the
audience test red; (5) move the driver call BEFORE `$db->commit()` → source-order assertion red
(balanced-paren technique from test-song-redirect-claim.php:223-246 — do NOT regex; that file
documents why twice).

**Commit 4 — `feat(editor)`: the v2 delete modal collects reason + note (#1695).**
§4.3. `editor2.php` modal + `api-client.js` signature; legacy untouched (stated in the body).
*Assertions:* reason options server-rendered from `songDeleteReasons()` — assert `editor2.php`
contains the `songDeleteReasons(` call AND none of the reason keys as HTML literals (narrow: keys,
not labels — labels may legitimately appear in help copy; rule #34's fails-on-correct-code caveat);
`test-editor-api2-contract.php` still green with the widened client call (its regex window was the
thing that lied once — re-verify against real source, don't assume); `node --check` both files.
*Drills:* (1) hardcode one `<option value="duplicate">` → literal-key assert red; (2) drop `reason`
from the postJson body → contract/behaviour check red (⚗ plus the live 422-on-junk-reason probe);
(3) `tests/test-editor-deep-links.js` untouched-and-green (no new params emitted at the editor).

**Commit 5 — `docs/test`: copy + records.**
help.php §1 copy; deleted-songs.php doc-block tense; CHANGELOG; wiki (Admin-Guide / API-Reference);
MEMORY.md:324 + ProjectBrief; the stage-2 plan's PUSH_KINDS correction (§0.3, dated in place);
issue #1695 updated with the §5 policy decisions + the §0 corrections; #1692 epic ticked; handoff.

---

## 8. (g) What could force a second migration / rework — adversarial

1. **The owner chooses review-only visibility after all** (§10). The immediate-hide asymmetry is
   real: stage 2 already hides, so review-only means a NEW workflow state — a queue table (the
   `tblLyricsReviewQueue` shape), read-paths consulting it, and a third lifecycle verdict. That is a
   real second migration and the ONLY one in sight. It is not pre-built, deliberately (§2's last
   row). **Mitigation: the owner question is answered before commit 3 lands** — everything in
   commits 1-2 is correct under either answer.
2. **Notification audience beyond `purge_songs` holders** (e.g. "editors of songbook X") — the
   reviewer-enumeration seam (`songSoftDeleteReviewerIds()`) localises the change; no schema
   (org/songbook scoping would ride existing membership tables).
3. **A `/manage` bell** — new UI, zero schema (`tblNotifications` already per-user).
4. **Four-eyes purge enforced** — one clause in `songSoftDeleteVerdict()`, zero schema (§5 Q2).
5. **Digest / email notifications** — app-level on the SMTP config that `manage_configuration`
   already owns; zero schema.
6. **The audience field pressure-tested**: if a future kind needs audience = "a specific user set"
   rather than an entitlement, the 4th element stays an entitlement key and the SENDER's `$userIds`
   targeting (already how this feature works) carries the rest — no registry reshape.
7. **`webPushKinds()` growth** stays one line per kind by contract (web_push.php:127-130 says the
   drift-fix is restoring the derivation, not a second list) — the settings emit + composer both
   derive; commit 3 must not add a hand-list anywhere.
8. **Entitlement override snapshotting** — if a future stage needs "what did the operator choose vs
   inherit", that wants a delta-storing rewrite of `saveEntitlementOverrides()` (a data-shape
   change, no DDL — `entitlements_overrides` is one JSON row). Out of scope; noted because the
   whole-map save is what created §3's problem and will create it again for the next re-aimed key.

---

## 9. ⚗ Alpha rehearsal

Deploy all docroots → run parity/gate suites green → as an **editor**: delete a test song from the
v2 editor with reason `data-error` + note → confirm it vanishes from public reads (spot-check
search + songbook + 410 on the URL) → confirm the editor sees `/manage/deleted-songs` in the nav,
the row with their own name/reason/note, a working Restore, and **zero purge affordances** → as an
**admin**: confirm the bell notification (public header) with the deep link, and
`/manage/notifications` history → restore as the editor → confirm the admin gets the restore
notification → re-delete, purge as admin, confirm type-to-confirm + tombstone → run the §3 card
once; inspect its dropped/preserved output; re-check an editor can delete AFTER the card on an
install that had saved overrides → junk-reason POST returns 422 → `push_test` from
`/manage/notifications` remains honest about VAPID dormancy. Then the standing-tasks checklist.

## 10. Owner question (asked in #1695) — immediate hide vs review-only

Reported with the recommendation in the session's final message per the 🙋 format: **keep
immediate-hiding + notification** (agreeing with the issue), with the stress-test recorded there and
in the issue. Non-blocking for commits 1-2; blocks nothing in commit 3 unless the answer is
review-only, which would supersede this plan's §4 with §8.1.

## Honest unknowns (planner's own words)

- The #1695 issue body was not readable in this container (no `gh`); §0 is derived from the quoted
  scope + every in-repo reference. If the issue lists further sub-items, reconcile before commit 1.
- Whether the live DB currently HAS an `entitlements_overrides` row (and what it holds) is
  unknowable here; §3's card is correct in every case, and the owner's read-only probe tool
  (#1708, `922377ca`) can answer it before the card runs.
- Whether `getAuthenticatedUser()` context is in scope at settings.php's emit exactly as assumed —
  the fragment renders per-user state already, but the precise variable plumbing is confirmed at
  implementation, not assumed here.
- No push has ever reached a real device (web_push.php:65-73); commit 3's push half is
  provably-encrypted, dormant, and UNPROVEN as delivery — the in-app bell is the acceptance path.
