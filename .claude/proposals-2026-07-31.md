# Ranked new-work proposals — 2026-07-31

Asked for by the owner alongside the remediation programme: *"SUGGEST NEW WORK … List the proposals
individually here, ranked in order of recommendation and/or complexity/work involved."*

Ranked by **expected harm avoided per unit of effort**, not by size. Every item below is grounded in
something verified against the codebase during this programme — none is speculative product wishlist.

Effort is a rough band: **S** ≲ half a day, **M** ≲ two days, **L** a week or more.

---

## The one that outranks everything

### P0 — A controlled runtime verification pass on alpha · **S** · nothing else on this list matters as much

**Nothing on this branch has touched a database or a browser.** No MySQL, no browser, no push
service in the container. Roughly 85 commits of work — CCLI gating, setlist tombstones, the songbook
re-key, four newly-wired feature UIs, the namespaced settings store, the entitlement truth-up — are
*reasoned* correct, guarded by source-derived tests, and **observed** correct nowhere.

That is not a criticism of the work; it is the honest state of it, and it is the single largest risk
carried by this branch. The definition-of-done in `.claude/remediation-plan-2026-07-30.md` §7 says
so explicitly and it remains undischarged.

The pass, in order:
1. Apply every pending migration via `/manage/setup-database` on alpha; confirm the pending counter
   reaches **zero** and the Schema Audit shows no divergence. (This is also the only way to learn
   whether the seed-width fix and the entitlement truth-up migration actually run.)
2. Exercise **one v2 editor write** — until this branch, every v2 write 403'd (#1677). This is the
   highest-value single click available.
3. A real songbook move: old URL redirects, new id everywhere, favourites and history intact.
4. Two-device setlist sync: a tombstone propagating, an expiry converting, 61 setlists surviving.
5. Sign in, open `/settings` → devices card populates; submit a song request → it appears; set a
   musical key → the badge renders.
6. CCLI gating flipped ON in a controlled window: an org member of a licensed org passes; an
   unverified personal number does not. Flip it back.

**Why it ranks first:** every later item on this list is cheaper to do after this than before,
because a runtime surprise here invalidates assumptions the rest build on.

---

## Correctness debts with a known failure mode

### P1 — Convert the six remaining source-inspection guards to behavioural tests (#1691) · **S/M**

Four consecutive rounds on this branch shipped a guard that was green while the thing it guarded was
broken. The root cause is now understood and written up: **source inspection was used as primary
evidence for properties that have a runtime handle.**

`tests/php/test-transaction-fatal.php` is the worked counter-example — it constructs exceptions and
asserts booleans, reads no source, and killed a bypass that had defeated two previous guard
generations. Three of #1691's six want exactly that treatment (extract the gate decision into a pure
function, assert its return value); two are ordinary pattern bugs; one is a doc correction.

**Why it ranks high for its size:** a weak guard is worse than none, because its tick is read as
coverage. This is the difference between "we have tests" and "the tests can fail".

### P2 — `getSongById()`'s number fallback can serve a *different song* (#1689) · **S**

On an exact-id miss the resolver falls back to `getSongByNumber(prefix, number)` **before** consulting
the redirect layer, and `(SongbookAbbr, Number)` is a non-unique index. A dead or moved id can
therefore resolve to whatever now holds that number: **HTTP 200, wrong song, no `redirectedFrom`.**

Pre-existing, but #1679 made id-vacating a routine curation action, so the exposure grew. Wrong
content is worse than the 404 the redirect layer was built to remove.

### P3 — The cascade pre-check is blind to a *missing* FK, and refuses too broadly (#1690) · **S/M** · needs one owner decision

Two defects in one function. It checks the *rule* of FKs that exist and cannot see a column with **no
FK at all** — and `migrate-song-softref-fks.php` exists precisely because three such columns shipped,
including `tblSongRevisions.SongId`. On an install that never ran it, a re-key strands every revision
row while the song looks healthy. Separately it refuses moves schema-wide where `RESTRICT` would only
have bitten songs that actually have child rows, and the migration its error message names can be a
no-op.

Needs a granularity decision (recommendation is in the issue); the second-order fix is unconditional.

### P4 — `apple-deploy` has not run at all since 10 July (#1579) · **S** · possibly trivial

Discovered during the issue sweep by querying the Actions API: five runs, all failed, **none since
2026-07-10**, despite alpha pushes on 29 July. The issue says "fails on every alpha push"; the truth
is it has stopped triggering. Same symptom — nothing reaches TestFlight — different cause, and a
trigger misconfiguration is usually a one-line fix.

**Nobody has noticed for three weeks**, which is the part worth fixing: a deploy that silently stops
running is indistinguishable from one that was never needed.

---

## Structural gaps that generate future bugs

### P5 — The `php-compat` matrix hand-lists its tests (#1682) · **S**

The main CI job globs `tests/php/*.php`; the compatibility matrix does not. So the newest and most
security-relevant guards — the orphan inventory, the seed-width check, the transaction-fatal
behaviour test, both relocate suites, the entitlement parity check — run on **one** interpreter.

This is the *same disease* #1631 and #1602 already fixed twice elsewhere (15 of 22 node suites and 8
of 46 PHP suites were running nowhere). A glob has no "forgot to add the new file" failure mode; a
hand-list does, and this one already has.

### P6 — No crash surfacing on the default admin surface (#1599) · **S/M**

`/manage/editor/` now serves the **v2 editor** — the most-used admin screen — and it is one of the
bespoke-`<head>` pages excluded from the client error monitor. An asset load failure or a JS
exception there surfaces as `console.error` and a blank pane. `tests/test-error-monitor.js` is
honest about the gap (`"a REAL, KNOWN GAP"`), but an allowlisted exception reads as coverage in a
passing run.

The v2 cutover made this materially worse without anyone re-examining the exclusion.

### P7 — Offline downloads: seven root causes, all verified (#1597) · **M/L**

Downloads self-destruct, do not survive deploys, and cannot be browsed. The issue sweep confirmed
each of the seven causes individually against the code. This is the most user-visible broken feature
still open, and it is a PWA's headline promise.

Ranked below the structural items only because it is genuinely large; if user-facing polish matters
more than internal correctness right now, promote it.

---

## Deliberate, larger investments

### P8 — Song deletion stages 2 and 3 (#1694, #1695) · **M** then **S**

Already decided and tracked. Stage 1 (admin-only interim) shipped. Stage 2 is real soft delete —
and the load-bearing constraint is that **restore must be prevention, not repair**: 38 of 41 FKs
cascade, so once the row goes nothing the app holds can rebuild it.

### P9 — A `tblApiKeyUsage`-driven usage report · **M** · closes an audit blind spot permanently

The orphan guard's stated limitation is that it cannot see **out-of-repo consumers** — which is why
53 admin-parity endpoints are allowlisted on judgement rather than evidence. `tblApiKeyUsage` already
exists (#1066, dormant). A usage report would convert that judgement into data, and let the *next*
audit cull with confidence instead of guessing.

**This is the item that stops the orphan problem recurring**, as opposed to detecting it faster.

### P10 — Retire the v1 editor (#1601 scope 3) · **M** · deliberately deferred

The prerequisite for every red/destructive cleanup downstream. Explicitly out of scope for this
branch and correctly so — but it is now the thing blocking the most other work.

---

## Not recommended yet

- **Culling the 53 admin-parity endpoints.** Owner default is keep + allowlist, and that is right:
  culling published API is the only irreversible option and the only one that breaks out-of-repo
  consumers silently. Revisit after P9 gives evidence.
- **Mass comment backfill to the annotation standard.** House rule forbids one unreviewed sweep;
  new code is annotated as written and legacy backfill is a tracked programme, not a task.
- **Anything requiring a second migration on a family already shipped one-pass** (#1066/#1088).
  Rule #20 exists because that is how schema families rot.

---

## Honest note on this list

It is drawn from what this programme happened to touch. The orphan inventory's own header records
three permanent blind spots — out-of-repo API consumers, mounted-but-unreachable UI, and dynamic
action assembly — so a defect in one of those would not appear here. **A ranked list is not a
complete list**, and the next audit should start from the inventory's stated limitations rather than
from this file.
